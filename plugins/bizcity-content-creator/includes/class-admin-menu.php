<?php
/**
 * Admin menu — registers pages for Content Creator.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_Admin_Menu {

	const MENU_SLUG = 'bizcity-creator';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		// Menu registration moved to BizCity_Admin_Menu (centralized).
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	/* ── Menu ── */

	public static function register_menu(): void {
		add_menu_page(
			'Dạy AI làm nội dung sáng tạo',
			'Dạy AI làm nội dung',
			'read',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-media-document',
			73
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Templates',
			'Templates',
			'manage_options',
			self::MENU_SLUG . '-templates',
			[ __CLASS__, 'render_templates_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Categories',
			'Danh mục',
			'manage_options',
			self::MENU_SLUG . '-categories',
			[ __CLASS__, 'render_categories_page' ]
		);
	}

	/* ── Assets ── */

	public static function enqueue_assets( string $hook ): void {
		if ( strpos( $hook, self::MENU_SLUG ) === false ) {
			return;
		}

		wp_enqueue_style(
			'bzcc-admin',
			BZCC_URL . 'assets/admin.css',
			[],
			BZCC_VERSION
		);

		wp_enqueue_script(
			'bzcc-admin',
			BZCC_URL . 'assets/admin.js',
			[ 'jquery' ],
			BZCC_VERSION,
			true
		);

		wp_localize_script( 'bzcc-admin', 'bzcc', [
			'rest_url' => rest_url( 'bzcc/v1' ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'slug'     => BZCC_SLUG,
		] );

		/* Form Builder assets — only on template add/edit page */
		if ( strpos( $hook, 'templates' ) !== false ) {
			wp_enqueue_style(
				'bzcc-frontend-preview',
				BZCC_URL . 'assets/frontend.css',
				[],
				BZCC_VERSION
			);
			wp_enqueue_style(
				'bzcc-form-builder',
				BZCC_URL . 'assets/admin-form-builder.css',
				[ 'bzcc-admin', 'bzcc-frontend-preview' ],
				BZCC_VERSION
			);
			wp_enqueue_script(
				'bzcc-form-builder',
				BZCC_URL . 'assets/admin-form-builder.js',
				[ 'jquery' ],
				BZCC_VERSION,
				true
			);
		}
	}

	/* ── Page renderers ── */

	public static function render_page(): void {
		$tab = sanitize_key( $_GET['tab'] ?? 'dashboard' );
		include BZCC_DIR . 'views/admin-dashboard.php';
	}

	public static function render_templates_page(): void {
		$action = sanitize_key( $_GET['action'] ?? 'list' );
		$id     = absint( $_GET['id'] ?? 0 );
		include BZCC_DIR . 'views/admin-templates.php';
	}

	public static function render_categories_page(): void {
		include BZCC_DIR . 'views/admin-categories.php';
	}
}
