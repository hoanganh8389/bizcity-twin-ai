<?php
/**
 * Invalid fixture for the WP4 sender ownership gate.
 *
 * Two violations in one file, so both rules are exercised:
 *   1. a direct message-send POST to a provider endpoint from outside any
 *      approved transport owner;
 *   2. a canonical sender call with no `idempotency_key`, so a retry or a
 *      double-fired hook can produce a duplicate customer-visible message.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Sender_Bypass {
	public static function reply( string $chat_id, string $text, string $token ): void {
		// Violation 1: direct provider message send.
		wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendMessage', array(
			'body' => array( 'chat_id' => $chat_id, 'text' => $text ),
		) );

		// Violation 2: canonical sender without an idempotency key.
		BizCity_Gateway_Sender::instance()->send( $chat_id, $text, 'text' );
	}
}