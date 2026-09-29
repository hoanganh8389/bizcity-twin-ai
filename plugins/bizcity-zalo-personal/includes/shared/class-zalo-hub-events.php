<?php
/**
 * BizCity Zalo Personal — Hub events other than `inbound_forward` (PHASE-0.80 Lane C 4a-5).
 *
 * The Hub relays every zalo-hub cell event on the same `/zalo-bridge/inbound` callback with an
 * `event` field (01 §5.3). `inbound_forward` keeps the historical path; this class owns the rest:
 *
 *  - `bot_reply`    → one OUTGOING CRM message per part, sender `agent_bot`,
 *                     `ai_metadata.system_source = 'zalo_hub'`, deduped by `message_id`
 *                     (falling back to `idempotency_key`) through bizcity_zalo_message_map.
 *  - `thread_state` → accepted, recorded for Bot Studio (4c-4 shows it).
 *  - `usage`, `memory_upsert`, `tenant_deleted`, `supersede_notify`, anything unknown
 *                   → `200 {ok:true, ignored:true}` (forward compatible, 01 §5.3).
 *
 * Contract fixtures: core/channel-gateway/_library/zalo-hub/contracts/fixtures/ (canonical set; the old docs copy was archived, D-P1-10).
 *
 * @package BizCity_Zalo_Personal
 * @since   1.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Zalo_Hub_Events', false ) ) {
	return;
}

final class BizCity_Zalo_Hub_Events {

	const SYSTEM_SOURCE      = 'zalo_hub';
	const THREAD_STATE_OPTION = 'bizcity_zalo_hub_thread_state';
	const THREAD_STATE_MAX    = 300;

	/**
	 * @return array{status:int, body:array}
	 */
	public static function handle( string $event, array $body ): array {
		switch ( $event ) {
			case 'bot_reply':
				return self::bot_reply( $body );
			case 'thread_state':
				return self::thread_state( $body );
		}
		self::log( 'hub_event_ignored', array( 'event' => $event, 'account_id' => (string) ( $body['account_id'] ?? '' ) ) );
		return array( 'status' => 200, 'body' => array( 'ok' => true, 'ignored' => true, 'event' => $event ) );
	}

	/**
	 * Pure shared builder for outbound CRM echoes from every Zalo Personal transport.
	 *
	 * The legacy name remains as a compatibility wrapper because existing Hub fixtures and
	 * callers use it directly. Transport provenance is metadata, never a routing branch.
	 */
	public static function build_outbound_echo( array $body ): array {
		$account_id = (string) ( $body['account_id'] ?? '' );
		$peer       = (string) ( $body['conversation_id'] ?? '' );
		$is_group   = 'group' === sanitize_key( (string) ( $body['thread_kind'] ?? '' ) );
		$attachments = array();
		foreach ( (array) ( $body['attachments'] ?? array() ) as $att ) {
			if ( is_array( $att ) && ! empty( $att['url'] ) ) {
				$attachments[] = array( 'file_type' => 'file', 'data_url' => (string) $att['url'], 'meta' => array( 'file_name' => (string) ( $att['name'] ?? '' ), 'origin' => self::SYSTEM_SOURCE ) );
			}
		}
		$sent_at = isset( $body['sent_at'] ) ? strtotime( (string) $body['sent_at'] ) : false;
		return array(
			'inbox_ref'          => $account_id,
			'inbox_name'         => 'Zalo Cá nhân ' . $account_id,
			'source_id'          => $is_group ? 'group:' . $peer : $peer,
			'contact_name'       => '',
			'content'            => (string) ( $body['text'] ?? '' ),
			'content_type'       => 'text',
			'external_source_id' => 'zalo:hub:' . self::dedupe_key( $body ),
			'received_at'        => $sent_at ? gmdate( 'Y-m-d H:i:s', $sent_at ) : current_time( 'mysql' ),
			'sender_type'        => 'agent_bot',
			// Explicit: a `manual` stamp would arm Bot Studio's staff-takeover pause (BizCity_Bot_Turn_Runner::on_message_inserted).
			'responder_kind'     => 'auto',
			'ai_metadata'        => array(
				'system_source'   => self::SYSTEM_SOURCE,
				'replier'         => self::SYSTEM_SOURCE,
				'provider'        => 'zalo_hub',
				// [2026-09-28 11:33 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.82-A3 — preserve transport provenance in the shared echo builder.
				'transport'       => (string) ( $body['transport'] ?? 'zalo_hub' ),
				// [2026-09-27 Claude Sonnet 5] PHASE-0.80 doc 28 T-2 — "agent" (model thật viết) hay một câu dự
				// phòng cố định cell tự gửi khi hỏng (`fallback_router_empty`/`fallback_step_limit`, không qua
				// Guru). Trước trường này, CRM/Bot Studio không phân biệt được "đã trả lời thật" với "cell đang
				// tự xin lỗi" nếu không đọc log cell. Cũ (cell chưa gửi field này) mặc định 'agent' — không đoán
				// ngược thành lỗi khi không rõ (R-ERROR-UX: fail an toàn về phía không cảnh báo oan).
				'reply_kind'      => in_array( (string) ( $body['reply_kind'] ?? '' ), array( 'agent', 'fallback_router_empty', 'fallback_step_limit' ), true ) ? (string) $body['reply_kind'] : 'agent',
				'trace_ref'       => sanitize_text_field( (string) ( $body['trace_ref'] ?? '' ) ),
				'idempotency_key' => sanitize_text_field( (string) ( $body['idempotency_key'] ?? '' ) ),
				'part'            => (int) ( $body['part'] ?? 1 ),
				'parts'           => (int) ( $body['parts'] ?? 1 ),
				'thread_kind'     => $is_group ? 'group' : 'personal',
				'group_id'        => $is_group ? $peer : '',
				'request_id'      => sanitize_text_field( (string) ( $body['request_id'] ?? '' ) ),
			),
			'thread_kind'        => $is_group ? 'group' : 'personal',
			'group_id'           => $is_group ? $peer : '',
			'attachments'        => $attachments,
			'trace_id'           => sanitize_text_field( (string) ( $body['trace_ref'] ?? '' ) ),
		);
	}

	/** Pure compatibility alias for the historical Hub event API. */
	public static function bot_reply_norm( array $body ): array {
		return self::build_outbound_echo( $body );
	}

	/** Zalo message id of the bot message; `idempotency_key` when the cell could not get one. */
	public static function dedupe_key( array $body ): string {
		$msg = (string) ( $body['message_id'] ?? '' );
		return '' !== $msg ? $msg : 'idem:' . (string) ( $body['idempotency_key'] ?? '' );
	}

	private static function bot_reply( array $body ): array {
		$account_id = (string) ( $body['account_id'] ?? '' );
		$peer       = (string) ( $body['conversation_id'] ?? '' );
		if ( '' === $account_id || '' === $peer || ( '' === (string) ( $body['message_id'] ?? '' ) && '' === (string) ( $body['idempotency_key'] ?? '' ) ) ) {
			return array( 'status' => 400, 'body' => array( 'ok' => false, 'code' => 'invalid_payload', 'message' => 'bot_reply thiếu account_id, conversation_id hoặc message_id/idempotency_key.', 'hint' => 'Kiểm tra hợp đồng zalo-hub-bridge/1.0 §5.3.', 'help_code' => 'invalid_param_generic' ) );
		}
		if ( ! class_exists( 'BizCity_Zalo_Mapping_Repo' ) || ! class_exists( 'BizCity_CRM_Facebook_Ingestor' ) ) {
			return array( 'status' => 503, 'body' => array( 'ok' => false, 'code' => 'module_not_loaded', 'message' => 'CRM chưa sẵn sàng nhận tin bot.', 'hint' => 'Thử lại sau; Hub/cell sẽ gửi lại.', 'help_code' => 'module_not_loaded' ) );
		}
		$account = BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $account_id );
		$local_id = $account ? (int) $account['id'] : 0;
		if ( $local_id <= 0 || (int) ( $account['owner_user_id'] ?? 0 ) <= 0 || (int) ( $account['crm_inbox_id'] ?? 0 ) <= 0 ) {
			// Same policy as the customer path: an account not bound on this site is dropped, not retried.
			return array( 'status' => 200, 'body' => array( 'ok' => true, 'ignored' => true, 'reason' => 'account_unbound' ) );
		}
		$key = self::dedupe_key( $body );
		$existing = BizCity_Zalo_Mapping_Repo::find_by_zalo_msg_id( $local_id, $key );
		$norm = self::build_outbound_echo( $body );
		if ( null !== $existing && (int) ( $existing['crm_message_id'] ?? 0 ) > 0 ) {
			$crm_id = (int) $existing['crm_message_id'];
			// [2026-09-28 Claude Opus 5.5] PHASE-0.81 P0-6 — Zalo echoes the bot's own message back (selfListen) and that
			// echo can reach this site BEFORE `bot_reply`: the cell only remembers "this msgId is the bot's" after every
			// part is sent. The echo was then stored as the owner typing on the phone (`agent`, `zalo:self:`) and the real
			// `bot_reply` was dropped as a duplicate ⇒ no `agent_bot` row, CRM shows the bot's words as a person's.
			// Same Zalo msgId ⇒ same message: turn that echo row into the bot row instead of ignoring the bot event.
			if ( self::claim_self_echo( $crm_id, $norm ) ) {
				self::log( 'hub_bot_reply_claimed_echo', array( 'account_id' => $account_id, 'crm_message_id' => $crm_id, 'trace_ref' => $norm['ai_metadata']['trace_ref'], 'part' => $norm['ai_metadata']['part'] ) );
				if ( function_exists( 'do_action' ) ) {
					do_action( 'bizcity_zalo_hub_bot_reply', $crm_id, $norm, $body );
				}
				return array( 'status' => 200, 'body' => array( 'ok' => true, 'accepted' => true, 'claimed_echo' => true, 'crm_message_id' => $crm_id ) );
			}
			return array( 'status' => 200, 'body' => array( 'ok' => true, 'accepted' => true, 'duplicate' => true, 'crm_message_id' => $crm_id ) );
		}
		if ( null === $existing ) {
			BizCity_Zalo_Mapping_Repo::save_map( array(
				'account_id'     => $local_id,
				'zalo_msg_id'    => $key,
				'zalo_thread_id' => $peer,
				'thread_kind'    => $norm['thread_kind'],
				'crm_message_id' => 0,
				'direction'      => 'out',
				'quote_src_json' => '',
			) );
		}
		$msg_id = (int) BizCity_CRM_Facebook_Ingestor::instance()->ingest_outbound( 'zalo_personal', $norm );
		if ( $msg_id <= 0 ) {
			self::log( 'hub_bot_reply_failed', array( 'account_id' => $account_id, 'trace_ref' => $norm['ai_metadata']['trace_ref'] ) );
			return array( 'status' => 503, 'body' => array( 'ok' => false, 'code' => 'crm_ingest_failed', 'message' => 'CRM chưa ghi được tin bot.', 'hint' => 'Cell sẽ gửi lại theo outbox.', 'help_code' => 'crm_ingest_failed' ) );
		}
		BizCity_Zalo_Mapping_Repo::link_crm_message( $local_id, $key, $msg_id );
		self::log( 'hub_bot_reply_accepted', array( 'account_id' => $account_id, 'crm_message_id' => $msg_id, 'trace_ref' => $norm['ai_metadata']['trace_ref'], 'part' => $norm['ai_metadata']['part'] ) );
		if ( function_exists( 'do_action' ) ) {
			do_action( 'bizcity_zalo_hub_bot_reply', $msg_id, $norm, $body );
		}
		return array( 'status' => 200, 'body' => array( 'ok' => true, 'accepted' => true, 'crm_message_id' => $msg_id ) );
	}

	/**
	 * Pure: the column update that turns a self-echo CRM row into this `bot_reply` row, or null when the row is not an
	 * echo (a real bot row, a staff/CRM send, anything else is left alone). `external_source_id` is kept: it is unique per
	 * inbox and already names this Zalo message.
	 *
	 * @param array $row  CRM message row (`sender_type`, `external_source_id`, `ai_metadata_json`).
	 * @param array $norm bot_reply_norm() of the event.
	 */
	public static function echo_claim_patch( array $row, array $norm ): ?array {
		if ( 'agent' !== (string) ( $row['sender_type'] ?? '' ) || 0 !== strpos( (string) ( $row['external_source_id'] ?? '' ), 'zalo:self:' ) ) {
			return null;
		}
		$meta = json_decode( (string) ( $row['ai_metadata_json'] ?? '' ), true );
		$meta = is_array( $meta ) ? $meta : array();
		if ( 'native_zalo' !== (string) ( $meta['zalo_personal_origin'] ?? '' ) ) {
			return null;
		}
		$meta = array_merge( $meta, $norm['ai_metadata'], array( 'zalo_personal_origin' => 'bot', 'claimed_from' => 'self_echo' ) );
		return array(
			'sender_type'      => 'agent_bot',
			'responder_kind'   => 'auto',
			'ai_metadata_json' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
		);
	}

	private static function claim_self_echo( int $crm_id, array $norm ): bool {
		global $wpdb;
		if ( $crm_id <= 0 || ! isset( $wpdb ) || ! class_exists( 'BizCity_CRM_DB_Installer_V2' ) ) {
			return false;
		}
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_messages();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, sender_type, external_source_id, ai_metadata_json FROM {$tbl} WHERE id = %d", $crm_id ), ARRAY_A );
		$patch = is_array( $row ) ? self::echo_claim_patch( $row, $norm ) : null;
		if ( null === $patch ) {
			return false;
		}
		return false !== $wpdb->update( $tbl, $patch, array( 'id' => $crm_id ), array( '%s', '%s', '%s' ), array( '%d' ) );
	}

	private static function thread_state( array $body ): array {
		$account_id = (string) ( $body['account_id'] ?? '' );
		$conversation = (string) ( $body['conversation_id'] ?? '' );
		if ( '' === $account_id || '' === $conversation ) {
			return array( 'status' => 400, 'body' => array( 'ok' => false, 'code' => 'invalid_payload', 'message' => 'thread_state thiếu account_id hoặc conversation_id.', 'hint' => 'Kiểm tra hợp đồng zalo-hub-bridge/1.0 §5.3.', 'help_code' => 'invalid_param_generic' ) );
		}
		$states = get_option( self::THREAD_STATE_OPTION, array() );
		$states = is_array( $states ) ? $states : array();
		$states[ $account_id . '|' . $conversation ] = array(
			'bot_enabled'  => ! empty( $body['bot_enabled'] ),
			'paused_until' => sanitize_text_field( (string) ( $body['paused_until'] ?? '' ) ),
			'reason'       => sanitize_key( (string) ( $body['reason'] ?? '' ) ),
			'at'           => time(),
		);
		if ( count( $states ) > self::THREAD_STATE_MAX ) {
			uasort( $states, static function ( $a, $b ) { return (int) ( $b['at'] ?? 0 ) <=> (int) ( $a['at'] ?? 0 ); } );
			$states = array_slice( $states, 0, self::THREAD_STATE_MAX, true );
		}
		update_option( self::THREAD_STATE_OPTION, $states, false );
		return array( 'status' => 200, 'body' => array( 'ok' => true, 'accepted' => true ) );
	}

	/** Last known Hub-side bot state of one thread (Bot Studio 4c-4). */
	public static function thread_state_for( string $account_id, string $conversation ): ?array {
		$states = get_option( self::THREAD_STATE_OPTION, array() );
		$key = $account_id . '|' . $conversation;
		return is_array( $states ) && isset( $states[ $key ] ) ? $states[ $key ] : null;
	}

	private static function log( string $event, array $context ): void {
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_ZALO_PERSONAL, BizCity_Channel_File_Logger::LEVEL_INFO, $event, 'Zalo Hub event.', $context );
		}
	}
}
