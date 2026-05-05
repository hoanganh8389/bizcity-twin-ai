<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Twin Context Resolver (local runtime implementation).
 *
 * Single entry point used by Chat Gateway and Intent Stream in Twin AI.
 * It keeps compatibility with existing local flow while reducing scattered
 * prompt assembly logic.
 *
 * @package BizCity_Twin_AI
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Twin_Context_Resolver', false ) ) {
    return;
}

class BizCity_Twin_Context_Resolver {

    /**
     * Build full system prompt text for a mode.
     */
    public static function build_system_prompt( string $mode, array $ctx = [] ): string {
        $bundle = self::build_prompt_bundle( $mode, $ctx );
        return (string) ( $bundle['system_content'] ?? '' );
    }

    /**
     * Compatibility helper for chat consumers that need context slices.
     */
    public static function for_chat( array $ctx = [] ): array {
        return self::build_prompt_bundle( 'chat', $ctx );
    }

    /**
     * Build unified prompt bundle (prompt + contextual slices).
     */
    public static function build_prompt_bundle( string $mode, array $ctx = [] ): array {
        $mode = $mode ?: 'chat';

        $user_id        = (int) ( $ctx['user_id'] ?? 0 );
        $session_id     = (string) ( $ctx['session_id'] ?? '' );
        $message        = (string) ( $ctx['message'] ?? '' );
        $character_id   = (int) ( $ctx['character_id'] ?? 0 );
        $platform_type  = (string) ( $ctx['platform_type'] ?? '' );
        $images         = is_array( $ctx['images'] ?? null ) ? $ctx['images'] : [];
        $engine_result  = is_array( $ctx['engine_result'] ?? null ) ? $ctx['engine_result'] : [];
        $effective_platform = $platform_type !== '' ? $platform_type : 'WEBCHAT';
        $channel_role = $ctx['channel_role'] ?? [];

        if ( class_exists( 'BizCity_Focus_Gate' ) ) {
            BizCity_Focus_Gate::ensure_resolved( $message, [
                'mode'           => $engine_result['meta']['mode'] ?? $mode,
                'platform_type'  => $effective_platform,
                'routing_branch' => $engine_result['action'] ?? '',
                'active_goal'    => $engine_result['goal'] ?? '',
                'user_id'        => $user_id,
                'session_id'     => $session_id,
                'images'         => $images,
                'channel_role'   => $channel_role,
                'context'        => $ctx,
            ] );
            BizCity_Focus_Gate::amend_for_goal( $engine_result['goal'] ?? '' );
        }

        $character = null;
        if ( $character_id > 0 && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $character = BizCity_Knowledge_Database::instance()->get_character( $character_id );
        }

        $system_content = ( $character && ! empty( $character->system_prompt ) )
            ? (string) $character->system_prompt
            : 'Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời bằng tiếng Việt.';

        $profile_context = '';
        $transit_context = '';
        if ( class_exists( 'BizCity_Profile_Context' ) ) {
            $can_inject_profile = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'profile' );
            if ( $can_inject_profile ) {
                $profile_ctx_inst = BizCity_Profile_Context::instance();
                $profile_context  = (string) $profile_ctx_inst->build_user_context(
                    $user_id ?: get_current_user_id(),
                    $session_id,
                    $effective_platform,
                    [ 'coach_type' => '' ]
                );

                $can_inject_transit = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'transit' );
                if ( $can_inject_transit ) {
                    $transit_context = (string) $profile_ctx_inst->build_transit_context(
                        $message,
                        $user_id ?: get_current_user_id(),
                        $session_id,
                        $effective_platform,
                        (string) ( $engine_result['goal'] ?? '' )
                    );
                }
            }
        }

        if ( $profile_context !== '' ) {
            $system_content .= "\n\n" . $profile_context;
        }
        if ( $transit_context !== '' ) {
            $system_content .= "\n\n" . $transit_context;
        }

        // ── Context Layers Capture: start recording (Phase 1.6) ──
        $capture_active = class_exists( 'BizCity_Context_Layers_Capture' )
            && class_exists( 'BizCity_Session_Memory_Spec' )
            && BizCity_Session_Memory_Spec::is_enabled();
        if ( $capture_active ) {
            BizCity_Context_Layers_Capture::start();
            if ( $profile_context !== '' ) {
                BizCity_Context_Layers_Capture::record( 'profile', $profile_context, array( 'priority' => 0, 'source' => 'twin_resolver' ) );
            }
            if ( $transit_context !== '' ) {
                BizCity_Context_Layers_Capture::record( 'transit', $transit_context, array( 'priority' => 0, 'source' => 'twin_resolver', 'gated_by' => 'focus_gate' ) );
            }
        }

        if ( ! empty( $engine_result['meta']['system_instructions'] ) ) {
            $system_content .= "\n\n" . (string) $engine_result['meta']['system_instructions'];
        }
        if ( ! empty( $engine_result['meta']['provider_context'] ) ) {
            $system_content .= "\n\n" . (string) $engine_result['meta']['provider_context'];
        }

        // ── Knowledge RAG Context (v4.9.4) ──
        // Registered as one-shot filter at priority 95 (AFTER Skill Context at 93)
        // so skills define HOW to do things, knowledge supplies business data.
        $knowledge_context = '';
        $can_inject_knowledge = ! class_exists( 'BizCity_Focus_Gate' ) || BizCity_Focus_Gate::should_inject( 'knowledge' );
        if ( $can_inject_knowledge && class_exists( 'BizCity_Knowledge_Context_API' ) && $character_id > 0 ) {
            $k_char_id = $character_id;
            $k_message = $message;
            $k_images  = $images;
            add_filter( 'bizcity_chat_system_prompt', function ( $prompt, $args ) use ( $k_char_id, $k_message, $k_images, &$knowledge_context ) {
                $knowledge_result  = BizCity_Knowledge_Context_API::instance()->build_context(
                    $k_char_id,
                    $k_message,
                    [ 'images' => $k_images ]
                );
                $knowledge_context = $knowledge_result['context'] ?? '';
                if ( $knowledge_context !== '' ) {
                    $prompt .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge_context;
                }
                return $prompt;
            }, 95, 2 );
        }

        $filter_args = [
            'mode'             => $engine_result['meta']['mode'] ?? $mode,
            'character_id'     => $character_id,
            'message'          => $message,
            'user_id'          => $user_id,
            'session_id'       => $session_id,
            'platform_type'    => $effective_platform,
            'images'           => $images,
            'engine_result'    => $engine_result,
            'kci_ratio'        => (int) ( $ctx['kci_ratio'] ?? 80 ),
            'mention_override' => ! empty( $ctx['mention_override'] ),
            'channel_role'     => $channel_role,
            'via'              => $ctx['via'] ?? 'twin_resolver',
        ];

        $system_content = (string) apply_filters( 'bizcity_chat_system_prompt', $system_content, $filter_args );

        // ── Phase 1.6: Fire system_prompt_built action for observability ──
        $bundle = [
            'system_content'     => $system_content,
            'character'          => $character,
            'profile_context'    => $profile_context,
            'transit_context'    => $transit_context,
            'knowledge_context'  => $knowledge_context,
            'memory_context'     => '',
            'effective_platform' => $effective_platform,
        ];

        do_action( 'bizcity_system_prompt_built', $system_content, $filter_args, $bundle );

        // §20 C2 fix: Removed inline on_prompt_built() call.
        // Bootstrap hook (bizcity_system_prompt_built @10) already handles it.
        // Duplicate call was no-op (safe) but confusing.

        return $bundle;
    }

    /* ──────────────────────────────────────────────────────────────────── */
    /* Sprint 4.5h — KG Context Injection (Hình thức C / Contract §4)      */
    /* ──────────────────────────────────────────────────────────────────── */

    /**
     * Resolve KG passages + format as extra_system block for Twin Agent.
     *
     * Called from TwinChat stream handler BEFORE BizCity_Twin_Agent::run()
     * when bizcity_kg_is_main_task() returns true. Injects authoritative
     * knowledge context into the system prompt so even cheap LLMs answer
     * from the user's KG rather than from training data.
     *
     * @param array  $scope  { plugin: string, scope_id: int, scope_type?: string }
     * @param string $query  The user message / question text.
     * @param array  $opts   { use_kg: bool, source_ids: int[], top_k: int }
     * @return array {
     *   passages      : array,   raw passage rows from retriever
     *   context_block : string,  formatted system-prompt fragment
     *   kg_citations  : array,   [{ index, id, name, type }]
     *   sources       : array,   enriched source rows for SSE sources event
     *   subgraph      : array,   { nodes, links }
     * }
     */
    public static function resolve( array $scope, string $query, array $opts = [] ): array {
        $empty = [
            'passages'      => [],
            'context_block' => '',
            'kg_citations'  => [],
            'sources'       => [],
            'subgraph'      => [ 'nodes' => [], 'links' => [] ],
        ];

        if ( ! (bool) ( $opts['use_kg'] ?? true ) ) {
            return $empty;
        }
        if ( trim( $query ) === '' ) {
            return $empty;
        }
        if ( ! class_exists( 'BizCity_KG_Retriever' ) ) {
            return $empty;
        }

        $scope_id = (int) ( $scope['scope_id'] ?? 0 );
        if ( $scope_id <= 0 ) {
            return $empty;
        }

        // PHASE-0.19 P1 — cap raised 10→20 to allow large notebooks
        // (markdown tables, long-form sources) more passage coverage.
        // Lower bound stays at 1; default unchanged.
        $top_k = max( 1, min( 20, (int) ( $opts['top_k'] ?? 5 ) ) );

        $seed_entities  = max( 1, min( 32, (int) ( $opts['seed_entities']  ?? 4 ) ) );
        $seed_relations = max( 1, min( 64, (int) ( $opts['seed_relations'] ?? 12 ) ) );
        $expand_hops    = max( 1, min( 3,  (int) ( $opts['expand_hops']    ?? 1 ) ) );

        try {
            $retr = BizCity_KG_Retriever::instance()->ask( $scope_id, $query, [
                'answer'         => false,
                'seed_entities'  => $seed_entities,
                'seed_relations' => $seed_relations,
                'rerank_top_k'   => $top_k,
                'expand_hops'    => $expand_hops,
            ] );
        } catch ( \Throwable $e ) {
            error_log( '[TwinContextResolver] KG retrieval error: ' . $e->getMessage() );
            return $empty;
        }

        if ( ! is_array( $retr ) ) {
            return $empty;
        }

        $passages = isset( $retr['passages'] ) && is_array( $retr['passages'] )
            ? self::_sort_passages_by_origin( $retr['passages'] )
            : [];
        // Slice AFTER origin sort so chat-memory passages can't crowd out
        // file-sourced ones via the top_k cap.
        if ( ! empty( $passages ) ) {
            $passages = array_slice( $passages, 0, $top_k );
        }

        // Optional source_ids filter — narrow to selected sources.
        if ( ! empty( $opts['source_ids'] ) && is_array( $opts['source_ids'] ) && ! empty( $passages ) ) {
            $wanted   = array_flip( array_map( 'intval', $opts['source_ids'] ) );
            $passages = array_values( array_filter( $passages, static function ( $p ) use ( $wanted ) {
                return isset( $wanted[ (int) ( $p['source_id'] ?? 0 ) ] );
            } ) );
        }

        $subgraph = isset( $retr['subgraph'] ) && is_array( $retr['subgraph'] )
            ? $retr['subgraph']
            : [ 'nodes' => [], 'links' => [] ];

        $entities  = isset( $retr['query_entities'] ) && is_array( $retr['query_entities'] )
            ? $retr['query_entities']
            : [];

        $relations = isset( $retr['reranked_relations'] ) && is_array( $retr['reranked_relations'] )
            ? $retr['reranked_relations']
            : ( isset( $retr['retrieval_detail']['relation_texts'] ) ? (array) $retr['retrieval_detail']['relation_texts'] : [] );

        $kg_citations  = self::_build_kg_citations_from_subgraph( $subgraph );
        $sources       = self::_enrich_sources_for_citations( $passages );
        $context_block = self::_format_context_block( $passages, $entities, $relations, $kg_citations );

        $result = [
            'passages'      => $passages,
            'context_block' => $context_block,
            'kg_citations'  => $kg_citations,
            'sources'       => $sources,
            'subgraph'      => $subgraph,
        ];

        return (array) apply_filters( 'bizcity_twin_context_resolver_result', $result, $scope, $query, $opts );
    }

    /**
     * Format passages + KG citations + relations as system prompt fragment.
     *
     * @internal
     */
    private static function _format_context_block(
        array $passages,
        array $entities,
        array $relations,
        array $kg_citations
    ): string {
        if ( empty( $passages ) && empty( $entities ) ) {
            return '';
        }

        $lines = [];

        if ( ! empty( $passages ) ) {
            // Phase 0.6 CITATION V2 (spec §2) — emit explicit allowed-IDs block
            // BEFORE the passages so the LLM has a flat vocabulary it can
            // pattern-match against, plus the "OMIT if unsure" rule that
            // reframes hallucination as the disfavored option.
            $allowed_labels = [];
            $passage_lines  = [];
            $idx            = 1;
            foreach ( $passages as $p ) {
                $content = isset( $p['content'] ) ? (string) $p['content'] : '';
                $content = trim( preg_replace( '/\s+/', ' ', $content ) );
                if ( mb_strlen( $content ) > 1600 ) {
                    $content = mb_substr( $content, 0, 1600 ) . '…';
                }
                $pid    = (int) ( $p['id'] ?? $p['passage_id'] ?? 0 );
                $src_id = self::_resolve_citable_source_id( $p );
                // 2026-05-05 v2 — NexusRAG-style. Use ONLY the short [N] in
                // the header. The long `src:N#pM` form is NOT printed in the
                // passage header anymore: cheap LLMs (gemini-flash) were
                // copy-pasting that long form back into answers, producing
                // `[src:484#p5963]` markers that don't resolve on the FE.
                // The mapping `[N] → src:N#pM` lives in code only.
                $short_label      = sprintf( '[%d]', $idx );
                $allowed_labels[] = (string) $idx;
                $label            = $short_label;
                // Heading path hint helps the LLM pick the right passage.
                $hpath = '';
                if ( ! empty( $p['source_title'] ) ) {
                    $hpath = (string) $p['source_title'];
                    if ( ! empty( $p['heading_path'] ) ) {
                        $hp = is_array( $p['heading_path'] ) ? implode( ' › ', $p['heading_path'] ) : (string) $p['heading_path'];
                        if ( $hp !== '' ) $hpath .= ' › ' . $hp;
                    }
                }
                $header = $hpath !== '' ? sprintf( '%s — %s', $label, $hpath ) : $label;
                $passage_lines[] = $header;
                $passage_lines[] = $content;
                $idx++;
            }

            $lines[] = '=== Knowledge Context (KG Retrieval) ===';
            foreach ( $passage_lines as $pl ) $lines[] = $pl;

            if ( ! empty( $allowed_labels ) ) {
                $lines[] = '';
                $lines[] = '=== Allowed citation IDs ===';
                $lines[] = '[' . implode( '], [', $allowed_labels ) . ']';
            }

            // 2026-05-05 v2 — NexusRAG-proven citation rules. Key changes vs
            // previous attempt: NO "MANDATORY ≥ 1 marker" rule (caused lazy
            // `[1][2][3][4]`-at-start dumping); each marker MUST be in its own
            // brackets right after the sentence it supports; "omit when unsure"
            // is the explicit escape hatch (post-mortem anti-pattern #2).
            $lines[] = '';
            $lines[] = '=== CITATION RULES ===';
            $lines[] = 'ANCHOR-AND-EXPAND: anchor your answer in the passages above (~20%, cited), then EXPAND with your own explanation, examples, and actionable guidance (~80%, no marker). Do NOT refuse just because passages cover the topic only partially.';
            $lines[] = '';
            $lines[] = 'Each passage above has a unique short ID shown in brackets at the start of its block (e.g. [1], [2], [3]).';
            $lines[] = '';
            $lines[] = 'GOOD example:';
            $lines[] = '  "Sao Thủy ở 27° Bảo Bình[1]. Sao Kim ở 11° Song Ngư[2][3]."';
            $lines[] = '';
            $lines[] = 'BAD examples (NEVER do this):';
            $lines[] = '  "Sao Thủy ở 27° Bảo Bình [1, 2]."        ← grouped in one bracket';
            $lines[] = '  "Đoạn mở đầu [1][2][3][4]. Thông tin chi tiết…" ← lazy dump at start';
            $lines[] = '  "…theo bản đồ sao . [1]"                 ← space before bracket';
            $lines[] = '';
            $lines[] = 'Rules:';
            $lines[] = '1. Each citation MUST be in its OWN brackets: [1][3]. NEVER write [1, 3] or [1,3].';
            $lines[] = '2. Place markers IMMEDIATELY after the word they support, with NO space before the bracket.';
            $lines[] = '3. ONLY use numbers from the "Allowed citation IDs" list above. NEVER invent higher numbers.';
            $lines[] = '4. Cite up to 3 most relevant passages per sentence. Pick the most pertinent ones.';
            $lines[] = '5. When a sentence draws a fact, name, or value from a passage above, append the matching [N] marker.';
            $lines[] = '6. Sentences containing your OWN expansion (explanation, examples, general knowledge) do NOT need a marker — write them confidently in your own voice WITHOUT prefacing with "the sources don\'t say…".';
            $lines[] = '7. Do NOT use [d1], [d2], [doc1], [draft:N], [src:N#pM] or any other form. Only [N] from the Allowed list above.';
            $lines[] = '8. For Knowledge-Graph entities, use [K1], [K2]… as listed below.';
        }

        if ( ! empty( $kg_citations ) ) {
            $lines[] = '';
            $lines[] = 'Knowledge Graph entities (cite with [K1], [K2]…):';
            foreach ( $kg_citations as $kc ) {
                $lines[] = sprintf(
                    '[K%d] %s%s',
                    (int) $kc['index'],
                    (string) ( $kc['name'] ?? '' ),
                    ( $kc['type'] ?? '' ) !== '' ? ' (' . $kc['type'] . ')' : ''
                );
            }
        }

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

        return implode( "\n", $lines );
    }

    /**
     * Build kg_citations array from subgraph nodes (top 8).
     *
     * @internal
     */
    private static function _build_kg_citations_from_subgraph( array $subgraph ): array {
        $nodes = isset( $subgraph['nodes'] ) && is_array( $subgraph['nodes'] )
            ? $subgraph['nodes']
            : [];
        $cits = [];
        $idx  = 1;
        foreach ( array_slice( $nodes, 0, 8 ) as $n ) {
            $name = (string) ( $n['name'] ?? $n['label'] ?? '' );
            if ( $name === '' ) {
                continue;
            }
            $cits[] = [
                'index' => $idx++,
                'id'    => (int) ( $n['id'] ?? 0 ),
                'name'  => $name,
                'type'  => (string) ( $n['type'] ?? '' ),
            ];
        }
        return $cits;
    }

    /**
     * Enrich passages with source title/heading info for citation chips.
     *
     * Phase 0.6 CITATION V2 — emit ONE row per (source_id, passage_id) so the
     * FE strict lookup map `srcBySrcPid` (key = `${sid}|${pid}`) can resolve
     * `[src:N#pM]` markers. Schema must match what FE `CitationLink` expects:
     *   { index, source_id, passage_id, source_title, content_snippet,
     *     heading_id, heading_path }.
     *
     * @internal
     */
    private static function _enrich_sources_for_citations( array $passages ): array {
        if ( empty( $passages ) ) {
            return [];
        }
        $sources = [];
        $idx     = 1;
        $seen    = []; // dedupe key = "sid|pid"
        foreach ( $passages as $p ) {
            $pid = (int) ( $p['id'] ?? $p['passage_id'] ?? 0 );
            if ( $pid <= 0 ) {
                // No passage id at all — cannot cite, skip.
                continue;
            }
            // 2026-05-05 — emit a row even when source_id<=0 (chat-promoted /
            // synthesized passages). Use a stable pseudo-id so the prompt and FE
            // map agree. See _resolve_citable_source_id().
            $real_sid = (int) ( $p['source_id'] ?? 0 );
            $sid      = self::_resolve_citable_source_id( $p );
            if ( $sid <= 0 ) {
                continue;
            }
            $key = $sid . '|' . $pid;
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            $content = isset( $p['content'] ) ? (string) $p['content'] : '';
            $snippet = trim( preg_replace( '/\s+/', ' ', $content ) );
            if ( mb_strlen( $snippet ) > 220 ) {
                $snippet = mb_substr( $snippet, 0, 220 ) . '…';
            }
            $heading_path = isset( $p['heading_path'] ) && is_array( $p['heading_path'] )
                ? $p['heading_path']
                : ( isset( $p['heading_path'] ) && is_string( $p['heading_path'] ) && $p['heading_path'] !== ''
                    ? [ (string) $p['heading_path'] ]
                    : [] );

            $is_chat_memory = ( $real_sid <= 0 );
            $sources[] = [
                'index'           => $idx,
                'source_id'       => $sid,
                'passage_id'      => $pid,
                'source_title'    => $is_chat_memory
                    ? 'Trí nhớ hội thoại'
                    : (string) ( $p['source_title'] ?? $p['origin_url'] ?? "Source {$real_sid}" ),
                'source_type'     => $is_chat_memory ? 'chat_memory' : 'vector',
                'content_snippet' => $snippet,
                'page_no'         => isset( $p['page_no'] ) ? (int) $p['page_no'] : null,
                'heading_path'    => $heading_path,
                'heading_id'      => (string) ( $p['heading_id'] ?? '' ),
                'origin'          => (string) ( $p['origin'] ?? '' ),
                // Back-compat alias for code paths that read `cite_id`.
                'cite_id'         => $idx,
            ];
            $idx++;
        }
        return $sources;
    }

    /**
     * Compute a stable, citable source_id for a passage even when its underlying
     * `source_id` is NULL (e.g. chat-promoted passages from BizCity_KG_Auto_Promoter).
     *
     * Returns the real source_id when > 0, otherwise a synthetic id derived from
     * the passage_id offset by SYNTHETIC_SOURCE_BASE so it cannot collide with
     * real DB source ids. Both _format_context_block() and _enrich_sources_for_citations()
     * call this so the prompt label and FE chip map use the same id.
     *
     * @internal
     */
    private static function _resolve_citable_source_id( array $p ): int {
        $sid = (int) ( $p['source_id'] ?? 0 );
        if ( $sid > 0 ) {
            return $sid;
        }
        $pid = (int) ( $p['id'] ?? $p['passage_id'] ?? 0 );
        if ( $pid <= 0 ) {
            return 0;
        }
        // Bound = 1B; passage ids stay well below that. Stable across turns.
        return 1000000000 + $pid;
    }

    /**
     * Stable origin-aware sort for passages.
     *
     * File/web-ingested passages (real `source_id > 0`) are placed BEFORE
     * chat-promoted passages (source_id NULL/0). Within each group the original
     * relative order from the retriever (vector + rerank) is preserved.
     *
     * Rationale: when a notebook has many chat turns, BizCity_KG_Auto_Promoter
     * floods kg_passages with conversational text. Those passages share many
     * entities with the question and dominate the relation → passage join,
     * pushing real source citations off the top-K window. Sorting here makes
     * the top-K reflect authoritative sources first, then chat memory.
     *
     * @internal
     */
    private static function _sort_passages_by_origin( array $passages ): array {
        if ( count( $passages ) <= 1 ) {
            return array_values( $passages );
        }
        // Decorate with original index for stability, then sort.
        $decorated = [];
        foreach ( $passages as $i => $p ) {
            $sid = (int) ( $p['source_id'] ?? 0 );
            $decorated[] = [ 'i' => $i, 'is_chat' => ( $sid <= 0 ) ? 1 : 0, 'p' => $p ];
        }
        usort( $decorated, static function ( $a, $b ) {
            if ( $a['is_chat'] !== $b['is_chat'] ) {
                return $a['is_chat'] - $b['is_chat']; // 0 (file) before 1 (chat)
            }
            return $a['i'] - $b['i']; // stable within group
        } );
        return array_map( static fn( $d ) => $d['p'], $decorated );
    }
}

/* ──────────────────────────────────────────────────────────────────────── */
/* Sprint 4.5h — Global predicate: bizcity_kg_is_main_task()               */
/* ──────────────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'bizcity_kg_is_main_task' ) ) {
    /**
     * Determine whether the current request is a substantive knowledge-seeking
     * task that benefits from KG context injection (Hình thức C).
     *
     * Returns true for most queries (conservative default). Filters allow code
     * to narrow this down — e.g. skip for purely atomic/formatting intents.
     *
     * Usage in decide_retrieval_mode():
     *   if ( ! bizcity_kg_is_main_task( 'twinchat', 'chat', $query ) ) {
     *       return 'skip';
     *   }
     *
     * @param string $plugin       Calling plugin slug, e.g. 'twinchat'.
     * @param string $context_type Context type, e.g. 'chat'.
     * @param string $query        The user message / query text (optional).
     * @return bool True if KG retrieval should run for this task.
     */
    function bizcity_kg_is_main_task( string $plugin = '', string $context_type = '', string $query = '' ): bool {
        // Hard rule: empty or very short queries have nothing to retrieve.
        if ( mb_strlen( trim( $query ) ) < 3 ) {
            return false;
        }
        return (bool) apply_filters( 'bizcity_kg_is_main_task', true, $plugin, $context_type, $query );
    }
}
