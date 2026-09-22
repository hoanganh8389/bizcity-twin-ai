#!/usr/bin/env node
/**
 * WP6 — Context Bank pointer isolation and KG-Hub promotion ownership.
 *
 * PHASE-0-RULE-CONTEXT-BANK makes two structural promises that a static gate can
 * actually hold, and this reconciles both plus the KG half:
 *
 *  1. `R-CB.ledger_payload_column` — the pointer ledger must never be able to
 *     HOLD a payload. The rule text ("never store memory, message, document,
 *     rule, decrypted content, embedding, credential, raw secret or copied JSON
 *     payload") is enforced today only by the writer's discipline. A TEXT/BLOB/
 *     JSON column added to the DDL would make the violation possible before it
 *     is ever observable in behaviour, so the column TYPES are gated directly.
 *     Both tables currently declare zero payload-capable columns — the widest
 *     is `relative_file VARCHAR(500)` — so this baseline is intentionally empty
 *     and a regression fails on the commit that introduces it.
 *
 *  2. `R-CB.ledger_write_outside_owner` — one writer. The rule names
 *     `BizCity_Context_Bank_Ledger::record()` as the single admission path, so a
 *     `$wpdb` write to `bizcity_context_bank*` from outside `core/context-bank/`
 *     is a second source of truth.
 *
 *  3. `R-KG.promotion_outside_owner` — KG-Hub must own entity/relation/citation
 *     promotion. A write to a `bizcity_kg_*` table from outside
 *     `core/knowledge/kg-hub/` creates a passage, entity, relation or
 *     provenance row that never passed the KG promotion path, which breaks the
 *     citation chain the rule requires (kg relation/entity -> kg passage/source
 *     -> context record_id -> ledger pointer -> verified JSONL line).
 *
 * This is static source analysis. It does NOT execute a capture, does not prove
 * a receipt is valid at runtime, does not verify pointer/hash/tenant denial
 * behaviour, and does not prove rollup or retrieval-pack correctness. Those
 * remain runtime evidence owned by the Context Bank probes.
 *
 * Usage:
 *   node bin/validate-context-bank-kg-ownership.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-context-bank-kg-ownership.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { extractFunctions, maskStructural, matchParen, splitTopLevel } from './lib/php-source.mjs';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'context-bank-kg-ownership', 'baseline.json');
const baseline = !fixtureRoot && fs.existsSync(baselinePath)
  ? JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
  : { known_findings: [] };

const excludedSegments = new Set([
  '_archived',
  '_library',
  'node_modules',
  'vendor',
  'dist',
  'build',
  '.vite',
  '.git',
]);

/** Canonical owners. A write under these prefixes IS the owner. */
const contextBankOwner = /(?:^|\/)core\/context-bank\//;
const kgOwner = /(?:^|\/)core\/knowledge\/kg-hub\//;

/**
 * Exempt paths. Diagnostics probes and CLI fixtures create and tear down their
 * own synthetic rows by design — the same exemption, with the same reason, that
 * the WP4 identity rule and the WP5 CRM ownership gate already apply.
 */
const exemptions = [
  {
    pattern: /(?:^|\/)core\/diagnostics\/includes\/(?:probes|fixtures)\//,
    reason: 'Diagnostics probe/fixture creates and cleans up its own synthetic rows.',
  },
  {
    pattern: /(?:^|\/)bin\//,
    reason: 'CLI fixture script with an explicit --confirm gate and its own teardown.',
  },
];

/**
 * KG table helpers, taken from the owner itself
 * (`core/knowledge/kg-hub/includes/class-kg-database.php:248-268, 1042`) rather
 * than guessed.
 *
 * The helper names are matched ONLY in combination with a KG context, never on
 * their own. WP5 recorded that accepting any `::tbl_xxx()` assignment matched
 * KG's own `tbl_passages`/`tbl_sources` and produced 54 false positives; the
 * mirror of that mistake here would be matching CRM's `tbl_messages` or an
 * unrelated `tbl_sources` as a KG write.
 */
const kgHelperNames = [
  'notebooks', 'notebook_sources', 'passages', 'entities', 'relations',
  'passage_entities', 'passage_relations', 'triplet_queue', 'provenance',
  'scope_links', 'sources', 'xref', 'source_chunks',
  'notebook_character_attachments', 'passage_identities',
];
/**
 * The KG CONTENT tables, enumerated from the owner's own helpers rather than
 * matched by the `bizcity_kg_*` prefix.
 *
 * The prefix was tried first and over-reached. `modules/twinchat/includes/
 * learning/class-twinchat-learning-database.php` owns
 * `bizcity_kg_learning_jobs`, `bizcity_kg_learning_events` and
 * `bizcity_kg_learning_batches` — deliberately renamed into the `bizcity_kg_*`
 * namespace "for unified naming" (see that file's header) but they hold queue,
 * progress and ring-buffer state, not entities, relations, passages or
 * citations. This rule is about promotion ownership, so flagging a learning
 * job row would be a scope error, not a bypass.
 *
 * The negative lookahead matters: without it `bizcity_kg_passages` would also
 * match a hypothetical `bizcity_kg_passages_archive`.
 */
const kgContentTables = [
  'bizcity_kg_notebooks', 'bizcity_kg_notebook_sources', 'bizcity_kg_passages',
  'bizcity_kg_entities', 'bizcity_kg_relations', 'bizcity_kg_passage_entities',
  'bizcity_kg_passage_relations', 'bizcity_kg_triplet_queue',
  'bizcity_kg_provenance', 'bizcity_kg_scope_links', 'bizcity_kg_sources',
  'bizcity_kg_xref', 'bizcity_kg_passage_identities',
  'bizcity_notebook_character_attachments',
];
const kgLiteralPattern = new RegExp(`(?:${kgContentTables.join('|')})(?![a-z_])`);
const kgHelperPattern = new RegExp(`->\\s*tbl_(?:${kgHelperNames.join('|')})\\s*\\(`);
const contextBankLiteralPattern = /bizcity_context_bank[a-z_]*/;

const wpdbWritePattern = /\$wpdb\s*->\s*(?:insert|update|replace|delete)\s*\(/i;
/** Raw SQL writes: `$wpdb->query( "UPDATE {$db->tbl_passages()} ..." )`. */
const rawSqlWritePattern = /(?:UPDATE|DELETE\s+FROM|INSERT\s+INTO|REPLACE\s+INTO)\s/i;

/** Payload-capable column types that must never appear in the pointer ledger. */
const payloadColumnPattern = /\b(?:TINYTEXT|TEXT|MEDIUMTEXT|LONGTEXT|TINYBLOB|BLOB|MEDIUMBLOB|LONGBLOB|JSON)\b/i;

function walk(directory, files = [], extension = '.php') {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (excludedSegments.has(entry.name)) continue;
      walk(target, files, extension);
    } else if (entry.isFile() && target.toLowerCase().endsWith(extension)) {
      files.push(target);
    }
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

const findings = [];
const counters = {
  files_scanned: 0,
  functions_parsed: 0,
  table_accessors_resolved: 0,
  ledger_ddl_tables_checked: 0,
  ledger_ddl_columns_checked: 0,
  context_bank_writers: 0,
  context_bank_owner_writers: 0,
  kg_writing_files: 0,
  kg_owner_writers: 0,
  kg_exempt_writers: 0,
  kg_bypass_writers: 0,
  kg_passthrough_resolved: 0,
  ledger_passthrough_resolved: 0,
};

// ── Rule 1: the pointer ledger DDL must not declare a payload column ────────
// Read from the R-DCL changelog, which is the authoritative DDL source that
// `BizCity_Diagnostics_Auto_Create` actually executes.
const changelogRoot = fixtureRoot
  ? path.join(fixtureRoot, 'changelog')
  : path.join(root, 'core', 'diagnostics', 'changelog');
const ledgerChangelog = path.join(changelogRoot, 'core.context-bank.json');
if (fs.existsSync(ledgerChangelog)) {
  const document = JSON.parse(fs.readFileSync(ledgerChangelog, 'utf8'));
  for (const [tableName, definition] of Object.entries(document.tables || {})) {
    counters.ledger_ddl_tables_checked += 1;
    for (const [columnName, spec] of Object.entries(definition.columns || {})) {
      counters.ledger_ddl_columns_checked += 1;
      const type = String(spec.type || spec.sql || '');
      if (!payloadColumnPattern.test(type)) continue;
      findings.push({
        rule: 'R-CB.ledger_payload_column',
        file: relativeOf(ledgerChangelog),
        line: 1,
        table: tableName,
        column: columnName,
        owner: 'core/context-bank',
        missing: ['pointer_only_ledger'],
        contract: 'PHASE-0-RULE-CONTEXT-BANK §pointer/identity only, never payload',
        fix_hint:
          `Column ${tableName}.${columnName} is declared ${type}, which lets the pointer ledger hold a payload. Keep the body in the encrypted filestore and store only pointer, hash and identity dimensions.`,
        fingerprint: `cb_payload_column|${tableName}|${columnName}`,
      });
    }
  }
}

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    const relative = relativeOf(file);
    counters.files_scanned += 1;
    const source = fs.readFileSync(file, 'utf8');

    const mentionsKg = kgLiteralPattern.test(source) || kgHelperPattern.test(source);
    const mentionsLedger = contextBankLiteralPattern.test(source);
    if (!mentionsKg && !mentionsLedger) continue;

    const exempt = exemptions.find((entry) => entry.pattern.test(relative));
    const functions = extractFunctions(source);
    counters.functions_parsed += functions.length;

    // ── Class-constant expansion ────────────────────────────────────────────
    // The ledger never names its table inline: it declares
    // `const TABLE_BASE = 'bizcity_context_bank'` and writes through
    // `$this->table()`. Without expanding the constant, rule 2 inspects ZERO
    // writers and silently "passes" — the exact dormant-rule failure WP3
    // recorded. The JSONL parity gate already set this precedent by resolving
    // same-file class constants.
    const constMap = new Map();
    const constPattern = /const\s+([A-Z_][A-Z0-9_]*)\s*=\s*'([^']*)'/g;
    let constMatch;
    while ((constMatch = constPattern.exec(source)) !== null) {
      constMap.set(constMatch[1], constMatch[2]);
    }
    const expand = (text) => text.replace(
      /(?:self|static|[A-Za-z_][A-Za-z0-9_]*)\s*::\s*([A-Z_][A-Z0-9_]*)/g,
      (whole, name) => (constMap.has(name) ? `'${constMap.get(name)}'` : whole),
    );

    // Methods that RETURN a ledger/KG table name, so `$this->table()` resolves.
    const ledgerAccessors = new Set();
    const kgAccessors = new Set();
    for (const fn of functions) {
      const body = expand(fn.body);
      if (/return[^;]*;/.test(body) && contextBankLiteralPattern.test(body)) ledgerAccessors.add(fn.name);
      if (/return[^;]*;/.test(body) && kgLiteralPattern.test(body)) kgAccessors.add(fn.name);
    }
    const accessorCall = (names) => (names.size === 0 ? null : new RegExp(
      `(?:self|static|\\$this|\\$[a-z_][a-z0-9_]*)\\s*(?:::|->)\\s*(?:${[...names].join('|')})\\s*\\(`,
      'i',
    ));
    const ledgerAccessorPattern = accessorCall(ledgerAccessors);
    const kgAccessorPattern = accessorCall(kgAccessors);
    counters.table_accessors_resolved += ledgerAccessors.size + kgAccessors.size;

    const kgWrites = [];
    const ledgerWrites = [];

    // A write's target is classified against VARIABLES, referenced ONLY here
    // so the later interprocedural pass can reuse the exact same check. The
    // `|$` alternative matters for that pass: it tests an ISOLATED, trimmed
    // call argument (e.g. exactly `$tbl_passages`) rather than a full line of
    // code, so there is no guaranteed trailing `,`/`)`/brace to match against.
    const referencesVar = (text, vars) => [...vars].some(
      (name) => new RegExp(`${name.replace('$', '\\$')}\\s*(?:[,)\\s}]|$)`).test(text),
    );

    for (const fn of functions) {
      const body = fn.body;
      const bodyLines = body.split(/\r?\n/);
      const baseLine = source.slice(0, fn.start).split(/\r?\n/).length;

      // Variables are tracked at FUNCTION scope. File-wide tracking was tried
      // and was wrong for the same reason it was wrong in WP4/WP5: a 12k-line
      // controller reuses `$tbl` for dozens of unrelated tables, so one KG
      // assignment marked ~25 unrelated CRM product/task/lead writes as KG
      // promotion bypasses.
      // Kept on the function object (not a loop-local const) so the
      // interprocedural pass below can look up a CALLER's resolved variables
      // by function, not just the function currently being walked.
      fn.kgVars = new Set();
      fn.ledgerVars = new Set();
      for (const line of bodyLines) {
        const assign = /^\s*(\$[a-z_][a-z0-9_]*)\s*=\s*([^;]*);/i.exec(line);
        if (!assign) continue;
        const value = expand(assign[2]);
        if (kgLiteralPattern.test(value) || kgHelperPattern.test(value)
          || (kgAccessorPattern && kgAccessorPattern.test(value))) fn.kgVars.add(assign[1]);
        if (contextBankLiteralPattern.test(value)
          || (ledgerAccessorPattern && ledgerAccessorPattern.test(value))) fn.ledgerVars.add(assign[1]);
      }

      bodyLines.forEach((rawLine, index) => {
        const line = expand(rawLine);
        const isWpdbWrite = wpdbWritePattern.test(line);
        const isRawWrite = rawSqlWritePattern.test(line);
        if (!isWpdbWrite && !isRawWrite) return;

        const hitsKg = kgLiteralPattern.test(line) || kgHelperPattern.test(line)
          || (kgAccessorPattern && kgAccessorPattern.test(line))
          || referencesVar(line, fn.kgVars);
        const hitsLedger = contextBankLiteralPattern.test(line)
          || (ledgerAccessorPattern && ledgerAccessorPattern.test(line))
          || referencesVar(line, fn.ledgerVars);

        const absolute = baseLine + index;
        if (hitsKg) kgWrites.push({ line: absolute, text: rawLine.trim() });
        if (hitsLedger) ledgerWrites.push({ line: absolute, text: rawLine.trim() });
      });
    }

    // ── Interprocedural pass: table name passed as a PARAMETER ──────────────
    // Stated false negative (WP6 evidence, 2026-09-16): `insert_passage(
    // $tbl_passages, array $args )` writes `$wpdb->insert( $tbl_passages, ... )`
    // inside its OWN body, where `$tbl_passages` is a parameter, not a local
    // assignment — invisible to the per-function variable tracking above,
    // which only sees `$var = <kg-tagged value>;`. This resolves it by
    // reading PARAMETER NAMES from the shared parser (new: `fn.params`) and,
    // for every `$wpdb` write whose first argument is a bare parameter,
    // checking whether ANY call site in this file passes a KG/ledger-tagged
    // argument into that parameter position. A hit is pushed into the SAME
    // `kgWrites`/`ledgerWrites` arrays Rule 2/3 already consume, so ownership,
    // exemption and counter handling below is not duplicated.
    //
    // File-scoped, like every other resolution in this gate (class constants,
    // table accessors): a call site is matched by RECEIVER + METHOD NAME
    // without disambiguating which class owns the method if two classes in
    // one file happen to share a name. That is the same tolerance the
    // existing accessor-call resolution already accepts.
    const maskedSource = maskStructural(source);
    const functionAt = (offset) => functions.find((fn) => offset >= fn.start && offset < fn.end);
    const wpdbCallPattern = /\$wpdb\s*->\s*(?:insert|update|replace|delete)\s*\(/gi;
    let wpdbMatch;
    while ((wpdbMatch = wpdbCallPattern.exec(maskedSource)) !== null) {
      const openIndex = wpdbMatch.index + wpdbMatch[0].length - 1;
      const closeIndex = matchParen(maskedSource, openIndex);
      if (closeIndex === -1) continue;

      const sinkFn = functionAt(openIndex);
      if (!sinkFn) continue;

      const args = splitTopLevel(
        maskedSource.slice(openIndex + 1, closeIndex),
        source.slice(openIndex + 1, closeIndex),
      );
      const target = args[0];
      if (!target || !/^\$[A-Za-z_][A-Za-z0-9_]*$/.test(target)) continue;
      if (!sinkFn.params.includes(target)) continue;
      // Already resolvable through this function's own kgVars/ledgerVars
      // (e.g. reassigned before use)? Rule 2/3's line-based pass already
      // found it — do not push a duplicate.
      if (sinkFn.kgVars.has(target) || sinkFn.ledgerVars.has(target)) continue;

      const paramIndex = sinkFn.params.indexOf(target);
      const writeLine = source.slice(0, openIndex).split(/\r?\n/).length;
      const escapedName = sinkFn.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const callPattern = new RegExp(
        `(?:self|static|\\$this|\\$[a-z_][a-z0-9_]*)\\s*(?:::|->)\\s*${escapedName}\\s*\\(`,
        'gi',
      );

      let callMatch;
      let sawKgArg = false;
      let sawLedgerArg = false;
      while ((callMatch = callPattern.exec(maskedSource)) !== null) {
        const callOpen = callMatch.index + callMatch[0].length - 1;
        if (callOpen === openIndex) continue;
        const callClose = matchParen(maskedSource, callOpen);
        if (callClose === -1) continue;

        const callArgs = splitTopLevel(
          maskedSource.slice(callOpen + 1, callClose),
          source.slice(callOpen + 1, callClose),
        );
        const argText = callArgs[paramIndex];
        if (!argText) continue;

        const expandedArg = expand(argText);
        const callerFn = functionAt(callMatch.index);
        const callerKgVars = callerFn ? callerFn.kgVars : new Set();
        const callerLedgerVars = callerFn ? callerFn.ledgerVars : new Set();

        if (kgLiteralPattern.test(expandedArg) || kgHelperPattern.test(expandedArg)
          || (kgAccessorPattern && kgAccessorPattern.test(expandedArg))
          || referencesVar(expandedArg, callerKgVars)) sawKgArg = true;
        if (contextBankLiteralPattern.test(expandedArg)
          || (ledgerAccessorPattern && ledgerAccessorPattern.test(expandedArg))
          || referencesVar(expandedArg, callerLedgerVars)) sawLedgerArg = true;
      }

      if (sawKgArg) {
        counters.kg_passthrough_resolved += 1;
        kgWrites.push({ line: writeLine, text: source.split(/\r?\n/)[writeLine - 1].trim() });
      }
      if (sawLedgerArg) {
        counters.ledger_passthrough_resolved += 1;
        ledgerWrites.push({ line: writeLine, text: source.split(/\r?\n/)[writeLine - 1].trim() });
      }
    }

    // ── Rule 2: ledger write outside the Context Bank owner ─────────────────
    if (ledgerWrites.length > 0) {
      counters.context_bank_writers += 1;
      if (contextBankOwner.test(relative)) {
        counters.context_bank_owner_writers += 1;
      } else if (!exempt) {
        for (const write of ledgerWrites) {
          findings.push({
            rule: 'R-CB.ledger_write_outside_owner',
            file: relative,
            line: write.line,
            owner: relative.split('/').slice(0, 2).join('/'),
            missing: ['context_bank_ledger_owner'],
            contract: 'PHASE-0-RULE-CONTEXT-BANK §one writer: BizCity_Context_Bank_Ledger::record()',
            fix_hint:
              'Admit the pointer through BizCity_Context_Bank_Ledger::record() instead of writing bizcity_context_bank directly; a second writer bypasses receipt validation and tenant checks.',
            fingerprint: `cb_ledger_write|${relative}|${write.line}`,
          });
        }
      }
    }

    // ── Rule 3: KG promotion outside KG-Hub ─────────────────────────────────
    if (kgWrites.length > 0) {
      counters.kg_writing_files += 1;
      if (kgOwner.test(relative)) {
        counters.kg_owner_writers += 1;
      } else if (exempt) {
        counters.kg_exempt_writers += 1;
      } else {
        counters.kg_bypass_writers += 1;
        for (const write of kgWrites) {
          findings.push({
            rule: 'R-KG.promotion_outside_owner',
            file: relative,
            line: write.line,
            owner: relative.split('/').slice(0, 2).join('/'),
            missing: ['kg_hub_promotion_owner'],
            contract: 'PHASE-0-RULE-KG-HUB-CONTRACT §canonical persistence + citation provenance',
            fix_hint:
              'Promote through the BizCity_KG facade / KG_Source_Service / KG_Graph_Service instead of writing bizcity_kg_* directly, so the passage keeps provenance that a citation can resolve back to a ledger pointer.',
            fingerprint: `kg_bypass_write|${relative}|${write.line}`,
          });
        }
      }
    }
  }
}

const baselineFingerprints = new Set(
  (fixtureRoot ? [] : (baseline.known_findings || [])).map((item) => item.fingerprint),
);
const newFindings = findings.filter((item) => !baselineFingerprints.has(item.fingerprint));
const staleBaseline = fixtureRoot
  ? []
  : (baseline.known_findings || []).filter(
    (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
  );

const byRule = findings.reduce((accumulator, finding) => {
  accumulator[finding.rule] = (accumulator[finding.rule] || 0) + 1;
  return accumulator;
}, {});

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relativeOf(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relativeOf(baselinePath),
  counters,
  // Proof of execution: a rule that inspected nothing is not a passing rule.
  ledger_coverage: counters.ledger_ddl_columns_checked === 0
    ? 'ZERO ledger columns inspected — payload rule unproven'
    : `${counters.ledger_ddl_columns_checked} columns across ${counters.ledger_ddl_tables_checked} ledger tables inspected`,
  kg_coverage: counters.kg_writing_files === 0
    ? 'ZERO KG writers scanned — ownership rule unproven'
    : `${counters.kg_owner_writers} owner + ${counters.kg_exempt_writers} exempt + ${counters.kg_bypass_writers} bypass of ${counters.kg_writing_files} KG-writing files`,
  findings_by_rule: byRule,
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: staleBaseline,
  findings,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0 && (strict || fixtureRoot)) process.exitCode = 1;
