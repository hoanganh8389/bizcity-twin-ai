<?php
/**
 * BizCity Tool Facebook — Standalone OAuth Flow
 *
 * Per-developer Facebook App OAuth — 100% independent from bizcity-facebook-bot.
 * Client installs their OWN Facebook Developer App credentials.
 *
 * Setup:
 *   1. Client creates a Facebook Developer App at developers.facebook.com
 *   2. Enters App ID + App Secret in WP Admin → Facebook → Settings
 *   3. Clicks "Kết nối Facebook" → OAuth flow starts
 *   4. Facebook redirects back → access tokens stored in bztfb_pages table
 *
 * URL flow:
 *   Admin clicks "Kết nối" → ?bztfb_oauth=start
 *   → Facebook dialog (user grants permissions)
 *   → redirect_uri: site/?bztfb_oauth=callback&code=xxx
 *   → exchange code → user token → /me/accounts → page tokens
 *   → save to bztfb_pages, redirect to admin page
 *
 * Permissions required in Facebook App:
 *   pages_show_list
 *   pages_manage_posts
 *   pages_manage_engagement
 *   pages_read_engagement
 *   pages_read_user_content
 *   pages_messaging
 *   instagram_basic (for IG)
 *   instagram_content_publish (for IG publishing)
 *   instagram_manage_comments (for IG comments)
 *
 * @package BizCity\TwinAI\ToolFacebook
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_FB_OAuth {

    private static ?self $instance = null;

    const GRAPH_VERSION = 'v21.0';
    const GRAPH_BASE    = 'https://graph.facebook.com/';

    const SCOPES = [
        'pages_show_list',
        'pages_manage_posts',
        'pages_manage_engagement',
        'pages_manage_metadata',
        'pages_read_engagement',
        'pages_read_user_content',
        'pages_messaging',
        'pages_messaging_subscriptions',
        'public_profile',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_comments',
        'instagram_manage_insights',
        'publish_to_groups',
    ];

    public static function instance(): self {
        if ( is_null( self::$instance ) ) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        add_action( 'init', [ $this, 'handle_request' ], 1 );
    }

    /**
     * Route ?bztfb_oauth= requests.
     */
    public function handle_request(): void {
        if ( ! isset( $_GET['bztfb_oauth'] ) ) return;

        $action = sanitize_text_field( $_GET['bztfb_oauth'] );

        switch ( $action ) {
            case 'start':    $this->start();    break;
            case 'callback': $this->callback(); break;
            case 'disconnect': $this->disconnect(); break;
        }
    }

    /* ──────────────────────────────────────────────────────────────
     *  STEP 1: Build OAuth URL → redirect to Facebook
     * ────────────────────────────────────────────────────────────── */

    private function start(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Không có quyền truy cập.', '', [ 'response' => 403 ] );
        }

        $app_id = get_option( 'bztfb_app_id', '' );
        if ( empty( $app_id ) ) {
            wp_die( 'Chưa cấu hình Facebook App ID. Vào Admin → Facebook → Settings để nhập App ID.', '', [ 'response' => 400 ] );
        }

        $state = $this->build_state( get_current_user_id() );
        set_transient( 'bztfb_oauth_' . md5( $state ), [
            'user_id' => get_current_user_id(),
            'time'    => time(),
        ], 10 * MINUTE_IN_SECONDS );

        $redirect_uri = add_query_arg( 'bztfb_oauth', 'callback', home_url( '/' ) );

        $auth_url = 'https://www.facebook.com/' . self::GRAPH_VERSION . '/dialog/oauth?' . http_build_query( [
            'client_id'     => $app_id,
            'redirect_uri'  => $redirect_uri,
            'scope'         => implode( ',', self::SCOPES ),
            'state'         => $state,
            'response_type' => 'code',
        ] );

        wp_redirect( $auth_url );
        exit;
    }

    /* ──────────────────────────────────────────────────────────────
     *  STEP 2: Handle Facebook redirect → exchange code → save pages
     * ────────────────────────────────────────────────────────────── */

    private function callback(): void {
        // -- Error from Facebook
        if ( isset( $_GET['error'] ) ) {
            $err = sanitize_text_field( $_GET['error_description'] ?? $_GET['error'] ?? 'Unknown error' );
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => urlencode( $err ) ] ) );
            exit;
        }

        $code  = sanitize_text_field( $_GET['code']  ?? '' );
        $state = sanitize_text_field( $_GET['state'] ?? '' );

        if ( empty( $code ) || empty( $state ) ) {
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => 'Missing+code+or+state' ] ) );
            exit;
        }

        // Verify state
        $transient_data = get_transient( 'bztfb_oauth_' . md5( $state ) );
        if ( ! $transient_data ) {
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => 'State+expired' ] ) );
            exit;
        }
        delete_transient( 'bztfb_oauth_' . md5( $state ) );

        $user_id = (int) ( $transient_data['user_id'] ?? 0 );

        // Exchange code → short-lived user token
        $app_id     = get_option( 'bztfb_app_id', '' );
        $app_secret = get_option( 'bztfb_app_secret', '' );
        $redirect_uri = add_query_arg( 'bztfb_oauth', 'callback', home_url( '/' ) );

        $token_resp = wp_remote_get(
            self::GRAPH_BASE . self::GRAPH_VERSION . '/oauth/access_token?' . http_build_query( [
                'client_id'     => $app_id,
                'client_secret' => $app_secret,
                'redirect_uri'  => $redirect_uri,
                'code'          => $code,
            ] ),
            [ 'timeout' => 20 ]
        );

        if ( is_wp_error( $token_resp ) ) {
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => urlencode( $token_resp->get_error_message() ) ] ) );
            exit;
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_resp ), true );
        $user_access_token = $token_data['access_token'] ?? '';

        if ( empty( $user_access_token ) ) {
            $err = $token_data['error']['message'] ?? 'Failed to get user access token';
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => urlencode( $err ) ] ) );
            exit;
        }

        // Get long-lived user token
        $long_resp = wp_remote_get(
            self::GRAPH_BASE . self::GRAPH_VERSION . '/oauth/access_token?' . http_build_query( [
                'grant_type'        => 'fb_exchange_token',
                'client_id'         => $app_id,
                'client_secret'     => $app_secret,
                'fb_exchange_token' => $user_access_token,
            ] ),
            [ 'timeout' => 20 ]
        );

        if ( ! is_wp_error( $long_resp ) ) {
            $long_data = json_decode( wp_remote_retrieve_body( $long_resp ), true );
            if ( ! empty( $long_data['access_token'] ) ) {
                $user_access_token = $long_data['access_token'];
            }
        }

        // Fetch page list (/me/accounts)
        $accounts_resp = wp_remote_get(
            self::GRAPH_BASE . self::GRAPH_VERSION . '/me/accounts?fields=id,name,category,access_token&access_token=' . urlencode( $user_access_token ),
            [ 'timeout' => 20 ]
        );

        if ( is_wp_error( $accounts_resp ) ) {
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => urlencode( $accounts_resp->get_error_message() ) ] ) );
            exit;
        }

        $accounts_data = json_decode( wp_remote_retrieve_body( $accounts_resp ), true );
        $pages = $accounts_data['data'] ?? [];

        if ( empty( $pages ) ) {
            wp_redirect( $this->admin_url( [ 'bztfb_status' => 'error', 'bztfb_msg' => 'Không+tìm+thấy+Facebook+Page' ] ) );
            exit;
        }

        $saved_count = 0;
        foreach ( $pages as $page ) {
            $page_id    = $page['id']           ?? '';
            $page_name  = $page['name']         ?? '';
            $page_token = $page['access_token'] ?? '';

            if ( empty( $page_id ) || empty( $page_token ) ) continue;

            // Try to get IG Business Account ID
            $ig_account_id = '';
            $ig_resp = wp_remote_get(
                self::GRAPH_BASE . self::GRAPH_VERSION . "/{$page_id}?fields=instagram_business_account&access_token=" . urlencode( $page_token ),
                [ 'timeout' => 10 ]
            );
            if ( ! is_wp_error( $ig_resp ) ) {
                $ig_data = json_decode( wp_remote_retrieve_body( $ig_resp ), true );
                $ig_account_id = $ig_data['instagram_business_account']['id'] ?? '';
            }

            BizCity_FB_Database::save_page( [
                'page_id'           => $page_id,
                'page_name'         => $page_name,
                'page_access_token' => $page_token,
                'ig_account_id'     => $ig_account_id,
                'category'          => $page['category'] ?? '',
                'user_id'           => $user_id,
            ] );

            // Subscribe page to webhook (auto-setup)
            $this->subscribe_page_webhook( $page_id, $page_token, $app_id );

            $saved_count++;
        }

        wp_redirect( $this->admin_url( [ 'bztfb_status' => 'connected', 'bztfb_pages' => $saved_count ] ) );
        exit;
    }

    /* ──────────────────────────────────────────────────────────────
     *  DISCONNECT: Remove a page connection
     * ────────────────────────────────────────────────────────────── */

    private function disconnect(): void {
        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Không có quyền truy cập.', '', [ 'response' => 403 ] );
        }

        $nonce   = $_GET['_wpnonce'] ?? '';
        $page_id = sanitize_text_field( $_GET['page_id'] ?? '' );

        if ( ! wp_verify_nonce( $nonce, 'bztfb_disconnect_' . $page_id ) ) {
            wp_die( 'Security check failed.', '', [ 'response' => 403 ] );
        }

        if ( $page_id ) {
            BizCity_FB_Database::delete_page( $page_id );
        }

        wp_redirect( $this->admin_url( [ 'bztfb_status' => 'disconnected' ] ) );
        exit;
    }

    /* ──────────────────────────────────────────────────────────────
     *  HELPERS
     * ────────────────────────────────────────────────────────────── */

    /**
     * Subscribe a page to webhook events automatically.
     * Requires the page token has pages_manage_metadata permission.
     */
    private function subscribe_page_webhook( string $page_id, string $page_token, string $app_id ): void {
        $subscriptions = 'messages,messaging_postbacks,feed,mention';

        $resp = wp_remote_post(
            self::GRAPH_BASE . self::GRAPH_VERSION . "/{$page_id}/subscribed_apps",
            [
                'body'    => [
                    'subscribed_fields' => $subscriptions,
                    'access_token'      => $page_token,
                ],
                'timeout' => 15,
            ]
        );

        if ( ! is_wp_error( $resp ) ) {
            $body = json_decode( wp_remote_retrieve_body( $resp ), true );
            if ( ! empty( $body['success'] ) ) {
                // Mark as subscribed in DB
                global $wpdb;
                $wpdb->update(
                    BizCity_FB_Database::pages_table(),
                    [ 'webhook_subscribed' => 1 ],
                    [ 'page_id' => $page_id ]
                );
            }
        }
    }

    /**
     * Build a HMAC-signed state string.
     */
    private function build_state( int $user_id ): string {
        $nonce = wp_generate_password( 16, false );
        $data  = $user_id . ':' . $nonce . ':' . wp_salt( 'auth' );
        return base64_encode( $user_id . '|' . $nonce . '|' . hash_hmac( 'sha256', $data, wp_salt( 'secure_auth' ) ) );
    }

    /**
     * Return the admin settings page URL with optional query args.
     */
    private function admin_url( array $args = [] ): string {
        return add_query_arg(
            $args,
            admin_url( 'admin.php?page=bizcity-facebook-settings' )
        );
    }
}
