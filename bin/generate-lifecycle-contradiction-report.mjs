#!/usr/bin/env node
// Report contradictions between the diagnostics table registry and active legacy-table callers.
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const registryPath = path.join(root, 'core/diagnostics/includes/class-diagnostics-table-registry.php');
const registrySource = fs.readFileSync(registryPath, 'utf8');

const excludedDirectoryNames = new Set([
  '_archived',
  '_library',
  'vendor',
  'node_modules',
  'build',
  'dist',
  'docs',
  'tests',
]);
const sourceExtensions = new Set(['.php', '.js', '.mjs', '.ts', '.tsx']);

function parseList(value) {
  if (!value) return [];
  return [...value.matchAll(/'([^']+)'/g)].map((match) => match[1]);
}

function parseRegistryRows() {
  const rows = [];
  const rowPattern = /\[\s*'name'\s*=>\s*'([^']+)'([^\n]*)\],/g;
  for (const match of registrySource.matchAll(rowPattern)) {
    const name = match[1];
    const body = match[2];
    const lifecycle = body.match(/'lifecycle'\s*=>\s*'([^']+)'/)?.[1] ?? '';
    const sqlStatus = body.match(/'sql_status'\s*=>\s*'([^']+)'/)?.[1] ?? '';
    if (!lifecycle && !sqlStatus) continue;
    rows.push({
      name,
      owner: body.match(/'owner'\s*=>\s*'([^']+)'/)?.[1] ?? '',
      lifecycle,
      sql_status: sqlStatus,
      replacement_status: body.match(/'replacement_status'\s*=>\s*'([^']+)'/)?.[1] ?? '',
      readers: parseList(body.match(/'readers'\s*=>\s*\[([^\]]*)\]/)?.[1]),
      writers: parseList(body.match(/'writers'\s*=>\s*\[([^\]]*)\]/)?.[1]),
      orphan_gate: body.match(/'orphan_gate'\s*=>\s*'([^']+)'/)?.[1] ?? '',
      source: 'class-diagnostics-table-registry.php',
    });
  }
  return rows;
}

function listSourceFiles(directory) {
  const files = [];
  const entries = fs.readdirSync(directory, { withFileTypes: true });
  for (const entry of entries) {
    if (excludedDirectoryNames.has(entry.name)) continue;
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      files.push(...listSourceFiles(absolute));
      continue;
    }
    if (sourceExtensions.has(path.extname(entry.name).toLowerCase())) files.push(absolute);
  }
  return files;
}

function findActiveReferences(name, files) {
  const matches = [];
  for (const file of files) {
    const text = fs.readFileSync(file, 'utf8');
    if (!text.includes(name)) continue;
    const relative = path.relative(root, file).replaceAll(path.sep, '/');
    const referenceKind = relative === 'core/diagnostics/includes/class-diagnostics-table-registry.php'
      ? 'registry'
      : relative.startsWith('core/diagnostics/includes/probes/')
        ? 'diagnostics_probe'
        : relative.includes('/migrations/') || relative.startsWith('core/diagnostics/changelog/')
          ? 'migration'
          : relative.startsWith('core/diagnostics/includes/')
            ? 'diagnostics_policy'
            : 'runtime_owner';
    const lineNumbers = [];
    text.split(/\r?\n/).forEach((line, index) => {
      if (line.includes(name)) lineNumbers.push(index + 1);
    });
    matches.push({ path: relative, reference_kind: referenceKind, lines: lineNumbers.slice(0, 12) });
  }
  return matches;
}

function countReferenceKinds(references) {
  return references.reduce((counts, reference) => {
    counts[reference.reference_kind] = (counts[reference.reference_kind] ?? 0) + 1;
    return counts;
  }, {});
}

const registryRows = parseRegistryRows();
const activeFiles = listSourceFiles(root);
const candidates = registryRows
  .filter((row) => row.lifecycle === 'retired' || row.lifecycle === 'quarantine' || row.sql_status === 'dead')
  .map((row) => ({
    ...row,
    metadata_contradiction: row.lifecycle === 'retired' && (row.readers.length > 0 || row.writers.length > 0),
    active_source_references: findActiveReferences(row.name, activeFiles),
  }))
  .map((row) => ({
    ...row,
    active_reference_count: row.active_source_references.length,
    reference_kind_counts: countReferenceKinds(row.active_source_references),
    runtime_reference_count: row.active_source_references.filter((reference) =>
      reference.reference_kind === 'runtime_owner'
    ).length,
    classification: row.active_source_references.some((reference) =>
      reference.reference_kind === 'runtime_owner'
    )
      ? 'runtime_contradiction_candidate'
      : row.active_source_references.length > 0 || row.metadata_contradiction
        ? 'evidence_only_candidate'
        : 'metadata_only_candidate',
  }));

const report = {
  contract: 'bizcity.lifecycle-contradiction-report',
  version: '1.0.0',
  evidence_boundary: 'static_registry_and_active_source_scan',
  source_registry: 'core/diagnostics/includes/class-diagnostics-table-registry.php',
  exclusions: [...excludedDirectoryNames],
  exclusion_directory_segments: true,
  generated_at: new Date().toISOString(),
  registry_rows_with_lifecycle_metadata: registryRows.length,
  candidate_count: candidates.length,
  contradiction_candidate_count: candidates.filter((row) => row.classification === 'runtime_contradiction_candidate').length,
  evidence_only_candidate_count: candidates.filter((row) => row.classification === 'evidence_only_candidate').length,
  metadata_only_candidate_count: candidates.filter((row) => row.classification === 'metadata_only_candidate').length,
  candidates,
  interpretation: 'A runtime_contradiction_candidate requires owner review. Registry, probe and migration references are evidence-only; static references do not prove runtime writes and do not authorize DROP.',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
