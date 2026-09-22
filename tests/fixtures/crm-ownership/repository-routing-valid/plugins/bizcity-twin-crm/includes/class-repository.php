<?php
/**
 * Valid fixture for R-CRM.mutation_outside_repository.
 *
 * Customer-facing contact mutation is inside the declared repository owner.
 */

class Fixture_Crm_Repository {
	public static function update_contact( $id, array $fields ) {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		$wpdb->update( $table, $fields, array( 'id' => (int) $id ) );
	}
}
