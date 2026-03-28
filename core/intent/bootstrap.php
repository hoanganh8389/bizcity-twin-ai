<?php
/**
 * BizCity Intent — 2-Tier Mode Router + Conversation State Machine
 *
 * Unified conversation management layer that sits between all chat channels
 * (Webchat, Zalo, Telegram, FB) and the AI brain (class-chat-gateway.php).
 *
 * Core responsibilities:
 *   0. Meta Mode Classifier — classify each message into 4 modes
 *   1. Mode Pipelines        — emotion, reflection, knowledge, execution
 *   2. Conversation Manager  — track goal + slots + status per conversation_id
 *   3. Intent Router          — classify execution messages (new_goal / continue / end)
 *   4. Flow Planner           — step-by-step execution (ask, call_tool, compose, complete)
 *   5. Tool Registry          — declarative tool schemas with missing-field detection
 *   6. Stream Adapter         — SSE for webchat, batch for hooks
 *
 * 2-Tier Architecture:
 *   Tier 1: Meta Mode Classifier → emotion | reflection | knowledge | execution
 *   Tier 2: Intent Extractor     → only runs when mode = execution
 *
 * @package BizCity_Intent
 * @version 3.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Constants — guarded to allow coexistence with legacy mu-plugin during migration
if ( ! defined( 'BIZCITY_INTENT_VERSION' ) ) {
    define( 'BIZCITY_INTENT_VERSION', '3.8.0' );
}
if ( ! defined( 'BIZCITY_INTENT_DIR' ) ) {
    define( 'BIZCITY_INTENT_DIR', __DIR__ );
}
if ( ! defined( 'BIZCITY_INTENT_URL' ) ) {
    define( 'BIZCITY_INTENT_URL', plugin_dir_url( __FILE__ ) );
}

/* ── Load sub-classes (skip if already loaded by legacy mu-plugin) ── */
if ( class_exists( 'BizCity_Intent_Database' ) ) {
    return;
}

/* ── Data layer + Infrastructure (always loaded) ── */

/* -- infrastructure/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-database.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-logger.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-execution-logger.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-stream.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-monitor.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-prompt-context-logger.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-job-trace.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-data-browser.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-settings-api.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-intent-rest-api.php';
require_once BIZCITY_INTENT_DIR . '/includes/infrastructure/class-unified-rest-api.php';

/* -- conversation/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/conversation/class-intent-conversation.php';
require_once BIZCITY_INTENT_DIR . '/includes/conversation/class-rolling-memory.php';
require_once BIZCITY_INTENT_DIR . '/includes/conversation/class-episodic-memory.php';
require_once BIZCITY_INTENT_DIR . '/includes/conversation/class-context-builder.php';

/* -- providers/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-provider.php';
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-simple-provider.php';
require_once BIZCITY_INTENT_DIR . '/includes/providers/class-intent-provider-registry.php';

/* -- routing/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-mode-pipeline.php';  // parent class for knowledge-router
require_once BIZCITY_INTENT_DIR . '/includes/routing/class-intent-router.php';
require_once BIZCITY_INTENT_DIR . '/includes/routing/class-knowledge-router.php';

/* -- classification/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/classification/class-mode-classifier.php';
require_once BIZCITY_INTENT_DIR . '/includes/classification/class-intent-clarify-gate.php';
require_once BIZCITY_INTENT_DIR . '/includes/classification/class-intent-classify-cache.php';
require_once BIZCITY_INTENT_DIR . '/includes/classification/class-slot-analysis.php';
require_once BIZCITY_INTENT_DIR . '/includes/classification/class-confirm-analyzer.php';

/* -- tools/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-intent-tools.php';
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-intent-tool-index.php';
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-tool-control-panel.php';

/* -- orchestration/ -- */
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-intent-planner.php';
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-priority-functions.php';
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-intent-engine.php';

/* -- Phase 1 — Unified Pipeline (Evidence, IO Mapper, Core Planner, Scenario) -- */
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-tool-evidence.php';
require_once BIZCITY_INTENT_DIR . '/includes/tools/class-tool-io-mapper.php';
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-core-planner.php';
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-scenario-generator.php';
require_once BIZCITY_INTENT_DIR . '/includes/orchestration/class-objective-parser.php';

/* ── Init CPT registrations ── */
BizCity_Tool_Evidence::init();

require_once BIZCITY_INTENT_DIR . '/services/class-task-service.php';
require_once BIZCITY_INTENT_DIR . '/services/class-session-list-service.php';

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Tools Map (universal AI tools panel)
 *  Touch Bar clicks → /tools-map/?bizcity_iframe=1 → tools overview
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function () {
    add_rewrite_rule( '^tools-map/?$', 'index.php?bizcity_agent_page=tools-map', 'top' );
    add_rewrite_rule( '^tool-control-panel/?$', 'index.php?bizcity_agent_page=tool-control-panel', 'top' );
    add_rewrite_rule( '^tool-stats/?$', 'index.php?bizcity_agent_page=tool-stats', 'top' );
    add_rewrite_rule( '^tasks/?$', 'index.php?bizcity_agent_page=tasks', 'top' );
    add_rewrite_rule( '^tasks/([a-zA-Z0-9_-]+)/?$', 'index.php?bizcity_agent_page=task-detail&bizcity_task_id=$matches[1]', 'top' );
    add_rewrite_rule( '^chat-sessions/?$', 'index.php?bizcity_agent_page=chat-sessions', 'top' );
    add_rewrite_rule( '^chat-sessions/(\d+)/?$', 'index.php?bizcity_agent_page=session-detail&bizcity_session_pk=$matches[1]', 'top' );
} );
add_filter( 'query_vars', function ( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) {
        $vars[] = 'bizcity_agent_page';
    }
    if ( ! in_array( 'bizcity_task_id', $vars, true ) ) {
        $vars[] = 'bizcity_task_id';
    }
    if ( ! in_array( 'bizcity_session_pk', $vars, true ) ) {
        $vars[] = 'bizcity_session_pk';
    }
    return $vars;
} );
add_action( 'template_redirect', function () {
    $page = get_query_var( 'bizcity_agent_page' );
    if ( $page === 'tools-map' ) {
        include BIZCITY_INTENT_DIR . '/views/page-tools-map.php';
        exit;
    }
    if ( $page === 'tool-control-panel' ) {
        include BIZCITY_INTENT_DIR . '/views/page-tool-control-panel.php';
        exit;
    }
    if ( $page === 'tool-stats' ) {
        include BIZCITY_INTENT_DIR . '/views/page-tool-stats.php';
        exit;
    }
    if ( $page === 'tasks' ) {
        include BIZCITY_INTENT_DIR . '/views/page-tasks.php';
        exit;
    }
    if ( $page === 'task-detail' ) {
        include BIZCITY_INTENT_DIR . '/views/page-task-detail.php';
        exit;
    }
    if ( $page === 'chat-sessions' ) {
        include BIZCITY_INTENT_DIR . '/views/page-sessions.php';
        exit;
    }
    if ( $page === 'session-detail' ) {
        include BIZCITY_INTENT_DIR . '/views/page-session-detail.php';
        exit;
    }
} );

/* ── Boot ── */
add_action( 'plugins_loaded', function () {
    // Database table check / creation
    BizCity_Intent_Database::instance()->maybe_create_tables();

    // Context builder (5-layer priority chain)
    BizCity_Context_Builder::instance();

    // Memory services (Rolling + Episodic) — always needed (Smart Gateway uses them)
    BizCity_Rolling_Memory::instance();
    BizCity_Episodic_Memory::instance();

    // Main intent engine orchestrator
    BizCity_Intent_Engine::instance();

    // ── O10: WP-Cron for reliable stale conversation cleanup (v3.6.1) ──
    add_action( 'bizcity_intent_stale_cleanup', function () {
        BizCity_Intent_Database::instance()->expire_stale();
    } );
    if ( ! wp_next_scheduled( 'bizcity_intent_stale_cleanup' ) ) {
        wp_schedule_event( time(), 'hourly', 'bizcity_intent_stale_cleanup' );
    }

    // ── Episodic Memory: daily habit aggregation cron ──
    add_action( 'bizcity_episodic_daily_aggregate', function () {
        BizCity_Episodic_Memory::instance()->cron_daily_aggregate();
    } );
    if ( ! wp_next_scheduled( 'bizcity_episodic_daily_aggregate' ) ) {
        wp_schedule_event( time(), 'daily', 'bizcity_episodic_daily_aggregate' );
    }

    // ── Prompt Context Logger: daily cleanup (v3.9.0) ──
    add_action( 'bizcity_prompt_log_cleanup', function () {
        BizCity_Prompt_Context_Logger::instance()->cleanup( 7 );
    } );
    if ( ! wp_next_scheduled( 'bizcity_prompt_log_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'bizcity_prompt_log_cleanup' );
    }

    // Monitor dashboard (admin only)
    if ( is_admin() ) {
        BizCity_Intent_Monitor::instance();
        BizCity_Intent_Data_Browser::instance();
        BizCity_Tool_Control_Panel::instance();
    }

    // REST API for settings (mobile app ready)
    BizCity_Intent_Settings_API::instance();

    // REST API for tasks & sessions (React / app ready)
    BizCity_Intent_REST_API::instance();

    // Unified REST API — bizcity/v1 (single namespace for bizcity-app)
    BizCity_Unified_REST_API::instance();

    // Fire action so other plugins can register tools
    do_action( 'bizcity_intent_register_tools', BizCity_Intent_Tools::instance() );

    // Provider Registry: let plugins register their skill providers
    $registry = BizCity_Intent_Provider_Registry::instance();
    do_action( 'bizcity_intent_register_providers', $registry );
    $registry->boot();

    // ── Tool Registry: event-driven sync on plugin activate/deactivate ──
    // WordPress fires `activated_plugin` after activate_plugin() succeeds.
    // The newly-activated plugin has already registered its provider above
    // (if it hooked `bizcity_intent_register_providers`), so we can sync it.
    add_action( 'activated_plugin', function ( $plugin_file ) use ( $registry ) {
        // Resolve plugin slug from plugin file (e.g. 'bizcity-tarot/bizcity-tarot.php' → 'bizcity-tarot')
        $slug = dirname( $plugin_file );
        if ( $slug === '.' ) {
            $slug = basename( $plugin_file, '.php' );
        }

        $tool_index = BizCity_Intent_Tool_Index::instance();

        // Check if this plugin registered a provider
        $provider = $registry->get( $slug );
        if ( $provider ) {
            $tool_index->sync_provider( $provider );
        } else {
            // Plugin may not have registered yet (late hook) — schedule re-sync
            // via a transient flag that sync_all() checks on next boot
            $tool_index->ensure_schema();
            // Re-activate any previously-deactivated rows for this plugin
            global $wpdb;
            $table = $wpdb->prefix . 'bizcity_tool_registry';
            $reactivated = $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET active = 1 WHERE plugin = %s AND active = 0",
                $slug
            ) );
            if ( $reactivated > 0 ) {
                delete_transient( BizCity_Intent_Tool_Index::MANIFEST_CACHE_KEY );
                do_action( 'bizcity_tool_registry_changed', 'reactivate', $slug, [] );
            }
        }
    }, 20 );

    // WordPress fires `deactivated_plugin` after deactivate_plugins() succeeds.
    add_action( 'deactivated_plugin', function ( $plugin_file ) {
        $slug = dirname( $plugin_file );
        if ( $slug === '.' ) {
            $slug = basename( $plugin_file, '.php' );
        }
        BizCity_Intent_Tool_Index::instance()->unsync_plugin( $slug );
    }, 20 );

    // ── Prompt Log: record every processed request to DB ──
    add_action( 'bizcity_intent_processed', function ( $result, $params ) {
        // Avoid logging empty/heartbeat requests
        if ( empty( $params['message'] ) ) {
            return;
        }

        static $start_time = null;
        if ( $start_time === null ) {
            $start_time = defined( 'BIZCITY_INTENT_REQUEST_START' )
                ? BIZCITY_INTENT_REQUEST_START
                : microtime( true );
        }

        $meta = $result['meta'] ?? [];

        BizCity_Intent_Database::instance()->insert_prompt_log( [
            'session_id'        => $params['session_id']   ?? '',
            'conversation_id'   => $result['conversation_id'] ?? '',
            'user_id'           => intval( $params['user_id'] ?? 0 ),
            'channel'           => $params['channel']      ?? 'webchat',
            'character_id'      => intval( $params['character_id'] ?? 0 ),
            'blog_id'           => get_current_blog_id(),
            'message'           => $params['message']      ?? '',
            'images_count'      => count( $params['images'] ?? [] ),
            'detected_mode'     => $meta['mode']           ?? ( $meta['pipeline']['pipeline'] ?? '' ),
            'mode_confidence'   => floatval( $meta['mode_confidence'] ?? $meta['confidence'] ?? 0 ),
            'mode_method'       => $meta['mode_method']    ?? '',
            'intent_key'        => $meta['intent_key']     ?? ( $result['goal'] ?? '' ),
            'goal'              => $result['goal']         ?? '',
            'goal_label'        => $result['goal_label']   ?? '',
            'slots'             => $result['slots']        ?? [],
            'context_summary'   => $meta['context_summary']   ?? '',
            'context_layers'    => $meta['context_layers']    ?? [],
            'pipeline_class'    => $meta['pipeline_class']    ?? ( $meta['pipeline']['pipeline'] ?? '' ),
            'pipeline_action'   => $meta['pipeline_action']   ?? ( $result['action'] ?? '' ),
            'tool_calls'        => $meta['tool_calls']        ?? [],
            'provider_used'     => $meta['provider']          ?? '',
            'executor_trace_id' => $meta['executor_trace_id'] ?? '',
            'planner_plan_id'   => $meta['planner_plan_id']   ?? '',
            'response_summary'  => mb_substr( $result['reply'] ?? '', 0, 500, 'UTF-8' ),
            'response_action'   => $result['action']          ?? '',
            'duration_ms'       => round( ( microtime( true ) - $start_time ) * 1000, 2 ),
        ] );
    }, 99, 2 );
}, 5 );

/* ======================================================================
 * PUBLIC HELPER FUNCTIONS
 * Each function is individually guarded — PHP function declarations cannot be
 * conditionally skipped with a single `return`, so each one needs its own
 * if(!function_exists()) wrapper to survive coexistence with the legacy mu-plugin.
 * ====================================================================== */

if ( ! function_exists( 'bizcity_intent_register_tool' ) ) {
    /**
     * Register a tool with the Intent Engine.
     *
     * @param string   $name
     * @param array    $schema
     * @param callable $callback
     */
    function bizcity_intent_register_tool( $name, array $schema, $callback ) {
        BizCity_Intent_Tools::instance()->register( $name, $schema, $callback );
    }
}

if ( ! function_exists( 'bizcity_intent_get_conversation' ) ) {
    /**
     * Get or create an active conversation for a user + channel.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return array|null
     */
    function bizcity_intent_get_conversation( $user_id, $channel = 'webchat', $session_id = '' ) {
        return BizCity_Intent_Conversation::instance()->get_active( $user_id, $channel, $session_id );
    }
}
