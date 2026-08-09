#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const args = new Set(process.argv.slice(2));
const tag = process.env.GITHUB_REF_NAME || '';

function readJson(relativePath) {
  const filePath = path.join(root, relativePath);
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function fail(message) {
  console.error(`SDK RELEASE VALIDATION FAIL: ${message}`);
  process.exitCode = 1;
}

function requireFile(relativePath, description) {
  if (!fs.existsSync(path.join(root, relativePath))) {
    fail(`${description} is missing: ${relativePath}`);
    return false;
  }
  return true;
}

function validateSemver(version, label) {
  if (!/^\d+\.\d+\.\d+$/.test(String(version))) {
    fail(`${label} must use stable SemVer, received ${version}`);
    return false;
  }
  return true;
}

function validateChangelog(relativePath, version) {
  if (!requireFile(relativePath, 'Changelog')) return;
  const content = fs.readFileSync(path.join(root, relativePath), 'utf8');
  if (!content.includes(`## [${version}]`)) {
    fail(`${relativePath} has no release heading for ${version}`);
  }
}

function validateUiPackage() {
  const relativeDir = 'packages/twin-ui-sdk';
  const pkgPath = `${relativeDir}/package.json`;
  const pkg = readJson(pkgPath);
  const versionOk = validateSemver(pkg.version, `${pkg.name} version`);
  if (pkg.name !== '@bizcity/twin-ui-sdk') fail(`unexpected UI package name: ${pkg.name}`);
  if (pkg.type !== 'module') fail('UI package must declare type=module');
  if (pkg.main !== 'dist/index.js') fail('UI package main must point to dist/index.js');
  if (pkg.types !== 'dist/index.d.ts') fail('UI package types must point to dist/index.d.ts');
  if (!pkg.exports || !pkg.exports['.']) fail('UI package must declare the root export');
  validateChangelog(`${relativeDir}/CHANGELOG.md`, pkg.version);
  if (args.has('--check-build') || args.has('--check-tag')) {
    requireFile(`${relativeDir}/dist/index.js`, 'UI JavaScript build');
    requireFile(`${relativeDir}/dist/index.d.ts`, 'UI declaration build');
  }
  if (args.has('--check-tag') && tag && tag.startsWith('twin-ui-v')) {
    const tagVersion = tag.slice('twin-ui-v'.length);
    if (tagVersion !== pkg.version) fail(`UI tag ${tagVersion} does not match package version ${pkg.version}`);
  }
  return versionOk;
}

function validatePhpPackage() {
  const relativeDir = 'packages/bizcity-framework-sdk';
  const pkgPath = `${relativeDir}/composer.json`;
  const pkg = readJson(pkgPath);
  const versionOk = validateSemver(pkg.version, `${pkg.name} version`);
  if (pkg.name !== 'bizcity/framework-sdk') fail(`unexpected PHP package name: ${pkg.name}`);
  if (!pkg.require || pkg.require.php !== '>=7.4') fail('PHP SDK must retain the PHP >=7.4 floor');
  if (!pkg.autoload || !pkg.autoload['psr-4']) fail('PHP SDK must declare PSR-4 autoloading');
  validateChangelog(`${relativeDir}/CHANGELOG.md`, pkg.version);
  if (args.has('--check-tag') && tag && tag.startsWith('twin-framework-v')) {
    const tagVersion = tag.slice('twin-framework-v'.length);
    if (tagVersion !== pkg.version) fail(`PHP tag ${tagVersion} does not match package version ${pkg.version}`);
  }
  return versionOk;
}

if (!requireFile('packages/twin-ui-sdk/package.json', 'UI package metadata')) {
  process.exit(1);
}
if (!requireFile('packages/bizcity-framework-sdk/composer.json', 'PHP package metadata')) {
  process.exit(1);
}
validateUiPackage();
validatePhpPackage();

if (process.exitCode) process.exit(process.exitCode);
console.log('SDK RELEASE VALIDATION PASS');
