<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Intent REST API — Tasks & Sessions
 *
 * Provides paginated REST endpoints for tasks (nhiệm vụ) and webchat sessions.
 * Designed for React / mobile app consumption.
 *
 * Endpoints:
 *   GET  /bizcity-intent/v1/tasks                           — List tasks (paginated)
 *   GET  /bizcity-intent/v1/tasks/(?P<id>[a-f0-9-]+)        — Single task detail
 *   GET  /bizcity-intent/v1/tasks/(?P<id>[a-f0-9-]+)/turns  — Task conversation turns
 *   GET  /bizcity-intent/v1/tasks/stats                     — Task status counts
 *
 *   GET  /bizcity-intent/v1/sessions                                   — List sessions (paginated)
 *   GET  /bizcity-intent/v1/sessions/(?P<id>\d+)                       — Single session detail
 *   GET  /bizcity-intent/v1/sessions/(?P<id>\d+)/messages              — Session messages (paginated)
 *   GET  /bizcity-intent/v1/sessions/by-sid/(?P<sid>[a-zA-Z0-9_-]+)   — Lookup by session_id string
 *   GET  /bizcity-intent/v1/sessions/stats                             — Session status counts
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_REST_API {

    private static $instance = null;

    const NAMESPACE = 'bizcity-intent/v1';

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /* ================================================================
     * Route Registration
     * ================================================================ */

    public function register_routes() {

        // ── Tasks (Nhiệm vụ) ──

        register_rest_route( self::NAMESPACE, '/tasks', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_list_tasks' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => $this->paged_args( [
                'channel' => [
                    'type'              => 'string',
                    'default'           => 'adminchat',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'status' => [
                    'type'              => 'string',
                    'default'           => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'project_id' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'search' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ] ),
        ] );

        register_rest_route( self::NAMESPACE, '/tasks/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_task_stats' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'channel' => [
                    'type'    => 'string',
                    'default' => 'adminchat',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/tasks/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_task' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NAMESPACE, '/tasks/(?P<id>[a-zA-Z0-9_-]+)/turns', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_task_turns' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        // ── Sessions (Phiên chat) ──

        register_rest_route( self::NAMESPACE, '/sessions', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_list_sessions' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => $this->paged_args( [
                'platform_type' => [
                    'type'    => 'string',
                    'default' => 'ADMINCHAT',
                    'enum'    => [ 'ADMINCHAT', 'WEBCHAT' ],
                ],
                'status' => [
                    'type'              => 'string',
                    'default'           => 'all',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'project_id' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'search' => [
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ] ),
        ] );

        register_rest_route( self::NAMESPACE, '/sessions/stats', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_session_stats' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'platform_type' => [
                    'type'    => 'string',
                    'default' => 'ADMINCHAT',
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/sessions/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_session' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NAMESPACE, '/sessions/(?P<id>\d+)/messages', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_session_messages' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => [
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/sessions/by-sid/(?P<sid>[a-zA-Z0-9_-]+)', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'rest_get_session_by_sid' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
    }

    /* ================================================================
     * Permission
     * ================================================================ */

    public function check_auth( WP_REST_Request $request ) {
        // API key auth (for app / external clients)
        $api_key = $request->get_header( 'X-BizCity-API-Key' );
        if ( $api_key ) {
            $stored = get_option( 'bizcity_webchat_api_key', '' );
            if ( $stored && hash_equals( $stored, $api_key ) ) {
                return true;
            }
        }
        // Cookie / nonce auth (WP admin)
        return is_user_logged_in();
    }

    /* ================================================================
     * Task Handlers
     * ================================================================ */

    public function rest_list_tasks( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        $result = BizCity_Task_Service::instance()->list_tasks( $user_id, $request->get_params() );
        return rest_ensure_response( $result );
    }

    public function rest_get_task( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $result  = BizCity_Task_Service::instance()->get_task(
            $request->get_param( 'id' ),
            $user_id
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_task_turns( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $result  = BizCity_Task_Service::instance()->get_task_turns(
            $request->get_param( 'id' ),
            $user_id,
            $request->get_param( 'page' ),
            $request->get_param( 'per_page' )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_task_stats( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        $counts = BizCity_Task_Service::instance()->get_status_counts(
            $user_id,
            $request->get_param( 'channel' ) ?: 'adminchat'
        );
        return rest_ensure_response( $counts );
    }

    /* ================================================================
     * Session Handlers
     * ================================================================ */

    public function rest_list_sessions( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        $result = BizCity_Session_List_Service::instance()->list_sessions( $user_id, $request->get_params() );
        return rest_ensure_response( $result );
    }

    public function rest_get_session( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $result  = BizCity_Session_List_Service::instance()->get_session(
            (int) $request->get_param( 'id' ),
            $user_id
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_session_messages( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        // Get session_id string from the PK
        $wc_db = class_exists( 'BizCity_WebChat_Database' ) ? BizCity_WebChat_Database::instance() : null;
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $session = $wc_db->get_session( (int) $request->get_param( 'id' ) );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        $result = BizCity_Session_List_Service::instance()->get_session_messages(
            $session->session_id,
            $user_id,
            $request->get_param( 'page' ),
            $request->get_param( 'per_page' )
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_get_session_by_sid( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $result  = BizCity_Session_List_Service::instance()->get_session_by_sid(
            $request->get_param( 'sid' ),
            $user_id
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }

    public function rest_session_stats( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        $counts = BizCity_Session_List_Service::instance()->get_status_counts(
            $user_id,
            $request->get_param( 'platform_type' ) ?: 'ADMINCHAT'
        );
        return rest_ensure_response( $counts );
    }

    /* ================================================================
     * Shared args builder
     * ================================================================ */

    private function paged_args( array $extra = [] ) {
        return array_merge( [
            'page' => [
                'type'    => 'integer',
                'default' => 1,
                'minimum' => 1,
            ],
            'per_page' => [
                'type'    => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
            ],
            'order' => [
                'type'    => 'string',
                'default' => 'DESC',
                'enum'    => [ 'ASC', 'DESC' ],
            ],
        ], $extra );
    }
}
