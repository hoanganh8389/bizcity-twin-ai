<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Output Store — Phase 1.9 Sprint 1 (S1.2 + S1.3)
 *
 * Saves content tool artifacts into `bizcity_webchat_studio_outputs`
 * whenever a content tool execution completes via the unified pipe.
 *
 * Listens to: `bizcity_tool_execution_completed`
 * Only acts when tool_type = 'content' (content_tier >= 1).
 *
 * Works alongside BCN_Studio — does NOT replace it. BCN_Studio handles
 * Studio-tab-initiated generation; Output_Store handles chat/pipeline-initiated
 * artifacts that should appear in the Studio tab with caller metadata.
 *
 * @package BizCity\TwinAI\Tools
 * @since   2.5.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Output_Store {

    /** Tool types treated as content artifacts. */
    const CONTENT_TOOL_TYPES = [
        'content', 'mindmap', 'slide', 'report', 'flashcard',
        'quiz', 'data_table', 'landing', 'summary', 'blog',
    ];

    /** Caller values mapped from BizCity_Tool_Run's caller field. */
    const CALLER_MAP = [
        'intent'   => 'intent',
        'pipeline' => 'pipeline',
        'studio'   => 'studio',
        'schedule' => 'schedule',
    ];

    /* ─────────────────────────────────────────────────────────────────
     * Bootstrapper — call once from plugins_loaded or init.
     * ───────────────────────────────────────────────────────────────── */

    public static function init(): void {
        add_action( 'bizcity_tool_execution_completed', [ self::class, 'on_execution_completed' ], 10, 1 );
    }

    /* ─────────────────────────────────────────────────────────────────
     * Event handler
     * ───────────────────────────────────────────────────────────────── */

    /**
     * Called when `do_action('bizcity_tool_execution_completed', $event)` fires.
     *
     * @param array $event {
     *   tool_id, success, verified, data, message, skill, caller,
     *   session_id, user_id, channel, duration_ms, invoke_id, resource_bundle
     * }
     */
    public static function on_execution_completed( array $event ): void {
        // Only save successful, verified content tool executions.
        if ( empty( $event['success'] ) || empty( $event['verified'] ) ) {
            return;
        }

        $tool_id = $event['tool_id'] ?? '';
        if ( empty( $tool_id ) ) {
            return;
        }

        // Check if BCN schema is available.
        if ( ! class_exists( 'BCN_Schema_Extend' ) ) {
            return;
        }

        // Detect tool_type from registry (falls back to 'content').
        $tool_type = self::resolve_tool_type( $tool_id );

        // Only store content-tier artifacts.
        if ( ! in_array( $tool_type, self::CONTENT_TOOL_TYPES, true ) ) {
            return;
        }

        // Build and persist the artifact.
        self::save_artifact( $event, $tool_type );
    }

    /* ─────────────────────────────────────────────────────────────────
     * Core save
     * ───────────────────────────────────────────────────────────────── */

    /**
     * Persist an artifact into `bizcity_webchat_studio_outputs`.
     *
     * @param  array  $event      Execution event payload.
     * @param  string $tool_type  Resolved tool type string.
     * @return int|false          Inserted row ID or false on failure.
     */
    public static function save_artifact( array $event, string $tool_type = 'content' ) {
        global $wpdb;

        $table = BCN_Schema_Extend::table_studio_outputs();

        $data_payload = $event['data'] ?? [];
        if ( ! is_array( $data_payload ) ) {
            $data_payload = [];
        }

        // --- Caller ---
        $raw_caller = $event['caller'] ?? 'intent';
        $caller     = self::CALLER_MAP[ $raw_caller ] ?? 'intent';

        // --- Content extraction ---
        $content        = $data_payload['content'] ?? $data_payload['output'] ?? ( $event['message'] ?? '' );
        $content_format = self::detect_format( $content );

        // --- Title ---
        $title = $data_payload['title'] ?? $data_payload['heading'] ?? '';
        if ( empty( $title ) ) {
            $title = self::extract_title_from_content( $content );
        }
        $title = wp_strip_all_tags( $title );
        $title = mb_substr( $title, 0, 255 );

        // --- Resource bundle counts ---
        $bundle      = $event['resource_bundle'] ?? [];
        $note_count  = (int) ( $bundle['notes']['count'] ?? 0 );
        $src_count   = (int) ( $bundle['sources']['count'] ?? 0 );
        $token_count = (int) ( $bundle['total_tokens'] ?? 0 );

        // --- Project ID from session ---
        $session_id = $event['session_id'] ?? '';
        $project_id = self::resolve_project_id( $session_id );

        // --- User ---
        $user_id = (int) ( $event['user_id'] ?? get_current_user_id() );

        // --- task_id (invoke_id as surrogate task_id) ---
        $task_id = ! empty( $event['task_id'] ) ? (int) $event['task_id'] : null;

        $row = [
            'user_id'        => $user_id,
            'caller'         => $caller,
            'tool_id'        => sanitize_key( $event['tool_id'] ?? '' ),
            'task_id'        => $task_id,
            'invoke_id'      => sanitize_text_field( $event['invoke_id'] ?? '' ),
            'project_id'     => sanitize_text_field( $project_id ),
            'tool_type'      => sanitize_key( $tool_type ),
            'title'          => $title,
            'content'        => $content,
            'content_format' => $content_format,
            'source_count'   => $src_count,
            'note_count'     => $note_count,
            'token_count'    => $token_count,
            'input_snapshot' => wp_json_encode( [
                'event_tool_id'  => $event['tool_id'] ?? '',
                'invoke_id'      => $event['invoke_id'] ?? '',
                'resource_bundle'=> $bundle,
            ], JSON_UNESCAPED_UNICODE ),
            'status'         => 'ready',
            'created_at'     => current_time( 'mysql' ),
        ];

        $inserted = $wpdb->insert( $table, $row );

        if ( $inserted ) {
            $output_id = $wpdb->insert_id;

            /**
             * Fires after a content artifact is saved by Output Store.
             *
             * @param int   $output_id  Inserted row ID.
             * @param array $event      Original execution event.
             * @param array $row        Data row that was inserted.
             */
            do_action( 'bizcity_output_store_saved', $output_id, $event, $row );

            return $output_id;
        }

        error_log( '[BizCity_Output_Store] Insert failed: ' . $wpdb->last_error );
        return false;
    }

    /* ─────────────────────────────────────────────────────────────────
     * Update distribution result
     * ───────────────────────────────────────────────────────────────── */

    /**
     * Update external_url / external_post_id after a successful distribution.
     *
     * @param int    $output_id       Studio output ID.
     * @param string $external_url    Published URL (Facebook post, WP permalink…).
     * @param int    $external_post_id WP post ID, 0 if not applicable.
     * @return bool
     */
    public static function update_distribution_result(
        int $output_id,
        string $external_url,
        int $external_post_id = 0
    ): bool {
        global $wpdb;
        $table = BCN_Schema_Extend::table_studio_outputs();

        $data = [ 'updated_at' => current_time( 'mysql' ) ];
        if ( $external_url ) {
            $data['external_url'] = esc_url_raw( $external_url );
        }
        if ( $external_post_id ) {
            $data['external_post_id'] = $external_post_id;
        }

        return (bool) $wpdb->update( $table, $data, [ 'id' => $output_id ] );
    }

    /* ─────────────────────────────────────────────────────────────────
     * Helpers
     * ───────────────────────────────────────────────────────────────── */

    /**
     * Resolve tool_type from Intent Tools registry.
     */
    private static function resolve_tool_type( string $tool_id ): string {
        if ( class_exists( 'BizCity_Intent_Tools' ) ) {
            $schema = BizCity_Intent_Tools::instance()->get( $tool_id );
            if ( ! empty( $schema['tool_type'] ) ) {
                return $schema['tool_type'];
            }
        }
        // Heuristic fallbacks.
        $map = [
            'mindmap'  => 'mindmap',
            'slide'    => 'slide',
            'report'   => 'report',
            'flashcard'=> 'flashcard',
            'quiz'     => 'quiz',
            'landing'  => 'landing',
        ];
        foreach ( $map as $keyword => $type ) {
            if ( strpos( $tool_id, $keyword ) !== false ) {
                return $type;
            }
        }
        return 'content';
    }

    /**
     * Resolve project_id from session metadata.
     */
    private static function resolve_project_id( string $session_id ): string {
        if ( empty( $session_id ) ) return '';

        global $wpdb;
        $sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$sessions_table}'" ) ) return '';

        $project_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT project_id FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
            $session_id
        ) );

        return $project_id ?: '';
    }

    /**
     * Detect content format from the content string.
     */
    private static function detect_format( string $content ): string {
        $trimmed = ltrim( $content );
        if ( strpos( $trimmed, '{' ) === 0 || strpos( $trimmed, '[' ) === 0 ) {
            return 'json';
        }
        return 'markdown';
    }

    /**
     * Extract a short title from the first heading or first sentence.
     */
    private static function extract_title_from_content( string $content ): string {
        // Markdown heading
        if ( preg_match( '/^#+\s+(.+)/m', $content, $m ) ) {
            return trim( $m[1] );
        }
        // First non-empty line
        $lines = array_filter( explode( "\n", $content ) );
        $first = reset( $lines );
        if ( $first ) {
            return mb_substr( trim( $first ), 0, 100 );
        }
        return 'Studio Output';
    }

    /* ─────────────────────────────────────────────────────────────────
     * S4.5 — Auto-cleanup: remove old unpinned outputs (24h default).
     * ───────────────────────────────────────────────────────────────── */

    /**
     * Delete studio outputs older than $hours that are not pinned and have no external_url.
     *
     * @param int $hours Age threshold in hours (default 24).
     * @return int Number of rows deleted.
     */
    public static function cleanup_old_outputs( $hours = 24 ) {
        if ( ! class_exists( 'BCN_Schema_Extend' ) ) {
            return 0;
        }

        global $wpdb;
        $table    = BCN_Schema_Extend::table_studio_outputs();
        $cutoff   = gmdate( 'Y-m-d H:i:s', time() - ( $hours * 3600 ) );

        // Only delete outputs that:
        // 1. Are older than cutoff
        // 2. Have no external_url (not distributed)
        // 3. Have status != 'pinned'
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s AND (external_url IS NULL OR external_url = '') AND status != 'pinned' LIMIT 500",
            $cutoff
        ) );

        return $deleted !== false ? $deleted : 0;
    }

    /**
     * Register WP-Cron event for auto-cleanup.
     * Call once during plugin init.
     */
    public static function schedule_cleanup() {
        if ( ! wp_next_scheduled( 'bizcity_output_store_cleanup' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'bizcity_output_store_cleanup' );
        }
        add_action( 'bizcity_output_store_cleanup', array( self::class, 'cleanup_old_outputs' ) );
    }
}
