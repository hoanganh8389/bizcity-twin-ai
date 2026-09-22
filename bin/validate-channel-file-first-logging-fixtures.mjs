#!/usr/bin/env node
/**
 * CI runner for the WP4 file-first operational evidence gate.
 *
 * A gate that cannot fail is not a gate, and a rule that is never exercised is
 * not proven. This asserts five conditions:
 *
 *   1. the clean fixture PASSES and is proven through the CANONICAL path
 *   2. the no-evidence fixture FAILS with `db_write_without_file_evidence`
 *   3. the wrong-order fixture FAILS with `db_write_before_file_evidence`
 *      (this rule has ZERO production hits, so the fixture is its only proof)
 *   4. the wrapper fixture PASSES and is proven through the LOCAL WRAPPER path,
 *      so wrapper resolution cannot silently rot into dead code
 *   5. the config-table fixture PASSES with `db_writing_functions = 0`, proving
 *      the message-table narrowing still excludes configuration CRUD rather
 *      than the rule having been switched off
 *
 * Usage: node bin/validate-channel-file-first-logging-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-channel-file-first-logging.mjs');

function runValidator(fixtureRelative) {
  const result = spawnSync(process.execPath, [validator, `--fixture-root=${fixtureRelative}`], {
    cwd: root,
    encoding: 'utf8',
  });
  let report = null;
  try {
    report = JSON.parse(result.stdout);
  } catch {
    report = null;
  }
  return { code: result.status, report };
}

const failures = [];
const base = 'tests/fixtures/channel-file-first-logging';

// 1. Clean fixture: canonical append before the write.
const valid = runValidator(`${base}/valid`);
if (valid.code !== 0) failures.push(`valid fixture exited ${valid.code} (expected 0)`);
if (!valid.report) failures.push('valid fixture produced no parsable report');
else {
  if (valid.report.total_findings !== 0) {
    failures.push(`valid fixture produced ${valid.report.total_findings} findings (expected 0)`);
  }
  if (valid.report.counters.file_first_ok !== 1) {
    failures.push('valid fixture was not counted as file-first');
  }
  const via = (valid.report.file_first_examples || []).map((item) => item.via);
  if (!via.includes('canonical')) {
    failures.push('valid fixture was not proven through the canonical logger path');
  }
}

// 2. No evidence at all must fail.
const missing = runValidator(`${base}/invalid`);
if (missing.code === 0) failures.push('invalid fixture exited 0 (a failing gate is required)');
if (!missing.report) failures.push('invalid fixture produced no parsable report');
else {
  const rules = new Set((missing.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-CH-FILE-LOG.db_write_without_file_evidence')) {
    failures.push('invalid fixture did not report db_write_without_file_evidence');
  }
  if (rules.has('R-CH-FILE-LOG.db_write_before_file_evidence')) {
    failures.push('invalid fixture also reported the ordering rule (rules are not independent)');
  }
}

// 3. Ordering violation must fail — and with the ORDERING rule specifically.
const order = runValidator(`${base}/order-invalid`);
if (order.code === 0) failures.push('order-invalid fixture exited 0 (expected nonzero)');
if (!order.report) failures.push('order-invalid fixture produced no parsable report');
else {
  const rules = new Set((order.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-CH-FILE-LOG.db_write_before_file_evidence')) {
    failures.push('order-invalid fixture did not report db_write_before_file_evidence');
  }
  if (rules.has('R-CH-FILE-LOG.db_write_without_file_evidence')) {
    failures.push('order-invalid fixture reported "no evidence" — the append was not detected at all');
  }
  if (order.report.counters.order_violation !== 1) {
    failures.push('order-invalid fixture did not increment the order_violation counter');
  }
}

// 4. Wrapper resolution must be proven independently of the canonical path.
const wrapper = runValidator(`${base}/wrapper-valid`);
if (wrapper.code !== 0) failures.push(`wrapper-valid fixture exited ${wrapper.code} (expected 0)`);
if (wrapper.report) {
  if (wrapper.report.total_findings !== 0) {
    failures.push(`wrapper-valid fixture produced ${wrapper.report.total_findings} findings (expected 0)`);
  }
  if (wrapper.report.counters.local_wrappers_resolved < 1) {
    failures.push('wrapper-valid fixture resolved no local wrapper (wrapper resolution regressed?)');
  }
  const via = (wrapper.report.file_first_examples || []).map((item) => item.via);
  if (!via.includes('local_wrapper')) {
    failures.push('wrapper-valid fixture was not proven through the local wrapper path');
  }
}

// 5. Config CRUD must stay out of scope entirely.
const config = runValidator(`${base}/non-message-table`);
if (config.code !== 0) failures.push(`non-message-table fixture exited ${config.code} (expected 0)`);
if (config.report) {
  if (config.report.total_findings !== 0) {
    failures.push(`non-message-table fixture produced ${config.report.total_findings} findings (expected 0)`);
  }
  if (config.report.counters.db_writing_functions !== 0) {
    failures.push(
      `non-message-table fixture counted ${config.report.counters.db_writing_functions} message writes (expected 0 — the table narrowing regressed)`,
    );
  }
  if (config.report.counters.channel_context_files !== 1) {
    failures.push('non-message-table fixture was not in channel scope, so it proves nothing');
  }
}

const summary = {
  valid_fixture: {
    exit: valid.code,
    findings: valid.report ? valid.report.total_findings : null,
    via: valid.report ? (valid.report.file_first_examples || []).map((item) => item.via) : null,
    expected: 'PASS proven through the canonical logger path',
  },
  invalid_fixture: {
    exit: missing.code,
    findings: missing.report ? missing.report.total_findings : null,
    expected: 'FAIL reporting db_write_without_file_evidence',
  },
  order_invalid_fixture: {
    exit: order.code,
    findings: order.report ? order.report.total_findings : null,
    order_violations: order.report ? order.report.counters.order_violation : null,
    expected: 'FAIL reporting db_write_before_file_evidence',
  },
  wrapper_valid_fixture: {
    exit: wrapper.code,
    findings: wrapper.report ? wrapper.report.total_findings : null,
    wrappers_resolved: wrapper.report ? wrapper.report.counters.local_wrappers_resolved : null,
    expected: 'PASS proven through the local wrapper path',
  },
  non_message_table_fixture: {
    exit: config.code,
    findings: config.report ? config.report.total_findings : null,
    db_writing_functions: config.report ? config.report.counters.db_writing_functions : null,
    expected: 'PASS with zero message writes (config CRUD stays out of scope)',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
