#!/usr/bin/env node
/**
 * WP4 — Canonical sender ownership and duplicate-send prevention.
 *
 * Closes the last WP4 checklist item: "Prove canonical sender ownership and
 * duplicate-send prevention."
 *
 * Two static facts are reconciled:
 *
 *  1. **Sender ownership.** An outbound customer message must leave through the
 *     canonical sender (`BizCity_Gateway_Sender`). A direct provider POST
 *     (`graph.facebook.com`, `api.zalo.me`, `openapi.zalo.me`,
 *     `api.telegram.org`) from outside an approved transport owner bypasses
 *     trace context, outbound logging and the `bizcity_channel_after_send`
 *     contract.
 *
 *  2. **Duplicate-send prevention.** A caller of the canonical sender must pass
 *     an `idempotency_key`, because the sender forwards it to the provider and
 *     stamps it on the outbound evidence record. Without it a retry or a
 *     double-fired hook produces a duplicate customer-visible message.
 *
 * This is static source analysis. It does not execute a send, does not prove the
 * provider honoured the idempotency key, and does not prove a message was
 * delivered exactly once.
 *
 * Usage:
 *   node bin/validate-sender-ownership.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-sender-ownership.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { maskComments } from './lib/php-source.mjs';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'sender-ownership', 'baseline.json');
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
 * Provider endpoints that actually deliver a customer-visible MESSAGE.
 *
 * The first draft matched ANY provider endpoint and produced four false
 * positives, all verified by reading the source:
 *   - `core/channel-gateway/integrations/zalo.php:119` posts to
 *     `oauth.zaloapp.com/v4/oa/access_token` — an OAuth token exchange.
 *   - `core/channel-gateway/includes/class-fb-chat-widget.php:176` posts to
 *     `me/messenger_profile` — chat-widget configuration.
 *   - `core/channel-gateway/includes/class-fb-publisher.php:333` posts to
 *     `/{page_id}/feed|photos` — a page PUBLISH, not a reply.
 *   - `core/channel-gateway/legacy/legacy-messenger-compat.php:220` posts to
 *     `me/take_thread_control` — thread ownership, not a message.
 *
 * Only message-delivery endpoints are in scope, because only those can produce
 * the duplicate customer-visible message this rule exists to prevent.
 */
const messageSendEndpointPattern = /(?:\/me\/messages|v3\/message\/cs|v3\.0\/oa\/message\/cs|\/sendMessage|\/sendPhoto|\/sendDocument)/;

/** Any provider endpoint, used only to detect that a file talks to a provider. */
const anyProviderEndpointPattern = /(?:graph\.facebook\.com|api\.zalo\.me|openapi\.zalo\.me|api\.telegram\.org|oauth\.zaloapp\.com)/;

/**
 * Approved transport owners. Each entry must state why the direct POST is the
 * transport implementation rather than a bypass of the canonical sender.
 */
const transportOwners = [
  {
    pattern: /core\/channel-gateway\/includes\/class-gateway-sender\.php$/,
    reason: 'The canonical sender itself; its legacy path IS the transport.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/class-notify-dispatcher\.php$/,
    reason: 'Notification Center dispatcher owns the Zalo Bot + email notification transport for WP/Woo events.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/adapters\//,
    reason: 'Channel Gateway adapter directory: adapters are the transport layer the sender delegates to.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/webchat\//,
    reason: 'Channel Gateway webchat transport directory.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/cf7\//,
    reason: 'Channel Gateway CF7 transport directory.',
  },
  {
    pattern: /plugins\/bizcity-twin-crm\/includes\/inbox\/adapters\//,
    reason: 'CRM inbox adapters implement the CRM channel contract send() transport.',
  },
  {
    pattern: /plugins\/bizcity-twin-crm\/includes\/inbox\/bridges\//,
    reason: 'CRM inbox bridges own the provider transport for their channel.',
  },
  {
    pattern: /plugins\/bizcity-zalo-bot\//,
    reason: 'Zalo Bot plugin is the Zone 2 Zalo transport owner.',
  },
  {
    pattern: /plugins\/bizcity-zalo-bizcity\//,
    reason: 'Zalo OA legacy transport owner (bounded legacy adapter).',
  },
  {
    pattern: /plugins\/bizcity-facebook-bot\//,
    reason: 'Facebook Bot plugin is the Facebook transport owner.',
  },
  {
    pattern: /plugins\/bizcity-zalo-personal\//,
    reason: 'Zalo Personal plugin owns the zca-bridge transport.',
  },
  {
    pattern: /core\/diagnostics\/includes\/probes\//,
    reason: 'Diagnostics probes fire synthetic provider calls as negative-case scaffolding.',
  },
  {
    pattern: /core\/helper-legacy\//,
    reason: 'Legacy helper tree retained for compatibility; tracked as migration debt, not new code.',
  },
  {
    pattern: /plugins\/bizcoach-pro\/legacy\//,
    reason: 'Bundled plugin legacy tree retained for compatibility; tracked as migration debt.',
  },
];

const senderCallPattern = /BizCity_Gateway_Sender::instance\(\)\s*->\s*send\s*\(/;
const idempotencyPattern = /idempotency_key/;

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

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const findings = [];
const counters = {
  files_scanned: 0,
  provider_post_sites: 0,
  provider_posts_in_owner: 0,
  sender_call_sites: 0,
  sender_calls_with_idempotency: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot)) {
    const relative = relativeOf(file);
    const source = fs.readFileSync(file, 'utf8');
    counters.files_scanned += 1;

    const lines = source.split(/\r?\n/);

    // ── Rule A: direct message-send POST outside an approved transport owner ─
    // The endpoint may sit on the line after `wp_remote_post(`, and the URL may
    // be built from a variable (`$url`, `$endpoint`). Both shapes are resolved
    // by scanning a small window around each `wp_remote_post(` call.
    const providerPosts = [];
    lines.forEach((line, index) => {
      if (!/wp_remote_post\s*\(/.test(line)) return;
      const window = lines.slice(index, Math.min(lines.length, index + 6)).join(' ');
      if (!messageSendEndpointPattern.test(window)) return;
      providerPosts.push({ line: index + 1 });
    });

    if (providerPosts.length > 0) {
      counters.provider_post_sites += providerPosts.length;
      const owner = transportOwners.find((entry) => entry.pattern.test(relative));
      if (owner) {
        counters.provider_posts_in_owner += providerPosts.length;
      } else {
        for (const post of providerPosts) {
          findings.push({
            rule: 'R-CH-SENDER.direct_provider_post_outside_owner',
            file: relative,
            line: post.line,
            owner: relative.split('/').slice(0, 2).join('/'),
            missing: ['canonical_sender'],
            contract: 'R-CH-FILE-LOG + R-ZONE §canonical sender ownership',
            fix_hint:
              'Send through BizCity_Gateway_Sender::instance()->send() so trace context, outbound logging and bizcity_channel_after_send are applied.',
            fingerprint: `provider_post|${relative}|${post.line}`,
          });
        }
      }
    }

    // ── Rule B: canonical sender call without an idempotency key ────────────
    // The key must appear in CODE, not in a comment. The first draft tested the
    // raw file source, so a docblock that merely *mentions* `idempotency_key`
    // satisfied the rule — a false negative proven by the invalid fixture, whose
    // own header comment names the field it is supposed to be missing.
    const senderCalls = [];
    lines.forEach((line, index) => {
      if (senderCallPattern.test(line)) senderCalls.push({ line: index + 1 });
    });

    if (senderCalls.length > 0) {
      counters.sender_call_sites += senderCalls.length;
      // Comments are blanked, string literals are kept: `'idempotency_key' => $k`
      // is the evidence the rule wants, while a docblock that merely names the
      // field must not satisfy it. See maskComments() for why this is not
      // cosmetic — the invalid fixture's own header comment names the field it
      // is missing.
      const codeOnly = maskComments(source);
      if (idempotencyPattern.test(codeOnly)) {
        counters.sender_calls_with_idempotency += senderCalls.length;
      } else {
        for (const call of senderCalls) {
          findings.push({
            rule: 'R-CH-SENDER.send_without_idempotency_key',
            file: relative,
            line: call.line,
            owner: relative.split('/').slice(0, 2).join('/'),
            missing: ['idempotency_key'],
            contract: 'R-CH-FILE-LOG §duplicate-send prevention',
            fix_hint:
              'Pass an idempotency_key in $extra so a retry or double-fired hook cannot produce a duplicate customer-visible message.',
            fingerprint: `send_no_idem|${relative}|${call.line}`,
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
  sender_ownership_coverage: counters.provider_post_sites === 0
    ? 'ZERO provider posts scanned — rule unproven'
    : `${counters.provider_posts_in_owner}/${counters.provider_post_sites} provider posts inside an approved transport owner`,
  idempotency_coverage: counters.sender_call_sites === 0
    ? 'ZERO sender calls scanned — rule unproven'
    : `${counters.sender_calls_with_idempotency}/${counters.sender_call_sites} sender calls carry an idempotency key`,
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