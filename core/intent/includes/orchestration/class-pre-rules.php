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
 * BizCity Pre-Rules — Deterministic Router (0 LLM calls)
 *
 * Phase 1.11 S4: Handles 30-40% of messages without ANY LLM classification.
 *
 * Rule categories:
 *   SKILL rules      — /slash → skill match → recipe parse → pipeline or inject
 *   WAITING rules    — regex-based CONFIRM/CLARIFY/HIL/PLAN resume
 *   TOOL CONTEXT     — post-tool follow-up (satisfied/retry/next)
 *   BUILT-IN         — /help, /settings, /cancel
 *
 * Returns: [ 'handled' => true, 'result' => [...] ] or [ 'handled' => false ]
 *
 * @since Phase 1.11 S4
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Pre_Rules {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[PreRules]';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ================================================================
	 *  Main entry: resolve
	 * ================================================================ */

	/**
	 * Attempt deterministic resolution of a message.
	 *
	 * @param string $message      User message text.
	 * @param array  $params       Original request params.
	 * @param array  $conversation Conversation row.
	 * @return array { handled: bool, result?: array, rule?: string }
	 */
	public function resolve( string $message, array $params, array $conversation ): array {
		$msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );

		// 1. Built-in commands (highest priority — escape hatches)
		$builtin = $this->check_builtins( $msg_lower, $params, $conversation );
		if ( $builtin['handled'] ) {
			return $builtin;
		}

		// 2. WAITING state rules (conversation-scoped)
		$status = $conversation['status'] ?? 'ACTIVE';
		if ( $status === 'WAITING_USER' ) {
			$waiting = $this->check_waiting( $message, $msg_lower, $params, $conversation );
			if ( $waiting['handled'] ) {
				return $waiting;
			}
		}

		// 3. Slash command → Skill match
		$slash = $this->check_slash_command( $message, $params, $conversation );
		if ( $slash['handled'] ) {
			return $slash;
		}

		// 4. Skill continuation (active skill goal)
		$continuation = $this->check_skill_continuation( $message, $params, $conversation );
		if ( $continuation['handled'] ) {
			return $continuation;
		}

		return [ 'handled' => false ];
	}

	/* ================================================================
	 *  Rule: Built-in commands
	 * ================================================================ */

	private function check_builtins( string $msg_lower, array $params, array $conversation ): array {
		// /help
		if ( preg_match( '/^\/?(help|trợ giúp|hướng dẫn)$/u', $msg_lower ) ) {
			return [
				'handled' => true,
				'rule'    => 'BUILTIN_HELP',
				'result'  => [
					'reply'  => $this->get_help_text(),
					'action' => 'chat',
					'meta'   => [ 'pre_rule' => 'BUILTIN_HELP', 'llm_calls' => 0 ],
				],
			];
		}

		// /cancel — close current conversation gracefully
		if ( preg_match( '/^\/?(cancel|hủy|dừng|stop)$/u', $msg_lower ) ) {
			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
				BizCity_Intent_Conversation::instance()->close( $conv_id );
			}
			return [
				'handled' => true,
				'rule'    => 'BUILTIN_CANCEL',
				'result'  => [
					'reply'  => 'Đã hủy. Bạn cần gì khác không?',
					'action' => 'complete',
					'meta'   => [ 'pre_rule' => 'BUILTIN_CANCEL', 'llm_calls' => 0 ],
				],
			];
		}

		return [ 'handled' => false ];
	}

	/* ================================================================
	 *  Rule: WAITING state — deterministic CONFIRM / CLARIFY / HIL
	 * ================================================================ */

	private function check_waiting( string $message, string $msg_lower, array $params, array $conversation ): array {
		$waiting_for = $conversation['waiting_for'] ?? '';

		// WAITING_CONFIRM — accept/reject/modify
		if ( strpos( $waiting_for, 'confirm' ) !== false || strpos( $waiting_for, 'plan' ) !== false ) {
			return $this->resolve_confirm( $message, $msg_lower, $params, $conversation );
		}

		// WAITING_CLARIFY — any text = answer to clarifying question
		if ( strpos( $waiting_for, 'clarify' ) !== false || strpos( $waiting_for, 'field' ) !== false ) {
			return $this->resolve_clarify( $message, $params, $conversation );
		}

		// WAITING_HIL — pipeline resume/cancel
		if ( strpos( $waiting_for, 'hil' ) !== false || strpos( $waiting_for, 'pipeline' ) !== false ) {
			return $this->resolve_hil( $message, $msg_lower, $params, $conversation );
		}

		return [ 'handled' => false ];
	}

	/**
	 * Confirm: user says ok/yes/approve OR rejects OR modifies.
	 */
	private function resolve_confirm( string $message, string $msg_lower, array $params, array $conversation ): array {
		// Accept patterns
		if ( preg_match( '/^(ok|yes|có|đồng ý|được|xác nhận|approve|chạy đi|làm đi|go|tiếp|tiếp tục|làm luôn|chấp nhận)/u', $msg_lower ) ) {
			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
				BizCity_Intent_Conversation::instance()->resume( $conv_id );
			}

			return [
				'handled' => true,
				'rule'    => 'WAITING_CONFIRM_ACCEPT',
				'result'  => [
					'reply'  => null, // Shell will continue pipeline execution
					'action' => 'resume_pipeline',
					'meta'   => [ 'pre_rule' => 'WAITING_CONFIRM_ACCEPT', 'llm_calls' => 0 ],
				],
			];
		}

		// Reject patterns
		if ( preg_match( '/^(no|không|hủy|reject|cancel|thôi|bỏ|dừng)/u', $msg_lower ) ) {
			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
				BizCity_Intent_Conversation::instance()->close( $conv_id );
			}

			return [
				'handled' => true,
				'rule'    => 'WAITING_CONFIRM_REJECT',
				'result'  => [
					'reply'  => 'Đã hủy. Bạn cần gì khác không?',
					'action' => 'complete',
					'meta'   => [ 'pre_rule' => 'WAITING_CONFIRM_REJECT', 'llm_calls' => 0 ],
				],
			];
		}

		// Modify — user says something else → treat as new info → fall through
		return [ 'handled' => false ];
	}

	/**
	 * Clarify: any message = answer to the clarifying question.
	 */
	private function resolve_clarify( string $message, array $params, array $conversation ): array {
		$conv_id       = $conversation['conversation_id'] ?? '';
		$waiting_field = $conversation['waiting_field'] ?? '';

		if ( empty( $conv_id ) || empty( $waiting_field ) ) {
			return [ 'handled' => false ];
		}

		// Store the answer as a slot
		if ( class_exists( 'BizCity_Intent_Conversation' ) ) {
			$mgr = BizCity_Intent_Conversation::instance();
			$mgr->update_slots( $conv_id, [ $waiting_field => trim( $message ) ] );
			$mgr->resume( $conv_id );
		}

		return [
			'handled' => true,
			'rule'    => 'WAITING_CLARIFY_ANSWER',
			'result'  => [
				'reply'  => null, // Shell will re-process with new slot data
				'action' => 'resume_with_slot',
				'meta'   => [
					'pre_rule'    => 'WAITING_CLARIFY_ANSWER',
					'field'       => $waiting_field,
					'value'       => mb_substr( $message, 0, 100 ),
					'llm_calls'   => 0,
				],
			],
		];
	}

	/**
	 * HIL: pipeline waiting for human input — resume or cancel.
	 */
	private function resolve_hil( string $message, string $msg_lower, array $params, array $conversation ): array {
		// Cancel pipeline
		if ( preg_match( '/^(hủy|cancel|stop|dừng|bỏ)/u', $msg_lower ) ) {
			return [
				'handled' => true,
				'rule'    => 'WAITING_HIL_CANCEL',
				'result'  => [
					'reply'  => 'Đã hủy pipeline. Bạn cần gì khác không?',
					'action' => 'cancel_pipeline',
					'meta'   => [ 'pre_rule' => 'WAITING_HIL_CANCEL', 'llm_calls' => 0 ],
				],
			];
		}

		// Any other message = user input for HIL → resume pipeline with this input
		return [
			'handled' => true,
			'rule'    => 'WAITING_HIL_INPUT',
			'result'  => [
				'reply'  => null,
				'action' => 'resume_pipeline',
				'meta'   => [
					'pre_rule'   => 'WAITING_HIL_INPUT',
					'user_input' => mb_substr( $message, 0, 500 ),
					'llm_calls'  => 0,
				],
			],
		];
	}

	/* ================================================================
	 *  Rule: Slash commands → Skill match
	 * ================================================================ */

	private function check_slash_command( string $message, array $params, array $conversation ): array {
		// Extract slash command from params or message
		$slash = $params['slash_command'] ?? '';
		if ( empty( $slash ) && preg_match( '/^\/([a-zA-Z0-9_]+)/', trim( $message ), $m ) ) {
			$slash = $m[1];
		}

		if ( empty( $slash ) ) {
			return [ 'handled' => false ];
		}

		// Look up skill by slash command via Skill Database (Phase 1.12 fix)
		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return [ 'handled' => false ];
		}

		$skill_db = BizCity_Skill_Database::instance();
		$row      = $skill_db->get_by_slash_command( $slash );

		if ( ! $row ) {
			return [ 'handled' => false ];
		}

		// Normalize DB row to expected format (frontmatter.title, content)
		$skill = $row;
		if ( ! isset( $skill['frontmatter'] ) ) {
			$skill['frontmatter'] = [
				'title' => $row['title'] ?? $slash,
				'name'  => $row['skill_key'] ?? $slash,
			];
		}

		// Parse skill body with RecipeParser
		$parsed = [ 'strategy' => 'simple', 'tool_refs' => [], 'steps' => [], 'guardrails' => [] ];
		if ( class_exists( 'BizCity_Skill_Recipe_Parser' ) ) {
			$parsed = BizCity_Skill_Recipe_Parser::instance()->parse(
				$skill['content'] ?? '',
				$skill['frontmatter'] ?? []
			);
		}

		$skill_title = $skill['frontmatter']['title'] ?? $slash;
		error_log( self::LOG . " Slash /{$slash} → skill '{$skill_title}' strategy={$parsed['strategy']}" );

		if ( $parsed['strategy'] === 'guided' || $parsed['strategy'] === 'explicit' ) {
			// Pipeline execution — fire the same action used by skill-context
			return [
				'handled' => true,
				'rule'    => 'SKILL_SLASH_PIPELINE',
				'result'  => [
					'reply'   => null,
					'action'  => 'trigger_pipeline',
					'skill'   => $skill,
					'parsed'  => $parsed,
					'meta'    => [ 'pre_rule' => 'SKILL_SLASH_PIPELINE', 'slash' => $slash, 'llm_calls' => 0 ],
				],
			];
		}

		// Simple strategy → inject into prompt (fall through to server or compose_answer)
		return [
			'handled' => true,
			'rule'    => 'SKILL_SLASH_INJECT',
			'result'  => [
				'reply'  => null,
				'action' => 'inject_skill',
				'skill'  => $skill,
				'parsed' => $parsed,
				'meta'   => [ 'pre_rule' => 'SKILL_SLASH_INJECT', 'slash' => $slash, 'llm_calls' => 0 ],
			],
		];
	}

	/* ================================================================
	 *  Rule: Skill continuation
	 * ================================================================ */

	private function check_skill_continuation( string $message, array $params, array $conversation ): array {
		$goal = $conversation['goal'] ?? '';

		// Active skill goal: "skill:contentcongnghe" etc.
		if ( strpos( $goal, 'skill:' ) !== 0 ) {
			return [ 'handled' => false ];
		}

		// Escape keywords — user wants to break free from current skill
		$msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
		$escape_patterns = '/^(tạo workflow|switch|chuyển|thoát skill|quit|exit skill)/u';
		if ( preg_match( $escape_patterns, $msg_lower ) ) {
			// Clear goal and fall through to normal processing
			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
				BizCity_Intent_Conversation::instance()->set_goal( $conv_id, '', '' );
			}
			return [ 'handled' => false ];
		}

		// Continuation — re-inject skill context
		$skill_slug = ltrim( substr( $goal, 6 ), '/' ); // remove "skill:" prefix + any leading /
		return [
			'handled' => true,
			'rule'    => 'SKILL_CONTINUATION',
			'result'  => [
				'reply'      => null,
				'action'     => 'continue_skill',
				'skill_slug' => $skill_slug,
				'meta'       => [ 'pre_rule' => 'SKILL_CONTINUATION', 'skill' => $skill_slug, 'llm_calls' => 0 ],
			],
		];
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	private function get_help_text(): string {
		return "## 📖 Hướng dẫn\n\n"
			. "- Gõ tin nhắn bình thường để trò chuyện\n"
			. "- Dùng /slash_command để kích hoạt skill (VD: `/research`, `/contentcongnghe`)\n"
			. "- Gõ 'ok' hoặc 'đồng ý' để xác nhận khi được hỏi\n"
			. "- Gõ 'hủy' hoặc /cancel để dừng tác vụ hiện tại\n"
			. "- Gửi ảnh kèm text để AI phân tích\n";
	}
}
