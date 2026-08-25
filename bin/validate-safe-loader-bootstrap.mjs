#!/usr/bin/env node
/**
 * R-SAFE-LOADER bootstrap enforcement.
 *
 * Existing raw requires are reported as legacy debt. CI blocks only raw require
 * lines added by the current diff, so the rule can be adopted without hiding
 * the migration backlog or breaking unrelated historical bootstraps at once.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const scopeRoots = ['plugins', 'core', 'modules'];
const excludedSegments = new Set(['_archived', '_library', 'node_modules', 'vendor', 'dist', 'build', '.vite']);
const rawRequire = /\brequire(?:_once)?\b/;
const safeLoad = /BizCity_Safe_Loader::require_file\s*\(/;

function relative(filePath) {
  return path.relative(root, filePath).replaceAll(path.sep, '/');
}

function excluded(filePath) {
  return relative(filePath).split('/').some((segment) => excludedSegments.has(segment));
}

function walk(directory) {
  if (!fs.existsSync(directory)) return [];
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (excluded(target)) continue;
    if (entry.isDirectory()) files.push(...walk(target));
    else if (entry.isFile() && entry.name === 'bootstrap.php') files.push(target);
  }
  return files;
}

function isLoaderBootstrap(file, line) {
  const rel = relative(file);
  return (
    (rel === 'core/helper/bootstrap.php' && line.includes('$_helper_safe_loader'))
    || (rel === 'plugins/bizcity-profile/bootstrap.php' && line.includes('$_profile_safe_loader'))
    || (rel === 'plugins/bizcity-profile/bizcity-personal.php' && line.includes('$_profile_safe_loader'))
  );
}

function scanFile(file) {
  const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
  const violations = [];
  lines.forEach((line, index) => {
    if (!rawRequire.test(line) || safeLoad.test(line) || isLoaderBootstrap(file, line)) return;
    if (/^\s*(?:\/\/|\/\*|\*|\*\/|#)/.test(line)) return;
    violations.push({ file: relative(file), line: index + 1, source: line.trim() });
  });
  return violations;
}

function gitDiffAddedLines(base, head) {
  try {
    const diff = execFileSync('git', ['diff', '--no-ext-diff', '--unified=0', base, head, '--', 'plugins', 'core', 'modules'], { encoding: 'utf8' });
    const added = new Map();
    let current = '';
    for (const line of diff.split(/\r?\n/)) {
      if (line.startsWith('+++ b/')) {
        current = line.slice(6);
        continue;
      }
      if (current && line.startsWith('+') && !line.startsWith('+++')) {
        if (!added.has(current)) added.set(current, []);
        added.get(current).push(line.slice(1));
      }
    }
    return added;
  } catch (error) {
    return { error: `git diff unavailable: ${error.message}` };
  }
}

const files = scopeRoots.flatMap((scope) => walk(path.join(root, scope)));
const legacyDebt = files.flatMap(scanFile);
const args = new Map();
for (const arg of process.argv.slice(2)) {
  const [key, value] = arg.split('=', 2);
  if (key && value) args.set(key.replace(/^--/, ''), value);
}

let newViolations = [];
if (args.has('base') && args.has('head')) {
  const added = gitDiffAddedLines(args.get('base'), args.get('head'));
  if (added.error) {
    process.stderr.write(`${added.error}\n`);
    process.exitCode = 1;
  } else {
    for (const [file, lines] of added.entries()) {
      if (!file.endsWith('/bootstrap.php') || excluded(file)) continue;
      lines.forEach((line, index) => {
        if (rawRequire.test(line) && !safeLoad.test(line) && !isLoaderBootstrap(path.join(root, file), line)) {
          newViolations.push({ file, added_line: index + 1, source: line.trim() });
        }
      });
    }
  }
}

if (args.has('strict')) {
  newViolations = legacyDebt;
}

const report = {
  rule: 'R-SAFE-LOADER',
  scope: 'plugins/**/bootstrap.php, core/**/bootstrap.php, modules/**/bootstrap.php',
  bootstrap_files: files.length,
  legacy_debt: legacyDebt.length,
  new_violations: newViolations,
  mode: args.has('strict') ? 'strict' : (args.has('base') && args.has('head') ? 'changed' : 'inventory'),
  status: newViolations.length === 0 ? 'PASS' : 'FAIL',
};
process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newViolations.length > 0) process.exitCode = 1;
