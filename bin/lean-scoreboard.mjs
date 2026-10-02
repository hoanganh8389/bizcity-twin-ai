#!/usr/bin/env node
// R-LEAN-4 lean scoreboard — shipped PHP and live client tables against docs/contracts/CLIENT-LEAN-BUDGET-v1.json.
// [2026-09-30 Claude Opus 5.5] R-LEAN-4 §3 / WP-16 — one command for the two budget metrics (PHP < 10 MB, tables < 30).
//
// Shipped PHP   = plugin PHP minus bin/dev-only-paths.txt, bin/deploy-exclude-paths.txt, _library, vendor, _archived,
//                 dist, zalo-hub/.
// Live tables   = tables declared in core/helper/schema/changelog − Hub-owned changelogs (contract hub_owned_changelogs)
//                 − tables retired or quarantined in core/helper/class-bizcity-legacy-table-policy.php.
//
//   node bin/lean-scoreboard.mjs          # human summary
//   node bin/lean-scoreboard.mjs --json   # machine form
import fs from 'node:fs';
import path from 'node:path';
import { readManifest, readDeployExclude, isDevOnly } from './validate-dev-only-boundary.mjs';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, '$1')), '..');
const SKIP = new Set(['_library', 'node_modules', 'vendor', '_archived', 'dist', '_notes', '.git', 'zalo-hub']);
const budget = JSON.parse(fs.readFileSync(path.join(ROOT, 'docs/contracts/CLIENT-LEAN-BUDGET-v1.json'), 'utf8'));
const devOnly = readManifest(ROOT) || [];
const deployExclude = readDeployExclude(ROOT);

// ── 1. PHP ────────────────────────────────────────────────────────────────────
let shipped = 0;
let devBytes = 0;
let excludedBytes = 0;
const byArea = {};
const walk = (dir) => {
	for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
		if (SKIP.has(e.name)) continue;
		const abs = path.join(dir, e.name);
		const rel = path.relative(ROOT, abs).split(path.sep).join('/');
		if (e.isDirectory()) { walk(abs); continue; }
		if (!rel.endsWith('.php')) continue;
		const size = fs.statSync(abs).size;
		if (isDevOnly(rel, devOnly)) { devBytes += size; continue; }
		if (isDevOnly(rel, deployExclude)) { excludedBytes += size; continue; }
		shipped += size;
		const parts = rel.split('/');
		const key = ['core', 'modules', 'plugins'].includes(parts[0]) && parts.length > 2 ? parts.slice(0, 2).join('/') : parts[0];
		byArea[key] = (byArea[key] || 0) + size;
	}
};
walk(ROOT);

// ── 2. Tables ────────────────────────────────────────────────────────────────
const schemaDir = path.join(ROOT, 'core/helper/schema/changelog');
const declared = {};
for (const f of fs.readdirSync(schemaDir).filter((x) => x.endsWith('.json'))) {
	const j = JSON.parse(fs.readFileSync(path.join(schemaDir, f), 'utf8'));
	const names = j.tables && typeof j.tables === 'object' ? Object.keys(j.tables) : [];
	if (names.length) declared[f.replace(/\.json$/, '')] = names;
}
const policySrc = fs.readFileSync(path.join(ROOT, 'core/helper/class-bizcity-legacy-table-policy.php'), 'utf8');
const policyList = (name) => {
	// Stop at the array's own closing line — a comment inside the array may contain ');'.
	const m = policySrc.match(new RegExp('private static \\$' + name + '\\s*=\\s*array\\(([\\s\\S]*?)\\r?\\n\\t\\);'));
	// Table names are quoted snake_case (most start with bizcity_, archived tool tables use their own prefix, e.g. bztimg_).
	return m ? [...m[1].matchAll(/'([a-z][a-z0-9]*_[a-z0-9_]+)'/g)].map((x) => x[1]) : [];
};
const notLive = new Set([...policyList('retired'), ...policyList('quarantine')]);
const hubOwned = new Set([...(budget.hub_owned_changelogs || []), ...(budget.extension_owned_changelogs || [])]);
const keep = new Set(budget.keep_tables || []);
const live = [];
const hub = [];
const retiredOrQuarantined = [];
for (const [owner, names] of Object.entries(declared)) {
	for (const t of names) {
		if (hubOwned.has(owner)) hub.push(t);
		else if (notLive.has(t)) retiredOrQuarantined.push(t);
		else live.push({ owner, table: t });
	}
}
const liveOutsideKeep = live.filter((x) => !keep.has(x.table));
const keepNotDeclared = [...keep].filter((t) => !live.some((x) => x.table === t));

const mb = (b) => +(b / 1048576).toFixed(2);
const out = {
	rule: 'R-LEAN-4',
	contract: `${budget.contract}@${budget.version}`,
	at: new Date().toISOString(),
	php: {
		shipped_mb: mb(shipped),
		budget_mb: budget.budgets.shipped_php_mb,
		dev_only_mb: mb(devBytes),
		deploy_excluded_mb: mb(excludedBytes),
		by_area_kb: Object.fromEntries(Object.entries(byArea).sort((a, b) => b[1] - a[1]).map(([k, v]) => [k, Math.round(v / 1024)])),
	},
	tables: {
		declared: Object.values(declared).reduce((n, t) => n + t.length, 0),
		hub_or_extension_owned: hub.length,
		retired_or_quarantined: retiredOrQuarantined.length,
		live_client: live.length,
		budget: budget.budgets.live_client_tables,
		live_outside_keep_list: liveOutsideKeep.length,
		keep_list_not_declared: keepNotDeclared,
		live_by_owner: live.reduce((a, x) => { a[x.owner] = (a[x.owner] || 0) + 1; return a; }, {}),
	},
};
if (process.argv.includes('--json')) {
	console.log(JSON.stringify(out, null, 2));
} else {
	const p = out.php;
	const t = out.tables;
	console.log(`PHP  shipped ${p.shipped_mb} MB (budget < ${p.budget_mb}) · dev-only ${p.dev_only_mb} MB · committed-not-uploaded ${p.deploy_excluded_mb} MB`);
	console.log(`Tables  live client ${t.live_client} (budget < ${t.budget}) · declared ${t.declared} · Hub/extension-owned ${t.hub_or_extension_owned} · retired/quarantined ${t.retired_or_quarantined} · live outside keep list ${t.live_outside_keep_list}`);
	if (t.keep_list_not_declared.length) console.log(`  keep list names not declared as live: ${t.keep_list_not_declared.join(', ')}`);
	console.log('  live by owner: ' + Object.entries(t.live_by_owner).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ${v}`).join(' · '));
	console.log('  PHP by area (KB): ' + Object.entries(p.by_area_kb).slice(0, 12).map(([k, v]) => `${k} ${v}`).join(' · '));
}

// ── 3. Ratchet (R-LEAN-4 R-L4-3: "càng làm càng gọn") ────────────────────────
// --check    exit 1 when shipped PHP or live client tables are ABOVE the recorded ratchet (a change made the plugin heavier).
// --ratchet  lower the recorded ratchet to today's figures when they improved; it never raises it.
// A growth that is intended must be written into the contract's ratchet by hand, with the reason, in the same change.
const TOLERANCE_MB = 0.02; // line-ending / comment noise
const contractPath = path.join(ROOT, 'docs/contracts/CLIENT-LEAN-BUDGET-v1.json');
const ratchet = budget.ratchet || null;
if (process.argv.includes('--check')) {
	if (!ratchet) {
		console.error('LEAN RATCHET: no ratchet in CLIENT-LEAN-BUDGET-v1.json — run with --ratchet once.');
		process.exit(1);
	}
	const phpOver = out.php.shipped_mb > ratchet.shipped_php_mb + TOLERANCE_MB;
	const tblOver = out.tables.live_client > ratchet.live_client_tables;
	console.log(`LEAN RATCHET ${phpOver || tblOver ? 'FAIL' : 'PASS'} — PHP ${out.php.shipped_mb} / ratchet ${ratchet.shipped_php_mb} MB · live tables ${out.tables.live_client} / ratchet ${ratchet.live_client_tables}`);
	if (phpOver || tblOver) {
		console.log('  A change made the plugin heavier. Pay it back in the same change, or record the growth and its reason in the contract ratchet (R-LEAN-4 R-L4-3).');
		process.exit(1);
	}
}
if (process.argv.includes('--ratchet')) {
	const c = JSON.parse(fs.readFileSync(contractPath, 'utf8'));
	const prev = c.ratchet || { shipped_php_mb: Infinity, live_client_tables: Infinity };
	const next = {
		shipped_php_mb: Math.min(prev.shipped_php_mb, out.php.shipped_mb),
		live_client_tables: Math.min(prev.live_client_tables, out.tables.live_client),
		updated: new Date().toISOString().slice(0, 10),
	};
	if (next.shipped_php_mb !== prev.shipped_php_mb || next.live_client_tables !== prev.live_client_tables || !c.ratchet) {
		c.ratchet = next;
		fs.writeFileSync(contractPath, JSON.stringify(c, null, 2) + '\n');
		console.log(`LEAN RATCHET lowered → PHP ${next.shipped_php_mb} MB · live tables ${next.live_client_tables}`);
	} else {
		console.log('LEAN RATCHET unchanged (no improvement to record).');
	}
}
