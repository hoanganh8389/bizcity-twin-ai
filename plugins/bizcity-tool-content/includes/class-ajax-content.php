<?php
/**
 * BizCity Tool Content — AJAX Handlers
 *
 * Two-step flow: Generate Preview → Publish.
 * Plus: prompt history, WP settings, file upload.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Ajax_Content {

    public static function init() {
        add_action( 'wp_ajax_bztc_generate_preview',  array( __CLASS__, 'handle_generate_preview' ) );
        add_action( 'wp_ajax_bztc_publish_post',       array( __CLASS__, 'handle_publish_post' ) );
        add_action( 'wp_ajax_bztc_poll_history',       array( __CLASS__, 'handle_poll_history' ) );
        add_action( 'wp_ajax_bztc_save_wp_settings',   array( __CLASS__, 'handle_save_wp_settings' ) );
        add_action( 'wp_ajax_bztc_upload_image',       array( __CLASS__, 'handle_upload_image' ) );
        add_action( 'wp_ajax_bztc_rerun_prompt',       array( __CLASS__, 'handle_rerun_prompt' ) );
    }

    /* ══════════════════════════════════════════════════════
     *  Generate Preview (AI only, no posting)
     * ══════════════════════════════════════════════════════ */
    public static function handle_generate_preview() {
        check_ajax_referer( 'bztc_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $topic     = sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $tone      = sanitize_text_field( $_POST['tone'] ?? 'friendly' );

        if ( empty( $topic ) ) wp_send_json_error( 'Vui lòng nhập chủ đề bài viết.' );

        // AI generate title + content (no publish)
        $ai_title   = '';
        $ai_content = '';

        if ( function_exists( 'bizcity_openrouter_chat' ) ) {
            $prompt = "Viết bài blog tiếng Việt hoàn chỉnh, tone {$tone}.\nChủ đề: {$topic}\n\n"
                . "YÊU CẦU: Tiêu đề dưới 80 ký tự, nội dung >= 700 từ, HTML (h2,h3,strong,em). KHÔNG markdown.\n"
                . "Trả về JSON: {\"title\":\"...\",\"content\":\"...\"}";

            $result = bizcity_openrouter_chat( [
                [ 'role' => 'system', 'content' => 'Bạn là nhà sáng tạo nội dung blog chuyên nghiệp. Chỉ trả JSON.' ],
                [ 'role' => 'user',   'content' => $prompt ],
            ], [ 'temperature' => 0.75, 'max_tokens' => 4000 ] );

            $raw    = $result['message'] ?? '';
            $parsed = json_decode( preg_replace( '/^```json\s*|```$/m', '', trim( $raw ) ), true );
            if ( is_array( $parsed ) ) {
                $ai_title   = $parsed['title']   ?? '';
                $ai_content = $parsed['content']  ?? '';
            }
        }

        // Generate image if not provided
        if ( empty( $image_url ) && function_exists( 'twf_generate_image_url' ) ) {
            $image_url = twf_generate_image_url( $topic );
        }

        if ( empty( $ai_content ) ) {
            wp_send_json_error( 'AI không tạo được nội dung. Vui lòng thử lại.' );
        }

        wp_send_json_success( array(
            'title'     => $ai_title,
            'content'   => $ai_content,
            'image_url' => $image_url,
        ) );
    }

    /* ══════════════════════════════════════════════════════
     *  Publish Post (from previewed content)
     * ══════════════════════════════════════════════════════ */
    public static function handle_publish_post() {
        check_ajax_referer( 'bztc_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $title     = sanitize_textarea_field( wp_unslash( $_POST['title'] ?? '' ) );
        $content   = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $topic     = sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) );

        if ( empty( $content ) ) wp_send_json_error( 'Không có nội dung để đăng.' );

        // Check WP connection settings
        $wp_settings = self::get_wp_settings( $user_id );

        if ( $wp_settings && ! empty( $wp_settings['site_url'] ) ) {
            // External WordPress — use REST API
            $result = self::publish_to_external_wp( $wp_settings, $title, $content, $image_url );
        } else {
            // Local WordPress
            $result = self::publish_to_local_wp( $user_id, $title, $content, $image_url );
        }

        if ( ! $result['success'] ) {
            wp_send_json_error( $result['message'] );
        }

        // Save to prompt history
        BizCity_Content_Post_Type::save_history(
            $user_id,
            'write_article',
            $topic ?: $title,
            $title,
            $content,
            $result['post_id'] ?? null,
            $result['url'] ?? '',
            $image_url
        );

        wp_send_json_success( $result );
    }

    /**
     * Publish to local WordPress.
     */
    private static function publish_to_local_wp( int $user_id, string $title, string $content, string $image_url ): array {
        if ( function_exists( 'twf_wp_create_post' ) ) {
            $post_id = twf_wp_create_post( $title, $content, $image_url );
        } else {
            $post_id = wp_insert_post( array(
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_author'  => $user_id,
            ) );

            if ( $post_id && ! is_wp_error( $post_id ) && $image_url ) {
                self::attach_image( $post_id, $image_url );
            }
        }

        if ( ! $post_id || is_wp_error( $post_id ) ) {
            return [ 'success' => false, 'message' => 'Lỗi tạo bài viết WordPress.' ];
        }

        return [
            'success'    => true,
            'post_id'    => $post_id,
            'url'        => get_permalink( $post_id ),
            'edit_url'   => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
            'message'    => 'Đăng bài thành công!',
        ];
    }

    /**
     * Publish to external WordPress via REST API.
     */
    private static function publish_to_external_wp( array $settings, string $title, string $content, string $image_url ): array {
        $site_url = rtrim( $settings['site_url'], '/' );
        $username = $settings['username'] ?? '';
        $app_pass = $settings['app_password'] ?? '';

        if ( empty( $site_url ) || empty( $username ) || empty( $app_pass ) ) {
            return [ 'success' => false, 'message' => 'Thiếu thông tin kết nối WordPress (URL, username, app password).' ];
        }

        $endpoint = $site_url . '/wp-json/wp/v2/posts';

        $body = array(
            'title'   => $title,
            'content' => $content,
            'status'  => 'publish',
        );

        $response = wp_remote_post( $endpoint, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_pass ),
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => 'Lỗi kết nối: ' . $response->get_error_message() ];
        }

        $code   = wp_remote_retrieve_response_code( $response );
        $result = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 201 && $code !== 200 ) {
            $msg = $result['message'] ?? "HTTP {$code}";
            return [ 'success' => false, 'message' => 'Lỗi từ WordPress: ' . $msg ];
        }

        return [
            'success' => true,
            'post_id' => $result['id'] ?? null,
            'url'     => $result['link'] ?? '',
            'message' => 'Đăng bài thành công lên ' . $site_url,
        ];
    }

    /**
     * Get WP connection settings for a user.
     * Admin users always use local WP (return null).
     * Non-admin users may have external WP config.
     */
    private static function get_wp_settings( int $user_id ): ?array {
        // Admin auto-uses local
        if ( current_user_can( 'manage_options' ) ) {
            $force_external = get_user_meta( $user_id, 'bztc_force_external_wp', true );
            if ( ! $force_external ) return null;
        }

        $site_url = get_user_meta( $user_id, 'bztc_wp_site_url', true );
        if ( empty( $site_url ) ) return null;

        return array(
            'site_url'     => $site_url,
            'username'     => get_user_meta( $user_id, 'bztc_wp_username', true ),
            'app_password' => get_user_meta( $user_id, 'bztc_wp_app_password', true ),
        );
    }

    /* ══════════════════════════════════════════════════════
     *  Prompt History
     * ══════════════════════════════════════════════════════ */
    public static function handle_poll_history() {
        check_ajax_referer( 'bztc_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $items = BizCity_Content_Post_Type::get_history( $user_id, 30 );

        wp_send_json_success( array( 'items' => $items ) );
    }

    /**
     * Re-run a prompt from history.
     */
    public static function handle_rerun_prompt() {
        check_ajax_referer( 'bztc_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $history_id = (int) ( $_POST['history_id'] ?? 0 );
        if ( ! $history_id ) wp_send_json_error( 'Thiếu history_id.' );

        $row = BizCity_Content_Post_Type::get_entry( $history_id, $user_id );

        if ( ! $row ) wp_send_json_error( 'Không tìm thấy prompt.' );

        wp_send_json_success( array(
            'prompt'  => $row->prompt,
            'goal'    => $row->goal,
        ) );
    }

    /* ══════════════════════════════════════════════════════
     *  WP Connection Settings
     * ══════════════════════════════════════════════════════ */
    public static function handle_save_wp_settings() {
        check_ajax_referer( 'bztc_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        $site_url     = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );
        $username     = sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) );
        $app_password = sanitize_text_field( wp_unslash( $_POST['app_password'] ?? '' ) );

        if ( empty( $site_url ) ) {
            // Clearing settings = use local WP
            delete_user_meta( $user_id, 'bztc_wp_site_url' );
            delete_user_meta( $user_id, 'bztc_wp_username' );
            delete_user_meta( $user_id, 'bztc_wp_app_password' );
            delete_user_meta( $user_id, 'bztc_force_external_wp' );
            wp_send_json_success( array( 'message' => 'Đã chuyển về đăng bài trên WordPress nội bộ.' ) );
        }

        if ( empty( $username ) || empty( $app_password ) ) {
            wp_send_json_error( 'Cần nhập Username và Application Password.' );
        }

        // Test connection
        $test_url = rtrim( $site_url, '/' ) . '/wp-json/wp/v2/posts?per_page=1';
        $response = wp_remote_get( $test_url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $app_password ),
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Không kết nối được: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 401 || $code === 403 ) {
            wp_send_json_error( 'Sai Username hoặc Application Password (HTTP ' . $code . ').' );
        }
        if ( $code !== 200 ) {
            wp_send_json_error( 'WordPress trả về HTTP ' . $code . '. Kiểm tra lại URL.' );
        }

        update_user_meta( $user_id, 'bztc_wp_site_url', $site_url );
        update_user_meta( $user_id, 'bztc_wp_username', $username );
        update_user_meta( $user_id, 'bztc_wp_app_password', $app_password );
        update_user_meta( $user_id, 'bztc_force_external_wp', '1' );

        wp_send_json_success( array( 'message' => 'Kết nối thành công! Bài viết sẽ được đăng trên ' . $site_url ) );
    }

    /* ══════════════════════════════════════════════════════
     *  File Upload
     * ══════════════════════════════════════════════════════ */
    public static function handle_upload_image() {
        check_ajax_referer( 'bztc_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) wp_send_json_error( 'Chưa đăng nhập.' );

        if ( empty( $_FILES['image'] ) ) {
            wp_send_json_error( 'Không có file nào được gửi lên.' );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attach_id = media_handle_upload( 'image', 0 );
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( 'Lỗi upload: ' . $attach_id->get_error_message() );
        }

        wp_send_json_success( array(
            'url'       => wp_get_attachment_url( $attach_id ),
            'attach_id' => $attach_id,
        ) );
    }

    /**
     * Attach an image URL as featured image.
     */
    private static function attach_image( int $post_id, string $url ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $attach_id = media_sideload_image( $url, $post_id, '', 'id' );
        if ( ! is_wp_error( $attach_id ) ) {
            set_post_thumbnail( $post_id, $attach_id );
        }
    }
}
