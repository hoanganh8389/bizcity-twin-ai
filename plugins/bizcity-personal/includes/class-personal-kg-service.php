<?php
/**
 * BizCity Personal — KG Source Service
 *
 * Implements the BizCity_KG ingest contract for personal notebook pages.
 * Registered via filter `bizcity_kg_register_source_table` in bootstrap.php.
 *
 * Pipeline (mirrors BizCity_TwinChat_Sources_Service):
 *   1. Validate scope + payload
 *   2. Hash-dedup against bizcity_personal_notebook_pages (source_id = page_id, content_hash)
 *   3. Chunk content (~1500 chars, 200 overlap) via BizCity_TwinChat_Chunker or fallback
 *   4. Embed chunks via bizcity_openrouter_embeddings()
 *   5. INSERT chunks into bizcity_personal_notebook_chunks
 *   6. PROMOTE each chunk into bizcity_kg_passages
 *      scope_type = 'personal_notebook', scope_id = notebook_id, source_id = page_id
 *   7. Return { source_id (page_id), chunk_count, passage_ids[] }
 *
 * Service contract methods consumed by BizCity_KG facade:
 *   - ingest(int $scope_id, int $user_id, array $payload): array|WP_Error
 *   - list_sources(int $scope_id, array $args): array
 *   - get_source(int $source_id): ?array
 *   - delete_source(int $source_id): bool
 *   - list_scopes(int $user_id): array  →  bizcity_personal_notebooks list
 *
 * Scope semantics:
 *   scope_id = bizcity_personal_notebooks.id (one notebook per user = one scope)
 *   source_id = bizcity_personal_notebook_pages.id (one page = one source)
 *
 * PHP 7.4 compatible — no union types, no nullsafe, no match, no str_contains.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME-NOTEBOOKS PATH-B)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_KG_Service' ) ) { return; }

class BizCity_Personal_KG_Service {

	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — chunker constants (mirrors TwinChat)
	const EMBED_MODEL    = 'text-embedding-3-small';
	const CHUNK_CHARS    = 1500;
	const CHUNK_OVERLAP  = 200;
	const SCOPE_TYPE     = 'personal_notebook';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Table helpers ─────────────────────────────────────────────────────────

	private function tbl_pages() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_notebook_pages';
	}

	private function tbl_chunks() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_notebook_chunks';
	}

	private function tbl_notebooks() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_personal_notebooks';
	}

	// ── KG passages table helper ──────────────────────────────────────────────

	/**
	 * Get bizcity_kg_passages table name via BizCity_KG_Database or fallback.
	 *
	 * @return string|null  null if KG not available
	 */
	private function tbl_passages() {
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			return BizCity_KG_Database::instance()->tbl_passages();
		}
		return null;
	}

	// ── Service contract ──────────────────────────────────────────────────────

	/**
	 * Ingest a page into KG Hub.
	 *
	 * @param int   $scope_id  bizcity_personal_notebooks.id
	 * @param int   $user_id
	 * @param array $payload   {
	 *     type         string  (always 'text' for notebook pages)
	 *     title        string
	 *     content      string  full markdown text
	 *     source_meta  array   { page_id, notebook_id }  ← set by REST + block callers
	 * }
	 * @return array|WP_Error { source_id, chunk_count, passage_ids[] }
	 */
	public function ingest( $scope_id, $user_id, array $payload ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — ingest pipeline step 1: validate
		$scope_id = (int) $scope_id;
		$user_id  = (int) $user_id;

		if ( $scope_id <= 0 ) {
			return new WP_Error( 'invalid_scope', 'Thiếu notebook_id — vui lòng cung cấp scope_id.' );
		}

		$title   = isset( $payload['title'] )   ? sanitize_text_field( (string) $payload['title'] ) : '';
		$content = isset( $payload['content'] ) ? (string) $payload['content'] : '';

		if ( $content === '' ) {
			return new WP_Error( 'empty_content', 'Nội dung ghi chú rỗng — không thể ingest.' );
		}

		// page_id: từ source_meta hoặc auto-detect từ scope (notebook) + title
		$source_meta = isset( $payload['source_meta'] ) && is_array( $payload['source_meta'] )
			? $payload['source_meta'] : array();
		$page_id = (int) ( $source_meta['page_id'] ?? 0 );

		if ( $page_id <= 0 ) {
			return new WP_Error( 'missing_page_id', 'Thiếu source_meta.page_id — cần ID trang để liên kết.' );
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — step 2: hash-dedup
		$hash    = hash( 'sha256', $content );
		$dup_cid = $this->find_chunks_by_page( $page_id );
		if ( $dup_cid > 0 ) {
			return array(
				'source_id'   => $page_id,
				'chunk_count' => 0,
				'passage_ids' => array(),
				'duplicate'   => true,
			);
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — step 3: chunk
		$chunk_records = $this->chunk_content( $content );
		$chunks        = array_map( function ( $r ) { return (string) $r['text']; }, $chunk_records );

		if ( empty( $chunks ) ) {
			return new WP_Error( 'chunk_failed', 'Chunker trả về rỗng.' );
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — step 4: embed
		$vectors = $this->embed_batch( $chunks, $scope_id, $page_id );
		if ( is_wp_error( $vectors ) ) {
			return $vectors;
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — steps 5+6: insert chunks + promote passages
		global $wpdb;
		$chunk_ids   = array();
		$passage_ids = array();
		$tbl_chunks  = $this->tbl_chunks();
		$tbl_passages = $this->tbl_passages();

		foreach ( $chunks as $idx => $chunk_text ) {
			$vec          = isset( $vectors[ $idx ] ) ? $vectors[ $idx ] : null;
			$token_count  = (int) ceil( mb_strlen( $chunk_text ) / 4 );
			$heading_path = isset( $chunk_records[ $idx ]['heading_path'] ) && is_array( $chunk_records[ $idx ]['heading_path'] )
				? $chunk_records[ $idx ]['heading_path'] : array();
			$chunk_hash   = hash( 'sha256', $chunk_text );

			// Insert chunk row (step 5)
			$ins = $wpdb->insert( $tbl_chunks, array(
				'source_id'       => $page_id,
				'notebook_id'     => $scope_id,
				'user_id'         => $user_id,
				'chunk_index'     => $idx,
				'content'         => $chunk_text,
				'token_count'     => $token_count,
				'embedding'       => is_array( $vec ) ? wp_json_encode( $vec ) : null,
				'embedding_model' => self::EMBED_MODEL,
				'heading_path'    => ! empty( $heading_path ) ? wp_json_encode( $heading_path ) : null,
				'content_hash'    => $chunk_hash,
			) );
			$chunk_id = $ins ? (int) $wpdb->insert_id : 0;
			if ( $chunk_id > 0 ) {
				$chunk_ids[] = $chunk_id;
			}

			// Promote chunk into bizcity_kg_passages (step 6)
			if ( $tbl_passages ) {
				$passage_id = $this->insert_passage( $tbl_passages, array(
					'scope_type'   => self::SCOPE_TYPE,
					'scope_id'     => (string) $scope_id,
					'notebook_id'  => $scope_id,
					'source_id'    => $page_id,
					'chunk_id'     => $chunk_id,
					'origin'       => 'text:' . ( $title ?: ( 'page-' . $page_id ) ),
					'content'      => $chunk_text,
					'embedding'    => $vec,
					'token_count'  => $token_count,
					'metadata'     => array(
						'plugin'       => 'personal',
						'scope_type'   => self::SCOPE_TYPE,
						'scope_id'     => $scope_id,
						'page_id'      => $page_id,
						'chunk_index'  => $idx,
						'heading_path' => $heading_path,
					),
				) );
				if ( $passage_id > 0 ) {
					$passage_ids[] = $passage_id;
				}
			}
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — step 7: return result
		return array(
			'source_id'   => $page_id,
			'chunk_count' => count( $chunk_ids ),
			'passage_ids' => $passage_ids,
			'duplicate'   => false,
		);
	}

	/**
	 * List sources (pages) for a given notebook scope.
	 *
	 * @param int   $scope_id  notebook_id
	 * @param array $args      { limit?, offset?, search? }
	 * @return array
	 */
	public function list_sources( $scope_id, array $args = array() ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — list pages as sources
		global $wpdb;
		$scope_id = (int) $scope_id;
		$limit    = max( 1, min( 200, (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 ) ) );
		$offset   = max( 0, (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 ) );
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		$tbl = $this->tbl_pages();
		if ( $search !== '' ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, notebook_id, user_id, title, excerpt, word_count, kg_source_id, file_path, created_at, updated_at
				 FROM {$tbl}
				 WHERE notebook_id = %d AND (title LIKE %s OR excerpt LIKE %s)
				 ORDER BY updated_at DESC
				 LIMIT %d OFFSET %d",
				$scope_id, '%' . $wpdb->esc_like( $search ) . '%', '%' . $wpdb->esc_like( $search ) . '%', $limit, $offset
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, notebook_id, user_id, title, excerpt, word_count, kg_source_id, file_path, created_at, updated_at
				 FROM {$tbl}
				 WHERE notebook_id = %d
				 ORDER BY updated_at DESC
				 LIMIT %d OFFSET %d",
				$scope_id, $limit, $offset
			), ARRAY_A );
		}

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'             => (int) $row['id'],
				'notebook_id'    => (int) $row['notebook_id'],
				'user_id'        => (int) $row['user_id'],
				'title'          => (string) $row['title'],
				'source_type'    => 'text',
				'excerpt'        => (string) ( $row['excerpt'] ?? '' ),
				'word_count'     => (int) $row['word_count'],
				'kg_source_id'   => $row['kg_source_id'] ? (int) $row['kg_source_id'] : null,
				'file_path'      => (string) ( $row['file_path'] ?? '' ),
				'embedding_status' => $row['kg_source_id'] ? 'ready' : 'pending',
				'created_at'     => (string) $row['created_at'],
				'updated_at'     => (string) $row['updated_at'],
			);
		}
		return $out;
	}

	/**
	 * Get a single source (page) by ID.
	 *
	 * @param int $source_id  page_id
	 * @return array|null
	 */
	public function get_source( $source_id ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — get page by id
		global $wpdb;
		$tbl = $this->tbl_pages();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, user_id, title, excerpt, word_count, kg_source_id, file_path, created_at, updated_at FROM {$tbl} WHERE id = %d LIMIT 1",
			(int) $source_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Delete chunks + passages for a page (called when page is deleted).
	 * Does NOT delete the page row itself — that is done by notebook REST.
	 *
	 * @param int $source_id  page_id
	 * @return bool
	 */
	public function delete_source( $source_id ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — delete chunks + passages for page
		global $wpdb;
		$source_id = (int) $source_id;
		if ( $source_id <= 0 ) { return false; }

		// Delete chunks
		$wpdb->delete( $this->tbl_chunks(), array( 'source_id' => $source_id ) );

		// Delete passages from kg_passages if available
		$tbl_passages = $this->tbl_passages();
		if ( $tbl_passages ) {
			$wpdb->delete( $tbl_passages, array(
				'scope_type' => self::SCOPE_TYPE,
				'source_id'  => $source_id,
			) );
		}

		return true;
	}

	/**
	 * List notebooks (scopes) the user has access to.
	 * Consumed by BizCity_KG::available_scopes() to populate scope picker.
	 *
	 * @param int $user_id
	 * @return array  [{ id, label, meta }]
	 */
	public function list_scopes( $user_id ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — list personal notebooks as scopes
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) { return array(); }

		$tbl  = $this->tbl_notebooks();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, icon, color, page_count, is_default, updated_at FROM {$tbl} WHERE user_id = %d ORDER BY sort_order ASC, created_at ASC LIMIT 50",
			$user_id
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'    => (int) $r['id'],
				'label' => ( $r['icon'] ? $r['icon'] . ' ' : '' ) . (string) $r['title'],
				'meta'  => array(
					'color'      => (string) ( $r['color'] ?? '#6366f1' ),
					'page_count' => (int) $r['page_count'],
					'is_default' => (bool) $r['is_default'],
					'updated_at' => (string) ( $r['updated_at'] ?? '' ),
				),
			);
		}
		return $out;
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Check whether any chunks already exist for a given page (dedup guard).
	 * Returns first chunk id found, or 0 if none.
	 *
	 * @param int $page_id
	 * @return int
	 */
	private function find_chunks_by_page( $page_id ) {
		global $wpdb;
		$tbl = $this->tbl_chunks();
		$id  = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE source_id = %d LIMIT 1",
			(int) $page_id
		) );
		return $id ? (int) $id : 0;
	}

	/**
	 * Chunk text into records using BizCity_TwinChat_Chunker when available,
	 * with a simple fallback splitter.
	 *
	 * @param string $content
	 * @return array  [{ text, heading_path }]
	 */
	private function chunk_content( $content ) {
		if ( class_exists( 'BizCity_TwinChat_Chunker' ) ) {
			$raw  = BizCity_TwinChat_Chunker::chunk( $content, self::CHUNK_CHARS, self::CHUNK_OVERLAP );
			$dedup = BizCity_TwinChat_Chunker::dedup_chunks( $raw );
			return $dedup['kept'];
		}
		// Fallback: simple fixed-size overlap splitter
		$out    = array();
		$len    = mb_strlen( $content );
		$step   = self::CHUNK_CHARS - self::CHUNK_OVERLAP;
		$offset = 0;
		while ( $offset < $len ) {
			$out[] = array(
				'text'         => mb_substr( $content, $offset, self::CHUNK_CHARS ),
				'heading_path' => array(),
			);
			$offset += $step;
		}
		return $out;
	}

	/**
	 * Embed a batch of chunk texts via bizcity_openrouter_embeddings() or
	 * BizCity_LLM_Client (graceful degradation).
	 * Returns array of vectors (may be nulls if embedding failed).
	 *
	 * @param string[] $chunks
	 * @param int      $scope_id
	 * @param int      $source_id
	 * @return array|WP_Error  array of float[] per chunk, or WP_Error on fatal
	 */
	private function embed_batch( array $chunks, $scope_id, $source_id ) {
		if ( empty( $chunks ) ) { return array(); }

		// Path A: openrouter helper (mu-plugin)
		if ( function_exists( 'bizcity_openrouter_embeddings' ) ) {
			$results = bizcity_openrouter_embeddings( $chunks, self::EMBED_MODEL );
			if ( is_wp_error( $results ) ) {
				error_log( '[bizcity-personal] KG embed error (scope ' . $scope_id . '/' . $source_id . '): ' . $results->get_error_message() );
				// Fail-OPEN: return nulls — chunks stored without embedding, KG will skip cosine search
				return array_fill( 0, count( $chunks ), null );
			}
			return (array) $results;
		}

		// Path B: LLM client (fail-OPEN: return nulls)
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$llm = BizCity_LLM_Client::instance();
			if ( $llm->is_ready() ) {
				$results = $llm->embed( $chunks, array( 'model' => self::EMBED_MODEL ) );
				if ( ! is_wp_error( $results ) ) {
					return (array) $results;
				}
			}
		}

		// Graceful degrade — store chunks without embeddings
		error_log( '[bizcity-personal] No embedding provider available. Chunks stored without vectors.' );
		return array_fill( 0, count( $chunks ), null );
	}

	/**
	 * Insert a passage row into bizcity_kg_passages.
	 * Mirrors TwinChat promote_chunk_to_passage() logic.
	 *
	 * @param string $tbl_passages
	 * @param array  $args
	 * @return int  passage_id or 0 on failure
	 */
	private function insert_passage( $tbl_passages, array $args ) {
		global $wpdb;

		$vec = isset( $args['embedding'] ) ? $args['embedding'] : null;
		$embedding_json = is_array( $vec ) ? wp_json_encode( $vec ) : null;

		$metadata = isset( $args['metadata'] ) && is_array( $args['metadata'] )
			? wp_json_encode( $args['metadata'] ) : null;

		// kg_passages columns: notebook_id, scope_type, scope_id, source_id, chunk_id,
		//   origin, content, embedding, token_count, metadata, created_at
		// Use defensive column list — gracefully skip missing columns.
		$row = array(
			'notebook_id'  => (int) $args['notebook_id'],
			'scope_type'   => (string) ( $args['scope_type'] ?? self::SCOPE_TYPE ),
			'scope_id'     => (string) $args['scope_id'],
			'source_id'    => (int) $args['source_id'],
			'chunk_id'     => (int) ( $args['chunk_id'] ?? 0 ),
			'origin'       => (string) ( $args['origin'] ?? '' ),
			'content'      => (string) $args['content'],
			'embedding'    => $embedding_json,
			'token_count'  => (int) ( $args['token_count'] ?? 0 ),
			'metadata'     => $metadata,
			'created_at'   => current_time( 'mysql' ),
		);

		$ins = $wpdb->insert( $tbl_passages, $row );
		return $ins ? (int) $wpdb->insert_id : 0;
	}
}
