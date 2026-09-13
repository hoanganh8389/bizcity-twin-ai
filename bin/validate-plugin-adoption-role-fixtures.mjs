#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const fixturePath = path.join(root, 'tests/fixtures/plugin-adoption-role-fixtures.json');
const fixture = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));
const expectedRoles = [
  'core',
  'module',
  'channel_owner',
  'framework_integrated',
  'vertical_extension',
  'optional_utility',
  'private_pro_utility',
  'legacy_adapter',
  'reference_only',
];
const allowedStages = new Set([
  'package', 'loader', 'identity', 'channel', 'crm', 'context_bank',
  'knowledge', 'brain', 'mcp', 'action', 'error', 'storage', 'diagnostics',
  'release',
]);

if (fixture.contract !== 'bizcity.plugin-adoption-role-fixtures') {
  throw new Error('Unexpected role fixture contract');
}

const roles = fixture.roles || [];
if (roles.length !== expectedRoles.length) {
  throw new Error(`Expected ${expectedRoles.length} role fixtures, found ${roles.length}`);
}

const seen = new Set();
for (const entry of roles) {
  if (!expectedRoles.includes(entry.role)) throw new Error(`Unsupported role: ${entry.role}`);
  if (seen.has(entry.role)) throw new Error(`Duplicate role: ${entry.role}`);
  seen.add(entry.role);
  if (!Array.isArray(entry.required_stages) || entry.required_stages.length === 0) {
    throw new Error(`Role ${entry.role} must declare required stages`);
  }
  for (const stage of entry.required_stages) {
    if (!allowedStages.has(stage)) throw new Error(`Role ${entry.role} has invalid stage: ${stage}`);
  }
}

for (const role of expectedRoles) {
  if (!seen.has(role)) throw new Error(`Missing role fixture: ${role}`);
}

console.log(`PLUGIN ADOPTION ROLE FIXTURES PASS (${roles.length} roles)`);