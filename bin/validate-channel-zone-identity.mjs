#!/usr/bin/env node
/**
 * WP4 — Channel identity and Zone enforcement audit.
 *
 * Two things are reconciled here:
 *
 * 1. **Zone isolation.** A hook whose name implies admin/command ownership
 *    (`bizcity_zalo_message_received`, `bizcity_facebook_message_received`, ...)
 *    is a Zone 2 surface. Zone 1 channels (Zalo OA, Zalo Personal, Messenger,
 *    WebChat, Email) legitimately EMIT those hooks with a discriminator, but a
 *    Zone 2 consumer must bail a Zone 1 payload before doing admin work. This
 *    audit requires every Zone 2 consumer to contain a discriminator guard.
 *
 * 2. **Identity tuple.** Every raw channel emitter must carry the canonical
 *    provenance tuple `(platform, account_id, user_id/from_user_id, chat_id,
 *    message_id)` — or an explicit `code` discriminator plus account field —
 *    so downstream owners can resolve exact tenant/account scope.
 *
 * This is static source analysis. It does not execute a webhook, does not prove
 * runtime routing and does not verify that a guard is *correct*, only that a
 * discriminator check exists before the handler proceeds.
 *
 * Usage:
 *   node bin/validate-channel-zone-identity.mjs [--strict] [--baseline=<path>]
 *   node bin/validate-channel-zone-identity.mjs --fixture-root=<path>
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const strict = process.argv.includes('--strict');
const fixtureArgument = process.argv.find((argument) => argument.startsWith('--fixture-root='));
const fixtureRoot = fixtureArgument
  ? path.resolve(root, fixtureArgument.slice('--fixture-root='.length))
  : null;
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'tests', 'fixtures', 'channel-zone-identity', 'baseline.json');
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

/** Zone 2 (admin/command) hook surfaces. Zone 1 channels may emit these. */
const zone2Hooks = [
  'bizcity_zalo_message_received',
  'bizcity_facebook_message_received',
  'bizcity_facebook_comment_received',
  'bizcity_facebook_image_received',
  'bizcity_telegram_message_received',
];

/**
 * Raw inbound hooks that carry a customer message and therefore must preserve
 * the identity tuple. Continuity/replay/observability hooks are deliberately
 * excluded here and handled by `identityExemptions` instead.
 *
 * `bizcity_webchat_push_message` is deliberately NOT here: inspection of all
 * five of its emit sites showed it is an OUTBOUND push of a bot reply
 * (`'message_from' => 'bot'`, `do_action( 'bizcity_webchat_push_message',
 * $session_id, $message, $options )`), not a raw customer inbound. Including it
 * produced three false positives (`core/channel-gateway/includes/adapters/`,
 * `core/channel-gateway/includes/webchat/`, `modules/webchat/includes/`).
 */
const rawInboundHooks = [
  'bizcity_channel_message_received',
  'bizcity_zalo_message_received',
  'bizcity_zalo_oa_message_received',
  'bizcity_zalo_personal_message_received',
  'bizcity_facebook_message_received',
  'bizcity_telegram_message_received',
];

/** Zone 1 discriminator markers a Zone 2 consumer must test for. */
const zone1Discriminators = [
  'zalo_oa',
  'zalo_personal',
  'ZALO_OA',
  'ZALO_PERSONAL',
  'telegram',
  'webchat',
  'messenger',
  'old_message',
];

/**
 * Zone 2 markers. A handler that asserts the zone-2 platform it accepts
 * (`platform !== 'ZALO_BOT'` → return) is guarding positively, which is an
 * equally valid discriminator. Both directions satisfy this audit.
 */
const zone2Discriminators = [
  'ZALO_BOT',
  'zalo_bot',
  'TELEGRAM',
  'telegram_bot',
];

/**
 * Canonical identity tuple required on every raw channel emitter, with the
 * accepted field aliases observed in real payloads. WP4 checklist item:
 * "Preserve `(platform, account_id, user_id, chat_id, message_id)`".
 *
 * Aliases are legitimate, not debt: the inventory showed
 * `from_user_id` carries the user identity for Zalo Personal, `mid` carries the
 * message id for Facebook/Zalo OA, and `conversation_id` carries the thread id
 * for Zalo. A rule that only accepted the five canonical names would report
 * false positives against correct code — the same class of defect already hit
 * once in this gate with the interface naming styles.
 */
const identityTuple = [
  { key: 'platform', aliases: ['platform'] },
  { key: 'account_id', aliases: ['account_id', 'bot_id', 'oa_id', 'instance_id', 'page_id'] },
  { key: 'user_id', aliases: ['user_id', 'from_user_id', 'sender_id'] },
  { key: 'chat_id', aliases: ['chat_id', 'conversation_id', 'conversation_chat_id', 'provider_chat_id', 'thread_id'] },
  { key: 'message_id', aliases: ['message_id', 'mid', 'msg_id', 'zalo_msg_id'] },
];

/**
 * Files whose emits are continuity/replay/observability rather than a raw
 * inbound carrying customer identity. Each entry must state why.
 */
const identityExemptions = [
  {
    pattern: /core\/channel-gateway\/includes\/class-channel-conversation-archive\.php$/,
    reason: 'Archive-written event carries entry/receipt, not an inbound identity tuple.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/class-webhook-replay\.php$/,
    reason: 'Replay event carries the stored row plus parent correlation.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/class-channel-user-linker\.php$/,
    reason: 'Link event carries (platform, account, external, wp_user_id, blog_id), not a message.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/class-gateway-bridge\.php$/,
    reason: 'Verification-failure event carries the raw request plus platform; no inbound identity exists yet.',
  },
  {
    // Diagnostics probes fire SYNTHETIC payloads on purpose. Two of them were
    // reported as incomplete: `class-probe-automation.php` deliberately sends a
    // WRONG `instance_id` to prove a workflow must not enqueue, and
    // `class-probe-zalo-personal.php` fires a synthetic ZALO_BOT event to prove
    // it does not reach the flow. Both are negative-case scaffolding, not raw
    // inbound emitters, so requiring a full identity tuple there is wrong.
    pattern: /^core\/diagnostics\/includes\/probes\//,
    reason: 'Diagnostics probe intentionally fires synthetic/negative payloads; not a production inbound path.',
  },
];

/**
 * Zone 1 channel packages: a package that owns customer-care transport. These
 * may emit zone-2 hook names, but must always carry a discriminator.
 */
const zone1PackagePattern = /plugins\/bizcity-(?:zalo-personal|zalo-bizcity|facebook-bot)\//;

/**
 * Runtime owners that are allowed to subscribe to a zone-2 hook without a local
 * discriminator because they are the router/logger whose job is to observe every
 * payload. Each entry must state why.
 */
const observerAllowlist = [
  {
    pattern: /core\/channel-gateway\/includes\/class-cg-debug-logger\.php$/,
    reason: 'Channel Gateway debug logger is the observability owner; it records every zone payload before routing.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/class-universal-channel-listener\.php$/,
    reason: 'Universal Channel Listener IS the zone router; it branches on platform/code internally at lines 113-127.',
  },
  {
    pattern: /core\/channel-gateway\/includes\/listener\/class-listener-bus\.php$/,
    reason: 'Listener bus consumes the normalized envelope surface, not raw business hooks.',
  },
];

function walk(directory, extension, files = []) {
  if (!fs.existsSync(directory)) return files;
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) {
      if (excludedSegments.has(entry.name)) continue;
      walk(target, extension, files);
    } else if (entry.isFile() && target.toLowerCase().endsWith(extension)) {
      files.push(target);
    }
  }
  return files;
}

function relativeOf(file) {
  return path.relative(root, file).replaceAll(path.sep, '/');
}

/** Read a PHP file and return { lines, handlers } for `add_action` on zone2 hooks. */
function fileFacts(file) {
  const source = fs.readFileSync(file, 'utf8');
  const lines = source.split(/\r?\n/);

  // Emitters: do_action( 'hook', ... ) referencing any channel hook.
  const emitters = [];
  const emitterPattern = /do_action\s*\(\s*['"]([a-z0-9_]+)['"]/g;
  let match;
  while ((match = emitterPattern.exec(source)) !== null) {
    const hook = match[1];
    if (!hook.startsWith('bizcity_') && hook !== 'waic_twf_process_flow') continue;
    if (!/zalo|facebook|messenger|webchat|telegram|channel|waic/.test(hook)) continue;
    const lineNumber = source.slice(0, match.index).split(/\r?\n/).length;
    emitters.push({ hook, line: lineNumber });
  }

  // Zone 2 subscribers and the handler body that follows.
  // Two traps found by running this against real source:
  //   1. `add_action` accepts 2, 3 or 4 arguments, so priority/accepted-args are
  //      optional. Requiring them silently missed 15 of 16 real consumers.
  //   2. The callback is usually `array( __CLASS__, 'method' )`, which contains
  //      a comma. A naive `[^,)]+` stops inside the array and never matches.
  const handlers = [];
  const callbackPattern = '(?:array\\s*\\([^)]*\\)|[^,)]+?)';
  const subscribePattern = new RegExp(
    `add_action\\s*\\(\\s*['"](${zone2Hooks.join('|')})['"]\\s*,\\s*(${callbackPattern})\\s*(?:,\\s*\\d+\\s*)?(?:,\\s*\\d+\\s*)?\\)`,
    'g',
  );
  while ((match = subscribePattern.exec(source)) !== null) {
    handlers.push({
      hook: match[1],
      callback: match[2].trim(),
      line: source.slice(0, match.index).split(/\r?\n/).length,
    });
  }

  // A discriminator guard exists when the file either:
  //   (a) NEGATIVE — tests a zone-1 marker (zalo_oa / zalo_personal / ...) so the
  //       handler can bail a zone-1 payload, or
  //   (b) POSITIVE — asserts the zone-2 platform/code it accepts (e.g.
  //       `platform !== 'ZALO_BOT'` → return), which is an equally strong and
  //       often stricter gate.
  //
  // Only (a) was implemented at first, which produced a false positive against
  // `plugins/bizcity-zalo-bot/includes/class-channel-adapter.php`: its handler
  // guards positively at line 38 (`platform !== 'ZALO_BOT'` → return). Verified
  // by reading the file; the negative-only rule was the defect, not the code.
  //
  // File-scope is the deliberate granularity because the guard may live in a
  // sibling method of the same class.
  const guardPattern = new RegExp(
    `(?:\\$[a-z_]+\\s*\\[\\s*['"](?:code|platform|channel)['"]\\s*\\]|\\$[a-z_]*(?:code|platform)\\b)`,
    'i',
  );
  const guardFieldPresent = guardPattern.test(source);
  const hasNegativeGuard = guardFieldPresent
    && zone1Discriminators.some((marker) => source.includes(marker));
  const hasPositiveGuard = guardFieldPresent
    && zone2Discriminators.some((marker) => source.includes(marker));
  const hasGuard = hasNegativeGuard || hasPositiveGuard;
  const guardKind = hasNegativeGuard ? 'negative' : (hasPositiveGuard ? 'positive' : 'none');

  return { lines, emitters, handlers, hasGuard, guardKind, source };
}

const scanRoots = fixtureRoot
  ? [fixtureRoot]
  : ['core', 'modules', 'plugins', 'includes']
    .map((segment) => path.join(root, segment))
    .filter((directory) => fs.existsSync(directory));

const findings = [];
const counters = {
  files_scanned: 0,
  channel_emitters: 0,
  zone2_consumers: 0,
  zone2_consumers_guarded: 0,
  zone2_consumers_guarded_negative: 0,
  zone2_consumers_guarded_positive: 0,
  zone2_consumers_allowlisted: 0,
  identity_fields_present: 0,
  identity_emitters_checked: 0,
  identity_emitters_complete: 0,
  envelope_producers_checked: 0,
  envelope_producers_complete: 0,
};

for (const scanRoot of scanRoots) {
  for (const file of walk(scanRoot, '.php')) {
    const relative = relativeOf(file);
    const facts = fileFacts(file);
    if (facts.emitters.length === 0 && facts.handlers.length === 0) continue;
    counters.files_scanned += 1;
    counters.channel_emitters += facts.emitters.length;

    const isZone1Package = zone1PackagePattern.test(relative);

    // ── Rule 1: zone-2 consumer without a discriminator guard ───────────────
    if (facts.handlers.length > 0) {
      const allowlisted = observerAllowlist.find((entry) => entry.pattern.test(relative));
      for (const handler of facts.handlers) {
        counters.zone2_consumers += 1;
        if (facts.hasGuard) {
          counters.zone2_consumers_guarded += 1;
          if (facts.guardKind === 'negative') counters.zone2_consumers_guarded_negative += 1;
          if (facts.guardKind === 'positive') counters.zone2_consumers_guarded_positive += 1;
          continue;
        }
        if (allowlisted) {
          counters.zone2_consumers_allowlisted += 1;
          continue;
        }
        findings.push({
          rule: 'R-ZONE.zone2_consumer_without_discriminator',
          file: relative,
          line: handler.line,
          hook: handler.hook,
          owner: relative.split('/').slice(0, 2).join('/'),
          missing: ['zone1_discriminator_guard'],
          contract: 'R-ZONE §Zone 1/Zone 2 + PHASE-0-RULE-ZONE-CHANNEL.md',
          fix_hint:
            `Handler ${handler.callback} subscribes ${handler.hook} (zone 2) without testing code/platform for a zone-1 value; bail zalo_oa/zalo_personal before doing admin work.`,
          fingerprint: `zone2_noguard|${relative}|${handler.hook}|${handler.callback}`,
        });
      }
    }

    // ── Rule 4: normalized envelope must carry contract identity ────────────
    // WP4 checklist: "Require `channel-payload` and `channel-diagnostics-record`
    // versions." A producer that emits `bizcity_channel_normalized` without
    // `contract` + `version` forces every consumer to guess the payload shape.
    if (facts.emitters.some((emitter) => emitter.hook === 'bizcity_channel_normalized')) {
      counters.envelope_producers_checked += 1;
      const hasContract = /['"]contract['"]\s*=>/.test(facts.source);
      const hasVersion = /['"]version['"]\s*=>/.test(facts.source);
      if (hasContract && hasVersion) {
        counters.envelope_producers_complete += 1;
      } else {
        const missing = [];
        if (!hasContract) missing.push('contract');
        if (!hasVersion) missing.push('version');
        findings.push({
          rule: 'R-CH-UNI.envelope_missing_contract_identity',
          file: relative,
          line: facts.emitters.find((emitter) => emitter.hook === 'bizcity_channel_normalized').line,
          hook: 'bizcity_channel_normalized',
          owner: relative.split('/').slice(0, 2).join('/'),
          missing,
          contract: 'channel-payload@1.x',
          fix_hint:
            `Normalized envelope producer is missing ${missing.join(' and ')}; carry contract='channel-payload' and version so consumers can reject an incompatible producer.`,
          fingerprint: `envelope_nocontract|${relative}|${missing.join('+')}`,
        });
      }
    }

    // ─ Rule 2: zone-1 package emitting a zone-2 hook without discriminator ─
    if (isZone1Package) {
      for (const emitter of facts.emitters) {
        if (!zone2Hooks.includes(emitter.hook)) continue;
        if (facts.source.includes("'code'") || facts.source.includes('"code"')) {
          counters.identity_fields_present += 1;
          continue;
        }
        findings.push({
          rule: 'R-ZONE.zone1_emitter_without_code_discriminator',
          file: relative,
          line: emitter.line,
          hook: emitter.hook,
          owner: relative.split('/').slice(0, 2).join('/'),
          missing: ['code_discriminator'],
          contract: 'R-ZONE §BE discriminator bắt buộc',
          fix_hint:
            `Zone 1 package emits zone-2 hook ${emitter.hook} without a 'code' discriminator; zone-2 consumers cannot bail it.`,
          fingerprint: `zone1_nocode|${relative}|${emitter.hook}`,
        });
      }
    }

    // ── Rule 3: raw channel emitter missing identity tuple fields ───────────
    // Only raw inbound hooks are checked. Continuity/replay/observability
    // events are exempt with a stated reason.
    if (facts.emitters.length > 0) {
      const identityExempt = identityExemptions.find((entry) => entry.pattern.test(relative));
      const rawInboundEmitters = facts.emitters.filter((emitter) => rawInboundHooks.includes(emitter.hook));
      if (rawInboundEmitters.length > 0 && !identityExempt) {
        const present = identityTuple.filter((field) => field.aliases.some(
          (alias) => facts.source.includes(`'${alias}'`) || facts.source.includes(`"${alias}"`),
        ));
        const missing = identityTuple.filter((field) => !present.includes(field)).map((field) => field.key);
        counters.identity_emitters_checked += 1;
        if (missing.length === 0) {
          counters.identity_emitters_complete += 1;
        } else {
          findings.push({
            rule: 'R-CH-IDMEM.identity_tuple_incomplete',
            file: relative,
            line: rawInboundEmitters[0].line,
            hook: rawInboundEmitters[0].hook,
            owner: relative.split('/').slice(0, 2).join('/'),
            missing,
            contract: 'R-CH-IDMEM §Identity tuple bắt buộc',
            fix_hint:
              `Raw inbound emitter is missing ${missing.join(', ')}; carry the full (platform, account_id, user_id, chat_id, message_id) tuple so downstream owners can resolve exact tenant/account scope.`,
            fingerprint: `identity_incomplete|${relative}|${missing.join('+')}`,
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
  guard_coverage: counters.zone2_consumers === 0
    ? 'ZERO consumers scanned — rule unproven'
    : `${counters.zone2_consumers_guarded} guarded + ${counters.zone2_consumers_allowlisted} allowlisted of ${counters.zone2_consumers} consumers`,
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