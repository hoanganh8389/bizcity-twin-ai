<?php
/**
 * Diagnostics Table Registry — central catalog of every bizcity_* table
 * created by core/, modules/, and bundled plugins inside bizcity-twin-ai.
 *
 * Modules SHOULD register themselves via the filter
 *   add_filter( 'bizcity_diagnostics_register_tables', $fn )
 * but the registry also ships an authoritative seed list so operators get
 * a meaningful inventory even before all modules opt in.
 *
 * Each registered entry: [
 *   'name'    => 'bizcity_kg_passages',         // suffix only (without wpdb prefix)
 *   'owner'   => 'core/knowledge/kg-hub',       // logical owner path
 *   'class'   => 'BizCity_KG_Database',         // installer class (optional)
 *   'group'   => 'knowledge',                   // for UI grouping
 *   'critical'=> true,                          // missing → block features
 *   'module'   => 'core/knowledge/kg-hub',       // owning module (defaults to owner)
 *   'feature'  => 'retrieval',                  // product/runtime feature
 *   'purpose'  => 'Passage retrieval store',     // human-readable function
 *   'readers'  => [ 'Class::method' ],           // known read callsites
 *   'writers'  => [ 'Class::method' ],           // known write callsites
 * ]
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-20
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_Table_Registry {

	/** @var array<int,array>|null memoized snapshot */
	private static $cache = null;

	/**
	 * Authoritative seed — discovered by grep of CREATE TABLE statements
	 * (2026-05-20 audit, refined 2026-05-21 after orphan sweep).
	 * Modules may extend via the filter below.
	 */
	private static function seed(): array {
		return [
			// ── core/knowledge/kg-hub ─────────────────────────────────────
			[ 'name' => 'bizcity_kg_notebooks',          'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_notebook_sources',   'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_passages',           'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_entities',           'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_relations',          'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_passage_entities',   'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_passage_relations',  'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_triplet_queue',      'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_provenance',         'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database', 'feature' => 'source provenance', 'purpose' => 'Links KG rows back to their originating CMS/Studio record for citation trace.', 'readers' => [ 'BizCity_KG_Source_Adapter_Studio' ], 'writers' => [ 'BizCity_KG_Source_Adapter_Studio' ] ],
			[ 'name' => 'bizcity_kg_scope_links',        'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database', 'feature' => 'scope binding', 'purpose' => 'Maps a scope (notebook/character) to a shared passage without duplicating rows.', 'readers' => [ 'BizCity_KG_Facade' ], 'writers' => [ 'BizCity_KG_Facade' ] ],
			[ 'name' => 'bizcity_kg_sources',            'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_xref',               'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_passage_identities', 'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			// [2026-08-21 Johnny Chu] KG-GURU-SCHEMA-INVENTORY — canonical notebook↔Guru attachment map used by attach_guru()/virtual merge.
			[ 'name' => 'bizcity_notebook_character_attachments', 'owner' => 'core/knowledge/kg-hub', 'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			// [2026-07-27 Johnny Chu] PHASE-0.53-MCP Wave A — core/mcp (Twin Client Brain MCP gateway).
			[ 'name' => 'bizcity_mcp_api_keys',             'owner' => 'core/mcp', 'group' => 'mcp', 'critical' => true, 'class' => 'BizCity_MCP_Installer' ],
			[ 'name' => 'bizcity_mcp_retrieval_snapshots',  'owner' => 'core/mcp', 'group' => 'mcp', 'critical' => true, 'class' => 'BizCity_MCP_Installer' ],
			[ 'name' => 'bizcity_mcp_context_packs',        'owner' => 'core/mcp', 'group' => 'mcp', 'class' => 'BizCity_MCP_Installer' ],
			// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — source progress moved to uploads JSONL.
			// Legacy SQL table `bizcity_kg_source_progress_log` is cleanup-only and no longer part of required schema inventory.
			// FIX 2026-05-21: previous seed had `bizcity_kg_cost_guard` — actual table is `bizcity_kg_usage_log` (see BizCity_KG_Cost_Guard::ensure_table()).
			[ 'name' => 'bizcity_kg_usage_log',          'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Cost_Guard', 'feature' => 'billing/quota ledger', 'purpose' => 'Per-operation KG billing/cost ledger consumed by Membership usage reports. Keep SQL — needs owner sign-off before any retention change.', 'readers' => [ 'BizCity_Membership_Usage_Report', 'BizCity_Membership_Usage' ], 'writers' => [ 'BizCity_KG_Cost_Guard::record_usage' ] ],

			// ── core/knowledge (legacy / shared) ──────────────────────────
			// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — catalog the canonical Guru table before policy-column provisioning.
			[ 'name' => 'bizcity_characters',       'owner' => 'core/knowledge', 'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_Knowledge_Database', 'feature' => 'Guru policy', 'purpose' => 'Canonical Twin Guru records and vertical capability policy.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-DUAL-WRITE — catalog the physical suffix used by BizCity_User_Memory.
			[ 'name' => 'bizcity_memory_users',      'owner' => 'core/knowledge',           'group' => 'memory', 'class' => 'BizCity_User_Memory' ],

			// core/knowledge/legal — REMOVED 2026-05-21 (module deleted).
			// Tables moved to deprecated_tables() for auto-drop.

			// ── core/intent ───────────────────────────────────────────────
			[ 'name' => 'bizcity_intent_conversations', 'owner' => 'core/intent',  'group' => 'intent', 'critical' => true ],
			[ 'name' => 'bizcity_intent_turns',         'owner' => 'core/intent',  'group' => 'intent', 'critical' => true ],
			[ 'name' => 'bizcity_intent_todos',         'owner' => 'core/intent',  'group' => 'intent' ],
			// [2026-06-10 Johnny Chu] HOTFIX — bizcity_intent_traces: orphan, no installer, no code references → removed.
			// [2026-06-10 Johnny Chu] HOTFIX — bizcity_intent_tasks:  orphan, no installer, no code references → removed.
			[ 'name' => 'bizcity_intent_classify_cache','owner' => 'core/intent',  'group' => 'intent' ],
			// [2026-06-10 Johnny Chu] HOTFIX — name was bizcity_intent_tool_index (wrong); BizCity_Intent_Tool_Index creates bizcity_tool_registry.
			[ 'name' => 'bizcity_tool_registry',        'owner' => 'core/intent',  'group' => 'intent', 'class' => 'BizCity_Intent_Tool_Index' ],
			// [2026-06-10 Johnny Chu] HOTFIX — name was bizcity_intent_logger (wrong); BizCity_Intent_Logger uses bizcity_intent_logs.
			// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-DUAL-WRITE — align inventory with the intent memory installers.
			[ 'name' => 'bizcity_memory_rolling',       'owner' => 'core/intent',  'group' => 'memory', 'class' => 'BizCity_Rolling_Memory' ],
			[ 'name' => 'bizcity_memory_episodic',      'owner' => 'core/intent',  'group' => 'memory', 'class' => 'BizCity_Episodic_Memory' ],

			// ── core/twin-core ────────────────────────────────────────────
			// [2026-07-29 Johnny Chu] PHASE-1.21-C — register the three active Twin state tables.
			[ 'name' => 'bizcity_twin_prompt_specs',  'owner' => 'core/twin-core', 'group' => 'twin-core', 'critical' => true, 'class' => 'BizCity_Twin_State_Schema', 'feature' => 'objective extraction', 'purpose' => 'One row per user turn: parsed prompt/objectives consumed by the intent pipeline. Retention: 7 days (already active).', 'readers' => [ 'core/intent pipeline' ], 'writers' => [ 'BizCity_Twin_Prompt_Parser' ] ],
			[ 'name' => 'bizcity_twin_milestones',    'owner' => 'core/twin-core', 'group' => 'twin-core', 'class' => 'BizCity_Twin_State_Schema', 'feature' => 'engagement milestones', 'purpose' => 'Analytics/event milestones for Twin Event Bus. Retention: 7 days (already active).', 'readers' => [ 'BizCity_Twin_Event_Bus' ], 'writers' => [ 'BizCity_Twin_Event_Bus' ] ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — annotate twin_context_logs runtime role before staged quarantine tracking.
			[ 'name' => 'bizcity_twin_context_logs',  'owner' => 'core/twin-core', 'group' => 'twin-core', 'class' => 'BizCity_Twin_State_Schema', 'feature' => 'context decision trace', 'purpose' => 'Decision-log stream emitted by Twin Event Bus for focus/suppress/extend/tool decisions. Retention is active in Twin State Schema.', 'readers' => [ 'BizCity_Twin_State_Schema::gc_retention', 'BizCity_Twin_Event_Bus' ], 'writers' => [ 'BizCity_Twin_Event_Bus::record_context_log' ] ],
			// Installer = BizCity_Twin_Event_Stream_Schema::ensure_table (registered
			// in installer-registry as id='event_stream'). The 'class' field is the
			// real schema owner — old value 'BizCity_Twin_Core_Database' was a
			// placeholder that never existed → resolver returned null → no Fix
			// button rendered for the most critical table in the system.
			[ 'name' => 'bizcity_twin_event_stream', 'owner' => 'core/twin-core', 'group' => 'twin-core', 'critical' => true, 'class' => 'BizCity_Twin_Event_Stream_Schema', 'installer' => 'event_stream' ],

			// ── core/memory ───────────────────────────────────────────────
			[ 'name' => 'bizcity_memory',       'owner' => 'core/memory', 'group' => 'memory', 'class' => 'BizCity_Memory_Unified_Installer' ],
			[ 'name' => 'bizcity_memory_specs',  'owner' => 'core/memory', 'group' => 'memory' ],

			// ── core/skills ───────────────────────────────────────────────
			[ 'name' => 'bizcity_skills',          'owner' => 'core/skills',   'group' => 'skills' ],
			[ 'name' => 'bizcity_skill_tool_map',  'owner' => 'core/skills',   'group' => 'skills' ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-AUDIT — core/skills still provisions, writes, and reads skill usage telemetry; keep SQL until a real JSONL reader cutover exists.
			[ 'name' => 'bizcity_skill_logs',    'owner' => 'core/skills',   'group' => 'skills', 'class' => 'BizCity_Skill_Database', 'feature' => 'skill usage telemetry', 'purpose' => 'Usage rows update skill use_count and power get_usage_stats() by skill/mode. Retention: 7 days.', 'readers' => [ 'BizCity_Skill_Database::get_usage_stats' ], 'writers' => [ 'BizCity_Skill_Database::log_usage' ] ],

			// ── core/runtime ──────────────────────────────────────────────
			[ 'name' => 'bizcity_twin_runs',     'owner' => 'core/runtime', 'group' => 'runtime' ],
			[ 'name' => 'bizcity_twin_hil',      'owner' => 'core/runtime', 'group' => 'runtime' ],

			// ── modules/twinweb ─────────────────────────────────────────────
			// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose Twin GPT thread/artifact-job contracts to Diagnostics inventory.
			[ 'name' => 'bizcity_twinweb_threads',       'owner' => 'modules/twinweb', 'group' => 'twinweb', 'critical' => true, 'class' => 'BizCity_TwinWeb_Installer' ],
			[ 'name' => 'bizcity_twinweb_artifact_jobs', 'owner' => 'modules/twinweb', 'group' => 'twinweb', 'critical' => true, 'class' => 'BizCity_TwinWeb_Installer' ],

			// ── core/research ─────────────────────────────────────────────
			[ 'name' => 'bizcity_research_sessions', 'owner' => 'core/research', 'group' => 'research' ],
			[ 'name' => 'bizcity_research_turns',    'owner' => 'core/research', 'group' => 'research' ],
			[ 'name' => 'bizcity_research_ingests',  'owner' => 'core/research', 'group' => 'research' ],

			// ── core/scheduler ────────────────────────────────────────────
			[ 'name' => 'bizcity_crm_events', 'owner' => 'core/scheduler', 'group' => 'scheduler', 'critical' => true ],
			// [2026-08-21 Johnny Chu] AUTOMATION-SCHEMA-INVENTORY — catalog all runtime workflow tables, not only the log projection.
			[ 'name' => 'bizcity_automation_workflows', 'owner' => 'core/automation', 'group' => 'automation', 'critical' => true, 'class' => 'BizCity_Automation_Installer' ],
			[ 'name' => 'bizcity_automation_runs',      'owner' => 'core/automation', 'group' => 'automation', 'critical' => true, 'class' => 'BizCity_Automation_Installer' ],
			[ 'name' => 'bizcity_automation_templates', 'owner' => 'core/automation', 'group' => 'automation', 'class' => 'BizCity_Automation_Installer' ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-CATALOG — automation step
			// logs remain SQL-backed because REST/SSE readers and update paths are active.
			[ 'name' => 'bizcity_automation_logs', 'owner' => 'core/automation', 'group' => 'automation', 'class' => 'BizCity_Automation_Repo_Runs', 'feature' => 'workflow step trace', 'purpose' => 'Mutable per-node execution status/error rows consumed by Automation REST, Twin GPT timeline and SSE mirror. Retention: 7 days; migration gate remains before retirement.', 'readers' => [ 'BizCity_Automation_Repo_Runs::logs', 'BizCity_TwinWeb_REST' ], 'writers' => [ 'BizCity_Automation_Repo_Runs::append_log', 'BizCity_Automation_Repo_Runs::append_log_update' ] ],

			// ── core/channel-gateway ──────────────────────────────────────
			[ 'name' => 'bizcity_channel_messages', 'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Channel_Messages' ],
			// [2026-07-29 Johnny Chu] PHASE-1.21-B — use physical table names and owners, not resolver class names.
			[ 'name' => 'bizcity_channel_bindings', 'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Channel_Binding' ],
			// [2026-08-09 Johnny Chu] R-DCL — register the canonical ZNS automation rules table.
			[ 'name' => 'bizcity_zns_event_rules', 'owner' => 'core/channel-gateway/includes/zns', 'module' => 'modules.zns-automation', 'group' => 'channel', 'class' => 'BizCity_ZNS_Rules_Repo' ],
			[ 'name' => 'global_inbox_admin',        'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Blog_Resolver', 'raw' => true ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-CATALOG — bundled channel
			// tables are active legacy message/audit readers; do not classify as dead.
			[ 'name' => 'bizcity_facebook_bot_logs', 'owner' => 'plugins/bizcity-facebook-bot', 'group' => 'channel', 'class' => 'BizCity_Facebook_Bot_Database', 'feature' => 'Facebook inbound/outbound audit', 'purpose' => 'Legacy Facebook event log still used by bot admin and client-history readers.', 'readers' => [ 'BizCity_Facebook_Bot_Database::get_logs' ], 'writers' => [ 'BizCity_Facebook_Bot_Database::insert_log', 'BizCity_Facebook_Bot_Database::log_event' ] ],
			// [2026-08-21 Johnny Chu] ZALO-SCHEMA-INVENTORY — connected-channel probes query the bot configuration table directly through the bundled owner.
			[ 'name' => 'bizcity_zalo_bots', 'owner' => 'plugins/bizcity-zalo-bot', 'group' => 'channel', 'critical' => true, 'class' => 'BizCity_Zalo_Bot_Database' ],
			[ 'name' => 'bizcity_zalo_bot_logs', 'owner' => 'plugins/bizcity-zalo-bot', 'group' => 'channel', 'class' => 'BizCity_Zalo_Bot_Database', 'feature' => 'Zalo Bot inbound/outbound audit', 'purpose' => 'Legacy Zalo Bot message log still used by memory, admin dashboard and REST readers.', 'readers' => [ 'BizCity_Zalo_Bot_Memory', 'BizCity_Zalo_Bot_REST' ], 'writers' => [ 'BizCity_Zalo_Bot_Webhook_Handler', 'BizCity_Zalo_Bot_REST' ] ],

			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-CATALOG — cleanup audit is
			// append-only operational evidence and needs a JSONL/retention decision.
			[ 'name' => 'bizcity_kg_cleanup_log', 'owner' => 'core/knowledge/kg-hub', 'group' => 'knowledge', 'class' => 'BizCity_KG_Cleanup_Service', 'feature' => 'KG cleanup audit', 'purpose' => 'Weekly/on-demand orphan cleanup actions displayed by the Learning Hub.', 'readers' => [ 'BizCity_KG_Cleanup_Service::list_audit' ], 'writers' => [ 'BizCity_KG_Cleanup_Service::log' ] ],

			// ── core/bizcity-llm ──────────────────────────────────────────
			// [2026-07-29 Johnny Chu] PHASE-1.21-B — usage moved to JSONL; SQL cleanup is catalogued below.

			// ── core/bizcity-market — [2026-06-10 Johnny Chu] HOTFIX: module disabled, tables removed from registry.
			// 5 tables (bizcity_market_plugins/votes/entitlements/hub_rollups/meta) no longer created on client sites.

			// ── modules/twinchat — learning ───────────────────────────────
			[ 'name' => 'bizcity_kg_learning_jobs',    'owner' => 'modules/twinchat/learning', 'group' => 'twinchat', 'critical' => true ],
			[ 'name' => 'bizcity_kg_learning_events',  'owner' => 'modules/twinchat/learning', 'group' => 'twinchat', 'critical' => true ],
			[ 'name' => 'bizcity_kg_learning_batches', 'owner' => 'modules/twinchat/learning', 'group' => 'twinchat' ],

			// ── modules/twinchat — studio ─────────────────────────────────
			[ 'name' => 'bizcity_webchat_studio_jobs', 'owner' => 'modules/twinchat/studio',   'group' => 'twinchat', 'critical' => true ],

			// ── modules/webchat ───────────────────────────────────────────
			[ 'name' => 'bizcity_webchat_projects',      'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_webchat_sessions',      'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_webchat_conversations', 'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_webchat_messages',      'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_webchat_tasks',         'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			// [2026-07-29 Johnny Chu] PHASE-1.21-B — registry suffix matches class-webchat-database.php.
			[ 'name' => 'bizcity_webchat_task_steps',    'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_memory_session',        'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_memory_notes',          'owner' => 'modules/twinchat/notebooklm', 'group' => 'memory', 'class' => 'BizCity_TwinChat_Notes_Service' ],

			// ── plugins/bizcity-twin-crm (selected; CRM ships many tables) ─
			[ 'name' => 'bizcity_crm_inboxes',         'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_contacts',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_conversations',   'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_messages',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_attachments',     'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_labels',          'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_macros',          'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_automation_rules', 'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer_V2' ],
			[ 'name' => 'bizcity_crm_accounts',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_tasks',           'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_documents',       'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer_V2' ],
			[ 'name' => 'bizcity_crm_leads',           'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_opportunities',   'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_contracts',       'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_products',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_invoices',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_campaigns',       'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],

			// [2026-07-29 Johnny Chu] PHASE-1.21-B — bizgpt-tool-google is not shipped in this framework tree; no required rows.

			// ── plugins/bizcity-tool-image ───────────────────────────────
			[ 'name' => 'bztimg_editor_shapes',       'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],
			[ 'name' => 'bztimg_editor_frames',       'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],
			[ 'name' => 'bztimg_editor_fonts',        'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()', 'feature' => 'font catalog', 'purpose' => 'Font family catalog for the image editor font picker. Low write volume; shares an installer with 4 sibling asset tables — do not split into CPT/JSONL alone.' ],
			[ 'name' => 'bztimg_editor_text_presets', 'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],
			[ 'name' => 'bztimg_editor_templates',    'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],

			// ── plugins/bizcity-video-kling ──────────────────────────────
			[ 'name' => 'bizcity_kling_scripts', 'owner' => 'plugins/bizcity-video-kling', 'group' => 'tools', 'class' => 'BizCity_Video_Kling_Scripts' ],
			[ 'name' => 'bizcity_kling_jobs',    'owner' => 'plugins/bizcity-video-kling', 'group' => 'tools', 'class' => 'BizCity_Video_Kling_Job_Monitor' ],

			// ──────────────────────────────────────────────────────────────
			// DROPPED 2026-05-21 (ORPHAN-NO-CODE — verified zero consumer):
			//   bizcity_intent_one_shot, bizcity_kg_characters, bizcity_kg_sources_legacy,
			//   bizcity_persona_subscribers, bizcity_persona_prefs,
			//   bizcity_twin_state_{focus,snapshot,resolver,session,log,kv},
			//   bizcity_twinchat_welcome_jobs, bizcity_twinchat_notes,
			//   bizcity_kling_effects
			// → safe to DROP TABLE IF EXISTS on shards where they were ever created.
		];
	}

	/**
	 * Return the full table registry (seed merged with filter contributions).
	 * Each entry is normalised to include all keys.
	 */
	public static function get_tables(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$tables = apply_filters( 'bizcity_diagnostics_register_tables', self::seed() );
		$out    = [];
		$purpose_by_group = [
			'knowledge' => 'Knowledge graph, source and retrieval data',
			'intent'    => 'Intent conversation, planning and tool state',
			'memory'    => 'Cross-turn memory and recall state',
			'cron'      => 'Cron registry, run, retry and lock state',
			'skills'    => 'Skill library and tool binding',
			'webchat'   => 'WebChat session, message and timeline data',
			'channel'   => 'Channel gateway bindings and messages',
			'crm'       => 'CRM customer, sales and campaign data',
			'runtime'   => 'Twin runtime execution and HIL state',
		];
		foreach ( (array) $tables as $t ) {
			if ( ! is_array( $t ) || empty( $t['name'] ) ) {
				continue;
			}
			$group = (string) ( $t['group'] ?? 'misc' );
			$out[] = [
				'name'     => (string) $t['name'],
				'owner'    => (string) ( $t['owner']    ?? 'unknown' ),
				'group'    => $group,
				'class'    => (string) ( $t['class']    ?? '' ),
				'notes'    => (string) ( $t['notes']    ?? '' ),
				'critical' => (bool)   ( $t['critical'] ?? false ),
				'raw'      => (bool)   ( $t['raw']      ?? false ), // raw=true → no wpdb->prefix
				'module'   => (string) ( $t['module']   ?? $t['owner'] ?? 'unknown' ),
				'feature'  => (string) ( $t['feature']  ?? $group ),
				'purpose'  => (string) ( $t['purpose']  ?? ( $purpose_by_group[ $group ] ?? 'Registered framework data' ) ),
				'readers'  => is_array( $t['readers'] ?? null ) ? array_values( $t['readers'] ) : [],
				'writers'  => is_array( $t['writers'] ?? null ) ? array_values( $t['writers'] ) : [],
			];
		}
		return self::$cache = $out;
	}

	/** Reset cache (tests / after filter changes). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Deprecated / quarantine tables — orphan entries are verified zero-consumer;
	 * quarantine-only entries require owner migration/sign-off before DROP.
	 * Listed here so Diagnostics can track every cleanup candidate on each shard.
	 * EACH entry MUST include:
	 *   - 'name'   : table suffix (without wpdb->prefix), OR raw name when 'raw'=true
	 *   - 'reason' : human-readable why it's orphan
	 *   - 'raw'    : true if name already includes its own prefix
	 *   - 'quarantine_only' : true when owner migration/sign-off must precede DROP
	 *   - 'prefix_scope' : 'blog' (default) or 'base' for network/base-prefix tables
	 *
	 * Modules can extend via filter `bizcity_diagnostics_deprecated_tables`.
	 *
	 * @return array<int,array{name:string,reason:string,raw?:bool,quarantine_only?:bool,prefix_scope?:string,related_tables?:array<int,string>,orphan_gate?:string}>
	 */
	public static function deprecated_tables(): array {
		$seed = [
			[ 'name' => 'bizcity_intent_one_shot',       'reason' => 'No consumer code; no installer' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — legacy table removed from active registry; track before cleanup.
			[ 'name' => 'bizcity_intent_traces',         'reason' => 'QUARANTINE — no active consumer or installer; verify zero rows before DROP.' ],
			[ 'name' => 'bizcity_intent_tasks',          'reason' => 'QUARANTINE — no active consumer or installer; verify zero rows before DROP.' ],
			[ 'name' => 'bizcity_kg_characters',         'reason' => 'No consumer code; legacy KG schema' ],
			[ 'name' => 'bizcity_kg_sources_legacy',     'reason' => 'No consumer code; replaced by bizcity_kg_sources' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — JSONL migration owns export; keep the legacy SQL table tracked until migration evidence is complete.
			[ 'name' => 'bizcity_kg_source_progress_log', 'reason' => 'QUARANTINE — runtime storage moved to JSONL; migrate/export existing rows before DROP.', 'quarantine_only' => true ],
			[ 'name' => 'bizcity_persona_subscribers',   'reason' => 'No consumer code; persona module never landed' ],
			[ 'name' => 'bizcity_persona_prefs',         'reason' => 'No consumer code; persona module never landed' ],
			[ 'name' => 'bizcity_twin_state_focus',      'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_snapshot',   'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_resolver',   'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_session',    'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_log',        'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_kv',         'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twinchat_welcome_jobs', 'reason' => 'No installer + no consumer' ],
			[ 'name' => 'bizcity_twinchat_notes',        'reason' => 'Service writes to bizcity_memory_notes instead' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — legacy Zalo extractor uses base_prefix storage; retain it for migration review, never auto-drop.
			[ 'name' => 'bizcity_zalo_bot_memory',        'reason' => 'QUARANTINE ONLY — legacy ZaloBot daily extractor writes to base-prefix storage; migrate to TwinBrain Memory_Writer before DROP.', 'quarantine_only' => true, 'prefix_scope' => 'base' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — D7 legacy memory set; retain until dual-write/read parity and sign-off are complete.
			[ 'name' => 'bizcity_memory_users',          'reason' => 'QUARANTINE ONLY — D7 legacy memory_users; active writer/read path must be cut over before DROP.', 'quarantine_only' => true ],
			[ 'name' => 'bizcity_memory_episodic',       'reason' => 'QUARANTINE ONLY — D7 legacy memory_episodic; active writer/read path must be cut over before DROP.', 'quarantine_only' => true ],
			[ 'name' => 'bizcity_memory_rolling',        'reason' => 'QUARANTINE ONLY — D7 legacy memory_rolling; active writer/read path must be cut over before DROP.', 'quarantine_only' => true ],
			[ 'name' => 'bizcity_memory_session',        'reason' => 'QUARANTINE ONLY — D7 legacy memory_session; active writer/read path must be cut over before DROP.', 'quarantine_only' => true ],
			[ 'name' => 'bizcity_memory_notes',          'reason' => 'QUARANTINE ONLY — D7 legacy memory_notes; Notes Service dual-write/read parity and sign-off required before DROP.', 'quarantine_only' => true ],
			[ 'name' => 'bizcity_kling_effects',         'reason' => 'Only nonce slug ref; no installer + no wpdb query' ],
			[ 'name' => 'bizcity_twin_identity',         'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_twin_focus_state',      'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_twin_timeline_state',   'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_twin_journeys',         'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_llm_usage_clients',     'reason' => 'PHASE-1.21-B — usage migrated to JSONL; export/verify existing rows before DROP' ],
			[ 'name' => 'bizcity_llm_usage',             'reason' => 'QUARANTINE ONLY — Membership usage report still reads this legacy table; migrate readers and obtain sign-off before DROP.', 'quarantine_only' => true ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — legacy flow name is migration-owned; never treat it as canonical storage.
			[ 'name' => 'bizcity_cg_flows',              'reason' => 'QUARANTINE — legacy interim flow table; verify migration/export before DROP.', 'quarantine_only' => true ],
			// Wave 2.8d (2026-05-24): Memory consolidation audit — see core/memory/PHASE-MEMORY-CONSOLIDATION.md
			[ 'name' => 'bizcity_memory_research',       'reason' => 'DEAD — only migration artifact from bizcity_webchat_research_jobs; no INSERT/SELECT in active code (Wave 2.8d TBR.MEM-D2). Drop scheduled via Site Provisioner.' ],
			// [2026-07-30 Johnny Chu] PHASE-0.6-KG-CLEANUP — no active INSERT/SELECT consumer; retire safely via zero-row orphan cleanup.
			[ 'name' => 'bizcity_kg_mentions',           'module' => 'core/knowledge/kg-hub', 'feature' => 'knowledge graph', 'purpose' => 'Legacy passage/entity graph edges', 'class' => 'BizCity_KG_Database', 'reason' => 'DEAD — declared by the legacy KG source layer but no active INSERT/SELECT consumer; export non-empty rows before DROP.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-TOOL-CATALOG — WebChat timeline now reads the unified registry; export non-empty legacy rows before DROP.
			[ 'name' => 'bizcity_webchat_tools',         'module' => 'modules/webchat', 'feature' => 'tool catalog', 'purpose' => 'Legacy WebChat tool display catalog', 'class' => 'BizCity_Tool_Registry', 'reason' => 'RETIRED — no active writer; timeline metadata now comes from BizCity_Tool_Registry.' ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — stage every active *_log(s) table in quarantine so Orphan Cleaner can track dependency gates before final DROP.
			[ 'name' => 'bizcity_intent_logs',          'module' => 'core/intent', 'feature' => 'pipeline step trace', 'purpose' => 'Retired SQL trace; canonical evidence is JSONL.', 'class' => 'BizCity_Intent_Logger', 'reason' => 'ORPHAN-CANDIDATE — SQL writer/reader retired; drop after seven-day retention leaves zero rows.', 'orphan_gate' => 'Verify JSONL probe PASS and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_intent_prompt_logs',   'module' => 'core/intent', 'feature' => 'per-turn telemetry', 'purpose' => 'Retired SQL prompt telemetry; canonical evidence is JSONL.', 'class' => 'BizCity_Intent_Database', 'reason' => 'ORPHAN-CANDIDATE — SQL writer/reader retired; drop after seven-day retention leaves zero rows.', 'orphan_gate' => 'Verify JSONL reader/stats and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_logs',          'module' => 'core/memory', 'feature' => 'memory mutation audit', 'purpose' => 'Retired SQL projection; source of truth is Twin Event Stream plus JSONL.', 'class' => 'BizCity_Memory_Log_Projector', 'reason' => 'ORPHAN-CANDIDATE — SQL projection/reader retired; drop after seven-day retention leaves zero rows.', 'orphan_gate' => 'Verify memory JSONL/event parity and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_mcp_audit_log',        'module' => 'core/mcp', 'feature' => 'mcp call audit', 'purpose' => 'Retired SQL audit projection; canonical evidence is MCP JSONL.', 'class' => 'BizCity_MCP_Installer', 'reason' => 'ORPHAN-CANDIDATE — SQL audit writer/installer retired; drop after seven-day retention leaves zero rows.', 'orphan_gate' => 'Verify MCP file evidence and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_automation_logs',     'module' => 'core/automation', 'feature' => 'workflow step trace', 'purpose' => 'Mutable per-node run log with active REST/SSE readers; SQL retention is 7 days.', 'class' => 'BizCity_Automation_Repo_Runs', 'reason' => 'ACTIVE-QUARANTINE — seven-day SQL retention is active; retain SQL until Automation REST/SSE readers and log updates have a JSONL parity replacement.', 'quarantine_only' => true, 'orphan_gate' => 'Prove JSONL reader/update parity for run timeline before stopping SQL writes.', 'related_tables' => [ 'bizcity_automation_runs' ] ],
			[ 'name' => 'bizcity_facebook_bot_logs',  'module' => 'plugins/bizcity-facebook-bot', 'feature' => 'Facebook message audit', 'purpose' => 'Legacy Facebook bot event/message log with active admin and client readers.', 'class' => 'BizCity_Facebook_Bot_Database', 'reason' => 'ACTIVE-QUARANTINE — reconcile with Channel Gateway/CRM message evidence before migration or DROP.', 'quarantine_only' => true, 'orphan_gate' => 'Prove Facebook bot admin/history readers no longer depend on SQL and preserve channel correlation.', 'related_tables' => [ 'bizcity_facebook_bots', 'bizcity_facebook_inbox', 'bizcity_crm_messages' ] ],
			[ 'name' => 'bizcity_zalo_bot_logs',       'module' => 'plugins/bizcity-zalo-bot', 'feature' => 'Zalo Bot message audit', 'purpose' => 'Legacy Zalo Bot log consumed by memory, dashboard and REST history paths.', 'class' => 'BizCity_Zalo_Bot_Database', 'reason' => 'ACTIVE-QUARANTINE — reconcile with Channel Gateway/CRM message evidence before migration or DROP.', 'quarantine_only' => true, 'orphan_gate' => 'Prove Zalo memory/admin/REST readers use canonical channel evidence and preserve identity correlation.', 'related_tables' => [ 'bizcity_zalo_bots', 'bizcity_crm_messages' ] ],
			[ 'name' => 'bizcity_google_usage_logs',  'module' => 'plugins/bizgpt-tool-google', 'feature' => 'Google usage audit', 'purpose' => 'Global Google service usage/action audit keyed by blog and user.', 'class' => 'BZGoogle_Installer', 'reason' => 'ACTIVE-QUARANTINE — global usage reader/retention owner has not signed off on migration.', 'quarantine_only' => true, 'prefix_scope' => 'base', 'orphan_gate' => 'Inventory all REST/admin readers, define global retention and prove report parity before SQL retirement.', 'related_tables' => [ 'bizcity_google_accounts' ] ],
			[ 'name' => 'bizcity_kg_cleanup_log',    'module' => 'core/knowledge/kg-hub', 'feature' => 'KG cleanup audit', 'purpose' => 'Append-only orphan cleanup audit currently read by Learning Hub.', 'class' => 'BizCity_KG_Cleanup_Service', 'reason' => 'ACTIVE-QUARANTINE — bounded SQL retention is active; JSONL reader parity is still optional future work.', 'quarantine_only' => true, 'orphan_gate' => 'Migrate list_audit() to JSONL and prove history parity before SQL retirement.', 'related_tables' => [ 'bizcity_kg_triplet_queue', 'bizcity_kg_entities', 'bizcity_kg_relations' ] ],
			[ 'name' => 'bizcity_kg_usage_log',         'module' => 'core/knowledge/kg-hub', 'feature' => 'billing usage ledger', 'purpose' => 'Per-operation KG usage ledger consumed by membership billing reports.', 'class' => 'BizCity_KG_Cost_Guard', 'reason' => 'ACTIVE-QUARANTINE — billing owner sign-off required before any SQL retirement plan.', 'quarantine_only' => true, 'orphan_gate' => 'Require billing/finance sign-off, retention policy approval, and report parity evidence before migration.', 'related_tables' => [ 'bizcity_member_usage', 'bizcity_kg_notebooks' ] ],
			[ 'name' => 'bizcity_twin_context_logs',    'module' => 'core/twin-core', 'feature' => 'twin context decision trace', 'purpose' => 'Context decision log emitted by Twin Event Bus and governed by Twin State retention jobs.', 'class' => 'BizCity_Twin_State_Schema', 'reason' => 'ACTIVE-QUARANTINE — twin-core owner must define JSONL/event-stream replacement reader before SQL retirement.', 'quarantine_only' => true, 'orphan_gate' => 'Need twin-core parity probe proving context decision timeline works without direct SQL table reads.', 'related_tables' => [ 'bizcity_twin_event_stream', 'bizcity_twin_prompt_specs', 'bizcity_twin_milestones' ] ],
			[ 'name' => 'bizcity_skill_logs',            'module' => 'core/skills', 'feature' => 'skill usage telemetry', 'purpose' => 'Active skill usage telemetry; tracked here only for a future JSONL reader cutover.', 'class' => 'BizCity_Skill_Database', 'reason' => 'ACTIVE-QUARANTINE — not orphan; active writer/readers remain in core/skills.', 'quarantine_only' => true, 'orphan_gate' => 'Build JSONL reader for skill power stats, prove parity, then stop SQL log writes without disabling skill use_count updates.', 'related_tables' => [ 'bizcity_skills', 'bizcity_skill_tool_map' ] ],
		];
		$out = apply_filters( 'bizcity_diagnostics_deprecated_tables', $seed );
		$norm = [];
		foreach ( (array) $out as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$norm[] = [
				'name'            => (string) $row['name'],
				'reason'          => (string) ( $row['reason'] ?? '' ),
				'module'          => (string) ( $row['module'] ?? 'deprecated' ),
				'feature'         => (string) ( $row['feature'] ?? 'legacy cleanup' ),
				'purpose'         => (string) ( $row['purpose'] ?? '' ),
				'class'           => (string) ( $row['class'] ?? '' ),
				// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — normalize dependency metadata so Diagnostics/Orphan Cleaner can render relation gates consistently.
				'related_tables'  => array_values( array_filter( array_map( 'strval', is_array( $row['related_tables'] ?? null ) ? $row['related_tables'] : [] ) ) ),
				'orphan_gate'     => (string) ( $row['orphan_gate'] ?? '' ),
				'raw'             => (bool)   ( $row['raw']    ?? false ),
				// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — only entries
				// explicitly marked quarantine_only require owner sign-off; dead entries
				// without that flag may be dropped after the zero-row guard passes.
				'quarantine_only' => (bool)   ( $row['quarantine_only'] ?? false ),
				'prefix_scope'    => (string) ( $row['prefix_scope'] ?? 'blog' ),
			];
		}
		return $norm;
	}
}
