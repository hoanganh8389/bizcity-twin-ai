<?php
/**
 * Deterministic Prompt Intent to Goal Alignment contract.
 *
 * This class does not call an LLM or persist state. It checks whether the
 * existing triage intent and Goal Draft are sufficiently compatible for the
 * next TwinBrain stage.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Goal_Alignment', false ) ) {
	return;
}

final class BizCity_TwinBrain_Goal_Alignment {

	/**
	 * @return array<string,mixed>
	 */
	public static function check( array $intent, array $draft = array(), array $goal = array(), array $temporal = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-GOAL-ALIGNMENT — deterministic gate; Prompt Intent remains the single triage classifier.
		// [2026-08-17 Johnny Chu] MPR-V5-GOAL-ALIGNMENT — preserve dotted Prompt Intent IDs used by the canonical taxonomy.
		$intent_id = self::normalize_intent_id( (string) ( $intent['intent_id'] ?? 'unknown.clarify' ) );
		$goal_id = sanitize_text_field( (string) ( $goal['goal_id'] ?? '' ) );
		$goal_text = trim( (string) ( $goal['primary_goal'] ?? $draft['primary_goal'] ?? '' ) );
		$requires_confirmation = (string) ( $intent['side_effect_level'] ?? 'none' ) === 'write_external'
			|| ! empty( $intent['requires_hil'] );
		$reasons = array();
		$status = 'aligned';

		if ( $intent_id === '' || $intent_id === 'unknown.clarify' ) {
			$status = 'needs_clarification';
			$reasons[] = 'intent_unclear';
		}
		if ( $goal_text === '' && ! empty( $intent['requires_goal'] ) ) {
			$status = 'needs_clarification';
			$reasons[] = 'goal_draft_missing';
		}
		if ( ! empty( $temporal ) && empty( $temporal['resolved'] ) && ! empty( $temporal['required'] ) ) {
			$status = 'needs_clarification';
			$reasons[] = 'temporal_context_missing';
		}
		if ( ! empty( $intent['preserve_explicit_command'] ) && $intent_id !== 'automation.task_execute' ) {
			$status = 'conflict';
			$reasons[] = 'explicit_command_intent_mismatch';
		}
		if ( $goal_id === '' && $goal_text !== '' && ! empty( $intent['requires_goal'] ) ) {
			$reasons[] = 'goal_session_not_opened_yet';
		}
		if ( empty( $reasons ) ) {
			$reasons[] = 'intent_and_goal_contract_present';
		}

		return array(
			'status'                   => $status,
			'intent_id'                => $intent_id,
			'goal_id'                  => $goal_id,
			'confidence'               => max( 0.0, min( 1.0, (float) ( $intent['confidence'] ?? 0 ) ) ),
			'reasons'                  => array_values( array_unique( $reasons ) ),
			'requires_user_confirmation' => $requires_confirmation,
			'goal_present'             => $goal_text !== '',
			'required_input_count'     => count( (array) ( $draft['required_inputs'] ?? $goal['required_inputs'] ?? array() ) ),
		);
	}

	private static function normalize_intent_id( string $intent_id ): string {
		$intent_id = strtolower( trim( $intent_id ) );
		return (string) preg_replace( '/[^a-z0-9_.-]+/', '', $intent_id );
	}
}
