<?php
/**
 * Bizcity KG Guru Builder — Phase 0.21 Wave 3.0
 *
 * Promotes a notebook into a Guru (character) by cloning all kg_sources +
 * kg_passages rows under a fresh character_uuid, then rebuilding the
 * gurus/{uuid}.bin vector file from those cloned rows.
 *
 * Mode: clone (default) — original notebook rows untouched.
 * Mode: move (future)  — re-tags rows in place; not implemented in Wave 3.0.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-05-06
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Guru_Builder {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Promote a notebook into a guru.
	 *
	 * @param int   $notebook_id
	 * @param array $args {
	 *     @type string $name          Required. Display name of the guru.
	 *     @type string $slug          Optional. Auto-derived from name if empty.
	 *     @type string $description   Optional.
	 *     @type string $system_prompt Optional. Persona instruction; empty string OK.
	 *     @type string $mode          'clone' (default) | 'move' (future).
	 *     @type int    $user_id       Defaults to get_current_user_id().
	 * }
	 * @return array|WP_Error  { character_id, guru_uuid, source_count, chunk_count, bin: { count, dim, path } }
	 */
	public function promote_notebook( $notebook_id, array $args ) {
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'guru_bad_notebook', 'notebook_id must be > 0' );
		}
		if ( empty( $args['name'] ) ) {
			return new WP_Error( 'guru_missing_name', 'name is required' );
		}
		$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'clone';
		if ( $mode !== 'clone' ) {
			return new WP_Error( 'guru_mode_unsupported', 'Only mode=clone is supported in Wave 3.0' );
		}
		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : (int) get_current_user_id();

		if ( ! class_exists( 'BizCity_KG_Database' ) || ! class_exists( 'BizCity_Knowledge_Database' ) ) {
			return new WP_Error( 'guru_deps_missing', 'KG-Hub or Knowledge DB not loaded' );
		}

		global $wpdb;
		$kg_db        = BizCity_KG_Database::instance();
		$sources_tbl  = $kg_db->tbl_sources();
		$chunks_tbl   = $kg_db->tbl_source_chunks();

		// 1. Discover sources in this notebook.
		$src_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$sources_tbl}
			  WHERE scope_type = 'notebook' AND scope_id = %s
			    AND ( character_uuid IS NULL OR character_uuid = '' )
			    AND status = 'active'
			  ORDER BY id ASC",
			(string) $notebook_id
		), ARRAY_A );
		if ( empty( $src_rows ) ) {
			return new WP_Error( 'guru_no_sources', 'Notebook ' . $notebook_id . ' has no active kg_sources rows to promote.' );
		}

		// 2. Generate guru_uuid + create character.
		$guru_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $this->fallback_uuid4();
		$slug      = ! empty( $args['slug'] ) ? sanitize_title( $args['slug'] ) : sanitize_title( $args['name'] );
		$slug      = $this->ensure_unique_slug( $slug );

		$char_db   = BizCity_Knowledge_Database::instance();
		$char_id   = $char_db->create_character( [
			'name'          => (string) $args['name'],
			'slug'          => $slug,
			'description'   => (string) ( $args['description'] ?? '' ),
			'system_prompt' => (string) ( $args['system_prompt'] ?? '' ),
			'status'        => 'draft',
			'author_id'     => $user_id,
		] );
		if ( is_wp_error( $char_id ) ) {
			return $char_id;
		}
		$char_id = (int) $char_id;
		if ( $char_id <= 0 ) {
			global $wpdb;
			$char_tbl   = $wpdb->prefix . 'bizcity_characters';
			// Fallback: insert may have succeeded but insert_id was lost (e.g. WPDB router
			// returning rows-affected=0 across shards). Try to recover by slug.
			$char_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$char_tbl} WHERE slug = %s ORDER BY id DESC LIMIT 1",
				$slug
			) );
		}
		if ( $char_id <= 0 ) {
			global $wpdb;
			$char_tbl   = $wpdb->prefix . 'bizcity_characters';
			$tbl_exists = bizcity_tbl_exists( $char_tbl ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$last_err   = $wpdb->last_error ?: '(empty)';
			$last_query = $wpdb->last_query ?: '(empty)';
			return new WP_Error(
				'guru_char_failed',
				'create_character returned 0. table=' . $char_tbl . ' exists=' . ( $tbl_exists ? 'yes' : 'NO' )
					. ' | slug_tried=' . $slug
					. ' | last_error=' . $last_err
					. ' | last_query=' . substr( $last_query, 0, 400 )
			);
		}

		// Stamp guru columns (visibility/version/uuid/embed_model/bin_path).
		$bin_rel = 'gurus/' . $guru_uuid . '.bin';
		$char_db->update_character( $char_id, [
			'guru_uuid'    => $guru_uuid,
			'visibility'   => 'private',
			'version'      => '1.0.0',
			'license'      => 'proprietary',
			'embed_model'  => 'text-embedding-3-small',
			'bin_path'     => $bin_rel,
			'origin_user'  => $user_id,
		] );

		// 3. Clone sources + chunks under (character_uuid, scope_type='character').
		$source_id_map = [];     // old → new
		$cloned_sources = 0;
		$cloned_chunks  = 0;
		// HOTFIX 2026-05-06: capture per-source diagnostic for "0 chunks cloned" mystery.
		// Some notebooks have kg_passages rows whose source_id refers to OLD legacy table
		// (bizcity_knowledge_sources / bizcity_doc_sources), not bizcity_kg_sources.
		// We probe with a permissive count first, then narrow filter, and log the gap.
		$diag_per_source = [];
		foreach ( $src_rows as $src ) {
			$old_src_id = (int) $src['id'];
			$new_uuid   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $this->fallback_uuid4();

			$insert = [
				'uuid'           => $new_uuid,
				'blog_id'        => (int) ( $src['blog_id'] ?? get_current_blog_id() ),
				'origin_plugin'  => (string) ( $src['origin_plugin'] ?? 'twinchat' ),
				'origin_kind'    => (string) ( $src['origin_kind'] ?? 'text' ),
				'origin_id'      => isset( $src['origin_id'] ) ? (int) $src['origin_id'] : null,
				'title'          => (string) ( $src['title'] ?? '' ),
				'origin_url'     => (string) ( $src['origin_url'] ?? '' ),
				'content_text'   => $src['content_text'] ?? null,
				'status'         => 'active',
				'scope_type'     => 'character',
				'scope_id'       => $guru_uuid,
				'user_id'        => $user_id,
				'passage_count'  => (int) ( $src['passage_count'] ?? 0 ),
				'embed_model'    => (string) ( $src['embed_model'] ?? 'text-embedding-3-small' ),
				'character_uuid' => $guru_uuid,
				'created_at'     => current_time( 'mysql', true ),
			];
			$ok = $wpdb->insert( $sources_tbl, $insert );
			if ( ! $ok ) {
				return new WP_Error( 'guru_src_clone_failed', 'INSERT into kg_sources failed: ' . $wpdb->last_error );
			}
			$new_src_id = (int) $wpdb->insert_id;
			$source_id_map[ $old_src_id ] = $new_src_id;
			$cloned_sources++;

			// Clone chunks for this source.
			// HOTFIX 2026-05-06: triple-probe to diagnose "0 chunks" issue.
			//   probe_total: chunks for this source_id, regardless of character_uuid
			//   probe_free:  chunks for this source_id where character_uuid IS NULL/''
			//   if probe_total > 0 but probe_free == 0 → previous failed promote already
			//   stamped them; we relax the filter and clone anyway (idempotent re-promote).
			$probe_total = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$chunks_tbl} WHERE source_id = %d", $old_src_id
			) );
			$probe_free  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$chunks_tbl} WHERE source_id = %d AND ( character_uuid IS NULL OR character_uuid = '' )",
				$old_src_id
			) );
			$use_relaxed = ( $probe_total > 0 && $probe_free === 0 );
			$diag_per_source[] = [
				'old_src_id'  => $old_src_id,
				'title'       => (string) ( $src['title'] ?? '' ),
				'probe_total' => $probe_total,
				'probe_free'  => $probe_free,
				'relaxed'     => $use_relaxed,
			];

			if ( $use_relaxed ) {
				$chunk_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$chunks_tbl}
					  WHERE source_id = %d
					  ORDER BY chunk_index ASC, id ASC",
					$old_src_id
				), ARRAY_A );
			} else {
				$chunk_rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$chunks_tbl}
					  WHERE source_id = %d
					    AND ( character_uuid IS NULL OR character_uuid = '' )
					  ORDER BY chunk_index ASC, id ASC",
					$old_src_id
				), ARRAY_A );
			}

			foreach ( $chunk_rows as $ck ) {
				$ck_uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : $this->fallback_uuid4();
				$crow = [
					'uuid'           => $ck_uuid,
					'source_id'      => $new_src_id,
					'blog_id'        => (int) ( $ck['blog_id'] ?? get_current_blog_id() ),
					'project_id'     => (string) ( $ck['project_id'] ?? '' ),
					'plugin_name'    => (string) ( $ck['plugin_name'] ?? 'twinchat' ),
					'user_id'        => $user_id,
					'notebook_id'    => 0, // guru-scope chunk; column is NOT NULL, use sentinel 0 (filter by character_uuid IS NOT NULL)
					'chunk_index'    => (int) ( $ck['chunk_index'] ?? 0 ),
					'content'        => (string) ( $ck['content'] ?? '' ),
					'content_hash'   => (string) ( $ck['content_hash'] ?? '' ),
					'token_count'   => (int) ( $ck['token_count'] ?? 0 ),
					// Filestore-only (Rule v2.0): embedding column NULL; vector goes into gurus/{uuid}.bin via register_chunk below.
					'embedding'      => null,
					'embed_model'    => (string) ( $ck['embed_model'] ?? 'text-embedding-3-small' ),
					'embed_status'   => (string) ( $ck['embed_status'] ?? ( ! empty( $ck['embedding'] ) ? 'ready' : 'pending' ) ),
					'origin'         => (string) ( $ck['origin'] ?? 'source' ),
					'scope_type'     => 'character',
					'scope_id'       => $guru_uuid,
					'source_table'   => $sources_tbl,
					'character_uuid' => $guru_uuid,
					'created_at'     => current_time( 'mysql', true ),
				];
				$ok2 = $wpdb->insert( $chunks_tbl, $crow );
				if ( ! $ok2 ) {
					return new WP_Error( 'guru_chunk_clone_failed', 'INSERT into kg_passages failed for old src ' . $old_src_id . ': ' . $wpdb->last_error );
				}
				$cloned_chunks++;

				// Append vector to gurus/{uuid}.bin per-chunk (replaces post-loop rebuild_from_scope).
				$new_pid = (int) $wpdb->insert_id;
				if ( $new_pid && ! empty( $ck['embedding'] ) && class_exists( 'BizCity_KG_Embedding_Writer' ) ) {
					$vec_arr = is_array( $ck['embedding'] )
						? $ck['embedding']
						: json_decode( (string) $ck['embedding'], true );
					if ( is_array( $vec_arr ) && ! empty( $vec_arr ) ) {
						BizCity_KG_Embedding_Writer::instance()->register_chunk(
							0, $new_pid, $vec_arr, $guru_uuid, (int) $new_src_id
						);
					}
				}
			}

			// Update passage_count on the cloned source row.
			$wpdb->update( $sources_tbl, [ 'passage_count' => count( $chunk_rows ) ], [ 'id' => $new_src_id ] );
		}

		// 4. Refresh bin_count on character row from the freshly-written .bin header.
		// (Per-chunk register_chunk above already wrote the file; rebuild_from_scope no
		//  longer applies because chunks have embedding=NULL after Rule v2.0 migration.)
		$bin_info = null;
		if ( class_exists( 'BizCity_KG_Vector_File_Store' )
			&& function_exists( 'bizcity_kg_vector_bin_path' ) ) {
			$store   = BizCity_KG_Vector_File_Store::instance();
			$bin_abs = bizcity_kg_vector_bin_path( 'gurus', strtolower( (string) $guru_uuid ) );
			if ( $bin_abs && file_exists( $bin_abs ) ) {
				$hdr = $store->header_validate( $bin_abs );
				if ( is_wp_error( $hdr ) ) {
					if ( class_exists( 'BizCity_Twin_Debug' ) ) {
						BizCity_Twin_Debug::trace( 'kg', 'guru_bin_header_invalid', [
							'guru_uuid' => $guru_uuid,
							'error'     => $hdr->get_error_message(),
						] );
					}
					$bin_info = [ 'error' => $hdr->get_error_code(), 'message' => $hdr->get_error_message() ];
				} else {
					$bin_info = [
						'count' => (int) ( $hdr['count'] ?? 0 ),
						'dim'   => (int) ( $hdr['dim'] ?? 0 ),
						'path'  => $bin_abs,
					];
					// Persist bin stats on character row.
					$char_db->update_character( $char_id, [
						'bin_dim'   => (int) ( $hdr['dim'] ?? 0 ),
						'bin_count' => (int) ( $hdr['count'] ?? 0 ),
					] );
				}
			} else {
				$bin_info = [ 'error' => 'kg_bin_missing', 'message' => 'gurus/' . $guru_uuid . '.bin not found after clone' ];
			}
		}

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'kg', 'guru_promote_done', [
				'notebook_id'  => $notebook_id,
				'character_id' => $char_id,
				'guru_uuid'    => $guru_uuid,
				'sources'      => $cloned_sources,
				'chunks'       => $cloned_chunks,
				'bin'          => $bin_info,
				'per_source'   => $diag_per_source,
			] );
		}

		return [
			'character_id' => $char_id,
			'guru_uuid'    => $guru_uuid,
			'slug'         => $slug,
			'source_count' => $cloned_sources,
			'chunk_count'  => $cloned_chunks,
			'bin'          => $bin_info,
			'per_source'   => $diag_per_source, // surface to UI for debugging
		];
	}

	/**
	 * Get a one-line summary of what would happen if we promoted this notebook.
	 * Read-only; callable from REST/CLI/diagnostic.
	 */
	public function preview_notebook( $notebook_id ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'guru_bad_notebook', 'notebook_id must be > 0' );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'guru_deps_missing', 'KG-Hub not loaded' );
		}
		$kg_db        = BizCity_KG_Database::instance();
		$sources_tbl  = $kg_db->tbl_sources();
		$chunks_tbl   = $kg_db->tbl_source_chunks();
		$ents_tbl     = $kg_db->tbl_entities();
		$rels_tbl     = $kg_db->tbl_relations();

		// Path A — sources registered for this notebook (the "11 sources").
		$source_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$sources_tbl}
			  WHERE scope_type='notebook' AND scope_id=%s
			    AND (character_uuid IS NULL OR character_uuid='')
			    AND status='active'",
			(string) $notebook_id
		) );

		// Path B — chunks discovered via sources join (current promote path).
		$chunk_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl} c
			   JOIN {$sources_tbl} s ON s.id = c.source_id
			  WHERE s.scope_type='notebook' AND s.scope_id=%s
			    AND (s.character_uuid IS NULL OR s.character_uuid='')",
			(string) $notebook_id
		) );
		$with_embedding = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl} c
			   JOIN {$sources_tbl} s ON s.id = c.source_id
			  WHERE s.scope_type='notebook' AND s.scope_id=%s
			    AND (s.character_uuid IS NULL OR s.character_uuid='')
			    AND c.embedding IS NOT NULL AND c.embedding <> ''",
			(string) $notebook_id
		) );

		// Path C — DIAGNOSTIC: chunks bound directly by notebook_id (catches
		// legacy/orphan rows where source_id is NULL or source row missing).
		$chunks_by_notebook = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl}
			  WHERE notebook_id = %d
			    AND ( character_uuid IS NULL OR character_uuid = '' )",
			$notebook_id
		) );
		$orphan_chunks = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl} c
			  WHERE c.notebook_id = %d
			    AND ( c.character_uuid IS NULL OR c.character_uuid = '' )
			    AND ( c.source_id IS NULL OR c.source_id = 0
			          OR NOT EXISTS ( SELECT 1 FROM {$sources_tbl} s2 WHERE s2.id = c.source_id ) )",
			$notebook_id
		) );
		$orphan_with_embedding = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$chunks_tbl} c
			  WHERE c.notebook_id = %d
			    AND ( c.character_uuid IS NULL OR c.character_uuid = '' )
			    AND ( c.source_id IS NULL OR c.source_id = 0
			          OR NOT EXISTS ( SELECT 1 FROM {$sources_tbl} s2 WHERE s2.id = c.source_id ) )
			    AND c.embedding IS NOT NULL AND c.embedding <> ''",
			$notebook_id
		) );

		// Path D — graph counts (notebook-scoped).
		$entity_count   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$ents_tbl} WHERE notebook_id = %d", $notebook_id
		) );
		$relation_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$rels_tbl} WHERE notebook_id = %d", $notebook_id
		) );

		// Sample of the 11 sources so the UI can show why chunks are missing.
		$src_sample = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.title, s.origin_kind, s.passage_count,
			        ( SELECT COUNT(*) FROM {$chunks_tbl} c WHERE c.source_id = s.id ) AS actual_chunks
			   FROM {$sources_tbl} s
			  WHERE s.scope_type='notebook' AND s.scope_id=%s
			    AND ( s.character_uuid IS NULL OR s.character_uuid='' )
			    AND s.status='active'
			  ORDER BY s.id DESC
			  LIMIT 20",
			(string) $notebook_id
		), ARRAY_A ) ?: [];

		return [
			'notebook_id'             => $notebook_id,
			'sources_to_clone'        => $source_count,
			'chunks_to_clone'         => $chunk_count,
			'chunks_with_embedding'   => $with_embedding,
			// Diagnostic — surfaces legacy/orphan data.
			'chunks_by_notebook_id'   => $chunks_by_notebook,
			'orphan_chunks'           => $orphan_chunks,
			'orphan_with_embedding'   => $orphan_with_embedding,
			'entity_count'            => $entity_count,
			'relation_count'          => $relation_count,
			'sources_sample'          => $src_sample,
		];
	}

	private function ensure_unique_slug( $slug ) {
		global $wpdb;
		$base  = $slug !== '' ? $slug : 'guru-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		$try   = $base;
		$tbl   = $wpdb->prefix . 'bizcity_characters';
		$i     = 1;
		while ( true ) {
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tbl} WHERE slug = %s", $try ) );
			if ( ! $exists ) { return $try; }
			$i++;
			$try = $base . '-' . $i;
			if ( $i > 50 ) { return $base . '-' . substr( md5( uniqid( '', true ) ), 0, 6 ); }
		}
	}

	private function fallback_uuid4() {
		$d = random_bytes( 16 );
		$d[6] = chr( ( ord( $d[6] ) & 0x0f ) | 0x40 );
		$d[8] = chr( ( ord( $d[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $d ), 4 ) );
	}
}
