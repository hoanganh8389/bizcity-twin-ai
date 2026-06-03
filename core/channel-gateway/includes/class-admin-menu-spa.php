<?php
/**
 * Channel Gateway — SPA Admin Page (PHASE 0.37++)
 *
 * Mounts a React SPA (Vite/IIFE bundle) for the new Channels admin UX.
 * Lives alongside the legacy `class-admin-menu.php` overview during the
 * transition period. Both pages remain registered so admins can compare.
 *
 * URL: wp-admin/admin.php?page=bizchat-gateway-spa
 *
 * Boot data injected via `window.BIZCITY_CG_BOOT`.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE 0.37++ (2026-05-21)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Gateway_Admin_SPA {

	const MENU_SLUG     = 'bizchat-gateway-spa';
	const SCRIPT_HANDLE = 'bizcity-channel-gateway-app';
	const STYLE_HANDLE  = 'bizcity-channel-gateway-app';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ], 30 );
		add_action( 'network_admin_menu',    [ $this, 'register_menu' ], 30 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// ── Iframe chrome suppression ─────────────────────────────────────────
		// When this SPA page is embedded inside a TwinChat iframe the URL carries
		// ?bizcity_iframe=1. WordPress must NOT render admin bar, admin menu, or
		// Query Monitor output — use PHP-level filters fired here (before init,
		// before admin_bar_init) rather than CSS-only hiding.
		if ( $this->is_spa_iframe_context() ) {
			// Prevent admin bar HTML from being generated at all.
			add_filter( 'show_admin_bar',    '__return_false', 999 );
			// Prevent Query Monitor HTML injection.
			add_filter( 'qm/dispatch/html',  '__return_false', 999 );
			// Allow cross-origin iframe embedding.
			remove_action( 'admin_init',     'send_frame_options_header' );
			add_action( 'send_headers', static function () {
				header_remove( 'X-Frame-Options' );
			}, 99 );
			// Belt-and-suspenders CSS (catches any chrome WP still renders).
			add_action( 'admin_head', [ $this, 'print_iframe_chrome_css' ], 1 );
		}
	}

	/**
	 * Detect that THIS specific SPA page is being requested in iframe mode.
	 * Checks $_GET directly so hooks can fire before admin_bar_init on `init`.
	 *
	 * @return bool
	 */
	private function is_spa_iframe_context(): bool {
		if ( ( $_GET['page'] ?? '' ) !== self::MENU_SLUG ) {
			return false;
		}
		if ( ! empty( $_GET['bizcity_iframe'] ) ) {
			return true;
		}
		// Fallback: browser sends Sec-Fetch-Dest: iframe when loading as child frame.
		return strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '' ) ) === 'iframe';
	}

	/**
	 * Minimal CSS to hide any admin chrome that PHP filters didn't fully prevent.
	 * Priority 1 on admin_head so it loads before plugin styles.
	 */
	public function print_iframe_chrome_css(): void {
		echo '<style id="bizcity-cg-spa-iframe-chrome">'
			. '#adminmenumain,#adminmenuback,#adminmenuwrap,'
			. '#adminmenu,#collapse-menu,#wpadminbar,'
			. '#wpfooter,#screen-meta,#screen-meta-links{display:none!important}'
			. '#wpcontent{margin-left:0!important}'
			. 'html.wp-toolbar{padding-top:0!important}'
			. '#wpbody-content{padding-bottom:0!important}'
			. '</style>' . "\n";
	}

	/* ─── Menu Registration ─── */

	public function register_menu(): void {
		// Prefer adding under `bizchat-gateway` parent if it exists.
		global $menu, $submenu;
		$parent = $this->resolve_parent();

		if ( $parent ) {
			add_submenu_page(
				$parent,
				__( 'Channels', 'bizcity-twin-ai' ),
				__( 'Channels', 'bizcity-twin-ai' ),
				'manage_options',
				self::MENU_SLUG,
				[ $this, 'render_page' ]
			);
			return;
		}

		// Fallback: top-level entry.
		add_menu_page(
			__( 'BizChat Channels', 'bizcity-twin-ai' ),
			__( 'Channels', 'bizcity-twin-ai' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-share-alt2',
			31
		);
	}

	private function resolve_parent(): string {
		// 2026-05-22 — Prefer `bizcity-twinchat` parent first for standalone deploys.
		global $submenu;
		foreach ( [ 'bizcity-twinchat', 'bizchat-gateway', 'bizchat-menu', 'bizcity-twin-ai' ] as $candidate ) {
			if ( isset( $submenu[ $candidate ] ) || ( is_array( $submenu ) && array_key_exists( $candidate, (array) $submenu ) ) ) {
				return $candidate;
			}
		}
		return 'bizcity-twinchat'; // assume TwinChat parent will be registered later
	}

	/* ─── Render ─── */

	public function render_page(): void {
		// 2026-05-26 R-CMP-MIG: auto-import legacy flows → campaigns once per
		// AUTO_IMPORT_VERSION bump. Idempotent (version-gated). Runs here so
		// admins simply opening the Channel Gateway SPA see /campaigns
		// populated automatically without a manual CLI / migrate button.
		if ( class_exists( 'BizCity_CRM_Flow_Importer' ) ) {
			$mig = BizCity_CRM_Flow_Importer::maybe_auto_import_all();
			if ( ! empty( $mig['processed'] ) && ( $mig['created'] + $mig['updated'] ) > 0 ) {
				$src = isset( $mig['source'] ) ? esc_html( (string) $mig['source'] ) : '';
				echo '<div class="notice notice-success is-dismissible"><p>'
					. '<b>Đã import</b> ' . (int) $mig['created'] . ' tạo mới · '
					. (int) $mig['updated'] . ' cập nhật · ' . (int) $mig['failed'] . ' fail'
					. ' (từ <code>' . $src . '</code>) sang <code>wp_bizcity_crm_campaigns</code>.'
					. '</p></div>';
			} elseif ( ! empty( $mig['failed'] ) ) {
				echo '<div class="notice notice-warning is-dismissible"><p>'
					. '<b>Flow → Campaign import:</b> ' . (int) $mig['failed'] . ' fail. Reason: '
					. esc_html( (string) ( $mig['reason'] ?? '' ) ) . '</p></div>';
			}
		}

		// Strip wp-admin chrome around our mount point so React owns the canvas.
		// When inside an iframe the admin bar is absent — use full 100vh.
		$min_h = $this->is_spa_iframe_context() ? '100vh' : 'calc(100vh - 32px)';
		echo '<div id="bizcity-channel-gateway-root" style="min-height:' . esc_attr( $min_h ) . ';"></div>';
	}

	/* ─── Asset Enqueue ─── */

	public function enqueue_assets( $hook ): void {
		if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		$dist_dir = dirname( __DIR__ ) . '/assets/dist/';
		$dist_url = plugins_url( 'assets/dist/', dirname( __DIR__ ) . '/_dummy.php' );

		// `dirname( __DIR__ )` resolves to `.../core/channel-gateway`. Build URL
		// correctly via the bootstrap-defined constant if available.
		if ( defined( 'BIZCITY_TWIN_AI_URL' ) ) {
			$dist_url = trailingslashit( BIZCITY_TWIN_AI_URL ) . 'core/channel-gateway/assets/dist/';
		} else {
			$dist_url = plugins_url( 'assets/dist/', dirname( __DIR__ ) . '/channel-gateway/index.php' );
		}

		$js_path  = $dist_dir . 'channel-gateway-app.js';
		$css_path = $dist_dir . 'channel-gateway-app.css';

		if ( ! file_exists( $js_path ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-warning"><p><strong>Channel Gateway SPA bundle chưa build.</strong> ';
				echo 'Chạy <code>cd core/channel-gateway/frontend && npm install && npm run build</code>.</p></div>';
			} );
			return;
		}

		$ver_js  = filemtime( $js_path );
		$ver_css = file_exists( $css_path ) ? filemtime( $css_path ) : $ver_js;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, $dist_url . 'channel-gateway-app.css', [], $ver_css );
		}
		// Ensure WP Media library (wp.media) is available for image picker.
		if ( function_exists( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_script( self::SCRIPT_HANDLE, $dist_url . 'channel-gateway-app.js', [], $ver_js, true );

		$boot = [
			// R-GW-8 — Force same-origin path. Multisite/domain-mapping setups
			// often make rest_url() return the primary domain (e.g. bizcity.vn)
			// even on satellite sites, breaking REST + CORS. Send a relative
			// URL so the browser always hits the current host.
			'restUrl'      => '/wp-json/bizcity-channel/v1/',
			// Cross-namespace base for core/scheduler (calendar + Google sync
			// shared with bizcity-twin-crm). Same R-GW-8 relative-path policy.
			'schedulerRestUrl' => '/wp-json/bizcity-scheduler/v1/',
			'restNonce'    => wp_create_nonce( 'wp_rest' ),
			'menuSlug'     => self::MENU_SLUG,
			'adminUrl'     => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			'siteUrl'      => '/',
			'blogId'       => (int) get_current_blog_id(),
			'blogName'     => (string) get_bloginfo( 'name' ),
			'version'      => defined( 'BIZCITY_TWIN_CORE_VERSION' ) ? BIZCITY_TWIN_CORE_VERSION : '1.0',
			'caps'         => [
				'manage' => current_user_can( 'manage_options' ),
				'send'   => current_user_can( 'manage_options' ) || current_user_can( 'bizcity_channel_send' ),
			],
			'i18n'         => [
				'plugin_title' => __( 'BizChat Channels', 'bizcity-twin-ai' ),
			],
			'platforms'    => $this->platform_catalog(),
		];

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.BIZCITY_CG_BOOT = ' . wp_json_encode( $boot ) . ';',
			'before'
		);

		// ── Self-heal: keep ?bizcity_iframe=1 in URL after SPA navigations ────
		// React Router (hash-based) does not touch query params, but any
		// external pushState/replaceState call could drop them. This snippet
		// runs before the bundle and re-adds the param when the page is already
		// inside an iframe but the URL is missing the flag.
		$self_heal_js = <<<'JS'
(function(){
  try{
    if(window.self!==window.top){
      var u=new URL(window.location.href);
      if(!u.searchParams.has('bizcity_iframe')){
        u.searchParams.set('bizcity_iframe','1');
        window.history.replaceState(null,'',u.toString());
      }
    }
  }catch(e){}
})();
JS;
		wp_add_inline_script( self::SCRIPT_HANDLE, $self_heal_js, 'before' );
	}

	/**
	 * Static catalog of supported platforms displayed in the "Add Channel"
	 * wizard. Each entry mirrors what the registry returns + adds a slug,
	 * icon hint, and "ready" flag so the UI can grey-out Phase-2 items.
	 */
	private function platform_catalog(): array {
		$registry = class_exists( 'BizCity_Integration_Registry' ) ? BizCity_Integration_Registry::instance() : null;
		$has      = function ( $code ) use ( $registry ) {
			return $registry && (bool) $registry->get( $code );
		};

		return [
			[ 'code' => 'facebook_page',  'label' => 'Facebook Page',       'platform' => 'FACEBOOK',     'icon' => 'facebook',  'group' => 'social',    'ready' => $has( 'facebook_page' ) || $has( 'facebook_bot' ) || $has( 'facebook' ), 'desc' => 'Nhận & gửi tin nhắn từ Fanpage qua Messenger Platform.' ],
			[ 'code' => 'facebook_messenger', 'label' => 'Facebook Messenger', 'platform' => 'FACEBOOK', 'icon' => 'messenger', 'group' => 'social',    'ready' => $has( 'facebook_page' ) || $has( 'facebook_bot' ) || $has( 'facebook' ), 'desc' => 'Channel Messenger 1-1 với khách hàng.' ],
			[ 'code' => 'zalo_bot',       'label' => 'Zalo Bot OA',         'platform' => 'ZALO_BOT',     'icon' => 'zalo',      'group' => 'social',    'ready' => $has( 'zalo_bot' ),                          'desc' => 'Bot Zalo (bot.zapps.me) dùng Bot Token + webhook secret token — nhận webhook qua /zalohook/.' ],
			[ 'code' => 'zalo_hotline',   'label' => 'Zalo BizCity (Hotline)', 'platform' => 'ZALO_HOTLINE', 'icon' => 'zalo',  'group' => 'social',    'ready' => function_exists( 'send_zalo_botbanhang' ) || function_exists( 'biz_send_message' ), 'desc' => 'Gửi tin nhắn qua tài khoản Zalo cá nhân (PA agent).' ],
			[ 'code' => 'telegram',       'label' => 'Telegram',            'platform' => 'TELEGRAM',     'icon' => 'telegram',  'group' => 'social',    'ready' => function_exists( 'twf_telegram_send_message' ), 'desc' => 'Telegram Bot API — token từ @BotFather.' ],
			[ 'code' => 'webchat',        'label' => 'WebChat',             'platform' => 'WEBCHAT',      'icon' => 'globe',     'group' => 'web',       'ready' => true,                                        'desc' => 'Widget chat trực tiếp trên website.' ],
			[ 'code' => 'adminchat',      'label' => 'Admin Chat',          'platform' => 'ADMINCHAT',    'icon' => 'shield',    'group' => 'web',       'ready' => true,                                        'desc' => 'Kênh nội bộ giữa các quản trị viên.' ],
			[ 'code' => 'email_smtp',     'label' => 'Email SMTP',          'platform' => 'EMAIL',        'icon' => 'mail',      'group' => 'email',     'ready' => true,                                        'desc' => 'Gửi mail outbound qua SMTP server.' ],
			[ 'code' => 'gmail',          'label' => 'Gmail',               'platform' => 'GMAIL',        'icon' => 'gmail',     'group' => 'google',    'ready' => false,                                       'desc' => 'OAuth Gmail (read + send). Phase 2.' ],
			[ 'code' => 'google_calendar', 'label' => 'Google Calendar',    'platform' => 'GOOGLE_CALENDAR', 'icon' => 'calendar', 'group' => 'google', 'ready' => false,                                       'desc' => 'OAuth Calendar (book / list). Phase 2.' ],
			[ 'code' => 'google_drive',   'label' => 'Google Drive',        'platform' => 'GOOGLE_DRIVE', 'icon' => 'drive',     'group' => 'google',    'ready' => false,                                       'desc' => 'OAuth Drive (read / upload). Phase 2.' ],
		];
	}
}
