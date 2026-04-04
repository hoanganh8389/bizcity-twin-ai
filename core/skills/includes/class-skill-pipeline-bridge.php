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
 * BizCity Skill Pipeline Bridge — Archetype C Skill → Pipeline Generator
 *
 * Listens to `bizcity_skill_trigger_pipeline` action (fired by class-skill-context.php
 * when an archetype C skill is matched). Converts the skill into a pipeline plan
 * via Core Planner → Scenario Generator → bizcity_tasks + bizcity_intent_todos.
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
			'skill_content' => $skill['content'] ?? '',
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
			$gen_result = $this->generate_pipeline_from_skill( $payload );

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
