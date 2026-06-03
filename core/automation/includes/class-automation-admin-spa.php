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
		add_action( 'admin_menu',            [ $this, 'register_menu' ], 35 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function register_menu(): void {
		global $submenu;

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
				__( 'Automation', 'bizcity-twin-ai' ),
				__( 'Automation', 'bizcity-twin-ai' ),
				'manage_options',
				self::MENU_SLUG,
				[ $this, 'render_page' ]
			);
			return;
		}

		add_menu_page(
			__( 'BizCity Automation', 'bizcity-twin-ai' ),
			__( 'Automation', 'bizcity-twin-ai' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-randomize',
			32
		);
	}

	public function render_page(): void {
		echo '<div id="bizcity-automation-root" style="min-height:calc(100vh - 32px);"></div>';
	}

	public function enqueue_assets( $hook ): void {
		if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		$dist_dir = BIZCITY_AUTOMATION_DIR . '/assets/dist/';
		$dist_url = trailingslashit( BIZCITY_AUTOMATION_URL ) . 'assets/dist/';

		$js_path  = $dist_dir . 'automation-app.js';
		$css_path = $dist_dir . 'automation-app.css';

		if ( ! file_exists( $js_path ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-warning"><p><strong>Automation SPA bundle chưa build.</strong> ';
				echo 'Chạy <code>cd core/automation/frontend &amp;&amp; npm install &amp;&amp; npm run build</code>.</p></div>';
			} );
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
			'adminUrl'  => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			'siteUrl'   => '/',
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
