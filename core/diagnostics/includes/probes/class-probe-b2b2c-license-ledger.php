<?php
/**
 * Read-only H3 probe for the Global B1 license ledger.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_License_Ledger', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_License_Ledger implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-02 09:45 PM Johnny Chu - Chu Hoàng Anh] B2C-H3 - identify the Global license ledger probe.
		return 'b2b2c.checkout.license_ledger';
	}

	public function label(): string {
		return 'B2B2C Global license ledger';
	}

	public function description(): string {
		return 'Checks the Hub-global append-only license journal, physical routing declaration and idempotency constraints without inserting data.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 24;
	}

	public function icon(): string {
		return 'book-lock';
	}

	public function estimate_ms(): int {
		return 180;
	}

	public function precondition() {
		// [2026-09-02 09:45 PM Johnny Chu - Chu Hoàng Anh] B2C-H3 - keep the Hub-global ledger probe out of B2 client runs.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: Global license ledger is owned by bizcity.vn.';
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		if ( $router_dir === '' || ! class_exists( 'BizCity_Safe_Loader', false ) ) {
			return new WP_Error( 'license_ledger_loader_missing', 'License ledger Router loader context is not available.' );
		}
		$files = array(
			$router_dir . '/includes/class-router-schema.php'                 => 'diagnostics.b2b2c_license_ledger.schema',
			$router_dir . '/includes/license/class-router-license-ledger.php'  => 'diagnostics.b2b2c_license_ledger.repository',
		);
		foreach ( $files as $file => $label ) {
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				return new WP_Error( 'license_ledger_file_missing', 'A Global license ledger owner artifact is missing.' );
			}
			if ( strpos( $label, '.schema' ) !== false && ! class_exists( 'BizCity_Router_Schema', false ) ) {
				BizCity_Safe_Loader::require_file( $file, $label );
			}
			if ( strpos( $label, '.repository' ) !== false && ! class_exists( 'BizCity_Router_License_Ledger', false ) ) {
				BizCity_Safe_Loader::require_file( $file, $label );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 09:45 PM Johnny Chu - Chu Hoàng Anh] B2C-H3 - verify Global physical table and constraints without mutation.
		$failures = array();
		$router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$router_active      = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
		$ctx->emit_step( array(
			'label'  => 'Router plugin activation',
			'status' => $router_active ? 'pass' : 'fail',
			'detail' => $router_active ? 'Router is active in the current B1 lifecycle.' : 'Router is inactive in the current B1 lifecycle.',
		) );
		if ( ! $router_active ) {
			return array( 'status' => 'fail', 'summary' => 'Router is inactive; Global license ledger cannot claim runtime ownership.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate the deployed Router on B1, run its schema upgrade once, then rerun this probe.' );
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$schema_file = $router_dir . '/includes/class-router-schema.php';
		$ledger_file = $router_dir . '/includes/license/class-router-license-ledger.php';
		$schema_source = is_readable( $schema_file ) ? (string) file_get_contents( $schema_file ) : '';
		$ledger_source = is_readable( $ledger_file ) ? (string) file_get_contents( $ledger_file ) : '';
		$db_file = defined( 'WP_CONTENT_DIR' ) ? rtrim( (string) WP_CONTENT_DIR, '/\\' ) . '/db.php' : '';
		$db_source = is_readable( $db_file ) ? (string) file_get_contents( $db_file ) : '';

		$disk_ok = $schema_source !== ''
			&& $ledger_source !== ''
			&& $db_source !== ''
			&& strpos( $schema_source, 'bizcity_llm_license_ledger' ) !== false
			&& strpos( $schema_source, 'install_license_ledger' ) !== false
			&& strpos( $ledger_source, 'INSERT IGNORE INTO' ) !== false
			&& strpos( $ledger_source, 'append_paid_order' ) !== false
			&& strpos( $db_source, "'bizcity_llm_license_ledger'" ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Disk - Global ledger owner and routing markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Router schema, append-only repository and db.php Global table declaration are readable.' : 'Global ledger owner or routing markers are incomplete.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'ledger_disk_markers_missing';
		}

		$loader_ok = class_exists( 'BizCity_Router_Schema' )
			&& class_exists( 'BizCity_Router_License_Ledger' )
			&& method_exists( 'BizCity_Router_Schema', 'install_license_ledger' )
			&& method_exists( 'BizCity_Router_License_Ledger', 'append_paid_order' )
			&& method_exists( 'BizCity_Router_License_Ledger', 'get_by_idempotency' )
			&& 'bizcity_llm_license_ledger' === BizCity_Router_License_Ledger::TABLE_SUFFIX;
		$ctx->emit_step( array(
			'label'  => 'Loader - Global ledger owner',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Router schema installer and append-only repository are loaded.' : 'Global ledger owner methods are not fully loaded.',
		) );
		if ( ! $loader_ok ) {
			$failures[] = 'ledger_loader_missing';
		}

		global $wpdb;
		$table = BizCity_Router_License_Ledger::table_name();
		$exists = false;
		$unique_ok = false;
		$route_ok = true;
		if ( $loader_ok && is_object( $wpdb ) ) {
			$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", $table ) );
			$index_rows = $wpdb->get_results( $wpdb->prepare( "SELECT INDEX_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME IN ('uq_order_item_event', 'uq_idempotency') GROUP BY INDEX_NAME, NON_UNIQUE", $table ), ARRAY_A );
			$indexes = array();
			foreach ( (array) $index_rows as $index_row ) {
				$indexes[ (string) ( $index_row['INDEX_NAME'] ?? '' ) ] = (int) ( $index_row['NON_UNIQUE'] ?? 1 );
			}
			$unique_ok = isset( $indexes['uq_order_item_event'], $indexes['uq_idempotency'] ) && 0 === $indexes['uq_order_item_event'] && 0 === $indexes['uq_idempotency'];
			if ( is_object( $wpdb ) && property_exists( $wpdb, 'global_tables' ) ) {
				$route_ok = in_array( 'bizcity_llm_license_ledger', (array) $wpdb->global_tables, true );
			}
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime - Global table and idempotency constraints',
			'status' => $exists && $unique_ok && $route_ok ? 'pass' : 'fail',
			'detail' => $exists && $unique_ok && $route_ok ? 'Physical Global ledger exists and both required unique constraints are present.' : 'Global ledger table, unique constraints or db.php routing declaration is missing.',
		) );
		if ( ! $exists ) {
			$failures[] = 'ledger_table_missing';
		}
		if ( ! $unique_ok ) {
			$failures[] = 'ledger_unique_constraints_missing';
		}
		if ( ! $route_ok ) {
			$failures[] = 'ledger_global_route_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Global license ledger contract failed: ' . implode( ', ', array_unique( $failures ) ),
				'error'   => implode( '; ', array_unique( $failures ) ),
				'fix_hint' => 'Deploy the Router schema/repository and db.php together, activate B1, run the schema upgrade once, then rerun the focused ledger probe.',
			);
		}

		return array( 'status' => 'pass', 'summary' => 'Global license ledger owner, physical table, Global routing declaration and idempotency constraints passed read-only checks.' );
	}

	public function cleanup(): void {
		// Read-only probe: no persistent artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_License_Ledger';
	return $list;
} );