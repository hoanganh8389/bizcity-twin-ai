<?php
/**
 * Deterministic Goal Loop delta computation.
 *
 * Reuses evidence already produced by TwinBrain. It never calls an LLM and
 * never treats a generated answer alone as completed goal evidence.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-01
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Delta {

	/**
	 * @return array<string,mixed>
	 */
	public static function compute( array $goal, string $prompt, array $result, array $opts = array() ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — derive progress from existing turn evidence only.
		$patch = array(
			'user_intent_current' => sanitize_text_field( $prompt ),
			'spiral_turns' => max( 0, (int) ( $goal['spiral_turns'] ?? 0 ) ) + 1,
		);
		$evidence = self::turn_evidence( $result, $opts );
		if ( ! empty( $evidence ) ) {
			$patch['roadmap_progress'] = array(
				array(
					'id' => 'evidence_' . substr( md5( (string) ( $result['trace_id'] ?? $prompt ) ), 0, 12 ),
					'label' => 'Evidence gathered for current turn',
					'status' => 'done',
					'evidence' => $evidence,
				),
			);
		}

		$tool = self::successful_tool( $result );
		if ( ! empty( $tool ) ) {
			$patch['completed_outputs'] = array(
				array(
					'id' => 'tool_' . substr( md5( (string) ( $tool['run_id'] ?? $tool['tool_slug'] ?? 'turn' ) ), 0, 12 ),
					'label' => 'Tool output received',
					'status' => 'done',
					'evidence' => array( sanitize_text_field( (string) ( $tool['run_id'] ?? $tool['tool_slug'] ?? 'tool_result' ) ) ),
				),
			);
		}

		$patch['next_best_action'] = self::next_action( $goal );
		return $patch;
	}

	/**
	 * Merge a delta without replacing existing evidence arrays.
	 *
	 * @return array<string,mixed>
	 */
	public static function apply( array $goal, array $patch ): array {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G3 — merge deterministic evidence idempotently.
		$next = $goal;
		foreach ( $patch as $key => $value ) {
			if ( in_array( $key, array( 'roadmap_progress', 'completed_outputs', 'open_loops', 'blockers', 'gaps' ), true ) && is_array( $value ) ) {
				$existing = array();
				foreach ( (array) ( $next[ $key ] ?? array() ) as $item ) {
					if ( is_array( $item ) && ! empty( $item['id'] ) ) {
						$existing[ (string) $item['id'] ] = $item;
					}
				}
				foreach ( $value as $item ) {
					if ( is_array( $item ) && ! empty( $item['id'] ) ) {
						$existing[ (string) $item['id'] ] = $item;
					}
				}
				$next[ $key ] = array_values( $existing );
				continue;
			}
			$next[ $key ] = $value;
		}
		return class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
			? BizCity_TwinBrain_Goal_Loop_State::normalize( $next )
			: $next;
	}

	private static function turn_evidence( array $result, array $opts ): array {
		$evidence = array();
		$trace_id = sanitize_text_field( (string) ( $result['trace_id'] ?? $opts['trace_id'] ?? '' ) );
		$citations = (array) ( $result['synthesis']['citations'] ?? $result['citations'] ?? array() );
		$passages = (array) ( $result['cited_passages'] ?? array() );
		$source_counts = (array) ( $opts['notebook_source_counts'] ?? array() );
		if ( ! empty( $citations ) || ! empty( $passages ) || (int) ( $source_counts['passage_count'] ?? 0 ) > 0 ) {
			$evidence[] = 'notebook_evidence';
		}
		if ( $trace_id !== '' ) {
			$evidence[] = 'trace:' . $trace_id;
		}
		return $evidence;
	}

	private static function successful_tool( array $result ): array {
		$tool = isset( $result['tool_dispatch'] ) && is_array( $result['tool_dispatch'] )
			? $result['tool_dispatch']
			: array();
		if ( empty( $tool ) || empty( $tool['success'] ) ) {
			return array();
		}
		return $tool;
	}

	private static function next_action( array $goal ) {
		if ( class_exists( 'BizCity_TwinBrain_Goal_Loop_Question_Engine' ) ) {
			$question = BizCity_TwinBrain_Goal_Loop_Question_Engine::next_question( $goal );
			if ( is_array( $question ) ) {
				return $question;
			}
		}
		foreach ( (array) ( $goal['required_inputs'] ?? array() ) as $item ) {
			if ( is_array( $item ) && in_array( (string) ( $item['status'] ?? 'pending' ), array( 'pending', 'blocked' ), true ) ) {
				return array( 'type' => 'ask', 'label' => 'Bổ sung: ' . (string) ( $item['label'] ?? 'thông tin còn thiếu' ), 'ref' => (string) ( $item['id'] ?? '' ) );
			}
		}
		foreach ( (array) ( $goal['open_loops'] ?? array() ) as $item ) {
			if ( is_array( $item ) && (string) ( $item['status'] ?? 'pending' ) !== 'done' ) {
				return array( 'type' => 'act', 'label' => 'Tiếp tục: ' . (string) ( $item['label'] ?? 'việc còn mở' ), 'ref' => (string) ( $item['id'] ?? '' ) );
			}
		}
		return array( 'type' => 'verify', 'label' => 'Kiểm tra Definition of Done trước khi đóng goal', 'ref' => 'definition_of_done' );
	}
}
