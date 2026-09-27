<?php
/**
 * Research Tool Router — dispatches TavilySearch / TavilyExtract / TavilyCrawl.
 *
 * GATEWAY-ONLY: 100% via BizCity_Search_Client → bizcity-llm-router.
 * NEVER calls Tavily directly. See PHASE-0-RULE-GATEWAY-ONLY.md (R-GW-1).
 */
defined( 'ABSPATH' ) || exit;

final class BizCity_Research_Tool_Router {

    public static function get_type( string $tool_name ): string {
        $name = strtolower( $tool_name );
        if ( strpos( $name, 'extract' ) !== false ) return 'extract';
        if ( strpos( $name, 'crawl' )   !== false ) return 'crawl';
        return 'search';
    }

    /**
     * Execute one tool call. Always returns array with 'success' key.
     * Return shape: { success: bool, results: array, error?: string }
     */
    public static function call( string $tool_name, array $input ): array {
        if ( ! class_exists( 'BizCity_Search_Client' ) ) {
            return [ 'success' => false, 'results' => [], 'error' => 'BizCity_Search_Client not available — gateway client missing.' ];
        }
        $client = BizCity_Search_Client::instance();
        if ( ! $client->is_ready() ) {
            return [ 'success' => false, 'results' => [], 'error' => 'BizCity API key chưa được cấu hình (gateway).' ];
        }

        $type = self::get_type( $tool_name );

        switch ( $type ) {
            case 'search': {
                $query = (string) ( $input['query'] ?? '' );
                if ( $query === '' ) {
                    return [ 'success' => false, 'results' => [], 'error' => 'Empty query' ];
                }
                $options = [ 'include_raw_content' => false ];
                if ( ! empty( $input['topic'] ) )           $options['topic']           = (string) $input['topic'];
                if ( ! empty( $input['search_depth'] ) )    $options['search_depth']    = (string) $input['search_depth'];
                if ( ! empty( $input['include_domains'] ) ) $options['include_domains'] = (array)  $input['include_domains'];
                if ( ! empty( $input['exclude_domains'] ) ) $options['exclude_domains'] = (array)  $input['exclude_domains'];
                $max_results = isset( $input['max_results'] ) ? (int) $input['max_results'] : 6;

                $res = $client->search( $query, $max_results, $options );
                if ( is_wp_error( $res ) ) {
                    return [ 'success' => false, 'results' => [], 'error' => $res->get_error_message() ];
                }
                return [ 'success' => true, 'results' => $res ];
            }

            case 'extract': {
                $urls = $input['urls'] ?? ( isset( $input['url'] ) ? [ $input['url'] ] : [] );
                if ( empty( $urls ) ) {
                    return [ 'success' => false, 'results' => [], 'error' => 'No URLs provided' ];
                }
                $res = $client->extract( (array) $urls );
                if ( is_wp_error( $res ) ) {
                    return [ 'success' => false, 'results' => [], 'error' => $res->get_error_message() ];
                }
                return [ 'success' => true, 'results' => $res ];
            }

            case 'crawl': {
                $url = (string) ( $input['url'] ?? '' );
                if ( $url === '' ) {
                    return [ 'success' => false, 'results' => [], 'error' => 'No URL provided' ];
                }
                $limit = isset( $input['limit'] ) ? (int) $input['limit'] : 15;
                $res = $client->crawl( $url, [ 'limit' => $limit ] );
                if ( is_wp_error( $res ) ) {
                    return [ 'success' => false, 'results' => [], 'error' => $res->get_error_message() ];
                }
                return [ 'success' => true, 'results' => $res ];
            }
        }
        return [ 'success' => false, 'results' => [], 'error' => 'Unknown tool: ' . $tool_name ];
    }
}
