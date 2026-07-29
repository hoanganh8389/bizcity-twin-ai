<?php
/**
 * Bizcity Twin AI — TwinChat Keyword Search Engine
 *
 * Dedicated lexical search core for TwinChat document search.
 * Supports 2 scopes:
 *   - notebook: search documents inside one notebook_id
 *   - all:      search documents across all notebooks visible to current user
 *
 * ## Cache Contract (R-CACHE)
 *
 * Group : chat
 *
 * | Cache key                 | Covers                                     | TTL       | Invalidated by                         |
 * |---------------------------|--------------------------------------------|-----------|----------------------------------------|
 * | search_docs_{args_hash}   | search_documents(query, scope, paging)     | TTL_SHORT | flush_group('chat') + short TTL expiry |
 *
 * Notes:
 * - Read-only manager. Results are additionally refreshed by short TTL.
 * - Hooks flush cache on ingest + notebook delete events.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Search_Engine {

	const CACHE_GROUP = 'chat';
	const CACHE_TTL   = 120;

	private static $instance   = null;
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

		add_action( 'bizcity_twinchat_after_ingest', array( __CLASS__, 'flush_cache' ), 10, 4 );
		add_action( 'bizcity_kg_notebook_deleted', array( __CLASS__, 'flush_cache' ), 10, 1 );
		add_action( 'bizcity_kg_notebook_stats_dirty', array( __CLASS__, 'flush_cache' ), 10, 1 );
	}

	public static function flush_cache() {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	/**
	 * Search matching documents by keyword.
	 *
	 * @param array $args {
	 *   @type int    $user_id
	 *   @type string $scope          notebook|all
	 *   @type int    $notebook_id
	 *   @type string $query
	 *   @type int    $page
	 *   @type int    $per_page
	 *   @type bool   $include_content
	 * }
	 * @return array
	 */
	public function search_documents( array $args ) {
		global $wpdb;

		// [2026-07-14 Johnny Chu] PHASE-0.43 — delegate local-document retrieval to shared core/twinsearch.
		if ( class_exists( 'BizCity_TwinSearch_Core' ) ) {
			$core_args = $args;
			if ( isset( $core_args['scope'] ) && 'all' === $core_args['scope'] ) {
				$core_args['scope'] = 'user';
			}
			$result = BizCity_TwinSearch_Core::instance()->search_documents( $core_args );
			// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — current core already derives match_count from hydrated passages; normalize only for an older partial deploy.
			if ( method_exists( 'BizCity_TwinSearch_Core', 'search_document_matches' ) ) {
				return $result;
			}
			return $this->normalize_document_match_counts( $result, $args );
		}

		// [2026-07-14 Johnny Chu] PHASE-0.43 — normalize request args for dual-scope keyword search.
		$user_id         = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$scope           = isset( $args['scope'] ) ? sanitize_key( (string) $args['scope'] ) : 'notebook';
		$notebook_id     = isset( $args['notebook_id'] ) ? (int) $args['notebook_id'] : 0;
		$query           = trim( (string) ( $args['query'] ?? '' ) );
		$page            = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page        = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$include_content = ! empty( $args['include_content'] );

		if ( ! in_array( $scope, array( 'notebook', 'all' ), true ) ) {
			$scope = 'notebook';
		}
		if ( $query === '' ) {
			return $this->empty_result( $query, $scope, $page, $per_page );
		}

		$tokens = $this->tokenize_query( $query );
		if ( empty( $tokens ) ) {
			return $this->empty_result( $query, $scope, $page, $per_page );
		}

		$notebook_ids = $this->resolve_notebook_ids( $user_id, $scope, $notebook_id );
		if ( empty( $notebook_ids ) ) {
			return $this->empty_result( $query, $scope, $page, $per_page );
		}

		$cache_key = 'search_docs_' . md5( wp_json_encode( array(
			'user_id'         => $user_id,
			'scope'           => $scope,
			'notebook_id'     => $notebook_id,
			'query'           => $query,
			'page'            => $page,
			'per_page'        => $per_page,
			'include_content' => $include_content,
		) ) );
		$cached = $this->cache_get( $cache_key );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$db            = BizCity_KG_Database::instance();
		$tbl_passages  = $db->tbl_passages();
		$tbl_sources   = $db->tbl_sources();
		$nb_ph         = implode( ',', array_fill( 0, count( $notebook_ids ), '%d' ) );
		$token_clauses = array();
		$token_params  = array();
		foreach ( $tokens as $token ) {
			$token_clauses[] = 'p.content LIKE %s';
			$token_params[]  = '%' . $wpdb->esc_like( $token ) . '%';
		}
		$token_where = implode( ' OR ', $token_clauses );
		$doc_key_sql = "IF(p.source_id > 0, CONCAT('s:', p.source_id), CONCAT('p:', p.id))";

		$count_sql = "SELECT COUNT(*) FROM (
			SELECT 1
			  FROM {$tbl_passages} p
			 WHERE p.notebook_id IN ({$nb_ph})
			   AND ({$token_where})
			 GROUP BY {$doc_key_sql}, p.notebook_id
		) q";
		$count_params = array_merge( $notebook_ids, $token_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );

		$offset = ( $page - 1 ) * $per_page;
		$docs_sql = "SELECT
				{$doc_key_sql} AS doc_key,
				p.notebook_id,
				MAX(p.source_id) AS source_id,
				COUNT(*) AS match_count,
				MAX(p.updated_at) AS last_hit_at,
				MIN(p.id) AS first_passage_id,
				MAX(s.title) AS source_title,
				MAX(s.origin_kind) AS origin_kind,
				MAX(s.origin_url) AS origin_url,
				MAX(s.content_text) AS source_content
			 FROM {$tbl_passages} p
			 LEFT JOIN {$tbl_sources} s ON s.id = p.source_id
			 WHERE p.notebook_id IN ({$nb_ph})
			   AND ({$token_where})
			 GROUP BY {$doc_key_sql}, p.notebook_id
			 ORDER BY match_count DESC, last_hit_at DESC
			 LIMIT %d OFFSET %d";
		$docs_params = array_merge( $notebook_ids, $token_params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$doc_rows = $wpdb->get_results( $wpdb->prepare( $docs_sql, $docs_params ), ARRAY_A );
		if ( ! is_array( $doc_rows ) ) {
			$doc_rows = array();
		}

		$first_ids = array_values( array_unique( array_filter( array_map( static function( $r ) {
			return (int) ( $r['first_passage_id'] ?? 0 );
		}, $doc_rows ) ) ) );
		$passage_map = array();
		if ( ! empty( $first_ids ) ) {
			$first_ph = implode( ',', array_fill( 0, count( $first_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$first_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content, metadata, storage_ver, file_shard, file_offset, file_length
				   FROM {$tbl_passages}
				  WHERE id IN ({$first_ph})",
				$first_ids
			), ARRAY_A );
			if ( $first_rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
				BizCity_KG_Content_Router::instance()->hydrate_passages( $first_rows );
			}
			if ( is_array( $first_rows ) ) {
				foreach ( $first_rows as $fr ) {
					$passage_map[ (int) $fr['id'] ] = $fr;
				}
			}
		}

		$results = array();
		foreach ( $doc_rows as $row ) {
			$source_id        = (int) ( $row['source_id'] ?? 0 );
			$first_passage_id = (int) ( $row['first_passage_id'] ?? 0 );
			$source_content   = isset( $row['source_content'] ) ? (string) $row['source_content'] : '';
			$passage_content  = isset( $passage_map[ $first_passage_id ]['content'] )
				? (string) $passage_map[ $first_passage_id ]['content']
				: '';
			$base_content = $source_content !== '' ? $source_content : $passage_content;
			$title = isset( $row['source_title'] ) && (string) $row['source_title'] !== ''
				? (string) $row['source_title']
				: ( $source_id > 0 ? ( 'Source #' . $source_id ) : ( 'Passage #' . $first_passage_id ) );

			$item = array(
				'doc_key'          => (string) ( $row['doc_key'] ?? '' ),
				'notebook_id'      => (int) ( $row['notebook_id'] ?? 0 ),
				'source_id'        => $source_id,
				'first_passage_id' => $first_passage_id,
				'title'            => $title,
				'origin_kind'      => (string) ( $row['origin_kind'] ?? '' ),
				'origin_url'       => (string) ( $row['origin_url'] ?? '' ),
				'match_count'      => (int) ( $row['match_count'] ?? 0 ),
				'last_hit_at'      => (string) ( $row['last_hit_at'] ?? '' ),
				'document_chars'   => $base_content !== '' ? mb_strlen( $base_content, 'UTF-8' ) : 0,
				'snippet'          => $this->build_snippet( $base_content, $tokens, 280 ),
			);
			if ( $include_content ) {
				$item['document_text'] = $base_content;
			}
			$results[] = $item;
		}

		$response = array(
			'query'        => $query,
			'scope'        => $scope,
			'tokens'       => $tokens,
			'page'         => $page,
			'per_page'     => $per_page,
			'total'        => $total,
			'total_pages'  => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
			'notebook_ids' => $notebook_ids,
			'results'      => $results,
		);

		$this->cache_set( $cache_key, $response );
		return $response;
	}

	/**
	 * Align card counts with the match endpoint when an older shared core is deployed.
	 *
	 * @param array $result Shared document-search response.
	 * @param array $args   Original search arguments.
	 * @return array
	 */
	private function normalize_document_match_counts( array $result, array $args ) {
		if ( empty( $result['results'] ) || ! is_array( $result['results'] ) ) {
			return $result;
		}

		$normalized = array();
		foreach ( $result['results'] as $item ) {
			$detail = $this->search_document_matches( array(
				'query'          => (string) ( $args['query'] ?? '' ),
				'doc_key'        => (string) ( $item['doc_key'] ?? '' ),
				'notebook_id'    => (int) ( $item['notebook_id'] ?? 0 ),
				'character_uuid' => (string) ( $item['character_uuid'] ?? '' ),
				'page'           => 1,
				'per_page'       => 200,
			) );
			$count = (int) ( $detail['total'] ?? 0 );
			if ( $count <= 0 ) {
				continue;
			}
			$item['match_count'] = $count;
			if ( ! empty( $detail['matches'][0]['passage_id'] ) ) {
				$item['first_passage_id'] = (int) $detail['matches'][0]['passage_id'];
				$item['citation']         = (string) ( $detail['matches'][0]['citation'] ?? ( $item['citation'] ?? '' ) );
			}
			$normalized[] = $item;
		}
		$result['results'] = $normalized;
		return $result;
	}

	/**
	 * Drill-down list of every matching passage excerpt for one search_documents()
	 * doc_key. Delegates to BizCity_TwinSearch_Core (no legacy fallback — this is
	 * a new feature only meaningful when the canonical core is loaded).
	 *
	 * [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — new wrapper, mirrors search_documents() delegate pattern.
	 *
	 * @param array $args { query, doc_key, notebook_id, character_uuid?, page?, per_page? }
	 * @return array
	 */
	public function search_document_matches( array $args ) {
		// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — tolerate a partially deployed core until the shared class is updated.
		if ( class_exists( 'BizCity_TwinSearch_Core' ) && method_exists( 'BizCity_TwinSearch_Core', 'search_document_matches' ) ) {
			return BizCity_TwinSearch_Core::instance()->search_document_matches( $args );
		}
		return $this->search_document_matches_fallback( $args );
	}

	/**
	 * Keep the drill-down endpoint functional while an older shared core is still deployed.
	 *
	 * @param array $args Search and scope arguments.
	 * @return array
	 */
	private function search_document_matches_fallback( array $args ) {
		global $wpdb;

		$query          = trim( (string) ( $args['query'] ?? '' ) );
		$doc_key        = trim( (string) ( $args['doc_key'] ?? '' ) );
		$notebook_id    = (int) ( $args['notebook_id'] ?? 0 );
		$character_uuid = strtolower( trim( (string) ( $args['character_uuid'] ?? '' ) ) );
		$page           = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page       = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$tokens         = $this->tokenize_query( $query );
		$empty          = array(
			'query'       => $query,
			'doc_key'     => $doc_key,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => 0,
			'total_pages' => 0,
			'matches'     => array(),
		);

		if ( '' === $query || empty( $tokens ) || '' === $doc_key || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $empty;
		}

		$source_id       = 0;
		$solo_passage_id = 0;
		if ( 0 === strpos( $doc_key, 's:' ) ) {
			$source_id = (int) substr( $doc_key, 2 );
		} elseif ( 0 === strpos( $doc_key, 'p:' ) ) {
			$solo_passage_id = (int) substr( $doc_key, 2 );
		}
		if ( $source_id <= 0 && $solo_passage_id <= 0 ) {
			return $empty;
		}

		$tbl_passages = BizCity_KG_Database::instance()->tbl_passages();
		$where        = array();
		$params       = array();
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
			$where[]  = 'p.character_uuid = %s';
			$params[] = $character_uuid;
		} else {
			$where[] = "( p.character_uuid IS NULL OR p.character_uuid = '' )";
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.notebook_id, p.content, p.metadata, p.storage_ver, p.file_shard, p.file_offset, p.file_length, p.updated_at
			   FROM {$tbl_passages} p
			  WHERE {$where_sql}
			  ORDER BY p.id ASC",
			$params
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			$rows = array();
		}
		if ( ! empty( $rows ) && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}

		// [2026-07-25 Johnny Chu] PHASE-0.47-KG-SEARCH-MATCHES — filter after hydration because storage_ver=2 scrubs SQL content.
		$matching_rows = array();
		foreach ( $rows as $row ) {
			$content_lower = mb_strtolower( (string) ( $row['content'] ?? '' ), 'UTF-8' );
			foreach ( $tokens as $token ) {
				if ( false !== mb_strpos( $content_lower, (string) $token, 0, 'UTF-8' ) ) {
					$matching_rows[] = $row;
					break;
				}
			}
		}
		$total = count( $matching_rows );
		if ( $total <= 0 ) {
			return $empty;
		}

		$offset = ( $page - 1 ) * $per_page;
		$rows   = array_slice( $matching_rows, $offset, $per_page );
		$matches = array();
		foreach ( $rows as $row ) {
			$passage_id = (int) ( $row['id'] ?? 0 );
			$match_nb   = (int) ( $row['notebook_id'] ?? $notebook_id );
			$content    = (string) ( $row['content'] ?? '' );
			$matches[]  = array(
				'passage_id'      => $passage_id,
				'notebook_id'     => $match_nb,
				'citation'        => ( $match_nb > 0 && $passage_id > 0 ) ? ( '[nb:' . $match_nb . '/p' . $passage_id . ']' ) : '',
				'highlight_quote' => $this->build_snippet( $content, $tokens, 280 ),
				'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
			);
		}

		$empty['total']       = $total;
		$empty['total_pages'] = (int) ceil( $total / $per_page );
		$empty['matches']     = $matches;
		return $empty;
	}

	private function empty_result( $query, $scope, $page, $per_page ) {
		return array(
			'query'        => (string) $query,
			'scope'        => (string) $scope,
			'tokens'       => array(),
			'page'         => (int) $page,
			'per_page'     => (int) $per_page,
			'total'        => 0,
			'total_pages'  => 0,
			'notebook_ids' => array(),
			'results'      => array(),
		);
	}

	private function resolve_notebook_ids( $user_id, $scope, $notebook_id ) {
		$user_id     = (int) $user_id;
		$scope       = (string) $scope;
		$notebook_id = (int) $notebook_id;

		if ( $scope === 'notebook' ) {
			return $notebook_id > 0 ? array( $notebook_id ) : array();
		}

		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return $notebook_id > 0 ? array( $notebook_id ) : array();
		}

		if ( current_user_can( 'manage_options' ) ) {
			global $wpdb;
			$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
			$ids = $wpdb->get_col( "SELECT id FROM {$tbl} ORDER BY id DESC LIMIT 2000" );
			if ( ! is_array( $ids ) ) {
				return array();
			}
			return array_values( array_unique( array_map( 'intval', $ids ) ) );
		}

		$rows = BizCity_KG_Notebook_Service::instance()->list_for_user( $user_id, array( 'limit' => 500 ) );
		$ids  = array();
		foreach ( (array) $rows as $row ) {
			$id = isset( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private function tokenize_query( $query ) {
		$query = mb_strtolower( (string) $query, 'UTF-8' );
		$raw   = preg_split( '/[^\p{L}\p{N}]+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$tokens = array();
		foreach ( $raw as $part ) {
			$part = trim( (string) $part );
			if ( $part === '' ) {
				continue;
			}
			if ( mb_strlen( $part, 'UTF-8' ) < 2 ) {
				continue;
			}
			$tokens[ $part ] = true;
		}
		if ( empty( $tokens ) && $query !== '' ) {
			$tokens[ $query ] = true;
		}
		return array_slice( array_keys( $tokens ), 0, 10 );
	}

	private function build_snippet( $text, array $tokens, $length = 280 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( $text === '' ) {
			return '';
		}

		$length = max( 80, (int) $length );
		$pos    = null;
		$lower  = mb_strtolower( $text, 'UTF-8' );
		foreach ( $tokens as $token ) {
			$idx = mb_strpos( $lower, (string) $token, 0, 'UTF-8' );
			if ( false !== $idx ) {
				$pos = (int) $idx;
				break;
			}
		}

		if ( null === $pos ) {
			$snippet = mb_substr( $text, 0, $length, 'UTF-8' );
			if ( mb_strlen( $text, 'UTF-8' ) > $length ) {
				$snippet .= '...';
			}
			return $snippet;
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

	private function cache_get( $key ) {
		if ( ! class_exists( 'BizCity_Cache' ) ) {
			return false;
		}
		return BizCity_Cache::get( self::CACHE_GROUP, (string) $key );
	}

	private function cache_set( $key, $value ) {
		if ( ! class_exists( 'BizCity_Cache' ) ) {
			return;
		}
		$ttl = defined( 'BizCity_Cache::TTL_SHORT' ) ? BizCity_Cache::TTL_SHORT : self::CACHE_TTL;
		BizCity_Cache::set( self::CACHE_GROUP, (string) $key, $value, $ttl );
	}
}

// [2026-07-14 Johnny Chu] PHASE-0.43 — fallback contract when shared core/twinsearch is unavailable.
if ( class_exists( 'BizCity_Cache_Registry' ) && class_exists( 'BizCity_Cache' ) ) {
	if ( ! class_exists( 'BizCity_TwinSearch_Core' ) ) {
		BizCity_Cache_Registry::register( 'chat', 'modules.twinchat', array(
			'search_docs_{args_hash}' => array( 'ttl' => BizCity_Cache::TTL_SHORT, 'desc' => 'TwinChat keyword document search payload' ),
		) );
	}
}
