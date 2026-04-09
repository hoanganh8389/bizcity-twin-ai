<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Pipeline Bridge — Archetype C+D Skill → Pipeline Generator
 *
 * Listens to `bizcity_skill_trigger_pipeline` action (fired by class-skill-context.php
 * when an archetype C or D skill is matched). Converts the skill into a pipeline plan.
 *
 * Archetype C: Core Planner → Scenario Generator → bizcity_tasks + bizcity_intent_todos.
 * Archetype D: execution_plan YAML → direct 5-step pipeline (research→memory→content→reflection).
 *
 * DESIGN NOTE (G4 fix): This action fires inside a `bizcity_chat_system_prompt` filter.
 * Heavy work (LLM calls, DB inserts) MUST NOT block the filter chain.
 * Strategy: store pending skill in transient, then process after prompt return.
 *
 * Flow:
 *   1. `bizcity_skill_trigger_pipeline` → queue_pipeline_generation() [store transient]
 *   2. `bizcity_intent_processed` → process_pending_pipeline() [heavy work here]
 *   3. Core Planner → build_plan()
 *   4. Scenario Generator → generate() → save draft task
	 *   5. Planner node creates todos at runtime (single source of truth)
 *   6. Return builder link to user via Pipeline Messenger
 *
 * @package  BizCity_Skills
 * @since    2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Pipeline_Bridge {

	private static $instance = null;

	/** @var string Log prefix for all error_log() calls */
	private const LOG_PREFIX = '[SkillBridge]';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		// Step 1: Queue — runs inside filter chain (FAST, no heavy work)
		add_action( 'bizcity_skill_trigger_pipeline', [ $this, 'queue_pipeline_generation' ], 10, 2 );

		// Step 2: Process — runs AFTER engine finishes (existing hook in class-intent-engine.php line 3525)
		add_action( 'bizcity_intent_processed', [ $this, 'process_pending_pipeline' ], 20, 2 );

		// Fallback: process on shutdown if bizcity_intent_processed never fires (early returns)
		add_action( 'shutdown', [ $this, 'process_pending_pipeline_shutdown' ] );
	}

	/* ================================================================
	 *  Step 1: Queue (inside filter chain — must be fast)
	 * ================================================================ */

	/**
	 * Queue a pipeline generation request. Fires inside bizcity_chat_system_prompt filter.
	 * Stores skill + context in a short-lived transient for deferred processing.
	 *
	 * @param array $skill { path, frontmatter, content, score, reasons, archetype }
	 * @param array $args  { mode, message, engine_result }
	 */
	public function queue_pipeline_generation( array $skill, array $args ): void {
		$skill_path  = $skill['path'] ?? 'unknown';
		$skill_title = $skill['frontmatter']['title'] ?? basename( $skill_path, '.md' );

		error_log( self::LOG_PREFIX . ' [QUEUE] Archetype C skill matched: ' . $skill_title . ' (' . $skill_path . ')' );
		error_log( self::LOG_PREFIX . ' [QUEUE] Score: ' . ( $skill['score'] ?? 0 ) . ' | Reasons: ' . implode( '+', $skill['reasons'] ?? [] ) );

		// Extract key data for deferred processing
		// B3+B10 fix: session_id/channel come from filter $args (top-level), NOT engine_result['meta']
		$engine  = $args['engine_result'] ?? [];
		$payload = [
			'skill_path'    => $skill_path,
			'skill_title'   => $skill_title,
			'skill_tools'   => array_merge(
				(array) ( $skill['frontmatter']['tools'] ?? [] ),
				(array) ( $skill['frontmatter']['related_tools'] ?? [] ),
				(array) ( $skill['body_tool_refs'] ?? [] )
			),
			'skill_content'    => $skill['content'] ?? '',
			'archetype'        => $skill['archetype'] ?? 'C',
			'steps'            => ! empty( $skill['frontmatter']['steps'] )
				? (array) $skill['frontmatter']['steps']
				: (array) ( $skill['body_steps'] ?? [] ),
			'mode'          => $args['mode'] ?? '',
			'message'       => $args['message'] ?? '',
			'goal'          => $engine['meta']['goal'] ?? $engine['goal'] ?? '',
			'session_id'    => $args['session_id'] ?? $engine['meta']['session_id'] ?? '',
			'user_id'       => get_current_user_id(),
			'channel'       => $engine['channel'] ?? $args['channel'] ?? 'webchat',
			'queued_at'     => microtime( true ),
		];

		// B2 fix: session-scoped transient key to prevent collision between guests (user_id=0)
		// and between multiple sessions of the same user
		$scope_key     = $payload['session_id'] ?: (string) $payload['user_id'];
		$transient_key = 'bizcity_skill_pipe_' . substr( md5( $scope_key ), 0, 16 );
		set_transient( $transient_key, $payload, 300 ); // 5 min TTL — just enough to survive the request

		// Store transient key in a user-level index so process_pending can find it
		$index_key = 'bizcity_skill_pipe_idx_' . $payload['user_id'];
		set_transient( $index_key, $transient_key, 300 );

		error_log( self::LOG_PREFIX . ' [QUEUE] Stored pending pipeline: transient=' . $transient_key . ' tools=' . implode( ',', $payload['skill_tools'] ) );

		// Trace for observability
		if ( class_exists( 'BizCity_Twin_Trace' ) ) {
			BizCity_Twin_Trace::gate( 'skill_bridge', true, 'queued: ' . $skill_title . ' → pipeline pending' );
		}
	}

	/* ================================================================
	 *  Step 2: Process (after filter chain — safe for heavy work)
	 * ================================================================ */

	/**
	 * Process pending pipeline after intent engine finishes.
	 * Hooked to bizcity_intent_processed (fires at end of process() in class-intent-engine.php).
	 *
	 * @param array $result Engine result { reply, action, goal, slots, meta, ... }
	 * @param array $params Original request params { message, session_id, user_id, channel, ... }
	 */
	public function process_pending_pipeline( $result = [], $params = [] ): void {
		// B2 fix: look up session-scoped transient via user-level index
		$user_id       = get_current_user_id();
		$index_key     = 'bizcity_skill_pipe_idx_' . $user_id;
		$transient_key = get_transient( $index_key );
		$payload       = $transient_key ? get_transient( $transient_key ) : false;

		if ( ! $payload ) {
			return; // No pending pipeline for this user
		}

		// Consume both transients immediately to prevent double-processing
		delete_transient( $transient_key );
		delete_transient( $index_key );

		// B12 fix: patch missing session_id/channel from hook $params (bizcity_intent_processed)
		if ( is_array( $params ) && ! empty( $params ) ) {
			if ( empty( $payload['session_id'] ) && ! empty( $params['session_id'] ) ) {
				$payload['session_id'] = $params['session_id'];
			}
			if ( ( $payload['channel'] === 'webchat' ) && ! empty( $params['channel'] ) ) {
				$payload['channel'] = $params['channel'];
			}
		}

		$elapsed = round( ( microtime( true ) - ( $payload['queued_at'] ?? microtime( true ) ) ) * 1000, 1 );
		error_log( self::LOG_PREFIX . ' [PROCESS] Starting pipeline generation (queued ' . $elapsed . 'ms ago)' );
		error_log( self::LOG_PREFIX . ' [PROCESS] Skill: ' . $payload['skill_title'] . ' | User: ' . $payload['user_id'] . ' | Tools: ' . implode( ',', $payload['skill_tools'] ) );

		$start_time = microtime( true );

		try {
			// Route by archetype: D uses steps inference, C uses Core Planner
			$archetype = $payload['archetype'] ?? 'C';

			if ( $archetype === 'D' ) {
				$gen_result = $this->generate_pipeline_from_steps( $payload );
			} else {
				$gen_result = $this->generate_pipeline_from_skill( $payload );
			}

			$total_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );

			if ( $gen_result['success'] ) {
				error_log( self::LOG_PREFIX . ' [PROCESS] ✅ Pipeline created: task_id=' . $gen_result['task_id'] . ' todos=' . $gen_result['todo_count'] . ' (' . $total_ms . 'ms)' );

				// Send builder link to user via Messenger
				$this->notify_user( $payload, $gen_result );
			} else {
				error_log( self::LOG_PREFIX . ' [PROCESS] ❌ Pipeline failed: ' . $gen_result['error'] . ' (' . $total_ms . 'ms)' );
			}
		} catch ( \Throwable $e ) {
			$total_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
			error_log( self::LOG_PREFIX . ' [PROCESS] 💥 Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . ' (' . $total_ms . 'ms)' );
		}

		// Trace
		if ( class_exists( 'BizCity_Twin_Trace' ) ) {
			BizCity_Twin_Trace::gate( 'skill_bridge', true, 'processed: ' . $payload['skill_title'] );
		}
	}

	/**
	 * Shutdown fallback — if bizcity_intent_processed never fires
	 * (e.g. early returns in process()), process on shutdown.
	 */
	public function process_pending_pipeline_shutdown(): void {
		// B2 fix: use session-scoped transient via index
		$user_id       = get_current_user_id();
		$index_key     = 'bizcity_skill_pipe_idx_' . $user_id;
		$transient_key = get_transient( $index_key );
		$payload       = $transient_key ? get_transient( $transient_key ) : false;

		if ( ! $payload ) {
			return;
		}

		error_log( self::LOG_PREFIX . ' [SHUTDOWN] Processing pending pipeline (bizcity_intent_processed did not fire)' );
		$this->process_pending_pipeline();
	}

	/* ================================================================
	 *  Core: Generate Pipeline from Skill
	 * ================================================================ */

	/**
	 * Convert skill payload into a full pipeline (plan + scenario + task + todos).
	 *
	 * @param array $payload From queue_pipeline_generation().
	 * @return array { success, task_id, todo_count, plan_link, error }
	 */
	private function generate_pipeline_from_skill( array $payload ): array {
		// ── Step 3a: Check dependencies ──
		if ( ! class_exists( 'BizCity_Core_Planner' ) ) {
			error_log( self::LOG_PREFIX . ' [GEN] ❌ BizCity_Core_Planner class not loaded' );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'Core Planner not loaded' ];
		}
		if ( ! class_exists( 'BizCity_Scenario_Generator' ) ) {
			error_log( self::LOG_PREFIX . ' [GEN] ❌ BizCity_Scenario_Generator class not loaded' );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'Scenario Generator not loaded' ];
		}

		// ── Step 3b: Build objectives from skill tools ──
		$tools   = array_unique( array_filter( $payload['skill_tools'] ) );
		$message = $payload['message'] ?? '';

		if ( empty( $tools ) ) {
			error_log( self::LOG_PREFIX . ' [GEN] ❌ Skill has no tools: ' . $payload['skill_path'] );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'Skill has no tools' ];
		}

		$objectives = [];
		foreach ( $tools as $tool_name ) {
			$objectives[] = [
				'text'       => $message ?: $payload['skill_title'],
				'tool_hint'  => $tool_name,
				'confidence' => 0.9, // Skill match = high confidence
			];
		}

		error_log( self::LOG_PREFIX . ' [GEN] Built ' . count( $objectives ) . ' objectives: ' . implode( ', ', $tools ) );

		// ── Step 3c: Core Planner → build_plan() ──
		$planner = BizCity_Core_Planner::instance();
		$plan    = $planner->build_plan( $objectives, [
			'user_id'            => $payload['user_id'],
			'session_id'         => $payload['session_id'],
			'conversation_slots' => [],
		] );

		$step_count = $plan['step_count'] ?? 0;
		error_log( self::LOG_PREFIX . ' [GEN] Plan built: mode=' . ( $plan['mode'] ?? '?' ) . ' steps=' . $step_count . ' pipeline_id=' . ( $plan['pipeline_id'] ?? '?' ) );

		if ( $step_count === 0 && empty( $plan['package_tool'] ) ) {
			error_log( self::LOG_PREFIX . ' [GEN] ❌ Plan has 0 steps — tools may not be registered' );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'Plan has 0 steps — tools not registered' ];
		}

		// ── Step 3d: Scenario Generator → generate() → save draft task ──
		$trigger_context = [
			'user_id'    => $payload['user_id'],
			'session_id' => $payload['session_id'],
			'message'    => $message,
			'channel'    => $payload['channel'],
		];

		$description = 'Skill: ' . $payload['skill_title'];
		$gen_result  = BizCity_Scenario_Generator::generate( $plan, $trigger_context, $description );

		if ( ! $gen_result['success'] ) {
			error_log( self::LOG_PREFIX . ' [GEN] ❌ Scenario Generator failed: ' . ( $gen_result['message'] ?? 'unknown' ) );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'Scenario Generator failed' ];
		}

		$task_id   = $gen_result['task_id'];
		$scenario  = $gen_result['scenario'];
		$plan_link = $gen_result['plan_link'];

		error_log( self::LOG_PREFIX . ' [GEN] ✅ Task saved: task_id=' . $task_id . ' plan_link=' . $plan_link );

		// B1 fix: Do NOT create todos here — planner node (it_todos_planner) creates them
		// at runtime with task_id from $taskId param. This prevents double creation.
		$step_count = count( $plan['steps'] ?? [] );
		error_log( self::LOG_PREFIX . ' [GEN] ℹ️ Todos will be created by planner node at runtime (' . $step_count . ' steps, pipeline_id=' . ( $plan['pipeline_id'] ?? '?' ) . ')' );

		return [
			'success'    => true,
			'task_id'    => $task_id,
			'todo_count' => $step_count,
			'plan_link'  => $plan_link,
			'error'      => '',
		];
	}

	/* ================================================================
	 *  Core (D-path): Infer Pipeline from user-friendly steps + @tools
	 * ================================================================ */

	/**
	 * Keyword patterns for step→block inference.
	 *
	 * Skill writers are low-tech users. They write steps in natural language like:
	 *   "Tìm kiếm tài liệu từ internet"
	 *   "Viết bài chuyên gia dựa trên tài liệu"
	 *
	 * This map translates those into pipeline block codes.
	 * Order matters: first match wins.
	 *
	 * @return array [ pattern => block_code ]
	 */
	private static function get_step_inference_map(): array {
		return [
			// Research / search / tìm kiếm → it_call_research
			// @tool_name patterns: if step text mentions @deep_research etc., infer directly
			'@deep_research|@web_search|@search_web|@tavily|tìm kiếm|tìm tài liệu|nghiên cứu|research|search|thu thập nguồn|collect source|web search|tìm trên mạng|tìm trên internet|tra cứu' => 'it_call_research',

			// Write / create content → it_call_content
			'@generate_blog_content|@generate_fb_post|@write_article|@write_fb_post|@write_post|viết|tạo nội dung|tạo bài|write|generate|soạn|biên soạn|create content|tổng hợp.*bài|content' => 'it_call_content',

			// Memory / save context → it_call_memory
			'lưu|ghi nhớ|memory|save context|persist|snapshot|lưu trữ|context' => 'it_call_memory',

			// Reflection / verify → it_call_reflection
			'kiểm tra|xác nhận|verify|check|review|rà soát|đánh giá|reflection|tổng kết|summary|phản hồi' => 'it_call_reflection',

			// Notify / send → bc_send_adminchat
			'gửi|thông báo|notify|send|đăng|post|publish|báo cáo|report' => 'bc_send_adminchat',
		];
	}

	/**
	 * Convert archetype D skill into a pipeline by inferring blocks from:
	 *   - `steps[]` (user-friendly text, e.g. "Tìm kiếm tài liệu từ internet")
	 *   - `tools[]` (@tool_name mentions, e.g. "@generate_blog_content")
	 *   - `skill_content` (natural language body for additional context)
	 *
	 * Low-tech users don't know about it_call_research, BCN_Tavily_Client, etc.
	 * The bridge infers the correct pipeline blocks from their natural language.
	 *
	 * @param array $payload From queue_pipeline_generation().
	 * @return array { success, task_id, todo_count, plan_link, error }
	 */
	private function generate_pipeline_from_steps( array $payload ): array {
		$steps       = $payload['steps'] ?? [];
		$skill_tools = $payload['skill_tools'] ?? [];
		$session_id  = $payload['session_id'] ?? '';
		$user_id     = $payload['user_id'] ?? 0;
		$channel     = $payload['channel'] ?? 'webchat';
		$message     = $payload['message'] ?? '';
		$skill_title = $payload['skill_title'] ?? 'Unnamed Skill';
		$content     = $payload['skill_content'] ?? '';

		error_log( self::LOG_PREFIX . ' [GEN-D] Archetype D: ' . $skill_title . ' | steps=' . count( $steps ) . ' | @tools=' . implode( ',', $skill_tools ) );

		// ── Clean @tools: strip @ prefix ──
		$clean_tools = [];
		foreach ( $skill_tools as $t ) {
			$t = ltrim( trim( $t ), '@' );
			if ( $t ) {
				$clean_tools[] = $t;
			}
		}

		// ── Infer blocks from steps (natural language → block code) ──
		$inferred_blocks = $this->infer_blocks_from_steps( $steps, $clean_tools, $content );

		if ( empty( $inferred_blocks ) ) {
			// Fallback: if no steps but has @tools, infer from tools alone
			$inferred_blocks = $this->infer_blocks_from_tools( $clean_tools, $content );
		}

		if ( empty( $inferred_blocks ) ) {
			error_log( self::LOG_PREFIX . ' [GEN-D] ❌ Could not infer any blocks from steps/tools' );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'Cannot infer pipeline from steps' ];
		}

		error_log( self::LOG_PREFIX . ' [GEN-D] Inferred ' . count( $inferred_blocks ) . ' blocks: ' . implode( ' → ', array_column( $inferred_blocks, 'code' ) ) );

		// ── Auto-inject bookend infrastructure nodes ──
		// it_call_memory: insert after last research, before first content
		// it_call_reflection: always append as the final action node
		$inferred_blocks = $this->inject_bookend_nodes( $inferred_blocks );

		error_log( self::LOG_PREFIX . ' [GEN-D] After bookend injection: ' . implode( ' → ', array_column( $inferred_blocks, 'code' ) ) );

		// ── Build workflow nodes ──
		$nodes = [];

		// Node 0: Trigger
		$nodes[] = [
			'type'     => 'trigger',
			'code'     => 'bc_instant_run',
			'label'    => 'Trigger: ' . $skill_title,
			'settings' => [ 'default_text' => $message ],
		];

		// Node 1: Planner (creates todos)
		$step_labels = [];
		foreach ( $inferred_blocks as $block ) {
			$step_labels[] = [
				'tool_name' => $block['code'],
				'label'     => $block['label'],
			];
		}
		$nodes[] = [
			'type'     => 'action',
			'code'     => 'it_todos_planner',
			'label'    => 'Plan: ' . $skill_title,
			'settings' => [
				'steps_json'     => wp_json_encode( $step_labels ),
				'pipeline_label' => $skill_title,
			],
		];

		// ── Track research node index for content auto-chain ──
		$research_node_index = null;

		// Node 2+: inferred + bookend action blocks
		$node_index = 2;
		foreach ( $inferred_blocks as $block ) {
			$node_settings = $block['settings'] ?? [];

			// Remember the ACTUAL research node index
			if ( $block['code'] === 'it_call_research' ) {
				$research_node_index = $node_index;
			}

			// Auto-chain: inject upstream reference for content blocks
			// Use tracked research node index (not node_index-1) to survive memory node insertion
			if ( $block['code'] === 'it_call_content' && $research_node_index !== null ) {
				$node_settings['input_json'] = wp_json_encode( [
					'topic'   => '{{node#0.text}}',
					'sources' => '{{node#' . $research_node_index . '.research_summary}}',
				] );
			}

			$nodes[] = [
				'type'     => 'action',
				'code'     => $block['code'],
				'label'    => $block['label'],
				'settings' => $node_settings,
			];
			$node_index++;
		}

		error_log( self::LOG_PREFIX . ' [GEN-D] Built ' . count( $nodes ) . ' workflow nodes' );

		// ── Save as task ──
		return $this->save_d_pipeline_as_task( $payload, $nodes, $inferred_blocks );
	}

	/**
	 * Auto-inject infrastructure bookend nodes that every Archetype D pipeline needs:
	 *   - it_call_memory:     after the last research block, before first content block
	 *   - it_call_reflection: always as the final action node
	 *
	 * If user already explicitly included these in their steps, skip injection.
	 *
	 * @param array $blocks Inferred blocks from steps.
	 * @return array Modified blocks with bookend nodes injected.
	 */
	private function inject_bookend_nodes( array $blocks ): array {
		$codes = array_column( $blocks, 'code' );

		// ── 1. Inject it_call_memory (if not already present) ──
		if ( ! in_array( 'it_call_memory', $codes, true ) ) {
			// Find insert position: after last it_call_research, before first it_call_content
			$insert_pos = null;
			$last_research_idx = null;
			$first_content_idx = null;

			foreach ( $blocks as $i => $block ) {
				if ( $block['code'] === 'it_call_research' ) {
					$last_research_idx = $i;
				}
				if ( $block['code'] === 'it_call_content' && $first_content_idx === null ) {
					$first_content_idx = $i;
				}
			}

			if ( $last_research_idx !== null ) {
				$insert_pos = $last_research_idx + 1;
			} elseif ( $first_content_idx !== null ) {
				$insert_pos = $first_content_idx;
			}

			if ( $insert_pos !== null ) {
				$memory_block = [
					'code'     => 'it_call_memory',
					'label'    => 'Lưu context pipeline vào memory',
					'settings' => [
						'goal_label'    => '{{node#0.text}}',
						'focus_context' => '',
					],
				];
				array_splice( $blocks, $insert_pos, 0, [ $memory_block ] );
				error_log( self::LOG_PREFIX . ' [BOOKEND] Injected it_call_memory at position ' . $insert_pos );
			}
		}

		// ── 2. Inject it_call_reflection (if not already present) ──
		$codes = array_column( $blocks, 'code' ); // Refresh after splice
		if ( ! in_array( 'it_call_reflection', $codes, true ) ) {
			$blocks[] = [
				'code'     => 'it_call_reflection',
				'label'    => 'Kiểm tra & tổng kết pipeline',
				'settings' => [
					'create_cpt'  => '0',
					'max_retries' => '2',
				],
			];
			error_log( self::LOG_PREFIX . ' [BOOKEND] Appended it_call_reflection at end' );
		}

		return $blocks;
	}

	/**
	 * Infer pipeline blocks from user-friendly step descriptions.
	 *
	 * Example: "Tìm kiếm tài liệu từ internet" → it_call_research
	 *          "Viết bài dựa trên tài liệu"     → it_call_content (with @tool settings)
	 *
	 * @param array $steps       User-written steps (strings).
	 * @param array $clean_tools @tool names without @ prefix.
	 * @param string $content    Skill body text for additional context.
	 * @return array [ { code, label, settings } ]
	 */
	private function infer_blocks_from_steps( array $steps, array $clean_tools, string $content ): array {
		$inference_map = self::get_step_inference_map();
		$blocks        = [];
		$used_codes    = [];
		$content_tool_assigned = false;

		foreach ( $steps as $step_text ) {
			if ( ! is_string( $step_text ) || empty( trim( $step_text ) ) ) {
				continue;
			}
			$step_lower = mb_strtolower( trim( $step_text ) );

			$matched_code = null;
			foreach ( $inference_map as $pattern => $block_code ) {
				if ( preg_match( '/' . $pattern . '/iu', $step_lower ) ) {
					$matched_code = $block_code;
					break;
				}
			}

			if ( ! $matched_code ) {
				error_log( self::LOG_PREFIX . ' [INFER] Could not infer block for step: "' . $step_text . '" — skipping' );
				continue;
			}

			// Build settings for this block
			$settings = [];

			// For it_call_content: assign the first available @tool as content_tool
			if ( $matched_code === 'it_call_content' && ! $content_tool_assigned ) {
				$content_atomic = $this->pick_content_tool( $clean_tools );
				if ( $content_atomic ) {
					$settings['content_tool'] = $content_atomic;
					$content_tool_assigned = true;
				}
			}

			// For it_call_research: auto-set query from trigger
			if ( $matched_code === 'it_call_research' ) {
				$settings['research_query'] = '{{node#0.text}}';
			}

			$blocks[] = [
				'code'     => $matched_code,
				'label'    => $step_text,
				'settings' => $settings,
			];
			$used_codes[] = $matched_code;
		}

		return $blocks;
	}

	/**
	 * Fallback: infer blocks when no steps provided, only @tools.
	 *
	 * If user listed @generate_blog_content but no steps,
	 * we infer: research (if keywords in content) → content → done.
	 *
	 * @param array  $clean_tools
	 * @param string $content
	 * @return array
	 */
	private function infer_blocks_from_tools( array $clean_tools, string $content ): array {
		$blocks      = [];
		$content_lower = mb_strtolower( $content );

		// Check if skill body mentions research/search
		$needs_research = (bool) preg_match( '/tìm kiếm|nghiên cứu|research|search|tài liệu|nguồn|source|internet/iu', $content_lower );

		if ( $needs_research ) {
			$blocks[] = [
				'code'     => 'it_call_research',
				'label'    => 'Tìm kiếm tài liệu',
				'settings' => [ 'research_query' => '{{node#0.text}}' ],
			];
		}

		// Add content block for each @tool that accepts_skill
		$content_tool = $this->pick_content_tool( $clean_tools );
		if ( $content_tool ) {
			$blocks[] = [
				'code'     => 'it_call_content',
				'label'    => 'Tạo nội dung',
				'settings' => [ 'content_tool' => $content_tool ],
			];
		}

		return $blocks;
	}

	/**
	 * Pick the first content-generating @tool from the list.
	 * Only tools registered with accepts_skill=true are valid content tools.
	 *
	 * @param array $clean_tools Tool names without @ prefix.
	 * @return string|null Tool name or null.
	 */
	private function pick_content_tool( array $clean_tools ) {
		if ( empty( $clean_tools ) ) {
			return null;
		}

		// If BizCity_Intent_Tools available, verify tool exists and accepts_skill
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$registry = BizCity_Intent_Tools::instance();
			foreach ( $clean_tools as $tool_name ) {
				if ( ! $registry->has( $tool_name ) ) {
					continue;
				}
				$all = $registry->list_all();
				if ( ! empty( $all[ $tool_name ]['accepts_skill'] ) ) {
					return $tool_name;
				}
			}
		}

		// Fallback: return first tool that looks like a content generator
		foreach ( $clean_tools as $tool_name ) {
			if ( preg_match( '/generate|write|create|compose|draft/i', $tool_name ) ) {
				return $tool_name;
			}
		}

		return $clean_tools[0] ?? null;
	}

	/**
	 * Save D-path pipeline nodes as a WAIC task via Scenario Generator.
	 *
	 * Uses generate_from_agentic() — the D-path equivalent of generate() —
	 * which converts simplified nodes to full WAIC builder format with proper
	 * IDs, positions, data envelopes, edges, memory spec, and pipeline_created.
	 *
	 * @param array $payload
	 * @param array $nodes
	 * @param array $inferred_blocks
	 * @return array { success, task_id, todo_count, plan_link, error }
	 */
	private function save_d_pipeline_as_task( array $payload, array $nodes, array $inferred_blocks ): array {
		$session_id  = $payload['session_id'] ?? '';
		$user_id     = $payload['user_id'] ?? 0;
		$channel     = $payload['channel'] ?? 'webchat';
		$message     = $payload['message'] ?? '';
		$skill_title = $payload['skill_title'] ?? 'Unnamed Skill';
		$pipeline_id = 'skill_d_' . substr( md5( $payload['skill_path'] . $session_id ), 0, 12 );

		// ── Primary: Scenario Generator D-path (correct WAIC format) ──
		if ( class_exists( 'BizCity_Scenario_Generator' ) ) {
			$gen_result = BizCity_Scenario_Generator::generate_from_agentic( $nodes, [
				'user_id'     => $user_id,
				'session_id'  => $session_id,
				'message'     => $message,
				'channel'     => $channel,
				'pipeline_id' => $pipeline_id,
			], 'Skill D: ' . $skill_title );

			if ( $gen_result['success'] ) {
				error_log( self::LOG_PREFIX . ' [GEN-D] ✅ Task saved via Scenario (agentic): task_id=' . $gen_result['task_id'] );
				return [
					'success'    => true,
					'task_id'    => $gen_result['task_id'],
					'todo_count' => count( $inferred_blocks ),
					'plan_link'  => $gen_result['plan_link'],
					'error'      => '',
				];
			}

			error_log( self::LOG_PREFIX . ' [GEN-D] ⚠️ generate_from_agentic() failed, falling back to direct insert' );
		}

		// ── Fallback: Direct WAIC task insert (correct column format) ──
		global $wpdb;
		$table = $wpdb->prefix . ( defined( 'WAIC_DB_PREF' ) ? WAIC_DB_PREF : 'bizcity_' ) . 'tasks';

		// Build full builder-format nodes for WAIC executor
		$builder_nodes = [];
		$builder_edges = [];
		$x_pos = 350;

		foreach ( $nodes as $i => $node ) {
			$node_id  = (string) ( $i + 1 );
			$code     = $node['code'] ?? '';
			$type     = $node['type'] ?? 'action';
			$category = ( strpos( $code, 'it_' ) === 0 ) ? 'it' : 'bc';

			$builder_nodes[] = [
				'id'       => $node_id,
				'type'     => $type,
				'position' => [ 'x' => $x_pos, 'y' => 200 ],
				'data'     => [
					'type'            => $type,
					'category'        => $category,
					'code'            => $code,
					'label'           => $node['label'] ?? $code,
					'settings'        => $node['settings'] ?? [],
					'executionStatus' => null,
					'dragged'         => true,
				],
			];

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

		$params = wp_json_encode( [
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
			'version'  => '1.0.0',
			'meta'     => [
				'pipeline_id'      => $pipeline_id,
				'pipeline_version' => 1,
				'session_id'       => $session_id,
				'channel'          => $channel,
				'source'           => 'skill_d_pipeline',
				'skill_path'       => $payload['skill_path'] ?? '',
				'generated_by'     => 'skill_pipeline_bridge',
			],
		], JSON_UNESCAPED_UNICODE );

		$now = current_time( 'mysql', true );
		$inserted = $wpdb->insert( $table, [
			'feature' => 'workflow',
			'author'  => $user_id,
			'title'   => mb_substr( $skill_title, 0, 250 ),
			'params'  => $params,
			'status'  => 0,
			'created' => $now,
			'updated' => $now,
			'mode'    => 'execute_once',
			'steps'   => count( $nodes ) - 1,
		] );

		if ( ! $inserted ) {
			error_log( self::LOG_PREFIX . ' [GEN-D] ❌ DB insert failed: ' . $wpdb->last_error );
			return [ 'success' => false, 'task_id' => 0, 'todo_count' => 0, 'plan_link' => '', 'error' => 'DB insert failed' ];
		}

		$task_id = $wpdb->insert_id;
		error_log( self::LOG_PREFIX . ' [GEN-D] ✅ Direct insert (WAIC format): task_id=' . $task_id );

		// Build memory spec + fire event (same as Scenario Generator)
		if ( class_exists( 'BizCity_Memory_Spec' ) ) {
			$scenario = [ 'nodes' => $builder_nodes, 'edges' => $builder_edges, 'settings' => [ 'pipeline_id' => $pipeline_id ] ];
			BizCity_Memory_Spec::build_from_pipeline( $task_id, $scenario, [
				'user_id' => $user_id, 'session_id' => $session_id, 'channel' => $channel,
			] );
		}
		do_action( 'bizcity_pipeline_created', $task_id, [
			'user_id' => $user_id, 'session_id' => $session_id, 'channel' => $channel,
		], [ 'nodes' => $builder_nodes, 'edges' => $builder_edges ] );

		$plan_link = class_exists( 'BizCity_Scenario_Generator' )
			? BizCity_Scenario_Generator::get_builder_url( $task_id )
			: admin_url( 'admin.php?page=bizcity-workspace&tab=builder&task_id=' . intval( $task_id ) . '&bizcity_iframe=1' );

		return [
			'success'    => true,
			'task_id'    => $task_id,
			'todo_count' => count( $inferred_blocks ),
			'plan_link'  => $plan_link,
			'error'      => '',
		];
	}

	/**
	/* ================================================================
	 *  Step 4: Notify user via Messenger
	 * ================================================================ */

	/**
	 * Send pipeline creation notification to user's chat session.
	 *
	 * @param array $payload From queue payload.
	 * @param array $result  From generate_pipeline_from_skill().
	 */
	private function notify_user( array $payload, array $result ): void {
		if ( ! class_exists( 'BizCity_Pipeline_Messenger' ) ) {
			error_log( self::LOG_PREFIX . ' [NOTIFY] Pipeline Messenger not available — skipping chat notification' );
			return;
		}

		try {
			$session_id = $payload['session_id'] ?? '';
			if ( ! $session_id ) {
				error_log( self::LOG_PREFIX . ' [NOTIFY] No session_id — cannot send notification' );
				return;
			}

			$message = sprintf(
				"📋 **Kỹ năng \"%s\" đã tạo kế hoạch tự động!**\n\n🔗 [Xem & xác nhận kế hoạch](%s)\n\n🔢 %d bước thực thi | Task #%d",
				$payload['skill_title'],
				$result['plan_link'],
				$result['todo_count'],
				$result['task_id']
			);

			// B3 fix: use send() — send_system_message() does not exist
			BizCity_Pipeline_Messenger::send(
				[
					'session_id'  => $session_id,
					'user_id'     => $payload['user_id'],
					'channel'     => $payload['channel'],
					'pipeline_id' => '',
				],
				$message,
				'info'
			);

			error_log( self::LOG_PREFIX . ' [NOTIFY] ✅ Sent pipeline notification to session=' . $session_id );
		} catch ( \Throwable $e ) {
			error_log( self::LOG_PREFIX . ' [NOTIFY] ❌ Failed: ' . $e->getMessage() );
		}
	}

	/* ================================================================
	 *  Phase 1.12: Pure Planner — Skill → WAIC Nodes (no DB save)
	 * ================================================================ */

	/**
	 * Build WAIC nodes from a skill — pure planner, DOES NOT save task.
	 * Shell calls this when it detects a skill match via skill_tool_map.
	 *
	 * Uses structured frontmatter (chain: + blocks:) for 100% deterministic chaining.
	 * Falls back to tool registry content_tier → naming convention if blocks: absent.
	 *
	 * @param array  $skill   { title, tools, pipeline_config, content }
	 * @param string $message User message.
	 * @return array|null     WAIC nodes[] or null if insufficient data.
	 */
	public function plan_nodes_from_skill( array $skill, string $message, array $options = [] ): ?array {
		$tools            = (array) ( $skill['tools'] ?? [] );
		$title            = $skill['title'] ?? 'Unnamed Skill';
		$config           = (array) ( $skill['pipeline_config'] ?? [] );
		$chain            = (array) ( $config['chain'] ?? [] );
		$blocks           = (array) ( $config['blocks'] ?? [] );
		$extra_dist_tools = (array) ( $options['extra_distribution_tools'] ?? [] );
		// block_code_hints: LLM-provided mapping (tool_id → block_code) from Scenario Planner.
		// Highest priority — overrides both $blocks config and infer_block_code_for_tool().
		$bc_hints         = (array) ( $options['block_code_hints'] ?? [] );
		$skip             = [
			'research'   => ! empty( $config['skip_research'] ),
			'planner'    => ! empty( $config['skip_planner'] ),
			'memory'     => ! empty( $config['skip_memory'] ),
			'reflection' => ! empty( $config['skip_reflection'] ),
		];

		if ( empty( $tools ) ) {
			error_log( self::LOG_PREFIX . ' [PLAN] Cannot plan: no tools in skill' );
			return null;
		}

		error_log( self::LOG_PREFIX . ' [PLAN] Building nodes for: ' . $title
			. ' | tools=' . implode( ',', $tools )
			. ' | chain=' . count( $chain )
			. ' | blocks=' . count( $blocks ) );

		// ── Phase-order tools when no explicit chain config ──
		// Ensures content generation (it_call_content) precedes distribution/action (it_call_tool).
		// Fixes reversed ordering when tools_json has publish before generate.
		if ( empty( $chain ) ) {
			$self = $this;
			usort( $tools, function ( $a, $b ) use ( $blocks, $bc_hints, $self ) {
				$code_a = $bc_hints[ $a ] ?? $blocks[ $a ] ?? $self->infer_block_code_for_tool( $a );
				$code_b = $bc_hints[ $b ] ?? $blocks[ $b ] ?? $self->infer_block_code_for_tool( $b );
				$phase  = [
					'it_call_research' => 1,
					'it_call_content'  => 2,
					'it_call_tool'     => 3,
				];
				return ( $phase[ $code_a ] ?? 3 ) - ( $phase[ $code_b ] ?? 3 );
			} );
			error_log( self::LOG_PREFIX . ' [PLAN] Phase-sorted tools: ' . implode( ', ', $tools ) );
		}

		// Node ID offset: trigger=1, planner=2 (if not skipped)
		$prefix_count = 1 + ( $skip['planner'] ? 0 : 1 );

		$nodes           = [];
		$x_pos           = 350;
		$node_id         = 1;
		$content_node_id = null; // Track content node for auto-wiring distribution tools

		// ── Node 1: Trigger ──
		$nodes[] = [
			'id'       => (string) $node_id,
			'type'     => 'trigger',
			'position' => [ 'x' => $x_pos, 'y' => 200 ],
			'data'     => [
				'type'            => 'trigger',
				'category'        => 'bc',
				'code'            => 'bc_instant_run',
				'label'           => '⚡ Chạy ngay',
				'settings'        => [ 'default_text' => $message ],
				'executionStatus' => null,
				'dragged'         => true,
			],
		];
		$x_pos += 210;
		$node_id++;

		// ── Node 2: Planner (unless skip_planner) ──
		if ( ! $skip['planner'] ) {
			$plan_steps = [];
			foreach ( $tools as $i => $tool_key ) {
				$block_code        = $bc_hints[ $tool_key ] ?? $blocks[ $tool_key ] ?? $this->infer_block_code_for_tool( $tool_key );
				$step_label_map    = [ 'it_call_research' => 'Nghiên cứu tài liệu' ];
				$plan_steps[] = [
					'tool_name' => $block_code,
					'label'     => $step_label_map[ $tool_key ] ?? $tool_key,
					'node_id'   => (string) ( $prefix_count + $i + 1 ),
				];
			}

			$nodes[] = [
				'id'       => (string) $node_id,
				'type'     => 'action',
				'position' => [ 'x' => $x_pos, 'y' => 200 ],
				'data'     => [
					'type'            => 'action',
					'category'        => 'it',
					'code'            => 'it_todos_planner',
					'label'           => '📋 Kế hoạch: ' . $title,
					'settings'        => [
						'steps_json'     => wp_json_encode( $plan_steps ),
						'pipeline_label' => $title,
					],
					'executionStatus' => null,
					'dragged'         => true,
				],
			];
			$x_pos += 210;
			$node_id++;
		}

		// ── Tool action nodes (from structured frontmatter) ──
		foreach ( $tools as $i => $tool_key ) {
			$step_num   = $i + 1;
			$block_code = $bc_hints[ $tool_key ] ?? $blocks[ $tool_key ] ?? $this->infer_block_code_for_tool( $tool_key );
			$category   = ( strpos( $block_code, 'it_' ) === 0 ) ? 'it' : 'bc';

			// Track content node for downstream auto-wiring
			if ( $block_code === 'it_call_content' && null === $content_node_id ) {
				$content_node_id = (string) $node_id;
			}

			// ── Resolve chaining from structured chain: config ──
			// Supports two formats:
			//   Format A: from: { step: N, fields: [...] }           (single source)
			//   Format B: from_multi: [ { step: N, fields: [...] } ] (multiple sources)
			//   Rename:   rename_FIELD: NEW_NAME  (inside from entry)
			$input = [];
			foreach ( $chain as $chain_entry ) {
				if ( (int) ( $chain_entry['step'] ?? 0 ) !== $step_num ) {
					continue;
				}

				// Build from_list from either 'from' or 'from_multi'
				$from_list = [];
				if ( ! empty( $chain_entry['from_multi'] ) && is_array( $chain_entry['from_multi'] ) ) {
					$from_list = $chain_entry['from_multi'];
				} elseif ( ! empty( $chain_entry['from'] ) ) {
					$from_raw = $chain_entry['from'];
					// Normalize single { step, fields } to array-of-one
					if ( isset( $from_raw['step'] ) ) {
						$from_list = [ $from_raw ];
					} else {
						$from_list = (array) $from_raw;
					}
				}

				foreach ( $from_list as $from ) {
					$from_step = (int) ( $from['step'] ?? 0 );
					$fields    = (array) ( $from['fields'] ?? [] );
					$rename    = (array) ( $from['rename'] ?? [] );
					$from_node = $prefix_count + $from_step;

					foreach ( $fields as $field ) {
						// Check rename map OR rename_FIELD key
						$target = $rename[ $field ] ?? ( $from[ 'rename_' . $field ] ?? $field );
						$input[ $target ] = '{{node#' . $from_node . '.' . $field . '}}';
					}
				}
			}

			error_log( self::LOG_PREFIX . ' [PLAN] Step ' . $step_num . ' (' . $tool_key . '): '
				. 'block=' . $block_code . ', input_keys=' . implode( ',', array_keys( $input ) ) );

			// ── Build block settings ──
			$settings = [];
			if ( $block_code === 'it_call_content' ) {
				$settings['content_tool']      = $tool_key;
				$settings['require_confirm']   = '0';
				$settings['max_refine_rounds'] = '2';
				if ( empty( $input ) ) {
					$input['topic'] = '{{node#1.text}}';
				}
			} elseif ( $block_code === 'it_call_tool' ) {
				$settings['tool_id'] = $tool_key;
				// Auto-wire content + title from content node for extra distribution tools
				if ( in_array( $tool_key, $extra_dist_tools, true ) && $content_node_id && empty( $input ) ) {
					$input['content'] = '{{node#' . $content_node_id . '.content}}';
					$input['title']   = '{{node#' . $content_node_id . '.title}}';
				}
			} elseif ( $block_code === 'it_call_research' ) {
				$settings['research_query'] = '{{node#1.text}}';
			}

			if ( ! empty( $input ) ) {
				$settings['input_json'] = wp_json_encode( $input, JSON_UNESCAPED_UNICODE );
			}

			// Friendly label: avoid showing internal sentinel keys to user
			$label_map = [
				'it_call_research' => '🔍 Nghiên cứu',
			];
			$label = $label_map[ $tool_key ] ?? $tool_key;

			$nodes[] = [
				'id'       => (string) $node_id,
				'type'     => 'action',
				'position' => [ 'x' => $x_pos, 'y' => 200 ],
				'data'     => [
					'type'            => 'action',
					'category'        => $category,
					'code'            => $block_code,
					'label'           => $label,
					'settings'        => $settings,
					'executionStatus' => null,
					'dragged'         => true,
				],
			];
			$x_pos += 210;
			$node_id++;
		}

		// ── Bookend: Memory — INSERT after last research, before first content ──
		// 8-finger principle: research(3) → memory(4) → content(5) → tool(6)
		if ( ! $skip['memory'] ) {
			$memory_node = [
				'id'       => '0', // placeholder — recalculated below
				'type'     => 'action',
				'position' => [ 'x' => 0, 'y' => 200 ], // placeholder
				'data'     => [
					'type'            => 'action',
					'category'        => 'it',
					'code'            => 'it_call_memory',
					'label'           => 'Lưu context pipeline',
					'settings'        => [
						'goal_label'    => '{{node#1.text}}',
						'focus_context' => '',
					],
					'executionStatus' => null,
					'dragged'         => true,
				],
			];

			// Find insertion point: after last it_call_research, before first it_call_content
			$mem_insert_idx   = null;
			$last_research_idx = null;
			$first_content_idx = null;
			foreach ( $nodes as $ni => $n ) {
				$code = $n['data']['code'] ?? '';
				if ( $code === 'it_call_research' ) {
					$last_research_idx = $ni;
				}
				if ( $code === 'it_call_content' && $first_content_idx === null ) {
					$first_content_idx = $ni;
				}
			}
			if ( $last_research_idx !== null ) {
				$mem_insert_idx = $last_research_idx + 1;
			} elseif ( $first_content_idx !== null ) {
				$mem_insert_idx = $first_content_idx;
			}

			if ( $mem_insert_idx !== null ) {
				array_splice( $nodes, $mem_insert_idx, 0, [ $memory_node ] );
				// All node IDs >= (mem_insert_idx + 1) shift +1 in {{node#N.xxx}} refs
				$shift_threshold = $mem_insert_idx + 1; // old IDs at/above this shift
			} else {
				$nodes[] = $memory_node; // fallback: append if no research/content found
				$shift_threshold = PHP_INT_MAX; // no shift needed
			}

			// Recalculate IDs and x positions for all nodes
			foreach ( $nodes as $ri => &$rn ) {
				$rn['id']                 = (string) ( $ri + 1 );
				$rn['position']['x']      = 350 + ( $ri * 210 );
			}
			unset( $rn );
			$node_id = count( $nodes ) + 1;
			$x_pos   = 350 + ( count( $nodes ) * 210 );

			// Rewrite {{node#N.xxx}} references in input_json to match new IDs
			if ( $shift_threshold < PHP_INT_MAX ) {
				foreach ( $nodes as &$rn ) {
					$ij = $rn['data']['settings']['input_json'] ?? '';
					if ( $ij !== '' ) {
						$rn['data']['settings']['input_json'] = preg_replace_callback(
							'/\{\{node#(\d+)\./',
							function ( $m ) use ( $shift_threshold ) {
								$old = (int) $m[1];
								$new = $old >= $shift_threshold ? $old + 1 : $old;
								return '{{node#' . $new . '.';
							},
							$ij
						);
					}
				}
				unset( $rn );
			}

			// Rebuild planner steps_json with correct node_ids after splice
			foreach ( $nodes as &$rn ) {
				if ( ( $rn['data']['code'] ?? '' ) === 'it_todos_planner' ) {
					$new_plan_steps = [];
					foreach ( $nodes as $ni => $action_node ) {
						$acode = $action_node['data']['code'] ?? '';
						if ( in_array( $acode, [ 'it_call_research', 'it_call_content', 'it_call_tool' ], true ) ) {
							$step_label_map    = [ 'it_call_research' => 'Nghiên cứu tài liệu' ];
							$tool_label        = $action_node['data']['label'] ?? '';
							$new_plan_steps[]  = [
								'tool_name' => $acode,
								'label'     => $step_label_map[ $acode ] ?? $tool_label,
								'node_id'   => $action_node['id'],
							];
						}
					}
					$rn['data']['settings']['steps_json'] = wp_json_encode( $new_plan_steps );
					break;
				}
			}
			unset( $rn );
		}

		// ── Bookend: Reflection (unless skip_reflection) ──
		if ( ! $skip['reflection'] ) {
			$nodes[] = [
				'id'       => (string) $node_id,
				'type'     => 'action',
				'position' => [ 'x' => $x_pos, 'y' => 200 ],
				'data'     => [
					'type'            => 'action',
					'category'        => 'it',
					'code'            => 'it_call_reflection',
					'label'           => 'Kiểm tra & tổng kết',
					'settings'        => [
						'create_cpt'  => '0',
						'max_retries' => '2',
					],
					'executionStatus' => null,
					'dragged'         => true,
				],
			];
			$x_pos += 210;
			$node_id++;
		}

		// ── Summary verifier ──
		$nodes[] = [
			'id'       => (string) $node_id,
			'type'     => 'action',
			'position' => [ 'x' => $x_pos, 'y' => 200 ],
			'data'     => [
				'type'            => 'action',
				'category'        => 'it',
				'code'            => 'it_summary_verifier',
				'label'           => '✅ Tổng kết Pipeline',
				'settings'        => [
					'pipeline_label' => $title,
				],
				'executionStatus' => null,
				'dragged'         => true,
			],
		];

		error_log( self::LOG_PREFIX . ' [PLAN] ✅ Built ' . count( $nodes ) . ' nodes for: ' . $title );

		return $nodes;
	}

	/**
	 * Infer block code for a tool — 3-layer fallback.
	 *
	 * Used when skill frontmatter doesn't specify blocks: for a tool.
	 *   1. Tool registry content_tier (0=it_call_tool, 1=it_call_content)
	 *   2. Naming convention (generate_*, write_* → it_call_content)
	 *   3. Default → it_call_tool
	 *
	 * @param string $tool_key Tool registration key.
	 * @return string Block code.
	 */
	private function infer_block_code_for_tool( string $tool_key ): string {
		// Layer 1: Tool registry content_tier
		if ( class_exists( 'BizCity_Intent_Tools' ) ) {
			$registry = BizCity_Intent_Tools::instance();
			$all      = $registry->list_all();
			if ( isset( $all[ $tool_key ] ) ) {
				$tier = (int) ( $all[ $tool_key ]['content_tier'] ?? 0 );
				return $tier >= 1 ? 'it_call_content' : 'it_call_tool';
			}
		}

		// Layer 2: Naming convention
		if ( preg_match( '/^generate_|^write_|^video_create_script$/', $tool_key ) ) {
			return 'it_call_content';
		}

		// Layer 3: Default
		return 'it_call_tool';
	}
}
