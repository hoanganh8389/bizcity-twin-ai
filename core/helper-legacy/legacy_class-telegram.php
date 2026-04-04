<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Legacy Telegram Webhook Handler.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/includes/class-bizcity-adminhook-telegram.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BizCity_AdminHook_Telegram' ) ) :
class BizCity_AdminHook_Telegram {
	public static function register() {
		add_action('rest_api_init', [__CLASS__, 'registerRestRoutes']);
		add_action('twf_tg_process_event', [__CLASS__, 'processEventCallback']);
	}

	public static function registerRestRoutes() {
		register_rest_route('telegram-trigger/v1', '/webhook/(?P<bot_token>[a-zA-Z0-9:_-]+)', [
			'methods'  => 'POST',
			'callback' => [__CLASS__, 'webhookHandler'],
			'permission_callback' => '__return_true',
		]);
	}

	public static function registerWebhook() {
		$bot_token = get_option('twf_bot_token');
		if (!$bot_token) return 'Chưa nhập Telegram Bot Token.';

		$webhook_url = home_url('/wp-json/telegram-trigger/v1/webhook/' . $bot_token);
		$api_url = "https://api.telegram.org/bot{$bot_token}/setWebhook";

		$response = wp_remote_post($api_url, [
			'body' => [ 'url' => $webhook_url ],
		]);
		if (is_wp_error($response)) {
			return 'Lỗi: ' . $response->get_error_message();
		}
		$result = json_decode(wp_remote_retrieve_body($response), true);
		if ($result && isset($result['ok']) && $result['ok'] === true) {
			return true;
		}
		return 'Telegram trả về: ' . print_r($result, true);
	}

	public static function webhookHandler($request) {
		$token_in_url = $request->get_param('bot_token');
		$official_token = get_option('twf_bot_token');
		if ($token_in_url !== $official_token) {
			return new WP_REST_Response(['error' => 'Unauthorized'], 401);
		}

		$params = $request->get_json_params();
		$uid = uniqid('tg_', true);
		set_transient('twf_tg_msg_' . $uid, $params, 60);

		$message = $params['message'] ?? [];
		$chat_id = $message['chat']['id'] ?? null;
		if ($chat_id && function_exists('twf_telegram_send_message')) {
			twf_telegram_send_message($chat_id, 'Dạ sếp');
		}

		self::processEventCallback($uid);

		return new WP_REST_Response(['status' => 'Đã nhận, sẽ xử lý sau!'], 200);
	}

	public static function processEventCallback($uid) {
		$params = get_transient('twf_tg_msg_' . $uid);
		if (!$params) return;

		$message = $params['message'] ?? [];
		$chat_id = $message['chat']['id'] ?? null;
		if (!$chat_id) {
			delete_transient('twf_tg_msg_' . $uid);
			return;
		}

		if (!function_exists('twf_get_user_id_by_chat_id')) {
			delete_transient('twf_tg_msg_' . $uid);
			return;
		}
		$user_id = twf_get_user_id_by_chat_id($chat_id);
		if (!$user_id) {
			$login_url = site_url('/telegram-login/?cid=' . (function_exists('twf_encrypt_chat_id') ? twf_encrypt_chat_id($chat_id, 'vietqr') : ''));
			if (function_exists('twf_telegram_send_message')) {
				twf_telegram_send_message($chat_id, "Bạn chưa liên kết tài khoản. Vui lòng bấm: $login_url");
			}
			return;
		}
		if (!user_can($user_id, 'manage_woocommerce')) {
			if (function_exists('twf_telegram_send_message')) {
				twf_telegram_send_message($chat_id, 'Bạn không đủ quyền thực hiện chức năng này.');
			}
			return;
		}

		if (function_exists('twf_process_flow_from_params')) {
			twf_process_flow_from_params($params);
		}

		delete_transient('twf_tg_msg_' . $uid);
	}
}endif; // class_exists guard