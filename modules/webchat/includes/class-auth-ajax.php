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
        add_action('wp_ajax_nopriv_bizcity_ajax_login',           [__CLASS__, 'handle_login']);
        add_action('wp_ajax_nopriv_bizcity_ajax_register',        [__CLASS__, 'handle_register']);
        // [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP FE-4 — forgot password AJAX handler
        add_action('wp_ajax_nopriv_bizcity_ajax_forgot_password', [__CLASS__, 'handle_forgot_password']);
        add_action('wp_ajax_bizcity_ajax_forgot_password',        [__CLASS__, 'handle_forgot_password']);
        // [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — change password + update profile AJAX handlers
        add_action('wp_ajax_bizcity_ajax_change_password', [__CLASS__, 'handle_change_password']);
        add_action('wp_ajax_bizcity_ajax_update_profile',  [__CLASS__, 'handle_update_profile']);
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
        // Phone là tùy chọn — email là định danh chính
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

    /**
     * AJAX Forgot Password — send WP password-reset email.
     *
     * [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP FE-4 — forgot password handler.
     * Calls retrieve_password() (WP core) which sends the reset email.
     * Registered for both nopriv (guests) and priv (logged-in) contexts.
     *
     * @return void Sends JSON and exits.
     */
    public static function handle_forgot_password() {
        check_ajax_referer('bizcity_webchat', '_ajax_nonce');

        $user_login = sanitize_text_field(wp_unslash($_POST['user_login'] ?? ''));
        if (empty($user_login)) {
            wp_send_json_error(['message' => 'Vui lòng nhập email hoặc tên đăng nhập.']);
        }

        $result = retrieve_password($user_login);
        if (is_wp_error($result)) {
            $msg = wp_strip_all_tags($result->get_error_message());
            wp_send_json_error(['message' => $msg ?: 'Không thể gửi email đặt lại mật khẩu.']);
        }

        wp_send_json_success(['message' => 'OK']);
    }

    /**
     * AJAX change password — verified via current password before updating.
     */
    public static function handle_change_password() {
        // [2026-06-04 Johnny Chu] PHASE-MEMBERSHIP BE-3A — change password handler
        check_ajax_referer('bizcity_webchat', '_ajax_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bạn chưa đăng nhập.']);
        }

        $current_pass = isset($_POST['current_pass']) ? $_POST['current_pass'] : '';
        $new_pass     = sanitize_text_field(wp_unslash(isset($_POST['new_pass']) ? $_POST['new_pass'] : ''));

        if (strlen($new_pass) < 8) {
            wp_send_json_error(['message' => 'Mật khẩu mới phải có ít nhất 8 ký tự.']);
        }

        $user = wp_get_current_user();
        if (!wp_check_password($current_pass, $user->user_pass, $user->ID)) {
            wp_send_json_error(['message' => 'Mật khẩu hiện tại không đúng.']);
        }

        wp_set_password($new_pass, $user->ID);
        wp_send_json_success(['message' => 'OK']);
    }

    /**
     * AJAX update display name, first/last name, phone, bio.
     */
    public static function handle_update_profile() {
        // [2026-06-05 Johnny Chu] PHASE-MEMBERSHIP BE-3A — extend profile: first_name/last_name/phone/bio
        check_ajax_referer('bizcity_webchat', '_ajax_nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => 'Bạn chưa đăng nhập.']);
        }

        $uid          = get_current_user_id();
        $display_name = sanitize_text_field(wp_unslash(isset($_POST['display_name']) ? $_POST['display_name'] : ''));
        $first_name   = sanitize_text_field(wp_unslash(isset($_POST['first_name'])   ? $_POST['first_name']   : ''));
        $last_name    = sanitize_text_field(wp_unslash(isset($_POST['last_name'])    ? $_POST['last_name']    : ''));
        $phone        = sanitize_text_field(wp_unslash(isset($_POST['phone'])        ? $_POST['phone']        : ''));
        $bio          = sanitize_textarea_field(wp_unslash(isset($_POST['bio'])      ? $_POST['bio']          : ''));

        if (empty($display_name)) {
            wp_send_json_error(['message' => 'Tên hiển thị không được để trống.']);
        }

        $user_data = ['ID' => $uid, 'display_name' => $display_name];
        if ($first_name !== '') $user_data['first_name']   = $first_name;
        if ($last_name  !== '') $user_data['last_name']    = $last_name;
        if ($bio        !== '') $user_data['description']  = $bio;

        $result = wp_update_user($user_data);

        if (is_wp_error($result)) {
            $msg = wp_strip_all_tags($result->get_error_message());
            wp_send_json_error(['message' => $msg ? $msg : 'Không thể lưu thay đổi.']);
        }

        // Save phone to usermeta (+ WooCommerce billing_phone compat)
        update_user_meta($uid, 'phone', $phone);
        update_user_meta($uid, 'billing_phone', $phone);
        // Also sync first/last if sent
        if ($first_name !== '') update_user_meta($uid, 'first_name', $first_name);
        if ($last_name  !== '') update_user_meta($uid, 'last_name',  $last_name);

        wp_send_json_success(['message' => 'OK']);
    }
}
