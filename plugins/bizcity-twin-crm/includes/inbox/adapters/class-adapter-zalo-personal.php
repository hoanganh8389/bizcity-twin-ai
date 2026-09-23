<?php
/**
 * BizCity CRM — Zalo Personal customer-care adapter.
 *
 * Reuses the generic Zalo normalizer and CRM repository, while routing outbound
 * messages through the zca-bridge sidecar instead of a Bot/OA access token.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39B 2026-08-21
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Adapter_ZaloPersonal' ) ) {
	return;
}

class BizCity_CRM_Adapter_ZaloPersonal extends BizCity_CRM_Adapter_Zalo {

	public function code(): string {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — keep Personal distinct in CRM adapter routing.
		return 'zalo_personal';
	}

	public function label(): string {
		return 'Zalo Cá nhân (CSKH)';
	}

	public function normalize_inbound( array $raw ): ?array {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — reuse Zalo normalization while retaining Personal map linkage.
		$normalized = parent::normalize_inbound( $raw );
		if ( ! is_array( $normalized ) ) {
			return null;
		}
		$is_group = 'group' === sanitize_key( (string) ( $raw['thread_kind'] ?? '' ) ) || ! empty( $raw['is_group'] );
		$group_id = (string) ( $raw['thread_id'] ?? $raw['conversation_id'] ?? '' );
		if ( $is_group && $group_id === '' ) {
			return null;
		}
		if ( $is_group ) {
			// [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — group_id owns the CRM conversation; sender identity stays message metadata.
			$normalized['source_id'] = 'group:' . $group_id;
			$normalized['contact_name'] = (string) ( $raw['group_name'] ?? '' );
			if ( $normalized['contact_name'] === '' ) {
				$normalized['contact_name'] = 'Nhóm Zalo ' . substr( $group_id, -8 );
			}
			$normalized['thread_kind'] = 'group';
			$normalized['group_id'] = $group_id;
			$normalized['group_name'] = (string) ( $raw['group_name'] ?? '' );
			$normalized['sender_user_id'] = (string) ( $raw['from_user_id'] ?? '' );
			$normalized['sender_name'] = (string) ( $raw['from_user_name'] ?? '' );
			$normalized['ai_metadata'] = array_merge( (array) ( $normalized['ai_metadata'] ?? array() ), array(
				'thread_kind' => 'group',
				'group_id' => $group_id,
				'group_name' => (string) ( $raw['group_name'] ?? '' ),
				'sender_user_id' => (string) ( $raw['from_user_id'] ?? '' ),
				'sender_name' => (string) ( $raw['from_user_name'] ?? '' ),
				// [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-3.3 — passthrough from the same
				// $message_data UCL already reads into the bizcity_channel_normalized envelope's
				// mention_detected (class-universal-channel-listener.php:454); stamped per-message so
				// Bot_Context_Builder::history() can filter non-@mention group rows out of context
				// later without touching this ingest path (doc §6 EA-3.3 "lọc ở tầng đọc").
				'mention_detected' => ! empty( $raw['mention_detected'] ),
			) );
		}
		$normalized['inbox_name'] = 'Zalo Cá nhân ' . (string) ( $raw['account_name'] ?? $raw['conversation_id'] ?? '' );
		$normalized['_zalo_local_account_id'] = (int) ( $raw['_zalo_local_account_id'] ?? 0 );
		$normalized['_zalo_message_id']       = (string) ( $raw['message_id'] ?? '' );
		if ( is_array( $raw['quote_src'] ?? null ) && ! empty( $raw['quote_src']['msgId'] ) ) {
			$normalized['ai_metadata'] = array_merge( (array) ( $normalized['ai_metadata'] ?? array() ), array( 'quote_src' => $raw['quote_src'] ) );
		}
		// [2026-08-24 Johnny Chu] PHASE-0.39E-D1 — preserve sidecar correlation into the CRM event/archive boundary.
		$trace_id = substr( sanitize_text_field( (string) ( $raw['trace_id'] ?? '' ) ), 0, 128 );
		if ( $trace_id !== '' ) {
			$normalized['trace_id']    = $trace_id;
			$normalized['ai_metadata'] = array_merge( (array) ( $normalized['ai_metadata'] ?? array() ), array( 'trace_id' => $trace_id ) ); // [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — preserve group metadata alongside correlation.
		}
		return $normalized;
	}

	/**
	 * Send through the Personal bridge account bound to this CRM inbox.
	 *
	 * @param array $conversation CRM conversation row.
	 * @param array $message      Outbound CRM message.
	 * @return array
	 */
	public function send( array $conversation, array $message ): array {
		// [2026-08-21 Johnny Chu] PHASE-0.39B — route CRM outbound only through the Personal sidecar.
		// [2026-08-22 Johnny Chu] R-CH-FILE-LOG — record the outbound attempt before inbox/account DB reads.
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write(
				BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
				BizCity_Channel_File_Logger::LEVEL_INFO,
				'outbound_attempt',
				'Zalo Personal outbound requested.',
				array(
					'conversation_id' => (int) ( $conversation['id'] ?? 0 ),
					'inbox_id'        => (int) ( $conversation['inbox_id'] ?? 0 ),
					'content_type'    => sanitize_key( (string) ( $message['content_type'] ?? 'text' ) ),
				)
			);
		}
		$inbox = BizCity_CRM_Repository::get_inbox( (int) ( $conversation['inbox_id'] ?? 0 ) );
		if ( ! $inbox || (string) ( $inbox['channel_type'] ?? '' ) !== 'zalo_personal' ) {
			self::log_send_result( 'personal_inbox_not_found', false, $conversation );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'personal_inbox_not_found' );
		}

		$bridge_account_id = (string) ( $inbox['channel_ref_id'] ?? '' );
		$account = class_exists( 'BizCity_Zalo_Mapping_Repo' )
			? BizCity_Zalo_Mapping_Repo::find_account_by_bridge_id( 'personal', $bridge_account_id )
			: null;
		if ( ! is_array( $account ) || (int) ( $account['crm_inbox_id'] ?? 0 ) !== (int) $inbox['id'] ) {
			self::log_send_result( 'personal_account_mapping_missing', false, $conversation );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'personal_account_mapping_missing' );
		}

		$recipient = $this->resolve_uid_from_conversation( $conversation );
		if ( $recipient === '' ) {
			self::log_send_result( 'personal_recipient_missing', false, $conversation );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'personal_recipient_missing' );
		}
		$thread_kind = 'user';
		if ( 0 === strpos( $recipient, 'group:' ) ) {
			// [2026-08-25 Johnny Chu] PHASE-0.39F-GROUP-INBOX — route group replies as Group, never as a user recipient.
			$thread_kind = 'group';
			$recipient = substr( $recipient, 6 );
		}

		$text         = (string) ( $message['content'] ?? '' );
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments  = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
		// [2026-09-05 Johnny Chu - Chu Hoàng Anh] PHASE-0.39H — forward server-validated native group mentions only through Personal bridge transport.
		$mentions     = is_array( $message['mentions'] ?? null ) ? $message['mentions'] : array();
		$reply_to     = absint( $message['reply_to'] ?? 0 );
		$quote        = array();
		if ( $reply_to > 0 ) {
			$reply_row = BizCity_CRM_Repository::get_message( $reply_to );
			$reply_meta = is_array( $reply_row ) && ! empty( $reply_row['ai_metadata_json'] ) ? json_decode( (string) $reply_row['ai_metadata_json'], true ) : array();
			$quote = is_array( $reply_meta ) && is_array( $reply_meta['quote_src'] ?? null )
				? $reply_meta['quote_src']
				: ( is_array( $reply_meta['reply_to']['quote_src'] ?? null ) ? $reply_meta['reply_to']['quote_src'] : array() );
		}
		$idempotency_key = sanitize_key( (string) ( $message['idempotency_key'] ?? '' ) );
		if ( $idempotency_key === '' && ! empty( $message['id'] ) ) {
			$idempotency_key = 'crm_zp_' . absint( $message['id'] );
		}
		if ( $idempotency_key === '' ) {
			$idempotency_key = 'crm_zp_' . substr( hash( 'sha256', (int) ( $conversation['id'] ?? 0 ) . '|' . $recipient . '|' . $text ), 0, 32 );
		}
		$first        = $attachments[0] ?? array();
		$attachment_url = is_array( $first ) ? (string) ( $first['data_url'] ?? '' ) : '';
		// [2026-09-17 Johnny Chu - Chu Hoàng Anh] PHASE-0.48C-ORDER-SEND — the bridge downloads and sends any attachment URL; gating on content_type=image silently dropped PDFs/files and sent text only.
		$type         = $attachment_url === '' ? 'text' : ( $content_type === 'image' ? 'image' : 'file' );
		$attachment_meta = is_array( $first['meta'] ?? null ) ? $first['meta'] : ( is_string( $first['meta_json'] ?? null ) ? (array) json_decode( (string) $first['meta_json'], true ) : array() );
		$attachment_name = sanitize_file_name( (string) ( $attachment_meta['name'] ?? $attachment_meta['file_name'] ?? ( is_array( $first ) ? ( $first['name'] ?? '' ) : '' ) ) );
		$bridge       = class_exists( 'BizCity_Zalo_Bridge_Client' ) ? BizCity_Zalo_Bridge_Client::instance() : null;
		if ( ! $bridge ) {
			self::log_send_result( 'zalo_personal_bridge_missing', false, $conversation );
			return array( 'success' => false, 'external_source_id' => null, 'error' => 'zalo_personal_bridge_missing' );
		}

		$result = $bridge->enqueue_outbound(
			$bridge_account_id,
			$recipient,
			// [2026-08-21 Johnny Chu] PHASE-0.39B — bridge receives caption text separately from attachment URL.
			$text,
			$type,
			$type !== 'text' ? array( array( 'url' => $attachment_url, 'name' => $attachment_name ) ) : array(),
			$thread_kind,
			$mentions,
			$idempotency_key,
			$quote
		);
		$accepted = ! empty( $result['success'] ) && empty( $result['_degraded'] );
		$bridge_outcome = sanitize_key( (string) ( $result['outcome'] ?? $result['delivery_status'] ?? '' ) );
		$delivered = $accepted && in_array( $bridge_outcome, array( 'sent', 'delivered' ), true );
		$outcome = $delivered ? $bridge_outcome : ( $accepted ? 'queued' : 'failed' );
		self::log_send_result( $accepted ? ( 'queued' === $outcome ? 'outbound_queued' : 'outbound_sent' ) : 'outbound_failed', $accepted, $conversation );

		return array(
			'success'            => $accepted,
			'outcome'            => $outcome,
			'code'               => $accepted ? $outcome : 'zalo_personal_send_failed',
			'external_source_id' => (string) ( $result['message_id'] ?? ( $result['job_id'] ?? '' ) ),
			'error'              => $accepted ? null : (string) ( $result['message'] ?? 'zalo_personal_send_failed' ),
			'retryable'          => ! empty( $result['retryable'] ),
		);
	}

	private static function log_send_result( string $reason, bool $success, array $conversation ): void {
		// [2026-08-22 Johnny Chu] R-CH-FILE-LOG — record the final Personal outbound result without message content.
		if ( ! class_exists( 'BizCity_Channel_File_Logger' ) ) {
			return;
		}
		BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_PERSONAL,
			$success ? BizCity_Channel_File_Logger::LEVEL_INFO : BizCity_Channel_File_Logger::LEVEL_ERROR,
			$success ? 'outbound_accepted' : 'outbound_failed',
			$success ? 'Zalo Personal outbound accepted by bridge.' : 'Zalo Personal outbound failed.',
			array(
				'reason'         => $reason,
				'conversation_id'=> (int) ( $conversation['id'] ?? 0 ),
				'inbox_id'       => (int) ( $conversation['inbox_id'] ?? 0 ),
			)
		);
	}
}