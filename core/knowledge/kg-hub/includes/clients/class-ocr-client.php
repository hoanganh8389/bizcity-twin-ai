<?php
/**
 * BizCity_OCR_Client — thin client for /llm/router/v1/tools/ocr.
 *
 * Calls the BizCity LLM Router OCR endpoint (Vision LLM) with a Bearer token.
 * Used by:
 *   - BizCity_KG_Pdf_Adapter (E2.SCAN — Tier-2 fallback when text layer is empty).
 *   - Future: image-source adapter (E0).
 *
 * Sprint PHASE-0.7 Wave E0 (client side).
 *
 * @package BizCity_Twin_AI\KG_Hub\Clients
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_OCR_Client {

    const ENDPOINT_PATH    = '/wp-json/bizcity/v1/tools/ocr';        // canonical (R-GW-6)
    const ENDPOINT_LEGACY  = '/wp-json/llm/router/v1/tools/ocr';     // kept for reference
    const HEALTH_PATH      = '/wp-json/bizcity/v1/tools/ocr/health'; // canonical
    const DEFAULT_TIMEOUT  = 90;
    const MAX_PAGE_BYTES   = 8 * 1024 * 1024;

    /** @var BizCity_OCR_Client|null */
    private static $instance = null;

    public static function instance(): BizCity_OCR_Client {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Whether the OCR client has the gateway URL + API key configured.
     */
    public function is_configured(): bool {
        return ! empty( $this->gateway_url() ) && ! empty( $this->api_key() );
    }

    /**
     * Probe the health endpoint (no auth, no provider call).
     *
     * @return array {success, data?, error?, http_status?}
     */
    public function health(): array {
        $url = $this->gateway_url() . self::HEALTH_PATH;
        if ( empty( $this->gateway_url() ) ) {
            return [ 'success' => false, 'error' => 'gateway_url_not_configured' ];
        }
        $res = wp_remote_get( $url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $res ) ) {
            return [ 'success' => false, 'error' => $res->get_error_message() ];
        }
        $status = (int) wp_remote_retrieve_response_code( $res );
        $body   = json_decode( wp_remote_retrieve_body( $res ), true );
        return [
            'success'     => $status === 200 && ! empty( $body['success'] ),
            'http_status' => $status,
            'data'        => is_array( $body ) ? $body : [],
        ];
    }

    /**
     * OCR a single image (file path on disk).
     *
     * @param string $file_path
     * @param array  $opts {lang?, prompt_hint?, mime?, model?, max_tokens?, timeout?}
     * @return array|WP_Error  Array on success: {text, model, usage, cost_usd, latency_ms, fallback_used}.
     */
    public function ocr_image( string $file_path, array $opts = [] ) {
        if ( ! is_string( $file_path ) || $file_path === '' || ! file_exists( $file_path ) ) {
            return new WP_Error( 'ocr_file_missing', 'OCR source file not found.', [ 'status' => 400 ] );
        }
        if ( ! is_readable( $file_path ) ) {
            return new WP_Error( 'ocr_file_unreadable', 'OCR source file not readable.', [ 'status' => 400 ] );
        }
        $size = (int) @filesize( $file_path );
        if ( $size <= 0 ) {
            return new WP_Error( 'ocr_file_empty', 'OCR source file is empty.', [ 'status' => 400 ] );
        }
        if ( $size > self::MAX_PAGE_BYTES ) {
            return new WP_Error( 'ocr_file_too_large', sprintf( 'Image too large: %d bytes (max %d).', $size, self::MAX_PAGE_BYTES ), [ 'status' => 413 ] );
        }

        $bytes = @file_get_contents( $file_path );
        if ( $bytes === false ) {
            return new WP_Error( 'ocr_file_read_failed', 'Failed to read OCR source file.', [ 'status' => 500 ] );
        }

        $mime = isset( $opts['mime'] ) ? (string) $opts['mime'] : $this->guess_mime( $file_path );
        return $this->ocr_bytes( $bytes, $mime, $opts );
    }

    /**
     * OCR raw image bytes.
     *
     * @return array|WP_Error
     */
    public function ocr_bytes( string $bytes, string $mime, array $opts = [] ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'ocr_not_configured', 'OCR client not configured (gateway URL or API key missing).', [ 'status' => 503 ] );
        }

        $payload = [
            'image_b64'   => base64_encode( $bytes ),
            'mime'        => $mime ?: 'image/png',
            'lang'        => isset( $opts['lang'] ) ? (string) $opts['lang'] : 'auto',
            'prompt_hint' => isset( $opts['prompt_hint'] ) ? (string) $opts['prompt_hint'] : '',
            'max_tokens'  => isset( $opts['max_tokens'] ) ? intval( $opts['max_tokens'] ) : 4000,
        ];
        if ( ! empty( $opts['model'] ) ) {
            $payload['model'] = (string) $opts['model'];
        }

        $url     = $this->gateway_url() . self::ENDPOINT_PATH;
        $timeout = isset( $opts['timeout'] ) ? max( 30, intval( $opts['timeout'] ) ) : self::DEFAULT_TIMEOUT;

        $t_start = microtime( true );
        $res = wp_remote_post( $url, [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->api_key(),
                'Accept'        => 'application/json',
            ],
            'body' => wp_json_encode( $payload ),
        ] );
        $latency_ms = intval( ( microtime( true ) - $t_start ) * 1000 );

        if ( is_wp_error( $res ) ) {
            return new WP_Error( 'ocr_transport_error', $res->get_error_message(), [ 'status' => 502, 'latency_ms' => $latency_ms ] );
        }
        $status = (int) wp_remote_retrieve_response_code( $res );
        $body   = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'ocr_invalid_response', 'OCR endpoint returned non-JSON response.', [ 'status' => $status ?: 502 ] );
        }
        if ( $status >= 400 || empty( $body['success'] ) ) {
            $code = isset( $body['code'] ) ? (string) $body['code'] : 'ocr_provider_error';
            $msg  = isset( $body['error'] ) ? (string) $body['error'] : 'OCR provider error.';
            return new WP_Error( $code, $msg, [ 'status' => $status ?: 502, 'raw' => $body ] );
        }

        return [
            'text'          => isset( $body['text'] ) ? (string) $body['text'] : '',
            'model'         => isset( $body['model'] ) ? (string) $body['model'] : '',
            'usage'         => is_array( $body['usage'] ?? null ) ? $body['usage'] : [],
            'cost_usd'      => isset( $body['cost_usd'] ) ? (float) $body['cost_usd'] : 0.0,
            'latency_ms'    => isset( $body['latency_ms'] ) ? intval( $body['latency_ms'] ) : $latency_ms,
            'fallback_used' => ! empty( $body['fallback_used'] ),
            'meta'          => is_array( $body['meta'] ?? null ) ? $body['meta'] : [],
        ];
    }

    /**
     * OCR a list of pre-rendered page image paths (e.g. from PDF rasterization).
     *
     * @param array $page_paths List of file paths, ordered by page_num (1-based).
     * @param array $opts       Forwarded to ocr_image() per call.
     * @return array {success, segments[{page_num,text}], total_chars, errors[], total_cost_usd, total_latency_ms}
     */
    public function ocr_pdf_pages( array $page_paths, array $opts = [] ): array {
        $segments        = [];
        $errors          = [];
        $total_chars     = 0;
        $total_cost      = 0.0;
        $total_latency   = 0;
        $page_num        = 0;

        foreach ( $page_paths as $path ) {
            $page_num++;
            $r = $this->ocr_image( (string) $path, $opts );
            if ( is_wp_error( $r ) ) {
                $errors[] = [
                    'page_num' => $page_num,
                    'code'     => $r->get_error_code(),
                    'message'  => $r->get_error_message(),
                ];
                continue;
            }
            $text = isset( $r['text'] ) ? (string) $r['text'] : '';
            // Treat the marker token as empty for char-counting purposes
            if ( trim( $text ) === '[NO_TEXT_DETECTED]' ) {
                $text = '';
            }
            $segments[] = [
                'page_num' => $page_num,
                'text'     => $text,
            ];
            $total_chars   += mb_strlen( $text );
            $total_cost    += (float) ( $r['cost_usd'] ?? 0.0 );
            $total_latency += intval( $r['latency_ms'] ?? 0 );
        }

        return [
            'success'          => count( $errors ) === 0 || count( $segments ) > 0,
            'segments'         => $segments,
            'total_chars'      => $total_chars,
            'errors'           => $errors,
            'total_cost_usd'   => $total_cost,
            'total_latency_ms' => $total_latency,
            'page_count'       => $page_num,
        ];
    }

    /* ================================================================
     *  Internals
     * ================================================================ */

    private function gateway_url(): string {
        if ( class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'instance' ) ) {
            $url = BizCity_LLM_Client::instance()->get_gateway_url();
            if ( ! empty( $url ) ) {
                return rtrim( (string) $url, '/' );
            }
        }
        return rtrim( (string) get_site_option( 'bizcity_llm_gateway_url', '' ), '/' );
    }

    private function api_key(): string {
        if ( class_exists( 'BizCity_LLM_Client' ) && method_exists( 'BizCity_LLM_Client', 'instance' ) ) {
            $key = BizCity_LLM_Client::instance()->get_api_key();
            if ( ! empty( $key ) ) {
                return (string) $key;
            }
        }
        return trim( (string) get_site_option( 'bizcity_llm_api_key', '' ) );
    }

    private function guess_mime( string $file_path ): string {
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $map = [
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
        ];
        return $map[ $ext ] ?? 'image/png';
    }
}
