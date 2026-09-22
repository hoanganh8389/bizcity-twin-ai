<?php
/**
 * Valid fixture for the exact diagnostics harness allowlist.
 *
 * The harness creates disposable conversation rows and removes them before
 * returning. It must not be classified as a repository bypass.
 */

class Fixture_Crm_Sprint_Diagnostic {
	public static function run() {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_conversations();
		$wpdb->insert( $table, array( 'status' => 'open' ) );
		$id = (int) $wpdb->insert_id;
		$wpdb->delete( $table, array( 'id' => $id ) );
	}
}
