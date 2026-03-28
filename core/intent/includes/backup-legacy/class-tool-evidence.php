<?php
/**
 * BizCity Tool Evidence — CPT 'bizcity-auto-tool'
 *
 * Mỗi tool chạy xong → tạo 1 WordPress CPT post làm evidence.
 * Hệ thống tự động save evidence sau execute() — tool callback KHÔNG cần tự save.
 *
 * Phase 1 — Nguyên tắc 2: NHẤT QUÁN (Coherence)
 *
 * @package BizCity_Intent
 * @since   4.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Evidence {

    const POST_TYPE = 'bizcity-auto-tool';

    /**
     * Initialize CPT registration.
     */
    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_post_type' ] );
    }

    /**
     * Register the CPT.
     */
    public static function register_post_type() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'          => 'Tool Evidence',
                'singular_name' => 'Tool Evidence Entry',
            ],
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => false, // Accessible via custom admin page
            'show_in_rest'    => false,
            'supports'        => [ 'title', 'editor', 'author', 'custom-fields' ],
            'capability_type' => 'post',
        ] );
    }

    /**
     * Save tool execution evidence as a CPT post.
     *
     * Called automatically by BizCity_Intent_Tools::execute() after successful callback.
     *
     * @param string $tool_name    Tool that was executed.
     * @param array  $result       Tool callback result (success, message, data).
     * @param array  $context      Pipeline context {pipeline_id, step_index, session_id, user_id}.
     * @return int|false           Evidence post_id or false on failure.
     */
    public static function save( $tool_name, array $result, array $context = [] ) {
        if ( empty( $result['success'] ) ) {
            return false;
        }

        $data       = $result['data'] ?? [];
        $user_id    = $context['user_id'] ?? get_current_user_id();
        $title_text = ( $data['title'] ?? $result['message'] ?? $tool_name );

        $post_id = wp_insert_post( [
            'post_type'    => self::POST_TYPE,
            'post_title'   => sanitize_text_field( mb_substr( $title_text . ' — ' . $tool_name, 0, 200 ) ),
            'post_content' => wp_kses_post( $result['message'] ?? '' ),
            'post_status'  => 'publish',
            'post_author'  => (int) $user_id,
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            error_log( "[BizCity_Tool_Evidence] Failed to create evidence for tool '{$tool_name}'" );
            return false;
        }

        // Core meta
        update_post_meta( $post_id, '_tool_name',    sanitize_text_field( $tool_name ) );
        update_post_meta( $post_id, '_tool_status',   'completed' );
        update_post_meta( $post_id, '_tool_result',   wp_json_encode( $result, JSON_UNESCAPED_UNICODE ) );

        // Resource references
        if ( ! empty( $data['id'] ) ) {
            update_post_meta( $post_id, '_resource_id', (int) $data['id'] );
        }
        if ( ! empty( $data['url'] ) ) {
            update_post_meta( $post_id, '_resource_url', esc_url_raw( $data['url'] ) );
        }
        if ( ! empty( $data['image_url'] ) ) {
            update_post_meta( $post_id, '_image_url', esc_url_raw( $data['image_url'] ) );
        }
        if ( ! empty( $data['type'] ) ) {
            update_post_meta( $post_id, '_resource_type', sanitize_text_field( $data['type'] ) );
        }

        // Pipeline context
        if ( ! empty( $context['pipeline_id'] ) ) {
            update_post_meta( $post_id, '_pipeline_id', sanitize_text_field( $context['pipeline_id'] ) );
        }
        if ( isset( $context['step_index'] ) ) {
            update_post_meta( $post_id, '_step_index', (int) $context['step_index'] );
        }
        if ( ! empty( $context['session_id'] ) ) {
            update_post_meta( $post_id, '_session_id', sanitize_text_field( $context['session_id'] ) );
        }

        return $post_id;
    }

    /**
     * Mark an evidence record as failed (e.g., post-verification found resource deleted).
     *
     * @param int    $evidence_id
     * @param string $reason
     */
    public static function mark_failed( $evidence_id, $reason = '' ) {
        update_post_meta( $evidence_id, '_tool_status', 'failed' );
        if ( $reason ) {
            update_post_meta( $evidence_id, '_fail_reason', sanitize_text_field( $reason ) );
        }
    }

    /**
     * Verify that the resource referenced by an evidence record still exists.
     *
     * @param int $evidence_id
     * @return bool
     */
    public static function verify( $evidence_id ) {
        $resource_id = get_post_meta( $evidence_id, '_resource_id', true );
        if ( ! $resource_id ) {
            return true; // No resource to verify (analytics/utility tools)
        }

        $post = get_post( (int) $resource_id );
        if ( ! $post || $post->post_status === 'trash' ) {
            self::mark_failed( $evidence_id, 'Resource not found or trashed' );
            return false;
        }

        return true;
    }

    /**
     * Query evidence records for a pipeline.
     *
     * @param string $pipeline_id
     * @return array Evidence posts.
     */
    public static function get_by_pipeline( $pipeline_id ) {
        return self::query( [ '_pipeline_id' => $pipeline_id ], 50, 'ASC' );
    }

    /**
     * Query evidence records for a session.
     *
     * @param string $session_id
     * @param int    $limit
     * @return array
     */
    public static function get_by_session( $session_id, $limit = 50 ) {
        return self::query( [ '_session_id' => $session_id ], $limit );
    }

    /**
     * Query evidence records for a specific tool.
     *
     * @param string $tool_name
     * @param int    $user_id
     * @param int    $limit
     * @return array
     */
    public static function get_by_tool( $tool_name, $user_id = 0, $limit = 20 ) {
        $meta = [ '_tool_name' => $tool_name ];
        return self::query( $meta, $limit, 'DESC', $user_id );
    }

    /**
     * Generic meta query for evidence records.
     *
     * @param array  $meta_filters  [ meta_key => meta_value ]
     * @param int    $limit
     * @param string $order
     * @param int    $author        Filter by author (0 = all).
     * @return array
     */
    private static function query( array $meta_filters, $limit = 50, $order = 'DESC', $author = 0 ) {
        $meta_query = [];
        foreach ( $meta_filters as $key => $value ) {
            $meta_query[] = [
                'key'   => $key,
                'value' => $value,
            ];
        }

        $args = [
            'post_type'      => self::POST_TYPE,
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => $order,
            'meta_query'     => $meta_query,
        ];

        if ( $author > 0 ) {
            $args['author'] = $author;
        }

        $query = new WP_Query( $args );
        $items = [];

        foreach ( $query->posts as $p ) {
            $items[] = [
                'evidence_id'   => $p->ID,
                'tool_name'     => get_post_meta( $p->ID, '_tool_name', true ),
                'tool_status'   => get_post_meta( $p->ID, '_tool_status', true ),
                'resource_id'   => get_post_meta( $p->ID, '_resource_id', true ),
                'resource_url'  => get_post_meta( $p->ID, '_resource_url', true ),
                'resource_type' => get_post_meta( $p->ID, '_resource_type', true ),
                'image_url'     => get_post_meta( $p->ID, '_image_url', true ),
                'pipeline_id'   => get_post_meta( $p->ID, '_pipeline_id', true ),
                'step_index'    => get_post_meta( $p->ID, '_step_index', true ),
                'session_id'    => get_post_meta( $p->ID, '_session_id', true ),
                'message'       => $p->post_content,
                'title'         => $p->post_title,
                'created_at'    => $p->post_date,
                'user_id'       => (int) $p->post_author,
            ];
        }

        return $items;
    }
}
