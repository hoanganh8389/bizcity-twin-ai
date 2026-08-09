<?php
/**
 * Deterministic continuity question engine for Twin Goal Loop.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Question_Engine {

	const DEFAULT_MAX_SPIRAL_TURNS = 6;

	/**
	 * @return array<string,mixed>|null
	 */
	public static function next_question( array $goal ): ?array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G9 — ask one bounded continuity question and never execute a side effect.
		$state = class_exists( 'BizCity_TwinBrain_Goal_Loop_State' )
			? BizCity_TwinBrain_Goal_Loop_State::normalize( $goal )
			: $goal;
		if ( ! empty( $state['goal_id'] ) && (int) ( $state['spiral_turns'] ?? 0 ) >= self::max_spiral_turns() ) {
			return array(
				'type' => 'checkpoint',
				'label' => 'Đã đi qua nhiều vòng làm rõ; hãy chọn tiếp tục hoặc tổng hợp lại.',
				'ref' => 'spiral_turns',
				'question_text' => 'Bạn muốn tiếp tục làm rõ một điểm còn thiếu hay để mình tổng hợp những gì đã có?',
				'kind' => 'checkpoint',
			);
		}
		foreach ( (array) ( $state['gaps'] ?? array() ) as $gap ) {
			if ( ! is_array( $gap ) || ! in_array( (string) ( $gap['status'] ?? 'open' ), array( 'open', 'in_progress' ), true ) ) {
				continue;
			}
			return self::question_for_gap( $gap );
		}
		foreach ( (array) ( $state['required_inputs'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) || ! in_array( (string) ( $item['status'] ?? 'pending' ), array( 'pending', 'blocked' ), true ) ) {
				continue;
			}
			$label = (string) ( $item['label'] ?? 'thông tin còn thiếu' );
			return array(
				'type' => 'ask',
				'label' => 'Bổ sung: ' . $label,
				'ref' => (string) ( $item['id'] ?? '' ),
				'question_text' => 'Bạn cho mình biết ' . self::lowercase_label( $label ) . ' để mình tiếp tục nhé?',
				'kind' => 'missing_input',
				'target_field' => (string) ( $item['id'] ?? '' ),
			);
		}
		return null;
	}

	public static function max_spiral_turns(): int {
		$max = defined( 'BIZCITY_TWINBRAIN_GOAL_SPIRAL_MAX_TURNS' )
			? (int) BIZCITY_TWINBRAIN_GOAL_SPIRAL_MAX_TURNS
			: self::DEFAULT_MAX_SPIRAL_TURNS;
		if ( function_exists( 'apply_filters' ) ) {
			$max = (int) apply_filters( 'bizcity_twinbrain_goal_spiral_max_turns', $max );
		}
		return max( 1, min( 100, $max ) );
	}

	private static function question_for_gap( array $gap ): array {
		$kind = sanitize_key( (string) ( $gap['kind'] ?? 'missing_input' ) );
		$label = (string) ( $gap['label'] ?? 'điểm còn thiếu' );
		$questions = array(
			'missing_input' => 'Bạn bổ sung giúp mình ' . self::lowercase_label( $label ) . ' để mình tư vấn chính xác hơn nhé?',
			'weak_evidence' => 'Bạn muốn mình kiểm tra thêm nguồn hoặc bằng chứng cụ thể nào cho phần "' . $label . '" không?',
			'ambiguous_objective' => 'Mình xác nhận lại: với mục tiêu "' . $label . '", kết quả nào là quan trọng nhất với bạn?',
			'unverified_claim' => 'Bạn có nguồn hoặc thông tin xác nhận nào cho "' . $label . '" để mình kiểm tra tiếp không?',
		);
		$question = $questions[ $kind ] ?? $questions['missing_input'];
		return array(
			'type' => 'ask_gap',
			'label' => 'Làm rõ: ' . $label,
			'ref' => (string) ( $gap['id'] ?? '' ),
			'question_text' => $question,
			'kind' => $kind,
		);
	}

	private static function lowercase_label( string $label ): string {
		$label = trim( $label );
		return $label === '' ? 'thông tin còn thiếu' : lcfirst( $label );
	}
}