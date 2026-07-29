<?php
/**
 * TwinBrain Product Resolver Service.
 *
 * Shared service for Ask Brain products mode and automation product blocks.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-15
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Product_Resolver_Service {

	const INTENT_LOOKUP        = 'product_lookup';
	const INTENT_STOCK_PRICE   = 'stock_price';
	const INTENT_PRODUCT_LEARN = 'product_learn';
	const INTENT_NEED_SOLUTION = 'need_solution';

	private static $instance = null;

	public static function instance(): self {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - singleton shared resolver across surfaces.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - no-op constructor.
	}

	/**
	 * Resolve product answer by user query.
	 *
	 * @param string $query
	 * @param array  $opts
	 * @return array<string,mixed>
	 */
	public function resolve_by_query( string $query, array $opts = array() ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - central Woo-first + web-fallback resolver contract.
		$turn_started = microtime( true );
		$query = trim( $query );
		if ( $query === '' ) {
			return array(
				'success'   => false,
				'intent'    => self::INTENT_LOOKUP,
				'query'     => '',
				'detected_products' => array(),
				'detected_count'    => 0,
				'missing_constraints' => array(),
				'sheet_recommended'  => false,
				'sheet_seed'         => array(),
				'sheet_handoff'      => $this->default_sheet_handoff( 'not_needed' ),
				'matched'   => array(),
				'gaps'      => array(),
				'citations' => array(),
				// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - keep fail payload parity with source-of-truth contract.
				'source_of_truth_links'      => array(),
				'source_of_truth_links_json' => '[]',
				'source_block_md'            => '',
				'internal_link_count'        => 0,
				'public_link_count'          => 0,
				'_degraded' => 'invalid_param',
				'message'   => 'Noi dung cau hoi san pham dang rong.',
			);
		}
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — reject known non-MRO retail domains before any Woo/web call.
		if ( ! $this->is_super_mro_term( $query ) ) {
			return array(
				'success'           => false,
				'intent'            => self::INTENT_LOOKUP,
				'query'             => $query,
				'detected_products' => array(),
				'detected_count'    => 0,
				'missing_constraints' => array(),
				'sheet_recommended'  => false,
				'sheet_seed'         => array(),
				'sheet_handoff'      => $this->default_sheet_handoff( 'not_needed' ),
				'matched'           => array(),
				'gaps'              => array(),
				'citations'         => array(),
				// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - keep fail payload parity with source-of-truth contract.
				'source_of_truth_links'      => array(),
				'source_of_truth_links_json' => '[]',
				'source_block_md'            => '',
				'internal_link_count'        => 0,
				'public_link_count'          => 0,
				'_degraded'         => 'out_of_scope',
				'code'              => 'invalid_param',
				'message'           => 'Super-MRO chỉ hỗ trợ vật tư công nghiệp, điện, công cụ và xây dựng.',
				'hint'              => 'Chọn chế độ khác cho mỹ phẩm, thời trang, thực phẩm hoặc hàng tiêu dùng.',
				'help_code'         => 'invalid_param_generic',
			);
		}

		$provider        = BizCity_TwinBrain_Product_Provider::instance();
		$composer        = BizCity_TwinBrain_Product_Composer::instance();
		$intent_hint     = isset( $opts['intent_hint'] ) ? (string) $opts['intent_hint'] : '';
		$want_enrichment = ! isset( $opts['want_enrichment'] ) || ! empty( $opts['want_enrichment'] );
		$max_results     = max( 1, min( 20, (int) ( $opts['max_results'] ?? 10 ) ) );
		$max_items       = max( 1, min( 20, (int) ( $opts['max_items'] ?? 15 ) ) );
		$emit            = isset( $opts['sse'] ) && is_callable( $opts['sse'] ) ? $opts['sse'] : null;
		$source_marker   = isset( $opts['source_marker'] ) ? (string) $opts['source_marker'] : 'twinbrain_chat';
		$trace_id        = isset( $opts['trace_id'] ) ? trim( (string) $opts['trace_id'] ) : '';
		if ( $trace_id === '' ) {
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - synthesize deterministic trace_id fallback for non-TwinBrain callers.
			$trace_id = 'tbprod-' . wp_generate_uuid4();
		}
		$event_user_id    = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : (int) get_current_user_id();
		$event_session_id = isset( $opts['session_id'] ) ? (string) $opts['session_id'] : '';

		$intent = $this->classify_intent( $query, $intent_hint );
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - extract detected products for all intents before resolver loop.
		$detected_products = $this->extract_detected_products( $query, $intent, $max_items );
		if ( empty( $detected_products ) ) {
			$detected_products = array(
				array(
					'term'       => $query,
					'aliases'    => array( $query ),
					'group'      => 'general',
					'priority'   => 'must',
					'confidence' => 0.2,
					'source'     => 'fallback_query',
				),
			);
		}

		$need_entries = $this->build_need_entries( $detected_products, $query, $max_items );
		$need_items   = $this->extract_need_terms( $need_entries );
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — expose missing constraints and BOQ/sheet hint from resolver core.
		$missing_constraints = $this->detect_missing_constraints( $query, $intent );
		$sheet_recommended   = $this->is_boq_or_sheet_query( $query, $intent );
		$keywords     = array_slice( $need_items, 0, 8 );
		$this->emit_step( $emit, 'web_research_started', array(
			'trace_id'      => $trace_id,
			'mode'          => 'products',
			'query'         => $query,
			'intent'        => $intent,
			'source_marker' => $source_marker,
		) );
		$this->emit_step( $emit, 'web_react_step', array(
			'trace_id'            => $trace_id,
			'mode'                => 'products',
			'iter'                => 1,
			'action'              => 'intent_prompt',
			'action_input'        => $query,
			'observation_summary' => 'detected=' . count( $detected_products ),
		) );
		$this->emit_product_timeline_event( 'product_research_started', array(
			'trace_id'      => $trace_id,
			'query'         => $query,
			'intent'        => $intent,
			'source_marker' => $source_marker,
		), $event_user_id, $event_session_id );
		$this->emit_product_timeline_event( 'product_intent_detected', array(
			'trace_id'        => $trace_id,
			'intent'          => $intent,
			'keywords'        => $keywords,
			'detected_products' => $detected_products,
			'missing_constraints' => $missing_constraints,
			'output_format'   => $sheet_recommended ? 'sheet' : 'chat',
			'want_enrichment' => (bool) $want_enrichment,
			'source'          => $intent_hint !== '' ? 'intent_hint' : 'classifier',
		), $event_user_id, $event_session_id );

		if ( $intent === self::INTENT_NEED_SOLUTION ) {
			$this->emit_step( $emit, 'web_react_step', array(
				'trace_id'            => $trace_id,
				'mode'                => 'products',
				'iter'                => 2,
				'action'              => 'decompose_needs',
				'action_input'        => $query,
				'observation_summary' => 'items=' . count( $need_entries ),
			) );
			$this->emit_product_timeline_event( 'product_needs_decomposed', array(
				'trace_id' => $trace_id,
				'count'    => count( $need_items ),
				'items'    => $need_items,
			), $event_user_id, $event_session_id );
		}

		$degraded_reason = '';
		if ( ! $provider->is_ready() ) {
			$degraded_reason = 'woo_inactive';
		}

		$matched    = array();
		$gaps       = array();
		$seen_ids   = array();
		$iter       = 3;
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-2 - bound Woo search fan-out for alias-based lookup.
		$woo_query_budget = max( 1, min( 20, (int) ( $opts['woo_query_budget'] ?? 8 ) ) );
		$woo_query_count  = 0;
		$woo_search_cache = array();

		foreach ( $need_entries as $entry ) {
			$need = is_array( $entry ) ? trim( (string) ( $entry['term'] ?? '' ) ) : '';
			if ( $need === '' ) {
				continue;
			}
			$aliases = is_array( $entry ) && isset( $entry['aliases'] ) && is_array( $entry['aliases'] )
				? $entry['aliases']
				: array( $need );

			$results = array();
			$aliases_tried = array();
			if ( $provider->is_ready() ) {
				$limit   = $intent === self::INTENT_NEED_SOLUTION ? 3 : $max_results;
				$search_bundle = $this->search_need_aliases(
					$provider,
					$aliases,
					$limit,
					$woo_query_budget,
					$woo_query_count,
					$woo_search_cache
				);
				$results       = isset( $search_bundle['results'] ) && is_array( $search_bundle['results'] )
					? $this->filter_super_mro_results( $search_bundle['results'] )
					: array();
				$aliases_tried = isset( $search_bundle['aliases_tried'] ) && is_array( $search_bundle['aliases_tried'] )
					? $search_bundle['aliases_tried']
					: array();
			}

			$this->emit_step( $emit, 'web_react_step', array(
				'trace_id'            => $trace_id,
				'mode'                => 'products',
				'iter'                => $iter,
				'action'              => 'woo_search',
				'action_input'        => $need,
				'observation_summary' => 'matches=' . count( $results ) . '; aliases=' . count( $aliases_tried ),
				'aliases'             => $aliases_tried,
			) );
			$this->emit_product_timeline_event( 'product_react_step', array(
				'trace_id'            => $trace_id,
				'iter'                => $iter,
				'action'              => 'woo_search',
				'action_input'        => $need,
				'observation_summary' => 'matches=' . count( $results ) . '; aliases=' . count( $aliases_tried ),
				'matched_ids'         => array_values( array_filter( array_map( static function ( $row ) {
					return is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
				}, $results ) ) ),
				'web_hits'            => 0,
				'aliases'             => $aliases_tried,
			), $event_user_id, $event_session_id );
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - mirror Stage 2 search checkpoint for timeline parity.
			$this->emit_step( $emit, 'web_search_done', array(
				'trace_id' => $trace_id,
				'mode'    => 'products',
				'iter'    => $iter,
				'query'   => $need,
				'source'  => 'woo',
				'count'   => count( $results ),
				'results' => $this->map_woo_result_preview( $results ),
			) );
			$iter++;

			if ( ! empty( $results ) ) {
				if ( $intent === self::INTENT_NEED_SOLUTION ) {
					$first = $results[0];
					$pid   = (int) ( $first['id'] ?? 0 );
					if ( $pid > 0 && ! isset( $seen_ids[ $pid ] ) ) {
						$seen_ids[ $pid ] = true;
						$matched[] = array(
							'need'      => $need,
							'product'   => $first,
							'citation'  => '[prod:' . $pid . ']',
						);
					}
				} else {
					foreach ( $results as $row ) {
						$pid = (int) ( $row['id'] ?? 0 );
						if ( $pid <= 0 || isset( $seen_ids[ $pid ] ) ) {
							continue;
						}
						$seen_ids[ $pid ] = true;

						if ( $intent === self::INTENT_PRODUCT_LEARN ) {
							$detail = $provider->detail( $pid );
							if ( ! empty( $detail ) ) {
								$row = array_merge( $row, $detail );
								// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - detail checkpoint for product_learn mode.
								$this->emit_step( $emit, 'web_extract_done', array(
									'trace_id'   => $trace_id,
									'mode'       => 'products',
									'iter'       => $iter,
									'source'     => 'woo_detail',
									'product_id' => $pid,
								) );
							}
						}

						$matched[] = array(
							'need'      => $need,
							'product'   => $row,
							'citation'  => '[prod:' . $pid . ']',
						);
						if ( count( $matched ) >= $max_results ) {
							break;
						}
					}
				}
			}

			if ( empty( $results ) ) {
				$enrich = $this->web_enrich_need( $need, $want_enrichment, $emit, $iter, $trace_id, $event_user_id, $event_session_id );
				// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - external enrichment checkpoint for no-Woo hits.
				$this->emit_step( $emit, 'web_extract_done', array(
					'trace_id'  => $trace_id,
					'mode'      => 'products',
					'iter'      => $iter,
					'source'    => 'web_enrich',
					'query'     => $need,
					'web_hits'  => count( $enrich['citations'] ),
					'degraded'  => (string) $enrich['degraded'],
				) );
				$iter++;
				if ( $enrich['degraded'] !== '' && $degraded_reason === '' ) {
					$degraded_reason = $enrich['degraded'];
				}
				$gaps[] = array(
					'need'           => $need,
					'reason'         => 'not_in_woo',
					'web_suggestion' => $enrich['suggestion'],
					'citations'      => $enrich['citations'],
				);
			}

			if ( $intent !== self::INTENT_NEED_SOLUTION && count( $matched ) >= $max_results ) {
				break;
			}
		}

		$resolve_ms = (int) round( ( microtime( true ) - $turn_started ) * 1000 );
		$citation_tokens = $this->collect_citation_tokens( $matched, $gaps );
		$sheet_seed      = $sheet_recommended ? $this->build_sheet_seed( $query, $need_entries, $matched ) : array();
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — SMRO-8 auto handoff: create/enrich BOQ sheet when recommended.
		$sheet_handoff   = $this->maybe_auto_sheet_handoff( $sheet_recommended, $sheet_seed, $opts, $trace_id );
		$sheet_token     = (string) ( $sheet_handoff['token'] ?? '' );
		if ( $sheet_token !== '' && ! in_array( $sheet_token, $citation_tokens, true ) ) {
			$citation_tokens[] = $sheet_token;
		}
		$composed        = $composer->compose(
			$query,
			$intent,
			$detected_products,
			$matched,
			$gaps,
			$degraded_reason,
			array(
				'missing_constraints' => $missing_constraints,
				'sheet_recommended'  => $sheet_recommended,
				'sheet_seed'         => $sheet_seed,
				'sheet_handoff'      => $sheet_handoff,
			)
		);

		$source_of_truth_links = array();
		$source_block_md       = '';
		$internal_link_count   = 0;
		$public_link_count     = 0;
		if ( class_exists( 'BizCity_TwinBrain_Product_Source_Layer' ) ) {
			// [2026-07-16 Johnny Chu] PHASE-TWB-PRODUCTS-SOURCE-LAYER - emit canonical source links and block in resolver contract.
			$source_layer         = BizCity_TwinBrain_Product_Source_Layer::instance();
			$source_of_truth_links = $source_layer->build_source_of_truth_links( $matched, $gaps, $query );
			$source_block_md      = $source_layer->build_source_block_md( $source_of_truth_links );
			$scope_counts         = $source_layer->count_scopes( $source_of_truth_links );
			$internal_link_count  = (int) ( $scope_counts['internal'] ?? 0 );
			$public_link_count    = (int) ( $scope_counts['public'] ?? 0 );
		}
		$source_of_truth_links_json = wp_json_encode( $source_of_truth_links, JSON_UNESCAPED_UNICODE );

		$this->emit_step( $emit, 'web_synthesize_done', array(
			'trace_id'      => $trace_id,
			'mode'          => 'products',
			'intent'        => $intent,
			'matched_count' => count( $matched ),
			'gap_count'     => count( $gaps ),
			'answer_md'     => (string) ( $composed['final_answer_md'] ?? '' ),
			'source_of_truth_links'      => $source_of_truth_links,
			'source_of_truth_links_json' => is_string( $source_of_truth_links_json ) ? $source_of_truth_links_json : '[]',
			'source_block_md'            => $source_block_md,
			'internal_link_count'        => $internal_link_count,
			'public_link_count'          => $public_link_count,
			'citation_count'=> count( $citation_tokens ),
			'tokens'        => 0,
			'ms'            => $resolve_ms,
		) );
		$this->emit_product_timeline_event( 'product_synthesize_done', array(
			'trace_id'      => $trace_id,
			'matched_count' => count( $matched ),
			'gap_count'     => count( $gaps ),
			'source_of_truth_links'      => $source_of_truth_links,
			'source_of_truth_links_json' => is_string( $source_of_truth_links_json ) ? $source_of_truth_links_json : '[]',
			'source_block_md'            => $source_block_md,
			'internal_link_count'        => $internal_link_count,
			'public_link_count'          => $public_link_count,
			'citation_count'=> count( $citation_tokens ),
			'tokens'        => 0,
			'ms'            => $resolve_ms,
		), $event_user_id, $event_session_id );

		return array(
			'success'         => true,
			'trace_id'        => $trace_id,
			'intent'          => $intent,
			'query'           => $query,
			'detected_products' => $detected_products,
			'detected_count'    => count( $detected_products ),
			'missing_constraints' => $missing_constraints,
			'sheet_recommended'  => $sheet_recommended,
			'sheet_seed'         => $sheet_seed,
			'sheet_handoff'      => $sheet_handoff,
			'need_items'      => $need_items,
			'need_count'      => count( $need_items ),
			'matched'         => $matched,
			'gaps'            => $gaps,
			'matched_count'   => count( $matched ),
			'gap_count'       => count( $gaps ),
			'detected_md'     => (string) ( $composed['detected_md'] ?? '' ),
			'catalog_md'      => (string) ( $composed['catalog_md'] ?? '' ),
			'gaps_md'         => (string) ( $composed['gaps_md'] ?? '' ),
			'final_answer_md' => (string) ( $composed['final_answer_md'] ?? '' ),
			'citations'       => $citation_tokens,
			'source_of_truth_links'      => $source_of_truth_links,
			'source_of_truth_links_json' => is_string( $source_of_truth_links_json ) ? $source_of_truth_links_json : '[]',
			'source_block_md'            => $source_block_md,
			'internal_link_count'        => $internal_link_count,
			'public_link_count'          => $public_link_count,
			'source_marker'   => $source_marker,
			'_degraded'       => $degraded_reason,
			'message'         => '',
		);
	}

	private function classify_intent( string $query, string $hint ): string {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — classify Super-MRO intent after Vietnamese accent normalization.
		$allowed = array(
			self::INTENT_LOOKUP,
			self::INTENT_STOCK_PRICE,
			self::INTENT_PRODUCT_LEARN,
			self::INTENT_NEED_SOLUTION,
		);
		if ( in_array( $hint, $allowed, true ) ) {
			return $hint;
		}

		$q = $this->normalize_term_for_compare( $query );
		if ( preg_match( '/(toi muon|minh muon|muon lap|can gi|tron bo|combo|setup|chuan bi|xay nha|xay dung|sua nha|lap dat|bao tri|bang gia|boq|bom|bo dung cu)/u', $q ) ) {
			return self::INTENT_NEED_SOLUTION;
		}
		if ( preg_match( '/(gia|bao nhieu|con hang|het hang|ton kho|instock|outofstock)/u', $q ) ) {
			return self::INTENT_STOCK_PRICE;
		}
		if ( preg_match( '/(cong dung|dung de|so sanh|review|khac nhau|uu diem|nhuoc diem)/u', $q ) ) {
			return self::INTENT_PRODUCT_LEARN;
		}
		return self::INTENT_LOOKUP;
	}

	/**
	 * @param string $query
	 * @param int    $max_items
	 * @return array<int,string>
	 */
	private function decompose_needs( string $query, int $max_items ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — deterministic Super-MRO work packages take precedence over LLM drift.
		$q = $this->normalize_term_for_compare( $query );
		if ( preg_match( '/(lap|gan|thay).*(den tran|den am tran|den op tran|downlight)|den tran/u', $q ) ) {
			return array_slice( array(
				'den led tran', 'driver den led', 'day dien', 'ong luon day dien', 'dau noi dien',
				'cong tac dien', 'aptomat bao ve', 'vit tac ke', 'bang keo dien', 'day rut',
				'but thu dien', 'dong ho do dien', 'may khoan', 'mui khoan', 'thang', 'bao ho lao dong'
			), 0, $max_items );
		}
		if ( mb_strpos( $q, 'xay nha' ) !== false || mb_strpos( $q, 'xay dung' ) !== false || mb_strpos( $q, 'sua nha' ) !== false ) {
			return array_slice( array(
				'xi mang', 'sat thep', 'cat xay', 'da xay dung', 'gach xay', 'chong tham',
				'day dien', 'ong nuoc', 'may tron be tong', 'gian giao', 'dung cu do', 'bao ho lao dong'
			), 0, $max_items );
		}

		$llm_items = $this->decompose_needs_by_llm( $query, $max_items );
		if ( ! empty( $llm_items ) ) {
			return $llm_items;
		}

		$raw = preg_split( '/,|\.|\;|\n| va | voi /u', $query );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( $item === '' ) {
				continue;
			}
			$out[] = $item;
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}
		if ( empty( $out ) ) {
			$out[] = $query;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $detected_products
	 * @param string                          $query
	 * @param int                             $max_items
	 * @return array<int,array<string,mixed>>
	 */
	private function build_need_entries( array $detected_products, string $query, int $max_items ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - build resolver entries from detected product list.
		$out = array();
		foreach ( $detected_products as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$term = trim( (string) ( $row['term'] ?? '' ) );
			if ( $term === '' ) {
				continue;
			}
			$aliases = isset( $row['aliases'] ) && is_array( $row['aliases'] ) ? $row['aliases'] : array();
			$out[] = array(
				'term'     => $term,
				'aliases'  => $this->normalize_aliases( $term, $aliases ),
				'group'    => (string) ( $row['group'] ?? '' ),
				'priority' => (string) ( $row['priority'] ?? 'must' ),
			);
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}

		if ( empty( $out ) ) {
			$out[] = array(
				'term'     => $query,
				'aliases'  => $this->normalize_aliases( $query, array() ),
				'group'    => 'general',
				'priority' => 'must',
			);
		}

		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $need_entries
	 * @return array<int,string>
	 */
	private function extract_need_terms( array $need_entries ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - flatten need term list for payload and compatibility fields.
		$out = array();
		foreach ( $need_entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$term = trim( (string) ( $entry['term'] ?? '' ) );
			if ( $term !== '' ) {
				$out[] = $term;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param BizCity_TwinBrain_Product_Provider $provider
	 * @param array<int,mixed>                   $aliases
	 * @param int                                $limit
	 * @param int                                $woo_query_budget
	 * @param int                                $woo_query_count
	 * @param array<string,array>                $woo_search_cache
	 * @return array{results:array<int,array<string,mixed>>,aliases_tried:array<int,string>,budget_exhausted:bool}
	 */
	private function search_need_aliases( BizCity_TwinBrain_Product_Provider $provider, array $aliases, int $limit, int $woo_query_budget, int &$woo_query_count, array &$woo_search_cache ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-2 - Woo-first search by normalized aliases, dedupe by product ID.
		$by_id         = array();
		$aliases_tried = array();
		$alias_seen    = array();

		foreach ( $aliases as $alias_raw ) {
			$alias = trim( (string) $alias_raw );
			if ( $alias === '' ) {
				continue;
			}

			$alias_key = $this->normalize_term_for_compare( $alias );
			if ( $alias_key === '' || isset( $alias_seen[ $alias_key ] ) ) {
				continue;
			}
			$alias_seen[ $alias_key ] = true;

			if ( $woo_query_count >= $woo_query_budget ) {
				break;
			}

			$woo_query_count++;
			$aliases_tried[] = $alias;

			$cache_key = $alias_key . '|' . $limit;
			$rows      = array();
			if ( isset( $woo_search_cache[ $cache_key ] ) && is_array( $woo_search_cache[ $cache_key ] ) ) {
				$rows = $woo_search_cache[ $cache_key ];
			} else {
				$rows = $provider->search( $alias, $limit );
				$woo_search_cache[ $cache_key ] = $rows;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$pid = (int) ( $row['id'] ?? 0 );
				if ( $pid <= 0 || isset( $by_id[ $pid ] ) ) {
					continue;
				}
				$by_id[ $pid ] = $row;
				if ( count( $by_id ) >= $limit ) {
					break;
				}
			}

			if ( count( $by_id ) >= $limit ) {
				break;
			}
		}

		return array(
			'results'          => array_values( $by_id ),
			'aliases_tried'    => $aliases_tried,
			'budget_exhausted' => ( $woo_query_count >= $woo_query_budget ),
		);
	}

	/**
	 * @param string $query
	 * @param string $intent
	 * @param int    $max_items
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_detected_products( string $query, string $intent, int $max_items ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - intent step must return explicit detected product list.
		$detected = $this->extract_detected_products_by_llm( $query, $intent, $max_items );
		if ( ! empty( $detected ) ) {
			return $detected;
		}
		return $this->extract_detected_products_deterministic( $query, $intent, $max_items );
	}

	/**
	 * @param string $query
	 * @param string $intent
	 * @param int    $max_items
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_detected_products_by_llm( string $query, string $intent, int $max_items ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — domain-grounded Super-MRO parser with a strong OpenAI model.
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array();
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return array();
		}

		$system = 'Ban la intent parser CHUYEN NGANH Super-MRO: vat tu cong nghiep, dien, chieu sang, co khi, dung cu, bao tri va vat lieu xay dung. Tuyet doi khong sinh my pham, skincare, thoi trang, thuc pham hay hang tieu dung ngoai MRO. Neu user mo ta muc dich, phan ra vat tu chinh, phu kien lap dat, dung cu, do kiem va PPE. Tra ve duy nhat JSON object: {"items":[{"term":"","aliases":[],"group":"","priority":"must|should","confidence":0.0}]}. Khong markdown, khong giai thich.';
		$user   = 'Query: ' . $query . '. Intent: ' . $intent . '. Max items: ' . $max_items . '.';
		$model  = (string) apply_filters( 'bizcity_twinbrain_super_mro_model', 'openai/gpt-4o', 'intent_terms' );

		try {
			$resp = $llm->chat( array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			), array(
				'purpose'     => 'twinbrain_products_intent_terms',
				'model'       => $model,
				'temperature' => 0,
				'max_tokens'  => 420,
				'timeout'     => 12,
			) );
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( empty( $resp['success'] ) ) {
			return array();
		}

		$msg = isset( $resp['message'] ) ? (string) $resp['message'] : '';
		if ( $msg === '' ) {
			return array();
		}

		$decoded = $this->parse_json_object( $msg );
		if ( ! is_array( $decoded ) || ! isset( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) {
			return array();
		}

		$out  = array();
		$seen = array();
		foreach ( $decoded['items'] as $item ) {
			$term = '';
			$aliases = array();
			$group = '';
			$priority = 'must';
			$confidence = 0.8;

			if ( is_array( $item ) ) {
				$term = trim( wp_strip_all_tags( (string) ( $item['term'] ?? '' ) ) );
				$aliases = isset( $item['aliases'] ) && is_array( $item['aliases'] ) ? $item['aliases'] : array();
				$group = (string) ( $item['group'] ?? '' );
				$priority = (string) ( $item['priority'] ?? 'must' );
				$confidence = is_numeric( $item['confidence'] ?? null ) ? (float) $item['confidence'] : 0.8;
			} else {
				$term = trim( wp_strip_all_tags( (string) $item ) );
			}

			if ( $term === '' ) {
				continue;
			}
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — fail closed for obvious non-MRO retail contamination.
			$alias_text = array();
			foreach ( $aliases as $alias ) {
				if ( is_scalar( $alias ) ) {
					$alias_text[] = (string) $alias;
				}
			}
			if ( ! $this->is_super_mro_term( $term . ' ' . implode( ' ', $alias_text ) ) ) {
				continue;
			}

			$term_key = $this->normalize_term_for_compare( $term );
			if ( $term_key === '' || isset( $seen[ $term_key ] ) ) {
				continue;
			}
			$seen[ $term_key ] = true;

			if ( $priority !== 'should' ) {
				$priority = 'must';
			}

			$out[] = array(
				'term'       => $term,
				'aliases'    => $this->normalize_aliases( $term, $aliases ),
				'group'      => $group,
				'priority'   => $priority,
				'confidence' => max( 0.0, min( 1.0, $confidence ) ),
				'source'     => 'intent_prompt',
			);

			if ( count( $out ) >= $max_items ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param string $query
	 * @param string $intent
	 * @param int    $max_items
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_detected_products_deterministic( string $query, string $intent, int $max_items ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-1 - deterministic fallback extraction when intent LLM is unavailable.
		$max_local = $intent === self::INTENT_NEED_SOLUTION ? $max_items : min( 3, $max_items );
		$terms     = array();

		if ( $intent === self::INTENT_NEED_SOLUTION ) {
			$terms = $this->decompose_needs( $query, $max_local );
		} else {
			$q = trim( wp_strip_all_tags( $query ) );
			if ( preg_match( '/\bva\b/u', mb_strtolower( $q ) ) ) {
				$segments = preg_split( '/\bva\b/u', $q );
				$segments = is_array( $segments ) ? $segments : array();
				foreach ( $segments as $segment ) {
					$segment = trim( (string) $segment );
					if ( $segment === '' ) {
						continue;
					}
					$tokens = preg_split( '/\s+/u', mb_strtolower( $segment ) );
					$tokens = is_array( $tokens ) ? $this->strip_generic_tokens( $tokens ) : array();
					if ( ! empty( $tokens ) ) {
						$terms[] = trim( implode( ' ', $tokens ) );
					}
					if ( count( $terms ) >= $max_local ) {
						break;
					}
				}
			}

			if ( empty( $terms ) ) {
				$clean = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', mb_strtolower( $q ) );
				$clean = preg_replace( '/\s+/u', ' ', (string) $clean );
				$parts = preg_split( '/\s+/u', trim( (string) $clean ) );
				$parts = is_array( $parts ) ? $this->strip_generic_tokens( $parts ) : array();
				if ( ! empty( $parts ) ) {
					$terms[] = trim( implode( ' ', $parts ) );
				}
			}
		}

		if ( empty( $terms ) ) {
			$terms[] = $query;
		}

		$out  = array();
		$seen = array();
		foreach ( $terms as $term ) {
			$term = trim( wp_strip_all_tags( (string) $term ) );
			if ( $term === '' ) {
				continue;
			}
			$term_key = $this->normalize_term_for_compare( $term );
			if ( $term_key === '' || isset( $seen[ $term_key ] ) ) {
				continue;
			}
			$seen[ $term_key ] = true;

			$out[] = array(
				'term'       => $term,
				'aliases'    => $this->normalize_aliases( $term, array() ),
				'group'      => $intent === self::INTENT_NEED_SOLUTION ? 'solution' : 'lookup',
				'priority'   => 'must',
				'confidence' => 0.65,
				'source'     => 'deterministic',
			);
			if ( count( $out ) >= $max_local ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * @param string            $term
	 * @param array<int,mixed>  $aliases
	 * @return array<int,string>
	 */
	private function normalize_aliases( string $term, array $aliases ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-2 - normalize aliases to improve Woo recall for natural-language prompts.
		$pool = array( $term );
		foreach ( $aliases as $alias ) {
			$pool[] = (string) $alias;
		}
		foreach ( $this->build_aliases_for_term( $term ) as $alias ) {
			$pool[] = $alias;
		}

		$out  = array();
		$seen = array();
		foreach ( $pool as $raw ) {
			$alias = trim( wp_strip_all_tags( (string) $raw ) );
			if ( $alias === '' ) {
				continue;
			}
			$alias = preg_replace( '/\s+/u', ' ', $alias );
			$key   = $this->normalize_term_for_compare( (string) $alias );
			if ( $key === '' || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = (string) $alias;
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param string $term
	 * @return array<int,string>
	 */
	private function build_aliases_for_term( string $term ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS V2-2 - lightweight alias expansion for common VN e-commerce wording.
		$norm = $this->normalize_term_for_compare( $term );
		if ( $norm === '' ) {
			return array();
		}

		$tokens = preg_split( '/\s+/u', $norm );
		$tokens = is_array( $tokens ) ? array_values( array_filter( $tokens ) ) : array();
		$out    = array( $norm );

		if ( count( $tokens ) >= 2 ) {
			$out[] = $tokens[0];
			$out[] = $tokens[ count( $tokens ) - 1 ];
		}

		$has_den    = in_array( 'den', $tokens, true );
		$has_bong   = in_array( 'bong', $tokens, true );
		$has_bulong = in_array( 'bulong', $tokens, true ) || ( in_array( 'bu', $tokens, true ) && in_array( 'long', $tokens, true ) );
		$has_oc     = in_array( 'oc', $tokens, true );

		if ( $has_den || $has_bong ) {
			$out[] = 'bong den';
			$out[] = 'den led';
			$out[] = 'bong';
		}
		if ( $has_bulong || $has_oc ) {
			$out[] = 'bu long';
			$out[] = 'bulong';
			$out[] = 'oc';
			$out[] = 'oc luc giac';
		}

		return $out;
	}

	/**
	 * @param string $term
	 * @return string
	 */
	private function normalize_term_for_compare( string $term ): string {
		$term = trim( wp_strip_all_tags( $term ) );
		if ( $term === '' ) {
			return '';
		}
		if ( function_exists( 'remove_accents' ) ) {
			$term = remove_accents( $term );
		}
		$term = mb_strtolower( $term );
		$term = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $term );
		$term = preg_replace( '/\s+/u', ' ', (string) $term );
		return trim( (string) $term );
	}

	/**
	 * Reject obvious unrelated retail categories before they enter Woo/web ranking.
	 *
	 * @param string $term Candidate term and aliases.
	 * @return bool
	 */
	private function is_super_mro_term( string $term ): bool {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — prevent cosmetics/food/fashion domain contamination.
		$normalized = $this->normalize_term_for_compare( $term );
		if ( $normalized === '' ) {
			return false;
		}
		$deny = array(
			'mascara', 'phan mat', 'phan ma', 'phan nen', 'chi ke mat', 'son moi', 'nuoc hoa',
			'skincare', 'kem duong', 'serum', 'kem chong nang', 'sua rua mat', 'my pham',
			'quan ao', 'vay dam', 'trang suc', 'do choi', 'thuc pham chuc nang',
		);
		foreach ( $deny as $blocked ) {
			if ( mb_strpos( $normalized, $blocked ) !== false ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<int,array<string,mixed>> $results Woo candidates.
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_super_mro_results( array $results ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — remove obvious cross-domain Woo matches before ranking/composition.
		$out = array();
		foreach ( $results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$haystack = (string) ( $row['name'] ?? '' ) . ' ' . (string) ( $row['categories_text'] ?? '' );
			if ( ! $this->is_super_mro_term( $haystack ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Detect inputs still needed to compute quantity/spec safely.
	 *
	 * @param string $query
	 * @param string $intent
	 * @return array<int,string>
	 */
	private function detect_missing_constraints( string $query, string $intent ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — collect question prompts before BOQ/quantity commitments.
		if ( $intent !== self::INTENT_NEED_SOLUTION ) {
			return array();
		}

		$q = $this->normalize_term_for_compare( $query );
		$out = array();

		if ( preg_match( '/(den tran|den am tran|den op tran|downlight)/u', $q ) ) {
			$checks = array(
				'loai tran'           => '/(tran thach cao|tran be tong|tran nhua|tran go)/u',
				'so diem den'         => '/(\d+\s*(den|bong|diem))/u',
				'dien tich thi cong'  => '/(\d+\s*(m2|met vuong))/u',
				'chieu dai tuyen day' => '/(\d+\s*(m|met)\s*(day|cap))/u',
				'dien ap pha'         => '/(220v|380v|1 pha|3 pha)/u',
				'cong suat tong'      => '/(\d+\s*(w|kw))/u',
				'moi truong lap dat'  => '/(am uot|ngoai troi|trong nha|nha xuong|chong nuoc)/u',
				'ngan sach'           => '/(ngan sach|bao nhieu tien|duoi\s*\d|toi da\s*\d|\d+\s*(trieu|k|nghin))/u',
			);
			foreach ( $checks as $label => $pattern ) {
				if ( preg_match( $pattern, $q ) !== 1 ) {
					$out[] = $label;
				}
			}
		}

		if ( preg_match( '/(boq|bom|bang gia|bao gia|rfq|du toan)/u', $q ) ) {
			$checks = array(
				'pham vi cong viec' => '/(lap moi|sua chua|thay the|bao tri|nang cap)/u',
				'don vi tinh khoi luong' => '/(m2|m3|m|cai|bo|cuon|kg|tan)/u',
			);
			foreach ( $checks as $label => $pattern ) {
				if ( preg_match( $pattern, $q ) !== 1 ) {
					$out[] = $label;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string $query
	 * @param string $intent
	 * @return bool
	 */
	private function is_boq_or_sheet_query( string $query, string $intent ): bool {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — detect requests that should prepare BOQ/sheet handoff metadata.
		if ( $intent !== self::INTENT_NEED_SOLUTION ) {
			return false;
		}
		$q = $this->normalize_term_for_compare( $query );
		return preg_match( '/(boq|bom|bang gia|bao gia|rfq|excel|sheet|du toan|khoi luong)/u', $q ) === 1;
	}

	/**
	 * @param string                          $query
	 * @param array<int,array<string,mixed>>  $need_entries
	 * @param array<int,array<string,mixed>>  $matched
	 * @return array<string,mixed>
	 */
	private function build_sheet_seed( string $query, array $need_entries, array $matched ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — prepare deterministic sheet seed for BOQ/sheet next-step handoff.
		$headers = array( 'Nhom', 'Hang muc', 'Spec toi thieu', 'DVT', 'SL', 'Don gia', 'Ton kho', 'Citation' );
		$rows = array();

		$match_by_need = array();
		foreach ( $matched as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$need = trim( (string) ( $row['need'] ?? '' ) );
			if ( $need === '' ) {
				continue;
			}
			$key = $this->normalize_term_for_compare( $need );
			if ( $key !== '' && ! isset( $match_by_need[ $key ] ) ) {
				$match_by_need[ $key ] = $row;
			}
		}

		foreach ( $need_entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$need = trim( (string) ( $entry['term'] ?? '' ) );
			if ( $need === '' ) {
				continue;
			}
			$key   = $this->normalize_term_for_compare( $need );
			$group = trim( (string) ( $entry['group'] ?? '' ) );
			if ( $group === '' ) {
				$group = 'super_mro';
			}

			$unit = 'cai';
			if ( preg_match( '/(day|cap|ong|mang|xich|curoa)/u', $key ) === 1 ) {
				$unit = 'm';
			}

			$spec     = '';
			$price    = '';
			$stock    = '';
			$citation = '';
			if ( isset( $match_by_need[ $key ] ) && is_array( $match_by_need[ $key ] ) ) {
				$product = isset( $match_by_need[ $key ]['product'] ) && is_array( $match_by_need[ $key ]['product'] )
					? $match_by_need[ $key ]['product']
					: array();
				$spec = trim( (string) ( $product['name'] ?? '' ) );
				$raw_price = trim( (string) ( $product['price'] ?? '' ) );
				$currency  = trim( (string) ( $product['currency'] ?? 'VND' ) );
				if ( $raw_price !== '' ) {
					$price = $raw_price . ( $currency !== '' ? ' ' . strtoupper( $currency ) : '' );
				}
				$stock = trim( (string) ( $product['stock_status'] ?? '' ) );
				$pid   = (int) ( $product['id'] ?? 0 );
				$link  = trim( (string) ( $product['permalink'] ?? '' ) );
				if ( $pid > 0 ) {
					$citation = '[prod:' . $pid . ']' . ( $link !== '' ? '(' . $link . ')' : '(no_link)' );
				}
			}

			$rows[] = array( $group, $need, $spec, $unit, '', $price, $stock, $citation );
			if ( count( $rows ) >= 20 ) {
				break;
			}
		}

		if ( empty( $rows ) ) {
			$rows[] = array( 'super_mro', $query, '', 'cai', '', '', '', '' );
		}

		$title = 'BOQ Super-MRO - ' . mb_substr( $query, 0, 60 );
		return array(
			'title'   => $title,
			'headers' => $headers,
			'rows'    => $rows,
		);
	}

	/**
	 * @param string $status
	 * @param string $error
	 * @return array<string,mixed>
	 */
	private function default_sheet_handoff( string $status = 'not_needed', string $error = '' ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — standard handoff envelope for SMRO-8 cross-surface parity.
		return array(
			'attempted'   => false,
			'created'     => false,
			'status'      => $status,
			'sheet_id'    => 0,
			'token'       => '',
			'enriched'    => 0,
			'still_empty' => 0,
			'error'       => $error,
		);
	}

	/**
	 * @param bool               $sheet_recommended
	 * @param array<string,mixed> $sheet_seed
	 * @param array<string,mixed> $opts
	 * @param string             $trace_id
	 * @return array<string,mixed>
	 */
	private function maybe_auto_sheet_handoff( bool $sheet_recommended, array $sheet_seed, array $opts, string $trace_id ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — perform auto create+enrich sheet_enrich handoff when BOQ intent is detected.
		if ( ! $sheet_recommended ) {
			return $this->default_sheet_handoff( 'not_needed' );
		}

		$auto_handoff = ! isset( $opts['auto_sheet_handoff'] ) || ! empty( $opts['auto_sheet_handoff'] );
		if ( ! $auto_handoff ) {
			return $this->default_sheet_handoff( 'disabled' );
		}

		$handoff = $this->default_sheet_handoff( 'pending' );
		$handoff['attempted'] = true;

		$user_id = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			$handoff['status'] = 'no_owner';
			$handoff['error']  = 'user_id_required';
			return $handoff;
		}

		if ( ! class_exists( 'BizCity_TwinBrain_Sheet_Enricher' ) ) {
			$handoff['status'] = 'sheet_unavailable';
			$handoff['error']  = 'enricher_missing';
			return $handoff;
		}

		$headers = isset( $sheet_seed['headers'] ) && is_array( $sheet_seed['headers'] ) ? $sheet_seed['headers'] : array();
		$rows    = isset( $sheet_seed['rows'] ) && is_array( $sheet_seed['rows'] ) ? $sheet_seed['rows'] : array();
		if ( empty( $headers ) || empty( $rows ) ) {
			$handoff['status'] = 'invalid_seed';
			$handoff['error']  = 'missing_headers_rows';
			return $handoff;
		}

		$enricher = BizCity_TwinBrain_Sheet_Enricher::instance();
		$created  = $enricher->create_sheet( array(
			'user_id'       => $user_id,
			'title'         => (string) ( $sheet_seed['title'] ?? '' ),
			'headers'       => $headers,
			'rows'          => $rows,
			'research_mode' => (string) ( $opts['sheet_research_mode'] ?? 'fast' ),
			'trace_id'      => $trace_id,
		) );

		if ( empty( $created['ok'] ) ) {
			$handoff['status'] = 'create_failed';
			$handoff['error']  = (string) ( $created['error'] ?? 'sheet_create_failed' );
			return $handoff;
		}

		$sheet_id = (int) ( $created['sheet_id'] ?? 0 );
		if ( $sheet_id <= 0 ) {
			$handoff['status'] = 'create_failed';
			$handoff['error']  = 'sheet_id_missing';
			return $handoff;
		}

		$handoff['created']  = true;
		$handoff['sheet_id'] = $sheet_id;
		$handoff['token']    = '[sheet:S#' . $sheet_id . ']';

		$max_cells = max( 1, min( 10, (int) ( $opts['sheet_max_cells'] ?? 10 ) ) );
		$run = $enricher->enrich_sheet( $sheet_id, array(
			'user_id'   => $user_id,
			'max_cells' => $max_cells,
			'trace_id'  => $trace_id,
		) );
		if ( empty( $run['ok'] ) ) {
			$handoff['status'] = 'enrich_failed';
			$handoff['error']  = (string) ( $run['error'] ?? 'sheet_enrich_failed' );
			return $handoff;
		}

		$handoff['status']      = 'ok';
		$handoff['enriched']    = (int) ( $run['enriched'] ?? 0 );
		$handoff['still_empty'] = (int) ( $run['still_empty'] ?? 0 );
		$handoff['error']       = '';
		return $handoff;
	}

	/**
	 * @param array<int,mixed> $tokens
	 * @return array<int,string>
	 */
	private function strip_generic_tokens( array $tokens ): array {
		$stopwords = array(
			'nha', 'co', 'khong', 'ko', 'shop', 'cua', 'hang', 'ban', 'san', 'pham',
			'gia', 'bao', 'nhieu', 'con', 'het', 'ton', 'kho', 'can', 'mua', 'toi', 'muon',
			'dung', 'de', 'lam', 'gi', 'nao', 'cho', 'xin', 'tu', 'van', 've', 'hay', 'giup',
			'minh', 'voi', 'nhe', 'a', 'ah', 'uh', 'u', 'duoc', 'khong',
		);

		$out = array();
		foreach ( $tokens as $token_raw ) {
			$token = trim( (string) $token_raw );
			if ( $token === '' ) {
				continue;
			}
			if ( function_exists( 'remove_accents' ) ) {
				$token = remove_accents( $token );
			}
			$token = preg_replace( '/[^\p{L}\p{N}]+/u', '', mb_strtolower( $token ) );
			$token = (string) $token;
			if ( $token === '' || in_array( $token, $stopwords, true ) ) {
				continue;
			}
			if ( mb_strlen( $token ) < 2 ) {
				continue;
			}
			$out[] = $token;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param string $raw
	 * @return array<string,mixed>
	 */
	private function parse_json_object( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array();
		}

		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/```(?:json)?\s*([\s\S]*?)```/u', $raw, $m ) ) {
			$candidate = trim( (string) $m[1] );
			$decoded   = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		if ( preg_match( '/\{[\s\S]*\}/u', $raw, $m ) ) {
			$candidate = trim( (string) $m[0] );
			$decoded   = json_decode( $candidate, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	/**
	 * @param array<int,array<string,mixed>> $results
	 * @return array<int,array<string,mixed>>
	 */
	private function map_woo_result_preview( array $results ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - compact result payload for web_search_done event.
		$out = array();
		foreach ( array_slice( $results, 0, 5 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'id'           => (int) ( $row['id'] ?? 0 ),
				'name'         => (string) ( $row['name'] ?? '' ),
				'price'        => (string) ( $row['price'] ?? '' ),
				'currency'     => (string) ( $row['currency'] ?? '' ),
				'stock_status' => (string) ( $row['stock_status'] ?? '' ),
				'permalink'    => (string) ( $row['permalink'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * @param string                  $need
	 * @param bool                    $enabled
	 * @param callable|null           $emit
	 * @param int                     $iter
	 * @param string                  $trace_id
	 * @param int                     $event_user_id
	 * @param string                  $event_session_id
	 * @return array{suggestion:string,citations:array<int,string>,degraded:string}
	 */
	private function web_enrich_need( string $need, bool $enabled, $emit, int $iter, string $trace_id, int $event_user_id = 0, string $event_session_id = '' ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — prioritize super-mro.com before curated MRO web fallback.
		if ( ! $enabled ) {
			return array(
				'suggestion' => '',
				'citations'  => array(),
				'degraded'   => '',
			);
		}

		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return array(
				'suggestion' => '',
				'citations'  => array(),
				'degraded'   => 'search_gateway',
			);
		}

		$search = BizCity_Search_Client::instance();
		if ( ! $search->is_ready() ) {
			return array(
				'suggestion' => '',
				'citations'  => array(),
				'degraded'   => 'search_gateway',
			);
		}

		$search_args = array(
			'search_depth'        => 'basic',
			'include_answer'      => false,
			'include_raw_content' => false,
			'include_domains'     => array( 'super-mro.com' ),
		);
		$results = $search->search( $need . ' vat tu MRO', 3, $search_args );
		if ( ! is_wp_error( $results ) && is_array( $results ) && empty( $results ) ) {
			// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — broaden only to approved technical/MRO sources after first-party miss.
			$search_args['include_domains'] = array(
				'super-mro.com', 'mcmaster.com', 'fastenal.com', 'grainger.com', 'rs-online.com', 'moc.gov.vn'
			);
			$results = $search->search( $need . ' vat tu cong nghiep MRO thong so ky thuat', 3, $search_args );
		}
		if ( is_wp_error( $results ) || ! is_array( $results ) ) {
			return array(
				'suggestion' => '',
				'citations'  => array(),
				'degraded'   => 'search_gateway',
			);
		}

		$citations = array();
		$summary   = '';
		foreach ( $results as $idx => $row ) {
			$url = isset( $row['url'] ) ? (string) $row['url'] : '';
			if ( $url === '' ) {
				continue;
			}
			$citations[] = '[web:' . ( (int) $idx + 1 ) . '#' . $url . ']';
			if ( $summary === '' ) {
				$title   = isset( $row['title'] ) ? (string) $row['title'] : '';
				$excerpt = isset( $row['excerpt'] ) ? (string) $row['excerpt'] : '';
				$summary = trim( $title !== '' ? $title : $excerpt );
			}
		}

		if ( $summary === '' ) {
			$summary = 'Tham khao thong tin ben ngoai theo nhu cau nay.';
		}

		$this->emit_step( $emit, 'web_react_step', array(
			'trace_id'            => $trace_id,
			'mode'                => 'products',
			'iter'                => $iter,
			'action'              => 'web_enrich',
			'action_input'        => $need,
			'observation_summary' => $summary,
			'web_hits'            => count( $citations ),
		) );
		$this->emit_product_timeline_event( 'product_react_step', array(
			'trace_id'            => $trace_id,
			'iter'                => $iter,
			'action'              => 'web_enrich',
			'action_input'        => $need,
			'observation_summary' => $summary,
			'matched_ids'         => array(),
			'web_hits'            => count( $citations ),
		), $event_user_id, $event_session_id );

		return array(
			'suggestion' => $summary,
			'citations'  => $citations,
			'degraded'   => '',
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $matched
	 * @param array<int,array<string,mixed>> $gaps
	 * @return array<int,string>
	 */
	private function collect_citation_tokens( array $matched, array $gaps ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - flatten and deduplicate prod/web citation tokens.
		$out = array();
		foreach ( $matched as $row ) {
			$token = isset( $row['citation'] ) ? (string) $row['citation'] : '';
			if ( $token !== '' ) {
				$out[] = $token;
			}
		}
		foreach ( $gaps as $row ) {
			$cits = isset( $row['citations'] ) && is_array( $row['citations'] ) ? $row['citations'] : array();
			foreach ( $cits as $token ) {
				$token = (string) $token;
				if ( $token !== '' ) {
					$out[] = $token;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Best-effort LLM decomposition.
	 *
	 * @param string $query
	 * @param int    $max_items
	 * @return array<int,string>
	 */
	private function decompose_needs_by_llm( string $query, int $max_items ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS — specialist Super-MRO planner; never inherit Gemini Flash chat default.
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return array();
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return array();
		}

		$system = 'Ban la ky su du toan Super-MRO cho MRO, dien, co khi va xay dung. Phan ra vat tu chinh, phu kien, dung cu thi cong, do kiem va PPE theo muc dich. Cam my pham, thoi trang, thuc pham va hang ngoai MRO. Tra ve JSON object: {"items":["item 1","item 2"]}. Khong markdown, khong giai thich.';
		$user   = 'Nhu cau: ' . $query . '. So item toi da: ' . $max_items . '.';
		$model  = (string) apply_filters( 'bizcity_twinbrain_super_mro_model', 'openai/gpt-4o', 'decompose' );
		try {
			$resp = $llm->chat( array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $user ),
			), array(
				'purpose'     => 'twinbrain_products_decompose',
				'model'       => $model,
				'temperature' => 0,
				'max_tokens'  => 280,
				'timeout'     => 12,
			) );
		} catch ( \Throwable $e ) {
			return array();
		}

		if ( empty( $resp['success'] ) ) {
			return array();
		}

		$msg = isset( $resp['message'] ) ? (string) $resp['message'] : '';
		if ( $msg === '' ) {
			return array();
		}
		$decoded = json_decode( trim( $msg ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['items'] ) || ! is_array( $decoded['items'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $decoded['items'] as $item ) {
			$item = trim( wp_strip_all_tags( (string) $item ) );
			if ( $item === '' || ! $this->is_super_mro_term( $item ) ) {
				continue;
			}
			$out[] = $item;
			if ( count( $out ) >= $max_items ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param callable|null $emit
	 * @param string        $event
	 * @param array         $payload
	 */
	private function emit_step( $emit, string $event, array $payload ): void {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - relay progress events for Ask Brain timeline.
		if ( is_callable( $emit ) ) {
			call_user_func( $emit, $event, $payload );
		}
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $event, $payload );
			} catch ( \Throwable $e ) {
				// Fail-open: timeline event emission must never break resolver.
			}
		}
	}

	/**
	 * @param string $query
	 * @return array<int,string>
	 */
	private function extract_keywords( string $query ): array {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - lightweight keyword extraction for product_intent_detected telemetry.
		$raw = preg_split( '/\s+/u', mb_strtolower( trim( $query ) ) );
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $token ) {
			$token = trim( (string) $token );
			if ( $token === '' || mb_strlen( $token ) < 2 ) {
				continue;
			}
			$out[] = $token;
			if ( count( $out ) >= 8 ) {
				break;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Emit optional product-specific timeline events.
	 *
	 * 1) do_action('bizcity_twin_event') for live SSE bridge in runtime.
	 * 2) dispatch_v2() for canonical event-stream persistence (R-EVT-1).
	 *
	 * @param string $event_type
	 * @param array  $payload
	 * @param int    $event_user_id
	 * @param string $event_session_id
	 */
	private function emit_product_timeline_event( string $event_type, array $payload, int $event_user_id = 0, string $event_session_id = '' ): void {
		// [2026-07-15 Johnny Chu] PHASE-TWB-PRODUCTS - optional product_* timeline taxonomy emission (fail-open).
		$trace_id = isset( $payload['trace_id'] ) ? (string) $payload['trace_id'] : '';
		if ( $trace_id === '' ) {
			return;
		}

		do_action( 'bizcity_twin_event', $event_type, $payload );

		if ( ! class_exists( 'BizCity_Twin_Event_Bus' ) || ! method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			return;
		}

		$opts = array(
			'event_source' => 'twinbrain',
			'trace_id'     => $trace_id,
			'user_id'      => $event_user_id > 0 ? $event_user_id : (int) get_current_user_id(),
		);
		if ( $event_session_id !== '' ) {
			$opts['session_id'] = $event_session_id;
		}

		try {
			BizCity_Twin_Event_Bus::dispatch_v2( $event_type, $payload, $opts );
		} catch ( \Throwable $e ) {
			// Fail-open: product telemetry must not block resolver response.
		}
	}
}
