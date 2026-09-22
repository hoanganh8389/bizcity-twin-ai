#!/usr/bin/env node
/**
 * Validate JSONL log contract declarations against static registration evidence.
 *
 * Every manifest `logging.contracts[]` entry must correspond to at least one
 * `BizCity_Log_Contract_Registry::register()` call in active source. Class
 * constants declared in the same file are resolved so `self::CONST` usage is
 * counted as evidence rather than treated as a missing registration.
 *
 * This is a static parity gate. It does not execute WordPress and does not
 * prove runtime write/read behaviour.
 *
 * Usage:
 *   node bin/validate-jsonl-contract-parity.mjs [--strict]
 *   node bin/validate-jsonl-contract-parity.mjs --fixture-root=tests/fixtures/jsonl-contract-parity/invalid
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
const scanRoots = ['core', 'modules', 'plugins', 'examples', 'bin'];
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
const contractIdPattern = /^[a-z][a-z0-9._-]{2,100}$/;

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

// Resolve `const NAME = 'value'` declarations so constant-based registration counts.
function collectConstants(source) {
  const constants = new Map();
  const pattern = /const\s+([A-Z][A-Z0-9_]*)\s*=\s*'([^']+)'/g;
  let match;
  while ((match = pattern.exec(source)) !== null) {
    constants.set(match[1], match[2]);
  }
  return constants;
}

const registrations = new Map();
const registrationFiles = new Set();

const registrationSources = fixtureRoot
  ? [fixtureRoot]
  : scanRoots.map((scanRoot) => path.join(root, scanRoot));

for (const scanRoot of registrationSources) {
  for (const file of walk(scanRoot, '.php')) {
    const relative = relativeOf(file);
    const source = fs.readFileSync(file, 'utf8');
    if (!source.includes('Log_Contract_Registry::register')) continue;
    registrationFiles.add(relative);
    const constants = collectConstants(source);
    const pattern = /Log_Contract_Registry::register\s*\(\s*([^,]+),/g;
    let match;
    while ((match = pattern.exec(source)) !== null) {
      const argument = match[1].trim();
      let contractId = '';
      const literal = argument.match(/^'([^']+)'$/) || argument.match(/^"([^"]+)"$/);
      if (literal) {
        contractId = literal[1];
      } else {
        const constantName = (argument.match(/(?:self|static|[\w\\]+)::([A-Z][A-Z0-9_]*)$/) || [])[1];
        if (constantName && constants.has(constantName)) contractId = constants.get(constantName);
      }
      if (!contractId || !contractIdPattern.test(contractId)) continue;
      if (!registrations.has(contractId)) registrations.set(contractId, []);
      registrations.get(contractId).push(relative);
    }
  }
}

const declared = new Map();
for (const file of walk(fixtureRoot || root, '.json')) {
  const relative = relativeOf(file);
  if (!fixtureRoot) {
    const segments = relative.split('/');
    if (excludedSegments.has(segments[0])) continue;
  }
  if (!/(?:^|\/)manifest\.json$/.test(relative)) continue;
  let parsed;
  try {
    parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    continue;
  }
  const contracts = parsed && parsed.logging && Array.isArray(parsed.logging.contracts)
    ? parsed.logging.contracts
    : [];
  for (const contractId of contracts) {
    if (typeof contractId !== 'string' || !contractIdPattern.test(contractId)) continue;
    if (!declared.has(contractId)) declared.set(contractId, []);
    declared.get(contractId).push(relative);
  }
}

const undeclaredRegistrations = [...registrations.keys()].filter((id) => !declared.has(id)).sort();
const unregisteredDeclarations = [...declared.keys()]
  .filter((id) => !registrations.has(id))
  .sort()
  .map((id) => ({
    contract_id: id,
    declared_in: declared.get(id),
    owner: 'public contracts / module owner',
    contract: 'core.helper.log_contract_registry',
    fix_hint: 'Call BizCity_Log_Contract_Registry::register() with this id in active source, or remove the manifest declaration.',
  }));
const duplicateRegistrations = [...registrations.entries()]
  .filter(([, files]) => new Set(files).size > 1)
  .sort()
  .map(([id, files]) => ({ contract_id: id, files: [...new Set(files)].sort() }));

const report = {
  generated_at: new Date().toISOString(),
  scope: `${scanRoots.join(',')}/**/*.php + manifest.json logging.contracts (excluding archived/library/vendor/generated trees)`,
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  registration_files: [...registrationFiles].sort(),
  registered_contracts: [...registrations.keys()].sort(),
  declared_contracts: [...declared.keys()].sort(),
  unregistered_declarations: unregisteredDeclarations,
  duplicate_registrations: duplicateRegistrations,
  registrations_without_manifest_declaration: undeclaredRegistrations,
  status: unregisteredDeclarations.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (unregisteredDeclarations.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;