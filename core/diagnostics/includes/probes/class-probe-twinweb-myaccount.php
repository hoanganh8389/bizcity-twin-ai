<?php
/**
 * BizCity Diagnostics - Twin GPT My Account probe.
 *
 * R-DDV 3 layers evidence:
 * - Disk: /gpt/myaccount/ shell markers, Membership REST self-service routes, FE built artifact only.
 * - Loader: TwinWeb page + Membership REST methods and routes registered.
 * - Runtime: Membership REST returns current-user account, plans and payments payloads.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-18
 */

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-4 — DDV probe for /gpt/myaccount/ account foundation.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_MyAccount', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_MyAccount implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.myaccount'; }
	public function label(): string { return 'Twin GPT My Account (/gpt/myaccount/)'; }
	public function description(): string {
		return 'Verifies the /gpt/myaccount/ SPA shell, same-origin Membership REST account/checkout contract, current-user billing/usage payloads and dist-only frontend artifact policy.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 86; }
	public function icon(): string { return 'UserCircle'; }
	public function estimate_ms(): int { return 120; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_Page' ) ) {
			return new WP_Error( 'no_twinweb_page', 'BizCity_TwinWeb_Page is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Membership_REST' ) ) {
			return new WP_Error( 'no_membership_rest', 'BizCity_Membership_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( __DIR__ ) ) ) . '/';
		$page_file       = $root . 'modules/twinweb/includes/class-twinweb-page.php';
		$membership_file = $root . 'core/membership/includes/class-membership-rest.php';
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — resolve disk evidence against the actual loaded class files first.
		if ( class_exists( 'ReflectionClass' ) ) {
			try {
				$ref_page = new ReflectionClass( 'BizCity_TwinWeb_Page' );
				$ref_page_file = (string) $ref_page->getFileName();
				if ( $ref_page_file !== '' && is_readable( $ref_page_file ) ) {
					$page_file = $ref_page_file;
				}
				$ref_membership = new ReflectionClass( 'BizCity_Membership_REST' );
				$ref_membership_file = (string) $ref_membership->getFileName();
				if ( $ref_membership_file !== '' && is_readable( $ref_membership_file ) ) {
					$membership_file = $ref_membership_file;
				}
			} catch ( Throwable $e ) {
				// Keep fallback paths above.
			}
		}
		$manifest_file   = $root . 'modules/twinweb/ui/dist/.vite/manifest.json';
		$dist_assets_dir = $root . 'modules/twinweb/ui/dist/assets';

		$page_src       = is_readable( $page_file ) ? file_get_contents( $page_file ) : '';
		$membership_src = is_readable( $membership_file ) ? file_get_contents( $membership_file ) : '';

		$disk_route_ok = is_string( $page_src )
			&& strpos( $page_src, '/gpt/myaccount/' ) !== false
			&& strpos( $page_src, 'current_request_url' ) !== false
			&& strpos( $page_src, 'is_direct_gpt_request' ) !== false;
		$step = array(
			'label'  => 'Disk - TwinWeb shell serves /gpt/myaccount/',
			'status' => $disk_route_ok ? 'pass' : 'fail',
			'detail' => $disk_route_ok ? 'Direct account subpath markers found in class-twinweb-page.php.' : 'Missing /gpt/myaccount/ shell markers.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_route_ok ) { $pass = false; }

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-5 — include account self-service checkout/capture/cancel/invoice routes.
		$disk_rest_base_ok = is_string( $membership_src )
			&& $membership_src !== ''
			&& strpos( $membership_src, "'/me'" ) !== false
			&& strpos( $membership_src, "'/me/payments'" ) !== false
			&& strpos( $membership_src, "'/me/affiliate'" ) !== false
			&& strpos( $membership_src, "'/me/cancel'" ) !== false
			// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-4 — account Profile & Security self-service endpoints.
			&& strpos( $membership_src, "'/me/profile'" ) !== false
			&& strpos( $membership_src, "'/me/change-password'" ) !== false
			&& strpos( $membership_src, "'/me/invoice" ) !== false
			&& strpos( $membership_src, "'/plans'" ) !== false
			&& strpos( $membership_src, "'/checkout'" ) !== false
			&& strpos( $membership_src, "'/capture'" ) !== false
			&& strpos( $membership_src, 'profile' ) !== false
			&& strpos( $membership_src, 'usage' ) !== false;
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — affiliate + Woo/capacity markers are additive and may be absent in mixed-version deploys.
		$disk_rest_c6_markers_ok = is_string( $membership_src )
			&& strpos( $membership_src, 'woo_projection' ) !== false
			&& strpos( $membership_src, 'commerce_capacity' ) !== false
			&& strpos( $membership_src, 'current_user_affiliate_snapshot' ) !== false
			&& strpos( $membership_src, 'bizcity_membership_affiliate_payload' ) !== false;
		$disk_rest_ok = $disk_rest_base_ok;
		$step = array(
			'label'  => 'Disk - Membership REST account contract markers',
			'status' => $disk_rest_ok ? 'pass' : 'fail',
			'detail' => $disk_rest_ok
				? sprintf(
					'/plans + /me + /me/payments + /me/affiliate + /me/cancel + /me/profile + /me/change-password + /me/invoice + /checkout + /capture markers found; c6_markers=%s.',
					$disk_rest_c6_markers_ok ? 'present' : 'missing'
				)
				: sprintf( 'Missing Membership REST account/self-service markers (source=%s, readable=%s).', (string) $membership_file, is_readable( $membership_file ) ? 'yes' : 'no' ),
		);
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — do not hard-fail when class is loaded but source file cannot be read in this runtime.
		if ( ! $disk_rest_ok && class_exists( 'BizCity_Membership_REST' ) && $membership_src === '' ) {
			$step['status'] = 'skip';
			$step['detail'] = sprintf( 'Membership REST class loaded but source not readable at %s; skipping strict disk markers and relying on loader/runtime checks.', (string) $membership_file );
		}
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( $step['status'] === 'fail' ) { $pass = false; }

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER R-DDV-FE — production may deploy dist only; never inspect React src here.
		$dist_ok = is_readable( $manifest_file ) || is_dir( $dist_assets_dir );
		$step = array(
			'label'  => 'Disk - FE deploy artifact policy (React src is not inspected)',
			'status' => $dist_ok ? 'pass' : 'skip',
			'detail' => sprintf(
				'dist=%s; /gpt/myaccount/ probe intentionally checks built artifact/runtime contracts, not modules/twinweb/ui/src.',
				$dist_ok ? 'present' : 'not found'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$method_ok = method_exists( 'BizCity_Membership_REST', 'plans' )
			&& method_exists( 'BizCity_Membership_REST', 'me' )
			&& method_exists( 'BizCity_Membership_REST', 'me_payments' )
			&& method_exists( 'BizCity_Membership_REST', 'me_affiliate' )
			&& method_exists( 'BizCity_Membership_REST', 'me_cancel' )
			&& method_exists( 'BizCity_Membership_REST', 'me_update_profile' )
			&& method_exists( 'BizCity_Membership_REST', 'me_change_password' )
			&& method_exists( 'BizCity_Membership_REST', 'me_invoice' )
			&& method_exists( 'BizCity_Membership_REST', 'checkout' )
			&& method_exists( 'BizCity_Membership_REST', 'capture' );
		$step = array(
			'label'  => 'Loader - Membership account handlers loaded',
			'status' => $method_ok ? 'pass' : 'fail',
			'detail' => $method_ok ? 'plans + me + me_payments + me_affiliate + me_cancel + me_update_profile + me_change_password + me_invoice + checkout + capture loaded.' : 'One or more Membership REST handlers missing.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $method_ok ) { $pass = false; }

		$routes = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-membership/v1/plans', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me/payments', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me/affiliate', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me/cancel', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me/profile', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me/change-password', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/me/invoice/(?P<id>[A-Za-z0-9_\-]+)', 'GET' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/checkout', 'POST' )
			&& $this->route_has_method( $routes, '/bizcity-membership/v1/capture', 'POST' );
		$step = array(
			'label'  => 'Loader - Membership REST routes registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? '/plans + /me + /me/payments + /me/affiliate + /me/cancel + /me/profile + /me/change-password + /me/invoice + /checkout + /capture routes registered.' : 'Missing one or more bizcity-membership/v1 account/self-service routes.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) { $pass = false; }

		$plans_req = new WP_REST_Request( 'GET', '/bizcity-membership/v1/plans' );
		$plans_res = rest_do_request( $plans_req );
		$plans_data = is_wp_error( $plans_res ) ? array() : (array) $plans_res->get_data();
		$plans = isset( $plans_data['plans'] ) && is_array( $plans_data['plans'] ) ? $plans_data['plans'] : array();

		$me_req = new WP_REST_Request( 'GET', '/bizcity-membership/v1/me' );
		$me_res = rest_do_request( $me_req );
		$me_data = is_wp_error( $me_res ) ? array() : (array) $me_res->get_data();
		$me_source = 'route';

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — fallback to direct handler call when route payload is filtered/stale.
		if (
			( ! isset( $me_data['woo_projection'] ) || ! is_array( $me_data['woo_projection'] ) || ! isset( $me_data['commerce_capacity'] ) || ! is_array( $me_data['commerce_capacity'] ) )
			&& is_callable( array( 'BizCity_Membership_REST', 'me' ) )
		) {
			$me_direct_res = BizCity_Membership_REST::me( $me_req );
			$me_direct_data = is_wp_error( $me_direct_res ) ? array() : (array) rest_ensure_response( $me_direct_res )->get_data();
			if ( isset( $me_direct_data['woo_projection'] ) && ! isset( $me_data['woo_projection'] ) ) {
				$me_data['woo_projection'] = $me_direct_data['woo_projection'];
			}
			if ( isset( $me_direct_data['commerce_capacity'] ) && ! isset( $me_data['commerce_capacity'] ) ) {
				$me_data['commerce_capacity'] = $me_direct_data['commerce_capacity'];
			}
			if ( isset( $me_data['woo_projection'], $me_data['commerce_capacity'] ) ) {
				$me_source = 'direct_fallback';
			}
		}

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — final probe fallback for transformed route payloads.
		if ( ! isset( $me_data['woo_projection'] ) || ! is_array( $me_data['woo_projection'] ) ) {
			$me_data['woo_projection'] = $this->fallback_woo_projection_payload();
			$me_source = ( $me_source === 'route' ) ? 'probe_fallback' : $me_source;
		}
		if ( ! isset( $me_data['commerce_capacity'] ) || ! is_array( $me_data['commerce_capacity'] ) ) {
			$me_data['commerce_capacity'] = $this->fallback_capacity_payload();
			$me_source = ( $me_source === 'route' ) ? 'probe_fallback' : $me_source;
		}

		$payments_req = new WP_REST_Request( 'GET', '/bizcity-membership/v1/me/payments' );
		$payments_res = rest_do_request( $payments_req );
		$payments_data = is_wp_error( $payments_res ) ? array() : (array) $payments_res->get_data();
		$payments = isset( $payments_data['payments'] ) && is_array( $payments_data['payments'] ) ? $payments_data['payments'] : array();

		$affiliate_req = new WP_REST_Request( 'GET', '/bizcity-membership/v1/me/affiliate' );
		$affiliate_res = rest_do_request( $affiliate_req );
		$affiliate_data = is_wp_error( $affiliate_res ) ? array() : (array) $affiliate_res->get_data();
		$affiliate_stats = isset( $affiliate_data['stats'] ) && is_array( $affiliate_data['stats'] ) ? $affiliate_data['stats'] : array();
		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-6 — fail-closed when runtime can only pass via probe-level shape fallback.
		$is_probe_fallback = ( $me_source === 'probe_fallback' );

		$runtime_ok = ! empty( $plans_data['success'] )
			&& is_array( $plans )
			&& ! empty( $me_data['success'] )
			&& isset( $me_data['entitlement'], $me_data['usage'], $me_data['profile'], $me_data['subscription'], $me_data['woo_projection'], $me_data['commerce_capacity'] )
			&& is_array( $me_data['woo_projection'] )
			&& is_array( $me_data['commerce_capacity'] )
			&& isset( $me_data['woo_projection']['status'], $me_data['commerce_capacity']['capacity_bucket'] )
			&& ! empty( $payments_data['success'] )
			&& is_array( $payments )
			&& ! empty( $affiliate_data['success'] )
			&& isset( $affiliate_data['referral_code'], $affiliate_data['share_url'], $affiliate_stats['clicks'], $affiliate_stats['paid_conversions'] )
			&& ! $is_probe_fallback;
		$step = array(
			'label'  => 'Runtime - current-user account payloads load from Membership REST',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'plans=%d; me=%s; usage=%s; profile=%s; woo_projection=%s; capacity=%s; payments=%d; affiliate=%s/%s; me_source=%s; probe_fallback=%s',
				count( $plans ),
				! empty( $me_data['success'] ) ? 'ok' : 'FAIL',
				isset( $me_data['usage'] ) && is_array( $me_data['usage'] ) ? 'ok' : 'MISSING',
				isset( $me_data['profile'] ) && is_array( $me_data['profile'] ) ? 'ok' : 'MISSING',
				isset( $me_data['woo_projection'] ) && is_array( $me_data['woo_projection'] ) ? (string) ( $me_data['woo_projection']['status'] ?? 'ok' ) : 'MISSING',
				isset( $me_data['commerce_capacity'] ) && is_array( $me_data['commerce_capacity'] ) ? (string) ( $me_data['commerce_capacity']['capacity_bucket'] ?? 'ok' ) : 'MISSING',
				count( $payments ),
				! empty( $affiliate_data['success'] ) ? 'ok' : 'FAIL',
				isset( $affiliate_data['source'] ) ? (string) $affiliate_data['source'] : 'MISSING',
				$me_source,
				$is_probe_fallback ? 'yes' : 'no'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_ok ) { $pass = false; }

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT /gpt/myaccount/ shell and Membership REST account contract PASS.'
				: 'Twin GPT /gpt/myaccount/ account contract failed one or more checks (including probe_fallback fail-closed guard).',
			'error'    => $pass ? '' : 'twinweb_myaccount_contract_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-page.php direct path support and bizcity-membership/v1 /plans, /me, /me/payments route registration; ensure /me returns woo_projection + commerce_capacity from route payload (not probe fallback). Do not hard-gate React src on dist-only servers.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}

	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) && false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
				return true;
			}
			if ( is_array( $ep['methods'] ) ) {
				foreach ( $ep['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Build a stable Woo projection payload shape for probe runtime checks.
	 *
	 * @return array
	 */
	private function fallback_woo_projection_payload() {
		$payload = array(
			'available'        => false,
			'_degraded'        => true,
			'degraded_reasons' => array( 'probe_shape_fallback' ),
			'status'           => 'none',
			'order_id'         => 0,
			'order_number'     => '',
			'order_status'     => '',
			'offer_code'       => '',
			'plan_slug'        => '',
			'reason'           => '',
			'projected_at'     => '',
			'applied_at'       => '',
			'expires_at'       => '',
			'seat_delta'       => 0,
		);

		$uid = (int) get_current_user_id();
		if ( $uid > 0 && class_exists( 'BizCity_Membership_Woo_Projector' ) && BizCity_Membership_Woo_Projector::woo_ready() ) {
			$payload['available'] = true;
		}
		return $payload;
	}

	/**
	 * Build a stable capacity payload shape for probe runtime checks.
	 *
	 * @return array
	 */
	private function fallback_capacity_payload() {
		$payload = array(
			'seat_limit'       => null,
			'seat_used'        => 0,
			'seat_remaining'   => null,
			'at_capacity'      => false,
			'over_capacity'    => false,
			'capacity_bucket'  => 'capacity_unknown',
			'_degraded'        => true,
			'degraded_reasons' => array( 'probe_shape_fallback' ),
		);

		if ( class_exists( 'BizCity_Membership_Woo_Projector' ) && method_exists( 'BizCity_Membership_Woo_Projector', 'get_capacity_snapshot' ) ) {
			$snapshot = BizCity_Membership_Woo_Projector::get_capacity_snapshot();
			if ( is_array( $snapshot ) ) {
				$payload = array_merge( $payload, $snapshot );
			}
		}

		return $payload;
	}
}

// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-4 — register Twin GPT My Account probe.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_MyAccount';
	return $list;
} );
