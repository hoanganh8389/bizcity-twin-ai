<?php
/**
 * Bizcity Twin AI — Knowledge Graph Hub
 *
 * Bootstrap loader for the KG-Hub module (Phase 0.3).
 * Mounts under core/knowledge as a sub-module so it inherits all existing
 * infrastructure (embedding, LLM router, character system, multisite shard).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! defined( 'BIZCITY_KG_HUB_DIR' ) ) {
	define( 'BIZCITY_KG_HUB_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_URL' ) ) {
	// Resolve URL relative to the parent knowledge module URL when available.
	if ( defined( 'BIZCITY_KNOWLEDGE_DIR' ) ) {
		$parent_url = plugin_dir_url( BIZCITY_KNOWLEDGE_DIR . 'bootstrap.php' );
		define( 'BIZCITY_KG_HUB_URL', trailingslashit( $parent_url ) . 'kg-hub/' );
	} else {
		define( 'BIZCITY_KG_HUB_URL', plugin_dir_url( __FILE__ ) );
	}
}
if ( ! defined( 'BIZCITY_KG_HUB_VERSION' ) ) {
	define( 'BIZCITY_KG_HUB_VERSION', '0.3.0' );
}
if ( ! defined( 'BIZCITY_KG_HUB_INCLUDES' ) ) {
	define( 'BIZCITY_KG_HUB_INCLUDES', BIZCITY_KG_HUB_DIR . 'includes/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_PROMPTS' ) ) {
	define( 'BIZCITY_KG_HUB_PROMPTS', BIZCITY_KG_HUB_DIR . 'prompts/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_UI_DIR' ) ) {
	define( 'BIZCITY_KG_HUB_UI_DIR', BIZCITY_KG_HUB_DIR . 'ui/' );
}

// ─── Includes ──────────────────────────────────────────────────────────────
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cost-guard.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-database.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-vector-index.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-notebook-service.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-service.php';
// Phase 0.5 — KG-Hub Contract registry + facade.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-registry.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-facade.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-scoped-rest-controller.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-auto-promoter.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'kg-helpers.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-graph-service.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-triplet-extractor.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-reranker.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-retriever.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-adapter-studio.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-rest-controller.php';
// Phase 0.6.6 / Wave B — orphan cleanup (soft → reaper) + audit log.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cleanup-service.php';
BizCity_KG_Cleanup_Service::bind();

// PHASE-0.13 Wave 10c — per-source learning evidence trail (diagnose 100%→0% loop).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-progress-log.php';
BizCity_KG_Source_Progress_Log::bind();

if ( is_admin() ) {
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-admin-menu.php';
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-settings-page.php';
}
// Phase 0.6 — Multisite cron backfill (runs in cron context, not admin-only).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-backfill.php';
BizCity_KG_Backfill::boot();

// Phase 0.6 Wave A — WP-CLI commands for brain-reflection observability.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cli.php';
}

// Phase 0.6.5 — Wave C: real-time mirror of legacy *_sources INSERTs into kg_sources.
// Feature-flagged inside the handler (option `bizcity_kg_v06_unified_write`).
add_action( 'bizcity_kg_legacy_source_inserted',   [ 'BizCity_KG', 'on_legacy_source_inserted' ], 10, 1 );
add_action( 'bizcity_kg_legacy_chunks_persisted', [ 'BizCity_KG', 'on_legacy_chunks_persisted' ], 10, 1 );

// ─── Init ──────────────────────────────────────────────────────────────────
add_action( 'init', static function () {
	// Trigger DB migration on every page load (cheap, version-gated).
	BizCity_KG_Database::instance();
	// Phase 0.5 Sprint 2 — hook studio output → KG adapter.
	BizCity_KG_Source_Adapter_Studio::instance()->boot();
	// Phase 0.5 Sprint 4.5g — auto-promote chat messages to KG passages.
	BizCity_KG_Auto_Promoter::instance()->boot();
}, 5 );

add_action( 'rest_api_init', static function () {
	BizCity_KG_Rest_Controller::instance()->register_routes();
	if ( class_exists( 'BizCity_KG_Scoped_REST_Controller' ) ) {
		BizCity_KG_Scoped_REST_Controller::instance()->register_routes();
	}
} );

if ( is_admin() ) {
	add_action( 'admin_menu', static function () {
		BizCity_KG_Admin_Menu::instance()->register();
		BizCity_KG_Settings_Page::instance()->register();
	}, 20 );
}

// ─── Phase 0.6 — Feature flags (WP option controlled, off by default) ───────
// Enable dual-write:  update_option('bizcity_kg_v06_dual_write_enabled', true)
// Enable read-switch: update_option('bizcity_kg_v06_read_switch_enabled', true)
add_filter( 'bizcity_kg_v06_dual_write', static function ( $enabled ) {
	return $enabled || (bool) get_option( 'bizcity_kg_v06_dual_write_enabled', false );
} );
add_filter( 'bizcity_kg_v06_read_switch', static function ( $enabled ) {
	return $enabled || (bool) get_option( 'bizcity_kg_v06_read_switch_enabled', false );
} );
