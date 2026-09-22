#!/usr/bin/env node
/**
 * CI runner for the WP4 canonical sender ownership gate.
 *
 * Asserts both directions plus the transport-owner exemption, because the
 * exemption is the part most likely to over-reach and silently excuse a real
 * bypass:
 *   1. the bypass fixture FAILS with direct_provider_post_outside_owner
 *   2. the bypass fixture also FAILS with send_without_idempotency_key
 *   3. the compliant fixture PASSES with 1/1 idempotency coverage
 *   4. a provider post under the approved transport owner is exempt
 *      (proven by a dedicated fixture)
 *
 * Rule 2 exists because the first draft tested the raw file source, so a
 * docblock that merely *mentioned* `idempotency_key` satisfied the rule. The
 * bypass fixture's own header comment names the field it is missing, so this
 * runner fails if the comment ever satisfies the rule again.
 *
 * Usage: node bin/validate-sender-ownership-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-sender-ownership.mjs');

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

const broken = runValidator('tests/fixtures/sender-ownership/invalid');
if (broken.code === 0) failures.push('sender bypass fixture exited 0 (a failing gate is required)');
if (!broken.report) failures.push('sender bypass fixture produced no parsable report');
else {
  const rules = new Set((broken.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-CH-SENDER.direct_provider_post_outside_owner')) {
    failures.push('sender bypass fixture did not report R-CH-SENDER.direct_provider_post_outside_owner');
  }
  if (!rules.has('R-CH-SENDER.send_without_idempotency_key')) {
    failures.push(
      'sender bypass fixture did not report R-CH-SENDER.send_without_idempotency_key ' +
        '(a comment must not satisfy the idempotency rule)',
    );
  }
}

const valid = runValidator('tests/fixtures/sender-ownership/valid');
if (valid.code !== 0) failures.push(`sender compliant fixture exited ${valid.code} (expected 0)`);
if (valid.report) {
  if (valid.report.total_findings !== 0) {
    failures.push(`sender compliant fixture produced ${valid.report.total_findings} findings (expected 0)`);
  }
  if (valid.report.counters.sender_call_sites < 1) {
    failures.push('sender compliant fixture was not recognized as a sender call site');
  }
  if (valid.report.counters.sender_calls_with_idempotency !== valid.report.counters.sender_call_sites) {
    failures.push('sender compliant fixture did not reach full idempotency coverage');
  }
}

// The transport-owner exemption is what allows the canonical gateway adapters to
// call providers directly. If it stops working, the real gate starts failing on
// the approved owner; if it over-reaches, a real bypass would be excused. Both
// directions are covered by asserting the owner fixture is exempt AND reports
// zero findings.
const owner = runValidator('tests/fixtures/sender-ownership/transport-owner');
if (owner.code !== 0) failures.push(`transport owner fixture exited ${owner.code} (expected 0, exemption)`);
if (owner.report) {
  if (owner.report.total_findings !== 0) {
    failures.push(`transport owner fixture produced ${owner.report.total_findings} findings (expected 0 via exemption)`);
  }
  if (owner.report.counters.provider_posts_in_owner < 1) {
    failures.push('transport owner fixture was not recognized as an approved transport owner');
  }
}

const summary = {
  bypass_fixture: {
    exit: broken.code,
    findings: broken.report ? broken.report.total_findings : null,
    rules: broken.report ? [...new Set((broken.report.findings || []).map((f) => f.rule))] : [],
    expected: 'FAIL reporting direct_provider_post_outside_owner and send_without_idempotency_key',
  },
  compliant_fixture: {
    exit: valid.code,
    findings: valid.report ? valid.report.total_findings : null,
    idempotency_coverage: valid.report ? valid.report.idempotency_coverage : null,
    expected: 'PASS with 0 findings and full idempotency coverage',
  },
  transport_owner_fixture: {
    exit: owner.code,
    findings: owner.report ? owner.report.total_findings : null,
    provider_posts_in_owner: owner.report ? owner.report.counters.provider_posts_in_owner : null,
    expected: 'PASS via approved transport-owner exemption',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
