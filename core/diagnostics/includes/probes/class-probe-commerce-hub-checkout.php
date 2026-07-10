<?php
/**
 * BizCity Diagnostics — commerce.hub_checkout probe.
 *
 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — validate hub commerce routes
 * (/bizcity-commerce/v1/packages|checkout|orders/{id}/status).
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Commerce_Hub_Checkout', false ) ) {
	return;
}

final class BizCity_Probe_Commerce_Hub_Checkout implements BizCity_Diagnostics_Probe {

	// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — initial commerce checkout DDV probe.

	public function id(): string       { return 'commerce.hub_checkout'; }
	public function label(): string    { return 'Commerce Hub Checkout'; }
	public function description(): string {
		return 'Disk/Loader/Runtime evidence for Hub commerce package + checkout + order-status routes.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 50; }
	public function icon(): string     { return 'credit-card'; }
	public function estimate_ms(): int { return 700; }

	public function precondition() {
		// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — Hub-only probe: skip on client
		// sites where bizcity-llm-router is intentionally absent (R-GW-8).
		if ( ! class_exists( 'BizCity_Router_Commerce_REST' ) && ! defined( 'BIZCITY_LLM_ROUTER_DIR' ) ) {
			return new WP_Error( 'hub_router_not_loaded', 'bizcity-llm-router chưa load trên site này; probe được skip.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' )
			? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' )
			: rtrim( WP_PLUGIN_DIR, '/\\' ) . '/bizcity-llm-router';

		$disk_rest    = $router_dir . '/includes/commerce/class-router-commerce-rest.php';
		$disk_service = $router_dir . '/includes/commerce/class-router-commerce-service.php';

		/* -----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ----------------------------------------------------------------- */
		$has_rest    = file_exists( $disk_rest );
		$has_service = file_exists( $disk_service );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · commerce files',
			'status' => ( $has_rest && $has_service ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'rest=%s | service=%s',
				$has_rest ? 'present' : 'missing',
				$has_service ? 'present' : 'missing'
			),
		) );
		if ( ! $has_rest || ! $has_service ) {
			$failures[] = 'disk_files_missing';
		}

		if ( $has_rest ) {
			$src = (string) file_get_contents( $disk_rest );
			$tokens_ok =
				strpos( $src, "'/packages'" ) !== false
				&& strpos( $src, "'/checkout'" ) !== false
				&& strpos( $src, "'/orders/(?P<order_id>\\d+)/status'" ) !== false
				&& strpos( $src, "'/webhook/woo-order-paid'" ) !== false;
			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · route tokens',
				'status' => $tokens_ok ? 'pass' : 'fail',
				'detail' => $tokens_ok
					? 'Found commerce route markers.'
					: 'Missing one or more commerce route markers.',
			) );
			if ( ! $tokens_ok ) {
				$failures[] = 'disk_tokens_missing';
			}
		}

		/* -----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ----------------------------------------------------------------- */
		$rest_loaded    = class_exists( 'BizCity_Router_Commerce_REST' );
		$service_loaded = class_exists( 'BizCity_Router_Commerce_Service' );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · classes',
			'status' => ( $rest_loaded && $service_loaded ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'rest=%s | service=%s',
				$rest_loaded ? 'loaded' : 'missing',
				$service_loaded ? 'loaded' : 'missing'
			),
		) );
		if ( ! $rest_loaded || ! $service_loaded ) {
			$failures[] = 'loader_classes_missing';
		}

		$routes = rest_get_server()->get_routes();
		$pkg_methods      = $this->collect_route_methods( $routes, '/bizcity-commerce/v1/packages' );
		$checkout_methods = $this->collect_route_methods( $routes, '/bizcity-commerce/v1/checkout' );
		$status_methods   = $this->collect_route_methods_by_prefix( $routes, '/bizcity-commerce/v1/orders/' );
		$route_ok = in_array( 'GET', $pkg_methods, true )
			&& in_array( 'POST', $checkout_methods, true )
			&& in_array( 'GET', $status_methods, true );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · routes',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'packages=%s | checkout=%s | status=%s',
				empty( $pkg_methods ) ? 'none' : implode( ',', $pkg_methods ),
				empty( $checkout_methods ) ? 'none' : implode( ',', $checkout_methods ),
				empty( $status_methods ) ? 'none' : implode( ',', $status_methods )
			),
		) );
		if ( ! $route_ok ) {
			$failures[] = 'loader_routes_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Commerce Hub Disk/Loader failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Verify commerce class loading and route wiring in bizcity-llm-router.',
			);
		}

		/* -----------------------------------------------------------------
		 * Layer 3 — Runtime
		 * ----------------------------------------------------------------- */
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			$ctx->emit_step( array(
				'label'  => 'Layer 3 · Runtime · user context',
				'status' => 'skip',
				'detail' => 'No logged-in user. Runtime smoke skipped.',
			) );
			return array(
				'status'  => 'skip',
				'summary' => 'Disk/Loader passed. Runtime skipped due to missing logged-in user.',
				'reason'  => 'user_context_missing',
			);
		}

		$pkg_req = new WP_REST_Request( 'GET', '/bizcity-commerce/v1/packages' );
		$pkg_res = rest_do_request( $pkg_req );
		$pkg_data = ( $pkg_res instanceof WP_REST_Response ) ? $pkg_res->get_data() : array();
		$pkg_ok = is_array( $pkg_data ) && ! empty( $pkg_data['success'] ) && isset( $pkg_data['items'] ) && is_array( $pkg_data['items'] );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · GET /packages',
			'status' => $pkg_ok ? 'pass' : 'fail',
			'detail' => $pkg_ok ? 'Packages endpoint returned catalog.' : 'Packages endpoint failed.',
		) );
		if ( ! $pkg_ok ) {
			$failures[] = 'runtime_packages_failed';
		}

		$plan_code = 'free';
		if ( $pkg_ok ) {
			foreach ( (array) $pkg_data['items'] as $item ) {
				if ( is_array( $item ) && isset( $item['plan_code'] ) ) {
					$candidate = sanitize_key( (string) $item['plan_code'] );
					if ( $candidate !== '' ) {
						$plan_code = $candidate;
						if ( $candidate === 'free' ) {
							break;
						}
					}
				}
			}
		}

		$checkout_req = new WP_REST_Request( 'POST', '/bizcity-commerce/v1/checkout' );
		$checkout_req->set_param( 'plan_code', $plan_code );
		$checkout_req->set_param( 'billing_cycle', 'month' );
		$checkout_req->set_param( 'client_id', 'diag_client_checkout' );
		$checkout_req->set_param( 'user_id', $uid );
		$checkout_req->set_param( 'return_url', home_url( '/' ) );
		$checkout_res = rest_do_request( $checkout_req );
		$checkout_data = ( $checkout_res instanceof WP_REST_Response ) ? $checkout_res->get_data() : array();
		$checkout_ok = is_array( $checkout_data ) && array_key_exists( 'success', $checkout_data );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · POST /checkout',
			'status' => $checkout_ok ? 'pass' : 'fail',
			'detail' => $checkout_ok ? 'Checkout endpoint returned payload shape.' : 'Checkout endpoint failed.',
		) );
		if ( ! $checkout_ok ) {
			$failures[] = 'runtime_checkout_failed';
		}

		$status_ok = true;
		if ( $checkout_ok && ! empty( $checkout_data['success'] ) && isset( $checkout_data['order_id'] ) ) {
			$order_id = (int) $checkout_data['order_id'];
			if ( $order_id > 0 ) {
				$status_req = new WP_REST_Request( 'GET', '/bizcity-commerce/v1/orders/' . $order_id . '/status' );
				$status_res = rest_do_request( $status_req );
				$status_data = ( $status_res instanceof WP_REST_Response ) ? $status_res->get_data() : array();
				$status_ok = is_array( $status_data ) && array_key_exists( 'success', $status_data );
				$ctx->emit_step( array(
					'label'  => 'Layer 3 · Runtime · GET /orders/{id}/status',
					'status' => $status_ok ? 'pass' : 'fail',
					'detail' => $status_ok ? 'Order status endpoint returned payload shape.' : 'Order status endpoint failed.',
				) );
				if ( ! $status_ok ) {
					$failures[] = 'runtime_status_failed';
				}
			}
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Commerce runtime failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check checkout payload handling and route permission/auth logic.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Commerce Hub checkout probe passed across Disk/Loader/Runtime.',
		);
	}

	/**
	 * @param array<string,mixed> $routes
	 * @param string              $exact_key
	 * @return array<int,string>
	 */
	private function collect_route_methods( array $routes, $exact_key ): array {
		$methods = array();
		if ( ! isset( $routes[ $exact_key ] ) || ! is_array( $routes[ $exact_key ] ) ) {
			return $methods;
		}
		foreach ( (array) $routes[ $exact_key ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) || ! is_array( $ep['methods'] ) ) {
				continue;
			}
			foreach ( $ep['methods'] as $method => $enabled ) {
				if ( $enabled ) {
					$methods[] = strtoupper( (string) $method );
				}
			}
		}
		$methods = array_values( array_unique( $methods ) );
		sort( $methods );
		return $methods;
	}

	/**
	 * @param array<string,mixed> $routes
	 * @param string              $prefix
	 * @return array<int,string>
	 */
	private function collect_route_methods_by_prefix( array $routes, $prefix ): array {
		$methods = array();
		foreach ( $routes as $route_key => $endpoints ) {
			if ( strpos( (string) $route_key, $prefix ) !== 0 ) {
				continue;
			}
			foreach ( (array) $endpoints as $ep ) {
				if ( ! is_array( $ep ) || empty( $ep['methods'] ) || ! is_array( $ep['methods'] ) ) {
					continue;
				}
				foreach ( $ep['methods'] as $method => $enabled ) {
					if ( $enabled ) {
						$methods[] = strtoupper( (string) $method );
					}
				}
			}
		}
		$methods = array_values( array_unique( $methods ) );
		sort( $methods );
		return $methods;
	}

	public function cleanup(): void {
		// No-op: probe only touches REST read/create paths with safe payloads.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Commerce_Hub_Checkout';
	return $list;
} );
