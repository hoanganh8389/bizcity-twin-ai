<?php
/**
 * BizCity Diagnostics - Twin GPT Customer Channels probe.
 *
 * DDV evidence for Phase 2 customer My Channels MVP:
 * - Disk: TwinWeb REST + FE MyChannels markers exist.
 * - Loader: routes are registered.
 * - Runtime: guest is denied, app catalog exposes My Channels safely, logged-in
 *   user payload is owner-scoped and does not expose credentials.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-21
 */

// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — DDV probe for /gpt/mychannels MVP.
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

if ( class_exists( 'BizCity_Probe_TwinWeb_Customer_Channels', false ) ) {
	return;
}

final class BizCity_Probe_TwinWeb_Customer_Channels implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.customer_channels'; }
	public function label(): string { return 'Twin GPT Customer Channels (/gpt/mychannels/)'; }
	public function description(): string {
		return 'Disk / Loader / Runtime: My Channels REST, app catalog entry, Zalo Bot link command, Facebook Pages owner scope and credential redaction.';
	}
	public function severity(): string { return 'warning'; }
	public function order(): int { return 89; }
	public function icon(): string { return 'share'; }
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
		$root  = defined( 'BIZCITY_TWIN_AI_DIR' ) ? rtrim( BIZCITY_TWIN_AI_DIR, '/\\' ) . '/' : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';

		$rest_file = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$app_file  = $root . 'modules/twinweb/ui/src/App.tsx';
		$page_file = $root . 'modules/twinweb/ui/src/pages/MyChannelsPage.tsx';
		$api_file  = $root . 'modules/twinweb/ui/src/api/myChannels.ts';
		$dist_dir  = $root . 'modules/twinweb/ui/dist/assets/';

		$rest_src = is_readable( $rest_file ) ? (string) file_get_contents( $rest_file ) : '';
		$app_src  = is_readable( $app_file ) ? (string) file_get_contents( $app_file ) : '';
		$page_src = is_readable( $page_file ) ? (string) file_get_contents( $page_file ) : '';
		$api_src  = is_readable( $api_file ) ? (string) file_get_contents( $api_file ) : '';

		$dist_marker_ok = false;
		if ( is_dir( $dist_dir ) ) {
			$assets = glob( $dist_dir . '*.js' );
			if ( is_array( $assets ) ) {
				foreach ( $assets as $asset ) {
					$src = is_readable( $asset ) ? (string) file_get_contents( $asset ) : '';
					if ( false !== strpos( $src, 'Kênh của tôi' ) || false !== strpos( $src, '/mychannels' ) ) {
						$dist_marker_ok = true;
						break;
					}
				}
			}
		}

		$rest_markers = array(
				"'/mychannels'",
				"'/mychannels/zalo/recent-chats'",
				"'/mychannels/zalo/pin-chat'",
				"'/mychannels/facebook/select'",
				"'/mychannels/automation-defaults'",
				"'/mychannels/automation/preflight'",
				"'/mychannels/automation/activate'",
				'function get_mychannels',
				'function get_mychannels_zalo_recent_chats',
				'function list_customer_zalo_recent_chats',
				'function pin_mychannels_zalo_chat',
				'function select_mychannels_facebook_page',
				'function get_mychannels_automation_defaults',
				'function get_automation_preflight',
				'function activate_my_workflow',
				'function create_mychannels_zalo_link_command',
				'bizcity_twinweb_mychannels',
				'bizcity_facebook_bots',
		);
		$missing = array();
		foreach ( $rest_markers as $marker ) {
			if ( '' === $rest_src || false === strpos( $rest_src, $marker ) ) {
				$missing[] = $marker;
			}
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — React src is optional on production; dist artifact is the deploy contract.
		$optional_missing = array();
		if ( '' !== $app_src ) {
			foreach ( array( 'isMyChannelsPath', 'MyChannelsPage' ) as $marker ) {
				if ( false === strpos( $app_src, $marker ) ) { $optional_missing[] = 'App:' . $marker; }
			}
		}
		if ( '' !== $page_src ) {
			// [2026-08-20 Johnny Chu] R-DDV-FE — assert stable component/action wiring instead of mutable translated labels.
			foreach ( array( 'export function MyChannelsPage', 'createLinkCommand', 'pinZaloChat', 'selectFacebookPage', 'runPreflight', 'runActivate', 'myChannelsApi.get' ) as $marker ) {
				if ( false === strpos( $page_src, $marker ) ) { $optional_missing[] = 'Page:' . $marker; }
			}
		}
		if ( '' !== $api_src ) {
			foreach ( array( "'/mychannels'", 'createZaloLinkCommand', 'getZaloRecentChats', 'selectFacebookPage', 'getAutomationDefaults', 'pinZaloChat', 'getAutomationPreflight', 'activateWorkflow' ) as $marker ) {
				if ( false === strpos( $api_src, $marker ) ) { $optional_missing[] = 'API:' . $marker; }
			}
		}
		$disk_ok = empty( $missing ) && empty( $optional_missing ) && $dist_marker_ok;
		$this->emit( $ctx, $steps, $pass, 'Disk - My Channels REST + FE + built artifact markers', $disk_ok, $disk_ok ? 'REST markers and built JS artifact marker are present; React src markers checked when source is deployed.' : 'Missing markers: ' . implode( ', ', array_merge( $missing, $optional_missing ) ) . '; dist_marker=' . ( $dist_marker_ok ? 'ok' : 'missing' ) );

		$loader_methods_ok = method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'select_mychannels_zalo_bot' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'create_mychannels_zalo_link_command' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_zalo_recent_chats' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'select_mychannels_facebook_page' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_automation_defaults' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_automation_preflight' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'activate_my_workflow' )
			&& method_exists( 'BizCity_TwinWeb_REST', 'get_mychannels_facebook_pages' );
		$this->emit( $ctx, $steps, $pass, 'Loader - TwinWeb My Channels methods', $loader_methods_ok, $loader_methods_ok ? 'My Channels handlers are loaded.' : 'One or more My Channels handlers are missing.' );

		$routes = rest_get_server()->get_routes();
		$route_checks = array(
			'/bizcity-twinweb/v1/mychannels' => 'GET',
			'/bizcity-twinweb/v1/mychannels/zalo/bots' => 'GET',
			'/bizcity-twinweb/v1/mychannels/zalo/select' => 'POST',
			'/bizcity-twinweb/v1/mychannels/zalo/recent-chats' => 'GET',
			'/bizcity-twinweb/v1/mychannels/zalo/link-command' => 'POST',
			'/bizcity-twinweb/v1/mychannels/zalo/pin-chat' => 'POST',
			'/bizcity-twinweb/v1/mychannels/facebook/pages' => 'GET',
			'/bizcity-twinweb/v1/mychannels/facebook/select' => 'POST',
			'/bizcity-twinweb/v1/mychannels/automation-defaults' => 'GET',
			'/bizcity-twinweb/v1/mychannels/automation/preflight' => 'GET',
			'/bizcity-twinweb/v1/mychannels/automation/activate' => 'POST',
			'/bizcity-twinweb/v1/mychannels/dashboard' => 'GET',
		);
		$missing_routes = array();
		foreach ( $route_checks as $route => $method ) {
			if ( ! $this->route_has_method( $routes, $route, $method ) ) {
				$missing_routes[] = $route . '.' . $method;
			}
		}
		$this->emit( $ctx, $steps, $pass, 'Loader - My Channels REST routes registered', empty( $missing_routes ), empty( $missing_routes ) ? 'All MVP routes registered.' : 'Missing routes: ' . implode( ', ', $missing_routes ) );

		$original_uid = (int) get_current_user_id();
		wp_set_current_user( 0 );
		try {
			$guest_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mychannels' );
			$guest_res = rest_do_request( $guest_req );
			$guest_data = is_wp_error( $guest_res ) ? array() : (array) $guest_res->get_data();
			$guest_ok = empty( $guest_data['success'] ) && (string) ( $guest_data['code'] ?? '' ) === 'auth_required';
			$this->emit( $ctx, $steps, $pass, 'Runtime - guest denied by R-ERROR-UX payload', $guest_ok, $guest_ok ? 'Guest receives auth_required payload.' : 'Guest did not receive auth_required payload.' );

			$app_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/apps/effective' );
			$app_res = rest_do_request( $app_req );
			$app_data = is_wp_error( $app_res ) ? array() : (array) $app_res->get_data();
			$apps = isset( $app_data['apps'] ) && is_array( $app_data['apps'] ) ? $app_data['apps'] : array();
			$mychannels = $this->find_app( $apps, 'mychannels' );
			$app_ok = is_array( $mychannels )
				&& (string) ( $mychannels['href'] ?? '' ) !== ''
				&& false !== strpos( (string) ( $mychannels['href'] ?? '' ), '/gpt/mychannels/' )
				&& ! empty( $mychannels['auth_required'] );
			$this->emit( $ctx, $steps, $pass, 'Runtime - app catalog exposes My Channels with auth gate', $app_ok, $app_ok ? 'mychannels app has /gpt/mychannels/ href and auth_required=true.' : 'mychannels app missing, href wrong, or auth_required missing.' );
		} finally {
			wp_set_current_user( $original_uid );
		}

		$current_uid = (int) get_current_user_id();
		if ( $current_uid > 0 ) {
			$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mychannels' );
			$res = rest_do_request( $req );
			$data = is_wp_error( $res ) ? array() : (array) $res->get_data();
			$payload_ok = ! empty( $data['success'] )
				&& (int) ( $data['user_id'] ?? 0 ) === $current_uid
				&& isset( $data['zalo']['bots'] )
				&& isset( $data['facebook']['pages'] )
				&& isset( $data['dashboard'] );
			$credential_leak = $this->contains_credential_key( $data );
			$this->emit( $ctx, $steps, $pass, 'Runtime - current user gets scoped payload without credentials', $payload_ok && ! $credential_leak, ( $payload_ok && ! $credential_leak ) ? 'Payload user_id matches current user and contains no app_secret/token/webhook_secret keys.' : 'Payload missing shape/user_id or contains credential-like keys.' );

			$recent_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mychannels/zalo/recent-chats' );
			$recent_res = rest_do_request( $recent_req );
			$recent_data = is_wp_error( $recent_res ) ? array() : (array) $recent_res->get_data();
			$recent_ok = ! empty( $recent_data['success'] ) && isset( $recent_data['items'] ) && is_array( $recent_data['items'] ) && ! $this->contains_credential_key( $recent_data );
			$this->emit( $ctx, $steps, $pass, 'Runtime - recent Zalo chats endpoint is owner-scoped and credential-redacted', $recent_ok, $recent_ok ? 'Recent chats route returns success/items and no credential-like keys.' : 'Recent chats route missing success/items or leaked credential-like keys.' );

			$defaults_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mychannels/automation-defaults' );
			$defaults_res = rest_do_request( $defaults_req );
			$defaults_data = is_wp_error( $defaults_res ) ? array() : (array) $defaults_res->get_data();
			$defaults_ok = ! empty( $defaults_data['success'] )
				&& isset( $defaults_data['defaults']['zalo'] )
				&& isset( $defaults_data['defaults']['facebook'] )
				&& isset( $defaults_data['defaults']['can_activate'] )
				&& ! $this->contains_credential_key( $defaults_data );
			$this->emit( $ctx, $steps, $pass, 'Runtime - automation defaults are owner-scoped and credential-redacted', $defaults_ok, $defaults_ok ? 'Defaults payload includes Zalo/Facebook/can_activate and no credential-like keys.' : 'Defaults payload missing shape or leaked credential-like keys.' );

			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — preflight route exists and returns R-ERROR-UX for unknown workflow.
			$preflight_req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mychannels/automation/preflight' );
			$preflight_req->set_query_params( array( 'workflow_id' => 999999 ) );
			$preflight_res = rest_do_request( $preflight_req );
			$preflight_data = is_wp_error( $preflight_res ) ? array() : (array) $preflight_res->get_data();
			$preflight_route_ok = isset( $preflight_data['code'] ) || isset( $preflight_data['_degraded'] );
			$this->emit( $ctx, $steps, $pass, 'Runtime - preflight route returns structured response (not 500)', $preflight_route_ok, $preflight_route_ok ? 'Preflight route returns code or _degraded for unknown workflow_id.' : 'Preflight route returned unexpected shape — check handler.' );
		} else {
			$step = array(
				'label'  => 'Runtime - current user gets scoped payload without credentials',
				'status' => 'skip',
				'detail' => 'No logged-in diagnostics user; scoped payload runtime check skipped.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		$meta_isolation_status = 'skip';
		$meta_detail = 'No logged-in diagnostics user; synthetic user-meta isolation skipped.';
		if ( $current_uid > 0 ) {
			$other_user_id = $this->find_other_user_id( $current_uid );
			if ( $other_user_id <= 0 ) {
				$meta_detail = 'Only one WP user found; A/B user-meta isolation skipped.';
			} else {
				$meta_key = 'bizcity_twinweb_mychannels';
				$had_current = metadata_exists( 'user', $current_uid, $meta_key );
				$had_other = metadata_exists( 'user', $other_user_id, $meta_key );
				$old_current = get_user_meta( $current_uid, $meta_key, true );
				$old_other = get_user_meta( $other_user_id, $meta_key, true );
				try {
					update_user_meta( $current_uid, $meta_key, array( 'selected_zalo_bot_id' => 1001, 'updated_at' => gmdate( 'c' ) ) );
					update_user_meta( $other_user_id, $meta_key, array( 'selected_zalo_bot_id' => 2002, 'updated_at' => gmdate( 'c' ) ) );
					$req = new WP_REST_Request( 'GET', '/bizcity-twinweb/v1/mychannels' );
					$res = rest_do_request( $req );
					$payload = is_wp_error( $res ) ? array() : (array) $res->get_data();
					$settings = isset( $payload['settings'] ) && is_array( $payload['settings'] ) ? $payload['settings'] : array();
					$meta_isolation_status = (int) ( $settings['selected_zalo_bot_id'] ?? 0 ) === 1001 ? 'pass' : 'fail';
					$meta_detail = $meta_isolation_status === 'pass'
						? 'Current user sees selected_zalo_bot_id=1001, not other user 2002.'
						: 'Current user did not receive its own selected_zalo_bot_id from user_meta.';
				} finally {
					if ( $had_current ) { update_user_meta( $current_uid, $meta_key, $old_current ); } else { delete_user_meta( $current_uid, $meta_key ); }
					if ( $had_other ) { update_user_meta( $other_user_id, $meta_key, $old_other ); } else { delete_user_meta( $other_user_id, $meta_key ); }
				}
			}
		}
		$step = array(
			'label'  => 'Runtime - Zalo selected bot is scoped per WP user_meta',
			'status' => $meta_isolation_status,
			'detail' => $meta_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( $meta_isolation_status === 'fail' ) { $pass = false; }

		$failed_steps = array();
		foreach ( $steps as $step ) {
			if ( is_array( $step ) && 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$failed_steps[] = (string) ( $step['label'] ?? 'unlabeled step' ) . ': ' . (string) ( $step['detail'] ?? 'no detail' );
			}
		}
		$summary = $pass ? 'Twin GPT My Channels MVP routes, UI markers and owner-safe payload contract are in place.' : 'Twin GPT My Channels MVP contract failed one or more DDV checks. Failed steps: ' . implode( ' | ', $failed_steps );

		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $summary,
			'error'    => $pass ? '' : 'twinweb_customer_channels_contract_failed',
			'fix_hint' => $pass ? '' : 'Check class-twinweb-rest.php mychannels routes, App.tsx/MyChannelsPage wiring and credential redaction.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}

	private function emit( $ctx, array &$steps, &$pass, $label, $ok, $detail ) {
		$step = array(
			'label'  => (string) $label,
			'status' => $ok ? 'pass' : 'fail',
			'detail' => (string) $detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );
		if ( ! $ok ) {
			$pass = false;
		}
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
			if ( is_string( $ep['methods'] ) && false !== strpos( strtoupper( $ep['methods'] ), $want ) ) {
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

	private function find_app( $apps, $id ) {
		foreach ( (array) $apps as $app ) {
			if ( is_array( $app ) && sanitize_key( (string) ( $app['id'] ?? '' ) ) === $id ) {
				return $app;
			}
		}
		return null;
	}

	private function contains_credential_key( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $inner ) {
				$key = strtolower( (string) $key );
				if ( false !== strpos( $key, 'secret' ) || false !== strpos( $key, 'token' ) || false !== strpos( $key, 'access_token' ) || false !== strpos( $key, 'webhook_secret' ) ) {
					return true;
				}
				if ( $this->contains_credential_key( $inner ) ) {
					return true;
				}
			}
		}
		return false;
	}

	private function find_other_user_id( $current_uid ) {
		$users = get_users( array(
			'fields' => 'ID',
			'number' => 10,
			'exclude' => array( (int) $current_uid ),
		) );
		if ( ! is_array( $users ) || empty( $users ) ) {
			return 0;
		}
		return (int) $users[0];
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinWeb_Customer_Channels';
	return $list;
} );
