<?php
/**
 * BizCity_AV_Transcribe_Client — thin gateway client for /bizcity/v1/tools/transcribe.
 *
 * R-GW compliance: the client side never holds OpenRouter / provider keys.
 * It calls the BizCity LLM Router endpoint with the user's `biz-xxx` Bearer token,
 * passing a publicly fetchable `media_url` (typically a WP Media Library URL).
 *
 * Used by:
 *   - BizCity_KG_AV_Adapter (Wave E0.AV — audio/video learning).
 *
 * @package BizCity_Twin_AI\KG_Hub\Clients
 * @since   PHASE-0.7 Wave E0.AV
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_AV_Transcribe_Client {

    const ENDPOINT_PATH   = '/wp-json/bizcity/v1/tools/transcribe';
    const HEALTH_PATH     = '/wp-json/bizcity/v1/tools/transcribe/health';
    const DEFAULT_TIMEOUT = 180;

    /** @var BizCity_AV_Transcribe_Client|null */
    private static $instance = null;

    public static function instance(): BizCity_AV_Transcribe_Client {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_configured(): bool {
        return $this->gateway_url() !== '' && $this->api_key() !== '';
    }

    /**
     * Probe the public health endpoint (no auth, no provider call).
     *
     * @return array {success, data?, error?, http_status?}
     */
    public function health(): array {
        $url = $this->gateway_url() . self::HEALTH_PATH;
        if ( $this->gateway_url() === '' ) {
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
     * Transcribe an audio/video file by URL.
     *
     * @param string $media_url Public URL of the media (WP Media Library URL preferred).
     * @param string $kind      'audio' | 'video'
     * @param array  $opts {
     *     mime?         : string  (e.g. 'audio/mpeg', 'video/mp4')
     *     lang?         : string  ('vi' | 'en' | 'auto', default 'auto')
     *     prompt_hint?  : string
     *     model?        : string  (override; otherwise gateway picks via purpose=transcribe)
     *     max_tokens?   : int
     *     timeout?      : int
     *     duration_sec? : int
     *     plugin_name?  : string  (analytics tag)
     * }
     * @return array|WP_Error  Success: {text, model, usage, cost_usd, latency_ms, meta, fallback_used}
     */
    public function transcribe( string $media_url, string $kind, array $opts = [] ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error(
                'av_not_configured',
                'BizCity LLM gateway URL or API key not configured.',
                [ 'status' => 503 ]
            );
        }
        $media_url = esc_url_raw( $media_url );
        if ( $media_url === '' ) {
            return new WP_Error( 'av_missing_media_url', 'media_url is required.', [ 'status' => 400 ] );
        }
        $kind = strtolower( $kind );
        if ( ! in_array( $kind, [ 'audio', 'video' ], true ) ) {
            return new WP_Error( 'av_invalid_kind', 'kind must be "audio" or "video".', [ 'status' => 400 ] );
        }

        $payload = [
            'media_url'   => $media_url,
            'kind'        => $kind,
            'mime'        => isset( $opts['mime'] )         ? (string) $opts['mime']        : '',
            'lang'        => isset( $opts['lang'] )         ? (string) $opts['lang']        : 'auto',
            'prompt_hint' => isset( $opts['prompt_hint'] )  ? (string) $opts['prompt_hint'] : '',
            'max_tokens'  => isset( $opts['max_tokens'] )   ? intval( $opts['max_tokens'] ) : 8000,
            'duration_sec'=> isset( $opts['duration_sec'] ) ? intval( $opts['duration_sec'] ) : 0,
            'plugin_name' => isset( $opts['plugin_name'] )  ? (string) $opts['plugin_name'] : 'kg-hub/av-adapter',
            'site_url'    => home_url( '/' ),
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
            return new WP_Error(
                'av_transport_error',
                $res->get_error_message(),
                [ 'status' => 502, 'latency_ms' => $latency_ms ]
            );
        }
        $status = (int) wp_remote_retrieve_response_code( $res );
        $body   = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'av_invalid_response', 'Transcribe endpoint returned non-JSON response.', [ 'status' => $status ?: 502 ] );
        }
        if ( $status >= 400 || empty( $body['success'] ) ) {
            $code = isset( $body['code'] ) ? (string) $body['code'] : 'av_provider_error';
            $msg  = isset( $body['error'] ) ? (string) $body['error'] : 'Transcribe provider error.';
            return new WP_Error( $code, $msg, [
                'status'         => $status ?: 502,
                'tier'           => $body['tier']           ?? null,
                'used_today'     => $body['used_today']     ?? null,
                'free_day'       => $body['free_day']       ?? null,
                'remaining_free' => $body['remaining_free'] ?? null,
                'feature'        => $body['feature']        ?? null,
                'raw'            => $body,
            ] );
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

    /* ─── internals ─── */

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
}
