<?php
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

        // ── Step 1: Segment splitting ──
        $segments = $this->split_segments( $normalized );

        // ── Step 2: Resolve each segment into a structured intent ──
        $intents = $this->resolve_intents( $segments, $intent );

        if ( empty( $intents ) ) {
            return array_merge( $empty, [
                'segments'       => $segments,
                'clarify_reason' => 'Không nhận diện được mục tiêu cụ thể từ tin nhắn.',
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
            $clarify_reason = 'Độ tin cậy thấp cho: ' . implode( ', ', $low_items )
                            . '. Bạn có thể mô tả rõ hơn không?';
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
                            . 'Không xác định được tool cho: ' . implode( ', ', $unresolved );
        }

        return [
            'intents'         => $intents,
            'dependencies'    => $dependencies,
            'output_contract' => $output_contract,
            'confidence'      => round( $min_confidence, 3 ),
            'needs_clarify'   => $needs_clarify,
            'clarify_reason'  => $clarify_reason,
            'is_multi'        => count( $intents ) > 1,
            'segments'        => $segments,
        ];
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
