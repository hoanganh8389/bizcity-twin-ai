<?php
/**
 * TwinBrain — Web Products Engine.
 *
 * Stage 2.5 vertical engine for `web_mode=products`.
 * Delegates business logic to shared resolver service so Ask Brain and
 * Automation Zalo blocks stay in one contract.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Products {

	private static $instance = null;

	public static function instance(): self {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - singleton products web engine.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - no-op constructor.
	}

	/**
	 * @param string $trace_id
	 * @param string $query
	 * @param array  $opts
	 * @return array<string,mixed>
	 */
	public function run( string $trace_id, string $query, array $opts = array() ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - run products mode via shared resolver.
		$turn_start = microtime( true );
		$query      = trim( $query );

		$row = array(
			'mode'           => 'products',
			'trace_id'       => $trace_id,
			'query'          => $query,
			'label'          => 'Products Search',
			'intent'         => 'product_lookup',
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - expose intent-step detected list on engine row.
			'detected_products' => array(),
			'detected_count' => 0,
			'detected_md'    => '',
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — include BOQ/sheet handoff metadata in web-mode row contract.
			'missing_constraints' => array(),
			'sheet_recommended'  => false,
			'sheet_seed'         => array(),
			'sheet_handoff'      => array(),
			'need_items'     => array(),
			'matched'        => array(),
			'gaps'           => array(),
			'matched_count'  => 0,
			'gap_count'      => 0,
			'answer_md'      => '',
			'catalog_md'     => '',
			'gaps_md'        => '',
			// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - expose canonical source block contract in web row.
			'source_of_truth_links'      => array(),
			'source_of_truth_links_json' => '[]',
			'source_block_md'            => '',
			'internal_link_count'        => 0,
			'public_link_count'          => 0,
			'citations'      => array(),
			'citation_count' => 0,
			'tokens'         => 0,
			'ms'             => 0,
			'error'          => '',
			'_degraded'      => '',
			'stance'         => 'unknown',
			'confidence'     => 0.0,
		);

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		if ( ! class_exists( 'BizCity_TwinBrain_Product_Resolver_Service' ) ) {
			$row['error'] = 'resolver_missing';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$service = BizCity_TwinBrain_Product_Resolver_Service::instance();
		$res = $service->resolve_by_query( $query, array(
			'trace_id'         => $trace_id,
			'user_id'          => (int) ( $opts['user_id'] ?? get_current_user_id() ),
			'session_id'       => (string) ( $opts['session_id'] ?? '' ),
			'intent_hint'      => isset( $opts['intent_hint'] ) ? (string) $opts['intent_hint'] : '',
			'want_enrichment'  => ! isset( $opts['want_enrichment'] ) || ! empty( $opts['want_enrichment'] ),
			'max_results'      => max( 1, min( 20, (int) ( $opts['max'] ?? $opts['max_results'] ?? 10 ) ) ),
			'max_items'        => max( 1, min( 20, (int) ( $opts['max_items'] ?? 15 ) ) ),
			'surface'          => 'ask_brain_products',
			'source_marker'    => isset( $opts['source_marker'] ) ? (string) $opts['source_marker'] : 'twinbrain_chat',
		) );

		if ( empty( $res['success'] ) ) {
			$row['error'] = (string) ( $res['_degraded'] ?? 'products_failed' );
			if ( $row['error'] === '' ) {
				$row['error'] = 'products_failed';
			}
			$row['answer_md'] = (string) ( $res['message'] ?? '' );
			$row['ms']        = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$matched = isset( $res['matched'] ) && is_array( $res['matched'] ) ? $res['matched'] : array();
		$gaps    = isset( $res['gaps'] ) && is_array( $res['gaps'] ) ? $res['gaps'] : array();
		$tokens  = isset( $res['citations'] ) && is_array( $res['citations'] ) ? $res['citations'] : array();

		$row['intent']         = (string) ( $res['intent'] ?? 'product_lookup' );
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - forward detected products contract to TwinChat/TwinWeb payload.
		$row['detected_products'] = isset( $res['detected_products'] ) && is_array( $res['detected_products'] ) ? $res['detected_products'] : array();
		$row['detected_count'] = (int) ( $res['detected_count'] ?? count( $row['detected_products'] ) );
		$row['detected_md']    = (string) ( $res['detected_md'] ?? '' );
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — forward resolver metadata for UI hints and downstream automation.
		$row['missing_constraints'] = isset( $res['missing_constraints'] ) && is_array( $res['missing_constraints'] ) ? $res['missing_constraints'] : array();
		$row['sheet_recommended']  = ! empty( $res['sheet_recommended'] );
		$row['sheet_seed']         = isset( $res['sheet_seed'] ) && is_array( $res['sheet_seed'] ) ? $res['sheet_seed'] : array();
		$row['sheet_handoff']      = isset( $res['sheet_handoff'] ) && is_array( $res['sheet_handoff'] ) ? $res['sheet_handoff'] : array();
		$row['need_items']     = isset( $res['need_items'] ) && is_array( $res['need_items'] ) ? $res['need_items'] : array();
		$row['matched']        = $matched;
		$row['gaps']           = $gaps;
		$row['matched_count']  = (int) ( $res['matched_count'] ?? count( $matched ) );
		$row['gap_count']      = (int) ( $res['gap_count'] ?? count( $gaps ) );
		$row['answer_md']      = (string) ( $res['final_answer_md'] ?? '' );
		$row['catalog_md']     = (string) ( $res['catalog_md'] ?? '' );
		$row['gaps_md']        = (string) ( $res['gaps_md'] ?? '' );
		$row['source_of_truth_links'] = isset( $res['source_of_truth_links'] ) && is_array( $res['source_of_truth_links'] )
			? $res['source_of_truth_links']
			: array();
		$row['source_of_truth_links_json'] = isset( $res['source_of_truth_links_json'] )
			? (string) $res['source_of_truth_links_json']
			: (string) wp_json_encode( $row['source_of_truth_links'], JSON_UNESCAPED_UNICODE );
		$row['source_block_md'] = (string) ( $res['source_block_md'] ?? '' );
		$row['internal_link_count'] = (int) ( $res['internal_link_count'] ?? 0 );
		$row['public_link_count'] = (int) ( $res['public_link_count'] ?? 0 );
		$row['_degraded']      = (string) ( $res['_degraded'] ?? '' );
		$row['citations']      = $this->normalize_citations( $tokens, $matched, $gaps );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0 ? min( 1.0, 0.4 + 0.08 * $row['citation_count'] ) : 0.35;
		$row['stance']         = $row['citation_count'] > 0 ? 'conditional' : 'unknown';
		$row['ms']             = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		if ( $row['_degraded'] !== '' ) {
			$row['error'] = 'degraded:' . $row['_degraded'];
		}

		return $row;
	}

	/**
	 * @param array<int,string>                  $tokens
	 * @param array<int,array<string,mixed>>     $matched
	 * @param array<int,array<string,mixed>>     $gaps
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_citations( array $tokens, array $matched, array $gaps ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - normalize prod/web tokens to FE-friendly citation objects.
		$out = array();

		$product_by_id = array();
		foreach ( $matched as $row ) {
			$p = isset( $row['product'] ) && is_array( $row['product'] ) ? $row['product'] : array();
			$pid = (int) ( $p['id'] ?? 0 );
			if ( $pid > 0 ) {
				$product_by_id[ $pid ] = $p;
			}
		}

		$web_by_url = array();
		foreach ( $gaps as $row ) {
			$cits = isset( $row['citations'] ) && is_array( $row['citations'] ) ? $row['citations'] : array();
			foreach ( $cits as $token ) {
				if ( preg_match( '/^\[web:(\d+)#(.+)\]$/', (string) $token, $m ) ) {
					$url = trim( (string) $m[2] );
					if ( $url !== '' ) {
						$web_by_url[ $url ] = array(
							'title' => (string) ( $row['web_suggestion'] ?? '' ),
							'host'  => $this->host_of( $url ),
						);
					}
				}
			}
		}

		foreach ( $tokens as $token ) {
			$token = (string) $token;
			if ( preg_match( '/^\[prod:(\d+)\]$/', $token, $m ) ) {
				$pid = (int) $m[1];
				$p   = isset( $product_by_id[ $pid ] ) ? $product_by_id[ $pid ] : array();
				$out[] = array(
					'token'   => $token,
					'kind'    => 'prod',
					// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — canonical Super-MRO fallback label.
					'label'   => (string) ( $p['name'] ?? ( 'Super-MRO #' . $pid ) ),
					'url'     => (string) ( $p['permalink'] ?? '' ),
					'price'   => (string) ( $p['price'] ?? '' ),
					'stock'   => (string) ( $p['stock_status'] ?? '' ),
					'host'    => '',
				);
				continue;
			}

			if ( preg_match( '/^\[web:(\d+)#(.+)\]$/', $token, $m ) ) {
				$url  = trim( (string) $m[2] );
				$meta = isset( $web_by_url[ $url ] ) ? $web_by_url[ $url ] : array();
				$out[] = array(
					'token'   => $token,
					'kind'    => 'web',
					'label'   => (string) ( $meta['title'] ?? $url ),
					'url'     => $url,
					'price'   => '',
					'stock'   => '',
					'host'    => (string) ( $meta['host'] ?? $this->host_of( $url ) ),
				);
				continue;
			}

			if ( preg_match( '/^\[sheet:S#(\d+)\]$/i', $token, $m ) ) {
				// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — include auto sheet token in normalized citation list.
				$sheet_id = (int) $m[1];
				$out[] = array(
					'token'   => $token,
					'kind'    => 'sheet',
					'label'   => 'Sheet #' . $sheet_id,
					'url'     => '',
					'price'   => '',
					'stock'   => '',
					'host'    => '',
				);
			}
		}

		return $out;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
	}
}
