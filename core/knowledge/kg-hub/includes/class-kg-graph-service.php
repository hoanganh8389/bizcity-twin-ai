<?php
/**
 * Bizcity Twin AI — KG_Graph_Service
 *
 * Owns CRUD over entities, relations, triplet-queue. Handles upsert/dedup
 * during the "apply triplet" stage.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Graph_Service {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Upsert an entity by normalized name.
	 *
	 * @return int entity_id
	 */
	public function upsert_entity( $notebook_id, $name, $type = 'Other', $description = '' ) {
		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$norm = BizCity_KG_Database::normalize_name( $name );
		if ( $norm === '' ) {
			return 0;
		}

		// PHASE-0.6.6 §10 — UNIQUE (notebook_id, name_normalized) means we must match
		// soft-deleted rows too; if matched + soft-deleted, restore (clear deleted_at).
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_entities()} WHERE notebook_id=%d AND name_normalized=%s LIMIT 1",
			(int) $notebook_id, $norm
		) );
		if ( $existing ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$db->tbl_entities()} SET weight = weight + 1, deleted_at = NULL WHERE id = %d", $existing
			) );
			// Restoring a soft-deleted row changes the approved-count → drop stats cache.
			do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
			return $existing;
		}

		// Embed entity name + description.
		$embed_text = trim( $name . ( $description ? ' — ' . $description : '' ) );
		$vec        = BizCity_KG_Vector_Index::instance()->embed( $embed_text );
		$embedding  = is_array( $vec ) ? BizCity_KG_Database::encode_embedding( $vec ) : null;

		// PHASE-0.3 Wave 2 — auto-tag identity (sku/order/…) from entity name+desc.
		// Pure regex, no LLM, no DB read. NULL columns when no structured ID found.
		$id_kind = null; $canon_id = null; $id_score = null; $id_source = 'none';
		if ( class_exists( 'BizCity_KG_Identity_Extractor' ) ) {
			$ids = BizCity_KG_Identity_Extractor::extract( $embed_text );
			$primary = BizCity_KG_Identity_Extractor::primary( $ids );
			if ( $primary ) {
				$id_kind   = $primary['id_kind'];
				$canon_id  = $primary['canonical_id'];
				$id_score  = (float) $primary['score'];
				$id_source = 'auto';
			}
		}

		// 2026-04-30 — race-safe insert. Two concurrent learning batches can
		// both miss the SELECT above and both try to INSERT, hitting the
		// UNIQUE (notebook_id, name_normalized) constraint. Suppress the
		// expected duplicate noise and re-SELECT so the loser still gets a
		// valid entity_id back instead of polluting debug.log.
		$prev = $wpdb->suppress_errors( true );
		$ok   = $wpdb->insert( $db->tbl_entities(), [
			'notebook_id'     => (int) $notebook_id,
			'name'            => sanitize_text_field( $name ),
			'name_normalized' => $norm,
			'type'            => sanitize_text_field( $type ?: 'Other' ),
			'description'     => $description,
			'aliases'         => wp_json_encode( [] ),
			'embedding'       => $embedding,
			'weight'          => 1,
			'status'          => 'approved',
			'id_kind'         => $id_kind,
			'canonical_id'    => $canon_id,
			'identity_source' => $id_source,
			'identity_score'  => $id_score,
		] );
		$wpdb->suppress_errors( $prev );

		if ( $ok ) {
			$eid = (int) $wpdb->insert_id;
			do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
			// PHASE-0.7-LEARN-VECTOR-FILE Wave F2 — dual-write entity to filestore.
			if ( $eid && class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
				BizCity_KG_Filestore_Dispatcher::instance()->after_entity_insert( $eid, (int) $notebook_id );
			}
			return $eid;
		}

		// Duplicate hit (race) → another worker already inserted. Bump weight
		// + restore from soft-delete and return its id.
		$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_entities()} WHERE notebook_id=%d AND name_normalized=%s LIMIT 1",
			(int) $notebook_id, $norm
		) );
		if ( $existing_id ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$db->tbl_entities()} SET weight = weight + 1, deleted_at = NULL WHERE id = %d", $existing_id
			) );
			do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
		}
		return $existing_id;
	}

	/**
	 * Upsert a relation between two entities.
	 *
	 * @return int relation_id
	 */
	public function upsert_relation( $notebook_id, $head_id, $predicate, $tail_id, $passage_id = null, $confidence = 1.0 ) {
		global $wpdb;
		$db        = BizCity_KG_Database::instance();
		$pred_norm = BizCity_KG_Database::normalize_name( $predicate );
		if ( ! $head_id || ! $tail_id || $pred_norm === '' ) {
			return 0;
		}

		// PHASE-0.6.6 §10 — UNIQUE (notebook_id, head, predicate, tail) covers soft-deleted rows.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_relations()}
			 WHERE notebook_id=%d AND head_entity_id=%d AND tail_entity_id=%d AND predicate_normalized=%s LIMIT 1",
			(int) $notebook_id, (int) $head_id, (int) $tail_id, $pred_norm
		) );

		if ( $existing ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$db->tbl_relations()} SET weight = weight + 1, deleted_at = NULL WHERE id = %d", $existing
			) );
			// Restoring a soft-deleted relation changes the approved-count.
			do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
			$relation_id = $existing;
		} else {
			// Build relation_text "head predicate tail" + embed it.
			$head_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$db->tbl_entities()} WHERE id = %d", (int) $head_id ) );
			$tail_name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$db->tbl_entities()} WHERE id = %d", (int) $tail_id ) );
			$rel_text  = trim( "{$head_name} {$predicate} {$tail_name}" );

			$vec       = BizCity_KG_Vector_Index::instance()->embed( $rel_text );
			$embedding = is_array( $vec ) ? BizCity_KG_Database::encode_embedding( $vec ) : null;

			// 2026-04-30 — race-safe insert (see upsert_entity).
			$prev = $wpdb->suppress_errors( true );
			$ok   = $wpdb->insert( $db->tbl_relations(), [
				'notebook_id'         => (int) $notebook_id,
				'head_entity_id'      => (int) $head_id,
				'tail_entity_id'      => (int) $tail_id,
				'predicate'           => sanitize_text_field( $predicate ),
				'predicate_normalized'=> $pred_norm,
				'relation_text'       => $rel_text,
				'embedding'           => $embedding,
				'confidence'          => max( 0, min( 1, (float) $confidence ) ),
				'status'              => 'approved',
			] );
			$wpdb->suppress_errors( $prev );

			if ( $ok ) {
				$relation_id = (int) $wpdb->insert_id;
				do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
				// PHASE-0.7-LEARN-VECTOR-FILE Wave F2 — dual-write relation to filestore.
				if ( $relation_id && class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
					BizCity_KG_Filestore_Dispatcher::instance()->after_relation_insert( $relation_id, (int) $notebook_id );
				}
			} else {
				// Race loser — re-SELECT and bump weight.
				$relation_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$db->tbl_relations()}
					 WHERE notebook_id=%d AND head_entity_id=%d AND tail_entity_id=%d AND predicate_normalized=%s LIMIT 1",
					(int) $notebook_id, (int) $head_id, (int) $tail_id, $pred_norm
				) );
				if ( $relation_id ) {
					$wpdb->query( $wpdb->prepare(
						"UPDATE {$db->tbl_relations()} SET weight = weight + 1, deleted_at = NULL WHERE id = %d", $relation_id
					) );
					do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
				}
			}
		}

		// Provenance link.
		if ( $passage_id ) {
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$db->tbl_passage_relations()} (passage_id, relation_id) VALUES (%d, %d)",
				(int) $passage_id, $relation_id
			) );
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$db->tbl_passage_entities()} (passage_id, entity_id) VALUES (%d, %d)",
				(int) $passage_id, (int) $head_id
			) );
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$db->tbl_passage_entities()} (passage_id, entity_id) VALUES (%d, %d)",
				(int) $passage_id, (int) $tail_id
			) );
		}

		return $relation_id;
	}

	/**
	 * Approve a triplet from the queue: upsert entities + relation, mark queue row.
	 *
	 * @return int|WP_Error relation_id
	 */
	public function approve_triplet( $queue_id, $reviewer_id = 0 ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$db->tbl_triplet_queue()} WHERE id = %d", (int) $queue_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Triplet not in queue' );
		}
		if ( $row['status'] !== 'pending' ) {
			return new WP_Error( 'invalid_state', 'Triplet already processed' );
		}

		$head_id = $this->upsert_entity( $row['notebook_id'], $row['subject'], $row['subject_type'] );
		$tail_id = $this->upsert_entity( $row['notebook_id'], $row['object'],  $row['object_type'] );
		$relation_id = $this->upsert_relation(
			$row['notebook_id'], $head_id, $row['predicate'], $tail_id,
			(int) $row['passage_id'], (float) $row['confidence']
		);

		$wpdb->update( $db->tbl_triplet_queue(),
			[
				'status'              => 'approved',
				'reviewed_by'         => (int) $reviewer_id,
				'reviewed_at'         => current_time( 'mysql' ),
				'applied_relation_id' => $relation_id,
			],
			[ 'id' => (int) $queue_id ]
		);

		// Pending→approved transition shifts pending_triplets count → flush stats cache.
		do_action( 'bizcity_kg_notebook_stats_dirty', (int) $row['notebook_id'] );

		return $relation_id;
	}

	public function reject_triplet( $queue_id, $reviewer_id = 0 ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		// Capture notebook_id BEFORE flipping status so we can invalidate the right cache key.
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$db->tbl_triplet_queue()} WHERE id=%d", (int) $queue_id
		) );
		$wpdb->update( $db->tbl_triplet_queue(),
			[
				'status'      => 'rejected',
				'reviewed_by' => (int) $reviewer_id,
				'reviewed_at' => current_time( 'mysql' ),
			],
			[ 'id' => (int) $queue_id ]
		);
		if ( $nb_id ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', $nb_id );
		}
		return true;
	}

	/**
	 * Bulk insert triplets into the review queue.
	 *
	 * @param array $triplets each: [subject, predicate, object, subject_type, object_type, confidence, raw]
	 */
	public function enqueue_triplets( $notebook_id, $passage_id, array $triplets, $raw_llm_output = '' ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$inserted = 0;

		foreach ( $triplets as $t ) {
			$subject = trim( (string) ( $t['subject']   ?? '' ) );
			$pred    = trim( (string) ( $t['predicate'] ?? '' ) );
			$object  = trim( (string) ( $t['object']    ?? '' ) );
			if ( $subject === '' || $pred === '' || $object === '' ) {
				continue;
			}
			$wpdb->insert( $db->tbl_triplet_queue(), [
				'notebook_id'   => (int) $notebook_id,
				'passage_id'    => (int) $passage_id,
				'subject'       => $subject,
				'predicate'     => $pred,
				'object'        => $object,
				'subject_type'  => sanitize_text_field( $t['subject_type'] ?? 'Other' ),
				'object_type'   => sanitize_text_field( $t['object_type']  ?? 'Other' ),
				'confidence'    => max( 0, min( 1, (float) ( $t['confidence'] ?? 0.5 ) ) ),
				'raw_llm_output'=> $raw_llm_output,
				'status'        => 'pending',
			] );
			$inserted++;
		}
		// Pending-triplet count moved → invalidate notebook stats cache.
		if ( $inserted > 0 ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', (int) $notebook_id );
		}
		return $inserted;
	}

	/**
	 * List triplets in queue.
	 */
	public function list_queue( $notebook_id, $status = 'pending', $limit = 100 ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$db->tbl_triplet_queue()}
			 WHERE notebook_id=%d AND status=%s
			 ORDER BY created_at DESC LIMIT %d",
			(int) $notebook_id, $status, (int) $limit
		), ARRAY_A ) ?: [];
	}

	/**
	 * Approve ALL pending triplets for a notebook in one call.
	 *
	 * @return array{approved:int, errors:int}
	 */
	public function approve_all_pending( $notebook_id, $reviewer_id = 0 ) {
		global $wpdb;
		$db          = BizCity_KG_Database::instance();
		$notebook_id = (int) $notebook_id;

		// Many triplets — keep work alive past Cloudflare's 524 wall.
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		$t0 = microtime( true );

		// Count for debug/trace only — the actual loop uses $all_rows fetched below.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_triplet_queue()} WHERE notebook_id=%d AND status='pending' ORDER BY id ASC",
			$notebook_id
		) );

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'approve_start', [
				'notebook_id' => $notebook_id,
				'queue'       => count( $ids ),
			] );
		}

		// PERF FIX — load ALL pending rows in one query instead of SELECT×N inside the loop.
		// Also eliminates the 2×get_var() post-approval lookups by tracking entity IDs
		// directly from upsert_entity() return values.
		// In-process entity cache: "nb:name_normalized" → entity_id.  Prevents duplicate
		// embed() calls when the same subject/object appears across many triplets.
		$all_rows     = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$db->tbl_triplet_queue()} WHERE notebook_id=%d AND status='pending' ORDER BY id ASC",
			$notebook_id
		), ARRAY_A ) ?: [];

		$approved     = 0;
		$errors       = 0;
		$entity_ids   = [];
		$entity_cache = []; // "nb:name_norm" → entity_id — avoids duplicate embed calls

		foreach ( $all_rows as $row ) {
			$queue_id = (int) $row['id'];

			// Resolve head entity (with cache).
			$h_norm = (int) $notebook_id . ':' . BizCity_KG_Database::normalize_name( $row['subject'] );
			if ( ! isset( $entity_cache[ $h_norm ] ) ) {
				$entity_cache[ $h_norm ] = $this->upsert_entity( (int) $row['notebook_id'], $row['subject'], $row['subject_type'] );
			}
			$head_id = $entity_cache[ $h_norm ];

			// Resolve tail entity (with cache).
			$t_norm = (int) $notebook_id . ':' . BizCity_KG_Database::normalize_name( $row['object'] );
			if ( ! isset( $entity_cache[ $t_norm ] ) ) {
				$entity_cache[ $t_norm ] = $this->upsert_entity( (int) $row['notebook_id'], $row['object'], $row['object_type'] );
			}
			$tail_id = $entity_cache[ $t_norm ];

			if ( ! $head_id || ! $tail_id ) {
				$errors++;
				continue;
			}

			$relation_id = $this->upsert_relation(
				(int) $row['notebook_id'], $head_id, $row['predicate'], $tail_id,
				(int) $row['passage_id'], (float) $row['confidence']
			);

			$wpdb->update( $db->tbl_triplet_queue(), [
				'status'              => 'approved',
				'reviewed_by'         => (int) $reviewer_id,
				'reviewed_at'         => current_time( 'mysql' ),
				'applied_relation_id' => $relation_id,
			], [ 'id' => $queue_id ] );

			$approved++;
			$entity_ids[] = $head_id;
			$entity_ids[] = $tail_id;
		}
		$entity_ids = array_values( array_unique( $entity_ids ) );
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'approve_done', [
				'notebook_id' => $notebook_id,
				'approved'    => $approved,
				'errors'      => $errors,
				'entities'    => count( $entity_ids ),
				'elapsed_ms'  => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			] );
		}
		// Realtime kick: let listeners (graph cache busters, central brain mirror,
		// SSE bridges) know the graph just changed so the UI can hot-refresh.
		do_action( 'bizcity_kg_graph_updated', $notebook_id, [
			'reason'     => 'approve_all',
			'approved'   => $approved,
			'entity_ids' => $entity_ids,
		] );
		// Bulk approval moves many triplets pending→approved (and may add entities/relations).
		// upsert_entity/relation already fired the stats-dirty hook per row, but call once
		// more here as a cheap belt-and-suspenders for the queue→approved transition.
		if ( $approved > 0 ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', $notebook_id );
		}
		return [
			'approved'   => $approved,
			'errors'     => $errors,
			'entity_ids' => $entity_ids,
		];
	}

	/**
	 * Return graph subset for a notebook (entities + relations) for visualization.
	 */
	public function get_full_graph( $notebook_id, $limit_nodes = 200 ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$entities = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, type, weight,
			        COALESCE(user_verified,0) AS user_verified,
			        COALESCE(user_corrected,0) AS user_corrected
			 FROM {$db->tbl_entities()}
			 WHERE notebook_id=%d AND status='approved'
			   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
			 ORDER BY weight DESC LIMIT %d",
			(int) $notebook_id, $limit_nodes
		), ARRAY_A ) ?: [];

		if ( empty( $entities ) ) {
			return [ 'nodes' => [], 'links' => [] ];
		}

		$nb_id   = (int) $notebook_id;
		$ids_csv = implode( ',', array_map( static fn( $e ) => (int) $e['id'], $entities ) );
		$relations = $wpdb->get_results(
			"SELECT id, head_entity_id, tail_entity_id, predicate, weight,
			        COALESCE(user_verified,0) AS user_verified,
			        COALESCE(user_corrected,0) AS user_corrected
			 FROM {$db->tbl_relations()}
			 WHERE notebook_id={$nb_id}
			   AND status='approved'
			   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
			   AND head_entity_id IN ({$ids_csv})
			   AND tail_entity_id IN ({$ids_csv})",
			ARRAY_A
		) ?: [];

		return [
			'nodes' => array_map( static function ( $e ) {
				return [
					'id'             => (int) $e['id'],
					'label'          => $e['name'],
					'type'           => $e['type'],
					'weight'         => (int) $e['weight'],
					'user_verified'  => (int) $e['user_verified'],
					'user_corrected' => (int) $e['user_corrected'],
				];
			}, $entities ),
			'links' => array_map( static function ( $r ) {
				return [
					'id'             => (int) $r['id'],
					'source'         => (int) $r['head_entity_id'],
					'target'         => (int) $r['tail_entity_id'],
					'predicate'      => $r['predicate'],
					'weight'         => (int) $r['weight'],
					'user_verified'  => (int) $r['user_verified'],
					'user_corrected' => (int) $r['user_corrected'],
				];
			}, $relations ),
		];
	}

	/**
	 * Phase 4.5b — Global graph: all approved entities + relations across every notebook.
	 * Each node/link includes notebook_id so the client can color-code or group.
	 */
	/**
	 * Phase 4.5b — Global graph: all approved entities + relations.
	 *
	 * @param int   $limit_nodes   Maximum entity nodes to return.
	 * @param int[] $notebook_ids  When non-empty, restrict to these notebooks only
	 *                             (used to scope to the current user's brain).
	 */
	public function get_global_graph( $limit_nodes = 600, $notebook_ids = [], $must_include_ids = [] ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		// Build optional per-user notebook scope clause.
		$nb_clause = '';
		if ( ! empty( $notebook_ids ) ) {
			$safe_ids  = implode( ',', array_map( 'intval', $notebook_ids ) );
			$nb_clause = "AND notebook_id IN ({$safe_ids}) ";
		}

		$entities = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, name, type, weight,
			        COALESCE(user_verified,0) AS user_verified,
			        COALESCE(user_corrected,0) AS user_corrected
			 FROM {$db->tbl_entities()}
			 WHERE status='approved'
			   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
			   {$nb_clause}
			 ORDER BY weight DESC, id ASC
			 LIMIT %d",
			(int) $limit_nodes
		), ARRAY_A ) ?: [];

		// TBR.F2-cite (2026-05-13) — union with must-include entity ids so the
		// caller's highlight set always has matching nodes in the payload, even if
		// they fall outside the top-N weight slice. Same nb scope still applies
		// (admin all-mode bypasses scope upstream).
		if ( ! empty( $must_include_ids ) ) {
			$present = [];
			foreach ( $entities as $e ) $present[ (int) $e['id'] ] = true;
			$missing = array_values( array_filter(
				array_map( 'intval', $must_include_ids ),
				static fn( $id ) => $id > 0 && empty( $present[ $id ] )
			) );
			if ( ! empty( $missing ) ) {
				$miss_csv = implode( ',', $missing );
				$extra = $wpdb->get_results(
					"SELECT id, notebook_id, name, type, weight,
					        COALESCE(user_verified,0) AS user_verified,
					        COALESCE(user_corrected,0) AS user_corrected
					 FROM {$db->tbl_entities()}
					 WHERE status='approved'
					   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
					   {$nb_clause}
					   AND id IN ({$miss_csv})",
					ARRAY_A
				) ?: [];
				$entities = array_merge( $entities, $extra );
			}
		}

		if ( empty( $entities ) ) {
			return [ 'nodes' => [], 'links' => [] ];
		}

		$ids_csv = implode( ',', array_map( static fn( $e ) => (int) $e['id'], $entities ) );
		$relations = $wpdb->get_results(
			"SELECT id, notebook_id, head_entity_id, tail_entity_id, predicate, weight,
			        COALESCE(user_verified,0) AS user_verified,
			        COALESCE(user_corrected,0) AS user_corrected
			 FROM {$db->tbl_relations()}
			 WHERE status='approved'
			   AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')
			   AND head_entity_id IN ({$ids_csv})
			   AND tail_entity_id IN ({$ids_csv})",
			ARRAY_A
		) ?: [];

		return [
			'nodes' => array_map( static function ( $e ) {
				return [
					'id'             => (int) $e['id'],
					'notebook_id'    => (int) $e['notebook_id'],
					'label'          => $e['name'],
					'type'           => $e['type'],
					'weight'         => (int) $e['weight'],
					'user_verified'  => (int) $e['user_verified'],
					'user_corrected' => (int) $e['user_corrected'],
				];
			}, $entities ),
			'links' => array_map( static function ( $r ) {
				return [
					'id'             => (int) $r['id'],
					'notebook_id'    => (int) $r['notebook_id'],
					'source'         => (int) $r['head_entity_id'],
					'target'         => (int) $r['tail_entity_id'],
					'predicate'      => $r['predicate'],
					'weight'         => (int) $r['weight'],
					'user_verified'  => (int) $r['user_verified'],
					'user_corrected' => (int) $r['user_corrected'],
				];
			}, $relations ),
		];
	}

	/**
	 * Merge two entities (canonical wins, others become aliases + relations re-pointed).
	 */
	public function merge_entities( $canonical_id, array $other_ids ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$canonical_id = (int) $canonical_id;
		$other_ids    = array_filter( array_map( 'intval', $other_ids ), static fn( $i ) => $i && $i !== $canonical_id );
		if ( empty( $other_ids ) ) {
			return 0;
		}
		$ids_csv = implode( ',', $other_ids );

		// Re-point relations.
		$wpdb->query( "UPDATE {$db->tbl_relations()} SET head_entity_id={$canonical_id} WHERE head_entity_id IN ({$ids_csv})" );
		$wpdb->query( "UPDATE {$db->tbl_relations()} SET tail_entity_id={$canonical_id} WHERE tail_entity_id IN ({$ids_csv})" );
		$wpdb->query( "UPDATE IGNORE {$db->tbl_passage_entities()} SET entity_id={$canonical_id} WHERE entity_id IN ({$ids_csv})" );
		$wpdb->query( "DELETE FROM {$db->tbl_passage_entities()} WHERE entity_id IN ({$ids_csv})" );

		// Append aliases.
		$alias_rows = $wpdb->get_col( "SELECT name FROM {$db->tbl_entities()} WHERE id IN ({$ids_csv})" ) ?: [];
		// Filestore-first read — inline `aliases` is NULL once Wave F4 cleaned.
		$canon_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, aliases, storage_ver, jsonl_line FROM {$db->tbl_entities()} WHERE id=%d",
			$canonical_id
		), ARRAY_A );
		$canon_rows = $canon_row ? [ $canon_row ] : [];
		if ( $canon_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_entities( $canon_rows, true );
			$canon_row = $canon_rows[0];
		}
		$cur_aliases = $canon_row ? ( json_decode( (string) $canon_row['aliases'], true ) ?: [] ) : [];
		$new_aliases = array_values( array_unique( array_merge( $cur_aliases, $alias_rows ) ) );
		$wpdb->update( $db->tbl_entities(),
			[ 'aliases' => wp_json_encode( $new_aliases ) ],
			[ 'id' => $canonical_id ]
		);
		// Re-dual-write so the JSONL reflects the merged aliases list.
		if ( class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
			BizCity_KG_Filestore_Dispatcher::instance()->backfill_entity( (int) $canonical_id );
		}

		$wpdb->query( "DELETE FROM {$db->tbl_entities()} WHERE id IN ({$ids_csv})" );

		// Hard-deleting entities + re-pointing relations changes counts for the affected notebook(s).
		// Look up the canonical entity's notebook (all merged ids share one notebook by design).
		$canon_nb = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$db->tbl_entities()} WHERE id=%d", $canonical_id
		) );
		if ( $canon_nb ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', $canon_nb );
		}
		return count( $other_ids );
	}

	// ─── Phase 0.5 Sprint 3 ─ Editable graph ───────────────────────────────

	/** Update entity fields (name, type, description, aliases). */
	public function update_entity( $id, array $data ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$id  = (int) $id;
		if ( ! $id ) return new WP_Error( 'bad_id', 'Missing id' );

		$update = [];
		if ( isset( $data['name'] ) && $data['name'] !== '' ) {
			$update['name']            = sanitize_text_field( $data['name'] );
			$update['name_normalized'] = BizCity_KG_Database::normalize_name( $data['name'] );
		}
		if ( isset( $data['type'] ) )           $update['type']        = sanitize_text_field( $data['type'] );
		if ( isset( $data['description'] ) )    $update['description'] = wp_kses_post( $data['description'] );
		if ( isset( $data['aliases'] ) )        $update['aliases']     = wp_json_encode( (array) $data['aliases'] );
		if ( isset( $data['user_verified'] ) )  $update['user_verified']  = (int) (bool) $data['user_verified'];
		if ( isset( $data['user_corrected'] ) ) $update['user_corrected'] = (int) (bool) $data['user_corrected'];

		if ( empty( $update ) ) {
			return $this->get_entity( $id );
		}
		$wpdb->update( $db->tbl_entities(), $update, [ 'id' => $id ] );
		return $this->get_entity( $id );
	}

	public function get_entity( $id ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$db->tbl_entities()} WHERE id=%d", (int) $id
		), ARRAY_A );
		if ( ! $row ) return null;
		$row['aliases'] = json_decode( (string) $row['aliases'], true ) ?: [];
		unset( $row['embedding'] );
		return $row;
	}

	public function soft_delete_entity( $id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		// Capture notebook_id before mutation so we can flush stats cache.
		$nb_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$db->tbl_entities()} WHERE id=%d", (int) $id
		) );
		$wpdb->update( $db->tbl_entities(),
			[ 'deleted_at' => current_time( 'mysql' ), 'user_corrected' => 1 ],
			[ 'id' => (int) $id ]
		);
		// Also soft-delete relations attached to it.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$db->tbl_relations()} SET deleted_at = %s
			 WHERE (head_entity_id=%d OR tail_entity_id=%d) AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00')",
			current_time( 'mysql' ), (int) $id, (int) $id
		) );
		if ( $nb_id ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', $nb_id );
		}
		return true;
	}

	public function update_relation( $id, array $data ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$id = (int) $id;
		if ( ! $id ) return new WP_Error( 'bad_id', 'Missing id' );

		$update = [];
		if ( isset( $data['predicate'] ) && $data['predicate'] !== '' ) {
			$update['predicate']            = sanitize_text_field( $data['predicate'] );
			$update['predicate_normalized'] = BizCity_KG_Database::normalize_name( $data['predicate'] );
		}
		if ( isset( $data['confidence'] ) )     $update['confidence']     = max( 0.0, min( 1.0, (float) $data['confidence'] ) );
		if ( isset( $data['user_verified'] ) )  $update['user_verified']  = (int) (bool) $data['user_verified'];
		if ( isset( $data['user_corrected'] ) ) $update['user_corrected'] = (int) (bool) $data['user_corrected'];
		if ( ! empty( $data['swap'] ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT head_entity_id, tail_entity_id FROM {$db->tbl_relations()} WHERE id=%d", $id
			), ARRAY_A );
			if ( $row ) {
				$update['head_entity_id'] = (int) $row['tail_entity_id'];
				$update['tail_entity_id'] = (int) $row['head_entity_id'];
			}
		}

		if ( empty( $update ) ) {
			return $this->get_relation( $id );
		}
		$wpdb->update( $db->tbl_relations(), $update, [ 'id' => $id ] );
		return $this->get_relation( $id );
	}

	public function get_relation( $id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$db->tbl_relations()} WHERE id=%d", (int) $id
		), ARRAY_A );
	}

	public function soft_delete_relation( $id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$wpdb->update( $db->tbl_relations(),
			[ 'deleted_at' => current_time( 'mysql' ), 'user_corrected' => 1 ],
			[ 'id' => (int) $id ]
		);
		return true;
	}

	/**
	 * Manually add a triplet (subject -> predicate -> object) into a notebook.
	 *
	 * @param array $args { notebook_id, head_name, head_type?, tail_name, tail_type?, predicate, confidence? }
	 * @return array{entity_head:int, entity_tail:int, relation_id:int}|WP_Error
	 */
	public function create_manual_relation( array $args ) {
		$nb        = (int) ( $args['notebook_id'] ?? 0 );
		$head_name = trim( (string) ( $args['head_name'] ?? '' ) );
		$tail_name = trim( (string) ( $args['tail_name'] ?? '' ) );
		$predicate = trim( (string) ( $args['predicate'] ?? '' ) );
		if ( ! $nb || $head_name === '' || $tail_name === '' || $predicate === '' ) {
			return new WP_Error( 'bad_request', 'notebook_id, head_name, tail_name, predicate are required' );
		}

		$head_id = $this->upsert_entity( $nb, $head_name, (string) ( $args['head_type'] ?? 'Other' ) );
		$tail_id = $this->upsert_entity( $nb, $tail_name, (string) ( $args['tail_type'] ?? 'Other' ) );
		if ( ! $head_id || ! $tail_id ) {
			return new WP_Error( 'upsert_failed', 'Could not upsert entities' );
		}

		$rid = $this->upsert_relation( $nb, $head_id, $predicate, $tail_id, null, (float) ( $args['confidence'] ?? 1.0 ) );
		if ( ! $rid ) {
			return new WP_Error( 'upsert_failed', 'Could not create relation' );
		}

		// Mark verified (user-created).
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$wpdb->update( $db->tbl_relations(),
			[ 'user_verified' => 1, 'confidence' => 1.0 ],
			[ 'id' => (int) $rid ]
		);

		return [ 'entity_head' => $head_id, 'entity_tail' => $tail_id, 'relation_id' => $rid ];
	}
}
