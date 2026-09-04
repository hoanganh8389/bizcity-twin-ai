<?php
/**
 * Read-only runtime probe for exact-key plan/checkout context on the B1 Hub.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_B2B2C_Plan_Context', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Plan_Context implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'b2b2c.account.plan_context';
	}

	public function label(): string {
		return 'B2B2C exact-key plan context';
	}

	public function description(): string {
		return 'Checks Hub plan/checkout ownership and rejects foreign key context without creating an order.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 20;
	}

	public function icon(): string {
		return 'key-round';
	}

	public function estimate_ms(): int {
		return 250;
	}

	public function precondition() {
		// [2026-09-02 Johnny Chu] R-GW-8/B2C-F5 — exact-key Hub commerce evidence belongs to the B1 Hub, never to a B2 client.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: exact-key Hub plan context is owned by bizcity.vn.';
		}

		$router_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/bizcity-llm-router/includes/' : '';
		$load_map  = array(
			'BizCity_Router_Auth'             => 'class-router-auth.php',
			'BizCity_Router_Account'           => 'class-router-account-rest.php',
			'BizCity_Router_Account_Experience' => 'class-router-account-experience.php',
			'BizCity_Router_Commerce_Service'  => 'commerce/class-router-commerce-service.php',
			'BizCity_Router_Master_Schema'     => 'class-router-master-schema.php',
		);
		foreach ( $load_map as $class_name => $file_name ) {
			$file = $router_dir . $file_name;
			if ( ! class_exists( $class_name ) && $router_dir !== '' && is_file( $file ) && is_readable( $file ) && class_exists( 'BizCity_Safe_Loader' ) ) {
				BizCity_Safe_Loader::require_file( $file, 'diagnostics.b2b2c_plan_context.' . $class_name );
			}
		}
		if ( ! class_exists( 'BizCity_Router_Account_Experience' ) || ! class_exists( 'BizCity_Router_Commerce_Service' ) ) {
			return new WP_Error( 'plan_context_classes_missing', 'Account Experience/Commerce classes are not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();
		$router_plugin_file = 'bizcity-llm-router/bizcity-llm-router.php';
		$active_plugins     = (array) get_option( 'active_plugins', array() );
		$network_plugins    = (array) get_site_option( 'active_sitewide_plugins', array() );
		$router_is_active   = in_array( $router_plugin_file, $active_plugins, true ) || isset( $network_plugins[ $router_plugin_file ] );
		$ctx->emit_step( array(
			'label'  => 'Router plugin activation',
			'status' => $router_is_active ? 'pass' : 'fail',
			'detail' => $router_is_active ? 'bizcity-llm-router is active in the current Hub lifecycle' : 'Router is not active in the current Hub lifecycle',
		) );
		if ( ! $router_is_active ) {
			return array( 'status' => 'fail', 'summary' => 'Router plugin is inactive; exact-key plan context cannot claim Hub runtime.', 'error' => 'router_plugin_inactive', 'fix_hint' => 'Activate bizcity-llm-router on the B1 Hub, then rerun this probe with --host=bizcity.vn.' );
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$account_file = $router_dir . '/includes/class-router-account-experience.php';
		$commerce_file = $router_dir . '/includes/commerce/class-router-commerce-service.php';
		$account_source = is_readable( $account_file ) ? (string) file_get_contents( $account_file ) : '';
		$commerce_source = is_readable( $commerce_file ) ? (string) file_get_contents( $commerce_file ) : '';
		$disk_ok = $account_source !== '' && $commerce_source !== ''
			&& strpos( $account_source, 'handle_plan_checkout' ) !== false
			&& strpos( $account_source, 'owned_active_key' ) !== false
			&& strpos( $commerce_source, '_bizcity_key_id' ) !== false
			&& strpos( $commerce_source, '_bizcity_plan_code' ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Disk · exact-key checkout contract',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Account ownership guard and immutable key/plan order metadata markers present' : 'Exact-key checkout markers missing',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'disk_exact_key_markers_missing';
		}

		$loader_ok = method_exists( 'BizCity_Router_Account_Experience', 'handle_plan_checkout' )
			&& method_exists( 'BizCity_Router_Commerce_Service', 'create_checkout' )
			&& method_exists( 'BizCity_Router_Master_Schema', 'get_plan_by_level' );
		$ctx->emit_step( array(
			'label'  => 'Loader · exact-key checkout methods',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'Account checkout, Commerce checkout and Master Plan methods are loaded' : 'One or more exact-key checkout methods are missing',
		) );
		if ( ! $loader_ok ) {
			$failures[] = 'loader_exact_key_methods_missing';
		}
		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Exact-key plan context Disk/Loader contract failed.', 'error' => implode( ',', $failures ), 'fix_hint' => 'Load the Hub Account Experience, Commerce Service and Master Plan classes before rerunning.' );
		}

		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return array( 'status' => 'fail', 'summary' => 'No authenticated Hub user is available for ownership denial runtime check.', 'error' => 'hub_user_missing', 'fix_hint' => 'Run the probe in the Hub diagnostics admin context.' );
		}

		$foreign_request = new WP_REST_Request( 'POST', '/bizcity/v1/account/plan-checkout' );
		$foreign_request->set_header( 'Content-Type', 'application/json' );
		$foreign_request->set_body( wp_json_encode( array( 'key_id' => 2147483647, 'plan_code' => 'free' ) ) );
		$foreign_response = BizCity_Router_Account_Experience::handle_plan_checkout( $foreign_request );
		$foreign_data = $foreign_response instanceof WP_REST_Response ? $foreign_response->get_data() : array();
		$foreign_status = $foreign_response instanceof WP_REST_Response ? (int) $foreign_response->get_status() : 0;
		$foreign_denied = 403 === $foreign_status
			&& is_array( $foreign_data )
			&& 'permission_denied' === (string) ( $foreign_data['code'] ?? '' )
			&& ! isset( $foreign_data['checkout_url'] );
		$ctx->emit_step( array(
			'label'  => 'Runtime · foreign key denial',
			'status' => $foreign_denied ? 'pass' : 'fail',
			'detail' => $foreign_denied ? 'Foreign/non-owned key rejected with 403 before checkout creation' : 'Foreign key was not rejected with the expected ownership boundary',
		) );
		if ( ! $foreign_denied ) {
			$failures[] = 'foreign_key_not_denied';
		}

		global $wpdb;
		$owned_key_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->base_prefix}bizcity_llm_api_keys WHERE user_id = %d AND is_active = 1 ORDER BY id ASC LIMIT 1",
			$uid
		) );
		$invalid_plan_ok = false;
		if ( $owned_key_id > 0 ) {
			$invalid_request = new WP_REST_Request( 'POST', '/bizcity/v1/account/plan-checkout' );
			$invalid_request->set_header( 'Content-Type', 'application/json' );
			$invalid_request->set_body( wp_json_encode( array( 'key_id' => $owned_key_id, 'plan_code' => '__invalid_plan__' ) ) );
			$invalid_response = BizCity_Router_Account_Experience::handle_plan_checkout( $invalid_request );
			$invalid_data = $invalid_response instanceof WP_REST_Response ? $invalid_response->get_data() : array();
			$invalid_status = $invalid_response instanceof WP_REST_Response ? (int) $invalid_response->get_status() : 0;
			$invalid_plan_ok = 400 === $invalid_status && 'not_found' === (string) ( $invalid_data['code'] ?? '' ) && ! isset( $invalid_data['checkout_url'] );
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime · owned key plan validation',
			'status' => $invalid_plan_ok ? 'pass' : 'fail',
			'detail' => $invalid_plan_ok ? 'Owned key reaches server-side plan validation; invalid plan rejected before checkout creation' : 'Owned key/invalid plan validation did not return the expected safe rejection',
		) );
		if ( ! $invalid_plan_ok ) {
			$failures[] = $owned_key_id > 0 ? 'invalid_plan_not_rejected' : 'owned_key_missing';
		}

		if ( ! empty( $failures ) ) {
			return array( 'status' => 'fail', 'summary' => 'Exact-key plan context runtime failed: ' . implode( ', ', $failures ), 'error' => implode( '; ', $failures ), 'fix_hint' => 'Keep checkout ownership and plan validation before Commerce order creation; rerun after repairing the failing boundary.' );
		}
		return array( 'status' => 'pass', 'summary' => 'Exact-key plan context passed Disk/Loader/Runtime without creating an order or calling a provider.' );
	}

	public function cleanup(): void {
		// Read-only probe: no fixture state to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Plan_Context';
	return $list;
} );
