<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.13 Vòng 3 — Task 3.13.1
 * BizCity_TwinShell_Event_Bus — runtime event bus for agent runs.
 *
 * Persists events into bizcity_trace_tasks (reused as event log) via
 * BizCity_Trace_Store::append_run_event(). Each emit auto-increments
 * a per-run sequence counter so consumers can replay from `?since=N`.
 *
 * Naming note: prefixed `BizCity_TwinShell_*` to avoid collision with
 * the legacy `BizCity_Twin_Event_Bus` (Phase 0.12 trace stream).
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinShell_Event_Bus' ) ) return;

final class BizCity_TwinShell_Event_Bus {

	/**
	 * Standard event types emitted by the Runner.
	 */
	const TYPE_RUN_START          = 'run_start';
	const TYPE_TOOL_PROPOSED      = 'tool_proposed';
	const TYPE_TOOL_EXECUTED      = 'tool_executed';
	const TYPE_INTERRUPT          = 'interrupt';
	const TYPE_RESUMED            = 'resumed';
	const TYPE_FINAL              = 'final';
	const TYPE_FAILED             = 'failed';
	// Vòng 3 — Sprint 6 (Canvas Activation): bridge sub-agent runs into the
	// parent run's event stream so FE polling the parent run_id can pick up
	// child agent lifecycle (and the child component's self-gate auto-opens).
	const TYPE_SUBAGENT_STARTED   = 'subagent_started';
	const TYPE_SUBAGENT_COMPLETED = 'subagent_completed';

	/**
	 * In-memory per-run sequence cache to avoid extra SELECT MAX(seq) on hot loops.
	 * Keyed by run_id → last seq written by THIS process.
	 *
	 * @var array<string, int>
	 */
	private static $seq_cache = [];

	/**
	 * Emit a single event for the given run.
	 *
	 * @param string $run_id  Run identifier.
	 * @param string $type    One of the TYPE_* constants (free-form string allowed).
	 * @param array  $payload Arbitrary JSON-serializable payload.
	 * @return int Sequence number assigned (>=1), or 0 on failure.
	 */
	public static function emit( string $run_id, string $type, array $payload = [] ): int {
		if ( $run_id === '' || $type === '' ) {
			return 0;
		}

		try {
			$store = BizCity_Trace_Store::instance();
			$seq   = self::next_seq( $run_id );
			$store->append_run_event( $run_id, $seq, $type, $payload );
			return $seq;
		} catch ( \Throwable $e ) {
			error_log( '[TwinShell_Event_Bus] emit failed (' . $type . '): ' . $e->getMessage() );
			return 0;
		}
	}

	/**
	 * Fetch events newer than $since for a run.
	 *
	 * @param string $run_id
	 * @param int    $since   Return events with seq > $since.
	 * @return array<int, array{seq:int, event_type:string, payload:array, created_at:string}>
	 */
	public static function fetch( string $run_id, int $since = 0 ): array {
		if ( $run_id === '' ) {
			return [];
		}

		$rows = BizCity_Trace_Store::instance()->get_run_events( $run_id, $since );

		$out = [];
		foreach ( $rows as $row ) {
			$payload = isset( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : null;
			$out[] = [
				'seq'        => (int) $row['seq'],
				'event_type' => (string) $row['event_type'],
				'payload'    => is_array( $payload ) ? $payload : [],
				'created_at' => (string) ( $row['created_at'] ?? '' ),
			];
		}
		return $out;
	}

	/**
	 * Compute the next sequence number for a run.
	 *
	 * Uses an in-memory cache for the current PHP process. On first call for
	 * a run, queries SELECT MAX(seq) so resumes by a different worker pick up
	 * after the highest persisted event.
	 *
	 * @param string $run_id
	 * @return int Next seq (>= 1).
	 */
	private static function next_seq( string $run_id ): int {
		if ( ! isset( self::$seq_cache[ $run_id ] ) ) {
			global $wpdb;
			$store = BizCity_Trace_Store::instance();
			$store->ensure_tables();
			$tasks_table = $wpdb->prefix . 'bizcity_trace_tasks';
			$max = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(MAX(seq), 0) FROM `{$tasks_table}` WHERE trace_id = %s",
				$run_id
			) );
			self::$seq_cache[ $run_id ] = $max;
		}

		self::$seq_cache[ $run_id ]++;
		return self::$seq_cache[ $run_id ];
	}
}
