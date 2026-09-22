<?php
/**
 * Broken fixture: the append exists but runs AFTER the message write.
 *
 * Must report `R-CH-FILE-LOG.db_write_before_file_evidence`.
 *
 * This fixture is the only proof this rule works: the production scan reports
 * `0` ordering violations, so without it the rule would be indistinguishable
 * from a rule that never runs. WP3 recorded exactly that failure mode — two
 * rules that existed in a validator but appeared in neither fixtures nor
 * production, and were therefore unproven.
 *
 * The append here is real and canonical; only its POSITION is wrong. Evidence
 * written after the INSERT is conditional on the INSERT succeeding, which is
 * the ordering R-CH-FILE-LOG exists to forbid.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Log_After_Write {

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

		// Too late: this row only exists when the write above already succeeded.
		BizCity_Channel_File_Logger::write_record( array(
			'channel' => 'zalo_oa',
			'event'   => 'message_persisted',
			'stage'   => 'persist',
			'account' => array( 'account_id' => (string) $payload['account_id'], 'scope' => 'exact' ),
		) );

		return false !== $inserted;
	}
}
