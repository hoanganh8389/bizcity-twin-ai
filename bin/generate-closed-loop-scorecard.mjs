#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const registryPath = path.join(root, 'docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));

const stages = [
  'package',
  'loader',
  'identity',
  'channel',
  'crm',
  'context_bank',
  'knowledge',
  'brain',
  'mcp',
  'action',
  'error',
  'storage',
  'diagnostics',
  'release',
];

function classifyRole(packageEntry) {
  if (packageEntry.kind === 'reference') return 'reference_only';
  if (packageEntry.kind === 'legacy_adapter') return 'legacy_adapter';
  if (packageEntry.tier === 'core') return 'core';
  if (packageEntry.tier === 'module') return 'module';

  const channelIds = new Set([
    'bizcity.twin-crm',
    'bizcity.zalo-bot',
    'bizcity.facebook-bot',
    'bizcity.zalo-personal',
  ]);
  if (channelIds.has(packageEntry.id)) return 'channel_owner';

  return 'framework_integrated';
}

function stageRow(stage) {
  return {
    stage,
    applicability: 'pending_review',
    status: 'deferred',
    evidence: ['registry_discovery'],
    fix_hint: 'Review role-specific applicability and attach current Disk/Loader/Runtime evidence before promotion.',
  };
}

const packages = [
  ...(registry.modules || []),
  ...(registry.plugins || []),
].map((packageEntry) => ({
  id: packageEntry.id,
  path: packageEntry.path,
  kind: packageEntry.kind,
  registry_status: packageEntry.status,
  role: classifyRole(packageEntry),
  role_source: 'inferred_from_registry_kind_tier_and_package_id',
  distribution: 'public_framework_registry',
  stages: stages.map(stageRow),
}));

const scorecard = {
  contract: 'bizcity.closed-loop-adoption-scorecard',
  version: '1.0.0',
  source_registry: 'docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json',
  evidence_boundary: 'baseline_discovery_only',
  package_count: packages.length,
  stage_count: stages.length,
  stage_catalog: stages,
  packages,
  summary: {
    registry_discovery: packages.length,
    deferred_stage_rows: packages.length * stages.length,
    pass_stage_rows: 0,
    runtime_evidence_rows: 0,
    closed_loop_rows: 0,
  },
};

if (packages.length !== 14) {
  throw new Error(`Expected 14 registry packages, found ${packages.length}`);
}

if (stages.length !== 14) {
  throw new Error(`Expected 14 adoption stages, found ${stages.length}`);
}

process.stdout.write(`${JSON.stringify(scorecard, null, 2)}\n`);
