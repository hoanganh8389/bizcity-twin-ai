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
