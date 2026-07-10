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
			[ 'name' => 'bizcity_kg_provenance',         'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_scope_links',        'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_sources',            'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'critical' => true, 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_mentions',           'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_xref',               'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_passage_identities', 'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Database' ],
			[ 'name' => 'bizcity_kg_source_progress_log','owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Source_Progress_Log' ],
			// FIX 2026-05-21: previous seed had `bizcity_kg_cost_guard` — actual table is `bizcity_kg_usage_log` (see BizCity_KG_Cost_Guard::ensure_table()).
			[ 'name' => 'bizcity_kg_usage_log',          'owner' => 'core/knowledge/kg-hub',  'group' => 'knowledge', 'class' => 'BizCity_KG_Cost_Guard' ],

			// ── core/knowledge (legacy / shared) ──────────────────────────
			[ 'name' => 'bizcity_user_memory',       'owner' => 'core/knowledge',           'group' => 'memory' ],

			// core/knowledge/legal — REMOVED 2026-05-21 (module deleted).
			// Tables moved to deprecated_tables() for auto-drop.

			// ── core/intent ───────────────────────────────────────────────
			[ 'name' => 'bizcity_intent_conversations', 'owner' => 'core/intent',  'group' => 'intent', 'critical' => true ],
			[ 'name' => 'bizcity_intent_turns',         'owner' => 'core/intent',  'group' => 'intent', 'critical' => true ],
			[ 'name' => 'bizcity_intent_prompt_logs',   'owner' => 'core/intent',  'group' => 'intent' ],
			[ 'name' => 'bizcity_intent_todos',         'owner' => 'core/intent',  'group' => 'intent' ],
			// [2026-06-10 Johnny Chu] HOTFIX — bizcity_intent_traces: orphan, no installer, no code references → removed.
			// [2026-06-10 Johnny Chu] HOTFIX — bizcity_intent_tasks:  orphan, no installer, no code references → removed.
			[ 'name' => 'bizcity_intent_classify_cache','owner' => 'core/intent',  'group' => 'intent' ],
			// [2026-06-10 Johnny Chu] HOTFIX — name was bizcity_intent_tool_index (wrong); BizCity_Intent_Tool_Index creates bizcity_tool_registry.
			[ 'name' => 'bizcity_tool_registry',        'owner' => 'core/intent',  'group' => 'intent', 'class' => 'BizCity_Intent_Tool_Index' ],
			// [2026-06-10 Johnny Chu] HOTFIX — name was bizcity_intent_logger (wrong); BizCity_Intent_Logger uses bizcity_intent_logs.
			[ 'name' => 'bizcity_intent_logs',          'owner' => 'core/intent',  'group' => 'intent', 'class' => 'BizCity_Intent_Logger' ],
			[ 'name' => 'bizcity_rolling_memory',       'owner' => 'core/intent',  'group' => 'memory' ],
			[ 'name' => 'bizcity_episodic_memory',      'owner' => 'core/intent',  'group' => 'memory' ],

			// ── core/twin-core ────────────────────────────────────────────
			// Installer = BizCity_Twin_Event_Stream_Schema::ensure_table (registered
			// in installer-registry as id='event_stream'). The 'class' field is the
			// real schema owner — old value 'BizCity_Twin_Core_Database' was a
			// placeholder that never existed → resolver returned null → no Fix
			// button rendered for the most critical table in the system.
			[ 'name' => 'bizcity_twin_event_stream', 'owner' => 'core/twin-core', 'group' => 'twin-core', 'critical' => true, 'class' => 'BizCity_Twin_Event_Stream_Schema', 'installer' => 'event_stream' ],

			// ── core/memory ───────────────────────────────────────────────
			[ 'name' => 'bizcity_memory_specs',  'owner' => 'core/memory', 'group' => 'memory' ],
			[ 'name' => 'bizcity_memory_logs',   'owner' => 'core/memory', 'group' => 'memory' ],

			// ── core/skills ───────────────────────────────────────────────
			[ 'name' => 'bizcity_skills',          'owner' => 'core/skills',   'group' => 'skills' ],
			[ 'name' => 'bizcity_skill_tool_map',  'owner' => 'core/skills',   'group' => 'skills' ],
			[ 'name' => 'bizcity_skill_logs',      'owner' => 'core/knowledge','group' => 'skills', 'class' => 'BizCity_Skill_Database' ],

			// ── core/runtime ──────────────────────────────────────────────
			[ 'name' => 'bizcity_twin_runs',     'owner' => 'core/runtime', 'group' => 'runtime' ],
			[ 'name' => 'bizcity_twin_hil',      'owner' => 'core/runtime', 'group' => 'runtime' ],

			// ── core/research ─────────────────────────────────────────────
			[ 'name' => 'bizcity_research_sessions', 'owner' => 'core/research', 'group' => 'research' ],
			[ 'name' => 'bizcity_research_turns',    'owner' => 'core/research', 'group' => 'research' ],
			[ 'name' => 'bizcity_research_ingests',  'owner' => 'core/research', 'group' => 'research' ],

			// ── core/scheduler ────────────────────────────────────────────
			[ 'name' => 'bizcity_crm_events', 'owner' => 'core/scheduler', 'group' => 'scheduler', 'critical' => true ],

			// ── core/channel-gateway ──────────────────────────────────────
			[ 'name' => 'bizcity_channel_messages', 'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Channel_Messages' ],
			[ 'name' => 'bizcity_channel_binding',  'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Channel_Binding' ],
			[ 'name' => 'bizcity_user_resolver',    'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_User_Resolver' ],
			[ 'name' => 'bizcity_blog_resolver',    'owner' => 'core/channel-gateway', 'group' => 'channel', 'class' => 'BizCity_Blog_Resolver' ],

			// ── core/bizcity-llm ──────────────────────────────────────────
			// [2026-06-10 Johnny Chu] R-LLM-USAGE — 2-table pattern:
			//   bizcity_llm_usage_logs    = hub table (base_prefix, owned by bizcity-llm-router). NOT created here.
			//   bizcity_llm_usage_clients = per-blog client table (prefix, owned by core/bizcity-llm).
			[ 'name' => 'bizcity_llm_usage_clients', 'owner' => 'core/bizcity-llm', 'group' => 'llm', 'class' => 'BizCity_LLM_Usage_Clients' ],

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
			[ 'name' => 'bizcity_webchat_steps',         'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_webchat_tools',         'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],
			[ 'name' => 'bizcity_webchat_memory',        'owner' => 'modules/webchat', 'group' => 'webchat', 'class' => 'BizCity_WebChat_Database' ],

			// ── plugins/bizcity-twin-crm (selected; CRM ships many tables) ─
			[ 'name' => 'bizcity_crm_inboxes',         'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_contacts',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_conversations',   'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_messages',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_attachments',     'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_labels',          'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_macros',          'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_rules',           'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_accounts',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_tasks',           'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_docs',            'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_leads',           'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_opportunities',   'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_contracts',       'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_products',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_invoices',        'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],
			[ 'name' => 'bizcity_crm_campaigns',       'owner' => 'plugins/bizcity-twin-crm', 'group' => 'crm', 'class' => 'BizCity_CRM_DB_Installer' ],

			// ── plugins/bizgpt-tool-google ───────────────────────────────
			[ 'name' => 'bizcity_google_accounts', 'owner' => 'plugins/bizgpt-tool-google', 'group' => 'tools', 'class' => 'BizCity_Google_Installer' ],
			[ 'name' => 'bizcity_google_logs',     'owner' => 'plugins/bizgpt-tool-google', 'group' => 'tools', 'class' => 'BizCity_Google_Installer' ],

			// ── plugins/bizcity-tool-image ───────────────────────────────
			[ 'name' => 'bztimg_editor_shapes',       'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],
			[ 'name' => 'bztimg_editor_frames',       'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],
			[ 'name' => 'bztimg_editor_fonts',        'owner' => 'plugins/bizcity-tool-image', 'group' => 'tools', 'class' => 'bztimg_install_editor_asset_tables()' ],
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
		foreach ( (array) $tables as $t ) {
			if ( ! is_array( $t ) || empty( $t['name'] ) ) {
				continue;
			}
			$out[] = [
				'name'     => (string) $t['name'],
				'owner'    => (string) ( $t['owner']    ?? 'unknown' ),
				'group'    => (string) ( $t['group']    ?? 'misc' ),
				'class'    => (string) ( $t['class']    ?? '' ),
				'notes'    => (string) ( $t['notes']    ?? '' ),
				'critical' => (bool)   ( $t['critical'] ?? false ),
				'raw'      => (bool)   ( $t['raw']      ?? false ), // raw=true → no wpdb->prefix
			];
		}
		return self::$cache = $out;
	}

	/** Reset cache (tests / after filter changes). */
	public static function flush(): void {
		self::$cache = null;
	}

	/**
	 * Deprecated / orphan tables — verified zero PHP consumer (audit 2026-05-21).
	 * Listed here so the Orphan Cleaner tool can offer a guarded DROP TABLE on
	 * each shard. EACH entry MUST include:
	 *   - 'name'   : table suffix (without wpdb->prefix), OR raw name when 'raw'=true
	 *   - 'reason' : human-readable why it's orphan
	 *   - 'raw'    : true if name already includes its own prefix
	 *
	 * Modules can extend via filter `bizcity_diagnostics_deprecated_tables`.
	 *
	 * @return array<int,array{name:string,reason:string,raw?:bool}>
	 */
	public static function deprecated_tables(): array {
		$seed = [
			[ 'name' => 'bizcity_intent_one_shot',       'reason' => 'No consumer code; no installer' ],
			[ 'name' => 'bizcity_kg_characters',         'reason' => 'No consumer code; legacy KG schema' ],
			[ 'name' => 'bizcity_kg_sources_legacy',     'reason' => 'No consumer code; replaced by bizcity_kg_sources' ],
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
			[ 'name' => 'bizcity_kling_effects',         'reason' => 'Only nonce slug ref; no installer + no wpdb query' ],
			// Wave 2.8d (2026-05-24): Memory consolidation audit — see core/memory/PHASE-MEMORY-CONSOLIDATION.md
			[ 'name' => 'bizcity_memory_research',       'reason' => 'DEAD — only migration artifact from bizcity_webchat_research_jobs; no INSERT/SELECT in active code (Wave 2.8d TBR.MEM-D2). Drop scheduled via Site Provisioner.' ],
		];
		$out = apply_filters( 'bizcity_diagnostics_deprecated_tables', $seed );
		$norm = [];
		foreach ( (array) $out as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) ) {
				continue;
			}
			$norm[] = [
				'name'   => (string) $row['name'],
				'reason' => (string) ( $row['reason'] ?? '' ),
				'raw'    => (bool)   ( $row['raw']    ?? false ),
			];
		}
		return $norm;
	}
}
