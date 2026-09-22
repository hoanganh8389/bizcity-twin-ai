<?php
/**
 * Clean fixture: evidence appended through a same-file wrapper method.
 *
 * Must PASS and must be counted `via=local_wrapper`.
 *
 * This is the dominant real-world shape, not a hypothetical: the only compliant
 * production path found by this gate is
 * `plugins/bizcity-twin-crm/includes/class-ai-replier.php::reply()`, which calls
 * `self::log()` at line 64 before its message write at line 651, and `log()`
 * ends in `BizCity_Channel_File_Logger::write()` at line 1133. A gate that only
 * accepted an inline canonical call would report that correct code as a
 * violation.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Wrapper_Writer {

	public static function boot(): void {
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'persist' ), 10, 1 );
	}

	public static function persist( array $payload ): bool {
		global $wpdb;

		self::log( 'inbound accepted for ' . (string) $payload['message_id'] );

		$table = $wpdb->prefix . 'bizcity_channel_messages';

		return false !== $wpdb->insert( $table, array(
			'platform'   => (string) $payload['platform'],
			'message_id' => (string) $payload['message_id'],
		) );
	}

	/** Same-file wrapper around the canonical append. */
	private static function log( string $message ): void {
		BizCity_Channel_File_Logger::write(
			BizCity_Channel_File_Logger::CH_ZALO_OA,
			BizCity_Channel_File_Logger::LEVEL_INFO,
			'message_received',
			$message,
			array()
		);
	}
}
