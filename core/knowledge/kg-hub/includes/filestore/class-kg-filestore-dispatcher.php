<?php
/**
 * Bizcity Twin AI — KG Filestore Dispatcher (Phase 0.7 Wave F1).
 *
 * Single entry-point for dual-writing passage / entity / relation content into
 * the filestore companion files. Designed so existing INSERT call-sites in
 * BizCity_KG_Source_Service / BizCity_KG_Graph_Service add ONE line each:
 *
 *   $pid = (int) $wpdb->insert_id;
 *   BizCity_KG_Filestore_Dispatcher::instance()->after_passage_insert(
 *       $pid, $notebook_id, $content, $frontmatter
 *   );
 *
 * Gating:
 *   wp_option `bizcity_kg_filestore_dual_write`
 *      0                         = no-op, log only (legacy override)
 *      1 (default since 2026-05-22) = dual-write on, UPDATE storage_ver=2 after flush
 *
 *   Filter `bizcity_kg_filestore_dual_write_default` (bool) lets ops disable
 *   the new default without touching wp_options — return false to opt back to 0.
 *
 * Failure handling:
 *   - File write fails → log error, leave storage_ver=1 (legacy reader path
 *     still serves correctly from kg_passages.content).
 *   - MySQL UPDATE fails → file is orphaned; backfill cron (F2) will reconcile.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Filestore_Dispatcher {

	const OPT_DUAL_WRITE = 'bizcity_kg_filestore_dual_write';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function is_enabled() {
		// 2026-05-22 — flipped default 0 → 1 per R-LEARN root-cause audit
		// (PHASE-0.7-LEARN-VECTOR-FILE Wave F1 ship). Existing sites that opted
		// out explicitly (option row = "0") still see OFF. Ops can also override
		// the new default via filter `bizcity_kg_filestore_dual_write_default`.
		$default = apply_filters( 'bizcity_kg_filestore_dual_write_default', 1 );
		return (int) get_option( self::OPT_DUAL_WRITE, (int) $default ) === 1;
	}

	// -------------------------------------------------------------------
	// Passage dual-write
	// -------------------------------------------------------------------

	/**
	 * Called after $wpdb->insert( tbl_passages, ... ).
	 *
	 * @param int    $passage_id    LAST_INSERT_ID
	 * @param int    $notebook_id
	 * @param string $content       Body text — must equal the value inserted into `content` column
	 * @param array  $extra_meta    Optional. Frontmatter overrides + extra meta.
	 * @return bool|WP_Error        true on success, WP_Error or false on disabled.
	 */
	public function after_passage_insert( $passage_id, $notebook_id, $content, array $extra_meta = [] ) {
		if ( ! self::is_enabled() ) { return false; }
		if ( $passage_id <= 0 || $notebook_id <= 0 ) { return false; }

		$folder = BizCity_KG_Notebook_Folder::instance();
		$uuid   = $folder->notebook_uuid( $notebook_id );
		if ( is_wp_error( $uuid ) ) {
			error_log( '[KG Filestore] passage ' . $passage_id . ' notebook_uuid: ' . $uuid->get_error_message() );
			return $uuid;
		}

		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_passages();

		// Fetch the canonical row so the frontmatter snapshot matches MySQL truth.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, source_id, chunk_id, origin, content_hash, token_count, extraction_status, metadata, created_at FROM {$tbl} WHERE id=%d LIMIT 1",
			(int) $passage_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'kg_filestore_no_row', 'passage row missing after insert' );
		}

		$meta = is_string( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : null;
		if ( ! is_array( $meta ) ) { $meta = []; }
		$front = array_merge( [
			'id'                => (int) $row['id'],
			'notebook_id'       => (int) $row['notebook_id'],
			'source_id'         => $row['source_id'] !== null ? (int) $row['source_id'] : null,
			'chunk_id'          => $row['chunk_id']   !== null ? (int) $row['chunk_id']   : null,
			'origin'            => (string) $row['origin'],
			'content_hash'      => (string) $row['content_hash'],
			'token_count'       => (int) $row['token_count'],
			'extraction_status' => (string) $row['extraction_status'],
			'created_at'        => (string) $row['created_at'],
			'metadata'          => $meta,
		], $extra_meta );

		$res = BizCity_KG_Passage_File_Store::instance()->write(
			(int) $passage_id, (string) $uuid, $front, (string) $content
		);
		if ( is_wp_error( $res ) ) {
			error_log( '[KG Filestore] passage ' . $passage_id . ' write: ' . $res->get_error_message() );
			return $res;
		}

		// Flip storage_ver only after successful file flush.
		$ok = $wpdb->update(
			$tbl,
			[
				'storage_ver' => 2,
				'file_shard'  => (int) $res['shard'],
				'file_offset' => (int) $res['file_offset'],
				'file_length' => (int) $res['file_length'],
			],
			[ 'id' => (int) $passage_id ],
			[ '%d', '%d', '%d', '%d' ],
			[ '%d' ]
		);
		if ( false === $ok ) {
			error_log( '[KG Filestore] passage ' . $passage_id . ' UPDATE storage_ver failed: ' . $wpdb->last_error );
		}
		return true;
	}

	/**
	 * Backfill one existing passage row (storage_ver=1 → 2). Wave F2 uses this.
	 */
	public function backfill_passage( $passage_id ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_passages();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, content, storage_ver FROM {$tbl} WHERE id=%d LIMIT 1",
			(int) $passage_id
		), ARRAY_A );
		if ( ! $row ) { return new WP_Error( 'kg_filestore_missing', 'row not found' ); }
		if ( (int) $row['storage_ver'] === 2 ) { return true; }
		return $this->after_passage_insert(
			(int) $row['id'], (int) $row['notebook_id'], (string) $row['content']
		);
	}

	// -------------------------------------------------------------------
	// Entity dual-write
	// -------------------------------------------------------------------

	public function after_entity_insert( $entity_id, $notebook_id ) {
		if ( ! self::is_enabled() ) { return false; }
		if ( $entity_id <= 0 || $notebook_id <= 0 ) { return false; }

		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( $notebook_id );
		if ( is_wp_error( $uuid ) ) { return $uuid; }

		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_entities();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, name, name_normalized, type, description, aliases, weight, status, character_uuid, metadata, id_kind, canonical_id FROM {$tbl} WHERE id=%d LIMIT 1",
			(int) $entity_id
		), ARRAY_A );
		if ( ! $row ) { return new WP_Error( 'kg_filestore_no_row', 'entity row missing' ); }

		$aliases = is_string( $row['aliases'] ) ? json_decode( $row['aliases'], true ) : null;
		$meta    = is_string( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : null;

		$line = BizCity_KG_Entity_File_Store::instance()->append( $entity_id, $uuid, [
			'id'               => (int) $row['id'],
			'name'             => (string) $row['name'],
			'name_normalized'  => (string) $row['name_normalized'],
			'type'             => (string) $row['type'],
			'desc'             => (string) ( $row['description'] ?? '' ),
			'aliases'          => is_array( $aliases ) ? $aliases : [],
			'weight'           => (int) $row['weight'],
			'status'           => (string) $row['status'],
			'cuuid'            => $row['character_uuid'] ?: null,
			'id_kind'          => $row['id_kind'] ?: null,
			'canonical_id'     => $row['canonical_id'] ?: null,
			'meta'             => is_array( $meta ) ? $meta : [],
		] );
		if ( is_wp_error( $line ) ) {
			error_log( '[KG Filestore] entity ' . $entity_id . ' append: ' . $line->get_error_message() );
			return $line;
		}
		$wpdb->update( $tbl,
			[ 'storage_ver' => 2, 'jsonl_line' => (int) $line ],
			[ 'id' => (int) $entity_id ],
			[ '%d', '%d' ], [ '%d' ]
		);
		return true;
	}

	public function backfill_entity( $entity_id ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$nb  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$db->tbl_entities()} WHERE id=%d AND storage_ver=1 LIMIT 1",
			(int) $entity_id
		) );
		if ( $nb <= 0 ) { return false; }
		return $this->after_entity_insert( (int) $entity_id, $nb );
	}

	// -------------------------------------------------------------------
	// Relation dual-write
	// -------------------------------------------------------------------

	public function after_relation_insert( $relation_id, $notebook_id ) {
		if ( ! self::is_enabled() ) { return false; }
		if ( $relation_id <= 0 || $notebook_id <= 0 ) { return false; }

		$uuid = BizCity_KG_Notebook_Folder::instance()->notebook_uuid( $notebook_id );
		if ( is_wp_error( $uuid ) ) { return $uuid; }

		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tbl = $db->tbl_relations();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, head_entity_id, tail_entity_id, predicate, predicate_normalized, relation_text, weight, confidence, status, metadata FROM {$tbl} WHERE id=%d LIMIT 1",
			(int) $relation_id
		), ARRAY_A );
		if ( ! $row ) { return new WP_Error( 'kg_filestore_no_row', 'relation row missing' ); }

		$meta = is_string( $row['metadata'] ) ? json_decode( $row['metadata'], true ) : null;

		$line = BizCity_KG_Relation_File_Store::instance()->append( $relation_id, $uuid, [
			'id'                   => (int) $row['id'],
			'head'                 => (int) $row['head_entity_id'],
			'tail'                 => (int) $row['tail_entity_id'],
			'predicate'            => (string) $row['predicate'],
			'predicate_normalized' => (string) $row['predicate_normalized'],
			'relation_text'        => (string) ( $row['relation_text'] ?? '' ),
			'weight'               => (int) $row['weight'],
			'confidence'           => (float) $row['confidence'],
			'status'               => (string) $row['status'],
			'meta'                 => is_array( $meta ) ? $meta : [],
		] );
		if ( is_wp_error( $line ) ) {
			error_log( '[KG Filestore] relation ' . $relation_id . ' append: ' . $line->get_error_message() );
			return $line;
		}
		$wpdb->update( $tbl,
			[ 'storage_ver' => 2, 'jsonl_line' => (int) $line ],
			[ 'id' => (int) $relation_id ],
			[ '%d', '%d' ], [ '%d' ]
		);
		return true;
	}

	public function backfill_relation( $relation_id ) {
		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$nb  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT notebook_id FROM {$db->tbl_relations()} WHERE id=%d AND storage_ver=1 LIMIT 1",
			(int) $relation_id
		) );
		if ( $nb <= 0 ) { return false; }
		return $this->after_relation_insert( (int) $relation_id, $nb );
	}
}
