<?php
/**
 * BizCity Twin AI — Action Block: Pipeline TODOs Planner (v8 — Transparency)
 *
 * Auto-injected as the first action node after trigger in generated pipelines.
 * Creates N rows in bizcity_intent_todos, then runs 4 transparency checks
 * and sends a separate chat message for each:
 *
 *   MSG 1 — Skill Resolution:    find matching skill → report to user
 *   MSG 2 — Memory Spec:         build spec + CPT draft → send link
 *   MSG 3 — Knowledge Sources:   check available sources → report count
 *   MSG 4 — Memory Notes:        check pinned/saved notes → report titles
 *
 * Outputs skill_key / skill_title / skill_content so downstream nodes
 * (e.g. it_call_content) can reference {{node#2.skill_key}}.
 *
 * Phase 1.1-G2 + Phase 1.11-S8: Transparency pipeline planner.
 * Phase 1.11: Fixed source_url column, added skill link, CPT init hook.
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
			'todo_count'       => __( 'Number of TODOs created', 'bizcity-twin-ai' ),
			'pipeline_id'      => __( 'Pipeline ID', 'bizcity-twin-ai' ),
			'plan_summary'     => __( 'Plan summary text', 'bizcity-twin-ai' ),
			'skill_key'        => __( 'Resolved skill key/path', 'bizcity-twin-ai' ),
			'skill_title'      => __( 'Resolved skill title', 'bizcity-twin-ai' ),
			'skill_content'    => __( 'Resolved skill body (markdown)', 'bizcity-twin-ai' ),
			'source_count'     => __( 'Number of knowledge sources available', 'bizcity-twin-ai' ),
			'note_count'       => __( 'Number of memory notes found', 'bizcity-twin-ai' ),
			'memory_spec_url'  => __( 'URL to memory spec draft post', 'bizcity-twin-ai' ),
			'prompt_draft_url' => __( 'URL to prompt draft post (skill + spec preview)', 'bizcity-twin-ai' ),
		];
	}

	/* ================================================================
	 *  Main execution
	 * ================================================================ */

	public function getResults( $taskId, $variables, $step = 0 ) {
		$start_time = microtime( true );

		$steps_raw = $this->replaceVariables( $this->getParam( 'steps_json', '[]' ), $variables );
		$label     = $this->replaceVariables( $this->getParam( 'pipeline_label', '' ), $variables );

		$steps = json_decode( $steps_raw, true );
		if ( ! is_array( $steps ) || empty( $steps ) ) {
			return [
				'result'     => [ 'todo_count' => 0, 'pipeline_id' => '', 'plan_summary' => '' ],
				'todo_count' => 0,
			];
		}

		// ── Trace: execute_start ──
		$session_id = $variables['_session_id'] ?? '';
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_start', [
			'node_code'  => 'it_todos_planner',
			'label'      => 'Đang lập kế hoạch ' . count( $steps ) . ' bước...',
			'session_id' => $session_id,
		], 'info', 0 );

		// Get pipeline_id from execution state
		$execution_state = $this->getExecutionState( $variables );
		$pipeline_id     = $execution_state['pipeline_id'] ?: ( 'pipe_' . $taskId . '_' . time() );
		$user_id         = $execution_state['user_id'] ?: get_current_user_id();
		$user_message    = $variables['node#1']['text'] ?? ( $variables['text'] ?? '' );

		// ── A. Create TODO rows ──
		$existing_count = 0;
		if ( class_exists( 'BizCity_Intent_Todos' ) ) {
			$existing = BizCity_Intent_Todos::get_pipeline_todos( $pipeline_id );
			$existing_count = is_array( $existing ) ? count( $existing ) : 0;
		}

		$todo_count = $existing_count;
		if ( $existing_count === 0 && class_exists( 'BizCity_Intent_Todos' ) ) {
			$todo_count = BizCity_Intent_Todos::create_from_plan( $pipeline_id, $steps, $user_id, [
				'task_id'          => (int) $taskId,
				'pipeline_version' => 1,
			] );
		}

		$has_messenger = class_exists( 'BizCity_Pipeline_Messenger' ) && ! empty( $execution_state['session_id'] );

		// ════════════════════════════════════════════
		//  MSG 1: Skill Resolution
		// ════════════════════════════════════════════
		$skill_result = $this->resolve_skill( $steps, $user_message, $execution_state, $has_messenger );

		// ════════════════════════════════════════════
		//  MSG 2: Memory Spec + CPT Draft
		// ════════════════════════════════════════════
		$memory_result = $this->build_memory_spec_and_draft( $taskId, $steps, $skill_result, $execution_state, $has_messenger );

		// ════════════════════════════════════════════
		//  MSG 3: Knowledge Sources Check
		// ════════════════════════════════════════════
		$source_result = $this->check_knowledge_sources( $execution_state, $has_messenger );

		// ════════════════════════════════════════════
		//  MSG 4: Memory Notes Check
		// ════════════════════════════════════════════
		$notes_result = $this->check_memory_notes( $taskId, $memory_result['spec'] ?? null, $execution_state, $has_messenger );

		// ── Send plan start message (after 4 transparency messages) ──
		$step_labels = array_column( $steps, 'label' );
		if ( $has_messenger ) {
			BizCity_Pipeline_Messenger::send_plan_start( $execution_state, $step_labels );
		}

		$plan_text = $label ?: ( 'Kế hoạch ' . count( $steps ) . ' bước' );

		// ── Studio output entry ──
		$this->save_plan_studio_output( $session_id, $execution_state['user_id'], $taskId, $todo_count, $steps, $plan_text );

		// ── Trace: execute_done ──
		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_todos_planner',
			'label'      => sprintf( 'Plan — %d bước | skill=%s | sources=%d | notes=%d', $todo_count, $skill_result['skill_title'], $source_result['source_count'], $notes_result['note_count'] ),
			'has_error'  => 'false',
			'session_id' => $session_id,
		], 'info', (int) $elapsed_ms );

		return [
			'result' => [
				'todo_count'       => $todo_count,
				'pipeline_id'      => $pipeline_id,
				'plan_summary'     => $plan_text,
				'skill_key'        => $skill_result['skill_key'],
				'skill_title'      => $skill_result['skill_title'],
				'skill_content'    => $skill_result['skill_content'],
				'source_count'     => $source_result['source_count'],
				'note_count'       => $notes_result['note_count'],
				'memory_spec_url'  => $memory_result['memory_spec_url'],
				'prompt_draft_url' => $memory_result['prompt_draft_url'],
			],
			'todo_count'       => $todo_count,
			'pipeline_id'      => $pipeline_id,
			'plan_summary'     => $plan_text,
			'skill_key'        => $skill_result['skill_key'],
			'skill_title'      => $skill_result['skill_title'],
			'skill_content'    => $skill_result['skill_content'],
			'source_count'     => $source_result['source_count'],
			'note_count'       => $notes_result['note_count'],
			'memory_spec_url'  => $memory_result['memory_spec_url'],
			'prompt_draft_url' => $memory_result['prompt_draft_url'],
		];
	}

	/* ================================================================
	 *  MSG 1 — Skill Resolution
	 * ================================================================ */

	private function resolve_skill( array $steps, string $user_message, array $exec_state, bool $has_messenger ): array {
		$result = [ 'skill_key' => '', 'skill_title' => 'none', 'skill_content' => '' ];

		if ( ! class_exists( 'BizCity_Skill_Manager' ) ) {
			if ( $has_messenger ) {
				BizCity_Pipeline_Messenger::send( $exec_state,
					"🎯 **Skill**: ⚠️ Skill Manager chưa sẵn sàng — bỏ qua skill resolution.",
					'info', [ 'tool_name' => 'it_todos_planner', 'step_code' => 'resolve_skill' ]
				);
			}
			return $result;
		}

		$user_id = (int) ( $exec_state['user_id'] ?? 0 );

		// ── 1. Slash command → DB exact lookup ──
		$slash = $exec_state['slash_command'] ?? '';
		if ( ! empty( $slash ) && class_exists( 'BizCity_Skill_Database' ) ) {
			$slash_clean = ltrim( $slash, '/' );
			$row = BizCity_Skill_Database::instance()->get_by_slash_command( $slash_clean );

			if ( $row ) {
				$result['skill_key']     = $row['skill_key'] ?? '';
				$result['skill_title']   = $row['title'] ?? 'Untitled';
				$result['skill_content'] = $row['content'] ?? '';

				$skill_url = $this->build_skill_url( $result['skill_key'], $row['id'] ?? 0 );
				$msg = "🎯 **Skill (slash)**: {$result['skill_title']}\n"
				     . "⚡ Tìm trực tiếp qua /{$slash_clean}";
				if ( $skill_url ) {
					$msg .= "\n🔗 [Xem Skill](" . esc_url( $skill_url ) . ')';
				}

				error_log( "[IT_TODOS_PLANNER] Skill resolved via slash DB: /{$slash_clean} → {$result['skill_title']} (id={$row['id']})" );

				if ( $has_messenger ) {
					BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
						'tool_name'   => 'it_todos_planner',
						'step_code'   => 'resolve_skill',
					] );
				}

				return $result;
			}

			error_log( "[IT_TODOS_PLANNER] Slash /{$slash_clean} not found in DB, falling back to criteria matching" );
		}

		// ── 2. Extract tool names from pipeline steps ──
		$tool_names = [];
		$content_tool = '';
		$mode = 'content';
		foreach ( $steps as $s ) {
			$tool_name = $s['tool_name'] ?? '';
			if ( $tool_name ) {
				$tool_names[] = $tool_name;
			}
			if ( $tool_name === 'it_call_content' ) {
				$content_tool = 'generate_blog_content';
			}
			if ( strpos( $tool_name, 'research' ) !== false ) {
				$mode = 'research_and_content';
			}
		}

		// ── 3. Criteria-based matching (SQL scorer) ──
		$criteria = [
			'mode'          => $mode,
			'goal'          => $content_tool ?: 'content',
			'tool'          => $content_tool,
			'message'       => $user_message,
			'user_id'       => $user_id,
			'slash_command'  => ltrim( $slash, '/' ),
			'limit'         => 3,
		];

		$t_start  = microtime( true );
		$matches  = BizCity_Skill_Manager::instance()->find_matching( $criteria );
		$duration = (int) ( ( microtime( true ) - $t_start ) * 1000 );

		if ( ! empty( $matches ) ) {
			$best = $matches[0];
			$result['skill_key']     = $best['path'] ?? ( $best['skill_id'] ?? '' );
			$result['skill_title']   = $best['frontmatter']['title'] ?? 'Untitled';
			$result['skill_content'] = $best['content'] ?? '';

			$score   = $best['score'] ?? 0;
			$reasons = implode( ', ', $best['reasons'] ?? [] );

			$skill_url = $this->build_skill_url( $result['skill_key'], $best['skill_id'] ?? 0 );
			$msg = "🎯 **Skill tìm thấy**: {$result['skill_title']}\n"
			     . "📊 Score: {$score} — {$reasons}";
			if ( $skill_url ) {
				$msg .= "\n🔗 [Xem Skill](" . esc_url( $skill_url ) . ')';
			}
			if ( count( $matches ) > 1 ) {
				$msg .= "\n📋 Còn " . ( count( $matches ) - 1 ) . ' skill khác phù hợp';
			}

			error_log( "[IT_TODOS_PLANNER] Skill resolved: {$result['skill_title']} (score={$score}, {$duration}ms)" );

			if ( $has_messenger ) {
				BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
					'tool_name'   => 'it_todos_planner',
					'step_code'   => 'resolve_skill',
					'duration_ms' => $duration,
				] );
			}
			return $result;
		}

		// ── 4. Fallback: tool-map binding (bizcity_skill_tool_map) ──
		if ( class_exists( 'BizCity_Skill_Tool_Map' ) && ! empty( $tool_names ) ) {
			$map = BizCity_Skill_Tool_Map::instance();
			foreach ( $tool_names as $tn ) {
				$row = $map->resolve_skill_for_tool( $tn, $user_id );
				if ( $row ) {
					$result['skill_key']     = $row['skill_key'] ?? ( 'sql://' . ( $row['id'] ?? '' ) );
					$result['skill_title']   = $row['title'] ?? 'Untitled';
					$result['skill_content'] = $row['content'] ?? '';

					$skill_url = $this->build_skill_url( $result['skill_key'], $row['id'] ?? 0 );
					$msg = "🎯 **Skill (tool-map)**: {$result['skill_title']}\n"
					     . "🔧 Tìm qua tool binding: {$tn} → {$result['skill_key']}";
					if ( $skill_url ) {
						$msg .= "\n🔗 [Xem Skill](" . esc_url( $skill_url ) . ')';
					}

					error_log( "[IT_TODOS_PLANNER] Skill via tool-map: {$tn} → {$result['skill_title']} ({$duration}ms)" );

					if ( $has_messenger ) {
						BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
							'tool_name'   => 'it_todos_planner',
							'step_code'   => 'resolve_skill',
							'duration_ms' => $duration,
						] );
					}
					return $result;
				}
			}
		}

		// ── 5. No skill found ──
		$msg = "🎯 **Skill**: ⚠️ Không tìm thấy skill phù hợp.\n"
		     . "🔎 Đã tìm: mode={$mode}, tool={$content_tool}, tools=[" . implode( ',', $tool_names ) . "]\n"
		     . "💡 Content sẽ dùng prompt mặc định (không có skill injection).";

		error_log( "[IT_TODOS_PLANNER] No matching skill: mode={$mode}, tool={$content_tool}" );

		if ( $has_messenger ) {
			BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
				'tool_name'   => 'it_todos_planner',
				'step_code'   => 'resolve_skill',
				'duration_ms' => $duration,
			] );
		}

		return $result;
	}

	/* ================================================================
	 *  MSG 2 — Memory Spec + CPT Draft
	 * ================================================================ */

	private function build_memory_spec_and_draft( int $taskId, array $steps, array $skill_result, array $exec_state, bool $has_messenger ): array {
		$result = [ 'memory_spec_url' => '', 'prompt_draft_url' => '' ];
		$spec   = null;

		// ── A. Build memory spec via BizCity_Memory_Spec ──
		if ( class_exists( 'BizCity_Memory_Spec' ) ) {
			$scenario_nodes = [];
			foreach ( $steps as $i => $s ) {
				$scenario_nodes[] = [
					'id'   => $s['node_id'] ?? (string) ( $i + 3 ),
					'type' => 'action',
					'data' => [
						'label' => $s['label'] ?? '',
						'tool'  => $s['tool_name'] ?? '',
					],
				];
			}

			$scenario = [
				'nodes'    => $scenario_nodes,
				'settings' => [
					'pipeline_id' => $exec_state['pipeline_id'] ?? '',
					'description' => $skill_result['skill_title'] !== 'none'
						? 'Pipeline with skill: ' . $skill_result['skill_title']
						: 'Pipeline ' . count( $steps ) . ' steps',
					'goal' => $skill_result['skill_key'] ?: 'workflow',
				],
			];

			$context = [
				'user_id'    => $exec_state['user_id'] ?? 0,
				'session_id' => $exec_state['session_id'] ?? '',
				'channel'    => $exec_state['channel'] ?? 'adminchat',
			];

			$spec = BizCity_Memory_Spec::build_from_pipeline( (int) $taskId, $scenario, $context );

			// Inject skill info into spec
			if ( $skill_result['skill_key'] ) {
				$spec['skill'] = [
					'key'   => $skill_result['skill_key'],
					'title' => $skill_result['skill_title'],
				];
				BizCity_Memory_Spec::persist( (int) $taskId, $spec );
			}

			// ── B. Create Memory Spec CPT draft ──
			$draft_url = $this->create_memory_draft_post( $taskId, $spec, $exec_state );
			$result['memory_spec_url'] = $draft_url;

			// ── C. Create Prompt Draft CPT — preview of LLM prompt ──
			$prompt_url = $this->create_prompt_draft_post( $taskId, $spec, $skill_result, $exec_state );
			$result['prompt_draft_url'] = $prompt_url;
		}

		// ── D. Send MSG 2a: Memory Spec ──
		if ( $has_messenger ) {
			$spec_summary = '';
			if ( ! empty( $spec ) ) {
				$goal_label = $spec['goal']['label'] ?? ( $spec['goal']['primary'] ?? 'N/A' );
				$total      = $spec['pipeline']['total_steps'] ?? count( $steps );
				$spec_summary = "📝 Goal: {$goal_label}\n"
				              . "📋 Steps: {$total}\n";
				if ( ! empty( $spec['skill'] ) ) {
					$spec_summary .= "🎯 Skill: {$spec['skill']['title']}\n";
				}
				$spec_summary .= "📦 Saved in task #{$taskId}";
			}

			$msg = "🧠 **Memory Spec** đã tạo.\n" . $spec_summary;
			if ( ! empty( $result['memory_spec_url'] ) ) {
				$msg .= "\n[📄 Xem Memory Spec](" . esc_url( $result['memory_spec_url'] ) . ')';
			}

			BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
				'tool_name' => 'it_todos_planner',
				'step_code' => 'memory_spec',
			] );

			// ── MSG 2b: Prompt Draft (if skill found) ──
			if ( ! empty( $result['prompt_draft_url'] ) ) {
				$prompt_msg = "📝 **Prompt Draft** đã tạo — bao gồm skill instructions + context.\n"
				            . "[📄 Xem Prompt gửi LLM](" . esc_url( $result['prompt_draft_url'] ) . ')';
				BizCity_Pipeline_Messenger::send( $exec_state, $prompt_msg, 'info', [
					'tool_name' => 'it_todos_planner',
					'step_code' => 'prompt_draft',
				] );
			}
		}

		$result['spec'] = $spec;
		return $result;
	}

	/**
	 * Create a Prompt Draft CPT — preview of the prompt that will be sent to LLM.
	 * Combines: skill content (markdown instructions) + memory spec context.
	 */
	private function create_prompt_draft_post( int $task_id, array $spec, array $skill_result, array $exec_state ): string {
		if ( empty( $skill_result['skill_content'] ) && empty( $spec ) ) {
			return '';
		}

		self::maybe_register_memory_draft_cpt();

		$skill_title = $skill_result['skill_title'] ?? 'none';
		$title = sprintf( 'Prompt Draft — %s — Task #%d — %s', $skill_title, $task_id, current_time( 'Y-m-d H:i' ) );

		$content = '';

		// Section 1: Skill instructions (the main LLM prompt body)
		if ( ! empty( $skill_result['skill_content'] ) ) {
			$content .= "<h2>🎯 Skill Instructions</h2>\n";
			$content .= '<p><strong>Skill:</strong> ' . esc_html( $skill_title ) . ' (<code>' . esc_html( $skill_result['skill_key'] ) . "</code>)</p>\n";
			$content .= "<div class=\"bizcity-prompt-skill\">\n<pre style=\"white-space:pre-wrap;\">" . esc_html( $skill_result['skill_content'] ) . "</pre>\n</div>\n\n";
		}

		// Section 2: Memory Spec context
		$content .= "<h2>🧠 Memory Spec Context</h2>\n";
		$goal_label = $spec['goal']['label'] ?? ( $spec['goal']['primary'] ?? 'N/A' );
		$content .= '<p><strong>Goal:</strong> ' . esc_html( $goal_label ) . "</p>\n";
		$content .= '<p><strong>Steps:</strong> ' . intval( $spec['pipeline']['total_steps'] ?? 0 ) . "</p>\n";

		if ( ! empty( $spec['checklist'] ) ) {
			$content .= "<h3>Pipeline Checklist</h3>\n<ol>\n";
			foreach ( $spec['checklist'] as $item ) {
				$content .= '<li>' . esc_html( $item['label'] ) . ' — <code>' . esc_html( $item['tool'] ) . "</code></li>\n";
			}
			$content .= "</ol>\n";
		}

		// Section 3: Notes (if any in spec)
		if ( ! empty( $spec['notes'] ) ) {
			$content .= "<h2>📝 Memory Notes</h2>\n<ul>\n";
			foreach ( $spec['notes'] as $note ) {
				$content .= '<li><strong>' . esc_html( $note['title'] ) . '</strong>: ' . esc_html( $note['excerpt'] ?? '' ) . "</li>\n";
			}
			$content .= "</ul>\n";
		}

		// Section 4: Raw spec JSON (collapsible)
		$content .= "<h2>📦 Raw Spec JSON</h2>\n";
		$content .= '<pre style="max-height:300px;overflow:auto;">' . esc_html( wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . "</pre>\n";

		$post_id = wp_insert_post( [
			'post_type'    => 'bizcity_mem_draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_author'  => $exec_state['user_id'] ?: get_current_user_id(),
			'meta_input'   => [
				'_bizcity_task_id'    => $task_id,
				'_bizcity_session_id' => $exec_state['session_id'] ?? '',
				'_bizcity_draft_type' => 'prompt_draft',
				'_bizcity_skill_key'  => $skill_result['skill_key'] ?? '',
				'_bizcity_spec_json'  => wp_json_encode( $spec ),
				'_bizcity_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			],
		], true );

		if ( is_wp_error( $post_id ) ) {
			error_log( '[IT_TODOS_PLANNER] Cannot create prompt draft: ' . $post_id->get_error_message() );
			return '';
		}

		error_log( "[IT_TODOS_PLANNER] Prompt draft created: post_id={$post_id} for task_id={$task_id} skill={$skill_title}" );

		return admin_url( 'post.php?post=' . intval( $post_id ) . '&action=edit' );
	}

	/**
	 * Create a temporary draft CPT post for user preview.
	 * Auto-cleaned by cron via cleanup_expired_drafts().
	 */
	private function create_memory_draft_post( int $task_id, array $spec, array $exec_state ): string {
		self::maybe_register_memory_draft_cpt();

		$title = sprintf( 'Memory Spec — Task #%d — %s', $task_id, current_time( 'Y-m-d H:i' ) );

		// Format spec as readable HTML content
		$content  = "<h3>Goal</h3>\n";
		$content .= '<p>' . esc_html( $spec['goal']['label'] ?? ( $spec['goal']['primary'] ?? '' ) ) . "</p>\n";
		$content .= "<h3>Pipeline</h3>\n";
		$content .= '<p>Task #' . intval( $task_id ) . ' — ' . intval( $spec['pipeline']['total_steps'] ?? 0 ) . " steps</p>\n";

		if ( ! empty( $spec['checklist'] ) ) {
			$content .= "<h3>Checklist</h3>\n<ol>\n";
			foreach ( $spec['checklist'] as $item ) {
				$content .= '<li><strong>' . esc_html( $item['label'] ) . '</strong> (' . esc_html( $item['tool'] ) . ') — ' . esc_html( $item['status'] ) . "</li>\n";
			}
			$content .= "</ol>\n";
		}

		if ( ! empty( $spec['skill'] ) ) {
			$content .= "<h3>Skill</h3>\n";
			$content .= '<p>' . esc_html( $spec['skill']['title'] ) . ' (' . esc_html( $spec['skill']['key'] ) . ")</p>\n";
		}

		$content .= "<h3>Raw JSON</h3>\n";
		$content .= '<pre>' . esc_html( wp_json_encode( $spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . "</pre>\n";

		$post_id = wp_insert_post( [
			'post_type'    => 'bizcity_mem_draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => 'draft',
			'post_author'  => $exec_state['user_id'] ?: get_current_user_id(),
			'meta_input'   => [
				'_bizcity_task_id'    => $task_id,
				'_bizcity_session_id' => $exec_state['session_id'] ?? '',
				'_bizcity_spec_json'  => wp_json_encode( $spec ),
				'_bizcity_expires_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			],
		], true );

		if ( is_wp_error( $post_id ) ) {
			error_log( '[IT_TODOS_PLANNER] Cannot create memory draft: ' . $post_id->get_error_message() );
			return '';
		}

		error_log( "[IT_TODOS_PLANNER] Memory draft created: post_id={$post_id} for task_id={$task_id}" );

		return admin_url( 'post.php?post=' . intval( $post_id ) . '&action=edit' );
	}

	/**
	 * Register bizcity_mem_draft CPT (called inline before wp_insert_post).
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
	 * Cron callback: clean expired memory draft posts (older than 24 h).
	 * Hook via: add_action( 'bizcity_cleanup_memory_drafts', [ 'WaicAction_it_todos_planner', 'cleanup_expired_drafts' ] );
	 */
	public static function cleanup_expired_drafts(): int {
		$posts = get_posts( [
			'post_type'      => 'bizcity_mem_draft',
			'post_status'    => 'draft',
			'meta_query'     => [ [
				'key'     => '_bizcity_expires_at',
				'value'   => current_time( 'mysql', true ),
				'compare' => '<',
				'type'    => 'DATETIME',
			] ],
			'posts_per_page' => 50,
			'fields'         => 'ids',
		] );

		$deleted = 0;
		foreach ( $posts as $pid ) {
			if ( wp_delete_post( $pid, true ) ) {
				$deleted++;
			}
		}

		if ( $deleted > 0 ) {
			error_log( "[IT_TODOS_PLANNER] Cleaned {$deleted} expired memory drafts." );
		}
		return $deleted;
	}

	/* ================================================================
	 *  MSG 3 — Knowledge Sources Check
	 * ================================================================ */

	private function check_knowledge_sources( array $exec_state, bool $has_messenger ): array {
		$result     = [ 'source_count' => 0 ];
		$session_id = $exec_state['session_id'] ?? '';
		$user_id    = $exec_state['user_id'] ?? 0;

		if ( empty( $session_id ) && empty( $user_id ) ) {
			if ( $has_messenger ) {
				BizCity_Pipeline_Messenger::send( $exec_state,
					"📚 **Knowledge Sources**: ⚠️ Không xác định được session — bỏ qua.",
					'info', [ 'tool_name' => 'it_todos_planner', 'step_code' => 'check_sources' ]
				);
			}
			return $result;
		}

		global $wpdb;

		// --- 1. Session sources (bizcity_webchat_sources) ---
		$ws_table   = $wpdb->prefix . 'bizcity_webchat_sources';
		$ws_count   = 0;
		$ws_sources = [];

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ws_table ) ) ) {
			if ( ! empty( $session_id ) ) {
				$ws_count   = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$ws_table} WHERE session_id = %s", $session_id
				) );
				$ws_sources = $ws_count > 0
					? $wpdb->get_results( $wpdb->prepare(
						"SELECT title, source_url, source_type FROM {$ws_table} WHERE session_id = %s ORDER BY id DESC LIMIT 5", $session_id
					  ), ARRAY_A )
					: [];
			} else {
				$ws_count   = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$ws_table} WHERE user_id = %d", (int) $user_id
				) );
				$ws_sources = $ws_count > 0
					? $wpdb->get_results( $wpdb->prepare(
						"SELECT title, source_url, source_type FROM {$ws_table} WHERE user_id = %d ORDER BY id DESC LIMIT 5", (int) $user_id
					  ), ARRAY_A )
					: [];
			}
		}

		// --- 2. Admin knowledge sources (bizcity_knowledge_sources) ---
		$ks_table   = $wpdb->prefix . 'bizcity_knowledge_sources';
		$ks_count   = 0;
		$ks_sources = [];

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ks_table ) ) ) {
			$character_id = 0;
			if ( ! empty( $session_id ) && class_exists( 'BizCity_WebChat_Database' ) ) {
				$session_row  = BizCity_WebChat_Database::instance()->get_session_v3_by_session_id( $session_id );
				$character_id = (int) ( $session_row->character_id ?? 0 );
			}
			if ( $character_id ) {
				$ks_count   = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$ks_table} WHERE character_id = %d AND status = 'ready'", $character_id
				) );
				$ks_sources = $ks_count > 0
					? $wpdb->get_results( $wpdb->prepare(
						"SELECT source_name AS title, source_url, source_type FROM {$ks_table} WHERE character_id = %d AND status = 'ready' ORDER BY id DESC LIMIT 5", $character_id
					  ), ARRAY_A )
					: [];
			}
		}

		$total_count = $ws_count + $ks_count;
		$result['source_count'] = $total_count;

		if ( $has_messenger ) {
			if ( $total_count > 0 ) {
				$msg = "📚 **Knowledge Sources**: {$total_count} nguồn có sẵn\n";

				if ( $ks_count > 0 ) {
					$msg .= "\n**📖 Nguồn admin** ({$ks_count}):\n";
					foreach ( (array) $ks_sources as $src ) {
						$title = mb_substr( $src['title'] ?? 'Untitled', 0, 60 );
						$type  = $src['source_type'] ?? 'manual';
						$url   = $src['source_url'] ?? '';
						if ( $url ) {
							$msg .= "  • [{$title}](" . esc_url( $url ) . ") ({$type})\n";
						} else {
							$msg .= "  • {$title} ({$type})\n";
						}
					}
					if ( $ks_count > 5 ) {
						$msg .= '  … và ' . ( $ks_count - 5 ) . " nguồn khác\n";
					}
				}

				if ( $ws_count > 0 ) {
					$msg .= "\n**💬 Nguồn phiên chat** ({$ws_count}):\n";
					foreach ( (array) $ws_sources as $src ) {
						$title = mb_substr( $src['title'] ?? 'Untitled', 0, 60 );
						$type  = $src['source_type'] ?? 'web';
						$url   = $src['source_url'] ?? '';
						if ( $url ) {
							$msg .= "  • [{$title}](" . esc_url( $url ) . ") ({$type})\n";
						} else {
							$msg .= "  • {$title} ({$type})\n";
						}
					}
					if ( $ws_count > 5 ) {
						$msg .= '  … và ' . ( $ws_count - 5 ) . " nguồn khác\n";
					}
				}
			} else {
				$msg = "📚 **Knowledge Sources**: ⚠️ Chưa có nguồn nào.\n"
				     . "💡 Research step sẽ tự động tìm kiếm và thêm nguồn mới.";
			}

			BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
				'tool_name'    => 'it_todos_planner',
				'step_code'    => 'check_sources',
				'source_count' => $total_count,
			] );
		}

		error_log( "[IT_TODOS_PLANNER] Knowledge sources: {$total_count} found (admin={$ks_count}, session={$ws_count}, session_id={$session_id})" );

		return $result;
	}

	/* ================================================================
	 *  MSG 4 — Memory Notes Check
	 * ================================================================ */

	private function check_memory_notes( int $taskId, ?array $spec, array $exec_state, bool $has_messenger ): array {
		$result     = [ 'note_count' => 0 ];
		$session_id = $exec_state['session_id'] ?? '';

		if ( empty( $session_id ) ) {
			if ( $has_messenger ) {
				BizCity_Pipeline_Messenger::send( $exec_state,
					"📝 **Memory Notes**: ⚠️ Không xác định được session — bỏ qua.",
					'info', [ 'tool_name' => 'it_todos_planner', 'step_code' => 'check_notes' ]
				);
			}
			return $result;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_memory_notes';

		// Check if table exists
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			if ( $has_messenger ) {
				BizCity_Pipeline_Messenger::send( $exec_state,
					"📝 **Memory Notes**: ⚠️ Bảng notes chưa tồn tại.",
					'info', [ 'tool_name' => 'it_todos_planner', 'step_code' => 'check_notes' ]
				);
			}
			return $result;
		}

		$notes = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, content, note_type, created_at FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 20",
			$session_id
		), ARRAY_A );

		$count = is_array( $notes ) ? count( $notes ) : 0;
		$result['note_count'] = $count;

		// Inject notes into memory spec
		if ( $count > 0 && $spec !== null && class_exists( 'BizCity_Memory_Spec' ) ) {
			$spec['notes'] = array_map( function ( $n ) {
				return [
					'id'        => (int) $n['id'],
					'title'     => $n['title'],
					'note_type' => $n['note_type'],
					'excerpt'   => mb_substr( $n['content'] ?? '', 0, 200 ),
				];
			}, $notes );
			BizCity_Memory_Spec::persist( $taskId, $spec );
		}

		if ( $has_messenger ) {
			if ( $count > 0 ) {
				$msg = "📝 **Memory Notes**: Đã tìm thấy {$count} ghi chú được yêu cầu ghi nhớ gồm:\n";
				foreach ( (array) $notes as $n ) {
					$title = mb_substr( $n['title'] ?? 'Ghi chú', 0, 80 );
					$type  = $n['note_type'] ?? 'note';
					$icon  = $type === 'pin' || $type === 'chat_pinned' ? '📌' : ( $type === 'insight' ? '💡' : '📝' );
					$msg  .= "  • {$icon} {$title}\n";
				}
				if ( $count > 20 ) {
					$msg .= '  … và thêm ghi chú khác';
				}
			} else {
				$msg = "📝 **Memory Notes**: Chưa có ghi chú nào trong session này.\n"
				     . "💡 Tin nhắn quan trọng có thể được ghim (📌) để AI ghi nhớ.";
			}

			BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
				'tool_name'  => 'it_todos_planner',
				'step_code'  => 'check_notes',
				'note_count' => $count,
			] );
		}

		error_log( "[IT_TODOS_PLANNER] Memory notes: {$count} found (session={$session_id})" );

		return $result;
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	private function save_plan_studio_output( $session_id, $user_id, $task_id, $todo_count, array $steps, $plan_text ) {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return;
		}

		$content = "**{$plan_text}**\n\n";
		foreach ( $steps as $i => $s ) {
			$lbl      = $s['label'] ?? $s['tool_name'] ?? ( 'Step ' . ( $i + 1 ) );
			$content .= ( $i + 1 ) . '. ' . $lbl . "\n";
		}

		BizCity_Output_Store::save_artifact( [
			'tool_id'    => 'it_todos_planner',
			'caller'     => 'pipeline',
			'session_id' => $session_id,
			'user_id'    => (int) $user_id,
			'task_id'    => $task_id ?: null,
			'data'       => [
				'title'   => sprintf( 'Plan — %d steps', $todo_count ),
				'content' => $content,
			],
		], 'plan' );
	}

	private function getExecutionState( $variables ) {
		return [
			'pipeline_id'            => $variables['_pipeline_id'] ?? '',
			'session_id'             => $variables['_session_id'] ?? '',
			'user_id'                => $variables['_user_id'] ?? 0,
			'channel'                => $variables['_channel'] ?? 'adminchat',
			'intent_conversation_id' => $variables['_intent_conversation_id'] ?? '',
			'slash_command'          => $variables['_slash_command'] ?? '',
		];
	}

	/**
	 * Build admin URL to skill detail page.
	 */
	private function build_skill_url( string $skill_key, $skill_id = 0 ): string {
		if ( ! empty( $skill_key ) ) {
			// Strip sql:// prefix for URL
			$key_clean = preg_replace( '#^sql://#', '', $skill_key );
			return admin_url( 'admin.php?page=bizcity-skills&file=' . rawurlencode( $key_clean ) );
		}
		if ( $skill_id ) {
			return admin_url( 'admin.php?page=bizcity-skills&skill_id=' . intval( $skill_id ) );
		}
		return '';
	}
}
