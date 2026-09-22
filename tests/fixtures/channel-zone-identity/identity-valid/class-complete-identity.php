<?php
/**
 * Canonical-field fixture for the WP4 identity tuple rule.
 *
 * Carries all five canonical field names. The alias path is proven separately by
 * `tests/fixtures/channel-zone-identity/identity-alias-only/`, because this rule
 * counts per file: keeping both shapes in one file would let the canonical
 * emitter mask the alias emitter and neither would be independently proven.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Identity_Canonical_Emitter {
	public static function emit( $payload ): void {
		do_action( 'bizcity_channel_message_received', array(
			'platform'   => 'ZALO_OA',
			'account_id' => 'fixture_oa',
			'user_id'    => 'fixture_user',
			'chat_id'    => 'fixture_thread',
			'message_id' => 'fixture_msg',
		) );
	}
}