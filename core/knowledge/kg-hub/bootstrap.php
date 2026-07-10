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
	define( 'BIZCITY_KG_HUB_VERSION', '0.21.2' );
}
if ( ! defined( 'BIZCITY_KG_HUB_INCLUDES' ) ) {
	define( 'BIZCITY_KG_HUB_INCLUDES', BIZCITY_KG_HUB_DIR . 'includes/' );
}
if ( ! defined( 'BIZCITY_KG_HUB_SKELETON' ) ) {
	// PHASE-6.6 — skeleton subsystem isolated under /skeleton/ for clarity.
	define( 'BIZCITY_KG_HUB_SKELETON', BIZCITY_KG_HUB_DIR . 'skeleton/' );
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
// PHASE-0.3 Identity Algorithm (transparency-first, 2026-05-08) — pure
// regex-based extractor used by tool wrapper + prompt resolver. No DB.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-identity-extractor.php';
// PHASE-0.3 Wave 2 — backfill engine + WP-CLI + REST. Loaded after database.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-identity-backfill.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-vector-index.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-vector-file-store.php';
// PHASE-0.7-LEARN-VECTOR-FILE (Wave F0-F2, 2026-05-20) — content filestore companion.
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-notebook-folder.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-md-parser.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-passage-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-jsonl-stream.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-entity-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-relation-file-store.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-dispatcher.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-content-router.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-backfill.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'filestore/class-kg-filestore-diagnostic.php';
BizCity_KG_Filestore_Backfill::instance()->bind();
// bind() must run outside is_admin() so the cron_schedules filter is always
// registered — cron context is not admin and needs bizcity_kg_weekly etc.
// AJAX/admin-UI hooks inside bind() are no-ops when not in admin context.
BizCity_KG_Filestore_Diagnostic::instance()->bind();
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-embedding-writer.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-notebook-service.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-service.php';// Phase 0.5 — KG-Hub Contract registry + facade.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-registry.php';
// Phase 0.7 / Wave E1 — Source adapter framework (interface + registry).
// PDF/Office adapters auto-registered by the registry's defaults loader.
require_once BIZCITY_KG_HUB_INCLUDES . 'adapters/interface-source-adapter.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'adapters/class-adapter-registry.php';
// Phase 0.7 / Wave E0 — OCR client (Vision LLM via /llm/router/v1/tools/ocr).
require_once BIZCITY_KG_HUB_INCLUDES . 'clients/class-ocr-client.php';
require_once BIZCITY_KG_HUB_INCLUDES . 'clients/class-youtube-transcriber.php'; // Phase 0.7 / Wave E0.YT
// Phase 0.7 / Wave E0.AV — audio/video transcribe client (multimodal LLM via gateway).
require_once BIZCITY_KG_HUB_INCLUDES . 'clients/class-av-transcribe-client.php';
// Phase 0.7 / Sprint D — temporal-aware passage chunker for AV transcripts.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-av-chunker.php';
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
// PHASE 0.31 T-S1.6 — public workflow REST surface (token-gated).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-public-api.php';
// PHASE 0.31 T-S2.4 — WaicChannelIntegration_notebook (Twin Second Brain bus).
require_once BIZCITY_KG_HUB_INCLUDES . 'integration-notebook.php';
// Phase 0.21 Wave 3.0 — Guru Builder (promote notebook → guru, clone mode).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-guru-builder.php';
// Phase 0.6.6 / Wave B — orphan cleanup (soft → reaper) + audit log.
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cleanup-service.php';
BizCity_KG_Cleanup_Service::bind();

// PHASE-0.13 Wave 10c — per-source learning evidence trail (diagnose 100%→0% loop).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-source-progress-log.php';
BizCity_KG_Source_Progress_Log::bind();

// PHASE-0-RULE-SKELETON Sprint 0★ — Notebook Skeleton-First foundation
// (Adapter = single point of truth, Service = debounced reflection cron,
//  Prompt = shared LLM contract, REST = bizcity/kg/v1 surface — see
//  PHASE-0-RULE-NAMESPACE).
// MUST load before any tool plugin so `BizCity_KG_Skeleton_Adapter` is available
// from `init` onwards (RULE-1 invariant).
require_once BIZCITY_KG_HUB_SKELETON . 'class-skeleton-prompt.php';
require_once BIZCITY_KG_HUB_SKELETON . 'class-notebook-skeleton-adapter.php';
require_once BIZCITY_KG_HUB_SKELETON . 'class-notebook-skeleton-service.php';
require_once BIZCITY_KG_HUB_SKELETON . 'class-skeleton-rest.php';
BizCity_KG_Skeleton_Service::bind();
BizCity_KG_Skeleton_REST::bind();

// PHASE-0-RULE-SKELETON Sprint 0★ — Skeleton diagnostics class (always loaded:
// needed from CLI, cron context, and admin alike).
require_once BIZCITY_KG_HUB_SKELETON . 'class-kg-skeleton-diagnostic.php';
if ( is_admin() ) {
	// Bind admin_menu hook (Tools → KG Skeleton).
	BizCity_KG_Skeleton_Diagnostic::instance();
}

// PHASE-0-RULE-SKELETON Sprint 0★ S0.7–S0.9 — shared FE web components
// (<bztwin-notebook-selector>, <bztwin-skeleton-preview>, useNotebookSkeleton helper).
// Loaded everywhere so any plugin (admin or front-end) can call
// BizCity_KG_Skeleton_Assets::enqueue() to wire RULE-3 / RULE-4 surfaces.
require_once BIZCITY_KG_HUB_SKELETON . 'class-kg-skeleton-assets.php';

// Phase 0.22 — Skeleton backfill cron: re-queues failed/stuck notebooks hourly (R-CRON-META).
// Runs outside admin context so it fires in WP-Cron / Action Scheduler passes.
require_once BIZCITY_KG_HUB_SKELETON . 'class-kg-skeleton-backfill-cron.php';
BizCity_KG_Skeleton_Backfill_Cron::boot();

if ( is_admin() ) {
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-admin-menu.php';
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-settings-page.php';
	// Phase 0.21 Wave 2 — browser-accessible .bin diagnostic (Tools → KG .bin Diagnostic).
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-bin-diagnostic.php';
}
// Phase 0.6 — Multisite cron backfill (runs in cron context, not admin-only).
require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-backfill.php';
BizCity_KG_Backfill::boot();

// Phase 0.6 Wave A — WP-CLI commands for brain-reflection observability.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BIZCITY_KG_HUB_INCLUDES . 'class-kg-cli.php';
}

// PHASE 0.31 T-S3.2 — [bizcity_notebook_notes notebook_id=N limit=20] shortcode
// renders the per-note "Tag note" + "Trigger workflow" panel that backs the
// REST endpoints /passages/{id}/tag and /passages/{id}/trigger-workflow.
add_shortcode( 'bizcity_notebook_notes', static function ( $atts ) {
	$atts = shortcode_atts( array(
		'notebook_id' => 0,
		'limit'       => 20,
	), $atts, 'bizcity_notebook_notes' );

	$view = WP_PLUGIN_DIR . '/bizcity-twin-ai/core/knowledge/views/notebook-notes-panel.php';
	if ( ! is_readable( $view ) ) { return ''; }
	ob_start();
	include $view; // $atts is in scope
	return ob_get_clean();
} );

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

	// 2026-05-11 — Bug-fix: TwinChat parent menu (`bizcity-twinchat`) registers
	// at admin_menu priority 25 (modules/twinchat/bootstrap.php). Previously this
	// subpage was registered at priority 20 → child ran BEFORE parent → orphan,
	// link broken (404 /wp-admin/bizcity-twinchat-gurus). Fix: register at 30.
	//
	// 2026-05-06 — Phase 0.21 Wave 3.3: "Phong cấp Guru" subpage under TwinChat parent.
	// Same React bundle, defaultView='gurus' wired via bootstrap data.
	// Capability 'read' so end users (notebook owners) can promote their notebooks.
	add_action( 'admin_menu', static function () {
		BizCity_KG_Admin_Menu::instance()->register_subpage(
			'bizcity-twinchat',
			BizCity_KG_Admin_Menu::PAGE_SLUG_GURUS,
			__( 'Nâng cấp Connector', 'bizcity-knowledge' ),
			__( 'Nâng cấp Connector', 'bizcity-knowledge' ),
			'read',
			'gurus'
		);
	}, 30 );
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
