<?php
/**
 * Legacy Admin Menu — WP admin menus for Zalo/Telegram.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/includes/class-bizcity-adminhook-adminmenu.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BizCity_AdminHook_AdminMenu' ) ) :
class BizCity_AdminHook_AdminMenu {
	public static function register() {
		add_action('admin_menu', [__CLASS__, 'registerMenus']);
	}

	public static function registerMenus() {
		add_menu_page(
			'Biz-life',
			'Bots - Zalo BizCity',
			'manage_options',
			'bizlife_dashboard',
			'zalo-video-guider',
			'dashicons-format-status',
			1
		);
		add_submenu_page(
			'bizlife_dashboard',
			'Hướng dẫn ra lệnh qua zalo BizCity',
			'Hướng dẫn ra lệnh qua zalo BizCity',
			'manage_options',
			'zalo-video-guider',
			'bizcity_guides_admin_page',
			'0',
		);
		add_submenu_page(
			'bizlife_dashboard',
			'Tài khoản quản trị qua Zalo BizCity',
			'Tài khoản quản trị qua Zalo BizCity',
			'manage_options',
			'zalo-users-admin',
			'twf_zalo_users_admin_page'
		);
		add_submenu_page(
			'bizlife_dashboard',
			'Hướng dẫn kết nối Zalo BizCity',
			'Hướng dẫn kết nối Zalo BizCity',
			'manage_options',
			'zalo-guider',
			'twf_telegram_command_widget_content'
		);
	}
}endif; // class_exists guard