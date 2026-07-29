<?php
/**
 * BizCity Diagnostics - Twin GPT commerce capacity probe.
 *
 * Sprint 10 SB-3 DDV contract:
 * - Disk: TwinWeb REST has /admin/commerce route + admin_get_commerce handler.
 * - Loader: /bizcity-twinweb/v1/admin/commerce route registered (GET).
 * - Runtime: payload has seat_limit/seat_used/seat_remaining/over_capacity and
 *   returns 200 with _degraded flag (no hard fail) when limit is unavailable.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17
 */

// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 - DDV probe for Twin GPT Commerce/Woo capacity dashboard.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Commerce_Capacity', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Commerce_Capacity implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.commerce_capacity'; }
	public function label(): string { return 'Twin GPT Commerce Capacity (/admin/commerce)'; }
	public function description(): string {
		return 'Validates seat capacity + Woo projection queue contract for Control Plane Commerce tab.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 85; }
	public function icon(): string { return 'ShoppingCart'; }
	public function estimate_ms(): int { return 140; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		/* Layer 1 - Disk */
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 - resolve active plugin path deterministically to avoid stale-slug false negatives.
		$rest_file = $this->resolve_file_path( 'modules/twinweb/includes/class-twinweb-rest.php' );
		$disk_readable = $rest_file !== '' && is_readable( $rest_file );
		$has_handler   = false;
		$has_route     = false;
		if ( $disk_readable ) {
			$src = file_get_contents( $rest_file );
			if ( $src !== false ) {
				$has_handler = strpos( $src, 'admin_get_commerce' ) !== false;
				$has_route   = strpos( $src, '/admin/commerce' ) !== false;
			}
		}
		$disk_ok = $disk_readable && $has_handler && $has_route;
		$step = array(
			'label'  => 'Disk - TwinWeb REST commerce markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_readable
				? sprintf( 'file=%s handler=%s route=%s', $rest_file, $has_handler ? 'ok' : 'MISSING', $has_route ? 'ok' : 'MISSING' )
				: 'class-twinweb-rest.php not readable from resolved candidates',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) {
			$pass = false;
		}

		/* Layer 2 - Loader */
		$class_file = '';
		if ( class_exists( 'BizCity_TwinWeb_REST' ) ) {
			try {
				$ref = new ReflectionClass( 'BizCity_TwinWeb_REST' );
				$class_file = (string) $ref->getFileName();
			} catch ( Exception $e ) {
				$class_file = '';
			}
		}
		$loader_ok = method_exists( 'BizCity_TwinWeb_REST', 'admin_get_commerce' );
		$step = array(
			'label'  => 'Loader - TwinWeb admin_get_commerce() method',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok
				? sprintf( 'method loaded (class_file=%s)', $class_file !== '' ? $class_file : 'unknown' )
				: sprintf( 'method missing at runtime (class_exists=%s class_file=%s)', class_exists( 'BizCity_TwinWeb_REST' ) ? '1' : '0', $class_file !== '' ? $class_file : 'unknown' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) {
			$pass = false;
		}

		$routes   = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/commerce', 'GET' );
		$step = array(
			'label'  => 'Loader - REST route /bizcity-twinweb/v1/admin/commerce GET',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'route registered' : 'route missing in rest_get_server()->get_routes()',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) {
			$pass = false;
		}

		/* Layer 3 - Runtime */
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			$step = array(
				'label'  => 'Runtime - admin commerce payload contract',
				'status' => 'skip',
				'detail' => 'No logged-in admin user context; runtime check skipped.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		} else {
			$request = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/commerce' );
			$request->set_query_params( array( 'limit' => 5 ) );
			$response = rest_do_request( $request );
			$status = is_wp_error( $response ) ? 500 : (int) $response->get_status();
			$data = is_wp_error( $response ) ? array() : (array) $response->get_data();

			$cap = isset( $data['capacity'] ) && is_array( $data['capacity'] ) ? $data['capacity'] : array();
			$has_shape = array_key_exists( 'seat_limit', $cap )
				&& array_key_exists( 'seat_used', $cap )
				&& array_key_exists( 'seat_remaining', $cap )
				&& array_key_exists( 'over_capacity', $cap );
			$has_queue = isset( $data['queue'] ) && is_array( $data['queue'] )
				&& isset( $data['queue']['summary'] ) && is_array( $data['queue']['summary'] )
				&& isset( $data['queue']['items'] ) && is_array( $data['queue']['items'] );
			$has_offers = isset( $data['offers'] ) && is_array( $data['offers'] );

			$runtime_ok = $status === 200 && $has_shape && $has_queue && $has_offers;

			$detail_parts = array(
				'http=' . $status,
				'seat_limit=' . ( $cap['seat_limit'] === null ? 'null' : (string) (int) $cap['seat_limit'] ),
				'seat_used=' . (string) (int) ( $cap['seat_used'] ?? -1 ),
				'over_capacity=' . ( ! empty( $cap['over_capacity'] ) ? '1' : '0' ),
				'_degraded=' . ( ! empty( $data['_degraded'] ) ? '1' : '0' ),
			);

			$step = array(
				'label'  => 'Runtime - admin commerce payload contract',
				'status' => $runtime_ok ? 'pass' : 'fail',
				'detail' => implode( ' ', $detail_parts ),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			if ( ! $runtime_ok ) {
				$pass = false;
			}
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Commerce capacity contract is wired and returns seat/offer/queue payload.'
				: 'Commerce capacity contract failed one or more Disk/Loader/Runtime checks.',
			'error'    => $pass ? '' : 'twinweb_commerce_capacity_contract_failed',
			'fix_hint' => $pass ? '' : 'Check /admin/commerce route registration and payload shape in class-twinweb-rest.php.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}

	/**
	 * Check whether a REST route has a concrete method enabled.
	 *
	 * @param array  $routes REST route map.
	 * @param string $route  Route key.
	 * @param string $method HTTP method.
	 * @return bool
	 */
	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) ) {
				if ( false !== strpos( strtoupper( (string) $ep['methods'] ), $want ) ) {
					return true;
				}
				continue;
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
	 * Resolve a file path from multiple plugin-root candidates.
	 *
	 * @param string $relative_path Plugin-relative path.
	 * @return string
	 */
	private function resolve_file_path( $relative_path ) {
		$relative_path = ltrim( (string) $relative_path, '/\\' );
		$candidates    = array();

		if ( defined( 'BIZCITY_TWIN_AI_DIR' ) ) {
			$candidates[] = rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/' . $relative_path;
		}

		$plugin_root_from_probe = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$candidates[]           = rtrim( (string) $plugin_root_from_probe, '/\\' ) . '/' . $relative_path;

		if ( defined( 'WP_PLUGIN_DIR' ) && defined( 'BIZCITY_TWIN_AI_SLUG' ) ) {
			$candidates[] = rtrim( (string) WP_PLUGIN_DIR, '/\\' ) . '/' . trim( (string) BIZCITY_TWIN_AI_SLUG, '/\\' ) . '/' . $relative_path;
		}

		foreach ( $candidates as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}

		return '';
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Commerce_Capacity';
	return $list;
} );
