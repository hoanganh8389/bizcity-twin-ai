<?php
/**
 * Intent Engine to Twin Goal Loop compatibility adapter.
 *
 * Intent Engine remains the turn-level classifier. This adapter maps its
 * conversation row into the canonical Goal State without writing a second state.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Intent_Adapter {

	public static function from_conversation( array $conversation, array $context = array() ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — map legacy Intent Conversation state into canonical Goal State.
		$intent_status = strtoupper( (string) ( $conversation['status'] ?? '' ) );
		$status_map = array(
			'ACTIVE'        => 'executing',
			'WAITING_USER'  => 'clarifying',
			'COMPLETED'     => 'completed',
			'CANCELLED'     => 'cancelled',
			'CLOSED'        => 'cancelled',
			'EXPIRED'       => 'abandoned',
		);
		$slots = $conversation['slots'] ?? array();
		if ( is_string( $slots ) ) {
			$slots = json_decode( $slots, true );
		}
		$slots = is_array( $slots ) ? $slots : array();
		$open_loops = $conversation['open_loops'] ?? array();
		if ( is_string( $open_loops ) ) {
			$open_loops = json_decode( $open_loops, true );
		}
		$state = array(
			'goal_id'             => (string) ( $conversation['goal_id'] ?? $conversation['conversation_id'] ?? '' ),
			// [2026-08-02 Johnny Chu] HOTFIX — preserve the canonical identity supplied by the channel/session resolver.
			'identity_uuid'       => (string) ( $conversation['identity_uuid'] ?? $context['identity_uuid'] ?? '' ),
			'blog_id'             => (int) ( $conversation['blog_id'] ?? $context['blog_id'] ?? get_current_blog_id() ),
			'session_id'          => (string) ( $conversation['session_id'] ?? $context['session_id'] ?? '' ),
			'primary_goal'        => (string) ( $conversation['goal_label'] ?? $conversation['goal'] ?? '' ),
			'user_intent_current' => (string) ( $context['intent'] ?? $conversation['goal'] ?? '' ),
			'required_inputs'     => ! empty( $conversation['waiting_field'] ) ? array( (string) $conversation['waiting_field'] ) : array(),
			'open_loops'          => is_array( $open_loops ) ? $open_loops : array(),
			'blockers'            => ! empty( $conversation['waiting_for'] ) ? array( (string) $conversation['waiting_for'] ) : array(),
			'status'              => $status_map[ $intent_status ] ?? 'clarifying',
			'completion_score'    => $intent_status === 'COMPLETED' ? 1.0 : 0.0,
			'closure_signal'      => $intent_status === 'COMPLETED' ? array( 'type' => 'user_completed' ) : null,
		);
		if ( ! empty( $slots['definition_of_done'] ) ) {
			$state['definition_of_done'] = (array) $slots['definition_of_done'];
		}
		if ( class_exists( 'BizCity_TwinBrain_Goal_Loop_State' ) ) {
			return BizCity_TwinBrain_Goal_Loop_State::normalize( $state );
		}
		return $state;
	}
}
