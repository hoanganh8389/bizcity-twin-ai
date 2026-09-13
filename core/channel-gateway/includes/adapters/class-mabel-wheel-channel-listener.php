<?php
/**
 * Channel Gateway - Mabel Wheel passive intake listener.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since   PHASE-0.55
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Mabel_Wheel_Channel_Listener', false ) ) {
	return;
}

class BizCity_Mabel_Wheel_Channel_Listener {

	private static $registered = false;

	public static function init(): void {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - register both passive intake hook boundaries once.
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'wof_optin', array( __CLASS__, 'on_optin' ), 20, 1 );
		add_action( 'wof_play', array( __CLASS__, 'on_play' ), 20, 1 );
	}

	public static function on_optin( $payload ): void {
		self::ingest( $payload, 'wof_optin' );
	}

	public static function on_play( $payload ): void {
		self::ingest( $payload, 'wof_play' );
	}

	private static function ingest( $payload, string $event_name ): void {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - contain bridge failures so Mabel's response is never replaced by a CRM fatal.
		try {
			if ( ! is_array( $payload ) ) {
				return;
			}
			$payload['event_name'] = $event_name;
			$adapter = class_exists( 'BizCity_CRM_Channel_Registry' )
				? BizCity_CRM_Channel_Registry::get( 'mabel_wheel' )
				: null;
			if ( ! $adapter || ! method_exists( $adapter, 'normalize_inbound' ) ) {
				self::write_log( 'mabel_adapter_unavailable', 'Mabel Wheel adapter is unavailable.', array( 'event_name' => $event_name ) );
				return;
			}
			$normalized = $adapter->normalize_inbound( $payload );
			if ( ! is_array( $normalized ) ) {
				self::write_log( 'mabel_skipped_no_identity', 'Mabel event did not contain a valid identity or source event.', array( 'event_name' => $event_name ) );
				self::emit_listener( $payload, $event_name, 'skip', 'mabel_skipped_no_identity', 0, 0 );
				return;
			}
			if ( class_exists( 'BizCity_CRM_Channel_Contract' ) ) {
				$normalized = BizCity_CRM_Channel_Contract::normalize_inbound( 'mabel_wheel', $normalized );
				if ( is_wp_error( $normalized ) ) {
					self::write_log( 'mabel_contract_rejected', 'Mabel event was rejected by the CRM channel contract.', array( 'event_name' => $event_name, 'reason' => $normalized->get_error_code() ) );
					self::emit_listener( $payload, $event_name, 'fail', 'mabel_contract_rejected', 0, 0 );
					return;
				}
			}

			self::write_log( 'mabel_crm_sync_attempt', 'Mabel event entered CRM synchronization.', array( 'event_name' => $event_name, 'wheel_id' => (int) $normalized['wheel_id'], 'source_event_id' => (string) $normalized['source_event_id'] ) );
			if ( ! class_exists( 'BizCity_CRM_Repository' ) ) {
				self::write_log( 'mabel_crm_unavailable', 'CRM repository is unavailable; Mabel event was not mutated.', array( 'event_name' => $event_name ) );
				self::emit_listener( $normalized, $event_name, 'skip', 'crm_unavailable', 0, 0 );
				return;
			}

			$inbox_id = BizCity_CRM_Repository::upsert_inbox( 'mabel_wheel', (string) $normalized['inbox_ref'], array( 'name' => $normalized['inbox_name'] ) );
			if ( $inbox_id <= 0 ) {
				self::write_log( 'mabel_crm_sync_failed', 'Mabel inbox could not be resolved.', array( 'event_name' => $event_name, 'wheel_id' => (int) $normalized['wheel_id'] ) );
				self::emit_listener( $normalized, $event_name, 'fail', 'inbox_unavailable', 0, 0 );
				return;
			}
			$contact_result = BizCity_CRM_Repository::upsert_contact_by_identity( $inbox_id, (string) $normalized['source_id'], array(
				'name'                  => (string) ( $normalized['contact_name'] ?? '' ),
				'email'                 => (string) ( $normalized['contact_email'] ?? '' ),
				'phone'                 => (string) ( $normalized['contact_phone'] ?? '' ),
				'acquisition_source'    => 'mabel_wheel',
				'acquisition_meta'      => array( 'wheel_id' => (int) $normalized['wheel_id'], 'source_event_id' => (string) $normalized['source_event_id'] ),
				'additional_attributes' => (array) ( $normalized['additional_attributes'] ?? array() ),
			) );
			$contact_id       = (int) ( $contact_result['contact_id'] ?? 0 );
			$contact_inbox_id = (int) ( $contact_result['contact_inbox_id'] ?? 0 );
			if ( $contact_id <= 0 || $contact_inbox_id <= 0 ) {
				self::write_log( 'mabel_crm_sync_failed', 'Mabel contact could not be resolved.', array( 'event_name' => $event_name, 'wheel_id' => (int) $normalized['wheel_id'] ) );
				self::emit_listener( $normalized, $event_name, 'fail', 'contact_unavailable', 0, 0 );
				return;
			}

			$intake = array( 'duplicate' => false, 'message_id' => 0, 'conversation_id' => 0 );
			if ( $event_name === 'wof_optin' ) {
				$intake = BizCity_CRM_Repository::ingest_resolved_intake( $inbox_id, $contact_inbox_id, array(
					'content'             => (string) $normalized['content'],
					'content_type'        => 'text',
					'message_type'        => 'incoming',
					'sender_type'         => 'contact',
					'external_source_id'  => (string) $normalized['external_source_id'],
					'ai_metadata'         => array( 'channel' => 'mabel_wheel', 'event_type' => 'mabel_wheel_lead_capture' ),
				) );
			}
			$action = (string) ( $contact_result['action'] ?? ( ! empty( $intake['duplicate'] ) ? 'duplicate' : 'updated' ) );
			self::write_log( 'mabel_crm_sync_ok', 'Mabel event was synchronized into canonical CRM owners.', array( 'event_name' => $event_name, 'wheel_id' => (int) $normalized['wheel_id'], 'crm_action' => $action, 'contact_id' => $contact_id, 'message_id' => (int) ( $intake['message_id'] ?? 0 ) ) );
			self::emit_listener( $normalized, $event_name, 'ok', $action, $contact_id, (int) ( $intake['message_id'] ?? 0 ) );
		} catch ( \Throwable $e ) {
			self::write_log( 'mabel_crm_sync_exception', 'Mabel CRM bridge raised an exception.', array( 'event_name' => $event_name, 'exception_class' => get_class( $e ) ) );
		}
	}

	private static function write_log( string $event, string $message, array $context = array() ): void {
		if ( class_exists( 'BizCity_Channel_File_Logger' ) ) {
			BizCity_Channel_File_Logger::write( BizCity_Channel_File_Logger::CH_MABEL_WHEEL, BizCity_Channel_File_Logger::LEVEL_INFO, $event, $message, array_merge( $context, array( 'account_id' => 'mabel_wheel_' . (int) ( $context['wheel_id'] ?? 0 ) ) ) );
		}
	}

	private static function emit_listener( array $payload, string $event_name, string $status, string $action, int $contact_id, int $message_id ): void {
		if ( ! class_exists( 'BizCity_Listener_Bus' ) ) {
			return;
		}
		$wheel_id = absint( $payload['wheel_id'] ?? $payload['wheel'] ?? 0 );
		$source_id = sanitize_text_field( (string) ( $payload['source_id'] ?? '' ) );
		BizCity_Listener_Bus::emit( array(
			'kind'       => 'inbound',
			'platform'   => 'MABEL_WHEEL',
			'account_id' => 'mabel_wheel_' . $wheel_id,
			'user_id'    => $source_id,
			'chat_id'    => 'mabel_wheel_' . $wheel_id . '_' . sanitize_key( (string) ( $payload['source_event_id'] ?? '' ) ),
			'event_type' => $event_name === 'wof_optin' ? 'mabel_wheel_lead_capture' : 'mabel_wheel_play',
			'direction'  => 'in',
			'message'    => 'Mabel Wheel event ' . $action,
			'status'     => $status,
			'meta'       => array( 'wheel_id' => $wheel_id, 'crm_action' => $action, 'contact_id' => $contact_id, 'message_id' => $message_id ),
		) );
	}
}
