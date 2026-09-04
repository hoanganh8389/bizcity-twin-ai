<?php
/**
 * Read-only H1/H2 probe for the Woo checkout-first billing context.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Checkout_Billing_Context', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Checkout_Billing_Context implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-02 08:55 PM Johnny Chu - Chu Hoàng Anh] B2C-H1/H2 - identify the checkout-first billing context probe.
		return 'b2b2c.checkout.billing_context';
	}

	public function label(): string {
		return 'B2B2C checkout-first billing context';
	}

	public function description(): string {
		return 'Checks the signed exact-key cart context and Woo order snapshot hooks without creating an order.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 22;
	}

	public function icon(): string {
		return 'shopping-cart';
	}

	public function estimate_ms(): int {
		return 120;
	}

	public function precondition() {
		// [2026-09-02 08:55 PM Johnny Chu - Chu Hoàng Anh] B2C-H1/H2 - keep checkout billing evidence on the B1 Hub owner.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: checkout billing commerce is owned by bizcity.vn.';
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		if ( $router_dir === '' || ! class_exists( 'BizCity_Safe_Loader', false ) ) {
			return new WP_Error( 'checkout_billing_loader_missing', 'Checkout billing Router loader context is not available.' );
		}
		$files = array(
			$router_dir . '/includes/commerce/class-router-commerce-service.php' => 'diagnostics.b2b2c_checkout_billing.commerce',
			$router_dir . '/includes/class-router-account-experience.php'         => 'diagnostics.b2b2c_checkout_billing.account',
		);
		foreach ( $files as $file => $label ) {
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				return new WP_Error( 'checkout_billing_file_missing', 'A checkout billing owner artifact is missing.' );
			}
			if ( strpos( $label, '.commerce' ) !== false && ! class_exists( 'BizCity_Router_Commerce_Service', false ) ) {
				BizCity_Safe_Loader::require_file( $file, $label );
			}
			if ( strpos( $label, '.account' ) !== false && ! class_exists( 'BizCity_Router_Account_Experience', false ) ) {
				BizCity_Safe_Loader::require_file( $file, $label );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 08:55 PM Johnny Chu - Chu Hoàng Anh] B2C-H1/H2 - verify source boundaries and tamper denial without Woo mutation.
		$failures   = array();
		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' ) ? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' ) : '';
		$commerce   = $router_dir . '/includes/commerce/class-router-commerce-service.php';
		$account    = $router_dir . '/includes/class-router-account-experience.php';
		$commerce_source = is_readable( $commerce ) ? (string) file_get_contents( $commerce ) : '';
		$account_source  = is_readable( $account ) ? (string) file_get_contents( $account ) : '';

		$disk_ok = $commerce_source !== ''
			&& $account_source !== ''
			&& strpos( $commerce_source, 'create_checkout_context' ) !== false
			&& strpos( $commerce_source, 'encrypt_json_payload' ) !== false
			&& strpos( $commerce_source, 'validate_checkout_context' ) !== false
			&& strpos( $account_source, 'woocommerce_check_cart_items' ) !== false
			&& strpos( $account_source, 'woocommerce_checkout_create_order' ) !== false
			&& strpos( $account_source, 'woocommerce_checkout_create_order_line_item' ) !== false
			&& strpos( $account_source, '_bizcity_allowed_domain_snapshot' ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Disk - checkout-first owner markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Signed context, pre-order validation and immutable Woo snapshot markers are readable.' : 'Checkout-first owner markers are incomplete.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'checkout_billing_source_markers_missing';
		}

		$hook_ok = class_exists( 'BizCity_Router_Commerce_Service' )
			&& class_exists( 'BizCity_Router_Account_Experience' )
			&& method_exists( 'BizCity_Router_Commerce_Service', 'create_checkout_context' )
			&& method_exists( 'BizCity_Router_Commerce_Service', 'validate_checkout_context' )
			&& method_exists( 'BizCity_Router_Account_Experience', 'snapshot_checkout_order_context' )
			&& method_exists( 'BizCity_Router_Account_Experience', 'snapshot_checkout_item_context' )
			&& function_exists( 'has_action' )
			&& false !== has_action( 'woocommerce_check_cart_items', array( 'BizCity_Router_Account_Experience', 'validate_checkout_cart_context' ) )
			&& false !== has_action( 'woocommerce_checkout_create_order', array( 'BizCity_Router_Account_Experience', 'snapshot_checkout_order_context' ) );
		$ctx->emit_step( array(
			'label'  => 'Loader - checkout Woo hooks',
			'status' => $hook_ok ? 'pass' : 'fail',
			'detail' => $hook_ok ? 'Account Experience checkout validation and order snapshot hooks are registered.' : 'Checkout Woo hooks are not fully registered.',
		) );
		if ( ! $hook_ok ) {
			$failures[] = 'checkout_billing_hooks_missing';
		}

		$tamper_denied = false;
		if ( class_exists( 'BizCity_Router_Commerce_Service' ) && method_exists( 'BizCity_Router_Commerce_Service', 'validate_checkout_context' ) ) {
			$tamper_denied = false === BizCity_Router_Commerce_Service::validate_checkout_context( 'bzc1_tampered_context' );
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime - tampered context denial',
			'status' => $tamper_denied ? 'pass' : 'fail',
			'detail' => $tamper_denied ? 'A malformed context is rejected before key lookup or Woo order creation.' : 'Malformed checkout context was not rejected safely.',
		) );
		if ( ! $tamper_denied ) {
			$failures[] = 'tampered_context_not_denied';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'  => 'fail',
				'summary' => 'Checkout-first billing context contract failed: ' . implode( ', ', array_unique( $failures ) ),
				'error'   => implode( '; ', array_unique( $failures ) ),
				'fix_hint' => 'Load the Hub Commerce Service and Account Experience hooks, then rerun the focused checkout billing probe.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Checkout-first context and immutable snapshot boundaries passed; real billing-form/order creation browser evidence remains separate.',
		);
	}

	public function cleanup(): void {
		// Read-only probe: no persistent artifacts to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
		$list[] = 'BizCity_Probe_B2B2C_Checkout_Billing_Context';
		return $list;
} );