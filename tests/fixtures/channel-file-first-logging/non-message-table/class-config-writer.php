<?php
/**
 * Clean fixture: configuration CRUD in a channel file, no evidence required.
 *
 * Must PASS with ZERO findings and `db_writing_functions = 0`.
 *
 * This fixture proves the scope narrowing is real rather than a rule that was
 * quietly switched off. R-CH-FILE-LOG is about the record of a customer
 * message; saving a bot's settings row is not that, and demanding channel
 * operational evidence here would be a false positive.
 *
 * The narrowing was forced by ground truth: a file-scope version of this gate
 * reported `137` findings including `delete_bot` (`bizcity_facebook_bots`),
 * `create_project` (`bizcity_webchat_projects`) and 50 CRM REST methods such as
 * `post_crm_product_category`. Scoping to the message-bearing write target cut
 * that to `20` real findings. If someone later widens the table pattern so that
 * config tables match again, THIS fixture fails and says so.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Config_Writer {

	public static function boot(): void {
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'noop' ), 10, 1 );
	}

	public static function noop( array $payload ): void {}

	/** Not a message record: a bot configuration row. No evidence required. */
	public static function save_bot( array $data ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'bizcity_facebook_bots';

		return false !== $wpdb->update( $table, $data, array( 'id' => (int) $data['id'] ) );
	}

	/** Also not a message record: a webchat project row. */
	public static function delete_project( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'bizcity_webchat_projects';

		return false !== $wpdb->delete( $table, array( 'id' => $id ) );
	}
}
