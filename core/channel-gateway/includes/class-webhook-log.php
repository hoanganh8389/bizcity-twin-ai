<?php
/**
 * Webhook Log — daily-partitioned **file-based** raw audit ledger
 *
 * PHASE 0.33 M1.5 (refactor 2026-05-09):
 *   Storage moved from DB tables (wp_{Y_m_d}_webhook_log) to JSON files
 *   under wp-content/hook-logs/{Y_m_d}/{id}.json. Rationale:
 *     - Webhook payloads are write-heavy, read-rare → file-system is cheaper
 *     - Avoids creating dozens of MySQL tables per quarter
 *     - Easier `tar | gzip` ship-out for incident review
 *     - Channel state lives in the unified wp_bizcity_channel_messages
 *
 * Each event = one JSON file. The numeric id is taken from a per-day
 * counter file (`_counter.txt`, locked with flock). channel_messages
 * table still references the file via (webhook_log_date, webhook_log_id).
 *
 * Public API (unchanged signatures so callers don't break):
 *   BizCity_Webhook_Log::log( $args ) : array{date,id}
 *   BizCity_Webhook_Log::update( $date, $id, $patch ) : bool
 *   BizCity_Webhook_Log::find( $date, $id ) : ?array
 *   BizCity_Webhook_Log::query( $filters ) : array
 *   BizCity_Webhook_Log::counts_by_platform( $days ) : array
 *   BizCity_Webhook_Log::list_partitions() : string[]   // active day dirs
 *   BizCity_Webhook_Log::prune() : array{kept,dropped}
 *   BizCity_Webhook_Log::drop_legacy_tables() : string[]  // one-time cleanup
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0 (PHASE 0.33 M1)
 * @since 1.5.1 (PHASE 0.33 M1.5 — file-based refactor)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Webhook_Log {

	const TTL_DAYS  = 3;
	const CRON_HOOK = 'bizcity_webhook_log_prune';
	const DIR_NAME  = 'hook-logs';
	const DATE_RX   = '/^\d{4}_\d{2}_\d{2}$/';
	const BODY_CAP  = 262144; // 256 KB

	/* ───────────────────────── Paths ───────────────────────── */

	/**
	 * Per-site root directory (multisite-aware).
	 *
	 * Uses wp_upload_dir() so path auto-resolves to
	 * uploads/sites/{blog_id}/hook-logs/ on multisite subsites,
	 * matching the same pattern as bizcity-cg-logs/ and automation-workflow-logs/.
	 *
	 * PHASE CG-Log-Paths Rule LOG-1: never use WP_CONTENT_DIR for dynamic logs.
	 */
	public static function root_dir(): string {
		$upload = wp_upload_dir();
		$path   = $upload['basedir'] . '/' . self::DIR_NAME;
		return apply_filters( 'bizcity_webhook_log_root_dir', $path );
	}

	public static function dir_for( string $date_ymd ): string {
		return self::root_dir() . '/' . $date_ymd;
	}

	public static function today_dir(): string {
		return self::dir_for( self::today_key() );
	}

	public static function today_key(): string {
		// Site-local timezone — partition by site day, prune by site day.
		return wp_date( 'Y_m_d' );
	}

	public static function file_for( string $date_ymd, int $id ): string {
		return self::dir_for( $date_ymd ) . '/' . $id . '.json';
	}

	/* ───────────────────────── Filesystem prep ───────────────────────── */

	private static function ensure_dir( string $dir ): bool {
		if ( is_dir( $dir ) ) {
			return true;
		}
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// Block direct HTTP access at apache/litespeed level + listing.
		$root = self::root_dir();
		if ( $dir === $root || strpos( $dir, $root . '/' ) === 0 ) {
			$ht = $root . '/.htaccess';
			if ( ! file_exists( $ht ) ) {
				@file_put_contents( $ht, "Require all denied\nDeny from all\n" );
			}
			$idx = $root . '/index.html';
			if ( ! file_exists( $idx ) ) {
				@file_put_contents( $idx, '' );
			}
		}
		return true;
	}

	/**
	 * Atomic per-day counter via flock(_counter.txt).
	 *
	 * @return int monotonic id starting at 1
	 */
	private static function next_id( string $date_ymd ): int {
		$dir  = self::dir_for( $date_ymd );
		self::ensure_dir( $dir );
		$path = $dir . '/_counter.txt';
		$fp   = @fopen( $path, 'c+' );
		if ( ! $fp ) {
			// Fallback: timestamp-based if FS is read-only — should never happen.
			return (int) ( microtime( true ) * 1000 );
		}
		try {
			flock( $fp, LOCK_EX );
			$cur = (int) trim( (string) stream_get_contents( $fp ) );
			$next = $cur + 1;
			ftruncate( $fp, 0 );
			rewind( $fp );
			fwrite( $fp, (string) $next );
			fflush( $fp );
			return $next;
		} finally {
			flock( $fp, LOCK_UN );
			fclose( $fp );
		}
	}

	/* ───────────────────────── Write ───────────────────────── */

	/**
	 * Insert a webhook log file.
	 *
	 * @param array $args {
	 *   platform, endpoint, method?, http_status?, verify_status?, latency_ms?,
	 *   remote_ip?, user_agent?, headers?, body_raw?, channel_message_id?,
	 *   character_id?, error?, is_replay?, parent_log_date?, parent_log_id?
	 * }
	 * @return array{date:string,id:int} ('id' = 0 on failure)
	 */
	public static function log( array $args ): array {
		$date = self::today_key();
		$dir  = self::today_dir();
		if ( ! self::ensure_dir( $dir ) ) {
			return array( 'date' => $date, 'id' => 0 );
		}
		$id = self::next_id( $date );
		if ( $id <= 0 ) {
			return array( 'date' => $date, 'id' => 0 );
		}

		$body = isset( $args['body_raw'] ) ? (string) $args['body_raw'] : '';
		if ( strlen( $body ) > self::BODY_CAP ) {
			$body = substr( $body, 0, self::BODY_CAP ) . "\n…[truncated " . ( strlen( $body ) - self::BODY_CAP ) . " bytes]";
		}

		$envelope = array(
			'id'                 => $id,
			'date'               => $date,
			'blog_id'            => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'platform'           => strtoupper( (string) ( $args['platform'] ?? '' ) ),
			'endpoint'           => (string) ( $args['endpoint'] ?? '' ),
			'method'             => strtoupper( (string) ( $args['method'] ?? 'POST' ) ),
			'http_status'        => (int) ( $args['http_status'] ?? 0 ),
			'verify_status'      => (string) ( $args['verify_status'] ?? 'pending' ),
			'latency_ms'         => (int) ( $args['latency_ms'] ?? 0 ),
			'remote_ip'          => (string) ( $args['remote_ip'] ?? '' ),
			'user_agent'         => (string) ( $args['user_agent'] ?? '' ),
			'headers'            => isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array(),
			'body'               => $body,
			'channel_message_id' => isset( $args['channel_message_id'] ) ? (int) $args['channel_message_id'] : null,
			'character_id'       => isset( $args['character_id'] ) ? (int) $args['character_id'] : null,
			'error'              => (string) ( $args['error'] ?? '' ),
			'is_replay'          => ! empty( $args['is_replay'] ) ? 1 : 0,
			'parent_log_date'    => isset( $args['parent_log_date'] ) ? (string) $args['parent_log_date'] : null,
			'parent_log_id'      => isset( $args['parent_log_id'] ) ? (int) $args['parent_log_id'] : null,
			'created_at'         => current_time( 'mysql' ),
		);

		$path = self::file_for( $date, $id );
		$ok   = (bool) @file_put_contents( $path, wp_json_encode( $envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), LOCK_EX );
		return array( 'date' => $date, 'id' => $ok ? $id : 0 );
	}

	/**
	 * Patch a subset of fields on an existing log file.
	 * Whitelisted keys: http_status, verify_status, latency_ms, error,
	 * channel_message_id, character_id.
	 */
	public static function update( string $date, int $id, array $patch ): bool {
		if ( ! preg_match( self::DATE_RX, $date ) || $id <= 0 ) {
			return false;
		}
		$path = self::file_for( $date, $id );
		if ( ! is_file( $path ) ) {
			return false;
		}
		$fp = @fopen( $path, 'c+' );
		if ( ! $fp ) {
			return false;
		}
		$ok = false;
		try {
			flock( $fp, LOCK_EX );
			$json = stream_get_contents( $fp );
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				return false;
			}
			$allow = array( 'http_status', 'verify_status', 'latency_ms', 'error', 'channel_message_id', 'character_id' );
			foreach ( $allow as $k ) {
				if ( array_key_exists( $k, $patch ) ) {
					$data[ $k ] = $patch[ $k ];
				}
			}
			$out = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			ftruncate( $fp, 0 );
			rewind( $fp );
			fwrite( $fp, $out );
			fflush( $fp );
			$ok = true;
		} finally {
			flock( $fp, LOCK_UN );
			fclose( $fp );
		}
		return $ok;
	}

	public static function find( string $date, int $id ): ?array {
		if ( ! preg_match( self::DATE_RX, $date ) || $id <= 0 ) {
			return null;
		}
		$path = self::file_for( $date, $id );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$json = @file_get_contents( $path );
		$data = json_decode( (string) $json, true );
		return is_array( $data ) ? $data : null;
	}

	/* ───────────────────────── Read ───────────────────────── */

	/**
	 * Recent active day-keys, newest first, capped at TTL_DAYS unless overridden.
	 *
	 * @return string[] ['2026_05_09', '2026_05_08', ...]
	 */
	public static function recent_dates( int $days = self::TTL_DAYS ): array {
		$days = max( 1, min( self::TTL_DAYS, $days ) );
		$out  = array();
		$now  = current_time( 'timestamp' );
		for ( $i = 0; $i < $days; $i++ ) {
			$out[] = wp_date( 'Y_m_d', $now - ( $i * DAY_IN_SECONDS ) );
		}
		return $out;
	}

	/**
	 * @return string[] active day-keys that have a directory (not all of recent_dates may exist)
	 */
	public static function list_partitions(): array {
		$root = self::root_dir();
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$out = array();
		foreach ( (array) @scandir( $root ) as $entry ) {
			if ( preg_match( self::DATE_RX, $entry ) && is_dir( $root . '/' . $entry ) ) {
				$out[] = $entry;
			}
		}
		rsort( $out );
		return $out;
	}

	/**
	 * Scan + filter recent logs.
	 *
	 * @param array $filters {platform?, verify_status?, http_min?, http_max?,
	 *                        days?(default TTL_DAYS), since?, until?, limit?}
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $filters = array() ): array {
		$days  = isset( $filters['days'] ) ? max( 1, min( self::TTL_DAYS, (int) $filters['days'] ) ) : self::TTL_DAYS;
		$dates = self::recent_dates( $days );

		$want_platform = isset( $filters['platform'] ) ? strtoupper( (string) $filters['platform'] ) : '';
		$want_verify   = isset( $filters['verify_status'] ) ? (string) $filters['verify_status'] : '';
		$http_min      = isset( $filters['http_min'] ) ? (int) $filters['http_min'] : null;
		$http_max      = isset( $filters['http_max'] ) ? (int) $filters['http_max'] : null;
		$since         = isset( $filters['since'] ) ? (string) $filters['since'] : '';
		$until         = isset( $filters['until'] ) ? (string) $filters['until'] : '';
		$limit         = isset( $filters['limit'] ) ? max( 1, min( 500, (int) $filters['limit'] ) ) : 100;

		$out = array();
		foreach ( $dates as $date ) {
			$dir = self::dir_for( $date );
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$entries = (array) @scandir( $dir );
			// Numeric ids → reverse sort to get newest first within day.
			$ids = array();
			foreach ( $entries as $f ) {
				if ( preg_match( '/^(\d+)\.json$/', $f, $m ) ) {
					$ids[] = (int) $m[1];
				}
			}
			rsort( $ids, SORT_NUMERIC );
			foreach ( $ids as $id ) {
				$data = self::find( $date, $id );
				if ( ! $data ) {
					continue;
				}
				if ( $want_platform !== '' && (string) ( $data['platform'] ?? '' ) !== $want_platform ) { continue; }
				if ( $want_verify !== '' && (string) ( $data['verify_status'] ?? '' ) !== $want_verify ) { continue; }
				if ( $http_min !== null && (int) ( $data['http_status'] ?? 0 ) < $http_min ) { continue; }
				if ( $http_max !== null && (int) ( $data['http_status'] ?? 0 ) > $http_max ) { continue; }
				if ( $since !== '' && (string) ( $data['created_at'] ?? '' ) < $since ) { continue; }
				if ( $until !== '' && (string) ( $data['created_at'] ?? '' ) > $until ) { continue; }
				// Project a compact row for list view (drop body + headers).
				$out[] = array(
					'log_date'           => $date,
					'id'                 => (int) $data['id'],
					'blog_id'            => (int) ( $data['blog_id'] ?? 0 ),
					'platform'           => (string) ( $data['platform'] ?? '' ),
					'endpoint'           => (string) ( $data['endpoint'] ?? '' ),
					'method'             => (string) ( $data['method'] ?? '' ),
					'http_status'        => (int) ( $data['http_status'] ?? 0 ),
					'verify_status'      => (string) ( $data['verify_status'] ?? '' ),
					'latency_ms'         => (int) ( $data['latency_ms'] ?? 0 ),
					'remote_ip'          => (string) ( $data['remote_ip'] ?? '' ),
					'user_agent'         => (string) ( $data['user_agent'] ?? '' ),
					'channel_message_id' => isset( $data['channel_message_id'] ) ? (int) $data['channel_message_id'] : null,
					'character_id'       => isset( $data['character_id'] ) ? (int) $data['character_id'] : null,
					'error'              => (string) ( $data['error'] ?? '' ),
					'is_replay'          => (int) ( $data['is_replay'] ?? 0 ),
					'created_at'         => (string) ( $data['created_at'] ?? '' ),
				);
				if ( count( $out ) >= $limit ) {
					break 2;
				}
			}
		}
		return $out;
	}

	/**
	 * @return array<string,int> e.g. ['FB_MESS'=>87, 'ZALO_BOT'=>52]
	 */
	public static function counts_by_platform( int $days = self::TTL_DAYS ): array {
		$rows = self::query( array( 'days' => $days, 'limit' => 500 ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$p = (string) $r['platform'];
			$out[ $p ] = ( $out[ $p ] ?? 0 ) + 1;
		}
		return $out;
	}

	/* ───────────────────────── Prune ───────────────────────── */

	/**
	 * Recursively delete day-directories older than TTL_DAYS.
	 *
	 * @return array{kept:string[],dropped:string[]}
	 */
	public static function prune(): array {
		$root   = self::root_dir();
		$kept   = array();
		$drop   = array();
		if ( ! is_dir( $root ) ) {
			return array( 'kept' => $kept, 'dropped' => $drop );
		}
		$keep = array_flip( self::recent_dates( self::TTL_DAYS ) );
		foreach ( (array) @scandir( $root ) as $entry ) {
			if ( ! preg_match( self::DATE_RX, $entry ) ) {
				continue;
			}
			$path = $root . '/' . $entry;
			if ( ! is_dir( $path ) ) {
				continue;
			}
			if ( isset( $keep[ $entry ] ) ) {
				$kept[] = $entry;
			} else {
				self::rmdir_recursive( $path );
				$drop[] = $entry;
			}
		}
		return array( 'kept' => $kept, 'dropped' => $drop );
	}

	private static function rmdir_recursive( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) @scandir( $dir ) as $f ) {
			if ( $f === '.' || $f === '..' ) {
				continue;
			}
			$p = $dir . '/' . $f;
			if ( is_dir( $p ) ) {
				self::rmdir_recursive( $p );
			} else {
				@unlink( $p );
			}
		}
		@rmdir( $dir );
	}

	/* ───────────────────────── Cron ───────────────────────── */

	public static function register_cron(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'cron_prune' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// 02:15 site-local daily.
			$tomorrow = strtotime( 'tomorrow 02:15', current_time( 'timestamp' ) );
			wp_schedule_event( $tomorrow, 'daily', self::CRON_HOOK );
		}
	}

	public static function cron_prune(): void {
		self::prune();
	}

	/* ───────────────────────── Legacy DB cleanup ───────────────────────── */

	/**
	 * One-time: DROP every legacy wp_*_webhook_log table left over from
	 * the DB-based version of this class. Idempotent.
	 *
	 * @return string[] dropped table names
	 */
	public static function drop_legacy_tables(): array {
		global $wpdb;
		$prefix = $wpdb->esc_like( $wpdb->prefix );
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . '%_webhook_log' ) );
		$dropped = array();
		foreach ( $tables as $t ) {
			if ( preg_match( '/_\d{4}_\d{2}_\d{2}_webhook_log$/', $t ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS `{$t}`" );
				$dropped[] = $t;
			}
		}
		return $dropped;
	}
}
