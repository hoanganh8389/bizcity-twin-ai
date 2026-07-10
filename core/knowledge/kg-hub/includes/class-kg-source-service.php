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
			$exists = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
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
			$tbl_check = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			if ( ! $tbl_check ) continue;

			// Determine chunk count table.
			$chunk_suffix = ( $suffix === 'bizcity_webchat_sources' )
				? 'bizcity_webchat_source_chunks'
				: 'bizcity_knowledge_chunks';
			$chunk_tbl = $wpdb->prefix . $chunk_suffix;
			$chunk_check = bizcity_tbl_exists( $chunk_tbl ) ? $chunk_tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES

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
			// Check table exists first.
			$exists = bizcity_tbl_exists( $tbl ) ? $tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
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
		$exists = bizcity_tbl_exists( $src_tbl ) ? $src_tbl : null; // [2026-06-21 Johnny Chu] R-SHOW-TABLES
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
			// Filestore-only (Rule v2.0): set embedding column NULL; .bin is source of truth.
			$embedding_json = null;

			$wpdb->insert( $db->tbl_passages(), [
				'notebook_id'       => (int) $notebook_id,
				'source_id'         => (int) $source_id,
				'chunk_id'          => null,
				'origin'            => 'source',
				'content'           => $content,
				'content_hash'      => $content_hash,
				'embedding'         => null,
				'token_count'       => (int) ceil( mb_strlen( $content ) / 4 ),
				'extraction_status' => 'pending',
				'metadata'          => wp_json_encode( [ 'chunk_index' => $idx, 'source' => 'content_text' ] ),
			] );
			$count++;

			// PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0 — .bin is single source of truth.
			$pid = (int) $wpdb->insert_id;
			if ( $pid && is_array( $vec ) && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
				BizCity_KG_Embedding_Writer::instance()->register_chunk(
					(int) $notebook_id, $pid, $vec, null, (int) $source_id
				);
			}
			// PHASE-0.7-LEARN-VECTOR-FILE Wave F1 — dual-write body to filestore.
			// No-op when option `bizcity_kg_filestore_dual_write` !== 1.
			if ( $pid && class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
				BizCity_KG_Filestore_Dispatcher::instance()->after_passage_insert(
					$pid, (int) $notebook_id, $content
				);
			}
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
				'embedding'         => null,
				'token_count'       => (int) ( $chunk['token_count'] ?? 0 ),
				'extraction_status' => 'pending',
				'metadata'          => $has_meta && ! empty( $chunk['metadata'] )
					? $chunk['metadata']
					: wp_json_encode( (object) [] ),
			] );
			$count++;

			// PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0 — .bin only.
			$pid = (int) $wpdb->insert_id;
			if ( $pid && ! empty( $chunk['embedding'] ) && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
				$vec_arr = is_array( $chunk['embedding'] )
					? $chunk['embedding']
					: json_decode( (string) $chunk['embedding'], true );
				if ( is_array( $vec_arr ) && ! empty( $vec_arr ) ) {
					BizCity_KG_Embedding_Writer::instance()->register_chunk(
						(int) $notebook_id, $pid, $vec_arr, null, (int) $source_id
					);
				}
			}
			// PHASE-0.7-LEARN-VECTOR-FILE Wave F1 — dual-write body to filestore.
			if ( $pid && class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
				BizCity_KG_Filestore_Dispatcher::instance()->after_passage_insert(
					$pid, (int) $notebook_id, $content
				);
			}
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
		// Filestore-only (Rule v2.0): embedding column stays NULL; .bin holds vector.

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
			'embedding'        => null,
			'token_count'      => $token_estimate,
			'extraction_status'=> 'pending',
			'metadata'         => wp_json_encode( $metadata ),
		] );
		$pid = (int) $wpdb->insert_id;

		// PHASE-0-RULE-VECTOR-FILE-STORE.md v2.0 — .bin is source of truth.
		if ( $pid && is_array( $vec ) && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
			BizCity_KG_Embedding_Writer::instance()->register_chunk(
				(int) $notebook_id, $pid, $vec, null, null
			);
		}
		// PHASE-0.7-LEARN-VECTOR-FILE Wave F1 — dual-write body to filestore.
		if ( $pid && class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
			BizCity_KG_Filestore_Dispatcher::instance()->after_passage_insert(
				$pid, (int) $notebook_id, $content
			);
		}

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

		// PHASE 0.31 T-S3.1 — fire Brain⇄Workflow event so trigger blocks can react.
		// Payload contract is documented at the trigger side (`nb_note_created`).
		// `event` is the subtype string the trigger filters on.
		if ( $pid ) {
			do_action( 'bizcity_twin_notebook_event', 'note_created', array(
				'event'       => 'note_created',
				'passage_id'  => (int) $pid,
				'notebook_id' => (int) $notebook_id,
				'origin'      => $origin_clean,
				'content'     => $content,
				'metadata'    => $metadata,
				'user_id'     => get_current_user_id(),
				'timestamp'   => time() * 1000,
			) );
		}

		return $pid;
	}

	/**
	 * PHASE 0.31 T-S3.1 (Sprint 4 follow-up) — Update an existing passage.
	 *
	 * Supports updating `content`, `metadata` (merge), and/or `origin`.
	 * - When `content` changes: recompute `content_hash`, regenerate embedding,
	 *   dual-write into the .bin store, and record cost.
	 * - When `metadata` changes: shallow-merge with existing JSON (caller passes
	 *   only the keys to overwrite; pass `null` value to remove a key).
	 * - Emits `bizcity_twin_notebook_event` with subtype `note_updated` per changed
	 *   field so trigger `nb_note_updated` (with optional changed_field filter)
	 *   reacts deterministically.
	 *
	 * @param int   $passage_id
	 * @param array $changes  ['content' => string, 'metadata' => array, 'origin' => string]
	 * @return int|WP_Error  passage_id on success, WP_Error otherwise.
	 */
	public function update_passage( $passage_id, array $changes ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$passage_id = (int) $passage_id;
		if ( $passage_id <= 0 ) {
			return new WP_Error( 'invalid_id', 'passage_id required' );
		}
		if ( empty( $changes ) ) {
			return new WP_Error( 'empty', 'No changes supplied' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, origin, content, metadata,
			        storage_ver, file_shard, file_offset, file_length
			 FROM {$db->tbl_passages()} WHERE id = %d LIMIT 1",
			$passage_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Passage not found' );
		}
		// Filestore-first read — inline `content` is NULL once Wave F4 cleaned.
		if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
			$row['content'] = BizCity_KG_Content_Router::instance()->passage_body( $row );
		}

		$update      = array();
		$diffs       = array();   // [ ['changed_field', $old, $new], ... ]
		$notebook_id = (int) $row['notebook_id'];

		// --- content -------------------------------------------------------
		if ( array_key_exists( 'content', $changes ) ) {
			$new_content = trim( (string) $changes['content'] );
			if ( $new_content === '' ) {
				return new WP_Error( 'empty_content', 'Empty content' );
			}
			if ( $new_content !== (string) $row['content'] ) {
				$new_hash = md5( $new_content );
				$update['content']      = $new_content;
				$update['content_hash'] = $new_hash;
				$update['token_count']  = (int) ceil( str_word_count( $new_content ) * 1.3 );

				$vec = BizCity_KG_Vector_Index::instance()->embed( $new_content );
				if ( is_array( $vec ) ) {
					$update['embedding'] = BizCity_KG_Database::encode_embedding( $vec );
				}
				$update['extraction_status'] = 'pending';

				$diffs[] = array( 'content', (string) $row['content'], $new_content );

				// Re-register embedding + cost (deferred to AFTER update succeeds).
				$pending_embedding = is_array( $vec ) ? $vec : null;
			}
		}

		// --- metadata (shallow merge) -------------------------------------
		if ( array_key_exists( 'metadata', $changes ) && is_array( $changes['metadata'] ) ) {
			$old_meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
			if ( ! is_array( $old_meta ) ) { $old_meta = array(); }
			$new_meta = $old_meta;
			foreach ( $changes['metadata'] as $k => $v ) {
				if ( $v === null ) {
					unset( $new_meta[ $k ] );
				} else {
					$new_meta[ $k ] = $v;
				}
			}
			if ( $new_meta !== $old_meta ) {
				$update['metadata'] = wp_json_encode( $new_meta );
				$diffs[] = array( 'metadata', $old_meta, $new_meta );
			}
		}

		// --- origin --------------------------------------------------------
		if ( array_key_exists( 'origin', $changes ) ) {
			$new_origin = substr( sanitize_text_field( (string) $changes['origin'] ), 0, 100 );
			if ( $new_origin !== (string) $row['origin'] ) {
				$update['origin'] = $new_origin;
				$diffs[] = array( 'origin', (string) $row['origin'], $new_origin );
			}
		}

		if ( empty( $update ) ) {
			return $passage_id; // nothing actually changed
		}

		$wpdb->update( $db->tbl_passages(), $update, array( 'id' => $passage_id ) );

		// Dual-write embedding + cost AFTER successful update.
		if ( isset( $update['embedding'] ) && ! empty( $pending_embedding ) ) {
			if ( class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
				BizCity_KG_Embedding_Writer::instance()->register_chunk(
					$notebook_id, $passage_id, $pending_embedding, null, null
				);
			}
			if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
				BizCity_KG_Cost_Guard::instance()->record_usage( array(
					'user_id'      => get_current_user_id(),
					'operation'    => BizCity_KG_Cost_Guard::OP_EMBED,
					'notebook_id'  => $notebook_id,
					'passage_id'   => $passage_id,
					'input_tokens' => isset( $update['token_count'] ) ? (int) $update['token_count'] : 0,
				) );
			}
		}

		// Fire one event per changed field so trigger filter (changed_field=…)
		// can target a single field cleanly.
		foreach ( $diffs as $d ) {
			list( $field, $old_val, $new_val ) = $d;
			do_action( 'bizcity_twin_notebook_event', 'note_updated', array(
				'event'         => 'note_updated',
				'passage_id'    => $passage_id,
				'notebook_id'   => $notebook_id,
				'changed_field' => $field,
				'old_value'     => $old_val,
				'new_value'     => $new_val,
				'user_id'       => get_current_user_id(),
				'timestamp'     => time() * 1000,
			) );
		}

		return $passage_id;
	}

	/**
	 * PHASE 0.31 T-S3.1 (Sprint 4 follow-up) — Add or remove a tag on a passage.
	 *
	 * Tags live inside `metadata.tags` (array of unique strings, lowercase-trim).
	 * Emits `bizcity_twin_notebook_event` with subtype `note_tagged` so the
	 * `nb_note_tagged` trigger reacts. No-op (returns passage_id) if the tag
	 * change would be a duplicate add or remove-of-missing.
	 *
	 * @param int    $passage_id
	 * @param string $tag
	 * @param string $action  'added' | 'removed'
	 * @return int|WP_Error
	 */
	public function tag_passage( $passage_id, $tag, $action = 'added' ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();
		$passage_id = (int) $passage_id;
		$tag        = strtolower( trim( (string) $tag ) );
		$action     = ( $action === 'removed' ) ? 'removed' : 'added';

		if ( $passage_id <= 0 || $tag === '' ) {
			return new WP_Error( 'invalid', 'passage_id and tag required' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, notebook_id, metadata FROM {$db->tbl_passages()} WHERE id = %d LIMIT 1",
			$passage_id
		), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Passage not found' );
		}

		$meta = json_decode( (string) ( $row['metadata'] ?? '' ), true );
		if ( ! is_array( $meta ) ) { $meta = array(); }
		$tags = isset( $meta['tags'] ) && is_array( $meta['tags'] ) ? array_values( $meta['tags'] ) : array();
		$tags = array_map( static function ( $t ) { return strtolower( trim( (string) $t ) ); }, $tags );
		$tags = array_values( array_unique( array_filter( $tags ) ) );

		$has = in_array( $tag, $tags, true );
		if ( $action === 'added' && $has )       { return $passage_id; } // no-op
		if ( $action === 'removed' && ! $has )   { return $passage_id; } // no-op

		if ( $action === 'added' ) {
			$tags[] = $tag;
		} else {
			$tags = array_values( array_diff( $tags, array( $tag ) ) );
		}

		$meta['tags'] = $tags;
		$wpdb->update(
			$db->tbl_passages(),
			array( 'metadata' => wp_json_encode( $meta ) ),
			array( 'id' => $passage_id )
		);

		do_action( 'bizcity_twin_notebook_event', 'note_tagged', array(
			'event'       => 'note_tagged',
			'passage_id'  => $passage_id,
			'notebook_id' => (int) $row['notebook_id'],
			'tag'         => $tag,
			'action'      => $action,
			'all_tags'    => $tags,
			'user_id'     => get_current_user_id(),
			'timestamp'   => time() * 1000,
		) );

		return $passage_id;
	}

	/**
	 * List passages of a notebook (paginated).
	 */
	public function list_passages( $notebook_id, $args = [] ) {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$limit  = isset( $args['limit'] )  ? min( 200, (int) $args['limit'] ) : 50;
		$offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;

		$rows = $wpdb->get_results( $wpdb->prepare(
			// Bug fix 2026-05-08 — `chunk_id` only exists when kg_passages is the
			// VIEW alias from migrate_v065_unified_sources() (`uuid AS chunk_id`).
			// On blogs reverted to physical kg_passages by HOTFIX 2026-05-06 the
			// canonical column is `uuid`. Select with explicit alias to work on
			// BOTH shapes — VIEW already exposes `uuid` underneath, BASE TABLE
			// has it natively.
			"SELECT id, notebook_id, source_id, uuid AS chunk_id, origin, content, token_count, extraction_status, created_at,
			        storage_ver, file_shard, file_offset, file_length
			 FROM {$db->tbl_passages()}
			 WHERE notebook_id = %d
			 ORDER BY created_at DESC
			 LIMIT %d OFFSET %d",
			(int) $notebook_id, $limit, $offset
		), ARRAY_A ) ?: [];
		if ( $rows && class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		return $rows;
	}
}
