#!/usr/bin/env node
/**
 * Active plugin contract guard.
 *
 * This is intentionally a narrow regression gate, not a replacement for the
 * WordPress runtime probes. It scans active plugin PHP plus the reviewed
 * PageBuilder transport artifact, compares findings with the migration
 * baseline, and fails when a new bypass appears.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const scanRootArgument = process.argv.find((argument) => argument.startsWith('--scan-root='));
const baselineArgument = process.argv.find((argument) => argument.startsWith('--baseline='));
const scanRoot = scanRootArgument
  ? path.resolve(root, scanRootArgument.slice('--scan-root='.length))
  : path.join(root, 'plugins');
const baselinePath = baselineArgument
  ? path.resolve(root, baselineArgument.slice('--baseline='.length))
  : path.join(root, 'docs', 'audits', 'ACTIVE-CONTRACT-DEBT-BASELINE.json');
const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));
const excludedSegments = new Set([
  '_archived',
  '_library',
  'node_modules',
  'vendor',
  'dist',
  'build',
  '.vite',
]);

const rules = [
  {
    id: 'R-GW-8.direct-openai-plugin',
    regex: /wp_remote_(?:post|request|get)\s*\(\s*['"]https:\/\/api\.openai\.com/i,
    message: 'Active plugin calls OpenAI directly; use BizCity_LLM_Client or an approved wrapper.',
  },
  {
    id: 'R-1API-AUTH.raw-gateway-key-plugin',
    regex: /get_option\s*\(\s*['"](?:bizcity_llm_api_key|bizcity_openrouter_api_key|bzpb_openai_api_key)['"]/i,
    message: 'Active plugin reads a gateway/provider key directly; use the canonical client getter.',
  },
  {
    id: 'R-GW-8.direct-router-class-plugin',
    regex: /BizCity_Router_(?:Proxy|Auth|Usage|Models)\b/,
    message: 'Active client code references a server-only Router class; use the approved client wrapper.',
  },
  {
    id: 'R-CH-UNI.raw-business-channel-listener',
    regex: /add_action\s*\(\s*['"]bizcity_(?:zalo|facebook)_(?:message|comment|image)_received['"]/i,
    message: 'Active plugin business logic subscribes to a raw channel hook; consume bizcity_channel_normalized.',
  },
  {
    id: 'R-CH-UNI.legacy-waic-dispatch',
    regex: /do_action\s*\(\s*['"]waic_twf_process_flow['"]/i,
    message: 'Active plugin emits the legacy WAIC flow directly; route through the canonical channel path.',
  },
  {
    id: 'R-CRM.direct-sql-outside-owner',
    regex: /\$wpdb\s*->\s*(?:insert|update|delete)\s*\([^\r\n]*(?:bizcity_crm_(?:messages|conversations|contacts)|tbl_(?:messages|conversations|contacts)\s*\()/i,
    message: 'Active plugin writes CRM business tables directly; use the CRM repository and event emitter owner.',
  },
  {
    id: 'R-KG-HUB.direct-table-access-outside-owner',
    filePattern: /(?:^|\/)plugins\/(?:bizcity-(?:zalo-bot|facebook-bot|twin-crm))\//,
    regex: /\$wpdb\s*->\s*(?:get_results|get_row|get_var|insert|update|delete)\s*\([^\r\n]*(?:bizcity_kg_|tbl_(?:notebooks|sources|passages|entities|relations)\s*\()/i,
    message: 'External package accesses KG tables directly; use the KG-Hub service or facade owner.',
  },
  {
    id: 'R-MCP.tool-registration-metadata',
    filePattern: /(?:^|\/)plugins\/(?!bizcity-twin-crm\/)/,
    regex: /add_filter\s*\(\s*['"]bizcity_twin_register_tool['"]/i,
    message: 'External plugin registers a Twin tool without local permission/scope or manifest binding metadata.',
  },
  {
    id: 'R-1API-AUTH.video-kling-local-provider-key',
    filePattern: /(?:^|\/)plugins\/bizcity-video-kling\//,
    regex: /(?:get_option|update_option|add_option)\s*\(\s*['"](?:bizcity_video_kling_api_key|bizcity_video_kling_openai_api_key|twf_openai_api_key|bizcity_video_kling_endpoint)['"]/i,
    message: 'Video Kling reads or writes a local provider credential/endpoint; use the managed client boundary.',
  },
  {
    id: 'R-1API-AUTH.video-kling-twitcanva-local-provider-key',
    filePattern: /(?:^|\/)plugins\/bizcity-video-kling\//,
    regex: /['"](?:gemini_key|kling_access_key|kling_secret_key|hailuo_key|openai_key|fal_key)['"]/i,
    message: 'Video Kling exposes a retired TwitCanva provider key path; keep provider credentials at the managed Hub boundary.',
  },
  {
    id: 'R-GW-8.video-kling-direct-provider-url',
    filePattern: /(?:^|\/)plugins\/bizcity-video-kling\//,
    regex: /https?:\/\/api\.(?:piapi\.ai|openai\.com|klingai\.com)/i,
    message: 'Video Kling contains a direct provider URL; use BizCity_Video_Client and Hub-owned provider transport.',
  },
  {
    id: 'R-ERROR-UX.pagebuilder-upload-message-only',
    filePattern: /(?:^|\/)plugins\/bizcity-pagebuilder\/includes\/(?:class-rest-api|class-submission-handler)\.php$/,
    regex: /wp_send_json_error\s*\(\s*(?:['"]|(?:array\s*\(|\[)\s*['"]message['"])/i,
    message: 'PageBuilder upload/submission boundary returns a message-only AJAX error; use BizCity_Error_Payload.',
  },
  {
    id: 'R-ERROR-UX.video-kling-message-only',
    filePattern: /(?:^|\/)plugins\/bizcity-video-kling\/.*\.php$/,
    regex: /wp_send_json_error\s*\(\s*(?:['"]|(?:array\s*\(|\[)\s*['"]message['"])/i,
    message: 'Video Kling user-facing boundary returns a message-only AJAX error; use BizCity_Error_Payload.',
  },
];

const evidenceByRuleId = {
  'R-GW-8.direct-openai-plugin': {
    owner: 'BizCity_LLM_Client',
    contract: 'R-GW-8',
    fix_hint: 'Route the provider request through BizCity_LLM_Client or an approved client wrapper.',
  },
  'R-1API-AUTH.raw-gateway-key-plugin': {
    owner: 'BizCity_LLM_Client',
    contract: 'R-1API-AUTH',
    fix_hint: 'Read the credential through BizCity_LLM_Client::get_api_key().',
  },
  'R-GW-8.direct-router-class-plugin': {
    owner: 'BizCity_LLM_Client',
    contract: 'R-GW-8',
    fix_hint: 'Replace the server-only Router class with the client wrapper boundary.',
  },
  'R-CH-UNI.raw-business-channel-listener': {
    owner: 'Channel Gateway',
    contract: 'channel-payload@1.x',
    fix_hint: 'Consume bizcity_channel_normalized after verified intake and identity resolution.',
  },
  'R-CH-UNI.legacy-waic-dispatch': {
    owner: 'Channel Gateway',
    contract: 'channel-payload@1.x',
    fix_hint: 'Route the event through the canonical normalized channel path.',
  },
  'R-CRM.direct-sql-outside-owner': {
    owner: 'plugins/bizcity-twin-crm',
    contract: 'CRM channel contract + Repository/Event Emitter',
    fix_hint: 'Use the canonical CRM repository and emit one CRM event after the write.',
  },
  'R-KG-HUB.direct-table-access-outside-owner': {
    owner: 'core/knowledge/kg-hub',
    contract: 'KG-Hub source/retrieval contract',
    fix_hint: 'Resolve KG data through the KG-Hub service/facade instead of direct table SQL.',
  },
  'R-MCP.tool-registration-metadata': {
    owner: 'Twin Capability Consent / Tool Registry',
    contract: 'CAPABILITY-SECURITY-v1 + manifest permissions/scope_bindings',
    fix_hint: 'Declare permissions and scope bindings, then bind registration to the validated extension manifest.',
  },
  'R-ERROR-UX.pagebuilder-upload-message-only': {
    owner: 'BizCity_Error_Payload',
    contract: 'error-envelope@1.x',
    fix_hint: 'Return the four-field error envelope with BizCity_Error_Payload.',
  },
  'R-ERROR-UX.video-kling-message-only': {
    owner: 'BizCity_Error_Payload',
    contract: 'error-envelope@1.x',
    fix_hint: 'Return the four-field error envelope with BizCity_Error_Payload.',
  },
  'R-RUNTIME.pagebuilder-mutation-idempotency': {
    owner: 'Twin Core mutation guard',
    contract: 'mutation-contract@1.x',
    fix_hint: 'Send X-Idempotency-Key on every mutation caller and built bundle.',
  },
  'R-1API-AUTH.video-kling-local-provider-key': {
    owner: 'BizCity_Video_Client',
    contract: 'R-1API-AUTH',
    fix_hint: 'Remove local provider credential storage and use BizCity_Video_Client.',
  },
  'R-1API-AUTH.video-kling-twitcanva-local-provider-key': {
    owner: 'BizCity_Video_Client',
    contract: 'R-1API-AUTH',
    fix_hint: 'Remove retired provider key paths; provider credentials remain Hub-owned.',
  },
  'R-GW-8.video-kling-direct-provider-url': {
    owner: 'BizCity_Video_Client',
    contract: 'R-GW-8',
    fix_hint: 'Route video generation through BizCity_Video_Client and the Hub gateway.',
  },
  'R-ERROR-UX.video-kling-message-only': {
    owner: 'BizCity_Error_Payload',
    contract: 'error-envelope@1.x',
    fix_hint: 'Return the four-field error envelope with BizCity_Error_Payload.',
  },
};

function evidenceForRule(ruleId) {
  return evidenceByRuleId[ruleId] || {
    owner: 'Twin AI Core / Framework Governance',
    contract: ruleId.split('.')[0],
    fix_hint: 'Apply the canonical owner and contract named by this audit rule.',
  };
}

function isExcluded(filePath) {
  const relative = path.relative(root, filePath);
  return relative.split(path.sep).some((segment) => excludedSegments.has(segment));
}

function walk(directory) {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    if (isExcluded(target)) continue;
    if (entry.isDirectory()) files.push(...walk(target));
    else if (entry.isFile() && target.toLowerCase().endsWith('.php')) files.push(target);
  }
  return files;
}

function fingerprint(rule, file, source, occurrence) {
  const normalizedSource = source
    .replace(/\s+/g, ' ')
    .trim();
  return `${rule.id}|${file}|${normalizedSource}|${occurrence}`;
}

const findings = [];
const occurrences = new Map();
for (const file of walk(scanRoot)) {
  const relative = path.relative(root, file).replaceAll(path.sep, '/');
  const source = fs.readFileSync(file, 'utf8');
  const lines = source.split(/\r?\n/);
  lines.forEach((line, index) => {
    if (/^\s*(?:\/\/|\/\*|\*|\*\/|#)/.test(line)) return;
    for (const rule of rules) {
      if (rule.filePattern && !rule.filePattern.test(relative)) continue;
      if (!rule.regex.test(line)) continue;
      // Canonical channel adapters must consume the verified raw webhook once
      // in order to publish the normalized envelope for downstream consumers.
      if (
        rule.id === 'R-CH-UNI.raw-business-channel-listener'
        && relative.endsWith('/includes/class-channel-adapter.php')
        && line.includes("'emit_normalized'")
      ) continue;
      if (
        rule.id === 'R-CRM.direct-sql-outside-owner'
        && relative.startsWith('plugins/bizcity-twin-crm/')
      ) continue;
      if (
        rule.id === 'R-MCP.tool-registration-metadata'
        && /\b(?:permissions|scope(?:_level|_bindings)?)\b|register_manifest/i.test(source)
      ) continue;
      const occurrenceKey = `${rule.id}|${relative}|${line.replace(/\s+/g, ' ').trim()}`;
      const occurrence = (occurrences.get(occurrenceKey) || 0) + 1;
      occurrences.set(occurrenceKey, occurrence);
      findings.push({
        id: rule.id,
        file: relative,
        line: index + 1,
        message: rule.message,
        ...evidenceForRule(rule.id),
        fingerprint: fingerprint(rule, relative, line, occurrence),
      });
    }
  });
}

const pageBuilderApiRelative = 'plugins/bizcity-pagebuilder/app/src/api.ts';
const pageBuilderApiPath = path.join(root, pageBuilderApiRelative);
const pageBuilderMutationRule = {
  id: 'R-RUNTIME.pagebuilder-mutation-idempotency',
  message: 'PageBuilder mutation caller must send X-Idempotency-Key to the mutation boundary.',
};
const pageBuilderMutations = [
  'saveProject',
  'deleteProject',
  'publishProject',
];

if (!scanRootArgument && fs.existsSync(pageBuilderApiPath)) {
  const source = fs.readFileSync(pageBuilderApiPath, 'utf8');
  for (const mutation of pageBuilderMutations) {
    const functionMatch = source.match(
      new RegExp(`export async function ${mutation}\\b[\\s\\S]*?(?=\\r?\\nexport async function |\\r?\\nexport interface |$)`),
    );
    if (!functionMatch || !functionMatch[0].includes('X-Idempotency-Key')) {
      const marker = `export async function ${mutation}`;
      const line = source.slice(0, Math.max(0, source.indexOf(marker))).split(/\r?\n/).length;
      findings.push({
        id: pageBuilderMutationRule.id,
        file: pageBuilderApiRelative,
        line,
        message: pageBuilderMutationRule.message,
        ...evidenceForRule(pageBuilderMutationRule.id),
        fingerprint: fingerprint(pageBuilderMutationRule, pageBuilderApiRelative, marker, 1),
      });
    }
  }

  const pageBuilderDistRelative = 'plugins/bizcity-pagebuilder/assets/dist/pagebuilder-app.js';
  const pageBuilderDistPath = path.join(root, pageBuilderDistRelative);
  if (fs.existsSync(pageBuilderDistPath)) {
    const bundle = fs.readFileSync(pageBuilderDistPath, 'utf8');
    if (!bundle.includes('X-Idempotency-Key')) {
      findings.push({
        id: pageBuilderMutationRule.id,
        file: pageBuilderDistRelative,
        line: 1,
        message: 'Built PageBuilder bundle does not contain the mutation idempotency header.',
        ...evidenceForRule(pageBuilderMutationRule.id),
        fingerprint: fingerprint(pageBuilderMutationRule, pageBuilderDistRelative, 'X-Idempotency-Key', 1),
      });
    }
  }
}

const baselineFingerprints = new Set(baseline.known_findings.map((item) => item.fingerprint));
const newFindings = findings.filter((item) => !baselineFingerprints.has(item.fingerprint));
const missingBaseline = baseline.known_findings.filter(
  (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
);

const report = {
  audit_id: baseline.audit_id,
  generated_at: new Date().toISOString(),
  scope: scanRootArgument
    ? `${path.relative(root, scanRoot).replaceAll(path.sep, '/')} fixture scan`
    : 'plugins/**/*.php excluding archived/vendor/generated trees plus PageBuilder source/bundle transport checks',
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: missingBaseline,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0) process.exitCode = 1;
