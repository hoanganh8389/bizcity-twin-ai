#!/usr/bin/env node
/**
 * WP7 — prohibit direct provider orchestration from vertical plugins.
 *
 * R-GW-8 already exists as a CI-only bash+PHP grep guard
 * (`.github/workflows/ci.yml`, step "R-GW-8 anti-pattern grep guards"):
 * client code must never reference `BizCity_Router_(Proxy|Auth|Usage|Models)`
 * directly — those classes belong to the separate server-side
 * `bizcity-llm-router` companion, not to this codebase, so ANY match here is
 * a violation, not a debt-to-review — and must route provider calls through
 * `BizCity_LLM_Client` instead. That guard runs only in CI, is PHP-CLI
 * tokenizer based (`token_get_all()`, unavailable in this local toolchain,
 * see CLAUDE.md/README for the Node-only dev setup this repo's other gates
 * assume), and has no baseline/fixtures/evidence trail like the rest of this
 * roadmap. This ports the SAME two checks into that established pattern so
 * WP7 gets the same provable, locally-runnable evidence WP3-WP6 already have
 * — it does not change what is enforced, only how it is proven.
 *
 *   1. `R-GW8.router_reference_outside_gateway` — a bare `BizCity_Router_*`
 *      identifier (class name, static call, `instanceof`, etc.) anywhere in
 *      `core/modules/plugins`. Matched on a structurally masked copy
 *      (comments AND string content blanked) so a docblock or log-message
 *      string that merely MENTIONS the class name is not a violation — the
 *      same distinction PHP's own tokenizer draws between `T_STRING` and
 *      `T_CONSTANT_ENCAPSED_STRING`/comment tokens.
 *   2. `R-GW8.provider_key_option_referenced` — the server-only option name
 *      `bizcity_openrouter_api_key` referenced from client code outside
 *      `docs/`/changelog paths. Matched on the RAW source (comments preserved
 *      but not masked) because this is a string-literal option name
 *      (`get_option( 'bizcity_openrouter_api_key' )`), not a bare identifier.
 *
 * This is static source analysis. It does not execute WordPress and does not
 * prove the gateway class itself never leaks a provider credential at
 * runtime — that remains a server-side (bizcity-llm-router) concern outside
 * this repo.
 *
 * Usage:
 *   node bin/validate-provider-gateway-isolation.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-provider-gateway-isolation.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { maskStructural } from './lib/php-source.mjs';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'provider-gateway-isolation', 'baseline.json');
const baseline = !fixtureRoot && fs.existsSync(baselinePath)
  ? JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
  : { known_findings: [] };

const excludedSegments = new Set([
  '_archived',
  '_library',
  'node_modules',
  'vendor',
  'dist',
  'build',
  '.vite',
  '.git',
  'languages',
  'tests',
]);

function walk(directory, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (excludedSegments.has(entry.name)) continue;
      walk(target, files);
    } else if (entry.isFile() && target.toLowerCase().endsWith('.php')) {
      files.push(target);
    }
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

function lineOf(text, index) {
  return text.slice(0, index).split(/\r?\n/).length;
}

/**
 * Exempt paths for Rule 1 only. One real instance exists in production:
 * `core/diagnostics/includes/probes/class-probe-router-domain-policy-runtime.php`
 * safe-loads `BizCity_Router_Auth`/`BizCity_Router_Usage` and calls them
 * directly, but its own `precondition()` gates it to the B1 Hub domains
 * (`bizcity.vn`/`www.bizcity.vn`) with an explicit "R-GW-8/B2C-G7.4 — B2
 * clients are intentionally not Router owners" comment: this is the Hub-side
 * diagnostics probe FOR the router's own state machine, not a vertical
 * bypassing the gateway. Same exemption directory the WP4/WP5/WP6 gates
 * already use for diagnostics probes/fixtures that legitimately touch what
 * they are testing.
 */
const exemptions = [
  {
    pattern: /(?:^|\/)core\/diagnostics\/includes\/(?:probes|fixtures)\//,
    reason: 'Hub-only diagnostics probe testing the Router state machine itself; precondition() gates it to the B1 Hub domain, and B2 clients are intentionally not Router owners.',
  },
];

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const routerPattern = /\bBizCity_Router_(Proxy|Auth|Usage|Models)\b/g;
const keyOptionLiteral = 'bizcity_openrouter_api_key';

const findings = [];
const counters = {
  files_scanned: 0,
  router_reference_findings: 0,
  router_reference_exempt: 0,
  provider_key_option_findings: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    const relative = relativeOf(file);
    counters.files_scanned += 1;
    const source = fs.readFileSync(file, 'utf8');
    const exempt = exemptions.find((entry) => entry.pattern.test(relative));

    // ── Rule 1: bare Router_* identifier, code only (not comment/string) ───
    const masked = maskStructural(source);
    let match;
    routerPattern.lastIndex = 0;
    while ((match = routerPattern.exec(masked)) !== null) {
      const line = lineOf(source, match.index);
      if (exempt) {
        counters.router_reference_exempt += 1;
        continue;
      }
      counters.router_reference_findings += 1;
      findings.push({
        rule: 'R-GW8.router_reference_outside_gateway',
        file: relative,
        line,
        symbol: match[0],
        missing: ['bizcity_llm_client_gateway'],
        contract: 'R-GW-8 §client code must route provider calls through BizCity_LLM_Client',
        fix_hint: `${match[0]} belongs to the separate server-side bizcity-llm-router companion — route this call through BizCity_LLM_Client instead of referencing it directly.`,
        fingerprint: `router_reference|${relative}|${line}|${match[0]}`,
      });
    }

    // ── Rule 2: server-only provider key option referenced from client code ─
    if (relative.includes('docs/') || relative.includes('changelog')) continue;
    if (source.includes(keyOptionLiteral)) {
      const index = source.indexOf(keyOptionLiteral);
      const line = lineOf(source, index);
      counters.provider_key_option_findings += 1;
      findings.push({
        rule: 'R-GW8.provider_key_option_referenced',
        file: relative,
        line,
        symbol: keyOptionLiteral,
        missing: ['server_only_credential_boundary'],
        contract: 'R-GW-8 §provider API key option is server-only, never read from client code',
        fix_hint: `${keyOptionLiteral} is the server-side provider credential option — client code must not read or reference it directly.`,
        fingerprint: `provider_key_option|${relative}|${line}`,
      });
    }
  }
}

const baselineFingerprints = new Set(
  (fixtureRoot ? [] : (baseline.known_findings || [])).map((item) => item.fingerprint),
);
const newFindings = findings.filter((item) => !baselineFingerprints.has(item.fingerprint));
const staleBaseline = fixtureRoot
  ? []
  : (baseline.known_findings || []).filter(
    (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
  );

const byRule = findings.reduce((accumulator, finding) => {
  accumulator[finding.rule] = (accumulator[finding.rule] || 0) + 1;
  return accumulator;
}, {});

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relativeOf(baselinePath),
  counters,
  coverage: counters.files_scanned === 0
    ? 'ZERO files scanned — this gate is unproven'
    : `${counters.files_scanned} PHP files scanned across core/modules/plugins `
      + `(${counters.router_reference_exempt} exempt Hub-diagnostics reference(s))`,
  findings_by_rule: byRule,
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: staleBaseline,
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;
