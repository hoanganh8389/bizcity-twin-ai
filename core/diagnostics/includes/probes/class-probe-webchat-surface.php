<?php
/**
 * DDV probe for the moved WebChat extension surface.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_WebChat_Surface', false ) ) {
	return;
}

final class BizCity_Probe_WebChat_Surface implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.webchat.surface';
	}

	public function label(): string {
		return 'WebChat extension surface';
	}

	public function description(): string {
		return 'Checks the moved WebChat bootstrap, public shortcodes, and REST registration boundary without writing chat data.';
	}

	public function severity(): string {
		return 'blocking';
	}

	public function order(): int {
		return 79;
	}

	public function icon(): string {
		return 'MessageSquare';
	}

	public function estimate_ms(): int {
		return 80;
	}

	public function precondition() {
		return class_exists( 'BizCity_WebChat_API' )
			? true
			: new WP_Error( 'webchat_api_missing', 'BizCity_WebChat_API is not loaded.' );
	}

	public function run( $ctx ): array {
		// [2026-08-25 Johnny Chu] PHASE-1.29-WEBCHAT-SURFACE — verify moved extension contracts without provider or data writes.
		$steps = array();
		$pass  = true;
		$emit  = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
		};

		$shortcodes_ok = shortcode_exists( 'bizcity_webchat' ) && shortcode_exists( 'bizcity_webchat_timeline' );
		$emit(
			'Runtime - WebChat shortcodes registered',
			$shortcodes_ok,
			$shortcodes_ok ? 'bizcity_webchat and bizcity_webchat_timeline are registered.' : 'One or more WebChat shortcodes are missing.'
		);

		$api = BizCity_WebChat_API::instance();
		$rest_hook_ok = false !== has_action( 'rest_api_init', array( $api, 'register_routes' ) );
		$emit(
			'Loader - WebChat REST registration hook',
			$rest_hook_ok,
			$rest_hook_ok ? 'bizcity-webchat/v1 REST registration hook is attached.' : 'WebChat REST registration hook is missing.'
		);

		$route_status = 'REST server unavailable; route enumeration deferred to REST smoke.';
		$route_ok = true;
		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes();
			$route_ok = false;
			foreach ( array_keys( (array) $routes ) as $route ) {
				if ( strpos( (string) $route, '/bizcity-webchat/v1/ready' ) !== false ) {
					$route_ok = true;
					break;
				}
			}
			$route_status = $route_ok ? 'GET /bizcity-webchat/v1/ready is registered.' : 'REST server is available but the WebChat ready route is missing.';
		}
		$emit( 'Runtime - WebChat ready route', $route_ok, $route_status );

		$required_routes = array( '/ready', '/send', '/inbox', '/list', '/conversation/', '/timeline' );
		$missing_routes = array();
		if ( function_exists( 'rest_get_server' ) ) {
			$route_keys = array_keys( (array) rest_get_server()->get_routes() );
			foreach ( $required_routes as $required_route ) {
				$found = false;
				foreach ( $route_keys as $route_key ) {
					if ( strpos( (string) $route_key, '/bizcity-webchat/v1' . $required_route ) !== false ) {
						$found = true;
						break;
					}
				}
				if ( ! $found ) {
					$missing_routes[] = $required_route;
				}
			}
		}
		$routes_ok = empty( $missing_routes );
		$emit(
			'Runtime - WebChat REST route family',
			$routes_ok,
			$routes_ok ? 'ready/send/inbox/list/conversation/timeline route family is registered.' : 'Missing WebChat REST routes: ' . implode( ', ', $missing_routes )
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'WebChat extension surface contract passed.' : 'WebChat extension surface contract failed.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_WebChat_Surface';
	return $list;
} );
