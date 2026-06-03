<?php
/**
 * Bizcity Twin AI — KG Task Predicate & Citation Validator
 *
 * Sprint 4.5h — `bizcity_kg_is_main_task( $surface, $intent )`
 *   Predicate plugins call BEFORE building a prompt. Returns true when the
 *   request qualifies as a "main LLM task" requiring KG context injection.
 *   Atomic operations (button-click utilities, formatters, classifiers, …)
 *   should return false to skip the KG round-trip.
 *
 * Sprint 4.5i — `bizcity_kg_validate_citations( $text, $sources )`
 *   After a main-task LLM response arrives, count `[N]` and `[KG-N]` markers
 *   and verify each refers to an entry in the supplied source list. Returns
 *   { ok, missing[], orphans[], total_markers, max_index }.
 *
 * Governed by PHASE-0-RULE-KG-HUB-CONTRACT.md §4.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @since      2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/**
 * Return true when the operation needs Knowledge Graph context.
 *
 * Default policy:
 *  - chat / answer / generate / explain / summarize  → main task (true)
 *  - format / classify / extract / tag / score       → atomic (false)
 *  - explicit `$intent` overrides surface heuristics
 *
 * Filter `bizcity_kg_is_main_task` lets modules override per-call.
 *
 * @param string $surface  e.g. 'twinchat', 'webchat', 'doc', 'tool'
 * @param string $intent   optional verb hint
 * @return bool
 */
if ( ! function_exists( 'bizcity_kg_is_main_task' ) ) {
	function bizcity_kg_is_main_task( $surface = '', $intent = '' ) {
		$surface = strtolower( (string) $surface );
		$intent  = strtolower( (string) $intent );

		$atomic_intents = [
			'format', 'classify', 'tag', 'score', 'rank', 'rerank',
			'normalize', 'sanitize', 'translate_short', 'detect_language',
			'extract_entities', 'extract_keywords',
		];

		$main_intents = [
			'chat', 'answer', 'generate', 'explain', 'summarize',
			'plan', 'reason', 'research', 'rewrite', 'compose',
		];

		$is_main = false;
		if ( $intent !== '' ) {
			if ( in_array( $intent, $main_intents, true ) ) {
				$is_main = true;
			} elseif ( in_array( $intent, $atomic_intents, true ) ) {
				$is_main = false;
			}
		} else {
			// No intent: judge by surface.
			$main_surfaces = [ 'twinchat', 'webchat', 'chat', 'companion-notebook', 'notebook', 'doc-chat' ];
			$is_main = in_array( $surface, $main_surfaces, true );
		}

		/**
		 * Allow modules to override the predicate.
		 *
		 * @param bool   $is_main
		 * @param string $surface
		 * @param string $intent
		 */
		return (bool) apply_filters( 'bizcity_kg_is_main_task', $is_main, $surface, $intent );
	}
}

/**
 * Validate citation markers in an LLM output string.
 *
 * Supports BOTH legacy and Phase 0.6 formats:
 *   Legacy:    [N], [KG-N]
 *   Phase 0.6: [src:N], [src:N#pM], [ent:N], [rel:N], [draft:N], [K1]
 *
 * @param string $text     raw LLM output
 * @param array  $sources  array of source items the model was given.
 *                          Each item may carry: index, source_id, passage_id, label, cite_id.
 * @param array  $kg_items optional separate list for [KG-N] / [K-N] markers
 *                          Each item may carry: index, id (entity_id).
 * @return array {
 *   ok:            bool   — true iff every marker maps to context AND has at least one citation
 *   total_markers: int
 *   max_index:     int
 *   missing:       array  — markers referenced but not present in context (mixed: int|string)
 *   orphans:       int[]  — source indexes never cited (informational)
 *   kg_markers:    string[]  — Phase 0.6 markers found (raw, without brackets)
 *   reason:        string — short reason code
 * }
 */
if ( ! function_exists( 'bizcity_kg_validate_citations' ) ) {
	function bizcity_kg_validate_citations( $text, array $sources = [], array $kg_items = [] ) {
		$text = (string) $text;

		// Legacy markers.
		preg_match_all( '/\[(\d{1,4})\]/', $text, $m_plain );
		preg_match_all( '/\[KG-(\d{1,4})\]/i', $text, $m_kg );
		preg_match_all( '/\[K(\d{1,4})\]/', $text, $m_kg_short );

		// Phase 0.6 KG markers.
		preg_match_all( '/\[src:(\d+)(?:#p(\d+))?\]/', $text, $m_src );
		preg_match_all( '/\[ent:(\d+)\]/', $text, $m_ent );
		preg_match_all( '/\[rel:(\d+)\]/', $text, $m_rel );
		preg_match_all( '/\[draft:(\d+)\]/', $text, $m_draft );

		$plain_idx = array_map( 'intval', $m_plain[1] );
		$kg_idx    = array_map( 'intval', array_merge( $m_kg[1], $m_kg_short[1] ) );

		// Build lookup tables for Phase 0.6 markers.
		$valid_src_ids = [];
		$valid_pid_by_src = [];
		foreach ( $sources as $s ) {
			$sid = (int) ( $s['source_id'] ?? $s['id'] ?? 0 );
			$pid = (int) ( $s['passage_id'] ?? 0 );
			if ( $sid > 0 ) {
				$valid_src_ids[ $sid ] = true;
				if ( $pid > 0 ) {
					$valid_pid_by_src[ $sid ][ $pid ] = true;
				}
			}
		}
		$valid_ent_ids = [];
		foreach ( $kg_items as $k ) {
			$eid = (int) ( $k['id'] ?? $k['entity_id'] ?? 0 );
			if ( $eid > 0 ) $valid_ent_ids[ $eid ] = true;
		}

		$source_count = count( $sources );
		$kg_count     = count( $kg_items );

		$missing    = [];
		$kg_markers = [];

		// Validate legacy [N] indexes.
		foreach ( $plain_idx as $i ) {
			if ( $i < 1 || $i > $source_count ) {
				$missing[] = $i;
			}
		}
		// Validate legacy [KG-N] / [K1] indexes.
		foreach ( $kg_idx as $i ) {
			if ( $i < 1 || $i > $kg_count ) {
				$missing[] = 'K' . $i;
			}
		}

		// Validate Phase 0.6 [src:N] / [src:N#pM].
		if ( ! empty( $m_src[1] ) ) {
			foreach ( $m_src[1] as $idx => $sid_str ) {
				$sid = (int) $sid_str;
				$pid = isset( $m_src[2][ $idx ] ) ? (int) $m_src[2][ $idx ] : 0;
				$label = $pid > 0 ? "src:{$sid}#p{$pid}" : "src:{$sid}";
				$kg_markers[] = $label;
				// Soft validation: only flag if context has src list at all and sid not in it.
				if ( ! empty( $valid_src_ids ) && empty( $valid_src_ids[ $sid ] ) ) {
					$missing[] = $label;
				} elseif ( $pid > 0 && ! empty( $valid_pid_by_src[ $sid ] ) && empty( $valid_pid_by_src[ $sid ][ $pid ] ) ) {
					// passage_id known but doesn't belong to that source's known passages.
					$missing[] = $label;
				}
			}
		}
		// Validate [ent:N].
		if ( ! empty( $m_ent[1] ) ) {
			foreach ( $m_ent[1] as $eid_str ) {
				$eid = (int) $eid_str;
				$kg_markers[] = "ent:{$eid}";
				if ( ! empty( $valid_ent_ids ) && empty( $valid_ent_ids[ $eid ] ) ) {
					$missing[] = "ent:{$eid}";
				}
			}
		}
		// [rel:N] and [draft:N] cannot be validated against passed context — accept as-is.
		foreach ( ( $m_rel[1] ?? [] ) as $rid ) $kg_markers[] = "rel:{$rid}";
		foreach ( ( $m_draft[1] ?? [] ) as $did ) $kg_markers[] = "draft:{$did}";

		$cited = array_unique( array_merge( $plain_idx, $kg_idx ) );
		$orphans = [];
		for ( $i = 1; $i <= max( $source_count, $kg_count ); $i++ ) {
			if ( ! in_array( $i, $cited, true ) ) {
				$orphans[] = $i;
			}
		}

		$total = count( $plain_idx ) + count( $kg_idx ) + count( $kg_markers );
		$max_index = $total > 0 ? max( array_merge( [ 0 ], $plain_idx, $kg_idx ) ) : 0;

		$reason = empty( $missing )
			? ( $total === 0 ? 'no-citations' : 'ok' )
			: 'invalid-references';

		return [
			'ok'            => empty( $missing ),
			'total_markers' => $total,
			'max_index'     => $max_index,
			'missing'       => array_values( array_unique( $missing ) ),
			'orphans'       => $orphans,
			'kg_markers'    => array_values( array_unique( $kg_markers ) ),
			'reason'        => $reason,
		];
	}
}

/**
 * Phase 0.7 — Walk a JSON document tree (bzdoc schema) and validate citations
 * embedded in any text fields. Adds support for `[note:N]` markers (pinned notes).
 *
 * @param mixed $json_data Decoded JSON (array/object) — typically bzdoc schema.
 * @param array $sources   Sources list (see bizcity_kg_validate_citations).
 * @param array $kg_items  KG entity list.
 * @param array $notes     Optional pinned-note list, each item with `id`.
 * @return array {
 *   ok: bool, total_text_fields: int, total_markers: int, total_note_markers: int,
 *   missing: array, hallucinated_fields: array, score: float (0-1), reason: string
 * }
 */
if ( ! function_exists( 'bizcity_kg_validate_citations_in_json' ) ) {
	function bizcity_kg_validate_citations_in_json( $json_data, array $sources = [], array $kg_items = [], array $notes = [] ) {
		// Build lookup of valid note ids.
		$valid_note_ids = [];
		foreach ( $notes as $n ) {
			$nid = (int) ( is_array( $n ) ? ( $n['id'] ?? 0 ) : ( is_object( $n ) ? ( $n->id ?? 0 ) : 0 ) );
			if ( $nid > 0 ) $valid_note_ids[ $nid ] = true;
		}

		$state = [
			'text_fields'        => 0,
			'fields_with_marker' => 0,
			'markers'            => 0,
			'note_markers'       => 0,
			'missing'            => [],
			'hallucinated'       => [], // text fields with prose but zero citation markers
		];

		// Field name allow-list — only validate fields that typically carry prose.
		$prose_keys = [
			'content', 'text', 'body', 'note', 'description', 'summary',
			'subtitle', 'caption', 'label', 'value', 'paragraph', 'bullet',
			'speaker_notes', 'speakerNotes', 'notes', 'cell',
		];

		$walker = function ( $node, $key_hint ) use ( &$walker, &$state, $sources, $kg_items, $valid_note_ids, $prose_keys ) {
			if ( is_array( $node ) ) {
				foreach ( $node as $k => $v ) {
					$walker( $v, is_string( $k ) ? $k : $key_hint );
				}
				return;
			}
			if ( is_object( $node ) ) {
				foreach ( get_object_vars( $node ) as $k => $v ) {
					$walker( $v, $k );
				}
				return;
			}
			if ( ! is_string( $node ) ) return;

			$is_prose = in_array( strtolower( (string) $key_hint ), $prose_keys, true );
			if ( ! $is_prose ) return;

			$plain = trim( $node );
			if ( $plain === '' || mb_strlen( $plain ) < 24 ) return;

			$state['text_fields']++;

			// Validate normal markers via the existing validator.
			$res = bizcity_kg_validate_citations( $plain, $sources, $kg_items );
			$has_marker = $res['total_markers'] > 0;

			// Additional [note:N] markers.
			preg_match_all( '/\[note:(\d+)\]/', $plain, $m_note );
			$note_count = count( $m_note[1] );
			if ( $note_count > 0 ) {
				$state['note_markers'] += $note_count;
				$has_marker = true;
				if ( ! empty( $valid_note_ids ) ) {
					foreach ( $m_note[1] as $nid_str ) {
						$nid = (int) $nid_str;
						if ( empty( $valid_note_ids[ $nid ] ) ) {
							$state['missing'][] = "note:{$nid}";
						}
					}
				}
			}

			$state['markers'] += $res['total_markers'];
			if ( ! empty( $res['missing'] ) ) {
				$state['missing'] = array_merge( $state['missing'], $res['missing'] );
			}

			if ( $has_marker ) {
				$state['fields_with_marker']++;
			} else {
				$state['hallucinated'][] = mb_substr( $plain, 0, 120 );
			}
		};

		$walker( $json_data, '' );

		$tf = $state['text_fields'];
		$score = $tf > 0 ? round( $state['fields_with_marker'] / $tf, 3 ) : 1.0;
		$missing = array_values( array_unique( $state['missing'] ) );

		return [
			'ok'                  => empty( $missing ) && ( $tf === 0 || $score >= 0.5 ),
			'total_text_fields'   => $tf,
			'fields_with_marker'  => $state['fields_with_marker'],
			'total_markers'       => $state['markers'],
			'total_note_markers'  => $state['note_markers'],
			'missing'             => $missing,
			'hallucinated_fields' => array_slice( $state['hallucinated'], 0, 12 ),
			'score'               => $score,
			'reason'              => empty( $missing )
				? ( $score >= 0.5 ? 'ok' : ( $tf === 0 ? 'no-prose' : 'low-coverage' ) )
				: 'invalid-references',
		];
	}
}

/* ───────────────────────────────────────────────────────────────────────
 * PHASE-0.21 Wave 1 — Local `.bin` storage path helpers.
 *
 * Vector embeddings live as binary float32 files under:
 *     {uploads basedir}/bizcity-kg/{kind}/{uid}.bin
 *     {uploads basedir}/bizcity-kg/{kind}/{uid}.idx.json
 *
 * Multisite-safe: wp_upload_dir() returns sites/{blog_id}/ subpath when
 * invoked inside switch_to_blog() context. Single-site falls back to a
 * flat uploads/bizcity-kg/ tree.
 *
 * IMPORTANT: only RELATIVE path (e.g. 'gurus/{uuid}.bin') is stored in
 * bizcity_characters.bin_path so site migration / domain change stays safe.
 * Use bizcity_kg_resolve_path() at runtime to materialise the absolute path.
 * ─────────────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'bizcity_kg_storage_dir' ) ) {
	/**
	 * Absolute base directory for KG file artifacts on this blog.
	 * Auto-creates and stamps an .htaccess deny + index.php guard.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	function bizcity_kg_storage_dir() {
		$upload = wp_upload_dir( null, false );
		$base   = trailingslashit( $upload['basedir'] ) . 'bizcity-kg/';
		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
			// Defensive — block direct HTTP access to .bin/.idx.json files.
			@file_put_contents( $base . '.htaccess', "Deny from all\n" );
			@file_put_contents( $base . 'index.php', "<?php\n// Silence is golden.\n" );
		}
		return $base;
	}
}

if ( ! function_exists( 'bizcity_kg_storage_path' ) ) {
	/**
	 * Absolute file path for a guru/notebook artifact, kind ∈ { 'gurus', 'notebooks', 'tmp' }.
	 *
	 * @param string $kind 'gurus' | 'notebooks' | 'tmp'
	 * @param string $uid  guru_uuid (gurus) | notebook id-string (notebooks) | free string (tmp)
	 * @param string $ext  Extension WITHOUT dot. Default 'bin'.
	 * @return string Absolute path. Parent dir created.
	 */
	function bizcity_kg_storage_path( $kind, $uid, $ext = 'bin' ) {
		$kind = preg_replace( '/[^a-z0-9_\-]/i', '', (string) $kind ) ?: 'tmp';
		$uid  = preg_replace( '/[^A-Za-z0-9._\-]/', '', (string) $uid );
		$ext  = preg_replace( '/[^a-z0-9.]/i', '', (string) $ext ) ?: 'bin';
		$dir  = bizcity_kg_storage_dir() . $kind . '/';
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return $dir . $uid . '.' . $ext;
	}
}

if ( ! function_exists( 'bizcity_kg_resolve_path' ) ) {
	/**
	 * Resolve a relative path stored in DB (e.g. 'gurus/{uuid}.bin') to absolute.
	 * Returns null if the resolved file does not exist.
	 *
	 * @param string $relative Relative path under uploads/bizcity-kg/, no leading slash.
	 * @return string|null
	 */
	function bizcity_kg_resolve_path( $relative ) {
		if ( ! is_string( $relative ) || $relative === '' ) {
			return null;
		}
		// Reject directory-traversal attempts.
		if ( strpos( $relative, '..' ) !== false ) {
			return null;
		}
		$abs = bizcity_kg_storage_dir() . ltrim( $relative, '/\\' );
		return file_exists( $abs ) ? $abs : null;
	}
}

if ( ! function_exists( 'bizcity_kg_relative_path' ) ) {
	/**
	 * Inverse of bizcity_kg_resolve_path() — convert absolute back to DB-storable relative.
	 *
	 * @param string $absolute
	 * @return string|null
	 */
	function bizcity_kg_relative_path( $absolute ) {
		$base = bizcity_kg_storage_dir();
		if ( strpos( $absolute, $base ) !== 0 ) {
			return null;
		}
		return substr( $absolute, strlen( $base ) );
	}
}

/* -----------------------------------------------------------------------------
 * Phase 0.21 Wave 2 — Vector .bin path resolver
 *
 * Strategy: 80% convention (derive from scope_type+uuid), 20% manual override
 * stored in wp_options for edge cases (S3 offload, custom mount, A/B versioning).
 *
 * Lookup order in bizcity_kg_vector_bin_path():
 *   1. Override map in wp_options['bizcity_kg_bin_overrides'][key] → use that
 *   2. Convention path bizcity_kg_storage_path(scope_type, uuid, 'bin')
 *
 * Override key format: "{scope_type}/{uuid}" (e.g. "notebooks/c5f60b56-...")
 * Override value: relative path under bizcity_kg_storage_dir() OR absolute path.
 * --------------------------------------------------------------------------- */

if ( ! function_exists( 'bizcity_kg_bin_override_option_name' ) ) {
	/**
	 * Single wp_options row holding all manual overrides.
	 * Stored as PHP-serialized array keyed by "{scope_type}/{uuid}".
	 */
	function bizcity_kg_bin_override_option_name() {
		return 'bizcity_kg_bin_overrides';
	}
}

if ( ! function_exists( 'bizcity_kg_bin_overrides_all' ) ) {
	/**
	 * Read full override map from wp_options (cached by WP).
	 * @return array<string,string>
	 */
	function bizcity_kg_bin_overrides_all() {
		$raw = get_option( bizcity_kg_bin_override_option_name(), [] );
		return is_array( $raw ) ? $raw : [];
	}
}

if ( ! function_exists( 'bizcity_kg_bin_override_key' ) ) {
	/**
	 * Build canonical override key. Returns null on bad input.
	 * @param string $scope_type  e.g. 'notebooks', 'gurus', 'sources'
	 * @param string $uuid
	 * @return string|null
	 */
	function bizcity_kg_bin_override_key( $scope_type, $uuid ) {
		$scope_type = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $scope_type ) );
		$uuid       = strtolower( (string) $uuid );
		if ( '' === $scope_type || ! preg_match( '/^[0-9a-f-]{36}$/', $uuid ) ) {
			return null;
		}
		return $scope_type . '/' . $uuid;
	}
}

if ( ! function_exists( 'bizcity_kg_vector_bin_path' ) ) {
	/**
	 * Resolve absolute filesystem path of a `.bin` file for a scope.
	 *
	 * Order:
	 *   1. Manual override (wp_options) — relative or absolute
	 *   2. Convention: {storage}/{scope_type}/{uuid}.bin
	 *
	 * @param string $scope_type 'notebooks' | 'gurus' | 'sources'
	 * @param string $uuid
	 * @return string|null absolute path (file may or may not exist)
	 */
	function bizcity_kg_vector_bin_path( $scope_type, $uuid ) {
		$key = bizcity_kg_bin_override_key( $scope_type, $uuid );
		if ( null === $key ) { return null; }

		// 1) Manual override.
		$overrides = bizcity_kg_bin_overrides_all();
		if ( ! empty( $overrides[ $key ] ) && is_string( $overrides[ $key ] ) ) {
			$override = $overrides[ $key ];
			// Absolute path? trust it.
			if ( preg_match( '#^([A-Za-z]:[\\\\/]|/)#', $override ) ) {
				return $override;
			}
			// Relative under storage dir.
			$abs = bizcity_kg_storage_dir() . ltrim( $override, '/\\' );
			return $abs;
		}

		// 2) Convention — bizcity_kg_storage_path() already returns an absolute path.
		return bizcity_kg_storage_path( $scope_type, $uuid, 'bin' );
	}
}

if ( ! function_exists( 'bizcity_kg_vector_bin_set_override' ) ) {
	/**
	 * Set or clear a manual override path for a scope.
	 *
	 * @param string      $scope_type
	 * @param string      $uuid
	 * @param string|null $path       relative or absolute; null = remove override
	 * @return bool
	 */
	function bizcity_kg_vector_bin_set_override( $scope_type, $uuid, $path ) {
		$key = bizcity_kg_bin_override_key( $scope_type, $uuid );
		if ( null === $key ) { return false; }

		$overrides = bizcity_kg_bin_overrides_all();

		if ( null === $path || '' === $path ) {
			if ( isset( $overrides[ $key ] ) ) {
				unset( $overrides[ $key ] );
				return update_option( bizcity_kg_bin_override_option_name(), $overrides, false );
			}
			return true;
		}

		$path = trim( (string) $path );
		// Reject path traversal in relative form.
		if ( ! preg_match( '#^([A-Za-z]:[\\\\/]|/)#', $path ) && false !== strpos( $path, '..' ) ) {
			return false;
		}
		$overrides[ $key ] = $path;
		return update_option( bizcity_kg_bin_override_option_name(), $overrides, false );
	}
}

if ( ! function_exists( 'bizcity_kg_vector_bin_resolve_with_meta' ) ) {
	/**
	 * Same as bizcity_kg_vector_bin_path() but returns metadata about WHICH source served the path.
	 * Useful for diagnostics + admin UI.
	 *
	 * @return array|null { path, source: 'override'|'convention', exists: bool }
	 */
	function bizcity_kg_vector_bin_resolve_with_meta( $scope_type, $uuid ) {
		$key = bizcity_kg_bin_override_key( $scope_type, $uuid );
		if ( null === $key ) { return null; }

		$overrides = bizcity_kg_bin_overrides_all();
		if ( ! empty( $overrides[ $key ] ) ) {
			$abs = bizcity_kg_vector_bin_path( $scope_type, $uuid );
			return [
				'path'   => $abs,
				'source' => 'override',
				'exists' => $abs && file_exists( $abs ),
			];
		}
		$abs = bizcity_kg_vector_bin_path( $scope_type, $uuid );
		return [
			'path'   => $abs,
			'source' => 'convention',
			'exists' => $abs && file_exists( $abs ),
		];
	}
}

if ( ! function_exists( 'bizcity_kg_is_outhouse' ) ) {
	/**
	 * Determine whether a KG row (source / chunk / entity / relation) is
	 * "out-house" — i.e. imported from another site / marketplace bundle and
	 * therefore READ-ONLY on this install.
	 *
	 * Per PHASE-0-RULE-VECTOR-FILE-STORE.md §2.1:
	 *   in-house  := origin_site == home_url() AND imported_from IS NULL
	 *   out-house := origin_site != home_url() OR imported_from IS NOT NULL
	 *
	 * Rows from before Wave 1.5 (no marker columns) are treated as in-house
	 * to preserve backward compatibility.
	 *
	 * @param array|object|null $row Row from kg_sources / kg_source_chunks / kg_entities / kg_relations.
	 * @return bool
	 */
	function bizcity_kg_is_outhouse( $row ) {
		if ( empty( $row ) ) { return false; }
		$arr = is_object( $row ) ? get_object_vars( $row ) : (array) $row;

		$imported_from = isset( $arr['imported_from'] ) ? trim( (string) $arr['imported_from'] ) : '';
		if ( $imported_from !== '' ) { return true; }

		$origin = isset( $arr['origin_site'] ) ? trim( (string) $arr['origin_site'] ) : '';
		if ( $origin === '' ) { return false; } // legacy row → in-house

		return rtrim( $origin, '/' ) !== rtrim( (string) home_url(), '/' );
	}
}

