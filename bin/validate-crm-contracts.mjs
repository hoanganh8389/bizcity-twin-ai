#!/usr/bin/env node
/**
 * WP5 — normalized CRM contract, canonical event and Zone 2 isolation gate.
 *
 * This is a structural gate for the runtime contracts already implemented in
 * the CRM owner. It does not execute WordPress or prove database/runtime
 * behavior; it prevents a future edit from silently removing the guards.
 *
 * Usage:
 *   node bin/validate-crm-contracts.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-crm-contracts.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { extractFunctions } from './lib/php-source.mjs';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((arg) => arg.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((arg) => arg.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'crm-contracts', 'baseline.json');
const baseline = !fixtureRoot && fs.existsSync(baselinePath)
  ? JSON.parse(fs.readFileSync(baselinePath, 'utf8'))
  : { known_findings: [] };

const files = fixtureRoot
  ? {
      contract: path.join(fixtureRoot, 'includes', 'inbox', 'class-channel-contract.php'),
      repository: path.join(fixtureRoot, 'includes', 'class-repository.php'),
      zone: path.join(fixtureRoot, 'includes', 'class-ai-autoreply-listener.php'),
      scope: path.join(fixtureRoot, 'includes', 'class-inbox-access.php'),
      archive: path.join(fixtureRoot, 'core', 'channel-gateway', 'includes', 'class-channel-conversation-archive.php'),
    }
  : {
      contract: path.join(root, 'plugins', 'bizcity-twin-crm', 'includes', 'inbox', 'class-channel-contract.php'),
      repository: path.join(root, 'plugins', 'bizcity-twin-crm', 'includes', 'class-repository.php'),
      zone: path.join(root, 'plugins', 'bizcity-twin-crm', 'includes', 'class-ai-autoreply-listener.php'),
      scope: path.join(root, 'plugins', 'bizcity-twin-crm', 'includes', 'class-inbox-access.php'),
      archive: path.join(root, 'core', 'channel-gateway', 'includes', 'class-channel-conversation-archive.php'),
    };

const findings = [];
const counters = {
  contract_files: 0,
  repository_files: 0,
  zone_files: 0,
  scope_files: 0,
  archive_files: 0,
  canonical_write_functions: 0,
  canonical_event_functions: 0,
};

function read(file) {
  if (!fs.existsSync(file)) return '';
  return fs.readFileSync(file, 'utf8');
}

function relative(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

function add(rule, file, line, missing, fixHint) {
  findings.push({
    rule,
    file: relative(file),
    line,
    missing,
    contract: 'R-CRM-FRAMEWORK + WP5 canonical CRM spine',
    fix_hint: fixHint,
    fingerprint: `${rule}|${relative(file)}|${line}`,
  });
}

function lineOf(source, offset) {
  return source.slice(0, offset).split(/\r?\n/).length;
}

const contract = read(files.contract);
if (contract) {
  counters.contract_files += 1;
  const normalizeStart = contract.indexOf('function normalize_inbound');
  const normalizeEnd = normalizeStart >= 0
    ? contract.indexOf('\n\t}', normalizeStart)
    : -1;
  const body = normalizeStart >= 0
    ? contract.slice(normalizeStart, normalizeEnd > normalizeStart ? normalizeEnd : undefined)
    : '';
  const required = ['inbox_ref', 'source_id', 'content', 'content_type', 'attachments', 'external_source_id', 'received_at'];
  const missing = required.filter((field) => !body.includes(`'${field}'`));
  if (missing.length > 0) {
    add(
      'R-CRM.normalized_contract_incomplete',
      files.contract,
      normalizeStart >= 0 ? lineOf(contract, normalizeStart) : 1,
      missing,
      'Keep every required normalized inbound field in normalize_inbound() before CRM repository writes.',
    );
  }
  if (!body.includes("'identity'")) {
    add(
      'R-CRM.identity_not_preserved',
      files.contract,
      normalizeStart >= 0 ? lineOf(contract, normalizeStart) : 1,
      ['identity'],
      'Return canonical inbox_ref/source_id/external_source_id identity with the normalized payload.',
    );
  }
} else {
  add('R-CRM.normalized_contract_missing', files.contract, 1, ['class-channel-contract.php'], 'Load the canonical CRM channel contract.');
}

const repository = read(files.repository);
if (repository) {
  counters.repository_files += 1;
  const functions = extractFunctions(repository);
  const canonicalNames = new Set([
    'upsert_contact_by_identity',
    'upsert_contact',
    'open_or_get_conversation',
    'insert_message',
  ]);
  for (const fn of functions) {
    if (!canonicalNames.has(fn.name)) continue;
    counters.canonical_write_functions += 1;
    const emits = /BizCity_CRM_Event_Emitter::emit\s*\(/.test(fn.body);
    if (emits) counters.canonical_event_functions += 1;
    else {
      add(
        'R-CRM.write_without_canonical_event',
        files.repository,
        lineOf(repository, fn.start),
        ['BizCity_CRM_Event_Emitter::emit'],
        'Emit one canonical CRM event after the repository mutation succeeds.',
      );
    }
  }

  const insert = functions.find((fn) => fn.name === 'insert_message');
  if (!insert) {
    add('R-CRM.message_writer_missing', files.repository, 1, ['insert_message'], 'Keep the canonical message repository writer.');
  } else {
    const guards = [
      ['BizCity_CRM_Channel_Contract::require_crm_enabled', 'channel contract gate'],
      ['external_source_id', 'external source identity'],
      ['LIMIT 1', 'duplicate lookup'],
    ];
    const missing = guards.filter(([marker]) => !insert.body.includes(marker)).map(([, label]) => label);
    if (missing.length > 0) {
      add(
        'R-CRM.message_write_guard_incomplete',
        files.repository,
        lineOf(repository, insert.start),
        missing,
        'Require channel authorization, external identity and duplicate lookup before message INSERT.',
      );
    }
  }
} else {
  add('R-CRM.repository_missing', files.repository, 1, ['class-repository.php'], 'Load the canonical CRM repository.');
}

const zone = read(files.zone);
if (zone) {
  counters.zone_files += 1;
  const hasDescriptor = /BizCity_CRM_Channel_Contract::describe\s*\(/.test(zone);
  const hasCustomerGuard = /ai_policy[^\n]*customer_autoreply|customer_autoreply[^\n]*ai_policy/.test(zone);
  if (!hasDescriptor || !hasCustomerGuard) {
    add(
      'R-CRM.zone2_ai_consumer_without_policy_guard',
      files.zone,
      1,
      [!hasDescriptor ? 'BizCity_CRM_Channel_Contract::describe' : null, !hasCustomerGuard ? 'ai_policy=customer_autoreply' : null].filter(Boolean),
      'Resolve the canonical channel descriptor and refuse Zone 2 CRM AI autoreply before model/send side effects.',
    );
  }
} else {
  add('R-CRM.zone_listener_missing', files.zone, 1, ['class-ai-autoreply-listener.php'], 'Load the CRM AI listener with the Zone 2 guard.');
}

const scope = read(files.scope);
if (scope) {
  counters.scope_files += 1;
  const requiredScopeMarkers = [
    ['list_personal_accounts_for_owner', 'exact personal-account owner lookup'],
    ["'owner_only'", 'Zalo Personal owner-only access mode'],
  ];
  const missing = requiredScopeMarkers.filter(([marker]) => !scope.includes(marker)).map(([, label]) => label);
  if (missing.length > 0) {
    add(
      'R-CRM.user_scope_incomplete',
      files.scope,
      1,
      missing,
      'Keep exact-owner Zalo Personal filtering and user-centric member conversation predicates in the canonical Inbox scope owner.',
    );
  }
} else {
  add('R-CRM.user_scope_missing', files.scope, 1, ['class-inbox-access.php'], 'Load the canonical user-centric Inbox scope owner.');
}

if (repository) {
  const memberScopeMarkers = [
    ['list_conversations_for_member', 'member user_id conversation scope'],
    ['contact_wp_user_id', 'contact/user ownership predicate'],
  ];
  const missing = memberScopeMarkers.filter(([marker]) => !repository.includes(marker)).map(([, label]) => label);
  if (missing.length > 0) {
    add(
      'R-CRM.user_scope_incomplete',
      files.repository,
      1,
      missing,
      'Keep member conversation reads bound to the resolved user_id and contact ownership predicate.',
    );
  }
}

const archive = read(files.archive);
if (archive) {
  counters.archive_files += 1;
  const requiredArchiveMarkers = [
    ['crm_message_id', 'CRM message correlation'],
    ['event_uuid', 'event stream correlation'],
    ['mark_message_archived', 'CRM archive-state acknowledgement'],
    ['BizCity_Channel_File_Logger', 'canonical channel operational log'],
  ];
  const missing = requiredArchiveMarkers.filter(([marker]) => !archive.includes(marker)).map(([, label]) => label);
  if (missing.length > 0) {
    add(
      'R-CRM.archive_correlation_incomplete',
      files.archive,
      1,
      missing,
      'Preserve crm_message_id + event_uuid through the encrypted archive receipt and canonical channel log before marking CRM content archived.',
    );
  }
} else {
  add('R-CRM.archive_owner_missing', files.archive, 1, ['class-channel-conversation-archive.php'], 'Load the canonical channel conversation archive owner.');
}

const baselineFingerprints = new Set(
  (fixtureRoot ? [] : (baseline.known_findings || [])).map((item) => item.fingerprint),
);
const newFindings = findings.filter((finding) => !baselineFingerprints.has(finding.fingerprint));
const staleBaseline = fixtureRoot
  ? []
  : (baseline.known_findings || []).filter(
    (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
  );

const findingsByRule = findings.reduce((out, finding) => {
  out[finding.rule] = (out[finding.rule] || 0) + 1;
  return out;
}, {});

const report = {
  generated_at: new Date().toISOString(),
  scan_root: fixtureRoot ? relative(fixtureRoot) : '(production)',
  mode: strict ? 'strict' : 'report',
  baseline: fixtureRoot ? '(not applied)' : relative(baselinePath),
  counters,
  findings_by_rule: findingsByRule,
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: staleBaseline,
  status: newFindings.length > 0 || staleBaseline.length > 0 ? 'FAIL' : 'PASS',
};
process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (report.status === 'FAIL' && (strict || fixtureRoot)) process.exitCode = 1;
