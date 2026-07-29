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

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — resolve workflow owner before Messenger send.
		$owner_user_id = $this->resolve_owner_user_id( $ctx, 0 );

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

		if ( $chat_id === '' ) {
			return new WP_Error(
				'no_chat_id',
				'reply_fb_message: thiếu chat_id (cần trigger.chat_id hoặc account_id+user_id).'
			);
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — non-admin workflows may only message through their own Facebook Page.
		$owner_check = $this->assert_facebook_chat_owner( $chat_id, $owner_user_id, $ctx );
		if ( is_wp_error( $owner_check ) ) {
			return $owner_check;
		}

		// Dry-run mode — emit synthetic outbound event, không gọi API thật.
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — dry-run also passes owner guard above.
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

	/**
	 * [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — verify Messenger target Page belongs to workflow owner.
	 *
	 * @return true|WP_Error
	 */
	private function assert_facebook_chat_owner( string $chat_id, int $owner_user_id, array $ctx ) {
		$chat_id       = trim( $chat_id );
		$owner_user_id = (int) $owner_user_id;

		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$trigger_owner = (int) ( $trigger['wp_user_id'] ?? $trigger['_owner_user_id'] ?? 0 );
		$trigger_chat  = (string) ( $trigger['chat_id'] ?? '' );
		if ( $owner_user_id > 0 && $trigger_owner === $owner_user_id && $trigger_chat !== '' && $trigger_chat === $chat_id ) {
			return true;
		}

		if ( $owner_user_id > 0 && function_exists( 'user_can' ) && user_can( $owner_user_id, 'manage_options' ) ) {
			return true;
		}

		if ( $owner_user_id <= 0 ) {
			return true; // legacy/admin workflows without owner context keep backward compatibility.
		}

		$page_id = '';
		if ( preg_match( '/^fb_([^_]+)_.+$/', $chat_id, $m ) ) {
			$page_id = (string) $m[1];
		} elseif ( ! empty( $trigger['account_id'] ) ) {
			$page_id = (string) $trigger['account_id'];
		} elseif ( ! empty( $trigger['page_id'] ) ) {
			$page_id = (string) $trigger['page_id'];
		}

		if ( $page_id === '' ) {
			return new WP_Error( 'permission_denied', 'reply_fb_message: không xác định được page_id để verify ownership.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_facebook_bots';
		$has_table = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		if ( ! $has_table ) {
			return new WP_Error( 'module_not_loaded', 'reply_fb_message: bảng Facebook bot chưa sẵn sàng để verify ownership.' );
		}
		$owner = $wpdb->get_var( $wpdb->prepare(
			'SELECT user_id FROM ' . $table . ' WHERE page_id = %s AND (status = %s OR status = %s OR status = %s OR status = %s) LIMIT 1',
			$page_id,
			'active',
			'enabled',
			'1',
			''
		) );
		$owner = null === $owner ? -1 : (int) $owner;

		if ( $owner === $owner_user_id ) {
			return true;
		}

		return new WP_Error( 'permission_denied', 'reply_fb_message: fanpage Messenger không thuộc owner của workflow.' );
	}
}
