<?php
/**
 * Read-only H7 probe for Master Admin license order management.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Master_Admin', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Master_Admin implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-04 01:30 PM Johnny Chu - Chu Hoàng Anh] B2C-H7 - identify Master Admin order/customer management contract.
		return 'b2b2c.checkout.master_admin';
	}

	public function label(): string {
		return 'B2B2C Master Admin license orders';
	}

	public function description(): string {
		return 'Checks the existing Master Admin owner, bounded ledger order reads, capability boundary and safe key/order projection without mutation.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 28;
	}

	public function icon(): string {
		return 'shield-check';
	}

	public function estimate_ms(): int {
		return 220;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: Master Admin license order management is owned by bizcity.vn.';
		}
		if ( ! class_exists( 'BizCity_Router_Master_Admin' ) || ! class_exists( 'BizCity_Router_License_Ledger' ) ) {
			return new WP_Error( 'master_admin_loader_missing', 'Master Admin or license ledger is not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( BizCity_Router_License_Ledger::table_name() ) ) {
			return 'ledger_runtime_missing: Master Admin order view requires the Global license ledger table.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-04 01:30 PM Johnny Chu - Chu Hoàng Anh] B2C-H7 - verify read-only admin order management and bounded safe projection.
		$failures = array();
		$router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$router_active      = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
		$ctx->emit_step( array( 'label' => 'Router plugin activation', 'status' => $router_active ? 'pass' : 'fail', 'detail' => $router_active ? 'Router is active in the B1 lifecycle.' : 'Router is inactive in the B1 lifecycle.' ) );
		if ( ! $router_active ) {
			return array( 'status' => 'fail', 'summary' => 'Router is inactive; Master Admin cannot claim runtime ownership.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate the deployed Router on B1, then rerun this focused probe.' );
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$admin_file = $router_dir . '/includes/class-router-master-admin.php';
		$ledger_file = $router_dir . '/includes/license/class-router-license-ledger.php';
		$admin_source = is_readable( $admin_file ) ? (string) file_get_contents( $admin_file ) : '';
		$ledger_source = is_readable( $ledger_file ) ? (string) file_get_contents( $ledger_file ) : '';
		$disk_ok = $admin_source !== '' && $ledger_source !== '' && strpos( $admin_source, '&tab=orders' ) !== false && strpos( $admin_source, '&tab=customers' ) !== false && strpos( $admin_source, 'render_license_orders_tab' ) !== false && strpos( $admin_source, 'render_customers_tab' ) !== false && strpos( $admin_source, 'license_history_locked' ) !== false && strpos( $ledger_source, 'get_admin_purchase_history' ) !== false && strpos( $ledger_source, 'count_admin_purchase_history' ) !== false && strpos( $ledger_source, 'get_admin_customer_summary' ) !== false && strpos( $ledger_source, 'has_events_for_key' ) !== false;
		$ctx->emit_step( array( 'label' => 'Disk - Master Admin order owner', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Existing Master Admin page and Global ledger admin readers contain the H7 owner markers.' : 'Master Admin or Global ledger H7 markers are missing.' ) );
		if ( ! $disk_ok ) {
			$failures[] = 'master_admin_disk_markers_missing';
		}

		$loader_ok = method_exists( 'BizCity_Router_Master_Admin', 'render_license_orders_tab' ) && method_exists( 'BizCity_Router_License_Ledger', 'get_admin_purchase_history' ) && method_exists( 'BizCity_Router_License_Ledger', 'count_admin_purchase_history' ) && method_exists( 'BizCity_Router_License_Ledger', 'get_admin_customer_summary' ) && method_exists( 'BizCity_Router_License_Ledger', 'count_admin_customer_summary' ) && method_exists( 'BizCity_Router_License_Ledger', 'has_events_for_key' );
		$ctx->emit_step( array( 'label' => 'Loader - Master Admin order methods', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'Master Admin order renderer and bounded ledger readers are loaded.' : 'One or more H7 owner methods are unavailable.' ) );
		if ( ! $loader_ok ) {
			$failures[] = 'master_admin_loader_missing';
		}

		$capability_ok = current_user_can( 'manage_options' );
		$ctx->emit_step( array( 'label' => 'Runtime - admin capability boundary', 'status' => $capability_ok ? 'pass' : 'fail', 'detail' => $capability_ok ? 'Current diagnostics user has the required Master Admin capability.' : 'Current diagnostics user cannot exercise the manage_options boundary.' ) );
		if ( ! $capability_ok ) {
			$failures[] = 'admin_capability_missing';
		}
		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Master Admin Disk/Loader/capability contract failed: ' . implode( ', ', array_unique( $failures ) ), 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Keep the existing manage_options Master Admin owner and load the bounded Global ledger order readers before rerunning.' );
		}

		$rows = BizCity_Router_License_Ledger::get_admin_purchase_history( array( 'page' => 1, 'per_page' => 500 ) );
		$total = BizCity_Router_License_Ledger::count_admin_purchase_history( array( 'page' => 1, 'per_page' => 500 ) );
		$bounded = is_array( $rows ) && 50 >= count( $rows ) && $total >= count( $rows );
		$ctx->emit_step( array( 'label' => 'Runtime - bounded order query', 'status' => $bounded ? 'pass' : 'fail', 'detail' => $bounded ? 'Admin order reader capped the requested page at 50 rows and returned a matching non-negative count.' : 'Admin order reader exceeded the page cap or returned an inconsistent count.' ) );
		if ( ! $bounded ) {
			$failures[] = 'admin_order_bounds_failed';
		}

		$safe = true;
		foreach ( $rows as $row ) {
			foreach ( array( 'key_hash', 'api_key', 'bearer', 'secret' ) as $forbidden ) {
				if ( array_key_exists( $forbidden, (array) $row ) ) {
					$safe = false;
				}
			}
		}
		$ctx->emit_step( array( 'label' => 'Runtime - safe admin projection', 'status' => $safe ? 'pass' : 'fail', 'detail' => $safe ? 'Admin order rows expose masked key/order metadata only; no raw credential or hash field is returned.' : 'Admin order projection contains a forbidden credential/hash field.' ) );
		if ( ! $safe ) {
			$failures[] = 'unsafe_admin_order_projection';
		}

		$customers = BizCity_Router_License_Ledger::get_admin_customer_summary( array( 'page' => 1, 'per_page' => 500 ) );
		$customer_total = BizCity_Router_License_Ledger::count_admin_customer_summary( array( 'page' => 1, 'per_page' => 500 ) );
		$customer_bounds = is_array( $customers ) && 50 >= count( $customers ) && $customer_total >= count( $customers );
		$ctx->emit_step( array( 'label' => 'Runtime - derived customer bounds', 'status' => $customer_bounds ? 'pass' : 'fail', 'detail' => $customer_bounds ? 'Derived WordPress customer summary is bounded at 50 rows and reports a consistent owner count.' : 'Derived customer summary exceeded the page cap or returned an inconsistent owner count.' ) );
		if ( ! $customer_bounds ) {
			$failures[] = 'customer_summary_bounds_failed';
		}
		$customer_safe = true;
		foreach ( $customers as $customer ) {
			foreach ( array( 'key_hash', 'api_key', 'bearer', 'secret' ) as $forbidden ) {
				if ( array_key_exists( $forbidden, (array) $customer ) ) {
					$customer_safe = false;
				}
			}
		}
		$ctx->emit_step( array( 'label' => 'Runtime - derived customer redaction', 'status' => $customer_safe ? 'pass' : 'fail', 'detail' => $customer_safe ? 'Customer summary contains bounded identity/order aggregates without credential fields.' : 'Customer summary exposed a forbidden credential/hash field.' ) );
		if ( ! $customer_safe ) {
			$failures[] = 'unsafe_customer_projection';
		}

		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Master Admin runtime failed: ' . implode( ', ', array_unique( $failures ) ), 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Keep admin queries read-only, capped at 50 rows and free of full key/hash fields; rerun the focused H7 probe.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Master Admin passed activation, Disk/Loader, manage_options capability, bounded Global ledger reads and safe order projection without mutation.' );
	}

	public function cleanup(): void {
		// Read-only probe: no persistent artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Master_Admin';
	return $list;
} );
