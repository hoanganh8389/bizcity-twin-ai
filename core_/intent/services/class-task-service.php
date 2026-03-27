<?php
/**
 * Task Service — Paginated queries for intent tasks (nhiệm vụ)
 *
 * Provides reusable business logic for listing and inspecting
 * intent conversations (goals / nhiệm vụ). Used by both the REST API
 * and any future React / mobile app client.
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Task_Service {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ================================================================
     * DB helper
     * ================================================================ */

    /** @return BizCity_Intent_Database|null */
    private function get_db() {
        return class_exists( 'BizCity_Intent_Database' )
            ? BizCity_Intent_Database::instance()
            : null;
    }

    /* ================================================================
     * List tasks — paginated
     * ================================================================ */

    /**
     * Get paginated list of tasks for a user.
     *
     * @param int         $user_id
     * @param array       $args {
     *   @type string $channel    adminchat | webchat (default: adminchat)
     *   @type string $status     Filter by status (ACTIVE|COMPLETED|CANCELLED|...|all)
     *   @type string $project_id Filter by project UUID
     *   @type string $search     Free-text search in goal_label / goal
     *   @type int    $page       1-based page number
     *   @type int    $per_page   Items per page (max 100)
     *   @type string $order      ASC | DESC (default: DESC)
     * }
     * @return array { items: array, total: int, page: int, per_page: int, total_pages: int }
     */
    public function list_tasks( $user_id, array $args = [] ) {
        global $wpdb;

        $db = $this->get_db();
        if ( ! $db ) {
            return $this->empty_paged();
        }

        $channel    = sanitize_text_field( $args['channel']    ?? 'adminchat' );
        $status     = sanitize_text_field( $args['status']     ?? 'all' );
        $project_id = isset( $args['project_id'] ) ? sanitize_text_field( $args['project_id'] ) : null;
        $search     = sanitize_text_field( $args['search']     ?? '' );
        $page       = max( 1, intval( $args['page']            ?? 1 ) );
        $per_page   = min( 100, max( 1, intval( $args['per_page'] ?? 20 ) ) );
        $order      = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $table  = $wpdb->prefix . 'bizcity_intent_conversations';
        $wheres = [ 'user_id = %d' ];
        $params = [ $user_id ];

        // Channel
        if ( $channel ) {
            $wheres[] = 'channel = %s';
            $params[] = $channel;
        }

        // Status filter
        if ( $status && $status !== 'all' ) {
            $wheres[] = 'status = %s';
            $params[] = strtoupper( $status );
        }

        // Must have a goal (skip empty chitchat rows)
        $wheres[] = "goal != ''";

        // Project filter
        if ( $project_id !== null ) {
            $wheres[] = 'project_id = %s';
            $params[] = $project_id;
        }

        // Search
        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $wheres[] = '(goal_label LIKE %s OR goal LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        // Exclude very old expired rows
        $wheres[] = "NOT (status = 'EXPIRED' AND last_activity_at < DATE_SUB(NOW(), INTERVAL 30 DAY))";

        $where_sql = 'WHERE ' . implode( ' AND ', $wheres );

        // Count total
        $count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
        $total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) );

        // Fetch page
        $offset     = ( $page - 1 ) * $per_page;
        $data_sql   = "SELECT * FROM {$table} {$where_sql}
                       ORDER BY last_activity_at {$order}
                       LIMIT %d OFFSET %d";
        $all_params = array_merge( $params, [ $per_page, $offset ] );
        $rows       = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$all_params ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $items = [];
        foreach ( $rows as $row ) {
            $items[] = $this->format_task( $row );
        }

        return [
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /* ================================================================
     * Single task detail
     * ================================================================ */

    /**
     * Get a single task with full detail.
     *
     * @param string $conversation_id
     * @param int    $user_id  For ownership check (0 = skip check)
     * @return array|WP_Error
     */
    public function get_task( $conversation_id, $user_id = 0 ) {
        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Intent database not available', [ 'status' => 500 ] );
        }

        $conv = $db->get_conversation( $conversation_id );
        if ( ! $conv ) {
            return new WP_Error( 'not_found', 'Task not found', [ 'status' => 404 ] );
        }

        // Ownership check
        if ( $user_id > 0 && (int) $conv->user_id !== $user_id ) {
            return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
        }

        return $this->format_task_detail( $conv );
    }

    /* ================================================================
     * Task turns (conversation history)
     * ================================================================ */

    /**
     * Get turns for a task with pagination.
     *
     * @param string $conversation_id
     * @param int    $user_id  For ownership check
     * @param int    $page
     * @param int    $per_page
     * @return array|WP_Error
     */
    public function get_task_turns( $conversation_id, $user_id = 0, $page = 1, $per_page = 50 ) {
        global $wpdb;

        $db = $this->get_db();
        if ( ! $db ) {
            return new WP_Error( 'no_db', 'Intent database not available', [ 'status' => 500 ] );
        }

        $conv = $db->get_conversation( $conversation_id );
        if ( ! $conv ) {
            return new WP_Error( 'not_found', 'Task not found', [ 'status' => 404 ] );
        }

        if ( $user_id > 0 && (int) $conv->user_id !== $user_id ) {
            return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
        }

        $table   = $wpdb->prefix . 'bizcity_intent_turns';
        $per_page = min( 100, max( 1, $per_page ) );
        $page     = max( 1, $page );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %s",
            $conversation_id
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE conversation_id = %s
             ORDER BY turn_index ASC
             LIMIT %d OFFSET %d",
            $conversation_id,
            $per_page,
            $offset
        ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $turns = [];
        foreach ( $rows as $row ) {
            $turns[] = [
                'turn_index'  => (int) ( $row->turn_index ?? 0 ),
                'role'        => $row->role ?? 'assistant',
                'content'     => $row->content ?? '',
                'tool_calls'  => ! empty( $row->tool_calls ) ? json_decode( $row->tool_calls, true ) : null,
                'slots_delta' => ! empty( $row->slots_delta ) ? json_decode( $row->slots_delta, true ) : null,
                'created_at'  => $row->created_at ?? '',
            ];
        }

        return [
            'conversation_id' => $conversation_id,
            'goal'            => $conv->goal,
            'goal_label'      => $conv->goal_label,
            'status'          => $conv->status,
            'turns'           => $turns,
            'total'           => $total,
            'page'            => $page,
            'per_page'        => $per_page,
            'total_pages'     => (int) ceil( $total / $per_page ),
        ];
    }

    /* ================================================================
     * Stats — aggregate counts per status
     * ================================================================ */

    /**
     * @param int    $user_id
     * @param string $channel
     * @return array  e.g. { ACTIVE: 3, COMPLETED: 12, CANCELLED: 1 }
     */
    public function get_status_counts( $user_id, $channel = 'adminchat' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_intent_conversations';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM {$table}
             WHERE user_id = %d AND channel = %s AND goal != ''
             GROUP BY status",
            $user_id,
            $channel
        ) );

        $counts = [];
        foreach ( $rows as $row ) {
            $counts[ $row->status ] = (int) $row->cnt;
        }
        return $counts;
    }

    /* ================================================================
     * Formatters
     * ================================================================ */

    private function format_task( $row ) {
        $title = ! empty( $row->goal_label ) ? $row->goal_label : ( $row->goal ?? '' );
        return [
            'id'              => $row->conversation_id ?? '',
            'session_id'      => $row->session_id ?? '',
            'goal'            => $row->goal ?? '',
            'title'           => $title,
            'status'          => $row->status ?? 'ACTIVE',
            'turn_count'      => (int) ( $row->turn_count ?? 0 ),
            'project_id'      => $row->project_id ?? '',
            'created_at'      => $row->created_at ?? '',
            'last_activity_at' => $row->last_activity_at ?? $row->created_at ?? '',
        ];
    }

    private function format_task_detail( $conv ) {
        $slots = $conv->slots_json ? json_decode( $conv->slots_json, true ) : [];
        return [
            'id'               => $conv->conversation_id,
            'session_id'       => $conv->session_id ?? '',
            'goal'             => $conv->goal,
            'title'            => ! empty( $conv->goal_label ) ? $conv->goal_label : $conv->goal,
            'status'           => $conv->status,
            'turn_count'       => (int) $conv->turn_count,
            'project_id'       => $conv->project_id ?? '',
            'slots'            => is_array( $slots ) ? $slots : [],
            'rolling_summary'  => $conv->rolling_summary ?? '',
            'created_at'       => $conv->created_at,
            'last_activity_at' => $conv->last_activity_at,
        ];
    }

    private function empty_paged() {
        return [
            'items'       => [],
            'total'       => 0,
            'page'        => 1,
            'per_page'    => 20,
            'total_pages' => 0,
        ];
    }
}
