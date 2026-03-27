<?php
/**
 * BizCity Maturity Dashboard — Admin Page + Frontend /maturity/ Route
 *
 * Registers admin submenu, frontend /maturity/ rewrite rule,
 * enqueues Chart.js + custom assets, renders dashboard template.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Maturity_Dashboard {

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register frontend /maturity/ route (called outside is_admin check).
	 */
	public static function register_frontend_route(): void {
		// Auto-create /maturity/ page
		add_action( 'init', [ __CLASS__, 'ensure_maturity_page' ], 20 );
		// Template include
		add_filter( 'template_include', [ __CLASS__, 'load_maturity_template' ] );
	}

	public static function ensure_maturity_page(): void {
		$existing = get_page_by_path( 'maturity' );
		$needs_flush = false;

		if ( $existing ) {
			if ( $existing->post_status !== 'publish' ) {
				wp_update_post( [ 'ID' => $existing->ID, 'post_status' => 'publish' ] );
				$needs_flush = true;
			}
			if ( get_post_meta( $existing->ID, '_wp_page_template', true ) !== 'bizcity-maturity-spa' ) {
				update_post_meta( $existing->ID, '_wp_page_template', 'bizcity-maturity-spa' );
			}
		} else {
			$page_id = wp_insert_post( [
				'post_title'   => 'Twin AI Maturity',
				'post_name'    => 'maturity',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '<!-- Maturity Dashboard managed by BizCity Twin Core -->',
			] );
			if ( $page_id && ! is_wp_error( $page_id ) ) {
				update_post_meta( $page_id, '_wp_page_template', 'bizcity-maturity-spa' );
				$needs_flush = true;
			}
		}

		if ( $needs_flush ) {
			flush_rewrite_rules();
		}

		// SPA sub-routes
		add_rewrite_rule( '^maturity/(.+?)/?$', 'index.php?pagename=maturity', 'top' );

		$rules = get_option( 'rewrite_rules', [] );
		if ( is_array( $rules ) && ! isset( $rules['^maturity/(.+?)/?$'] ) ) {
			flush_rewrite_rules( false );
		}
	}

	public static function load_maturity_template( string $template ): string {
		if ( is_page() ) {
			$page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
			if ( 'bizcity-maturity-spa' === $page_template ) {
				$custom = BIZCITY_TWIN_CORE_DIR . '/templates/page-maturity.php';
				if ( file_exists( $custom ) ) {
					return $custom;
				}
			}
		}
		return $template;
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'bizcity-webchat-dashboard',
			'Twin AI Maturity',
			'🧬 Maturity',
			'read',
			'bizcity-twin-maturity',
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'chat_page_bizcity-twin-maturity' && $hook !== 'chat-with-assistant_page_bizcity-twin-maturity' ) {
			if ( strpos( $hook, 'bizcity-twin-maturity' ) === false ) {
				return;
			}
		}

		self::do_enqueue_assets();
	}

	/**
	 * Shared enqueue — used from both admin + frontend template.
	 */
	public static function do_enqueue_assets(): void {
		$assets_dir = BIZCITY_TWIN_CORE_DIR . '/assets';
		$assets_url = plugins_url( 'core/twin-core/assets', dirname( BIZCITY_TWIN_CORE_DIR, 2 ) . '/bizcity-twin-ai.php' );

		// Chart.js from CDN (v4)
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js',
			[],
			'4.4.7',
			true
		);

		// Dashboard CSS
		$css_file = $assets_dir . '/maturity-dashboard.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'bizcity-maturity-dashboard',
				$assets_url . '/maturity-dashboard.css',
				[],
				filemtime( $css_file )
			);
		}

		// Dashboard JS
		$js_file = $assets_dir . '/maturity-dashboard.js';
		if ( file_exists( $js_file ) ) {
			wp_enqueue_script(
				'bizcity-maturity-dashboard',
				$assets_url . '/maturity-dashboard.js',
				[ 'chartjs', 'jquery' ],
				filemtime( $js_file ),
				true
			);

			wp_localize_script( 'bizcity-maturity-dashboard', 'bizcMaturity', [
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bizcity_maturity_nonce' ),
				'userId'  => get_current_user_id(),
			] );
		}
	}

	public function render_page(): void {
		$template = BIZCITY_TWIN_CORE_DIR . '/templates/maturity-dashboard.php';
		if ( file_exists( $template ) ) {
			include $template;
		} else {
			echo '<div class="wrap"><h1>Twin AI Maturity Dashboard</h1><p>Template not found.</p></div>';
		}
	}
}
