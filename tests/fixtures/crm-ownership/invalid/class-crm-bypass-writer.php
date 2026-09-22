<?php
/**
 * Invalid fixture for the WP5 CRM ownership gate.
 *
 * Writes a CRM business table directly from outside the CRM owner and without
 * referencing the CRM channel contract. Must be reported as
 * `R-CRM.direct-sql-outside-owner` and `R-CRM.write_without_channel_contract`.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Crm_Bypass_Writer {
	public static function save( int $contact_id, string $name ): void {
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer::tbl_contacts();
		$wpdb->update( $tbl, array( 'name' => $name ), array( 'id' => $contact_id ) );
	}
}