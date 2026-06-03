<?php
/**
 * BizCity Scheduler — Automation Runner (P1)
 *
 * Listens to `bizcity_scheduler_reminder_fire` and, if the event's
 * `ai_context.automation.on_fire[]` chain is present, dispatches each step
 * through the Intent Provider tool registry (BizCity_Intent_Tools::execute()).
 *
 * Contract: see core/scheduler/docs/PHASE-0.37-SCHEDULER-AUTOMATION.md §3.
 *
 * Cron evidence is written via BizCity_Cron_Manager (R-CRON-META):
 *   - counters: automation_chains, automation_steps_ok, automation_steps_failed
 *   - events: automation_chain_started, automation_step_ok,
 *             automation_step_failed, automation_chain_done
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-04 (Phase 0.37)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Automation {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Priority 20 so channel-gateway push (default 10) fires first.
		add_action( 'bizcity_scheduler_reminder_fire', [ $this, 'on_reminder_fire' ], 20, 1 );
	}

	/**
	 * Inspect $event['ai_context'] for an automation chain and run it.
	 *
	 * @param array $event Full event row (associative).
	 */
	public function on_reminder_fire( $event ): void {
		if ( ! is_array( $event ) || empty( $event['ai_context'] ) ) {
			return;
		}

		$ctx = $this->decode_context( $event['ai_context'] );
		if ( empty( $ctx['automation']['on_fire'] ) || ! is_array( $ctx['automation']['on_fire'] ) ) {
			return;
		}

		$steps    = $ctx['automation']['on_fire'];
		$event_id = (int) ( $event['id'] ?? 0 );
		$user_id  = (int) ( $event['user_id'] ?? 0 );

		$this->note_event( 'automation_chain_started', [
			'event_id'   => $event_id,
			'user_id'    => $user_id,
			'step_count' => count( $steps ),
			'skill_ref'  => isset( $ctx['skill_ref'] ) ? (string) $ctx['skill_ref'] : '',
		] );

		$counters = [ 'automation_chains' => 1, 'automation_steps_ok' => 0, 'automation_steps_failed' => 0 ];
		$results  = [];

		foreach ( $steps as $idx => $step ) {
			if ( ! is_array( $step ) || empty( $step['tool'] ) ) {
				continue;
			}

			$tool     = (string) $step['tool'];
			$args     = isset( $step['args'] ) && is_array( $step['args'] ) ? $step['args'] : [];
			$on_error = isset( $step['on_error'] ) ? (string) $step['on_error'] : 'continue';

			// Inject owner so tool callbacks can gate by user_id.
			$args['_meta'] = array_merge(
				isset( $args['_meta'] ) && is_array( $args['_meta'] ) ? $args['_meta'] : [],
				[
					'user_id'   => $user_id,
					'event_id'  => $event_id,
					'source'    => 'scheduler.automation',
					'step_idx'  => $idx,
				]
			);

			[ $ok, $result, $error ] = $this->run_step( $tool, $args );

			if ( $ok ) {
				$counters['automation_steps_ok']++;
				$results[] = [ 'idx' => $idx, 'tool' => $tool, 'ok' => true ];
				$this->note_event( 'automation_step_ok', [
					'event_id'  => $event_id,
					'step_idx'  => $idx,
					'tool'      => $tool,
					'message'   => isset( $result['message'] ) ? (string) $result['message'] : '',
				] );
				continue;
			}

			$counters['automation_steps_failed']++;
			$results[] = [ 'idx' => $idx, 'tool' => $tool, 'ok' => false, 'error' => $error ];

			$this->note_event( 'automation_step_failed', [
				'event_id' => $event_id,
				'step_idx' => $idx,
				'tool'     => $tool,
				'reason'   => $this->bucket_reason( $error ),
				'error'    => $error,
			] );

			if ( $on_error === 'stop' ) {
				break;
			}

			if ( $on_error === 'retry_once' && empty( $args['_meta']['_retried'] ) ) {
				// One-shot retry: re-queue this same event with a 5-min reminder.
				$this->requeue_event( $event_id );
				break;
			}
			// 'continue' (default): fall through to next step.
		}

		$this->note( $counters );

		$this->note_event( 'automation_chain_done', [
			'event_id' => $event_id,
			'results'  => $results,
		] );

		// ── Auto-mark event done when chain ran without a hard stop ──────
		// status=ok (all steps fine) or status=partial (≥1 ok) → mark done.
		// status=failed (all steps failed) → leave active so operator can retry.
		$steps_ok     = $counters['automation_steps_ok'];
		$steps_failed = $counters['automation_steps_failed'];
		$chain_ran    = ( $steps_ok + $steps_failed ) > 0;
		$chain_ok     = $chain_ran && $steps_ok > 0; // partial is ok too

		if ( $event_id > 0 && $chain_ok && class_exists( 'BizCity_Scheduler_Manager' ) ) {
			BizCity_Scheduler_Manager::instance()->update_event( $event_id, [ 'status' => 'done' ] );
			$this->note_event( 'automation_event_marked_done', [ 'event_id' => $event_id ] );
		}

		/**
		 * Hook for other modules (audit log, dashboards) to observe a chain run.
		 */
		do_action( 'bizcity_scheduler_automation_chain_done', $event, $results, $counters );
	}

	/* ─────────────────── Helpers ─────────────────── */

	/**
	 * Decode ai_context which may already be array (rare) or JSON string.
	 */
	private function decode_context( $raw ): array {
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [];
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Run a single tool step via Intent Provider registry.
	 *
	 * @return array{0:bool,1:array,2:string}  [ok, result, error]
	 */
	private function run_step( string $tool, array $args ): array {
		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return [ false, [], 'intent_tools_unavailable' ];
		}

		try {
			$result = BizCity_Intent_Tools::instance()->execute( $tool, $args );
		} catch ( \Throwable $e ) {
			return [ false, [], 'exception:' . $e->getMessage() ];
		}

		if ( ! is_array( $result ) ) {
			return [ false, [], 'invalid_result_shape' ];
		}

		$ok = ! empty( $result['success'] );
		if ( ! $ok ) {
			$err = isset( $result['message'] ) ? (string) $result['message'] : 'tool_returned_failure';
			return [ false, $result, $err ];
		}

		return [ true, $result, '' ];
	}

	/**
	 * Re-queue an event for retry (one-shot).
	 *
	 * Clears reminder_sent so the next cron scan picks it up again.
	 * Does NOT change start_at — caller can extend a manual deadline if needed.
	 */
	private function requeue_event( int $event_id ): void {
		if ( $event_id <= 0 ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_crm_events';
		$wpdb->update(
			$table,
			[ 'reminder_sent' => 0 ],
			[ 'id' => $event_id ],
			[ '%d' ],
			[ '%d' ]
		);
	}

	/**
	 * Bucket error message into R-CRON-META reason codes.
	 */
	private function bucket_reason( string $error ): string {
		$lower = strtolower( $error );
		if ( strpos( $lower, 'unavailable' ) !== false || strpos( $lower, 'không được tìm thấy' ) !== false ) {
			return 'invalid_param';
		}
		if ( strpos( $lower, 'thiếu thông tin' ) !== false || strpos( $lower, 'invalid_result_shape' ) !== false ) {
			return 'invalid_param';
		}
		if ( strpos( $lower, 'timeout' ) !== false ) {
			return 'timeout';
		}
		if ( strpos( $lower, 'permission' ) !== false ) {
			return 'permission_denied';
		}
		if ( strpos( $lower, 'rate' ) !== false ) {
			return 'rate_limited';
		}
		if ( strpos( $lower, 'exception' ) === 0 ) {
			return 'http_error';
		}
		return 'tool_error';
	}

	private function note( array $payload ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( [ 'counters' => $payload ] );
		}
	}

	private function note_event( string $code, array $payload ): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note_event( $code, $payload );
		}
	}
}
