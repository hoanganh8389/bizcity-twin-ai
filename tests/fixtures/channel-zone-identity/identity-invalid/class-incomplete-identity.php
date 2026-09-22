<?php
/**
 * Invalid fixture for the WP4 identity tuple rule.
 *
 * Emits a raw inbound hook with only partial identity: no chat_id and no
 * message_id, so downstream owners cannot correlate the exact thread or dedupe
 * the message. Must be reported as `R-CH-IDMEM.identity_tuple_incomplete`
 * with `missing=chat_id+message_id`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Identity_Incomplete_Emitter {
	public static function emit( $payload ): void {
		do_action( 'bizcity_channel_message_received', array(
			'platform'  => 'WEBCHAT',
			'account_id' => 'fixture_account',
			'user_id'   => 'fixture_user',
			'text'      => (string) ( $payload['text'] ?? '' ),
		) );
	}
}