#!/usr/bin/env node
/**
 * CI runner for the WP7 Brain retrieval facade ownership gate.
 *
 * Asserts three conditions:
 *   1. a call to BizCity_Context_Bank_Retrieval_Pack::build() from a vertical
 *      plugin FAILS as R-TB.retrieval_outside_facade
 *   2. the same call from a diagnostics probe PASSES (exempted, same
 *      convention as the WP4/WP5/WP6/WP7 diagnostics exemption)
 *   3. the same call from the facade's own directory (core/twinbrain/) and
 *      the builder's own directory (core/context-bank/) PASSES — these ARE
 *      the owners
 *
 * Usage: node bin/validate-brain-retrieval-facade-ownership-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-brain-retrieval-facade-ownership.mjs');
const base = 'tests/fixtures/brain-retrieval-facade-ownership';

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
const rulesOf = (report) => new Set((report.findings || []).map((finding) => finding.rule));

// 1. Vertical plugin bypassing the facade.
const bypass = runValidator(`${base}/bypass-invalid`);
if (bypass.code === 0) failures.push('bypass-invalid fixture exited 0 (expected nonzero)');
if (!bypass.report) failures.push('bypass-invalid fixture produced no parsable report');
else if (!rulesOf(bypass.report).has('R-TB.retrieval_outside_facade')) {
  failures.push('bypass-invalid fixture did not report R-TB.retrieval_outside_facade');
}

// 2. Diagnostics probe must be exempt.
const exempt = runValidator(`${base}/exempt-diagnostics-valid`);
if (exempt.code !== 0) failures.push(`exempt-diagnostics-valid fixture exited ${exempt.code} (expected 0)`);
if (exempt.report) {
  if (exempt.report.total_findings !== 0) {
    failures.push(`exempt-diagnostics-valid fixture produced ${exempt.report.total_findings} findings (expected 0)`);
  }
  if (exempt.report.counters.retrieval_build_exempt_calls !== 1) {
    failures.push('exempt-diagnostics-valid fixture did not count retrieval_build_exempt_calls=1 (exemption regressed?)');
  }
}

// 3. Facade's own directory and the builder's own directory are the owners.
const owner = runValidator(`${base}/owner-valid`);
if (owner.code !== 0) failures.push(`owner-valid fixture exited ${owner.code} (expected 0)`);
if (owner.report) {
  if (owner.report.total_findings !== 0) {
    failures.push(`owner-valid fixture produced ${owner.report.total_findings} findings (expected 0)`);
  }
  if (owner.report.counters.retrieval_build_owner_calls !== 2) {
    failures.push(`owner-valid fixture counted retrieval_build_owner_calls=${owner.report.counters.retrieval_build_owner_calls} (expected 2)`);
  }
}

const summary = {
  bypass_invalid_fixture: {
    exit: bypass.code,
    findings: bypass.report ? bypass.report.total_findings : null,
    expected: 'FAIL reporting R-TB.retrieval_outside_facade',
  },
  exempt_diagnostics_valid_fixture: {
    exit: exempt.code,
    findings: exempt.report ? exempt.report.total_findings : null,
    expected: 'PASS — diagnostics probe is exempt',
  },
  owner_valid_fixture: {
    exit: owner.code,
    findings: owner.report ? owner.report.total_findings : null,
    expected: 'PASS — core/twinbrain/ and core/context-bank/ are the owners',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
