<?php
/**
 * BizCity Diagnostics - Twin GPT app catalog probe.
 *
 * Sprint 6 CAP-1 DDV contract:
 * - Disk: TwinWeb REST file readable + get_apps_effective marker exists.
 * - Loader: /bizcity-twinweb/v1/apps/effective route is registered.
 * - Runtime:
 *   - guest => chat=available, workflow=admin_only
 *   - subscriber simulation => workflow=locked and no admin href leakage
 *   - iframe apps => parent href stays under /gpt/{app}/ and iframe_href targets legacy workspace
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17
 */

// [2026-07-17 Johnny Chu] PHASE-TWINWEB CAP-1 - DDV probe for apps/effective server catalog.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_App_Catalog', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_App_Catalog implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.app_catalog'; }
	public function label(): string { return 'Twin GPT App Catalog (/apps/effective)'; }
	public function description(): string {
		return 'Verifies Disk/Loader/Runtime for apps/effective contract and workflow state behavior for guest/subscriber.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 84; }
	public function icon(): string { return 'LayoutGrid'; }
	public function estimate_ms(): int { return 120; }

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
		$rest_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'modules/twinweb/includes/class-twinweb-rest.php'
			: '';
		$disk_readable = $rest_file !== '' && is_readable( $rest_file );
		$has_handler   = false;
		$has_route     = false;
		if ( $disk_readable ) {
			$src = file_get_contents( $rest_file );
			if ( $src !== false ) {
				$has_handler = strpos( $src, 'function get_apps_effective' ) !== false;
				$has_route   = strpos( $src, "'/apps/effective'" ) !== false;
			}
		}
		$disk_ok = $disk_readable && $has_handler && $has_route;
		$step = array(
			'label'  => 'Disk - TwinWeb REST file + apps/effective markers',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_readable
				? sprintf( 'handler=%s route=%s', $has_handler ? 'ok' : 'MISSING', $has_route ? 'ok' : 'MISSING' )
				: 'class-twinweb-rest.php not readable',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_ok ) {
			$pass = false;
		}

		/* Layer 2 - Loader */
		$loader_ok = method_exists( 'BizCity_TwinWeb_REST', 'get_apps_effective' );
		$step = array(
			'label'  => 'Loader - TwinWeb class method get_apps_effective()',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'method loaded' : 'method missing at runtime',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_ok ) {
			$pass = false;
		}

		$routes   = rest_get_server()->get_routes();
		$route_ok = $this->route_has_method( $routes, '/bizcity-twinweb/v1/apps/effective', 'GET' );
		$step = array(
			'label'  => 'Loader - REST route /bizcity-twinweb/v1/apps/effective GET',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => $route_ok ? 'route registered' : 'route missing in rest_get_server()->get_routes()',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) {
			$pass = false;
		}

		/* Layer 3 - Runtime (guest) */
		$original_uid = get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$guest_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/apps/effective' );
			$guest_res  = rest_do_request( $guest_req );
			$guest_data = is_wp_error( $guest_res ) ? array() : (array) $guest_res->get_data();
			$guest_apps = isset( $guest_data['apps'] ) && is_array( $guest_data['apps'] ) ? $guest_data['apps'] : array();

			$chat_app     = $this->find_app( $guest_apps, 'chat' );
			$workflow_app = $this->find_app( $guest_apps, 'workflow' );
			$guest_ok     = is_array( $chat_app )
				&& is_array( $workflow_app )
				&& (string) ( $chat_app['state'] ?? '' ) === 'available'
				&& (string) ( $workflow_app['state'] ?? '' ) === 'admin_only';

			$step = array(
				'label'  => 'Runtime - guest states (chat=available, workflow=admin_only)',
				'status' => $guest_ok ? 'pass' : 'fail',
				'detail' => sprintf(
					'chat=%s; workflow=%s',
					is_array( $chat_app ) ? (string) ( $chat_app['state'] ?? 'MISSING' ) : 'MISSING',
					is_array( $workflow_app ) ? (string) ( $workflow_app['state'] ?? 'MISSING' ) : 'MISSING'
				),
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $guest_ok ) {
				$pass = false;
			}

			// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — catch regressions where iframe shortcuts leave /gpt/ in the parent URL.
			$deeplink_ok = $this->apps_have_gpt_deeplinks( $guest_apps );
			$step = array(
				'label'  => 'Runtime - iframe app deeplinks stay under /gpt/{app}/',
				'status' => $deeplink_ok ? 'pass' : 'fail',
				'detail' => $deeplink_ok
					? 'available iframe apps expose href=/gpt/{app}/ plus iframe_href legacy target'
					: 'one or more available iframe apps has wrong href/iframe_href contract',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
			if ( ! $deeplink_ok ) {
				$pass = false;
			}
		} finally {
			wp_set_current_user( $original_uid );
		}

		/* Layer 3 - Runtime (subscriber simulation) */
		if ( $original_uid > 0 ) {
			$override_prio = PHP_INT_MAX;
			$cap_filter = function ( $allcaps, $caps, $args, $user ) use ( $original_uid ) {
				if ( $user instanceof WP_User && (int) $user->ID === $original_uid ) {
					$allcaps['manage_options'] = false;
					$allcaps['administrator']  = false;
				}
				return $allcaps;
			};
			$map_cap_filter = function ( $caps, $cap, $uid ) use ( $original_uid ) {
				if ( (int) $uid === $original_uid && $cap === 'manage_options' ) {
					return array( 'do_not_allow' );
				}
				return $caps;
			};
			$tier_filter = function ( $tier, $uid ) use ( $original_uid ) {
				if ( (int) $uid === $original_uid ) {
					return 'free';
				}
				return $tier;
			};

			// [2026-07-17 Johnny Chu] SPRINT-7 DDV-FIX — ensure simulation cannot be overridden by lower-priority role filters.
			add_filter( 'map_meta_cap', $map_cap_filter, $override_prio, 4 );
			add_filter( 'user_has_cap', $cap_filter, $override_prio, 4 );
			add_filter( 'bizcity_twinweb_user_tier', $tier_filter, $override_prio, 2 );
			try {
				$sub_req  = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/apps/effective' );
				$sub_res  = rest_do_request( $sub_req );
				$sub_data = is_wp_error( $sub_res ) ? array() : (array) $sub_res->get_data();
				$sub_apps = isset( $sub_data['apps'] ) && is_array( $sub_data['apps'] ) ? $sub_data['apps'] : array();

				$workflow_app = $this->find_app( $sub_apps, 'workflow' );
				$sub_ok       = is_array( $workflow_app )
					&& (string) ( $workflow_app['state'] ?? '' ) === 'locked'
					&& (string) ( $workflow_app['href'] ?? '' ) === '';

				$step = array(
					'label'  => 'Runtime - subscriber simulation workflow=locked and no admin href',
					'status' => $sub_ok ? 'pass' : 'fail',
					'detail' => is_array( $workflow_app )
						? sprintf(
							'state=%s; href=%s',
							(string) ( $workflow_app['state'] ?? 'MISSING' ),
							(string) ( $workflow_app['href'] ?? '' )
						)
						: 'workflow app missing from response',
				);
				$steps[] = $step;
				$ctx->emit_step( $step );
				if ( ! $sub_ok ) {
					$pass = false;
				}
			} finally {
				remove_filter( 'map_meta_cap', $map_cap_filter, $override_prio );
				remove_filter( 'user_has_cap', $cap_filter, $override_prio );
				remove_filter( 'bizcity_twinweb_user_tier', $tier_filter, $override_prio );
			}
		} else {
			$step = array(
				'label'  => 'Runtime - subscriber simulation workflow=locked and no admin href',
				'status' => 'skip',
				'detail' => 'No logged-in user context, skip subscriber simulation.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'apps/effective contract is wired and workflow state rules are enforced.'
				: 'apps/effective contract failed one or more Disk/Loader/Runtime checks.',
			'error'    => $pass ? '' : 'twinweb_app_catalog_contract_failed',
			'fix_hint' => $pass ? '' : 'Check get_apps_effective route registration and state gating in class-twinweb-rest.php.',
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
	 * Find one app row by id.
	 *
	 * @param array  $apps App list.
	 * @param string $id   App id.
	 * @return array|null
	 */
	private function find_app( $apps, $id ) {
		if ( ! is_array( $apps ) ) {
			return null;
		}
		$id = sanitize_key( (string) $id );
		foreach ( $apps as $app ) {
			if ( is_array( $app ) && sanitize_key( (string) ( $app['id'] ?? '' ) ) === $id ) {
				return $app;
			}
		}
		return null;
	}

	private function apps_have_gpt_deeplinks( $apps ) {
		// [2026-07-20 Johnny Chu] PHASE-TWINWEB-DEEPLINK — enforce parent/iframe URL split for shortcut apps.
		$legacy_paths = array(
			'twinchat' => '/twinchat/',
			'astro'    => '/astro/',
			'creator'  => '/creator/',
			'doc'      => '/tool-doc/',
			'image'    => '/tool-image/',
			'profile'  => '/profile-studio/',
		);

		foreach ( $legacy_paths as $id => $legacy_path ) {
			$app = $this->find_app( $apps, $id );
			if ( ! is_array( $app ) || (string) ( $app['state'] ?? '' ) !== 'available' ) {
				continue;
			}

			$href        = (string) ( $app['href'] ?? '' );
			$iframe_href = (string) ( $app['iframe_href'] ?? '' );
			$href_path   = (string) parse_url( $href, PHP_URL_PATH );
			$iframe_path = (string) parse_url( $iframe_href, PHP_URL_PATH );

			if ( '/' . trim( $href_path, '/' ) . '/' !== '/gpt/' . $id . '/' ) {
				return false;
			}
			if ( '/' . trim( $iframe_path, '/' ) . '/' !== $legacy_path ) {
				return false;
			}
		}

		return true;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_App_Catalog';
	return $list;
} );
