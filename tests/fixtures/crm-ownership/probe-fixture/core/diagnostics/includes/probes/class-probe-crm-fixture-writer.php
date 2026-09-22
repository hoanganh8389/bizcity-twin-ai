<?php
/**
 * Probe-exemption fixture for the WP5 CRM ownership gate.
 *
 * The path of this file is what matters: it lives under a
 * `core/diagnostics/includes/probes/` directory inside the fixture root, which
 * is the exemption prefix the validator recognises. Diagnostics probes create
 * and clean up their own fixture rows by design, so a CRM write here must NOT be
 * reported — but it must still be counted as a probe writer, which is how the
 * runner proves the exemption actually applied instead of the file being
 * skipped for an unrelated reason.
 */

defined( 'ABSPATH' ) || exit;

final class Fixture_Crm_Probe_Writer {
	public static function cleanup( int $contact_id ): void {
		global $wpdb;
		$wpdb->delete( BizCity_CRM_DB_Installer_V2::tbl_contacts(), array( 'id' => $contact_id ), array( '%d' ) );
	}
}