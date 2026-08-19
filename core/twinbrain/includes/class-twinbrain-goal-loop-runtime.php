<?php
/**
 * Twin Goal Loop runtime hooks for vertical chat.
 *
 * G1 is deterministic and fail-open: it resolves existing snapshots and adds
 * compact metadata to a turn, but does not call an LLM evaluator yet.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Runtime {

	public static function pre_turn( string $prompt, array $opts ): array {
		// [2026-08-15 Johnny Chu] MPR-V5-GOAL-TRACE — preserve the Goal Session action so Runtime can emit one canonical timeline milestone.
		$opts['goal_loop_session_action'] = (string) ( $opts['goal_loop_session_action'] ?? '' );
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — resolve an existing Goal Brief before vertical routing.
		$identity = self::identity( $opts );
		if ( ! $identity['ready'] || ! class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' ) ) {
			return $opts;
		}
		if ( class_exists( 'BizCity_TwinBrain_Goal_Contract_Store' ) && method_exists( 'BizCity_TwinBrain_Goal_Contract_Store', 'get_by_scope' ) ) {
			// [2026-08-03 Johnny Chu] G12.3 — expose the indexed contract projection to MPR/Diagnostics; lifecycle and DoD still come from Repository::latest().
			$contract_projection = BizCity_TwinBrain_Goal_Contract_Store::get_by_scope( $identity['blog_id'], $identity['identity_uuid'], $identity['session_id'] );
			if ( ! empty( $contract_projection ) ) {
				$opts['goal_contract_projection'] = $contract_projection;
			}
		}
		// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — parse the turn before Goal bootstrap so direct questions/implicit concerns can open an answer contract without relying on legacy goal phrases.
		$turn_draft = class_exists( 'BizCity_TwinBrain_Goal_Loop_Parser' )
			? BizCity_TwinBrain_Goal_Loop_Parser::parse( $prompt, $opts )
			: array();
		$goal = BizCity_TwinBrain_Goal_Loop_Repository::latest( $identity['blog_id'], $identity['identity_uuid'], $identity['session_id'] );
		if ( empty( $goal ) && method_exists( 'BizCity_TwinBrain_Goal_Loop_Repository', 'latest_active_by_identity' ) ) {
			// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — resume an active goal created by another channel/session.
			$active_goal = BizCity_TwinBrain_Goal_Loop_Repository::latest_active_by_identity( $identity['blog_id'], $identity['identity_uuid'] );
			if ( ! empty( $active_goal ) ) {
				$decision = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
					? BizCity_TwinBrain_Goal_Loop_State::session_start_decision( $active_goal, $identity['session_id'], (string) ( $opts['goal_loop_choice'] ?? '' ) )
					: array( 'action' => 'ask_resume_or_new' );
				$channel_class = (string) ( $opts['subject_contract']['channel_class'] ?? '' );
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — guest and member surfaces both require an explicit choice before cross-session rebase; never silently resume a goal.
				if ( ( $decision['action'] ?? '' ) === 'resume' ) {
					$source_session_id = (string) ( $active_goal['session_id'] ?? '' );
					$active_goal['session_id'] = $identity['session_id'];
					$active_goal['identity_uuid'] = $identity['identity_uuid'];
					$goal = $active_goal;
					$opts['goal_loop_resume_from_session_id'] = $source_session_id;
					$opts['goal_loop_session_action'] = 'resumed';
				} elseif ( in_array( (string) ( $decision['action'] ?? '' ), array( 'supersede', 'open_new' ), true ) && in_array( (string) ( $opts['goal_loop_choice'] ?? '' ), array( 'new', 'replace' ), true ) ) {
					// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-P0 — make New/Replace an audited transition before bootstrapping another goal; never leave two ambiguous active goals by accident.
					$old_goal = $active_goal;
					$old_session_id = (string) ( $old_goal['session_id'] ?? '' );
					if ( $old_session_id === '' ) {
						$opts['goal_loop_choice_error'] = 'active_goal_session_missing';
						return $opts;
					}
					$old_goal['session_id'] = $old_session_id;
					if ( (string) ( $opts['goal_loop_choice'] ?? '' ) === 'replace' ) {
						$old_goal['status'] = 'superseded';
						$old_goal['closure_signal'] = array(
							'type'       => BizCity_TwinBrain_Goal_Loop_State::CLOSURE_SUPERSEDED,
							'evidence'   => 'user_selected_replace',
							'created_at' => gmdate( 'c' ),
						);
						$transition_uuid = BizCity_TwinBrain_Goal_Loop_Repository::close( $old_goal, array(
							'identity_uuid' => $identity['identity_uuid'],
							'blog_id'       => $identity['blog_id'],
							'user_id'       => (int) ( $opts['user_id'] ?? 0 ),
							'session_id'    => $old_session_id,
							'trace_id'      => (string) ( $opts['trace_id'] ?? '' ),
							'event_source'  => 'twinbrain', // [2026-08-02 Johnny Chu] HOTFIX — use taxonomy-approved source so Goal Loop choice events persist.
							'platform'      => (string) ( $opts['platform'] ?? $opts['channel'] ?? '' ),
							'account_id'    => (string) ( $opts['account_id'] ?? '' ),
							'external_user_id' => (string) ( $opts['external_user_id'] ?? '' ),
							'chat_id'       => (string) ( $opts['chat_id'] ?? '' ),
						) );
					} else {
						$old_goal['status'] = 'paused';
						$old_goal['closure_signal'] = array(
							'type'       => BizCity_TwinBrain_Goal_Loop_State::CLOSURE_SESSION_CLOSED,
							'evidence'   => 'user_selected_new',
							'created_at' => gmdate( 'c' ),
						);
						$transition_uuid = BizCity_TwinBrain_Goal_Loop_Repository::progress( $old_goal, array(
							'identity_uuid' => $identity['identity_uuid'],
							'blog_id'       => $identity['blog_id'],
							'user_id'       => (int) ( $opts['user_id'] ?? 0 ),
							'session_id'    => $old_session_id,
							'trace_id'      => (string) ( $opts['trace_id'] ?? '' ),
							'event_source'  => 'twinbrain', // [2026-08-02 Johnny Chu] HOTFIX — use taxonomy-approved source so Goal Loop choice events persist.
							'platform'      => (string) ( $opts['platform'] ?? $opts['channel'] ?? '' ),
							'account_id'    => (string) ( $opts['account_id'] ?? '' ),
							'external_user_id' => (string) ( $opts['external_user_id'] ?? '' ),
							'chat_id'       => (string) ( $opts['chat_id'] ?? '' ),
						) );
					}
					if ( (string) $transition_uuid === '' ) {
						$opts['goal_loop_choice_error'] = 'active_goal_transition_failed';
						$opts['goal_loop_state'] = $active_goal;
						return $opts;
					}
					$goal = array();
				} elseif ( (string) ( $decision['action'] ?? '' ) === 'ask_resume_or_new' ) {
					// [2026-08-16 Johnny Chu] MPR-V5-GOAL-SESSION — block cross-session carry until the user explicitly chooses resume/new/replace.
					$opts['goal_loop_blocked'] = array(
						'goal_id'     => (string) ( $decision['goal_id'] ?? $active_goal['goal_id'] ?? '' ),
						'action'      => 'blocked',
						'reason_code' => (string) ( $decision['reason'] ?? 'active_goal_in_another_session' ),
					);
					return $opts;
				}
			}
		}
		if ( empty( $goal ) && class_exists( 'BizCity_Intent_Conversation' ) && class_exists( 'BizCity_TwinBrain_Goal_Loop_Intent_Adapter' ) ) {
			// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — read legacy Intent state only as a compatibility source until its event snapshot exists.
			try {
				$conversation = BizCity_Intent_Conversation::instance()->get_active(
					(int) ( $opts['user_id'] ?? 0 ),
					(string) ( $opts['channel'] ?? $opts['platform'] ?? 'twinchat' ),
					$identity['session_id']
				);
				if ( is_array( $conversation ) && ! empty( $conversation['conversation_id'] ) && ! empty( $conversation['goal'] ) ) {
					$goal = BizCity_TwinBrain_Goal_Loop_Intent_Adapter::from_conversation( $conversation, array_merge( $opts, $identity ) );
				}
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain][goal-loop] Intent compatibility read skipped: ' . get_class( $e ) . ' ' . $e->getMessage() );
			}
		}
		if ( empty( $goal ) ) {
			$goal = self::bootstrap_goal( $prompt, $opts, $identity, $turn_draft );
		}
		if ( ! empty( $goal ) && class_exists( 'BizCity_TwinBrain_Goal_Loop_Parser' ) ) {
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8 — attach deterministic Goal Draft facts before the turn is composed.
			$draft = $turn_draft;
			if ( ! empty( $draft ) ) {
				$goal['goal_draft'] = $draft;
				// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — freeze the per-turn Conversation Goal and Answer Obligations before Memory/MPR starts.
				if ( ! empty( $draft['conversation_goal'] ) && is_array( $draft['conversation_goal'] ) ) {
					$goal['conversation_goal'] = $draft['conversation_goal'];
				}
				if ( ! empty( $draft['answer_obligations'] ) && is_array( $draft['answer_obligations'] ) ) {
					$goal['answer_obligations'] = self::merge_items( (array) ( $goal['answer_obligations'] ?? array() ), (array) $draft['answer_obligations'] );
					$goal['resolution_scoreboard'] = array(
						'scoreboard_version' => 'v1',
						'rows' => array(),
						'overall_ready_for_final' => false,
						'retrieve_round' => 0,
						'method' => 'pending_reflection',
					);
				}
				if ( empty( $goal['primary_goal'] ) && ! empty( $draft['primary_goal'] ) ) {
					$goal['primary_goal'] = $draft['primary_goal'];
				}
				if ( ! empty( $draft['objectives'] ) ) {
					$goal['objectives'] = $draft['objectives'];
				}
				if ( ! empty( $draft['required_inputs'] ) ) {
					$goal['required_inputs'] = self::merge_items( (array) ( $goal['required_inputs'] ?? array() ), (array) $draft['required_inputs'] );
				}
			}
		}
		// [2026-08-03 Johnny Chu] R-TGL-CS — resolve case scope before the first
		// goal snapshot; never derive a child identity from clinical facts alone.
		if ( ! empty( $goal ) ) {
			$goal = self::apply_case_scope( $goal, $opts, $identity );
		}
		if ( ! empty( $goal ) && empty( $goal['_event_type'] ) ) {
			$event_uuid = BizCity_TwinBrain_Goal_Loop_Repository::open( $goal, array(
					'identity_uuid' => $identity['identity_uuid'],
					'blog_id' => $identity['blog_id'],
					'user_id' => (int) ( $opts['user_id'] ?? 0 ),
					'session_id' => $identity['session_id'],
					'trace_id' => (string) ( $opts['trace_id'] ?? '' ),
					'event_source' => 'twinbrain', // [2026-08-02 Johnny Chu] HOTFIX — use taxonomy-approved source so bootstrap reaches JSONL trace.
					'platform' => (string) ( $opts['platform'] ?? $opts['channel'] ?? '' ),
					'account_id' => (string) ( $opts['account_id'] ?? '' ),
					'external_user_id' => (string) ( $opts['external_user_id'] ?? '' ),
					'chat_id' => (string) ( $opts['chat_id'] ?? '' ),
				) );
			if ( $event_uuid === '' ) {
				$goal = array();
			} else {
				$opts['goal_loop_session_action'] = 'opened';
			}
		}
		if ( empty( $goal ) ) {
			return $opts;
		}
		$opts['goal_loop_state'] = $goal;
		$opts['goal_loop'] = $goal;
		$opts['goal_loop_brief'] = self::brief( $goal );
		return $opts;
	}

	private static function apply_case_scope( array $goal, array $opts, array $identity ): array {
		$scope = sanitize_key( (string) ( $opts['memory_scope'] ?? $goal['memory_scope'] ?? '' ) );
		$subject_key = sanitize_text_field( (string) ( $opts['subject_key'] ?? $goal['subject_key'] ?? '' ) );
		$case_id = sanitize_text_field( (string) ( $opts['case_id'] ?? $goal['case_id'] ?? '' ) );
		if ( $scope === '' && ( $case_id !== '' || $subject_key !== '' ) ) {
			$scope = 'goal_case';
		}
		if ( $scope !== 'goal_case' ) {
			if ( $scope !== '' ) {
				$goal['memory_scope'] = in_array( $scope, array( 'goal_owner', 'identity_global' ), true ) ? $scope : '';
			}
			return $goal;
		}
		$goal['memory_scope'] = 'goal_case';
		$goal['subject_key'] = $subject_key;
		if ( $case_id === '' && $subject_key !== '' && ! empty( $identity['identity_uuid'] ) ) {
			$case_id = 'case_' . substr( sha1( strtolower( $identity['identity_uuid'] ) . '|' . $subject_key ), 0, 24 );
		}
		$goal['case_id'] = $case_id;
		$goal['case_scope_state'] = ( $case_id !== '' && $subject_key !== '' ) ? 'resolved' : 'unresolved';
		if ( $goal['case_scope_state'] === 'unresolved' ) {
			$goal['status'] = 'clarifying';
			$goal['next_best_action'] = array(
				'type' => 'ask_clarify',
				'label' => 'Xác định đúng hồ sơ đang được tư vấn trước khi dùng memory của ca này',
				'target_field' => 'subject_key',
			);
		}
		return $goal;
	}

	public static function post_turn( string $prompt, array $result, array $opts ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — deterministic post-turn progress metadata; evaluator remains deferred.
		$identity = self::identity( $opts );
		$goal = isset( $opts['goal_loop_state'] ) && is_array( $opts['goal_loop_state'] ) ? $opts['goal_loop_state'] : array();
		if ( ! $identity['ready'] || empty( $goal['goal_id'] ) ) {
			return $result;
		}
		$progress = $goal;
		$event_uuid = '';
		$reflection = array();
		// [2026-08-07 Johnny Chu] V4-DEPTH — fast is globally reflection-free,
		// including the post-turn projection. Final Gate already records the
		// authoritative skipped reflection state for this turn.
		$answer_depth = sanitize_key( (string) ( $opts['answer_depth'] ?? 'high' ) );
		$post_reflection_enabled = $answer_depth !== 'fast';
		if ( class_exists( 'BizCity_TwinBrain_Goal_Loop_Repository' ) ) {
			$trace_id = sanitize_text_field( (string) ( $result['trace_id'] ?? $opts['trace_id'] ?? '' ) );
			$patch = class_exists( 'BizCity_TwinBrain_Goal_Loop_Delta' )
				? BizCity_TwinBrain_Goal_Loop_Delta::compute( $goal, $prompt, $result, $opts )
				: array( 'user_intent_current' => sanitize_text_field( $prompt ) );
			$progress = class_exists( 'BizCity_TwinBrain_Goal_Loop_Delta' )
				? BizCity_TwinBrain_Goal_Loop_Delta::apply( $goal, $patch )
				: array_merge( $goal, $patch );
			if ( $post_reflection_enabled && class_exists( 'BizCity_TwinBrain_Goal_Loop_Reflector' ) ) {
				// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8 — reflect against current DoD/evidence without blocking the assistant answer.
				$reflection = BizCity_TwinBrain_Goal_Loop_Reflector::reflect( $progress, $prompt, $result, $opts );
				$progress = class_exists( 'BizCity_TwinBrain_Goal_Loop_Delta' )
					? BizCity_TwinBrain_Goal_Loop_Delta::apply( $progress, $reflection )
					: array_merge( $progress, $reflection );
			}
			if ( class_exists( 'BizCity_TwinBrain_Goal_Loop_Question_Engine' ) ) {
				$next_question = BizCity_TwinBrain_Goal_Loop_Question_Engine::next_question( $progress );
				if ( is_array( $next_question ) ) {
					$progress['next_best_action'] = $next_question;
				}
			}
			// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G10 — expose whether the deterministic delta was actually persisted instead of reporting an in-memory state as durable.
			$event_uuid = BizCity_TwinBrain_Goal_Loop_Repository::progress( $progress, array(
				'identity_uuid' => $identity['identity_uuid'],
				'blog_id' => $identity['blog_id'],
				'user_id' => (int) ( $opts['user_id'] ?? 0 ),
				'session_id' => $identity['session_id'],
				'trace_id' => $trace_id,
				'event_source' => 'twinbrain',
				'platform' => (string) ( $opts['platform'] ?? $opts['channel'] ?? '' ),
				'account_id' => (string) ( $opts['account_id'] ?? '' ),
				'external_user_id' => (string) ( $opts['external_user_id'] ?? '' ),
				'chat_id' => (string) ( $opts['chat_id'] ?? '' ),
				// [2026-08-03 Johnny Chu] G12.5 — pass the already-computed reflection result to the trace boundary; it remains advisory and never controls terminal status.
				'reflection_result' => $reflection,
				'turn_id' => (string) ( $opts['turn_id'] ?? $trace_id ),
			) );
		}
		$result['goal_loop'] = array(
			'phase' => 'post_turn_deterministic',
			'goal_id' => (string) ( $progress['goal_id'] ?? $goal['goal_id'] ?? '' ),
			'goal_state' => $progress,
			// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — keep per-obligation reflection visible in the existing close frame; this is not a second persistence store.
			'conversation_goal' => $progress['conversation_goal'] ?? null,
			'answer_obligations' => (array) ( $progress['answer_obligations'] ?? array() ),
			'resolution_scoreboard' => $progress['resolution_scoreboard'] ?? null,
			'final_gate' => (array) ( $result['final_gate'] ?? array() ),
			'answer_depth' => $answer_depth,
			'reflection_skipped' => ! $post_reflection_enabled,
			'next_best_action' => $progress['next_best_action'] ?? null,
			'open_loops' => (array) ( $progress['open_loops'] ?? array() ),
			'gaps' => (array) ( $progress['gaps'] ?? array() ),
			'completion_score' => (float) ( $progress['completion_score'] ?? 0 ),
			'persisted' => $event_uuid !== '',
			'event_id' => (int) ( $progress['_event_id'] ?? 0 ),
			'event_uuid' => $event_uuid,
			'persistence_error' => $event_uuid === '' ? 'event_write_rejected' : '',
			'evaluator' => class_exists( 'BizCity_TwinBrain_Goal_Loop_Reflector' ) ? 'deterministic_v1' : 'deferred',
		);
		return $result;
	}

	private static function merge_items( array $existing, array $incoming ): array {
		$merged = array();
		foreach ( array_merge( $existing, $incoming ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = (string) ( $item['id'] ?? '' );
			if ( $id === '' ) {
				continue;
			}
			if ( isset( $merged[ $id ] ) && (string) ( $merged[ $id ]['status'] ?? '' ) === 'done' ) {
				continue;
			}
			$merged[ $id ] = $item;
		}
		return array_values( $merged );
	}

	/**
	 * Build a first goal only for an explicit request/task signal.
	 *
	 * @return array<string,mixed>
	 */
	private static function bootstrap_goal( string $prompt, array $opts, array $identity, array $draft = array() ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G4 — bootstrap only explicit goals, never greetings or small-talk.
		$text = trim( preg_replace( '/\s+/', ' ', sanitize_text_field( $prompt ) ) );
		if ( $text === '' || mb_strlen( $text ) < 8 || self::is_small_talk( $text ) ) {
			return array();
		}
		$signal = strtolower( trim( (string) ( $opts['goal_signal'] ?? $opts['goal_intent'] ?? $opts['answer_intent'] ?? $opts['intent'] ?? '' ) ) );
		$has_signal = $signal !== '' && in_array( $signal, array( 'goal', 'goal_statement', 'task', 'request', 'action_request', 'planning' ), true );
		$has_phrase = (bool) preg_match( '/\b(tôi muốn|mình muốn|em muốn|anh muốn|chị muốn|muốn|cần|hãy|giúp|tư vấn|kiểm tra|đặt|mua|đăng|tích điểm|làm sao)\b/ui', $text );
		$has_answer_contract = ! empty( $draft['answer_obligations'] );
		if ( ! $has_signal && ! $has_phrase && ! $has_answer_contract ) {
			return array();
		}
		return array(
			'goal_id' => 'goal_' . substr( md5( $identity['identity_uuid'] . '|' . $identity['session_id'] . '|' . $text ), 0, 16 ),
			'blog_id' => $identity['blog_id'],
			'identity_uuid' => $identity['identity_uuid'],
			'session_id' => $identity['session_id'],
			'primary_goal' => $text,
			'goal_title' => self::make_goal_title( $text ),
			'user_intent_current' => $text,
			'objectives' => array( array( 'id' => 'objective_1', 'label' => $text, 'status' => 'in_progress' ) ),
			'open_loops' => array( array( 'id' => 'loop_1', 'label' => 'Làm rõ và thực hiện mục tiêu', 'status' => 'pending' ) ),
			'next_best_action' => array( 'type' => 'clarify', 'label' => 'Làm rõ thông tin cần thiết để thực hiện mục tiêu' ),
			'status' => 'clarifying',
			'completion_score' => 0,
		);
	}

	private static function make_goal_title( string $text ): string {
		// [2026-08-15 Johnny Chu] MPR-V5-GOAL-TITLE — create a short redacted title for timeline/session UI without persisting contact details.
		$title = trim( preg_replace( '/\+?\d[\d\s().-]{7,}\d/u', '', $text ) );
		$title = trim( preg_replace( '/\s+/u', ' ', $title ) );
		return mb_substr( $title !== '' ? $title : 'Mục tiêu mới', 0, 80, 'UTF-8' );
	}

	private static function is_small_talk( string $text ): bool {
		return in_array( strtolower( trim( $text ) ), array( 'hi', 'hello', 'hey', 'chào', 'chào bạn', 'xin chào', 'ok', 'ừ', 'cảm ơn', 'thanks', 'thank you' ), true );
	}

	private static function identity( array $opts ): array {
		$blog_id = (int) get_current_blog_id();
		$identity_uuid = strtolower( trim( (string) ( $opts['identity_uuid'] ?? '' ) ) );
		$session_id = trim( (string) ( $opts['session_id'] ?? '' ) );
		return array(
			'blog_id' => $blog_id,
			'identity_uuid' => $identity_uuid,
			'session_id' => $session_id,
			'ready' => $blog_id > 0 && $identity_uuid !== '' && $session_id !== '',
		);
	}

	private static function brief( array $goal ): string {
		$lines = array();
		if ( ! empty( $goal['primary_goal'] ) ) {
			$lines[] = 'PRIMARY GOAL: ' . sanitize_text_field( (string) $goal['primary_goal'] );
		}
		if ( ! empty( $goal['open_loops'] ) ) {
			$labels = array();
			foreach ( array_slice( (array) $goal['open_loops'], 0, 5 ) as $item ) {
				$label = is_array( $item ) ? (string) ( $item['label'] ?? '' ) : (string) $item;
				if ( $label !== '' ) { $labels[] = sanitize_text_field( $label ); }
			}
			if ( ! empty( $labels ) ) { $lines[] = 'OPEN LOOPS: ' . implode( '; ', $labels ); }
		}
		if ( ! empty( $goal['next_best_action']['label'] ) ) {
			$lines[] = 'NEXT BEST ACTION: ' . sanitize_text_field( (string) $goal['next_best_action']['label'] );
		}
		return implode( "\n", $lines );
	}
}
