<?php
/**
 * TwinBrain Notebook Source Layer.
 *
 * Builds a first-class source map for Ask Brain notebook answers so final
 * answers can name notebook title/id and validate `[nb:X/pY]` citations.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-07-18
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Notebook_Source_Layer {

	/** @var self|null */
	private static $instance = null;

	public static function instance(): self {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — singleton builder for notebook source map.
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — no-op constructor.
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private function normalize_source_url( $value ) {
		// [2026-08-01 Johnny Chu] HOTFIX — reject malformed notebook origin URLs before they enter SSE citations.
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || preg_match( '/^(none|null|undefined|false|nan)$/i', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}
		return esc_url_raw( $value );
	}

	/**
	 * Build source-map payload from selected notebooks and perspective rows.
	 *
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<int,array<string,mixed>> $answers
	 * @param array<string,mixed>            $opts
	 * @return array<string,mixed>
	 */
	public function build_from_turn( array $candidates, array $answers, array $opts = array() ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — promote notebook rows into first-class answer sources.
		$candidate_index = $this->index_candidates( $candidates );
		$passage_refs    = $this->collect_passage_refs( $answers );
		$passage_meta    = $this->fetch_passage_meta( $passage_refs );

		$out = array();
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			$mode = (string) ( $answer['mode'] ?? '' );
			if ( $mode === 'quick' || $mode === 'deep' || $mode === 'social' || $mode === 'company' || $mode === 'med' || $mode === 'scholar' || $mode === 'nutri' || $mode === 'law' || $mode === 'tax' || $mode === 'gov' || $mode === 'products' ) {
				continue;
			}

			$notebook_id = (int) ( $answer['notebook_id'] ?? 0 );
			if ( $notebook_id <= 0 ) {
				continue;
			}

			$candidate = isset( $candidate_index[ $notebook_id ] ) ? $candidate_index[ $notebook_id ] : array();
			$title     = $this->first_non_empty( array(
				(string) ( $answer['label'] ?? '' ),
				(string) ( $candidate['label'] ?? '' ),
				'Notebook #' . $notebook_id,
			) );
			$reason = $this->first_non_empty( array(
				(string) ( $answer['reason'] ?? '' ),
				(string) ( $candidate['reason'] ?? '' ),
			) );

			$passages = $this->build_passage_rows_for_answer( $notebook_id, $answer, $passage_meta );
			if ( empty( $passages ) ) {
				continue;
			}

			$matched_tokens = array();
			$source_files   = array();
			foreach ( $passages as $passage ) {
				foreach ( (array) ( $passage['matched_tokens'] ?? array() ) as $tok ) {
					$tok = trim( (string) $tok );
					if ( $tok !== '' ) {
						$matched_tokens[ $tok ] = true;
					}
				}
				$source_title = trim( (string) ( $passage['source_title'] ?? '' ) );
				if ( $source_title !== '' ) {
					$source_files[ $source_title ] = true;
				}
			}

			$confidence = 'weak';
			if ( count( $passages ) >= 3 && ! empty( $matched_tokens ) ) {
				$confidence = 'strong';
			} elseif ( count( $passages ) > 0 ) {
				$confidence = 'medium';
			}

			$out[] = array(
				'notebook_id'      => $notebook_id,
				'title'            => $title,
				'label'            => $title,
				'selection_reason' => $reason,
				'score'            => isset( $candidate['score'] ) ? (float) $candidate['score'] : 0.0,
				'guru_bound'       => ( strpos( $reason, 'guru_bound' ) !== false ),
				'coverage'         => array(
					'passage_count'        => count( $passages ),
					'matched_token_count'  => count( $matched_tokens ),
					'source_files'         => array_values( array_keys( $source_files ) ),
					'confidence'           => $confidence,
				),
				'passages'         => $passages,
			);
		}

		$search_context_payload = array( 'query' => '', 'scope' => '', 'total' => 0, 'top_n' => 0, 'tokens' => array(), 'results' => array() );
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — enrich source map with global top TwinSearch hits before citation validation and file briefs.
		$out = $this->augment_source_map_with_search_context( $out, $candidate_index, $opts, $search_context_payload );

		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 Cross-Notebook Graph: compute runtime shared-entity links between notebooks.
		$cross_notebook_links = $this->compute_cross_notebook_links( $out );

		$block = $this->build_source_block_md( $out );
		$source_file_payload = class_exists( 'BizCity_TwinBrain_Source_File_Deep_Layer' )
			? BizCity_TwinBrain_Source_File_Deep_Layer::instance()->build_from_source_map( $out, $opts )
			: array( 'source_file_briefs' => array(), 'source_file_briefs_json' => '[]', 'source_file_counts' => array( 'source_file_count' => 0, 'with_relations_count' => 0, 'weak_count' => 0 ) );

		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — extract concrete product entities from source evidence, not source titles.
		$product_entities = $this->extract_product_entities(
			(array) ( $search_context_payload['results'] ?? array() ),
			(array) ( $source_file_payload['source_file_briefs'] ?? array() )
		);
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — canonical Graph -> retrieval top30 -> rerank -> top8 evidence pack for all surfaces.
		$graph_vector_rerank_pack = $this->build_graph_vector_rerank_pack(
			$out,
			$search_context_payload,
			$cross_notebook_links,
			(array) ( $source_file_payload['source_file_briefs'] ?? array() ),
			$product_entities,
			$opts
		);
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.16 — count true product-name entities separately from category traits.
		$product_name_entity_count = 0;
		foreach ( $product_entities as $entity ) {
			if ( is_array( $entity ) && ( ! empty( $entity['is_product_name'] ) || (string) ( $entity['entity_type'] ?? '' ) === 'product_name' ) ) {
				$product_name_entity_count++;
			}
		}
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13/W0.15 — aggregate weak evidence and missing product-entity gaps per notebook.
		$training_gap_report = $this->compile_training_gap_report( $out, (array) ( $source_file_payload['source_file_briefs'] ?? array() ) );
		$training_gap_report = $this->append_product_entity_training_gap( $training_gap_report, $out, (array) ( $source_file_payload['source_file_briefs'] ?? array() ), $product_entities, $opts );
		return array(
			'notebook_source_map'       => $out,
			'notebook_source_map_json'  => (string) wp_json_encode( $out, JSON_UNESCAPED_UNICODE ),
			'notebook_source_block_md'  => $block,
			'notebook_source_counts'    => $this->count_source_map( $out ),
			// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — W0.7 file-level briefs for Ask Brain deep Notebook answers.
			'source_file_briefs'        => (array) ( $source_file_payload['source_file_briefs'] ?? array() ),
			'source_file_briefs_json'   => (string) ( $source_file_payload['source_file_briefs_json'] ?? '[]' ),
			'source_file_counts'        => (array) ( $source_file_payload['source_file_counts'] ?? array() ),
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — expose TwinSearch top hits to FE trace and replay.
			'search_context'            => $search_context_payload,
			'search_context_results'    => (array) ( $search_context_payload['results'] ?? array() ),
			'search_context_total'      => (int) ( $search_context_payload['total'] ?? 0 ),
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 cross-notebook links for shared-entity analysis.
			'cross_notebook_links'      => $cross_notebook_links,
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — Graph/vector/rerank evidence pack consumed by Final Composer and FE trace.
			'graph_vector_rerank_pack'  => $graph_vector_rerank_pack,
			'graph_entities'            => (array) ( $graph_vector_rerank_pack['graph_entities'] ?? array() ),
			'retrieval_candidates'       => (array) ( $graph_vector_rerank_pack['retrieval_candidates'] ?? array() ),
			'final_context_chunks'       => (array) ( $graph_vector_rerank_pack['final_context_chunks'] ?? array() ),
			'retrieval_candidate_count'  => (int) ( $graph_vector_rerank_pack['retrieval_candidate_count'] ?? 0 ),
			'final_context_count'        => (int) ( $graph_vector_rerank_pack['final_context_count'] ?? 0 ),
			'rerank_method'              => (string) ( $graph_vector_rerank_pack['rerank_method'] ?? '' ),
			'rerank_degraded'            => ! empty( $graph_vector_rerank_pack['rerank_degraded'] ),
			'rerank_error'               => (string) ( $graph_vector_rerank_pack['rerank_error'] ?? '' ),
			'vector_status'              => (string) ( $graph_vector_rerank_pack['vector_status'] ?? '' ),
			'vector_candidate_count'     => (int) ( $graph_vector_rerank_pack['vector_candidate_count'] ?? 0 ),
			'vector_degraded_reason'     => (string) ( $graph_vector_rerank_pack['vector_degraded_reason'] ?? '' ),
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21 — expose selector/graph hardening counters for runtime/UI evidence trace.
			'graph_candidate_count'      => (int) ( $graph_vector_rerank_pack['graph_candidate_count'] ?? 0 ),
			'selector_hardening_applied' => ! empty( $graph_vector_rerank_pack['selector_hardening_applied'] ),
			'selector_hardening_reason'  => (string) ( $graph_vector_rerank_pack['selector_hardening_reason'] ?? '' ),
			'selector_hardening_count'   => (int) ( $graph_vector_rerank_pack['selector_hardening_count'] ?? 0 ),
			'selector_hardening_scope'   => (string) ( $graph_vector_rerank_pack['selector_hardening_scope'] ?? '' ),
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — N5 training gap report per weak notebook.
			'training_gap_report'       => $training_gap_report,
			'product_entities'          => $product_entities,
			'product_entity_count'      => count( $product_entities ),
			'product_name_entity_count' => $product_name_entity_count,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @return string
	 */
	public function build_source_block_md( array $source_map ): string {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — deterministic user-visible notebook source block.
		if ( empty( $source_map ) ) {
			return '';
		}

		$lines   = array();
		$lines[] = '### Nguồn từ Notebook';
		$lines[] = '';
		$lines[] = '**Notebook đã dùng:**';
		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$coverage = isset( $row['coverage'] ) && is_array( $row['coverage'] ) ? $row['coverage'] : array();
			$tokens   = array();
			foreach ( array_slice( (array) ( $row['passages'] ?? array() ), 0, 4 ) as $passage ) {
				if ( is_array( $passage ) && ! empty( $passage['token'] ) ) {
					$tokens[] = (string) $passage['token'];
				}
			}
			$matched = (int) ( $coverage['matched_token_count'] ?? 0 );
			$lines[] = sprintf(
				'- %s (id=%d) — %d đoạn, match=%d — %s',
				(string) ( $row['title'] ?? '' ),
				(int) ( $row['notebook_id'] ?? 0 ),
				(int) ( $coverage['passage_count'] ?? 0 ),
				$matched,
				implode( ' ', $tokens )
			);
		}
		$lines[] = '';
		$lines[] = '**Cách đọc nguồn:** click citation để mở đúng passage trong Source View.';

		return trim( implode( "\n", $lines ) );
	}

	/**
	 * @param string                         $citation_token
	 * @param array<int,array<string,mixed>> $source_map
	 * @return bool
	 */
	public function validate_citation( string $citation_token, array $source_map ): bool {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — validate nb citations against current turn source map.
		$token = trim( $citation_token );
		if ( ! preg_match( '/^\[nb:(\d+)\/p(\d+)\]$/', $token, $m ) ) {
			return false;
		}
		$notebook_id = (int) $m[1];
		$passage_id  = (int) $m[2];
		if ( $notebook_id <= 0 || $passage_id <= 0 ) {
			return false;
		}

		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) || (int) ( $row['notebook_id'] ?? 0 ) !== $notebook_id ) {
				continue;
			}
			foreach ( (array) ( $row['passages'] ?? array() ) as $passage ) {
				if ( is_array( $passage ) && (int) ( $passage['passage_id'] ?? 0 ) === $passage_id ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Remove invalid nb citations from final text.
	 *
	 * @param string                         $answer_md
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array{answer_md:string,invalid_count:int,invalid_tokens:array<int,string>}
	 */
	public function strip_invalid_citations( string $answer_md, array $source_map ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — strip fake [nb:0/p0] and hallucinated notebook citations.
		$invalid = array();
		$clean = preg_replace_callback( '/\[nb:\d+\/p\d+\]/', function ( $m ) use ( $source_map, &$invalid ) {
			$token = (string) $m[0];
			if ( $this->validate_citation( $token, $source_map ) ) {
				return $token;
			}
			$invalid[] = $token;
			return '';
		}, $answer_md );

		return array(
			'answer_md'      => is_string( $clean ) ? $clean : $answer_md,
			'invalid_count'  => count( $invalid ),
			'invalid_tokens' => array_values( array_unique( $invalid ) ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array{notebook_count:int,passage_count:int,strong_count:int,weak_count:int}
	 */
	public function count_source_map( array $source_map ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — counters for SSE/probe/FE.
		$passages = 0;
		$strong   = 0;
		$weak     = 0;
		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$coverage = isset( $row['coverage'] ) && is_array( $row['coverage'] ) ? $row['coverage'] : array();
			$passages += (int) ( $coverage['passage_count'] ?? 0 );
			$confidence = (string) ( $coverage['confidence'] ?? '' );
			if ( $confidence === 'strong' ) {
				$strong++;
			} elseif ( $confidence === 'weak' ) {
				$weak++;
			}
		}
		return array(
			'notebook_count' => count( $source_map ),
			'passage_count'  => $passages,
			'strong_count'   => $strong,
			'weak_count'     => $weak,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return array<int,array<string,mixed>>
	 */
	private function index_candidates( array $candidates ): array {
		$index = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$notebook_id = (int) ( $candidate['notebook_id'] ?? 0 );
			if ( $notebook_id > 0 ) {
				$index[ $notebook_id ] = $candidate;
			}
		}
		return $index;
	}

	/**
	 * [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — Canonical Graph → retrieval top30 → rerank → top8 pack.
	 *
	 * This is the single Notebook evidence pack all surfaces should consume.
	 * It keeps the current local/lexical implementation fail-open while exposing
	 * explicit counters so a future hub/vector reranker can replace the scoring
	 * without changing TwinChat/Twin GPT/automation payload contracts.
	 *
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<string,mixed>            $search_context_payload
	 * @param array<int,array<string,mixed>> $cross_notebook_links
	 * @param array<int,array<string,mixed>> $source_file_briefs
	 * @param array<int,array<string,mixed>> $product_entities
	 * @param array<string,mixed>            $opts
	 * @return array<string,mixed>
	 */
	private function build_graph_vector_rerank_pack( array $source_map, array $search_context_payload, array $cross_notebook_links, array $source_file_briefs, array $product_entities, array $opts ): array {
		$target_candidates = isset( $opts['notebook_retrieval_candidate_top_n'] ) ? max( 10, min( 60, (int) $opts['notebook_retrieval_candidate_top_n'] ) ) : 30;
		$target_final      = isset( $opts['notebook_final_context_top_n'] ) ? max( 5, min( 8, (int) $opts['notebook_final_context_top_n'] ) ) : 8;
		$query             = trim( (string) ( $search_context_payload['query'] ?? $opts['notebook_search_context_query'] ?? $opts['prompt'] ?? '' ) );
		$query_tokens      = $this->w020_normalize_terms( (array) ( $search_context_payload['tokens'] ?? array() ) );
		if ( empty( $query_tokens ) ) {
			$query_tokens = $this->w020_tokenize_text( $query );
		}

		$graph_entities = $this->w020_extract_graph_entities( $query_tokens, $cross_notebook_links, $source_file_briefs, $product_entities );
		$candidates     = $this->w020_collect_retrieval_candidates( $source_map, (array) ( $search_context_payload['results'] ?? array() ), $target_candidates );
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.2 — promote relation triples/entities into real retrieval candidates, not only score terms.
		$graph_payload  = $this->w020_collect_graph_relation_candidates( $source_file_briefs, $graph_entities, $target_candidates );
		$candidates     = $this->w020_merge_candidate_lists( $candidates, (array) ( $graph_payload['candidates'] ?? array() ), $target_candidates * 3 );
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.2 — merge real KG vector candidates into the canonical top30 pool when .bin retrieval is available.
		$vector_payload = ! empty( $opts['w020_skip_vector_retriever'] )
			? array( 'status' => 'skipped', 'count' => 0, 'candidates' => array(), 'degraded_reason' => 'disabled_by_caller' )
			: $this->w020_collect_vector_candidates( $source_map, $query, $target_candidates );
		$candidates     = $this->w020_merge_candidate_lists( $candidates, (array) ( $vector_payload['candidates'] ?? array() ), $target_candidates * 3 );
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.1 — widen search when selected source evidence is weak or vector pool has no candidates.
		$selector_hardening = $this->w020_collect_broader_search_candidates( $query, $opts, $source_map, $vector_payload, $target_candidates );
		$candidates     = $this->w020_merge_candidate_lists( $candidates, (array) ( $selector_hardening['candidates'] ?? array() ), $target_candidates * 3 );
		$candidates     = $this->w020_rerank_candidates( $candidates, $query_tokens, $graph_entities );
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.3 — use Hub Branch #8 rerank when ready, otherwise keep deterministic local score.
		$hub_rerank     = $this->w020_apply_hub_rerank( $query, array_slice( $candidates, 0, $target_candidates ), $target_final, $opts );
		$candidates     = (array) ( $hub_rerank['candidates'] ?? $candidates );

		$retrieval_candidates = array_slice( $candidates, 0, $target_candidates );
		$final_chunks         = array_slice( $retrieval_candidates, 0, $target_final );

		return array(
			'phase'                     => 'W0.20',
			'query'                     => $query,
			'graph_entities'            => $graph_entities,
			'graph_entity_count'        => count( $graph_entities ),
			'target_candidate_count'    => $target_candidates,
			'retrieval_candidate_count' => count( $retrieval_candidates ),
			'retrieval_candidates'      => $retrieval_candidates,
			'rerank_applied'            => ! empty( $retrieval_candidates ),
			'rerank_method'             => (string) ( $hub_rerank['method'] ?? 'local_hybrid_graph_lexical' ),
			'rerank_degraded'           => ! empty( $hub_rerank['degraded'] ),
			'rerank_error'              => (string) ( $hub_rerank['error'] ?? '' ),
			'vector_status'             => (string) ( $vector_payload['status'] ?? 'not_attempted' ),
			'vector_candidate_count'    => (int) ( $vector_payload['count'] ?? 0 ),
			'vector_degraded_reason'    => (string) ( $vector_payload['degraded_reason'] ?? '' ),
			'graph_candidate_count'     => (int) ( $graph_payload['count'] ?? 0 ),
			'selector_hardening_applied' => ! empty( $selector_hardening['applied'] ),
			'selector_hardening_reason' => (string) ( $selector_hardening['reason'] ?? '' ),
			'selector_hardening_count'  => (int) ( $selector_hardening['count'] ?? 0 ),
			'selector_hardening_scope'  => (string) ( $selector_hardening['scope'] ?? '' ),
			'target_final_count'        => $target_final,
			'final_context_count'       => count( $final_chunks ),
			'final_context_chunks'      => $final_chunks,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $source_file_briefs
	 * @param array<int,array<string,mixed>> $graph_entities
	 * @return array{count:int,candidates:array<int,array<string,mixed>>}
	 */
	private function w020_collect_graph_relation_candidates( array $source_file_briefs, array $graph_entities, int $target_candidates ): array {
		$candidates = array();
		$seen       = array();
		$terms      = array();
		foreach ( $graph_entities as $entity ) {
			if ( is_array( $entity ) ) {
				$terms[] = (string) ( $entity['name'] ?? '' );
			}
		}
		$terms = $this->w020_normalize_terms( $terms );
		foreach ( $source_file_briefs as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			$notebook_id    = (int) ( $brief['notebook_id'] ?? 0 );
			$notebook_title = (string) ( $brief['notebook_title'] ?? '' );
			$source_id      = (int) ( $brief['source_id'] ?? 0 );
			$source_title   = (string) ( $brief['source_title'] ?? '' );
			$citations      = array_values( array_filter( array_map( 'strval', (array) ( $brief['key_citations'] ?? array() ) ) ) );
			$primary_cite   = isset( $citations[0] ) ? (string) $citations[0] : '';
			foreach ( (array) ( $brief['relation_triples'] ?? array() ) as $idx => $triple ) {
				if ( ! is_array( $triple ) ) {
					continue;
				}
				$subject   = (string) ( $triple['subject'] ?? '' );
				$predicate = (string) ( $triple['predicate'] ?? '' );
				$object    = (string) ( $triple['object'] ?? '' );
				$excerpt   = trim( $subject . ' ' . $predicate . ' ' . $object );
				if ( $excerpt === '' ) {
					continue;
				}
				$this->w020_add_candidate( $candidates, $seen, array(
					'source'           => 'graph_relation',
					'rank'             => (int) $idx + 1,
					'notebook_id'      => $notebook_id,
					'notebook_title'   => $notebook_title,
					'source_id'        => $source_id,
					'source_title'     => $source_title,
					'passage_id'       => $this->w020_extract_passage_id_from_citation( $primary_cite ),
					'citation'         => $primary_cite,
					'excerpt'          => 'Graph relation: ' . $excerpt,
					'matched_tokens'   => $this->w020_terms_present_in_text( $excerpt, $terms ),
					'match_count'      => 0,
					'graph_edge_count' => 1,
				) );
			}
			foreach ( array_slice( (array) ( $brief['source_claims'] ?? array() ), 0, 4 ) as $idx => $claim ) {
				$claim = trim( (string) $claim );
				if ( $claim === '' ) {
					continue;
				}
				$matched = $this->w020_terms_present_in_text( $claim, $terms );
				if ( empty( $matched ) && ! empty( $terms ) ) {
					continue;
				}
				$this->w020_add_candidate( $candidates, $seen, array(
					'source'           => 'graph_source_claim',
					'rank'             => 20 + (int) $idx,
					'notebook_id'      => $notebook_id,
					'notebook_title'   => $notebook_title,
					'source_id'        => $source_id,
					'source_title'     => $source_title,
					'passage_id'       => $this->w020_extract_passage_id_from_citation( $primary_cite ),
					'citation'         => $primary_cite,
					'excerpt'          => 'Source claim: ' . $claim,
					'matched_tokens'   => $matched,
					'match_count'      => count( $matched ),
					'graph_edge_count' => 0,
				) );
			}
			if ( count( $candidates ) >= $target_candidates ) {
				break;
			}
		}
		return array( 'count' => count( $candidates ), 'candidates' => array_slice( array_values( $candidates ), 0, $target_candidates ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<string,mixed>            $vector_payload
	 * @return array{applied:bool,reason:string,scope:string,count:int,candidates:array<int,array<string,mixed>>}
	 */
	private function w020_collect_broader_search_candidates( string $query, array $opts, array $source_map, array $vector_payload, int $target_candidates ): array {
		$payload = array( 'applied' => false, 'reason' => '', 'scope' => '', 'count' => 0, 'candidates' => array() );
		if ( ! empty( $opts['w020_skip_selector_hardening'] ) || trim( $query ) === '' || ! class_exists( 'BizCity_TwinSearch_Core' ) ) {
			return $payload;
		}
		$passage_count = 0;
		$strong_count  = 0;
		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$passages = (array) ( $row['passages'] ?? array() );
			$passage_count += count( $passages );
			if ( (string) ( (array) ( $row['coverage'] ?? array() )['confidence'] ?? '' ) === 'strong' ) {
				$strong_count++;
			}
		}
		$vector_count = (int) ( $vector_payload['count'] ?? 0 );
		$weak_source  = empty( $source_map ) || $passage_count < 3 || $strong_count === 0;
		if ( ! $weak_source && $vector_count > 0 ) {
			return $payload;
		}

		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$scope   = $user_id > 0 ? 'user' : 'blog';
		if ( ! empty( $opts['notebook_selector_hardening_scope'] ) ) {
			$scope = sanitize_key( (string) $opts['notebook_selector_hardening_scope'] );
		}
		if ( ! in_array( $scope, array( 'user', 'blog', 'character' ), true ) ) {
			$scope = $user_id > 0 ? 'user' : 'blog';
		}

		try {
			$result = BizCity_TwinSearch_Core::instance()->search_documents( array(
				'query'           => $query,
				'scope'           => $scope,
				'user_id'         => $user_id,
				'character_id'    => (int) ( $opts['guru_id'] ?? $opts['character_id'] ?? 0 ),
				'character_uuid'  => (string) ( $opts['character_uuid'] ?? '' ),
				'page'            => 1,
				'per_page'        => max( 8, min( 30, $target_candidates ) ),
				'include_content' => true,
			) );
		} catch ( \Throwable $e ) {
			$payload['reason'] = 'twinsearch_exception';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][W0.21.1][selector-hardening] ' . $e->getMessage() );
			}
			return $payload;
		}

		$tokens = isset( $result['tokens'] ) && is_array( $result['tokens'] ) ? array_values( array_filter( array_map( 'strval', $result['tokens'] ) ) ) : array();
		$candidates = array();
		$seen = array();
		$rank = 1;
		foreach ( (array) ( is_array( $result ) ? ( $result['results'] ?? array() ) : array() ) as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$normalized = $this->normalize_search_context_hit( $hit, $tokens, $rank );
			$this->w020_add_candidate( $candidates, $seen, array(
				'source'         => 'selector_hardening_twinsearch',
				'rank'           => $rank,
				'notebook_id'    => (int) ( $normalized['notebook_id'] ?? 0 ),
				'notebook_title' => (string) ( $normalized['notebook_title'] ?? '' ),
				'source_id'      => (int) ( $normalized['source_id'] ?? 0 ),
				'source_title'   => (string) ( $normalized['source_title'] ?? '' ),
				'passage_id'     => (int) ( $normalized['first_passage_id'] ?? 0 ),
				'citation'       => (string) ( $normalized['citation'] ?? '' ),
				'excerpt'        => (string) ( $normalized['context_excerpt'] ?? $normalized['snippet'] ?? '' ),
				'matched_tokens' => (array) ( $normalized['matched_tokens'] ?? array() ),
				'match_count'    => (int) ( $normalized['match_count'] ?? 0 ),
			) );
			$rank++;
		}
		$reason = $weak_source ? 'weak_source_map' : 'vector_empty';
		if ( $weak_source && $vector_count <= 0 ) {
			$reason = 'weak_source_map_and_vector_empty';
		}
		return array( 'applied' => true, 'reason' => $reason, 'scope' => $scope, 'count' => count( $candidates ), 'candidates' => array_slice( array_values( $candidates ), 0, $target_candidates ) );
	}

	/**
	 * @param array<int,string>              $query_tokens
	 * @param array<int,array<string,mixed>> $cross_notebook_links
	 * @param array<int,array<string,mixed>> $source_file_briefs
	 * @param array<int,array<string,mixed>> $product_entities
	 * @return array<int,array<string,mixed>>
	 */
	private function w020_extract_graph_entities( array $query_tokens, array $cross_notebook_links, array $source_file_briefs, array $product_entities ): array {
		$entities = array();
		foreach ( $query_tokens as $token ) {
			$this->w020_add_entity( $entities, $token, 'query_token', '', '' );
		}
		foreach ( $cross_notebook_links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			foreach ( (array) ( $link['shared_tokens'] ?? array() ) as $token ) {
				$this->w020_add_entity( $entities, (string) $token, 'cross_notebook_shared_token', (string) ( $link['link_reason'] ?? 'shared_entity' ), '' );
			}
		}
		foreach ( $source_file_briefs as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			foreach ( (array) ( $brief['relation_triples'] ?? array() ) as $triple ) {
				if ( is_array( $triple ) ) {
					$this->w020_add_entity( $entities, (string) ( $triple['subject'] ?? '' ), 'relation_subject', (string) ( $triple['predicate'] ?? '' ), (string) ( $triple['object'] ?? '' ) );
					$this->w020_add_entity( $entities, (string) ( $triple['object'] ?? '' ), 'relation_object', (string) ( $triple['predicate'] ?? '' ), (string) ( $triple['subject'] ?? '' ) );
				}
			}
		}
		foreach ( $product_entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$name = (string) ( $entity['name'] ?? '' );
			$type = (string) ( $entity['entity_type'] ?? 'product_entity' );
			$this->w020_add_entity( $entities, $name, $type !== '' ? $type : 'product_entity', (string) ( $entity['match_reason'] ?? '' ), (string) ( $entity['citation'] ?? '' ) );
		}

		usort( $entities, function ( $a, $b ) {
			return (int) ( $b['weight'] ?? 0 ) - (int) ( $a['weight'] ?? 0 );
		} );
		return array_slice( array_values( $entities ), 0, 30 );
	}

	/**
	 * @param array<string,array<string,mixed>> $entities
	 */
	private function w020_add_entity( array &$entities, string $name, string $type, string $relation, string $citation ): void {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( $name === '' || mb_strlen( $name ) < 2 ) {
			return;
		}
		$key = $this->w020_normalize_key( $name );
		if ( $key === '' ) {
			return;
		}
		if ( ! isset( $entities[ $key ] ) ) {
			$entities[ $key ] = array(
				'name'      => $name,
				'normalized'=> $key,
				'type'      => $type,
				'relations' => array(),
				'citations' => array(),
				'weight'    => 0,
			);
		}
		$entities[ $key ]['weight'] = (int) $entities[ $key ]['weight'] + 1;
		if ( $relation !== '' ) {
			$entities[ $key ]['relations'][] = $relation;
			$entities[ $key ]['relations'] = array_values( array_unique( $entities[ $key ]['relations'] ) );
		}
		if ( $citation !== '' ) {
			$entities[ $key ]['citations'][] = $citation;
			$entities[ $key ]['citations'] = array_values( array_unique( $entities[ $key ]['citations'] ) );
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<int,array<string,mixed>> $search_hits
	 * @return array<int,array<string,mixed>>
	 */
	private function w020_collect_retrieval_candidates( array $source_map, array $search_hits, int $target_candidates ): array {
		$candidates = array();
		$seen       = array();
		foreach ( $search_hits as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$citation = trim( (string) ( $hit['citation'] ?? '' ) );
			if ( $citation === '' && (int) ( $hit['notebook_id'] ?? 0 ) > 0 && (int) ( $hit['first_passage_id'] ?? 0 ) > 0 ) {
				$citation = sprintf( '[nb:%d/p%d]', (int) $hit['notebook_id'], (int) $hit['first_passage_id'] );
			}
			$this->w020_add_candidate( $candidates, $seen, array(
				'source'         => 'search_context',
				'rank'           => (int) ( $hit['rank'] ?? 0 ),
				'notebook_id'    => (int) ( $hit['notebook_id'] ?? 0 ),
				'notebook_title' => (string) ( $hit['notebook_title'] ?? '' ),
				'source_id'      => (int) ( $hit['source_id'] ?? 0 ),
				'source_title'   => (string) ( $hit['source_title'] ?? '' ),
				'passage_id'     => (int) ( $hit['first_passage_id'] ?? 0 ),
				'citation'       => $citation,
				'excerpt'        => (string) ( $hit['context_excerpt'] ?? $hit['snippet'] ?? '' ),
				'matched_tokens' => (array) ( $hit['matched_tokens'] ?? array() ),
				'match_count'    => (int) ( $hit['match_count'] ?? 0 ),
			) );
		}

		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$notebook_id = (int) ( $row['notebook_id'] ?? 0 );
			$title       = (string) ( $row['title'] ?? '' );
			foreach ( (array) ( $row['passages'] ?? array() ) as $passage ) {
				if ( ! is_array( $passage ) ) {
					continue;
				}
				$this->w020_add_candidate( $candidates, $seen, array(
					'source'         => 'notebook_source_map',
					'rank'           => (int) ( $passage['search_result_rank'] ?? 0 ),
					'notebook_id'    => $notebook_id,
					'notebook_title' => $title,
					'source_id'      => (int) ( $passage['source_id'] ?? 0 ),
					'source_title'   => (string) ( $passage['source_title'] ?? '' ),
					'passage_id'     => (int) ( $passage['passage_id'] ?? 0 ),
					'citation'       => (string) ( $passage['token'] ?? '' ),
					'excerpt'        => (string) ( $passage['excerpt'] ?? '' ),
					'matched_tokens' => (array) ( $passage['matched_tokens'] ?? array() ),
					'match_count'    => (int) ( $passage['search_match_count'] ?? 0 ),
				) );
			}
		}

		return array_slice( array_values( $candidates ), 0, max( 1, $target_candidates * 2 ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array{status:string,count:int,candidates:array<int,array<string,mixed>>,degraded_reason:string}
	 */
	private function w020_collect_vector_candidates( array $source_map, string $query, int $target_candidates ): array {
		$payload = array( 'status' => 'not_attempted', 'count' => 0, 'candidates' => array(), 'degraded_reason' => '' );
		$query = trim( $query );
		if ( $query === '' ) {
			$payload['status'] = 'skipped';
			$payload['degraded_reason'] = 'empty_query';
			return $payload;
		}
		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			$payload['status'] = 'degraded';
			$payload['degraded_reason'] = 'kg_retriever_missing';
			return $payload;
		}

		$notebooks = array();
		foreach ( $source_map as $row ) {
			if ( is_array( $row ) && (int) ( $row['notebook_id'] ?? 0 ) > 0 ) {
				$notebooks[ (int) $row['notebook_id'] ] = (string) ( $row['title'] ?? '' );
			}
		}
		if ( empty( $notebooks ) ) {
			$payload['status'] = 'skipped';
			$payload['degraded_reason'] = 'no_notebook_scope';
			return $payload;
		}

		$candidates = array();
		$seen = array();
		$per_notebook = max( 3, min( 12, (int) ceil( $target_candidates / max( 1, count( $notebooks ) ) ) + 2 ) );
		$degraded = '';
		foreach ( $notebooks as $notebook_id => $notebook_title ) {
			try {
				$out = BizCity_KG_Retriever::instance()->search( (int) $notebook_id, $query, $per_notebook );
				if ( is_array( $out ) && ! empty( $out['mode'] ) ) {
					$degraded = (string) $out['mode'];
				}
				foreach ( (array) ( is_array( $out ) ? ( $out['results'] ?? array() ) : array() ) as $rank => $hit ) {
					if ( ! is_array( $hit ) ) {
						continue;
					}
					$passage_id = (int) ( $hit['passage_id'] ?? 0 );
					$citation = $passage_id > 0 ? sprintf( '[nb:%d/p%d]', (int) $notebook_id, $passage_id ) : (string) ( $hit['citation'] ?? '' );
					$this->w020_add_candidate( $candidates, $seen, array(
						'source'         => 'kg_vector',
						'rank'           => (int) $rank + 1,
						'notebook_id'    => (int) $notebook_id,
						'notebook_title' => $notebook_title !== '' ? $notebook_title : 'Notebook #' . (int) $notebook_id,
						'source_id'      => (int) ( $hit['source_id'] ?? 0 ),
						'source_title'   => (string) ( $hit['source_title'] ?? '' ),
						'passage_id'     => $passage_id,
						'citation'       => $citation,
						'excerpt'        => (string) ( $hit['snippet'] ?? $hit['content'] ?? '' ),
						'matched_tokens' => array(),
						'match_count'    => 0,
						'vector_score'   => isset( $hit['score'] ) ? (float) $hit['score'] : 0.0,
					) );
				}
			} catch ( \Throwable $e ) {
				$degraded = 'kg_vector_exception';
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][W0.20.2][vector] ' . $e->getMessage() );
				}
			}
		}

		$payload['candidates'] = array_slice( array_values( $candidates ), 0, $target_candidates );
		$payload['count'] = count( $payload['candidates'] );
		$payload['status'] = $payload['count'] > 0 ? ( $degraded !== '' ? 'degraded_ready' : 'ready' ) : 'degraded';
		$payload['degraded_reason'] = $payload['count'] > 0 ? $degraded : ( $degraded !== '' ? $degraded : 'no_vector_hits' );
		return $payload;
	}

	/**
	 * @param array<int,array<string,mixed>> $primary
	 * @param array<int,array<string,mixed>> $secondary
	 * @return array<int,array<string,mixed>>
	 */
	private function w020_merge_candidate_lists( array $primary, array $secondary, int $limit ): array {
		$out = array();
		$seen = array();
		foreach ( array_merge( $primary, $secondary ) as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$this->w020_add_candidate( $out, $seen, $candidate );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,bool>             $seen
	 * @param array<string,mixed>            $candidate
	 */
	private function w020_add_candidate( array &$candidates, array &$seen, array $candidate ): void {
		$excerpt = trim( wp_strip_all_tags( (string) ( $candidate['excerpt'] ?? '' ) ) );
		if ( $excerpt === '' ) {
			return;
		}
		$citation = trim( (string) ( $candidate['citation'] ?? '' ) );
		$key = $citation !== ''
			? $citation
			: ( (int) ( $candidate['notebook_id'] ?? 0 ) . ':' . (int) ( $candidate['source_id'] ?? 0 ) . ':' . md5( mb_substr( $excerpt, 0, 180 ) ) );
		if ( isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		$candidate['excerpt'] = mb_substr( $excerpt, 0, 1800 );
		$candidates[] = $candidate;
	}

	/**
	 * @param array<int,string> $terms
	 * @return array<int,string>
	 */
	private function w020_terms_present_in_text( string $text, array $terms ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.2 — shared relation-candidate term matcher.
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( wp_strip_all_tags( $text ), 'UTF-8' ) : strtolower( wp_strip_all_tags( $text ) );
		$hits = array();
		foreach ( $this->w020_normalize_terms( $terms ) as $term ) {
			if ( $term !== '' && strpos( $text, $term ) !== false ) {
				$hits[] = $term;
			}
		}
		return array_values( array_unique( $hits ) );
	}

	private function w020_extract_passage_id_from_citation( string $citation ): int {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.21.2 — preserve nb citation identity for graph-derived candidates.
		if ( preg_match( '/\[nb:\d+\/p(\d+)\]/', $citation, $m ) ) {
			return (int) $m[1];
		}
		return 0;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<int,string>              $query_tokens
	 * @param array<int,array<string,mixed>> $graph_entities
	 * @return array<int,array<string,mixed>>
	 */
	private function w020_rerank_candidates( array $candidates, array $query_tokens, array $graph_entities ): array {
		$graph_terms = array();
		foreach ( $graph_entities as $entity ) {
			if ( is_array( $entity ) ) {
				$graph_terms[] = (string) ( $entity['name'] ?? '' );
			}
		}
		$graph_terms = $this->w020_normalize_terms( $graph_terms );

		foreach ( $candidates as &$candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$text = trim( (string) ( $candidate['source_title'] ?? '' ) . ' ' . (string) ( $candidate['excerpt'] ?? '' ) );
			$lexical_hits = $this->w020_count_term_hits( $text, $query_tokens );
			$graph_hits   = $this->w020_count_term_hits( $text, $graph_terms );
			$rank_bonus   = max( 0, 12 - (int) ( $candidate['rank'] ?? 0 ) );
			$vector_bonus = isset( $candidate['vector_score'] ) ? max( 0, (int) round( (float) $candidate['vector_score'] * 10 ) ) : 0;
			$score        = ( $lexical_hits * 4 ) + ( $graph_hits * 5 ) + min( 10, (int) ( $candidate['match_count'] ?? 0 ) * 2 ) + $rank_bonus + $vector_bonus;
			if ( trim( (string) ( $candidate['citation'] ?? '' ) ) !== '' ) {
				$score += 3;
			}
			$candidate['graph_overlap_count'] = $graph_hits;
			$candidate['lexical_overlap_count'] = $lexical_hits;
			$candidate['rerank_score'] = $score;
			$candidate['rerank_reason'] = $graph_hits > 0 ? 'graph_entity_overlap' : ( $lexical_hits > 0 ? 'query_token_overlap' : ( $vector_bonus > 0 ? 'vector_similarity' : 'search_rank' ) );
		}
		unset( $candidate );

		usort( $candidates, function ( $a, $b ) {
			$score_cmp = (int) ( $b['rerank_score'] ?? 0 ) - (int) ( $a['rerank_score'] ?? 0 );
			if ( $score_cmp !== 0 ) {
				return $score_cmp;
			}
			return (int) ( $a['rank'] ?? 999 ) - (int) ( $b['rank'] ?? 999 );
		} );

		return $candidates;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,mixed>            $opts
	 * @return array{candidates:array<int,array<string,mixed>>,method:string,degraded:bool,error:string}
	 */
	private function w020_apply_hub_rerank( string $query, array $candidates, int $top_k, array $opts ): array {
		$base = array( 'candidates' => $candidates, 'method' => 'local_hybrid_graph_lexical', 'degraded' => true, 'error' => '' );
		if ( ! empty( $opts['w020_skip_hub_rerank'] ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20.3 — DDV can assert pack shape without firing a live gateway call.
			$base['error'] = 'disabled_by_caller';
			return $base;
		}
		if ( $query === '' || empty( $candidates ) || ! class_exists( 'BizCity_LLM_Client' ) || ! method_exists( 'BizCity_LLM_Client', 'rerank' ) ) {
			$base['error'] = empty( $candidates ) ? 'no_candidates' : 'rerank_client_unavailable';
			return $base;
		}

		$wire = array();
		$by_id = array();
		foreach ( $candidates as $idx => $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$id = 'c' . ( $idx + 1 );
			$by_id[ $id ] = $candidate;
			$wire[] = array(
				'id'   => $id,
				'text' => trim( (string) ( $candidate['source_title'] ?? '' ) . "\n" . (string) ( $candidate['excerpt'] ?? '' ) ),
			);
		}
		if ( empty( $wire ) ) {
			$base['error'] = 'empty_wire_candidates';
			return $base;
		}

		try {
			$resp = BizCity_LLM_Client::instance()->rerank( $query, $wire, array(
				'top_k'      => min( max( 1, $top_k ), count( $wire ) ),
				'trace_id'   => (string) ( $opts['trace_id'] ?? '' ),
				'session_id' => (string) ( $opts['session_id'] ?? '' ),
				'purpose'    => 'twinbrain_w020_rerank',
				'timeout'    => 8,
			) );
		} catch ( \Throwable $e ) {
			$base['error'] = 'rerank_exception';
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][W0.20.3][rerank] ' . $e->getMessage() );
			}
			return $base;
		}

		if ( empty( $resp['success'] ) || empty( $resp['results'] ) || ! is_array( $resp['results'] ) ) {
			$base['error'] = (string) ( $resp['error_code'] ?? $resp['error'] ?? 'rerank_degraded' );
			return $base;
		}

		$out = array();
		$used = array();
		foreach ( (array) $resp['results'] as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$id = (string) ( $result['id'] ?? '' );
			if ( $id === '' || ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$row = $by_id[ $id ];
			$row['rerank_score'] = isset( $result['score'] ) ? (float) $result['score'] : (float) ( $row['rerank_score'] ?? 0 );
			$row['rerank_reason'] = (string) ( $result['reason'] ?? 'hub_branch_8' );
			$row['rerank_rank'] = isset( $result['rank'] ) ? (int) $result['rank'] : count( $out ) + 1;
			$out[] = $row;
			$used[ $id ] = true;
		}
		foreach ( $by_id as $id => $row ) {
			if ( ! isset( $used[ $id ] ) ) {
				$out[] = $row;
			}
		}

		return array( 'candidates' => $out, 'method' => 'hub_branch_8_rerank', 'degraded' => false, 'error' => '' );
	}

	/**
	 * @param array<int,string> $terms
	 * @return array<int,string>
	 */
	private function w020_normalize_terms( array $terms ): array {
		$out = array();
		foreach ( $terms as $term ) {
			$key = $this->w020_normalize_key( (string) $term );
			if ( $key !== '' ) {
				$out[ $key ] = $key;
			}
		}
		return array_values( $out );
	}

	/**
	 * @return array<int,string>
	 */
	private function w020_tokenize_text( string $text ): array {
		$text = $this->w020_normalize_key( $text );
		$parts = preg_split( '/[^a-z0-9\x{00C0}-\x{1EF9}]+/u', $text );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		$out = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( mb_strlen( $part ) >= 2 ) {
				$out[ $part ] = $part;
			}
		}
		return array_values( $out );
	}

	private function w020_normalize_key( string $text ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( $text === '' ) {
			return '';
		}
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return is_string( $text ) ? trim( $text ) : '';
	}

	/**
	 * @param array<int,string> $terms
	 */
	private function w020_count_term_hits( string $text, array $terms ): int {
		$haystack = $this->w020_normalize_key( $text );
		if ( $haystack === '' || empty( $terms ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $terms as $term ) {
			$term = $this->w020_normalize_key( (string) $term );
			if ( $term !== '' && strpos( $haystack, $term ) !== false ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 Cross-Notebook Graph (runtime, no schema change).
	 *
	 * Scans matched_tokens across all notebooks in the source map. When the same
	 * token appears in 2+ notebooks, it records a shared-entity link. The caller
	 * stores the result in the payload so Final Composer and FE can surface
	 * cross-notebook agreements/tensions without any new DB table.
	 *
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array<int,array<string,mixed>>
	 */
	private function compute_cross_notebook_links( array $source_map ): array {
		if ( count( $source_map ) < 2 ) {
			return array();
		}

		// 1. Collect tokens per notebook (from all passages)
		$nb_tokens = array();  // notebook_id => [ token => count ]
		$nb_titles = array();  // notebook_id => title
		$nb_citations = array(); // notebook_id => [ token => first_citation ]
		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$nb_id = (int) ( $row['notebook_id'] ?? 0 );
			if ( $nb_id <= 0 ) {
				continue;
			}
			$nb_titles[ $nb_id ] = (string) ( $row['title'] ?? 'Notebook #' . $nb_id );
			if ( ! isset( $nb_tokens[ $nb_id ] ) ) {
				$nb_tokens[ $nb_id ] = array();
				$nb_citations[ $nb_id ] = array();
			}
			foreach ( (array) ( $row['passages'] ?? array() ) as $passage ) {
				if ( ! is_array( $passage ) ) {
					continue;
				}
				$citation = trim( (string) ( $passage['token'] ?? '' ) );
				foreach ( (array) ( $passage['matched_tokens'] ?? array() ) as $tok ) {
					$tok = trim( (string) $tok );
					if ( $tok === '' ) {
						continue;
					}
					if ( ! isset( $nb_tokens[ $nb_id ][ $tok ] ) ) {
						$nb_tokens[ $nb_id ][ $tok ] = 0;
						if ( $citation !== '' ) {
							$nb_citations[ $nb_id ][ $tok ] = $citation;
						}
					}
					$nb_tokens[ $nb_id ][ $tok ]++;
				}
			}
		}

		// 2. Find tokens that appear in multiple notebooks
		$token_to_nbs = array(); // token => [nb_id, ...]
		foreach ( $nb_tokens as $nb_id => $tokens ) {
			foreach ( array_keys( $tokens ) as $tok ) {
				if ( ! isset( $token_to_nbs[ $tok ] ) ) {
					$token_to_nbs[ $tok ] = array();
				}
				$token_to_nbs[ $tok ][] = $nb_id;
			}
		}

		// 3. Build link records: one per notebook-pair that share ≥1 token
		$pair_links = array(); // "nb1,nb2" => [ ...link data... ]
		foreach ( $token_to_nbs as $tok => $nb_ids ) {
			$nb_ids = array_values( array_unique( $nb_ids ) );
			if ( count( $nb_ids ) < 2 ) {
				continue;
			}
			sort( $nb_ids );
			for ( $i = 0; $i < count( $nb_ids ) - 1; $i++ ) {
				for ( $j = $i + 1; $j < count( $nb_ids ); $j++ ) {
					$a = $nb_ids[ $i ];
					$b = $nb_ids[ $j ];
					$key = "{$a},{$b}";
					if ( ! isset( $pair_links[ $key ] ) ) {
						$pair_links[ $key ] = array(
							'notebook_ids'   => array( $a, $b ),
							'notebook_titles' => array( $nb_titles[ $a ] ?? '', $nb_titles[ $b ] ?? '' ),
							'shared_tokens'  => array(),
							'citations_a'    => array(),
							'citations_b'    => array(),
							'link_reason'    => 'shared_entity',
						);
					}
					$pair_links[ $key ]['shared_tokens'][] = $tok;
					if ( isset( $nb_citations[ $a ][ $tok ] ) ) {
						$pair_links[ $key ]['citations_a'][] = $nb_citations[ $a ][ $tok ];
					}
					if ( isset( $nb_citations[ $b ][ $tok ] ) ) {
						$pair_links[ $key ]['citations_b'][] = $nb_citations[ $b ][ $tok ];
					}
				}
			}
		}

		// 4. Deduplicate token lists and sort by shared token count (most linked first)
		$links = array();
		foreach ( $pair_links as $link ) {
			$link['shared_tokens']  = array_values( array_unique( $link['shared_tokens'] ) );
			$link['citations_a']    = array_values( array_unique( $link['citations_a'] ) );
			$link['citations_b']    = array_values( array_unique( $link['citations_b'] ) );
			$link['shared_count']   = count( $link['shared_tokens'] );
			$links[] = $link;
		}
		usort( $links, function ( $a, $b ) {
			return (int) $b['shared_count'] - (int) $a['shared_count'];
		} );

		return array_slice( $links, 0, 10 );
	}

	/**
	 * [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — N5 Admin Training Loop.
	 *
	 * Aggregates `source_gaps` from all weak/minimal source-file briefs,
	 * groups them by notebook, and produces a `training_gap_report[]` the
	 * Final Composer and FE can use to drive a training CTA.
	 *
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<int,array<string,mixed>> $source_file_briefs  from Source File Deep Layer
	 * @return array<int,array<string,mixed>>
	 */
	private function compile_training_gap_report( array $source_map, array $source_file_briefs ): array {
		if ( empty( $source_file_briefs ) ) {
			return array();
		}

		// Index notebook titles from source_map for fast lookup
		$nb_titles = array();
		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$nb_id = (int) ( $row['notebook_id'] ?? 0 );
			if ( $nb_id > 0 && ! isset( $nb_titles[ $nb_id ] ) ) {
				$nb_titles[ $nb_id ] = (string) ( $row['title'] ?? 'Notebook #' . $nb_id );
			}
		}

		// Group briefs by notebook_id, collect gaps from weak/minimal coverage
		$by_notebook = array(); // notebook_id => [...]
		foreach ( $source_file_briefs as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			$nb_id   = (int) ( $brief['notebook_id'] ?? 0 );
			$coverage = (string) ( $brief['coverage'] ?? 'strong' );
			$gaps     = (array) ( $brief['source_gaps'] ?? array() );
			$title    = (string) ( $brief['source_title'] ?? 'Source #' . (int) ( $brief['source_id'] ?? 0 ) );
			$used     = (int) ( $brief['passage_count_used'] ?? 0 );
			$total    = (int) ( $brief['passage_count_total'] ?? 0 );

			if ( $nb_id <= 0 ) {
				continue;
			}
			if ( ! in_array( $coverage, array( 'weak', 'minimal' ), true ) && empty( $gaps ) ) {
				continue;
			}

			if ( ! isset( $by_notebook[ $nb_id ] ) ) {
				$by_notebook[ $nb_id ] = array(
					'notebook_id'          => $nb_id,
					'notebook_title'       => $nb_titles[ $nb_id ] ?? 'Notebook #' . $nb_id,
					'weak_source_count'    => 0,
					'total_source_count'   => 0,
					'gap_sources'          => array(),
					'suggested_content'    => array(),
				);
			}
			$by_notebook[ $nb_id ]['total_source_count']++;
			if ( in_array( $coverage, array( 'weak', 'minimal' ), true ) ) {
				$by_notebook[ $nb_id ]['weak_source_count']++;
			}
			if ( $title !== '' ) {
				$coverage_label = $total > 0 ? "({$used}/{$total} đoạn)" : '';
				$by_notebook[ $nb_id ]['gap_sources'][] = trim( "{$title} [{$coverage}] {$coverage_label}" );
			}
			foreach ( $gaps as $gap ) {
				$gap = trim( wp_strip_all_tags( (string) $gap ) );
				if ( $gap !== '' ) {
					$by_notebook[ $nb_id ]['suggested_content'][] = $gap;
				}
			}
		}

		$report = array();
		foreach ( $by_notebook as $nb_id => $entry ) {
			$entry['gap_sources']       = array_values( array_unique( $entry['gap_sources'] ) );
			$entry['suggested_content'] = array_values( array_unique( $entry['suggested_content'] ) );
			$entry['gap_coverage_pct']  = $entry['total_source_count'] > 0
				? (int) round( ( $entry['weak_source_count'] / $entry['total_source_count'] ) * 100 )
				: 0;
			$report[] = $entry;
		}

		// Sort: most-weak notebooks first
		usort( $report, function ( $a, $b ) {
			return (int) $b['weak_source_count'] - (int) $a['weak_source_count'];
		} );

		return $report;
	}

	/**
	 * @param array<int,array<string,mixed>> $report
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<int,array<string,mixed>> $source_file_briefs
	 * @param array<int,array<string,mixed>> $product_entities
	 * @param array<string,mixed>            $opts
	 * @return array<int,array<string,mixed>>
	 */
	private function append_product_entity_training_gap( array $report, array $source_map, array $source_file_briefs, array $product_entities, array $opts ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — make missing product entity extraction visible in Training Gap Report.
		$query = (string) ( $opts['notebook_search_context_query'] ?? '' );
		if ( ! $this->looks_like_product_list_query( $query ) || ! empty( $product_entities ) ) {
			return $report;
		}
		if ( empty( $source_map ) && empty( $source_file_briefs ) ) {
			return $report;
		}

		$notebook_id = 0;
		$notebook_title = 'Notebook liên quan';
		foreach ( $source_map as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$notebook_id = (int) ( $row['notebook_id'] ?? 0 );
			$notebook_title = (string) ( $row['title'] ?? ( $notebook_id > 0 ? 'Notebook #' . $notebook_id : $notebook_title ) );
			break;
		}

		$gap_sources = array();
		foreach ( array_slice( $source_file_briefs, 0, 6 ) as $brief ) {
			if ( is_array( $brief ) && ! empty( $brief['source_title'] ) ) {
				$gap_sources[] = (string) $brief['source_title'] . ' [product_entities_not_extracted]';
			}
		}

		$report[] = array(
			'notebook_id'        => $notebook_id,
			'notebook_title'     => $notebook_title,
			'weak_source_count'  => 0,
			'total_source_count' => max( 1, count( $source_file_briefs ) ),
			'gap_sources'        => array_values( array_unique( $gap_sources ) ),
			'suggested_content'  => array( 'product_entities_not_extracted: bổ sung đoạn liệt kê tên dòng sữa cụ thể trong nội dung/source claims, không chỉ tiêu đề file.' ),
			'gap_coverage_pct'   => 100,
			'gap_bucket'         => 'product_entities_not_extracted',
		);

		return $report;
	}

	private function looks_like_product_list_query( string $query ): bool {
		$query_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$has_list = false;
		foreach ( array( 'kể tên', 'ke ten', 'liệt kê', 'liet ke', 'dòng nào', 'dong nao', 'sản phẩm nào', 'san pham nao', 'hãng nào', 'hang nao' ) as $needle ) {
			if ( strpos( $query_lc, $needle ) !== false ) {
				$has_list = true;
				break;
			}
		}
		return $has_list && ( strpos( $query_lc, 'sữa' ) !== false || strpos( $query_lc, 'sua' ) !== false || strpos( $query_lc, 'sản phẩm' ) !== false || strpos( $query_lc, 'san pham' ) !== false );
	}

	/**
	 * Add Search Core hits to the current Notebook source map so final composer
	 * receives source-file context beyond the first perspective snippets.
	 *
	 * @param array<int,array<string,mixed>> $source_map
	 * @param array<int,array<string,mixed>> $candidate_index
	 * @param array<string,mixed>            $opts
	 * @return array<int,array<string,mixed>>
	 */
	private function augment_source_map_with_search_context( array $source_map, array $candidate_index, array $opts, array &$search_context_payload ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — Search Core becomes the top-10 retrieval layer for LLM context and FE trace.
		$query = trim( (string) ( $opts['notebook_search_context_query'] ?? $opts['prompt'] ?? '' ) );
		if ( $query === '' || ! class_exists( 'BizCity_TwinSearch_Core' ) ) {
			return $source_map;
		}
		$core = BizCity_TwinSearch_Core::instance();
		$search_context_payload = $this->build_search_context_payload( $core, $query, $opts );
		$hits = isset( $search_context_payload['results'] ) && is_array( $search_context_payload['results'] ) ? $search_context_payload['results'] : array();
		if ( empty( $hits ) ) {
			return $source_map;
		}

		$by_notebook = array();
		foreach ( $source_map as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$nb = (int) ( $row['notebook_id'] ?? 0 );
			if ( $nb > 0 ) {
				$by_notebook[ $nb ] = $idx;
			}
		}

		$hits_by_notebook = array();
		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$notebook_id = (int) ( $hit['notebook_id'] ?? 0 );
			if ( $notebook_id <= 0 ) {
				continue;
			}
			if ( ! isset( $hits_by_notebook[ $notebook_id ] ) ) {
				$hits_by_notebook[ $notebook_id ] = array();
			}
			$hits_by_notebook[ $notebook_id ][] = $hit;
		}

		foreach ( $hits_by_notebook as $notebook_id => $notebook_hits ) {
			if ( ! isset( $by_notebook[ $notebook_id ] ) ) {
				$source_map[] = $this->empty_source_map_row_for_notebook( (int) $notebook_id, isset( $candidate_index[ $notebook_id ] ) ? $candidate_index[ $notebook_id ] : array(), isset( $notebook_hits[0] ) && is_array( $notebook_hits[0] ) ? $notebook_hits[0] : array() );
				$by_notebook[ $notebook_id ] = count( $source_map ) - 1;
			}
			$idx = $by_notebook[ $notebook_id ];
			$source_map[ $idx ]['passages'] = $this->append_search_hits_to_passages( (array) ( $source_map[ $idx ]['passages'] ?? array() ), (int) $notebook_id, $notebook_hits );
		}

		return $this->refresh_source_map_coverage( $source_map );
	}

	/**
	 * @param BizCity_TwinSearch_Core $core
	 * @return array<string,mixed>
	 */
	private function build_search_context_payload( $core, string $query, array $opts ): array {
		$payload = array( 'query' => $query, 'scope' => '', 'total' => 0, 'top_n' => 0, 'tokens' => array(), 'results' => array() );
		if ( ! is_object( $core ) || ! method_exists( $core, 'search_documents' ) ) {
			return $payload;
		}
		$forced_ids = isset( $opts['force_notebooks'] ) && is_array( $opts['force_notebooks'] )
			? array_values( array_unique( array_filter( array_map( 'intval', $opts['force_notebooks'] ) ) ) )
			: array();
		$scope = isset( $opts['notebook_search_context_scope'] ) ? sanitize_key( (string) $opts['notebook_search_context_scope'] ) : '';
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );
		$notebook_id = count( $forced_ids ) === 1 ? (int) $forced_ids[0] : 0;
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.11 — when guru/character is set, use character scope so
		// TwinSearch finds guru-attached notebooks (not just user-owned notebooks via scope='user').
		// Previously scope='user' returned empty results for guru-attached notebooks → search_context_results=[].
		if ( $scope === '' ) {
			$_guru_id   = (int) ( $opts['guru_id'] ?? $opts['character_id'] ?? 0 );
			$_char_uuid = (string) ( $opts['character_uuid'] ?? '' );
			if ( $notebook_id > 0 ) {
				$scope = 'notebook';
			} elseif ( $_guru_id > 0 || $_char_uuid !== '' ) {
				$scope = 'character';
			} elseif ( $user_id > 0 ) {
				$scope = 'user';
			} else {
				$scope = 'blog';
			}
		}
		if ( ! in_array( $scope, array( 'notebook', 'user', 'blog', 'character' ), true ) ) {
			$scope = $user_id > 0 ? 'user' : 'blog';
		}
		$top_n = isset( $opts['notebook_search_context_top_n'] ) ? max( 1, min( 20, (int) $opts['notebook_search_context_top_n'] ) ) : 10;
		$result = $core->search_documents( array(
			'query'           => $query,
			'scope'           => $scope,
			'notebook_id'     => $notebook_id,
			'user_id'         => $user_id,
			'character_id'    => (int) ( $opts['guru_id'] ?? $opts['character_id'] ?? 0 ),
			'character_uuid'  => (string) ( $opts['character_uuid'] ?? '' ),
			'page'            => 1,
			'per_page'        => $top_n,
			'include_content' => true,
		) );
		$hits = isset( $result['results'] ) && is_array( $result['results'] ) ? $result['results'] : array();
		$tokens = isset( $result['tokens'] ) && is_array( $result['tokens'] ) ? array_values( array_filter( array_map( 'strval', $result['tokens'] ) ) ) : array();
		$normalized = array();
		$rank = 1;
		foreach ( $hits as $hit ) {
			if ( is_array( $hit ) ) {
				$normalized[] = $this->normalize_search_context_hit( $hit, $tokens, $rank );
				$rank++;
			}
		}
		$payload['scope'] = $scope;
		$payload['total'] = (int) ( $result['total'] ?? count( $normalized ) );
		$payload['top_n'] = $top_n;
		$payload['tokens'] = $tokens;
		$payload['results'] = $normalized;
		return $payload;
	}

	/**
	 * @param array<string,mixed> $hit
	 * @param array<int,string>   $tokens
	 * @return array<string,mixed>
	 */
	private function normalize_search_context_hit( array $hit, array $tokens, int $rank ): array {
		$source_id = (int) ( $hit['source_id'] ?? 0 );
		if ( $source_id >= 1000000000 ) {
			$source_id = 0;
		}
		$context_excerpt = $this->build_search_hit_excerpt( $hit );
		return array(
			'rank'             => $rank,
			'notebook_id'      => (int) ( $hit['notebook_id'] ?? 0 ),
			'notebook_title'   => (string) ( $hit['notebook_title'] ?? '' ),
			'source_id'        => $source_id,
			'source_title'     => (string) ( $hit['source_title'] ?? $hit['title'] ?? '' ),
			'origin_kind'      => (string) ( $hit['origin_kind'] ?? '' ),
			'origin_url'       => $this->normalize_source_url( $hit['origin_url'] ?? '' ),
			'first_passage_id' => (int) ( $hit['first_passage_id'] ?? 0 ),
			'citation'         => (string) ( $hit['citation'] ?? '' ),
			'match_count'      => (int) ( $hit['match_count'] ?? 0 ),
			'document_chars'   => (int) ( $hit['document_chars'] ?? 0 ),
			'snippet'          => mb_substr( trim( wp_strip_all_tags( (string) ( $hit['highlight_quote'] ?? $hit['snippet'] ?? '' ) ) ), 0, 420 ),
			'context_excerpt'  => $context_excerpt,
			'matched_tokens'   => $tokens,
		);
	}

	/**
	 * @param array<string,mixed> $hit
	 */
	private function build_search_hit_excerpt( array $hit ): string {
		$snippet = trim( wp_strip_all_tags( (string) ( $hit['highlight_quote'] ?? $hit['snippet'] ?? '' ) ) );
		$document = trim( wp_strip_all_tags( (string) ( $hit['document_text'] ?? '' ) ) );
		if ( $document !== '' ) {
			$context = mb_substr( $document, 0, 1800 );
			if ( $snippet !== '' && strpos( $context, $snippet ) === false ) {
				return mb_substr( 'Search match: ' . $snippet . "\nSource context: " . $context, 0, 2200 );
			}
			return mb_substr( $context, 0, 2200 );
		}
		return mb_substr( $snippet, 0, 900 );
	}

	/**
	 * @param array<string,mixed> $candidate
	 * @return array<string,mixed>
	 */
	private function empty_source_map_row_for_notebook( int $notebook_id, array $candidate, array $hit = array() ): array {
		$title = $this->first_non_empty( array(
			(string) ( $candidate['label'] ?? '' ),
			(string) ( $hit['notebook_title'] ?? '' ),
			'Notebook #' . $notebook_id,
		) );
		return array(
			'notebook_id'      => $notebook_id,
			'title'            => $title,
			'label'            => $title,
			'selection_reason' => (string) ( $candidate['reason'] ?? 'search_context_hit' ),
			'score'            => isset( $candidate['score'] ) ? (float) $candidate['score'] : 0.0,
			'guru_bound'       => false,
			'coverage'         => array( 'passage_count' => 0, 'matched_token_count' => 0, 'source_files' => array(), 'confidence' => 'weak' ),
			'passages'         => array(),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $passages
	 * @param array<int,array<string,mixed>> $hits
	 * @return array<int,array<string,mixed>>
	 */
	private function append_search_hits_to_passages( array $passages, int $notebook_id, array $hits ): array {
		$seen = array();
		foreach ( $passages as $passage ) {
			$pid = is_array( $passage ) ? (int) ( $passage['passage_id'] ?? 0 ) : 0;
			if ( $pid > 0 ) {
				$seen[ $pid ] = true;
			}
		}
		foreach ( $hits as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$passage_id = (int) ( $hit['first_passage_id'] ?? 0 );
			if ( $passage_id <= 0 || isset( $seen[ $passage_id ] ) ) {
				continue;
			}
			$source_id = (int) ( $hit['source_id'] ?? 0 );
			if ( $source_id >= 1000000000 ) {
				$source_id = 0;
			}
			$excerpt = trim( wp_strip_all_tags( (string) ( $hit['context_excerpt'] ?? $hit['highlight_quote'] ?? $hit['snippet'] ?? '' ) ) );
			if ( $excerpt === '' ) {
				continue;
			}
			$seen[ $passage_id ] = true;
			$passages[] = array(
				'token'          => sprintf( '[nb:%d/p%d]', $notebook_id, $passage_id ),
				'passage_id'     => $passage_id,
				'source_id'      => $source_id,
				'chunk_id'       => 0,
				'source_title'   => (string) ( $hit['source_title'] ?? $hit['title'] ?? '' ),
				'source_type'    => (string) ( $hit['origin_kind'] ?? '' ),
				'matched_tokens' => array_values( array_unique( array_filter( array_map( 'strval', (array) ( $hit['matched_tokens'] ?? array() ) ) ) ) ),
				'excerpt'        => mb_substr( $excerpt, 0, 1600 ),
				'rank_reason'    => 'search_context_hit',
				'search_match_count' => (int) ( $hit['match_count'] ?? 0 ),
				'search_result_rank' => (int) ( $hit['rank'] ?? 0 ),
			);
		}
		return $passages;
	}

	/**
	 * @param array<int,array<string,mixed>> $source_map
	 * @return array<int,array<string,mixed>>
	 */
	private function refresh_source_map_coverage( array $source_map ): array {
		foreach ( $source_map as &$row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$matched_tokens = array();
			$source_files = array();
			$search_hits = 0;
			foreach ( (array) ( $row['passages'] ?? array() ) as $passage ) {
				if ( ! is_array( $passage ) ) {
					continue;
				}
				foreach ( (array) ( $passage['matched_tokens'] ?? array() ) as $tok ) {
					$tok = trim( (string) $tok );
					if ( $tok !== '' ) {
						$matched_tokens[ $tok ] = true;
					}
				}
				$source_title = trim( (string) ( $passage['source_title'] ?? '' ) );
				if ( $source_title !== '' ) {
					$source_files[ $source_title ] = true;
				}
				if ( (string) ( $passage['rank_reason'] ?? '' ) === 'search_context_hit' ) {
					$search_hits++;
				}
			}
			$passage_count = count( (array) ( $row['passages'] ?? array() ) );
			$confidence = 'weak';
			if ( $passage_count >= 3 && ( ! empty( $matched_tokens ) || $search_hits > 0 ) ) {
				$confidence = 'strong';
			} elseif ( $passage_count > 0 ) {
				$confidence = 'medium';
			}
			$row['coverage'] = array(
				'passage_count'        => $passage_count,
				'matched_token_count'  => count( $matched_tokens ),
				'search_context_count' => $search_hits,
				'source_files'         => array_values( array_keys( $source_files ) ),
				'confidence'           => $confidence,
			);
		}
		unset( $row );
		return $source_map;
	}

	/**
	 * @param array<int,array<string,mixed>> $answers
	 * @return array<int,array{notebook_id:int,passage_id:int}>
	 */
	private function collect_passage_refs( array $answers ): array {
		$refs = array();
		$seen = array();
		foreach ( $answers as $answer ) {
			if ( ! is_array( $answer ) ) {
				continue;
			}
			$notebook_id = (int) ( $answer['notebook_id'] ?? 0 );
			foreach ( (array) ( $answer['citations'] ?? array() ) as $citation ) {
				if ( ! is_array( $citation ) ) {
					continue;
				}
				$nb = (int) ( $citation['notebook_id'] ?? $notebook_id );
				$pp = (int) ( $citation['passage_id'] ?? 0 );
				$this->append_ref( $refs, $seen, $nb, $pp );
			}
			if ( preg_match_all( '/\[nb:(\d+)\/p(\d+)\]/', (string) ( $answer['answer_md'] ?? '' ), $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$this->append_ref( $refs, $seen, (int) $m[1], (int) $m[2] );
				}
			}
		}
		return $refs;
	}

	/**
	 * @param array<int,array{notebook_id:int,passage_id:int}> $refs
	 * @param array<string,bool>                               $seen
	 */
	private function append_ref( array &$refs, array &$seen, int $notebook_id, int $passage_id ): void {
		if ( $notebook_id <= 0 || $passage_id <= 0 ) {
			return;
		}
		$key = $notebook_id . ':' . $passage_id;
		if ( isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;
		$refs[] = array( 'notebook_id' => $notebook_id, 'passage_id' => $passage_id );
	}

	/**
	 * @param array<int,array{notebook_id:int,passage_id:int}> $refs
	 * @return array<string,array<string,mixed>>
	 */
	private function fetch_passage_meta( array $refs ): array {
		global $wpdb;
		if ( empty( $refs ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}
		$db = BizCity_KG_Database::instance();
		if ( ! method_exists( $db, 'tbl_passages' ) ) {
			return array();
		}

		$ids = array();
		foreach ( $refs as $ref ) {
			$ids[] = (int) $ref['passage_id'];
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$tbl = $db->tbl_passages();
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, source_id, chunk_id, origin, content, metadata,
			        storage_ver, file_shard, file_offset, file_length
			 FROM {$tbl}
			 WHERE id IN ({$ph})",
			$ids
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		$out = array();
		foreach ( $rows as $row ) {
			$nb = (int) ( $row['notebook_id'] ?? 0 );
			$pp = (int) ( $row['id'] ?? 0 );
			if ( $nb <= 0 || $pp <= 0 ) {
				continue;
			}
			$out[ $nb . ':' . $pp ] = $row;
		}
		return $out;
	}

	/**
	 * @param int                         $notebook_id
	 * @param array<string,mixed>         $answer
	 * @param array<string,array<string,mixed>> $passage_meta
	 * @return array<int,array<string,mixed>>
	 */
	private function build_passage_rows_for_answer( int $notebook_id, array $answer, array $passage_meta ): array {
		$snippets = $this->extract_answer_snippets( (string) ( $answer['answer_md'] ?? '' ) );
		$rows     = array();
		$seen     = array();

		foreach ( (array) ( $answer['citations'] ?? array() ) as $citation ) {
			if ( ! is_array( $citation ) ) {
				continue;
			}
			$nb = (int) ( $citation['notebook_id'] ?? $notebook_id );
			$pp = (int) ( $citation['passage_id'] ?? 0 );
			if ( $nb !== $notebook_id || $pp <= 0 ) {
				continue;
			}
			$this->append_passage_row( $rows, $seen, $notebook_id, $pp, (array) ( $citation['matched_tokens'] ?? array() ), $snippets, $passage_meta, (string) ( $citation['rank_reason'] ?? 'perspective_citation' ) );
		}

		if ( preg_match_all( '/\[nb:' . preg_quote( (string) $notebook_id, '/' ) . '\/p(\d+)\]/', (string) ( $answer['answer_md'] ?? '' ), $matches ) ) {
			foreach ( $matches[1] as $pid ) {
				$this->append_passage_row( $rows, $seen, $notebook_id, (int) $pid, array(), $snippets, $passage_meta, 'perspective_citation' );
			}
		}

		return $rows;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<string,bool>             $seen
	 * @param array<string,string>           $snippets
	 * @param array<string,array<string,mixed>> $passage_meta
	 */
	private function append_passage_row( array &$rows, array &$seen, int $notebook_id, int $passage_id, array $matched_tokens, array $snippets, array $passage_meta, string $rank_reason = 'perspective_citation' ): void {
		$key = $notebook_id . ':' . $passage_id;
		if ( isset( $seen[ $key ] ) ) {
			return;
		}
		$seen[ $key ] = true;

		$meta    = isset( $passage_meta[ $key ] ) ? $passage_meta[ $key ] : array();
		$content = trim( (string) ( $meta['content'] ?? '' ) );
		$snippet = isset( $snippets[ $key ] ) ? (string) $snippets[ $key ] : '';
		$excerpt = $content !== '' ? mb_substr( wp_strip_all_tags( $content ), 0, 420 ) : mb_substr( wp_strip_all_tags( $snippet ), 0, 420 );
		$decoded = $this->decode_json_array( (string) ( $meta['metadata'] ?? '' ) );

		$rows[] = array(
			'token'          => sprintf( '[nb:%d/p%d]', $notebook_id, $passage_id ),
			'passage_id'     => $passage_id,
			'source_id'      => (int) ( $meta['source_id'] ?? 0 ),
			'chunk_id'       => (int) ( $meta['chunk_id'] ?? 0 ),
			'source_title'   => $this->resolve_source_title( $decoded, (string) ( $meta['origin'] ?? '' ) ),
			'source_type'    => (string) ( $meta['origin'] ?? '' ),
			'matched_tokens' => array_values( array_unique( array_filter( array_map( 'strval', $matched_tokens ) ) ) ),
			'excerpt'        => $excerpt,
			'rank_reason'    => $rank_reason !== '' ? $rank_reason : 'perspective_citation',
		);
	}

	/**
	 * @param string $answer_md
	 * @return array<string,string>
	 */
	private function extract_answer_snippets( string $answer_md ): array {
		$out = array();
		if ( preg_match_all( '/\[nb:(\d+)\/p(\d+)\]\s*([^\[]*)/u', $answer_md, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$key = (int) $m[1] . ':' . (int) $m[2];
				$out[ $key ] = trim( (string) $m[3] );
			}
		}
		return $out;
	}

	/**
	 * @param string $raw
	 * @return array<string,mixed>
	 */
	private function decode_json_array( string $raw ): array {
		if ( trim( $raw ) === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array<string,mixed> $metadata
	 * @param string              $origin
	 * @return string
	 */
	private function resolve_source_title( array $metadata, string $origin ): string {
		$keys = array( 'source_title', 'title', 'file_name', 'filename', 'name', 'url' );
		foreach ( $keys as $key ) {
			if ( ! empty( $metadata[ $key ] ) && is_scalar( $metadata[ $key ] ) ) {
				return mb_substr( trim( (string) $metadata[ $key ] ), 0, 160 );
			}
		}
		return $origin !== '' ? mb_substr( $origin, 0, 160 ) : '';
	}

	/**
	 * @param array<int,array<string,mixed>> $search_context_results
	 * @param array<int,array<string,mixed>> $source_file_briefs
	 * @return array<int,array<string,mixed>>
	 */
	private function extract_product_entities( array $search_context_results, array $source_file_briefs ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — build structured product entity evidence before Final Composer.
		$entities = array();
		$seen     = array();
		$push = function ( $name, array $ctx ) use ( &$entities, &$seen ) {
			$name = $this->clean_product_entity_name( (string) $name );
			if ( ! $this->is_valid_product_entity_name( $name ) ) {
				return;
			}
			$excerpt = trim( wp_strip_all_tags( (string) ( $ctx['evidence_excerpt'] ?? '' ) ) );
			$class = $this->classify_product_entity( $name, $excerpt );
			if ( (string) ( $class['entity_type'] ?? '' ) === 'claim_phrase' ) {
				return;
			}
			$normalized = $this->normalize_product_entity_name( $name );
			if ( $normalized === '' || isset( $seen[ $normalized ] ) ) {
				return;
			}
			$seen[ $normalized ] = true;
			$citation = $this->normalize_citation_token( (string) ( $ctx['citation'] ?? '' ) );
			$confidence = (string) ( $class['confidence'] ?? 'medium' );
			if ( $citation === '' && $confidence === 'high' ) {
				$confidence = 'medium';
			}

			$entities[] = array(
				'name'             => mb_substr( $name, 0, 140 ),
				'normalized_name'  => $normalized,
				'entity_type'      => (string) ( $class['entity_type'] ?? 'category_trait' ),
				'is_product_name'  => ! empty( $class['is_product_name'] ),
				'source_title'     => mb_substr( trim( wp_strip_all_tags( (string) ( $ctx['source_title'] ?? '' ) ) ), 0, 160 ),
				'notebook_title'   => mb_substr( trim( wp_strip_all_tags( (string) ( $ctx['notebook_title'] ?? '' ) ) ), 0, 140 ),
				'source_id'        => (int) ( $ctx['source_id'] ?? 0 ),
				'passage_id'       => (int) ( $ctx['passage_id'] ?? 0 ),
				'citation'         => $citation,
				'evidence_excerpt' => mb_substr( $excerpt, 0, 360 ),
				'confidence'       => $confidence,
				'match_reason'     => mb_substr( trim( (string) ( $ctx['match_reason'] ?? 'milk_phrase_in_evidence' ) ), 0, 120 ),
			);
		};

		foreach ( array_slice( $search_context_results, 0, 12 ) as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$excerpt = $this->first_non_empty( array( (string) ( $hit['context_excerpt'] ?? '' ), (string) ( $hit['snippet'] ?? '' ) ) );
			foreach ( $this->extract_product_names_from_text( $excerpt ) as $name ) {
				$push( $name, array(
					'source_title'     => (string) ( $hit['source_title'] ?? '' ),
					'notebook_title'   => (string) ( $hit['notebook_title'] ?? '' ),
					'source_id'        => (int) ( $hit['source_id'] ?? 0 ),
					'passage_id'       => (int) ( $hit['first_passage_id'] ?? 0 ),
					'citation'         => (string) ( $hit['citation'] ?? '' ),
					'evidence_excerpt' => $excerpt,
					'match_reason'     => 'search_context_result_excerpt',
				) );
			}
		}

		foreach ( array_slice( $source_file_briefs, 0, 12 ) as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			$source_title = (string) ( $brief['source_title'] ?? '' );
			$notebook_id  = (int) ( $brief['notebook_id'] ?? 0 );
			$source_id    = (int) ( $brief['source_id'] ?? 0 );
			$citations    = array_values( (array) ( $brief['key_citations'] ?? array() ) );
			foreach ( array_slice( (array) ( $brief['source_claims'] ?? array() ), 0, 8 ) as $claim ) {
				$excerpt = trim( wp_strip_all_tags( (string) $claim ) );
				foreach ( $this->extract_product_names_from_text( $excerpt ) as $name ) {
					$push( $name, array(
						'source_title'     => $source_title,
						'notebook_title'   => 'Notebook #' . $notebook_id,
						'source_id'        => $source_id,
						'passage_id'       => 0,
						'citation'         => (string) ( $citations[0] ?? '' ),
						'evidence_excerpt' => $excerpt,
						'match_reason'     => 'source_claim',
					) );
				}
			}
			foreach ( array_slice( (array) ( $brief['search_context_hits'] ?? array() ), 0, 10 ) as $hit ) {
				if ( ! is_array( $hit ) ) {
					continue;
				}
				$excerpt = trim( wp_strip_all_tags( (string) ( $hit['excerpt'] ?? '' ) ) );
				foreach ( $this->extract_product_names_from_text( $excerpt ) as $name ) {
					$citation = (string) ( $hit['token'] ?? ( $citations[0] ?? '' ) );
					$ref = $this->parse_notebook_citation_ref( $citation );
					$push( $name, array(
						'source_title'     => $source_title,
						'notebook_title'   => 'Notebook #' . $notebook_id,
						'source_id'        => $source_id,
						'passage_id'       => (int) ( $ref['passage_id'] ?? 0 ),
						'citation'         => $citation,
						'evidence_excerpt' => $excerpt,
						'match_reason'     => 'source_file_search_context_hit',
					) );
				}
			}
		}

		usort( $entities, function ( $a, $b ) {
			$rank = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
			$ra = ( ! empty( $a['is_product_name'] ) ? 10 : 0 ) + ( isset( $rank[ (string) ( $a['confidence'] ?? '' ) ] ) ? $rank[ (string) $a['confidence'] ] : 0 );
			$rb = ( ! empty( $b['is_product_name'] ) ? 10 : 0 ) + ( isset( $rank[ (string) ( $b['confidence'] ?? '' ) ] ) ? $rank[ (string) $b['confidence'] ] : 0 );
			if ( $ra === $rb ) {
				return mb_strlen( (string) ( $a['name'] ?? '' ) ) <=> mb_strlen( (string) ( $b['name'] ?? '' ) );
			}
			return $rb <=> $ra;
		} );

		return array_slice( $entities, 0, 12 );
	}

	/** @return array<int,string> */
	private function extract_product_names_from_text( string $text ): array {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( $text === '' ) {
			return array();
		}
		$names = array();
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.16 — prefer brand/product-like names over generic phrases beginning with "sữa".
		$brand_pattern = '/\b(HiPP|Kendamil|Bellamy\'?s|LittleOak|Meiji|Aptamil|Friso|NAN|Enfamil|Morinaga|Glico|Similac|Blackmores|S-26|Nutifood|Vinamilk|Dielac|ColosBaby|Optimum\s+Gold)(?:\s+(?:Goat|A2|Organic|Premium|Gold|Comfort|Gentle|Pro|Plus|Infant|Follow[- ]?On|Toddler|OPO)){0,4}\b/iu';
		if ( preg_match_all( $brand_pattern, $text, $brand_matches ) ) {
			foreach ( (array) ( $brand_matches[0] ?? array() ) as $raw ) {
				$name = $this->clean_product_entity_name( (string) $raw );
				if ( $this->is_valid_product_entity_name( $name ) ) {
					$names[] = $name;
				}
			}
		}
		if ( preg_match_all( '/(?:các\s+dòng\s+|dòng\s+)?(sữa\s+[^\.,;:\n\(\)\[\]]{2,110})/iu', $text, $matches ) ) {
			foreach ( (array) ( $matches[1] ?? array() ) as $raw ) {
				$name = $this->clean_product_entity_name( (string) $raw );
				if ( $this->is_valid_product_entity_name( $name ) ) {
					$names[] = $name;
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	private function clean_product_entity_name( string $name ): string {
		$name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );
		$name = preg_replace( '/^(các\s+dòng\s+|dòng\s+)/iu', '', $name );
		$name = preg_split( '/\s+(?:giúp|khi|được\s+nhắc|được\s+tìm|phù\s+hợp|cho\s+thấy|là\s+một|có\s+khả\s+năng)\b/iu', $name )[0] ?? $name;
		$name = trim( $name, " \t\n\r\0\x0B-–—:;,.'\"" );
		$words = preg_split( '/\s+/u', $name );
		if ( is_array( $words ) && count( $words ) > 14 ) {
			$name = implode( ' ', array_slice( $words, 0, 14 ) );
		}
		return trim( $name );
	}

	private function is_valid_product_entity_name( string $name ): bool {
		$name = trim( $name );
		if ( $name === '' || mb_strlen( $name ) < 6 ) {
			return false;
		}
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$reject_fragments = array( 'top 10', 'chủ đề', 'chu de', 'bảng cập nhật', 'bang cap nhat', 'hôm qua', 'hom qua', 'nguyễn', 'nguyen', 'thu trang', 'táo bón chức năng', 'tao bon chuc nang' );
		foreach ( $reject_fragments as $fragment ) {
			if ( strpos( $lc, $fragment ) !== false ) {
				return false;
			}
		}
		$too_generic = array( 'sữa', 'sữa công thức', 'sữa cho bé', 'sữa bột', 'sữa mẹ' );
		if ( in_array( $lc, $too_generic, true ) ) {
			return false;
		}
		if ( preg_match( '/\b(hipp|kendamil|bellamy|littleoak|meiji|aptamil|friso|nan|enfamil|morinaga|glico|similac|blackmores|s-26|nutifood|vinamilk|dielac|colosbaby|optimum\s+gold)\b/i', $lc ) ) {
			return true;
		}
		return strpos( $lc, 'sữa' ) !== false || strpos( $lc, 'sua' ) !== false;
	}

	/** @return array{entity_type:string,is_product_name:bool,confidence:string} */
	private function classify_product_entity( string $name, string $excerpt ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.16 — separate real product names from traits/claims.
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$brand_re = '/\b(hipp|kendamil|bellamy|littleoak|meiji|aptamil|friso|nan|enfamil|morinaga|glico|similac|blackmores|s-26|nutifood|vinamilk|dielac|colosbaby|optimum\s+gold)\b/i';
		if ( preg_match( $brand_re, $name ) ) {
			return array( 'entity_type' => 'product_name', 'is_product_name' => true, 'confidence' => 'high' );
		}
		if ( preg_match( '/\b(A2|OPO|GOS\/FOS|Lactoferrin|Organic|Premium|Clean Label)\b/iu', $name . ' ' . $excerpt ) && preg_match( '/sữa\s+(dê|hữu\s+cơ|organic|a2|có|bổ\s+sung|chứa|ứng\s+dụng)/iu', $name ) ) {
			return array( 'entity_type' => 'category_trait', 'is_product_name' => false, 'confidence' => 'medium' );
		}
		$claim_fragments = array( 'vẫn táo bón', 'van tao bon', 'bột thông thường', 'bot thong thuong', 'dùng đâu', 'dung dau', 'thanh mát', 'thanh mat', 'trị táo bón', 'tri tao bon', 'cho bé', 'cho be', 'công thức', 'cong thuc' );
		foreach ( $claim_fragments as $fragment ) {
			if ( strpos( $lc, $fragment ) !== false ) {
				return array( 'entity_type' => 'claim_phrase', 'is_product_name' => false, 'confidence' => 'low' );
			}
		}
		return array( 'entity_type' => 'claim_phrase', 'is_product_name' => false, 'confidence' => 'low' );
	}

	private function normalize_product_entity_name( string $name ): string {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$name = preg_replace( '/\s+/u', ' ', trim( $name ) );
		return is_string( $name ) ? $name : '';
	}

	/** @return array{notebook_id:int,passage_id:int} */
	private function parse_notebook_citation_ref( string $citation ): array {
		if ( preg_match( '/\[?nb:(\d+)\/p(\d+)\]?/i', trim( $citation ), $m ) ) {
			return array( 'notebook_id' => (int) $m[1], 'passage_id' => (int) $m[2] );
		}
		return array( 'notebook_id' => 0, 'passage_id' => 0 );
	}

	private function normalize_citation_token( string $citation ): string {
		$ref = $this->parse_notebook_citation_ref( $citation );
		if ( (int) $ref['notebook_id'] > 0 && (int) $ref['passage_id'] > 0 ) {
			return '[nb:' . (int) $ref['notebook_id'] . '/p' . (int) $ref['passage_id'] . ']';
		}
		return '';
	}

	/**
	 * @param array<int,string> $values
	 * @return string
	 */
	private function first_non_empty( array $values ): string {
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( $value !== '' ) {
				return $value;
			}
		}
		return '';
	}
}