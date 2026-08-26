<?php
/**
 * Bizcity Twin AI — TwinChat Module Bootstrap
 *
 * Phase 0.5 Sprint 4 — TwinChat Workspace.
 *
 * Loads the TwinChat experience layer:
 *  - REST namespace `bizcity-twinchat/v1`
 *  - SSE chat streaming endpoint
 *  - Per-message persistence (twinchat_messages table)
 *  - WP Admin page hosting the React workspace
 *
 * Governing rule: PHASE-0-RULE-BRAIN-UNIFICATION.md (10 Contracts).
 *  - C1: KG access only via BizCity_KG_Graph_Service / KG_Retriever
 *  - C2: LLM only via BizCity_LLM_Client (Smart Gateway)
 *  - C5: Cost Guard checked BEFORE every LLM call
 *  - C6: scope_type/scope_id propagated to every KG write
 *  - C7: TwinChat inherits Twin-core session state
 *  - C9: kg_summary present in payload
 *
 * PHP 7.4 compatible — no match, no readonly, no enums, no nullsafe (?->).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_TWINCHAT_DIR' ) ) {
	define( 'BIZCITY_TWINCHAT_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_TWINCHAT_URL' ) ) {
	define( 'BIZCITY_TWINCHAT_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'BIZCITY_TWINCHAT_VERSION' ) ) {
	define( 'BIZCITY_TWINCHAT_VERSION', '0.5.5' );
}
if ( ! defined( 'BIZCITY_TWINCHAT_INCLUDES' ) ) {
	define( 'BIZCITY_TWINCHAT_INCLUDES', BIZCITY_TWINCHAT_DIR . 'includes/' );
}
if ( ! defined( 'BIZCITY_TWINCHAT_UI_DIR' ) ) {
	define( 'BIZCITY_TWINCHAT_UI_DIR', BIZCITY_TWINCHAT_DIR . 'ui/' );
}
if ( ! defined( 'BIZCITY_TWINCHAT_REST_NS' ) ) {
	define( 'BIZCITY_TWINCHAT_REST_NS', 'bizcity-twinchat/v1' );
}

// [2026-08-25 Johnny Chu] R-SAFE-LOADER — bootstrap the canonical loader only through a guarded core artifact path.
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = defined( 'BIZCITY_TWIN_AI_DIR' )
		? BIZCITY_TWIN_AI_DIR . 'core/helper/class-bizcity-safe-loader.php'
		: dirname( dirname( __DIR__ ) ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}

// [2026-08-07 Johnny Chu] R-PERF - the wp-admin TwinChat page renders only a TwinShell iframe; defer the full backend stack to REST/iframe requests.
$_bizcity_twinchat_admin_shell_only = is_admin()
	&& isset( $_GET['page'] )
	&& in_array( sanitize_key( (string) $_GET['page'] ), array( 'bizcity-twinchat', 'bizcity-twinbrain' ), true );
if ( $_bizcity_twinchat_admin_shell_only ) {
	BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-admin-menu.php', 'twinchat.admin_menu' );
	add_action( 'admin_menu', static function () {
		BizCity_TwinChat_Admin_Menu::instance()->register();
	}, 20 );
	return;
}

// ── Includes ──────────────────────────────────────────────────────────────
// [2026-08-25 Johnny Chu] R-SAFE-LOADER — optional/deployable TwinChat artifacts fail gracefully when absent or unreadable.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-database.php', 'twinchat.database' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-sources-database.php', 'twinchat.sources_database' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-chunker.php', 'twinchat.chunker' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-sources-service.php', 'twinchat.sources_service' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-context-builder.php', 'twinchat.context_builder' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-stream-handler.php', 'twinchat.stream_handler' );
// [2026-07-14 Johnny Chu] PHASE-0.43 — core keyword search engine for notebook/all-notebook document search.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-search-engine.php', 'twinchat.search_engine' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-rest-controller.php', 'twinchat.rest_controller' );
// PHASE-0.41 L6 (R-GW) — Entitlement proxy: client FE → this proxy → gateway.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-entitlement-proxy.php', 'twinchat.entitlement_proxy' );
// PHASE-0.41 L7 (R-GW-8) — Search proxy: wraps search/router/v1/{query,extract}.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-search-proxy.php', 'twinchat.search_proxy' );
// PHASE-0.42 — LiteParse sidecar health (status pill in AddSourceDialog).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-liteparse-health.php', 'twinchat.liteparse_health' );
// Wave 9 — Brain Workspace tabs aggregator (history / integrations / plans).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'workspace/class-twinchat-workspace-rest.php', 'twinchat.workspace_rest' );
// NotebookLM-parity surface (notes / pin / suggestion-click) — see modules/twinchat/notebooklm/README.md
// Service must load before controller (controller depends on BizCity_TwinChat_Notes_Service).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_DIR . 'notebooklm/includes/class-twinchat-notes-service.php', 'twinchat.notes_service' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_DIR . 'notebooklm/includes/class-twinchat-notes-controller.php', 'twinchat.notes_controller' );
// PHASE-6.4-KGHub-IMAGE Wave B — context-bundle endpoint feeding embedded Doc/Image Studio tabs.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_DIR . 'notebooklm/includes/class-twinchat-context-bundle-controller.php', 'twinchat.context_bundle_controller' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-public-page.php', 'twinchat.public_page' );

// Phase 0.7 / Wave D0 — Pro Learning Diagnostic (admin Tools page).
// Loads in admin context only; safe at all times since the class self-registers
// its admin_menu hook only when first accessed (singleton).
if ( is_admin() ) {
	BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'diagnostics/class-pro-learning-diagnostic.php', 'twinchat.pro_learning_diagnostic' );
}

// Phase 0.7 — Studio (port of BCN_Studio for TwinChat notebook scope).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'studio/class-twinchat-studio-input-builder.php', 'twinchat.studio_input_builder' );
// Phase 0.8 — Job Manager: generic async job layer (must load before studio class).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'studio/class-studio-job-manager.php', 'twinchat.studio_job_manager' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'studio/class-twinchat-studio.php', 'twinchat.studio' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'studio/class-twinchat-studio-rest.php', 'twinchat.studio_rest' );
// Wave 0.7.D — built-in Mindmap tool (Graph-RAG markmap output).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'studio/class-twinchat-studio-tools-mindmap.php', 'twinchat.studio_tools_mindmap' );

// Phase 4.9 — backend learning pipeline + SSE.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-database.php', 'twinchat.learning_database' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-events.php', 'twinchat.learning_events' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-quota-cooldown.php', 'twinchat.quota_cooldown' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-job-queue.php', 'twinchat.learning_job_queue' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-notifier.php', 'twinchat.learning_notifier' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-pipeline.php', 'twinchat.learning_pipeline' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-stream.php', 'twinchat.learning_stream' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-extractor-bridge.php', 'twinchat.learning_extractor_bridge' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-aggregator.php', 'twinchat.learning_aggregator' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-sweep-cron.php', 'twinchat.learning_sweep_cron' );
// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK — stateless share-link adapter (public console-log link + automation action block).
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-learning-share-adapter.php', 'twinchat.learning_share_adapter' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'learning/class-twinchat-rest-learning.php', 'twinchat.rest_learning' );

// Sprint 5.1 — AI Welcome Message after Upload.
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'welcome/class-twinchat-welcome-database.php', 'twinchat.welcome_database' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'welcome/class-twinchat-welcome-job-queue.php', 'twinchat.welcome_job_queue' );
BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'welcome/class-twinchat-welcome-runner.php', 'twinchat.welcome_runner' );

if ( is_admin() ) {
	BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-admin-menu.php', 'twinchat.admin_menu' );
	BizCity_Safe_Loader::require_file( BIZCITY_TWINCHAT_INCLUDES . 'class-twinchat-settings-page.php', 'twinchat.settings_page' );
}

// ── Boot ──────────────────────────────────────────────────────────────────
add_action( 'init', static function () {
	if ( class_exists( 'BizCity_TwinChat_Database' ) ) {
		BizCity_TwinChat_Database::instance()->maybe_install();
	}
	if ( class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
		BizCity_TwinChat_Sources_Database::instance()->maybe_install();
	}
	if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
		BizCity_TwinChat_Learning_Database::instance()->maybe_install();
	}
	if ( class_exists( 'BizCity_TwinChat_Welcome_Database' ) ) {
		BizCity_TwinChat_Welcome_Database::instance()->maybe_install();
	}
	if ( class_exists( 'BizCity_Studio_Job_Manager' ) ) {
		BizCity_Studio_Job_Manager::maybe_install();
	}
	// Bind background workers / hooks.
	if ( class_exists( 'BizCity_TwinChat_Learning_Pipeline' ) ) {
		BizCity_TwinChat_Learning_Pipeline::bind();
	}
	if ( class_exists( 'BizCity_TwinChat_Learning_Notifier' ) ) {
		BizCity_TwinChat_Learning_Notifier::bind();
	}
	if ( class_exists( 'BizCity_TwinChat_Learning_Extractor_Bridge' ) ) {
		BizCity_TwinChat_Learning_Extractor_Bridge::bind();
	}
	// Wave A — TwinShell Learning Hub: ghost-chunk sweep cron (15min/blog).
	if ( class_exists( 'BizCity_TwinChat_Learning_Sweep_Cron' ) ) {
		BizCity_TwinChat_Learning_Sweep_Cron::bind();
	}
}, 6 );

// Register TwinChat as a KG-Hub source provider (PHASE-0-RULE-KG-HUB-CONTRACT.md §2).
add_filter( 'bizcity_kg_register_source_table', static function ( $entries ) {
	if ( ! is_array( $entries ) ) {
		$entries = [];
	}
	$entries[] = [
		'slug'              => 'twinchat',
		'label'             => __( 'TwinChat Notebook', 'bizcity-twin-ai' ),
		'scope_type'        => 'notebook',
		'parent_fk'         => 'project_id',  // webchat_sources uses project_id column
		'sources_table'     => BizCity_TwinChat_Sources_Database::instance()->table_sources(),
		'chunks_table'      => BizCity_TwinChat_Sources_Database::instance()->table_source_chunks(),
		'service_class'     => 'BizCity_TwinChat_Sources_Service',
		'capability'        => 'read',
		'manage_capability' => 'edit_posts',
		'icon'              => 'dashicons-format-chat',
	];
	return $entries;
}, 10, 1 );

add_action( 'rest_api_init', static function () {
	BizCity_TwinChat_REST_Controller::instance()->register_routes();
	if ( class_exists( 'BizCity_TwinChat_Entitlement_Proxy' ) ) {
		BizCity_TwinChat_Entitlement_Proxy::instance()->register_routes();
	}
	if ( class_exists( 'BizCity_TwinChat_Search_Proxy' ) ) {
		BizCity_TwinChat_Search_Proxy::instance()->register_routes();
	}
	if ( class_exists( 'BizCity_TwinChat_LiteParse_Health' ) ) {
		BizCity_TwinChat_LiteParse_Health::instance()->register_routes();
	}
	BizCity_TwinChat_REST_Learning::instance()->register_routes();
	BizCity_TwinChat_Notes_Controller::instance()->register_routes();
	BizCity_TwinChat_Context_Bundle_Controller::instance()->register_routes();
	BizCity_TwinChat_Studio_REST::instance()->register_routes();
	if ( class_exists( 'BizCity_TwinChat_Workspace_REST' ) ) {
		BizCity_TwinChat_Workspace_REST::instance()->register_routes();
	}
} );

/**
 * Cascade-clean TwinChat sources + chunks when a KG notebook is removed via library trash.
 * Fires BEFORE KG-Hub wipes its own rows so passages can still join back to source ids if needed.
 */
add_action( 'bizcity_kg_notebook_before_delete', static function ( $notebook_id ) {
	$notebook_id = (int) $notebook_id;
	if ( $notebook_id <= 0 || ! class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
		return;
	}
	BizCity_TwinChat_Sources_Database::instance()->delete_for_notebook( $notebook_id );
	// [2026-07-27 Johnny Chu] PHASE-0.51 — remove learning ring/job/batch
	// rows for this notebook to avoid orphan telemetry after notebook delete.
	if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
		BizCity_TwinChat_Learning_Database::instance()->delete_for_notebook( $notebook_id );
	}
}, 10, 1 );

// Wave 0.7.D — boot built-in Studio tool callbacks (registers via bcn_register_notebook_tools).
add_action( 'init', static function () {
	if ( class_exists( 'BizCity_TwinChat_Studio_Tools_Mindmap' ) ) {
		BizCity_TwinChat_Studio_Tools_Mindmap::instance();
	}
}, 5 );

// Wave 0.7.D — async studio background worker (WP-Cron / Action Scheduler).
// NOTE: Use string literal (not BizCity_TwinChat_Studio::HOOK_RUN) so a missing class file
// during partial deploys / OPcache race never causes a top-level fatal.
add_action( 'bizcity_twinchat_studio_run', static function ( $output_id, $notebook_id, $tool_type, $user_id, $opts = [] ) {
	// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — queued Studio callbacks
	// must not enter the production worker during diagnostics CLI.
	if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
		return;
	}
	if ( class_exists( 'BizCity_TwinChat_Studio' ) ) {
		BizCity_TwinChat_Studio::instance()->run_background_job( $output_id, $notebook_id, $tool_type, $user_id, $opts );
	}
}, 10, 5 );

// Sprint 5.1 — schedule welcome job after each TwinChat source ingest.
// Fires alongside the existing learning auto-enqueue listener — both are
// independent so a slow learning extract does not delay the welcome bubble.
add_action( 'bizcity_twinchat_after_ingest', static function ( $scope_id, $user_id, $result, $payload ) {
	if ( ! is_array( $result ) || empty( $result['source_id'] ) ) {
		return;
	}
	if ( ! empty( $result['duplicate'] ) ) {
		return; // dedup — the source's welcome already fired previously.
	}
	if ( ! class_exists( 'BizCity_TwinChat_Welcome_Job_Queue' ) ) {
		error_log( '[twinchat-welcome] listener: queue class missing, abort' );
		return;
	}
	$res = BizCity_TwinChat_Welcome_Job_Queue::instance()->enqueue( [
		'notebook_id' => (int) $scope_id,
		'source_id'   => (int) $result['source_id'],
		'user_id'     => (int) $user_id,
	] );
	if ( is_wp_error( $res ) ) {
		error_log( sprintf( '[twinchat-welcome] enqueue skipped (nb=%d, src=%d): %s',
			(int) $scope_id, (int) $result['source_id'], $res->get_error_message() ) );
	} else {
		error_log( sprintf( '[twinchat-welcome] enqueued job#%d (nb=%d, src=%d)',
			(int) $res, (int) $scope_id, (int) $result['source_id'] ) );
	}
}, 9, 4 ); // priority 9 — run BEFORE learning enqueue so welcome row exists first.

// Sprint 5.1 — async worker callback.
// NOTE: Use string literal (not BizCity_TwinChat_Welcome_Job_Queue::HOOK_RUN) — same reason as above.
add_action( 'bizcity_twinchat_welcome_run', static function ( $job_id ) {
	// [2026-08-20 Johnny Chu] HOTFIX-DIAGNOSTICS-CLI — do not execute queued
	// production welcome jobs while the headless diagnostics runner is alive.
	if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
		return;
	}
	if ( class_exists( 'BizCity_TwinChat_Welcome_Runner' ) ) {
		BizCity_TwinChat_Welcome_Runner::instance()->run_job( (int) $job_id );
	}
}, 10, 1 );

// Phase 4.9 — auto-enqueue a learning job after every TwinChat source ingest.
add_action( 'bizcity_twinchat_after_ingest', static function ( $scope_id, $user_id, $result, $payload ) {
	if ( ! is_array( $result ) || empty( $result['source_id'] ) ) {
		return;
	}
	if ( ! empty( $result['duplicate'] ) ) {
		return; // dedup hit — passages already learned previously
	}
	if ( ! class_exists( 'BizCity_TwinChat_Learning_Job_Queue' ) ) {
		return;
	}
	$title = isset( $payload['title'] ) ? (string) $payload['title'] : '';
	if ( $title === '' && ! empty( $payload['file']['name'] ) ) {
		$title = (string) $payload['file']['name'];
	}
	if ( $title === '' && ! empty( $payload['url'] ) ) {
		$title = (string) $payload['url'];
	}
	BizCity_TwinChat_Learning_Job_Queue::instance()->enqueue( [
		'notebook_id'  => (int) $scope_id,
		'source_id'    => (int) $result['source_id'],
		'source_title' => $title,
		'user_id'      => (int) $user_id,
	] );
}, 10, 4 );

if ( is_admin() ) {
	add_action( 'admin_menu', static function () {
		BizCity_TwinChat_Admin_Menu::instance()->register();
	}, 9 );
	// R-1API-9: register the unified BizCity API & Gateway settings page
	// as a submenu of the TwinChat parent menu.
	if ( class_exists( 'BizCity_TwinChat_Settings_Page' ) ) {
		BizCity_TwinChat_Settings_Page::instance()->register();
	}
}

// ── Public frontend page — /twinchat/ ────────────────────────────────────
if ( class_exists( 'BizCity_TwinChat_Public_Page' ) ) {
	BizCity_TwinChat_Public_Page::instance()->register();
}

// [2026-06-09 Johnny Chu] R-CR — migrated to Central Rewrite Flush Registry.
// Registry fires ONE consolidated flush_rewrite_rules(false) at admin_init:1.
BizCity_Rewrite_Flush_Registry::register( 'twinchat', BIZCITY_TWINCHAT_VERSION );

// One-time flush if the option was never set (handles already-active installs).
// Kept as safety net for installs upgrading before the registry ran.
add_action( 'admin_init', static function () {
	if ( ! get_option( BizCity_TwinChat_Public_Page::OPTION_KEY ) ) {
		flush_rewrite_rules( false );
		update_option( BizCity_TwinChat_Public_Page::OPTION_KEY, 1 );
	}
} );

// Default listener for KG cost alert (Contract 5 supplement).
add_action( 'bizcity_kg_cost_alert_80', static function ( $spent, $cap ) {
	$admin_email = get_option( 'admin_email' );
	if ( empty( $admin_email ) ) {
		return;
	}
	$subject = sprintf( '[BizCity KG] Daily cost alert — 80%% reached ($%.2f / $%.2f)', (float) $spent, (float) $cap );
	$body    = "Knowledge Graph daily LLM/embedding spend has crossed 80% of the configured cap.\n\n"
	         . "Spent today: \$" . number_format( (float) $spent, 4 ) . "\n"
	         . "Daily cap:   \$" . number_format( (float) $cap, 4 ) . "\n\n"
	         . "Adjust at: " . admin_url( 'admin.php?page=bizcity-kg-settings' );
	wp_mail( $admin_email, $subject, $body );
}, 10, 2 );
