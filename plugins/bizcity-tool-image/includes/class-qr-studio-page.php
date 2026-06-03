<?php
/**
 * QR Studio — Full-page at /qr-studio/
 *
 * Two-panel layout:
 *   Left  — QR image input (URL param ?qr= OR file upload)
 *   Right — Template gallery fetched from bizcity.vn via proxy REST
 *
 * Architecture (R-GW-8 compliant):
 *   FE → /wp-json/bizcity-channel/v1/qr-studio/templates  (same-origin)
 *              ↓ PHP proxy
 *        https://bizcity.vn/wp-json/bizcity/v1/qr-templates  (Bearer biz-xxx)
 *
 * Fail-OPEN: gateway unreachable → return _degraded + empty list, never 5xx.
 *
 * @package BizCity_Tool_Image
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_QR_Studio_Page {

    const SLUG      = 'qr-studio';
    const QUERY_VAR = 'bztimg_qr_studio';
    const NS        = 'bizcity-channel/v1';

    public static function init(): void {
        add_action( 'init',              [ __CLASS__, 'register_rewrite' ] );
        add_filter( 'query_vars',        [ __CLASS__, 'register_query_var' ] );
        add_action( 'template_redirect', [ __CLASS__, 'render' ] );
        add_action( 'rest_api_init',     [ __CLASS__, 'register_proxy_routes' ] );

        /* AJAX */
        add_action( 'wp_ajax_bztimg_qr_upload_image', [ __CLASS__, 'ajax_upload_image' ] );
    }

    /* ─── Rewrite ─── */

    public static function register_rewrite(): void {
        add_rewrite_rule(
            '^' . self::SLUG . '/?$',
            'index.php?' . self::QUERY_VAR . '=1',
            'top'
        );
    }

    public static function register_query_var( array $vars ): array {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /* ─── Full-page render ─── */

    public static function render(): void {
        if ( ! get_query_var( self::QUERY_VAR ) ) {
            return;
        }

        if ( ! is_user_logged_in() ) {
            wp_redirect( wp_login_url( home_url( '/' . self::SLUG . '/' ) ) );
            exit;
        }

        /* Hide admin bar, Query Monitor on this full-page studio */
        add_filter( 'show_admin_bar', '__return_false' );
        add_filter( 'wp_admin_bar_class', '__return_empty_string' );
        // Query Monitor: disable dispatchers for this request
        add_filter( 'qm/dispatchers', '__return_empty_array', 99 );
        add_filter( 'qm/dispatch/html', '__return_false', 99 );

        $view = BZTIMG_DIR . 'views/page-qr-studio.php';
        if ( file_exists( $view ) ) {
            include $view;
        } else {
            wp_die( 'QR Studio view not found.', '', [ 'response' => 500 ] );
        }
        exit;
    }

    /* ─── Proxy REST Routes (R-GW-8) ─── */

    public static function register_proxy_routes(): void {

        /* Templates list */
        register_rest_route( self::NS, '/qr-studio/templates', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'proxy_list_templates' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'category'   => [ 'type' => 'string',  'default' => '' ],
                'search'     => [ 'type' => 'string',  'default' => '' ],
                'per_page'   => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
                'page'       => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
            ],
        ] );

        /* Single template with full template_json */
        register_rest_route( self::NS, '/qr-studio/templates/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'proxy_get_template' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'      => [ 'type' => 'integer', 'required' => true ],
                'preview' => [ 'type' => 'boolean', 'default'  => false ],
            ],
        ] );

        /* Categories */
        register_rest_route( self::NS, '/qr-studio/categories', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'proxy_get_categories' ],
            'permission_callback' => '__return_true',
        ] );

        /* AI Generate — uses BizCity_Tools_Image::generate_image with QR + template as references */
        register_rest_route( self::NS, '/qr-studio/generate', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'ai_generate' ],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => [
                'template_id' => [ 'type' => 'integer', 'required' => true ],
                'qr_url'      => [ 'type' => 'string',  'required' => true ],
                'model'       => [ 'type' => 'string',  'default'  => 'gpt-image-mini' ],
                'extra_hint'  => [ 'type' => 'string',  'default'  => '' ],
            ],
        ] );
    }

    /* ─── AI Generate ─── */
    public static function ai_generate( WP_REST_Request $request ) {
        /* R-GW-8: client KHÔNG cài bizcity-llm-router. Phải đi qua
         * BizCity_LLM_Client (proxy REST → bizcity.vn). Fail-OPEN trả 200 +
         * _degraded thay vì 5xx. */
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            error_log( '[QR-Studio] ai_generate: BizCity_LLM_Client missing (core/bizcity-llm not loaded)' );
            return new WP_REST_Response( [
                'success'   => false,
                '_degraded' => true,
                'stage'     => 'guard',
                'message'   => 'BizCity LLM client chưa được load. Hãy kiểm tra core/bizcity-llm bootstrap.',
            ], 200 );
        }
        $llm = BizCity_LLM_Client::instance();
        if ( ! $llm->is_ready() ) {
            return new WP_REST_Response( [
                'success'   => false,
                '_degraded' => true,
                'stage'     => 'guard',
                'message'   => 'BizCity API key chưa cấu hình (Settings → BizCity TwinChat). Khoá biz-xxx được cấp tại https://bizcity.vn/my-account/api-keys/.',
            ], 200 );
        }

        $template_id = (int) $request->get_param( 'template_id' );
        $qr_url      = esc_url_raw( (string) $request->get_param( 'qr_url' ) );
        $model       = sanitize_text_field( (string) $request->get_param( 'model' ) );
        $extra_hint  = sanitize_text_field( (string) $request->get_param( 'extra_hint' ) );

        if ( ! $template_id || ! $qr_url ) {
            return new WP_REST_Response( [
                'success' => false,
                'stage'   => 'input',
                'message' => 'Thiếu template_id hoặc qr_url.',
            ], 200 );
        }

        /* Pull template detail (preview=1, không tăng download count) — vẫn qua
         * proxy gateway_get() ở dưới (REST contract bizcity/v1/qr-templates/...). */
        $tpl_resp = self::gateway_get( '/wp-json/bizcity/v1/qr-templates/' . $template_id, [ 'preview' => 1 ] );
        if ( empty( $tpl_resp['success'] ) || empty( $tpl_resp['data'] ) ) {
            error_log( '[QR-Studio] ai_generate: template fetch failed → ' . wp_json_encode( $tpl_resp ) );
            return new WP_REST_Response( [
                'success'   => false,
                '_degraded' => ! empty( $tpl_resp['_degraded'] ),
                'stage'     => 'template',
                'message'   => 'Không tải được template (' . ( $tpl_resp['message'] ?? 'unknown' ) . ').',
                'debug'     => $tpl_resp,
            ], 200 );
        }

        $tpl       = $tpl_resp['data'];
        $thumbnail = esc_url_raw( $tpl['thumbnail_url'] ?? '' );
        $cw        = (int) ( $tpl['canvas_width']  ?? 1024 );
        $ch        = (int) ( $tpl['canvas_height'] ?? 1024 );

        $tjson = $tpl['template_json'] ?? null;
        if ( is_string( $tjson ) ) {
            $decoded = json_decode( $tjson, true );
            $tjson   = is_array( $decoded ) ? $decoded : [];
        } elseif ( ! is_array( $tjson ) ) {
            $tjson = [];
        }

        $base_prompt = trim( (string) ( $tjson['prompt'] ?? ( $tpl['description'] ?? '' ) ) );
        if ( $base_prompt === '' ) {
            $base_prompt = 'Create a high-quality marketing image based on the reference style.';
        }

        /* QR slot hint */
        $slot      = $tjson['qr_slot'] ?? null;
        $qr_region = 'center';
        if ( is_array( $slot ) && isset( $slot['x'], $slot['y'], $slot['w'], $slot['h'] ) ) {
            $cx = $slot['x'] + $slot['w'] / 2;
            $cy = $slot['y'] + $slot['h'] / 2;
            $hx = $cx < $cw / 3 ? 'left' : ( $cx > 2 * $cw / 3 ? 'right' : 'center' );
            $hy = $cy < $ch / 3 ? 'top'  : ( $cy > 2 * $ch / 3 ? 'bottom' : 'middle' );
            $qr_region = trim( $hy . '-' . $hx, '-' );
        }

        $qr_instruction = sprintf(
            "IMPORTANT: The SECOND reference image is a QR code. Integrate it naturally into the composition at the %s area of the canvas. The QR MUST remain perfectly scannable: keep it on a flat, high-contrast (preferably white) background, with clear margin around it, no perspective distortion, no rotation, no overlapping graphics on top. The QR should look like an intentional design element (e.g. printed on a product label, framed on a wall, placed on a table, on a phone screen) — not pasted on as an afterthought. The FIRST reference image is the style/composition reference: reproduce its style and mood but create a NEW image, do not copy it.",
            $qr_region
        );
        if ( $extra_hint !== '' ) {
            $qr_instruction .= ' Additional context: ' . $extra_hint;
        }

        $final_prompt = $base_prompt . "\n\n" . $qr_instruction;

        /* Model: same default as bzdoc image pipeline = Nano Banana Pro */
        $model_map = [
            'gemini-image'   => 'google/gemini-2.5-flash-image',
            'gemini-flash'   => 'google/gemini-2.5-flash-image',
            'nano-banana'    => 'google/gemini-2.5-flash-image',
            'gemini-pro'     => 'google/gemini-3-pro-image-preview',
            'nano-banana-pro'=> 'google/gemini-3-pro-image-preview',
            'gpt-image'      => 'openai/gpt-5-image',
            'gpt-image-mini' => 'openai/gpt-5-image-mini',
            'gpt-image-1'    => 'openai/gpt-image-1',
            'gpt-image-2'    => 'openai/gpt-5.4-image-2',
        ];
        $or_model = isset( $model_map[ $model ] ) ? $model_map[ $model ] : ( $model !== '' ? $model : 'google/gemini-3-pro-image-preview' );
        $or_model = apply_filters( 'bzqrstudio_image_model', $or_model, $template_id, $request );

        /* Reference images — gửi cho gateway qua input_images[] (R-GW-8.3) */
        $input_images = array_values( array_filter( [ $thumbnail, $qr_url ] ) );
        $size         = self::pick_size( $cw, $ch );

        error_log( '[QR-Studio] ai_generate: model=' . $or_model . ' size=' . $size . ' refs=' . count( $input_images ) );

        /* Call gateway client — proxy về https://bizcity.vn/wp-json/bizcity/v1/llm/images/generations.
         * KHÔNG dùng BizCity_Router_Proxy (server-side only, không tồn tại trên client per R-GW-8.1). */
        $gen = $llm->generate_image( $final_prompt, [
            'model'        => $or_model,
            'size'         => $size,
            'n'            => 1,
            'timeout'      => 300,
            'input_images' => $input_images,
            'stream'       => true, // gateway sẽ stream nếu hỗ trợ — fail-back về blocking nếu không
        ] );

        if ( empty( $gen['success'] ) ) {
            error_log( '[QR-Studio] ai_generate: gateway fail → ' . ( $gen['error'] ?? 'unknown' ) );
            return new WP_REST_Response( [
                'success'   => false,
                '_degraded' => true,
                'stage'     => 'gateway',
                'message'   => 'AI sinh ảnh thất bại: ' . ( $gen['error'] ?? 'unknown' ),
                'model'     => $or_model,
                'debug'     => $gen,
            ], 200 );
        }

        /* Persist into Media Library */
        $src = ! empty( $gen['image_url'] )
            ? $gen['image_url']
            : ( ! empty( $gen['b64_json'] ) ? 'data:image/png;base64,' . $gen['b64_json'] : '' );

        if ( $src === '' ) {
            return new WP_REST_Response( [
                'success'   => false,
                '_degraded' => true,
                'stage'     => 'extract',
                'message'   => 'Gateway trả success nhưng không có image_url/b64_json.',
                'debug'     => $gen,
            ], 200 );
        }

        $saved = self::save_image_to_media( $src, [
            'source'      => 'qr-studio',
            'template_id' => $template_id,
            'model'       => $or_model,
        ] );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'image_url'     => $saved['url'] ?? $src,
                'attachment_id' => $saved['attachment_id'] ?? 0,
                'model'         => $gen['model'] ?? $or_model,
                'template_id'   => $template_id,
                'qr_region'     => $qr_region,
            ],
        ], 200 );
    }

    /**
     * Save AI-generated image (URL or data: URI) into WP Media Library.
     * Falls back to returning original URL on failure.
     */
    private static function save_image_to_media( string $src, array $meta = [] ): array {
        if ( ! function_exists( 'wp_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $tmp_file = wp_tempnam( 'qrstudio-' );
        if ( ! $tmp_file ) {
            return [ 'url' => $src, 'attachment_id' => 0 ];
        }

        $bytes = false;
        if ( strpos( $src, 'data:' ) === 0 ) {
            $comma = strpos( $src, ',' );
            if ( $comma !== false ) {
                $bytes = base64_decode( substr( $src, $comma + 1 ) );
            }
        } else {
            $r = wp_remote_get( $src, [ 'timeout' => 60 ] );
            if ( ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) === 200 ) {
                $bytes = wp_remote_retrieve_body( $r );
            }
        }
        if ( $bytes === false || $bytes === '' ) {
            @unlink( $tmp_file );
            return [ 'url' => $src, 'attachment_id' => 0 ];
        }

        file_put_contents( $tmp_file, $bytes );

        $file_array = [
            'name'     => 'qr-studio-' . time() . '.png',
            'tmp_name' => $tmp_file,
        ];

        $attachment_id = media_handle_sideload( $file_array, 0, '', [
            'post_title' => 'QR Studio AI · template ' . ( $meta['template_id'] ?? '?' ),
        ] );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp_file );
            return [ 'url' => $src, 'attachment_id' => 0 ];
        }

        foreach ( $meta as $k => $v ) {
            update_post_meta( $attachment_id, '_qrstudio_' . $k, $v );
        }

        return [
            'url'           => wp_get_attachment_url( $attachment_id ),
            'attachment_id' => $attachment_id,
        ];
    }

    /** Map arbitrary canvas WxH to a supported model size. */
    private static function pick_size( int $w, int $h ): string {
        if ( $w <= 0 || $h <= 0 ) return '1024x1024';
        $ratio = $w / $h;
        if ( $ratio > 1.3 )  return '1536x1024';   // landscape
        if ( $ratio < 0.77 ) return '1024x1536';   // portrait
        return '1024x1024';                         // square
    }

    public static function proxy_list_templates( WP_REST_Request $request ): WP_REST_Response {
        $params   = [];
        $category = sanitize_title( (string) $request->get_param( 'category' ) );
        $search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
        $per_page = (int) $request->get_param( 'per_page' );
        $page     = (int) $request->get_param( 'page' );

        if ( $category !== '' ) $params['category'] = $category;
        if ( $search   !== '' ) $params['search']   = $search;
        if ( $per_page > 0 )   $params['per_page'] = $per_page;
        if ( $page     > 0 )   $params['page']     = $page;

        return new WP_REST_Response(
            self::gateway_get( '/wp-json/bizcity/v1/qr-templates', $params ),
            200
        );
    }

    public static function proxy_get_template( WP_REST_Request $request ): WP_REST_Response {
        $id      = (int) $request->get_param( 'id' );
        $preview = (bool) $request->get_param( 'preview' );
        $params  = $preview ? [ 'preview' => 1 ] : [];

        return new WP_REST_Response(
            self::gateway_get( '/wp-json/bizcity/v1/qr-templates/' . $id, $params ),
            200
        );
    }

    public static function proxy_get_categories( WP_REST_Request $request ): WP_REST_Response {
        return new WP_REST_Response(
            self::gateway_get( '/wp-json/bizcity/v1/qr-templates/categories' ),
            200
        );
    }

    /* ─── Gateway HTTP helper ─── */

    /**
     * Proxy a GET request to bizcity.vn with Bearer auth.
     * Fail-OPEN: always returns 200 with _degraded flag on error.
     */
    private static function gateway_get( string $path, array $params = [] ): array {
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return [ 'success' => false, '_degraded' => true, 'message' => 'LLM Client not available.', 'data' => null ];
        }

        /* Transient cache: 15 min for single templates, 5 min for lists/categories.
         * Skip cache entirely for ?preview=1 requests so template_json/prompt is always fresh. */
        $is_preview = ! empty( $params['preview'] );
        $cache_key  = 'bzt_qr_gw_' . md5( $path . serialize( $params ) );
        if ( ! $is_preview ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $llm = BizCity_LLM_Client::instance();
        if ( ! $llm->is_ready() ) {
            return [ 'success' => false, '_degraded' => true, 'message' => 'Gateway API key not configured.', 'data' => null ];
        }

        $url = rtrim( $llm->get_gateway_url(), '/' ) . $path;
        if ( $params ) {
            $url = add_query_arg( $params, $url );
        }

        $response = wp_remote_get( $url, [
            'timeout' => 15,
            'headers' => [
                'Authorization' => 'Bearer ' . $llm->get_api_key(),
                'Accept'        => 'application/json',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success'   => false,
                '_degraded' => true,
                'message'   => $response->get_error_message(),
                'data'      => null,
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code !== 200 || json_last_error() !== JSON_ERROR_NONE ) {
            return [
                'success'   => false,
                '_degraded' => true,
                'message'   => 'Gateway error (HTTP ' . $code . ').',
                'data'      => null,
            ];
        }

        /* Cache successful responses (skip for preview requests) */
        if ( ! $is_preview ) {
            $ttl = preg_match( '#/qr-templates/\d+#', $path ) ? 15 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS;
            set_transient( $cache_key, $json, $ttl );
        }

        return $json;
    }

    /* ─── AJAX: Upload QR image to WP Media ─── */

    public static function ajax_upload_image(): void {
        check_ajax_referer( 'bztimg_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Yêu cầu đăng nhập.' ] );
        }

        if ( empty( $_FILES['file'] ) || (int) $_FILES['file']['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => 'Tải file thất bại.' ] );
        }

        /* Validate MIME via finfo (not just extension) */
        $allowed = [ 'image/jpeg', 'image/png', 'image/webp' ];
        $finfo   = new finfo( FILEINFO_MIME_TYPE );
        $mime    = $finfo->file( $_FILES['file']['tmp_name'] );

        if ( ! in_array( $mime, $allowed, true ) ) {
            wp_send_json_error( [ 'message' => 'Chỉ chấp nhận JPG, PNG, WebP.' ] );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => $attachment_id->get_error_message() ] );
        }

        wp_send_json_success( [
            'attachment_id' => $attachment_id,
            'url'           => wp_get_attachment_url( $attachment_id ),
        ] );
    }
}
