<?php
/**
 * BizCity Twin AI — Action Block: Skill Resolution (Explicit)
 *
 * Tường minh skill cho pipeline: user chủ động chọn hoặc hệ thống auto-resolve
 * skill, rồi output skill_key / skill_title / skill_content cho downstream nodes.
 *
 * Khác it_todos_planner (implicit):
 *   - User có thể chọn skill cụ thể từ dropdown
 *   - Có nút edit mở skill editor (target _blank)
 *   - Standalone — có thể đặt ở bất kỳ vị trí nào trong pipeline
 *   - Output chỉ tập trung vào skill (không tạo TODOs)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @since      4.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicAction_it_call_skill extends WaicAction {
	protected $_code  = 'it_call_skill';
	protected $_order = 0;

	public function __construct( $block = null ) {
		$this->_name = __( '🎯 Skill — Resolve & Inject', 'bizcity-twin-ai' );
		$this->_desc = __( 'Chọn hoặc tự tìm skill phù hợp, inject vào pipeline cho các node phía sau.', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	/* ================================================================
	 *  Settings — UI fields in workflow editor
	 * ================================================================ */

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	/**
	 * Build skill dropdown from DB.
	 */
	private function getSkillOptions(): array {
		$options = [
			''     => __( '— Tự động tìm skill —', 'bizcity-twin-ai' ),
		];

		if ( ! class_exists( 'BizCity_Skill_Database' ) ) {
			return $options;
		}

		$db     = BizCity_Skill_Database::instance();
		$result = $db->find_matching( [ 'limit' => 200 ] );

		if ( ! empty( $result ) && is_array( $result ) ) {
			foreach ( $result as $row ) {
				$id    = $row['skill_id'] ?? ( $row['id'] ?? 0 );
				$key   = $row['skill_key'] ?? ( $row['path'] ?? '' );
				$title = $row['frontmatter']['title'] ?? ( $row['title'] ?? $key );
				$slash = '';
				if ( ! empty( $row['slash_commands'] ) ) {
					$cmds  = is_array( $row['slash_commands'] ) ? $row['slash_commands'] : explode( ',', $row['slash_commands'] );
					$slash = $cmds[0] ?? '';
				}

				$label = $title;
				if ( $slash ) {
					$label .= ' (/' . ltrim( $slash, '/' ) . ')';
				}

				$option_key = $id ? 'id:' . $id : $key;
				$options[ $option_key ] = $label;
			}
		}

		return $options;
	}

	public function setSettings() {
		$this->_settings = [
			'skill_select' => [
				'type'    => 'select',
				'label'   => __( 'Chọn Skill', 'bizcity-twin-ai' ),
				'options' => $this->getSkillOptions(),
				'default' => '',
				'desc'    => __( 'Chọn skill cụ thể hoặc để trống để tự tìm.', 'bizcity-twin-ai' ),
			],
			'slash_command_override' => [
				'type'      => 'input',
				'label'     => __( 'Slash command override', 'bizcity-twin-ai' ),
				'default'   => '',
				'variables' => true,
				'desc'      => __( 'VD: /contentcongnghe — override slash command. Hỗ trợ {{node#X.var}}.', 'bizcity-twin-ai' ),
			],
			'fallback_mode' => [
				'type'    => 'select',
				'label'   => __( 'Fallback khi không tìm thấy', 'bizcity-twin-ai' ),
				'default' => 'continue',
				'options' => [
					'continue' => __( 'Tiếp tục (không có skill)', 'bizcity-twin-ai' ),
					'error'    => __( 'Dừng pipeline — báo lỗi', 'bizcity-twin-ai' ),
				],
			],
		];
	}

	/* ================================================================
	 *  Variables — output for downstream nodes
	 * ================================================================ */

	public function getVariables() {
		if ( empty( $this->_variables ) ) {
			$this->setVariables();
		}
		return $this->_variables;
	}

	public function setVariables() {
		$this->_variables = [
			'skill_key'     => __( 'Skill key/path', 'bizcity-twin-ai' ),
			'skill_title'   => __( 'Skill title', 'bizcity-twin-ai' ),
			'skill_content' => __( 'Skill body (markdown)', 'bizcity-twin-ai' ),
			'skill_url'     => __( 'URL to edit skill', 'bizcity-twin-ai' ),
			'skill_id'      => __( 'Skill DB ID', 'bizcity-twin-ai' ),
			'success'       => __( 'Tìm thấy skill? (true/false)', 'bizcity-twin-ai' ),
		];
	}

	/* ================================================================
	 *  Main execution
	 * ================================================================ */

	public function getResults( $taskId, $variables, $step = 0 ) {
		$start_time = microtime( true );
		error_log( '[IT_CALL_SKILL] getResults ENTRY: taskId=' . $taskId . ' nodeId=' . $this->getId() );

		// ── Trace: execute_start ──
		$session_id = $variables['_session_id'] ?? '';
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_start', [
			'node_code'  => 'it_call_skill',
			'label'      => 'Đang tìm skill...',
			'session_id' => $session_id,
		], 'info', 0 );

		$exec_state    = $this->getExecutionState( $variables );
		$has_messenger = class_exists( 'BizCity_Pipeline_Messenger' ) && ! empty( $exec_state['session_id'] );

		// ── Read settings ──
		$skill_select    = (string) $this->getParam( 'skill_select' );
		$slash_override  = trim( $this->replaceVariables( $this->getParam( 'slash_command_override', '' ), $variables ) );
		$fallback_mode   = (string) $this->getParam( 'fallback_mode' );

		$user_message = $variables['node#1']['text'] ?? ( $variables['text'] ?? '' );

		// ── Set user context ──
		$uid = 0;
		if ( ! empty( $variables['_user_id'] ) ) {
			$uid = (int) $variables['_user_id'];
		} elseif ( ! empty( $variables['user_id'] ) ) {
			$uid = (int) $variables['user_id'];
		} elseif ( isset( $variables['node#1'] ) && ! empty( $variables['node#1']['user_id'] ) ) {
			$uid = (int) $variables['node#1']['user_id'];
		}
		if ( $uid > 0 && get_userdata( $uid ) ) {
			wp_set_current_user( $uid );
		}

		// ── Resolve skill ──
		$skill_result = $this->resolve_skill( $skill_select, $slash_override, $user_message, $exec_state, $has_messenger );

		// ── Handle fallback ──
		$found = ! empty( $skill_result['skill_key'] ) && $skill_result['skill_title'] !== 'none';

		if ( ! $found && $fallback_mode === 'error' ) {
			$error_msg = 'Không tìm thấy skill phù hợp. Pipeline dừng.';
			error_log( '[IT_CALL_SKILL] FAIL: no skill found, fallback=error' );

			if ( $has_messenger ) {
				BizCity_Pipeline_Messenger::send_node_result( $exec_state, '❌', $error_msg, 'it_call_skill' );
			}

			return [
				'result' => [
					'skill_key'     => '',
					'skill_title'   => '',
					'skill_content' => '',
					'skill_url'     => '',
					'skill_id'      => 0,
					'success'       => 'false',
				],
				'error'  => $error_msg,
				'status' => 3,
			];
		}

		// ── Trace: execute_done ──
		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_call_skill',
			'label'      => $found
				? sprintf( 'Skill: %s', $skill_result['skill_title'] )
				: 'Không có skill — tiếp tục',
			'has_error'  => 'false',
			'session_id' => $session_id,
		], 'info', (int) $elapsed_ms );

		return [
			'result' => [
				'skill_key'     => $skill_result['skill_key'],
				'skill_title'   => $skill_result['skill_title'],
				'skill_content' => $skill_result['skill_content'],
				'skill_url'     => $skill_result['skill_url'],
				'skill_id'      => $skill_result['skill_id'],
				'success'       => $found ? 'true' : 'false',
			],
			'error'  => '',
			'status' => 3,
		];
	}

	/* ================================================================
	 *  Skill Resolution — Phase 1.12: 2 chiến lược rõ ràng
	 *
	 *  1. Có slash → regex extract → SELECT by slash_commands in bizcity_skills
	 *  2. Không slash → lấy tool name từ pipeline context → LIKE in skill_tool_map
	 *
	 *  Luôn nhắn tin cho user (tìm được hay không).
	 * ================================================================ */

	private function resolve_skill( string $skill_select, string $slash_override, string $user_message, array $exec_state, bool $has_messenger ): array {
		$result = [
			'skill_key'     => '',
			'skill_title'   => 'none',
			'skill_content' => '',
			'skill_url'     => '',
			'skill_id'      => 0,
		];

		// ── Priority 0: User selected a specific skill from dropdown (manual workflow) ──
		if ( ! empty( $skill_select ) ) {
			$resolved = $this->resolve_from_selection( $skill_select );
			if ( $resolved ) {
				$this->fill_result( $result, $resolved );
				$this->send_skill_message( $exec_state, $has_messenger, $result, 'chọn thủ công',
					'📌 User đã chọn skill này trong cấu hình node.' );
				return $result;
			}
		}

		$user_id = (int) ( $exec_state['user_id'] ?? 0 );

		// ── Strategy 1: Slash command → DB exact match ──
		$slash = $slash_override ?: ( $exec_state['slash_command'] ?? '' );

		// Also try to regex-extract slash from user message if none provided
		if ( empty( $slash ) && ! empty( $user_message ) ) {
			if ( preg_match( '/^\/([a-zA-Z0-9_]+)/', trim( $user_message ), $m ) ) {
				$slash = $m[1];
			}
		}

		if ( ! empty( $slash ) && class_exists( 'BizCity_Skill_Database' ) ) {
			$slash_clean = ltrim( $slash, '/' );
			$row = BizCity_Skill_Database::instance()->get_by_slash_command( $slash_clean );

			if ( $row ) {
				$this->fill_result_from_db_row( $result, $row );
				$this->send_skill_message( $exec_state, $has_messenger, $result, 'slash',
					"⚡ Tìm trực tiếp qua /{$slash_clean}" );
				error_log( "[IT_CALL_SKILL] Strategy 1 OK: slash /{$slash_clean} → {$result['skill_title']} (id={$result['skill_id']})" );
				return $result;
			}

			error_log( "[IT_CALL_SKILL] Strategy 1 MISS: slash /{$slash_clean} not found in bizcity_skills" );
		}

		// ── Strategy 2: Tool name from pipeline → skill_tool_map LIKE lookup ──
		$tool_names = $this->extract_tool_names_from_context( $exec_state );

		if ( ! empty( $tool_names ) && class_exists( 'BizCity_Skill_Tool_Map' ) ) {
			$map = BizCity_Skill_Tool_Map::instance();

			foreach ( $tool_names as $tool_key ) {
				$row = $map->resolve_skill_for_tool( $tool_key, $user_id );
				if ( $row ) {
					$this->fill_result_from_db_row( $result, $row );
					$this->send_skill_message( $exec_state, $has_messenger, $result, 'tool-map',
						"🔧 Tìm qua tool binding: {$tool_key}" );
					error_log( "[IT_CALL_SKILL] Strategy 2 OK: tool-map {$tool_key} → {$result['skill_title']} (id={$result['skill_id']})" );
					return $result;
				}
			}

			error_log( '[IT_CALL_SKILL] Strategy 2 MISS: no skill for tools [' . implode( ', ', $tool_names ) . ']' );
		}

		// ── No skill found — still notify user ──
		$search_info = [];
		if ( ! empty( $slash ) ) {
			$search_info[] = 'slash=/' . ltrim( $slash, '/' );
		}
		if ( ! empty( $tool_names ) ) {
			$search_info[] = 'tools=[' . implode( ', ', $tool_names ) . ']';
		}

		$msg = "🎯 **Skill**: ⚠️ Không tìm thấy skill phù hợp.\n"
		     . '🔎 Đã tìm: ' . ( ! empty( $search_info ) ? implode( ', ', $search_info ) : 'không có thông tin' ) . "\n"
		     . "💡 Pipeline tiếp tục không có skill injection.\n"
		     . '📝 [Tạo skill mới](' . esc_url( admin_url( 'admin.php?page=bizcity-skills' ) ) . ')';

		error_log( '[IT_CALL_SKILL] No skill found. ' . implode( ', ', $search_info ) );

		if ( $has_messenger ) {
			BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
				'tool_name' => 'it_call_skill',
				'step_code' => 'resolve_skill',
			] );
		}

		return $result;
	}

	/* ================================================================
	 *  Extract tool names from pipeline context
	 *
	 *  Sources (in priority order):
	 *   1. Sibling nodes in execution state (variables from other nodes)
	 *   2. Task params from bizcity_tasks (if task_id available)
	 * ================================================================ */

	private function extract_tool_names_from_context( array $exec_state ): array {
		$tools = [];

		// Source 1: Read task_id → bizcity_tasks.params → extract tool names from nodes
		$task_id = 0;
		if ( ! empty( $exec_state['pipeline_id'] ) && preg_match( '/shell_/', $exec_state['pipeline_id'] ) ) {
			// Pipeline ID format: shell_<uuid> — we need to find the task
			global $wpdb;
			$tasks_table = $wpdb->prefix . 'bizcity_tasks';
			$task_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tasks_table} WHERE feature = 'workflow' AND params LIKE %s ORDER BY id DESC LIMIT 1",
				'%' . $wpdb->esc_like( $exec_state['pipeline_id'] ) . '%'
			) );
		}

		if ( $task_id > 0 ) {
			global $wpdb;
			$tasks_table = $wpdb->prefix . 'bizcity_tasks';
			$params_json = $wpdb->get_var( $wpdb->prepare(
				"SELECT params FROM {$tasks_table} WHERE id = %d LIMIT 1",
				$task_id
			) );

			if ( $params_json ) {
				$params = json_decode( $params_json, true );
				$nodes  = $params['nodes'] ?? [];

				foreach ( $nodes as $node ) {
					$code = $node['data']['code'] ?? '';
					$settings = $node['data']['settings'] ?? [];

					// Extract content_tool from it_call_content nodes
					if ( $code === 'it_call_content' && ! empty( $settings['content_tool'] ) ) {
						$tools[] = $settings['content_tool'];
					}
					// Extract tool_id from it_call_tool nodes
					if ( $code === 'it_call_tool' && ! empty( $settings['tool_id'] ) ) {
						$tools[] = $settings['tool_id'];
					}
				}
			}
		}

		// Source 2: Parse tool names from steps_json in planner settings (if a sibling it_todos_planner exists)
		// This handles cases where the planner node was already generated with steps
		// The steps_json contains [{tool_name, label, node_id}, ...]
		// We don't have direct access to sibling node settings here,
		// but the task params above should cover it.

		return array_unique( array_filter( $tools ) );
	}

	/* ================================================================
	 *  Helpers — fill result + send message
	 * ================================================================ */

	private function fill_result( array &$result, array $resolved ): void {
		$result['skill_key']     = $resolved['skill_key'] ?? ( $resolved['path'] ?? '' );
		$result['skill_title']   = $resolved['title'] ?? ( $resolved['frontmatter']['title'] ?? 'Untitled' );
		$result['skill_content'] = $resolved['content'] ?? '';
		$result['skill_id']      = (int) ( $resolved['id'] ?? ( $resolved['skill_id'] ?? 0 ) );
		$result['skill_url']     = $this->build_skill_url( $result['skill_key'], $result['skill_id'] );
	}

	private function fill_result_from_db_row( array &$result, array $row ): void {
		$result['skill_key']     = $row['skill_key'] ?? '';
		$result['skill_title']   = $row['title'] ?? 'Untitled';
		$result['skill_content'] = $row['content'] ?? '';
		$result['skill_id']      = (int) ( $row['id'] ?? 0 );
		$result['skill_url']     = $this->build_skill_url( $result['skill_key'], $result['skill_id'] );
	}

	/**
	 * Send skill resolution result to user via Pipeline Messenger.
	 * Always sends — whether skill found or not.
	 * Includes clickable link to view/edit skill in dialog.
	 */
	private function send_skill_message( array $exec_state, bool $has_messenger, array $result, string $strategy, string $detail ): void {
		$msg = "🎯 **Skill ({$strategy})**: {$result['skill_title']}\n{$detail}";

		if ( ! empty( $result['skill_url'] ) ) {
			$msg .= "\n🔗 [Xem & Sửa Skill](" . esc_url( $result['skill_url'] ) . ')';
		}

		error_log( "[IT_CALL_SKILL] Resolved via {$strategy}: {$result['skill_title']} (id={$result['skill_id']})" );

		if ( $has_messenger ) {
			BizCity_Pipeline_Messenger::send( $exec_state, $msg, 'info', [
				'tool_name' => 'it_call_skill',
				'step_code' => 'resolve_skill',
			] );
		}
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Resolve skill from the dropdown selection value.
	 * Values: "id:123" for DB skill, or "path/to/skill.md" for file-based.
	 */
	private function resolve_from_selection( string $value ): ?array {
		if ( str_starts_with( $value, 'id:' ) ) {
			$id = (int) substr( $value, 3 );
			if ( $id > 0 && class_exists( 'BizCity_Skill_Database' ) ) {
				$db  = BizCity_Skill_Database::instance();
				$row = $db->get( $id );
				if ( $row ) {
					return $row;
				}
			}
			return null;
		}

		// File-based path
		if ( class_exists( 'BizCity_Skill_Manager' ) ) {
			$mgr  = BizCity_Skill_Manager::instance();
			$skill = $mgr->load_skill( $value );
			if ( $skill ) {
				return $skill;
			}
		}

		return null;
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
	 * Build admin URL to skill editor page with deep link support.
	 */
	private function build_skill_url( string $skill_key, $skill_id = 0 ): string {
		if ( ! empty( $skill_key ) ) {
			$key_clean = preg_replace( '#^sql://#', '', $skill_key );
			return admin_url( 'admin.php?page=bizcity-skills&file=' . rawurlencode( $key_clean ) );
		}
		if ( $skill_id ) {
			return admin_url( 'admin.php?page=bizcity-skills&skill_id=' . intval( $skill_id ) );
		}
		return '';
	}
}
