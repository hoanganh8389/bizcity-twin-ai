<?php
/**
 * Google OAuth flow handler — runs on hub site.
 *
 * Endpoints:
 *   /google-auth/connect   — Start Google OAuth (redirect to Google)
 *   /google-auth/callback  — Google redirects back here
 *   /google-auth/disconnect — Revoke & disconnect account
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Google_OAuth {

    /** Google OAuth endpoints */
    const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const GOOGLE_USERINFO  = 'https://www.googleapis.com/oauth2/v2/userinfo';
    const GOOGLE_REVOKE    = 'https://oauth2.googleapis.com/revoke';

    /** Available scopes mapped by service name */
    const SCOPES = [
        'gmail_read'     => 'https://www.googleapis.com/auth/gmail.readonly',
        'gmail_send'     => 'https://www.googleapis.com/auth/gmail.send',
        'calendar_read'  => 'https://www.googleapis.com/auth/calendar.readonly',
        'calendar_write' => 'https://www.googleapis.com/auth/calendar',
        'drive_read'     => 'https://www.googleapis.com/auth/drive.readonly',
        'drive_write'    => 'https://www.googleapis.com/auth/drive.file',
        'contacts_read'  => 'https://www.googleapis.com/auth/contacts.readonly',
        'docs_write'     => 'https://www.googleapis.com/auth/documents',
        'sheets_write'   => 'https://www.googleapis.com/auth/spreadsheets',
        'slides_write'   => 'https://www.googleapis.com/auth/presentations',
        'profile'        => 'https://www.googleapis.com/auth/userinfo.email',
    ];

    /**
     * Default scopes — only basic profile on first connect.
     * Additional scopes are requested incrementally when user uses a tool.
     */
    const DEFAULT_SCOPES = [
        'profile',
    ];

    /**
     * Scope groups per service — used for incremental authorization.
     */
    const SCOPE_GROUPS = [
        'gmail'    => [ 'gmail_read', 'gmail_send' ],
        'calendar' => [ 'calendar_read', 'calendar_write' ],
        'drive'    => [ 'drive_read', 'drive_write' ],
        'contacts' => [ 'contacts_read' ],
        'docs'     => [ 'docs_write', 'drive_write' ],
        'sheets'   => [ 'sheets_write', 'drive_write' ],
        'slides'   => [ 'slides_write', 'drive_write' ],
    ];

    /**
     * Register rewrite rules for /google-auth/* endpoints.
     */
    public static function register_rewrite_rules() {
        add_rewrite_rule(
            '^google-auth/([a-z]+)/?$',
            'index.php?bzgoogle_action=$matches[1]',
            'top'
        );
    }

    /**
     * Check if current site is the OAuth hub (has bizgpt-oauth-server-new active).
     */
    public static function is_hub() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active( 'bizgpt-oauth-server-new/wp-oauth.php' );
    }

    /** Default hub blog_id = 1258 (bizcity.vn) */
    const DEFAULT_HUB_BLOG_ID = 1258;

    /**
     * Get the hub blog ID (from network config, default 1258 = bizcity.vn).
     */
    public static function get_hub_blog_id() {
        $id = (int) get_site_option( 'bzgoogle_hub_blog_id', self::DEFAULT_HUB_BLOG_ID );
        return $id > 0 ? $id : self::DEFAULT_HUB_BLOG_ID;
    }

    /**
     * Get the hub site URL (from network config, fallback bizcity.vn).
     * Always returns a full URL with https:// scheme.
     */
    public static function get_hub_url() {
        if ( self::is_hub() ) {
            return home_url();
        }
        $hub_blog_id = self::get_hub_blog_id();
        $url = get_home_url( $hub_blog_id );
        if ( ! empty( $url ) ) {
            $url = untrailingslashit( $url );
            // Ensure URL has scheme
            if ( strpos( $url, 'http' ) !== 0 ) {
                $url = 'https://' . $url;
            }
            return $url;
        }
        return 'https://bizcity.vn';
    }

    /**
     * Get the hub domain name (e.g. 'bizcity.vn').
     */
    public static function get_hub_domain() {
        return parse_url( self::get_hub_url(), PHP_URL_HOST ) ?: 'bizcity.vn';
    }

    /**
     * Get the callback URL (always on hub).
     */
    public static function get_callback_url() {
        return self::get_hub_url() . '/google-auth/callback';
    }

    /**
     * Handle /google-auth/* requests (template_redirect fallback).
     *
     * Uses query_var from rewrite rule when available.
     * Falls back to parsing REQUEST_URI directly when rewrite rules
     * haven't been flushed yet (e.g. plugin just activated via marketplace).
     */
    public static function handle_request() {
        $action = get_query_var( 'bzgoogle_action' );

        // Fallback: parse URI directly if rewrite rules not flushed
        if ( empty( $action ) && ! empty( $_SERVER['REQUEST_URI'] ) ) {
            $path = trim( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ), '/' );
            if ( preg_match( '/^google-auth\/([a-z]+)$/', $path, $m ) ) {
                $action = $m[1];
            }
        }

        if ( empty( $action ) ) return;

        self::dispatch_action( $action );
    }

    /**
     * Handle /google-auth/* directly from init hook — bypasses WP_Query entirely.
     * Called before the main WordPress query runs, so no 404/canonical redirect issues.
     *
     * @param string $action  connect|callback|disconnect
     */
    public static function handle_request_direct( string $action ) {
        self::dispatch_action( $action );
    }

    /**
     * Dispatch to the correct handler based on action.
     *
     * @param string $action  connect|callback|disconnect
     */
    private static function dispatch_action( string $action ) {
        switch ( $action ) {
            case 'connect':
                self::handle_connect();
                break;
            case 'callback':
                self::handle_callback();
                break;
            case 'disconnect':
                self::handle_disconnect();
                break;
        }
    }

    /**
     * Step 1: Start Google OAuth — redirect user to Google consent screen.
     *
     * Cross-domain: user is logged in on client site, redirected here (hub).
     * Uses HMAC signature instead of WordPress nonce for cross-domain verification.
     *
     * Expected params:
     *   blog_id    — originating site (required)
     *   user_id    — originating user (required)
     *   return_url — where to redirect after completion (required)
     *   scopes     — comma-separated scope keys (optional)
     *   ts         — timestamp
     *   sig        — HMAC-SHA256 signature of params
     */
    private static function handle_connect() {
        $blog_id    = absint( $_GET['blog_id'] ?? 0 );
        $user_id    = absint( $_GET['user_id'] ?? 0 );
        $return_url = esc_url_raw( $_GET['return_url'] ?? '' );
        $scope_keys = ! empty( $_GET['scopes'] )
            ? array_map( 'sanitize_text_field', explode( ',', sanitize_text_field( $_GET['scopes'] ) ) )
            : self::DEFAULT_SCOPES;
        $mode       = sanitize_text_field( $_GET['mode'] ?? 'shared_app' );
        $ts         = absint( $_GET['ts'] ?? 0 );
        $sig        = sanitize_text_field( $_GET['sig'] ?? '' );

        // Verify HMAC signature (cross-domain safe — same AUTH_SALT across multisite)
        $expected_sig = self::sign_connect_params( $blog_id, $user_id, $return_url, $ts );
        if ( ! $sig || ! hash_equals( $expected_sig, $sig ) ) {
            wp_die( 'Invalid signature.', 'Error', [ 'response' => 403 ] );
        }

        // Verify not expired (15 min)
        if ( abs( time() - $ts ) > 900 ) {
            wp_die( 'Link expired. Please try again.', 'Error', [ 'response' => 403 ] );
        }

        if ( ! $blog_id || ! $user_id || ! $return_url ) {
            wp_die( 'Missing required parameters.', 'Error', [ 'response' => 400 ] );
        }

        // Resolve scopes
        $scopes = [ 'openid' ];
        foreach ( $scope_keys as $key ) {
            if ( isset( self::SCOPES[ $key ] ) ) {
                $scopes[] = self::SCOPES[ $key ];
            }
        }
        $scopes = array_unique( $scopes );

        // Get credentials
        $creds = BZGoogle_Token_Store::get_google_credentials( $mode );
        if ( ! $creds ) {
            wp_die(
                '<h1>Lỗi cấu hình Google OAuth</h1>'
                . '<p>Google OAuth chưa được cấu hình trên hub site. Vui lòng liên hệ admin.</p>'
                . '<p><a href="' . esc_url( $return_url ?: home_url() ) . '">← Quay lại</a></p>',
                'Lỗi cấu hình — Google OAuth',
                [ 'response' => 200 ]
            );
        }

        // Build state (signed) — carries user identity through Google OAuth
        $state_data = [
            'blog_id'    => $blog_id,
            'user_id'    => $user_id,
            'return_url' => $return_url,
            'mode'       => $mode,
            'ts'         => time(),
        ];
        $state = self::encode_state( $state_data );

        // Build Google auth URL — use incremental authorization
        $params = [
            'client_id'              => $creds['client_id'],
            'redirect_uri'           => self::get_callback_url(),
            'response_type'          => 'code',
            'scope'                  => implode( ' ', $scopes ),
            'access_type'            => 'offline',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ];

        // First connect: prompt consent for refresh_token.
        // Scope upgrade: only select_account (no re-consent for existing scopes).
        $is_upgrade = ! empty( $_GET['upgrade'] );
        $params['prompt'] = $is_upgrade ? 'consent' : 'consent';
        // Google requires prompt=consent to get refresh_token on first connect.
        // On upgrade: include_granted_scopes merges old + new scopes automatically.

        $auth_url = self::GOOGLE_AUTH_URL . '?' . http_build_query( $params );
        wp_redirect( $auth_url );
        exit;
    }

    /**
     * Step 2: Google callback — exchange code for tokens and store.
     */
    private static function handle_callback() {
        $code  = sanitize_text_field( $_GET['code'] ?? '' );
        $state = sanitize_text_field( $_GET['state'] ?? '' );
        $error = sanitize_text_field( $_GET['error'] ?? '' );

        if ( $error ) {
            $return_url = admin_url( 'admin.php?page=bzgoogle-settings&error=google_denied' );
            wp_safe_redirect( $return_url );
            exit;
        }

        if ( empty( $code ) || empty( $state ) ) {
            wp_die( 'Missing authorization code.', 'Error', [ 'response' => 400 ] );
        }

        // Decode & verify state
        $state_data = self::decode_state( $state );
        if ( ! $state_data ) {
            wp_die( 'Invalid state parameter.', 'Error', [ 'response' => 400 ] );
        }

        // Verify state is not too old (15 min max)
        if ( ( time() - ( $state_data['ts'] ?? 0 ) ) > 900 ) {
            wp_die( 'State expired. Please try connecting again.', 'Error', [ 'response' => 400 ] );
        }

        $blog_id    = absint( $state_data['blog_id'] );
        $user_id    = absint( $state_data['user_id'] );
        $return_url = esc_url_raw( $state_data['return_url'] );
        $mode       = $state_data['mode'] ?? 'shared_app';

        // Get credentials
        $creds = BZGoogle_Token_Store::get_google_credentials( $mode );
        if ( ! $creds ) {
            wp_die( 'Google OAuth credentials not found.', 'Error', [ 'response' => 500 ] );
        }

        // Exchange code for tokens
        $token_response = wp_remote_post( self::GOOGLE_TOKEN_URL, [
            'timeout' => 15,
            'body'    => [
                'code'          => $code,
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'redirect_uri'  => self::get_callback_url(),
                'grant_type'    => 'authorization_code',
            ],
        ] );

        if ( is_wp_error( $token_response ) ) {
            wp_die( 'Failed to exchange authorization code: ' . esc_html( $token_response->get_error_message() ), 'Error', [ 'response' => 500 ] );
        }

        $token_body = json_decode( wp_remote_retrieve_body( $token_response ), true );
        if ( empty( $token_body['access_token'] ) ) {
            $err = $token_body['error_description'] ?? $token_body['error'] ?? 'Unknown error';
            wp_die( 'Google token exchange failed: ' . esc_html( $err ), 'Error', [ 'response' => 500 ] );
        }

        // Get user info from Google
        $userinfo_response = wp_remote_get( self::GOOGLE_USERINFO, [
            'timeout' => 10,
            'headers' => [ 'Authorization' => 'Bearer ' . $token_body['access_token'] ],
        ] );

        $google_email = '';
        $google_sub   = '';
        if ( ! is_wp_error( $userinfo_response ) ) {
            $userinfo = json_decode( wp_remote_retrieve_body( $userinfo_response ), true );
            $google_email = sanitize_email( $userinfo['email'] ?? '' );
            $google_sub   = sanitize_text_field( $userinfo['id'] ?? '' );
        }

        // Build scope string from granted scopes
        $granted_scope = $token_body['scope'] ?? '';

        // Store tokens
        $save_result = BZGoogle_Token_Store::save( [
            'blog_id'         => $blog_id,
            'user_id'         => $user_id,
            'google_email'    => $google_email,
            'google_sub'      => $google_sub,
            'access_token'    => $token_body['access_token'],
            'refresh_token'   => $token_body['refresh_token'] ?? '',
            'scope'           => $granted_scope,
            'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + ( $token_body['expires_in'] ?? 3600 ) ),
            'connection_mode' => $mode,
        ] );

        // Debug: log save result (remove after fixing)
        error_log( sprintf(
            '[BZGoogle callback] save_result=%s, blog_id=%d, user_id=%d, email=%s, table=%s, last_error=%s',
            var_export( $save_result, true ), $blog_id, $user_id, $google_email,
            BZGoogle_Installer::table_accounts(),
            $GLOBALS['wpdb']->last_error ?? 'none'
        ) );

        // Log
        BZGoogle_REST_API::log_usage( $blog_id, $user_id, 'oauth', 'connect', $google_email, 'success' );

        // Redirect back — allow cross-domain redirect within multisite network
        $sep = ( strpos( $return_url, '?' ) !== false ) ? '&' : '?';
        $redirect = $return_url . $sep . 'bzgoogle_connected=1&email=' . urlencode( $google_email );
        $return_host = parse_url( $return_url, PHP_URL_HOST );
        if ( $return_host ) {
            add_filter( 'allowed_redirect_hosts', function( $hosts ) use ( $return_host ) {
                $hosts[] = $return_host;
                return $hosts;
            } );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Disconnect a Google account.
     */
    private static function handle_disconnect() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $account_id = absint( $_GET['account_id'] ?? 0 );
        if ( ! $account_id ) {
            wp_die( 'Missing account ID.', 'Error', [ 'response' => 400 ] );
        }

        if ( ! wp_verify_nonce( sanitize_text_field( $_GET['_wpnonce'] ?? '' ), 'bzgoogle_disconnect_' . $account_id ) ) {
            wp_die( 'Invalid nonce.', 'Error', [ 'response' => 403 ] );
        }

        BZGoogle_Token_Store::disconnect( $account_id, get_current_user_id() );

        $return_url = esc_url_raw( $_GET['return_url'] ?? admin_url( 'admin.php?page=bzgoogle-settings' ) );
        $sep = ( strpos( $return_url, '?' ) !== false ) ? '&' : '?';
        wp_safe_redirect( $return_url . $sep . 'bzgoogle_disconnected=1' );
        exit;
    }

    /* ── State encode/decode with HMAC signing ─────────────── */

    private static function state_secret() {
        return defined( 'AUTH_SALT' ) ? AUTH_SALT : 'bzgoogle-state-salt';
    }

    private static function encode_state( $data ) {
        $json    = wp_json_encode( $data );
        $payload = base64_encode( $json );
        $sig     = hash_hmac( 'sha256', $payload, self::state_secret() );
        return $payload . '.' . $sig;
    }

    private static function decode_state( $state ) {
        $parts = explode( '.', $state, 2 );
        if ( count( $parts ) !== 2 ) return false;

        $payload = $parts[0];
        $sig     = $parts[1];

        $expected = hash_hmac( 'sha256', $payload, self::state_secret() );
        if ( ! hash_equals( $expected, $sig ) ) return false;

        $json = base64_decode( $payload, true );
        if ( ! $json ) return false;

        return json_decode( $json, true );
    }

    /**
     * Sign connect params with HMAC (cross-domain safe).
     */
    private static function sign_connect_params( $blog_id, $user_id, $return_url, $ts ) {
        $data = "{$blog_id}|{$user_id}|{$return_url}|{$ts}";
        return hash_hmac( 'sha256', $data, self::state_secret() );
    }

    /**
     * Build the connect URL for a user on any site.
     * Uses HMAC signature instead of WordPress nonce (cross-domain compatible).
     */
    public static function get_connect_url( $args = [] ) {
        $blog_id    = $args['blog_id'] ?? get_current_blog_id();
        $user_id    = $args['user_id'] ?? get_current_user_id();
        $return_url = $args['return_url'] ?? admin_url( 'admin.php?page=bzgoogle-settings' );
        $scopes     = $args['scopes'] ?? implode( ',', self::DEFAULT_SCOPES );
        $mode       = $args['mode'] ?? 'shared_app';
        $upgrade    = ! empty( $args['upgrade'] ) ? '1' : '';
        $ts         = time();

        $hub_url = self::get_hub_url();
        $sig     = self::sign_connect_params( $blog_id, $user_id, $return_url, $ts );

        $query = [
            'blog_id'    => $blog_id,
            'user_id'    => $user_id,
            'return_url' => $return_url,
            'scopes'     => $scopes,
            'mode'       => $mode,
            'ts'         => $ts,
            'sig'        => $sig,
        ];
        if ( $upgrade ) {
            $query['upgrade'] = '1';
        }

        return add_query_arg( $query, $hub_url . '/google-auth/connect' );
    }

    /**
     * Build a scope upgrade URL — for incremental authorization.
     *
     * @param string $service  Service name: gmail, calendar, drive, contacts
     * @param string $return_url  Where to redirect after granting
     * @return string  URL to redirect user for additional scope consent
     */
    public static function get_scope_upgrade_url( $service, $return_url = '' ) {
        if ( ! isset( self::SCOPE_GROUPS[ $service ] ) ) return '';

        $scope_keys = self::SCOPE_GROUPS[ $service ];

        return self::get_connect_url( [
            'blog_id'    => get_current_blog_id(),
            'return_url' => $return_url ?: home_url(),
            'scopes'     => implode( ',', $scope_keys ),
            'upgrade'    => true,
        ] );
    }

    /**
     * Check if a user has a specific service scope granted.
     *
     * @param int    $blog_id
     * @param int    $user_id
     * @param string $service  gmail, calendar, drive, contacts
     * @return bool
     */
    public static function has_scope( $blog_id, $user_id, $service ) {
        if ( ! isset( self::SCOPE_GROUPS[ $service ] ) ) return false;

        $token_data = BZGoogle_Token_Store::get_token( $blog_id, $user_id );
        if ( ! $token_data ) return false;

        $granted = $token_data['scope'] ?? '';

        // Check if at least the first scope of the group is granted
        $required_scope = self::SCOPES[ self::SCOPE_GROUPS[ $service ][0] ] ?? '';
        return ! empty( $required_scope ) && strpos( $granted, $required_scope ) !== false;
    }
}
