<?php
/**
 * Broken fixture: message write with NO operational evidence at all.
 *
 * Must report `R-CH-FILE-LOG.db_write_without_file_evidence`. `error_log()` is
 * deliberately present and deliberately does NOT satisfy the rule: it writes to
 * the shared PHP diagnostic stream, which the channel file-log canon explicitly
 * refuses to depend on, and here it only runs when the INSERT already failed.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_No_Evidence_Writer {

	public static function boot(): void {
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'persist' ), 10, 1 );
	}

	public static function persist( array $payload ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'bizcity_channel_messages';

		$inserted = $wpdb->insert( $table, array(
			'platform'   => (string) $payload['platform'],
			'message_id' => (string) $payload['message_id'],
		) );

		if ( false === $inserted ) {
			error_log( '[fixture] insert failed: ' . $wpdb->last_error );
		}

		return false !== $inserted;
	}
}
