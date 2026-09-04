import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const policyPath = path.join(root, 'core', 'helper', 'class-bizcity-legacy-table-policy.php');
const uninstallPath = path.join(root, 'uninstall.php');

function read(file) {
  return fs.readFileSync(file, 'utf8');
}

function walk(directory, files = []) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (['_archived', '_library', 'vendor', 'node_modules', 'docs', 'changelog', 'dist', 'build'].includes(entry.name)) {
      continue;
    }
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) walk(file, files);
    else if (entry.isFile() && file.endsWith('.php')) files.push(file);
  }
  return files;
}

function withoutPhpComments(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, '')
    .replace(/(^|\s)\/\/.*$/gm, '$1')
    .replace(/(^|\s)#.*$/gm, '$1');
}

if (!fs.existsSync(policyPath)) throw new Error('Missing legacy table policy helper.');
const policy = read(policyPath);
const retiredBlock = policy.match(/private\s+static\s+\$retired\s*=\s*array\s*\(([\s\S]*?)\);/);
if (!retiredBlock) throw new Error('Cannot read retired table list from policy helper.');
const retired = [...retiredBlock[1].matchAll(/['"]([a-z0-9_]+)['"]/gi)].map((match) => match[1]);
if (retired.length < 8) throw new Error(`Retired table list is unexpectedly short: ${retired.length}.`);
for (const marker of ['install_blocked', 'allow_sql', 'ready_to_drop', 'can_drop', 'uninstall_ready_tables']) {
  if (!policy.includes(marker)) throw new Error(`Policy helper is missing ${marker}.`);
}

const groupB = [
  'bizcity_intent_one_shot',
  'bizcity_intent_traces',
  'bizcity_intent_tasks',
  'bizcity_kg_characters',
  'bizcity_kg_sources_legacy',
  'bizcity_persona_subscribers',
  'bizcity_persona_prefs',
  'bizcity_twin_state_focus',
  'bizcity_twin_state_snapshot',
  'bizcity_twin_state_resolver',
  'bizcity_twin_state_session',
  'bizcity_twin_state_log',
  'bizcity_twin_state_kv',
  'bizcity_twinchat_welcome_jobs',
  'bizcity_twinchat_notes',
  'bizcity_kling_effects',
  'bizcity_twin_identity',
  'bizcity_twin_focus_state',
  'bizcity_twin_timeline_state',
  'bizcity_twin_journeys',
];
const catalogPath = path.join(root, 'core', 'diagnostics', 'includes', 'class-diagnostics-table-registry.php');
if (!fs.existsSync(catalogPath)) throw new Error('Missing Diagnostics table catalog.');
const catalog = read(catalogPath);
const groupBFindings = [];
for (const table of groupB) {
  if (!retired.includes(table)) groupBFindings.push(`${table}: missing from central retired policy`);
  if (!new RegExp(`['"]name['"]\\s*=>\\s*['"]${table}['"]`, 'i').test(catalog)) {
    groupBFindings.push(`${table}: missing from Diagnostics catalog`);
  }
}
if (groupBFindings.length) {
  console.error('Group B inventory violations:');
  for (const finding of groupBFindings) console.error(`- ${finding}`);
  process.exit(1);
}

const findings = [];
for (const file of walk(root)) {
  const relative = path.relative(root, file).replaceAll(path.sep, '/');
  const code = withoutPhpComments(read(file));
  for (const table of retired) {
    const tablePattern = new RegExp(`\\b${table}\\b`, 'i');
    if (!tablePattern.test(code)) continue;
    if (new RegExp(`(?:CREATE\\s+TABLE|dbDelta\\s*\\()[^;\\n]*${table}`, 'i').test(code)) {
      findings.push(`${relative}: direct install of ${table}`);
    }
    if (relative !== 'core/helper/class-bizcity-legacy-table-policy.php'
      && new RegExp(`(?:DROP\\s+TABLE|DELETE\\s+FROM|ALTER\\s+TABLE)[^;\\n]*${table}`, 'i').test(code)) {
      findings.push(`${relative}: direct destructive SQL for ${table}`);
    }
    if (groupB.includes(table)
      && relative !== 'core/helper/class-bizcity-legacy-table-policy.php'
      && new RegExp(`(?:SELECT\\b[\\s\\S]{0,500}?FROM|INSERT\\s+INTO|UPDATE\\s+|DELETE\\s+FROM|CREATE\\s+TABLE|ALTER\\s+TABLE|DROP\\s+TABLE|dbDelta\\s*\\()[^;\\n]*${table}`, 'i').test(code)) {
      findings.push(`${relative}: active SQL operation for Group B table ${table}`);
    }
  }
}

if (!fs.existsSync(uninstallPath)) throw new Error('Missing main plugin uninstall.php.');
const uninstall = read(uninstallPath);
for (const marker of ['WP_UNINSTALL_PLUGIN', 'BizCity_Legacy_Table_Policy', 'uninstall_ready_tables']) {
  if (!uninstall.includes(marker)) throw new Error(`uninstall.php is missing ${marker}.`);
}

const mainPluginPath = path.join(root, 'bizcity-twin-ai.php');
if (!fs.existsSync(mainPluginPath)) throw new Error('Missing main plugin entrypoint.');
const mainPlugin = read(mainPluginPath);
for (const marker of ['register_deactivation_hook', 'deactivate_retired_tables']) {
  if (!mainPlugin.includes(marker)) throw new Error(`Main plugin is missing ${marker} deactivation wiring.`);
}

if (findings.length) {
  console.error('Retired-table install violations:');
  for (const finding of findings.slice(0, 30)) console.error(`- ${finding}`);
  process.exit(1);
}

console.log(`LEGACY TABLE LIFECYCLE PASS (${retired.length} retired tables; no direct install statements; gated uninstall present)`);
console.log(`GROUP B EXIT-RETURN PASS (${groupB.length} dead tables; policy/catalog covered; no active SQL path)`);
