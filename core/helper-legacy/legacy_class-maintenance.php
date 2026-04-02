<?php
/**
 * Legacy Maintenance — ensures telegram-login page exists.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/lib/class-bizcity-adminhook-maintenance.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BizCity_AdminHook_Maintenance' ) ) :
class BizCity_AdminHook_Maintenance {
	public static function register() {
		add_action('admin_init', [__CLASS__, 'ensureTelegramLoginPage']);
	}

	public static function ensureTelegramLoginPage() {
		$page = get_page_by_path('telegram-login');
		if ($page) return;

		wp_insert_post([
			'post_title'   => 'Telegram Login',
			'post_name'    => 'telegram-login',
			'post_content' => '[telegram_login_form]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		]);
	}
}endif; // class_exists guard