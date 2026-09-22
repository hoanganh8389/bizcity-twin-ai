#!/usr/bin/env node
/**
 * WP7 — route retrieval through the canonical Context Bank/KG facade.
 *
 * `core/twinbrain/includes/class-brain-retrieval-facade.php` docblocks
 * `BizCity_Brain_Retrieval_Facade::pack()` as "the only public read entry
 * point for the bounded Context Bank retrieval pack. Consumers may shape the
 * response for their surface, but may not create a second retrieval path."
 * It composes the canonical builder,
 * `core/context-bank/includes/class-context-bank-retrieval-pack.php`
 * (`BizCity_Context_Bank_Retrieval_Pack::build()`), which itself docblocks
 * "This class owns no storage, no ACL and no semantic extraction. It never
 * reads JSONL, ledger tables or file paths directly; every read goes through
 * the canonical owners above."
 *
 * Nothing previously checked that `::build()` is called ONLY from those two
 * owning directories. This gate is the retrieval-side mirror of WP6's
 * `R-CB.ledger_write_outside_owner`: one admission path in, one read path
 * out.
 *
 *   `R-TB.retrieval_outside_facade` — `BizCity_Context_Bank_Retrieval_Pack::
 *   build(` called from a file outside `core/twinbrain/` (the facade) and
 *   `core/context-bank/` (the builder's own home) creates a second retrieval
 *   path that bypasses the facade's surface shaping and the builder's own
 *   fail-closed/degraded contract.
 *
 * This is static source analysis. It does not execute WordPress, does not
 * prove the facade itself never widens beyond its four authorized surfaces
 * (`twin_gpt|twinchat|mcp|automation`), and does not verify the scope
 * resolver's tenant/identity authority at runtime.
 *
 * Usage:
 *   node bin/validate-brain-retrieval-facade-ownership.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-brain-retrieval-facade-ownership.mjs --fixture-root=<path>
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
  : path.join(root, 'tests', 'fixtures', 'brain-retrieval-facade-ownership', 'baseline.json');
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

/** Canonical owners. A call from inside these prefixes IS the owner. */
const facadeOwner = /(?:^|\/)core\/twinbrain\//;
const builderOwner = /(?:^|\/)core\/context-bank\//;

/**
 * Diagnostics probes/fixtures create their own bounded pack for a runtime
 * check and are not a second retrieval path a real consumer can reach — same
 * exemption directory the WP4/WP5/WP6/WP7 gates already use.
 */
const exemptions = [
  {
    pattern: /(?:^|\/)core\/diagnostics\/includes\/(?:probes|fixtures)\//,
    reason: 'Diagnostics probe/fixture builds its own pack for a runtime check, not a second consumer-reachable retrieval path.',
  },
];

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const buildCallPattern = /\bBizCity_Context_Bank_Retrieval_Pack\s*::\s*build\s*\(/g;

const findings = [];
const counters = {
  files_scanned: 0,
  retrieval_build_calls: 0,
  retrieval_build_owner_calls: 0,
  retrieval_build_exempt_calls: 0,
  retrieval_build_bypass_calls: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    const relative = relativeOf(file);
    counters.files_scanned += 1;
    const source = fs.readFileSync(file, 'utf8');
    const masked = maskStructural(source);

    let match;
    buildCallPattern.lastIndex = 0;
    while ((match = buildCallPattern.exec(masked)) !== null) {
      counters.retrieval_build_calls += 1;
      const line = lineOf(source, match.index);

      if (facadeOwner.test(relative) || builderOwner.test(relative)) {
        counters.retrieval_build_owner_calls += 1;
        continue;
      }
      const exempt = exemptions.find((entry) => entry.pattern.test(relative));
      if (exempt) {
        counters.retrieval_build_exempt_calls += 1;
        continue;
      }

      counters.retrieval_build_bypass_calls += 1;
      findings.push({
        rule: 'R-TB.retrieval_outside_facade',
        file: relative,
        line,
        owner: relative.split('/').slice(0, 2).join('/'),
        missing: ['brain_retrieval_facade'],
        contract: 'PHASE-1.22A WP7 §route retrieval through the canonical Context Bank/KG facade',
        fix_hint: 'Call BizCity_Brain_Retrieval_Facade::pack() instead of BizCity_Context_Bank_Retrieval_Pack::build() directly — a second retrieval path bypasses the facade\'s surface shaping and the builder\'s fail-closed contract.',
        fingerprint: `retrieval_bypass|${relative}|${line}`,
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

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relativeOf(baselinePath),
  counters,
  coverage: counters.retrieval_build_calls === 0
    ? 'ZERO BizCity_Context_Bank_Retrieval_Pack::build() calls found — this gate is unproven'
    : `${counters.retrieval_build_calls} call(s) found across ${counters.files_scanned} PHP files `
      + `(${counters.retrieval_build_owner_calls} owner, ${counters.retrieval_build_exempt_calls} exempt, `
      + `${counters.retrieval_build_bypass_calls} bypass)`,
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: staleBaseline,
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;
