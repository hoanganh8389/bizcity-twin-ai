<?php
/**
 * BizCity Twin Prompt Parser — Basic prompt understanding layer.
 *
 * Phase 2 Priority 4: Parse user prompt into structured prompt_spec record.
 * Write-only — ghi prompt_specs để Phase 3 focus engine đọc.
 *
 * Luồng:
 *   1. Nhận raw prompt + context IDs.
 *   2. Tách prompt thành segments (theo dấu câu/ý).
 *   3. Xác định primary objective, secondary objectives.
 *   4. Đánh giá confidence + needs_confirmation.
 *   5. Ghi vào bizcity_twin_prompt_specs.
 *   6. Fire event EVT_PROMPT_PARSED.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-27
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Prompt_Parser {

	/**
	 * Parse a user prompt and save to prompt_specs table.
	 *
	 * @param string $raw_prompt    The raw user prompt text.
	 * @param array  $context       Context IDs: session_id, project_id, intent_conversation_id.
	 * @return array{prompt_spec_id: int, confidence: float, needs_confirmation: bool, objectives: array}
	 */
	public static function parse( string $raw_prompt, array $context = [] ): array {
		$trace_id = BizCity_Twin_Data_Contract::current_trace_id();
		$user_id  = BizCity_Twin_Data_Contract::current_user_id();
		$blog_id  = BizCity_Twin_Data_Contract::current_blog_id();

		// Step 1: Segment the prompt
		$segments = self::segment_prompt( $raw_prompt );

		// Step 2: Extract objectives from segments
		$objectives = self::extract_objectives( $segments );

		// Step 3: Determine primary and secondary
		$primary    = ! empty( $objectives ) ? $objectives[0] : $raw_prompt;
		$secondary  = array_slice( $objectives, 1 );

		// Step 4: Assess confidence and confirmation need
		$confidence         = self::assess_confidence( $raw_prompt, $segments, $objectives );
		$needs_confirmation = self::needs_confirmation( $confidence, $objectives );
		$confirm_questions  = $needs_confirmation ? self::generate_confirmation_questions( $objectives, $confidence ) : [];

		// Step 5: Recommend mode and path
		$recommended_mode = self::recommend_mode( $raw_prompt, $objectives );
		$recommended_path = self::recommend_path( $recommended_mode, $context );

		// Step 6: Write to DB
		$prompt_spec_id = self::save_prompt_spec( [
			'trace_id'                   => $trace_id,
			'user_id'                    => $user_id,
			'blog_id'                    => $blog_id,
			'session_id'                 => $context['session_id'] ?? null,
			'project_id'                 => $context['project_id'] ?? null,
			'intent_conversation_id'     => $context['intent_conversation_id'] ?? null,
			'raw_prompt'                 => $raw_prompt,
			'prompt_segments_json'       => wp_json_encode( $segments ),
			'objective_list_json'        => wp_json_encode( $objectives ),
			'primary_objective'          => mb_substr( $primary, 0, 65535 ),
			'secondary_objectives_json'  => wp_json_encode( $secondary ),
			'expected_outputs_json'      => null,
			'constraints_json'           => null,
			'ambiguity_flags_json'       => wp_json_encode( self::detect_ambiguity( $raw_prompt, $objectives ) ),
			'confidence'                 => $confidence,
			'needs_confirmation'         => $needs_confirmation ? 1 : 0,
			'confirmation_questions_json' => ! empty( $confirm_questions ) ? wp_json_encode( $confirm_questions ) : null,
			'recommended_mode'           => $recommended_mode,
			'recommended_path'           => $recommended_path,
			'recommended_tools_json'     => null,
		] );

		// Step 7: Fire event
		$event_payload = [
			'trace_id'         => $trace_id,
			'user_id'          => $user_id,
			'prompt_spec_id'   => $prompt_spec_id,
			'confidence'       => $confidence,
			'recommended_mode' => $recommended_mode,
		];
		do_action( 'bizcity_twin_event', BizCity_Twin_Data_Contract::EVT_PROMPT_PARSED, $event_payload );

		// Log to trace
		BizCity_Twin_Trace::log( 'prompt_parsed', [
			'prompt_spec_id'   => $prompt_spec_id,
			'segments'         => count( $segments ),
			'objectives'       => count( $objectives ),
			'confidence'       => $confidence,
			'needs_confirm'    => $needs_confirmation,
			'mode'             => $recommended_mode,
		] );

		return [
			'prompt_spec_id'    => $prompt_spec_id,
			'confidence'        => $confidence,
			'needs_confirmation' => $needs_confirmation,
			'objectives'        => $objectives,
			'primary_objective' => $primary,
			'recommended_mode'  => $recommended_mode,
			'recommended_path'  => $recommended_path,
		];
	}

	/* ================================================================
	 * SEGMENTATION — Split prompt into meaningful segments
	 * ================================================================ */

	/**
	 * Segment prompt text into logical parts.
	 *
	 * @param string $text Raw prompt.
	 * @return string[] Array of segments.
	 */
	private static function segment_prompt( string $text ): array {
		$text = trim( $text );
		if ( $text === '' ) {
			return [];
		}

		// Split by newlines, numbered lists, bullet points, and sentence-final punctuation
		$parts = preg_split(
			'/(?:\r?\n\s*\r?\n)|(?:(?<=[\.\!\?])\s+(?=[A-ZÀ-Ỹ\d]))|(?:\r?\n\s*[-•\*]\s)|(?:\r?\n\s*\d+[\.\)]\s)/u',
			$text
		);

		$segments = [];
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( $part !== '' ) {
				$segments[] = $part;
			}
		}

		return $segments ?: [ $text ];
	}

	/* ================================================================
	 * OBJECTIVE EXTRACTION — Identify what the user wants
	 * ================================================================ */

	/**
	 * Extract actionable objectives from segments.
	 *
	 * @param string[] $segments
	 * @return string[] Objectives ordered by significance.
	 */
	private static function extract_objectives( array $segments ): array {
		if ( empty( $segments ) ) {
			return [];
		}

		// Each segment is treated as a potential objective.
		// Filter out very short greeting-only segments.
		$objectives = [];
		foreach ( $segments as $seg ) {
			$clean = mb_strtolower( trim( $seg ) );
			// Skip pure greetings / filler
			if ( preg_match( '/^(hi|hello|xin chào|chào|hey|ok|thanks|cảm ơn|thank you)[\.\!\s]*$/iu', $clean ) ) {
				continue;
			}
			$objectives[] = $seg;
		}

		return ! empty( $objectives ) ? $objectives : $segments;
	}

	/* ================================================================
	 * CONFIDENCE ASSESSMENT
	 * ================================================================ */

	/**
	 * Assess parse confidence (0.0 — 1.0).
	 *
	 * @param string   $raw_prompt
	 * @param string[] $segments
	 * @param string[] $objectives
	 * @return float
	 */
	private static function assess_confidence( string $raw_prompt, array $segments, array $objectives ): float {
		$confidence = 1.0;

		// Multiple objectives reduce confidence
		$obj_count = count( $objectives );
		if ( $obj_count > 3 ) {
			$confidence -= 0.3;
		} elseif ( $obj_count > 1 ) {
			$confidence -= 0.15;
		}

		// Very long prompt reduces confidence
		$char_len = mb_strlen( $raw_prompt );
		if ( $char_len > 2000 ) {
			$confidence -= 0.2;
		} elseif ( $char_len > 800 ) {
			$confidence -= 0.1;
		}

		// Question marks suggest uncertainty
		$question_marks = substr_count( $raw_prompt, '?' );
		if ( $question_marks > 3 ) {
			$confidence -= 0.15;
		}

		// Very short prompt is high confidence (single intent)
		if ( $char_len < 100 && $obj_count <= 1 ) {
			$confidence = min( 1.0, $confidence + 0.1 );
		}

		return max( 0.0, min( 1.0, round( $confidence, 4 ) ) );
	}

	/* ================================================================
	 * CONFIRMATION LOGIC
	 * ================================================================ */

	/**
	 * Determine if user confirmation is needed.
	 */
	private static function needs_confirmation( float $confidence, array $objectives ): bool {
		if ( $confidence < 0.6 ) {
			return true;
		}
		if ( count( $objectives ) > 2 ) {
			return true;
		}
		return false;
	}

	/**
	 * Generate confirmation questions.
	 *
	 * @param string[] $objectives
	 * @param float    $confidence
	 * @return string[]
	 */
	private static function generate_confirmation_questions( array $objectives, float $confidence ): array {
		$questions = [];

		if ( count( $objectives ) > 1 ) {
			$questions[] = 'Bạn muốn giải quyết mục tiêu nào trước: ' . implode( ' hay ', array_map(
				fn( $o ) => '"' . mb_substr( $o, 0, 60 ) . '"',
				array_slice( $objectives, 0, 3 )
			) ) . '?';
		}

		if ( $confidence < 0.5 ) {
			$questions[] = 'Bạn có thể nói rõ hơn kết quả bạn mong muốn là gì không?';
		}

		return $questions;
	}

	/* ================================================================
	 * AMBIGUITY DETECTION
	 * ================================================================ */

	/**
	 * Detect ambiguity flags in prompt.
	 *
	 * @param string   $raw_prompt
	 * @param string[] $objectives
	 * @return array{multi_objective: bool, long_prompt: bool, unclear_output: bool}
	 */
	private static function detect_ambiguity( string $raw_prompt, array $objectives ): array {
		return [
			'multi_objective' => count( $objectives ) > 1,
			'long_prompt'     => mb_strlen( $raw_prompt ) > 800,
			'unclear_output'  => ! preg_match( '/(?:tạo|viết|liệt kê|phân tích|giải thích|so sánh|tìm|sửa|fix|create|write|list|analyze|compare|find|build)/iu', $raw_prompt ),
		];
	}

	/* ================================================================
	 * MODE / PATH RECOMMENDATION
	 * ================================================================ */

	/**
	 * Recommend processing mode based on prompt analysis.
	 *
	 * @param string   $raw_prompt
	 * @param string[] $objectives
	 * @return string Mode: emotion|knowledge|planning|execution|ambiguous
	 */
	private static function recommend_mode( string $raw_prompt, array $objectives ): string {
		$text = mb_strtolower( $raw_prompt );

		// Emotion patterns
		if ( preg_match( '/(?:buồn|vui|lo|sợ|stress|mệt|chán|tâm sự|cảm xúc|sad|anxious|happy|worried)/iu', $text ) ) {
			return 'emotion';
		}

		// Execution patterns
		if ( preg_match( '/(?:chạy|execute|thực hiện|deploy|run|gửi|send|tạo file|create file|push|publish)/iu', $text ) ) {
			return 'execution';
		}

		// Planning patterns
		if ( preg_match( '/(?:kế hoạch|plan|roadmap|timeline|lịch|schedule|bước|step|phase|ưu tiên|priority)/iu', $text ) ) {
			return 'planning';
		}

		// Knowledge patterns
		if ( preg_match( '/(?:là gì|what is|giải thích|explain|tại sao|why|so sánh|compare|khác nhau|difference)/iu', $text ) ) {
			return 'knowledge';
		}

		return 'ambiguous';
	}

	/**
	 * Recommend path based on mode and context.
	 *
	 * @param string $mode
	 * @param array  $context
	 * @return string Path: chat|notebook|intent|execution|studio
	 */
	private static function recommend_path( string $mode, array $context ): string {
		// If there's an active intent conversation, prefer intent path
		if ( ! empty( $context['intent_conversation_id'] ) ) {
			return 'intent';
		}

		switch ( $mode ) {
			case 'execution':
			case 'planning':
				return 'intent';
			case 'emotion':
			case 'knowledge':
			default:
				return 'chat';
		}
	}

	/* ================================================================
	 * DB PERSISTENCE
	 * ================================================================ */

	/**
	 * Save prompt spec record.
	 *
	 * @param array $data Row data.
	 * @return int prompt_spec_id (0 on failure).
	 */
	private static function save_prompt_spec( array $data ): int {
		global $wpdb;
		$table = BizCity_Twin_State_Schema::prompt_specs_table();

		// Safety: ensure table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get the latest prompt spec for a user.
	 *
	 * @param int $user_id
	 * @param int $blog_id
	 * @return object|null
	 */
	public static function get_latest( int $user_id, int $blog_id = 0 ): ?object {
		global $wpdb;
		$table   = BizCity_Twin_State_Schema::prompt_specs_table();
		$blog_id = $blog_id ?: get_current_blog_id();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE user_id = %d AND blog_id = %d ORDER BY prompt_spec_id DESC LIMIT 1",
			$user_id, $blog_id
		) );
	}
}
