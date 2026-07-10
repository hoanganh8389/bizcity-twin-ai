<?php
/**
 * BizCity Search Client — Gateway-only web search via search/router/v1.
 *
 * 100% server-routed: all requests go through {gateway_url}/wp-json/search/router/v1/*.
 * User only needs the BizCity API key — no Tavily key required on client side.
 *
 * Pattern mirrors BizCity_LLM_Client (singleton, gateway URL + API key from site options).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\BizCity_LLM
 * @since      1.4.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Search_Client {

    /* ─── Singleton ─── */
    private static ?self $instance = null;

    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /* ─── Constants ─── */
    const TIMEOUT_SEC = 30;
    const MAX_CONTENT = 5000; // chars per page — kept for compat with BCN_Tavily_Client

    /* ================================================================
     *  Configuration — reuses LLM gateway settings
     * ================================================================ */

    /**
     * Gateway base URL (same as LLM: bizcity.vn or bizcity.ai).
     */
    public function get_gateway_url(): string {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option
        return rtrim( (string) get_option( 'bizcity_llm_gateway_url', 'https://bizcity.vn' ), '/' );
    }

    /**
     * BizCity API key (same key for LLM + Search + all services).
     */
    public function get_api_key(): string {
        // [2026-06-10 Johnny Chu] HOTFIX — per-site option
        // [2026-06-24 Johnny Chu] HOTFIX-MULTISITE — fallback to main site when sub-site key is empty
        $key = trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
        if ( $key === '' && is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
            switch_to_blog( get_main_site_id() );
            $key = trim( (string) get_option( 'bizcity_llm_api_key', '' ) );
            restore_current_blog();
        }
        return $key;
    }

    /**
     * Whether the client is configured and ready.
     */
    public function is_ready(): bool {
        return ! empty( $this->get_gateway_url() ) && ! empty( $this->get_api_key() );
    }

    /* ── Debug logging ── */
    /**
     * Record a search-service call into the unified usage log (R-1API Phase B).
     * Best-effort: silently no-ops if the log class is unavailable.
     */
    // [2026-06-10 Johnny Chu] R-LLM-USAGE — write to per-blog clients table.
    private function record_usage( string $endpoint, bool $success, int $ms, string $error = '', string $model = '' ): void {
        if ( ! class_exists( 'BizCity_LLM_Usage_Clients' ) ) return;
        BizCity_LLM_Usage_Clients::log( [
            'service'    => 'search',
            'mode'       => 'gateway',
            'purpose'    => $endpoint, // search|extract|crawl
            'endpoint'   => 'search',
            'model_used' => $model ?: 'tavily',
            'success'    => $success,
            'latency_ms' => $ms,
            'error'      => $error,
        ] );
    }

    private function debug_log( string $msg, array $ctx = [] ): void {
        if ( ! defined( 'BIZCITY_LLM_DEBUG' ) || ! BIZCITY_LLM_DEBUG ) {
            return;
        }
        $extra = $ctx ? ' | ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '';
        error_log( "[BizCity-Search] {$msg}{$extra}" );
    }

    /* ================================================================
     *  POST /search/router/v1/query — Web search
     * ================================================================ */

    /**
     * Search the web via BizCity Search Router.
     *
     * @param string $query        Search query.
     * @param int    $max_results  1–20, default 10.
     * @param array  $options {
     *   @type string $search_depth        'basic' (default) | 'advanced'.
     *   @type string $topic               'general' (default) | 'news'.
     *   @type array  $include_domains     Domain whitelist.
     *   @type array  $exclude_domains     Domain blacklist.
     *   @type bool   $include_raw_content Include full page text (default true).
     *   @type bool   $include_answer      Include AI answer (default false).
     * }
     * @return array|WP_Error  Array of normalized results on success.
     */
    public function search( string $query, int $max_results = 10, array $options = [] ) {
        if ( ! $this->is_ready() ) {
            return new WP_Error(
                'search_not_configured',
                'BizCity API key chưa được cấu hình. Vào BizCity Settings để nhập API key.'
            );
        }

        $endpoint = $this->get_gateway_url() . '/wp-json/search/router/v1/query';
        $start    = microtime( true );

        $body = [
            'query'               => $query,
            'max_results'         => min( max( $max_results, 1 ), 20 ),
            'search_depth'        => sanitize_text_field( $options['search_depth'] ?? 'basic' ),
            'include_raw_content' => $options['include_raw_content'] ?? true,
            'include_answer'      => $options['include_answer'] ?? false,
        ];

        if ( ! empty( $options['topic'] ) ) {
            $body['topic'] = sanitize_text_field( $options['topic'] );
        }
        if ( ! empty( $options['include_domains'] ) && is_array( $options['include_domains'] ) ) {
            $body['include_domains'] = $options['include_domains'];
        }
        if ( ! empty( $options['exclude_domains'] ) && is_array( $options['exclude_domains'] ) ) {
            $body['exclude_domains'] = $options['exclude_domains'];
        }

        $this->debug_log( 'search() START', [
            'query' => mb_substr( $query, 0, 80 ),
            'max'   => $body['max_results'],
            'depth' => $body['search_depth'],
        ] );

        $response = wp_remote_post( $endpoint, [
            'timeout' => self::TIMEOUT_SEC,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_api_key(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        $ms = intval( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            $this->debug_log( 'search() HTTP ERROR', [ 'error' => $response->get_error_message(), 'ms' => $ms ] );
            $this->record_usage( 'search', false, $ms, $response->get_error_message() );
            return $response;
        }

        $code     = intval( wp_remote_retrieve_response_code( $response ) );
        // [2026-06-24 Johnny Chu] HOTFIX — strip BOM + trim before json_decode;
        // bizcity.vn may prepend UTF-8 BOM causing json_decode to return null on HTTP 200
        // → empty($data['success']) = true → false error "Search gateway HTTP 200".
        $raw_body = wp_remote_retrieve_body( $response );
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $data     = json_decode( trim( $raw_body ), true );

        if ( $code === 401 ) {
            return new WP_Error( 'search_auth_failed', $data['error'] ?? 'API key không hợp lệ.' );
        }
        if ( $code === 402 ) {
            return new WP_Error( 'search_insufficient_credits', $data['error'] ?? 'Không đủ credits. Hãy nạp thêm.' );
        }
        if ( $code === 429 ) {
            return new WP_Error( 'search_rate_limited', $data['error'] ?? 'Quá nhiều request. Thử lại sau.' );
        }
        if ( $code < 200 || $code >= 300 || empty( $data['success'] ) ) {
            $msg = $data['error'] ?? "Search gateway HTTP {$code}";
            $this->debug_log( 'search() FAIL', [ 'code' => $code, 'error' => $msg, 'ms' => $ms ] );
            $this->record_usage( 'search', false, $ms, $msg );
            return new WP_Error( 'search_error', $msg );
        }

        // Normalize to match BCN_Tavily_Client format for backward compatibility
        $results = [];
        foreach ( $data['results'] ?? [] as $item ) {
            $raw_content = (string) ( $item['raw_content'] ?? $item['content'] ?? '' );
            $clean       = self::clean_content( $raw_content );
            $results[]   = [
                'url'          => (string) ( $item['url'] ?? '' ),
                'title'        => (string) ( $item['title'] ?? '' ),
                'excerpt'      => mb_substr( (string) ( $item['content'] ?? $clean ), 0, 300 ),
                'content'      => mb_substr( $clean, 0, self::MAX_CONTENT ),
                'score'        => (float) ( $item['score'] ?? 0.0 ),
                'published_at' => (string) ( $item['published_date'] ?? '' ),
                'domain'       => self::extract_domain( $item['url'] ?? '' ),
            ];
        }

        $this->debug_log( 'search() OK', [ 'results' => count( $results ), 'ms' => $ms ] );
        $this->record_usage( 'search', true, $ms );

        return $results;
    }

    /* ================================================================
     *  POST /search/router/v1/extract — Content extraction
     * ================================================================ */

    /**
     * Extract full-text content from URLs via BizCity Search Router.
     *
     * @param string[] $urls  Up to 20 URLs.
     * @return array|WP_Error  Array of {url, raw_content} on success.
     */
    public function extract( array $urls ) {
        if ( ! $this->is_ready() ) {
            return new WP_Error( 'search_not_configured', 'BizCity API key chưa được cấu hình.' );
        }

        if ( empty( $urls ) ) {
            return new WP_Error( 'no_urls', 'Cần ít nhất 1 URL.' );
        }

        $endpoint = $this->get_gateway_url() . '/wp-json/search/router/v1/extract';
        $start    = microtime( true );

        $this->debug_log( 'extract() START', [ 'url_count' => count( $urls ) ] );

        $response = wp_remote_post( $endpoint, [
            'timeout' => self::TIMEOUT_SEC,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_api_key(),
            ],
            'body' => wp_json_encode( [ 'urls' => array_slice( $urls, 0, 20 ) ] ),
        ] );

        $ms = intval( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code     = intval( wp_remote_retrieve_response_code( $response ) );
        // [2026-06-24 Johnny Chu] HOTFIX — strip BOM + trim before json_decode (extract)
        $raw_body = wp_remote_retrieve_body( $response );
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $data     = json_decode( trim( $raw_body ), true );

        if ( in_array( $code, [ 401, 402, 429 ], true ) ) {
            return new WP_Error( 'search_gateway_' . $code, $data['error'] ?? "HTTP {$code}" );
        }
        if ( $code < 200 || $code >= 300 || empty( $data['success'] ) ) {
            return new WP_Error( 'extract_error', $data['error'] ?? "HTTP {$code}" );
        }

        $this->debug_log( 'extract() OK', [ 'results' => count( $data['results'] ?? [] ), 'ms' => $ms ] );
        $this->record_usage( 'extract', true, $ms );

        return $data['results'] ?? [];
    }

    /* ================================================================
     *  POST /search/router/v1/crawl — Site crawl
     * ================================================================ */

    /**
     * Crawl pages from a seed URL via BizCity Search Router.
     *
     * @param string $url     Seed URL.
     * @param array  $options { limit?: int 1-50, include_favicon?: bool, max_depth?: int }
     * @return array|WP_Error  Array of {url, title, raw_content, favicon} on success.
     */
    public function crawl( string $url, array $options = [] ) {
        if ( ! $this->is_ready() ) {
            return new WP_Error( 'search_not_configured', 'BizCity API key chưa được cấu hình.' );
        }

        $url = esc_url_raw( $url );
        if ( empty( $url ) ) {
            return new WP_Error( 'no_url', 'Cần URL hợp lệ.' );
        }

        $endpoint = $this->get_gateway_url() . '/wp-json/search/router/v1/crawl';
        $start    = microtime( true );

        $body = [
            'url'             => $url,
            'limit'           => isset( $options['limit'] ) ? max( 1, min( intval( $options['limit'] ), 50 ) ) : 10,
            'include_favicon' => ! empty( $options['include_favicon'] ),
        ];
        if ( isset( $options['max_depth'] ) ) {
            $body['max_depth'] = max( 1, min( intval( $options['max_depth'] ), 5 ) );
        }

        $this->debug_log( 'crawl() START', [ 'url' => $url, 'limit' => $body['limit'] ] );

        $response = wp_remote_post( $endpoint, [
            'timeout' => 90,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->get_api_key(),
            ],
            'body' => wp_json_encode( $body ),
        ] );

        $ms = intval( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code     = intval( wp_remote_retrieve_response_code( $response ) );
        // [2026-06-24 Johnny Chu] HOTFIX — strip BOM + trim before json_decode (crawl)
        $raw_body = wp_remote_retrieve_body( $response );
        if ( substr( $raw_body, 0, 3 ) === "\xEF\xBB\xBF" ) {
            $raw_body = substr( $raw_body, 3 );
        }
        $data     = json_decode( trim( $raw_body ), true );

        if ( in_array( $code, [ 401, 402, 429 ], true ) ) {
            return new WP_Error( 'search_gateway_' . $code, $data['error'] ?? "HTTP {$code}" );
        }
        if ( $code < 200 || $code >= 300 || empty( $data['success'] ) ) {
            return new WP_Error( 'crawl_error', $data['error'] ?? "HTTP {$code}" );
        }

        $this->debug_log( 'crawl() OK', [ 'results' => count( $data['results'] ?? [] ), 'ms' => $ms ] );
        $this->record_usage( 'crawl', true, $ms );

        return $data['results'] ?? [];
    }

    /* ================================================================
     *  GET /search/router/v1/health — Health check
     * ================================================================ */

    /**
     * Check whether the search service is healthy.
     *
     * @return array {service, status, version}
     */
    public function health(): array {
        $endpoint = $this->get_gateway_url() . '/wp-json/search/router/v1/health';

        $response = wp_remote_get( $endpoint, [ 'timeout' => 10 ] );

        if ( is_wp_error( $response ) ) {
            return [ 'service' => 'search-router', 'status' => 'unreachable', 'error' => $response->get_error_message() ];
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [ 'status' => 'unknown' ];
    }

    /* ================================================================
     *  Helpers (static — shared format with BCN_Tavily_Client)
     * ================================================================ */

    private static function clean_content( string $text ): string {
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text );
    }

    public static function extract_domain( string $url ): string {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) return '';
        return preg_replace( '/^www\./i', '', strtolower( $host ) );
    }
}
