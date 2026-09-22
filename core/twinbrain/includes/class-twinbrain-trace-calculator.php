<?php
/**
 * Read-only MPR trace calculator.
 *
 * [2026-09-16 Johnny Chu - Chu Hoàng Anh] PHASE-1.33C-C2 — implements the
 * "read-only trace calculator that reports wall time, parallel worker time,
 * network gap, client gap and unattributed_ms" checklist item. It only reads
 * from the canonical `bizcity_twin_event_stream` table through
 * BizCity_Twin_Event_Store::fetch_for_trace() and performs no write, no
 * decryption of owner bodies and no cross-tenant query — the trace_id filter
 * is combined with the current blog_id at the store layer.
 *
 * Honest scope note: only event types dispatched through
 * BizCity_Twin_Event_Bus::dispatch_v2() become durable, trace-queryable rows.
 * As of this file, that durable set is: user_message, assistant_message,
 * evidence_fallback_notice, context_bank_started, context_bank_done. Every
 * other MPR phase marker (memory_recall, candidate_selection, perspectives,
 * notebook_source_layer_ready, synthesis, final compose) is currently emitted
 * through the V1 telemetry-only BizCity_Twin_Event_Bus::dispatch() path
 * (do_action, no INSERT) and is therefore NOT visible to this calculator yet.
 * This class reports that gap explicitly per phase (`durable => false` with a
 * reason) instead of fabricating a zero or omitting the phase silently — the
 * exact anti-pattern PHASE-1.33C C7/C9/C10 were opened to close.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\TwinBrain
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Trace_Calculator', false ) ) {
	return;
}

final class BizCity_TwinBrain_Trace_Calculator {

	const VERSION = '1.0.0';

	/**
	 * Phase names this calculator knows to look for. Each is reported with
	 * `durable => true` and a measured duration only when a matching event
	 * type actually reached the durable store for this trace; otherwise it is
	 * reported with `durable => false` and a stable reason so a caller cannot
	 * mistake "not yet measured" for "took 0ms".
	 *
	 * @var array<string,string> phase name => durable event_type that proves it
	 */
	private static function known_phase_event_types() {
		return array(
			'context_bank'          => 'context_bank_done',
			'memory_recall'         => '',
			'candidate_selection'   => '',
			'perspectives'          => '',
			'notebook_source_layer' => '',
			'synthesis'             => '',
			'final_composer'        => '',
		);
	}

	/**
	 * Compute the bounded trace breakdown for one trace_id.
	 *
	 * @param string $trace_id
	 * @param array<string,mixed> $opts { @type int $limit Row cap, default 500, max 2000. }
	 * @return array<string,mixed>
	 */
	public static function calculate( string $trace_id, array $opts = array() ) {
		$trace_id = sanitize_text_field( (string) $trace_id );
		if ( '' === $trace_id ) {
			return self::failure( $trace_id, 'trace_id_required' );
		}
		if ( ! class_exists( 'BizCity_Twin_Event_Store' ) || ! method_exists( 'BizCity_Twin_Event_Store', 'fetch_for_trace' ) ) {
			return self::failure( $trace_id, 'event_store_unavailable' );
		}

		$limit = max( 1, min( 2000, (int) ( $opts['limit'] ?? 500 ) ) );
		try {
			$events = BizCity_Twin_Event_Store::fetch_for_trace( $trace_id, array( 'limit' => $limit ) );
		} catch ( \Throwable $e ) {
			return self::failure( $trace_id, 'event_store_query_failed' );
		}
		if ( empty( $events ) || ! is_array( $events ) ) {
			return self::failure( $trace_id, 'no_durable_events_for_trace' );
		}

		return self::compute_from_events( $trace_id, $events );
	}

	/**
	 * Pure computation over an already-fetched row set — no DB, no WP globals
	 * beyond what the caller already resolved into `$events`. Extracted so the
	 * wall-time/unattributed_ms/phase rules are assertable against a fixture
	 * array without a live event-stream table, the same reason
	 * PHASE-1.33D extracted `narrow_contracts_for_binding()` as a pure helper.
	 *
	 * @param string $trace_id
	 * @param array<int,array<string,mixed>> $events Rows as returned by
	 *        BizCity_Twin_Event_Store::fetch_for_trace() — each row must carry
	 *        `event_type`, `event_uuid`, `parent_event_uuid`, `created_epoch_ms`
	 *        and a decoded `payload` array, ordered ascending by id/time.
	 * @return array<string,mixed>
	 */
	public static function compute_from_events( string $trace_id, array $events ) {
		if ( empty( $events ) ) {
			return self::failure( $trace_id, 'no_durable_events_for_trace' );
		}

		$by_type = array();
		foreach ( $events as $row ) {
			$type = (string) ( $row['event_type'] ?? '' );
			if ( '' === $type ) {
				continue;
			}
			$by_type[ $type ][] = $row;
		}

		// [PHASE-1.33C §3.2.1] Prefer the widest known durable turn boundary
		// (user_message -> assistant_message) for wall time; fall back to the
		// first/last durable event overall with an explicit caveat so a
		// partial trace (e.g. only the Context Bank pair persisted) is never
		// silently reported as if it bounded the whole turn.
		$first_row = $events[0];
		$last_row  = end( $events );
		$wall_basis = 'all_durable_events_fallback';
		if ( ! empty( $by_type['user_message'] ) && ! empty( $by_type['assistant_message'] ) ) {
			$first_row  = $by_type['user_message'][0];
			$last_row   = end( $by_type['assistant_message'] );
			$wall_basis = 'user_message_to_assistant_message';
		}
		$wall_start_ms = (int) ( $first_row['created_epoch_ms'] ?? 0 );
		$wall_end_ms   = (int) ( $last_row['created_epoch_ms'] ?? 0 );
		$wall_ms       = max( 0, $wall_end_ms - $wall_start_ms );

		$phases              = array();
		$known_phase_ms_sum  = 0;
		foreach ( self::known_phase_event_types() as $phase_name => $done_type ) {
			if ( '' === $done_type || empty( $by_type[ $done_type ] ) ) {
				$phases[ $phase_name ] = array(
					'durable' => false,
					'reason'  => '' === $done_type
						? 'not_yet_persisted_via_dispatch_v2'
						: 'no_' . $done_type . '_event_for_trace',
				);
				continue;
			}
			$done_row     = end( $by_type[ $done_type ] );
			$done_payload = is_array( $done_row['payload'] ?? null ) ? $done_row['payload'] : array();
			$duration_ms  = (int) ( $done_payload['duration_ms'] ?? 0 );
			$status       = (string) ( $done_payload['status'] ?? '' );
			$phases[ $phase_name ] = array(
				'durable'           => true,
				'duration_ms'       => $duration_ms,
				'status'            => $status,
				'reason_bucket'     => (string) ( $done_payload['reason_bucket'] ?? '' ),
				'event_uuid'        => (string) ( $done_row['event_uuid'] ?? '' ),
				'parent_event_uuid' => (string) ( $done_row['parent_event_uuid'] ?? '' ),
				'created_epoch_ms'  => (int) ( $done_row['created_epoch_ms'] ?? 0 ),
			);
			// [PHASE-1.33C §3.2.1 rules 2-3] A skipped phase contributes 0 to the
			// sum by construction (duration_ms is 0 on that path); a `deadline`
			// phase's duration_ms is already the joined span, not the full lane,
			// so it is safe to add here without re-deriving the distinction.
			if ( in_array( $status, array( 'ran', 'degraded', 'deadline' ), true ) ) {
				$known_phase_ms_sum += $duration_ms;
			}
		}

		$unattributed_ms = max( 0, $wall_ms - $known_phase_ms_sum );

		return array(
			'ok'                  => true,
			'trace_id'            => $trace_id,
			'event_count'         => count( $events ),
			'durable_event_types' => array_keys( $by_type ),
			'wall_time_ms'        => $wall_ms,
			'wall_time_basis'     => $wall_basis,
			'known_phase_ms_sum'  => $known_phase_ms_sum,
			'unattributed_ms'     => $unattributed_ms,
			'phases'              => $phases,
			// [C2 checklist] Parallel worker time, network gap and client gap are
			// intentionally null, not 0 or omitted: no perspective event is durable
			// yet (see `perspectives` phase above), and network/client gaps require
			// a client-reported `client_received_ms`/`client_painted_ms` timestamp
			// this server-side calculator has no way to observe.
			'parallel_worker_ms'  => null,
			'network_gap_ms'      => null,
			'client_gap_ms'       => null,
			'coverage_note'       => 'Only event types dispatched through BizCity_Twin_Event_Bus::dispatch_v2() are durable and computable here. See class docblock for the current durable set.',
			'calculator_version'  => self::VERSION,
		);
	}

	/**
	 * @param string $trace_id
	 * @param string $reason
	 * @return array<string,mixed>
	 */
	private static function failure( $trace_id, $reason ) {
		return array(
			'ok'                 => false,
			'trace_id'           => (string) $trace_id,
			'reason'             => (string) $reason,
			'calculator_version' => self::VERSION,
		);
	}
}
