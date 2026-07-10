<?php
/**
 * BizCity Diagnostics — license.hub_entitlement_issue probe.
 *
 * [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — validate Hub license entitlement
 * route and payload contract.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_License_Hub_Entitlement_Issue', false ) ) {
	return;
}

final class BizCity_Probe_License_Hub_Entitlement_Issue implements BizCity_Diagnostics_Probe {

	// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — initial license entitlement DDV probe.

	public function id(): string       { return 'license.hub_entitlement_issue'; }
	public function label(): string    { return 'License Hub Entitlement'; }
	public function description(): string {
		return 'Disk/Loader/Runtime evidence for /bizcity-license/v1/me/entitlement route.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int       { return 51; }
	public function icon(): string     { return 'shield'; }
	public function estimate_ms(): int { return 450; }

	public function precondition() {
		// [2026-07-10 Johnny Chu] PHASE-C-WOO-HUB — Hub-only probe: skip on client
		// sites where bizcity-llm-router is intentionally absent (R-GW-8).
		if ( ! class_exists( 'BizCity_Router_License_REST' ) && ! defined( 'BIZCITY_LLM_ROUTER_DIR' ) ) {
			return new WP_Error( 'hub_router_not_loaded', 'bizcity-llm-router chưa load trên site này; probe được skip.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$failures = array();

		$router_dir = defined( 'BIZCITY_LLM_ROUTER_DIR' )
			? rtrim( (string) BIZCITY_LLM_ROUTER_DIR, '/\\' )
			: rtrim( WP_PLUGIN_DIR, '/\\' ) . '/bizcity-llm-router';

		$rest_file    = $router_dir . '/includes/license/class-router-license-rest.php';
		$service_file = $router_dir . '/includes/license/class-router-license-service.php';

		/* -----------------------------------------------------------------
		 * Layer 1 — Disk
		 * ----------------------------------------------------------------- */
		$has_rest    = file_exists( $rest_file );
		$has_service = file_exists( $service_file );
		$ctx->emit_step( array(
			'label'  => 'Layer 1 · Disk · license files',
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
			$src = (string) file_get_contents( $rest_file );
			$tokens_ok =
				strpos( $src, "'/me/entitlement'" ) !== false
				&& strpos( $src, 'handle_me_entitlement' ) !== false
				&& strpos( $src, 'permission_bearer' ) !== false;
			$ctx->emit_step( array(
				'label'  => 'Layer 1 · Disk · route tokens',
				'status' => $tokens_ok ? 'pass' : 'fail',
				'detail' => $tokens_ok ? 'Found route + handler markers.' : 'Missing one or more route markers.',
			) );
			if ( ! $tokens_ok ) {
				$failures[] = 'disk_tokens_missing';
			}
		}

		/* -----------------------------------------------------------------
		 * Layer 2 — Loader
		 * ----------------------------------------------------------------- */
		$rest_loaded    = class_exists( 'BizCity_Router_License_REST' );
		$service_loaded = class_exists( 'BizCity_Router_License_Service' );
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
		$ent_methods = $this->collect_route_methods( $routes, '/bizcity-license/v1/me/entitlement' );
		$route_ok = in_array( 'GET', $ent_methods, true );
		$ctx->emit_step( array(
			'label'  => 'Layer 2 · Loader · route',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => 'methods=' . ( empty( $ent_methods ) ? 'none' : implode( ',', $ent_methods ) ),
		) );
		if ( ! $route_ok ) {
			$failures[] = 'loader_route_missing';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'License Hub Disk/Loader failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Verify license class loading and route registration in bizcity-llm-router.',
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

		$req = new WP_REST_Request( 'GET', '/bizcity-license/v1/me/entitlement' );
		$req->set_param( 'client_id', 'diag_client_license' );
		$req->set_param( 'user_id', $uid );
		$res = rest_do_request( $req );
		$data = ( $res instanceof WP_REST_Response ) ? $res->get_data() : array();

		$runtime_ok = is_array( $data )
			&& array_key_exists( 'success', $data )
			&& array_key_exists( 'plan_code', $data )
			&& array_key_exists( 'features', $data );
		$ctx->emit_step( array(
			'label'  => 'Layer 3 · Runtime · GET /me/entitlement',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_ok ? 'Entitlement payload shape is valid.' : 'Entitlement payload shape invalid.',
		) );
		if ( ! $runtime_ok ) {
			$failures[] = 'runtime_entitlement_failed';
		}

		if ( ! empty( $failures ) ) {
			return array(
				'status'   => 'fail',
				'summary'  => 'License Hub runtime failed: ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Check permission/auth path and payload normalization in license REST handler.',
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => 'License Hub entitlement probe passed across Disk/Loader/Runtime.',
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
		// No-op.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_License_Hub_Entitlement_Issue';
	return $list;
} );
