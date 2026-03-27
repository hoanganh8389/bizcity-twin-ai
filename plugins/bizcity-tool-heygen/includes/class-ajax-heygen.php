<?php
/**
 * BizCity Tool HeyGen — AJAX Handlers
 *
 * Frontend AJAX for: upload voice sample, create character, clone voice,
 * create video, poll jobs, save settings, upload to media.
 *
 * @package BizCity_Tool_HeyGen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_HeyGen_Ajax {

    /**
     * Register all AJAX hooks
     */
    public static function init() {
        // Character management
        add_action( 'wp_ajax_bthg_save_character',   [ __CLASS__, 'handle_save_character' ] );
        add_action( 'wp_ajax_bthg_delete_character',  [ __CLASS__, 'handle_delete_character' ] );
        add_action( 'wp_ajax_bthg_clone_voice',       [ __CLASS__, 'handle_clone_voice' ] );
        add_action( 'wp_ajax_bthg_list_characters',   [ __CLASS__, 'handle_list_characters' ] );
        add_action( 'wp_ajax_bthg_get_character',     [ __CLASS__, 'handle_get_character' ] );

        // File uploads
        add_action( 'wp_ajax_bthg_upload_voice',     [ __CLASS__, 'handle_upload_voice' ] );
        add_action( 'wp_ajax_bthg_upload_avatar',    [ __CLASS__, 'handle_upload_avatar' ] );

        // Video creation
        add_action( 'wp_ajax_bthg_create_video',     [ __CLASS__, 'handle_create_video' ] );
        add_action( 'wp_ajax_bthg_upload_audio_for_video', [ __CLASS__, 'handle_upload_audio_for_video' ] );

        // Poll / refresh job status
        add_action( 'wp_ajax_bthg_poll_jobs',        [ __CLASS__, 'handle_poll_jobs' ] );

        // Save settings
        add_action( 'wp_ajax_bthg_save_settings',    [ __CLASS__, 'handle_save_settings' ] );

        // Upload video to WP Media (manual trigger)
        add_action( 'wp_ajax_bthg_upload_to_media',  [ __CLASS__, 'handle_upload_to_media' ] );

        // HeyGen talking photos
        add_action( 'wp_ajax_bthg_list_talking_photos', [ __CLASS__, 'handle_list_talking_photos' ] );

        // Push photo to HeyGen (upload → create avatar group → train)
        add_action( 'wp_ajax_bthg_push_photo_heygen', [ __CLASS__, 'handle_push_photo_heygen' ] );
        add_action( 'wp_ajax_bthg_poll_training',     [ __CLASS__, 'handle_poll_training' ] );

        // Push video to HeyGen (upload → create video avatar → train)
        add_action( 'wp_ajax_bthg_push_video_heygen',   [ __CLASS__, 'handle_push_video_heygen' ] );
        add_action( 'wp_ajax_bthg_poll_video_training',  [ __CLASS__, 'handle_poll_video_training' ] );
        add_action( 'wp_ajax_bthg_save_video_avatar_id', [ __CLASS__, 'handle_save_video_avatar_id' ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Character Management
     * ═══════════════════════════════════════════════════════ */

    /**
     * Save (create/update) character
     */
    public static function handle_save_character() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Bạn không có quyền quản lý nhân vật.' ] );
        }

        $id = intval( $_POST['character_id'] ?? 0 );

        $data = [
            'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'slug'           => sanitize_title( wp_unslash( $_POST['slug'] ?? $_POST['name'] ?? '' ) ),
            'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'persona_prompt' => sanitize_textarea_field( wp_unslash( $_POST['persona_prompt'] ?? '' ) ),
            'tone_of_voice'  => sanitize_text_field( wp_unslash( $_POST['tone_of_voice'] ?? '' ) ),
            'language'       => sanitize_text_field( $_POST['language'] ?? 'vi' ),
            'avatar_id'      => sanitize_text_field( $_POST['avatar_id'] ?? '' ),
            'image_url'      => esc_url_raw( $_POST['image_url'] ?? '' ),
            'default_cta'    => sanitize_text_field( wp_unslash( $_POST['default_cta'] ?? '' ) ),
            'status'         => ( isset( $_POST['status'] ) && in_array( $_POST['status'], [ 'active', 'inactive' ], true ) ) ? sanitize_text_field( $_POST['status'] ) : 'active',
        ];

        if ( empty( $data['name'] ) ) {
            wp_send_json_error( [ 'message' => 'Tên nhân vật không được để trống.' ] );
        }

        if ( $id ) {
            BizCity_Tool_HeyGen_Database::update_character( $id, $data );
            wp_send_json_success( [ 'message' => 'Đã cập nhật nhân vật.', 'character_id' => $id ] );
        } else {
            $new_id = BizCity_Tool_HeyGen_Database::create_character( $data );
            if ( $new_id ) {
                wp_send_json_success( [ 'message' => 'Đã tạo nhân vật mới.', 'character_id' => $new_id ] );
            } else {
                wp_send_json_error( [ 'message' => 'Lỗi tạo nhân vật. Kiểm tra slug có bị trùng không.' ] );
            }
        }
    }

    /**
     * Delete character
     */
    public static function handle_delete_character() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        $id = intval( $_POST['character_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Missing character_id.' ] );
        }

        BizCity_Tool_HeyGen_Database::delete_character( $id );
        wp_send_json_success( [ 'message' => 'Đã xóa nhân vật.' ] );
    }

    /**
     * Clone voice via HeyGen API
     */
    public static function handle_clone_voice() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        $character_id = intval( $_POST['character_id'] ?? 0 );
        if ( ! $character_id ) {
            wp_send_json_error( [ 'message' => 'Missing character_id.' ] );
        }

        $character = BizCity_Tool_HeyGen_Database::get_character( $character_id );
        if ( ! $character ) {
            wp_send_json_error( [ 'message' => 'Nhân vật không tồn tại.' ] );
        }

        if ( empty( $character->voice_sample_url ) ) {
            wp_send_json_error( [ 'message' => 'Nhân vật chưa có voice sample. Upload trước nhé.' ] );
        }

        // Mark as cloning
        BizCity_Tool_HeyGen_Database::update_character( $character_id, [
            'voice_clone_status' => 'cloning',
        ] );

        $result = bizcity_heygen_clone_voice( $character->voice_sample_url, $character->name );

        if ( ! empty( $result['ok'] ) && ! empty( $result['voice_id'] ) ) {
            BizCity_Tool_HeyGen_Database::update_character( $character_id, [
                'voice_id'           => $result['voice_id'],
                'voice_clone_status' => 'cloned',
            ] );
            wp_send_json_success( [
                'message'  => 'Clone voice thành công!',
                'voice_id' => $result['voice_id'],
            ] );
        } else {
            $err_msg = $result['error'] ?? 'Unknown error';
            if ( is_array( $err_msg ) ) $err_msg = wp_json_encode( $err_msg, JSON_UNESCAPED_UNICODE );
            $http_code = $result['http_code'] ?? '';

            $existing_meta = json_decode( $character->metadata ?? '{}', true ) ?: [];
            $existing_meta['clone_error']     = $err_msg;
            $existing_meta['clone_http_code'] = $http_code;
            $existing_meta['clone_time']      = current_time( 'mysql' );

            BizCity_Tool_HeyGen_Database::update_character( $character_id, [
                'voice_clone_status' => 'failed',
                'metadata' => wp_json_encode( $existing_meta, JSON_UNESCAPED_UNICODE ),
            ] );
            wp_send_json_error( [
                'message' => 'Clone voice thất bại' . ( $http_code ? " (HTTP {$http_code})" : '' ) . ': ' . $err_msg,
            ] );
        }
    }

    /**
     * List characters (for select dropdowns)
     */
    public static function handle_list_characters() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $characters = BizCity_Tool_HeyGen_Database::get_active_characters();
        $list = [];
        foreach ( $characters as $c ) {
            $list[] = [
                'id'                 => $c->id,
                'name'               => $c->name,
                'slug'               => $c->slug,
                'voice_id'           => $c->voice_id ?: '',
                'voice_clone_status' => $c->voice_clone_status,
                'has_avatar'         => ! empty( $c->avatar_id ) || ! empty( $c->image_url ),
                'image_url'          => $c->image_url ?: '',
            ];
        }

        wp_send_json_success( [ 'characters' => $list ] );
    }

    /**
     * Get single character data (for edit form)
     */
    public static function handle_get_character() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $id = intval( $_POST['character_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [ 'message' => 'Missing character_id.' ] );
        }

        $c = BizCity_Tool_HeyGen_Database::get_character( $id );
        if ( ! $c ) {
            wp_send_json_error( [ 'message' => 'Nhân vật không tồn tại.' ] );
        }

        wp_send_json_success( [
            'character' => [
                'id'                 => $c->id,
                'name'               => $c->name,
                'slug'               => $c->slug,
                'description'        => $c->description ?: '',
                'persona_prompt'     => $c->persona_prompt ?: '',
                'tone_of_voice'      => $c->tone_of_voice ?: '',
                'language'           => $c->language ?: 'vi',
                'voice_sample_url'   => $c->voice_sample_url ?: '',
                'voice_id'           => $c->voice_id ?: '',
                'voice_clone_status' => $c->voice_clone_status ?: 'none',
                'avatar_id'          => $c->avatar_id ?: '',
                'image_url'          => $c->image_url ?: '',
                'default_cta'        => $c->default_cta ?: '',
                'status'             => $c->status ?: 'active',
                'metadata'           => $c->metadata ?: '{}',
            ],
        ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  File Uploads
     * ═══════════════════════════════════════════════════════ */

    /**
     * Upload voice sample → WP Media → return URL
     */
    public static function handle_upload_voice() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        if ( empty( $_FILES['voice_file'] ) ) {
            wp_send_json_error( [ 'message' => 'Không nhận được file âm thanh.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_upload( 'voice_file', 0 );
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( [ 'message' => $attach_id->get_error_message() ] );
        }

        $url = wp_get_attachment_url( $attach_id );

        // Update character if character_id provided
        $character_id = intval( $_POST['character_id'] ?? 0 );
        if ( $character_id ) {
            BizCity_Tool_HeyGen_Database::update_character( $character_id, [
                'voice_sample_url'           => $url,
                'voice_sample_attachment_id' => $attach_id,
                'voice_clone_status'         => 'none', // Reset clone status
            ] );
        }

        wp_send_json_success( [
            'attachment_id' => $attach_id,
            'url'           => $url,
        ] );
    }

    /**
     * Upload avatar image → WP Media → return URL
     */
    public static function handle_upload_avatar() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        if ( empty( $_FILES['avatar_file'] ) ) {
            wp_send_json_error( [ 'message' => 'Không nhận được file ảnh.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_upload( 'avatar_file', 0 );
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( [ 'message' => $attach_id->get_error_message() ] );
        }

        $url = wp_get_attachment_url( $attach_id );

        // Update character if character_id provided
        $character_id = intval( $_POST['character_id'] ?? 0 );
        if ( $character_id && current_user_can( 'manage_options' ) ) {
            BizCity_Tool_HeyGen_Database::update_character( $character_id, [
                'image_url'             => $url,
                'image_attachment_id'   => $attach_id,
            ] );
        }

        wp_send_json_success( [
            'attachment_id' => $attach_id,
            'url'           => $url,
        ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  HeyGen Talking Photos — List & Assign
     * ═══════════════════════════════════════════════════════ */

    public static function handle_list_talking_photos() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        $result = bizcity_heygen_list_talking_photos();

        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [
                'message' => 'Lỗi lấy danh sách Talking Photos: ' . ( $result['error'] ?? 'Unknown' ),
                'data'    => $result['data'] ?? null,
            ] );
        }

        // If assign_to_character is set, save the selected talking_photo_id
        $assign_to = intval( $_POST['assign_to_character'] ?? 0 );
        $selected  = sanitize_text_field( wp_unslash( $_POST['talking_photo_id'] ?? '' ) );

        if ( $assign_to && $selected ) {
            BizCity_Tool_HeyGen_Database::update_character( $assign_to, [
                'avatar_id' => $selected,
            ] );
            wp_send_json_success( [
                'message'  => 'Đã gán Talking Photo ID thành công!',
                'assigned' => true,
                'photos'   => $result['photos'],
            ] );
        }

        wp_send_json_success( [
            'photos' => $result['photos'],
        ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Push Photo to HeyGen — Upload → Create Group → Train
     * ═══════════════════════════════════════════════════════ */

    /**
     * Push character's image to HeyGen: upload asset → create group → train
     * Client should poll training status afterwards via bthg_poll_training.
     */
    public static function handle_push_photo_heygen() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        $character_id = intval( $_POST['character_id'] ?? 0 );
        if ( ! $character_id ) {
            wp_send_json_error( [ 'message' => 'Missing character_id.' ] );
        }

        $character = BizCity_Tool_HeyGen_Database::get_character( $character_id );
        if ( ! $character ) {
            wp_send_json_error( [ 'message' => 'Nhân vật không tồn tại.' ] );
        }

        $image_url = $character->image_url;
        if ( empty( $image_url ) ) {
            wp_send_json_error( [ 'message' => 'Nhân vật chưa có ảnh đại diện. Upload ảnh trước.' ] );
        }

        // ── Duplicate detection: check if this image was already pushed ──
        $meta = json_decode( $character->metadata ?? '{}', true ) ?: [];
        $prev_image = $meta['heygen_push_image_url'] ?? '';
        $prev_group = $meta['heygen_group_id'] ?? '';
        $prev_status = $meta['heygen_push_status'] ?? '';

        if ( $prev_group && $prev_image === $image_url ) {
            // Same image was already pushed — check current training status
            $check = bizcity_heygen_get_training_status( $prev_group );
            $cur_status = strtolower( $check['status'] ?? 'unknown' );
            $avatar_id  = $check['avatar_id'] ?? $prev_group;

            if ( $cur_status === 'ready' || $cur_status === 'completed' ) {
                // Already trained! Update character and return
                $meta['heygen_push_status'] = 'ready';
                $update = [ 'metadata' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ) ];
                if ( $avatar_id ) {
                    $update['avatar_id'] = $avatar_id;
                }
                BizCity_Tool_HeyGen_Database::update_character( $character_id, $update );

                wp_send_json_success( [
                    'message'   => 'Ảnh này đã được push & training xong trước đó!',
                    'group_id'  => $prev_group,
                    'avatar_id' => $avatar_id,
                    'status'    => 'already_ready',
                ] );
            }

            if ( $cur_status === 'pending' || $cur_status === 'training' ) {
                wp_send_json_success( [
                    'message'   => 'Ảnh này đang được training. Vui lòng đợi...',
                    'group_id'  => $prev_group,
                    'avatar_id' => $avatar_id,
                    'status'    => 'training',
                ] );
            }

            // If status is failed/unknown, fall through to re-push
            error_log( '[BTHG] handle_push_photo_heygen: previous push status=' . $cur_status . ' — will re-push' );
        }

        // Download image to temp file
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp_file = download_url( $image_url, 60 );
        if ( is_wp_error( $tmp_file ) ) {
            wp_send_json_error( [ 'message' => 'Không tải được ảnh: ' . $tmp_file->get_error_message() ] );
        }

        // Detect MIME type from file extension
        $ext  = strtolower( pathinfo( wp_parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        $mime = 'image/jpeg';
        if ( $ext === 'png' ) $mime = 'image/png';

        // Also try to detect from actual downloaded file
        $finfo_mime = wp_check_filetype( $tmp_file );
        if ( ! empty( $finfo_mime['type'] ) ) {
            $mime = $finfo_mime['type'];
        }

        error_log( '[BTHG] handle_push_photo_heygen: char=' . $character_id . ' image_url=' . $image_url . ' ext=' . $ext . ' mime=' . $mime . ' tmpfile=' . $tmp_file . ' size=' . filesize( $tmp_file ) );

        // Run full pipeline: Upload → Create Group → Add Looks → Train
        $result = bizcity_heygen_push_photo_avatar( $tmp_file, $mime, $character->name );

        // Clean up temp file
        if ( file_exists( $tmp_file ) ) {
            wp_delete_file( $tmp_file );
        }

        if ( empty( $result['ok'] ) ) {
            // If group was created but training failed, still save group_id
            if ( ! empty( $result['group_id'] ) || ! empty( $result['image_key'] ) ) {
                $meta = json_decode( $character->metadata ?? '{}', true ) ?: [];
                if ( ! empty( $result['group_id'] ) )  $meta['heygen_group_id']  = $result['group_id'];
                if ( ! empty( $result['image_key'] ) )  $meta['heygen_image_key'] = $result['image_key'];
                $meta['heygen_push_status'] = ( $result['step'] ?? '' ) === 'train' ? 'train_failed' : 'failed';
                $meta['heygen_push_error']  = $result['error'] ?? '';
                $meta['heygen_push_step']   = $result['step'] ?? '';
                $meta['heygen_push_time']   = current_time( 'mysql' );
                BizCity_Tool_HeyGen_Database::update_character( $character_id, [
                    'metadata' => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
                ] );
            }
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Push photo thất bại.', 'step' => $result['step'] ?? '' ] );
        }

        // Save group_id + avatar_id + image_key + image_url to character metadata
        $update = [];
        $meta = json_decode( $character->metadata ?? '{}', true ) ?: [];
        $meta['heygen_group_id']       = $result['group_id'];
        $meta['heygen_image_key']      = $result['image_key'] ?? '';
        $meta['heygen_push_status']    = 'training';
        $meta['heygen_push_time']      = current_time( 'mysql' );
        $meta['heygen_push_image_url'] = $image_url;
        $update['metadata'] = wp_json_encode( $meta, JSON_UNESCAPED_UNICODE );

        if ( ! empty( $result['avatar_id'] ) ) {
            $update['avatar_id'] = $result['avatar_id'];
        }

        BizCity_Tool_HeyGen_Database::update_character( $character_id, $update );

        wp_send_json_success( [
            'message'   => 'Đã upload & bắt đầu training trên HeyGen! Vui lòng đợi...',
            'group_id'  => $result['group_id'],
            'avatar_id' => $result['avatar_id'] ?? '',
            'image_key' => $result['image_key'] ?? '',
            'status'    => 'training',
        ] );
    }

    /**
     * Poll training status for a character's avatar group
     */
    public static function handle_poll_training() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        $character_id = intval( $_POST['character_id'] ?? 0 );
        $group_id     = sanitize_text_field( $_POST['group_id'] ?? '' );

        // If no group_id passed, try from character metadata
        if ( empty( $group_id ) && $character_id ) {
            $character = BizCity_Tool_HeyGen_Database::get_character( $character_id );
            if ( $character ) {
                $meta     = json_decode( $character->metadata ?? '{}', true ) ?: [];
                $group_id = $meta['heygen_group_id'] ?? '';
            }
        }

        if ( empty( $group_id ) ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy group_id.' ] );
        }

        $result = bizcity_heygen_get_training_status( $group_id );

        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi kiểm tra training: ' . ( $result['error'] ?? 'Unknown' ) ] );
        }

        $status    = $result['status'] ?? 'unknown';
        $avatar_id = $result['avatar_id'] ?? $group_id; // Photo Avatar: group_id IS avatar_id

        // If training is ready, update character with avatar_id
        if ( strtolower( $status ) === 'ready' && $character_id ) {
            $update = [];
            $character = BizCity_Tool_HeyGen_Database::get_character( $character_id );
            $meta = json_decode( $character->metadata ?? '{}', true ) ?: [];
            $meta['heygen_push_status'] = 'ready';
            $update['metadata'] = wp_json_encode( $meta, JSON_UNESCAPED_UNICODE );

            if ( ! empty( $avatar_id ) ) {
                $update['avatar_id'] = $avatar_id;
            }

            BizCity_Tool_HeyGen_Database::update_character( $character_id, $update );
        }

        wp_send_json_success( [
            'status'    => $status,
            'avatar_id' => $avatar_id,
            'group_id'  => $group_id,
        ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Create Video — Reuses tool callback
     * ═══════════════════════════════════════════════════════ */

    public static function handle_create_video() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $slots = [
            'character_id' => intval( $_POST['character_id'] ?? 0 ),
            'script'       => sanitize_textarea_field( wp_unslash( $_POST['script'] ?? '' ) ),
            'mode'         => sanitize_text_field( $_POST['mode'] ?? 'text' ),
            'user_id'      => $user_id,
            'session_id'   => 'profile_direct_' . $user_id,
            '_meta'        => [],
        ];

        // Audio mode: accept audio_url from frontend (uploaded via bthg_upload_audio_for_video)
        if ( $slots['mode'] === 'audio' && ! empty( $_POST['audio_url'] ) ) {
            $slots['audio_url'] = esc_url_raw( wp_unslash( $_POST['audio_url'] ) );
        }

        $result = BizCity_Tool_HeyGen::create_lipsync_video( $slots, [] );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * Upload audio file for video creation → WP Media → return URL
     */
    public static function handle_upload_audio_for_video() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        if ( empty( $_FILES['audio_file'] ) ) {
            wp_send_json_error( [ 'message' => 'Không nhận được file âm thanh.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attach_id = media_handle_upload( 'audio_file', 0 );
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( [ 'message' => $attach_id->get_error_message() ] );
        }

        $url = wp_get_attachment_url( $attach_id );

        wp_send_json_success( [
            'attachment_id' => $attach_id,
            'url'           => $url,
        ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Poll Jobs — Return recent jobs for current user
     * ═══════════════════════════════════════════════════════ */

    public static function handle_poll_jobs() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        global $wpdb;
        $jobs_table = BizCity_Tool_HeyGen_Database::get_table_name( 'jobs' );
        $chars_table = BizCity_Tool_HeyGen_Database::get_table_name( 'characters' );

        $has_table = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;
        if ( ! $has_table ) {
            wp_send_json_success( [ 'jobs' => [], 'stats' => [ 'total' => 0, 'done' => 0, 'active' => 0 ] ] );
        }

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT j.id, j.character_id, j.script, j.status, j.progress, j.video_url, j.media_url,
                    j.attachment_id, j.mode, j.checkpoints, j.error_message, j.created_at, j.updated_at,
                    c.name AS character_name
             FROM {$jobs_table} j
             LEFT JOIN {$chars_table} c ON j.character_id = c.id
             WHERE j.created_by = %d
             ORDER BY j.created_at DESC LIMIT 20",
            $user_id
        ), ARRAY_A );

        foreach ( $jobs as &$j ) {
            $j['checkpoints'] = ! empty( $j['checkpoints'] ) ? json_decode( $j['checkpoints'], true ) : [];

            // Auto-heal: if checkpoints show completion but status is stale (race condition)
            if (
                in_array( $j['status'], [ 'queued', 'processing' ], true )
                && ! empty( $j['checkpoints']['video_completed'] )
                && ( ! empty( $j['media_url'] ) || ! empty( $j['checkpoints']['media_uploaded'] ) )
            ) {
                BizCity_Tool_HeyGen_Database::update_job( (int) $j['id'], [
                    'status'   => 'completed',
                    'progress' => 100,
                ] );
                $j['status']   = 'completed';
                $j['progress'] = 100;
                error_log( '[BTHG] Auto-healed stale job #' . $j['id'] . ' → completed' );
            }
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

    /* ═══════════════════════════════════════════════════════
     *  Save Settings
     * ═══════════════════════════════════════════════════════ */

    public static function handle_save_settings() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        // API fields — admin only
        if ( current_user_can( 'manage_options' ) ) {
            if ( isset( $_POST['api_key'] ) ) {
                update_option( 'bizcity_tool_heygen_api_key', sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) );
            }
            if ( isset( $_POST['endpoint'] ) ) {
                update_option( 'bizcity_tool_heygen_endpoint', esc_url_raw( wp_unslash( $_POST['endpoint'] ) ) );
            }
        }

        // Default mode — any logged-in user
        $allowed_modes = [ 'text', 'audio' ];
        if ( isset( $_POST['default_mode'] ) && in_array( $_POST['default_mode'], $allowed_modes, true ) ) {
            update_option( 'bizcity_tool_heygen_default_mode', sanitize_text_field( $_POST['default_mode'] ) );
        }

        wp_send_json_success( [ 'message' => 'Đã lưu cài đặt thành công.' ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Upload to WP Media — Manual trigger for completed jobs
     * ═══════════════════════════════════════════════════════ */

    public static function handle_upload_to_media() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            wp_send_json_error( [ 'message' => 'Vui lòng đăng nhập.' ] );
        }

        $job_id = intval( $_POST['job_id'] ?? 0 );
        if ( ! $job_id ) {
            wp_send_json_error( [ 'message' => 'Missing job_id.' ] );
        }

        $job = BizCity_Tool_HeyGen_Database::get_job( $job_id );
        if ( ! $job || (int) $job->created_by !== $user_id ) {
            wp_send_json_error( [ 'message' => 'Job không tồn tại hoặc không có quyền.' ] );
        }

        // Already uploaded?
        if ( ! empty( $job->media_url ) && ! empty( $job->attachment_id ) ) {
            if ( wp_get_attachment_url( $job->attachment_id ) ) {
                wp_send_json_success( [
                    'message'       => 'Video đã có trong Media Library.',
                    'media_url'     => $job->media_url,
                    'attachment_id' => (int) $job->attachment_id,
                    'duplicate'     => true,
                ] );
            }
        }

        $video_url = $job->video_url;
        if ( empty( $video_url ) ) {
            wp_send_json_error( [ 'message' => 'Job chưa có video URL từ HeyGen.' ] );
        }

        $result = bizcity_heygen_download_to_media( $video_url, "heygen-video-{$job_id}.mp4" );

        if ( empty( $result['ok'] ) ) {
            wp_send_json_error( [ 'message' => 'Lỗi tải video: ' . ( $result['error'] ?? 'Unknown' ) ] );
        }

        BizCity_Tool_HeyGen_Database::update_job( $job_id, [
            'media_url'     => $result['media_url'],
            'attachment_id' => $result['attachment_id'],
        ] );
        BizCity_Tool_HeyGen_Database::set_checkpoint( $job_id, 'manual_media_upload' );

        wp_send_json_success( [
            'message'       => 'Đã upload video vào Media Library!',
            'media_url'     => $result['media_url'],
            'attachment_id' => $result['attachment_id'],
            'duplicate'     => false,
        ] );
    }

    /* ═══════════════════════════════════════════════════════
     *  Push Video to HeyGen — Upload → Create Video Avatar → Poll
     * ═══════════════════════════════════════════════════════ */

    public static function handle_push_video_heygen() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        $char_id = intval( $_POST['character_id'] ?? 0 );
        if ( ! $char_id ) {
            wp_send_json_error( [ 'message' => 'Thiếu character_id.' ] );
        }

        $char = BizCity_Tool_HeyGen_Database::get_character( $char_id );
        if ( ! $char ) {
            wp_send_json_error( [ 'message' => 'Nhân vật không tồn tại.' ] );
        }

        $meta = json_decode( $char->metadata ?? '{}', true ) ?: [];

        // Already done?
        if ( ( $meta['heygen_video_push_status'] ?? '' ) === 'ready' && ! empty( $meta['heygen_video_avatar_id'] ) ) {
            wp_send_json_success( [
                'status'    => 'already_ready',
                'avatar_id' => $meta['heygen_video_avatar_id'],
                'message'   => 'Video avatar đã train xong trước đó.',
            ] );
        }

        /* ── Step 1: Get video file ── */
        $tmp_file = '';
        $mime     = 'video/mp4';

        if ( ! empty( $_FILES['video_file']['tmp_name'] ) ) {
            $tmp_file = sanitize_text_field( $_FILES['video_file']['tmp_name'] );
            $mime     = sanitize_mime_type( $_FILES['video_file']['type'] ) ?: 'video/mp4';
        } elseif ( ! empty( $_POST['video_url'] ) ) {
            $video_url = esc_url_raw( $_POST['video_url'] );
            $tmp_file  = download_url( $video_url, 120 );
            if ( is_wp_error( $tmp_file ) ) {
                wp_send_json_error( [ 'message' => 'Không tải được video: ' . $tmp_file->get_error_message(), 'step' => 'download' ] );
            }
        } else {
            wp_send_json_error( [ 'message' => 'Thiếu video URL hoặc file.', 'step' => 'input' ] );
        }

        /* ── Step 2: Upload to HeyGen ── */
        $upload = bizcity_heygen_upload_video_asset( $tmp_file, $mime );

        // Clean up temp file from download_url
        if ( ! empty( $_POST['video_url'] ) && file_exists( $tmp_file ) ) {
            wp_delete_file( $tmp_file );
        }

        if ( ! $upload['ok'] ) {
            $meta['heygen_video_push_status'] = 'failed';
            $meta['heygen_video_push_error']  = $upload['error'] ?? 'Upload failed';
            BizCity_Tool_HeyGen_Database::update_character( $char_id, [ 'metadata' => wp_json_encode( $meta ) ] );
            wp_send_json_error( [ 'message' => 'Upload video thất bại: ' . ( $upload['error'] ?? 'Unknown' ), 'step' => 'upload' ] );
        }

        $video_key = $upload['video_key'];
        $video_url_heygen = $upload['video_url'] ?? '';
        $meta['heygen_video_key'] = $video_key;
        $meta['heygen_video_upload_url'] = $video_url_heygen;

        /* ── Step 3: Create video avatar ── */
        $name   = $char->name ?: ( 'BizCity VA ' . $char_id );
        $create = bizcity_heygen_create_video_avatar( $name, $video_key, $video_url_heygen );

        if ( ! $create['ok'] ) {
            $meta['heygen_video_push_status'] = 'failed';
            $meta['heygen_video_push_error']  = $create['error'] ?? 'Create failed';
            BizCity_Tool_HeyGen_Database::update_character( $char_id, [ 'metadata' => wp_json_encode( $meta ) ] );
            wp_send_json_error( [ 'message' => 'Tạo video avatar thất bại: ' . ( $create['error'] ?? 'Unknown' ), 'step' => 'create' ] );
        }

        $avatar_id = $create['avatar_id'];
        $meta['heygen_video_avatar_id']     = $avatar_id;
        $meta['heygen_video_push_status']   = 'training';
        $meta['heygen_video_training_url']  = $_POST['video_url'] ?? '';
        unset( $meta['heygen_video_push_error'] );

        BizCity_Tool_HeyGen_Database::update_character( $char_id, [ 'metadata' => wp_json_encode( $meta ) ] );

        wp_send_json_success( [
            'message'   => 'Video đã upload! Đang training...',
            'avatar_id' => $avatar_id,
            'status'    => 'training',
        ] );
    }

    public static function handle_poll_video_training() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        $char_id   = intval( $_POST['character_id'] ?? 0 );
        $avatar_id = sanitize_text_field( $_POST['avatar_id'] ?? '' );

        if ( ! $char_id || ! $avatar_id ) {
            wp_send_json_error( [ 'message' => 'Thiếu character_id hoặc avatar_id.' ] );
        }

        $result = bizcity_heygen_get_video_avatar_status( $avatar_id );

        if ( ! $result['ok'] ) {
            wp_send_json_error( [ 'message' => 'Lỗi check status: ' . ( $result['error'] ?? 'Unknown' ) ] );
        }

        $status = $result['status'];

        if ( in_array( $status, [ 'ready', 'completed' ], true ) ) {
            $char = BizCity_Tool_HeyGen_Database::get_character( $char_id );
            $meta = json_decode( $char->metadata ?? '{}', true ) ?: [];
            $meta['heygen_video_push_status'] = 'ready';
            $meta['heygen_video_avatar_id']   = $avatar_id;
            BizCity_Tool_HeyGen_Database::update_character( $char_id, [ 'metadata' => wp_json_encode( $meta ) ] );
        } elseif ( $status === 'failed' ) {
            $char = BizCity_Tool_HeyGen_Database::get_character( $char_id );
            $meta = json_decode( $char->metadata ?? '{}', true ) ?: [];
            $meta['heygen_video_push_status'] = 'train_failed';
            $meta['heygen_video_push_error']  = $result['raw']['error'] ?? 'Training failed';
            BizCity_Tool_HeyGen_Database::update_character( $char_id, [ 'metadata' => wp_json_encode( $meta ) ] );
        }

        wp_send_json_success( [
            'status'    => $status,
            'avatar_id' => $avatar_id,
            'error'     => $result['raw']['error'] ?? '',
        ] );
    }

    /**
     * Save Video Avatar ID manually (from HeyGen dashboard)
     */
    public static function handle_save_video_avatar_id() {
        check_ajax_referer( 'bthg_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Không có quyền.' ] );
        }

        $char_id   = intval( $_POST['character_id'] ?? 0 );
        $avatar_id = sanitize_text_field( $_POST['video_avatar_id'] ?? '' );

        if ( ! $char_id || ! $avatar_id ) {
            wp_send_json_error( [ 'message' => 'Thiếu character_id hoặc video_avatar_id.' ] );
        }

        $char = BizCity_Tool_HeyGen_Database::get_character( $char_id );
        if ( ! $char ) {
            wp_send_json_error( [ 'message' => 'Nhân vật không tồn tại.' ] );
        }

        $meta = json_decode( $char->metadata ?? '{}', true ) ?: [];
        $meta['heygen_video_avatar_id']   = $avatar_id;
        $meta['heygen_video_push_status'] = 'ready';
        unset( $meta['heygen_video_push_error'] );

        BizCity_Tool_HeyGen_Database::update_character( $char_id, [
            'metadata' => wp_json_encode( $meta ),
        ] );

        wp_send_json_success( [
            'message'   => 'Đã lưu Video Avatar ID!',
            'avatar_id' => $avatar_id,
        ] );
    }
}
