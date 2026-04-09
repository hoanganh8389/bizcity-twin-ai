<?php
/**
 * WaicAction Block: it_call_memory — Pipeline Memory Spec Persistence
 *
 * Captures the pipeline's execution context (goals, focus, research summary)
 * and persists it to three storage layers:
 *   1. bizcity_webchat_sessions.session_memory_spec  (session-level)
 *   2. bizcity_tasks.params.meta.memory_spec          (task-level)
 *   3. bizcity_intent_conversations                    (conversation snapshot)
 *
 * This prevents "context drift" — even if the user chats off-topic between
 * pipeline steps, downstream blocks (it_call_content) read memory_spec
 * to stay focused on the original goal.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\WebChat\Blocks\Actions
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      Phase 1.10 Sprint 1
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class WaicAction_it_call_memory extends WaicAction {

	protected $_code  = 'it_call_memory';
	protected $_order = 0;

	/** @var string Log prefix */
	private const LOG_PREFIX = '[it_call_memory]';

	public function __construct( $block = null ) {
		$this->_name = __( '🧠 Memory — Save Pipeline Context', 'bizcity-twin-ai' );
		$this->_desc = __( 'Lưu memory specs vào session + task + conversations để pipeline giữ focus.', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	/* ================================================================
	 *  Settings
	 * ================================================================ */

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$this->_settings = [
			'goal_label' => [
				'type'      => 'input',
				'label'     => __( 'Goal Label', 'bizcity-twin-ai' ),
				'default'   => '{{node#1.text}}',
				'variables' => true,
				'desc'      => __( 'Mô tả mục tiêu pipeline. Hỗ trợ {{node#X.var}}.', 'bizcity-twin-ai' ),
			],
			'focus_context' => [
				'type'      => 'textarea',
				'label'     => __( 'Focus Context', 'bizcity-twin-ai' ),
				'default'   => '',
				'rows'      => 3,
				'variables' => true,
				'desc'      => __( 'Bối cảnh bổ sung (research summary, keywords). Hỗ trợ {{node#X.var}}.', 'bizcity-twin-ai' ),
			],
		];
	}

	/* ================================================================
	 *  Variables
	 * ================================================================ */

	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = [
			'memory_spec'      => __( 'JSON memory spec saved', 'bizcity-twin-ai' ),
			'memory_saved_to'  => __( 'Comma list of storage layers (session, task, conversation, cpt)', 'bizcity-twin-ai' ),
			'memory_draft_url' => __( 'URL to memory draft CPT post for user review', 'bizcity-twin-ai' ),
			'memory_draft_id'  => __( 'Post ID of memory draft CPT', 'bizcity-twin-ai' ),
		];
	}

	/* ================================================================
	 *  getResults — Main execution
	 * ================================================================ */

	/**
	 * @param int   $taskId    Current task ID.
	 * @param array $variables Pipeline variables.
	 * @param int   $step      Current step index.
	 * @return array
	 */
	public function getResults( $taskId, $variables, $step = 0 ) {
		$start_time = microtime( true );

		// ── Resolve settings ──
		$goal_label    = $this->replaceVariables( $this->getParam( 'goal_label', '' ), $variables );
		$focus_context = $this->replaceVariables( $this->getParam( 'focus_context', '' ), $variables );

		// Override from block instance settings (getParams() reads $this->_block['data']['settings'])
		$block_settings = $this->getParams();
		if ( ! empty( $block_settings['goal_label'] ) ) {
			$goal_label = $this->replaceVariables( $block_settings['goal_label'], $variables );
		}
		if ( ! empty( $block_settings['focus_context'] ) ) {
			$focus_context = $this->replaceVariables( $block_settings['focus_context'], $variables );
		}

		// ── Execution state ──
		$session_id  = $variables['_session_id'] ?? '';
		$user_id     = (int) ( $variables['_user_id'] ?? 0 );
		$pipeline_id = $variables['_pipeline_id'] ?? '';
		$channel     = $variables['_channel'] ?? 'webchat';
		$conv_id     = $variables['_intent_conversation_id'] ?? '';

		error_log( self::LOG_PREFIX . ' START session=' . $session_id . ' pipeline=' . $pipeline_id . ' goal=' . mb_substr( $goal_label, 0, 80 ) );

		// ── Trace: execute_start ──
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_start', [
			'node_code'  => 'it_call_memory',
			'label'      => 'Đang lưu memory specs...',
			'session_id' => $session_id,
		], 'info', 0 );

		// ── Gather context from ALL previous nodes ──
		$research_summary = '';
		$source_count     = 0;
		$source_ids       = '';
		$total_chars      = 0;
		$chunk_count      = 0;
		$plan_summary     = '';
		$plan_steps       = [];
		$skill_key        = '';
		$skill_title      = '';
		$skill_content    = '';
		$trigger_text     = '';

		// Scan node# variables for all upstream data
		foreach ( $variables as $key => $val ) {
			if ( ! is_array( $val ) || strpos( $key, 'node#' ) !== 0 ) {
				continue;
			}
			// From trigger (node#1)
			if ( ! empty( $val['text'] ) && empty( $trigger_text ) ) {
				$trigger_text = $val['text'];
			}
			// From it_call_skill (node#2)
			if ( ! empty( $val['skill_key'] ) ) {
				$skill_key     = $val['skill_key'];
				$skill_title   = $val['skill_title'] ?? '';
				$skill_content = $val['skill_content'] ?? '';
			}
			// From it_todos_planner (node#3)
			if ( ! empty( $val['plan_summary'] ) ) {
				$plan_summary = $val['plan_summary'];
			}
			if ( ! empty( $val['steps_json'] ) ) {
				$decoded = json_decode( $val['steps_json'], true );
				if ( is_array( $decoded ) ) {
					$plan_steps = $decoded;
				}
			}
			// From it_call_research
			if ( ! empty( $val['research_summary'] ) ) {
				$research_summary = $val['research_summary'];
				$source_count     = (int) ( $val['source_count'] ?? 0 );
				$source_ids       = $val['source_ids'] ?? '';
				$total_chars      = (int) ( $val['total_chars'] ?? 0 );
				$chunk_count      = (int) ( $val['chunk_count'] ?? 0 );
			}
		}

		// Auto-fill goal_label from trigger if empty or still contains unresolved template
		if ( empty( $goal_label ) || strpos( $goal_label, '{{' ) !== false ) {
			if ( ! empty( $trigger_text ) ) {
				$goal_label = $trigger_text;
			} elseif ( ! empty( $variables['node#1']['text'] ) ) {
				$goal_label = $variables['node#1']['text'];
			}
		}

		// ── Build FULL memory spec (captures all upstream context) ──
		$memory_spec = [
			'version'     => 2,
			'scope'       => 'pipeline_execution',
			'pipeline_id' => $pipeline_id,
			'session_id'  => $session_id,
			'goal'        => [
				'primary' => mb_substr( $goal_label, 0, 200 ),
				'label'   => $goal_label,
			],
			'skill'       => [
				'key'     => $skill_key,
				'title'   => $skill_title,
				'content' => mb_substr( $skill_content, 0, 2000 ),
			],
			'pipeline'    => [
				'total_steps' => count( $plan_steps ),
				'plan'        => $plan_summary,
			],
			'checklist'   => array_map( function ( $s ) {
				return [
					'label'  => $s['label'] ?? '',
					'tool'   => $s['tool_name'] ?? '',
					'status' => 'pending',
				];
			}, $plan_steps ),
			'research'    => [
				'source_count'     => $source_count,
				'source_ids'       => $source_ids,
				'total_chars'      => $total_chars,
				'chunk_count'      => $chunk_count,
				'research_summary' => $research_summary,
			],
			'context'     => [
				'focus'            => $focus_context ?: $goal_label,
				'trigger_message'  => mb_substr( $trigger_text, 0, 500 ),
			],
			'mode'        => 'execution',
			'updated_at'  => current_time( 'mysql', true ),
		];

		// ── Layer 1: Session memory spec ──
		$saved_to = [];

		if ( class_exists( 'BizCity_Session_Memory_Spec' ) && ! empty( $session_id ) ) {
			$session_spec = BizCity_Session_Memory_Spec::get( $session_id );
			if ( ! $session_spec ) {
				$session_spec = BizCity_Session_Memory_Spec::blank( 'pipeline' );
			}

			$session_spec['mode']              = 'execution';
			$session_spec['current_topic']     = $goal_label;
			$session_spec['current_focus']     = $focus_context ?: $goal_label;
			$session_spec['pipeline_memory']   = $memory_spec;
			$session_spec['next_best_actions'] = [ 'Tạo nội dung', 'Kiểm tra kết quả' ];

			BizCity_Session_Memory_Spec::persist( $session_id, $session_spec );
			$saved_to[] = 'session';
			error_log( self::LOG_PREFIX . ' Saved to session_memory_spec' );
		}

		// ── Layer 2: Task params.meta.memory_spec ──
		if ( class_exists( 'BizCity_Memory_Spec' ) && $taskId ) {
			$task_id_int = (int) $taskId;
			if ( $task_id_int > 0 ) {
				BizCity_Memory_Spec::persist( $task_id_int, $memory_spec );
				$saved_to[] = 'task';
				error_log( self::LOG_PREFIX . ' Saved to bizcity_tasks.params.meta' );
			}
		}

		// ── Layer 3: Save to intent_conversations via proper API ──
		if ( class_exists( 'BizCity_Memory_Spec' ) && ! empty( $conv_id ) ) {
			BizCity_Memory_Spec::save_session( $conv_id, $memory_spec );
			$saved_to[] = 'conversation';
			error_log( self::LOG_PREFIX . ' Saved to intent_conversations via save_session' );
		} elseif ( ! empty( $conv_id ) ) {
			// Fallback: direct insert if class not available
			$this->create_conversation_snapshot( $session_id, $user_id, $conv_id, $pipeline_id, $memory_spec );
			$saved_to[] = 'conversation';
		}

		// ── Layer 4: Create bizcity_mem_draft CPT post for user review ──
		$draft_post_id  = 0;
		$draft_post_url = '';
		$draft_result   = $this->create_memory_draft_post( (int) $taskId, $memory_spec, [
			'user_id'    => $user_id,
			'session_id' => $session_id,
		] );
		if ( ! empty( $draft_result['post_id'] ) ) {
			$draft_post_id  = $draft_result['post_id'];
			$draft_post_url = $draft_result['url'];
			$saved_to[]     = 'cpt';
			error_log( self::LOG_PREFIX . ' Saved to bizcity_mem_draft post_id=' . $draft_post_id );
		}

		// Layer 1b removed — BizCity_Session_Memory_Spec::persist() already sets mode + updated_at

		// ── Layer 5: Phase 1.15 — Persistent Memory Spec (bizcity_memory_specs table) ──
		// Nguyên tắc: Ngón 4 (it_call_memory) là canonical memory writer cho pipeline mode.
		// Layer 1-4 = ephemeral (session/task/conversation/CPT) — per-execution, internal.
		// Layer 5 = persistent (bizcity_memory_specs) — cross-session, user-editable, UI-browsable.
		//
		// Memory Lifecycle position: UPDATE (Ngón 4)
		// - CREATE:   Shell Step 3.5 (load_or_create) — đã tạo notebook
		// - UPDATE:   HERE — cập nhật Sources, Notes, Context từ upstream data
		// - FINALIZE: finalize() hoặc Ngón 8 (it_summary_verifier)
		if ( class_exists( 'BizCity_Memory_Manager' ) ) {
			$_p115_mem_id = 0;

			// Try to find memory_id from pipeline context (injected by Shell Step 3.5)
			$_pipeline_ctx = $variables['_pipeline_context'] ?? [];
			if ( ! empty( $_pipeline_ctx['memory_id'] ) ) {
				$_p115_mem_id = (int) $_pipeline_ctx['memory_id'];
			} elseif ( ! empty( $variables['_phase115_memory_id'] ) ) {
				$_p115_mem_id = (int) $variables['_phase115_memory_id'];
			}

			// Fallback: find active memory for this session
			if ( ! $_p115_mem_id && ! empty( $session_id ) ) {
				$_mgr = BizCity_Memory_Manager::instance();
				$_mem = $_mgr->load_or_create( array(
					'user_id'      => $user_id,
					'character_id' => 0,
					'session_id'   => $session_id,
					'goal'         => mb_substr( $goal_label, 0, 200, 'UTF-8' ),
				) );
				if ( $_mem ) {
					$_p115_mem_id = (int) ( $_mem['id'] ?? 0 );
				}
			}

			if ( $_p115_mem_id ) {
				$_mgr = BizCity_Memory_Manager::instance();

				// Update ## Sources from research data
				if ( ! empty( $research_summary ) ) {
					$_sources_md = '';
					if ( $source_count > 0 ) {
						$_sources_md .= '- Research: ' . $source_count . ' sources found';
						if ( ! empty( $source_ids ) ) {
							$_sources_md .= ' (ids: ' . mb_substr( $source_ids, 0, 100 ) . ')';
						}
						$_sources_md .= "\n";
					}
					$_sources_md .= '- Summary: ' . mb_substr( $research_summary, 0, 500, 'UTF-8' );
					$_mgr->update_section( $_p115_mem_id, 'Sources', $_sources_md );
				}

				// Update ## Notes from skill + context
				$_notes_parts = array();
				if ( ! empty( $skill_title ) ) {
					$_notes_parts[] = '- Skill: ' . $skill_title . ( $skill_key ? ' (' . $skill_key . ')' : '' );
				}
				if ( ! empty( $focus_context ) ) {
					$_notes_parts[] = '- Focus: ' . mb_substr( $focus_context, 0, 200, 'UTF-8' );
				}
				if ( ! empty( $plan_summary ) ) {
					$_notes_parts[] = '- Plan: ' . mb_substr( $plan_summary, 0, 200, 'UTF-8' );
				}
				if ( ! empty( $_notes_parts ) ) {
					$_mgr->update_section( $_p115_mem_id, 'Notes', implode( "\n", $_notes_parts ) );
				}

				// Update ## Current — Ngón 4 done, next → Ngón 5 (content)
				// FIX BUG #4: update_current() expects array, not two strings.
				$_mgr->update_current( $_p115_mem_id, array(
					'step' => 'it_call_memory (completed)',
					'next' => 'it_call_content',
				), 'it_call_memory' );

				$saved_to[] = 'phase115';
				error_log( self::LOG_PREFIX . ' [Phase1.15] Layer 5 → bizcity_memory_specs updated: mem_id=' . $_p115_mem_id );
			}
		}

		// ── Studio outputs entry ──
		$this->save_studio_output( $session_id, $user_id, $taskId, $memory_spec, $saved_to );

		// ── Send chat notification WITH draft link ──
		$this->notify_memory_saved( $session_id, $user_id, $channel, $goal_label, $saved_to, $memory_spec, $draft_post_url );

		// ── Trace: execute_done ──
		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_call_memory',
			'label'      => 'Memory specs saved → ' . implode( ', ', $saved_to ),
			'has_error'  => 'false',
			'session_id' => $session_id,
		], 'info', (int) $elapsed_ms );

		error_log( self::LOG_PREFIX . ' DONE saved_to=[' . implode( ',', $saved_to ) . '] draft_id=' . $draft_post_id . ' (' . $elapsed_ms . 'ms)' );

		$spec_json = wp_json_encode( $memory_spec, JSON_UNESCAPED_UNICODE );

		return [
			'result' => [
				'memory_spec'      => $spec_json,
				'memory_saved_to'  => implode( ',', $saved_to ),
				'memory_draft_url' => $draft_post_url,
				'memory_draft_id'  => (string) $draft_post_id,
			],
			'error'  => '',
			'status' => 3,
		];
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Create a context snapshot in bizcity_intent_conversations.
	 */
	private function create_conversation_snapshot( $session_id, $user_id, $conv_id, $pipeline_id, array $memory_spec ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_intent_conversations';

		// Check table exists
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			error_log( self::LOG_PREFIX . ' intent_conversations table not found — skipping snapshot' );
			return;
		}

		$snapshot = [
			'pipeline_id'  => $pipeline_id,
			'session_id'   => $session_id,
			'memory_spec'  => $memory_spec,
			'created_by'   => 'it_call_memory',
			'step'         => 'memory_save',
		];

		$wpdb->insert( $table, [
			'conversation_id'   => $conv_id ?: ( 'mem_' . uniqid( '', true ) ),
			'user_id'           => (int) $user_id,
			'session_id'        => $session_id,
			'context_snapshot'  => wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ),
			'rolling_summary'   => 'Memory specs saved for pipeline ' . $pipeline_id,
			'status'            => 'ACTIVE',
			'created_at'        => current_time( 'mysql' ),
		] );

		if ( $wpdb->last_error ) {
			error_log( self::LOG_PREFIX . ' Conversation insert error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Update session_memory_mode to "execution".
	 */
	private function update_session_memory_mode( $session_id ) {
		if ( empty( $session_id ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_webchat_sessions';

		$wpdb->update(
			$table,
			[
				'session_memory_mode'       => 'execution',
				'session_memory_updated_at' => current_time( 'mysql' ),
			],
			[ 'session_id' => $session_id ]
		);
	}

	/**
	 * Save memory spec to studio_outputs.
	 */
	private function save_studio_output( $session_id, $user_id, $task_id, array $memory_spec, array $saved_to ) {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return;
		}

		$goal = $memory_spec['goal']['label'] ?? $memory_spec['goal']['primary'] ?? '';
		$content = "**Memory Specs Saved (v2)**\n\n"
			. '- **Goal:** ' . esc_html( mb_substr( $goal, 0, 100 ) ) . "\n"
			. '- **Mode:** execution' . "\n"
			. '- **Storage:** ' . implode( ', ', $saved_to ) . "\n";

		$src_count = $memory_spec['research']['source_count'] ?? 0;
		if ( $src_count > 0 ) {
			$content .= '- **Research sources:** ' . $src_count
				. ' (' . ( $memory_spec['research']['total_chars'] ?? 0 ) . " chars)\n";
		}

		$steps_count = $memory_spec['pipeline']['total_steps'] ?? 0;
		if ( $steps_count > 0 ) {
			$content .= '- **Pipeline steps:** ' . $steps_count . "\n";
		}

		$skill_title = $memory_spec['skill']['title'] ?? '';
		if ( $skill_title ) {
			$content .= '- **Skill:** ' . esc_html( $skill_title ) . "\n";
		}

		BizCity_Output_Store::save_artifact( [
			'tool_id'    => 'it_call_memory',
			'caller'     => 'pipeline',
			'session_id' => $session_id,
			'user_id'    => (int) $user_id,
			'task_id'    => $task_id ?: null,
			'data'       => [
				'title'   => 'Memory — Specs saved',
				'content' => $content,
			],
		], 'memory' );
	}

	/**
	 * Notify user that memory specs were saved — includes full summary + CPT link.
	 */
	private function notify_memory_saved( $session_id, $user_id, $channel, $goal_label, array $saved_to, array $memory_spec = [], string $draft_url = '' ) {
		if ( empty( $session_id ) ) {
			return;
		}

		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		// Build rich summary message
		$msg = sprintf(
			"🧠 **Memory Spec đã lưu!**\n📝 Goal: \"%s\"",
			mb_substr( $goal_label, 0, 80 )
		);

		$src_count = $memory_spec['research']['source_count'] ?? 0;
		if ( $src_count > 0 ) {
			$msg .= sprintf(
				"\n🔍 Research: %d nguồn · %s chars · %d chunks",
				$src_count,
				number_format( (int) ( $memory_spec['research']['total_chars'] ?? 0 ) ),
				(int) ( $memory_spec['research']['chunk_count'] ?? 0 )
			);
		}

		$steps_count = $memory_spec['pipeline']['total_steps'] ?? 0;
		if ( $steps_count > 0 ) {
			$msg .= "\n📋 Pipeline: " . $steps_count . ' steps';
		}

		$skill_title = $memory_spec['skill']['title'] ?? '';
		if ( $skill_title ) {
			$msg .= "\n🎯 Skill: " . $skill_title;
		}

		$msg .= "\n💾 Saved → " . implode( ', ', $saved_to );

		if ( ! empty( $draft_url ) ) {
			$msg .= "\n\n[📄 Xem Memory Spec](" . esc_url_raw( $draft_url ) . ')';
		}

		BizCity_WebChat_Database::instance()->log_message( [
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => 'Memory Bot',
			'message_id'    => uniqid( 'memory_' ),
			'message_text'  => $msg,
			'message_from'  => 'bot',
			'message_type'  => 'pipeline_progress',
			'platform_type' => ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT',
			'tool_name'     => 'it_call_memory',
			'meta'          => wp_json_encode( [
				'action'    => 'memory_saved',
				'saved_to'  => $saved_to,
				'goal'      => $goal_label,
				'draft_url' => $draft_url,
			] ),
		] );
	}

	/**
	 * Create a bizcity_mem_draft CPT post with full spec for user review.
	 * Auto-cleaned by cron via bizcity_cleanup_memory_drafts.
	 *
	 * @param int   $task_id   Current task ID.
	 * @param array $spec      Full memory spec.
	 * @param array $exec_ctx  { user_id, session_id }
	 * @return array { post_id, url } or empty on failure.
	 */
	private function create_memory_draft_post( int $task_id, array $spec, array $exec_ctx ): array {
		self::maybe_register_memory_draft_cpt();

		$title = sprintf(
			'Memory Spec — Task #%d — %s',
			$task_id,
			current_time( 'Y-m-d H:i' )
		);

		// Build readable HTML content from the full spec
		$goal_label = $spec['goal']['label'] ?? ( $spec['goal']['primary'] ?? '' );

		$content  = "<h3>🎯 Goal</h3>\n";
		$content .= '<p>' . esc_html( $goal_label ) . "</p>\n";

		// Skill info
		$skill_title = $spec['skill']['title'] ?? '';
		if ( $skill_title ) {
			$content .= "<h3>🧩 Skill</h3>\n";
			$content .= '<p><strong>' . esc_html( $skill_title ) . '</strong>';
			if ( ! empty( $spec['skill']['key'] ) ) {
				$content .= ' (' . esc_html( $spec['skill']['key'] ) . ')';
			}
			$content .= "</p>\n";
		}

		// Pipeline steps
		$content .= "<h3>📋 Pipeline</h3>\n";
		$content .= '<p>Task #' . intval( $task_id ) . ' — '
			. intval( $spec['pipeline']['total_steps'] ?? 0 ) . " steps</p>\n";

		if ( ! empty( $spec['checklist'] ) ) {
			$content .= "<ol>\n";
			foreach ( $spec['checklist'] as $item ) {
				$content .= '<li><strong>' . esc_html( $item['label'] ?? '' ) . '</strong>'
					. ' (' . esc_html( $item['tool'] ?? '' ) . ')'
					. ' — ' . esc_html( $item['status'] ?? 'pending' ) . "</li>\n";
			}
			$content .= "</ol>\n";
		}

		// Research context
		$src_count = (int) ( $spec['research']['source_count'] ?? 0 );
		if ( $src_count > 0 ) {
			$content .= "<h3>🔍 Research</h3>\n";
			$content .= '<p>' . $src_count . ' nguồn · '
				. number_format( (int) ( $spec['research']['total_chars'] ?? 0 ) ) . ' chars · '
				. intval( $spec['research']['chunk_count'] ?? 0 ) . " chunks</p>\n";

			$summary = $spec['research']['research_summary'] ?? '';
			if ( $summary ) {
				$decoded = json_decode( $summary, true );
				if ( is_array( $decoded ) ) {
					$content .= "<ul>\n";
					foreach ( array_slice( $decoded, 0, 10 ) as $src ) {
						$src_title = $src['title'] ?? ( $src['url'] ?? '(no title)' );
						$src_url   = $src['url'] ?? '';
						$content  .= '<li>';
						if ( $src_url ) {
							$content .= '<a href="' . esc_url( $src_url ) . '">' . esc_html( $src_title ) . '</a>';
						} else {
							$content .= esc_html( $src_title );
						}
						$content .= "</li>\n";
					}
					$content .= "</ul>\n";
				} else {
					$content .= '<p>' . esc_html( mb_substr( $summary, 0, 500 ) ) . "</p>\n";
				}
			}
		}

		// Focus context
		$focus = $spec['context']['focus'] ?? '';
		if ( $focus && $focus !== $goal_label ) {
			$content .= "<h3>🎯 Focus Context</h3>\n";
			$content .= '<p>' . esc_html( mb_substr( $focus, 0, 300 ) ) . "</p>\n";
		}

		$content .= "<h3>📦 Raw JSON</h3>\n";
		$content .= '<pre>' . esc_html( wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . "</pre>\n";

		$post_id = wp_insert_post( [
			'post_type'    => 'bizcity_mem_draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_author'  => $exec_ctx['user_id'] ?: get_current_user_id(),
			'meta_input'   => [
				'_bizcity_task_id'    => $task_id,
				'_bizcity_session_id' => $exec_ctx['session_id'] ?? '',
				'_bizcity_spec_json'  => wp_json_encode( $spec ),
				'_bizcity_expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) ),
			],
		], true );

		if ( is_wp_error( $post_id ) ) {
			error_log( self::LOG_PREFIX . ' Cannot create memory draft: ' . $post_id->get_error_message() );
			return [];
		}

		error_log( self::LOG_PREFIX . " Memory draft created: post_id={$post_id} for task_id={$task_id}" );

		return [
			'post_id' => $post_id,
			'url'     => admin_url( 'post.php?post=' . intval( $post_id ) . '&action=edit' ),
		];
	}

	/**
	 * Register bizcity_mem_draft CPT (idempotent — safe to call multiple times).
	 */
	public static function maybe_register_memory_draft_cpt(): void {
		if ( post_type_exists( 'bizcity_mem_draft' ) ) {
			return;
		}
		register_post_type( 'bizcity_mem_draft', [
			'labels' => [
				'name'          => 'Memory Drafts',
				'singular_name' => 'Memory Draft',
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'supports'        => [ 'title', 'editor', 'custom-fields' ],
			'capability_type' => 'post',
		] );
	}

	/**
	 * Resolve {{node#X.var}} variables in a string.
	 */
	public function replaceVariables( $text, $variables ) {
		if ( strpos( $text, '{{' ) === false ) {
			return $text;
		}

		return preg_replace_callback( '/\{\{(node#\d+)\.(\w+)\}\}/', function ( $m ) use ( $variables ) {
			$node_key = $m[1];
			$var_key  = $m[2];
			if ( isset( $variables[ $node_key ][ $var_key ] ) ) {
				return $variables[ $node_key ][ $var_key ];
			}
			if ( isset( $variables[ $node_key ] ) && is_string( $variables[ $node_key ] ) ) {
				return $variables[ $node_key ];
			}
			return $m[0];
		}, $text );
	}
}
