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

        // Optimize prompt via LLM
        add_action( 'wp_ajax_bvk_optimize_prompt', [ __CLASS__, 'handle_optimize_prompt' ] );
        add_action( 'wp_ajax_bvk_get_media_videos', [ __CLASS__, 'handle_get_media_videos' ] );
        // nopriv fallback returns auth error (so React can show proper message)
        add_action( 'wp_ajax_nopriv_bvk_get_media_videos', [ __CLASS__, 'handle_get_media_videos' ] );

        // Video editor — server-side MP4 export via FFmpeg
        add_action( 'wp_ajax_bvk_export_mp4', [ __CLASS__, 'handle_export_mp4' ] );
        // Check FFmpeg availability (for editor UI)
        add_action( 'wp_ajax_bvk_check_ffmpeg', [ __CLASS__, 'handle_check_ffmpeg' ] );
        // AI Image generation from within Editor
        add_action( 'wp_ajax_bvk_editor_generate_image', [ __CLASS__, 'handle_editor_generate_image' ] );

        // Audio: browse WP Media audios + upload
        add_action( 'wp_ajax_bvk_get_media_audios', [ __CLASS__, 'handle_get_media_audios' ] );
        add_action( 'wp_ajax_bvk_upload_audio',      [ __CLASS__, 'handle_upload_audio' ] );
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

    /* ═════════════════════════════════════════════════════════
     *  Optimize Prompt via LLM
     * ═════════════════════════════════════════════════════════ */
    public static function handle_get_media_videos() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'video',
            'post_status'    => 'inherit',
            'posts_per_page' => 60,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $query = new WP_Query( $args );
        $videos = [];
        foreach ( $query->posts as $post ) {
            $src = wp_get_attachment_url( $post->ID );
            if ( ! $src ) continue;
            $meta  = wp_get_attachment_metadata( $post->ID );
            // Use video thumbnail if available, else empty
            $thumb = '';
            // 1) Try WordPress featured image / poster for the attachment
            $thumb_id = get_post_thumbnail_id( $post->ID );
            if ( $thumb_id ) {
                $thumb_url = wp_get_attachment_image_url( $thumb_id, 'medium' );
                if ( $thumb_url ) $thumb = $thumb_url;
            }
            // 2) Fallback: image size inside video meta (rare)
            if ( ! $thumb && ! empty( $meta['sizes']['medium']['file'] ) ) {
                $upload_dir = wp_upload_dir();
                $thumb = trailingslashit( $upload_dir['baseurl'] )
                       . dirname( get_post_meta( $post->ID, '_wp_attached_file', true ) )
                       . '/' . $meta['sizes']['medium']['file'];
            }
            $videos[] = [
                'id'       => $post->ID,
                'src'      => $src,
                'thumb'    => $thumb,
                'title'    => get_the_title( $post->ID ) ?: basename( $src ),
                'width'    => $meta['width']  ?? 0,
                'height'   => $meta['height'] ?? 0,
                'duration' => $meta['length'] ?? 0,
            ];
        }
        wp_send_json_success( [ 'videos' => $videos ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Get Audio files from WP Media Library
     * ═════════════════════════════════════════════════════════ */
    public static function handle_get_media_audios() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        $args = [
            'post_type'      => 'attachment',
            'post_mime_type' => 'audio',
            'post_status'    => 'inherit',
            'posts_per_page' => 60,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];
        $query  = new WP_Query( $args );
        $audios = [];
        foreach ( $query->posts as $post ) {
            $src = wp_get_attachment_url( $post->ID );
            if ( ! $src ) continue;
            $meta     = wp_get_attachment_metadata( $post->ID );
            $audios[] = [
                'id'       => $post->ID,
                'src'      => $src,
                'title'    => get_the_title( $post->ID ) ?: basename( $src ),
                'duration' => isset( $meta['length'] ) ? (int) $meta['length'] : 0,
                'mime'     => get_post_mime_type( $post->ID ),
            ];
        }
        wp_send_json_success( [ 'audios' => $audios ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Upload Audio → WP Media Library → return URL
     * ═════════════════════════════════════════════════════════ */
    public static function handle_upload_audio() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        if ( empty( $_FILES['audio'] ) ) {
            wp_send_json_error( [ 'message' => 'Không nhận được file audio.' ] );
        }

        // Validate MIME type — only allow audio files
        $allowed = [ 'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'audio/flac' ];
        $finfo   = finfo_open( FILEINFO_MIME_TYPE );
        $mime    = finfo_file( $finfo, $_FILES['audio']['tmp_name'] );
        finfo_close( $finfo );
        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'Chỉ chấp nhận file audio (mp3, wav, ogg, aac, m4a, flac).' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_upload( 'audio', 0 );

        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( [ 'message' => $attach_id->get_error_message() ] );
        }

        $meta = wp_get_attachment_metadata( $attach_id );
        wp_send_json_success( [
            'attachment_id' => $attach_id,
            'url'           => wp_get_attachment_url( $attach_id ),
            'title'         => get_the_title( $attach_id ),
            'duration'      => isset( $meta['length'] ) ? (int) $meta['length'] : 0,
        ] );
    }

    public static function handle_optimize_prompt() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );

        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            wp_send_json_error( [ 'message' => 'LLM client không khả dụng.' ] );
        }

        $llm = new BizCity_LLM_Client();
        $result = $llm->chat( [
            [
                'role'    => 'system',
                'content' => 'You are a video prompt expert. Rewrite the user prompt into a vivid, cinematic English prompt for AI video generation (max 300 words). Output only the improved prompt, no explanation.',
            ],
            [
                'role'    => 'user',
                'content' => $prompt ?: 'No prompt provided. Write a beautiful cinematic prompt.',
            ],
        ], [ 'purpose' => 'chat', 'max_tokens' => 400 ] );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Lỗi LLM.' ] );
        }

        $optimized = trim( $result['content'] ?? '' );
        wp_send_json_success( [ 'prompt' => $optimized ] );
    }

    /* ═════════════════════════════════════════════════════════
     *  Check FFmpeg availability on server
     * ═════════════════════════════════════════════════════════ */
    public static function handle_check_ffmpeg() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $check = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
        wp_send_json_success( $check );
    }

    /* ═════════════════════════════════════════════════════════
     *  Export MP4 — Server-side FFmpeg
     *
     *  Receives JSON with video/audio URLs + layer positioning,
     *  downloads files, runs FFmpeg overlay composite, returns MP4.
     *
     *  Supports two modes:
     *    • Overlay/composite (new): when videos have displayFrom/x/y
     *    • Concat (legacy fallback): sequential concatenation
     * ═════════════════════════════════════════════════════════ */
    public static function handle_export_mp4() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        // Check FFmpeg first
        $ffcheck = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
        if ( empty( $ffcheck['available'] ) ) {
            wp_send_json_error( [ 'message' => 'FFmpeg không khả dụng trên server: ' . ( $ffcheck['error'] ?? 'unknown' ) ] );
        }

        // Parse input
        $raw = file_get_contents( 'php://input' );
        $body = json_decode( $raw, true );

        if ( empty( $body ) ) {
            // Fallback to $_POST
            $body = [
                'videos' => isset( $_POST['videos'] ) ? json_decode( wp_unslash( $_POST['videos'] ), true ) : [],
                'audios' => isset( $_POST['audios'] ) ? json_decode( wp_unslash( $_POST['audios'] ), true ) : [],
                'width'  => intval( $_POST['width'] ?? 1080 ),
                'height' => intval( $_POST['height'] ?? 1920 ),
            ];
        }

        $videos = $body['videos'] ?? [];
        $audios = $body['audios'] ?? [];
        $width  = max( 1, intval( $body['width'] ?? 1080 ) );
        $height = max( 1, intval( $body['height'] ?? 1920 ) );
        $transition = $body['transition'] ?? null; // { type, duration, direction }

        if ( empty( $videos ) ) {
            wp_send_json_error( [ 'message' => 'Không có video nào để xuất.' ] );
        }

        // Limit inputs to prevent abuse
        if ( count( $videos ) > 50 || count( $audios ) > 20 ) {
            wp_send_json_error( [ 'message' => 'Quá nhiều file. Tối đa 50 video + 20 audio.' ] );
        }

        // Detect overlay mode: new payload has displayFrom field
        $is_overlay = isset( $videos[0]['displayFrom'] );

        // Create temp working directory
        $tmp_dir = sys_get_temp_dir() . '/bvk_export_' . get_current_user_id() . '_' . uniqid();
        if ( ! wp_mkdir_p( $tmp_dir ) ) {
            wp_send_json_error( [ 'message' => 'Không tạo được thư mục tạm.' ] );
        }

        $downloaded_videos = [];
        $downloaded_audios = [];

        try {
            // ── Step 1: Download video files ──
            foreach ( $videos as $i => $v ) {
                $src = $v['src'] ?? '';
                if ( empty( $src ) || ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
                    throw new \Exception( "Video " . ( $i + 1 ) . ": URL không hợp lệ." );
                }

                $local = $tmp_dir . "/v{$i}.mp4";

                $resp = wp_remote_get( $src, [
                    'timeout'  => 120,
                    'stream'   => true,
                    'filename' => $local,
                ] );

                if ( is_wp_error( $resp ) ) {
                    throw new \Exception( "Không tải được video " . ( $i + 1 ) . ": " . $resp->get_error_message() );
                }

                if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                    throw new \Exception( "Video " . ( $i + 1 ) . " trả về HTTP " . wp_remote_retrieve_response_code( $resp ) );
                }

                if ( ! file_exists( $local ) || filesize( $local ) < 1000 ) {
                    throw new \Exception( "Video " . ( $i + 1 ) . " tải không thành công (file quá nhỏ)." );
                }

                $entry = [
                    'path'     => $local,
                    'trimFrom' => floatval( $v['trimFrom'] ?? 0 ) / 1000,  // ms → sec
                    'trimTo'   => floatval( $v['trimTo'] ?? 0 ) / 1000,
                    'volume'   => intval( $v['volume'] ?? 100 ),
                ];

                if ( $is_overlay ) {
                    $entry['displayFrom']  = floatval( $v['displayFrom'] ?? 0 ) / 1000;
                    $entry['displayTo']    = floatval( $v['displayTo'] ?? 0 ) / 1000;
                    $entry['x']            = intval( $v['x'] ?? 0 );
                    $entry['y']            = intval( $v['y'] ?? 0 );
                    $entry['renderWidth']  = max( 2, intval( $v['renderWidth'] ?? $width ) );
                    $entry['renderHeight'] = max( 2, intval( $v['renderHeight'] ?? $height ) );
                    // FFmpeg needs even dimensions
                    $entry['renderWidth']  = $entry['renderWidth']  + ( $entry['renderWidth']  % 2 );
                    $entry['renderHeight'] = $entry['renderHeight'] + ( $entry['renderHeight'] % 2 );
                }

                $downloaded_videos[] = $entry;
            }

            // ── Step 2: Download audio files ──
            foreach ( $audios as $i => $a ) {
                $src = $a['src'] ?? '';
                if ( empty( $src ) || ! filter_var( $src, FILTER_VALIDATE_URL ) ) {
                    continue;
                }

                $url_path = wp_parse_url( $src, PHP_URL_PATH );
                $ext = pathinfo( $url_path, PATHINFO_EXTENSION ) ?: 'mp3';
                $ext = preg_replace( '/[^a-z0-9]/', '', strtolower( $ext ) );
                $local = $tmp_dir . "/a{$i}.{$ext}";

                $resp = wp_remote_get( $src, [
                    'timeout'  => 60,
                    'stream'   => true,
                    'filename' => $local,
                ] );

                if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                    continue;
                }

                $downloaded_audios[] = [
                    'path'     => $local,
                    'trimFrom' => floatval( $a['trimFrom'] ?? 0 ) / 1000,
                    'trimTo'   => floatval( $a['trimTo'] ?? 0 ) / 1000,
                    'volume'   => intval( $a['volume'] ?? 100 ),
                ];
            }

            $ffmpeg = BizCity_Video_Kling_FFmpeg_Presets::get_ffmpeg_path();

            if ( $is_overlay ) {
                $output = self::export_overlay( $downloaded_videos, $downloaded_audios, $width, $height, $tmp_dir, $ffmpeg, $transition );
            } else {
                $output = self::export_concat( $downloaded_videos, $downloaded_audios, $width, $height, $tmp_dir, $ffmpeg, $transition );
            }

            if ( ! file_exists( $output ) || filesize( $output ) < 1000 ) {
                throw new \Exception( 'Xuất video thất bại — file output trống.' );
            }

            // ── Move to uploads folder for download ──
            $upload_dir = wp_upload_dir();
            $export_dir = $upload_dir['basedir'] . '/bvk-exports';
            wp_mkdir_p( $export_dir );

            $filename    = 'export-' . get_current_user_id() . '-' . time() . '.mp4';
            $final_path  = $export_dir . '/' . $filename;
            $final_url   = $upload_dir['baseurl'] . '/bvk-exports/' . $filename;

            rename( $output, $final_path );

            // ── Auto-upload to WordPress Media Library ──
            $attach_id   = 0;
            $media_url   = $final_url;
            try {
                $file_type = wp_check_filetype( $filename, null );
                $attachment = [
                    'guid'           => $final_url,
                    'post_mime_type' => $file_type['type'] ?: 'video/mp4',
                    'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ];

                // Copy to standard uploads folder for WP to manage
                $media_dir  = $upload_dir['path'];  // e.g. .../uploads/2026/04
                $media_file = $media_dir . '/' . $filename;
                copy( $final_path, $media_file );

                $attach_id = wp_insert_attachment( $attachment, $media_file );
                if ( ! is_wp_error( $attach_id ) && $attach_id > 0 ) {
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';
                    $metadata = wp_generate_attachment_metadata( $attach_id, $media_file );
                    wp_update_attachment_metadata( $attach_id, $metadata );
                    $media_url = wp_get_attachment_url( $attach_id ) ?: $final_url;
                } else {
                    $attach_id = 0;
                }
            } catch ( \Exception $media_ex ) {
                error_log( '[BVK-Export] Media upload failed: ' . $media_ex->getMessage() );
                // Non-fatal — still return the export URL
            }

            // Cleanup temp dir
            self::cleanup_dir( $tmp_dir );

            wp_send_json_success( [
                'url'           => $media_url,
                'download_url'  => $final_url,
                'attachment_id' => $attach_id,
                'filename'      => $filename,
                'size'          => filesize( $final_path ),
            ] );

        } catch ( \Exception $e ) {
            self::cleanup_dir( $tmp_dir );
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /**
     * OVERLAY / COMPOSITE export — multi-layer FFmpeg filter_complex
     *
     * Each video is a layer with position (x,y), size (renderWidth×renderHeight),
     * and timeline timing (displayFrom/displayTo). FFmpeg overlays them in z-order.
    /* ═════════════════════════════════════════════════════════
     *  AI Image Generation — Bridge to BizCity_Tool_Image
     * ═════════════════════════════════════════════════════════ */
    public static function handle_editor_generate_image() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        if ( ! class_exists( 'BizCity_Tool_Image' ) ) {
            wp_send_json_error( [ 'message' => 'Plugin Tool-Image chưa được kích hoạt.' ] );
        }

        $prompt = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
        $model  = sanitize_text_field( $_POST['model'] ?? 'flux-pro' );
        $size   = sanitize_text_field( $_POST['size'] ?? '1024x1024' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Vui lòng nhập mô tả hình ảnh.' ] );
        }

        $result = BizCity_Tool_Image::generate_image( [
            'creation_mode' => 'text',
            'prompt'        => $prompt,
            'model'         => $model,
            'size'          => $size,
            'style'         => 'auto',
            'user_id'       => get_current_user_id(),
        ] );

        if ( ! empty( $result['success'] ) && ! empty( $result['data']['image_url'] ) ) {
            wp_send_json_success( [
                'image_url'     => $result['data']['image_url'],
                'attachment_id' => $result['data']['attachment_id'] ?? 0,
                'model'         => $result['data']['model'] ?? $model,
                'prompt'        => $prompt,
            ] );
        } else {
            wp_send_json_error( [
                'message' => $result['message'] ?? 'Tạo ảnh thất bại.',
            ] );
        }
    }

    /**
     *
     * Pipeline:
     *   1. Pre-render each clip (trim + scale to render dimensions)
     *   2. Single filter_complex:
     *      - Input 0 = black canvas for full timeline duration
     *      - Each segment gets setpts=PTS+displayFrom/TB to delay start
     *      - Chained overlay filters with enable='between(t,start,end)'
     *   3. Extract + mix audio tracks
     */
    private static function export_overlay( $videos, $audios, $width, $height, $tmp_dir, $ffmpeg, $transition = null ) {
        // ── Step A: Pre-render each segment (trim + scale to render size) ──
        $segments = [];
        foreach ( $videos as $i => $v ) {
            $seg_out = $tmp_dir . "/seg{$i}.mp4";
            $ss  = max( 0, $v['trimFrom'] );
            $dur = $v['trimTo'] - $v['trimFrom'];
            if ( $dur <= 0 ) {
                $ss  = 0;
                $dur = 0;
            }

            $rw = $v['renderWidth'];
            $rh = $v['renderHeight'];

            $cmd_parts = [
                escapeshellarg( $ffmpeg ),
                '-y',
            ];
            if ( $ss > 0 ) {
                $cmd_parts[] = '-ss ' . number_format( $ss, 3, '.', '' );
            }
            if ( $dur > 0 ) {
                $cmd_parts[] = '-t ' . number_format( $dur, 3, '.', '' );
            }
            $cmd_parts[] = '-i ' . escapeshellarg( $v['path'] );
            $cmd_parts[] = '-vf "scale=' . $rw . ':' . $rh . ':force_original_aspect_ratio=decrease,pad=' . $rw . ':' . $rh . ':(ow-iw)/2:(oh-ih)/2:black"';
            $cmd_parts[] = '-c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30';
            $cmd_parts[] = '-an';
            $cmd_parts[] = escapeshellarg( $seg_out );

            $cmd = implode( ' ', $cmd_parts );
            error_log( "[BVK-Export] Overlay seg {$i}: {$cmd}" );

            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            if ( empty( $result['success'] ) ) {
                throw new \Exception( "Clip " . ( $i + 1 ) . " xử lý thất bại: " . ( $result['error'] ?? $result['output'] ?? 'unknown' ) );
            }
            if ( ! file_exists( $seg_out ) || filesize( $seg_out ) < 100 ) {
                throw new \Exception( "Clip " . ( $i + 1 ) . " output rỗng." );
            }

            $segments[] = [
                'path'        => $seg_out,
                'x'           => $v['x'],
                'y'           => $v['y'],
                'displayFrom' => max( 0, $v['displayFrom'] ),
                'displayTo'   => $v['displayTo'],
            ];
        }

        // ── Step B: Check if xfade mode is applicable ──
        $xfade_type = null;
        $xfade_dur  = 0.5;
        if ( $transition && ! empty( $transition['type'] ) && $transition['type'] !== 'none' ) {
            $allowed = [ 'fade', 'slideright', 'slideleft', 'slideup', 'slidedown',
                         'wiperight', 'wipeleft', 'wipeup', 'wipedown',
                         'circlecrop', 'rectcrop', 'distance', 'fadeblack', 'fadewhite' ];
            if ( in_array( $transition['type'], $allowed, true ) ) {
                $xfade_type = $transition['type'];
                $xfade_dur  = max( 0.1, min( 2.0, floatval( $transition['duration'] ?? 0.5 ) ) );
            }
        }

        // Detect if clips are sequential (non-overlapping) — xfade works best for this case
        $is_sequential = false;
        if ( $xfade_type && count( $segments ) > 1 ) {
            // Sort by displayFrom
            usort( $segments, function( $a, $b ) {
                return $a['displayFrom'] <=> $b['displayFrom'];
            });
            $is_sequential = true;
            for ( $i = 1; $i < count( $segments ); $i++ ) {
                $gap = abs( $segments[ $i ]['displayFrom'] - $segments[ $i - 1 ]['displayTo'] );
                if ( $gap > 1.0 ) {
                    // More than 1 second gap or overlap — not truly sequential
                    $is_sequential = false;
                    break;
                }
            }
        }

        if ( $is_sequential && $xfade_type && count( $segments ) > 1 ) {
            // ── Xfade path: Re-render segments at full canvas size, then xfade ──
            $full_segments = [];
            foreach ( $segments as $idx => $s ) {
                $full_out = $tmp_dir . "/full{$idx}.mp4";
                $cmd = escapeshellarg( $ffmpeg ) . ' -y'
                     . ' -i ' . escapeshellarg( $s['path'] )
                     . ' -vf "scale=' . $width . ':' . $height . ':force_original_aspect_ratio=decrease,pad=' . $width . ':' . $height . ':(ow-iw)/2:(oh-ih)/2:black"'
                     . ' -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30 -an'
                     . ' ' . escapeshellarg( $full_out );
                $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                if ( empty( $result['success'] ) || ! file_exists( $full_out ) ) {
                    // Fallback to overlay mode
                    $is_sequential = false;
                    break;
                }
                $full_segments[] = $full_out;
            }

            if ( $is_sequential ) {
                // Get durations via ffprobe
                $seg_durations = [];
                $ffprobe = str_replace( 'ffmpeg', 'ffprobe', $ffmpeg );
                foreach ( $full_segments as $idx => $seg ) {
                    $probe_cmd = escapeshellarg( $ffprobe )
                        . ' -v error -show_entries format=duration -of csv=p=0 '
                        . escapeshellarg( $seg );
                    $dur_out = trim( shell_exec( $probe_cmd ) ?? '' );
                    $seg_durations[ $idx ] = floatval( $dur_out ) ?: 5.0;
                }

                // Build xfade filter chain
                $input_parts = [];
                foreach ( $full_segments as $seg ) {
                    $input_parts[] = '-i ' . escapeshellarg( $seg );
                }

                $n = count( $full_segments );
                $filter_parts = [];
                $cumulative = 0;
                $prev_label = '[0:v]';

                for ( $i = 1; $i < $n; $i++ ) {
                    $offset = max( 0, $cumulative + $seg_durations[ $i - 1 ] - $xfade_dur );
                    $out_label = ( $i === $n - 1 ) ? '[vout]' : '[v' . $i . ']';
                    $filter_parts[] = $prev_label . '[' . $i . ':v]xfade=transition=' . $xfade_type
                        . ':duration=' . number_format( $xfade_dur, 3, '.', '' )
                        . ':offset=' . number_format( $offset, 3, '.', '' )
                        . $out_label;
                    $cumulative = $offset;
                    $prev_label = $out_label;
                }

                $filter_str = implode( ';', $filter_parts );
                $merged = $tmp_dir . '/merged.mp4';

                $cmd = escapeshellarg( $ffmpeg ) . ' -y '
                     . implode( ' ', $input_parts )
                     . ' -filter_complex "' . $filter_str . '"'
                     . ' -map "[vout]"'
                     . ' -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30'
                     . ' -movflags +faststart'
                     . ' ' . escapeshellarg( $merged );

                error_log( "[BVK-Export] Overlay xfade: {$cmd}" );
                $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );

                if ( ! empty( $result['success'] ) && file_exists( $merged ) && filesize( $merged ) > 100 ) {
                    // Xfade succeeded — skip to audio mix
                    goto audio_mix_overlay;
                }

                error_log( "[BVK-Export] Overlay xfade failed, falling back to standard overlay: " . ( $result['error'] ?? '' ) );
            }
        }

        // ── Standard overlay filter_complex (no xfade) ──
        $total_dur = 0;
        foreach ( $segments as $s ) {
            $total_dur = max( $total_dur, $s['displayTo'] );
        }
        if ( $total_dur <= 0 ) {
            $total_dur = 30;
        }

        $input_args = [];
        // Input 0: black canvas for total duration
        $input_args[] = '-f lavfi -i "color=c=black:s=' . $width . 'x' . $height . ':d=' . number_format( $total_dur, 3, '.', '' ) . ':r=30"';

        foreach ( $segments as $s ) {
            $input_args[] = '-i ' . escapeshellarg( $s['path'] );
        }

        $n = count( $segments );
        $filter_parts = [];
        $prev_label = '0:v';

        for ( $i = 0; $i < $n; $i++ ) {
            $s = $segments[ $i ];
            $input_idx = $i + 1;
            $delay_sec = number_format( $s['displayFrom'], 3, '.', '' );
            $end_sec   = number_format( $s['displayTo'], 3, '.', '' );
            $start_sec = $delay_sec;

            // Delay the segment so it starts at displayFrom on the timeline
            $delayed_label = 'd' . $i;
            if ( $s['displayFrom'] > 0.01 ) {
                $filter_parts[] = "[{$input_idx}:v]setpts=PTS+{$delay_sec}/TB[{$delayed_label}]";
            } else {
                $filter_parts[] = "[{$input_idx}:v]setpts=PTS[{$delayed_label}]";
            }

            // Overlay with enable window
            $out_label = ( $i === $n - 1 ) ? 'out' : 't' . $i;
            $x = intval( $s['x'] );
            $y = intval( $s['y'] );

            $overlay = "[{$prev_label}][{$delayed_label}]overlay={$x}:{$y}"
                     . ":eof_action=pass"
                     . ":enable='between(t,{$start_sec},{$end_sec})'"
                     . "[{$out_label}]";

            $filter_parts[] = $overlay;
            $prev_label = $out_label;
        }

        $filter_str = implode( ';', $filter_parts );

        $merged = $tmp_dir . '/merged.mp4';
        $cmd = escapeshellarg( $ffmpeg ) . ' -y '
             . implode( ' ', $input_args )
             . ' -filter_complex "' . $filter_str . '"'
             . ' -map "[out]"'
             . ' -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30'
             . ' -movflags +faststart'
             . ' -t ' . number_format( $total_dur, 3, '.', '' )
             . ' ' . escapeshellarg( $merged );

        error_log( "[BVK-Export] Overlay composite: {$cmd}" );

        $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
        if ( empty( $result['success'] ) ) {
            // Log full output for debugging
            error_log( "[BVK-Export] Overlay FAILED output: " . ( $result['output'] ?? 'none' ) );
            throw new \Exception( "Overlay composite thất bại: " . ( $result['error'] ?? $result['output'] ?? 'unknown' ) );
        }

        // ── Step C: Audio mix (from video tracks + separate audio tracks) ──
        audio_mix_overlay:
        $output = $tmp_dir . '/output.mp4';

        // Collect audio sources from video tracks (with volume > 0) + separate audios
        $audio_inputs = [];

        foreach ( $videos as $i => $v ) {
            if ( ( $v['volume'] ?? 0 ) > 0 ) {
                $audio_out = $tmp_dir . "/va{$i}.aac";
                $ss  = max( 0, $v['trimFrom'] );
                $dur = $v['trimTo'] - $v['trimFrom'];

                $a_cmd_parts = [
                    escapeshellarg( $ffmpeg ),
                    '-y',
                ];
                if ( $ss > 0 ) {
                    $a_cmd_parts[] = '-ss ' . number_format( $ss, 3, '.', '' );
                }
                if ( $dur > 0 ) {
                    $a_cmd_parts[] = '-t ' . number_format( $dur, 3, '.', '' );
                }
                $a_cmd_parts[] = '-i ' . escapeshellarg( $v['path'] );
                $a_cmd_parts[] = '-vn -c:a aac -b:a 128k';
                $a_cmd_parts[] = escapeshellarg( $audio_out );

                $a_cmd = implode( ' ', $a_cmd_parts );
                $a_result = BizCity_Video_Kling_FFmpeg_Presets::execute( $a_cmd );

                if ( ! empty( $a_result['success'] ) && file_exists( $audio_out ) && filesize( $audio_out ) > 100 ) {
                    $audio_inputs[] = [
                        'path'   => $audio_out,
                        'volume' => intval( $v['volume'] ) / 100,
                    ];
                }
                // If audio extraction fails (no audio stream), just skip
            }
        }

        foreach ( $audios as $a ) {
            $audio_inputs[] = [
                'path'   => $a['path'],
                'volume' => intval( $a['volume'] ) / 100,
            ];
        }

        if ( ! empty( $audio_inputs ) ) {
            $cmd_parts = [
                escapeshellarg( $ffmpeg ),
                '-y',
                '-i ' . escapeshellarg( $merged ),
            ];

            $filter_audio_parts = [];
            foreach ( $audio_inputs as $idx => $ai ) {
                $cmd_parts[] = '-i ' . escapeshellarg( $ai['path'] );
                $a_label = '[' . ( $idx + 1 ) . ':a]';
                $vol = number_format( $ai['volume'], 2, '.', '' );
                $filter_audio_parts[] = $a_label . 'volume=' . $vol . '[a' . $idx . ']';
            }

            $mix_inputs = '';
            for ( $i = 0; $i < count( $audio_inputs ); $i++ ) {
                $mix_inputs .= '[a' . $i . ']';
            }

            $filter_str = implode( ';', $filter_audio_parts )
                        . ';' . $mix_inputs . 'amix=inputs=' . count( $audio_inputs ) . ':duration=longest[outa]';

            $cmd_parts[] = '-filter_complex "' . $filter_str . '"';
            $cmd_parts[] = '-map 0:v -map "[outa]"';
            $cmd_parts[] = '-c:v copy -c:a aac -b:a 128k';
            $cmd_parts[] = '-movflags +faststart -shortest';
            $cmd_parts[] = escapeshellarg( $output );

            $cmd = implode( ' ', $cmd_parts );
            error_log( "[BVK-Export] Audio mix: {$cmd}" );

            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            if ( empty( $result['success'] ) ) {
                error_log( "[BVK-Export] Audio mix failed, using video-only: " . ( $result['error'] ?? '' ) );
                copy( $merged, $output );
            }
        } else {
            copy( $merged, $output );
        }

        return $output;
    }

    /**
     * CONCAT export (legacy) — sequential concatenation
     */
    private static function export_concat( $videos, $audios, $width, $height, $tmp_dir, $ffmpeg, $transition = null ) {
        $scale = "{$width}:{$height}";

        // ── Re-encode each clip as segment ──
        $segments = [];
        foreach ( $videos as $i => $v ) {
            $seg_out = $tmp_dir . "/seg{$i}.mp4";
            $ss  = max( 0, $v['trimFrom'] );
            $dur = $v['trimTo'] - $v['trimFrom'];
            if ( $dur <= 0 ) {
                $ss  = 0;
                $dur = 0;
            }

            $cmd_parts = [
                escapeshellarg( $ffmpeg ),
                '-y',
            ];
            if ( $ss > 0 ) {
                $cmd_parts[] = '-ss ' . number_format( $ss, 3, '.', '' );
            }
            if ( $dur > 0 ) {
                $cmd_parts[] = '-t ' . number_format( $dur, 3, '.', '' );
            }
            $cmd_parts[] = '-i ' . escapeshellarg( $v['path'] );
            $cmd_parts[] = '-vf "scale=' . $scale . ':force_original_aspect_ratio=decrease,pad=' . $scale . ':(ow-iw)/2:(oh-ih)/2:black"';
            $cmd_parts[] = '-c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30';
            $cmd_parts[] = '-an';
            $cmd_parts[] = escapeshellarg( $seg_out );

            $cmd = implode( ' ', $cmd_parts );
            error_log( "[BVK-Export] Concat seg {$i}: {$cmd}" );

            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            if ( empty( $result['success'] ) ) {
                throw new \Exception( "Clip " . ( $i + 1 ) . " xử lý thất bại: " . ( $result['error'] ?? $result['output'] ?? 'unknown' ) );
            }
            if ( ! file_exists( $seg_out ) || filesize( $seg_out ) < 100 ) {
                throw new \Exception( "Clip " . ( $i + 1 ) . " output rỗng." );
            }

            $segments[] = $seg_out;
        }

        // ── Concat all segments (with optional xfade transition) ──
        $merged = $tmp_dir . '/merged.mp4';

        $xfade_type = null;
        $xfade_dur  = 0.5;
        if ( $transition && ! empty( $transition['type'] ) && $transition['type'] !== 'none' ) {
            $allowed = [ 'fade', 'slideright', 'slideleft', 'slideup', 'slidedown',
                         'wiperight', 'wipeleft', 'wipeup', 'wipedown',
                         'circlecrop', 'rectcrop', 'distance', 'fadeblack', 'fadewhite' ];
            if ( in_array( $transition['type'], $allowed, true ) ) {
                $xfade_type = $transition['type'];
                $xfade_dur  = max( 0.1, min( 2.0, floatval( $transition['duration'] ?? 0.5 ) ) );
            }
        }

        if ( count( $segments ) === 1 ) {
            copy( $segments[0], $merged );
        } elseif ( $xfade_type && count( $segments ) > 1 ) {
            // ── FFmpeg xfade chain between consecutive clips ──
            // Get each segment's duration via ffprobe
            $seg_durations = [];
            foreach ( $segments as $idx => $seg ) {
                $probe_cmd = escapeshellarg( str_replace( 'ffmpeg', 'ffprobe', $ffmpeg ) )
                    . ' -v error -show_entries format=duration -of csv=p=0 '
                    . escapeshellarg( $seg );
                $dur_out = trim( shell_exec( $probe_cmd ) ?? '' );
                $seg_durations[ $idx ] = floatval( $dur_out ) ?: 5.0;
            }

            // Build inputs
            $input_parts = [];
            foreach ( $segments as $seg ) {
                $input_parts[] = '-i ' . escapeshellarg( $seg );
            }

            // Build xfade filter chain
            $n = count( $segments );
            $filter_parts = [];
            $cumulative = 0;
            $prev_label = '[0:v]';

            for ( $i = 1; $i < $n; $i++ ) {
                $offset = max( 0, $cumulative + $seg_durations[ $i - 1 ] - $xfade_dur );
                $out_label = ( $i === $n - 1 ) ? '[vout]' : '[v' . $i . ']';
                $filter_parts[] = $prev_label . '[' . $i . ':v]xfade=transition=' . $xfade_type
                    . ':duration=' . number_format( $xfade_dur, 3, '.', '' )
                    . ':offset=' . number_format( $offset, 3, '.', '' )
                    . $out_label;
                $cumulative = $offset; // next xfade offset is relative to the output stream
                $prev_label = $out_label;
            }

            $filter_str = implode( ';', $filter_parts );

            $cmd = escapeshellarg( $ffmpeg ) . ' -y '
                 . implode( ' ', $input_parts )
                 . ' -filter_complex "' . $filter_str . '"'
                 . ' -map "[vout]"'
                 . ' -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30'
                 . ' -movflags +faststart'
                 . ' ' . escapeshellarg( $merged );

            error_log( "[BVK-Export] Xfade concat: {$cmd}" );

            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            if ( empty( $result['success'] ) ) {
                error_log( "[BVK-Export] Xfade failed, falling back to simple concat: " . ( $result['error'] ?? '' ) );
                // Fallback: simple concat without transitions
                $concat_file = $tmp_dir . '/concat.txt';
                $concat_content = '';
                foreach ( $segments as $seg ) {
                    $escaped = str_replace( '\\', '/', $seg );
                    $concat_content .= "file '" . str_replace( "'", "'\\''", $escaped ) . "'\n";
                }
                file_put_contents( $concat_file, $concat_content );

                $cmd = sprintf(
                    '%s -y -f concat -safe 0 -i %s -c copy -movflags +faststart %s',
                    escapeshellarg( $ffmpeg ),
                    escapeshellarg( $concat_file ),
                    escapeshellarg( $merged )
                );
                $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                if ( empty( $result['success'] ) ) {
                    throw new \Exception( "Ghép video thất bại: " . ( $result['error'] ?? 'unknown' ) );
                }
            }
        } else {
            $concat_file = $tmp_dir . '/concat.txt';
            $concat_content = '';
            foreach ( $segments as $seg ) {
                $escaped = str_replace( '\\', '/', $seg );
                $concat_content .= "file '" . str_replace( "'", "'\\''", $escaped ) . "'\n";
            }
            file_put_contents( $concat_file, $concat_content );

            $cmd = sprintf(
                '%s -y -f concat -safe 0 -i %s -c copy -movflags +faststart %s',
                escapeshellarg( $ffmpeg ),
                escapeshellarg( $concat_file ),
                escapeshellarg( $merged )
            );
            error_log( "[BVK-Export] Concat: {$cmd}" );

            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            if ( empty( $result['success'] ) ) {
                throw new \Exception( "Ghép video thất bại: " . ( $result['error'] ?? 'unknown' ) );
            }
        }

        // ── Audio mix (if any) ──
        $output = $tmp_dir . '/output.mp4';

        if ( ! empty( $audios ) ) {
            $cmd_parts = [
                escapeshellarg( $ffmpeg ),
                '-y',
                '-i ' . escapeshellarg( $merged ),
            ];

            foreach ( $audios as $a ) {
                $cmd_parts[] = '-i ' . escapeshellarg( $a['path'] );
            }

            $audio_streams = [];
            foreach ( $audios as $idx => $a ) {
                $audio_streams[] = '[' . ( $idx + 1 ) . ':a]';
            }

            $cmd_parts[] = '-filter_complex "' . implode( '', $audio_streams ) . 'amix=inputs=' . count( $audios ) . ':duration=longest[outa]"';
            $cmd_parts[] = '-map 0:v -map "[outa]"';
            $cmd_parts[] = '-c:v copy -c:a aac -b:a 128k';
            $cmd_parts[] = '-movflags +faststart -shortest';
            $cmd_parts[] = escapeshellarg( $output );

            $cmd = implode( ' ', $cmd_parts );
            error_log( "[BVK-Export] Audio mix: {$cmd}" );

            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            if ( empty( $result['success'] ) ) {
                error_log( "[BVK-Export] Audio mix failed, using video-only: " . ( $result['error'] ?? '' ) );
                copy( $merged, $output );
            }
        } else {
            copy( $merged, $output );
        }

        return $output;
    }

    /**
     * Recursively remove a temp directory
     */
    private static function cleanup_dir( $dir ) {
        if ( ! is_dir( $dir ) ) return;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $dir );
    }
}
