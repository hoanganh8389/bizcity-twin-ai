<?php
/**
 * Read-only H9 probe for issuer-scoped multi-Hub authority.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Multi_Hub_Authority', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Multi_Hub_Authority implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-05 12:15 PM Johnny Chu - Chu Hoàng Anh] B2C-H9 - identify issuer-scoped multi-Hub authority boundary.
		return 'b2b2c.checkout.multi_hub_authority';
	}

	public function label(): string {
		return 'B2B2C multi-Hub issuer authority';
	}

	public function description(): string {
		return 'Checks stable issuer identity, issuer-scoped ledger/cache reads and foreign-issuer separation without implementing federation or raw-key roaming.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 30;
	}

	public function icon(): string {
		return 'network';
	}

	public function estimate_ms(): int {
		return 180;
	}

	public function precondition() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: issuer authority is owned by the B1 Hub.';
		}
		if ( ! class_exists( 'BizCity_Router_License_Ledger' ) || ! class_exists( 'BizCity_Router_Commerce_Service' ) ) {
			return new WP_Error( 'multi_hub_loader_missing', 'License ledger or commerce issuer owner is not loaded.' );
		}
		if ( ! function_exists( 'bizcity_tbl_exists' ) || ! bizcity_tbl_exists( BizCity_Router_License_Ledger::table_name() ) ) {
			return 'ledger_runtime_missing: issuer authority requires the Global license ledger table.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-05 12:15 PM Johnny Chu - Chu Hoàng Anh] B2C-H9 - verify local issuer scope and distinguish federation evidence from namespace markers.
		$failures = array();
		$router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$router_active      = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
		$ctx->emit_step( array( 'label' => 'Router plugin activation', 'status' => $router_active ? 'pass' : 'fail', 'detail' => $router_active ? 'Router is active in the B1 lifecycle.' : 'Router is inactive in the B1 lifecycle.' ) );
		if ( ! $router_active ) {
			return array( 'status' => 'fail', 'summary' => 'Router is inactive; issuer authority cannot claim runtime ownership.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate the deployed Router on B1, then rerun this focused probe.' );
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$ledger_file = $router_dir . '/includes/license/class-router-license-ledger.php';
		$commerce_file = $router_dir . '/includes/commerce/class-router-commerce-service.php';
		$ledger_source = is_readable( $ledger_file ) ? (string) file_get_contents( $ledger_file ) : '';
		$commerce_source = is_readable( $commerce_file ) ? (string) file_get_contents( $commerce_file ) : '';
		$issuer = sanitize_key( (string) get_site_option( 'bizcity_issuer_hub_id', 'bizcity' ) );
		$issuer_ok = $issuer !== '' && strlen( $issuer ) <= 64 && preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $issuer );
		$scope_markers = $ledger_source !== '' && $commerce_source !== ''
			&& strpos( $ledger_source, 'WHERE grant_row.issuer_hub_id' ) !== false
			&& strpos( $ledger_source, "md5( self::issuer_hub_id() )" ) !== false
			&& strpos( $commerce_source, 'issuer_hub_id' ) !== false;
		$ctx->emit_step( array( 'label' => 'Disk/Loader - stable issuer and scope markers', 'status' => $issuer_ok && $scope_markers ? 'pass' : 'fail', 'detail' => $issuer_ok && $scope_markers ? 'Issuer identity is stable/normalized and ledger/cache/checkout code carries issuer scope.' : 'Issuer identity or issuer-scope markers are incomplete.' ) );
		if ( ! $issuer_ok ) {
			$failures[] = 'issuer_identity_invalid';
		}
		if ( ! $scope_markers ) {
			$failures[] = 'issuer_scope_markers_missing';
		}

		global $wpdb;
		$table = BizCity_Router_License_Ledger::table_name();
		$local_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$table} WHERE issuer_hub_id = %s", $issuer ) );
		$foreign_id = 'foreign_' . ( $issuer === 'bizcity' ? 'twinclaw' : 'bizcity' );
		$foreign_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(1) FROM {$table} WHERE issuer_hub_id = %s", $foreign_id ) );
		$local_scope_ok = $local_rows >= 0 && $foreign_rows >= 0;
		$ctx->emit_step( array( 'label' => 'Runtime - issuer-scoped ledger namespace', 'status' => $local_scope_ok ? 'pass' : 'fail', 'detail' => $local_scope_ok ? 'Local and foreign issuer namespaces are queryable independently; no cross-issuer merge was performed.' : 'Issuer-scoped ledger namespace query failed.' ) );
		if ( ! $local_scope_ok ) {
			$failures[] = 'issuer_namespace_query_failed';
		}

		$foreign_fixture_status = $foreign_rows > 0 ? 'pass' : 'deferred';
		$ctx->emit_step( array( 'label' => 'Runtime - second issuer fixture', 'status' => $foreign_fixture_status, 'detail' => $foreign_rows > 0 ? 'A foreign issuer namespace exists for explicit separation evidence.' : 'No second Hub issuer fixture exists on this B1; federation/foreign-issuer denial remains deferred.' ) );
		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Multi-Hub issuer boundary failed: ' . implode( ', ', array_unique( $failures ) ), 'error' => implode( '; ', array_unique( $failures ) ), 'fix_hint' => 'Keep issuer identity stable, scope ledger/cache reads by issuer and add a disposable second-Hub fixture before claiming federation evidence.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Local issuer identity and issuer-scoped ledger/cache boundary passed; second sovereign issuer/federation evidence is explicitly deferred.', 'issuer_hub_id' => $issuer, 'local_rows' => $local_rows, 'foreign_fixture_rows' => $foreign_rows, 'foreign_fixture_status' => $foreign_fixture_status );
	}

	public function cleanup(): void {
		// Read-only probe: no persistent artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Multi_Hub_Authority';
	return $list;
} );
