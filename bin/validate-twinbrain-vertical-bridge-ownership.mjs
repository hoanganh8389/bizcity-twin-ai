#!/usr/bin/env node
/**
 * WP7 — vertical registration through the canonical Brain bridge registry.
 *
 * `manifest.schema.json`'s `verticalMode` definition (frozen: `registry_filter`
 * is a `const` locked to `bizcity_twinbrain_vertical_bridge_registry`) lets any
 * plugin DECLARE a `vertical_modes[]` entry, but nothing previously checked
 * that the declaration is actually REACHABLE: `BizCity_TwinBrain_Vertical_
 * Bridge_Registry::all()` returns a hardcoded PHP array, and no plugin in this
 * codebase calls `add_filter( 'bizcity_twinbrain_vertical_bridge_registry',
 * ... )` — the one real instance (`plugins/bizcoach-pro` declaring `astro`)
 * only works today because a human kept the manifest and the hardcoded row in
 * sync by hand. This gate makes that sync statically provable instead of
 * assumed, in three parts:
 *
 *   1. `R-TB.vertical_unregistered` — a declared `vertical_modes[]` id has NO
 *      matching hardcoded registry row and NO `add_filter(...)` call anywhere
 *      in the owning plugin's PHP source: the declaration is dead.
 *   2. `R-TB.vertical_registry_drift` — a declared id DOES match a hardcoded
 *      row, but a field differs (label/role/output_shape/guest_allowed/
 *      min_plan/owner_plugin, plus the `row()` helper's own fixed defaults for
 *      mpr_layers/automation_mode/channel_entry/admin_surface, read from its
 *      source rather than assumed): the manifest and the registry describe
 *      two different verticals under one id.
 *   3. `R-TB.registry_row_undeclared` — a hardcoded row names a real,
 *      installed plugin as `owner_plugin`, but that plugin's own manifest
 *      never declares the matching `vertical_modes[]` entry: the registry
 *      grants a vertical with no formal contract behind it.
 *
 * This is static parity analysis. It does not execute WordPress, does not
 * prove the `apply_filters()` call actually runs a registered plugin's
 * callback, and does not verify Brain-mode enforcement (allowed tools,
 * output-shape rendering) at runtime.
 *
 * Usage:
 *   node bin/validate-twinbrain-vertical-bridge-ownership.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-twinbrain-vertical-bridge-ownership.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { extractFunctions, maskStructural, matchParen, splitTopLevel } from './lib/php-source.mjs';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'twinbrain-vertical-bridge-ownership', 'baseline.json');
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

function walk(directory, extension, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (excludedSegments.has(entry.name)) continue;
      walk(target, extension, files);
    } else if (entry.isFile() && target.toLowerCase().endsWith(extension)) {
      files.push(target);
    }
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

function unquote(text) {
  const match = /^'([\s\S]*)'$/.exec((text || '').trim());
  return match ? match[1] : (text || '').trim();
}

const scanRoot = fixtureRoot || root;

// ── Registry side: hardcoded rows + the `row()` helper's fixed defaults ────
const registryFile = path.join(scanRoot, 'core', 'twinbrain', 'includes', 'class-twinbrain-vertical-bridge-registry.php');
const registryRelative = relativeOf(registryFile);
const registryRows = new Map();
const registryDefaults = { mpr_layers: [], automation_mode: '', channel_entry: '', admin_surface: '' };

if (fs.existsSync(registryFile)) {
  const registrySource = fs.readFileSync(registryFile, 'utf8');
  const masked = maskStructural(registrySource);

  const rowFn = extractFunctions(registrySource).find((fn) => fn.name === 'row');
  if (rowFn) {
    const mprMatch = /'mpr_layers'\s*=>\s*array\(\s*([^)]*)\)/.exec(rowFn.body);
    if (mprMatch) {
      registryDefaults.mpr_layers = mprMatch[1].split(',')
        .map((n) => Number(n.trim())).filter((n) => !Number.isNaN(n));
    }
    const automationMatch = /'automation_mode'\s*=>\s*'([^']*)'/.exec(rowFn.body);
    if (automationMatch) registryDefaults.automation_mode = automationMatch[1];
    const channelMatch = /'channel_entry'\s*=>\s*'([^']*)'/.exec(rowFn.body);
    if (channelMatch) registryDefaults.channel_entry = channelMatch[1];
    const adminMatch = /'admin_surface'\s*=>\s*'([^']*)'/.exec(rowFn.body);
    if (adminMatch) registryDefaults.admin_surface = adminMatch[1];
  }

  // Every `self::row( ... )` call. Parsed via the shared parser's paren/comma
  // matching (depth-read from the structural mask) rather than a naive
  // comma-split, because several `role` strings are Vietnamese sentences
  // containing literal commas (e.g. "Dữ liệu doanh thu, đơn hàng và khách
  // hàng WooCommerce.") that would otherwise mis-split the argument list.
  const callPattern = /self::row\s*\(/g;
  let callMatch;
  while ((callMatch = callPattern.exec(masked)) !== null) {
    const openIndex = callMatch.index + callMatch[0].length - 1;
    const closeIndex = matchParen(masked, openIndex);
    if (closeIndex === -1) continue;
    const args = splitTopLevel(
      masked.slice(openIndex + 1, closeIndex),
      registrySource.slice(openIndex + 1, closeIndex),
    );
    if (args.length < 7) continue;
    const id = unquote(args[0]);
    if (!id) continue;
    registryRows.set(id, {
      label: unquote(args[1]),
      role: unquote(args[2]),
      owner_plugin: unquote(args[3]),
      output_shape: unquote(args[4]),
      guest_allowed: args[5].trim() === 'true',
      min_plan: unquote(args[6]),
    });
  }
}

// ── Manifest side ────────────────────────────────────────────────────────
const manifestFiles = walk(scanRoot, '.json').filter((file) => /(?:^|\/)manifest\.json$/.test(relativeOf(file)));

const findings = [];
const counters = {
  registry_rows_parsed: registryRows.size,
  manifests_scanned: 0,
  vertical_modes_declared: 0,
  vertical_modes_reachable_via_row: 0,
  vertical_modes_reachable_via_filter: 0,
  vertical_modes_drifted: 0,
  vertical_modes_unregistered: 0,
  registry_rows_with_plugin_owner: 0,
  registry_rows_undeclared: 0,
};

const declaredByOwnerPlugin = new Map();

for (const manifestFile of manifestFiles) {
  const manifestRelative = relativeOf(manifestFile);
  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf8'));
  } catch {
    continue;
  }
  if (!manifest || !Array.isArray(manifest.vertical_modes) || manifest.vertical_modes.length === 0) continue;

  counters.manifests_scanned += 1;
  const pluginDir = path.dirname(manifestFile);
  const pluginDirName = path.basename(pluginDir);
  const phpFiles = walk(pluginDir, '.php');
  const pluginSource = phpFiles.map((file) => fs.readFileSync(file, 'utf8')).join('\n');
  const hasAddFilter = /add_filter\s*\(\s*'bizcity_twinbrain_vertical_bridge_registry'/.test(pluginSource);

  let declaredIds = declaredByOwnerPlugin.get(pluginDirName);
  if (!declaredIds) {
    declaredIds = new Set();
    declaredByOwnerPlugin.set(pluginDirName, declaredIds);
  }

  manifest.vertical_modes.forEach((entry, index) => {
    if (!entry || typeof entry !== 'object') return;
    const id = typeof entry.id === 'string' ? entry.id.trim() : '';
    if (!id) return;
    counters.vertical_modes_declared += 1;
    declaredIds.add(id);

    const row = registryRows.get(id);

    if (!row && !hasAddFilter) {
      counters.vertical_modes_unregistered += 1;
      findings.push({
        rule: 'R-TB.vertical_unregistered',
        file: manifestRelative,
        line: 1,
        vertical_id: id,
        owner: manifest.id || pluginDirName,
        missing: ['registry_reachability'],
        contract: 'PHASE-1.22A WP7 §vertical registration through the canonical bridge registry',
        fix_hint: `vertical_modes[${index}] (id="${id}") is declared but has no matching row in `
          + `BizCity_TwinBrain_Vertical_Bridge_Registry::all() and no add_filter('bizcity_twinbrain_vertical_bridge_registry', ...) `
          + `call anywhere in ${pluginDirName}/ — the declaration is unreachable at runtime.`,
        fingerprint: `vertical_unregistered|${manifestRelative}|${id}`,
      });
      return;
    }

    if (!row) {
      // Reachable only via add_filter(): no hardcoded row exists to diff
      // against, so field-level drift cannot be checked statically.
      counters.vertical_modes_reachable_via_filter += 1;
      return;
    }
    counters.vertical_modes_reachable_via_row += 1;

    const mismatches = [];
    const compareField = (field, manifestValue, registryValue) => {
      if (manifestValue === undefined) return;
      if (JSON.stringify(manifestValue) !== JSON.stringify(registryValue)) mismatches.push(field);
    };
    compareField('label', entry.label, row.label);
    compareField('role', entry.role, row.role);
    compareField('output_shape', entry.output_shape, row.output_shape);
    compareField('guest_allowed', entry.guest_allowed, row.guest_allowed);
    compareField('min_plan', entry.min_plan, row.min_plan);
    if (Array.isArray(entry.mpr_layers)) compareField('mpr_layers', entry.mpr_layers, registryDefaults.mpr_layers);
    compareField('automation_mode', entry.automation_mode, registryDefaults.automation_mode);
    compareField('channel_entry', entry.channel_entry, registryDefaults.channel_entry);
    compareField('admin_surface', entry.admin_surface, registryDefaults.admin_surface);
    if (row.owner_plugin !== pluginDirName) mismatches.push('owner_plugin');

    if (mismatches.length > 0) {
      counters.vertical_modes_drifted += 1;
      findings.push({
        rule: 'R-TB.vertical_registry_drift',
        file: manifestRelative,
        line: 1,
        vertical_id: id,
        owner: manifest.id || pluginDirName,
        missing: mismatches,
        contract: 'PHASE-1.22A WP7 §vertical registration through the canonical bridge registry',
        fix_hint: `vertical_modes[${index}] (id="${id}") declares [${mismatches.join(', ')}] differently from the `
          + `hardcoded row in BizCity_TwinBrain_Vertical_Bridge_Registry::all() (${registryRelative}) — the manifest `
          + 'and the registry describe two different verticals under the same id.',
        fingerprint: `vertical_drift|${manifestRelative}|${id}|${mismatches.join('+')}`,
      });
    }
  });
}

// ── Reverse direction: a registry row naming a real, installed plugin as its
// owner must be declared by that plugin's own manifest. ────────────────────
for (const [id, row] of registryRows.entries()) {
  const ownerPlugin = row.owner_plugin;
  if (!ownerPlugin || ownerPlugin.includes('/')) continue; // 'core/twinbrain' etc. — not a plugin directory
  const pluginManifestPath = path.join(scanRoot, 'plugins', ownerPlugin, 'manifest.json');
  if (!fs.existsSync(pluginManifestPath)) continue; // owner not installed here — nothing to check

  counters.registry_rows_with_plugin_owner += 1;
  const declaredIds = declaredByOwnerPlugin.get(ownerPlugin) || new Set();
  if (!declaredIds.has(id)) {
    counters.registry_rows_undeclared += 1;
    findings.push({
      rule: 'R-TB.registry_row_undeclared',
      file: relativeOf(pluginManifestPath),
      line: 1,
      vertical_id: id,
      owner: ownerPlugin,
      missing: ['manifest_vertical_modes_entry'],
      contract: 'PHASE-1.22A WP7 §vertical registration through the canonical bridge registry',
      fix_hint: `BizCity_TwinBrain_Vertical_Bridge_Registry::all() (${registryRelative}) grants "${id}" to `
        + `owner_plugin="${ownerPlugin}", but ${ownerPlugin}/manifest.json does not declare a matching `
        + 'vertical_modes[] entry — the registration has no formal contract behind it.',
      fingerprint: `registry_undeclared|${ownerPlugin}|${id}`,
    });
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
  // Proof of execution: a rule that inspected nothing is not a passing rule.
  registry_coverage: counters.registry_rows_parsed === 0
    ? 'ZERO registry rows parsed — this gate is unproven'
    : `${counters.registry_rows_parsed} hardcoded rows parsed from ${registryRelative}`,
  manifest_coverage: counters.vertical_modes_declared === 0
    ? 'ZERO vertical_modes[] entries scanned — this gate is unproven'
    : `${counters.vertical_modes_declared} declared entries across ${counters.manifests_scanned} manifests `
      + `(${counters.vertical_modes_reachable_via_row} matched a row, `
      + `${counters.vertical_modes_reachable_via_filter} matched only via add_filter, `
      + `${counters.vertical_modes_unregistered} unreachable)`,
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
