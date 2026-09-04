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
			// [2026-09-02 09:20 PM Johnny Chu - Chu Hoàng Anh] B2C-H3 — inventory the Hub-global license journal; B2 clients must treat this as not applicable.
			[ 'name' => 'bizcity_llm_license_ledger', 'owner' => 'plugins/bizcity-llm-router', 'group' => 'commerce', 'critical' => true, 'feature' => 'license ledger', 'purpose' => 'Global append-only B1 license grant/reversal journal; current entitlement is a projection.', 'lifecycle' => 'hub_only' ],
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
			// [2026-09-02 Johnny Chu] PHASE-CB5.1 — inventory the tenant-scoped rollup lease/checkpoint state owner.
			[ 'name' => 'bizcity_context_bank_rollup_state', 'owner' => 'core/context-bank', 'group' => 'context-bank', 'critical' => true, 'class' => 'BizCity_Context_Bank_Rollup_Worker', 'feature' => 'rollup worker state', 'purpose' => 'Lease and checkpoint metadata for resumable Context Bank rollups; never stores rollup payload.' ],

			// ── core/knowledge (legacy / shared) ──────────────────────────
			// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — catalog the canonical Guru table before policy-column provisioning.
			[ 'name' => 'bizcity_characters',       'owner' => 'core/knowledge', 'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_Knowledge_Database', 'feature' => 'Guru policy', 'purpose' => 'Canonical Twin Guru records and vertical capability policy.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-MEMORY-DUAL-WRITE — catalog the physical suffix used by BizCity_User_Memory.
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-MEMORY-FILESTORE — user memory SQL projection is dead; Context Bank and encrypted filestore own payloads.
			[ 'name' => 'bizcity_memory_users',      'owner' => 'core/knowledge',           'group' => 'memory', 'class' => 'BizCity_User_Memory', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify user-memory filestore/Context Bank parity and COUNT(*)=0 before DROP.' ],

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
			[ 'name' => 'bizcity_memory_rolling',       'owner' => 'core/intent',  'group' => 'memory', 'class' => 'BizCity_Rolling_Memory', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify rolling-memory filestore/Context Bank parity and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_episodic',      'owner' => 'core/intent',  'group' => 'memory', 'class' => 'BizCity_Episodic_Memory', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify episodic-memory filestore/Context Bank parity and COUNT(*)=0 before DROP.' ],

			// ── core/twin-core ────────────────────────────────────────────
			// [2026-07-29 Johnny Chu] PHASE-1.21-C — register the three active Twin state tables.
			[ 'name' => 'bizcity_twin_prompt_specs',  'owner' => 'core/twin-core', 'group' => 'twin-core', 'critical' => true, 'lifecycle' => 'core_active', 'class' => 'BizCity_Twin_State_Schema', 'feature' => 'objective extraction', 'purpose' => 'One row per user turn: parsed prompt/objectives consumed by the intent pipeline. Retention: 7 days (already active).', 'readers' => [ 'core/intent pipeline' ], 'writers' => [ 'BizCity_Twin_Prompt_Parser' ] ],
			[ 'name' => 'bizcity_twin_milestones',    'owner' => 'core/twin-core', 'group' => 'twin-core', 'critical' => true, 'lifecycle' => 'core_active', 'class' => 'BizCity_Twin_State_Schema', 'feature' => 'engagement milestones', 'purpose' => 'Analytics/event milestones for Twin Event Bus. Retention: 7 days (already active).', 'readers' => [ 'BizCity_Twin_Event_Bus' ], 'writers' => [ 'BizCity_Twin_Event_Bus' ] ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — annotate twin_context_logs runtime role before staged quarantine tracking.
			[ 'name' => 'bizcity_twin_context_logs',  'owner' => 'core/twin-core', 'group' => 'twin-core', 'class' => 'BizCity_Twin_State_Schema', 'feature' => 'context decision trace', 'purpose' => 'Retired SQL projection; Twin Event Stream is canonical.', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'readers' => [ 'BizCity_Twin_State_Schema::gc_retention', 'BizCity_Twin_Event_Bus' ], 'writers' => [ 'BizCity_Twin_Event_Bus::record_context_log' ] ],
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
			[ 'name' => 'bizcity_skill_logs',    'owner' => 'core/skills',   'group' => 'skills', 'class' => 'BizCity_Skill_Database', 'feature' => 'skill usage telemetry', 'purpose' => 'Retired SQL projection; JSONL owns usage telemetry while skill state remains SQL.', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'readers' => [ 'BizCity_Skill_Database::get_usage_stats' ], 'writers' => [ 'BizCity_Skill_Database::log_usage' ] ],

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
			// [2026-08-28 Johnny Chu] PHASE-1.30-LIFECYCLE — active readers now consume repository log readers; direct timeline SQL reads were replaced by repo-model access.
			[ 'name' => 'bizcity_automation_logs', 'owner' => 'core/automation', 'group' => 'automation', 'class' => 'BizCity_Automation_Repo_Runs', 'feature' => 'workflow step trace', 'purpose' => 'Retired SQL projection; JSONL owns the workflow timeline.', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'readers' => [ 'BizCity_Automation_Repo_Runs::logs', 'BizCity_Automation_Repo_Runs::log_by_id', 'BizCity_TwinWeb_REST::get_member_automation_run_detail (via repo model)' ], 'writers' => [ 'BizCity_Automation_Repo_Runs::append_log', 'BizCity_Automation_Repo_Runs::append_log_update' ] ],

			// ── core/channel-gateway ──────────────────────────────────────
			[ 'name' => 'bizcity_channel_messages', 'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Channel_Messages' ],
			// [2026-07-29 Johnny Chu] PHASE-1.21-B — use physical table names and owners, not resolver class names.
			[ 'name' => 'bizcity_channel_bindings', 'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Channel_Binding' ],
			// [2026-08-09 Johnny Chu] R-DCL — register the canonical ZNS automation rules table.
			[ 'name' => 'bizcity_zns_event_rules', 'owner' => 'core/channel-gateway/includes/zns', 'module' => 'modules.zns-automation', 'group' => 'channel', 'class' => 'BizCity_ZNS_Rules_Repo' ],
			[ 'name' => 'global_inbox_admin',        'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Blog_Resolver', 'raw' => true ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-CATALOG — bundled channel
			// tables are active legacy message/audit readers; do not classify as dead.
			[ 'name' => 'bizcity_facebook_bot_logs', 'owner' => 'plugins/bizcity-facebook-bot', 'group' => 'channel', 'class' => 'BizCity_Facebook_Bot_Database', 'feature' => 'Facebook inbound/outbound audit', 'purpose' => 'Retired SQL projection; exact facebook channel evidence is canonical JSONL.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'readers' => [ 'BizCity_Facebook_Bot_Database::get_logs' ], 'writers' => [ 'BizCity_Facebook_Bot_Database::insert_log', 'BizCity_Facebook_Bot_Database::log_event' ] ],
			// [2026-08-21 Johnny Chu] ZALO-SCHEMA-INVENTORY — connected-channel probes query the bot configuration table directly through the bundled owner.
			[ 'name' => 'bizcity_zalo_bots', 'owner' => 'plugins/bizcity-zalo-bot', 'group' => 'channel', 'critical' => true, 'class' => 'BizCity_Zalo_Bot_Database' ],
			[ 'name' => 'bizcity_zalo_bot_logs', 'owner' => 'plugins/bizcity-zalo-bot', 'group' => 'channel', 'class' => 'BizCity_Zalo_Bot_Database', 'feature' => 'Zalo Bot inbound/outbound audit', 'purpose' => 'Retired SQL projection; exact zalo_bot Zone 2 evidence is canonical JSONL.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'readers' => [ 'BizCity_Zalo_Bot_REST' ], 'writers' => [ 'BizCity_Zalo_Bot_Webhook_Handler', 'BizCity_Zalo_Bot_REST' ] ],

			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-CATALOG — cleanup audit is
			// append-only operational evidence and needs a JSONL/retention decision.
			[ 'name' => 'bizcity_kg_cleanup_log', 'owner' => 'core/knowledge/kg-hub', 'group' => 'knowledge', 'class' => 'BizCity_KG_Cleanup_Service', 'feature' => 'KG cleanup audit', 'purpose' => 'Retired SQL projection; JSONL owns cleanup audit.', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'readers' => [ 'BizCity_KG_Cleanup_Service::list_audit' ], 'writers' => [ 'BizCity_KG_Cleanup_Service::log' ] ],

			// ── core/bizcity-llm ──────────────────────────────────────────
			// [2026-07-29 Johnny Chu] PHASE-1.21-B — usage moved to JSONL; SQL cleanup is catalogued below.
			// [2026-08-27 Johnny Chu] R-LOG-HYBRID — one rebuildable cross-contract JSONL pointer index; never stores log content.
			[ 'name' => 'bizcity_log_index', 'owner' => 'core/helper', 'group' => 'runtime', 'critical' => false, 'class' => 'BizCity_Log_Index', 'feature' => 'cross-source log search', 'purpose' => 'Tenant-scoped pointers into JSONL date-files for bounded support search; JSONL remains canonical.' ],

			// ── core/bizcity-market — [2026-06-10 Johnny Chu] HOTFIX: module disabled, tables removed from registry.
			// 5 tables (bizcity_market_plugins/votes/entitlements/hub_rollups/meta) no longer created on client sites.

			// ── modules/twinchat — learning ───────────────────────────────
			[ 'name' => 'bizcity_kg_learning_jobs',    'owner' => 'modules/twinchat/learning', 'group' => 'twinchat', 'critical' => true ],
			[ 'name' => 'bizcity_kg_learning_events',  'owner' => 'modules/twinchat/learning', 'group' => 'twinchat', 'critical' => true ],
			[ 'name' => 'bizcity_kg_learning_batches', 'owner' => 'modules/twinchat/learning', 'group' => 'twinchat' ],

			// ── modules/twinchat — studio ─────────────────────────────────
			[ 'name' => 'bizcity_webchat_studio_jobs', 'owner' => 'modules/twinchat/studio',   'group' => 'twinchat', 'critical' => true ],

			// [2026-08-25 Johnny Chu] PHASE-1.29-EXTENSIONS — table ownership follows the moved WebChat extension.
			// [2026-08-25 Johnny Chu] PHASE-1.29-MODULES — WebChat returns to the canonical modules layer.
			// ── modules/webchat ────────────────────────────────────────────
			[ 'name' => 'bizcity_webchat_projects',      'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify TwinWeb/Intent notebook repository parity and COUNT(*)=0 before DROP.' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-SESSION-FILESTORE — schedule the duplicate session-state SQL table for complete encrypted filestore cutover.
			[ 'name' => 'bizcity_webchat_sessions',      'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database', 'lifecycle' => 'quarantine', 'sql_status' => 'active', 'replacement_status' => 'planned', 'orphan_gate' => 'Migrate session state to modules.webchat.session_state filestore, prove parity/zero-growth/zero-row and obtain owner approval before DROP.' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — keep the duplicate header store explicitly quarantined until message-owned parity closes.
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — record verified message-owned replacement while retaining the physical table for cleanup gates.
			[ 'name' => 'bizcity_webchat_conversations', 'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database', 'lifecycle' => 'quarantine', 'sql_status' => 'active', 'replacement_status' => 'verified', 'orphan_gate' => 'Retain rows until zero-growth, zero-row, approval and G5 gates; no production DROP.' ],
			[ 'name' => 'bizcity_webchat_messages',      'owner' => 'core/twin-core', 'group' => 'twin-core', 'critical' => true, 'class' => 'BizCity_TwinChat_Database', 'feature' => 'shared message projection', 'purpose' => 'Retained core-compatible message projection while Twin Event Stream remains canonical event source.', 'lifecycle' => 'core_active', 'related_tables' => [ 'bizcity_twin_event_stream' ] ],
			[ 'name' => 'bizcity_webchat_tasks',         'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify Goal/Event Stream task replacement and COUNT(*)=0 before DROP.' ],
			// [2026-07-29 Johnny Chu] PHASE-1.21-B — registry suffix matches class-webchat-database.php.
			[ 'name' => 'bizcity_webchat_task_steps',    'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify Event Stream task-step timeline replacement and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_session',        'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify Context Bank memory reference and COUNT(*)=0 before DROP.' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-NOTES-FILESTORE — notes SQL projection is retired; canonical Notes Service is filestore-only.
			[ 'name' => 'bizcity_memory_notes',          'owner' => 'modules/twinchat/notebooklm', 'group' => 'memory', 'class' => 'BizCity_TwinChat_Notes_Service', 'lifecycle' => 'retired', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify notes filestore parity and COUNT(*)=0 before DROP.' ],

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
				// [2026-08-25 Johnny Chu] PHASE-1.29-WEBCHAT-QUARANTINE — preserve lifecycle metadata for runtime ownership probes.
				'lifecycle' => (string) ( $t['lifecycle'] ?? '' ),
				'quarantine_only' => ! empty( $t['quarantine_only'] ),
				'orphan_gate' => (string) ( $t['orphan_gate'] ?? '' ),
				'related_tables' => is_array( $t['related_tables'] ?? null ) ? array_values( $t['related_tables'] ) : [],
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
	 * @return array<int,array{name:string,reason:string,raw?:bool,quarantine_only?:bool,prefix_scope?:string,related_tables?:array<int,string>,orphan_gate?:string,jsonl_replacement?:array}>
	 */
	public static function deprecated_tables(): array {
		$seed = [
			[ 'name' => 'bizcity_intent_one_shot',       'reason' => 'No consumer code; no installer' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — legacy table removed from active registry; track before cleanup.
			[ 'name' => 'bizcity_intent_traces',         'reason' => 'QUARANTINE — no active consumer or installer; verify zero rows before DROP.', 'orphan_gate' => 'Intent owner confirms no reader/writer remains and COUNT(*)=0.' ],
			[ 'name' => 'bizcity_intent_tasks',          'reason' => 'QUARANTINE — no active consumer or installer; verify zero rows before DROP.', 'orphan_gate' => 'Intent owner confirms no task reader/writer remains and COUNT(*)=0.' ],
			[ 'name' => 'bizcity_kg_characters',         'reason' => 'No consumer code; legacy KG schema' ],
			[ 'name' => 'bizcity_kg_sources_legacy',     'reason' => 'No consumer code; replaced by bizcity_kg_sources' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — JSONL migration owns export; keep the legacy SQL table tracked until migration evidence is complete.
			[ 'name' => 'bizcity_kg_source_progress_log', 'reason' => 'DEAD SQL TABLE — runtime storage moved to JSONL; retain old rows only for approved cleanup.', 'quarantine_only' => true, 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify JSONL progress rows cover the SQL window, then confirm COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_persona_subscribers',   'reason' => 'No consumer code; persona module never landed' ],
			[ 'name' => 'bizcity_persona_prefs',         'reason' => 'No consumer code; persona module never landed' ],
			[ 'name' => 'bizcity_twin_state_focus',      'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_snapshot',   'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_resolver',   'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_session',    'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_log',        'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twin_state_kv',         'reason' => 'No installer + no consumer (core/twin-core)' ],
			[ 'name' => 'bizcity_twinchat_welcome_jobs', 'reason' => 'No installer + no consumer' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-NOTES-FILESTORE — retain the legacy alias only as migration metadata; the TwinChat notes filestore is the sole owner.
			[ 'name' => 'bizcity_twinchat_notes',        'reason' => 'DEAD SQL TABLE — deprecated alias; TwinChat notes use the canonical encrypted modules.twinchat.memory_notes filestore; no SQL payload owner remains.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify notes filestore parity and COUNT(*)=0 before DROP.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — legacy Zalo extractor uses base_prefix storage; retain it for migration review, never auto-drop.
			[ 'name' => 'bizcity_zalo_bot_memory',        'reason' => 'DEAD SQL TABLE — ZaloBot memory uses the canonical encrypted user-memory filestore; retain existing base-prefix rows for explicit owner-approved cleanup.', 'prefix_scope' => 'base', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify user-memory filestore parity, retain non-empty rows, and obtain Zalo owner sign-off before any DROP plan.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — D7 legacy memory set; retain until dual-write/read parity and sign-off are complete.
			[ 'name' => 'bizcity_memory_users',          'reason' => 'DEAD SQL TABLE — encrypted user-memory filestore plus Context Bank pointer admission/read path is active.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify five-family Context Bank reference and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_episodic',       'reason' => 'DEAD SQL TABLE — encrypted episodic-memory filestore plus Context Bank pointer admission/read path is active.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify five-family Context Bank reference and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_rolling',        'reason' => 'DEAD SQL TABLE — encrypted rolling-memory filestore plus Context Bank pointer admission/read path is active.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify five-family Context Bank reference and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_session',        'reason' => 'DEAD SQL TABLE — encrypted session-memory filestore plus Context Bank pointer admission/read path is active.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify five-family Context Bank reference and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_notes',          'reason' => 'DEAD SQL TABLE — encrypted notes filestore plus Context Bank pointer admission/read path is active.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify five-family Context Bank reference and COUNT(*)=0 before DROP.' ],
			// [2026-08-26 Johnny Chu] PHASE-1.30-CATALOG — include WebChat quarantine tables in the central deprecated-table probe catalog.
			[ 'name' => 'bizcity_webchat_projects',      'reason' => 'QUARANTINE ONLY — WebChat project CRUD requires TwinWeb/Intent replacement parity before DROP.', 'quarantine_only' => true, 'orphan_gate' => 'Prove owner-scoped project replacement and zero active legacy readers before DROP.' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-SESSION-FILESTORE — document full session metadata/state removal from SQL.
			[ 'name' => 'bizcity_webchat_sessions',      'reason' => 'QUARANTINE ONLY — active session metadata/state callers now use encrypted modules.webchat.session_state filestore; retain physical rows for cleanup gates.', 'quarantine_only' => true, 'lifecycle' => 'quarantine', 'sql_status' => 'active', 'replacement_status' => 'verified', 'orphan_gate' => 'Retain rows until multi-request zero-growth, zero-row, owner approval and G5 evidence; no production DROP.' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — deprecate the duplicate conversation header before message-owned migration.
			[ 'name' => 'bizcity_webchat_conversations', 'reason' => 'QUARANTINE ONLY — conversation/session metadata is now served by canonical webchat_messages marker rows.', 'quarantine_only' => true, 'lifecycle' => 'quarantine', 'sql_status' => 'active', 'replacement_status' => 'verified', 'orphan_gate' => 'Retain rows until zero-growth, zero-row, approval and G5 gates; no production DROP.' ],
			[ 'name' => 'bizcity_webchat_tasks',         'reason' => 'QUARANTINE ONLY — WebChat task projection is write-frozen pending Goal/Event Stream replacement.', 'quarantine_only' => true, 'orphan_gate' => 'Prove Goal/Event Stream task replacement and zero active task readers before DROP.' ],
			[ 'name' => 'bizcity_webchat_task_steps',    'reason' => 'QUARANTINE ONLY — WebChat task-step projection is write-frozen pending timeline replacement.', 'quarantine_only' => true, 'orphan_gate' => 'Prove task-step timeline replacement and zero active readers before DROP.' ],
			[ 'name' => 'bizcity_kling_effects',         'reason' => 'Only nonce slug ref; no installer + no wpdb query' ],
			[ 'name' => 'bizcity_twin_identity',         'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_twin_focus_state',      'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_twin_timeline_state',   'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_twin_journeys',         'reason' => 'PHASE-1.21-C — no active consumer; removed from Twin State Schema' ],
			[ 'name' => 'bizcity_llm_usage_clients',     'reason' => 'PHASE-1.21-B — usage migrated to JSONL; export/verify existing rows before DROP' ],
			[ 'name' => 'bizcity_llm_usage',             'reason' => 'DEAD SQL TABLE — client performance/usage telemetry is canonical JSONL and is outside Context Bank memory.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify usage filestore report parity and COUNT(*)=0 before DROP.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — legacy flow name is migration-owned; never treat it as canonical storage.
			[ 'name' => 'bizcity_cg_flows',              'reason' => 'DEAD SQL TABLE — interim flow name is retired; canonical flow ownership is bizcity_crm_flows.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify canonical CRM flow repository parity and COUNT(*)=0 before DROP.' ],
			// Wave 2.8d (2026-05-24): Memory consolidation audit — see core/memory/PHASE-MEMORY-CONSOLIDATION.md
			[ 'name' => 'bizcity_memory_research',       'reason' => 'DEAD — only migration artifact from bizcity_webchat_research_jobs; no INSERT/SELECT in active code (Wave 2.8d TBR.MEM-D2). Drop scheduled via Site Provisioner.' ],
			// [2026-07-30 Johnny Chu] PHASE-0.6-KG-CLEANUP — no active INSERT/SELECT consumer; retire safely via zero-row orphan cleanup.
			[ 'name' => 'bizcity_kg_mentions',           'module' => 'core/knowledge/kg-hub', 'feature' => 'knowledge graph', 'purpose' => 'Legacy passage/entity graph edges', 'class' => 'BizCity_KG_Database', 'reason' => 'DEAD SQL TABLE — no active INSERT/SELECT consumer; no automatic replacement or backfill.', 'sql_status' => 'dead', 'replacement_status' => 'none', 'orphan_gate' => 'Confirm no active owner and COUNT(*)=0 before DROP.' ],
			// [2026-07-31 Johnny Chu] PHASE-1.22-TOOL-CATALOG — WebChat timeline now reads the unified registry; export non-empty legacy rows before DROP.
			[ 'name' => 'bizcity_webchat_tools',         'module' => 'modules/webchat', 'feature' => 'tool catalog', 'purpose' => 'Legacy WebChat tool display catalog', 'class' => 'BizCity_Tool_Registry', 'reason' => 'DEAD SQL TABLE — no active writer; timeline metadata comes from BizCity_Tool_Registry.', 'sql_status' => 'dead', 'replacement_status' => 'active' ],
			// [2026-08-01 Johnny Chu] PHASE-1.24-LOG-ORPHAN-GATE — stage every active *_log(s) table in quarantine so Orphan Cleaner can track dependency gates before final DROP.
			[ 'name' => 'bizcity_intent_logs',          'module' => 'core/intent', 'feature' => 'pipeline step trace', 'purpose' => 'Dead SQL trace table; canonical evidence is JSONL.', 'class' => 'BizCity_Intent_Logger', 'reason' => 'DEAD SQL TABLE — SQL writer/reader retired; canonical replacement is JSONL.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify JSONL probe PASS and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_intent_prompt_logs',   'module' => 'core/intent', 'feature' => 'per-turn telemetry', 'purpose' => 'Dead SQL prompt telemetry table; canonical evidence is JSONL.', 'class' => 'BizCity_Intent_Database', 'reason' => 'DEAD SQL TABLE — SQL writer/reader retired; canonical replacement is JSONL.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify JSONL reader/stats and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_memory_logs',          'module' => 'core/memory', 'feature' => 'memory mutation audit', 'purpose' => 'Dead SQL projection; source of truth is Twin Event Stream plus JSONL.', 'class' => 'BizCity_Memory_Log_Projector', 'reason' => 'DEAD SQL TABLE — SQL projection/reader retired; canonical replacement is Event Stream plus JSONL.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify memory JSONL/event parity and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_mcp_audit_log',        'module' => 'core/mcp', 'feature' => 'mcp call audit', 'purpose' => 'Dead SQL audit projection; canonical evidence is MCP JSONL.', 'class' => 'BizCity_MCP_Installer', 'reason' => 'DEAD SQL TABLE — SQL writer/installer/reader retired; canonical replacement is MCP JSONL.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify MCP file evidence and COUNT(*)=0 before DROP.' ],
			[ 'name' => 'bizcity_automation_logs',     'module' => 'core/automation', 'feature' => 'workflow step trace', 'purpose' => 'Retired per-node SQL log projection; canonical timeline is JSONL.', 'class' => 'BizCity_Automation_Repo_Runs', 'reason' => 'DEAD SQL TABLE — SQL install/read/write paths are blocked; canonical replacement is JSONL workflow trace.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify JSONL timeline parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_automation_runs' ] ],
			[ 'name' => 'bizcity_facebook_bot_logs',  'module' => 'plugins/bizcity-facebook-bot', 'feature' => 'Facebook message audit', 'purpose' => 'Retired Facebook bot SQL log projection.', 'class' => 'BizCity_Facebook_Bot_Database', 'reason' => 'DEAD SQL TABLE — exact facebook channel evidence is canonical JSONL; CRM SQL remains the Inbox source of truth.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify exact facebook account/page JSONL parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_facebook_bots', 'bizcity_facebook_inbox', 'bizcity_crm_messages' ] ],
			[ 'name' => 'bizcity_zalo_bot_logs',       'module' => 'plugins/bizcity-zalo-bot', 'feature' => 'Zalo Bot message audit', 'purpose' => 'Retired Zalo Bot SQL log projection.', 'class' => 'BizCity_Zalo_Bot_Database', 'reason' => 'DEAD SQL TABLE — exact zalo_bot Zone 2 evidence is canonical JSONL; never use for zalo_oa or zalo_personal.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify exact zalo_bot account JSONL parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_zalo_bots', 'bizcity_crm_messages' ] ],
			[ 'name' => 'bizcity_google_usage_logs',  'module' => 'plugins/bizgpt-tool-google', 'feature' => 'Google usage audit', 'purpose' => 'Retired global Google usage SQL projection.', 'class' => 'BZGoogle_REST_API', 'reason' => 'DEAD SQL TABLE — global Google usage audit is canonical JSONL and remains outside CRM/Context Bank memory.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'prefix_scope' => 'base', 'orphan_gate' => 'Verify global JSONL routing, retention/report parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_google_accounts' ] ],
			[ 'name' => 'bizcity_kg_cleanup_log',    'module' => 'core/knowledge/kg-hub', 'feature' => 'KG cleanup audit', 'purpose' => 'Retired append-only SQL cleanup audit projection; canonical audit is JSONL.', 'class' => 'BizCity_KG_Cleanup_Service', 'reason' => 'DEAD SQL TABLE — SQL install/read/write/delete paths are blocked; canonical replacement is JSONL cleanup audit.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify JSONL audit parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_kg_triplet_queue', 'bizcity_kg_entities', 'bizcity_kg_relations' ] ],
			[ 'name' => 'bizcity_kg_usage_log',         'module' => 'core/knowledge/kg-hub', 'feature' => 'billing usage ledger', 'purpose' => 'Per-operation KG usage ledger consumed by membership billing reports.', 'class' => 'BizCity_KG_Cost_Guard', 'reason' => 'ACTIVE-QUARANTINE — billing owner sign-off required before any SQL retirement plan.', 'quarantine_only' => true, 'orphan_gate' => 'Require billing/finance sign-off, retention policy approval, and report parity evidence before migration.', 'related_tables' => [ 'bizcity_member_usage', 'bizcity_kg_notebooks' ] ],
			[ 'name' => 'bizcity_twin_context_logs',    'module' => 'core/twin-core', 'feature' => 'twin context decision trace', 'purpose' => 'Retired context decision SQL projection; canonical timeline is Twin Event Stream.', 'class' => 'BizCity_Twin_State_Schema', 'reason' => 'DEAD SQL TABLE — SQL install/read/write paths are blocked; canonical replacement is Twin Event Stream.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify Event Stream context timeline parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_twin_event_stream', 'bizcity_twin_prompt_specs', 'bizcity_twin_milestones' ] ],
			[ 'name' => 'bizcity_skill_logs',            'module' => 'core/skills', 'feature' => 'skill usage telemetry', 'purpose' => 'Retired skill usage SQL projection; canonical telemetry is JSONL.', 'class' => 'BizCity_Skill_Database', 'reason' => 'DEAD SQL TABLE — SQL install/read/write/delete paths are blocked; canonical replacement is JSONL skill telemetry.', 'sql_status' => 'dead', 'replacement_status' => 'active', 'orphan_gate' => 'Verify JSONL usage stats parity and COUNT(*)=0 before DROP.', 'related_tables' => [ 'bizcity_skills', 'bizcity_skill_tool_map' ] ],
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
				// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — distinguish a dead legacy SQL table from its active replacement owner.
				'sql_status'      => (string) ( $row['sql_status'] ?? '' ),
				'replacement_status' => (string) ( $row['replacement_status'] ?? '' ),
				// [2026-09-01 Johnny Chu] PHASE-CB4.4 — expose memory-reference adapter readiness separately from filestore contract readiness.
				'context_bank_role'       => (string) ( $row['context_bank_role'] ?? '' ),
				'context_bank_adapter'    => (string) ( $row['context_bank_adapter'] ?? '' ),
				'context_bank_ledger'     => (string) ( $row['context_bank_ledger'] ?? '' ),
				'context_bank_probe_id'   => (string) ( $row['context_bank_probe_id'] ?? '' ),
				// [2026-08-27 Johnny Chu] R-LOG-HYBRID — expose one replacement contract for every deprecated row so Diagnostics can distinguish verified JSONL from non-log retirement paths.
				'jsonl_replacement' => self::replacement_spec( (string) $row['name'], $row ),
			];
		}
		return $norm;
	}

	/**
	 * Return the framework replacement target for one deprecated table.
	 *
	 * This is catalog metadata only. Runtime PASS is calculated by the
	 * Orphan Cleaner after checking the registered contract and file evidence.
	 */
	private static function replacement_spec( string $name, array $row = [] ): array {
		$targets = [
			'bizcity_intent_logs' => [ 'mode' => 'jsonl', 'label' => 'JSONL pipeline trace', 'contract_id' => 'core.intent.pipeline_trace', 'folder' => 'bizcity-intent-logs', 'module' => 'pipeline-trace', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_intent_prompt_logs' => [ 'mode' => 'jsonl', 'label' => 'JSONL prompt telemetry', 'contract_id' => 'core.intent.prompt_log', 'folder' => 'bizcity-intent-logs', 'module' => 'prompt-log', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_memory_logs' => [ 'mode' => 'jsonl', 'label' => 'Event Stream + JSONL mutation audit', 'contract_id' => 'core.memory.mutation_audit', 'folder' => 'bizcity-memory-logs', 'module' => 'mutation-audit', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_mcp_audit_log' => [ 'mode' => 'jsonl', 'label' => 'MCP JSONL audit', 'contract_id' => 'core.mcp.audit', 'folder' => 'bizcity-mcp-logs', 'module' => 'audit', 'writer' => 'BizCity_MCP_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_kg_source_progress_log' => [ 'mode' => 'jsonl', 'label' => 'JSONL source progress', 'contract_id' => 'core.knowledge.kg_source_progress', 'folder' => 'bizcity-usage-logs', 'module' => 'kg-source-progress', 'writer' => 'BizCity_KG_Source_Progress_Log', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_llm_usage_clients' => [ 'mode' => 'jsonl', 'label' => 'JSONL client usage', 'contract_id' => 'core.bizcity_llm.client_usage', 'folder' => 'bizcity-usage-logs', 'module' => 'client-usage', 'writer' => 'BizCity_LLM_Usage_File_Log', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_automation_logs' => [ 'mode' => 'jsonl', 'label' => 'JSONL workflow timeline', 'contract_id' => 'core.automation.workflow_trace', 'folder' => 'bizcity-automation-logs', 'module' => 'workflow-trace', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_facebook_bot_logs' => [ 'mode' => 'jsonl', 'label' => 'Channel JSONL: Facebook', 'contract_id' => 'core.channel_gateway.facebook', 'folder' => 'bizcity-channel-logs', 'module' => 'facebook', 'writer' => 'BizCity_Channel_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_zalo_bot_logs' => [ 'mode' => 'jsonl', 'label' => 'Channel JSONL: Zalo Bot', 'contract_id' => 'core.channel_gateway.zalo_bot', 'folder' => 'bizcity-channel-logs', 'module' => 'zalo_bot', 'writer' => 'BizCity_Channel_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			// [2026-09-02 10:55 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-DDV — declare Google usage audit as a global JSONL contract owned by the Google integration.
			'bizcity_google_usage_logs' => [ 'mode' => 'jsonl', 'label' => 'JSONL Google usage audit', 'contract_id' => 'plugins.bizgpt_tool_google.usage_audit', 'folder' => 'bizcity-google-logs', 'module' => 'usage', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity', 'storage_scope' => 'global' ],
			'bizcity_kg_cleanup_log' => [ 'mode' => 'jsonl', 'label' => 'JSONL KG cleanup audit', 'contract_id' => 'core.knowledge.kg_cleanup_audit', 'folder' => 'bizcity-kg-logs', 'module' => 'cleanup', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_skill_logs' => [ 'mode' => 'jsonl', 'label' => 'JSONL skill telemetry', 'contract_id' => 'core.skills.usage_audit', 'folder' => 'bizcity-skill-logs', 'module' => 'usage', 'writer' => 'BizCity_JSONL_File_Logger', 'probe_id' => 'core.legacy_table.jsonl_source_parity' ],
			'bizcity_twin_context_logs' => [ 'mode' => 'event_stream', 'label' => 'Twin Event Stream decision timeline', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_Twin_Event_Bus', 'probe_id' => 'twinbrain.goal_contracts' ],
			'bizcity_memory_users' => [ 'mode' => 'filestore', 'label' => 'Encrypted user memory business filestore', 'contract_id' => 'core.knowledge.user_memory', 'folder' => 'bizcity-memory-data', 'module' => 'user', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.memory.filestore_parity', 'context_bank_role' => 'memory', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — map intent memory rows to focused filestore owner evidence.
			'bizcity_memory_episodic' => [ 'mode' => 'filestore', 'label' => 'Encrypted episodic memory business filestore', 'contract_id' => 'core.intent.episodic_memory', 'folder' => 'bizcity-memory-data', 'module' => 'episodic', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.memory.intent_filestore_parity', 'context_bank_role' => 'memory', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			'bizcity_memory_rolling' => [ 'mode' => 'filestore', 'label' => 'Encrypted rolling memory business filestore', 'contract_id' => 'core.intent.rolling_memory', 'folder' => 'bizcity-memory-data', 'module' => 'rolling', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.memory.intent_filestore_parity', 'context_bank_role' => 'memory', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			'bizcity_memory_session' => [ 'mode' => 'filestore', 'label' => 'Encrypted session memory business filestore', 'contract_id' => 'modules.webchat.session_memory', 'folder' => 'bizcity-memory-data', 'module' => 'session', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.memory.filestore_parity', 'context_bank_role' => 'memory', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			'bizcity_memory_notes' => [ 'mode' => 'filestore', 'label' => 'Encrypted TwinChat notes business filestore', 'contract_id' => 'modules.twinchat.memory_notes', 'folder' => 'bizcity-memory-data', 'module' => 'notes', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.memory.notes_filestore_parity', 'context_bank_role' => 'memory_or_rule_reference', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-ZALO-FILESTORE — map the retired base-prefix table to the canonical user-memory filestore owner.
			'bizcity_zalo_bot_memory' => [ 'mode' => 'filestore', 'label' => 'Canonical ZaloBot user-memory filestore', 'contract_id' => 'core.knowledge.user_memory', 'folder' => 'bizcity-memory-data', 'module' => 'user', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'modules.zalobot.memory_unify', 'context_bank_role' => 'memory', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — declare encrypted WebChat session-state ownership for the retired SQL table.
			'bizcity_webchat_sessions' => [ 'mode' => 'filestore', 'label' => 'Encrypted WebChat session-state filestore', 'contract_id' => 'modules.webchat.session_state', 'folder' => 'bizcity-memory-data', 'module' => 'session-state', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.webchat.session_filestore_parity', 'replacement_status' => 'active', 'sql_status' => 'quarantine' ],
			'bizcity_cg_flows' => [ 'mode' => 'repository', 'label' => 'Native Automation repository', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_Automation_Repo_Workflows', 'probe_id' => 'channel-gateway.flows' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — reserve a dedicated parity probe for message-owned conversation metadata.
			'bizcity_webchat_conversations' => [ 'mode' => 'repository', 'label' => 'Canonical webchat_messages conversation metadata', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_TwinChat_Database / webchat_messages', 'probe_id' => 'core.webchat.conversation_message_unify' ],
			// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-NOTES-FILESTORE — map the legacy TwinChat notes alias to the same canonical filestore as memory_notes.
			'bizcity_twinchat_notes' => [ 'mode' => 'filestore', 'label' => 'Canonical TwinChat notes filestore alias', 'contract_id' => 'modules.twinchat.memory_notes', 'folder' => 'bizcity-memory-data', 'module' => 'notes', 'writer' => 'BizCity_Business_JSONL_File_Store', 'probe_id' => 'core.memory.notes_filestore_parity', 'context_bank_role' => 'memory_or_rule_reference', 'context_bank_adapter' => 'registered', 'context_bank_ledger' => 'bizcity_context_bank', 'context_bank_probe_id' => 'core.context_bank.memory_reference', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
			'bizcity_webchat_projects' => [ 'mode' => 'repository', 'label' => 'TwinWeb/Intent project repository', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'TwinWeb/Intent owner-scoped repository', 'probe_id' => 'core.webchat.sql_lifecycle' ],
			'bizcity_webchat_tasks' => [ 'mode' => 'event_stream', 'label' => 'Goal/Event Stream task replacement', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_Twin_Event_Bus', 'probe_id' => 'core.webchat.sql_lifecycle' ],
			'bizcity_webchat_task_steps' => [ 'mode' => 'event_stream', 'label' => 'Event Stream task-step timeline', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_Twin_Event_Bus', 'probe_id' => 'core.webchat.sql_lifecycle' ],
			// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — map retired WebChat tool catalog to focused canonical registry evidence.
			'bizcity_webchat_tools' => [ 'mode' => 'repository', 'label' => 'Canonical Tool Registry', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_Tool_Registry', 'probe_id' => 'core.webchat.tool_registry_parity' ],
			'bizcity_kg_mentions' => [ 'mode' => 'retire_only', 'label' => 'Retire-only legacy graph table', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => '', 'probe_id' => 'core.legacy_table.callers' ],
			'bizcity_kg_usage_log' => [ 'mode' => 'sql_structural', 'label' => 'SQL billing usage ledger', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => 'BizCity_KG_Cost_Guard', 'probe_id' => 'core.knowledge.kg_usage_ledger_parity' ],
			'bizcity_llm_usage' => [ 'mode' => 'jsonl', 'label' => 'Client LLM usage JSONL telemetry', 'contract_id' => 'core.bizcity_llm.client_usage', 'folder' => 'bizcity-usage-logs', 'module' => 'client-usage', 'writer' => 'BizCity_LLM_Usage_File_Log', 'probe_id' => 'core.bizcity_llm.usage_filestore_parity', 'context_bank_role' => 'not_applicable', 'replacement_status' => 'active', 'sql_status' => 'dead' ],
		];
		// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — default dead-table rows use caller-audit evidence instead of an invented JSONL contract.
		$spec = $targets[ $name ] ?? [ 'mode' => 'retire_only', 'label' => 'No replacement log; retire after zero-row audit', 'contract_id' => '', 'folder' => '', 'module' => '', 'writer' => '', 'probe_id' => 'core.legacy_table.callers' ];
		if ( isset( $row['jsonl_replacement'] ) && is_array( $row['jsonl_replacement'] ) ) {
			$spec = array_merge( $spec, $row['jsonl_replacement'] );
		}
		$spec['status'] = $spec['mode'] === 'jsonl' ? 'pending' : 'not_applicable';
		return $spec;
	}
}
