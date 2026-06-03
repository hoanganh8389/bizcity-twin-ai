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
	 * @return array  [ ['id', 'name', 'type', 'score'], ... ]
	 */
	public function search_entities( $notebook_id, $query_vec, $top_k = 10, $threshold = 0.0 ) {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$table = $db->tbl_entities();
		$where = $db->build_virtual_merge_where( (int) $notebook_id );

		$rows = $wpdb->get_results(
			"SELECT id, name, type, embedding FROM {$table}
			 WHERE ({$where}) AND status = 'approved' AND embedding IS NOT NULL
			   AND deleted_at IS NULL",
			ARRAY_A
		);

		return $this->rank( $query_vec, $rows ?: [], $top_k, $threshold );
	}

	/**
	 * Search relations in a notebook by query text.
	 * Wave 1.3 — includes attached-guru relations via virtual-merge WHERE.
	 */
	public function search_relations( $notebook_id, $query_vec, $top_k = 20, $threshold = 0.0 ) {
		global $wpdb;
		$db    = BizCity_KG_Database::instance();
		$table = $db->tbl_relations();
		$where = $db->build_virtual_merge_where( (int) $notebook_id );

		$rows = $wpdb->get_results(
			"SELECT id, head_entity_id, tail_entity_id, predicate, relation_text, embedding
			 FROM {$table}
			 WHERE ({$where}) AND status = 'approved' AND embedding IS NOT NULL
			   AND deleted_at IS NULL",
			ARRAY_A
		);

		return $this->rank( $query_vec, $rows ?: [], $top_k, $threshold );
	}

	/**
	 * Search passages by relation IDs (for answer generation).
	 *
	 * @param int[] $relation_ids
	 * @return array  [ ['id', 'content', 'source_id'], ... ]
	 */
	public function get_passages_for_relations( array $relation_ids ) {
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
		return $rows;
	}
}
