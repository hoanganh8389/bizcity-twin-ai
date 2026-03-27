<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Auth Service — Shared authentication (login, register, phone-based)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 * @since      2.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Auth_Service {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Login user by credentials.
     *
     * @param string $username
     * @param string $password
     * @param bool   $remember
     * @return array|WP_Error  { user_id, display_name, nonce }
     */
    public function login( $username, $password, $remember = true ) {
        if ( empty( $username ) || empty( $password ) ) {
            return new WP_Error( 'missing_fields', 'Vui lòng nhập tên đăng nhập và mật khẩu.' );
        }

        $user = wp_signon( [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => $remember,
        ], is_ssl() );

        if ( is_wp_error( $user ) ) {
            $code = $user->get_error_code();
            $msg  = 'Tên đăng nhập hoặc mật khẩu không đúng.';
            if ( $code === 'invalid_username' || $code === 'invalid_email' ) {
                $msg = 'Tài khoản không tồn tại.';
            }
            return new WP_Error( $code, $msg );
        }

        wp_set_current_user( $user->ID );

        return [
            'user_id'      => $user->ID,
            'display_name' => $user->display_name,
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
        ];
    }

    /**
     * Register user (phone-based or email-based).
     *
     * @param array $args { phone, email, username, password, display_name }
     * @return array|WP_Error  { user_id, display_name, nonce }
     */
    public function register( $args ) {
        $phone    = preg_replace( '/\D/', '', $args['phone'] ?? '' );
        $email    = sanitize_email( $args['email'] ?? '' );
        $username = sanitize_user( $args['username'] ?? '' );
        $password = $args['password'] ?? '';
        $display  = sanitize_text_field( $args['display_name'] ?? '' );

        if ( ! get_option( 'users_can_register' ) ) {
            return new WP_Error( 'registration_disabled', 'Đăng ký tài khoản hiện đang bị tắt.' );
        }

        // Phone-based: normalize Vietnamese phone → username
        if ( empty( $username ) && ! empty( $phone ) ) {
            if ( preg_match( '/^84(\d{9,})$/', $phone, $m ) ) {
                $phone = '0' . $m[1];
            }
            $username = $phone;
        }

        // Auto-generate email from phone if empty
        if ( empty( $email ) || ! is_email( $email ) ) {
            if ( ! empty( $phone ) ) {
                $email = $phone . '@bizcity.vn';
            }
        }

        // Auto-generate username from email if still empty
        if ( empty( $username ) && ! empty( $email ) ) {
            $username = sanitize_user( strstr( $email, '@', true ) );
            $base = $username;
            $i    = 1;
            while ( username_exists( $username ) ) {
                $username = $base . $i;
                $i++;
            }
        }

        // Validate
        if ( empty( $email ) || ! is_email( $email ) ) {
            return new WP_Error( 'invalid_email', 'Vui lòng nhập email hoặc số điện thoại hợp lệ.' );
        }
        if ( email_exists( $email ) ) {
            return new WP_Error( 'email_exists', 'Email hoặc số điện thoại này đã được sử dụng. Hãy đăng nhập.' );
        }
        if ( empty( $username ) ) {
            return new WP_Error( 'missing_username', 'Vui lòng nhập số điện thoại.' );
        }
        if ( username_exists( $username ) ) {
            return new WP_Error( 'username_exists', 'Số điện thoại này đã được đăng ký. Hãy đăng nhập.' );
        }

        // Auto-generate password if empty
        if ( empty( $password ) ) {
            $password = wp_generate_password( 12, true );
        }

        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Set role + display name
        $user = new WP_User( $user_id );
        $user->set_role( 'subscriber' );

        if ( $display || $phone ) {
            wp_update_user( [
                'ID'           => $user_id,
                'display_name' => $display ?: $phone,
            ] );
        }

        if ( $phone ) {
            update_user_meta( $user_id, 'phone', $phone );
        }

        // Auto-login
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true, is_ssl() );

        // Notification
        wp_new_user_notification( $user_id, null, 'both' );

        return [
            'user_id'      => $user_id,
            'display_name' => $user->display_name ?: $display ?: $phone,
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'avatar_url'   => get_avatar_url( $user_id, [ 'size' => 96 ] ),
        ];
    }

    /**
     * Get current user info for frontend.
     *
     * @return array|null
     */
    public function get_current_user_info() {
        if ( ! is_user_logged_in() ) {
            return null;
        }
        $user = wp_get_current_user();
        return [
            'user_id'      => $user->ID,
            'display_name' => $user->display_name,
            'email'        => $user->user_email,
            'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
            'roles'        => $user->roles,
            'nonce'        => wp_create_nonce( 'wp_rest' ),
        ];
    }
}
