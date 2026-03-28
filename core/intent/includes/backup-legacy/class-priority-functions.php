<?php
/**
 * BizCity Priority Functions — 3-Tier Built-in Function Dispatcher
 *
 * Handles built-in system functions BEFORE the Intent Router (Step 3).
 * Called from Intent Engine Step 2.4 when LLM classifies built_in_function.
 *
 * 3-Tier Priority:
 *   Tier 0 — Absolute: end_conversation (immediate return, no router)
 *   Tier 1 — Context: save_user_memory, forget_memory, list_memories (may continue to router)
 *   Tier 2 — Support: explain_last, summarize_session (may continue to router)
 *
 * @package  BizCity_Intent
 * @version  1.0.0
 * @since    2026-06-28 §29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Priority_Functions {

	/**
	 * Function registry: function_name => [tier, handler_method]
	 */
	const REGISTRY = [
		// Tier 0 — Absolute (immediate return)
		'end_conversation'  => [ 'tier' => 0, 'handler' => 'handle_end_conversation' ],

		// Tier 1 — Context (memory operations, may continue)
		'save_user_memory'  => [ 'tier' => 1, 'handler' => 'handle_save_memory' ],
		'forget_memory'     => [ 'tier' => 1, 'handler' => 'handle_forget_memory' ],
		'list_memories'     => [ 'tier' => 1, 'handler' => 'handle_list_memories' ],

		// Tier 2 — Support (meta-conversation)
		'explain_last'      => [ 'tier' => 2, 'handler' => 'handle_explain_last' ],
		'summarize_session' => [ 'tier' => 2, 'handler' => 'handle_summarize_session' ],
	];

	/**
	 * Dispatch a built-in function.
	 *
	 * @param string $function_name  The built_in_function from LLM classification.
	 * @param array  $context        Engine context: message, user_id, session_id, conversation, mode_result.
	 * @return array|null {
	 *   @type bool   $handled          Whether the function was fully handled.
	 *   @type string $prompt_hint      System prompt hint to inject (even if not fully handled).
	 *   @type bool   $continue_to_router  Whether execution should continue to Intent Router.
	 *   @type string $reply            Direct reply (if handled and no router needed).
	 *   @type string $action           'reply' or 'compose'.
	 * }
	 */
	public static function dispatch( string $function_name, array $context ): ?array {
		if ( empty( $function_name ) || ! isset( self::REGISTRY[ $function_name ] ) ) {
			return null;
		}

		$reg     = self::REGISTRY[ $function_name ];
		$handler = $reg['handler'];

		if ( ! method_exists( static::class, $handler ) ) {
			return null;
		}

		$result = static::$handler( $context );

		// Log for admin Console
		if ( class_exists( 'BizCity_User_Memory' ) ) {
			BizCity_User_Memory::log_router_event( [
				'step'             => 'priority_function',
				'message'          => mb_substr( $context['message'] ?? '', 0, 200, 'UTF-8' ),
				'mode'             => $context['mode_result']['mode'] ?? '',
				'confidence'       => $context['mode_result']['confidence'] ?? 0,
				'method'           => 'built_in_function',
				'functions_called' => $function_name,
				'pipeline'         => [ 'mode_classify', 'priority_function:' . $function_name ],
				'response_preview' => mb_substr( $result['prompt_hint'] ?? $result['reply'] ?? '', 0, 200, 'UTF-8' ),
				'tier'             => $reg['tier'],
			], $context['session_id'] ?? '' );
		}

		return $result;
	}

	/* ================================================================
	 * Tier 0 — Absolute Handlers
	 * ================================================================ */

	/**
	 * End conversation — complete the active conversation, return farewell.
	 */
	protected static function handle_end_conversation( array $ctx ): array {
		// Complete active conversation if exists
		if ( ! empty( $ctx['conversation']['conversation_id'] ) ) {
			$conv_mgr = BizCity_Intent_Conversation::instance();
			$conv_mgr->complete(
				$ctx['conversation']['conversation_id'],
				'User ended conversation via built-in function.'
			);
		}

		return [
			'handled'            => true,
			'prompt_hint'        => "Người dùng muốn kết thúc cuộc trò chuyện. Hãy chào tạm biệt ấm áp, ngắn gọn.",
			'continue_to_router' => false,
			'action'             => 'compose',
		];
	}

	/* ================================================================
	 * Tier 1 — Context Handlers (Memory Operations)
	 * ================================================================ */

	/**
	 * Save user memory — already handled in Step 2.3 (is_memory).
	 * This handler provides the memory_type hint for richer acknowledgment.
	 */
	protected static function handle_save_memory( array $ctx ): array {
		$memory_type = $ctx['mode_result']['meta']['memory_type'] ?? 'save_fact';

		$type_labels = [
			'save_fact'               => 'thông tin cá nhân',
			'set_response_rule'       => 'quy tắc phản hồi',
			'set_communication_style' => 'phong cách giao tiếp',
			'pin_context'             => 'ngữ cảnh liên tục',
			'set_output_format'       => 'định dạng output',
			'set_focus_topic'         => 'chủ đề quan tâm',
		];

		$label = $type_labels[ $memory_type ] ?? 'thông tin';

		return [
			'handled'            => false, // Step 2.3 already saves; just enrich prompt
			'prompt_hint'        => "Loại ghi nhớ: {$label} ({$memory_type}). Xác nhận rằng đã lưu và sẽ áp dụng ngay.",
			'continue_to_router' => false,
			'memory_type'        => $memory_type,
		];
	}

	/**
	 * Forget/delete a memory.
	 */
	protected static function handle_forget_memory( array $ctx ): array {
		$message = $ctx['message'] ?? '';
		$user_id = $ctx['user_id'] ?? 0;
		$deleted = false;

		if ( class_exists( 'BizCity_User_Memory' ) && $user_id ) {
			$memory = BizCity_User_Memory::instance();
			// Try to find and soft-delete matching memory
			$deleted = $memory->forget_by_message( $message, $user_id );
		}

		$hint = $deleted
			? "Đã xoá thông tin ký ức theo yêu cầu. Xác nhận ngắn gọn."
			: "Người dùng muốn AI quên một thông tin, nhưng không tìm thấy ký ức phù hợp. Hỏi lại cụ thể hơn.";

		return [
			'handled'            => true,
			'prompt_hint'        => $hint,
			'continue_to_router' => false,
			'action'             => 'compose',
			'memory_deleted'     => $deleted,
		];
	}

	/**
	 * List all memories about the user.
	 */
	protected static function handle_list_memories( array $ctx ): array {
		$user_id  = $ctx['user_id'] ?? 0;
		$memories = [];

		if ( class_exists( 'BizCity_User_Memory' ) && $user_id ) {
			$memories = BizCity_User_Memory::instance()->get_user_memories( $user_id, 20 );
		}

		if ( empty( $memories ) ) {
			$hint = "Chưa có ký ức nào về người dùng. Thông báo nhẹ nhàng.";
		} else {
			$items = [];
			foreach ( $memories as $m ) {
				$items[] = '• ' . ( $m['content'] ?? $m['memory_text'] ?? $m['key'] ?? '?' );
			}
			$list = implode( "\n", $items );
			$hint = "### Ký ức về người dùng:\n{$list}\n\nHãy trình bày lại gọn gàng, thân thiện.";
		}

		return [
			'handled'            => true,
			'prompt_hint'        => $hint,
			'continue_to_router' => false,
			'action'             => 'compose',
		];
	}

	/* ================================================================
	 * Tier 2 — Support Handlers
	 * ================================================================ */

	/**
	 * Explain the last AI response.
	 */
	protected static function handle_explain_last( array $ctx ): array {
		return [
			'handled'            => true,
			'prompt_hint'        => "Người dùng muốn bạn giải thích câu trả lời TRƯỚC ĐÓ. Hãy xem lại lịch sử chat và giải thích rõ ràng, dễ hiểu.",
			'continue_to_router' => false,
			'action'             => 'compose',
		];
	}

	/**
	 * Summarize the current session.
	 */
	protected static function handle_summarize_session( array $ctx ): array {
		return [
			'handled'            => true,
			'prompt_hint'        => "Người dùng muốn tóm tắt phiên trò chuyện hiện tại. Hãy tóm tắt ngắn gọn các chủ đề đã thảo luận, quyết định đã đưa ra, và thông tin quan trọng.",
			'continue_to_router' => false,
			'action'             => 'compose',
		];
	}
}
