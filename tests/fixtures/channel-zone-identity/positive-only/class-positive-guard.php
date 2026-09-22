<?php
/**
 * Positive-guard-only fixture for the WP4 channel zone/identity gate.
 *
 * This file contains NO zone-1 marker, so the only way it can pass is through
 * the POSITIVE guard path (`platform !== 'ZALO_BOT'` → return). It exists so
 * both guard directions are independently proven: if the positive path
 * regresses, this fixture starts failing.
 *
 * The real code this mirrors is
 * `plugins/bizcity-zalo-bot/includes/class-channel-adapter.php` line 38.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Zone_Positive_Only_Guard {
	public static function init(): void {
		add_action( 'bizcity_zalo_message_received', array( __CLASS__, 'emit_normalized' ), 1, 1 );
	}

	public static function emit_normalized( $message_data ): void {
		if ( ! is_array( $message_data ) || (string) ( $message_data['platform'] ?? '' ) !== 'ZALO_BOT' ) {
			return;
		}
		update_option( 'fixture_last_normalized', (string) ( $message_data['message_id'] ?? '' ) );
	}
}