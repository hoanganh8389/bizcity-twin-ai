#!/usr/bin/env node
/**
 * CI runner for the WP6 Context Bank / KG ownership gate.
 *
 * Asserts seven conditions. Two of the three rules find NOTHING in production —
 * `ledger_payload_column` and `ledger_write_outside_owner` are both clean — so
 * without fixtures they would be indistinguishable from rules that never run.
 * WP3 recorded exactly that: two validator rules that appeared in neither
 * fixtures nor production and were therefore unproven.
 *
 *   1. KG content written outside KG-Hub FAILS
 *   2. namespace-adjacent tables + facade promotion PASS (scope regression guard)
 *   3. a second ledger writer FAILS, resolved through `const` + accessor
 *   4. a payload-capable ledger column FAILS
 *   5. the pointer-only ledger PASSES with columns actually inspected
 *   6. a KG table passed as a function PARAMETER (not a local assignment)
 *      still FAILS — the WP6 interprocedural gap
 *   7. a passthrough helper of the same shape but a non-KG table stays clean
 *      (regression guard for #6, folded into the `valid/` fixture)
 *
 * Usage: node bin/validate-context-bank-kg-ownership-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-context-bank-kg-ownership.mjs');

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
const base = 'tests/fixtures/context-bank-kg-ownership';
const rulesOf = (report) => new Set((report.findings || []).map((finding) => finding.rule));

// 1. KG promotion outside the owner must fail — both write shapes.
const kgBypass = runValidator(`${base}/invalid`);
if (kgBypass.code === 0) failures.push('kg invalid fixture exited 0 (a failing gate is required)');
if (!kgBypass.report) failures.push('kg invalid fixture produced no parsable report');
else {
  if (!rulesOf(kgBypass.report).has('R-KG.promotion_outside_owner')) {
    failures.push('kg invalid fixture did not report R-KG.promotion_outside_owner');
  }
  if (kgBypass.report.total_findings !== 2) {
    failures.push(
      `kg invalid fixture reported ${kgBypass.report.total_findings} findings (expected 2: direct helper call and tracked variable)`,
    );
  }
  if (kgBypass.report.counters.kg_bypass_writers !== 1) {
    failures.push('kg invalid fixture was not counted as a bypass writer');
  }
}

// 2. Namespace-adjacent tables must NOT be treated as KG content. This
//    fixture also carries the #7 regression guard (a passthrough helper
//    receiving a non-KG table), so it doubles as proof the interprocedural
//    pass does not over-fire on an ordinary "table via parameter" shape.
const kgValid = runValidator(`${base}/valid`);
if (kgValid.code !== 0) failures.push(`kg valid fixture exited ${kgValid.code} (expected 0)`);
if (kgValid.report && kgValid.report.total_findings !== 0) {
  failures.push(
    `kg valid fixture produced ${kgValid.report.total_findings} findings (expected 0 — bizcity_kg_learning_* is namespace-adjacent, not KG content, and the non-KG passthrough helper must stay clean)`,
  );
}

// 6. A KG table passed as a function PARAMETER, not a local assignment, must
//    still FAIL — the interprocedural pass closing the WP6 stated false
//    negative (`insert_passage( $tbl_passages, ... )` shape).
const kgPassthrough = runValidator(`${base}/kg-passthrough-invalid`);
if (kgPassthrough.code === 0) failures.push('kg-passthrough-invalid fixture exited 0 (expected nonzero)');
if (!kgPassthrough.report) failures.push('kg-passthrough-invalid fixture produced no parsable report');
else {
  if (!rulesOf(kgPassthrough.report).has('R-KG.promotion_outside_owner')) {
    failures.push('kg-passthrough-invalid fixture did not report R-KG.promotion_outside_owner');
  }
  if (kgPassthrough.report.total_findings !== 1) {
    failures.push(
      `kg-passthrough-invalid fixture reported ${kgPassthrough.report.total_findings} findings (expected 1: the $wpdb->insert() inside the parameter-passthrough helper)`,
    );
  }
  if (kgPassthrough.report.counters.kg_passthrough_resolved !== 1) {
    failures.push('kg-passthrough-invalid fixture did not count kg_passthrough_resolved=1 (interprocedural pass regressed?)');
  }
}

// 3. A second ledger writer must fail, and must be caught through the
//    `const TABLE_BASE` + `$this->table()` shape the real ledger uses.
const ledgerBypass = runValidator(`${base}/ledger-invalid`);
if (ledgerBypass.code === 0) failures.push('ledger-invalid fixture exited 0 (expected nonzero)');
if (!ledgerBypass.report) failures.push('ledger-invalid fixture produced no parsable report');
else {
  if (!rulesOf(ledgerBypass.report).has('R-CB.ledger_write_outside_owner')) {
    failures.push('ledger-invalid fixture did not report R-CB.ledger_write_outside_owner');
  }
  if (ledgerBypass.report.counters.table_accessors_resolved < 1) {
    failures.push('ledger-invalid fixture resolved no table accessor (constant/accessor resolution regressed?)');
  }
}

// 4. A payload-capable ledger column must fail.
const payloadColumn = runValidator(`${base}/payload-column`);
if (payloadColumn.code === 0) failures.push('payload-column fixture exited 0 (expected nonzero)');
if (!payloadColumn.report) failures.push('payload-column fixture produced no parsable report');
else {
  const finding = (payloadColumn.report.findings || []).find(
    (item) => item.rule === 'R-CB.ledger_payload_column',
  );
  if (!finding) failures.push('payload-column fixture did not report R-CB.ledger_payload_column');
  else if (finding.column !== 'payload_json') {
    failures.push(`payload-column fixture named column ${finding.column} (expected payload_json)`);
  }
}

// 5. The pointer-only ledger must pass WITH columns actually inspected.
const payloadClean = runValidator(`${base}/payload-clean`);
if (payloadClean.code !== 0) failures.push(`payload-clean fixture exited ${payloadClean.code} (expected 0)`);
if (payloadClean.report) {
  if (payloadClean.report.total_findings !== 0) {
    failures.push(`payload-clean fixture produced ${payloadClean.report.total_findings} findings (expected 0)`);
  }
  if (payloadClean.report.counters.ledger_ddl_columns_checked < 1) {
    failures.push('payload-clean fixture inspected zero columns, so it proves nothing');
  }
}

const summary = {
  kg_invalid_fixture: {
    exit: kgBypass.code,
    findings: kgBypass.report ? kgBypass.report.total_findings : null,
    expected: 'FAIL reporting R-KG.promotion_outside_owner on both write shapes',
  },
  kg_valid_fixture: {
    exit: kgValid.code,
    findings: kgValid.report ? kgValid.report.total_findings : null,
    expected: 'PASS — namespace-adjacent learning tables are not KG content, non-KG passthrough helper stays clean',
  },
  kg_passthrough_invalid_fixture: {
    exit: kgPassthrough.code,
    findings: kgPassthrough.report ? kgPassthrough.report.total_findings : null,
    passthrough_resolved: kgPassthrough.report ? kgPassthrough.report.counters.kg_passthrough_resolved : null,
    expected: 'FAIL reporting R-KG.promotion_outside_owner via parameter-passthrough resolution',
  },
  ledger_invalid_fixture: {
    exit: ledgerBypass.code,
    findings: ledgerBypass.report ? ledgerBypass.report.total_findings : null,
    accessors: ledgerBypass.report ? ledgerBypass.report.counters.table_accessors_resolved : null,
    expected: 'FAIL reporting R-CB.ledger_write_outside_owner via const + accessor',
  },
  payload_column_fixture: {
    exit: payloadColumn.code,
    findings: payloadColumn.report ? payloadColumn.report.total_findings : null,
    expected: 'FAIL reporting R-CB.ledger_payload_column on payload_json',
  },
  payload_clean_fixture: {
    exit: payloadClean.code,
    columns_checked: payloadClean.report ? payloadClean.report.counters.ledger_ddl_columns_checked : null,
    expected: 'PASS with ledger columns actually inspected',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
