<?php
/**
 * BizCity CRM channel framework contract.
 *
 * Validates the shared inbound envelope and normalizes outbound outcomes for
 * every adapter without owning provider transport or channel credentials.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.39F 2026-08-24
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Channel_Contract', false ) ) {
	return;
}

final class BizCity_CRM_Channel_Contract {

	const VERSION = '1.1.0'; // [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - separate CRM enablement from channel zone.

	/** Return the framework descriptor for a channel code. */
	public static function describe( string $code ): array {
		$code = sanitize_key( $code );
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W5 — resolve registered channel policy through the SDK facade before retaining the legacy compatibility descriptor.
		if ( class_exists( 'BizCity_Framework_SDK' ) ) {
			$manifest_channel = BizCity_Framework_SDK::channel_policy( $code );
			if ( is_array( $manifest_channel ) ) {
				$crm_enabled = 'enabled' === (string) ( $manifest_channel['crm_policy'] ?? '' );
				$zone        = (string) ( $manifest_channel['zone'] ?? 'unknown' );
				return array(
					'contract_version' => self::VERSION,
					'code'             => $code,
					'zone'             => $zone,
					'crm_enabled'      => $crm_enabled,
					'storage'          => $crm_enabled ? 'crm_sql_plus_encrypted_filestore' : 'none',
					'identity'         => (string) ( $manifest_channel['identity_policy'] ?? 'manifest_declared' ),
					'twinbrain'        => $crm_enabled ? (string) ( $manifest_channel['brain_policy'] ?? 'not_applicable' ) : 'none',
					'crm_event'        => $crm_enabled ? 'crm_message_received' : null,
					'ai_policy'        => $crm_enabled ? ( 'admin' === $zone ? 'automation_owner' : 'customer_autoreply' ) : 'disabled',
					'context_policy'   => (string) ( $manifest_channel['context_policy'] ?? 'none' ),
					'surface_policy'   => isset( $manifest_channel['surface_policy'] ) && is_array( $manifest_channel['surface_policy'] ) ? array_values( $manifest_channel['surface_policy'] ) : array(),
					'log_contract'     => (string) ( $manifest_channel['log_contract'] ?? 'channel-diagnostics-record@1.x' ),
				);
			}
		}
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - classify CRM enablement independently from zone.
		// [2026-09-01 Johnny Chu] PHASE-0.41-CRM-ONE-BRAIN — keep bare Messenger and Zalo aliases quarantined until a matching runtime adapter manifest exists (core.channel.crm_adapter_matrix guards this).
		$customer_channels = array( 'facebook', 'zalo_oa', 'zalo_personal', 'webchat', 'web_widget', 'email', 'email_imap', 'instagram', 'whatsapp', 'whatsapp_cloud' );
		$admin_channels    = array( 'zalo_bot', 'telegram', 'twinchat_be' );
		$legacy_channels   = array( 'zalo', 'messenger' );
		$zone = in_array( $code, $customer_channels, true )
			? 'customer'
			: ( in_array( $code, $admin_channels, true ) ? 'admin' : ( in_array( $code, $legacy_channels, true ) ? 'legacy' : 'unknown' ) );
		$crm_enabled = in_array( $code, $customer_channels, true ) || $code === 'zalo_bot';
		return array(
			'contract_version' => self::VERSION,
			'code'            => $code,
			'zone'            => $zone,
			'crm_enabled'     => $crm_enabled,
			'storage'         => $crm_enabled ? 'crm_sql_plus_encrypted_filestore' : 'none',
			'identity'        => $code === 'zalo_bot' ? 'admin_channel_identity' : ( $zone === 'customer' ? 'inbox_ref_plus_source_id' : 'legacy_quarantine' ),
			'twinbrain'       => $code === 'zalo_bot' ? 'channel_owner_runtime' : ( $zone === 'customer' ? 'crm_message_received_event' : 'none' ),
			'crm_event'       => $crm_enabled ? 'crm_message_received' : null,
			'ai_policy'       => $code === 'zalo_bot' ? 'automation_owner' : ( $zone === 'customer' ? 'customer_autoreply' : 'disabled' ),
		);
	}

	/** Validate and enrich the normalized inbound envelope before CRM writes. */
	public static function normalize_inbound( string $code, array $normalized ) {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-FRAMEWORK — enforce one input contract before any CRM repository write.
		$descriptor = self::describe( $code );
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - reject only channels that are not CRM-enabled.
		if ( empty( $descriptor['crm_enabled'] ) ) {
			return new WP_Error( 'channel_zone_not_crm', 'Channel này không thuộc CRM Inbox.', array( 'status' => 400, 'channel' => $descriptor['code'] ) );
		}
		$payload_code = sanitize_key( (string) ( $normalized['channel_code'] ?? '' ) );
		if ( $payload_code !== '' && $payload_code !== $descriptor['code'] ) {
			// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - refuse a normalized envelope relabeled by a different channel boundary.
			return new WP_Error( 'channel_contract_mismatch', 'Channel payload không khớp adapter đã xác thực.', array( 'status' => 400, 'channel' => $descriptor['code'] ) );
		}
		$required = array( 'inbox_ref', 'source_id', 'content', 'content_type', 'attachments', 'external_source_id', 'received_at' );
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $normalized ) ) {
				return new WP_Error( 'channel_contract_invalid', 'Channel payload thiếu trường bắt buộc.', array( 'status' => 400, 'field' => $field, 'channel' => $descriptor['code'] ) );
			}
		}
		if ( (string) $normalized['inbox_ref'] === '' || (string) $normalized['source_id'] === '' || (string) $normalized['external_source_id'] === '' ) {
			return new WP_Error( 'channel_contract_invalid', 'Channel payload thiếu định danh ổn định.', array( 'status' => 400, 'channel' => $descriptor['code'] ) );
		}
		if ( ! is_array( $normalized['attachments'] ) ) {
			return new WP_Error( 'channel_contract_invalid', 'Danh sách attachment của channel không hợp lệ.', array( 'status' => 400, 'channel' => $descriptor['code'] ) );
		}
		$attachments = array();
		foreach ( $normalized['attachments'] as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $attachment['file_type'] ?? $attachment['type'] ?? 'file' ) );
			$attachments[] = array(
				'file_type' => in_array( $type, array( 'text', 'image', 'audio', 'video', 'file' ), true ) ? $type : 'file',
				'data_url'  => (string) ( $attachment['data_url'] ?? $attachment['url'] ?? '' ),
				'thumb_url' => isset( $attachment['thumb_url'] ) ? (string) $attachment['thumb_url'] : null,
				'meta'      => isset( $attachment['meta'] ) && is_array( $attachment['meta'] ) ? $attachment['meta'] : array(),
			);
		}
		$normalized['attachments'] = $attachments;
		$normalized['channel_code'] = $descriptor['code'];
		$normalized['contract_version'] = self::VERSION;
		// [2026-08-30 Johnny Chu] R-CRM-ZALOBOT-ADMIN-ZONE - expose zone and AI ownership to downstream listeners.
		$normalized['framework'] = array(
			'zone'        => $descriptor['zone'],
			'crm_enabled' => $descriptor['crm_enabled'],
			'crm_event'   => $descriptor['crm_event'],
			'storage'     => $descriptor['storage'],
			'identity'    => $descriptor['identity'],
			'twinbrain'   => $descriptor['twinbrain'],
			'ai_policy'   => $descriptor['ai_policy'],
		);
		$normalized['identity'] = array(
			'inbox_ref'          => (string) $normalized['inbox_ref'],
			'source_id'          => (string) $normalized['source_id'],
			'external_source_id' => (string) $normalized['external_source_id'],
		);
		return $normalized;
	}

	/**
	 * Return the descriptor only when a channel is allowed to write CRM state.
	 *
	 * @param string $code Channel adapter code.
	 * @return array|WP_Error
	 */
	public static function require_crm_enabled( string $code ) {
		// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - apply the same CRM-enabled gate to outbound and non-ingestor writers.
		$descriptor = self::describe( $code );
		if ( empty( $descriptor['crm_enabled'] ) ) {
			return new WP_Error( 'channel_zone_not_crm', 'Channel này không được phép ghi CRM.', array( 'status' => 400, 'channel' => $descriptor['code'] ) );
		}
		if ( class_exists( 'BizCity_CRM_Channel_Registry' ) && ! BizCity_CRM_Channel_Registry::get( $descriptor['code'] ) ) {
			// [2026-09-01 Johnny Chu] R-CRM-CHANNEL-CONTRACT - fail closed when a contract label has no registered runtime adapter.
			return new WP_Error( 'channel_adapter_unavailable', 'Channel chưa có adapter runtime được đăng ký.', array( 'status' => 503, 'channel' => $descriptor['code'] ) );
		}
		return $descriptor;
	}

	/** Normalize provider adapter results into one internal outbound outcome. */
	public static function normalize_send_result( string $code, $result ): array {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-FRAMEWORK — normalize every outbound provider result before CRM status mutation.
		if ( is_wp_error( $result ) ) {
			return array(
				'success'            => false,
				'outcome'            => 'failed',
				'code'               => sanitize_key( $result->get_error_code() ?: 'channel_send_failed' ),
				'external_source_id' => null,
				'error'              => $result->get_error_message(),
				'retryable'          => false,
				'channel_code'       => sanitize_key( $code ),
				'contract_version'   => self::VERSION,
			);
		}
		if ( ! is_array( $result ) ) {
			$result = array();
		}
		$success = ! empty( $result['success'] );
		$error = (string) ( $result['error'] ?? $result['message'] ?? '' );
		$explicit_code = (string) ( $result['code'] ?? $result['error_code'] ?? '' );
		$normalized = array_merge( $result, array(
			'success'            => $success,
			'outcome'            => $success ? 'accepted' : 'failed',
			'code'               => sanitize_key( $explicit_code !== '' ? $explicit_code : ( $success ? 'sent' : 'channel_send_failed' ) ),
			'external_source_id' => isset( $result['external_source_id'] ) && $result['external_source_id'] !== '' ? (string) $result['external_source_id'] : null,
			'error'              => $error !== '' ? $error : null,
			'retryable'          => isset( $result['retryable'] ) ? (bool) $result['retryable'] : false,
			'channel_code'       => sanitize_key( $code ),
			'contract_version'   => self::VERSION,
		) );
		return $normalized;
	}
}
