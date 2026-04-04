<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoang Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

if (!defined('ABSPATH')) exit;

// Stub file để tránh fatal do bootstrap/flow có case faq_* nhưng repo hiện tại thiếu file action_faq.php.
// Nếu anh có bản đầy đủ cũ, mình sẽ thay nội dung stub này bằng logic thật.

if (!function_exists('twf_handle_create_quick_faq')) {
	function twf_handle_create_quick_faq($chat_id, $text) {
		if (function_exists('twf_telegram_send_message')) {
			twf_telegram_send_message($chat_id, 'Chức năng FAQ (tạo) chưa được cài trong repo hiện tại.');
		}
		return null;
	}
}

if (!function_exists('twf_handle_edit_quick_faq_ai')) {
	function twf_handle_edit_quick_faq_ai($chat_id, $text) {
		if (function_exists('twf_telegram_send_message')) {
			twf_telegram_send_message($chat_id, 'Chức năng FAQ (sửa) chưa được cài trong repo hiện tại.');
		}
		return null;
	}
}

if (!function_exists('twf_handle_list_quick_faq')) {
	function twf_handle_list_quick_faq($chat_id) {
		if (function_exists('twf_telegram_send_message')) {
			twf_telegram_send_message($chat_id, 'Chức năng FAQ (danh sách) chưa được cài trong repo hiện tại.');
		}
		return null;
	}
}
