<?php
/**
 * Negative fixture for the diagnostics-fixture exemption (WP5 CRM ownership).
 *
 * This file sits in the exempted directory `core/diagnostics/includes/fixtures/`
 * but has NO teardown entry point and NO marker scoping. It must still fail
 * `R-CRM.direct-sql-outside-owner`, because otherwise simply moving an unowned
 * writer into a directory named `fixtures/` would be a back door around the CRM
 * owner rule.
 *
 * The matching positive fixture
 * (`tests/fixtures/crm-ownership/fixture-factory-proven/`) proves the exemption
 * still matches a real factory with `destroy()` + marker-scoped deletes. Both
 * directions are asserted in CI.
 */

class BizCity_CRM_Unproven_Fixture_Writer {

	/**
	 * The write target is passed on the SAME line as the `$wpdb` call on purpose.
	 *
	 * The gate resolves a write target by tracking a variable within the line,
	 * so a multi-line `$wpdb->insert(\n $table,\n ...)` is not resolved (the gate
	 * deliberately under-reports rather than guessing). A negative fixture that
	 * is not detected proves nothing, so this one must use the single-line shape.
	 */
	public static function seed() {
		global $wpdb;

		$table = BizCity_CRM_DB_Installer_V2::tbl_messages();

		// No teardown, no marker scope: this is an unowned writer wearing a
		// fixture directory name.
		$wpdb->insert( $table, array( 'conversation_id' => 1, 'content' => 'seed' ) );
	}
}
