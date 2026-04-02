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
     * Friendly tool label map for known tools.
     * Used to build human-readable node labels in the builder UI.
     *
     * @var array tool_id => Vietnamese label
     */
    private static $tool_labels = [
        'write_article'         => 'Viết bài blog',
        'write_seo_article'     => 'Viết bài SEO',
        'rewrite_article'       => 'Viết lại bài viết',
        'translate_and_publish' => 'Dịch & đăng bài',
        'generate_image'        => 'Tạo ảnh minh họa',
        'create_facebook_post'  => 'Đăng Facebook',
        'post_facebook'         => 'Đăng Facebook',
        'create_video'          => 'Tạo video',
        'schedule_post'         => 'Hẹn giờ đăng bài',
        'send_email'            => 'Gửi email',
        'send_zalo_message'     => 'Gửi tin Zalo',
        'knowledge_search'      => 'Tìm kiến thức',
    ];

    /**
     * Get the tool labels map (for use by other classes like Core Planner).
     *
     * @return array tool_id => Vietnamese label
     */
    public static function get_tool_labels(): array {
        return self::$tool_labels;
    }

    /**
     * Convert a pipeline plan into a workflow scenario (nodes + edges).
     *
     * Generates the exact same JSON structure as manually-built workflows
     * (workflow-17 reference format): numeric string IDs, full data envelope
     * with type/category/label/code/settings, {{node#N.var}} variable syntax,
     * and edges with sourceHandle + targetHandle.
     *
     * @param array $plan            From BizCity_Core_Planner::build_plan().
     * @param array $trigger_context { user_id, session_id, message, channel }
     * @return array Workflow scenario { nodes, edges, settings }
     */
    public static function from_pipeline_plan( array $plan, array $trigger_context = [] ) {
        $nodes = [];
        $edges = [];

        $channel = $trigger_context['channel'] ?? 'webchat';

        // ── Use bc_instant_run trigger so workflow can execute immediately without waiting for event ──
        $trigger_code  = 'bc_instant_run';
        $trigger_label = '⚡ Instant Run — Chạy ngay';

        // ── Node ID layout: "1"=trigger, "2"=planner, "3"=verify-content, "4"+=actions, last=verifier ──
        $trigger_node_id = '1';
        $planner_node_id = '2';
        $verify_node_id  = '3';

        // Trigger node — matches builder's real trigger format
        $nodes[] = [
            'id'       => $trigger_node_id,
            'type'     => 'trigger',
            'position' => [ 'x' => 350, 'y' => 200 ],
            'data'     => [
                'type'            => 'trigger',
                'category'        => 'bc',
                'code'            => $trigger_code,
                'label'           => $trigger_label,
                'settings'        => [],
                'executionStatus' => null,
                'dragged'         => true,
            ],
        ];

        // ── TODOs Planner Node (id="2") ──
        // Creates TODO tracking rows and sends plan-start message to user.
        // B1 fix: include node_id + node_code mapping, filter out skip-list tools
        $skip_tools = [ 'write_article', 'write_seo_article' ];
        $steps_for_planner = [];
        foreach ( $plan['steps'] ?? [] as $s ) {
            $tool_name = $s['tool'] ?? 'unknown';
            // Skip tools handled by verify-content node (no runtime node)
            if ( in_array( $tool_name, $skip_tools, true ) ) {
                continue;
            }
            $step_node_id = (string) ( ( $s['step_index'] ?? 0 ) + 4 );
            $steps_for_planner[] = [
                'tool_name' => $tool_name,
                'label'     => self::$tool_labels[ $tool_name ] ?? $tool_name,
                'node_id'   => $step_node_id,
                'node_code' => '', // filled at runtime by block execution
            ];
        }
        $pipeline_label = self::build_title( $plan );

        $nodes[] = [
            'id'       => $planner_node_id,
            'type'     => 'action',
            'position' => [ 'x' => 560, 'y' => 200 ],
            'data'     => [
                'type'            => 'action',
                'category'        => 'it',
                'code'            => 'it_todos_planner',
                'label'           => '📋 Khởi tạo kế hoạch',
                'settings'        => [
                    'steps_json'     => wp_json_encode( $steps_for_planner, JSON_UNESCAPED_UNICODE ),
                    'pipeline_label' => $pipeline_label,
                ],
                'executionStatus' => null,
                'dragged'         => true,
            ],
        ];

        // Edge: trigger → planner
        $edges[] = [
            'id'           => 'e' . $trigger_node_id . '-' . $planner_node_id,
            'source'       => $trigger_node_id,
            'target'       => $planner_node_id,
            'sourceHandle' => 'output-right',
            'targetHandle' => 'input-left',
            'type'         => 'default',
        ];

        // ── Verify-Content Node (id="3") ──
        // AI clarifies, supplements, and structures the user's content requirements.
        // Exposes {{node#3.title}} and {{node#3.content}} for ALL subsequent nodes.
        $content_value = $plan['_content_value'] ?? ( $trigger_context['message'] ?? '' );
        $plan_summary  = [];
        foreach ( $plan['steps'] ?? [] as $s ) {
            $plan_summary[] = self::$tool_labels[ $s['tool'] ?? '' ] ?? $s['tool'];
        }

        $verify_prompt = "Bạn là trợ lý biên tập nội dung. Dựa trên yêu cầu sau, hãy:\n"
            . "1. Làm rõ nội dung chính (clarify)\n"
            . "2. Bổ sung chi tiết nếu còn thiếu (supplement)\n"
            . "3. Viết hoàn chỉnh title và content\n\n"
            . "YÊU CẦU: " . $content_value . "\n"
            . "MỤC TIÊU: " . implode( ' → ', $plan_summary ) . "\n\n"
            . "Trả về JSON: {\"title\": \"...\", \"content\": \"...\"}";

        $nodes[] = [
            'id'       => $verify_node_id,
            'type'     => 'action',
            'position' => [ 'x' => 770, 'y' => 200 ],
            'data'     => [
                'type'            => 'action',
                'category'        => 'it',
                'code'            => 'it_call_tool',
                'label'           => '📝 Xác minh & Biên tập nội dung',
                'settings'        => [
                    'tool_id'        => 'write_article',
                    'input_json'     => wp_json_encode( [
                        'topic'   => $content_value,
                        'message' => $verify_prompt,
                    ], JSON_UNESCAPED_UNICODE ),
                    'user_id_source' => 'trigger',
                ],
                'executionStatus' => null,
                'dragged'         => true,
            ],
        ];

        // Edge: planner → verify-content
        $edges[] = [
            'id'           => 'e' . $planner_node_id . '-' . $verify_node_id,
            'source'       => $planner_node_id,
            'target'       => $verify_node_id,
            'sourceHandle' => 'output-right',
            'targetHandle' => 'input-left',
            'type'         => 'default',
        ];

        $prev_node_id = $verify_node_id;
        $x_pos        = 980;

        // ── Native block mapping: tool_id → { category, code, settings_builder } ──
        // Tools with native blocks use them directly instead of it_call_tool wrapper.
        $native_block_map = [
            'write_article' => [
                'category' => 'wp',
                'code'     => 'wp_create_post',
                'label'    => '📄 Tạo bài viết WordPress',
                'settings' => function( $verify_nid ) {
                    return [
                        'title'  => '{{node#' . $verify_nid . '.title}}',
                        'body'   => '{{node#' . $verify_nid . '.content}}',
                        'status' => 'publish',
                    ];
                },
            ],
            'write_seo_article' => [
                'category' => 'wp',
                'code'     => 'wp_create_post',
                'label'    => '📄 Tạo bài SEO WordPress',
                'settings' => function( $verify_nid ) {
                    return [
                        'title'  => '{{node#' . $verify_nid . '.title}}',
                        'body'   => '{{node#' . $verify_nid . '.content}}',
                        'status' => 'publish',
                    ];
                },
            ],
            'create_facebook_post' => [
                'category' => 'bc',
                'code'     => 'ai_generate_facebook',
                'label'    => '📘 Đăng Facebook',
                'settings' => function( $verify_nid ) {
                    return [
                        'message'   => '{{node#' . $verify_nid . '.content}}',
                        'image_url' => '',
                    ];
                },
            ],
            'post_facebook' => [
                'category' => 'bc',
                'code'     => 'ai_generate_facebook',
                'label'    => '📘 Đăng Facebook',
                'settings' => function( $verify_nid ) {
                    return [
                        'message'   => '{{node#' . $verify_nid . '.content}}',
                        'image_url' => '',
                    ];
                },
            ],
        ];

        foreach ( $plan['steps'] as $step ) {
            // Node ID = step_index + 4 (trigger="1", planner="2", verify="3", first action="4")
            $node_id   = (string) ( $step['step_index'] + 4 );
            $tool_name = $step['tool'];

            // Skip tools already handled by the verify-content node (#3).
            // The verify node calls write_article which creates the WP post;
            // generating a separate wp_create_post node would double-publish.
            if ( in_array( $tool_name, [ 'write_article', 'write_seo_article' ], true ) ) {
                continue;
            }

            // Check if this tool has a native block
            $native = $native_block_map[ $tool_name ] ?? null;

            if ( $native ) {
                // ── Native block node ──
                $settings_fn = $native['settings'];
                $block_settings = $settings_fn( $verify_node_id );

                $nodes[] = [
                    'id'       => $node_id,
                    'type'     => 'action',
                    'position' => [ 'x' => $x_pos, 'y' => 200 ],
                    'data'     => [
                        'type'            => 'action',
                        'category'        => $native['category'],
                        'code'            => $native['code'],
                        'label'           => $native['label'],
                        'settings'        => $block_settings,
                        'executionStatus' => null,
                        'dragged'         => true,
                    ],
                ];
            } else {
                // ── Fallback: it_call_tool wrapper ──
                $raw_slots   = $step['slots'] ?? [];
                $tool_inputs = $step['input_fields'] ?? [];
                $input_json  = [];

                if ( ! empty( $tool_inputs ) ) {
                    foreach ( $tool_inputs as $field => $cfg ) {
                        if ( isset( $raw_slots[ $field ] ) && $raw_slots[ $field ] !== '' ) {
                            $input_json[ $field ] = $raw_slots[ $field ];
                        }
                    }
                }

                // Convert input_map references → {{node#N.field}} with offset +2 for planner+verify nodes.
                foreach ( $step['input_map'] ?? [] as $target_field => $ref ) {
                    if ( preg_match( '/^\$step\[(\d+)\]\.data\.(.+)$/', $ref, $m ) ) {
                        $source_node_id = (string) ( (int) $m[1] + 4 );
                        $source_field   = $m[2];
                        $input_json[ $target_field ] = '{{node#' . $source_node_id . '.' . $source_field . '}}';
                    } elseif ( preg_match( '/^\$slots\.(.+)$/', $ref, $m ) ) {
                        $input_json[ $target_field ] = '{{node#' . $trigger_node_id . '.' . $m[1] . '}}';
                    }
                }

                // Auto-reference verify-content node for primary input fields
                $primary_keys = [ 'message', 'content', 'description', 'topic' ];
                foreach ( $primary_keys as $pk ) {
                    if ( isset( $tool_inputs[ $pk ] ) && empty( $input_json[ $pk ] ) ) {
                        $input_json[ $pk ] = '{{node#' . $verify_node_id . '.content}}';
                        break;
                    }
                }
                if ( isset( $tool_inputs['title'] ) && empty( $input_json['title'] ) ) {
                    $input_json['title'] = '{{node#' . $verify_node_id . '.title}}';
                }

                $label = '🤖 Agent — ' . ( self::$tool_labels[ $tool_name ] ?? $tool_name );

                $nodes[] = [
                    'id'       => $node_id,
                    'type'     => 'action',
                    'position' => [ 'x' => $x_pos, 'y' => 200 ],
                    'data'     => [
                        'type'            => 'action',
                        'category'        => 'it',
                        'code'            => 'it_call_tool',
                        'label'           => $label,
                        'settings'        => [
                            'tool_id'        => $tool_name,
                            'input_json'     => wp_json_encode( $input_json, JSON_UNESCAPED_UNICODE ),
                            'user_id_source' => 'trigger',
                        ],
                        'executionStatus' => null,
                        'dragged'         => true,
                    ],
                ];
            }

            // Edge from previous node
            $edges[] = [
                'id'           => 'e' . $prev_node_id . '-' . $node_id,
                'source'       => $prev_node_id,
                'target'       => $node_id,
                'sourceHandle' => 'output-right',
                'targetHandle' => 'input-left',
                'type'         => 'default',
            ];

            $prev_node_id = $node_id;
            $x_pos       += 210;
        }

        // ── Summary Verifier Node (last node) ──
        // Aggregates results, calculates pipeline score, sends final summary.
        $verifier_node_id = (string) ( count( $plan['steps'] ) + 4 ); // after all action nodes

        $nodes[] = [
            'id'       => $verifier_node_id,
            'type'     => 'action',
            'position' => [ 'x' => $x_pos, 'y' => 200 ],
            'data'     => [
                'type'            => 'action',
                'category'        => 'it',
                'code'            => 'it_summary_verifier',
                'label'           => '✅ Tổng kết Pipeline',
                'settings'        => [
                    'pipeline_label' => $pipeline_label,
                ],
                'executionStatus' => null,
                'dragged'         => true,
            ],
        ];

        // Edge: last action → verifier
        $edges[] = [
            'id'           => 'e' . $prev_node_id . '-' . $verifier_node_id,
            'source'       => $prev_node_id,
            'target'       => $verifier_node_id,
            'sourceHandle' => 'output-right',
            'targetHandle' => 'input-left',
            'type'         => 'default',
        ];

        return [
            'nodes'    => $nodes,
            'edges'    => $edges,
            'settings' => [
                'timeout'     => 300,
                'mode'        => 'execute_once',
                'multiple'    => 0,
                'skip'        => 0,
                'cooldown'    => 0,
                'stop'        => 'yes',
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
                'pipeline_id'      => $scenario['settings']['pipeline_id'] ?? '',
                'pipeline_version' => 1,
                'session_id'       => $context['session_id'] ?? '',
                'channel'          => $context['channel'] ?? 'webchat',
                'generated_by'     => 'scenario_generator',
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
