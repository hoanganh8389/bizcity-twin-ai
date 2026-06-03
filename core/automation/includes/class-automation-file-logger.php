<?php
/**
 * Per-workflow JSONL file logger.
 *
 * Subscribes to `bizcity_automation_log_appended` and mirrors each runner log
 * row into a JSONL file. Path is multisite-aware via wp_upload_dir():
 *   single-site → wp-content/uploads/automation-workflow-logs/wf-{id}.jsonl
 *   multisite   → wp-content/uploads/sites/{blog_id}/automation-workflow-logs/wf-{id}.jsonl
 *
 * Purpose: debug workflows that don't visibly fire (no run created) or fire but
 * produce no visible effect. JSONL is human-readable, easy to grep, easy to
 * export, and survives DB log pruning.
 *
 * Each line is one JSON object:
 *   { ts, run_id, workflow_id, node_id, block_id, step, status, status_text, error }
 *
 * Rotation: when file > 5 MB → rename to wf-{id}.1.jsonl (single backup, overwrite).
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since   PG-S9-fix v6 (2026-06-01)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_File_Logger {

	const SUBDIR        = 'automation-workflow-logs';
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
		$up = wp_upload_dir();
		return trailingslashit( $up['basedir'] ) . self::SUBDIR;
	}

	public static function ensure_dir(): bool {
		$dir = self::base_dir();
		if ( ! file_exists( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) { return false; }
			// Lock down from public listing.
			@file_put_contents( $dir . '/index.html', '' );
			@file_put_contents( $dir . '/.htaccess', "Order allow,deny\nDeny from all\n" );
		}
		return is_writable( $dir );
	}

	public static function path_for( int $workflow_id ): string {
		return self::base_dir() . '/wf-' . $workflow_id . '.jsonl';
	}

	public static function size( int $workflow_id ): int {
		$p = self::path_for( $workflow_id );
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
		global $wpdb;
		$log_id = (int) $log_id;
		if ( $log_id <= 0 ) { return; }

		$tbl_logs = $wpdb->prefix . 'bizcity_automation_logs';
		$tbl_runs = $wpdb->prefix . 'bizcity_automation_runs';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT l.id, l.run_id, l.node_id, l.block_id, l.step, l.status, l.started_at, l.ended_at, l.error,
			        r.workflow_id
			   FROM {$tbl_logs} l
			   LEFT JOIN {$tbl_runs} r ON r.run_id = l.run_id
			  WHERE l.id = %d
			  LIMIT 1",
			$log_id
		), ARRAY_A );

		if ( ! $row || empty( $row['workflow_id'] ) ) { return; }

		$status_int = (int) $row['status'];
		$entry = array(
			'ts'          => $row['ended_at'] ?: $row['started_at'] ?: current_time( 'mysql' ),
			'run_id'      => (string) $row['run_id'],
			'workflow_id' => (int) $row['workflow_id'],
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
		if ( ! self::ensure_dir() ) { return; }
		$path = self::path_for( $workflow_id );
		self::rotate_if_needed( $path );
		$line = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $line === false ) { return; }
		@file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	private static function rotate_if_needed( string $path ): void {
		if ( ! file_exists( $path ) ) { return; }
		if ( @filesize( $path ) < self::ROTATE_BYTES ) { return; }
		$bak = $path . '.1';
		if ( file_exists( $bak ) ) { @unlink( $bak ); }
		@rename( $path, $bak );
	}

	/* ─── Readers / Admin ────────────────────────────────────────────── */

	/**
	 * Return last $lines parsed JSONL entries (newest last). Cheap impl: read
	 * whole file (max 5MB) → split lines → parse JSON.
	 */
	public static function tail( int $workflow_id, int $lines = 200 ): array {
		$path = self::path_for( $workflow_id );
		if ( ! file_exists( $path ) ) { return array(); }
		$raw  = @file_get_contents( $path );
		if ( ! is_string( $raw ) || $raw === '' ) { return array(); }
		$rows = preg_split( "/\r?\n/", trim( $raw ) );
		if ( ! is_array( $rows ) ) { return array(); }
		if ( count( $rows ) > $lines ) {
			$rows = array_slice( $rows, -$lines );
		}
		$out = array();
		foreach ( $rows as $r ) {
			if ( $r === '' ) { continue; }
			$d = json_decode( $r, true );
			if ( is_array( $d ) ) { $out[] = $d; }
			else                  { $out[] = array( 'raw' => $r ); }
		}
		return $out;
	}

	public static function clear( int $workflow_id ): bool {
		$path = self::path_for( $workflow_id );
		$ok1  = ! file_exists( $path ) || @unlink( $path );
		$bak  = $path . '.1';
		if ( file_exists( $bak ) ) { @unlink( $bak ); }
		return (bool) $ok1;
	}
}
