import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const manifestPath = path.join(root, 'docs', 'contracts', 'LEGACY-29-CONTEXT-BANK-MANIFEST-v1.json');
const lifecyclePath = path.join(root, 'docs', 'roadmaps', 'PHASE-1.30-LEGACY-TABLE-LIFECYCLE.md');
const policyPath = path.join(root, 'core', 'helper', 'class-bizcity-legacy-table-policy.php');
const catalogPath = path.join(root, 'core', 'diagnostics', 'includes', 'class-diagnostics-table-registry.php');

const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
const lifecycle = fs.readFileSync(lifecyclePath, 'utf8');
const policy = fs.readFileSync(policyPath, 'utf8');
const catalog = fs.readFileSync(catalogPath, 'utf8');
const entries = Array.isArray(manifest.entries) ? manifest.entries : [];
const required = [
  'id', 'legacy_name', 'physical_table', 'kind', 'data_role', 'criticality',
  'storage_target', 'context_bank_role', 'canonical_owner', 'probe_id', 'status',
  'blockers',
];
const findings = [];

const unique = (values) => [...new Set(values)];
const retiredBlock = policy.match(/private\s+static\s+\$retired\s*=\s*array\s*\(([\s\S]*?)\);/);
const retiredNames = retiredBlock
  ? unique([...retiredBlock[1].matchAll(/['"]([a-z0-9_]+)['"]/gi)].map((match) => match[1]))
  : [];
const deprecatedBlock = catalog.match(/public static function deprecated_tables\(\): array \{([\s\S]*?)\$out = apply_filters/);
const deprecatedNames = deprecatedBlock
  ? unique([...deprecatedBlock[1].matchAll(/\[\s*'name'\s*=>\s*'([^']+)'/g)].map((match) => match[1]))
  : [];

if (manifest.schema_version !== '1.0.0') findings.push('unexpected manifest schema version');
if (entries.length !== 29) findings.push(`expected 29 entries, found ${entries.length}`);
if (retiredNames.length !== 47) findings.push(`expected retirement_count=47, found ${retiredNames.length}`);
if (deprecatedNames.length !== 48) findings.push(`expected catalog_count=48, found ${deprecatedNames.length}`);
if (!manifest.rules?.memory_payload_sql_forbidden) findings.push('memory SQL payload prohibition is not enabled');
if (!manifest.rules?.context_bank_ledger_payload_forbidden) findings.push('ledger payload prohibition is not enabled');

const ids = new Set();
const physicalNames = new Map();
for (const entry of entries) {
  for (const field of required) {
    if (!(field in entry)) findings.push(`${entry.id ?? 'unknown'}: missing ${field}`);
  }
  if (ids.has(entry.id)) findings.push(`duplicate entry id: ${entry.id}`);
  ids.add(entry.id);
  if (!Array.isArray(entry.blockers)) findings.push(`${entry.id}: blockers must be an array`);
  if (entry.kind === 'physical_candidate' && entry.physical_table) {
    physicalNames.set(entry.physical_table, (physicalNames.get(entry.physical_table) ?? 0) + 1);
  }
  if (entry.context_bank_role === 'memory' || entry.context_bank_role === 'memory_or_rule_reference') {
    if (entry.storage_target !== 'filestore') findings.push(`${entry.id}: memory role is not filestore-backed`);
    if (!entry.contract_id) findings.push(`${entry.id}: memory role has no file contract`);
    if (entry.status === 'mapped') findings.push(`${entry.id}: memory role cannot be marked mapped before ledger/adapter evidence`);
  }
  if (entry.storage_target === 'filestore' && !entry.contract_id) findings.push(`${entry.id}: filestore target has no contract_id`);
  if (entry.storage_target === 'jsonl' && !entry.contract_id) findings.push(`${entry.id}: jsonl target has no contract_id`);
  if (entry.kind === 'unreconciled_alias' && entry.status === 'mapped') findings.push(`${entry.id}: unreconciled alias cannot be mapped`);
}

for (const [physical, count] of physicalNames) {
  if (count > 1) findings.push(`physical candidate appears more than once without alias classification: ${physical}`);
}

if (!lifecycle.includes('LEGACY-29-CONTEXT-BANK-MANIFEST-v1.json')) {
  findings.push('lifecycle roadmap does not link the exact 29-entry assessment manifest');
}
if (!lifecycle.includes('catalog_count') || !lifecycle.includes('retirement_count') || !lifecycle.includes('maturity_dataset_count')) {
  findings.push('lifecycle roadmap does not distinguish catalog_count, retirement_count and maturity_dataset_count');
}

if (findings.length) {
  console.error('CONTEXT BANK LEGACY MANIFEST FAIL');
  for (const finding of findings) console.error(`- ${finding}`);
  process.exit(1);
}

const byStatus = Object.groupBy(entries, (entry) => entry.status);
const byTarget = Object.groupBy(entries, (entry) => entry.storage_target);
console.log(`CONTEXT BANK LEGACY MANIFEST PASS (entries=${entries.length}; statuses=${JSON.stringify(Object.fromEntries(Object.entries(byStatus).map(([key, value]) => [key, value.length])))}; targets=${JSON.stringify(Object.fromEntries(Object.entries(byTarget).map(([key, value]) => [key, value.length])))})`);
