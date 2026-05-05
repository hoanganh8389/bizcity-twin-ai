<?php
/**
 * Bizcity Twin AI — TwinChat Sources Service
 *
 * Implements the KG-Hub ingest contract for the TwinChat plugin.
 *
 * Pipeline:
 *   1. Validate + normalize payload (file | url | text | manual)
 *   2. Insert raw row into `bizcity_twinchat_sources`
 *   3. Extract content text (read file / fetch URL / use given text)
 *   4. Chunk content (Smart Sources Standard §3 — ~1500 chars w/ 200 overlap)
 *   5. Embed all chunks via bizcity_openrouter_embeddings()
 *   6. Insert chunks into `bizcity_twinchat_source_chunks`
 *   7. Promote each chunk into `bizcity_kg_passages` (linked source_id + chunk_id)
 *   8. Update source row stats (chunk_count, embedding_status)
 *
 * Service contract methods consumed by BizCity_KG facade:
 *   - ingest(int $scope_id, int $user_id, array $payload): array|WP_Error
 *   - list_sources(int $scope_id, array $args): array
 *   - get_source(int $source_id): ?array
 *   - delete_source(int $source_id): bool
 *   - list_scopes(int $user_id): array
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Sources_Service {

	const EMBED_MODEL    = 'text-embedding-3-small';
	const CHUNK_CHARS    = 1500;
	const CHUNK_OVERLAP  = 200;
	const MAX_FILE_BYTES = 5242880; // 5 MB

	const ALLOWED_TEXT_EXT = [
		'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log',
		'html', 'htm', 'xml', 'rtf', 'srt', 'vtt',
	];

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/* ──────────────────────  Public Service Contract  ────────────────────── */

	/**
	 * @param int   $scope_id  notebook_id
	 * @param int   $user_id
	 * @param array $payload   { type, title?, content?, url?, file?, attachment_id?, metadata? }
	 * @return array|WP_Error
	 */
	public function ingest( $scope_id, $user_id, array $payload ) {
		$scope_id = (int) $scope_id;
		$user_id  = (int) $user_id;
		if ( $scope_id <= 0 ) {
			return new WP_Error( 'invalid_scope', 'notebook_id required' );
		}
		$type = isset( $payload['type'] ) ? sanitize_key( $payload['type'] ) : 'text';
		$type = in_array( $type, [ 'file', 'url', 'text', 'manual' ], true ) ? $type : 'text';

		// PHP-2024: keep work alive even if Cloudflare cuts the client connection at ~100s
		// (524 Origin Timeout). Server-side ingest will still complete; the FE polls
		// list_sources() to discover when embedding_status flips to 'ready'.
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		$file_size = 0;
		if ( $type === 'file' && isset( $payload['file']['size'] ) ) {
			$file_size = (int) $payload['file']['size'];
		}
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'ingest_start', [
				'scope_id'  => $scope_id,
				'user_id'   => $user_id,
				'type'      => $type,
				'file_name' => $payload['file']['name'] ?? '',
				'file_size' => $file_size,
				'url'       => $payload['url'] ?? '',
				'title'     => $payload['title'] ?? '',
			] );
		}

		// Materialize content + title.
		$t0 = microtime( true );
		$material = $this->materialize_content( $type, $payload );
		if ( is_wp_error( $material ) ) {
			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'kg', 'ingest_materialize_error', [
					'scope_id' => $scope_id,
					'error'    => $material->get_error_message(),
				] );
			}
			return $material;
		}
		$title       = $material['title'];
		$content     = $material['content'];
		$source_url  = $material['source_url'];
		$attach_id   = (int) ( $payload['attachment_id'] ?? 0 );
		$extra_meta  = isset( $payload['metadata'] ) && is_array( $payload['metadata'] ) ? $payload['metadata'] : [];
		$metadata    = array_merge( [
			'origin' => $type,
			'mime'   => $material['mime'] ?? '',
		], $extra_meta );

		if ( $content === '' ) {
			return new WP_Error( 'empty_content', 'Source has no readable content' );
		}

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'ingest_materialized', [
				'scope_id'    => $scope_id,
				'title'       => $title,
				'content_len' => mb_strlen( $content ),
				'elapsed_ms'  => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			] );
		}

		$db   = BizCity_TwinChat_Sources_Database::instance();
		$hash = hash( 'sha256', $content );

		// URL dedup: check bizcity_kg_sources (unified canonical table) for an
		// existing row with the same notebook scope + URL before any DB write.
		// origin_id = bizcity_webchat_sources.id → returned as source_id so
		// callers stay consistent regardless of which dedup path fired.
		if ( $source_url !== '' && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$_kg_tbl        = BizCity_KG_Database::instance()->tbl_sources();
			$url_dup_origin = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT origin_id FROM {$_kg_tbl}
				  WHERE scope_type    = 'notebook'
				    AND scope_id      = %s
				    AND origin_plugin = 'twinchat'
				    AND origin_url    = %s
				  LIMIT 1",
				(string) $scope_id,
				$source_url
			) );
			if ( $url_dup_origin > 0 ) {
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'ingest_duplicate_url', [
						'scope_id'  => $scope_id,
						'source_id' => $url_dup_origin,
						'url'       => $source_url,
					] );
				}
				return [
					'source_id'   => $url_dup_origin,
					'chunk_count' => 0,
					'passage_ids' => [],
					'duplicate'   => true,
					'dedup_by'    => 'url',
				];
			}
		}

		// Hash dedup: if same notebook already has a source with same hash, return it.
		$dup_id = $db->find_by_hash( $scope_id, $hash );
		if ( $dup_id > 0 ) {
			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'kg', 'ingest_duplicate', [
					'scope_id'  => $scope_id,
					'source_id' => $dup_id,
				] );
			}
			return [
				'source_id'    => $dup_id,
				'chunk_count'  => 0,
				'passage_ids'  => [],
				'duplicate'    => true,
			];
		}

		$source_id = $db->insert_source( [
			'project_id'       => (string) $scope_id,  // webchat_sources uses project_id
			'notebook_id'      => $scope_id,            // kept for bridge back-compat
			'user_id'          => $user_id,
			'title'            => $title !== '' ? $title : $this->derive_title( $type, $material ),
			'source_type'      => $type,
			'source_url'       => $source_url,
			'attachment_id'    => $attach_id,
			'content_text'     => $content,
			'content_hash'     => $hash,
			'embedding_model'  => self::EMBED_MODEL,
			'embedding_status' => 'processing',
			'metadata'         => $metadata,
		] );
		if ( $source_id <= 0 ) {
			return new WP_Error( 'insert_failed', 'Failed to insert source row' );
		}

		// Wave 0.6.C — write to kg_sources as primary unified store (unconditional, not flag-gated).
		$kg_source_id      = $this->_upsert_kg_source_row( $source_id, $scope_id, $user_id, $type, $title, $source_url, $attach_id );
		$passage_source_id = $kg_source_id > 0 ? $kg_source_id : $source_id;

		// Chunk + embed.
		// Sprint 4.8c+d (Nexus port) — heading-aware chunker + Jaccard 5-gram dedup.
		$t_chunk = microtime( true );
		$chunk_records = [];
		if ( class_exists( 'BizCity_TwinChat_Chunker' ) ) {
			$raw_records = BizCity_TwinChat_Chunker::chunk( $content, self::CHUNK_CHARS, self::CHUNK_OVERLAP );
			$dedup_res   = BizCity_TwinChat_Chunker::dedup_chunks( $raw_records );
			$chunk_records = $dedup_res['kept'];
			if ( ! empty( $dedup_res['dropped']['noise'] ) || ! empty( $dedup_res['dropped']['duplicate'] ) ) {
				$metadata['chunker'] = [
					'kept'      => count( $chunk_records ),
					'noise'     => (int) $dedup_res['dropped']['noise'],
					'duplicate' => (int) $dedup_res['dropped']['duplicate'],
				];
			}
		} else {
			foreach ( $this->chunk_text( $content ) as $t ) {
				$chunk_records[] = [ 'text' => $t, 'heading_path' => [], 'heading' => '' ];
			}
		}
		$chunks = array_map( static function ( $r ) { return (string) $r['text']; }, $chunk_records );
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'ingest_chunked', [
				'scope_id'   => $scope_id,
				'source_id'  => $source_id,
				'chunks'     => count( $chunks ),
				'elapsed_ms' => (int) round( ( microtime( true ) - $t_chunk ) * 1000 ),
			] );
		}
		$t_embed = microtime( true );
		$embed_result = $this->embed_batch( $chunks, $scope_id, $source_id );
		if ( is_wp_error( $embed_result ) ) {
			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'kg', 'ingest_embed_error', [
					'scope_id' => $scope_id,
					'source_id'=> $source_id,
					'error'    => $embed_result->get_error_message(),
				] );
			}
			$db->update_source( $source_id, [
				'embedding_status' => 'error',
				'error_message'    => $embed_result->get_error_message(),
			] );
			return $embed_result;
		}
		$vectors = $embed_result;
		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'ingest_embedded', [
				'scope_id'   => $scope_id,
				'source_id'  => $source_id,
				'vectors'    => count( $vectors ),
				'elapsed_ms' => (int) round( ( microtime( true ) - $t_embed ) * 1000 ),
			] );
		}

		$chunk_ids   = [];
		$passage_ids = [];
		foreach ( $chunks as $idx => $chunk_text ) {
			$vec  = $vectors[ $idx ] ?? null;
			$tcnt = (int) ceil( mb_strlen( $chunk_text ) / 4 );
			$heading_path = isset( $chunk_records[ $idx ]['heading_path'] ) && is_array( $chunk_records[ $idx ]['heading_path'] )
				? $chunk_records[ $idx ]['heading_path'] : [];
			$heading      = isset( $chunk_records[ $idx ]['heading'] ) ? (string) $chunk_records[ $idx ]['heading'] : '';

			$chunk_id = $db->insert_chunk( [
				'source_id'       => $source_id,
				'notebook_id'     => $scope_id,
				'chunk_index'     => $idx,
				'content'         => $chunk_text,
				'token_count'     => $tcnt,
				'embedding'       => is_array( $vec ) ? $vec : null,
				'embedding_model' => self::EMBED_MODEL,
			] );
			if ( $chunk_id > 0 ) {
				$chunk_ids[] = $chunk_id;
			}

			// Promote into kg_passages.
			$passage_id = $this->promote_chunk_to_passage( [
				'notebook_id'     => $scope_id,
				'scope_type'      => 'notebook',
				'scope_id'        => (string) $scope_id,
				'source_table'    => ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) ? BizCity_KG_Database::instance()->tbl_sources() : $db->table_sources(),
				'source_id'       => $passage_source_id,
				'chunk_id'        => $chunk_id,
				'origin'          => $type . ':' . ( $title ?: $source_url ?: ('source-' . $source_id) ),
				'content'         => $chunk_text,
				'embedding'       => $vec,
				'token_count'     => $tcnt,
				'metadata'        => [
					'plugin'       => 'twinchat',
					'scope_type'   => 'notebook',
					'scope_id'     => $scope_id,
					'source_table' => ( $kg_source_id > 0 && class_exists( 'BizCity_KG_Database' ) ) ? BizCity_KG_Database::instance()->tbl_sources() : $db->table_sources(),
					'source_id'    => $passage_source_id,
					'chunk_index'  => $idx,
					'heading_path' => $heading_path,
					'heading'      => $heading,
				],
			] );
			if ( $passage_id > 0 ) {
				$passage_ids[] = $passage_id;
			}
		}

		$db->update_source( $source_id, [
			'chunk_count'      => count( $chunk_ids ),
			'embedding_status' => 'ready',
		] );

		// Phase 0.6 dual-write — mirror into kg_sources (flag-gated, non-blocking).
		// Wave 0.6.C: skipped when _upsert_kg_source_row() already wrote the row above.
		if ( $kg_source_id <= 0 && class_exists( 'BizCity_KG' ) ) {
			BizCity_KG::ingest_central(
				[
					'plugin'     => 'twinchat',
					'scope_type' => 'notebook',
					'scope_id'   => (string) $scope_id,
				],
				[
					'type'          => $type,
					'title'         => $title,
					'url'           => $source_url,
					'content'       => $content,
					'attachment_id' => $attach_id,
					'user_id'       => $user_id,
				]
			);
		}

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'ingest_done', [
				'scope_id'    => $scope_id,
				'source_id'   => $source_id,
				'chunks'      => count( $chunk_ids ),
				'passages'    => count( $passage_ids ),
				'elapsed_ms'  => (int) round( ( microtime( true ) - $t0 ) * 1000 ),
			] );
		}

		$result = [
			'source_id'    => $source_id,
			'kg_source_id' => $kg_source_id,
			'chunk_count'  => count( $chunk_ids ),
			'passage_ids'  => $passage_ids,
			'duplicate'    => false,
		];

		/**
		 * Phase 4.9 — fired right after a TwinChat source has been ingested
		 * (chunked + embedded). Listeners may enqueue background work like
		 * the learning pipeline (extract → approve → notify).
		 *
		 * @param int   $scope_id  notebook id
		 * @param int   $user_id
		 * @param array $result    { source_id, chunk_count, passage_ids, duplicate }
		 * @param array $payload   the original ingest payload
		 */
		do_action( 'bizcity_twinchat_after_ingest', $scope_id, $user_id, $result, $payload );

		return $result;
	}

	public function list_sources( $scope_id, array $args = [] ) {
		return BizCity_TwinChat_Sources_Database::instance()->list_sources( (int) $scope_id, $args );
	}

	public function get_source( $source_id ) {
		$source_id = (int) $source_id;
		$row = BizCity_TwinChat_Sources_Database::instance()->get_source( $source_id );
		if ( $row ) return $row;

		// Fallback: source may live in another registered Smart Sources table
		// (e.g. bizcity_knowledge_sources from KG-Hub admin upload, or webchat).
		// Build a minimal, viewer-friendly shape so SourceDetailDrawer still renders.
		if ( ! class_exists( 'BizCity_KG_Source_Service' ) ) return null;
		$meta = BizCity_KG_Source_Service::instance()->lookup_source_meta( $source_id );
		if ( ! $meta ) return null;

		// Pull aggregated content from kg_passages so the viewer has something to show.
		// kg_passages schema: id, notebook_id, source_id, content, metadata (JSON) — NO heading_path/page_no columns.
		$content = '';
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$tbl = BizCity_KG_Database::instance()->tbl_passages();
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT content FROM {$tbl} WHERE source_id = %d ORDER BY id ASC LIMIT 200",
				$source_id
			), ARRAY_A );
			if ( is_array( $rows ) ) {
				$parts = [];
				foreach ( $rows as $r ) {
					$parts[] = (string) $r['content'];
				}
				$content = implode( "\n\n", $parts );
			}
		}

		return [
			'id'           => $source_id,
			'title'        => (string) ( $meta['title'] ?? ( 'Source #' . $source_id ) ),
			'source_type'  => (string) ( $meta['source_type'] ?? '' ),
			'source_url'   => (string) ( $meta['source_url'] ?? '' ),
			'content_text' => $content,
			'metadata'     => [ 'origin_table' => $meta['table'] ?? '' ],
		];
	}

	public function delete_source( $source_id ) {
		// Also remove kg_passages linked to this source.
		global $wpdb;
		$source_id = (int) $source_id;
		$src       = BizCity_TwinChat_Sources_Database::instance()->get_source( $source_id );
		if ( ! $src ) return false;

		$project_id = (string) ( $src['project_id'] ?? $src['notebook_id'] ?? 0 );

		// Wave 0.6.C — find the mirrored kg_sources row for cascade deletion.
		$kg_source_id_to_del = 0;
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$tbl_kg              = BizCity_KG_Database::instance()->tbl_sources();
			$kg_source_id_to_del = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl_kg} WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
				$source_id, 'notebook', $project_id
			) );
		}

		// PHASE-0.6.6 — fire BEFORE deleting kg_passages so the cleanup hook can still
		// resolve passage_ids via passages.source_id and cascade-delete passage_entities /
		// passage_relations / triplet_queue rows that point at them. If we delete passages
		// first, the hook sees zero pids → orphan pe/pr/queue rows survive forever.
		do_action( 'bizcity_twinchat_after_source_delete', (int) $source_id, $project_id );

		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$kg = BizCity_KG_Database::instance();
			// Delete passages: new-path (kg_source_id) + legacy-path (webchat_sources.id).
			if ( $kg_source_id_to_del > 0 ) {
				$wpdb->delete( $kg->tbl_passages(), [ 'source_id' => $kg_source_id_to_del ] );
			}
			// project_id stored as string; scope_id in kg_passages is string.
			$wpdb->delete( $kg->tbl_passages(), [
				'scope_id'  => $project_id,
				'source_id' => $source_id,
			] );
			// Delete the mirrored kg_sources row.
			if ( $kg_source_id_to_del > 0 ) {
				$wpdb->delete( $kg->tbl_sources(), [ 'id' => $kg_source_id_to_del ] );
			}
		}
		return BizCity_TwinChat_Sources_Database::instance()->delete_source( $source_id );
	}

	/**
	 * List notebooks the user has access to (acts as scope picker source).
	 */
	public function list_scopes( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) return [];
		$rows = BizCity_KG_Notebook_Service::instance()->list_for_user( $user_id, [ 'limit' => 100 ] );
		$out  = [];
		foreach ( (array) $rows as $r ) {
			if ( empty( $r['id'] ) ) continue;
			$out[] = [
				'id'    => (int) $r['id'],
				'label' => isset( $r['name'] ) ? (string) $r['name'] : ('Notebook #' . $r['id']),
				'meta'  => [
					'color'      => $r['color'] ?? '',
					'updated_at' => $r['updated_at'] ?? '',
				],
			];
		}
		return $out;
	}

	/* ──────────────────────  Internals  ────────────────────── */

	/**
	 * @return array{title:string,content:string,source_url:string,mime?:string}|WP_Error
	 */
	private function materialize_content( $type, array $payload ) {
		$title      = isset( $payload['title'] ) ? sanitize_text_field( (string) $payload['title'] ) : '';
		$source_url = '';
		$content    = '';
		$mime       = '';

		switch ( $type ) {

			case 'file':
				$file = isset( $payload['file'] ) && is_array( $payload['file'] ) ? $payload['file'] : null;
				if ( ! $file || empty( $file['tmp_name'] ) ) {
					// Allow attachment_id alternative.
					$attach = (int) ( $payload['attachment_id'] ?? 0 );
					if ( $attach > 0 ) {
						$path = get_attached_file( $attach );
						if ( ! $path || ! file_exists( $path ) ) {
							return new WP_Error( 'no_file', 'Attachment file not found' );
						}
						$file = [
							'name'     => basename( $path ),
							'tmp_name' => $path,
							'size'     => filesize( $path ),
							'type'     => mime_content_type( $path ) ?: '',
						];
					} else {
						return new WP_Error( 'no_file', 'No file provided' );
					}
				}

				if ( (int) $file['size'] > self::MAX_FILE_BYTES ) {
					return new WP_Error( 'file_too_large', 'File exceeds 5 MB limit' );
				}

				$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
				$mime = (string) ( $file['type'] ?? '' );
				$title = $title !== '' ? $title : $file['name'];

				$content = $this->read_file_content( $file['tmp_name'], $ext );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				break;

			case 'url':
				$url = isset( $payload['url'] ) ? esc_url_raw( (string) $payload['url'] ) : '';
				if ( ! $url ) {
					return new WP_Error( 'no_url', 'No URL provided' );
				}
				$source_url = $url;
				$title      = $title !== '' ? $title : $this->derive_title_from_url( $url );

				if ( ! empty( $payload['content'] ) ) {
					// Caller pre-fetched content (e.g. browser snippet).
					$content = (string) $payload['content'];
				} else {
					$content = $this->fetch_url_text( $url );
					if ( is_wp_error( $content ) ) {
						return $content;
					}
				}
				break;

			case 'text':
			case 'manual':
			default:
				$content = isset( $payload['content'] ) ? (string) $payload['content'] : '';
				$title   = $title !== '' ? $title : $this->derive_title_from_text( $content );
				break;
		}

		$content = $this->normalize_text( $content );
		return [
			'title'      => $title,
			'content'    => $content,
			'source_url' => $source_url,
			'mime'       => $mime,
		];
	}

	private function read_file_content( $path, $ext ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'file_missing', 'Uploaded file missing' );
		}
		if ( in_array( $ext, self::ALLOWED_TEXT_EXT, true ) ) {
			$raw = file_get_contents( $path );
			if ( $raw === false ) {
				return new WP_Error( 'file_read_failed', 'Cannot read file' );
			}
			if ( in_array( $ext, [ 'html', 'htm' ], true ) ) {
				$raw = wp_strip_all_tags( $raw );
			}
			return $raw;
		}

		// PDF/DOCX/etc — try external extractor if present.
		if ( class_exists( 'BizCity_File_Extractor' ) && method_exists( 'BizCity_File_Extractor', 'extract' ) ) {
			$extracted = BizCity_File_Extractor::extract( $path, $ext );
			if ( is_string( $extracted ) && $extracted !== '' ) {
				return $extracted;
			}
		}
		return new WP_Error(
			'unsupported_ext',
			sprintf( 'File type ".%s" not supported yet (allowed: %s)', $ext, implode( ', ', self::ALLOWED_TEXT_EXT ) )
		);
	}

	private function fetch_url_text( $url ) {
		$res = wp_remote_get( $url, [
			'timeout'    => 12,
			'user-agent' => 'BizCityKG/0.5 (+https://bizcity.vn)',
		] );
		if ( is_wp_error( $res ) ) return $res;
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error( 'url_http_' . $code, 'URL fetch returned HTTP ' . $code );
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $body === '' ) {
			return new WP_Error( 'url_empty', 'URL returned empty body' );
		}
		// Strip HTML — tiny extractor; full readability later.
		$body = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body );
		$body = wp_strip_all_tags( $body );
		$body = preg_replace( "/\s+/u", ' ', $body );
		return trim( (string) $body );
	}

	private function normalize_text( $text ) {
		$text = (string) $text;
		// Remove BOM + collapse Windows newlines.
		$text = preg_replace( "/\xEF\xBB\xBF/", '', $text );
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}

	/**
	 * Simple character-window chunker with overlap.
	 *
	 * @return array<int,string>
	 */
	private function chunk_text( $text ) {
		$len = mb_strlen( $text );
		if ( $len === 0 ) return [];
		if ( $len <= self::CHUNK_CHARS ) return [ $text ];

		$out    = [];
		$start  = 0;
		$step   = self::CHUNK_CHARS - self::CHUNK_OVERLAP;
		while ( $start < $len ) {
			$piece = mb_substr( $text, $start, self::CHUNK_CHARS );
			if ( trim( $piece ) !== '' ) {
				$out[] = $piece;
			}
			$start += $step;
		}
		return $out;
	}

	/**
	 * @param array<int,string> $texts
	 * @return array<int,array<int,float>>|WP_Error
	 */
	private function embed_batch( array $texts, $scope_id = 0, $source_id = 0 ) {
		if ( empty( $texts ) ) return [];
		if ( ! function_exists( 'bizcity_openrouter_embeddings' ) ) {
			return new WP_Error( 'no_embedder', 'bizcity_openrouter_embeddings() unavailable' );
		}
		// Embed in batches of 32 to stay under provider limits.
		$out      = [];
		$batches  = array_chunk( $texts, 32 );
		$total_b  = count( $batches );
		foreach ( $batches as $bi => $batch ) {
			$bt0 = microtime( true );
			$res = bizcity_openrouter_embeddings( $batch );
			if ( empty( $res['success'] ) || empty( $res['embeddings'] ) ) {
				$err = isset( $res['error'] ) ? (string) $res['error'] : 'Embedding API failed';
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'kg', 'embed_batch_error', [
						'scope_id'  => (int) $scope_id,
						'source_id' => (int) $source_id,
						'batch_idx' => $bi,
						'batch_n'   => $total_b,
						'error'     => $err,
					] );
				}
				return new WP_Error( 'embed_failed', $err );
			}
			foreach ( $res['embeddings'] as $vec ) {
				$out[] = is_array( $vec ) ? $vec : [];
			}
			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'kg', 'embed_batch_done', [
					'scope_id'   => (int) $scope_id,
					'source_id'  => (int) $source_id,
					'batch_idx'  => $bi + 1,
					'batch_n'    => $total_b,
					'items'      => count( $batch ),
					'elapsed_ms' => (int) round( ( microtime( true ) - $bt0 ) * 1000 ),
				] );
			}
		}
		return $out;
	}

	private function promote_chunk_to_passage( array $args ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return 0;

		$db   = BizCity_KG_Database::instance();
		$hash = md5( (string) $args['content'] );

		// Dedup by notebook + hash.
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()} WHERE notebook_id=%d AND content_hash=%s LIMIT 1",
			(int) $args['notebook_id'],
			$hash
		) );
		if ( $exists ) {
			return $exists;
		}

		$embedding_json = null;
		if ( is_array( $args['embedding'] ) && ! empty( $args['embedding'] ) ) {
			if ( method_exists( 'BizCity_KG_Database', 'encode_embedding' ) ) {
				$embedding_json = BizCity_KG_Database::encode_embedding( $args['embedding'] );
			} else {
				$embedding_json = wp_json_encode( $args['embedding'] );
			}
		}

		$ok = $wpdb->insert( $db->tbl_passages(), [
			'notebook_id'       => (int) $args['notebook_id'],
			'scope_type'        => isset( $args['scope_type'] ) ? (string) $args['scope_type'] : 'notebook',
			'scope_id'          => isset( $args['scope_id'] ) ? (string) $args['scope_id'] : (string) (int) $args['notebook_id'],
			'source_table'      => isset( $args['source_table'] ) ? (string) $args['source_table'] : '',
			'source_id'         => (int) $args['source_id'],
			'chunk_id'          => (int) $args['chunk_id'],
			'origin'            => substr( sanitize_text_field( (string) $args['origin'] ), 0, 100 ),
			'content'           => (string) $args['content'],
			'content_hash'      => $hash,
			'embedding'         => $embedding_json,
			'token_count'       => (int) $args['token_count'],
			// Keep 'pending' so KG triplet extractor can build the Knowledge Graph.
			'extraction_status' => 'pending',
			'metadata'          => wp_json_encode( $args['metadata'] ?? [] ),
		] );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	private function derive_title( $type, $material ) {
		if ( ! empty( $material['title'] ) ) return $material['title'];
		if ( ! empty( $material['source_url'] ) ) return $this->derive_title_from_url( $material['source_url'] );
		return $this->derive_title_from_text( $material['content'] ?? '' );
	}

	private function derive_title_from_url( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return trim( ( $host ?: 'URL' ) . ( $path ? ' ' . trim( $path, '/' ) : '' ) );
	}

	private function derive_title_from_text( $text ) {
		$first = trim( explode( "\n", trim( (string) $text ) )[0] ?? '' );
		if ( $first === '' ) return 'Untitled';
		return mb_substr( $first, 0, 80 );
	}

	/**
	 * Wave 0.6.C — Write a kg_sources row mirroring a newly-ingested webchat source.
	 * Idempotent: returns existing id if origin_id + scope pair already exists.
	 *
	 * @param int    $webchat_id  webchat_sources.id — stored as origin_id for passage lookup.
	 * @param int    $scope_id    notebook id.
	 * @param int    $user_id
	 * @param string $type        source kind (file|url|text|manual).
	 * @param string $title
	 * @param string $source_url
	 * @param int    $attach_id   WP attachment id (may be 0, unused but kept for signature clarity).
	 * @return int  kg_sources.id, or 0 on failure.
	 */
	private function _upsert_kg_source_row( int $webchat_id, int $scope_id, int $user_id, string $type, string $title, string $source_url, int $attach_id ): int {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return 0;

		$tbl = BizCity_KG_Database::instance()->tbl_sources();

		// Idempotent: return existing row if origin_id + scope already mirrored.
		$existing = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE origin_id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
			$webchat_id, 'notebook', (string) $scope_id
		) );
		if ( $existing > 0 ) return $existing;

		$wpdb->insert( $tbl, [
			'uuid'          => wp_generate_uuid4(),
			'blog_id'       => (int) get_current_blog_id(),
			'origin_plugin' => 'twinchat',
			'origin_kind'   => $type,
			'origin_id'     => $webchat_id,
			'title'         => $title,
			'origin_url'    => $source_url ?: null,
			'status'        => 'active',
			'scope_type'    => 'notebook',
			'scope_id'      => (string) $scope_id,
			'user_id'       => $user_id ?: (int) get_current_user_id(),
		] );
		return (int) $wpdb->insert_id;
	}
}
