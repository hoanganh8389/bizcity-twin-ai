<?php
/**
 * OAuth Proxy Helper (PHASE 0.31 T-S6.3)
 *
 * Wraps the existing `WaicIntegration` auth-proxy URL/secret so non-Waic
 * callers (mu-plugins, channel adapters, REST clients) can route every new
 * OAuth handshake through the central `bizcity.vn/wp-json/aops/v1/oauth/*`
 * proxy without re-implementing the token signing.
 *
 * Why a proxy at all? Per-blog FB/Google App credentials are managed at the
 * network level (T-S4.3). Proxying ensures App ID/Secret never leak into
 * per-blog code and lets us rotate in one place.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.4.0 (Sprint 6 T-S6.3)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_OAuth_Proxy {

	/** Mirror of WaicIntegration default — keep in sync. */
	const PROXY_BASE = 'https://bizcity.vn/';
	const PROXY_INIT = 'wp-json/aops/v1/oauth/init';
	const PROXY_REFRESH = 'wp-json/aops/v1/oauth/refresh';

	/**
	 * Resolve the proxy init URL. Optionally override with a filter so a
	 * staging site can route through a different proxy host.
	 */
	public static function get_init_url( array $query = array() ): string {
		$base = (string) apply_filters( 'bizcity_oauth_proxy_base', self::PROXY_BASE );
		$url  = rtrim( $base, '/' ) . '/' . ltrim( self::PROXY_INIT, '/' );
		return $query ? add_query_arg( $query, $url ) : $url;
	}

	public static function get_refresh_url( array $query = array() ): string {
		$base = (string) apply_filters( 'bizcity_oauth_proxy_base', self::PROXY_BASE );
		$url  = rtrim( $base, '/' ) . '/' . ltrim( self::PROXY_REFRESH, '/' );
		return $query ? add_query_arg( $query, $url ) : $url;
	}

	/**
	 * Verify a JWT-style token_package returned by the proxy. Mirrors
	 * `WaicIntegration::unpackTokenPackage()` so non-Waic code (e.g. REST
	 * callbacks in mu-plugins) can validate without bootstrapping the full
	 * automation framework.
	 *
	 * @return array|string Decoded payload array on success, error string on failure.
	 */
	public static function unpack_token_package( string $jwt, string $secret = '' ) {
		if ( $secret === '' ) {
			$secret = (string) apply_filters( 'bizcity_oauth_proxy_secret', '' );
		}
		if ( $secret === '' ) {
			return 'Missing oauth proxy secret (filter `bizcity_oauth_proxy_secret`).';
		}
		$parts = explode( '.', $jwt );
		if ( count( $parts ) !== 3 ) {
			return 'Invalid format token_package';
		}
		// [2026-08-20 Johnny Chu] CODEC-CORE — use shared JWT base64url/HMAC primitives.
		$signature = BizCity_Codec::base64url_decode( $parts[2] );
		$expected  = BizCity_Codec::hmac_sha256( $parts[0] . '.' . $parts[1], $secret, true );
		if ( ! hash_equals( $expected, $signature ) ) {
			return 'The signature is incorrect';
		}
		$payload = BizCity_Codec::json_base64url_decode( $parts[1] );
		if ( ! is_array( $payload ) ) {
			return 'Incorrect body JWT';
		}
		return $payload;
	}
}
