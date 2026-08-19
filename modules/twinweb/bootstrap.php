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
define( 'BIZCITY_TWINWEB_REWRITE_VERSION', '1.0.3' ); // [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — refresh direct shell routing for /gpt/mymcp/.

// ── Includes ──────────────────────────────────────────────────────────────────
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-installer.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-identity.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-page.php';
// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — load contract-only tool catalog for intent/artifact planning.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-agent-tool-catalog.php';
// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register real Doc Studio handoff adapters in the canonical tool registry.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-agent-tool-adapters.php';
// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — durable artifact job state store for AT-7 polling/replay.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-artifact-jobs.php';
// [2026-07-19 Johnny Chu] PHASE-TWINWEB-THREADS — unified thread registry foundation for TwinWeb/TwinChat convergence.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-thread-registry.php';
// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — load core subject profile layer before TwinWeb REST/profile surfaces.
$_bizcity_twinweb_subject_profile_layer = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR . 'core/twinbrain/includes/class-twinbrain-subject-profile-layer.php'
	: dirname( __DIR__, 2 ) . '/core/twinbrain/includes/class-twinbrain-subject-profile-layer.php';
if ( is_readable( $_bizcity_twinweb_subject_profile_layer ) ) {
	require_once $_bizcity_twinweb_subject_profile_layer;
}
unset( $_bizcity_twinweb_subject_profile_layer );
// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — load lightweight My Content artifact service for /gpt/ even when full Automation bootstrap is gated.
$_bizcity_twinweb_content_artifact_service = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR . 'core/automation/includes/class-content-artifact-service.php'
	: dirname( __DIR__, 2 ) . '/core/automation/includes/class-content-artifact-service.php';
if ( is_readable( $_bizcity_twinweb_content_artifact_service ) ) {
	require_once $_bizcity_twinweb_content_artifact_service;
	BizCity_Content_Artifact_Service::init();
}
unset( $_bizcity_twinweb_content_artifact_service );
// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — prompt input can reuse ZaloBot keyword workflow matching.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-prompt-automation-bridge.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-rest.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-profile-grounding.php';
// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — persist MPR web citations into the canonical notebook source ledger through a deferred queue.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-citation-source-persistence.php';
BizCity_TwinWeb_Citation_Source_Persistence::init();
// [2026-06-22 Johnny Chu] PHASE-TWINWEB — projects REST (port from webchat, clean prefix)
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-projects-rest.php';
// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — channel binding bootstrap (TWINWEB -> Guru)
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-binding-bootstrap.php';

// ── DB installer (deferred — avoid file-scope DB calls, R-PERF.2) ─────────────
add_action( 'plugins_loaded', function () {
	// [2026-07-31 Johnny Chu] R-PERF/R-MSDB — do not run TwinWeb tenant DDL on ordinary frontend HTML requests.
	$schema_context = is_admin()
		|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
		|| ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/' ) )
		|| ( defined( 'DOING_CRON' ) && DOING_CRON )
		|| ( defined( 'WP_CLI' ) && WP_CLI );
	if ( ! $schema_context ) {
		return;
	}
	// [2026-06-17 Johnny Chu] PHASE-TWINWEB — run installer only when needed
	if ( class_exists( 'BizCity_TwinWeb_Installer' ) ) {
		// [2026-06-22 Johnny Chu] PHASE-TWINWEB — also adds project_id column to threads (R-NO-NEW-TABLE)
		BizCity_TwinWeb_Installer::maybe_install();
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — ensure managed page slug migrates to /gpt/
		// even when DB schema version is already up-to-date.
		BizCity_TwinWeb_Installer::maybe_create_page();
	}
	// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — schedule AT-7.6 durable artifact job poller with R-CRON-META evidence.
	if ( class_exists( 'BizCity_TwinWeb_Artifact_Jobs' ) ) {
		BizCity_TwinWeb_Artifact_Jobs::init_cron();
	}
	// NOTE: BizCity_TwinWeb_Projects_REST uses bizcity_webchat_projects (existing table).
	// No installer call needed — no new table created.
}, 20 );

// ── Public page ───────────────────────────────────────────────────────────────
if ( class_exists( 'BizCity_TwinWeb_Page' ) ) {
	BizCity_TwinWeb_Page::instance()->register();
}
if ( class_exists( 'BizCity_TwinWeb_Profile_Grounding' ) ) {
	BizCity_TwinWeb_Profile_Grounding::init();
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

// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — load DDV probe (deferred: only on diagnostics page or REST)
// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH W2/W3/W4 — also load document_search + citation probes
add_action( 'current_screen', function ( $screen ) {
	if ( $screen && false !== strpos( (string) $screen->id, 'bizcity-diagnostics' ) ) {
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — load FB connect DDV probe for user_id-scope validation.
		$probe_files = array(
			'class-probe-twinweb-channel.php',
			'class-probe-twinweb-document-search.php',
			'class-probe-twinweb-citation.php',
			'class-probe-twinweb-fb-connect.php',
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-CP — DDV for live Control Plane dashboard endpoints.
			'class-probe-twinweb-control-plane-dashboards.php',
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV for Appearance policy + public skin renderer.
			'class-probe-twinweb-appearance.php',
			'class-probe-twinweb-skin-renderer.php',
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV for block/float shortcode surfaces.
			'class-probe-twinweb-shortcode-surfaces.php',
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — DDV for customer profile grounding.
			'class-probe-twinweb-customer-profile-grounding.php',
		);
		foreach ( $probe_files as $fname ) {
			$path = BIZCITY_TWINWEB_DIR . 'includes/' . $fname;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}
}, 1 );
add_action( 'rest_api_init', function () {
	if ( ! empty( $_SERVER['REQUEST_URI'] )
		&& false !== strpos( (string) $_SERVER['REQUEST_URI'], '/bizcity-diagnostics/' ) ) {
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — load FB connect DDV probe for REST smoke runs.
		$probe_files = array(
			'class-probe-twinweb-channel.php',
			'class-probe-twinweb-document-search.php',
			'class-probe-twinweb-citation.php',
			'class-probe-twinweb-fb-connect.php',
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-CP — DDV for live Control Plane dashboard endpoints.
			'class-probe-twinweb-control-plane-dashboards.php',
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV for Appearance policy + public skin renderer.
			'class-probe-twinweb-appearance.php',
			'class-probe-twinweb-skin-renderer.php',
			// [2026-07-18 Johnny Chu] PHASE-TWINWEB-UI-SKINS — DDV for block/float shortcode surfaces.
			'class-probe-twinweb-shortcode-surfaces.php',
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — DDV for customer profile grounding.
			'class-probe-twinweb-customer-profile-grounding.php',
		);
		foreach ( $probe_files as $fname ) {
			$path = BIZCITY_TWINWEB_DIR . 'includes/' . $fname;
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}
	}
}, 1 );

// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — canonical Twin GPT brand; keep technical code twinweb/TWINWEB.
add_filter( 'bizcity_channel_platform_catalog', function ( array $catalog ) {
	foreach ( $catalog as $item ) {
		if ( isset( $item['code'] ) && (string) $item['code'] === 'twinweb' ) {
			return $catalog;
		}
	}

	$catalog[] = array(
		'code'     => 'twinweb',
		'label'    => 'Twin GPT',
		'platform' => 'TWINWEB',
		'icon'     => 'globe',
		'group'    => 'admin',
		'zone'     => 'admin',
		'ready'    => true,
		'desc'     => 'Twin GPT - khong gian AI public cho user/guest lam viec voi Guru va tai lieu.',
	);

	return $catalog;
}, 20 );
