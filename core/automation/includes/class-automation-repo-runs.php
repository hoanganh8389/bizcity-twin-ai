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
	const LOG_RETENTION_DAYS = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep automation step logs for one week.
	const LOG_RETENTION_BATCH = 500;

	const STATUS_QUEUED    = 0;
	const STATUS_RUNNING   = 1;
	const STATUS_OK        = 2;
	const STATUS_FAIL      = 3;
	const STATUS_CANCELLED = 4;

	private static $runs_user_id_column_exists = null;

	public static function table_runs(): string { return BizCity_Automation_Installer::table( self::TABLE_RUNS ); }
	public static function table_logs(): string { return BizCity_Automation_Installer::table( self::TABLE_LOGS ); }

	/**
	 * Enqueue a new run for a workflow.
	 *
	 * @param int                 $workflow_id
	 * @param array|string|null   $payload         Trigger payload (raw or pre-encoded JSON).
	 * @param string              $parent_run_id   PG-S6: link replay child → parent. Empty = top-level run.
	 * @param array               $extra           Optional metadata: user_id, contact_id, conversation_id.
	 * @return string|WP_Error  The generated run_id on success.
	 */
	public static function enqueue( int $workflow_id, $payload = null, string $parent_run_id = '', array $extra = array() ) {
		global $wpdb;
		BizCity_Automation_Installer::ensure();

		$run_id = 'run_' . wp_generate_password( 12, false, false );
		// [2026-08-01 Johnny Chu] PHASE-1.26-CORRELATION — persist correlation
		// metadata inside the DB queue payload so cron/async workers keep the
		// inbound trace and causal parent after the request ends.
		if ( class_exists( 'BizCity_Chat_Correlation' ) ) {
			if ( is_string( $payload ) && $payload !== '' ) {
				$decoded_payload = json_decode( $payload, true );
				if ( is_array( $decoded_payload ) ) {
					$decoded_payload['correlation'] = BizCity_Chat_Correlation::export_async( $decoded_payload, 'automation_run' );
					$payload = wp_json_encode( $decoded_payload );
				}
			} elseif ( is_array( $payload ) ) {
				$payload['correlation'] = BizCity_Chat_Correlation::export_async( $payload, 'automation_run' );
			}
		}
		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — derive canonical owner from payload/extra/workflow fallback.
		$owner_user_id = 0;
		if ( is_array( $payload ) ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — linked channel user owns run before workflow creator fallback.
			$owner_user_id = (int) ( $payload['wp_user_id'] ?? $payload['_owner_user_id'] ?? 0 );
		} elseif ( is_string( $payload ) && $payload !== '' ) {
			$decoded = json_decode( $payload, true );
			if ( is_array( $decoded ) ) {
				$owner_user_id = (int) ( $decoded['wp_user_id'] ?? $decoded['_owner_user_id'] ?? 0 );
			}
		}
		if ( $owner_user_id <= 0 ) {
			$owner_user_id = (int) ( $extra['user_id'] ?? 0 );
		}
		if ( $owner_user_id <= 0 && class_exists( 'BizCity_Automation_Repo_Workflows' ) ) {
			$wf = BizCity_Automation_Repo_Workflows::find( $workflow_id );
			$owner_user_id = is_array( $wf ) ? (int) ( $wf['created_by'] ?? 0 ) : 0;
		}

		// [2026-06-15 Johnny Chu] R-UNIFY Wave 5 — accept contact_id / conversation_id from caller.
		$insert = array(
			'workflow_id'          => $workflow_id,
			'run_id'               => $run_id,
			'status'               => self::STATUS_QUEUED,
			'trigger_payload_json' => $payload === null ? null : ( is_string( $payload ) ? $payload : wp_json_encode( $payload ) ),
			'parent_run_id'        => substr( $parent_run_id, 0, 32 ),
			'created_at'           => current_time( 'mysql' ),
		);
		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — persist canonical owner into runs.user_id when schema is ready.
		if ( $owner_user_id > 0 && self::runs_has_user_id_column() ) {
			$insert['user_id'] = $owner_user_id;
		}
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
		// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — optional owner filter for run listings.
		if ( ! empty( $args['user_id'] ) && self::runs_has_user_id_column() ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $args['user_id'];
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

	public static function log_by_id( string $run_id, int $log_id ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-NOTICE — fetch one canonical node log for progress projection instead of rescanning a run on every hook.
		global $wpdb;
		if ( $run_id === '' || $log_id <= 0 ) {
			return array();
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_logs() . ' WHERE run_id = %s AND id = %d LIMIT 1',
				$run_id,
				$log_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? self::hydrate_log( $row ) : array();
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

	/** Purge completed automation step logs outside the seven-day window. */
	public static function gc_logs(): int {
		global $wpdb;
		$table = self::table_logs();
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
			return 0;
		}
		$deleted = $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table}
			 WHERE COALESCE( ended_at, started_at ) < ( CURRENT_TIMESTAMP - INTERVAL %d DAY )
			 ORDER BY id ASC LIMIT %d",
			self::LOG_RETENTION_DAYS,
			self::LOG_RETENTION_BATCH
		) );
		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — find a recent run that already claimed
	 * the same inbound trigger payload (matcher run vs FE test-listen run race).
	 *
	 * @param int   $workflow_id Workflow id.
	 * @param array $payload     Incoming trigger payload.
	 * @param int   $window_seconds Search window in seconds.
	 * @return array|null {run, reason} or null when no duplicate found.
	 */
	public static function find_recent_duplicate_capture_run( int $workflow_id, array $payload, int $window_seconds = 45 ) {
		global $wpdb;
		if ( $workflow_id <= 0 ) { return null; }

		$window_seconds = max( 5, min( 600, $window_seconds ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table_runs() . ' WHERE workflow_id = %d AND status IN (0,1,2,3) ORDER BY id DESC LIMIT 80',
				$workflow_id
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) { return null; }

		$incoming = self::capture_identity_from_payload( $payload );
		foreach ( $rows as $row ) {
			$run = self::hydrate( (array) $row );
			$created_at_ts = ! empty( $run['created_at'] ) ? strtotime( (string) $run['created_at'] ) : 0;
			if ( $created_at_ts > 0 && ( time() - $created_at_ts ) > $window_seconds ) {
				continue;
			}
			$trigger = is_array( $run['trigger_payload'] ?? null ) ? (array) $run['trigger_payload'] : array();
			if ( empty( $trigger ) ) { continue; }
			$existing = self::capture_identity_from_payload( $trigger );

			// [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — strongest identity: listener_event_id.
			if ( $incoming['listener_event_id'] > 0
				&& $incoming['listener_event_id'] === $existing['listener_event_id'] ) {
				return array( 'run' => $run, 'reason' => 'listener_event_id' );
			}

			// [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — provider message id if listener_event_id missing.
			if ( $incoming['message_id'] !== ''
				&& $incoming['message_id'] === $existing['message_id']
				&& $incoming['platform'] !== ''
				&& $incoming['platform'] === $existing['platform'] ) {
				return array( 'run' => $run, 'reason' => 'message_id' );
			}

			// [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — last-resort chat+message signature within tiny window.
			if ( $incoming['signature'] !== ''
				&& $incoming['signature'] === $existing['signature']
				&& $incoming['chat_id'] !== ''
				&& $incoming['chat_id'] === $existing['chat_id'] ) {
				return array( 'run' => $run, 'reason' => 'signature' );
			}
		}

		return null;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — normalize payload into compare-safe identity fields.
	 */
	private static function capture_identity_from_payload( array $payload ): array {
		$platform = strtoupper( trim( (string) ( $payload['platform'] ?? '' ) ) );
		$account  = trim( (string) ( $payload['account_id'] ?? $payload['instance_id'] ?? '' ) );
		$chat_id  = trim( (string) ( $payload['conversation_chat_id'] ?? $payload['chat_id'] ?? '' ) );
		$message  = self::normalize_capture_text( (string) ( $payload['message_text_clean'] ?? $payload['message'] ?? $payload['text'] ?? '' ) );
		$meta     = is_array( $payload['meta'] ?? null ) ? (array) $payload['meta'] : array();
		$message_id = trim( (string) ( $payload['mid'] ?? $payload['message_id'] ?? $meta['message_id'] ?? '' ) );
		$listener_event_id = (int) ( $payload['listener_event_id'] ?? 0 );

		$signature = '';
		if ( $platform !== '' || $account !== '' || $chat_id !== '' || $message_id !== '' || $message !== '' ) {
			$signature = sha1( implode( '|', array( $platform, $account, $chat_id, $message_id, $message ) ) );
		}

		return array(
			'platform'          => $platform,
			'account_id'        => $account,
			'chat_id'           => $chat_id,
			'message_id'        => $message_id,
			'listener_event_id' => $listener_event_id,
			'signature'         => $signature,
		);
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-LISTEN-DEDUP — lowercase/trim for signature comparison.
	 */
	private static function normalize_capture_text( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		if ( $text === '' ) { return ''; }
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}
		return strtolower( $text );
	}

	// [2026-07-17 Johnny Chu] PHASE-TWINWEB F4 — information_schema column guard for mixed-version deployments.
	private static function runs_has_user_id_column(): bool {
		if ( self::$runs_user_id_column_exists !== null ) {
			return (bool) self::$runs_user_id_column_exists;
		}
		global $wpdb;
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			self::table_runs(),
			'user_id'
		) );
		self::$runs_user_id_column_exists = ( $exists === 1 );
		return (bool) self::$runs_user_id_column_exists;
	}

	public static function hydrate( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['workflow_id']   = (int) $row['workflow_id'];
		$row['user_id']       = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
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
