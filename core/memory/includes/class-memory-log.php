<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory Log — Append-only Audit Trail
 *
 * Phase 1.15: Record every change to memory specs in `bizcity_memory_logs`.
 * Supports traceability, conflict detection, rollback, and user audit.
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Log' ) ) {
	return;
}

class BizCity_Memory_Log {

	/** @var string */
	private static $LOG = '[MemoryLog]';

	/** @var BizCity_Memory_Log|null */
	private static $instance = null;

	/** @var string Table name (set in constructor). */
	private $table;

	/**
	 * Singleton accessor.
	 *
	 * @return BizCity_Memory_Log
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_memory_logs';
	}

	/* ================================================================
	 *  Record
	 * ================================================================ */

	/**
	 * Record an audit log entry.
	 *
	 * @param int    $memory_id   The memory spec ID.
	 * @param string $action      Action name: created|updated|section_patched|archived|restored|deleted.
	 * @param string $step_name   Pipeline step name (e.g. "planner", "executor", "reflector").
	 * @param array  $details     Additional details (JSON serializable).
	 * @param int    $user_id     Optional user ID — defaults to current user.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function record( $memory_id, $action, $step_name = '', $details = array(), $user_id = 0 ) {
		if ( empty( $memory_id ) || empty( $action ) ) {
			return false;
		}

		$user_id = $user_id > 0 ? $user_id : get_current_user_id();

		// R-EVT-1 (Sprint 5.0+ housekeeping): NEVER write directly to
		// bizcity_memory_logs. Dispatch a 'memory_mutation' event onto the
		// single backbone (bizcity_twin_event_stream); the projector
		// BizCity_Memory_Log_Projector materializes the legacy table for
		// read consumers (get_logs / get_step_trail / etc.).
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			error_log( self::$LOG . ' Event_Bus unavailable; memory_mutation dropped (memory_id=' . absint( $memory_id ) . ')' );
			return false;
		}

		try {
			BizCity_Twin_Event_Bus::dispatch_v2(
				'memory_mutation',
				array(
					'memory_id' => absint( $memory_id ),
					'operation' => sanitize_text_field( (string) $action ),
					'step_name' => sanitize_text_field( (string) $step_name ),
					'user_id'   => absint( $user_id ),
					'details'   => is_array( $details ) ? $details : array(),
				),
				array( 'event_source' => 'memory' )
			);
			return true;
		} catch ( \Exception $e ) {
			error_log( self::$LOG . ' dispatch failed: ' . $e->getMessage() );
			return false;
		}
	}

	/* ================================================================
	 *  Query
	 * ================================================================ */

	/**
	 * Get logs for a specific memory spec.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @param int $limit     Max entries to return (default 50).
	 * @param int $offset    Offset for pagination.
	 * @return array Array of log objects ordered by created_at DESC.
	 */
	public function get_logs( $memory_id, $limit = 50, $offset = 0 ) {
		$memory_id = absint( $memory_id );
		$limit     = absint( $limit );
		$offset    = absint( $offset );
		if ( $memory_id <= 0 || $limit <= 0 ) {
			return array();
		}

		// [2026-08-01 Johnny Chu] PHASE-1.24-MEMORY-READER — read the canonical
		// JSONL projection first, then merge frozen/legacy SQL rows during migration.
		$rows = $this->read_merged_logs( $memory_id, max( 200, $limit + $offset ) );
		return array_slice( $rows, $offset, $limit );
	}

	/**
	 * Get latest log entry for a memory spec.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @return object|null
	 */
	public function get_latest( $memory_id ) {
		$logs = $this->get_logs( absint( $memory_id ), 1, 0 );
		return ! empty( $logs ) ? $logs[0] : null;
	}

	/**
	 * Count log entries for a memory spec.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @return int
	 */
	public function count_logs( $memory_id ) {
		$memory_id = absint( $memory_id );
		if ( $memory_id <= 0 ) {
			return 0;
		}
		// [2026-08-01 Johnny Chu] PHASE-1.24-MEMORY-READER — count merged rows so
		// the API remains correct after SQL projection writes are disabled.
		return count( $this->read_merged_logs( $memory_id, 10000 ) );
	}

	/**
	 * Get logs filtered by action type.
	 *
	 * @param int    $memory_id Memory spec ID.
	 * @param string $action    Action type to filter.
	 * @param int    $limit     Max entries.
	 * @return array
	 */
	public function get_logs_by_action( $memory_id, $action, $limit = 20 ) {
		$action = sanitize_text_field( $action );
		$limit  = absint( $limit );
		if ( $action === '' || $limit <= 0 ) {
			return array();
		}
		$rows = array_filter( $this->read_merged_logs( absint( $memory_id ), 10000 ), static function ( $row ) use ( $action ) {
			return is_object( $row ) && (string) ( $row->action ?? '' ) === $action;
		} );
		return array_slice( array_values( $rows ), 0, $limit );
	}

	const RETENTION_HOOK  = 'bizcity_memory_logs_retention';
	const RETENTION_DAYS  = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep memory audit projection for one week.
	const RETENTION_BATCH = 500;

	/**
	 * [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — register bounded cleanup for the
	 * memory_mutation projection table (BizCity_Memory_Log_Projector materializes it from
	 * the event bus; this class never writes rows directly, but the projection still needs
	 * a bounded window since it has an active reader via the memory REST API).
	 */
	public static function register_retention_cron(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => 'core.memory.logs_retention',
			'hook'        => self::RETENTION_HOOK,
			'interval'    => 'daily',
			'owner'       => 'core/memory',
			'description' => 'Bounded retention sweep for the memory mutation audit projection.',
			'retention'   => self::RETENTION_DAYS,
		) );
	}

	/**
	 * [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — delete old rows only from the scheduled cron context.
	 */
	public static function gc_logs(): void {
		// [2026-08-27 Johnny Chu] PHASE-1.30-JSONL-ONLY — retired memory SQL retention is disabled; JSONL/Event Stream owns the audit lifecycle.
		$deleted = 0;
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'memory_logs_retention_deleted' => $deleted ) ) );
			$cron->note_event( 'memory_logs_retention', array( 'deleted' => $deleted, 'retention_days' => self::RETENTION_DAYS ) );
		}
	}

	/**
	 * Get step-by-step trail for a memory spec (pipeline audit).
	 *
	 * @param int $memory_id Memory spec ID.
	 * @param int $limit     Max entries.
	 * @return array Logs with non-empty step_name, ordered by created_at ASC.
	 */
	public function get_step_trail( $memory_id, $limit = 100 ) {
		$limit = absint( $limit );
		if ( $limit <= 0 ) {
			return array();
		}
		$rows = array_filter( $this->read_merged_logs( absint( $memory_id ), 10000 ), static function ( $row ) {
			return is_object( $row ) && (string) ( $row->step_name ?? '' ) !== '';
		} );
		usort( $rows, static function ( $left, $right ) {
			return strcmp( (string) ( $left->created_at ?? '' ), (string) ( $right->created_at ?? '' ) );
		} );
		return array_slice( array_values( $rows ), 0, $limit );
	}

	/**
	 * Read and merge JSONL rows with the legacy SQL projection.
	 *
	 * @param int $memory_id Memory spec ID.
	 * @param int $limit     Maximum rows to inspect from each source.
	 * @return array<int,object>
	 */
	private function read_merged_logs( $memory_id, $limit = 10000 ) {
		$memory_id = absint( $memory_id );
		$limit     = max( 1, min( 10000, absint( $limit ) ) );
		$rows      = array();

		if ( class_exists( 'BizCity_JSONL_File_Logger' ) && method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) ) {
			$json_rows = BizCity_JSONL_File_Logger::query_contract(
				'core.memory.mutation_audit',
				array(
					'days'   => self::RETENTION_DAYS,
					'limit'  => $limit,
					'filter' => static function ( $row ) use ( $memory_id ) {
						$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
						return (int) ( $ctx['memory_id'] ?? 0 ) === $memory_id;
					},
				)
			);
			foreach ( (array) $json_rows as $row ) {
				$ctx     = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				$details = is_array( $ctx['details'] ?? null ) ? $ctx['details'] : array();
				$event_uuid = (string) ( $details['_event_uuid'] ?? '' );
				$rows[ $event_uuid !== '' ? 'event:' . $event_uuid : 'json:' . md5( wp_json_encode( $row ) ) ] = (object) array(
					'id'          => 0,
					'memory_id'   => $memory_id,
					'action'      => (string) ( $ctx['action'] ?? $row['event'] ?? '' ),
					'step_name'   => (string) ( $ctx['step_name'] ?? '' ),
					'user_id'     => (int) ( $ctx['user_id'] ?? 0 ),
					'detail_json' => $details,
					'created_at'  => (string) ( $row['ts'] ?? '' ),
					'event_uuid'  => $event_uuid,
				);
			}
		}

		$rows = array_values( $rows );
		usort( $rows, static function ( $left, $right ) {
			return strcmp( (string) ( $right->created_at ?? '' ), (string) ( $left->created_at ?? '' ) );
		} );
		return $rows;
	}

	/* ================================================================
	 *  Cleanup
	 * ================================================================ */

	/**
	 * Purge old logs (retention policy).
	 *
	 * @param int $days Delete logs older than this many days (default 90).
	 * @return int Number of deleted rows.
	 */
	public function purge_old( $days = 7 ) {
		global $wpdb;
		$days = max( 1, absint( $days ) );
		// [2026-08-26 Johnny Chu] PHASE-1.30-EXIT-RETURN — prevent legacy memory-log DELETE from reaching the database.
		// [2026-08-26 Johnny Chu] PHASE-1.30-FAIL-CLOSED — purge exits when policy is unavailable or legacy SQL is blocked.
		if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) || ! BizCity_Legacy_Table_Policy::allow_sql( $this->table, 'delete' ) ) {
			return 0;
		}
		if ( ! $wpdb || ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $this->table ) ) ) {
			return 0;
		}
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table} WHERE created_at < DATE_SUB( NOW(), INTERVAL %d DAY )", $days ) );
		return false === $result ? 0 : (int) $result;
	}
}
