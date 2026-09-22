#!/usr/bin/env node
// Validate the plugin contract registry: ids, kinds, roles, stages and adoption metadata.
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const registryArgument = process.argv.find((argument) => argument.startsWith('--registry='));
const requireAdoptionMetadata = process.argv.includes('--require-adoption');
const registryPath = registryArgument
  ? path.resolve(root, registryArgument.slice('--registry='.length))
  : path.join(root, 'docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
const errors = [];
const allowedKinds = new Set(['reference', 'framework_integrated', 'legacy_adapter']);
const allowedStatuses = new Set(['pass', 'partial', 'fail', 'review', 'reference_only']);
const allowedRoles = new Set(['core', 'module', 'channel_owner', 'framework_integrated', 'vertical_extension', 'optional_utility', 'private_pro_utility', 'legacy_adapter', 'reference_only']);
const allowedStages = new Set(['package', 'loader', 'identity', 'channel', 'crm', 'context_bank', 'knowledge', 'brain', 'mcp', 'action', 'error', 'storage', 'diagnostics', 'release']);
const allowedDistributions = new Set(['public_framework', 'private_overlay', 'approved_customer', 'legacy_private']);
const ids = new Set();

if (registry.registry_version !== '1.0.0') errors.push('registry_version must be 1.0.0');
if (!Array.isArray(registry.plugins) || registry.plugins.length === 0) errors.push('plugins must be a non-empty array');
if (registry.modules !== undefined && !Array.isArray(registry.modules)) errors.push('modules must be an array when declared');

const collections = [
  ['plugins', registry.plugins || []],
  ['modules', registry.modules || []],
];
let packageCount = 0;

for (const [collectionName, packages] of collections) {
  for (const [index, plugin] of packages.entries()) {
    const prefix = `${collectionName}[${index}]`;
    packageCount += 1;
    for (const field of ['id', 'path', 'kind', 'status', 'bootstrap', 'required_surfaces']) {
      if (!(field in plugin)) errors.push(`${prefix} missing ${field}`);
    }
    if (ids.has(plugin.id)) errors.push(`${prefix} duplicate id ${plugin.id}`);
    ids.add(plugin.id);
    if (!allowedKinds.has(plugin.kind)) errors.push(`${prefix} invalid kind ${plugin.kind}`);
    if (!allowedStatuses.has(plugin.status)) errors.push(`${prefix} invalid status ${plugin.status}`);
    if (!Array.isArray(plugin.required_surfaces) || plugin.required_surfaces.length === 0) {
      errors.push(`${prefix} required_surfaces must be non-empty`);
    }
    if (plugin.role !== undefined && !allowedRoles.has(plugin.role)) {
      errors.push(`${prefix} invalid role ${plugin.role}`);
    }
    if (requireAdoptionMetadata) {
      for (const field of ['role', 'applicable_stages', 'distribution', 'probe_ids']) {
        if (!(field in plugin)) errors.push(`${prefix} missing adoption metadata ${field}`);
      }
      if (plugin.role === 'legacy_adapter' && !('sunset' in plugin)) {
        errors.push(`${prefix} legacy_adapter requires sunset metadata`);
      }
    }
    if (plugin.applicable_stages !== undefined) {
      if (!Array.isArray(plugin.applicable_stages) || plugin.applicable_stages.length === 0) {
        errors.push(`${prefix} applicable_stages must be a non-empty array`);
      } else {
        const duplicateStages = plugin.applicable_stages.filter((stage, stageIndex, stages) => stages.indexOf(stage) !== stageIndex);
        if (duplicateStages.length > 0) errors.push(`${prefix} applicable_stages must be unique`);
        for (const stage of plugin.applicable_stages) {
          if (!allowedStages.has(stage)) errors.push(`${prefix} invalid applicable stage ${stage}`);
        }
      }
    }
    if (plugin.distribution !== undefined && !allowedDistributions.has(plugin.distribution)) {
      errors.push(`${prefix} invalid distribution ${plugin.distribution}`);
    }
    if (plugin.probe_ids !== undefined) {
      if (!Array.isArray(plugin.probe_ids) || plugin.probe_ids.some((probeId) => typeof probeId !== 'string' || !/^[a-z][a-z0-9._-]{2,160}$/.test(probeId))) {
        errors.push(`${prefix} probe_ids must contain stable probe IDs`);
      }
    }
    if (plugin.sunset !== undefined) {
      if (typeof plugin.sunset !== 'object' || plugin.sunset === null || Array.isArray(plugin.sunset)) {
        errors.push(`${prefix} sunset must be an object`);
      } else {
        if (typeof plugin.sunset.owner !== 'string' || plugin.sunset.owner.length < 2) errors.push(`${prefix} sunset.owner is required`);
        if (typeof plugin.sunset.status !== 'string' || !['planned', 'active', 'expired'].includes(plugin.sunset.status)) errors.push(`${prefix} sunset.status is invalid`);
      }
    }
    if (!fs.existsSync(path.join(root, plugin.path))) errors.push(`${prefix} path missing: ${plugin.path}`);
    if (!fs.existsSync(path.join(root, plugin.bootstrap))) errors.push(`${prefix} bootstrap missing: ${plugin.bootstrap}`);
    if (plugin.manifest !== null && !fs.existsSync(path.join(root, plugin.manifest))) {
      errors.push(`${prefix} manifest missing: ${plugin.manifest}`);
    }
    if (plugin.kind === 'reference' && plugin.manifest === null) {
      errors.push(`${prefix} reference package must declare a manifest`);
    }
    if (plugin.tier === 'optional') {
      errors.push(`${prefix} optional feature plugins are outside the framework contract registry`);
    }
  }
}

if (errors.length > 0) {
  console.error('PLUGIN CONTRACT REGISTRY FAIL');
  for (const error of errors) console.error(` - ${error}`);
  process.exit(1);
}

console.log(`PLUGIN CONTRACT REGISTRY PASS (${packageCount} packages: ${(registry.plugins || []).length} plugins, ${(registry.modules || []).length} modules)`);
