<?php
/**
 * Bizcity Twin AI — KG_Reranker
 *
 * LLM-based relation reranker (single-pass, port from vector-graph-rag).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Reranker {

	const MODEL_OPTION  = 'bizcity_kg_rerank_model';
	const DEFAULT_MODEL = 'gpt-4o-mini';

	/** Sprint 4.8b — Strategy A: route rerank via bizcity-llm-router. */
	const ROUTER_ENDPOINT = '/wp-json/llm/router/v1/rerank';
	const CACHE_TTL       = 300; // 5 min

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Rerank candidate relations using an LLM.
	 *
	 * @param string $question
	 * @param array  $candidates each: ['id', 'relation_text']
	 * @param int    $top_k
	 * @return int[]  IDs in selected order. Falls back to original order on failure.
	 */
	public function rerank( $question, array $candidates, $top_k = 5 ) {
		if ( empty( $candidates ) ) {
			return [];
		}
		$top_k = max( 1, (int) $top_k );

		// Strategy A — try LLM Router /rerank first (multisite-friendly, charged via Smart Gateway).
		$router_ids = $this->rerank_via_router( $question, $candidates, $top_k );
		if ( null !== $router_ids ) {
			return $router_ids;
		}

		// Strategy B — fallback to BizCity_LLM_Client::chat() (việc dùng /rerank
		// endpoint có thể thiếu trên router phiên bản cũ; chat() luôn có).
		// PHASE-0-RULE-SMART-GATEWAY-MIGRATION: KHÔNG được gọi thẳng api.openai.com.
		$tpl = @file_get_contents( BIZCITY_KG_HUB_PROMPTS . 'rerank-relations.txt' );
		if ( ! $tpl || ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->fallback( $candidates, $top_k );
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return $this->fallback( $candidates, $top_k );
		}

		$lines = [];
		foreach ( $candidates as $c ) {
			$lines[] = sprintf( '[%d] %s', (int) $c['id'], $c['relation_text'] ?? '' );
		}
		$prompt = strtr( $tpl, [
			'{{QUESTION}}'   => $question,
			'{{CANDIDATES}}' => implode( "\n", $lines ),
			'{{TOP_K}}'      => (string) $top_k,
		] );

		$model = get_option( self::MODEL_OPTION, '' );
		if ( $model === '' ) {
			$model = $client->get_model( 'rerank' ) ?: self::DEFAULT_MODEL;
		}

		$resp = $client->chat( [
			[ 'role' => 'system', 'content' => 'You output strict JSON arrays only.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		], [
			'purpose'     => 'rerank',
			'model'       => $model,
			'temperature' => 0.0,
			'max_tokens'  => 200,
			'timeout'     => 45,
			'extra_body'  => [
				'response_format' => [ 'type' => 'json_object' ],
			],
		] );

		if ( empty( $resp['success'] ) ) {
			return $this->fallback( $candidates, $top_k );
		}
		$raw = trim( (string) ( $resp['message'] ?? '' ) );
		// Strip optional code fences.
		$raw = preg_replace( '/^```[a-z]*|```$/m', '', $raw );
		$ids = json_decode( $raw, true );
		// Some prompts may return an object like {"ids":[...]} — unwrap.
		if ( is_array( $ids ) && isset( $ids['ids'] ) && is_array( $ids['ids'] ) ) {
			$ids = $ids['ids'];
		}
		if ( ! is_array( $ids ) ) {
			return $this->fallback( $candidates, $top_k );
		}
		$valid_ids = array_map( 'intval', $ids );
		$known     = array_column( $candidates, 'id' );
		$valid_ids = array_values( array_intersect( $valid_ids, $known ) );
		return array_slice( $valid_ids, 0, $top_k );
	}

	/**
	 * Sprint 4.8b — Strategy A: rerank via `bizcity-llm-router` /rerank endpoint.
	 *
	 * @return int[]|null  Returns selected IDs in order, or NULL when router call
	 *                     should be skipped (no client/key) so caller falls back.
	 */
	private function rerank_via_router( string $question, array $candidates, int $top_k ): ?array {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return null;
		}
		$client  = BizCity_LLM_Client::instance();
		$api_key = method_exists( $client, 'get_api_key' ) ? (string) $client->get_api_key() : '';
		$gateway = method_exists( $client, 'get_gateway_url' ) ? (string) $client->get_gateway_url() : '';
		if ( '' === $api_key || '' === $gateway ) {
			return null;
		}

		$payload_candidates = [];
		foreach ( $candidates as $c ) {
			$payload_candidates[] = [
				'id'   => (string) ( $c['id'] ?? '' ),
				'text' => (string) ( $c['relation_text'] ?? $c['text'] ?? '' ),
			];
		}

		// Phase 0.12 Wave C — attach trace_meta so the router can correlate its
		// piggybacked twin_events with the user turn currently in flight.
		$trace_meta = class_exists( 'BizCity_Twin_Router_Event_Ingester' )
			? BizCity_Twin_Router_Event_Ingester::build_trace_meta()
			: [];

		$body = wp_json_encode( array_merge( [
			'query'       => $question,
			'candidates'  => $payload_candidates,
			'top_k'       => $top_k,
			'site_url'    => home_url(),
			'plugin_name' => 'bizcity-twin-ai/kg-reranker',
		], $trace_meta ) );

		$cache_key = 'bc_kgrr_' . md5( $body );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$resp = wp_remote_post( rtrim( $gateway, '/' ) . self::ROUTER_ENDPOINT, [
			'timeout' => 30,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			],
			'body'    => $body,
		] );
		if ( is_wp_error( $resp ) ) {
			error_log( '[KG_Reranker] router rerank failed: ' . $resp->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );

		// Phase 0.12 Wave C — ingest any piggybacked twin_events so they appear
		// in the local event stream and get forwarded over the live SSE.
		if ( class_exists( 'BizCity_Twin_Router_Event_Ingester' ) ) {
			BizCity_Twin_Router_Event_Ingester::ingest_response_body( $json );
		}

		if ( $code < 200 || $code >= 300 || empty( $json['success'] ) || ! is_array( $json['results'] ?? null ) ) {
			error_log( '[KG_Reranker] router rerank bad response code=' . $code );
			return null;
		}

		$known = array_map( 'intval', array_column( $candidates, 'id' ) );
		$out   = [];
		foreach ( $json['results'] as $r ) {
			$id = (int) ( $r['id'] ?? 0 );
			if ( $id > 0 && in_array( $id, $known, true ) && ! in_array( $id, $out, true ) ) {
				$out[] = $id;
			}
		}
		$out = array_slice( $out, 0, $top_k );

		// Cache only non-empty responses.
		if ( ! empty( $out ) ) {
			set_transient( $cache_key, $out, self::CACHE_TTL );
		}
		return $out;
	}

	private function fallback( array $candidates, $top_k ) {
		// Already pre-sorted by cosine score upstream.
		return array_slice( array_map( static fn( $c ) => (int) $c['id'], $candidates ), 0, $top_k );
	}
}
