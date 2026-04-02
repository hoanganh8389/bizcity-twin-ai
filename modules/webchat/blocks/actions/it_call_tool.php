<?php
/**
 * Bizcity Twin AI — Action Block: Universal Intent Tool Caller
 * Block hành động: Gọi công cụ AI từ Intent Engine
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      3.3.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Universal Intent Tool Caller
 * Gọi bất kỳ công cụ AI nào từ Intent Engine / Call any AI tool from Intent Engine
 *
 * Hybrid 80/20 approach:
 *   80% tools → dùng block này (zero config, chọn tool_id từ dropdown)
 *   20% specialized → dùng custom WaicAction riêng (video-kling polling, multi-step...)
 *
 * Cơ chế:
 *   1. Dropdown liệt kê tất cả tools đã đăng ký trong BizCity_Intent_Tools
 *   2. Input JSON textarea cho phép truyền params + {{node.var}} variables
 *   3. getResults() gọi tool callback trực tiếp
 *   4. Output = result_json (generic) — các node sau dùng {{node#X.result_json}} + parse
 *
 * @package BizCity_WebChat
 * @since   3.3.0
 */
class WaicAction_it_call_tool extends WaicAction {
	protected $_code  = 'it_call_tool';
	protected $_order = 0;

	public function __construct( $block = null ) {
		$this->_name = __( '🤖 Agent — Call AI Tool', 'bizcity-twin-ai' );
		$this->_desc = __( 'Call any AI tool registered in BizCity Intent Engine (write post, create product, post to social media...)', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	/**
	 * Build tool options from registered Intent Tools.
	 */
	private function getToolOptions() {
		$options = [ '' => __( '— Select Tool —', 'bizcity-twin-ai' ) ];

		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return $options;
		}

		$tools = BizCity_Intent_Tools::instance();
		$all   = $tools->list_all();

		foreach ( $all as $name => $schema ) {
			$desc   = isset( $schema['description'] ) ? $schema['description'] : $name;
			$label  = $name . ' — ' . mb_substr( $desc, 0, 60 );
			$options[ $name ] = $label;
		}

		return $options;
	}

	public function setSettings() {
		$this->_settings = [
			'tool_id' => [
				'type'    => 'select',
				'label'   => __( 'Tool *', 'bizcity-twin-ai' ),
				'options' => $this->getToolOptions(),
				'default' => '',
				'desc'    => __( 'Select the AI tool to call. List is auto-populated from Intent Engine.', 'bizcity-twin-ai' ),
			],
			'input_json' => [
				'type'      => 'textarea',
				'label'     => __( 'Input Parameters (JSON)', 'bizcity-twin-ai' ),
				'default'   => '{"message": "{{node#1.text}}"}',
				'rows'      => 6,
				'variables' => true,
				'desc'      => __( 'JSON object with key=param, value=value. Supports {{node#X.var}} variables.', 'bizcity-twin-ai' ),
			],
			'user_id_source' => [
				'type'    => 'select',
				'label'   => __( 'User context', 'bizcity-twin-ai' ),
				'default' => 'trigger',
				'options' => [
					'trigger' => __( 'From trigger ({{node#1.user_id}})', 'bizcity-twin-ai' ),
					'admin'   => __( 'Admin (user_id = 1)', 'bizcity-twin-ai' ),
					'none'    => __( 'No user context', 'bizcity-twin-ai' ),
				],
				'desc' => __( 'User ID for tool callback context (API keys, permissions).', 'bizcity-twin-ai' ),
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
			'success'     => __( 'Success (true/false)', 'bizcity-twin-ai' ),
			'tool_name'   => __( 'Called tool name', 'bizcity-twin-ai' ),
			'result_json' => __( 'Full JSON result', 'bizcity-twin-ai' ),
			'message'     => __( 'Response message (AI generated)', 'bizcity-twin-ai' ),
			'content'     => __( 'Content body (article / post body)', 'bizcity-twin-ai' ),
			'resource_id' => __( 'Created resource ID (post_id / product_id / ...)', 'bizcity-twin-ai' ),
			'resource_url'=> __( 'Resource URL', 'bizcity-twin-ai' ),
			'title'       => __( 'Resource title', 'bizcity-twin-ai' ),
			'image_url'   => __( 'Image URL (if any)', 'bizcity-twin-ai' ),
		];
	}

	public function getResults( $taskId, $variables, $step = 0 ) {
		error_log('[IT_CALL_TOOL] getResults ENTRY: taskId=' . $taskId . ' nodeId=' . $this->getId());
		$tool_id        = (string) $this->getParam( 'tool_id' );
		$input_json_raw = $this->replaceVariablesJsonSafe( $this->getParam( 'input_json' ), $variables );
		$user_source    = (string) $this->getParam( 'user_id_source' );

		error_log('[IT_CALL_TOOL] tool_id=' . $tool_id . ' input_json_raw=' . mb_substr( $input_json_raw, 0, 500 ) . ' user_source=' . $user_source);

		$error = '';

		if ( empty( $tool_id ) ) {
			$error = __( 'No tool selected.', 'bizcity-twin-ai' );
		}

		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			$error = __( 'BizCity Intent Engine is not active.', 'bizcity-twin-ai' );
		}

		// Parse input JSON
		$params = [];
		if ( empty( $error ) && ! empty( $input_json_raw ) ) {
			$params = json_decode( $input_json_raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$error = sprintf(
					__( 'Invalid input JSON: %s', 'bizcity-twin-ai' ),
					json_last_error_msg()
				);
			}
		}

		// Set user context for tool callback
		if ( empty( $error ) ) {
			switch ( $user_source ) {
				case 'trigger':
					$uid = 0;
					if ( ! empty( $variables['user_id'] ) ) {
						$uid = (int) $variables['user_id'];
					} elseif ( isset( $variables['node#1'] ) && ! empty( $variables['node#1']['user_id'] ) ) {
						$uid = (int) $variables['node#1']['user_id'];
					}
					if ( $uid > 0 ) {
						wp_set_current_user( $uid );
					}
					break;
				case 'admin':
					wp_set_current_user( 1 );
					break;
			}
		}

		$result_data = [];
		$execution_state = $this->getExecutionState( $variables );
		$pipeline_id     = $execution_state['pipeline_id'] ?? '';
		$node_id_str     = (string) ( $this->getId() ?? '' );

		if ( empty( $error ) ) {
			$tools = BizCity_Intent_Tools::instance();

			if ( ! $tools->has( $tool_id ) ) {
				$error = sprintf(
					__( 'Tool "%s" not found.', 'bizcity-twin-ai' ),
					$tool_id
				);
			} else {
				$schema = $tools->get_schema( $tool_id );

				// ── Phase 1.1: Skill Context Injection ──
				// Read matching skill BEFORE execution, log trace, inject into LLM context
				$skill_info = $this->resolve_skill_for_tool( $tool_id );
				error_log( '[IT_CALL_TOOL] skill_lookup tool=' . $tool_id . ' found=' . ( $skill_info ? $skill_info['title'] : 'NONE' ) );

				if ( ! empty( $schema['input_fields'] ) ) {
					// Common text-field aliases for auto-mapping
					$text_aliases = [ 'message', 'text', 'user_message', 'question', 'prompt', 'content', 'query' ];

					foreach ( $schema['input_fields'] as $field => $cfg ) {
						if ( empty( $cfg['required'] ) ) {
							continue;
						}
						// Already provided → skip
						if ( isset( $params[ $field ] ) && $params[ $field ] !== '' ) {
							continue;
						}
						// Try alias from existing params (e.g. message → question)
						if ( in_array( $field, $text_aliases, true ) ) {
							foreach ( $text_aliases as $alias ) {
								if ( $alias !== $field && isset( $params[ $alias ] ) && $params[ $alias ] !== '' ) {
									$params[ $field ] = $params[ $alias ];
									break;
								}
							}
						}
						// Still missing → try trigger variables
						if ( ! isset( $params[ $field ] ) || $params[ $field ] === '' ) {
							// Try flat variable first, then node#1 variable
							if ( ! empty( $variables[ $field ] ) ) {
								$params[ $field ] = $variables[ $field ];
							} elseif ( isset( $variables['node#1'] ) && ! empty( $variables['node#1'][ $field ] ) ) {
								$params[ $field ] = $variables['node#1'][ $field ];
							} elseif ( in_array( $field, $text_aliases, true ) ) {
								// Last resort: try text/message from trigger
								foreach ( $text_aliases as $alias ) {
									if ( ! empty( $variables[ $alias ] ) ) {
										$params[ $field ] = $variables[ $alias ];
										break;
									}
									if ( isset( $variables['node#1'] ) && ! empty( $variables['node#1'][ $alias ] ) ) {
										$params[ $field ] = $variables['node#1'][ $alias ];
										break;
									}
								}
							}
						}
					}

					// ── Phase 1.1 G1: HIL Slot Gathering (un_confirm pattern) ──
					$hil_state = $this->get_hil_state( $tool_id, $variables );

					// If user previously responded with slot data, merge it
					if ( $hil_state && ! empty( $hil_state['filled_slots'] ) ) {
						foreach ( $hil_state['filled_slots'] as $k => $v ) {
							if ( $v !== '' && $v !== null ) {
								$params[ $k ] = $v;
							}
						}
						error_log( '[IT_CALL_TOOL] HIL slots merged: ' . implode( ',', array_keys( $hil_state['filled_slots'] ) ) );
					}

					// Check if required fields are still missing (AFTER merge)
					$missing_required = [];
					foreach ( $schema['input_fields'] as $field => $cfg ) {
						if ( ! empty( $cfg['required'] ) && ( ! isset( $params[ $field ] ) || $params[ $field ] === '' || $params[ $field ] === null ) ) {
							$missing_required[] = $field;
						}
					}

					if ( ! empty( $missing_required ) ) {
						// Check if we already asked and user responded — try LLM fill first
						$params = $this->prepare_input_with_llm( $tool_id, $params, $variables, $schema );

						// Re-check after LLM fill
						$still_missing = [];
						foreach ( $schema['input_fields'] as $field => $cfg ) {
							if ( ! empty( $cfg['required'] ) && ( ! isset( $params[ $field ] ) || $params[ $field ] === '' || $params[ $field ] === null ) ) {
								$still_missing[] = $field;
							}
						}

						if ( ! empty( $still_missing ) ) {
							// ── HIL WAITING: Send prompt to user and pause workflow ──
							error_log( '[IT_CALL_TOOL] HIL_WAIT: tool=' . $tool_id . ' missing=' . implode( ',', $still_missing ) );

							// Update todo status to WAITING_USER
							if ( $pipeline_id && class_exists( 'BizCity_Intent_Todos' ) ) {
								BizCity_Intent_Todos::update_status( $pipeline_id, $tool_id, 'WAITING_USER', [
									'node_id' => $node_id_str,
								] );
							}

							// Send HIL prompt message to user
							$this->send_hil_slot_prompt( $variables, $tool_id, $still_missing, $schema, $params, $skill_info );

							// Save current params so we can merge on resume
							$this->set_hil_state( $tool_id, $variables, $params, $still_missing );

							$blog_id     = get_current_blog_id();
							$session_id  = $this->resolve_var( $variables, 'session_id' );
							$hil_key     = 'waic_hil_' . $blog_id . '_' . md5( $tool_id . '_' . $session_id );
							$timeout_at  = time() + 86400; // 24h timeout for slot gathering
							set_transient( $hil_key . '_timeout', $timeout_at, 86400 + 300 );

							return [
								'result'  => [ 'success' => 'false', 'tool_name' => $tool_id, 'message' => 'Waiting for user input' ],
								'error'   => '',
								'status'  => 0, // waiting
								'waiting' => $timeout_at,
							];
						}
					} else {
						// LLM-based smart input preparation: fill remaining empty optional fields
						$params = $this->prepare_input_with_llm( $tool_id, $params, $variables, $schema );
					}
				}

				// ── Phase 1.1: Update todo to IN_PROGRESS ──
				if ( $pipeline_id && class_exists( 'BizCity_Intent_Todos' ) ) {
					BizCity_Intent_Todos::update_status( $pipeline_id, $tool_id, 'IN_PROGRESS', [
						'node_id'         => $node_id_str,
						'node_input_json' => $params,
					] );
				}

				// ── Execute via Intent Tools registry ──
				$result_data = $tools->execute( $tool_id, $params );
			}
		}

		// Extract common fields from tool result
		$success  = ! empty( $result_data['success'] );
		$data     = isset( $result_data['data'] ) ? $result_data['data'] : [];
		$msg      = isset( $result_data['message'] ) ? $result_data['message'] : '';

		$resource_id  = isset( $data['id'] ) ? $data['id'] : '';
		$resource_url = isset( $data['url'] ) ? $data['url'] : '';
		$title        = isset( $data['title'] ) ? $data['title'] : '';
		$content      = isset( $data['content'] ) ? $data['content'] : '';
		$image_url    = isset( $data['image_url'] ) ? $data['image_url'] : '';

		// If tool returned error
		if ( ! $success && empty( $error ) ) {
			$error = $msg ?: __( 'Tool returned an error.', 'bizcity-twin-ai' );
		}

		// ── Phase 1.1 G3: Save evidence to intent_conversations ──
		$this->save_pipeline_evidence( $tool_id, $params, $result_data, $variables, $execution_state );

		// ── Phase 1.1 G2: Update ToDos checkpoint ──
		if ( $pipeline_id && class_exists( 'BizCity_Intent_Todos' ) ) {
			$todo_status = $success ? 'COMPLETED' : 'FAILED';
			$todo_extra  = [
				'node_id'          => $node_id_str,
				'node_output_json' => $result_data,
				'output_summary'   => mb_substr( $msg ?: ( $title ?: '' ), 0, 200 ),
				'score'            => $success ? 80 : 0,
			];
			if ( ! $success ) {
				$todo_extra['error_message'] = mb_substr( $error, 0, 500 );
			}
			BizCity_Intent_Todos::update_status( $pipeline_id, $tool_id, $todo_status, $todo_extra );
			error_log( '[IT_CALL_TOOL] todo_update pipeline=' . $pipeline_id . ' tool=' . $tool_id . ' status=' . $todo_status );
		}

		// Clear HIL state on completion
		$this->clear_hil_state( $tool_id, $variables );

		// Auto-send result to admin chat session if platform is adminchat
		$this->maybe_send_to_adminchat( $variables, $tool_id, $success, $msg, $error, $resource_url, $title );

		$this->_results = [
			'result' => [
				'success'      => $success ? 'true' : 'false',
				'tool_name'    => $tool_id,
				'result_json'  => wp_json_encode( $result_data, JSON_UNESCAPED_UNICODE ),
				'message'      => $msg,
				'content'      => $content,
				'resource_id'  => (string) $resource_id,
				'resource_url' => $resource_url,
				'title'        => $title,
				'image_url'    => $image_url,
			],
			'error'  => $error,
			'status' => empty( $error ) ? 3 : 7,
		];

		return $this->_results;
	}

	/* ================================================================
	 *  Phase 1.1: HIL State Management (un_confirm pattern)
	 * ================================================================ */

	/**
	 * Get HIL state for this tool from transient.
	 *
	 * @param string $tool_id   Tool identifier.
	 * @param array  $variables Workflow variables.
	 * @return array|null HIL state or null if not waiting.
	 */
	private function get_hil_state( $tool_id, $variables ) {
		$blog_id    = get_current_blog_id();
		$session_id = $this->resolve_var( $variables, 'session_id' );
		$key        = 'waic_hil_' . $blog_id . '_' . md5( $tool_id . '_' . $session_id );
		$state      = get_transient( $key );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Save HIL state with current params and missing fields.
	 */
	private function set_hil_state( $tool_id, $variables, $current_params, $missing_fields ) {
		$blog_id    = get_current_blog_id();
		$session_id = $this->resolve_var( $variables, 'session_id' );
		$key        = 'waic_hil_' . $blog_id . '_' . md5( $tool_id . '_' . $session_id );
		$state      = [
			'tool_id'        => $tool_id,
			'current_params' => $current_params,
			'missing_fields' => $missing_fields,
			'filled_slots'   => [],
			'created_at'     => time(),
			'run_id'         => isset( $this->_runId ) ? $this->_runId : 0,
		];
		set_transient( $key, $state, 86400 + 300 );
	}

	/**
	 * Clear HIL state after tool execution completes.
	 */
	private function clear_hil_state( $tool_id, $variables ) {
		$blog_id    = get_current_blog_id();
		$session_id = $this->resolve_var( $variables, 'session_id' );
		$key        = 'waic_hil_' . $blog_id . '_' . md5( $tool_id . '_' . $session_id );
		delete_transient( $key );
		delete_transient( $key . '_timeout' );
	}

	/**
	 * Send HIL slot prompt message to user via chat.
	 *
	 * @param array       $variables Workflow variables.
	 * @param string      $tool_id   Tool identifier.
	 * @param array       $missing   Missing required field names.
	 * @param array       $schema    Tool schema.
	 * @param array       $params    Current resolved params.
	 * @param array|null  $skill     Matched skill info or null.
	 */
	private function send_hil_slot_prompt( $variables, $tool_id, $missing, $schema, $params, $skill ) {
		$session_id = $this->resolve_var( $variables, 'session_id' );
		if ( empty( $session_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		$input_fields = $schema['input_fields'] ?? [];

		// Build human-friendly prompt
		$lines = [];
		$lines[] = '📝 **' . ( $schema['description'] ?? $tool_id ) . '** — cần thêm thông tin:';
		$lines[] = '';

		// Show what we already have
		$filled = [];
		foreach ( $params as $k => $v ) {
			if ( $v !== '' && $v !== null ) {
				$label = $input_fields[ $k ]['description'] ?? $k;
				$filled[] = '✅ ' . $label . ': ' . mb_substr( (string) $v, 0, 80 );
			}
		}
		if ( $filled ) {
			$lines[] = implode( "\n", $filled );
			$lines[] = '';
		}

		// Show what's missing
		foreach ( $missing as $field ) {
			$desc = $input_fields[ $field ]['description'] ?? $field;
			$type = $input_fields[ $field ]['type'] ?? 'text';
			$lines[] = '❓ **' . $desc . '** (' . $type . ')';
		}

		$lines[] = '';

		// Skill context note
		if ( $skill ) {
			$lines[] = '💡 Tôi tìm thấy skill "' . $skill['title'] . '" liên quan đến tool này. Sẽ làm theo hướng dẫn skill.';
		} else {
			$lines[] = '💡 Không có skill cụ thể cho tool này. Tôi sẽ dùng kinh nghiệm để thực hiện.';
		}

		$lines[] = '';
		$lines[] = 'Vui lòng cung cấp thông tin còn thiếu, hoặc gõ "bỏ qua" để skip bước này.';

		$text = implode( "\n", $lines );

		BizCity_WebChat_Database::instance()->log_message( [
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => 'Pipeline Bot',
			'message_id'    => uniqid( 'hil_' ),
			'message_text'  => $text,
			'message_from'  => 'bot',
			'message_type'  => 'text',
			'platform_type' => 'ADMINCHAT',
			'tool_name'     => $tool_id,
		] );
	}

	/* ================================================================
	 *  Phase 1.1: Skill Resolution
	 * ================================================================ */

	/**
	 * Find matching skill for a tool before execution.
	 * Logs trace for transparency.
	 *
	 * @param string $tool_id Tool name.
	 * @return array|null { title, content, path } or null.
	 */
	private function resolve_skill_for_tool( $tool_id ) {
		if ( ! class_exists( 'BizCity_Skill_Manager' ) ) {
			error_log( '[IT_CALL_TOOL] skill_resolve: BizCity_Skill_Manager not available' );
			return null;
		}
		$mgr     = BizCity_Skill_Manager::instance();
		$matches = $mgr->find_matching( [
			'tool'          => $tool_id,
			'slash_command' => '/' . $tool_id,
			'limit'         => 1,
		] );

		if ( empty( $matches ) ) {
			error_log( '[IT_CALL_TOOL] skill_resolve: no skill found for tool=' . $tool_id );
			return null;
		}

		$m = $matches[0];
		$title = $m['frontmatter']['title'] ?? basename( $m['path'] ?? '', '.md' );
		error_log( '[IT_CALL_TOOL] skill_resolve: FOUND skill="' . $title . '" path=' . ( $m['path'] ?? '' ) . ' score=' . ( $m['score'] ?? 0 ) );
		return [
			'title'   => $title,
			'content' => $m['content'] ?? '',
			'path'    => $m['path'] ?? '',
		];
	}

	/* ================================================================
	 *  Phase 1.1: Evidence — Save to intent_conversations
	 * ================================================================ */

	/**
	 * Save tool execution evidence to intent_conversations table.
	 *
	 * @param string $tool_id         Tool name.
	 * @param array  $params          Input params used.
	 * @param array  $result_data     Tool execution result.
	 * @param array  $variables       Workflow variables.
	 * @param array  $execution_state Pipeline execution state.
	 */
	private function save_pipeline_evidence( $tool_id, $params, $result_data, $variables, $execution_state ) {
		if ( ! class_exists( 'BizCity_Intent_Database' ) ) {
			return;
		}
		$pipeline_id = $execution_state['pipeline_id'] ?? '';
		if ( empty( $pipeline_id ) ) {
			return; // Not in a pipeline context
		}

		$db = BizCity_Intent_Database::instance();

		$success    = ! empty( $result_data['success'] );
		$node_id    = (string) ( $this->getId() ?? '' );
		$session_id = $execution_state['session_id'] ?? '';
		$user_id    = $execution_state['user_id'] ?? get_current_user_id();

		$conv_id = 'tool_' . $tool_id . '_' . $pipeline_id . '_n' . $node_id;

		$conv_data = [
			'conversation_id'    => $conv_id,
			'user_id'            => (int) $user_id,
			'session_id'         => $session_id,
			'channel'            => 'pipeline',
			'goal'               => $tool_id,
			'goal_label'         => isset( $result_data['data']['title'] ) ? $result_data['data']['title'] : $tool_id,
			'status'             => $success ? 'COMPLETED' : 'FAILED',
			'parent_pipeline_id' => $pipeline_id,
			'step_index'         => (int) str_replace( 'node_', '', $node_id ),
			'slots_json'         => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
			'context_snapshot'   => wp_json_encode( [
				'result'   => $result_data,
				'verified' => $success && ! empty( $result_data['data']['id'] ),
			], JSON_UNESCAPED_UNICODE ),
		];

		// Use insert_conversation (upsert-safe — it will update if conversation_id already exists)
		if ( method_exists( $db, 'insert_conversation' ) ) {
			$db->insert_conversation( $conv_data );
		} elseif ( method_exists( $db, 'update_conversation' ) ) {
			$db->update_conversation( $conv_id, $conv_data );
		} else {
			error_log( '[IT_CALL_TOOL] evidence_save FAILED: no insert/update method on BizCity_Intent_Database' );
			return;
		}

		error_log( '[IT_CALL_TOOL] evidence_saved conv_id=' . $conv_id . ' status=' . ( $success ? 'COMPLETED' : 'FAILED' ) );
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

	/**
	 * LLM-based smart input preparation.
	 *
	 * When template resolution leaves required params empty, uses LLM to
	 * intelligently map available context from previous nodes to tool inputs.
	 *
	 * @param string $tool_id   Target tool name.
	 * @param array  $params    Currently resolved params (may have empty values).
	 * @param array  $variables All available variables from previous nodes.
	 * @param array  $schema    Tool schema with input_fields.
	 * @return array Params with empty required fields filled by LLM.
	 */
	private function prepare_input_with_llm( $tool_id, $params, $variables, $schema ) {
		$input_fields = isset( $schema['input_fields'] ) ? $schema['input_fields'] : [];
		if ( empty( $input_fields ) ) {
			return $params;
		}

		// Check if any required fields are still empty
		$missing = [];
		foreach ( $input_fields as $field => $cfg ) {
			if ( ! empty( $cfg['required'] ) && ( ! isset( $params[ $field ] ) || $params[ $field ] === '' || $params[ $field ] === false || $params[ $field ] === null ) ) {
				$missing[] = $field;
			}
		}

		// Also trigger when ALL provided params are empty (failed template resolution)
		// This handles pipeline tools like post_facebook where all fields are optional
		if ( empty( $missing ) && ! empty( $params ) ) {
			$all_empty = true;
			foreach ( $params as $v ) {
				if ( $v !== '' && $v !== false && $v !== null ) {
					$all_empty = false;
					break;
				}
			}
			if ( $all_empty ) {
				// Only trigger if there's context from nodes after trigger (not just node#1)
				foreach ( $variables as $vk => $vv ) {
					if ( preg_match( '/^node#[2-9]/', $vk ) && is_array( $vv ) ) {
						$missing = array_keys( $params );
						break;
					}
				}
			}
		}

		if ( empty( $missing ) ) {
			return $params;
		}

		if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
			return $params;
		}

		error_log( '[IT_CALL_TOOL] LLM input prep triggered for tool=' . $tool_id . ' missing=' . implode( ',', $missing ) );

		// Build compact context from all node variables
		$context = $this->build_context_for_llm( $variables );

		// Build schema description
		$schema_desc = [];
		foreach ( $input_fields as $field => $cfg ) {
			$req  = ! empty( $cfg['required'] ) ? 'BẮT BUỘC' : 'tùy chọn';
			$type = isset( $cfg['type'] ) ? $cfg['type'] : 'text';
			$desc = isset( $cfg['description'] ) ? $cfg['description'] : '';
			$schema_desc[] = "- {$field} ({$type}, {$req}): {$desc}";
		}

		$system = "Bạn là data mapper cho workflow automation.\n"
			. "Map dữ liệu context từ các node trước → input params cho tool.\n\n"
			. "TOOL: {$tool_id}\n"
			. "INPUT SCHEMA:\n" . implode( "\n", $schema_desc ) . "\n\n"
			. "DỮ LIỆU CÓ SẴN TỪ CÁC NODE TRƯỚC:\n{$context}\n\n"
			. "INPUT HIỆN TẠI (các field trống cần được điền từ context):\n"
			. wp_json_encode( $params, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n\n"
			. "QUY TẮC:\n"
			. "- Điền TẤT CẢ các field trống từ context data (ưu tiên BẮT BUỘC, rồi tùy chọn)\n"
			. "- Giữ nguyên các giá trị đã có\n"
			. "- Ưu tiên dùng data từ node gần nhất (node# lớn nhất)\n"
			. "- Nếu context có result_json đã parse, dùng data bên trong\n"
			. "- Trả về CHỈ JSON object hợp lệ, không markdown, không giải thích";

		$response = bizcity_openrouter_chat(
			[
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => 'Map context → tool input. JSON only.' ],
			],
			[ 'temperature' => 0.1, 'max_tokens' => 2000, 'purpose' => 'fast' ]
		);

		if ( empty( $response['success'] ) || empty( $response['message'] ) ) {
			error_log( '[IT_CALL_TOOL] LLM input prep failed: ' . ( isset( $response['error'] ) ? $response['error'] : 'no response' ) );
			return $params;
		}

		$raw = $response['message'];
		$raw = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
		$raw = preg_replace( '/```\s*$/m', '', $raw );
		$raw = trim( $raw );

		if ( preg_match( '/\{[\s\S]*\}/u', $raw, $matches ) ) {
			$fixed = json_decode( $matches[0], true );
			if ( is_array( $fixed ) ) {
				error_log( '[IT_CALL_TOOL] LLM input prep success: ' . wp_json_encode( array_keys( $fixed ), JSON_UNESCAPED_UNICODE ) );
				// Only fill empty fields — preserve existing values
				foreach ( $fixed as $k => $v ) {
					if ( ! isset( $params[ $k ] ) || $params[ $k ] === '' || $params[ $k ] === false || $params[ $k ] === null ) {
						$params[ $k ] = $v;
					}
				}
				return $params;
			}
		}

		error_log( '[IT_CALL_TOOL] LLM input prep: could not parse response' );
		return $params;
	}

	/**
	 * Build compact context summary from workflow variables for LLM.
	 * Parses JSON strings in result_json to expose nested data.
	 */
	private function build_context_for_llm( $variables ) {
		$lines = [];

		foreach ( $variables as $key => $value ) {
			if ( strpos( $key, 'node#' ) !== 0 ) {
				continue;
			}
			if ( ! is_array( $value ) ) {
				continue;
			}

			$lines[] = "[{$key}]:";

			foreach ( $value as $field => $val ) {
				if ( $field === 'result_json' && is_string( $val ) && $val !== '' ) {
					$parsed = json_decode( $val, true );
					if ( is_array( $parsed ) ) {
						$summary = $this->extract_context_fields( $parsed );
						$lines[] = '  result_json (parsed):';
						foreach ( $summary as $sk => $sv ) {
							$sv_str = is_string( $sv ) ? mb_substr( $sv, 0, 500 ) : wp_json_encode( $sv, JSON_UNESCAPED_UNICODE );
							$lines[] = "    {$sk}: {$sv_str}";
						}
						continue;
					}
				}

				if ( is_string( $val ) && $val !== '' ) {
					$lines[] = "  {$field}: " . mb_substr( $val, 0, 300 );
				} elseif ( is_array( $val ) ) {
					$lines[] = "  {$field}: " . mb_substr( wp_json_encode( $val, JSON_UNESCAPED_UNICODE ), 0, 300 );
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Extract useful fields from a parsed tool result for LLM context.
	 */
	private function extract_context_fields( $result ) {
		$summary = [];

		foreach ( [ 'success', 'message', 'complete' ] as $f ) {
			if ( isset( $result[ $f ] ) ) {
				$summary[ $f ] = $result[ $f ];
			}
		}

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $dk => $dv ) {
				if ( $dk === 'content' && is_string( $dv ) ) {
					$summary[ "data.{$dk}" ] = mb_substr( wp_strip_all_tags( $dv ), 0, 500 );
				} else {
					$summary[ "data.{$dk}" ] = $dv;
				}
			}
		}

		return $summary;
	}

	/**
	 * Auto-send tool result to admin chat session.
	 * Resolves session_id from trigger variables (node#1 or flat).
	 */
	private function maybe_send_to_adminchat( $variables, $tool_id, $success, $msg, $error, $resource_url, $title ) {
		if ( ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		// Detect platform from trigger
		$platform = $this->resolve_var( $variables, 'platform' );
		if ( strtolower( $platform ) !== 'adminchat' ) {
			return;
		}

		$session_id = $this->resolve_var( $variables, 'session_id' );
		if ( empty( $session_id ) ) {
			return;
		}

		// Build human-readable result message
		if ( $success ) {
			$text = '✅ Tool [' . $tool_id . '] hoàn tất.';
			if ( ! empty( $msg ) ) {
				$text .= "\n" . $msg;
			}
			if ( ! empty( $title ) ) {
				$text .= "\n📌 " . $title;
			}
			if ( ! empty( $resource_url ) ) {
				$text .= "\n🔗 " . $resource_url;
			}
		} else {
			$text = '❌ Tool [' . $tool_id . '] thất bại.';
			if ( ! empty( $error ) ) {
				$text .= "\n" . $error;
			}
		}

		BizCity_WebChat_Database::instance()->log_message( [
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => 'Pipeline Bot',
			'message_id'    => uniqid( 'tool_' ),
			'message_text'  => $text,
			'message_from'  => 'bot',
			'message_type'  => 'text',
			'platform_type' => 'ADMINCHAT',
			'tool_name'     => $tool_id,
		] );
	}

	/**
	 * Resolve a variable from flat scope or node#1.
	 */
	private function resolve_var( $variables, $key ) {
		if ( ! empty( $variables[ $key ] ) ) {
			return $variables[ $key ];
		}
		if ( isset( $variables['node#1'] ) && ! empty( $variables['node#1'][ $key ] ) ) {
			return $variables['node#1'][ $key ];
		}
		return '';
	}

	/**
	 * JSON-safe variable replacement.
	 *
	 * Unlike replaceVariables() which does raw str_replace, this method
	 * properly escapes variable values for JSON context using json_encode().
	 * This prevents breakage when values contain quotes, backslashes,
	 * newlines, or other JSON-special characters.
	 *
	 * @param string $template  JSON template with {{node#X.field}} placeholders.
	 * @param array  $variables Variables from previous workflow nodes.
	 * @return string Valid JSON string with all placeholders replaced.
	 */
	private function replaceVariablesJsonSafe( $template, $variables ) {
		$template = (string) $template;
		preg_match_all( '/\{\{(.*?)\}\}/', $template, $matches );

		if ( empty( $matches[1] ) ) {
			return $template;
		}

		foreach ( $matches[1] as $var ) {
			$replace = '';
			$parts   = explode( '.', $var );

			if ( count( $parts ) == 2 ) {
				$node     = $parts[0];
				$variable = $parts[1];

				if ( isset( $variables[ $node ] ) && isset( $variables[ $node ][ $variable ] ) ) {
					$replace = $variables[ $node ][ $variable ];
					if ( is_array( $replace ) ) {
						$replace = implode( ',', $replace );
					}
				}
			}

			// json_encode() properly escapes ", \, \n, \r, \t, control chars.
			// Strip the surrounding quotes since the value is inserted INTO
			// an existing JSON string literal in the template.
			$encoded = json_encode( (string) $replace, JSON_UNESCAPED_UNICODE );
			$safe    = substr( $encoded, 1, -1 );

			$template = str_replace( '{{' . $var . '}}', $safe, $template );
		}

		return $template;
	}

	/**
	 * Sanitize a JSON string after variable replacement.
	 *
	 * replaceVariables() substitutes {{node#X.field}} with raw values that may
	 * contain unescaped control characters (newlines, tabs, backslashes, quotes)
	 * inside JSON string positions, making json_decode() fail with
	 * "Control character error, possibly incorrectly encoded".
	 *
	 * Strategy: Parse the JSON template to find string values, then properly
	 * escape control characters within those values.
	 */
	private function sanitize_json_string( $json_str ) {
		// Quick check: if json_decode already works, no sanitization needed
		$test = json_decode( $json_str, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return $json_str;
		}

		// Escape control characters inside JSON string values.
		// Walk through the string character by character, tracking whether
		// we're inside a JSON string (between unescaped quotes).
		$len    = strlen( $json_str );
		$result = '';
		$in_str = false;
		$i      = 0;

		while ( $i < $len ) {
			$ch = $json_str[ $i ];

			if ( $in_str ) {
				if ( $ch === '\\' && $i + 1 < $len ) {
					// Already escaped sequence — keep as-is
					$result .= $ch . $json_str[ $i + 1 ];
					$i += 2;
					continue;
				}
				if ( $ch === '"' ) {
					// End of string
					$result .= $ch;
					$in_str  = false;
					$i++;
					continue;
				}
				// Control characters that must be escaped in JSON strings
				$ord = ord( $ch );
				if ( $ord < 0x20 ) {
					switch ( $ch ) {
						case "\n": $result .= '\\n';  break;
						case "\r": $result .= '\\r';  break;
						case "\t": $result .= '\\t';  break;
						default:   $result .= sprintf( '\\u%04x', $ord ); break;
					}
					$i++;
					continue;
				}
				$result .= $ch;
			} else {
				if ( $ch === '"' ) {
					$in_str = true;
				}
				$result .= $ch;
			}
			$i++;
		}

		return $result;
	}
}
