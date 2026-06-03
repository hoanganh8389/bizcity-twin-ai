<?php
/**
 * BizCity Facebook OAuth — Central OAuth Flow
 *
 * Flow:
 *   1. Subsite admin clicks "Kết nối Facebook" → redirects to main site:
 *      bizcity.vn/?biz_fb_oauth=start&blog_id=42&_nonce=xxx
 *
 *   2. Main site builds Facebook OAuth URL:
 *      - App ID from get_site_option('bizcity_fb_app_id')
 *      - redirect_uri = bizcity.vn/?biz_fb_oauth=callback
 *      - state = encrypted(blog_id + user_id + nonce)
 *
 *   3. Facebook redirects back to:
 *      bizcity.vn/?biz_fb_oauth=callback&code=xxx&state=xxx
 *
 *   4. Main site exchanges code → user token → /me/accounts → page tokens
 *      - Saves pages to fb_pages_connected on target blog
 *      - Registers routes in global wp_bizcity_facebook_page_routes
 *      - Redirects back to subsite admin with status
 *
 * Advantages over legacy:
 *   - Only 1 redirect_uri (main site) → 1 domain in Facebook App
 *   - App ID/Secret from network options (no per-site config)
 *   - Auto-registers central webhook routes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Facebook_OAuth {

	private static $instance = null;

	const FB_GRAPH_VERSION = 'v18.0';

	const SCOPES = [
		'pages_show_list',
		'pages_manage_posts',
		'pages_manage_engagement',
		'pages_manage_metadata',
		'pages_read_engagement',
		'pages_read_user_content',
		'pages_messaging',
		'pages_messaging_subscriptions',
		// BizCity PHASE 0.31 Sprint 6 — required to query /me/businesses and
		// pull Pages assigned via Business Portfolio (Business Manager). Without
		// this, /me/businesses returns "(#100) Missing Permission" and Pages
		// owned by a Business (not by personal account) are invisible.
		'business_management',
		'public_profile',
	];

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init', [ $this, 'handle_oauth_request' ], 1 );
	}

	/**
	 * Route OAuth requests based on ?biz_fb_oauth= parameter.
	 */
	public function handle_oauth_request() {
		if ( ! isset( $_GET['biz_fb_oauth'] ) ) {
			return;
		}

		$action = sanitize_text_field( $_GET['biz_fb_oauth'] );

		switch ( $action ) {
			case 'start':
				$this->handle_start();
				break;
			case 'user_start':
				$this->handle_user_start();
				break;
			case 'callback':
				$this->handle_callback();
				break;
			default:
				return;
		}
	}

	// ==========================================
	// STEP 1: START — Redirect to Facebook OAuth
	// ==========================================

	/**
	 * Initiate OAuth flow from a subsite.
	 * Can be called on ANY site in the multisite — redirects to main site if needed.
	 *
	 * URL: {any_site}/?biz_fb_oauth=start
	 */
	private function handle_start() {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền thực hiện thao tác này.', 'Lỗi quyền truy cập', [ 'response' => 403 ] );
		}

		$app_id = $this->get_app_id();
		if ( empty( $app_id ) ) {
			wp_die(
				'Chưa cấu hình Facebook App ID. Vui lòng liên hệ Super Admin để cấu hình tại Network Admin → Facebook Central.',
				'Thiếu cấu hình',
				[ 'response' => 400 ]
			);
		}

		$blog_id = get_current_blog_id();
		$user_id = get_current_user_id();

		// Build state: contains blog_id + user_id + hmac for verification
		$state = $this->encode_state( $blog_id, $user_id );

		// Save state to transient for verification (5 min TTL)
		set_site_transient( 'biz_fb_oauth_' . $this->state_key( $state ), [
			'blog_id' => $blog_id,
			'user_id' => $user_id,
			'time'    => time(),
		], 5 * MINUTE_IN_SECONDS );

		// redirect_uri ALWAYS points to main site
		$redirect_uri = $this->get_callback_url();
		$scopes       = implode( ',', self::SCOPES );

		$fb_url = sprintf(
			'https://www.facebook.com/%s/dialog/oauth?%s',
			self::FB_GRAPH_VERSION,
			http_build_query( [
				'client_id'     => $app_id,
				'redirect_uri'  => $redirect_uri,
				'scope'         => $scopes,
				'response_type' => 'code',
				'state'         => $state,
			] )
		);

		wp_redirect( $fb_url );
		exit;
	}

	// ==========================================
	// STEP 1B: USER START — OAuth with user's own app
	// ==========================================

	/**
	 * Initiate OAuth flow using user's own Facebook Developer App.
	 * URL: {site}/?biz_fb_oauth=user_start
	 */
	private function handle_user_start() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Bạn chưa đăng nhập.', 'Lỗi', [ 'response' => 403 ] );
		}

		$user_id = get_current_user_id();

		// 2026-05-25 — SINGLE SOURCE OF TRUTH: đọc trực tiếp từ option `bztfb_app_id` /
		// `bztfb_app_secret` (nơi "Lưu App Config" trong Channel-Gateway UI ghi vào).
		// Bỏ toàn bộ fallback (user_meta + WAIC integration) vì chúng giữ giá trị
		// cũ từ flow twinchat và ghi đè app_id người dùng vừa lưu.
		$app_id     = (string) get_option( 'bztfb_app_id', '' );
		$app_secret = (string) get_option( 'bztfb_app_secret', '' );
		$config_id  = '';

		// Fallback: multisite network-wide option (sub-site mà main site save).
		if ( $app_id === '' )     { $app_id     = (string) get_site_option( 'bztfb_app_id', '' ); }
		if ( $app_secret === '' ) { $app_secret = (string) get_site_option( 'bztfb_app_secret', '' ); }
		// Legacy keys (bizcity-facebook-bot original).
		if ( $app_id === '' )     { $app_id     = (string) get_site_option( 'bizcity_fb_app_id', '' ); }
		if ( $app_secret === '' ) { $app_secret = (string) get_site_option( 'bizcity_fb_app_secret', '' ); }
		if ( $app_id === '' )     { $app_id     = (string) get_option( 'fb_app_id', '' ); }
		if ( $app_secret === '' ) { $app_secret = (string) get_option( 'fb_app_secret', '' ); }

		if ( empty( $app_id ) || empty( $app_secret ) ) {
			error_log( sprintf(
				'[BizCity FB OAuth] user_start MISSING credentials blog=%d user=%d app_id_len=%d app_secret_len=%d',
				get_current_blog_id(), $user_id, strlen( $app_id ), strlen( $app_secret )
			) );
			wp_die(
				'Chưa cấu hình Facebook App ID/Secret. Vào admin → BizCity Channel Gateway → Facebook → Cài đặt → Lưu App Config.<br><br>'
				. '<small>Debug: blog_id=' . get_current_blog_id() . ', option_bztfb_app_id=' . esc_html( (string) get_option( 'bztfb_app_id', '(empty)' ) )
				. ', site_option=' . esc_html( (string) get_site_option( 'bztfb_app_id', '(empty)' ) ) . '</small>',
				'Thiếu cấu hình',
				[ 'response' => 400 ]
			);
		}

		$blog_id = get_current_blog_id();

		// state includes app_source=user to distinguish from admin flow
		$state = $this->encode_state( $blog_id, $user_id, 'user' );

		// BizCity PHASE 0.31 Sprint 6 — stash the resolved app_id/app_secret
		// in the transient so handle_callback() can reuse them without a second
		// WAIC lookup (state has no num and user_meta may still be empty).
		set_site_transient( 'biz_fb_oauth_' . $this->state_key( $state ), [
			'blog_id'    => $blog_id,
			'user_id'    => $user_id,
			'app_source' => 'user',
			'app_id'     => $app_id,
			'app_secret' => $app_secret,
			'config_id'  => $config_id,
			'time'       => time(),
		], 5 * MINUTE_IN_SECONDS );

		// User's own app → redirect_uri = current site (user must configure this in their app)
		$redirect_uri = home_url( '/?biz_fb_oauth=callback' );
		$scopes       = implode( ',', self::SCOPES );

		// BizCity PHASE 0.31 Sprint 6 — Facebook Login for Business (Business
		// app type) IGNORES the scope= parameter and grants only public_profile
		// unless the request includes config_id pointing to a Login Configuration
		// (created in App Dashboard → Use cases → Facebook Login for Business →
		// Configurations). With config_id, FB uses the configuration's pre-defined
		// permissions/assets and pulls Pages correctly.
		$oauth_args = [
			'client_id'     => $app_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'state'         => $state,
			// BizCity PHASE 0.31 Sprint 6 — force Facebook to re-show the
			// Asset Selector (Page picker) every time. Without this, FB caches
			// previous consent and silently skips Page selection → /me/accounts
			// returns [] even though pages_show_list is granted.
			'auth_type'     => 'rerequest',
		];
		if ( ! empty( $config_id ) ) {
			$oauth_args['config_id'] = $config_id;
		} else {
			// Classic Facebook Login fallback (only works for non-Business apps)
			$oauth_args['scope'] = $scopes;
		}

		$fb_url = sprintf(
			'https://www.facebook.com/%s/dialog/oauth?%s',
			self::FB_GRAPH_VERSION,
			http_build_query( $oauth_args )
		);

		error_log( sprintf(
			'[BizCity FB OAuth] user_start: app_id_len=%d, secret_len=%d, config_id=%s, mode=%s',
			strlen( (string) $app_id ), strlen( (string) $app_secret ),
			$config_id ?: '(empty)',
			! empty( $config_id ) ? 'config_id (FLB)' : 'scope= (classic Login)'
		) );

		wp_redirect( $fb_url );
		exit;
	}

	// ==========================================
	// STEP 2: CALLBACK — Exchange code & save pages
	// ==========================================

	/**
	 * Handle Facebook OAuth callback.
	 * Supports both admin flow (hub site) and user flow (per-site).
	 *
	 * URL: {site}/?biz_fb_oauth=callback&code=xxx&state=xxx
	 */
	private function handle_callback() {
		$code  = sanitize_text_field( $_GET['code'] ?? '' );
		$state = sanitize_text_field( $_GET['state'] ?? '' );
		$error = sanitize_text_field( $_GET['error'] ?? '' );

		// User cancelled
		if ( ! empty( $error ) ) {
			$this->redirect_with_error( 0, 'Facebook authorization cancelled: ' . $error );
			return;
		}

		if ( empty( $code ) || empty( $state ) ) {
			wp_die( 'Missing OAuth code or state.', 'OAuth Error', [ 'response' => 400 ] );
		}

		// Decode & verify state
		$state_data = $this->decode_state( $state );
		if ( ! $state_data ) {
			wp_die( 'Invalid or expired OAuth state.', 'OAuth Error', [ 'response' => 400 ] );
		}

		$blog_id = (int) $state_data['blog_id'];
		$user_id = (int) $state_data['user_id'];

		// Verify transient exists
		$transient_key = 'biz_fb_oauth_' . $this->state_key( $state );
		$saved = get_site_transient( $transient_key );
		if ( ! $saved || (int) $saved['blog_id'] !== $blog_id || (int) $saved['user_id'] !== $user_id ) {
			wp_die( 'OAuth state verification failed. Please try again.', 'OAuth Error', [ 'response' => 400 ] );
		}
		delete_site_transient( $transient_key );

		// Determine app credentials based on flow source
		$app_source = $saved['app_source'] ?? 'admin';
		if ( $app_source === 'user' ) {
			// Prefer the credentials we stashed at start (covers both user_meta
			// and WAIC "Tích hợp bên ngoài" rows). Fall back to user_meta for
			// older transients that pre-date the stash.
			$app_id     = ! empty( $saved['app_id'] )     ? $saved['app_id']     : get_user_meta( $user_id, 'bztfb_user_app_id', true );
			$app_secret = ! empty( $saved['app_secret'] ) ? $saved['app_secret'] : get_user_meta( $user_id, 'bztfb_user_app_secret', true );

			// BizCity PHASE 0.31 Sprint 6 — last-resort WAIC fallback inside
			// the callback in case neither transient nor user_meta carry creds
			// (e.g. transient stash from older deploy / opcache lag).
			if ( ( empty( $app_id ) || empty( $app_secret ) ) && class_exists( 'WaicFrame' ) ) {
				try {
					$model = WaicFrame::_()->getModule( 'workflow' )->getModel( 'integrations' );
					$accs  = $model->getSavedIntegrations( 'facebook' );
					if ( is_array( $accs ) && ! empty( $accs ) ) {
						$first = reset( $accs );
						$integ = $model->getIntegration( 'facebook', $first );
						if ( $integ ) {
							$p = $integ->getDecryptedParams( true );
							if ( empty( $app_id )     && ! empty( $p['app_id'] ) )     $app_id     = $p['app_id'];
							if ( empty( $app_secret ) && ! empty( $p['app_secret'] ) ) $app_secret = $p['app_secret'];
						}
					}
				} catch ( \Throwable $e ) { /* fall through */ }
			}

			// User flow: use home_url() (guaranteed absolute URI with scheme)
			$callback_url = home_url( '/?biz_fb_oauth=callback' );
		} else {
			$app_id     = $this->get_app_id();
			$app_secret = $this->get_app_secret();
			$callback_url = $this->get_callback_url();
		}

		// BizCity PHASE 0.31 Sprint 6 — diagnostic log so we can see exactly
		// why the callback fails on production. Safe: only logs presence
		// (lengths), never the actual secret value.
		if ( function_exists( 'error_log' ) ) {
			error_log( sprintf(
				'[BizCity FB OAuth] callback: app_source=%s, blog_id=%d, user_id=%d, app_id_len=%d, secret_len=%d, transient_has_creds=%s, code_len=%d',
				$app_source, $blog_id, $user_id,
				strlen( (string) $app_id ), strlen( (string) $app_secret ),
				( ! empty( $saved['app_id'] ) ? 'yes' : 'no' ),
				strlen( (string) $code )
			) );
		}

		if ( empty( $app_id ) || empty( $app_secret ) ) {
			$this->redirect_with_error( $blog_id, 'Missing Facebook App credentials.', $app_source );
			return;
		}

		// Exchange code for access token
		$token_url = sprintf( 'https://graph.facebook.com/%s/oauth/access_token', self::FB_GRAPH_VERSION );
		$response = wp_remote_get( add_query_arg( [
			'client_id'     => $app_id,
			'client_secret' => $app_secret,
			'redirect_uri'  => $callback_url,
			'code'          => $code,
		], $token_url ), [
			'timeout'   => 30,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			$this->redirect_with_error( $blog_id, 'Lỗi kết nối Facebook: ' . $response->get_error_message(), $app_source );
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$http_code = (int) wp_remote_retrieve_response_code( $response );

		if ( $http_code !== 200 || empty( $body['access_token'] ) ) {
			$msg = $body['error']['message'] ?? 'Unknown error (HTTP ' . $http_code . ')';
			error_log( '[BizCity FB OAuth] token-exchange FAIL http=' . $http_code . ' body=' . wp_remote_retrieve_body( $response ) );
			$this->redirect_with_error( $blog_id, 'Facebook token error: ' . $msg, $app_source );
			return;
		}

		$user_access_token = $body['access_token'];
		error_log( '[BizCity FB OAuth] token OK len=' . strlen( $user_access_token ) );

		// Exchange for long-lived token (60 days)
		$ll_response = wp_remote_get( add_query_arg( [
			'grant_type'        => 'fb_exchange_token',
			'client_id'         => $app_id,
			'client_secret'     => $app_secret,
			'fb_exchange_token' => $user_access_token,
		], $token_url ), [ 'timeout' => 30 ] );

		if ( ! is_wp_error( $ll_response ) ) {
			$ll_body = json_decode( wp_remote_retrieve_body( $ll_response ), true );
			if ( ! empty( $ll_body['access_token'] ) ) {
				$user_access_token = $ll_body['access_token'];
			}
		}

		// Get user info
		$me_url = sprintf( 'https://graph.facebook.com/%s/me?fields=id,name,email&access_token=%s',
			self::FB_GRAPH_VERSION, urlencode( $user_access_token )
		);
		$me_response = wp_remote_get( $me_url, [ 'timeout' => 15 ] );
		$me_data = [];
		if ( ! is_wp_error( $me_response ) ) {
			$me_data = json_decode( wp_remote_retrieve_body( $me_response ), true ) ?: [];
		}
		error_log( '[BizCity FB OAuth] /me?fields=id,name,email response: ' . substr( wp_remote_retrieve_body( $me_response ), 0, 500 ) );

		// BizCity PHASE 0.31 Sprint 6 — log granted permissions to diagnose
		// why /me/accounts returns []. If pages_show_list is not in granted
		// list → user only granted public_profile in OAuth dialog (silently
		// stripped page perms) → need to re-auth and tick page permissions.
		$perms_url = sprintf( 'https://graph.facebook.com/%s/me/permissions?access_token=%s',
			self::FB_GRAPH_VERSION, urlencode( $user_access_token )
		);
		$perms_resp = wp_remote_get( $perms_url, [ 'timeout' => 15 ] );
		if ( ! is_wp_error( $perms_resp ) ) {
			error_log( '[BizCity FB OAuth] /me/permissions response: ' . substr( wp_remote_retrieve_body( $perms_resp ), 0, 1000 ) );
		}

		// Get pages managed by this user.
		// BizCity PHASE 0.31 Sprint 6 — Facebook Login for Business (FLB)
		// returns granted Pages via different endpoints depending on whether
		// the asset was selected directly or assigned through Business Manager.
		// Try in order:
		//   1) /me/accounts                   — classic Facebook Login
		//   2) /me?fields=accounts{...}       — sometimes works when (1) empty
		//   3) /me/businesses{owned_pages,client_pages} — Business Manager assets
		// Stop at first non-empty result.
		$pages_collected = array();

		$pages_url = sprintf(
			'https://graph.facebook.com/%s/me/accounts?fields=id,name,access_token,category&limit=100&access_token=%s',
			self::FB_GRAPH_VERSION, urlencode( $user_access_token )
		);
		$pages_response = wp_remote_get( $pages_url, [ 'timeout' => 30 ] );

		if ( is_wp_error( $pages_response ) ) {
			$this->redirect_with_error( $blog_id, 'Lỗi lấy danh sách Fanpage: ' . $pages_response->get_error_message(), $app_source );
			return;
		}

		$pages_data = json_decode( wp_remote_retrieve_body( $pages_response ), true );
		error_log( '[BizCity FB OAuth] /me/accounts response: ' . substr( wp_remote_retrieve_body( $pages_response ), 0, 500 ) );

		if ( isset( $pages_data['error'] ) ) {
			$this->redirect_with_error( $blog_id, 'Facebook API: ' . ( $pages_data['error']['message'] ?? 'Unknown error' ), $app_source );
			return;
		}
		if ( ! empty( $pages_data['data'] ) ) {
			$pages_collected = $pages_data['data'];
		}

		// Fallback A: /me?fields=accounts{...}
		if ( empty( $pages_collected ) ) {
			$alt_url = sprintf(
				'https://graph.facebook.com/%s/me?fields=accounts.limit(100){id,name,access_token,category}&access_token=%s',
				self::FB_GRAPH_VERSION, urlencode( $user_access_token )
			);
			$alt_resp = wp_remote_get( $alt_url, [ 'timeout' => 30 ] );
			if ( ! is_wp_error( $alt_resp ) ) {
				$alt_body = wp_remote_retrieve_body( $alt_resp );
				error_log( '[BizCity FB OAuth] /me?fields=accounts response: ' . substr( $alt_body, 0, 500 ) );
				$alt_data = json_decode( $alt_body, true );
				if ( ! empty( $alt_data['accounts']['data'] ) ) {
					$pages_collected = $alt_data['accounts']['data'];
				}
			}
		}

		// Fallback B: /me/businesses → owned_pages + client_pages (Business Login)
		if ( empty( $pages_collected ) ) {
			$biz_url = sprintf(
				'https://graph.facebook.com/%s/me/businesses?fields=id,name,owned_pages.limit(100){id,name,access_token,category},client_pages.limit(100){id,name,access_token,category}&access_token=%s',
				self::FB_GRAPH_VERSION, urlencode( $user_access_token )
			);
			$biz_resp = wp_remote_get( $biz_url, [ 'timeout' => 30 ] );
			if ( ! is_wp_error( $biz_resp ) ) {
				$biz_body = wp_remote_retrieve_body( $biz_resp );
				error_log( '[BizCity FB OAuth] /me/businesses response: ' . substr( $biz_body, 0, 800 ) );
				$biz_data = json_decode( $biz_body, true );
				if ( ! empty( $biz_data['data'] ) ) {
					foreach ( $biz_data['data'] as $biz ) {
						foreach ( array( 'owned_pages', 'client_pages' ) as $bucket ) {
							if ( ! empty( $biz[ $bucket ]['data'] ) ) {
								foreach ( $biz[ $bucket ]['data'] as $pg ) {
									if ( ! empty( $pg['id'] ) && ! empty( $pg['access_token'] ) ) {
										$pages_collected[] = $pg;
									}
								}
							}
						}
					}
				}
			}
		}

		// Fallback C: /me/assigned_pages (granular per-user asset assignment in newer FLB)
		if ( empty( $pages_collected ) ) {
			$asg_url = sprintf(
				'https://graph.facebook.com/%s/me/assigned_pages?fields=id,name,access_token,category&limit=100&access_token=%s',
				self::FB_GRAPH_VERSION, urlencode( $user_access_token )
			);
			$asg_resp = wp_remote_get( $asg_url, [ 'timeout' => 30 ] );
			if ( ! is_wp_error( $asg_resp ) ) {
				$asg_body = wp_remote_retrieve_body( $asg_resp );
				error_log( '[BizCity FB OAuth] /me/assigned_pages response: ' . substr( $asg_body, 0, 500 ) );
				$asg_data = json_decode( $asg_body, true );
				if ( ! empty( $asg_data['data'] ) ) {
					$pages_collected = $asg_data['data'];
				}
			}
		}

		// Dedupe by page id
		if ( ! empty( $pages_collected ) ) {
			$seen = array();
			$dedup = array();
			foreach ( $pages_collected as $pg ) {
				if ( empty( $pg['id'] ) || isset( $seen[ $pg['id'] ] ) ) continue;
				$seen[ $pg['id'] ] = true;
				$dedup[] = $pg;
			}
			$pages_collected = $dedup;
		}

		if ( empty( $pages_collected ) ) {
			$user_name = $me_data['name'] ?? 'Unknown';
			error_log( '[BizCity FB OAuth] ALL endpoints returned ZERO pages for user_name=' . $user_name );
			$msg = sprintf(
				'Tài khoản %s đã liên kết app nhưng KHÔNG tìm thấy Fanpage qua /me/accounts, /me/businesses, hay /me/assigned_pages. '
				. 'Nguyên nhân thường gặp với Facebook Login for Business: '
				. '(A) App của bạn đang ở dạng "Login for Business" mà chưa cấu hình Login Configuration với asset Pages → vào Meta App Dashboard → Use Cases → Facebook Login for Business → bật asset "Pages" và scopes pages_show_list, pages_read_engagement, pages_manage_posts, pages_messaging. '
				. '(B) Page chưa được add vào Business Portfolio chứa app → vào business.facebook.com → Pages → Add. '
				. '(C) Bạn chưa tick Page ở bước "Chỉnh sửa quyền" của dialog OAuth.',
				$user_name
			);
			$this->redirect_with_error( $blog_id, $msg, $app_source );
			return;
		}
		// Map Business Manager pages to /me/accounts shape
		$pages_data = array( 'data' => $pages_collected );

		// Save pages to target blog
		$pages_clean = [];
		foreach ( $pages_data['data'] as $page ) {
			$pages_clean[] = [
				'id'           => $page['id'],
				'name'         => $page['name'],
				'access_token' => $page['access_token'],
				'category'     => $page['category'] ?? '',
			];
		}

		switch_to_blog( $blog_id );

		if ( $app_source === 'user' ) {
			// Plan B: Save pages as user-owned bots in bizcity_facebook_bots
			$db = BizCity_Facebook_Bot_Database::instance();
			$inserted = 0; $updated = 0; $errors = array();
			foreach ( $pages_clean as $page ) {
				$existing = $db->get_bot_by_user_page( $user_id, $page['id'] );
				$bot_data = array(
					'bot_name'          => $page['name'],
					'page_id'           => $page['id'],
					'page_access_token' => $page['access_token'],
					'user_id'           => $user_id,
					'app_id'            => $app_id,
					'app_secret'        => $app_secret,
					'status'            => 'active',
				);
				global $wpdb;
				if ( $existing ) {
					$db->update_bot( $existing->id, $bot_data );
					$updated++;
				} else {
					$res = $db->insert_bot( $bot_data );
					if ( $res ) {
						$inserted++;
					} else {
						$errors[] = $page['id'] . ':' . ( $wpdb->last_error ?: 'unknown' );
					}
				}
			}
			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf(
					'[BizCity FB OAuth] save pages: blog=%d user=%d table=%sbizcity_facebook_bots pages=%d inserted=%d updated=%d errors=%s',
					$blog_id, $user_id, $GLOBALS['wpdb']->prefix,
					count( $pages_clean ), $inserted, $updated,
					$errors ? implode( '|', $errors ) : 'none'
				) );
			}
			// Also save to user meta as "user's default page"
			if ( ! empty( $pages_clean[0]['id'] ) ) {
				update_user_meta( $user_id, 'bztfb_user_page', $pages_clean[0]['id'] );
			}
		} else {
			// Admin flow: Save pages to site options (existing behavior)
			update_option( 'fb_user_token', $user_access_token );
			update_option( 'fb_pages_connected', $pages_clean );
		}

		restore_current_blog();

		// Register all pages in global route table
		if ( class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
			foreach ( $pages_clean as $page ) {
				BizCity_Facebook_Central_Webhook::register_route(
					$page['id'],
					$blog_id,
					$page['name'],
					$page['access_token']
				);
			}
		}

		// Redirect back to subsite with success
		if ( $app_source === 'user' ) {
			/**
			 * Fires after a successful user-mode OAuth, before the
			 * redirect back to the subsite. Used by Channel Gateway
			 * to copy the connected page into a pending integration
			 * account row.
			 *
			 * @param array $pages_clean List of pages: [{id,name,access_token,category}, ...]
			 * @param int   $blog_id     Target subsite blog id.
			 * @param int   $user_id     User id who initiated the flow.
			 * @param string $app_source 'user' (Phương án B).
			 */
			do_action( 'bizcity_fb_oauth_complete', $pages_clean, $blog_id, $user_id, $app_source );

			// User flow → redirect to /tool-facebook/ using home_url() for reliability
			$redirect_url = home_url( '/tool-facebook/' );
			$redirect_url = add_query_arg( array(
				'biz_fb_oauth_status' => 'success',
				'biz_fb_pages_count'  => count( $pages_clean ),
			), $redirect_url );

			// Channel Gateway pending hand-off: if the user started OAuth
			// from the gateway SPA, override the redirect to bounce back
			// into the gateway settings page so they see the result inline.
			$cg_redirect = apply_filters( 'bizcity_fb_oauth_user_redirect', '', $user_id, $pages_clean, $blog_id );
			if ( is_string( $cg_redirect ) && $cg_redirect !== '' ) {
				$redirect_url = $cg_redirect;
			}

			wp_redirect( $redirect_url );
			exit;
		}

		/** @see bizcity_fb_oauth_complete docs above. Fires for admin-mode (Phương án A) too. */
		do_action( 'bizcity_fb_oauth_complete', $pages_clean, $blog_id, $user_id, $app_source );

		$this->redirect_to_subsite( $blog_id, [
			'biz_fb_oauth_status' => 'success',
			'biz_fb_pages_count'  => count( $pages_clean ),
		] );
	}

	// ==========================================
	// HELPERS
	// ==========================================

	/**
	 * Get Facebook App ID from network option (fallback to per-site + constant).
	 */
	private function get_app_id(): string {
		$id = get_site_option( 'bizcity_fb_app_id', '' );
		if ( ! empty( $id ) ) return $id;

		$id = get_option( 'fb_app_id', '' );
		if ( ! empty( $id ) ) return $id;

		return defined( 'FB_APP_ID' ) ? FB_APP_ID : '';
	}

	/**
	 * Get Facebook App Secret from network option (fallback to per-site + constant).
	 */
	private function get_app_secret(): string {
		$secret = get_site_option( 'bizcity_fb_app_secret', '' );
		if ( ! empty( $secret ) ) return $secret;

		$secret = get_option( 'fb_app_secret', '' );
		if ( ! empty( $secret ) ) return $secret;

		return defined( 'FB_APP_SECRET' ) ? FB_APP_SECRET : '';
	}

	/**
	 * Build the OAuth callback URL (on Hub Site).
	 * Hub Site is configured in Network Admin → Facebook Central.
	 * Only this 1 domain needs to be registered in Facebook App → Valid OAuth Redirect URIs.
	 */
	private function get_callback_url(): string {
		if ( class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
			$hub_url = BizCity_Facebook_Central_Webhook::get_hub_site_url();
		} else {
			$hub_url = network_site_url();
		}
		return trailingslashit( $hub_url ) . '?biz_fb_oauth=callback';
	}

	/**
	 * Encode state parameter: blog_id + user_id + app_source + HMAC signature.
	 */
	private function encode_state( int $blog_id, int $user_id, string $app_source = 'admin' ): string {
		$payload = $blog_id . '|' . $user_id . '|' . $app_source . '|' . time();
		$sig = hash_hmac( 'sha256', $payload, $this->get_hmac_key() );
		return base64_encode( $payload . '|' . $sig );
	}

	/**
	 * Decode & verify state parameter.
	 */
	private function decode_state( string $state ): ?array {
		$decoded = base64_decode( $state, true );
		if ( ! $decoded ) return null;

		$parts = explode( '|', $decoded );
		// Support both old (4 parts) and new (5 parts with app_source) format
		if ( count( $parts ) === 4 ) {
			[ $blog_id, $user_id, $timestamp, $sig ] = $parts;
			$app_source = 'admin';
			$payload = $blog_id . '|' . $user_id . '|' . $timestamp;
		} elseif ( count( $parts ) === 5 ) {
			[ $blog_id, $user_id, $app_source, $timestamp, $sig ] = $parts;
			$payload = $blog_id . '|' . $user_id . '|' . $app_source . '|' . $timestamp;
		} else {
			return null;
		}

		// Verify signature
		$expected_sig = hash_hmac( 'sha256', $payload, $this->get_hmac_key() );

		if ( ! hash_equals( $expected_sig, $sig ) ) return null;

		// Check expiry (10 min max)
		if ( abs( time() - (int) $timestamp ) > 600 ) return null;

		return [
			'blog_id'    => (int) $blog_id,
			'user_id'    => (int) $user_id,
			'app_source' => $app_source,
		];
	}

	/**
	 * Short key for transient name (to keep under WP limit).
	 */
	private function state_key( string $state ): string {
		return substr( md5( $state ), 0, 16 );
	}

	/**
	 * HMAC key for state signing — derived from AUTH_KEY or fallback.
	 */
	private function get_hmac_key(): string {
		return defined( 'AUTH_KEY' ) ? AUTH_KEY : 'biz_fb_oauth_default_key';
	}

	/**
	 * Redirect back to subsite admin with success/error params.
	 */
	private function redirect_to_subsite( int $blog_id, array $params = [] ) {
		$site_url = get_admin_url( $blog_id, 'admin.php' );
		$url = add_query_arg( array_merge( [ 'page' => 'bizcity-facebook-bot-connect' ], $params ), $site_url );
		wp_redirect( $url );
		exit;
	}

	/**
	 * Redirect with error message.
	 */
	private function redirect_with_error( int $blog_id, string $message, string $app_source = 'admin' ) {
		error_log( '[BizCity FB OAuth] redirect_with_error: blog=' . $blog_id . ' source=' . $app_source . ' msg=' . $message );
		if ( $blog_id > 0 ) {
			$params = [
				'biz_fb_oauth_status' => 'error',
				'biz_fb_oauth_error'  => urlencode( $message ),
			];
			if ( $app_source === 'user' ) {
				// User flow: redirect to /tool-facebook/ using home_url() for reliability
				$url = add_query_arg( $params, home_url( '/tool-facebook/' ) );
				wp_redirect( $url );
				exit;
			}
			$this->redirect_to_subsite( $blog_id, $params );
		} else {
			wp_die( esc_html( $message ), 'OAuth Error', [ 'response' => 400 ] );
		}
	}

	// ==========================================
	// PUBLIC: Build OAuth URL for subsite use
	// ==========================================

	/**
	 * Get the OAuth start URL for the current site (admin flow).
	 * Uses network-level App credentials.
	 *
	 * @return string|null URL or null if App ID not configured.
	 */
	public static function get_oauth_url(): ?string {
		$app_id = get_site_option( 'bizcity_fb_app_id', '' );
		if ( empty( $app_id ) ) {
			$app_id = get_option( 'fb_app_id', '' );
		}
		if ( empty( $app_id ) ) return null;

		return home_url( '/?biz_fb_oauth=start' );
	}

	/**
	 * Get the OAuth start URL for a user's own Facebook App (Plan B).
	 * Uses per-user App credentials stored in user_meta.
	 *
	 * @return string|null URL or null if user hasn't configured their app.
	 */
	public static function get_user_oauth_url(): ?string {
		$user_id = get_current_user_id();
		if ( ! $user_id ) return null;

		$app_id = get_user_meta( $user_id, 'bztfb_user_app_id', true );
		if ( empty( $app_id ) ) return null;

		return home_url( '/?biz_fb_oauth=user_start' );
	}
}

BizCity_Facebook_OAuth::instance();
