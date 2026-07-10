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
define( 'BIZCITY_PERSONAL_VERSION',        '1.1.0' ); // [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — bumped after W2/W7 deprecation + automation adapter
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

// ── DB installer (R-CR.2, R-DCL) ─────────────────────────────────────────────
// [2026-06-24 Johnny Chu] PHASE-HOME W6 — run on init only when version stale; schema registry
// does the heavy-lifting (BizCity_Schema_Registry fires at admin_init:5).
add_action( 'init', function () {
	// [2026-06-24 Johnny Chu] PHASE-HOME — run installer only on admin / REST / cron contexts
	if ( class_exists( 'BizCity_Personal_Installer' )
		&& (
			is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
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
	// [2026-06-24 Johnny Chu] PHASE-HOME — register bizcity-personal/v1 routes
	if ( class_exists( 'BizCity_Personal_REST' ) ) {
		BizCity_Personal_REST::instance()->register_routes();
	}
	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — register notebook REST routes
	if ( class_exists( 'BizCity_Personal_Notebook_REST' ) ) {
		BizCity_Personal_Notebook_REST::instance()->register_routes();
	}
} );

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
