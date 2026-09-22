#!/usr/bin/env node
/**
 * Reconcile manifest capability declarations with real registration symbols.
 *
 * For every `capabilities.<kind>[]` entry in a plugin manifest, this validator
 * requires static evidence inside that plugin's own PHP source:
 *   1. the declared `class` name appears (class definition evidence), and
 *   2. the declared `id` appears (registration/wiring evidence).
 *
 * It also reports the registration hook used per capability kind so a reviewer
 * can see whether the plugin wires through the canonical filter.
 *
 * This is a static parity gate. It does not execute WordPress and does not
 * prove runtime registration, consent or execution.
 *
 * Usage:
 *   node bin/validate-manifest-capability-parity.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-manifest-capability-parity.mjs --fixture-root=tests/fixtures/manifest-capability-parity/invalid
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'manifest-capability-parity', 'baseline.json');
const baseline = fs.existsSync(baselinePath)
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
  'languages',
  'tests',
]);

const capabilityHooks = {
  tools: ['bizcity_twin_register_tool', 'bizcity_twin_register_extension_capabilities'],
  skills: ['bizcity_twin_register_skill', 'bizcity_twin_register_extension_capabilities'],
  agents: ['bizcity_twin_register_extension_capabilities'],
  channels: ['bizcity_twin_register_extension_capabilities'],
  kg_source_adapters: ['bizcity_twin_register_extension_capabilities', 'bizcity_kg_register_source_table'],
  workflow_blocks: ['bizcity_twin_register_extension_capabilities'],
  personas: ['bizcity_twin_register_extension_capabilities'],
  output_renderers: ['bizcity_twin_register_extension_capabilities'],
};

function isExcluded(target) {
  if (fixtureRoot) return false;
  const relative = path.relative(root, target);
  return relative.split(path.sep).some((segment) => excludedSegments.has(segment));
}

function walk(directory, extension, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (isExcluded(target)) continue;
    if (entry.isDirectory()) walk(target, extension, files);
    else if (entry.isFile() && target.toLowerCase().endsWith(extension)) files.push(target);
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

const manifestFiles = walk(fixtureRoot || root, '.json')
  .filter((file) => /(?:^|\/)manifest\.json$/.test(relativeOf(file)));

const findings = [];
const scanned = [];

for (const manifestFile of manifestFiles) {
  const manifestRelative = relativeOf(manifestFile);
  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
  } catch {
    continue;
  }
  if (!manifest || !manifest.capabilities || typeof manifest.capabilities !== 'object') continue;

  const pluginDir = path.dirname(manifestFile);
  const phpFiles = walk(pluginDir, '.php');
  const source = phpFiles.map((file) => fs.readFileSync(file, 'utf8')).join('\n');
  if (source === '') continue;

  scanned.push(manifestRelative);

  for (const [kind, entries] of Object.entries(manifest.capabilities)) {
    if (!Array.isArray(entries)) continue;
    const hooks = capabilityHooks[kind] || [];
    const hookUsed = hooks.find((hook) => source.includes(hook)) || '';
    entries.forEach((entry, index) => {
      if (!entry || typeof entry !== 'object') return;
      const classId = typeof entry.class === 'string' ? entry.class.trim() : '';
      const capabilityId = typeof entry.id === 'string' ? entry.id.trim() : '';
      const missing = [];
      if (classId !== '' && !source.includes(classId)) missing.push('class');
      if (capabilityId !== '' && !source.includes(capabilityId)) missing.push('id');
      if (missing.length === 0) return;
      findings.push({
        manifest: manifestRelative,
        kind,
        index,
        capability_id: capabilityId,
        class: classId,
        missing,
        registration_hook: hookUsed,
        owner: manifest.id || 'unknown extension',
        contract: 'manifest capabilities + canonical registration filter',
        fix_hint: `Define ${classId || 'the declared class'} and register ${capabilityId || 'the declared id'} through ${hooks[0] || 'the canonical capability filter'}.`,
        fingerprint: `${manifestRelative}|${kind}|${index}|${capabilityId}|${classId}|${missing.join('+')}`,
      });
    });
  }
}

const baselineFingerprints = new Set(
  (fixtureRoot ? [] : (baseline.known_findings || [])).map((item) => item.fingerprint),
);
const newFindings = findings.filter((item) => !baselineFingerprints.has(item.fingerprint));
const missingBaseline = fixtureRoot
  ? []
  : (baseline.known_findings || []).filter(
    (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
  );

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relativeOf(baselinePath),
  manifests_scanned: scanned.sort(),
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: missingBaseline,
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;