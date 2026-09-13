#!/usr/bin/env node
/**
 * Run the permanent clean and broken fixtures for the deterministic contract audit.
 *
 * The production audit remains a separate CI step. This validator proves that
 * known compliant examples pass and that each covered bypass class fails.
 */

import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const auditScript = path.join(root, 'bin', 'framework-contract-audit.mjs');
const baseline = path.join(root, 'tests', 'fixtures', 'framework-contract-audit', 'empty-baseline.json');
const fixtureRoot = path.join(root, 'tests', 'fixtures', 'framework-contract-audit');
const cleanRoot = path.join(fixtureRoot, 'clean');
const brokenRoot = path.join(fixtureRoot, 'broken');
const expectedBrokenRules = new Set([
  'R-GW-8.direct-openai-plugin',
  'R-1API-AUTH.raw-gateway-key-plugin',
  'R-GW-8.direct-router-class-plugin',
  'R-CH-UNI.raw-business-channel-listener',
  'R-CH-UNI.legacy-waic-dispatch',
  'R-CRM.direct-sql-outside-owner',
  'R-KG-HUB.direct-table-access-outside-owner',
  'R-1API-AUTH.video-kling-local-provider-key',
  'R-GW-8.video-kling-direct-provider-url',
  'R-ERROR-UX.video-kling-message-only',
  'R-MCP.tool-registration-metadata',
]);

for (const file of [auditScript, baseline, cleanRoot, brokenRoot]) {
  if (!fs.existsSync(file)) {
    throw new Error(`Missing fixture audit path: ${path.relative(root, file)}`);
  }
}

function runAudit(scanRoot) {
  const result = spawnSync(process.execPath, [
    auditScript,
    `--scan-root=${path.relative(root, scanRoot)}`,
    `--baseline=${path.relative(root, baseline)}`,
  ], {
    cwd: root,
    encoding: 'utf8',
  });
  const output = `${result.stdout || ''}${result.stderr || ''}`.trim();
  let report;
  try {
    report = JSON.parse(output);
  } catch (error) {
    throw new Error(`Audit did not return JSON for ${path.relative(root, scanRoot)}: ${error.message}`);
  }
  return { exitCode: result.status, report };
}

const clean = runAudit(cleanRoot);
if (clean.exitCode !== 0 || clean.report.status !== 'PASS' || clean.report.total_findings !== 0) {
  throw new Error(`Clean fixture must PASS with zero findings: ${JSON.stringify(clean.report)}`);
}

const broken = runAudit(brokenRoot);
const brokenRuleIds = new Set(broken.report.new_findings.map((finding) => finding.id));
const missingRules = [...expectedBrokenRules].filter((ruleId) => !brokenRuleIds.has(ruleId));
if (broken.exitCode === 0 || broken.report.status !== 'FAIL' || missingRules.length > 0) {
  throw new Error(`Broken fixtures must FAIL with all covered rule IDs; missing=${missingRules.join(',')}`);
}

console.log(`CLEAN_FIXTURE=PASS findings=${clean.report.total_findings}`);
console.log(`BROKEN_FIXTURES=PASS rules=${[...expectedBrokenRules].join(',')}`);
