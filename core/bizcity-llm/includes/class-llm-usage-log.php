<?php
/**
 * BizCity LLM — Usage Logging (File-based JSONL)
 *
 * Wave 1 (client): migrate per-blog usage trace from SQL table
 * `{prefix}bizcity_llm_usage_clients` to uploads JSONL files.
 *
 * @package BizCity_LLM
 * @since   2026-07-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * File-based usage log engine.
 */
class BizCity_LLM_Usage_File_Log {

    const BASE_FOLDER = 'bizcity-usage-logs';

    // [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — runtime cleanup version gate for legacy SQL tables.
    const CLEANUP_VER_OPT = 'bizcity_llm_usage_sql_cleanup_ver';
    const CLEANUP_VER     = 1;
    const CLEANUP_HOOK    = 'bizcity_llm_usage_cleanup_blog';

    /** @var array<string,string> */
    private static $dir_cache = array();

    /**
     * Canonical service buckets.
     */
    const SERVICES = array( 'llm', 'embedding', 'search', 'video', 'image', 'astro', 'market', 'tools' );

    /**
     * Keep compatibility with old bootstrap call sites.
     */
    public static function maybe_install(): void {
        self::ensure_hooks_registered();
        self::maybe_schedule_cleanup_for_current_blog();
    }

    /**
     * Keep compatibility with callers that expect install().
     */
    public static function install(): void {
        self::ensure_hooks_registered();
    }

    /**
     * Keep compatibility with callers that used pending logs.
     * File log is append-only, so pending rows are not persisted.
     */
    public static function log_pending( array $data ): int {
        self::maybe_install();
        return 0;
    }

    /**
     * Keep compatibility with callers that used pending->done flow.
     */
    public static function log_done( int $id, array $result ): void {
        unset( $id );
        self::write( $result );
    }

    /**
     * Single-shot writer.
     *
     * @param array $data Usage payload.
     * @return bool
     */
    public static function write( array $data ): bool {
        self::maybe_install();

        $ts = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d\TH:i:s' ) : gmdate( 'Y-m-d\TH:i:s' );

        $usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

        $endpoint = sanitize_text_field( (string) ( $data['endpoint'] ?? 'chat' ) );
        $service  = sanitize_text_field( (string) ( $data['service'] ?? '' ) );
        if ( $service === '' ) {
            $service = self::infer_service( $endpoint );
        }
        if ( ! in_array( $service, self::SERVICES, true ) ) {
            $service = 'llm';
        }

        $surface = sanitize_key( (string) ( $data['surface'] ?? '' ) );
        if ( $surface === '' ) {
            $surface = 'unknown';
        }

        $channel = sanitize_key( (string) ( $data['channel'] ?? '' ) );
        if ( $channel === '' ) {
            $channel = 'unknown';
        }

        $flow = sanitize_key( (string) ( $data['flow'] ?? '' ) );
        if ( $flow === '' ) {
            if ( $surface === 'twinchat' ) {
                $flow = 'b2b';
            } elseif ( $surface === 'twinweb' ) {
                $flow = 'b2b2c';
            } else {
                $flow = 'unknown';
            }
        }

        $entry = array(
            'ts'                => $ts,
            'blog_id'           => (int) get_current_blog_id(),
            'user_id'           => (int) get_current_user_id(),
            'status'            => ! empty( $data['success'] ) ? 'done' : 'failed',
            'flow'              => $flow,
            'surface'           => $surface,
            'channel'           => $channel,
            'service'           => $service,
            'mode'              => sanitize_text_field( (string) ( $data['mode'] ?? 'gateway' ) ),
            'purpose'           => sanitize_text_field( (string) ( $data['purpose'] ?? 'chat' ) ),
            'endpoint'          => $endpoint,
            'model_requested'   => sanitize_text_field( (string) ( $data['model_requested'] ?? '' ) ),
            'model_used'        => sanitize_text_field( (string) ( $data['model_used'] ?? '' ) ),
            'fallback_used'     => ! empty( $data['fallback_used'] ),
            'success'           => ! empty( $data['success'] ),
            'tokens_prompt'     => (int) ( $usage['prompt_tokens'] ?? 0 ),
            'tokens_completion' => (int) ( $usage['completion_tokens'] ?? 0 ),
            'latency_ms'        => (int) ( $data['latency_ms'] ?? 0 ),
            'error'             => mb_substr( sanitize_text_field( (string) ( $data['error'] ?? '' ) ), 0, 500 ),
        );

        return self::append_line( $entry );
    }

    /**
     * Compatibility alias for previous API.
     */
    public static function log( array $data ): void {
        self::write( $data );
    }

    /**
     * Read recent entries newest-first.
     */
    public static function get_recent( int $limit = 50, int $offset = 0, int $user_id = 0 ): array {
        $limit  = max( 1, min( $limit, 200 ) );
        $offset = max( 0, $offset );

        $dates = self::list_dates( 120 );
        if ( empty( $dates ) ) {
            return array();
        }

        $rows = array();
        foreach ( $dates as $date ) {
            $lines = self::read_raw_lines_for_date( $date );
            if ( empty( $lines ) ) {
                continue;
            }
            foreach ( array_reverse( $lines ) as $raw ) {
                $obj = json_decode( $raw, true );
                if ( ! is_array( $obj ) ) {
                    continue;
                }
                if ( $user_id > 0 && (int) ( $obj['user_id'] ?? 0 ) !== $user_id ) {
                    continue;
                }
                $rows[] = self::normalize_row( $obj );
                if ( count( $rows ) >= ( $offset + $limit ) ) {
                    break 2;
                }
            }
        }

        if ( empty( $rows ) ) {
            return array();
        }

        return array_slice( $rows, $offset, $limit );
    }

    /**
     * Aggregated stats.
     */
    public static function get_stats( string $period = '24h', array $filters = array() ): array {
        $agg = self::aggregate_period( $period, $filters );

        $out = array(
            'total_calls'             => (int) $agg['total_calls'],
            'success_count'           => (int) $agg['success_count'],
            'error_count'             => (int) $agg['error_count'],
            'fallback_count'          => (int) $agg['fallback_count'],
            'total_prompt_tokens'     => (int) $agg['total_prompt_tokens'],
            'total_completion_tokens' => (int) $agg['total_completion_tokens'],
            'avg_latency_ms'          => 0,
            'avg_latency'             => 0,
            'max_latency_ms'          => (int) $agg['max_latency_ms'],
            'total_tokens'            => 0,
        );

        if ( $out['total_calls'] > 0 ) {
            $out['avg_latency_ms'] = (int) round( (float) $agg['latency_sum_ms'] / max( 1, $out['total_calls'] ) );
            $out['avg_latency']    = $out['avg_latency_ms'];
        }

        $out['total_tokens'] = $out['total_prompt_tokens'] + $out['total_completion_tokens'];

        return $out;
    }

    /**
     * Aggregated stats grouped by service.
     */
    public static function get_stats_by_service( string $period = '24h', array $filters = array() ): array {
        $services = self::SERVICES;
        $out = array();
        foreach ( $services as $svc ) {
            $out[ $svc ] = array(
                'service'                 => $svc,
                'total_calls'             => 0,
                'success_count'           => 0,
                'error_count'             => 0,
                'total_prompt_tokens'     => 0,
                'total_completion_tokens' => 0,
                'total_tokens'            => 0,
                'avg_latency_ms'          => 0,
                'avg_latency'             => 0,
            );
        }

        self::scan_period_rows( $period, function ( array $row ) use ( &$out ) {
            $svc = sanitize_text_field( (string) ( $row['service'] ?? 'llm' ) );
            if ( $svc === '' || ! isset( $out[ $svc ] ) ) {
                $svc = 'llm';
            }
            $out[ $svc ]['total_calls']++;
            $out[ $svc ]['success_count'] += ! empty( $row['success'] ) ? 1 : 0;
            $out[ $svc ]['error_count'] += empty( $row['success'] ) ? 1 : 0;
            $out[ $svc ]['total_prompt_tokens'] += (int) ( $row['tokens_prompt'] ?? 0 );
            $out[ $svc ]['total_completion_tokens'] += (int) ( $row['tokens_completion'] ?? 0 );
            $out[ $svc ]['total_tokens'] += (int) ( $row['tokens_prompt'] ?? 0 ) + (int) ( $row['tokens_completion'] ?? 0 );
            $out[ $svc ]['_latency_sum'] = ( $out[ $svc ]['_latency_sum'] ?? 0 ) + (int) ( $row['latency_ms'] ?? 0 );
        }, $filters );

        foreach ( $out as $svc => $data ) {
            $calls = (int) ( $data['total_calls'] ?? 0 );
            $sum   = (int) ( $data['_latency_sum'] ?? 0 );
            $out[ $svc ]['avg_latency_ms'] = $calls > 0 ? (int) round( $sum / $calls ) : 0;
            $out[ $svc ]['avg_latency']    = $out[ $svc ]['avg_latency_ms'];
            unset( $out[ $svc ]['_latency_sum'] );
        }

        return $out;
    }

    /**
     * Stats grouped by user_id.
     */
    public static function get_stats_by_user( string $period = '7d', array $filters = array() ): array {
        $bucket = array();

        self::scan_period_rows( $period, function ( array $row ) use ( &$bucket ) {
            $uid = (int) ( $row['user_id'] ?? 0 );
            if ( $uid <= 0 ) {
                return;
            }

            if ( ! isset( $bucket[ $uid ] ) ) {
                $bucket[ $uid ] = array(
                    'user_id'       => $uid,
                    'display_name'  => '#' . $uid,
                    'total_calls'   => 0,
                    'success_count' => 0,
                    'total_tokens'  => 0,
                    'avg_latency'   => 0,
                    '_latency_sum'  => 0,
                );

                $u = get_userdata( $uid );
                if ( $u && ! empty( $u->display_name ) ) {
                    $bucket[ $uid ]['display_name'] = (string) $u->display_name;
                }
            }

            $bucket[ $uid ]['total_calls']++;
            $bucket[ $uid ]['success_count'] += ! empty( $row['success'] ) ? 1 : 0;
            $bucket[ $uid ]['total_tokens'] += (int) ( $row['tokens_prompt'] ?? 0 ) + (int) ( $row['tokens_completion'] ?? 0 );
            $bucket[ $uid ]['_latency_sum'] += (int) ( $row['latency_ms'] ?? 0 );
        }, $filters );

        foreach ( $bucket as $uid => $row ) {
            $calls = max( 1, (int) $row['total_calls'] );
            $bucket[ $uid ]['avg_latency'] = (int) round( (int) $row['_latency_sum'] / $calls );
            unset( $bucket[ $uid ]['_latency_sum'] );
        }

        usort( $bucket, function ( array $a, array $b ) {
            $aa = (int) ( $a['total_tokens'] ?? 0 );
            $bb = (int) ( $b['total_tokens'] ?? 0 );
            if ( $aa === $bb ) {
                return 0;
            }
            return ( $aa > $bb ) ? -1 : 1;
        } );

        return array_slice( array_values( $bucket ), 0, 100 );
    }

    /**
     * Top models by call count.
     */
    public static function get_top_models( int $limit = 10, string $period = '7d', array $filters = array() ): array {
        $limit = max( 1, min( $limit, 50 ) );
        $bucket = array();

        self::scan_period_rows( $period, function ( array $row ) use ( &$bucket ) {
            $model = sanitize_text_field( (string) ( $row['model_used'] ?? '' ) );
            if ( $model === '' ) {
                $model = sanitize_text_field( (string) ( $row['model_requested'] ?? '' ) );
            }
            if ( $model === '' ) {
                $model = '(empty)';
            }

            if ( ! isset( $bucket[ $model ] ) ) {
                $bucket[ $model ] = array(
                    'model_used'   => $model,
                    'call_count'   => 0,
                    'calls'        => 0,
                    'total_tokens' => 0,
                    'avg_latency'  => 0,
                    '_latency_sum' => 0,
                );
            }

            $bucket[ $model ]['call_count']++;
            $bucket[ $model ]['calls']++;
            $bucket[ $model ]['total_tokens'] += (int) ( $row['tokens_prompt'] ?? 0 ) + (int) ( $row['tokens_completion'] ?? 0 );
            $bucket[ $model ]['_latency_sum'] += (int) ( $row['latency_ms'] ?? 0 );
        }, $filters );

        foreach ( $bucket as $m => $row ) {
            $calls = max( 1, (int) $row['calls'] );
            $bucket[ $m ]['avg_latency'] = (int) round( (int) $row['_latency_sum'] / $calls );
            unset( $bucket[ $m ]['_latency_sum'] );
        }

        usort( $bucket, function ( array $a, array $b ) {
            $aa = (int) ( $a['call_count'] ?? 0 );
            $bb = (int) ( $b['call_count'] ?? 0 );
            if ( $aa === $bb ) {
                return 0;
            }
            return ( $aa > $bb ) ? -1 : 1;
        } );

        return array_slice( array_values( $bucket ), 0, $limit );
    }

    /**
     * Purge old daily files.
     */
    public static function purge( int $days = 7 ): int { // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep client usage files for one week.
        $days = max( 1, $days );
        $dir  = self::get_base_log_dir();
        if ( $dir === '' || ! is_dir( $dir ) ) {
            return 0;
        }

        $threshold = strtotime( gmdate( 'Y-m-d', strtotime( '-' . $days . ' day' ) ) );
        if ( false === $threshold ) {
            return 0;
        }

        $deleted = 0;
        $files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
        if ( ! is_array( $files ) ) {
            return 0;
        }

        foreach ( $files as $f ) {
            $date = basename( $f, '.jsonl' );
            $ts = strtotime( $date . ' 00:00:00' );
            if ( false === $ts || $ts >= $threshold ) {
                continue;
            }
            if ( @unlink( $f ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Ensure cleanup hook is registered once.
     */
    private static function ensure_hooks_registered(): void {
        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup_legacy_sql_for_blog' ), 10, 1 );
    }

    /**
     * Queue one-time cleanup for current blog.
     */
    private static function maybe_schedule_cleanup_for_current_blog(): void {
        $done_ver = (int) get_option( self::CLEANUP_VER_OPT, 0 );
        if ( $done_ver >= self::CLEANUP_VER ) {
            return;
        }

        $blog_id = (int) get_current_blog_id();
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK, array( $blog_id ) ) ) {
            wp_schedule_single_event( time() + 60, self::CLEANUP_HOOK, array( $blog_id ) );
        }
    }

    /**
     * Cron callback: cleanup legacy SQL tables for one blog.
     */
    public static function cleanup_legacy_sql_for_blog( $blog_id ): void {
        $blog_id = (int) $blog_id;
        if ( $blog_id <= 0 ) {
            return;
        }

        // [2026-07-25 Johnny Chu] R-LLM-USAGE-FILELOG — cleanup in strict blog context with finally restore.
        $did_switch = false;
        if ( function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
            switch_to_blog( $blog_id );
            $did_switch = true;
        }
        try {
            global $wpdb;
            $table_clients = $wpdb->prefix . 'bizcity_llm_usage_clients';
            $table_legacy  = $wpdb->prefix . 'bizcity_llm_usage';

            $wpdb->query( "DROP TABLE IF EXISTS `{$table_clients}`" );
            // [2026-07-31 Johnny Chu] PHASE-1.22-QUARANTINE — keep legacy usage storage while Membership reports still read it; deprecated_tables tracks it until reader migration sign-off.

            delete_option( 'bizcity_llm_usage_clients_db_ver' );
            delete_option( 'bizcity_llm_usage_clients_last_table_check' );
            delete_option( 'bizcity_llm_usage_db_ver' );

            wp_cache_delete( 'bz_tbl_' . $blog_id . '_' . crc32( $table_clients ), 'bizcity_tbl' );
            wp_cache_delete( 'bz_tbl_' . $blog_id . '_' . crc32( $table_legacy ), 'bizcity_tbl' );

            update_option( self::CLEANUP_VER_OPT, self::CLEANUP_VER, false );
        } finally {
            if ( $did_switch && function_exists( 'restore_current_blog' ) ) {
                restore_current_blog();
            }
        }
    }

    /**
     * Infer service bucket from endpoint.
     */
    private static function infer_service( string $endpoint ): string {
        $endpoint = strtolower( $endpoint );
        if ( $endpoint === 'embeddings' || $endpoint === 'embedding' ) return 'embedding';
        if ( $endpoint === 'search' ) return 'search';
        if ( $endpoint === 'video' ) return 'video';
        if ( $endpoint === 'image' || $endpoint === 'image_generation' ) return 'image';
        if ( $endpoint === 'astro' || $endpoint === 'astrology' ) return 'astro';
        if ( $endpoint === 'market' || $endpoint === 'marketplace' ) return 'market';
        if ( $endpoint === 'tool' || $endpoint === 'tools' || $endpoint === 'rerank' ) return 'tools';
        return 'llm';
    }

    /**
     * Aggregate a period into counters.
     */
    private static function aggregate_period( string $period, array $filters = array() ): array {
        $agg = array(
            'total_calls'             => 0,
            'success_count'           => 0,
            'error_count'             => 0,
            'fallback_count'          => 0,
            'total_prompt_tokens'     => 0,
            'total_completion_tokens' => 0,
            'latency_sum_ms'          => 0,
            'max_latency_ms'          => 0,
        );

        self::scan_period_rows( $period, function ( array $row ) use ( &$agg ) {
            $agg['total_calls']++;
            $agg['success_count'] += ! empty( $row['success'] ) ? 1 : 0;
            $agg['error_count'] += empty( $row['success'] ) ? 1 : 0;
            $agg['fallback_count'] += ! empty( $row['fallback_used'] ) ? 1 : 0;
            $agg['total_prompt_tokens'] += (int) ( $row['tokens_prompt'] ?? 0 );
            $agg['total_completion_tokens'] += (int) ( $row['tokens_completion'] ?? 0 );
            $lat = (int) ( $row['latency_ms'] ?? 0 );
            $agg['latency_sum_ms'] += $lat;
            if ( $lat > (int) $agg['max_latency_ms'] ) {
                $agg['max_latency_ms'] = $lat;
            }
        }, $filters );

        return $agg;
    }

    /**
     * Scan normalized rows for a period.
     */
    private static function scan_period_rows( string $period, callable $cb, array $filters = array() ): void {
        $dates = self::dates_for_period( $period );
        if ( empty( $dates ) ) {
            return;
        }

        $range = self::period_time_range( $period );
        $from_ts = (int) $range['from'];
        $want_surface = isset( $filters['surface'] ) ? sanitize_key( (string) $filters['surface'] ) : '';
        $want_flow = isset( $filters['flow'] ) ? sanitize_key( (string) $filters['flow'] ) : '';
        $want_channel = isset( $filters['channel'] ) ? sanitize_key( (string) $filters['channel'] ) : '';

        foreach ( $dates as $date ) {
            $lines = self::read_raw_lines_for_date( $date );
            if ( empty( $lines ) ) {
                continue;
            }
            foreach ( $lines as $raw ) {
                $obj = json_decode( $raw, true );
                if ( ! is_array( $obj ) ) {
                    continue;
                }
                if ( $from_ts > 0 ) {
                    $row_ts = self::row_timestamp( $obj );
                    if ( $row_ts <= 0 || $row_ts < $from_ts ) {
                        continue;
                    }
                }
                $row = self::normalize_row( $obj );
                if ( $want_surface !== '' && sanitize_key( (string) ( $row['surface'] ?? '' ) ) !== $want_surface ) {
                    continue;
                }
                if ( $want_flow !== '' && sanitize_key( (string) ( $row['flow'] ?? '' ) ) !== $want_flow ) {
                    continue;
                }
                if ( $want_channel !== '' && sanitize_key( (string) ( $row['channel'] ?? '' ) ) !== $want_channel ) {
                    continue;
                }
                $cb( $row );
            }
        }
    }

    /**
     * Compute date list for period.
     *
     * @return string[]
     */
    private static function dates_for_period( string $period ): array {
        $period = strtolower( trim( $period ) );
        $days = 0;

        if ( $period === '1h' || $period === '24h' ) {
            $days = 2;
        } elseif ( $period === '7d' ) {
            $days = 8;
        } elseif ( $period === '30d' ) {
            $days = 31;
        } elseif ( $period === 'all' ) {
            return self::list_dates( 365 );
        }

        if ( $days <= 0 ) {
            $days = 2;
        }

        $out = array();
        for ( $i = 0; $i < $days; $i++ ) {
            $out[] = gmdate( 'Y-m-d', strtotime( '-' . $i . ' day' ) );
        }

        return array_reverse( $out );
    }

    /**
     * Resolve period to unix timestamp range.
     *
     * @return array{from:int,to:int}
     */
    private static function period_time_range( string $period ): array {
        $period = strtolower( trim( $period ) );
        $now = time();

        if ( $period === '1h' ) {
            return array( 'from' => $now - HOUR_IN_SECONDS, 'to' => $now );
        }
        if ( $period === '24h' ) {
            return array( 'from' => $now - DAY_IN_SECONDS, 'to' => $now );
        }
        if ( $period === '7d' ) {
            return array( 'from' => $now - ( 7 * DAY_IN_SECONDS ), 'to' => $now );
        }
        if ( $period === '30d' ) {
            return array( 'from' => $now - ( 30 * DAY_IN_SECONDS ), 'to' => $now );
        }

        return array( 'from' => 0, 'to' => $now );
    }

    /**
     * Parse row timestamp from JSONL entry.
     */
    private static function row_timestamp( array $row ): int {
        $raw = '';
        if ( isset( $row['ts'] ) ) {
            $raw = (string) $row['ts'];
        } elseif ( isset( $row['created_at'] ) ) {
            $raw = (string) $row['created_at'];
        }
        if ( $raw === '' ) {
            return 0;
        }
        $ts = strtotime( $raw );
        return false === $ts ? 0 : (int) $ts;
    }

    /**
     * Normalize a row shape to legacy SQL-compatible keys.
     */
    private static function normalize_row( array $row ): array {
        $ts = (string) ( $row['ts'] ?? '' );
        $created = $ts !== '' ? str_replace( 'T', ' ', $ts ) : '';

        return array(
            'created_at'        => $created,
            'updated_at'        => $created,
            'blog_id'           => (int) ( $row['blog_id'] ?? get_current_blog_id() ),
            'user_id'           => (int) ( $row['user_id'] ?? 0 ),
            'status'            => sanitize_text_field( (string) ( $row['status'] ?? ( ! empty( $row['success'] ) ? 'done' : 'failed' ) ) ),
            'flow'              => sanitize_key( (string) ( $row['flow'] ?? 'unknown' ) ),
            'surface'           => sanitize_key( (string) ( $row['surface'] ?? 'unknown' ) ),
            'channel'           => sanitize_key( (string) ( $row['channel'] ?? 'unknown' ) ),
            'service'           => sanitize_text_field( (string) ( $row['service'] ?? 'llm' ) ),
            'mode'              => sanitize_text_field( (string) ( $row['mode'] ?? 'gateway' ) ),
            'purpose'           => sanitize_text_field( (string) ( $row['purpose'] ?? 'chat' ) ),
            'endpoint'          => sanitize_text_field( (string) ( $row['endpoint'] ?? 'chat' ) ),
            'model_requested'   => sanitize_text_field( (string) ( $row['model_requested'] ?? '' ) ),
            'model_used'        => sanitize_text_field( (string) ( $row['model_used'] ?? '' ) ),
            'fallback_used'     => ! empty( $row['fallback_used'] ) ? 1 : 0,
            'success'           => ! empty( $row['success'] ) ? 1 : 0,
            'tokens_prompt'     => (int) ( $row['tokens_prompt'] ?? 0 ),
            'tokens_completion' => (int) ( $row['tokens_completion'] ?? 0 ),
            'latency_ms'        => (int) ( $row['latency_ms'] ?? 0 ),
            'error'             => sanitize_text_field( (string) ( $row['error'] ?? '' ) ),
        );
    }

    /**
     * Read raw JSONL lines for one date.
     *
     * @return string[]
     */
    private static function read_raw_lines_for_date( string $date ): array {
        $dir = self::get_base_log_dir();
        if ( $dir === '' ) {
            return array();
        }

        $file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
        if ( ! file_exists( $file ) ) {
            return array();
        }

        $lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        return is_array( $lines ) ? $lines : array();
    }

    /**
     * List available date files desc.
     *
     * @return string[]
     */
    private static function list_dates( int $max = 60 ): array {
        $dir = self::get_base_log_dir();
        if ( $dir === '' ) {
            return array();
        }

        $files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
        if ( ! is_array( $files ) ) {
            return array();
        }

        $dates = array();
        foreach ( $files as $f ) {
            $dates[] = basename( $f, '.jsonl' );
        }

        rsort( $dates );
        return array_slice( $dates, 0, max( 1, $max ) );
    }

    /**
     * Append one JSON line.
     */
    private static function append_line( array $entry ): bool {
        $dir = self::get_base_log_dir();
        if ( $dir === '' ) {
            return false;
        }

        $date = substr( (string) ( $entry['ts'] ?? '' ), 0, 10 );
        if ( $date === '' ) {
            $date = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
        }

        $line = json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $line ) {
            return false;
        }

        $file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
        return false !== @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * Resolve/create base log dir.
     */
    private static function get_base_log_dir(): string {
        $blog_id = (int) get_current_blog_id();
        if ( isset( self::$dir_cache[ (string) $blog_id ] ) ) {
            return self::$dir_cache[ (string) $blog_id ];
        }

        $upload = wp_upload_dir();
        $base = (string) ( $upload['basedir'] ?? '' );
        if ( $base === '' ) {
            return '';
        }

        $dir = $base . DIRECTORY_SEPARATOR . self::BASE_FOLDER;
        if ( ! file_exists( $dir ) ) {
            @mkdir( $dir, 0755, true );
        }

        $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if ( file_exists( $dir ) && ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\nOptions -Indexes\n" );
        }

        if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
            return '';
        }

        self::$dir_cache[ (string) $blog_id ] = $dir;
        return $dir;
    }
}

/**
 * Backward compatibility wrapper.
 *
 * Existing modules still calling BizCity_LLM_Usage_Clients::*
 * will transparently use file-based logging.
 */
class BizCity_LLM_Usage_Clients {

    const TABLE_SUFFIX = 'bizcity_llm_usage_clients';
    const DB_VER = 1;
    const DB_VER_OPT = 'bizcity_llm_usage_clients_db_ver';
    const TABLE_RECHECK_OPT = 'bizcity_llm_usage_clients_last_table_check';
    const TABLE_RECHECK_TTL = 60000;

    public static function maybe_install(): void {
        BizCity_LLM_Usage_File_Log::maybe_install();
    }

    public static function install(): void {
        BizCity_LLM_Usage_File_Log::install();
    }

    public static function log_pending( array $data ): int {
        unset( $data );
        BizCity_LLM_Usage_File_Log::maybe_install();
        return 0;
    }

    public static function log_done( int $id, array $result ): void {
        unset( $id );
        BizCity_LLM_Usage_File_Log::write( $result );
    }

    public static function log( array $data ): void {
        BizCity_LLM_Usage_File_Log::write( $data );
    }

    public static function get_recent( int $limit = 50, int $offset = 0, int $user_id = 0 ): array {
        return BizCity_LLM_Usage_File_Log::get_recent( $limit, $offset, $user_id );
    }

    public static function get_stats( string $period = '24h' ): array {
        return BizCity_LLM_Usage_File_Log::get_stats( $period );
    }

    public static function get_stats_by_service( string $period = '24h' ): array {
        return BizCity_LLM_Usage_File_Log::get_stats_by_service( $period );
    }

    public static function get_stats_by_user( string $period = '7d' ): array {
        return BizCity_LLM_Usage_File_Log::get_stats_by_user( $period );
    }

    public static function get_top_models( int $limit = 10, string $period = '7d' ): array {
        return BizCity_LLM_Usage_File_Log::get_top_models( $limit, $period );
    }

    public static function purge( int $days = 7 ): int { // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep compatibility usage files for one week.
        return BizCity_LLM_Usage_File_Log::purge( $days );
    }
}

/**
 * Legacy wrapper retained for compatibility.
 */
class BizCity_LLM_Usage_Log {

    const TABLE_SUFFIX = 'bizcity_llm_usage';
    const DB_VER = 2;
    const SERVICES = array( 'llm', 'embedding', 'search', 'video', 'image', 'astro', 'market', 'tools' );

    public static function maybe_install(): void {
        BizCity_LLM_Usage_File_Log::maybe_install();
    }

    public static function install(): void {
        BizCity_LLM_Usage_File_Log::install();
    }

    public static function log( array $data ): void {
        BizCity_LLM_Usage_File_Log::write( $data );
    }

    public static function get_recent( int $limit = 50, int $offset = 0 ): array {
        return BizCity_LLM_Usage_File_Log::get_recent( $limit, $offset, 0 );
    }

    public static function get_stats( $period = '24h' ): array {
        if ( is_int( $period ) ) {
            if ( $period <= 1 ) {
                $period = '24h';
            } elseif ( $period <= 7 ) {
                $period = '7d';
            } elseif ( $period <= 30 ) {
                $period = '30d';
            } else {
                $period = 'all';
            }
        }
        return BizCity_LLM_Usage_File_Log::get_stats( (string) $period );
    }

    public static function get_top_models( int $limit = 10, string $period = '7d' ): array {
        return BizCity_LLM_Usage_File_Log::get_top_models( $limit, $period );
    }

    public static function get_stats_by_service( string $period = '24h' ): array {
        return BizCity_LLM_Usage_File_Log::get_stats_by_service( $period );
    }

    public static function get_top_models_for_service( string $service, int $limit = 10, string $period = '7d' ): array {
        $rows = BizCity_LLM_Usage_File_Log::get_top_models( max( 20, $limit ), $period );
        $svc  = sanitize_text_field( $service );
        if ( $svc === '' ) {
            return array_slice( $rows, 0, $limit );
        }

        $out = array();
        foreach ( $rows as $row ) {
            $model = (string) ( $row['model_used'] ?? '' );
            if ( $model === '' ) {
                continue;
            }
            // Best-effort legacy helper. We do not have per-model service map in this fallback.
            if ( $svc === 'llm' ) {
                $out[] = $row;
            }
        }

        return array_slice( $out, 0, $limit );
    }

    public static function purge( int $days = 7 ): int { // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep legacy usage files for one week.
        return BizCity_LLM_Usage_File_Log::purge( $days );
    }
}
