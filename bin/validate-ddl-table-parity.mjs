#!/usr/bin/env node
/**
 * Reconcile DDL tables across changelog, diagnostics table registry and schema registry.
 *
 * Three canonical sources describe managed tables:
 *   1. `core/diagnostics/changelog/*.json`  -> per-module schema changelog (R-DCL)
 *   2. `BizCity_Diagnostics_Table_Registry` -> diagnostics inventory (name/owner/group)
 *   3. `BizCity_Schema_Registry::register()` -> register-before-create convention (R-CR)
 *
 * For every table declared in a changelog this validator requires matching
 * evidence in the diagnostics registry and in a schema-registry registration.
 * It also reports tables present in a registry without any changelog entry.
 *
 * This is a static parity gate. It does not execute WordPress, does not read a
 * physical shard and does not authorize DROP.
 *
 * Usage:
 *   node bin/validate-ddl-table-parity.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-ddl-table-parity.mjs --fixture-root=tests/fixtures/ddl-table-parity/invalid
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
  : path.join(root, 'tests', 'fixtures', 'ddl-table-parity', 'baseline.json');
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

// ── 1. Changelog declarations ────────────────────────────────────────────────
const changelogTables = new Map();
const changelogDirs = fixtureRoot
  ? [fixtureRoot]
  : [path.join(root, 'core', 'diagnostics', 'changelog')];
const changelogFiles = changelogDirs
  .flatMap((dir) => walk(dir, '.json'))
  .filter((file) => !relativeOf(file).includes('/_shared/'));

for (const file of changelogFiles) {
  const relative = relativeOf(file);
  let parsed;
  try {
    parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    continue;
  }
  const moduleId = typeof parsed.module_id === 'string' ? parsed.module_id : '';
  const owner = typeof parsed.owner === 'string' ? parsed.owner : '';
  const tables = parsed.tables && typeof parsed.tables === 'object' ? parsed.tables : {};
  for (const tableName of Object.keys(tables)) {
    changelogTables.set(tableName, {
      table: tableName,
      module_id: moduleId,
      owner,
      changelog: relative,
    });
  }
}

// ─ 2. Diagnostics table registry seed ───────────────────────────────────────
const registryPath = fixtureRoot
  ? path.join(fixtureRoot, 'class-diagnostics-table-registry.php')
  : path.join(root, 'core', 'diagnostics', 'includes', 'class-diagnostics-table-registry.php');
const diagnosticsTables = new Map();
if (fs.existsSync(registryPath)) {
  const source = fs.readFileSync(registryPath, 'utf8');
  const pattern = /\[\s*'name'\s*=>\s*'([a-z0-9_]+)'\s*,\s*'owner'\s*=>\s*'([^']+)'/g;
  let match;
  while ((match = pattern.exec(source)) !== null) {
    diagnosticsTables.set(match[1], {
      table: match[1],
      owner: match[2],
      registry_file: relativeOf(registryPath),
    });
  }
}

// ── 3. Schema registry registrations ─────────────────────────────────────────
const schemaRegistrations = new Map();
const schemaScanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes'].map((segment) => path.join(root, segment));
for (const scanRoot of schemaScanRoots) {
  for (const file of walk(scanRoot, '.php')) {
    const source = fs.readFileSync(file, 'utf8');
    if (!source.includes('Schema_Registry::register')) continue;
    const pattern = /Schema_Registry::register\s*\(\s*'([a-z0-9_]+)'/g;
    let match;
    while ((match = pattern.exec(source)) !== null) {
      schemaRegistrations.set(match[1], relativeOf(file));
    }
  }
}

// ── Reconciliation ───────────────────────────────────────────────────────────
const findings = [];
for (const [table, entry] of [...changelogTables.entries()].sort()) {
  const missing = [];
  if (!diagnosticsTables.has(table)) missing.push('diagnostics_registry');
  if (!schemaRegistrations.has(table)) missing.push('schema_registry');
  if (missing.length === 0) continue;
  findings.push({
    table,
    module_id: entry.module_id,
    owner: entry.owner || entry.module_id,
    changelog: entry.changelog,
    missing,
    diagnostics_registry: diagnosticsTables.has(table),
    schema_registry: schemaRegistrations.get(table) || '',
    contract: 'R-DCL changelog + R-CR schema registry + diagnostics table registry',
    fix_hint: `Add ${table} to ${missing.join(' and ')} so changelog, diagnostics inventory and register-before-create agree.`,
    fingerprint: `${table}|${missing.join('+')}`,
  });
}

const orphanDiagnostics = [...diagnosticsTables.keys()]
  .filter((table) => !changelogTables.has(table))
  .sort();
const orphanSchemaRegistrations = [...schemaRegistrations.keys()]
  .filter((table) => !changelogTables.has(table))
  .sort();

const baselineFingerprints = new Set(
  (fixtureRoot ? [] : (baseline.known_findings || [])).map((item) => item.fingerprint),
);
const newFindings = findings.filter((item) => !baselineFingerprints.has(item.fingerprint));
const missingBaseline = fixtureRoot
  ? []
  : (baseline.known_findings || []).filter(
    (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
  );

const findingsMissingBoth = findings.filter((finding) => finding.missing.length === 2).length;
const findingsMissingDiagnosticsOnly = findings.filter(
  (finding) => finding.missing.length === 1 && finding.missing[0] === 'diagnostics_registry',
).length;
const findingsMissingSchemaOnly = findings.filter(
  (finding) => finding.missing.length === 1 && finding.missing[0] === 'schema_registry',
).length;

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relativeOf(baselinePath),
  changelog_files_scanned: changelogFiles.length,
  changelog_tables: changelogTables.size,
  diagnostics_registry_tables: diagnosticsTables.size,
  schema_registrations: schemaRegistrations.size,
  total_findings: findings.length,
  findings_missing_both: findingsMissingBoth,
  findings_missing_diagnostics_only: findingsMissingDiagnosticsOnly,
  findings_missing_schema_only: findingsMissingSchemaOnly,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: missingBaseline,
  diagnostics_registry_tables_without_changelog: orphanDiagnostics,
  schema_registrations_without_changelog: orphanSchemaRegistrations,
  // Informational only: a registry entry without a changelog row is not itself a
  // DDL bypass, but it does mean R-DCL coverage is incomplete for that table.
  orphan_counts: {
    diagnostics_registry_without_changelog: orphanDiagnostics.length,
    schema_registrations_without_changelog: orphanSchemaRegistrations.length,
  },
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;