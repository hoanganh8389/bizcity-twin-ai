<?php
/**
 * Content Ops — Admin SPA mount
 *
 * URL: wp-admin/admin.php?page=bizcity-content-ops
 * Boot data: window.BIZCITY_CO_BOOT
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Content_Admin_SPA {

	const MENU_SLUG     = 'bizcity-content-ops';
	const SCRIPT_HANDLE = 'bizcity-content-ops-app';
	const STYLE_HANDLE  = 'bizcity-content-ops-app';

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		// 2026-05-22 — Consolidated under TwinChat parent (`bizcity-twinchat`) for
		// standalone deployments. Falls back to top-level if parent not present.
		global $submenu;
		$parent = ( is_array( $submenu ) && isset( $submenu['bizcity-twinchat'] ) ) ? 'bizcity-twinchat' : '';

		if ( $parent ) {
			add_submenu_page(
				$parent,
				__( 'Content Ops', 'bizcity-twin-ai' ),
				__( 'Content Ops', 'bizcity-twin-ai' ),
				'manage_options',
				self::MENU_SLUG,
				array( $this, 'render_page' )
			);
			return;
		}

		add_menu_page(
			__( 'Content Ops', 'bizcity-twin-ai' ),
			__( 'Content Ops', 'bizcity-twin-ai' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-megaphone',
			32
		);
	}

	public function render_page(): void {
		echo '<div id="bizcity-content-ops-root" style="margin:0 0 0 -20px;min-height:calc(100vh - 32px);"></div>';
	}

	public function enqueue_assets( $hook ): void {
		if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		$dist_dir = dirname( __DIR__ ) . '/assets/dist/';
		$dist_url = defined( 'BIZCITY_TWIN_AI_URL' )
			? trailingslashit( BIZCITY_TWIN_AI_URL ) . 'core/content-ops/assets/dist/'
			: plugins_url( 'assets/dist/', dirname( __DIR__ ) . '/content-ops/index.php' );

		$js_path  = $dist_dir . 'content-ops-app.js';
		$css_path = $dist_dir . 'content-ops-app.css';

		if ( ! file_exists( $js_path ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-warning"><p><strong>Content Ops SPA bundle chưa build.</strong> ';
				echo 'Chạy <code>cd core/content-ops/frontend && npm install && npm run build</code>.</p></div>';
			} );
			return;
		}

		$ver_js  = filemtime( $js_path );
		$ver_css = file_exists( $css_path ) ? filemtime( $css_path ) : $ver_js;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style( self::STYLE_HANDLE, $dist_url . 'content-ops-app.css', array(), $ver_css );
		}
		wp_enqueue_script( self::SCRIPT_HANDLE, $dist_url . 'content-ops-app.js', array(), $ver_js, true );

		$readiness   = class_exists( 'BizCity_Content_Channel_Readiness' )
			? BizCity_Content_Channel_Readiness::matrix()
			: array();
		$ready_count = 0;
		$total       = count( $readiness );
		foreach ( $readiness as $r ) {
			if ( ! empty( $r['ready'] ) ) {
				++$ready_count;
			}
		}

		$boot = array(
			'restUrl'        => rest_url( BizCity_Content_REST_API::NS . '/' ),
			'channelRestUrl' => rest_url( 'bizcity-channel/v1/' ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'menuSlug'       => self::MENU_SLUG,
			'adminUrl'       => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			'channelAdminUrl' => admin_url( 'admin.php?page=bizchat-gateway-spa' ),
			'siteUrl'        => home_url( '/' ),
			'version'        => defined( 'BIZCITY_TWIN_CORE_VERSION' ) ? BIZCITY_TWIN_CORE_VERSION : '1.0',
			'caps'           => array(
				'manage' => current_user_can( 'manage_options' ),
				'send'   => current_user_can( 'manage_options' ) || current_user_can( 'bizcity_channel_send' ),
			),
			'readiness'      => array(
				'channels'    => $readiness,
				'ready_count' => $ready_count,
				'total'       => $total,
				'llm_ready'   => class_exists( 'BizCity_Content_LLM_Proxy' ) ? BizCity_Content_LLM_Proxy::is_ready() : false,
			),
			'cptSlug'        => defined( 'BIZCITY_CONTENT_OPS_LOADED' ) ? 'bizcity_doc' : null,
		);

		wp_add_inline_script(
			self::SCRIPT_HANDLE,
			'window.BIZCITY_CO_BOOT = ' . wp_json_encode( $boot ) . ';',
			'before'
		);
	}
}
