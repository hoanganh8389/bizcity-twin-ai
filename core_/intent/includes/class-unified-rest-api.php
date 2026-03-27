<?php
/**
 * BizCity Unified REST API
 *
 * Single namespace `bizcity/v1` that consolidates all endpoints
 * from bizcity-intent, bizcity-knowledge, and bizcity-bot-webchat.
 *
 * Designed as the primary API surface for bizcity-app (React/Next.js).
 *
 * Auth strategy (3-tier):
 *   1. JWT token (Bearer header) — stateless, mobile-friendly
 *   2. API key (X-BizCity-API-Key) — external integrations
 *   3. WP cookie — admin dashboard fallback
 *
 * @package BizCity_Intent
 * @since   4.5.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Unified_REST_API {

    /* ── Singleton ── */
    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const NS = 'bizcity/v1';

    /** @var int|null Cached user ID from JWT auth */
    private $jwt_user_id = null;

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /* ================================================================
     *  ROUTE REGISTRATION
     * ================================================================ */

    public function register_routes() {

        /* ── Auth ── */

        register_rest_route( self::NS, '/auth/login', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_auth_login' ],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'password' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/auth/register', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_auth_register' ],
            'permission_callback' => '__return_true',
            'args' => [
                'phone'        => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'display_name' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'password'     => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NS, '/auth/me', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_auth_me' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NS, '/auth/refresh', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_auth_refresh' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NS, '/auth/logout', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_auth_logout' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        /* ── Chat ── */

        register_rest_route( self::NS, '/chat/send', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_chat_send' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'message'       => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
                'session_id'    => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'character_id'  => [ 'type' => 'integer', 'default' => 0 ],
                'images'        => [ 'type' => 'array', 'default' => [] ],
                'plugin_slug'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'routing_mode'  => [ 'type' => 'string', 'default' => 'automatic', 'sanitize_callback' => 'sanitize_text_field' ],
                'provider_hint' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT', 'sanitize_callback' => 'sanitize_text_field' ],
                'tool_goal'     => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'tool_name'     => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/chat/history', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_chat_history' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'session_id'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                    'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT', 'sanitize_callback' => 'sanitize_text_field' ],
                    'limit'         => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'rest_chat_clear' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'session_id'    => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                    'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/chat/pull', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_chat_pull' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'session_id' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                'after_id'   => [ 'type' => 'integer', 'default' => 0 ],
            ],
        ] );

        /* ── Sessions ── */

        register_rest_route( self::NS, '/sessions', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_sessions_list' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args'                => $this->paged_args( [
                    'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT', 'sanitize_callback' => 'sanitize_text_field' ],
                    'status'        => [ 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ],
                    'project_id'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'search'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ] ),
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_sessions_create' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'title'         => [ 'type' => 'string', 'default' => '',          'sanitize_callback' => 'sanitize_text_field' ],
                    'project_id'    => [ 'type' => 'string', 'default' => '',          'sanitize_callback' => 'sanitize_text_field' ],
                    'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT', 'sanitize_callback' => 'sanitize_text_field' ],
                    'character_id'  => [ 'type' => 'integer', 'default' => 0 ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_sessions_stats' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'platform_type' => [ 'type' => 'string', 'default' => 'ADMINCHAT', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_sessions_get' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'rest_sessions_update' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'title' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'rest_sessions_delete' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)/messages', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_sessions_messages' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/by-sid/(?P<sid>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_sessions_by_sid' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)/move', [
            'methods'             => 'PUT',
            'callback'            => [ $this, 'rest_sessions_move' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'project_id' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/sessions/(?P<id>\d+)/gen-title', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_sessions_gen_title' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NS, '/sessions/close-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_sessions_close_all' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        /* ── Tasks (Intent Conversations) ── */

        register_rest_route( self::NS, '/tasks', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tasks_list' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args'                => $this->paged_args( [
                'channel'    => [ 'type' => 'string', 'default' => 'adminchat', 'sanitize_callback' => 'sanitize_text_field' ],
                'status'     => [ 'type' => 'string', 'default' => 'all', 'sanitize_callback' => 'sanitize_text_field' ],
                'project_id' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'search'     => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ] ),
        ] );

        register_rest_route( self::NS, '/tasks/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tasks_stats' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'channel' => [ 'type' => 'string', 'default' => 'adminchat', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/tasks/close-all', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_tasks_close_all' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'channel'    => [ 'type' => 'string', 'default' => 'adminchat', 'sanitize_callback' => 'sanitize_text_field' ],
                'session_id' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/tasks/(?P<id>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tasks_get' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        register_rest_route( self::NS, '/tasks/(?P<id>[a-zA-Z0-9_-]+)/turns', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tasks_turns' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
                'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        register_rest_route( self::NS, '/tasks/(?P<id>[a-zA-Z0-9_-]+)/cancel', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_tasks_cancel' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );

        /* ── Projects ── */

        register_rest_route( self::NS, '/projects', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_projects_list' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_projects_create' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'name'          => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
                    'description'   => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'icon'          => [ 'type' => 'string', 'default' => '📁', 'sanitize_callback' => 'sanitize_text_field' ],
                    'color'         => [ 'type' => 'string', 'default' => '#6366f1', 'sanitize_callback' => 'sanitize_hex_color' ],
                    'character_id'  => [ 'type' => 'integer', 'default' => 0 ],
                    'knowledge_ids' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_projects_get' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'rest_projects_update' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'name'          => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'description'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
                    'icon'          => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'color'         => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color' ],
                    'character_id'  => [ 'type' => 'integer' ],
                    'knowledge_ids' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ $this, 'rest_projects_delete' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
        ] );

        register_rest_route( self::NS, '/projects/(?P<id>\d+)/sessions', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_projects_sessions' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'limit' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            ],
        ] );

        /* ── Tools & Agents ── */

        register_rest_route( self::NS, '/tools/search', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tools_search' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'query'       => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'plugin_slug' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'limit'       => [ 'type' => 'integer', 'default' => 5, 'minimum' => 1, 'maximum' => 20 ],
            ],
        ] );

        register_rest_route( self::NS, '/tools/estimate', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_tools_estimate' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'message' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/agents', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_agents_list' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'query' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
                'limit' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50 ],
            ],
        ] );

        register_rest_route( self::NS, '/agents/(?P<slug>[a-zA-Z0-9\-_]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_agents_detail' ],
            'permission_callback' => [ $this, 'check_auth' ],
            'args' => [
                'session_id' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        /* ── User ── */

        register_rest_route( self::NS, '/user/profile', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_user_profile' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'rest_user_profile_update' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'display_name' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                    'email'        => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/user/settings', [
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'rest_user_settings' ],
                'permission_callback' => [ $this, 'check_auth' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ $this, 'rest_user_settings_update' ],
                'permission_callback' => [ $this, 'check_auth' ],
                'args' => [
                    'settings' => [ 'type' => 'object', 'required' => true ],
                ],
            ],
        ] );

        /* ── Companion ── */

        register_rest_route( self::NS, '/companion/emotion', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_companion_emotion' ],
            'permission_callback' => '__return_true',
            'args' => [
                'text' => [ 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_textarea_field' ],
            ],
        ] );

        register_rest_route( self::NS, '/companion/bond', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_companion_bond' ],
            'permission_callback' => [ $this, 'check_auth' ],
        ] );
    }

    /* ================================================================
     *  AUTH — JWT Token Management
     * ================================================================ */

    /**
     * 3-tier auth check: JWT → API key → WP cookie.
     */
    public function check_auth( WP_REST_Request $request ) {
        // Tier 1: JWT Bearer token
        $auth_header = $request->get_header( 'Authorization' );
        if ( $auth_header && stripos( $auth_header, 'Bearer ' ) === 0 ) {
            $token   = substr( $auth_header, 7 );
            $user_id = $this->verify_jwt( $token );
            if ( $user_id ) {
                wp_set_current_user( $user_id );
                $this->jwt_user_id = $user_id;
                return true;
            }
            // Invalid token — don't fall through, reject immediately
            return new WP_Error( 'invalid_token', 'Invalid or expired token', [ 'status' => 401 ] );
        }

        // Tier 2: API key
        $api_key = $request->get_header( 'X-BizCity-API-Key' );
        if ( $api_key ) {
            $stored = get_option( 'bizcity_webchat_api_key', '' );
            if ( $stored && hash_equals( $stored, $api_key ) ) {
                return true;
            }
            return new WP_Error( 'invalid_api_key', 'Invalid API key', [ 'status' => 401 ] );
        }

        // Tier 3: WP cookie/nonce (admin dashboard legacy)
        if ( is_user_logged_in() ) {
            return true;
        }

        return new WP_Error( 'unauthorized', 'Authentication required', [ 'status' => 401 ] );
    }

    /**
     * Generate a JWT-like token (transient-backed).
     *
     * Uses WordPress transients for storage — no external JWT library needed.
     * Token = random 64-char hex string, stored as transient with user_id.
     */
    private function issue_token( $user_id, $ttl = DAY_IN_SECONDS * 7 ) {
        $token = bin2hex( random_bytes( 32 ) );
        $key   = 'bizcity_jwt_' . hash( 'sha256', $token );

        set_transient( $key, [
            'user_id'    => (int) $user_id,
            'issued_at'  => time(),
            'expires_at' => time() + $ttl,
        ], $ttl );

        return $token;
    }

    /**
     * Verify a token and return user_id or false.
     */
    private function verify_jwt( $token ) {
        if ( empty( $token ) || strlen( $token ) !== 64 ) {
            return false;
        }

        $key  = 'bizcity_jwt_' . hash( 'sha256', $token );
        $data = get_transient( $key );

        if ( ! $data || ! isset( $data['user_id'] ) ) {
            return false;
        }

        if ( isset( $data['expires_at'] ) && $data['expires_at'] < time() ) {
            delete_transient( $key );
            return false;
        }

        return (int) $data['user_id'];
    }

    /**
     * Revoke a token.
     */
    private function revoke_token( $token ) {
        if ( empty( $token ) ) {
            return;
        }
        $key = 'bizcity_jwt_' . hash( 'sha256', $token );
        delete_transient( $key );
    }

    /* ================================================================
     *  AUTH ENDPOINTS
     * ================================================================ */

    public function rest_auth_login( WP_REST_Request $request ) {
        $username = $request->get_param( 'username' );
        $password = $request->get_param( 'password' );

        $user = wp_signon( [
            'user_login'    => $username,
            'user_password' => $password,
            'remember'      => true,
        ] );

        if ( is_wp_error( $user ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $user->get_error_message(),
            ], 401 );
        }

        wp_set_current_user( $user->ID );
        $token = $this->issue_token( $user->ID );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'token'        => $token,
                'user_id'      => $user->ID,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
            ],
        ], 200 );
    }

    public function rest_auth_register( WP_REST_Request $request ) {
        $phone        = $request->get_param( 'phone' );
        $display_name = $request->get_param( 'display_name' );
        $password     = $request->get_param( 'password' );

        $username = 'user_' . preg_replace( '/[^0-9]/', '', $phone );

        if ( username_exists( $username ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Số điện thoại đã được đăng ký',
            ], 409 );
        }

        $user_id = wp_create_user( $username, $password, $username . '@phone.local' );
        if ( is_wp_error( $user_id ) ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => $user_id->get_error_message(),
            ], 400 );
        }

        wp_update_user( [
            'ID'           => $user_id,
            'display_name' => $display_name ?: $phone,
        ] );
        update_user_meta( $user_id, 'phone', $phone );

        wp_set_auth_cookie( $user_id, true );
        wp_set_current_user( $user_id );

        $token = $this->issue_token( $user_id );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'token'        => $token,
                'user_id'      => $user_id,
                'display_name' => $display_name ?: $phone,
                'nonce'        => wp_create_nonce( 'wp_rest' ),
            ],
        ], 201 );
    }

    public function rest_auth_me( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        $user = wp_get_current_user();

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'user_id'      => $user->ID,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
                'roles'        => $user->roles,
                'phone'        => get_user_meta( $user->ID, 'phone', true ),
            ],
        ] );
    }

    public function rest_auth_refresh( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }

        // Revoke old token if present
        $auth_header = $request->get_header( 'Authorization' );
        if ( $auth_header && stripos( $auth_header, 'Bearer ' ) === 0 ) {
            $this->revoke_token( substr( $auth_header, 7 ) );
        }

        $new_token = $this->issue_token( $user_id );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'token' => $new_token,
                'nonce' => wp_create_nonce( 'wp_rest' ),
            ],
        ] );
    }

    public function rest_auth_logout( WP_REST_Request $request ) {
        $auth_header = $request->get_header( 'Authorization' );
        if ( $auth_header && stripos( $auth_header, 'Bearer ' ) === 0 ) {
            $this->revoke_token( substr( $auth_header, 7 ) );
        }

        wp_logout();

        return rest_ensure_response( [ 'success' => true ] );
    }

    /* ================================================================
     *  CHAT ENDPOINTS
     *  Delegates to BizCity_Chat_Gateway + BizCity_WebChat_Database
     * ================================================================ */

    public function rest_chat_send( WP_REST_Request $request ) {
        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Gateway not available' ], 500 );
        }

        $message       = $request->get_param( 'message' );
        $session_id    = $request->get_param( 'session_id' );
        $character_id  = intval( $request->get_param( 'character_id' ) );
        $images        = $request->get_param( 'images' );
        $plugin_slug   = $request->get_param( 'plugin_slug' );
        $routing_mode  = $request->get_param( 'routing_mode' );
        $provider_hint = $request->get_param( 'provider_hint' );
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );
        $tool_goal     = $request->get_param( 'tool_goal' );
        $tool_name     = $request->get_param( 'tool_name' );

        if ( empty( $message ) && empty( $images ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Tin nhắn trống' ], 400 );
        }

        if ( ! $character_id ) {
            $character_id = $this->get_default_character_id();
        }

        if ( ! $session_id ) {
            $session_id = $this->generate_session_id( $platform_type );
        }

        $user_id     = get_current_user_id();
        $user        = wp_get_current_user();
        $client_name = $user->ID ? ( $user->display_name ?: $user->user_login ) : 'Guest';

        // Log user message
        $this->log_chat_message( [
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'client_name'   => $client_name,
            'message_id'    => uniqid( 'urest_' ),
            'message_text'  => $message ?: '[Image]',
            'message_from'  => 'user',
            'message_type'  => ! empty( $images ) ? 'image' : 'text',
            'attachments'   => is_array( $images ) ? $images : [],
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug,
        ] );

        // Pre-AI filter (intent engine intercept, plugin gathering)
        $pre_reply = apply_filters( 'bizcity_chat_pre_ai_response', null, [
            'message'       => $message,
            'character_id'  => $character_id,
            'session_id'    => $session_id,
            'user_id'       => $user_id,
            'platform_type' => $platform_type,
            'images'        => is_array( $images ) ? $images : [],
            'plugin_slug'   => $plugin_slug,
            'provider_hint' => $provider_hint,
            'routing_mode'  => $routing_mode,
            'tool_goal'     => $tool_goal,
            'tool_name'     => $tool_name,
        ] );

        if ( is_array( $pre_reply ) && ! empty( $pre_reply['message'] ) ) {
            $bot_msg_id     = uniqid( 'urest_bot_' );
            $effective_slug = $pre_reply['plugin_slug'] ?? $plugin_slug;

            $this->log_chat_message( [
                'session_id'    => $session_id,
                'user_id'       => 0,
                'client_name'   => 'AI Assistant',
                'message_id'    => $bot_msg_id,
                'message_text'  => $pre_reply['message'],
                'message_from'  => 'bot',
                'message_type'  => 'text',
                'platform_type' => $platform_type,
                'plugin_slug'   => $effective_slug,
            ] );

            return new WP_REST_Response( [
                'success' => true,
                'data'    => [
                    'message'         => $pre_reply['message'],
                    'plugin_slug'     => $effective_slug,
                    'action'          => $pre_reply['action'] ?? '',
                    'goal'            => $pre_reply['goal'] ?? '',
                    'goal_label'      => $pre_reply['goal_label'] ?? '',
                    'focus_mode'      => $pre_reply['focus_mode'] ?? 'none',
                    'bot_message_id'  => $bot_msg_id,
                    'session_id'      => $session_id,
                    'conversation_id' => $pre_reply['conversation_id'] ?? '',
                ],
            ], 200 );
        }

        // Direct AI response
        try {
            $reply_data = $gateway->get_ai_response(
                $character_id, $message, is_array( $images ) ? $images : [],
                $session_id, '[]', $user_id, $platform_type
            );
        } catch ( \Exception $e ) {
            return new WP_REST_Response( [
                'success' => false,
                'message' => 'Có lỗi xảy ra: ' . $e->getMessage(),
            ], 500 );
        }

        $bot_msg_id = uniqid( 'urest_bot_' );
        $this->log_chat_message( [
            'session_id'    => $session_id,
            'user_id'       => 0,
            'client_name'   => $reply_data['character_name'] ?? 'AI Assistant',
            'message_id'    => $bot_msg_id,
            'message_text'  => $reply_data['message'],
            'message_from'  => 'bot',
            'message_type'  => 'text',
            'platform_type' => $platform_type,
            'plugin_slug'   => $plugin_slug,
        ] );

        return new WP_REST_Response( [
            'success' => true,
            'data'    => [
                'message'        => $reply_data['message'],
                'provider'       => $reply_data['provider'] ?? '',
                'model'          => $reply_data['model'] ?? '',
                'usage'          => $reply_data['usage'] ?? [],
                'vision_used'    => $reply_data['vision_used'] ?? false,
                'plugin_slug'    => $plugin_slug,
                'focus_mode'     => 'none',
                'bot_message_id' => $bot_msg_id,
                'session_id'     => $session_id,
            ],
        ], 200 );
    }

    public function rest_chat_history( WP_REST_Request $request ) {
        $session_id    = $request->get_param( 'session_id' );
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );
        $limit         = intval( $request->get_param( 'limit' ) );

        $history = $this->get_chat_history( $session_id, $platform_type, $limit );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'messages'   => $history,
                'count'      => count( $history ),
                'session_id' => $session_id,
            ],
        ] );
    }

    public function rest_chat_clear( WP_REST_Request $request ) {
        $session_id    = $request->get_param( 'session_id' );
        $platform_type = strtoupper( $request->get_param( 'platform_type' ) );

        global $wpdb;
        $tbl_msg  = $wpdb->prefix . 'bizcity_webchat_messages';
        $tbl_conv = $wpdb->prefix . 'bizcity_webchat_conversations';

        $deleted = 0;
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl_msg ) ) === $tbl_msg ) {
            $deleted = (int) $wpdb->delete( $tbl_msg, [
                'session_id'    => $session_id,
                'platform_type' => $platform_type,
            ] );
        }

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tbl_conv ) ) === $tbl_conv ) {
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$tbl_conv}` SET status = 'closed', ended_at = NOW() WHERE session_id = %s AND platform_type = %s",
                $session_id, $platform_type
            ) );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'cleared' => true, 'deleted_count' => $deleted ],
        ] );
    }

    public function rest_chat_pull( WP_REST_Request $request ) {
        $session_id = $request->get_param( 'session_id' );
        $after_id   = intval( $request->get_param( 'after_id' ) );

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return rest_ensure_response( [ 'success' => true, 'data' => [ 'messages' => [] ] ] );
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE session_id = %s AND id > %d ORDER BY id ASC LIMIT 50",
            $session_id, $after_id
        ) );

        $messages = [];
        foreach ( (array) $rows as $row ) {
            $messages[] = [
                'id'          => (int) $row->id,
                'message_id'  => $row->message_id,
                'message'     => $row->message_text,
                'from'        => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $row->attachments ? json_decode( $row->attachments, true ) : [],
                'created_at'  => $row->created_at,
                'meta'        => $row->meta ? json_decode( $row->meta, true ) : [],
            ];
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'messages' => $messages ],
        ] );
    }

    /* ================================================================
     *  SESSION ENDPOINTS
     *  Delegates to BizCity_Session_List_Service + BizCity_WebChat_Database
     * ================================================================ */

    public function rest_sessions_list( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        return rest_ensure_response(
            BizCity_Session_List_Service::instance()->list_sessions( $user_id, $request->get_params() )
        );
    }

    public function rest_sessions_create( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $user        = wp_get_current_user();
        $client_name = $user->display_name ?: $user->user_login;

        $result = $wc_db->create_session_v3(
            $user_id,
            $client_name,
            strtoupper( $request->get_param( 'platform_type' ) ),
            $request->get_param( 'title' ),
            [
                'project_id'   => $request->get_param( 'project_id' ),
                'character_id' => intval( $request->get_param( 'character_id' ) ),
            ]
        );

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 201 );
    }

    public function rest_sessions_stats( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        return rest_ensure_response(
            BizCity_Session_List_Service::instance()->get_status_counts(
                $user_id,
                $request->get_param( 'platform_type' ) ?: 'ADMINCHAT'
            )
        );
    }

    public function rest_sessions_get( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $result = BizCity_Session_List_Service::instance()->get_session(
            (int) $request->get_param( 'id' ),
            $user_id
        );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    public function rest_sessions_update( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db   = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $session = $wc_db->get_session_v3( (int) $request->get_param( 'id' ) );
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        $update = [];
        $title  = $request->get_param( 'title' );
        if ( $title !== null ) {
            $update['title'] = $title;
        }

        if ( ! empty( $update ) ) {
            $wc_db->update_session_v3( $session->id, $update );
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_sessions_delete( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $session = $wc_db->get_session_v3( (int) $request->get_param( 'id' ) );
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        $wc_db->delete_session_v3( $session->id );
        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_sessions_messages( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $session = $wc_db->get_session_v3( (int) $request->get_param( 'id' ) );
        if ( ! $session ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        $result = BizCity_Session_List_Service::instance()->get_session_messages(
            $session->session_id,
            $user_id,
            $request->get_param( 'page' ),
            $request->get_param( 'per_page' )
        );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    public function rest_sessions_by_sid( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $result = BizCity_Session_List_Service::instance()->get_session_by_sid(
            $request->get_param( 'sid' ),
            $user_id
        );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    public function rest_sessions_move( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $session = $wc_db->get_session_v3( (int) $request->get_param( 'id' ) );
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        $project_id = $request->get_param( 'project_id' );
        $wc_db->move_session_to_project( $session->id, $project_id );

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_sessions_gen_title( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $session = $wc_db->get_session_v3( (int) $request->get_param( 'id' ) );
        if ( ! $session || (int) $session->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Session not found', [ 'status' => 404 ] );
        }

        // Get recent messages for title generation
        $messages = $wc_db->get_messages_by_session_id( $session->session_id, 10 );
        if ( empty( $messages ) ) {
            return rest_ensure_response( [ 'success' => true, 'data' => [ 'title' => 'New Chat' ] ] );
        }

        // Build context from messages
        $context = '';
        foreach ( $messages as $msg ) {
            $from     = $msg->message_from === 'user' ? 'User' : 'AI';
            $context .= "{$from}: {$msg->message_text}\n";
        }

        // Use gateway for title generation (fast mode)
        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            // Fallback: first user message
            $title = '';
            foreach ( $messages as $msg ) {
                if ( $msg->message_from === 'user' ) {
                    $title = mb_substr( $msg->message_text, 0, 50 );
                    break;
                }
            }
            $wc_db->update_session_v3( $session->id, [ 'title' => $title, 'title_generated' => 1 ] );
            return rest_ensure_response( [ 'success' => true, 'data' => [ 'title' => $title ] ] );
        }

        try {
            $prompt = "Generate a very short title (max 6 words, Vietnamese preferred) for this conversation. Return ONLY the title, no quotes:\n\n{$context}";
            $result = $gateway->get_ai_response(
                $this->get_default_character_id(),
                $prompt, [], $session->session_id, '[]', 0, 'SYSTEM'
            );
            $title = trim( $result['message'] ?? '' );
            $title = mb_substr( $title, 0, 80 );
        } catch ( \Exception $e ) {
            $title = mb_substr( $messages[0]->message_text ?? 'New Chat', 0, 50 );
        }

        $wc_db->update_session_v3( $session->id, [ 'title' => $title, 'title_generated' => 1 ] );

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'title' => $title ] ] );
    }

    public function rest_sessions_close_all( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $count = $wc_db->close_all_sessions( $user_id, 'ADMINCHAT' );
        return rest_ensure_response( [ 'success' => true, 'data' => [ 'closed' => $count ] ] );
    }

    /* ================================================================
     *  TASK ENDPOINTS
     *  Delegates to BizCity_Task_Service + BizCity_Intent_Database
     * ================================================================ */

    public function rest_tasks_list( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        return rest_ensure_response(
            BizCity_Task_Service::instance()->list_tasks( $user_id, $request->get_params() )
        );
    }

    public function rest_tasks_stats( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        return rest_ensure_response(
            BizCity_Task_Service::instance()->get_status_counts(
                $user_id,
                $request->get_param( 'channel' ) ?: 'adminchat'
            )
        );
    }

    public function rest_tasks_get( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $result  = BizCity_Task_Service::instance()->get_task(
            $request->get_param( 'id' ),
            $user_id
        );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    public function rest_tasks_turns( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        $result  = BizCity_Task_Service::instance()->get_task_turns(
            $request->get_param( 'id' ),
            $user_id,
            $request->get_param( 'page' ),
            $request->get_param( 'per_page' )
        );

        if ( is_wp_error( $result ) ) return $result;
        return rest_ensure_response( $result );
    }

    public function rest_tasks_cancel( WP_REST_Request $request ) {
        $user_id         = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $conversation_id = sanitize_text_field( $request->get_param( 'id' ) );

        // Verify ownership
        $db   = BizCity_Intent_Database::instance();
        $conv = $db->get_conversation( $conversation_id );
        if ( ! $conv || (int) $conv->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Task not found', [ 'status' => 404 ] );
        }

        BizCity_Intent_Conversation::instance()->cancel( $conversation_id, 'user_cancel' );

        return rest_ensure_response( [
            'success'         => true,
            'cancelled'       => true,
            'conversation_id' => $conversation_id,
        ] );
    }

    public function rest_tasks_close_all( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $channel    = $request->get_param( 'channel' ) ?: 'adminchat';
        $session_id = $request->get_param( 'session_id' );

        $db    = BizCity_Intent_Database::instance();
        $count = $db->close_all_for_user( $user_id, $channel, $session_id );

        return rest_ensure_response( [ 'success' => true, 'data' => [ 'closed' => $count ] ] );
    }

    /* ================================================================
     *  PROJECT ENDPOINTS
     *  Delegates to BizCity_WebChat_Database (V3 project methods)
     * ================================================================ */

    public function rest_projects_list( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $projects = $wc_db->get_projects_for_user( $user_id );

        // Enrich with session counts
        $result = [];
        foreach ( $projects as $p ) {
            $result[] = [
                'id'            => (int) $p->id,
                'project_id'    => $p->project_id,
                'name'          => $p->name,
                'description'   => $p->description ?? '',
                'icon'          => $p->icon ?? '📁',
                'color'         => $p->color ?? '#6366f1',
                'character_id'  => (int) ( $p->character_id ?? 0 ),
                'session_count' => (int) ( $p->session_count ?? 0 ),
                'is_public'     => (int) ( $p->is_public ?? 0 ),
                'knowledge_ids' => $p->knowledge_ids ?? '',
                'created_at'    => $p->created_at ?? '',
                'updated_at'    => $p->updated_at ?? '',
            ];
        }

        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    public function rest_projects_create( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $result = $wc_db->create_project( $user_id, $request->get_param( 'name' ), [
            'description'   => $request->get_param( 'description' ),
            'icon'          => $request->get_param( 'icon' ),
            'color'         => $request->get_param( 'color' ),
            'character_id'  => intval( $request->get_param( 'character_id' ) ),
            'knowledge_ids' => $request->get_param( 'knowledge_ids' ),
        ] );

        return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 201 );
    }

    public function rest_projects_get( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $project = $wc_db->get_project( (int) $request->get_param( 'id' ) );
        if ( ! $project || (int) $project->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Project not found', [ 'status' => 404 ] );
        }

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'id'            => (int) $project->id,
                'project_id'    => $project->project_id,
                'name'          => $project->name,
                'description'   => $project->description ?? '',
                'icon'          => $project->icon ?? '📁',
                'color'         => $project->color ?? '#6366f1',
                'character_id'  => (int) ( $project->character_id ?? 0 ),
                'session_count' => (int) ( $project->session_count ?? 0 ),
                'is_public'     => (int) ( $project->is_public ?? 0 ),
                'knowledge_ids' => $project->knowledge_ids ?? '',
                'settings'      => $project->settings ? json_decode( $project->settings, true ) : [],
                'created_at'    => $project->created_at ?? '',
                'updated_at'    => $project->updated_at ?? '',
            ],
        ] );
    }

    public function rest_projects_update( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $project = $wc_db->get_project( (int) $request->get_param( 'id' ) );
        if ( ! $project || (int) $project->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Project not found', [ 'status' => 404 ] );
        }

        $update = [];
        foreach ( [ 'name', 'description', 'icon', 'color', 'character_id', 'knowledge_ids' ] as $field ) {
            $val = $request->get_param( $field );
            if ( $val !== null ) {
                $update[ $field ] = $val;
            }
        }

        $wc_db->update_project( $project->id, $update );

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_projects_delete( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $project = $wc_db->get_project( (int) $request->get_param( 'id' ) );
        if ( ! $project || (int) $project->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Project not found', [ 'status' => 404 ] );
        }

        $wc_db->delete_project( $project->id );

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_projects_sessions( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $wc_db = $this->get_webchat_db();
        if ( ! $wc_db ) {
            return new WP_Error( 'no_db', 'Database unavailable', [ 'status' => 500 ] );
        }

        $project = $wc_db->get_project( (int) $request->get_param( 'id' ) );
        if ( ! $project || (int) $project->user_id !== $user_id ) {
            return new WP_Error( 'not_found', 'Project not found', [ 'status' => 404 ] );
        }

        $sessions = $wc_db->get_sessions_by_project( $project->project_id, intval( $request->get_param( 'limit' ) ) );

        $result = [];
        foreach ( $sessions as $s ) {
            $result[] = [
                'id'                   => (int) $s->id,
                'session_id'           => $s->session_id,
                'title'                => $s->title,
                'status'               => $s->status,
                'message_count'        => (int) ( $s->message_count ?? 0 ),
                'last_message_at'      => $s->last_message_at ?? '',
                'last_message_preview' => $s->last_message_preview ?? '',
                'started_at'           => $s->started_at ?? '',
            ];
        }

        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    /* ================================================================
     *  TOOLS & AGENTS ENDPOINTS
     *  Delegates to BizCity_Plugin_Suggestion_API
     * ================================================================ */

    public function rest_tools_search( WP_REST_Request $request ) {
        $api = $this->get_suggestion_api();
        if ( ! $api ) {
            return rest_ensure_response( [ 'success' => true, 'data' => [] ] );
        }

        $results = $api->search_tools(
            $request->get_param( 'query' ),
            $request->get_param( 'plugin_slug' ),
            intval( $request->get_param( 'limit' ) )
        );

        return rest_ensure_response( [ 'success' => true, 'data' => $results ] );
    }

    public function rest_tools_estimate( WP_REST_Request $request ) {
        $api = $this->get_suggestion_api();
        if ( ! $api ) {
            return rest_ensure_response( [ 'success' => true, 'data' => [ 'match' => null ] ] );
        }

        $result = $api->estimate_plugin_match( $request->get_param( 'message' ) );

        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    public function rest_agents_list( WP_REST_Request $request ) {
        $api = $this->get_suggestion_api();
        if ( ! $api ) {
            return rest_ensure_response( [ 'success' => true, 'data' => [] ] );
        }

        $results = $api->get_plugin_suggestions(
            $request->get_param( 'query' ),
            intval( $request->get_param( 'limit' ) )
        );

        return rest_ensure_response( [ 'success' => true, 'data' => $results ] );
    }

    public function rest_agents_detail( WP_REST_Request $request ) {
        $api = $this->get_suggestion_api();
        if ( ! $api ) {
            return new WP_Error( 'not_available', 'Agent API not available', [ 'status' => 503 ] );
        }

        $slug       = $request->get_param( 'slug' );
        $session_id = $request->get_param( 'session_id' );

        $result = $api->get_plugin_context( $slug, $session_id );

        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    /* ================================================================
     *  USER ENDPOINTS
     * ================================================================ */

    public function rest_user_profile( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $user = wp_get_current_user();

        return rest_ensure_response( [
            'success' => true,
            'data'    => [
                'user_id'      => $user->ID,
                'username'     => $user->user_login,
                'display_name' => $user->display_name,
                'email'        => $user->user_email,
                'avatar_url'   => get_avatar_url( $user->ID, [ 'size' => 96 ] ),
                'phone'        => get_user_meta( $user->ID, 'phone', true ),
                'registered'   => $user->user_registered,
            ],
        ] );
    }

    public function rest_user_profile_update( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $update = [ 'ID' => $user_id ];

        $display_name = $request->get_param( 'display_name' );
        if ( $display_name !== null ) {
            $update['display_name'] = $display_name;
        }

        $email = $request->get_param( 'email' );
        if ( $email !== null && is_email( $email ) ) {
            // Check if email already taken
            $existing = email_exists( $email );
            if ( $existing && $existing !== $user_id ) {
                return new WP_Error( 'email_taken', 'Email đã được sử dụng', [ 'status' => 409 ] );
            }
            $update['user_email'] = $email;
        }

        $result = wp_update_user( $update );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return rest_ensure_response( [ 'success' => true ] );
    }

    public function rest_user_settings( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $settings = get_user_meta( $user_id, 'bizcity_app_settings', true );
        if ( ! is_array( $settings ) ) {
            $settings = [
                'theme'         => 'dark',
                'language'      => 'vi',
                'notifications' => true,
                'default_model' => 'auto',
            ];
        }

        return rest_ensure_response( [ 'success' => true, 'data' => $settings ] );
    }

    public function rest_user_settings_update( WP_REST_Request $request ) {
        $user_id = $this->require_user();
        if ( is_wp_error( $user_id ) ) return $user_id;

        $new_settings = $request->get_param( 'settings' );
        if ( ! is_array( $new_settings ) ) {
            return new WP_Error( 'invalid', 'Settings must be an object', [ 'status' => 400 ] );
        }

        // Merge with existing
        $existing = get_user_meta( $user_id, 'bizcity_app_settings', true );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        // Whitelist allowed keys
        $allowed = [ 'theme', 'language', 'notifications', 'default_model', 'sidebar_collapsed' ];
        foreach ( $new_settings as $key => $val ) {
            if ( in_array( $key, $allowed, true ) ) {
                $existing[ $key ] = $val;
            }
        }

        update_user_meta( $user_id, 'bizcity_app_settings', $existing );

        return rest_ensure_response( [ 'success' => true, 'data' => $existing ] );
    }

    /* ================================================================
     *  COMPANION ENDPOINTS
     * ================================================================ */

    public function rest_companion_emotion( WP_REST_Request $request ) {
        if ( ! class_exists( 'BizCity_Emotional_Memory' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Emotional Memory not available' ], 503 );
        }

        $result = BizCity_Emotional_Memory::instance()->estimate_emotion( $request->get_param( 'text' ) );

        return rest_ensure_response( [ 'success' => true, 'data' => $result ] );
    }

    public function rest_companion_bond( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        if ( ! class_exists( 'BizCity_Emotional_Memory' ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => 'Emotional Memory not available' ], 503 );
        }

        $bond = BizCity_Emotional_Memory::instance()->get_bond_score( $user_id );

        return rest_ensure_response( [
            'success' => true,
            'data'    => [ 'bond_score' => $bond, 'user_id' => $user_id ],
        ] );
    }

    /* ================================================================
     *  INTERNAL HELPERS
     * ================================================================ */

    /**
     * Require logged-in user or return WP_Error.
     */
    private function require_user() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new WP_Error( 'unauthorized', 'Login required', [ 'status' => 401 ] );
        }
        return $user_id;
    }

    /**
     * Get BizCity_WebChat_Database instance.
     */
    private function get_webchat_db() {
        if ( class_exists( 'BizCity_WebChat_Database' ) ) {
            return BizCity_WebChat_Database::instance();
        }
        return null;
    }

    /**
     * Get BizCity_Chat_Gateway instance.
     */
    private function get_gateway() {
        if ( class_exists( 'BizCity_Chat_Gateway' ) ) {
            return BizCity_Chat_Gateway::instance();
        }
        return null;
    }

    /**
     * Get BizCity_Plugin_Suggestion_API instance.
     */
    private function get_suggestion_api() {
        if ( class_exists( 'BizCity_Plugin_Suggestion_API' ) ) {
            return BizCity_Plugin_Suggestion_API::instance();
        }
        return null;
    }

    /**
     * Default character ID from settings.
     */
    private function get_default_character_id() {
        $id = intval( get_option( 'bizcity_webchat_default_character_id', 0 ) );
        if ( ! $id ) {
            $bot_setup = get_option( 'pmfacebook_options', [] );
            $id = isset( $bot_setup['default_character_id'] ) ? intval( $bot_setup['default_character_id'] ) : 0;
        }
        return $id;
    }

    /**
     * Generate session ID for a platform.
     */
    private function generate_session_id( $platform_type = 'ADMINCHAT' ) {
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();

        if ( $user_id ) {
            return strtolower( $platform_type ) . '_' . $blog_id . '_' . $user_id;
        }

        return 'sess_' . wp_generate_uuid4();
    }

    /**
     * Pagination args builder.
     */
    private function paged_args( array $extra = [] ) {
        return array_merge( [
            'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
            'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
            'order'    => [ 'type' => 'string', 'default' => 'DESC', 'enum' => [ 'ASC', 'DESC' ] ],
        ], $extra );
    }

    /**
     * Log a chat message to the webchat_messages table.
     */
    private function log_chat_message( $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return;
        }

        $wpdb->insert( $table, [
            'session_id'    => $data['session_id'] ?? '',
            'user_id'       => (int) ( $data['user_id'] ?? 0 ),
            'client_name'   => $data['client_name'] ?? '',
            'message_id'    => $data['message_id'] ?? uniqid( 'msg_' ),
            'message_text'  => $data['message_text'] ?? '',
            'message_from'  => $data['message_from'] ?? 'user',
            'message_type'  => $data['message_type'] ?? 'text',
            'attachments'   => isset( $data['attachments'] ) ? wp_json_encode( $data['attachments'] ) : '[]',
            'platform_type' => $data['platform_type'] ?? 'ADMINCHAT',
            'meta'          => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : '{}',
            'created_at'    => current_time( 'mysql' ),
        ] );
    }

    /**
     * Get chat history by session.
     */
    private function get_chat_history( $session_id, $platform_type = 'ADMINCHAT', $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE session_id = %s AND platform_type = %s ORDER BY id ASC LIMIT %d",
            $session_id, $platform_type, $limit
        ) );

        $history = [];
        foreach ( (array) $rows as $row ) {
            $meta        = $row->meta ? json_decode( $row->meta, true ) : [];
            $attachments = $row->attachments ? json_decode( $row->attachments, true ) : [];

            $images = [];
            if ( is_array( $attachments ) ) {
                foreach ( $attachments as $att ) {
                    if ( is_string( $att ) && $att !== '' ) {
                        $images[] = $att;
                    } elseif ( is_array( $att ) ) {
                        $url = $att['url'] ?? ( $att['data'] ?? '' );
                        if ( $url ) {
                            $images[] = $url;
                        }
                    }
                }
            }

            $history[] = [
                'id'          => (int) $row->id,
                'message_id'  => $row->message_id,
                'message'     => $row->message_text,
                'from'        => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $attachments,
                'images'      => $images,
                'created_at'  => $row->created_at,
                'meta'        => $meta,
            ];
        }

        return $history;
    }
}
