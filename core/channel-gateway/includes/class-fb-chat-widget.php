<?php
/**
 * BizCity Facebook Customer Chat Widget Auto-Injector
 *
 * Tự động inject Facebook Customer Chat SDK vào wp_footer (before </body>)
 * thay vì dán tay vào page builder (Flatsome, Elementor, v.v.)
 *
 * Settings per page_id stored in option `bizcity_cg_fb_widget_{page_id}`:
 *   enabled                   bool
 *   theme_color               #rrggbb  (default #0084ff)
 *   logged_in_greeting        string
 *   logged_out_greeting       string
 *   greeting_dialog_display   show|hide|fade
 *   greeting_dialog_delay     int 0-30 seconds
 *   locale                    vi_VN|en_US|...
 *   position                  bottom_right|bottom_left
 *   minimized                 bool
 *
 * REST (bizcity-channel/v1, require manage_options):
 *   GET  /facebook/chat-widget/{page_id}   — get settings
 *   POST /facebook/chat-widget/{page_id}   — save settings (enabling one disables others)
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      2026-06-12
 */

defined( 'ABSPATH' ) || exit;

class BizCity_FB_Chat_Widget {

	const OPT_PREFIX = 'bizcity_cg_fb_widget_';
	const NS         = 'bizcity-channel/v1';

	private static $instance = null;

	public static function instance(): self {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// [2026-06-12 Johnny Chu] HOTFIX — init REST + frontend injection hook.
	public static function init(): void {
		$self = self::instance();
		add_action( 'rest_api_init', [ $self, 'register_routes' ] );
		add_action( 'wp_footer',     [ $self, 'maybe_inject' ], 100 );
	}

	/* ─── REST routes ─── */

	public function register_routes(): void {
		$perm = [ $this, 'perm_admin' ];

		register_rest_route( self::NS, '/facebook/chat-widget/(?P<page_id>[A-Za-z0-9_-]+)', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_get' ),
				'permission_callback' => $perm,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save' ),
				'permission_callback' => $perm,
			),
		) );

		// [2026-06-12 Johnny Chu] HOTFIX — auto sync site_url() into FB whitelisted_domains via Graph API.
		register_rest_route( self::NS, '/facebook/chat-widget/(?P<page_id>[A-Za-z0-9_-]+)/sync-whitelist', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_sync_whitelist' ),
			'permission_callback' => $perm,
		) );

		// [2026-06-12 Johnny Chu] HOTFIX — debug/list endpoint: GET /facebook/chat-widget-list
		register_rest_route( self::NS, '/facebook/chat-widget-list', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_list' ),
			'permission_callback' => $perm,
		) );
	}

	public function perm_admin(): bool {
		return current_user_can( 'manage_options' );
	}

	public function rest_get( WP_REST_Request $req ) {
		$page_id = sanitize_text_field( (string) $req['page_id'] );
		return rest_ensure_response( $this->get_settings( $page_id ) );
	}

	// [2026-06-12 Johnny Chu] HOTFIX — list all widget configs for debug / admin overview.
	public function rest_list() {
		global $wpdb;
		$like = $wpdb->esc_like( self::OPT_PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row['option_value'] );
			if ( is_array( $data ) ) {
				$out[] = $data;
			}
		}
		return rest_ensure_response( array( 'configs' => $out, 'count' => count( $out ) ) );
	}

	// [2026-06-12 Johnny Chu] HOTFIX — auto-push site domain into FB whitelisted_domains via Graph API.
	// Uses page_access_token stored in wp_bizcity_facebook_bots for the given page_id.
	public function rest_sync_whitelist( WP_REST_Request $req ) {
		$page_id = sanitize_text_field( (string) $req['page_id'] );
		if ( $page_id === '' ) {
			return new WP_Error( 'invalid_param', 'page_id required', array( 'status' => 400 ) );
		}

		// Get page_access_token from bot DB.
		$token = $this->get_page_token( $page_id );
		if ( $token === '' ) {
			return new WP_Error(
				'token_missing',
				'Không tìm thấy page_access_token cho page này. Hãy kết nối OAuth trước.',
				array( 'status' => 400 )
			);
		}

		// [2026-06-12 Johnny Chu] HOTFIX — FB rejects URLs with trailing slashes (causes OAuthException).
		// Must be exactly: https://example.com (no trailing slash, no path, no port unless needed).
		$site  = untrailingslashit( site_url() );
		$home  = untrailingslashit( home_url() );
		// Strip to origin only (scheme + host) — FB only accepts domain-level entries.
		$site  = preg_replace( '#(https?://[^/]+).*#', '$1', $site );
		$home  = preg_replace( '#(https?://[^/]+).*#', '$1', $home );

		$new_domains = array_values( array_unique( array_filter( array( $site, $home ) ) ) );

		// Allow filter for custom extra domains.
		$new_domains = (array) apply_filters( 'bizcity_fb_widget_whitelist_domains', $new_domains, $page_id );

		// [2026-06-12 Johnny Chu] HOTFIX — GET current list first, merge to avoid overwriting other entries.
		$get_response = wp_remote_get(
			add_query_arg(
				array(
					'fields'       => 'whitelisted_domains',
					'access_token' => $token,
				),
				'https://graph.facebook.com/v18.0/me/messenger_profile'
			),
			array( 'timeout' => 10 )
		);
		$existing_domains = array();
		if ( ! is_wp_error( $get_response ) ) {
			$get_body = json_decode( wp_remote_retrieve_body( $get_response ), true );
			if ( isset( $get_body['data'][0]['whitelisted_domains'] ) ) {
				$existing_domains = (array) $get_body['data'][0]['whitelisted_domains'];
			}
		}

		$domains = array_values( array_unique( array_merge( $existing_domains, $new_domains ) ) );

		// [2026-06-12 Johnny Chu] HOTFIX — use ?access_token= query param instead of Authorization
		// Bearer header; more reliable for Facebook Graph API messenger_profile endpoint.
		$response = wp_remote_post(
			add_query_arg( array( 'access_token' => $token ), 'https://graph.facebook.com/v18.0/me/messenger_profile' ),
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'whitelisted_domains' => $domains ) ),
			)
		);

		// [2026-06-12 Johnny Chu] HOTFIX — R-GW-8: never return HTTP 5xx; Cloudflare intercepts
		// and replaces the response with its own 502 error page. Always return HTTP 200.
		if ( is_wp_error( $response ) ) {
			return rest_ensure_response( array(
				'success' => false,
				'error'   => 'http_error',
				'message' => $response->get_error_message(),
			) );
		}

		$code    = (int) wp_remote_retrieve_response_code( $response );
		$fb_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code !== 200 || empty( $fb_body['result'] ) ) {
			$fb_msg = isset( $fb_body['error']['message'] ) ? (string) $fb_body['error']['message'] : 'Facebook trả về lỗi.';
			$fb_sub = isset( $fb_body['error']['error_subcode'] ) ? (int) $fb_body['error']['error_subcode'] : 0;
			return rest_ensure_response( array(
				'success'    => false,
				'error'      => 'fb_api_error',
				'message'    => $fb_msg,
				'fb_code'    => $code,
				'fb_subcode' => $fb_sub,
				'fb_type'    => $fb_body['error']['type'] ?? '',
			) );
		}

		return rest_ensure_response( array(
			'success' => true,
			'domains' => $domains,
			'result'  => isset( $fb_body['result'] ) ? $fb_body['result'] : 'success',
		) );
	}

	/**
	 * Retrieve page_access_token for a page_id from bot DB or registry.
	 */
	private function get_page_token( string $page_id ): string {
		// 1) Try bot DB table.
		if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_facebook_bots';
			$token = $wpdb->get_var( $wpdb->prepare(
				"SELECT page_access_token FROM {$table}
				 WHERE page_id = %s AND status = 'active'
				 ORDER BY id DESC LIMIT 1",
				$page_id
			) );
			if ( ! empty( $token ) ) {
				return (string) $token;
			}
		}

		// 2) Try integration registry (channel accounts).
		if ( class_exists( 'BizCity_Integration_Registry' ) ) {
			$accounts = BizCity_Integration_Registry::instance()->get_channel_accounts( 'facebook' );
			foreach ( (array) $accounts as $a ) {
				if ( (string) ( $a['page_id'] ?? '' ) === $page_id && ! empty( $a['page_access_token'] ) ) {
					return (string) $a['page_access_token'];
				}
			}
		}

		return '';
	}

	public function rest_save( WP_REST_Request $req ) {
		// [2026-06-12 Johnny Chu] HOTFIX — enabling one page widget disables all others
		// so only one chat bubble shows sitewide.
		$page_id = sanitize_text_field( (string) $req['page_id'] );

		// [2026-06-12 Johnny Chu] HOTFIX — get_json_params() returns null when Content-Type
		// is stripped by proxy/CDN/security plugin. Fallback to raw body parse.
		$body = $req->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			$raw = (string) $req->get_body();
			if ( $raw ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$body = $decoded;
				}
			}
		}
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$current = $this->get_settings( $page_id );

		if ( ! empty( $body['enabled'] ) ) {
			$this->disable_all_except( $page_id );
		}

		$patch = $this->sanitize_settings( $body, $current );
		update_option( self::OPT_PREFIX . $page_id, $patch, false );

		// Return saved value + confirmation so FE can verify.
		return rest_ensure_response( array_merge( $patch, array( '_saved' => true ) ) );
	}

	/* ─── Frontend injection ─── */

	/**
	 * Hook: wp_footer (priority 100).
	 * Injects the FB Customer Chat SDK if any page has the widget enabled.
	 */
	// [2026-06-12 Johnny Chu] HOTFIX — switched from FB Customer Chat SDK (connect.facebook.net
	// returning HTTP 500) to a simple floating m.me anchor. No external JS dependency.
	public function maybe_inject(): void {
		if ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$settings = $this->get_any_enabled();

		echo '<!-- bzc-fb-widget: ' . ( $settings ? 'active page_id=' . esc_html( $settings['page_id'] ) : 'loaded, no enabled config' ) . " -->\n";

		if ( ! $settings || empty( $settings['page_id'] ) ) {
			return;
		}

		$page_id  = (string) $settings['page_id'];
		$color    = sanitize_hex_color( $settings['theme_color'] ?? '' ) ?: '#0084ff';
		$position = ( ( $settings['position'] ?? '' ) === 'bottom_left' ) ? 'left' : 'right';
		$label    = ! empty( $settings['logged_out_greeting'] ) ? esc_html( $settings['logged_out_greeting'] ) : '💬 Chat Messenger';
		$mme_url  = 'https://m.me/' . rawurlencode( $page_id );

		// Inline CSS vars so color/position are controlled by settings without a separate stylesheet.
		$bg_rgb   = $this->hex_to_rgb( $color );
		$shadow   = 'rgba(' . $bg_rgb . ',.45)';
		?>
<style id="bzc-fb-float-css">
.bzc-fb-float{
  position:fixed;
  <?php echo esc_attr( $position ); ?>:22px;
  bottom:22px;
  background:<?php echo esc_attr( $color ); ?>;
  color:#fff;
  padding:13px 20px;
  border-radius:999px;
  font-weight:600;
  text-decoration:none;
  z-index:999999;
  box-shadow:0 8px 28px <?php echo esc_attr( $shadow ); ?>;
  font-family:Arial,sans-serif;
  font-size:15px;
  line-height:1;
  display:inline-flex;
  align-items:center;
  gap:8px;
  transition:transform .15s,box-shadow .15s;
}
.bzc-fb-float:hover{
  transform:translateY(-2px);
  box-shadow:0 12px 32px <?php echo esc_attr( $shadow ); ?>;
  color:#fff;
  text-decoration:none;
}
</style>
<a class="bzc-fb-float"
   href="<?php echo esc_url( $mme_url ); ?>"
   target="_blank"
   rel="noopener noreferrer"
   aria-label="Chat trên Messenger">
  <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 48 48" fill="none" aria-hidden="true" focusable="false" style="flex-shrink:0;display:block">
    <circle cx="24" cy="24" r="24" fill="#fff" fill-opacity=".18"/>
    <path d="M24 6C14.06 6 6 13.47 6 22.67c0 5.13 2.55 9.7 6.55 12.74V42l5.9-3.25c1.57.44 3.24.67 4.97.67 9.94 0 18-7.47 18-16.67S33.94 6 24 6zm1.82 22.43-4.63-4.94-9.03 4.94 9.93-10.55 4.74 4.94 8.93-4.94-9.94 10.55z" fill="#fff"/>
  </svg>
  <?php echo $label; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</a>
		<?php
	}

	/**
	 * Convert #rrggbb to "r,g,b" for rgba().
	 */
	private function hex_to_rgb( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return $r . ',' . $g . ',' . $b;
	}

	/* ─── Option helpers ─── */

	public function get_settings( string $page_id ): array {
		$v = get_option( self::OPT_PREFIX . $page_id );
		if ( ! is_array( $v ) ) {
			$v = array();
		}
		return array_merge( $this->defaults( $page_id ), $v );
	}

	private function defaults( string $page_id ): array {
		return array(
			'page_id'                 => $page_id,
			'enabled'                 => false,
			'theme_color'             => '#0084ff',
			'logged_in_greeting'      => 'Xin chào! Tôi có thể giúp gì cho bạn?',
			'logged_out_greeting'     => 'Xin chào! Tôi có thể giúp gì cho bạn?',
			'greeting_dialog_display' => 'show',
			'greeting_dialog_delay'   => 0,
			'locale'                  => 'vi_VN',
			'position'                => 'bottom_right',
			'minimized'               => false,
		);
	}

	private function sanitize_settings( array $in, array $current ): array {
		$out = $current;
		if ( isset( $in['enabled'] ) ) {
			$out['enabled'] = (bool) $in['enabled'];
		}
		if ( isset( $in['theme_color'] ) ) {
			$out['theme_color'] = sanitize_hex_color( $in['theme_color'] ) ?: '#0084ff';
		}
		if ( isset( $in['logged_in_greeting'] ) ) {
			$out['logged_in_greeting'] = sanitize_textarea_field( $in['logged_in_greeting'] );
		}
		if ( isset( $in['logged_out_greeting'] ) ) {
			$out['logged_out_greeting'] = sanitize_textarea_field( $in['logged_out_greeting'] );
		}
		if ( isset( $in['greeting_dialog_display'] ) ) {
			$out['greeting_dialog_display'] = in_array( $in['greeting_dialog_display'], array( 'show', 'hide', 'fade' ), true )
				? $in['greeting_dialog_display']
				: 'show';
		}
		if ( isset( $in['greeting_dialog_delay'] ) ) {
			$out['greeting_dialog_delay'] = max( 0, min( 30, (int) $in['greeting_dialog_delay'] ) );
		}
		if ( isset( $in['locale'] ) ) {
			$out['locale'] = sanitize_text_field( $in['locale'] );
		}
		if ( isset( $in['position'] ) ) {
			$out['position'] = in_array( $in['position'], array( 'bottom_right', 'bottom_left' ), true )
				? $in['position']
				: 'bottom_right';
		}
		if ( isset( $in['minimized'] ) ) {
			$out['minimized'] = (bool) $in['minimized'];
		}
		return $out;
	}

	/**
	 * Find first enabled widget config across all pages.
	 */
	public function get_any_enabled(): ?array {
		global $wpdb;
		$prefix = self::OPT_PREFIX;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC", $like ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row['option_value'] );
			if ( is_array( $data ) && ! empty( $data['enabled'] ) && ! empty( $data['page_id'] ) ) {
				return $data;
			}
		}
		return null;
	}

	/**
	 * Disable widget on every page except $keep_page_id.
	 */
	private function disable_all_except( string $keep_page_id ): void {
		global $wpdb;
		$prefix = self::OPT_PREFIX;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		$rows   = $wpdb->get_results(
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row['option_value'] );
			if ( ! is_array( $data ) ) {
				continue;
			}
			$pid = (string) ( $data['page_id'] ?? '' );
			if ( $pid === $keep_page_id || $pid === '' ) {
				continue;
			}
			if ( ! empty( $data['enabled'] ) ) {
				$data['enabled'] = false;
				update_option( $row['option_name'], $data, false );
			}
		}
	}
}

BizCity_FB_Chat_Widget::init();
