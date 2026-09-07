<?php
/**
 * Read-only H8 probe for the bounded commerce dashboard.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Commerce_Dashboard', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Commerce_Dashboard implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-05 10:45 AM Johnny Chu - Chu Hoàng Anh] B2C-H8 - identify bounded commerce dashboard contract.
		return 'b2b2c.checkout.commerce_dashboard';
	}

	public function label(): string {
		return 'B2B2C commerce dashboard';
	}

	public function description(): string {
		return 'Checks date-filtered ledger sales, refund, plan and renewal aggregates without Woo mutation or credential exposure.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 29;
	}

	public function icon(): string {
		return 'chart-no-axes-combined';
	}

	public function estimate_ms(): int {
		return 220;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: commerce dashboard is owned by bizcity.vn.';
		}
		if ( ! class_exists( 'BizCity_Router_Master_Admin' ) || ! class_exists( 'BizCity_Router_License_Ledger' ) ) {
			return new WP_Error( 'commerce_dashboard_loader_missing', 'Master Admin or license ledger is not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( BizCity_Router_License_Ledger::table_name() ) ) {
			return 'ledger_runtime_missing: commerce dashboard requires the Global license ledger table.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-05 10:45 AM Johnny Chu - Chu Hoàng Anh] B2C-H8 - verify bounded aggregate loader, shape and redaction.
		$failures = array();
		$router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$router_active      = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
		$ctx->emit_step( array( 'label' => 'Router plugin activation', 'status' => $router_active ? 'pass' : 'fail', 'detail' => $router_active ? 'Router is active in the B1 lifecycle.' : 'Router is inactive in the B1 lifecycle.' ) );
		if ( ! $router_active ) {
			return array( 'status' => 'fail', 'summary' => 'Router is inactive; commerce dashboard cannot claim runtime ownership.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate the deployed Router on B1, then rerun this focused probe.' );
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$admin_file = $router_dir . '/includes/class-router-master-admin.php';
		$ledger_file = $router_dir . '/includes/license/class-router-license-ledger.php';
		$admin_source = is_readable( $admin_file ) ? (string) file_get_contents( $admin_file ) : '';
		$ledger_source = is_readable( $ledger_file ) ? (string) file_get_contents( $ledger_file ) : '';
		$commerce_fixture_file = defined( 'BIZCITY_DIAGNOSTICS_DIR' ) ? rtrim( (string) BIZCITY_DIAGNOSTICS_DIR, '/\\' ) . '/includes/probes/class-probe-b2b2c-commerce-exact-key.php' : '';
		$commerce_fixture_source = is_readable( $commerce_fixture_file ) ? (string) file_get_contents( $commerce_fixture_file ) : '';
		$disk_ok = $admin_source !== '' && $ledger_source !== '' && $commerce_fixture_source !== '' && strpos( $admin_source, '&tab=commerce' ) !== false && strpos( $admin_source, 'render_commerce_tab' ) !== false && strpos( $ledger_source, 'get_admin_commerce_dashboard' ) !== false && strpos( $commerce_fixture_source, 'Woo paid to ledger reconciliation' ) !== false && strpos( $commerce_fixture_source, 'Woo refund to reversal reconciliation' ) !== false;
		$ctx->emit_step( array( 'label' => 'Disk - commerce dashboard owner', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Master Admin Commerce tab and Global ledger aggregate markers are readable.' : 'Commerce dashboard owner markers are incomplete.' ) );
		if ( ! $disk_ok ) {
			$failures[] = 'commerce_dashboard_disk_markers_missing';
		}

		$loader_ok = method_exists( 'BizCity_Router_License_Ledger', 'get_admin_commerce_dashboard' );
		$ctx->emit_step( array( 'label' => 'Loader - commerce aggregate reader', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'Bounded commerce aggregate reader is loaded.' : 'Commerce aggregate reader is missing.' ) );
		if ( ! $loader_ok ) {
			$failures[] = 'commerce_dashboard_loader_missing';
		}
		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Commerce dashboard Disk/Loader contract failed.', 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Load the existing Master Admin owner and Global ledger aggregate reader before rerunning.' );
		}

		$dashboard = BizCity_Router_License_Ledger::get_admin_commerce_dashboard( array( 'from' => '2000-01-01', 'to' => '2100-12-31' ) );
		$sales = is_array( $dashboard['sales'] ?? null ) ? $dashboard['sales'] : array();
		$shape_ok = isset( $dashboard['from'], $dashboard['to'], $dashboard['plans'], $dashboard['renewals'], $dashboard['expiry'], $dashboard['integrity'], $dashboard['integrity']['woo_scanned'], $dashboard['integrity']['woo_paid_missing_grant'], $dashboard['integrity']['orphan_ledger_orders'], $dashboard['expiry']['expired'], $dashboard['expiry']['due_7d'], $dashboard['expiry']['due_30d'], $dashboard['expiry']['due_60d'], $sales['paid_orders'], $sales['gross'], $sales['refunds'], $sales['net'], $sales['average_order'] ) && is_array( $dashboard['plans'] ) && is_array( $dashboard['renewals'] ) && count( $dashboard['plans'] ) <= 50 && count( $dashboard['renewals']['rows'] ?? array() ) <= 50 && (int) $dashboard['integrity']['woo_scanned'] <= 100;
		$ctx->emit_step( array( 'label' => 'Runtime - bounded dashboard shape', 'status' => $shape_ok ? 'pass' : 'fail', 'detail' => $shape_ok ? 'Date-filtered sales/refund, plan and renewal aggregates returned within bounded row limits.' : 'Commerce dashboard aggregate shape or bounds are incomplete.' ) );
		if ( ! $shape_ok ) {
			$failures[] = 'commerce_dashboard_shape_failed';
		}

		$safe = true;
		foreach ( array( $dashboard, $sales, $dashboard['plans'] ?? array(), $dashboard['renewals'] ?? array() ) as $part ) {
			if ( is_array( $part ) ) {
				foreach ( array( 'key_hash', 'api_key', 'bearer', 'secret' ) as $forbidden ) {
					if ( array_key_exists( $forbidden, $part ) ) {
						$safe = false;
					}
				}
			}
		}
		$ctx->emit_step( array( 'label' => 'Runtime - commerce redaction', 'status' => $safe ? 'pass' : 'fail', 'detail' => $safe ? 'Commerce aggregates contain no credentials or raw hashes.' : 'Commerce aggregate response exposed a forbidden credential/hash field.' ) );
		if ( ! $safe ) {
			$failures[] = 'unsafe_commerce_dashboard';
		}

		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Commerce dashboard runtime failed: ' . implode( ', ', array_unique( $failures ) ), 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Keep dashboard reads bounded/date-filtered and reconcile ledger values against Woo before promoting H8.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Commerce dashboard passed bounded date-filtered sales/refund, plan and renewal aggregate checks without mutation.' );
	}

	public function cleanup(): void {
		// Read-only probe: no persistent artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Commerce_Dashboard';
	return $list;
} );
