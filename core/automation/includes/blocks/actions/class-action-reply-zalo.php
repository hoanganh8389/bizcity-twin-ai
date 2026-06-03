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
			'defaults' => array( 'label' => 'reply_zalo', 'text' => '{{llm.output}}' ),
			'fields'   => array(
				array( 'name' => 'label', 'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'text',  'label' => 'Nội dung',     'type' => 'textarea' ),
			),
		);
	}
	public function execute( array $ctx, array $data ) {
		$text = (string) $this->resolve( $data['text'] ?? '', $ctx );
		if ( $text === '' ) {
			return new WP_Error( 'empty_text', 'reply_zalo: text rỗng sau khi resolve.' );
		}

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

		// PG-S9 — dry-run mode: KHÔNG gọi thật, chỉ emit synthetic outbound
		// listener event để InboxLivePanel hiện bubble với badge "DRY".
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

		// Custom override hook for legacy callers (Phase 1 fallback).
		$result = apply_filters(
			'bizcity_automation_send_message',
			null,
			array( 'channel' => 'zalo', 'chat_id' => $chat_id, 'text' => $text ),
			$ctx
		);
		if ( is_wp_error( $result ) ) { return $result; }
		if ( is_array( $result ) )    { return $result; }

		if ( $chat_id === '' ) {
			return new WP_Error(
				'no_chat_id',
				'reply_zalo: thiếu chat_id (cần trigger.chat_id hoặc account_id+user_id).'
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
}
