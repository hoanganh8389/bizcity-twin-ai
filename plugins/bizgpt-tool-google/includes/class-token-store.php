<?php
/**
 * Token storage — CRUD, encryption, refresh for Google tokens.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BZGoogle_Token_Store {

    /* ── Encryption helpers ────────────────────────────────── */

    private static function encryption_key() {
        return defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bzgoogle-fallback-key';
    }

    /**
     * Encrypt a value using AES-256-CBC.
     */
    public static function encrypt( $plain ) {
        if ( empty( $plain ) ) return '';
        $key    = hash( 'sha256', self::encryption_key(), true );
        $iv     = openssl_random_pseudo_bytes( 16 );
        $cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return base64_encode( $iv . $cipher );
    }

    /**
     * Decrypt a value.
     */
    public static function decrypt( $encrypted ) {
        if ( empty( $encrypted ) ) return '';
        $data = base64_decode( $encrypted, true );
        if ( $data === false || strlen( $data ) < 17 ) return '';
        $key   = hash( 'sha256', self::encryption_key(), true );
        $iv    = substr( $data, 0, 16 );
        $cipher = substr( $data, 16 );
        $plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        return $plain !== false ? $plain : '';
    }

    /* ── CRUD ──────────────────────────────────────────────── */

    /**
     * Save (insert or update) a Google account token.
     */
    public static function save( $args ) {
        global $wpdb;
        $table = BZGoogle_Installer::table_accounts();

        $blog_id      = absint( $args['blog_id'] ?? get_current_blog_id() );
        $user_id      = absint( $args['user_id'] ?? get_current_user_id() );
        $google_email = sanitize_email( $args['google_email'] ?? '' );
        $google_sub   = sanitize_text_field( $args['google_sub'] ?? '' );
        $scope        = sanitize_text_field( $args['scope'] ?? '' );
        $mode         = sanitize_text_field( $args['connection_mode'] ?? 'shared_app' );

        $access_enc   = self::encrypt( $args['access_token'] ?? '' );
        $refresh_enc  = self::encrypt( $args['refresh_token'] ?? '' );
        $expires_at   = $args['expires_at'] ?? gmdate( 'Y-m-d H:i:s', time() + 3600 );

        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE blog_id = %d AND user_id = %d AND google_email = %s",
            $blog_id, $user_id, $google_email
        ) );

        if ( $existing ) {
            $data = [
                'access_token'    => $access_enc,
                'scope'           => $scope,
                'expires_at'      => $expires_at,
                'google_sub'      => $google_sub,
                'connection_mode' => $mode,
                'status'          => 'active',
            ];
            if ( ! empty( $args['refresh_token'] ) ) {
                $data['refresh_token'] = $refresh_enc;
            }
            $wpdb->update( $table, $data, [ 'id' => $existing ] );
            return (int) $existing;
        }

        $wpdb->insert( $table, [
            'blog_id'         => $blog_id,
            'user_id'         => $user_id,
            'google_email'    => $google_email,
            'google_sub'      => $google_sub,
            'access_token'    => $access_enc,
            'refresh_token'   => $refresh_enc,
            'scope'           => $scope,
            'expires_at'      => $expires_at,
            'connection_mode' => $mode,
            'status'          => 'active',
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * Get a valid access token for a blog/user. Auto-refreshes if expired.
     *
     * @return array|false  { access_token, google_email, scope, expires_at } or false
     */
    public static function get_token( $blog_id, $user_id, $google_email = '' ) {
        global $wpdb;
        $table = BZGoogle_Installer::table_accounts();

        $where = $wpdb->prepare(
            "blog_id = %d AND user_id = %d AND status = 'active'",
            $blog_id, $user_id
        );
        if ( $google_email ) {
            $where .= $wpdb->prepare( " AND google_email = %s", $google_email );
        }

        $row = $wpdb->get_row( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT 1" );
        if ( ! $row ) return false;

        // Check expiry — refresh if < 5 min remaining
        $expires_ts = strtotime( $row->expires_at );
        if ( $expires_ts < time() + 300 ) {
            $refreshed = self::refresh( $row );
            if ( ! $refreshed ) {
                $wpdb->update( $table, [ 'status' => 'expired' ], [ 'id' => $row->id ] );
                return false;
            }
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $row->id ) );
        }

        return [
            'access_token'  => self::decrypt( $row->access_token ),
            'google_email'  => $row->google_email,
            'scope'         => $row->scope,
            'expires_at'    => $row->expires_at,
            'connection_mode' => $row->connection_mode,
        ];
    }

    /**
     * Check if a valid token exists.
     */
    public static function has_valid_token( $blog_id, $user_id ) {
        global $wpdb;
        $table = BZGoogle_Installer::table_accounts();
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE blog_id = %d AND user_id = %d AND status = 'active' LIMIT 1",
            $blog_id, $user_id
        ) );
    }

    /**
     * Get all accounts for a user across a specific blog.
     */
    public static function get_accounts( $blog_id, $user_id ) {
        global $wpdb;
        $table = BZGoogle_Installer::table_accounts();
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, google_email, google_sub, scope, expires_at, connection_mode, status, created_at, updated_at
             FROM {$table}
             WHERE blog_id = %d AND user_id = %d
             ORDER BY updated_at DESC",
            $blog_id, $user_id
        ) );
    }

    /**
     * Disconnect (soft-delete) an account.
     */
    public static function disconnect( $account_id, $user_id ) {
        global $wpdb;
        $table = BZGoogle_Installer::table_accounts();
        return $wpdb->update(
            $table,
            [ 'status' => 'disconnected', 'access_token' => '', 'refresh_token' => '' ],
            [ 'id' => $account_id, 'user_id' => $user_id ]
        );
    }

    /**
     * Refresh an access token using the refresh_token.
     */
    public static function refresh( $row ) {
        $refresh_token = self::decrypt( $row->refresh_token );
        if ( empty( $refresh_token ) ) return false;

        // Get Google OAuth credentials from hub
        $creds = self::get_google_credentials( $row->connection_mode );
        if ( ! $creds ) return false;

        $response = wp_remote_post( 'https://oauth2.googleapis.com/token', [
            'timeout' => 15,
            'body'    => [
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'refresh_token' => $refresh_token,
                'grant_type'    => 'refresh_token',
            ],
        ] );

        if ( is_wp_error( $response ) ) return false;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['access_token'] ) ) return false;

        global $wpdb;
        $table = BZGoogle_Installer::table_accounts();
        $wpdb->update( $table, [
            'access_token' => self::encrypt( $body['access_token'] ),
            'expires_at'   => gmdate( 'Y-m-d H:i:s', time() + ( $body['expires_in'] ?? 3600 ) ),
            'status'       => 'active',
        ], [ 'id' => $row->id ] );

        return true;
    }

    /**
     * Get Google OAuth client credentials.
     * For shared_app: from hub site option.
     * For byo_app: from per-site option.
     */
    public static function get_google_credentials( $mode = 'shared_app' ) {
        if ( $mode === 'byo_app' ) {
            $client_id     = get_option( 'bzgoogle_byo_client_id', '' );
            $client_secret = get_option( 'bzgoogle_byo_client_secret', '' );
        } else {
            $client_id     = get_site_option( 'bzgoogle_client_id', '' );
            $client_secret = get_site_option( 'bzgoogle_client_secret', '' );
        }

        if ( empty( $client_id ) || empty( $client_secret ) ) return false;

        return [
            'client_id'     => $client_id,
            'client_secret' => self::decrypt( $client_secret ),
        ];
    }

    /**
     * Get all accounts expiring soon (for cron refresh).
     */
    public static function get_expiring_accounts( $within_seconds = 600 ) {
        global $wpdb;
        $table      = BZGoogle_Installer::table_accounts();
        $threshold  = gmdate( 'Y-m-d H:i:s', time() + $within_seconds );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'active' AND expires_at < %s AND refresh_token != ''",
            $threshold
        ) );
    }
}
