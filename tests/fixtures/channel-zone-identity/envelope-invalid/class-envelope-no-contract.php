<?php
/**
 * Invalid fixture for the WP4 normalized-envelope contract identity rule.
 *
 * Emits `bizcity_channel_normalized` without `contract` / `version`, so every
 * consumer must guess the payload shape. Must be reported as
 * `R-CH-UNI.envelope_missing_contract_identity` with `missing=contract+version`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Envelope_No_Contract {
	public static function emit( $payload ): void {
		$envelope = array(
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