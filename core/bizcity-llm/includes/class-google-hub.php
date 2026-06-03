<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity_Google_Hub — Canonical 1-API for hub-based Google connection.
 *
 * All Google tools (Scheduler, Gmail, Drive, Sheets, Calendar, Docs, Slides,
 * Contacts) MUST go through this class to obtain a connect URL pointing to
 * the hub bizcity.vn following the pretty-URL pattern:
 *
 *   https://bizcity.vn/google-auth/connect
 *     ?blog_id=...&user_id=...&return_url=...
 *     &scopes=gmail_read,gmail_send&mode=shared_app
 *     &ts=...&sig=<HMAC-SHA256(blog|user|return|ts, AUTH_SALT)>
 *
 * Resolution order:
 *   1. BZGoogle_Google_OAuth (plugins/bizgpt-tool-google) — multisite native.
 *      Token stored in bzgoogle_accounts table on the hub site.
 *   2. BizCity_LLM_Client::google_auth_url() — REST fallback for open-source
 *      client sites (R-GW-8) where BZGoogle is NOT bundled.
 *
 * Anti-patterns CẤM:
 *   - Tự build URL kiểu add_query_arg('https://bizcity.vn/google-auth/connect', …)
 *     trong từng tool — phải dùng class này.
 *   - Lưu Client ID / Client Secret OAuth riêng cho từng site (per-site form).
 *
 * @since 2026-06-04
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Google_Hub {

	/**
	 * Catalog of services exposed to admin UI.
	 * key => [ label, icon, scope_group, description ]
	 *
	 * scope_group must match BZGoogle_Google_OAuth::SCOPE_GROUPS keys.
	 */
	const SERVICES = [
		'calendar' => [
			'label'    => 'Google Calendar',
			'icon'     => '📅',
			'scopes'   => 'calendar_read,calendar_write',
			'desc'     => 'Đồng bộ lịch & nhắc việc 2 chiều cho Scheduler.',
		],
		'gmail'    => [
			'label'    => 'Gmail',
			'icon'     => '📧',
			'scopes'   => 'gmail_read,gmail_send',
			'desc'     => 'Đọc / gửi email cho Inbox AI và Lead Report.',
		],
		'drive'    => [
			'label'    => 'Google Drive',
			'icon'     => '🗂️',
			'scopes'   => 'drive_read,drive_write',
			'desc'     => 'Upload tài liệu & ảnh sinh từ Twin Brain.',
		],
		'sheets'   => [
			'label'    => 'Google Sheets',
			'icon'     => '📊',
			'scopes'   => 'sheets_write,drive_write',
			'desc'     => 'Xuất report định kỳ ra Sheets.',
		],
		'docs'     => [
			'label'    => 'Google Docs',
			'icon'     => '📝',
			'scopes'   => 'docs_write,drive_write',
			'desc'     => 'Lưu draft bài viết AI dưới dạng Google Doc.',
		],
		'slides'   => [
			'label'    => 'Google Slides',
			'icon'     => '🎞️',
			'scopes'   => 'slides_write,drive_write',
			'desc'     => 'Tạo presentation từ outline AI.',
		],
		'contacts' => [
			'label'    => 'Google Contacts',
			'icon'     => '👥',
			'scopes'   => 'contacts_read',
			'desc'     => 'Đồng bộ danh bạ với Lead pool.',
		],
	];

	/**
	 * Whether the BZGoogle bundled plugin is available on this site.
	 * Multisite installs ship BZGoogle on every blog; standalone open-source
	 * client sites won't.
	 *
	 * @return bool
	 */
	public static function is_bzgoogle_available() {
		return class_exists( 'BZGoogle_Google_OAuth', false )
			&& class_exists( 'BZGoogle_Token_Store', false );
	}

	/**
	 * Whether the bizcity-llm gateway client is configured (Bearer biz-xxx key).
	 *
	 * @return bool
	 */
	public static function is_llm_gateway_ready() {
		if ( ! class_exists( 'BizCity_LLM_Client', false ) ) {
			return false;
		}
		return BizCity_LLM_Client::instance()->is_ready();
	}

	/**
	 * Hub URL preview, e.g. "bizcity.vn".
	 *
	 * @return string
	 */
	public static function get_hub_domain() {
		if ( self::is_bzgoogle_available() ) {
			return BZGoogle_Google_OAuth::get_hub_domain();
		}
		if ( self::is_llm_gateway_ready() ) {
			$gw = BizCity_LLM_Client::instance()->get_gateway_url();
			$host = parse_url( $gw, PHP_URL_HOST );
			return $host ? $host : 'bizcity.vn';
		}
		return 'bizcity.vn';
	}

	/**
	 * Build the hub connect URL.
	 *
	 * @param array $args {
	 *   @type string       $return_url  Where the hub redirects back after consent.
	 *                                   Default: current admin Google Hub page.
	 *   @type string|array $scopes      Comma-separated scope keys or array.
	 *                                   Default: ['profile'] (basic identity).
	 *   @type bool         $upgrade     Mark as incremental scope upgrade.
	 *   @type int          $blog_id     Override blog_id (default: current).
	 *   @type int          $user_id     Override user_id (default: current).
	 * }
	 * @return string|WP_Error  Pretty hub URL, or WP_Error if no path available.
	 */
	public static function get_connect_url( array $args = [] ) {
		$return_url = isset( $args['return_url'] ) && $args['return_url']
			? $args['return_url']
			: admin_url( 'admin.php?page=bizchat-gateway&group=integrations&sub=google-hub' );

		$scopes = isset( $args['scopes'] ) ? $args['scopes'] : 'profile';
		if ( is_array( $scopes ) ) {
			$scopes = implode( ',', $scopes );
		}

		$payload = [
			'return_url' => $return_url,
			'scopes'     => $scopes,
		];
		if ( ! empty( $args['upgrade'] ) ) {
			$payload['upgrade'] = true;
		}
		if ( isset( $args['blog_id'] ) ) {
			$payload['blog_id'] = absint( $args['blog_id'] );
		}
		if ( isset( $args['user_id'] ) ) {
			$payload['user_id'] = absint( $args['user_id'] );
		}

		// Path 1 — BZGoogle bundled (multisite native, AUTH_SALT shared).
		if ( self::is_bzgoogle_available() ) {
			return BZGoogle_Google_OAuth::get_connect_url( $payload );
		}

		// Path 2 — REST fallback for standalone open-source clients.
		// The hub returns a Google consent URL directly (no pretty redirect),
		// but the eventual experience is identical for the end user.
		if ( self::is_llm_gateway_ready() ) {
			$resp = BizCity_LLM_Client::instance()->google_auth_url( [
				'redirect_uri' => $return_url,
				'scopes'       => $scopes,
			] );
			if ( is_array( $resp ) && ! empty( $resp['auth_url'] ) ) {
				return (string) $resp['auth_url'];
			}
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			return new WP_Error(
				'bizcity_google_hub_no_auth_url',
				__( 'Hub không trả về auth_url hợp lệ.', 'bizcity-twin-ai' )
			);
		}

		return new WP_Error(
			'bizcity_google_hub_unavailable',
			__( 'Chưa có đường kết nối Google nào khả dụng (cần BZGoogle plugin hoặc API key gateway).', 'bizcity-twin-ai' )
		);
	}

	/**
	 * Build a per-service scope upgrade URL (incremental authorization).
	 *
	 * @param string $service     One of self::SERVICES keys (gmail / calendar / …).
	 * @param string $return_url  Optional override.
	 * @return string|WP_Error
	 */
	public static function get_service_connect_url( $service, $return_url = '' ) {
		if ( ! isset( self::SERVICES[ $service ] ) ) {
			return new WP_Error(
				'bizcity_google_hub_unknown_service',
				sprintf( __( 'Service không xác định: %s', 'bizcity-twin-ai' ), $service )
			);
		}
		return self::get_connect_url( [
			'return_url' => $return_url,
			'scopes'     => self::SERVICES[ $service ]['scopes'],
			'upgrade'    => true,
		] );
	}

	/**
	 * Disconnect URL — removes the active token on hub.
	 *
	 * @return string  Empty string if disconnect not supported here.
	 */
	public static function get_disconnect_url() {
		if ( ! self::is_bzgoogle_available() ) {
			return '';
		}
		$hub = BZGoogle_Google_OAuth::get_hub_url();
		return add_query_arg( [
			'blog_id'    => get_current_blog_id(),
			'user_id'    => get_current_user_id(),
			'return_url' => admin_url( 'admin.php?page=bizchat-gateway&group=integrations&sub=google-hub' ),
		], $hub . '/google-auth/disconnect' );
	}

	/**
	 * Connection status snapshot for the current site/user.
	 *
	 * @return array {
	 *   @type bool   $connected     Has at least one active Google account.
	 *   @type string $email         Primary connected Google email (if any).
	 *   @type string $scope         Granted scope string.
	 *   @type array  $services      [ service_key => bool ] whether each scope group is granted.
	 *   @type string $path          'bzgoogle' | 'llm_gateway' | 'none'.
	 *   @type string $hub_domain    e.g. 'bizcity.vn'.
	 * }
	 */
	public static function get_status() {
		$out = [
			'connected'  => false,
			'email'      => '',
			'scope'      => '',
			'services'   => [],
			'path'       => 'none',
			'hub_domain' => self::get_hub_domain(),
		];

		foreach ( array_keys( self::SERVICES ) as $svc ) {
			$out['services'][ $svc ] = false;
		}

		if ( self::is_bzgoogle_available() ) {
			$out['path'] = 'bzgoogle';
			$blog_id = get_current_blog_id();
			$user_id = get_current_user_id();
			$accounts = BZGoogle_Token_Store::get_accounts( $blog_id, $user_id );
			if ( ! empty( $accounts ) ) {
				$primary = $accounts[0];
				$out['connected'] = true;
				$out['email']     = isset( $primary->google_email ) ? (string) $primary->google_email : '';
				$out['scope']     = isset( $primary->scope ) ? (string) $primary->scope : '';
				foreach ( array_keys( self::SERVICES ) as $svc ) {
					$out['services'][ $svc ] = BZGoogle_Google_OAuth::has_scope( $blog_id, $user_id, $svc );
				}
			}
			return $out;
		}

		if ( self::is_llm_gateway_ready() ) {
			$out['path'] = 'llm_gateway';
			if ( method_exists( 'BizCity_LLM_Client', 'google_status' ) ) {
				$resp = BizCity_LLM_Client::instance()->google_status();
				if ( is_array( $resp ) ) {
					$out['connected'] = ! empty( $resp['connected'] );
					$out['email']     = isset( $resp['email'] ) ? (string) $resp['email'] : '';
					$out['scope']     = isset( $resp['scope'] ) ? (string) $resp['scope'] : '';
				}
			}
			return $out;
		}

		return $out;
	}
}
