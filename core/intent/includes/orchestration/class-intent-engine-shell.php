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
 * BizCity Intent Engine Shell — Phase 1.11 S4
 *
 * Lightweight replacement for the 6488-line Intent Engine.
 * 5 steps instead of 48:
 *
 *   1. Init         — parse params, get/create conversation       (~30 lines)
 *   2. Pre-rules    — deterministic, 0 LLM calls                 (~10 lines)
 *   3. Collect + call server — Data Contract v1 → Smart Classifier (~40 lines)
 *   4. Execute      — unified pipeline for ALL actions             (~80 lines)
 *   5. Memory save  — persist session spec + update conversation   (~20 lines)
 *
 * Feature flag: `bizcity_shell_percentage` (0-100) controls traffic routing.
 *   0   = legacy 100%
 *   100 = shell 100%
 *   N   = N% shell, (100-N)% legacy (A/B test)
 *
 * Rollback: set flag to 0 → instant (< 10s).
 *
 * @since Phase 1.11 S4
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Engine_Shell {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private const LOG = '[Shell]';

	/** @var int Server timeout in seconds */
	private const SERVER_TIMEOUT = 5;

	/** @var BizCity_Intent_Conversation */
	private $conversation_mgr;

	/** @var BizCity_Pre_Rules */
	private $pre_rules;

	/** @var BizCity_Context_Collector */
	private $context_collector;

	/** @var BizCity_Intent_Tools */
	private $tools;

	/** @var BizCity_Intent_Logger|null */
	private $logger;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->conversation_mgr  = BizCity_Intent_Conversation::instance();
		$this->pre_rules         = BizCity_Pre_Rules::instance();
		$this->context_collector = class_exists( 'BizCity_Context_Collector' )
			? BizCity_Context_Collector::instance()
			: null;
		$this->tools  = BizCity_Intent_Tools::instance();
		$this->logger = class_exists( 'BizCity_Intent_Logger' ) ? BizCity_Intent_Logger::instance() : null;
	}

	/* ================================================================
	 *  Feature Flag
	 * ================================================================ */

	/**
	 * Check if shell should handle this request.
	 *
	 * @return bool True if shell engine should process.
	 */
	public static function should_handle(): bool {
		// HARDCODE: Shell Engine ON 100% — bypass percentage check
		// TODO: Revert to percentage-based logic after stable
		return true;
	}

	/* ================================================================
	 *  Main: process (5 steps)
	 * ================================================================ */

	/**
	 * Process a message through the Shell pipeline.
	 *
	 * Same params/return signature as BizCity_Intent_Engine::process().
	 *
	 * @param array $params { message, session_id, user_id, channel, character_id, images, ... }
	 * @return array { reply, action, conversation_id, goal, status, slots, meta }
	 */
	public function process( array $params ): array {
		$message      = $params['message']      ?? '';
		$session_id   = $params['session_id']   ?? '';
		$user_id      = intval( $params['user_id'] ?? 0 );
		$channel      = $params['channel']      ?? 'webchat';
		$character_id = intval( $params['character_id'] ?? 0 );
		$start_time   = microtime( true );

		$result = [
			'reply'           => '',
			'action'          => 'passthrough',
			'conversation_id' => '',
			'channel'         => $channel,
			'goal'            => '',
			'goal_label'      => '',
			'status'          => 'ACTIVE',
			'slots'           => [],
			'rolling_summary' => '',
			'meta'            => [ 'engine' => 'shell' ],
		];

		error_log( self::LOG . " process: user={$user_id}, channel={$channel}, msg=" . mb_substr( $message, 0, 80 ) );

		// ── Step 1: Init — Get/create conversation ──
		$conversation = $this->conversation_mgr->get_or_create(
			$user_id, $channel, $session_id, $character_id
		);
		$conv_id = $conversation['conversation_id'];
		$result['conversation_id'] = $conv_id;

		// ── Step 2: Pre-rules — deterministic, 0 LLM ──
		$pre_result = $this->pre_rules->resolve( $message, $params, $conversation );

		if ( $pre_result['handled'] ) {
			$rule   = $pre_result['rule'] ?? 'unknown';
			$action = $pre_result['result']['action'] ?? '';

			error_log( self::LOG . " Pre-rule matched: {$rule}, action={$action}" );

			// Handle pipeline trigger from pre-rules
			if ( $action === 'trigger_pipeline' ) {
				return $this->handle_pipeline_trigger( $pre_result, $params, $conversation, $result );
			}

			// Handle pipeline resume
			if ( $action === 'resume_pipeline' || $action === 'resume_with_slot' ) {
				return $this->handle_pipeline_resume( $pre_result, $params, $conversation, $result );
			}

			// Handle inject_skill — set goal and fall through to server with skill context
			if ( $action === 'inject_skill' ) {
				$skill  = $pre_result['result']['skill'] ?? [];
				$parsed = $pre_result['result']['parsed'] ?? [];
				// Set conversation goal so next turn triggers skill continuation
				$slash_name = ltrim( $skill['frontmatter']['name'] ?? '', '/' );
				if ( $slash_name && $conv_id ) {
					$this->conversation_mgr->set_goal(
						$conv_id,
						'skill:' . $slash_name,
						$skill['frontmatter']['title'] ?? $slash_name
					);
				}
				return $this->step3_server_resolve( $message, $params, $conversation, $result, $skill, $parsed, $start_time );
			}

			// Handle skill continuation — load skill and fall through to server
			if ( $action === 'continue_skill' ) {
				$skill_slug = $pre_result['result']['skill_slug'] ?? '';
				$cont_skill = [];
				$cont_parsed = [];
				if ( $skill_slug && class_exists( 'BizCity_Skill_Manager' ) ) {
					$mgr = BizCity_Skill_Manager::instance();
					if ( method_exists( $mgr, 'find_by_key' ) ) {
						$cont_skill = $mgr->find_by_key( $skill_slug ) ?: [];
					}
					if ( ! empty( $cont_skill ) && class_exists( 'BizCity_Skill_Recipe_Parser' ) ) {
						$cont_parsed = BizCity_Skill_Recipe_Parser::instance()->parse(
							$cont_skill['content'] ?? '',
							$cont_skill['frontmatter'] ?? []
						);
					}
				}
				return $this->step3_server_resolve( $message, $params, $conversation, $result, $cont_skill, $cont_parsed, $start_time );
			}

			// Direct response (help, cancel, confirm_reject, etc.)
			if ( ! empty( $pre_result['result']['reply'] ) ) {
				$result['reply']  = $pre_result['result']['reply'];
				$result['action'] = $pre_result['result']['action'] ?? 'chat';
				$result['meta']   = array_merge( $result['meta'], $pre_result['result']['meta'] ?? [] );
				$this->finalize( $result, $conversation, $start_time, $params );
				return $result;
			}
		}

		// ── Step 3+4: Collect context → call server → execute ──
		return $this->step3_server_resolve( $message, $params, $conversation, $result, [], [], $start_time );
	}

	/* ================================================================
	 *  Step 3: Collect + Server resolve
	 * ================================================================ */

	private function step3_server_resolve(
		string $message,
		array $params,
		array $conversation,
		array $result,
		array $skill = [],
		array $parsed = [],
		float $start_time = 0.0
	): array {
		if ( $start_time <= 0.0 ) {
			$start_time = microtime( true );
		}

		// Build Data Contract v1 payload
		$payload = null;
		if ( $this->context_collector ) {
			$payload = $this->context_collector->build_payload(
				$message, $params, $conversation, $parsed, $skill
			);
		}

		// Call server Smart Classifier
		$server_result = $this->call_server( $payload ?: [ 'message' => $message ] );

		// Fallback on server failure
		if ( ! $server_result || ! empty( $server_result['error'] ) ) {
			error_log( self::LOG . ' Server failed, using local fallback.' );
			$fallback_result = $this->handle_local_fallback( $message, $params, $conversation, $result, $skill, $parsed );
			$this->finalize( $fallback_result, $conversation, $start_time, $params );
			return $fallback_result;
		}

		// ── S8: Full observability — log raw server response ──
		$server_via = $server_result['debug']['via'] ?? '(unknown)';
		$raw_action = $server_result['action'] ?? '(missing)';
		error_log( self::LOG . ' ── Server Response ──' );
		error_log( self::LOG . '   via=' . $server_via
			. ' | raw_action=' . $raw_action
			. ' | depth=' . ( $server_result['depth'] ?? '?' )
			. ' | tool=' . ( $server_result['tool'] ?? '(none)' )
			. ' | tool_type=' . ( $server_result['tool_type'] ?? '?' )
			. ' | confidence=' . ( $server_result['confidence'] ?? 0 ) );
		if ( ! empty( $server_result['reasoning'] ) ) {
			error_log( self::LOG . '   reasoning=' . mb_substr( $server_result['reasoning'], 0, 200, 'UTF-8' ) );
		}
		if ( ! empty( $server_result['goals'] ) ) {
			error_log( self::LOG . '   goals=' . wp_json_encode( $server_result['goals'], JSON_UNESCAPED_UNICODE ) );
		}
		if ( ! empty( $server_result['knowledge_strategy'] ) ) {
			error_log( self::LOG . '   knowledge_strategy=' . $server_result['knowledge_strategy'] );
		}

		// ── S8: Map legacy server actions to new vocabulary ──
		// If server used old Intelligence Pipeline (not Smart Classifier v2),
		// it may return call_tool, compose_answer, etc. Map them:
		$action = $server_result['action'] ?? 'knowledge';
		if ( $action === 'call_tool' ) {
			$action = 'execution';
			error_log( self::LOG . ' [LEGACY-MAP] call_tool → execution (server chưa chạy Smart Classifier v2)' );
		} elseif ( $action === 'compose_answer' || $action === 'passthrough' ) {
			$action = 'knowledge';
			error_log( self::LOG . ' [LEGACY-MAP] ' . $raw_action . ' → knowledge' );
		}

		// ── Step 4: Execute based on server response (Phase 2 / S8: 3-mode) ──
		$tool_type = $server_result['tool_type'] ?? 'atomic';

		error_log( self::LOG . ' Step 4: action=' . $action
			. ' | depth=' . ( $server_result['depth'] ?? '?' )
			. ' | tool=' . ( $server_result['tool'] ?? '(none)' )
			. ' | confidence=' . ( $server_result['confidence'] ?? 0 ) );

		// ── error: Server gặp sự cố ──
		if ( $action === 'error' ) {
			$result['reply']  = $server_result['reply'] ?: 'Hệ thống đang gặp sự cố, vui lòng thử lại sau.';
			$result['action'] = 'error';
			$result['meta']['server_action'] = 'error';
			$this->save_server_memory_spec( $server_result, $conversation );
			$this->finalize( $result, $conversation, $start_time, $params );
			return $result;
		}

		// ── execution (single goal) OR legacy execute_pipeline ──
		// S3: Composite tool → expand to pipeline steps via Composite Executor
		if ( ( $action === 'execution' || $action === 'execute_pipeline' ) && $tool_type === 'composite' && ! empty( $server_result['tool'] ) ) {
			$composite_plan = $this->expand_composite( $server_result['tool'], $server_result );
			if ( $composite_plan ) {
				return $this->execute_pipeline(
					$composite_plan,
					$server_result,
					$params,
					$conversation,
					$result
				);
			}
			// Fallback: if composite not found, try the pipeline_plan as-is
		}

		if ( ( $action === 'execution' || $action === 'execute_pipeline' ) && ! empty( $server_result['pipeline_plan'] ) ) {
			return $this->execute_pipeline(
				$server_result['pipeline_plan'],
				$server_result,
				$params,
				$conversation,
				$result
			);
		}

		// execution without pipeline_plan → compose answer (server classified as exec but no plan)
		if ( $action === 'execution' ) {
			$result['action'] = 'execution';
			$result['meta']['server_action'] = 'execution';
			$result['meta']['depth'] = $server_result['depth'] ?? 'standard';
			$result['meta']['tool']  = $server_result['tool'] ?? '';
			// Falls through to Step 5 → compose_answer will use execution context
		}

		// ── multi_goal (Phase 2 / S11 — basic handler now, full orchestrator later) ──
		elseif ( $action === 'multi_goal' ) {
			$goals = $server_result['goals'] ?? array();
			error_log( self::LOG . ' multi_goal: ' . count( $goals ) . ' goals' );
			// S11 will add full orchestrator. For now: show plan + execute first goal
			$result['action'] = 'multi_goal';
			$result['meta']['server_action'] = 'multi_goal';
			$result['meta']['goals'] = $goals;
			$result['meta']['depth'] = 'deep';

			// S8 v4: Build pipeline_plan from goals when SmartClassifier didn't provide steps.
			// Always build — infer_block_code() can infer from labels even when tool is empty.
			if ( empty( $server_result['pipeline_plan']['steps'] ) && ! empty( $goals ) ) {
				$built_steps = array();
				foreach ( $goals as $i => $goal ) {
					$tool_id = ! empty( $goal['tool'] ) ? $goal['tool'] : '';
					$label   = ! empty( $goal['goal'] ) ? $goal['goal'] : ( ! empty( $goal['description'] ) ? $goal['description'] : ( 'Step ' . ( $i + 1 ) ) );
					$built_steps[] = array(
						'step'          => $i + 1,
						'tool'          => $tool_id,
						'label'         => $label,
						'input_mapping' => ! empty( $goal['input_hints'] ) ? $goal['input_hints'] : array(),
					);
				}
				$server_result['pipeline_plan'] = array( 'steps' => $built_steps );
				error_log( self::LOG . ' [multi_goal] Built pipeline from ' . count( $goals ) . ' goals → ' . count( $built_steps ) . ' steps' );
			}

			// If there's a pipeline_plan for the first goal, execute it
			if ( ! empty( $server_result['pipeline_plan']['steps'] ) ) {
				return $this->execute_pipeline(
					$server_result['pipeline_plan'],
					$server_result,
					$params,
					$conversation,
					$result
				);
			}
		}

		// ── ask_user ──
		elseif ( $action === 'ask_user' ) {
			$result['reply']  = $server_result['reply'] ?? 'Bạn có thể cho mình biết thêm?';
			$result['action'] = 'ask_user';
			$result['meta']['server_action'] = 'ask_user';

			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id ) {
				$this->conversation_mgr->set_waiting( $conv_id, 'clarify', '' );
			}
		}

		// ── confirm ──
		elseif ( $action === 'confirm' ) {
			$result['reply']  = $server_result['reply'] ?? 'Bạn có muốn tiếp tục không?';
			$result['action'] = 'ask_user';
			$result['meta']['server_action'] = 'confirm';

			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id ) {
				$this->conversation_mgr->set_waiting( $conv_id, 'confirm', '' );
			}
		}

		// ── knowledge (default mode: research/chat/qa) ──
		else {
			$result['action'] = 'knowledge';
			$result['meta']['server_action'] = $action;
			$result['meta']['depth'] = $server_result['depth'] ?? 'standard';
			$result['meta']['knowledge_strategy'] = $server_result['knowledge_strategy'] ?? 'direct_answer';
		}

		// ── Step 5: Save memory spec ──
		$this->save_server_memory_spec( $server_result, $conversation );
		$this->finalize( $result, $conversation, $start_time, $params );

		return $result;
	}

	/* ================================================================
	 *  Pipeline execution — unified for all depths
	 * ================================================================ */

	private function execute_pipeline(
		array $plan,
		array $server_result,
		array $params,
		array $conversation,
		array $result
	): array {
		$steps = $plan['steps'] ?? [];
		$start_time = microtime( true );

		error_log( self::LOG . ' Executing pipeline: ' . count( $steps ) . ' steps' );

		// Use existing Scenario Generator + Step Executor for actual execution
		// Shell delegates to the proven execution layer (not reimplemented)
		if ( class_exists( 'BizCity_Scenario_Generator' ) && class_exists( 'BizCity_Step_Executor' ) ) {
			$message     = $params['message'] ?? '';
			$slash_param = $params['slash_command'] ?? '';
			$nodes       = $this->plan_to_scenario_nodes( $steps, $message, $slash_param );

			$pipeline_id = 'shell_' . wp_generate_uuid4();
			$scenario = array(
				'nodes'    => $nodes,
				'edges'    => $this->build_edges( $nodes ),
				'version'  => '1.0.0',
				'settings' => array(
					'pipeline_id' => $pipeline_id,
					'description' => $server_result['reasoning'] ?? 'Shell pipeline',
					'goal'        => $server_result['tool'] ?? 'workflow',
					'timeout'     => 600,
					'mode'        => 'execute_once',
					'multiple'    => 0,
					'skip'        => 0,
					'cooldown'    => 0,
					'stop'        => 'yes',
				),
				'meta'     => array(
					'source'        => 'shell_engine',
					'user_id'       => intval( $params['user_id'] ?? 0 ),
					'session_id'    => $params['session_id'] ?? '',
					'channel'       => $params['channel'] ?? 'webchat',
					'slash_command' => $params['slash_command'] ?? '',
				),
			);

			// Save as draft task → Step Executor handles async execution
			if ( method_exists( 'BizCity_Scenario_Generator', 'save_draft_task' ) ) {
				$task_id = BizCity_Scenario_Generator::save_draft_task(
					$scenario,
					$params['message'] ?? '',
					[
						'user_id'       => intval( $params['user_id'] ?? 0 ),
						'session_id'    => $params['session_id'] ?? '',
						'channel'       => $params['channel'] ?? 'webchat',
						'slash_command' => $params['slash_command'] ?? '',
					]
				);

				if ( $task_id ) {
					// Build memory spec for the pipeline
					BizCity_Memory_Spec::build_from_pipeline( $task_id, $scenario, [
						'user_id'    => $params['user_id'] ?? 0,
						'session_id' => $params['session_id'] ?? '',
						'channel'    => $params['channel'] ?? 'webchat',
					] );

					// Build rich reply with step plan + workflow link (MediaPreview in Message.jsx intercepts builder links)
					$plan_text = "📋 **Kế hoạch thực hiện (" . count( $steps ) . " bước):**\n\n";
					foreach ( $steps as $i => $step ) {
						$step_label = ! empty( $step['label'] ) ? $step['label'] : ( ! empty( $step['tool'] ) ? $step['tool'] : 'Bước ' . ( $i + 1 ) );
						$plan_text .= ( $i + 1 ) . '. 🔧 ' . $step_label . "\n";
					}
					$builder_url = admin_url( 'admin.php?page=bizcity-workspace&tab=builder&task_id=' . intval( $task_id ) . '&bizcity_iframe=1' );
					$plan_text .= "\n[📋 Workflow #" . intval( $task_id ) . "](" . $builder_url . ")";

					$result['reply']  = $plan_text;
					$result['action'] = 'execute_pipeline';
					$result['meta']['task_id']     = $task_id;
					$result['meta']['step_count']  = count( $steps );
					$result['meta']['pipeline_id'] = $pipeline_id;
				}
			}
		} else {
			// Minimal execution without Scenario Generator
			$result['reply']  = 'Pipeline plan received: ' . count( $steps ) . ' steps. Execution engine not available.';
			$result['action'] = 'passthrough';
		}

		$this->save_server_memory_spec( $server_result, $conversation );
		$this->finalize( $result, $conversation, $start_time, $params );

		return $result;
	}

	/**
	 * Convert Data Contract pipeline_plan steps → Scenario Generator nodes.
	 *
	 * Phase 1.12: Pipeline Decomposition — chia nhỏ việc.
	 * Node order: Trigger → it_call_skill → it_todos_planner → Action nodes → Summary
	 *
	 * `it_call_skill` (node #2) runs BEFORE planner to resolve skill from:
	 *   - Slash command (regex extract or from params)
	 *   - Tool name → skill_tool_map lookup
	 *
	 * `it_todos_planner` (node #3) receives skill context from {{node#2.skill_content}}.
	 *
	 * @param array  $steps   Pipeline plan steps from SmartClassifier.
	 * @param string $message Original user message (for trigger node).
	 * @return array Nodes in WAIC format.
	 */
	private function plan_to_scenario_nodes( array $steps, $message = '', $slash_param = '' ): array {
		$nodes  = array();
		$x_pos  = 350;

		// Node 1: Trigger (bc_instant_run)
		$nodes[] = array(
			'id'       => '1',
			'type'     => 'trigger',
			'position' => array( 'x' => $x_pos, 'y' => 200 ),
			'data'     => array(
				'type'            => 'trigger',
				'category'        => 'bc',
				'code'            => 'bc_instant_run',
				'label'           => '⚡ Chạy ngay',
				'settings'        => array( 'default_text' => $message ),
				'executionStatus' => null,
				'dragged'         => true,
			),
		);
		$x_pos += 210;

		// Node 2: Skill Resolution (it_call_skill) — Phase 1.12
		// Determine slash: from params (priority) or regex from message
		$slash_cmd = $slash_param;
		if ( empty( $slash_cmd ) && ! empty( $message ) && preg_match( '/^\/([a-zA-Z0-9_]+)/', trim( $message ), $m ) ) {
			$slash_cmd = $m[1];
		}

		$nodes[] = array(
			'id'       => '2',
			'type'     => 'action',
			'position' => array( 'x' => $x_pos, 'y' => 200 ),
			'data'     => array(
				'type'            => 'action',
				'category'        => 'it',
				'code'            => 'it_call_skill',
				'label'           => '🎯 Tìm Skill',
				'settings'        => array(
					'skill_select'           => '',
					'slash_command_override'  => $slash_cmd,
					'fallback_mode'          => 'continue',
				),
				'executionStatus' => null,
				'dragged'         => true,
			),
		);
		$x_pos += 210;

		// Node 3: Planner (it_todos_planner) — receives skill from node#2
		$plan_steps = array();
		$content_node_id = null;
		$research_node_id = null;
		$node_counter = 4; // start action nodes at 4 (trigger=1, skill=2, planner=3)

		// Pre-scan to build planner steps and identify content/research nodes
		foreach ( $steps as $step ) {
			if ( ! empty( $step['skip'] ) ) {
				continue;
			}
			$tool_id = ! empty( $step['tool'] ) ? $step['tool'] : '';
			$label   = ! empty( $step['label'] ) ? $step['label'] : ( $tool_id ?: 'Step' );
			$block   = $this->infer_block_code( $tool_id, $label );

			if ( $block === 'it_call_research' && $research_node_id === null ) {
				$research_node_id = (string) $node_counter;
			}
			if ( $block === 'it_call_content' && $content_node_id === null ) {
				$content_node_id = (string) $node_counter;
			}

			$plan_steps[] = array(
				'tool_name' => $block,
				'label'     => $label,
				'node_id'   => (string) $node_counter,
			);
			$node_counter++;
		}

		$nodes[] = array(
			'id'       => '3',
			'type'     => 'action',
			'position' => array( 'x' => $x_pos, 'y' => 200 ),
			'data'     => array(
				'type'            => 'action',
				'category'        => 'it',
				'code'            => 'it_todos_planner',
				'label'           => '📋 Kế hoạch',
				'settings'        => array(
					'steps_json'     => wp_json_encode( $plan_steps ),
					'pipeline_label' => 'Shell Pipeline',
				),
				'executionStatus' => null,
				'dragged'         => true,
			),
		);
		$x_pos += 210;

		// Node 4+: Action nodes
		$node_id = 4;
		foreach ( $steps as $step ) {
			if ( ! empty( $step['skip'] ) ) {
				continue;
			}

			$tool_id  = ! empty( $step['tool'] ) ? $step['tool'] : '';
			$label    = ! empty( $step['label'] ) ? $step['label'] : ( $tool_id ?: 'Step' );
			$block    = $this->infer_block_code( $tool_id, $label );
			$category = ( strpos( $block, 'it_' ) === 0 ) ? 'it' : 'bc';

			// Build settings based on block type
			$settings = array();
			if ( $block === 'it_call_content' ) {
				$settings['content_tool']      = $tool_id ?: 'generate_blog_content';
				$settings['require_confirm']   = '0';
				$settings['max_refine_rounds'] = '2';
				// Chain from trigger + skill (node#2) + research (if available)
				$input = array(
					'topic'         => '{{node#1.text}}',
					'skill_content' => '{{node#2.skill_content}}',
				);
				if ( $research_node_id !== null ) {
					$input['sources'] = '{{node#' . $research_node_id . '.research_summary}}';
				}
				$settings['input_json'] = wp_json_encode( $input );
			} elseif ( $block === 'it_call_research' ) {
				$settings['research_query'] = '{{node#1.text}}';
			} elseif ( $block === 'it_call_tool' ) {
				$settings['tool_id'] = $tool_id;
				// Chain from content output if available
				$input = array();
				if ( $content_node_id !== null ) {
					$input['content'] = '{{node#' . $content_node_id . '.content}}';
					$input['title']   = '{{node#' . $content_node_id . '.title}}';
				}
				if ( ! empty( $step['input_mapping'] ) ) {
					$input = array_merge( $input, $step['input_mapping'] );
				}
				if ( ! empty( $input ) ) {
					$settings['input_json'] = wp_json_encode( $input );
				}
			}

			if ( empty( $tool_id ) && $block !== 'it_call_research' ) {
				error_log( self::LOG . ' [Pipeline] Warning: step has empty tool_id, block=' . $block . ', label=' . $label );
			}

			$nodes[] = array(
				'id'       => (string) $node_id,
				'type'     => 'action',
				'position' => array( 'x' => $x_pos, 'y' => 200 ),
				'data'     => array(
					'type'            => 'action',
					'category'        => $category,
					'code'            => $block,
					'label'           => $label,
					'settings'        => $settings,
					'executionStatus' => null,
					'dragged'         => true,
				),
			);

			$x_pos += 210;
			$node_id++;
		}

		// Final node: Summary verifier
		$nodes[] = array(
			'id'       => (string) $node_id,
			'type'     => 'action',
			'position' => array( 'x' => $x_pos, 'y' => 200 ),
			'data'     => array(
				'type'            => 'action',
				'category'        => 'it',
				'code'            => 'it_summary_verifier',
				'label'           => '✅ Tổng kết Pipeline',
				'settings'        => array(
					'pipeline_label' => 'Shell Pipeline',
				),
				'executionStatus' => null,
				'dragged'         => true,
			),
		);

		return $nodes;
	}

	/**
	 * Infer WAIC block code from tool_id and step label.
	 *
	 * Maps SmartClassifier tool names to proper WAIC execution blocks:
	 *   - Content generation tools → it_call_content
	 *   - Research/search intent → it_call_research
	 *   - Distribution/action tools → it_call_tool (generic)
	 *
	 * @param string $tool_id Tool name from SmartClassifier.
	 * @param string $label   Step label for keyword matching.
	 * @return string Block code.
	 */
	private function infer_block_code( $tool_id, $label ): string {
		// Direct block code references
		if ( strpos( $tool_id, 'it_call_' ) === 0 || strpos( $tool_id, 'bc_' ) === 0 ) {
			return $tool_id;
		}

		// Content generation tools → it_call_content
		$content_tools = array(
			'generate_blog_content', 'generate_seo_content', 'rewrite_content',
			'generate_fb_post', 'generate_ad_copy', 'generate_email_sales',
			'generate_email_reply', 'generate_product_desc', 'generate_landing_page',
			'generate_faq', 'generate_threads_post', 'generate_ig_caption',
			'generate_youtube_desc', 'generate_tiktok_script', 'generate_linkedin_post',
			'generate_zalo_message', 'generate_video_script', 'generate_shorts_script',
			'generate_podcast_outline', 'generate_presentation', 'generate_support_reply',
			'generate_chatbot_response', 'generate_announcement', 'generate_comparison',
			'generate_testimonial_request', 'generate_campaign_brief', 'generate_proposal',
			'generate_report_content', 'generate_policy', 'generate_sop',
			'generate_job_description', 'generate_meeting_notes', 'generate_email_quote',
			'generate_email_contract', 'generate_email_announce', 'generate_email_newsletter',
			'generate_email_followup', 'write_article',
		);
		if ( in_array( $tool_id, $content_tools, true ) ) {
			return 'it_call_content';
		}
		// bizcity_atomic_* prefix → content
		if ( strpos( $tool_id, 'generate_' ) === 0 ) {
			return 'it_call_content';
		}

		// Research/search — by tool_id or label keywords
		$label_lower = mb_strtolower( $label );
		if ( empty( $tool_id ) && preg_match( '/nghiên cứu|research|tìm kiếm|search|tra cứu|thu thập|tìm tài liệu/iu', $label_lower ) ) {
			return 'it_call_research';
		}

		// Content creation — by label keywords when tool is empty
		if ( empty( $tool_id ) && preg_match( '/viết|tạo nội dung|write|generate|soạn|bài viết|content/iu', $label_lower ) ) {
			return 'it_call_content';
		}

		// Distribution/posting — by label keywords when tool is empty
		if ( empty( $tool_id ) && preg_match( '/gửi|đăng|post|publish|chia sẻ|share|phân phối|distribute/iu', $label_lower ) ) {
			return 'bc_send_adminchat';
		}

		// Distribution/posting tools → it_call_tool
		if ( ! empty( $tool_id ) ) {
			return 'it_call_tool';
		}

		// Fallback: generic tool call
		return 'it_call_tool';
	}

	/**
	 * Build sequential edges in WAIC format from nodes.
	 */
	private function build_edges( array $nodes ): array {
		$edges = array();
		for ( $i = 0; $i < count( $nodes ) - 1; $i++ ) {
			$src = $nodes[ $i ]['id'];
			$tgt = $nodes[ $i + 1 ]['id'];
			$edges[] = array(
				'id'           => 'e' . $src . '-' . $tgt,
				'source'       => $src,
				'target'       => $tgt,
				'sourceHandle' => 'output-right',
				'targetHandle' => 'input-left',
				'type'         => 'default',
			);
		}
		return $edges;
	}

	/* ================================================================
	 *  Pipeline trigger / resume from pre-rules
	 * ================================================================ */

	private function handle_pipeline_trigger( array $pre_result, array $params, array $conversation, array $result ): array {
		$skill  = $pre_result['result']['skill'] ?? [];
		$parsed = $pre_result['result']['parsed'] ?? [];

		// Fire the same action that Skill Context uses for C/D archetypes
		$skill['archetype']       = 'D';
		$skill['body_steps']      = $parsed['steps'] ?? [];
		$skill['body_tool_refs']  = $parsed['tool_refs'] ?? [];
		$skill['body_guardrails'] = $parsed['guardrails'] ?? [];

		$args = [
			'mode'          => $params['mode'] ?? 'execution',
			'message'       => $params['message'] ?? '',
			'session_id'    => $params['session_id'] ?? '',
			'channel'       => $params['channel'] ?? 'webchat',
			'engine_result' => [],
		];

		do_action( 'bizcity_skill_trigger_pipeline', $skill, $args );

		$result['reply']  = '✅ Skill "' . ( $skill['frontmatter']['title'] ?? 'unknown' ) . '" pipeline triggered.';
		$result['action'] = 'trigger_pipeline';
		$result['meta']   = array_merge( $result['meta'], $pre_result['result']['meta'] ?? [] );
		$result['goal']   = 'skill:' . ltrim( $skill['frontmatter']['name'] ?? '', '/' );

		// Set conversation goal
		$conv_id = $conversation['conversation_id'] ?? '';
		if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
			$this->conversation_mgr->set_goal( $conv_id, $result['goal'], $skill['frontmatter']['title'] ?? '' );
		}

		$this->finalize( $result, $conversation, microtime( true ), $params );
		return $result;
	}

	private function handle_pipeline_resume( array $pre_result, array $params, array $conversation, array $result ): array {
		$user_id = intval( $params['user_id'] ?? 0 );

		// Find active pipeline and resume
		if ( class_exists( 'BizCity_Pipeline_Resume' ) ) {
			$resume = BizCity_Pipeline_Resume::instance();
			if ( method_exists( $resume, 'resume_current' ) ) {
				$resume_result = $resume->resume_current( $user_id, $params['message'] ?? '' );
				if ( $resume_result ) {
					$result['reply']  = $resume_result['reply'] ?? 'Đang tiếp tục pipeline...';
					$result['action'] = 'resume_pipeline';
					$result['meta']   = array_merge( $result['meta'], $pre_result['result']['meta'] ?? [] );
					$this->finalize( $result, $conversation, microtime( true ), $params );
					return $result;
				}
			}
		}

		// Fallback if no pipeline to resume
		$result['reply']  = 'Không tìm thấy pipeline để tiếp tục.';
		$result['action'] = 'chat';
		$this->finalize( $result, $conversation, microtime( true ), $params );
		return $result;
	}

	/**
	 * S3: Expand composite tool into pipeline plan.
	 *
	 * Looks up the composite definition in Tool Registry Map,
	 * then converts it to a pipeline_plan using Composite Executor.
	 *
	 * @param string $tool_id       Composite tool ID from server.
	 * @param array  $server_result Full server response.
	 * @return array|null Pipeline plan or null if not a composite.
	 */
	private function expand_composite( string $tool_id, array $server_result ): ?array {
		if ( ! class_exists( 'BizCity_Tool_Registry_Map' ) || ! class_exists( 'BizCity_Composite_Executor' ) ) {
			return null;
		}

		$map       = BizCity_Tool_Registry_Map::instance();
		$composite = $map->match_composite( $tool_id );

		if ( ! $composite ) {
			error_log( self::LOG . " Composite '{$tool_id}' not found in registry." );
			return null;
		}

		$executor = BizCity_Composite_Executor::instance();
		$steps    = $executor->to_pipeline_steps( $composite );

		if ( empty( $steps ) ) {
			return null;
		}

		error_log( self::LOG . " Expanded composite '{$tool_id}' into " . count( $steps ) . ' steps.' );

		return [ 'steps' => $steps, 'source' => 'composite:' . $tool_id ];
	}

	/* ================================================================
	 *  Server communication
	 * ================================================================ */

	/**
	 * Call the Smart Classifier server endpoint.
	 *
	 * @param array $payload Data Contract v1 payload.
	 * @return array|null Server response or null on failure.
	 */
	private function call_server( array $payload ): ?array {
		// Get server URL from options (same pattern as existing Intelligence Engine)
		$server_url = get_option( 'bizcity_llm_router_url', '' );

		if ( empty( $server_url ) ) {
			// Try local fallback (gateway on same server)
			if ( function_exists( 'bizcity_llm_is_ready' ) && bizcity_llm_is_ready() ) {
				return $this->call_local_intelligence( $payload );
			}
			return null;
		}

		$endpoint = rtrim( $server_url, '/' ) . '/wp-json/bizcity/v1/ai/resolve';

		$response = wp_remote_post( $endpoint, [
			'timeout' => self::SERVER_TIMEOUT,
			'headers' => [
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->get_api_token(),
			],
			'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );

		if ( is_wp_error( $response ) ) {
			error_log( self::LOG . ' Server call failed: ' . $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( self::LOG . " Server returned HTTP {$code}" );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			error_log( self::LOG . ' Server returned invalid JSON: ' . mb_substr( $body, 0, 300, 'UTF-8' ) );
			return null;
		}

		$engine_result = $data['engine_result'] ?? $data;

		// S8: Log which server pathway was used
		$via = $data['debug']['via'] ?? '(not_specified)';
		error_log( self::LOG . ' call_server OK: via=' . $via
			. ' | action=' . ( $engine_result['action'] ?? '?' )
			. ' | keys=' . implode( ',', array_keys( $engine_result ) ) );

		// Propagate debug.via into engine_result for Step 4 observability
		if ( ! isset( $engine_result['debug'] ) ) {
			$engine_result['debug'] = [];
		}
		if ( isset( $data['debug']['via'] ) ) {
			$engine_result['debug']['via'] = $data['debug']['via'];
		}

		return $engine_result;
	}

	/**
	 * Call local Intelligence — Smart Classifier v2 first, old pipeline fallback.
	 */
	private function call_local_intelligence( array $payload ): ?array {
		// ── S8: Prefer Smart Classifier v2 (same logic as REST handle_resolve) ──
		if ( class_exists( 'BizCity_Smart_Classifier' ) ) {
			error_log( self::LOG . ' call_local: Smart Classifier v2 available → calling classify()' );
			$sc_result = BizCity_Smart_Classifier::classify( $payload );

			if ( ! isset( $sc_result['debug'] ) ) {
				$sc_result['debug'] = [];
			}
			$sc_result['debug']['via'] = 'local_smart_classifier_v2';
			error_log( self::LOG . ' call_local: via=local_smart_classifier_v2 | action=' . ( $sc_result['action'] ?? '?' ) );

			return $sc_result;
		}

		// ── Fallback: old Intelligence Engine pipeline ──
		if ( ! class_exists( 'BizCity_Intelligence_Engine' ) ) {
			error_log( self::LOG . ' call_local: No Smart Classifier, no Intelligence Engine → null' );
			return null;
		}

		error_log( self::LOG . ' call_local: Smart Classifier NOT available, falling back to old pipeline' );

		$context = [
			'channel'   => $payload['channel'] ?? 'webchat',
			'user_id'   => $payload['user_id'] ?? 0,
			'client_engine_result' => [
				'meta' => [
					'session_id'    => $payload['session_id'] ?? '',
					'skill_context' => $payload['skill_context'] ?? null,
					'memory_spec'   => $payload['memory_spec'] ?? null,
				],
			],
		];

		$result = BizCity_Intelligence_Engine::resolve( $payload['message'] ?? '', $context );

		$engine_result = $result['engine_result'] ?? $result;

		if ( ! isset( $engine_result['debug'] ) ) {
			$engine_result['debug'] = [];
		}
		$engine_result['debug']['via'] = 'local_intelligence_engine';
		error_log( self::LOG . ' call_local: via=local_intelligence_engine | action=' . ( $engine_result['action'] ?? '?' ) );

		return $engine_result;
	}

	/**
	 * Get API token for server authentication.
	 */
	private function get_api_token(): string {
		return get_option( 'bizcity_llm_api_token', '' );
	}

	/* ================================================================
	 *  Local fallback
	 * ================================================================ */

	private function handle_local_fallback(
		string $message,
		array $params,
		array $conversation,
		array $result,
		array $skill = [],
		array $parsed = []
	): array {
		// Tier 1: If we have a matched skill with guided/explicit strategy → trigger pipeline locally
		$strategy = $parsed['strategy'] ?? 'simple';
		if ( ! empty( $skill ) && in_array( $strategy, [ 'guided', 'explicit' ], true ) ) {
			return $this->handle_pipeline_trigger(
				[
					'result' => [
						'skill'  => $skill,
						'parsed' => $parsed,
						'meta'   => [ 'pre_rule' => 'LOCAL_FALLBACK_SKILL', 'llm_calls' => 0 ],
					],
				],
				$params,
				$conversation,
				$result
			);
		}

		// Tier 2: Local classification via existing Mode Classifier (if available)
		if ( class_exists( 'BizCity_Local_Fallback' ) ) {
			$fallback = BizCity_Local_Fallback::instance();
			return $fallback->resolve( $message, $params, $conversation, $result );
		}

		// Tier 3: Passthrough to Chat Gateway (compose_answer)
		$result['action'] = 'passthrough';
		$result['meta']['fallback'] = 'tier3_passthrough';
		$result['meta']['llm_calls'] = 0;

		return $result;
	}

	/* ================================================================
	 *  Memory save + finalize
	 * ================================================================ */

	/**
	 * Save server-returned memory spec to session.
	 */
	private function save_server_memory_spec( array $server_result, array $conversation ): void {
		$server_spec = $server_result['memory_spec'] ?? null;
		if ( empty( $server_spec ) || ! is_array( $server_spec ) ) {
			return;
		}

		$conv_id = $conversation['conversation_id'] ?? '';
		if ( empty( $conv_id ) ) {
			return;
		}

		// Load existing session spec
		$existing = BizCity_Memory_Spec::load_session( $conversation );

		if ( $existing ) {
			$merged = BizCity_Memory_Spec::merge_from_server( $existing, $server_spec );
		} else {
			$session_id = $conversation['session_id'] ?? '';
			$merged = BizCity_Memory_Spec::build_session_from_server( $server_spec, $session_id );
		}

		BizCity_Memory_Spec::save_session( $conv_id, $merged );
	}

	/**
	 * Finalize: increment turn, log timing.
	 */
	private function finalize( array &$result, array $conversation, float $start_time, array $params = [] ): void {
		$conv_id = $conversation['conversation_id'] ?? '';
		if ( $conv_id ) {
			$this->conversation_mgr->increment_turn( $conv_id );
		}

		$elapsed = round( ( microtime( true ) - $start_time ) * 1000 );
		$result['meta']['engine']     = 'shell';
		$result['meta']['elapsed_ms'] = $elapsed;

		error_log( self::LOG . " Done: action={$result['action']}, elapsed={$elapsed}ms" );

		// Fire the same hook as legacy engine so downstream listeners work
		$hook_params = array_merge( $params, [
			'conversation_id' => $conv_id,
		] );
		do_action( 'bizcity_intent_processed', $result, $hook_params );
	}
}
