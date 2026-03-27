<?php
/**
 * BizCity LLM — Usage Logging
 *
 * Tracks every LLM API call with model, tokens, latency, success/failure.
 * Stores in a custom DB table for efficient querying.
 *
 * @package BizCity_LLM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_LLM_Usage_Log {

    const TABLE_SUFFIX = 'bizcity_llm_usage';

    /**
     * Get the full table name for the current site/network.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->base_prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create the table if it doesn't exist (called on plugin boot).
     */
    public static function maybe_install(): void {
        $installed = get_site_option( 'bizcity_llm_usage_db_ver', 0 );
        if ( (int) $installed >= 1 ) {
            // Double-check table actually exists (may differ across DB shards)
            global $wpdb;
            $table = self::table();
            $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
            if ( $exists ) {
                return;
            }
        }
        self::install();
        update_site_option( 'bizcity_llm_usage_db_ver', 1 );
    }

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blog_id     BIGINT UNSIGNED NOT NULL DEFAULT 1,
            user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            mode        VARCHAR(20)     NOT NULL DEFAULT 'gateway',
            purpose     VARCHAR(50)     NOT NULL DEFAULT 'chat',
            endpoint    VARCHAR(20)     NOT NULL DEFAULT 'chat' COMMENT 'chat|stream|embeddings',
            model_requested VARCHAR(200) NOT NULL DEFAULT '',
            model_used  VARCHAR(200)    NOT NULL DEFAULT '',
            fallback_used TINYINT(1)    NOT NULL DEFAULT 0,
            success     TINYINT(1)      NOT NULL DEFAULT 0,
            tokens_prompt    INT        NOT NULL DEFAULT 0,
            tokens_completion INT       NOT NULL DEFAULT 0,
            latency_ms  INT             NOT NULL DEFAULT 0,
            error       VARCHAR(500)    NOT NULL DEFAULT '',
            KEY idx_created (created_at),
            KEY idx_blog (blog_id, created_at),
            KEY idx_user (user_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Log an API call.
     *
     * @param array $data {
     *   @type string $mode           'gateway'|'direct'
     *   @type string $purpose        'chat'|'vision'|'code'|…
     *   @type string $endpoint       'chat'|'stream'|'embeddings'
     *   @type string $model_requested
     *   @type string $model_used
     *   @type bool   $fallback_used
     *   @type bool   $success
     *   @type array  $usage          { prompt_tokens, completion_tokens }
     *   @type int    $latency_ms
     *   @type string $error
     * }
     */
    public static function log( array $data ): void {
        global $wpdb;

        $usage = $data['usage'] ?? [];

        $wpdb->insert( self::table(), [
            'blog_id'           => get_current_blog_id(),
            'user_id'           => get_current_user_id(),
            'mode'              => sanitize_text_field( $data['mode'] ?? 'gateway' ),
            'purpose'           => sanitize_text_field( $data['purpose'] ?? 'chat' ),
            'endpoint'          => sanitize_text_field( $data['endpoint'] ?? 'chat' ),
            'model_requested'   => sanitize_text_field( $data['model_requested'] ?? '' ),
            'model_used'        => sanitize_text_field( $data['model_used'] ?? '' ),
            'fallback_used'     => ! empty( $data['fallback_used'] ) ? 1 : 0,
            'success'           => ! empty( $data['success'] ) ? 1 : 0,
            'tokens_prompt'     => intval( $usage['prompt_tokens'] ?? 0 ),
            'tokens_completion' => intval( $usage['completion_tokens'] ?? 0 ),
            'latency_ms'        => intval( $data['latency_ms'] ?? 0 ),
            'error'             => mb_substr( sanitize_text_field( $data['error'] ?? '' ), 0, 500 ),
        ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ] );
    }

    /**
     * Get recent log entries.
     */
    public static function get_recent( int $limit = 50, int $offset = 0 ): array {
        global $wpdb;
        $table = self::table();
        $limit  = min( $limit, 200 );
        $offset = max( $offset, 0 );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A ) ?: [];
    }

    /**
     * Get aggregated stats for a period.
     *
     * @param string $period '1h'|'24h'|'7d'|'30d'|'all'
     */
    public static function get_stats( string $period = '24h' ): array {
        global $wpdb;
        $table = self::table();

        $where = '';
        switch ( $period ) {
            case '1h':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"; break;
            case '24h': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; break;
            case '7d':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
            case '30d': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; break;
        }

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*)                       AS total_calls,
                SUM(success)                   AS success_count,
                SUM(CASE WHEN success=0 THEN 1 ELSE 0 END) AS error_count,
                SUM(fallback_used)             AS fallback_count,
                SUM(tokens_prompt)             AS total_prompt_tokens,
                SUM(tokens_completion)         AS total_completion_tokens,
                AVG(latency_ms)                AS avg_latency_ms,
                MAX(latency_ms)                AS max_latency_ms
            FROM `{$table}` {$where}",
            ARRAY_A
        );

        return $row ?: [
            'total_calls' => 0, 'success_count' => 0, 'error_count' => 0,
            'fallback_count' => 0, 'total_prompt_tokens' => 0,
            'total_completion_tokens' => 0, 'avg_latency_ms' => 0, 'max_latency_ms' => 0,
        ];
    }

    /**
     * Get top models by usage.
     */
    public static function get_top_models( int $limit = 10, string $period = '7d' ): array {
        global $wpdb;
        $table = self::table();

        $where = '';
        if ( $period === '24h' ) $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        elseif ( $period === '7d' ) $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT model_used,
                    COUNT(*) AS calls,
                    SUM(tokens_prompt + tokens_completion) AS total_tokens,
                    AVG(latency_ms) AS avg_latency
            FROM `{$table}` {$where}
            GROUP BY model_used
            ORDER BY calls DESC
            LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * Purge old entries.
     */
    public static function purge( int $days = 90 ): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ) );
    }
}
