<?php
/**
 * BizCoach Pro — Astro Gateway Client (PHASE-0.2 Sprint G.1).
 *
 * Single client surface that EVERY astro caller in bizcoach-pro (and the
 * adopted legacy `bccm_*` admin pages) must use to reach the FAA gateway
 * exposed by `bizcity-llm-router` at `bizcity/v1/astrology/*`.
 *
 * Strategy
 *   1. PREFER same-process dispatch via `rest_do_request()` — zero HTTP
 *      overhead, no auth round-trip needed (cookie/nonce works for
 *      logged-in users; router's auth class falls back to current user).
 *   2. FALLBACK to remote `wp_remote_*` using the configured Bearer token
 *      `bcpro_gateway_api_key` (site option). Used when router isn't
 *      mounted on the current site (cross-site call) or for cron paths
 *      where no user is logged in.
 *
 * Public contract (sugar methods on top of `call()`):
 *
 *   - natal_western( $payload )
 *   - transits_western( $payload )
 *   - chart_svg_western( $payload )
 *   - calculate_vedic( $payload )
 *   - dasha_vedic( $payload )
 *   - gochar_vedic( $payload )
 *   - bazi_chinese( $payload )
 *   - geo_search( $params )       — GET
 *   - moon_phase( $params )       — GET
 *   - moon_month( $params )       — GET
 *   - quota()                     — GET
 *
 * All methods return a uniform shape:
 *
 *   array(
 *     'success'   => bool,
 *     'envelope'  => array,        // gateway V2 envelope (or error body)
 *     'http'      => array(
 *       'status'      => int,
 *       'latency_ms'  => int,
 *       'transport'   => 'rest_do_request' | 'wp_remote',
 *     ),
 *     'error'     => null | string,
 *   )
 *
 * @package BizCoach_Pro
 * @since   0.3.0  (PHASE-0.2 Sprint G.1)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Client' ) ) { return; }

final class BizCoach_Pro_Astro_Client {

	const NS               = 'bizcity/v1';
	/**
	 * @deprecated since 0.4.0 — kept only for backward compatibility / migration.
	 * Canonical option per R-1API-2 is `bizcity_llm_api_key`. Use {@see self::get_api_key()}.
	 */
	const OPT_API_KEY      = 'bcpro_gateway_api_key';
	/**
	 * @deprecated since 0.4.0 — canonical option per R-1API-2 is `bizcity_llm_gateway_url`.
	 * Use {@see self::get_gateway_base()}.
	 */
	const OPT_GATEWAY_BASE = 'bcpro_gateway_base_url';
	/** Canonical R-1API option names — the SoT, shared across all BizCity plugins. */
	const OPT_CANON_API_KEY     = 'bizcity_llm_api_key';
	const OPT_CANON_GATEWAY_URL = 'bizcity_llm_gateway_url';
	const DEFAULT_TIMEOUT  = 30;

	/**
	 * Canonical HUB URL — every bizcoach-pro install (subsite OR external
	 * host) calls back to this BizCity LLM Router endpoint by default.
	 * Override via:
	 *   1. Site option `bcpro_gateway_base_url` (admin UI)
	 *   2. Filter `bcpro_astro_hub_url`
	 */
	const HUB_URL = 'https://bizcity.vn/wp-json';

	/* =================================================================
	 * Sugar methods
	 * ================================================================= */

	public static function natal_western( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/western/natal', $payload, $opts );
	}

	public static function transits_western( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/western/transits', $payload, $opts );
	}

	public static function transits_timeline_western( array $payload, array $opts = array() ): array {
		// Timeline endpoint is heavier — bump default timeout.
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 30; }
		return self::call( 'POST', '/astrology/western/transits/timeline', $payload, $opts );
	}

	public static function chart_svg_western( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/western/chart-svg', $payload, $opts );
	}

	// [2026-07-09 Johnny Chu] PHASE-A5 — Western PRO charts wrappers (same-origin client -> hub gateway).
	public static function synastry_western( array $payload, array $opts = array() ): array {
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 30; }
		return self::call( 'POST', '/astrology/western/synastry', $payload, $opts );
	}

	// [2026-07-09 Johnny Chu] PHASE-A5 — Western PRO charts wrappers (same-origin client -> hub gateway).
	public static function composite_western( array $payload, array $opts = array() ): array {
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 30; }
		return self::call( 'POST', '/astrology/western/composite', $payload, $opts );
	}

	// [2026-07-09 Johnny Chu] PHASE-A5 — Western PRO charts wrappers (same-origin client -> hub gateway).
	public static function solar_return_western( array $payload, array $opts = array() ): array {
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 30; }
		return self::call( 'POST', '/astrology/western/solar-return', $payload, $opts );
	}

	// [2026-07-09 Johnny Chu] PHASE-A5 — Western PRO charts wrappers (same-origin client -> hub gateway).
	public static function lunar_return_western( array $payload, array $opts = array() ): array {
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 30; }
		return self::call( 'POST', '/astrology/western/lunar-return', $payload, $opts );
	}

	public static function calculate_vedic( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/vedic/calculate', $payload, $opts );
	}

	// [2026-07-05 Johnny Chu] HOTFIX — FAA2 Vedic full (planets + extended + navamsa).
	// Calls /astrology/vedic/faa2/full which uses faa2_vedic provider (3 endpoints in 1).
	// Prefer this over BizCity_Astro_Router direct call in fetch_all_astro — client has
	// HTTP fallback to bizcity.vn when local FAA2 key not configured.
	public static function natal_vedic_faa2_full( array $payload, array $opts = array() ): array {
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 30; }
		return self::call( 'POST', '/astrology/vedic/faa2/full', $payload, $opts );
	}

	public static function dasha_vedic( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/vedic/dasha', $payload, $opts );
	}

	public static function gochar_vedic( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/vedic/gochar', $payload, $opts );
	}

	public static function bazi_chinese( array $payload, array $opts = array() ): array {
		return self::call( 'POST', '/astrology/chinese/bazi', $payload, $opts );
	}

	public static function geo_search( array $params, array $opts = array() ): array {
		return self::call( 'GET', '/astrology/utilities/geo-search', $params, $opts );
	}

	public static function moon_phase( array $params, array $opts = array() ): array {
		return self::call( 'GET', '/astrology/utilities/moon-phase', $params, $opts );
	}

	public static function moon_month( array $params, array $opts = array() ): array {
		return self::call( 'GET', '/astrology/utilities/moon-month', $params, $opts );
	}

	// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-5 — Ashtakoot compatibility score wrapper
	public static function ashtakoot( array $payload, array $opts = array() ): array {
		if ( empty( $opts['timeout'] ) ) { $opts['timeout'] = 20; }
		return self::call( 'POST', '/astrology/match/ashtakoot-score', $payload, $opts );
	}

	public static function quota( array $opts = array() ): array {
		return self::call( 'GET', '/astrology/quota', array(), $opts );
	}

	/* =================================================================
	 * Core dispatcher
	 *
	 * @param string $method   'GET'|'POST'
	 * @param string $path     Route relative to namespace, e.g. '/astrology/western/natal'.
	 * @param array  $payload  Body params (POST) or query params (GET).
	 * @param array  $opts     'timeout' (int seconds), 'force_remote' (bool),
	 *                         'api_key' (string override), 'headers' (array).
	 * ================================================================= */

	public static function call( string $method, string $path, array $payload = array(), array $opts = array() ): array {
		$method  = strtoupper( $method );
		$started = microtime( true );

		// Same-process fast path: router classes loaded AND no explicit base
		// override (admin override → always go remote so the override is
		// honoured, e.g. pointing staging client at production hub).
		$loaded       = self::is_in_process_ready();
		// Treat ANY explicit base override (legacy bcpro_* or canonical bizcity_llm_*)
		// as a signal to always go remote so the override is honoured.
		$has_override = (string) get_site_option( self::OPT_GATEWAY_BASE, '' ) !== ''
		             || (string) get_site_option( self::OPT_CANON_GATEWAY_URL, '' ) !== '';
		$force_remote = ! empty( $opts['force_remote'] ) || $has_override;

		if ( $loaded && ! $force_remote ) {
			$result = self::call_in_process( $method, $path, $payload, $opts, $started );
		} else {
			$result = self::call_remote( $method, $path, $payload, $opts, $started );
		}

		/**
		 * Fires after every gateway call (success or failure) for diagnostics.
		 *
		 * @param array  $result  Final result envelope.
		 * @param string $path    Gateway path called.
		 * @param string $method  HTTP method.
		 */
		do_action( 'bcpro_astro_client_call', $result, $path, $method );

		return $result;
	}

	/* =================================================================
	 * Transport: in-process (rest_do_request)
	 * ================================================================= */

	private static function call_in_process( string $method, string $path, array $payload, array $opts, float $started ): array {
		$route = '/' . self::NS . $path;

		$req = new WP_REST_Request( $method, $route );
		$req->set_header( 'content-type', 'application/json' );

		if ( $method === 'GET' ) {
			$req->set_query_params( self::flatten_scalar_params( $payload ) );
		} else {
			$req->set_body( wp_json_encode( $payload ) );
		}

		// If caller is anonymous (cron), inject Bearer header so router's
		// auth path resolves to the gateway-owner user.
		$key = $opts['api_key'] ?? self::get_api_key();
		if ( ! is_user_logged_in() && $key ) {
			$req->set_header( 'authorization', 'Bearer ' . $key );
		}

		// Custom headers pass-through (rare).
		if ( ! empty( $opts['headers'] ) && is_array( $opts['headers'] ) ) {
			foreach ( $opts['headers'] as $h => $v ) {
				$req->set_header( $h, $v );
			}
		}

		$response = rest_do_request( $req );
		$status   = (int) $response->get_status();
		$body     = $response->get_data();
		if ( ! is_array( $body ) ) {
			$body = array( 'raw' => $body );
		}

		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );
		$success = ( $status >= 200 && $status < 300 ) && empty( $body['code'] );
		$error   = $success ? null : ( $body['message'] ?? $body['code'] ?? 'http_' . $status );

		return array(
			'success'  => $success,
			'envelope' => $body,
			'http'     => array(
				'status'     => $status,
				'latency_ms' => $latency,
				'transport'  => 'rest_do_request',
			),
			'error'    => $error,
		);
	}

	/* =================================================================
	 * Transport: remote (wp_remote_*)
	 * ================================================================= */

	private static function call_remote( string $method, string $path, array $payload, array $opts, float $started ): array {
		$base = self::resolve_hub_url();
		$url  = $base . '/' . self::NS . $path;

		$key = $opts['api_key'] ?? self::get_api_key();

		// Fail fast with actionable message when no Bearer key — remote hub
		// rejects with generic 401 otherwise.
		if ( $key === '' ) {
			$latency = (int) round( ( microtime( true ) - $started ) * 1000 );
			$settings_url = admin_url( 'admin.php?page=bizcity-twinchat-settings' );
			$msg = sprintf(
				'Chưa cấu hình BizCity API key (gateway %s). Vào TwinChat → ⚙ Settings (%s) → dán key hoặc bấm "Đăng ký nhanh". 1 key dùng chung cho mọi plugin BizCity (R-1API).',
				$base,
				$settings_url
			);
			return array(
				'success'  => false,
				'envelope' => array( 'code' => 'no_api_key', 'message' => $msg ),
				'http'     => array( 'status' => 0, 'latency_ms' => $latency, 'transport' => 'wp_remote' ),
				'error'    => $msg,
			);
		}

		$timeout = (int) ( $opts['timeout'] ?? self::DEFAULT_TIMEOUT );

		$args = array(
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'content-type'  => 'application/json',
					'authorization' => $key ? 'Bearer ' . $key : '',
				),
				(array) ( $opts['headers'] ?? array() )
			),
		);

		if ( $method === 'GET' ) {
			$qs  = http_build_query( self::flatten_scalar_params( $payload ) );
			$url = $url . ( $qs ? ( '?' . $qs ) : '' );
			$resp = wp_remote_get( $url, $args );
		} else {
			$args['body'] = wp_json_encode( $payload );
			$resp = wp_remote_post( $url, $args );
		}

		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $resp ) ) {
			return array(
				'success'  => false,
				'envelope' => array(
					'code'    => 'transport_error',
					'message' => $resp->get_error_message(),
				),
				'http'     => array(
					'status'     => 0,
					'latency_ms' => $latency,
					'transport'  => 'wp_remote',
				),
				'error'    => $resp->get_error_message(),
			);
		}

		$status    = (int) wp_remote_retrieve_response_code( $resp );
		$raw_body  = (string) wp_remote_retrieve_body( $resp );
		// [2026-06-28 Johnny Chu] HOTFIX — strip UTF-8 BOM if present.
		// bizcity.vn router returns valid JSON but prefixed with \xEF\xBB\xBF (BOM),
		// causing json_decode() to return null (JSON_ERROR_SYNTAX=4) even though body is valid.
		if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
			$raw_body = substr( $raw_body, 3 );
		}
		$body      = json_decode( $raw_body, true );
		if ( ! is_array( $body ) ) {
			error_log( '[BizCoach_Pro_Astro_Client] call_remote non-JSON response'
				. ' status=' . $status
				. ' path=' . $path
				. ' json_last_error=' . json_last_error()
				. ' body_len=' . strlen( $raw_body )
				. ' body_preview=' . substr( $raw_body, 0, 500 ) );
			$body = array( 'raw' => $body );
		}

		$success = ( $status >= 200 && $status < 300 ) && empty( $body['code'] );
		$error   = $success ? null : ( $body['message'] ?? $body['code'] ?? 'http_' . $status );

		return array(
			'success'  => $success,
			'envelope' => $body,
			'http'     => array(
				'status'     => $status,
				'latency_ms' => $latency,
				'transport'  => 'wp_remote',
			),
			'error'    => $error,
		);
	}

	/* =================================================================
	 * Helpers
	 * ================================================================= */

	/** Flatten array params to scalars (querystring-safe) by JSON-encoding arrays. */
	private static function flatten_scalar_params( array $params ): array {
		$out = array();
		foreach ( $params as $k => $v ) {
			if ( is_scalar( $v ) || $v === null ) {
				$out[ $k ] = $v;
			} else {
				$out[ $k ] = wp_json_encode( $v );
			}
		}
		return $out;
	}

	/** True when the gateway is reachable in-process (router plugin active). */
	public static function is_in_process_ready(): bool {
		return class_exists( 'BizCity_Astrology_REST' ) && class_exists( 'BizCity_Router_Auth' );
	}

	/**
	 * Canonical API key accessor — R-1API-9 / R-1API-10 fallback chain:
	 *   1. legacy site option `bcpro_gateway_api_key` (pre-2026-05-17 installs)
	 *   2. canonical site option `bizcity_llm_api_key` (R-1API-2)
	 *
	 * All bizcoach-pro callers MUST go through this — never `get_site_option(OPT_API_KEY)` directly.
	 */
	public static function get_api_key(): string {
		$legacy = (string) get_site_option( self::OPT_API_KEY, '' );
		if ( $legacy !== '' ) { return $legacy; }
		return (string) get_site_option( self::OPT_CANON_API_KEY, '' );
	}

	/**
	 * Canonical gateway base accessor — R-1API fallback chain:
	 *   1. legacy site option `bcpro_gateway_base_url`
	 *   2. canonical site option `bizcity_llm_gateway_url` (R-1API-2)
	 *   3. filter `bcpro_astro_hub_url`
	 *   4. HUB_URL constant
	 */
	public static function get_gateway_base(): string {
		$base = (string) get_site_option( self::OPT_GATEWAY_BASE, '' );
		if ( $base === '' ) {
			$canon = (string) get_site_option( self::OPT_CANON_GATEWAY_URL, '' );
			if ( $canon !== '' ) {
				// Canonical option stores root URL (e.g. https://bizcity.vn); HUB_URL
				// historically points at /wp-json. Normalise so call_remote()'s
				// concatenation with `/bizcity/v1/...` still resolves.
				$canon = untrailingslashit( $canon );
				if ( substr( $canon, -8 ) !== '/wp-json' ) {
					$canon .= '/wp-json';
				}
				$base = $canon;
			}
		}
		if ( $base === '' ) {
			$base = (string) apply_filters( 'bcpro_astro_hub_url', self::HUB_URL );
		}
		return untrailingslashit( $base );
	}

	/**
	 * Resolve the canonical hub URL used by the HTTP transport.
	 * Alias of {@see self::get_gateway_base()} for backward compatibility.
	 */
	public static function resolve_hub_url(): string {
		return self::get_gateway_base();
	}

	/** Return the configured API key (masked) for UI display. Never logs the full key. */
	public static function get_masked_api_key(): string {
		$k = self::get_api_key();
		if ( $k === '' ) return '';
		return substr( $k, 0, 6 ) . '…' . substr( $k, -4 );
	}
}
