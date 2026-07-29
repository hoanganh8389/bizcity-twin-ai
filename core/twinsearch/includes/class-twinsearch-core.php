<?php
/**
 * Core TwinSearch — shared lexical document search.
 *
 * Supported scopes:
 * - notebook: one notebook plus its virtually attached Guru knowledge.
 * - user: all notebooks owned by a user.
 * - blog: all notebooks in the current WordPress blog (admin/internal callers).
 * - character: notebooks bound to a Guru plus that Guru's namespaced passages.
 *
 * ## Cache Contract (R-CACHE)
 *
 * Group: chat
 *
 * | Key pattern                  | Covers                            | TTL       | Invalidations |
 * |------------------------------|-----------------------------------|-----------|---------------|
 * | twinsearch_docs_{args_hash}  | document search result page       | TTL_SHORT | ingest, notebook/guru binding mutations |
 *
 * PHP 7.4 compatible.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinSearch
 * @since      2026-07-14
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinSearch_Core' ) ) {
	return;
}

class BizCity_TwinSearch_Core {

	const CACHE_GROUP = 'chat';
	const CACHE_TTL   = 120;

	private static $instance = null;
	private static $hooks_bound = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::bind_invalidation_hooks();
		}
		return self::$instance;
	}

	private static function bind_invalidation_hooks() {
		if ( self::$hooks_bound ) {
			return;
		}
		self::$hooks_bound = true;

		// [2026-07-14 Johnny Chu] PHASE-0.43 — invalidate shared search results after KG/source mutations.
		add_action( 'bizcity_twinchat_after_ingest', array( __CLASS__, 'flush_cache' ), 10, 4 );
		add_action( 'bizcity_kg_notebook_deleted', array( __CLASS__, 'flush_cache' ), 10, 1 );
		add_action( 'bizcity_kg_notebook_stats_dirty', array( __CLASS__, 'flush_cache' ), 10, 1 );
		add_action( 'bizcity_kg_guru_attached', array( __CLASS__, 'flush_cache' ), 10, 2 );
		add_action( 'bizcity_kg_guru_detached', array( __CLASS__, 'flush_cache' ), 10, 2 );
	}

	public static function flush_cache() {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Search local KG documents by keyword.
	 *
	 * @param array $args {
	 *   @type string $query
	 *   @type string $scope          notebook|user|blog|character (all aliases user)
	 *   @type int    $notebook_id
	 *   @type int    $user_id
	 *   @type int    $character_id
	 *   @type string $character_uuid
	 *   @type int    $page
	 *   @type int    $per_page
	 *   @type bool   $include_content
	 * }
	 * @return array
	 */
	public function search_documents( array $args ) {
		global $wpdb;

		// [2026-07-14 Johnny Chu] PHASE-0.43 — normalize the cross-surface TwinSearch contract.
		$query           = trim( (string) ( $args['query'] ?? '' ) );
		$scope           = sanitize_key( (string) ( $args['scope'] ?? 'notebook' ) );
		$notebook_id     = (int) ( $args['notebook_id'] ?? 0 );
		$user_id         = (int) ( $args['user_id'] ?? 0 );
		$character_id    = (int) ( $args['character_id'] ?? 0 );
		$character_uuid  = strtolower( trim( (string) ( $args['character_uuid'] ?? '' ) ) );
		$page            = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page        = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$include_content = ! empty( $args['include_content'] );

		// Backward compatibility: TwinChat's historical `all` means current user's notebooks.
		if ( 'all' === $scope ) {
			$scope = 'user';
		}
		if ( ! in_array( $scope, array( 'notebook', 'user', 'blog', 'character' ), true ) ) {
			$scope = 'notebook';
		}

		$tokens = $this->tokenize_query( $query );
		if ( '' === $query || empty( $tokens ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $this->empty_result( $query, $scope, $page, $per_page );
		}

		$scope_data = $this->resolve_scope( array(
			'scope'          => $scope,
			'notebook_id'    => $notebook_id,
			'user_id'        => $user_id,
			'character_id'   => $character_id,
			'character_uuid' => $character_uuid,
		) );
		if ( empty( $scope_data['notebook_ids'] ) && empty( $scope_data['guru_uuids'] ) ) {
			return $this->empty_result( $query, $scope, $page, $per_page );
		}

		$cache_key = 'twinsearch_docs_' . md5( wp_json_encode( array(
			'blog_id'         => (int) get_current_blog_id(),
			// [2026-07-28 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — bust cached results after file-backed passage matching.
			'contract'        => 'w0.8.2_filestore_body_match',
			'query'           => $query,
			'scope'           => $scope,
			'scope_data'      => $scope_data,
			'page'            => $page,
			'per_page'        => $per_page,
			'include_content' => $include_content,
		) ) );
		$cached = $this->cache_get( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$db           = BizCity_KG_Database::instance();
		$tbl_passages = $db->tbl_passages();
		$tbl_sources  = $db->tbl_sources();
		$tbl_notebooks = $db->tbl_notebooks();

		$scope_sql    = $this->build_scope_sql( $scope_data, 'p.' );
		$scope_where  = $scope_sql['sql'];
		$scope_params = $scope_sql['params'];

		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT W0.8.1 — search source metadata and Vietnamese no-accent variants, not only p.content.
		$search_tokens = $this->expand_search_tokens( $tokens );
		$token_clauses = array();
		$token_params  = array();
		foreach ( $search_tokens as $token ) {
			$like = '%' . $wpdb->esc_like( $token ) . '%';
			$token_clauses[] = '(LOWER(p.content) LIKE %s OR LOWER(s.title) LIKE %s OR LOWER(s.origin_kind) LIKE %s OR LOWER(s.origin_url) LIKE %s OR LOWER(s.content_text) LIKE %s OR LOWER(n.name) LIKE %s)';
			$token_params[]  = $like;
			$token_params[]  = $like;
			$token_params[]  = $like;
			$token_params[]  = $like;
			$token_params[]  = $like;
			$token_params[]  = $like;
		}
		$token_where = implode( ' OR ', $token_clauses );
		$doc_key_sql = "IF(p.source_id > 0, CONCAT('s:', p.source_id), CONCAT('p:', p.id))";

		// [2026-07-28 Johnny Chu] PHASE-0.45-KG-FILE-GRAPH — storage_ver=2 rows
		// have empty inline content after migration, so SQL must retain them as
		// candidates until the canonical filestore body is hydrated below.
		$docs_sql = "SELECT
				{$doc_key_sql} AS doc_key,
				MAX(p.notebook_id) AS notebook_id,
				MAX(p.character_uuid) AS character_uuid,
				MAX(p.source_id) AS source_id,
				COUNT(*) AS match_count,
				MAX(p.updated_at) AS last_hit_at,
				MIN(p.id) AS first_passage_id
			 FROM {$tbl_passages} p
			 LEFT JOIN {$tbl_sources} s ON s.id = p.source_id
			 LEFT JOIN {$tbl_notebooks} n ON n.id = p.notebook_id
			 WHERE ({$scope_where})
			   AND (({$token_where}) OR p.storage_ver = 2)
			 GROUP BY {$doc_key_sql}, p.notebook_id, p.character_uuid
			 ORDER BY match_count DESC, last_hit_at DESC";
		$docs_params = array_merge( $scope_params, $token_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$doc_rows = $wpdb->get_results( $wpdb->prepare( $docs_sql, $docs_params ), ARRAY_A );
		if ( ! is_array( $doc_rows ) ) {
			$doc_rows = array();
		}

		$source_ids = array_values( array_unique( array_filter( array_map( static function ( $row ) {
			return (int) ( $row['source_id'] ?? 0 );
		}, $doc_rows ) ) ) );
		$source_map = array();
		if ( ! empty( $source_ids ) ) {
			$source_ph = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$source_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, origin_kind, origin_url, content_text FROM {$tbl_sources} WHERE id IN ({$source_ph})",
				$source_ids
			), ARRAY_A );
			foreach ( (array) $source_rows as $source_row ) {
				$source_map[ (int) $source_row['id'] ] = $source_row;
			}
		}

		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT W0.8 — include notebook titles in global search results for source attribution UX.
		$notebook_ids_for_rows = array_values( array_unique( array_filter( array_map( static function ( $row ) {
			return (int) ( $row['notebook_id'] ?? 0 );
		}, $doc_rows ) ) ) );
		$notebook_map = array();
		if ( ! empty( $notebook_ids_for_rows ) ) {
			$notebook_ph  = implode( ',', array_fill( 0, count( $notebook_ids_for_rows ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$notebook_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, name FROM {$tbl_notebooks} WHERE id IN ({$notebook_ph})",
				$notebook_ids_for_rows
			), ARRAY_A );
			foreach ( (array) $notebook_rows as $notebook_row ) {
				$notebook_map[ (int) $notebook_row['id'] ] = (string) ( $notebook_row['name'] ?? '' );
			}
		}

		$first_ids = array_values( array_unique( array_filter( array_map( static function ( $row ) {
			return (int) ( $row['first_passage_id'] ?? 0 );
		}, $doc_rows ) ) ) );
		$passage_map = array();
		if ( ! empty( $first_ids ) ) {
			$first_ph = implode( ',', array_fill( 0, count( $first_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$first_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content, metadata, storage_ver, file_shard, file_offset, file_length
				   FROM {$tbl_passages} WHERE id IN ({$first_ph})",
				$first_ids
			), ARRAY_A );
			if ( $first_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_passages( $first_rows );
			}
			foreach ( (array) $first_rows as $first_row ) {
				$passage_map[ (int) $first_row['id'] ] = $first_row;
			}
		}

		$fallback_notebook_id = ! empty( $scope_data['notebook_ids'] ) ? (int) reset( $scope_data['notebook_ids'] ) : 0;
		$all_results = array();
		foreach ( $doc_rows as $row ) {
			$source_id        = (int) ( $row['source_id'] ?? 0 );
			$first_passage_id = (int) ( $row['first_passage_id'] ?? 0 );
			$doc_key          = (string) ( $row['doc_key'] ?? '' );
			$matching_rows    = $this->find_matching_passages( $doc_key, (int) ( $row['notebook_id'] ?? 0 ), (string) ( $row['character_uuid'] ?? '' ), $tokens );
			if ( empty( $matching_rows ) ) {
				continue;
			}
			$first_match       = $matching_rows[0];
			$first_passage_id  = (int) ( $first_match['id'] ?? $first_passage_id );
			$source            = $source_map[ $source_id ] ?? array();
			$source_content    = (string) ( $source['content_text'] ?? '' );
			$passage_content   = (string) ( $first_match['content'] ?? ( $passage_map[ $first_passage_id ]['content'] ?? '' ) );
			$base_content      = '' !== $source_content ? $source_content : $passage_content;
			$result_notebook   = (int) ( $row['notebook_id'] ?? 0 );
			if ( $result_notebook <= 0 ) {
				$result_notebook = $fallback_notebook_id;
			}
			$notebook_title = (string) ( $notebook_map[ $result_notebook ] ?? '' );
			if ( '' === $notebook_title && $result_notebook > 0 ) {
				$notebook_title = 'Notebook #' . $result_notebook;
			}

			$title = (string) ( $source['title'] ?? '' );
			if ( '' === $title ) {
				$title = $source_id > 0 ? 'Source #' . $source_id : 'Passage #' . $first_passage_id;
			}
			$display_source_id = $source_id > 0 ? $source_id : ( 1000000000 + $first_passage_id );
			$highlight_quote   = $this->build_snippet( $base_content, $tokens, 280 );

			$item = array(
				'doc_key'          => $doc_key,
				'notebook_id'      => $result_notebook,
				'notebook_title'   => $notebook_title,
				'character_uuid'   => (string) ( $row['character_uuid'] ?? '' ),
				'source_id'        => $display_source_id,
				'source_title'     => $title,
				'first_passage_id' => $first_passage_id,
				'citation'         => $result_notebook > 0 && $first_passage_id > 0 ? '[nb:' . $result_notebook . '/p' . $first_passage_id . ']' : '',
				'title'            => $title,
				'origin_kind'      => (string) ( $source['origin_kind'] ?? '' ),
				'origin_url'       => (string) ( $source['origin_url'] ?? '' ),
				'match_count'      => count( $matching_rows ),
				'last_hit_at'      => (string) ( $row['last_hit_at'] ?? '' ),
				'document_chars'   => '' !== $base_content ? mb_strlen( $base_content, 'UTF-8' ) : 0,
				'snippet'          => $highlight_quote,
				'highlight_quote'  => $highlight_quote,
			);
			if ( $include_content ) {
				$item['document_text'] = $base_content;
			}
			$all_results[] = $item;
		}
		$total  = count( $all_results );
		$offset = ( $page - 1 ) * $per_page;
		$results = array_slice( $all_results, $offset, $per_page );

		$response = array(
			'query'          => $query,
			'scope'          => $scope,
			'tokens'         => $tokens,
			'page'           => $page,
			'per_page'       => $per_page,
			'total'          => $total,
			'total_pages'    => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'notebook_ids'   => $scope_data['notebook_ids'],
			'character_uuid' => $scope_data['character_uuid'],
			'results'        => $results,
		);
		$this->cache_set( $cache_key, $response );
		return $response;
	}

	/**
	 * Read all passages for one document, hydrate file-backed bodies, then apply
	 * lexical matching. Source metadata can make the document query match every
	 * row, so passage statistics must come from the hydrated bodies.
	 *
	 * @param string   $doc_key        Source or standalone passage key.
	 * @param int      $notebook_id    Notebook scope.
	 * @param string   $character_uuid Character scope.
	 * @param string[] $tokens         Query tokens.
	 * @return array
	 */
	private function find_matching_passages( $doc_key, $notebook_id, $character_uuid, array $tokens ) {
		global $wpdb;

		$source_id       = 0;
		$solo_passage_id = 0;
		if ( 0 === strpos( (string) $doc_key, 's:' ) ) {
			$source_id = (int) substr( (string) $doc_key, 2 );
		} elseif ( 0 === strpos( (string) $doc_key, 'p:' ) ) {
			$solo_passage_id = (int) substr( (string) $doc_key, 2 );
		}
		if ( ( $source_id <= 0 && $solo_passage_id <= 0 ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return array();
		}

		$table  = BizCity_KG_Database::instance()->tbl_passages();
		$where  = array();
		$params = array();
		if ( $source_id > 0 ) {
			$where[]  = 'p.source_id = %d';
			$params[] = $source_id;
		} else {
			$where[]  = 'p.id = %d';
			$params[] = $solo_passage_id;
		}
		if ( (int) $notebook_id > 0 ) {
			$where[]  = 'p.notebook_id = %d';
			$params[] = (int) $notebook_id;
		}
		$character_uuid = strtolower( trim( (string) $character_uuid ) );
		if ( '' !== $character_uuid ) {
			$where[]  = 'p.character_uuid = %s';
			$params[] = $character_uuid;
		} else {
			$where[] = "( p.character_uuid IS NULL OR p.character_uuid = '' )";
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.notebook_id, p.character_uuid, p.content, p.metadata,
					p.storage_ver, p.file_shard, p.file_offset, p.file_length, p.updated_at
			   FROM {$table} p
			  WHERE {$where_sql}
			  ORDER BY p.id ASC",
			$params
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		if ( ! empty( $rows ) && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		$matching_rows = array();
		$search_tokens = $this->expand_search_tokens( $tokens );
		foreach ( $rows as $row ) {
			$content_lower = mb_strtolower( (string) ( $row['content'] ?? '' ), 'UTF-8' );
			foreach ( $search_tokens as $token ) {
				if ( false !== mb_strpos( $content_lower, (string) $token, 0, 'UTF-8' ) ) {
					$matching_rows[] = $row;
					break;
				}
			}
		}
		return $matching_rows;
	}

	/**
	 * Drill-down for a single search_documents() doc_key: list every matching
	 * passage excerpt (not just the one representative snippet) so the FE can
	 * render "342 matches" as 342 individual highlighted excerpts.
	 *
	 * [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — new method, no behavior change to search_documents().
	 *
	 * @param array $args { query, doc_key, notebook_id, character_uuid?, page?, per_page? }
	 * @return array { query, doc_key, page, per_page, total, total_pages, matches:array }
	 */
	public function search_document_matches( array $args ) {
		global $wpdb;

		$query          = trim( (string) ( $args['query'] ?? '' ) );
		$doc_key        = trim( (string) ( $args['doc_key'] ?? '' ) );
		$notebook_id    = (int) ( $args['notebook_id'] ?? 0 );
		$character_uuid = strtolower( trim( (string) ( $args['character_uuid'] ?? '' ) ) );
		$page           = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page       = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );

		$tokens = $this->tokenize_query( $query );
		if ( '' === $query || empty( $tokens ) || '' === $doc_key || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $this->empty_matches_result( $query, $doc_key, $page, $per_page );
		}

		// Parse doc_key ("s:{source_id}" | "p:{passage_id}") — mirrors the
		// GROUP BY expression `doc_key_sql` used to build search_documents() rows.
		$source_id       = 0;
		$solo_passage_id = 0;
		if ( 0 === strpos( $doc_key, 's:' ) ) {
			$source_id = (int) substr( $doc_key, 2 );
		} elseif ( 0 === strpos( $doc_key, 'p:' ) ) {
			$solo_passage_id = (int) substr( $doc_key, 2 );
		}
		if ( $source_id <= 0 && $solo_passage_id <= 0 ) {
			return $this->empty_matches_result( $query, $doc_key, $page, $per_page );
		}

		$db           = BizCity_KG_Database::instance();
		$tbl_passages = $db->tbl_passages();

		// Filter by notebook_id + character_uuid too — the same combo used by
		// search_documents()'s GROUP BY, since Guru-cloned passages can share a
		// source_id across multiple (notebook_id, character_uuid) pairs.
		$where  = array();
		$params = array();
		if ( $source_id > 0 ) {
			$where[]  = 'p.source_id = %d';
			$params[] = $source_id;
		} else {
			$where[]  = 'p.id = %d';
			$params[] = $solo_passage_id;
		}
		if ( $notebook_id > 0 ) {
			$where[]  = 'p.notebook_id = %d';
			$params[] = $notebook_id;
		}
		if ( '' !== $character_uuid ) {
			$where[] = 'p.character_uuid = %s';
			$params[] = $character_uuid;
		} else {
			$where[] = "( p.character_uuid IS NULL OR p.character_uuid = '' )";
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.notebook_id, p.character_uuid, p.content, p.metadata,
					p.storage_ver, p.file_shard, p.file_offset, p.file_length, p.updated_at
			   FROM {$tbl_passages} p
			  WHERE {$where_sql}
			  ORDER BY p.id ASC",
			$params
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — filter after hydration because storage_ver=2 scrubs SQL content.
		$search_tokens = $this->expand_search_tokens( $tokens );
		$matching_rows = array();
		foreach ( $rows as $row ) {
			$content_lower = mb_strtolower( (string) ( $row['content'] ?? '' ), 'UTF-8' );
			foreach ( $search_tokens as $token ) {
				if ( false !== mb_strpos( $content_lower, (string) $token, 0, 'UTF-8' ) ) {
					$matching_rows[] = $row;
					break;
				}
			}
		}
		$total = count( $matching_rows );
		if ( $total <= 0 ) {
			return $this->empty_matches_result( $query, $doc_key, $page, $per_page );
		}

		$offset = ( $page - 1 ) * $per_page;
		$rows   = array_slice( $matching_rows, $offset, $per_page );
		$matches = array();
		foreach ( $rows as $row ) {
			$pid     = (int) $row['id'];
			$nb      = (int) ( $row['notebook_id'] ?? $notebook_id );
			$content = (string) ( $row['content'] ?? '' );
			$matches[] = array(
				'passage_id'      => $pid,
				'notebook_id'     => $nb,
				'citation'        => ( $nb > 0 && $pid > 0 ) ? ( '[nb:' . $nb . '/p' . $pid . ']' ) : '',
				'highlight_quote' => $this->build_snippet( $content, $tokens, 280 ),
				'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
			);
		}

		return array(
			'query'       => $query,
			'doc_key'     => $doc_key,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $total,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'matches'     => $matches,
		);
	}

	/**
	 * Resolve a search scope without executing the document query.
	 *
	 * @param array $args Scope arguments.
	 * @return array { notebook_ids:int[], guru_uuids:string[], character_uuid:string }
	 */
	public function resolve_scope( array $args ) {
		global $wpdb;

		// [2026-07-14 Johnny Chu] PHASE-0.43 — canonical notebook/blog/character scope resolver.
		$scope          = sanitize_key( (string) ( $args['scope'] ?? 'notebook' ) );
		$notebook_id    = (int) ( $args['notebook_id'] ?? 0 );
		$user_id        = (int) ( $args['user_id'] ?? 0 );
		$character_id   = (int) ( $args['character_id'] ?? 0 );
		$character_uuid = strtolower( trim( (string) ( $args['character_uuid'] ?? '' ) ) );
		$db             = BizCity_KG_Database::instance();
		$notebooks      = $db->tbl_notebooks();
		$attachments    = $db->tbl_notebook_character_attachments();

		if ( 'all' === $scope ) {
			$scope = 'user';
		}

		$notebook_ids = array();
		if ( 'notebook' === $scope ) {
			if ( $notebook_id > 0 ) {
				$notebook_ids[] = $notebook_id;
			}
		} elseif ( 'user' === $scope ) {
			$notebook_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$notebooks} WHERE owner_id = %d ORDER BY id DESC LIMIT 2000",
				$user_id
			) );
		} elseif ( 'blog' === $scope ) {
			$notebook_ids = $wpdb->get_col( "SELECT id FROM {$notebooks} ORDER BY id DESC LIMIT 5000" );
		} elseif ( 'character' === $scope ) {
			if ( '' === $character_uuid && $character_id > 0 ) {
				$characters = $wpdb->prefix . 'bizcity_characters';
				$character_uuid = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT guru_uuid FROM {$characters} WHERE id = %d LIMIT 1",
					$character_id
				) );
			}

			$direct_ids = array();
			if ( $character_id > 0 ) {
				$direct_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT id FROM {$notebooks} WHERE character_id = %d ORDER BY id DESC LIMIT 2000",
					$character_id
				) );
			}
			$attached_ids = array();
			if ( '' !== $character_uuid ) {
				$attached_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT notebook_id FROM {$attachments} WHERE guru_uuid = %s ORDER BY notebook_id DESC LIMIT 2000",
					$character_uuid
				) );
			}
			$notebook_ids = array_merge( (array) $direct_ids, (array) $attached_ids );
		}

		$notebook_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $notebook_ids ) ) ) );
		$guru_uuids   = array();
		if ( ! empty( $notebook_ids ) ) {
			$nb_ph = implode( ',', array_fill( 0, count( $notebook_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$guru_uuids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT guru_uuid FROM {$attachments} WHERE notebook_id IN ({$nb_ph})",
				$notebook_ids
			) );
		}
		if ( 'character' === $scope && '' !== $character_uuid ) {
			$guru_uuids = array( $character_uuid );
		}
		$guru_uuids = array_values( array_unique( array_filter( array_map( 'strval', (array) $guru_uuids ) ) ) );

		return array(
			'notebook_ids'   => $notebook_ids,
			'guru_uuids'     => $guru_uuids,
			'character_uuid' => $character_uuid,
		);
	}

	/**
	 * Resolve a source DTO for a given passage, with scope authorization.
	 *
	 * @param int    $passage_id    Passage ID.
	 * @param string $scope         character|user.
	 * @param int    $character_id  Required for character scope.
	 * @param int    $user_id       Required for user scope.
	 * @param array  $evidence_ctx  Optional FE context (claim/snippet/tokens).
	 * @return array|WP_Error
	 */
	public function resolve_passage_for_source( $passage_id, $scope, $character_id = 0, $user_id = 0, array $evidence_ctx = array() ) {
		// [2026-07-14 Johnny Chu] PHASE-TWINWEB-SEARCH W4 — canonical passage->source resolver for citation dialogs.
		global $wpdb;

		$passage_id   = (int) $passage_id;
		$scope        = sanitize_key( (string) $scope );
		$character_id = (int) $character_id;
		$user_id      = (int) $user_id;
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — normalize optional FE evidence context.
		$claim_text   = isset( $evidence_ctx['claim_text'] ) ? sanitize_text_field( (string) $evidence_ctx['claim_text'] ) : '';
		$snippet      = isset( $evidence_ctx['snippet'] ) ? sanitize_text_field( (string) $evidence_ctx['snippet'] ) : '';
		$tokens       = isset( $evidence_ctx['tokens'] ) && is_array( $evidence_ctx['tokens'] )
			? array_values( array_filter( array_map( 'sanitize_text_field', (array) $evidence_ctx['tokens'] ) ) )
			: array();

		if ( $passage_id <= 0 ) {
			return new WP_Error( 'invalid_param', 'Passage ID khong hop le.', array( 'status' => 400 ) );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'unavailable', 'KG Database chua tai.', array( 'status' => 503 ) );
		}

		$db           = BizCity_KG_Database::instance();
		$tbl_passages = $db->tbl_passages();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, source_id, notebook_id, character_uuid, content, metadata,
					storage_ver, file_shard, file_offset, file_length
			   FROM {$tbl_passages}
			  WHERE id = %d
			  LIMIT 1",
			$passage_id
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return new WP_Error( 'not_found', 'Passage khong ton tai.', array( 'status' => 404 ) );
		}

		if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
			$rows = array( $row );
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
			if ( ! empty( $rows[0] ) && is_array( $rows[0] ) ) {
				$row = $rows[0];
			}
		}

		$scope_data = $this->resolve_scope( array(
			'scope'        => $scope,
			'character_id' => $character_id,
			'user_id'      => $user_id,
		) );

		$nb_ids        = array_map( 'intval', (array) ( $scope_data['notebook_ids'] ?? array() ) );
		$allowed_uuids = array_map( 'strtolower', (array) ( $scope_data['guru_uuids'] ?? array() ) );
		$row_nb        = (int) ( $row['notebook_id'] ?? 0 );
		$row_uuid      = strtolower( (string) ( $row['character_uuid'] ?? '' ) );

		$authorized = in_array( $row_nb, $nb_ids, true )
			|| ( $row_uuid !== '' && in_array( $row_uuid, $allowed_uuids, true ) );
		if ( ! $authorized ) {
			return new WP_Error( 'forbidden', 'Passage ngoai pham vi cho phep.', array( 'status' => 403 ) );
		}

		$source_id   = (int) ( $row['source_id'] ?? 0 );
		$source_meta = array();
		if ( $source_id > 0 ) {
			$tbl_sources = $db->tbl_sources();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$source_meta = (array) $wpdb->get_row( $wpdb->prepare(
				"SELECT id, title, origin_kind, origin_url, content_text
				   FROM {$tbl_sources}
				  WHERE id = %d
				  LIMIT 1",
				$source_id
			), ARRAY_A );
		}

		$content = (string) ( $row['content'] ?? '' );
		$content_text = (string) ( $source_meta['content_text'] ?? $content );
		$evidence = $this->build_source_evidence( $content_text, $claim_text, $snippet, $tokens );
		return array(
			'source_id'      => $source_id,
			'passage_id'     => (int) $row['id'],
			'notebook_id'    => $row_nb,
			'character_uuid' => $row_uuid,
			'title'          => (string) ( $source_meta['title'] ?? ( 'Passage #' . $passage_id ) ),
			'origin_kind'    => (string) ( $source_meta['origin_kind'] ?? '' ),
			'origin_url'     => (string) ( $source_meta['origin_url'] ?? '' ),
			'content_text'   => $content_text,
			'evidence'       => $evidence,
		);
	}

	/**
	 * Build evidence payload for citation source sheet.
	 *
	 * @param string $content_text Full source content.
	 * @param string $claim_text   Optional user-visible claim text.
	 * @param string $snippet      Optional snippet from FE context.
	 * @param array  $tokens       Optional lexical tokens from FE context.
	 * @return array
	 */
	private function build_source_evidence( $content_text, $claim_text, $snippet, array $tokens ) {
		// [2026-07-15 Johnny Chu] PHASE-TWINWEB-SEARCH-HARDENING — phase 3: sentence-first matching + overlap merge.
		$content      = $this->normalize_evidence_text( (string) $content_text );
		$claim        = $this->normalize_evidence_text( (string) $claim_text );
		$raw_snippet  = $this->normalize_evidence_text( (string) $snippet );
		$token_terms  = $this->normalize_evidence_tokens( $tokens );
		$token_terms  = array_values( array_unique( array_merge(
			$token_terms,
			$this->extract_evidence_tokens_from_text( $claim ),
			$this->extract_evidence_tokens_from_text( $raw_snippet )
		) ) );
		$matched      = '' !== $raw_snippet ? $raw_snippet : '';
		$ranges       = array();

		$snippet_range = $this->find_phrase_range( $content, $matched );
		if ( is_array( $snippet_range ) ) {
			$ranges[] = $snippet_range;
		}

		$claim_range = $this->find_phrase_range( $content, $claim );
		if ( is_array( $claim_range ) ) {
			$ranges[] = $claim_range;
		}

		if ( '' !== $content && ! empty( $token_terms ) ) {
			$sentence_ranges = $this->build_sentence_match_ranges( $content, $token_terms, 4 );
			if ( ! empty( $sentence_ranges ) ) {
				$ranges = array_merge( $ranges, $sentence_ranges );
			}
		}

		if ( count( $ranges ) < 3 && '' !== $content && ! empty( $token_terms ) ) {
			foreach ( $token_terms as $token ) {
				$token_range = $this->find_phrase_range( $content, (string) $token );
				if ( ! is_array( $token_range ) ) {
					continue;
				}
				$ranges[] = $token_range;
				if ( count( $ranges ) >= 5 ) {
					break;
				}
			}
		}

		$ranges = $this->merge_evidence_ranges( $ranges, 14, 6 );

		if ( '' === $matched && ! empty( $ranges ) ) {
			$top = $ranges[0];
			$matched = $this->build_excerpt_from_range( $content, (int) $top['start'], (int) $top['end'], 140 );
		}

		if ( '' === $matched ) {
			if ( '' !== $claim ) {
				$matched = $claim;
			} elseif ( '' !== $content ) {
				$content_len = mb_strlen( $content, 'UTF-8' );
				$matched = mb_substr( $content, 0, min( 280, $content_len ), 'UTF-8' );
				if ( $content_len > 280 ) {
					$matched .= ' ...';
				}
			}
		}

		return array(
			'claim'            => $claim,
			'matched_snippet'  => $matched,
			'highlight_ranges' => $ranges,
		);
	}

	/**
	 * Find exact phrase range inside content.
	 *
	 * @param string $content Haystack text.
	 * @param string $needle  Phrase to find.
	 * @return array|null
	 */
	private function find_phrase_range( $content, $needle ) {
		$haystack = (string) $content;
		$phrase   = $this->normalize_evidence_text( (string) $needle );
		if ( '' === $haystack || '' === $phrase ) {
			return null;
		}

		$pos = mb_stripos( $haystack, $phrase, 0, 'UTF-8' );
		if ( false === $pos ) {
			return null;
		}

		$len = mb_strlen( $phrase, 'UTF-8' );
		if ( $len <= 0 ) {
			return null;
		}

		return array(
			'start' => (int) $pos,
			'end'   => (int) ( $pos + $len ),
		);
	}

	/**
	 * Build sentence-level evidence ranges scored by token overlap.
	 *
	 * @param string $content Full content text.
	 * @param array  $tokens  Candidate tokens.
	 * @param int    $limit   Max ranges returned.
	 * @return array
	 */
	private function build_sentence_match_ranges( $content, array $tokens, $limit ) {
		$text = (string) $content;
		$terms = $this->normalize_evidence_tokens( $tokens );
		$max = max( 1, (int) $limit );
		if ( '' === $text || empty( $terms ) ) {
			return array();
		}

		$sentences = $this->split_sentences_with_offsets( $text );
		if ( empty( $sentences ) ) {
			return array();
		}

		$candidates = array();
		foreach ( $sentences as $sentence ) {
			$sentence_text  = (string) ( $sentence['text'] ?? '' );
			$sentence_lower = mb_strtolower( $sentence_text, 'UTF-8' );
			$hit_count      = 0;
			$hit_weight     = 0;
			foreach ( $terms as $term ) {
				if ( false !== mb_strpos( $sentence_lower, (string) $term, 0, 'UTF-8' ) ) {
					++$hit_count;
					$hit_weight += mb_strlen( (string) $term, 'UTF-8' );
				}
			}

			if ( $hit_count <= 0 ) {
				continue;
			}

			$score = ( $hit_count * 10 ) + min( 20, $hit_weight );
			$sentence_len = (int) ( $sentence['length'] ?? 0 );
			if ( $sentence_len > 0 && $sentence_len <= 240 ) {
				$score += 6;
			}

			$candidates[] = array(
				'start' => (int) $sentence['start'],
				'end'   => (int) $sentence['end'],
				'score' => $score,
			);
		}

		if ( empty( $candidates ) ) {
			return array();
		}

		usort( $candidates, static function ( $a, $b ) {
			$score_a = (int) ( $a['score'] ?? 0 );
			$score_b = (int) ( $b['score'] ?? 0 );
			if ( $score_a === $score_b ) {
				return (int) ( $a['start'] ?? 0 ) <=> (int) ( $b['start'] ?? 0 );
			}
			return $score_b <=> $score_a;
		} );

		$picked = array_slice( $candidates, 0, $max );
		usort( $picked, static function ( $a, $b ) {
			return (int) ( $a['start'] ?? 0 ) <=> (int) ( $b['start'] ?? 0 );
		} );

		$ranges = array();
		foreach ( $picked as $item ) {
			$ranges[] = array(
				'start' => (int) $item['start'],
				'end'   => (int) $item['end'],
			);
		}

		return $ranges;
	}

	/**
	 * Split text to sentence chunks with UTF-8 char offsets.
	 *
	 * @param string $text Input text.
	 * @return array
	 */
	private function split_sentences_with_offsets( $text ) {
		$source = (string) $text;
		if ( '' === $source ) {
			return array();
		}

		$matches = array();
		$ok = preg_match_all( '/[^.!?。！？\n]+[.!?。！？]?/u', $source, $matches, PREG_OFFSET_CAPTURE );
		if ( 1 !== $ok && false === $ok ) {
			return array();
		}

		$sentences = array();
		foreach ( (array) ( $matches[0] ?? array() ) as $entry ) {
			$chunk = (string) ( $entry[0] ?? '' );
			$byte_offset = (int) ( $entry[1] ?? 0 );
			if ( '' === $chunk ) {
				continue;
			}

			$ltrimmed = ltrim( $chunk );
			$trimmed  = rtrim( $ltrimmed );
			if ( '' === $trimmed ) {
				continue;
			}

			$leading_chars = mb_strlen( $chunk, 'UTF-8' ) - mb_strlen( $ltrimmed, 'UTF-8' );
			$start = $this->byte_offset_to_char_offset( $source, $byte_offset ) + $leading_chars;
			$length = mb_strlen( $trimmed, 'UTF-8' );
			$sentences[] = array(
				'text'   => $trimmed,
				'start'  => (int) $start,
				'end'    => (int) ( $start + $length ),
				'length' => (int) $length,
			);
		}

		return $sentences;
	}

	/**
	 * Convert byte offset to UTF-8 char offset.
	 *
	 * @param string $text        Full string.
	 * @param int    $byte_offset Byte offset.
	 * @return int
	 */
	private function byte_offset_to_char_offset( $text, $byte_offset ) {
		$offset = max( 0, (int) $byte_offset );
		if ( $offset <= 0 ) {
			return 0;
		}
		$prefix = substr( (string) $text, 0, $offset );
		return (int) mb_strlen( (string) $prefix, 'UTF-8' );
	}

	/**
	 * Merge overlapping/nearby ranges to reduce noisy highlight blocks.
	 *
	 * @param array $ranges     Raw ranges.
	 * @param int   $gap        Max char gap to merge.
	 * @param int   $max_ranges Max returned ranges.
	 * @return array
	 */
	private function merge_evidence_ranges( array $ranges, $gap, $max_ranges ) {
		$merge_gap = max( 0, (int) $gap );
		$limit = max( 1, (int) $max_ranges );
		$normalized = array();
		foreach ( $ranges as $range ) {
			$start = (int) ( $range['start'] ?? 0 );
			$end   = (int) ( $range['end'] ?? 0 );
			if ( $end <= $start ) {
				continue;
			}
			$normalized[] = array( 'start' => $start, 'end' => $end );
		}

		if ( empty( $normalized ) ) {
			return array();
		}

		usort( $normalized, static function ( $a, $b ) {
			$cmp = (int) ( $a['start'] ?? 0 ) <=> (int) ( $b['start'] ?? 0 );
			if ( 0 !== $cmp ) {
				return $cmp;
			}
			return (int) ( $a['end'] ?? 0 ) <=> (int) ( $b['end'] ?? 0 );
		} );

		$merged = array();
		foreach ( $normalized as $range ) {
			if ( empty( $merged ) ) {
				$merged[] = $range;
				continue;
			}
			$last_index = count( $merged ) - 1;
			$last = $merged[ $last_index ];
			if ( (int) $range['start'] <= ( (int) $last['end'] + $merge_gap ) ) {
				$merged[ $last_index ]['end'] = max( (int) $last['end'], (int) $range['end'] );
			} else {
				$merged[] = $range;
			}
		}

		return array_slice( $merged, 0, $limit );
	}

	/**
	 * Normalize free-text evidence input.
	 *
	 * @param string $value Raw text.
	 * @return string
	 */
	private function normalize_evidence_text( $value ) {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
		return $value;
	}

	/**
	 * Normalize token list for lexical matching.
	 *
	 * @param array $tokens Raw tokens.
	 * @return array
	 */
	private function normalize_evidence_tokens( array $tokens ) {
		$normalized = array();
		foreach ( $tokens as $token ) {
			$clean = mb_strtolower( $this->normalize_evidence_text( (string) $token ), 'UTF-8' );
			if ( '' === $clean || mb_strlen( $clean, 'UTF-8' ) < 3 ) {
				continue;
			}
			$normalized[ $clean ] = true;
		}
		return array_keys( $normalized );
	}

	/**
	 * Tokenize arbitrary text into evidence terms.
	 *
	 * @param string $text Input text.
	 * @return array
	 */
	private function extract_evidence_tokens_from_text( $text ) {
		$value = mb_strtolower( $this->normalize_evidence_text( (string) $text ), 'UTF-8' );
		if ( '' === $value ) {
			return array();
		}
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $value, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		return $this->normalize_evidence_tokens( $parts );
	}

	/**
	 * Build compact excerpt around a matched range.
	 *
	 * @param string $text   Full text.
	 * @param int    $start  Match start offset (UTF-8 chars).
	 * @param int    $end    Match end offset (UTF-8 chars).
	 * @param int    $radius Context radius in chars.
	 * @return string
	 */
	private function build_excerpt_from_range( $text, $start, $end, $radius ) {
		$text_len = mb_strlen( (string) $text, 'UTF-8' );
		$start    = max( 0, min( (int) $start, $text_len ) );
		$end      = max( $start, min( (int) $end, $text_len ) );
		$radius   = max( 0, (int) $radius );

		$left  = max( 0, $start - $radius );
		$right = min( $text_len, $end + $radius );
		$body  = mb_substr( (string) $text, $left, $right - $left, 'UTF-8' );
		$head  = $left > 0 ? '... ' : '';
		$tail  = $right < $text_len ? ' ...' : '';
		return trim( $head . $body . $tail );
	}

	private function build_scope_sql( array $scope_data, $prefix ) {
		$parts  = array();
		$params = array();
		$notebook_ids = (array) ( $scope_data['notebook_ids'] ?? array() );
		$guru_uuids   = (array) ( $scope_data['guru_uuids'] ?? array() );

		if ( ! empty( $notebook_ids ) ) {
			$parts[] = '(' . $prefix . 'notebook_id IN (' . implode( ',', array_fill( 0, count( $notebook_ids ), '%d' ) )
				. ' ) AND (' . $prefix . "character_uuid IS NULL OR {$prefix}character_uuid = ''))";
			$params = array_merge( $params, $notebook_ids );
		}
		if ( ! empty( $guru_uuids ) ) {
			$parts[] = $prefix . 'character_uuid IN (' . implode( ',', array_fill( 0, count( $guru_uuids ), '%s' ) ) . ')';
			$params = array_merge( $params, $guru_uuids );
		}
		if ( empty( $parts ) ) {
			return array( 'sql' => '1 = 0', 'params' => array() );
		}
		return array( 'sql' => implode( ' OR ', $parts ), 'params' => $params );
	}

	private function tokenize_query( $query ) {
		$query = mb_strtolower( (string) $query, 'UTF-8' );
		$raw   = preg_split( '/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = array();
		foreach ( is_array( $raw ) ? $raw : array() as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && mb_strlen( $part, 'UTF-8' ) >= 2 ) {
				$tokens[ $part ] = true;
			}
		}
		if ( empty( $tokens ) && '' !== $query ) {
			$tokens[ $query ] = true;
		}
		return array_slice( array_keys( $tokens ), 0, 10 );
	}

	private function expand_search_tokens( array $tokens ) {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT W0.8.1 — tolerate Vietnamese accented/unaccented keyword mismatch in LIKE search.
		$out = array();
		foreach ( $tokens as $token ) {
			$token = trim( mb_strtolower( (string) $token, 'UTF-8' ) );
			if ( '' === $token ) {
				continue;
			}
			$out[ $token ] = true;
			$plain = $this->strip_vietnamese_diacritics( $token );
			if ( '' !== $plain && $plain !== $token ) {
				$out[ $plain ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, 20 );
	}

	private function strip_vietnamese_diacritics( $text ) {
		$text = (string) $text;
		$map = array(
			'à' => 'a', 'á' => 'a', 'ạ' => 'a', 'ả' => 'a', 'ã' => 'a', 'â' => 'a', 'ầ' => 'a', 'ấ' => 'a', 'ậ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ă' => 'a', 'ằ' => 'a', 'ắ' => 'a', 'ặ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a',
			'è' => 'e', 'é' => 'e', 'ẹ' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ê' => 'e', 'ề' => 'e', 'ế' => 'e', 'ệ' => 'e', 'ể' => 'e', 'ễ' => 'e',
			'ì' => 'i', 'í' => 'i', 'ị' => 'i', 'ỉ' => 'i', 'ĩ' => 'i',
			'ò' => 'o', 'ó' => 'o', 'ọ' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ô' => 'o', 'ồ' => 'o', 'ố' => 'o', 'ộ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ơ' => 'o', 'ờ' => 'o', 'ớ' => 'o', 'ợ' => 'o', 'ở' => 'o', 'ỡ' => 'o',
			'ù' => 'u', 'ú' => 'u', 'ụ' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ư' => 'u', 'ừ' => 'u', 'ứ' => 'u', 'ự' => 'u', 'ử' => 'u', 'ữ' => 'u',
			'ỳ' => 'y', 'ý' => 'y', 'ỵ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y',
			'đ' => 'd',
		);
		return strtr( mb_strtolower( $text, 'UTF-8' ), $map );
	}

	private function build_snippet( $text, array $tokens, $length ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}
		$length = max( 80, (int) $length );
		$lower  = mb_strtolower( $text, 'UTF-8' );
		$pos    = null;
		foreach ( $tokens as $token ) {
			$found = mb_strpos( $lower, (string) $token, 0, 'UTF-8' );
			if ( false !== $found && ( null === $pos || $found < $pos ) ) {
				$pos = (int) $found;
			}
		}
		if ( null === $pos ) {
			$pos = 0;
		}
		$start   = max( 0, $pos - 80 );
		$snippet = mb_substr( $text, $start, $length, 'UTF-8' );
		if ( $start > 0 ) {
			$snippet = '... ' . $snippet;
		}
		if ( $start + $length < mb_strlen( $text, 'UTF-8' ) ) {
			$snippet .= ' ...';
		}
		return $snippet;
	}

	private function empty_result( $query, $scope, $page, $per_page ) {
		return array(
			'query'          => (string) $query,
			'scope'          => (string) $scope,
			'tokens'         => array(),
			'page'           => (int) $page,
			'per_page'       => (int) $per_page,
			'total'          => 0,
			'total_pages'    => 0,
			'notebook_ids'   => array(),
			'character_uuid' => '',
			'results'        => array(),
		);
	}

	/** Empty-shape helper for search_document_matches(). */
	private function empty_matches_result( $query, $doc_key, $page, $per_page ) {
		return array(
			'query'       => (string) $query,
			'doc_key'     => (string) $doc_key,
			'page'        => (int) $page,
			'per_page'    => (int) $per_page,
			'total'       => 0,
			'total_pages' => 0,
			'matches'     => array(),
		);
	}

	private function cache_get( $key ) {
		return class_exists( 'BizCity_Cache' ) ? BizCity_Cache::get( self::CACHE_GROUP, (string) $key ) : false;
	}

	private function cache_set( $key, $value ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, (string) $key, $value, self::CACHE_TTL );
		}
	}
}

// [2026-07-14 Johnny Chu] PHASE-0.43 — register shared local-document search cache contract.
if ( class_exists( 'BizCity_Cache_Registry' ) ) {
	BizCity_Cache_Registry::register( 'chat', 'core.twinsearch', array(
		'twinsearch_docs_{args_hash}' => array( 'ttl' => BizCity_TwinSearch_Core::CACHE_TTL, 'desc' => 'Local KG document search page' ),
	) );
}
