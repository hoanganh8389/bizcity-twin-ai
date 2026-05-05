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
		global $wpdb;

		$memory_id = absint( $memory_id );
		$limit     = absint( $limit );
		$offset    = absint( $offset );

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table}
			 WHERE memory_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d OFFSET %d",
			$memory_id,
			$limit,
			$offset
		);

		$rows = $wpdb->get_results( $query );
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$row ) {
				$row->detail_json = json_decode( $row->detail_json, true );
			}
			unset( $row );
		}

		return is_array( $rows ) ? $rows : array();
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
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$this->table} WHERE memory_id = %d",
			absint( $memory_id )
		) );
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
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table}
			 WHERE memory_id = %d AND action = %s
			 ORDER BY created_at DESC
			 LIMIT %d",
			absint( $memory_id ),
			sanitize_text_field( $action ),
			absint( $limit )
		);

		$rows = $wpdb->get_results( $query );
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$row ) {
				$row->detail_json = json_decode( $row->detail_json, true );
			}
			unset( $row );
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get step-by-step trail for a memory spec (pipeline audit).
	 *
	 * @param int $memory_id Memory spec ID.
	 * @param int $limit     Max entries.
	 * @return array Logs with non-empty step_name, ordered by created_at ASC.
	 */
	public function get_step_trail( $memory_id, $limit = 100 ) {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT * FROM {$this->table}
			 WHERE memory_id = %d AND step_name != ''
			 ORDER BY created_at ASC
			 LIMIT %d",
			absint( $memory_id ),
			absint( $limit )
		);

		$rows = $wpdb->get_results( $query );
		if ( is_array( $rows ) ) {
			foreach ( $rows as &$row ) {
				$row->detail_json = json_decode( $row->detail_json, true );
			}
			unset( $row );
		}

		return is_array( $rows ) ? $rows : array();
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
	public function purge_old( $days = 90 ) {
		global $wpdb;

		$days = max( 1, absint( $days ) );

		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );

		return is_int( $deleted ) ? $deleted : 0;
	}
}
