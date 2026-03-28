<?php
/**
 * BizCity Scenario Generator — Pipeline Plan → Workspace Builder Task
 *
 * Converts a Core Planner pipeline plan into a workflow (nodes + edges)
 * and saves it as a draft task in bizcity_tasks. ALWAYS returns a
 * builder URL so the user can review the plan and confirm before execution.
 *
 * This acts as the HIL (Human-In-the-Loop) confirm gate — whether the plan
 * has 1 step or 10, the user always gets a visual builder link to approve.
 *
 * Phase 1 — YC5: Execute-once with mandatory plan review
 *
 * @package BizCity_Intent
 * @since   4.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scenario_Generator {

    /**
     * Main entry point — build scenario, save draft task, return confirm link.
     *
     * @param array  $plan            From BizCity_Core_Planner::build_plan().
     * @param array  $trigger_context { user_id, session_id, message, channel }
     * @param string $description     Human-readable title for the task.
     * @return array {
     *   @type bool   $success
     *   @type int    $task_id    Row ID in bizcity_tasks.
     *   @type string $plan_link  Builder URL for user to review/confirm.
     *   @type string $message    Human-readable message (Vietnamese).
     *   @type array  $scenario   The generated workflow JSON (nodes, edges, settings).
     * }
     */
    public static function generate( array $plan, array $trigger_context = [], string $description = '' ) {
        $scenario = self::from_pipeline_plan( $plan, $trigger_context );
        $title    = sanitize_text_field( $description ?: self::build_title( $plan ) );
        $task_id  = self::save_draft_task( $scenario, $title, $trigger_context );

        if ( ! $task_id ) {
            return [
                'success'  => false,
                'task_id'  => 0,
                'plan_link'=> '',
                'message'  => 'Không thể lưu kế hoạch. Vui lòng thử lại.',
                'scenario' => $scenario,
            ];
        }

        $plan_link = self::get_builder_url( $task_id );
        $step_count = count( $plan['steps'] ?? [] );

        return [
            'success'   => true,
            'task_id'   => $task_id,
            'plan_link' => $plan_link,
            'message'   => sprintf(
                "📋 Đã tạo kế hoạch %d bước: **%s**\n\n👉 [Xem & xác nhận kế hoạch](%s)\n\nBấm vào link để xem chi tiết và triển khai.",
                $step_count,
                $title,
                $plan_link
            ),
            'scenario'  => $scenario,
        ];
    }

    /**
     * Build the builder URL for a task.
     *
     * @param int  $task_id  Row ID in bizcity_tasks.
     * @param bool $iframe   Whether to add bizcity_iframe=1 param.
     * @return string Full admin URL.
     */
    public static function get_builder_url( int $task_id, bool $iframe = true ): string {
        $url = admin_url( 'admin.php' ) . '?page=bizcity-workspace&tab=builder&task_id=' . $task_id;
        if ( $iframe ) {
            $url .= '&bizcity_iframe=1';
        }
        return $url;
    }

    /**
     * Convert a pipeline plan into a workflow scenario (nodes + edges).
     *
     * @param array $plan            From BizCity_Core_Planner::build_plan().
     * @param array $trigger_context { user_id, session_id, message, channel }
     * @return array Workflow scenario { nodes, edges, settings }
     */
    public static function from_pipeline_plan( array $plan, array $trigger_context = [] ) {
        $nodes = [];
        $edges = [];

        // Trigger node (virtual — represents the user's message)
        $trigger_id = 'node-trigger';
        $nodes[] = [
            'id'   => $trigger_id,
            'type' => 'trigger',
            'data' => [
                'code'     => 'pipeline_trigger',
                'settings' => [
                    'pipeline_id' => $plan['pipeline_id'],
                    'message'     => $trigger_context['message'] ?? '',
                    'user_id'     => $trigger_context['user_id'] ?? 0,
                    'session_id'  => $trigger_context['session_id'] ?? '',
                    'channel'     => $trigger_context['channel'] ?? 'webchat',
                ],
            ],
            'position' => [ 'x' => 100, 'y' => 200 ],
        ];

        $prev_node_id = $trigger_id;
        $x_offset     = 350;

        foreach ( $plan['steps'] as $step ) {
            $node_id = 'node-step-' . $step['step_index'];

            // Build input_json from step slots + input_map
            $input_json = $step['slots'] ?? [];

            // Convert input_map references to workflow variable syntax
            // $step[N].data.field → {{node-step-N.data_field}}
            foreach ( $step['input_map'] ?? [] as $target_field => $ref ) {
                if ( preg_match( '/^\$step\[(\d+)\]\.data\.(.+)$/', $ref, $m ) ) {
                    $source_node = 'node-step-' . $m[1];
                    $source_field = $m[2];
                    $input_json[ $target_field ] = '{{' . $source_node . '.' . $source_field . '}}';
                } elseif ( preg_match( '/^\$slots\.(.+)$/', $ref, $m ) ) {
                    $input_json[ $target_field ] = '{{' . $trigger_id . '.' . $m[1] . '}}';
                }
            }

            // Action node: it_call_tool
            $nodes[] = [
                'id'   => $node_id,
                'type' => 'action',
                'data' => [
                    'code'     => 'it_call_tool',
                    'settings' => [
                        'tool_id'        => $step['tool'],
                        'input_json'     => wp_json_encode( $input_json, JSON_UNESCAPED_UNICODE ),
                        'user_id_source' => 'trigger',
                        'pipeline_step'  => $step['step_index'],
                    ],
                ],
                'position' => [
                    'x' => $x_offset + ( $step['step_index'] * 250 ),
                    'y' => 200,
                ],
            ];

            // Edge from previous node → current node
            $edges[] = [
                'id'           => 'edge-' . $prev_node_id . '-' . $node_id,
                'source'       => $prev_node_id,
                'target'       => $node_id,
                'sourceHandle' => 'output-right',
            ];

            $prev_node_id = $node_id;
        }

        return [
            'nodes'    => $nodes,
            'edges'    => $edges,
            'settings' => [
                'timeout'     => 300,
                'mode'        => 'execute_once',
                'pipeline_id' => $plan['pipeline_id'],
            ],
            'version'  => '1.0.0',
        ];
    }

    /**
     * Save scenario as a draft task in bizcity_tasks (status=0 = New).
     *
     * Uses $wpdb directly for reliability — no dependency on WaicFrame load order.
     * The task appears in the Workspace builder and the user confirms via the UI.
     *
     * @param array  $scenario   From from_pipeline_plan().
     * @param string $title      Human-readable title.
     * @param array  $context    Trigger context (user_id, session_id, etc.).
     * @return int|false  Task row ID or false on failure.
     */
    private static function save_draft_task( array $scenario, string $title, array $context = [] ) {
        global $wpdb;

        $table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

        // Check table exists
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return false;
        }

        $now = current_time( 'mysql', true );

        $params = wp_json_encode( [
            'nodes'    => $scenario['nodes'],
            'edges'    => $scenario['edges'],
            'settings' => $scenario['settings'],
            'version'  => $scenario['version'] ?? '1.0.0',
            'meta'     => [
                'pipeline_id' => $scenario['settings']['pipeline_id'] ?? '',
                'session_id'  => $context['session_id'] ?? '',
                'channel'     => $context['channel'] ?? 'webchat',
                'generated_by'=> 'scenario_generator',
            ],
        ], JSON_UNESCAPED_UNICODE );

        $inserted = $wpdb->insert( $table, [
            'feature' => 'workflow',
            'author'  => $context['user_id'] ?? get_current_user_id(),
            'title'   => mb_substr( $title, 0, 250 ),
            'params'  => $params,
            'status'  => 0, // New — awaiting user review
            'created' => $now,
            'updated' => $now,
            'mode'    => 'execute_once',
            'steps'   => count( $scenario['nodes'] ) - 1, // exclude trigger node
        ] );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Build a descriptive title from the plan.
     *
     * @param array $plan From Core Planner.
     * @return string
     */
    private static function build_title( array $plan ): string {
        $tools = [];
        foreach ( $plan['steps'] ?? [] as $step ) {
            $tools[] = $step['tool'] ?? 'unknown';
        }

        if ( ! empty( $plan['package_tool'] ) ) {
            return 'Kế hoạch: ' . $plan['package_tool'];
        }

        if ( count( $tools ) === 1 ) {
            return 'Kế hoạch: ' . $tools[0];
        }

        return 'Kế hoạch ' . count( $tools ) . ' bước: ' . implode( ' → ', array_slice( $tools, 0, 4 ) );
    }
}
