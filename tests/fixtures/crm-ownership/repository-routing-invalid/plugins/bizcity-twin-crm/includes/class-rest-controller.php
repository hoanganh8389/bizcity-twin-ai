<?php
/**
 * Invalid fixture for R-CRM.mutation_outside_repository.
 *
 * This path is inside the CRM plugin owner, but it is a controller rather than
 * a repository. A customer-facing contact mutation here must still fail.
 */

class Fixture_Crm_Rest_Controller_Bypass {
	public static function update_contact( $id, array $fields ) {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$wpdb->update( $table, $fields, array( 'id' => (int) $id ) );
	}
}
