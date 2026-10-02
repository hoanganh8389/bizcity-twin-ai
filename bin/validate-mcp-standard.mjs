#!/usr/bin/env node
/**
 * One MCP standard validator (PHASE-0.88 X1) — keeps site core/mcp, the Hub relay and the zalo-hub cell aligned with
 * docs/contracts/BIZCITY-MCP-STANDARD-v1.json and prints the 3-layer MCP scoreboard (numbers are generated, never edited).
 *
 *   node bin/validate-mcp-standard.mjs            missing items of later waves are "pending"
 *   node bin/validate-mcp-standard.mjs --strict   pending fails too (CI from wave 6)
 *   node bin/validate-mcp-standard.mjs --json     machine output
 *
 * Checks
 *   1. Every catalog tool has a site marker `@mcp bizcity-mcp-standard@1 tool <name>`; the register() block after the
 *      marker declares the same mode, and `'confirm' => 'always'` when the catalog says always.
 *   2. BizCity_MCP_Delegation mirrors the catalog's mode → scopes map (delegated principals get exactly those scopes).
 *   3. Tools registered before 0.88 carry the catalog's mode (existing_tool_modes) so the cell can reach them by mode.
 *   4. PHP files carrying an @mcp marker never call an LLM / embedding API (R-PF-11, R-TAA-6).
 *   5. Shared fixtures zalo-hub/contracts/fixtures/mcp match their CHECKSUMS.json (sha256, CRLF→LF, same as the cell).
 *   6. Seams exist: site bridge route, delegation class, Hub relay (sibling plugin), cell MCP client; only the cell client
 *      module talks to the Hub MCP route.
 *   7. Resource templates of the catalog have a marker `@mcp bizcity-mcp-standard@1 resource <uriTemplate>` (wave 5).
 *
 * // @mcp bizcity-mcp-standard@1 validator X1
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..');
const strict = process.argv.includes('--strict');
const asJson = process.argv.includes('--json');
const std = JSON.parse(fs.readFileSync(path.join(root, 'docs', 'contracts', 'BIZCITY-MCP-STANDARD-v1.json'), 'utf8'));
const fail = [];
const pending = [];
const rel = (p) => path.relative(root, p).split(path.sep).join('/');
const read = (p) => (fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '');
const SKIP = new Set(['_archived', '_library', 'node_modules', 'vendor', 'dist', 'build', '.git', '.vite', 'data', 'evidence', 'tests']);
const MARK = '@mcp bizcity-mcp-standard@1';

function walk(dir, exts, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (SKIP.has(e.name)) continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, exts, out);
    else if (e.isFile() && exts.some((x) => e.name.endsWith(x)) && !/\.test\.ts$/.test(e.name)) out.push(p);
  }
  return out;
}

// ── marker inventory ─────────────────────────────────────────────────
const phpFiles = ['core', 'plugins', 'modules'].flatMap((d) => walk(path.join(root, d), ['.php']));
const tsFiles = walk(path.join(root, 'zalo-hub', 'src'), ['.ts']);
const markers = [];
for (const f of [...phpFiles, ...tsFiles]) {
  const src = read(f);
  if (!src.includes(MARK)) continue;
  const lines = src.split(/\r?\n/);
  lines.forEach((line, i) => {
    const m = line.match(/@mcp bizcity-mcp-standard@1\s+([a-z-]+)(?:\s+([^\s*]+))?/);
    if (m) markers.push({ file: rel(f), line: i + 1, kind: m[1], id: (m[2] || '').trim(), lang: f.endsWith('.ts') ? 'ts' : 'php', window: lines.slice(i, i + 45).join('\n') });
  });
}
const siteTool = (name) => markers.find((m) => m.lang === 'php' && m.kind === 'tool' && m.id === name);

// ── 1. catalog tools ─────────────────────────────────────────────────
const tools = std.tools || [];
const toolDone = [];
for (const t of tools) {
  const m = siteTool(t.name);
  if (!m) { pending.push(`tool "${t.name}" (wave ${t.wave}) has no site marker yet`); continue; }
  const modeRe = new RegExp(`'mode'\\s*=>\\s*'${t.mode.replace('*', '\\*')}'`);
  if (!modeRe.test(m.window)) fail.push(`${m.file}:${m.line} tool "${t.name}" does not declare 'mode' => '${t.mode}'`);
  if (t.confirm === 'always' && !/'confirm'\s*=>\s*'always'/.test(m.window)) fail.push(`${m.file}:${m.line} tool "${t.name}" must declare 'confirm' => 'always'`);
  if (t.kind === 'write' && !/'read_only'\s*=>\s*false/.test(m.window)) fail.push(`${m.file}:${m.line} write tool "${t.name}" must declare 'read_only' => false`);
  toolDone.push(t);
}

// ── 2. delegation scopes ─────────────────────────────────────────────
const delegationFile = path.join(root, 'core', 'mcp', 'includes', 'class-mcp-delegation.php');
const delegationSrc = read(delegationFile);
if (!delegationSrc) {
  pending.push('core/mcp/includes/class-mcp-delegation.php missing (L3-1)');
} else {
  for (const [mode, def] of Object.entries(std.modes || {})) {
    const m = delegationSrc.match(new RegExp(`'${mode}'\\s*=>\\s*array\\(([^)]*)\\)`));
    if (!m) { fail.push(`class-mcp-delegation.php has no scope list for mode "${mode}"`); continue; }
    const got = [...m[1].matchAll(/'([a-z.]+)'/g)].map((x) => x[1]).sort();
    const want = [...(def.scopes || [])].sort();
    if (JSON.stringify(got) !== JSON.stringify(want)) fail.push(`class-mcp-delegation.php scopes for "${mode}" = [${got}] but the catalog says [${want}]`);
  }
}

// ── 3. modes of pre-0.88 tools ───────────────────────────────────────
const mcpSrc = walk(path.join(root, 'core', 'mcp'), ['.php']).map(read).join('\n');
for (const [tool, mode] of Object.entries(std.existing_tool_modes || {})) {
  if (tool === 'notes') continue;
  if (!new RegExp(`'${tool.replace(/\./g, '\\.')}'\\s*=>\\s*'${mode}'`).test(mcpSrc)) pending.push(`pre-0.88 tool "${tool}" has no mode map entry '${tool}' => '${mode}' in core/mcp`);
}

// ── 4. no LLM in marked PHP ──────────────────────────────────────────
const LLM = /(BizCity_LLM_Client::|->chat\(|chat_stream\(|\/chat\/completions|\/embeddings|bizcity_llm_chat\(|BizCity_Embedding)/;
for (const f of new Set(markers.filter((m) => m.lang === 'php').map((m) => m.file))) {
  read(path.join(root, f)).split(/\r?\n/).forEach((line, i) => {
    if (/^\s*(\/\/|\*|\/\*|#)/.test(line)) return;
    if (LLM.test(line)) fail.push(`${f}:${i + 1} calls an LLM/embedding API inside an MCP tool file (R-PF-11)`);
  });
}

// ── 5. shared fixtures ───────────────────────────────────────────────
const fx = path.join(root, 'zalo-hub', 'contracts', 'fixtures', 'mcp');
if (!fs.existsSync(fx)) {
  pending.push('zalo-hub/contracts/fixtures/mcp not on this machine');
} else if (!fs.existsSync(path.join(fx, 'CHECKSUMS.json'))) {
  pending.push('fixtures/mcp/CHECKSUMS.json missing — run corepack pnpm contracts:checksum in zalo-hub');
} else {
  const manifest = JSON.parse(read(path.join(fx, 'CHECKSUMS.json')) || '{"files":{}}');
  const files = fs.readdirSync(fx).filter((f) => f.endsWith('.json') && f !== 'CHECKSUMS.json').sort();
  if (JSON.stringify(files) !== JSON.stringify(Object.keys(manifest.files || {}).sort())) fail.push('mcp fixtures and CHECKSUMS.json list different files — run corepack pnpm contracts:checksum in zalo-hub');
  for (const f of files) {
    const sum = crypto.createHash('sha256').update(read(path.join(fx, f)).replace(/\r\n/g, '\n'), 'utf8').digest('hex');
    if (manifest.files?.[f] && manifest.files[f] !== sum) fail.push(`mcp/${f} drifted from CHECKSUMS.json`);
  }
}

// ── 6. seams ─────────────────────────────────────────────────────────
const seams = {
  site_bridge_route: fs.existsSync(path.join(root, 'plugins', 'bizcity-zalo-personal', 'includes', 'shared', 'class-zalo-mcp-bridge-rest.php')),
  delegation: !!delegationSrc,
  hub_relay: fs.existsSync(path.join(root, '..', 'bizcity-llm-router', 'includes', 'class-router-zalo-hub-mcp-relay-s88.php')),
  cell_client: markers.some((m) => m.lang === 'ts' && m.kind === 'cell-client'),
};
for (const [k, v] of Object.entries(seams)) if (!v) pending.push(`seam ${k} not built yet`);
for (const f of tsFiles) {
  const r = rel(f);
  if (r.startsWith('zalo-hub/src/brain-core/mcp/')) continue;
  if (/zalo-hub\/mcp\b/.test(read(f))) fail.push(`${r} calls the Hub MCP route directly; only zalo-hub/src/brain-core/mcp/* may`);
}

// ── 7. resources ─────────────────────────────────────────────────────
const templates = std.resources?.templates || [];
const resDone = templates.filter((t) => markers.some((m) => m.kind === 'resource' && m.id === t.uriTemplate));
for (const t of templates) if (!resDone.includes(t)) pending.push(`resource "${t.uriTemplate}" (wave ${std.resources.wave}) has no marker yet`);

// ── scoreboard ───────────────────────────────────────────────────────
const pct = (a, b) => (b ? Math.round((a / b) * 100) : 0);
const writeTools = tools.filter((t) => t.kind === 'write');
const scoreboard = {
  L1_tools: { done: toolDone.length, total: tools.length, pct: pct(toolDone.length, tools.length), write_done: toolDone.filter((t) => t.kind === 'write').length, write_total: writeTools.length },
  L2_resources: { done: resDone.length, total: templates.length, pct: pct(resDone.length, templates.length) },
  L3_seams: { done: Object.values(seams).filter(Boolean).length, total: Object.keys(seams).length, pct: pct(Object.values(seams).filter(Boolean).length, Object.keys(seams).length) },
};

const result = { ok: fail.length === 0 && (!strict || pending.length === 0), scoreboard, fail, pending, markers: markers.length };
if (asJson) {
  console.log(JSON.stringify(result, null, 2));
} else {
  const s = scoreboard;
  console.log(`mcp-standard: L1 tools ${s.L1_tools.done}/${s.L1_tools.total} (${s.L1_tools.pct}%, write ${s.L1_tools.write_done}/${s.L1_tools.write_total}) · L2 resources ${s.L2_resources.done}/${s.L2_resources.total} (${s.L2_resources.pct}%) · L3 seams ${s.L3_seams.done}/${s.L3_seams.total} (${s.L3_seams.pct}%)`);
  console.log(`  ${markers.length} markers · ${fail.length} fail · ${pending.length} pending${strict ? ' (strict)' : ''}`);
  for (const f of fail) console.log(`  FAIL    ${f}`);
  for (const p of pending) console.log(`  pending ${p}`);
  console.log(result.ok ? 'PASS' : 'FAIL');
}
process.exit(result.ok ? 0 : 1);
