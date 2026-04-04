<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Twin Core — Phase 0–2: Context Cleanup + Twin State Backbone
 *
 * mu-plugin entry point.
 * Loads Data Contract, State Schema, Event Bus, Prompt Parser,
 * Focus Router, Focus Gate, Twin Context Resolver, Twin Snapshot Builder.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Feature Flags ─────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_TWIN_FOCUS_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_FOCUS_ENABLED', true );     // Sprint 0A — focus gate
}
if ( ! defined( 'BIZCITY_TWIN_RESOLVER_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_RESOLVER_ENABLED', true );   // Sprint 0B — resolver ★ ENABLED
}
if ( ! defined( 'BIZCITY_TWIN_SNAPSHOT_ENABLED' ) ) {
    define( 'BIZCITY_TWIN_SNAPSHOT_ENABLED', false );   // Sprint 0C — snapshot
}

/* ── Constants ─────────────────────────────────────────────────── */
if ( ! defined( 'BIZCITY_TWIN_CORE_DIR' ) ) {
    define( 'BIZCITY_TWIN_CORE_DIR', __DIR__ );
}
if ( ! defined( 'BIZCITY_TWIN_CORE_VERSION' ) ) {
    define( 'BIZCITY_TWIN_CORE_VERSION', '2.0.2' );
}

/* ── Autoload Classes ──────────────────────────────────────────── */
// Skip if already loaded by legacy mu-plugin
if ( class_exists( 'BizCity_Twin_Trace' ) ) {
    return;
}
$twin_includes = BIZCITY_TWIN_CORE_DIR . '/includes';

// Twin Trace — always loaded so trace calls are safe even when gates are off
require_once $twin_includes . '/class-twin-trace.php';
BizCity_Twin_Trace::init();

// Phase 2 Priority 1 — Data Contract (source registry, event taxonomy, ID contract)
require_once $twin_includes . '/class-twin-data-contract.php';

// Phase 2 Priority 3–5 — State Schema (7 state tables DDL + migration)
require_once $twin_includes . '/class-twin-state-schema.php';

// Phase 2 Priority 5 — Event Bus (milestone + context log recording)
require_once $twin_includes . '/class-twin-event-bus.php';
BizCity_Twin_Event_Bus::boot();

// Phase 2 Priority 4 — Prompt Parser (write-only prompt specs)
require_once $twin_includes . '/class-twin-prompt-parser.php';

// Memory Table Migration — rename scattered tables to unified bizcity_memory_* prefix
require_once $twin_includes . '/class-memory-table-migration.php';

// Sprint 0A — Focus Gate (always loaded when flag enabled)
if ( BIZCITY_TWIN_FOCUS_ENABLED ) {
    require_once $twin_includes . '/class-focus-router.php';
    require_once $twin_includes . '/class-focus-gate.php';
    require_once $twin_includes . '/class-twin-suggest.php';

    // Hook Focus Gate at priority 1 — BEFORE all context injectors
    add_filter( 'bizcity_chat_system_prompt', [ 'BizCity_Focus_Gate', 'gate_context' ], 1, 2 );
}

// Sprint 0C — Twin Snapshot Builder
if ( BIZCITY_TWIN_SNAPSHOT_ENABLED ) {
    require_once $twin_includes . '/class-twin-snapshot-builder.php';

    // Event-driven snapshot invalidation — hook names match actual do_action() calls
    add_action( 'bizcity_webchat_message_saved',  [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bizcity_intent_processed',       [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bizcity_chat_message_processed', [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bcn_note_created',               [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bcn_note_updated',               [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bcn_source_added',               [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bizcity_knowledge_ingested',     [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
    add_action( 'bizcity_tool_registry_changed',  [ 'BizCity_Twin_Snapshot_Builder', 'invalidate' ] );
}

// Sprint 0B — Twin Context Resolver (always loaded — unified prompt builder)
require_once $twin_includes . '/class-twin-context-resolver.php';

/* ── Maturity Dashboard (§29) ──────────────────────────────────── */
require_once $twin_includes . '/class-maturity-calculator.php';
require_once $twin_includes . '/class-maturity-dashboard.php';

/* ── BizChat Menu — Unified Admin Menu Registry ───────────────── */
require_once $twin_includes . '/class-bizchat-menu.php';
BizChat_Menu::boot();

// DB table + cron + AJAX
// Memory migration is heavy (SHOW TABLES + RENAME) — run only on admin requests,
// never on frontend AJAX/SSE which would block the chat stream for seconds.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    BizCity_Memory_Table_Migration::maybe_migrate();
}
BizCity_Maturity_Calculator::ensure_table();
BizCity_Twin_State_Schema::ensure_tables();  // Phase 2 — 7 state tables
BizCity_Maturity_Calculator::schedule_cron();
BizCity_Maturity_Calculator::register_ajax();
add_action( 'bizcity_maturity_daily_snapshot', [ 'BizCity_Maturity_Calculator', 'cron_save_snapshots' ] );
add_action( 'bizcity_maturity_aggregate_refresh', [ 'BizCity_Maturity_Calculator', 'cron_refresh_all_aggregates' ] );

// Frontend /maturity/ rewrite route
BizCity_Maturity_Dashboard::register_frontend_route();

// Admin UI
if ( is_admin() ) {
    BizCity_Maturity_Dashboard::instance();
}
