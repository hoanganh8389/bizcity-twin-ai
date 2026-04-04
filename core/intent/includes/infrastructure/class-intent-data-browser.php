<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent — Data Browser
 *
 * Admin sub-menu pages for browsing:
 *   • Intent tables  (conversations, turns, prompt_logs, debug_logs)
 *   • Planner tables (candidates, playbooks, experiments, stats, reviews, patches, cache)
 *
 * Replicates the Executor Data Browser pattern with:
 *   • Paginated list with filters & free-text search
 *   • Column sorting
 *   • Export JSON (filtered / full)
 *   • Click-through links between related tables
 *   • Checkbox select + bulk delete
 *   • Record detail modal
 *
 * @package BizCity_Intent
 * @since   3.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Data_Browser {

    /** @var self|null */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menus' ], 35 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // AJAX endpoints
        add_action( 'wp_ajax_bizcity_intent_browse',        [ $this, 'ajax_browse' ] );
        add_action( 'wp_ajax_bizcity_intent_export',        [ $this, 'ajax_export_json' ] );
        add_action( 'wp_ajax_bizcity_intent_record_detail', [ $this, 'ajax_record_detail' ] );
        add_action( 'wp_ajax_bizcity_intent_delete',        [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_bizcity_intent_expand',        [ $this, 'ajax_expand' ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  PAGE DEFINITIONS
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Data browser page definitions.
     *
     * Each entry maps to a DB table with display config:
     *   title   — page <title> / heading
     *   menu    — admin menu label
     *   table   — DB table name (without wp_ prefix)
     *   columns — visible columns in list view
     *   filters — filterable columns (input fields)
     *   order   — default ORDER BY clause
     *   links   — cross-table links { column => target_page_slug }
     */
    public static function get_browser_pages() {
        return [

            /* ── Intent Tables ────────────────────────────── */

            'int-conversations' => [
                'title'   => 'Intent — Conversations',
                'menu'    => '💬 Conversations',
                'table'   => 'bizcity_intent_conversations',
                'columns' => [
                    'id', 'conversation_id', 'user_id', 'session_id', 'channel',
                    'character_id', 'project_id', 'goal', 'goal_label', 'status',
                    'waiting_for', 'turn_count', 'created_at', 'updated_at',
                    'last_activity_at',
                ],
                'filters' => [
                    'conversation_id', 'user_id', 'session_id', 'channel',
                    'character_id', 'project_id', 'status', 'goal',
                ],
                'order'   => 'last_activity_at DESC',
                'links'   => [
                    'conversation_id' => 'int-turns',
                    'session_id'      => 'int-prompt-logs',
                ],
            ],

            'int-turns' => [
                'title'   => 'Intent — Turns',
                'menu'    => '🔄 Turns',
                'table'   => 'bizcity_intent_turns',
                'columns' => [
                    'id', 'conversation_id', 'turn_index', 'role', 'content',
                    'attachments', 'intent', 'slots_delta', 'tool_calls',
                    'meta', 'created_at',
                ],
                'filters' => [ 'conversation_id', 'role', 'intent' ],
                'order'   => 'id DESC',
                'links'   => [
                    'conversation_id' => 'int-conversations',
                ],
            ],

            'int-prompt-logs' => [
                'title'   => 'Intent — Prompt Logs',
                'menu'    => '📝 Prompt Logs',
                'table'   => 'bizcity_intent_prompt_logs',
                'columns' => [
                    'id', 'session_id', 'conversation_id', 'user_id', 'channel',
                    'character_id', 'blog_id', 'detected_mode', 'mode_confidence',
                    'intent_key', 'goal', 'goal_label', 'pipeline_class',
                    'pipeline_action', 'provider_used', 'executor_trace_id',
                    'planner_plan_id', 'duration_ms', 'created_at',
                ],
                'filters' => [
                    'session_id', 'conversation_id', 'user_id', 'channel',
                    'detected_mode', 'intent_key', 'pipeline_class',
                    'provider_used', 'executor_trace_id', 'planner_plan_id',
                ],
                'order'   => 'created_at DESC',
                'links'   => [
                    'conversation_id' => 'int-conversations',
                    'session_id'      => 'int-conversations',
                ],
            ],

            'int-logs' => [
                'title'   => 'Intent — Debug Logs',
                'menu'    => '🐛 Debug Logs',
                'table'   => 'bizcity_intent_logs',
                'columns' => [
                    'id', 'trace_id', 'conversation_id', 'turn_index', 'step',
                    'step_index', 'duration_ms', 'level', 'user_id', 'channel',
                    'created_at',
                ],
                'filters' => [
                    'trace_id', 'conversation_id', 'step', 'level',
                    'user_id', 'channel',
                ],
                'order'   => 'created_at DESC',
                'links'   => [
                    'conversation_id' => 'int-conversations',
                ],
            ],

            /* ── Planner Tables ───────────────────────────── */

            'plan-candidates' => [
                'title'   => 'Planner — Intent Candidates',
                'menu'    => '🎯 Candidates',
                'table'   => 'bizcity_intent_candidates',
                'columns' => [
                    'id', 'intent_key', 'tool_key', 'base_weight', 'role',
                    'domain', 'notes', 'active', 'created_at', 'updated_at',
                ],
                'filters' => [ 'intent_key', 'tool_key', 'role', 'domain', 'active' ],
                'order'   => 'intent_key ASC',
                'links'   => [],
            ],

            'plan-playbooks' => [
                'title'   => 'Planner — Playbooks',
                'menu'    => '📖 Playbooks',
                'table'   => 'bizcity_playbooks',
                'columns' => [
                    'id', 'playbook_key', 'intent_key', 'domain', 'title',
                    'status', 'version', 'created_at', 'updated_at',
                ],
                'filters' => [ 'playbook_key', 'intent_key', 'domain', 'status' ],
                'order'   => 'updated_at DESC',
                'links'   => [
                    'intent_key' => 'plan-candidates',
                ],
            ],

            'plan-experiments' => [
                'title'   => 'Planner — Experiments',
                'menu'    => '🧪 Experiments',
                'table'   => 'bizcity_tool_experiments',
                'columns' => [
                    'id', 'experiment_key', 'intent_key', 'status',
                    'variant_a', 'variant_b', 'allocation_a', 'allocation_b',
                    'winner', 'started_at', 'ended_at', 'created_at', 'updated_at',
                ],
                'filters' => [ 'experiment_key', 'intent_key', 'status', 'winner' ],
                'order'   => 'created_at DESC',
                'links'   => [
                    'intent_key' => 'plan-candidates',
                ],
            ],

            'plan-tool-stats' => [
                'title'   => 'Planner — Tool Stats',
                'menu'    => '📊 Tool Stats',
                'table'   => 'bizcity_tool_stats',
                'columns' => [
                    'id', 'tool_key', 'env', 'window_days', 'n_calls',
                    'n_success', 'n_fail', 'n_retry', 'success_rate',
                    'retry_rate', 'p50_ms', 'p95_ms', 'avg_cost', 'updated_at',
                ],
                'filters' => [ 'tool_key', 'env', 'window_days' ],
                'order'   => 'updated_at DESC',
                'links'   => [],
            ],

            'plan-reviews' => [
                'title'   => 'Planner — Trace Reviews',
                'menu'    => '✅ Reviews',
                'table'   => 'bizcity_trace_reviews',
                'columns' => [
                    'id', 'trace_id', 'intent_key', 'playbook_key', 'variant',
                    'outcome', 'quality_score', 'duration_ms', 'task_count',
                    'task_succeeded', 'task_failed', 'root_cause_tag',
                    'error_code', 'reviewed_at',
                ],
                'filters' => [
                    'trace_id', 'intent_key', 'playbook_key', 'outcome',
                    'root_cause_tag', 'error_code',
                ],
                'order'   => 'reviewed_at DESC',
                'links'   => [
                    'playbook_key' => 'plan-playbooks',
                ],
            ],

            'plan-patches' => [
                'title'   => 'Planner — Registry Patches',
                'menu'    => '🩹 Patches',
                'table'   => 'bizcity_registry_patches',
                'columns' => [
                    'id', 'target_type', 'target_key', 'patch_type', 'reason',
                    'evidence_count', 'evidence_trace_id', 'status',
                    'created_at', 'updated_at',
                ],
                'filters' => [ 'target_type', 'target_key', 'patch_type', 'status' ],
                'order'   => 'created_at DESC',
                'links'   => [],
            ],

            'plan-cache' => [
                'title'   => 'Planner — Plan Cache',
                'menu'    => '💾 Plan Cache',
                'table'   => 'bizcity_planner_cache',
                'columns' => [
                    'id', 'intent_hash', 'intent_key', 'playbook_key',
                    'hit_count', 'expires_at', 'created_at',
                ],
                'filters' => [ 'intent_key', 'playbook_key' ],
                'order'   => 'created_at DESC',
                'links'   => [
                    'playbook_key' => 'plan-playbooks',
                ],
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  MENU REGISTRATION
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Register data browser sub-menus under Intent Monitor parent.
     */
    public function register_menus() {
        $pages = self::get_browser_pages();
        foreach ( $pages as $slug => $page ) {
            add_submenu_page(
                'bizcity-intent-monitor',
                $page['title'],
                $page['menu'],
                'manage_options',
                'bizcity-idb-' . $slug,
                [ $this, 'render_page' ]
            );
        }
    }

    /* ══════════════════════════════════════════════════════════════
     *  ASSETS
     * ══════════════════════════════════════════════════════════════ */

    public function enqueue_assets( $hook ) {
        // Only load on our data browser pages
        if ( strpos( $hook, 'bizcity-idb-' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'bizcity-intent-data-browser',
            BIZCITY_INTENT_URL . '/assets/data-browser.css',
            [],
            BIZCITY_INTENT_VERSION
        );

        wp_enqueue_script(
            'bizcity-intent-data-browser',
            BIZCITY_INTENT_URL . '/assets/data-browser.js',
            [ 'jquery' ],
            BIZCITY_INTENT_VERSION,
            true
        );

        // Detect which page slug from hook
        $page_slug = str_replace( 'intent-monitor_page_bizcity-idb-', '', $hook );
        $pages     = self::get_browser_pages();
        $page_conf = $pages[ $page_slug ] ?? [];

        wp_localize_script( 'bizcity-intent-data-browser', 'BizIntentBrowser', [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'bizcity_intent_browser' ),
            'slug'           => $page_slug,
            'table'          => $page_conf['table']   ?? '',
            'columns'        => $page_conf['columns'] ?? [],
            'filters'        => $page_conf['filters'] ?? [],
            'links'          => $page_conf['links']   ?? [],
            'base_url'       => admin_url( 'admin.php' ),
            // AJAX actions (so JS is generic and reusable)
            'action_browse'  => 'bizcity_intent_browse',
            'action_export'  => 'bizcity_intent_export',
            'action_detail'  => 'bizcity_intent_record_detail',
            'action_delete'  => 'bizcity_intent_delete',
            'action_expand'  => 'bizcity_intent_expand',
            'page_prefix'    => 'bizcity-idb-',
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  PAGE RENDER
     * ══════════════════════════════════════════════════════════════ */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }
        require_once BIZCITY_INTENT_DIR . '/views/data-browser.php';
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Browse (paginated list with filters)
     * ══════════════════════════════════════════════════════════════ */

    public function ajax_browse() {
        check_ajax_referer( 'bizcity_intent_browser', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $result = $this->query_browser_data( $_GET );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Export JSON
     * ══════════════════════════════════════════════════════════════ */

    public function ajax_export_json() {
        check_ajax_referer( 'bizcity_intent_browser', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $params        = $_GET;
        $params['per']  = min( absint( $params['per'] ?? 10000 ), 10000 );
        $params['page'] = 1;

        $result = $this->query_browser_data( $params );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $table_key = sanitize_text_field( $params['table'] ?? '' );

        $export = [
            'exported_at' => current_time( 'mysql' ),
            'blog_id'     => get_current_blog_id(),
            'table'       => $table_key,
            'filters'     => $this->extract_filters( $params ),
            'total'       => $result['total'],
            'rows'        => $result['rows'],
        ];

        // Decode JSON columns for cleaner export
        foreach ( $export['rows'] as &$row ) {
            $row = $this->decode_json_fields( $row );
        }
        unset( $row );

        // Related data export
        if ( ! empty( $params['related'] ) ) {
            $export['related'] = $this->export_related( $result['rows'], $table_key );
        }

        $filename = 'intent-' . sanitize_file_name( $table_key )
                  . '-' . gmdate( 'Y-m-d_His' ) . '.json';

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Cache-Control: no-cache, must-revalidate' );

        echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        wp_die();
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Record Detail
     * ══════════════════════════════════════════════════════════════ */

    public function ajax_record_detail() {
        check_ajax_referer( 'bizcity_intent_browser', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $table_key = sanitize_text_field( $_GET['table'] ?? '' );
        $row_id    = absint( $_GET['id'] ?? 0 );

        $pages = self::get_browser_pages();
        $match = null;
        foreach ( $pages as $slug => $page ) {
            if ( $page['table'] === $table_key ) {
                $match = $page;
                break;
            }
        }
        if ( ! $match || ! $row_id ) {
            wp_send_json_error( 'Invalid table or id' );
        }

        $db_table = $wpdb->prefix . $table_key;
        $row      = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$db_table}` WHERE `id` = %d", $row_id
        ), ARRAY_A );

        if ( ! $row ) {
            wp_send_json_error( 'Record not found', 404 );
        }

        $row = $this->decode_json_fields( $row );

        // Fetch related records based on available FK columns
        $related = $this->fetch_related( $row, $table_key );

        wp_send_json_success( [ 'record' => $row, 'related' => $related ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Bulk Delete
     * ══════════════════════════════════════════════════════════════ */

    public function ajax_delete() {
        check_ajax_referer( 'bizcity_intent_browser', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        $table_key = sanitize_text_field( $_POST['table'] ?? '' );
        $ids_raw   = sanitize_text_field( $_POST['ids'] ?? '' );

        // Validate table key
        $pages = self::get_browser_pages();
        $found = false;
        foreach ( $pages as $slug => $page ) {
            if ( $page['table'] === $table_key ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            wp_send_json_error( 'Invalid table: ' . $table_key );
        }

        $ids = array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( 'No valid IDs provided' );
        }

        $db_table     = $wpdb->prefix . $table_key;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $deleted      = $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$db_table}` WHERE `id` IN ({$placeholders})",
            ...$ids
        ) );

        wp_send_json_success( [
            'deleted' => (int) $deleted,
            'total'   => count( $ids ),
        ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  AJAX: Inline Expand — related data by FK columns
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Expand a row inline: fetch related records from intent, planner, and executor tables.
     *
     * Lookup paths:
     *   conversation_id → turns, prompt_logs, debug_logs, conversations, executor traces + tasks
     *   executor_trace_id → executor traces + trace_tasks
     *   message_id → executor traces (by message_id) + trace_tasks
     *   session_id → conversations, prompt_logs
     *   intent_key → candidates, playbooks
     */
    public function ajax_expand() {
        check_ajax_referer( 'bizcity_intent_browser', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $table_key = sanitize_text_field( $_GET['table'] ?? '' );
        $row_id    = absint( $_GET['id'] ?? 0 );

        $pages = self::get_browser_pages();
        $match = null;
        foreach ( $pages as $slug => $page ) {
            if ( $page['table'] === $table_key ) {
                $match = $page;
                break;
            }
        }
        if ( ! $match || ! $row_id ) {
            wp_send_json_error( 'Invalid table or id' );
        }

        $db_table = $wpdb->prefix . $table_key;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$db_table}` WHERE `id` = %d", $row_id
        ), ARRAY_A );
        if ( ! $row ) {
            wp_send_json_error( 'Record not found' );
        }

        $prefix   = $wpdb->prefix;
        $sections = [];

        $conv_id           = $row['conversation_id']   ?? '';
        $executor_trace_id = $row['executor_trace_id'] ?? '';
        $session_id        = $row['session_id']        ?? '';
        $message_id        = $row['message_id']        ?? '';
        $intent_key        = $row['intent_key']        ?? '';
        $trace_id_field    = $row['trace_id']          ?? '';

        /* ── By conversation_id ──────────────────────────── */
        if ( $conv_id ) {
            if ( $table_key !== 'bizcity_intent_conversations' ) {
                $sections['conversation'] = [
                    'label' => "\xF0\x9F\x92\xAC Conversation",
                    'link'  => '?page=bizcity-idb-int-conversations&f_conversation_id=' . urlencode( $conv_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, conversation_id, user_id, session_id, channel, goal, goal_label, status, turn_count, created_at, last_activity_at
                         FROM `{$prefix}bizcity_intent_conversations`
                         WHERE `conversation_id` = %s LIMIT 5", $conv_id
                    ), ARRAY_A ) ?: [],
                ];
            }

            if ( $table_key !== 'bizcity_intent_turns' ) {
                $sections['turns'] = [
                    'label' => "\xF0\x9F\x94\x84 Turns",
                    'link'  => '?page=bizcity-idb-int-turns&f_conversation_id=' . urlencode( $conv_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, turn_index, role, content, intent, slots_delta, created_at
                         FROM `{$prefix}bizcity_intent_turns`
                         WHERE `conversation_id` = %s ORDER BY turn_index ASC LIMIT 50", $conv_id
                    ), ARRAY_A ) ?: [],
                ];
            }

            if ( $table_key !== 'bizcity_intent_prompt_logs' ) {
                $sections['prompt_logs'] = [
                    'label' => "\xF0\x9F\x93\x9D Prompt Logs",
                    'link'  => '?page=bizcity-idb-int-prompt-logs&f_conversation_id=' . urlencode( $conv_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, detected_mode, intent_key, pipeline_class, provider_used, executor_trace_id, duration_ms, created_at
                         FROM `{$prefix}bizcity_intent_prompt_logs`
                         WHERE `conversation_id` = %s ORDER BY created_at DESC LIMIT 20", $conv_id
                    ), ARRAY_A ) ?: [],
                ];
            }

            if ( $table_key !== 'bizcity_intent_logs' ) {
                $sections['debug_logs'] = [
                    'label' => "\xF0\x9F\x90\x9B Debug Logs",
                    'link'  => '?page=bizcity-idb-int-logs&f_conversation_id=' . urlencode( $conv_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, trace_id, turn_index, step, level, duration_ms, created_at
                         FROM `{$prefix}bizcity_intent_logs`
                         WHERE `conversation_id` = %s ORDER BY created_at DESC LIMIT 20", $conv_id
                    ), ARRAY_A ) ?: [],
                ];
            }

            // Executor traces by conv_id
            $exec_table = $prefix . 'bizcity_traces';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $exec_table ) ) === $exec_table ) {
                $exec_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, trace_id, message_id, session_id, conv_id, intent_key, title, status, created_at, ended_at
                     FROM `{$exec_table}`
                     WHERE `conv_id` = %s ORDER BY created_at DESC LIMIT 20", $conv_id
                ), ARRAY_A ) ?: [];

                if ( $exec_rows ) {
                    $sections['executor_traces'] = [
                        'label' => "\xE2\x9A\x99 Executor Traces",
                        'link'  => '?page=bizcity-exec-traces&f_conv_id=' . urlencode( $conv_id ),
                        'rows'  => $exec_rows,
                    ];

                    // Trace tasks for those traces
                    $trace_ids = array_column( $exec_rows, 'trace_id' );
                    if ( $trace_ids ) {
                        $task_table   = $prefix . 'bizcity_trace_tasks';
                        $placeholders = implode( ',', array_fill( 0, count( $trace_ids ), '%s' ) );
                        $sections['executor_tasks'] = [
                            'label' => "\xE2\x9A\x99 Executor Tasks",
                            'rows'  => $wpdb->get_results( $wpdb->prepare(
                                "SELECT id, trace_id, task_id, title, tool_name, status, attempt, created_at
                                 FROM `{$task_table}`
                                 WHERE `trace_id` IN ({$placeholders}) ORDER BY created_at ASC LIMIT 50",
                                ...$trace_ids
                            ), ARRAY_A ) ?: [],
                        ];
                    }
                }
            }
        }

        /* ── By executor_trace_id (from prompt_logs) ────── */
        if ( $executor_trace_id && empty( $sections['executor_traces'] ) ) {
            $exec_table = $prefix . 'bizcity_traces';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $exec_table ) ) === $exec_table ) {
                $sections['executor_traces'] = [
                    'label' => "\xE2\x9A\x99 Executor Trace",
                    'link'  => '?page=bizcity-exec-traces&f_trace_id=' . urlencode( $executor_trace_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, trace_id, message_id, session_id, conv_id, intent_key, title, status, created_at, ended_at
                         FROM `{$exec_table}`
                         WHERE `trace_id` = %s LIMIT 1", $executor_trace_id
                    ), ARRAY_A ) ?: [],
                ];

                $task_table = $prefix . 'bizcity_trace_tasks';
                $sections['executor_tasks'] = [
                    'label' => "\xE2\x9A\x99 Executor Tasks",
                    'link'  => '?page=bizcity-exec-trace-tasks&f_trace_id=' . urlencode( $executor_trace_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, trace_id, task_id, title, tool_name, status, attempt, created_at
                         FROM `{$task_table}`
                         WHERE `trace_id` = %s ORDER BY created_at ASC LIMIT 50", $executor_trace_id
                    ), ARRAY_A ) ?: [],
                ];
            }
        }

        /* ── By message_id ───────────────────────────────── */
        if ( $message_id && empty( $sections['executor_traces'] ) ) {
            $exec_table = $prefix . 'bizcity_traces';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $exec_table ) ) === $exec_table ) {
                $exec_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, trace_id, message_id, session_id, conv_id, intent_key, title, status, created_at, ended_at
                     FROM `{$exec_table}`
                     WHERE `message_id` = %s ORDER BY created_at DESC LIMIT 20", $message_id
                ), ARRAY_A ) ?: [];

                if ( $exec_rows ) {
                    $sections['executor_traces'] = [
                        'label' => "\xE2\x9A\x99 Executor Traces (message_id)",
                        'link'  => '?page=bizcity-exec-traces&f_message_id=' . urlencode( $message_id ),
                        'rows'  => $exec_rows,
                    ];

                    $trace_ids = array_column( $exec_rows, 'trace_id' );
                    if ( $trace_ids ) {
                        $task_table   = $prefix . 'bizcity_trace_tasks';
                        $placeholders = implode( ',', array_fill( 0, count( $trace_ids ), '%s' ) );
                        $sections['executor_tasks'] = [
                            'label' => "\xE2\x9A\x99 Executor Tasks",
                            'rows'  => $wpdb->get_results( $wpdb->prepare(
                                "SELECT id, trace_id, task_id, title, tool_name, status, attempt, created_at
                                 FROM `{$task_table}`
                                 WHERE `trace_id` IN ({$placeholders}) ORDER BY created_at ASC LIMIT 50",
                                ...$trace_ids
                            ), ARRAY_A ) ?: [],
                        ];
                    }
                }
            }
        }

        /* ── By session_id ───────────────────────────────── */
        if ( $session_id && empty( $sections['conversation'] ) ) {
            if ( $table_key !== 'bizcity_intent_conversations' ) {
                $sections['session_convs'] = [
                    'label' => "\xF0\x9F\x97\x82 Session Conversations",
                    'link'  => '?page=bizcity-idb-int-conversations&f_session_id=' . urlencode( $session_id ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, conversation_id, user_id, channel, goal, goal_label, status, turn_count, created_at
                         FROM `{$prefix}bizcity_intent_conversations`
                         WHERE `session_id` = %s ORDER BY created_at DESC LIMIT 20", $session_id
                    ), ARRAY_A ) ?: [],
                ];
            }
        }

        /* ── By intent_key (planner relations) ──────────── */
        if ( $intent_key ) {
            if ( $table_key !== 'bizcity_intent_candidates' ) {
                $sections['candidates'] = [
                    'label' => "\xF0\x9F\x8E\xAF Candidates",
                    'link'  => '?page=bizcity-idb-plan-candidates&f_intent_key=' . urlencode( $intent_key ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, intent_key, tool_key, base_weight, role, domain, active
                         FROM `{$prefix}bizcity_intent_candidates`
                         WHERE `intent_key` = %s AND `active` = 1 LIMIT 20", $intent_key
                    ), ARRAY_A ) ?: [],
                ];
            }
            if ( $table_key !== 'bizcity_playbooks' ) {
                $sections['playbooks'] = [
                    'label' => "\xF0\x9F\x93\x96 Playbooks",
                    'link'  => '?page=bizcity-idb-plan-playbooks&f_intent_key=' . urlencode( $intent_key ),
                    'rows'  => $wpdb->get_results( $wpdb->prepare(
                        "SELECT id, playbook_key, intent_key, title, status, version, updated_at
                         FROM `{$prefix}bizcity_playbooks`
                         WHERE `intent_key` = %s ORDER BY version DESC LIMIT 10", $intent_key
                    ), ARRAY_A ) ?: [],
                ];
            }
        }

        /* ── By trace_id (debug logs → executor link) ─── */
        if ( $trace_id_field && empty( $sections['executor_traces'] ) ) {
            $exec_table = $prefix . 'bizcity_traces';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $exec_table ) ) === $exec_table ) {
                $exec_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, trace_id, message_id, session_id, conv_id, intent_key, title, status, created_at, ended_at
                     FROM `{$exec_table}`
                     WHERE `trace_id` = %s LIMIT 1", $trace_id_field
                ), ARRAY_A ) ?: [];

                if ( $exec_rows ) {
                    $sections['executor_traces'] = [
                        'label' => "\xE2\x9A\x99 Executor Trace",
                        'link'  => '?page=bizcity-exec-traces&f_trace_id=' . urlencode( $trace_id_field ),
                        'rows'  => $exec_rows,
                    ];
                    $task_table = $prefix . 'bizcity_trace_tasks';
                    $sections['executor_tasks'] = [
                        'label' => "\xE2\x9A\x99 Executor Tasks",
                        'link'  => '?page=bizcity-exec-trace-tasks&f_trace_id=' . urlencode( $trace_id_field ),
                        'rows'  => $wpdb->get_results( $wpdb->prepare(
                            "SELECT id, trace_id, task_id, title, tool_name, status, attempt, created_at
                             FROM `{$task_table}`
                             WHERE `trace_id` = %s ORDER BY created_at ASC LIMIT 50", $trace_id_field
                        ), ARRAY_A ) ?: [],
                    ];
                }
            }
        }

        // Filter out empty sections
        $sections = array_filter( $sections, function ( $s ) {
            return ! empty( $s['rows'] );
        } );

        wp_send_json_success( [ 'sections' => $sections ] );
    }

    /* ══════════════════════════════════════════════════════════════
     *  QUERY BUILDER
     * ══════════════════════════════════════════════════════════════ */

    private function query_browser_data( array $params ) {
        global $wpdb;

        $table_key = sanitize_text_field( $params['table'] ?? '' );
        $page_num  = max( 1, absint( $params['page'] ?? 1 ) );
        $per_page  = min( max( 1, absint( $params['per'] ?? 50 ) ), 10000 );
        $sort_col  = sanitize_key( $params['sort']  ?? 'id' );
        $sort_dir  = strtoupper( $params['dir'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';
        $search    = sanitize_text_field( $params['search'] ?? '' );

        // Validate table key
        $pages = self::get_browser_pages();
        $found = null;
        foreach ( $pages as $slug => $page ) {
            if ( $page['table'] === $table_key ) {
                $found = $page;
                break;
            }
        }
        if ( ! $found ) {
            return new \WP_Error( 'invalid_table', 'Unknown table: ' . $table_key );
        }

        $db_table = $wpdb->prefix . $table_key;

        // Build WHERE
        $where_parts = [ '1=1' ];
        $where_args  = [];

        $filters = $this->extract_filters( $params );
        foreach ( $filters as $col => $val ) {
            if ( $val === '' ) continue;
            if ( ! in_array( $col, $found['columns'], true ) && ! in_array( $col, $found['filters'], true ) ) continue;
            $safe_col = preg_replace( '/[^a-z0-9_]/i', '', $col );
            if ( strpos( $val, '%' ) !== false ) {
                $where_parts[] = "`{$safe_col}` LIKE %s";
            } else {
                $where_parts[] = "`{$safe_col}` = %s";
            }
            $where_args[] = $val;
        }

        // Free text search across varchar columns
        if ( $search ) {
            $numeric_cols = [
                'id', 'user_id', 'character_id', 'blog_id', 'turn_index', 'step_index',
                'turn_count', 'mode_confidence', 'duration_ms', 'base_weight', 'active',
                'version', 'allocation_a', 'allocation_b', 'n_calls', 'n_success',
                'n_fail', 'n_retry', 'success_rate', 'retry_rate', 'p50_ms', 'p95_ms',
                'avg_cost', 'quality_score', 'task_count', 'task_succeeded', 'task_failed',
                'evidence_count', 'hit_count', 'images_count',
            ];
            $search_cols = array_filter( $found['columns'], function ( $c ) use ( $numeric_cols ) {
                return ! in_array( $c, $numeric_cols, true );
            } );
            if ( $search_cols ) {
                $or_parts = [];
                foreach ( $search_cols as $col ) {
                    $safe_col     = preg_replace( '/[^a-z0-9_]/i', '', $col );
                    $or_parts[]   = "`{$safe_col}` LIKE %s";
                    $where_args[] = '%' . $wpdb->esc_like( $search ) . '%';
                }
                $where_parts[] = '(' . implode( ' OR ', $or_parts ) . ')';
            }
        }

        $where_sql = implode( ' AND ', $where_parts );

        // Validate sort column
        $safe_sort = preg_replace( '/[^a-z0-9_]/i', '', $sort_col );
        if ( ! in_array( $safe_sort, $found['columns'], true ) ) {
            $safe_sort = 'id';
        }

        // Count total
        $count_sql = "SELECT COUNT(*) FROM `{$db_table}` WHERE {$where_sql}";
        if ( $where_args ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$where_args ) );
        } else {
            $total = (int) $wpdb->get_var( $count_sql );
        }

        // Query rows
        $offset    = ( $page_num - 1 ) * $per_page;
        $query_sql = "SELECT * FROM `{$db_table}` WHERE {$where_sql} ORDER BY `{$safe_sort}` {$sort_dir} LIMIT %d OFFSET %d";
        $all_args  = array_merge( $where_args, [ $per_page, $offset ] );
        $rows      = $wpdb->get_results( $wpdb->prepare( $query_sql, ...$all_args ), ARRAY_A );

        return [
            'rows'    => $rows ?? [],
            'total'   => $total,
            'page'    => $page_num,
            'per'     => $per_page,
            'columns' => $found['columns'],
        ];
    }

    /* ══════════════════════════════════════════════════════════════
     *  HELPERS
     * ══════════════════════════════════════════════════════════════ */

    /**
     * Extract filter params from request (f_col = val).
     */
    private function extract_filters( array $params ) {
        $filters = [];
        foreach ( $params as $k => $v ) {
            if ( strpos( $k, 'f_' ) === 0 ) {
                $col = sanitize_key( substr( $k, 2 ) );
                $filters[ $col ] = sanitize_text_field( $v );
            }
        }
        return $filters;
    }

    /**
     * Fetch related records for a single record's detail view.
     */
    private function fetch_related( array $row, string $table_key ) {
        global $wpdb;
        $related = [];
        $prefix  = $wpdb->prefix;

        // ── Intent cross-links ──
        $conv_id = $row['conversation_id'] ?? '';

        if ( $conv_id && $table_key !== 'bizcity_intent_conversations' ) {
            $related['conversation'] = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, conversation_id, user_id, session_id, channel, goal, goal_label, status, turn_count, created_at, last_activity_at
                 FROM `{$prefix}bizcity_intent_conversations`
                 WHERE `conversation_id` = %s LIMIT 1",
                $conv_id
            ), ARRAY_A );
        }

        if ( $conv_id && $table_key !== 'bizcity_intent_turns' ) {
            $related['turns'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, turn_index, role, intent, created_at
                 FROM `{$prefix}bizcity_intent_turns`
                 WHERE `conversation_id` = %s ORDER BY turn_index ASC LIMIT 50",
                $conv_id
            ), ARRAY_A );
        }

        if ( $conv_id && $table_key !== 'bizcity_intent_prompt_logs' ) {
            $related['prompt_logs'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, detected_mode, intent_key, pipeline_class, provider_used, duration_ms, created_at
                 FROM `{$prefix}bizcity_intent_prompt_logs`
                 WHERE `conversation_id` = %s ORDER BY created_at DESC LIMIT 20",
                $conv_id
            ), ARRAY_A );
        }

        // ── Executor trace link ──
        $executor_trace_id = $row['executor_trace_id'] ?? '';
        if ( $executor_trace_id ) {
            // Check if executor tables exist
            $exec_table = $prefix . 'bizcity_executor_traces';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$exec_table}'" ) === $exec_table ) {
                $related['executor_trace'] = $wpdb->get_row( $wpdb->prepare(
                    "SELECT id, trace_id, title, status, intent_key, created_at, ended_at
                     FROM `{$exec_table}` WHERE `trace_id` = %s LIMIT 1",
                    $executor_trace_id
                ), ARRAY_A );
            }
        }

        // ── Planner cross-links ──
        $playbook_key = $row['playbook_key'] ?? '';
        if ( $playbook_key && $table_key !== 'bizcity_playbooks' ) {
            $related['playbook'] = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, playbook_key, intent_key, title, status, version, updated_at
                 FROM `{$prefix}bizcity_playbooks`
                 WHERE `playbook_key` = %s ORDER BY version DESC LIMIT 1",
                $playbook_key
            ), ARRAY_A );
        }

        $intent_key = $row['intent_key'] ?? '';
        if ( $intent_key && $table_key !== 'bizcity_intent_candidates' ) {
            $related['candidates'] = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, intent_key, tool_key, base_weight, role, active
                 FROM `{$prefix}bizcity_intent_candidates`
                 WHERE `intent_key` = %s AND `active` = 1 LIMIT 20",
                $intent_key
            ), ARRAY_A );
        }

        // Filter out empty
        return array_filter( $related, function ( $v ) {
            if ( is_array( $v ) && empty( $v ) ) return false;
            return $v !== null;
        } );
    }

    /**
     * Export related records when exporting rows.
     */
    private function export_related( array $rows, string $table_key ) {
        global $wpdb;
        $prefix  = $wpdb->prefix;
        $related = [];

        // Collect unique conversation_ids
        $conv_ids = array_unique( array_filter( array_column( $rows, 'conversation_id' ) ) );

        if ( ! empty( $conv_ids ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%s' ) );

            $tables_to_export = [
                'bizcity_intent_conversations' => 'intent_conversations',
                'bizcity_intent_turns'         => 'intent_turns',
                'bizcity_intent_prompt_logs'   => 'intent_prompt_logs',
                'bizcity_intent_logs'          => 'intent_logs',
            ];
            unset( $tables_to_export[ $table_key ] );

            foreach ( $tables_to_export as $tbl => $label ) {
                $full_table = $prefix . $tbl;
                $sql = $wpdb->prepare(
                    "SELECT * FROM `{$full_table}` WHERE `conversation_id` IN ({$placeholders}) ORDER BY id ASC LIMIT 5000",
                    ...$conv_ids
                );
                $result = $wpdb->get_results( $sql, ARRAY_A );
                if ( $result ) {
                    $related[ $label ] = array_map( [ $this, 'decode_json_fields' ], $result );
                }
            }
        }

        // Also export by intent_key for planner tables
        $intent_keys = array_unique( array_filter( array_column( $rows, 'intent_key' ) ) );
        if ( ! empty( $intent_keys ) ) {
            $placeholders = implode( ',', array_fill( 0, count( $intent_keys ), '%s' ) );

            $planner_tables = [
                'bizcity_intent_candidates' => 'intent_candidates',
                'bizcity_playbooks'         => 'playbooks',
            ];
            unset( $planner_tables[ $table_key ] );

            foreach ( $planner_tables as $tbl => $label ) {
                $full_table = $prefix . $tbl;
                $sql = $wpdb->prepare(
                    "SELECT * FROM `{$full_table}` WHERE `intent_key` IN ({$placeholders}) ORDER BY id ASC LIMIT 2000",
                    ...$intent_keys
                );
                $result = $wpdb->get_results( $sql, ARRAY_A );
                if ( $result ) {
                    $related[ $label ] = array_map( [ $this, 'decode_json_fields' ], $result );
                }
            }
        }

        return $related;
    }

    /**
     * Decode JSON string columns into arrays for display/export.
     */
    private function decode_json_fields( array $row ) {
        $json_cols = [
            'slots_json', 'slots_delta', 'rolling_summary', 'open_loops',
            'context_snapshot', 'attachments', 'tool_calls', 'meta',
            'context_layers_json', 'tool_calls_json', 'data_json',
            'selector_json', 'required_inputs_json', 'plan_template_json',
            'acceptance_template_json', 'runbook_json', 'metric_json',
            'results_json', 'error_top_json', 'failure_pattern_json',
            'suggested_patch_json', 'patch_json', 'plan_json', 'settings',
            'knowledge_ids', 'file_ids',
        ];
        foreach ( $json_cols as $col ) {
            if ( isset( $row[ $col ] ) && is_string( $row[ $col ] ) ) {
                $decoded = json_decode( $row[ $col ], true );
                if ( $decoded !== null ) {
                    $row[ $col ] = $decoded;
                }
            }
        }
        return $row;
    }
}
