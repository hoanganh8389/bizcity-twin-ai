<?php
/**
 * Bizcity Twin AI — TwinChat Search Proxy (R-GW-8)
 *
 * Client-side REST proxy fronting the gateway's `search/router/v1/*` namespace
 * (Tavily). Per PHASE-0-RULE-GATEWAY-ONLY R-GW-8, FE must NEVER call
 * `https://CLIENT-HOST/wp-json/search/router/v1/*` — that namespace lives only
 * on the BizCity gateway (bizcity.vn) and 99.999% of client sites do not have
 * `bizcity-llm-router` installed. FE talks to this same-origin proxy, which
 * delegates server-side to `BizCity_Search_Client::instance()->search()` and
 * `extract()`.
 *
 * Routes:
 *   POST /wp-json/bizcity-twinchat/v1/search   { query, max_results?, search_depth?, include_raw_content?, include_answer?, topic?, include_domains?, exclude_domains? }
 *   POST /wp-json/bizcity-twinchat/v1/extract  { urls[] }
 *
 * Failure policy: ALWAYS 200 — upstream errors surface as
 * `{ success:false, results:[], error, _degraded:{ code, message } }` so the FE
 * can render a single happy path and stop retry loops.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since      PHASE-0.41 L7 (2026-05-21)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Search_Proxy {

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes(): void {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' )
			? BIZCITY_TWINCHAT_REST_NS
			: 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/search', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_search' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );

		register_rest_route( $ns, '/extract', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_extract' ],
			'permission_callback' => [ $this, 'check_logged_in' ],
		] );
	}

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Login required.', [ 'status' => 401 ] );
		}
		return true;
	}

	public function handle_search( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}

		$query = isset( $body['query'] ) ? trim( (string) $body['query'] ) : '';
		if ( $query === '' ) {
			return new WP_REST_Response( [
				'success' => false,
				'results' => [],
				'query'   => '',
				'error'   => 'Query is empty.',
			], 200 );
		}

		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_REST_Response( $this->fail( $query, 'search_client_missing', 'BizCity_Search_Client not loaded.' ), 200 );
		}

		$max_results = isset( $body['max_results'] ) ? (int) $body['max_results'] : 5;
		$options = [
			'search_depth'        => isset( $body['search_depth'] ) ? (string) $body['search_depth'] : 'basic',
			'include_raw_content' => ! empty( $body['include_raw_content'] ),
			'include_answer'      => ! empty( $body['include_answer'] ),
		];
		if ( ! empty( $body['topic'] ) ) {
			$options['topic'] = (string) $body['topic'];
		}
		if ( ! empty( $body['include_domains'] ) && is_array( $body['include_domains'] ) ) {
			$options['include_domains'] = array_map( 'strval', $body['include_domains'] );
		}
		if ( ! empty( $body['exclude_domains'] ) && is_array( $body['exclude_domains'] ) ) {
			$options['exclude_domains'] = array_map( 'strval', $body['exclude_domains'] );
		}

		$client = BizCity_Search_Client::instance();
		$result = $client->search( $query, $max_results, $options );

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			return new WP_REST_Response(
				$this->fail( $query, $result->get_error_code(), $result->get_error_message(), $status ),
				200
			);
		}

		// `BizCity_Search_Client::search()` returns the normalized results array
		// (each item: { url, title, excerpt, content, score, published_at, domain }).
		// FE TavilyResult uses `published_date` — re-key for FE compatibility.
		$normalized = [];
		foreach ( (array) $result as $item ) {
			$normalized[] = [
				'title'          => (string) ( $item['title']        ?? '' ),
				'url'            => (string) ( $item['url']          ?? '' ),
				'content'        => (string) ( $item['content']      ?? $item['excerpt'] ?? '' ),
				'score'          => (float)  ( $item['score']        ?? 0.0 ),
				'published_date' => (string) ( $item['published_at'] ?? '' ),
			];
		}

		return new WP_REST_Response( [
			'success' => true,
			'results' => $normalized,
			'query'   => $query,
		], 200 );
	}

	public function handle_extract( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $request->get_params();
		}
		$urls = isset( $body['urls'] ) && is_array( $body['urls'] ) ? array_map( 'strval', $body['urls'] ) : [];
		if ( empty( $urls ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'results' => [],
				'error'   => 'No URLs provided.',
			], 200 );
		}
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_REST_Response( [
				'success'   => false,
				'results'   => [],
				'error'     => 'Search client missing.',
				'_degraded' => [ 'code' => 'search_client_missing', 'message' => 'BizCity_Search_Client not loaded.' ],
			], 200 );
		}
		$client = BizCity_Search_Client::instance();
		$result = $client->extract( $urls );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [
				'success'   => false,
				'results'   => [],
				'error'     => $result->get_error_message(),
				'_degraded' => [
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
			], 200 );
		}
		return new WP_REST_Response( [
			'success' => true,
			'results' => is_array( $result ) ? $result : [],
		], 200 );
	}

	private function fail( string $query, string $code, string $message, int $upstream_status = 0 ): array {
		return [
			'success'   => false,
			'results'   => [],
			'query'     => $query,
			'error'     => $message,
			'_degraded' => [
				'code'            => $code,
				'message'         => $message,
				'upstream_status' => $upstream_status,
				'reason'          => 'gateway_unreachable_or_unauthorized',
			],
		];
	}
}
