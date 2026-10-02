#!/usr/bin/env node
/**
 * R-DIAG-LOCAL boundary validator (WP-13 DL-4).
 *
 *   node bin/validate-dev-only-boundary.mjs          report + exit 1 on any finding
 *
 * Dev-only paths are listed in bin/dev-only-paths.txt (core/diagnostics/, tests/, _notes/, node_modules/).
 * They never reach a server or GitHub, so production code must survive without them. Checks:
 *   1. every manifest entry is git-ignored and nothing under it is tracked (tracked = FAIL in CI, INFO on a
 *      dev machine, because commits are made from the VPS and a dev index may lag);
 *   2. production PHP that require/include-s a dev-only path (a literal, a __DIR__/dirname() expression,
 *      or a variable assigned from one) does so behind is_file()/file_exists()/is_readable();
 *   3. production PHP that uses a class/interface/trait declared ONLY under a dev-only path
 *      (new, ::, extends, implements, instanceof, trait use) guards it with class_exists()/
 *      interface_exists()/trait_exists() naming it in the same file. SKIP when the dev-only
 *      folders are absent (CI, VPS) — there is nothing to compare against there.
 * Informational only (never fails): the same patterns in tooling (bin/, any tests/ folder),
 * Safe_Loader calls on a dev-only path without an is_file() first (they log missing_file on every
 * call), and frontend sources that still call bizcity-diagnostics/v1 (a 404 on servers).
 *
 * Exported analyze() is reused by bin/simulate-production-tree.mjs (L4).
 *
 * [2026-09-28 Claude Opus 5.5] CORE-REDUCTION WP-13 DL-4 — R-DIAG-LOCAL enforcement.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..');
const SKIP_DIRS = new Set(['_archived', '_library', 'node_modules', 'vendor', '.git', 'dist', 'build', '.vite', 'docs']);

export function readManifest(root = ROOT) {
  const file = path.join(root, 'bin/dev-only-paths.txt');
  if (!fs.existsSync(file)) return null;
  return fs.readFileSync(file, 'utf8').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('#'));
}

// [2026-09-30 Claude Opus 5.5] R-LEAN-4 WP-16 B-1 — committed but never uploaded (bin/, SDK sources). Not git-checked.
export function readDeployExclude(root = ROOT) {
  const file = path.join(root, 'bin/deploy-exclude-paths.txt');
  if (!fs.existsSync(file)) return [];
  return fs.readFileSync(file, 'utf8').split(/\r?\n/).map((l) => l.trim()).filter((l) => l && !l.startsWith('#'));
}

// Manifest entries are gitignore-style: `dir/` (the folder and everything under it), `file.php`,
// `*` inside one path segment, and a leading `**/` (any depth).
const reCache = new Map();
function entryRe(e) {
  if (reCache.has(e)) return reCache.get(e);
  const s = e.replace(/\/$/, '');
  let re = '';
  for (let i = 0; i < s.length; i++) {
    if (s.startsWith('**/', i)) { re += '(?:.*/)?'; i += 2; continue; }
    re += s[i] === '*' ? '[^/]*' : s[i].replace(/[.+?^${}()|[\]\\]/g, '\\$&');
  }
  const r = new RegExp('^' + re + (e.endsWith('/') ? '(?:/.*)?$' : '$'));
  reCache.set(e, r);
  return r;
}

export function isDevOnly(rel, manifest) {
  const p = rel.split(path.sep).join('/');
  return manifest.some((e) => entryRe(e).test(p));
}

const isTooling = (rel) => rel.startsWith('bin/') || rel.split('/').includes('tests');

function walk(dir, root, out) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP_DIRS.has(e.name)) continue;
    const full = path.join(dir, e.name);
    if (e.isDirectory()) walk(full, root, out);
    else if (e.name.endsWith('.php')) out.push(path.relative(root, full).split(path.sep).join('/'));
  }
  return out;
}

/** Strip comments; optionally blank string contents. Newlines are preserved so line numbers stay valid. */
export function stripPhp(src, blankStrings) {
  let out = '';
  let i = 0;
  const n = src.length;
  while (i < n) {
    const c = src[i];
    const d = src[i + 1];
    if (c === '/' && d === '*') {
      const end = src.indexOf('*/', i + 2);
      const chunk = src.slice(i, end < 0 ? n : end + 2);
      out += chunk.replace(/[^\n]/g, ' ');
      i += chunk.length;
    } else if ((c === '/' && d === '/') || (c === '#' && d !== '[')) {
      let j = i;
      while (j < n && src[j] !== '\n' && !(src[j] === '?' && src[j + 1] === '>')) j++;
      out += ' '.repeat(j - i);
      i = j;
    } else if (c === "'" || c === '"') {
      let j = i + 1;
      while (j < n && src[j] !== c) { if (src[j] === '\\') j++; j++; }
      const chunk = src.slice(i, j + 1);
      out += blankStrings ? c + chunk.slice(1, -1).replace(/[^\n]/g, ' ') + c : chunk;
      i = j + 1;
    } else {
      out += c;
      i++;
    }
  }
  return out;
}

/** Resolve `__DIR__ . '/x'`, `dirname( __DIR__[, N] ) . '/x'`, `dirname( __FILE__ ) . '/x'` to a root-relative path. */
function resolveExpr(expr, fileRel, root) {
  const dir = path.dirname(path.join(root, fileRel));
  const m = expr.match(/(dirname\s*\(\s*(?:dirname\s*\(\s*)?(?:__DIR__|__FILE__)\s*\)?(?:\s*,\s*(\d+))?\s*\)|__DIR__)\s*\.\s*(['"])([^'"]+)\3/);
  if (!m) return null;
  let base = dir;
  const head = m[1];
  if (head !== '__DIR__') {
    let levels = (head.match(/dirname/g) || []).length;
    if (/__FILE__/.test(head)) levels -= 1;
    if (m[2]) levels += Number(m[2]) - 1;
    for (let k = 0; k < levels; k++) base = path.dirname(base);
  }
  return path.relative(root, path.normalize(path.join(base, m[4]))).split(path.sep).join('/');
}

// Tail matching: `SOME_CONST . 'diagnostics/class-x.php'` cannot be resolved (the constant comes from
// plugin_dir_path()), but the literal is the tail of a dev-only file. Set by analyze() before the scan.
let DEV_TAILS = { files: [], prod: [], prodBasenames: new Set() };

function exprIsDev(expr, fileRel, root, manifest, devVars) {
  const resolved = resolveExpr(expr, fileRel, root);
  if (resolved && !resolved.startsWith('..') && isDevOnly(resolved, manifest)) return resolved;
  for (const m of expr.matchAll(/(['"])([^'"$]+\.php)\1/g)) {
    const lit = m[2].replace(/^\/+/, '');
    const base = lit.split('/').pop();
    if (!lit.includes('/') && DEV_TAILS.prodBasenames.has(base)) continue;
    const tail = (f) => f === lit || f.endsWith('/' + lit);
    if (DEV_TAILS.prod.some(tail)) continue; // the literal names a production file too (e.g. a fixture mirror)
    const hit = DEV_TAILS.files.find(tail);
    if (hit) return hit;
  }
  for (const e of manifest) {
    if (e.includes('*') || e.split('/').filter(Boolean).length < 2) continue;
    if (expr.includes(e)) return e.endsWith('/') ? e + '…' : e;
  }
  for (const v of devVars.keys()) {
    const re = v.startsWith('$') ? new RegExp('\\' + v + '\\b') : new RegExp('\\b' + v + '\\b');
    if (re.test(expr)) return devVars.get(v);
  }
  return null;
}

const GUARD_FILE = /\b(is_file|file_exists|is_readable)\s*\(/;

export function analyze({ root = ROOT, manifest = readManifest(root), git = true } = {}) {
  const findings = [];
  const info = [];
  const skipped = [];
  if (!manifest) return { findings: [{ check: 'manifest_missing', file: 'bin/dev-only-paths.txt' }], info, skipped, stats: {} };

  // 1. git: ignored and untracked
  if (git) {
    // Commits are made from the VPS (R-DL-6): a path still tracked in THIS checkout is a hard failure in CI
    // (the GitHub checkout is the published state) and informational on a dev machine (its index may lag).
    const trackedBucket = process.env.CI ? findings : info;
    for (const e of manifest) {
      const base = e.startsWith('**/') ? 'frontend/' + e.slice(3) : e;
      const probe = (base.endsWith('/') ? base + 'x' : base).replace(/\*/g, 'x');
      try {
        // --no-index: test the .gitignore pattern itself; a tracked file is otherwise never reported as ignored.
        execFileSync('git', ['check-ignore', '-q', '--no-index', probe], { cwd: root, stdio: 'ignore' });
      } catch (err) {
        if (err.status === 1) findings.push({ check: 'dev_path_not_ignored', path: e, fix: 'add it to .gitignore' });
        else { skipped.push('git check-ignore unavailable'); break; }
      }
      try {
        const spec = e.includes('*') ? `:(glob)${e}${e.endsWith('/') ? '**' : ''}` : e;
        const tracked = execFileSync('git', ['ls-files', '--', spec], { cwd: root, encoding: 'utf8' }).trim();
        if (tracked) trackedBucket.push({ check: 'dev_path_tracked', path: e, count: tracked.split('\n').length, sample: tracked.split('\n')[0], fix: `git rm -r --cached ${e} (once, on the machine that commits)` });
      } catch { /* git unavailable: reported above */ }
    }
  }

  // 2 + 3. PHP scan
  const all = walk(root, root, []);
  const dev = all.filter((f) => isDevOnly(f, manifest));
  const prod = all.filter((f) => !isDevOnly(f, manifest));
  // Tails come from dev-only product files only (test fixtures mirror production paths on purpose).
  DEV_TAILS = { files: dev.filter((f) => !isTooling(f)), prod, prodBasenames: new Set(prod.map((f) => f.split('/').pop())) };
  const declRe = /^\s*(?:<\?php\s+)?(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]*)/gm;
  const declared = (files) => {
    const s = new Set();
    for (const f of files) {
      const code = stripPhp(fs.readFileSync(path.join(root, f), 'utf8'), true);
      for (const m of code.matchAll(declRe)) s.add(m[1]);
    }
    return s;
  };
  const prodDecl = declared(prod);
  // tests/ declares WordPress stubs (WP_User, wpdb …) and fixtures, never product classes — only the
  // non-tooling dev-only files (core/diagnostics) define classes production could wrongly depend on.
  const devOnlyClasses = new Set([...declared(dev.filter((f) => !isTooling(f)))].filter((c) => !prodDecl.has(c)));
  if (!dev.length) skipped.push('class check: no dev-only PHP present (normal on CI/VPS)');

  const refRes = [
    /\bnew\s+\\?([A-Za-z_]\w*)/g,
    /\b([A-Za-z_]\w*)\s*::/g,
    /\bextends\s+\\?([A-Za-z_]\w*)/g,
    /\binstanceof\s+\\?([A-Za-z_]\w*)/g,
  ];
  let requireRefs = 0;
  let classRefs = 0;
  // Constants defined from a dev-only path in any production file (e.g. BIZCITY_DIAGNOSTICS_DIR) — a
  // constant defined in one file and used in another is still a dev-only path.
  const devConsts = new Map();
  const fileVars = (code, f) => {
    const vars = new Map();
    for (const m of code.matchAll(/(\$[A-Za-z_]\w*)\s*=\s*([^;]+);/g)) {
      const hit = exprIsDev(m[2], f, root, manifest, new Map());
      if (hit) vars.set(m[1], hit);
    }
    return vars;
  };
  for (const f of prod) {
    const code = stripPhp(fs.readFileSync(path.join(root, f), 'utf8'), false);
    const vars = fileVars(code, f);
    for (const m of code.matchAll(/\bdefine\s*\(\s*['"]([A-Z_][A-Z0-9_]*)['"]\s*,\s*([^;]+)\)\s*;/g)) {
      const hit = exprIsDev(m[2], f, root, manifest, vars);
      if (hit) devConsts.set(m[1], hit);
    }
  }
  for (const f of prod) {
    const raw = fs.readFileSync(path.join(root, f), 'utf8');
    const code = stripPhp(raw, false);
    const bare = stripPhp(raw, true);
    const lines = code.split('\n');
    const bucket = isTooling(f) ? info : findings;

    // variables and constants assigned from a dev-only path (the WP-13 B-2 fatal went through a constant)
    const devVars = fileVars(code, f);
    for (const [c, hit] of devConsts) devVars.set(c, hit);
    lines.forEach((line, idx) => {
      const req = line.match(/\b(require|include)(_once)?\b\s*\(?\s*([^;]+)/);
      const safe = line.match(/BizCity_Safe_Loader::require_file\s*\(\s*([^,)]+)/);
      if (!req && !safe) return;
      const expr = req ? req[3] : safe[1];
      const hit = exprIsDev(expr, f, root, manifest, devVars);
      if (!hit) return;
      requireRefs++;
      const windowText = lines.slice(Math.max(0, idx - 8), idx + 1).join('\n');
      const guarded = GUARD_FILE.test(windowText);
      if (safe && !req) {
        // R-DL-3: fail-soft, but on a server it logs missing_file on every request — a finding in production code.
        if (!guarded) bucket.push({ check: 'safe_loader_without_is_file', file: f, line: idx + 1, target: hit, fix: 'check is_file() before BizCity_Safe_Loader::require_file()' });
        return;
      }
      if (!guarded) bucket.push({ check: 'unguarded_require_of_dev_path', file: f, line: idx + 1, target: hit, fix: 'wrap in is_file() and load through BizCity_Safe_Loader::require_file()' });
    });

    // class references
    if (!devOnlyClasses.size) continue;
    const bareLines = bare.split('\n');
    const guardedNames = new Set();
    for (const m of code.matchAll(/\b(?:class|interface|trait)_exists\s*\(\s*['"]\\?([A-Za-z_]\w*)['"]/g)) guardedNames.add(m[1]);
    for (const m of code.matchAll(/\b(?:class|interface|trait)_exists\s*\(\s*\\?([A-Za-z_]\w*)::class/g)) guardedNames.add(m[1]);
    for (const m of code.matchAll(/\b(?:method_exists|is_callable)\s*\(\s*(?:array\s*\(|\[)?\s*['"]\\?([A-Za-z_]\w*)['"]/g)) guardedNames.add(m[1]);
    const seen = new Set();
    bareLines.forEach((line, idx) => {
      const names = [];
      for (const re of refRes) for (const m of line.matchAll(re)) names.push(m[1]);
      const impl = line.match(/\bimplements\s+([\\\w\s,]+)/);
      if (impl) names.push(...impl[1].split(',').map((s) => s.trim().replace(/^\\/, '')).filter(Boolean));
      const use = line.match(/^\s*use\s+\\?([A-Za-z_]\w*)\s*;/);
      if (use) names.push(use[1]);
      for (const name of names) {
        if (!devOnlyClasses.has(name) || guardedNames.has(name)) continue;
        const key = name + ':' + idx;
        if (seen.has(key)) continue;
        seen.add(key);
        classRefs++;
        bucket.push({ check: 'unguarded_dev_only_class', file: f, line: idx + 1, class: name, fix: `guard with class_exists( '${name}' ) (or interface_exists/trait_exists) before use` });
      }
    });
  }

  // frontend callers of the Diagnostics REST namespace (advisory)
  const feRoots = ['modules', 'core', 'plugins'];
  const feHits = [];
  const feWalk = (dir) => {
    if (!fs.existsSync(dir)) return;
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      if (SKIP_DIRS.has(e.name) && e.name !== 'docs') continue;
      if (e.name === 'docs') continue;
      const full = path.join(dir, e.name);
      if (e.isDirectory()) feWalk(full);
      else if (/\.(tsx?|jsx?)$/.test(e.name) && /[\\/](src)[\\/]/.test(full)) {
        const rel = path.relative(root, full).split(path.sep).join('/');
        if (isDevOnly(rel, manifest)) continue;
        const live = fs.readFileSync(full, 'utf8').split(/\r?\n/).filter((l) => !/^\s*(\*|\/\/|\/\*)/.test(l));
        if (live.some((l) => l.includes('bizcity-diagnostics/v1'))) feHits.push(rel);
      }
    }
  };
  for (const r of feRoots) feWalk(path.join(root, r));
  for (const f of feHits) info.push({ check: 'frontend_calls_diagnostics_rest', file: f, note: 'answers 404 on servers (R-DIAG-LOCAL); fail-soft only if the client swallows it' });

  return {
    findings,
    info,
    skipped,
    stats: { manifest, php_files: all.length, production_php: prod.length, dev_only_php: dev.length, dev_only_classes: devOnlyClasses.size, dev_path_requires: requireRefs, dev_class_refs: classRefs },
  };
}

const isMain = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1'));
if (isMain) {
  const r = analyze();
  const out = { rule: 'R-DIAG-LOCAL', manifest: 'bin/dev-only-paths.txt', stats: r.stats, skipped: r.skipped, findings: r.findings, info: r.info, status: r.findings.length ? 'FAIL' : 'PASS' };
  console.log(JSON.stringify(out, null, 2));
  if (r.findings.length) process.exitCode = 1;
}
