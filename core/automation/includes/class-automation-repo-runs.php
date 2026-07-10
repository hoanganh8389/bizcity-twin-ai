<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @license    GPL-2.0-or-later
 *
 * BizCity_Automation_Repo_Runs — `bizcity_automation_runs` + `*_logs` (BE-1).
 *
 * Provides minimal life-cycle methods used by the manual-run REST endpoint
 * and by the runner (BE-3). Status codes:
 *   0=queued · 1=running · 2=ok · 3=fail · 4=cancelled
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Repo_Runs {

	const TABLE_RUNS = 'bizcity_automation_runs';
	const TABLE_LOGS = 'bizcity_automation_logs';

	const STATUS_QUEUED    = 0;
	const STATUS_RUNNING   = 1;
	const STATUS_OK        = 2;
	const STATUS_FAIL      = 3;
	const STATUS_CANCELLED = 4;

	public static function table_runs(): string { return BizCity_Automation_Installer::table( self::TABLE_RUNS ); }
	public static function table_logs(): string { return BizCity_Automation_Installer::table( self::TABLE_LOGS ); }

	/**
	 * Enqueue a new run for a workflow.
	 *
	 * @param int                 $workflow_id
	 * @param array|string|null   $payload         Trigger payload (raw or pre-encoded JSON).
	 * @param string              $parent_run_id   PG-S6: link replay child → parent. Empty = top-level run.
	 * @param array               $extra           R-UNIFY Wave 5: optional contact_id / conversation_id for CRM-originated runs.
	 * @return string|WP_Error  The generated run_id on success.
	 */
	public static function enqueue( int $workflow_id, $payload = null, string $parent_run_id = '', array $extra = array() ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$run_id = 'run_' . wp_generate_password( 12, false, false );

		// [2026-06-15 Johnny Chu] R-UNIFY Wave 5 — accept contact_id / conversation_id from caller.
		$insert = array(
			'workflow_id'          => $workflow_id,
			'run_id'               => $run_id,
			'status'               => self::STATUS_QUEUED,
			'trigger_payload_json' => $payload === null ? null : ( is_string( $payload ) ? $payload : wp_json_encode( $payload ) ),
			'parent_run_id'        => substr( $parent_run_id, 0, 32 ),
			'created_at'           => current_time( 'mysql' ),
		);
		if ( ! empty( $extra['contact_id'] ) ) {
			$insert['contact_id'] = (int) $extra['contact_id'];
		}
		if ( ! empty( $extra['conversation_id'] ) ) {
			$insert['conversation_id'] = (int) $extra['conversation_id'];
		}

		$ok = $wpdb->insert( self::table_runs(), $insert );
		if ( $ok === false ) {
			return new WP_Error( 'db_insert_failed', $wpdb->last_error ?: 'enqueue failed', array( 'status' => 500 ) );
		}
		return $run_id;
	}

	public static function find( string $run_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table_runs() . ' WHERE run_id = %s', $run_id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	public static function query( array $args = array() ): array {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['workflow_id'] ) ) {
			$where[]  = 'workflow_id = %d';
			$params[] = (int) $args['workflow_id'];
		}
		if ( isset( $args['status'] ) && $args['status'] !== '' && $args['status'] !== null ) {
			$where[]  = 'status = %d';
			$params[] = (int) $args['status'];
		}
		$limit  = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$sql_where = implode( ' AND ', $where );
		$sql = "SELECT * FROM " . self::table_runs() . " WHERE {$sql_where} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";
		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, ...$params ) : $sql, ARRAY_A );
		$rows = array_map( array( __CLASS__, 'hydrate' ), $rows ?: array() );

		$total_sql = "SELECT COUNT(*) FROM " . self::table_runs() . " WHERE {$sql_where}";
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, ...$params ) : $total_sql );

		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function cancel( string $run_id ): bool {
		global $wpdb;
		return $wpdb->update(
			self::table_runs(),
			array( 'status' => self::STATUS_CANCELLED, 'ended_at' => current_time( 'mysql' ) ),
			array( 'run_id' => $run_id, 'status' => self::STATUS_QUEUED ),
			array( '%d', '%s' ),
			array( '%s', '%d' )
		) > 0;
	}

	public static function set_status( string $run_id, int $status, array $extra = array() ): bool {
		global $wpdb;
		$data = array_merge( array( 'status' => $status ), $extra );
		return $wpdb->update( self::table_runs(), $data, array( 'run_id' => $run_id ) ) !== false;
	}

	/**
	 * [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — atomic CAS: QUEUED → RUNNING.
	 * Returns true only if THIS caller successfully claimed the run.
	 * Prevents double-execution when both on_cron_dispatch and bizcity_automation_run_async
	 * race to execute the same QUEUED run in concurrent WP-Cron processes.
	 *
	 * @return bool true = claimed (caller may proceed); false = another process got there first.
	 */
	public static function try_claim_running( string $run_id, array $extra = array() ): bool {
		global $wpdb;
		$data = array_merge(
			array( 'status' => self::STATUS_RUNNING ),
			$extra
		);
		$affected = $wpdb->update(
			self::table_runs(),
			$data,
			array( 'run_id' => $run_id, 'status' => self::STATUS_QUEUED )
		);
		return $affected > 0;
	}

	/**
	 * Update only the `debug_state` column (PG-S5).
	 *
	 * Values: '' | 'pausing' | 'stepping' | 'paused_before:<node_id>'.
	 * Does NOT touch status/ended_at — callers may flip status separately.
	 */
	public static function set_debug_state( string $run_id, string $state ): bool {
		global $wpdb;
		return $wpdb->update(
			self::table_runs(),
			array( 'debug_state' => substr( $state, 0, 64 ) ),
			array( 'run_id' => $run_id ),
			array( '%s' ),
			array( '%s' )
		) !== false;
	}

	public static function logs( string $run_id, int $since_id = 0 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_logs() . ' WHERE run_id = %s AND id > %d ORDER BY id ASC',
				$run_id,
				$since_id
			),
			ARRAY_A
		) ?: array();
		return array_map( array( __CLASS__, 'hydrate_log' ), $rows );
	}

	public static function append_log( array $row ): int {
		global $wpdb;
		$row = array_merge(
			array(
				'run_id'      => '',
				'node_id'     => '',
				'block_id'    => '',
				'step'        => 0,
				'status'      => self::STATUS_QUEUED,
				'started_at'  => current_time( 'mysql' ),
			),
			$row
		);
		$wpdb->insert( self::table_logs(), $row );
		return (int) $wpdb->insert_id;
	}

	/** Update an existing log row (used by runner to mark ok/fail). */
	public static function append_log_update( int $log_id, array $patch ): bool {
		global $wpdb;
		if ( $log_id <= 0 ) { return false; }
		return $wpdb->update( self::table_logs(), $patch, array( 'id' => $log_id ) ) !== false;
	}

	public static function hydrate( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['workflow_id']   = (int) $row['workflow_id'];
		$row['status']        = (int) $row['status'];
		$row['tokens_used']   = (int) $row['tokens_used'];
		$row['crm_event_id']  = (int) $row['crm_event_id'];
		$row['debug_state']   = isset( $row['debug_state'] ) ? (string) $row['debug_state'] : '';
		$row['parent_run_id'] = isset( $row['parent_run_id'] ) ? (string) $row['parent_run_id'] : '';
		// [2026-06-15 Johnny Chu] R-UNIFY Wave 5 — cast new FK columns (default 0 before migration).
		$row['contact_id']      = isset( $row['contact_id'] )      ? (int) $row['contact_id']      : 0;
		$row['conversation_id'] = isset( $row['conversation_id'] ) ? (int) $row['conversation_id'] : 0;
		$row['trigger_payload'] = isset( $row['trigger_payload_json'] ) && $row['trigger_payload_json'] !== null && $row['trigger_payload_json'] !== ''
			? json_decode( $row['trigger_payload_json'], true ) : null;
		$row['result']        = isset( $row['result_json'] ) && $row['result_json'] !== null && $row['result_json'] !== ''
			? json_decode( $row['result_json'], true ) : null;
		return $row;
	}

	public static function hydrate_log( array $row ): array {
		$row['id']     = (int) $row['id'];
		$row['step']   = (int) $row['step'];
		$row['status'] = (int) $row['status'];
		$row['input']  = isset( $row['input_json'] )  && $row['input_json']  !== '' && $row['input_json']  !== null ? json_decode( $row['input_json'],  true ) : null;
		$row['output'] = isset( $row['output_json'] ) && $row['output_json'] !== '' && $row['output_json'] !== null ? json_decode( $row['output_json'], true ) : null;
		return $row;
	}
}
