<?php
/**
 * Transport-owner fixture for the WP4 sender ownership gate.
 *
 * Lives under an approved transport-owner path inside the fixture root
 * (`core/channel-gateway/includes/adapters/`), so its direct provider message
 * POST must be EXEMPT — that directory IS the transport layer the canonical
 * sender delegates to. The runner asserts the exemption applied by checking the
 * post was counted as in-owner rather than skipped.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Transport_Owner {
	public static function deliver( string $chat_id, string $text, string $token ): array {
		return wp_remote_post( 'https://api.telegram.org/bot' . $token . '/sendMessage', array(
			'body' => array( 'chat_id' => $chat_id, 'text' => $text ),
		) );
	}
}