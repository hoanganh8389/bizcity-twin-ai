#!/usr/bin/env node
/**
 * CI runner for the WP4 channel zone/identity gate.
 *
 * Asserts four things, because a gate that cannot fail is not a gate and a
 * guard direction that is never exercised is not proven:
 *   1. the unguarded fixture FAILS with the expected rule
 *   2. the negative-guard fixture PASSES and is proven via the negative path
 *   3. the positive-guard fixture PASSES and is proven via the positive path
 *   4. the two guard fixtures are genuinely independent (each reports only its
 *      own direction), so neither masks the other at file scope
 *
 * Usage: node bin/validate-channel-zone-identity-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-channel-zone-identity.mjs');

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

// 1. Unguarded consumer must fail.
const broken = runValidator('tests/fixtures/channel-zone-identity/invalid');
if (broken.code === 0) failures.push('unguarded fixture exited 0 (a failing gate is required)');
if (!broken.report) failures.push('unguarded fixture produced no parsable report');
else {
  const rules = new Set((broken.report.findings || []).map((finding) => finding.rule));
  if (!rules.has('R-ZONE.zone2_consumer_without_discriminator')) {
    failures.push('unguarded fixture did not report R-ZONE.zone2_consumer_without_discriminator');
  }
}

// 2. Negative guard must pass via the negative path only.
const negative = runValidator('tests/fixtures/channel-zone-identity/negative-only');
if (negative.code !== 0) failures.push(`negative-guard fixture exited ${negative.code} (expected 0)`);
if (negative.report) {
  if (negative.report.total_findings !== 0) {
    failures.push(`negative-guard fixture produced ${negative.report.total_findings} findings (expected 0)`);
  }
  if (negative.report.counters.zone2_consumers_guarded_negative !== 1) {
    failures.push('negative-guard fixture was not proven through the negative guard path');
  }
  if (negative.report.counters.zone2_consumers_guarded_positive !== 0) {
    failures.push('negative-guard fixture unexpectedly matched the positive guard path');
  }
}

// 3. Positive guard must pass via the positive path only.
const positive = runValidator('tests/fixtures/channel-zone-identity/positive-only');
if (positive.code !== 0) failures.push(`positive-guard fixture exited ${positive.code} (expected 0)`);
if (positive.report) {
  if (positive.report.total_findings !== 0) {
    failures.push(`positive-guard fixture produced ${positive.report.total_findings} findings (expected 0)`);
  }
  if (positive.report.counters.zone2_consumers_guarded_positive !== 1) {
    failures.push('positive-guard fixture was not proven through the positive guard path');
  }
  if (positive.report.counters.zone2_consumers_guarded_negative !== 0) {
    failures.push('positive-guard fixture unexpectedly matched the negative guard path');
  }
}

// ── Identity: incomplete tuple must fail with exact missing fields ───────────
const identityBroken = runValidator('tests/fixtures/channel-zone-identity/identity-invalid');
if (identityBroken.code === 0) failures.push('identity-invalid fixture exited 0 (expected nonzero)');
if (!identityBroken.report) failures.push('identity-invalid fixture produced no parsable report');
else {
  const finding = (identityBroken.report.findings || []).find(
    (item) => item.rule === 'R-CH-IDMEM.identity_tuple_incomplete',
  );
  if (!finding) {
    failures.push('identity-invalid fixture did not report R-CH-IDMEM.identity_tuple_incomplete');
  } else {
    const actual = [...finding.missing].sort().join('+');
    if (actual !== 'chat_id+message_id') {
      failures.push(`identity-invalid fixture reported missing=${actual} (expected chat_id+message_id)`);
    }
  }
}

// ── Identity: canonical field names must pass ────────────────────────────────
const identityValid = runValidator('tests/fixtures/channel-zone-identity/identity-valid');
if (identityValid.code !== 0) failures.push(`identity-valid fixture exited ${identityValid.code} (expected 0)`);
if (identityValid.report) {
  if (identityValid.report.total_findings !== 0) {
    failures.push(`identity-valid fixture produced ${identityValid.report.total_findings} findings (expected 0)`);
  }
  if (identityValid.report.counters.identity_emitters_complete !== 1) {
    failures.push('identity-valid fixture was not counted as a complete tuple');
  }
}

// ── Identity: alias support must be proven independently ─────────────────────
// This rule counts per file, so the canonical and alias shapes cannot share one
// fixture without one masking the other. The alias-only fixture is what proves
// `from_user_id` / `conversation_id` / `mid` are still accepted.
const identityAlias = runValidator('tests/fixtures/channel-zone-identity/identity-alias-only');
if (identityAlias.code !== 0) failures.push(`identity-alias fixture exited ${identityAlias.code} (expected 0)`);
if (identityAlias.report) {
  if (identityAlias.report.total_findings !== 0) {
    failures.push(`identity-alias fixture produced ${identityAlias.report.total_findings} findings (expected 0)`);
  }
  if (identityAlias.report.counters.identity_emitters_complete !== 1) {
    failures.push('identity-alias fixture was not counted as a complete tuple (alias support regressed?)');
  }
}

// ── Envelope: contract identity must be carried ──────────────────────────────
const envelopeBroken = runValidator('tests/fixtures/channel-zone-identity/envelope-invalid');
if (envelopeBroken.code === 0) failures.push('envelope-invalid fixture exited 0 (expected nonzero)');
if (!envelopeBroken.report) failures.push('envelope-invalid fixture produced no parsable report');
else {
  const finding = (envelopeBroken.report.findings || []).find(
    (item) => item.rule === 'R-CH-UNI.envelope_missing_contract_identity',
  );
  if (!finding) {
    failures.push('envelope-invalid fixture did not report R-CH-UNI.envelope_missing_contract_identity');
  } else {
    const actual = [...finding.missing].sort().join('+');
    if (actual !== 'contract+version') {
      failures.push(`envelope-invalid fixture reported missing=${actual} (expected contract+version)`);
    }
  }
}

const envelopeValid = runValidator('tests/fixtures/channel-zone-identity/envelope-valid');
if (envelopeValid.code !== 0) failures.push(`envelope-valid fixture exited ${envelopeValid.code} (expected 0)`);
if (envelopeValid.report) {
  if (envelopeValid.report.total_findings !== 0) {
    failures.push(`envelope-valid fixture produced ${envelopeValid.report.total_findings} findings (expected 0)`);
  }
  if (envelopeValid.report.counters.envelope_producers_complete !== 1) {
    failures.push('envelope-valid fixture was not counted as a complete envelope contract');
  }
}

const summary = {
  unguarded_fixture: {
    exit: broken.code,
    findings: broken.report ? broken.report.total_findings : null,
    expected: 'FAIL reporting R-ZONE.zone2_consumer_without_discriminator',
  },
  negative_guard_fixture: {
    exit: negative.code,
    findings: negative.report ? negative.report.total_findings : null,
    guarded_negative: negative.report ? negative.report.counters.zone2_consumers_guarded_negative : null,
    expected: 'PASS proven via negative guard path',
  },
  positive_guard_fixture: {
    exit: positive.code,
    findings: positive.report ? positive.report.total_findings : null,
    guarded_positive: positive.report ? positive.report.counters.zone2_consumers_guarded_positive : null,
    expected: 'PASS proven via positive guard path',
  },
  identity_invalid_fixture: {
    exit: identityBroken.code,
    findings: identityBroken.report ? identityBroken.report.total_findings : null,
    expected: 'FAIL reporting identity_tuple_incomplete missing=chat_id+message_id',
  },
  identity_valid_fixture: {
    exit: identityValid.code,
    findings: identityValid.report ? identityValid.report.total_findings : null,
    complete: identityValid.report ? identityValid.report.counters.identity_emitters_complete : null,
    expected: 'PASS with canonical field names',
  },
  identity_alias_fixture: {
    exit: identityAlias.code,
    findings: identityAlias.report ? identityAlias.report.total_findings : null,
    complete: identityAlias.report ? identityAlias.report.counters.identity_emitters_complete : null,
    expected: 'PASS with alias field names (from_user_id / conversation_id / mid)',
  },
  envelope_invalid_fixture: {
    exit: envelopeBroken.code,
    findings: envelopeBroken.report ? envelopeBroken.report.total_findings : null,
    expected: 'FAIL reporting envelope_missing_contract_identity missing=contract+version',
  },
  envelope_valid_fixture: {
    exit: envelopeValid.code,
    findings: envelopeValid.report ? envelopeValid.report.total_findings : null,
    complete: envelopeValid.report ? envelopeValid.report.counters.envelope_producers_complete : null,
    expected: 'PASS carrying contract + version',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;