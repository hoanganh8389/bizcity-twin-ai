<?php
/**
 * Bizcity Twin AI — KG_Vector_Index
 *
 * Pluggable vector search interface. Phase A: pure PHP cosine over MySQL.
 * Phase C can swap to SQLite-VSS / Qdrant by re-implementing this class.
 *
 * Reuses BizCity_Knowledge_Embedding for embedding generation (text-embedding-3-small, 1536d).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Vector_Index {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Generate an embedding for a text using the existing knowledge embedding service.
	 *
	 * Note: the per-call throttle lives inside
	 * `BizCity_Knowledge_Embedding::openai_embed_request_with_retry()` so every
	 * caller (KG, legal chunker, knowledge fabric) is rate-limited uniformly.
	 *
	 * @return float[]|WP_Error
	 */
	public function embed( $text ) {
		if ( ! class_exists( 'BizCity_Knowledge_Embedding' ) ) {
			return new WP_Error( 'no_embedder', 'BizCity_Knowledge_Embedding not loaded' );
		}
		return BizCity_Knowledge_Embedding::instance()->create_embedding( $text );
	}

	/**
	 * Cosine similarity between two equal-length vectors.
	 */
	public function cosine( array $a, array $b ) {
		$len = count( $a );
		if ( $len === 0 || $len !== count( $b ) ) {
			return 0.0;
		}
		$dot = 0.0; $na = 0.0; $nb = 0.0;
		for ( $i = 0; $i < $len; $i++ ) {
			$x = (float) $a[ $i ];
			$y = (float) $b[ $i ];
			$dot += $x * $y;
			$na  += $x * $x;
			$nb  += $y * $y;
		}
		if ( $na === 0.0 || $nb === 0.0 ) {
			return 0.0;
		}
		return $dot / ( sqrt( $na ) * sqrt( $nb ) );
	}

	/**
	 * Generic top-K search over a result-set of rows that contain id + embedding fields.
	 *
	 * @param float[] $query_vec
	 * @param array   $rows       each row: [ 'id' => int, 'embedding' => string|array, ... ]
	 * @param int     $top_k
	 * @param float   $threshold  minimum cosine
	 * @return array  rows enriched with 'score', sorted desc, sliced to top_k
	 */
	public function rank( array $query_vec, array $rows, $top_k = 10, $threshold = 0.0 ) {
		$scored = [];
		foreach ( $rows as $row ) {
			$vec = $row['embedding'];
			if ( is_string( $vec ) ) {
				$vec = BizCity_KG_Database::decode_embedding( $vec );
			}
			if ( ! is_array( $vec ) || empty( $vec ) ) {
				continue;
			}
			$s = $this->cosine( $query_vec, $vec );
			if ( $s < $threshold ) {
				continue;
			}
			$row['score'] = $s;
			unset( $row['embedding'] );
			$scored[] = $row;
		}
		usort( $scored, static function ( $x, $y ) {
			return $y['score'] <=> $x['score'];
		} );
		return array_slice( $scored, 0, $top_k );
	}

	/**
	 * Search entities in a notebook by query text.
	 * Wave 1.3 — includes attached-guru entities via virtual-merge WHERE.
	 *
	 * [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — bin-first fix. Once
	 * `BizCity_KG_Graph_Embedding_Migration` drains a row's embedding to
	 * `entities.embed.bin` it sets SQL `embedding=NULL` BY DESIGN (storage_ver=2).
	 * The old SQL-only "embedding IS NOT NULL" query therefore silently returned
	 * ZERO seed entities for every migrated notebook, collapsing
	 * `BizCity_KG_Retriever::ask()` to its "KG empty" passage-only fallback
	 * (graph seeding/expansion/rerank steps never fired again). Bin-first read
	 * covers migrated (storage_ver=2) rows; the SQL query below still covers any
	 * remaining legacy storage_ver=1 rows whose embedding is still inline.
	 *
	 * Known gap: attached-guru virtual-merge notebooks are NOT bin-searched here
	 * (only the primary notebook's own entities.embed.bin) — guru entities still
	 * rely on the SQL path, so they degrade the same way once THEIR embeddings
	 * get drained. Acceptable for now; flagged in kg-hub docs for a follow-up.
	 *
	 * @return array  [ ['id', 'name', 'type', 'score'], ... ]
	 */
	public function search_entities( $notebook_id, $query_vec, $top_k = 10, $threshold = 0.0 ) {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$table = $db->tbl_entities();
		$where = $db->build_virtual_merge_where( (int) $notebook_id );

		$bin_hits = $this->search_graph_via_bin( 'entity', (int) $notebook_id, $query_vec, $top_k, $threshold );

		$rows = $wpdb->get_results(
			"SELECT id, name, type, embedding FROM {$table}
			 WHERE ({$where}) AND status = 'approved' AND embedding IS NOT NULL
			   AND deleted_at IS NULL",
			ARRAY_A
		);
		$sql_hits = $this->rank( $query_vec, $rows ?: [], $top_k, $threshold );

		return $this->merge_top_k( $bin_hits, $sql_hits, $top_k );
	}

	/**
	 * Search relations in a notebook by query text.
	 * Wave 1.3 — includes attached-guru relations via virtual-merge WHERE.
	 *
	 * [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — same bin-first fix as
	 * search_entities() above; see that docblock for the regression this closes.
	 */
	public function search_relations( $notebook_id, $query_vec, $top_k = 20, $threshold = 0.0 ) {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$table = $db->tbl_relations();
		$where = $db->build_virtual_merge_where( (int) $notebook_id );

		$bin_hits = $this->search_graph_via_bin( 'relation', (int) $notebook_id, $query_vec, $top_k, $threshold );

		$rows = $wpdb->get_results(
			"SELECT id, head_entity_id, tail_entity_id, predicate, relation_text, embedding
			 FROM {$table}
			 WHERE ({$where}) AND status = 'approved' AND embedding IS NOT NULL
			   AND deleted_at IS NULL",
			ARRAY_A
		);
		$sql_hits = $this->rank( $query_vec, $rows ?: [], $top_k, $threshold );

		return $this->merge_top_k( $bin_hits, $sql_hits, $top_k );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — bin-first entity/relation
	 * vector search. Reads `{notebook}/entities.embed.bin` or `relations.embed.bin`
	 * (written by BizCity_KG_Graph_Embedding_Migration), resolves the winning
	 * `entity_id`/`relation_id`s back through a small scoped SQL SELECT (still
	 * guarded by status/deleted_at/virtual-merge), and hydrates `relation_text`
	 * via Content_Router since that column IS nulled for storage_ver=2 relations
	 * (entity `name`/`type` are never nulled, so no hydration needed there).
	 *
	 * @param string  $kind         'entity' | 'relation'
	 * @param int     $notebook_id
	 * @param float[] $query_vec
	 * @param int     $top_k
	 * @param float   $threshold
	 * @return array  Same row shape as the SQL-path branch, with 'score' set.
	 */
	private function search_graph_via_bin( $kind, $notebook_id, array $query_vec, $top_k, $threshold ) {
		if ( ! class_exists( 'BizCity_KG_Vector_File_Store' ) || ! class_exists( 'BizCity_KG_Notebook_Folder' ) ) {
			return [];
		}
		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( (int) $notebook_id );
		if ( is_wp_error( $uuid ) || ! $uuid ) { return []; }
		$root = BizCity_KG_Notebook_Folder::instance()->path( 'notebooks', $uuid );
		if ( is_wp_error( $root ) ) { return []; }
		$file     = ( 'relation' === $kind ) ? 'relations.embed.bin' : 'entities.embed.bin';
		$bin_path = rtrim( $root, '/\\' ) . '/' . $file;
		if ( ! file_exists( $bin_path ) ) { return []; }

		$store = BizCity_KG_Vector_File_Store::instance();
		$over  = max( (int) $top_k * 2, (int) $top_k + 5 );
		$hits  = $store->search( $bin_path, $query_vec, $over, (float) $threshold );
		if ( is_wp_error( $hits ) || empty( $hits ) ) { return []; }

		$scores = [];
		foreach ( $hits as $h ) {
			$payload = (array) ( $h['payload'] ?? [] );
			$id      = (int) ( 'relation' === $kind ? ( $payload['relation_id'] ?? 0 ) : ( $payload['entity_id'] ?? 0 ) );
			if ( $id > 0 && ! isset( $scores[ $id ] ) ) {
				$scores[ $id ] = (float) ( $h['score'] ?? 0.0 );
			}
		}
		if ( empty( $scores ) ) { return []; }

		global $wpdb;
		$db      = BizCity_KG_Database::instance();
		$where   = $db->build_virtual_merge_where( (int) $notebook_id );
		$ids_csv = implode( ',', array_map( 'intval', array_keys( $scores ) ) );

		if ( 'relation' === $kind ) {
			$rows = $wpdb->get_results(
				"SELECT id, head_entity_id, tail_entity_id, predicate, relation_text, storage_ver
				   FROM {$db->tbl_relations()}
				  WHERE id IN ({$ids_csv}) AND ({$where}) AND status='approved' AND deleted_at IS NULL",
				ARRAY_A
			) ?: [];
			if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_relations( $rows );
			}
		} else {
			$rows = $wpdb->get_results(
				"SELECT id, name, type, storage_ver
				   FROM {$db->tbl_entities()}
				  WHERE id IN ({$ids_csv}) AND ({$where}) AND status='approved' AND deleted_at IS NULL",
				ARRAY_A
			) ?: [];
		}

		$out = [];
		foreach ( $rows as $row ) {
			$row_id = (int) $row['id'];
			if ( ! isset( $scores[ $row_id ] ) ) { continue; }
			unset( $row['storage_ver'] );
			$row['score'] = $scores[ $row_id ];
			$out[] = $row;
		}
		usort( $out, static function ( $x, $y ) { return $y['score'] <=> $x['score']; } );
		return $out;
	}

	/**
	 * Merge bin-sourced + SQL-sourced hit lists (dedupe by id, keep highest
	 * score, sort desc, slice to top_k). Shared by search_entities/relations.
	 */
	private function merge_top_k( array $a, array $b, $top_k ) {
		$by_id = [];
		foreach ( array_merge( $a, $b ) as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id <= 0 ) { continue; }
			if ( ! isset( $by_id[ $id ] ) || (float) $row['score'] > (float) $by_id[ $id ]['score'] ) {
				$by_id[ $id ] = $row;
			}
		}
		$out = array_values( $by_id );
		usort( $out, static function ( $x, $y ) { return $y['score'] <=> $x['score']; } );
		return array_slice( $out, 0, (int) $top_k );
	}

	/**
	 * Search passages by relation IDs (for answer generation).
	 *
	 * @param int[] $relation_ids
	 * @param array $relation_scores Optional canonical relation_id => retrieval score map.
	 * @return array  [ ['id', 'content', 'source_id', 'score', 'score_source'], ... ]
	 */
	public function get_passages_for_relations( array $relation_ids, array $relation_scores = array() ) {
		if ( empty( $relation_ids ) ) {
			return [];
		}
		global $wpdb;
		$db    = BizCity_KG_Database::instance();

		$ids_csv = implode( ',', array_map( 'intval', $relation_ids ) );
		// 2026-05-05 — origin bias: passages with a real `source_id` (file/web
		// ingest) come BEFORE chat-promoted passages (source_id IS NULL). This
		// keeps authoritative sources from being crowded out by conversational
		// memory when a notebook accumulates many chat turns.
		$sql = "SELECT DISTINCT p.id, p.content, p.source_id, p.notebook_id, p.metadata,
		                p.storage_ver, p.file_shard, p.file_offset, p.file_length
				FROM {$db->tbl_passages()} p
				INNER JOIN {$db->tbl_passage_relations()} pr ON pr.passage_id = p.id
				WHERE pr.relation_id IN ({$ids_csv})
				ORDER BY (p.source_id IS NULL) ASC, p.id DESC";
		$rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
		if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		$relation_ids_by_passage = array();
		if ( ! empty( $relation_scores ) && ! empty( $rows ) ) {
			$passage_ids_csv = implode( ',', array_map( static function ( $row ) { return (int) $row['id']; }, $rows ) );
			$relation_rows = $wpdb->get_results(
				"SELECT passage_id, relation_id FROM {$db->tbl_passage_relations()} WHERE passage_id IN ({$passage_ids_csv}) AND relation_id IN ({$ids_csv})",
				ARRAY_A
			) ?: array();
			foreach ( $relation_rows as $relation_row ) {
				$passage_id = (int) $relation_row['passage_id'];
				$relation_ids_by_passage[ $passage_id ][] = (int) $relation_row['relation_id'];
			}
		}
		foreach ( $rows as &$row ) {
			$passage_id = (int) $row['id'];
			$row['score'] = 0.0;
			$row['score_source'] = 'graph_relation_unscored';
			$matched_score = false;
			if ( ! empty( $relation_scores ) ) {
				foreach ( (array) ( isset( $relation_ids_by_passage[ $passage_id ] ) ? $relation_ids_by_passage[ $passage_id ] : array() ) as $relation_id ) {
					// [2026-07-28 Johnny Chu] PHASE-0.53-MCP — preserve graph provenance even when the canonical score is zero.
					$score_value = isset( $relation_scores[ $relation_id ] ) && is_array( $relation_scores[ $relation_id ] )
						? (float) ( $relation_scores[ $relation_id ]['score'] ?? 0.0 )
						: ( isset( $relation_scores[ $relation_id ] ) ? (float) $relation_scores[ $relation_id ] : 0.0 );
					if ( isset( $relation_scores[ $relation_id ] ) && ( ! $matched_score || $score_value > (float) $row['score'] ) ) {
						$row['score'] = $score_value;
						$score_source = is_array( $relation_scores[ $relation_id ] ) && ! empty( $relation_scores[ $relation_id ]['source'] ) ? (string) $relation_scores[ $relation_id ]['source'] : 'graph_relation';
						$row['score_source'] = $score_source;
						$matched_score = true;
					}
				}
			}
		}
		unset( $row );
		usort( $rows, static function ( $a, $b ) {
			if ( (float) $a['score'] === (float) $b['score'] ) {
				return (int) $b['id'] <=> (int) $a['id'];
			}
			return (float) $a['score'] < (float) $b['score'] ? 1 : -1;
		} );
		return $rows;
	}
}
