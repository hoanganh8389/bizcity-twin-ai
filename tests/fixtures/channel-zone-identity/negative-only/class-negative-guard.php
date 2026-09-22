<?php
/**
 * Negative-guard-only fixture for the WP4 channel zone/identity gate.
 *
 * Contains only zone-1 markers, so it can pass only through the NEGATIVE guard
 * path. Paired with the positive-only fixture so each direction is proven
 * independently rather than one masking the other at file scope.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Zone_Negative_Only_Guard {
	public static function init(): void {
		add_action( 'bizcity_zalo_message_received', array( __CLASS__, 'on_message' ), 5, 1 );
	}

	public static function on_message( $message_data ): void {
		$code     = (string) ( $message_data['code'] ?? '' );
		$platform = (string) ( $message_data['platform'] ?? '' );
		if ( $code === 'zalo_oa' || $code === 'zalo_personal'
			|| $platform === 'ZALO_OA' || $platform === 'ZALO_PERSONAL' ) {
			return;
		}
		update_option( 'fixture_last_admin_command', (string) ( $message_data['message_text'] ?? '' ) );
	}
}