<?php
/**
 * BizCity Twin AI — Router Event Ingester (Phase 0.12 Wave C)
 *
 * Helper that parses `_twin_events` from bizcity-llm-router HTTP responses
 * and feeds each envelope into `BizCity_Twin_Event_Bus::ingest_remote()`.
 * Idempotent — `ingest_remote` dedupes by `event_uuid`.
 *
 * Two entry points:
 *   - `ingest_response_body($json_decoded_body)` for plain JSON REST calls.
 *   - `ingest_sse_frame($frame_json)` for SSE `event: twin_event` frames
 *     (used in Wave 0.12.D when /ai/resolve/stream piggybacks events).
 *
 * Side effect: every successfully ingested envelope fires the action
 * `bizcity_twin_event_v2` so the Stream Handler forwarder can relay it
 * through the live `event: twin_event` SSE frame to the React UI in the
 * same browser session.
 *
 * @package BizCity_Twin_AI
 * @since   2026-04-29 (Phase 0.12 Wave C)
 */

defined( 'ABSPATH' ) or die( 'Direct access denied.' );

if ( ! class_exists( 'BizCity_Twin_Router_Event_Ingester' ) ) :

class BizCity_Twin_Router_Event_Ingester {

	/** Match the reserved key used by the router emitter. */
	const RESPONSE_KEY = '_twin_events';

	/**
	 * Read a router response (already JSON-decoded) and ingest any
	 * piggybacked twin events. Returns the count of events ingested.
	 *
	 * Safe to call with any value — non-arrays / missing key return 0.
	 *
	 * @param mixed $body Decoded HTTP response body.
	 * @return int        Number of envelopes ingested (after dedup).
	 */
	public static function ingest_response_body( $body ): int {
		if ( ! is_array( $body ) ) {
			return 0;
		}
		$envelopes = $body[ self::RESPONSE_KEY ] ?? null;
		if ( ! is_array( $envelopes ) || empty( $envelopes ) ) {
			return 0;
		}
		return self::ingest_envelopes( $envelopes );
	}

	/**
	 * Parse a single SSE frame's `data:` JSON string and ingest it.
	 *
	 * @param string $data_json Raw JSON from `data: ...` line.
	 * @return string|null      Ingested event_uuid or null on error.
	 */
	public static function ingest_sse_frame( string $data_json ): ?string {
		$decoded = json_decode( $data_json, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		try {
			return BizCity_Twin_Event_Bus::ingest_remote( $decoded );
		} catch ( \Throwable $e ) {
			error_log( '[Router_Event_Ingester] SSE ingest failed: ' . $e->getMessage() );
			return null;
		}
	}

	/**
	 * Ingest a list of envelopes. Each failure is logged but does not
	 * abort the batch — partial success is acceptable.
	 *
	 * @param array<int,array<string,mixed>> $envelopes
	 * @return int
	 */
	public static function ingest_envelopes( array $envelopes ): int {
		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $envelopes as $env ) {
			if ( ! is_array( $env ) || empty( $env['event_uuid'] ) || empty( $env['event_type'] ) ) {
				continue;
			}
			try {
				BizCity_Twin_Event_Bus::ingest_remote( $env );
				$count++;
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'[Router_Event_Ingester] ingest failed type=%s uuid=%s: %s',
					(string) ( $env['event_type'] ?? '?' ),
					(string) ( $env['event_uuid'] ?? '?' ),
					$e->getMessage()
				) );
			}
		}
		return $count;
	}

	/**
	 * Convenience: build the trace_id payload fragment to attach to a
	 * router REST request body so the server can correlate emitted events
	 * with the user turn currently being handled by the client.
	 *
	 * @return array{trace_id?:string,session_id?:string,conversation_id?:int}
	 */
	public static function build_trace_meta(): array {
		$out = [];
		if ( class_exists( 'BizCity_Trace_Store' ) ) {
			$tid = (string) BizCity_Trace_Store::current_trace_id();
			if ( $tid !== '' ) {
				$out['trace_id'] = $tid;
			}
		}
		// Best-effort session/conversation fields for richer correlation.
		if ( ! empty( $_REQUEST['session_id'] ) ) {
			$out['session_id'] = sanitize_text_field( (string) $_REQUEST['session_id'] );
		}
		if ( ! empty( $_REQUEST['conversation_id'] ) ) {
			$out['conversation_id'] = (int) $_REQUEST['conversation_id'];
		}
		return $out;
	}
}

endif;
