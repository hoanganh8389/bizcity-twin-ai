<?php
/**
 * BizCity Personal — Module Bootstrap
 *
 * Trợ lý cá nhân: Lịch · Việc · Ngân sách · Tài liệu · Nhật ký · Chat
 * Spec: plugins/bizcity-personal/docs/PHASE-0-BIZCITY-HOME-PERSONAL-ASSISTANT.md
 *
 * Load order:
 *   1. Constants
 *   2. Page (rewrite + template)
 *   3. REST controller
 *
 * Gates:
 *   - Always-load (public page, REST) — no admin gate (R-PERF).
 *   - No DB installer for W0 — reuses bizcity_twinweb_threads (no new table).
 *
 * Rewrite flush: handled via BizCity_Rewrite_Flush_Registry (R-CR.1).
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME Wave 0)
 */
defined( 'ABSPATH' ) || exit;

// [2026-06-24 Johnny Chu] PHASE-HOME — module constants
define( 'BIZCITY_PERSONAL_DIR',            __DIR__ . '/' );
define( 'BIZCITY_PERSONAL_URL',            plugin_dir_url( __FILE__ ) );
define( 'BIZCITY_PERSONAL_VERSION',        '1.5.2' ); // [2026-08-25 Johnny Chu] PHASE-PROFILE-PUBLIC-SSE — invalidate bundle: Hero graph now drifts gently after settling ("bay bay").
define( 'BIZCITY_PERSONAL_REWRITE_VERSION', '1.0.0' );

// ── Includes ──────────────────────────────────────────────────────────────────
// [2026-06-24 Johnny Chu] PHASE-HOME — lazy-friendly: page+REST only, no heavy deps at file scope
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-installer.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-page.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-rest.php';
// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — notebook file store + REST controller
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-notebook-file-store.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-notebook-rest.php';
// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — KG service (7-step ingest pipeline)
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-kg-service.php';
// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — automation adapter replaces W2 listener + W7 notifier.
// W2 (class-personal-zalo-listener.php) DEPRECATED — bypassed automation runner. Removed.
// W7 (class-personal-reminder-notifier.php) DEPRECATED — BizCity_Scheduler_Completion_Notifier (core) handles reply. Removed.
require_once BIZCITY_PERSONAL_DIR . 'includes/class-personal-automation-adapter.php';
// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — Profile card registry + canonical REST surface.
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-card-manager.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-bzpb-bridge.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-channel-resolver.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-chat-handler.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-entrypoint-manager.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-contacts-bridge.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-analytics.php';
// [2026-08-23 Johnny Chu] R-CH-FILE-LOG — public Profile traffic must have the canonical daily logger without loading the full Channel Gateway.
if ( ! class_exists( 'BizCity_Channel_File_Logger', false ) ) {
	$_profile_file_logger = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR . 'core/channel-gateway/includes/class-channel-file-logger.php' : '';
	if ( '' !== $_profile_file_logger && is_readable( $_profile_file_logger ) ) {
		require_once $_profile_file_logger;
	}
	unset( $_profile_file_logger );
}
// [2026-08-23 Johnny Chu] PHASE-TBP-6.4 — public Profile transcript uses the existing WebChat message store; load only its lightweight class contract here.
if ( ! class_exists( 'BizCity_WebChat_Database', false ) && defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
	$_profile_webchat_database = BIZCITY_TWIN_AI_DIR . 'modules/webchat/includes/class-webchat-database.php';
	if ( is_readable( $_profile_webchat_database ) ) {
		require_once $_profile_webchat_database;
	}
	unset( $_profile_webchat_database );
}
// [2026-08-25 Johnny Chu] PHASE-1.24 — keep the optional wheel provider from making Profile activation fatal when the vendor adapter artifact is absent.
$_profile_wheel_provider = BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-wheel-provider.php';
if ( is_readable( $_profile_wheel_provider ) ) {
	require_once $_profile_wheel_provider;
}
unset( $_profile_wheel_provider );
// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — repair provider registration after mixed/legacy loader paths so the registry never exposes an empty Mabel catalog.
if ( class_exists( 'BizCity_Profile_Wheel_Provider_Registry' )
	&& class_exists( 'BizCity_Profile_Mabel_Wheel_Provider' )
	&& method_exists( 'BizCity_Profile_Wheel_Provider_Registry', 'get' )
	&& method_exists( 'BizCity_Profile_Wheel_Provider_Registry', 'register' )
	&& ! BizCity_Profile_Wheel_Provider_Registry::get( 'mabel' ) ) {
	BizCity_Profile_Wheel_Provider_Registry::register( new BizCity_Profile_Mabel_Wheel_Provider() );
}
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-wheel-bridge.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-qr-manager.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-vcard-export.php';
require_once BIZCITY_PERSONAL_DIR . 'includes/profile/class-personal-profile-rest.php';
add_action( 'init', function () {
	// [2026-08-21 Johnny Chu] PHASE-TWIN-BRAIN-PROFILE — TBP-4: wire optional Mabel play attribution after WordPress/plugin loading is ready.
		if ( class_exists( 'BizCity_Personal_Profile_Wheel_Bridge' ) ) {
			BizCity_Personal_Profile_Wheel_Bridge::init();
		}
}, 20 );

// ── DB installer (R-CR.2, R-DCL) ─────────────────────────────────────────────
// [2026-06-24 Johnny Chu] PHASE-HOME W6 — run on init only when version stale; schema registry
// does the heavy-lifting (BizCity_Schema_Registry fires at admin_init:5).
add_action( 'init', function () {
	// [2026-06-24 Johnny Chu] PHASE-HOME — run installer only on admin / REST / cron contexts
	if ( class_exists( 'BizCity_Personal_Installer' )
		&& (
			is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/bizcity-profile/v1/' ) )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		)
	) {
		BizCity_Personal_Installer::install();
	}
}, 5 );

// ── Public page (shortcode + template takeover) ────────────────────────────────
if ( class_exists( 'BizCity_Personal_Page' ) ) {
	BizCity_Personal_Page::instance()->register();
}

// ── REST routes ───────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — register canonical bizcity-profile/v1 routes
	if ( class_exists( 'BizCity_Personal_REST' ) ) {
		BizCity_Personal_REST::instance()->register_routes();
	}
	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — register notebook REST routes
	if ( class_exists( 'BizCity_Personal_Notebook_REST' ) ) {
		BizCity_Personal_Notebook_REST::instance()->register_routes();
	}
	// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — register bizcity-profile/v1 Profile routes.
	if ( class_exists( 'BizCity_Personal_Profile_REST' ) ) {
		BizCity_Personal_Profile_REST::instance()->register_routes();
	}
} );

// [2026-08-20 Johnny Chu] PHASE-PROFILE-QR — defer the foundation probe to Diagnostics only.
function bizcity_personal_profile_load_probe() {
	static $loaded = false;
	if ( $loaded ) { return; }
	$loaded = true;
	$probe = BIZCITY_PERSONAL_DIR . 'includes/profile/class-probe-personal-profile.php';
	if ( is_readable( $probe ) ) {
		require_once $probe;
	}
	// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 5 demo-fix evidence probe, same lazy-load gate.
	$probe_wave5 = BIZCITY_PERSONAL_DIR . 'includes/profile/class-probe-personal-profile-wave5.php';
	if ( is_readable( $probe_wave5 ) ) {
		require_once $probe_wave5;
	}
	// [2026-08-21 Johnny Chu] PHASE-PROFILE-QR — Wave 6.2 quick-edit/Page Builder evidence probe.
	$probe_wave62 = BIZCITY_PERSONAL_DIR . 'includes/profile/class-probe-personal-profile-wave62.php';
	if ( is_readable( $probe_wave62 ) ) {
		require_once $probe_wave62;
	}
}

add_action( 'current_screen', function ( $screen ) {
	if ( $screen && false !== strpos( (string) $screen->id, 'bizcity-diagnostics' ) ) {
		bizcity_personal_profile_load_probe();
	}
}, 1 );

add_action( 'rest_api_init', function () {
	if ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/bizcity-diagnostics/' ) ) {
		bizcity_personal_profile_load_probe();
	}
}, 1 );

// ── Rewrite flush registry (R-CR.1) ───────────────────────────────────────────
// [2026-06-24 Johnny Chu] PHASE-HOME — register at file-load time (outside hooks)
// ONE flush via registry on admin_init when version changes. NEVER flush in init.
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'bizcity-personal', BIZCITY_PERSONAL_REWRITE_VERSION );
}

// ── KG-Hub source registration (PHASE-HOME-NOTEBOOKS PATH-B, R-GW-8 §2) ─────
// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — Register 'personal' plugin in KG source registry.
// scope_type='personal_notebook', scope_id = bizcity_personal_notebooks.id.
// list_scopes() returns user's notebooks so BizCity_KG::available_scopes() works.
// Must be outside any hook (file scope) — registry is collected via filter at first use.
add_filter( 'bizcity_kg_register_source_table', static function ( $entries ) {
	if ( ! is_array( $entries ) ) { $entries = array(); }
	if ( ! class_exists( 'BizCity_Personal_KG_Service' ) ) { return $entries; }
	global $wpdb;
	$entries[] = array(
		'slug'              => 'personal',
		'label'             => __( 'Personal Notebook', 'bizcity-twin-ai' ),
		'scope_type'        => 'personal_notebook',
		'parent_fk'         => 'notebook_id',
		'sources_table'     => $wpdb->prefix . 'bizcity_personal_notebook_pages',
		'chunks_table'      => $wpdb->prefix . 'bizcity_personal_notebook_chunks',
		'service_class'     => 'BizCity_Personal_KG_Service',
		'capability'        => 'read',
		'manage_capability' => 'read',
		'icon'              => 'dashicons-book-alt',
	);
	return $entries;
}, 10, 1 );

// ── Automation adapter (PHASE-HOME-ARCH) ────────────────────────────────────
// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — register action blocks into core/automation.
// Replaces W2 Zalo listener (DEPRECATED) and W7 reminder notifier (DEPRECATED).
// Blocks: action.personal_create_task, action.personal_save_finance, action.personal_save_journal
if ( class_exists( 'BizCity_Personal_Automation_Adapter' ) ) {
	BizCity_Personal_Automation_Adapter::init();
}
