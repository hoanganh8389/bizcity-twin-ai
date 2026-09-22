#!/usr/bin/env node
/**
 * CI runner for the DDL table parity gate.
 *
 * Asserts that the deterministic validator both:
 *   - PASSES on the clean fixture (exit 0, zero findings)
 *   - FAILS on the broken fixture and reports every expected violation
 *
 * A gate that cannot fail is not a gate; this runner is what keeps the parity
 * validator honest in CI.
 *
 * Usage: node bin/validate-ddl-table-parity-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-ddl-table-parity.mjs');

const expectedViolations = [
  { table: 'fixture_widgets', missing: ['diagnostics_registry', 'schema_registry'] },
  { table: 'fixture_widget_events', missing: ['schema_registry'] },
];

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
  return { code: result.status, report, stderr: result.stderr || '' };
}

const failures = [];

const clean = runValidator('tests/fixtures/ddl-table-parity/valid');
if (clean.code !== 0) failures.push(`clean fixture exited ${clean.code} (expected 0)`);
if (!clean.report) failures.push('clean fixture produced no parsable report');
else if (clean.report.total_findings !== 0) {
  failures.push(`clean fixture produced ${clean.report.total_findings} findings (expected 0)`);
}

const broken = runValidator('tests/fixtures/ddl-table-parity/invalid');
if (broken.code === 0) failures.push('broken fixture exited 0 (a failing gate is required)');
if (!broken.report) failures.push('broken fixture produced no parsable report');
else {
  for (const expected of expectedViolations) {
    const finding = (broken.report.findings || []).find(
      (item) => item.table === expected.table,
    );
    if (!finding) {
      failures.push(`broken fixture missed expected violation for ${expected.table}`);
      continue;
    }
    const actual = [...(finding.missing || [])].sort().join('+');
    const wanted = [...expected.missing].sort().join('+');
    if (actual !== wanted) {
      failures.push(
        `broken fixture reported missing=${actual} for ${expected.table} (expected ${wanted})`,
      );
    }
  }
}

const summary = {
  clean_fixture: {
    exit: clean.code,
    findings: clean.report ? clean.report.total_findings : null,
    expected: 'PASS with 0 findings',
  },
  broken_fixture: {
    exit: broken.code,
    findings: broken.report ? broken.report.total_findings : null,
    tables: broken.report ? (broken.report.findings || []).map((item) => item.table) : [],
    expected: `FAIL with ${expectedViolations.length} violations`,
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;