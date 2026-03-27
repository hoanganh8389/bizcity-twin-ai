<?php
/**
 * BizCity Twin Context Resolver — Single entry point for ALL context needs.
 *
 * REPLACES scattered context building across intent/chat/notebook/studio/tool.
 * CONSUMES: Focus Router + Snapshot Builder + existing data providers.
 * OUTPUT:   Structured context array ready for prompt rendering.
 *
 * @package  BizCity_Twin_Core
 * @version  0.1.0
 * @since    2026-03-22
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Context_Resolver {

    /* ================================================================
     * PUBLIC API
     * ================================================================ */

    /**
     * Resolve context for a given consumer mode.
     *
     * @param string $mode One of: chat, notebook, planner, execution, studio
     * @param array  $params {
     *   user_id, session_id, project_id, message, intent_conversation_id,
     *   mode_classifier_result, platform_type, images, routing_branch, ...
     * }
     * @return array {
     *   twin_snapshot:     array   Full snapshot state object
     *   identity_context:  string  Rendered identity section
     *   focus_context:     string  Rendered focus section
     *   timeline_context:  string  Rendered timeline section
     *   knowledge_context: string  RAG/source context (if allowed)
     *   memory_context:    string  Focus-filtered memories
     *   trace_meta:        array   { trace_id, mode, focus_profile, token_budget }
     * }
     */
    public static function resolve( string $mode, array $params ): array {
        $user_id    = (int) ( $params['user_id'] ?? 0 );
        $session_id = $params['session_id'] ?? '';
        $message    = $params['message'] ?? '';

        // 1. Resolve focus profile
        $classifier_mode = $params['mode_classifier_result']['mode']
            ?? self::map_consumer_to_classifier_mode( $mode );

        $focus_profile = BizCity_Focus_Router::resolve( array_merge( $params, [
            'mode' => $classifier_mode,
        ] ) );

        // 2. Build snapshot (cached per request, only when flag enabled)
        $snapshot = [];
        if ( defined( 'BIZCITY_TWIN_SNAPSHOT_ENABLED' ) && BIZCITY_TWIN_SNAPSHOT_ENABLED
             && class_exists( 'BizCity_Twin_Snapshot_Builder' ) ) {
            $snapshot = BizCity_Twin_Snapshot_Builder::build( $user_id, $session_id );
        }

        // 3. Render each section gated by focus profile
        $result = [
            'twin_snapshot'     => $snapshot,
            'identity_context'  => self::render_identity( $snapshot, $focus_profile ),
            'focus_context'     => self::render_focus( $snapshot, $focus_profile ),
            'timeline_context'  => self::render_timeline( $snapshot, $focus_profile ),
            'knowledge_context' => self::render_knowledge( $params, $focus_profile ),
            'memory_context'    => self::render_memory( $user_id, $message, $focus_profile ),
            'profile_context'   => self::render_profile( $user_id, $session_id, $params, $focus_profile ),
            'transit_context'   => self::render_transit( $user_id, $session_id, $message, $params, $focus_profile ),
            'trace_meta'        => [
                'trace_id'      => 'trace_' . wp_generate_uuid4(),
                'mode'          => $mode,
                'focus_profile' => $focus_profile,
                'token_budget'  => $focus_profile['token_budget'] ?? 6000,
            ],
        ];

        return $result;
    }

    /** Convenience methods per consumer */
    public static function for_chat( array $p ): array      { return self::resolve( 'chat', $p ); }
    public static function for_notebook( array $p ): array   { return self::resolve( 'notebook', $p ); }
    public static function for_planner( array $p ): array    { return self::resolve( 'planner', $p ); }
    public static function for_execution( array $p ): array  { return self::resolve( 'execution', $p ); }
    public static function for_studio( array $p ): array     { return self::resolve( 'studio', $p ); }

    /* ================================================================
     * BUILD SYSTEM PROMPT — THE single entry point for ALL consumers
     *
     * Replaces scattered context building in:
     *   - Chat Gateway: build_system_prompt(), prepare_llm_call()
     *   - Intent Stream: build_llm_messages()
     *   - Notebook:      fallback_sse()
     *
     * Builds ALL layers, applies filters, returns complete prompt string.
     *
     * @param string $mode   Consumer: chat, notebook, planner, execution, studio
     * @param array  $params {
     *   user_id, session_id, message, character_id, platform_type,
     *   images, via, engine_result, project_id
     * }
     * @return string Complete system prompt
     * ================================================================ */
    public static function build_system_prompt( string $mode, array $params ): string {
        $start   = microtime( true );
        $timing  = [];

        // ── Extract params ──
        $user_id       = (int) ( $params['user_id'] ?? 0 );
        $session_id    = $params['session_id'] ?? '';
        $message       = $params['message'] ?? '';
        $character_id  = (int) ( $params['character_id'] ?? 0 );
        $images        = $params['images'] ?? [];
        $engine_result = $params['engine_result'] ?? [];
        $platform_type = $params['platform_type'] ?? '';
        $via           = $params['via'] ?? $mode;
        $kci_ratio     = (int) ( $params['kci_ratio'] ?? 80 );

        // ── 1. Resolve focus profile via Focus Gate ──
        if ( class_exists( 'BizCity_Focus_Gate' ) ) {
            BizCity_Focus_Gate::ensure_resolved( $message, [
                'user_id'        => $user_id,
                'session_id'     => $session_id,
                'platform_type'  => $platform_type,
                'images'         => $images,
                'mode'           => $engine_result['meta']['mode'] ?? '',
                'active_goal'    => $engine_result['goal'] ?? '',
                'routing_branch' => $engine_result['meta']['routing_branch'] ?? '',
            ] );
            BizCity_Focus_Gate::amend_for_goal( $engine_result['goal'] ?? '' );
        }
        $fp = class_exists( 'BizCity_Focus_Gate' )
            ? ( BizCity_Focus_Gate::get_focus_profile() ?? [] )
            : [];

        // ── Twin Trace ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::log( 'prompt_start', [
                'via'      => $via,
                'mode'     => $mode,
                'platform' => $platform_type,
                'user_id'  => $user_id,
                'resolver' => true,
            ] );
        }

        // ── Debug: log resolved params ──
        error_log( sprintf(
            '[ContextResolver] build_system_prompt START | char_id=%d | platform=%s | user_id=%d | kci=%d | fp_knowledge=%s | fp_notes=%s | fp_astro=%s | fp_transit=%s',
            $character_id, $platform_type, $user_id, $kci_ratio,
            var_export( $fp['knowledge'] ?? null, true ),
            var_export( $fp['notes'] ?? null, true ),
            var_export( $fp['astro'] ?? null, true ),
            var_export( $fp['transit'] ?? null, true )
        ) );

        // ── 2. Build each section ──
        $system_content = '';

        // 2a. Character base persona
        $t0 = microtime( true );
        $char_data      = self::render_character_base( $character_id );
        $system_content = $char_data['prompt'];
        $timing['0:Character'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
        error_log( sprintf(
            '[ContextResolver] Character loaded | id=%d | name=%s | prompt_len=%d',
            $char_data['character_id'] ?? $character_id,
            mb_substr( $char_data['name'] ?? '(unknown)', 0, 50, 'UTF-8' ),
            mb_strlen( $char_data['prompt'] ?? '', 'UTF-8' )
        ) );

        // 2b. User Memory (Layer 0 — highest priority)
        $t0 = microtime( true );
        $memory_context = self::render_user_memory_full( $user_id, $session_id );
        if ( ! empty( $memory_context ) ) {
            $system_content .= $memory_context;
        }
        $timing['1:Memory'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        // 2c. Profile Context (gated by focus profile: astro/coaching)
        $t0 = microtime( true );
        $profile_context = self::render_profile( $user_id, $session_id, $params, $fp );
        if ( ! empty( $profile_context ) ) {
            $system_content .= "\n\n---\n\n" . $profile_context;
        }
        $timing['2:Profile'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        // 2d. Transit Context (gated by focus profile: transit)
        $t0 = microtime( true );
        $transit_context = self::render_transit( $user_id, $session_id, $message, $params, $fp );
        if ( ! empty( $transit_context ) ) {
            $system_content .= "\n\n---\n\n" . $transit_context;
        }
        $timing['3:Transit'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'transit', ! empty( $transit_context ), $timing['3:Transit'] );
        }

        // 2e. Provider Profile Context (per-plugin profile data)
        $t0 = microtime( true );
        $system_content .= self::render_provider_profile_context( $user_id, $engine_result );
        $timing['3.5:ProviderProfile'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );

        // 2f. Knowledge Context (RAG + keyword search)
        $t0 = microtime( true );
        $knowledge_context = self::render_knowledge_rag( $character_id, $message, $images );
        if ( ! empty( $knowledge_context ) ) {
            $system_content .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge_context;
        }
        $timing['4:Knowledge'] = round( ( microtime( true ) - $t0 ) * 1000, 2 );
        error_log( sprintf(
            '[ContextResolver] Knowledge result | has_knowledge=%s | knowledge_len=%d | time_ms=%s',
            ! empty( $knowledge_context ) ? 'YES' : 'NO',
            mb_strlen( $knowledge_context ?? '', 'UTF-8' ),
            $timing['4:Knowledge']
        ) );

        // 2g. Conversation Context (rolling summary + goal + slots from engine_result)
        $system_content .= self::render_conversation_ctx( $engine_result );

        // 2h. Knowledge Expansion (Hybrid C — direct answer from knowledge AI)
        $has_expansion = self::has_knowledge_expansion( $engine_result );
        $system_content .= self::render_knowledge_expansion( $engine_result );

        // 2i. Intent conversation messages (when no expansion + has conversation)
        if ( ! $has_expansion && ! empty( $engine_result['conversation_id'] ) ) {
            $system_content .= self::render_intent_conv_messages( $engine_result, $session_id );
        }

        // 2j. Response Rules (astro grounding, tarot fusion, response depth, language)
        $system_content .= self::render_response_rules(
            $profile_context, $transit_context, $knowledge_context,
            $message, $images, $fp
        );

        // 2k. Role Block (Team Leader identity + behavior) — skip for WEBCHAT
        if ( $platform_type !== 'WEBCHAT' ) {
            $system_content .= self::render_role_block( $engine_result, $has_expansion );
        }

        // 2l. Tool Manifest — graduated by KCI Ratio (knowledge ↔ execution)
        // kci_ratio: 100 = knowledge-only (no tools), 0 = full execution (max tools)
        $execution_ratio = 100 - $kci_ratio;
        $mention_override = ! empty( $params['mention_override'] );
        $manifest_tier = 'none';
        if ( $platform_type === 'WEBCHAT' ) {
            // WEBCHAT always locked at knowledge-only, skip tool manifest entirely
            $manifest_tier = 'webchat_locked';
        } elseif ( $execution_ratio === 0 && ! $mention_override ) {
            // 100% knowledge — no tool awareness at all
            $manifest_tier = 'knowledge_only';
        } elseif ( $execution_ratio === 0 && $mention_override ) {
            // @/ mention override: inject manifest for the mentioned plugin only
            $manifest_tier = 'mention_override';
            $system_content .= self::render_tool_manifest_block();
            $system_content .= "\n🔧 OVERRIDE: Chủ Nhân dùng @/command → inject tool manifest CHO LẦN NÀY. Sau request trở về knowledge-only.";
        } elseif ( $execution_ratio <= 20 ) {
            // Low execution — manifest present but strongly discouraged
            $manifest_tier = 'low_exec';
            $system_content .= self::render_tool_manifest_block();
            $system_content .= "\n⚠️ CHẾ ĐỘ ƯU TIÊN KIẾN THỨC: Hạn chế tối đa gợi ý/sử dụng công cụ. Chỉ dùng tool khi Chủ Nhân YÊU CẦU TRỰC TIẾP và RÕ RÀNG.";
        } elseif ( $execution_ratio <= 50 ) {
            // Balanced — full manifest, neutral behavior
            $manifest_tier = 'balanced';
            $system_content .= self::render_tool_manifest_block();
        } else {
            // High execution (>50%) — full manifest + encourage tool usage
            $manifest_tier = 'high_exec';
            $system_content .= self::render_tool_manifest_block();
            $system_content .= "\n💡 GỢI Ý CÔNG CỤ: Khi có tool phù hợp với yêu cầu, hãy chủ động đề xuất sử dụng.";
        }
        error_log( "[KCI-TRACE] manifest: tier={$manifest_tier}, kci={$kci_ratio}, exec_ratio={$execution_ratio}, mention_override=" . ( $mention_override ? 'true' : 'false' ) );

        // 2m. Twin Suggest (follow-up question suggestions)
        $system_content .= self::render_suggest_block( $user_id, $session_id, $message, $engine_result );

        // 2n. Provider System Instructions (domain-specific AI instructions)
        $system_content .= self::render_provider_instructions( $engine_result );

        // 2o. WEBCHAT frontend widget — knowledge-only restriction
        if ( $platform_type === 'WEBCHAT' ) {
            $system_content .= self::render_webchat_restriction();
        }

        // Fallback
        if ( empty( trim( $system_content ) ) ) {
            if ( $platform_type === 'WEBCHAT' ) {
                $site_name = get_bloginfo( 'name' );
                $system_content = "Bạn là Trợ lý AI hỗ trợ khách hàng của {$site_name}. Trả lời thân thiện, ngắn gọn bằng tiếng Việt.";
            } else {
                $system_content = "Bạn là Trợ lý Team Leader AI cá nhân của BizCity. Trả lời đầy đủ, chi tiết bằng tiếng Việt.";
            }
        }

        // ── 3. Tool context mode for slash commands ──
        $engine_method = $engine_result['meta']['method'] ?? '';
        if ( $engine_method === 'slash_command_direct' && class_exists( 'BizCity_Context_Builder' ) ) {
            BizCity_Context_Builder::instance()->set_tool_context_mode( true );
        }

        // ── 4. Log pre-filter assembly ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            BizCity_User_Memory::log_router_event( [
                'step'             => 'context_build',
                'message'          => mb_substr( $message, 0, 120, 'UTF-8' ),
                'mode'             => $mode,
                'functions_called' => 'BizCity_Twin_Context_Resolver::build_system_prompt()',
                'pipeline'         => [
                    '0:Character'     . ( ! empty( $char_data['prompt'] ) ? ' ✓' : ' —' ),
                    '1:Memory'        . ( ! empty( $memory_context )      ? ' ✓' : ' —' ),
                    '2:Profile'       . ( ! empty( $profile_context )     ? ' ✓' : ' —' ),
                    '3:Transit'       . ( ! empty( $transit_context )     ? ' ✓' : ' —' ),
                    '4:Knowledge'     . ( ! empty( $knowledge_context )   ? ' ✓' : ' —' ),
                    '5:Conversation'  . ( ! empty( $engine_result['conversation_id'] ?? '' ) ? ' ✓' : ' —' ),
                    '5.5:Expansion'   . ( $has_expansion ? ' ✓ HybridC' : ' —' ),
                    '6:Rules ✓',
                    '7:Role ✓',
                    '7.5:Tools',
                    '7.6:Suggest',
                    '→ 8:Filters',
                    '→ 9:EndReminder',
                ],
                'file_line'        => 'class-twin-context-resolver.php::build_system_prompt',
                'via'              => $via,
                'context_length'   => mb_strlen( $system_content, 'UTF-8' ),
                'timing_breakdown' => $timing,
            ], $session_id );
        }

        // ── 5. Apply filters ──
        // BCN sources (pri 15), Response Texture (pri 48), Context Builder (pri 90),
        // Companion Context (pri 97), User Memory (pri 99 — skipped: already_injected).
        $emotion_args = self::compute_emotion_args( $message, $engine_result );
        $filter_args  = array_merge( [
            'character_id'  => $character_id,
            'message'       => $message,
            'user_id'       => $user_id,
            'session_id'    => $session_id,
            'platform_type' => $platform_type,
            'via'           => $via,
            'images'        => $images,
            'active_goal'   => $engine_result['goal'] ?? '',
        ], $emotion_args );

        $system_content = apply_filters( 'bizcity_chat_system_prompt', $system_content, $filter_args );

        // ── 6. Log final prompt ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $prompt_len = mb_strlen( $system_content, 'UTF-8' );
            BizCity_User_Memory::log_router_event( [
                'step'             => 'final_prompt',
                'message'          => "System prompt built (via {$via}, resolver)",
                'mode'             => 'debug',
                'functions_called' => 'BizCity_Twin_Context_Resolver::build_system_prompt()',
                'file_line'        => 'class-twin-context-resolver.php::build_system_prompt',
                'via'              => $via,
                'prompt_length'    => $prompt_len,
                'has_memory'       => ( strpos( $system_content, 'KÝ ỨC USER' ) !== false ),
                'has_bizcoach'     => ! empty( $profile_context ),
                'has_transit'      => ! empty( $transit_context ),
                'prompt_head'      => mb_substr( $system_content, 0, 500, 'UTF-8' ),
                'prompt_tail'      => $prompt_len > 1000 ? mb_substr( $system_content, -500, 500, 'UTF-8' ) : '',
                'full_prompt'      => $system_content,
                'build_ms'         => round( ( microtime( true ) - $start ) * 1000, 2 ),
            ], $session_id );
        }

        // ── 7. End Reminder (MUST be LAST — closest to user message) ──
        $system_content .= self::render_end_reminder_block( $message, $profile_context, $session_id, $platform_type );

        // ── Twin Trace: complete ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::log( 'resolver_build_complete', [
                'mode'     => $mode,
                'via'      => $via,
                'timing'   => $timing,
                'total_ms' => round( ( microtime( true ) - $start ) * 1000, 2 ),
                'length'   => mb_strlen( $system_content, 'UTF-8' ),
            ] );
        }

        return $system_content;
    }

    /* ================================================================
     * PROMPT RENDERERS — Used by build_system_prompt()
     * ================================================================ */

    /**
     * Render character base persona.
     *
     * When character_id = 0 (e.g. WEBCHAT without configured default),
     * auto-resolve to first active character on the current site.
     */
    private static function render_character_base( int $character_id ): array {
        $character = null;
        if ( class_exists( 'BizCity_Knowledge_Database' ) ) {
            $db = BizCity_Knowledge_Database::instance();
            if ( $character_id ) {
                $character = $db->get_character( $character_id );
            }
            // Auto-resolve when character_id = 0 or character not found
            if ( ! $character ) {
                $chars = $db->get_characters( [ 'status' => 'active', 'limit' => 1 ] );
                if ( ! empty( $chars ) ) {
                    $character = $chars[0];
                    error_log( "[ContextResolver] render_character_base: auto-resolved character_id={$character->id} ({$character->name})" );
                }
            }
        }
        $prompt = '';
        if ( $character && ! empty( $character->system_prompt ) ) {
            $prompt = $character->system_prompt;
        }
        return [ 'prompt' => $prompt, 'character' => $character ];
    }

    /**
     * Render user memory context (Layer 0 — highest priority).
     * Delegates to BizCity_User_Memory::build_memory_context().
     */
    private static function render_user_memory_full( int $user_id, string $session_id ): string {
        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return '';
        }
        $mem   = BizCity_User_Memory::instance();
        $q_uid = $user_id > 0 ? $user_id : 0;
        $q_sid = $user_id > 0 ? ''       : $session_id;
        return $mem->build_memory_context( $q_uid, $q_sid, $session_id );
    }

    /**
     * Render knowledge context — RAG + keyword search.
     *
     * When character_id = 0 (e.g. WEBCHAT without configured default),
     * auto-resolve to first active character on the current site so that
     * knowledge chunks can still be retrieved.
     */
    private static function render_knowledge_rag( int $character_id, string $message, array $images ): string {
        $original_char_id = $character_id;

        // Auto-resolve character_id when 0 — pick first active character on this site
        if ( ! $character_id && class_exists( 'BizCity_Knowledge_Database' ) ) {
            $chars = BizCity_Knowledge_Database::instance()->get_characters( [ 'status' => 'active', 'limit' => 1 ] );
            if ( ! empty( $chars ) ) {
                $character_id = (int) $chars[0]->id;
                error_log( "[ContextResolver] render_knowledge_rag: auto-resolved character_id={$character_id}" );
            }
        }

        error_log( sprintf(
            '[ContextResolver] render_knowledge_rag START | original_char_id=%d | resolved_char_id=%d | message=%s | has_images=%s',
            $original_char_id, $character_id,
            mb_substr( $message, 0, 80, 'UTF-8' ),
            ! empty( $images ) ? 'yes' : 'no'
        ) );

        $knowledge = '';
        if ( class_exists( 'BizCity_Knowledge_Context_API' ) ) {
            $ctx = BizCity_Knowledge_Context_API::instance()->build_context( $character_id, $message, [
                'max_tokens'     => 3000,
                'include_vision' => ! empty( $images ),
                'images'         => $images,
            ] );
            $knowledge = $ctx['context'] ?? '';
            error_log( sprintf(
                '[ContextResolver] render_knowledge_rag | Context API returned: context_len=%d | tokens_used=%d | parts=%s | sources=%d',
                mb_strlen( $knowledge, 'UTF-8' ),
                $ctx['tokens_used'] ?? 0,
                implode( ',', array_column( $ctx['parts'] ?? [], 'type' ) ) ?: '(none)',
                count( $ctx['sources'] ?? [] )
            ) );
        } else {
            error_log( '[ContextResolver] render_knowledge_rag | BizCity_Knowledge_Context_API class NOT found' );
        }

        if ( $character_id && function_exists( 'bizcity_knowledge_search_character' ) ) {
            $kw_ctx = bizcity_knowledge_search_character( $message, $character_id );
            if ( ! empty( $kw_ctx ) ) {
                if ( ! empty( $knowledge ) ) {
                    if ( strpos( $knowledge, $kw_ctx ) === false ) {
                        $knowledge .= "\n\n---\n\n### Kiến thức bổ sung (keyword search):\n" . $kw_ctx;
                    }
                } else {
                    $knowledge = $kw_ctx;
                }
                error_log( "[ContextResolver] render_knowledge_rag | keyword search appended: len=" . mb_strlen( $kw_ctx, 'UTF-8' ) );
            }
        }

        error_log( '[ContextResolver] render_knowledge_rag END | total_knowledge_len=' . mb_strlen( $knowledge, 'UTF-8' ) );
        return $knowledge;
    }

    /**
     * Render provider profile context — per-plugin profile data.
     */
    private static function render_provider_profile_context( int $user_id, array $engine_result ): string {
        if ( ! class_exists( 'BizCity_Intent_Provider_Registry' ) ) {
            return '';
        }
        $registry    = BizCity_Intent_Provider_Registry::instance();
        $active_goal = $engine_result['goal'] ?? '';
        $output      = '';

        foreach ( $registry->get_all() as $provider ) {
            if ( $active_goal && ! $provider->owns_goal( $active_goal ) ) {
                continue;
            }
            $profile = $provider->get_profile_context( $user_id );
            if ( ! empty( $profile['context'] ) ) {
                $output .= "\n\n---\n\n### 👤 Hồ sơ người dùng (" . $provider->get_name() . "):\n" . $profile['context'];
            }
            if ( ! ( $profile['complete'] ?? true ) && ! empty( $profile['fallback'] ) ) {
                $output .= "\n\n⚠️ " . $profile['fallback'];
            }
        }
        return $output;
    }

    /**
     * Render conversation context from engine_result — rolling summary + goal + slots.
     */
    private static function render_conversation_ctx( array $engine_result ): string {
        if ( empty( $engine_result['conversation_id'] ) ) {
            return '';
        }
        $output = '';
        if ( ! empty( $engine_result['rolling_summary'] ) ) {
            $output .= "\n\n---\n\n### 🧵 Tóm tắt hội thoại hiện tại:\n" . $engine_result['rolling_summary'];
        }
        if ( ! empty( $engine_result['goal'] ) ) {
            $output .= "\n\n### 🎯 Mục tiêu hiện tại: " . ( $engine_result['goal_label'] ?? $engine_result['goal'] );
            if ( ! empty( $engine_result['slots'] ) ) {
                $output .= "\nThông tin đã thu thập: " . wp_json_encode( $engine_result['slots'], JSON_UNESCAPED_UNICODE );
            }
        }
        return $output;
    }

    /**
     * Check if engine_result has knowledge expansion (Hybrid C).
     */
    private static function has_knowledge_expansion( array $engine_result ): bool {
        return isset( $engine_result['action'] )
            && $engine_result['action'] === 'reply'
            && ! empty( $engine_result['reply'] );
    }

    /**
     * Render knowledge expansion (Hybrid C — direct answer from knowledge AI).
     */
    private static function render_knowledge_expansion( array $engine_result ): string {
        if ( ! self::has_knowledge_expansion( $engine_result ) ) {
            return '';
        }
        return "\n\n---\n\n## 🔍 KIẾN THỨC MỞ RỘNG (trả lời từ AI kiến thức nội bộ):\n"
            . $engine_result['reply'] . "\n";
    }

    /**
     * Render intent conversation messages (raw webchat messages by conversation_id).
     * Only used when there's no knowledge expansion.
     */
    private static function render_intent_conv_messages( array $engine_result, string $session_id ): string {
        $conv_id = $engine_result['conversation_id'] ?? '';
        if ( empty( $conv_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
            return '';
        }
        $wc_db = BizCity_WebChat_Database::instance();
        if ( ! method_exists( $wc_db, 'get_recent_messages_by_intent_conversation' ) ) {
            return '';
        }
        $msgs = $wc_db->get_recent_messages_by_intent_conversation( $conv_id, $session_id, 15 );
        if ( empty( $msgs ) ) {
            return '';
        }
        $lines = [];
        foreach ( $msgs as $row ) {
            $who  = ( $row->message_from === 'user' ) ? 'Chủ Nhân (User)' : 'AI Trợ lý (Bạn)';
            $text = mb_substr( $row->message_text, 0, 500, 'UTF-8' );
            $lines[] = "- {$who}: {$text}";
        }
        return "\n\n---\n\n## 💬 NGỮ CẢNH HỘI THOẠI HIỆN TẠI:\n"
            . "_(Chủ Nhân = Người dùng. AI Trợ lý = Bạn.)_\n"
            . implode( "\n", $lines ) . "\n";
    }

    /**
     * Render response rules — astro grounding, tarot fusion, response depth, language.
     */
    private static function render_response_rules(
        string $profile_ctx,
        string $transit_ctx,
        string $knowledge_ctx,
        string $message,
        array $images,
        array $fp
    ): string {
        $inject_astro = empty( $fp ) || ! empty( $fp['astro'] );

        $rules = "\n\n---\n\n## QUY TẮC TRẢ LỜI (BẮT BUỘC — ƯU TIÊN CAO NHẤT):\n";

        if ( ! empty( $profile_ctx ) ) {
            $rules .= "### 📌 Nhận diện người dùng:\n";
            $rules .= "1. Bạn ĐÃ BIẾT người đang trò chuyện thông qua Hồ Sơ Chủ Nhân ở trên. ";
            $rules .= "Khi họ hỏi \"tôi là ai\", \"bạn biết tôi không\", hãy trả lời TỰ TIN dựa trên hồ sơ.\n";
            $rules .= "2. Luôn gọi người dùng bằng TÊN khi có thể.\n\n";

            if ( $inject_astro ) {
                $rules .= "### 🔒 NỀN TẢNG TRẢ LỜI — LUÔN BÁM THEO DỮ LIỆU:\n";
                $rules .= "🔴 **QUY TẮC CỐT LÕI**: Mọi câu trả lời về cuộc sống, tương lai, tính cách, sự nghiệp, tài chính, tình cảm, hôn nhân, sức khỏe ĐỀU PHẢI dựa trên:\n";
                $rules .= "   a) **Bản đồ chiêm tinh natal** — đã có trong Hồ Sơ Chủ Nhân\n";
                $rules .= "   b) **Kết quả luận giải (gen_results)** — SWOT, thần số học, ngũ hành\n";
                $rules .= "   c) **Câu trả lời coaching (answer_json)** — thông tin user tự khai\n";
                if ( ! empty( $transit_ctx ) ) {
                    $rules .= "   d) **Dữ liệu Transit chiêm tinh** — vị trí THỰC TẾ các sao\n";
                }
                $rules .= "\n🚫 **CẤM**: KHÔNG bịa đặt vị trí sao, góc chiếu. KHÔNG trả lời chung chung thiếu dữ liệu.\n\n";

                $rules .= "✅ **YÊU CẦU BẮT BUỘC khi trả lời về tương lai/dự báo**:\n";
                $rules .= "   - Luôn nhắc TÊN SAO + CUNG + GÓC CHIẾU\n";
                $rules .= "   - Liên hệ trực tiếp với natal chart và gen_results\n";
                $rules .= "   - Tham chiếu answer_json khi liên quan\n";
                if ( ! empty( $transit_ctx ) ) {
                    $rules .= "   - Sử dụng DỮ LIỆU TRANSIT THỰC TẾ đã cung cấp\n";
                }
                $rules .= "\n";
            }
        }

        if ( ! empty( $transit_ctx ) && $inject_astro ) {
            $rules .= "### ⭐ ĐẶC BIỆT — DỮ LIỆU TRANSIT:\n";
            $rules .= "Dữ liệu transit THỰC TẾ đã cung cấp. Bạn PHẢI:\n";
            $rules .= "- Phân tích dựa HOÀN TOÀN trên transit thực tế + natal chart\n";
            $rules .= "- Giải thích: sao transit nào, cung nào, góc chiếu gì\n";
            $rules .= "- Liên hệ gen_results và answer_json để cá nhân hóa\n\n";
        }

        if ( ! empty( $images ) && ! empty( $profile_ctx ) && $inject_astro ) {
            $rules .= "### 🃏 KHI USER GỬI ẢNH LÁ BÀI / HÌNH ẢNH:\n";
            $rules .= "PHẢI trả lời: 1) Nhận diện ảnh → 2) Ý nghĩa phổ quát → 3) Chiếu lên natal chart → ";
            $rules .= ! empty( $transit_ctx )
                ? "4) Transit hiện tại → 5) Lời khuyên cá nhân hóa.\n"
                : "4) Lời khuyên cá nhân hóa.\n";
            $rules .= "⛔ NGHIÊM CẤM trả lời chung chung không nhắc natal chart.\n\n";
        }

        if ( ! empty( $knowledge_ctx ) ) {
            $rules .= "### 📚 Kiến thức: Ưu tiên kiến thức tham khảo. Nếu không có, dùng hiểu biết chung.\n";
        }

        // Response depth
        $astro_intent = ! empty( $transit_ctx ) || ! empty( $images )
            || (bool) preg_match(
                '/chiêm tinh|natal|transit|tarot|lá bài|bói|tử vi|phong thủy|'
                . 'hôm nay thế nào|ngày mai|tuần tới|tháng này|tháng sau|năm tới|'
                . 'dự báo|xu hướng|tính cách|mệnh|nghiệp|'
                . 'tình duyên|sự nghiệp|tài chính|sức khỏe|hôn nhân|tương lai/ui',
                $message
            );

        if ( $astro_intent ) {
            $rules .= "### 📏 ĐỘ DÀI TRẢ LỜI (BẮT BUỘC):\n";
            $rules .= "Chủ đề chiêm tinh/tarot/dự báo → ĐẦY ĐỦ, CỤ THỂ (200–400 từ):\n";
            $rules .= "1. Phân tích có đánh số. 2. TÊN SAO + CUNG + GÓC CHIẾU. 3. 2–3 lời khuyên. 4. Giọng thân mật.\n";
            $rules .= "🚫 KHÔNG vắn tắt 1–2 câu.\n\n";
        } else {
            $rules .= "### 🗨️ Phong cách: Rõ ràng, đầy đủ. Đơn giản → ngắn; phân tích → chiết lọc.\n\n";
        }

        $rules .= "### 🗣️ Ngôn ngữ: Trả lời bằng tiếng Việt, thân thiện, tự nhiên, giàu cảm xúc.\n";

        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::layer( 'astro_rules', $inject_astro );
        }

        return $rules;
    }

    /**
     * Render role block — Team Leader identity + behavioral instructions.
     */
    private static function render_role_block( array $engine_result, bool $has_expansion ): string {
        $role  = "\n\n## 🧑‍💼 VAI TRÒ CỦA BẠN:\n";
        $role .= "Bạn là **Trợ lý Team Leader cá nhân** của Chủ Nhân (người đang trò chuyện).\n";
        $role .= "- Điều phối, tư vấn và hỗ trợ Chủ Nhân quản lý công việc, cuộc sống.\n";
        $role .= "- Hệ thống BizCity có NHIỀU AI Agent chuyên biệt khác có thể giúp thực thi công việc.\n";

        if ( $has_expansion ) {
            $role .= "- 🔍 **QUAN TRỌNG**: Có phần **KIẾN THỨC MỞ RỘNG** — HÃY SỬ DỤNG làm NỘI DUNG CHÍNH.\n";
            $role .= "- KHÔNG gợi ý Chợ AI Agent. Kiến thức đã có sẵn.\n";
            $role .= "- Trình bày lại kiến thức một cách tự nhiên, thân thiện, cá nhân hóa.\n";
        } else {
            $role .= "- HÃY TRẢ LỜI dựa trên hiểu biết và ngữ cảnh hội thoại hiện có.\n";
            $role .= "- KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent. Chỉ tập trung trả lời.\n";
        }

        $role .= "\n### ⛔ RANH GIỚI VAI TRÒ BẮT BUỘC:\n";
        $role .= "- Bạn là AI Trợ lý. Chủ Nhân là NGƯỜI DÙNG đang nhắn tin cho bạn.\n";
        $role .= "- KHÔNG BAO GIỜ tự xưng bằng tên Chủ Nhân (VD: không nói \"Chu đây!\").\n";
        $role .= "- KHÔNG nhập vai thành Chủ Nhân. KHÔNG nói như thể BẠN là người dùng.\n";
        $role .= "- Khi xưng hô 'mày tao': Chủ Nhân xưng 'tao', gọi AI là 'mày'. AI KHÔNG xưng 'tao'.\n";

        return $role;
    }

    /**
     * Render tool manifest — passive self-awareness (tool list so AI can answer "bạn có công cụ gì?").
     */
    private static function render_tool_manifest_block(): string {
        if ( ! class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            return '';
        }
        $manifest = BizCity_Intent_Tool_Index::instance()->build_tools_context( 1500 );
        if ( empty( $manifest ) ) {
            return '';
        }
        $output  = "\n\n" . $manifest;
        $output .= "\n\n**LƯU Ý CÔNG CỤ**: Chỉ liệt kê công cụ khi Chủ Nhân HỎI TRỰC TIẾP. KHÔNG tự gợi ý công cụ.";
        $output .= "\nKhi được hỏi: nêu TÊN + MÔ TẢ ngắn gọn.";
        return $output;
    }

    /**
     * Render WEBCHAT frontend restriction — knowledge-only, no execution.
     *
     * Applied when platform_type === 'WEBCHAT' (floating widget on frontend).
     * Prevents execution tasks, tool invocation, limits response length.
     * Includes site-specific CSKH (customer support) identity.
     */
    private static function render_webchat_restriction(): string {
        $site_name = get_bloginfo( 'name' );
        $r  = "\n\n# ⚠️ CHẾ ĐỘ WIDGET FRONTEND (BẮT BUỘC):\n";
        $r .= "Bạn đang hoạt động ở chế độ **Widget hỗ trợ khách hàng** trên trang web **{$site_name}**.\n";
        $r .= "\n## 🧑‍💼 VAI TRÒ CỦA BẠN:\n";
        $r .= "- Bạn là **Trợ lý AI hỗ trợ khách hàng** của **{$site_name}**.\n";
        $r .= "- Trả lời thân thiện, chuyên nghiệp, phục vụ khách hàng đang truy cập trang web.\n";
        $r .= "- KHÔNG xưng là 'Team Leader', 'Chủ Nhân', hoặc bất kỳ vai trò nội bộ nào.\n";
        $r .= "- KHÔNG đề cập đến hệ thống BizCity, AI Agent, hay bất kỳ nội bộ nào.\n";
        $r .= "\n## 🚫 GIỚI HẠN TUYỆT ĐỐI:\n";
        $r .= "- KHÔNG thực thi bất kỳ công cụ, hành động (execution) nào.\n";
        $r .= "- KHÔNG gọi tool, function, API, hay bất kỳ tác vụ nào.\n";
        $r .= "- KHÔNG gợi ý hoặc đề cập đến công cụ, slash command, hay khả năng thực thi.\n";
        $r .= "- KHÔNG tạo, chỉnh sửa, xóa dữ liệu hệ thống.\n";
        $r .= "- KHÔNG đặt lịch, nhắc nhở, hay tự động hóa.\n";
        $r .= "\n## ✅ NHIỆM VỤ DUY NHẤT:\n";
        $r .= "- Trả lời câu hỏi dựa trên **kiến thức (knowledge base)** đã được cung cấp.\n";
        $r .= "- Tư vấn, hỗ trợ, CSKH (chăm sóc khách hàng) thân thiện.\n";
        $r .= "- Sử dụng ngữ cảnh hội thoại hiện tại (session chat) để trả lời mạch lạc.\n";
        $r .= "- Trả lời ngắn gọn, súc tích (tối đa ~500 token).\n";
        $r .= "- Nếu không biết câu trả lời, nói rõ và gợi ý liên hệ hỗ trợ trực tiếp.\n";
        return $r;
    }

    /**
     * Render follow-up suggestion instructions.
     */
    private static function render_suggest_block( int $user_id, string $session_id, string $message, array $engine_result ): string {
        if ( ! class_exists( 'BizCity_Twin_Suggest' ) ) {
            return '';
        }
        return BizCity_Twin_Suggest::build( [
            'user_id'       => $user_id,
            'session_id'    => $session_id,
            'message'       => $message,
            'mode'          => $engine_result['meta']['mode'] ?? '',
            'engine_result' => $engine_result,
        ] ) ?: '';
    }

    /**
     * Render provider system instructions (domain-specific AI instructions from engine_result).
     */
    private static function render_provider_instructions( array $engine_result ): string {
        $output = '';
        if ( ! empty( $engine_result['meta']['system_instructions'] ) ) {
            $output .= "\n\n" . $engine_result['meta']['system_instructions'];
        }
        if ( ! empty( $engine_result['meta']['provider_context'] ) ) {
            $output .= "\n\n" . $engine_result['meta']['provider_context'];
        }
        return $output;
    }

    /**
     * Render end reminder — prohibited phrases, tool registry verification, fallback template.
     * MUST be positioned LAST in system prompt (closest to user message).
     */
    private static function render_end_reminder_block( string $message, string $profile_context, string $session_id, string $platform_type = '' ): string {
        $end  = "\n\n# ⚠️ NHẮC NHỞ QUAN TRỌNG (BẮT BUỘC ĐỌC TRƯỚC KHI TRẢ LỜI):\n";
        $end .= "\n## 🚫 DANH SÁCH CÂU BỊ CẤM — KHÔNG BAO GIỜ ĐƯỢC NÓI:\n";
        $end .= "- 'tôi không có quyền truy cập thông tin cá nhân'\n";
        $end .= "- 'tôi không có quyền truy cập vào thông tin cá nhân hoặc hồ sơ'\n";
        $end .= "- 'hãy liên hệ bộ phận hỗ trợ'\n";
        $end .= "- 'tôi không biết thông tin về bạn'\n";
        $end .= "- 'tôi không có khả năng truy cập'\n";
        $end .= "- 'tôi là AI nên không thể truy cập'\n";
        $end .= "- 'liên hệ email/hotline/admin để được hỗ trợ'\n";
        $end .= "- Bất kỳ biến thể nào của các câu trên\n";
        $end .= "Nếu bạn sắp nói bất kỳ câu nào giống như trên → DỪNG LẠI và dùng mẫu fallback bên dưới.\n";

        if ( ! empty( $profile_context ) ) {
            $end .= "\n## ✅ BẠN ĐÃ CÓ HỒ SƠ CHỦ NHÂN:\n";
            $end .= "- Hồ sơ người dùng đã được cung cấp ở phần trên.\n";
            $end .= "- HÃY sử dụng hồ sơ để cá nhân hóa câu trả lời.\n";
            $end .= "- HÃY gọi người dùng bằng TÊN (nếu có trong hồ sơ).\n";
        }

        // Tool registry verification
        $matching_tool = null;
        if ( class_exists( 'BizCity_Intent_Tool_Index' ) ) {
            $msg_lower = mb_strtolower( trim( $message ), 'UTF-8' );
            $msg_words = array_filter(
                preg_split( '/[\s,;.!?]+/u', $msg_lower ),
                function( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 2; }
            );
            if ( ! empty( $msg_words ) ) {
                $tools = BizCity_Intent_Tool_Index::instance()->get_all_active();
                foreach ( $tools as $row ) {
                    $fields = mb_strtolower(
                        ( $row['goal'] ?? '' ) . ' ' . ( $row['title'] ?? '' ) . ' '
                        . ( $row['goal_label'] ?? '' ) . ' ' . ( $row['custom_hints'] ?? '' ) . ' '
                        . ( $row['goal_description'] ?? '' ) . ' ' . ( $row['plugin'] ?? '' ),
                        'UTF-8'
                    );
                    foreach ( $msg_words as $kw ) {
                        if ( mb_strpos( $fields, $kw ) !== false && mb_strlen( $kw, 'UTF-8' ) >= 3 ) {
                            $matching_tool = $row;
                            break 2;
                        }
                    }
                }
            }
        }

        if ( $matching_tool ) {
            $end .= "\n## 📋 HƯỚNG DẪN:\n";
            $end .= "→ TUYỆT ĐỐI KHÔNG nói 'mình chưa có trợ lý chuyên về...'.\n";
            $end .= "→ HÃY TRẢ LỜI câu hỏi dựa trên hiểu biết.\n";
            $end .= "→ KHÔNG gợi ý công cụ, KHÔNG gợi ý Chợ AI Agent.\n";
            $end .= "→ Cuối câu trả lời, đặt 1-2 câu hỏi gợi mở.\n";
        } elseif ( $platform_type === 'WEBCHAT' ) {
            $end .= "\n## 📋 HƯỚNG DẪN:\n";
            $end .= "→ HÃY TRẢ LỜI câu hỏi dựa trên kiến thức đã cung cấp.\n";
            $end .= "→ Nếu không biết, nói rõ: 'Hiện mình chưa có thông tin về vấn đề này. Bạn có thể liên hệ trực tiếp để được hỗ trợ thêm!'\n";
            $end .= "→ KHÔNG đề cập Team Leader, Chợ AI Agent, hay bất kỳ hệ thống nội bộ nào.\n";
        } else {
            $end .= "\n## 📋 MẪU TRẢ LỜI FALLBACK — khi chức năng CHƯA CÓ trên hệ thống:\n";
            $end .= "Ví dụ: nghe nhạc, phát nhạc, xem phim, đặt hàng, chuyển khoản, gọi điện...\n";
            $end .= "→ Trả lời: 'Hiện tại mình chưa có trợ lý chuyên về [chức năng]. Nhưng bạn có thể vào **Chợ AI Agent** của BizCity để chọn một trợ lý phù hợp — sau khi kích hoạt, mình sẽ phối hợp với Agent đó để giúp bạn! 🚀'\n";
            $end .= "→ KHÔNG nói 'không có quyền', KHÔNG nói 'liên hệ hỗ trợ'.\n";
            $end .= "→ Luôn thể hiện: 'Mình là Team Leader của bạn — việc gì cũng có cách giải quyết!'\n";
        }

        return $end;
    }

    /**
     * Compute emotion args for filter chain (Texture Engine, Companion Context).
     */
    private static function compute_emotion_args( string $message, array $engine_result ): array {
        $intensity     = 1;
        $empathy       = false;
        $valence       = 'neutral';
        $emotion       = 'none';
        $empathy_level = 'none';
        $mode          = $engine_result['meta']['mode'] ?? 'knowledge';
        $routing_branch = $engine_result['meta']['routing_branch'] ?? 'knowledge';

        if ( class_exists( 'BizCity_Emotional_Memory' ) ) {
            $struct        = BizCity_Emotional_Memory::instance()->estimate_emotion( $message );
            $intensity     = $struct['intensity'];
            $valence       = $struct['valence'];
            $emotion       = $struct['emotion'];
            $empathy_level = $struct['empathy_level'];
            $empathy       = ( $intensity >= 3 )
                          && in_array( $mode, [ 'emotion', 'reflection' ], true );
        }

        return [
            'mode'           => $mode,
            'intensity'      => $intensity,
            'valence'        => $valence,
            'emotion'        => $emotion,
            'empathy_level'  => $empathy_level,
            'empathy_flag'   => $empathy,
            'routing_branch' => $routing_branch,
        ];
    }

    /* ================================================================
     * SNAPSHOT RENDERERS — Used by resolve() for Sprint 0B fragments
     * Each reads focus_profile to decide include/skip
     * ================================================================ */

    /**
     * Render identity section: who is this user + support style.
     */
    private static function render_identity( array $snapshot, array $fp ): string {
        if ( empty( $fp['identity'] ) ) {
            return '';
        }

        $id = $snapshot['identity'] ?? [];
        if ( empty( $id ) ) {
            return '';
        }

        $lines = [];
        if ( ! empty( $id['display_name'] ) ) {
            $lines[] = 'Người dùng: ' . $id['display_name'];
        }
        if ( ! empty( $id['support_style'] ) ) {
            $style_labels = [
                'direct_but_warm' => 'trực tiếp, ấm áp',
                'gentle'          => 'nhẹ nhàng, cảm thông',
                'analytical'      => 'phân tích, logic',
            ];
            $label = $style_labels[ $id['support_style'] ] ?? $id['support_style'];
            $lines[] = 'Phong cách hỗ trợ: ' . $label;
        }
        if ( ! empty( $id['preferences'] ) ) {
            $lines[] = 'Sở thích đã ghi nhớ:';
            foreach ( array_slice( $id['preferences'], 0, 5 ) as $pref ) {
                $lines[] = '  - ' . $pref;
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Render focus section: what the user is currently working on.
     */
    private static function render_focus( array $snapshot, array $fp ): string {
        if ( empty( $fp['focus_current'] ) && empty( $fp['open_loops'] ) ) {
            return '';
        }

        $focus = $snapshot['focus'] ?? [];
        if ( empty( $focus ) ) {
            return '';
        }

        $lines = [];

        if ( ! empty( $fp['focus_current'] ) && ! empty( $focus['current_focus'] ) ) {
            $cf = $focus['current_focus'];
            $lines[] = 'Trọng tâm hiện tại: ' . ( $cf['label'] ?? 'không xác định' );
        }

        if ( ! empty( $fp['open_loops'] ) && ! empty( $focus['open_loops'] ) ) {
            $loops = array_slice( $focus['open_loops'], 0, 3 );
            if ( $loops ) {
                $lines[] = 'Luồng mở:';
                foreach ( $loops as $loop ) {
                    $lines[] = '  - ' . ( $loop['label'] ?? '' );
                }
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Render timeline section: today's context.
     */
    private static function render_timeline( array $snapshot, array $fp ): string {
        $timeline = $snapshot['timeline'] ?? [];
        if ( empty( $timeline ) ) {
            return '';
        }

        $lines = [];

        // Today context — brief
        if ( ! empty( $timeline['today_context'] ) ) {
            $items = array_slice( $timeline['today_context'], -5 );
            $lines[] = 'Hôm nay:';
            foreach ( $items as $item ) {
                $lines[] = '  [' . ( $item['time'] ?? '' ) . '] ' . ( $item['summary'] ?? '' );
            }
        }

        // Active emotional threads
        if ( ! empty( $fp['emotional_threads'] ) && ! empty( $timeline['active_threads'] ) ) {
            $lines[] = 'Chủ đề cảm xúc đang mở:';
            foreach ( array_slice( $timeline['active_threads'], 0, 3 ) as $t ) {
                $lines[] = '  - ' . ( $t['topic'] ?? '' );
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Render knowledge context — delegate to existing knowledge providers.
     * Only when focus profile allows.
     */
    private static function render_knowledge( array $params, array $fp ): string {
        if ( empty( $fp['knowledge'] ) ) {
            return '';
        }

        // Delegate to existing RAG system if available
        if ( class_exists( 'BizCity_Knowledge_Provider' ) ) {
            $provider = BizCity_Knowledge_Provider::instance();
            $message  = $params['message'] ?? '';
            $user_id  = (int) ( $params['user_id'] ?? 0 );
            if ( method_exists( $provider, 'search_relevant' ) ) {
                $results = $provider->search_relevant( $message, $user_id, 3 );
                if ( ! empty( $results ) ) {
                    $lines = [ 'Tri thức liên quan:' ];
                    foreach ( $results as $r ) {
                        $lines[] = '  - ' . ( is_string( $r ) ? $r : ( $r['content'] ?? '' ) );
                    }
                    return implode( "\n", $lines );
                }
            }
        }

        return '';
    }

    /**
     * Render memory context — uses focus-filtered user memories.
     */
    private static function render_memory( int $user_id, string $message, array $fp ): string {
        $memory_mode = $fp['memory'] ?? 'all';
        if ( $memory_mode === false || $memory_mode === 'none' ) {
            return '';
        }

        if ( ! class_exists( 'BizCity_User_Memory' ) ) {
            return '';
        }

        $mem = BizCity_User_Memory::instance();

        // Apply memory mode from focus profile
        $query_args = [
            'user_id' => $user_id,
            'limit'   => 30,
        ];

        if ( $memory_mode === 'explicit' ) {
            $query_args['memory_tier'] = 'explicit';
            $query_args['limit']       = 10;
        }

        $memories = $mem->get_memories( $query_args );

        if ( empty( $memories ) ) {
            return '';
        }

        // Filter for relevance if mode says so
        if ( $memory_mode === 'relevant' && ! empty( $message ) ) {
            $memories = BizCity_User_Memory::filter_relevant_memories( $memories, $message );
        }

        if ( empty( $memories ) ) {
            return '';
        }

        $lines = [ 'Bộ nhớ người dùng:' ];
        foreach ( $memories as $m ) {
            $text = is_object( $m ) ? ( $m->memory_text ?? '' ) : ( $m['memory_text'] ?? '' );
            if ( $text ) {
                $lines[] = '  - ' . $text;
            }
        }

        return implode( "\n", $lines );
    }

    /**
     * Render profile context — delegates to BizCity_Profile_Context, gated by focus profile.
     * This is the astro/SWOT/coaching data from bizcoach-map.
     */
    private static function render_profile( int $user_id, string $session_id, array $params, array $fp ): string {
        // Profile is gated by 'astro' or 'coaching' — full bizcoach profile (natal chart, SWOT, gen_results)
        // is heavy and should only load when the mode actually needs it.
        // Note: 'identity' is NOT checked here — it's always truthy and is handled by render_identity().
        if ( empty( $fp['astro'] ) && empty( $fp['coaching'] ) ) {
            return '';
        }

        if ( ! class_exists( 'BizCity_Profile_Context' ) ) {
            return '';
        }

        $platform_type = $params['platform_type'] ?? '';
        $profile_ctx   = BizCity_Profile_Context::instance();

        return $profile_ctx->build_user_context(
            $user_id ?: get_current_user_id(),
            $session_id,
            $platform_type,
            [ 'coach_type' => '' ]
        );
    }

    /**
     * Render transit context — delegates to BizCity_Profile_Context, gated by focus profile.
     * Transit = real-time planetary positions affecting the user's natal chart.
     */
    private static function render_transit( int $user_id, string $session_id, string $message, array $params, array $fp ): string {
        if ( empty( $fp['transit'] ) ) {
            return '';
        }

        if ( ! class_exists( 'BizCity_Profile_Context' ) ) {
            return '';
        }

        $platform_type = $params['platform_type'] ?? '';
        $images        = $params['images'] ?? [];
        $active_goal   = $params['engine_result']['goal'] ?? $params['active_goal'] ?? '';
        $profile_ctx   = BizCity_Profile_Context::instance();

        $transit = $profile_ctx->build_transit_context(
            $message,
            $user_id ?: get_current_user_id(),
            $session_id,
            $platform_type,
            $active_goal
        );

        // Fallback: force transit for vision images (Tarot/photo)
        if ( empty( $transit ) && ! empty( $images ) ) {
            $transit = $profile_ctx->build_transit_context(
                'chiêm tinh tháng này',
                $user_id ?: get_current_user_id(),
                $session_id,
                $platform_type,
                $active_goal
            );
        }

        return $transit;
    }

    /* ================================================================
     * HELPERS
     * ================================================================ */

    /**
     * Map consumer mode → classifier mode (default fallback).
     * If mode_classifier_result is passed in params, it takes precedence.
     */
    private static function map_consumer_to_classifier_mode( string $consumer ): string {
        $map = [
            'chat'      => 'knowledge',   // default, overridden by classifier
            'notebook'  => 'studio',
            'planner'   => 'planning',
            'execution' => 'execution',
            'studio'    => 'studio',
        ];
        return $map[ $consumer ] ?? 'knowledge';
    }
}
