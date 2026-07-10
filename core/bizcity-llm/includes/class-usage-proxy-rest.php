<?php
/**
 * BizCity Twin AI — Usage Stats Proxy REST Controller
 *
 * [2026-06-10 Johnny Chu] USAGE-ROLLUP-SPEC Phase 3 (R-GW-8)
 *
 * Client-side same-origin proxy for the hub's /bizcity/v1/account/* analytics
 * endpoints. Browser calls /wp-json/bizcity-twinchat/v1/account/* (X-WP-Nonce),
 * this proxy forwards server-side to the gateway using Bearer biz-xxx.
 *
 * Failure policy (fail-OPEN — R-GW-8):
 *   • All handlers always return HTTP 200.
 *   • Gateway failure / missing key → { ok: false, _degraded: true }.
 *   • FE should show empty/fallback UI on _degraded, not retry-loop.
 *
 * Routes (all require is_user_logged_in):
 *   GET  /bizcity-twinchat/v1/account/usage-summary       → /account/cost-summary
 *   GET  /bizcity-twinchat/v1/account/usage-by-type       → /account/usage-by-type
 *   GET  /bizcity-twinchat/v1/account/usage-by-model      → /account/usage-by-model
 *   GET  /bizcity-twinchat/v1/account/usage-by-day        → /account/usage-by-day
 *   GET  /bizcity-twinchat/v1/account/usage-logs          → /account/usage-logs
 *   GET  /bizcity-twinchat/v1/account/api-keys            → /account/api-keys
 *
 * NOTE: POST/DELETE api-keys are intentionally NOT proxied here.
 * Hub's create/revoke endpoints require WP cookie auth (is_user_logged_in) which
 * Bearer-token requests from a client site cannot satisfy. Key management MUST
 * happen directly on the hub's /my-account/api-keys/ page (wallet template).
 *
 * PHP 7.4 compat — no union types, no nullsafe, no str_contains.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since      USAGE-ROLLUP-SPEC Phase 3 (2026-06-10)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Usage_Proxy_REST {

    const NS = 'bizcity-twinchat/v1';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function register_routes() {
        $ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : self::NS;

        $auth = array( __CLASS__, 'check_logged_in' );

        // Analytics endpoints (GET)
        $analytics = array(
            'usage-summary'   => array( __CLASS__, 'handle_usage_summary' ),
            'usage-by-type'   => array( __CLASS__, 'handle_usage_by_type' ),
            'usage-by-model'  => array( __CLASS__, 'handle_usage_by_model' ),
            'usage-by-day'    => array( __CLASS__, 'handle_usage_by_day' ),
            'usage-logs'      => array( __CLASS__, 'handle_usage_logs' ),
        );
        foreach ( $analytics as $slug => $callback ) {
            register_rest_route( $ns, '/account/' . $slug, array(
                'methods'             => 'GET',
                'callback'            => $callback,
                'permission_callback' => $auth,
            ) );
        }

        // API Keys — GET only (read). POST/DELETE intentionally excluded:
        // Hub requires WP cookie auth for mutations; Bearer from client proxy cannot satisfy that.
        register_rest_route( $ns, '/account/api-keys', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_get_keys' ),
            'permission_callback' => $auth,
        ) );
    }

    public static function check_logged_in() {
        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'rest_forbidden', 'Login required.', array( 'status' => 401 ) );
        }
        return true;
    }

    // ── Analytics handlers ────────────────────────────────────────

    public static function handle_usage_summary( WP_REST_Request $request ) {
        return self::proxy_get( '/account/cost-summary', self::period_args( $request ) );
    }

    public static function handle_usage_by_type( WP_REST_Request $request ) {
        return self::proxy_get( '/account/usage-by-type', self::period_args( $request ) );
    }

    public static function handle_usage_by_model( WP_REST_Request $request ) {
        $args = self::period_args( $request );
        $svc  = $request->get_param( 'service' );
        if ( $svc ) { $args['service'] = sanitize_text_field( $svc ); }
        return self::proxy_get( '/account/usage-by-model', $args );
    }

    public static function handle_usage_by_day( WP_REST_Request $request ) {
        return self::proxy_get( '/account/usage-by-day', self::period_args( $request ) );
    }

    public static function handle_usage_logs( WP_REST_Request $request ) {
        $args = self::period_args( $request );
        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 30 ) ) );
        $args['page']     = $page;
        $args['per_page'] = $per_page;
        $svc   = $request->get_param( 'service' );
        $model = $request->get_param( 'model' );
        if ( $svc )   { $args['service'] = sanitize_text_field( $svc ); }
        if ( $model ) { $args['model']   = sanitize_text_field( $model ); }
        return self::proxy_get( '/account/usage-logs', $args );
    }

    // ── API Keys handlers ─────────────────────────────────────────

    public static function handle_get_keys( WP_REST_Request $request ) {
        $args = array();
        $days = (int) $request->get_param( 'days' );
        if ( $days > 0 ) { $args['days'] = $days; }
        return self::proxy_get( '/account/api-keys', $args );
    }

    // ── Helpers ───────────────────────────────────────────────────

    /**
     * @return BizCity_LLM_Client|null
     */
    private static function get_client() {
        if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
            return null;
        }
        return BizCity_LLM_Client::instance();
    }

    private static function proxy_get( string $path, array $query = array() ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_REST_Response(
                array( 'ok' => false, 'error' => 'llm_client_missing', '_degraded' => true ),
                200
            );
        }
        $result = $client->gateway_get( $path, $query );
        return new WP_REST_Response( $result, 200 );
    }

    private static function period_args( WP_REST_Request $request ) {
        $period = sanitize_text_field( (string) $request->get_param( 'period' ) );
        if ( ! in_array( $period, array( 'today', '7d', '30d', 'all' ), true ) ) {
            $period = '30d';
        }
        $args = array( 'period' => $period );
        $key_id = (int) $request->get_param( 'key_id' );
        if ( $key_id > 0 ) { $args['key_id'] = $key_id; }
        return $args;
    }
}
