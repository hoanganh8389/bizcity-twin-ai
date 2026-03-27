<?php
/**
 * Session List Service — Paginated queries for webchat sessions
 *
 * Provides reusable business logic for listing and inspecting
 * webchat sessions and their messages. Used by REST API and future React app.
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Session_List_Service {

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

    /** @return BizCity_WebChat_Database|null */
    private function get_wc_db() {
        return class_exists( 'BizCity_WebChat_Database' )
            ? BizCity_WebChat_Database::instance()
            : null;
    }

    /* ================================================================
     * List sessions — paginated
     * ================================================================ */

    /**
     * @param int   $user_id
     * @param array $args {
     *   @type string $platform_type  ADMINCHAT | WEBCHAT
     *   @type string $status         active | closed | archived | all
     *   @type string $project_id     Filter by project UUID
     *   @type string $search         Free-text search in title
     *   @type int    $page           1-based page
     *   @type int    $per_page       Items per page (max 100)
     *   @type string $order          ASC | DESC
     * }
     * @return array { items, total, page, per_page, total_pages }
     */
    public function list_sessions( $user_id, array $args = [] ) {
        global $wpdb;

        $table = $wpdb->prefix . 'bizcity_webchat_sessions';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return $this->empty_paged();
        }

        $platform = sanitize_text_field( $args['platform_type'] ?? 'ADMINCHAT' );
        $status   = sanitize_text_field( $args['status']        ?? 'all' );
        $project  = isset( $args['project_id'] ) ? sanitize_text_field( $args['project_id'] ) : null;
        $search   = sanitize_text_field( $args['search']        ?? '' );
        $page     = max( 1, intval( $args['page']               ?? 1 ) );
        $per_page = min( 100, max( 1, intval( $args['per_page'] ?? 20 ) ) );
        $order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $wheres = [ 'user_id = %d' ];
        $params = [ $user_id ];

        if ( $platform ) {
            $wheres[] = 'platform_type = %s';
            $params[] = $platform;
        }
        if ( $status && $status !== 'all' ) {
            $wheres[] = 'status = %s';
            $params[] = $status;
        }
        if ( $project !== null ) {
            $wheres[] = 'project_id = %s';
            $params[] = $project;
        }
        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $wheres[] = '(title LIKE %s OR last_message_preview LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = 'WHERE ' . implode( ' AND ', $wheres );

        // Count
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$params
        ) );

        // Fetch
        $offset     = ( $page - 1 ) * $per_page;
        $all_params = array_merge( $params, [ $per_page, $offset ] );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where_sql}
             ORDER BY COALESCE(last_message_at, started_at) {$order}
             LIMIT %d OFFSET %d",
            ...$all_params
        ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $items = [];
        foreach ( $rows as $row ) {
            $items[] = $this->format_session( $row );
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
     * Single session detail
     * ================================================================ */

    /**
     * @param int $session_pk  Primary key (id column)
     * @param int $user_id     For ownership check
     * @return array|WP_Error
     */
    public function get_session( $session_pk, $user_id = 0 ) {
        $wc_db = $this->get_wc_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'WebChat database not available', [ 'status' => 500 ] );
        }

        $row = $wc_db->get_session( $session_pk );
        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        if ( $user_id > 0 && (int) $row->user_id !== $user_id ) {
            return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
        }

        return $this->format_session_detail( $row );
    }

    /**
     * Get session by session_id string (e.g. wcs_xxx).
     *
     * @param string $session_id
     * @param int    $user_id
     * @return array|WP_Error
     */
    public function get_session_by_sid( $session_id, $user_id = 0 ) {
        $wc_db = $this->get_wc_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'WebChat database not available', [ 'status' => 500 ] );
        }

        $row = method_exists( $wc_db, 'get_session_by_session_id' )
            ? $wc_db->get_session_by_session_id( $session_id )
            : null;

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        if ( $user_id > 0 && (int) $row->user_id !== $user_id ) {
            return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
        }

        return $this->format_session_detail( $row );
    }

    /* ================================================================
     * Session messages — paginated
     * ================================================================ */

    /**
     * @param string $session_id  The session_id string
     * @param int    $user_id     For ownership check
     * @param int    $page
     * @param int    $per_page
     * @return array|WP_Error { messages, total, page, per_page, total_pages, session }
     */
    public function get_session_messages( $session_id, $user_id = 0, $page = 1, $per_page = 50 ) {
        global $wpdb;

        // Verify ownership via sessions table
        $sess_table = $wpdb->prefix . 'bizcity_webchat_sessions';
        $session    = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$sess_table} WHERE session_id = %s LIMIT 1",
            $session_id
        ) );

        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }
        if ( $user_id > 0 && (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
        }

        $msg_table = $wpdb->prefix . 'bizcity_webchat_messages';
        $per_page  = min( 100, max( 1, $per_page ) );
        $page      = max( 1, $page );
        $offset    = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$msg_table} WHERE session_id = %s",
            $session_id
        ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$msg_table}
             WHERE session_id = %s
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            $session_id,
            $per_page,
            $offset
        ) );

        $messages = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $messages[] = [
                    'id'                     => (int) $row->id,
                    'message_id'             => $row->message_id ?? '',
                    'text'                   => $row->message_text ?? '',
                    'message_text'           => $row->message_text ?? '',
                    'from'                   => $row->message_from ?? 'bot',
                    'message_from'           => $row->message_from ?? 'bot',
                    'type'                   => $row->message_type ?? 'text',
                    'plugin_slug'            => $row->plugin_slug ?? '',
                    'intent_conversation_id' => $row->intent_conversation_id ?? '',
                    'tool_name'              => $row->tool_name ?? '',
                    'tool_calls'             => ! empty( $row->tool_calls ) ? json_decode( $row->tool_calls, true ) : null,
                    'input_tokens'           => (int) ( $row->input_tokens ?? 0 ),
                    'output_tokens'          => (int) ( $row->output_tokens ?? 0 ),
                    'attachments'            => ! empty( $row->attachments ) ? json_decode( $row->attachments, true ) : [],
                    'meta'                   => ! empty( $row->meta ) ? json_decode( $row->meta, true ) : [],
                    'created_at'             => $row->created_at ?? '',
                ];
            }
        }

        return [
            'session' => $this->format_session( $session ),
            'messages'    => $messages,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil( $total / $per_page ),
        ];
    }

    /* ================================================================
     * Stats
     * ================================================================ */

    /**
     * @param int    $user_id
     * @param string $platform_type
     * @return array  e.g. { active: 5, closed: 20, archived: 3 }
     */
    public function get_status_counts( $user_id, $platform_type = 'ADMINCHAT' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM {$table}
             WHERE user_id = %d AND platform_type = %s
             GROUP BY status",
            $user_id,
            $platform_type
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

    private function format_session( $row ) {
        $title = $row->title ?? '';
        if ( empty( $title ) ) {
            $title = $row->title_generated ?? 'Hội thoại mới';
        }

        return [
            'id'              => (int) $row->id,
            'session_id'      => $row->session_id ?? '',
            'title'           => $title,
            'project_id'      => $row->project_id ?? '',
            'message_count'   => (int) ( $row->message_count ?? 0 ),
            'last_message'    => $row->last_message_preview ?? $row->last_message ?? '',
            'status'          => $row->status ?? 'active',
            'platform_type'   => $row->platform_type ?? '',
            'started_at'      => $row->started_at ?? null,
            'last_activity_at' => $row->last_message_at ?? $row->started_at ?? null,
        ];
    }

    private function format_session_detail( $row ) {
        $base = $this->format_session( $row );
        $base['rolling_summary'] = $row->rolling_summary ?? '';
        $base['character_id']    = (int) ( $row->character_id ?? 0 );
        $base['meta']            = isset( $row->meta ) ? json_decode( $row->meta, true ) : [];
        return $base;
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
