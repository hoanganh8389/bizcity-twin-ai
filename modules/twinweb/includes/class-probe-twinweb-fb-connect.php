<?php
/**
 * BizCity Diagnostics - modules.twinweb.fb_connect probe.
 *
 * R-DDV: deep server-side evidence for TwinWeb Facebook member connect flow
 * with user_id-first isolation.
 *
 * DDV rows:
 *   twinweb.fb_connect.disk          - facebook REST source + user_id markers present
 *   twinweb.fb_connect.loader        - routes + handler methods loaded
 *   twinweb.fb_connect.runtime_auth  - anonymous request is denied (401/403)
 *   twinweb.fb_connect.runtime_scope - response items are scoped to current user_id only
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since      2026-07-15 (PHASE-TWINWEB)
 */

// [2026-07-15 Johnny Chu] PHASE-TWINWEB - DDV probe for Facebook member connect user_id isolation.
defined( 'ABSPATH' ) || exit;

$bizcity_twinweb_plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
	? BIZCITY_TWIN_AI_DIR
	: dirname( __DIR__, 3 ) . '/';
$bizcity_twinweb_probe_iface = $bizcity_twinweb_plugin_root . 'core/diagnostics/includes/interface-diagnostics-probe.php';
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) && is_readable( $bizcity_twinweb_probe_iface ) ) {
	require_once $bizcity_twinweb_probe_iface;
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_TwinWeb_FB_Connect', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_FB_Connect implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'modules.twinweb.fb_connect'; }
	public function label(): string       { return 'TwinWeb - Facebook Connect (user_id scope)'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: member routes /facebook/user-oauth-start + /facebook/user-pages enforce logged-in permission and user_id-only page scope.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 83; }
	public function icon(): string        { return 'facebook'; }
	public function estimate_ms(): int    { return 60; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		$steps = array();
		$pass  = true;

		$plugin_root = defined( 'BIZCITY_TWIN_AI_DIR' )
			? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' )
			: dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		$fb_rest_file = $plugin_root . '/core/channel-gateway/includes/adapters/class-facebook-page-rest.php';

		// Disk - source readable + user_id markers.
		$disk_readable = is_readable( $fb_rest_file );
		$marker_ok     = false;
		$marker_detail = 'facebook REST file unreadable.';
		if ( $disk_readable ) {
			$src = (string) file_get_contents( $fb_rest_file );
			$markers = array(
				'/facebook/user-oauth-start',
				'/facebook/user-pages',
				'function list_user_pages',
				'function delete_user_page',
				'WHERE user_id = %d',
			);
			$missing = array();
			foreach ( $markers as $marker ) {
				if ( false === strpos( $src, $marker ) ) {
					$missing[] = $marker;
				}
			}
			$marker_ok = empty( $missing );
			$marker_detail = $marker_ok
				? 'Routes + handlers + SQL user_id marker found.'
				: 'Missing markers: ' . implode( ', ', $missing );
		}

		$step = array(
			'label'  => 'Disk - class-facebook-page-rest.php user_id markers',
			'status' => ( $disk_readable && $marker_ok ) ? 'pass' : 'fail',
			'detail' => $disk_readable ? $marker_detail : 'Missing file: ' . $fb_rest_file,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $disk_readable || ! $marker_ok ) {
			$pass = false;
		}

		// Loader - class/methods + routes.
		$loader_methods_ok = class_exists( 'BizCity_Facebook_Page_REST' )
			&& method_exists( 'BizCity_Facebook_Page_REST', 'perm_logged_in' )
			&& method_exists( 'BizCity_Facebook_Page_REST', 'user_oauth_start' )
			&& method_exists( 'BizCity_Facebook_Page_REST', 'list_user_pages' )
			&& method_exists( 'BizCity_Facebook_Page_REST', 'delete_user_page' );
		$step = array(
			'label'  => 'Loader - BizCity_Facebook_Page_REST member methods',
			'status' => $loader_methods_ok ? 'pass' : 'fail',
			'detail' => $loader_methods_ok ? 'perm_logged_in + member handlers loaded.' : 'Class/method contract missing in runtime.',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $loader_methods_ok ) {
			$pass = false;
		}

		$routes       = rest_get_server()->get_routes();
		$route_oauth  = $this->route_has_method( $routes, '/bizcity-channel/v1/facebook/user-oauth-start', 'POST' );
		$route_pages  = $this->route_has_method( $routes, '/bizcity-channel/v1/facebook/user-pages', 'GET' );
		$route_delete = $this->route_has_method( $routes, '/bizcity-channel/v1/facebook/user-pages/(?P<page_id>[A-Za-z0-9_-]+)', 'DELETE' );
		$route_ok     = $route_oauth && $route_pages && $route_delete;
		$step = array(
			'label'  => 'Loader - member REST routes registered',
			'status' => $route_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'user-oauth-start.POST=%s ; user-pages.GET=%s ; user-pages/{id}.DELETE=%s',
				$route_oauth ? 'ok' : 'missing',
				$route_pages ? 'ok' : 'missing',
				$route_delete ? 'ok' : 'missing'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $route_ok ) {
			$pass = false;
		}

		// Runtime - auth gate: anonymous must be denied.
		$runtime_auth_ok     = false;
		$runtime_auth_detail = 'Skipped: WP user context unavailable.';
		$original_user_id    = (int) get_current_user_id();
		if ( function_exists( 'wp_set_current_user' ) ) {
			try {
				wp_set_current_user( 0 );
				$anon_req = new WP_REST_Request( 'GET', '/bizcity-channel/v1/facebook/user-pages' );
				$anon_res = rest_do_request( $anon_req );
				$anon_status = (int) $anon_res->get_status();
				$runtime_auth_ok = in_array( $anon_status, array( 401, 403 ), true );
				$runtime_auth_detail = $runtime_auth_ok
					? 'Anonymous request blocked with HTTP ' . $anon_status . '.'
					: 'Anonymous request returned HTTP ' . $anon_status . ' (expected 401/403).';
			} catch ( Exception $e ) {
				$runtime_auth_detail = 'Exception while testing anonymous gate: ' . $e->getMessage();
			} finally {
				wp_set_current_user( $original_user_id );
			}
		}
		$step = array(
			'label'  => 'Runtime - anonymous access denied on /facebook/user-pages',
			'status' => $runtime_auth_ok ? 'pass' : 'fail',
			'detail' => $runtime_auth_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $runtime_auth_ok ) {
			$pass = false;
		}

		// Runtime - scope gate: returned page_ids must belong to current user_id only.
		$runtime_scope_status = 'skip';
		$runtime_scope_detail = 'Skipped: no logged-in user in diagnostics session.';
		$current_user_id      = (int) get_current_user_id();
		if ( $current_user_id > 0 ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			if ( ! $this->has_table_column( $table, 'user_id' ) ) {
				$runtime_scope_status = 'fail';
				$runtime_scope_detail = 'Table bizcity_facebook_bots has no user_id column.';
				$pass = false;
			} else {
				$wpdb->suppress_errors( true );
				$allowed_rows = (array) $wpdb->get_col(
					$wpdb->prepare(
						"SELECT DISTINCT page_id FROM {$table}
						 WHERE user_id = %d AND status = 'active' AND page_id IS NOT NULL AND page_id != ''",
						$current_user_id
					)
				);
				$wpdb->suppress_errors( false );
				$allowed = array();
				foreach ( $allowed_rows as $pid ) {
					$allowed[ (string) $pid ] = true;
				}

				try {
					$scope_req  = new WP_REST_Request( 'GET', '/bizcity-channel/v1/facebook/user-pages' );
					$scope_res  = rest_do_request( $scope_req );
					$scope_data = $scope_res->get_data();
					$items      = is_array( $scope_data ) && isset( $scope_data['items'] ) && is_array( $scope_data['items'] )
						? $scope_data['items']
						: array();

					$leaked = array();
					foreach ( $items as $row ) {
						$page_id = is_array( $row ) ? (string) ( $row['page_id'] ?? '' ) : '';
						if ( $page_id === '' ) {
							continue;
						}
						if ( ! isset( $allowed[ $page_id ] ) ) {
							$leaked[] = $page_id;
						}
					}

					if ( empty( $leaked ) ) {
						$runtime_scope_status = 'pass';
						$runtime_scope_detail = sprintf(
							'user_id=%d scoped OK. Returned=%d; allowed_by_db=%d.',
							$current_user_id,
							count( $items ),
							count( $allowed )
						);
					} else {
						$runtime_scope_status = 'fail';
						$runtime_scope_detail = 'Cross-user leak page_id(s): ' . implode( ', ', $leaked );
						$pass = false;
					}
				} catch ( Exception $e ) {
					$runtime_scope_status = 'fail';
					$runtime_scope_detail = 'Exception while validating user_id scope: ' . $e->getMessage();
					$pass = false;
				}
			}
		}

		$step = array(
			'label'  => 'Runtime - /facebook/user-pages returns only current user_id pages',
			'status' => $runtime_scope_status,
			'detail' => $runtime_scope_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass
				? 'TwinWeb Facebook member connect enforces authenticated + user_id-only scope on server side.'
				: 'TwinWeb Facebook member connect user_id isolation check failed.',
			'error'    => $pass ? '' : 'twinweb_fb_connect_user_scope_failed',
			'fix_hint' => $pass ? '' : 'Verify /facebook/user-pages SQL filter uses user_id and permission callback requires logged-in user.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe; no cleanup needed.
	}

	/**
	 * Check whether a route has the HTTP method.
	 *
	 * @param array  $routes Routes map.
	 * @param string $route  Route key.
	 * @param string $method HTTP method.
	 * @return bool
	 */
	private function route_has_method( $routes, $route, $method ) {
		if ( ! isset( $routes[ $route ] ) || ! is_array( $routes[ $route ] ) ) {
			return false;
		}
		$want = strtoupper( (string) $method );
		foreach ( $routes[ $route ] as $endpoint ) {
			if ( ! is_array( $endpoint ) || empty( $endpoint['methods'] ) ) {
				continue;
			}
			if ( is_string( $endpoint['methods'] ) ) {
				if ( false !== strpos( strtoupper( (string) $endpoint['methods'] ), $want ) ) {
					return true;
				}
				continue;
			}
			if ( is_array( $endpoint['methods'] ) ) {
				foreach ( $endpoint['methods'] as $registered => $enabled ) {
					if ( $enabled && strtoupper( (string) $registered ) === $want ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Check if table has column via information_schema.
	 *
	 * @param string $table_name Table name.
	 * @param string $column_name Column name.
	 * @return bool
	 */
	private function has_table_column( $table_name, $column_name ) {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME = %s
				   AND COLUMN_NAME = %s
				 LIMIT 1",
				(string) $table_name,
				(string) $column_name
			)
		);
		$wpdb->suppress_errors( false );
		return $exists > 0;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( array $probes ) {
	if ( class_exists( 'BizCity_Probe_TwinWeb_FB_Connect', false ) ) {
		$probes[] = new BizCity_Probe_TwinWeb_FB_Connect();
	}
	return $probes;
} );
