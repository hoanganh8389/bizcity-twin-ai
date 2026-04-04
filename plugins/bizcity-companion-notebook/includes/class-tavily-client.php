<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tavily API Client — web search + content extraction.
 *
 * Requires constant TAVILY_API_KEY defined in wp-config.php.
 */
class BCN_Tavily_Client {

    const API_BASE    = 'https://api.tavily.com';
    const TIMEOUT_SEC = 30;
    const MAX_CONTENT = 5000; // chars per page

    /**
     * Search the web and return raw Tavily results.
     *
     * @param string $query
     * @param int    $max_results  Number of results to request (1–20).
     * @param string $language     'en' | 'vi' | etc. (sent as search_language hint).
     * @return array|WP_Error  Array of result objects on success.
     */
    public static function search( $query, $max_results = 10, $language = 'vi' ) {
        // Prefer network option (set via Network Admin → BizCity OpenRouter).
        $api_key = (string) get_site_option( 'bizcity_tavily_api_key', '' );
        if ( empty( $api_key ) && defined( 'TAVILY_API_KEY' ) ) {
            $api_key = TAVILY_API_KEY; // backward-compat with wp-config.php constant
        }
        if ( empty( $api_key ) ) {
            return new WP_Error(
                'no_api_key',
                'Tavily API key chưa được cấu hình. Vào Network Admin → Settings → BizCity OpenRouter để nhập key.'
            );
        }

        $body = [
            'api_key'              => $api_key,
            'query'                => (string) $query,
            'max_results'          => min( (int) $max_results, 20 ),
            'include_raw_content'  => true,
            'include_answer'       => false,
        ];

        $response = wp_remote_post(
            self::API_BASE . '/search',
            [
                'timeout' => self::TIMEOUT_SEC,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( $code !== 200 || empty( $data['results'] ) ) {
            $msg = $data['message'] ?? $data['detail'] ?? "Tavily HTTP {$code}";
            return new WP_Error( 'tavily_error', $msg );
        }

        // Normalize each result.
        $results = [];
        foreach ( $data['results'] as $item ) {
            $raw_content = (string) ( $item['raw_content'] ?? $item['content'] ?? '' );
            $clean       = self::clean_content( $raw_content );
            $results[]   = [
                'url'          => (string) ( $item['url'] ?? '' ),
                'title'        => (string) ( $item['title'] ?? '' ),
                'excerpt'      => mb_substr( (string) ( $item['content'] ?? $clean ), 0, 300 ),
                'content'      => mb_substr( $clean, 0, self::MAX_CONTENT ),
                'score'        => (float) ( $item['score'] ?? 0.0 ),   // Tavily relevance 0-1
                'published_at' => (string) ( $item['published_date'] ?? '' ),
                'domain'       => self::extract_domain( $item['url'] ?? '' ),
            ];
        }

        return $results;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Strip tags, collapse whitespace, normalize for LLM consumption.
     */
    private static function clean_content( $text ) {
        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
        $text = preg_replace( '/\s+/', ' ', $text );
        return trim( $text );
    }

    /**
     * Extract root domain from URL, e.g. "https://www.arxiv.org/..." → "arxiv.org"
     */
    public static function extract_domain( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) return '';
        // Remove leading www.
        return preg_replace( '/^www\./i', '', strtolower( $host ) );
    }
}
