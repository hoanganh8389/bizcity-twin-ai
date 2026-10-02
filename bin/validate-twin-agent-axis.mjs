#!/usr/bin/env node
/**
 * R-TWIN-AGENT-AXIS validator (PHASE-0.87 CL-10) — keeps the code aligned with docs/contracts/TWIN-AGENT-AXIS-v1.json.
 *
 *   node bin/validate-twin-agent-axis.mjs            wave-1 checks; later-wave gaps are reported as "pending"
 *   node bin/validate-twin-agent-axis.mjs --strict   pending gaps fail too
 *   node bin/validate-twin-agent-axis.mjs --json     machine output
 *
 * Checks
 *   1. Every mode of the axis JSON is registered by BizCity_Agent_Mode_Access::defaults() with an access block, and the
 *      registry has no mode the JSON does not know.
 *   2. Markers `@axis twin-agent-axis@1 <kind> <id[,id]>` (PHP docblock or TS `//`) are inventoried; every pack kind and
 *      tool of the JSON has at least one implementing marker (missing ⇒ pending, not yet in wave).
 *   3. Every PHP file carrying a marker contains no LLM / embedding call (R-TAA-6: the plugin never answers for the axis).
 *   4. owner-capture@1 is the only write path from Zalo: the retired PHASE-0.86 triggers stay removed.
 *   5. Shared fixtures zalo-hub/contracts/fixtures/taa match their CHECKSUMS.json (sha256, CRLF→LF, same as the cell).
 *   6. Every PHASE-0.87 doc links the axis rule.
 *   7. Cell owner tools (TS markers `tool <id>`) declare the mode that gates them.
 *
 * // @axis twin-agent-axis@1 seam validator CL-10
 */
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..');
const strict = process.argv.includes('--strict');
const asJson = process.argv.includes('--json');
const axis = JSON.parse(fs.readFileSync(path.join(root, 'docs', 'contracts', 'TWIN-AGENT-AXIS-v1.json'), 'utf8'));
const fail = [];
const pending = [];
const rel = (p) => path.relative(root, p).split(path.sep).join('/');
const read = (p) => (fs.existsSync(p) ? fs.readFileSync(p, 'utf8') : '');
const SKIP = new Set(['_archived', '_library', 'node_modules', 'vendor', 'dist', 'build', '.git', '.vite', 'data', 'evidence']);

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

// ── 1. mode registry ↔ axis JSON ─────────────────────────────────────
const registrySrc = read(path.join(root, 'core', 'channel-gateway', 'includes', 'class-agent-mode-access.php'));
const defaultsBody = (registrySrc.match(/function defaults\(\)[\s\S]*?return array\(([\s\S]*?)\n\t\t\);/) || [])[1] || '';
const registered = [...defaultsBody.matchAll(/^\s*'([a-z_]+)'\s*=>\s*array\(\s*'label'/gm)].map((m) => m[1]);
const accessOk = new Set([...defaultsBody.matchAll(/^\s*'([a-z_]+)'\s*=>\s*array\([^\n]*'access'\s*=>/gm)].map((m) => m[1]));
const jsonModes = (axis.modes || []).map((m) => m.id);
if (!registrySrc) fail.push('agent-mode-access registry missing: core/channel-gateway/includes/class-agent-mode-access.php');
for (const id of jsonModes) {
  if (!registered.includes(id)) fail.push(`mode "${id}" of the axis JSON is not registered in BizCity_Agent_Mode_Access::defaults()`);
  else if (!accessOk.has(id)) fail.push(`mode "${id}" is registered without an access block`);
}
for (const id of registered) if (!jsonModes.includes(id)) fail.push(`registry mode "${id}" is unknown to the axis JSON`);

// ── 2. marker inventory ──────────────────────────────────────────────
const phpFiles = ['core', 'plugins', 'modules'].flatMap((d) => walk(path.join(root, d), ['.php']));
const tsFiles = walk(path.join(root, 'zalo-hub', 'src'), ['.ts']);
const markers = [];
for (const f of [...phpFiles, ...tsFiles]) {
  const src = read(f);
  if (!src.includes('@axis twin-agent-axis@1')) continue;
  for (const m of src.matchAll(/@axis twin-agent-axis@1\s+([a-z_]+)\s+([^\s*]+)/g)) {
    for (const id of m[2].split(',')) markers.push({ file: rel(f), kind: m[1], id: id.trim(), lang: f.endsWith('.ts') ? 'ts' : 'php' });
  }
}
const has = (kind, id) => markers.some((m) => m.kind === kind && m.id === id);
for (const p of axis.packs || []) {
  if (p.kind === 'knowledge') continue; // existing C-4 route, predates the marker rule
  if (!has('pack', p.kind)) pending.push(`pack "${p.kind}" has no implementing marker yet`);
}
for (const mode of axis.modes || []) {
  for (const t of mode.tools || []) {
    const id = String(t).split(':')[0];
    if (!has('tool', id)) pending.push(`tool "${id}" (mode ${mode.id}) has no implementing marker yet`);
  }
}

// ── 3. no LLM / embedding call in marked PHP ─────────────────────────
const LLM = /(BizCity_LLM_Client::|->chat\(|chat_stream\(|\/chat\/completions|\/embeddings|bizcity_llm_chat\(|BizCity_Embedding)/;
for (const f of new Set(markers.filter((m) => m.lang === 'php').map((m) => m.file))) {
  const lines = read(path.join(root, f)).split(/\r?\n/);
  lines.forEach((line, i) => {
    if (/^\s*(\/\/|\*|\/\*|#)/.test(line)) return;
    if (LLM.test(line)) fail.push(`${f}:${i + 1} calls an LLM/embedding API inside an axis file (R-TAA-6)`);
  });
}

// ── 4. single write path ─────────────────────────────────────────────
const zp = path.join(root, 'plugins', 'bizcity-zalo-personal', 'includes', 'shared');
if (/from_native_self\(/.test(read(path.join(zp, 'class-zalo-inbound-emitter.php')))) fail.push('class-zalo-inbound-emitter.php still calls the retired from_native_self() capture');
if (/from_outbound\(/.test(read(path.join(zp, 'class-zalo-bridge-client.php')))) fail.push('class-zalo-bridge-client.php still calls the retired from_outbound() capture');
if (!fs.existsSync(path.join(zp, 'class-zalo-owner-capture-rest.php'))) fail.push('owner-capture@1 site route is missing (class-zalo-owner-capture-rest.php)');

// ── 5. shared fixtures ───────────────────────────────────────────────
const taa = path.join(root, 'zalo-hub', 'contracts', 'fixtures', 'taa');
if (!fs.existsSync(taa)) {
  pending.push('zalo-hub/contracts/fixtures/taa not on this machine');
} else {
  const manifest = JSON.parse(read(path.join(taa, 'CHECKSUMS.json')) || '{"files":{}}');
  const files = fs.readdirSync(taa).filter((f) => f.endsWith('.json') && f !== 'CHECKSUMS.json').sort();
  const listed = Object.keys(manifest.files || {}).sort();
  if (JSON.stringify(files) !== JSON.stringify(listed)) fail.push('taa fixtures and CHECKSUMS.json list different files — run corepack pnpm contracts:checksum in zalo-hub');
  for (const f of files) {
    const sum = crypto.createHash('sha256').update(read(path.join(taa, f)).replace(/\r\n/g, '\n'), 'utf8').digest('hex');
    if (manifest.files?.[f] && manifest.files[f] !== sum) fail.push(`taa/${f} drifted from CHECKSUMS.json`);
  }
}

// ── 6. docs link the rule ────────────────────────────────────────────
const docDir = path.join(root, 'core', 'channel-gateway', 'docs', 'PHASE-0.87-OWNER-AGENT-VERTICAL-TOOLS');
for (const f of fs.existsSync(docDir) ? fs.readdirSync(docDir).filter((x) => x.endsWith('.md')) : []) {
  if (!read(path.join(docDir, f)).includes('PHASE-0-RULE-TWIN-AGENT-AXIS.md')) fail.push(`PHASE-0.87/${f} does not link docs/rules/PHASE-0-RULE-TWIN-AGENT-AXIS.md`);
}

// ── 7. cell owner tools declare their mode ───────────────────────────
const ownerTools = new Set((axis.modes || []).flatMap((m) => (m.tools || []).map((t) => String(t).split(':')[0])));
for (const m of markers.filter((x) => x.lang === 'ts' && x.kind === 'tool' && ownerTools.has(x.id))) {
  if (!/\bmode\s*:\s*['"][a-z_]+['"]/.test(read(path.join(root, m.file)))) fail.push(`${m.file}: owner tool "${m.id}" does not declare the mode that gates it`);
}

// ── report ───────────────────────────────────────────────────────────
const result = { ok: fail.length === 0 && (!strict || pending.length === 0), fail, pending, markers: markers.length, marker_files: new Set(markers.map((m) => m.file)).size };
if (asJson) {
  console.log(JSON.stringify(result, null, 2));
} else {
  console.log(`twin-agent-axis: ${result.markers} markers in ${result.marker_files} files · ${fail.length} fail · ${pending.length} pending${strict ? ' (strict)' : ''}`);
  for (const f of fail) console.log(`  FAIL    ${f}`);
  for (const p of pending) console.log(`  pending ${p}`);
  console.log(result.ok ? 'PASS' : 'FAIL');
}
process.exit(result.ok ? 0 : 1);
