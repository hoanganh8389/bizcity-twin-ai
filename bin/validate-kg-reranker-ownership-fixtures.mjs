#!/usr/bin/env node
/**
 * CI runner for the WP7 KG reranker ownership gate.
 *
 * Asserts two conditions:
 *   1. a call to BizCity_KG_Reranker from outside core/knowledge/kg-hub/
 *      FAILS as R-TB.reranker_outside_owner
 *   2. the same call from inside core/knowledge/kg-hub/ PASSES — this IS the
 *      owner — and a docblock mention alone is not flagged
 *
 * Usage: node bin/validate-kg-reranker-ownership-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-kg-reranker-ownership.mjs');
const base = 'tests/fixtures/kg-reranker-ownership';

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

// 1. Reranker called from outside KG-Hub.
const bypass = runValidator(`${base}/bypass-invalid`);
if (bypass.code === 0) failures.push('bypass-invalid fixture exited 0 (expected nonzero)');
if (!bypass.report) failures.push('bypass-invalid fixture produced no parsable report');
else if (!rulesOf(bypass.report).has('R-TB.reranker_outside_owner')) {
  failures.push('bypass-invalid fixture did not report R-TB.reranker_outside_owner');
}

// 2. KG-Hub's own directory is the owner.
const owner = runValidator(`${base}/owner-valid`);
if (owner.code !== 0) failures.push(`owner-valid fixture exited ${owner.code} (expected 0)`);
if (owner.report) {
  if (owner.report.total_findings !== 0) {
    failures.push(`owner-valid fixture produced ${owner.report.total_findings} findings (expected 0)`);
  }
  if (owner.report.counters.reranker_owner_references !== 1) {
    failures.push(`owner-valid fixture counted reranker_owner_references=${owner.report.counters.reranker_owner_references} (expected 1)`);
  }
}

const summary = {
  bypass_invalid_fixture: {
    exit: bypass.code,
    findings: bypass.report ? bypass.report.total_findings : null,
    expected: 'FAIL reporting R-TB.reranker_outside_owner',
  },
  owner_valid_fixture: {
    exit: owner.code,
    findings: owner.report ? owner.report.total_findings : null,
    expected: 'PASS — core/knowledge/kg-hub/ is the owner',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
