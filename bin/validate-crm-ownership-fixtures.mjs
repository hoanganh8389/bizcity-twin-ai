#!/usr/bin/env node
/**
 * CI runner for the WP5 CRM ownership gate.
 *
 * Asserts both directions plus the diagnostics-probe exemption, because the
 * exemption is the part most likely to over-reach and silently excuse a real
 * bypass:
 *   1. the bypass fixture FAILS with direct-sql-outside-owner
 *   2. the bypass fixture also FAILS with write_without_channel_contract
 *   3. the compliant fixture PASSES with zero findings
 *   4. a probe-fixture-style write under core/diagnostics/includes/probes/ is
 *      exempt (proven by a dedicated fixture)
 *   5. a repository-layer write passes
 *   6. a CRM-owner controller write fails the repository rule
 *   7. the exact diagnostics harness path passes only with cleanup
 *   8. the same harness path without cleanup fails
 *
 * Usage: node bin/validate-crm-ownership-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-crm-ownership.mjs');

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

const broken = runValidator('tests/fixtures/crm-ownership/invalid');
if (broken.code === 0) failures.push('crm bypass fixture exited 0 (a failing gate is required)');
if (!broken.report) failures.push('crm bypass fixture produced no parsable report');
else {
  const rules = new Set((broken.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-CRM.direct-sql-outside-owner')) {
    failures.push('crm bypass fixture did not report R-CRM.direct-sql-outside-owner');
  }
  if (!rules.has('R-CRM.write_without_channel_contract')) {
    failures.push('crm bypass fixture did not report R-CRM.write_without_channel_contract');
  }
}

const valid = runValidator('tests/fixtures/crm-ownership/valid');
if (valid.code !== 0) failures.push(`crm compliant fixture exited ${valid.code} (expected 0)`);
if (valid.report) {
  if (valid.report.total_findings !== 0) {
    failures.push(`crm compliant fixture produced ${valid.report.total_findings} findings (expected 0)`);
  }
  if (valid.report.counters.crm_writing_files !== 0) {
    failures.push('crm compliant fixture was counted as a CRM table writer');
  }
}

// The probe exemption is what allows diagnostics fixtures to create and clean up
// their own rows. If it stops working, the real gate starts failing on ~14 probe
// files; if it over-reaches, a real bypass would be excused. Both directions are
// covered by asserting the probe fixture is exempt AND reports zero findings.
const probe = runValidator('tests/fixtures/crm-ownership/probe-fixture');
if (probe.code !== 0) failures.push(`probe fixture exited ${probe.code} (expected 0, exemption)`);
if (probe.report) {
  if (probe.report.total_findings !== 0) {
    failures.push(`probe fixture produced ${probe.report.total_findings} findings (expected 0 via exemption)`);
  }
  if (probe.report.counters.probe_fixture_writers < 1) {
    failures.push('probe fixture was not recognized as a diagnostics probe writer');
  }
}

// The fixture-factory exemption lives in a SIBLING directory
// (`core/diagnostics/includes/fixtures/`) rather than `.../probes/`, and it is
// deliberately narrower: a fixtures/ path is only exempt when the file can prove
// teardown (a `destroy()` entry point plus marker-scoped deletes). Without these
// two assertions the exemption could silently stop matching the real factory, or
// silently become a back door that excuses any writer dropped into `fixtures/`.
const proven = runValidator('tests/fixtures/crm-ownership/fixture-factory-proven');
if (proven.code !== 0) failures.push(`proven fixture factory exited ${proven.code} (expected 0, exemption)`);
if (proven.report) {
  if (proven.report.total_findings !== 0) {
    failures.push(`proven fixture factory produced ${proven.report.total_findings} findings (expected 0 via exemption)`);
  }
  if (proven.report.counters.probe_fixture_writers < 1) {
    failures.push('proven fixture factory was not recognized as an exempt diagnostics fixture');
  }
}

const unproven = runValidator('tests/fixtures/crm-ownership/fixture-unproven');
if (unproven.code === 0) {
  failures.push('unproven fixture factory exited 0 (a fixtures/ path without teardown must not be exempt)');
}
if (unproven.report && unproven.report.counters.unproven_fixture_writers < 1) {
  failures.push('unproven fixture factory was not counted as an unproven fixture writer');
}

const repositoryBypass = runValidator('tests/fixtures/crm-ownership/repository-routing-invalid');
if (repositoryBypass.code === 0) failures.push('repository bypass fixture exited 0 (a CRM-owner controller write must fail)');
if (repositoryBypass.report) {
  const rules = new Set((repositoryBypass.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-CRM.mutation_outside_repository')) {
    failures.push('repository bypass fixture did not report R-CRM.mutation_outside_repository');
  }
}

const repositoryValid = runValidator('tests/fixtures/crm-ownership/repository-routing-valid');
if (repositoryValid.code !== 0) failures.push(`repository fixture exited ${repositoryValid.code} (expected 0)`);
if (repositoryValid.report) {
  if (repositoryValid.report.total_findings !== 0) {
    failures.push(`repository fixture produced ${repositoryValid.report.total_findings} findings (expected 0)`);
  }
  if (repositoryValid.report.counters.repository_layer_writers < 1) {
    failures.push('repository fixture was not counted as a repository-layer writer');
  }
}

const harnessValid = runValidator('tests/fixtures/crm-ownership/diagnostics-harness-valid');
if (harnessValid.code !== 0) failures.push(`diagnostics harness fixture exited ${harnessValid.code} (expected 0, exact allowlist + cleanup)`);
if (harnessValid.report && harnessValid.report.total_findings !== 0) {
  failures.push(`diagnostics harness fixture produced ${harnessValid.report.total_findings} findings (expected 0)`);
}

const harnessUnproven = runValidator('tests/fixtures/crm-ownership/diagnostics-harness-unproven');
if (harnessUnproven.code === 0) {
  failures.push('unproven diagnostics harness exited 0 (exact path without cleanup must not be exempt)');
}
if (harnessUnproven.report) {
  const rules = new Set((harnessUnproven.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-CRM.mutation_outside_repository')) {
    failures.push('unproven diagnostics harness did not report R-CRM.mutation_outside_repository');
  }
}

const summary = {
  bypass_fixture: {
    exit: broken.code,
    findings: broken.report ? broken.report.total_findings : null,
    rules: broken.report ? [...new Set((broken.report.findings || []).map((f) => f.rule))] : [],
    expected: 'FAIL reporting direct-sql-outside-owner and write_without_channel_contract',
  },
  compliant_fixture: {
    exit: valid.code,
    findings: valid.report ? valid.report.total_findings : null,
    expected: 'PASS with 0 findings and 0 CRM writers',
  },
  probe_exemption_fixture: {
    exit: probe.code,
    findings: probe.report ? probe.report.total_findings : null,
    probe_writers: probe.report ? probe.report.counters.probe_fixture_writers : null,
    expected: 'PASS via diagnostics-probe exemption',
  },
  fixture_factory_proven: {
    exit: proven.code,
    findings: proven.report ? proven.report.total_findings : null,
    fixture_writers: proven.report ? proven.report.counters.probe_fixture_writers : null,
    expected: 'PASS via exemptions that require a teardown entry point',
  },
  fixture_factory_unproven: {
    exit: unproven.code,
    findings: unproven.report ? unproven.report.total_findings : null,
    unproven_writers: unproven.report ? unproven.report.counters.unproven_fixture_writers : null,
    expected: 'FAIL because a fixtures/ path without teardown is not exempt',
  },
  repository_bypass_fixture: {
    exit: repositoryBypass.code,
    findings: repositoryBypass.report ? repositoryBypass.report.total_findings : null,
    expected: 'FAIL with R-CRM.mutation_outside_repository',
  },
  repository_valid_fixture: {
    exit: repositoryValid.code,
    findings: repositoryValid.report ? repositoryValid.report.total_findings : null,
    repository_writers: repositoryValid.report ? repositoryValid.report.counters.repository_layer_writers : null,
    expected: 'PASS via the declared repository layer',
  },
  diagnostics_harness_valid_fixture: {
    exit: harnessValid.code,
    findings: harnessValid.report ? harnessValid.report.total_findings : null,
    expected: 'PASS via exact harness allowlist plus cleanup',
  },
  diagnostics_harness_unproven_fixture: {
    exit: harnessUnproven.code,
    findings: harnessUnproven.report ? harnessUnproven.report.total_findings : null,
    expected: 'FAIL because the exact harness path has no cleanup',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;