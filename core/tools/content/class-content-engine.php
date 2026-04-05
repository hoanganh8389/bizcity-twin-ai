<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Content Engine — Skill-Aware LLM Content Generation
 *
 * Phase 1.4c: Central content generation service shared by all atomic content tools.
 * Replaces legacy ai_generate_content() + chatbot_chatgpt_call_omni_tele().
 *
 * Key methods:
 *   - build_skill_prompt() — Merges skill instructions + tool template + user topic
 *   - generate()           — Single LLM call via bizcity_llm_chat()
 *   - parse_json_response() — Extracts JSON from LLM response text
 *
 * @package  BizCity_Content
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Content_Engine {

	/**
	 * Build a resource-aware prompt by merging all available resource layers.
	 *
	 * Phase 1.9: Replaces build_skill_prompt() as the primary prompt builder.
	 * Layers (in order):
	 *   1. Skill instructions (from _meta._skill)
	 *   2. Session Spec (topic, focus, facts from _meta._session_spec)
	 *   3. Notes (from _meta._notes)
	 *   4. Sources (from _meta._sources)
	 *   5. Knowledge RAG (from _meta._knowledge)
	 *   6. Tool template + topic
	 *
	 * @param array $params {
	 *   @type string $topic          User's topic/message.
	 *   @type string $message        Alias for topic.
	 *   @type string $_tool_template Tool-specific prompt template.
	 *   @type array  $_meta          Resource bundle (skill, session_spec, notes, sources, knowledge).
	 * }
	 * @return string Complete LLM prompt.
	 */
	public static function build_unified_prompt( array $params ): string {
		$topic = $params['topic'] ?? $params['message'] ?? '';
		$meta  = $params['_meta'] ?? [];
		$parts = [];

		// Layer 1: Skill instructions
		$skill = $meta['_skill'] ?? [];
		if ( ! empty( $skill['content'] ) ) {
			$parts[] = "=== HƯỚNG DẪN KỸ NĂNG: " . ( $skill['title'] ?? 'Skill' ) . " ===\n"
			         . trim( $skill['content'] ) . "\n"
			         . "=== KẾT THÚC HƯỚNG DẪN ===";
		}

		// Layer 2: Session Spec (topic, focus, facts)
		$session = $meta['_session_spec'] ?? [];
		if ( ! empty( $session ) ) {
			$session_parts = [];
			if ( ! empty( $session['current_topic'] ) ) {
				$session_parts[] = "Chủ đề phiên: " . $session['current_topic'];
			}
			if ( ! empty( $session['current_focus'] ) ) {
				$session_parts[] = "Trọng tâm: " . $session['current_focus'];
			}
			$facts = $session['recent_facts'] ?? [];
			if ( $facts ) {
				$session_parts[] = "Dữ kiện đã xác nhận:\n" . implode( "\n", array_map( fn( $f ) => "- {$f}", array_slice( $facts, 0, 5 ) ) );
			}
			if ( $session_parts ) {
				$parts[] = "=== BỐI CẢNH PHIÊN ===\n" . implode( "\n", $session_parts ) . "\n=== /BỐI CẢNH ===";
			}
		}

		// Layer 3: Notes
		$notes = $meta['_notes'] ?? [];
		if ( $notes ) {
			$note_text = array_map(
				fn( $n ) => "- [{$n['note_type']}] {$n['title']}: " . mb_substr( $n['content'], 0, 400 ),
				array_slice( $notes, 0, 5 )
			);
			$parts[] = "=== GHI CHÚ NGHIÊN CỨU ===\n" . implode( "\n", $note_text ) . "\n=== /GHI CHÚ ===";
		}

		// Layer 4: Sources
		$sources = $meta['_sources'] ?? [];
		if ( $sources ) {
			$src_text = array_map(
				fn( $s ) => "- [{$s['source_type']}] {$s['title']}" . ( ! empty( $s['excerpt'] ) ? ': ' . mb_substr( $s['excerpt'], 0, 300 ) : '' ),
				array_slice( $sources, 0, 3 )
			);
			$parts[] = "=== NGUỒN TÀI LIỆU ===\n" . implode( "\n", $src_text ) . "\n=== /NGUỒN ===";
		}

		// Layer 5: Knowledge RAG
		$knowledge = $meta['_knowledge'] ?? [];
		if ( $knowledge ) {
			$know_text = array_map( fn( $k ) => mb_substr( $k['content'], 0, 250 ), array_slice( $knowledge, 0, 3 ) );
			$parts[] = "=== KIẾN THỨC BỔ SUNG ===\n" . implode( "\n---\n", $know_text ) . "\n=== /KIẾN THỨC ===";
		}

		// Layer 6: Tool template + topic
		$tool_template = $params['_tool_template'] ?? '';
		if ( ! empty( $skill['content'] ) ) {
			$parts[] = "Áp dụng đúng hướng dẫn kỹ năng ở trên.";
		}
		if ( $tool_template ) {
			$parts[] = $tool_template;
		}
		$parts[] = "Nội dung/chủ đề: " . $topic;

		error_log( '[CONTENT-ENGINE] build_unified_prompt: layers=' . count( $parts )
			. ' skill=' . ( ! empty( $skill['content'] ) ? 'yes' : 'no' )
			. ' session=' . ( ! empty( $session ) ? 'yes' : 'no' )
			. ' notes=' . count( $notes )
			. ' sources=' . count( $sources )
			. ' knowledge=' . count( $knowledge ) );

		return implode( "\n\n", $parts );
	}

	/**
	 * Build a complete prompt by merging skill instructions + tool template + user topic.
	 *
	 * Phase 1.9: Redirects to build_unified_prompt() when resource layers are present (from Resolver).
	 * Otherwise falls back to the legacy skill-only prompt.
	 *
	 * @param array  $slots         Tool input slots (including _meta).
	 * @param string $tool_template Tool-specific prompt template.
	 * @param string $topic         User's topic/message.
	 * @return string Complete LLM prompt.
	 */
	public static function build_skill_prompt( array $slots, string $tool_template, string $topic ): string {
		// Phase 1.9: Redirect to unified prompt if resource layers are available.
		$meta = $slots['_meta'] ?? [];
		if ( isset( $meta['_session_spec'] ) || isset( $meta['_notes'] ) || isset( $meta['_sources'] ) || isset( $meta['_knowledge'] ) ) {
			$slots['_tool_template'] = $tool_template;
			$slots['topic']          = $topic;
			return self::build_unified_prompt( $slots );
		}

		// Legacy path: skill + template + topic only.
		return self::build_skill_prompt_legacy( $slots, $tool_template, $topic );
	}

	/**
	 * Legacy skill prompt builder (pre-Phase 1.9).
	 *
	 * @param array  $slots         Tool input slots.
	 * @param string $tool_template Tool-specific prompt template.
	 * @param string $topic         User's topic.
	 * @return string
	 */
	public static function build_skill_prompt_legacy( array $slots, string $tool_template, string $topic ): string {
		$skill_content = $slots['_meta']['_skill']['content'] ?? '';
		$skill_title   = $slots['_meta']['_skill']['title']   ?? '';

		if ( $skill_content ) {
			error_log( "[CONTENT-ENGINE] build_skill_prompt_legacy: skill={$skill_title} len=" . strlen( $skill_content ) );

			return "=== HƯỚNG DẪN KỸ NĂNG: {$skill_title} ===\n"
			     . trim( $skill_content ) . "\n"
			     . "=== KẾT THÚC HƯỚNG DẪN ===\n\n"
			     . "Áp dụng đúng hướng dẫn kỹ năng ở trên.\n"
			     . $tool_template . "\n\n"
			     . "Nội dung/chủ đề: " . $topic;
		}

		// Fallback: tool template only (no skill)
		return $tool_template . "\n\nNội dung/chủ đề: " . $topic;
	}

	/**
	 * Execute a single LLM generation call.
	 *
	 * Routes through bizcity_llm_chat() → LLM Router → Provider.
	 * Falls back to legacy ai_generate_content() if LLM client unavailable.
	 *
	 * @param string $prompt  Complete prompt text.
	 * @param array  $options {
	 *   @type string $model       LLM model ID (default: auto via purpose).
	 *   @type int    $max_tokens  Max output tokens (default: 4096).
	 *   @type float  $temperature Creativity (default: 0.7).
	 *   @type string $purpose     Router purpose tag (default: 'content_generation').
	 * }
	 * @return array { success, content, title, metadata, tokens_used, model }
	 */
	public static function generate( string $prompt, array $options = [] ): array {
		$max_tokens  = $options['max_tokens']  ?? 4096;
		$temperature = $options['temperature'] ?? 0.7;
		$purpose     = $options['purpose']     ?? 'content_generation';

		// ── Primary path: bizcity_llm_chat() ──
		if ( function_exists( 'bizcity_llm_chat' ) ) {
			$messages = [
				[ 'role' => 'system', 'content' => 'You are a professional Vietnamese content writer. Always respond in valid JSON format.' ],
				[ 'role' => 'user',   'content' => $prompt ],
			];

			$result = bizcity_llm_chat( $messages, [
				'purpose'     => $purpose,
				'max_tokens'  => $max_tokens,
				'temperature' => $temperature,
			] );

			if ( ! empty( $result['success'] ) && ! empty( $result['message'] ) ) {
				$parsed = self::parse_json_response( $result['message'] );

				error_log( "[CONTENT-ENGINE] generate: success model={$result['model']} tokens=" . ( $result['usage']['total_tokens'] ?? 'n/a' ) );

				return [
					'success'     => true,
					'title'       => $parsed['title'] ?? '',
					'content'     => $parsed['content'] ?? $result['message'],
					'metadata'    => array_diff_key( $parsed, [ 'title' => 1, 'content' => 1 ] ),
					'tokens_used' => $result['usage']['total_tokens'] ?? 0,
					'model'       => $result['model'] ?? '',
				];
			}

			error_log( "[CONTENT-ENGINE] generate: FAILED error=" . ( $result['error'] ?? 'unknown' ) );

			return [
				'success'     => false,
				'title'       => '',
				'content'     => '',
				'metadata'    => [],
				'tokens_used' => 0,
				'model'       => '',
				'error'       => $result['error'] ?? 'LLM call failed',
			];
		}

		// ── Fallback: legacy ai_generate_content() ──
		if ( function_exists( 'ai_generate_content' ) ) {
			error_log( '[CONTENT-ENGINE] generate: using legacy ai_generate_content fallback' );
			$fields = ai_generate_content( $prompt );
			return [
				'success'     => ! empty( $fields['content'] ) || ! empty( $fields['title'] ),
				'title'       => $fields['title'] ?? '',
				'content'     => $fields['content'] ?? '',
				'metadata'    => [],
				'tokens_used' => 0,
				'model'       => 'legacy',
			];
		}

		return [
			'success' => false,
			'title'   => '',
			'content' => '',
			'error'   => 'No LLM provider available (bizcity_llm_chat or ai_generate_content)',
		];
	}

	/**
	 * Parse a JSON object from LLM response text.
	 *
	 * Handles common LLM quirks: markdown code fences, trailing text, BOM.
	 *
	 * @param string $text Raw LLM output.
	 * @return array Parsed associative array (empty on failure).
	 */
	public static function parse_json_response( string $text ): array {
		// Strip BOM
		$text = ltrim( $text, "\xEF\xBB\xBF" );

		// Try direct parse first
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Strip markdown code fences: ```json ... ``` or ``` ... ```
		if ( preg_match( '/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $text, $m ) ) {
			$decoded = json_decode( $m[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Extract first { ... } block
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( $start !== false && $end !== false && $end > $start ) {
			$json_str = substr( $text, $start, $end - $start + 1 );
			$decoded  = json_decode( $json_str, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		// Last resort: return raw text as content
		return [ 'content' => $text ];
	}
}
