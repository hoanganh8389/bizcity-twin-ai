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
 * Legacy Webchat helper.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/lib/class-bizcity-adminhook-webchat.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BizCity_AdminHook_Webchat' ) ) :
class BizCity_AdminHook_Webchat {
	public static function sendMessageToSession($user_id, $session_id, $reply_content) {
		if (function_exists('bizgpt_log_chat_message')) {
			bizgpt_log_chat_message($user_id, $reply_content, 'bot', $session_id, 'telegram');
		}
	}
}endif; // class_exists guard