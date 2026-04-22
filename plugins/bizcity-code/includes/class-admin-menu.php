<?php
/**
 * Admin menu — registers pages for Code Builder.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCode_Admin_Menu {

	const MENU_SLUG = 'bizcity-code';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/* ── Menu ── */

	public static function register_menu(): void {
		add_menu_page(
			'Code Builder — AI tạo web',
			'Code Builder',
			'read',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-editor-code',
			74
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Projects',
			'Dự án',
			'read',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Templates',
			'Templates',
			'manage_options',
			self::MENU_SLUG . '-templates',
			[ __CLASS__, 'render_templates_page' ]
		);
	}

	/* ── Assets ── */

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'bzcode-admin',
			BZCODE_URL . 'assets/admin.css',
			[],
			BZCODE_VERSION
		);

		wp_enqueue_script(
			'bzcode-admin',
			BZCODE_URL . 'assets/admin.js',
			[ 'jquery' ],
			BZCODE_VERSION,
			true
		);

		wp_localize_script( 'bzcode-admin', 'bzcode', [
			'rest_url'  => rest_url( 'bzcode/v1' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'slug'      => BZCODE_SLUG,
			'editor_url' => home_url( '/tool-code/' ),
		] );
	}

	/* ── Render ── */

	public static function render_page(): void {
		include BZCODE_DIR . 'views/page-projects.php';
	}

	public static function render_templates_page(): void {
		include BZCODE_DIR . 'views/page-templates.php';
	}
}
