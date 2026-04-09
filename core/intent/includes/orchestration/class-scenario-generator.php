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

        if ( $task_id ) {
            self::build_memory_spec_for_task( $task_id, $scenario, $trigger_context );

            // ── Phase 1.6 B2: Fire pipeline_created for session memory spec ──
            // Escalates session mode: goal → pipeline
            do_action( 'bizcity_pipeline_created', $task_id, $trigger_context, $scenario );
        }

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
     * Generate a workflow task from pre-built Archetype D pipeline nodes.
     *
     * Unlike generate() which rebuilds nodes from Core Planner steps via
     * from_pipeline_plan(), this method takes already-assembled nodes from
     * Skill Pipeline Bridge and wraps them in the proper WAIC builder format.
     *
     * Used by: BizCity_Skill_Pipeline_Bridge::save_d_pipeline_as_task()
     *
     * @param array  $nodes           Simplified nodes [{ type, code, label, settings }, ...]
     * @param array  $trigger_context { user_id, session_id, message, channel, pipeline_id }
     * @param string $description     Task title.
     * @return array Same format as generate(): { success, task_id, plan_link, message, scenario }
     */
    public static function generate_from_agentic( array $nodes, array $trigger_context = [], string $description = '' ) {
        $builder_nodes = [];
        $builder_edges = [];
        $x_pos = 350;

        foreach ( $nodes as $i => $node ) {
            $node_id  = (string) ( $i + 1 );
            $code     = $node['code'] ?? 'unknown';
            $type     = $node['type'] ?? 'action';

            // Derive category from code prefix
            if ( strpos( $code, 'it_' ) === 0 ) {
                $category = 'it';
            } elseif ( strpos( $code, 'bc_' ) === 0 ) {
                $category = 'bc';
            } else {
                $category = 'bc'; // triggers + generic WAIC blocks
            }

            // Use tool_labels for display, fall back to node's own label
            $label = isset( self::$tool_labels[ $code ] ) ? self::$tool_labels[ $code ] : ( $node['label'] ?? $code );

            $builder_nodes[] = [
                'id'       => $node_id,
                'type'     => $type,
                'position' => [ 'x' => $x_pos, 'y' => 200 ],
                'data'     => [
                    'type'            => $type,
                    'category'        => $category,
                    'code'            => $code,
                    'label'           => $label,
                    'settings'        => $node['settings'] ?? [],
                    'executionStatus' => null,
                    'dragged'         => true,
                ],
            ];

            // Sequential edge from previous node
            if ( $i > 0 ) {
                $prev_id = (string) $i;
                $builder_edges[] = [
                    'id'           => 'e' . $prev_id . '-' . $node_id,
                    'source'       => $prev_id,
                    'target'       => $node_id,
                    'sourceHandle' => 'output-right',
                    'targetHandle' => 'input-left',
                    'type'         => 'default',
                ];
            }

            $x_pos += 210;
        }

        $pipeline_id = $trigger_context['pipeline_id'] ?? ( 'skill_d_' . wp_generate_password( 8, false ) );

        $scenario = [
            'nodes'    => $builder_nodes,
            'edges'    => $builder_edges,
            'settings' => [
                'timeout'     => 600,
                'mode'        => 'execute_once',
                'multiple'    => 0,
                'skip'        => 0,
                'cooldown'    => 0,
                'stop'        => 'yes',
                'pipeline_id' => $pipeline_id,
            ],
            'version' => '1.0.0',
        ];

        $title   = sanitize_text_field( $description ?: 'Agentic Pipeline' );
        $task_id = self::save_draft_task( $scenario, $title, $trigger_context );

        if ( $task_id ) {
            self::build_memory_spec_for_task( $task_id, $scenario, $trigger_context );
            do_action( 'bizcity_pipeline_created', $task_id, $trigger_context, $scenario );
        }

        if ( ! $task_id ) {
            return [
                'success'  => false,
                'task_id'  => 0,
                'plan_link' => '',
                'message'  => 'Không thể lưu Agentic Pipeline. Vui lòng thử lại.',
                'scenario' => $scenario,
            ];
        }

        $plan_link  = self::get_builder_url( $task_id );
        $step_count = count( $nodes ) - 1; // exclude trigger

        return [
            'success'   => true,
            'task_id'   => $task_id,
            'plan_link' => $plan_link,
            'message'   => sprintf(
                "📋 Đã tạo Agentic Pipeline %d bước: **%s**\n\n👉 [Xem & xác nhận](%s)",
                $step_count,
                $title,
                $plan_link
            ),
            'scenario' => $scenario,
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
        'write_article'            => 'Viết bài blog',
        'generate_blog_content'    => 'Viết bài blog',
        'write_seo_article'        => 'Viết bài SEO',
        'generate_seo_content'     => 'Viết nội dung SEO',
        'rewrite_article'          => 'Viết lại bài viết',
        'rewrite_content'          => 'Viết lại nội dung',
        'translate_and_publish'    => 'Dịch & đăng bài',
        'generate_image'           => 'Tạo ảnh minh họa',
        'create_facebook_post'     => 'Đăng Facebook',
        'post_facebook'            => 'Đăng Facebook',
        'generate_fb_post'         => 'Đăng Facebook',
        'create_video'             => 'Tạo video',
        'generate_video_script'    => 'Tạo kịch bản video',
        'schedule_post'            => 'Hẹn giờ đăng bài',
        'send_email'               => 'Gửi email',
        'generate_email_sales'     => 'Tạo email bán hàng',
        'generate_email_reply'     => 'Tạo email trả lời',
        'send_zalo_message'        => 'Gửi tin Zalo',
        'send_zalo'                => 'Gửi tin Zalo',
        'knowledge_search'         => 'Tìm kiến thức',
        'post_website'             => 'Đăng bài WordPress',
        'publish_wp_post'          => 'Đăng bài WordPress',
        'generate_ig_caption'      => 'Tạo caption Instagram',
        'generate_linkedin_post'   => 'Tạo bài LinkedIn',
        'generate_proposal'        => 'Tạo đề xuất',

        // ── Phase 1.10: Agentic Pipeline Blocks ──
        'it_call_research'         => '🔍 Nghiên cứu & Thu thập nguồn',
        'it_call_memory'           => '🧠 Lưu context pipeline',
        'it_call_content'          => '📝 Tạo nội dung',
        'it_call_skill'            => '🎯 Skill — Resolve & Inject',
        'it_call_reflection'       => '🎯 Kiểm tra & Tổng kết',
        'it_todos_planner'         => '📋 Khởi tạo kế hoạch',
    ];

    /**
     * Tool taxonomy map — classifies tools for proper workflow node generation.
     *
     * Rules:
     *   - atomic content (accepts_skill=true) → it_call_content node, skip if same family as Node #3
     *   - distribution → it_call_tool node (delivers content, never generates)
     *   - composite → it_call_tool node (internal pipeline, NOT for workflow automation)
     *   - mutation → it_call_tool node (creates/modifies real objects)
     *
     * @var array tool_id => { family, tool_type, execution_path }
     */
    private static $tool_taxonomy = [
        // ── Atomic Content Tools (accepts_skill=true, use it_call_content) ──
        'generate_blog_content'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_seo_content'     => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'rewrite_content'          => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_fb_post'         => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_ad_copy'         => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_ig_caption'      => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_linkedin_post'   => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_threads_post'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_youtube_desc'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_tiktok_script'   => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_zalo_message'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_sales'     => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_reply'     => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_quote'     => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_contract'  => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_announce'  => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_newsletter' => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_email_followup'  => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_proposal'        => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_report_content'  => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_product_desc'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_landing_page'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_faq'             => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_video_script'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_shorts_script'   => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_podcast_outline' => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_presentation'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_policy'          => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_sop'             => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_job_description' => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_meeting_notes'   => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_comparison'      => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_testimonial_request' => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_campaign_brief'  => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_support_reply'   => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_chatbot_response' => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_announcement'    => [ 'family' => 'content_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_content' ],
        'generate_image'           => [ 'family' => 'image_production', 'tool_type' => 'atomic', 'execution_path' => 'it_call_tool' ],

        // ── Distribution Tools (accepts_skill=false, use it_call_tool) ──
        'post_facebook'            => [ 'family' => 'distribution', 'tool_type' => 'distribution', 'execution_path' => 'it_call_tool' ],
        'send_email'               => [ 'family' => 'distribution', 'tool_type' => 'distribution', 'execution_path' => 'it_call_tool' ],
        'send_zalo'                => [ 'family' => 'distribution', 'tool_type' => 'distribution', 'execution_path' => 'it_call_tool' ],
        'publish_wp_post'          => [ 'family' => 'distribution', 'tool_type' => 'distribution', 'execution_path' => 'it_call_tool' ],
        'schedule_post'            => [ 'family' => 'distribution', 'tool_type' => 'distribution', 'execution_path' => 'it_call_tool' ],

        // ── Composite Tools (multi-step internal, NOT for workflow automation) ──
        'post_website'             => [ 'family' => 'content_production', 'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],
        'write_article'            => [ 'family' => 'content_production', 'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],
        'write_seo_article'        => [ 'family' => 'content_production', 'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],
        'rewrite_article'          => [ 'family' => 'content_production', 'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],
        'create_facebook_post'     => [ 'family' => 'distribution',       'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],
        'translate_and_publish'    => [ 'family' => 'content_production', 'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],
        'create_video'             => [ 'family' => 'content_production', 'tool_type' => 'composite', 'execution_path' => 'provider_direct' ],

        // ── Phase 1.10: Agentic Pipeline Blocks (self-contained execution) ──
        'it_call_research'         => [ 'family' => 'pipeline_infra', 'tool_type' => 'pipeline_block', 'execution_path' => 'it_call_research' ],
        'it_call_memory'           => [ 'family' => 'pipeline_infra', 'tool_type' => 'pipeline_block', 'execution_path' => 'it_call_memory' ],
        'it_call_skill'            => [ 'family' => 'pipeline_infra', 'tool_type' => 'pipeline_block', 'execution_path' => 'it_call_skill' ],
        'it_call_reflection'       => [ 'family' => 'pipeline_infra', 'tool_type' => 'pipeline_block', 'execution_path' => 'it_call_reflection' ],
        'it_todos_planner'         => [ 'family' => 'pipeline_infra', 'tool_type' => 'pipeline_block', 'execution_path' => 'it_todos_planner' ],
    ];

    /**
     * Resolve a composite/provider tool to its atomic equivalents for workflow.
     *
     * e.g. write_article (composite) → [ generate_blog_content, publish_wp_post ]
     *      create_facebook_post (composite) → [ generate_fb_post, post_facebook ]
     *
     * @param string $tool_name Original tool from plan
     * @return array|null [ content_atomic, distribution_atomic ] or null if not composite
     */
    private static function resolve_composite( string $tool_name ): ?array {
        $map = [
            'post_website'         => [ 'generate_blog_content', 'publish_wp_post' ],
            'write_article'        => [ 'generate_blog_content', 'publish_wp_post' ],
            'write_seo_article'    => [ 'generate_seo_content',  'publish_wp_post' ],
            'create_facebook_post' => [ 'generate_fb_post',      'post_facebook' ],
        ];
        return $map[ $tool_name ] ?? null;
    }

    /**
     * Get the tool labels map (for use by other classes like Core Planner).
     */
    public static function get_tool_labels(): array {
        return self::$tool_labels;
    }

    /**
     * Get the tool taxonomy map.
     */
    public static function get_tool_taxonomy(): array {
        return self::$tool_taxonomy;
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

        // ── Trigger selection: scheduler if plan has schedule_at, otherwise instant ──
        $schedule_at = $trigger_context['schedule_at'] ?? '';
        if ( $schedule_at ) {
            $trigger_code  = 'bc_scheduler_run';
            $trigger_label = '📅 Scheduler Run — Lên lịch';
            // Parse schedule_at into date + time for trigger settings
            $sched_date = substr( $schedule_at, 0, 10 ); // Y-m-d
            $sched_time = strlen( $schedule_at ) >= 16 ? substr( $schedule_at, 11, 5 ) : '20:00';
            $trigger_settings = [
                'mode'         => 'one',
                'date'         => $sched_date,
                'time'         => $sched_time,
                'default_text' => $trigger_context['message'] ?? '',
            ];
        } else {
            $trigger_code  = 'bc_instant_run';
            $trigger_label = '⚡ Instant Run — Chạy ngay';
            $trigger_settings = [
                'default_text'      => $trigger_context['message'] ?? '',
                'default_image_url' => '',
            ];
        }

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
                'settings'        => $trigger_settings,
                'executionStatus' => null,
                'dragged'         => true,
            ],
        ];

        // ═══════════════════════════════════════════════════════════
        //  STEP A: Expand plan — resolve composite tools to atomic
        // ═══════════════════════════════════════════════════════════
        $expanded_steps = [];
        foreach ( $plan['steps'] ?? [] as $step ) {
            $tool_name = $step['tool'] ?? 'unknown';
            $taxonomy  = self::$tool_taxonomy[ $tool_name ] ?? null;

            // Composite tools get expanded into atomic equivalents
            if ( $taxonomy && $taxonomy['tool_type'] === 'composite' ) {
                $atomics = self::resolve_composite( $tool_name );
                if ( $atomics ) {
                    foreach ( $atomics as $atomic_tool ) {
                        $expanded_steps[] = [
                            'tool'         => $atomic_tool,
                            'step_index'   => count( $expanded_steps ),
                            'slots'        => $step['slots'] ?? [],
                            'input_fields' => $step['input_fields'] ?? [],
                            'input_map'    => $step['input_map'] ?? [],
                            'depends_on'   => $step['depends_on'] ?? [],
                            'trust_tier'   => $step['trust_tier'] ?? 2,
                            '_expanded_from' => $tool_name,
                        ];
                    }
                    continue;
                }
            }

            // Already atomic/distribution — keep as-is
            $step['step_index'] = count( $expanded_steps );
            $expanded_steps[] = $step;
        }

        // ═══════════════════════════════════════════════════════════
        //  STEP B: Identify primary content tool AND image tool
        // ═══════════════════════════════════════════════════════════
        // First atomic content_production tool → becomes it_call_content Node #3 (content).
        // First image_production tool → becomes separate it_call_tool Node (image).
        $primary_content_tool = null;
        $content_step_indices = []; // indices consumed by content Node #3

        $image_tool = null;
        $image_step_indices = [];   // indices consumed by image Node

        foreach ( $expanded_steps as $idx => $step ) {
            $tool_name = $step['tool'];
            $taxonomy  = self::$tool_taxonomy[ $tool_name ] ?? null;

            if ( ! $taxonomy ) {
                continue;
            }

            // Content production → Node #3
            if ( $taxonomy['family'] === 'content_production'
                 && $taxonomy['tool_type'] === 'atomic'
                 && $taxonomy['execution_path'] === 'it_call_content'
            ) {
                if ( $primary_content_tool === null ) {
                    $primary_content_tool = $tool_name;
                }
                if ( $primary_content_tool === $tool_name ) {
                    $content_step_indices[] = $idx;
                }
            }

            // Image production → separate image node
            if ( $taxonomy['family'] === 'image_production' && $taxonomy['tool_type'] === 'atomic' ) {
                if ( $image_tool === null ) {
                    $image_tool = $tool_name;
                }
                if ( $image_tool === $tool_name ) {
                    $image_step_indices[] = $idx;
                }
            }
        }

        // Fallback: if no atomic content tool found, use generate_blog_content
        if ( ! $primary_content_tool ) {
            $primary_content_tool = 'generate_blog_content';
        }

        // ═══════════════════════════════════════════════════════════
        //  STEP C: Build planner node steps (exclude content + image steps)
        // ═══════════════════════════════════════════════════════════
        $consumed_indices = array_merge( $content_step_indices, $image_step_indices );
        $steps_for_planner = [];
        foreach ( $expanded_steps as $idx => $s ) {
            if ( in_array( $idx, $consumed_indices, true ) ) {
                continue; // handled by dedicated nodes
            }
            $tool_name = $s['tool'];
            $step_node_id = (string) ( $idx + 4 );
            $steps_for_planner[] = [
                'tool_name' => $tool_name,
                'label'     => self::$tool_labels[ $tool_name ] ?? $tool_name,
                'node_id'   => $step_node_id,
                'node_code' => '',
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

        // ═══════════════════════════════════════════════════════════
        //  STEP D: Verify-Content Node #3 (atomic content tool)
        // ═══════════════════════════════════════════════════════════
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
                'code'            => 'it_call_content',
                'label'           => '📝 Xác minh & Biên tập nội dung',
                'settings'        => [
                    'content_tool'      => $primary_content_tool,
                    'input_json'        => wp_json_encode( [
                        'topic'   => $content_value,
                        'message' => $verify_prompt,
                    ], JSON_UNESCAPED_UNICODE ),
                    'require_confirm'   => '0',
                    'max_refine_rounds' => '2',
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

        // Track publish_wp_post node ID so downstream facebook tool can wire post_url → link
        $publish_node_id = null;

        // ═══════════════════════════════════════════════════════════
        //  STEP D2: Image Generation Node (if plan includes generate_image)
        // ═══════════════════════════════════════════════════════════
        $image_node_id = null;
        if ( $image_tool ) {
            // Image node ID: use a fixed offset to avoid collision with action nodes
            $image_node_id = (string) ( count( $expanded_steps ) + 5 );

            $image_prompt = "Tạo ảnh minh họa chuyên nghiệp cho bài viết.\n"
                . "Chủ đề: " . $content_value . "\n"
                . "Tiêu đề bài: {{node#" . $verify_node_id . ".title}}\n"
                . "Phong cách: hiện đại, chuyên nghiệp, phù hợp nội dung bài viết\n"
                . "Yêu cầu: ảnh rõ ràng, bố cục cân đối, màu sắc hài hòa, không chứa text trên ảnh";

            // Phase 1.1h: Inject skill/brand context into image prompt if available
            if ( class_exists( 'BizCity_Tool_Run' ) ) {
                $image_skill = BizCity_Tool_Run::resolve_skill( 'generate_image', $options['user_id'] ?? 0 );
                if ( $image_skill && ! empty( $image_skill['content'] ) ) {
                    $image_prompt .= "\n[Brand/Style]\n" . mb_substr( $image_skill['content'], 0, 500 );
                }
            }

            $nodes[] = [
                'id'       => $image_node_id,
                'type'     => 'action',
                'position' => [ 'x' => $x_pos, 'y' => 350 ], // offset Y to show parallel
                'data'     => [
                    'type'            => 'action',
                    'category'        => 'it',
                    'code'            => 'it_call_tool',
                    'label'           => '🖼️ Tạo ảnh minh họa',
                    'settings'        => [
                        'tool_id'        => $image_tool,
                        'input_json'     => wp_json_encode( [
                            'prompt'  => $image_prompt,
                            'topic'   => '{{node#' . $verify_node_id . '.title}}',
                        ], JSON_UNESCAPED_UNICODE ),
                        'user_id_source' => 'trigger',
                    ],
                    'executionStatus' => null,
                    'dragged'         => true,
                ],
            ];

            // Edge: content node → image node (image depends on content for title)
            $edges[] = [
                'id'           => 'e' . $verify_node_id . '-' . $image_node_id,
                'source'       => $verify_node_id,
                'target'       => $image_node_id,
                'sourceHandle' => 'output-right',
                'targetHandle' => 'input-left',
                'type'         => 'default',
            ];

            $prev_node_id = $image_node_id;
            $x_pos       += 210;
        }

        // ═══════════════════════════════════════════════════════════
        //  STEP E: Build action nodes (distribution + remaining tools)
        // ═══════════════════════════════════════════════════════════
        foreach ( $expanded_steps as $idx => $step ) {
            // Skip content + image steps already consumed by dedicated nodes
            if ( in_array( $idx, $consumed_indices, true ) ) {
                continue;
            }

            $node_id   = (string) ( $idx + 4 );
            $tool_name = $step['tool'];
            $taxonomy  = self::$tool_taxonomy[ $tool_name ] ?? null;

            // Build input_json — auto-wire upstream content from Node #3
            $input_json = [];
            $tool_inputs = $step['input_fields'] ?? [];

            // For distribution tools: wire content/title from Node #3 + image from image node
            if ( $taxonomy && $taxonomy['tool_type'] === 'distribution' ) {
                $content_fields = [ 'content', 'message', 'description' ];
                foreach ( $content_fields as $cf ) {
                    if ( isset( $tool_inputs[ $cf ] ) ) {
                        $input_json[ $cf ] = '{{node#' . $verify_node_id . '.content}}';
                    }
                }
                if ( isset( $tool_inputs['title'] ) ) {
                    $input_json['title'] = '{{node#' . $verify_node_id . '.title}}';
                }
                // Wire image_url from image node if available, otherwise leave empty
                if ( isset( $tool_inputs['image_url'] ) ) {
                    $input_json['image_url'] = $image_node_id
                        ? '{{node#' . $image_node_id . '.image_url}}'
                        : '';
                }
                // Wire post URL from publish_wp_post into facebook tool's 'link' field
                if ( isset( $tool_inputs['link'] ) && $publish_node_id ) {
                    $input_json['link'] = '{{node#' . $publish_node_id . '.post_url}}';
                }
            }

            // Track publish_wp_post node so subsequent facebook tools can reference post_url
            if ( $tool_name === 'publish_wp_post' ) {
                $publish_node_id = $node_id;
            }

            // Fill from existing slots
            $raw_slots = $step['slots'] ?? [];
            foreach ( $tool_inputs as $field => $cfg ) {
                if ( ! isset( $input_json[ $field ] ) && isset( $raw_slots[ $field ] ) && $raw_slots[ $field ] !== '' ) {
                    $input_json[ $field ] = $raw_slots[ $field ];
                }
            }

            // Convert input_map references → {{node#N.field}}
            foreach ( $step['input_map'] ?? [] as $target_field => $ref ) {
                if ( preg_match( '/^\$step\[(\d+)\]\.data\.(.+)$/', $ref, $m ) ) {
                    $source_node_id = (string) ( (int) $m[1] + 4 );
                    $source_field   = $m[2];
                    $input_json[ $target_field ] = '{{node#' . $source_node_id . '.' . $source_field . '}}';
                }
            }

            // Determine execution path based on taxonomy
            $exec_path = $taxonomy['execution_path'] ?? 'it_call_tool';
            $label = ( self::$tool_labels[ $tool_name ] ?? $tool_name );

            if ( $exec_path === 'it_call_content' ) {
                // Atomic content tool that's NOT the primary (e.g. second content step)
                $node_label = '📝 ' . $label;
                $nodes[] = [
                    'id'       => $node_id,
                    'type'     => 'action',
                    'position' => [ 'x' => $x_pos, 'y' => 200 ],
                    'data'     => [
                        'type'            => 'action',
                        'category'        => 'it',
                        'code'            => 'it_call_content',
                        'label'           => $node_label,
                        'settings'        => [
                            'content_tool'      => $tool_name,
                            'input_json'        => wp_json_encode( $input_json, JSON_UNESCAPED_UNICODE ),
                            'require_confirm'   => '0',
                            'max_refine_rounds' => '2',
                        ],
                        'executionStatus' => null,
                        'dragged'         => true,
                    ],
                ];
            } else {
                // Distribution / mutation / fallback → it_call_tool
                $family_icon = '🤖';
                if ( $taxonomy ) {
                    switch ( $taxonomy['family'] ) {
                        case 'distribution': $family_icon = '📤'; break;
                        case 'mutation':     $family_icon = '🔧'; break;
                        case 'scheduler':    $family_icon = '📅'; break;
                    }
                }
                $node_label = $family_icon . ' ' . $label;

                $nodes[] = [
                    'id'       => $node_id,
                    'type'     => 'action',
                    'position' => [ 'x' => $x_pos, 'y' => 200 ],
                    'data'     => [
                        'type'            => 'action',
                        'category'        => 'it',
                        'code'            => 'it_call_tool',
                        'label'           => $node_label,
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
        $verifier_node_id = (string) ( count( $expanded_steps ) + 6 ); // +6 to avoid collision with image node (+5)

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
    public static function save_draft_task( array $scenario, string $title, array $context = [] ) {
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
                'slash_command'    => $context['slash_command'] ?? '',
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
     * Update scenario params for an existing task (e.g. after auto-fix).
     *
     * @param int   $task_id  Existing task row ID.
     * @param array $scenario Updated scenario JSON.
     * @return bool True if updated.
     */
    public static function update_task_params( int $task_id, array $scenario ): bool {
        global $wpdb;

        $table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

        $params = wp_json_encode( [
            'nodes'    => $scenario['nodes'],
            'edges'    => $scenario['edges'],
            'settings' => $scenario['settings'],
            'version'  => $scenario['version'] ?? '1.0.0',
            'meta'     => [
                'pipeline_id'      => $scenario['settings']['pipeline_id'] ?? '',
                'pipeline_version' => 1,
                'generated_by'     => 'scenario_generator',
                'auto_fixed'       => true,
            ],
        ], JSON_UNESCAPED_UNICODE );

        $updated = $wpdb->update(
            $table,
            [ 'params' => $params, 'updated' => current_time( 'mysql', true ) ],
            [ 'id' => $task_id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );

        return $updated !== false;
    }

    /**
     * Build Memory Spec after task is saved.
     *
     * @param int   $task_id  Task row ID.
     * @param array $scenario { nodes, edges, settings }.
     * @param array $context  { user_id, session_id, channel }.
     */
    private static function build_memory_spec_for_task( int $task_id, array $scenario, array $context ) {
        if ( class_exists( 'BizCity_Memory_Spec' ) ) {
            BizCity_Memory_Spec::build_from_pipeline( $task_id, $scenario, $context );
        }
    }

    /**
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
