<?php
/**
 * REST API — Design Editor project CRUD.
 *
 * Namespace: bztool-image/v1  (same as templates — used by the React editor build)
 *
 * Endpoints:
 *   GET    /projects/:id   — Load a design project
 *   POST   /projects       — Create a new project
 *   PUT    /projects/:id   — Update project (data and/or title)
 *   DELETE /projects/:id   — Delete a project
 *
 * @package BizCity_Tool_Image
 * @since   3.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_REST_API_Projects {

    const NS = 'bztool-image/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /* ═══════════════════════ ROUTE REGISTRATION ═══════════════════════ */

    public static function register_routes() {

        /* Collection: create */
        register_rest_route( self::NS, '/projects', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'create_project' ),
            'permission_callback' => '__return_true',
        ) );

        /* Single: read / update / delete */
        register_rest_route( self::NS, '/projects/(?P<id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( __CLASS__, 'get_project' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'PUT',
                'callback'            => array( __CLASS__, 'update_project' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => 'DELETE',
                'callback'            => array( __CLASS__, 'delete_project' ),
                'permission_callback' => '__return_true',
            ),
        ) );
    }

    /* ═══════════════════════ HELPERS ═══════════════════════ */

    /**
     * Get current user ID, falling back to cookie-based auth if nonce is stale.
     * Needed because iframe nonce can become invalid (Cloudflare cache, Rocket Loader).
     */
    private static function get_user_id() {
        $uid = get_current_user_id();
        if ( $uid ) return $uid;
        // Fallback: validate logged_in cookie directly
        $uid = wp_validate_auth_cookie( '', 'logged_in' );
        if ( $uid ) {
            wp_set_current_user( $uid );
        }
        return (int) $uid;
    }

    /* ═══════════════════════ GET ═══════════════════════ */

    /**
     * GET /projects/:id
     *
     * Response: { id, title, data (JSON-decoded), created_at, updated_at }
     */
    public static function get_project( WP_REST_Request $request ) {
        $user_id = self::get_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_projects';
        $id    = (int) $request->get_param( 'id' );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND status = 'active'",
            $id
        ) );

        if ( ! $row ) {
            return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
        }

        /* Only the owner or an admin may read */
        if ( (int) $row->user_id !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Not your project', array( 'status' => 403 ) );
        }

        return rest_ensure_response( array(
            'id'         => (int) $row->id,
            'title'      => $row->title,
            'data'       => json_decode( $row->data, true ),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ) );
    }

    /* ═══════════════════════ CREATE ═══════════════════════ */

    /**
     * POST /projects
     *
     * Body: { data: <editor JSON> }
     * Response: { id }
     */
    public static function create_project( WP_REST_Request $request ) {
        $user_id = self::get_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_projects';

        $body  = $request->get_json_params();
        $title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
        $data  = isset( $body['data'] ) ? $body['data'] : null;

        $inserted = $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'title'      => $title,
            'data'       => wp_json_encode( $data ),
            'status'     => 'active',
            'created_at' => current_time( 'mysql', true ),
            'updated_at' => current_time( 'mysql', true ),
        ), array( '%d', '%s', '%s', '%s', '%s', '%s' ) );

        if ( ! $inserted ) {
            error_log( '[bztimg_projects] Insert failed: ' . $wpdb->last_error );
            return new WP_Error( 'db_error', 'Could not create project', array( 'status' => 500 ) );
        }

        return rest_ensure_response( array( 'id' => (int) $wpdb->insert_id ) );
    }

    /* ═══════════════════════ UPDATE ═══════════════════════ */

    /**
     * PUT /projects/:id
     *
     * Body may contain { data } and/or { title }.
     * Response: { id, updated_at }
     */
    public static function update_project( WP_REST_Request $request ) {
        $user_id = self::get_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_projects';
        $id    = (int) $request->get_param( 'id' );

        /* Verify ownership */
        $owner = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d AND status = 'active'",
            $id
        ) );

        if ( $owner === null ) {
            return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
        }
        if ( (int) $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Not your project', array( 'status' => 403 ) );
        }

        /* Build update set */
        $body   = $request->get_json_params();
        $update = array( 'updated_at' => current_time( 'mysql', true ) );
        $format = array( '%s' );

        if ( isset( $body['title'] ) ) {
            $update['title'] = sanitize_text_field( $body['title'] );
            $format[]        = '%s';
        }

        if ( isset( $body['data'] ) ) {
            $update['data'] = wp_json_encode( $body['data'] );
            $format[]       = '%s';
        }

        $wpdb->update( $table, $update, array( 'id' => $id ), $format, array( '%d' ) );

        return rest_ensure_response( array(
            'id'         => $id,
            'updated_at' => $update['updated_at'],
        ) );
    }

    /* ═══════════════════════ DELETE ═══════════════════════ */

    /**
     * DELETE /projects/:id
     *
     * Soft-delete (status → 'deleted').
     */
    public static function delete_project( WP_REST_Request $request ) {
        $user_id = self::get_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bztimg_projects';
        $id    = (int) $request->get_param( 'id' );

        /* Verify ownership */
        $owner = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE id = %d AND status = 'active'",
            $id
        ) );

        if ( $owner === null ) {
            return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
        }
        if ( (int) $owner !== $user_id && ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'forbidden', 'Not your project', array( 'status' => 403 ) );
        }

        $wpdb->update(
            $table,
            array( 'status' => 'deleted', 'updated_at' => current_time( 'mysql', true ) ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return rest_ensure_response( array( 'deleted' => true ) );
    }
}
