<?php
/**
 * BizCity Twin AI — Action Block: Pipeline TODOs Planner
 *
 * Auto-injected as the first action node after trigger in generated pipelines.
 * Creates N rows in bizcity_intent_todos (one per subsequent action step),
 * then sends a plan-start message to the user's chat session.
 *
 * Phase 1.1 — G2: TODOs tracking for pipeline progress.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @since      3.9.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicAction_it_todos_planner extends WaicAction {
	protected $_code  = 'it_todos_planner';
	protected $_order = 0;

	public function __construct( $block = null ) {
		$this->_name = __( '📋 TODOs Planner — Lập kế hoạch', 'bizcity-twin-ai' );
		$this->_desc = __( 'Tạo danh sách TODO cho pipeline và gửi tin nhắn kế hoạch cho người dùng.', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	public function setSettings() {
		$this->_settings = [
			'steps_json' => [
				'type'      => 'textarea',
				'label'     => __( 'Steps JSON', 'bizcity-twin-ai' ),
				'default'   => '[]',
				'desc'      => __( 'JSON array of steps: [{"tool_name":"...","label":"..."}]', 'bizcity-twin-ai' ),
				'variables' => true,
				'rows'      => 4,
			],
			'pipeline_label' => [
				'type'      => 'input',
				'label'     => __( 'Pipeline Label', 'bizcity-twin-ai' ),
				'default'   => '',
				'variables' => true,
			],
		];
	}

	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = [
			'todo_count'   => __( 'Number of TODOs created', 'bizcity-twin-ai' ),
			'pipeline_id'  => __( 'Pipeline ID', 'bizcity-twin-ai' ),
			'plan_summary' => __( 'Plan summary text', 'bizcity-twin-ai' ),
		];
	}

	public function getResults( $taskId, $variables, $step = 0 ) {
		$steps_raw = $this->replaceVariables( $this->getParam( 'steps_json', '[]' ), $variables );
		$label     = $this->replaceVariables( $this->getParam( 'pipeline_label', '' ), $variables );

		$steps = json_decode( $steps_raw, true );
		if ( ! is_array( $steps ) || empty( $steps ) ) {
			return [
				'result'     => [ 'todo_count' => 0, 'pipeline_id' => '', 'plan_summary' => '' ],
				'todo_count' => 0,
			];
		}

		// Get pipeline_id from execution state
		$execution_state = $this->getExecutionState( $variables );
		// Use ?: (falsy coalesce) so empty string '' also triggers fallback generation
		$pipeline_id     = $execution_state['pipeline_id'] ?: ( 'pipe_' . $taskId . '_' . time() );
		$user_id         = $execution_state['user_id'] ?: get_current_user_id();

		// B1 fix: check if todos already exist for this pipeline_id (bridge may have pre-created)
		$existing_count = 0;
		if ( class_exists( 'BizCity_Intent_Todos' ) ) {
			$existing = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
			$existing_count = is_array( $existing ) ? count( $existing ) : 0;
		}

		// Create TODO rows only if not pre-created by bridge
		$todo_count = $existing_count;
		if ( $existing_count === 0 && class_exists( 'BizCity_Intent_Todos' ) ) {
			// B1 fix: pass task_id + pipeline_version as pipeline_meta for rich mapping
			$todo_count = BizCity_Intent_Todos::create_from_plan( $pipeline_id, $steps, $user_id, [
				'task_id'          => (int) $taskId,
				'pipeline_version' => 1,
			] );
		}

		// Send plan start message to user's chat session
		$step_labels = array_column( $steps, 'label' );
		if ( class_exists( 'BizCity_Pipeline_Messenger' ) && ! empty( $execution_state['session_id'] ) ) {
			BizCity_Pipeline_Messenger::send_plan_start( $execution_state, $step_labels );
		}

		$plan_text = $label ?: ( 'Kế hoạch ' . count( $steps ) . ' bước' );

		return [
			'result' => [
				'todo_count'   => $todo_count,
				'pipeline_id'  => $pipeline_id,
				'plan_summary' => $plan_text,
			],
			'todo_count'   => $todo_count,
			'pipeline_id'  => $pipeline_id,
			'plan_summary' => $plan_text,
		];
	}

	/**
	 * Extract execution state from variables (passed through by execute-api.php).
	 */
	private function getExecutionState( $variables ) {
		return [
			'pipeline_id'            => $variables['_pipeline_id'] ?? '',
			'session_id'             => $variables['_session_id'] ?? '',
			'user_id'                => $variables['_user_id'] ?? 0,
			'channel'                => $variables['_channel'] ?? 'adminchat',
			'intent_conversation_id' => $variables['_intent_conversation_id'] ?? '',
		];
	}
}
