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
	 * Phase 0.21 Wave 2 — canonical chunk table when "unified primary" flag ON.
	 * Returns the table name we should READ from (and where new chunks land
	 * when self::write_target_unified() is true).
	 */
	public function table_source_chunks_canonical() {
		global $wpdb;
		if ( self::write_target_unified() && class_exists( 'BizCity_KG_Database' ) ) {
			return BizCity_KG_Database::instance()->tbl_source_chunks();
		}
		return $this->table_source_chunks();
	}

	/**
	 * Feature flag: when ON, insert_chunk() writes DIRECTLY to kg_source_chunks
	 * and SKIPS the legacy webchat_source_chunks table entirely.
	 *
	 * Phase 0.21 Wave 2 final — DEFAULT ON. The legacy webchat_source_chunks
	 * table is no longer written to; it remains for read-fallback only.
	 * Toggle OFF only for emergency rollback via:
	 *   - WP option `bizcity_kg_chunks_unified_primary` (set to 0)
	 *   - filter   `bizcity_kg_chunks_unified_primary` (return false)
	 *   - the .bin Diagnostic admin page (Tools → KG .bin Diagnostic).
	 */
	public static function write_target_unified() {
		$opt = (bool) get_option( 'bizcity_kg_chunks_unified_primary', true );
		return (bool) apply_filters( 'bizcity_kg_chunks_unified_primary', $opt );
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
		// Delete chunks from BOTH legacy + canonical to avoid orphans across the toggle.
		$wpdb->delete( $this->table_source_chunks(), [ 'source_id' => $source_id ] );
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$kg_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
			if ( $kg_tbl !== $this->table_source_chunks() ) {
				$wpdb->delete( $kg_tbl, [ 'source_id' => $source_id ] );
			}
		}
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
		// Delete chunks via JOIN-friendly IN list — clean BOTH legacy and canonical.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table_source_chunks()} WHERE source_id IN ({$ph})",
			$ids
		) );
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$kg_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
			if ( $kg_tbl !== $this->table_source_chunks() ) {
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$kg_tbl} WHERE source_id IN ({$ph})",
					$ids
				) );
			}
		}
		$deleted = (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table_sources()} WHERE project_id = %s",
			(string) $notebook_id
		) );
		return $deleted;
	}

	/* ─────────────────────────  Chunks  ───────────────────────── */

	/**
	 * Insert a chunk.
	 *
	 * Default path (flag OFF) → writes to legacy `bizcity_webchat_source_chunks`
	 *   and fires `bizcity_kg_legacy_chunks_persisted` for the mirror handler.
	 *
	 * Unified-primary path (flag ON) → writes DIRECTLY to `bizcity_kg_source_chunks`,
	 *   skips webchat_source_chunks entirely. notebook_id is looked up from the
	 *   parent webchat_sources row when not supplied.
	 */
	public function insert_chunk( array $args ) {
		global $wpdb;

		$source_id = (int) ( $args['source_id'] ?? 0 );

		// Resolve embedding payload once.
		$emb_str = null;
		$emb     = $args['embedding'] ?? null;
		if ( is_array( $emb ) ) {
			$emb_str = wp_json_encode( $emb );
		} elseif ( is_string( $emb ) && $emb !== '' ) {
			$emb_str = $emb;
		}

		// ─── Unified primary path ────────────────────────────────────────
		if ( self::write_target_unified() && class_exists( 'BizCity_KG_Database' ) ) {
			$kg_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();

			// Prefer kg_sources.id (canonical) when caller supplies it; fall back to legacy webchat_sources.id.
			$kg_source_id = (int) ( $args['kg_source_id'] ?? 0 );
			$canonical_source_id = $kg_source_id > 0 ? $kg_source_id : $source_id;

			// Look up notebook_id + project_id from parent webchat_sources when missing.
			$notebook_id = isset( $args['notebook_id'] ) ? (int) $args['notebook_id'] : 0;
			$project_id  = isset( $args['project_id'] ) ? (string) $args['project_id'] : '';
			if ( $source_id > 0 && ( $notebook_id <= 0 || $project_id === '' ) ) {
				$parent = $wpdb->get_row( $wpdb->prepare(
					"SELECT project_id, user_id FROM {$this->table_sources()} WHERE id = %d LIMIT 1",
					$source_id
				), ARRAY_A );
				if ( $parent ) {
					if ( $project_id === '' )  { $project_id  = (string) $parent['project_id']; }
					if ( $notebook_id <= 0 )   { $notebook_id = (int) $parent['project_id']; }
				}
			}

			$content      = (string) ( $args['content'] ?? '' );
			$content_hash = $content !== '' ? hash( 'sha256', $content ) : '';

			$row = [
				'source_id'   => $canonical_source_id,
				'blog_id'     => (int) get_current_blog_id(),
				'project_id'  => $project_id,
				'plugin_name' => 'twinchat',
				'notebook_id' => $notebook_id > 0 ? $notebook_id : null,
				'chunk_index' => (int) ( $args['chunk_index'] ?? 0 ),
				'content'     => $content,
				'content_hash'=> $content_hash,
				'token_count' => (int) ( $args['token_count'] ?? 0 ),
				// Filestore-only (Rule v2.0): NULL column; vector goes into .bin via register_chunk below.
				'embedding'   => null,
				'embed_model' => (string) ( $args['embedding_model'] ?? '' ),
				'embed_status'=> $emb_str ? 'ready' : 'pending',
				'origin'      => 'source',
				'scope_type'  => 'notebook',
				'scope_id'    => (string) ( $notebook_id > 0 ? $notebook_id : $project_id ),
				'created_at'  => current_time( 'mysql', true ),
			];

			$ok = $wpdb->insert( $kg_tbl, $row );
			if ( ! $ok ) {
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'twinchat_unified_insert_failed', [
						'source_id' => $source_id,
						'error'     => $wpdb->last_error,
					] );
				}
				return 0;
			}
			$chunk_id = (int) $wpdb->insert_id;

			// Phase 0.21 Wave 2 — push embedding to .bin file store.
			if ( $chunk_id > 0 && $notebook_id > 0 && is_array( $emb ) && ! empty( $emb )
				&& class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
				BizCity_KG_Embedding_Writer::instance()->register_chunk(
					$notebook_id, $chunk_id, $emb, null, $source_id
				);
			}

			return $chunk_id;
		}

		// ─── Legacy path (emergency rollback only — flag must be explicitly OFF) ──
		// Phase 0.21 Wave 2 final: dual-write hook intentionally REMOVED. When the
		// flag is OFF the chunk lands in webchat_source_chunks ONLY (no mirror to
		// kg_source_chunks). This avoids the legacy table accumulating rows that
		// duplicate kg_source_chunks entries.
		$row = [
			'source_id'       => $source_id,
			'chunk_index'     => (int) ( $args['chunk_index']     ?? 0 ),
			'content'         => (string) ( $args['content']      ?? '' ),
			'token_count'     => (int) ( $args['token_count']     ?? 0 ),
			'embedding'       => $emb_str,
			'embedding_model' => (string) ( $args['embedding_model'] ?? '' ),
			'created_at'      => current_time( 'mysql', true ),
		];

		$ok = $wpdb->insert( $this->table_source_chunks(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function list_chunks( $source_id ) {
		global $wpdb;
		// Read from canonical (kg_source_chunks when unified flag ON, else legacy).
		$tbl = $this->table_source_chunks_canonical();
		// Both tables share these column names; embed_model alias for legacy compatibility.
		$col_model = ( $tbl === $this->table_source_chunks() ) ? 'embedding_model' : 'embed_model AS embedding_model';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, chunk_index, content, token_count, embedding, {$col_model}
			   FROM {$tbl}
			  WHERE source_id = %d
			  ORDER BY chunk_index ASC",
			(int) $source_id
		), ARRAY_A ) ?: [];
	}
}
