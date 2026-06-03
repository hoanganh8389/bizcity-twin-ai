<?php
/**
 * BizCity_Automation_TwinBrain_Bridge — bidirectional bridge.
 *
 * BE-6.E (TwinBrain ↔ Automation).
 *
 * ## Downstream (intent → workflow)
 *
 * TwinBrain runtime sau khi nhận diện intent kiểu `create_spreadsheet`,
 * `compose_post`, ... fire:
 *   do_action( 'bizcity_twinbrain_intent', $intent_id, $payload );
 *
 * Bridge này KHÔNG tự dispatch sang matcher — matcher đã có 4 hook sources
 * (channel/scheduler/webhook/cron). Bridge chỉ register hook
 * `bizcity_twinbrain_intent` route sang
 * trigger_type='twinbrain_intent' qua matcher virtual channel.
 *
 * ## Upstream (workflow → MPR think with capture)
 *
 * `BizCity_Automation_LLM_MPR_Think::execute()` gọi
 *   BizCity_Automation_TwinBrain_Bridge::run_with_capture($prompt, $opts, $on_event)
 *
 * → subscribe `bizcity_twin_event` action priority 1 → call
 *   `BizCity_TwinBrain_Runtime::instance()->start_turn(...)` → unhook.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      AUTOMATION BE-6 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_TwinBrain_Bridge {

	/** @var null|callable */
	private static $current_capture = null;

	/**
	 * Request-scoped context populated during run_with_capture.
	 * Tap (priority 30) overlays this onto twin-event envelopes so MPR pane
	 * can scope by automation run_id (instead of TwinBrain trace_id) and
	 * inherit chat_id from inbound payload.
	 *
	 * @var array{run_id?:string, chat_id?:string, workflow_id?:int, user_id?:int}
	 */
	private static $current_context = array();

	/** Public getter for tap / probe. */
	public static function current_context(): array {
		return self::$current_context;
	}

	public static function init(): void {
		// Downstream — route TwinBrain intent to matcher.
		add_action( 'bizcity_twinbrain_intent', array( __CLASS__, 'on_intent' ), 10, 2 );

		// BE-7.A — fan out Twin Event Bus stream into automation triggers.
		// R-EVT-1 / R-EVT-4 compliant: we subscribe canonical `bizcity_twin_event`
		// NOT a custom event name; we DON'T write anything to event_stream here.
		// Priority 20 so projectors (priority 10) run first — our handler is a
		// pure read-side fan-out into matcher's virtual channel.
		add_action( 'bizcity_twin_event', array( __CLASS__, 'on_twin_event' ), 20, 2 );
	}

	/**
	 * Map canonical Twin Event Bus keys → automation trigger_type.
	 * Whitelist only — high-volume keys (final_token, perspective_done…)
	 * are intentionally skipped to avoid floods.
	 */
	private const TWIN_EVENT_MAP = array(
		'synthesis_done'  => 'twinbrain_turn_completed',
		'final_done'      => 'twinbrain_turn_completed',
		'tool_decided'    => 'twinbrain_tool_decided',
		'agent_loop_done' => 'twinbrain_turn_completed',
	);

	public static function on_twin_event( $event_key, $payload = array() ): void {
		if ( ! is_string( $event_key ) || $event_key === '' )                 { return; }
		if ( ! isset( self::TWIN_EVENT_MAP[ $event_key ] ) )                   { return; }
		$trigger_type = self::TWIN_EVENT_MAP[ $event_key ];
		$payload      = is_array( $payload ) ? $payload : array( '_raw' => $payload );

		// Dedup: same (trace_id + trigger_type) only once per request — synthesis_done
		// + final_done both map to twinbrain_turn_completed, we only want 1 enqueue.
		$trace_id = (string) ( $payload['trace_id'] ?? '' );
		$dedup    = $trace_id . '|' . $trigger_type;
		if ( $trace_id !== '' && isset( self::$seen_traces[ $dedup ] ) ) { return; }
		if ( $trace_id !== '' ) { self::$seen_traces[ $dedup ] = true; }

		$wfs = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => $trigger_type,
			'enabled'      => 1,
			'limit'        => 50,
		) );
		foreach ( $wfs['rows'] as $wf ) {
			$cfg = is_string( $wf['trigger_config_json'] ?? null )
				? json_decode( $wf['trigger_config_json'], true )
				: ( $wf['trigger_config'] ?? array() );
			if ( ! is_array( $cfg ) ) { $cfg = array(); }

			// Optional skill_slug filter for tool_decided.
			if ( $trigger_type === 'twinbrain_tool_decided' ) {
				$want = trim( (string) ( $cfg['skill_slug'] ?? '' ) );
				$got  = (string) ( $payload['skill_slug'] ?? $payload['tool'] ?? '' );
				if ( $want !== '' && $want !== $got ) { continue; }
			}

			$enriched = array_merge( $payload, array(
				'_trigger'   => $trigger_type,
				'_event_key' => $event_key,
				'trace_id'   => $trace_id,
			) );

			// Also notify the test listener so FE "Chạy thử" panel can capture.
			if ( class_exists( 'BizCity_Automation_Listener' ) ) {
				BizCity_Automation_Listener::inject( $trigger_type, $enriched );
			}

			$run = BizCity_Automation_Repo_Runs::enqueue( (int) $wf['id'], $enriched );
			if ( ! is_wp_error( $run ) ) {
				do_action( 'bizcity_automation_run_enqueued', $run, (int) $wf['id'], $enriched );
			}
		}
	}

	/** Request-scoped dedup so synthesis_done + final_done don't double-fire. */
	private static $seen_traces = array();

	public static function on_intent( string $intent_id, $payload ): void {
		if ( $intent_id === '' ) { return; }
		$payload = is_array( $payload ) ? $payload : array( '_raw' => $payload );

		// Find workflows with trigger_type=twinbrain_intent + cfg.intent_id matching.
		$wfs = BizCity_Automation_Repo_Workflows::query( array(
			'trigger_type' => 'twinbrain_intent',
			'enabled'      => 1,
			'limit'        => 50,
		) );
		foreach ( $wfs['rows'] as $wf ) {
			$cfg = is_string( $wf['trigger_config_json'] ?? null )
				? json_decode( $wf['trigger_config_json'], true )
				: ( $wf['trigger_config'] ?? array() );
			$want = (string) ( $cfg['intent_id'] ?? '' );
			if ( $want !== '' && $want !== $intent_id ) { continue; }

			$run = BizCity_Automation_Repo_Runs::enqueue( (int) $wf['id'], array_merge( $payload, array(
				'_trigger'  => 'twinbrain_intent',
				'intent_id' => $intent_id,
			) ) );
			if ( ! is_wp_error( $run ) ) {
				do_action( 'bizcity_automation_run_enqueued', $run, (int) $wf['id'], $payload );
			}
		}
	}

	/**
	 * Run TwinBrain `start_turn` with event capture.
	 *
	 * @param string   $prompt    User-facing prompt.
	 * @param array    $opts      Forwarded to start_turn (user_id, guru_id, k, ...).
	 * @param callable $on_event  fn(string $event_key, array $payload): void
	 * @return array              start_turn result OR WP_Error.
	 */
	public static function run_with_capture( string $prompt, array $opts, callable $on_event, array $context = array() ) {
		if ( ! class_exists( 'BizCity_TwinBrain_Runtime' ) ) {
			return new WP_Error( 'twinbrain_missing', 'TwinBrain runtime chưa cài/đang tắt.', array( 'status' => 503 ) );
		}
		self::$current_capture = $on_event;
		self::$current_context = array(
			'run_id'      => isset( $context['run_id'] ) ? (string) $context['run_id'] : '',
			'chat_id'     => isset( $context['chat_id'] ) ? (string) $context['chat_id'] : '',
			'workflow_id' => isset( $context['workflow_id'] ) ? (int) $context['workflow_id'] : 0,
			'user_id'     => isset( $context['user_id'] ) ? (int) $context['user_id'] : (int) ( $opts['user_id'] ?? 0 ),
		);
		$listener = array( __CLASS__, 'capture_event_bus' );
		add_action( 'bizcity_twin_event', $listener, 1, 2 );

		try {
			$result = BizCity_TwinBrain_Runtime::instance()->start_turn( $prompt, $opts );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'twinbrain_exception', $e->getMessage(), array( 'status' => 500 ) );
		} finally {
			remove_action( 'bizcity_twin_event', $listener, 1 );
			self::$current_capture = null;
			self::$current_context = array();
		}
		return $result;
	}

	public static function capture_event_bus( string $event_key, $payload ): void {
		$cb = self::$current_capture;
		if ( ! is_callable( $cb ) ) { return; }
		try {
			$cb( $event_key, is_array( $payload ) ? $payload : array( '_raw' => $payload ) );
		} catch ( \Throwable $e ) {
			error_log( '[automation][twinbrain-bridge] capture failed: ' . $e->getMessage() );
		}
	}
}
