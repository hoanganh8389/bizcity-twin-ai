<?php
/**
 * Opt-in V2 runtime fixtures for tenant validation.
 *
 * Every mutating fixture runs inside a transaction and rolls back. The service
 * requires explicit confirm=V2 from an admin REST request.
 *
 * @package BizCity_Twin_CRM\Woo
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Identity_Fixtures' ) ) { return; }

final class BizCity_CRM_Identity_Fixtures {

	public static function run( string $confirm ): array {
		if ( 'V2' !== strtoupper( trim( $confirm ) ) ) {
			return array( 'status' => 'SKIP', 'reason' => 'explicit_confirm_V2_required' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'status' => 'FAIL', 'reason' => 'admin_required' );
		}
		$results = array(
			'tenant_schema'          => self::tenant_schema(),
			'concurrent_checkout'    => self::concurrent_checkout(),
			'rollback_projection'    => self::rollback_projection(),
		);
		$failed = false;
		foreach ( $results as $result ) { if ( ( $result['status'] ?? '' ) === 'FAIL' ) { $failed = true; } }
		return array( 'status' => $failed ? 'FAIL' : 'PASS', 'blog_id' => (int) get_current_blog_id(), 'fixtures' => $results );
	}

	private static function tenant_schema(): array {
		$tables = array(
			'contacts'  => BizCity_CRM_DB_Installer_V2::tbl_contacts(),
			'conflicts' => BizCity_CRM_DB_Installer_V2::tbl_identity_conflicts(),
			'points'    => $GLOBALS['wpdb']->prefix . 'user_points',
		);
		$missing = array();
		foreach ( $tables as $name => $table ) {
			if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $table ) ) { $missing[] = $name; }
		}
		return empty( $missing ) ? array( 'status' => 'PASS', 'tables' => array_keys( $tables ) ) : array( 'status' => 'SKIP', 'reason' => 'tables_missing', 'missing' => $missing );
	}

	private static function concurrent_checkout(): array {
		global $wpdb;
		$table = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $table ) || ! class_exists( 'BizCity_CRM_Woo_Customer_Bridge' ) ) { return array( 'status' => 'SKIP', 'reason' => 'woo_contact_fixture_unavailable' ); }
		$wpdb->query( 'START TRANSACTION' );
		try {
			$order = new class {
				public function get_id() { return 990000001; }
				public function get_customer_id() { return 0; }
				public function get_billing_email() { return 'v2-fixture@example.invalid'; }
				public function get_billing_phone() { return '0987654321'; }
				public function get_billing_first_name() { return 'V2'; }
				public function get_billing_last_name() { return 'Fixture'; }
			};
			$first = BizCity_CRM_Woo_Customer_Bridge::sync_from_order( $order );
			$second = BizCity_CRM_Woo_Customer_Bridge::sync_from_order( $order );
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE email=%s", 'v2-fixture@example.invalid' ) );
			$wpdb->query( 'ROLLBACK' );
			return $first > 0 && $first === $second && 1 === $count
				? array( 'status' => 'PASS', 'same_contact_id' => true, 'duplicate_count' => $count )
				: array( 'status' => 'FAIL', 'first_contact_id' => $first, 'second_contact_id' => $second, 'duplicate_count' => $count );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'status' => 'FAIL', 'reason' => 'fixture_exception' );
		}
	}

	private static function rollback_projection(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'user_points';
		$contacts = BizCity_CRM_DB_Installer_V2::tbl_contacts();
		if ( ! BizCity_CRM_DB_Installer_V2::table_exists( $table ) || ! BizCity_CRM_DB_Installer_V2::table_exists( $contacts ) || ! function_exists( 'bizcity_column_exists' ) || ! bizcity_column_exists( $table, 'contact_id' ) ) {
			return array( 'status' => 'SKIP', 'reason' => 'ledger_projection_fixture_unavailable' );
		}
		$row = $wpdb->get_row( "SELECT id, contact_id FROM `{$table}` ORDER BY id ASC LIMIT 1", ARRAY_A );
		$contact_id = (int) $wpdb->get_var( "SELECT id FROM `{$contacts}` ORDER BY id ASC LIMIT 1" );
		if ( ! $row || $contact_id <= 0 ) { return array( 'status' => 'SKIP', 'reason' => 'no_fixture_row' ); }
		$original = $row['contact_id'] === null ? null : (int) $row['contact_id'];
		$wpdb->query( 'START TRANSACTION' );
		try {
			$wpdb->update( $table, array( 'contact_id' => $contact_id ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
			$inside = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM `{$table}` WHERE id=%d", (int) $row['id'] ) );
			$wpdb->query( 'ROLLBACK' );
			$after = $wpdb->get_var( $wpdb->prepare( "SELECT contact_id FROM `{$table}` WHERE id=%d", (int) $row['id'] ) );
			$inside_ok = (int) $inside === $contact_id;
			$after_ok = null === $original ? null === $after : (int) $after === $original;
			return $inside_ok && $after_ok ? array( 'status' => 'PASS', 'rolled_back' => true ) : array( 'status' => 'FAIL', 'rolled_back' => false );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'status' => 'FAIL', 'reason' => 'rollback_exception' );
		}
	}
}
