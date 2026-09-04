<?php
/**
 * BizCity_Cron_File_Logger
 *
 * JSONL-based cron run logger (PHASE-CRON-FILE-LOGGER 2026-06-14).
 *
 * Writes lightweight JSONL evidence files per-day, per-blog into:
 *   {wp_uploads}/bizcity-cron-logs/run-evidence/YYYY-MM-DD.jsonl
 *
 * Design goals:
 *  - Zero-DB reads on the hot write path; JSONL I/O is delegated to the shared helper.
 *  - Multisite-safe: wp_upload_dir() returns per-blog basedir automatically.
 *  - GC: keep 5 most-recent days, delete older files.
 *  - Stats: parse JSONL to produce daily summary (total/ok/error/avg_ms/p95).
 *
 * PHP 7.4 compatible (no union types, no str_contains, no nullsafe).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @since      CRON-FILE-LOGGER (2026-06-14)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Cron_File_Logger {

	const FOLDER_NAME    = 'bizcity_cron_logs';
	const JSONL_FOLDER   = 'bizcity-cron-logs';
	const JSONL_MODULE   = 'run-evidence';
	const KEEP_DAYS      = 5;
	const HTACCESS_BODY  = "Order Deny,Allow\nDeny from all\n";

	// [2026-06-14 Johnny Chu] GAP-5 — class-level static so reset_dir_cache() can clear it.
	private static $_dir_cache = null;

	/** Singleton bootstrap path — called once from bootstrap.php. */
	public static function init() {
		// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — hook into CronManager run lifecycle
		add_action( 'bizcity_cron_run_started', array( __CLASS__, 'on_run_started' ), 10, 3 );
		add_action( 'bizcity_cron_run_ended',   array( __CLASS__, 'on_run_ended' ),   10, 5 );
		// GC: piggy-back on the existing nightly GC hook
		add_action( BizCity_Cron_Manager::GC_HOOK, array( __CLASS__, 'gc_old_logs' ) );
	}

	// ─── Hook Handlers ───────────────────────────────────────────────────

	/**
	 * Write a "start" line when a cron run begins.
	 *
	 * @param int    $run_id DB run id.
	 * @param string $job_id Registered job identifier.
	 * @param array  $meta   Additional fields from CronManager (hook, owner, …).
	 */
	public static function on_run_started( int $run_id, string $job_id, array $meta ) {
		// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — write start line
		$entry = array(
			'type'   => 'start',
			'ts'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'run_id' => $run_id,
			'job_id' => $job_id,
			'hook'   => (string) ( $meta['hook']  ?? '' ),
			'owner'  => (string) ( $meta['owner'] ?? '' ),
		);
		self::append( $entry );
	}

	/**
	 * Write an "end" line when a cron run finishes.
	 *
	 * @param int        $run_id      DB run id.
	 * @param string     $job_id      Registered job identifier.
	 * @param string     $status      'ok' or 'error'.
	 * @param int|null   $duration_ms Duration in milliseconds.
	 * @param string     $error       Error message (empty on success).
	 */
	public static function on_run_ended( int $run_id, string $job_id, string $status, $duration_ms, string $error ) {
		// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — write end line
		$entry = array(
			'type'        => 'end',
			'ts'          => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'run_id'      => $run_id,
			'job_id'      => $job_id,
			'status'      => $status,
			'duration_ms' => is_null( $duration_ms ) ? null : (int) $duration_ms,
			'error'       => $error !== '' ? $error : null,
		);
		self::append( $entry );
	}
	/**
	 * Write a "trigger" line — called externally by automation/channel code
	 * to record what triggered this cron run.
	 *
	 * @param int    $run_id      DB run id (0 = use CronManager::current_run_id()).
	 * @param string $job_id      Job identifier.
	 * @param string $trigger     'cron'|'inbound'|'webhook'|'manual'.
	 * @param array  $extra       Extra context (platform, workflow_id, …).
	 */
	public static function log_trigger( int $run_id, string $job_id, string $trigger, array $extra = array() ) {
		// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — write trigger line
		$entry = array(
			'type'    => 'trigger',
			'ts'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'run_id'  => $run_id,
			'job_id'  => $job_id,
			'trigger' => $trigger,
		);
		if ( ! empty( $extra ) ) {
			$entry = array_merge( $entry, $extra );
		}
		self::append( $entry );
	}

	// ─── Core I/O ────────────────────────────────────────────────────────

	/**
	 * Append a single JSON entry to today's log file.
	 *
	 * @param array $entry Associative array — will be JSON-encoded.
	 */
	public static function append( array $entry ): bool {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return false;
		}
		$type = (string) ( $entry['type'] ?? 'event' );
		$level = ( $type === 'end' && (string) ( $entry['status'] ?? '' ) !== 'ok' ) ? 'error' : 'info';
		$message = (string) ( $entry['job_id'] ?? $type );
		return (bool) BizCity_JSONL_File_Logger::write_contract( 'core.cron.run_evidence', $level, 'cron_' . $type, $message, $entry );
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY —
	 * append merged run meta/evidence emitted by Cron_Manager::note()/note_event().
	 */
	public static function append_meta( int $run_id, string $job_id, array $meta ) {
		if ( $run_id <= 0 || $job_id === '' || empty( $meta ) ) {
			return;
		}
		self::append( array(
			'type'   => 'meta',
			'ts'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'run_id' => $run_id,
			'job_id' => $job_id,
			'meta'   => $meta,
		) );
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY — gate helper
	 * so Cron_Manager can disable SQL run-log writes only when file logger is writable.
	 */
	public static function is_ready(): bool {
		$dir = self::log_dir();
		if ( ! is_string( $dir ) || $dir === '' || ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}
		$probe = @tempnam( $dir, '.cron-write-' );
		if ( false === $probe ) {
			return false;
		}
		@unlink( $probe );
		return true;
	}

	// ─── Readers ─────────────────────────────────────────────────────────

	/**
	 * Read all entries from a given date's log file.
	 *
	 * @param string $date 'YYYY-MM-DD' or 'today'.
	 * @return array  Parsed entries (each is an associative array).
	 */
	public static function read_day( string $date = 'today' ) {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — read through the shared JSONL reader and preserve the legacy entry shape.
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return array();
		}
		$date = $date === 'today' ? gmdate( 'Y-m-d' ) : $date;
		$rows = BizCity_JSONL_File_Logger::read_contract( 'core.cron.run_evidence', $date, 5000 );
		$entries = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$entries[] = self::unwrap_entry( $row );
			}
		}
		return array_reverse( $entries );
	}

	/**
	 * Read the last N entries from a date's log file (efficient tail via fseek).
	 *
	 * @param string $date  'YYYY-MM-DD' or 'today'.
	 * @param int    $limit Max lines to return.
	 * @return array
	 */
	public static function tail( string $date = 'today', int $limit = 100 ) {
		return array_slice( self::read_day( $date ), - max( 1, $limit ) );
	}

	// ─── Statistics ───────────────────────────────────────────────────────

	/**
	 * Compute daily statistics by parsing the JSONL file.
	 *
	 * @param string $date 'YYYY-MM-DD' or 'today'.
	 * @return array {
	 *   date, total_runs, ok_count, error_count, avg_duration_ms, p95_duration_ms,
	 *   jobs: { job_id: { runs, ok, error, avg_ms } },
	 *   triggers: { type: count },
	 *   errors: [ { run_id, job_id, error, ts } ]
	 * }
	 */
	public static function stats( string $date = 'today' ) {
		// [2026-06-14 Johnny Chu] CRON-FILE-LOGGER — stats()
		$entries = self::read_day( $date );
		$actual_date  = $date === 'today' ? gmdate( 'Y-m-d' ) : $date;
		$total_runs   = 0;
		$ok_count     = 0;
		$error_count  = 0;
		$durations    = array();
		$jobs         = array();
		$triggers     = array();
		$errors       = array();

		foreach ( $entries as $e ) {
			$type   = (string) ( $e['type']   ?? '' );
			$job_id = (string) ( $e['job_id'] ?? '' );

			if ( $type === 'end' ) {
				$total_runs++;
				$status = (string) ( $e['status'] ?? 'unknown' );
				$ms     = isset( $e['duration_ms'] ) && $e['duration_ms'] !== null ? (int) $e['duration_ms'] : null;

				if ( $status === 'ok' ) {
					$ok_count++;
				} else {
					$error_count++;
					$errors[] = array(
						'run_id' => (int) ( $e['run_id'] ?? 0 ),
						'job_id' => $job_id,
						'error'  => (string) ( $e['error'] ?? '' ),
						'ts'     => (string) ( $e['ts']    ?? '' ),
					);
				}

				if ( $ms !== null ) {
					$durations[] = $ms;
				}

				if ( $job_id !== '' ) {
					if ( ! isset( $jobs[ $job_id ] ) ) {
						$jobs[ $job_id ] = array( 'runs' => 0, 'ok' => 0, 'error' => 0, 'durations' => array() );
					}
					$jobs[ $job_id ]['runs']++;
					if ( $status === 'ok' ) {
						$jobs[ $job_id ]['ok']++;
					} else {
						$jobs[ $job_id ]['error']++;
					}
					if ( $ms !== null ) {
						$jobs[ $job_id ]['durations'][] = $ms;
					}
				}
			} elseif ( $type === 'trigger' ) {
				$trig = (string) ( $e['trigger'] ?? 'unknown' );
				$triggers[ $trig ] = ( $triggers[ $trig ] ?? 0 ) + 1;
			}
		}

		// Compute averages + p95.
		$avg_ms = count( $durations ) ? (int) round( array_sum( $durations ) / count( $durations ) ) : 0;
		$p95_ms = 0;
		if ( count( $durations ) ) {
			sort( $durations );
			$idx    = (int) ceil( 0.95 * count( $durations ) ) - 1;
			$p95_ms = $durations[ max( 0, $idx ) ];
		}

		// Per-job summaries (clean up internal durations array).
		$jobs_out = array();
		foreach ( $jobs as $jid => $jdata ) {
			$jdurs = $jdata['durations'];
			$javg  = count( $jdurs ) ? (int) round( array_sum( $jdurs ) / count( $jdurs ) ) : 0;
			$jobs_out[ $jid ] = array(
				'runs'   => $jdata['runs'],
				'ok'     => $jdata['ok'],
				'error'  => $jdata['error'],
				'avg_ms' => $javg,
			);
		}

		return array(
			'date'             => $actual_date,
			'total_runs'       => $total_runs,
			'ok_count'         => $ok_count,
			'error_count'      => $error_count,
			'avg_duration_ms'  => $avg_ms,
			'p95_duration_ms'  => $p95_ms,
			'jobs'             => $jobs_out,
			'triggers'         => $triggers,
			'errors'           => $errors,
		);
	}

	/**
	 * Return a list of dates (YYYY-MM-DD strings) that have log files,
	 * sorted newest-first.
	 *
	 * @return string[]
	 */
	public static function available_dates() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return array();
		}
		return BizCity_JSONL_File_Logger::list_dates( self::JSONL_FOLDER, self::JSONL_MODULE, 365 );
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY —
	 * return normalized recent runs, newest-first, compatible with Cron_Manager recent_runs() shape.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent_runs( string $job_id = '', int $limit = 20 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$dates = self::available_dates();
		if ( empty( $dates ) ) {
			return array();
		}

		$runs_by_id = array();
		$order      = array();
		foreach ( $dates as $date ) {
			$entries = self::read_day( $date );
			if ( empty( $entries ) ) {
				continue;
			}
			for ( $i = count( $entries ) - 1; $i >= 0; $i-- ) {
				$entry = $entries[ $i ];
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$rid = isset( $entry['run_id'] ) ? (int) $entry['run_id'] : 0;
				if ( $rid <= 0 ) {
					continue;
				}
				$jid = (string) ( $entry['job_id'] ?? '' );
				if ( $jid === '' ) {
					continue;
				}
				if ( $job_id !== '' && $jid !== $job_id ) {
					continue;
				}

				$run_key = $rid . ':' . $jid;
				if ( ! isset( $runs_by_id[ $run_key ] ) ) {
					$runs_by_id[ $run_key ] = array(
						'id'          => $rid,
						'job_id'      => $jid,
						'started_at'  => '',
						'ended_at'    => '',
						'duration_ms' => null,
						'status'      => '',
						'error'       => '',
						'meta'        => '',
					);
				}

				$type = (string) ( $entry['type'] ?? '' );
				if ( $type === 'end' ) {
					if ( $runs_by_id[ $run_key ]['ended_at'] === '' ) {
						$runs_by_id[ $run_key ]['ended_at'] = self::iso_to_mysql_utc( (string) ( $entry['ts'] ?? '' ) );
					}
					$runs_by_id[ $run_key ]['status'] = (string) ( $entry['status'] ?? '' );
					$runs_by_id[ $run_key ]['duration_ms'] = isset( $entry['duration_ms'] ) && $entry['duration_ms'] !== null ? (int) $entry['duration_ms'] : null;
					$runs_by_id[ $run_key ]['error'] = (string) ( $entry['error'] ?? '' );
					if ( ! in_array( $run_key, $order, true ) ) {
						$order[] = $run_key;
					}
				} elseif ( $type === 'start' ) {
					if ( $runs_by_id[ $run_key ]['started_at'] === '' ) {
						$runs_by_id[ $run_key ]['started_at'] = self::iso_to_mysql_utc( (string) ( $entry['ts'] ?? '' ) );
					}
				} elseif ( $type === 'meta' && isset( $entry['meta'] ) && is_array( $entry['meta'] ) ) {
					$runs_by_id[ $run_key ]['meta'] = wp_json_encode( $entry['meta'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				}

			}
		}

		$out = array();
		foreach ( array_slice( $order, 0, $limit ) as $run_key ) {
			$out[] = $runs_by_id[ $run_key ];
		}
		return $out;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.50-CRON-FILELOG-PRIMARY —
	 * return last-run summary compatible with Cron_Manager::last_run().
	 *
	 * @return array{started_at_ts:int,status:string,duration_ms:?int,error:string}|array{}
	 */
	public static function last_run_summary( string $job_id ): array {
		$rows = self::recent_runs( $job_id, 1 );
		if ( empty( $rows ) ) {
			return array();
		}
		$row = $rows[0];
		$started = (string) ( $row['started_at'] ?? '' );
		$ts = strtotime( $started . ' UTC' );
		return array(
			'started_at_ts' => $ts ? (int) $ts : 0,
			'status'        => (string) ( $row['status'] ?? '' ),
			'duration_ms'   => isset( $row['duration_ms'] ) && $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
			'error'         => (string) ( $row['error'] ?? '' ),
		);
	}

	/**
	 * Build a per-job index of most recent runs in one pass.
	 *
	 * @return array<string,array{started_at_ts:int,status:string,duration_ms:?int,error:string}>
	 */
	public static function last_runs_index( int $scan_limit = 2000 ): array {
		$rows  = self::recent_runs( '', max( 1, min( 5000, $scan_limit ) ) );
		$index = array();
		foreach ( $rows as $row ) {
			$job_id = (string) ( $row['job_id'] ?? '' );
			if ( $job_id === '' || isset( $index[ $job_id ] ) ) {
				continue;
			}
			$started = (string) ( $row['started_at'] ?? '' );
			$ts = strtotime( $started . ' UTC' );
			$index[ $job_id ] = array(
				'started_at_ts' => $ts ? (int) $ts : 0,
				'status'        => (string) ( $row['status'] ?? '' ),
				'duration_ms'   => isset( $row['duration_ms'] ) && $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
				'error'         => (string) ( $row['error'] ?? '' ),
			);
		}
		return $index;
	}

	private static function iso_to_mysql_utc( string $iso ): string {
		if ( $iso === '' ) {
			return '';
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	// ─── GC ──────────────────────────────────────────────────────────────

	/**
	 * Delete JSONL files older than KEEP_DAYS.
	 * Called nightly via bizcity_cron_runs_gc hook.
	 */
	public static function gc_old_logs() {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — cron retention is owned by the shared JSONL CRUD class.
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return;
		}
		BizCity_JSONL_File_Logger::purge_older_than( self::JSONL_FOLDER, self::JSONL_MODULE, self::KEEP_DAYS );
	}

	// ─── Path Helpers ─────────────────────────────────────────────────────

	/**
	 * Get today's log file path, creating the directory + .htaccess if needed.
	 *
	 * @return string|false
	 */
	public static function today_path() {
		return self::path_for_date( 'today' );
	}

	/**
	 * Get log file path for a given date.
	 *
	 * @param string $date 'today' | 'YYYY-MM-DD'
	 * @return string|false
	 */
	public static function path_for_date( string $date ) {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return false;
		}

		if ( $date === 'today' ) {
			$date = gmdate( 'Y-m-d' );
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return false;
		}

		$location = BizCity_JSONL_File_Logger::location( self::JSONL_FOLDER, self::JSONL_MODULE, $date );
		return ! empty( $location['file'] ) ? $location['file'] : false;
	}

	/**
	 * Get (and create if needed) the log directory.
	 *
	 * @return string|false  Absolute directory path, or false on failure.
	 */
	public static function log_dir() {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return false;
		}
		$location = BizCity_JSONL_File_Logger::location( self::JSONL_FOLDER, self::JSONL_MODULE );
		return ! empty( $location['directory'] ) ? $location['directory'] : false;
	}

	/**
	 * Invalidate the log_dir static cache. Called in unit tests / on blog switch.
	 */
	public static function reset_dir_cache() {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — retained as a compatibility no-op after path ownership moved to the shared logger.
		self::$_dir_cache = null;
	}

	private static function unwrap_entry( array $row ): array {
		$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
		return ! empty( $ctx ) ? array_merge( $ctx, array( 'ts' => (string) ( $row['ts'] ?? '' ) ) ) : $row;
	}
}
