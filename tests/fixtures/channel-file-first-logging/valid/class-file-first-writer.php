<?php
/**
 * Clean fixture: canonical operational append BEFORE the message write.
 *
 * Enters gate scope through the channel hook reference, then writes a
 * message-bearing table (`bizcity_channel_messages`) only after
 * `BizCity_Channel_File_Logger::write_record()` has appended evidence. This is
 * the shape R-CH-FILE-LOG requires: if the INSERT fails, the JSONL row proving
 * the message arrived still exists.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_File_First_Writer {

	public static function boot(): void {
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'persist' ), 10, 1 );
	}

	public static function persist( array $payload ): bool {
		global $wpdb;

		// File-first: operational evidence is durable before any DB call.
		BizCity_Channel_File_Logger::write_record( array(
			'channel'    => 'zalo_oa',
			'event'      => 'message_received',
			'stage'      => 'persist',
			'direction'  => 'inbound',
			'account'    => array( 'account_id' => (string) $payload['account_id'], 'scope' => 'exact' ),
			'message'    => 'inbound message accepted',
		) );

		$table = $wpdb->prefix . 'bizcity_channel_messages';

		return false !== $wpdb->insert( $table, array(
			'platform'   => (string) $payload['platform'],
			'account_id' => (string) $payload['account_id'],
			'chat_id'    => (string) $payload['chat_id'],
			'message_id' => (string) $payload['message_id'],
		) );
	}
}
