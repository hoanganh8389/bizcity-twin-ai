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
        // [2026-06-28 Johnny Chu] R-SHOW-TABLES — information_schema + wp_cache dual cache (no SHOW TABLES)
        $_ck_llm = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
        $_p_llm  = wp_cache_get( $_ck_llm, 'bizcity_tbl' );
        if ( false === $_p_llm ) {
            $_p_llm = (int) (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                $table
            ) );
            wp_cache_set( $_ck_llm, $_p_llm, 'bizcity_tbl', HOUR_IN_SECONDS );
        }
        $exists = (bool) $_p_llm;
        if ( $installed >= self::DB_VER && $exists ) {
            return;
        }
        self::install();
        self::migrate( $installed );
        update_site_option( 'bizcity_llm_usage_db_ver', self::DB_VER );
        wp_cache_delete( $_ck_llm, 'bizcity_tbl' ); // flush after table created/migrated
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

/**
 * BizCity LLM — Per-Blog Client Usage Log
 *
 * Two-table architecture (R-LLM-USAGE, 2026-06-10):
 *
 *   CLIENT SIDE — this class:
 *     Table: {prefix}bizcity_llm_usage_clients  (per-blog, $wpdb->prefix)
 *     Owner: core/bizcity-llm  (bizcity-twin-ai, client plugin)
 *     Purpose: Record every LLM API call initiated from this blog, per user_id.
 *              Enables Twin master to track per-member usage and enforce
 *              membership quotas via core/membership rules.
 *     Flow:
 *       1. Before HTTP call → log_pending() inserts row with status='pending'.
 *       2. After HTTP response → log_done() updates status='done'/'failed' + tokens.
 *
 *   HUB SIDE — BizCity_Router_Usage (DO NOT use on client):
 *     Table: {base_prefix}bizcity_llm_usage_logs  (network-shared, $wpdb->base_prefix)
 *     Owner: bizcity-llm-router  (hub plugin, only on bizcity.vn/bizcity.ai)
 *     Purpose: Aggregate usage across all client sites at the hub level.
 *              Written by hub when it processes the forwarded call.
 *              Has extra columns: api_key_id, site_url, cost_usd, commission_usd,
 *              provider, domain, finish_reason, is_stream, is_error.
 *
 * On multisite (bizcity.vn): both tables co-exist.
 * On standalone client site: only bizcity_llm_usage_clients exists.
 *
 * @package BizCity_LLM
 * @since   2026-06-10 R-LLM-USAGE
 */
// [2026-06-10 Johnny Chu] R-LLM-USAGE — per-blog client usage log class.
class BizCity_LLM_Usage_Clients {

    const TABLE_SUFFIX = 'bizcity_llm_usage_clients';

    /** Schema version — bump when adding columns/indexes. */
    const DB_VER = 1;

    /** Per-blog option key for installed schema version. */
    const DB_VER_OPT = 'bizcity_llm_usage_clients_db_ver';

    /**
     * Per-blog table name (uses $wpdb->prefix, NOT base_prefix).
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * Create the per-blog table if needed. Called on plugin boot.
     */
    public static function maybe_install(): void {
        $installed = (int) get_option( self::DB_VER_OPT, 0 );
        if ( $installed >= self::DB_VER ) {
            return;
        }
        self::install();
        update_option( self::DB_VER_OPT, self::DB_VER, false );
    }

    public static function install(): void {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (
            id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blog_id           BIGINT UNSIGNED NOT NULL DEFAULT 1,
            user_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status            VARCHAR(10)     NOT NULL DEFAULT 'pending' COMMENT 'pending|done|failed',
            service           VARCHAR(20)     NOT NULL DEFAULT 'llm' COMMENT 'llm|embedding|search|video|image|astro|market|tools',
            mode              VARCHAR(20)     NOT NULL DEFAULT 'gateway',
            purpose           VARCHAR(50)     NOT NULL DEFAULT 'chat',
            endpoint          VARCHAR(20)     NOT NULL DEFAULT 'chat' COMMENT 'chat|stream|embeddings|search|video|image|astro|tool',
            model_requested   VARCHAR(200)    NOT NULL DEFAULT '',
            model_used        VARCHAR(200)    NOT NULL DEFAULT '',
            fallback_used     TINYINT(1)      NOT NULL DEFAULT 0,
            success           TINYINT(1)      NOT NULL DEFAULT 0,
            tokens_prompt     INT             NOT NULL DEFAULT 0,
            tokens_completion INT             NOT NULL DEFAULT 0,
            latency_ms        INT             NOT NULL DEFAULT 0,
            error             VARCHAR(500)    NOT NULL DEFAULT '',
            KEY idx_created (created_at),
            KEY idx_user_created (user_id, created_at),
            KEY idx_blog_user (blog_id, user_id),
            KEY idx_status (status),
            KEY idx_service_created (service, created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Insert a 'pending' row BEFORE the HTTP call is sent to the hub.
     * Returns insert_id (0 on failure) — pass to log_done() after response.
     *
     * @param array $data { service, mode, purpose, endpoint, model_requested }
     * @return int
     */
    public static function log_pending( array $data ): int {
        global $wpdb;
        $service = sanitize_text_field( $data['service'] ?? '' );
        if ( $service === '' ) {
            $service = self::infer_service( sanitize_text_field( $data['endpoint'] ?? 'chat' ) );
        }
        $wpdb->insert( self::table(), [
            'blog_id'         => get_current_blog_id(),
            'user_id'         => get_current_user_id(),
            'status'          => 'pending',
            'service'         => $service,
            'mode'            => sanitize_text_field( $data['mode']            ?? 'gateway' ),
            'purpose'         => sanitize_text_field( $data['purpose']         ?? 'chat' ),
            'endpoint'        => sanitize_text_field( $data['endpoint']        ?? 'chat' ),
            'model_requested' => sanitize_text_field( $data['model_requested'] ?? '' ),
        ], [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update a pending row after the API response is received.
     *
     * @param int   $id     Row ID returned by log_pending().
     * @param array $result { success, model_used, fallback_used, usage, latency_ms, error }
     */
    public static function log_done( int $id, array $result ): void {
        if ( $id <= 0 ) {
            return;
        }
        global $wpdb;
        $usage = $result['usage'] ?? [];
        $wpdb->update(
            self::table(),
            [
                'status'            => ! empty( $result['success'] ) ? 'done' : 'failed',
                'model_used'        => sanitize_text_field( $result['model_used'] ?? '' ),
                'fallback_used'     => ! empty( $result['fallback_used'] ) ? 1 : 0,
                'success'           => ! empty( $result['success'] ) ? 1 : 0,
                'tokens_prompt'     => intval( $usage['prompt_tokens'] ?? 0 ),
                'tokens_completion' => intval( $usage['completion_tokens'] ?? 0 ),
                'latency_ms'        => intval( $result['latency_ms'] ?? 0 ),
                'error'             => mb_substr( sanitize_text_field( $result['error'] ?? '' ), 0, 500 ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Single-shot log for callers that don't split pending/done (e.g. search client).
     *
     * @param array $data Same shape as BizCity_LLM_Usage_Log::log().
     */
    public static function log( array $data ): void {
        $id = self::log_pending( $data );
        self::log_done( $id, [
            'model_used'    => $data['model_used']    ?? ( $data['model_requested'] ?? '' ),
            'latency_ms'    => $data['latency_ms']    ?? 0,
            'fallback_used' => $data['fallback_used'] ?? false,
            'success'       => $data['success']       ?? false,
            'usage'         => $data['usage']         ?? [],
            'error'         => $data['error']         ?? '',
        ] );
    }

    /**
     * Heuristic endpoint → service bucket (mirrors BizCity_LLM_Usage_Log).
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
     * Get recent log entries for this blog.
     *
     * @param int $limit   Max rows (cap 200).
     * @param int $offset  Pagination offset.
     * @param int $user_id Filter by user (0 = all).
     * @return array
     */
    public static function get_recent( int $limit = 50, int $offset = 0, int $user_id = 0 ): array {
        global $wpdb;
        $table  = self::table();
        $limit  = min( $limit, 200 );
        $offset = max( $offset, 0 );
        if ( $user_id > 0 ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
                $user_id, $limit, $offset
            ), ARRAY_A ) ?: [];
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ), ARRAY_A ) ?: [];
    }

    /**
     * Aggregated stats for this blog.
     *
     * @param string $period '1h'|'24h'|'7d'|'30d'|'all'
     */
    public static function get_stats( string $period = '24h' ): array {
        global $wpdb;
        $table = self::table();
        $where = '';
        switch ( $period ) {
            case '1h':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";  break;
            case '24h': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; break;
            case '7d':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";   break;
            case '30d': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";  break;
        }
        $row = $wpdb->get_row(
            "SELECT COUNT(*) as total_calls, SUM(success) as success_count,
                    SUM(tokens_prompt + tokens_completion) as total_tokens, AVG(latency_ms) as avg_latency
             FROM `{$table}` {$where}",
            ARRAY_A
        );
        return $row ?: [ 'total_calls' => 0, 'success_count' => 0, 'total_tokens' => 0, 'avg_latency' => 0 ];
    }

    /**
     * Stats broken down by user_id — for Twin master membership quota reporting.
     *
     * @param string $period '24h'|'7d'|'30d'
     * @return array [ { user_id, display_name, total_calls, success_count, total_tokens, avg_latency } ]
     */
    public static function get_stats_by_user( string $period = '7d' ): array {
        global $wpdb;
        $table = self::table();
        $where = '';
        switch ( $period ) {
            case '1h':  $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)";  break;
            case '24h': $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; break;
            case '7d':  $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";   break;
            case '30d': $where = "WHERE l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";  break;
        }
        return $wpdb->get_results(
            "SELECT l.user_id, u.display_name,
                    COUNT(*) AS total_calls, SUM(l.success) AS success_count,
                    SUM(l.tokens_prompt + l.tokens_completion) AS total_tokens,
                    AVG(l.latency_ms) AS avg_latency
             FROM `{$table}` l
             LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
             {$where}
             GROUP BY l.user_id ORDER BY total_tokens DESC LIMIT 100",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Top models by call count for this blog.
     */
    public static function get_top_models( int $limit = 10, string $period = '7d' ): array {
        global $wpdb;
        $table = self::table();
        $where = '';
        switch ( $period ) {
            case '24h': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"; break;
            case '7d':  $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";   break;
            case '30d': $where = "WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";  break;
        }
        $limit = min( $limit, 50 );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT model_used, COUNT(*) as call_count, SUM(tokens_prompt + tokens_completion) as total_tokens
             FROM `{$table}` {$where} GROUP BY model_used ORDER BY call_count DESC LIMIT %d",
            $limit
        ), ARRAY_A ) ?: [];
    }

    /**
     * Purge old entries for this blog.
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
