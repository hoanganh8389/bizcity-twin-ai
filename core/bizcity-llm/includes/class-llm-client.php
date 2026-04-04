<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity LLM — Core Client Class
 *
 * Two modes:
 *   • Gateway  — calls bizcity.vn/bizcity.ai REST API (LLM Router)
 *   • Direct   — calls OpenRouter directly with user's own key
 *
 * Provides identical interface regardless of mode, including:
 *   chat(), chat_stream(), embeddings(), get_model(), get_fallback_model()
 *
 * @package BizCity_LLM
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_LLM_Client {

    /* ─── Singleton ─── */
    private static ?self $instance = null;

    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /* ── Debug logging ── */
    private function debug_log( string $msg, array $ctx = [] ): void {
        if ( ! defined( 'BIZCITY_LLM_DEBUG' ) || ! BIZCITY_LLM_DEBUG ) {
            return;
        }
        $extra = $ctx ? ' | ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
        error_log( "[BizCity-LLM] {$msg}{$extra}" );
    }

    /* ================================================================
     *  Configuration
     * ================================================================ */

    /**
     * Connection mode: 'gateway' or 'direct'.
     */
    public function get_mode(): string {
        return get_site_option( 'bizcity_llm_mode', 'gateway' );
    }

    /**
     * Gateway base URL (only used in gateway mode).
     */
    public function get_gateway_url(): string {
        return rtrim( get_site_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' ), '/' );
    }

    /**
     * API key — gateway key in gateway mode, OpenRouter key in direct mode.
     */
    public function get_api_key(): string {
        return trim( (string) get_site_option( 'bizcity_llm_api_key', '' ) );
    }

    /**
     * Whether the client is configured and ready.
     */
    public function is_ready(): bool {
        return ! empty( $this->get_api_key() );
    }

    /**
     * Get a setting value.
     */
    public function get_setting( string $key, $default = null ) {
        $settings = get_site_option( 'bizcity_llm_settings', [] );
        return $settings[ $key ] ?? $default;
    }

    /**
     * Get the primary model for a purpose.
     */
    public function get_model( string $purpose = 'chat' ): string {
        $stored = $this->get_setting( 'model_' . $purpose, '' );
        if ( ! empty( $stored ) ) {
            return $stored;
        }
        return BizCity_LLM_Models::DEFAULTS[ $purpose ]
            ?? BizCity_LLM_Models::DEFAULTS['chat'];
    }

    /**
     * Get the fallback model for a purpose.
     */
    public function get_fallback_model( string $purpose = 'chat' ): string {
        if ( ! empty( $this->get_setting( 'no_fallback_' . $purpose, 0 ) ) ) {
            return '';
        }
        $stored = $this->get_setting( 'model_fallback_' . $purpose, '' );
        if ( ! empty( $stored ) ) {
            return $stored;
        }
        return BizCity_LLM_Models::FALLBACK_DEFAULTS[ $purpose ] ?? '';
    }

    /**
     * Global timeout in seconds.
     */
    public function get_timeout(): int {
        return (int) $this->get_setting( 'timeout', 60 );
    }

    /* ================================================================
     *  Chat — delegates to gateway or direct based on mode
     * ================================================================ */

    /**
     * Send a chat-completion request.
     *
     * @param array $messages  OpenAI-format messages.
     * @param array $options   { model, purpose, temperature, max_tokens, extra_body, no_fallback, ... }
     * @return array { success, message, model, model_primary, fallback_used, provider, usage, error }
     */
    public function chat( array $messages, array $options = [] ): array {
        $mode    = $this->get_mode();
        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? $this->get_model( $purpose );
        $start   = microtime( true );

        $this->debug_log( 'chat() START', [
            'mode' => $mode, 'purpose' => $purpose, 'model' => $model,
            'api_key_set' => ! empty( $this->get_api_key() ),
            'msg_count' => count( $messages ),
        ] );

        if ( $mode === 'direct' ) {
            $result = $this->chat_direct( $messages, $options );
        } else {
            $result = $this->chat_gateway( $messages, $options );
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'chat() END', [
            'success' => $result['success'], 'model' => $result['model'] ?? '',
            'provider' => $result['provider'] ?? '', 'ms' => $ms,
            'fallback' => $result['fallback_used'] ?? false,
            'error' => $result['error'] ?? '',
            'reply_len' => mb_strlen( $result['message'] ?? '' ),
        ] );

        $this->log_usage( $result, $options, 'chat', $model, $ms );

        return $result;
    }

    /**
     * Send a streaming chat-completion request.
     *
     * @param array         $messages
     * @param array         $options
     * @param callable|null $on_chunk  function(string $delta, string $full): void
     * @return array Same shape as chat().
     */
    public function chat_stream( array $messages, array $options = [], $on_chunk = null ): array {
        $mode    = $this->get_mode();
        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? $this->get_model( $purpose );
        $start   = microtime( true );

        $this->debug_log( 'chat_stream() START', [
            'mode' => $mode, 'purpose' => $purpose, 'model' => $model,
            'api_key_set' => ! empty( $this->get_api_key() ),
        ] );

        if ( $mode === 'direct' ) {
            $result = $this->chat_stream_direct( $messages, $options, $on_chunk );
        } else {
            $result = $this->chat_stream_gateway( $messages, $options, $on_chunk );
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'chat_stream() END', [
            'success' => $result['success'], 'ms' => $ms,
            'error' => $result['error'] ?? '',
        ] );

        $this->log_usage( $result, $options, 'stream', $model, $ms );

        return $result;
    }

    /* ================================================================
     *  Chat with character (backward compat with bizcity-knowledge)
     * ================================================================ */

    public function chat_with_character( object $character, array $messages ): array {
        $result = $this->chat( $messages, [
            'model'       => $character->model_id       ?? '',
            'temperature' => floatval( $character->creativity_level ?? 0.7 ),
            'max_tokens'  => intval( $character->max_tokens ?? 3000 ),
        ] );

        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'llm_call',
                'message'          => 'chat_with_character()',
                'mode'             => $this->get_mode(),
                'functions_called' => 'BizCity_LLM_Client::chat_with_character()',
                'model_requested'  => $character->model_id ?? '',
                'model_actual'     => $result['model'] ?? '',
                'fallback_used'    => $result['fallback_used'] ?? false,
                'success'          => $result['success'] ?? false,
                'error'            => $result['error'] ?? '',
                'usage'            => $result['usage'] ?? [],
                'reply_length'     => mb_strlen( $result['message'] ?? '', 'UTF-8' ),
            ] );
        }

        return $result;
    }

    public function chat_stream_with_character( object $character, array $messages, $on_chunk = null ): array {
        return $this->chat_stream( $messages, [
            'model'       => $character->model_id       ?? '',
            'temperature' => floatval( $character->creativity_level ?? 0.7 ),
            'max_tokens'  => intval( $character->max_tokens ?? 3000 ),
        ], $on_chunk );
    }

    /* ================================================================
     *  GATEWAY MODE — call bizcity.vn REST API
     * ================================================================ */

    private function chat_gateway( array $messages, array $options ): array {
        $base = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            $this->debug_log( 'chat_gateway() ABORT — no API key' );
            return $base;
        }

        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? '';
        if ( empty( $model ) ) {
            $model = $this->get_model( $purpose );
        }
        $base['model']         = $model;
        $base['model_primary'] = $model;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => floatval( $options['temperature'] ?? 0.7 ),
            'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
            'purpose'     => $purpose,
            'site_url'    => home_url(),
        ];
        if ( ! empty( $options['extra_body'] ) ) {
            $body = array_merge( $body, $options['extra_body'] );
        }

        $timeout  = isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout();
        // [2026-03-25] Unified API namespace: migrate llm/router/v1/chat → bizcity/v1/llm/chat
        // $endpoint = $this->get_gateway_url() . '/wp-json/llm/router/v1/chat';
        $endpoint = $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/chat';

        $this->debug_log( 'chat_gateway() POST', [
            'endpoint' => $endpoint, 'model' => $model, 'purpose' => $purpose,
            'timeout' => $timeout, 'key_prefix' => substr( $api_key, 0, 8 ) . '…',
        ] );

        $response = wp_remote_post( $endpoint, [
            'timeout'     => $timeout,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $raw_body = wp_remote_retrieve_body( $response );
        // Strip BOM (UTF-8 byte-order mark) that bizcity.vn may prepend
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $raw_body = trim( $raw_body );
        $decoded  = json_decode( $raw_body, true );

        if ( $code === 402 ) {
            $base['error'] = $decoded['message'] ?? 'Hết credit. Vui lòng nạp thêm tại bizcity.vn';
            return $base;
        }

        if ( $code === 429 ) {
            $base['error'] = $decoded['message'] ?? 'Rate limit exceeded.';
            return $base;
        }

        if ( isset( $decoded['success'] ) && $decoded['success'] ) {
            return array_merge( $base, [
                'success'       => true,
                'message'       => $decoded['message'] ?? '',
                'model'         => $decoded['model'] ?? $model,
                'model_primary' => $decoded['model_primary'] ?? $model,
                'fallback_used' => $decoded['fallback_used'] ?? false,
                'usage'         => $decoded['usage'] ?? [],
            ] );
        }

        $base['error'] = $decoded['error'] ?? $decoded['message'] ?? "HTTP {$code}";
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[bizcity-llm] chat_gateway error: HTTP {$code} model={$model} error=" . $base['error'] );
        }
        return $base;
    }

    private function chat_stream_gateway( array $messages, array $options, $on_chunk = null ): array {
        $stream_t0 = microtime( true );
        $base = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            return $base;
        }

        if ( ! function_exists( 'curl_init' ) ) {
            return $this->chat_gateway( $messages, $options );
        }

        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? '';
        if ( empty( $model ) ) {
            $model = $this->get_model( $purpose );
        }
        $base['model']         = $model;
        $base['model_primary'] = $model;

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => floatval( $options['temperature'] ?? 0.7 ),
            'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
            'purpose'     => $purpose,
            'stream'      => true,
            'site_url'    => home_url(),
        ];
        if ( ! empty( $options['extra_body'] ) ) {
            $body = array_merge( $body, $options['extra_body'] );
        }

        $timeout  = isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout();
        // SSE streaming MUST use the direct llm/router/v1/chat/stream endpoint.
        // bizcity/v1/llm/chat/stream is handled by Hub REST which internally dispatches
        // into handle_chat_stream() — the ob_end_flush() calls inside it cannot escape
        // Hub REST's own WP_REST_Server output-buffer layer, so all SSE chunks arrive
        // buffered in one burst at the end (cURL sees HTTP 200 + empty stream, then falls
        // back to blocking). The legacy llm/router/v1 route goes directly to the SSE
        // handler with no intermediate wrapper, so real-time streaming works correctly.
        // DO NOT migrate this URL to bizcity/v1 until Hub REST supports SSE pass-through.
        $endpoint = $this->get_gateway_url() . '/wp-json/llm/router/v1/chat/stream';

        $full_text    = '';
        $usage        = [];
        $buffer       = '';
        $raw_response = '';
        $actual_model = '';

        error_log( '[LLM-Client] v2-curl_multi | mode=gateway | endpoint=' . $endpoint . ' | model=' . $model );

        // Chunk queue: WRITEFUNCTION pushes parsed deltas here.
        // The curl_multi polling loop reads from the queue and calls on_chunk
        // *outside* the cURL callback context.
        //
        // Why curl_multi instead of curl_exec:
        // On LiteSpeed / shared hosting, curl_exec blocks the PHP process inside
        // the C library. The web server sees zero PHP output for 8-10s, interprets
        // it as an idle LSAPI connection, and kills the process → CURLE_WRITE_ERROR 23.
        // curl_multi keeps PHP's execution loop active, preventing the kill.
        $chunk_queue  = [];
        $on_keepalive = $options['on_keepalive'] ?? null;

        $ch = curl_init( $endpoint );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'X-Site-URL: ' . home_url(),
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_ENCODING       => '',
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_BUFFERSIZE     => 1024,
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$full_text, &$usage, &$buffer, &$raw_response, &$actual_model, &$chunk_queue ) {
                $raw_len = strlen( $data );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    static $write_t0;
                    if ( ! $write_t0 ) { $write_t0 = microtime( true ); }
                    $w_ms = (int) round( ( microtime( true ) - $write_t0 ) * 1000 );
                    error_log( '[llm-stream-write] +' . $w_ms . 'ms | recv ' . $raw_len . 'B | queue=' . count( $chunk_queue ) . ' | full_len=' . strlen( $full_text ) );
                }
                if ( $buffer === '' && $raw_len >= 3 && substr( $data, 0, 3 ) === "\xEF\xBB\xBF" ) {
                    $data = substr( $data, 3 );
                }
                $raw_response .= $data;

                $data = str_replace( [ "\r\n", "\r" ], "\n", $data );
                $buffer .= $data;

                while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
                    $line   = trim( substr( $buffer, 0, $nl ) );
                    $buffer = substr( $buffer, $nl + 1 );

                    if ( $line === '' || stripos( $line, 'data:' ) !== 0 ) {
                        continue;
                    }
                    $json_str = ltrim( substr( $line, 5 ) );
                    if ( $json_str === '[DONE]' ) {
                        continue;
                    }
                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }

                    if ( ! empty( $chunk['done'] ) ) {
                        if ( isset( $chunk['usage'] ) ) {
                            $usage = $chunk['usage'];
                        }
                        if ( isset( $chunk['model'] ) ) {
                            $actual_model = $chunk['model'];
                        }
                        continue;
                    }

                    $delta = $chunk['choices'][0]['delta']['content']
                          ?? $chunk['delta']
                          ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        $chunk_queue[] = [ $delta, $full_text ];
                    }
                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }
                return $raw_len;
            },
        ] );

        // ── curl_multi polling loop ──
        $mh = curl_multi_init();
        curl_multi_add_handle( $mh, $ch );
        $last_ping = microtime( true );

        do {
            $status = curl_multi_exec( $mh, $still_running );

            // Drain chunk queue → deliver to browser immediately
            if ( ! empty( $chunk_queue ) && is_callable( $on_chunk ) ) {
                foreach ( $chunk_queue as $qc ) {
                    call_user_func( $on_chunk, $qc[0], $qc[1] );
                }
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG && count( $chunk_queue ) > 0 ) {
                    $p_ms = (int) round( ( microtime( true ) - $stream_t0 ) * 1000 );
                    error_log( '[llm-stream-poll] +' . $p_ms . 'ms | drained ' . count( $chunk_queue ) . ' chunks | full_len=' . strlen( $full_text ) );
                }
                $chunk_queue = [];
                $last_ping = microtime( true );
            }

            // Send SSE keepalive ping every 3 seconds to prevent web server
            // from killing the browser→PHP connection during LLM thinking time.
            $now = microtime( true );
            if ( is_callable( $on_keepalive ) && ( $now - $last_ping ) >= 3.0 ) {
                call_user_func( $on_keepalive );
                $last_ping = $now;
            }

            if ( $still_running > 0 ) {
                curl_multi_select( $mh, 0.05 );
            }
        } while ( $still_running > 0 && $status === CURLM_OK );

        // Flush remaining queued chunks
        if ( ! empty( $chunk_queue ) && is_callable( $on_chunk ) ) {
            foreach ( $chunk_queue as $qc ) {
                call_user_func( $on_chunk, $qc[0], $qc[1] );
            }
            $chunk_queue = [];
        }

        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        $ok        = ( $curl_err === '' && $http_code >= 200 && $http_code < 300 );
        curl_multi_remove_handle( $mh, $ch );
        curl_close( $ch );
        curl_multi_close( $mh );

        $stream_ms = (int) round( ( microtime( true ) - $stream_t0 ) * 1000 );

        if ( ! $ok || $http_code < 200 || $http_code >= 300 ) {
            $err_detail = $buffer ? mb_substr( trim( $buffer ), 0, 500 ) : '';
            $base['error'] = $curl_err ?: "HTTP {$http_code}";
            if ( $err_detail ) {
                $base['error'] .= ' | ' . $err_detail;
            }
            $base['stream_ms']       = $stream_ms;
            $base['stream_fallback'] = false;
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "[bizcity-llm] chat_stream_gateway error: HTTP {$http_code} curl={$curl_err} model={$model} stream_ms={$stream_ms} full_text_len=" . strlen( $full_text ) . " body={$err_detail}" );
            }
            // CURLE_WRITE_ERROR (23) often means the client SSE connection dropped
            // while cURL was still receiving data. If we managed to collect text
            // before the abort, recover it so the reply can still be logged/saved.
            if ( ! empty( $full_text ) ) {
                $base['success'] = true;
                $base['message'] = $full_text;
                $base['model']   = $actual_model ?: $model;
                $base['usage']   = $usage;
            }
            return $base;
        }

        $base['success'] = true;
        $base['message'] = $full_text;
        $base['model']   = $actual_model ?: $model;
        $base['usage']   = $usage;
        $base['stream_ms'] = $stream_ms;
        $base['stream_fallback'] = false;

        // If upstream/proxy buffered SSE into a single payload, recover chunks from raw body.
        if ( $full_text === '' && $raw_response !== '' ) {
            $normalized = str_replace( [ "\r\n", "\r" ], "\n", $raw_response );
            if ( preg_match_all( '/(?:^|\n)\s*data:\s*(.+?)(?=\n\s*data:\s*|\z)/s', $normalized, $matches ) ) {
                foreach ( $matches[1] as $json_str ) {
                    $json_str = trim( $json_str );
                    if ( $json_str === '' || $json_str === '[DONE]' ) {
                        continue;
                    }

                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }

                    if ( ! empty( $chunk['done'] ) ) {
                        if ( isset( $chunk['usage'] ) ) {
                            $usage = $chunk['usage'];
                        }
                        if ( isset( $chunk['model'] ) ) {
                            $actual_model = $chunk['model'];
                        }
                        continue;
                    }

                    $delta = $chunk['choices'][0]['delta']['content']
                          ?? $chunk['delta']
                          ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        if ( is_callable( $on_chunk ) ) {
                            call_user_func( $on_chunk, $delta, $full_text );
                        }
                    }
                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }

                $base['message'] = $full_text;
                $base['model']   = $actual_model ?: $model;
                $base['usage']   = $usage;
            }

            // Some gateways/proxies may downgrade stream endpoint to blocking JSON.
            // Recover here to avoid a second blocking retry call (saves ~10-20s).
            if ( $full_text === '' ) {
                $trimmed = trim( $raw_response );
                $decoded_raw = json_decode( $trimmed, true );
                if ( is_array( $decoded_raw ) ) {
                    $msg = $decoded_raw['message']
                        ?? $decoded_raw['choices'][0]['message']['content']
                        ?? '';
                    if ( is_string( $msg ) && $msg !== '' ) {
                        $full_text = $msg;
                        if ( is_callable( $on_chunk ) ) {
                            call_user_func( $on_chunk, $msg, $msg );
                        }
                        $usage = is_array( $decoded_raw['usage'] ?? null ) ? $decoded_raw['usage'] : $usage;
                        $actual_model = (string) ( $decoded_raw['model'] ?? $actual_model ?: $model );
                        $base['message'] = $full_text;
                        $base['model']   = $actual_model ?: $model;
                        $base['usage']   = $usage;
                        $base['stream_fallback'] = false;
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[bizcity-llm] chat_stream_gateway recovered from non-SSE JSON body | model=' . $base['model'] . ' | stream_ms=' . $stream_ms . ' | msg_len=' . strlen( $msg ) );
                        }
                    }
                }
            }
        }

        // Gateway returned 200 but no content was streamed — likely gateway-side error (BOM-only, silent crash, etc.)
        // Retry as non-streaming call so user gets a response
        if ( $full_text === '' && is_callable( $on_chunk ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $preview = mb_substr( trim( str_replace( [ "\r", "\n" ], ' ', $raw_response ) ), 0, 240 );
                error_log( '[bizcity-llm] chat_stream_gateway empty-stream diagnostics | model=' . $model . ' | http=' . $http_code . ' | stream_ms=' . $stream_ms . ' | raw_len=' . strlen( $raw_response ) . ' | buf_len=' . strlen( $buffer ) . ' | preview=' . $preview );
            }
            error_log( '[bizcity-llm] chat_stream_gateway: 200 OK but empty stream — retrying as blocking call' );
            $blocking = $this->chat_gateway( $messages, $options );
            $blocking['stream_fallback'] = true;
            $blocking['stream_ms'] = $stream_ms;
            if ( ! empty( $blocking['message'] ) ) {
                // Send the full reply as a single SSE chunk so the frontend receives it
                call_user_func( $on_chunk, $blocking['message'], $blocking['message'] );
            }
            return $blocking;
        }

        return $base;
    }

    /* ================================================================
     *  DIRECT MODE — call OpenRouter directly
     * ================================================================ */

    private function chat_direct( array $messages, array $options ): array {
        $base    = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenRouter API key chưa được cấu hình.';
            return $base;
        }

        $base['provider'] = 'openrouter';
        $purpose  = $options['purpose'] ?? 'chat';

        $primary = $options['model'] ?? '';
        if ( empty( $primary ) ) {
            $primary = $this->get_model( $purpose );
        }

        $fallback = array_key_exists( 'fallback_model', $options )
            ? (string) $options['fallback_model']
            : $this->get_fallback_model( $purpose );

        $no_fallback = ! empty( $options['no_fallback'] ) || $fallback === '' || $fallback === $primary;

        $base['model_primary'] = $primary;

        $result = $this->call_openrouter( $primary, $messages, $options, $api_key );
        if ( $result['success'] ) {
            return array_merge( $base, $result );
        }

        $primary_error = $result['error'];
        error_log( "[BizCity_LLM] Primary model '{$primary}' failed: {$primary_error}" );

        if ( $no_fallback ) {
            return array_merge( $base, $result );
        }

        error_log( "[BizCity_LLM] Falling back to '{$fallback}'" );
        $fb_result = $this->call_openrouter( $fallback, $messages, $options, $api_key );

        if ( $fb_result['success'] ) {
            $fb_result['fallback_used'] = true;
            $fb_result['model_primary'] = $primary;
            $fb_result['error']         = '';
            return array_merge( $base, $fb_result );
        }

        $base['error'] = "Primary ({$primary}): {$primary_error} | Fallback ({$fallback}): {$fb_result['error']}";
        return $base;
    }

    /**
     * Single model call to OpenRouter (direct mode).
     */
    private function call_openrouter( string $model_id, array $messages, array $options, string $api_key ): array {
        $result = [
            'success'       => false,
            'message'       => '',
            'model'         => $model_id,
            'model_primary' => $model_id,
            'fallback_used' => false,
            'provider'      => 'openrouter',
            'usage'         => [],
            'error'         => '',
        ];

        $timeout = isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout();

        $body = array_merge(
            [
                'model'       => $model_id,
                'messages'    => $messages,
                'temperature' => floatval( $options['temperature'] ?? 0.7 ),
                'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
            ],
            $options['extra_body'] ?? []
        );

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => $options['http_referer'] ?? network_home_url(),
                'X-Title'       => $options['x_title'] ?? $this->get_setting( 'site_name', 'BizCity' ),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $decoded['choices'][0]['message']['content'] ) ) {
            $result['success'] = true;
            $result['message'] = trim( $decoded['choices'][0]['message']['content'] );
            $result['model']   = $decoded['model'] ?? $model_id;
            $result['usage']   = $decoded['usage'] ?? [];
            return $result;
        }

        $result['error'] = $decoded['error']['message'] ?? 'Không nhận được phản hồi từ LLM.';
        return $result;
    }

    private function chat_stream_direct( array $messages, array $options, $on_chunk = null ): array {
        $base    = $this->empty_result();
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenRouter API key chưa được cấu hình.';
            return $base;
        }

        if ( ! function_exists( 'curl_init' ) ) {
            return $this->chat_direct( $messages, $options );
        }

        $base['provider'] = 'openrouter';
        $purpose = $options['purpose'] ?? 'chat';
        $model   = $options['model'] ?? '';
        if ( empty( $model ) ) {
            $model = $this->get_model( $purpose );
        }
        $base['model']         = $model;
        $base['model_primary'] = $model;

        $result = $this->stream_single_model( $model, $messages, $options, $api_key, $on_chunk );

        if ( $result['success'] ) {
            $result['fallback_used'] = false;
            return array_merge( $base, $result );
        }

        // Fallback only if no partial output was streamed
        if ( ! empty( $result['message'] ) ) {
            return array_merge( $base, $result, [ 'fallback_used' => false ] );
        }

        $fallback = $options['fallback_model'] ?? '';
        if ( empty( $fallback ) ) {
            $fallback = $this->get_fallback_model( $purpose );
        }
        if ( empty( $fallback ) || $fallback === $model ) {
            return array_merge( $base, $result, [ 'fallback_used' => false ] );
        }

        error_log( "[BizCity-LLM] Stream direct primary '{$model}' failed: {$result['error']}. Trying '{$fallback}'" );
        $fb = $this->stream_single_model( $fallback, $messages, $options, $api_key, $on_chunk );
        $fb['fallback_used'] = true;
        $fb['model_primary'] = $model;
        $fb['model']         = $fallback;

        if ( ! $fb['success'] ) {
            $fb['error'] = "Primary ({$model}): {$result['error']} | Fallback ({$fallback}): {$fb['error']}";
        }

        return array_merge( $base, $fb );
    }

    /**
     * Stream a single model call to OpenRouter (used by chat_stream_direct).
     */
    private function stream_single_model( string $model, array $messages, array $options, string $api_key, $on_chunk = null ): array {
        $result = [
            'success' => false,
            'message' => '',
            'model'   => $model,
            'usage'   => [],
            'error'   => '',
        ];

        $body = array_merge(
            [
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => floatval( $options['temperature'] ?? 0.7 ),
                'max_tokens'  => intval( $options['max_tokens']  ?? 3000 ),
                'stream'      => true,
            ],
            $options['extra_body'] ?? []
        );

        $full_text = '';
        $usage     = [];
        $buffer    = '';

        $ch = curl_init( 'https://openrouter.ai/api/v1/chat/completions' );
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key,
                'HTTP-Referer: ' . ( $options['http_referer'] ?? network_home_url() ),
                'X-Title: ' . ( $options['x_title'] ?? $this->get_setting( 'site_name', 'BizCity' ) ),
            ],
            CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT        => isset( $options['timeout'] ) ? intval( $options['timeout'] ) : $this->get_timeout(),
            CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$full_text, &$usage, &$buffer, $on_chunk ) {
                $buffer .= $data;
                while ( ( $nl = strpos( $buffer, "\n" ) ) !== false ) {
                    $line   = trim( substr( $buffer, 0, $nl ) );
                    $buffer = substr( $buffer, $nl + 1 );

                    if ( $line === '' || strpos( $line, 'data: ' ) !== 0 ) {
                        continue;
                    }
                    $json_str = substr( $line, 6 );
                    if ( $json_str === '[DONE]' ) {
                        continue;
                    }
                    $chunk = json_decode( $json_str, true );
                    if ( ! is_array( $chunk ) ) {
                        continue;
                    }
                    $delta = $chunk['choices'][0]['delta']['content'] ?? '';
                    if ( $delta !== '' ) {
                        $full_text .= $delta;
                        if ( is_callable( $on_chunk ) ) {
                            call_user_func( $on_chunk, $delta, $full_text );
                        }
                    }
                    if ( isset( $chunk['usage'] ) ) {
                        $usage = $chunk['usage'];
                    }
                }
                return strlen( $data );
            },
        ] );

        $ok        = curl_exec( $ch );
        $http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $curl_err  = curl_error( $ch );
        curl_close( $ch );

        if ( ! $ok || $http_code < 200 || $http_code >= 300 ) {
            $result['error'] = $curl_err ?: "HTTP {$http_code}";
            if ( ! empty( $full_text ) ) {
                $result['success'] = true;
                $result['message'] = $full_text;
                $result['usage']   = $usage;
            }
            return $result;
        }

        $result['success'] = true;
        $result['message'] = $full_text;
        $result['usage']   = $usage;
        return $result;
    }

    /* ================================================================
     *  Embeddings
     * ================================================================ */

    public function embeddings( $input, array $options = [] ): array {
        $start = microtime( true );
        $model = $options['model'] ?? $this->get_model( 'embedding' );

        $this->debug_log( 'embeddings() START', [ 'mode' => $this->get_mode(), 'model' => $model ] );

        if ( $this->get_mode() === 'direct' ) {
            $result = $this->embeddings_direct( $input, $options );
        } else {
            $result = $this->embeddings_gateway( $input, $options );
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'embeddings() END', [ 'success' => $result['success'], 'ms' => $ms ] );

        $this->log_usage( [
            'success' => $result['success'] ?? false,
            'model'   => $result['model'] ?? $model,
            'usage'   => $result['usage'] ?? [],
            'error'   => $result['error'] ?? '',
        ], $options, 'embeddings', $model, $ms );

        return $result;
    }

    private function embeddings_gateway( $input, array $options ): array {
        $base = [
            'success' => false, 'embeddings' => [], 'model' => '',
            'usage' => [], 'error' => '', 'dimensions' => 0,
        ];

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            return $base;
        }

        $texts = is_array( $input ) ? array_values( $input ) : [ (string) $input ];
        $model = $options['model'] ?? $this->get_model( 'embedding' );

        // [2026-03-25] Unified API namespace: migrate llm/router/v1/embeddings → bizcity/v1/llm/embeddings
        // $response = wp_remote_post( $this->get_gateway_url() . '/wp-json/llm/router/v1/embeddings', [
        $response = wp_remote_post( $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/embeddings', [
            'timeout'     => intval( $options['timeout'] ?? 30 ),
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( [
                'model' => $model,
                'input' => count( $texts ) === 1 ? $texts[0] : $texts,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $decoded['success'] ) && ! empty( $decoded['embeddings'] ) ) {
            return array_merge( $base, [
                'success'    => true,
                'embeddings' => $decoded['embeddings'],
                'model'      => $decoded['model'] ?? $model,
                'usage'      => $decoded['usage'] ?? [],
                'dimensions' => $decoded['dimensions'] ?? ( ! empty( $decoded['embeddings'][0] ) ? count( $decoded['embeddings'][0] ) : 0 ),
            ] );
        }

        $base['error'] = $decoded['error'] ?? $decoded['message'] ?? 'Embedding failed.';
        return $base;
    }

    private function embeddings_direct( $input, array $options ): array {
        $base = [
            'success' => false, 'embeddings' => [], 'model' => '',
            'usage' => [], 'error' => '', 'dimensions' => 0,
        ];

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenRouter API key chưa được cấu hình.';
            return $base;
        }

        $texts   = is_array( $input ) ? array_values( $input ) : [ (string) $input ];
        $primary = $options['model'] ?? $this->get_model( 'embedding' );
        $timeout = intval( $options['timeout'] ?? 30 );

        $result = $this->call_openrouter_embeddings( $primary, $texts, $api_key, $timeout );
        if ( $result['success'] ) {
            return $result;
        }

        $fallback = $this->get_fallback_model( 'embedding' );
        if ( $fallback && $fallback !== $primary ) {
            $fb = $this->call_openrouter_embeddings( $fallback, $texts, $api_key, $timeout );
            if ( $fb['success'] ) {
                return $fb;
            }
            $base['error'] = "Primary ({$primary}): {$result['error']} | Fallback ({$fallback}): {$fb['error']}";
        } else {
            $base['error'] = $result['error'];
        }

        return $base;
    }

    private function call_openrouter_embeddings( string $model_id, array $texts, string $api_key, int $timeout ): array {
        $result = [
            'success' => false, 'embeddings' => [], 'model' => $model_id,
            'usage' => [], 'error' => '', 'dimensions' => 0,
        ];

        $response = wp_remote_post( 'https://openrouter.ai/api/v1/embeddings', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => network_home_url(),
                'X-Title'       => get_site_option( 'site_name', 'BizCity' ),
            ],
            'body' => wp_json_encode( [
                'model' => $model_id,
                'input' => count( $texts ) === 1 ? $texts[0] : $texts,
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
            $embeddings = [];
            foreach ( $decoded['data'] as $item ) {
                if ( isset( $item['embedding'] ) ) {
                    $embeddings[] = $item['embedding'];
                }
            }
            if ( ! empty( $embeddings ) ) {
                $result['success']    = true;
                $result['embeddings'] = $embeddings;
                $result['model']      = $decoded['model'] ?? $model_id;
                $result['usage']      = $decoded['usage'] ?? [];
                $result['dimensions'] = count( $embeddings[0] );
                return $result;
            }
        }

        $result['error'] = $decoded['error']['message'] ?? 'Không nhận được embedding.';
        return $result;
    }

    /* ================================================================
     *  Fetch available models (from gateway or OpenRouter)
     * ================================================================ */

    public function get_available_models( ?string $category = null ): array {
        $transient_key = 'bizcity_llm_models';
        $cached = get_site_transient( $transient_key );

        if ( false === $cached ) {
            if ( $this->get_mode() === 'gateway' ) {
                $cached = $this->fetch_gateway_models();
            } else {
                $cached = $this->fetch_openrouter_models();
            }
            if ( ! empty( $cached ) ) {
                set_site_transient( $transient_key, $cached, DAY_IN_SECONDS );
            }
        }

        if ( $category && is_array( $cached ) ) {
            return array_filter( $cached, fn( $m ) =>
                isset( $m['id'] ) && (
                    str_contains( strtolower( $m['id'] ), strtolower( $category ) ) ||
                    str_contains( strtolower( $m['name'] ?? '' ), strtolower( $category ) )
                )
            );
        }

        return is_array( $cached ) ? $cached : [];
    }

    private function fetch_gateway_models(): array {
        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) return [];

        // [2026-03-25] Unified API namespace: migrate llm/router/v1/models → bizcity/v1/llm/models
        // $response = wp_remote_get( $this->get_gateway_url() . '/wp-json/llm/router/v1/models', [
        $response = wp_remote_get( $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/models', [
            'timeout'     => 15,
            'redirection' => 0,
            'headers'     => [ 'Authorization' => 'Bearer ' . $api_key ],
        ] );

        if ( is_wp_error( $response ) ) return [];

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'] ?? $body['models'] ?? [];
    }

    private function fetch_openrouter_models(): array {
        $api_key  = $this->get_api_key();
        $response = wp_remote_get( 'https://openrouter.ai/api/v1/models', [
            'timeout' => 15,
            'headers' => empty( $api_key ) ? [] : [ 'Authorization' => 'Bearer ' . $api_key ],
        ] );

        if ( is_wp_error( $response ) ) return [];

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return $body['data'] ?? [];
    }

    public function bust_models_cache(): void {
        delete_site_transient( 'bizcity_llm_models' );
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    private function empty_result(): array {
        return [
            'success'       => false,
            'message'       => '',
            'model'         => '',
            'model_primary' => '',
            'fallback_used' => false,
            'provider'      => $this->get_mode() === 'direct' ? 'openrouter' : 'bizcity-gateway',
            'usage'         => [],
            'error'         => '',
        ];
    }

    /**
     * Record a usage log entry if the usage-log class is loaded.
     */
    private function log_usage( array $result, array $options, string $endpoint, string $model_requested, int $ms ): void {
        if ( ! class_exists( 'BizCity_LLM_Usage_Log' ) ) {
            return;
        }
        BizCity_LLM_Usage_Log::log( [
            'mode'            => $this->get_mode(),
            'purpose'         => $options['purpose'] ?? 'chat',
            'endpoint'        => $endpoint,
            'model_requested' => $model_requested,
            'model_used'      => $result['model'] ?? '',
            'fallback_used'   => $result['fallback_used'] ?? false,
            'success'         => $result['success'] ?? false,
            'usage'           => $result['usage'] ?? [],
            'latency_ms'      => $ms,
            'error'           => $result['error'] ?? '',
        ] );
    }

    /* ================================================================
     *  Image Generation (via gateway or direct OpenAI)
     * ================================================================ */

    /**
     * Generate an image via the LLM gateway (or direct OpenAI in direct mode).
     *
     * @param string $prompt  Image prompt.
     * @param array  $options { model, size, n, timeout, site_url }
     * @return array { success, image_url, b64_json, model, error }
     */
    public function generate_image( string $prompt, array $options = [] ): array {
        $start = microtime( true );

        $this->debug_log( 'generate_image() START', [
            'mode' => $this->get_mode(), 'model' => $options['model'] ?? 'gpt-image-1',
        ] );

        if ( $this->get_mode() === 'direct' ) {
            $result = $this->generate_image_direct( $prompt, $options );
        } else {
            $result = $this->generate_image_gateway( $prompt, $options );
        }

        $ms = intval( ( microtime( true ) - $start ) * 1000 );
        $this->debug_log( 'generate_image() END', [
            'success' => $result['success'], 'ms' => $ms,
        ] );

        $this->log_usage( [
            'success' => $result['success'],
            'model'   => $result['model'] ?? 'gpt-image-1',
            'usage'   => [],
            'error'   => $result['error'] ?? '',
        ], array_merge( $options, [ 'purpose' => 'image' ] ), 'image', $options['model'] ?? 'gpt-image-1', $ms );

        return $result;
    }

    /**
     * Image generation via gateway REST endpoint.
     */
    private function generate_image_gateway( string $prompt, array $options ): array {
        $base = [
            'success'   => false,
            'image_url' => '',
            'b64_json'  => '',
            'model'     => $options['model'] ?? 'gpt-image-1',
            'error'     => '',
        ];

        $api_key = $this->get_api_key();
        if ( empty( $api_key ) ) {
            $base['error'] = 'BizCity LLM API key chưa được cấu hình.';
            return $base;
        }

        $body = [
            'prompt'  => $prompt,
            'model'   => $options['model'] ?? 'gpt-image-1',
            'size'    => $options['size'] ?? '1024x1024',
            'n'       => intval( $options['n'] ?? 1 ),
            'timeout' => intval( $options['timeout'] ?? 120 ),
            'site_url' => home_url(),
        ];

        $endpoint = $this->get_gateway_url() . '/wp-json/bizcity/v1/llm/images/generations';

        $response = wp_remote_post( $endpoint, [
            'timeout'     => intval( $options['timeout'] ?? 120 ) + 10,
            'redirection' => 0,
            'headers'     => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'X-Site-URL'    => home_url(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 402 ) {
            $base['error'] = $decoded['error'] ?? 'Hết credit. Vui lòng nạp thêm tại bizcity.vn';
            return $base;
        }

        if ( ! empty( $decoded['success'] ) ) {
            return array_merge( $base, [
                'success'   => true,
                'image_url' => $decoded['image_url'] ?? '',
                'b64_json'  => $decoded['b64_json'] ?? '',
                'model'     => $decoded['model'] ?? $base['model'],
            ] );
        }

        $base['error'] = $decoded['error'] ?? 'Image generation failed. HTTP ' . $code;
        return $base;
    }

    /**
     * Image generation direct to OpenAI (fallback when in direct mode).
     */
    private function generate_image_direct( string $prompt, array $options ): array {
        $base = [
            'success'   => false,
            'image_url' => '',
            'b64_json'  => '',
            'model'     => $options['model'] ?? 'gpt-image-1',
            'error'     => '',
        ];

        // In direct mode, use local OpenAI key
        $api_key = get_option( 'twf_openai_api_key', '' );
        if ( empty( $api_key ) ) {
            $api_key = get_option( 'bztimg_openai_key', '' );
        }
        if ( empty( $api_key ) ) {
            $base['error'] = 'OpenAI API key chưa được cấu hình.';
            return $base;
        }

        $model   = $options['model'] ?? 'gpt-image-1';
        $size    = $options['size'] ?? '1024x1024';
        $timeout = intval( $options['timeout'] ?? 120 );

        $body = [
            'model'  => $model,
            'prompt' => $prompt,
            'n'      => 1,
            'size'   => $size,
        ];

        $response = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            $base['error'] = $response->get_error_message();
            return $base;
        }

        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $decoded['data'][0]['url'] ) ) {
            $base['success']   = true;
            $base['image_url'] = $decoded['data'][0]['url'];
            return $base;
        }

        if ( ! empty( $decoded['data'][0]['b64_json'] ) ) {
            $base['success']  = true;
            $base['b64_json'] = $decoded['data'][0]['b64_json'];
            return $base;
        }

        $base['error'] = $decoded['error']['message'] ?? 'OpenAI image generation failed.';
        return $base;
    }
}
