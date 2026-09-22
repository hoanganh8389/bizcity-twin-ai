<?php
/**
 * Negative fixture for the WP4 channel zone/identity gate.
 *
 * This handler subscribes to a Zone 2 hook and does admin work without testing
 * any discriminator, so a Zone 1 payload (zalo_oa / zalo_personal) would be
 * processed as an admin command. It must be reported as
 * `R-ZONE.zone2_consumer_without_discriminator`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Zone_Unguarded_Consumer {
	public static function init(): void {
		add_action( 'bizcity_zalo_message_received', array( __CLASS__, 'on_message' ), 5, 1 );
	}

	public static function on_message( $message_data ): void {
		// No discriminator: processes every payload as an admin command.
		$text = (string) ( $message_data['message_text'] ?? '' );
		update_option( 'fixture_last_admin_command', $text );
	}
}