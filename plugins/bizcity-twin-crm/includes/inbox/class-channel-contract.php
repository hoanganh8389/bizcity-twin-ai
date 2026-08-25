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

	const VERSION = '1.0.0';

	/** Return the framework descriptor for a channel code. */
	public static function describe( string $code ): array {
		$code = sanitize_key( $code );
		$customer_channels = array( 'facebook', 'messenger', 'zalo_oa', 'zalo_personal', 'webchat', 'web_widget', 'email', 'email_imap', 'instagram', 'whatsapp', 'whatsapp_cloud' );
		$admin_channels    = array( 'zalo_bot', 'telegram', 'twinchat_be' );
		$zone = in_array( $code, $customer_channels, true )
			? 'customer'
			: ( in_array( $code, $admin_channels, true ) ? 'admin' : 'unknown' );
		return array(
			'contract_version' => self::VERSION,
			'code'            => $code,
			'zone'            => $zone,
			'storage'         => $zone === 'customer' ? 'crm_sql_plus_encrypted_filestore' : 'none',
			'identity'        => $zone === 'customer' ? 'inbox_ref_plus_source_id' : 'admin_channel_identity',
			'twinbrain'       => $zone === 'customer' ? 'crm_message_received_event' : 'channel_owner_runtime',
		);
	}

	/** Validate and enrich the normalized inbound envelope before CRM writes. */
	public static function normalize_inbound( string $code, array $normalized ) {
		// [2026-08-24 Johnny Chu] PHASE-0.39F-FRAMEWORK — enforce one input contract before any CRM repository write.
		$descriptor = self::describe( $code );
		if ( $descriptor['zone'] !== 'customer' ) {
			return new WP_Error( 'channel_zone_not_crm', 'Channel này không thuộc CRM Inbox.', array( 'status' => 400, 'channel' => $descriptor['code'] ) );
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
		$normalized['framework'] = array(
			'zone'     => $descriptor['zone'],
			'storage'  => $descriptor['storage'],
			'identity' => $descriptor['identity'],
			'twinbrain' => $descriptor['twinbrain'],
		);
		$normalized['identity'] = array(
			'inbox_ref'          => (string) $normalized['inbox_ref'],
			'source_id'          => (string) $normalized['source_id'],
			'external_source_id' => (string) $normalized['external_source_id'],
		);
		return $normalized;
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
