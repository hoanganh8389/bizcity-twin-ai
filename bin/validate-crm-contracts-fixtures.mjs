#!/usr/bin/env node
/** CI runner for WP5 normalized contract/event/Zone 2 fixtures. */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-crm-contracts.mjs');

function run(fixture) {
  const result = spawnSync(process.execPath, [validator, `--fixture-root=${fixture}`], {
    cwd: root,
    encoding: 'utf8',
  });
  let report = null;
  try { report = JSON.parse(result.stdout); } catch { report = null; }
  return { code: result.status, report };
}

const failures = [];
const valid = run('tests/fixtures/crm-contracts/valid');
if (valid.code !== 0) failures.push(`valid CRM contract fixture exited ${valid.code}`);
if (!valid.report || valid.report.total_findings !== 0) {
  failures.push('valid CRM contract fixture must have zero findings');
}

const invalid = run('tests/fixtures/crm-contracts/invalid');
if (invalid.code === 0) failures.push('invalid CRM contract fixture exited 0');
const requiredRules = [
  'R-CRM.normalized_contract_incomplete',
  'R-CRM.write_without_canonical_event',
  'R-CRM.message_write_guard_incomplete',
  'R-CRM.zone2_ai_consumer_without_policy_guard',
  'R-CRM.user_scope_incomplete',
  'R-CRM.archive_correlation_incomplete',
];
if (!invalid.report) {
  failures.push('invalid CRM contract fixture produced no parsable report');
} else {
  for (const rule of requiredRules) {
    if (!Object.prototype.hasOwnProperty.call(invalid.report.findings_by_rule || {}, rule)) {
      failures.push(`invalid fixture did not report ${rule}`);
    }
  }
}

const summary = {
  valid_fixture: {
    exit: valid.code,
    findings: valid.report ? valid.report.total_findings : null,
    expected: 'PASS with zero findings and 4/4 canonical event functions',
  },
  invalid_fixture: {
    exit: invalid.code,
    findings: invalid.report ? invalid.report.total_findings : null,
    rules: invalid.report ? Object.keys(invalid.report.findings_by_rule || {}) : [],
    expected: 'FAIL with normalized contract, event, message guard and Zone 2 findings',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};
process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
