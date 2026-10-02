#!/usr/bin/env node
/**
 * L4 — production-tree simulation (WP-13 DL-4, R-DIAG-LOCAL). Dev machine only.
 *
 *   node bin/simulate-production-tree.mjs [--no-harness] [--root=<plugin copy>]   (--root: a mirror; git checks off)
 *
 * The tree a server receives is the plugin minus bin/dev-only-paths.txt. This script:
 *   1. runs the dev-only boundary analysis (bin/validate-dev-only-boundary.mjs) — production PHP must not
 *      require a dev-only path or use a dev-only class without a guard;
 *   2. checks every unguarded require/include with a resolvable target (__DIR__ / dirname() + literal) in
 *      production PHP: the target must exist in the production tree (catches files a deploy would miss,
 *      not only dev-only ones);
 *   3. runs the mirror harnesses that execute production code without core/diagnostics
 *      (core/knowledge/tests/harness/wp13-dl{1,2,3}-harness.php, wp14-cg-scope-harness.php);
 *   4. writes the report to _notes/l4/ (git-ignored, never uploaded) and prints a summary.
 * Exit 1 when step 1 or 3 fails, or step 2 finds a missing target.
 *
 * [2026-09-28 Claude Opus 5.5] CORE-REDUCTION WP-13 DL-4 — L4 simulation.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { analyze, readManifest, readDeployExclude, isDevOnly, stripPhp } from './validate-dev-only-boundary.mjs';

const ROOT_ARG = process.argv.find((a) => a.startsWith('--root='));
const ROOT = ROOT_ARG ? path.resolve(ROOT_ARG.slice(7)) : path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..');
const SKIP_DIRS = new Set(['_archived', '_library', 'node_modules', 'vendor', '.git', 'dist', 'build', '.vite', 'docs']);
const manifest = readManifest(ROOT);
// [2026-09-30 Claude Opus 5.5] R-LEAN-4 WP-16 B-1 — the server tree also drops committed-but-not-uploaded paths.
const shipExclude = [...(manifest || []), ...readDeployExclude(ROOT)];
const report = { rule: 'R-DIAG-LOCAL', layer: 'L4', at: new Date().toISOString(), manifest };

// 1. boundary
const b = analyze({ root: ROOT, manifest, git: !ROOT_ARG });
report.boundary = { status: b.findings.length ? 'FAIL' : 'PASS', stats: b.stats, findings: b.findings, info_count: b.info.length };

// 2. resolvable unguarded requires must hit a file that ships
const walk = (dir, out = []) => {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP_DIRS.has(e.name)) continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, out);
    else if (e.name.endsWith('.php')) out.push(path.relative(ROOT, full).split(path.sep).join('/'));
  }
  return out;
};
const prod = walk(ROOT).filter((f) => !isDevOnly(f, shipExclude));
const tooling = (f) => f.startsWith('bin/') || f.split('/').includes('tests');
const missing = [];
let resolved = 0;
const reqRe = /\b(require|include)(_once)?\b\s*\(?\s*((?:dirname\s*\(\s*(?:dirname\s*\(\s*)?(?:__DIR__|__FILE__)\s*\)?(?:\s*,\s*\d+)?\s*\)|__DIR__)\s*\.\s*(['"])([^'"$]+)\4)\s*\)?\s*;/;
for (const f of prod) {
  if (tooling(f)) continue;
  const lines = stripPhp(fs.readFileSync(path.join(ROOT, f), 'utf8'), false).split('\n');
  lines.forEach((line, idx) => {
    const m = line.match(reqRe);
    if (!m) return;
    const head = m[3].slice(0, m[3].lastIndexOf('.')).trim();
    let base = path.dirname(path.join(ROOT, f));
    if (head !== '__DIR__') {
      let levels = (head.match(/dirname/g) || []).length;
      if (/__FILE__/.test(head)) levels -= 1;
      const n = head.match(/,\s*(\d+)/);
      if (n) levels += Number(n[1]) - 1;
      for (let k = 0; k < levels; k++) base = path.dirname(base);
    }
    const target = path.relative(ROOT, path.normalize(path.join(base, m[5]))).split(path.sep).join('/');
    resolved++;
    const window = lines.slice(Math.max(0, idx - 8), idx + 1).join('\n');
    if (/\b(is_file|file_exists|is_readable|class_exists)\s*\(/.test(window)) return;
    const ships = fs.existsSync(path.join(ROOT, target)) && !isDevOnly(target, shipExclude);
    if (!ships) missing.push({ file: f, line: idx + 1, target, dev_only: isDevOnly(target, shipExclude) });
  });
}
report.require_targets = { status: missing.length ? 'FAIL' : 'PASS', resolvable_requires_checked: resolved, missing };

// 3. mirror harnesses
const harnesses = ['wp13-dl1-harness.php', 'wp13-dl2-harness.php', 'wp13-dl3-harness.php', 'wp14-cg-scope-harness.php'];
report.harnesses = [];
if (!process.argv.includes('--no-harness')) {
  for (const h of harnesses) {
    const file = path.join(ROOT, 'core/knowledge/tests/harness', h);
    if (!fs.existsSync(file)) { report.harnesses.push({ harness: h, status: 'SKIP', note: 'not present' }); continue; }
    let out = '';
    let code = 0;
    try { out = execFileSync('php', [file], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }); } catch (e) { out = String(e.stdout || ''); code = e.status || 1; }
    const last = out.trim().split('\n').pop();
    report.harnesses.push({ harness: h, status: code === 0 ? 'PASS' : 'FAIL', summary: last });
  }
}

const failed = report.boundary.status === 'FAIL' || report.require_targets.status === 'FAIL' || report.harnesses.some((h) => h.status === 'FAIL');
report.status = failed ? 'FAIL' : 'PASS';

const outDir = path.join(ROOT, '_notes/l4');
fs.mkdirSync(outDir, { recursive: true });
const outFile = path.join(outDir, `l4-${report.at.slice(0, 19).replace(/[:T]/g, '-')}.json`);
fs.writeFileSync(outFile, JSON.stringify(report, null, 2));

console.log(JSON.stringify({
  status: report.status,
  boundary: `${report.boundary.status} (${b.stats.production_php} production PHP, ${b.stats.dev_only_classes} dev-only classes, ${b.findings.length} findings)`,
  require_targets: `${report.require_targets.status} (${resolved} resolvable requires checked, ${missing.length} missing)`,
  missing: missing.slice(0, 20),
  harnesses: report.harnesses.map((h) => `${h.harness}: ${h.status} — ${h.summary || h.note}`),
  report: path.relative(ROOT, outFile).split(path.sep).join('/'),
}, null, 2));
if (failed) process.exitCode = 1;
