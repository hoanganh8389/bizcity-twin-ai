 <?php
/**
 * REST API — Design Editor asset endpoints (shapes, frames, fonts, texts, images, suggestions).
 *
 * Namespace: image-editor/v1  (clean, marketplace-ready)
 *
 * Architecture:
 *   - Local DB → serve directly
 *   - Local DB empty + hub URL configured → transparent proxy to hub (e.g. bizcity.vn)
 *   - User uploads → always local (never proxied)
 *   - Thumbnails: attachment_id (WP Media) → clean URL, fallback to img_url
 *
 * Provides the exact JSON format that canva-editor v1.0.69 expects:
 *   { "data": [ ... ] }   with pagination via ?ps=&pi=&kw=
 *
 * @package BizCity_Tool_Image
 * @since   3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_REST_API_Editor_Assets {

    const NS = 'image-editor/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /* ═══════════════════════════ ROUTE REGISTRATION ═══════════════════════════ */

    public static function register_routes() {

        $search_args = array(
            'ps' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30 ),
            'pi' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ),
            'kw' => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
        );

        $suggestion_args = array(
            'kw' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
        );

        /* ── Shapes ── */
        register_rest_route( self::NS, '/shapes', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_shapes' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );
        register_rest_route( self::NS, '/shape-suggestion', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'suggest_shapes' ),
            'permission_callback' => '__return_true',
            'args'                => $suggestion_args,
        ) );

        /* ── Frames ── */
        register_rest_route( self::NS, '/frames', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_frames' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );
        register_rest_route( self::NS, '/frame-suggestion', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'suggest_frames' ),
            'permission_callback' => '__return_true',
            'args'                => $suggestion_args,
        ) );

        /* ── Text presets ── */
        register_rest_route( self::NS, '/text-presets', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_texts' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );
        register_rest_route( self::NS, '/text-suggestion', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'suggest_texts' ),
            'permission_callback' => '__return_true',
            'args'                => $suggestion_args,
        ) );

        /* ── Images (stock/collection — hub-proxied) ── */
        register_rest_route( self::NS, '/stock-images', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_images_collection' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );
        register_rest_route( self::NS, '/image-collection', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_images_collection' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );
        register_rest_route( self::NS, '/image-suggestion', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'suggest_images' ),
            'permission_callback' => '__return_true',
            'args'                => $suggestion_args,
        ) );

        /* ── Fonts (override the old hardcoded endpoint) ── */
        register_rest_route( self::NS, '/fonts', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_fonts' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );

        /* ── Templates (canva-editor format) ── */
        register_rest_route( self::NS, '/editor-templates', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'search_editor_templates' ),
            'permission_callback' => '__return_true',
            'args'                => $search_args,
        ) );
        register_rest_route( self::NS, '/template-suggestion', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'suggest_templates' ),
            'permission_callback' => '__return_true',
            'args'                => $suggestion_args,
        ) );

        /* ── User uploads ── */
        register_rest_route( self::NS, '/user-images', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_user_images' ),
            'permission_callback' => '__return_true',  // Public — returns empty if not logged in
        ) );
        register_rest_route( self::NS, '/user-images/upload', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'upload_user_image' ),
            'permission_callback' => '__return_true',  // Auth checked inside callback via nonce
        ) );
        register_rest_route( self::NS, '/user-images/remove/(?P<id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( __CLASS__, 'remove_user_image' ),
            'permission_callback' => '__return_true',  // Auth checked inside callback via nonce
        ) );

        /* ── Import (admin only) ── */
        register_rest_route( self::NS, '/editor-assets/import', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'import_asset_json' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Clear (admin only) ── */
        register_rest_route( self::NS, '/editor-assets/clear', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'clear_assets' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Counts (admin only) ── */
        register_rest_route( self::NS, '/editor-assets/counts', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_counts' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Attach Media thumbnails (admin only) ── */
        register_rest_route( self::NS, '/editor-assets/attach-media', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'attach_media' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Editor Template CRUD (admin only) ── */
        register_rest_route( self::NS, '/editor-templates/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_editor_template' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( __CLASS__, 'update_editor_template' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_editor_template' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
        ) );

        /* ── Seed from mock-api (admin only) ── */
        register_rest_route( self::NS, '/editor-assets/seed', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'seed_from_mock_api' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Image proxy (CORS bypass for media CDN) ── */
        register_rest_route( self::NS, '/image-proxy', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'proxy_image' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'url' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'esc_url_raw' ),
            ),
        ) );

        /* ── Image proxy v2 — base64 path (bypass Cloudflare WAF on ?url= param) ── */
        register_rest_route( self::NS, '/img/(?P<b64>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'proxy_image_b64' ),
            'permission_callback' => '__return_true',
        ) );

        /* ── Save to WP Media Library ── */
        register_rest_route( self::NS, '/save-to-media', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'save_to_media' ),
            'permission_callback' => 'is_user_logged_in',
        ) );

        /* ══ Phase 3.6 — AI Template Generation ══ */
        register_rest_route( self::NS, '/ai/vision-to-template', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'ai_vision_to_template' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );
        register_rest_route( self::NS, '/ai/generate-variations', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'ai_generate_variations' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );
        register_rest_route( self::NS, '/ai/skeletons', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'ai_list_skeletons' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );
        register_rest_route( self::NS, '/ai/save-template', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'ai_save_template' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );
        register_rest_route( self::NS, '/ai/remove-bg', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'ai_remove_bg' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
        register_rest_route( self::NS, '/ai/task-status/(?P<task_id>[A-Za-z0-9_-]+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'ai_task_status' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
        register_rest_route( self::NS, '/ai/text-suggest', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'ai_text_suggest' ),
            'permission_callback' => 'is_user_logged_in',
        ) );
    }

    /* ═══════════════════════════ SHAPES ═══════════════════════════ */

    public static function search_shapes( $request ) {
        return self::hub_proxy_or_local( 'bztimg_editor_shapes', $request, function( $row ) {
            return array(
                'clipPath'   => $row->clip_path,
                'desc'       => $row->description,
                'background' => $row->background,
                'width'      => (int) $row->width,
                'height'     => (int) $row->height,
                'img'        => array(
                    'url'    => self::resolve_thumb_url( $row ),
                    'width'  => (int) $row->width,
                    'height' => (int) $row->height,
                ),
            );
        } );
    }

    public static function suggest_shapes( $request ) {
        return self::hub_proxy_or_local_suggestion( 'bztimg_editor_shapes', $request );
    }

    /* ═══════════════════════════ FRAMES ═══════════════════════════ */

    public static function search_frames( $request ) {
        return self::hub_proxy_or_local( 'bztimg_editor_frames', $request, function( $row ) {
            return array(
                'clipPath' => $row->clip_path,
                'desc'     => $row->description,
                'width'    => (int) $row->width,
                'height'   => (int) $row->height,
                'img'      => array(
                    'url'    => self::resolve_thumb_url( $row ),
                    'width'  => (int) $row->width,
                    'height' => (int) $row->height,
                ),
            );
        } );
    }

    public static function suggest_frames( $request ) {
        return self::hub_proxy_or_local_suggestion( 'bztimg_editor_frames', $request );
    }

    /* ═══════════════════════════ TEXT PRESETS ═══════════════════════════ */

    public static function search_texts( $request ) {
        return self::hub_proxy_or_local( 'bztimg_editor_text_presets', $request, function( $row ) {
            $data_str = self::rewrite_localhost_urls( $row->data_json );
            return array(
                'desc'   => $row->description,
                'data'   => json_decode( $data_str, true ),
                'width'  => (int) $row->width,
                'height' => (int) $row->height,
                'img'    => array(
                    'url'    => self::resolve_thumb_url( $row ),
                    'width'  => (int) $row->width,
                    'height' => (int) $row->height,
                ),
            );
        } );
    }

    public static function suggest_texts( $request ) {
        return self::hub_proxy_or_local_suggestion( 'bztimg_editor_text_presets', $request );
    }

    /* ═══════════════════════════ IMAGES (stock collection) ═══════════════════════════ */

    public static function search_images_collection( $request ) {
        // Try hub proxy first
        $hub = self::proxy_to_hub( $request );
        if ( $hub ) return $hub;

        // Fallback: serve images from WP Media Library
        $ps = max( 1, (int) $request->get_param( 'ps' ) ?: 30 );
        $pi = max( 0, (int) $request->get_param( 'pi' ) ?: 0 );
        $kw = $request->get_param( 'kw' ) ?: '';

        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'posts_per_page' => $ps,
            'offset'         => $pi * $ps,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );
        if ( $kw ) {
            $args['s'] = $kw;
        }

        $attachments = get_posts( $args );
        $data = array();
        foreach ( $attachments as $att ) {
            $url   = wp_get_attachment_url( $att->ID );
            $meta  = wp_get_attachment_metadata( $att->ID );
            $thumb = wp_get_attachment_image_url( $att->ID, 'medium' );
            $data[] = array(
                'id'         => $att->ID,
                'documentId' => (string) $att->ID,
                'img'        => array(
                    'url'    => self::to_proxy_url( $url ),
                    'thumb'  => self::to_proxy_url( $thumb ? $thumb : $url ),
                    'mime'   => $att->post_mime_type,
                    'width'  => (int) ( $meta['width'] ?? 256 ),
                    'height' => (int) ( $meta['height'] ?? 256 ),
                ),
            );
        }

        return new \WP_REST_Response( array( 'data' => $data ), 200 );
    }

    public static function suggest_images( $request ) {
        $kw = strtolower( $request->get_param( 'kw' ) ?: '' );
        $defaults = array( 'animal', 'sport', 'love', 'scene', 'nature', 'model', 'christmas', 'birthday' );
        $matches  = array();
        $id = 1;
        foreach ( $defaults as $d ) {
            if ( empty( $kw ) || strpos( $d, $kw ) !== false ) {
                $matches[] = array( 'id' => $id++, 'name' => $d );
            }
        }
        return new \WP_REST_Response( $matches, 200 );
    }

    /* ═══════════════════════════ FONTS ═══════════════════════════ */

    public static function search_fonts( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_fonts';

        // Check local count for hub proxy
        $local_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $local_count === 0 ) {
            $hub = self::proxy_to_hub( $request );
            if ( $hub ) return $hub;
        }

        $ps    = max( 1, (int) $request->get_param( 'ps' ) ?: 30 );
        $pi    = max( 0, (int) $request->get_param( 'pi' ) ?: 0 );
        $kw    = $request->get_param( 'kw' ) ?: '';

        $where = '1=1';
        $params = array();
        if ( $kw ) {
            $where   .= ' AND family LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $kw ) . '%';
        }

        $offset = $pi * $ps;
        $query  = "SELECT * FROM {$table} WHERE {$where} ORDER BY sort_order ASC, family ASC LIMIT %d OFFSET %d";
        $params[] = $ps;
        $params[] = $offset;

        $rows = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        $data = array();
        foreach ( $rows as $row ) {
            $data[] = array(
                'family' => $row->family,
                'styles' => json_decode( $row->styles_json, true ) ?: array(),
            );
        }

        return new \WP_REST_Response( array( 'data' => $data ), 200 );
    }

    /* ═══════════════════════════ EDITOR TEMPLATES ═══════════════════════════ */

    public static function search_editor_templates( $request ) {
        return self::hub_proxy_or_local( 'bztimg_editor_templates', $request, function( $row ) {
            $data_str = self::rewrite_cdn_in_json( self::rewrite_localhost_urls( $row->data_json ) );
            $thumb    = self::resolve_thumb_url( $row );

            // Scale dimensions to sidebar-friendly thumbnail size (max 320px wide).
            $w = (int) $row->width  ?: 256;
            $h = (int) $row->height ?: 256;
            $max_w = 320;
            if ( $w > $max_w ) {
                $h = (int) round( $h * $max_w / $w );
                $w = $max_w;
            }

            // Decode stored JSON (verbose format: { name, notes, layers: { ROOT, … } }).
            $decoded = json_decode( $data_str, true );

            // Convert verbose → minified format matching lidojs Xb key map,
            // and wrap single page in array (editor expects Array<SerializedPage>).
            if ( is_array( $decoded ) ) {
                $decoded = self::pack_page_data( $decoded );
                // Wrap single page object in array if not already an array of pages.
                if ( isset( $decoded['a'] ) || isset( $decoded['c'] ) ) {
                    // Already-packed single page: { a, b, c } → wrap in array.
                    $decoded = array( $decoded );
                } elseif ( isset( $decoded['name'] ) || isset( $decoded['layers'] ) ) {
                    // Verbose single page that pack_page_data missed (shouldn't happen).
                    $decoded = array( $decoded );
                }
                // If it's already an array of pages (numeric keys), keep as-is.
            }

            return array(
                'desc'  => $row->description,
                'data'  => $decoded,
                'pages' => (int) $row->pages,
                'img'   => array(
                    'url'    => $thumb,
                    'width'  => $w,
                    'height' => $h,
                ),
            );
        } );
    }

    public static function suggest_templates( $request ) {
        return self::hub_proxy_or_local_suggestion( 'bztimg_editor_templates', $request );
    }

    /* ═══════════════════════════ USER UPLOADS ═══════════════════════════ */

    public static function get_user_images( $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new \WP_REST_Response( array( 'data' => array() ), 200 );
        }
        $args = array(
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_status'    => 'inherit',
            'author'         => $user_id,
            'posts_per_page' => 50,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $attachments = get_posts( $args );
        $images = array();
        foreach ( $attachments as $att ) {
            $url   = wp_get_attachment_url( $att->ID );
            $meta  = wp_get_attachment_metadata( $att->ID );
            $thumb = wp_get_attachment_image_url( $att->ID, 'medium' );
            $images[] = array(
                'id'         => $att->ID,
                'documentId' => (string) $att->ID,
                'img'        => array(
                    'url'    => self::to_proxy_url( $url ),
                    'thumb'  => self::to_proxy_url( $thumb ? $thumb : $url ),
                    'mime'   => $att->post_mime_type,
                    'width'  => (int) ( $meta['width'] ?? 256 ),
                    'height' => (int) ( $meta['height'] ?? 256 ),
                ),
            );
        }

        return new \WP_REST_Response( array( 'data' => $images ), 200 );
    }

    public static function upload_user_image( $request ) {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }
        $files = $request->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new \WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return new \WP_Error( 'upload_failed', $attachment_id->get_error_message(), array( 'status' => 500 ) );
        }

        update_post_meta( $attachment_id, '_bztimg_editor_upload', '1' );

        $url   = wp_get_attachment_url( $attachment_id );
        $meta  = wp_get_attachment_metadata( $attachment_id );
        $thumb = wp_get_attachment_image_url( $attachment_id, 'medium' );
        $post  = get_post( $attachment_id );

        // canva-editor expects an array of uploaded items
        return new \WP_REST_Response( array(
            'data' => array(
                array(
                    'id'         => $attachment_id,
                    'documentId' => (string) $attachment_id,
                    'img'        => array(
                        'url'    => self::to_proxy_url( $url ),
                        'thumb'  => self::to_proxy_url( $thumb ? $thumb : $url ),
                        'mime'   => $post ? $post->post_mime_type : 'image/png',
                        'width'  => (int) ( $meta['width'] ?? 256 ),
                        'height' => (int) ( $meta['height'] ?? 256 ),
                    ),
                ),
            ),
        ), 200 );
    }

    public static function remove_user_image( $request ) {
        if ( ! is_user_logged_in() ) {
            return new \WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }
        $id      = (int) $request['id'];
        $user_id = get_current_user_id();
        $post    = get_post( $id );

        if ( ! $post || (int) $post->post_author !== $user_id ) {
            return new \WP_Error( 'not_found', 'Image not found.', array( 'status' => 404 ) );
        }

        wp_delete_attachment( $id, true );
        return new \WP_REST_Response( array( 'success' => true ), 200 );
    }

    /* ═══════════════════════════ EDITOR TEMPLATE CRUD ═══════════════════════════ */

    public static function get_editor_template( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_templates';
        $id    = (int) $request['id'];

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            return new \WP_Error( 'not_found', 'Template not found.', array( 'status' => 404 ) );
        }

        return new \WP_REST_Response( array(
            'id'          => (int) $row->id,
            'description' => $row->description,
            'data_json'   => self::rewrite_cdn_in_json( self::rewrite_localhost_urls( $row->data_json ) ),
            'pages'       => (int) $row->pages,
            'width'       => (int) $row->width,
            'height'      => (int) $row->height,
            'img_url'     => self::resolve_thumb_url( $row ),
            'attachment_id' => (int) ( $row->attachment_id ?? 0 ),
            'sort_order'  => (int) $row->sort_order,
            'created_at'  => $row->created_at,
        ), 200 );
    }

    public static function update_editor_template( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_templates';
        $id    = (int) $request['id'];

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            return new \WP_Error( 'not_found', 'Template not found.', array( 'status' => 404 ) );
        }

        $update = array();
        $format = array();

        $params = $request->get_json_params();

        if ( isset( $params['description'] ) ) {
            $update['description'] = sanitize_text_field( $params['description'] );
            $format[] = '%s';
        }
        if ( isset( $params['data_json'] ) ) {
            $data_str = is_string( $params['data_json'] ) ? $params['data_json'] : wp_json_encode( $params['data_json'] );
            $update['data_json']    = $data_str;
            $update['content_hash'] = md5( $data_str );
            $format[] = '%s';
            $format[] = '%s';
        }
        if ( isset( $params['pages'] ) ) {
            $update['pages'] = (int) $params['pages'];
            $format[] = '%d';
        }
        if ( isset( $params['width'] ) ) {
            $update['width'] = (int) $params['width'];
            $format[] = '%d';
        }
        if ( isset( $params['height'] ) ) {
            $update['height'] = (int) $params['height'];
            $format[] = '%d';
        }
        if ( isset( $params['img_url'] ) ) {
            $update['img_url'] = esc_url_raw( $params['img_url'] );
            $format[] = '%s';
        }
        if ( isset( $params['attachment_id'] ) ) {
            $update['attachment_id'] = (int) $params['attachment_id'];
            $format[] = '%d';
        }
        if ( isset( $params['sort_order'] ) ) {
            $update['sort_order'] = (int) $params['sort_order'];
            $format[] = '%d';
        }

        if ( empty( $update ) ) {
            return new \WP_Error( 'no_data', 'Nothing to update.', array( 'status' => 400 ) );
        }

        $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

        return self::get_editor_template( $request );
    }

    public static function delete_editor_template( $request ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_templates';
        $id    = (int) $request['id'];

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
        if ( ! $row ) {
            return new \WP_Error( 'not_found', 'Template not found.', array( 'status' => 404 ) );
        }

        $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
        return new \WP_REST_Response( array( 'success' => true, 'deleted' => $id ), 200 );
    }

    /* ═══════════════════════════ SEED FROM MOCK-API ═══════════════════════════ */

    public static function seed_from_mock_api( $request ) {
        if ( ! function_exists( 'bztimg_seed_all_editor_assets' ) ) {
            return new \WP_Error( 'missing_function', 'Seed function not available.', array( 'status' => 500 ) );
        }

        // Increase limits for batch media upload
        @set_time_limit( 300 );

        $result = bztimg_seed_all_editor_assets();
        $total  = array_sum( $result );

        return new \WP_REST_Response( array(
            'success' => true,
            'seeded'  => $result,
            'total'   => $total,
        ), 200 );
    }

    /* ═══════════════════════════ JSON IMPORT ═══════════════════════════ */

    public static function import_asset_json( $request ) {
        $type = sanitize_text_field( $request->get_param( 'type' ) );
        $json = $request->get_param( 'data' );

        if ( ! in_array( $type, array( 'shapes', 'frames', 'fonts', 'texts', 'templates' ), true ) ) {
            return new \WP_Error( 'invalid_type', 'Type must be: shapes, frames, fonts, texts, templates', array( 'status' => 400 ) );
        }

        if ( ! is_array( $json ) ) {
            return new \WP_Error( 'invalid_data', 'data must be an array', array( 'status' => 400 ) );
        }

        $imported = 0;
        $errors   = 0;

        switch ( $type ) {
            case 'shapes':
                $imported = self::import_shapes( $json, $errors );
                break;
            case 'frames':
                $imported = self::import_frames( $json, $errors );
                break;
            case 'fonts':
                $imported = self::import_fonts( $json, $errors );
                break;
            case 'texts':
                $imported = self::import_texts( $json, $errors );
                break;
            case 'templates':
                $imported = self::import_templates( $json, $errors );
                break;
        }

        return new \WP_REST_Response( array(
            'type'     => $type,
            'imported' => $imported,
            'errors'   => $errors,
        ), 200 );
    }

    /* ═══════════════════════════ IMPORT HELPERS ═══════════════════════════ */

    private static function import_shapes( array $items, int &$errors ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_shapes';
        $count = 0;

        foreach ( $items as $i => $item ) {
            if ( empty( $item['clipPath'] ) ) { $errors++; continue; }

            // Dedup: hash on clipPath content
            $hash = md5( $item['clipPath'] );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
            ) );
            if ( $exists ) continue; // skip duplicate silently

            $img_url = '';
            if ( ! empty( $item['img']['url'] ) ) {
                $img_url = self::normalize_asset_url( $item['img']['url'] );
            }

            $ok = $wpdb->insert( $table, array(
                'clip_path'    => $item['clipPath'],
                'description'  => sanitize_text_field( $item['desc'] ?? '' ),
                'background'   => sanitize_text_field( $item['background'] ?? 'rgb(0,0,0)' ),
                'width'        => (int) ( $item['width'] ?? $item['img']['width'] ?? 256 ),
                'height'       => (int) ( $item['height'] ?? $item['img']['height'] ?? 256 ),
                'img_url'      => esc_url_raw( $img_url ),
                'content_hash' => $hash,
                'sort_order'   => $i,
            ), array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d' ) );

            $ok ? $count++ : $errors++;
        }

        return $count;
    }

    private static function import_frames( array $items, int &$errors ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_frames';
        $count = 0;

        foreach ( $items as $i => $item ) {
            if ( empty( $item['clipPath'] ) ) { $errors++; continue; }

            $hash = md5( $item['clipPath'] );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
            ) );
            if ( $exists ) continue;

            $img_url = '';
            if ( ! empty( $item['img']['url'] ) ) {
                $img_url = self::normalize_asset_url( $item['img']['url'] );
            }

            $ok = $wpdb->insert( $table, array(
                'clip_path'    => $item['clipPath'],
                'description'  => sanitize_text_field( $item['desc'] ?? '' ),
                'width'        => (int) ( $item['width'] ?? $item['img']['width'] ?? 256 ),
                'height'       => (int) ( $item['height'] ?? $item['img']['height'] ?? 256 ),
                'img_url'      => esc_url_raw( $img_url ),
                'content_hash' => $hash,
                'sort_order'   => $i,
            ), array( '%s', '%s', '%d', '%d', '%s', '%s', '%d' ) );

            $ok ? $count++ : $errors++;
        }

        return $count;
    }

    private static function import_fonts( array $items, int &$errors ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_fonts';
        $count = 0;

        foreach ( $items as $i => $item ) {
            if ( empty( $item['family'] ) ) { $errors++; continue; }

            $family = sanitize_text_field( $item['family'] );
            // Dedup: unique on family name
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE family = %s LIMIT 1", $family
            ) );
            if ( $exists ) continue;

            $styles = $item['styles'] ?? array();

            $ok = $wpdb->insert( $table, array(
                'family'      => $family,
                'styles_json' => wp_json_encode( $styles ),
                'sort_order'  => $i,
            ), array( '%s', '%s', '%d' ) );

            $ok ? $count++ : $errors++;
        }

        return $count;
    }

    private static function import_texts( array $items, int &$errors ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_text_presets';
        $count = 0;

        foreach ( $items as $i => $item ) {
            $data = $item['data'] ?? null;
            if ( ! $data ) { $errors++; continue; }

            $data_str = is_string( $data ) ? $data : wp_json_encode( $data );
            $hash = md5( $data_str );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
            ) );
            if ( $exists ) continue;

            $img_url = '';
            if ( ! empty( $item['img']['url'] ) ) {
                $img_url = self::normalize_asset_url( $item['img']['url'] );
            }

            $ok = $wpdb->insert( $table, array(
                'description'  => sanitize_text_field( $item['desc'] ?? '' ),
                'data_json'    => $data_str,
                'width'        => (int) ( $item['width'] ?? 256 ),
                'height'       => (int) ( $item['height'] ?? 256 ),
                'img_url'      => esc_url_raw( $img_url ),
                'content_hash' => $hash,
                'sort_order'   => $i,
            ), array( '%s', '%s', '%d', '%d', '%s', '%s', '%d' ) );

            $ok ? $count++ : $errors++;
        }

        return $count;
    }

    private static function import_templates( array $items, int &$errors ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_editor_templates';
        $count = 0;

        foreach ( $items as $i => $item ) {
            // Support 2 formats:
            // Format A (wrapped):  { "desc": "...", "data": { layers... }, "img": {...} }
            // Format B (native canva-editor export): { "name": "...", "layers": { "ROOT": {...}, ... } }
            if ( isset( $item['layers'] ) && ! isset( $item['data'] ) ) {
                $desc = $item['name'] ?? $item['notes'] ?? '';
                $data = $item; // entire item IS the template data
                $img  = array();
                // Extract boxSize from ROOT layer for dimensions
                if ( isset( $item['layers']['ROOT']['props']['boxSize'] ) ) {
                    $box = $item['layers']['ROOT']['props']['boxSize'];
                    $img['width']  = $box['width'] ?? 256;
                    $img['height'] = $box['height'] ?? 256;
                }
            } else {
                $data = $item['data'] ?? null;
                $desc = $item['desc'] ?? '';
                $img  = $item['img'] ?? array();
            }

            if ( ! $data ) { $errors++; continue; }

            $data_str = is_string( $data ) ? $data : wp_json_encode( $data );
            $hash = md5( $data_str );
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE content_hash = %s LIMIT 1", $hash
            ) );
            if ( $exists ) continue;

            $img_url = '';
            if ( ! empty( $img['url'] ) ) {
                $img_url = self::normalize_asset_url( $img['url'] );
            }

            $pages = $item['pages'] ?? 1;

            $ok = $wpdb->insert( $table, array(
                'description'  => sanitize_text_field( $desc ),
                'data_json'    => $data_str,
                'pages'        => (int) $pages,
                'width'        => (int) ( $img['width'] ?? 256 ),
                'height'       => (int) ( $img['height'] ?? 256 ),
                'img_url'      => esc_url_raw( $img_url ),
                'content_hash' => $hash,
                'sort_order'   => $i,
            ), array( '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d' ) );

            $ok ? $count++ : $errors++;
        }

        return $count;
    }

    /**
     * Rewrite localhost:4000 URLs in template/text data to production-accessible paths.
     * http://localhost:4000/images/... → {BZTIMG_URL}design-editor-build/mock-api/public/images/...
     * http://localhost:4000/fonts/...  → {BZTIMG_URL}design-editor-build/mock-api/public/fonts/...
     */
    private static function rewrite_localhost_urls( $data ) {
        if ( empty( $data ) ) return $data;
        $json = is_string( $data ) ? $data : wp_json_encode( $data );

        // Replace localhost:4000 URLs with local mock-api public path.
        if ( strpos( $json, 'localhost:4000' ) !== false ) {
            $base_url         = BZTIMG_URL . 'design-editor-build/mock-api/public/';
            $escaped_base_url = str_replace( '/', '\\/', $base_url );
            // Replace JSON-escaped URLs: http:\/\/localhost:4000\/
            $json = str_replace( 'http:\\/\\/localhost:4000\\/', $escaped_base_url, $json );
            // Replace unescaped URLs
            $json = str_replace( 'http://localhost:4000/', $base_url, $json );
        }

        // Upgrade http:// font URLs to https:// to avoid Mixed Content blocks.
        $json = str_replace( 'http:\\/\\/fonts.gstatic.com\\/', 'https:\\/\\/fonts.gstatic.com\\/', $json );
        $json = str_replace( 'http://fonts.gstatic.com/',       'https://fonts.gstatic.com/',       $json );
        $json = str_replace( 'http:\\/\\/fonts.googleapis.com\\/', 'https:\\/\\/fonts.googleapis.com\\/', $json );
        $json = str_replace( 'http://fonts.googleapis.com/',       'https://fonts.googleapis.com/',       $json );

        return is_string( $data ) ? $json : json_decode( $json, true );
    }

    /**
     * Normalize imported asset URLs (keep as-is for marketplace model).
     */
    private static function normalize_asset_url( string $url ): string {
        return $url;
    }

    /**
     * Minified key map matching lidojs built-in Xb mapping.
     * Converts verbose keys (name, notes, layers, …) to single-letter keys (a, b, c, …)
     * so the editor's `unpack()` / `bd()` can deserialize them.
     */
    private static $XB_MAP = array(
        'name'                => 'a',
        'notes'               => 'b',
        'layers'              => 'c',
        'ROOT'                => 'd',
        'type'                => 'e',
        'resolvedName'        => 'f',
        'props'               => 'g',
        'boxSize'             => 'h',
        'width'               => 'i',
        'height'              => 'j',
        'position'            => 'k',
        'x'                   => 'l',
        'y'                   => 'm',
        'rotate'              => 'n',
        'color'               => 'o',
        'image'               => 'p',
        'gradientBackground'  => 'q',
        'locked'              => 'r',
        'child'               => 's',
        'parent'              => 't',
        'scale'               => 'u',
        'text'                => 'v',
        'fonts'               => 'w',
        'family'              => 'x',
        'url'                 => 'y',
        'style'               => 'z',
        'styles'              => 'aa',
        'colors'              => 'ab',
        'fontSizes'           => 'ac',
        'effect'              => 'ad',
        'settings'            => 'ae',
        'thickness'           => 'af',
        'transparency'        => 'ag',
        'clipPath'            => 'ah',
        'shapeSize'           => 'ai',
        'thumb'               => 'aj',
        'offset'              => 'ak',
        'direction'           => 'al',
        'blur'                => 'am',
        'border'              => 'an',
        'weight'              => 'ao',
        'roundedCorners'      => 'ap',
    );

    /**
     * Recursively minify (pack) verbose lidojs data using the Xb key map.
     * Converts { "name": "", "layers": { "ROOT": … } } → { "a": "", "c": { "d": … } }
     *
     * @param mixed $data Decoded verbose page data.
     * @return mixed Minified data ready for the editor.
     */
    private static function pack_page_data( $data ) {
        if ( ! is_array( $data ) ) return $data;

        // Sequential (numeric) array — recurse into each element.
        if ( array_values( $data ) === $data ) {
            return array_map( array( __CLASS__, 'pack_page_data' ), $data );
        }

        // Associative array — remap keys.
        $out = array();
        foreach ( $data as $key => $value ) {
            $mapped = self::$XB_MAP[ $key ] ?? $key;
            $out[ $mapped ] = self::pack_page_data( $value );
        }
        return $out;
    }

    /* ═══════════════════════════ SHARED HELPERS ═══════════════════════════ */

    /**
     * Resolve thumbnail URL from a DB row.
     * Priority: attachment_id → WP attachment URL → img_url field.
     */
    private static function resolve_thumb_url( $row ): string {
        if ( ! empty( $row->attachment_id ) ) {
            $att_url = wp_get_attachment_url( (int) $row->attachment_id );
            if ( $att_url ) return self::to_proxy_url( $att_url );
        }
        $url = $row->img_url ?? '';
        if ( $url && strpos( $url, 'localhost:4000' ) !== false ) {
            $url = str_replace( 'http://localhost:4000/', BZTIMG_URL . 'design-editor-build/mock-api/public/', $url );
        }
        $result = self::to_proxy_url( $url );
        // Fallback: transparent placeholder SVG when no thumbnail available
        if ( empty( $result ) ) {
            $result = 'data:image/svg+xml,' . rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="100%" height="100%" fill="#e2e8f0"/><text x="50%" y="50%" text-anchor="middle" dy=".3em" fill="#94a3b8" font-size="14">No preview</text></svg>' );
        }
        return $result;
    }

    /**
     * Hub proxy: forward request to hub site when local DB is empty.
     * Returns WP_REST_Response on success, null if no hub configured.
     * Validates hub schema compatibility before proxying.
     */
    private static function proxy_to_hub( $request ) {
        $hub_url = get_option( 'bztimg_editor_hub_url', '' );
        if ( empty( $hub_url ) ) return null;

        // Schema compatibility check (cached 1 hour)
        $compat_key = 'bztimg_hub_compat_' . md5( $hub_url );
        $compat = get_transient( $compat_key );
        if ( $compat === 'incompatible' ) return null;
        if ( $compat === false ) {
            // First call or cache expired — check hub schema version
            $check_url = rtrim( $hub_url, '/' ) . '/wp-json/' . self::NS . '/editor-assets/counts';
            $check_resp = wp_remote_get( $check_url, array( 'timeout' => 5 ) );
            if ( ! is_wp_error( $check_resp ) ) {
                $check_body = json_decode( wp_remote_retrieve_body( $check_resp ), true );
                $hub_schema = $check_body['schema_version'] ?? '0';
                $local_schema = defined( 'BZTIMG_SCHEMA_VERSION' ) ? BZTIMG_SCHEMA_VERSION : '0';
                if ( version_compare( $hub_schema, '5.0', '<' ) ) {
                    set_transient( $compat_key, 'incompatible', HOUR_IN_SECONDS );
                    return null;
                }
            }
            set_transient( $compat_key, 'ok', HOUR_IN_SECONDS );
        }

        // Build remote URL
        $route = $request->get_route();
        $route = preg_replace( '#^/' . preg_quote( self::NS, '#' ) . '#', '', $route );
        $params = $request->get_query_params();
        $remote = rtrim( $hub_url, '/' ) . '/wp-json/' . self::NS . $route;
        if ( $params ) {
            $remote .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $remote, array(
            'timeout' => 10,
            'headers' => array( 'Accept' => 'application/json' ),
        ) );

        if ( is_wp_error( $response ) ) return null;

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        // Rewrite media CDN URLs in hub response to proxy URLs for CORS
        $body = self::rewrite_cdn_urls_recursive( $body ?: array( 'data' => array() ) );
        return new \WP_REST_Response( $body, 200 );
    }

    /**
     * Recursively rewrite media.bizcity.vn URLs in 'url' and 'thumb' keys to proxy URLs.
     * Rewrites both 'url' and 'thumb' fields inside 'img' arrays.
     */
    private static function rewrite_cdn_urls_recursive( $data, $inside_img = false ) {
        if ( ! is_array( $data ) ) return $data;
        $result = array();
        foreach ( $data as $key => $value ) {
            if ( is_array( $value ) ) {
                $result[ $key ] = self::rewrite_cdn_urls_recursive( $value, $key === 'img' || $inside_img );
            } elseif ( $inside_img && in_array( $key, array( 'url', 'thumb' ), true ) && is_string( $value ) ) {
                $result[ $key ] = self::to_proxy_url( $value );
            } else {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }

    /**
     * Hub-aware paginated search: serve local → or proxy to hub.
     */
    private static function hub_proxy_or_local( string $table_suffix, $request, callable $mapper ) {
        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;

        $local_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $local_count === 0 ) {
            $hub = self::proxy_to_hub( $request );
            if ( $hub ) return $hub;
        }

        return self::paginated_search( $table_suffix, $request, $mapper );
    }

    /**
     * Hub-aware keyword suggestion: serve local → or proxy to hub.
     */
    private static function hub_proxy_or_local_suggestion( string $table_suffix, $request ) {
        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;

        $local_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $local_count === 0 ) {
            $hub = self::proxy_to_hub( $request );
            if ( $hub ) return $hub;
        }

        return self::keyword_suggest( $table_suffix, $request );
    }

    /**
     * Hub proxy for endpoints with no local table (e.g. image-collection).
     */
    private static function hub_proxy_or_empty( $request ) {
        $hub = self::proxy_to_hub( $request );
        if ( $hub ) return $hub;
        return new \WP_REST_Response( array( 'data' => array() ), 200 );
    }

    /**
     * Paginated search for shapes/frames/texts/templates tables.
     * Uses FULLTEXT MATCH AGAINST for keyword search (requires 3+ char keyword),
     * falls back to LIKE for shorter keywords.
     */
    private static function paginated_search( string $table_suffix, $request, callable $mapper ) {
        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;
        $ps    = max( 1, (int) $request->get_param( 'ps' ) ?: 30 );
        $pi    = max( 0, (int) $request->get_param( 'pi' ) ?: 0 );
        $kw    = $request->get_param( 'kw' ) ?: '';

        $where  = '1=1';
        $params = array();
        if ( $kw ) {
            if ( mb_strlen( $kw ) >= 3 ) {
                // FULLTEXT boolean mode — uses the ft_desc index
                $where   .= ' AND MATCH(description) AGAINST(%s IN BOOLEAN MODE)';
                $params[] = '+' . $wpdb->esc_like( $kw ) . '*';
            } else {
                // Short keywords — fallback to LIKE
                $where   .= ' AND description LIKE %s';
                $params[] = '%' . $wpdb->esc_like( $kw ) . '%';
            }
        }

        $offset   = $pi * $ps;
        $params[] = $ps;
        $params[] = $offset;

        $query = "SELECT * FROM {$table} WHERE {$where} ORDER BY sort_order ASC, id ASC LIMIT %d OFFSET %d";
        $rows  = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

        $data = array_map( $mapper, $rows );

        return new \WP_REST_Response( array( 'data' => $data ), 200 );
    }

    /**
     * Keyword suggestion: extracts unique words from description field.
     */
    private static function keyword_suggest( string $table_suffix, $request ) {
        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;
        $kw    = strtolower( $request->get_param( 'kw' ) ?: '' );

        $rows = $wpdb->get_col( "SELECT description FROM {$table} LIMIT 200" );

        $words = array();
        foreach ( $rows as $desc ) {
            foreach ( explode( ' ', strtolower( $desc ) ) as $w ) {
                $w = trim( $w );
                if ( strlen( $w ) > 1 && ! isset( $words[ $w ] ) ) {
                    if ( empty( $kw ) || strpos( $w, $kw ) !== false ) {
                        $words[ $w ] = true;
                    }
                }
            }
        }

        $result = array();
        $id = 1;
        foreach ( array_keys( $words ) as $name ) {
            $result[] = array( 'id' => $id++, 'name' => $name );
            if ( $id > 20 ) break;
        }

        return new \WP_REST_Response( $result, 200 );
    }

    /* ═══════════════════════════ CLEAR / COUNTS ═══════════════════════════ */

    public static function clear_assets( $request ) {
        global $wpdb;
        $type = sanitize_text_field( $request->get_param( 'type' ) );

        $map = array(
            'shapes'    => 'bztimg_editor_shapes',
            'frames'    => 'bztimg_editor_frames',
            'fonts'     => 'bztimg_editor_fonts',
            'texts'     => 'bztimg_editor_text_presets',
            'templates' => 'bztimg_editor_templates',
        );

        if ( ! isset( $map[ $type ] ) ) {
            return new \WP_Error( 'invalid_type', 'Type must be: shapes, frames, fonts, texts, templates', array( 'status' => 400 ) );
        }

        $table   = $wpdb->prefix . $map[ $type ];
        $deleted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        return new \WP_REST_Response( array( 'type' => $type, 'deleted' => $deleted ), 200 );
    }

    public static function get_counts( $request ) {
        global $wpdb;
        return new \WP_REST_Response( array(
            'shapes'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_shapes" ),
            'frames'         => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_frames" ),
            'fonts'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_fonts" ),
            'texts'          => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_text_presets" ),
            'templates'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bztimg_editor_templates" ),
            'schema_version' => defined( 'BZTIMG_SCHEMA_VERSION' ) ? BZTIMG_SCHEMA_VERSION : '0',
        ), 200 );
    }

    /* ═══════════════════════════ ATTACH MEDIA THUMBNAILS ═══════════════════════════ */

    public static function attach_media( $request ) {
        global $wpdb;
        $type        = sanitize_text_field( $request->get_param( 'type' ) );
        $attachments = $request->get_param( 'attachments' );

        $map = array(
            'shapes'    => 'bztimg_editor_shapes',
            'frames'    => 'bztimg_editor_frames',
            'texts'     => 'bztimg_editor_text_presets',
            'templates' => 'bztimg_editor_templates',
        );

        if ( ! isset( $map[ $type ] ) ) {
            return new \WP_Error( 'invalid_type', 'Type must be: shapes, frames, texts, templates', array( 'status' => 400 ) );
        }

        if ( ! is_array( $attachments ) || empty( $attachments ) ) {
            return new \WP_Error( 'invalid_data', 'attachments must be a non-empty array', array( 'status' => 400 ) );
        }

        $table = $wpdb->prefix . $map[ $type ];

        // Find items without thumbnails (attachment_id = 0 AND img_url = '')
        $empty_items = $wpdb->get_col(
            "SELECT id FROM {$table} WHERE attachment_id = 0 AND img_url = '' ORDER BY sort_order ASC, id ASC LIMIT " . count( $attachments )
        );

        $updated = 0;
        foreach ( $attachments as $i => $att ) {
            if ( ! isset( $empty_items[ $i ] ) ) break;
            $att_id = absint( $att['id'] ?? 0 );
            if ( ! $att_id ) continue;

            $wpdb->update( $table,
                array( 'attachment_id' => $att_id ),
                array( 'id' => $empty_items[ $i ] ),
                array( '%d' ),
                array( '%d' )
            );
            $updated++;
        }

        return new \WP_REST_Response( array(
            'type'    => $type,
            'updated' => $updated,
            'total'   => count( $attachments ),
        ), 200 );
    }

    /* ═══════════════════════════ IMAGE PROXY (CORS bypass) ═══════════════════════════ */

    /**
     * Proxy images from media.bizcity.vn to bypass CORS for canva-editor canvas usage.
     * Only allows whitelisted CDN domains.
     */
    public static function proxy_image( $request ) {
        $url = $request->get_param( 'url' );
        if ( empty( $url ) ) {
            return new \WP_Error( 'missing_url', 'URL parameter is required.', array( 'status' => 400 ) );
        }

        // Security: only allow our media CDN domain
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $allowed_hosts = array( 'media.bizcity.vn' );
        if ( ! in_array( $host, $allowed_hosts, true ) ) {
            return new \WP_Error( 'forbidden_host', 'Only media CDN URLs are allowed.', array( 'status' => 403 ) );
        }

        // Security: only allow image paths
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( ! preg_match( '/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i', $path ) ) {
            return new \WP_Error( 'invalid_type', 'Only image files are allowed.', array( 'status' => 400 ) );
        }

        $response = wp_remote_get( $url, array(
            'timeout'   => 30,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error( 'fetch_failed', 'Failed to fetch image.', array( 'status' => 502 ) );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new \WP_Error( 'upstream_error', 'Upstream returned ' . $code, array( 'status' => $code ) );
        }

        $body         = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' ) ?: 'image/png';

        // Clean ALL output buffers (handles aaa-fix-headers.php ob_start())
        while ( ob_get_level() ) {
            ob_end_clean();
        }

        // Send image with CORS headers
        header( 'Content-Type: ' . $content_type );
        header( 'Content-Length: ' . strlen( $body ) );
        header( 'Access-Control-Allow-Origin: *' );
        header( 'Cache-Control: public, max-age=86400, immutable' );
        echo $body;
        exit;
    }

    /**
     * Proxy images using base64-encoded URL in path (bypass Cloudflare WAF on ?url= param).
     * Route: GET /image-editor/v1/img/{base64url}
     */
    public static function proxy_image_b64( $request ) {
        $b64 = $request->get_param( 'b64' );
        // URL-safe base64: replace -_ back to +/
        $url = base64_decode( strtr( $b64, '-_', '+/' ) );
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new \WP_Error( 'invalid_url', 'Invalid encoded URL.', array( 'status' => 400 ) );
        }

        // Reuse the existing proxy logic by injecting url param
        $request->set_param( 'url', $url );
        return self::proxy_image( $request );
    }

    /**
     * Rewrite media.bizcity.vn URLs to server-side proxy route (CORS bypass).
     * Uses base64-encoded URL in path to avoid Cloudflare WAF blocking ?url= param.
     * Route: GET /image-editor/v1/img/{base64url}
     */
    private static function to_proxy_url( string $url ): string {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( $host !== 'media.bizcity.vn' ) {
            return $url;
        }
        // URL-safe base64: replace +/ with -_
        $b64 = rtrim( strtr( base64_encode( $url ), '+/', '-_' ), '=' );
        return rest_url( self::NS . '/img/' . $b64 );
    }

    /**
     * Rewrite all media.bizcity.vn URLs in a JSON string to proxy URLs.
     * Handles both escaped (JSON-encoded) and plain URL formats.
     */
    private static function rewrite_cdn_in_json( $data ) {
        if ( empty( $data ) ) return $data;
        $json = is_string( $data ) ? $data : wp_json_encode( $data );

        // Match media.bizcity.vn URLs in JSON (both escaped and unescaped)
        $json = preg_replace_callback(
            '#https?://media\.bizcity\.vn/[^\s"\\\\]+#',
            function( $m ) {
                $url = $m[0];
                // Only proxy image files
                if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i', wp_parse_url( $url, PHP_URL_PATH ) ?: '' ) ) {
                    return self::to_proxy_url( $url );
                }
                return $url;
            },
            $json
        );

        // Also handle JSON-escaped slashes: https:\/\/media.bizcity.vn\/...
        $json = preg_replace_callback(
            '#https?:\\\\/\\\\/media\\.bizcity\\.vn\\\\/[^\s"]+#',
            function( $m ) {
                $url = str_replace( '\\/', '/', $m[0] );
                if ( preg_match( '/\.(jpe?g|png|gif|webp|svg|bmp|ico)$/i', wp_parse_url( $url, PHP_URL_PATH ) ?: '' ) ) {
                    $proxy = self::to_proxy_url( $url );
                    return str_replace( '/', '\\/', $proxy );
                }
                return $m[0];
            },
            $json
        );

        return is_string( $data ) ? $json : json_decode( $json, true );
    }

    /* ═══════════════════════════ SAVE TO MEDIA ═══════════════════════════ */

    /**
     * Save a base64 data URL image to WP Media Library.
     * Expects POST body: { "dataUrl": "data:image/png;base64,...", "filename": "design.png" }
     */
    public static function save_to_media( $request ) {
        $data_url = $request->get_param( 'dataUrl' );
        $filename = sanitize_file_name( $request->get_param( 'filename' ) ?: 'design-export.png' );

        if ( empty( $data_url ) || strpos( $data_url, 'data:image/' ) !== 0 ) {
            return new \WP_Error( 'invalid_data', 'dataUrl must be a valid image data URL.', array( 'status' => 400 ) );
        }

        // Parse data URL: data:image/png;base64,iVBOR...
        if ( ! preg_match( '#^data:image/(\w+);base64,(.+)$#s', $data_url, $matches ) ) {
            return new \WP_Error( 'invalid_format', 'Could not parse data URL.', array( 'status' => 400 ) );
        }

        $ext  = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
        $data = base64_decode( $matches[2] );
        if ( $data === false || strlen( $data ) < 100 ) {
            return new \WP_Error( 'decode_failed', 'Base64 decode failed.', array( 'status' => 400 ) );
        }

        // Ensure correct extension
        if ( ! preg_match( '/\.(png|jpe?g|webp|gif)$/i', $filename ) ) {
            $filename .= '.' . $ext;
        }

        $upload = wp_upload_bits( $filename, null, $data );
        if ( ! empty( $upload['error'] ) ) {
            return new \WP_Error( 'upload_failed', $upload['error'], array( 'status' => 500 ) );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachment = array(
            'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
            'post_mime_type' => 'image/' . ( $ext === 'jpg' ? 'jpeg' : $ext ),
            'post_status'    => 'inherit',
        );
        $attach_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( is_wp_error( $attach_id ) ) {
            return new \WP_Error( 'attach_failed', $attach_id->get_error_message(), array( 'status' => 500 ) );
        }

        $metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
        wp_update_attachment_metadata( $attach_id, $metadata );

        return new \WP_REST_Response( array(
            'id'  => $attach_id,
            'url' => wp_get_attachment_url( $attach_id ),
        ), 200 );
    }

    /* ═══════════════════════════ AI TEMPLATE GENERATION (Phase 3.6) ═══════════════════════════ */

    /**
     * POST /ai/vision-to-template — PA1: Upload/URL image → AI → lidojs template.
     */
    public static function ai_vision_to_template( $request ) {
        $body = $request->get_json_params();
        $image_url = $body['image_url'] ?? '';

        // Accept base64 data URI or URL.
        if ( empty( $image_url ) ) {
            return new WP_REST_Response( array( 'error' => 'image_url is required (URL or base64 data URI).' ), 400 );
        }

        // Validate URL format (allow data: and https:).
        if ( ! preg_match( '#^(https?://|data:image/)#i', $image_url ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid image_url format.' ), 400 );
        }

        $options = array(
            'canvas_preset' => sanitize_text_field( $body['canvas_preset'] ?? 'square' ),
            'canvas_width'  => (int) ( $body['canvas_width'] ?? 0 ),
            'canvas_height' => (int) ( $body['canvas_height'] ?? 0 ),
            'description'   => sanitize_text_field( $body['description'] ?? '' ),
            'language'      => sanitize_text_field( $body['language'] ?? 'vi' ),
        );

        $result = BizCity_AI_Template_Generator::vision_to_template( $image_url, $options );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( $result, 422 );
        }

        // Also return packed (minified) version for direct editor use.
        $result['packed'] = array_map( array( __CLASS__, 'pack_page_data' ), $result['template'] );

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * POST /ai/generate-variations — PA2: Skeleton + prompt → AI → N variations.
     */
    public static function ai_generate_variations( $request ) {
        $body = $request->get_json_params();

        // Load skeleton by ID or accept raw skeleton data.
        $skeleton = null;
        if ( ! empty( $body['skeleton_id'] ) ) {
            global $wpdb;
            $table = $wpdb->prefix . 'bztimg_editor_templates';
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT data_json FROM {$table} WHERE id = %d", (int) $body['skeleton_id']
            ) );
            if ( $row ) {
                $skeleton = json_decode( $row->data_json, true );
            }
        } elseif ( ! empty( $body['skeleton'] ) && is_array( $body['skeleton'] ) ) {
            $skeleton = $body['skeleton'];
        }

        if ( ! $skeleton ) {
            return new WP_REST_Response( array( 'error' => 'skeleton_id or skeleton data is required.' ), 400 );
        }

        $prompt = sanitize_textarea_field( $body['prompt'] ?? 'Create diverse variations' );

        $vary_fields = $body['vary_fields'] ?? array( 'text', 'colors', 'fonts' );
        if ( ! is_array( $vary_fields ) ) {
            $vary_fields = array( 'text', 'colors', 'fonts' );
        }
        $vary_fields = array_map( 'sanitize_text_field', $vary_fields );

        $options = array(
            'count'       => (int) ( $body['count'] ?? 3 ),
            'language'    => sanitize_text_field( $body['language'] ?? 'vi' ),
            'vary_fields' => $vary_fields,
        );

        $result = BizCity_AI_Template_Generator::generate_variations( $skeleton, $prompt, $options );

        if ( ! $result['success'] ) {
            return new WP_REST_Response( $result, 422 );
        }

        // Pack each variation for direct editor use.
        $result['packed'] = array_map( function( $variation ) {
            $pages = isset( $variation['layers'] ) ? array( $variation ) : $variation;
            return array_map( array( 'BizCity_REST_API_Editor_Assets', 'pack_page_data' ), $pages );
        }, $result['variations'] );

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * GET /ai/skeletons — List available skeleton templates for variation.
     */
    public static function ai_list_skeletons( $request ) {
        $templates = BizCity_AI_Template_Generator::get_skeleton_templates();
        return new WP_REST_Response( array( 'data' => $templates ), 200 );
    }

    /**
     * POST /ai/save-template — Save an AI-generated template to DB.
     */
    public static function ai_save_template( $request ) {
        $body = $request->get_json_params();

        if ( empty( $body['template'] ) || ! is_array( $body['template'] ) ) {
            return new WP_REST_Response( array( 'error' => 'template data is required.' ), 400 );
        }

        $description = sanitize_text_field( $body['description'] ?? 'AI Generated Template' );
        $source      = sanitize_text_field( $body['source'] ?? 'ai_variation' );

        $insert_id = BizCity_AI_Template_Generator::save_template( $body['template'], $description, $source );

        if ( ! $insert_id ) {
            return new WP_REST_Response( array( 'error' => 'Failed to save template.' ), 500 );
        }

        return new WP_REST_Response( array( 'success' => true, 'id' => $insert_id ), 201 );
    }

    public static function ai_remove_bg( $request ) {
        $body = $request->get_json_params();
        $image_url = esc_url_raw( $body['image_url'] ?? '' );

        if ( empty( $image_url ) ) {
            return new WP_REST_Response( array( 'error' => 'image_url is required.' ), 400 );
        }

        if ( empty( get_site_option( 'bizcity_piapi_api_key' ) ) ) {
            return new WP_REST_Response( array( 'error' => 'PiAPI key is not configured.' ), 500 );
        }

        $result = self::ai_piapi_request(
            'POST',
            '/api/v1/task',
            array(
                'model'     => 'Qubico/image-toolkit',
                'task_type' => 'background-remove',
                'input'     => array(
                    'image'      => $image_url,
                    'rmbg_model' => 'RMBG-2.0',
                ),
            )
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array( 'error' => $result->get_error_message() ),
                (int) ( $result->get_error_data()['status'] ?? 500 )
            );
        }

        $task_id = $result['data']['task_id'] ?? '';

        if ( empty( $task_id ) ) {
            return new WP_REST_Response( array( 'error' => 'PiAPI did not return a task_id.' ), 502 );
        }

        return new WP_REST_Response(
            array(
                'success' => true,
                'task_id' => $task_id,
                'status'  => 'pending',
            ),
            200
        );
    }

    public static function ai_task_status( $request ) {
        $task_id = sanitize_text_field( $request['task_id'] ?? '' );

        if ( empty( $task_id ) ) {
            return new WP_REST_Response( array( 'error' => 'task_id is required.' ), 400 );
        }

        if ( empty( get_site_option( 'bizcity_piapi_api_key' ) ) ) {
            return new WP_REST_Response( array( 'error' => 'PiAPI key is not configured.' ), 500 );
        }

        $result = self::ai_piapi_request( 'GET', '/api/v1/task/' . rawurlencode( $task_id ) );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                array( 'error' => $result->get_error_message() ),
                (int) ( $result->get_error_data()['status'] ?? 500 )
            );
        }

        $data = $result['data'] ?? array();
        $output = $data['output'] ?? array();
        $image_url = '';

        if ( is_array( $output ) ) {
            if ( ! empty( $output['image_url'] ) ) {
                $image_url = esc_url_raw( $output['image_url'] );
            } elseif ( ! empty( $output['image_urls'] ) && is_array( $output['image_urls'] ) ) {
                $image_url = esc_url_raw( $output['image_urls'][0] ?? '' );
            }
        } elseif ( is_string( $output ) ) {
            $image_url = esc_url_raw( $output );
        }

        return new WP_REST_Response(
            array(
                'success'   => true,
                'task_id'   => $task_id,
                'status'    => sanitize_text_field( $data['status'] ?? 'pending' ),
                'image_url' => $image_url,
                'error'     => sanitize_text_field( $data['error']['message'] ?? $data['message'] ?? '' ),
            ),
            200
        );
    }

    public static function ai_text_suggest( $request ) {
        $body = $request->get_json_params();
        $current_text = trim( wp_strip_all_tags( (string) ( $body['current_text'] ?? '' ) ) );
        $context = sanitize_textarea_field( $body['context'] ?? '' );
        $language = sanitize_text_field( $body['language'] ?? 'vi' );
        $count = max( 3, min( 8, (int) ( $body['count'] ?? 5 ) ) );

        if ( empty( $current_text ) ) {
            return new WP_REST_Response( array( 'error' => 'current_text is required.' ), 400 );
        }

        if ( ! function_exists( 'bizcity_llm_chat' ) ) {
            return new WP_REST_Response( array( 'error' => 'LLM service is unavailable.' ), 500 );
        }

        $messages = array(
            array(
                'role'    => 'system',
                'content' => 'You are an expert marketing copywriter for a design editor. Return only a valid JSON array of concise text suggestions. Do not include markdown, explanations, or extra keys.',
            ),
            array(
                'role'    => 'user',
                'content' => sprintf(
                    "Language: %s\nGenerate %d short alternatives for this text. Keep the same core meaning but improve clarity, punch, and conversion. Each suggestion should stay concise and usable directly in a design canvas.\n\nCurrent text:\n%s\n\nOptional context:\n%s",
                    $language,
                    $count,
                    $current_text,
                    $context ?: 'None'
                ),
            ),
        );

        $result = bizcity_llm_chat(
            $messages,
            array(
                'purpose'     => 'editor_text_suggest',
                'temperature' => 0.8,
                'max_tokens'  => 500,
                'timeout'     => 45,
            )
        );

        if ( empty( $result['success'] ) ) {
            return new WP_REST_Response( $result, 422 );
        }

        $suggestions = self::ai_parse_text_suggestions( (string) ( $result['message'] ?? '' ), $count );

        if ( empty( $suggestions ) ) {
            return new WP_REST_Response( array( 'error' => 'Unable to parse AI suggestions.' ), 422 );
        }

        return new WP_REST_Response(
            array(
                'success'     => true,
                'suggestions' => $suggestions,
            ),
            200
        );
    }

    private static function ai_piapi_request( $method, $path, $body = null ) {
        $api_key = trim( (string) get_site_option( 'bizcity_piapi_api_key' ) );

        if ( empty( $api_key ) ) {
            return new WP_Error( 'piapi_key_missing', 'PiAPI key is not configured.', array( 'status' => 500 ) );
        }

        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 60,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key'    => $api_key,
            ),
        );

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( 'https://api.piapi.ai' . $path, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw_body, true );

        if ( $status >= 400 ) {
            $message = is_array( $data )
                ? ( $data['message'] ?? $data['error'] ?? 'PiAPI request failed.' )
                : 'PiAPI request failed.';
            return new WP_Error( 'piapi_http_error', $message, array( 'status' => $status ) );
        }

        return is_array( $data ) ? $data : array();
    }

    private static function ai_parse_text_suggestions( $content, $count ) {
        $content = trim( $content );

        if ( preg_match( '/\[[\s\S]*\]/', $content, $matches ) ) {
            $content = $matches[0];
        }

        $decoded = json_decode( $content, true );

        if ( is_array( $decoded ) && isset( $decoded['suggestions'] ) && is_array( $decoded['suggestions'] ) ) {
            $decoded = $decoded['suggestions'];
        }

        if ( ! is_array( $decoded ) ) {
            $decoded = preg_split( '/\r\n|\r|\n/', $content );
        }

        $suggestions = array();
        foreach ( $decoded as $item ) {
            if ( ! is_string( $item ) ) {
                continue;
            }

            $item = trim( preg_replace( '/^[-*\d\.)\s]+/', '', $item ) );
            $item = trim( wp_strip_all_tags( $item ), " \t\n\r\0\x0B\"'" );

            if ( '' !== $item ) {
                $suggestions[] = $item;
            }
        }

        $suggestions = array_values( array_unique( $suggestions ) );

        return array_slice( $suggestions, 0, $count );
    }

    /* ═══════════════════════════ PERMISSIONS ═══════════════════════════ */

    public static function is_admin() {
        return current_user_can( 'manage_options' );
    }
}
