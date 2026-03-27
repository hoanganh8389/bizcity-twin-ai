<?php
/**
 * BizCity Tool Image — AJAX Handlers
 *
 * Frontend AJAX for: upload photo, generate image, poll jobs, save settings.
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Image_Ajax {

    public static function init() {
        add_action( 'wp_ajax_bztimg_upload_photo',    [ __CLASS__, 'handle_upload_photo' ] );
        add_action( 'wp_ajax_bztimg_generate',        [ __CLASS__, 'handle_generate' ] );
        add_action( 'wp_ajax_bztimg_poll_jobs',       [ __CLASS__, 'handle_poll_jobs' ] );
        add_action( 'wp_ajax_bztimg_save_settings',   [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'wp_ajax_bztimg_upload_to_media', [ __CLASS__, 'handle_upload_to_media' ] );
        add_action( 'wp_ajax_bztimg_delete_job',      [ __CLASS__, 'handle_delete_job' ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Upload Photo (reference image) → WP Media → URL
     * ═════════════════════════════════════════════════════════ */
    public static function handle_upload_photo() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        if ( empty( $_FILES['photo'] ) ) {
            wp_send_json_error( [ 'message' => 'Không nhận được file ảnh.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_upload( 'photo', 0 );

        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( [ 'message' => $attach_id->get_error_message() ] );
        }

        wp_send_json_success( [
            'attachment_id' => $attach_id,
            'url'           => wp_get_attachment_url( $attach_id ),
        ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Generate Image — Reuses BizCity_Tool_Image::generate_image()
     * ═════════════════════════════════════════════════════════ */
    public static function handle_generate() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $slots = [
            'prompt'    => sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ),
            'image_url' => esc_url_raw( $_POST['image_url'] ?? '' ),
            'model'     => sanitize_text_field( $_POST['model'] ?? 'flux-pro' ),
            'size'      => sanitize_text_field( $_POST['size'] ?? '1024x1024' ),
            'style'     => sanitize_text_field( $_POST['style'] ?? 'auto' ),
            'user_id'   => $user_id,
            '_meta'     => [
                'session_id' => 'profile_direct_' . $user_id,
            ],
        ];

        $result = BizCity_Tool_Image::generate_image( $slots );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /* ═════════════════════════════════════════════════════════
     *  Poll Jobs — Return recent jobs for current user
     * ═════════════════════════════════════════════════════════ */
    public static function handle_poll_jobs() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            wp_send_json_success( [ 'jobs' => [], 'stats' => [ 'total' => 0, 'done' => 0, 'active' => 0 ] ] );
            return;
        }

        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) );
        $done   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'completed'", $user_id ) );
        $active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND status = 'processing'", $user_id ) );

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, model, size, style, status, image_url, attachment_id, ref_image, error_message, created_at
             FROM {$table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 30",
            $user_id
        ), ARRAY_A );

        wp_send_json_success( [
            'jobs'  => $jobs,
            'stats' => compact( 'total', 'done', 'active' ),
        ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Save Settings
     * ═════════════════════════════════════════════════════════ */
    public static function handle_save_settings() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        // Admin-only fields
        if ( current_user_can( 'manage_options' ) ) {
            if ( isset( $_POST['api_key'] ) ) {
                update_option( 'bztimg_api_key', sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) );
            }
            if ( isset( $_POST['api_endpoint'] ) ) {
                update_option( 'bztimg_api_endpoint', esc_url_raw( wp_unslash( $_POST['api_endpoint'] ) ) );
            }
            if ( isset( $_POST['openai_key'] ) ) {
                update_option( 'bztimg_openai_key', sanitize_text_field( wp_unslash( $_POST['openai_key'] ) ) );
            }
        }

        // User-accessible settings
        $allowed_models = [ 'flux-pro', 'flux-flex', 'flux-max', 'flux-klein', 'gemini-image', 'gemini-pro', 'seedream', 'gpt-image', 'gpt-image-mini' ];
        if ( isset( $_POST['default_model'] ) && in_array( $_POST['default_model'], $allowed_models, true ) ) {
            update_option( 'bztimg_default_model', sanitize_text_field( $_POST['default_model'] ) );
        }

        $allowed_sizes = [ '1024x1024', '1024x1536', '1536x1024', '768x1344', '1344x768' ];
        if ( isset( $_POST['default_size'] ) && in_array( $_POST['default_size'], $allowed_sizes, true ) ) {
            update_option( 'bztimg_default_size', sanitize_text_field( $_POST['default_size'] ) );
        }

        wp_send_json_success( [ 'message' => 'Đã lưu cài đặt thành công.' ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Upload existing image URL to WP Media (manual trigger)
     * ═════════════════════════════════════════════════════════ */
    public static function handle_upload_to_media() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $job_id    = intval( $_POST['job_id'] ?? 0 );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );

        if ( empty( $image_url ) ) {
            wp_send_json_error( [ 'message' => 'URL ảnh trống.' ] );
        }

        $att_id = BizCity_Tool_Image::save_to_media( $image_url, 'AI Image #' . $job_id );

        if ( ! $att_id ) {
            wp_send_json_error( [ 'message' => 'Lỗi lưu ảnh vào Media Library.' ] );
        }

        // Update job record
        if ( $job_id ) {
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'bztimg_jobs',
                [ 'attachment_id' => $att_id, 'image_url' => wp_get_attachment_url( $att_id ) ],
                [ 'id' => $job_id, 'user_id' => get_current_user_id() ]
            );
        }

        wp_send_json_success( [
            'attachment_id' => $att_id,
            'url'           => wp_get_attachment_url( $att_id ),
        ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Delete Job
     * ═════════════════════════════════════════════════════════ */
    public static function handle_delete_job() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $job_id  = intval( $_POST['job_id'] ?? 0 );

        if ( ! $user_id || ! $job_id ) {
            wp_send_json_error( [ 'message' => 'Thiếu dữ liệu.' ] );
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'bztimg_jobs', [
            'id'      => $job_id,
            'user_id' => $user_id,
        ] );

        wp_send_json_success( [ 'message' => 'Đã xóa.' ] );
    }
}

BizCity_Tool_Image_Ajax::init();
