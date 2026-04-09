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

			// Handle tool execution trigger (from @tool mention pre-rule)
			if ( $action === 'trigger_tool_execution' ) {
				$tool_name_tr = $pre_result['result']['tool_name'] ?? '';
				if ( $tool_name_tr && $conv_id ) {
					$this->conversation_mgr->set_goal( $conv_id, 'tool:' . $tool_name_tr, $tool_name_tr );
				}
				// Inject tool_name into params so context collector includes it
				$params['tool_name'] = $tool_name_tr;
				error_log( self::LOG . " Tool trigger: @{$tool_name_tr} → server resolve with tool context" );
				return $this->step3_server_resolve( $message, $params, $conversation, $result, [], [], $start_time );
			}

			// Direct response (help, cancel, confirm_reject, ask_user from slot gathering, etc.)
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

		// ── S8: Map legacy server actions to 2-mode vocabulary ──
		// v3: single|multi|ask_user|confirm|error
		// Legacy compat: knowledge → multi, execution → single/multi, multi_goal → multi
		$action = $server_result['action'] ?? 'multi';
		$mode   = $server_result['mode'] ?? '';

		if ( $action === 'call_tool' ) {
			$action = 'single';
			$mode   = 'single';
			error_log( self::LOG . ' [LEGACY-MAP] call_tool → single' );
		} elseif ( $action === 'compose_answer' || $action === 'passthrough' ) {
			$action = 'multi';
			$mode   = 'multi';
			error_log( self::LOG . ' [LEGACY-MAP] ' . $raw_action . ' → multi' );
		} elseif ( $action === 'knowledge' ) {
			$action = 'multi';
			$mode   = 'multi';
			error_log( self::LOG . ' [LEGACY-MAP] knowledge → multi' );
		} elseif ( $action === 'execution' || $action === 'execute_pipeline' ) {
			// Legacy: check if server already set mode
			if ( $mode !== 'single' ) {
				$action = 'multi';
				$mode   = 'multi';
			}
			error_log( self::LOG . ' [LEGACY-MAP] ' . $raw_action . ' → ' . $action );
		} elseif ( $action === 'multi_goal' ) {
			$action = 'multi';
			$mode   = 'multi';
			error_log( self::LOG . ' [LEGACY-MAP] multi_goal → multi' );
		}

		// Ensure mode is set
		if ( empty( $mode ) ) {
			$mode = ( $action === 'single' ) ? 'single' : 'multi';
		}

		// ══════════════════════════════════════════════════════════════
		//  Step 3.5: Phase 1.15 Memory Spec — CREATE / LOAD
		//
		//  Nguyên tắc "Não chỉ điều phối, ngón tay làm việc":
		//  - Shell (Não) CHỈ tạo/load Memory Spec (= mở notebook)
		//  - Shell KHÔNG resolve skill (đã có pre-rules + Ngón 1)
		//  - Shell KHÔNG update memory sau tool (Ngón 4/7/8 sẽ làm)
		//  - Kết quả: memory_id inject vào $result['meta'] →
		//    downstream handlers + it_call_* blocks đọc từ đây
		//
		//  Lifecycle: CREATE (here) → UPDATE (blocks) → FINALIZE (finalize())
		// ══════════════════════════════════════════════════════════════
		$memory_spec_row = null;

		if ( class_exists( 'BizCity_Memory_Manager' ) ) {
			$_conv_id  = $conversation['conversation_id'] ?? '';
			$_user_id  = (int) ( $params['user_id'] ?? 0 );
			$_char_id  = (int) ( $params['character_id'] ?? 0 );
			$_sess_id  = isset( $conversation['session_id'] ) ? $conversation['session_id'] : ( $params['session_id'] ?? '' );
			$_proj_id  = isset( $conversation['project_id'] ) ? $conversation['project_id'] : '';

			// Goal from server reasoning or build from mode + tool
			$_goal = isset( $server_result['reasoning'] ) ? $server_result['reasoning'] : '';
			if ( empty( $_goal ) ) {
				$_goal = ucfirst( $mode ) . ': ' . ( $server_result['tool'] ?? 'classify' );
			}

			// Attach skill info if available from pre-rules (inject_skill / continue_skill)
			$_skill_id  = ! empty( $skill['id'] ) ? (int) $skill['id'] : null;
			$_skill_key = ! empty( $skill['skill_key'] ) ? $skill['skill_key'] : ( ! empty( $skill['key'] ) ? $skill['key'] : null );

			$memory_spec_row = BizCity_Memory_Manager::instance()->load_or_create( array(
				'user_id'         => $_user_id,
				'character_id'    => $_char_id,
				'session_id'      => $_sess_id,
				'project_id'      => $_proj_id,
				'goal'            => mb_substr( $_goal, 0, 200, 'UTF-8' ),
				'conversation_id' => $_conv_id,
				'skill_id'        => $_skill_id,
				'skill_key'       => $_skill_key,
			) );

			if ( $memory_spec_row ) {
				$_mem_id   = isset( $memory_spec_row['id'] ) ? $memory_spec_row['id'] : 0;
				$_mem_key  = isset( $memory_spec_row['memory_key'] ) ? $memory_spec_row['memory_key'] : '';
				$_mem_link = admin_url( 'admin.php?page=bizcity-memory&id=' . $_mem_id );

				// Inject into result meta → downstream (execute_*, blocks, finalize) read from here
				$result['meta']['phase115_memory_id']   = (int) $_mem_id;
				$result['meta']['phase115_memory_key']   = $_mem_key;
				$result['meta']['phase115_memory_link']  = $_mem_link;

				error_log( self::LOG . ' [Phase1.15] ★ Memory Spec ready: id=' . $_mem_id . ' key=' . $_mem_key );
				error_log( self::LOG . ' [Phase1.15]   → Admin: ' . $_mem_link );
			} else {
				error_log( self::LOG . ' [Phase1.15] ⚠ FAILED to create memory spec for conv=' . $_conv_id );
			}
		}

		// ── Step 4: Execute based on 2-mode dispatch ──
		$tool_type = $server_result['tool_type'] ?? 'atomic';

		error_log( self::LOG . ' Step 4: mode=' . $mode
			. ' | action=' . $action
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

		// ══════════════════════════════════════════════════
		//  SINGLE MODE — Atomic tool, execute without pipeline
		// ══════════════════════════════════════════════════
		if ( $mode === 'single' && ! empty( $server_result['tool'] ) ) {
			return $this->handle_single_mode( $server_result, $params, $conversation, $result, $start_time );
		}

		// ══════════════════════════════════════════════════
		//  MULTI MODE — Pipeline execution (adaptive depth)
		// ══════════════════════════════════════════════════

		// ── ask_user (pause state — missing info) ──
		if ( $action === 'ask_user' ) {
			$result['reply']  = $server_result['reply'] ?? 'Bạn có thể cho mình biết thêm?';
			$result['action'] = 'ask_user';
			$result['meta']['server_action'] = 'ask_user';
			$result['meta']['mode'] = $mode;

			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id ) {
				$server_tool = $server_result['tool'] ?? '';

				// Safety net: if classifier returned tool="" but reasoning/entities hint at a tool domain,
				// try to infer the tool from the message keywords
				if ( empty( $server_tool ) ) {
					$server_tool = $this->infer_tool_from_context( $message, $server_result );
					if ( $server_tool ) {
						error_log( self::LOG . " ask_user: inferred tool \"{$server_tool}\" from context (classifier returned empty)" );
					}
				}

				// ALWAYS save goal before setting WAITING_USER — goal must never be NULL in WAITING state
				if ( $server_tool ) {
					$this->conversation_mgr->set_goal( $conv_id, 'tool:' . $server_tool, $server_tool );
				} else {
					$this->conversation_mgr->set_goal( $conv_id, 'clarify:pending', 'Đang chờ bổ sung thông tin' );
				}

				// Save entities as slots if available (e.g., date, time from "đặt lịch ngày mai")
				$entities = $server_result['entities'] ?? [];
				if ( ! empty( $entities ) && is_array( $entities ) ) {
					$this->conversation_mgr->update_slots( $conv_id, $entities );
				}

				$this->conversation_mgr->set_waiting( $conv_id, 'clarify', 'user_input' );
			}
		}

		// ── confirm (pause state — dangerous action) ──
		elseif ( $action === 'confirm' ) {
			$result['reply']  = $server_result['reply'] ?? 'Bạn có muốn tiếp tục không?';
			$result['action'] = 'ask_user';
			$result['meta']['server_action'] = 'confirm';
			$result['meta']['mode'] = $mode;

			$conv_id = $conversation['conversation_id'] ?? '';
			if ( $conv_id ) {
				$server_tool = $server_result['tool'] ?? '';

				// Safety net: infer tool if empty
				if ( empty( $server_tool ) ) {
					$server_tool = $this->infer_tool_from_context( $message, $server_result );
				}

				// ALWAYS save goal before setting WAITING_USER
				if ( $server_tool ) {
					$this->conversation_mgr->set_goal( $conv_id, 'tool:' . $server_tool, $server_tool );
				} else {
					$this->conversation_mgr->set_goal( $conv_id, 'confirm:pending', 'Đang chờ xác nhận' );
				}

				$this->conversation_mgr->set_waiting( $conv_id, 'confirm', '' );
			}
		}

		// ── multi: composite tool → expand to pipeline ──
		elseif ( $tool_type === 'composite' && ! empty( $server_result['tool'] ) ) {
			$composite_plan = $this->expand_composite( $server_result['tool'], $server_result );
			if ( $composite_plan ) {
				return $this->execute_pipeline(
					$composite_plan,
					$server_result,
					$params,
					$conversation,
					$result,
					$start_time
				);
			}
		}

		// ── multi: has pipeline_plan → execute ──
		elseif ( ! empty( $server_result['pipeline_plan']['steps'] ) ) {
			return $this->execute_pipeline(
				$server_result['pipeline_plan'],
				$server_result,
				$params,
				$conversation,
				$result,
				$start_time
			);
		}

		// ── multi: goals array → build pipeline from goals ──
		elseif ( ! empty( $server_result['goals'] ) ) {
			$goals = $server_result['goals'];
			error_log( self::LOG . ' multi: ' . count( $goals ) . ' goals → building pipeline' );

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
			error_log( self::LOG . ' [multi] Built pipeline from ' . count( $goals ) . ' goals → ' . count( $built_steps ) . ' steps' );

			return $this->execute_pipeline(
				$server_result['pipeline_plan'],
				$server_result,
				$params,
				$conversation,
				$result,
				$start_time
			);
		}

		// ── multi: no pipeline, no goals → knowledge/default (compose answer) ──
		else {
			$result['action'] = 'knowledge';
			$result['meta']['server_action'] = $action;
			$result['meta']['mode'] = 'multi';
			$result['meta']['depth'] = $server_result['depth'] ?? 'standard';
			$result['meta']['knowledge_strategy'] = $server_result['knowledge_strategy'] ?? 'direct_answer';
		}

		// ── Step 5: Save memory spec ──
		$this->save_server_memory_spec( $server_result, $conversation );
		$this->finalize( $result, $conversation, $start_time, $params );

		return $result;
	}

	/* ================================================================
	 *  SINGLE MODE — Atomic tool execution without pipeline
	 *  Owns full lifecycle: slot gather → confirm → execute → return
	 * ================================================================ */

	/**
	 * Handle single mode: atomic tool that needs no content generation.
	 *
	 * Flow:
	 *   1. Check if slots are sufficient
	 *   2. If missing → ask_user (set waiting=clarify, persist goal=tool:xxx)
	 *   3. If confirm_required → show confirm (set waiting=confirm, persist goal)
	 *   4. Execute tool callback directly → return structured result
	 *
	 * No pipeline, no content generation, no research, no reflection.
	 *
	 * @param array $server_result Server classifier output.
	 * @param array $params        Original request params.
	 * @param array $conversation  Conversation state.
	 * @param array $result        Result template.
	 * @param float $start_time    Process start time.
	 * @return array Final result.
	 */
	private function handle_single_mode( array $server_result, array $params, array $conversation, array $result, float $start_time ): array {
		$tool_name = $server_result['tool'] ?? '';
		$entities  = $server_result['entities'] ?? [];
		$conv_id   = $conversation['conversation_id'] ?? '';

		error_log( self::LOG . " [SINGLE] tool={$tool_name}, entities=" . wp_json_encode( $entities, JSON_UNESCAPED_UNICODE ) );

		$result['meta']['mode']          = 'single';
		$result['meta']['server_action'] = $server_result['action'] ?? 'single';

		// Persist goal so resume works after pause states
		if ( $conv_id && $tool_name ) {
			$current_goal = $conversation['goal'] ?? '';
			if ( empty( $current_goal ) || strpos( $current_goal, 'tool:' ) !== 0 ) {
				$goal_set = $this->conversation_mgr->set_goal( $conv_id, 'tool:' . $tool_name, $tool_name );
				if ( ! $goal_set ) {
					error_log( self::LOG . " [SINGLE] CRITICAL: set_goal() failed for conv_id={$conv_id} tool={$tool_name}" );
				}
			}
		}

		// Store entities as conversation slots
		if ( $conv_id && ! empty( $entities ) ) {
			$existing_slots = $conversation['slots'] ?? [];
			$merged_slots = array_merge( $existing_slots, $entities );
			$this->conversation_mgr->update_slots( $conv_id, $merged_slots );
		}

		// Check if tool requires confirmation and server said confirm
		$action = $server_result['action'] ?? 'single';
		if ( $action === 'confirm' ) {
			$result['reply']  = $server_result['reply'] ?? 'Bạn có muốn tiếp tục không?';
			$result['action'] = 'ask_user';
			$result['meta']['server_action'] = 'confirm';

			if ( $conv_id ) {
				$this->conversation_mgr->set_waiting( $conv_id, 'confirm', '' );
			}

			$this->save_server_memory_spec( $server_result, $conversation );
			$this->finalize( $result, $conversation, $start_time, $params );
			return $result;
		}

		// Check if tool needs more info
		if ( $action === 'ask_user' ) {
			$result['reply']  = $server_result['reply'] ?? 'Bạn có thể cho mình biết thêm?';
			$result['action'] = 'ask_user';
			$result['meta']['server_action'] = 'ask_user';

			if ( $conv_id ) {
				$this->conversation_mgr->set_waiting( $conv_id, 'clarify', 'user_input' );
				// Store _single_tool in slots so resume can find tool even if goal is NULL
				if ( $tool_name ) {
					$this->conversation_mgr->update_slots( $conv_id, array_merge(
						$conversation['slots'] ?? [],
						$entities,
						[ '_single_tool' => $tool_name ]
					) );
				}
			}

			$this->save_server_memory_spec( $server_result, $conversation );
			$this->finalize( $result, $conversation, $start_time, $params );
			return $result;
		}

		// Execute the tool directly (single step, no pipeline)
		return $this->execute_single_tool( $tool_name, $entities, $server_result, $params, $conversation, $result, $start_time );
	}

	/**
	 * Execute a single atomic tool directly — NO pipeline, NO WAIC scenario.
	 *
	 * Phase 1.12a: Delegates to BizCity_Tool_Wrapper::run() for unified
	 * slot prep + validation + execution. Shell handles conversation state.
	 */
	private function execute_single_tool( string $tool_name, array $entities, array $server_result, array $params, array $conversation, array $result, float $start_time ): array {
		$conv_id    = $conversation['conversation_id'] ?? '';
		$session_id = $params['session_id'] ?? '';
		$user_id    = (int) ( $params['user_id'] ?? get_current_user_id() );
		$channel    = $params['channel'] ?? '';
		$message    = $params['message'] ?? '';

		if ( ! class_exists( 'BizCity_Tool_Wrapper' ) ) {
			error_log( self::LOG . ' [SINGLE] BizCity_Tool_Wrapper not available' );
			$result['reply']  = 'Hệ thống chưa sẵn sàng để thực thi công cụ này.';
			$result['action'] = 'error';
			$this->finalize( $result, $conversation, $start_time, $params );
			return $result;
		}

		// ── Delegate to Tool Wrapper ──
		$wrapper_context = [
			'caller'        => 'intent_engine_single',
			'session_id'    => $session_id,
			'user_id'       => $user_id,
			'channel'       => $channel,
			'conv_id'       => $conv_id,
			'message'       => $message,
			'goal'          => 'tool:' . $tool_name,
			'goal_label'    => $tool_name,
			'entities'      => $entities,
			'save_studio'   => 'auto',
			'save_evidence' => true,
			'pipeline_id'   => $conv_id,
			'node_id'       => 'single',
		];

		// SSE streaming for SINGLE mode tool execution
		$can_stream_sse = function_exists( 'bizcity_openrouter_chat_stream' )
		                && has_action( 'bizcity_intent_stream_chunk' );
		if ( $can_stream_sse ) {
			$wrapper_context['stream_callback'] = function ( $delta, $full_text ) {
				do_action( 'bizcity_intent_stream_chunk', $delta, $full_text );
			};
		}

		$wrapper_result = BizCity_Tool_Wrapper::run( $tool_name, [], $wrapper_context );

		// ── Handle waiting_slot → ask_user ──
		if ( $wrapper_result['status'] === 'waiting_slot' ) {
			$still_missing = $wrapper_result['missing_fields'];
			$tool_params   = $wrapper_result['filled_params'];

			error_log( self::LOG . ' [SINGLE] Missing after prep: ' . implode( ',', $still_missing ) );

			$result['reply']  = $wrapper_result['slot_prompt'];
			$result['action'] = 'ask_user';
			$result['meta']['server_action']  = 'single_missing_slots';
			$result['meta']['missing_fields'] = $still_missing;
			$result['meta']['filled_params']  = $tool_params;

			// Persist state so resume can pick up where we left off
			if ( $conv_id ) {
				$this->conversation_mgr->set_waiting( $conv_id, 'clarify', $still_missing[0] );
				$this->conversation_mgr->update_slots( $conv_id, array_merge(
					$conversation['slots'] ?? [],
					$tool_params,
					[ '_single_tool' => $tool_name, '_single_missing' => $still_missing ]
				) );
			}

			$this->save_server_memory_spec( $server_result, $conversation );
			$this->finalize( $result, $conversation, $start_time, $params );
			return $result;
		}

		// ── Handle error (tool not found, engine not available) ──
		if ( $wrapper_result['status'] === 'error' && empty( $wrapper_result['data'] ) ) {
			$result['reply']  = $wrapper_result['message'] ?: "Không thể thực thi {$tool_name}.";
			$result['action'] = 'error';
			$this->save_server_memory_spec( $server_result, $conversation );
			$this->finalize( $result, $conversation, $start_time, $params );
			return $result;
		}

		// ── Handle completed / execution error ──
		$success     = $wrapper_result['success'];
		$tool_msg    = $wrapper_result['message'];
		$tool_data   = $wrapper_result['data'];
		$duration_ms = $wrapper_result['duration_ms'];

		error_log( self::LOG . " [SINGLE] Tool result: success={$success}, duration={$duration_ms}ms" );

		if ( $success ) {
			$reply = $this->format_single_tool_reply( $tool_name, $tool_data, $tool_msg );
			$result['reply']  = $reply;
			$result['action'] = 'single_done';
			$result['meta']['tool_result']      = $tool_data;
			$result['meta']['tool_duration_ms'] = $duration_ms;
		} else {
			$result['reply']  = $tool_msg ?: "Không thể thực thi {$tool_name}.";
			$result['action'] = 'error';
		}

		// Phase 1.15: Memory update is handled by finalize() — see MEMORY LIFECYCLE rule:
		// CREATE (Step 3.5) → UPDATE (blocks / Ngón 4,7) → FINALIZE (finalize())
		// Shell (Não) does NOT update memory — "Ngón tay mới làm việc"

		$this->save_server_memory_spec( $server_result, $conversation );
		$this->finalize( $result, $conversation, $start_time, $params );
		return $result;
	}

	/**
	 * Infer likely tool from message keywords when classifier returns tool="".
	 *
	 * Keyword-based domain matching against registered tools. Used as safety net
	 * when SmartClassifier returns ask_user/confirm with empty tool field.
	 *
	 * @param string $message       User message.
	 * @param array  $server_result Classifier result (may contain reasoning, entities).
	 * @return string Tool ID or empty string.
	 */
	private function infer_tool_from_context( string $message, array $server_result ): string {
		$msg_lower = mb_strtolower( $message, 'UTF-8' );

		// Domain keyword → tool mapping (ordered by specificity)
		$domain_map = [
			'scheduler_create_event'  => [ 'đặt lịch', 'tạo lịch', 'lịch hẹn', 'hẹn lịch', 'tạo sự kiện', 'book lịch' ],
			'scheduler_delete_event'  => [ 'xóa lịch', 'hủy lịch', 'xóa sự kiện' ],
			'scheduler_update_event'  => [ 'sửa lịch', 'dời lịch', 'cập nhật lịch', 'đổi lịch' ],
			'scheduler_list_events'   => [ 'xem lịch', 'lịch trình', 'danh sách lịch' ],
			'gmail_send_message'      => [ 'gửi email', 'gửi mail', 'send email', 'viết email' ],
			'gmail_list_messages'     => [ 'xem email', 'đọc email', 'check email', 'inbox' ],
			'post_facebook'           => [ 'đăng facebook', 'post facebook', 'đăng fb' ],
			'calendar_create_event'   => [ 'tạo event', 'create event', 'add calendar' ],
		];

		foreach ( $domain_map as $tool_id => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( mb_strpos( $msg_lower, $kw ) !== false ) {
					return $tool_id;
				}
			}
		}

		// Also check reasoning from classifier
		$reasoning = mb_strtolower( $server_result['reasoning'] ?? '', 'UTF-8' );
		if ( $reasoning ) {
			foreach ( $domain_map as $tool_id => $keywords ) {
				foreach ( $keywords as $kw ) {
					if ( mb_strpos( $reasoning, $kw ) !== false ) {
						return $tool_id;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Format tool output into a short, human-readable reply.
	 * For SINGLE mode — no LLM synthesis, just structured data → text.
	 */
	private function format_single_tool_reply( string $tool_name, array $data, string $fallback_msg ): string {
		if ( ! empty( $fallback_msg ) ) {
			return $fallback_msg;
		}

		// For action tools: return success + key data fields
		$parts = [];
		foreach ( $data as $key => $val ) {
			if ( is_string( $val ) && strlen( $val ) < 500 && ! empty( $val ) ) {
				$parts[] = "**{$key}**: {$val}";
			}
		}

		if ( $parts ) {
			return "✅ {$tool_name} thành công:\n" . implode( "\n", $parts );
		}

		return "✅ {$tool_name} đã hoàn tất.";
	}

	/* ================================================================
	 *  Pipeline execution — unified for all depths (MULTI mode)
	 * ================================================================ */

	private function execute_pipeline(
		array $plan,
		array $server_result,
		array $params,
		array $conversation,
		array $result,
		float $start_time = 0.0
	): array {
		$steps = $plan['steps'] ?? [];
		if ( $start_time <= 0.0 ) {
			$start_time = microtime( true );
		}

		error_log( self::LOG . ' Executing pipeline: ' . count( $steps ) . ' steps' );

		// Use existing Scenario Generator + Step Executor for actual execution
		// Shell delegates to the proven execution layer (not reimplemented)
		if ( class_exists( 'BizCity_Scenario_Generator' ) && class_exists( 'BizCity_Step_Executor' ) ) {
			$message     = $params['message'] ?? '';
			$slash_param = $params['slash_command'] ?? '';

			// Phase 1.12: Try skill-based plan first (structured frontmatter, zero hardcode)
			$nodes = $this->try_skill_based_plan( $steps, $message, $slash_param, $server_result );

			if ( ! $nodes ) {
				// Fallback: generic plan_to_scenario_nodes (legacy chaining)
				$nodes = $this->plan_to_scenario_nodes( $steps, $message, $slash_param );
			}

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
					// ── Pre-flight: Validate pipeline tools ──
					$validation = null;
					$fixes      = [];
					if ( class_exists( 'BizCity_Pipeline_Validator' ) ) {
						$validation = BizCity_Pipeline_Validator::validate( $scenario );

						// Auto-fix errors that have a suggested replacement
						if ( ! $validation['valid'] ) {
							$fixes = BizCity_Pipeline_Validator::auto_fix( $scenario, $validation['issues'] );
							if ( ! empty( $fixes ) ) {
								// Update existing task with fixed tool IDs (not INSERT new)
								BizCity_Scenario_Generator::update_task_params( $task_id, $scenario );
								error_log( self::LOG . ' Pipeline auto-fixed: ' . count( $fixes ) . ' issues' );
								// Re-validate after fix
								$validation = BizCity_Pipeline_Validator::validate( $scenario );
							}
						}

						$result['meta']['pipeline_validation'] = $validation;

						// Send validation results to webchat_messages
						$this->send_validation_messages( $validation, $fixes, $params, $pipeline_id );
					}

					// Build memory spec for the pipeline (Phase 1.2)
					BizCity_Memory_Spec::build_from_pipeline( $task_id, $scenario, [
						'user_id'    => $params['user_id'] ?? 0,
						'session_id' => $params['session_id'] ?? '',
						'channel'    => $params['channel'] ?? 'webchat',
					] );

					// Phase 1.15: Seed memory ## Tasks from pipeline plan
					// Nguyên tắc: Shell (Não) chỉ GHI KẾ HOẠCH vào memory —
					// it_call_* blocks (Ngón tay) sẽ update ## Current khi thực thi
					$_p115_mem_id = isset( $result['meta']['phase115_memory_id'] ) ? (int) $result['meta']['phase115_memory_id'] : 0;
					if ( $_p115_mem_id && class_exists( 'BizCity_Memory_Manager' ) ) {
						$_task_labels = array();
						foreach ( $steps as $_si => $_stp ) {
							$_task_labels[] = isset( $_stp['label'] ) && $_stp['label'] ? $_stp['label'] : ( isset( $_stp['tool'] ) ? $_stp['tool'] : 'Step ' . ( $_si + 1 ) );
						}
						BizCity_Memory_Manager::instance()->update_section(
							$_p115_mem_id,
							'Tasks',
							implode( "\n", array_map( function ( $l ) { return '- [ ] ' . $l; }, $_task_labels ) )
						);
						error_log( self::LOG . ' [Phase1.15] Memory ## Tasks seeded: mem_id=' . $_p115_mem_id . ' steps=' . count( $steps ) );
					}

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
	 * Phase 1.12: Try to build pipeline nodes from a skill match.
	 * Queries bizcity_skill_tool_map for the first tool in steps.
	 * If a skill is found, delegates to Bridge::plan_nodes_from_skill().
	 *
	 * @param array  $steps       Pipeline plan steps from SmartClassifier.
	 * @param string $message     User message.
	 * @param string $slash_param Slash command parameter.
	 * @return array|null         WAIC nodes[] or null if no skill match.
	 */
	private function try_skill_based_plan( array $steps, string $message, string $slash_param, array $server_result = [] ): ?array {
		if ( ! class_exists( 'BizCity_Skill_Tool_Map' ) || ! class_exists( 'BizCity_Skill_Pipeline_Bridge' ) ) {
			return null;
		}

		// Ensure skills table migration has run (adds pipeline_json column if missing)
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			BizCity_Skill_Database::instance();
		}

		// Find the first tool_id from classifier steps
		$tool_key = '';
		foreach ( $steps as $step ) {
			if ( ! empty( $step['tool'] ) ) {
				$tool_key = $step['tool'];
				break;
			}
		}

		if ( empty( $tool_key ) ) {
			return null;
		}

		// Query skill_tool_map: tool → best matching skill
		$map       = BizCity_Skill_Tool_Map::instance();
		$skill_row = $map->resolve_skill_for_tool( $tool_key, (int) get_current_user_id() );

		if ( ! $skill_row ) {
			error_log( self::LOG . ' [SkillPlan] No skill for tool: ' . $tool_key );
			return null;
		}

		error_log( self::LOG . ' [SkillPlan] Skill match: ' . $skill_row['title']
			. ' (id=' . $skill_row['id'] . ') via tool=' . $tool_key );

		// Build skill data for Bridge
		$tools_json      = json_decode( $skill_row['tools_json'] ?? '[]', true ) ?: [];

		// pipeline_json: try from JOIN result first, fallback to direct DB fetch
		$pipeline_raw = $skill_row['pipeline_json'] ?? null;
		if ( empty( $pipeline_raw ) && class_exists( 'BizCity_Skill_Database' ) ) {
			$full_skill = BizCity_Skill_Database::instance()->get( (int) $skill_row['id'] );
			$pipeline_raw = $full_skill['pipeline_json'] ?? null;
		}
		$pipeline_config = $pipeline_raw ? ( json_decode( $pipeline_raw, true ) ?: [] ) : [];

		// ── Priority 1: Use Scenario Planner steps when available (LLM-accurate) ──
		// Scenario Planner (gemini-2.5-pro) provides block_code per step, replacing
		// regex/keyword guessing. Natural language context handled by LLM, not code.
		$planner_steps    = $server_result['pipeline_plan']['steps'] ?? [];
		$has_planner_steps = ! empty( $planner_steps ) && isset( $planner_steps[0]['block_code'] );

		$block_code_hints = [];
		$extra_tools      = [];

		if ( $has_planner_steps ) {
			// Build merged_tools list and block_code_hints from Scenario Planner output
			$merged_tools = [];
			foreach ( $planner_steps as $ps ) {
				$bc      = $ps['block_code'] ?? '';
				// Research uses its own block_code as sentinel key (tool is empty)
				$tid    = ( $bc === 'it_call_research' ) ? 'it_call_research' : ( $ps['tool'] ?? '' );
				if ( empty( $tid ) ) {
					continue; // skip malformed step
				}
				if ( ! in_array( $tid, $merged_tools, true ) ) {
					$merged_tools[] = $tid;
				}
				$block_code_hints[ $tid ] = $bc;
				// Register research sentinel in config blocks
				if ( $bc === 'it_call_research' ) {
					$pipeline_config['blocks']['it_call_research'] = 'it_call_research';
				}
				// Track extra distribution tools (not in original skill)
				if ( $bc === 'it_call_tool' && ! in_array( $tid, $tools_json, true ) ) {
					$extra_tools[] = $tid;
				}
			}
			error_log( self::LOG . ' [SkillPlan] Scenario Planner nodes: ' . implode( ' → ', $merged_tools ) );
		} else {
			// ── Fallback: Programmatic detection (no Planner output available) ──
			$depth              = $server_result['depth'] ?? '';
			$knowledge_strategy = $server_result['knowledge_strategy'] ?? '';
			$needs_research     = ( $depth === 'deep' || $knowledge_strategy === 'deep_research' );

			$skill_tools_set = array_fill_keys( $tools_json, true );
			foreach ( $steps as $step ) {
				$t = $step['tool'] ?? '';
				if ( $t && $t !== $tool_key && ! isset( $skill_tools_set[ $t ] ) ) {
					$extra_tools[] = $t;
				}
			}

			$merged_tools = $tools_json;
			if ( $needs_research && ! in_array( 'it_call_research', $merged_tools, true ) ) {
				array_unshift( $merged_tools, 'it_call_research' );
				$pipeline_config['blocks'] = array_merge(
					$pipeline_config['blocks'] ?? [],
					[ 'it_call_research' => 'it_call_research' ]
				);
				error_log( self::LOG . ' [SkillPlan] Fallback: injecting research (depth=' . $depth . ', ks=' . $knowledge_strategy . ')' );
			}
			foreach ( $extra_tools as $et ) {
				if ( ! in_array( $et, $merged_tools, true ) ) {
					$merged_tools[] = $et;
				}
			}
			if ( $extra_tools ) {
				error_log( self::LOG . ' [SkillPlan] Fallback: extra distribution tools: ' . implode( ', ', $extra_tools ) );
			}
		}

		$skill = [
			'title'           => $skill_row['title'] ?? 'Unnamed Skill',
			'tools'           => $merged_tools,
			'pipeline_config' => $pipeline_config,
			'content'         => $skill_row['content'] ?? '',
		];

		// Bridge = planner (returns nodes[], no DB save)
		$bridge  = BizCity_Skill_Pipeline_Bridge::instance();
		$options = [
			'extra_distribution_tools' => $extra_tools,
			'block_code_hints'         => $block_code_hints,
		];
		$nodes   = $bridge->plan_nodes_from_skill( $skill, $message, $options );

		if ( $nodes ) {
			error_log( self::LOG . ' [SkillPlan] ✅ Skill-based plan: ' . count( $nodes )
				. ' nodes from "' . $skill_row['title'] . '"' );
		}

		return $nodes;
	}

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
			// LLM-provided block_code has highest priority (from Scenario Planner)
			$block   = ! empty( $step['block_code'] ) ? $step['block_code'] : $this->infer_block_code( $tool_id, $label );

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

		// ── Auto-inject content synthesis step when research → tool gap ──
		// If classifier produced research + tool but NO content step in between,
		// inject it_call_content to synthesize research → content → show in chat.
		if ( $research_node_id !== null && $content_node_id === null ) {
			$inject_before = null;
			foreach ( $steps as $idx => $step ) {
				if ( ! empty( $step['skip'] ) ) {
					continue;
				}
				$tid = ! empty( $step['tool'] ) ? $step['tool'] : '';
				$lbl = ! empty( $step['label'] ) ? $step['label'] : ( $tid ?: 'Step' );
				$chk = ! empty( $step['block_code'] ) ? $step['block_code'] : $this->infer_block_code( $tid, $lbl );
				if ( $chk === 'it_call_tool' ) {
					$inject_before = $idx;
					break;
				}
			}

			if ( $inject_before !== null ) {
				$synth_step = array(
					'tool'  => '',
					'label' => 'Viết nội dung tổng hợp',
				);
				array_splice( $steps, $inject_before, 0, array( $synth_step ) );

				// Re-scan to update node IDs after injection
				$plan_steps       = array();
				$content_node_id  = null;
				$research_node_id = null;
				$node_counter     = 4;
				foreach ( $steps as $step ) {
					if ( ! empty( $step['skip'] ) ) {
						continue;
					}
					$tid   = ! empty( $step['tool'] ) ? $step['tool'] : '';
					$lbl   = ! empty( $step['label'] ) ? $step['label'] : ( $tid ?: 'Step' );
					$block = ! empty( $step['block_code'] ) ? $step['block_code'] : $this->infer_block_code( $tid, $lbl );
					if ( $block === 'it_call_research' && $research_node_id === null ) {
						$research_node_id = (string) $node_counter;
					}
					if ( $block === 'it_call_content' && $content_node_id === null ) {
						$content_node_id = (string) $node_counter;
					}
					$plan_steps[] = array(
						'tool_name' => $block,
						'label'     => $lbl,
						'node_id'   => (string) $node_counter,
					);
					$node_counter++;
				}

				error_log( self::LOG . ' [Pipeline] Auto-injected content synthesis: content_node=' . $content_node_id . ' before tool step' );
			}
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
			// LLM-provided block_code has highest priority (from Scenario Planner)
			$block    = ! empty( $step['block_code'] ) ? $step['block_code'] : $this->infer_block_code( $tool_id, $label );
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
				// Chain from content output if available, plus research as fallback
				$input = array();
				if ( $content_node_id !== null ) {
					$input['content'] = '{{node#' . $content_node_id . '.content}}';
					$input['title']   = '{{node#' . $content_node_id . '.title}}';
				}
				// Always pass research context if available (tool can use for context enrichment)
				if ( $research_node_id !== null ) {
					$input['sources'] = '{{node#' . $research_node_id . '.research_summary}}';
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

		// Layer 1: Tool registry content_tier — mirrors Bridge's infer_block_code_for_tool().
		// Authoritative: new tools only need content_tier set in registry, not this list.
		if ( ! empty( $tool_id ) ) {
			$all = $this->tools->list_all();
			if ( isset( $all[ $tool_id ] ) ) {
				$tier = (int) ( $all[ $tool_id ]['content_tier'] ?? 0 );
				return $tier >= 1 ? 'it_call_content' : 'it_call_tool';
			}
		}

		// Layer 2: Hardcoded fallback (for tools not yet in registry)
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
			'video_create_script', // Phase 1.12: video script → it_call_content (Ngón 4)
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
		$user_id    = intval( $params['user_id'] ?? 0 );
		$session_id = $params['session_id'] ?? '';
		$channel    = $params['channel'] ?? 'webchat';
		$action     = $pre_result['result']['action'] ?? '';

		// For resume_with_slot, skip pipeline resume — go straight to tool slot resume
		if ( $action !== 'resume_with_slot' ) {
			// Try to find active pipeline_id from conversation meta and resume it
			$pipeline_id = $conversation['meta']['pipeline_id'] ?? '';
			if ( $pipeline_id && class_exists( 'BizCity_Pipeline_Resume' ) && method_exists( 'BizCity_Pipeline_Resume', 'resume' ) ) {
				$resume_result = BizCity_Pipeline_Resume::resume( $pipeline_id, $user_id, $session_id, $channel );
				if ( ! empty( $resume_result['success'] ) ) {
					$result['reply']  = $resume_result['reply'] ?? 'Đang tiếp tục pipeline...';
					$result['action'] = 'resume_pipeline';
					$result['meta']   = array_merge( $result['meta'], $pre_result['result']['meta'] ?? [] );
					$this->finalize( $result, $conversation, microtime( true ), $params );
					return $result;
				}
			}
		}

		// ── Refresh conversation from DB for latest goal/slots ──
		$fresh_conv = $this->conversation_mgr->get_active( $user_id, $channel, $session_id );
		if ( $fresh_conv ) {
			$conversation = $fresh_conv;
		}

		// Fallback: check if conversation goal is tool:xxx → tool slot-gathering resume
		$goal = $conversation['goal'] ?? '';
		$tool_name_resume = '';

		// Primary: goal field
		if ( ! empty( $goal ) && strpos( $goal, 'tool:' ) === 0 ) {
			$tool_name_resume = substr( $goal, 5 );
		}

		// Fallback: _single_tool stored in slots during ask_user
		if ( empty( $tool_name_resume ) ) {
			$slots = $conversation['slots'] ?? [];
			$tool_name_resume = $slots['_single_tool'] ?? '';
			if ( $tool_name_resume ) {
				error_log( self::LOG . " resume: goal='{$goal}' empty, recovered tool='{$tool_name_resume}' from _single_tool slot" );
				// Also fix the goal for subsequent turns
				$conv_id_fix = $conversation['conversation_id'] ?? '';
				if ( $conv_id_fix ) {
					$this->conversation_mgr->set_goal( $conv_id_fix, 'tool:' . $tool_name_resume, $tool_name_resume );
				}
			}
		}

		error_log( self::LOG . " resume_dispatch: goal='{$goal}', tool_name_resume='{$tool_name_resume}'" );

		if ( ! empty( $tool_name_resume ) ) {
			$tool_resume_result = $this->handle_tool_slot_resume( $tool_name_resume, $params, $conversation, $result );
			if ( $tool_resume_result ) {
				return $tool_resume_result;
			}

			// No planner plan exists (atomic tool confirmed by user) → execute directly via BizCity_Tool_Run
			$rule = $pre_result['result']['meta']['pre_rule'] ?? '';
			if ( $rule === 'WAITING_CONFIRM_ACCEPT' && $tool_name_resume ) {
				error_log( self::LOG . " Confirm-accept: executing atomic tool '{$tool_name_resume}' directly via BizCity_Tool_Run" );

				$conv_id       = $conversation['conversation_id'] ?? '';
				$current_slots = $conversation['slots'] ?? [];
				if ( $conv_id ) {
					$fresh_conv = $this->conversation_mgr->get_active(
						intval( $params['user_id'] ?? 0 ),
						$params['channel'] ?? 'webchat',
						$params['session_id'] ?? ''
					);
					if ( $fresh_conv ) {
						$current_slots = $fresh_conv['slots'] ?? [];
					}
				}

				$tool_result = BizCity_Tool_Run::execute( $tool_name_resume, $current_slots, [
					'caller'     => 'intent_engine_single_confirm',
					'session_id' => $params['session_id'] ?? '',
					'user_id'    => intval( $params['user_id'] ?? 0 ),
					'channel'    => $params['channel'] ?? 'webchat',
					'conv_id'    => $conv_id,
					'goal'       => 'tool:' . $tool_name_resume,
					'goal_label' => $tool_name_resume,
				] );

				$success  = ! empty( $tool_result['success'] );
				$tool_msg = $tool_result['message'] ?? '';
				$tool_data = $tool_result['data'] ?? [];

				if ( $success ) {
					$reply = $this->format_single_tool_reply( $tool_name_resume, $tool_data, $tool_msg );
					$result['reply']  = $reply;
					$result['action'] = 'single_done';
					$result['meta']['tool_result']      = $tool_data;
					$result['meta']['tool_duration_ms'] = $tool_result['duration_ms'] ?? 0;
				} else {
					$result['reply']  = $tool_msg ?: "Không thể thực thi {$tool_name_resume}.";
					$result['action'] = 'error';
				}

				$result['meta']['mode'] = 'single';
				$this->finalize( $result, $conversation, microtime( true ), $params );
				return $result;
			}
		}

		// Fallback: no pipeline/tool goal found — re-process through classifier
		// Conversation history now contains original question + user's clarification answer,
		// giving the classifier enough context to resolve (e.g., ask_user "đặt lịch gì?" + answer → execution)
		error_log( self::LOG . ' resume_with_slot fallback: no pipeline/tool goal → re-routing through step3_server_resolve' );
		$message = $params['message'] ?? '';
		return $this->step3_server_resolve( $message, $params, $conversation, $result, [], [], microtime( true ) );
	}

	/* ================================================================
	 *  Tool slot-gathering resume (for intent provider tools without skill/pipeline)
	 * ================================================================ */

	/**
	 * Resume tool slot gathering after user provides a slot value.
	 *
	 * Called when conversation goal is "tool:xxx" but no pipeline is active.
	 * Checks Planner for remaining required slots:
	 *   - If missing slots → ask next one using provider's prompt
	 *   - If all filled → execute tool via single-step pipeline
	 *
	 * @param string $tool_name  Tool identifier (e.g., 'write_article').
	 * @param array  $params     Original request params.
	 * @param array  $conversation Conversation row (may be stale — re-fetches slots).
	 * @param array  $result     Result template.
	 * @return array|null Result array if handled, null to fall through.
	 */
	private function handle_tool_slot_resume( string $tool_name, array $params, array $conversation, array $result ): ?array {
		$conv_id = $conversation['conversation_id'] ?? '';

		// Re-fetch conversation for up-to-date slots (pre-rules just called update_slots)
		$current_slots = $conversation['slots'] ?? [];
		if ( $conv_id ) {
			$fresh_conv = $this->conversation_mgr->get_active(
				intval( $params['user_id'] ?? 0 ),
				$params['channel'] ?? 'webchat',
				$params['session_id'] ?? ''
			);
			if ( $fresh_conv ) {
				$current_slots = $fresh_conv['slots'] ?? [];
			}
		}

		// Try Planner first (provider-registered plans)
		$plan = null;
		if ( class_exists( 'BizCity_Intent_Planner' ) ) {
			$plan = BizCity_Intent_Planner::instance()->get_plan( $tool_name );
		}

		if ( $plan ) {
			// Use Planner's slot_order and required_slots
			$slot_order     = $plan['slot_order'] ?? array_keys( $plan['required_slots'] ?? [] );
			$required_slots = $plan['required_slots'] ?? [];
			$tool_label     = $plan['label'] ?? $tool_name;
		} else {
			// Fallback: use tool schema input_fields (for tools registered via BizCity_Intent_Tools)
			if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
				return null;
			}
			$schema = BizCity_Intent_Tools::instance()->get_schema( $tool_name );
			if ( ! $schema || empty( $schema['input_fields'] ) ) {
				return null;
			}

			// Build required_slots from input_fields
			$required_slots = [];
			$slot_order     = [];
			foreach ( $schema['input_fields'] as $field => $cfg ) {
				if ( ! empty( $cfg['required'] ) ) {
					$required_slots[ $field ] = [
						'type'   => $cfg['type'] ?? 'text',
						'prompt' => $cfg['description'] ?? $cfg['prompt'] ?? "Vui lòng cung cấp: {$field}",
					];
					$slot_order[] = $field;
				}
			}
			$tool_label = $schema['description'] ?? $tool_name;
			error_log( self::LOG . " Tool slot resume: no Planner plan, using tool schema for '{$tool_name}'" );
		}

		// Find first unfilled required slot
		$missing_slot = '';
		$missing_cfg  = [];
		foreach ( $slot_order as $slot_name ) {
			if ( ! isset( $required_slots[ $slot_name ] ) ) {
				continue;
			}
			if ( empty( $current_slots[ $slot_name ] ) ) {
				$missing_slot = $slot_name;
				$missing_cfg  = $required_slots[ $slot_name ];
				break;
			}
		}

		if ( $missing_slot ) {
			// Still missing slots → ask next one
			if ( $conv_id && class_exists( 'BizCity_Intent_Conversation' ) ) {
				// MUST use 'clarify' so pre-rules check_waiting() matches WAITING_CLARIFY branch
				// (check_waiting looks for strpos($waiting_for, 'clarify') or 'field')
				BizCity_Intent_Conversation::instance()->set_waiting(
					$conv_id,
					'clarify',
					$missing_slot
				);
			}

			$prompt = $missing_cfg['prompt'] ?? "Vui lòng cung cấp: {$missing_slot}";
			error_log( self::LOG . " Tool slot resume: {$tool_name} → asking '{$missing_slot}'" );

			$result['reply']  = $prompt;
			$result['action'] = 'ask_user';
			$result['meta']['tool_slot_resume'] = true;
			$result['meta']['tool_name']        = $tool_name;
			$result['meta']['slot_name']        = $missing_slot;

			// Preserve memory spec so context persists across slot-gathering turns
			$this->save_server_memory_spec(
				[ 'action' => 'single', 'tool' => $tool_name, 'mode' => 'single' ],
				$conversation
			);

			$this->finalize( $result, $conversation, microtime( true ), $params );
			return $result;
		}

		// All required slots filled → execute tool directly (NO pipeline/WAIC)
		error_log( self::LOG . " Tool slot resume: {$tool_name} → all required slots filled, executing via BizCity_Tool_Run" );

		$tool_result = BizCity_Tool_Run::execute( $tool_name, $current_slots, [
			'caller'     => 'intent_engine_single_resume',
			'session_id' => $params['session_id'] ?? '',
			'user_id'    => intval( $params['user_id'] ?? 0 ),
			'channel'    => $params['channel'] ?? 'webchat',
			'conv_id'    => $conv_id,
			'goal'       => 'tool:' . $tool_name,
			'goal_label' => $tool_label,
		] );

		$success  = ! empty( $tool_result['success'] );
		$tool_msg = $tool_result['message'] ?? '';
		$tool_data = $tool_result['data'] ?? [];

		if ( $success ) {
			$reply = $this->format_single_tool_reply( $tool_name, $tool_data, $tool_msg );
			$result['reply']  = $reply;
			$result['action'] = 'single_done';
			$result['meta']['tool_result']      = $tool_data;
			$result['meta']['tool_duration_ms'] = $tool_result['duration_ms'] ?? 0;
		} else {
			$result['reply']  = $tool_msg ?: "Không thể thực thi {$tool_name}.";
			$result['action'] = 'error';
		}

		$result['meta']['mode'] = 'single';
		$result['meta']['tool_slot_resume'] = true;
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

	/* ================================================================
	 *  Pipeline Validation → webchat_messages
	 * ================================================================ */

	/**
	 * Send pipeline validation results as webchat messages.
	 *
	 * MSG1: Pre-flight check summary (pass/fail + counts)
	 * MSG2: Auto-fix details (if any fixes applied)
	 * MSG3: Remaining errors (if unfixable issues remain)
	 *
	 * @param array  $validation  Validation result from BizCity_Pipeline_Validator::validate().
	 * @param array  $fixes       Auto-fix results from BizCity_Pipeline_Validator::auto_fix().
	 * @param array  $params      Original request params (session_id, channel, user_id).
	 * @param string $pipeline_id Pipeline ID.
	 */
	private function send_validation_messages( array $validation, array $fixes, array $params, string $pipeline_id ): void {
		if ( ! class_exists( 'BizCity_Pipeline_Messenger' ) ) {
			return;
		}

		$exec_state = [
			'session_id'  => $params['session_id'] ?? '',
			'user_id'     => intval( $params['user_id'] ?? 0 ),
			'channel'     => $params['channel'] ?? 'webchat',
			'pipeline_id' => $pipeline_id,
		];

		$issues   = $validation['issues'] ?? [];
		$errors   = array_filter( $issues, fn( $i ) => $i['severity'] === 'error' );
		$warnings = array_filter( $issues, fn( $i ) => $i['severity'] === 'warning' );

		// ── MSG1: Summary ──
		if ( empty( $issues ) ) {
			BizCity_Pipeline_Messenger::send( $exec_state,
				"✅ **Pre-flight check:** Pipeline hợp lệ — tất cả tools tồn tại, biến tham chiếu đúng.",
				'success',
				[
					'tool_name' => 'pipeline_validator',
					'step_code' => 'preflight_pass',
				]
			);
			return;
		}

		$summary_msg = "🔍 **Pre-flight check:** " . count( $errors ) . " lỗi, " . count( $warnings ) . " cảnh báo\n";
		foreach ( $issues as $issue ) {
			$icon = $issue['severity'] === 'error' ? '❌' : '⚠️';
			$summary_msg .= "\n{$icon} **Node #{$issue['node_id']}** — {$issue['rule']}\n";
			$summary_msg .= "   {$issue['message']}\n";
			if ( ! empty( $issue['fix_hint'] ) ) {
				$summary_msg .= "   💡 {$issue['fix_hint']}\n";
			}
		}

		BizCity_Pipeline_Messenger::send( $exec_state,
			$summary_msg,
			empty( $errors ) ? 'info' : 'error',
			[
				'tool_name'  => 'pipeline_validator',
				'step_code'  => 'preflight_issues',
				'error_count'   => count( $errors ),
				'warning_count' => count( $warnings ),
			]
		);

		// ── MSG2: Auto-fix details ──
		if ( ! empty( $fixes ) ) {
			$fix_msg = "🔧 **Auto-fix:** " . count( $fixes ) . " tool(s) đã được sửa tự động\n";
			foreach ( $fixes as $fix ) {
				$fix_msg .= "\n• Node #{$fix['node_id']}: `{$fix['old']}` → `{$fix['new']}`";
			}

			BizCity_Pipeline_Messenger::send( $exec_state,
				$fix_msg,
				'success',
				[
					'tool_name' => 'pipeline_validator',
					'step_code' => 'preflight_autofix',
					'fixes'     => $fixes,
				]
			);
		}

		// ── MSG3: Remaining unfixed errors ──
		$remaining = array_filter( $validation['issues'], fn( $i ) => $i['severity'] === 'error' );
		if ( ! empty( $remaining ) ) {
			$err_msg = "⛔ **" . count( $remaining ) . " lỗi chưa sửa được** — pipeline có thể fail tại các node này:\n";
			foreach ( $remaining as $issue ) {
				$err_msg .= "\n• **Node #{$issue['node_id']}**: {$issue['message']}";
			}

			BizCity_Pipeline_Messenger::send( $exec_state,
				$err_msg,
				'error',
				[
					'tool_name'       => 'pipeline_validator',
					'step_code'       => 'preflight_errors',
					'remaining_count' => count( $remaining ),
				]
			);
		}
	}

	/**
	 * Finalize: increment turn, log timing, finalize Phase 1.15 Memory Spec.
	 *
	 * Memory Lifecycle position: FINALIZE (last step)
	 * - CREATE:   Step 3.5 (load_or_create)
	 * - UPDATE:   it_call_* blocks (Ngón 4/7/8)
	 * - FINALIZE: HERE — ghi resume state, chuyển status nếu cần
	 */
	private function finalize( array &$result, array $conversation, float $start_time, array $params = [] ): void {
		$conv_id = $conversation['conversation_id'] ?? '';
		if ( $conv_id ) {
			$this->conversation_mgr->increment_turn( $conv_id );
		}

		$elapsed = round( ( microtime( true ) - $start_time ) * 1000 );
		$result['meta']['engine']     = 'shell';
		$result['meta']['elapsed_ms'] = $elapsed;

		// ── Phase 1.15: Memory Spec finalize ──
		// Rule 4: Kết thúc → ghi resume state
		// For SINGLE mode (no pipeline blocks), Shell must finalize memory
		// For MULTI mode, it_summary_verifier (Ngón 8) should have already updated
		$_p115_mem_id = isset( $result['meta']['phase115_memory_id'] ) ? (int) $result['meta']['phase115_memory_id'] : 0;
		if ( $_p115_mem_id && class_exists( 'BizCity_Memory_Manager' ) ) {
			$_mgr    = BizCity_Memory_Manager::instance();
			$_action = $result['action'] ?? '';
			$_tool   = $result['meta']['tool'] ?? ( $params['tool'] ?? '' );

			// Single mode: finalize with tool result (blocks did not run)
			if ( $_action === 'single_done' ) {
				// FIX BUG #3: complete_task() expects int $task_index (0-based), not string tool name.
				// Single mode has no seeded tasks, so seed one then mark it done.
				$_mgr->append_task( $_p115_mem_id, $_tool ?: 'single_task', false, 'shell_finalize' );
				$_mgr->complete_task( $_p115_mem_id, 0, 'shell_finalize' );
				$_mgr->finalize( $_p115_mem_id, array(
					'last_completed'      => $_tool,
					'last_output_summary' => 'Single tool completed in ' . $elapsed . 'ms',
					'next_action'         => 'none',
					'can_resume'          => false,
				) );
				error_log( self::LOG . ' [Phase1.15] Finalized (single_done): mem_id=' . $_p115_mem_id );

			} elseif ( $_action === 'error' ) {
				// FIX BUG #4: update_current() expects array, not two strings.
				$_mgr->update_current(
					$_p115_mem_id,
					array(
						'step' => 'FAILED: ' . $_tool,
						'next' => 'Retry or escalate',
					),
					'shell_finalize'
				);
				error_log( self::LOG . ' [Phase1.15] Finalized (error): mem_id=' . $_p115_mem_id );

			} elseif ( $_action === 'execute_pipeline' ) {
				// Pipeline mode: set pipeline_id, blocks will handle step-by-step updates
				// finalize() NOT called here — Ngón 7 (it_call_reflection) will call it
				$_task_id = $result['meta']['task_id'] ?? 0;
				// FIX BUG #4: update_current() expects array, not two strings.
				$_mgr->update_current(
					$_p115_mem_id,
					array(
						'step'        => 'Pipeline queued',
						'pipeline_id' => (string) $_task_id,
						'next'        => 'Waiting for WAIC BFS executor',
					),
					'shell_finalize'
				);
				error_log( self::LOG . ' [Phase1.15] Pipeline queued: mem_id=' . $_p115_mem_id . ' task_id=' . $_task_id );
			}
			// Other actions (passthrough, confirm, ask_user) — memory stays active, no finalize
		}

		error_log( self::LOG . " Done: action={$result['action']}, elapsed={$elapsed}ms" );

		// Fire the same hook as legacy engine so downstream listeners work
		$hook_params = array_merge( $params, [
			'conversation_id' => $conv_id,
		] );
		do_action( 'bizcity_intent_processed', $result, $hook_params );
	}
}
