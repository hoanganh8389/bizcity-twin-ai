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

    // Schema version — must match `current_version` in core.bizcity-llm.json changelog.
    const DB_VER = 2;

    /**
     * Service buckets used for per-service usage breakdown (R-1API Phase B, 2026-06-02).
     * Used by UsageWorkspace tabs and `get_stats_by_service()`.
     */
    const SERVICES = [ 'llm', 'embedding', 'search', 'video', 'image', 'astro', 'market', 'tools' ];

    /**
     * Create / migrate the table if needed (called on plugin boot).
     */
    public static function maybe_install(): void {
        $installed = (int) get_site_option( 'bizcity_llm_usage_db_ver', 0 );
        global $wpdb;
        $table  = self::table();
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" );
        if ( $installed >= self::DB_VER && $exists ) {
            return;
        }
        self::install();
        self::migrate( $installed );
        update_site_option( 'bizcity_llm_usage_db_ver', self::DB_VER );
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
            service     VARCHAR(20)     NOT NULL DEFAULT 'llm' COMMENT 'llm|embedding|search|video|image|astro|market|tools',
            mode        VARCHAR(20)     NOT NULL DEFAULT 'gateway',
            purpose     VARCHAR(50)     NOT NULL DEFAULT 'chat',
            endpoint    VARCHAR(20)     NOT NULL DEFAULT 'chat' COMMENT 'chat|stream|embeddings|search|video|image|astro|tool',
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
            KEY idx_user (user_id),
            KEY idx_service_created (service, created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Idempotent migration runner — promotes existing tables to current schema.
     */
    private static function migrate( int $from ): void {
        global $wpdb;
        $table = self::table();

        // v1 → v2 (2026-06-02): add `service` column + index
        if ( $from < 2 ) {
            $col = $wpdb->get_var( "SHOW COLUMNS FROM `{$table}` LIKE 'service'" );
            if ( ! $col ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `service` VARCHAR(20) NOT NULL DEFAULT 'llm' AFTER `created_at`" );
            }
            $idx = $wpdb->get_var( "SHOW INDEX FROM `{$table}` WHERE Key_name='idx_service_created'" );
            if ( ! $idx ) {
                $wpdb->query( "ALTER TABLE `{$table}` ADD KEY `idx_service_created` (`service`, `created_at`)" );
            }
        }
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

        // R-1API Phase B (2026-06-02): infer `service` from explicit param,
        // else from endpoint, else default 'llm'. Caller wrappers (Search/Video/
        // Astro/Image clients) SHOULD pass `service` explicitly.
        $service = sanitize_text_field( $data['service'] ?? '' );
        if ( $service === '' ) {
            $endpoint = sanitize_text_field( $data['endpoint'] ?? 'chat' );
            $service = self::infer_service( $endpoint );
        }
        if ( ! in_array( $service, self::SERVICES, true ) ) {
            $service = 'llm';
        }

        $wpdb->insert( self::table(), [
            'blog_id'           => get_current_blog_id(),
            'user_id'           => get_current_user_id(),
            'service'           => $service,
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
        ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ] );
    }

    /**
     * Heuristic mapping `endpoint` → `service` bucket. Best-effort only;
     * callers should pass `service` explicitly.
     */
    private static function infer_service( string $endpoint ): string {
        $endpoint = strtolower( $endpoint );
        if ( $endpoint === 'embeddings' || $endpoint === 'embedding' ) return 'embedding';
        if ( $endpoint === 'search' )                                   return 'search';
        if ( $endpoint === 'video' )                                    return 'video';
        if ( $endpoint === 'image' || $endpoint === 'image_generation' )return 'image';
        if ( $endpoint === 'astro' || $endpoint === 'astrology' )       return 'astro';
        if ( $endpoint === 'market' || $endpoint === 'marketplace' )    return 'market';
        if ( $endpoint === 'tool'   || $endpoint === 'tools' )          return 'tools';
        return 'llm';
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
     * Get aggregated stats grouped by `service` bucket (R-1API Phase B).
     *
     * Returns map keyed by service name with same shape as get_stats().
     * Always returns all canonical services (zero-padded for missing).
     *
     * @param string $period '1h'|'24h'|'7d'|'30d'|'all'
     * @return array<string, array> { service => { total_calls, success_count, error_count, total_tokens, avg_latency_ms } }
     */
    public static function get_stats_by_service( string $period = '24h' ): array {
        global $wpdb;
        $table = self::table();

        $where = '';
        switch ( $period ) {
            case '1h':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"; break;
            case '24h': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; break;
            case '7d':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; break;
            case '30d': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; break;
        }

        $rows = $wpdb->get_results(
            "SELECT service,
                    COUNT(*)                              AS total_calls,
                    SUM(success)                          AS success_count,
                    SUM(CASE WHEN success=0 THEN 1 ELSE 0 END) AS error_count,
                    SUM(tokens_prompt + tokens_completion) AS total_tokens,
                    AVG(latency_ms)                       AS avg_latency_ms
             FROM `{$table}` {$where}
             GROUP BY service",
            ARRAY_A
        ) ?: [];

        $by_service = [];
        foreach ( self::SERVICES as $svc ) {
            $by_service[ $svc ] = [
                'service'        => $svc,
                'total_calls'    => 0,
                'success_count'  => 0,
                'error_count'    => 0,
                'total_tokens'   => 0,
                'avg_latency_ms' => 0,
            ];
        }
        foreach ( $rows as $r ) {
            $svc = $r['service'] ?: 'llm';
            if ( ! isset( $by_service[ $svc ] ) ) {
                $by_service[ $svc ] = [ 'service' => $svc ];
            }
            $by_service[ $svc ]['total_calls']    = (int) $r['total_calls'];
            $by_service[ $svc ]['success_count']  = (int) $r['success_count'];
            $by_service[ $svc ]['error_count']    = (int) $r['error_count'];
            $by_service[ $svc ]['total_tokens']   = (int) $r['total_tokens'];
            $by_service[ $svc ]['avg_latency_ms'] = (int) round( (float) $r['avg_latency_ms'] );
        }
        return $by_service;
    }

    /**
     * Top models within a specific service bucket (e.g. top LLM models, top image models).
     */
    public static function get_top_models_for_service( string $service, int $limit = 10, string $period = '7d' ): array {
        global $wpdb;
        $table = self::table();
        $service = sanitize_text_field( $service );

        $where = "WHERE service = %s";
        $args  = [ $service ];
        if ( $period === '24h' ) { $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; }
        elseif ( $period === '7d' )  { $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; }
        elseif ( $period === '30d' ) { $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; }

        $args[] = $limit;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT model_used,
                    COUNT(*) AS calls,
                    SUM(tokens_prompt + tokens_completion) AS total_tokens,
                    AVG(latency_ms) AS avg_latency
             FROM `{$table}` {$where}
             GROUP BY model_used
             ORDER BY calls DESC
             LIMIT %d",
            $args
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
