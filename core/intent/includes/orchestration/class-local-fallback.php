<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Local Fallback — Phase 1.11 S5
 *
 * 3-tier fallback when server Smart Classifier is unavailable:
 *
 *   Tier 1: Pre-rules (already executed before this class is called)
 *   Tier 2: Local pattern match (regex → tool / mode classification)
 *   Tier 3: Knowledge LLM fallback (direct chat, no classification)
 *
 * Trigger conditions:
 *   - Server timeout > 5s
 *   - WP_Error from wp_remote_post()
 *   - Server returns HTTP error
 *
 * @since Phase 1.11 S5
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Local_Fallback {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[LocalFallback]';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Main: resolve
	 * ================================================================ */

	/**
	 * Resolve using local classification (no server).
	 *
	 * @param string $message      User message.
	 * @param array  $params       Request params.
	 * @param array  $conversation Conversation row.
	 * @param array  $result       Partially-built result array.
	 * @return array Updated result.
	 */
	public function resolve( string $message, array $params, array $conversation, array $result ): array {
		$msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );

		// Tier 2A: Execution pattern match → tool
		$tool_match = $this->match_tool_pattern( $msg_lower );
		if ( $tool_match ) {
			error_log( self::LOG . ' Tier 2A: tool pattern match → ' . $tool_match['tool'] );
			return $this->execute_tool( $tool_match, $message, $params, $conversation, $result );
		}

		// Tier 2B: Mode pattern match (emotion, knowledge)
		$mode = $this->classify_mode_local( $msg_lower );
		error_log( self::LOG . " Tier 2B: local mode = {$mode}" );

		if ( $mode === 'emotion' ) {
			$result['action'] = 'passthrough';
			$result['meta']['fallback'] = 'tier2_emotion';
			$result['meta']['mode']     = 'emotion';
			return $result;
		}

		// Tier 3: Knowledge LLM fallback → passthrough to Chat Gateway
		$result['action'] = 'passthrough';
		$result['meta']['fallback'] = 'tier3_knowledge';
		$result['meta']['mode']     = $mode;

		return $result;
	}

	/* ================================================================
	 *  Tier 2A: Tool pattern match
	 * ================================================================ */

	/**
	 * Match message to a tool using keyword patterns.
	 *
	 * @param string $msg_lower Lowercase message.
	 * @return array|null { tool, entities } or null.
	 */
	private function match_tool_pattern( string $msg_lower ): ?array {
		$patterns = [
			// Post Facebook (explicit mention)
			'/(?:đăng|post|share|chia sẻ).*(?:facebook|fb|fanpage)/u' => [
				'tool' => 'post_facebook',
				'entities' => [],
			],
			// Publish article ("đăng bài viết" = write + publish flow)
			'/(?:đăng)\s*(?:bài viết|bài|một bài)/u' => [
				'tool' => 'write_article',
				'entities' => [],
			],
			// Write article
			'/(?:viết|write|soạn|tạo).*(?:bài|article|blog|post|nội dung)/u' => [
				'tool' => 'write_article',
				'entities' => [],
			],
			// Create product
			'/(?:tạo|thêm|create|add).*(?:sản phẩm|product|sp)/u' => [
				'tool' => 'create_product',
				'entities' => [],
			],
			// Create video
			'/(?:tạo|make|create).*(?:video|clip|phim)/u' => [
				'tool' => 'create_video',
				'entities' => [],
			],
			// Reminder/task
			'/(?:nhắc|remind|đặt lịch|tạo task|tạo việc|nhắc việc)/u' => [
				'tool' => 'set_reminder',
				'entities' => [],
			],
			// Reports
			'/(?:báo cáo|report|thống kê|xuất nhập tồn|inventory)/u' => [
				'tool' => 'generate_report',
				'entities' => [],
			],
		];

		foreach ( $patterns as $pattern => $match ) {
			if ( preg_match( $pattern, $msg_lower ) ) {
				// Verify tool exists
				if ( class_exists( 'BizCity_Intent_Tools' ) && BizCity_Intent_Tools::instance()->has( $match['tool'] ) ) {
					return $match;
				}
			}
		}

		return null;
	}

	/* ================================================================
	 *  Tier 2B: Local mode classification
	 * ================================================================ */

	/**
	 * Classify message mode using regex patterns (0 LLM).
	 *
	 * @param string $msg_lower Lowercase message.
	 * @return string Mode: emotion|reflection|knowledge|execution
	 */
	private function classify_mode_local( string $msg_lower ): string {
		// Emotion patterns
		if ( preg_match( '/^(cảm ơn|thanks|thank you|tuyệt|great|hay quá|buồn|vui|haha|hihi|ok|ổn|tốt|hay|wow|nice|cool)[\s!.]*$/u', $msg_lower ) ) {
			return 'emotion';
		}

		// Execution patterns (intent to DO something)
		if ( preg_match( '/(hãy|giúp tôi|cho tôi|tạo|viết|đăng|gửi|tìm|nghiên cứu|phân tích|xử lý|thực hiện|làm)/u', $msg_lower ) ) {
			return 'execution';
		}

		// Default: knowledge (Q&A, chitchat)
		return 'knowledge';
	}

	/* ================================================================
	 *  Tool execution
	 * ================================================================ */

	/**
	 * Execute a matched tool — same pattern as legacy engine call_tool.
	 */
	private function execute_tool( array $match, string $message, array $params, array $conversation, array $result ): array {
		$tool_name = $match['tool'];
		$entities  = $match['entities'] ?? [];

		// Get current slot state and merge entities
		$slots = $conversation['slots'] ?? [];
		$slots = array_merge( $slots, $entities );
		$slots['_message'] = $message; // raw message for tools that need it

		// Validate required inputs
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$tools  = BizCity_Intent_Tools::instance();
			$missing = $tools->validate_inputs( $tool_name, $slots );

			if ( ! empty( $missing ) ) {
				// Ask for missing fields
				$first_missing = $missing[0] ?? 'thông tin';
				$result['reply']  = "Để thực hiện {$tool_name}, mình cần bạn cung cấp: {$first_missing}";
				$result['action'] = 'ask_user';
				$result['meta']['fallback'] = 'tier2_tool_ask';
				$result['meta']['tool']     = $tool_name;
				$result['meta']['missing']  = $missing;

				$conv_id = $conversation['conversation_id'] ?? '';
				if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
					BizCity_Intent_Conversation::instance()->set_waiting( $conv_id, 'field', $first_missing );
					BizCity_Intent_Conversation::instance()->set_goal( $conv_id, $tool_name, $tool_name );
				}

				return $result;
			}
		}

		// All inputs present → execute (passthrough to existing engine flow)
		$result['action'] = 'call_tool';
		$result['goal']   = $tool_name;
		$result['slots']  = $slots;
		$result['meta']['fallback'] = 'tier2_tool_execute';
		$result['meta']['tool']     = $tool_name;

		return $result;
	}
}
