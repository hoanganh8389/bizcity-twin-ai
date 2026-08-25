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
		$normalized['inbox_name'] = 'Zalo Cá nhân ' . (string) ( $raw['account_name'] ?? $raw['conversation_id'] ?? '' );
		$normalized['_zalo_local_account_id'] = (int) ( $raw['_zalo_local_account_id'] ?? 0 );
		$normalized['_zalo_message_id']       = (string) ( $raw['message_id'] ?? '' );
		// [2026-08-24 Johnny Chu] PHASE-0.39E-D1 — preserve sidecar correlation into the CRM event/archive boundary.
		$trace_id = substr( sanitize_text_field( (string) ( $raw['trace_id'] ?? '' ) ), 0, 128 );
		if ( $trace_id !== '' ) {
			$normalized['trace_id']    = $trace_id;
			$normalized['ai_metadata'] = array( 'trace_id' => $trace_id );
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

		$text         = (string) ( $message['content'] ?? '' );
		$content_type = (string) ( $message['content_type'] ?? 'text' );
		$attachments  = is_array( $message['attachments'] ?? null ) ? $message['attachments'] : array();
		$first        = $attachments[0] ?? array();
		$attachment_url = is_array( $first ) ? (string) ( $first['data_url'] ?? '' ) : '';
		$type         = ( $content_type === 'image' && $attachment_url !== '' ) ? 'image' : 'text';
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
			$type === 'image' ? array( array( 'url' => $attachment_url, 'name' => '' ) ) : array()
		);
		$sent = ! empty( $result['success'] ) && empty( $result['_degraded'] );
		self::log_send_result( $sent ? 'outbound_accepted' : 'outbound_failed', $sent, $conversation );

		return array(
			'success'            => $sent,
			'external_source_id' => (string) ( $result['message_id'] ?? ( $result['job_id'] ?? '' ) ),
			'error'              => $sent ? null : (string) ( $result['message'] ?? 'zalo_personal_send_failed' ),
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