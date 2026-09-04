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
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — list sessions through the canonical encrypted state owner.
        $wc_db = $this->get_wc_db();
        if ( ! $wc_db ) {
            return $this->empty_paged();
        }

        $platform = sanitize_text_field( $args['platform_type'] ?? 'ADMINCHAT' );
        $status   = sanitize_text_field( $args['status']        ?? 'all' );
        $project  = isset( $args['project_id'] ) ? sanitize_text_field( $args['project_id'] ) : null;
        $search   = sanitize_text_field( $args['search']        ?? '' );
        $page     = max( 1, intval( $args['page']               ?? 1 ) );
        $per_page = min( 100, max( 1, intval( $args['per_page'] ?? 20 ) ) );
        $order    = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

        $rows = $wc_db->get_sessions_v3_for_user( $user_id, $platform ?: null, 5000, $project, $status );
        if ( $search ) {
            $rows = array_filter( $rows, function ( $row ) use ( $search ) {
                return false !== stripos( (string) ( $row->title ?? '' ), $search )
                    || false !== stripos( (string) ( $row->last_message_preview ?? '' ), $search )
                    || false !== stripos( (string) ( $row->session_id ?? '' ), $search );
            } );
        }
        usort( $rows, function ( $left, $right ) use ( $order ) {
            $left_date = (string) ( $left->last_message_at ?? $left->started_at ?? '' );
            $right_date = (string) ( $right->last_message_at ?? $right->started_at ?? '' );
            return 'ASC' === $order ? strcmp( $left_date, $right_date ) : strcmp( $right_date, $left_date );
        } );
        $total = count( $rows );
        $offset = ( $page - 1 ) * $per_page;
        $rows = array_slice( $rows, $offset, $per_page );

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

        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — verify ownership through the encrypted session-state owner.
        $wc_db = $this->get_wc_db();
        $session = $wc_db ? $wc_db->get_session_v3_by_session_id( $session_id ) : null;

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
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — aggregate status counts from folded session records.
        $wc_db = $this->get_wc_db();
        $rows = $wc_db ? $wc_db->get_sessions_v3_for_user( $user_id, $platform_type, 5000, null ) : array();
        $counts = [];
        foreach ( $rows as $row ) {
            $status = (string) ( $row->status ?? 'active' );
            $counts[ $status ] = isset( $counts[ $status ] ) ? $counts[ $status ] + 1 : 1;
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
        $base['meta']            = is_array( $row->meta ?? null ) ? $row->meta : ( isset( $row->meta ) ? ( json_decode( $row->meta, true ) ?: [] ) : [] );
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
