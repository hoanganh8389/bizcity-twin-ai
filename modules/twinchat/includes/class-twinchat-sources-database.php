<?php
/**
 * Bizcity Twin AI — TwinChat Sources Database (Unified Bridge)
 *
 * Sprint 4.5d — Wire TwinChat sources to the shared webchat tables:
 *   bizcity_webchat_sources       — raw sources (project_id = notebook_id)
 *   bizcity_webchat_source_chunks — chunks with embeddings
 *
 * Tables are owned/created by the WebChat module; this class is a pure bridge.
 * Column mapping: notebook_id (TwinChat API) ↔ project_id (webchat schema).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Sources_Database {

	const SCHEMA_VERSION_OPTION = 'bizcity_twinchat_sources_schema_version';
	const SCHEMA_VERSION        = '2.0.0'; // bumped to trigger re-check

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Shared sources table (owned by WebChat module). */
	public function table_sources() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_webchat_sources';
	}

	/** Shared source_chunks table (owned by WebChat module). */
	public function table_source_chunks() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_webchat_source_chunks';
	}

	/**
	 * No DDL needed — tables owned by WebChat module.
	 * Triggers WebChat install if tables are missing.
	 */
	public function maybe_install() {
		$tbl = $this->table_sources();
		// Cached check — chỉ hit DB lần đầu / blog (option `bizcity_known_tables`).
		if ( function_exists( 'bizcity_table_exists' ) ? bizcity_table_exists( $tbl ) : true ) {
			return;
		}
		if ( class_exists( 'BizCity_WebChat_Database' ) ) {
			BizCity_WebChat_Database::instance()->create_tables();
			if ( function_exists( 'bizcity_table_cache_remember' ) ) {
				bizcity_table_cache_remember( $tbl );
			}
		}
	}

	/* ─────────────────────────  CRUD helpers  ───────────────────────── */

	/**
	 * Insert a source row. Returns new ID or 0.
	 *
	 * Accepts `notebook_id` OR `project_id` — both map to the `project_id` column.
	 */
	public function insert_source( array $args ) {
		global $wpdb;

		// Accept both legacy 'notebook_id' and canonical 'project_id'.
		$notebook_id = (int) ( $args['project_id'] ?? $args['notebook_id'] ?? 0 );
		$user_id     = (int) ( $args['user_id']    ?? 0 );

		$defaults = [
			'user_id'          => $user_id,
			'project_id'       => (string) $notebook_id,
			'title'            => '',
			'source_type'      => 'text',
			'source_url'       => '',
			'attachment_id'    => 0,
			'content_text'     => '',
			'content_hash'     => '',
			'char_count'       => 0,
			'token_estimate'   => 0,
			'chunk_count'      => 0,
			'embedding_model'  => '',
			'embedding_status' => 'pending',
			'error_message'    => null,
			'metadata'         => null,
		];

		// Filter to only columns webchat_sources has.
		$allowed = array_keys( $defaults );
		$row     = array_intersect_key(
			array_merge( $defaults, array_intersect_key( $args, array_flip( $allowed ) ) ),
			$defaults
		);
		$row['project_id'] = (string) $notebook_id;

		if ( is_array( $row['metadata'] ) ) {
			$row['metadata'] = wp_json_encode( $row['metadata'] );
		}
		if ( $row['content_hash'] === '' && $row['content_text'] !== '' ) {
			$row['content_hash'] = hash( 'sha256', (string) $row['content_text'] );
		}
		if ( $row['char_count'] === 0 && $row['content_text'] !== '' ) {
			$row['char_count']     = mb_strlen( (string) $row['content_text'] );
			$row['token_estimate'] = (int) ceil( $row['char_count'] / 4 );
		}
		$row['created_at'] = current_time( 'mysql', true );

		$ok = $wpdb->insert( $this->table_sources(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function update_source( $source_id, array $patch ) {
		global $wpdb;
		$source_id = (int) $source_id;
		if ( $source_id <= 0 || empty( $patch ) ) {
			return false;
		}
		if ( isset( $patch['metadata'] ) && is_array( $patch['metadata'] ) ) {
			$patch['metadata'] = wp_json_encode( $patch['metadata'] );
		}
		return false !== $wpdb->update( $this->table_sources(), $patch, [ 'id' => $source_id ] );
	}

	/**
	 * Find an existing source by content_hash within a notebook (dedup).
	 *
	 * Accepts `notebook_id` as alias for `project_id`.
	 */
	public function find_by_hash( $notebook_id, $hash ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$this->table_sources()} WHERE project_id = %s AND content_hash = %s LIMIT 1",
			(string) (int) $notebook_id,
			(string) $hash
		), ARRAY_A );
		return $row ? (int) $row['id'] : 0;
	}

	public function get_source( $source_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table_sources()} WHERE id = %d LIMIT 1",
			(int) $source_id
		), ARRAY_A );
		return $row ?: null;
	}

	public function list_sources( $notebook_id, array $args = [] ) {
		global $wpdb;
		$limit   = max( 1, min( 200, (int) ( $args['limit']  ?? 50 ) ) );
		$offset  = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$search  = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		$sql    = "SELECT id,
		                  project_id   AS notebook_id,
		                  user_id,
		                  title,
		                  source_type,
		                  source_url,
		                  attachment_id,
		                  content_hash,
		                  char_count,
		                  token_estimate,
		                  chunk_count,
		                  embedding_model,
		                  embedding_status,
		                  error_message,
		                  created_at
		             FROM {$this->table_sources()}
		            WHERE project_id = %s";
		$params = [ (string) (int) $notebook_id ];

		if ( $search !== '' ) {
			$sql      .= " AND title LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$sql      .= " ORDER BY id DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	public function delete_source( $source_id ) {
		global $wpdb;
		$source_id = (int) $source_id;
		if ( $source_id <= 0 ) return false;
		// Hard delete from webchat_sources (no status column).
		$wpdb->delete( $this->table_sources(), [ 'id' => $source_id ] );
		$wpdb->delete( $this->table_source_chunks(), [ 'source_id' => $source_id ] );
		return true;
	}

	/**
	 * Cascade-delete every source (and its chunks) belonging to a notebook.
	 * Called when a notebook is removed via library trash → keep KG + sources tables in sync.
	 *
	 * @param int $notebook_id  Mapped to the `project_id` column on webchat_sources.
	 * @return int Number of source rows deleted (0 on no-op).
	 */
	public function delete_for_notebook( $notebook_id ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) return 0;
		// Collect ids first so we can wipe their chunks.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$this->table_sources()} WHERE project_id = %s",
			(string) $notebook_id
		) );
		if ( ! $ids ) return 0;
		$ids = array_map( 'intval', $ids );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// Delete chunks via JOIN-friendly IN list.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table_source_chunks()} WHERE source_id IN ({$ph})",
			$ids
		) );
		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table_sources()} WHERE project_id = %s",
			(string) $notebook_id
		) );
		return $deleted;
	}

	/* ─────────────────────────  Chunks  ───────────────────────── */

	/**
	 * Insert a chunk. Accepts notebook_id (ignored on webchat_source_chunks — no such column).
	 */
	public function insert_chunk( array $args ) {
		global $wpdb;

		$row = [
			'source_id'       => (int)    ( $args['source_id']       ?? 0 ),
			'chunk_index'     => (int)    ( $args['chunk_index']      ?? 0 ),
			'content'         => (string) ( $args['content']          ?? '' ),
			'token_count'     => (int)    ( $args['token_count']      ?? 0 ),
			'embedding'       => null,
			'embedding_model' => (string) ( $args['embedding_model']  ?? '' ),
		];

		$emb = $args['embedding'] ?? null;
		if ( is_array( $emb ) ) {
			$row['embedding'] = wp_json_encode( $emb );
		} elseif ( is_string( $emb ) && $emb !== '' ) {
			$row['embedding'] = $emb;
		}

		$row['created_at'] = current_time( 'mysql', true );

		$ok = $wpdb->insert( $this->table_source_chunks(), $row );
		if ( ! $ok ) {
			return 0;
		}
		$chunk_id = (int) $wpdb->insert_id;

		// Phase 0.6.5 — Wave C: notify KG-Hub mirror so this chunk lands in
		// kg_source_chunks too (handler is feature-flagged + idempotent).
		if ( $chunk_id > 0 && (int) $row['source_id'] > 0 ) {
			do_action( 'bizcity_kg_legacy_chunks_persisted', [
				'cortex'              => 'webchat',
				'legacy_source_id'    => (int) $row['source_id'],
				'legacy_source_table' => $wpdb->prefix . 'bizcity_webchat_sources',
				'legacy_chunks_table' => $this->table_source_chunks(),
			] );
		}

		return $chunk_id;
	}

	public function list_chunks( $source_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, chunk_index, content, token_count, embedding, embedding_model
			   FROM {$this->table_source_chunks()}
			  WHERE source_id = %d
			  ORDER BY chunk_index ASC",
			(int) $source_id
		), ARRAY_A ) ?: [];
	}
}
