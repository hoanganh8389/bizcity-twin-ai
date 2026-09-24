#!/usr/bin/env node
/**
 * CORE-REDUCTION census — read-only inventory for core/knowledge, core/intent, core/tools.
 *
 * Owner document: core/knowledge/docs/CORE-REDUCTION-WP-00-BASELINE.md (decision D-2).
 * It measures; it never edits, moves, deletes or executes anything. It is a
 * planning aid, NOT a validator and NOT runtime evidence: every number is
 * static, regex-based and indicative. A "0 references" result is strong
 * evidence, not proof (WP-CLI registration, hook-registered singletons and
 * class names composed at runtime are invisible to it).
 *
 * Scope rules (same segment set as the sibling gates in this directory):
 *   `_archived`, `_library` (third-party reference library — out of scope by
 *   decision), `node_modules`, `vendor`, `dist`, `build`, `.vite`, `.git`
 *   are never walked.
 *
 *   Files named `*_deleted.php` are skipped as well. R-ORPHAN-FILE retires a file
 *   by renaming it to that suffix and wrapping its body in `if ( false )`, so it
 *   declares nothing and is never loaded; counting it would report weight the
 *   runtime does not carry. They are listed separately under "retired" instead.
 *
 * Reports (combine freely; default is --inventory):
 *   --inventory   files / KB / LOC / classes / hooks / routes / ajax / cron per target and area
 *   --html        PHP-rendered HTML weight per file (inline HTML outside <?php ?>, HTML inside
 *                 string/heredoc literals, embedded <script>/<style>), plus the entry points
 *                 (menu page, shortcode, ajax, REST) that reach each file
 *   --ajax        wp_ajax_* actions declared in the targets and the files that mention each
 *                 action name (JS/TS/PHP), to show which legacy AJAX actions still have a caller
 *   --refs        class-reference scan across the whole plugin: external callers per area,
 *                 classes with no reference outside their own file, duplicate files/classes,
 *                 PHP files that no other file names in a string literal
 *
 * Options:
 *   --targets=knowledge,intent,tools   folders under core/ (default: all three)
 *   --top=25                           rows per ranking (default 25)
 *   --json=<path>                      also write the full result as JSON
 *
 * Usage (from the plugin root):
 *   node bin/core-reduction-census.mjs
 *   node bin/core-reduction-census.mjs --html --targets=knowledge
 *   node bin/core-reduction-census.mjs --inventory --html --ajax --refs --json=census.json
 *
 * Exit code is always 0 unless the plugin root cannot be found.
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { maskComments } from './lib/php-source.mjs';

const root = process.cwd();
const args = process.argv.slice(2);
const flag = (name) => args.includes(`--${name}`);
const opt = (name, fallback) => {
  const hit = args.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.slice(name.length + 3) : fallback;
};

const targets = opt('targets', 'knowledge,intent,tools').split(',').map((s) => s.trim()).filter(Boolean);
const top = Number.parseInt(opt('top', '25'), 10) || 25;
const jsonOut = opt('json', '');
const wantHtml = flag('html');
const wantAjax = flag('ajax');
const wantRefs = flag('refs');
const wantInventory = flag('inventory') || (!wantHtml && !wantAjax && !wantRefs);

const excludedSegments = new Set([
  '_archived', '_library', 'node_modules', 'vendor', 'dist', 'build', '.vite', '.git',
]);
const scanExt = new Set(['.php', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.css', '.html']);

if (!fs.existsSync(path.join(root, 'core'))) {
  console.error('core-reduction-census: run from the plugin root (no core/ directory here).');
  process.exit(2);
}

/* ── walk ─────────────────────────────────────────────────────────────── */
const files = [];
const retired = [];
(function walk(dir) {
  let entries;
  try { entries = fs.readdirSync(dir, { withFileTypes: true }); } catch { return; }
  for (const e of entries) {
    if (excludedSegments.has(e.name)) continue;
    const abs = path.join(dir, e.name);
    if (e.isDirectory()) { walk(abs); continue; }
    const ext = path.extname(e.name).toLowerCase();
    if (!scanExt.has(ext)) continue;
    let size = 0;
    try { size = fs.statSync(abs).size; } catch { continue; }
    const rel = path.relative(root, abs).split(path.sep).join('/');
    if (/_deleted\.php$/i.test(e.name)) { retired.push({ rel, size }); continue; }
    files.push({ abs, rel, ext, size });
  }
}(root));

const textCache = new Map();
const read = (f) => {
  if (!textCache.has(f.abs)) {
    try { textCache.set(f.abs, fs.readFileSync(f.abs, 'utf8')); } catch { textCache.set(f.abs, ''); }
  }
  return textCache.get(f.abs);
};

const targetOf = (rel) => targets.find((t) => rel.startsWith(`core/${t}/`));
const inTargets = files.filter((f) => targetOf(f.rel));
const phpTargets = inTargets.filter((f) => f.ext === '.php');
const kb = (n) => Math.round(n / 1024);

/** `core/<target>/<area>` — area is the first sub-folder, two levels for includes/ and kg-hub/. */
function areaOf(rel) {
  const p = rel.split('/');
  const t = p[1];
  if (p.length <= 3) return `${t}/(root)`;
  const two = ['includes', 'kg-hub'].includes(p[2]) && p.length > 4;
  return `${t}/${p[2]}${two ? `/${p[3]}` : ''}`;
}

/** Any other-module path collapses to `<top>/<second>` for caller tables. */
function callerArea(rel) {
  if (rel.startsWith('core/diagnostics/')) return 'diagnostics';
  if (/(^|\/)(tests?|bin)\//.test(rel)) return 'tests/bin';
  const m = /^(core|modules|plugins|extensions|includes|mu-plugin)\/([^/]+)/.exec(rel);
  return m ? `${m[1]}/${m[2]}` : 'root';
}

const retiredInTargets = retired.filter((f) => targetOf(f.rel));
const result = { root, targets, walked: files.length, retired: retiredInTargets };
if (retiredInTargets.length) {
  const kbSum = retiredInTargets.reduce((s, f) => s + f.size, 0);
  console.log(`> ${retiredInTargets.length} retired file(s) under the targets are excluded (R-ORPHAN-FILE, ${kb(kbSum)} KB still on disk, zero loaded):`);
  for (const f of retiredInTargets) console.log(`>   ${f.rel.replace(/^core\//, '')} (${kb(f.size)} KB)`);
  console.log('');
}

/* ── --inventory ──────────────────────────────────────────────────────── */
const phpFacts = new Map();
for (const f of phpTargets) {
  const src = maskComments(read(f));
  const facts = {
    kb: f.size / 1024,
    loc: src.split(/\r?\n/).filter((l) => l.trim()).length,
    classes: [...src.matchAll(/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)/gm)].map((m) => m[1]),
    hooks: (src.match(/\b(?:add_action|add_filter)\s*\(/g) || []).length,
    routes: (src.match(/\bregister_rest_route\s*\(/g) || []).length,
    ajax: (src.match(/['"]wp_ajax_(?:nopriv_)?\w+/g) || []).length,
    cron: (src.match(/\bwp_schedule_(?:single_)?event\s*\(/g) || []).length,
    cli: /WP_CLI::add_command/.test(src),
  };
  phpFacts.set(f.rel, facts);
}

if (wantInventory) {
  const byTarget = {};
  const byArea = {};
  for (const f of phpTargets) {
    const facts = phpFacts.get(f.rel);
    for (const [bucket, key] of [[byTarget, targetOf(f.rel)], [byArea, areaOf(f.rel)]]) {
      const b = (bucket[key] ||= { files: 0, kb: 0, loc: 0, classes: 0, hooks: 0, routes: 0, ajax: 0, cron: 0 });
      b.files += 1; b.kb += facts.kb; b.loc += facts.loc; b.classes += facts.classes.length;
      b.hooks += facts.hooks; b.routes += facts.routes; b.ajax += facts.ajax; b.cron += facts.cron;
    }
  }
  const assets = inTargets.filter((f) => ['.js', '.css'].includes(f.ext))
    .map((f) => ({ rel: f.rel, kb: kb(f.size) })).sort((a, b) => b.kb - a.kb);
  const biggest = phpTargets.map((f) => ({ rel: f.rel, kb: kb(f.size) })).sort((a, b) => b.kb - a.kb).slice(0, top);
  result.inventory = { byTarget, byArea, biggest, assets: assets.slice(0, top) };

  console.log('## Inventory (PHP, comments stripped; JS/CSS listed separately)\n');
  console.log('| target | files | KB | LOC | classes | hooks | routes | ajax | cron |\n|---|---:|---:|---:|---:|---:|---:|---:|---:|');
  for (const [k, v] of Object.entries(byTarget)) {
    console.log(`| ${k} | ${v.files} | ${kb(v.kb * 1024)} | ${v.loc} | ${v.classes} | ${v.hooks} | ${v.routes} | ${v.ajax} | ${v.cron} |`);
  }
  console.log('\n| area | files | KB | routes | ajax | cron |\n|---|---:|---:|---:|---:|---:|');
  for (const [k, v] of Object.entries(byArea).sort((a, b) => b[1].kb - a[1].kb)) {
    console.log(`| ${k} | ${v.files} | ${kb(v.kb * 1024)} | ${v.routes} | ${v.ajax} | ${v.cron} |`);
  }
  console.log(`\nLargest PHP files: ${biggest.map((b) => `${b.rel.replace(/^core\//, '')}:${b.kb}KB`).join(' | ')}`);
  console.log(`Largest JS/CSS: ${assets.slice(0, 10).map((b) => `${b.rel.replace(/^core\//, '')}:${b.kb}KB`).join(' | ')}\n`);
}

/* ── --html : PHP-rendered HTML weight ────────────────────────────────── */
const TAG = /<\/?[a-zA-Z][\w:-]*(?:\s[^<>]*)?\/?>/;

/**
 * One pass over a PHP file. Splits it into inline HTML (outside <?php ?>) and PHP,
 * and inside PHP collects string/heredoc literals so HTML built with echo / .= /
 * heredoc is counted too. `<?xml` is not a PHP open tag (only `<?php` and `<?=`
 * are), the same rule as bin/lib/php-source.mjs.
 */
function scanHtml(src) {
  const n = src.length;
  const regions = [];
  const literals = [];
  let i = 0;
  let inPhp = false;
  while (i < n) {
    if (!inPhp) {
      const a = src.indexOf('<?php', i);
      const b = src.indexOf('<?=', i);
      const open = a === -1 ? b : (b === -1 ? a : Math.min(a, b));
      regions.push(src.slice(i, open === -1 ? n : open));
      if (open === -1) break;
      i = open + (src.startsWith('<?php', open) ? 5 : 3);
      inPhp = true;
      continue;
    }
    const ch = src[i];
    const next = src[i + 1];
    if (ch === '?' && next === '>') { i += 2; if (src[i] === '\n') i += 1; inPhp = false; continue; }
    if ((ch === '/' && next === '/') || ch === '#') {
      const e = src.indexOf('\n', i); i = e === -1 ? n : e; continue;
    }
    if (ch === '/' && next === '*') {
      const e = src.indexOf('*/', i + 2); i = e === -1 ? n : e + 2; continue;
    }
    if (src.startsWith('<<<', i)) {
      const m = /^<<<[ \t]*(['"]?)([A-Za-z_]\w*)\1\r?\n/.exec(src.slice(i, i + 80));
      if (m) {
        const start = i + m[0].length;
        const term = new RegExp(`^[ \\t]*${m[2]}\\b`, 'm');
        const rest = src.slice(start);
        const t = term.exec(rest);
        const end = t ? start + t.index : n;
        literals.push(src.slice(start, end));
        i = t ? end + t[0].length : n;
        continue;
      }
    }
    if (ch === "'" || ch === '"') {
      let j = i + 1;
      while (j < n) {
        if (src[j] === '\\') { j += 2; continue; }
        if (src[j] === ch) break;
        j += 1;
      }
      literals.push(src.slice(i + 1, j));
      i = j + 1;
      continue;
    }
    i += 1;
  }
  const markupRegions = regions.filter((r) => r.trim() && (TAG.test(r) || /\S/.test(r)));
  const markupLiterals = literals.filter((s) => s.length >= 8 && TAG.test(s));
  const inlineBytes = markupRegions.reduce((s, r) => s + Buffer.byteLength(r.trim()), 0);
  const literalBytes = markupLiterals.reduce((s, r) => s + Buffer.byteLength(r), 0);
  const blob = markupRegions.join('\n') + '\n' + markupLiterals.join('\n');
  let jsCss = 0;
  for (const m of blob.matchAll(/<(script|style)\b[\s\S]*?<\/\1>/gi)) jsCss += Buffer.byteLength(m[0]);
  return { inlineBytes, literalBytes, jsCss };
}

if (wantHtml) {
  const rows = [];
  for (const f of phpTargets) {
    const raw = read(f);
    const h = scanHtml(raw);
    const html = h.inlineBytes + h.literalBytes;
    if (html < 512) continue;
    const code = maskComments(raw);
    rows.push({
      rel: f.rel,
      target: targetOf(f.rel),
      kb: kb(f.size),
      htmlKb: +(html / 1024).toFixed(1),
      inlineKb: +(h.inlineBytes / 1024).toFixed(1),
      literalKb: +(h.literalBytes / 1024).toFixed(1),
      jsCssKb: +(h.jsCss / 1024).toFixed(1),
      ratio: f.size ? +(html / f.size).toFixed(2) : 0,
      entries: {
        menu: (code.match(/\badd_(?:menu|submenu)_page\s*\(/g) || []).length,
        shortcode: (code.match(/\badd_shortcode\s*\(/g) || []).length,
        ajax: (code.match(/['"]wp_ajax_(?:nopriv_)?\w+/g) || []).length,
        rest: (code.match(/\bregister_rest_route\s*\(/g) || []).length,
        template: /template_redirect|template_include|add_rewrite_rule/.test(code) ? 1 : 0,
      },
    });
  }
  rows.sort((a, b) => b.htmlKb - a.htmlKb);
  const totals = {};
  for (const r of rows) {
    const t = (totals[r.target] ||= { files: 0, htmlKb: 0, jsCssKb: 0, phpKb: 0 });
    t.files += 1; t.htmlKb += r.htmlKb; t.jsCssKb += r.jsCssKb; t.phpKb += r.kb;
  }
  result.html = { totals, rows };

  console.log('## PHP-rendered HTML (files with >= 0.5 KB of markup)\n');
  console.log('| target | files with HTML | HTML KB | of which embedded JS/CSS KB | PHP KB of those files |\n|---|---:|---:|---:|---:|');
  for (const [k, v] of Object.entries(totals)) {
    console.log(`| ${k} | ${v.files} | ${v.htmlKb.toFixed(0)} | ${v.jsCssKb.toFixed(0)} | ${v.phpKb} |`);
  }
  console.log('\n| file | KB | HTML KB (inline / in strings) | JS+CSS KB | ratio | menu | shortcode | ajax | rest |\n|---|---:|---:|---:|---:|---:|---:|---:|---:|');
  for (const r of rows.slice(0, top)) {
    console.log(`| ${r.rel.replace(/^core\//, '')} | ${r.kb} | ${r.htmlKb} (${r.inlineKb} / ${r.literalKb}) | ${r.jsCssKb} | ${r.ratio} | ${r.entries.menu} | ${r.entries.shortcode} | ${r.entries.ajax} | ${r.entries.rest} |`);
  }
  console.log('');
}

/* ── --ajax : declared wp_ajax actions vs. mentions ───────────────────── */
if (wantAjax) {
  const declared = new Map(); // action -> Set(files)
  for (const f of phpTargets) {
    for (const m of maskComments(read(f)).matchAll(/['"]wp_ajax_(?:nopriv_)?(\w+)/g)) {
      if (!declared.has(m[1])) declared.set(m[1], new Set());
      declared.get(m[1]).add(f.rel);
    }
  }
  const searchable = files.filter((f) => ['.php', '.js', '.mjs', '.ts', '.tsx', '.jsx', '.html'].includes(f.ext));
  const rows = [];
  for (const [action, declFiles] of declared) {
    const re = new RegExp(`(?<![\\w])${action}(?![\\w])`);
    const mentions = [];
    for (const f of searchable) {
      if (declFiles.has(f.rel)) continue;
      if (re.test(read(f))) mentions.push(f.rel);
    }
    rows.push({ action, declared: [...declFiles], mentions: mentions.length, sample: mentions.slice(0, 3) });
  }
  rows.sort((a, b) => a.mentions - b.mentions || a.action.localeCompare(b.action));
  result.ajax = rows;
  console.log(`## wp_ajax actions declared in targets: ${rows.length} (${rows.filter((r) => r.mentions === 0).length} with no mention outside the declaring file)\n`);
  console.log('| action | declared in | files mentioning it | sample |\n|---|---|---:|---|');
  for (const r of rows.slice(0, Math.max(top, rows.length))) {
    console.log(`| ${r.action} | ${r.declared.map((d) => d.replace(/^core\//, '')).join(', ')} | ${r.mentions} | ${r.sample.join(', ')} |`);
  }
  console.log('');
}

/* ── --refs : whole-plugin class references ───────────────────────────── */
if (wantRefs) {
  const declByClass = new Map();
  for (const [rel, facts] of phpFacts) for (const c of facts.classes) {
    if (!declByClass.has(c)) declByClass.set(c, []);
    declByClass.get(c).push(rel);
  }
  const names = [...declByClass.keys()];
  const bigRe = new RegExp(`\\b(${names.map((c) => c.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})\\b`, 'g');
  const refFiles = new Map();
  for (const f of files.filter((x) => ['.php', '.js', '.mjs'].includes(x.ext))) {
    for (const c of new Set(read(f).match(bigRe) || [])) {
      if (declByClass.get(c).includes(f.rel)) continue;
      if (!refFiles.has(c)) refFiles.set(c, new Set());
      refFiles.get(c).add(f.rel);
    }
  }
  const classRows = [];
  for (const [rel, facts] of phpFacts) {
    const t = targetOf(rel);
    for (const c of facts.classes) {
      const refs = [...(refFiles.get(c) || [])];
      const external = refs.filter((r) => !r.startsWith(`core/${t}/`));
      classRows.push({
        cls: c, file: rel, kb: Math.round(fsSize(rel) / 1024),
        external: external.length,
        externalLive: external.filter((r) => !['diagnostics', 'tests/bin'].includes(callerArea(r))).length,
        internal: refs.length - external.length,
        cli: facts.cli,
      });
    }
  }
  function fsSize(rel) { return files.find((x) => x.rel === rel)?.size || 0; }
  const unreferenced = classRows.filter((r) => r.external === 0 && r.internal === 0).sort((a, b) => b.kb - a.kb);
  const diagOnly = classRows.filter((r) => r.external > 0 && r.externalLive === 0 && r.internal === 0).sort((a, b) => b.kb - a.kb);
  const internalOnly = classRows.filter((r) => r.externalLive === 0 && r.internal > 0);

  const hashes = new Map();
  for (const f of inTargets) {
    const h = crypto.createHash('md5').update(read(f).replace(/\r\n/g, '\n')).digest('hex');
    if (!hashes.has(h)) hashes.set(h, []);
    hashes.get(h).push(f.rel);
  }
  const dupFiles = [...hashes.values()].filter((a) => a.length > 1);
  const dupClasses = [...declByClass.entries()].filter(([, a]) => a.length > 1);

  const byBase = new Map();
  for (const f of files) {
    const b = path.basename(f.rel).toLowerCase();
    if (!byBase.has(b)) byBase.set(b, []);
    byBase.get(b).push(f.rel);
  }
  const included = new Set();
  for (const f of files.filter((x) => x.ext === '.php')) {
    for (const m of maskComments(read(f)).matchAll(/['"]([^'"\n]*?([\w.-]+\.php))['"]/g)) {
      const cands = byBase.get(m[2].toLowerCase());
      if (!cands) continue;
      const frag = m[1].replace(/^\/+/, '').toLowerCase();
      for (const c of cands) if (c !== f.rel && (frag === m[2].toLowerCase() || c.toLowerCase().endsWith(frag))) included.add(c);
    }
  }
  const neverNamed = phpTargets.filter((f) => !included.has(f.rel))
    .map((f) => ({ rel: f.rel, kb: kb(f.size), classes: phpFacts.get(f.rel).classes.length }))
    .sort((a, b) => b.kb - a.kb);

  result.refs = { unreferenced, diagOnly, internalOnlyCount: internalOnly.length, dupFiles, dupClasses: dupClasses.map(([c, a]) => ({ cls: c, files: a })), neverNamed };

  console.log('## References\n');
  console.log(`Classes with no reference outside their own file (${unreferenced.length}; WP-CLI / hook / dynamic registration is invisible here):`);
  for (const r of unreferenced.slice(0, top)) console.log(`- ${r.cls} — ${r.file.replace(/^core\//, '')} (${r.kb} KB)${r.cli ? ' [WP-CLI]' : ''}`);
  console.log(`\nClasses referenced only from diagnostics/tests (${diagOnly.length}):`);
  for (const r of diagOnly.slice(0, top)) console.log(`- ${r.cls} — ${r.file.replace(/^core\//, '')} (${r.kb} KB)`);
  console.log(`\nClasses referenced only from inside their own target: ${internalOnly.length}`);
  console.log(`\nDuplicate file contents (line-ending-insensitive): ${dupFiles.length}`);
  for (const d of dupFiles) console.log(`- ${d.join('  ==  ')}`);
  console.log(`\nClass declared in more than one file: ${dupClasses.length}`);
  for (const [c, a] of dupClasses) console.log(`- ${c}: ${a.join(', ')}`);
  console.log(`\nPHP files whose name no other file mentions in a string literal: ${neverNamed.length}`);
  for (const r of neverNamed.slice(0, top)) console.log(`- ${r.rel.replace(/^core\//, '')} (${r.kb} KB, ${r.classes} classes)`);
  console.log('');
}

if (jsonOut) {
  fs.writeFileSync(path.resolve(root, jsonOut), JSON.stringify(result, null, 2));
  console.log(`JSON written: ${jsonOut}`);
}
