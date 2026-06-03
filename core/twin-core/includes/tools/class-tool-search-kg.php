<?php
/**
 * Bizcity Twin AI — Tool: search_kg
 *
 * Sprint 4.7b — Tool wrap `BizCity_KG_Retriever::ask()` cho Twin_Agent_Loop.
 * Output gồm passages + citation IDs `[a3x9]` để LLM cite trong câu trả lời.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core\Tools
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Search_KG implements BizCity_Twin_Tool {

	public function name(): string {
		return 'search_kg';
	}

	public function description(): string {
		return 'Search the knowledge graph of the current scope (notebook/project) for passages relevant to the query. Use when the user asks about specific facts, sources, content they have uploaded, or when you need evidence to cite. Returns passages with short citation IDs you must include in your final answer.';
	}

	public function parameters_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'query' => [
					'type'        => 'string',
					'description' => 'Natural language question to search for.',
				],
				'top_k' => [
					'type'        => 'integer',
					'description' => 'Number of passages to return (1-10).',
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 10,
				],
			],
			'required'   => [ 'query' ],
		];
	}

	public function execute( array $args, array $context ): array {
		$query = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';
		$top_k = (int) ( $args['top_k'] ?? 5 );
		if ( $top_k < 1 ) $top_k = 5;
		if ( $top_k > 10 ) $top_k = 10;

		if ( '' === $query ) {
			return [ 'ok' => false, 'error' => 'query is required' ];
		}

		$scope = $context['scope'] ?? [];
		$scope_id = (int) ( $scope['scope_id'] ?? $scope['id'] ?? 0 );
		if ( $scope_id <= 0 ) {
			return [ 'ok' => false, 'error' => 'Missing scope_id in context' ];
		}

		if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
			return [ 'ok' => false, 'error' => 'KG Retriever not available' ];
		}

		$retriever = BizCity_KG_Retriever::instance();
		// $scope_id treated as notebook_id (KG-Hub canonical scope key).
		$res = $retriever->ask( $scope_id, $query, [
			'rerank_top_k' => $top_k,
			'answer'       => false,    // Twin_Agent generates final answer itself
		] );

		$passages_raw = isset( $res['passages'] ) && is_array( $res['passages'] ) ? $res['passages'] : [];
		$subgraph     = isset( $res['subgraph'] )  && is_array( $res['subgraph'] )  ? $res['subgraph']  : [ 'nodes' => [], 'links' => [] ];

		// Resolve source titles via KG_Source_Service when available, else fall back to "Source #N".
		$source_ids   = [];
		foreach ( $passages_raw as $p ) {
			$sid = isset( $p['source_id'] ) ? (int) $p['source_id'] : 0;
			if ( $sid > 0 ) $source_ids[] = $sid;
		}
		$source_ids   = array_values( array_unique( $source_ids ) );
		$titles_by_id = [];
		if ( ! empty( $source_ids ) && class_exists( 'BizCity_KG_Source_Service' ) ) {
			$svc = BizCity_KG_Source_Service::instance();
			foreach ( $source_ids as $sid ) {
				$meta = $svc->lookup_source_meta( (int) $sid );
				if ( $meta && ! empty( $meta['title'] ) ) {
					$titles_by_id[ (int) $sid ] = (string) $meta['title'];
				}
			}
		}

		// Build KG entity citations from subgraph (top 8 nodes by weight).
		$kg_citations  = [];
		$entity_ids    = [];
		$relation_ids  = [];
		if ( ! empty( $subgraph['nodes'] ) && is_array( $subgraph['nodes'] ) ) {
			$kidx = 1;
			foreach ( array_slice( $subgraph['nodes'], 0, 8 ) as $n ) {
				if ( empty( $n['id'] ) ) continue;
				$kg_citations[] = [
					'index'     => $kidx++,
					'entity_id' => (int) $n['id'],
					'name'      => isset( $n['label'] ) ? (string) $n['label'] : ( isset( $n['name'] ) ? (string) $n['name'] : '' ),
					'type'      => isset( $n['type'] )  ? (string) $n['type']  : '',
				];
				$entity_ids[] = (int) $n['id'];
			}
			foreach ( $subgraph['nodes'] as $n ) {
				if ( ! empty( $n['id'] ) ) $entity_ids[] = (int) $n['id'];
			}
		}
		if ( ! empty( $subgraph['links'] ) && is_array( $subgraph['links'] ) ) {
			foreach ( $subgraph['links'] as $l ) {
				if ( ! empty( $l['id'] ) ) $relation_ids[] = (int) $l['id'];
			}
		}
		$entity_ids   = array_values( array_unique( $entity_ids ) );
		$relation_ids = array_values( array_unique( $relation_ids ) );

		// Phase 0.6 Wave A — Brain reflection: enrich top-cited entities with
		// tool-evidence summary (which intent tools have invoked/produced them).
		// 1 query, capped at 8 entities to keep latency negligible (~2 ms).
		if ( ! empty( $kg_citations ) && class_exists( 'BizCity_KG' ) ) {
			$evidence = self::lookup_tool_evidence_for_entities( wp_list_pluck( $kg_citations, 'entity_id' ) );
			foreach ( $kg_citations as &$ent ) {
				$eid = (int) $ent['entity_id'];
				$ent['tool_evidence'] = $evidence[ $eid ] ?? [];
			}
			unset( $ent );
		}

		if ( empty( $passages_raw ) ) {
			return [
				'ok'           => true,
				'result'       => [ 'passages' => [], 'note' => 'No relevant passages found in this scope.' ],
				'summary'      => 'KG search: 0 passages',
				'sources'      => [],
				'citation_ids' => [],
				'kg_citations' => $kg_citations,
				'kg_highlight' => [ 'entity_ids' => $entity_ids, 'relation_ids' => $relation_ids ],
			];
		}

		// Generate stable citation IDs for this batch (kept for legacy/internal logging).
		$ids = BizCity_Twin_Citation_Id_Generator::generate_batch( count( $passages_raw ) );

		$passages = [];
		$sources  = [];
		$cite_ids = [];
		// FE TwinChatSource expects a NUMERIC `index` (1-based) so the
		// `[1] [2] …` regex in CitationLink can match. We give the LLM the
		// same numeric tokens to cite with.
		foreach ( $passages_raw as $i => $p ) {
			$cid     = $ids[ $i ] ?? BizCity_Twin_Citation_Id_Generator::generate_one();
			$index   = $i + 1;
			$content = isset( $p['content'] ) ? (string) $p['content'] : '';
			$real_src_id = (int) ( $p['source_id'] ?? 0 );
			$pid         = (int) ( $p['id'] ?? 0 );
			// 2026-05-05 — synthetic source_id for chat-promoted passages so the
			// LLM gets a consistent `src:N#pM` label for EVERY passage (mixed
			// labels like "[1]" + "[src:42#p99]" confused the model into emitting
			// hallucinated [src:?#p?] markers → `?` chips on FE). Mirrors
			// BizCity_Twin_Context_Resolver::_resolve_citable_source_id().
			$src_id = ( $real_src_id > 0 )
				? $real_src_id
				: ( $pid > 0 ? ( 1000000000 + $pid ) : 0 );
			$is_chat = ( $real_src_id <= 0 );
			$title   = $is_chat
				? 'Trí nhớ hội thoại'
				: ( isset( $titles_by_id[ $real_src_id ] ) ? $titles_by_id[ $real_src_id ] : ( 'Source #' . $real_src_id ) );
			$heading_path = isset( $p['heading_path'] ) && is_array( $p['heading_path'] ) ? $p['heading_path'] : [];

			$snippet_short = trim( preg_replace( '/\s+/', ' ', $content ) );
			if ( mb_strlen( $snippet_short ) > 220 ) {
				$snippet_short = mb_substr( $snippet_short, 0, 220 ) . '…';
			}

			// Phase 0.6 — cite label: [src:N#pM] always when we have any pid.
			$label = ( $src_id > 0 && $pid > 0 ) ? "src:{$src_id}#p{$pid}" : (string) $index;

			$passages[] = [
				'index'      => $index,        // 1-based ordinal (for display)
				'label'      => $label,        // Phase 0.6: [src:N#pM] citation label
				'cite_id'    => $cid,          // legacy short id
				'passage_id' => $pid,
				'source_id'  => $src_id,
				'snippet'    => mb_substr( $content, 0, 800 ),
			];
			// Shape matches FE TwinChatSource (see types/twinchat.ts).
			$sources[] = [
				'index'             => $index,
				'label'             => $label,
				'cite_id'           => $cid,
				'passage_id'        => $pid,
				'source_id'         => $src_id,
				'source_title'      => $title,
				'source_type'       => $is_chat ? 'chat_memory' : 'vector',
				'content_snippet'   => $snippet_short,
				'page_no'           => isset( $p['page_no'] ) ? (int) $p['page_no'] : null,
				'heading_path'      => $heading_path,
				'related_entities'  => isset( $p['related_entities'] ) && is_array( $p['related_entities'] ) ? $p['related_entities'] : [],
				'origin'            => $is_chat ? 'chat' : 'source',
			];
			$cite_ids[] = $label;
		}

		// 2026-05-05 — origin sort: file-sourced passages first, chat-memory last.
		// Keeps top-K display + LLM cite preference on authoritative sources.
		// Stable sort: re-stamp index so FE legacy [1][2] markers stay aligned
		// after reordering.
		$sort_fn = static function ( $a, $b ) {
			$ac = ( ( $a['source_type'] ?? '' ) === 'chat_memory' ) ? 1 : 0;
			$bc = ( ( $b['source_type'] ?? '' ) === 'chat_memory' ) ? 1 : 0;
			if ( $ac !== $bc ) return $ac - $bc;
			return ( $a['index'] ?? 0 ) - ( $b['index'] ?? 0 );
		};
		usort( $sources, $sort_fn );
		// Mirror sort on $passages by passage_id so prompt ordering matches.
		$sources_order = [];
		foreach ( $sources as $new_idx => &$s ) {
			$s['index'] = $new_idx + 1;
			$sources_order[ (int) $s['passage_id'] ] = $new_idx + 1;
		}
		unset( $s );
		usort( $passages, static function ( $a, $b ) use ( $sources_order ) {
			$ai = $sources_order[ (int) ( $a['passage_id'] ?? 0 ) ] ?? 999;
			$bi = $sources_order[ (int) ( $b['passage_id'] ?? 0 ) ] ?? 999;
			return $ai - $bi;
		} );
		foreach ( $passages as $new_idx => &$pp ) {
			$pp['index'] = $new_idx + 1;
		}
		unset( $pp );

		// ─────────────────────────────────────────────────────────────
		// PHASE-0.3 §4.9 — TRANSPARENCY-FIRST IDENTITY OVERLAY
		// Pure additive: extracts structured IDs (sku/order/…) from the
		// query + each passage so the LLM can NEVER mix prices/values
		// of different SKUs without an explicit "Mã KHÁC …" disclaimer.
		// Old retrieval algorithm is untouched.
		// ─────────────────────────────────────────────────────────────
		$identity_report = [
			'has_query_identity' => false,
			'query_identities'   => [],
			'matched'            => [],   // [{passage_index, identity}]
			'related'            => [],   // wrong code, same family
			'unidentified'       => [],   // chunk has NO id
			'other'              => [],   // has id but completely unrelated
		];
		if ( class_exists( 'BizCity_KG_Identity_Extractor' ) ) {
			$q_ids = BizCity_KG_Identity_Extractor::extract( $query );
			$identity_report['query_identities']   = $q_ids;
			$identity_report['has_query_identity'] = ! empty( $q_ids );
			$q_primary = BizCity_KG_Identity_Extractor::primary( $q_ids );

			// PHASE-0.3 Wave 2 — bulk-load persisted identity cache for these
			// passage_ids so we skip re-running regex on long content (faster +
			// honors user_confirmed overrides). Cache misses fall back to
			// on-the-fly extraction below.
			$cache_by_pid = [];
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$pid_list = [];
				foreach ( $passages as $pp ) {
					$pid = (int) ( $pp['passage_id'] ?? 0 );
					if ( $pid > 0 ) $pid_list[] = $pid;
				}
				$pid_list = array_values( array_unique( $pid_list ) );
				if ( ! empty( $pid_list ) ) {
					global $wpdb;
					$db_  = BizCity_KG_Database::instance();
					$tbl  = $db_->tbl_passage_identities();
					$ids_csv = implode( ',', array_map( 'intval', $pid_list ) );
					$prev = $wpdb->suppress_errors( true );
					$rows = $wpdb->get_results(
						"SELECT passage_id, id_kind, canonical_id, evidence_span, occurrences, score, source
						   FROM {$tbl}
						  WHERE passage_id IN ({$ids_csv})
						  ORDER BY ( source = 'user_confirmed' ) DESC, score DESC",
						ARRAY_A
					);
					$wpdb->suppress_errors( $prev );
					foreach ( (array) $rows as $r ) {
						$cache_by_pid[ (int) $r['passage_id'] ][] = [
							'id_kind'       => (string) $r['id_kind'],
							'canonical_id'  => (string) $r['canonical_id'],
							'evidence_span' => (string) $r['evidence_span'],
							'occurrences'   => (int) $r['occurrences'],
							'score'         => (float) $r['score'],
							'source'        => (string) $r['source'],
						];
					}
				}
			}

			foreach ( $sources as $i => &$src ) {
				$pid_int = (int) ( $passages[ $i ]['passage_id'] ?? $src['passage_id'] ?? 0 );
				if ( $pid_int > 0 && ! empty( $cache_by_pid[ $pid_int ] ) ) {
					$p_ids = $cache_by_pid[ $pid_int ];
				} else {
					$snippet = '';
					if ( isset( $passages[ $i ]['snippet'] ) )       $snippet  = (string) $passages[ $i ]['snippet'];
					elseif ( isset( $src['content_snippet'] ) )      $snippet  = (string) $src['content_snippet'];
					$p_ids = BizCity_KG_Identity_Extractor::extract( $snippet );
				}

				$status   = 'unidentified';
				$matched  = null;
				$reason   = '';
				if ( empty( $p_ids ) ) {
					$status = 'unidentified';
				} elseif ( $q_primary ) {
					foreach ( $p_ids as $pid ) {
						if ( $pid['id_kind'] === $q_primary['id_kind']
							&& $pid['canonical_id'] === $q_primary['canonical_id'] ) {
							$status  = 'matched';
							$matched = $pid;
							$reason  = sprintf( 'exact_match:%s', $pid['evidence_span'] );
							break;
						}
					}
					if ( ! $matched ) {
						foreach ( $p_ids as $pid ) {
							if ( BizCity_KG_Identity_Extractor::are_related( $q_primary, $pid ) ) {
								$status  = 'related';
								$matched = $pid;
								$reason  = sprintf( 'related_to:%s (query:%s)',
									$pid['canonical_id'], $q_primary['canonical_id'] );
								break;
							}
						}
					}
					if ( ! $matched ) {
						$status   = 'other';
						$matched  = $p_ids[0];
						$reason   = sprintf( 'unrelated_id:%s', $p_ids[0]['canonical_id'] );
					}
				} else {
					// No identity in query — still annotate so prompt shows the IDs found.
					$status   = 'matched';   // treat as ok (no identity to mismatch)
					$matched  = $p_ids[0];
					$reason   = 'no_query_identity';
				}

				$identity_meta = [
					'identity_match'    => $status,                           // matched|related|other|unidentified
					'identity'          => $matched ? [
						'id_kind'      => $matched['id_kind'],
						'canonical_id' => $matched['canonical_id'],
					] : null,
					'identity_label'    => $matched ? BizCity_KG_Identity_Extractor::label(
						$matched['id_kind'], $matched['canonical_id'] ) : null,
					'evidence_span'     => $matched['evidence_span'] ?? null,
					'match_reason'      => $reason,
					'all_identities'    => array_map( static function ( $r ) {
						return [
							'id_kind'      => $r['id_kind'],
							'canonical_id' => $r['canonical_id'],
							'occurrences'  => (int) $r['occurrences'],
						];
					}, $p_ids ),
				];
				$src = array_merge( $src, $identity_meta );
				if ( isset( $passages[ $i ] ) ) {
					$passages[ $i ] = array_merge( $passages[ $i ], $identity_meta );
				}
				$identity_report[ $status ][] = [
					'passage_index' => $src['index'],
					'identity'      => $identity_meta['identity'],
					'reason'        => $reason,
				];
			}
			unset( $src );
		}

		// Build explicit Vietnamese instruction per spec §4.9.3.
		$default_instruction = 'Cite each used passage in your final answer using its exact label in square brackets, e.g. [src:187#p9921]. The label is the `label` field on each passage. Copy the numbers exactly — do NOT invent labels.';
		$instruction = $default_instruction;
		if ( $identity_report['has_query_identity'] ) {
			$qp = $identity_report['query_identities'][0];
			$ql = BizCity_KG_Identity_Extractor::label( $qp['id_kind'], $qp['canonical_id'] );
			$matched_idx     = wp_list_pluck( $identity_report['matched'], 'passage_index' );
			$related_idx     = wp_list_pluck( $identity_report['related'], 'passage_index' );
			$other_idx       = wp_list_pluck( $identity_report['other'], 'passage_index' );
			$unidentified_ix = wp_list_pluck( $identity_report['unidentified'], 'passage_index' );
			$has_match  = ! empty( $matched_idx );
			// 2026-05-14 — sync với class-twin-context-resolver.php fix #2:
			// Bỏ "trả lời nguyên văn ... rồi DỪNG". Cho phép dùng passage ❓
			// làm context định tính nhưng vẫn cấm suy số liệu định lượng.
			$strict_rule = $has_match
				? sprintf( "QUY TẮC TUYỆT ĐỐI:\n- Có passage ✅ cho %s → CHỈ dùng số liệu (giá, tồn, thông số) từ ✅.\n- KHÔNG viết \"Ngoài ra, có nguồn khác…\", \"Một thông tin khác…\", \"Lưu ý có mã…\" — đó là mã KHÁC, BỎ QUA hoàn toàn trong câu trả lời.\n- Nếu user muốn biết mã khác, họ sẽ hỏi tiếp ở câu sau.", $qp['canonical_id'] )
				: sprintf( "QUY TẮC:\n- Không có passage ✅ trùng mã \"%s\".\n- ĐƯỢC PHÉP dùng passage ❓ (không xác định mã) để trả lời ĐỊNH TÍNH về %s nếu nội dung/tiêu đề rõ ràng liên quan (cùng tên sản phẩm, cùng dòng, mô tả tính năng).\n- TUYỆT ĐỐI KHÔNG suy diễn số liệu định lượng (giá, tồn, kích thước, thông số) cho %s từ passage ⚠️ hoặc ❓.\n- Nếu chỉ trả lời được định tính, hãy nói rõ phần nào CÓ và phần nào THIẾU trong nguồn.\n- Chỉ refuse khi KHÔNG passage nào dùng được kể cả định tính.", $qp['canonical_id'], $qp['canonical_id'], $qp['canonical_id'] );
			$instruction = sprintf(
				"NHẬN DIỆN MÃ TRONG CÂU HỎI: %s (kind=%s).\n"
				. "PASSAGE TRÙNG MÃ (✅): %s\n"
				. "PASSAGE MÃ KHÁC – CÙNG HỌ (⚠️): %s\n"
				. "PASSAGE MÃ KHÁC HẲN: %s\n"
				. "PASSAGE KHÔNG CÓ MÃ (❓): %s\n\n"
				. "%s\n\n"
				. "Mỗi câu trích dẫn dùng marker [N] đúng số ID đã liệt kê — không gộp [1,2], không bịa số.",
				$ql, $qp['id_kind'],
				$matched_idx     ? '[' . implode( '][', $matched_idx ) . ']'      : '(không có)',
				$related_idx     ? '[' . implode( '][', $related_idx ) . ']'      : '(không có)',
				$other_idx       ? '[' . implode( '][', $other_idx ) . ']'        : '(không có)',
				$unidentified_ix ? '[' . implode( '][', $unidentified_ix ) . ']'  : '(không có)',
				$strict_rule
			);
		}

		/**
		 * Observability hook — fired once per search_kg call after identity
		 * tagging. Listeners (audit log, debug bar) can capture the report
		 * shape and the user query verbatim.
		 *
		 * @param int    $scope_id        Notebook id.
		 * @param string $query           Raw user query.
		 * @param array  $identity_report See header of this block.
		 */
		do_action( 'bizcity_kg_identity_report_built', $scope_id, $query, $identity_report );

		return [
			'ok'              => true,
			'result'          => [
				'passages'    => $passages,
				'instruction' => $instruction,
			],
			'summary'         => sprintf( 'KG search "%s": %d passages', mb_substr( $query, 0, 60 ), count( $passages ) ),
			'sources'         => $sources,
			'citation_ids'    => $cite_ids,
			'kg_citations'    => $kg_citations,
			'kg_highlight'    => [ 'entity_ids' => $entity_ids, 'relation_ids' => $relation_ids ],
			'identity_report' => $identity_report,
		];
	}

	/**
	 * Phase 0.6 Wave A — Brain reflection helper.
	 *
	 * For a list of entity IDs, return the top intent-tools that have invoked
	 * (or produced via a source containing) each entity. Reads from kg_xref
	 * → bizcity_intent_evidence to build the summary.
	 *
	 * @param int[] $entity_ids
	 * @return array<int, array<int, array{tool: string, count: int, last_at: string}>>
	 */
	private static function lookup_tool_evidence_for_entities( array $entity_ids ): array {
		$entity_ids = array_values( array_unique( array_filter( array_map( 'intval', $entity_ids ) ) ) );
		if ( empty( $entity_ids ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return [];
		}
		sort( $entity_ids, SORT_NUMERIC );

		/**
		 * Filter cache TTL (seconds) for kg_search tool_evidence enrichment.
		 *
		 * Set to 0 to disable cache. Default 60s — short enough to reflect new
		 * tool calls during a chat session, long enough to absorb bursts.
		 *
		 * @since 0.6.A
		 * @param int   $ttl         Default 60.
		 * @param int[] $entity_ids  Sorted unique entity IDs queried.
		 */
		$ttl = (int) apply_filters( 'bizcity_kg_xref_evidence_cache_ttl', 60, $entity_ids );

		$cache_group = 'bizcity_kg_xref';
		$cache_key   = 'tool_evidence:' . md5( implode( ',', $entity_ids ) );

		if ( $ttl > 0 ) {
			$cached = wp_cache_get( $cache_key, $cache_group );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		global $wpdb;
		$db   = BizCity_KG_Database::instance();
		$xref = method_exists( $db, 'tbl_xref' ) ? $db->tbl_xref() : ( $wpdb->prefix . 'bizcity_kg_xref' );
		$ev   = $wpdb->prefix . 'bizcity_intent_evidence';
		$ids  = implode( ',', $entity_ids ); // Already int-sanitized + sorted above.

		// Group: entity_id × tool → COUNT + MAX(created_at). Cap rows to 200 to avoid hot-tool noise.
		$sql = "
			SELECT x.kg_ref_id AS entity_id,
			       COALESCE(NULLIF(e.tool_name,''), 'unknown') AS tool,
			       COUNT(*)         AS cnt,
			       MAX(x.created_at) AS last_at
			FROM   {$xref} x
			LEFT JOIN {$ev} e ON e.id = x.cortex_ref_id
			WHERE  x.cortex      = 'intent'
			  AND  x.kg_ref_type = 'entity'
			  AND  x.kg_ref_id IN ({$ids})
			GROUP BY x.kg_ref_id, tool
			ORDER BY cnt DESC
			LIMIT 200
		";
		$rows = $wpdb->get_results( $sql );

		$out = [];
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $r ) {
				$eid = (int) $r->entity_id;
				if ( ! isset( $out[ $eid ] ) ) $out[ $eid ] = [];
				if ( count( $out[ $eid ] ) >= 3 ) continue; // Top-3 tool per entity.
				$out[ $eid ][] = [
					'tool'    => (string) $r->tool,
					'count'   => (int) $r->cnt,
					'last_at' => (string) $r->last_at,
				];
			}
		}

		if ( $ttl > 0 ) {
			// Cache empty result too — avoids hammering DB for entities never seen by tools.
			wp_cache_set( $cache_key, $out, $cache_group, $ttl );
		}

		return $out;
	}
}
