<?php
/**
 * Bizcity Twin AI — AJAX Login / Register
 * Đăng nhập & Đăng ký AJAX cho React LoginModal / AJAX auth for React LoginModal
 *
 * Hooks:
 *   wp_ajax_nopriv_bizcity_ajax_login
 *   wp_ajax_nopriv_bizcity_ajax_register
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.0.16
 */

defined('ABSPATH') or die();

class BizCity_WebChat_Auth_Ajax {

    public static function boot() {
        add_action('wp_ajax_nopriv_bizcity_ajax_login',    [__CLASS__, 'handle_login']);
        add_action('wp_ajax_nopriv_bizcity_ajax_register', [__CLASS__, 'handle_register']);
    }

    /**
     * AJAX Login — authenticate user and set auth cookies.
     */
    public static function handle_login() {
        check_ajax_referer('bizcity_webchat', '_ajax_nonce');

        $username = sanitize_text_field(wp_unslash($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            wp_send_json_error(['message' => 'Vui lòng điền đầy đủ thông tin.']);
        }

        $user = wp_signon([
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        ], is_ssl());

        if (is_wp_error($user)) {
            $msg = $user->get_error_message();
            // Sanitize WP error message (remove HTML links for clean display)
            $msg = wp_strip_all_tags($msg);
            if (empty($msg)) {
                $msg = 'Tên đăng nhập hoặc mật khẩu không đúng.';
            }
            wp_send_json_error(['message' => $msg]);
        }

        wp_set_current_user($user->ID);
        wp_send_json_success(['message' => 'OK', 'user_id' => $user->ID]);
    }

    /**
     * AJAX Register — create new user account + set auth cookies.
     */
    public static function handle_register() {
        check_ajax_referer('bizcity_webchat', '_ajax_nonce');

        // Check if registration is open
        if (!get_option('users_can_register') && !apply_filters('woocommerce_enable_myaccount_registration', false)) {
            wp_send_json_error(['message' => 'Đăng ký tài khoản hiện đang tắt.']);
        }

        $email    = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $phone    = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($email)) {
            wp_send_json_error(['message' => 'Vui lòng nhập email.']);
        }
        if (!is_email($email)) {
            wp_send_json_error(['message' => 'Email không hợp lệ.']);
        }
        if (email_exists($email)) {
            wp_send_json_error(['message' => 'Email này đã được sử dụng.']);
        }
        if (empty($phone)) {
            wp_send_json_error(['message' => 'Vui lòng nhập số điện thoại.']);
        }
        if (empty($password) || strlen($password) < 6) {
            wp_send_json_error(['message' => 'Mật khẩu phải có ít nhất 6 ký tự.']);
        }

        // Generate username from email local part
        $username = sanitize_user(strstr($email, '@', true), true);
        if (username_exists($username)) {
            $username = $username . '_' . wp_rand(100, 999);
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id)) {
            $msg = wp_strip_all_tags($user_id->get_error_message());
            wp_send_json_error(['message' => $msg ?: 'Không thể tạo tài khoản.']);
        }

        // Save phone
        update_user_meta($user_id, 'phone', $phone);
        update_user_meta($user_id, 'billing_phone', $phone);

        // Auto-login
        wp_set_auth_cookie($user_id, true, is_ssl());
        wp_set_current_user($user_id);

        // Fire WC hook so other plugins can react
        do_action('woocommerce_created_customer', $user_id, [], $password);

        wp_send_json_success(['message' => 'OK', 'user_id' => $user_id]);
    }
}
