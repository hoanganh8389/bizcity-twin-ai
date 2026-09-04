<?php
/**
 * Per-workflow JSONL file logger.
 *
 * Subscribes to `bizcity_automation_log_appended` during the compatibility
 * window and writes the canonical workflow trace JSONL source. Path is:
 *   {wp_uploads}/bizcity-automation-logs/workflow-trace/YYYY-MM-DD.jsonl
 *
 * Purpose: debug workflows that don't visibly fire (no run created) or fire but
 * produce no visible effect. JSONL is human-readable, easy to grep, easy to
 * export, and survives DB log pruning.
 *
 * Each line is one JSON object:
 *   { ts, run_id, workflow_id, node_id, block_id, step, status, status_text, error }
 *
 * Retention and file rotation are owned by BizCity_JSONL_File_Logger.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since   PG-S9-fix v6 (2026-06-01)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_File_Logger {

	const SUBDIR        = 'automation-workflow-logs';
	const JSONL_FOLDER  = 'bizcity-automation-logs';
	const JSONL_MODULE  = 'workflow-trace';
	const ROTATE_BYTES  = 5242880; // 5 MB
	const STATUS_MAP    = array( 0 => 'RUN', 1 => 'OK', 2 => 'FAIL', 3 => 'SKIP' );

	public static function init(): void {
		add_action( 'bizcity_automation_log_appended', array( __CLASS__, 'on_log_appended' ), 10, 2 );
		// Also capture run lifecycle (enqueue, start, end) for runs that
		// crash before any step logs.
		add_action( 'bizcity_automation_run_enqueued', array( __CLASS__, 'on_run_enqueued' ), 10, 3 );
	}

	/* ─── Path helpers ───────────────────────────────────────────────── */

	public static function base_dir(): string {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return '';
		}
		$location = BizCity_JSONL_File_Logger::location( self::JSONL_FOLDER, self::JSONL_MODULE );
		return (string) ( $location['directory'] ?? '' );
	}

	public static function ensure_dir(): bool {
		$dir = self::base_dir();
		return $dir !== '' && is_dir( $dir ) && is_writable( $dir );
	}

	public static function path_for( int $workflow_id ): string {
		unset( $workflow_id );
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) {
			return '';
		}
		$location = BizCity_JSONL_File_Logger::location( self::JSONL_FOLDER, self::JSONL_MODULE );
		return (string) ( $location['file'] ?? '' );
	}

	public static function size( int $workflow_id ): int {
		unset( $workflow_id );
		$p = self::path_for( 0 );
		return file_exists( $p ) ? (int) @filesize( $p ) : 0;
	}

	/* ─── Writers ────────────────────────────────────────────────────── */

	public static function on_run_enqueued( $run_id, $workflow_id, $payload ): void {
		$wfid = (int) $workflow_id;
		if ( $wfid <= 0 ) { return; }
		self::write( $wfid, array(
			'ts'          => current_time( 'mysql' ),
			'run_id'      => (string) $run_id,
			'workflow_id' => $wfid,
			'event'       => 'run_enqueued',
			'trigger'     => is_array( $payload ) ? ( $payload['_trigger'] ?? '' ) : '',
		) );
	}

	public static function on_log_appended( $run_id, $log_id ): void {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — SQL hook is retained only as compatibility backfill while runner callers migrate.
		$run_id = (string) $run_id;
		$log_id = (int) $log_id;
		if ( class_exists( 'BizCity_Automation_Repo_Runs' )
			&& method_exists( 'BizCity_Automation_Repo_Runs', 'sql_log_mode_enabled' )
			&& ! BizCity_Automation_Repo_Runs::sql_log_mode_enabled( 'read' ) ) {
			// [2026-08-27 Johnny Chu] PHASE-1.30-LIFECYCLE — repo already emits canonical JSONL rows when SQL mode is unavailable/blocked.
			return;
		}
		if ( $run_id === '' || $log_id <= 0 || ! class_exists( 'BizCity_Automation_Repo_Runs' ) ) { return; }

		$run = BizCity_Automation_Repo_Runs::find( $run_id );
		$row = BizCity_Automation_Repo_Runs::log_by_id( $run_id, $log_id );

		if ( ! is_array( $run ) || ! is_array( $row ) || empty( $run['workflow_id'] ) || empty( $row ) ) { return; }

		$status_int = (int) $row['status'];
		$entry = array(
			'ts'          => (string) ( $row['ended_at'] ?? $row['started_at'] ?? current_time( 'mysql' ) ),
			'log_id'      => (int) ( $row['id'] ?? $log_id ),
			'run_id'      => $run_id,
			'workflow_id' => (int) $run['workflow_id'],
			'node_id'     => (string) $row['node_id'],
			'block_id'    => (string) $row['block_id'],
			'step'        => (int) $row['step'],
			'status'      => $status_int,
			'status_text' => self::STATUS_MAP[ $status_int ] ?? (string) $status_int,
		);
		if ( ! empty( $row['error'] ) ) {
			$entry['error'] = (string) $row['error'];
		}
		self::write( (int) $row['workflow_id'], $entry );
	}

	/**
	 * Manual write hook for matcher decisions (called from Matcher_Trace).
	 */
	public static function note_decision( int $workflow_id, string $event, array $context = array() ): void {
		if ( $workflow_id <= 0 ) { return; }
		$entry = array_merge( array(
			'ts'          => current_time( 'mysql' ),
			'workflow_id' => $workflow_id,
			'event'       => $event,
		), $context );
		self::write( $workflow_id, $entry );
	}

	private static function write( int $workflow_id, array $entry ): void {
		unset( $workflow_id );
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) { return; }
		$event = sanitize_key( (string) ( $entry['event'] ?? 'workflow_event' ) );
		BizCity_JSONL_File_Logger::write_contract(
			'core.automation.workflow_trace',
			( (int) ( $entry['status'] ?? 0 ) === 2 ) ? 'error' : 'info',
			$event !== '' ? $event : 'workflow_event',
			(string) ( $entry['block_id'] ?? $event ),
			$entry
		);
	}

	/* ─── Readers / Admin ────────────────────────────────────────────── */

	/**
	 * Return last $lines parsed JSONL entries (newest last). Cheap impl: read
	 * whole file (max 5MB) → split lines → parse JSON.
	 */
	public static function tail( int $workflow_id, int $lines = 200 ): array {
		if ( ! class_exists( 'BizCity_JSONL_File_Logger' ) ) { return array(); }
		$rows = BizCity_JSONL_File_Logger::query_contract( 'core.automation.workflow_trace', array(
			'days' => 30,
			'limit' => max( 1, min( 2000, $lines ) ),
			'filter' => static function ( $row ) use ( $workflow_id ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				return (int) ( $ctx['workflow_id'] ?? $row['workflow_id'] ?? 0 ) === $workflow_id;
			},
		) );
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( is_array( $row ) ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				$out[] = ! empty( $ctx ) ? array_merge( $ctx, array( 'ts' => (string) ( $row['ts'] ?? '' ) ) ) : $row;
			}
		}
		return array_reverse( $out );
	}

	public static function logs_for_run( string $run_id, int $since_id = 0 ): array {
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — provide a bounded JSONL read model when the legacy automation log table is unavailable.
		if ( $run_id === '' || ! class_exists( 'BizCity_JSONL_File_Logger' ) || ! method_exists( 'BizCity_JSONL_File_Logger', 'query_contract' ) ) {
			return array();
		}
		$rows = BizCity_JSONL_File_Logger::query_contract( 'core.automation.workflow_trace', array(
			'days'   => 7,
			'limit'  => 5000,
			'filter' => static function ( $row ) use ( $run_id ) {
				$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
				return (string) ( $ctx['run_id'] ?? '' ) === $run_id;
			},
		) );
		$latest = array();
		foreach ( (array) $rows as $row ) {
			$ctx = is_array( $row['ctx'] ?? null ) ? $row['ctx'] : array();
			$log_id = (int) ( $ctx['log_id'] ?? 0 );
			if ( $log_id <= 0 ) {
				$log_id = abs( (int) crc32( 'automation-jsonl|' . (string) ( $row['event_uuid'] ?? '' ) ) );
				$log_id = $log_id > 0 ? $log_id : 1;
			}
			if ( $since_id > 0 && $log_id <= $since_id ) {
				continue;
			}
			$key = (string) $log_id;
			if ( isset( $latest[ $key ] ) ) {
				continue;
			}
			$latest[ $key ] = array(
				'id'          => $log_id,
				'run_id'      => $run_id,
				'workflow_id' => (int) ( $ctx['workflow_id'] ?? 0 ),
				'node_id'     => (string) ( $ctx['node_id'] ?? '' ),
				'block_id'    => (string) ( $ctx['block_id'] ?? '' ),
				'step'        => (int) ( $ctx['step'] ?? 0 ),
				'status'      => (int) ( $ctx['status'] ?? 0 ),
				'input_json'  => (string) ( $ctx['input_json'] ?? '' ),
				'output_json' => (string) ( $ctx['output_json'] ?? '' ),
				'error'       => (string) ( $ctx['error'] ?? '' ),
				'started_at'  => (string) ( $ctx['started_at'] ?? $row['ts'] ?? '' ),
				'ended_at'    => (string) ( $ctx['ended_at'] ?? '' ),
			);
		}
		usort( $latest, static function ( $left, $right ) {
			return (int) $left['id'] <=> (int) $right['id'];
		} );
		return array_map( array( __CLASS__, 'normalize_file_log' ), array_values( $latest ) );
	}

	private static function normalize_file_log( array $row ): array {
		$row['input']  = $row['input_json'] !== '' ? json_decode( $row['input_json'], true ) : null;
		$row['output'] = $row['output_json'] !== '' ? json_decode( $row['output_json'], true ) : null;
		return $row;
	}

	public static function clear( int $workflow_id ): bool {
		unset( $workflow_id );
		// [2026-08-27 Johnny Chu] R-LOG-HYBRID — append-only JSONL cannot delete one workflow file; retention owns deletion.
		return false;
	}
}
