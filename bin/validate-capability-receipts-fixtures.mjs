#!/usr/bin/env node
/**
 * CI runner for the WP3 capability receipt gate.
 *
 * Asserts the validator both PASSES on the clean fixture and FAILS on the
 * broken fixture with the expected rules. A gate that cannot fail is not a gate.
 *
 * Usage: node bin/validate-capability-receipts-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-capability-receipts.mjs');

const expectedRules = [
  'capability.receipt_incomplete',
  'capability.duplicate_id',
  'capability.class_not_found',
  'capability.class_not_typed',
  'capability.untyped_unclassified',
  'capability.contract_unknown',
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
  return { code: result.status, report };
}

const failures = [];

const clean = runValidator('tests/fixtures/capability-receipts/valid');
if (clean.code !== 0) failures.push(`clean fixture exited ${clean.code} (expected 0)`);
if (!clean.report) failures.push('clean fixture produced no parsable report');
else if (clean.report.total_findings !== 0) {
  failures.push(`clean fixture produced ${clean.report.total_findings} findings (expected 0)`);
}
// The clean fixture deliberately declares three capabilities, one per interface
// naming style found in this repository: runtime suffixed
// (`BizCity_Tool_Interface`), runtime unsuffixed (`BizCity_Channel_Adapter`,
// exactly as the real channel adapters declare it) and SDK namespaced
// (`BizCity\Twin\Contracts\SkillInterface`). Zero findings here is the proof
// that the gate accepts all three; if shortForm() regresses, this catches it.
if (clean.report && clean.report.capabilities_declared !== 3) {
  failures.push(
    `clean fixture declared ${clean.report.capabilities_declared} capabilities (expected 3 across three interface naming styles)`,
  );
}
if (clean.report && clean.report.counters && clean.report.counters.class_resolved !== 3) {
  failures.push(
    `clean fixture resolved ${clean.report.counters.class_resolved} classes (expected 3)`,
  );
}

const broken = runValidator('tests/fixtures/capability-receipts/invalid');
if (broken.code === 0) failures.push('broken fixture exited 0 (a failing gate is required)');
if (!broken.report) failures.push('broken fixture produced no parsable report');
else {
  const rules = new Set((broken.report.findings || []).map((finding) => finding.rule));
  for (const rule of expectedRules) {
    if (!rules.has(rule)) failures.push(`broken fixture did not report ${rule}`);
  }
  const duplicate = (broken.report.findings || []).find(
    (finding) => finding.rule === 'capability.duplicate_id',
  );
  if (duplicate && !duplicate.extension_id.includes(',')) {
    failures.push('duplicate_id finding must name both claiming extensions');
  }
}

// A package that declares package_role=legacy_adapter is exempt from the typed
// requirement, because it registers through the legacy filter path. If that
// exemption regresses, this fixture starts failing.
const legacy = runValidator('tests/fixtures/capability-receipts/legacy');
if (legacy.code !== 0) failures.push(`legacy exemption fixture exited ${legacy.code} (expected 0)`);
if (legacy.report && legacy.report.total_findings !== 0) {
  failures.push(
    `legacy exemption fixture produced ${legacy.report.total_findings} findings (expected 0)`,
  );
}

// A capability entry with no id at all must be reported, not silently skipped.
const missingId = runValidator('tests/fixtures/capability-receipts/missing-id');
if (missingId.code === 0) failures.push('missing-id fixture exited 0 (expected nonzero)');
if (missingId.report) {
  const rules = new Set((missingId.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('capability.receipt_missing_id')) {
    failures.push('missing-id fixture did not report capability.receipt_missing_id');
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
    rules: broken.report ? [...new Set((broken.report.findings || []).map((f) => f.rule))] : [],
    expected: `FAIL reporting ${expectedRules.join(' and ')}`,
  },
  legacy_exemption_fixture: {
    exit: legacy.code,
    findings: legacy.report ? legacy.report.total_findings : null,
    expected: 'PASS with 0 findings (package_role=legacy_adapter is exempt)',
  },
  missing_id_fixture: {
    exit: missingId.code,
    findings: missingId.report ? missingId.report.total_findings : null,
    expected: 'FAIL reporting capability.receipt_missing_id',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;