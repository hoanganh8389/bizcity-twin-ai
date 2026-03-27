<?php
/**
 * BizCity Video Kling — AJAX Handlers
 *
 * Frontend AJAX for: upload photo, create video, poll job status, list jobs.
 * Used by the profile page (page-kling-profile.php) for direct form submission.
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Video_Kling_Ajax {

    /**
     * Register all AJAX hooks — both logged-in (wp_ajax_) and guest (wp_ajax_nopriv_)
     */
    public static function init() {
        // Photo upload
        add_action( 'wp_ajax_bvk_upload_photo', [ __CLASS__, 'handle_upload_photo' ] );

        // Create video
        add_action( 'wp_ajax_bvk_create_video', [ __CLASS__, 'handle_create_video' ] );

        // Poll / refresh job status
        add_action( 'wp_ajax_bvk_poll_jobs', [ __CLASS__, 'handle_poll_jobs' ] );

        // Save settings
        add_action( 'wp_ajax_bvk_save_settings', [ __CLASS__, 'handle_save_settings' ] );

        // Upload video to WP Media (manual trigger)
        add_action( 'wp_ajax_bvk_upload_to_media', [ __CLASS__, 'handle_upload_to_media' ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Upload Photo → WP Media Library → return URL
     * ═════════════════════════════════════════════════════════ */
    public static function handle_upload_photo() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

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
     *  Create Video — Reuses BizCity_Tool_Kling::create_video()
     * ═════════════════════════════════════════════════════════ */
    public static function handle_create_video() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $slots = [
            'message'        => sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) ),
            'image_url'      => esc_url_raw( $_POST['image_url'] ?? '' ),
            'duration'       => intval( $_POST['duration'] ?? 10 ),
            'aspect_ratio'   => sanitize_text_field( $_POST['aspect_ratio'] ?? '9:16' ),
            'voiceover_text' => sanitize_textarea_field( wp_unslash( $_POST['voiceover_text'] ?? '' ) ),
            'model'          => sanitize_text_field( $_POST['model'] ?? '2.6|pro' ),
        ];

        $context = [
            'user_id'    => $user_id,
            'session_id' => 'profile_direct_' . $user_id,
            'chat_id'    => '',
            'conversation_id' => '',
        ];

        // Reuse the same tool callback used by intent engine
        if ( ! class_exists( 'BizCity_Tool_Kling' ) ) {
            require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-tools-kling.php';
        }

        $result = BizCity_Tool_Kling::create_video( $slots, $context );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /* ═════════════════════════════════════════════════════════
     *  Save Settings — API config + defaults
     * ═════════════════════════════════════════════════════════ */
    public static function handle_save_settings() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        // API fields — admin only
        if ( current_user_can( 'manage_options' ) ) {
            if ( isset( $_POST['api_key'] ) ) {
                update_option( 'bizcity_video_kling_api_key', sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) );
            }
            if ( isset( $_POST['endpoint'] ) ) {
                update_option( 'bizcity_video_kling_endpoint', esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) );
            }
        }

        // Default tool settings — any logged-in user
        $allowed_models = [ '2.6|pro', '2.6|std', '2.5|pro', '1.6|pro', 'seedance:1.0', 'sora:v1', 'veo:3' ];
        if ( isset( $_POST['default_model'] ) && in_array( $_POST['default_model'], $allowed_models, true ) ) {
            update_option( 'bizcity_video_kling_default_model', sanitize_text_field( $_POST['default_model'] ) );
        }

        $allowed_durations = [ 5, 10, 15, 20, 30 ];
        if ( isset( $_POST['default_duration'] ) && in_array( (int) $_POST['default_duration'], $allowed_durations, true ) ) {
            update_option( 'bizcity_video_kling_default_duration', (int) $_POST['default_duration'] );
        }

        $allowed_ratios = [ '9:16', '16:9', '1:1' ];
        if ( isset( $_POST['default_aspect_ratio'] ) && in_array( $_POST['default_aspect_ratio'], $allowed_ratios, true ) ) {
            update_option( 'bizcity_video_kling_default_aspect_ratio', sanitize_text_field( $_POST['default_aspect_ratio'] ) );
        }

        wp_send_json_success( [ 'message' => 'Đã lưu cài đặt thành công.' ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Poll Jobs — Return recent jobs for current user
     * ═════════════════════════════════════════════════════════ */
    public static function handle_poll_jobs() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        global $wpdb;
        $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        $has_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;

        if ( ! $has_table ) {
            wp_send_json_success( [ 'jobs' => [], 'stats' => [ 'total' => 0, 'done' => 0, 'active' => 0 ] ] );
        }

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, status, progress, video_url, media_url, attachment_id, model, duration, aspect_ratio, checkpoints, error_message, created_at, updated_at
             FROM {$jobs_table} WHERE created_by = %d ORDER BY created_at DESC LIMIT 20",
            $user_id
        ), ARRAY_A );

        // Parse checkpoints JSON for each job
        foreach ( $jobs as &$j ) {
            $j['checkpoints'] = ! empty( $j['checkpoints'] ) ? json_decode( $j['checkpoints'], true ) : [];
        }
        unset( $j );

        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d", $user_id ) );
        $done   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status = 'completed'", $user_id ) );
        $active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$jobs_table} WHERE created_by = %d AND status IN ('queued','processing')", $user_id ) );

        wp_send_json_success( [
            'jobs'  => $jobs,
            'stats' => compact( 'total', 'done', 'active' ),
        ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Upload to WP Media — Manual trigger for completed jobs
     *  Checks duplicate by existing media_url / attachment_id
     * ═════════════════════════════════════════════════════════ */
    public static function handle_upload_to_media() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $job_id = intval( $_POST['job_id'] ?? 0 );
        if ( ! $job_id ) {
            wp_send_json_error( [ 'message' => 'Missing job_id.' ] );
        }

        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        if ( ! $job || (int) $job->created_by !== $user_id ) {
            wp_send_json_error( [ 'message' => 'Job không tồn tại hoặc không có quyền.' ] );
        }

        // Already uploaded? Return existing URL
        if ( ! empty( $job->media_url ) && ! empty( $job->attachment_id ) ) {
            // Verify attachment still exists
            if ( wp_get_attachment_url( $job->attachment_id ) ) {
                wp_send_json_success( [
                    'message'       => 'Video đã có trong Media Library.',
                    'media_url'     => $job->media_url,
                    'attachment_id' => (int) $job->attachment_id,
                    'duplicate'     => true,
                ] );
            }
        }

        // Need a source video_url
        $video_url = $job->video_url;
        if ( empty( $video_url ) ) {
            wp_send_json_error( [ 'message' => 'Job chưa có video URL từ API.' ] );
        }

        // Download to WordPress Media Library
        if ( ! function_exists( 'waic_kling_download_video_to_media' ) ) {
            require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';
        }

        $result = waic_kling_download_video_to_media( $video_url, "kling-video-{$job_id}.mp4" );

        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi tải video: ' . ( $result['error'] ?? 'Unknown' ) ] );
        }

        // Update job record
        BizCity_Video_Kling_Database::update_job( $job_id, [
            'media_url'     => $result['media_url'],
            'attachment_id' => $result['attachment_id'],
        ] );
        BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'manual_media_upload' );

        wp_send_json_success( [
            'message'       => 'Đã upload video vào Media Library!',
            'media_url'     => $result['media_url'],
            'attachment_id' => $result['attachment_id'],
            'duplicate'     => false,
        ] );
    }
}
