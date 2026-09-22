#!/usr/bin/env node
/**
 * WP5 — CRM repository and event ownership gate.
 *
 * The CRM spine claim is: a Zone 1 customer-care channel must be registered
 * through the CRM channel registry, must pass the CRM channel contract before
 * any write, and must not be written by anything outside the canonical CRM
 * owner. This gate reconciles three static facts:
 *
 *  1. **Owner boundary.** A direct write to a CRM business table
 *     (`bizcity_crm_messages|conversations|contacts|events`) must originate from
 *     the CRM owner (`plugins/bizcity-twin-crm/`) or from a diagnostics probe
 *     fixture. Anywhere else is a repository bypass.
 *
 *  2. **Contract-before-write.** A file that writes a CRM business table must
 *     also reference the CRM channel contract or its registry, unless it is the
 *     repository itself (which IS the owner of the write path).
 *
 *  3. **Adapter/registry parity.** Every `BizCity_CRM_Channel_Adapter`
 *     implementation must declare a `code()` that is registered through
 *     `bizcity_crm_register_adapters`, so the registry cannot silently drop a
 *     channel and relabel it.
 *
 * This is static source analysis. It does not execute WordPress, does not prove
 * a write happened at runtime, does not prove tenant scoping, and does not
 * verify the repository's internal correctness.
 *
 * Usage:
 *   node bin/validate-crm-ownership.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-crm-ownership.mjs --fixture-root=<path>
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
  : path.join(root, 'tests', 'fixtures', 'crm-ownership', 'baseline.json');
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

/** The canonical CRM owner. Writes under this prefix are the owner itself. */
const crmOwnerPrefix = /(?:^|\/)plugins\/bizcity-twin-crm\//;

/**
 * Diagnostics disposable fixtures create and clean up their own rows by design.
 *
 * Two sibling directories, deliberately NOT the whole `core/diagnostics/includes/`
 * tree. Widening to the parent would exempt the real diagnostics services that
 * live there (`class-diagnostics-auto-create.php` and friends), which are
 * production code and must not be allowed to write CRM tables directly.
 */
const probePrefix = /(?:^|\/)core\/diagnostics\/includes\/probes\//;
const diagnosticsFixturePrefix = /(?:^|\/)core\/diagnostics\/includes\/fixtures\//;

/** Files that ARE the repository/write-path owner inside the CRM plugin. */
const crmRepositoryFiles = [
  /plugins\/bizcity-twin-crm\/includes\/class-repository\.php$/,
  /plugins\/bizcity-twin-crm\/includes\/class-event-emitter\.php$/,
  /plugins\/bizcity-twin-crm\/includes\/inbox\//,
  /plugins\/bizcity-twin-crm\/includes\/woo\/migrations\//,
  /plugins\/bizcity-twin-crm\/includes\/campaigns\//,
  /plugins\/bizcity-twin-crm\/includes\/reports\//,
  /plugins\/bizcity-twin-crm\/includes\/invoicing\//,
  /plugins\/bizcity-twin-crm\/includes\/print-ads\//,
  /plugins\/bizcity-twin-crm\/includes\/lead-capture\//,
  /plugins\/bizcity-twin-crm\/includes\/bridge\//,
  /plugins\/bizcity-twin-crm\/includes\/woo\//,
];

/**
 * The legacy sprint diagnostic is a registered disposable harness, not a
 * production repository caller. It creates temporary conversation/message
 * rows and deletes both rows before returning. Keep this allowlist exact: a
 * broad `class-*diagnostic*` exemption would hide business writers.
 */
const diagnosticsHarnessFiles = [
  /plugins\/bizcity-twin-crm\/includes\/class-sprint-diagnostic\.php$/,
];
const diagnosticsHarnessTeardownPattern = /\$wpdb\s*->\s*delete\s*\(/;

const crmTablePattern = /(?:bizcity_crm_(?:messages|conversations|contacts|events)|tbl_(?:messages|conversations|contacts|events|inboxes|crm_invoices|crm_invoice_lines|crm_invoice_payments)\s*\()/i;
const writePattern = /\$wpdb\s*->\s*(?:insert|update|delete)\s*\(/i;
const contractPattern = /BizCity_CRM_Channel_Contract|BizCity_CRM_Channel_Registry|bizcity_crm_register_adapters/;

/**
 * A diagnostics fixture is only exempt when it can PROVE teardown.
 *
 * Path alone is not evidence: putting a file in `.../fixtures/` would otherwise
 * be a back door around the owner rule. The exemption requires a teardown entry
 * point, and the matching probe-exemption fixture is asserted in CI so this
 * cannot silently stop matching.
 */
const teardownPattern = /function\s+destroy\s*\(/;
/** Guards against tearing down rows the fixture did not create. */
const markerScopePattern = /is_marker_value\s*\(|marker_ref\s*\(|MARKER/;

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

/** 1-based line number for a byte offset in the original source. */
function lineOf(source, offset) {
  let line = 1;
  for (let index = 0; index < offset && index < source.length; index += 1) {
    if (source[index] === '\n') line += 1;
  }
  return line;
}

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const findings = [];
const counters = {
  files_scanned: 0,
  crm_writing_files: 0,
  crm_owner_writers: 0,
  probe_fixture_writers: 0,
  unproven_fixture_writers: 0,
  repository_layer_writers: 0,
  diagnostics_harness_writers: 0,
  contract_aware_writers: 0,
  adapter_implementations: 0,
  adapters_registered: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    const relative = relativeOf(file);
    const source = fs.readFileSync(file, 'utf8');
    counters.files_scanned += 1;

    const lines = source.split(/\r?\n/);
    // Declared at file scope, not inside `if (crmWrites.length > 0)`: Rule D
    // evaluates the repository-layer question per file, including files that
    // write a customer-facing table without tripping the owner/consumer rules.
    const isRepository = crmRepositoryFiles.some((pattern) => pattern.test(relative));

    // ── Rule A: direct CRM table write outside the owner ────────────────────
    // Two real shapes exist and both must be resolved by variable tracking,
    // because a file-scope fallback ("this file mentions a CRM table AND has a
    // $wpdb write") produced 15 false positives in the first draft: it matched
    // files that only name `bizcity_crm_events` in a whitelist while writing an
    // unrelated table, e.g.
    // `core/automation/includes/blocks/actions/class-action-db-write.php`
    // writes `$wpdb->prefix . $table_suffix`, not a CRM table.
    //
    //   shape 1: `$wpdb->insert( $wpdb->prefix . 'bizcity_crm_messages', ... )`
    //   shape 2: `$tbl = BizCity_CRM_DB_Installer::tbl_contacts(); ... $wpdb->update( $tbl, ... )`
    const crmVarPattern = /(BizCity_CRM_DB_Installer(?:_V2)?::tbl_[a-z_]+|'bizcity_crm_[a-z_]+'|"bizcity_crm_[a-z_]+")/;
    // NOTE: an earlier revision also tracked ANY `::tbl_xxx()` assignment here.
    // That matched KG's own `tbl_passages`/`tbl_sources` helpers and produced 54
    // false positives across `core/knowledge/kg-hub/`. Only a CRM installer
    // helper or a literal `bizcity_crm_*` name may mark a variable as a CRM
    // write target.
    const crmVars = new Set();
    const crmWrites = [];
    lines.forEach((line, index) => {
      const assign = /^\s*(\$[a-z_][a-z0-9_]*)\s*=\s*([^;]*);/i.exec(line);
      if (assign && crmVarPattern.test(assign[2])) crmVars.add(assign[1]);

      if (!writePattern.test(line)) return;
      const inlineCrm = crmVarPattern.test(line);
      const viaTrackedVar = [...crmVars].some((name) => new RegExp(`${name.replace('$', '\\$')}\\s*[,)]`).test(line));
      if (inlineCrm || viaTrackedVar) {
        crmWrites.push({ line: index + 1, text: line.trim() });
      }
    });

    if (crmWrites.length > 0) {
      counters.crm_writing_files += 1;
      const isOwner = crmOwnerPrefix.test(relative);
      const isFixturePath =
        probePrefix.test(relative) || diagnosticsFixturePrefix.test(relative);
      // Path alone is not enough for the fixture directory; a shared factory
      // must also show a teardown entry point AND marker-scoped deletion, or it
      // is an unowned writer wearing a fixture name.
      const isDiagnosticsFixture = probePrefix.test(relative)
        || (diagnosticsFixturePrefix.test(relative)
          && teardownPattern.test(source)
          && markerScopePattern.test(source));
      const isProbe = isDiagnosticsFixture;

      if (isOwner) counters.crm_owner_writers += 1;
      if (isProbe) counters.probe_fixture_writers += 1;
      if (isFixturePath && !isDiagnosticsFixture) counters.unproven_fixture_writers += 1;

      if (!isOwner && !isProbe) {
        for (const write of crmWrites) {
          findings.push({
            rule: 'R-CRM.direct-sql-outside-owner',
            file: relative,
            line: write.line,
            owner: relative.split('/').slice(0, 2).join('/'),
            missing: ['crm_repository_owner'],
            contract: 'R-B2B2C + R-CRM-FRAMEWORK §CRM canonical owner',
            fix_hint:
              'Write CRM business tables through plugins/bizcity-twin-crm repositories + BizCity_CRM_Event_Emitter instead of direct $wpdb writes.',
            fingerprint: `crm_direct_write|${relative}|${write.line}`,
          });
        }
      }

      // ── Rule B: contract awareness on a CRM write path ────────────────────
      // Owner repository files ARE the write path; contract awareness is only
      // required from a consumer that writes CRM tables from outside.
      if (!isOwner && !isProbe && !contractPattern.test(source)) {
        findings.push({
          rule: 'R-CRM.write_without_channel_contract',
          file: relative,
          line: crmWrites[0].line,
          owner: relative.split('/').slice(0, 2).join('/'),
          missing: ['crm_channel_contract'],
          contract: 'R-CRM-FRAMEWORK §canonical channel inbox contract',
          fix_hint:
            'Reference BizCity_CRM_Channel_Contract / BizCity_CRM_Channel_Registry before writing CRM tables so inbox_ref, source identity and dedupe are validated.',
          fingerprint: `crm_no_contract|${relative}`,
        });
      }

      if (contractPattern.test(source)) counters.contract_aware_writers += 1;
    }

    // ─ Rule D: CRM mutations route through the repository ───────────────────
    // Closes the checklist item "Route contact/conversation/message mutations
    // through repositories": inside the CRM owner, only the declared repository
    // layer may write the three customer-facing tables. Any other owner file
    // (a REST controller, a page controller, an admin screen) writing
    // messages/conversations/contacts directly is a repository bypass, even
    // though `R-CRM.direct-sql-outside-owner` cannot see it — that rule only
    // looks outside the owner, so the owner's own internals were unsupervised.
    //
    // Scoped to the three customer-facing tables on purpose. Events, invoices,
    // print-ads and lead-capture have their own owners and would otherwise
    // require a much larger reviewed set; widening this rule is a separate,
    // owner-approved decision, not an incidental side effect.
    //
    // Variable tracking is FUNCTION-scoped, not file-scoped. The first draft
    // tracked `$tbl` file-wide and reported 37 findings in two files; reading
    // them showed the cause immediately — `class-rest-controller.php` is 10k
    // lines and reuses `$tbl` for broadcasts, leads, documents, contracts and
    // categories, so one `tbl_contacts()` assignment marked every later `$tbl`
    // write in the file. This is the identical defect WP4 and WP5 each hit.
    if (crmOwnerPrefix.test(relative)) {
      const isDiagnosticsHarness = diagnosticsHarnessFiles.some((pattern) => pattern.test(relative))
        && diagnosticsHarnessTeardownPattern.test(source);
      const customerFacing = /\btbl_(?:messages|conversations|contacts)\s*\(|'bizcity_crm_(?:messages|conversations|contacts)'|"bizcity_crm_(?:messages|conversations|contacts)"/;
      const customerVarsIn = (body) => {
        const found = new Set();
        for (const line of body.split(/\r?\n/)) {
          const assign = /^\s*(\$[a-z_][a-z0-9_]*)\s*=\s*([^;]*);/i.exec(line);
          if (assign && customerFacing.test(assign[2])) found.add(assign[1]);
        }
        return found;
      };

      const bypassWrites = [];
      for (const fn of extractFunctions(source)) {
        const customerVars = customerVarsIn(fn.body);
        const scan = new RegExp(writePattern.source, 'g');
        let hit;
        while ((hit = scan.exec(fn.body)) !== null) {
          if (inNested(fn, hit.index)) continue;
          // The target is the first argument and may sit on the next line for a
          // multi-line call, so inspect a bounded window after the call opens.
          const window = fn.body.slice(hit.index, hit.index + 240);
          const targetsCustomerTable = customerFacing.test(window)
            || [...customerVars].some(
              (name) => new RegExp(`${name.replace('$', '\\$')}\\s*[,)]`).test(window),
            );
          if (targetsCustomerTable) {
            bypassWrites.push({ line: lineOf(source, fn.start + hit.index) });
          }
        }
      }

      if (bypassWrites.length > 0) {
        if (isDiagnosticsHarness) {
          counters.diagnostics_harness_writers += 1;
        } else if (isRepository) {
          counters.repository_layer_writers += 1;
        } else {
          for (const write of bypassWrites) {
            findings.push({
              rule: 'R-CRM.mutation_outside_repository',
              file: relative,
              line: write.line,
              owner: relative.split('/').slice(0, 2).join('/'),
              missing: ['crm_repository_layer'],
              contract: 'R-CRM-FRAMEWORK §CRM repository mutation path',
              fix_hint:
                'Route message/conversation/contact mutations through plugins/bizcity-twin-crm/includes/class-repository.php instead of writing the table from a controller or screen.',
              fingerprint: `crm_repo_bypass|${relative}|${write.line}`,
            });
          }
        }
      }
    }

    // ─ Rule C: CRM channel adapter must be registered ──────────────────────
    if (/implements\s+[^,{]*BizCity_CRM_Channel_Adapter/.test(source)) {
      counters.adapter_implementations += 1;
      const codeMatch = /function\s+code\s*\(\s*\)\s*:\s*string\s*\{\s*return\s*'([a-z0-9_]+)'/.exec(source);
      if (!codeMatch) {
        findings.push({
          rule: 'R-CRM.adapter_code_not_literal',
          file: relative,
          line: 1,
          owner: relative.split('/').slice(0, 2).join('/'),
          missing: ['literal_code'],
          contract: 'R-CRM-FRAMEWORK §adapter/registry parity',
          fix_hint:
            'Adapter code() must return a literal channel code so registry parity can be verified statically.',
          fingerprint: `crm_adapter_nocode|${relative}`,
        });
        continue;
      }
      const code = codeMatch[1];
      if (new RegExp(`['"]${code}['"]`).test(source) && /bizcity_crm_register_adapters/.test(source)) {
        counters.adapters_registered += 1;
      } else {
        findings.push({
          rule: 'R-CRM.adapter_not_registered',
          file: relative,
          line: 1,
          owner: relative.split('/').slice(0, 2).join('/'),
          missing: ['register_adapters_binding'],
          contract: 'R-CRM-FRAMEWORK §adapter/registry parity',
          fix_hint:
            `Adapter declares code '${code}' but does not register it through bizcity_crm_register_adapters; the registry would silently drop this channel.`,
          fingerprint: `crm_adapter_unregistered|${relative}|${code}`,
        });
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
  adapter_registration_coverage: counters.adapter_implementations === 0
    ? 'ZERO adapters scanned — rule unproven'
    : `${counters.adapters_registered}/${counters.adapter_implementations} adapters declared + registered`,
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