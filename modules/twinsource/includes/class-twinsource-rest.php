<?php
/**
 * Twinsource REST — thin alias namespace.
 *
 * Twinsource intentionally has NO storage of its own. This class only
 * provides a stable namespace `bizcity-twinsource/v1/*` that PROXIES
 * to KG Hub's `bizcity-knowledge/v2/scoped/*` endpoints. The proxy
 * exists so plugin nonces / capabilities can be enforced consistently
 * even when a plugin needs to keep its own legacy nonce action name.
 *
 * Wave 0 (this scaffold): only registers a `health` ping.
 * Wave 1: implement proxy routes.
 *
 * @package Bizcity_Twin_AI\Twinsource
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Twinsource_REST {

	const NAMESPACE = 'bizcity-twinsource/v1';

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/health', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'health' ],
		] );

		// Wave 1 — TODO:
		// /scope/(?P<plugin>[\w-]+)/(?P<scope_id>[\w:-]+)/sources    GET, POST
		// /scope/.../sources/(?P<id>\d+)                              GET, DELETE
		// /scope/.../sources/search                                   POST  (web_search → tavily)
		// All routes proxy to BizCity_KG facade.
	}

	public static function health( WP_REST_Request $req ): WP_REST_Response {
		return new WP_REST_Response( [
			'ok'       => true,
			'version'  => defined( 'BIZCITY_TWINSOURCE_VERSION' ) ? BIZCITY_TWINSOURCE_VERSION : 'unknown',
			'kg_ready' => class_exists( 'BizCity_KG' ),
		], 200 );
	}
}
