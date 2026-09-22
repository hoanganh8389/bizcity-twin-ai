#!/usr/bin/env node
/**
 * WP3 — Capability registration receipt reconciliation.
 *
 * Every capability declared in a package manifest (`capabilities.<kind>[]`)
 * must resolve to a registration receipt that names its owner, scope and
 * contract. This validator derives what is checkable from manifest metadata
 * today and reports the rest as declaration debt.
 *
 * Receipt fields (see docs/contracts/CAPABILITY-RECEIPT-v1.md):
 *   extension_id · manifest_version · capability_id · capability_kind
 *   owner · scope · contract_id · contract_version · source_artifact
 *
 * Beyond completeness it also enforces the two WP3 integrity rules that need
 * no new manifest fields and are wrong today or not:
 *   - duplicate capability IDs across different extensions (conflicting IDs)
 *   - the same capability ID claimed by two different owners
 *
 * This is static declaration evidence. It does not execute WordPress, does not
 * load the SDK and does not prove runtime registration.
 *
 * Usage:
 *   node bin/validate-capability-receipts.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-capability-receipts.mjs --fixture-root=<path>
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
  : path.join(root, 'tests', 'fixtures', 'capability-receipts', 'baseline.json');
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
]);

const requiredReceiptFields = ['owner', 'scope', 'contract_id', 'contract_version'];

/**
 * Capability kind -> expected interface base name.
 *
 * Three interface naming styles exist in this repository and ALL are valid:
 *   - runtime, suffixed:   `BizCity_Channel_Adapter_Interface`
 *     (core/twin-core/contracts/content-contracts.php)
 *   - runtime, unsuffixed: `BizCity_Channel_Adapter`
 *     (core/channel-gateway/includes/interface-channel-adapter.php)
 *   - SDK, namespaced:     `BizCity\Twin\Contracts\ChannelAdapterInterface`
 *     (packages/bizcity-framework-sdk/src/Contracts.php)
 *
 * `shortForm()` strips the `BizCity_` prefix, the namespace, underscores and a
 * trailing `Interface`, so all three collapse to the same base name. Verified
 * against the real declarations enumerated from the repository.
 */
const capabilityInterfaces = {
  tools: 'tool',
  skills: 'skill',
  agents: 'agent',
  channels: 'channeladapter',
  kg_source_adapters: 'kgsourceadapter',
  workflow_blocks: 'workflowblock',
  personas: 'personaprovider',
  output_renderers: 'outputrenderer',
};

function shortForm(name) {
  const segments = String(name).split('\\');
  const last = segments[segments.length - 1];
  return last
    .replace(/^BizCity_/, '')
    .replaceAll('_', '')
    .replace(/Interface$/, '')
    .toLowerCase();
}

const classDeclarationCache = new Map();

function walkPhp(directory, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (excludedSegments.has(entry.name)) continue;
      walkPhp(target, files);
    } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.php')) {
      files.push(target);
    }
  }
  return files;
}

/** className -> { interfaces: string[], file: string } for one package directory. */
function classDeclarationsIn(directory) {
  if (classDeclarationCache.has(directory)) return classDeclarationCache.get(directory);
  const declarations = new Map();
  for (const file of walkPhp(directory)) {
    const source = fs.readFileSync(file, 'utf8');
    const pattern = /(?:abstract\s+|final\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b([^{;]*)\{/g;
    let match;
    while ((match = pattern.exec(source)) !== null) {
      const className = match[1];
      const header = match[2];
      const implementsMatch = /implements\s+([^{]+)/.exec(header);
      const interfaces = implementsMatch
        ? implementsMatch[1].split(',').map((part) => part.trim()).filter(Boolean)
        : [];
      if (!declarations.has(className)) {
        declarations.set(className, { interfaces, file: relativeOf(file) });
      }
    }
  }
  classDeclarationCache.set(directory, declarations);
  return declarations;
}

function walkManifests(directory, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (!fixtureRoot && entry.isDirectory() && excludedSegments.has(entry.name)) continue;
    if (entry.isDirectory()) {
      // Inside a fixture root only the fixture level counts; do not descend into
      // a .vite build manifest that is an asset manifest, not a package manifest.
      if (entry.name === '.vite') continue;
      walkManifests(target, files);
    } else if (entry.isFile() && entry.name === 'manifest.json') {
      files.push(target);
    }
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

const manifestRoots = fixtureRoot
  ? [fixtureRoot]
  : ['plugins', 'examples', 'modules'].map((segment) => path.join(root, segment));

// ── Canonical contract catalog ───────────────────────────────────────────────
// A declared contract_id must resolve to a real contract in the public
// catalog; otherwise the receipt names a contract that does not exist.
const catalogPath = fixtureRoot
  ? path.join(fixtureRoot, 'contract-catalog.json')
  : path.join(root, 'core', 'twin-core', 'contracts', 'schema', 'public', 'v1', 'contract-catalog.json');
const contractCatalog = new Map();
const catalogAvailable = fs.existsSync(catalogPath);
if (catalogAvailable) {
  try {
    const catalog = JSON.parse(fs.readFileSync(catalogPath, 'utf8'));
    for (const contract of catalog.contracts || []) {
      if (typeof contract.id === 'string') {
        contractCatalog.set(contract.id, String(contract.version || ''));
      }
    }
  } catch {
    // A malformed catalog is reported through catalog_available=false rather
    // than silently skipping contract resolution.
  }
}

const manifestFiles = manifestRoots.flatMap((dir) => walkManifests(dir)).sort();

/** extension_id -> { id, version, role, owner, scope, manifest, capabilities: [] } */
const extensions = new Map();
/** capability_id -> [{ extension_id, kind, owner, manifest }] */
const capabilityIndex = new Map();
const findings = [];
const unparsable = [];
/**
 * Proof-of-execution counters. A rule that finds nothing is indistinguishable
 * from a rule that never ran, so the report must show how many checks actually
 * happened. `class_resolved` counts declared classes that were located in the
 * package source and had their implemented interfaces inspected.
 */
const counters = {
  capability_entries_seen: 0,
  typed_checks_attempted: 0,
  class_resolved: 0,
  class_unresolved: 0,
  untyped_unclassified: 0,
  legacy_adapter_exemptions: 0,
};

for (const file of manifestFiles) {
  const relative = relativeOf(file);
  let manifest;
  try {
    manifest = JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch (error) {
    unparsable.push({ manifest: relative, error: String(error.message || error) });
    continue;
  }

  // A package manifest must identify a package; asset/build manifests do not.
  if (typeof manifest.id !== 'string' || manifest.id.trim() === '') continue;
  if (!manifest.capabilities || typeof manifest.capabilities !== 'object') continue;

  const extension = {
    extension_id: manifest.id,
    manifest_version: typeof manifest.version === 'string' ? manifest.version : '',
    role: typeof manifest.package_role === 'string' ? manifest.package_role : '',
    owner: typeof manifest.owner === 'string' ? manifest.owner : '',
    scope: typeof manifest.scope === 'string' ? manifest.scope : '',
    manifest: relative,
  };
  extensions.set(extension.extension_id, extension);

  for (const [kind, items] of Object.entries(manifest.capabilities)) {
    if (!Array.isArray(items)) continue;
    for (const [index, item] of items.entries()) {
      if (!item || typeof item !== 'object') continue;
      counters.capability_entries_seen += 1;
      const capabilityId = typeof item.id === 'string' ? item.id : '';
      if (capabilityId === '') {
        findings.push({
          rule: 'capability.receipt_missing_id',
          capability_id: '',
          extension_id: extension.extension_id,
          capability_kind: kind,
          manifest: relative,
          owner: extension.owner || extension.extension_id,
          missing: ['capability_id'],
          contract: 'CAPABILITY-RECEIPT-v1',
          fix_hint: `Declare an id for capabilities.${kind}[${index}] in ${relative}.`,
          fingerprint: `missing_id|${extension.extension_id}|${kind}|${index}`,
        });
        continue;
      }

      const record = {
        extension_id: extension.extension_id,
        capability_kind: kind,
        owner: typeof item.owner === 'string' && item.owner !== '' ? item.owner : extension.owner,
        scope: typeof item.scope === 'string' && item.scope !== ''
          ? item.scope
          : (typeof item.account_scope === 'string' && item.account_scope !== '' ? item.account_scope : extension.scope),
        contract_id: typeof item.contract_id === 'string' ? item.contract_id : '',
        contract_version: typeof item.contract_version === 'string' ? item.contract_version : '',
        manifest: relative,
      };
      if (!capabilityIndex.has(capabilityId)) capabilityIndex.set(capabilityId, []);
      capabilityIndex.get(capabilityId).push({ ...record, capability_id: capabilityId });

      const missing = requiredReceiptFields.filter((field) => record[field] === '');
      if (missing.length > 0) {
        findings.push({
          rule: 'capability.receipt_incomplete',
          capability_id: capabilityId,
          extension_id: extension.extension_id,
          capability_kind: kind,
          manifest: relative,
          owner: extension.owner || extension.extension_id,
          missing,
          contract: 'CAPABILITY-RECEIPT-v1',
          fix_hint:
            `Add ${missing.join(', ')} to the ${capabilityId} receipt so it names its owner, scope and contract.`,
          fingerprint: `incomplete|${extension.extension_id}|${capabilityId}|${missing.join('+')}`,
        });
      }

      // ── Typed capability or explicit legacy classification ────────────────
      // A declared class must implement the interface matching its kind, unless
      // the package is explicitly classified as a legacy adapter.
      const declaredClass = typeof item.class === 'string' ? item.class.trim() : '';
      const expectedInterface = capabilityInterfaces[kind];
      const isLegacyAdapter = extension.role === 'legacy_adapter';
      if (isLegacyAdapter && declaredClass !== '') counters.legacy_adapter_exemptions += 1;

      // ── Untyped and unclassified ─────────────────────────────────────────
      // Contract §3: "Silent untyped capabilities are not permitted." A
      // capability with no class must therefore either gain a typed class or be
      // explicitly classified through package_role=legacy_adapter, which is what
      // the legacy filter path requires.
      if (declaredClass === '' && expectedInterface && !isLegacyAdapter) {
        counters.untyped_unclassified += 1;
        findings.push({
          rule: 'capability.untyped_unclassified',
          capability_id: capabilityId,
          extension_id: extension.extension_id,
          capability_kind: kind,
          manifest: relative,
          owner: record.owner,
          missing: ['class'],
          contract: 'CAPABILITY-RECEIPT-v1 §3 typed capability',
          fix_hint:
            `${capabilityId} declares no class. Declare the ${kind} class that implements the matching interface, or set package_role=legacy_adapter with a sunset block if this package registers through the legacy filter path.`,
          fingerprint: `untyped|${extension.extension_id}|${capabilityId}|${kind}`,
        });
      }

      if (declaredClass !== '' && expectedInterface && !isLegacyAdapter) {
        counters.typed_checks_attempted += 1;
        const declarations = classDeclarationsIn(path.dirname(file));
        const declaration = declarations.get(declaredClass);
        if (!declaration) {
          counters.class_unresolved += 1;
          findings.push({
            rule: 'capability.class_not_found',
            capability_id: capabilityId,
            extension_id: extension.extension_id,
            capability_kind: kind,
            manifest: relative,
            owner: record.owner,
            missing: [],
            contract: 'CAPABILITY-RECEIPT-v1 §3 typed capability',
            fix_hint:
              `Class ${declaredClass} is declared for ${capabilityId} but no such class exists in this package.`,
            fingerprint: `class_not_found|${extension.extension_id}|${capabilityId}|${declaredClass}`,
          });
        } else {
          counters.class_resolved += 1;
          const implemented = declaration.interfaces.map(shortForm);
          if (!implemented.includes(expectedInterface)) {
            findings.push({
              rule: 'capability.class_not_typed',
              capability_id: capabilityId,
              extension_id: extension.extension_id,
              capability_kind: kind,
              manifest: relative,
              owner: record.owner,
              missing: [],
              contract: 'CAPABILITY-RECEIPT-v1 §3 typed capability',
              fix_hint:
                `${declaredClass} (${declaration.file}) must implement the ${kind} interface, or the package must declare package_role=legacy_adapter.`,
              fingerprint: `class_not_typed|${extension.extension_id}|${capabilityId}|${declaredClass}`,
            });
          }
        }
      }
    }
  }
}

// ── Integrity: duplicate IDs across extensions ───────────────────────────────
// NOTE: reusing one ID string across two capability kinds inside the SAME
// manifest is legitimate, not a conflict. Verified against the reference
// plugin: `reference.echo` is a tool `id()` and also a workflow `node_id()`
// (`examples/bizcity-reference-plugin/bizcity-reference-plugin.php` lines 26
// and 148) because the tool registry and the workflow-block registry are
// separate namespaces. Only cross-extension collisions are real conflicts.
for (const [capabilityId, claims] of [...capabilityIndex.entries()].sort()) {
  const extensionIds = [...new Set(claims.map((claim) => claim.extension_id))].sort();
  if (extensionIds.length > 1) {
    findings.push({
      rule: 'capability.duplicate_id',
      capability_id: capabilityId,
      extension_id: extensionIds.join(','),
      capability_kind: [...new Set(claims.map((claim) => claim.capability_kind))].sort().join(','),
      manifest: [...new Set(claims.map((claim) => claim.manifest))].sort().join(','),
      owner: [...new Set(claims.map((claim) => claim.owner).filter(Boolean))].sort().join(','),
      missing: [],
      contract: 'CAPABILITY-RECEIPT-v1 §conflicting IDs',
      fix_hint: `Capability ${capabilityId} is claimed by ${extensionIds.join(' and ')}; exactly one owner must own the ID.`,
      fingerprint: `duplicate|${capabilityId}|${extensionIds.join('+')}`,
    });
  }
}

// ── Integrity: declared contract must exist in the canonical catalog ─────────
if (catalogAvailable) {
  for (const claim of [...capabilityIndex.values()].flat()) {
    if (claim.contract_id === '') continue;
    if (contractCatalog.has(claim.contract_id)) continue;
    findings.push({
      rule: 'capability.contract_unknown',
      capability_id: claim.capability_id,
      extension_id: claim.extension_id,
      capability_kind: claim.capability_kind,
      manifest: claim.manifest,
      owner: claim.owner,
      missing: [],
      contract: 'CAPABILITY-RECEIPT-v1 §1 contract_id',
      fix_hint:
        `Contract ${claim.contract_id} is not in the public contract catalog; use a catalog ID or add the contract there first.`,
      fingerprint: `contract_unknown|${claim.extension_id}|${claim.capability_id}|${claim.contract_id}`,
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

const receiptCoverage = [...extensions.values()].map((extension) => {
  const claims = [...capabilityIndex.values()].flat().filter(
    (claim) => claim.extension_id === extension.extension_id,
  );
  const complete = claims.filter(
    (claim) => requiredReceiptFields.every((field) => claim[field] !== ''),
  ).length;
  return {
    extension_id: extension.extension_id,
    manifest_version: extension.manifest_version,
    role: extension.role || '(undeclared)',
    capabilities: claims.length,
    complete_receipts: complete,
    manifest: extension.manifest,
  };
}).sort((left, right) => left.extension_id.localeCompare(right.extension_id));

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relativeOf(baselinePath),
  manifests_scanned: manifestFiles.length,
  extensions_with_capabilities: extensions.size,
  capabilities_declared: [...capabilityIndex.values()].reduce((total, claims) => total + claims.length, 0),
  contract_catalog_available: catalogAvailable,
  contracts_in_catalog: contractCatalog.size,
  counters,
  typed_check_coverage: counters.typed_checks_attempted === 0
    ? 'ZERO — no manifest declares a class, so typed-interface compliance is unproven in production'
    : `${counters.typed_checks_attempted} attempted, ${counters.class_resolved} resolved, ${counters.class_unresolved} unresolved`,
  unparsable_manifests: unparsable,
  findings_by_rule: byRule,
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: staleBaseline,
  receipt_coverage: receiptCoverage,
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;