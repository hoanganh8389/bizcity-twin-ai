#!/usr/bin/env node
/**
 * CI runner for the R-AGENT-PARITY gates in bin/sync-agent-instructions.mjs.
 *
 * Each gate is asserted on a disposable fixture tree (created under the system
 * temp directory and removed afterwards), because a gate that can only be
 * exercised against the real repository silently rots:
 *   0. a clean fixture generates outputs and then passes --check          (exit 0)
 *   1. an edited generated file is reported as drift                      (exit 1)
 *   2. deployment identity in a public file is reported as a leak         (exit 2)
 *   3. a committed doc linking to a missing file is a dead link           (exit 3)
 *   4. a committed doc linking to a git-ignored file is a dead link       (exit 3)
 *   5. a `.local.` file that is not git-ignored breaks the naming rule    (exit 4)
 *   6. a git-ignored instruction file without `.local.` breaks it too     (exit 4)
 *   7. private content stays out of the public map/catalog, and the local
 *      twins are produced instead
 *
 * Usage: node bin/sync-agent-instructions-fixtures.mjs
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const script = path.join(repoRoot, 'bin/sync-agent-instructions.mjs');
const failures = [];
const write = (root, relative, content) => {
  const file = path.join(root, relative);
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, content, 'utf8');
};

function makeFixture() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'agent-parity-'));
  write(root, '.gitignore', [
    '# generated + private agent files',
    '/.github/copilot-instructions.local.md',
    '/.github/instructions/*.local.instructions.md',
    '/docs/AGENT-DOC-CATALOG.local.md',
    '/CLAUDE.local.md',
    '/docs/internal/',
    '/docs/rules/',
    '',
  ].join('\n'));
  write(root, '.github/copilot-instructions.md', [
    '# Fixture rules (public)',
    '',
    // Relative to .github/, exactly like the real rule file.
    'Read [the guide](../docs/guide.md) before changing anything.',
    '',
  ].join('\n'));
  write(root, '.github/instructions/runbook.instructions.md', [
    '---',
    'name: runbook',
    'description: "Fixture scoped runbook."',
    'applyTo: "**/*.php"',
    '---',
    '',
    '# Fixture runbook',
    '',
  ].join('\n'));
  write(root, 'README.md', '# Fixture project\n\nSee [CONTRIBUTING.md](CONTRIBUTING.md).\n');
  write(root, 'CONTRIBUTING.md', '# Contributing\n\nNothing here.\n');
  write(root, 'docs/guide.md', '# Fixture guide\n\nA published guide with enough prose to summarise.\n');
  write(root, 'docs/internal/secret-plan.md', '# Internal plan\n\nUnpublished document.\n');
  write(root, 'docs/rules/FIXTURE-RULE.md', '# Fixture internal rule\n\nAn unpublished rule document.\n');
  write(root, 'bin/tool.mjs', '#!/usr/bin/env node\n// Fixture tool that does nothing.\n');
  return root;
}

function run(root, extra = []) {
  const result = spawnSync(process.execPath, [script, `--root=${root}`, ...extra], { encoding: 'utf8' });
  return { code: result.status, out: `${result.stdout || ''}${result.stderr || ''}` };
}

function check(label, actual, expected, detail = '') {
  if (actual === expected) {
    console.log(`  ok   ${label}`);
    return true;
  }
  console.log(`  FAIL ${label} — expected exit ${expected}, got ${actual}`);
  if (detail) console.log(`       ${detail.trim().split('\n').slice(0, 4).join('\n       ')}`);
  failures.push(label);
  return false;
}

function scenario(label, mutate, expected, extra = []) {
  const root = makeFixture();
  try {
    const generated = run(root);
    if (generated.code !== 0) {
      console.log(`  FAIL ${label} — fixture generation itself failed (exit ${generated.code})`);
      console.log(`       ${generated.out.trim().split('\n').slice(0, 4).join('\n       ')}`);
      failures.push(label);
      return null;
    }
    if (mutate) mutate(root);
    const result = run(root, ['--check', ...extra]);
    check(label, result.code, expected, result.out);
    return root;
  } finally {
    if (!process.env.AGENT_PARITY_KEEP_FIXTURES) fs.rmSync(root, { recursive: true, force: true });
  }
}

console.log('[R-AGENT-PARITY fixtures] gate assertions');

// 0. clean fixture stays in sync after generation.
scenario('clean fixture passes --check', null, 0);

// 1. drift: a generated file edited by hand.
scenario('hand-edited AGENTS.md is drift', (root) => {
  fs.appendFileSync(path.join(root, 'AGENTS.md'), '\nmanual edit\n');
}, 1);

// 1b. drift: a new published doc that is not in the catalog yet.
scenario('new published doc is drift until regenerated', (root) => {
  write(root, 'docs/new-guide.md', '# New fixture guide\n\nAdded after generation.\n');
}, 1);

// 2. privacy: deployment identity in a public file.
scenario('operator path in public rules is a leak', (root) => {
  fs.appendFileSync(path.join(root, '.github/copilot-instructions.md'), '\nDeploy from /home/operator/site.\n');
}, 2);

// 3. dead link: target does not exist.
scenario('link to a missing file is a dead link', (root) => {
  fs.appendFileSync(path.join(root, 'README.md'), '\nSee [missing](docs/nope.md).\n');
}, 3);

// 4. dead link: target exists but is git-ignored.
scenario('link to an unpublished file is a dead link', (root) => {
  fs.appendFileSync(path.join(root, 'README.md'), '\nSee [internal](docs/internal/secret-plan.md).\n');
}, 3);

// 5. naming: `.local.` file that is not git-ignored.
scenario('.local. file must be git-ignored', (root) => {
  write(root, 'docs/AGENT-NOTES.local.md', '# Private notes\n');
}, 4);

// 6. naming: git-ignored instruction file without `.local.`.
scenario('git-ignored instruction file must be named .local.', (root) => {
  write(root, '.github/private-notes.md', '# Private notes\n');
  fs.appendFileSync(path.join(root, '.gitignore'), '/.github/private-notes.md\n');
}, 4);

// 7. separation: private sources feed only the `.local.` outputs.
{
  const root = makeFixture();
  try {
    write(root, '.github/copilot-instructions.local.md', '# Private rules\n\nDeploy host is host.example and path /home/operator/site.\n');
    write(root, '.github/instructions/deploy.local.instructions.md', [
      '---', 'name: deploy', 'description: "Private deploy runbook."', 'applyTo: "**"', '---', '',
      '# Private deploy runbook', '', 'Use /home/operator/site as the root.', '',
    ].join('\n'));
    const generated = run(root);
    check('fixture with private sources generates cleanly', generated.code, 0, generated.out);

    const read = (relative) => (fs.existsSync(path.join(root, relative)) ? fs.readFileSync(path.join(root, relative), 'utf8') : null);
    const publicMap = read('.github/instructions/agent-environment.instructions.md');
    const publicCatalog = read('docs/AGENT-DOC-CATALOG.md');
    const agents = read('AGENTS.md');
    const localMap = read('.github/instructions/agent-environment.local.instructions.md');
    const localCatalog = read('docs/AGENT-DOC-CATALOG.local.md');
    const claudeLocal = read('CLAUDE.local.md');

    const assert = (label, condition) => {
      if (condition) { console.log(`  ok   ${label}`); return; }
      console.log(`  FAIL ${label}`);
      failures.push(label);
    };
    assert('public map exists', Boolean(publicMap));
    assert('public outputs never mention the private rule file', ![publicMap, publicCatalog, agents].some((text) => (text || '').includes('copilot-instructions.local.md')));
    assert('public catalog hides unpublished doc names', !(publicCatalog || '').includes('secret-plan.md'));
    assert('public catalog lists the published doc', (publicCatalog || '').includes('docs/guide.md'));
    assert('AGENTS.md carries the public scoped runbook', (agents || '').includes('runbook.instructions.md'));
    assert('AGENTS.md excludes the private scoped runbook', !(agents || '').includes('deploy.local.instructions.md'));
    // The local map is the curated index (rules/contracts/guides/READMEs);
    // the local catalog is the exhaustive one.
    assert('local map indexes the internal rule', (localMap || '').includes('docs/rules/FIXTURE-RULE.md'));
    assert('local map points at the local catalog', (localMap || '').includes('docs/AGENT-DOC-CATALOG.local.md'));
    assert('public map hides the internal rule', !(publicMap || '').includes('FIXTURE-RULE.md'));
    assert('local catalog indexes every unpublished doc', ['secret-plan.md', 'FIXTURE-RULE.md'].every((name) => (localCatalog || '').includes(name)));
    assert('CLAUDE.local.md imports the private sources', (claudeLocal || '').includes('@.github/copilot-instructions.local.md')
      && (claudeLocal || '').includes('@.github/instructions/deploy.local.instructions.md'));

    const recheck = run(root, ['--check']);
    check('fixture with private sources passes --check', recheck.code, 0, recheck.out);
  } finally {
    if (!process.env.AGENT_PARITY_KEEP_FIXTURES) fs.rmSync(root, { recursive: true, force: true });
  }
}

if (failures.length) {
  console.error(`\n[R-AGENT-PARITY fixtures] FAIL — ${failures.length} assertion(s): ${failures.join('; ')}`);
  process.exit(1);
}
console.log('\n[R-AGENT-PARITY fixtures] PASS — all gates behave as documented.');
