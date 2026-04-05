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
 * BizCity Core Planner — Multi-Goal Orchestrator + Package Tool Matcher
 *
 * Entry point for Phase 1 multi-goal execution.
 * Detects single vs multi-goal, matches package tools,
 * builds dependency graph, and orchestrates step-by-step execution.
 *
 * Phase 1 — YC4: Unified Execution Path
 *
 * Integration point: hooked into `bizcity_intent_execution_detected`
 * by the Intent Engine when Prompt Parser returns multiple objectives.
 *
 * @package BizCity_Intent
 * @since   4.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Core_Planner {

    /** @var self|null */
    private static $instance = null;

    /** Orchestration tools that must NEVER appear as pipeline action steps. */
    private static $orchestration_tools = [ 'build_workflow', 'publish_workflow', 'list_workflows' ];

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Detect if prompt contains multiple objectives.
     *
     * @param array $objectives  Output from Prompt Parser extract_objectives().
     * @return bool
     */
    public function is_multi_goal( array $objectives ) {
        return count( $objectives ) > 1;
    }

    /**
     * Build a pipeline plan from multiple objectives.
     *
     * Steps:
     *   1. Check for package tool match (TIER P)
     *   2. Resolve each objective → tool
     *   3. Assign trust_tier to each step
     *   4. Build dependency graph
     *   5. Sort by tier + dependency
     *   6. Build input_map references
     *
     * @param array $objectives  Array of { text, tool_hint, confidence }
     * @param array $context     { user_id, session_id, conversation_slots }
     * @return array Pipeline plan {
     *   @type string $pipeline_id
     *   @type string $mode          'package' | 'multi_step' | 'single'
     *   @type array  $package_tool  Package tool schema (if mode=package)
     *   @type array  $steps         Ordered steps { tool, slots, input_map, depends_on, trust_tier }
     *   @type int    $step_count
     * }
     */
    public function build_plan( array $objectives, array $context = [] ) {
        $pipeline_id = 'pipe_' . wp_generate_password( 8, false );
        $tools       = BizCity_Intent_Tools::instance();
        $tool_index  = class_exists( 'BizCity_Intent_Tool_Index' ) ? BizCity_Intent_Tool_Index::instance() : null;

        // Single objective → simple pass-through
        if ( count( $objectives ) <= 1 ) {
            $obj  = $objectives[0] ?? [];
            $tool = $obj['tool_hint'] ?? '';
            // Filter orchestration tools from single-objective path.
            if ( in_array( $tool, self::$orchestration_tools, true ) ) {
                $tool = '';
            }
            return [
                'pipeline_id'  => $pipeline_id,
                'mode'         => 'single',
                'package_tool' => null,
                'steps'        => $tool ? [ $this->build_step( 0, $tool, $context, $tool_index ) ] : [],
                'step_count'   => $tool ? 1 : 0,
            ];
        }

        // Resolve tool names from objectives (filter orchestration tools)
        $tool_names = [];
        foreach ( $objectives as $obj ) {
            $tool = $this->resolve_tool_for_objective( $obj, $tools );
            if ( $tool && ! in_array( $tool, self::$orchestration_tools, true ) ) {
                $tool_names[] = $tool;
            }
        }

        // Step 1: Check package tool match
        $package = $this->find_package_tool( $tool_names, $tools );
        if ( $package ) {
            return [
                'pipeline_id'  => $pipeline_id,
                'mode'         => 'package',
                'package_tool' => $package,
                'steps'        => [], // Package tool handles its own sub-steps
                'step_count'   => count( $package['sub_tools'] ?? [] ),
            ];
        }

        // Step 2-6: Build multi-step plan
        $steps = [];
        foreach ( $tool_names as $index => $tool_name ) {
            $steps[] = $this->build_step( $index, $tool_name, $context, $tool_index );
        }

        // Sort by trust_tier ASC, then resolve dependencies
        usort( $steps, function ( $a, $b ) {
            return $a['trust_tier'] - $b['trust_tier'];
        } );

        // Re-index after sort
        foreach ( $steps as $i => &$step ) {
            $step['step_index'] = $i;
        }
        unset( $step );

        // Build dependency graph + input_map
        $steps = $this->resolve_dependencies( $steps );

        return [
            'pipeline_id'  => $pipeline_id,
            'mode'         => 'multi_step',
            'package_tool' => null,
            'steps'        => $steps,
            'step_count'   => count( $steps ),
        ];
    }

    /**
     * Resolve which tool handles an objective.
     *
     * @param array                $objective  { text, tool_hint }
     * @param BizCity_Intent_Tools $tools
     * @return string|null Tool name.
     */
    private function resolve_tool_for_objective( $objective, $tools ) {
        // Direct tool_hint from Prompt Parser
        $hint = $objective['tool_hint'] ?? '';
        if ( $hint && $tools->has( $hint ) ) {
            return $hint;
        }

        // Keyword search fallback against registered tools
        $text      = mb_strtolower( $objective['text'] ?? '', 'UTF-8' );
        $all_tools = $tools->list_all();
        foreach ( $all_tools as $name => $schema ) {
            // Skip orchestration tools.
            if ( in_array( $name, self::$orchestration_tools, true ) ) {
                continue;
            }
            $desc = mb_strtolower( $schema['description'] ?? '', 'UTF-8' );
            // Simple keyword overlap
            $words = array_filter( explode( ' ', $text ) );
            foreach ( $words as $word ) {
                if ( mb_strlen( $word, 'UTF-8' ) >= 3 && strpos( $desc, $word ) !== false ) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Build a single step descriptor.
     *
     * @param int                        $index
     * @param string                     $tool_name
     * @param array                      $context
     * @param BizCity_Intent_Tool_Index|null $tool_index
     * @return array
     */
    private function build_step( $index, $tool_name, $context, $tool_index ) {
        $trust_tier = 4; // Default utility

        if ( $tool_index ) {
            $db_tool = $tool_index->get_tool_by_key( $tool_name );
            if ( ! $db_tool && method_exists( $tool_index, 'get_tool_by_name' ) ) {
                $db_tool = $tool_index->get_tool_by_name( $tool_name );
            }
            if ( $db_tool && isset( $db_tool->trust_tier ) ) {
                $trust_tier = (int) $db_tool->trust_tier;
            } else {
                $trust_tier = self::auto_assign_tier( $tool_name );
            }
        } else {
            $trust_tier = self::auto_assign_tier( $tool_name );
        }

        $schema = BizCity_Intent_Tools::instance()->get_schema( $tool_name );

        return [
            'step_index'  => $index,
            'tool'        => $tool_name,
            'trust_tier'  => $trust_tier,
            'depends_on'  => [],
            'input_map'   => [],
            'slots'       => $context['conversation_slots'] ?? [],
            'output_fields' => $schema['output_fields'] ?? [],
            'input_fields'  => $schema['input_fields'] ?? [],
        ];
    }

    /**
     * Auto-assign trust tier based on tool name keywords.
     *
     * @param string $tool_name
     * @return int
     */
    public static function auto_assign_tier( $tool_name ) {
        $name = strtolower( $tool_name );

        // TIER 0: Core
        if ( in_array( $name, [ 'build_workflow', 'knowledge_train', 'knowledge_search', 'knowledge_manage' ], true ) ) {
            return 0;
        }

        // TIER 1: Production
        $production = [ 'write', 'create', 'generate', 'build' ];
        foreach ( $production as $kw ) {
            if ( strpos( $name, $kw ) !== false ) return 1;
        }

        // TIER 2: Distribution
        $distribution = [ 'post', 'send', 'publish', 'share' ];
        foreach ( $distribution as $kw ) {
            if ( strpos( $name, $kw ) !== false ) return 2;
        }

        // TIER 3: Analytics
        $analytics = [ 'list', 'stats', 'report', 'find', 'search' ];
        foreach ( $analytics as $kw ) {
            if ( strpos( $name, $kw ) !== false ) return 3;
        }

        return 4;
    }

    /**
     * Resolve step dependencies and build input_map references.
     *
     * For each step, check if its required input fields can be supplied
     * by a previous step's output fields using the IO Mapper rules.
     *
     * @param array $steps  Already sorted by trust_tier.
     * @return array Steps with depends_on and input_map populated.
     */
    private function resolve_dependencies( array $steps ) {
        $step_output_schemas = []; // step_index => output_fields

        foreach ( $steps as $i => &$step ) {
            $step_output_schemas[ $i ] = $step['output_fields'];

            if ( $i === 0 ) {
                continue; // First step has no dependencies
            }

            $required_inputs = [];
            foreach ( $step['input_fields'] as $field => $config ) {
                if ( ! empty( $config['required'] ) ) {
                    $required_inputs[ $field ] = $config;
                }
            }

            if ( empty( $required_inputs ) ) {
                continue;
            }

            // Check previous steps for output that satisfies input
            for ( $j = $i - 1; $j >= 0; $j-- ) {
                $prev_output_schema = $step_output_schemas[ $j ];
                if ( empty( $prev_output_schema ) ) {
                    continue;
                }

                $mapping = BizCity_Tool_IO_Mapper::map(
                    [], // No actual values yet — just schema matching
                    $prev_output_schema,
                    $required_inputs
                );

                // For each mapped field, create input_map reference
                foreach ( $mapping['mapping_log'] as $target_field => $log ) {
                    $source_field = $log['source'];
                    $step['input_map'][ $target_field ] = '$step[' . $j . '].data.' . $source_field;
                    if ( ! in_array( $j, $step['depends_on'], true ) ) {
                        $step['depends_on'][] = $j;
                    }
                    // Remove from required_inputs so we don't re-check
                    unset( $required_inputs[ $target_field ] );
                }
            }
        }
        unset( $step );

        return $steps;
    }

    /**
     * Find a package tool that covers all requested tool names.
     *
     * @param array                $tool_names  Atomic tools needed.
     * @param BizCity_Intent_Tools $tools
     * @return array|null Package tool schema or null.
     */
    private function find_package_tool( array $tool_names, $tools ) {
        $all_tools = $tools->list_all();
        $sorted_needed = $tool_names;
        sort( $sorted_needed );

        foreach ( $all_tools as $name => $schema ) {
            if ( ( $schema['type'] ?? 'atomic' ) !== 'package' ) {
                continue;
            }
            $sub_tools = $schema['sub_tools'] ?? [];
            $sorted_sub = $sub_tools;
            sort( $sorted_sub );

            // Exact match or subset match
            if ( $sorted_sub === $sorted_needed ) {
                return array_merge( $schema, [ 'name' => $name ] );
            }

            // Check if all needed tools are contained in the package
            $diff = array_diff( $sorted_needed, $sorted_sub );
            if ( empty( $diff ) ) {
                return array_merge( $schema, [ 'name' => $name ] );
            }
        }

        return null;
    }

    /**
     * Execute a pipeline plan step by step.
     *
     * This is the main execution loop for multi-step plans.
     * Each step: resolve input_map → validate → preconfirm → execute → save evidence → next.
     *
     * @param array $plan     Pipeline plan from build_plan().
     * @param array $context  { user_id, session_id, channel }
     * @return array {
     *   @type bool   $success
     *   @type string $pipeline_id
     *   @type array  $results      Per-step results.
     *   @type array  $evidence_ids
     *   @type string $summary
     * }
     */
    public function execute_plan( array $plan, array $context = [] ) {
        // ALL plans must go through the builder for user confirmation.
        // This generates a scenario, saves it as a draft task, and returns
        // the builder URL — regardless of 1 step or multi-step.
        return BizCity_Scenario_Generator::generate( $plan, [
            'user_id'    => $context['user_id'] ?? get_current_user_id(),
            'session_id' => $context['session_id'] ?? '',
            'message'    => $context['message'] ?? '',
            'channel'    => $context['channel'] ?? 'webchat',
        ] );
    }

    /**
     * Present pipeline plan to user for confirmation.
     *
     * @param array $plan Pipeline plan from build_plan().
     * @return string Human-readable plan summary.
     */
    public function format_plan_summary( array $plan, string $plan_link = '' ) {
        if ( $plan['mode'] === 'package' ) {
            $pkg = $plan['package_tool'];
            $name = $pkg['name'] ?? 'package';
            $desc = $pkg['description'] ?? '';
            $subs = implode( ' → ', $pkg['sub_tools'] ?? [] );
            $summary = "📦 Trọn gói: **{$desc}**\nCác bước: {$subs}";
        } else {
            $lines = [ "📋 Kế hoạch thực hiện ({$plan['step_count']} bước):" ];
            $icons = [ 0 => '🎯', 1 => '📝', 2 => '📱', 3 => '📊', 4 => '🔧' ];

            // Use friendly labels from Scenario Generator when available.
            $labels = class_exists( 'BizCity_Scenario_Generator' )
                ? BizCity_Scenario_Generator::get_tool_labels()
                : [];

            foreach ( $plan['steps'] as $step ) {
                $icon  = $icons[ $step['trust_tier'] ] ?? '▶️';
                $tool  = $step['tool'];
                $label = ! empty( $labels[ $tool ] ) ? $labels[ $tool ] : $tool;
                $deps  = '';
                if ( ! empty( $step['depends_on'] ) ) {
                    $dep_names = [];
                    foreach ( $step['depends_on'] as $dep_idx ) {
                        $dep_tool = $plan['steps'][ $dep_idx ]['tool'] ?? "step {$dep_idx}";
                        $dep_names[] = ! empty( $labels[ $dep_tool ] ) ? $labels[ $dep_tool ] : $dep_tool;
                    }
                    $deps = ' (dùng kết quả từ: ' . implode( ', ', $dep_names ) . ')';
                }
                $lines[] = sprintf( '%d. %s %s%s', $step['step_index'] + 1, $icon, $label, $deps );
            }
            $summary = implode( "\n", $lines );
        }

        // Embed workflow builder placeholder (rendered as WorkflowEmbed by React frontend).
        // Use markdown link format — MediaPreview component detects builder URLs
        // and renders them as interactive iframe with View / Run buttons.
        if ( $plan_link ) {
            $task_id = 0;
            if ( preg_match( '/task_id=(\d+)/', $plan_link, $m ) ) {
                $task_id = (int) $m[1];
            }
            $clean_url = $plan_link;
            if ( strpos( $clean_url, 'bizcity_iframe=1' ) === false ) {
                $clean_url .= ( strpos( $clean_url, '?' ) !== false ? '&' : '?' ) . 'bizcity_iframe=1';
            }
            $summary .= "\n\n[👁 Xem & Chạy Workflow #{$task_id}]({$clean_url})";
        }

        return $summary;
    }
}
