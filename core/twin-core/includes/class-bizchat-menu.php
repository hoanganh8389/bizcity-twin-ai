<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizChat Menu — Unified Admin Menu Registry
 *
 * Centralized menu manager for the Chat admin area.
 * Core, modules, and plugins can register submenus under the main
 * "Chat" top-level page via the `bizchat_register_menus` hook.
 *
 * Usage:
 *   add_action( 'bizchat_register_menus', function () {
 *       BizChat_Menu::add_submenu( 'my-page', [
 *           'title'      => 'My Page',
 *           'menu_title' => '📄 My Page',
 *           'callback'   => [ MyClass::class, 'render' ],
 *           'position'   => 50,
 *       ] );
 *   } );
 *
 * @package  BizCity_Twin_Core
 * @since    0.3.0
 */

defined( 'ABSPATH' ) || exit;

class BizChat_Menu {

	/**
	 * Parent menu slug — the main Chat dashboard page.
	 */
	const PARENT_SLUG = 'bizcity-webchat-dashboard';

	/**
	 * Registered submenu items.
	 *
	 * @var array<string, array>
	 */
	private static array $items = [];

	/**
	 * Boot the menu system. Call once from twin-core bootstrap.
	 */
	public static function boot(): void {
		// Priority 30 ensures the parent menu (registered at default priority) exists first.
		add_action( 'admin_menu', [ __CLASS__, 'build_menus' ], 30 );
	}

	/**
	 * Register a submenu page under the Chat parent.
	 *
	 * @param string $slug  Unique menu slug (e.g. 'bizchat-gateway').
	 * @param array  $args {
	 *     @type string   $title      Page title.
	 *     @type string   $menu_title Menu label (supports emoji).
	 *     @type string   $capability Required capability. Default 'manage_options'.
	 *     @type callable $callback   Render callback.
	 *     @type int      $position   Sort order (lower = higher). Default 100.
	 * }
	 */
	public static function add_submenu( string $slug, array $args ): void {
		self::$items[ $slug ] = wp_parse_args( $args, [
			'title'      => '',
			'menu_title' => '',
			'capability' => 'manage_options',
			'callback'   => '__return_null',
			'position'   => 100,
		] );
	}

	/**
	 * Fires the registration hook and registers all collected submenus.
	 *
	 * @internal Called by admin_menu @30.
	 */
	public static function build_menus(): void {
		/**
		 * Fires when BizChat Menu is ready to accept submenu registrations.
		 *
		 * @since 0.3.0
		 */
		do_action( 'bizchat_register_menus' );

		if ( empty( self::$items ) ) {
			return;
		}

		// Sort by position, then by slug for stable ordering.
		uasort( self::$items, function ( $a, $b ) {
			return $a['position'] <=> $b['position'] ?: strcmp( (string) key( [ $a ] ), (string) key( [ $b ] ) );
		} );

		foreach ( self::$items as $slug => $item ) {
			add_submenu_page(
				self::PARENT_SLUG,
				$item['title'],
				$item['menu_title'],
				$item['capability'],
				$slug,
				$item['callback'],
				$item['position']
			);
		}
	}

	/**
	 * Get all registered items (for debugging / status pages).
	 *
	 * @return array<string, array>
	 */
	public static function get_items(): array {
		return self::$items;
	}
}
