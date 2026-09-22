<?php
/**
 * Valid fixture for the WP4 normalized-envelope contract identity rule.
 *
 * Carries `contract` + `version` alongside the full identity tuple, mirroring
 * the real producers:
 *   - `core/channel-gateway/includes/class-universal-channel-listener.php`
 *     (`ENVELOPE_CONTRACT` / `ENVELOPE_VERSION` constants)
 *   - `plugins/bizcity-zalo-bot/includes/class-channel-adapter.php`
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Envelope_With_Contract {
	public static function emit( $payload ): void {
		$envelope = array(
			'contract'   => 'channel-payload',
			'version'    => '1.1.0',
			'platform'   => 'ZALO_OA',
			'account_id' => 'fixture_account',
			'user_id'    => 'fixture_user',
			'chat_id'    => 'fixture_thread',
			'message_id' => 'fixture_msg',
			'message'    => (string) ( $payload['text'] ?? '' ),
		);
		do_action( 'bizcity_channel_normalized', $envelope, 'fixture' );
	}
}