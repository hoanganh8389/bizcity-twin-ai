<?php
/**
 * Plugin Name: BizCity LLM Router
 * Plugin URI:  https://bizcity.vn
 * Description: LLM Gateway — Proxy AI requests to OpenRouter with API key auth, usage tracking, credit management, and purpose-based model routing.
 * Version:     1.3.0
 * Author:      BizCity
 * Author URI:  https://bizcity.vn
 * License:     GPL-2.0-or-later
 * Text Domain: bizcity-llm-router
 *
 * REST API namespace: llm/router/v1
 *
 * Endpoints:
 *   POST /llm/router/v1/chat           — Chat completion (streaming or non-streaming)
 *   POST /llm/router/v1/embeddings     — Create embeddings
 *   GET  /llm/router/v1/models         — List available models
 *   GET  /llm/router/v1/models/purposes — Purpose → model mapping
 *   GET  /llm/router/v1/usage          — Usage stats for authenticated user
 *   GET  /llm/router/v1/balance        — Credit balance
 *
 * @package BizCity_LLM_Router
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

define( 'BIZCITY_LLM_ROUTER_VERSION', '1.3.1' );
define( 'BIZCITY_LLM_ROUTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'BIZCITY_LLM_ROUTER_URL', plugin_dir_url( __FILE__ ) );

require_once BIZCITY_LLM_ROUTER_DIR . 'includes/compat-php74.php';               // PHP 7.4 polyfills — must be very first
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-schema.php';        // Must be first
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-auth.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-proxy.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-models.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-usage.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-pricing.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-rest.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-tools-rest.php';     // PHASE-0.7 Wave E0 — /llm/router/v1/tools/ocr (+ stt, vision later)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-gemini-files.php';   // PHASE-0.7 Sprint B — large-file mode picker (inline vs URL passthrough)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-transcribe-rest.php'; // PHASE-0.7 Wave E0.AV — /bizcity/v1/tools/transcribe (audio/video → text via Vision LLM)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-av-diagnostic.php';   // PHASE-0.7 Wave E0.AV — Tools → AV Transcribe Diag (operator health-check)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-entitlement.php';     // PHASE-0.7 Wave T0 — unified entitlement service
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-entitlement-rest.php';// PHASE-0.7 Wave T0 — /bizcity/v1/account/entitlement
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-cost-headers.php';     // PHASE-0.7 Wave T0.9 — X-Bizcity-Cost-* response headers
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-blocked-log.php';     // PHASE-0.7 Wave T2 — entitlement blocked-log audit
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-rerank-controller.php'; // Sprint 4.8a — /llm/router/v1/rerank
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-event-emitter.php';   // Phase 0.12 Wave C — Twin Event piggyback buffer
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-account-rest.php';    // bizcity/v1/account/* + billing/topup/* + free-tier helpers (Phase 1.11)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-settings.php';
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-openrouter-api.php'; // Public API proxy
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-model-cpt.php';       // AI Model CPT
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-support-cta.php';     // Shared support CTA
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-public-pages.php';   // Public pages
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-marketplace.php';    // Marketplace shortcode + pages
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-api-docs.php';       // API Documentation pages
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-plugin-standard.php'; // Plugin twin standard page
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-open-share-pages.php'; // Open Share public pages
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-piapi-proxy.php';             // PiAPI faceswap / VTO proxy (server-side API key)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-piapi-router-rest.php';     // piapi/router/v1/* REST endpoints
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-search-router-proxy.php';   // Tavily proxy (server-side API key)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-search-router-rest.php';    // search/router/v1/* REST endpoints
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-video-router-rest.php';     // video/router/v1/* REST endpoints (Phase 3.0 + 3.4 faceswap/VTO)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-market-catalog.php';         // Market plugin catalog (server DB)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-market-distribution.php';    // ZIP storage + serving
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-market-rest.php';            // market/v1/* REST endpoints
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-kg-bridge.php';       // bizcity-twin-ai KG Cost Guard filter bridge
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-master-schema.php';   // PHASE-MASTER-PLANS — bizcity_llm_master_plans table + api_keys columns
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-master-admin.php';    // PHASE-MASTER-PLANS — Quản lý Master submenu
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-master-rest.php';     // PHASE-MASTER-PLANS — GET /bizcity/v1/master/*
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-activity-dashboard.php'; // PHASE-LLM-ACTIVITY R9 — [bizcity_activity_dashboard] public /my-account/ rollup
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-quota-guard.php'; // PHASE-0.1-ASTRO Sprint A4 — per-tier daily quota enforcement
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-settings.php';    // PHASE-0.1-ASTRO Sprint A3 — admin settings sub-page
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-interface.php'; // PHASE-0.1-ASTRO Sprint B1 — BizCity_Astro_Provider + Normalizer
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-http-client.php';         // PHASE-0.2 Sprint F.1 — shared HTTP w/ retry+backoff+breaker
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa-base.php';  // PHASE-0.2 Sprint F.1 — FAA provider base class
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-normalizer-v2.php';      // PHASE-0.2 Sprint F.2 — V2 normalizer (western/vedic/chinese)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa-western.php'; // PHASE-0.2 Sprint F.2 — FAA Western provider
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa-vedic.php';   // PHASE-0.2 Sprint F.3 — FAA Vedic (Jyotish) provider
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa-chinese.php'; // PHASE-0.2 Sprint F.4 — FAA Chinese (BaZi) provider
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa-utilities.php'; // PHASE-0.2 Sprint F.5 — FAA Utilities (geo/tz/moon)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-diagnostics.php';          // PHASE-0.2 Sprint F.2 — diagnostics + AJAX self-test
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-freeastrology.php'; // Sprint B2 — Provider A (legacy, deprecating)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-freeastroapi.php'; // Sprint B3 — Provider B V1 (legacy, deprecating)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-transit-aspect-calculator.php'; // [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — PHP transit aspect calculator
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa2-base.php';         // [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — FAA2 base (freeastrologyapi.com)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-faa2-western.php';      // [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — FAA2 Western (MANDATORY natal)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-qr-template-schema.php';  // Phase QR-1 — QR Template Library schema
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-qr-template-catalog.php'; // Phase QR-1 — QR Template CRUD
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-qr-template-rest.php';    // Phase QR-1 — REST bizcity/v1/qr-templates/*
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-qr-library-importer.php'; // Phase QR-2 — GPT-Image-2 library importer
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-qr-template-admin.php';   // Phase QR-1 — Admin page
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-qr-proxy.php';            // Phase QR-3 — /create-qr-code/ proxy → api.qrserver.com
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-template-schema.php';  // Phase IT-1 — Image template lib schema (v1.10.0)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-template-catalog.php'; // Phase IT-1 — CRUD
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-template-seeder.php';  // Phase IT-1 — JSON seed loader
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-template-rest.php';    // Phase IT-1 — bizcity/v1/image-templates/*
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-template-admin.php';   // Phase IT-1 — Admin submenu
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-editor-asset-schema.php';  // Phase IT-2 — Editor asset 5-table schema
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-editor-asset-catalog.php'; // Phase IT-2 — unified CRUD
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-editor-asset-seeder.php';  // Phase IT-2 — editor JSON seeder
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-editor-asset-rest.php';   // Phase IT-2 — editor + bundle endpoints
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/tool-image/class-image-editor-asset-admin.php';  // Phase IT-2 — admin submenu
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-google-oauth.php';  // Google OAuth Hub (v1.9.0)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-automation-schema.php'; // PHASE-ATH W4 — Automation Hub template tables (Branch #17)
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/class-router-automation-rest.php';   // PHASE-ATH W4 — bizcity/v1/automation-templates/*
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-provider-local.php';        // Sprint B4 — Local fallback
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-router.php';                // Sprint B5 — Fallback router
require_once BIZCITY_LLM_ROUTER_DIR . 'includes/astro/class-astro-rest.php';        // PHASE-0.1-ASTRO Sprint A1 — bizcity/v1/astrology/* REST (B6: live)

/* ── Intelligence Engine (Smart Gateway) ── */
if ( file_exists( BIZCITY_LLM_ROUTER_DIR . 'intelligence/bootstrap.php' ) ) {
    require_once BIZCITY_LLM_ROUTER_DIR . 'intelligence/bootstrap.php';
}

/* ── i18n ── */
add_action( 'init', function () {
    load_plugin_textdomain( 'bizcity-llm-router', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/* ── Boot ── */
add_action( 'plugins_loaded', function () {
    BizCity_Router_Schema::maybe_upgrade(); // Migrate DB if version bumped
    BizCity_Router_Schema::boot_cron();     // Register daily aggregation cron
    BizCity_Router_Cost_Headers::boot();    // Wave T0.9 — cost-disclosure headers
    BizCity_Entitlement_Blocked_Log::boot(); // Wave T2 — blocked attempt audit log
    BizCity_Router_Settings::instance();
    BizCity_Router_Pricing::boot();
    BizCity_Router_Public_Pages::boot();    // Public model pages + REST
    BizCity_Router_Model_CPT::boot();        // AI Model CPT + cron sync
    BizCity_Router_Marketplace::boot();     // Marketplace shortcode + /marketplace/ pages
    BizCity_Router_API_Docs::boot();         // /api-docs/ pages
    BizCity_Router_Plugin_Standard::boot(); // /plugin-twin-standard/
    BizCity_Router_Open_Share_Pages::boot(); // /twin-ai/  /build-your-own-claw/  /open-source-ai-for-everyone/
    BizCity_Router_KG_Bridge::init();         // Feed KG Cost Guard config filters from LLM Router sitemeta
    BizCity_Router_Master_Schema::maybe_install(); // PHASE-MASTER-PLANS — ensure master_plans table + api_keys columns
    BizCity_Router_Master_Admin::boot();           // PHASE-MASTER-PLANS — Quản lý Master submenu
    BizCity_Router_Activity_Dashboard::boot();     // PHASE-LLM-ACTIVITY R9 — [bizcity_activity_dashboard] shortcode
    BizCity_Astro_Settings::boot();           // PHASE-0.1-ASTRO Sprint A3 — Astrology Gateway settings sub-page
    BizCity_Astro_Diagnostics::boot();        // PHASE-0.2 Sprint F.2 — admin AJAX self-test runner
    BizCity_QR_Template_Schema::maybe_install(); // Phase QR-1 — install QR template tables
    BizCity_QR_Template_Admin::boot();           // Phase QR-1 — admin submenu
    BizCity_QR_Proxy::boot();                    // Phase QR-3 — /create-qr-code/ pretty URL proxy
    BizCity_Image_Template_Schema::maybe_install(); // Phase IT-1 — install image template tables
    BizCity_Image_Template_Admin::boot();           // Phase IT-1 — admin submenu
    BizCity_Image_Editor_Asset_Schema::maybe_install(); // Phase IT-2 — install editor asset tables
    BizCity_Image_Editor_Asset_Admin::boot();           // Phase IT-2 — admin submenu
    BizCity_Router_Google_OAuth::boot();          // Google OAuth Hub — AJAX handlers (v1.3.2)
    BizCity_Router_Automation_Schema::maybe_install(); // PHASE-ATH W4 — Automation Hub template tables
}, 5 );

add_action( 'rest_api_init', function () {
    BizCity_Router_REST::register_routes();
    BizCity_Router_Rerank_Controller::register_routes(); // Sprint 4.8a — /rerank
    BizCity_Search_Router_REST::register_routes(); // search/router/v1/*
    BizCity_Video_Router_REST::register_routes();  // video/router/v1/* (Phase 3.0 + 3.4 faceswap/VTO)
    BizCity_PiAPI_Router_REST::register_routes();  // piapi/router/v1/* (Phase 3.4 faceswap/VTO)
    BizCity_Market_REST::register_routes();  // market/v1/*
    BizCity_Router_Account::register_routes(); // bizcity/v1/account/* + billing/* (Phase 1.11)
    BizCity_Astro_Router::boot();              // PHASE-0.1-ASTRO Sprint B5 — register provider adapters
    BizCity_Astrology_REST::register_routes(); // PHASE-0.1-ASTRO Sprint B6 — bizcity/v1/astrology/* (live natal/transit)
    BizCity_QR_Template_REST::register_routes(); // Phase QR-1 — bizcity/v1/qr-templates/*
    BizCity_Image_Template_REST::register_routes(); // Phase IT-1 — bizcity/v1/image-templates/* + /image-samples/*
    BizCity_Image_Editor_Asset_REST::register_routes(); // Phase IT-2 — bizcity/v1/image-editor-assets/* + /image-library/manifest|bundle
    BizCity_Router_Google_OAuth::register_routes(); // Google OAuth Hub — /bizcity/v1/google/*
    BizCity_Router_Master_REST::register_routes();   // PHASE-MASTER-PLANS — /bizcity/v1/master/*
    BizCity_Router_Automation_REST::register_routes(); // PHASE-ATH W4 — /bizcity/v1/automation-templates/*
} );

/* ── Activation: create all tables ── */
register_activation_hook( __FILE__, function () {
    BizCity_Router_Schema::install();   // Creates all 7 tables via dbDelta
    BizCity_Router_Master_Schema::install(); // PHASE-MASTER-PLANS — master_plans + api_keys columns
    BizCity_QR_Template_Schema::install(); // Creates QR template tables
    BizCity_QR_Template_Catalog::seed_defaults(); // Seed default categories
    BizCity_Image_Template_Schema::install();    // Phase IT-1 — image template tables
    BizCity_Image_Template_Seeder::run( true );  // Phase IT-1 — initial seed from data/*.json (force)
    BizCity_Image_Editor_Asset_Schema::install();    // Phase IT-2 — editor asset tables
    BizCity_Image_Editor_Asset_Seeder::run( true );  // Phase IT-2 — initial editor asset seed (force)
    BizCity_Router_Automation_Schema::install();     // PHASE-ATH W4 — Automation Hub template tables

    // Register CPT so it's available for rewrite flush
    BizCity_Router_Model_CPT::register_cpt();

    // Schedule initial sync if not already scheduled
    if ( ! wp_next_scheduled( BizCity_Router_Model_CPT::SYNC_HOOK ) ) {
        wp_schedule_event( time(), 'twicedaily', BizCity_Router_Model_CPT::SYNC_HOOK );
    }

    // Clear agent catalog cache so a fresh filesystem scan runs on next load
    BizCity_Router_Marketplace::flush_cache();

    // Register rewrite rules then flush so new pages work immediately
    BizCity_Router_Marketplace::add_rewrites();
    BizCity_Router_API_Docs::add_rewrites();
    BizCity_Router_Plugin_Standard::add_rewrites();
    BizCity_Router_Open_Share_Pages::add_rewrites();

    // Ensure a real WP page "twin-ai" exists so it can be set as homepage
    BizCity_Router_Open_Share_Pages::ensure_twin_ai_page();

    flush_rewrite_rules();
} );

/* ── Deactivation: clean up cron ── */
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( BizCity_Router_Model_CPT::SYNC_HOOK );
} );
