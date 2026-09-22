#!/usr/bin/env node
/**
 * CI runner for the WP7 provider gateway isolation gate (Node port of R-GW-8).
 *
 * Asserts four conditions:
 *   1. a bare BizCity_Router_* reference in client code FAILS as
 *      R-GW8.router_reference_outside_gateway
 *   2. a server-only provider key option read from client code FAILS as
 *      R-GW8.provider_key_option_referenced
 *   3. the same Router reference from a Hub-only diagnostics probe PASSES
 *      (exempted, same convention as the WP4/WP5/WP6 diagnostics exemption)
 *   4. routing through BizCity_LLM_Client, with the forbidden class name only
 *      mentioned in a comment/string, PASSES — proves masking, not a naive
 *      grep, decides the match
 *
 * Usage: node bin/validate-provider-gateway-isolation-fixtures.mjs
 */

import { spawnSync } from 'node:child_process';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const validator = path.join(root, 'bin', 'validate-provider-gateway-isolation.mjs');
const base = 'tests/fixtures/provider-gateway-isolation';

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

// 1. Bare Router_* reference in client code.
const routerRef = runValidator(`${base}/router-reference-invalid`);
if (routerRef.code === 0) failures.push('router-reference-invalid fixture exited 0 (expected nonzero)');
if (!routerRef.report) failures.push('router-reference-invalid fixture produced no parsable report');
else if (!rulesOf(routerRef.report).has('R-GW8.router_reference_outside_gateway')) {
  failures.push('router-reference-invalid fixture did not report R-GW8.router_reference_outside_gateway');
}

// 2. Server-only provider key option referenced from client code.
const keyRef = runValidator(`${base}/provider-key-invalid`);
if (keyRef.code === 0) failures.push('provider-key-invalid fixture exited 0 (expected nonzero)');
if (!keyRef.report) failures.push('provider-key-invalid fixture produced no parsable report');
else if (!rulesOf(keyRef.report).has('R-GW8.provider_key_option_referenced')) {
  failures.push('provider-key-invalid fixture did not report R-GW8.provider_key_option_referenced');
}

// 3. Hub-only diagnostics probe must be exempt, not flagged.
const exempt = runValidator(`${base}/exempt-diagnostics-valid`);
if (exempt.code !== 0) failures.push(`exempt-diagnostics-valid fixture exited ${exempt.code} (expected 0)`);
if (exempt.report) {
  if (exempt.report.total_findings !== 0) {
    failures.push(`exempt-diagnostics-valid fixture produced ${exempt.report.total_findings} findings (expected 0 — diagnostics probe exemption)`);
  }
  if (exempt.report.counters.router_reference_exempt !== 1) {
    failures.push('exempt-diagnostics-valid fixture did not count router_reference_exempt=1 (exemption regressed?)');
  }
}

// 4. Canonical gateway usage, forbidden name only in comment/string, PASSES.
const valid = runValidator(`${base}/valid`);
if (valid.code !== 0) failures.push(`valid fixture exited ${valid.code} (expected 0)`);
if (valid.report && valid.report.total_findings !== 0) {
  failures.push(`valid fixture produced ${valid.report.total_findings} findings (expected 0 — comment/string mentions must not match)`);
}

const summary = {
  router_reference_invalid_fixture: {
    exit: routerRef.code,
    findings: routerRef.report ? routerRef.report.total_findings : null,
    expected: 'FAIL reporting R-GW8.router_reference_outside_gateway',
  },
  provider_key_invalid_fixture: {
    exit: keyRef.code,
    findings: keyRef.report ? keyRef.report.total_findings : null,
    expected: 'FAIL reporting R-GW8.provider_key_option_referenced',
  },
  exempt_diagnostics_valid_fixture: {
    exit: exempt.code,
    findings: exempt.report ? exempt.report.total_findings : null,
    expected: 'PASS — Hub-only diagnostics probe is exempt',
  },
  valid_fixture: {
    exit: valid.code,
    findings: valid.report ? valid.report.total_findings : null,
    expected: 'PASS — canonical gateway usage, comment/string mentions ignored',
  },
  status: failures.length === 0 ? 'PASS' : 'FAIL',
  failures,
};

process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
if (failures.length > 0) process.exitCode = 1;
