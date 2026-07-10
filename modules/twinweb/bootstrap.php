<?php
/**
 * TwinWeb — Module Bootstrap
 *
 * Load order:
 *   1. Constants
 *   2. Installer (DB table)
 *   3. Identity helper
 *   4. Page (rewrite + template)
 *   5. REST controller
 *
 * Gates:
 *   - Always-load (public page, REST) — NO admin gate (R-PERF).
 *   - DB installer deferred to plugins_loaded (not file-scope).
 *
 * Rewrite flush: handled via BizCity_Rewrite_Flush_Registry (R-CR.1).
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 */
defined( 'ABSPATH' ) || exit;

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'BIZCITY_TWINWEB_DIR',     __DIR__ . '/' );
define( 'BIZCITY_TWINWEB_URL',     plugin_dir_url( __FILE__ ) );
define( 'BIZCITY_TWINWEB_VERSION', '1.0.0' );

// [2026-06-18 Johnny Chu] PHASE-TWINWEB — bumped to 1.0.1 to flush old ^twin(?:/.*)? rule
// that was hijacking /twin/ (twinshell's URL). Version bump triggers one-time flush via
// BizCity_Rewrite_Flush_Registry on next admin_init. (NEVER use time() here)
define( 'BIZCITY_TWINWEB_REWRITE_VERSION', '1.0.1' );

// ── Includes ──────────────────────────────────────────────────────────────────
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-installer.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-identity.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-page.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-rest.php';
// [2026-06-22 Johnny Chu] PHASE-TWINWEB — projects REST (port from webchat, clean prefix)
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-projects-rest.php';

// ── DB installer (deferred — avoid file-scope DB calls, R-PERF.2) ─────────────
add_action( 'plugins_loaded', function () {
	// [2026-06-17 Johnny Chu] PHASE-TWINWEB — run installer only when needed
	if ( class_exists( 'BizCity_TwinWeb_Installer' ) ) {
		// [2026-06-22 Johnny Chu] PHASE-TWINWEB — also adds project_id column to threads (R-NO-NEW-TABLE)
		BizCity_TwinWeb_Installer::maybe_install();
	}
	// NOTE: BizCity_TwinWeb_Projects_REST uses bizcity_webchat_projects (existing table).
	// No installer call needed — no new table created.
}, 20 );

// ── Public page ───────────────────────────────────────────────────────────────
if ( class_exists( 'BizCity_TwinWeb_Page' ) ) {
	BizCity_TwinWeb_Page::instance()->register();
}

// ── REST routes ───────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function () {
	// [2026-06-17 Johnny Chu] PHASE-TWINWEB — register bizcity-twinweb/v1 routes
	if ( class_exists( 'BizCity_TwinWeb_REST' ) ) {
		BizCity_TwinWeb_REST::instance()->register_routes();
	}
	// [2026-06-22 Johnny Chu] PHASE-TWINWEB — projects REST
	if ( class_exists( 'BizCity_TwinWeb_Projects_REST', false ) ) {
		BizCity_TwinWeb_Projects_REST::instance()->register_routes();
	}
} );

// ── Rewrite flush registry (R-CR.1) ───────────────────────────────────────────
// [2026-06-17 Johnny Chu] PHASE-TWINWEB — register at file-load time (outside hooks)
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'bizcity-twinweb', BIZCITY_TWINWEB_REWRITE_VERSION );
}
