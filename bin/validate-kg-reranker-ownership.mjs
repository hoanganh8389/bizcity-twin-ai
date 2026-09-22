#!/usr/bin/env node
/**
 * WP7 — route rerank through the canonical KG-Hub reranker.
 *
 * `core/knowledge/kg-hub/includes/class-kg-reranker.php` (`BizCity_KG_Reranker`,
 * "LLM-based relation reranker (single-pass, port from vector-graph-rag)") has
 * exactly one production caller today —
 * `core/knowledge/kg-hub/includes/class-kg-retriever.php:138` — which is
 * itself the documented single retrieval entrypoint
 * ("All retrieval goes through BizCity_KG_Retriever::instance()->ask()",
 * `core/mcp/includes/class-brain-mcp-service.php:9`). Nothing currently
 * enforces that this stays true; a second direct caller would rerank results
 * outside the retriever's own budget/degrade/cache handling around it.
 *
 * This is the "final context" half of WP7's "route rerank/final context
 * through the canonical core pack" item covered by a DIFFERENT mechanism
 * already: the actual final-context assembly
 * (`graph_vector_rerank_pack.final_context_chunks`) is built by
 * `BizCity_TwinBrain_Notebook_Source_Layer::build_graph_vector_rerank_pack()`,
 * a PRIVATE method — PHP's own visibility rules already make a second caller
 * structurally impossible, so no gate is needed for that half; only the
 * reranker's own ownership (enforced by convention, not by the language) is
 * gated here.
 *
 *   `R-TB.reranker_outside_owner` — a `BizCity_KG_Reranker::` reference from
 *   a file outside `core/knowledge/kg-hub/` is a second rerank path.
 *
 * This is static source analysis. It does not execute WordPress and does not
 * prove the retriever's own single call site is itself correct or that
 * reranking behaves consistently across providers.
 *
 * Usage:
 *   node bin/validate-kg-reranker-ownership.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-kg-reranker-ownership.mjs --fixture-root=<path>
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
  : path.join(root, 'tests', 'fixtures', 'kg-reranker-ownership', 'baseline.json');
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

/** Canonical owner. A reference from inside this prefix IS the owner. */
const rerankerOwner = /(?:^|\/)core\/knowledge\/kg-hub\//;

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const rerankerReferencePattern = /\bBizCity_KG_Reranker\b/g;

const findings = [];
const counters = {
  files_scanned: 0,
  reranker_references: 0,
  reranker_owner_references: 0,
  reranker_bypass_references: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    const relative = relativeOf(file);
    counters.files_scanned += 1;
    const source = fs.readFileSync(file, 'utf8');
    const masked = maskStructural(source);

    let match;
    rerankerReferencePattern.lastIndex = 0;
    while ((match = rerankerReferencePattern.exec(masked)) !== null) {
      counters.reranker_references += 1;
      const line = lineOf(source, match.index);

      if (rerankerOwner.test(relative)) {
        counters.reranker_owner_references += 1;
        continue;
      }

      counters.reranker_bypass_references += 1;
      findings.push({
        rule: 'R-TB.reranker_outside_owner',
        file: relative,
        line,
        owner: relative.split('/').slice(0, 2).join('/'),
        missing: ['kg_reranker_owner'],
        contract: 'PHASE-1.22A WP7 §route rerank/final context through the canonical core pack',
        fix_hint: 'Call BizCity_KG_Retriever::instance()->search()/ask() instead of BizCity_KG_Reranker directly — the retriever is the documented single retrieval entrypoint and already wraps reranking with its own budget/degrade handling.',
        fingerprint: `reranker_bypass|${relative}|${line}`,
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
  coverage: counters.reranker_references === 0
    ? 'ZERO BizCity_KG_Reranker references found — this gate is unproven'
    : `${counters.reranker_references} reference(s) across ${counters.files_scanned} PHP files `
      + `(${counters.reranker_owner_references} owner, ${counters.reranker_bypass_references} bypass)`,
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: staleBaseline,
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;
