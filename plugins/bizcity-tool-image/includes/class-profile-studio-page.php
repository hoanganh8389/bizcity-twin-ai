<?php
/**
 * Full-page Profile Studio at /profile-studio/
 *
 * Face-swap / style-copy feature:
 *   - Upload user photo (face source)
 *   - Choose style template or upload custom style reference
 *   - AI generates portrait with user's face + reference style
 *
 * URLs:
 *   /profile-studio/          — Main profile studio page
 *   /profile-studio/?tab=X    — Jump to specific tool tab
 *
 * @package BizCity_Tool_Image
 * @since   3.8.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Profile_Studio_Page {

    const SLUG = 'profile-studio';
    const OPT_PROFILE_SEED_VERSION = 'bztimg_profile_seed_version';
    const OPT_PROFILE_SEEDED_FLAG  = 'bztimg_profile_seeded';
    const PROFILE_SEED_VERSION     = '2026.07.11.1';
    const PROFILE_SEED_LOCK        = 'bztimg_profile_seed_lock';

    /** @var array<string,bool> */
    private static $table_exists_cache = array();

    /**
     * Build cache key for table existence.
     */
    private static function table_exists_cache_key( string $table_name ): string {
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        return 'bz_tbl_' . $blog_id . '_' . crc32( $table_name );
    }

    /**
     * Check table existence via information_schema with dual cache.
     */
    private static function table_exists( string $table_name ): bool {
        if ( isset( self::$table_exists_cache[ $table_name ] ) ) {
            return (bool) self::$table_exists_cache[ $table_name ];
        }

        $ck     = self::table_exists_cache_key( $table_name );
        $cached = wp_cache_get( $ck, 'bizcity_tbl' );
        if ( false === $cached ) {
            global $wpdb;
            // [2026-07-11 Johnny Chu] R-SHOW-TABLES — avoid SHOW TABLES metadata scan on multisite.
            $cached = (int) (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                $table_name
            ) );
            wp_cache_set( $ck, $cached, 'bizcity_tbl', HOUR_IN_SECONDS );
        }

        self::$table_exists_cache[ $table_name ] = (bool) $cached;
        return self::$table_exists_cache[ $table_name ];
    }

    /**
     * Reset table existence cache for current request and persistent cache.
     */
    private static function invalidate_table_exists_cache( string $table_name ): void {
        unset( self::$table_exists_cache[ $table_name ] );
        wp_cache_delete( self::table_exists_cache_key( $table_name ), 'bizcity_tbl' );
    }

    /**
     * Ensure profile template table has data on first run / clone installs.
     */
    public static function maybe_seed_templates(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_profile_templates';

        if ( ! self::table_exists( $table ) ) {
            // [2026-07-11 Johnny Chu] HOTFIX — clone/new installs may miss profile table until admin page is opened.
            self::create_tables();
            if ( ! self::table_exists( $table ) ) {
                return;
            }
        }

        $active_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if ( $active_count > 0 ) {
            update_option( self::OPT_PROFILE_SEEDED_FLAG, 1, true );
            update_option( self::OPT_PROFILE_SEED_VERSION, self::PROFILE_SEED_VERSION, true );
            return;
        }

        if ( get_transient( self::PROFILE_SEED_LOCK ) ) {
            return;
        }
        set_transient( self::PROFILE_SEED_LOCK, 1, MINUTE_IN_SECONDS );

        try {
            self::seed_templates_from_file();
            $active_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'active'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            if ( $active_after > 0 ) {
                update_option( self::OPT_PROFILE_SEEDED_FLAG, 1, true );
                update_option( self::OPT_PROFILE_SEED_VERSION, self::PROFILE_SEED_VERSION, true );
            } else {
                delete_option( self::OPT_PROFILE_SEEDED_FLAG );
            }
        } finally {
            delete_transient( self::PROFILE_SEED_LOCK );
        }
    }

    /**
     * Seed default profile templates from bundled JSON.
     */
    private static function seed_templates_from_file(): void {
        $seed_path = BZTIMG_DIR . 'data/profile-templates-seed.json';
        if ( ! file_exists( $seed_path ) ) {
            return;
        }

        $raw   = file_get_contents( $seed_path );
        $items = json_decode( (string) $raw, true );
        if ( ! is_array( $items ) ) {
            return;
        }

        global $wpdb;
        $table       = $wpdb->prefix . 'bztimg_profile_templates';
        $valid_cates = array( 'all', 'man', 'woman', 'professional', 'creative' );

        foreach ( $items as $idx => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $title = sanitize_text_field( $item['title'] ?? '' );
            $thumb = esc_url_raw( $item['thumbnail_url'] ?? '' );
            if ( $title === '' || $thumb === '' ) {
                continue;
            }

            $exists = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE title = %s AND thumbnail_url = %s",
                $title,
                $thumb
            ) );
            if ( $exists > 0 ) {
                continue;
            }

            $category = sanitize_key( $item['category'] ?? 'all' );
            if ( ! in_array( $category, $valid_cates, true ) ) {
                $category = 'all';
            }

            $wpdb->insert(
                $table,
                array(
                    'title'         => $title,
                    'thumbnail_url' => $thumb,
                    'reference_url' => esc_url_raw( $item['reference_url'] ?? '' ) ?: $thumb,
                    'category'      => $category,
                    'style_prompt'  => sanitize_textarea_field( $item['style_prompt'] ?? '' ),
                    'sort_order'    => (int) ( $item['sort_order'] ?? $idx ),
                    'status'        => in_array( (string) ( $item['status'] ?? '' ), array( 'active', 'draft' ), true ) ? (string) $item['status'] : 'active',
                ),
                array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
            );
        }
    }

    public static function init() {
        add_action( 'init',              array( __CLASS__, 'register_rewrite' ) );
        add_filter( 'query_vars',        array( __CLASS__, 'register_query_var' ) );
        add_action( 'template_redirect', array( __CLASS__, 'render' ) );

        /* AJAX handlers */
        add_action( 'wp_ajax_bztimg_profile_face_swap',        array( __CLASS__, 'ajax_face_swap' ) );
        add_action( 'wp_ajax_bztimg_profile_get_templates',    array( __CLASS__, 'ajax_get_templates' ) );
        add_action( 'wp_ajax_bztimg_profile_upload_image',     array( __CLASS__, 'ajax_upload_image' ) );
        add_action( 'wp_ajax_bztimg_profile_gallery',          array( __CLASS__, 'ajax_gallery' ) );
        add_action( 'wp_ajax_bztimg_profile_check_jobs',       array( __CLASS__, 'ajax_check_jobs' ) );
        add_action( 'wp_ajax_bztimg_profile_job_history',     array( __CLASS__, 'ajax_job_history' ) );
        add_action( 'wp_ajax_bztimg_profile_retry_job',       array( __CLASS__, 'ajax_retry_job' ) );

        /* Hook: process created jobs via PiAPI faceswap */
        add_action( 'bztimg_profile_jobs_created', array( __CLASS__, 'process_jobs' ), 10, 2 );
    }

    /* ═══════════ Rewrite ═══════════ */

    public static function register_rewrite() {
        add_rewrite_rule(
            '^' . self::SLUG . '/?$',
            'index.php?bztimg_profile_studio=1',
            'top'
        );
    }

    public static function register_query_var( $vars ) {
        $vars[] = 'bztimg_profile_studio';
        return $vars;
    }

    /* ═══════════ Render full-page ═══════════ */

    public static function render() {
        if ( ! get_query_var( 'bztimg_profile_studio' ) ) {
            return;
        }

        // Require login
        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( home_url( '/' . self::SLUG . '/' ) ) );
            exit;
        }

        // [2026-07-11 Johnny Chu] HOTFIX — ensure bundled profile templates exist before rendering studio UI.
        self::maybe_seed_templates();

        $view = BZTIMG_DIR . 'views/page-profile-studio.php';
        if ( file_exists( $view ) ) {
            include $view;
        } else {
            wp_die( 'Profile Studio view not found.', 'Error', array( 'response' => 500 ) );
        }
        exit;
    }

    /* ═══════════ AJAX: Face Swap Generation ═══════════ */

    public static function ajax_face_swap() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id       = get_current_user_id();
        $source_url    = isset( $_POST['source_url'] )    ? esc_url_raw( $_POST['source_url'] )    : '';
        $reference_url = isset( $_POST['reference_url'] )  ? esc_url_raw( $_POST['reference_url'] )  : '';
        $face_lock     = isset( $_POST['face_lock'] )      ? (bool) $_POST['face_lock']               : true;
        $count         = isset( $_POST['count'] )          ? absint( $_POST['count'] )                 : 1;
        $tool          = isset( $_POST['tool'] )           ? sanitize_key( $_POST['tool'] )            : 'style-copy';
        $prompt        = isset( $_POST['prompt'] )         ? sanitize_textarea_field( $_POST['prompt'] ) : '';

        if ( empty( $source_url ) ) {
            wp_send_json_error( array( 'message' => 'Vui lòng tải lên ảnh của bạn.' ) );
        }
        if ( empty( $reference_url ) && $tool !== 'free-prompt' ) {
            wp_send_json_error( array( 'message' => 'Vui lòng chọn ảnh phong cách mẫu.' ) );
        }
        if ( $count < 1 || $count > 4 ) {
            $count = 1;
        }

        // Build generation params
        $params = array(
            'user_id'       => $user_id,
            'source_url'    => $source_url,
            'reference_url' => $reference_url,
            'face_lock'     => $face_lock,
            'count'         => $count,
            'tool'          => $tool,
            'prompt'        => $prompt,
        );

        // Dispatch to AI generation (via existing pipeline or Gateway)
        $result = self::dispatch_generation( $params );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( $result );
    }

    /* ═══════════ AJAX: Get Profile Templates ═══════════ */

    public static function ajax_get_templates() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $category = isset( $_GET['category'] ) ? sanitize_key( $_GET['category'] ) : 'all';
        $page     = isset( $_GET['page'] )     ? absint( $_GET['page'] )            : 1;
        $per_page = 20;

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_profile_templates';

        // [2026-07-11 Johnny Chu] HOTFIX — self-heal table+seed when profile studio is opened directly.
        self::maybe_seed_templates();

        if ( ! self::table_exists( $table ) ) {
            wp_send_json_success( array( 'templates' => array(), 'total' => 0 ) );
        }

        $where = "WHERE status = 'active'";
        $params = array();

        if ( $category && $category !== 'all' ) {
            $where .= " AND category = %s";
            $params[] = $category;
        }

        $offset = ( $page - 1 ) * $per_page;

        if ( ! empty( $params ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} {$where}", ...$params
            ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, title, thumbnail_url, reference_url, category, style_prompt FROM {$table} {$where} ORDER BY sort_order ASC, id DESC LIMIT %d OFFSET %d",
                ...array_merge( $params, array( $per_page, $offset ) )
            ), ARRAY_A );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, title, thumbnail_url, reference_url, category, style_prompt FROM {$table} {$where} ORDER BY sort_order ASC, id DESC LIMIT %d OFFSET %d",
                $per_page, $offset
            ), ARRAY_A );
        }

        wp_send_json_success( array(
            'templates' => $rows ?: array(),
            'total'     => $total,
        ) );
    }

    /* ═══════════ AJAX: Upload Image ═══════════ */

    public static function ajax_upload_image() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        if ( empty( $_FILES['image'] ) ) {
            wp_send_json_error( array( 'message' => 'No file uploaded.' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'image', 0 );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
        }

        $url = wp_get_attachment_url( $attachment_id );

        wp_send_json_success( array(
            'attachment_id' => $attachment_id,
            'url'           => $url,
        ) );
    }

    /* ═══════════ AJAX: User Gallery (past generations) ═══════════ */

    public static function ajax_gallery() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id  = get_current_user_id();
        $page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
        $per_page = 20;

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        if ( ! self::table_exists( $table ) ) {
            wp_send_json_success( array( 'images' => array(), 'total' => 0 ) );
        }

        $offset = ( $page - 1 ) * $per_page;
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND session_id = 'profile-studio' AND status = 'completed'",
            $user_id
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, image_url, created_at FROM {$table} WHERE user_id = %d AND session_id = 'profile-studio' AND status = 'completed' ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );

        wp_send_json_success( array(
            'images' => $rows ?: array(),
            'total'  => $total,
        ) );
    }

    /* ═══════════ AJAX: Check Job Status (polling) ═══════════ */

    public static function ajax_check_jobs() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $raw_ids = isset( $_GET['job_ids'] ) ? sanitize_text_field( $_GET['job_ids'] ) : '';

        if ( empty( $raw_ids ) ) {
            wp_send_json_error( array( 'message' => 'No job IDs provided.' ) );
        }

        // Parse and validate IDs
        $ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'Invalid job IDs.' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $query_args   = array_merge( array( $user_id ), $ids );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, status, image_url, chat_id, error_message, updated_at FROM {$table} WHERE user_id = %d AND id IN ({$placeholders})",
            ...$query_args
        ), ARRAY_A );

        // For 'processing' jobs with a PiAPI task_id, poll PiAPI for status update
        if ( ! class_exists( 'BizCity_PiAPI_Proxy' ) ) {
            $proxy_file = WP_PLUGIN_DIR . '/bizcity-llm-router/includes/class-piapi-proxy.php';
            if ( file_exists( $proxy_file ) ) {
                require_once $proxy_file;
            }
        }
        $piapi_available = class_exists( 'BizCity_PiAPI_Proxy' ) && BizCity_PiAPI_Proxy::is_ready();

        // Safety timeout: jobs stuck in 'processing' for > 10 min with no PiAPI
        // response should be marked failed so the UI doesn't spin forever.
        $processing_timeout_sec = 10 * MINUTE_IN_SECONDS;

        $jobs = array();
        foreach ( $rows as $row ) {
            // Check PiAPI status for processing jobs
            if ( $row['status'] === 'processing' && ! empty( $row['chat_id'] ) && $piapi_available ) {
                $task_result = BizCity_PiAPI_Proxy::get_task( $row['chat_id'] );

                if ( $task_result['success'] ) {
                    $piapi_status = $task_result['status'];

                    if ( $piapi_status === 'completed' && ! empty( $task_result['output'] ) ) {
                        // Extract result image URL from PiAPI output
                        $result_url = '';
                        if ( is_array( $task_result['output'] ) ) {
                            $result_url = $task_result['output']['image_url']
                                ?? $task_result['output']['result_url']
                                ?? ( is_string( $task_result['output'][0] ?? null ) ? $task_result['output'][0] : '' );
                        } elseif ( is_string( $task_result['output'] ) ) {
                            $result_url = $task_result['output'];
                        }

                        if ( ! empty( $result_url ) ) {
                            $wpdb->update(
                                $table,
                                array(
                                    'status'     => 'completed',
                                    'image_url'  => esc_url_raw( $result_url ),
                                    'updated_at' => current_time( 'mysql' ),
                                ),
                                array( 'id' => $row['id'] ),
                                array( '%s', '%s', '%s' ),
                                array( '%d' )
                            );
                            $row['status']    = 'completed';
                            $row['image_url'] = $result_url;
                        }
                    } elseif ( $piapi_status === 'failed' ) {
                        $error_msg = $task_result['error'] ?: 'PiAPI task failed.';
                        $wpdb->update(
                            $table,
                            array(
                                'status'        => 'failed',
                                'error_message' => sanitize_text_field( $error_msg ),
                                'updated_at'    => current_time( 'mysql' ),
                            ),
                            array( 'id' => $row['id'] ),
                            array( '%s', '%s', '%s' ),
                            array( '%d' )
                        );
                        $row['status']        = 'failed';
                        $row['error_message'] = $error_msg;
                    }
                    // else PiAPI reports still processing — leave as-is
                } else {
                    // get_task() failed (network error, invalid/expired task_id, API error).
                    // Mark job failed so the UI stops spinning. The user can retry.
                    $error_msg = $task_result['error'] ?: 'PiAPI: không lấy được trạng thái task.';
                    $wpdb->update(
                        $table,
                        array(
                            'status'        => 'failed',
                            'error_message' => sanitize_text_field( $error_msg ),
                            'updated_at'    => current_time( 'mysql' ),
                        ),
                        array( 'id' => $row['id'] ),
                        array( '%s', '%s', '%s' ),
                        array( '%d' )
                    );
                    $row['status']        = 'failed';
                    $row['error_message'] = $error_msg;
                }
            }

            // Safety net: if still processing but PiAPI is unavailable or no
            // chat_id, and the job is older than the timeout, mark it failed.
            if ( $row['status'] === 'processing' ) {
                $updated_ts = strtotime( $row['updated_at'] ?? '' );
                if ( $updated_ts && ( time() - $updated_ts ) > $processing_timeout_sec ) {
                    $error_msg = 'Quá thời gian xử lý (10 phút). Vui lòng thử lại.';
                    $wpdb->update(
                        $table,
                        array(
                            'status'        => 'failed',
                            'error_message' => $error_msg,
                            'updated_at'    => current_time( 'mysql' ),
                        ),
                        array( 'id' => $row['id'] ),
                        array( '%s', '%s', '%s' ),
                        array( '%d' )
                    );
                    $row['status']        = 'failed';
                    $row['error_message'] = $error_msg;
                }
            }

            $job = array(
                'job_id' => (int) $row['id'],
                'status' => $row['status'],
            );
            if ( $row['status'] === 'completed' && ! empty( $row['image_url'] ) ) {
                $job['image_url'] = $row['image_url'];
            }
            if ( $row['status'] === 'failed' ) {
                $job['error'] = $row['error_message'] ?: 'Unknown error';
            }
            $jobs[] = $job;
        }

        wp_send_json_success( array( 'jobs' => $jobs ) );
    }

    /* ═══════════ AJAX: Job History (all statuses) ═══════════ */

    public static function ajax_job_history() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id  = get_current_user_id();
        $page     = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;
        $per_page = 30;

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        if ( ! self::table_exists( $table ) ) {
            wp_send_json_success( array( 'jobs' => array(), 'total' => 0 ) );
        }

        $offset = ( $page - 1 ) * $per_page;
        $total  = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND session_id = 'profile-studio'",
            $user_id
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, model, style, ref_image, status, image_url, error_message, created_at
             FROM {$table}
             WHERE user_id = %d AND session_id = 'profile-studio'
             ORDER BY id DESC
             LIMIT %d OFFSET %d",
            $user_id, $per_page, $offset
        ), ARRAY_A );

        wp_send_json_success( array(
            'jobs'  => $rows ?: array(),
            'total' => $total,
        ) );
    }

    /* ═══════════ AJAX: Retry a pending/failed job ═══════════ */

    public static function ajax_retry_job() {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        $user_id = get_current_user_id();
        $job_id  = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => 'Invalid job ID.' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        // Verify ownership + retryable status
        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, status, prompt, ref_image, image_url, style FROM {$table} WHERE id = %d AND user_id = %d AND session_id = 'profile-studio'",
            $job_id, $user_id
        ), ARRAY_A );

        if ( ! $job ) {
            wp_send_json_error( array( 'message' => 'Job không tồn tại hoặc không thuộc về bạn.' ) );
        }

        if ( $job['status'] === 'completed' ) {
            wp_send_json_error( array( 'message' => 'Job đã hoàn tất, không cần retry.' ) );
        }

        // Reset job to pending
        $wpdb->update(
            $table,
            array(
                'status'        => 'pending',
                'error_message' => null,
                'updated_at'    => current_time( 'mysql' ),
            ),
            array( 'id' => $job_id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        // Re-dispatch
        $params = array(
            'user_id'       => $user_id,
            'source_url'    => $job['ref_image'],
            'reference_url' => $job['image_url'],
            'tool'          => $job['style'],
            'prompt'        => $job['prompt'],
            'face_lock'     => true,
            'count'         => 1,
        );
        do_action( 'bztimg_profile_jobs_created', array( array( 'job_id' => $job_id, 'status' => 'pending' ) ), $params );

        wp_send_json_success( array(
            'job_id' => $job_id,
            'status' => 'pending',
            'message' => 'Đã gửi lại lệnh xử lý.',
        ) );
    }

    /* ═══════════ Dispatch to AI Generation Pipeline ═══════════ */

    private static function dispatch_generation( array $params ) {
        /**
         * Hook into existing BizCity Gateway / LLM Router for face-swap generation.
         * This is a placeholder that integrates with the bizcity_llm_chat / Gateway API.
         *
         * The actual implementation will call the configured face-swap API endpoint
         * (e.g., Replicate, fal.ai, or custom Gateway endpoint).
         */
        $results = array();

        // Use the existing AJAX handler infrastructure
        $tool_class = class_exists( 'BizCity_AJAX_Image' ) ? 'BizCity_AJAX_Image' : null;

        for ( $i = 0; $i < $params['count']; $i++ ) {
            // Build a prompt that encodes the profile-studio context
            $prompt_parts = array();
            $prompt_parts[] = 'Face-swap / style-copy portrait';
            if ( ! empty( $params['tool'] ) )   $prompt_parts[] = 'tool:' . $params['tool'];
            if ( $params['face_lock'] )          $prompt_parts[] = 'face_lock:on';
            if ( ! empty( $params['prompt'] ) )  $prompt_parts[] = $params['prompt'];

            // Map to actual bztimg_jobs columns
            $job_data = array(
                'user_id'    => $params['user_id'],
                'prompt'     => implode( ' | ', $prompt_parts ),
                'model'      => 'profile-studio',
                'size'       => '1024x1024',
                'style'      => $params['tool'],          // sub-tool as style
                'ref_image'  => $params['source_url'],     // user face photo
                'status'     => 'pending',
                'image_url'  => $params['reference_url'],  // temporarily store ref in image_url
                'session_id' => 'profile-studio',
                'created_at' => current_time( 'mysql' ),
            );

            global $wpdb;
            $table = $wpdb->prefix . 'bztimg_jobs';
            $inserted = $wpdb->insert( $table, $job_data, array(
                '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            ) );
            $job_id = $inserted ? $wpdb->insert_id : 0;

            if ( $job_id ) {
                $results[] = array(
                    'job_id' => $job_id,
                    'status' => 'pending',
                );
            }
        }

        if ( empty( $results ) ) {
            return new \WP_Error( 'generation_failed', 'Không thể tạo job. Vui lòng thử lại.' );
        }

        /**
         * Fire action for async processing (cron or immediate via Gateway).
         * Other plugins/modules can hook in to process the jobs.
         */
        do_action( 'bztimg_profile_jobs_created', $results, $params );

        return array(
            'jobs'    => $results,
            'message' => sprintf( 'Đã tạo %d ảnh. Đang xử lý...', count( $results ) ),
        );
    }

    /* ═══════════ Process Jobs via PiAPI Faceswap ═══════════ */

    /**
     * Hooked to 'bztimg_profile_jobs_created'.
     * Submits each job to PiAPI faceswap API and updates status to 'processing'.
     *
     * @param array $results  Array of [ 'job_id' => int, 'status' => 'pending' ]
     * @param array $params   Original generation params (source_url, reference_url, tool, …)
     */
    public static function process_jobs( $results, $params ) {
        if ( empty( $results ) ) {
            return;
        }

        // Ensure PiAPI Proxy is available
        if ( ! class_exists( 'BizCity_PiAPI_Proxy' ) ) {
            $proxy_file = WP_PLUGIN_DIR . '/bizcity-llm-router/includes/class-piapi-proxy.php';
            if ( file_exists( $proxy_file ) ) {
                require_once $proxy_file;
            }
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_jobs';

        if ( ! class_exists( 'BizCity_PiAPI_Proxy' ) || ! BizCity_PiAPI_Proxy::is_ready() ) {
            // Gateway not configured — mark all jobs as failed
            foreach ( $results as $job ) {
                $wpdb->update(
                    $table,
                    array(
                        'status'        => 'failed',
                        'error_message' => 'PiAPI Gateway chưa được cấu hình. Liên hệ admin.',
                        'updated_at'    => current_time( 'mysql' ),
                    ),
                    array( 'id' => $job['job_id'] ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            }
            return;
        }

        foreach ( $results as $job ) {
            $job_row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, ref_image, image_url FROM {$table} WHERE id = %d",
                $job['job_id']
            ), ARRAY_A );

            if ( ! $job_row ) {
                continue;
            }

            $swap_image   = $job_row['ref_image'];   // user's face photo
            $target_image = $job_row['image_url'];    // style template / reference

            if ( empty( $swap_image ) || empty( $target_image ) ) {
                $wpdb->update(
                    $table,
                    array(
                        'status'        => 'failed',
                        'error_message' => 'Thiếu ảnh nguồn hoặc ảnh mẫu.',
                        'updated_at'    => current_time( 'mysql' ),
                    ),
                    array( 'id' => $job['job_id'] ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
                continue;
            }

            // 2026-05-06 — PiAPI face-swap rejects images with any side > 2048px
            // ("invalid request, swap image size 1920x2560 too large, maximum is
            // 2048x2048"). Downscale BOTH inputs defensively before submission.
            $swap_image   = self::downscale_for_piapi( $swap_image );
            $target_image = self::downscale_for_piapi( $target_image );

            // Call PiAPI faceswap
            $piapi_result = BizCity_PiAPI_Proxy::faceswap_async( $swap_image, $target_image );

            if ( $piapi_result['success'] ) {
                // Store task_id in chat_id column, update status to processing
                $wpdb->update(
                    $table,
                    array(
                        'status'     => 'processing',
                        'chat_id'    => sanitize_text_field( $piapi_result['task_id'] ),
                        'updated_at' => current_time( 'mysql' ),
                    ),
                    array( 'id' => $job['job_id'] ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            } else {
                $wpdb->update(
                    $table,
                    array(
                        'status'        => 'failed',
                        'error_message' => $piapi_result['error'] ?: 'PiAPI faceswap thất bại.',
                        'updated_at'    => current_time( 'mysql' ),
                    ),
                    array( 'id' => $job['job_id'] ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );
            }
        }
    }

    /* ═══════════ Helper: Downscale images for PiAPI (max 2048×2048) ═══════════ */

    /**
     * Ensure the given image URL fits within PiAPI's 2048×2048 face-swap limit.
     * If the image already fits, the original URL is returned unchanged.
     * Otherwise a downscaled JPEG (long edge = 2048) is rendered into the WP
     * uploads dir and a public URL to that file is returned.
     *
     * @param string $url Public image URL (local upload OR remote).
     * @return string Possibly-rewritten URL safe for PiAPI submission.
     */
    public static function downscale_for_piapi( string $url ): string {
        if ( empty( $url ) ) {
            return $url;
        }

        $max = 2048;

        // Resolve to a local file path: prefer attached files; fall back to download.
        $path        = '';
        $cleanup_tmp = false;

        $attach_id = attachment_url_to_postid( $url );
        if ( $attach_id ) {
            $maybe = get_attached_file( $attach_id );
            if ( $maybe && file_exists( $maybe ) ) {
                $path = $maybe;
            }
        }

        if ( empty( $path ) ) {
            // Remote (or unattached) URL — download to a tmp file.
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $tmp = download_url( $url, 30 );
            if ( is_wp_error( $tmp ) ) {
                return $url; // Fail open — let PiAPI surface its own error.
            }
            $path        = $tmp;
            $cleanup_tmp = true;
        }

        // Probe dimensions.
        $info = @getimagesize( $path );
        if ( ! $info || empty( $info[0] ) || empty( $info[1] ) ) {
            if ( $cleanup_tmp ) @unlink( $path );
            return $url;
        }
        list( $w, $h ) = $info;
        if ( $w <= $max && $h <= $max ) {
            if ( $cleanup_tmp ) @unlink( $path );
            return $url;
        }

        // Resize via WP image editor (preserves aspect ratio).
        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            if ( $cleanup_tmp ) @unlink( $path );
            return $url;
        }
        $resized = $editor->resize( $max, $max, false );
        if ( is_wp_error( $resized ) ) {
            if ( $cleanup_tmp ) @unlink( $path );
            return $url;
        }

        // Save next to the original (or in uploads if remote) as JPEG.
        $uploads = wp_upload_dir();
        $ext     = 'jpg';
        $base    = wp_basename( $path );
        $base    = preg_replace( '/\.[A-Za-z0-9]+$/', '', $base );
        $base    = sanitize_file_name( $base );
        $fname   = $base . '-piapi2k.' . $ext;
        $out_dir = trailingslashit( $uploads['path'] );
        $out_url_base = trailingslashit( $uploads['url'] );

        // Avoid filename collisions.
        $i = 0;
        $candidate = $fname;
        while ( file_exists( $out_dir . $candidate ) ) {
            $i++;
            $candidate = $base . '-piapi2k-' . $i . '.' . $ext;
        }
        $saved = $editor->save( $out_dir . $candidate, 'image/jpeg' );
        if ( $cleanup_tmp ) @unlink( $path );

        if ( is_wp_error( $saved ) || empty( $saved['file'] ) ) {
            return $url;
        }

        return $out_url_base . wp_basename( $saved['file'] );
    }

    /* ═══════════ DB: Create profile templates table ═══════════ */

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $table   = $wpdb->prefix . 'bztimg_profile_templates';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL DEFAULT '',
            thumbnail_url text NOT NULL,
            reference_url text NOT NULL,
            category varchar(50) NOT NULL DEFAULT 'all',
            style_prompt text,
            sort_order int(11) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_category_status (category, status),
            KEY idx_sort (sort_order)
        ) {$charset};";

        dbDelta( $sql );
        self::invalidate_table_exists_cache( $table );
    }
}
