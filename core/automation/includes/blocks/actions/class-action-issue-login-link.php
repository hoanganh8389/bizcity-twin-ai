<?php
/**
 * Action: Issue the canonical Zalo Bot login-link response.
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Automation_Action_Issue_Login_Link' ) ) {
	return;
}

final class BizCity_Automation_Action_Issue_Login_Link extends BizCity_Automation_Block_Base {

	public function id(): string { return 'action.issue_login_link'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Gửi hướng dẫn liên kết tài khoản',
			'short'    => 'issue_login_link',
			'category' => 'identity',
			'color'    => '#0f766e',
			'icon'     => 'link',
			'defaults' => array( 'label' => 'Gửi hướng dẫn liên kết tài khoản' ),
			'fields'   => array( array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ) ),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-09-01 Johnny Chu] PHASE-0.45-W4 — workflow owns unlinked response; private link and group guidance use separate safe targets.
		$trigger = isset( $ctx['trigger'] ) && is_array( $ctx['trigger'] ) ? $ctx['trigger'] : array();
		$bot_id  = (int) ( $trigger['bot_id'] ?? $trigger['account_id'] ?? 0 );
		$zalo_uid = trim( (string) ( $trigger['sender_user_id'] ?? $trigger['user_id'] ?? '' ) );
		$chat_kind = sanitize_key( (string) ( $trigger['chat_kind'] ?? 'private' ) );
		$conversation_chat_id = trim( (string) ( $trigger['conversation_chat_id'] ?? $trigger['chat_id'] ?? '' ) );
		if ( $bot_id <= 0 || $zalo_uid === '' ) {
			return $this->error_result( 'invalid_param', 'Thiếu thông tin người gửi Zalo Bot.', 'Nhận lại tin nhắn rồi thử lại.', 'invalid_param_generic' );
		}

		if ( $chat_kind === 'group' ) {
			if ( $conversation_chat_id === '' || ! function_exists( 'bizcity_channel_send' ) ) {
				return $this->error_result( 'gateway_degraded', 'Chưa gửi được hướng dẫn liên kết trong nhóm.', 'Nhắn riêng Bot BizCity để liên kết tài khoản.', 'gateway_degraded' );
			}
			$result = bizcity_channel_send( $conversation_chat_id, 'Bạn cần nhắn riêng Bot BizCity hoặc dùng /link trong Twin GPT để liên kết tài khoản.' );
			if ( is_array( $result ) && empty( $result['sent'] ) ) {
				return $this->error_result( 'gateway_degraded', 'Chưa gửi được hướng dẫn liên kết trong nhóm.', 'Nhắn riêng Bot BizCity để liên kết tài khoản.', 'gateway_degraded' );
			}
			return array( 'ok' => true, 'success' => true, 'linked' => false, 'chat_kind' => 'group', 'sent' => true, 'target' => $conversation_chat_id );
		}

		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) || ! function_exists( 'bizcity_get_zalo_bot_api' ) ) {
			return $this->error_result( 'module_not_loaded', 'Tính năng liên kết Zalo Bot chưa sẵn sàng.', 'Kiểm tra module Zalo Bot rồi thử lại.', 'module_not_loaded' );
		}
		global $wpdb;
		$bot_table = $wpdb->prefix . 'bizcity_zalo_bots';
		$bot = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bot_table} WHERE id = %d LIMIT 1", $bot_id ) );
		if ( ! $bot ) {
			return $this->error_result( 'not_found', 'Không tìm thấy Bot BizCity.', 'Chọn lại Bot rồi thử lại.', 'not_found_generic' );
		}
		$sent = BizCity_Zalobot_User_Linker::maybe_send_login_link( $zalo_uid, $bot_id, $bot, (string) ( $trigger['display_name'] ?? '' ) );
		return array( 'ok' => true, 'success' => true, 'linked' => false, 'chat_kind' => 'private', 'sent' => (bool) $sent, 'target' => $zalo_uid );
	}

	private function error_result( string $code, string $message, string $hint, string $help_code ): array {
		$payload = class_exists( 'BizCity_Error_Payload' ) ? BizCity_Error_Payload::make( $code, $message, $hint, $help_code ) : array( 'success' => false, '_degraded' => true, 'code' => $code, 'message' => $message, 'hint' => $hint, 'help_code' => $help_code );
		return array_merge( $payload, array( 'ok' => 0, 'linked' => false, 'sent' => false, 'trace_id' => '' ) );
	}
}