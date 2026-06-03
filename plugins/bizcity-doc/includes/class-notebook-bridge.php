<?php
/**
 * Notebook Bridge — Register Doc Studio tools with Companion Notebook.
 *
 * Registers 4 tools into BCN_Notebook_Tool_Registry:
 *   doc_document      → DOCX/PDF document
 *   doc_presentation  → PPTX presentation
 *   doc_spreadsheet   → XLSX spreadsheet
 *   mindmap           → Interactive mindmap JSON schema
 *
 * Each tool receives the standard skeleton JSON from Notebook Studio,
 * converts it into a topic string, and delegates to the Doc Studio
 * generation pipeline (same quality as standalone mode).
 *
 * @package    BizCity_Doc
 * @since      0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Notebook_Bridge {

	/**
	 * Tool definitions.
	 */
	private static $tools = [
		'doc_document' => [
			'label'       => 'Tài liệu Word',
			'description' => 'Tạo tài liệu Word/PDF chuyên nghiệp từ nguồn dự án',
			'icon'        => '📄',
			'color'       => 'blue',
			'doc_type'    => 'document',
		],
		'doc_presentation' => [
			'label'       => 'Slide trình bày',
			'description' => 'Tạo bài thuyết trình PowerPoint từ nguồn dự án',
			'icon'        => '📊',
			'color'       => 'purple',
			'doc_type'    => 'presentation',
		],
		'doc_spreadsheet' => [
			'label'       => 'Bảng tính Excel',
			'description' => 'Tạo bảng tính Excel từ nguồn dự án',
			'icon'        => '📋',
			'color'       => 'green',
			'doc_type'    => 'spreadsheet',
		],
		'mindmap' => [
			'label'       => 'Sơ đồ tư duy',
			'description' => 'Tạo mindmap tương tác từ Graph-RAG entities và nguồn dự án',
			'icon'        => '🧠',
			'color'       => 'green',
			'doc_type'    => 'mindmap',
		],
	];

	/**
	 * Register all Doc Studio tools with the Notebook Tool Registry (legacy path).
	 */
	public static function register( $registry ) {
		foreach ( self::$tools as $type => $def ) {
			$doc_type = $def['doc_type'];

			$registry->add( [
				'type'        => $type,
				'label'       => $def['label'],
				'description' => $def['description'],
				'icon'        => $def['icon'],
				'color'       => $def['color'],
				'category'    => 'document',
				'mode'        => 'delegate',
				'available'   => true,
				'callback'    => function ( array $skeleton ) use ( $doc_type ) {
					return self::generate_from_skeleton( $skeleton, $doc_type );
				},
			] );
		}
	}

	/**
	 * Register bridges with BizCity_Studio_Job_Manager.
	 *
	 * bzdoc bridge mode = 'async': skeleton building (LLM) happens in worker;
	 * the bridge fn itself (project create + source stamp) runs fast (<1s)
	 * but is called from inside the worker after skeleton is ready.
	 *
	 * Plugin async completion (future): when bzdoc finishes generating the
	 * actual document content, it can call:
	 *   do_action( 'bizcity_studio_job_complete', $job_id, $result, $skeleton );
	 * to update the studio_outputs row with the real content for re-learning.
	 */
	public static function register_job_bridges(): void {
		if ( ! class_exists( 'BizCity_Studio_Job_Manager' ) ) return;

		foreach ( self::$tools as $type => $def ) {
			$doc_type = $def['doc_type'];

			BizCity_Studio_Job_Manager::register_bridge(
				$type,
				static function ( array $job, array $skeleton ) use ( $doc_type ): array {
					$result = self::generate_from_skeleton( $skeleton, $doc_type );
					if ( is_wp_error( $result ) ) return $result;
					return (array) $result;
				},
				'async'  // skeleton is built by worker; bridge fn is fast
			);
		}
	}

	/**
	 * Hybrid Bridge: Notebook → Doc Studio (Plan A — handoff only).
	 *
	 * Flow (no LLM here — bzdoc UI tự generate khi user mở):
	 *   1. Tạo Doc Studio project (blank row)
	 *   2. Transfer sources (clone embeddings từ notebook)
	 *   3. Lưu autogen payload (topic + source context + theme/template) vào
	 *      schema_json._autogen → bzdoc FE đọc & pre-fill prompt khi load.
	 *   4. Trả về URL `/tool-doc/?id={doc_id}&autogen=1` cho TwinChat.
	 *
	 * Tại sao bỏ step 2 (LLM):
	 *   - LLM call ~60–180s sống chung process với TwinChat shutdown worker
	 *     thường xuyên 524 (Cloudflare 100s) hoặc 502 (Anthropic outage).
	 *   - Đẩy LLM về trang bzdoc → user thấy progress, có nút retry, edit prompt
	 *     trước khi generate, dùng UI gốc của bzdoc (history/restore version).
	 *   - TwinChat row instant ready trong <1s, không treo "Đang tạo…".
	 *
	 * @param array  $skeleton  Standard skeleton JSON from BCN_Studio_Input_Builder.
	 * @param string $doc_type  document | presentation | spreadsheet | mindmap
	 * @return array|WP_Error
	 */
	private static function generate_from_skeleton( array $skeleton, string $doc_type ) {
		return self::generate_from_skeleton_public( $skeleton, $doc_type );
	}

	/**
	 * Vòng 4.5.5d — Public entry point so external agents (e.g. TwinShell mindmap
	 * agent) can use the same instant "blank doc + autogen URL" handoff. The
	 * private wrapper above stays for backward-compat with notebook bridges.
	 *
	 * @param array  $skeleton  Standard skeleton JSON.
	 * @param string $doc_type  document | presentation | spreadsheet | mindmap
	 * @return array|WP_Error
	 */
	public static function generate_from_skeleton_public( array $skeleton, string $doc_type ) {
		$topic_parts  = self::skeleton_to_topic( $skeleton, $doc_type );
		$topic        = $topic_parts['topic'] ?? '';
		$source_text  = $topic_parts['source_text'] ?? '';

		if ( empty( trim( $topic ) ) && empty( trim( $source_text ) ) ) {
			return new \WP_Error( 'no_content', 'Không có nội dung để tạo tài liệu.' );
		}

		/* ── Step 1: Create Doc Studio project + transfer sources ── */
		$doc_id   = self::create_doc_project( $skeleton, $doc_type );
		$raw_text = $skeleton['_raw_text'] ?? '';

		if ( is_wp_error( $doc_id ) || ! $doc_id ) {
			return is_wp_error( $doc_id )
				? $doc_id
				: new \WP_Error( 'create_failed', 'Không tạo được doc project.' );
		}

		self::transfer_sources( $doc_id, $skeleton, $raw_text );

		/* ── Step 2: Lưu autogen payload vào schema_json (bzdoc FE đọc khi load) ── */
		$template = self::guess_template( $skeleton, $doc_type );
		$theme    = 'modern';
		$title    = $skeleton['nucleus']['title'] ?? '';
		if ( empty( $title ) ) {
			$title = self::extract_title( [], $doc_type );
		}

		// Wave 7 (PHASE-6.1 §8.3) — resolve notebook id from skeleton.project_id ("tc_<id>").
		$project_id  = (string) ( $skeleton['project_id'] ?? '' );
		$notebook_id = 0;
		if ( $project_id !== '' && preg_match( '/^tc_(\d+)$/', $project_id, $m ) ) {
			$notebook_id = (int) $m[1];
		}

		// Wave 7 — load pinned memory notes for prompt injection.
		$pinned_notes = self::load_pinned_notes( $project_id );

		// Vòng 4.5.5e (Rule 8g v2 — 2026-05-02) — also pull recent webchat
		// turns of the SAME notebook so the autogen prompt is personalized
		// to the conversation that led to the agent dispatch. Without this
		// the iframe just sees the literal topic string and loses every
		// piece of context the user already shared in chat.
		$recent_chat = self::load_recent_webchat( $notebook_id );

		// Wave 7 — outline block from skeleton (replaces source-clone of "Dàn ý").
		$outline_block = self::skeleton_to_structured_source( $skeleton );

		// Append recent chat under the outline so summarize_autogen_payload
		// folds it into the LLM compaction step automatically.
		if ( $recent_chat !== '' ) {
			$outline_block = trim( $outline_block . "\n\n=== Hội thoại gần đây trong notebook ===\n" . $recent_chat );
		}

		// HOTFIX (2026-05-06) — MAX_TOPIC was raised to 50_000 chars (mb_strlen)
		// so we relax the in-bridge caps too. Outline default 4000 chars, and
		// only fall back to the LLM compaction when the combined payload is
		// genuinely huge (> 16k chars). Previous 1000/2000 caps silently truncated
		// notebook outlines and triggered an extra LLM round-trip that itself
		// often timed out (524) — manifesting as "prompt dài quá → không gen”.
		$max_outline = (int) apply_filters( 'bizcity_bzdoc_outline_block_chars', 4000 );
		if ( mb_strlen( $outline_block ) > $max_outline ) {
			$outline_block = mb_substr( $outline_block, 0, $max_outline - 1 ) . '…';
		}

		// Compact via LLM ONLY if combined payload would still risk overflow
		// (> ~16000 chars raw; comfortably under MAX_TOPIC = 50000 once headers/
		// topic added).
		$combined_len = mb_strlen( $outline_block );
		foreach ( $pinned_notes as $n ) {
			$combined_len += mb_strlen( (string) ( $n['content'] ?? '' ) ) + mb_strlen( (string) ( $n['title'] ?? '' ) );
		}
		if ( $combined_len > 16000 ) {
			$summary = self::summarize_autogen_payload( $outline_block, $pinned_notes, $topic );
			if ( $summary !== '' ) {
				$outline_block = $summary;
				$pinned_notes  = []; // already folded into summary
			}
		}

		// Wave 2 kickstart flag (PHASE-6.1 §8.4) — set by Studio orchestrator.
		// PHASE-6.4 BUG-FIX (May 2026) — for image doc_type, default-ON because
		// the ONLY callers of this code path are (a) Image Studio form submit
		// and (b) the `generate_image` agent tool — both want the bzdoc image
		// iframe to auto-fire on load. Without this default the iframe lands
		// with a prefilled prompt but stuck waiting for a manual click. The
		// `_kickstart` flag is still respected for non-image flows where the
		// orchestrator may legitimately want to disable autorun.
		$kickstart = ! empty( $skeleton['_kickstart'] );
		if ( $doc_type === 'image' && ! isset( $skeleton['_kickstart'] ) ) {
			$kickstart = true;
		}

		// PHASE-6.4 Wave C5 (May 2026) — split-two flag from FE doc_opts.
		// When true, bzdoc /generate/stream runs the section loop in 2 batches
		// and flushes Part 1 to the iframe before Part 2 starts.
		$split_two = false;
		if ( isset( $skeleton['doc_opts'] ) && is_array( $skeleton['doc_opts'] ) ) {
			$split_two = ! empty( $skeleton['doc_opts']['split_two'] );
		}

		// PHASE-6.4 Wave C6 (May 2026) — parallel_batches (2 or 3).
		$parallel_batches = 0;
		if ( isset( $skeleton['doc_opts'] ) && is_array( $skeleton['doc_opts'] ) ) {
			$parallel_batches = absint( $skeleton['doc_opts']['parallel_batches'] ?? 0 );
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bzdoc_documents';

		// PHASE-6.4 BUG-FIX (May 2026) — `bzdoc_documents.title` is VARCHAR(255).
		// Image / agent flows now pass verbatim user prompts (multi-line, often
		// 500+ chars) as nucleus.title. Without sanitization+truncation here the
		// row's title column rejects the value AND because wpdb->update is atomic
		// the `schema_json` write is rolled back too — that is exactly why row 95
		// & 96 in bzdoc_documents had `{}` schema and FE saw no autogen prefill
		// nor kickstart. We now mirror handle_project_create's normalization and
		// split the write into two best-effort updates so schema_json is ALWAYS
		// stored even if the title sanitizer chokes on exotic codepoints.
		$safe_title = sanitize_text_field( (string) $title );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$cleaned = @mb_convert_encoding( $safe_title, 'UTF-8', 'UTF-8' );
			if ( is_string( $cleaned ) ) $safe_title = $cleaned;
		}
		if ( function_exists( 'wp_encode_emoji' ) ) {
			$safe_title = wp_encode_emoji( $safe_title );
		}
		$safe_title = preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', $safe_title ) ?? $safe_title;
		$safe_title = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $safe_title ) ?? $safe_title;
		if ( function_exists( 'mb_substr' ) ) {
			$safe_title = mb_substr( $safe_title, 0, 200, 'UTF-8' );
		} else {
			$safe_title = substr( $safe_title, 0, 200 );
		}
		if ( $safe_title === '' ) {
			$safe_title = mb_substr( (string) $title, 0, 80, 'UTF-8' );
			if ( $safe_title === '' ) $safe_title = 'Untitled';
		}

		$autogen_payload = [
			'_autogen' => [
				'topic'            => $topic,
				'source_text'      => $source_text,
				'doc_type'         => $doc_type,
				'template_name'    => $template,
				'theme_name'       => $theme,
				'created_at'       => current_time( 'mysql' ),
				// Wave 7 additions:
				'notebook_id'      => $notebook_id,
				'studio_id'        => (int) $doc_id,
				'outline_block'    => $outline_block,
				'pinned_notes'     => $pinned_notes,
				'kickstart'        => $kickstart,
				// PHASE-6.4 Wave C5 (May 2026) — split-two passthrough.
				'split_two'        => $split_two,
				// PHASE-6.4 Wave C6 (May 2026) — parallel batches passthrough.
				'parallel_batches' => $parallel_batches,
				// Phase 6.4 — image options forwarded for FE pipeline call.
				'image_opts'       => isset( $skeleton['image_opts'] ) && is_array( $skeleton['image_opts'] )
					? $skeleton['image_opts'] : null,
			],
		];

		// (1) Schema first — must always land. Title intentionally OMITTED so a
		// title sanitizer failure can't roll back the autogen payload.
		// PHASE-6.4 BUG-FIX (May 2026) — DROP `JSON_UNESCAPED_UNICODE` so
		// Vietnamese chars are escaped to `\uXXXX`. The schema_json column
		// charset on some installs is `utf8` (3-byte) not `utf8mb4`, and wpdb
		// double-encodes any raw UTF-8 byte sequence it can't validate against
		// the column collation — that's why the DB ended up with mojibake
		// (`Chá»§ Ä‘á»` instead of `Chủ đề`). ASCII-only JSON sidesteps the
		// issue entirely; the FE's `JSON.parse` decodes \uXXXX → original chars.
		$schema_ok = $wpdb->update(
			$tbl,
			[
				'template_name' => $template,
				'theme_name'    => $theme,
				'notebook_id'   => $notebook_id,
				'schema_json'   => wp_json_encode( $autogen_payload ),
				'updated_at'    => current_time( 'mysql' ),
			],
			[ 'id' => $doc_id ]
		);
		if ( $schema_ok === false ) {
			error_log( sprintf(
				'[BZDoc][bridge] schema_json update FAILED doc_id=%d err=%s',
				(int) $doc_id, $wpdb->last_error ?: '(empty)'
			) );
		}

		// (2) Title best-effort. If wpdb rejects (cryptic invalid-data), retry
		// with ASCII-only fallback so the doc-list label degrades gracefully.
		$title_ok = $wpdb->update(
			$tbl,
			[ 'title' => $safe_title ],
			[ 'id' => $doc_id ]
		);
		if ( $title_ok === false ) {
			$ascii = preg_replace( '/[^A-Za-z0-9 _\-.,!?]/', '', $safe_title );
			$ascii = trim( substr( $ascii ?: '', 0, 100 ) );
			if ( $ascii === '' ) $ascii = ucfirst( $doc_type ) . ' ' . gmdate( 'Y-m-d H:i' );
			$wpdb->update( $tbl, [ 'title' => $ascii ], [ 'id' => $doc_id ] );
		}

		// Rule 8g v2 (2026-05-15) — federation key moved off `kg_sources.studio_id`
		// onto `kg_notebooks.artifacts_json`. The central helper owns the write now;
		// no per-source UPDATE needed here. Keeping the call site explicit so the
		// notebook-bridge upserts the doc into the federation map even when it
		// wasn't created via the agent path.
		if ( $notebook_id > 0 && class_exists( 'BizCity_Artifact_Source_Federation' ) ) {
			$edit_url = home_url( '/tool-doc/?id=' . $doc_id );
			BizCity_Artifact_Source_Federation::stamp(
				'bizcity-doc',
				(int) $doc_id,
				(int) $notebook_id,
				(string) $title,
				$edit_url
			);
		}

		/* ── Step 3: Build handoff URL (kickstart flag piggy-backs as ?kickstart=1) ── */
		$qs = [ 'id' => $doc_id, 'autogen' => 1 ];
		if ( $kickstart ) $qs['kickstart'] = 1;
		$url = home_url( '/tool-doc/?' . http_build_query( $qs ) );

		return [
			'content'        => wp_json_encode( [ 'doc_id' => $doc_id, 'autogen' => true ] ),
			'content_format' => 'json',
			'title'          => $title,
			'data'           => [
				'doc_id'   => $doc_id,
				'doc_type' => $doc_type,
				'url'      => $url,
			],
		];
	}

	/**
	 * Create a blank Doc Studio project to hold the generated document.
	 *
	 * @param array  $skeleton  Skeleton JSON (for title extraction).
	 * @param string $doc_type  document | presentation | spreadsheet
	 * @return int|WP_Error  Doc ID on success.
	 */
	private static function create_doc_project( array $skeleton, string $doc_type ) {
		$title = $skeleton['nucleus']['title'] ?? '';
		if ( empty( $title ) ) {
			$type_labels = [
				'document'     => 'Tài liệu',
				'presentation' => 'Thuyết trình',
				'spreadsheet'  => 'Bảng tính',
				'mindmap'      => 'Sơ đồ tư duy',
				'image'        => 'Ảnh AI',
			];
			$title = ( $type_labels[ $doc_type ] ?? 'Tài liệu' ) . ' từ Notebook';
		}

		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'doc_type', $doc_type );
		$request->set_param( 'title', $title );

		$result = BZDoc_Rest_API::handle_project_create( $request );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$data = $result->get_data();
		return $data['doc_id'] ?? 0;
	}

	/**
	 * Transfer Notebook sources into the Doc Studio project.
	 *
	 * Strategy: Clone existing sources + embeddings from notebook tables into Doc Studio tables.
	 * This avoids re-embedding — user gets immediate RAG support in Doc Studio.
	 *
	 * Clone order:
	 *   1. bizcity_rces (BCN canonical) → bzdoc_project_sources + bzdoc_project_source_chunks
	 *   2. bizcity_webchat_sources (research-imported) → bzdoc_project_sources + bzdoc_project_source_chunks
	 *   3. Skeleton JSON → bzdoc_project_sources as a "package" text source
	 *
	 * @param int    $doc_id    Doc Studio project ID.
	 * @param array  $skeleton  Skeleton JSON.
	 * @param string $raw_text  Raw concatenated source text (legacy fallback).
	 */
	private static function transfer_sources( int $doc_id, array $skeleton, string $raw_text ): void {
		global $wpdb;

		$project_id = $skeleton['project_id'] ?? '';
		$user_id    = get_current_user_id();
		$doc_table  = $wpdb->prefix . 'bzdoc_project_sources';
		$doc_chunks = $wpdb->prefix . 'bzdoc_project_source_chunks';
		$cloned     = 0;
		$chunks_cloned = 0;

		// Wave 7 hotfix (2026-04-29) — DEFAULT-OFF the legacy clone path.
		// Tại sao: clone vào bzdoc_project_sources với `embedding_status='pending'`
		// → bzdoc UI re-embed → toast "Đang phân tích nguồn 0/N" + double Graph-RAG cost.
		// Giờ bzdoc đọc trực tiếp federated `kg_sources` qua route
		// `/bizcity-doc/v1/document/{id}/kg-sources` (Wave 7 §8.3) — không cần clone.
		// Ops có thể bật lại bằng filter nếu cần fallback cho version cũ của bzdoc UI.
		$legacy_clone = (bool) apply_filters( 'bizcity_bzdoc_studio_legacy_clone_sources', false );
		if ( ! $legacy_clone ) {
			error_log( sprintf( '[BZDoc][Wave7] transfer_sources: SKIP legacy clone (federation via studio_id) doc_id=%d project_id=%s',
				$doc_id, (string) $project_id ) );
			return;
		}

		/* ── Step 1: Clone from bizcity_rces (BCN canonical sources with embeddings) ── */
		if ( $project_id && class_exists( 'BCN_Schema_Extend' ) ) {
			$bcn_table  = BCN_Schema_Extend::table_sources();
			$bcn_chunks = BCN_Schema_Extend::table_source_chunks();

			$bcn_sources = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, title, source_type, source_url, content_text, content_hash,
				        char_count, token_estimate, chunk_count, embedding_model, embedding_status
				 FROM {$bcn_table}
				 WHERE project_id = %s AND status = 'ready'
				 ORDER BY created_at ASC",
				$project_id
			) );

			foreach ( $bcn_sources ?: [] as $src ) {
				$wpdb->insert( $doc_table, [
					'doc_id'           => $doc_id,
					'user_id'          => $user_id,
					'title'            => $src->title,
					'source_type'      => $src->source_type ?: 'text',
					'source_url'       => $src->source_url ?: '',
					'content_text'     => $src->content_text,
					'content_hash'     => $src->content_hash ?: '',
					'char_count'       => (int) $src->char_count,
					'token_estimate'   => (int) $src->token_estimate,
					'chunk_count'      => (int) $src->chunk_count,
					'embedding_model'  => $src->embedding_model ?: '',
					'embedding_status' => $src->embedding_status === 'done' ? 'done' : 'pending',
					'status'           => 'ready',
					'created_at'       => current_time( 'mysql' ),
				] );
				$new_source_id = (int) $wpdb->insert_id;
				$cloned++;

				// Clone chunks (with embeddings!) if source was embedded
				if ( $new_source_id && (int) $src->chunk_count > 0 ) {
					$old_chunks = $wpdb->get_results( $wpdb->prepare(
						"SELECT chunk_index, content, token_count, embedding, embedding_model
						 FROM {$bcn_chunks}
						 WHERE source_id = %d
						 ORDER BY chunk_index ASC",
						(int) $src->id
					) );

					foreach ( $old_chunks ?: [] as $chunk ) {
						$wpdb->insert( $doc_chunks, [
							'source_id'       => $new_source_id,
							'doc_id'          => $doc_id,
							'chunk_index'     => (int) $chunk->chunk_index,
							'content'         => $chunk->content,
							'token_count'     => (int) $chunk->token_count,
							'embedding'       => $chunk->embedding,
							'embedding_model' => $chunk->embedding_model ?: '',
							'created_at'      => current_time( 'mysql' ),
						] );
						$chunks_cloned++;
					}
				}
			}
		}

		/* ── Step 2: Clone from bizcity_webchat_sources (research-imported, no chunks) ── */
		if ( $project_id ) {
			$wcs_table = $wpdb->prefix . 'bizcity_webchat_sources';
			$wcs_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wcs_table}'" );

			if ( $wcs_exists ) {
				// Check if project_id column exists
				$has_proj = $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM information_schema.COLUMNS
					 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'project_id'",
					$wcs_table
				) );

				if ( $has_proj ) {
					$url_col = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM information_schema.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'source_url'",
						$wcs_table
					) ) ? 'source_url' : 'url';

					$content_col = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(*) FROM information_schema.COLUMNS
						 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'content_text'",
						$wcs_table
					) ) ? 'content_text' : 'content';

					// Check for webchat_source_chunks table
					$wcs_chunks_table = $wpdb->prefix . 'bizcity_webchat_source_chunks';
					$wcs_has_chunks = $wpdb->get_var( "SHOW TABLES LIKE '{$wcs_chunks_table}'" );

					$wcs_sources = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, title, source_type, {$url_col} AS source_url,
						        {$content_col} AS content_text,
						        IFNULL(embedding_status, 'pending') AS embedding_status,
						        IFNULL(embedding_model, '') AS embedding_model,
						        IFNULL(chunk_count, 0) AS chunk_count
						 FROM {$wcs_table}
						 WHERE project_id = %s
						 ORDER BY created_at ASC",
						$project_id
					) );

					foreach ( $wcs_sources ?: [] as $src ) {
						$content = $src->content_text ?: '';
						if ( mb_strlen( $content ) < 10 ) continue; // Skip empty

						$src_chunk_count  = (int) $src->chunk_count;
						$src_embed_model  = $src->embedding_model ?: '';
						$src_embed_status = $src->embedding_status;

						$wpdb->insert( $doc_table, [
							'doc_id'           => $doc_id,
							'user_id'          => $user_id,
							'title'            => $src->title ?: 'Webchat Source',
							'source_type'      => $src->source_type ?: 'text',
							'source_url'       => $src->source_url ?: '',
							'content_text'     => $content,
							'content_hash'     => hash( 'sha256', $content ),
							'char_count'       => mb_strlen( $content ),
							'token_estimate'   => (int) ( mb_strlen( $content ) / 4 ),
							'chunk_count'      => $src_chunk_count,
							'embedding_model'  => $src_embed_model,
							'embedding_status' => $src_embed_status === 'done' ? 'done' : 'pending',
							'status'           => 'ready',
							'created_at'       => current_time( 'mysql' ),
						] );
						$new_source_id = (int) $wpdb->insert_id;
						$cloned++;

						// Clone chunks from bizcity_webchat_source_chunks (with embeddings!)
						if ( $new_source_id && $wcs_has_chunks && $src_chunk_count > 0 ) {
							$old_chunks = $wpdb->get_results( $wpdb->prepare(
								"SELECT chunk_index, content, token_count, embedding, embedding_model
								 FROM {$wcs_chunks_table}
								 WHERE source_id = %d
								 ORDER BY chunk_index ASC",
								(int) $src->id
							) );

							foreach ( $old_chunks ?: [] as $chunk ) {
								$wpdb->insert( $doc_chunks, [
									'source_id'       => $new_source_id,
									'doc_id'          => $doc_id,
									'chunk_index'     => (int) $chunk->chunk_index,
									'content'         => $chunk->content,
									'token_count'     => (int) $chunk->token_count,
									'embedding'       => $chunk->embedding,
									'embedding_model' => $chunk->embedding_model ?: '',
									'created_at'      => current_time( 'mysql' ),
								] );
								$chunks_cloned++;
							}
						}
					}
				}
			}
		}

		/* ── Step 3: Skeleton JSON as package source ──
		 * Wave 7 (PHASE-6.1 §8.3): SKIPPED. Outline + pinned notes now flow into
		 * `_autogen.outline_block` / `_autogen.pinned_notes` → bzdoc FE prefills the
		 * prompt textarea instead of polluting the SourceSidebar with a synthetic
		 * "Notebook — Dàn ý & Ghi nhớ" entry. Sources panel only shows real KG sources.
		 */

		/* ── Legacy fallback: raw text if no sources cloned ── */
		if ( $cloned === 0 && $raw_text && mb_strlen( $raw_text ) >= 10 ) {
			$content = mb_substr( $raw_text, 0, 500000 );
			$wpdb->insert( $doc_table, [
				'doc_id'           => $doc_id,
				'user_id'          => $user_id,
				'title'            => 'Notebook — Nguồn tài liệu gốc',
				'source_type'      => 'text',
				'source_url'       => '',
				'content_text'     => $content,
				'content_hash'     => hash( 'sha256', $content ),
				'char_count'       => mb_strlen( $content ),
				'token_estimate'   => (int) ( mb_strlen( $content ) / 4 ),
				'chunk_count'      => 0,
				'embedding_model'  => '',
				'embedding_status' => 'pending',
				'status'           => 'ready',
				'created_at'       => current_time( 'mysql' ),
			] );
			$cloned++;
		}

		error_log( "[BZDoc] transfer_sources: doc_id={$doc_id}, project_id={$project_id}, "
			. "sources_cloned={$cloned}, chunks_cloned={$chunks_cloned}" );
	}

	/**
	 * Wave 7 (PHASE-6.1 §8.3) — Load pinned memory notes for the notebook scope.
	 * Mirrors the logic used by Studio_Input_Builder so the same notes that
	 * inform skeleton extraction also inform the doc-gen prompt.
	 *
	 * @param string $project_id  e.g. "tc_2"
	 * @return array  Each entry: [ id, title, content, note_type, is_starred ]
	 *
	 * HOTFIX (2026-05-02): payload concatenated downstream into bzdoc `topic`
	 * which has a hard server cap (`BZDoc_Rest_API::MAX_TOPIC = 5000`). When
	 * users pin the same chat answer multiple times (very common in TwinChat)
	 * the raw 30-row dump easily exceeds 10k chars → 400 topic_too_long.
	 * We dedupe by content prefix and trim per-note body so total stays small.
	 */
	private static function load_pinned_notes( string $project_id ): array {
		if ( $project_id === '' ) return [];
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_memory_notes';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
		if ( ! $exists ) return [];
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, content, note_type, is_starred
			 FROM {$tbl}
			 WHERE project_id = %s
			   AND note_type IN ('chat_pinned','manual','research_auto','auto_pinned')
			 ORDER BY is_starred DESC, created_at DESC
			 LIMIT 30",
			$project_id
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) return [];

		/* Dedupe + truncate (PHASE-6.1 hotfix v2 — halved). */
		$max_notes      = (int) apply_filters( 'bizcity_bzdoc_pinned_notes_max', 4 );
		$max_note_chars = (int) apply_filters( 'bizcity_bzdoc_pinned_note_chars', 200 );
		$seen           = [];
		$out            = [];
		foreach ( $rows as $r ) {
			$body = trim( (string) ( $r['content'] ?? '' ) );
			if ( $body === '' ) continue;
			$key = mb_substr( preg_replace( '/\s+/u', ' ', $body ), 0, 200 );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			if ( mb_strlen( $body ) > $max_note_chars ) {
				$body = mb_substr( $body, 0, $max_note_chars - 1 ) . '…';
			}
			$r['content'] = $body;
			$out[] = $r;
			if ( count( $out ) >= $max_notes ) break;
		}
		return $out;
	}

	/**
	 * Vòng 4.5.5e (Rule 8g v2 — 2026-05-02) — Pull the most recent N twinchat
	 * messages of THIS notebook so the autogen prompt is conversation-aware,
	 * not just topic-aware. Without this, the iframe sees only the literal
	 * topic ("automation của bizcity") and loses every clarification or
	 * decision the user made in chat before approving the agent.
	 *
	 * Source table: `bizcity_webchat_messages` where
	 *   project_id = (string) $notebook_id   (no `tc_` prefix — twinchat raw)
	 *   platform_type = 'TWINCHAT'
	 *
	 * Capped tightly so the combined payload still fits MAX_TOPIC after
	 * outline + notes are appended.
	 */
	private static function load_recent_webchat( int $notebook_id ): string {
		if ( $notebook_id <= 0 ) return '';
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_webchat_messages';
		$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
		if ( ! $exists ) return '';

		$limit       = (int) apply_filters( 'bizcity_bzdoc_recent_chat_max', 8 );
		$turn_chars  = (int) apply_filters( 'bizcity_bzdoc_recent_chat_turn_chars', 220 );
		$total_chars = (int) apply_filters( 'bizcity_bzdoc_recent_chat_total_chars', 1200 );

		// Tolerate schemas that don't have project_id (older blogs).
		$has_project = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'project_id'" );
		if ( ! $has_project ) return '';

		// Schema reality (modules/webchat/.../class-webchat-database.php):
		//   message_from ENUM('user','bot','system'), message_text LONGTEXT.
		// Older patches called these "role" / "message" — keep BOTH paths so
		// blogs that ran an alias migration still work.
		$has_role         = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'role'" );
		$has_message_from = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'message_from'" );
		$role_col = $has_role ? 'role' : ( $has_message_from ? 'message_from' : '' );
		if ( $role_col === '' ) return '';

		$has_message      = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'message'" );
		$has_message_text = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$tbl}` LIKE 'message_text'" );
		$msg_col = $has_message ? 'message' : ( $has_message_text ? 'message_text' : '' );
		if ( $msg_col === '' ) return '';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT `{$role_col}` AS role, `{$msg_col}` AS message
			 FROM {$tbl}
			 WHERE project_id = %s
			 ORDER BY id DESC
			 LIMIT %d",
			(string) $notebook_id,
			$limit
		), ARRAY_A );
		if ( ! is_array( $rows ) || empty( $rows ) ) return '';

		// Reverse so output reads chronologically.
		$rows = array_reverse( $rows );

		$out    = [];
		$budget = $total_chars;
		foreach ( $rows as $r ) {
			$role = strtolower( (string) ( $r['role'] ?? 'user' ) );
			$msg  = trim( (string) ( $r['message'] ?? '' ) );
			if ( $msg === '' ) continue;
			if ( mb_strlen( $msg ) > $turn_chars ) {
				$msg = mb_substr( $msg, 0, $turn_chars - 1 ) . '…';
			}
			// Normalise: assistant|bot → AI, everything else → User.
			$prefix = ( $role === 'assistant' || $role === 'bot' ) ? 'AI' : 'User';
			$line   = $prefix . ': ' . $msg;
			if ( $budget - mb_strlen( $line ) < 0 ) break;
			$out[]   = $line;
			$budget -= mb_strlen( $line ) + 1;
		}
		return implode( "\n", $out );
	}

	/**
	 * HOTFIX (2026-05-02 v2) — When the raw outline + pinned notes payload
	 * still risks blowing past `BZDoc_Rest_API::MAX_TOPIC = 5000`, ask the
	 * LLM router to fold everything into one compact Vietnamese brief
	 * (≤ ~700 chars). Falls back to truncated raw text on any error so the
	 * user is never blocked by a transient LLM failure.
	 *
	 * @param string $outline_block
	 * @param array  $pinned_notes  rows from load_pinned_notes()
	 * @param string $topic         user's original topic (for context only)
	 * @return string  Summary text, or '' on failure (caller keeps raw inputs).
	 */
	private static function summarize_autogen_payload( string $outline_block, array $pinned_notes, string $topic ): string {
		if ( ! function_exists( 'bizcity_llm_chat' ) ) return '';

		$target_chars = (int) apply_filters( 'bizcity_bzdoc_autogen_summary_chars', 700 );

		$notes_text = '';
		foreach ( $pinned_notes as $n ) {
			$title = trim( (string) ( $n['title'] ?? '' ) );
			$body  = trim( (string) ( $n['content'] ?? '' ) );
			if ( $body === '' ) continue;
			$notes_text .= ( $title !== '' ? "[{$title}] " : '' ) . $body . "\n";
		}

		// Cap raw input fed to the LLM so we don't pay for huge prompts.
		$raw  = "DÀN Ý:\n" . $outline_block . "\n\nGHI CHÚ ĐÃ GHIM:\n" . $notes_text;
		if ( mb_strlen( $raw ) > 6000 ) {
			$raw = mb_substr( $raw, 0, 5999 ) . '…';
		}

		$messages = [
			[
				'role'    => 'system',
				'content' => 'Bạn là trợ lý tóm tắt tiếng Việt. Tóm tắt nội dung dưới đây thành MỘT đoạn ngắn gọn dưới ' . $target_chars . ' ký tự, giữ lại ý chính, dàn ý, quyết định, dữ kiện quan trọng. KHÔNG markdown, KHÔNG bullet, viết liền mạch.',
			],
			[
				'role'    => 'user',
				'content' => "Chủ đề người dùng: {$topic}\n\n{$raw}",
			],
		];

		try {
			$result = bizcity_llm_chat( $messages, [
				'purpose'     => 'bzdoc_autogen_summary',
				'max_tokens'  => 600,
				'temperature' => 0.2,
			] );
		} catch ( \Throwable $e ) {
			error_log( '[BZDoc][summarize_autogen] ' . $e->getMessage() );
			return '';
		}

		if ( is_wp_error( $result ) ) {
			error_log( '[BZDoc][summarize_autogen] ' . $result->get_error_message() );
			return '';
		}

		// Router returns array — extract content defensively.
		$text = '';
		if ( is_array( $result ) ) {
			$text = (string) ( $result['content'] ?? $result['text'] ?? $result['message'] ?? '' );
			if ( $text === '' && isset( $result['choices'][0]['message']['content'] ) ) {
				$text = (string) $result['choices'][0]['message']['content'];
			}
		} elseif ( is_string( $result ) ) {
			$text = $result;
		}

		$text = trim( $text );
		if ( $text === '' ) return '';

		// Hard-cap in case the model ignored the budget.
		if ( mb_strlen( $text ) > $target_chars + 200 ) {
			$text = mb_substr( $text, 0, $target_chars + 199 ) . '…';
		}
		return $text;
	}

	/**
	 * Convert skeleton into a readable structured text for source storage.
	 * This preserves notes, key points, entities, decisions as a reference
	 * document that Doc Studio can use for future RAG-based edits.
	 */
	private static function skeleton_to_structured_source( array $skeleton ): string {
		$parts = [];

		$nucleus = $skeleton['nucleus'] ?? [];
		if ( ! empty( $nucleus['title'] ) ) {
			$parts[] = '# ' . $nucleus['title'];
		}
		if ( ! empty( $nucleus['thesis'] ) ) {
			$parts[] = $nucleus['thesis'];
		}

		if ( ! empty( $skeleton['key_points'] ) ) {
			$parts[] = "\n## Điểm chính";
			foreach ( $skeleton['key_points'] as $kp ) {
				$text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : (string) $kp;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		if ( ! empty( $skeleton['skeleton'] ) ) {
			$parts[] = "\n## Dàn ý";
			foreach ( $skeleton['skeleton'] as $node ) {
				$label   = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
				$summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$parts[] = '### ' . $label;
				if ( $summary ) {
					$parts[] = $summary;
				}
				foreach ( ( is_array( $node ) ? ( $node['children'] ?? [] ) : [] ) as $child ) {
					$clabel = is_array( $child ) ? ( $child['label'] ?? $child['text'] ?? '' ) : (string) $child;
					if ( $clabel ) {
						$parts[] = '- ' . $clabel;
					}
				}
			}
		}

		if ( ! empty( $skeleton['entities'] ) ) {
			$parts[] = "\n## Thực thể";
			foreach ( array_slice( $skeleton['entities'], 0, 15 ) as $e ) {
				$name = is_array( $e ) ? ( $e['name'] ?? '' ) : (string) $e;
				$role = is_array( $e ) ? ( $e['role'] ?? '' ) : '';
				if ( $name ) {
					$parts[] = '- ' . $name . ( $role ? " ({$role})" : '' );
				}
			}
		}

		if ( ! empty( $skeleton['decisions'] ) ) {
			$parts[] = "\n## Quyết định";
			foreach ( array_slice( $skeleton['decisions'], 0, 10 ) as $d ) {
				$text = is_array( $d ) ? ( $d['text'] ?? $d['decision'] ?? '' ) : (string) $d;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		return implode( "\n", $parts );
	}

	/**
	 * Convert skeleton JSON to a topic string + separate source text.
	 *
	 * Returns ['topic' => short structured outline, 'source_text' => long raw content].
	 * The topic stays under MAX_TOPIC (2000), source text goes into source_context_override.
	 */
	private static function skeleton_to_topic( array $skeleton, string $doc_type ): array {
		$parts = [];

		// Nucleus — title + thesis.
		$nucleus = $skeleton['nucleus'] ?? [];
		if ( ! empty( $nucleus['title'] ) ) {
			$parts[] = 'Chủ đề: ' . $nucleus['title'];
		}
		if ( ! empty( $nucleus['thesis'] ) ) {
			$parts[] = 'Luận điểm chính: ' . $nucleus['thesis'];
		}
		if ( ! empty( $nucleus['domain'] ) ) {
			$parts[] = 'Lĩnh vực: ' . $nucleus['domain'];
		}

		// Skeleton tree → document structure / outline.
		if ( ! empty( $skeleton['skeleton'] ) ) {
			$outline_label = $doc_type === 'presentation' ? 'Dàn ý slide' : 'Dàn ý tài liệu';
			$parts[] = "\n{$outline_label}:";
			foreach ( $skeleton['skeleton'] as $node ) {
				$label   = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? '' ) : (string) $node;
				$summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
				$parts[] = '- ' . $label . ( $summary ? ': ' . $summary : '' );

				foreach ( ( is_array( $node ) ? ( $node['children'] ?? [] ) : [] ) as $child ) {
					$clabel   = is_array( $child ) ? ( $child['label'] ?? $child['text'] ?? '' ) : (string) $child;
					$csummary = is_array( $child ) ? ( $child['summary'] ?? '' ) : '';
					if ( $clabel ) {
						$parts[] = '  - ' . $clabel . ( $csummary ? ': ' . $csummary : '' );
					}
				}
			}
		}

		// Key points.
		if ( ! empty( $skeleton['key_points'] ) ) {
			$parts[] = "\nĐiểm chính:";
			foreach ( $skeleton['key_points'] as $kp ) {
				$text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : (string) $kp;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		// Entities.
		if ( ! empty( $skeleton['entities'] ) ) {
			$names = array_map( function ( $e ) {
				return is_array( $e ) ? ( $e['name'] ?? '' ) : (string) $e;
			}, $skeleton['entities'] );
			$names = array_filter( $names );
			if ( $names ) {
				$parts[] = "\nThực thể liên quan: " . implode( ', ', array_slice( $names, 0, 10 ) );
			}
		}

		// Decisions (useful for documents).
		if ( ! empty( $skeleton['decisions'] ) && $doc_type === 'document' ) {
			$parts[] = "\nQuyết định:";
			foreach ( array_slice( $skeleton['decisions'], 0, 8 ) as $d ) {
				$text = is_array( $d ) ? ( $d['text'] ?? $d['decision'] ?? '' ) : (string) $d;
				if ( $text ) {
					$parts[] = '- ' . $text;
				}
			}
		}

		// Timeline (useful for presentations).
		if ( ! empty( $skeleton['timeline'] ) && $doc_type === 'presentation' ) {
			$parts[] = "\nTimeline:";
			foreach ( $skeleton['timeline'] as $t ) {
				$label = is_array( $t ) ? ( $t['label'] ?? '' ) : (string) $t;
				$desc  = is_array( $t ) ? ( $t['description'] ?? '' ) : '';
				if ( $label ) {
					$parts[] = '- ' . $label . ( $desc ? ': ' . $desc : '' );
				}
			}
		}

		$topic = implode( "\n", $parts );

		// Raw source text goes into source_context_override (separate from topic).
		$raw_text = $skeleton['_raw_text'] ?? '';
		if ( empty( $raw_text ) && class_exists( 'BCN_Studio_Input_Builder' ) ) {
			$raw_text = BCN_Studio_Input_Builder::to_text( $skeleton );
		}
		$source_text = '';
		if ( $raw_text ) {
			$max_raw = $doc_type === 'spreadsheet' ? 8000 : 16000;
			$source_text = mb_substr( $raw_text, 0, $max_raw );
		}

		// Fallback: if nothing structured, use raw text as topic (truncated to fit).
		if ( empty( trim( $topic ) ) ) {
			$topic = mb_substr( $raw_text ?: '', 0, 1800 );
		}

		// Hard-cap topic to match server MAX_TOPIC (50_000 chars) — tránh lỗi
		// "Topic exceeds maximum length" từ REST endpoint.
		$max_topic = class_exists( 'BZDoc_Rest_API' ) ? BZDoc_Rest_API::MAX_TOPIC : 50000;
		if ( mb_strlen( $topic ) > $max_topic ) {
			$topic = mb_substr( $topic, 0, $max_topic );
		}

		return [
			'topic'       => $topic,
			'source_text' => $source_text,
		];
	}

	/**
	 * Guess the best template based on skeleton content.
	 */
	private static function guess_template( array $skeleton, string $doc_type ): string {
		if ( $doc_type !== 'document' ) {
			return 'blank';
		}

		$domain = strtolower( $skeleton['nucleus']['domain'] ?? '' );
		$title  = strtolower( $skeleton['nucleus']['title'] ?? '' );

		// Simple heuristic — map domain/title keywords to templates.
		if ( strpos( $title, 'proposal' ) !== false || strpos( $title, 'đề xuất' ) !== false ) {
			return 'proposal';
		}
		if ( strpos( $title, 'report' ) !== false || strpos( $title, 'báo cáo' ) !== false ) {
			return 'report';
		}
		if ( strpos( $title, 'contract' ) !== false || strpos( $title, 'hợp đồng' ) !== false ) {
			return 'contract';
		}

		return 'blank';
	}

	/**
	 * Extract a display title from the generated schema.
	 */
	private static function extract_title( $schema, string $doc_type ): string {
		if ( ! is_array( $schema ) ) {
			return 'Untitled';
		}

		switch ( $doc_type ) {
			case 'presentation':
				return $schema['presentation_title'] ?? 'Presentation';
			case 'spreadsheet':
				return $schema['metadata']['title'] ?? 'Spreadsheet';
			default:
				return $schema['metadata']['title'] ?? 'Document';
		}
	}
}
