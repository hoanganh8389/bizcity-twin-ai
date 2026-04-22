<?php
/**
 * REST API — Template & Category endpoints.
 *
 * @package BizCity_Tool_Image
 * @since   2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_REST_API_Templates {

    const NAMESPACE = 'bztool-image/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {

        /* ── Templates ── */

        register_rest_route( self::NAMESPACE, '/templates', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_templates' ),
                'permission_callback' => '__return_true',
                'args'                => self::list_args(),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'create_template' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/templates/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_template' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'PUT,PATCH',
                'callback'            => array( __CLASS__, 'update_template' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_template' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/templates/(?P<id>\d+)/duplicate', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'duplicate_template' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        register_rest_route( self::NAMESPACE, '/templates/(?P<id>\d+)/generate', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'generate_from_template' ),
            'permission_callback' => array( __CLASS__, 'is_logged_in' ),
        ) );

        register_rest_route( self::NAMESPACE, '/templates/export', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'export_templates' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        register_rest_route( self::NAMESPACE, '/templates/import', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'import_templates' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Categories ── */

        register_rest_route( self::NAMESPACE, '/template-categories', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_categories' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( __CLASS__, 'create_category' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/template-categories/(?P<id>\d+)', array(
            array(
                'methods'             => 'PUT,PATCH',
                'callback'            => array( __CLASS__, 'update_category' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_category' ),
                'permission_callback' => array( __CLASS__, 'is_admin' ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/template-categories/reorder', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'reorder_categories' ),
            'permission_callback' => array( __CLASS__, 'is_admin' ),
        ) );

        /* ── Design Editor: templates formatted for @lidojs ── */

        /* ── Design Editor endpoints moved to class-rest-api-editor-assets.php:
              /editor-templates, /fonts, /text-presets, /shapes, /frames,
              /user-images, keyword suggestions ── */

        /* ── Design Editor: stock images proxy (Pixabay) ── */

        register_rest_route( self::NAMESPACE, '/stock-images', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'get_stock_images' ),
            'permission_callback' => array( __CLASS__, 'is_logged_in' ),
            'args'                => array(
                'q'        => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ),
                'page'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1 ),
                'per_page' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 40 ),
            ),
        ) );
    }

    /* ═══════════════════════════ TEMPLATE CALLBACKS ═══════════════════════════ */

    public static function get_templates( $request ) {
        $args = array(
            'category_slug' => $request->get_param( 'category' ),
            'category_id'   => $request->get_param( 'category_id' ),
            'subcategory'   => $request->get_param( 'subcategory' ),
            'slug'          => $request->get_param( 'slug' ),
            'status'        => $request->get_param( 'status' ) ?: 'active',
            'is_featured'   => $request->get_param( 'featured' ),
            'search'        => $request->get_param( 'search' ),
            'per_page'      => $request->get_param( 'per_page' ) ?: 20,
            'page'          => $request->get_param( 'page' ) ?: 1,
        );

        $templates = BizCity_Template_Manager::get_all( $args );
        $total     = BizCity_Template_Manager::count( $args );

        // Decode form_fields JSON for each template
        foreach ( $templates as &$tpl ) {
            $tpl['form_fields'] = json_decode( $tpl['form_fields'] ?? '[]', true ) ?: array();
        }

        return new \WP_REST_Response( array(
            'templates' => $templates,
            'total'     => $total,
            'page'      => (int) $args['page'],
            'per_page'  => (int) $args['per_page'],
            'pages'     => ceil( $total / max( 1, (int) $args['per_page'] ) ),
        ), 200 );
    }

    public static function get_template( $request ) {
        $tpl = BizCity_Template_Manager::get_by_id( $request['id'] );
        if ( ! $tpl ) {
            return new \WP_Error( 'not_found', 'Template not found.', array( 'status' => 404 ) );
        }
        return new \WP_REST_Response( $tpl, 200 );
    }

    public static function create_template( $request ) {
        $data   = $request->get_json_params();
        $result = BizCity_Template_Manager::insert( $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return new \WP_REST_Response( array(
            'id'      => $result,
            'message' => 'Template created.',
        ), 201 );
    }

    public static function update_template( $request ) {
        $data   = $request->get_json_params();
        $result = BizCity_Template_Manager::update( $request['id'], $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return new \WP_REST_Response( array( 'message' => 'Template updated.' ), 200 );
    }

    public static function delete_template( $request ) {
        BizCity_Template_Manager::delete( $request['id'] );
        return new \WP_REST_Response( array( 'message' => 'Template deleted.' ), 200 );
    }

    public static function duplicate_template( $request ) {
        $result = BizCity_Template_Manager::duplicate( $request['id'] );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return new \WP_REST_Response( array(
            'id'      => $result,
            'message' => 'Template duplicated.',
        ), 201 );
    }

    public static function generate_from_template( $request ) {
        $data = $request->get_json_params();

        $form_data  = $data['form_data'] ?? array();
        $overrides  = array(
            'model' => $data['model'] ?? null,
            'size'  => $data['size'] ?? null,
            'style' => $data['style'] ?? null,
        );

        $slots = BizCity_Template_Manager::resolve_slots( (int) $request['id'], $form_data, array_filter( $overrides ) );
        if ( is_wp_error( $slots ) ) {
            return new \WP_Error( $slots->get_error_code(), $slots->get_error_message(), array( 'status' => 400 ) );
        }

        // Delegate to the existing generation tool
        if ( ! class_exists( 'BizCity_Tool_Image' ) ) {
            return new \WP_Error( 'tool_unavailable', 'Image generation tool not available.', array( 'status' => 500 ) );
        }

        $result = BizCity_Tool_Image::generate_image( $slots );

        if ( empty( $result['success'] ) ) {
            return new \WP_Error(
                'generation_failed',
                $result['message'] ?? 'Unknown error',
                array( 'status' => 400 )
            );
        }

        return new \WP_REST_Response( $result['data'] ?? $result, 200 );
    }

    public static function export_templates( $request ) {
        return new \WP_REST_Response( BizCity_Template_Manager::export_all(), 200 );
    }

    public static function import_templates( $request ) {
        $data   = $request->get_json_params();
        $force  = (bool) $request->get_param( 'force' );
        $result = BizCity_Template_Manager::import( $data, $force );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return new \WP_REST_Response( array(
            'imported' => $result,
            'force'    => $force,
            'message'  => "{$result} templates " . ( $force ? 'imported/updated' : 'imported' ) . ".",
        ), 200 );
    }

    /* ═══════════════════════════ CATEGORY CALLBACKS ═══════════════════════════ */

    public static function get_categories( $request ) {
        $args = array(
            'status' => $request->get_param( 'status' ) ?: 'active',
        );
        $categories = BizCity_Template_Category_Manager::get_all( $args );

        // Attach template count
        foreach ( $categories as &$cat ) {
            $cat['template_count'] = BizCity_Template_Category_Manager::count_templates( $cat['id'] );
        }

        return new \WP_REST_Response( $categories, 200 );
    }

    public static function create_category( $request ) {
        $data   = $request->get_json_params();
        $result = BizCity_Template_Category_Manager::insert( $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return new \WP_REST_Response( array(
            'id'      => $result,
            'message' => 'Category created.',
        ), 201 );
    }

    public static function update_category( $request ) {
        $data   = $request->get_json_params();
        $result = BizCity_Template_Category_Manager::update( $request['id'], $data );

        if ( is_wp_error( $result ) ) {
            return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
        }

        return new \WP_REST_Response( array( 'message' => 'Category updated.' ), 200 );
    }

    public static function delete_category( $request ) {
        BizCity_Template_Category_Manager::delete( $request['id'] );
        return new \WP_REST_Response( array( 'message' => 'Category deleted.' ), 200 );
    }

    public static function reorder_categories( $request ) {
        $data = $request->get_json_params();
        $ids  = $data['ordered_ids'] ?? array();

        if ( ! is_array( $ids ) || empty( $ids ) ) {
            return new \WP_Error( 'invalid_data', 'ordered_ids array is required.', array( 'status' => 400 ) );
        }

        $ids = array_map( 'absint', $ids );
        BizCity_Template_Category_Manager::reorder( $ids );
        return new \WP_REST_Response( array( 'message' => 'Categories reordered.' ), 200 );
    }

    /* ═══════════════════════════ DESIGN EDITOR CALLBACKS ═══════════════════════════ */

    /**
     * GET /editor-templates — returns templates in the format expected by
     * the design editor (img + elements SerializedPage JSON).
     */
    public static function get_editor_templates( $request ) {
        $args = array(
            'category_slug' => $request->get_param( 'category' ),
            'search'        => $request->get_param( 'search' ),
            'status'        => 'active',
            'per_page'      => $request->get_param( 'per_page' ) ?: 20,
            'page'          => $request->get_param( 'page' ) ?: 1,
        );

        $templates = BizCity_Template_Manager::get_all( $args );
        $total     = BizCity_Template_Manager::count( $args );

        $size_filter = $request->get_param( 'size' ); // "1:1", "16:9", "9:16"

        $items = array();
        foreach ( $templates as $tpl ) {
            // Parse editor_data JSON if available
            $editor_data = ! empty( $tpl['editor_data'] ) ? json_decode( $tpl['editor_data'], true ) : null;

            // Determine thumbnail
            $img = $tpl['preview_url'] ?? $tpl['thumbnail_url'] ?? '';

            // If no editor_data, create a minimal SerializedPage
            $elements = $editor_data ?: self::make_minimal_page( $tpl );

            // Size info
            $w = (int) ( $tpl['canvas_width']  ?? 1080 );
            $h = (int) ( $tpl['canvas_height'] ?? 1080 );
            $ratio = self::calc_ratio( $w, $h );

            // Apply size filter
            if ( $size_filter && $ratio !== $size_filter ) {
                continue;
            }

            $items[] = array(
                'id'       => (int) $tpl['id'],
                'title'    => $tpl['name'] ?? $tpl['title'] ?? '',
                'img'      => $img,
                'category' => $tpl['category_slug'] ?? '',
                'size'     => array( 'width' => $w, 'height' => $h, 'ratio' => $ratio ),
                'elements' => $elements,
            );
        }

        return new \WP_REST_Response( array(
            'templates' => $items,
            'total'     => (int) $total,
            'pages'     => ceil( $total / max( 1, (int) $args['per_page'] ) ),
        ), 200 );
    }

    /**
     * GET /stock-images — proxy to Pixabay API (keeps API key server-side).
     */
    public static function get_stock_images( $request ) {
        $api_key = defined( 'BZTIMG_PIXABAY_KEY' ) ? BZTIMG_PIXABAY_KEY : '';
        if ( empty( $api_key ) ) {
            return new \WP_REST_Response( array(), 200 );
        }

        $q        = $request->get_param( 'q' ) ?: 'nature';
        $page     = $request->get_param( 'page' ) ?: 1;
        $per_page = min( $request->get_param( 'per_page' ) ?: 40, 200 );

        $url = add_query_arg( array(
            'key'      => $api_key,
            'q'        => urlencode( $q ),
            'page'     => $page,
            'per_page' => $per_page,
            'image_type' => 'photo',
            'safesearch' => 'true',
        ), 'https://pixabay.com/api/' );

        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) {
            return new \WP_REST_Response( array(), 200 );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $hits = $body['hits'] ?? array();

        $images = array();
        foreach ( $hits as $hit ) {
            $images[] = array(
                'id'       => (string) $hit['id'],
                'image'    => $hit['largeImageURL'] ?? $hit['webformatURL'],
                'thumb'    => $hit['previewURL'],
                'width'    => (int) $hit['imageWidth'],
                'height'   => (int) $hit['imageHeight'],
                'username' => $hit['user'] ?? '',
                'name'     => $hit['user'] ?? '',
            );
        }

        return new \WP_REST_Response( $images, 200 );
    }

    /**
     * GET /fonts — returns Google Fonts list for the editor.
     */
    public static function get_fonts( $request ) {
        $google_key = defined( 'BZTIMG_GOOGLE_FONTS_KEY' ) ? BZTIMG_GOOGLE_FONTS_KEY : '';

        // Return popular Vietnamese-compatible fonts as fallback
        $default_fonts = array(
            self::make_font( 'Roboto', array( 'Regular', 'Bold', 'Italic', 'Bold_Italic' ) ),
            self::make_font( 'Open Sans', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Nunito', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Montserrat', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Lato', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Poppins', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Inter', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Playfair Display', array( 'Regular', 'Bold', 'Italic' ) ),
            self::make_font( 'Dancing Script', array( 'Regular', 'Bold' ) ),
            self::make_font( 'Be Vietnam Pro', array( 'Regular', 'Bold', 'Italic' ) ),
        );

        return new \WP_REST_Response( $default_fonts, 200 );
    }

    /**
     * GET /text-presets — returns text preset templates for the editor.
     */
    public static function get_text_presets( $request ) {
        // Return empty for now; the editor has built-in heading/subheading/body presets
        return new \WP_REST_Response( array(), 200 );
    }

    /* ═══════════════════════════ DESIGN EDITOR HELPERS ═══════════════════════════ */

    private static function calc_ratio( int $w, int $h ): string {
        if ( $w === $h ) return '1:1';
        if ( abs( $w / $h - 16/9 ) < 0.05 ) return '16:9';
        if ( abs( $w / $h - 9/16 ) < 0.05 ) return '9:16';
        return $w . ':' . $h;
    }

    private static function make_minimal_page( array $tpl ): array {
        $w = (int) ( $tpl['canvas_width']  ?? 1080 );
        $h = (int) ( $tpl['canvas_height'] ?? 1080 );

        return array(
            'locked' => false,
            'layers' => array(
                'ROOT' => array(
                    'type'   => array( 'resolvedName' => 'RootLayer' ),
                    'props'  => array(
                        'boxSize'  => array( 'width' => $w, 'height' => $h ),
                        'position' => array( 'x' => 0, 'y' => 0 ),
                        'rotate'   => 0,
                        'color'    => array( 'r' => 255, 'g' => 255, 'b' => 255, 'a' => 1 ),
                        'image'    => ! empty( $tpl['preview_url'] ) ? array(
                            'url'    => $tpl['preview_url'],
                            'thumb'  => $tpl['preview_url'],
                            'boxSize' => array( 'width' => $w, 'height' => $h ),
                            'position' => array( 'x' => 0, 'y' => 0 ),
                            'rotate' => 0,
                        ) : null,
                    ),
                    'locked' => false,
                    'child'  => array(),
                    'parent' => null,
                ),
            ),
        );
    }

    private static function make_font( string $family, array $styles ): array {
        $base_url = 'https://fonts.gstatic.com/s/' . strtolower( str_replace( ' ', '', $family ) ) . '/v1/';
        $fonts = array();
        foreach ( $styles as $style ) {
            $fonts[] = array(
                'style' => $style,
                'urls'  => array( "https://fonts.googleapis.com/css2?family=" . urlencode( $family ) . "&display=swap" ),
            );
        }
        return array(
            'name'  => $family,
            'fonts' => $fonts,
        );
    }

    /* ═══════════════════════════ PERMISSIONS ═══════════════════════════ */

    public static function is_admin() {
        return current_user_can( 'manage_options' );
    }

    public static function is_logged_in() {
        return is_user_logged_in();
    }

    /* ═══════════════════════════ ARGS ═══════════════════════════ */

    private static function list_args() {
        return array(
            'category'    => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ),
            'category_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
            'status'      => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ),
            'subcategory' => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ),
            'slug'        => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_title' ),
            'featured'    => array( 'type' => 'boolean' ),
            'search'      => array( 'type' => 'string',  'sanitize_callback' => 'sanitize_text_field' ),
            'per_page'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 20 ),
            'page'        => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1 ),
        );
    }
}
