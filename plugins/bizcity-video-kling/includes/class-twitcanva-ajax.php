<?php
/**
 * TwitCanva Video Workflow — WP AJAX Handlers
 *
 * Replaces TwitCanva Express server.
 * Frontend React SPA calls admin-ajax.php → this class → existing PHP libs.
 *
 * Actions:
 *   bvk_tc_generate_image     — Image gen (Gemini/Kling/OpenAI via LLM + PiAPI)
 *   bvk_tc_generate_video     — Video gen (Kling/Veo via kling_api.php)
 *   bvk_tc_generation_status  — Check if node generation result exists
 *   bvk_tc_save_workflow      — Save/update workflow JSON
 *   bvk_tc_list_workflows     — List user workflows
 *   bvk_tc_load_workflow      — Load single workflow
 *   bvk_tc_delete_workflow    — Delete workflow
 *   bvk_tc_update_workflow_cover — Update workflow cover image
 *   bvk_tc_upload_asset       — Upload base64 image/video → WP Media
 *   bvk_tc_list_assets        — List generated assets
 *   bvk_tc_delete_asset       — Delete asset
 *   bvk_tc_list_library       — List curated library assets
 *   bvk_tc_save_library_asset — Save asset to library
 *   bvk_tc_delete_library_asset — Delete library asset
 *   bvk_tc_generate_scripts   — Storyboard script gen (Gemini)
 *   bvk_tc_brainstorm_story   — AI story brainstorm (Gemini)
 *   bvk_tc_optimize_story     — Optimise story text (Gemini)
 *   bvk_tc_generate_composite — Generate composite image (Gemini)
 *   bvk_tc_describe_image     — Describe image (Gemini vision)
 *   bvk_tc_optimize_prompt    — Optimise prompt text (Gemini)
 *   bvk_tc_trim_video         — Trim video via FFmpeg
 *
 * @package BizCity_Video_Kling
 * @since   2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_TwitCanva_Ajax {

    /* ─── WP Option key for workflows ─── */
    const WF_OPTION  = 'bzvideo_tc_workflows';
    const LIB_OPTION = 'bzvideo_tc_library';

    /* ─── Register all AJAX hooks ─── */
    public static function init() {
        $actions = [
            'tc_generate_image',
            'tc_generate_video',
            'tc_generation_status',
            'tc_save_workflow',
            'tc_list_workflows',
            'tc_load_workflow',
            'tc_delete_workflow',
            'tc_update_workflow_cover',
            'tc_upload_asset',
            'tc_list_assets',
            'tc_delete_asset',
            'tc_list_library',
            'tc_save_library_asset',
            'tc_delete_library_asset',
            'tc_generate_scripts',
            'tc_brainstorm_story',
            'tc_optimize_story',
            'tc_generate_composite',
            'tc_describe_image',
            'tc_optimize_prompt',
            'tc_trim_video',
            'tc_compose_video',
            'tc_faceswap',
            'tc_faceswap_status',
            'tc_tts',
            'tc_upload_file',
            'tc_browse_media',
            'tc_save_url_to_media',
        ];

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_bvk_' . $action, [ __CLASS__, 'handle_' . $action ] );
        }
    }

    /* ─── Helpers ─── */

    private static function verify() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Đăng nhập để tiếp tục.' ] );
        }
    }

    private static function payload(): array {
        $raw = wp_unslash( $_POST['payload'] ?? '{}' );
        return json_decode( $raw, true ) ?: [];
    }

    /**
     * Get LLM Client singleton.
     */
    private static function llm(): BizCity_LLM_Client {
        return BizCity_LLM_Client::instance();
    }

    /**
     * Call Gemini via LLM Client for text/vision tasks.
     * Uses OpenAI-compatible chat format.
     */
    private static function gemini_chat( array $messages, array $opts = [] ): array {
        $defaults = [
            'model'   => 'google/gemini-2.0-flash-001',
            'purpose' => 'vision',
        ];
        return self::llm()->chat( $messages, array_merge( $defaults, $opts ) );
    }

    /**
     * Convert WP upload URL to public media CDN URL.
     * bizcity.vn/wp-content/uploads/... → media.bizcity.vn/uploads/...
     */
    private static function to_media_url( string $url ): string {
        $site_url = home_url();
        $parsed   = wp_parse_url( $site_url );
        $host     = $parsed['host'] ?? '';
        if ( $host && str_contains( $url, $host . '/wp-content/uploads/' ) ) {
            $url = str_replace(
                $host . '/wp-content/uploads/',
                'media.' . $host . '/uploads/',
                $url
            );
        }
        return $url;
    }

    /**
     * Check if a URL is a local (own domain) URL.
     */
    private static function is_local_url( string $url ): bool {
        $site_host  = wp_parse_url( home_url(), PHP_URL_HOST ) ?: '';
        $media_host = 'media.' . $site_host;
        $url_host   = wp_parse_url( $url, PHP_URL_HOST ) ?: '';
        return $url_host === $site_host || $url_host === $media_host;
    }

    /**
     * Download an external URL to WP Media Library.
     * Returns local media URL on success, original URL on failure.
     *
     * @param string $url       External URL to download.
     * @param string $filename  Desired filename (optional).
     * @return string           Local media URL or original URL.
     */
    private static function download_external_to_media( string $url, string $filename = '' ): string {
        if ( empty( $url ) || self::is_local_url( $url ) ) {
            return $url;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Download to temp file (30s timeout, 100MB max)
        $tmp = download_url( $url, 30 );
        if ( is_wp_error( $tmp ) ) {
            error_log( '[TwitCanva] Failed to download external URL: ' . $url . ' — ' . $tmp->get_error_message() );
            return $url; // Fallback: return original URL
        }

        if ( empty( $filename ) ) {
            $ext      = pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?: '', PATHINFO_EXTENSION ) ?: 'mp4';
            $filename = 'tc_ext_' . time() . '_' . wp_generate_password( 4, false ) . '.' . sanitize_file_name( $ext );
        }

        $file_array = [
            'name'     => sanitize_file_name( $filename ),
            'tmp_name' => $tmp,
        ];

        $attach_id = media_handle_sideload( $file_array, 0 );
        if ( is_wp_error( $attach_id ) ) {
            @unlink( $tmp );
            error_log( '[TwitCanva] Failed to sideload: ' . $attach_id->get_error_message() );
            return $url;
        }

        $local_url = wp_get_attachment_url( $attach_id );
        if ( ! $local_url ) {
            return $url;
        }

        return self::to_media_url( $local_url );
    }

    /**
     * Upload base64 data to WP Media Library.
     *
     * @param string $base64_data  Full data URL (data:image/png;base64,...)
     * @param string $filename     Desired filename
     * @return array { attachment_id, url } or WP_Error
     */
    private static function upload_base64_to_media( string $base64_data, string $filename = '' ): array {
        // Parse data URL
        if ( ! preg_match( '#^data:([^;]+);base64,(.+)$#s', $base64_data, $m ) ) {
            return [ 'error' => 'Invalid base64 data URL' ];
        }

        $mime = $m[1];
        $data = base64_decode( $m[2] );

        if ( empty( $filename ) ) {
            $ext = 'png';
            if ( str_contains( $mime, 'jpeg' ) || str_contains( $mime, 'jpg' ) ) $ext = 'jpg';
            elseif ( str_contains( $mime, 'webp' ) ) $ext = 'webp';
            elseif ( str_contains( $mime, 'mp4' ) ) $ext = 'mp4';
            elseif ( str_contains( $mime, 'webm' ) ) $ext = 'webm';
            $filename = 'tc_' . time() . '_' . wp_generate_password( 6, false ) . '.' . $ext;
        }

        $upload = wp_upload_bits( $filename, null, $data );

        if ( ! empty( $upload['error'] ) ) {
            return [ 'error' => $upload['error'] ];
        }

        // Insert into Media Library
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_id = wp_insert_attachment( [
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
            'post_status'    => 'inherit',
        ], $upload['file'] );

        if ( is_wp_error( $attach_id ) ) {
            return [ 'error' => $attach_id->get_error_message() ];
        }

        wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

        return [
            'attachment_id' => $attach_id,
            'url'           => self::to_media_url( $upload['url'] ),
        ];
    }

    /* ════════════════════════════════════════════════════════════
     *  IMAGE GENERATION
     *  Route: Gemini (default) → via LLM Client
     *         Kling image → via PiAPI (todo: add later)
     *         OpenAI GPT Image → via LLM Client
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_generate_image() {
        self::verify();
        $p = self::payload();

        $prompt     = sanitize_textarea_field( $p['prompt'] ?? '' );
        $model      = sanitize_text_field( $p['imageModel'] ?? 'gemini-pro' );
        $aspect     = sanitize_text_field( $p['aspectRatio'] ?? '1:1' );
        $resolution = sanitize_text_field( $p['resolution'] ?? '1K' );
        $node_id    = sanitize_text_field( $p['nodeId'] ?? '' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
        }

        // For Gemini / OpenAI models → use bizcity-tool-image's generate_image
        // which routes through the LLM gateway (supports gemini-image, gemini-pro, flux-*, gpt-image-*)
        if ( ! class_exists( 'BizCity_Tool_Image' ) ) {
            $tool_image_dir = dirname( BIZCITY_VIDEO_KLING_DIR ) . '/bizcity-tool-image/';
            if ( file_exists( $tool_image_dir . 'includes/class-tools-image.php' ) ) {
                require_once $tool_image_dir . 'includes/class-tools-image.php';
            }
        }

        // Map TwitCanva model names → bizcity-tool-image model names
        $model_map = [
            'gemini-pro'     => 'gemini-pro',
            'gpt-image-1.5'  => 'gpt-image',
            'gpt-image-1'    => 'gpt-image',
        ];
        $wp_model = $model_map[ $model ] ?? $model;

        // Resolve size from aspect ratio
        $size_map = [
            '1:1'  => '1024x1024',
            '3:4'  => '768x1024',
            '4:3'  => '1024x768',
            '9:16' => '576x1024',
            '16:9' => '1024x576',
        ];
        $size = $size_map[ $aspect ] ?? '1024x1024';

        // Handle reference images (base64 → upload to media first)
        $image_url  = '';
        $image_urls = [];
        $image_data = $p['imageBase64'] ?? null;
        if ( $image_data ) {
            $items = is_array( $image_data ) ? $image_data : [ $image_data ];
            foreach ( $items as $item ) {
                if ( str_starts_with( $item, 'data:' ) ) {
                    $uploaded = self::upload_base64_to_media( $item, 'tc_ref_' . time() . '_' . wp_generate_password( 4, false ) . '.png' );
                    if ( ! empty( $uploaded['url'] ) ) {
                        $image_urls[] = $uploaded['url'];
                    }
                } else {
                    $image_urls[] = $item;
                }
            }
            $image_url = $image_urls[0] ?? '';
        }

        if ( class_exists( 'BizCity_Tool_Image' ) ) {
            $gen_params = [
                'prompt'        => $prompt,
                'image_url'     => $image_url,
                'model'         => $wp_model,
                'size'          => $size,
                'style'         => 'auto',
                'user_id'       => get_current_user_id(),
                'creation_mode' => ! empty( $image_url ) ? 'reference' : 'text',
            ];
            // Pass additional reference images if available (Gemini multi-image)
            if ( count( $image_urls ) > 1 ) {
                $gen_params['extra_image_urls'] = array_slice( $image_urls, 1 );
            }

            $result = BizCity_Tool_Image::generate_image( $gen_params );

            if ( ! empty( $result['success'] ) && ! empty( $result['data']['image_url'] ) ) {
                wp_send_json_success( [ 'resultUrl' => $result['data']['image_url'] ] );
            }
            if ( ! empty( $result['success'] ) && ! empty( $result['data']['url'] ) ) {
                wp_send_json_success( [ 'resultUrl' => $result['data']['url'] ] );
            }
            wp_send_json_error( [ 'message' => $result['message'] ?? 'Image generation failed.' ] );
        }

        // Fallback: direct Gemini call via LLM
        $messages = [
            [ 'role' => 'user', 'content' => "Generate an image: {$prompt}" ],
        ];
        $result = self::gemini_chat( $messages, [ 'model' => 'google/gemini-2.0-flash-001' ] );

        if ( $result['success'] ) {
            // LLM returns text — for actual image gen we need the image tool
            wp_send_json_error( [ 'message' => 'Image model not available. Install bizcity-tool-image plugin.' ] );
        }

        wp_send_json_error( [ 'message' => $result['error'] ?? 'Generation failed.' ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  VIDEO GENERATION — Uses existing kling_api.php
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_generate_video() {
        self::verify();
        $p = self::payload();

        $prompt      = sanitize_textarea_field( $p['prompt'] ?? '' );
        $video_model = sanitize_text_field( $p['videoModel'] ?? 'kling-v2-1' );
        $duration    = max( 5, min( 30, intval( $p['duration'] ?? 5 ) ) );
        $aspect      = sanitize_text_field( $p['aspectRatio'] ?? '16:9' );
        $node_id     = sanitize_text_field( $p['nodeId'] ?? '' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
        }

        // Handle start frame image
        $image_url = '';
        $image_b64 = $p['imageBase64'] ?? '';
        if ( ! empty( $image_b64 ) && str_starts_with( $image_b64, 'data:' ) ) {
            $uploaded = self::upload_base64_to_media( $image_b64, 'tc_frame_' . time() . '.png' );
            $image_url = $uploaded['url'] ?? '';
        } elseif ( ! empty( $image_b64 ) ) {
            $image_url = $image_b64;
        }

        // Handle motion reference video (Kling 2.6 motion control)
        $motion_ref_url = '';
        $motion_raw = $p['motionReferenceUrl'] ?? '';
        if ( ! empty( $motion_raw ) && str_starts_with( $motion_raw, 'data:' ) ) {
            $uploaded_motion = self::upload_base64_to_media( $motion_raw, 'tc_motion_' . time() . '.mp4' );
            $motion_ref_url = $uploaded_motion['url'] ?? '';
        } elseif ( ! empty( $motion_raw ) ) {
            // Auto-fetch external URLs to WP Media to avoid PiAPI download failures
            $motion_ref_url = self::download_external_to_media( $motion_raw, 'tc_motion_' . time() . '.mp4' );
        }

        // Map TwitCanva model → PHP model format
        $model_map = [
            'kling-v2-1'        => '2.1|pro',
            'kling-v2-1-master' => '2.1|pro',
            'kling-v2'          => '2.1|pro',
            'kling-v2-5-turbo'  => '2.5|pro',
            'kling-v2-6'        => '2.6|pro',
            'veo-3.1'           => 'veo:3',
            'hailuo-2.3'        => 'hailuo:2.3',
            'hailuo-2.3-fast'   => 'hailuo:2.3-fast',
            'hailuo-02'         => 'hailuo:02',
        ];
        $php_model = $model_map[ $video_model ] ?? '2.6|pro';

        // Use the existing create_video tool
        if ( ! class_exists( 'BizCity_Tool_Kling' ) ) {
            require_once BIZCITY_VIDEO_KLING_DIR . 'includes/class-tools-kling.php';
        }

        $create_params = [
            'prompt'       => $prompt,
            'image_url'    => $image_url,
            'duration'     => min( $duration, 10 ), // Kling max 10s per segment
            'aspect_ratio' => $aspect,
            'model'        => $php_model,
            'user_id'      => get_current_user_id(),
            '_meta'        => [ 'source' => 'twitcanva', 'node_id' => $node_id ],
        ];
        if ( ! empty( $motion_ref_url ) ) {
            $create_params['motion_reference_url'] = $motion_ref_url;
        }
        if ( ! empty( $p['generateAudio'] ) ) {
            $create_params['with_audio'] = true;
        }

        $result = BizCity_Tool_Kling::create_video( $create_params );

        if ( ! empty( $result['success'] ) ) {
            // Video is async — return job info for polling
            $job_id  = $result['data']['job_id'] ?? '';
            $task_id = $result['data']['task_id'] ?? '';

            wp_send_json_success( [
                'resultUrl' => '', // Will be filled by polling
                'jobId'     => $job_id,
                'taskId'    => $task_id,
                'status'    => 'processing',
                'message'   => $result['message'] ?? 'Video đang được tạo...',
            ] );
        }

        wp_send_json_error( [ 'message' => $result['message'] ?? 'Video generation failed.' ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  GENERATION STATUS — Check if completed asset exists
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_generation_status() {
        self::verify();
        $p       = self::payload();
        $node_id = sanitize_text_field( $p['nodeId'] ?? '' );

        if ( empty( $node_id ) ) {
            wp_send_json_success( [ 'found' => false ] );
        }

        // Look up job by node_id in metadata
        global $wpdb;
        $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        $has_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;

        if ( ! $has_table ) {
            wp_send_json_success( [ 'found' => false ] );
        }

        // First: search for the specific node_id in job metadata
        $job = $wpdb->get_row( $wpdb->prepare(
            "SELECT video_url, media_url, status, created_at FROM {$jobs_table}
             WHERE created_by = %d
             AND status = 'completed'
             AND (video_url IS NOT NULL OR media_url IS NOT NULL)
             AND metadata LIKE %s
             ORDER BY updated_at DESC LIMIT 1",
            get_current_user_id(),
            '%' . $wpdb->esc_like( $node_id ) . '%'
        ), ARRAY_A );

        // Fallback: if no job found with node_id, try latest completed job for this user
        if ( ! $job ) {
            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT video_url, media_url, status, created_at FROM {$jobs_table}
                 WHERE created_by = %d AND status = 'completed' AND (video_url IS NOT NULL OR media_url IS NOT NULL)
                 ORDER BY updated_at DESC LIMIT 1",
                get_current_user_id()
            ), ARRAY_A );
        }

        if ( $job && ( $job['video_url'] || $job['media_url'] ) ) {
            $result_url = $job['media_url'] ?: $job['video_url'];

            // Auto-fetch: if media_url is empty and video_url is external, download to WP Media
            if ( empty( $job['media_url'] ) && ! empty( $job['video_url'] ) && ! self::is_local_url( $job['video_url'] ) ) {
                $local_url = self::download_external_to_media( $job['video_url'] );
                if ( $local_url !== $job['video_url'] ) {
                    $result_url = $local_url;
                    // Update job record so future polls return the local URL
                    $wpdb->update(
                        $jobs_table,
                        [ 'media_url' => $local_url ],
                        [ 'created_by' => get_current_user_id(), 'video_url' => $job['video_url'] ],
                        [ '%s' ],
                        [ '%d', '%s' ]
                    );
                }
            }

            wp_send_json_success( [
                'found'     => true,
                'resultUrl' => $result_url,
                'type'      => 'video',
                'createdAt' => $job['created_at'],
            ] );
        }

        wp_send_json_success( [ 'found' => false ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  WORKFLOW CRUD — Stored in wp_options as JSON
     * ════════════════════════════════════════════════════════════ */

    private static function get_workflows(): array {
        $user_id = get_current_user_id();
        return get_user_meta( $user_id, self::WF_OPTION, true ) ?: [];
    }

    private static function save_workflows( array $workflows ) {
        update_user_meta( get_current_user_id(), self::WF_OPTION, $workflows );
    }

    /**
     * Get the directory containing bundled public workflow templates.
     */
    private static function get_public_workflows_dir(): string {
        return BIZCITY_VIDEO_KLING_DIR . 'twitcanva-dist/workflows/';
    }

    /**
     * List all public workflow templates (shipped with plugin).
     */
    private static function list_public_workflows(): array {
        $dir = self::get_public_workflows_dir();
        if ( ! is_dir( $dir ) ) {
            return [];
        }

        $files = glob( $dir . '*.json' );
        if ( ! $files ) {
            return [];
        }

        $list = [];
        foreach ( $files as $file ) {
            $content = file_get_contents( $file );
            $wf      = json_decode( $content, true );
            if ( ! $wf || empty( $wf['title'] ) ) continue;

            // Build description from node types
            $node_types = [];
            foreach ( $wf['nodes'] ?? [] as $n ) {
                $t = $n['type'] ?? 'Unknown';
                $node_types[ $t ] = ( $node_types[ $t ] ?? 0 ) + 1;
            }
            $summary = implode( ', ', array_map(
                fn( $type, $count ) => $count . ' ' . $type . ( $count > 1 ? 's' : '' ),
                array_keys( $node_types ),
                array_values( $node_types )
            ) );

            $list[] = [
                'id'          => pathinfo( $file, PATHINFO_FILENAME ),
                'title'       => $wf['title'],
                'description' => $wf['description'] ?? ( $summary ? "Workflow with {$summary}" : 'A template workflow' ),
                'nodeCount'   => count( $wf['nodes'] ?? [] ),
                'coverUrl'    => $wf['coverUrl'] ?? '',
                'createdAt'   => $wf['createdAt'] ?? '',
                'updatedAt'   => $wf['updatedAt'] ?? '',
            ];
        }

        usort( $list, fn( $a, $b ) => strcmp( $a['title'], $b['title'] ) );
        return $list;
    }

    /**
     * Load a single public workflow template by file ID.
     */
    private static function load_public_workflow( string $id ): ?array {
        if ( empty( $id ) || preg_match( '/[\/\\\\]/', $id ) ) {
            return null; // Prevent path traversal
        }

        $file = self::get_public_workflows_dir() . $id . '.json';
        if ( ! file_exists( $file ) ) {
            return null;
        }

        $content = file_get_contents( $file );
        $wf      = json_decode( $content, true );
        return is_array( $wf ) ? $wf : null;
    }

    public static function handle_tc_save_workflow() {
        self::verify();
        $p = self::payload();

        $id   = sanitize_text_field( $p['id'] ?? '' );
        $title = sanitize_text_field( $p['title'] ?? 'Untitled' );
        $nodes = $p['nodes'] ?? [];
        $groups = $p['groups'] ?? [];
        $viewport = $p['viewport'] ?? null;

        if ( empty( $id ) ) {
            $id = wp_generate_uuid4();
        }

        $workflows = self::get_workflows();

        // Sanitise base64 from nodes to reduce storage size
        $nodes = self::sanitize_workflow_nodes( $nodes );

        $workflows[ $id ] = [
            'id'        => $id,
            'title'     => $title,
            'nodes'     => $nodes,
            'groups'    => $groups,
            'viewport'  => $viewport,
            'createdAt' => $workflows[ $id ]['createdAt'] ?? gmdate( 'c' ),
            'updatedAt' => gmdate( 'c' ),
            'coverUrl'  => $workflows[ $id ]['coverUrl'] ?? '',
            'nodeCount' => count( $nodes ),
        ];

        self::save_workflows( $workflows );

        wp_send_json_success( [ 'id' => $id ] );
    }

    /**
     * Strip large base64 data from workflow nodes to avoid bloating storage.
     */
    private static function sanitize_workflow_nodes( array $nodes ): array {
        foreach ( $nodes as &$node ) {
            // Convert base64 result images to media URLs
            if ( ! empty( $node['resultUrl'] ) && str_starts_with( $node['resultUrl'], 'data:' ) ) {
                $uploaded = self::upload_base64_to_media( $node['resultUrl'] );
                if ( ! empty( $uploaded['url'] ) ) {
                    $node['resultUrl'] = $uploaded['url'];
                }
            }
            if ( ! empty( $node['lastFrame'] ) && str_starts_with( $node['lastFrame'], 'data:' ) ) {
                $uploaded = self::upload_base64_to_media( $node['lastFrame'] );
                if ( ! empty( $uploaded['url'] ) ) {
                    $node['lastFrame'] = $uploaded['url'];
                }
            }
            // Remove editor canvas data to save space
            if ( ! empty( $node['editorCanvasData'] ) && str_starts_with( $node['editorCanvasData'], 'data:' ) ) {
                $uploaded = self::upload_base64_to_media( $node['editorCanvasData'] );
                if ( ! empty( $uploaded['url'] ) ) {
                    $node['editorCanvasData'] = $uploaded['url'];
                }
            }
        }
        return $nodes;
    }

    public static function handle_tc_list_workflows() {
        self::verify();
        $p = self::payload();

        // Public template workflows (shipped with the plugin)
        if ( ! empty( $p['publicOnly'] ) ) {
            wp_send_json_success( self::list_public_workflows() );
        }

        $workflows = self::get_workflows();

        // Return summaries (without full node data)
        $list = [];
        foreach ( $workflows as $wf ) {
            $list[] = [
                'id'        => $wf['id'],
                'title'     => $wf['title'],
                'createdAt' => $wf['createdAt'] ?? '',
                'updatedAt' => $wf['updatedAt'] ?? '',
                'coverUrl'  => $wf['coverUrl'] ?? '',
                'nodeCount' => $wf['nodeCount'] ?? count( $wf['nodes'] ?? [] ),
            ];
        }

        // Sort by updatedAt descending
        usort( $list, fn( $a, $b ) => strcmp( $b['updatedAt'], $a['updatedAt'] ) );

        wp_send_json_success( $list );
    }

    public static function handle_tc_load_workflow() {
        self::verify();
        $p  = self::payload();
        $id = sanitize_text_field( $p['id'] ?? '' );

        // Load public template workflow
        if ( ! empty( $p['publicOnly'] ) ) {
            $wf = self::load_public_workflow( $id );
            if ( ! $wf ) {
                wp_send_json_error( [ 'message' => 'Public workflow not found.' ] );
            }
            wp_send_json_success( $wf );
        }

        $workflows = self::get_workflows();

        if ( ! isset( $workflows[ $id ] ) ) {
            wp_send_json_error( [ 'message' => 'Workflow not found.' ] );
        }

        wp_send_json_success( $workflows[ $id ] );
    }

    public static function handle_tc_delete_workflow() {
        self::verify();
        $p  = self::payload();
        $id = sanitize_text_field( $p['id'] ?? '' );

        $workflows = self::get_workflows();
        unset( $workflows[ $id ] );
        self::save_workflows( $workflows );

        wp_send_json_success( [ 'ok' => true ] );
    }

    public static function handle_tc_update_workflow_cover() {
        self::verify();
        $p        = self::payload();
        $id       = sanitize_text_field( $p['id'] ?? '' );
        $cover_url = esc_url_raw( $p['coverUrl'] ?? '' );

        $workflows = self::get_workflows();
        if ( isset( $workflows[ $id ] ) ) {
            $workflows[ $id ]['coverUrl'] = $cover_url;
            self::save_workflows( $workflows );
        }

        wp_send_json_success( [ 'ok' => true ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  ASSET UPLOAD — Base64 → WP Media Library
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_upload_asset() {
        self::verify();
        $p = self::payload();

        $data_url = $p['data'] ?? '';
        $type     = sanitize_text_field( $p['type'] ?? 'image' );
        $prompt   = sanitize_textarea_field( $p['prompt'] ?? '' );

        if ( empty( $data_url ) || ! str_starts_with( $data_url, 'data:' ) ) {
            // Already a URL
            wp_send_json_success( [ 'url' => $data_url ] );
        }

        $ext  = $type === 'video' ? 'mp4' : 'png';
        $name = 'tc_' . $type . '_' . time() . '.' . $ext;

        $result = self::upload_base64_to_media( $data_url, $name );

        if ( ! empty( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        // Save metadata as post_excerpt
        if ( $prompt && $result['attachment_id'] ) {
            wp_update_post( [
                'ID'           => $result['attachment_id'],
                'post_excerpt' => $prompt,
            ] );
        }

        wp_send_json_success( [ 'url' => $result['url'] ] );
    }

    public static function handle_tc_list_assets() {
        self::verify();
        $p     = self::payload();
        $type  = sanitize_text_field( $p['type'] ?? 'images' );
        $limit = max( 1, min( 100, intval( $p['limit'] ?? 50 ) ) );

        $mime = $type === 'videos' ? 'video' : 'image';

        $attachments = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => $mime,
            'post_status'    => 'inherit',
            'author'         => get_current_user_id(),
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [
                    'key'     => '_wp_attached_file',
                    'value'   => 'tc_',
                    'compare' => 'LIKE',
                ],
            ],
        ] );

        $items = array_map( fn( $att ) => [
            'id'       => (string) $att->ID,
            'url'      => wp_get_attachment_url( $att->ID ),
            'filename' => basename( get_attached_file( $att->ID ) ),
            'prompt'   => $att->post_excerpt,
            'createdAt' => $att->post_date_gmt,
            'type'     => $type,
        ], $attachments );

        wp_send_json_success( $items );
    }

    public static function handle_tc_delete_asset() {
        self::verify();
        $p  = self::payload();
        $id = intval( $p['id'] ?? 0 );

        if ( $id > 0 ) {
            $att = get_post( $id );
            if ( $att && (int) $att->post_author === get_current_user_id() ) {
                wp_delete_attachment( $id, true );
            }
        }

        wp_send_json_success( [ 'ok' => true ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  FILE UPLOAD — Direct File → WP Media Library (FormData)
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_upload_file() {
        check_ajax_referer( 'bvk_nonce', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Đăng nhập để tiếp tục.' ] );
        }

        if ( empty( $_FILES['file'] ) ) {
            wp_send_json_error( [ 'message' => 'No file uploaded.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $file = $_FILES['file'];

        // Validate mime type (images + videos only)
        $allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/webm', 'video/quicktime' ];
        if ( ! in_array( $file['type'], $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'File type not allowed: ' . $file['type'] ] );
        }

        // Prefix filename for easy identification
        $original = sanitize_file_name( $file['name'] );
        $file['name'] = 'tc_' . time() . '_' . $original;

        // Use WP media_handle_sideload for proper Media Library integration
        $upload = wp_handle_upload( $file, [ 'test_form' => false ] );

        if ( ! empty( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => $upload['error'] ] );
        }

        $attach_id = wp_insert_attachment( [
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name( pathinfo( $original, PATHINFO_FILENAME ) ),
            'post_status'    => 'inherit',
        ], $upload['file'] );

        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( [ 'message' => $attach_id->get_error_message() ] );
        }

        wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

        wp_send_json_success( [
            'url'          => $upload['url'],
            'attachmentId' => $attach_id,
        ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  BROWSE WP MEDIA — List all user's media (images + videos)
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_browse_media() {
        self::verify();
        $p = self::payload();

        $type   = sanitize_text_field( $p['type'] ?? 'all' );
        $limit  = max( 1, min( 100, intval( $p['limit'] ?? 50 ) ) );
        $offset = max( 0, intval( $p['offset'] ?? 0 ) );
        $search = sanitize_text_field( $p['search'] ?? '' );

        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'author'         => get_current_user_id(),
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ];

        // Filter by mime type
        if ( $type === 'image' ) {
            $args['post_mime_type'] = 'image';
        } elseif ( $type === 'video' ) {
            $args['post_mime_type'] = 'video';
        } else {
            $args['post_mime_type'] = [ 'image', 'video' ];
        }

        if ( $search ) {
            $args['s'] = $search;
        }

        $attachments = get_posts( $args );

        $items = array_map( function( $att ) {
            $url  = wp_get_attachment_url( $att->ID );
            $mime = $att->post_mime_type;
            $thumb = '';
            if ( str_starts_with( $mime, 'image' ) ) {
                $sizes = wp_get_attachment_image_src( $att->ID, 'thumbnail' );
                $thumb = $sizes ? $sizes[0] : $url;
            }
            return [
                'id'        => $att->ID,
                'url'       => $url,
                'filename'  => basename( get_attached_file( $att->ID ) ),
                'title'     => $att->post_title,
                'mime'      => $mime,
                'date'      => $att->post_date_gmt,
                'thumbnail' => $thumb,
            ];
        }, $attachments );

        wp_send_json_success( $items );
    }

    /**
     * Download an external URL (e.g. PiAPI temp URL) to WP Media Library.
     * Returns the local media URL + attachment ID.
     */
    public static function handle_tc_save_url_to_media() {
        self::verify();
        // Read directly from POST fields (not payload JSON)
        $url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );

        if ( empty( $url ) ) {
            wp_send_json_error( [ 'message' => 'Missing URL' ] );
        }

        if ( self::is_local_url( $url ) ) {
            wp_send_json_success( [ 'url' => $url, 'attachmentId' => 0, 'alreadyLocal' => true ] );
        }

        $local_url = self::download_external_to_media( $url );

        if ( $local_url === $url ) {
            wp_send_json_error( [ 'message' => 'Failed to download to media library' ] );
        }

        // Optionally update the jobs table if nodeId is provided
        $node_id = sanitize_text_field( wp_unslash( $_POST['nodeId'] ?? '' ) );
        if ( ! empty( $node_id ) ) {
            global $wpdb;
            $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
            $has_table  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $jobs_table ) ) === $jobs_table;
            if ( $has_table ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$jobs_table} SET media_url = %s WHERE created_by = %d AND status = 'completed' AND metadata LIKE %s AND (media_url IS NULL OR media_url = '')",
                    $local_url,
                    get_current_user_id(),
                    '%' . $wpdb->esc_like( $node_id ) . '%'
                ) );
            }
        }

        wp_send_json_success( [ 'url' => $local_url, 'attachmentId' => 0 ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  LIBRARY (curated assets) — Stored in user meta
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_list_library() {
        self::verify();
        $library = get_user_meta( get_current_user_id(), self::LIB_OPTION, true ) ?: [];
        wp_send_json_success( array_values( $library ) );
    }

    public static function handle_tc_save_library_asset() {
        self::verify();
        $p = self::payload();

        $source_url = $p['sourceUrl'] ?? '';
        $name       = sanitize_text_field( $p['name'] ?? 'Asset' );
        $category   = sanitize_text_field( $p['category'] ?? 'Others' );
        $meta       = $p['meta'] ?? [];

        // If source is base64, upload first
        $url = $source_url;
        if ( str_starts_with( $source_url, 'data:' ) ) {
            $uploaded = self::upload_base64_to_media( $source_url, 'tc_lib_' . time() . '.png' );
            $url = $uploaded['url'] ?? $source_url;
        }

        $id = 'lib_' . time() . '_' . wp_generate_password( 6, false );

        $library = get_user_meta( get_current_user_id(), self::LIB_OPTION, true ) ?: [];
        $library[ $id ] = [
            'id'        => $id,
            'name'      => $name,
            'url'       => $url,
            'category'  => $category,
            'type'      => str_contains( $url, '.mp4' ) ? 'video' : 'image',
            'createdAt' => gmdate( 'c' ),
            'metadata'  => $meta,
        ];
        update_user_meta( get_current_user_id(), self::LIB_OPTION, $library );

        wp_send_json_success( $library[ $id ] );
    }

    public static function handle_tc_delete_library_asset() {
        self::verify();
        $p  = self::payload();
        $id = sanitize_text_field( $p['id'] ?? '' );

        $library = get_user_meta( get_current_user_id(), self::LIB_OPTION, true ) ?: [];
        unset( $library[ $id ] );
        update_user_meta( get_current_user_id(), self::LIB_OPTION, $library );

        wp_send_json_success( [ 'ok' => true ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  STORYBOARD — Generate scripts, brainstorm, optimise via Gemini
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_generate_scripts() {
        self::verify();
        $p = self::payload();

        $story       = sanitize_textarea_field( $p['story'] ?? '' );
        $scene_count = max( 1, min( 10, intval( $p['sceneCount'] ?? 3 ) ) );
        $chars       = $p['characterDescriptions'] ?? [];

        if ( empty( $story ) ) {
            wp_send_json_error( [ 'message' => 'Story is required.' ] );
        }

        // Build character context
        $char_text = '';
        if ( ! empty( $chars ) ) {
            $char_text = "\n\nCHARACTERS:\n";
            foreach ( $chars as $i => $c ) {
                $char_text .= ( $i + 1 ) . '. ' . sanitize_text_field( $c['name'] ?? 'Character' )
                    . ': ' . sanitize_text_field( $c['description'] ?? '' ) . "\n";
            }
        }

        $system = "You are a professional film storyboard artist. Create a {$scene_count}-scene cinematic storyboard. "
            . "Return ONLY valid JSON: {\"scripts\":[{\"sceneNumber\":1,\"description\":\"...\",\"cameraAngle\":\"...\",\"mood\":\"...\"},...]}";

        $user_msg = "Story: {$story}{$char_text}\n\nGenerate exactly {$scene_count} scenes as JSON.";

        $result = self::gemini_chat( [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user', 'content' => $user_msg ],
        ], [ 'temperature' => 0.8 ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Script generation failed.' ] );
        }

        // Parse JSON from response
        $text  = $result['message'] ?? '';
        // Extract JSON from markdown code blocks if present
        if ( preg_match( '/```(?:json)?\s*(\{.+?\})\s*```/s', $text, $m ) ) {
            $text = $m[1];
        }
        $data = json_decode( $text, true );

        if ( ! $data || empty( $data['scripts'] ) ) {
            // Try to parse the entire response as JSON
            $data = json_decode( $result['message'], true );
        }

        if ( ! $data || empty( $data['scripts'] ) ) {
            wp_send_json_error( [ 'message' => 'Could not parse script response.' ] );
        }

        wp_send_json_success( $data );
    }

    public static function handle_tc_brainstorm_story() {
        self::verify();
        $p     = self::payload();
        $chars = $p['characterDescriptions'] ?? [];

        $char_text = '';
        if ( ! empty( $chars ) ) {
            foreach ( $chars as $c ) {
                $char_text .= '- ' . sanitize_text_field( $c['name'] ?? '' )
                    . ': ' . sanitize_text_field( $c['description'] ?? '' ) . "\n";
            }
        }

        $prompt = "Create an engaging short story concept (3-5 sentences) for a video storyboard.";
        if ( $char_text ) {
            $prompt .= " Include these characters:\n{$char_text}";
        }
        $prompt .= "\nReturn only the story text, no JSON, no formatting.";

        $result = self::gemini_chat( [
            [ 'role' => 'user', 'content' => $prompt ],
        ], [ 'temperature' => 0.9 ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Brainstorm failed.' ] );
        }

        wp_send_json_success( [ 'story' => trim( $result['message'] ) ] );
    }

    public static function handle_tc_optimize_story() {
        self::verify();
        $p     = self::payload();
        $story = sanitize_textarea_field( $p['story'] ?? '' );

        if ( empty( $story ) ) {
            wp_send_json_error( [ 'message' => 'Story is required.' ] );
        }

        $result = self::gemini_chat( [
            [ 'role' => 'system', 'content' => 'You are a story editor. Improve the following story for cinematic video production. Keep it concise. Return only the improved text.' ],
            [ 'role' => 'user', 'content' => $story ],
        ], [ 'temperature' => 0.7 ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Optimization failed.' ] );
        }

        wp_send_json_success( [ 'story' => trim( $result['message'] ) ] );
    }

    public static function handle_tc_generate_composite() {
        self::verify();
        $p = self::payload();

        $prompt = sanitize_textarea_field( $p['prompt'] ?? '' );
        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
        }

        // Use image generation tool
        if ( ! class_exists( 'BizCity_Tool_Image' ) ) {
            $tool_image_dir = dirname( BIZCITY_VIDEO_KLING_DIR ) . '/bizcity-tool-image/';
            if ( file_exists( $tool_image_dir . 'includes/class-tools-image.php' ) ) {
                require_once $tool_image_dir . 'includes/class-tools-image.php';
            }
        }

        if ( class_exists( 'BizCity_Tool_Image' ) ) {
            $result = BizCity_Tool_Image::generate_image( [
                'prompt'  => $prompt,
                'model'   => 'gemini-pro',
                'size'    => '1024x1024',
                'user_id' => get_current_user_id(),
            ] );

            $url = $result['data']['image_url'] ?? $result['data']['url'] ?? '';
            if ( ! empty( $url ) ) {
                wp_send_json_success( [ 'resultUrl' => $url ] );
            }
        }

        wp_send_json_error( [ 'message' => 'Composite generation failed.' ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  GEMINI HELPERS — Describe image, optimise prompt
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_describe_image() {
        self::verify();
        $p   = self::payload();
        $b64 = $p['imageBase64'] ?? '';

        if ( empty( $b64 ) ) {
            wp_send_json_error( [ 'message' => 'Image data required.' ] );
        }

        // Build multimodal message (OpenAI format with image_url)
        $content = [
            [ 'type' => 'text', 'text' => 'Describe this image in detail for use as a video generation prompt. Focus on subjects, colors, mood, lighting, and composition.' ],
            [ 'type' => 'image_url', 'image_url' => [ 'url' => $b64 ] ],
        ];

        $result = self::gemini_chat( [
            [ 'role' => 'user', 'content' => $content ],
        ], [ 'model' => 'google/gemini-2.0-flash-001', 'purpose' => 'vision' ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Description failed.' ] );
        }

        wp_send_json_success( [ 'description' => trim( $result['message'] ) ] );
    }

    public static function handle_tc_optimize_prompt() {
        self::verify();
        $p      = self::payload();
        $prompt = sanitize_textarea_field( $p['prompt'] ?? '' );
        $target = sanitize_text_field( $p['targetModel'] ?? '' );

        if ( empty( $prompt ) ) {
            wp_send_json_error( [ 'message' => 'Prompt is required.' ] );
        }

        $system = "You are an expert at writing prompts for AI video generation models. "
            . "Optimize the following prompt for best results with {$target}. "
            . "Return ONLY the optimized prompt text, nothing else.";

        $result = self::gemini_chat( [
            [ 'role' => 'system', 'content' => $system ],
            [ 'role' => 'user', 'content' => $prompt ],
        ], [ 'temperature' => 0.6 ] );

        if ( ! $result['success'] ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Optimization failed.' ] );
        }

        wp_send_json_success( [ 'optimizedPrompt' => trim( $result['message'] ) ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  VIDEO TRIM — FFmpeg
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_trim_video() {
        self::verify();
        $p = self::payload();

        $video_url  = esc_url_raw( $p['videoUrl'] ?? '' );
        $start_time = floatval( $p['startTime'] ?? 0 );
        $end_time   = floatval( $p['endTime'] ?? 0 );

        if ( empty( $video_url ) || $end_time <= $start_time ) {
            wp_send_json_error( [ 'message' => 'Invalid trim parameters.' ] );
        }

        // Check FFmpeg availability
        $ffmpeg = trim( shell_exec( 'which ffmpeg 2>/dev/null' ) ?: shell_exec( 'where ffmpeg 2>nul' ) ?: '' );
        if ( empty( $ffmpeg ) ) {
            wp_send_json_error( [ 'message' => 'FFmpeg not available on server.' ] );
        }

        // Download video to temp
        $tmp_input = wp_tempnam( 'tc_trim_in_' );
        $response  = wp_remote_get( $video_url, [ 'timeout' => 60, 'stream' => true, 'filename' => $tmp_input ] );

        if ( is_wp_error( $response ) ) {
            @unlink( $tmp_input );
            wp_send_json_error( [ 'message' => 'Failed to download video: ' . $response->get_error_message() ] );
        }

        // Output path
        $upload_dir = wp_upload_dir();
        $filename   = 'tc_trimmed_' . time() . '_' . wp_generate_password( 6, false ) . '.mp4';
        $out_path   = $upload_dir['path'] . '/' . $filename;

        $duration = $end_time - $start_time;

        // FFmpeg trim command
        $cmd = sprintf(
            '%s -y -ss %s -i %s -t %s -c:v libx264 -c:a aac -movflags +faststart %s 2>&1',
            escapeshellarg( $ffmpeg ),
            escapeshellarg( (string) $start_time ),
            escapeshellarg( $tmp_input ),
            escapeshellarg( (string) $duration ),
            escapeshellarg( $out_path )
        );

        $output = [];
        $code   = 0;
        exec( $cmd, $output, $code );

        @unlink( $tmp_input );

        if ( $code !== 0 || ! file_exists( $out_path ) ) {
            wp_send_json_error( [ 'message' => 'FFmpeg trim failed.' ] );
        }

        // Insert into media library
        $attach_id = wp_insert_attachment( [
            'post_mime_type' => 'video/mp4',
            'post_title'     => 'Trimmed video',
            'post_status'    => 'inherit',
        ], $out_path );

        $url = $upload_dir['url'] . '/' . $filename;

        wp_send_json_success( [
            'url'      => $url,
            'filename' => $filename,
            'duration' => $duration,
        ] );
    }

    /* ════════════════════════════════════════════════════════════
     *  COMPOSE VIDEO — Concat multiple clips via FFmpeg
     *
     *  Payload:
     *    clips[]     — { url, duration? }  ordered list of video URLs
     *    transition  — 'none' | 'fade' | 'slide'   (default: none)
     *    fadeDuration — seconds for transition (default: 0.5)
     *    audioUrl    — optional background music URL
     *    aspectRatio — '9:16' | '16:9' | '1:1' (default: 9:16)
     * ════════════════════════════════════════════════════════════ */

    public static function handle_tc_compose_video() {
        self::verify();
        $p = self::payload();

        $clips         = $p['clips'] ?? [];
        $transition    = sanitize_text_field( $p['transition'] ?? 'none' );
        $fade_dur      = max( 0.1, min( 3, floatval( $p['fadeDuration'] ?? 0.5 ) ) );
        $audio_url     = esc_url_raw( $p['audioUrl'] ?? '' );
        $aspect_ratio  = sanitize_text_field( $p['aspectRatio'] ?? '9:16' );
        $text_overlays = $p['textOverlays'] ?? [];

        if ( empty( $clips ) || ! is_array( $clips ) ) {
            wp_send_json_error( [ 'message' => 'Không có video clip nào.' ] );
        }
        if ( count( $clips ) > 20 ) {
            wp_send_json_error( [ 'message' => 'Tối đa 20 clip.' ] );
        }

        // Resolve output dimensions
        $dim_map = [
            '9:16' => [ 1080, 1920 ],
            '16:9' => [ 1920, 1080 ],
            '1:1'  => [ 1080, 1080 ],
        ];
        // 'Auto' or unknown → detect from first clip (deferred), default 9:16
        if ( ! isset( $dim_map[ $aspect_ratio ] ) ) {
            $aspect_ratio = '9:16';
        }
        [ $width, $height ] = $dim_map[ $aspect_ratio ];

        // Check FFmpeg
        if ( ! class_exists( 'BizCity_Video_Kling_FFmpeg_Presets' ) ) {
            require_once BIZCITY_VIDEO_KLING_DIR . 'lib/class-ffmpeg-presets.php';
        }
        $ffcheck = BizCity_Video_Kling_FFmpeg_Presets::check_availability();
        if ( empty( $ffcheck['available'] ) ) {
            wp_send_json_error( [ 'message' => 'FFmpeg không khả dụng trên server.' ] );
        }
        $ffmpeg = BizCity_Video_Kling_FFmpeg_Presets::get_ffmpeg_path();

        // Temp dir
        $tmp_dir = sys_get_temp_dir() . '/bvk_compose_' . get_current_user_id() . '_' . uniqid();
        if ( ! wp_mkdir_p( $tmp_dir ) ) {
            wp_send_json_error( [ 'message' => 'Không tạo được thư mục tạm.' ] );
        }

        try {
            $scale = "{$width}:{$height}";
            $segments = [];

            // ── Step 1: Download & normalize each clip ──
            foreach ( $clips as $i => $clip ) {
                $url = esc_url_raw( $clip['url'] ?? '' );
                if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    throw new \Exception( "Clip " . ( $i + 1 ) . ": URL không hợp lệ." );
                }

                $local = $tmp_dir . "/v{$i}.mp4";
                $resp  = wp_remote_get( $url, [ 'timeout' => 120, 'stream' => true, 'filename' => $local ] );

                if ( is_wp_error( $resp ) ) {
                    throw new \Exception( "Không tải được clip " . ( $i + 1 ) . ": " . $resp->get_error_message() );
                }
                if ( wp_remote_retrieve_response_code( $resp ) !== 200 ) {
                    throw new \Exception( "Clip " . ( $i + 1 ) . " trả về HTTP " . wp_remote_retrieve_response_code( $resp ) );
                }
                if ( ! file_exists( $local ) || filesize( $local ) < 1000 ) {
                    throw new \Exception( "Clip " . ( $i + 1 ) . " tải không thành công." );
                }

                // Normalize to same resolution + codecs
                $seg_out = $tmp_dir . "/seg{$i}.mp4";
                $cmd = sprintf(
                    '%s -y -i %s -vf "scale=%s:force_original_aspect_ratio=decrease,pad=%s:(ow-iw)/2:(oh-ih)/2:black" -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -r 30 -an %s 2>&1',
                    escapeshellarg( $ffmpeg ),
                    escapeshellarg( $local ),
                    $scale, $scale,
                    escapeshellarg( $seg_out )
                );

                $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                if ( empty( $result['success'] ) || ! file_exists( $seg_out ) || filesize( $seg_out ) < 100 ) {
                    throw new \Exception( "Clip " . ( $i + 1 ) . " xử lý thất bại." );
                }

                $segments[] = $seg_out;
            }

            // ── Step 2: Concat or xfade ──
            $merged = $tmp_dir . '/merged.mp4';

            if ( count( $segments ) === 1 ) {
                copy( $segments[0], $merged );
            } elseif ( $transition === 'none' || count( $segments ) < 2 ) {
                // Simple concat demuxer
                $concat_file = $tmp_dir . '/concat.txt';
                $content = '';
                foreach ( $segments as $seg ) {
                    $escaped = str_replace( '\\', '/', $seg );
                    $content .= "file '" . str_replace( "'", "'\\''", $escaped ) . "'\n";
                }
                file_put_contents( $concat_file, $content );

                $cmd = sprintf(
                    '%s -y -f concat -safe 0 -i %s -c copy -movflags +faststart %s 2>&1',
                    escapeshellarg( $ffmpeg ),
                    escapeshellarg( $concat_file ),
                    escapeshellarg( $merged )
                );
                $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                if ( empty( $result['success'] ) ) {
                    throw new \Exception( "Ghép video thất bại: " . ( $result['error'] ?? 'unknown' ) );
                }
            } else {
                // xfade transitions between clips (requires FFmpeg 4.3+)
                $xfade_type = $transition === 'slide' ? 'slideleft' : 'fade';
                try {
                    self::compose_xfade( $segments, $merged, $xfade_type, $fade_dur, $ffmpeg );
                } catch ( \Exception $e ) {
                    // xfade not supported — fall back to simple concat
                    error_log( '[BVK-Compose] xfade fallback to concat: ' . $e->getMessage() );
                    $concat_file = $tmp_dir . '/concat.txt';
                    $content = '';
                    foreach ( $segments as $seg ) {
                        $escaped = str_replace( '\\', '/', $seg );
                        $content .= "file '" . str_replace( "'", "'\\''", $escaped ) . "'\n";
                    }
                    file_put_contents( $concat_file, $content );

                    $cmd = sprintf(
                        '%s -y -f concat -safe 0 -i %s -c copy -movflags +faststart %s 2>&1',
                        escapeshellarg( $ffmpeg ),
                        escapeshellarg( $concat_file ),
                        escapeshellarg( $merged )
                    );
                    $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                    if ( empty( $result['success'] ) || ! file_exists( $merged ) || filesize( $merged ) < 100 ) {
                        throw new \Exception( 'Ghép video thất bại (concat fallback).' );
                    }
                }
            }

            // ── Step 3: Audio mix (optional) ──
            $output = $tmp_dir . '/output.mp4';

            if ( ! empty( $audio_url ) && filter_var( $audio_url, FILTER_VALIDATE_URL ) ) {
                $audio_local = $tmp_dir . '/bgm.mp3';
                $resp = wp_remote_get( $audio_url, [ 'timeout' => 60, 'stream' => true, 'filename' => $audio_local ] );

                if ( ! is_wp_error( $resp ) && file_exists( $audio_local ) && filesize( $audio_local ) > 100 ) {
                    $cmd = sprintf(
                        '%s -y -i %s -i %s -map 0:v -map 1:a -c:v copy -c:a aac -b:a 128k -shortest -movflags +faststart %s 2>&1',
                        escapeshellarg( $ffmpeg ),
                        escapeshellarg( $merged ),
                        escapeshellarg( $audio_local ),
                        escapeshellarg( $output )
                    );
                    $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                    if ( empty( $result['success'] ) ) {
                        error_log( '[BVK-Compose] Audio mix failed, using video-only' );
                        copy( $merged, $output );
                    }
                } else {
                    copy( $merged, $output );
                }
            } else {
                copy( $merged, $output );
            }

            if ( ! file_exists( $output ) || filesize( $output ) < 1000 ) {
                throw new \Exception( 'Xuất video thất bại — file output trống.' );
            }

            // ── Step 4: Text overlays (optional) ──
            if ( ! empty( $text_overlays ) && is_array( $text_overlays ) ) {
                $text_input  = $output;
                $text_output = $tmp_dir . '/text_overlay.mp4';
                $drawtext_filters = [];

                foreach ( $text_overlays as $i => $overlay ) {
                    $text       = isset( $overlay['text'] ) ? strval( $overlay['text'] ) : '';
                    if ( empty( $text ) ) continue;

                    // Sanitize text for FFmpeg drawtext (escape special chars)
                    $text = str_replace( [ "'", "\\", ":", "%" ], [ "\\'", "\\\\", "\\:", "%%" ], $text );

                    $start_time = max( 0, floatval( $overlay['startTime'] ?? 0 ) );
                    $end_time   = max( $start_time + 0.1, floatval( $overlay['endTime'] ?? 3 ) );
                    $font_size  = max( 12, min( 120, intval( $overlay['fontSize'] ?? 32 ) ) );
                    $font_color = preg_match( '/^#[0-9a-fA-F]{6}$/', $overlay['fontColor'] ?? '' ) ? $overlay['fontColor'] : '#ffffff';
                    $y_pct      = max( 0, min( 100, intval( $overlay['y'] ?? 50 ) ) );
                    $x_pct      = max( 0, min( 100, intval( $overlay['x'] ?? 50 ) ) );

                    // Convert percentage to pixel expression
                    $x_expr = "(w*{$x_pct}/100-tw/2)";
                    $y_expr = "(h*{$y_pct}/100-th/2)";

                    // Check for background box
                    $box_opts = '';
                    if ( ! empty( $overlay['bgColor'] ) ) {
                        $bg_color = preg_match( '/^#[0-9a-fA-F]{6,8}$/', $overlay['bgColor'] ) ? $overlay['bgColor'] : '#00000080';
                        $box_opts = ":box=1:boxcolor={$bg_color}:boxborderw=8";
                    }

                    $drawtext_filters[] = sprintf(
                        "drawtext=text='%s':fontsize=%d:fontcolor=%s:x=%s:y=%s%s:enable='between(t,%.1f,%.1f)'",
                        $text,
                        $font_size,
                        $font_color,
                        $x_expr,
                        $y_expr,
                        $box_opts,
                        $start_time,
                        $end_time
                    );
                }

                if ( ! empty( $drawtext_filters ) ) {
                    $vf = implode( ',', $drawtext_filters );
                    $cmd = sprintf(
                        '%s -y -i %s -vf "%s" -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -c:a copy -movflags +faststart %s 2>&1',
                        escapeshellarg( $ffmpeg ),
                        escapeshellarg( $text_input ),
                        $vf,
                        escapeshellarg( $text_output )
                    );
                    $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
                    if ( ! empty( $result['success'] ) && file_exists( $text_output ) && filesize( $text_output ) > 1000 ) {
                        // Replace output with text-overlaid version
                        unlink( $output );
                        rename( $text_output, $output );
                    } else {
                        error_log( '[BVK-Compose] Text overlay failed, using video without text: ' . ( $result['error'] ?? 'unknown' ) );
                    }
                }
            }

            // ── Step 5: Move to uploads ──
            $upload_dir = wp_upload_dir();
            $export_dir = $upload_dir['basedir'] . '/bvk-exports';
            wp_mkdir_p( $export_dir );

            $filename   = 'compose-' . get_current_user_id() . '-' . time() . '.mp4';
            $final_path = $export_dir . '/' . $filename;
            $final_url  = $upload_dir['baseurl'] . '/bvk-exports/' . $filename;

            rename( $output, $final_path );

            // Cleanup
            self::compose_cleanup( $tmp_dir );

            wp_send_json_success( [
                'url'      => $final_url,
                'filename' => $filename,
                'size'     => filesize( $final_path ),
            ] );

        } catch ( \Exception $e ) {
            self::compose_cleanup( $tmp_dir );
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /**
     * Apply xfade transitions between segments.
     * Uses chained xfade filters: seg0 xfade seg1 → result xfade seg2 → ...
     */
    private static function compose_xfade( array $segments, string $output, string $xfade_type, float $fade_dur, string $ffmpeg ): void {
        // Get durations of each segment via ffprobe (using execute() to avoid shell_exec)
        $ffprobe = str_replace( 'ffmpeg', 'ffprobe', $ffmpeg );
        $durations = [];
        foreach ( $segments as $seg ) {
            $cmd = sprintf(
                '%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1',
                escapeshellarg( $ffprobe ),
                escapeshellarg( $seg )
            );
            $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );
            $dur = floatval( trim( $result['output'] ?? '' ) );
            $durations[] = $dur > 0 ? $dur : 5.0;
        }

        $n = count( $segments );

        // Build filter_complex for chained xfade
        $inputs = '';
        foreach ( $segments as $i => $seg ) {
            $inputs .= ' -i ' . escapeshellarg( $seg );
        }

        $filter_parts = [];
        // First xfade: [0:v][1:v]xfade=...
        $offset = $durations[0] - $fade_dur;
        $offset = max( 0.1, $offset );
        $filter_parts[] = "[0:v][1:v]xfade=transition={$xfade_type}:duration=" . number_format( $fade_dur, 3, '.', '' ) . ":offset=" . number_format( $offset, 3, '.', '' ) . "[v1]";

        $cumulative_dur = $durations[0] + $durations[1] - $fade_dur;

        for ( $i = 2; $i < $n; $i++ ) {
            $prev_label = 'v' . ( $i - 1 );
            $curr_label = 'v' . $i;
            $offset = $cumulative_dur - $fade_dur;
            $offset = max( 0.1, $offset );
            $filter_parts[] = "[{$prev_label}][{$i}:v]xfade=transition={$xfade_type}:duration=" . number_format( $fade_dur, 3, '.', '' ) . ":offset=" . number_format( $offset, 3, '.', '' ) . "[{$curr_label}]";
            $cumulative_dur = $cumulative_dur + $durations[ $i ] - $fade_dur;
        }

        $last_label = 'v' . ( $n - 1 );
        $filter_str = implode( ';', $filter_parts );

        $cmd = sprintf(
            '%s -y%s -filter_complex "%s" -map "[%s]" -c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p -movflags +faststart %s 2>&1',
            escapeshellarg( $ffmpeg ),
            $inputs,
            $filter_str,
            $last_label,
            escapeshellarg( $output )
        );

        error_log( "[BVK-Compose] xfade cmd: {$cmd}" );
        $result = BizCity_Video_Kling_FFmpeg_Presets::execute( $cmd );

        if ( empty( $result['success'] ) || ! file_exists( $output ) || filesize( $output ) < 100 ) {
            $raw_error = $result['error'] ?? $result['output'] ?? 'unknown';
            // Log full output for debugging, but extract only the last meaningful line for the exception
            error_log( "[BVK-Compose] xfade failed: {$raw_error}" );
            // Try to extract the real FFmpeg error line (last line containing "Error" or "No such filter")
            $short_error = 'xfade transition failed (FFmpeg 4.3+ required)';
            if ( preg_match( '/(?:No such filter|Error|Invalid|Unknown).{0,120}/i', $raw_error, $m ) ) {
                $short_error = trim( $m[0] );
            }
            throw new \Exception( $short_error );
        }
    }

    /**
     * Cleanup temp directory used by compose.
     */
    private static function compose_cleanup( string $dir ): void {
        if ( ! is_dir( $dir ) ) return;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $file ) {
            $file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
        }
        @rmdir( $dir );
    }

    /* ═════════════════════════════════════════════════════════
     *  Faceswap — Image + Video via PiAPI
     * ═════════════════════════════════════════════════════════ */

    /**
     * Handle faceswap request.
     *
     * Input:
     *   - swap_image:  URL or base64 of face source
     *   - target_image: URL or base64 of target image (for image faceswap)
     *   - target_video: URL of target video (for video faceswap)
     *   - mode: 'image' | 'video'
     *   - swap_faces_index: (optional) face indices for multi-face
     *   - target_faces_index: (optional) face indices for multi-face
     */
    public static function handle_tc_faceswap() {
        self::verify();
        $p = self::payload();

        $mode = sanitize_text_field( $p['mode'] ?? 'image' );
        if ( ! in_array( $mode, [ 'image', 'video' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid faceswap mode.' ] );
        }

        // Handle swap_image (face source)
        $swap_image = '';
        $swap_b64 = $p['swap_image'] ?? '';
        if ( ! empty( $swap_b64 ) && str_starts_with( $swap_b64, 'data:' ) ) {
            $uploaded = self::upload_base64_to_media( $swap_b64, 'tc_faceswap_src_' . time() . '.png' );
            $swap_image = $uploaded['url'] ?? '';
        } elseif ( ! empty( $swap_b64 ) && filter_var( $swap_b64, FILTER_VALIDATE_URL ) ) {
            $swap_image = $swap_b64;
        }

        if ( empty( $swap_image ) ) {
            wp_send_json_error( [ 'message' => 'swap_image (face source) is required.' ] );
        }

        // Load BizCity_Video_API
        if ( ! class_exists( 'BizCity_Video_API' ) ) {
            wp_send_json_error( [ 'message' => 'Video API not available.' ] );
        }

        if ( $mode === 'image' ) {
            // Handle target_image
            $target_image = '';
            $target_b64 = $p['target_image'] ?? '';
            if ( ! empty( $target_b64 ) && str_starts_with( $target_b64, 'data:' ) ) {
                $uploaded = self::upload_base64_to_media( $target_b64, 'tc_faceswap_tgt_' . time() . '.png' );
                $target_image = $uploaded['url'] ?? '';
            } elseif ( ! empty( $target_b64 ) && filter_var( $target_b64, FILTER_VALIDATE_URL ) ) {
                $target_image = $target_b64;
            }

            if ( empty( $target_image ) ) {
                wp_send_json_error( [ 'message' => 'target_image is required for image faceswap.' ] );
            }

            $result = BizCity_Video_API::faceswap_image( $swap_image, $target_image );
        } else {
            // Video faceswap
            $target_video = esc_url_raw( $p['target_video'] ?? '' );
            if ( empty( $target_video ) || ! filter_var( $target_video, FILTER_VALIDATE_URL ) ) {
                wp_send_json_error( [ 'message' => 'target_video URL is required for video faceswap.' ] );
            }

            $options = [];
            if ( ! empty( $p['swap_faces_index'] ) ) {
                $options['swap_faces_index'] = sanitize_text_field( $p['swap_faces_index'] );
            }
            if ( ! empty( $p['target_faces_index'] ) ) {
                $options['target_faces_index'] = sanitize_text_field( $p['target_faces_index'] );
            }

            $result = BizCity_Video_API::faceswap_video( $swap_image, $target_video, $options );
        }

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( [
                'taskId' => $result['task_id'],
                'status' => $result['status'] ?? 'pending',
                'mode'   => $mode,
            ] );
        } else {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'Faceswap failed.' ] );
        }
    }

    /**
     * Poll faceswap task status.
     * Input: taskId, mode ('image'|'video')
     */
    public static function handle_tc_faceswap_status() {
        self::verify();
        $p = self::payload();

        $task_id = sanitize_text_field( $p['taskId'] ?? '' );
        if ( empty( $task_id ) ) {
            wp_send_json_error( [ 'message' => 'taskId is required.' ] );
        }

        if ( ! class_exists( 'BizCity_Video_API' ) ) {
            wp_send_json_error( [ 'message' => 'Video API not available.' ] );
        }

        $result = BizCity_Video_API::faceswap_status( $task_id );

        if ( ! empty( $result['success'] ) ) {
            $resp = [
                'status'   => $result['status'],
                'progress' => $result['progress'] ?? 0,
            ];
            if ( ! empty( $result['image_url'] ) ) {
                $resp['resultUrl'] = $result['image_url'];
            }
            if ( ! empty( $result['video_url'] ) ) {
                $resp['resultUrl'] = $result['video_url'];
            }
            wp_send_json_success( $resp );
        } else {
            wp_send_json_error( [
                'message' => $result['error'] ?? 'Failed to get faceswap status.',
                'status'  => $result['status'] ?? 'failed',
            ] );
        }
    }

    /**
     * Text-to-Speech via OpenAI TTS.
     * Input: text, voice (optional), speed (optional), model (optional)
     */
    public static function handle_tc_tts() {
        self::verify();
        $p = self::payload();

        $text  = sanitize_textarea_field( $p['text'] ?? '' );
        $voice = sanitize_text_field( $p['voice'] ?? 'nova' );
        $speed = floatval( $p['speed'] ?? 1.0 );
        $model = sanitize_text_field( $p['model'] ?? 'tts-1' );

        if ( empty( trim( $text ) ) ) {
            wp_send_json_error( [ 'message' => 'Text is required.' ] );
        }

        if ( ! class_exists( 'BizCity_Video_Kling_OpenAI_TTS' ) ) {
            wp_send_json_error( [ 'message' => 'TTS library not available.' ] );
        }

        $result = BizCity_Video_Kling_OpenAI_TTS::generate_and_save( $text, '', [
            'voice'           => $voice,
            'model'           => $model,
            'speed'           => $speed,
            'response_format' => 'mp3',
        ] );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ?? 'TTS generation failed.' ] );
        }

        // Rewrite URL for CDN if needed
        $url = $result['url'] ?? '';
        if ( method_exists( __CLASS__, 'to_media_url' ) ) {
            $url = self::to_media_url( $url );
        }

        wp_send_json_success( [
            'url'  => $url,
            'size' => $result['size'] ?? 0,
        ] );
    }
}
