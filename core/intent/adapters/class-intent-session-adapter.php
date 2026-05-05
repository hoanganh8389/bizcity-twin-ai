<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Adapters
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.4
 * Bridge between the legacy `BizCity_Intent_Conversation` row store and the
 * runner's per-request `BizCity_Twin_Rolling_Session`.
 *
 * Sprint 2 responsibilities (minimal viable):
 *   • Resolve / create the `conversation_id` for a request.
 *   • Surface goal / status / slots into the runner ctx so the triage agent
 *     can see in-flight context.
 *   • Persist conversation transitions after the run completes (set_waiting
 *     when status=paused_hil, complete when status=completed).
 *
 * Sprint 3 will fold message history into the session messages list so the
 * agent has multi-turn memory without a separate KG lookup.
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Session_Adapter {

	/** @var BizCity_Intent_Conversation|null */
	private $convs;

	public function __construct() {
		if ( class_exists( 'BizCity_Intent_Conversation' ) ) {
			$this->convs = new BizCity_Intent_Conversation();
		}
	}

	/**
	 * Ensure a conversation row exists and merge its identity + state into ctx.
	 *
	 * @param array $params Original Intent_Engine params.
	 * @param array $ctx    Context produced by Context_Collector.
	 * @return array New ctx with conversation_id + goal + slots + status injected.
	 */
	public function attach( array $params, array $ctx ): array {
		if ( ! $this->convs ) {
			return $ctx; // Legacy class missing — runner still works without continuity.
		}

		$user_id      = (int) ( $params['user_id'] ?? 0 );
		$channel      = (string) ( $params['channel']      ?? 'webchat' );
		$session_id   = (string) ( $params['session_id']   ?? '' );
		$character_id = (int)    ( $params['character_id'] ?? 0 );

		if ( $user_id <= 0 ) {
			return $ctx; // Anonymous request — no persistent conversation.
		}

		$conversation = $this->convs->get_or_create( $user_id, $channel, $session_id, $character_id );
		if ( ! is_array( $conversation ) || empty( $conversation['conversation_id'] ) ) {
			return $ctx;
		}

		$ctx['conversation_id']     = (string) $conversation['conversation_id'];
		$ctx['conversation_goal']   = (string) ( $conversation['goal']       ?? '' );
		$ctx['conversation_label']  = (string) ( $conversation['goal_label'] ?? '' );
		$ctx['conversation_status'] = (string) ( $conversation['status']     ?? 'ACTIVE' );

		$slots = $conversation['slots'] ?? [];
		if ( is_string( $slots ) ) {
			$decoded = json_decode( $slots, true );
			$slots   = is_array( $decoded ) ? $decoded : [];
		}
		$ctx['conversation_slots'] = is_array( $slots ) ? $slots : [];

		// Sprint 3 — fold rolling-memory summary into ctx so the triage agent
		// sees in-flight goals / recently completed conversations across turns.
		if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
			try {
				$rm      = new BizCity_Rolling_Memory();
				$summary = $rm->build_context( $user_id, $session_id, $ctx['conversation_id'] );
				if ( is_string( $summary ) && $summary !== '' ) {
					$ctx['rolling_memory_summary'] = $summary;
				}
			} catch ( Throwable $e ) {
				// Non-fatal — agent still works without rolling memory.
				error_log( '[Intent_Session_Adapter] rolling memory: ' . $e->getMessage() );
			}
		}

		return $ctx;
	}

	/**
	 * Persist post-run state transitions.
	 *
	 * @param BizCity_Twin_RunState $state
	 * @param array                 $ctx
	 */
	public function commit( BizCity_Twin_RunState $state, array $ctx ): void {
		if ( ! $this->convs ) {
			return;
		}
		$conv_id = (string) ( $ctx['conversation_id'] ?? '' );
		if ( $conv_id === '' ) {
			return;
		}

		switch ( $state->status ) {
			case 'paused_hil':
				if ( method_exists( $this->convs, 'set_waiting' ) ) {
					$this->convs->set_waiting( $conv_id, 'approval', 'tool_call' );
				}
				break;

			case 'completed':
				// Don't auto-complete the WHOLE conversation — multi-turn flows
				// expect ACTIVE between runs. Just clear any waiting flag.
				if ( method_exists( $this->convs, 'resume' ) ) {
					$this->convs->resume( $conv_id );
				}
				break;
		}
	}

	/* ------------------------------------------------------------------
	 *  Approval / cancel dispatch — Sprint 2 partial completion of 4.16.2.
	 * ------------------------------------------------------------------ */

	/**
	 * Mark the conversation as cancelled. Returns true if anything matched.
	 */
	public function cancel_active( array $params ): bool {
		if ( ! $this->convs ) {
			return false;
		}
		$user_id    = (int) ( $params['user_id'] ?? 0 );
		$channel    = (string) ( $params['channel']    ?? 'webchat' );
		$session_id = (string) ( $params['session_id'] ?? '' );

		$active = $this->convs->get_active( $user_id, $channel, $session_id );
		if ( ! is_array( $active ) || empty( $active['conversation_id'] ) ) {
			return false;
		}
		if ( method_exists( $this->convs, 'complete' ) ) {
			$this->convs->complete( (string) $active['conversation_id'], 'cancelled by user' );
			return true;
		}
		return false;
	}
}
