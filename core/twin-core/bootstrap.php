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
if ( ! defined( 'BIZCITY_TWINCHAT_USE_TWIN_AGENT' ) ) {
    define( 'BIZCITY_TWINCHAT_USE_TWIN_AGENT', true );  // Sprint 4.7e — Twin Agent path (function-calling loop) ★ DEFAULT ON
}
if ( ! defined( 'BIZCITY_TWIN_DEBUG' ) ) {
    define( 'BIZCITY_TWIN_DEBUG', true );               // Sprint 4.10.5 — Twin Debug tracer (BE error_log + FE console). Set FALSE in production.
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

// Source HTML Sanitizer (Wave 0.18.5b) — single source of truth for HTML→Markdown
// conversion before kg_sources ingest. Stateless utility; no init needed.
require_once $twin_includes . '/class-source-html-sanitizer.php';

// Twin Debug — single on/off switch for verbose pipeline tracing (BE+FE).
// Loaded before everything else so any module can call BizCity_Twin_Debug::trace().
require_once $twin_includes . '/class-twin-debug.php';

// Phase 2 Priority 1 — Data Contract (source registry, event taxonomy, ID contract)
require_once $twin_includes . '/class-twin-data-contract.php';

// Phase 2 Priority 3–5 — State Schema (7 state tables DDL + migration)
require_once $twin_includes . '/class-twin-state-schema.php';

// [2026-06-19 Johnny Chu] PHASE-TWB-WORKFLOW W1 — Artifact Normalizer.
// Stateless utility; convert block execute() output → standardized artifact pool entry.
require_once $twin_includes . '/class-twin-artifact-normalizer.php';

// Phase 0.12 Wave A — Twin Event Stream foundation
//   Spec:  PHASE-0.12-TWIN-EVENT-STREAM-UNIFICATION.md
//   Rule:  PHASE-0-RULE-EVENT-STREAM.md (R-EVT-1..7)
//   Folder: core/twin-core/event-stream/  — SINGLE BACKBONE (do NOT scatter event-related files outside this folder).
$twin_event_stream = dirname( __FILE__ ) . '/event-stream';
require_once $twin_event_stream . '/class-bizcity-uuid.php';
require_once $twin_event_stream . '/class-twin-event-taxonomy.php';
require_once $twin_event_stream . '/class-twin-event-stream-schema.php';
require_once $twin_event_stream . '/class-twin-event-store.php';

// Phase 2 Priority 5 — Event Bus (milestone + context log recording + Phase 0.12 dispatch_v2)
require_once $twin_event_stream . '/class-twin-event-bus.php';
BizCity_Twin_Event_Bus::boot();

// Phase 0.12 Wave B+ PR-B+1 — Trace projector (registered, NO-OP until Wave B+3 flip)
require_once $twin_event_stream . '/class-twin-event-trace-projector.php';
BizCity_Twin_Event_Trace_Projector::boot();

// Phase 0.12 Wave C — Router event ingester (parses _twin_events from
// bizcity-llm-router HTTP responses + ingest_remote into local stream).
require_once $twin_event_stream . '/class-router-event-ingester.php';

// Phase 0.12 Wave F — Read-only REST for the Inspector drawer.
require_once $twin_event_stream . '/class-twin-event-stream-rest.php';
BizCity_Twin_Event_Stream_REST::boot();

// Phase 0.12 Wave F — Admin page (Twin Event Inspector).
if ( is_admin() ) {
	require_once $twin_event_stream . '/class-twin-event-inspector-page.php';
	BizCity_Twin_Event_Inspector_Page::boot();
}

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

/* ── Sprint 4.7 — TWIN AGENT CORE (RULE CAO NHẤT) ─────────────── */
// See PHASE-0-RULE-AGENTIC-CORE.md. Mọi main LLM call PHẢI qua BizCity_Twin_Agent::run().
require_once $twin_includes . '/interface-twin-tool.php';
require_once $twin_includes . '/class-twin-tool-registry.php';
require_once $twin_includes . '/class-twin-citation-id-generator.php';
require_once $twin_includes . '/class-twin-citation-validator.php';
require_once $twin_includes . '/class-twin-sse-writer.php';
require_once $twin_includes . '/class-twin-agent-loop.php';

// Register the 4 core tools (lazy — load class files on demand via filter).
add_filter( 'bizcity_twin_register_tool', function ( $registry ) use ( $twin_includes ) {
	if ( ! is_array( $registry ) ) $registry = [];
	require_once $twin_includes . '/tools/class-tool-search-kg.php';
	require_once $twin_includes . '/tools/class-tool-list-sources.php';
	require_once $twin_includes . '/tools/class-tool-fetch-url.php';
	require_once $twin_includes . '/tools/class-tool-query-entity.php';
	$registry['search_kg']    = new BizCity_Tool_Search_KG();
	$registry['list_sources'] = new BizCity_Tool_List_Sources();
	$registry['fetch_url']    = new BizCity_Tool_Fetch_Url();
	$registry['query_entity'] = new BizCity_Tool_Query_Entity();
	return $registry;
}, 5 );

/* ── BizChat Menu — Unified Admin Menu Registry ───────────────── */
require_once $twin_includes . '/class-bizchat-menu.php';
BizChat_Menu::boot();

// DB table + cron + AJAX
// Memory migration is heavy (SHOW TABLES + RENAME) — run only on admin requests,
// never on frontend AJAX/SSE which would block the chat stream for seconds.
if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
    BizCity_Memory_Table_Migration::maybe_migrate();
}
BizCity_Twin_State_Schema::ensure_tables();  // Phase 2 — 7 state tables
BizCity_Twin_Event_Stream_Schema::ensure_table();  // Phase 0.12 Wave A — canonical event stream

// NOTE 2026-05-06: Maturity Dashboard + Calculator subsystem removed entirely
// (admin menu, /maturity/ frontend route, daily/hourly cron, 11 AJAX endpoints,
// snapshot table). Cron events bizcity_maturity_daily_snapshot &
// bizcity_maturity_aggregate_refresh become no-op (no callback registered).
// Stale wp_options 'cron' entries will self-clean on next reschedule cycle.
