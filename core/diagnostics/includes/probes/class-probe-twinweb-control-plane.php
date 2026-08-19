<?php
/**
 * BizCity Diagnostics — Twin GPT control-plane probe.
 *
 * 3-layer R-DDV evidence for Wave 0/2 baseline:
 *   - Disk: control-plane handlers exist in TwinWeb REST controller.
 *   - Loader: TwinWeb class and REST routes are registered.
 *   - Runtime: /config/effective and /admin/access return canonical payloads.
 *
 * [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — diagnostics-first validation
 * path for environments that may not have local PHP CLI.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinWeb_Control_Plane', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Control_Plane implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twinweb.control_plane'; }
	public function label(): string { return 'Twin GPT Control Plane (config/access)'; }
	public function description(): string {
		return 'Verifies Disk/Loader/Runtime contract for Twin GPT control-plane endpoints used by Channel Gateway Access tab and /gpt/ effective config.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 76; }
	public function icon(): string { return 'Shield'; }
	public function estimate_ms(): int { return 120; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) ) {
			return new WP_Error( 'no_twinweb_rest', 'BizCity_TwinWeb_REST chưa load. Kiểm tra modules/twinweb/bootstrap.php.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — add DDV coverage for control-plane contract.
		$steps = array();
		$pass  = true;

		$plugin_root = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$rest_file   = $plugin_root . '/modules/twinweb/includes/class-twinweb-rest.php';

		/* Layer 1 — Disk */
		$disk_file_ok = is_readable( $rest_file );
		$step = array(
			'label'  => 'Disk · TwinWeb REST controller file',
			'status' => $disk_file_ok ? 'pass' : 'fail',
			'detail' => $disk_file_ok ? 'modules/twinweb/includes/class-twinweb-rest.php readable' : 'TwinWeb REST controller file missing',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_file_ok ) {
			$pass = false;
		}

		$disk_contract_ok = false;
		$disk_detail      = 'TwinWeb REST controller unreadable';
		if ( $disk_file_ok ) {
			$src = (string) file_get_contents( $rest_file );
			$markers = array(
				'function register_control_plane_routes',
				'function get_effective_config',
				'function admin_get_access',
				'function admin_put_access',
				// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — accept method token to avoid false-negative on signature formatting.
				'admin_get_usage',
				'function resolve_access_for_identity',
				// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — assert the read-only Guru Studio catalog handler.
				'function admin_get_guru_catalog',
			);
			$missing = array();
			foreach ( $markers as $marker ) {
				if ( strpos( $src, $marker ) === false ) {
					$missing[] = $marker;
				}
			}
			$disk_contract_ok = empty( $missing );
			$disk_detail      = $disk_contract_ok
				? 'control-plane handlers found in controller source'
				: 'missing markers: ' . implode( ', ', $missing );
		}
		$step = array(
			'label'  => 'Disk · control-plane handler markers',
			'status' => $disk_contract_ok ? 'pass' : 'fail',
			'detail' => $disk_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_contract_ok ) {
			$pass = false;
		}

		/* Layer 2 — Loader */
		$loader_class_ok = class_exists( 'BizCity_TwinWeb_REST' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'register_control_plane_routes' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_effective_config' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_access' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_usage' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_put_access' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'admin_get_guru_catalog' );
		$step = array(
			'label'  => 'Loader · TwinWeb class contract',
			'status' => $loader_class_ok ? 'pass' : 'fail',
			'detail' => $loader_class_ok ? 'class + methods loaded' : 'class/method contract missing in runtime',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_class_ok ) {
			$pass = false;
		}

		$routes          = rest_get_server()->get_routes();
		$route_cfg_ok    = $this->route_has_method( $routes, '/bizcity-twinweb/v1/config/effective', 'GET' );
		$route_access_get = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/access', 'GET' );
		$route_access_put = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/access', 'PUT' );
		$route_usage_get  = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/usage', 'GET' );
		// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — assert the canonical read-only catalog route is registered.
		$route_guru_get  = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/guru-catalog', 'GET' );
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — assert focused per-Guru vertical policy CRUD routes.
		$route_policy_get = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/guru-policy/(?P<guru_id>\\d+)', 'GET' );
		$route_policy_put = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/guru-policy/(?P<guru_id>\\d+)', 'PUT' );
		// [2026-08-14 Johnny Chu] PHASE-TWB-GURU-POLICY — preview route must remain separate from mutation and execution.
		$route_policy_preview = $this->route_has_method( $routes, '/bizcity-twinweb/v1/admin/guru-policy/preview', 'POST' );
		$route_ok        = $route_cfg_ok && $route_access_get && $route_access_put && $route_usage_get && $route_guru_get && $route_policy_get && $route_policy_put && $route_policy_preview;
		$step = array(
			'label'  => 'Loader · REST route registration',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'/config/effective.GET=%s · /admin/access.GET=%s · /admin/access.PUT=%s · /admin/usage.GET=%s · /admin/guru-catalog.GET=%s · /admin/guru-policy.GET=%s · /admin/guru-policy.PUT=%s · /admin/guru-policy/preview.POST=%s',
				$route_cfg_ok ? 'ok' : 'missing',
				$route_access_get ? 'ok' : 'missing',
				$route_access_put ? 'ok' : 'missing',
				$route_usage_get ? 'ok' : 'missing',
				$route_guru_get ? 'ok' : 'missing',
				$route_policy_get ? 'ok' : 'missing',
				$route_policy_put ? 'ok' : 'missing',
				$route_policy_preview ? 'ok' : 'missing'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) {
			$pass = false;
		}

		/* Layer 3 — Runtime */
		$cfg_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/config/effective' );
		$cfg_res  = rest_do_request( $cfg_req );
		$cfg_data = $cfg_res->get_data();
		$cfg_ok   = is_array( $cfg_data )
			&& isset( $cfg_data['product_name'], $cfg_data['surface'], $cfg_data['modes'], $cfg_data['default_mode'], $cfg_data['access'] )
			&& is_array( $cfg_data['access'] )
			&& array_key_exists( 'allowed', $cfg_data['access'] )
			&& array_key_exists( 'tier', $cfg_data['access'] );
		$step = array(
			'label'  => 'Runtime · GET /config/effective payload',
			'status' => $cfg_ok ? 'pass' : 'fail',
			'detail' => $cfg_ok
				? sprintf(
					'product=%s · surface=%s · modes=%d · access.allowed=%s · tier=%s',
					(string) $cfg_data['product_name'],
					(string) $cfg_data['surface'],
					is_array( $cfg_data['modes'] ) ? count( $cfg_data['modes'] ) : 0,
					! empty( $cfg_data['access']['allowed'] ) ? 'true' : 'false',
					isset( $cfg_data['access']['tier'] ) ? (string) $cfg_data['access']['tier'] : ''
				)
				: 'payload contract missing keys for effective config',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $cfg_ok ) {
			$pass = false;
		}

		if ( current_user_can( 'manage_options' ) ) {
			$access_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/access' );
			$access_res  = rest_do_request( $access_req );
			$access_data = $access_res->get_data();
			$access_ok   = is_array( $access_data )
				&& ! empty( $access_data['success'] )
				&& isset( $access_data['policy'] )
				&& is_array( $access_data['policy'] )
				&& isset( $access_data['policy']['guest'], $access_data['policy']['member'], $access_data['policy']['users'], $access_data['policy']['plan_matrix'] );
			$step = array(
				'label'  => 'Runtime · GET /admin/access payload',
				'status' => $access_ok ? 'pass' : 'fail',
				'detail' => $access_ok
					? sprintf(
						'policy keys ok · plans=%d · matrix_rows=%d',
						isset( $access_data['plans'] ) && is_array( $access_data['plans'] ) ? count( $access_data['plans'] ) : 0,
						is_array( $access_data['policy']['plan_matrix'] ) ? count( $access_data['policy']['plan_matrix'] ) : 0
					)
					: 'admin/access contract missing success/policy/plan_matrix',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $access_ok ) {
				$pass = false;
			}

			// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — validate catalog payload, tenant metadata and explicit pending policy boundary.
			$guru_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/guru-catalog' );
			$guru_res  = rest_do_request( $guru_req );
			$guru_data = $guru_res->get_data();
			$guru_ok   = is_array( $guru_data )
				&& ! empty( $guru_data['success'] )
				&& isset( $guru_data['blog_id'], $guru_data['cp_ver'], $guru_data['updated_at'], $guru_data['gurus'], $guru_data['bindings'], $guru_data['modes'], $guru_data['access_summary'], $guru_data['grounding_summary'], $guru_data['policy_contract'] )
				&& is_array( $guru_data['gurus'] )
				&& is_array( $guru_data['bindings'] )
				&& is_array( $guru_data['modes'] )
				&& is_array( $guru_data['policy_contract'] )
				&& ( $guru_data['policy_contract']['guru_vertical_acl'] ?? '' ) === 'pending';
			// [2026-08-13 Johnny Chu] PHASE-TWIN-GURU-UI — non-admin diagnostics must report the protected route as SKIP.
			$step = array(
				'label'  => 'Runtime · GET /admin/guru-catalog payload',
				'status' => $guru_ok ? 'pass' : 'fail',
				'detail' => $guru_ok
					? sprintf( 'blog_id=%d · cp_ver=%s · gurus=%d · bindings=%d · modes=%d · policy contract explicit pending', (int) $guru_data['blog_id'], (string) $guru_data['cp_ver'], count( $guru_data['gurus'] ), count( $guru_data['bindings'] ), count( $guru_data['modes'] ) )
					: 'guru-catalog contract missing success/catalog/policy keys',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $guru_ok ) {
				$pass = false;
			}

			$usage_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/admin/usage' );
			$usage_res  = rest_do_request( $usage_req );
			$usage_data = $usage_res->get_data();
			$usage_ok   = is_array( $usage_data )
				&& ! empty( $usage_data['success'] )
				&& isset( $usage_data['summary'] )
				&& is_array( $usage_data['summary'] )
				&& isset( $usage_data['services'] )
				&& is_array( $usage_data['services'] )
				&& isset( $usage_data['top_users'] )
				&& is_array( $usage_data['top_users'] );
			$step = array(
				'label'  => 'Runtime · GET /admin/usage payload',
				'status' => $usage_ok ? 'pass' : 'fail',
				'detail' => $usage_ok
					? sprintf(
						'summary ok · services=%d · top_users=%d',
						count( $usage_data['services'] ),
						count( $usage_data['top_users'] )
					)
					: 'admin/usage contract missing summary/services/top_users',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $usage_ok ) {
				$pass = false;
			}
		} else {
			$step = array(
				'label'  => 'Runtime · GET /admin/access payload',
				'status' => 'skip',
				'detail' => 'Skipped because current session is not manage_options.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			$step = array(
				'label'  => 'Runtime · GET /admin/guru-catalog payload',
				'status' => 'skip',
				'detail' => 'Skipped because current session is not manage_options.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );

			$step = array(
				'label'  => 'Runtime · GET /admin/usage payload',
				'status' => 'skip',
				'detail' => 'Skipped because current session is not manage_options.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'Twin GPT control-plane endpoints are wired and returning canonical payloads.'
				: 'Twin GPT control-plane DDV found missing Disk/Loader/Runtime evidence.',
			'error'    => $pass ? '' : 'twinweb_control_plane_contract_failed',
			'fix_hint' => $pass ? '' : 'Check modules/twinweb REST load order and route registration in diagnostics runtime.',
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
		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP W2 — support both string and map forms of endpoint methods.
		foreach ( $routes[ $route ] as $ep ) {
			if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
				continue;
			}
			if ( is_string( $ep['methods'] ) ) {
				$methods_str = strtoupper( (string) $ep['methods'] );
				if ( false !== strpos( $methods_str, $want ) ) {
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
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Control_Plane';
	return $list;
} );
