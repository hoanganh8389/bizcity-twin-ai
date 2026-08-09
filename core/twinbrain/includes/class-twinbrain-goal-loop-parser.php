<?php
/**
 * Deterministic Goal Draft parser for Twin Goal Loop.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_TwinBrain_Goal_Loop_Parser {

	// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — Goal Parser model is HARD-CODED per founder mandate.
	// Do NOT read this from BizCity_LLM_Client::get_model()/site settings; the model+reasoning
	// pair is passed explicitly to BizCity_LLM_Client::chat() below so no admin override can change it.
	const MODEL = 'openai/gpt-5.6-terra';
	const REASONING_EFFORT = 'low';

	/**
	 * @return array<string,mixed>
	 */
	public static function parse( string $prompt, array $opts = array() ): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8-V2 — keep deterministic parsing as the default and fail-open baseline.
		$v1 = self::parse_deterministic( $prompt );
		$enabled = (bool) apply_filters( 'bizcity_twinbrain_goal_parser_llm_enabled', false, $prompt, $opts );
		$ambiguous = empty( $v1 ) || empty( $v1['primary_goal'] ) || ! empty( $v1['required_inputs'] );
		if ( ! $enabled || ! $ambiguous || ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $v1;
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! is_object( $client ) || ! method_exists( $client, 'is_ready' ) || ! $client->is_ready() ) {
			return $v1;
		}
		try {
			// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — 'model' is passed explicitly (bypasses
			// get_model('goal_parse')/site settings entirely) so Goal Parser always runs on
			// gpt-5.6-terra with reasoning=low, regardless of per-site model_chat overrides.
			$response = $client->chat(
				array(
					array( 'role' => 'system', 'content' => 'Parse the user request into JSON with primary_goal, conversation_goal, answer_obligations, subject, constraints, objectives, and required_inputs. answer_obligations must cover direct questions, explanations, comparisons, recommendations, implicit concerns, and desired outcomes. Return JSON only. Do not invent facts; use an empty string or empty array for missing data.' ),
					array( 'role' => 'user', 'content' => wp_json_encode( array( 'prompt' => sanitize_text_field( $prompt ), 'v1_draft' => $v1 ) ) ),
				),
				array(
					'purpose'     => 'goal_parse',
					'model'       => self::MODEL,
					'temperature' => 0,
					'max_tokens'  => 300,
					'timeout'     => 12,
					'no_fallback' => true,
					'extra_body'  => array( 'reasoning' => array( 'effort' => self::REASONING_EFFORT ) ),
				)
			);
			$draft = self::decode_llm_draft( $response );
			return ! empty( $draft ) ? $draft : $v1;
		} catch ( \Throwable $e ) {
			return $v1;
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function parse_deterministic( string $prompt ): array {
		// [2026-08-02 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G8 — extract explicit goal facts without an LLM or side effects.
		$text = trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( $prompt ) ) );
		if ( $text === '' ) {
			return array();
		}
		$subject = array();
		$constraints = array();
		$objectives = array();
		$required_inputs = array();
		$domain_signal = (bool) preg_match( '/\b(bé|con|sữa|lactose|táo bón|cữ|ml|kg)\b/ui', $text );

		if ( preg_match( '/\b(bé|con)\b/ui', $text, $match ) ) {
			$subject['who'] = strtolower( $match[1] );
		}
		if ( preg_match( '/\b(\d+)\s*(tháng|tuổi)\b/ui', $text, $match ) ) {
			$subject['age'] = $match[1] . ' ' . strtolower( $match[2] );
		} elseif ( $domain_signal ) {
			$required_inputs[] = array( 'id' => 'subject_age', 'label' => 'Tuổi của đối tượng', 'status' => 'pending' );
		}
		if ( preg_match( '/\b(\d+(?:[.,]\d+)?)\s*kg\b/ui', $text, $match ) ) {
			$subject['weight_kg'] = str_replace( ',', '.', $match[1] );
		} elseif ( $domain_signal ) {
			$required_inputs[] = array( 'id' => 'subject_weight', 'label' => 'Cân nặng hiện tại', 'status' => 'pending' );
		}
		if ( preg_match( '/\b(\d+)\s*cữ\s*(\d+(?:[.,]\d+)?)\s*ml(?:\s*\/\s*(ngày|day))?/ui', $text, $match ) ) {
			$constraints[] = array(
				'kind' => 'dosage',
				'label' => $match[1] . ' cữ ' . str_replace( ',', '.', $match[2] ) . 'ml' . ( ! empty( $match[3] ) ? '/ngày' : '' ),
				'source' => 'user_stated',
			);
		}
		if ( preg_match( '/\btáo bón\b/ui', $text ) ) {
			$constraints[] = array( 'kind' => 'symptom', 'label' => 'Táo bón', 'source' => 'user_stated' );
			$objectives[] = array( 'id' => 'reduce_constipation', 'label' => 'Giảm tình trạng táo bón', 'status' => 'pending' );
		}
		if ( preg_match( '/\bbất dung nạp\s+lactose\b/ui', $text ) ) {
			$constraints[] = array( 'kind' => 'allergy', 'label' => 'Bất dung nạp Lactose', 'source' => 'user_stated' );
		}
		if ( preg_match( '/\bfree\s*lactose\b/ui', $text ) ) {
			$constraints[] = array( 'kind' => 'medical_recommendation', 'label' => 'Khuyến nghị sữa free Lactose', 'source' => 'user_stated' );
		}
		if ( preg_match( '/\b(chọn|tìm|tư vấn|giúp|muốn|cần|phù hợp|nên)\b/ui', $text ) || ! empty( $objectives ) ) {
			$primary_goal = $text;
		} else {
			$primary_goal = '';
		}
		$contract = self::extract_contract( $text, $primary_goal );
		if ( $primary_goal === '' && ! empty( $contract['answer_obligations'] ) ) {
			$primary_goal = (string) $contract['conversation_goal']['user_outcome'];
		}
		if ( ! empty( $objectives ) && ! empty( $constraints ) ) {
			$objectives[] = array( 'id' => 'recommend_product', 'label' => 'Chọn phương án phù hợp với các ràng buộc đã nêu', 'status' => 'pending' );
		}
		if ( $primary_goal === '' && empty( $constraints ) && empty( $required_inputs ) ) {
			return array();
		}
		return array(
			'primary_goal'    => $primary_goal,
			'subject'         => $subject,
			'constraints'     => $constraints,
			'objectives'      => $objectives,
			'required_inputs' => $required_inputs,
			'conversation_goal'  => $contract['conversation_goal'],
			'answer_obligations' => $contract['answer_obligations'],
			'reason'          => ! empty( $constraints ) ? 'regex_symptom_and_constraint_match' : 'explicit_request_match',
		);
	}

	private static function extract_contract( string $text, string $primary_goal ): array {
		// [2026-08-03 Johnny Chu] R-MPR-GOALBOARD — create a deterministic per-turn answer contract before Memory/MPR; LLM v2 only enriches ambiguous semantics.
		$obligations = array();
		if ( preg_match( '/\b(giá|chi phí|bao nhiêu|mức phí|đơn giá)\b/ui', $text ) ) {
			self::add_obligation( $obligations, 'fact', 'Chi phí là bao nhiêu?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		if ( preg_match( '/\b(phù hợp|nên chọn|gói nào|giải pháp nào|tư vấn|khuyến nghị)\b/ui', $text ) ) {
			self::add_obligation( $obligations, 'recommendation', 'Phương án nào phù hợp với nhu cầu của user?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		if ( preg_match( '/\b(an toàn|bảo mật|dữ liệu|đưa ra ngoài|rò rỉ|riêng tư)\b/ui', $text ) ) {
			$type = preg_match( '/\b(sợ|lo|băn khoăn|e ngại)\b/ui', $text ) ? 'implicit_concern' : 'risk_explanation';
			self::add_obligation( $obligations, $type, 'Dữ liệu có được bảo vệ và dữ liệu nào được gửi tới LLM?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		if ( preg_match( '/\b(dùng chung|nhân viên|team|nhóm|nhiều người|10 người)\b/ui', $text ) ) {
			self::add_obligation( $obligations, 'capability', 'Các thành viên có thể dùng chung hệ thống không?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		if ( preg_match( '/\b(khác|so với|so sánh|hơn)\b/ui', $text ) ) {
			self::add_obligation( $obligations, 'comparison', 'Giải pháp này khác các phương án thay thế ở điểm nào?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		if ( preg_match( '/\b(tại sao|vì sao|giải thích|tiết kiệm hơn)\b/ui', $text ) ) {
			self::add_obligation( $obligations, 'explanation', 'Vì sao phương án này phù hợp hoặc hiệu quả hơn?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		if ( preg_match( '/\b(trình ban giám đốc|trình sếp|phương án để trình|quyết định)\b/ui', $text ) ) {
			self::add_obligation( $obligations, 'desired_outcome', 'Cần chốt phương án nào để user có thể trình người ra quyết định?', 'Q' . ( count( $obligations ) + 1 ) );
		}
		$mode = 'consulting';
		if ( ! empty( $obligations ) && count( $obligations ) === 1 && ( $obligations[0]['type'] ?? '' ) === 'fact' ) {
			$mode = 'fact_lookup';
		} elseif ( preg_match( '/\b(khác|so với|so sánh)\b/ui', $text ) ) {
			$mode = 'comparison';
		} elseif ( preg_match( '/\b(sửa|lỗi|không chạy|khắc phục)\b/ui', $text ) ) {
			$mode = 'troubleshooting';
		}
		$user_outcome = $primary_goal !== '' ? $primary_goal : ( ! empty( $obligations ) ? 'Giải quyết đầy đủ các câu hỏi và băn khoăn trong lượt trao đổi này' : '' );
		return array(
			'conversation_goal'  => array(
				'user_outcome'      => sanitize_text_field( $user_outcome ),
				'conversation_mode' => $mode,
				'decision_required' => ! empty( $obligations ) && ( $mode === 'consulting' || preg_match( '/\b(chọn|nên|phù hợp|quyết định)\b/ui', $text ) ),
				'closure_condition' => ! empty( $obligations ) ? 'Các nghĩa vụ trả lời bắt buộc được giải quyết và user hiểu phương án phù hợp.' : '',
			),
			'answer_obligations' => $obligations,
		);
	}

	private static function add_obligation( array &$obligations, string $type, string $question, string $id ): void {
		foreach ( $obligations as $existing ) {
			if ( (string) ( $existing['type'] ?? '' ) === $type ) {
				return;
			}
		}
		$obligations[] = array(
			'id'       => sanitize_key( $id ),
			'question' => sanitize_text_field( $question ),
			'type'     => sanitize_key( $type ),
			'priority' => 'must',
			'status'   => 'open',
		);
	}

	private static function decode_llm_draft( array $response ): array {
		$content = trim( (string) ( $response['message'] ?? '' ) );
		if ( $content === '' ) {
			return array();
		}
		$content = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $content );
		$decoded = json_decode( trim( $content ), true );
		if ( ! is_array( $decoded ) || trim( (string) ( $decoded['primary_goal'] ?? '' ) ) === '' ) {
			return array();
		}
		$constraints = array();
		foreach ( array_slice( (array) ( $decoded['constraints'] ?? array() ), 0, 20 ) as $constraint ) {
			if ( ! is_array( $constraint ) || trim( (string) ( $constraint['label'] ?? '' ) ) === '' ) {
				continue;
			}
			$constraints[] = array(
				'kind'   => sanitize_key( (string) ( $constraint['kind'] ?? 'constraint' ) ),
				'label'  => sanitize_text_field( (string) $constraint['label'] ),
				'source' => 'llm_inferred',
			);
		}
		$objectives = array();
		foreach ( array_slice( (array) ( $decoded['objectives'] ?? array() ), 0, 20 ) as $index => $objective ) {
			if ( ! is_array( $objective ) || trim( (string) ( $objective['label'] ?? '' ) ) === '' ) {
				continue;
			}
			$objectives[] = array(
				'id'     => sanitize_key( (string) ( $objective['id'] ?? 'llm_objective_' . ( $index + 1 ) ) ),
				'label'  => sanitize_text_field( (string) $objective['label'] ),
				'status' => 'pending',
			);
		}
		$required_inputs = array();
		foreach ( array_slice( (array) ( $decoded['required_inputs'] ?? array() ), 0, 20 ) as $index => $input ) {
			if ( ! is_array( $input ) || trim( (string) ( $input['label'] ?? '' ) ) === '' ) {
				continue;
			}
			$required_inputs[] = array(
				'id'     => sanitize_key( (string) ( $input['id'] ?? 'llm_input_' . ( $index + 1 ) ) ),
				'label'  => sanitize_text_field( (string) $input['label'] ),
				'status' => 'pending',
			);
		}
		$subject = array();
		foreach ( array( 'who', 'age', 'weight_kg' ) as $key ) {
			if ( isset( $decoded['subject'][ $key ] ) && is_scalar( $decoded['subject'][ $key ] ) ) {
				$subject[ $key ] = sanitize_text_field( (string) $decoded['subject'][ $key ] );
			}
		}
		$contract = self::normalize_llm_contract( $decoded, (string) $decoded['primary_goal'] );
		return array(
			'primary_goal'    => sanitize_text_field( (string) $decoded['primary_goal'] ),
			'subject'         => $subject,
			'constraints'     => $constraints,
			'objectives'      => $objectives,
			'required_inputs' => $required_inputs,
			'conversation_goal'  => $contract['conversation_goal'],
			'answer_obligations' => $contract['answer_obligations'],
			'reason'          => 'small_llm_v2',
		);
	}

	private static function normalize_llm_contract( array $decoded, string $fallback_goal ): array {
		$goal = is_array( $decoded['conversation_goal'] ?? null ) ? $decoded['conversation_goal'] : array();
		$mode = sanitize_key( (string) ( $goal['conversation_mode'] ?? 'consulting' ) );
		if ( ! in_array( $mode, array( 'consulting', 'fact_lookup', 'comparison', 'troubleshooting', 'task_execution', 'casual' ), true ) ) {
			$mode = 'consulting';
		}
		$obligations = array();
		foreach ( array_slice( (array) ( $decoded['answer_obligations'] ?? array() ), 0, 50 ) as $index => $item ) {
			if ( ! is_array( $item ) || trim( (string) ( $item['question'] ?? '' ) ) === '' ) {
				continue;
			}
			$type = sanitize_key( (string) ( $item['type'] ?? 'explanation' ) );
			$priority = sanitize_key( (string) ( $item['priority'] ?? 'must' ) );
			$status = sanitize_key( (string) ( $item['status'] ?? 'open' ) );
			$obligations[] = array(
				'id'       => sanitize_key( (string) ( $item['id'] ?? 'q' . ( $index + 1 ) ) ),
				'question' => sanitize_text_field( (string) $item['question'] ),
				'type'     => in_array( $type, array( 'recommendation', 'fact', 'risk_explanation', 'capability', 'comparison', 'explanation', 'implicit_concern', 'desired_outcome' ), true ) ? $type : 'explanation',
				'priority' => in_array( $priority, array( 'must', 'should', 'nice_to_have' ), true ) ? $priority : 'must',
				'status'   => in_array( $status, array( 'open', 'answered', 'deferred' ), true ) ? $status : 'open',
			);
		}
		return array(
			'conversation_goal' => array(
				'user_outcome'      => sanitize_text_field( (string) ( $goal['user_outcome'] ?? $fallback_goal ) ),
				'conversation_mode' => $mode,
				'decision_required' => ! empty( $goal['decision_required'] ),
				'closure_condition' => sanitize_text_field( (string) ( $goal['closure_condition'] ?? '' ) ),
			),
			'answer_obligations' => $obligations,
		);
	}
}