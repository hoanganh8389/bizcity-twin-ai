<?php
/**
 * Action: Reply Zalo (delegate sang Channel Gateway sender).
 *
 * Resolve template tokens trong `text`, sau đó:
 *   do_action( 'bizcity_channel_send', [
 *     'channel' => 'zalo',
 *     'to'      => $ctx['trigger']['user_id'] ?? '',
 *     'text'    => $text,
 *     'context' => $ctx,
 *   ] );
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-2 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Reply_Zalo extends BizCity_Automation_Block_Base {
	public function id(): string   { return 'action.reply_zalo'; }
	public function kind(): string { return 'action'; }
	public function meta(): array {
		return array(
			'label'    => 'Trả lời Zalo',
			'short'    => 'reply_zalo',
			'category' => 'output',
			'color'    => '#15803d',
			'icon'     => 'reply',
			// [2026-06-25 Johnny Chu] PHASE-REPLY-ZALO-FIX — added instance_id + override_chat_id
			'defaults' => array( 'label' => 'reply_zalo', 'text' => '{{llm.output}}', 'instance_id' => '', 'override_chat_id' => '' ),
			'fields'   => array(
				array( 'name' => 'label',            'label' => 'Tên hiển thị',        'type' => 'text' ),
				array( 'name' => 'instance_id',      'label' => 'Zalo Bot',             'type' => 'channel_instance_picker', 'platform' => 'ZALO_BOT', 'hint' => 'Chọn bot để gửi tin. Để trống = dùng bot từ trigger context.' ),
				array( 'name' => 'override_chat_id', 'label' => 'Gửi đến người dùng',  'type' => 'zalo_user_picker',        'hint' => 'Chọn user đã linked, hoặc để trống = lấy từ trigger context (khi trigger là Zalo Bot inbound).' ),
				array( 'name' => 'text',             'label' => 'Nội dung',             'type' => 'textarea' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		$text = (string) $this->resolve( $data['text'] ?? '', $ctx );
		if ( $text === '' ) {
			return new WP_Error( 'empty_text', 'reply_zalo: text rỗng sau khi resolve.' );
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — fail before sending fake success when async placeholders are still unresolved.
		if ( preg_match( '/\{\{\s*[a-z0-9_.]+\s*\}\}/i', $text ) ) {
			return new WP_Error( 'unresolved_placeholder', 'reply_zalo: nội dung còn placeholder chưa resolve. Với publish_fb_post, permalink chỉ có sau khi Scheduler publish xong; hãy để Scheduler Completion Notifier gửi link thật.' );
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — resolve workflow owner before choosing customer chat target.
		$owner_user_id = $this->resolve_owner_user_id( $ctx, 0 );

		// PHASE-0-RULE-CHANNEL-UNIFY (R-CH-UNI 1.1) — KHÔNG hardcode platform.
		// Đọc canonical chat_id từ trigger (zalobot_<bot>_<user> / fb_<page>_<psid> / …).
		// Fallback to user_id chỉ để giữ tương thích cũ; logging thì cảnh báo.
		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$chat_id = (string) (
			$trigger['chat_id']
			?? $ctx['chat_id']
			?? ''
		);
		if ( $chat_id === '' ) {
			$bot_id  = (string) ( $trigger['account_id'] ?? $trigger['bot_id'] ?? '' );
			$user_id = (string) ( $trigger['user_id']    ?? $trigger['sender_id'] ?? '' );
			if ( $bot_id !== '' && $user_id !== '' ) {
				$chat_id = 'zalobot_' . $bot_id . '_' . $user_id;
			}
		}

		// [2026-06-25 Johnny Chu] PHASE-REPLY-ZALO-FIX — override_chat_id + instance_id fields.
		// For cron/scheduled workflows there is no trigger context carrying a chat_id.
		// User must explicitly set instance_id (Zalo Bot) + override_chat_id (linked user).
		// override_chat_id is the canonical chat_id string (zalobot_<bot>_<user>) from ZaloUserPicker.
		$override_chat_id = trim( (string) $this->resolve( $data['override_chat_id'] ?? '', $ctx ) );
		if ( $override_chat_id !== '' ) {
			$chat_id = $override_chat_id;
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — cron/customer workflows default to the owner's pinned Twin GPT Zalo chat.
		$owner_zalo_target = $this->resolve_owner_mychannels_zalo_target( $owner_user_id );
		if ( $chat_id === '' && ! empty( $owner_zalo_target['chat_id'] ) ) {
			$chat_id = (string) $owner_zalo_target['chat_id'];
		}
		if ( trim( (string) ( $data['instance_id'] ?? '' ) ) === '' && ! empty( $owner_zalo_target['bot_id'] ) ) {
			$data['instance_id'] = (string) $owner_zalo_target['bot_id'];
		}

		// If override_chat_id is not set but instance_id is — try to fall back to instance+user combo.
		// This handles cases where user types a raw zalo_user_id (not the full chat_id).
		if ( $chat_id === '' ) {
			$instance_id = trim( (string) ( $data['instance_id'] ?? '' ) );
			if ( $instance_id !== '' && $override_chat_id !== '' ) {
				$chat_id = 'zalobot_' . $instance_id . '_' . $override_chat_id;
			}
		}

		// [2026-06-29 Johnny Chu] PHASE-REPLY-ZALO-FIX — actionable error: include bot name + guide.
		if ( $chat_id === '' ) {
			$inst_label = '';
			$inst       = trim( (string) ( $data['instance_id'] ?? '' ) );
			if ( $inst !== '' ) {
				$inst_label = "Bot #{$inst} ";
			}
			return new WP_Error(
				'no_chat_id',
				"reply_zalo: {$inst_label}chưa có người nhận. Mở cấu hình node \"Trả lời Zalo\" → trường \"Gửi đến người dùng\" → chọn user đã linked. (Hướng dẫn: người dùng nhắn \"chatid\" cho bot → nhận chat_id → vào Channel Gateway → Bot → User links → Bind.)"
			);
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — non-admin workflows may only reply to their own linked Zalo identity/chat.
		$owner_check = $this->assert_zalo_chat_owner( $chat_id, $owner_user_id, $ctx );
		if ( is_wp_error( $owner_check ) ) {
			return $owner_check;
		}

		// Custom override hook for legacy callers (Phase 1 fallback).
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — override executes only after owner guard.
		$result = apply_filters(
			'bizcity_automation_send_message',
			null,
			array( 'channel' => 'zalo', 'chat_id' => $chat_id, 'text' => $text ),
			$ctx
		);
		if ( is_wp_error( $result ) ) { return $result; }
		if ( is_array( $result ) )    { return $result; }

		// PG-S9 — dry-run mode: KHÔNG gọi thật, chỉ emit synthetic outbound
		// listener event để InboxLivePanel hiện bubble với badge "DRY".
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — dry-run also passes owner guard above.
		if ( ! empty( $ctx['_dry_run'] ) ) {
			do_action( 'bizcity_listener_emit', array(
				'kind'       => 'outbound',
				'direction'  => 'out',
				'platform'   => (string) ( $trigger['platform'] ?? 'ZALO_BOT' ),
				'account_id' => (string) ( $trigger['account_id'] ?? '' ),
				'user_id'    => (string) ( $trigger['user_id']    ?? '' ),
				'chat_id'    => $chat_id,
				'message'    => $text,
				'event_type' => 'message',
				'meta'       => array( 'dry' => true, 'run_id' => (string) ( $ctx['_run_id'] ?? '' ) ),
			) );
			return array(
				'sent'    => true,
				'channel' => 'zalo',
				'chat_id' => $chat_id,
				'dry'     => true,
				'text'    => $text,
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
			'channel'  => 'zalo',
			'chat_id'  => $chat_id,
			'platform' => is_array( $send ) ? ( $send['platform'] ?? '' ) : '',
		);
	}

	private function resolve_owner_mychannels_zalo_target( int $owner_user_id ): array {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — shared runtime fallback for cron templates created from /gpt My Channels.
		$owner_user_id = (int) $owner_user_id;
		if ( $owner_user_id <= 0 ) {
			return array( 'bot_id' => 0, 'chat_id' => '', 'chat_label' => '' );
		}
		// [2026-07-27 Johnny Chu] R-PERF — read My Channels once through direct-SQL user-meta cache.
		$selected = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $owner_user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $owner_user_id, 'bizcity_twinweb_mychannels', true );
		$selected = is_array( $selected ) ? $selected : array();
		$bot_id = isset( $selected['selected_zalo_bot_id'] ) ? (int) $selected['selected_zalo_bot_id'] : 0;
		$chat_id = isset( $selected['selected_zalo_chat_id'] ) ? trim( sanitize_text_field( (string) $selected['selected_zalo_chat_id'] ) ) : '';
		if ( $bot_id <= 0 && $chat_id !== '' && preg_match( '/^zalobot_(\d+)_/', $chat_id, $m ) ) {
			$bot_id = (int) $m[1];
		}
		return array(
			'bot_id'     => $bot_id,
			'chat_id'    => $chat_id,
			'chat_label' => isset( $selected['selected_zalo_chat_label'] ) ? sanitize_text_field( (string) $selected['selected_zalo_chat_label'] ) : '',
		);
	}

	/**
	 * [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — verify Zalo target belongs to workflow owner.
	 *
	 * @return true|WP_Error
	 */
	private function assert_zalo_chat_owner( string $chat_id, int $owner_user_id, array $ctx ) {
		$chat_id       = trim( $chat_id );
		$owner_user_id = (int) $owner_user_id;

		if ( $chat_id === '' ) {
			return new WP_Error( 'invalid_param', 'reply_zalo: chat_id rỗng.' );
		}

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

		if ( preg_match( '/^zalobot_(\d+)_(.+)$/', $chat_id, $m ) ) {
			$bot_id       = (int) $m[1];
			$zalo_user_id = (string) $m[2];
			if ( class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
				$linked_user_id = (int) BizCity_Zalobot_User_Linker::resolve_wp_user( $zalo_user_id, $bot_id );
				if ( $linked_user_id === $owner_user_id ) {
					return true;
				}
			}
		}

		// [2026-07-27 Johnny Chu] R-PERF — reuse the request-level My Channels cache.
		$selected = class_exists( 'BizCity_User_Meta_Cache' )
			? BizCity_User_Meta_Cache::get( $owner_user_id, 'bizcity_twinweb_mychannels', array() )
			: get_user_meta( $owner_user_id, 'bizcity_twinweb_mychannels', true );
		if ( is_array( $selected ) && ! empty( $selected['selected_zalo_chat_id'] ) && (string) $selected['selected_zalo_chat_id'] === $chat_id ) {
			return true;
		}

		return new WP_Error( 'permission_denied', 'reply_zalo: chat_id không thuộc owner của workflow.' );
	}
}
