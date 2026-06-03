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

// PHASE-0.41 L3 — trait is required for the `use` below; load defensively so
// this file works even if core/diagnostics bootstrap hasn't fired yet.
if ( ! trait_exists( 'BizCity_REST_Error' ) ) {
	$__trait = dirname( __DIR__, 3 ) . '/core/diagnostics/includes/trait-rest-error.php';
	if ( file_exists( $__trait ) ) {
		require_once $__trait;
	}
}

class BizCity_TwinChat_Sources_Service {

	// Unified WP_Error builder (status/fix/ctx payload + telemetry recording).
	use BizCity_REST_Error;

	const EMBED_MODEL    = 'text-embedding-3-small';
	const CHUNK_CHARS    = 1500;
	const CHUNK_OVERLAP  = 200;

	/**
	 * Per-modality upload caps. Aligned with downstream adapters so the
	 * pre-flight check is a true gate, not a false-positive blocker.
	 *
	 * 2026-05-20 — bumped text cap from 5 MB → 25 MB so big PDFs and CSV/JSON
	 * dumps work without surprising users; the actual hosting cap is still
	 * enforced by `upload_max_filesize` / `post_max_size` in php.ini.
	 */
	const MAX_FILE_BYTES_TEXT   = 26214400;    // 25 MB — plain text / md / csv / json / html / srt / pdf…
	const MAX_FILE_BYTES_OFFICE = 26214400;    // 25 MB — mirrors BizCity_KG_Office_Adapter::MAX_DOC_BYTES
	const MAX_FILE_BYTES_AV     = 262144000;   // 250 MB — mirrors AV adapter cap (deferred to adapter)

	/** @deprecated Use modality-specific MAX_FILE_BYTES_* constants. Kept for BC. */
	const MAX_FILE_BYTES = self::MAX_FILE_BYTES_TEXT;

	const ALLOWED_TEXT_EXT = [
		'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'log',
		'html', 'htm', 'xml', 'rtf', 'srt', 'vtt',
	];

	private static $instance = null;

	/**
	 * Phase 0.7 / Wave E1 — when an Adapter (PDF/Office/...) processes a file
	 * via read_file_content(), it stashes its full structured payload here so
	 * downstream materialize/persist steps can pick up segments + meta without
	 * a wider refactor of the legacy string-only contract.
	 *
	 * Shape: see BizCity_KG_Source_Adapter::extract() return spec.
	 *
	 * @var array|null
	 */
	private $last_adapter_payload = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	protected function rest_error_module(): string {
		return 'twinchat.sources';
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
			return $this->err_validation( 'invalid_scope', 'Thiếu notebook_id — vui lòng chọn notebook trước khi thêm nguồn.' );
		}
		$type = isset( $payload['type'] ) ? sanitize_key( $payload['type'] ) : 'text';
		$type = in_array( $type, [ 'file', 'url', 'text', 'manual', 'youtube' ], true ) ? $type : 'text';

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
			return $this->err_validation( 'empty_content', 'Nguồn này không đọc được nội dung nào — file có thể rỗng, bị mã hoá, hoặc ở định dạng chưa hỗ trợ.', [ 'type' => $type ] );
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
			global $wpdb;
			$db_err = isset( $wpdb ) ? (string) $wpdb->last_error : '';
			// MySQL 1146 = table doesn't exist → promote to table_missing (auto-repair).
			if ( $db_err !== '' && preg_match( '/1146|doesn.?t exist|no such table/i', $db_err ) ) {
				return $this->err_table_missing( $wpdb->prefix . 'bizcity_webchat_sources', 'twinchat.sources' );
			}
			return $this->err_server( 'insert_failed', 'Không lưu được nguồn vào database — đã ghi nhận lỗi để team xử lý.', [ 'scope_id' => $scope_id, 'type' => $type, 'db_error' => $db_err ] );
		}

		// Wave 0.6.C — write to kg_sources as primary unified store (unconditional, not flag-gated).
		$kg_source_id      = $this->_upsert_kg_source_row( $source_id, $scope_id, $user_id, $type, $title, $source_url, $attach_id );
		$passage_source_id = $kg_source_id > 0 ? $kg_source_id : $source_id;

		// Chunk + embed.
		// Sprint 4.8c+d (Nexus port) — heading-aware chunker + Jaccard 5-gram dedup.
		// Phase 0.7 / Sprint D — AV adapter pre-chunked segments take precedence
		// so per-passage start_ts/end_ts/speaker survive into retrieval metadata.
		$t_chunk = microtime( true );
		$chunk_records = [];
		$av_segments   = null;
		if ( is_array( $this->last_adapter_payload )
			&& ! empty( $this->last_adapter_payload['segments'] )
			&& isset( $this->last_adapter_payload['meta']['chunker'] )
			&& $this->last_adapter_payload['meta']['chunker'] === 'av_temporal_v1'
		) {
			$av_segments = $this->last_adapter_payload['segments'];
		}
		if ( is_array( $av_segments ) ) {
			foreach ( $av_segments as $seg ) {
				$seg_text = isset( $seg['text'] ) ? trim( (string) $seg['text'] ) : '';
				if ( $seg_text === '' ) continue;
				$chunk_records[] = [
					'text'         => $seg_text,
					'heading_path' => [],
					'heading'      => '',
					'av'           => [
						'start_ts' => isset( $seg['start_ts'] ) ? (int) $seg['start_ts'] : 0,
						'end_ts'   => isset( $seg['end_ts'] )   ? (int) $seg['end_ts']   : 0,
						'speaker'  => isset( $seg['speaker'] )  ? $seg['speaker']        : null,
						'is_scene' => ! empty( $seg['is_scene'] ),
					],
				];
			}
			$metadata['chunker'] = [
				'kept'    => count( $chunk_records ),
				'engine'  => 'av_temporal_v1',
			];
		} elseif ( class_exists( 'BizCity_TwinChat_Chunker' ) ) {
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
			$av_meta      = isset( $chunk_records[ $idx ]['av'] ) && is_array( $chunk_records[ $idx ]['av'] )
				? $chunk_records[ $idx ]['av'] : null;

			$chunk_id = $db->insert_chunk( [
				'source_id'       => $source_id,
				'kg_source_id'    => $kg_source_id, // Wave 0.21.2 — link unified chunks to kg_sources.id
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
				] + ( $av_meta ? [
					'start_ts' => (int) $av_meta['start_ts'],
					'end_ts'   => (int) $av_meta['end_ts'],
					'speaker'  => $av_meta['speaker'],
					'is_scene' => (bool) $av_meta['is_scene'],
					'chunker'  => 'av_temporal_v1',
				] : [] ),
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
		// Source removal cascaded passages → stats counts (passages + entities/relations
		// touched by the cleanup hook) drifted. Drop the per-notebook stats cache.
		$nb_for_invalidation = (int) ( $src['notebook_id'] ?? $project_id );
		if ( $nb_for_invalidation > 0 ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', $nb_for_invalidation );
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

				$ext_check  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
				$mime_check = strtolower( (string) ( $file['type'] ?? '' ) );
				$cap        = $this->resolve_max_file_bytes( $ext_check, $mime_check );
				if ( (int) $file['size'] > $cap['bytes'] ) {
					// AV files are deferred to the AV adapter (its own 250 MB cap).
					// All other modalities (text 5 MB, office 25 MB) are hard-stopped here.
					if ( $cap['modality'] !== 'av' ) {
						return new WP_Error(
							$cap['modality'] === 'office' ? 'office_file_too_large' : 'file_too_large',
							sprintf( 'File exceeds %d MB limit for %s uploads.', (int) round( $cap['bytes'] / 1048576 ), $cap['modality'] ),
							[ 'status' => 413, 'modality' => $cap['modality'], 'max_bytes' => $cap['bytes'] ]
						);
					}
				}

				$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
				$mime = (string) ( $file['type'] ?? '' );
				$title = $title !== '' ? $title : $file['name'];

				$content = $this->read_file_content( $file['tmp_name'], $ext, [
					'filename'      => $file['name'],
					'mime'          => $mime,
					'attachment_id' => (int) ( $payload['attachment_id'] ?? 0 ),
					'user_id'       => (int) ( $payload['user_id'] ?? get_current_user_id() ),
				] );
				if ( is_wp_error( $content ) ) {
					return $content;
				}
				break;

			case 'youtube':
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
					// Phase 0.7 / Wave E0.YT — detect YouTube URL and route through caption transcriber.
					if ( class_exists( 'BizCity_Youtube_Transcriber' )
					     && BizCity_Youtube_Transcriber::is_youtube_url( $url ) ) {
						$transcriber = BizCity_Youtube_Transcriber::instance();
						// Sprint C — prefer fetch_with_av_fallback when available so videos
						// without captions transparently fall back to AV transcribe.
						$method = method_exists( $transcriber, 'fetch_with_av_fallback' )
							? 'fetch_with_av_fallback'
							: 'fetch';
						$yt = $transcriber->{$method}( $url, [
							'lang'              => isset( $payload['lang'] ) ? (string) $payload['lang'] : '',
							'allow_av_fallback' => true,
							'user_id'           => get_current_user_id(),
						] );
						if ( is_wp_error( $yt ) ) {
							return $yt;
						}
						$content = (string) ( $yt['text'] ?? '' );
						if ( ! empty( $yt['title'] ) && $title === $this->derive_title_from_url( $url ) ) {
							$title = (string) $yt['title'];
						}
						$mime = ( ( $yt['modality'] ?? '' ) === 'youtube_av_fallback' )
							? 'text/youtube-av-transcript'
							: 'text/youtube-transcript';
					} else {
						$content = $this->fetch_url_text( $url );
						if ( is_wp_error( $content ) ) {
							return $content;
						}
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

	/**
	 * Resolve the per-modality upload cap for a file based on its extension/mime.
	 *
	 * Modalities and caps:
	 *   - 'av'     → 250 MB (deferred to AV adapter — caller usually skips the hard stop)
	 *   - 'office' → 25 MB  (mirrors BizCity_KG_Office_Adapter::MAX_DOC_BYTES)
	 *   - 'text'   → 5 MB   (default for txt/md/csv/json/html/srt/pdf/...)
	 *
	 * Filterable via 'twinchat_sources_max_file_bytes' so site admins can override
	 * (e.g. raise office cap to 50 MB on enterprise tier).
	 *
	 * @param string $ext  Lowercased extension without the dot.
	 * @param string $mime Lowercased MIME type, may be empty.
	 * @return array{ modality: string, bytes: int }
	 */
	private function resolve_max_file_bytes( $ext, $mime ) {
		$ext  = (string) $ext;
		$mime = (string) $mime;

		$av_exts = [
			'mp3','wav','m4a','aac','ogg','oga','opus','flac','3gp','amr',
			'mp4','mov','m4v','webm','mkv','avi','mpeg','mpg','3gpp',
		];
		$office_exts = [
			'doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','rtf',
		];

		if ( strpos( $mime, 'audio/' ) === 0 || strpos( $mime, 'video/' ) === 0 || in_array( $ext, $av_exts, true ) ) {
			$out = [ 'modality' => 'av', 'bytes' => self::MAX_FILE_BYTES_AV ];
		} elseif ( in_array( $ext, $office_exts, true ) || strpos( $mime, 'application/vnd.openxmlformats' ) === 0 || strpos( $mime, 'application/vnd.oasis' ) === 0 || $mime === 'application/msword' ) {
			$out = [ 'modality' => 'office', 'bytes' => self::MAX_FILE_BYTES_OFFICE ];
		} else {
			$out = [ 'modality' => 'text', 'bytes' => self::MAX_FILE_BYTES_TEXT ];
		}

		/**
		 * Filter the resolved upload cap.
		 *
		 * @param array  $out  [ 'modality' => string, 'bytes' => int ]
		 * @param string $ext  File extension (no dot).
		 * @param string $mime MIME type.
		 */
		$filtered = apply_filters( 'twinchat_sources_max_file_bytes', $out, $ext, $mime );
		if ( is_array( $filtered ) && isset( $filtered['bytes'], $filtered['modality'] ) ) {
			$out = [ 'modality' => (string) $filtered['modality'], 'bytes' => max( 1, (int) $filtered['bytes'] ) ];
		}
		return $out;
	}

	private function read_file_content( $path, $ext, array $opts = [] ) {
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

		// PDF/DOCX/Audio/Video/etc — delegate to KG-Hub Adapter Registry (Phase 0.7 / Wave E1, E0.AV).
		// Adapters return a structured array; we keep the legacy string contract
		// here for backward compat and stash the structured payload on the
		// instance so the caller can pick up segments/meta without a refactor.
		if ( class_exists( 'BizCity_KG_Adapter_Registry' ) ) {
			$mime    = isset( $opts['mime'] ) && $opts['mime'] !== ''
				? (string) $opts['mime']
				: ( function_exists( 'mime_content_type' ) ? @mime_content_type( $path ) : '' );
			$adapter = BizCity_KG_Adapter_Registry::instance()->resolve( strtolower( $ext ), (string) $mime );
			if ( $adapter ) {
				$adapter_opts = array_merge( [ 'ext' => $ext, 'mime' => $mime ], $opts );
				$result = $adapter->extract( $path, $adapter_opts );
				if ( is_wp_error( $result ) ) {
					return $result; // already structured (e.g. pdf_extract_empty, av_no_speech, tier_required)
				}
				if ( is_array( $result ) && isset( $result['text'] ) && $result['text'] !== '' ) {
					$this->last_adapter_payload = $result;
					return (string) $result['text'];
				}
				return new WP_Error( 'adapter_empty', 'Adapter returned no text', [ 'http_status' => 422 ] );
			}
		}

		// Legacy stub — left in place for future wiring of niche extractors.
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
		// 2026-05-20 — hardened: longer timeout, follow redirects, gzip,
		// graceful fallback when upstream SSL cert is broken (common on VN sites).
		$base_args = [
			'timeout'     => 30,
			'redirection' => 5,
			'decompress'  => true,
			'user-agent'  => 'Mozilla/5.0 (compatible; BizCityKG/0.7; +https://bizcity.vn)',
			'headers'     => [
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'vi,en;q=0.8',
			],
		];
		$res = wp_remote_get( $url, $base_args );
		if ( is_wp_error( $res ) ) {
			// Retry once with SSL verification disabled — some Vietnamese sites
			// have expired/mis-chained certs that wp_http rejects by default.
			$code = $res->get_error_code();
			if ( strpos( (string) $code, 'http' ) !== false || $code === 'http_request_failed' ) {
				$res = wp_remote_get( $url, $base_args + [ 'sslverify' => false ] );
			}
		}
		if ( is_wp_error( $res ) ) {
			return new WP_Error(
				'url_fetch_failed',
				'Could not fetch URL: ' . $res->get_error_message(),
				[ 'http_status' => 502, 'url' => $url ]
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'url_http_' . $code,
				sprintf( 'URL fetch returned HTTP %d', $code ),
				[ 'http_status' => $code >= 500 ? 502 : 422, 'url' => $url ]
			);
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $body === '' ) {
			return new WP_Error( 'url_empty', 'URL returned empty body', [ 'http_status' => 422, 'url' => $url ] );
		}
		// Strip HTML — tiny extractor; full readability later.
		$body = preg_replace( '#<(script|style|noscript|template)\b[^>]*>.*?</\1>#is', ' ', $body );
		$body = wp_strip_all_tags( $body );
		$body = preg_replace( "/\s+/u", ' ', $body );
		$body = trim( (string) $body );
		if ( $body === '' ) {
			return new WP_Error( 'url_empty_text', 'URL had no extractable text', [ 'http_status' => 422, 'url' => $url ] );
		}
		// 2026-05-21 R-LEARN E? — surface "thin body" cases so we can debug why
		// some sites (e.g. JS-rendered SPA, soft-paywall) only yield ~nav text.
		// Stage 1 trace requirement (§5 of PHASE-0-RULE-LEARNING-PIPELINE).
		if ( class_exists( 'BizCity_Twin_Debug' ) && mb_strlen( $body ) < 400 ) {
			BizCity_Twin_Debug::trace( 'kg', 'ingest_url_thin', [
				'url'        => $url,
				'body_bytes' => mb_strlen( $body ),
				'preview'    => mb_substr( $body, 0, 120 ),
			] );
		}
		return $body;
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
		// 2026-05-20 — bumped batch 32 → 96. OpenAI / OpenRouter embedding
		// endpoints accept up to 2048 inputs per call; 96 trims request count
		// to ~1/3 for typical sources without bumping payload past ~150 KB.
		// Filterable so admins can tune per provider.
		$batch_size = (int) apply_filters( 'bizcity_kg_embed_batch_size', 96 );
		if ( $batch_size < 1 ) { $batch_size = 1; }
		$out      = [];
		$batches  = array_chunk( $texts, $batch_size );
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
			// Filestore-only (Rule v2.0): NO JSON encode for kg_passages.embedding column.
			// Keep $embedding_json as null; vector flows directly into .bin via register_chunk below.
			$embedding_json = null;
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
			'embedding'         => null,
			'token_count'       => (int) $args['token_count'],
			// Keep 'pending' so KG triplet extractor can build the Knowledge Graph.
			'extraction_status' => 'pending',
			'metadata'          => wp_json_encode( $args['metadata'] ?? [] ),
		] );
		$pid = $ok ? (int) $wpdb->insert_id : 0;

		// PHASE-0.21 Wave 2 — dual-write embedding into .bin file store.
		if ( $pid && is_array( $args['embedding'] ) && ! empty( $args['embedding'] ) && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
			BizCity_KG_Embedding_Writer::instance()->register_chunk(
				(int) $args['notebook_id'],
				$pid,
				$args['embedding'],
				null,
				(int) $args['source_id']
			);
		}

		// PHASE-0.7-LEARN-VECTOR-FILE Wave F1 — dual-write passage body to
		// MD shard file so new sources land in filestore immediately when
		// `bizcity_kg_filestore_dual_write` option is ON. Without this hook,
		// only embeddings hit .bin and the body stays in MySQL forever.
		if ( $pid && (int) $args['notebook_id'] > 0 && class_exists( 'BizCity_KG_Filestore_Dispatcher' ) ) {
			BizCity_KG_Filestore_Dispatcher::instance()->after_passage_insert(
				$pid,
				(int) $args['notebook_id'],
				(string) $args['content']
			);
		}

		// Passages count on the notebook just changed — flush KG stats cache so the
		// brain workspace shows fresh numbers without waiting for the 5-min TTL.
		if ( $pid && (int) $args['notebook_id'] > 0 ) {
			do_action( 'bizcity_kg_notebook_stats_dirty', (int) $args['notebook_id'] );
		}

		return $pid;
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
