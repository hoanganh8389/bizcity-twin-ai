<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Objective Understanding — Structured Intent Analysis Layer
 *
 * Separated from Execution Planning: this class ONLY understands WHAT
 * the user wants, not HOW to execute it. Returns a structured analysis
 * with intents, dependencies, output contracts, and confidence scoring.
 *
 * Phase 1 Addendum — Issue 0: Prompt Skeleton Foundation
 *
 * @package BizCity_Intent
 * @since   4.2.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Objective_Understanding {

    /** @var self|null */
    private static $instance = null;

    /** Minimum confidence to proceed without clarification. */
    const CONFIDENCE_THRESHOLD = 0.65;

    /** Orchestration tools that must NEVER appear as pipeline action steps. */
    private static $orchestration_tools = [ 'build_workflow', 'publish_workflow', 'list_workflows' ];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Analyze a user message into structured objectives.
     *
     * v4.9.1: LLM-first decomposition. Regex splitting is kept as fallback
     * only when LLM is unavailable. This allows the system to scale to 1000+
     * tools/plugins without needing regex patterns for every variation.
     *
     * @param string $message  Raw user message.
     * @param array  $intent   Router intent output { goal, intent, entities, confidence }.
     * @param array  $context  { user_id, session_id, conversation_slots, channel }.
     * @return array {
     *   @type array  $intents          Ordered list of { text, tool_hint, confidence, tier, input_fields, output_fields }.
     *   @type array  $dependencies     List of { from => int, to => int, fields => string[] } — index-based.
     *   @type array  $output_contract  Merged output fields the full pipeline will produce.
     *   @type float  $confidence       Overall confidence (min across intents).
     *   @type bool   $needs_clarify    True when confidence < threshold.
     *   @type string $clarify_reason   Human-readable reason for clarification.
     *   @type bool   $is_multi         Whether multiple intents detected.
     *   @type array  $segments         Raw text segments.
     * }
     */
    public function analyze( string $message, array $intent, array $context = [] ): array {
        $empty = [
            'intents'         => [],
            'dependencies'    => [],
            'output_contract' => [],
            'confidence'      => 0.0,
            'needs_clarify'   => true,
            'clarify_reason'  => 'Empty message.',
            'is_multi'        => false,
            'segments'        => [],
        ];

        $normalized = trim( preg_replace( '/\s+/u', ' ', $message ) );
        if ( $normalized === '' ) {
            return $empty;
        }

        // ── v4.9.1: LLM-first objective decomposition ──
        // Ask LLM to split message into objectives AND map each to a goal.
        // v4.9.2: Full context layers — memory, history, episodic, rolling.
        $llm_intents = $this->decompose_with_llm( $normalized, $intent, $context );

        if ( $llm_intents !== null ) {
            $intents = $llm_intents;
        } else {
            // Fallback: regex-based splitting (LLM unavailable)
            $segments = $this->split_segments( $normalized );
            $intents  = $this->resolve_intents( $segments, $intent );
        }

        if ( empty( $intents ) ) {
            return array_merge( $empty, [
                'segments'       => [ $normalized ],
                'clarify_reason' => __( 'Không nhận diện được mục tiêu cụ thể từ tin nhắn.', 'bizcity-twin-ai' ),
            ] );
        }

        // ── Step 3: Infer dependencies between intents ──
        $dependencies = $this->infer_dependencies( $intents );

        // ── Step 4: Build output contract ──
        $output_contract = $this->build_output_contract( $intents );

        // ── Step 5: Calculate overall confidence ──
        $min_confidence = 1.0;
        foreach ( $intents as $obj ) {
            if ( $obj['confidence'] < $min_confidence ) {
                $min_confidence = $obj['confidence'];
            }
        }

        // ── Step 6: Determine if clarification is needed ──
        $needs_clarify  = false;
        $clarify_reason = '';

        if ( $min_confidence < self::CONFIDENCE_THRESHOLD ) {
            $needs_clarify  = true;
            $low_items      = [];
            foreach ( $intents as $i => $obj ) {
                if ( $obj['confidence'] < self::CONFIDENCE_THRESHOLD ) {
                    $low_items[] = sprintf( '"%s" (%.0f%%)', $obj['text'], $obj['confidence'] * 100 );
                }
            }
            $clarify_reason = __( 'Độ tin cậy thấp cho: ', 'bizcity-twin-ai' ) . implode( ', ', $low_items )
                            . __( '. Bạn có thể mô tả rõ hơn không?', 'bizcity-twin-ai' );
        }

        // Check for intents with no resolved tool
        $unresolved = [];
        foreach ( $intents as $i => $obj ) {
            if ( empty( $obj['tool_hint'] ) ) {
                $unresolved[] = '"' . $obj['text'] . '"';
            }
        }
        if ( ! empty( $unresolved ) ) {
            $needs_clarify  = true;
            $clarify_reason = ( $clarify_reason ? $clarify_reason . ' ' : '' )
                            . __( 'Không xác định được tool cho: ', 'bizcity-twin-ai' ) . implode( ', ', $unresolved );
        }

        return [
            'intents'         => $intents,
            'dependencies'    => $dependencies,
            'output_contract' => $output_contract,
            'confidence'      => round( $min_confidence, 3 ),
            'needs_clarify'   => $needs_clarify,
            'clarify_reason'  => $clarify_reason,
            'is_multi'        => count( $intents ) > 1,
            'segments'        => array_map( function( $i ) { return $i['text']; }, $intents ),
        ];
    }

    /**
     * LLM-based objective decomposition (v4.9.1, enhanced v4.9.2).
     *
     * Sends user message + compact goal catalog + FULL context layers to LLM.
     * The LLM decomposes objectives, maps goals, pre-fills known slots from
     * memory/history, and identifies what's still missing.
     *
     * Context layers injected:
     *   - User Memory (identity, preferences, habits)
     *   - Chat History (recent conversation flow)
     *   - Rolling Summary (session continuity)
     *   - Episodic Memory (behavioral patterns, tool usage history)
     *   - Rolling Memory (active/recent goals across conversations)
     *
     * @param string $message    Normalized user message.
     * @param array  $intent     Router intent output (provides primary goal hint).
     * @param array  $context    Plan context { user_id, session_id, channel, rolling_summary, goal, ... }.
     * @return array[]|null  Array of intent structs (with prefilled), or null if LLM unavailable.
     */
    private function decompose_with_llm( string $message, array $intent, array $context = [] ): ?array {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return null;
        }

        $router = class_exists( 'BizCity_Intent_Router' ) ? BizCity_Intent_Router::instance() : null;
        if ( ! $router ) {
            return null;
        }

        // ── Goal catalog ──
        $goal_list = $router->build_goal_list_compact_for_preview( 2000 );
        if ( empty( trim( $goal_list ) ) ) {
            return null;
        }

        // ── Build rich context bundle from all available sources ──
        $context_bundle = $this->build_understanding_context( $context, $intent );

        // ── Primary goal hint — WEAKENED v4.6.4 ──
        // Router returns a single goal. This is ADVISORY ONLY — OU must
        // independently analyze the FULL message for ALL objectives.
        // Strong hints were anchoring LLM toward single-goal, suppressing
        // multi-goal detection for messages like "viết bài rồi đăng facebook".
        $primary_hint = '';
        if ( ! empty( $intent['goal'] ) ) {
            $primary_hint = "\nTham khảo: Router gợi ý \"{$intent['goal']}\" — nhưng có thể KHÔNG ĐẦY ĐỦ."
                          . " Hãy phân tích ĐỘC LẬP toàn bộ tin nhắn để tìm TẤT CẢ mục tiêu.";
        }

        $system = <<<PROMPT
Phân tích tin nhắn thành DANH SÁCH các mục tiêu (objectives) và PRE-FILL thông tin đã biết.

{$goal_list}

{$context_bundle}

QUY TẮC PHÂN TÍCH:
1. Mỗi mục tiêu = 1 hành động riêng biệt, map vào 1 goal cụ thể.
2. Nếu tin nhắn CHỈ có 1 mục tiêu → trả mảng 1 phần tử.
3. Giữ đúng THỨ TỰ thực hiện (trước → trước).
4. "text" = phần nguyên văn tương ứng trong tin nhắn.
5. confidence ≥ 0.8 khi chắc chắn goal khớp.
6. Nếu 1 phần không khớp goal nào → KHÔNG đưa vào.
7. "để + hành động" = 2 mục tiêu riêng (VD: "tạo ảnh để đăng facebook" → 2 goals).
8. Dấu hiệu NHIỀU mục tiêu: "sau đó", "rồi", "xong", "tiếp theo", "và" + hành động khác = TÁCH thành 2+ goals riêng biệt.
   VD: "đăng bài lên web, sau đó đăng lên facebook" → [{goal:"write_article"}, {goal:"post_facebook"}]
   VD: "viết bài rồi chia sẻ facebook" → [{goal:"write_article"}, {goal:"post_facebook"}]

QUY TẮC PRE-FILL:
9. Dựa vào USER CONTEXT (memory, history, habits) để điền sẵn slot values đã biết.
10. "prefilled" chỉ chứa field có GIÁ TRỊ CHẮC CHẮN từ context — KHÔNG bịa.
11. "missing" liệt kê fields CẦN THIẾT mà user chưa cung cấp VÀ context không có.

JSON duy nhất, KHÔNG giải thích:
[{"text":"...","goal":"goal_id","confidence":0.0,"prefilled":{"field":"value"},"missing":["field1","field2"]}, ...]
PROMPT;

        $user_prompt = "Tin nhắn: \"{$message}\"{$primary_hint}";

        $t_start = microtime( true );

        $result = bizcity_openrouter_chat(
            [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user_prompt ],
            ],
            [
                'model'       => 'openai/gpt-4.1-mini',
                'purpose'     => 'objective_understanding',
                'temperature' => 0.05,
                'max_tokens'  => 500,
                'no_fallback' => false,
            ]
        );

        $t_ms = round( ( microtime( true ) - $t_start ) * 1000, 1 );

        if ( empty( $result['success'] ) || empty( $result['message'] ) ) {
            return null;
        }

        // Parse JSON array from LLM response.
        $raw = trim( $result['message'] );
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
        $raw = preg_replace( '/\s*```$/', '', $raw );

        if ( ( $start = strpos( $raw, '[' ) ) !== false ) {
            $end = strrpos( $raw, ']' );
            if ( $end !== false ) {
                $raw = substr( $raw, $start, $end - $start + 1 );
            }
        }

        $parsed = json_decode( $raw, true );
        if ( ! is_array( $parsed ) || empty( $parsed ) ) {
            return null;
        }

        // Convert LLM output → standard intent structs.
        $tools   = class_exists( 'BizCity_Intent_Tools' ) ? BizCity_Intent_Tools::instance() : null;
        $planner = class_exists( 'BizCity_Intent_Planner' ) ? BizCity_Intent_Planner::instance() : null;
        $intents = [];
        $seen    = [];

        foreach ( $parsed as $obj ) {
            $goal       = $obj['goal'] ?? '';
            $text       = $obj['text'] ?? '';
            $confidence = (float) ( $obj['confidence'] ?? 0.7 );

            if ( empty( $goal ) || empty( $text ) ) {
                continue;
            }

            // Skip orchestration tools.
            if ( in_array( $goal, self::$orchestration_tools, true ) ) {
                continue;
            }

            // Resolve tool from goal.
            $tool_name = $goal;
            if ( $planner ) {
                $plan_def = $planner->get_plan( $goal );
                if ( ! empty( $plan_def['tool'] ) ) {
                    $tool_name = $plan_def['tool'];
                }
            }

            // Deduplicate by tool.
            if ( isset( $seen[ $tool_name ] ) ) {
                continue;
            }
            $seen[ $tool_name ] = true;

            // Fetch schema.
            $schema        = $tools ? $tools->get_schema( $tool_name ) : [];
            $input_fields  = $schema['input_fields'] ?? [];
            $output_fields = $schema['output_fields'] ?? [];
            $tier          = BizCity_Core_Planner::auto_assign_tier( $tool_name );

            $intents[] = [
                'text'          => $text,
                'tool_hint'     => $tool_name,
                'confidence'    => $confidence,
                'tier'          => $tier,
                'input_fields'  => $input_fields,
                'output_fields' => $output_fields,
                'prefilled'     => is_array( $obj['prefilled'] ?? null ) ? $obj['prefilled'] : [],
                'missing'       => is_array( $obj['missing'] ?? null ) ? $obj['missing'] : [],
            ];
        }

        if ( empty( $intents ) ) {
            return null; // Fallback to regex.
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $prefill_count = 0;
            foreach ( $intents as $i ) {
                $prefill_count += count( $i['prefilled'] );
            }
            error_log( '[objective-understanding] LLM decompose: '
                . count( $intents ) . ' objectives, '
                . $prefill_count . ' prefilled slots, '
                . round( $t_ms ) . 'ms, model=' . ( $result['model'] ?? '?' ) );
        }

        return $intents;
    }

    /**
     * Build compact context bundle from all available memory/history sources.
     *
     * Assembles context from User Memory, Chat History, Rolling Memory,
     * Episodic Memory, and Rolling Summary into a single text block
     * for the LLM to use in objective decomposition.
     *
     * @since 4.9.2
     * @param array $context  Plan context from Intent Engine.
     * @param array $intent   Router intent output.
     * @return string  Context block for system prompt, or ''.
     */
    private function build_understanding_context( array $context, array $intent ): string {
        $parts   = [];
        $user_id    = (int) ( $context['user_id'] ?? 0 );
        $session_id = (string) ( $context['session_id'] ?? '' );
        $channel    = (string) ( $context['channel'] ?? '' );
        $conv_id    = (string) ( $context['conversation_id'] ?? '' );
        $goal       = (string) ( $context['goal'] ?? $intent['goal'] ?? '' );

        // ── 1. User Memory (identity, preferences) ──
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $mem = BizCity_User_Memory::build_compact_memory( $user_id, $session_id );
            if ( $mem ) {
                $parts[] = trim( $mem );
            }
        }

        // ── 2. Chat History (recent conversation flow) ──
        if ( $session_id && class_exists( 'BizCity_Mode_Classifier' ) ) {
            $hist = BizCity_Mode_Classifier::get_recent_chat_history(
                $session_id,
                strtoupper( $channel ?: 'ADMINCHAT' ),
                4
            );
            if ( $hist ) {
                $parts[] = trim( $hist );
            }
        }

        // ── 3. Rolling Summary (session-level continuity) ──
        $rolling_summary = trim( $context['rolling_summary'] ?? '' );
        if ( $rolling_summary ) {
            $summary = mb_substr( $rolling_summary, 0, 300 );
            $parts[] = "ROLLING SUMMARY: {$summary}";
        }

        // ── 4. Episodic Memory (behavioral patterns, tool habits) ──
        if ( $user_id && class_exists( 'BizCity_Episodic_Memory' ) ) {
            $episodic = BizCity_Episodic_Memory::instance()->build_context( $user_id, $goal );
            if ( $episodic ) {
                // Compact: strip headers, keep only first 400 chars.
                $episodic = preg_replace( '/^.*?(?=\d|\-|\•)/su', '', trim( $episodic ) );
                $episodic = mb_substr( $episodic, 0, 400 );
                if ( $episodic ) {
                    $parts[] = "EPISODIC MEMORY: {$episodic}";
                }
            }
        }

        // ── 5. Rolling Memory (active/recent goals across conversations) ──
        if ( $user_id && class_exists( 'BizCity_Rolling_Memory' ) ) {
            $rolling_mem = BizCity_Rolling_Memory::instance()->build_context(
                $user_id, $session_id, $conv_id
            );
            if ( $rolling_mem ) {
                $rolling_mem = mb_substr( trim( $rolling_mem ), 0, 400 );
                $parts[] = $rolling_mem;
            }
        }

        if ( empty( $parts ) ) {
            return '';
        }

        return "USER CONTEXT:\n" . implode( "\n", $parts );
    }

    /**
     * Split message by Vietnamese/English sequential connectors.
     *
     * @param string $normalized
     * @return string[]
     */
    private function split_segments( string $normalized ): array {
        $segments = preg_split(
            '/\s*(?:,\s*r[ồo]i|r[ồo]i|sau\s+đ[óo]|ti[ếe]p\s+theo|v[àa]|đ[ồo]ng\s+th[ờo]i|and\s+then|then|and)\s*/ui',
            $normalized,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        return array_values( array_map( 'trim', array_filter( $segments, function ( $s ) {
            return trim( $s ) !== '';
        } ) ) );
    }

    /**
     * Resolve each segment into a structured intent with schema info.
     *
     * @param string[] $segments
     * @param array    $router_intent
     * @return array[] Each { text, tool_hint, confidence, tier, input_fields, output_fields }
     */
    private function resolve_intents( array $segments, array $router_intent ): array {
        $router        = class_exists( 'BizCity_Intent_Router' ) ? BizCity_Intent_Router::instance() : null;
        $goal_patterns = $router ? $router->get_goal_patterns() : [];
        $primary_goal  = $router_intent['goal'] ?? '';
        $planner       = class_exists( 'BizCity_Intent_Planner' ) ? BizCity_Intent_Planner::instance() : null;
        $tools         = class_exists( 'BizCity_Intent_Tools' ) ? BizCity_Intent_Tools::instance() : null;

        $intents = [];
        $seen    = [];

        foreach ( $segments as $idx => $seg ) {
            $goal_hint = '';
            $confidence = 0.0;

            // First segment inherits primary goal from Router —
            // UNLESS it's an orchestration tool (build_workflow, etc.) which must
            // never appear as a pipeline action step.
            //
            // v4.9.0 FIX: Validate that segment 0 text actually matches the primary
            // goal's pattern. When the Router matched a goal from the SECOND part of
            // a multi-objective message (e.g. "đăng bài lên web rồi đăng facebook"
            // → Router matched post_facebook from "đăng facebook"), segment 0 would
            // wrongly inherit that goal, causing dedup to collapse both segments into
            // one tool and is_multi=false. Now segment 0 falls through to regex
            // matching when its text doesn't fit the primary goal pattern.
            if ( $idx === 0 && $primary_goal
                 && ! in_array( $primary_goal, self::$orchestration_tools, true )
            ) {
                $seg_matches_primary = false;
                if ( count( $segments ) > 1 && ! empty( $goal_patterns ) ) {
                    foreach ( $goal_patterns as $pat => $pcfg ) {
                        if ( ( $pcfg['goal'] ?? '' ) === $primary_goal
                             && is_string( $pat )
                             && @preg_match( $pat, $seg ) === 1
                        ) {
                            $seg_matches_primary = true;
                            break;
                        }
                    }
                } else {
                    // Single segment — always inherit (no dedup risk).
                    $seg_matches_primary = true;
                }

                if ( $seg_matches_primary ) {
                    $goal_hint  = $primary_goal;
                    $confidence = (float) ( $router_intent['confidence'] ?? 0.9 );
                }
                // else: fall through to regex pattern matching below
            }

            // Match router goal patterns for remaining segments.
            if ( ! $goal_hint && ! empty( $goal_patterns ) ) {
                foreach ( $goal_patterns as $pattern => $cfg ) {
                    if ( ! is_string( $pattern ) || @preg_match( $pattern, $seg ) !== 1 ) {
                        continue;
                    }
                    $goal_hint  = $cfg['goal'] ?? '';
                    $confidence = 0.8;
                    if ( $goal_hint ) {
                        break;
                    }
                }
            }

            // Fallback: keyword matching against tool descriptions.
            if ( ! $goal_hint && $tools ) {
                $lower = mb_strtolower( $seg, 'UTF-8' );
                foreach ( $tools->list_all() as $t_name => $t_schema ) {
                    // Skip orchestration tools — they must not appear as pipeline action steps.
                    if ( in_array( $t_name, self::$orchestration_tools, true ) ) {
                        continue;
                    }
                    $desc = mb_strtolower( $t_schema['description'] ?? '', 'UTF-8' );
                    $words = array_filter( explode( ' ', $lower ), function( $w ) {
                        return mb_strlen( $w, 'UTF-8' ) >= 3;
                    } );
                    foreach ( $words as $word ) {
                        if ( strpos( $desc, $word ) !== false ) {
                            $goal_hint  = $t_name;
                            $confidence = 0.5;
                            break 2;
                        }
                    }
                }
            }

            if ( ! $goal_hint ) {
                // Unresolved segment — include with zero confidence.
                $intents[] = [
                    'text'          => $seg,
                    'tool_hint'     => '',
                    'confidence'    => 0.0,
                    'tier'          => 4,
                    'input_fields'  => [],
                    'output_fields' => [],
                ];
                continue;
            }

            // Resolve tool from goal.
            $tool_name = $goal_hint;
            if ( $planner ) {
                $plan_def = $planner->get_plan( $goal_hint );
                if ( ! empty( $plan_def['tool'] ) ) {
                    $tool_name = $plan_def['tool'];
                }
            }

            // Final guard: reject orchestration tools regardless of source.
            if ( in_array( $tool_name, self::$orchestration_tools, true ) ) {
                $intents[] = [
                    'text'          => $seg,
                    'tool_hint'     => '',
                    'confidence'    => 0.0,
                    'tier'          => 4,
                    'input_fields'  => [],
                    'output_fields' => [],
                ];
                continue;
            }

            // Deduplicate.
            if ( isset( $seen[ $tool_name ] ) ) {
                continue;
            }
            $seen[ $tool_name ] = true;

            // Fetch schema.
            $schema        = $tools ? $tools->get_schema( $tool_name ) : [];
            $input_fields  = $schema['input_fields'] ?? [];
            $output_fields = $schema['output_fields'] ?? [];
            $tier          = BizCity_Core_Planner::auto_assign_tier( $tool_name );

            $intents[] = [
                'text'          => $seg,
                'tool_hint'     => $tool_name,
                'confidence'    => $confidence,
                'tier'          => $tier,
                'input_fields'  => $input_fields,
                'output_fields' => $output_fields,
            ];
        }

        return $intents;
    }

    /**
     * Infer dependencies between intents based on I/O field overlap.
     *
     * Rule: if intent B requires an input that intent A produces as output,
     * and A comes before B in tier order, then B depends on A.
     *
     * Example: "viết bài rồi đăng facebook"
     *   → write_article outputs { id, content, url, image_url }
     *   → post_facebook requires { content }
     *   → dependency: { from: 0, to: 1, fields: ['content'] }
     *
     * @param array[] $intents
     * @return array[] Each { from, to, fields }
     */
    private function infer_dependencies( array $intents ): array {
        $dependencies = [];

        // Alias map for field name matching.
        $aliases = [
            'content'   => [ 'message', 'body', 'text' ],
            'title'     => [ 'name', 'subject' ],
            'image_url' => [ 'thumbnail', 'featured_image', 'image' ],
            'url'       => [ 'link', 'permalink', 'href' ],
            'id'        => [ 'post_id', 'resource_id', 'object_id' ],
            'excerpt'   => [ 'summary', 'description' ],
        ];

        for ( $b = 1; $b < count( $intents ); $b++ ) {
            $b_inputs = $intents[ $b ]['input_fields'];
            if ( empty( $b_inputs ) ) {
                continue;
            }

            for ( $a = 0; $a < $b; $a++ ) {
                $a_outputs = $intents[ $a ]['output_fields'];
                if ( empty( $a_outputs ) ) {
                    continue;
                }

                $matched_fields = [];
                foreach ( $b_inputs as $b_field => $b_cfg ) {
                    // Exact name match.
                    if ( isset( $a_outputs[ $b_field ] ) ) {
                        $matched_fields[] = $b_field;
                        continue;
                    }
                    // Alias match.
                    foreach ( $aliases as $canonical => $alias_list ) {
                        $all_names = array_merge( [ $canonical ], $alias_list );
                        if ( in_array( $b_field, $all_names, true ) ) {
                            foreach ( $all_names as $alias ) {
                                if ( isset( $a_outputs[ $alias ] ) ) {
                                    $matched_fields[] = $b_field;
                                    break 2;
                                }
                            }
                        }
                    }
                }

                if ( ! empty( $matched_fields ) ) {
                    $dependencies[] = [
                        'from'   => $a,
                        'to'     => $b,
                        'fields' => $matched_fields,
                    ];
                }
            }
        }

        return $dependencies;
    }

    /**
     * Build the merged output contract for the entire pipeline.
     *
     * @param array[] $intents
     * @return array  Merged output fields { field_name => { type, source_tool } }
     */
    private function build_output_contract( array $intents ): array {
        $contract = [];
        foreach ( $intents as $idx => $obj ) {
            foreach ( $obj['output_fields'] as $field => $cfg ) {
                // Last producer wins.
                $contract[ $field ] = [
                    'type'        => $cfg['type'] ?? 'string',
                    'description' => $cfg['description'] ?? '',
                    'source_tool' => $obj['tool_hint'],
                    'step_index'  => $idx,
                ];
            }
        }
        return $contract;
    }
}
