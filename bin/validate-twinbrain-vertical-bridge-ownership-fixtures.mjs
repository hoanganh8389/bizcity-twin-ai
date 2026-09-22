#!/usr/bin/env node
/**
 * CI runner for the WP7 vertical bridge registry ownership gate.
 *
 * Asserts five conditions:
 *   1. a declared vertical_modes[] id with no matching row and no
 *      add_filter() call FAILS as R-TB.vertical_unregistered
 *   2. a declared id matching a hardcoded row, but with drifted fields,
 *      FAILS as R-TB.vertical_registry_drift naming exactly the drifted
 *      fields
 *   3. a hardcoded row naming a real, installed plugin owner whose manifest
 *      never declares the vertical FAILS as R-TB.registry_row_undeclared
 *   4. a manifest/registry pair that matches exactly PASSES (the real
 *      bizcoach-pro/astro shape)
 *   5. a vertical reachable ONLY via add_filter() (no hardcoded row to diff
 *      against) PASSES rather than being treated as unregistered
 *
 * Usage: node bin/validate-twinbrain-vertical-bridge-ownership-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-twinbrain-vertical-bridge-ownership.mjs');
const base = 'tests/fixtures/twinbrain-vertical-bridge-ownership';

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

// 1. Declared but unreachable.
const unregistered = runValidator(`${base}/unregistered-invalid`);
if (unregistered.code === 0) failures.push('unregistered-invalid fixture exited 0 (expected nonzero)');
if (!unregistered.report) failures.push('unregistered-invalid fixture produced no parsable report');
else if (!rulesOf(unregistered.report).has('R-TB.vertical_unregistered')) {
  failures.push('unregistered-invalid fixture did not report R-TB.vertical_unregistered');
}

// 2. Drifted fields against a matching hardcoded row.
const drift = runValidator(`${base}/drift-invalid`);
if (drift.code === 0) failures.push('drift-invalid fixture exited 0 (expected nonzero)');
if (!drift.report) failures.push('drift-invalid fixture produced no parsable report');
else {
  const finding = (drift.report.findings || []).find((item) => item.rule === 'R-TB.vertical_registry_drift');
  if (!finding) failures.push('drift-invalid fixture did not report R-TB.vertical_registry_drift');
  else if (finding.missing.join(',') !== 'label,output_shape') {
    failures.push(`drift-invalid fixture named mismatches [${finding.missing.join(',')}] (expected label,output_shape)`);
  }
}

// 3. Registry row naming an installed plugin that never declared it.
const undeclared = runValidator(`${base}/registry-undeclared-invalid`);
if (undeclared.code === 0) failures.push('registry-undeclared-invalid fixture exited 0 (expected nonzero)');
if (!undeclared.report) failures.push('registry-undeclared-invalid fixture produced no parsable report');
else if (!rulesOf(undeclared.report).has('R-TB.registry_row_undeclared')) {
  failures.push('registry-undeclared-invalid fixture did not report R-TB.registry_row_undeclared');
}

// 4. Exact match (the real bizcoach-pro/astro shape) must PASS.
const valid = runValidator(`${base}/valid`);
if (valid.code !== 0) failures.push(`valid fixture exited ${valid.code} (expected 0)`);
if (valid.report && valid.report.total_findings !== 0) {
  failures.push(`valid fixture produced ${valid.report.total_findings} findings (expected 0)`);
}

// 5. Reachable only via add_filter() must PASS, not be treated as unregistered.
const viaFilter = runValidator(`${base}/via-filter-valid`);
if (viaFilter.code !== 0) failures.push(`via-filter-valid fixture exited ${viaFilter.code} (expected 0)`);
if (viaFilter.report) {
  if (viaFilter.report.total_findings !== 0) {
    failures.push(`via-filter-valid fixture produced ${viaFilter.report.total_findings} findings (expected 0)`);
  }
  if (viaFilter.report.counters.vertical_modes_reachable_via_filter !== 1) {
    failures.push('via-filter-valid fixture did not count vertical_modes_reachable_via_filter=1 (add_filter resolution regressed?)');
  }
}

const summary = {
  unregistered_invalid_fixture: {
    exit: unregistered.code,
    findings: unregistered.report ? unregistered.report.total_findings : null,
    expected: 'FAIL reporting R-TB.vertical_unregistered',
  },
  drift_invalid_fixture: {
    exit: drift.code,
    findings: drift.report ? drift.report.total_findings : null,
    expected: 'FAIL reporting R-TB.vertical_registry_drift on [label, output_shape]',
  },
  registry_undeclared_invalid_fixture: {
    exit: undeclared.code,
    findings: undeclared.report ? undeclared.report.total_findings : null,
    expected: 'FAIL reporting R-TB.registry_row_undeclared',
  },
  valid_fixture: {
    exit: valid.code,
    findings: valid.report ? valid.report.total_findings : null,
    expected: 'PASS — manifest and hardcoded row match exactly',
  },
  via_filter_valid_fixture: {
    exit: viaFilter.code,
    findings: viaFilter.report ? viaFilter.report.total_findings : null,
    expected: 'PASS — reachable only via add_filter(), not treated as unregistered',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
