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
			'memory_spec'     => __( 'JSON memory spec saved', 'bizcity-twin-ai' ),
			'memory_saved_to' => __( 'Comma list of storage layers (session, task, conversation)', 'bizcity-twin-ai' ),
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

		// ── Gather context from previous nodes ──
		$research_summary = '';
		$source_count     = 0;
		$plan_summary     = '';

		// Scan node# variables for research and plan data
		foreach ( $variables as $key => $val ) {
			if ( ! is_array( $val ) || strpos( $key, 'node#' ) !== 0 ) {
				continue;
			}
			// From it_call_research
			if ( ! empty( $val['research_summary'] ) ) {
				$research_summary = $val['research_summary'];
				$source_count     = (int) ( $val['source_count'] ?? 0 );
			}
			// From it_todos_planner
			if ( ! empty( $val['plan_summary'] ) ) {
				$plan_summary = $val['plan_summary'];
			}
		}

		// Auto-fill goal_label from trigger if empty
		if ( empty( $goal_label ) && ! empty( $variables['node#1']['text'] ) ) {
			$goal_label = $variables['node#1']['text'];
		}

		// ── Build memory spec ──
		$memory_spec = [
			'version'     => 1,
			'scope'       => 'pipeline_execution',
			'pipeline_id' => $pipeline_id,
			'session_id'  => $session_id,
			'goal'        => [
				'primary' => mb_substr( $goal_label, 0, 200 ),
				'label'   => $goal_label,
			],
			'context'     => [
				'focus'            => $focus_context ?: $goal_label,
				'research_sources' => $source_count,
				'research_summary' => $research_summary,
				'plan'             => $plan_summary,
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

		// ── Layer 3: Insert intent_conversations snapshot ──
		$this->create_conversation_snapshot( $session_id, $user_id, $conv_id, $pipeline_id, $memory_spec );
		$saved_to[] = 'conversation';

		// ── Layer 1b: Update session_memory_mode to "execution" ──
		$this->update_session_memory_mode( $session_id );

		// ── Studio outputs entry ──
		$this->save_studio_output( $session_id, $user_id, $taskId, $memory_spec, $saved_to );

		// ── Send chat notification ──
		$this->notify_memory_saved( $session_id, $user_id, $channel, $goal_label, $saved_to );

		// ── Trace: execute_done ──
		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_call_memory',
			'label'      => 'Memory specs saved → ' . implode( ', ', $saved_to ),
			'has_error'  => 'false',
			'session_id' => $session_id,
		], 'info', (int) $elapsed_ms );

		error_log( self::LOG_PREFIX . ' DONE saved_to=[' . implode( ',', $saved_to ) . '] (' . $elapsed_ms . 'ms)' );

		$spec_json = wp_json_encode( $memory_spec, JSON_UNESCAPED_UNICODE );

		return [
			'result' => [
				'memory_spec'     => $spec_json,
				'memory_saved_to' => implode( ',', $saved_to ),
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
			'conversation_uuid' => $conv_id ?: ( 'mem_' . uniqid( '', true ) ),
			'user_id'           => (int) $user_id,
			'context_snapshot'  => wp_json_encode( $snapshot, JSON_UNESCAPED_UNICODE ),
			'output_summary'    => 'Memory specs saved for pipeline ' . $pipeline_id,
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
				'session_memory_mode' => 'execution',
				'updated_at'          => current_time( 'mysql' ),
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
		$content = "**Memory Specs Saved**\n\n"
			. '- **Goal:** ' . esc_html( mb_substr( $goal, 0, 100 ) ) . "\n"
			. '- **Mode:** execution' . "\n"
			. '- **Storage:** ' . implode( ', ', $saved_to ) . "\n";

		$src_count = $memory_spec['context']['research_sources'] ?? 0;
		if ( $src_count > 0 ) {
			$content .= '- **Research sources:** ' . $src_count . "\n";
		}

		$plan = $memory_spec['context']['plan'] ?? '';
		if ( $plan ) {
			$content .= '- **Plan:** ' . esc_html( mb_substr( $plan, 0, 100 ) ) . "\n";
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
	 * Notify user that memory specs were saved.
	 */
	private function notify_memory_saved( $session_id, $user_id, $channel, $goal_label, array $saved_to ) {
		if ( empty( $session_id ) ) {
			return;
		}

		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		$msg = sprintf(
			'🧠 **Memory specs đã lưu!** Goal: "%s" → %s',
			mb_substr( $goal_label, 0, 60 ),
			implode( ', ', $saved_to )
		);

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
				'action'   => 'memory_saved',
				'saved_to' => $saved_to,
				'goal'     => $goal_label,
			] ),
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
