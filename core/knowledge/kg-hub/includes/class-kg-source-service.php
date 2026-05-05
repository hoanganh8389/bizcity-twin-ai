<?php
/**
 * Bizcity Twin AI — KG_Source_Service
 *
 * Attaches existing knowledge sources/chunks to a notebook and promotes
 * each chunk into a KG passage (with embedding copy / regen).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Source_Service {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Known candidate chunk tables — in priority order.
	 * webchat_source_chunks is primary (richer data + more resources).
	 * Each entry: [table_suffix, has_content_hash, has_metadata].
	 */
	private static $chunk_table_map = [
		[ 'bizcity_webchat_source_chunks',   false, false ],
		[ 'bizcity_knowledge_chunks',        true,  true  ],
		[ 'bizcity_knowledge_source_chunks', false, false ],
	];

	/**
	 * Paired source-metadata tables — same priority order as chunk tables.
	 * Each entry: [table_suffix, title_col, url_col, type_col].
	 */
	private static $source_meta_map = [
		[ 'bizcity_webchat_sources',   'title', 'source_url',   'source_type'  ],
		[ 'bizcity_knowledge_sources', 'name',  'url',          'type'         ],
	];

	/**
	 * Look up display metadata (title, url, type) for a source_id from any known source table.
	 *
	 * @param int $source_id
	 * @return array{id:int,title:string,source_url:string,source_type:string,table:string}|null
	 */
	public function lookup_source_meta( $source_id ) {
		global $wpdb;
		foreach ( self::$source_meta_map as $entry ) {
			list( $suffix, $title_col, $url_col, $type_col ) = $entry;
			$tbl    = $wpdb->prefix . $suffix;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( ! $exists ) continue;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, `{$title_col}` AS title, `{$url_col}` AS source_url, `{$type_col}` AS source_type
				 FROM `{$tbl}` WHERE id = %d LIMIT 1",
				(int) $source_id
			), ARRAY_A );
			if ( $row ) {
				$row['table'] = $tbl;
				return $row;
			}
		}
		return null;
	}

	/**
	 * List available sources from webchat_sources (primary) or knowledge_sources.
	 * Used by UI to let user pick instead of typing a raw ID.
	 *
	 * @param array $args  ['limit', 'search', 'project_id', 'user_id']
	 * @return array  each: {id, title, source_url, source_type, chunk_count, table}
	 */
	public function list_available_sources( array $args = [] ) {
		global $wpdb;
		$limit     = min( 200, (int) ( $args['limit'] ?? 50 ) );
		$search    = isset( $args['search'] ) ? '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%' : null;
		$project   = isset( $args['project_id'] ) ? sanitize_text_field( $args['project_id'] ) : null;
		$user_id   = isset( $args['user_id'] )    ? (int) $args['user_id']   : null;

		$results = [];

		foreach ( self::$source_meta_map as $entry ) {
			list( $suffix, $title_col, $url_col, $type_col ) = $entry;
			$tbl       = $wpdb->prefix . $suffix;
			$tbl_check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( ! $tbl_check ) continue;

			// Determine chunk count table.
			$chunk_suffix = ( $suffix === 'bizcity_webchat_sources' )
				? 'bizcity_webchat_source_chunks'
				: 'bizcity_knowledge_chunks';
			$chunk_tbl = $wpdb->prefix . $chunk_suffix;
			$has_chunks = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $chunk_tbl ) );

			$where  = [];
			$params = [];
			if ( $search ) {
				$where[]  = "`{$title_col}` LIKE %s";
				$params[] = $search;
			}
			if ( $project && in_array( $suffix, [ 'bizcity_webchat_sources' ], true ) ) {
				$where[]  = 'project_id = %s';
				$params[] = $project;
			}
			if ( $user_id && in_array( $suffix, [ 'bizcity_webchat_sources' ], true ) ) {
				$where[]  = 'user_id = %d';
				$params[] = $user_id;
			}

			$where_sql = $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '';
			$params[]  = $limit;

			$rows = empty( $params ) || count( $params ) === 1
				? $wpdb->get_results(
					"SELECT id, `{$title_col}` AS title, `{$url_col}` AS source_url, `{$type_col}` AS source_type
					 FROM `{$tbl}` ORDER BY id DESC LIMIT {$limit}",
					ARRAY_A )
				: $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->prepare(
						"SELECT id, `{$title_col}` AS title, `{$url_col}` AS source_url, `{$type_col}` AS source_type
						 FROM `{$tbl}` {$where_sql} ORDER BY id DESC LIMIT %d",
						...$params
					),
					ARRAY_A );

			if ( empty( $rows ) ) continue;

			foreach ( $rows as $r ) {
				$sid = (int) $r['id'];
				$chunk_count = $has_chunks
					? (int) $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM `{$chunk_tbl}` WHERE source_id = %d", $sid
					) )
					: 0;
				$results[] = [
					'id'          => $sid,
					'title'       => (string) ( $r['title'] ?? '' ),
					'source_url'  => (string) ( $r['source_url'] ?? '' ),
					'source_type' => (string) ( $r['source_type'] ?? '' ),
					'chunk_count' => $chunk_count,
					'table'       => $suffix,
				];
			}

			// Use first table that has results (webchat is primary).
			if ( ! empty( $results ) ) break;
		}

		return $results;
	}

	/**
	 * Auto-detect which chunk table contains rows for the given source_id.
	 * Returns the table name (with prefix) and its capability flags, or null.
	 *
	 * @return array{table:string,has_content_hash:bool,has_metadata:bool}|null
	 */
	private function detect_chunk_table( $source_id ) {
		global $wpdb;
		foreach ( self::$chunk_table_map as $entry ) {
			list( $suffix, $has_hash, $has_meta ) = $entry;
			$tbl = $wpdb->prefix . $suffix;
			// Check table exists first (SHOW TABLES is cheap).
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) );
			if ( ! $exists ) {
				continue;
			}
			$cnt = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$tbl}` WHERE source_id = %d", (int) $source_id
			) );
			if ( $cnt > 0 ) {
				return [ 'table' => $tbl, 'has_content_hash' => $has_hash, 'has_metadata' => $has_meta ];
			}
		}
		return null;
	}

	/**
	 * Attach a knowledge source to a notebook and promote all its chunks into kg_passages.
	 * Auto-detects the chunk table (knowledge_chunks, webchat_source_chunks, …).
	 *
	 * @param int    $notebook_id
	 * @param int    $source_id   integer source ID from any chunk table
	 * @return array{passages:int, source_id:int, table:string|null}
	 */
	public function attach_source( $notebook_id, $source_id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$notebook_id = (int) $notebook_id;
		$source_id   = (int) $source_id;

		// Insert link (ignore duplicate).
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$db->tbl_notebook_sources()} (notebook_id, source_id) VALUES (%d, %d)",
			$notebook_id, $source_id
		) );

		$promoted = $this->promote_chunks_for_source( $notebook_id, $source_id );
		$meta     = $this->lookup_source_meta( $source_id );

		return [
			'passages'    => $promoted['count'],
			'source_id'   => $source_id,
			'table'       => $promoted['table'],
			'title'       => $meta ? $meta['title']       : '',
			'source_url'  => $meta ? $meta['source_url']  : '',
			'source_type' => $meta ? $meta['source_type'] : '',
		];
	}

	/**
	 * Fallback: when no chunk table has rows, read content_text from webchat_sources
	 * directly, split into ~1500-char passages, and promote each.
	 *
	 * @return array{count:int, table:string|null}
	 */
	private function promote_from_content_text( $notebook_id, $source_id ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$src_tbl = $wpdb->prefix . 'bizcity_webchat_sources';
		$exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $src_tbl ) );
		if ( ! $exists ) {
			return [ 'count' => 0, 'table' => null ];
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, content_text FROM `{$src_tbl}` WHERE id = %d LIMIT 1",
			(int) $source_id
		), ARRAY_A );

		if ( ! $row || empty( $row['content_text'] ) ) {
			return [ 'count' => 0, 'table' => null ];
		}

		// Split content_text into chunks of ~1500 chars (≈300–400 tokens).
		$text       = (string) $row['content_text'];
		$chunk_size = 1500;
		$chunks     = [];

		// Prefer splitting on paragraph breaks first.
		$paragraphs = preg_split( '/\n{2,}/', $text );
		$buf        = '';
		foreach ( (array) $paragraphs as $p ) {
			$p = trim( $p );
			if ( $p === '' ) continue;
			if ( strlen( $buf ) + strlen( $p ) > $chunk_size && $buf !== '' ) {
				$chunks[] = trim( $buf );
				$buf = $p;
			} else {
				$buf .= ( $buf ? "\n\n" : '' ) . $p;
			}
		}
		if ( $buf !== '' ) {
			$chunks[] = trim( $buf );
		}

		// If paragraphs are huge, further split each chunk.
		$final_chunks = [];
		foreach ( $chunks as $c ) {
			if ( strlen( $c ) <= $chunk_size * 2 ) {
				$final_chunks[] = $c;
			} else {
				foreach ( str_split( $c, $chunk_size ) as $piece ) {
					$final_chunks[] = trim( $piece );
				}
			}
		}

		$count = 0;
		foreach ( $final_chunks as $idx => $content ) {
			if ( $content === '' ) continue;
			$content_hash = md5( $content );

			$exists_row = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$db->tbl_passages()} WHERE notebook_id=%d AND content_hash=%s LIMIT 1",
				(int) $notebook_id, $content_hash
			) );
			if ( $exists_row ) continue;

			// Generate embedding.
			$vec            = BizCity_KG_Vector_Index::instance()->embed( $content );
			$embedding_json = is_array( $vec ) ? BizCity_KG_Database::encode_embedding( $vec ) : null;

			$wpdb->insert( $db->tbl_passages(), [
				'notebook_id'       => (int) $notebook_id,
				'source_id'         => (int) $source_id,
				'chunk_id'          => null,
				'origin'            => 'source',
				'content'           => $content,
				'content_hash'      => $content_hash,
				'embedding'         => $embedding_json,
				'token_count'       => (int) ceil( mb_strlen( $content ) / 4 ),
				'extraction_status' => 'pending',
				'metadata'          => wp_json_encode( [ 'chunk_index' => $idx, 'source' => 'content_text' ] ),
			] );
			$count++;
		}

		return [ 'count' => $count, 'table' => $src_tbl . ' (content_text)' ];
	}

	/**
	 * Promote chunks from any detected chunk table → kg_passages.
	 * Falls back to content_text from webchat_sources when no chunks found.
	 * Returns ['count' => int, 'table' => string|null].
	 */
	public function promote_chunks_for_source( $notebook_id, $source_id ) {
		global $wpdb;
		$db          = BizCity_KG_Database::instance();
		$notebook_id = (int) $notebook_id;
		$source_id   = (int) $source_id;

		$detected = $this->detect_chunk_table( $source_id );
		if ( ! $detected ) {
			// Fallback: source may store content directly in webchat_sources.content_text.
			return $this->promote_from_content_text( $notebook_id, $source_id );
		}

		$chunks_table   = $detected['table'];
		$has_hash       = $detected['has_content_hash'];
		$has_meta       = $detected['has_metadata'];

		// Build SELECT based on available columns.
		$select_cols = 'id, content, embedding, token_count';
		if ( $has_hash ) $select_cols .= ', content_hash';
		if ( $has_meta ) $select_cols .= ', metadata';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$select_cols} FROM `{$chunks_table}` WHERE source_id = %d",
			$source_id
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return [ 'count' => 0, 'table' => $chunks_table ];
		}

		$count = 0;
		foreach ( $rows as $chunk ) {
			$content      = (string) ( $chunk['content'] ?? '' );
			if ( $content === '' ) continue;
			$content_hash = $has_hash && ! empty( $chunk['content_hash'] )
				? $chunk['content_hash']
				: md5( $content );

			// Dedup: skip if same notebook+hash already exists.
			$exists = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$db->tbl_passages()} WHERE notebook_id=%d AND content_hash=%s LIMIT 1",
				$notebook_id, $content_hash
			) );
			if ( $exists ) {
				continue;
			}

			$wpdb->insert( $db->tbl_passages(), [
				'notebook_id'       => $notebook_id,
				'source_id'         => $source_id,
				'chunk_id'          => (int) $chunk['id'],
				'origin'            => 'source',
				'content'           => $content,
				'content_hash'      => $content_hash,
				'embedding'         => $chunk['embedding'] ?: null,
				'token_count'       => (int) ( $chunk['token_count'] ?? 0 ),
				'extraction_status' => 'pending',
				'metadata'          => $has_meta && ! empty( $chunk['metadata'] )
					? $chunk['metadata']
					: wp_json_encode( (object) [] ),
			] );
			$count++;
		}
		return [ 'count' => $count, 'table' => $chunks_table ];
	}

	/**
	 * Add a free-form passage (note / chat snippet / manual input) to a notebook.
	 */
	public function add_passage( $notebook_id, $content, $origin = 'note', $metadata = [] ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$content      = trim( (string) $content );
		if ( $content === '' ) {
			return new WP_Error( 'empty', 'Empty content' );
		}
		$content_hash = md5( $content );

		// Phase 0.5 — Cost Guard dedupe by hash (skip identical passage in same notebook).
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$dup = BizCity_KG_Cost_Guard::instance()->find_duplicate_by_hash( (int) $notebook_id, $content_hash );
			if ( $dup ) {
				return (int) $dup;
			}
		}

		// Generate embedding immediately (cheap, cached).
		$token_estimate = (int) ceil( str_word_count( $content ) * 1.3 );
		$vec = BizCity_KG_Vector_Index::instance()->embed( $content );
		$embedding_json = is_array( $vec ) ? BizCity_KG_Database::encode_embedding( $vec ) : null;

		// Use sanitize_text_field (not sanitize_key) to preserve colons/dots in origins like
		// 'file:filename.txt' and 'url:domain.com'. Truncate to 100 chars (column width).
		$origin_clean = substr( sanitize_text_field( $origin ), 0, 100 );

		$wpdb->insert( $db->tbl_passages(), [
			'notebook_id'      => (int) $notebook_id,
			'source_id'        => null,
			'chunk_id'         => null,
			'origin'           => $origin_clean,
			'content'          => $content,
			'content_hash'     => $content_hash,
			'embedding'        => $embedding_json,
			'token_count'      => $token_estimate,
			'extraction_status'=> 'pending',
			'metadata'         => wp_json_encode( $metadata ),
		] );
		$pid = (int) $wpdb->insert_id;

		// Record embedding cost.
		if ( $pid && is_array( $vec ) && class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			BizCity_KG_Cost_Guard::instance()->record_usage( [
				'user_id'      => get_current_user_id(),
				'operation'    => BizCity_KG_Cost_Guard::OP_EMBED,
				'notebook_id'  => (int) $notebook_id,
				'passage_id'   => $pid,
				'input_tokens' => $token_estimate,
			] );
		}

		return $pid;
	}

	/**
	 * List passages of a notebook (paginated).
	 */
	public function list_passages( $notebook_id, $args = [] ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$limit  = isset( $args['limit'] )  ? min( 200, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, chunk_id, origin, content, token_count, extraction_status, created_at
			 FROM {$db->tbl_passages()}
			 WHERE notebook_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d OFFSET %d",
			(int) $notebook_id, $limit, $offset
		), ARRAY_A ) ?: [];
	}
}
