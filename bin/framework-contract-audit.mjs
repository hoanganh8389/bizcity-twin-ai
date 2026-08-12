#!/usr/bin/env node
/**
 * Active plugin contract guard.
 *
 * This is intentionally a narrow regression gate, not a replacement for the
 * WordPress runtime probes. It scans active plugin PHP only, compares findings
 * with the reviewed migration baseline, and fails when a new bypass appears.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = process.cwd();
const baselinePath = path.join(root, 'docs', 'audits', 'ACTIVE-CONTRACT-DEBT-BASELINE.json');
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
    id: 'R-1API-AUTH.video-kling-local-provider-key',
    filePattern: /^plugins\/bizcity-video-kling\//,
    regex: /(?:get_option|update_option|add_option)\s*\(\s*['"](?:bizcity_video_kling_api_key|bizcity_video_kling_openai_api_key|twf_openai_api_key|bizcity_video_kling_endpoint)['"]/i,
    message: 'Video Kling reads or writes a local provider credential/endpoint; use the managed client boundary.',
  },
  {
    id: 'R-1API-AUTH.video-kling-twitcanva-local-provider-key',
    filePattern: /^plugins\/bizcity-video-kling\//,
    regex: /['"](?:gemini_key|kling_access_key|kling_secret_key|hailuo_key|openai_key|fal_key)['"]/i,
    message: 'Video Kling exposes a retired TwitCanva provider key path; keep provider credentials at the managed Hub boundary.',
  },
  {
    id: 'R-GW-8.video-kling-direct-provider-url',
    filePattern: /^plugins\/bizcity-video-kling\//,
    regex: /https?:\/\/api\.(?:piapi\.ai|openai\.com|klingai\.com)/i,
    message: 'Video Kling contains a direct provider URL; use BizCity_Video_Client and Hub-owned provider transport.',
  },
  {
    id: 'R-ERROR-UX.pagebuilder-upload-message-only',
    filePattern: /^plugins\/bizcity-pagebuilder\/includes\/(?:class-rest-api|class-submission-handler)\.php$/,
    regex: /wp_send_json_error\s*\(\s*(?:['"]|(?:array\s*\(|\[)\s*['"]message['"])/i,
    message: 'PageBuilder upload/submission boundary returns a message-only AJAX error; use BizCity_Error_Payload.',
  },
  {
    id: 'R-ERROR-UX.video-kling-message-only',
    filePattern: /^plugins\/bizcity-video-kling\/.*\.php$/,
    regex: /wp_send_json_error\s*\(\s*(?:['"]|(?:array\s*\(|\[)\s*['"]message['"])/i,
    message: 'Video Kling user-facing boundary returns a message-only AJAX error; use BizCity_Error_Payload.',
  },
];

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
for (const file of walk(path.join(root, 'plugins'))) {
  const relative = path.relative(root, file).replaceAll(path.sep, '/');
  const lines = fs.readFileSync(file, 'utf8').split(/\r?\n/);
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
      const occurrenceKey = `${rule.id}|${relative}|${line.replace(/\s+/g, ' ').trim()}`;
      const occurrence = (occurrences.get(occurrenceKey) || 0) + 1;
      occurrences.set(occurrenceKey, occurrence);
      findings.push({
        id: rule.id,
        file: relative,
        line: index + 1,
        message: rule.message,
        fingerprint: fingerprint(rule, relative, line, occurrence),
      });
    }
  });
}

const baselineFingerprints = new Set(baseline.known_findings.map((item) => item.fingerprint));
const newFindings = findings.filter((item) => !baselineFingerprints.has(item.fingerprint));
const missingBaseline = baseline.known_findings.filter(
  (item) => !findings.some((finding) => finding.fingerprint === item.fingerprint),
);

const report = {
  audit_id: baseline.audit_id,
  generated_at: new Date().toISOString(),
  scope: 'plugins/**/*.php excluding archived/vendor/generated trees',
  total_findings: findings.length,
  known_debt: findings.length - newFindings.length,
  new_findings: newFindings,
  stale_baseline_entries: missingBaseline,
  status: newFindings.length === 0 ? 'PASS' : 'FAIL',
};

process.stdout.write(`${JSON.stringify(report, null, 2)}\n`);
if (newFindings.length > 0) process.exitCode = 1;
