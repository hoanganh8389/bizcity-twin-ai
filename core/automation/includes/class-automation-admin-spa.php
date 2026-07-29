<?php
/**
 * Automation — Admin SPA mount.
 *
 * Registers the wp-admin menu entry and enqueues the Vite-built React bundle.
 * Mirrors `BizCity_Gateway_Admin_SPA` but with its own slug, handles, and
 * boot payload — explicitly NOT a fork of channel-gateway code (no shared
 * platform catalog, no cross-module REST URLs).
 *
 * @package BizCity_Twin_AI
 * @subpackage Automation
 * @since AUTOMATION S0 (2026-05-28)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Admin_SPA {

	const MENU_SLUG     = 'bizcity-automation';
	const PUBLIC_QUERY  = 'bizcity_automation_flow';
	const PUBLIC_SLUG   = 'flow';
	const SCRIPT_HANDLE = 'bizcity-automation-app';
	const STYLE_HANDLE  = 'bizcity-automation-app';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init',                 [ $this, 'add_public_rewrite_rule' ] );
		add_filter( 'query_vars',           [ $this, 'add_public_query_var' ] );
		add_action( 'template_redirect',    [ $this, 'maybe_render_public_page' ] );
		add_action( 'wp_enqueue_scripts',   [ $this, 'enqueue_public_assets' ] );
		add_filter( 'redirect_canonical',   [ $this, 'disable_public_canonical_redirect' ], 10, 2 );
		add_action( 'admin_menu',            [ $this, 'register_menu' ], 35 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		// [2026-06-03 Johnny Chu] HOTFIX — suppress admin chrome when loaded inside TwinShell iframe.
		// Mirrors BizCity_Gateway_Admin_SPA::__construct() logic.
		// Without this, pressing F5 after navigating to Automation causes duplicate
		// admin menu + admin bar: the outer TwinChat page AND the inner automation
		// iframe both render full WP chrome (because _iurl is forwarded with bizcity_iframe=1).
		if ( $this->is_iframe_context() ) {
			add_filter( 'show_admin_bar',    '__return_false', 999 );
			add_filter( 'qm/dispatch/html',  '__return_false', 999 );
			remove_action( 'admin_init',     'send_frame_options_header' );
			add_action( 'send_headers', static function () {
				header_remove( 'X-Frame-Options' );
			}, 99 );
			add_action( 'admin_head', [ $this, 'print_iframe_chrome_css' ], 1 );
		}
	}

	/**
	 * Detect that THIS specific page is being requested in iframe (TwinShell) mode.
	 *
	 * @return bool
	 */
	private function is_iframe_context(): bool {
		// [2026-06-03 Johnny Chu] HOTFIX — check page slug first to avoid false positives.
		$page = isset( $_GET['page'] ) ? (string) $_GET['page'] : '';
		if ( $page !== self::MENU_SLUG ) {
			return false;
		}
		if ( ! empty( $_GET['bizcity_iframe'] ) ) {
			return true;
		}
		// Fallback: browser sends Sec-Fetch-Dest: iframe when loading as child frame.
		return strtolower( (string) ( isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? $_SERVER['HTTP_SEC_FETCH_DEST'] : '' ) ) === 'iframe';
	}

	/**
	 * Minimal CSS to hide any WP admin chrome that PHP filters didn't fully prevent.
	 * Priority 1 on admin_head so it runs before plugin styles.
	 */
	public function print_iframe_chrome_css(): void {
		// [2026-06-03 Johnny Chu] HOTFIX — belt-and-suspenders hide for any chrome WP still renders.
		echo '<style id="bizcity-automation-iframe-chrome">'
			. '#adminmenumain,#adminmenuback,#adminmenuwrap,'
			. '#adminmenu,#collapse-menu,#wpadminbar,'
			. '#wpfooter,#screen-meta,#screen-meta-links{display:none!important}'
			. '#wpcontent{margin-left:0!important}'
			. 'html.wp-toolbar{padding-top:0!important}'
			. '#wpbody-content{padding-bottom:0!important}'
			. '</style>' . "\n";
	}

	public function register_menu(): void {
		global $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv;
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — Twin GPT embeds this page for customers; normal wp-admin navigation stays admin-only.
		$page_cap = $this->is_iframe_context() ? 'read' : 'manage_options';
		if ( $this->is_iframe_context() ) {
			// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — central admin menu may have registered this slug earlier with manage_options; clear WP's nopriv marker for the iframe-only read surface.
			unset( $_wp_menu_nopriv[ self::MENU_SLUG ] );
			foreach ( [ 'bizcity-twinchat', 'bizchat-gateway', 'bizcity-twin-ai' ] as $nopriv_parent ) {
				if ( isset( $_wp_submenu_nopriv[ $nopriv_parent ][ self::MENU_SLUG ] ) ) {
					unset( $_wp_submenu_nopriv[ $nopriv_parent ][ self::MENU_SLUG ] );
				}
			}
		}

		// Prefer the standard TwinChat parent; fall back to a top-level entry.
		$parent_candidates = [ 'bizcity-twinchat', 'bizchat-gateway', 'bizcity-twin-ai' ];
		$parent            = '';
		foreach ( $parent_candidates as $cand ) {
			if ( isset( $submenu[ $cand ] ) ) {
				$parent = $cand;
				break;
			}
		}

		if ( $parent ) {
			add_submenu_page(
				$parent,
				__( 'Twin Workflow', 'bizcity-twin-ai' ),
				__( 'Twin Workflow', 'bizcity-twin-ai' ),
				$page_cap,
				self::MENU_SLUG,
				[ $this, 'render_page' ]
			);
			return;
		}

		add_menu_page(
			__( 'Twin Workflow', 'bizcity-twin-ai' ),
			__( 'Twin Workflow', 'bizcity-twin-ai' ),
			$page_cap,
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-randomize',
			32
		);
	}

	public function render_page(): void {
		echo '<div id="bizcity-automation-root" style="min-height:calc(100vh - 32px);"></div>';
	}

	public function add_public_rewrite_rule(): void {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — expose customer-safe Automation SPA outside wp-admin for subscriber iframe use.
		add_rewrite_rule(
			'^' . self::PUBLIC_SLUG . '(?:/.*)?$',
			'index.php?' . self::PUBLIC_QUERY . '=1',
			'top'
		);
	}

	public function add_public_query_var( array $vars ): array {
		$vars[] = self::PUBLIC_QUERY;
		return $vars;
	}

	private function is_public_flow_request(): bool {
		if ( (bool) get_query_var( self::PUBLIC_QUERY ) ) {
			return true;
		}
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — raw path fallback lets /flow/ work before rewrite rules are flushed.
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
		return (bool) preg_match( '#(^|/)flow(?:/.*)?$#', $path );
	}

	public function enqueue_public_assets(): void {
		if ( ! $this->is_public_flow_request() ) {
			return;
		}
		$this->enqueue_bundle_assets( false );
	}

	public function disable_public_canonical_redirect( $redirect_url, $requested_url ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — keep WordPress from canonical-redirecting the raw /flow/ SPA route before render.
		return $this->is_public_flow_request() ? false : $redirect_url;
	}

	public function maybe_render_public_page(): void {
		if ( ! $this->is_public_flow_request() ) {
			return;
		}

		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-CHANNEL-AUTOMATION — customers must be logged in, but do not require wp-admin access.
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/' . self::PUBLIC_SLUG . '/' ) ) );
			exit;
		}

		status_header( 200 );
		nocache_headers();
		?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
	<style id="bizcity-automation-public-shell">html,body,#bizcity-automation-root{min-height:100%;height:100%;margin:0}body{background:#f8fafc}</style>
</head>
<body class="bizcity-automation-flow">
	<div id="bizcity-automation-root" style="min-height:100vh;"></div>
	<?php wp_footer(); ?>
</body>
</html><?php
		exit;
	}

	public function enqueue_assets( $hook ): void {
		if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		$this->enqueue_bundle_assets( true );
	}

	private function enqueue_bundle_assets( bool $is_admin_surface ): void {

		$dist_dir = BIZCITY_AUTOMATION_DIR . '/assets/dist/';
		$dist_url = trailingslashit( BIZCITY_AUTOMATION_URL ) . 'assets/dist/';

		$js_path  = $dist_dir . 'automation-app.js';
		$css_path = $dist_dir . 'automation-app.css';

		if ( ! file_exists( $js_path ) ) {
			if ( $is_admin_surface ) {
				add_action( 'admin_notices', static function () {
					echo '<div class="notice notice-warning"><p><strong>Automation SPA bundle chưa build.</strong> ';
					echo 'Chạy <code>cd core/automation/frontend &amp;&amp; npm install &amp;&amp; npm run build</code>.</p></div>';
				} );
			}
			return;
		}

		$ver_js  = (string) filemtime( $js_path );
		$ver_css = file_exists( $css_path ) ? (string) filemtime( $css_path ) : $ver_js;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, $dist_url . 'automation-app.css', [], $ver_css );
		}
		wp_enqueue_script( self::SCRIPT_HANDLE, $dist_url . 'automation-app.js', [], $ver_js, true );

		$boot = [
			// S0: no REST yet. Reserved for S2+ when /workflows endpoints land.
			'restUrl'   => '/wp-json/bizcity-automation/v1/',
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'menuSlug'  => self::MENU_SLUG,
			'adminUrl'  => $is_admin_surface ? admin_url( 'admin.php?page=' . self::MENU_SLUG ) : home_url( '/' . self::PUBLIC_SLUG . '/' ),
			'siteUrl'   => home_url( '/' ),
			'blogId'    => (int) get_current_blog_id(),
			'version'   => defined( 'BIZCITY_TWIN_CORE_VERSION' ) ? BIZCITY_TWIN_CORE_VERSION : '1.0',
			'caps'      => [
				'manage' => current_user_can( 'manage_options' ),
			],
			// Discovery hook for satellite plugins to contribute block definitions
			// (S3+). Currently empty — UI palette uses local registry.
			'blockPaths' => apply_filters( 'bizcity_automation_external_blocks_paths', [] ),
		];

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.BIZCITY_AUTOMATION_BOOT = ' . wp_json_encode( $boot ) . ';',
			'before'
		);
	}
}
