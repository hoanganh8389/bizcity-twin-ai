<?php
/**
 * BizCity_Automation_Default_Reply — built-in safety net.
 *
 * PHASE-0-RULE-CHANNEL-UNIFY (R-CH-UNI 1.2) — khi `Trigger_Matcher` không
 * tìm được workflow nào match (matched=[] AND fallbacks=[]), handler này
 * chạy TwinBrain MPR Think + gửi reply qua `bizcity_channel_send()` để
 * người dùng KHÔNG bao giờ nhận im lặng / login link tự động.
 *
 * Filter:
 *   - `bizcity_automation_default_reply_enabled` (default true) — tắt toàn bộ.
 *   - `bizcity_automation_default_reply_prompt` ($prompt, $payload) — sửa prompt.
 *   - `bizcity_automation_default_reply_text` ($answer, $payload, $tb_result)
 *     — chỉnh nội dung trước khi send (vd thêm CTA login).
 *
 * KHÔNG insert row vào `bizcity_automation_runs` để tránh phình bảng — handler
 * này coi như "channel response", không phải "workflow run". Nếu cần audit,
 * row vào `bizcity_channel_messages` (direction=out) là đủ.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation
 * @since      2026-05-30 (Phase 1 ship cùng PHASE-0-RULE-CHANNEL-UNIFY)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Default_Reply {

	/**
	 * Handle a normalized inbound that matched no workflow.
	 *
	 * @param array $run_payload Same shape as Trigger_Matcher run_payload.
	 *                           Required keys: `text` / `chat_id`.
	 */
	public static function handle( array $run_payload ): void {
		$chat_id = (string) ( $run_payload['chat_id'] ?? '' );
		$text    = trim( (string) ( $run_payload['text'] ?? $run_payload['message'] ?? '' ) );

		if ( $chat_id === '' || $text === '' ) {
			// Không có chat_id hoặc text → không trả lời được. Phase 2 sẽ thay
			// bằng STT/vision khi `media_kind` set.
			return;
		}

		$prompt = (string) apply_filters(
			'bizcity_automation_default_reply_prompt',
			$text,
			$run_payload
		);

		// TwinBrain bridge bắt buộc — Phase 1 không hỗ trợ fallback nào khác.
		if ( ! class_exists( 'BizCity_Automation_TwinBrain_Bridge' ) ) {
			return;
		}

		$opts = array(
			'user_id' => (int) ( $run_payload['wp_user_id']   ?? 0 ),
			'guru_id' => (int) ( $run_payload['character_id'] ?? 0 ),
			'k'       => 8,
		);

		$result = BizCity_Automation_TwinBrain_Bridge::run_with_capture(
			$prompt,
			$opts,
			static function ( $event_key, $payload ) use ( $chat_id ) {
				do_action( 'bizcity_automation_default_reply_event', $chat_id, $event_key, $payload );
			}
		);

		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return;
		}

		$answer = (string) (
			$result['final_text']  ?? $result['answer']
			?? $result['answer_md'] ?? $result['message']
			?? $result['decision']  ?? ''
		);
		$answer = trim( $answer );
		if ( $answer === '' ) { return; }

		$answer = (string) apply_filters(
			'bizcity_automation_default_reply_text',
			$answer,
			$run_payload,
			$result
		);
		if ( trim( $answer ) === '' ) { return; }

		if ( ! function_exists( 'bizcity_channel_send' ) ) { return; }

		// [2026-07-07 Johnny Chu] HOTFIX — stamp no-match default reply for outbound trace.
		$trace_id = 'auto-def-' . substr( sha1( $chat_id . '|' . $prompt . '|' . microtime( true ) ), 0, 12 );
		error_log( sprintf(
			'[automation][default-reply] send trace=%s chat_id=%s chars=%d reason=no_keyword_no_fallback',
			$trace_id,
			$chat_id,
			mb_strlen( (string) $answer )
		) );

		bizcity_channel_send( $chat_id, $answer . '-AI-', 'text', array(
			'_trace_source' => 'automation.default_reply',
			'_trace_id'     => $trace_id,
			'detail'        => 'no_keyword_no_fallback',
		) );
	}
}
