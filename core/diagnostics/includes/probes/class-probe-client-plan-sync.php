<?php
/**
 * BizCity Diagnostics — client.plan_sync probe.
 *
 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — validate BizCoach client
 * billing proxy and entitlement sync routes with Disk/Loader/Runtime evidence.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Client_Plan_Sync', false ) ) {
	return;
}

final class BizCity_Probe_Client_Plan_Sync implements BizCity_Diagnostics_Probe {

	// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — initial client plan-sync DDV probe.

	public function id(): string       { return 'client.plan_sync'; }
	public function label(): string    { return 'Client Plan Sync (bizcity-client/v1)'; }
	public function description(): string {
		return 'Validate BizCoach billing proxy + plan sync wiring (Disk/Loader/Runtime).';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 49; }
	public function icon(): string     { return 'refresh'; }
	public function estimate_ms(): int { return 450; }

	public function precondition() {
		if ( ! defined( 'BCPRO_DIR' ) ) {
			return new WP_Error( 'bcpro_inactive', 'BCPRO_DIR chưa định nghĩa. Kích hoạt bizcoach-pro trước khi chạy probe.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();

		$plan_service_file = rtrim( (string) BCPRO_DIR, '/\\' ) . '/includes/frontend/class-bcpro-plan-service.php';
		$proxy_rest_file   = rtrim( (string) BCPRO_DIR, '/\\' ) . '/includes/frontend/class-bcpro-billing-proxy-rest.php';

		/* -----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ----------------------------------------------------------------- */
		$disk_service = file_exists( $plan_service_file );
		$disk_rest    = file_exists( $proxy_rest_file );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · billing/plan files',
			'status' => ( $disk_service && $disk_rest ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'plan_service=%s | billing_proxy=%s',
				$disk_service ? 'present' : 'missing',
				$disk_rest ? 'present' : 'missing'
			),
		) );
		if ( ! $disk_service || ! $disk_rest ) {
			$failures[] = 'disk_files_missing';
		}

		if ( $disk_rest ) {
			$src = (string) file_get_contents( $proxy_rest_file );
			$tokens_ok =
				strpos( $src, "'/entitlement/sync'" ) !== false
				&& strpos( $src, "'/me/plan'" ) !== false
				&& strpos( $src, 'entitlement_sync' ) !== false
				&& strpos( $src, 'me_plan' ) !== false;
			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · route tokens',
				'status' => $tokens_ok ? 'pass' : 'fail',
				'detail' => $tokens_ok
					? 'Found /entitlement/sync + /me/plan route markers.'
					: 'Missing one or more route markers in billing proxy class.',
			) );
			if ( ! $tokens_ok ) {
				$failures[] = 'disk_tokens_missing';
			}
		}

		/* -----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ----------------------------------------------------------------- */
		$service_loaded = class_exists( 'BizCoach_Pro_Plan_Service' );
		$rest_loaded    = class_exists( 'BizCoach_Pro_Billing_Proxy_REST' );
		$method_ok      = $rest_loaded
			&& method_exists( 'BizCoach_Pro_Billing_Proxy_REST', 'entitlement_sync' )
			&& method_exists( 'BizCoach_Pro_Billing_Proxy_REST', 'me_plan' );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · classes/methods',
			'status' => ( $service_loaded && $rest_loaded && $method_ok ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'plan_service=%s | billing_proxy=%s | methods=%s',
				$service_loaded ? 'loaded' : 'missing',
				$rest_loaded ? 'loaded' : 'missing',
				$method_ok ? 'ok' : 'missing'
			),
		) );
		if ( ! $service_loaded || ! $rest_loaded || ! $method_ok ) {
			$failures[] = 'loader_missing';
		}

		$routes = rest_get_server()->get_routes();
		$sync_methods = $this->collect_route_methods( $routes, '/bizcity-client/v1/entitlement/sync' );
		$plan_methods = $this->collect_route_methods( $routes, '/bizcity-client/v1/me/plan' );
		$route_ok = in_array( 'POST', $sync_methods, true ) && in_array( 'GET', $plan_methods, true );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · REST routes',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'sync=%s | me_plan=%s',
				empty( $sync_methods ) ? 'none' : implode( ',', $sync_methods ),
				empty( $plan_methods ) ? 'none' : implode( ',', $plan_methods )
			),
		) );
		if ( ! $route_ok ) {
			$failures[] = 'loader_routes_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Client plan-sync Disk/Loader failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Verify BizCoach billing proxy files and route registration.',
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
				'summary' => 'Disk/Loader passed. Runtime skipped due to missing logged-in user context.',
				'reason'  => 'user_context_missing',
			);
		}

		$plan_req = new WP_REST_Request( 'GET', '/bizcity-client/v1/me/plan' );
		$plan_req->set_param( 'fresh', '1' );
		$plan_res  = rest_do_request( $plan_req );
		$plan_data = ( $plan_res instanceof WP_REST_Response ) ? $plan_res->get_data() : array();
		$plan_ok   = is_array( $plan_data ) && array_key_exists( 'success', $plan_data );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · GET /me/plan',
			'status' => $plan_ok ? 'pass' : 'fail',
			'detail' => $plan_ok
				? 'Route responded with plan payload shape.'
				: 'Route did not return expected payload shape.',
		) );
		if ( ! $plan_ok ) {
			$failures[] = 'runtime_me_plan_failed';
		}

		$sync_req = new WP_REST_Request( 'POST', '/bizcity-client/v1/entitlement/sync' );
		$sync_res = rest_do_request( $sync_req );
		$sync_data = ( $sync_res instanceof WP_REST_Response ) ? $sync_res->get_data() : array();
		$sync_ok = is_array( $sync_data ) && array_key_exists( 'success', $sync_data );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · POST /entitlement/sync',
			'status' => $sync_ok ? 'pass' : 'fail',
			'detail' => $sync_ok
				? 'Sync route responded with payload shape.'
				: 'Sync route did not return expected payload shape.',
		) );
		if ( ! $sync_ok ) {
			$failures[] = 'runtime_sync_failed';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'Client plan-sync runtime failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check BizCoach billing proxy route callbacks and plan service fail-open handling.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'Client plan-sync probe passed across Disk/Loader/Runtime.',
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

	public function cleanup(): void {
		// No-op: runtime smoke for this probe is read-only.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Client_Plan_Sync';
	return $list;
} );
