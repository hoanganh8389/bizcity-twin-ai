<?php
/**
 * Bizcity Twin AI — Action Block: Content Generate & Verify
 * Block hành động: Tạo nội dung với skill injection + HIL content preview/refine
 *
 * Phase 1.4d: New block type for content production pipeline.
 * Differs from it_call_tool:
 *   - Only lists atomic content tools (accepts_skill=true)
 *   - Built-in skill resolution + build_skill_prompt
 *   - Typed output variables: content, title, metadata (not generic result_json)
 *   - Content preview + refine HIL flow
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @since      4.2.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicAction_it_call_content extends WaicAction {
	protected $_code  = 'it_call_content';
	protected $_order = 0;

	public function __construct( $block = null ) {
		$this->_name = __( '📝 Content — Generate & Verify', 'bizcity-twin-ai' );
		$this->_desc = __( 'Generate content with skill injection + HIL preview/refine', 'bizcity-twin-ai' );
		$this->setBlock( $block );
	}

	public function getSettings() {
		if ( empty( $this->_settings ) ) {
			$this->setSettings();
		}
		return $this->_settings;
	}

	/**
	 * Build tool dropdown — only atomic content tools (accepts_skill=true).
	 */
	private function getContentToolOptions(): array {
		$options = [ '' => __( '— Select Content Tool —', 'bizcity-twin-ai' ) ];

		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return $options;
		}

		$tools = BizCity_Intent_Tools::instance();
		$all   = $tools->list_all();

		foreach ( $all as $name => $schema ) {
			// Only show tools with accepts_skill=true
			if ( empty( $schema['accepts_skill'] ) ) {
				continue;
			}
			$desc   = isset( $schema['description'] ) ? $schema['description'] : $name;
			$label  = $name . ' — ' . mb_substr( $desc, 0, 60 );
			$options[ $name ] = $label;
		}

		return $options;
	}

	public function setSettings() {
		$this->_settings = [
			'content_tool' => [
				'type'    => 'select',
				'label'   => __( 'Content Tool *', 'bizcity-twin-ai' ),
				'options' => $this->getContentToolOptions(),
				'default' => '',
				'desc'    => __( 'Atomic content tool (only skill-aware tools shown).', 'bizcity-twin-ai' ),
			],
			'input_json' => [
				'type'      => 'textarea',
				'label'     => __( 'Input Parameters (JSON)', 'bizcity-twin-ai' ),
				'default'   => '{"topic": "{{node#1.text}}"}',
				'rows'      => 6,
				'variables' => true,
				'desc'      => __( 'JSON input. Supports {{node#X.var}} variables.', 'bizcity-twin-ai' ),
			],
			'require_confirm' => [
				'type'    => 'select',
				'label'   => __( 'Require user confirm', 'bizcity-twin-ai' ),
				'default' => '1',
				'options' => [
					'1' => __( 'Yes — preview + approve', 'bizcity-twin-ai' ),
					'0' => __( 'No — auto-approve', 'bizcity-twin-ai' ),
				],
				'desc'    => __( 'Show content preview for user approval before passing to downstream nodes.', 'bizcity-twin-ai' ),
			],
			'max_refine_rounds' => [
				'type'    => 'select',
				'label'   => __( 'Max refine rounds', 'bizcity-twin-ai' ),
				'default' => '2',
				'options' => [ '0' => '0', '1' => '1', '2' => '2', '3' => '3' ],
				'desc'    => __( 'How many times user can request content refinement.', 'bizcity-twin-ai' ),
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
			'success'    => __( 'Success (true/false)', 'bizcity-twin-ai' ),
			'content'    => __( 'Generated content body', 'bizcity-twin-ai' ),
			'title'      => __( 'Generated title', 'bizcity-twin-ai' ),
			'metadata'   => __( 'JSON metadata (hashtags, subject, sections...)', 'bizcity-twin-ai' ),
			'skill_used' => __( 'Skill name applied', 'bizcity-twin-ai' ),
			'refined'    => __( 'Was content refined? (true/false)', 'bizcity-twin-ai' ),
		];
	}

	public function getResults( $taskId, $variables, $step = 0 ) {
		$start_time = microtime( true );
		error_log( '[IT_CALL_CONTENT] getResults ENTRY: taskId=' . $taskId . ' nodeId=' . $this->getId() );

		// ── Trace: execute_start ──
		$session_id_trace = $variables['_session_id'] ?? '';
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_start', [
			'node_code'  => 'it_call_content',
			'label'      => 'Đang tạo nội dung...',
			'session_id' => $session_id_trace,
		], 'info', 0 );

		$tool_id         = (string) $this->getParam( 'content_tool' );
		$input_json_raw  = $this->replaceVariablesJsonSafe( $this->getParam( 'input_json' ), $variables );
		$require_confirm = (bool) $this->getParam( 'require_confirm' );
		$max_refine      = (int) $this->getParam( 'max_refine_rounds' );

		$error = '';

		// ── Auto-resolve content_tool when not explicitly set ──
		if ( empty( $tool_id ) ) {
			$tool_id = $this->auto_resolve_content_tool( $variables );
			if ( $tool_id ) {
				error_log( '[IT_CALL_CONTENT] Auto-resolved content_tool=' . $tool_id );
			}
		}

		if ( empty( $tool_id ) ) {
			$error = __( 'No content tool selected.', 'bizcity-twin-ai' );
		}

		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			$error = __( 'BizCity Intent Engine is not active.', 'bizcity-twin-ai' );
		}

		// Parse input JSON
		$params = [];
		if ( empty( $error ) && ! empty( $input_json_raw ) ) {
			$params = json_decode( $input_json_raw, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$error = sprintf( __( 'Invalid input JSON: %s', 'bizcity-twin-ai' ), json_last_error_msg() );
			}
		}

		// ── Auto-inherit context from previous nodes when params is empty ──
		if ( empty( $error ) && empty( $params ) ) {
			$params = $this->inherit_from_previous_nodes( $variables );
			if ( ! empty( $params ) ) {
				error_log( '[IT_CALL_CONTENT] Auto-inherited ' . count( $params ) . ' params from previous nodes: ' . implode( ', ', array_keys( $params ) ) );
			}
		}

		// ── Always merge upstream research/memory data even when params already set ──
		if ( empty( $error ) && ! empty( $params ) ) {
			$upstream = $this->inherit_from_previous_nodes( $variables );
			foreach ( [ '_research_summary', '_source_count', '_source_ids', '_memory_spec' ] as $up_key ) {
				if ( ! empty( $upstream[ $up_key ] ) && empty( $params[ $up_key ] ) ) {
					$params[ $up_key ] = $upstream[ $up_key ];
				}
			}
		}

		if ( ! empty( $error ) ) {
			return [
				'result' => [
					'success'    => 'false',
					'content'    => '',
					'title'      => '',
					'metadata'   => '',
					'skill_used' => '',
					'refined'    => 'false',
				],
				'error'  => $error,
				'status' => 3,
			];
		}

		// ── Set user context ──
		$uid = 0;
		if ( ! empty( $variables['user_id'] ) ) {
			$uid = (int) $variables['user_id'];
		} elseif ( isset( $variables['node#1'] ) && ! empty( $variables['node#1']['user_id'] ) ) {
			$uid = (int) $variables['node#1']['user_id'];
		}
		if ( $uid > 0 ) {
			wp_set_current_user( $uid );
		}

		// ── Check HIL state (confirm/refine flow) ──
		$hil_state = $this->get_hil_state( $tool_id, $variables );
		if ( $hil_state ) {
			$hil_status = $hil_state['status'] ?? '';

			if ( $hil_status === 'confirmed' ) {
				// User approved content → return stored result
				error_log( '[IT_CALL_CONTENT] HIL_CONFIRMED: tool=' . $tool_id );
				$this->clear_hil_state( $tool_id, $variables );
				$stored = $hil_state['result'] ?? [];
				return [
					'result' => [
						'success'    => 'true',
						'content'    => $stored['content'] ?? '',
						'title'      => $stored['title'] ?? '',
						'metadata'   => wp_json_encode( $stored['metadata'] ?? [] ),
						'skill_used' => $stored['skill_used'] ?? '',
						'refined'    => 'false',
					],
					'error'  => '',
					'status' => 3,
				];
			}

			if ( $hil_status === 'rejected' ) {
				error_log( '[IT_CALL_CONTENT] HIL_REJECTED: tool=' . $tool_id );
				$this->clear_hil_state( $tool_id, $variables );
				return [
					'result' => [
						'success'    => 'false',
						'content'    => '',
						'title'      => '',
						'metadata'   => '',
						'skill_used' => '',
						'refined'    => 'false',
					],
					'error'  => '',
					'status' => 3,
				];
			}

			if ( str_starts_with( $hil_status, 'refine:' ) ) {
				$feedback    = substr( $hil_status, 7 );
				$refine_round = (int) ( $hil_state['refine_round'] ?? 0 ) + 1;

				if ( $refine_round > $max_refine ) {
					// Max refines reached → auto-approve stored content
					$this->clear_hil_state( $tool_id, $variables );
					$stored = $hil_state['result'] ?? [];
					return [
						'result' => [
							'success'    => 'true',
							'content'    => $stored['content'] ?? '',
							'title'      => $stored['title'] ?? '',
							'metadata'   => wp_json_encode( $stored['metadata'] ?? [] ),
							'skill_used' => $stored['skill_used'] ?? '',
							'refined'    => 'true',
						],
						'error'  => '',
						'status' => 3,
					];
				}

				// Inject refine feedback into params
				$params['_refine_feedback'] = $feedback;
				$params['_refine_round']    = $refine_round;
				error_log( "[IT_CALL_CONTENT] REFINE round={$refine_round} feedback={$feedback}" );
			}
		}

		// ── Execute via BizCity_Tool_Wrapper (unified layer) ──
		$wrapper_context = [
			'caller'        => 'it_call_content',
			'session_id'    => $variables['session_id'] ?? '',
			'user_id'       => $uid,
			'channel'       => 'pipeline',
			'save_studio'   => $require_confirm ? 'never' : 'always',
			'save_evidence' => true,
			'pipeline_id'   => $variables['_pipeline_id'] ?? '',
			'node_id'       => (string) ( $this->getId() ?? '' ),
		];

		// ── Propagate skill from upstream node (e.g. it_todos_planner) ──
		$upstream_skill_key     = $variables['skill_key']     ?? '';
		$upstream_skill_title   = $variables['skill_title']   ?? '';
		$upstream_skill_content = $variables['skill_content'] ?? '';
		if ( $upstream_skill_key && $upstream_skill_content ) {
			$wrapper_context['skill_override'] = [
				'title'   => $upstream_skill_title ?: $upstream_skill_key,
				'content' => $upstream_skill_content,
				'path'    => $upstream_skill_key,
			];
		}

		// ── SSE streaming: provide stream callback if SSE context is active ──
		$can_stream_sse = function_exists( 'bizcity_openrouter_chat_stream' )
		                && has_action( 'bizcity_intent_stream_chunk' );
		if ( $can_stream_sse ) {
			$wrapper_context['stream_callback'] = function ( $delta, $full_text ) {
				do_action( 'bizcity_intent_stream_chunk', $delta, $full_text );
			};
		}

		// ── Micro-step: send granular progress messages ──
		$exec_state = $this->getExecutionState( $variables );
		$has_messenger = class_exists( 'BizCity_Pipeline_Messenger' ) && ! empty( $exec_state['session_id'] );

		if ( $has_messenger ) {
			BizCity_Pipeline_Messenger::send_micro_step( $exec_state, '🔍', "Tìm skill cho {$tool_id}...", 'resolve_skill' );
		}

		$t_start = microtime( true );
		$wrapper_result = BizCity_Tool_Wrapper::run( $tool_id, $params, $wrapper_context );
		$result      = $wrapper_result['raw_result'];
		$duration_ms = $wrapper_result['duration_ms'];

		$success    = $wrapper_result['success'];
		$content    = $wrapper_result['content'];
		$title      = $wrapper_result['title'];
		$skill_used = $wrapper_result['skill_used'];

		if ( $has_messenger ) {
			// ── Tin nhắn 1: Skill resolution detail ──
			$skill_resolve = $result['skill_resolve'] ?? '';
			if ( $skill_used && $skill_used !== 'none' ) {
				$method = '';
				if ( strpos( $skill_resolve, 'skill_tool_map' ) !== false ) {
					$method = ' (via tool-map binding)';
				} elseif ( strpos( $skill_resolve, 'text_matching' ) !== false ) {
					$method = ' (via text matching)';
				}
				BizCity_Pipeline_Messenger::send_micro_step(
					$exec_state, '📋',
					"Skill tìm thấy: {$skill_used}{$method}",
					'skill_found'
				);
			} else {
				$reason = "tool={$tool_id}, user={$uid}";
				BizCity_Pipeline_Messenger::send_micro_step(
					$exec_state, '⚠️',
					"Chưa tìm thấy skill cho {$tool_id} — truy vấn: {$reason}",
					'skill_not_found'
				);
			}

			// ── Tin nhắn 2: Knowledge/FAQ context ──
			$has_knowledge = ! empty( $result['_meta']['_context'] ?? '' )
			              || ! empty( $params['_meta']['_context'] ?? '' );
			if ( $has_knowledge ) {
				BizCity_Pipeline_Messenger::send_micro_step(
					$exec_state, '📚',
					'Context knowledge đã inject vào prompt',
					'knowledge_inject'
				);
			} else {
				BizCity_Pipeline_Messenger::send_micro_step(
					$exec_state, '📭',
					'Không có context knowledge — chỉ sử dụng skill + prompt gốc',
					'knowledge_none'
				);
			}

			// ── Tin nhắn 3: Kết quả tạo nội dung ──
			$icon  = $success ? '✅' : '❌';
			$label = $success
				? "Nội dung đã tạo — " . mb_substr( $title ?: $tool_id, 0, 60 )
				: "Lỗi tạo nội dung: " . mb_substr( $result['message'] ?: $result['error'] ?? 'unknown', 0, 80 );
			BizCity_Pipeline_Messenger::send_micro_step( $exec_state, $icon, $label, $tool_id, $duration_ms );
		}

		// Collect metadata (everything except standard keys)
		$standard_keys = [ 'success', 'content', 'title', 'message', 'data', 'skill', 'duration_ms', 'verified', 'invoke_id', 'missing_fields', 'skill_used', 'tokens_used' ];
		$metadata = array_diff_key( $result, array_flip( $standard_keys ) );

		error_log( "[IT_CALL_CONTENT] result: success={$success} title_len=" . strlen( $title ) . " content_len=" . strlen( $content ) . " skill={$skill_used}" );

		// ── Content Preview (HIL) — if required ──
		if ( $require_confirm && $success ) {
			$preview_msg = "📝 **Nội dung đã tạo** (tool: {$tool_id})\n\n"
			             . "**Tiêu đề:** " . ( $title ?: 'N/A' ) . "\n\n"
			             . "---\n"
			             . mb_substr( $content, 0, 500 )
			             . ( strlen( $content ) > 500 ? "...\n" : "\n" )
			             . "---\n\n"
			             . "Skill: {$skill_used}\n\n"
			             . "👉 Reply:\n"
			             . "• **OK** — duyệt nội dung\n"
			             . "• **Ngắn hơn** / **Thêm CTA** / yêu cầu khác — refine\n"
			             . "• **Hủy** — bỏ qua";

			// Store result for resume after user confirms
			$this->set_hil_state( $tool_id, $variables, [
				'status'       => 'waiting_confirm',
				'result'       => [
					'content'    => $content,
					'title'      => $title,
					'metadata'   => $metadata,
					'skill_used' => $skill_used,
				],
				'refine_round' => $params['_refine_round'] ?? 0,
			] );

			// Send preview message
			do_action( 'bizcity_pipeline_hil_message', $preview_msg, $variables );

			return [
				'result' => [
					'success'    => 'true',
					'content'    => $content,
					'title'      => $title,
					'metadata'   => wp_json_encode( $metadata ),
					'skill_used' => $skill_used,
					'refined'    => ! empty( $params['_refine_round'] ) ? 'true' : 'false',
				],
				'error'  => '',
				'status' => 2, // waiting (HIL)
			];
		}

		// ── Auto-approve (no HIL) ──

		// ── Stream content to chat UI ──
		$was_streamed = ! empty( $result['_streamed'] );
		if ( $success && $content ) {
			if ( $can_stream_sse && ! $was_streamed ) {
				// Tool did NOT natively stream → post-hoc stream the generated content via SSE
				error_log( "[IT_CALL_CONTENT] Post-hoc SSE stream: " . strlen( $content ) . " chars" );
				$chunk_size = 120;
				$offset     = 0;
				$full_buf   = '';
				$len        = strlen( $content );
				while ( $offset < $len ) {
					$chunk    = substr( $content, $offset, $chunk_size );
					$full_buf .= $chunk;
					do_action( 'bizcity_intent_stream_chunk', $chunk, $full_buf );
					$offset  += $chunk_size;
				}
			}

			// Push content as a persistent chat message (visible on reload / non-SSE channels)
			if ( $has_messenger ) {
				$word_count = str_word_count( strip_tags( $content ) );
				if ( $can_stream_sse || $was_streamed ) {
					// SSE already showed the full content → push compact card for chat history
					$card_msg = "📝 **" . ( $title ?: $tool_id ) . "**\n"
					          . "📊 {$word_count} từ · skill: {$skill_used} · {$duration_ms}ms";
				} else {
					// No SSE → push full content as chat message
					$card_msg = "📝 **" . ( $title ?: $tool_id ) . "**\n\n"
					          . $content . "\n\n"
					          . "— *{$skill_used}* · {$word_count} từ · {$duration_ms}ms";
				}
				BizCity_Pipeline_Messenger::send( $exec_state, $card_msg, 'success', [
					'_to_chat'  => true,
					'tool_name' => $tool_id,
					'format'    => 'content_stream',
				] );
			}
		}

		// ── Studio outputs entry — handled by BizCity_Tool_Wrapper when save_studio != 'never' ──
		// (auto-approve path: Wrapper saves via save_studio='always')
		// (HIL preview path: Wrapper skips via save_studio='never')

		// ── Trace: execute_done ──
		$elapsed_ms_trace = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_call_content',
			'label'      => $success
				? 'Content — ' . mb_substr( $title ?: $tool_id, 0, 60 )
				: 'Content — lỗi: ' . mb_substr( $result['message'] ?: $result['error'] ?? 'unknown', 0, 60 ),
			'has_error'  => $success ? 'false' : 'true',
			'session_id' => $session_id_trace,
		], $success ? 'info' : 'error', (int) $elapsed_ms_trace );

		// Build link to builder page for this task
		$content_url = $taskId
			? admin_url( 'admin.php?page=bizcity-workspace&tab=builder&task_id=' . intval( $taskId ) . '&bizcity_iframe=1' )
			: '';

		return [
			'result' => [
				'success'    => $success ? 'true' : 'false',
				'content'    => $content,
				'title'      => $title,
				'url'        => $content_url,
				'metadata'   => wp_json_encode( $metadata ),
				'skill_used' => $skill_used,
				'refined'    => 'false',
			],
			'error'  => $success ? '' : $this->build_error_message( $result ),
			'status' => 3,
		];
	}

	/**
	 * Save content artifact to studio_outputs.
	 * Called directly to ensure pipeline-generated content appears in Studio tab
	 * regardless of the bizcity_tool_execution_completed event's verified gate.
	 */
	private function save_content_studio_output( $session_id, $user_id, $task_id, $tool_id, $title, $content, $skill_used ) {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return;
		}

		BizCity_Output_Store::save_artifact( [
			'tool_id'    => $tool_id ?: 'it_call_content',
			'caller'     => 'pipeline',
			'session_id' => $session_id,
			'user_id'    => (int) $user_id,
			'task_id'    => $task_id ?: null,
			'data'       => [
				'title'   => $title ?: 'Content — ' . $tool_id,
				'content' => $content,
			],
		], 'content' );
	}

	/* ================================================================
	 *  Error message builder
	 * ================================================================ */

	/**
	 * Build a human-readable error from the tool run result.
	 * Propagates quota_exhausted and surfaces the real error string.
	 */
	private function build_error_message( array $result ): string {
		// quota_exhausted bubbles up via raw_result → tool_result
		$quota = ! empty( $result['quota_exhausted'] );
		$raw_error = $result['error'] ?? $result['message'] ?? '';

		if ( $quota || stripos( $raw_error, 'quota' ) !== false || stripos( $raw_error, 'monthly' ) !== false ) {
			return 'Hết quota LLM tháng này — ' . ( $raw_error ?: 'Monthly quota exceeded' ) . '. Vui lòng nạp thêm tại bizcity.vn/pricing';
		}

		if ( stripos( $raw_error, 'rate limit' ) !== false || stripos( $raw_error, 'Rate limit' ) !== false ) {
			return 'Rate limit LLM — ' . $raw_error . '. Thử lại sau vài giây.';
		}

		return $raw_error ?: 'Content generation failed';
	}

	/* ================================================================
	 *  HIL State Management (reuses WAIC task meta storage)
	 * ================================================================ */

	private function get_hil_state( string $tool_id, array $variables ): ?array {
		$key = 'content_hil_' . $tool_id . '_' . ( $this->getId() ?? '0' );
		$task_id = $variables['_task_id'] ?? '';
		if ( ! $task_id ) {
			return null;
		}
		$state = get_transient( 'bizcity_hil_' . $task_id . '_' . $key );
		return $state ?: null;
	}

	private function set_hil_state( string $tool_id, array $variables, array $state ): void {
		$key     = 'content_hil_' . $tool_id . '_' . ( $this->getId() ?? '0' );
		$task_id = $variables['_task_id'] ?? '';
		if ( $task_id ) {
			set_transient( 'bizcity_hil_' . $task_id . '_' . $key, $state, 3600 );
		}
	}

	private function clear_hil_state( string $tool_id, array $variables ): void {
		$key     = 'content_hil_' . $tool_id . '_' . ( $this->getId() ?? '0' );
		$task_id = $variables['_task_id'] ?? '';
		if ( $task_id ) {
			delete_transient( 'bizcity_hil_' . $task_id . '_' . $key );
		}
	}

	/**
	 * Utility: resolve {{node#X.var}} in JSON string.
	 */
	private function replaceVariablesJsonSafe( string $json, array $variables ): string {
		return preg_replace_callback( '/\{\{node#(\d+)\.(\w+)\}\}/', function ( $m ) use ( $variables ) {
			$node_key = 'node#' . $m[1];
			$field    = $m[2];

			// Try node result first
			$value = '';
			if ( isset( $variables[ $node_key ] ) && is_array( $variables[ $node_key ] ) ) {
				$value = $variables[ $node_key ][ $field ] ?? '';
			} else {
				// Try flat variable
				$value = $variables[ $field ] ?? '';
			}

			// JSON-escape the value so it doesn't break the surrounding JSON structure.
			// trim() removes the wrapping quotes added by json_encode for strings.
			if ( is_string( $value ) ) {
				return trim( wp_json_encode( $value ), '"' );
			}
			return (string) $value;
		}, $json );
	}

	/**
	 * Get execution state from pipeline variables.
	 */
	private function getExecutionState( array $variables ): array {
		return [
			'pipeline_id'            => $variables['_pipeline_id'] ?? '',
			'task_id'                => $variables['_task_id'] ?? '',
			'session_id'             => $variables['_session_id'] ?? '',
			'user_id'                => $variables['_user_id'] ?? 0,
			'channel'                => $variables['_channel'] ?? 'adminchat',
			'intent_conversation_id' => $variables['_intent_conversation_id'] ?? '',
		];
	}

	/**
	 * Auto-inherit context fields from previous completed nodes.
	 *
	 * Scans $variables['node#X'] in reverse order (latest first)
	 * and collects non-empty values for common content fields.
	 *
	 * @param array $variables Pipeline variables.
	 * @return array Inherited params (may be empty if nothing found).
	 */
	/**
	 * Auto-resolve content_tool when not explicitly configured.
	 *
	 * Precedence:
	 *   1. @tool_name mention in trigger message (node#1.text or variables.text)
	 *   2. Skill's slash_command (from _slash_command or planner's skill_key)
	 *   3. First tool with accepts_skill=true in registry
	 *
	 * @param array $variables Pipeline variables.
	 * @return string|null Resolved tool name or null.
	 */
	private function auto_resolve_content_tool( array $variables ): ?string {
		if ( ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return null;
		}
		$registry = BizCity_Intent_Tools::instance();

		// 1. Extract @tool_name from trigger message — trust explicit mention
		$trigger_text = $variables['text'] ?? ( $variables['node#1']['text'] ?? '' );
		if ( $trigger_text && preg_match( '/^@([a-zA-Z][a-zA-Z0-9_]*)/', trim( $trigger_text ), $m ) ) {
			$candidate = $m[1];
			if ( $registry->has( $candidate ) ) {
				return $candidate;
			}
		}

		// 2. Skill's slash_command
		$slash = $variables['_slash_command'] ?? '';
		if ( empty( $slash ) && ! empty( $variables['node#2']['skill_key'] ) ) {
			// skill_key format: "sql://content" → extract "content"
			$sk = $variables['node#2']['skill_key'];
			$slash = preg_replace( '/^sql:\/\//', '', $sk );
		}
		if ( $slash && $registry->has( $slash ) ) {
			$all = $registry->list_all();
			if ( ! empty( $all[ $slash ]['accepts_skill'] ) ) {
				return $slash;
			}
		}

		// 3. First accepts_skill=true tool as fallback
		$all = $registry->list_all();
		foreach ( $all as $name => $schema ) {
			if ( ! empty( $schema['accepts_skill'] ) ) {
				return $name;
			}
		}

		return null;
	}

	private function inherit_from_previous_nodes( array $variables ): array {
		$inherit_fields = [ 'topic', 'title', 'content', 'message', 'keyword', 'subject', 'summary' ];
		$inherited      = [];

		// Collect all node# keys, sort descending so latest node wins
		$node_keys = [];
		foreach ( array_keys( $variables ) as $key ) {
			if ( preg_match( '/^node#(\d+)$/', $key, $m ) ) {
				$node_keys[ (int) $m[1] ] = $key;
			}
		}
		krsort( $node_keys );

		foreach ( $node_keys as $node_key ) {
			$node_data = $variables[ $node_key ];
			if ( ! is_array( $node_data ) ) {
				continue;
			}

			// ── Phase 1.10: Recognize it_call_research outputs ──
			if ( ! empty( $node_data['research_summary'] ) ) {
				if ( ! isset( $inherited['_research_summary'] ) ) {
					$inherited['_research_summary'] = $node_data['research_summary'];
					$inherited['_source_count']     = $node_data['source_count'] ?? '0';
					$inherited['_source_ids']       = $node_data['source_ids'] ?? '';
				}
			}

			// ── Phase 1.10: Recognize it_call_memory outputs ──
			if ( ! empty( $node_data['memory_spec'] ) ) {
				if ( ! isset( $inherited['_memory_spec'] ) ) {
					$inherited['_memory_spec'] = $node_data['memory_spec'];
				}
			}

			foreach ( $inherit_fields as $field ) {
				if ( isset( $inherited[ $field ] ) ) {
					continue; // already found from a later node
				}
				if ( ! empty( $node_data[ $field ] ) && is_string( $node_data[ $field ] ) ) {
					$inherited[ $field ] = $node_data[ $field ];
				}
			}
		}

		// Also check trigger text from node#1
		if ( empty( $inherited['topic'] ) && ! empty( $variables['node#1']['text'] ) ) {
			$inherited['topic'] = $variables['node#1']['text'];
		}

		return $inherited;
	}
}
