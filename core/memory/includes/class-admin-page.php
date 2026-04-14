<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory — Admin Page
 *
 * Registers submenu under Knowledge + renders tree-view + editor UI.
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Admin_Page' ) ) {
	return;
}

class BizCity_Memory_Admin_Page {

	/** @var self|null */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Menu registration moved to BizCity_Admin_Menu (centralized).
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register submenu page under bizcity-knowledge.
	 */
	public function add_menu() {
		$td = 'bizcity-twin-ai';

		add_submenu_page(
			'bizcity-knowledge',
			__( 'Memory Specs', $td ),
			'🧠 ' . __( 'Memory Specs', $td ),
			'manage_options',
			'bizcity-memory',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		$view_file = BIZCITY_MEMORY_DIR . 'views/page-memory.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>Memory Specs</h1><p>View file not found.</p></div>';
		}
	}

	/**
	 * Enqueue CSS/JS on our admin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'bizcity-memory' ) === false ) {
			return;
		}

		// Inline JS variables
		wp_enqueue_script( 'wp-api-fetch' );
		wp_localize_script( 'wp-api-fetch', 'bizcityMemory', array(
			'rest_url' => esc_url_raw( rest_url( 'bizcity/memory/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
		) );
	}
}
