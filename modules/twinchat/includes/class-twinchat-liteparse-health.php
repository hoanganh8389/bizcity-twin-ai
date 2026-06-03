<?php
/**
 * BizCity TwinChat — LiteParse Sidecar Health Proxy (Phase 0.42).
 *
 * Same-origin REST shim so FE (TwinChatAddSourceDialog) can show a live status
 * pill — green = sidecar running, red = down, gray = adapter file absent.
 *
 * Route:  GET /wp-json/bizcity-twinchat/v1/liteparse/health
 * Auth :  permission_callback = is_user_logged_in
 *
 * Response shape:
 *   {
 *     "available": bool,                   // adapter class loaded AND engine reachable
 *     "engine":    "liteparse_http"|"liteparse_cli"|"none",
 *     "url":       "http://127.0.0.1:7860" | "",
 *     "version":   "1.27.2.3" | "",
 *     "latency_ms": int,
 *     "checked_at": int,                   // unix ts
 *     "tier_ok":   bool,                   // current user has learning.layout_parse
 *     "fallback":  {                       // Gemini Flash via /bizcity/v1/llm/chat
 *        "enabled": bool,
 *        "model":   string,
 *        "reason":  string                  // 'ok' | 'no_api_key' | 'llm_client_missing' | 'disabled_by_filter'
 *     },
 *     "_degraded": null | { code, message }
 *   }
 *
 * Cached for 30s in a request-scoped transient so the dialog polling every few
 * seconds doesn't hammer the sidecar.
 *
 * R-GW-8 compliant — only hits 127.0.0.1; never delegates to bizcity.vn.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since      2026-05-27 (Phase 0.42)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_LiteParse_Health {

	const ROUTE             = '/liteparse/health';
	const TRANSIENT_KEY     = 'bizcity_twinchat_liteparse_health_v1';
	const TRANSIENT_TTL_OK  = 30;  // seconds when sidecar is up
	const TRANSIENT_TTL_BAD = 5;   // seconds when sidecar is down (allow faster recovery feedback)
	const HTTP_TIMEOUT      = 2;   // never block FE for more than 2s

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes(): void {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' )
			? BIZCITY_TWINCHAT_REST_NS
			: 'bizcity-twinchat/v1';

		register_rest_route( $ns, self::ROUTE, [
			'methods'             => 'GET',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
	}

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$force = (bool) $request->get_param( 'force' );

		// Cache lookup
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT_KEY );
			if ( is_array( $cached ) ) {
				$cached['tier_ok']  = $this->check_tier();
				$cached['fallback'] = $this->fallback_status();
				$cached['_cached']  = true;
				return new WP_REST_Response( $cached, 200 );
			}
		}

		$result = $this->probe_sidecar();
		$result['tier_ok']    = $this->check_tier();
		$result['fallback']   = $this->fallback_status();
		$result['_cached']    = false;
		$result['checked_at'] = time();

		$ttl = ! empty( $result['available'] ) ? self::TRANSIENT_TTL_OK : self::TRANSIENT_TTL_BAD;
		set_transient( self::TRANSIENT_KEY, $result, $ttl );

		return new WP_REST_Response( $result, 200 );
	}

	private function check_tier(): bool {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		if ( ! class_exists( 'BizCity_Entitlement' ) ) {
			// R-GW-8 — client topology without router → treat as Pro for visibility purposes.
			// Adapter itself still gates correctly on the server side at extract() time.
			return true;
		}
		return (bool) BizCity_Entitlement::can( $uid, 'learning.layout_parse' );
	}

	private function fallback_status(): array {
		if ( class_exists( 'BizCity_KG_LiteParse_Adapter' )
			&& method_exists( 'BizCity_KG_LiteParse_Adapter', 'gemini_fallback_status' )
		) {
			return BizCity_KG_LiteParse_Adapter::gemini_fallback_status();
		}
		return [ 'enabled' => false, 'model' => '', 'reason' => 'adapter_missing' ];
	}

	private function probe_sidecar(): array {
		$t0 = microtime( true );

		// 1. Adapter class must be loaded (file may be absent on dev clones).
		if ( ! class_exists( 'BizCity_KG_LiteParse_Adapter' ) ) {
			return [
				'available'  => false,
				'engine'     => 'none',
				'url'        => '',
				'version'    => '',
				'latency_ms' => 0,
				'_degraded'  => [
					'code'    => 'adapter_missing',
					'message' => 'LiteParse adapter file not installed on this site.',
				],
			];
		}

		$engine = BizCity_KG_LiteParse_Adapter::picked_engine();
		$url    = defined( 'BIZCITY_LITEPARSE_URL' ) ? (string) BIZCITY_LITEPARSE_URL : '';

		// 2. HTTP sidecar — ping /health
		if ( $engine === 'liteparse_http' && $url !== '' ) {
			$res = wp_remote_get( rtrim( $url, '/' ) . '/health', [
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 0,
				'headers'     => [ 'Accept' => 'application/json' ],
			] );
			$latency = (int) round( ( microtime( true ) - $t0 ) * 1000 );

			if ( is_wp_error( $res ) ) {
				return [
					'available'  => false,
					'engine'     => 'liteparse_http',
					'url'        => $url,
					'version'    => '',
					'latency_ms' => $latency,
					'_degraded'  => [
						'code'    => 'sidecar_unreachable',
						'message' => 'Sidecar không kết nối được: ' . $res->get_error_message(),
					],
				];
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( $code !== 200 || ! is_array( $body ) ) {
				return [
					'available'  => false,
					'engine'     => 'liteparse_http',
					'url'        => $url,
					'version'    => '',
					'latency_ms' => $latency,
					'_degraded'  => [
						'code'    => 'sidecar_bad_response',
						'message' => 'Sidecar HTTP ' . $code,
					],
				];
			}
			return [
				'available'  => true,
				'engine'     => 'liteparse_http',
				'url'        => $url,
				'version'    => (string) ( $body['version'] ?? '' ),
				'latency_ms' => $latency,
				'_degraded'  => null,
			];
		}

		// 3. CLI mode (fallback — currently broken on most Linux/Node v22 due to
		//    upstream native module issue; left in place for future Rust prebuilt).
		if ( $engine === 'liteparse_cli' ) {
			$cli_ok  = BizCity_KG_LiteParse_Adapter::is_available();
			$version = $cli_ok ? BizCity_KG_LiteParse_Adapter::version_string() : '';
			return [
				'available'  => $cli_ok,
				'engine'     => 'liteparse_cli',
				'url'        => '',
				'version'    => $version,
				'latency_ms' => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
				'_degraded'  => $cli_ok ? null : [
					'code'    => 'cli_missing',
					'message' => 'lit binary not on PATH.',
				],
			];
		}

		return [
			'available'  => false,
			'engine'     => 'none',
			'url'        => '',
			'version'    => '',
			'latency_ms' => 0,
			'_degraded'  => [
				'code'    => 'no_engine',
				'message' => 'Cả sidecar URL và CLI binary đều không có.',
			],
		];
	}
}
