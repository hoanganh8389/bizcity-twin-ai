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
