<?php
/**
 * BizCity Tool Facebook — AJAX Handlers
 *
 * Handles AJAX requests for the Facebook tool profile view.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Ajax_Facebook {

    public static function init() {
        add_action( 'wp_ajax_bztfb_generate_post',  array( __CLASS__, 'handle_generate_post' ) );
        add_action( 'wp_ajax_bztfb_poll_jobs',       array( __CLASS__, 'handle_poll_jobs' ) );
        add_action( 'wp_ajax_bztfb_save_settings',   array( __CLASS__, 'handle_save_settings' ) );
        add_action( 'wp_ajax_bztfb_connect_page',    array( __CLASS__, 'handle_connect_page' ) );
        add_action( 'wp_ajax_bztfb_disconnect_page', array( __CLASS__, 'handle_disconnect_page' ) );
        add_action( 'wp_ajax_bztfb_register_route',  array( __CLASS__, 'handle_register_route' ) );
        add_action( 'wp_ajax_bztfb_set_user_page',   array( __CLASS__, 'handle_set_user_page' ) );
        // Plan A: save Facebook profile for tester request
        add_action( 'wp_ajax_bztfb_save_fb_profile', array( __CLASS__, 'handle_save_fb_profile' ) );
        // Plan B: save user's own Facebook Developer app config
        add_action( 'wp_ajax_bztfb_save_user_app',   array( __CLASS__, 'handle_save_user_app' ) );
        // Two-step post flow: preview then publish
        add_action( 'wp_ajax_bztfb_generate_preview', array( __CLASS__, 'handle_generate_preview' ) );
        add_action( 'wp_ajax_bztfb_publish_post',     array( __CLASS__, 'handle_publish_post' ) );
        // File upload
        add_action( 'wp_ajax_bztfb_upload_image',     array( __CLASS__, 'handle_upload_image' ) );
    }

    /**
     * Set which Fanpage the current user is assigned to.
     * Any logged-in user can select from site-connected pages.
     */
    public static function handle_set_user_page() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        $page_id = sanitize_text_field( wp_unslash( $_POST['page_id'] ?? '' ) );

        // Allow clearing selection
        if ( empty( $page_id ) ) {
            delete_user_meta( $user_id, 'bztfb_user_page' );
            wp_send_json_success( array( 'message' => 'Đã bỏ chọn Page mặc định.' ) );
        }

        // Validate page_id exists in site-connected pages
        $pages = get_option( 'fb_pages_connected', array() );
        $found = false;
        if ( is_array( $pages ) ) {
            foreach ( $pages as $page ) {
                if ( ( $page['id'] ?? '' ) === $page_id ) {
                    $found = true;
                    break;
                }
            }
        }

        // Also check user's Plan B bots
        if ( ! $found && class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
            $user_bots = BizCity_Facebook_Bot_Database::instance()->get_bots_by_user( $user_id );
            foreach ( $user_bots as $bot ) {
                if ( $bot->page_id === $page_id ) {
                    $found = true;
                    break;
                }
            }
        }

        if ( ! $found ) {
            wp_send_json_error( 'Page ID không hợp lệ hoặc chưa được kết nối trên site này.' );
        }

        update_user_meta( $user_id, 'bztfb_user_page', $page_id );
        wp_send_json_success( array( 'message' => 'Đã gán Page mặc định cho bạn.' ) );
    }

    /**
     * Generate and post Facebook content via AI.
     */
    public static function handle_generate_post() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        $topic     = sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $page_ids  = isset( $_POST['page_ids'] ) && is_array( $_POST['page_ids'] )
            ? array_map( 'sanitize_text_field', $_POST['page_ids'] )
            : array();

        if ( empty( $topic ) ) {
            wp_send_json_error( 'Vui lòng nhập chủ đề/nội dung bài viết.' );
        }

        // Create job record
        global $wpdb;
        $table = $wpdb->prefix . 'bztfb_jobs';
        $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'session_id' => sanitize_text_field( $_POST['session_id'] ?? '' ),
            'topic'      => $topic,
            'image_url'  => $image_url,
            'page_ids'   => wp_json_encode( $page_ids ),
            'status'     => 'generating',
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );
        $job_id = $wpdb->insert_id;

        // Call the tool directly
        $result = BizCity_Tool_Facebook::create_facebook_post( array(
            'user_id'   => $user_id,
            'topic'     => $topic,
            'image_url' => $image_url,
            'page_ids'  => $page_ids,
            'job_id'    => $job_id,
        ) );

        if ( $result['success'] ) {
            $wpdb->update( $table, array(
                'status'       => 'completed',
                'ai_title'     => $result['data']['title'] ?? '',
                'ai_content'   => $result['data']['content'] ?? '',
                'wp_post_id'   => $result['data']['wp_post_id'] ?? null,
                'fb_post_ids'  => wp_json_encode( $result['data']['fb_post_ids'] ?? array() ),
                'completed_at' => current_time( 'mysql' ),
            ), array( 'id' => $job_id ), array( '%s', '%s', '%s', '%d', '%s', '%s' ), array( '%d' ) );
        } else {
            $wpdb->update( $table, array(
                'status'        => 'failed',
                'error_message' => $result['message'] ?? 'Unknown error',
            ), array( 'id' => $job_id ), array( '%s', '%s' ), array( '%d' ) );
        }

        wp_send_json( $result );
    }

    /**
     * Poll recent jobs for current user.
     */
    public static function handle_poll_jobs() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztfb_jobs';
        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 20",
            $user_id
        ) );

        $total     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
        $completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = %s", $user_id, 'completed' ) );

        wp_send_json_success( array(
            'jobs'  => $jobs,
            'stats' => array( 'total' => $total, 'completed' => $completed ),
        ) );
    }

    /**
     * Save Facebook tool settings.
     */
    public static function handle_save_settings() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Không có quyền.' );
        }

        $ai_model = sanitize_text_field( $_POST['ai_model'] ?? 'gpt-4o' );
        update_option( 'bztfb_ai_model', $ai_model );

        wp_send_json_success( 'Đã lưu cài đặt.' );
    }

    /**
     * Connect a Facebook Page — saves locally + registers in global route table.
     */
    public static function handle_connect_page() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Không có quyền.' );
        }

        $access_token = sanitize_text_field( wp_unslash( $_POST['access_token'] ?? '' ) );
        if ( empty( $access_token ) ) {
            wp_send_json_error( 'Vui lòng nhập Page Access Token.' );
        }

        // Verify token with Facebook
        $response = wp_remote_get( "https://graph.facebook.com/v18.0/me?fields=id,name,picture&access_token=" . urlencode( $access_token ) );
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( 'Không kết nối được Facebook: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['id'] ) ) {
            wp_send_json_error( 'Token không hợp lệ: ' . ( $body['error']['message'] ?? 'Unknown error' ) );
        }

        $page_id   = sanitize_text_field( $body['id'] );
        $page_name = sanitize_text_field( $body['name'] ?? '' );

        // Save to local site options (for tools to read access token)
        $pages = get_option( 'fb_pages_connected', array() );
        if ( ! is_array( $pages ) ) $pages = array();

        $found = false;
        foreach ( $pages as &$p ) {
            if ( ( $p['id'] ?? '' ) === $page_id ) {
                $p['access_token'] = $access_token;
                $p['name']         = $page_name;
                $found = true;
                break;
            }
        }
        unset( $p );

        if ( ! $found ) {
            $pages[] = array(
                'id'           => $page_id,
                'name'         => $page_name,
                'access_token' => $access_token,
            );
        }
        update_option( 'fb_pages_connected', $pages );

        // Register in global route table (db.php routes to global DB)
        if ( class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
            BizCity_Facebook_Central_Webhook::register_route(
                $page_id, get_current_blog_id(), $page_name, $access_token
            );
        }

        wp_send_json_success( array(
            'page_id'   => $page_id,
            'page_name' => $page_name,
            'message'   => "Đã kết nối Page: {$page_name} ({$page_id})",
        ) );
    }

    /**
     * Disconnect a page — removes locally + from global route table.
     */
    public static function handle_disconnect_page() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Không có quyền.' );
        }

        $page_id = sanitize_text_field( $_POST['page_id'] ?? '' );
        if ( empty( $page_id ) ) {
            wp_send_json_error( 'Thiếu Page ID.' );
        }

        // Remove from local option
        $pages = get_option( 'fb_pages_connected', array() );
        $pages = array_filter( $pages, function( $p ) use ( $page_id ) {
            return ( $p['id'] ?? '' ) !== $page_id;
        } );
        update_option( 'fb_pages_connected', array_values( $pages ) );

        // Remove from global route table
        if ( class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
            BizCity_Facebook_Central_Webhook::unregister_route( $page_id );
        }

        wp_send_json_success( 'Đã ngắt kết nối.' );
    }

    /**
     * Sync all local pages to the global route table.
     */
    public static function handle_register_route() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Không có quyền.' );
        }

        if ( ! class_exists( 'BizCity_Facebook_Central_Webhook' ) ) {
            wp_send_json_error( 'Central Webhook không khả dụng.' );
        }

        $pages = get_option( 'fb_pages_connected', array() );
        $registered = 0;
        $blog_id = get_current_blog_id();
        foreach ( $pages as $page ) {
            if ( ! empty( $page['id'] ) && ! empty( $page['access_token'] ) ) {
                BizCity_Facebook_Central_Webhook::register_route(
                    $page['id'], $blog_id, $page['name'] ?? '', $page['access_token']
                );
                $registered++;
            }
        }

        wp_send_json_success( "Đã đăng ký {$registered} page lên central webhook." );
    }

    /* ══════════════════════════════════════════════════════
     *  PLAN A: Save Facebook Profile for Tester Request
     * ══════════════════════════════════════════════════════ */

    /**
     * Save user's Facebook URL / username / ID for tester request.
     * Network admin will review and add to Facebook Developer App Roles.
     */
    public static function handle_save_fb_profile() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        $fb_profile = sanitize_text_field( wp_unslash( $_POST['fb_profile'] ?? '' ) );

        if ( empty( $fb_profile ) ) {
            delete_user_meta( $user_id, 'bztfb_facebook_profile' );
            delete_user_meta( $user_id, 'bztfb_tester_requested_at' );
            wp_send_json_success( array( 'message' => 'Đã xóa thông tin Facebook profile.' ) );
        }

        // Basic validation: URL, numeric ID, or username
        $fb_profile = trim( $fb_profile );
        $is_valid = false;

        if ( filter_var( $fb_profile, FILTER_VALIDATE_URL ) ) {
            // Must be facebook.com URL
            $host = wp_parse_url( $fb_profile, PHP_URL_HOST );
            if ( $host && preg_match( '/facebook\.com$/i', $host ) ) {
                $is_valid = true;
            }
        } elseif ( preg_match( '/^\d{5,20}$/', $fb_profile ) ) {
            // Numeric Facebook ID
            $is_valid = true;
        } elseif ( preg_match( '/^[a-zA-Z0-9.]{5,50}$/', $fb_profile ) ) {
            // Facebook username
            $is_valid = true;
        }

        if ( ! $is_valid ) {
            wp_send_json_error( 'Vui lòng nhập URL Facebook hợp lệ (vd: https://facebook.com/username), Facebook ID (số), hoặc username.' );
        }

        update_user_meta( $user_id, 'bztfb_facebook_profile', $fb_profile );
        update_user_meta( $user_id, 'bztfb_tester_requested_at', current_time( 'mysql' ) );
        update_user_meta( $user_id, 'bztfb_tester_blog_id', get_current_blog_id() );

        wp_send_json_success( array( 'message' => 'Đã lưu. Super Admin sẽ thêm bạn vào danh sách Tester của Facebook App.' ) );
    }

    /* ══════════════════════════════════════════════════════
     *  PLAN B: Save User's Own Facebook Developer App Config
     * ══════════════════════════════════════════════════════ */

    /**
     * Save user's own Facebook App ID + App Secret.
     * Creates/updates a bot record in bizcity_facebook_bots with user_id.
     */
    public static function handle_save_user_app() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        $app_id     = sanitize_text_field( wp_unslash( $_POST['user_app_id'] ?? '' ) );
        $app_secret = sanitize_text_field( wp_unslash( $_POST['user_app_secret'] ?? '' ) );

        if ( empty( $app_id ) || empty( $app_secret ) ) {
            wp_send_json_error( 'Vui lòng nhập App ID và App Secret.' );
        }

        // Validate format
        if ( ! preg_match( '/^\d{10,20}$/', $app_id ) ) {
            wp_send_json_error( 'App ID phải là số (10-20 chữ số).' );
        }

        // Save to user meta for OAuth flow
        update_user_meta( $user_id, 'bztfb_user_app_id', $app_id );
        update_user_meta( $user_id, 'bztfb_user_app_secret', $app_secret );

        wp_send_json_success( array(
            'message' => 'Đã lưu cấu hình Facebook App của bạn. Bấm "Kết nối Facebook" để bắt đầu OAuth.',
        ) );
    }

    /* ══════════════════════════════════════════════════════
     *  TWO-STEP FLOW: Generate Preview (AI only, no posting)
     * ══════════════════════════════════════════════════════ */

    /**
     * Generate AI content for preview (no WP post, no FB post).
     */
    public static function handle_generate_preview() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        $topic     = sanitize_textarea_field( wp_unslash( $_POST['topic'] ?? '' ) );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );

        if ( empty( $topic ) ) {
            wp_send_json_error( 'Vui lòng nhập chủ đề/nội dung bài viết.' );
        }

        // AI generate only
        $ai_result = BizCity_Tool_Facebook::ai_generate_preview( $topic );

        // Generate image if not provided and tool-image is available
        if ( empty( $image_url ) && function_exists( 'twf_generate_image_url' ) ) {
            $image_url = twf_generate_image_url( $topic );
        }

        wp_send_json_success( array(
            'title'     => $ai_result['title'] ?? '',
            'content'   => $ai_result['content'] ?? $topic,
            'image_url' => $image_url,
        ) );
    }

    /* ══════════════════════════════════════════════════════
     *  TWO-STEP FLOW: Publish Post (from previewed content)
     * ══════════════════════════════════════════════════════ */

    /**
     * Publish a confirmed post to WP + Facebook Page(s).
     * Receives title + content from the preview step.
     */
    public static function handle_publish_post() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        $title     = sanitize_textarea_field( wp_unslash( $_POST['title'] ?? '' ) );
        $content   = sanitize_textarea_field( wp_unslash( $_POST['content'] ?? '' ) );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $page_ids  = isset( $_POST['page_ids'] ) && is_array( $_POST['page_ids'] )
            ? array_map( 'sanitize_text_field', $_POST['page_ids'] )
            : array();

        if ( empty( $content ) ) {
            wp_send_json_error( 'Không có nội dung để đăng.' );
        }

        // Create job record
        global $wpdb;
        $table = $wpdb->prefix . 'bztfb_jobs';
        $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'topic'      => $title,
            'ai_title'   => $title,
            'ai_content' => $content,
            'image_url'  => $image_url,
            'page_ids'   => wp_json_encode( $page_ids ),
            'status'     => 'posting',
        ), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        $job_id = $wpdb->insert_id;

        // Use post_facebook tool (skip AI generation, directly post)
        $result = BizCity_Tool_Facebook::post_facebook( array(
            'title'     => $title,
            'content'   => $content,
            'image_url' => $image_url,
            'page_ids'  => $page_ids,
            'user_id'   => $user_id,
            'job_id'    => $job_id,
        ) );

        // post_facebook already updates the job record via job_id,
        // but if it returned failure without updating, mark as failed here
        if ( ! $result['success'] ) {
            $wpdb->update( $table, array(
                'status'        => 'failed',
                'error_message' => $result['message'] ?? 'Unknown error',
            ), array( 'id' => $job_id ), array( '%s', '%s' ), array( '%d' ) );
        }

        wp_send_json( $result );
    }

    /* ══════════════════════════════════════════════════════
     *  FILE UPLOAD: Upload image to WordPress Media Library
     * ══════════════════════════════════════════════════════ */

    /**
     * Handle image file upload via AJAX.
     * Saves to WP Media Library and returns the attachment URL.
     */
    public static function handle_upload_image() {
        check_ajax_referer( 'bztfb_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( 'Chưa đăng nhập.' );
        }

        if ( empty( $_FILES['image'] ) ) {
            wp_send_json_error( 'Không có file nào được gửi lên.' );
        }

        $file = $_FILES['image'];

        // Validate file type
        $allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        $finfo = finfo_open( FILEINFO_MIME_TYPE );
        $mime  = finfo_file( $finfo, $file['tmp_name'] );
        finfo_close( $finfo );

        if ( ! in_array( $mime, $allowed_types, true ) ) {
            wp_send_json_error( 'Loại file không hợp lệ. Chỉ chấp nhận: JPG, PNG, GIF, WebP.' );
        }

        // Validate file size (10MB max)
        if ( $file['size'] > 10 * 1024 * 1024 ) {
            wp_send_json_error( 'File quá lớn. Tối đa 10MB.' );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_upload( 'image', 0 );

        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( 'Lỗi upload: ' . $attach_id->get_error_message() );
        }

        $url = wp_get_attachment_url( $attach_id );

        wp_send_json_success( array(
            'attachment_id' => $attach_id,
            'url'           => $url,
        ) );
    }
}

BizCity_Ajax_Facebook::init();
