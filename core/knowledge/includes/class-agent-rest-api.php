<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Agent REST API — Full API for React SPA / mobile apps
 * Namespace: bizcity-agent/v1
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Endpoints:
 *   ── AUTH ──
 *   POST   /auth/login          — Login
 *   POST   /auth/register       — Register
 *   GET    /auth/me             — Current user info
 *   POST   /auth/logout         — Logout
 *
 *   ── CHAT ──
 *   POST   /chat/send           — Send message (non-streaming)
 *   GET    /chat/history         — Chat history by session
 *   DELETE /chat/history         — Clear history
 *   GET    /chat/stream          — SSE stream (proxies to gateway)
 *
 *   ── SESSIONS ──
 *   GET    /sessions             — List sessions
 *   POST   /sessions             — Create session
 *   PATCH  /sessions/{id}        — Rename session
 *   DELETE /sessions/{id}        — Delete session
 *   POST   /sessions/{id}/move   — Move to project
 *   GET    /sessions/{id}/messages — Get session messages
 *   GET    /sessions/{id}/poll    — Poll new messages
 *   POST   /sessions/{id}/title   — AI-generate title
 *   POST   /sessions/close-all   — Close all sessions
 *
 *   ── PROJECTS ──
 *   GET    /projects             — List projects
 *   POST   /projects             — Create project
 *   PATCH  /projects/{id}        — Update project
 *   DELETE /projects/{id}        — Delete project
 *
 *   ── CONTEXT ──
 *   GET    /session              — Get/create session ID
 *   GET    /emotion              — Emotion analysis
 *   GET    /bond                 — User bond score
 *   GET    /characters           — List characters
 *   GET    /plugins              — List available agent plugins
 *
 * @package  BizCity_Knowledge
 * @version  1.0.0
 * @since    2026-03-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Agent_REST_API {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const NS = 'bizcity-agent/v1';

    private function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /* ================================================================
     * REGISTER ROUTES
     * ================================================================ */
    public function register_routes() {

        // ── AUTH ──
        register_rest_route( self::NS, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'auth_login' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'username' => [ 'type' => 'string', 'required' => true ],
                'password' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/auth/register', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'auth_register' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'phone'        => [ 'type' => 'string', 'default' => '' ],
                'email'        => [ 'type' => 'string', 'default' => '' ],
                'username'     => [ 'type' => 'string', 'default' => '' ],
                'display_name' => [ 'type' => 'string', 'default' => '' ],
                'password'     => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );

        register_rest_route( self::NS, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'auth_me' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NS, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'auth_logout' ],
            'permission_callback' => function() { return is_user_logged_in(); },
        ] );

        // ── CHAT ──
        register_rest_route( self::NS, '/chat/send', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'chat_send' ],
            'permission_callback' => [ $this, 'perm_chat' ],
            'args'                => [
                'message'       => [ 'type' => 'string', 'default' => '' ],
                'session_id'    => [ 'type' => 'string', 'default' => '' ],
                'character_id'  => [ 'type' => 'integer', 'default' => 0 ],
                'images'        => [ 'type' => 'array', 'default' => [] ],
                'plugin_slug'   => [ 'type' => 'string', 'default' => '' ],
                'routing_mode'  => [ 'type' => 'string', 'default' => 'automatic' ],
                'provider_hint' => [ 'type' => 'string', 'default' => '' ],
                'platform_type' => [ 'type' => 'string', 'default' => 'WEBCHAT' ],
            ],
        ] );

        register_rest_route( self::NS, '/chat/history', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'chat_history' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'session_id'    => [ 'type' => 'string', 'required' => true ],
                'platform_type' => [ 'type' => 'string', 'default' => 'WEBCHAT' ],
                'limit'         => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
            ],
        ] );

        register_rest_route( self::NS, '/chat/history', [
            'methods'             => 'DELETE',
            'callback'            => [ $this, 'chat_clear' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'session_id'    => [ 'type' => 'string', 'required' => true ],
                'platform_type' => [ 'type' => 'string', 'default' => 'WEBCHAT' ],
            ],
        ] );

        register_rest_route( self::NS, '/chat/stream', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'chat_stream' ],
            'permission_callback' => [ $this, 'perm_chat' ],
            'args'                => [
                'message'       => [ 'type' => 'string', 'required' => true ],
                'session_id'    => [ 'type' => 'string', 'default' => '' ],
                'character_id'  => [ 'type' => 'integer', 'default' => 0 ],
                'platform_type' => [ 'type' => 'string', 'default' => 'WEBCHAT' ],
                'plugin_slug'   => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );

        // ── SESSIONS ──
        register_rest_route( self::NS, '/sessions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'session_list' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
            'args'                => [
                'project_id'    => [ 'type' => 'string', 'default' => '' ],
                'search'        => [ 'type' => 'string', 'default' => '' ],
                'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT' ],
                'limit'         => [ 'type' => 'integer', 'default' => 50 ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'session_create' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
            'args'                => [
                'title'         => [ 'type' => 'string', 'default' => '' ],
                'project_id'    => [ 'type' => 'string', 'default' => '' ],
                'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/close-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'session_close_all' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
            'args'                => [
                'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>[\d]+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'session_rename' ],
                'permission_callback' => [ $this, 'perm_logged_in' ],
                'args'                => [
                    'title' => [ 'type' => 'string', 'required' => true ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'session_delete' ],
                'permission_callback' => [ $this, 'perm_logged_in' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>[\d]+)/move', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'session_move' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
            'args'                => [
                'project_id' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>[\\w-]+)/messages', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'session_messages' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>[\\w-]+)/poll', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'session_poll' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
            'args'                => [
                'since_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>[\d]+)/title', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'session_gen_title' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
            'args'                => [
                'user_message' => [ 'type' => 'string', 'default' => '' ],
                'bot_reply'    => [ 'type' => 'string', 'default' => '' ],
            ],
        ] );

        // ── PROJECTS ──
        register_rest_route( self::NS, '/projects', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'project_list' ],
                'permission_callback' => [ $this, 'perm_logged_in' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'project_create' ],
                'permission_callback' => [ $this, 'perm_logged_in' ],
                'args'                => [
                    'name'         => [ 'type' => 'string', 'required' => true ],
                    'icon'         => [ 'type' => 'string', 'default' => '📁' ],
                    'character_id' => [ 'type' => 'integer', 'default' => 0 ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/projects/(?P<id>[\\w-]+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ $this, 'project_update' ],
                'permission_callback' => [ $this, 'perm_logged_in' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'project_delete' ],
                'permission_callback' => [ $this, 'perm_logged_in' ],
            ],
        ] );

        // ── CONTEXT ──
        register_rest_route( self::NS, '/session', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_session_id' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'platform_type' => [ 'type' => 'string', 'default' => 'WEBCHAT' ],
            ],
        ] );

        register_rest_route( self::NS, '/emotion', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_emotion' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'text' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/bond', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_bond' ],
            'permission_callback' => [ $this, 'perm_logged_in' ],
        ] );

        register_rest_route( self::NS, '/characters', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_characters' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NS, '/plugins', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_plugins' ],
            'permission_callback' => '__return_true',
        ] );

        // ── BOOTSTRAP (all-in-one for SPA initial load) ──
        register_rest_route( self::NS, '/bootstrap', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'bootstrap' ],
            'permission_callback' => '__return_true',
        ] );
    }

    /* ================================================================
     * PERMISSION CALLBACKS
     * ================================================================ */

    public function perm_logged_in() {
        return is_user_logged_in();
    }

    public function perm_chat( $request ) {
        if ( is_user_logged_in() ) {
            return true;
        }
        $platform = strtoupper( $request->get_param( 'platform_type' ) ?: 'WEBCHAT' );
        return $platform === 'WEBCHAT';
    }

    /* ================================================================
     * AUTH HANDLERS
     * ================================================================ */

    public function auth_login( $request ) {
        $result = BizCity_Auth_Service::instance()->login(
            $request->get_param( 'username' ),
            $request->get_param( 'password' )
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 401 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 200 );
    }

    public function auth_register( $request ) {
        $result = BizCity_Auth_Service::instance()->register( [
            'phone'        => $request->get_param( 'phone' ),
            'email'        => $request->get_param( 'email' ),
            'username'     => $request->get_param( 'username' ),
            'display_name' => $request->get_param( 'display_name' ),
            'password'     => $request->get_param( 'password' ),
        ] );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 201 );
    }

    public function auth_me( $request ) {
        $info = BizCity_Auth_Service::instance()->get_current_user_info();

        if ( ! $info ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Not authenticated',
                'data'    => [ 'logged_in' => false ],
            ], 200 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => array_merge( $info, [ 'logged_in' => true ] ),
        ], 200 );
    }

    public function auth_logout( $request ) {
        wp_logout();
        return new WP_REST_Response( [
            'success' => true,
            'message' => 'Đã đăng xuất',
        ], 200 );
    }

    /* ================================================================
     * CHAT HANDLERS
     * ================================================================ */

    public function chat_send( $request ) {
        $send_svc = BizCity_Chat_Send_Service::instance();

        $result = $send_svc->send( [
            'message'       => $request->get_param( 'message' ),
            'character_id'  => $request->get_param( 'character_id' ),
            'session_id'    => $request->get_param( 'session_id' ),
            'images'        => $request->get_param( 'images' ) ?: [],
            'platform_type' => $request->get_param( 'platform_type' ),
            'plugin_slug'   => $request->get_param( 'plugin_slug' ),
            'routing_mode'  => $request->get_param( 'routing_mode' ),
            'provider_hint' => $request->get_param( 'provider_hint' ),
            'user_id'       => get_current_user_id(),
        ] );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $result->get_error_message(),
            ], 400 );
        }

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 200 );
    }

    public function chat_history( $request ) {
        $history = BizCity_Chat_History_Service::instance()->get_history(
            $request->get_param( 'session_id' ),
            strtoupper( $request->get_param( 'platform_type' ) ),
            $request->get_param( 'limit' )
        );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [ 'messages' => $history, 'count' => count( $history ) ],
        ], 200 );
    }

    public function chat_clear( $request ) {
        $result = BizCity_Chat_History_Service::instance()->clear_history(
            $request->get_param( 'session_id' ),
            strtoupper( $request->get_param( 'platform_type' ) )
        );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 200 );
    }

    public function chat_stream( $request ) {
        // SSE streaming: proxy to Chat Gateway's stream handler
        // The stream needs to write directly to output, so we set headers and delegate
        $gateway = class_exists( 'BizCity_Chat_Gateway' ) ? BizCity_Chat_Gateway::instance() : null;
        if ( ! $gateway || ! method_exists( $gateway, 'ajax_stream' ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Streaming not available',
            ], 503 );
        }

        // Populate $_POST from query params for gateway compatibility
        $_POST['message']       = $request->get_param( 'message' );
        $_POST['session_id']    = $request->get_param( 'session_id' );
        $_POST['character_id']  = $request->get_param( 'character_id' );
        $_POST['platform_type'] = $request->get_param( 'platform_type' );
        $_POST['plugin_slug']   = $request->get_param( 'plugin_slug' );

        // SSE headers
        header( 'Content-Type: text/event-stream' );
        header( 'Cache-Control: no-cache' );
        header( 'Connection: keep-alive' );
        header( 'X-Accel-Buffering: no' );

        $gateway->ajax_stream();
        exit;
    }

    /* ================================================================
     * SESSION HANDLERS
     * ================================================================ */

    public function session_list( $request ) {
        $data = BizCity_Session_Service::instance()->list_sessions(
            get_current_user_id(),
            strtoupper( $request->get_param( 'platform_type' ) ),
            $request->get_param( 'project_id' ) ?: null,
            $request->get_param( 'search' ),
            $request->get_param( 'limit' )
        );

        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], 200 );
    }

    public function session_create( $request ) {
        $result = BizCity_Session_Service::instance()->create_session(
            get_current_user_id(),
            strtoupper( $request->get_param( 'platform_type' ) ),
            $request->get_param( 'title' ),
            $request->get_param( 'project_id' )
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 400 );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 201 );
    }

    public function session_rename( $request ) {
        $result = BizCity_Session_Service::instance()->rename_session(
            intval( $request['id'] ),
            $request->get_param( 'title' ),
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 404 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function session_delete( $request ) {
        $result = BizCity_Session_Service::instance()->delete_session(
            intval( $request['id'] ),
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 404 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function session_move( $request ) {
        $result = BizCity_Session_Service::instance()->move_session(
            intval( $request['id'] ),
            $request->get_param( 'project_id' ),
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 404 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function session_messages( $request ) {
        $result = BizCity_Session_Service::instance()->get_session_messages(
            $request['id'],
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 404 );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public function session_poll( $request ) {
        $session_id = $request['id'];
        $since_id   = intval( $request->get_param( 'since_id' ) );

        $messages = BizCity_Chat_History_Service::instance()->poll( $session_id, $since_id );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [ 'messages' => $messages ],
        ], 200 );
    }

    public function session_gen_title( $request ) {
        $result = BizCity_Session_Service::instance()->generate_title(
            intval( $request['id'] ),
            $request->get_param( 'user_message' ),
            $request->get_param( 'bot_reply' ),
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 404 );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public function session_close_all( $request ) {
        $count = BizCity_Session_Service::instance()->close_all(
            get_current_user_id(),
            strtoupper( $request->get_param( 'platform_type' ) )
        );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [ 'closed_count' => $count ],
        ], 200 );
    }

    /* ================================================================
     * PROJECT HANDLERS
     * ================================================================ */

    public function project_list( $request ) {
        $data = BizCity_Project_Service::instance()->list_projects( get_current_user_id() );
        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], 200 );
    }

    public function project_create( $request ) {
        $result = BizCity_Project_Service::instance()->create_project(
            get_current_user_id(),
            $request->get_param( 'name' ),
            $request->get_param( 'icon' ),
            $request->get_param( 'character_id' )
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 400 );
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 201 );
    }

    public function project_update( $request ) {
        $data = $request->get_json_params();
        unset( $data['id'] );

        $result = BizCity_Project_Service::instance()->update_project(
            $request['id'],
            $data,
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 400 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    public function project_delete( $request ) {
        $result = BizCity_Project_Service::instance()->delete_project(
            $request['id'],
            get_current_user_id()
        );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 400 );
        }

        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    /* ================================================================
     * CONTEXT / UTILITY HANDLERS
     * ================================================================ */

    public function get_session_id( $request ) {
        $platform = strtoupper( $request->get_param( 'platform_type' ) );
        $sid      = BizCity_Session_Service::instance()->generate_session_id( $platform );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'session_id'    => $sid,
                'platform_type' => $platform,
                'user_id'       => get_current_user_id(),
                'blog_id'       => get_current_blog_id(),
            ],
        ], 200 );
    }

    public function get_emotion( $request ) {
        $text = $request->get_param( 'text' );

        if ( ! class_exists( 'BizCity_Emotional_Memory' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Emotion engine not available' ], 503 );
        }

        $result = BizCity_Emotional_Memory::instance()->estimate_emotion( $text );
        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
    }

    public function get_bond( $request ) {
        if ( ! class_exists( 'BizCity_Emotional_Memory' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Emotion engine not available' ], 503 );
        }

        $bond = BizCity_Emotional_Memory::instance()->get_bond_score( get_current_user_id() );
        return new WP_REST_Response( [
            'success' => true,
            'data'    => [ 'user_id' => get_current_user_id(), 'bond_score' => $bond ],
        ], 200 );
    }

    public function get_characters( $request ) {
        if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
            return new WP_REST_Response( [ 'success' => true, 'data' => [] ], 200 );
        }

        $db    = BizCity_Knowledge_Database::instance();
        $chars = $db->get_characters( [ 'status' => 'active' ] );
        $data  = [];

        foreach ( (array) $chars as $c ) {
            $data[] = [
                'id'          => (int) $c->id,
                'name'        => $c->name,
                'slug'        => $c->slug ?? '',
                'description' => $c->description ?? '',
                'model_id'    => $c->model_id ?? '',
                'avatar_url'  => $c->avatar_url ?? '',
                'status'      => $c->status,
            ];
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], 200 );
    }

    public function get_plugins( $request ) {
        // Use Plugin Suggestion API if available
        if ( class_exists( 'BizCity_Plugin_Suggestion_API' ) ) {
            $suggestions = BizCity_Plugin_Suggestion_API::instance()->get_suggestions();
            return new WP_REST_Response( [ 'success' => true, 'data' => $suggestions ], 200 );
        }

        // Fallback: get from tool registry
        $plugins = apply_filters( 'bizcity_agent_plugins_list', [] );
        return new WP_REST_Response( [ 'success' => true, 'data' => $plugins ], 200 );
    }

    /**
     * Bootstrap endpoint — returns all initial data needed by SPA.
     * Single request replaces 5+ separate API calls on page load.
     */
    public function bootstrap( $request ) {
        $user_info  = BizCity_Auth_Service::instance()->get_current_user_info();
        $session_svc = BizCity_Session_Service::instance();

        $data = [
            'user'           => $user_info ? array_merge( $user_info, [ 'logged_in' => true ] ) : [ 'logged_in' => false ],
            'blog_id'        => get_current_blog_id(),
            'site_name'      => get_bloginfo( 'name' ),
            'rest_url'       => rest_url( self::NS . '/' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'default_character_id' => $session_svc->get_default_character_id(),
        ];

        // Logged-in extras
        if ( $user_info ) {
            $data['sessions']  = $session_svc->list_sessions( $user_info['user_id'], 'ADMINCHAT', null, '', 20 );
            $data['projects']  = BizCity_Project_Service::instance()->list_projects( $user_info['user_id'] );
        }

        // Characters (always public)
        if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
            $chars = BizCity_Knowledge_Database::instance()->get_characters( [ 'status' => 'active' ] );
            $data['characters'] = [];
            foreach ( (array) $chars as $c ) {
                $data['characters'][] = [
                    'id'          => (int) $c->id,
                    'name'        => $c->name,
                    'description' => $c->description ?? '',
                    'model_id'    => $c->model_id ?? '',
                    'avatar_url'  => $c->avatar_url ?? '',
                ];
            }
        }

        return new WP_REST_Response( [ 'success' => true, 'data' => $data ], 200 );
    }
}
