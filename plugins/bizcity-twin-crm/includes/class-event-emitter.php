<?php
/**
 * BizCity CRM — Event Emitter.
 *
 * Thin adapter over BizCity_Twin_Event_Bus::dispatch() — adds CRM context
 * (event_uuid v7, parent_event_uuid linking) and falls back to a do_action
 * mirror when the Twin Event Bus is not yet booted.
 *
 * Per R-CRM-1: every CRM state change goes through this emitter; bypassing
 * it (direct $wpdb->insert into CRM tables) is a rule violation.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Event_Emitter {

	/**
	 * Emit a CRM event.
	 *
	 * @param string  $event_type  e.g. 'crm_message_received'
	 * @param array   $payload     Event payload (must include conversation_id where applicable)
	 * @param ?string $parent_uuid Optional parent_event_uuid for causal chain
	 * @return string event_uuid (v7 time-ordered)
	 */
	public static function emit( string $event_type, array $payload, ?string $parent_uuid = null ): string {
		$uuid = self::uuid_v7();

		$enriched = array_merge( $payload, array(
			'event_uuid'        => $uuid,
			'parent_event_uuid' => $parent_uuid,
			'event_source'      => 'crm',
			'created_epoch_ms'  => (int) round( microtime( true ) * 1000 ),
		) );

		// Primary path: Twin Event Bus dispatch (if loaded).
		if ( class_exists( 'BizCity_Twin_Event_Bus', false )
			&& method_exists( 'BizCity_Twin_Event_Bus', 'dispatch' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $event_type, $enriched );
			} catch ( \Throwable $e ) {
				// Log via error_log — never throw into business path.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[bizcity-crm] event-bus dispatch failed: ' . $e->getMessage() );
				}
			}
		}

		// Mirror via WP action so CRM listeners (AI Responder M3, projector, etc.)
		// can subscribe without depending on Twin Event Bus.
		do_action( 'bizcity_crm_event', $event_type, $enriched );
		do_action( 'bizcity_crm_event_' . $event_type, $enriched );

		return $uuid;
	}

	/**
	 * Generate UUID v7 (time-ordered, RFC 9562).
	 *
	 * Format: ttttttttt-tttt-7xxx-yxxx-xxxxxxxxxxxx
	 * First 48 bits are millisecond Unix timestamp.
	 */
	public static function uuid_v7(): string {
		$ms     = (int) round( microtime( true ) * 1000 );
		$ts_hex = str_pad( dechex( $ms ), 12, '0', STR_PAD_LEFT );
		$rand   = bin2hex( random_bytes( 10 ) ); // 80 bits

		// Layout
		$time_low  = substr( $ts_hex, 0, 8 );
		$time_mid  = substr( $ts_hex, 8, 4 );
		// Set version (7) in high nibble of byte 7.
		$ver_hi    = '7' . substr( $rand, 0, 3 );
		// Set variant (10xx) in high nibble of byte 9.
		$var_hi    = dechex( ( hexdec( substr( $rand, 3, 1 ) ) & 0x3 ) | 0x8 ) . substr( $rand, 4, 3 );
		$tail      = substr( $rand, 7 ) . str_pad( bin2hex( random_bytes( 2 ) ), 4, '0' );
		$tail      = substr( $tail, 0, 12 );

		return sprintf( '%s-%s-%s-%s-%s', $time_low, $time_mid, $ver_hi, $var_hi, $tail );
	}
}
