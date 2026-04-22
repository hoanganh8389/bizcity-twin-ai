<?php
/**
 * BizCity Video Kling — Gateway Video Proxy
 *
 * Hub-side REST endpoints at bzvideo/v1/proxy/task that
 * act as transparent proxies from client sites to PiAPI.
 *
 * Used when client sites operate in gateway mode (no local PiAPI key)
 * for avatar / omni-human tasks that are not supported by the
 * /bizcity/llmhub/v1/video/generate route (which requires `prompt`).
 *
 * Auth: Bearer token validated against bizcity_llm_api_key site option
 * on the hub site (bizcity.vn).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Video_Gateway_Proxy {

    const PIAPI_BASE = 'https://api.piapi.ai/api/v1';
    const REST_NS    = 'bzvideo/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes(): void {
        // POST — create a new task (avatar, omni-human, etc.)
        register_rest_route( self::REST_NS, '/proxy/task', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'handle_create' ],
            'permission_callback' => [ __CLASS__, 'check_bearer' ],
            'args'                => [
                'model'     => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'task_type' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        // GET — poll task status
        register_rest_route( self::REST_NS, '/proxy/task/(?P<task_id>[A-Za-z0-9_\-]+)', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'handle_status' ],
            'permission_callback' => [ __CLASS__, 'check_bearer' ],
        ] );
    }

    /**
     * Validate Bearer token against hub's bizcity_llm_api_key.
     */
    public static function check_bearer( WP_REST_Request $request ): bool {
        $auth = $request->get_header( 'Authorization' );
        if ( ! $auth || strpos( $auth, 'Bearer ' ) !== 0 ) {
            return false;
        }
        $token = trim( substr( $auth, 7 ) );
        if ( $token === '' ) {
            return false;
        }

        // Prefer site-level option (hub may be network-activated)
        $hub_key = get_site_option( 'bizcity_llm_api_key', '' );
        if ( empty( $hub_key ) ) {
            $hub_key = get_option( 'bizcity_llm_api_key', '' );
        }
        if ( empty( $hub_key ) ) {
            return false;
        }

        return hash_equals( (string) $hub_key, (string) $token );
    }

    /**
     * Retrieve the hub's PiAPI key.
     */
    private static function get_piapi_key(): string {
        $key = trim( get_option( 'bizcity_video_kling_api_key', '' ) );
        if ( empty( $key ) && defined( 'BIZCITY_KLING_API_KEY' ) ) {
            $key = (string) BIZCITY_KLING_API_KEY;
        }
        return $key;
    }

    /**
     * Forward a task-creation request to PiAPI.
     */
    public static function handle_create( WP_REST_Request $request ): WP_REST_Response {
        $piapi_key = self::get_piapi_key();
        if ( empty( $piapi_key ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => 'Hub PiAPI key is not configured.' ],
                503
            );
        }

        $body = $request->get_json_params();
        if ( empty( $body ) || ! is_array( $body ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => 'Empty or invalid JSON payload.' ],
                400
            );
        }

        // Strip gateway-only fields before forwarding to PiAPI
        unset( $body['plugin_name'], $body['site_url'] );

        $response = wp_remote_post( self::PIAPI_BASE . '/task', [
            'timeout' => 60,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $piapi_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => $response->get_error_message() ],
                502
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return new WP_REST_Response( $data, $code );
    }

    /**
     * Forward a task-status request to PiAPI.
     */
    public static function handle_status( WP_REST_Request $request ): WP_REST_Response {
        $piapi_key = self::get_piapi_key();
        if ( empty( $piapi_key ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => 'Hub PiAPI key is not configured.' ],
                503
            );
        }

        $task_id = sanitize_text_field( $request->get_param( 'task_id' ) );
        if ( empty( $task_id ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => 'task_id is required.' ],
                400
            );
        }

        $response = wp_remote_get( self::PIAPI_BASE . '/task/' . rawurlencode( $task_id ), [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key'    => $piapi_key,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_REST_Response(
                [ 'success' => false, 'error' => $response->get_error_message() ],
                502
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        return new WP_REST_Response( $data, $code );
    }
}

BizCity_Video_Gateway_Proxy::init();
