<?php
/**
 * Negative fixture for the diagnostics harness allowlist.
 *
 * The filename looks like the legacy harness but has no cleanup call. It must
 * not be exempt solely because it matches the exact path pattern.
 */

class Fixture_Crm_Sprint_Diagnostic_Unproven {
	public static function run() {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$wpdb->insert( $table, array( 'status' => 'open' ) );
	}
}
