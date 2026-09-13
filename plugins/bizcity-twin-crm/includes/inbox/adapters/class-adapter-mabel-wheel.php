<?php
/**
 * BizCity CRM - Mabel Wheel passive intake adapter.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE-0.55
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Adapter_Mabel_Wheel extends BizCity_CRM_Adapter_Base {

	public function code(): string {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - expose the passive intake channel code.
		return 'mabel_wheel';
	}

	public function label(): string {
		return 'Mabel Wheel';
	}

	public function capabilities(): array {
		return array( 'text' );
	}

	/**
	 * Normalize a Mabel hook payload into the CRM inbound envelope.
	 *
	 * @param array $raw Mabel `wof_optin` or `wof_play` payload.
	 * @return array|null
	 */
	public function normalize_inbound( array $raw ): ?array {
		// [2026-09-10 Johnny Chu - Chu Hoàng Anh] PHASE-0.55-MABEL-WHEEL - normalize only allowlisted scalar event data.
		$event_name = sanitize_key( (string) ( $raw['event_name'] ?? $raw['event_type'] ?? $raw['type'] ?? '' ) );
		$event_name = $event_name === 'optin' ? 'wof_optin' : $event_name;
		$event_name = $event_name === 'play' ? 'wof_play' : $event_name;
		if ( ! in_array( $event_name, array( 'wof_optin', 'wof_play' ), true ) ) {
			return null;
		}

		$wheel_id        = absint( $raw['wheel'] ?? $raw['wheel_id'] ?? 0 );
		$source_event_id = sanitize_text_field( (string) ( $raw['source_event_id'] ?? '' ) );
		if ( $wheel_id <= 0 || $source_event_id === '' ) {
			return null;
		}

		$email = sanitize_email( (string) ( $raw['email'] ?? $raw['mail'] ?? '' ) );
		if ( $email !== '' && ! is_email( $email ) ) {
			$email = '';
		}
		$phone = $this->normalize_phone( (string) ( $raw['phone'] ?? '' ) );
		$name  = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
		$fields = $this->normalize_fields( $raw['fields'] ?? array(), $email, $phone, $name );
		$email = $email !== '' ? $email : (string) ( $fields['email'] ?? '' );
		$phone = $phone !== '' ? $phone : (string) ( $fields['phone'] ?? '' );
		$name  = $name !== '' ? $name : (string) ( $fields['name'] ?? '' );
		unset( $fields['email'], $fields['phone'], $fields['name'] );

		if ( $email === '' && $phone === '' ) {
			return null;
		}

		$identity_value = $email !== '' ? 'email:' . strtolower( $email ) : 'phone:' . $phone;
		$source_id      = 'mwh_u_' . substr( hash_hmac( 'sha256', $identity_value, wp_salt( 'auth' ) ), 0, 40 );
		$inbox_ref      = 'mabel_wheel_' . $wheel_id;
		$event_type     = $event_name === 'wof_optin' ? 'mabel_wheel_lead_capture' : 'mabel_wheel_play';
		$summary        = array(
			'event'        => $event_name,
			'wheel_id'     => $wheel_id,
			'wheel_name'   => sanitize_text_field( (string) ( $raw['wheel_name'] ?? '' ) ),
			'fields'       => $fields,
			'winning'      => ! empty( $raw['winning'] ),
			'segment_id'   => sanitize_text_field( (string) ( $raw['segment_id'] ?? '' ) ),
			'segment_type' => absint( $raw['segment_type'] ?? 0 ),
			'segment_text' => sanitize_text_field( (string) ( $raw['segment_text'] ?? '' ) ),
			'prize'        => wp_strip_all_tags( (string) ( $raw['segment_prize'] ?? '' ) ),
		);
		$fields['mabel_wheel_engagement'] = array(
			'event'        => $event_name,
			'wheel_id'     => $wheel_id,
			'source_event_id' => $source_event_id,
			'winning'      => ! empty( $raw['winning'] ),
			'segment_id'   => sanitize_text_field( (string) ( $raw['segment_id'] ?? '' ) ),
			'segment_text' => sanitize_text_field( (string) ( $raw['segment_text'] ?? '' ) ),
			'prize_summary'=> wp_strip_all_tags( (string) ( $raw['segment_prize'] ?? '' ) ),
		);

		return array(
			'channel_code'        => 'mabel_wheel',
			'inbox_ref'           => $inbox_ref,
			'inbox_name'          => 'Mabel Wheel - ' . sanitize_text_field( (string) ( $raw['wheel_name'] ?? $wheel_id ) ),
			'source_id'           => $source_id,
			'contact_name'        => $name,
			'contact_email'       => $email,
			'contact_phone'       => $phone,
			'content'             => wp_json_encode( $summary ),
			'content_type'        => 'text',
			'attachments'         => array(),
			'external_source_id'  => 'mabel:' . $event_name . ':' . $source_event_id,
			'received_at'         => sanitize_text_field( (string) ( $raw['timestamp'] ?? current_time( 'mysql' ) ) ),
			'event_type'          => $event_type,
			'event_name'          => $event_name,
			'source_event_id'     => $source_event_id,
			'wheel_id'            => $wheel_id,
			'wheel_name'          => sanitize_text_field( (string) ( $raw['wheel_name'] ?? '' ) ),
			'additional_attributes' => $fields,
		);
	}

	public function send( array $conversation, array $message ): array {
		return array(
			'success'            => false,
			'outcome'            => 'failed',
			'code'               => 'passive_intake_only',
			'external_source_id' => null,
			'error'              => 'Mabel Wheel là kênh tiếp nhận một chiều.',
			'retryable'          => false,
			'channel_code'       => $this->code(),
			'contract_version'   => BizCity_CRM_Channel_Contract::VERSION,
		);
	}

	private function normalize_phone( string $phone ): string {
		$phone = preg_replace( '/[^0-9+\-() ]/', '', $phone );
		$phone = substr( trim( (string) $phone ), 0, 32 );
		if ( $phone !== '' && class_exists( 'BizCity_Phone_Normalizer' ) ) {
			$phone = BizCity_Phone_Normalizer::normalize_vn( $phone );
		}
		return (string) $phone;
	}

	private function normalize_fields( $raw_fields, string $email, string $phone, string $name ): array {
		$normalized = array();
		if ( ! is_array( $raw_fields ) ) {
			return $normalized;
		}
		foreach ( array_slice( $raw_fields, 0, 20 ) as $field ) {
			if ( is_object( $field ) ) {
				$field = (array) $field;
			}
			if ( ! is_array( $field ) ) {
				continue;
			}
			$field_id    = sanitize_key( (string) ( $field['id'] ?? $field['name'] ?? '' ) );
			$field_value = sanitize_text_field( (string) ( $field['value'] ?? '' ) );
			if ( $field_id === '' || $field_value === '' ) {
				continue;
			}
			$normalized[ $field_id ] = substr( $field_value, 0, 300 );
			$field_key = strtolower( $field_id );
			if ( $email === '' && ( strpos( $field_key, 'email' ) !== false || strpos( $field_key, 'mail' ) !== false ) ) {
				$normalized['email'] = sanitize_email( $field_value );
			}
			if ( $phone === '' && ( strpos( $field_key, 'phone' ) !== false || strpos( $field_key, 'tel' ) !== false || strpos( $field_key, 'mobile' ) !== false || strpos( $field_key, 'sdt' ) !== false ) ) {
				$normalized['phone'] = $this->normalize_phone( $field_value );
			}
			if ( $name === '' && ( strpos( $field_key, 'name' ) !== false || strpos( $field_key, 'ten' ) !== false ) ) {
				$normalized['name'] = $field_value;
			}
		}
		return $normalized;
	}
}
