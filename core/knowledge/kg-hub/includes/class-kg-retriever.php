<?php
/**
 * Bizcity Twin AI — KG_Retriever
 *
 * 5-step Graph RAG pipeline (port from vector-graph-rag):
 *   1. Retrieve Seeds  — embed query, vector search top-K entities + relations
 *   2. Expand Subgraph — 1-hop expansion around seeds
 *   3. LLM Rerank      — pick top-K relations
 *   4. Generate Answer — LLM answer using passages of selected relations
 *
 * Returns a payload shape that mirrors vector-graph-rag's QueryResult so
 * the React UI can be ported with minimal adaptation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Retriever {

	const ANSWER_MODEL_OPTION = 'bizcity_kg_answer_model';
	const DEFAULT_ANSWER_MODEL = 'gpt-4o-mini';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Run the full pipeline.
	 *
	 * @param int    $notebook_id
	 * @param string $question
	 * @param array  $opts  ['seed_entities'=>4, 'seed_relations'=>20, 'rerank_top_k'=>5, 'expand_hops'=>1]
	 * @return array  QueryResult-shaped payload
	 */
	public function ask( $notebook_id, $question, array $opts = [] ) {
		$opts = array_merge( [
			'seed_entities'  => 4,
			'seed_relations' => 20,
			'rerank_top_k'   => 5,
			'expand_hops'    => 1,
			'answer'         => true,
		], $opts );

		$result = [
			'query'             => $question,
			'answer'            => '',
			'query_entities'    => [],
			'retrieval_detail'  => [
				'entity_ids'      => [], 'entity_texts' => [], 'entity_scores' => [],
				'relation_ids'    => [], 'relation_texts' => [], 'relation_scores' => [],
			],
			'expanded_relations'=> [],
			'reranked_relations'=> [],
			'rerank_result'     => [ 'selected_relation_ids' => [], 'selected_relation_texts' => [] ],
			'subgraph'          => [ 'nodes' => [], 'links' => [], 'seed_node_ids' => [], 'seed_link_ids' => [], 'expanded_node_ids' => [], 'expanded_link_ids' => [], 'selected_link_ids' => [] ],
			'passages'          => [],
			'steps'             => [],
		];

		$index = BizCity_KG_Vector_Index::instance();

		// ── Step 0: query embedding
		$qvec = $index->embed( $question );
		if ( is_wp_error( $qvec ) ) {
			// Degraded mode: vector embedding API unavailable (e.g. OpenAI 429/quota).
			// Fall back to keyword (LIKE) passage search so the chat still gets sources
			// instead of silently answering "I have no info".
			$err_msg = $qvec->get_error_message();
			error_log( '[BizCity_KG_Retriever] query embed failed, using keyword fallback: ' . $err_msg );
			$kw_hits = $this->search_passages_keyword( (int) $notebook_id, $question, 8 );
			if ( ! empty( $kw_hits ) ) {
				$result['passages'] = array_map( static function ( $p ) {
					return [ 'id' => (int) $p['id'], 'content' => $p['content'], 'source_id' => (int) $p['source_id'], 'metadata' => isset( $p['metadata'] ) ? (string) $p['metadata'] : '' ];
				}, $kw_hits );
				$result['retrieval_mode'] = 'degraded_keyword';
				$result['steps'][] = [ 'name' => 'Retrieve Seeds',  'entities' => 0, 'relations' => 0, 'note' => 'embed_failed → keyword fallback' ];
				$result['steps'][] = [ 'name' => 'Expand Subgraph', 'entities' => 0, 'relations' => 0, 'note' => 'skipped (degraded)' ];
				$result['steps'][] = [ 'name' => 'LLM Rerank',      'selected' => 0, 'note' => 'skipped (degraded)' ];
				if ( $opts['answer'] ) {
					$result['answer'] = $this->generate_answer( $question, [], $kw_hits );
				}
				$result['steps'][] = [ 'name' => 'Generate Answer', 'passages' => count( $kw_hits ), 'note' => 'keyword-fallback' ];
				return $result;
			}
			$result['answer'] = 'Cannot embed query: ' . $err_msg;
			$result['retrieval_mode'] = 'embed_failed';
			return $result;
		}

		// ── Step 1: Retrieve Seeds
		$seed_entities  = $index->search_entities(  (int) $notebook_id, $qvec, (int) $opts['seed_entities'] );
		$seed_relations = $index->search_relations( (int) $notebook_id, $qvec, (int) $opts['seed_relations'] );

		$result['retrieval_detail']['entity_ids']      = array_map( static fn( $e ) => (int) $e['id'],   $seed_entities );
		$result['retrieval_detail']['entity_texts']    = array_map( static fn( $e ) => $e['name'],       $seed_entities );
		$result['retrieval_detail']['entity_scores']   = array_map( static fn( $e ) => (float) $e['score'], $seed_entities );
		$result['retrieval_detail']['relation_ids']    = array_map( static fn( $r ) => (int) $r['id'],   $seed_relations );
		$result['retrieval_detail']['relation_texts']  = array_map( static fn( $r ) => $r['relation_text'], $seed_relations );
		$result['retrieval_detail']['relation_scores'] = array_map( static fn( $r ) => (float) $r['score'], $seed_relations );
		$result['query_entities']                      = $result['retrieval_detail']['entity_texts'];
		$result['steps'][] = [ 'name' => 'Retrieve Seeds', 'entities' => count( $seed_entities ), 'relations' => count( $seed_relations ) ];

		// ── Fallback: KG is empty (no entities/relations yet) → pure passage vector search ──
		// This allows answering immediately after attach_source, before extraction is done.
		if ( empty( $seed_entities ) && empty( $seed_relations ) ) {
			$passage_hits = $this->search_passages_direct( (int) $notebook_id, $qvec, 8 );
			if ( ! empty( $passage_hits ) ) {
				$result['passages'] = array_map( static function ( $p ) {
					return [ 'id' => (int) $p['id'], 'content' => $p['content'], 'source_id' => (int) $p['source_id'], 'metadata' => isset( $p['metadata'] ) ? (string) $p['metadata'] : '' ];
				}, $passage_hits );
				$result['steps'][] = [ 'name' => 'Expand Subgraph',  'entities' => 0, 'relations' => 0, 'note' => 'fallback: KG empty' ];
				$result['steps'][] = [ 'name' => 'LLM Rerank',       'selected' => 0, 'note' => 'fallback' ];
				if ( $opts['answer'] ) {
					$result['answer'] = $this->generate_answer( $question, [], $passage_hits );
				}
				$result['steps'][] = [ 'name' => 'Generate Answer', 'passages' => count( $passage_hits ), 'note' => 'passage-fallback' ];
				return $result;
			}
		}

		// ── Step 2: Expand Subgraph (1-hop around seed entities + relations)
		$expanded = $this->expand_subgraph( (int) $notebook_id, $seed_entities, $seed_relations, (int) $opts['expand_hops'] );
		$result['subgraph']           = $expanded['subgraph'];
		$result['expanded_relations'] = array_map( static fn( $r ) => $r['relation_text'], $expanded['relations'] );
		$result['steps'][] = [ 'name' => 'Expand Subgraph', 'entities' => count( $expanded['subgraph']['nodes'] ), 'relations' => count( $expanded['relations'] ) ];

		// ── Step 3: LLM Rerank
		$selected_ids = BizCity_KG_Reranker::instance()->rerank(
			$question, $expanded['relations'], (int) $opts['rerank_top_k']
		);
		// Lookup texts.
		$id_to_rel = [];
		foreach ( $expanded['relations'] as $r ) { $id_to_rel[ (int) $r['id'] ] = $r; }
		$selected_texts = [];
		foreach ( $selected_ids as $rid ) {
			if ( isset( $id_to_rel[ $rid ] ) ) {
				$selected_texts[] = $id_to_rel[ $rid ]['relation_text'];
			}
		}
		$result['rerank_result']['selected_relation_ids']   = $selected_ids;
		$result['rerank_result']['selected_relation_texts'] = $selected_texts;
		$result['reranked_relations']                       = $selected_texts;
		$result['subgraph']['selected_link_ids']            = $selected_ids;
		$result['steps'][] = [ 'name' => 'LLM Rerank', 'selected' => count( $selected_ids ) ];

		// ── Step 4: Generate Answer
		$passages = $index->get_passages_for_relations( $selected_ids );

		// Hybrid fallback: if GraphRAG path returned too few passages (KG relations
		// chưa cover hết facts trong source — ví dụ source mới ingest, extraction
		// chưa kịp tạo relation cho mọi fact), bổ sung bằng vector search trực
		// tiếp trên passages. Merge dedupe theo passage.id, ưu tiên thứ tự
		// graph-passages trước (đã được rerank), keyword/vector hits append sau.
		$min_passages = (int) apply_filters( 'bizcity_kg_min_passages', 3, $notebook_id, $question );
		$fallback_added = 0;
		if ( count( $passages ) < $min_passages ) {
			$direct_hits = $this->search_passages_direct( (int) $notebook_id, $qvec, 8 );
			if ( ! empty( $direct_hits ) ) {
				$seen_ids = [];
				foreach ( $passages as $p ) { $seen_ids[ (int) $p['id'] ] = true; }
				foreach ( $direct_hits as $p ) {
					$pid = (int) $p['id'];
					if ( isset( $seen_ids[ $pid ] ) ) { continue; }
					$passages[] = $p;
					$seen_ids[ $pid ] = true;
					$fallback_added++;
				}
			}
		}

		$result['passages'] = array_map( static function ( $p ) {
			return [ 'id' => (int) $p['id'], 'content' => $p['content'], 'source_id' => (int) $p['source_id'], 'metadata' => isset( $p['metadata'] ) ? (string) $p['metadata'] : '' ];
		}, $passages );

		if ( $opts['answer'] ) {
			$result['answer'] = $this->generate_answer( $question, $selected_texts, $passages );
		}
		$step = [ 'name' => 'Generate Answer', 'passages' => count( $passages ) ];
		if ( $fallback_added > 0 ) {
			$step['note'] = sprintf( 'hybrid: +%d passage(s) via vector fallback', $fallback_added );
		}
		$result['steps'][] = $step;

		return $result;
	}

	/**
	 * 1-hop subgraph expansion. Returns relations & a vis-friendly subgraph.
	 */
	private function expand_subgraph( $notebook_id, array $seed_entities, array $seed_relations, $hops = 1 ) {
		global $wpdb;
		$db          = BizCity_KG_Database::instance();
		$notebook_id = (int) $notebook_id;

		$seed_eids = array_map( static fn( $e ) => (int) $e['id'], $seed_entities );
		$seed_rids = array_map( static fn( $r ) => (int) $r['id'], $seed_relations );

		// Collect entity IDs to traverse: seeds + endpoints of seed relations.
		$entity_ids = $seed_eids;
		foreach ( $seed_relations as $r ) {
			$entity_ids[] = (int) $r['head_entity_id'];
			$entity_ids[] = (int) $r['tail_entity_id'];
		}
		$entity_ids = array_values( array_unique( array_filter( $entity_ids ) ) );

		$relation_ids = $seed_rids;
		$current_layer = $entity_ids;

		// Wave 1.3 — virtual-merge: in-house + attached-guru relations.
		$merge_where = BizCity_KG_Database::instance()->build_virtual_merge_where( $notebook_id );

		for ( $hop = 0; $hop < max( 1, (int) $hops ); $hop++ ) {
			if ( empty( $current_layer ) ) break;
			$ids_csv = implode( ',', array_map( 'intval', $current_layer ) );
			$rows = $wpdb->get_results(
				"SELECT id, head_entity_id, tail_entity_id FROM {$db->tbl_relations()}
				 WHERE ({$merge_where}) AND status='approved'
				   AND deleted_at IS NULL
				   AND ( head_entity_id IN ({$ids_csv}) OR tail_entity_id IN ({$ids_csv}) )
				 LIMIT 500",
				ARRAY_A
			) ?: [];
			$next_entities = [];
			foreach ( $rows as $r ) {
				$relation_ids[] = (int) $r['id'];
				$next_entities[] = (int) $r['head_entity_id'];
				$next_entities[] = (int) $r['tail_entity_id'];
			}
			$current_layer = array_diff( array_unique( $next_entities ), $entity_ids );
			$entity_ids = array_values( array_unique( array_merge( $entity_ids, $current_layer ) ) );
		}

		$relation_ids = array_values( array_unique( array_filter( $relation_ids ) ) );

		// Fetch full data for vis.
		$relations_full = [];
		if ( ! empty( $relation_ids ) ) {
			$rids_csv = implode( ',', array_map( 'intval', $relation_ids ) );
			$relations_full = $wpdb->get_results(
				"SELECT id, notebook_id, head_entity_id, tail_entity_id, predicate, relation_text, weight,
				        storage_ver, jsonl_line
				 FROM {$db->tbl_relations()} WHERE id IN ({$rids_csv}) AND deleted_at IS NULL",
				ARRAY_A
			) ?: [];
			if ( $relations_full && class_exists( 'BizCity_KG_Content_Router' ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_relations( $relations_full );
			}
		}
		$entities_full = [];
		if ( ! empty( $entity_ids ) ) {
			$eids_csv = implode( ',', array_map( 'intval', $entity_ids ) );
			$entities_full = $wpdb->get_results(
				"SELECT id, name, type, weight FROM {$db->tbl_entities()} WHERE id IN ({$eids_csv}) AND deleted_at IS NULL",
				ARRAY_A
			) ?: [];
		}

		$subgraph = [
			'nodes' => array_map( static function ( $e ) {
				return [ 'id' => (int) $e['id'], 'label' => $e['name'], 'type' => $e['type'], 'weight' => (int) $e['weight'] ];
			}, $entities_full ),
			'links' => array_map( static function ( $r ) {
				return [
					'id'        => (int) $r['id'],
					'source'    => (int) $r['head_entity_id'],
					'target'    => (int) $r['tail_entity_id'],
					'predicate' => $r['predicate'],
					'weight'    => (int) $r['weight'],
				];
			}, $relations_full ),
			'seed_node_ids'     => $seed_eids,
			'seed_link_ids'     => $seed_rids,
			'expanded_node_ids' => array_values( array_diff( array_column( $entities_full, 'id' ), $seed_eids ) ),
			'expanded_link_ids' => array_values( array_diff( array_column( $relations_full, 'id' ), $seed_rids ) ),
			'selected_link_ids' => [],
		];

		return [ 'subgraph' => $subgraph, 'relations' => $relations_full ];
	}

	/**
	 * Sprint 0.6.9 — Standalone passage search (no agent loop, no answer generation).
	 *
	 * Embeds the query, runs vector search across `kg_passages` for the notebook,
	 * then enriches each hit with the parent source title/origin so the FE
	 * SearchResults grid can render snippet cards directly without extra round-trips.
	 *
	 * @param int    $notebook_id
	 * @param string $question
	 * @param int    $top_k  default 20, max 100
	 * @return array { results: array<int, array{passage_id,source_id,source_title,origin_kind,snippet,score,citation}>, count: int }
	 */
	public function search( $notebook_id, $question, $top_k = 20 ) {
		$question = trim( (string) $question );
		$top_k    = max( 1, min( 100, (int) $top_k ) );
		if ( $question === '' ) {
			return [ 'results' => [], 'count' => 0 ];
		}
		$index = BizCity_KG_Vector_Index::instance();
		$qvec  = $index->embed( $question );
		$degraded = false;
		if ( is_wp_error( $qvec ) ) {
			// Fallback to keyword search so the SearchResults grid still has rows.
			error_log( '[BizCity_KG_Retriever::search] query embed failed, using keyword fallback: ' . $qvec->get_error_message() );
			$hits     = $this->search_passages_keyword( (int) $notebook_id, $question, $top_k );
			$degraded = true;
		} else {
			$hits = $this->search_passages_direct( (int) $notebook_id, $qvec, $top_k );
		}
		if ( empty( $hits ) ) {
			return [ 'results' => [], 'count' => 0 ];
		}

		// Resolve source titles in one query.
		global $wpdb;
		$db          = BizCity_KG_Database::instance();
		$source_ids  = array_values( array_unique( array_map( static fn( $h ) => (int) $h['source_id'], $hits ) ) );
		$source_meta = [];
		if ( ! empty( $source_ids ) ) {
			$ids_csv = implode( ',', array_map( 'intval', $source_ids ) );
			$rows = $wpdb->get_results(
				"SELECT id, title, origin_kind, origin_url FROM {$db->tbl_sources()} WHERE id IN ({$ids_csv})",
				ARRAY_A
			) ?: [];
			foreach ( $rows as $r ) {
				$source_meta[ (int) $r['id'] ] = $r;
			}
		}

		$results = [];
		foreach ( $hits as $h ) {
			$sid     = (int) $h['source_id'];
			$content = (string) $h['content'];
			$snippet = mb_substr( $content, 0, 280, 'UTF-8' );
			if ( mb_strlen( $content, 'UTF-8' ) > 280 ) {
				$snippet .= '…';
			}
			$meta = $source_meta[ $sid ] ?? null;
			$results[] = [
				'passage_id'   => (int) $h['id'],
				'source_id'    => $sid,
				'source_title' => $meta ? (string) ( $meta['title'] ?? '' ) : '',
				'origin_kind'  => $meta ? (string) ( $meta['origin_kind'] ?? '' ) : '',
				'origin_url'   => $meta ? (string) ( $meta['origin_url'] ?? '' ) : '',
				'snippet'      => $snippet,
				'score'        => isset( $h['score'] ) ? (float) $h['score'] : 0.0,
				// Citation marker compatible with [src:N#pM] pipeline (Wave 0.6.B).
				'citation'     => sprintf( '[src:%d#p%d]', $sid, (int) $h['id'] ),
			];
		}

		$out = [ 'results' => $results, 'count' => count( $results ) ];
		if ( $degraded ) {
			$out['mode']    = 'degraded_keyword';
			$out['warning'] = 'Vector embedding API unavailable; using keyword fallback.';
		}
		return $out;
	}

	/**
	 * Pure passage vector search (no KG) — fallback when entities/relations tables are empty.
	 *
	 * PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0 (FILESTORE-ONLY, 2026-05-10):
	 *   .bin file is the ONLY source of truth. JSON column `embedding` fallback REMOVED.
	 *   .bin missing/corrupt → log + `do_action('bizcity_kg_bin_missing', ...)` + return [].
	 *   Run backfill via /wp-admin/tools.php?page=bizcity-kg-bin-diagnostic (Section 8.5).
	 *
	 * @param int   $notebook_id
	 * @param float[] $qvec
	 * @param int   $top_k
	 * @return array  passages enriched with 'score'
	 */
	private function search_passages_direct( $notebook_id, array $qvec, $top_k = 8 ) {
		$bin_hits = $this->search_passages_via_bin( (int) $notebook_id, $qvec, (int) $top_k );
		return is_array( $bin_hits ) ? $bin_hits : [];
	}

	/**
	 * PHASE-0.21 Wave 2 — vector search backed by `.bin` file store.
	 *
	 * v2.0 (FILESTORE-ONLY): No more JSON fallback. When `.bin` is missing /
	 * corrupt / empty, fires the `bizcity_kg_bin_missing` action so admin
	 * tooling can surface a backfill prompt, then returns []. Returns null
	 * ONLY when prerequisites are missing (file store class / helper / uuid)
	 * — caller will treat null as "[]" anyway.
	 *
	 * @return array<int, array{id:int,content:string,source_id:int,score:float}>|null
	 */
	private function search_passages_via_bin( $notebook_id, array $qvec, $top_k ) {
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) ) { return null; }
		if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) { return null; }

		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$nb_tbl = $db->tbl_notebooks();
		$uuid   = $wpdb->get_var( $wpdb->prepare( "SELECT uuid FROM {$nb_tbl} WHERE id = %d", (int) $notebook_id ) );
		if ( ! $uuid ) { return null; }
		$uuid = strtolower( (string) $uuid );

		$store = BizCity_KG_Vector_File_Store::instance();
		$all_hits = [];
		// 2026-05-14 — Over-fetch from .bin so we can drop chat-promoted (source_id<=0)
		// rows after DB resolve and still return $top_k real passages. Without this,
		// chat-promoted rows out-rank real chunks (lexical near-match on user query)
		// and feed the model its own "no data" answers → vicious loop.
		// See PHASE-0.13 §41 "infinite chat-promote loop".
		$include_chat = (bool) apply_filters( 'bizcity_kg_rag_include_chat_promoted', false, (int) $notebook_id );
		$hit_budget = $include_chat ? (int) $top_k : max( (int) $top_k * 4, (int) $top_k + 16 );

		// ── Notebook .bin (in-house) ──────────────────────────────────────────
		$nb_abs = bizcity_kg_vector_bin_path( 'notebooks', $uuid );
		$nb_present = $nb_abs && file_exists( $nb_abs );
		if ( $nb_present ) {
			$hdr = $store->header_validate( $nb_abs );
			if ( is_wp_error( $hdr ) ) {
				error_log( '[KG] .bin header invalid notebooks/' . $uuid . ': ' . $hdr->get_error_message() );
				do_action( 'bizcity_kg_bin_missing', 'notebooks', $uuid, $hdr );
				$nb_present = false;
			} else {
				$nb_results = $store->search( $nb_abs, $qvec, $hit_budget, 0.0 );
				if ( ! is_wp_error( $nb_results ) ) {
					$all_hits = array_merge( $all_hits, $nb_results );
				}
			}
		} else {
			error_log( '[KG] .bin missing notebooks/' . $uuid . ' — run backfill via Diagnostic 8.5' );
			do_action( 'bizcity_kg_bin_missing', 'notebooks', $uuid, null );
		}

		// ── Attached-guru .bin files (Wave 1.3 virtual-merge) ─────────────────
		$guru_uuids = $db->get_attached_guru_uuids( $notebook_id );
		foreach ( $guru_uuids as $guru_uuid ) {
			$guru_uuid = strtolower( (string) $guru_uuid );
			$g_abs = bizcity_kg_vector_bin_path( 'gurus', $guru_uuid );
			if ( ! $g_abs || ! file_exists( $g_abs ) ) {
				do_action( 'bizcity_kg_bin_missing', 'gurus', $guru_uuid, null );
				continue;
			}
			$g_hdr = $store->header_validate( $g_abs );
			if ( is_wp_error( $g_hdr ) ) {
				do_action( 'bizcity_kg_bin_missing', 'gurus', $guru_uuid, $g_hdr );
				continue;
			}
			$g_results = $store->search( $g_abs, $qvec, $hit_budget, 0.0 );
			if ( ! is_wp_error( $g_results ) ) {
				$all_hits = array_merge( $all_hits, $g_results );
			}
		}

		if ( empty( $all_hits ) ) {
			// All .bin candidates exhausted (missing or empty). Per filestore-only
			// rule: NO JSON fallback. Return [] so chat surfaces "no info" rather
			// than silently scanning DB embeddings.
			return [];
		}

		// Merge + re-sort by score, take top_k.
		usort( $all_hits, function( $a, $b ) {
			return ( $a['score'] < $b['score'] ) ? 1 : ( ( $a['score'] > $b['score'] ) ? -1 : 0 );
		} );
		// Keep over-fetched list intact here; final slice happens AFTER the
		// chat-promoted filter below so we don't waste headroom.

		// Resolve content for the matched chunk_ids.
		$ids = [];
		$score_map = [];
		foreach ( $all_hits as $hit ) {
			$cid = isset( $hit['payload']['chunk_id'] ) ? (int) $hit['payload']['chunk_id'] : 0;
			if ( $cid > 0 ) {
				$ids[] = $cid;
				$score_map[ $cid ] = (float) $hit['score'];
			}
		}
		if ( empty( $ids ) ) { return []; }

		$ids_csv = implode( ',', array_map( 'intval', $ids ) );
		$rows    = $wpdb->get_results(
			"SELECT id, content, source_id, metadata, notebook_id, storage_ver, file_shard, file_offset, file_length FROM {$db->tbl_passages()} WHERE id IN ({$ids_csv})",
			ARRAY_A
		);
		if ( empty( $rows ) ) { return []; }
		if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		$dropped_chat = 0;
		$out = [];
		foreach ( $rows as $r ) {
			$cid = (int) $r['id'];
			$src_id = (int) $r['source_id'];
			// 2026-05-14 — Drop chat-promoted rows from RAG context (source_id<=0).
			// Chat answers ingested as passages have NEAR-DUPLICATE lexical content
			// to the next user query ("giá FS 836G" → "Mình chưa có dữ liệu về FS
			// 836G"), so they out-rank real source chunks and the model parrots
			// the same "no data" reply forever. They remain available for
			// conversation memory paths that explicitly opt in via the filter.
			if ( ! $include_chat && $src_id <= 0 ) {
				$dropped_chat++;
				continue;
			}
			$out[] = [
				'id'        => $cid,
				'content'   => (string) $r['content'],
				'source_id' => $src_id,
				'metadata'  => isset( $r['metadata'] ) ? (string) $r['metadata'] : '',
				'score'     => isset( $score_map[ $cid ] ) ? $score_map[ $cid ] : 0.0,
			];
		}
		if ( $dropped_chat > 0 && function_exists( 'error_log' ) ) {
			error_log( sprintf(
				'[KG] chat-promoted RAG filter notebook=%d dropped=%d kept=%d (over-fetched=%d, top_k=%d)',
				(int) $notebook_id, $dropped_chat, count( $out ), count( $rows ), (int) $top_k
			) );
		}
		// Preserve order from .bin score ranking, then trim to top_k.
		usort( $out, function( $a, $b ) {
			return ( $a['score'] < $b['score'] ) ? 1 : ( ( $a['score'] > $b['score'] ) ? -1 : 0 );
		} );
		if ( count( $out ) > (int) $top_k ) {
			$out = array_slice( $out, 0, (int) $top_k );
		}
		return $out;
	}

	/**
	 * Keyword (LIKE) fallback for passage search when the query embedding API is unavailable.
	 *
	 * Tokenises the question into words ≥3 chars (Vietnamese-friendly), builds an OR'd LIKE
	 * across `content`, then ranks rows by simple term-frequency hit count. Not a true BM25 —
	 * just a degraded survival mode so TwinChat still surfaces sources during 429 storms.
	 *
	 * @param int    $notebook_id
	 * @param string $question
	 * @param int    $top_k
	 * @return array<int, array{id:int,content:string,source_id:int,score:float}>
	 */
	private function search_passages_keyword( $notebook_id, $question, $top_k = 8 ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		// Tokenise: split on non-letter/digit, drop stopwords + short tokens.
		$normalised = mb_strtolower( (string) $question, 'UTF-8' );
		$raw_tokens = preg_split( '/[^\p{L}\p{N}]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY );
		$stop = [ 'la','và','của','cho','với','một','các','được','để','trong','là','có','này','đó','khi','thì','mà','nào','gì','sao','bao','nhiêu','the','and','for','with','that','this','from','what','how','why','when' ];
		$tokens = [];
		foreach ( (array) $raw_tokens as $t ) {
			if ( mb_strlen( $t, 'UTF-8' ) < 3 ) continue;
			if ( in_array( $t, $stop, true ) ) continue;
			$tokens[ $t ] = true;
		}
		$tokens = array_keys( $tokens );
		if ( empty( $tokens ) ) {
			return [];
		}
		$tokens = array_slice( $tokens, 0, 8 ); // cap to keep SQL reasonable

		$where_or = [];
		$params   = [ (int) $notebook_id ];
		foreach ( $tokens as $tok ) {
			$where_or[] = 'content LIKE %s';
			$params[]   = '%' . $wpdb->esc_like( $tok ) . '%';
		}
		// Wave 1.3 — virtual-merge: notebook in-house + attached-guru passages.
		$merge_where = BizCity_KG_Database::instance()->build_virtual_merge_where( (int) $notebook_id );
		// $params already has notebook_id at index 0 (used for build_virtual_merge_where
		// internally); the LIKE params are separate — build combined WHERE by appending.
		$sql = "SELECT id, content, source_id, metadata, notebook_id, storage_ver, file_shard, file_offset, file_length
		        FROM {$db->tbl_passages()}
		        WHERE ({$merge_where}) AND ( " . implode( ' OR ', $where_or ) . " )
		        LIMIT 200";
		// Remove the notebook_id from $params[0] — it is already baked into $merge_where.
		$like_params = array_slice( $params, 1 );
		$rows = $wpdb->get_results(
			empty( $like_params ) ? $sql : $wpdb->prepare( $sql, $like_params ),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return [];
		}
		if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		// Rank by token-hit count (case-insensitive substring count).
		// 2026-05-14 — also drop chat-promoted (source_id<=0) rows here so the
		// keyword fallback shares the same RAG hygiene as the .bin path. See
		// search_passages_via_bin() for rationale.
		$include_chat = (bool) apply_filters( 'bizcity_kg_rag_include_chat_promoted', false, (int) $notebook_id );
		$dropped_chat = 0;
		$ranked = [];
		foreach ( $rows as $row ) {
			$src_id = (int) $row['source_id'];
			if ( ! $include_chat && $src_id <= 0 ) {
				$dropped_chat++;
				continue;
			}
			$content_lc = mb_strtolower( (string) $row['content'], 'UTF-8' );
			$score = 0.0;
			foreach ( $tokens as $tok ) {
				$cnt = substr_count( $content_lc, $tok );
				if ( $cnt > 0 ) {
					$score += 1.0 + log( 1 + $cnt ); // diminishing returns per term
				}
			}
			$row['score']     = $score;
			$row['id']        = (int) $row['id'];
			$row['source_id'] = $src_id;
			$ranked[] = $row;
		}
		if ( $dropped_chat > 0 && function_exists( 'error_log' ) ) {
			error_log( sprintf(
				'[KG] chat-promoted keyword filter notebook=%d dropped=%d kept=%d',
				(int) $notebook_id, $dropped_chat, count( $ranked )
			) );
		}

		usort( $ranked, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $ranked, 0, max( 1, (int) $top_k ) );
	}

	/**
	 * PHASE-0-RULE-SMART-GATEWAY-MIGRATION (BUSINESS-MODEL §4.1):
	 * generate_answer() PHẢI đi qua BizCity LLM Router. Cấm mọi wp_remote_post()
	 * trực tiếp tới api.openai.com từ knowledge / kg-hub.
	 */
	private function generate_answer( $question, array $relation_texts, array $passages ) {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return 'Hệ thống chưa cài đặt BizCity LLM Router (thiếu BizCity_LLM_Client).';
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			return 'BizCity LLM Router chưa cấu hình API key (option `bizcity_llm_api_key`).';
		}

		$tpl = @file_get_contents( BIZCITY_KG_HUB_PROMPTS . 'generate-answer.txt' );
		if ( ! $tpl ) {
			return 'Prompt template missing.';
		}
		$rel_block = '';
		foreach ( $relation_texts as $i => $r ) { $rel_block .= '- ' . $r . "\n"; }
		$pas_block = '';
		foreach ( $passages as $i => $p ) {
			$pas_block .= sprintf( "[#%d] %s\n\n", $i + 1, mb_substr( $p['content'], 0, 1200, 'UTF-8' ) );
		}

		$prompt = strtr( $tpl, [
			'{{QUESTION}}'  => $question,
			'{{RELATIONS}}' => $rel_block ?: '(none)',
			'{{PASSAGES}}'  => $pas_block ?: '(none)',
		] );

		// Model: site override -> router default cho purpose `answer`.
		$model = get_option( self::ANSWER_MODEL_OPTION, '' );
		if ( $model === '' ) {
			$model = $client->get_model( 'answer' ) ?: self::DEFAULT_ANSWER_MODEL;
		}

		$messages = [
			[ 'role' => 'system', 'content' => 'You are a careful research assistant.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		];

		$resp = $client->chat( $messages, [
			'purpose'     => 'answer',
			'model'       => $model,
			'temperature' => 0.2,
			'max_tokens'  => 800,
			'timeout'     => 60,
		] );

		if ( empty( $resp['success'] ) ) {
			return 'LLM error: ' . ( $resp['error'] ?? 'unknown' );
		}
		return trim( (string) ( $resp['message'] ?? '(empty)' ) );
	}
}
