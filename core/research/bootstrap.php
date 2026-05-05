<?php
/**
 * Bizcity Twin AI — Guru Research Studio (Phase 0.18.1)
 *
 * Self-contained research module that ports Tavily Chat (ReAct + NDJSON)
 * to PHP/WordPress + a vanilla JS Studio UI. Supports two scopes:
 *
 *   • scope=character  → ingest into Twin Guru L2 Knowledge
 *   • scope=user       → personal research projects (user_id)
 *
 * Mount points:
 *   1. Tab "Nghiên cứu" inside character-edit page
 *   2. Standalone admin page "Twin Research" (per-user projects)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Research
 * @since      Phase 0.18.1 — 2026-05-02
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'BIZCITY_RESEARCH_DIR' ) ) {
    define( 'BIZCITY_RESEARCH_DIR', __DIR__ . '/' );
}
if ( ! defined( 'BIZCITY_RESEARCH_URL' ) ) {
    define( 'BIZCITY_RESEARCH_URL', plugins_url( '', __FILE__ ) . '/' );
}
if ( ! defined( 'BIZCITY_RESEARCH_VERSION' ) ) {
    define( 'BIZCITY_RESEARCH_VERSION', '0.18.2' );
}

require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-db.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-store.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-prompts.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-summarizer.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-tool-router.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-event-emitter.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-agent.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-ingest-service.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-rest.php';
require_once BIZCITY_RESEARCH_DIR . 'includes/class-research-admin.php';

// Install DB tables (idempotent)
add_action( 'init', static function () {
    if ( class_exists( 'BizCity_Research_DB' ) ) {
        BizCity_Research_DB::install();
    }
}, 5 );

// Register REST routes
add_action( 'rest_api_init', static function () {
    BizCity_Research_REST::instance()->register_routes();
} );

// Admin pages + character-edit tab injection
add_action( 'admin_menu', static function () {
    BizCity_Research_Admin::instance()->register_menu();
}, 50 );

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
    BizCity_Research_Admin::instance()->maybe_enqueue( $hook );
} );

// Inject Research tab inside character-edit page
add_action( 'admin_footer', static function () {
    BizCity_Research_Admin::instance()->maybe_inject_character_tab();
} );
