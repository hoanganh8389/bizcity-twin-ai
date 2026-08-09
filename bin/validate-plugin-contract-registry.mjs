#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const registryPath = path.join(root, 'docs/contracts/PLUGIN-CONTRACT-REGISTRY-v1.json');
const registry = JSON.parse(fs.readFileSync(registryPath, 'utf8'));
const errors = [];
const allowedKinds = new Set(['reference', 'framework_integrated', 'legacy_adapter']);
const allowedStatuses = new Set(['pass', 'partial', 'fail', 'review', 'reference_only']);
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
    if (!fs.existsSync(path.join(root, plugin.path))) errors.push(`${prefix} path missing: ${plugin.path}`);
    if (!fs.existsSync(path.join(root, plugin.bootstrap))) errors.push(`${prefix} bootstrap missing: ${plugin.bootstrap}`);
    if (plugin.manifest !== null && !fs.existsSync(path.join(root, plugin.manifest))) {
      errors.push(`${prefix} manifest missing: ${plugin.manifest}`);
    }
    if (plugin.kind === 'reference' && plugin.manifest === null) {
      errors.push(`${prefix} reference package must declare a manifest`);
    }
  }
}

if (errors.length > 0) {
  console.error('PLUGIN CONTRACT REGISTRY FAIL');
  for (const error of errors) console.error(` - ${error}`);
  process.exit(1);
}

console.log(`PLUGIN CONTRACT REGISTRY PASS (${packageCount} packages: ${(registry.plugins || []).length} plugins, ${(registry.modules || []).length} modules)`);
