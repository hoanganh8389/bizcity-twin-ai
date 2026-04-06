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
				(array) ( $skill['frontmatter']['related_tools'] ?? [] )
			),
			'skill_content'    => $skill['content'] ?? '',
			'archetype'        => $skill['archetype'] ?? 'C',
			'steps'            => (array) ( $skill['frontmatter']['steps'] ?? [] ),
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
			'tìm kiếm|tìm tài liệu|nghiên cứu|research|search|thu thập nguồn|collect source|web search|tìm trên mạng|tìm trên internet|tra cứu' => 'it_call_research',

			// Write / create content → it_call_content
			'viết|tạo nội dung|tạo bài|write|generate|soạn|biên soạn|create content|tổng hợp.*bài|content' => 'it_call_content',

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
			'code'     => 'waic_trigger',
			'label'    => 'Trigger: ' . $skill_title,
			'settings' => [ 'message' => $message ],
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
					'topic'   => '{{node#0.message}}',
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
						'goal_label'    => '{{node#0.message}}',
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
				$settings['research_query'] = '{{node#0.message}}';
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
				'settings' => [ 'research_query' => '{{node#0.message}}' ],
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
}
