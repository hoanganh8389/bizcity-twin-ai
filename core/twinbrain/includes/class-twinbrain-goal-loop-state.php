<?php
/**
 * Deterministic state contract for Twin Goal Loop.
 *
 * This class does not call LLMs or persist data. It normalizes state and guards
 * lifecycle transitions before event-sourced persistence is introduced in G1.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_State {

	const ACTIVE_STATUSES = array( 'clarifying', 'planning', 'executing', 'verifying' );
	const RESUMABLE_STATUSES = array( 'paused', 'blocked' );
	const TERMINAL_STATUSES = array( 'completed', 'cancelled', 'abandoned', 'superseded' );

	const CLOSURE_USER_CONFIRMED = 'user_confirmed';
	const CLOSURE_USER_COMPLETED = 'user_completed';
	const CLOSURE_SESSION_CLOSED = 'session_closed';
	const CLOSURE_USER_CANCELLED = 'user_cancelled';
	const CLOSURE_INACTIVITY = 'inactivity_timeout';
	const CLOSURE_SUPERSEDED = 'superseded_by_goal';

	/**
	 * @return array<string,mixed>
	 */
	public static function normalize( array $state ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — normalize one cross-surface goal state without persistence.
		$status = sanitize_key( (string) ( $state['status'] ?? 'clarifying' ) );
		if ( ! self::is_status( $status ) ) {
			$status = 'clarifying';
		}
		$score = isset( $state['completion_score'] ) ? (float) $state['completion_score'] : 0.0;
		$score = max( 0.0, min( 1.0, $score ) );

		return array(
			'goal_id'            => self::sanitize_id( (string) ( $state['goal_id'] ?? '' ), 'goal' ),
			'blog_id'            => max( 0, (int) ( $state['blog_id'] ?? get_current_blog_id() ) ),
			'identity_uuid'      => strtolower( sanitize_text_field( (string) ( $state['identity_uuid'] ?? '' ) ) ),
			'session_id'         => sanitize_text_field( (string) ( $state['session_id'] ?? '' ) ),
			// [2026-08-03 Johnny Chu] R-TGL-CS — owner identity and case memory scope are separate state axes.
			'memory_scope'       => self::normalize_memory_scope( (string) ( $state['memory_scope'] ?? '' ) ),
			'case_id'            => self::sanitize_id( (string) ( $state['case_id'] ?? '' ), 'case' ),
			'subject_key'        => self::sanitize_scope_key( (string) ( $state['subject_key'] ?? '' ) ),
			'case_scope_state'   => in_array( sanitize_key( (string) ( $state['case_scope_state'] ?? '' ) ), array( 'resolved', 'unresolved', 'not_required' ), true ) ? sanitize_key( (string) $state['case_scope_state'] ) : '',
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P1 — retain the origin session as canonical state metadata across cross-session resume.
			'root_session_id'    => sanitize_text_field( (string) ( $state['root_session_id'] ?? $state['session_id'] ?? '' ) ),
			'parent_goal_id'     => self::sanitize_id( (string) ( $state['parent_goal_id'] ?? '' ), 'goal' ),
			'primary_goal'       => sanitize_text_field( (string) ( $state['primary_goal'] ?? '' ) ),
			'user_intent_current'=> sanitize_text_field( (string) ( $state['user_intent_current'] ?? '' ) ),
			'goal_draft'         => self::normalize_goal_draft( $state['goal_draft'] ?? null ),
			// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — persist the per-turn answer contract inside the existing event-sourced Goal State.
			'conversation_goal'  => self::normalize_conversation_goal( $state['conversation_goal'] ?? null ),
			'answer_obligations' => self::normalize_answer_obligations( (array) ( $state['answer_obligations'] ?? array() ) ),
			'resolution_scoreboard' => self::normalize_resolution_scoreboard( $state['resolution_scoreboard'] ?? null ),
			'objectives'         => self::normalize_items( (array) ( $state['objectives'] ?? array() ) ),
			'required_inputs'    => self::normalize_items( (array) ( $state['required_inputs'] ?? array() ) ),
			'definition_of_done' => self::normalize_items( (array) ( $state['definition_of_done'] ?? array() ) ),
			'completed_outputs'  => self::normalize_items( (array) ( $state['completed_outputs'] ?? array() ) ),
			'open_loops'         => self::normalize_items( (array) ( $state['open_loops'] ?? array() ) ),
			'blockers'           => self::normalize_items( (array) ( $state['blockers'] ?? array() ) ),
			'gaps'               => self::normalize_gaps( (array) ( $state['gaps'] ?? array() ) ),
			'spiral_turns'       => max( 0, min( 1000, (int) ( $state['spiral_turns'] ?? 0 ) ) ),
			'next_best_action'   => self::normalize_action( $state['next_best_action'] ?? null ),
			'roadmap_progress'   => self::normalize_items( (array) ( $state['roadmap_progress'] ?? array() ) ),
			'status'             => $status,
			'completion_score'   => $score,
			'closure_signal'     => self::normalize_closure_signal( $state['closure_signal'] ?? null ),
			'updated_at'         => self::normalize_timestamp( (string) ( $state['updated_at'] ?? '' ) ),
		);
	}

	public static function can_transition( string $from, string $to, array $state = array() ): bool {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — fail closed for invalid or unsupported lifecycle transitions.
		$from = sanitize_key( $from );
		$to = sanitize_key( $to );
		if ( ! self::is_status( $from ) || ! self::is_status( $to ) ) {
			return false;
		}
		if ( $from === $to ) {
			return true;
		}
		if ( in_array( $from, self::TERMINAL_STATUSES, true ) ) {
			return false;
		}
		if ( $to === 'completed' ) {
			return self::is_completion_ready( self::normalize( $state ) );
		}
		if ( $to === 'abandoned' ) {
			$signal = self::normalize_closure_signal( $state['closure_signal'] ?? null );
			return is_array( $signal ) && ( $signal['type'] ?? '' ) === self::CLOSURE_INACTIVITY;
		}
		if ( $to === 'cancelled' ) {
			$signal = self::normalize_closure_signal( $state['closure_signal'] ?? null );
			return is_array( $signal ) && ( $signal['type'] ?? '' ) === self::CLOSURE_USER_CANCELLED;
		}
		if ( $to === 'superseded' ) {
			$signal = self::normalize_closure_signal( $state['closure_signal'] ?? null );
			return is_array( $signal ) && ( $signal['type'] ?? '' ) === self::CLOSURE_SUPERSEDED;
		}
		return true;
	}

	public static function is_completion_ready( array $state ): bool {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — require DoD evidence and closure before completed.
		$state = self::normalize( $state );
		if ( empty( $state['definition_of_done'] ) || ! empty( $state['blockers'] ) || $state['completion_score'] < 1.0 ) {
			return false;
		}
		foreach ( $state['definition_of_done'] as $item ) {
			if ( ( $item['status'] ?? '' ) !== 'done' || empty( $item['evidence'] ) ) {
				return false;
			}
		}
		$signal = $state['closure_signal'];
		return is_array( $signal ) && in_array( (string) ( $signal['type'] ?? '' ), array(
			self::CLOSURE_USER_CONFIRMED,
			self::CLOSURE_USER_COMPLETED,
			self::CLOSURE_SESSION_CLOSED,
		), true );
	}

	public static function is_terminal( string $status ): bool {
		return in_array( sanitize_key( $status ), self::TERMINAL_STATUSES, true );
	}

	/**
	 * Decide what TwinBrain must do before opening a chat session.
	 *
	 * @return array{action:string,goal_id:string,reason:string}
	 */
	public static function session_start_decision( array $active_goal, string $requested_session_id, string $user_choice = '' ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G0 — never silently abandon or cross-carry an unfinished goal.
		$state = self::normalize( $active_goal );
		$choice = sanitize_key( $user_choice );
		if ( $state['goal_id'] === '' || self::is_terminal( $state['status'] ) ) {
			return array( 'action' => 'open_new', 'goal_id' => '', 'reason' => 'no_active_goal' );
		}
		if ( $choice === 'resume' ) {
			return array( 'action' => 'resume', 'goal_id' => $state['goal_id'], 'reason' => 'user_selected_resume' );
		}
		if ( $choice === 'new' ) {
			return array( 'action' => 'open_new', 'goal_id' => $state['goal_id'], 'reason' => 'user_selected_new_keep_old_paused' );
		}
		if ( $choice === 'replace' ) {
			return array( 'action' => 'supersede', 'goal_id' => $state['goal_id'], 'reason' => 'user_selected_replace' );
		}
		if ( $requested_session_id !== '' && $requested_session_id === $state['session_id'] ) {
			return array( 'action' => 'resume', 'goal_id' => $state['goal_id'], 'reason' => 'same_session' );
		}
		return array( 'action' => 'ask_resume_or_new', 'goal_id' => $state['goal_id'], 'reason' => 'active_goal_in_another_session' );
	}

	private static function is_status( string $status ): bool {
		return in_array( $status, array_merge( self::ACTIVE_STATUSES, self::RESUMABLE_STATUSES, self::TERMINAL_STATUSES ), true );
	}

	private static function normalize_memory_scope( string $scope ): string {
		$scope = sanitize_key( $scope );
		return in_array( $scope, array( 'goal_case', 'goal_owner', 'identity_global' ), true ) ? $scope : '';
	}

	private static function sanitize_scope_key( string $value ): string {
		return substr( preg_replace( '/[^a-zA-Z0-9_.:-]/', '', trim( $value ) ), 0, 128 );
	}

	private static function sanitize_id( string $value, string $prefix ): string {
		$value = preg_replace( '/[^a-zA-Z0-9_-]/', '', trim( $value ) );
		if ( $value === '' ) {
			return '';
		}
		return strpos( $value, $prefix . '_' ) === 0 ? $value : $prefix . '_' . $value;
	}

	private static function normalize_items( array $items ): array {
		$out = array();
		foreach ( array_slice( $items, 0, 100 ) as $index => $item ) {
			if ( is_string( $item ) ) {
				$item = array( 'label' => $item );
			}
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item['label'] ?? $item['text'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$status = sanitize_key( (string) ( $item['status'] ?? 'pending' ) );
			if ( ! in_array( $status, array( 'pending', 'in_progress', 'done', 'blocked', 'skipped' ), true ) ) {
				$status = 'pending';
			}
			$evidence = array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $item['evidence'] ?? array() ), 0, 20 ) ) ) );
			$out[] = array(
				'id'         => self::sanitize_id( (string) ( $item['id'] ?? ( 'item_' . ( $index + 1 ) ) ), 'item' ),
				'label'      => $label,
				'status'     => $status,
				'evidence'   => $evidence,
				'updated_at' => self::normalize_timestamp( (string) ( $item['updated_at'] ?? '' ) ),
			);
		}
		return $out;
	}

	private static function normalize_goal_draft( $draft ) {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8 — preserve deterministic parsed intent in the event snapshot contract.
		if ( ! is_array( $draft ) || empty( $draft ) ) {
			return null;
		}
		$subject = array();
		foreach ( array( 'who', 'age', 'weight_kg' ) as $key ) {
			if ( isset( $draft['subject'][ $key ] ) && is_scalar( $draft['subject'][ $key ] ) ) {
				$subject[ $key ] = sanitize_text_field( (string) $draft['subject'][ $key ] );
			}
		}
		$constraints = array();
		foreach ( array_slice( (array) ( $draft['constraints'] ?? array() ), 0, 30 ) as $constraint ) {
			if ( ! is_array( $constraint ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $constraint['label'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$constraints[] = array(
				'kind'   => sanitize_key( (string) ( $constraint['kind'] ?? 'constraint' ) ),
				'label'  => $label,
				'source' => sanitize_key( (string) ( $constraint['source'] ?? 'user_stated' ) ),
			);
		}
		return array(
			'primary_goal'   => sanitize_text_field( (string) ( $draft['primary_goal'] ?? '' ) ),
			'subject'        => $subject,
			'constraints'    => $constraints,
			'objectives'     => self::normalize_items( (array) ( $draft['objectives'] ?? array() ) ),
			'required_inputs'=> self::normalize_items( (array) ( $draft['required_inputs'] ?? array() ) ),
			'reason'         => sanitize_key( (string) ( $draft['reason'] ?? '' ) ),
		);
	}

	private static function normalize_conversation_goal( $goal ) {
		if ( ! is_array( $goal ) || empty( $goal ) ) {
			return null;
		}
		$mode = sanitize_key( (string) ( $goal['conversation_mode'] ?? 'consulting' ) );
		if ( ! in_array( $mode, array( 'consulting', 'fact_lookup', 'comparison', 'troubleshooting', 'task_execution', 'casual' ), true ) ) {
			$mode = 'consulting';
		}
		return array(
			'user_outcome'       => sanitize_text_field( (string) ( $goal['user_outcome'] ?? '' ) ),
			'conversation_mode'  => $mode,
			'decision_required'  => ! empty( $goal['decision_required'] ),
			'closure_condition'  => sanitize_text_field( (string) ( $goal['closure_condition'] ?? '' ) ),
		);
	}

	private static function normalize_answer_obligations( array $items ): array {
		$out = array();
		$allowed_types = array( 'recommendation', 'fact', 'risk_explanation', 'capability', 'comparison', 'explanation', 'implicit_concern', 'desired_outcome' );
		$allowed_priorities = array( 'must', 'should', 'nice_to_have' );
		$allowed_statuses = array( 'open', 'answered', 'deferred' );
		foreach ( array_slice( $items, 0, 50 ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = sanitize_text_field( (string) ( $item['question'] ?? $item['label'] ?? '' ) );
			if ( $question === '' ) {
				continue;
			}
			$type = sanitize_key( (string) ( $item['type'] ?? 'explanation' ) );
			$priority = sanitize_key( (string) ( $item['priority'] ?? 'must' ) );
			$status = sanitize_key( (string) ( $item['status'] ?? 'open' ) );
			$out[] = array(
				'id'         => sanitize_key( (string) ( $item['id'] ?? 'q' . ( $index + 1 ) ) ),
				'question'   => $question,
				'type'       => in_array( $type, $allowed_types, true ) ? $type : 'explanation',
				'priority'   => in_array( $priority, $allowed_priorities, true ) ? $priority : 'must',
				'status'     => in_array( $status, $allowed_statuses, true ) ? $status : 'open',
				'updated_at' => self::normalize_timestamp( (string) ( $item['updated_at'] ?? '' ) ),
			);
		}
		return $out;
	}

	private static function normalize_resolution_scoreboard( $scoreboard ) {
		if ( ! is_array( $scoreboard ) || empty( $scoreboard ) ) {
			return null;
		}
		$rows = array();
		foreach ( array_slice( (array) ( $scoreboard['rows'] ?? array() ), 0, 50 ) as $row ) {
			if ( ! is_array( $row ) || trim( (string) ( $row['obligation_id'] ?? '' ) ) === '' ) {
				continue;
			}
			$route = strtoupper( sanitize_key( (string) ( $row['route'] ?? 'PATCH' ) ) );
			if ( ! in_array( $route, array( 'PASS', 'PATCH', 'RETRIEVE' ), true ) ) {
				$route = 'PATCH';
			}
			$refs = array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $row['evidence_ref'] ?? array() ), 0, 20 ) ) ) );
			$rows[] = array(
				'obligation_id' => sanitize_key( (string) $row['obligation_id'] ),
				'coverage'      => max( 0.0, min( 1.0, round( (float) ( $row['coverage'] ?? 0 ), 2 ) ) ),
				'evidence_ref'  => $refs,
				'gap'           => sanitize_text_field( (string) ( $row['gap'] ?? '' ) ),
				'route'         => $route,
			);
		}
		return array(
			'scoreboard_version'     => sanitize_key( (string) ( $scoreboard['scoreboard_version'] ?? 'v1' ) ),
			'rows'                   => $rows,
			'overall_ready_for_final'=> ! empty( $scoreboard['overall_ready_for_final'] ),
			'retrieve_round'         => max( 0, min( 100, (int) ( $scoreboard['retrieve_round'] ?? 0 ) ) ),
			'method'                 => sanitize_key( (string) ( $scoreboard['method'] ?? 'deterministic_v1' ) ),
		);
	}

	private static function normalize_gaps( array $gaps ): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G9 — keep continuity gaps bounded and event-sourced.
		$out = array();
		foreach ( array_slice( $gaps, 0, 50 ) as $index => $gap ) {
			if ( ! is_array( $gap ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $gap['label'] ?? '' ) );
			if ( $label === '' ) {
				continue;
			}
			$kind = sanitize_key( (string) ( $gap['kind'] ?? 'missing_input' ) );
			if ( ! in_array( $kind, array( 'missing_input', 'weak_evidence', 'ambiguous_objective', 'unverified_claim' ), true ) ) {
				$kind = 'missing_input';
			}
			$status = sanitize_key( (string) ( $gap['status'] ?? 'open' ) );
			if ( ! in_array( $status, array( 'open', 'in_progress', 'resolved', 'skipped' ), true ) ) {
				$status = 'open';
			}
			$out[] = array(
				'id'              => self::sanitize_id( (string) ( $gap['id'] ?? ( 'gap_' . ( $index + 1 ) ) ), 'gap' ),
				'kind'            => $kind,
				'label'           => $label,
				'status'          => $status,
				'evidence_needed' => sanitize_text_field( (string) ( $gap['evidence_needed'] ?? '' ) ),
				'evidence'        => array_values( array_filter( array_map( 'sanitize_text_field', array_slice( (array) ( $gap['evidence'] ?? array() ), 0, 20 ) ) ) ),
				'updated_at'      => self::normalize_timestamp( (string) ( $gap['updated_at'] ?? '' ) ),
			);
		}
		return $out;
	}

	private static function normalize_action( $action ) {
		if ( ! is_array( $action ) ) {
			$label = sanitize_text_field( (string) $action );
			return $label === '' ? null : array( 'type' => 'respond', 'label' => $label );
		}
		$label = sanitize_text_field( (string) ( $action['label'] ?? '' ) );
		if ( $label === '' ) {
			return null;
		}
		return array(
			'type'  => sanitize_key( (string) ( $action['type'] ?? 'respond' ) ),
			'label' => $label,
			'ref'   => sanitize_text_field( (string) ( $action['ref'] ?? '' ) ),
			'question_text' => sanitize_text_field( (string) ( $action['question_text'] ?? '' ) ),
			'kind'  => sanitize_key( (string) ( $action['kind'] ?? '' ) ),
			'target_field' => sanitize_text_field( (string) ( $action['target_field'] ?? '' ) ),
		);
	}

	private static function normalize_closure_signal( $signal ) {
		if ( ! is_array( $signal ) ) {
			return null;
		}
		$type = sanitize_key( (string) ( $signal['type'] ?? '' ) );
		$allowed = array(
			self::CLOSURE_USER_CONFIRMED,
			self::CLOSURE_USER_COMPLETED,
			self::CLOSURE_SESSION_CLOSED,
			self::CLOSURE_USER_CANCELLED,
			self::CLOSURE_INACTIVITY,
			self::CLOSURE_SUPERSEDED,
		);
		if ( ! in_array( $type, $allowed, true ) ) {
			return null;
		}
		return array(
			'type'       => $type,
			'evidence'   => sanitize_text_field( (string) ( $signal['evidence'] ?? '' ) ),
			'created_at' => self::normalize_timestamp( (string) ( $signal['created_at'] ?? '' ) ),
		);
	}

	private static function normalize_timestamp( string $value ): string {
		$timestamp = $value !== '' ? strtotime( $value ) : false;
		return gmdate( 'c', $timestamp ? $timestamp : time() );
	}
}
