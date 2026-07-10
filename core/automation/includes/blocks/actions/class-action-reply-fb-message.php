<?php
/**
 * Action: Reply Facebook Message — gửi tin nhắn Messenger về người dùng.
 *
 * Đọc chat_id từ trigger (fb_<page_id>_<psid>) và gọi bizcity_channel_send()
 * để gửi qua Channel Gateway → Facebook Messenger Send API.
 *
 * Block ID: action.reply_fb_message
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      2026-06-14 (HOTFIX — Block chưa register: action.reply_fb_message)
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-14 Johnny Chu] HOTFIX — tạo block action.reply_fb_message (missing block)
final class BizCity_Automation_Action_Reply_FB_Message extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'action.reply_fb_message'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Trả lời Facebook Messenger',
			'short'    => 'reply_fb_message',
			'category' => 'output',
			'color'    => '#1877f2',
			'icon'     => 'message-circle',
			'defaults' => array(
				'label' => 'reply_fb_message',
				'text'  => '{{llm.output}}',
			),
			'fields'   => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'text',  'label' => 'Nội dung',     'type' => 'textarea' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$text = (string) $this->resolve( $data['text'] ?? '', $ctx );
		if ( $text === '' ) {
			return new WP_Error( 'empty_text', 'reply_fb_message: text rỗng sau khi resolve.' );
		}

		// Đọc chat_id từ trigger — pattern: fb_<page_id>_<psid>
		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$chat_id = (string) ( $trigger['chat_id'] ?? $ctx['chat_id'] ?? '' );

		// Fallback: ghép lại từ account_id + user_id nếu chat_id thiếu
		if ( $chat_id === '' ) {
			$page_id = (string) ( $trigger['account_id'] ?? '' );
			$psid    = (string) ( $trigger['user_id'] ?? '' );
			if ( $page_id !== '' && $psid !== '' ) {
				$chat_id = 'fb_' . $page_id . '_' . $psid;
			}
		}

		// Dry-run mode — emit synthetic outbound event, không gọi API thật
		if ( ! empty( $ctx['_dry_run'] ) ) {
			do_action( 'bizcity_listener_emit', array(
				'kind'       => 'outbound',
				'direction'  => 'out',
				'platform'   => (string) ( $trigger['platform'] ?? 'FB_MESS' ),
				'account_id' => (string) ( $trigger['account_id'] ?? '' ),
				'user_id'    => (string) ( $trigger['user_id'] ?? '' ),
				'chat_id'    => $chat_id,
				'message'    => $text,
				'event_type' => 'message',
				'meta'       => array( 'dry' => true, 'run_id' => (string) ( $ctx['_run_id'] ?? '' ) ),
			) );
			return array(
				'sent'     => true,
				'channel'  => 'facebook',
				'chat_id'  => $chat_id,
				'dry'      => true,
				'text'     => $text,
			);
		}

		if ( $chat_id === '' ) {
			return new WP_Error(
				'no_chat_id',
				'reply_fb_message: thiếu chat_id (cần trigger.chat_id hoặc account_id+user_id).'
			);
		}

		if ( ! function_exists( 'bizcity_channel_send' ) ) {
			return new WP_Error( 'gateway_missing', 'Channel Gateway sender chưa load.' );
		}

		$send = bizcity_channel_send( $chat_id, $text );
		$ok   = is_array( $send ) && ! empty( $send['sent'] );
		if ( ! $ok ) {
			return new WP_Error(
				'send_failed',
				is_array( $send ) ? (string) ( $send['error'] ?? 'unknown' ) : 'send returned non-array',
				array( 'send' => $send )
			);
		}

		return array(
			'sent'     => true,
			'channel'  => 'facebook',
			'chat_id'  => $chat_id,
			'platform' => is_array( $send ) ? ( $send['platform'] ?? 'FB_MESS' ) : 'FB_MESS',
		);
	}
}
