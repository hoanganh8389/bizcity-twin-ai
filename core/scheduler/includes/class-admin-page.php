<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Scheduler — Admin Page
 *
 * Registers submenu + enqueues the Vite-built React SPA.
 * Identical pattern to core/skills/includes/class-admin-page.php.
 *
 * @package  BizCity_Scheduler
 * @since    2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Admin_Page {

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Menu registration moved to BizCity_Admin_Menu (centralized).
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu(): void {
		$td = 'bizcity-twin-ai';

		add_submenu_page(
			'bizcity-webchat-dashboard',
			__( 'Scheduler', $td ),
			'📅 ' . __( 'Lịch & Nhắc việc', $td ),
			'read', // any logged-in user can view their own events
			'bizcity-scheduler',
			[ $this, 'render_page' ]
		);
	}

	public function render_page(): void {
		// Ensure schema on first visit
		BizCity_Scheduler_Manager::instance()->ensure_schema();

		echo '<div class="wrap" style="margin:0;padding:0;">';
		echo '<div id="scheduler-app" style="min-height:calc(100vh - 32px);"></div>';
		echo '</div>';
	}

	public function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, 'bizcity-scheduler' ) === false ) {
			return;
		}

		$dist = BIZCITY_SCHEDULER_DIR . 'assets/dist/';
		$url  = plugins_url( 'assets/dist/', BIZCITY_SCHEDULER_DIR . 'bootstrap.php' );

		// CSS
		$css_path = $dist . 'scheduler-app.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'bizcity-scheduler-app',
				$url . 'scheduler-app.css',
				[],
				(string) filemtime( $css_path )
			);
		}

		// JS
		$js_path = $dist . 'scheduler-app.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'bizcity-scheduler-app',
				$url . 'scheduler-app.js',
				[],
				(string) filemtime( $js_path ),
				true
			);
		}

		// Config for React app
		$google = BizCity_Scheduler_Google::instance();
		$config = [
			'restBase'  => esc_url_raw( rest_url( 'bizcity-scheduler/v1' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'userId'    => get_current_user_id(),
			'google'    => array_merge( $google->get_connection_status(), [
				'can_manage' => current_user_can( 'manage_options' ),
			] ),
			'locale'    => get_locale(),
			'timezone'  => wp_timezone_string(),
		];
		wp_add_inline_script(
			'bizcity-scheduler-app',
			'window.schedulerConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		// Dequeue WooCommerce noise
		wp_dequeue_script( 'wc-settings' );
		wp_dequeue_script( 'wc-entities' );
		wp_dequeue_style( 'woocommerce_admin_styles' );
	}
}
