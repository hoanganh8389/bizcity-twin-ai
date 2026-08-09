<?php
/**
 * Bizcity Twin AI — KG-Hub Per-Source Progress Log (File-based JSONL)
 *
 * Append-only evidence trail for every per-source learning state transition.
 *
 * [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — migrate
 * source-progress telemetry from legacy SQL table
 * `wp_{blog_id}_bizcity_kg_source_progress_log` to uploads JSONL:
 *   uploads/sites/{blog_id}/bizcity-usage-logs/kg-source-progress/YYYY-MM-DD.jsonl
 *
 * Public API is preserved so existing callers (KG REST + TwinChat share/log
 * snapshot builders) do not need code changes.
 *
 * What it records (per-source timeline):
 *   • extract_started        — extract_passage() began
 *   • passage_done           — bizcity_kg_extraction_passage_done fired
 *   • passage_error          — bizcity_kg_extraction_passage_error fired
 *   • batch_done             — bizcity_kg_extraction_batch_done fired
 *   • sweep_enqueued         — sweep cron found stranded chunks → re-enqueued job
 *   • force_reset            — extract_notebook_pending( force=true ) flipped done→pending
 *   • complete               — first time aggregate hit done == total
 *   • aggregate_drop         — aggregate dropped from full → partial (detector for the loop)
 *
 * Storage (current): file-based JSONL under uploads.
 * Storage (legacy): per-blog SQL table, dropped by one-time cleanup hook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      PHASE-0.13 (2026-05-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Source_Progress_Log {

	const SCHEMA_VERSION    = '2.0.0';
	const OPTION_VERSION    = 'bizcity_kg_source_progress_log_version';
	const RETENTION_DAYS    = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep source-progress evidence for one week.
	const BASE_FOLDER       = 'bizcity-usage-logs';
	const SUB_FOLDER        = 'kg-source-progress';
	const CLEANUP_VER_OPT   = 'bizcity_kg_source_progress_sql_cleanup_ver';
	const CLEANUP_VER       = 2;
	const CLEANUP_HOOK      = 'bizcity_kg_source_progress_cleanup_blog';
	const MIGRATION_LAST_ID_OPT = 'bizcity_kg_source_progress_sql_migration_last_id';
	const PURGE_MARKER_OPT  = 'bizcity_kg_source_progress_last_purge_ymd';

	/** @var array<string,string> */
	private static $dir_cache = [];

	/** @var int */
	private static $last_event_id = 0;

	/* ─── Lifecycle ─────────────────────────────────────────────────────── */

	public static function bind() {
		add_action( 'init', [ __CLASS__, 'maybe_install' ], 6 );
		add_action( self::CLEANUP_HOOK, [ __CLASS__, 'cleanup_legacy_sql_for_blog' ], 10, 1 );

		// Hook into existing extractor lifecycle (PHASE-0.7 Wave 0 actions).
		add_action( 'bizcity_kg_extraction_passage_done',  [ __CLASS__, 'on_passage_done' ], 20, 1 );
		add_action( 'bizcity_kg_extraction_passage_error', [ __CLASS__, 'on_passage_error' ], 20, 1 );
		add_action( 'bizcity_kg_extraction_batch_done',    [ __CLASS__, 'on_batch_done' ], 20, 1 );
	}

	public static function maybe_install() {
		// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — filelog
		// mode: ensure dir, queue one-time SQL cleanup, and keep version marker.
		self::get_base_log_dir( true );
		self::maybe_schedule_cleanup_for_current_blog();
		self::maybe_purge_once_per_day();
		if ( get_option( self::OPTION_VERSION ) !== self::SCHEMA_VERSION ) {
			update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		}
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_source_progress_log';
	}

	/* ─── Recording API ─────────────────────────────────────────────────── */

	/**
	 * Append a new event row. Best-effort — never throws (logging must not
	 * break the learning pipeline).
	 *
	 * @param array $args {
	 *   @type int    $notebook_id
	 *   @type int    $source_id
	 *   @type int    $passage_id
	 *   @type string $event           Required. See class header for vocabulary.
	 *   @type string $triggered_by    Optional. Defaults to 'system'.
	 *   @type int    $counts_total    Optional. Snapshot of current source aggregate.
	 *   @type int    $counts_done     Optional.
	 *   @type int    $counts_error    Optional.
	 *   @type array  $payload         Optional. Free-form JSON.
	 * }
	 */
	public static function record( array $args ) {
		try {
			self::maybe_install();

			$entry = array(
				'id'          => self::next_event_id(),
				'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1,
				'notebook_id' => isset( $args['notebook_id'] ) ? (int) $args['notebook_id'] : 0,
				'source_id'   => isset( $args['source_id'] ) ? (int) $args['source_id'] : 0,
				'passage_id'  => isset( $args['passage_id'] ) ? (int) $args['passage_id'] : 0,
				'event'       => sanitize_key( substr( (string) ( $args['event'] ?? '' ), 0, 40 ) ),
				'triggered_by'=> sanitize_key( substr( (string) ( $args['triggered_by'] ?? 'system' ), 0, 40 ) ),
				'counts_total'=> isset( $args['counts_total'] ) ? (int) $args['counts_total'] : null,
				'counts_done' => isset( $args['counts_done'] ) ? (int) $args['counts_done'] : null,
				'counts_error'=> isset( $args['counts_error'] ) ? (int) $args['counts_error'] : null,
				'payload'     => isset( $args['payload'] ) && is_array( $args['payload'] ) ? $args['payload'] : null,
				'created_at'  => current_time( 'mysql', true ),
			);

			if ( $entry['event'] === '' ) {
				$entry['event'] = 'log';
			}

			self::append_line( $entry );
		} catch ( \Throwable $e ) {
			error_log( '[KG Source Progress Log] record failed: ' . $e->getMessage() );
		}
	}

	/* ─── Query API (used by REST endpoint) ─────────────────────────────── */

	/**
	 * Fetch the most recent N events for a source, newest first.
	 *
	 * @param int $source_id
	 * @param int $limit
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_for_source( $source_id, $limit = 100 ) {
		self::maybe_install();
		$source_id = (int) $source_id;
		$limit     = max( 1, min( 500, (int) $limit ) );
		if ( $source_id <= 0 ) {
			return [];
		}

		return self::collect_recent_events( $limit, static function ( array $row ) use ( $source_id ) {
			return (int) ( $row['source_id'] ?? 0 ) === $source_id;
		} );
	}

	/**
	 * Fetch a notebook-wide timeline (all sources + sweep events with NULL source).
	 */
	public static function get_for_notebook( $notebook_id, $limit = 200 ) {
		self::maybe_install();
		$notebook_id = (int) $notebook_id;
		$limit       = max( 1, min( 1000, (int) $limit ) );
		if ( $notebook_id <= 0 ) {
			return [];
		}

		return self::collect_recent_events( $limit, static function ( array $row ) use ( $notebook_id ) {
			return (int) ( $row['notebook_id'] ?? 0 ) === $notebook_id;
		} );
	}

	/**
	 * Summarise per-source activity: counts of each event type and last_event_at.
	 * Used by the FE evidence panel to flag suspicious patterns
	 * (e.g. > 1 force_reset, > 3 sweep_enqueued for the same source).
	 */
	public static function summarise_for_source( $source_id ) {
		self::maybe_install();
		$source_id = (int) $source_id;
		if ( $source_id <= 0 ) {
			return [];
		}

		$out = [];

		self::scan_recent_days( 120, static function ( array $row ) use ( &$out, $source_id ) {
			if ( (int) ( $row['source_id'] ?? 0 ) !== $source_id ) {
				return true;
			}
			$event = sanitize_key( (string) ( $row['event'] ?? 'log' ) );
			if ( $event === '' ) {
				$event = 'log';
			}
			if ( ! isset( $out[ $event ] ) ) {
				$out[ $event ] = [
					'count'   => 0,
					'last_at' => '',
				];
			}
			$out[ $event ]['count']++;
			$created_at = (string) ( $row['created_at'] ?? '' );
			if ( $created_at !== '' && ( $out[ $event ]['last_at'] === '' || strcmp( $created_at, $out[ $event ]['last_at'] ) > 0 ) ) {
				$out[ $event ]['last_at'] = $created_at;
			}
			return true;
		} );

		foreach ( $out as $event => $meta ) {
			$out[ $event ]['count'] = (int) $meta['count'];
		}

		return $out;
	}

	private static function hydrate( $row ) {
		if ( ! is_array( $row ) ) {
			return [];
		}
		if ( isset( $row['payload'] ) && is_string( $row['payload'] ) && $row['payload'] !== '' ) {
			$decoded = json_decode( (string) $row['payload'], true );
			$row['payload'] = is_array( $decoded ) ? $decoded : null;
		}
		$row['id'] = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$row['notebook_id'] = isset( $row['notebook_id'] ) ? (int) $row['notebook_id'] : 0;
		$row['source_id'] = isset( $row['source_id'] ) ? (int) $row['source_id'] : 0;
		$row['passage_id'] = isset( $row['passage_id'] ) ? (int) $row['passage_id'] : 0;
		$row['event'] = sanitize_key( (string) ( $row['event'] ?? '' ) );
		$row['triggered_by'] = sanitize_key( (string) ( $row['triggered_by'] ?? 'system' ) );
		$row['counts_total'] = isset( $row['counts_total'] ) ? (int) $row['counts_total'] : null;
		$row['counts_done'] = isset( $row['counts_done'] ) ? (int) $row['counts_done'] : null;
		$row['counts_error'] = isset( $row['counts_error'] ) ? (int) $row['counts_error'] : null;
		$row['created_at'] = (string) ( $row['created_at'] ?? '' );
		return $row;
	}

	/* ─── Action hooks (auto-bound in bind()) ───────────────────────────── */

	public static function on_passage_done( $args ) {
		if ( ! is_array( $args ) ) return;
		$src = self::resolve_source_id_from_passage( (int) ( $args['passage_id'] ?? 0 ) );
		self::record( [
			'notebook_id'  => (int) ( $args['notebook_id'] ?? 0 ),
			'source_id'    => $src,
			'passage_id'   => (int) ( $args['passage_id']  ?? 0 ),
			'event'        => 'passage_done',
			'triggered_by' => self::detect_trigger(),
			'payload'      => [
				'triplets'  => (int) ( $args['triplets']  ?? 0 ),
				'cache_hit' => (bool) ( $args['cache_hit'] ?? false ),
			],
		] );
	}

	public static function on_passage_error( $args ) {
		if ( ! is_array( $args ) ) return;
		$src = self::resolve_source_id_from_passage( (int) ( $args['passage_id'] ?? 0 ) );
		self::record( [
			'notebook_id'  => (int) ( $args['notebook_id'] ?? 0 ),
			'source_id'    => $src,
			'passage_id'   => (int) ( $args['passage_id']  ?? 0 ),
			'event'        => 'passage_error',
			'triggered_by' => self::detect_trigger(),
			'payload'      => [
				'error' => (string) ( $args['error'] ?? '' ),
			],
		] );
	}

	public static function on_batch_done( $args ) {
		if ( ! is_array( $args ) ) return;
		// Notebook-wide event (no single source_id). Stored with source_id=NULL.
		self::record( [
			'notebook_id'  => (int) ( $args['notebook_id'] ?? 0 ),
			'event'        => 'batch_done',
			'triggered_by' => self::detect_trigger(),
			'payload'      => [
				'processed'      => (int)   ( $args['processed']      ?? 0 ),
				'total_triplets' => (int)   ( $args['total_triplets'] ?? 0 ),
				'errors'         => (int)   ( $args['errors']         ?? 0 ),
				'remaining'      => (int)   ( $args['remaining']      ?? 0 ),
				'time_exceeded'  => (bool)  ( $args['time_exceeded']  ?? false ),
				'elapsed_s'      => (float) ( $args['elapsed_s']      ?? 0 ),
			],
		] );
	}

	/* ─── Helpers ───────────────────────────────────────────────────────── */

	/**
	 * Look up source_id for a passage. Cached per request to avoid N+1 in batch.
	 */
	private static function resolve_source_id_from_passage( $passage_id ) {
		static $cache = [];
		$passage_id = (int) $passage_id;
		if ( $passage_id <= 0 ) return null;
		if ( array_key_exists( $passage_id, $cache ) ) return $cache[ $passage_id ];
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return null;
		$tbl = BizCity_KG_Database::instance()->tbl_passages();
		$sid = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_id FROM {$tbl} WHERE id = %d", $passage_id
		) );
		$cache[ $passage_id ] = $sid !== null ? (int) $sid : null;
		return $cache[ $passage_id ];
	}

	/**
	 * Best-effort detection of who triggered the current request.
	 * Reads WP-CLI / cron / REST flags + a thread-local set by callers
	 * (sweep cron sets `bizcity_kg_progress_log_trigger` filter).
	 */
	private static function detect_trigger() {
		$override = apply_filters( 'bizcity_kg_progress_log_trigger', '' );
		if ( $override ) return (string) $override;
		if ( defined( 'WP_CLI' ) && WP_CLI ) return 'cli';
		if ( wp_doing_cron() ) return 'cron';
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return 'rest';
		return 'system';
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — collect
	 * newest-first events from JSONL logs with predicate filtering.
	 */
	private static function collect_recent_events( int $limit, callable $predicate ): array {
		$rows = array();
		self::scan_recent_days( 120, static function ( array $row ) use ( &$rows, $limit, $predicate ) {
			if ( ! $predicate( $row ) ) {
				return true;
			}
			$rows[] = self::hydrate( $row );
			if ( count( $rows ) >= $limit ) {
				return false;
			}
			return true;
		} );

		// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — use the
		// persisted timestamp as the primary order across PHP processes.
		usort( $rows, static function ( array $a, array $b ) {
			$created_a = (string) ( $a['created_at'] ?? '' );
			$created_b = (string) ( $b['created_at'] ?? '' );
			if ( $created_a !== $created_b ) {
				return ( strcmp( $created_a, $created_b ) > 0 ) ? -1 : 1;
			}
			$ia = (int) ( $a['id'] ?? 0 );
			$ib = (int) ( $b['id'] ?? 0 );
			if ( $ia === $ib ) {
				return 0;
			}
			return ( $ia > $ib ) ? -1 : 1;
		} );

		if ( count( $rows ) > $limit ) {
			$rows = array_slice( $rows, 0, $limit );
		}
		return $rows;
	}

	/**
	 * Scan date files from newest to oldest.
	 * Callback must return true to continue, false to stop early.
	 */
	private static function scan_recent_days( int $max_days, callable $cb ): void {
		$dates = self::list_dates( $max_days );
		if ( empty( $dates ) ) {
			return;
		}

		foreach ( $dates as $date ) {
			$dir = self::get_base_log_dir( false );
			if ( $dir === '' ) {
				continue;
			}
			$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
			$continue = self::scan_file_newest_first( $file, static function ( string $raw ) use ( $cb ) {
				$decoded = json_decode( $raw, true );
				if ( ! is_array( $decoded ) ) {
					return true;
				}
				return $cb( $decoded );
			} );
			if ( ! $continue ) {
				return;
			}
		}
	}

	private static function scan_file_newest_first( string $file, callable $cb ): bool {
		// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — stream
		// reverse chunks so daily JSONL size does not determine query memory.
		if ( ! is_file( $file ) ) {
			return true;
		}
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return true;
		}
		$size     = filesize( $file );
		$position = false === $size ? 0 : (int) $size;
		$partial  = '';
		$chunk_size = 8192;
		while ( $position > 0 ) {
			$read_size = min( $chunk_size, $position );
			$position -= $read_size;
			if ( 0 !== fseek( $handle, $position ) ) {
				fclose( $handle );
				return true;
			}
			$chunk = fread( $handle, $read_size );
			if ( false === $chunk || $chunk === '' ) {
				break;
			}
			$parts   = explode( "\n", $chunk . $partial );
			$partial = array_shift( $parts );
			for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
				$line = trim( (string) $parts[ $i ] );
				if ( $line !== '' && ! $cb( $line ) ) {
					fclose( $handle );
					return false;
				}
			}
		}
		$partial = trim( $partial );
		if ( $partial !== '' ) {
			if ( ! $cb( $partial ) ) {
				fclose( $handle );
				return false;
			}
		}
		fclose( $handle );
		return true;
	}

	private static function maybe_schedule_cleanup_for_current_blog(): void {
		$done_ver = (int) get_option( self::CLEANUP_VER_OPT, 0 );
		if ( $done_ver >= self::CLEANUP_VER ) {
			return;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK, array( $blog_id ) ) ) {
			wp_schedule_single_event( time() + 90, self::CLEANUP_HOOK, array( $blog_id ) );
		}
	}

	public static function cleanup_legacy_sql_for_blog( $blog_id ): void {
		$blog_id = (int) $blog_id;
		if ( $blog_id <= 0 ) {
			return;
		}

		$did_switch = false;
		$origin_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_blog_details' ) && ! get_blog_details( $blog_id ) ) {
			return;
		}
		if ( function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' ) ) {
			if ( $origin_blog_id !== $blog_id ) {
				if ( ! switch_to_blog( $blog_id ) ) {
					return;
				}
				$did_switch = true;
			}
		}

		try {
			global $wpdb;
			$table = self::table();
			if ( ! self::legacy_table_exists( $table ) ) {
				delete_option( self::MIGRATION_LAST_ID_OPT );
				update_option( self::CLEANUP_VER_OPT, self::CLEANUP_VER, false );
				return;
			}

			// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — migrate
			// legacy rows in resumable batches before dropping the old table.
			$last_id = (int) get_option( self::MIGRATION_LAST_ID_OPT, 0 );
			do {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, notebook_id, source_id, passage_id, event, triggered_by, counts_total, counts_done, counts_error, payload, created_at FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 500",
						$last_id
					),
					ARRAY_A
				);
				if ( ! is_array( $rows ) ) {
					return;
				}

				foreach ( $rows as $row ) {
					$legacy_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
					if ( $legacy_id <= $last_id ) {
						continue;
					}
					$entry = self::normalise_legacy_row( $row, $blog_id );
					if ( ! self::append_line( $entry ) ) {
						return;
					}
					$last_id = $legacy_id;
					update_option( self::MIGRATION_LAST_ID_OPT, $last_id, false );
				}
			} while ( count( $rows ) === 500 );

			// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG —
			// only drop after every legacy row has been persisted successfully.
			$drop_result = $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
			if ( false === $drop_result ) {
				return;
			}

			delete_option( self::OPTION_VERSION );
			delete_option( self::MIGRATION_LAST_ID_OPT );
			wp_cache_delete( 'bz_tbl_' . $blog_id . '_' . crc32( $table ), 'bizcity_tbl' );
			update_option( self::CLEANUP_VER_OPT, self::CLEANUP_VER, false );
		} finally {
			if ( $did_switch && function_exists( 'restore_current_blog' ) ) {
				restore_current_blog();
			}
		}
	}

	private static function maybe_purge_once_per_day(): void {
		$today = gmdate( 'Y-m-d' );
		$last  = (string) get_option( self::PURGE_MARKER_OPT, '' );
		if ( $last === $today ) {
			return;
		}
		self::purge( self::RETENTION_DAYS );
		update_option( self::PURGE_MARKER_OPT, $today, false );
	}

	public static function purge( int $days = self::RETENTION_DAYS ): int {
		$days = max( 1, $days );
		$dir  = self::get_base_log_dir( false );
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return 0;
		}

		// [2026-07-27 Johnny Chu] PHASE-0.49-KG-PROGRESS-FILELOG — compare
		// validated UTC date names lexically to avoid server-timezone drift.
		$threshold_date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = 0;
		$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
		if ( ! is_array( $files ) ) {
			return 0;
		}

		foreach ( $files as $file ) {
			$date = basename( $file, '.jsonl' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $date >= $threshold_date ) {
				continue;
			}
			if ( @unlink( $file ) ) {
				$deleted++;
			}
		}

		return $deleted;
	}

	private static function legacy_table_exists( string $table ): bool {
		global $wpdb;
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			)
		);
		if ( null === $exists && ! empty( $wpdb->last_error ) ) {
			return false;
		}
		return (bool) $exists;
	}

	private static function normalise_legacy_row( array $row, int $blog_id ): array {
		$payload = null;
		if ( isset( $row['payload'] ) && (string) $row['payload'] !== '' ) {
			$decoded = json_decode( (string) $row['payload'], true );
			$payload = is_array( $decoded ) ? $decoded : array( 'legacy_payload' => (string) $row['payload'] );
		}
		$created_at = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created_at ) ) {
			$created_at = gmdate( 'Y-m-d H:i:s' );
		}
		return array(
			'id'           => isset( $row['id'] ) ? (int) $row['id'] : self::next_event_id(),
			'blog_id'      => $blog_id,
			'notebook_id'  => isset( $row['notebook_id'] ) ? (int) $row['notebook_id'] : 0,
			'source_id'    => isset( $row['source_id'] ) ? (int) $row['source_id'] : 0,
			'passage_id'   => isset( $row['passage_id'] ) ? (int) $row['passage_id'] : 0,
			'event'        => sanitize_key( substr( (string) ( $row['event'] ?? 'log' ), 0, 40 ) ) ?: 'log',
			'triggered_by' => sanitize_key( substr( (string) ( $row['triggered_by'] ?? 'system' ), 0, 40 ) ) ?: 'system',
			'counts_total' => isset( $row['counts_total'] ) ? (int) $row['counts_total'] : null,
			'counts_done'  => isset( $row['counts_done'] ) ? (int) $row['counts_done'] : null,
			'counts_error' => isset( $row['counts_error'] ) ? (int) $row['counts_error'] : null,
			'payload'      => $payload,
			'created_at'   => $created_at,
		);
	}

	private static function next_event_id(): int {
		$next = (int) round( microtime( true ) * 1000000 );
		if ( $next <= self::$last_event_id ) {
			$next = self::$last_event_id + 1;
		}
		self::$last_event_id = $next;
		return $next;
	}

	private static function append_line( array $entry ): bool {
		$dir = self::get_base_log_dir( true );
		if ( $dir === '' ) {
			return false;
		}

		$date = isset( $entry['created_at'] ) ? substr( (string) $entry['created_at'], 0, 10 ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = gmdate( 'Y-m-d' );
		}

		$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $date . '.jsonl';
		$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $line ) || $line === '' ) {
			return false;
		}

		return false !== @file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * @return string[]
	 */
	private static function list_dates( int $max = 90 ): array {
		$dir = self::get_base_log_dir( false );
		if ( $dir === '' || ! is_dir( $dir ) ) {
			return array();
		}

		$files = glob( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . '*.jsonl' );
		if ( ! is_array( $files ) ) {
			return array();
		}

		$dates = array();
		foreach ( $files as $file ) {
			$dates[] = basename( $file, '.jsonl' );
		}

		rsort( $dates, SORT_STRING );
		return array_slice( $dates, 0, max( 1, $max ) );
	}

	private static function get_base_log_dir( bool $ensure ): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$cache_k = (string) $blog_id;
		if ( isset( self::$dir_cache[ $cache_k ] ) ) {
			return self::$dir_cache[ $cache_k ];
		}

		$upload = wp_upload_dir();
		$base   = isset( $upload['basedir'] ) ? (string) $upload['basedir'] : '';
		if ( $base === '' ) {
			return '';
		}

		$dir = $base . DIRECTORY_SEPARATOR . self::BASE_FOLDER . DIRECTORY_SEPARATOR . self::SUB_FOLDER;
		if ( $ensure && ! is_dir( $dir ) ) {
			@wp_mkdir_p( $dir );
		}

		if ( is_dir( $dir ) ) {
			$htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
			if ( ! file_exists( $htaccess ) ) {
				@file_put_contents( $htaccess, "Deny from all\nOptions -Indexes\n" );
			}
			$index = $dir . DIRECTORY_SEPARATOR . 'index.php';
			if ( ! file_exists( $index ) ) {
				@file_put_contents( $index, "<?php // Silence is golden.\n" );
			}
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return '';
		}

		self::$dir_cache[ $cache_k ] = $dir;
		return $dir;
	}
}
