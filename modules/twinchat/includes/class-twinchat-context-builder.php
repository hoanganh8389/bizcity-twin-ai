<?php
/**
 * Bizcity Twin AI — TwinChat Context Builder
 *
 * Implements the "Twin Context Bridge" pattern from PHASE-0-RULE-BRAIN-UNIFICATION.md.
 * Single entry point that other surfaces (TwinChat, WebChat shim, Intent) will reuse
 * to assemble the unified context payload that flows to Smart Gateway.
 *
 * Returned shape:
 *   [
 *     'session'       => [...],
 *     'focus_state'   => [...],
 *     'kg_summary'    => [
 *        'mode'           => 'hybrid|vector|graph|skip',
 *        'passages'       => [...],
 *        'cited_entities' => [...],
 *        'relations'      => [...],
 *        'sources'        => [...],
 *     ],
 *     'history'       => [...],
 *     'system_prompt' => '...',
 *     'user_message'  => '...',
 *   ]
 *
 * Contracts honored: C1, C4, C5, C6, C7, C9.
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat
 * @since 2026-05-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Context_Builder {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Build full context for a chat turn.
	 *
	 * @param array $args {
	 *   @type int    notebook_id   KG notebook id (acts as scope_id when scope_type=notebook).
	 *   @type int    user_id       Current WP user id.
	 *   @type string session_id    Stable session UUID for memory grouping.
	 *   @type string user_message  The new user prompt.
	 *   @type array  history       Prior turns: [['role'=>'user'|'assistant','content'=>'...']].
	 *   @type bool   use_kg        Whether to query KG-Hub.
	 *   @type int[]  source_ids    Selected SmartSources (filter passages).
	 *   @type bool   enable_thinking
	 * }
	 * @return array
	 */
	public function build( array $args ) {
		$args = array_merge( [
			'notebook_id'     => 0,
			'user_id'         => get_current_user_id(),
			'session_id'      => '',
			'user_message'    => '',
			'history'         => [],
			'use_kg'          => true,
			'source_ids'      => [],
			'enable_thinking' => false,
		], $args );

		$notebook_id = (int) $args['notebook_id'];
		$user_id     = (int) $args['user_id'];
		$session_id  = (string) $args['session_id'];
		$query       = (string) $args['user_message'];

		// ── Focus Router (Contract 4) — decide retrieval mode BEFORE KG query.
		$retrieval_mode = $this->decide_retrieval_mode( $query, (bool) $args['use_kg'] );

		// ── KG Retrieval (Contract 1) — only when needed.
		$kg_summary = [
			'mode'           => $retrieval_mode,
			'passages'       => [],
			'cited_entities' => [],
			'relations'      => [],
			'sources'        => [],
			'subgraph'       => [ 'nodes' => [], 'links' => [] ],
		];

		if ( $retrieval_mode !== 'skip' && $notebook_id > 0 && class_exists( 'BizCity_KG_Retriever' ) ) {
			$retr = BizCity_KG_Retriever::instance()->ask( $notebook_id, $query, [
				'answer'         => false, // we only want context, not an answer here
				'seed_entities'  => 4,
				'seed_relations' => 12,
				'rerank_top_k'   => 5,
				'expand_hops'    => 1,
			] );

			if ( is_array( $retr ) ) {
				$kg_summary['passages']       = isset( $retr['passages'] ) && is_array( $retr['passages'] ) ? array_slice( $retr['passages'], 0, 8 ) : [];
				$kg_summary['cited_entities'] = isset( $retr['query_entities'] ) && is_array( $retr['query_entities'] ) ? $retr['query_entities'] : [];
				$kg_summary['subgraph']       = isset( $retr['subgraph'] ) && is_array( $retr['subgraph'] ) ? $retr['subgraph'] : $kg_summary['subgraph'];
				$kg_summary['relations']      = isset( $retr['reranked_relations'] ) && is_array( $retr['reranked_relations'] )
					? $retr['reranked_relations']
					: ( isset( $retr['retrieval_detail']['relation_texts'] ) ? $retr['retrieval_detail']['relation_texts'] : [] );
			}

			// Optional source-id filter — narrow passages to selected sources.
			if ( ! empty( $args['source_ids'] ) && ! empty( $kg_summary['passages'] ) ) {
				$wanted = array_flip( array_map( 'intval', (array) $args['source_ids'] ) );
				$kg_summary['passages'] = array_values( array_filter( $kg_summary['passages'], static function ( $p ) use ( $wanted ) {
					$sid = isset( $p['source_id'] ) ? (int) $p['source_id'] : 0;
					return isset( $wanted[ $sid ] );
				} ) );
			}

			// Enrich passages with source title/heading_path for citation chips.
			$kg_summary['sources'] = $this->enrich_sources_for_citations( $kg_summary['passages'] );

			// Sprint 4.4i — build KG entity citation list from subgraph nodes (top 8).
			$kg_summary['kg_citations'] = $this->build_kg_citations( $kg_summary['subgraph'] );
		}

		// ── Focus state (Contract 7).
		$focus_state = [
			'mode'         => $retrieval_mode === 'skip' ? 'chat' : 'knowledge',
			'scope_type'   => 'notebook',
			'scope_id'     => $notebook_id,
			'character_id' => 0,
			'project_id'   => 0,
		];
		$focus_state = apply_filters( 'bizcity_twinchat_focus_state', $focus_state, $args );

		// ── Session header.
		$session = [
			'session_id'  => $session_id,
			'user_id'     => $user_id,
			'started_at'  => current_time( 'mysql', true ),
		];

		// ── History compression (keep last 10 turns).
		$history = is_array( $args['history'] ) ? $args['history'] : [];
		if ( count( $history ) > 10 ) {
			$history = array_slice( $history, -10 );
		}

		// ── Notebook source list (for meta-queries like "tôi đã up file gì").
		$notebook_sources = [];
		if ( $notebook_id > 0 && class_exists( 'BizCity_KG' ) ) {
			$src_list = BizCity_KG::list_sources(
				[ 'plugin' => 'twinchat', 'scope_id' => $notebook_id ],
				[ 'limit' => 50 ]
			);
			if ( is_array( $src_list ) && ! is_wp_error( $src_list ) ) {
				foreach ( $src_list as $s ) {
					$title = (string) ( $s['title'] ?? $s['label'] ?? $s['origin_url'] ?? '' );
					if ( $title !== '' ) {
						$notebook_sources[] = $title;
					}
				}
			}
		}

		// ── Phase 0.7 — Pinned notes (Yêu cầu ghi chú) injected as soft tone hints.
		$pinned_notes = [];
		$inject_notes = (bool) apply_filters( 'bizcity_twinchat_inject_notes', get_option( 'bizcity_twinchat_inject_notes', true ) );
		if ( $inject_notes && $notebook_id > 0 && class_exists( 'BCN_Notes' ) ) {
			$pinned_notes = $this->load_pinned_notes( $notebook_id );
		}

		// ── Build the system prompt that wraps it all.
		$system_prompt = $this->build_system_prompt( $kg_summary, $focus_state, $notebook_sources, $pinned_notes );

		return apply_filters( 'bizcity_twinchat_context_payload', [
			'session'         => $session,
			'focus_state'     => $focus_state,
			'kg_summary'      => $kg_summary,
			'pinned_notes'    => $pinned_notes,
			'history'         => $history,
			'system_prompt'   => $system_prompt,
			'user_message'    => $query,
			'enable_thinking' => (bool) $args['enable_thinking'],
		], $args );
	}

	/* ──────────────────────────────────────────────────────────────────── */

	/**
	 * Lightweight heuristic Focus Router.
	 * Replace later with full BizCity_Twin_Focus_Router when available.
	 */
	private function decide_retrieval_mode( $query, $use_kg ) {
		if ( ! $use_kg ) {
			return 'skip';
		}
		$q = trim( (string) $query );
		if ( $q === '' || mb_strlen( $q ) < 3 ) {
			return 'skip';
		}
		// Sprint 4.5h — main-task gate. Atomic intents (format/classify) skip KG.
		if ( function_exists( 'bizcity_kg_is_main_task' ) && ! bizcity_kg_is_main_task( 'twinchat', 'chat' ) ) {
			return 'skip';
		}
		// Allow filter to override.
		$mode = apply_filters( 'bizcity_twinchat_retrieval_mode', 'hybrid', $q );
		$valid = [ 'vector', 'graph', 'hybrid', 'skip' ];
		return in_array( $mode, $valid, true ) ? $mode : 'hybrid';
	}

	/**
	 * Build a chat system prompt embedding KG context.
	 *
	 * @param array $notebook_sources list of source titles/URLs in this notebook
	 */
	private function build_system_prompt( array $kg_summary, array $focus_state, array $notebook_sources = [], array $pinned_notes = [] ) {
		$lines = [];
		$lines[] = 'You are TwinChat, an assistant grounded in the user\'s personal Knowledge Graph.';
		$lines[] = 'Always answer in the user\'s language. Be concise.';

		$passages = isset( $kg_summary['passages'] ) ? $kg_summary['passages'] : [];
		if ( ! empty( $passages ) ) {
			// PHASE 0.6 CITATION V2 — strict literal-ID contract.
			// Each passage block is labelled with REAL DB ids `[src:{source_id}#p{passage_id}]`
			// so the LLM never has to map ordinals → IDs (the FE renderer also looks up by
			// these literal IDs strictly). See PHASE-0.6-CITATION-V2.md §2.
			$lines[] = '';
			$lines[] = '=== Knowledge Context ===';
			$lines[] = 'Each passage starts with its citation ID in the form [src:{SOURCE_ID}#p{PASSAGE_ID}]. Use that EXACT id when citing.';
			$allowed_ids = [];
			$truncate_at = (int) apply_filters( 'bizcity_twinchat_passage_truncate_chars', 0 ); // 0 = no truncate
			foreach ( $passages as $p ) {
				$sid = isset( $p['source_id'] ) ? (int) $p['source_id'] : 0;
				// KG retriever returns passage rows with `id` (the passage primary key);
				// `enrich_sources_for_citations()` later renames it to `passage_id`. Accept both.
				$pid = isset( $p['passage_id'] ) ? (int) $p['passage_id'] : ( isset( $p['id'] ) ? (int) $p['id'] : 0 );
				if ( $sid <= 0 || $pid <= 0 ) {
					// Without real IDs this passage cannot be cited — skip (prevents fabricated cites).
					continue;
				}
				$content = isset( $p['content'] ) ? (string) $p['content'] : '';
				$content = trim( preg_replace( '/\s+/', ' ', $content ) );
				if ( $truncate_at > 0 && mb_strlen( $content ) > $truncate_at ) {
					$content = mb_substr( $content, 0, $truncate_at ) . '…';
				}
				$title   = isset( $p['source_title'] ) ? (string) $p['source_title'] : '';
				$heading = '';
				if ( ! empty( $p['heading_path'] ) && is_array( $p['heading_path'] ) ) {
					$heading = ' › ' . implode( ' › ', array_map( 'strval', $p['heading_path'] ) );
				}
				$cite_id       = sprintf( 'src:%d#p%d', $sid, $pid );
				$allowed_ids[] = $cite_id;
				$lines[] = sprintf(
					'[%s]%s%s',
					$cite_id,
					$title !== '' ? ' — ' . $title . $heading : $heading,
					"\n" . $content
				);
				$lines[] = '';
			}

			if ( ! empty( $allowed_ids ) ) {
				$lines[] = '=== Allowed citation IDs ===';
				$lines[] = implode( ', ', $allowed_ids );
				$lines[] = '';
				$lines[] = '=== CITATION RULES ===';
				$lines[] = '1. Cite a passage with [src:{SOURCE_ID}#p{PASSAGE_ID}] EXACTLY as listed above.';
				$lines[] = '2. ONLY use IDs from the "Allowed citation IDs" list. NEVER invent IDs.';
				$lines[] = '3. Cite ONLY when the passage directly supports the claim. If unsure, OMIT the marker.';
				$lines[] = '4. It is BETTER to omit a citation than to attach a wrong one.';
				$lines[] = '5. For Knowledge-Graph entities, use [K1], [K2]… as listed in the "Related entities" block.';
			}
		} else {
			// No retrieval context — explicitly forbid citations to prevent hallucinated markers.
			$lines[] = 'KHÔNG có nguồn nào được truy xuất cho câu hỏi này. TUYỆT ĐỐI KHÔNG chèn marker [src:...], [K...], [note:...] hay [draft:...] trong câu trả lời. Trả lời bình thường, không trích dẫn.';
		}

		$entities = isset( $kg_summary['cited_entities'] ) ? $kg_summary['cited_entities'] : [];
		if ( ! empty( $entities ) ) {
			$lines[] = '';
			$lines[] = 'Related entities (use [K1], [K2]… to cite when referring to a graph entity):';
			$kg_cits  = isset( $kg_summary['kg_citations'] ) && is_array( $kg_summary['kg_citations'] ) ? $kg_summary['kg_citations'] : [];
			if ( ! empty( $kg_cits ) ) {
				foreach ( $kg_cits as $kc ) {
					$lines[] = sprintf( '[K%d] %s%s', (int) $kc['index'], $kc['name'], $kc['type'] !== '' ? ' (' . $kc['type'] . ')' : '' );
				}
			} else {
				$lines[] = implode( ', ', array_slice( $entities, 0, 12 ) );
			}
		}

		$relations = isset( $kg_summary['relations'] ) ? $kg_summary['relations'] : [];
		if ( ! empty( $relations ) ) {
			$rels = [];
			foreach ( array_slice( $relations, 0, 8 ) as $r ) {
				if ( is_string( $r ) ) {
					$rels[] = $r;
				} elseif ( is_array( $r ) && isset( $r['relation_text'] ) ) {
					$rels[] = (string) $r['relation_text'];
				}
			}
			if ( ! empty( $rels ) ) {
				$lines[] = 'Known relations:';
				foreach ( $rels as $r ) {
					$lines[] = ' - ' . $r;
				}
			}
		}

		if ( ! empty( $notebook_sources ) ) {
			$lines[] = '';
			$lines[] = '=== Danh sách tài liệu đã tải lên trong notebook này ==';
			foreach ( $notebook_sources as $i => $title ) {
				$lines[] = sprintf( '%d. %s', $i + 1, $title );
			}
			$lines[] = 'Khi người dùng hỏi "tôi đã upload gì", "file nào", "tôi có nguồn gì", hãy trả lời bằng danh sách trên.';
		}

		// Phase 0.7 — Pinned notes (Yêu cầu ghi chú) for tone & style hints.
		if ( ! empty( $pinned_notes ) ) {
			$lines[] = '';
			$lines[] = '=== Ghi chú đã ghim của người dùng (Pinned notes) ===';
			$lines[] = 'Các ghi chú dưới đây phản ánh ngữ điệu / yêu cầu cá nhân, KHÔNG phải nguồn sự kiện. ';
			$lines[] = 'Vẫn phải dùng đúng id [src:{SOURCE_ID}#p{PASSAGE_ID}] từ Allowed citation IDs cho mọi sự kiện. Có thể trích lại bằng [note:N] khi nhắc đến yêu cầu trong note.';
			foreach ( $pinned_notes as $n ) {
				$nid     = (int) ( $n['id'] ?? 0 );
				$ntitle  = (string) ( $n['title'] ?? '' );
				$ncontent= (string) ( $n['content'] ?? '' );
				$snippet = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $ncontent ) ) );
				if ( mb_strlen( $snippet ) > 280 ) $snippet = mb_substr( $snippet, 0, 280 ) . '…';
				$lines[] = sprintf( '[note:%d] %s — %s', $nid, $ntitle !== '' ? $ntitle : '(không tiêu đề)', $snippet );
			}
		}

		$lines[] = '';
		$lines[] = 'Rules:';
		$lines[] = '- If the answer is not in the context, say so.';
		$lines[] = '- Cite passages with [src:{SOURCE_ID}#p{PASSAGE_ID}] using ONLY ids from Allowed citation IDs — see CITATION RULES above. Omit the marker if no allowed id supports the claim.';
		$lines[] = '- Use [K1], [K2]… inline citations when referring to a Knowledge Graph entity by name.';

		return implode( "\n", $lines );
	}

	/**
	 * Enrich raw passage hits with metadata for the React `sources` event.
	 */
	private function enrich_sources_for_citations( array $passages ) {
		if ( empty( $passages ) ) {
			return [];
		}
		global $wpdb;
		$source_ids = [];
		foreach ( $passages as $p ) {
			if ( isset( $p['source_id'] ) ) {
				$source_ids[] = (int) $p['source_id'];
			}
		}
		$source_ids = array_unique( array_filter( $source_ids ) );
		$titles_by_id = [];
		if ( ! empty( $source_ids ) ) {
			// Cross-table title lookup. Source rows can live in any registered
			// Smart Sources table (twinchat_sources, webchat_sources, knowledge_sources, …).
			// Prefer the unified KG-Hub helper; fall back to a direct twinchat-sources
			// query only when that helper isn't available.
			if ( class_exists( 'BizCity_KG_Source_Service' ) ) {
				$svc = BizCity_KG_Source_Service::instance();
				foreach ( $source_ids as $sid ) {
					$meta = $svc->lookup_source_meta( (int) $sid );
					if ( $meta && ! empty( $meta['title'] ) ) {
						$titles_by_id[ (int) $sid ] = (string) $meta['title'];
					}
				}
			}
			// Top-up with twinchat_sources rows for IDs we still haven't resolved.
			$missing = array_values( array_diff( $source_ids, array_keys( $titles_by_id ) ) );
			if ( ! empty( $missing ) && class_exists( 'BizCity_TwinChat_Sources_Database' ) ) {
				$table = BizCity_TwinChat_Sources_Database::instance()->table_sources();
				$tbl_check = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
				if ( $tbl_check === $table ) {
					$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, title FROM {$table} WHERE id IN ({$placeholders})",
						$missing
					), ARRAY_A );
					if ( is_array( $rows ) ) {
						foreach ( $rows as $r ) {
							$titles_by_id[ (int) $r['id'] ] = (string) $r['title'];
						}
					}
				}
			}
		}

		$out = [];
		$idx = 1;
		foreach ( $passages as $p ) {
			$sid     = isset( $p['source_id'] ) ? (int) $p['source_id'] : 0;
			$content = isset( $p['content'] ) ? (string) $p['content'] : '';
			$snippet = trim( preg_replace( '/\s+/', ' ', $content ) );
			if ( mb_strlen( $snippet ) > 220 ) {
				$snippet = mb_substr( $snippet, 0, 220 ) . '…';
			}
			$heading_path = isset( $p['heading_path'] ) && is_array( $p['heading_path'] ) ? $p['heading_path'] : [];
			// Sprint 4.4j — server-side slugify of last heading so FE scroll lookup is exact.
			$heading_id = '';
			if ( ! empty( $heading_path ) ) {
				$last       = (string) end( $heading_path );
				$heading_id = $this->slugify_heading( $last );
			}
			$out[] = [
				'index'             => $idx,
				'passage_id'        => isset( $p['id'] ) ? (int) $p['id'] : 0,
				'source_id'         => $sid,
				'source_title'      => isset( $titles_by_id[ $sid ] ) ? $titles_by_id[ $sid ] : ( 'Source #' . $sid ),
				'source_type'       => 'vector',
				'content_snippet'   => $snippet,
				'page_no'           => isset( $p['page_no'] ) ? (int) $p['page_no'] : null,
				'heading_path'      => $heading_path,
				'heading_id'        => $heading_id,
				'related_entities'  => isset( $p['related_entities'] ) && is_array( $p['related_entities'] ) ? $p['related_entities'] : [],
			];
			$idx++;
		}
		return $out;
	}

	/**
	 * Sprint 4.4i — build KG entity citation list from subgraph node array.
	 * Returns [{ index, entity_id, name, type, related_passage_indices }].
	 * FE renders these as `[K\d+]` chips that activate the Graph tab.
	 */
	private function build_kg_citations( $subgraph ) {
		if ( ! is_array( $subgraph ) || empty( $subgraph['nodes'] ) ) {
			return [];
		}
		$out = [];
		$idx = 1;
		foreach ( array_slice( $subgraph['nodes'], 0, 8 ) as $n ) {
			if ( empty( $n['id'] ) ) {
				continue;
			}
			$out[] = [
				'index'     => $idx,
				'entity_id' => (int) $n['id'],
				'name'      => isset( $n['name'] )  ? (string) $n['name']  : '',
				'type'      => isset( $n['type'] )  ? (string) $n['type']  : '',
			];
			$idx++;
		}
		return $out;
	}

	/**
	 * Phase 0.7 — Load top pinned + starred notes for a notebook.
	 * Tier order: chat_pinned > manual > auto_pinned > research_auto.
	 *
	 * @return array<int, array{id:int,title:string,content:string,note_type:string}>
	 */
	private function load_pinned_notes( $notebook_id ) {
		$out = [];
		$limit = (int) apply_filters( 'bizcity_twinchat_pinned_notes_limit', 20 );
		try {
			$svc = new BCN_Notes();
			$pid = 'tc_' . (int) $notebook_id;
			$rows = $svc->get_by_project( $pid );
			if ( ! is_array( $rows ) ) return $out;

			// Tier sort.
			$tier_rank = [
				'chat_pinned'      => 1,
				'manual'           => 2,
				'auto_pinned'      => 3,
				'studio_generated' => 4,
				'research_auto'    => 5,
			];
			usort( $rows, static function ( $a, $b ) use ( $tier_rank ) {
				$ra = $tier_rank[ $a->note_type ?? '' ] ?? 9;
				$rb = $tier_rank[ $b->note_type ?? '' ] ?? 9;
				if ( $ra !== $rb ) return $ra <=> $rb;
				$sa = (int) ( $a->is_starred ?? 0 );
				$sb = (int) ( $b->is_starred ?? 0 );
				if ( $sa !== $sb ) return $sb <=> $sa;
				return strcmp( (string) ( $b->created_at ?? '' ), (string) ( $a->created_at ?? '' ) );
			} );

			foreach ( array_slice( $rows, 0, $limit ) as $r ) {
				$out[] = [
					'id'        => (int) ( $r->id ?? 0 ),
					'title'     => (string) ( $r->title ?? '' ),
					'content'   => (string) ( $r->content ?? '' ),
					'note_type' => (string) ( $r->note_type ?? 'manual' ),
				];
			}
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinChat][load_pinned_notes] ' . $e->getMessage() );
			}
		}
		return $out;
	}

	/**
	 * Sprint 4.4j — PHP slugify mirroring FE NexusDocumentViewer.generateHeadingId().
	 * Algorithm: lowercase → NFD normalize → strip combining marks → đ→d →
	 * keep [a-z0-9 -] → collapse whitespace to '-' → cap at 80 chars.
	 */
	private function slugify_heading( $text ) {
		$text = (string) $text;
		if ( $text === '' ) {
			return '';
		}
		$text = mb_strtolower( $text, 'UTF-8' );
		if ( class_exists( 'Normalizer' ) ) {
			$text = \Normalizer::normalize( $text, \Normalizer::FORM_D );
			$text = preg_replace( '/[\x{0300}-\x{036f}]/u', '', $text );
		}
		$text = str_replace( [ 'đ', 'Đ' ], [ 'd', 'd' ], $text );
		// Keep [a-z0-9], whitespace, hyphen — drop everything else.
		$text = preg_replace( '/[^a-z0-9\s-]/u', '', $text );
		// Collapse whitespace runs into single hyphen.
		$text = preg_replace( '/\s+/u', '-', $text );
		return mb_substr( $text, 0, 80, 'UTF-8' );
	}
}
