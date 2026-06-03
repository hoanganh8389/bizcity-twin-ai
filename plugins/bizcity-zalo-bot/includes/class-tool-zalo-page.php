<?php
/**
 * BizCity Zalo Bot — Frontend Profile/Settings Page
 *
 * Mirrors `bizcity-tool-facebook` pattern:
 *   - Public route /tool-zalo-bizcity/  (4 tabs: Bot OA / Hotline ZNS / Pages / Cài đặt)
 *   - Surfaces a SINGLE source-of-truth UI for editing both Zalo integrations
 *     (`zalo_bot` from this plugin + `zalo_hotline` from bizcity-admin-hook-zalo)
 *     instead of forcing users into the WAIC integration dialog.
 *   - Save handler writes back into `WaicIntegrationsModel::saveIntegrations()`
 *     so the data plumbed by other workflow blocks stays consistent.
 *
 * Wired by:
 *   - WaicChannelIntegration_zalobot::_config_url     → /tool-zalo-bizcity/?tab=bot
 *   - WaicChannelIntegration_zalo_hotline::_config_url → /tool-zalo-bizcity/?tab=hotline
 *
 * @package BizCity\ZaloBot
 * @since   PHASE 0.31 Sprint 6 follow-up
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BizCity_Tool_Zalo_Page {

	const SLUG    = 'tool-zalo-bizcity';
	const QUERY   = 'bizcity_agent_page';
	const ACTION  = 'bizcity_tool_zalo_save';
	const NONCE   = 'bizcity_tool_zalo_nonce';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'init',              array( $this, 'register_rewrite' ) );
		add_filter( 'query_vars',        array( $this, 'add_query_var' ) );
		add_action( 'init',              array( $this, 'early_route' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_action( 'admin_post_' . self::ACTION,        array( $this, 'handle_save' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle_save_nopriv' ) );
	}

	public function register_rewrite() {
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?' . self::QUERY . '=' . self::SLUG, 'top' );
	}

	public function add_query_var( $vars ) {
		if ( ! in_array( self::QUERY, $vars, true ) ) {
			$vars[] = self::QUERY;
		}
		return $vars;
	}

	/**
	 * Early route — handle URL even when rewrite rules haven't been flushed yet.
	 * Mirrors bizgpt-tool-google pattern.
	 */
	public function early_route() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
		if ( preg_match( '/^' . preg_quote( self::SLUG, '/' ) . '\/?$/', $path ) ) {
			add_action( 'template_redirect', function () {
				$this->render();
				exit;
			}, 0 );
		}
	}

	public function maybe_render() {
		if ( get_query_var( self::QUERY ) === self::SLUG ) {
			$this->render();
			exit;
		}
	}

	/**
	 * Resolve the WAIC integrations model. Returns false if WAIC isn't loaded
	 * (defensive — page can still render with a hint).
	 */
	private function get_integ_model() {
		if ( ! class_exists( 'WaicFrame' ) ) {
			return false;
		}
		try {
			return WaicFrame::_()->getModule( 'workflow' )->getModel( 'integrations' );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get the first decrypted account row for an integration code, or empty
	 * defaults so form fields render with default values.
	 */
	private function get_first_account( $code ) {
		$model = $this->get_integ_model();
		if ( ! $model ) {
			return array();
		}
		$saved = $model->getSavedIntegrations( $code );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			return array();
		}
		$first = reset( $saved );
		$integ = $model->getIntegration( $code, $first );
		if ( ! $integ ) {
			return is_array( $first ) ? $first : array();
		}
		return $integ->getDecryptedParams( true );
	}

	/**
	 * admin-post handler — write form values back into WAIC integration row 0.
	 * Falls back to creating account if none exists yet.
	 */
	public function handle_save() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			wp_die( __( 'Bạn không có quyền lưu cấu hình.', 'bizcity-zalo-bot' ), 403 );
		}
		check_admin_referer( self::NONCE );

		$code = isset( $_POST['integ_code'] ) ? sanitize_key( $_POST['integ_code'] ) : '';
		if ( ! in_array( $code, array( 'zalo_bot', 'zalo_hotline' ), true ) ) {
			wp_die( __( 'Integration code không hợp lệ.', 'bizcity-zalo-bot' ), 400 );
		}

		$model = $this->get_integ_model();
		if ( ! $model ) {
			wp_die( __( 'WAIC integrations model chưa load — kiểm tra plugin bizcity-automation.', 'bizcity-zalo-bot' ), 500 );
		}

		// Pull existing accounts; we only edit row 0 (single account UX).
		$accounts = $model->getSavedIntegrations( $code );
		if ( ! is_array( $accounts ) ) {
			$accounts = array();
		}

		// Build account from POSTed fields. Strip control fields (_status*, etc.).
		$leer = method_exists( $model, 'getLeerIntegration' )
			? $model->getLeerIntegration( $code )
			: $model->getIntegration( $code, false );
		if ( ! $leer ) {
			wp_die( __( 'Không khởi tạo được integration class.', 'bizcity-zalo-bot' ), 500 );
		}
		$settings = $leer->getSettings();
		$row = isset( $accounts[0] ) && is_array( $accounts[0] ) ? $accounts[0] : array();

		foreach ( $settings as $key => $cfg ) {
			if ( strpos( $key, '_' ) === 0 ) continue; // skip _guide_intro etc.
			if ( ! isset( $_POST['fields'][ $key ] ) ) continue;
			$val = wp_unslash( $_POST['fields'][ $key ] );
			$row[ $key ] = is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : sanitize_text_field( $val );
		}
		$accounts[0] = $row;

		$model->saveIntegrations( $code, $accounts );

		$tab = ( $code === 'zalo_hotline' ) ? 'hotline' : 'bot';
		wp_redirect( home_url( '/' . self::SLUG . '/?tab=' . $tab . '&saved=1' ) );
		exit;
	}

	public function handle_save_nopriv() {
		wp_redirect( wp_login_url( home_url( '/' . self::SLUG . '/' ) ) );
		exit;
	}

	/**
	 * Render the multi-tab profile page. Tabs:
	 *   bots       → BizCity_Zalo_Bot_Admin_Menu::render_page()       (list / add / edit)
	 *   listener   → BizCity_Zalo_Bot_Admin_Menu::render_listener_page()
	 *   testapi    → BizCity_Zalo_Bot_Admin_Menu::render_test_api_page()
	 *   connections→ BizCity_Zalo_Bot_Admin_Menu::render_connections_page()
	 *   logs       → BizCity_Zalo_Bot_Admin_Menu::render_logs_page()
	 *   hotline    → WAIC zalo_hotline thin form (no admin equivalent)
	 *
	 * Frontend bám theo full menu admin (class-admin-menu.php) thay vì hiển
	 * thị duy nhất 1 form WAIC mỏng — user yêu cầu "toàn diện giao diện".
	 */
	private function render() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_redirect( wp_login_url( home_url( '/' . self::SLUG . '/' ) ) );
			exit;
		}

		$valid_tabs = array( 'bots', 'listener', 'testapi', 'connections', 'logs', 'hotline' );

		// Backward-compat: cũ dùng `?tab=bot` → map sang `bots`.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'bots';
		if ( $tab === 'bot' ) $tab = 'bots';
		if ( ! in_array( $tab, $valid_tabs, true ) ) {
			$tab = 'bots';
		}
		$saved = ! empty( $_GET['saved'] );

		// Hotline tab vẫn dùng WAIC integration form như cũ.
		$hotline_account  = $this->get_first_account( 'zalo_hotline' );
		$model            = $this->get_integ_model();
		$hotline_settings = $model ? $this->safe_get_settings( $model, 'zalo_hotline' ) : array();

		$nonce_field = wp_nonce_field( self::NONCE, '_wpnonce', true, false );
		$post_url    = admin_url( 'admin-post.php' );
		$waic_dialog = admin_url( 'admin.php?page=bizcity-integrations' );

		// Resolve admin menu instance for the rich tabs.
		$admin_menu = class_exists( 'BizCity_Zalo_Bot_Admin_Menu' )
			? BizCity_Zalo_Bot_Admin_Menu::instance()
			: null;

		// Enqueue plugin assets only — KHÔNG enqueue 'wp-admin'/'common'/'forms'
		// trên frontend vì những stylesheet đó được thiết kế cho layout wp-admin
		// có sẵn `<body class="wp-admin">` + `#wpwrap`. Khi áp lên theme thường
		// chúng làm `.wp-list-table` phình ra ~3000px và ăn `position:fixed`
		// (thấy bảng float scroll-follow). Chỉ giữ dashicons + plugin admin.css.
		if ( defined( 'BIZCITY_ZALO_BOT_URL' ) ) {
			wp_enqueue_style( 'dashicons' );
			wp_enqueue_style( 'bizcity-zalo-bot-admin', BIZCITY_ZALO_BOT_URL . '/assets/css/admin.css', array(), defined( 'ZALO_BOT_VERSION' ) ? ZALO_BOT_VERSION : '1.0' );
			wp_enqueue_script( 'jquery' );
			wp_enqueue_script( 'bizcity-zalo-bot-admin', BIZCITY_ZALO_BOT_URL . '/assets/js/admin.js', array( 'jquery' ), defined( 'ZALO_BOT_VERSION' ) ? ZALO_BOT_VERSION : '1.0', true );
			wp_localize_script( 'bizcity-zalo-bot-admin', 'bizcityZaloBot', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bizcity_zalo_bot_nonce' ),
			) );
		}

		get_header();
		include __DIR__ . '/../views/page-zalo-profile.php';
		get_footer();
	}

	private function safe_get_settings( $model, $code ) {
		// PHASE 0.31 Sprint 6 follow-up — use getLeerIntegration() (constructs
		// without saved account) instead of getIntegration($code, false) which
		// returns false when no row 0 exists yet → triggers misleading
		// "WAIC integration class chưa load" warning on first-time setup.
		if ( method_exists( $model, 'getLeerIntegration' ) ) {
			$leer = $model->getLeerIntegration( $code );
		} else {
			$leer = $model->getIntegration( $code, false );
		}
		return $leer ? $leer->getSettings() : array();
	}
}

BizCity_Tool_Zalo_Page::instance();

// Ensure rewrite rule is registered on plugin activation. We can't reliably
// hook plugin activation from a bundled sub-plugin, so soft-flush on first
// admin visit when our rule is missing.
add_action( 'admin_init', function () {
	$rules = get_option( 'rewrite_rules' );
	if ( ! is_array( $rules ) || ! isset( $rules['^' . BizCity_Tool_Zalo_Page::SLUG . '/?$'] ) ) {
		flush_rewrite_rules( false );
	}
}, 99 );
