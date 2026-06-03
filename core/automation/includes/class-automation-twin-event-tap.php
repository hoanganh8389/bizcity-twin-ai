<?php
/**
 * BizCity_Automation_Twin_Event_Tap — Mirror Twin Event Bus into Listener Bus.
 *
 * Phase PG-S3 (Playground MPR pane v1, 2026-05-31).
 *
 * Subscribes to canonical `bizcity_twin_event` action and re-emits a slim
 * envelope into `BizCity_Listener_Bus` with `kind: 'twin'`. Playground UI
 * (Right Sidebar tab "MPR") subscribes to the same Listener Bus stream and
 * groups twin events into 5 cognitive layers.
 *
 * Architecture rationale:
 *   - Listener Bus is the single canonical FE live tail (R-LB-1).
 *   - We DO NOT open a new SSE / REST channel for MPR — same stream,
 *     filter by `kind=twin` on FE.
 *   - Bus emission is read-only side effect; we do NOT touch
 *     `bizcity_twin_event_stream` table here (R-EVT-1 / R-EVT-4).
 *
 * Layer map (informational; FE owns the rendering grouping too):
 *   L1 Perceive  : user_message, brain_keywords, pre_rules_done
 *   L2 Recall    : memory_recall, guru_lookup, guru_layer
 *   L3 Reason    : brain_perspective_selected, brain_tool_intent,
 *                  tool_decided, tool_done, perspective_done
 *   L4 Synthesize: brain_synthesize, synthesis_done, agent_loop_done
 *   L4.5 Compose : assistant_message, final_done, memory_write
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      PHASE PG-S3 (2026-05-31)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Twin_Event_Tap {

	/**
	 * Whitelist of twin event keys we mirror — keep small to avoid flooding
	 * the listener ring (200 events cap). High-frequency token streams
	 * (final_token, agent_step) are intentionally skipped.
	 */
	private const LAYER_MAP = array(
		// L1 — Perceive
		'pre_rules_done'            => 1,
		'user_message'              => 1,
		'brain_keywords'            => 1,

		// L2 — Recall
		'memory_recall'             => 2,
		'guru_lookup'               => 2,
		'guru_layer'                => 2,

		// L3 — Reason / Tool
		'brain_perspective_selected'=> 3,
		'brain_tool_intent'         => 3,
		'tool_decided'              => 3,
		'tool_done'                 => 3,
		'perspective_done'          => 3,

		// L4 — Synthesize
		'brain_synthesize'          => 4,
		'synthesis_done'            => 4,
		'agent_loop_done'           => 4,
		'pipeline_auto_degraded'    => 4,

		// L4.5 — Compose / Final
		'assistant_message'         => 5,
		'final_done'                => 5,
		'memory_write'              => 5,
	);

	public static function init(): void {
		// Priority 30 — runs AFTER projectors (10) and AFTER capture/bridge (20-25),
		// so it never blocks the canonical pipeline.
		add_action( 'bizcity_twin_event', array( __CLASS__, 'on_twin_event' ), 30, 2 );
	}

	public static function on_twin_event( $event_key, $payload = array() ): void {
		if ( ! is_string( $event_key ) || $event_key === '' )                  { return; }
		if ( ! class_exists( 'BizCity_Listener_Bus' ) )                        { return; }
		if ( ! isset( self::LAYER_MAP[ $event_key ] ) )                        { return; }

		$payload = is_array( $payload ) ? $payload : array( '_raw' => $payload );
		$layer   = self::LAYER_MAP[ $event_key ];

		// PG fix #3+#4 — overlay automation bridge context so envelope is
		// scoped to the current automation run (not TwinBrain trace) and
		// inherits chat_id/user_id from inbound trigger payload. The bridge
		// only populates this during run_with_capture(); outside that window
		// the array is empty and we fall back to the payload itself.
		$bridge_ctx = class_exists( 'BizCity_Automation_TwinBrain_Bridge' )
			? BizCity_Automation_TwinBrain_Bridge::current_context()
			: array();

		$summary = self::summarize( $event_key, $payload );

		$run_id  = '';
		if ( ! empty( $bridge_ctx['run_id'] ) ) {
			$run_id = (string) $bridge_ctx['run_id'];
		} elseif ( isset( $payload['trace_id'] ) ) {
			$run_id = (string) $payload['trace_id'];
		}

		$chat_id = (string) ( $payload['chat_id'] ?? '' );
		if ( $chat_id === '' && ! empty( $bridge_ctx['chat_id'] ) ) {
			$chat_id = (string) $bridge_ctx['chat_id'];
		}

		$user_id = (string) ( $payload['user_id'] ?? '' );
		if ( $user_id === '' && ! empty( $bridge_ctx['user_id'] ) ) {
			$user_id = (string) $bridge_ctx['user_id'];
		}

		BizCity_Listener_Bus::emit( array(
			'kind'       => 'twin',
			'platform'   => 'TWIN',
			'event_type' => $event_key,
			'account_id' => (string) ( $payload['guru_id'] ?? $payload['character_id'] ?? '' ),
			'user_id'    => $user_id,
			'chat_id'    => $chat_id,
			'run_id'     => $run_id !== '' ? $run_id : null,
			'message'    => $summary,
			'meta'       => array(
				'layer'       => $layer,
				'event_key'   => $event_key,
				'workflow_id' => isset( $bridge_ctx['workflow_id'] ) ? (int) $bridge_ctx['workflow_id'] : 0,
				'trace_id'    => isset( $payload['trace_id'] ) ? (string) $payload['trace_id'] : '',
				'payload'     => self::trim_payload( $payload ),
			),
		) );
	}

	/**
	 * Build a one-line human summary per event_key for the bubble label.
	 * Keeps payload-heavy events readable.
	 */
	private static function summarize( string $key, array $p ): string {
		switch ( $key ) {
			case 'user_message':
				return '👤 ' . self::short( (string) ( $p['text'] ?? $p['message'] ?? '' ), 160 );
			case 'pre_rules_done':
				$n = (int) ( $p['rules_count'] ?? count( (array) ( $p['rules'] ?? array() ) ) );
				return "📋 Pre-rules ({$n})";
			case 'brain_keywords':
				$kw = (array) ( $p['keywords'] ?? array() );
				return '🔑 Keywords: ' . self::short( implode( ', ', array_slice( $kw, 0, 8 ) ), 120 );
			case 'guru_lookup':
				return '🧙 Guru lookup → ' . (string) ( $p['guru_id'] ?? $p['guru_name'] ?? '?' );
			case 'guru_layer':
				return '🧙 Guru layer · ' . (string) ( $p['layer'] ?? $p['stage'] ?? '?' );
			case 'memory_recall':
				$n = (int) ( $p['count'] ?? count( (array) ( $p['items'] ?? array() ) ) );
				return "💭 Memory recall · {$n} item(s)";
			case 'brain_perspective_selected':
				return '🎭 Perspective: ' . (string) ( $p['perspective'] ?? $p['name'] ?? '?' );
			case 'brain_tool_intent':
				return '🔧 Tool intent: ' . (string) ( $p['tool'] ?? $p['intent'] ?? '?' );
			case 'tool_decided':
				$dec = (string) ( $p['decision'] ?? '?' );
				$tool = (string) ( $p['tool'] ?? '' );
				return '🔧 Tool decided: ' . $dec . ( $tool !== '' ? " ({$tool})" : '' );
			case 'tool_done':
				$tool = (string) ( $p['tool'] ?? '?' );
				$ms   = (int) ( $p['duration_ms'] ?? 0 );
				return "✅ Tool done · {$tool}" . ( $ms ? " · {$ms}ms" : '' );
			case 'perspective_done':
				return '🎭 Perspective done · ' . (string) ( $p['perspective'] ?? '?' );
			case 'brain_synthesize':
				return '🧠 Synthesize…';
			case 'synthesis_done':
				$ms = (int) ( $p['duration_ms'] ?? 0 );
				return '🧠 Synthesis done' . ( $ms ? " · {$ms}ms" : '' );
			case 'agent_loop_done':
				$steps = (int) ( $p['steps'] ?? 0 );
				return "🤖 Agent loop done · {$steps} step(s)";
			case 'pipeline_auto_degraded':
				return '⚠ Pipeline degraded · ' . (string) ( $p['reason'] ?? '?' );
			case 'assistant_message':
				return '🤖 ' . self::short( (string) ( $p['text'] ?? $p['message'] ?? '' ), 200 );
			case 'final_done':
				$ms = (int) ( $p['duration_ms'] ?? 0 );
				return '🏁 Final done' . ( $ms ? " · {$ms}ms" : '' );
			case 'memory_write':
				$n = (int) ( $p['count'] ?? 1 );
				return "💾 Memory write · {$n}";
			default:
				return $key;
		}
	}

	private static function short( string $s, int $cap ): string {
		$s = trim( preg_replace( '/\s+/u', ' ', $s ) );
		if ( $cap <= 0 || mb_strlen( $s ) <= $cap ) { return $s; }
		return mb_substr( $s, 0, $cap - 1 ) . '…';
	}

	/**
	 * Drop noisy fields + cap nested string lengths so meta stays small
	 * (option-backed ring). We want < ~4KB per event.
	 */
	private static function trim_payload( array $p ): array {
		unset( $p['_raw'], $p['stream_chunk'], $p['chunks'] );
		$out = array();
		foreach ( $p as $k => $v ) {
			if ( is_string( $v ) ) {
				$out[ $k ] = self::short( $v, 600 );
			} elseif ( is_scalar( $v ) || is_null( $v ) ) {
				$out[ $k ] = $v;
			} elseif ( is_array( $v ) ) {
				// Encode + trim arrays as JSON snippet to avoid runaway nesting.
				$json = wp_json_encode( $v );
				$out[ $k ] = is_string( $json ) ? self::short( $json, 800 ) : '[array]';
			}
		}
		return $out;
	}
}
