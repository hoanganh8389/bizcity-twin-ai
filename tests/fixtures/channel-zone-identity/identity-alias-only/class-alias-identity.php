<?php
/**
 * Alias-only fixture for the WP4 identity tuple rule.
 *
 * Contains NO canonical field name (`user_id`, `chat_id`, `message_id`), only
 * the accepted aliases `from_user_id`, `conversation_id` and `mid`. It exists so
 * the alias path is proven independently: if alias support regresses, this
 * fixture starts failing while the canonical fixture still passes.
 *
 * The real code this mirrors is
 * `plugins/bizcity-zalo-personal/includes/shared/class-zalo-inbound-emitter.php`,
 * which carries `from_user_id` + `conversation_id` + `message_id`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Identity_Alias_Only_Emitter {
	public static function emit( $payload ): void {
		do_action( 'bizcity_zalo_message_received', array(
			'platform'        => 'ZALO_PERSONAL',
			'account_id'      => 'fixture_personal_account',
			'from_user_id'    => 'fixture_sender',
			'conversation_id' => 'fixture_thread',
			'mid'             => 'fixture_msg_id',
		) );
	}
}