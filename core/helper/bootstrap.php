<?php
/**
 * Core Helper — Bootstrap
 *
 * [2026-06-05 Johnny Chu] R-ERROR-UX — loads BizCity_Error_Payload and any
 * future shared helper utilities from core/helper/.
 *
 * Load order: early in bizcity-twin-ai.php, before channel-gateway and
 * automation, so all REST controllers can use the helper.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Core\Helper
 * @since      3.1.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'BIZCITY_HELPER_LOADED' ) ) {
	return;
}
define( 'BIZCITY_HELPER_LOADED', true );

$_helper_includes = __DIR__ . '/includes/';

// [2026-08-25 Johnny Chu] PHASE-1.24 — expose the shared guarded artifact loader before optional module bootstraps require PHP files.
$_helper_safe_loader = __DIR__ . '/class-bizcity-safe-loader.php';
if ( is_file( $_helper_safe_loader ) && is_readable( $_helper_safe_loader ) ) {
	require_once $_helper_safe_loader;
}
unset( $_helper_safe_loader );

// [2026-09-02  Johnny Chu - Chu Hoàng Anh] R-SAFE-LOADER — degrade cleanly when the guarded loader artifact is missing or unreadable during a partial deployment.
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	error_log( '[BizCity_Helper] safe_loader_unavailable' );
	return;
}

// [2026-06-05 Johnny Chu] R-ERROR-UX — canonical error payload builder
BizCity_Safe_Loader::require_file( $_helper_includes . 'class-bizcity-error-payload.php', 'helper.error_payload' );

// [2026-08-20 Johnny Chu] CODEC-CORE — shared base64url, JSON state, authenticated payload, and legacy crypto primitives.
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-codec.php', 'helper.codec' );

// [2026-09-02 17:40 PM Johnny Chu - Chu Hoàng Anh] HOTFIX — load the phone identity contract before the helper can be booted by an alternate entrypoint.
$_helper_framework_contracts = dirname( __DIR__ ) . '/twin-core/contracts/framework-contracts.php';
if ( is_file( $_helper_framework_contracts ) && is_readable( $_helper_framework_contracts ) ) {
	BizCity_Safe_Loader::require_file( $_helper_framework_contracts, 'twin_core.framework_contract' );
}
unset( $_helper_framework_contracts );

// [2026-08-11 Johnny Chu] PHASE-CRM-CONTACTS-UNIFY-WOO-USERPOINTS — canonical phone identity normalizer.
BizCity_Safe_Loader::require_file( $_helper_includes . 'class-bizcity-phone-normalizer.php', 'helper.phone_normalizer' );

// [2026-08-01 Johnny Chu] PHASE-LOG-SPLIT — per-blog JSONL logs for CRM and memory.
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-jsonl-file-logger.php', 'helper.jsonl_logger' );
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-log-contract-registry.php', 'helper.log_contract_registry' );
// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — load the shared encrypted business-record filestore before memory owners boot.
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-file-contract-registry.php', 'helper.file_contract_registry' );
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-business-jsonl-file-store.php', 'helper.business_jsonl_file_store' );
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-log-explorer.php', 'helper.log_explorer' );
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-log-index.php', 'helper.log_index' );
// [2026-08-29 Johnny Chu] PHASE-1.30-DDV — retry idempotent R-CR registration after guarded preload so an early compatibility loader cannot leave bizcity_log_index absent from Schema Registry.
if ( class_exists( 'BizCity_Log_Index', false ) && method_exists( 'BizCity_Log_Index', 'register_schema' ) ) {
	BizCity_Log_Index::register_schema();
}
// [2026-08-26 Johnny Chu] PHASE-LEGACY-TABLES — load the central quarantine/install/drop policy before legacy callers and installers run.
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-legacy-table-policy.php', 'helper.legacy_table_policy' );
if ( class_exists( 'BizCity_File_Contract_Registry' ) ) {
	BizCity_File_Contract_Registry::register( 'core.intent.episodic_memory', array(
		'owner_module'       => 'core/intent',
		'label'              => 'Episodic memory business records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'episodic',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_memory_episodic' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — rolling-memory business state is canonical in encrypted JSONL during legacy SQL retirement.
	BizCity_File_Contract_Registry::register( 'core.intent.rolling_memory', array(
		'owner_module'       => 'core/intent',
		'label'              => 'Rolling memory business records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'rolling',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_memory_rolling' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — user-memory owner writes/reads durable business rows through encrypted JSONL.
	BizCity_File_Contract_Registry::register( 'core.knowledge.user_memory', array(
		'owner_module'       => 'core/knowledge',
		'label'              => 'User memory business records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'user',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_memory_users' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — WebChat session-memory extraction records are canonicalized in contract-backed filestore.
	BizCity_File_Contract_Registry::register( 'modules.webchat.session_memory', array(
		'owner_module'       => 'modules/webchat',
		'label'              => 'WebChat session memory business records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'session',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_memory_session' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-SPEC-FILESTORE — session working briefs leave webchat_sessions SQL columns and use encrypted folded records.
	BizCity_File_Contract_Registry::register( 'modules.webchat.session_memory_spec', array(
		'owner_module'       => 'modules/webchat',
		'label'              => 'WebChat session working-brief records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'session-spec',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_webchat_sessions' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — move WebChat session metadata/state out of SQL into encrypted folded records.
	BizCity_File_Contract_Registry::register( 'modules.webchat.session_state', array(
		'owner_module'       => 'modules/webchat',
		'label'              => 'WebChat session state business records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'session-state',
		'record_key'         => 'record_id',
		'schema_version'     => '1.0',
		'related_sql_tables' => array( 'bizcity_webchat_sessions' ),
		'retention_days'     => 3650,
		'storage_scope'      => 'blog',
		'status'             => 'active',
	) );
	// [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — TwinChat notes are canonical encrypted business records during legacy SQL retirement.
	BizCity_File_Contract_Registry::register( 'modules.twinchat.memory_notes', array(
		'owner_module'       => 'modules/twinchat',
		'label'              => 'TwinChat notes business records',
		'folder'             => 'bizcity-memory-data',
		'module'             => 'notes',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_memory_notes' ),
		'retention_days'     => 3650,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-01 Johnny Chu] PHASE-CB4.1 — Event Stream business projections use a separate encrypted contract; the canonical event table remains the source of truth.
	BizCity_File_Contract_Registry::register( 'core.twin_core.context_bank_event', array(
		'owner_module'       => 'core/twin-core',
		'label'              => 'Twin Event Stream Context Bank projections',
		'folder'             => 'bizcity-context-bank',
		'module'             => 'event-stream',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_twin_event_stream' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-01 Johnny Chu] PHASE-CB4.2 — register the existing Zone 1 CRM archive as a pointer source; archive content remains canonical.
	BizCity_File_Contract_Registry::register( 'core.channel_gateway.context_corpus', array(
		'owner_module'       => 'core/channel-gateway',
		'label'              => 'CRM conversation encrypted archive',
		'folder'             => 'bizcity-channel-conversations',
		'module'             => 'conversation',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_crm_messages', 'bizcity_crm_archive_receipts' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-02 Johnny Chu] PHASE-CB4.3 — register encrypted Woo lifecycle records; WooCommerce remains the canonical commerce owner.
	BizCity_File_Contract_Registry::register( 'core.context_bank.commerce_order', array(
		'owner_module'       => 'core/context-bank',
		'label'              => 'WooCommerce order lifecycle Context Bank records',
		'folder'             => 'bizcity-context-bank',
		'module'             => 'commerce-order',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'posts', 'woocommerce_order_items' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-03 Johnny Chu] PHASE-CB5.1 - register durable rollup outputs before the worker writes encrypted JSONL receipts.
	BizCity_File_Contract_Registry::register( 'core.context_bank.rollup', array(
		'owner_module'       => 'core/context-bank',
		'label'              => 'Context Bank bounded rollup projections',
		'folder'             => 'bizcity-context-bank',
		'module'             => 'rollup',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_context_bank', 'bizcity_context_bank_rollup_state' ),
		'retention_days'     => 365,
		'storage_scope'      => 'blog',
	) );
	// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.5 — register Skill version references separately from the canonical Skill content owner.
	BizCity_File_Contract_Registry::register( 'core.skills.rule_reference', array(
		'owner_module'       => 'core/skills',
		'label'              => 'Skill version reference records',
		'folder'             => 'bizcity-context-bank',
		'module'             => 'skill-reference',
		'record_key'         => 'record_id',
		'related_sql_tables' => array( 'bizcity_skills' ),
		'retention_days'     => 3650,
		'storage_scope'      => 'blog',
	) );
}

if ( class_exists( 'BizCity_Log_Contract_Registry' ) ) {
	BizCity_Log_Contract_Registry::register( 'core.intent.pipeline_trace', array(
		'owner_module'       => 'core/intent',
		'label'              => 'Intent pipeline trace',
		'jsonl_folder'       => 'bizcity-intent-logs',
		'jsonl_module'       => 'pipeline-trace',
		'related_sql_tables' => array( 'bizcity_intent_logs' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.intent.prompt_log', array(
		'owner_module'       => 'core/intent',
		'label'              => 'Intent prompt telemetry',
		'jsonl_folder'       => 'bizcity-intent-logs',
		'jsonl_module'       => 'prompt-log',
		'related_sql_tables' => array( 'bizcity_intent_prompt_logs' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.memory.mutation_audit', array(
		'owner_module'       => 'core/memory',
		'label'              => 'Memory mutation audit',
		'jsonl_folder'       => 'bizcity-memory-logs',
		'jsonl_module'       => 'mutation-audit',
		'related_sql_tables' => array( 'bizcity_memory_logs' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.mcp.audit', array(
		'owner_module'       => 'core/mcp',
		'label'              => 'MCP request audit',
		'jsonl_folder'       => 'bizcity-mcp-logs',
		'jsonl_module'       => 'audit',
		'related_sql_tables' => array( 'bizcity_mcp_audit_log' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.knowledge.kg_source_progress', array(
		'owner_module'       => 'core/knowledge/kg-hub',
		'label'              => 'KG source progress',
		'jsonl_folder'       => 'bizcity-usage-logs',
		'jsonl_module'       => 'kg-source-progress',
		'related_sql_tables' => array( 'bizcity_kg_source_progress_log' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	// [2026-08-28 Johnny Chu] PHASE-1.30-LIFECYCLE — register KG cleanup audit as a canonical JSONL contract before SQL retirement.
	BizCity_Log_Contract_Registry::register( 'core.knowledge.kg_cleanup_audit', array(
		'owner_module'       => 'core/knowledge/kg-hub',
		'label'              => 'KG cleanup audit',
		'jsonl_folder'       => 'bizcity-kg-logs',
		'jsonl_module'       => 'cleanup',
		'related_sql_tables' => array( 'bizcity_kg_cleanup_log' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.bizcity_llm.client_usage', array(
		'owner_module'       => 'core/bizcity-llm',
		'label'              => 'Client LLM usage',
		'jsonl_folder'       => 'bizcity-usage-logs',
		'jsonl_module'       => 'client-usage',
		// [2026-09-01 Johnny Chu] R-LLM-USAGE-FILESTORE — one client filestore contract replaces both legacy client usage projections.
		'related_sql_tables' => array( 'bizcity_llm_usage_clients', 'bizcity_llm_usage' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	// [2026-08-28 Johnny Chu] PHASE-1.30-LIFECYCLE — register bundled Google usage audit contract for active-quarantine dual-write/read parity.
	BizCity_Log_Contract_Registry::register( 'plugins.bizgpt_tool_google.usage_audit', array(
		'owner_module'       => 'plugins/bizgpt-tool-google',
		'label'              => 'Google usage audit',
		'jsonl_folder'       => 'bizcity-google-logs',
		'jsonl_module'       => 'usage',
		'related_sql_tables' => array( 'bizcity_google_usage_logs' ),
		'retention_days'     => 7,
		'indexed'            => true,
		'storage_scope'      => 'global',
	) );
	// [2026-08-28 Johnny Chu] PHASE-1.30-LIFECYCLE — register skill usage audit contract for active-quarantine dual-write/read parity.
	BizCity_Log_Contract_Registry::register( 'core.skills.usage_audit', array(
		'owner_module'       => 'core/skills',
		'label'              => 'Skill usage audit',
		'jsonl_folder'       => 'bizcity-skill-logs',
		'jsonl_module'       => 'usage',
		'related_sql_tables' => array( 'bizcity_skill_logs' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.cron.run_evidence', array(
		'owner_module'       => 'core/cron',
		'label'              => 'Cron run evidence',
		'jsonl_folder'       => 'bizcity-cron-logs',
		'jsonl_module'       => 'run-evidence',
		'related_sql_tables' => array( 'bizcity_cron_runs' ),
		'retention_days'     => 5,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.knowledge.notebook_bridge', array(
		'owner_module'       => 'core/knowledge/kg-hub',
		'label'              => 'KG notebook bridge lifecycle',
		'jsonl_folder'       => 'bizcity-notebook-bridge-logs',
		'jsonl_module'       => 'capture-lifecycle',
		'related_sql_tables' => array(),
		'retention_days'     => 14,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.automation.workflow_trace', array(
		'owner_module'       => 'core/automation',
		'label'              => 'Automation workflow trace',
		'jsonl_folder'       => 'bizcity-automation-logs',
		'jsonl_module'       => 'workflow-trace',
		'related_sql_tables' => array( 'bizcity_automation_logs' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.twinbrain.goal_contract_trace', array(
		'owner_module'       => 'core/twinbrain',
		'label'              => 'TwinBrain goal contract trace',
		'jsonl_folder'       => 'bizcity-twinbrain-logs',
		'jsonl_module'       => 'goal-contracts',
		'related_sql_tables' => array( 'bizcity_twin_goal_contracts' ),
		'retention_days'     => 30,
		'segments_template'  => array( 'goal-contracts', '{goal_hash}' ),
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.twinbrain.goal_loop_trace', array(
		'owner_module'       => 'core/twinbrain',
		'label'              => 'TwinBrain goal loop trace',
		'jsonl_folder'       => 'bizcity-twinbrain-logs',
		'jsonl_module'       => 'twinbrain-goal-loop',
		'related_sql_tables' => array(),
		'retention_days'     => 30,
		'segments_template'  => array( 'twinbrain-goal-loop', '{platform}', '{client_hash}' ),
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.channel_gateway.cf7_audit', array(
		'owner_module'       => 'core/channel-gateway',
		'label'              => 'CF7 channel audit',
		'jsonl_folder'       => 'bizcity-crm-logs',
		'jsonl_module'       => 'cf7',
		'related_sql_tables' => array(),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.channel_gateway.zns_audit', array(
		'owner_module'       => 'core/channel-gateway',
		'label'              => 'ZNS channel audit',
		'jsonl_folder'       => 'bizcity-crm-logs',
		'jsonl_module'       => 'zns',
		'related_sql_tables' => array(),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.intent.episodic_memory_trace', array(
		'owner_module'       => 'core/intent',
		'label'              => 'Episodic memory migration trace',
		'jsonl_folder'       => 'bizcity-memory-logs',
		'jsonl_module'       => 'episodic-memory',
		'related_sql_tables' => array( 'bizcity_memory_episodic' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.intent.rolling_memory_trace', array(
		'owner_module'       => 'core/intent',
		'label'              => 'Rolling memory migration trace',
		'jsonl_folder'       => 'bizcity-memory-logs',
		'jsonl_module'       => 'rolling-memory',
		'related_sql_tables' => array( 'bizcity_memory_rolling' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.knowledge.user_memory_trace', array(
		'owner_module'       => 'core/knowledge',
		'label'              => 'User memory operational trace',
		'jsonl_folder'       => 'bizcity-memory-logs',
		'jsonl_module'       => 'user-memory',
		'related_sql_tables' => array( 'bizcity_memory_users' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.knowledge.kg_skeleton_trace', array(
		'owner_module'       => 'core/knowledge/kg-hub',
		'label'              => 'KG notebook skeleton trace',
		'jsonl_folder'       => 'bizcity-kg-logs',
		'jsonl_module'       => 'skeleton',
		'related_sql_tables' => array(),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.twin_core.event_stream_trace', array(
		'owner_module'       => 'core/twin-core',
		'label'              => 'Twin Event Stream operational trace',
		'jsonl_folder'       => 'bizcity-twin-core-logs',
		'jsonl_module'       => 'event-stream',
		'related_sql_tables' => array( 'bizcity_twin_event_stream' ),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	BizCity_Log_Contract_Registry::register( 'core.twinbrain.runtime_trace', array(
		'owner_module'       => 'core/twinbrain',
		'label'              => 'TwinBrain runtime trace',
		'jsonl_folder'       => 'bizcity-twinbrain-logs',
		'jsonl_module'       => 'runtime',
		'related_sql_tables' => array(),
		'retention_days'     => 7,
		'indexed'            => true,
	) );
	// [2026-08-27 Johnny Chu] PHASE-0-RULE-LOG-HYBRID-CANON — register the existing
	// R-CH-FILE-LOG per-channel folders. BizCity_Channel_File_Logger already writes
	// bizcity-channel-logs/{channel}/YYYY-MM-DD.jsonl, which matches the canonical
	// {folder}/{module}/date.jsonl shape read by BizCity_JSONL_File_Logger; write
	// logic is untouched, this only makes the sources discoverable/readable via
	// the shared Log Explorer instead of requiring a bespoke per-channel viewer.
	$_cg_channel_sql_map = array(
		'email'           => array(),
		'facebook'        => array( 'bizcity_facebook_bot_logs' ),
		'messenger'       => array(),
		'zalo_oa'         => array(),
		'zalo_bot'        => array( 'bizcity_zalo_bot_logs' ),
		'zalo_personal'   => array(),
		'zalo_zns'        => array(),
		'telegram'        => array(),
		'webchat'         => array(),
		'cf7'             => array(),
		'channel_gateway' => array(),
		'astro'           => array(),
	);
	foreach ( $_cg_channel_sql_map as $_cg_channel => $_cg_related_tables ) {
		BizCity_Log_Contract_Registry::register( 'core.channel_gateway.' . $_cg_channel, array(
			'owner_module'       => 'core/channel-gateway',
			'label'              => 'Channel Gateway — ' . $_cg_channel,
			'jsonl_folder'       => 'bizcity-channel-logs',
			'jsonl_module'       => $_cg_channel,
			'related_sql_tables' => $_cg_related_tables,
			'retention_days'     => 7,
			'indexed'            => true,
		) );
	}
	unset( $_cg_channel_sql_map, $_cg_channel, $_cg_related_tables );
}
// [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — register one bounded
// sweep for shared JSONL evidence folders after all cron owners are loaded.
if ( class_exists( 'BizCity_JSONL_File_Logger', false ) ) {
	// [2026-09-02 07:45 AM Johnny Chu - Chu Hoàng Anh] R-SAFE-LOADER — do not register callbacks for an unavailable optional logger artifact.
	add_action( 'init', array( 'BizCity_JSONL_File_Logger', 'register_retention_cron' ), 20 );
	add_action( 'bizcity_jsonl_retention', array( 'BizCity_JSONL_File_Logger', 'gc_standard_logs' ), 10, 0 );
}
// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — one event_uuid/trace_id/
// parent_event_uuid contract shared by Channel JSONL and Twin Event Stream.
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-chat-correlation.php', 'helper.chat_correlation' );

// [2026-06-09 Johnny Chu] R-CACHE — unified two-tier cache helper (object cache + transients)
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-cache.php', 'helper.cache' );

// [2026-06-21 Johnny Chu] R-CACHE — Central Cache Registry (catalog of all groups)
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-cache-registry.php', 'helper.cache_registry' );
if ( class_exists( 'BizCity_Log_Index' ) && class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'bzlogidx', 'core.helper', array(
		'search_{blog_db_args_hash}' => array( 'ttl' => 60, 'desc' => 'Bounded current-tenant canonical log pointer search.' ),
	) );
}

// [2026-08-29 Johnny Chu] R-METADATA-CACHE — load one canonical tenant/database-aware metadata contract.
BizCity_Safe_Loader::require_file( __DIR__ . '/class-bizcity-table-metadata.php', 'helper.table_metadata' );

if ( ! function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
	function bizcity_context_bank_load_memory_runtime() {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — defer Context Bank ledger/adapter loading until a memory boundary is actually used.
		if ( class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) ) {
			return true;
		}
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( __DIR__, 2 ) . '/';
		$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
		if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $bootstrap ) || ! is_readable( $bootstrap ) ) {
			return false;
		}
		BizCity_Safe_Loader::require_file( $bootstrap, 'context_bank.memory_runtime' );
		return class_exists( 'BizCity_Context_Bank_Memory_Adapter' );
	}
}

unset( $_helper_includes );
