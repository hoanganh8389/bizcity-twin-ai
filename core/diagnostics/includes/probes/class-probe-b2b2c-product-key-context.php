<?php
/**
 * Read-only D11 probe for the exact-key Woo product purchase context.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// [2026-09-02 11:45 AM Johnny Chu - Chu Hoàng Anh] B2C-D11 — the diagnostics contract is an interface, so guard it with interface_exists().
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_B2B2C_Product_Key_Context', false ) ) {
	return;
}

final class BizCity_Probe_B2B2C_Product_Key_Context implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-02 11:15 AM Johnny Chu - Chu Hoàng Anh] B2C-D11 - identify the exact-key product purchase context probe.
		return 'b2b2c.product_key_context';
	}

	public function label(): string {
		return 'B2B2C product key purchase context';
	}

	public function description(): string {
		return 'Checks the read-only Woo product key selector/create contract, Account REST projection and exact-key purchase mapping without creating a key or order.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 23;
	}

	public function icon(): string {
		return 'key-round';
	}

	public function estimate_ms(): int {
		return 180;
	}

	public function precondition() {
		// [2026-09-02 11:15 AM Johnny Chu - Chu Hoàng Anh] B2C-D11 - keep this Hub-owned probe out of B2 client runs.
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( ! in_array( $host, array( 'bizcity.vn', 'www.bizcity.vn' ), true ) ) {
			return 'not_applicable_b2_client: exact-key product purchase context is owned by bizcity.vn.';
		}

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' )
			? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' )
			: ( defined( 'WP_PLUGIN_DIR' ) ? rtrim( WP_PLUGIN_DIR, '/\\' ) . '/bizcity-llm-router' : '' );
		if ( $router_dir === '' || ! class_exists( 'BizCity_Safe_Loader', false ) ) {
			return new WP_Error( 'product_key_context_loader_missing', 'D11 Router loader context is not available.' );
		}

		$files = array(
			$router_dir . '/includes/class-router-account-experience.php' => 'diagnostics.b2b2c_product_key_context.account_experience',
			$router_dir . '/includes/class-router-account-rest.php'       => 'diagnostics.b2b2c_product_key_context.account_rest',
			$router_dir . '/includes/class-router-master-schema.php'      => 'diagnostics.b2b2c_product_key_context.master_schema',
		);
		foreach ( $files as $file => $label ) {
			if ( ! is_file( $file ) || ! is_readable( $file ) ) {
				return new WP_Error( 'product_key_context_file_missing', 'A D11 Router owner artifact is missing.' );
			}
			if ( strpos( $label, 'master_schema' ) !== false && class_exists( 'BizCity_Router_Master_Schema', false ) ) {
				continue;
			}
			BizCity_Safe_Loader::require_file( $file, $label );
		}

		if ( ! class_exists( 'BizCity_Router_Account_Experience' ) || ! class_exists( 'BizCity_Router_Account' ) ) {
			return new WP_Error( 'product_key_context_owner_missing', 'D11 Account Experience or Account REST owner is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures   = array();
		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' )
			? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' )
			: rtrim( WP_PLUGIN_DIR, '/\\' ) . '/bizcity-llm-router';
		$experience_file = $router_dir . '/includes/class-router-account-experience.php';
		$account_file    = $router_dir . '/includes/class-router-account-rest.php';
		$experience     = is_readable( $experience_file ) ? (string) file_get_contents( $experience_file ) : '';
		$account        = is_readable( $account_file ) ? (string) file_get_contents( $account_file ) : '';

		$disk_ok = $experience !== ''
			&& $account !== ''
			&& strpos( $experience, 'woocommerce_before_add_to_cart_button' ) !== false
			&& strpos( $experience, 'data-product-key-context' ) !== false
			&& strpos( $experience, 'data-product-key-create-submit' ) !== false
			&& strpos( $experience, 'block_unscoped_paid_plan_cart' ) !== false
			&& strpos( $experience, 'plan_code' ) !== false
			&& strpos( $account, 'master_level' ) !== false
			&& strpos( $account, 'is_non_production' ) !== false
			&& strpos( $account, "'key_id'" ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Disk - D11 owner and projection markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'Product selector/create, native-cart guard and Account REST projection markers are readable.' : 'D11 owner or Account REST projection markers are missing.',
		) );
		if ( ! $disk_ok ) {
			$failures[] = 'd11_source_markers_missing';
		}

		$hook_ok = class_exists( 'BizCity_Router_Account_Experience' )
			&& method_exists( 'BizCity_Router_Account_Experience', 'render_product_key_context' )
			&& method_exists( 'BizCity_Router_Account_Experience', 'block_unscoped_paid_plan_cart' )
			&& function_exists( 'has_action' )
			&& false !== has_action( 'woocommerce_before_add_to_cart_button', array( 'BizCity_Router_Account_Experience', 'render_product_key_context' ) )
			&& false !== has_filter( 'woocommerce_add_to_cart_validation', array( 'BizCity_Router_Account_Experience', 'block_unscoped_paid_plan_cart' ) );
		$ctx->emit_step( array(
			'label'  => 'Loader - Woo product hook registration',
			'status' => $hook_ok ? 'pass' : 'fail',
			'detail' => $hook_ok ? 'D11 renderer and native-cart guard are registered by Account Experience.' : 'D11 Woo hooks are not registered by Account Experience.',
		) );
		if ( ! $hook_ok ) {
			$failures[] = 'd11_hooks_missing';
		}

		$mapping_ok = false;
		$mapped_id  = 0;
		try {
			$plans = class_exists( 'BizCity_Router_Master_Schema' ) && method_exists( 'BizCity_Router_Master_Schema', 'get_all_plans' )
				? (array) BizCity_Router_Master_Schema::get_all_plans()
				: array();
			foreach ( $plans as $plan ) {
				if ( is_array( $plan ) && ! empty( $plan['is_active'] ) && (float) ( $plan['price_usd'] ?? 0 ) > 0 && (int) ( $plan['woo_product_id'] ?? 0 ) > 0 ) {
					$mapping_ok = true;
					$mapped_id  = (int) $plan['woo_product_id'];
					break;
				}
			}
		} catch ( Throwable $e ) {
			$failures[] = 'd11_plan_mapping_exception';
		}
		$ctx->emit_step( array(
			'label'  => 'Runtime - paid Master Plan Woo mapping',
			'status' => $mapping_ok ? 'pass' : 'fail',
			'detail' => $mapping_ok ? 'At least one active paid Master Plan has a configured Woo product binding.' : 'No active paid Master Plan has a configured Woo product binding.',
		) );
		if ( ! $mapping_ok ) {
			$failures[] = 'd11_paid_product_mapping_missing';
		}

		$checkout_ok = strpos( $experience, 'data-product-key-checkout' ) !== false
			&& strpos( $experience, 'JSON.stringify({key_id:keyId,plan_code:planCode})' ) !== false
			&& strpos( $experience, 'get_current_user_id()' ) !== false
			&& strpos( $account, 'user_id = %d' ) !== false;
		$ctx->emit_step( array(
			'label'  => 'Runtime - exact-key checkout contract',
			'status' => $checkout_ok ? 'pass' : 'fail',
			'detail' => $checkout_ok ? 'Product UI sends key_id/plan_code and Account REST scopes key listing to the current user.' : 'Exact-key checkout or current-user key scope markers are incomplete.',
		) );
		if ( ! $checkout_ok ) {
			$failures[] = 'd11_exact_key_contract_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'D11 product key context contract failed: ' . implode( ', ', array_unique( $failures ) ),
				'error'    => implode( '; ', array_unique( $failures ) ),
				'fix_hint' => 'Load Account Experience on B1, bind a paid Master Plan to a Woo product, and preserve current-user exact-key checkout markers.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'D11 product key selector/create and exact-key checkout contract passed read-only checks; mapped product id=' . $mapped_id . '.',
		);
	}

	public function cleanup(): void {
		// [2026-09-02 11:15 AM Johnny Chu - Chu Hoàng Anh] B2C-D11 - read-only probe has no persistent artifacts.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_B2B2C_Product_Key_Context';
	return $list;
} );
