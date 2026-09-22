#!/usr/bin/env node
/**
 * WP4 — File-first operational evidence gate (R-CH-FILE-LOG).
 *
 * The canonical rule (`core/channel-gateway/docs/RULE-CHANNEL-FILE-LOG.md`, and
 * the header of `core/channel-gateway/includes/class-channel-file-logger.php`)
 * states that channel operational evidence is **file-only and never depends on
 * the database**. The point of that rule is ordering: if the DB write is what
 * produces the evidence, then a failed/rolled-back DB write leaves no trace of
 * the message ever arriving, which is exactly the incident class the JSONL log
 * exists to survive.
 *
 * `BizCity_Channel_File_Logger::write_record()` already encodes the intent at
 * runtime — it stamps `pipeline_status.operational_logged = 'success'` only
 * after the append succeeds. This gate is the static half: inside a channel
 * path, the operational append must appear **before** the first database write
 * in the same function.
 *
 * Two rules:
 *
 *  1. `db_write_without_file_evidence` — a channel-context function writes the
 *     database with no operational append anywhere in that function. There is no
 *     evidence at all if the write fails.
 *  2. `db_write_before_file_evidence` — the function logs, but only *after* its
 *     first database write. The evidence is conditional on the DB call
 *     succeeding, which is the ordering R-CH-FILE-LOG forbids.
 *
 * **Local wrapper resolution.** Real channel code rarely calls the logger
 * inline; it calls a private helper (`self::log()`, `$this->log_event()`) that
 * wraps it. A rule that only accepted the canonical class name would report
 * false positives against correct code — the defect class this gate family has
 * already hit three times (interface naming styles in WP3, the `add_action`
 * callback regex and the positive-guard direction in WP4). So a same-file method
 * whose own body calls the canonical logger is resolved as a logger call, and
 * the count of resolved wrappers is reported as proof the resolution ran.
 *
 * This is static source analysis. It does NOT execute a webhook, does not prove
 * the append actually reached disk, does not prove the logged record is
 * semantically correct or complete, and does not follow a logger call across
 * file or class boundaries.
 *
 * Usage:
 *   node bin/validate-channel-file-first-logging.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-channel-file-first-logging.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { extractFunctions, inNested } from './lib/php-source.mjs';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'channel-file-first-logging', 'baseline.json');
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

/**
 * Channel-context owners. R-CH-FILE-LOG is a channel rule, so the gate applies
 * to the packages that own channel intake, normalization and dispatch. A file
 * outside these owners enters scope only by referencing a channel hook (see
 * `channelHookPattern`), which is how a channel path in another module is
 * caught without scanning unrelated CRUD.
 */
const channelOwnerPatterns = [
  /(?:^|\/)core\/channel-gateway\//,
  /(?:^|\/)plugins\/bizcity-zalo-personal\//,
  /(?:^|\/)plugins\/bizcity-zalo-bizcity\//,
  /(?:^|\/)plugins\/bizcity-zalo-bot\//,
  /(?:^|\/)plugins\/bizcity-facebook-bot\//,
  /(?:^|\/)modules\/webchat\//,
];

/** A file anywhere that participates in the channel hook surface. */
const channelHookPattern =
  /(?:do_action|add_action)\s*\(\s*['"](?:bizcity_channel_[a-z_]+|bizcity_zalo_[a-z_]*message_received|bizcity_facebook_[a-z_]+_received|bizcity_telegram_message_received|bizcity_webchat_push_message)['"]/;

/**
 * The canonical operational append. `BizCity_Channel_File_Logger` is the channel
 * facade; `BizCity_JSONL_File_Logger` is the canonical contract writer it
 * delegates to (verified at class-channel-file-logger.php:162-164). Both are
 * file-first by construction, so either satisfies the rule.
 */
const canonicalLoggerPattern =
  /(?:BizCity_Channel_File_Logger|BizCity_JSONL_File_Logger)\s*::\s*(?:write_record|write_contract_record|write|error)\s*\(/;

/** Database writes that must be preceded by operational evidence. */
const dbWritePattern = /\$wpdb\s*->\s*(?:insert|update|replace|delete)\s*\(/;

/**
 * Message-bearing tables: the rows that ARE the record of a customer message.
 * These are the writes R-CH-FILE-LOG is about — if one fails without a prior
 * append, the message is gone with no trace it ever arrived.
 *
 * Scoping the rule to the WRITE TARGET rather than the FILE is deliberate and
 * was forced by report-mode ground truth. A file-scope rule reported `137`
 * findings, but reading them showed the majority were configuration/admin CRUD
 * that merely lived in a channel file: `delete_bot` writes
 * `bizcity_facebook_bots`, `create_project` writes `bizcity_webchat_projects`,
 * and `plugins/bizcity-twin-crm/includes/class-rest-controller.php` contributed
 * `50` findings (`post_crm_product_category`, `delete_crm_lead`, ...) purely
 * because one outbound tap at line 6492 put the whole 12k-line file in scope.
 * Requiring channel operational evidence there would be a false positive — the
 * same coarse-file-scope defect WP5 already hit and fixed with variable
 * tracking.
 *
 * The `\b` terminator is load-bearing: `_` is a word character, so
 * `bizcity_crm_message_templates` correctly does NOT match `message\b`.
 */
const messageTablePattern = /bizcity_[a-z0-9_]*(?:messages?|conversations?|inbox|comments?)\b/i;

/** CRM installer helpers that resolve to a message-bearing table. */
const messageTableHelperPattern = /::\s*tbl_(?:messages|conversations|inboxes)\s*\(/;

/**
 * Diagnostics probes create and clean up their own synthetic rows by design and
 * deliberately fire negative-case payloads; requiring production operational
 * evidence there is wrong. Same exemption, same reason, as the WP4 identity rule
 * and the WP5 ownership gate.
 */
const exemptions = [
  {
    pattern: /(?:^|\/)core\/diagnostics\/includes\/probes\//,
    reason: 'Diagnostics probe writes synthetic rows it cleans up itself; not a production channel path.',
  },
  {
    pattern: /(?:^|\/)core\/channel-gateway\/includes\/class-channel-file-logger\.php$/,
    reason: 'The file logger IS the operational evidence owner; it cannot log before itself.',
  },
  {
    pattern: /(?:^|\/)core\/channel-gateway\/includes\/(?:class-webhook-replay|class-channel-conversation-archive)\.php$/,
    reason: 'Replay/archive continuity paths re-persist an already-logged event; the original append happened at intake.',
  },
  {
    pattern: /(?:^|\/)core\/channel-gateway\/includes\/class-sprint-diagnostic\.php$/,
    reason: 'Self-test harness; its writes are smoke rows it creates and deletes itself (check_t_s7_7, check_t_s7_12).',
  },
];

function walk(directory, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (excludedSegments.has(entry.name)) continue;
      walk(target, files);
    } else if (entry.isFile() && target.toLowerCase().endsWith('.php')) {
      files.push(target);
    }
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

function lineOf(source, offset) {
  return source.slice(0, offset).split(/\r?\n/).length;
}

// PHP structural parsing lives in bin/lib/php-source.mjs so every gate that
// reasons about function boundaries shares one implementation of the traps
// documented there (string braces, embedded JS, <?xml literals).

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const findings = [];
const fileFirstExamples = [];
const counters = {
  files_scanned: 0,
  channel_context_files: 0,
  exempt_files: 0,
  functions_parsed: 0,
  db_writing_functions: 0,
  file_first_ok: 0,
  missing_evidence: 0,
  order_violation: 0,
  local_wrappers_resolved: 0,
  table_accessors_resolved: 0,
  canonical_logger_calls: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    counters.files_scanned += 1;
    const relative = relativeOf(file);
    const source = fs.readFileSync(file, 'utf8');

    if (!dbWritePattern.test(source)) continue;

    const inChannelOwner = channelOwnerPatterns.some((pattern) => pattern.test(relative));
    const touchesChannelHook = channelHookPattern.test(source);
    if (!inChannelOwner && !touchesChannelHook) continue;

    const exempt = exemptions.find((entry) => entry.pattern.test(relative));
    if (exempt) {
      counters.exempt_files += 1;
      continue;
    }

    counters.channel_context_files += 1;
    const functions = extractFunctions(source);
    counters.functions_parsed += functions.length;

    // ── Local wrapper resolution ────────────────────────────────────────────
    // A method whose own body performs the canonical append is itself a valid
    // operational-evidence call for the rest of this file.
    const wrappers = new Set();
    for (const fn of functions) {
      if (canonicalLoggerPattern.test(fn.body)) wrappers.add(fn.name);
    }
    if (wrappers.size > 0) counters.local_wrappers_resolved += wrappers.size;

    const wrapperPattern = wrappers.size > 0
      ? new RegExp(`(?:self|static|\\$this)\\s*(?:::|->)\\s*(?:${[...wrappers].join('|')})\\s*\\(`)
      : null;

    // ── Message-table resolution ────────────────────────────────────────────
    // Real code rarely writes a literal table name. Three shapes exist and all
    // three must resolve or the rule silently misses the true positives:
    //   shape 1: `$wpdb->insert( $wpdb->prefix . 'bizcity_webchat_messages', ... )`
    //   shape 2: `$table = $wpdb->prefix . 'bizcity_facebook_inbox'; ... insert( $table )`
    //   shape 3: `$wpdb->insert( self::table(), $row )` where the same class
    //            defines `table()` returning `... 'bizcity_channel_messages'`
    //            (core/channel-gateway/includes/class-channel-messages.php:67)
    const tableAccessors = new Set();
    for (const fn of functions) {
      if (new RegExp(`return\\s+[^;]*${messageTablePattern.source}[^;]*;`, 'i').test(fn.body)) {
        tableAccessors.add(fn.name);
      }
    }
    const accessorPattern = tableAccessors.size > 0
      ? new RegExp(`(?:self|static|\\$this)\\s*(?:::|->)\\s*(?:${[...tableAccessors].join('|')})\\s*\\(`)
      : null;
    if (tableAccessors.size > 0) counters.table_accessors_resolved += tableAccessors.size;

    const resolvesToMessageTable = (text) => messageTablePattern.test(text)
      || messageTableHelperPattern.test(text)
      || (accessorPattern ? accessorPattern.test(text) : false);

    /**
     * Variables assigned a message-bearing table, tracked at FUNCTION scope.
     *
     * File scope was tried first and was wrong. A large controller reuses one
     * variable name for dozens of unrelated tables: in
     * `plugins/bizcity-twin-crm/includes/class-rest-controller.php`, `$tbl` is
     * assigned a message table in `broadcasts_*` and a product/task/lead table
     * in ~40 other methods. File-wide tracking let the single message-table
     * assignment mark EVERY `$tbl` write in the file, reporting
     * `put_crm_product_category` and `delete_crm_task` as channel-evidence
     * violations. That is the over-broad variable-tracking defect WP5 hit and
     * fixed; findings fell from `60` to the real set once tracking was scoped
     * to the assignment actually visible in the same function.
     *
     * Deliberately conservative: a table handed in as a parameter or held on a
     * class property does not resolve, so such a write is NOT flagged. This
     * gate under-reports rather than inventing findings against correct code.
     */
    const messageVarsIn = (body) => {
      const found = new Set();
      for (const line of body.split(/\r?\n/)) {
        const assign = /^\s*(\$[a-z_][a-z0-9_]*)\s*=\s*([^;]*);/i.exec(line);
        if (assign && resolvesToMessageTable(assign[2])) found.add(assign[1]);
      }
      return found;
    };

    for (const fn of functions) {
      const messageVars = messageVarsIn(fn.body);
      // Find the first write in this function that targets a message table.
      const writePattern = new RegExp(dbWritePattern.source, 'g');
      let writeMatch = null;
      let candidate;
      while ((candidate = writePattern.exec(fn.body)) !== null) {
        if (inNested(fn, candidate.index)) continue;
        // The target is the first argument, which may sit on the next line for
        // a multi-line call, so inspect a bounded window after the call opens.
        const window = fn.body.slice(candidate.index, candidate.index + 240);
        const targetsMessageTable = resolvesToMessageTable(window)
          || [...messageVars].some((name) => new RegExp(`${name.replace('$', '\\$')}\\s*[,)]`).test(window));
        if (targetsMessageTable) { writeMatch = candidate; break; }
      }
      if (!writeMatch) continue;
      counters.db_writing_functions += 1;

      // Earliest operational append in this function, canonical or wrapper.
      // A call inside a nested closure is not this function's evidence.
      const firstOutside = (pattern) => {
        if (!pattern) return null;
        const scan = new RegExp(pattern.source, 'g');
        let hit;
        while ((hit = scan.exec(fn.body)) !== null) {
          if (!inNested(fn, hit.index)) return hit.index;
        }
        return null;
      };
      const canonicalOffset = firstOutside(canonicalLoggerPattern);
      if (canonicalOffset !== null) counters.canonical_logger_calls += 1;
      const wrapperOffset = firstOutside(wrapperPattern);
      const loggerOffsets = [canonicalOffset, wrapperOffset].filter((item) => item !== null);
      const writeLine = lineOf(source, fn.start + writeMatch.index);
      const owner = relative.split('/').slice(0, 2).join('/');

      if (loggerOffsets.length === 0) {
        counters.missing_evidence += 1;
        findings.push({
          rule: 'R-CH-FILE-LOG.db_write_without_file_evidence',
          file: relative,
          line: writeLine,
          function: fn.name,
          owner,
          missing: ['operational_file_append'],
          contract: 'R-CH-FILE-LOG §file-only operational evidence + channel-diagnostics-record@1.x',
          fix_hint:
            `${fn.name}() writes the database with no operational append; call BizCity_Channel_File_Logger::write_record() before the write so a failed/rolled-back write still leaves evidence.`,
          fingerprint: `filelog_missing|${relative}|${fn.name}`,
        });
        continue;
      }

      const firstLogger = Math.min(...loggerOffsets);
      if (firstLogger < writeMatch.index) {
        counters.file_first_ok += 1;
        // Name the compliant paths. A bare count cannot distinguish "the rule
        // found a compliant function" from "the rule mis-parsed one", and the
        // resolution path (canonical vs wrapper) is what proves wrapper
        // resolution actually ran rather than being dead code.
        fileFirstExamples.push({
          file: relative,
          function: fn.name,
          via: canonicalOffset !== null && canonicalOffset === firstLogger ? 'canonical' : 'local_wrapper',
          line: writeLine,
        });
        continue;
      }

      counters.order_violation += 1;
      findings.push({
        rule: 'R-CH-FILE-LOG.db_write_before_file_evidence',
        file: relative,
        line: writeLine,
        function: fn.name,
        owner,
        missing: ['file_first_ordering'],
        contract: 'R-CH-FILE-LOG §file-only operational evidence + channel-diagnostics-record@1.x',
        fix_hint:
          `${fn.name}() appends operational evidence at line ${lineOf(source, fn.start + firstLogger)}, after its database write at line ${writeLine}; move the append before the write so the evidence is not conditional on the DB call succeeding.`,
        fingerprint: `filelog_order|${relative}|${fn.name}`,
      });
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
  // Proof of execution: a rule that finds nothing is indistinguishable from a
  // rule that never ran, so report the population the rule actually inspected.
  coverage: counters.db_writing_functions === 0
    ? 'ZERO db-writing channel functions scanned — rule unproven'
    : `${counters.file_first_ok} file-first of ${counters.db_writing_functions} db-writing functions across ${counters.channel_context_files} channel-context files`,
  file_first_examples: fileFirstExamples.slice(0, 10),
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
