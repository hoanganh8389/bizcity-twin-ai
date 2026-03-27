<?php
/**
 * BizCity Market — Remote Catalog (Client-side)
 *
 * Lightweight helper — provides gateway config for JS-driven marketplace.
 * All catalog browsing is done in the browser via JS → WP AJAX → server REST API.
 * This class only handles server-side operations: proxy fetch & download.
 *
 * Design philosophy:
 *   PHP renders a minimal container → JS fetches JSON → renders cards.
 *   No heavy PHP DB queries or REST calls during page load = no timeouts.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Market
 * @since      1.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Remote_Catalog {

    /**
     * Get the market API base URL.
     *
     * @return string e.g. https://bizcity.vn/wp-json/market/v1
     */
    public static function api_base(): string {
        $gateway = rtrim( get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' ), '/' );
        return $gateway . '/wp-json/market/v1';
    }

    /**
     * Get API key for authentication.
     *
     * @return string
     */
    public static function api_key(): string {
        return trim( (string) get_site_option( 'bizcity_llm_api_key', '' ) );
    }

    /**
     * Check if remote catalog is available.
     *
     * @return bool
     */
    public static function is_available(): bool {
        return ! empty( self::api_key() );
    }

    /**
     * Proxy GET to market API — called from AJAX handler only.
     *
     * @param string $endpoint e.g. '/catalog', '/catalog/bizcity-tool-image'
     * @param array  $params   Query params.
     * @return array|WP_Error  Decoded JSON response.
     */
    public static function proxy_get( string $endpoint, array $params = [] ) {
        $url = self::api_base() . '/' . ltrim( $endpoint, '/' );
        if ( $params ) {
            $url .= '?' . http_build_query( $params );
        }
        return self::request( 'GET', $url );
    }

    /**
     * Proxy POST to market API — called from AJAX handler only.
     *
     * @param string $endpoint e.g. '/updates'
     * @param array  $body     JSON body.
     * @return array|WP_Error
     */
    public static function proxy_post( string $endpoint, array $body = [] ) {
        $url = self::api_base() . '/' . ltrim( $endpoint, '/' );
        return self::request( 'POST', $url, $body );
    }

    /**
     * Download a plugin ZIP via signed URL.
     *
     * @param string $download_url Signed download URL from catalog response.
     * @return array|WP_Error { tmp_file, checksum, version }
     */
    public static function download( string $download_url ) {
        if ( empty( $download_url ) ) {
            return new WP_Error( 'no_url', 'Download URL not provided.' );
        }

        // Download to temp file
        $tmp_file = wp_tempnam( 'bizcity-market.zip' );

        $response = wp_remote_get( $download_url, [
            'timeout'     => 120,
            'redirection' => 3,
            'stream'      => true,
            'filename'    => $tmp_file,
            'headers'     => [
                'X-Site-URL' => network_home_url(),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            @unlink( $tmp_file );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            @unlink( $tmp_file );
            return new WP_Error( 'download_failed', 'Download failed (HTTP ' . $code . ')' );
        }

        return [
            'tmp_file' => $tmp_file,
            'checksum' => wp_remote_retrieve_header( $response, 'x-checksum' ) ?: '',
            'version'  => wp_remote_retrieve_header( $response, 'x-plugin-version' ) ?: '',
        ];
    }

    /* ================================================================
     *  Internal HTTP
     * ================================================================ */

    /**
     * @param string $method  GET or POST.
     * @param string $url     Full URL.
     * @param array  $body    JSON body (POST only).
     * @return array|WP_Error
     */
    private static function request( string $method, string $url, array $body = [] ) {
        $headers = [
            'Accept'     => 'application/json',
            'X-Site-URL' => network_home_url(),
        ];
        $key = self::api_key();
        if ( $key ) {
            $headers['Authorization'] = 'Bearer ' . $key;
        }

        $args = [
            'timeout'     => 15,
            'redirection' => 3,
            'headers'     => $headers,
        ];

        if ( $method === 'POST' ) {
            $args['body'] = wp_json_encode( $body );
            $args['headers']['Content-Type'] = 'application/json';
            $response = wp_remote_post( $url, $args );
        } else {
            $response = wp_remote_get( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $raw = wp_remote_retrieve_body( $response );
        // Strip BOM
        if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw = substr( $raw, 3 );
        }

        $decoded = json_decode( trim( $raw ), true );
        $code    = wp_remote_retrieve_response_code( $response );

        if ( $code >= 400 ) {
            $msg = $decoded['error'] ?? 'Server error (HTTP ' . $code . ')';
            return new WP_Error( 'market_api_error', $msg, [ 'status' => $code ] );
        }

        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'invalid_response', 'Invalid JSON from server.' );
        }

        return $decoded;
    }
}
