<?php
/**
 * Webhook Router — single ingress for all channels (PHASE 0.33 M1)
 *
 * RULE: Mọi inbound webhook đi qua Router trước. Router CHỈ làm 4 việc trong M1:
 *   1. Detect platform từ URL (canonical + legacy alias).
 *   2. Capture raw body + headers.
 *   3. Insert vào wp_{Y_M_D}_webhook_log (audit trail).
 *   4. Yield — KHÔNG thay thế adapter handler hiện tại.
 *
 * Adapter handlers cũ (FB/Zalo Bot/...) vẫn chạy bình thường sau Router.
 * M2 sẽ refactor adapter để trả về normalized envelope rồi Router tự fire trigger.
 *
 * Canonical URL:  /biz/hook/{platform}/
 * Legacy alias:   /bizfbhook/, ?fbhook=1, /zalohook/, /bizhook/, /webchat-hook/
 *
 * Disable via: add_filter('bizcity_webhook_router_enabled', '__return_false');
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since 1.5.0 (PHASE 0.33 M1)
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Webhook_Router {

	const QUERY_VAR = 'bizcity_hook';

	/** @var array<string,string> URL pattern (regex) → platform */
	private static $legacy_map = array(
		'#^/bizfbhook/?$#i'                  => 'FB_MESS',
		'#^/zalohook(/|_test/)?$#i'          => 'ZALO_BOT',
		'#^/bizhook/?$#i'                    => 'ZALO_HOTLINE',
		'#^/webchat-hook/?$#i'               => 'WEBCHAT',
	);

	/** @var array<string,string> Canonical platform slug → uppercase platform key */
	private static $canonical_map = array(
		'facebook'      => 'FB_MESS',
		'fb'            => 'FB_MESS',
		'zalo-bot'      => 'ZALO_BOT',
		'zalo-hotline'  => 'ZALO_HOTLINE',
		'webchat'       => 'WEBCHAT',
		'telegram'      => 'TELEGRAM',
	);

	/** @var array{date:string,id:int}|null Current request log row (set on intake) */
	private static $current = null;

	/** @var float|null Start microtime for latency calc */
	private static $started = null;

	/* ───────────────────────── Boot ───────────────────────── */

	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'init',           array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars',     array( __CLASS__, 'register_query_var' ) );
		// Intake — fire EARLIEST possible (parse_request runs before plugins/template_redirect).
		add_action( 'parse_request',  array( __CLASS__, 'intake' ), 1 );
		// Late update — capture HTTP status + finalize latency right before WP shuts down.
		add_action( 'shutdown',       array( __CLASS__, 'finalize' ), 99 );
	}

	private static function is_enabled(): bool {
		$opt = (int) get_option( 'bizcity_webhook_router_enabled', 1 );
		return (bool) apply_filters( 'bizcity_webhook_router_enabled', (bool) $opt );
	}

	public static function register_rewrite(): void {
		add_rewrite_tag( '%' . self::QUERY_VAR . '%', '([a-z0-9-]+)' );
		add_rewrite_rule( '^biz/hook/([a-z0-9-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/* ───────────────────────── Intake ───────────────────────── */

	/**
	 * Detect platform from current request, log raw, set $current.
	 *
	 * Runs at parse_request — does NOT short-circuit downstream handlers.
	 */
	public static function intake( $wp ): void {
		$platform = self::detect_platform_from_request( $wp );
		if ( ! $platform ) {
			return;
		}

		self::$started = microtime( true );

		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET';
		$body    = file_get_contents( 'php://input' );
		if ( ! is_string( $body ) ) {
			$body = '';
		}
		// Cap body size in audit table to avoid 16MB blow-ups; keep first 256KB.
		if ( strlen( $body ) > 262144 ) {
			$body = substr( $body, 0, 262144 ) . "\n…[truncated by router]";
		}

		$headers = self::collect_headers();
		$ip      = self::client_ip();
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) $_SERVER['HTTP_USER_AGENT'], 0, 255 ) : '';

		$log = BizCity_Webhook_Log::log( array(
			'platform'      => $platform,
			'endpoint'      => $req_uri,
			'method'        => $method,
			'http_status'   => 0,           // updated in finalize()
			'verify_status' => 'pending',   // adapter sẽ patch khi M2
			'remote_ip'     => $ip,
			'user_agent'    => $ua,
			'headers'       => $headers,
			'body_raw'      => $body,
		) );

		self::$current = $log;

		/**
		 * Cho phép adapter / observer subscribe sự kiện intake.
		 * Adapter ở M2 sẽ patch row qua BizCity_Webhook_Log::update().
		 *
		 * @param array{date:string,id:int} $log
		 * @param string                    $platform
		 * @param string                    $body
		 */
		do_action( 'bizcity_webhook_router_intake', $log, $platform, $body );
	}

	public static function finalize(): void {
		if ( ! self::$current ) {
			return;
		}
		$status  = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		$latency = self::$started ? (int) round( ( microtime( true ) - self::$started ) * 1000 ) : 0;
		BizCity_Webhook_Log::update( self::$current['date'], self::$current['id'], array(
			'http_status' => $status > 0 ? $status : 200,
			'latency_ms'  => $latency,
		) );
		self::$current = null;
		self::$started = null;
	}

	/* ───────────────────────── Detection ───────────────────────── */

	private static function detect_platform_from_request( $wp ): string {
		// Canonical via query var.
		$slug = isset( $wp->query_vars[ self::QUERY_VAR ] ) ? (string) $wp->query_vars[ self::QUERY_VAR ] : '';
		if ( $slug !== '' && isset( self::$canonical_map[ strtolower( $slug ) ] ) ) {
			return self::$canonical_map[ strtolower( $slug ) ];
		}

		$req_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path    = parse_url( $req_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			$path = '';
		}

		// ?fbhook=1 legacy (FB Messenger only)
		if ( isset( $_GET['fbhook'] ) ) {
			return 'FB_MESS';
		}

		foreach ( self::$legacy_map as $regex => $platform ) {
			if ( preg_match( $regex, $path ) ) {
				return $platform;
			}
		}

		return '';
	}

	/* ───────────────────────── Helpers ───────────────────────── */

	private static function collect_headers(): array {
		$out = array();
		if ( function_exists( 'getallheaders' ) ) {
			$h = getallheaders();
			if ( is_array( $h ) ) {
				foreach ( $h as $k => $v ) {
					$out[ (string) $k ] = is_string( $v ) ? $v : wp_json_encode( $v );
				}
				return $out;
			}
		}
		foreach ( $_SERVER as $k => $v ) {
			if ( strpos( $k, 'HTTP_' ) === 0 ) {
				$name = str_replace( '_', '-', strtolower( substr( $k, 5 ) ) );
				$out[ $name ] = is_string( $v ) ? $v : wp_json_encode( $v );
			}
		}
		return $out;
	}

	private static function client_ip(): string {
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = (string) $_SERVER[ $h ];
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				return substr( $ip, 0, 64 );
			}
		}
		return '';
	}

	/* ───────────────────────── Public introspection (for diag UI) ───────────────────────── */

	public static function current(): ?array {
		return self::$current;
	}

	public static function canonical_map(): array {
		return self::$canonical_map;
	}

	public static function legacy_map(): array {
		return self::$legacy_map;
	}
}
