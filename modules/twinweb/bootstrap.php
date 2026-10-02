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
define( 'BIZCITY_TWINWEB_REWRITE_VERSION', '1.0.10' ); // [2026-09-30 Claude Opus 5.5] PHASE-0.84 D-84-13 — drop /gpt/twinchat/ rewrite. Previously: [2026-09-18] PHASE-0.52 — add /gpt/myspace/ + /gpt/mycustomers/ (1.0.8: /gpt/mytasks/). Previously: [2026-09-07 03:45 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.48D — catalog all static Twin GPT public subpaths in one route contract.

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
// [2026-10-01 Claude Opus 5.5] CORE-REDUCTION WP-16 B-4 S3a (R-LEAN-4, Q-W16-1) — the layer moved with TwinBrain into the add-on.
$_bizcity_twinweb_subject_profile_layer = class_exists( 'BizCity_Addon_Locator', false )
	? BizCity_Addon_Locator::file( 'twinbrain/includes/class-twinbrain-subject-profile-layer.php' )
	: '';
if ( '' !== $_bizcity_twinweb_subject_profile_layer && is_readable( $_bizcity_twinweb_subject_profile_layer ) ) {
	require_once $_bizcity_twinweb_subject_profile_layer;
}
unset( $_bizcity_twinweb_subject_profile_layer );
// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — load lightweight My Content artifact service for /gpt/ even when full Automation bootstrap is gated.
// [2026-10-01 Claude Opus 5.5] CORE-REDUCTION WP-16 B-4 S2 (R-LEAN-4, Q-W16-1) — the service moved with Automation into the add-on.
$_bizcity_twinweb_content_artifact_service = class_exists( 'BizCity_Addon_Locator', false )
	? BizCity_Addon_Locator::file( 'automation/includes/class-content-artifact-service.php' )
	: '';
if ( '' !== $_bizcity_twinweb_content_artifact_service && is_readable( $_bizcity_twinweb_content_artifact_service ) ) {
	require_once $_bizcity_twinweb_content_artifact_service;
	if ( class_exists( 'BizCity_Content_Artifact_Service', false ) ) {
		BizCity_Content_Artifact_Service::init();
	}
}
unset( $_bizcity_twinweb_content_artifact_service );
// [2026-07-22 Johnny Chu] PHASE-3-TWIN-GPT — prompt input can reuse ZaloBot keyword workflow matching.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-prompt-automation-bridge.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-rest.php';
// [2026-08-25 Johnny Chu] PHASE-0.39F-F8 — load the member-safe CRM projection contract before TwinWeb REST handlers.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-crm-projection.php';
// [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 M4-02 — member-customer-360 serializer (C Customer 360).
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-member-customer-360.php';
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-profile-grounding.php';
// [2026-07-31 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — persist MPR web citations into the canonical notebook source ledger through a deferred queue.
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-citation-source-persistence.php';
BizCity_TwinWeb_Citation_Source_Persistence::init();
// [2026-06-22 Johnny Chu] PHASE-TWINWEB — projects REST (port from webchat, clean prefix)
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-projects-rest.php';
// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 W5 — member "Việc được giao" REST (C surface, current user only).
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-crm-tasks-rest.php';
// [2026-09-18] PHASE-0.52 — member customer pipeline REST (Hôm nay, Khách của tôi, Inbox stage, Không gian của tôi).
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-crm-pipeline-rest.php';
// [2026-09-19] PHASE-0.55 A2 — Brain Chat "work.*" retriever tools (member's own tasks/pipeline/space, no colleague data).
require_once BIZCITY_TWINWEB_DIR . 'includes/class-twinweb-crm-work-tools.php';
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

// ── Twin Shell ActivityBar entry ──────────────────────────────────────────────
// [2026-09-30 Claude Opus 5.5] PHASE-0.84 W-02 — the site owner and staff open Twin GPT inside /twin/.
// The iframe still loads /gpt/, so every request stays the C surface (R-LM §1.1: the URL decides the role).
add_filter( 'bizcity_twin_register_plugins', static function ( $plugins ) {
	if ( ! is_array( $plugins ) ) {
		$plugins = array();
	}
	$plugins[] = array(
		'id'          => 'gpt',
		'label'       => __( 'Twin GPT', 'bizcity-twin-ai' ),
		'icon'        => 'sparkles',
		'emoji'       => '✦',
		'mode'        => 'embed',
		'public_slug' => '/gpt/',
		'capability'  => 'read',
		'section'     => 'top',
		'route_mode'  => 'path',
		'desc'        => __( 'Your personal AI workspace: chat, your Zalo numbers and your tasks.', 'bizcity-twin-ai' ),
		'requires'    => array( 'class' => 'BizCity_TwinWeb_Page' ),
		'access'      => array(
			'mode'  => 'delegated',
			'owner' => 'modules/twinweb',
		),
	);
	return $plugins;
} );

// Delegated owner of the `gpt` icon: the Twin GPT access policy answers, never a copy of it.
add_filter( 'bizcity_module_access_delegate', static function ( $allowed, $module_id, $user_id ) {
	if ( 'gpt' !== $module_id || ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
		return $allowed;
	}
	return BizCity_TwinWeb_REST::instance()->module_access_allows_user( (int) $user_id );
}, 10, 3 );

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
	// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.50 W5 — leader-task-handoff member projection.
	if ( class_exists( 'BizCity_TwinWeb_CRM_Tasks_REST', false ) ) {
		BizCity_TwinWeb_CRM_Tasks_REST::instance()->register_routes();
	}
	// [2026-09-18] PHASE-0.52 — R-PIPE member projection (current user only).
	if ( class_exists( 'BizCity_TwinWeb_CRM_Pipeline_REST', false ) ) {
		BizCity_TwinWeb_CRM_Pipeline_REST::instance()->register_routes();
	}
} );

// ── Rewrite flush registry (R-CR.1) ───────────────────────────────────────────
// [2026-06-17 Johnny Chu] PHASE-TWINWEB — register at file-load time (outside hooks)
if ( class_exists( 'BizCity_Rewrite_Flush_Registry' ) ) {
	BizCity_Rewrite_Flush_Registry::register( 'bizcity-twinweb', BIZCITY_TWINWEB_REWRITE_VERSION );
}

// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W1 — load DDV probes only in diagnostics contexts.
function bizcity_twinweb_load_diagnostics_probes() {
	static $loaded = false;
	if ( $loaded || ! class_exists( 'BizCity_Safe_Loader' ) ) {
		return;
	}
	$loaded = true;
	// [2026-09-01 Johnny Chu] PHASE-0.45-DIAGNOSTICS — include direct PHP CLI in the same lazy probe path as Diagnostics UI/REST.
	$probe_files = array(
		'class-probe-twinweb-channel.php',
		'class-probe-twinweb-document-search.php',
		'class-probe-twinweb-citation.php',
		'class-probe-twinweb-fb-connect.php',
		'class-probe-twinweb-control-plane-dashboards.php',
		'class-probe-twinweb-appearance.php',
		'class-probe-twinweb-skin-renderer.php',
		'class-probe-twinweb-shortcode-surfaces.php',
		'class-probe-twinweb-customer-profile-grounding.php',
	);
	foreach ( $probe_files as $fname ) {
		$path = BIZCITY_TWINWEB_DIR . 'includes/' . $fname;
		if ( is_file( $path ) && is_readable( $path ) ) {
			BizCity_Safe_Loader::require_file( $path, 'twinweb.diagnostics.' . sanitize_key( basename( $fname, '.php' ) ) );
		}
	}
}

if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	bizcity_twinweb_load_diagnostics_probes();
}
add_action( 'current_screen', function ( $screen ) {
	if ( $screen && false !== strpos( (string) $screen->id, 'bizcity-diagnostics' ) ) {
		bizcity_twinweb_load_diagnostics_probes();
	}
}, 1 );
add_action( 'rest_api_init', function () {
	if ( ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) $_SERVER['REQUEST_URI'], '/bizcity-diagnostics/' ) ) {
		bizcity_twinweb_load_diagnostics_probes();
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
