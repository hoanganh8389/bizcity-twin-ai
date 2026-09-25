<?php
/**
 * BizCity Tool Wrapper — Universal Tool Execution Gateway
 *
 * Phase 1.12a + 1.20: Unified wrapper sitting BELOW Shell Engine, it_call_content, and it_call_tool.
 * ALL tool execution MUST go through this class. No exceptions.
 *
 * Absorbs shared logic:
 *   - Slot preparation (entity→schema for SINGLE, context→schema for pipeline)
 *   - Text alias resolution for pipeline tools
 *   - Required field validation + human-readable prompt
 *   - Canvas handoff decision (Phase 2a: should_handoff → dispatch → canvas_handoff status)
 *   - Inline execution via BizCity_Tool_Run::execute() (Phase 2b)
 *   - Studio output save (always / conditional based on tool_type)
 *   - Pipeline evidence logging to intent_conversations
 *
 * Returns 4 status codes:
 *   - 'completed'       → Tool executed inline, result ready
 *   - 'waiting_slot'    → Missing required fields, needs user input
 *   - 'canvas_handoff'  → Creative tool dispatched to Canvas Panel
 *   - 'error'           → Execution failed
 *
 * Callers add their own layer:
 *   - Shell Engine: conversation state, format_single_tool_reply, SSE canvas event
 *   - it_call_content: HIL confirm/refine, SSE post-hoc, micro-steps
 *   - it_call_tool: HIL transient state, todo updates, micro-steps
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @since      4.3.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Tool_Wrapper {

	const LOG = '[TOOL-WRAPPER]';

	/**
	 * Run a tool through the unified wrapper pipeline.
	 *
	 * @param string $tool_id    Tool identifier (e.g. 'write_article').
	 * @param array  $raw_input  Raw input parameters (may need alias/LLM fill).
	 * @param array  $context    Execution context {
	 *   // Identity:
	 *   @type string   $caller          'intent_engine_single'|'it_call_content'|'it_call_tool'
	 *   @type string   $session_id      Chat session ID.
	 *   @type int      $user_id         WordPress user ID.
	 *   @type string   $channel         webchat|adminchat|pipeline.
	 *   @type string   $conv_id         Intent conversation UUID.
	 *   @type string   $message         Original user message.
	 *   @type string   $message_id      Original message ID.
	 *   @type string   $goal            Goal identifier.
	 *   @type string   $goal_label      Human-readable goal label.
	 *   @type string   $character_id    AI character binding.
	 *
	 *   // Slot preparation:
	 *   @type array    $entities        SINGLE mode: classifier entities for LLM mapping.
	 *   @type array    $variables       Pipeline mode: workflow variables for LLM fill.
	 *   @type bool     $text_aliases    Enable text field alias resolution (pipeline).
	 *
	 *   // Skill:
	 *   @type array    $skill_override  Upstream skill override {title, content, path}.
	 *   @type callable $stream_callback SSE stream callback.
	 *
	 *   // Post-processing:
	 *   @type string   $save_studio     'auto'|'always'|'never' — studio output behavior.
	 *   @type bool     $save_evidence   Save to intent_conversations table.
	 *   @type string   $pipeline_id     Parent pipeline ID (for evidence).
	 *   @type string   $node_id         Node ID within pipeline (for evidence).
	 * }
	 * @return array {
	 *   @type string     $status         'completed'|'waiting_slot'|'canvas_handoff'|'error'
	 *   @type bool       $success        Whether execution succeeded.
	 *   @type string     $message        Human-readable result message.
	 *   @type array      $data           Tool output data.
	 *   @type string     $content        Generated content body.
	 *   @type string     $title          Generated title.
	 *   @type string     $skill_used     Skill name applied.
	 *   @type int        $duration_ms    Execution time in ms.
	 *   @type array      $missing_fields Missing required fields (when waiting_slot).
	 *   @type array      $filled_params  Params resolved so far.
	 *   @type string     $slot_prompt    Human-readable prompt for missing fields.
	 *   @type array      $raw_result     Full BizCity_Tool_Run result.
	 *   @type bool       $verified       Post-execution verification result.
	 *   @type int|null   $artifact_id    Studio output ID if saved.
	 *   @type array      $canvas         Canvas meta when status=canvas_handoff {workshop, launch_url, sse_endpoint, auto_execute, prefill_data}.
	 * }
	 */
	public static function run( string $tool_id, array $raw_input, array $context = [] ): array {
		$start  = microtime( true );
		$caller = $context['caller'] ?? 'unknown';

		error_log( self::LOG . " ═══ START tool={$tool_id} caller={$caller}" );

		// ── 0. Registry check ──
		if ( ! class_exists( 'BizCity_Tool_Run' ) || ! class_exists( 'BizCity_Intent_Tools' ) ) {
			return self::error_result( 'Tool engine not available.', $tool_id, $start );
		}

		$tools  = BizCity_Intent_Tools::instance();
		$schema = $tools->get_schema( $tool_id );

		if ( ! $schema ) {
			return self::error_result( "Tool '{$tool_id}' không được tìm thấy.", $tool_id, $start );
		}

		$input_fields = $schema['input_fields'] ?? [];

		// ══════════════════════════════════════════════════
		//  Phase 1: INPUT PREPARATION
		// ══════════════════════════════════════════════════

		$params    = $raw_input;
		$entities  = $context['entities'] ?? [];
		$variables = $context['variables'] ?? [];
		$message   = $context['message'] ?? '';

		// 1a. Text alias resolution (pipeline tools)
		if ( ! empty( $context['text_aliases'] ) && ! empty( $input_fields ) ) {
			$params = self::resolve_text_aliases( $params, $input_fields, $variables );
		}

		// 1b. LLM slot preparation
		if ( ! empty( $entities ) && ! empty( $input_fields ) ) {
			// SINGLE mode: entity→schema mapping via fast LLM
			$params = self::single_mode_slot_prep( $tool_id, $entities, $input_fields, $message );
		} elseif ( ! empty( $variables ) && ! empty( $input_fields ) ) {
			// Pipeline mode: LLM fill from previous node context
			$params = self::pipeline_slot_fill( $tool_id, $params, $variables, $schema );
		}

		// 1c. Required field validation (skip for legacy fallback)
		$missing = empty( $context['skip_required_check'] )
			? self::check_required( $params, $input_fields )
			: [];

		if ( ! empty( $missing ) ) {
			$prompt   = self::build_slot_prompt( $tool_id, $schema, $params, $missing, $input_fields );
			$duration = (int) ( ( microtime( true ) - $start ) * 1000 );

			error_log( self::LOG . " WAITING_SLOT tool={$tool_id} missing=" . implode( ',', $missing ) );

			return [
				'status'         => 'waiting_slot',
				'success'        => false,
				'message'        => 'Missing required fields.',
				'data'           => [],
				'content'        => '',
				'title'          => '',
				'skill_used'     => '',
				'duration_ms'    => $duration,
				'missing_fields' => $missing,
				'filled_params'  => $params,
				'slot_prompt'    => $prompt,
				'raw_result'     => [],
				'verified'       => false,
				'artifact_id'    => null,
			];
		}

		// ══════════════════════════════════════════════════
		//  Phase 2: EXECUTION
		//  Branch A: Canvas handoff (creative tools)
		//  Branch B: Inline execution (atomic tools)
		// ══════════════════════════════════════════════════

		// ── 2a. Canvas Adapter handoff — creative tools → Canvas Panel ──
		// Tool Wrapper is the UNIVERSAL GATEWAY for all tool execution.
		// Canvas check happens HERE (after slot prep + validation) so that:
		// - dispatch() receives properly resolved $params (not raw entities)
		// - All callers (Shell, it_call_content, it_call_tool) auto-benefit
		// - Output Store logic stays in one place (Phase 3)
		error_log( self::LOG . " [Phase2] Canvas check: tool={$tool_id} adapter_loaded=" . ( class_exists( 'BizCity_Canvas_Adapter' ) ? 'yes' : 'no' ) );

		if ( class_exists( 'BizCity_Canvas_Adapter' )
		     && BizCity_Canvas_Adapter::should_handoff( $tool_id ) ) {

			error_log( self::LOG . " [Phase2] Canvas dispatching: tool={$tool_id} params_keys=" . implode( ',', array_keys( $params ) ) );
			$canvas_result = BizCity_Canvas_Adapter::dispatch( $tool_id, $params, $context );

			if ( ! empty( $canvas_result['canvas_handoff'] ) ) {
				$duration = (int) ( ( microtime( true ) - $start ) * 1000 );

				error_log( self::LOG . " CANVAS_HANDOFF tool={$tool_id}"
					. ' artifact=' . ( $canvas_result['artifact_id'] ?? 0 )
					. " duration={$duration}ms caller={$caller}" );

				return [
					'status'         => 'canvas_handoff',
					'success'        => true,
					'message'        => $canvas_result['reply'] ?? '',
					'data'           => [],
					'content'        => '',
					'title'          => '',
					'skill_used'     => '',
					'duration_ms'    => $duration,
					'missing_fields' => [],
					'filled_params'  => $params,
					'slot_prompt'    => '',
					'raw_result'     => [],
					'verified'       => false,
					'artifact_id'    => $canvas_result['artifact_id'] ?? null,
					'canvas'         => [
						'workshop'     => $canvas_result['workshop'] ?? '',
						'launch_url'   => $canvas_result['launch_url'] ?? '',
						'sse_endpoint' => $canvas_result['sse_endpoint'] ?? '',
						'auto_execute' => $canvas_result['auto_execute'] ?? false,
						'prefill_data' => $canvas_result['prefill_data'] ?? $params,
					],
				];
			}

			// Canvas handoff declined (no handler, handler failed) → fall through to inline
			error_log( self::LOG . " CANVAS_DECLINED tool={$tool_id} error=" . ( $canvas_result['error'] ?? 'unknown' ) );
		}

		// ── 2b. Inline execution (atomic tools, or canvas fallback) ──
		$run_context = self::build_run_context( $context );

		error_log( self::LOG . ' PRE-EXECUTE tool=' . $tool_id
			. ' stream_cb=' . ( ! empty( $run_context['stream_callback'] ) ? 'yes' : 'no' )
			. ' skill_override=' . ( ! empty( $run_context['skill_override'] ) ? 'yes' : 'no' )
			. ' params_keys=' . implode( ',', array_keys( $params ) ) );

		$t_exec     = microtime( true );
		$run_result = BizCity_Tool_Run::execute( $tool_id, $params, $run_context );
		$exec_ms    = (int) ( ( microtime( true ) - $t_exec ) * 1000 );

		error_log( self::LOG . ' POST-EXECUTE tool=' . $tool_id
			. ' success=' . ( ! empty( $run_result['success'] ) ? 'YES' : 'NO' )
			. ' exec_ms=' . $exec_ms
			. ' content_len=' . strlen( $run_result['content'] ?? ( $run_result['data']['content'] ?? '' ) )
			. ' error=' . ( $run_result['error'] ?? '' ) );

		$success    = ! empty( $run_result['success'] );
		$msg        = $run_result['message'] ?? '';
		$data       = $run_result['data'] ?? [];
		$content    = $run_result['content'] ?? $data['content'] ?? '';
		$title      = $run_result['title'] ?? $data['title'] ?? '';
		$skill_used = $run_result['skill_used'] ?? 'none';

		// ══════════════════════════════════════════════════
		//  Phase 3: POST-PROCESSING
		// ══════════════════════════════════════════════════

		// 3a. Studio output save
		$artifact_id = null;
		$save_studio = $context['save_studio'] ?? 'never';
		if ( $success && $save_studio !== 'never' && class_exists( 'BizCity_Output_Store' ) ) {
			$artifact_id = self::maybe_save_studio_output(
				$tool_id, $title, $content, $data, $context, $save_studio
			);
		}

		// 3b. Pipeline evidence
		if ( ! empty( $context['save_evidence'] ) ) {
			self::save_evidence( $tool_id, $params, $run_result, $context );
		}

		$total_ms = (int) ( ( microtime( true ) - $start ) * 1000 );

		error_log( self::LOG . " ═══ END tool={$tool_id} success=" . ( $success ? 'YES' : 'NO' )
			. " total={$total_ms}ms caller={$caller}" );

		return [
			'status'         => $success ? 'completed' : 'error',
			'success'        => $success,
			'message'        => $msg,
			'data'           => $data,
			'content'        => $content,
			'title'          => $title,
			'skill_used'     => $skill_used,
			'duration_ms'    => $exec_ms,
			'missing_fields' => $run_result['missing_fields'] ?? [],
			'filled_params'  => $params,
			'slot_prompt'    => '',
			'raw_result'     => $run_result,
			'verified'       => $run_result['verified'] ?? false,
			'artifact_id'    => $artifact_id,
		];
	}

	/* ================================================================
	 *  Phase 1: Input Preparation
	 * ================================================================ */

	/**
	 * Auto-alias resolution for pipeline text fields.
	 *
	 * Maps common text field aliases (message, text, question, prompt, etc.)
	 * so pipeline tools accept flexible input names.
	 *
	 * Moved from it_call_tool getResults() lines 177-210.
	 */
	public static function resolve_text_aliases( array $params, array $input_fields, array $variables ): array {
		$text_aliases = [ 'message', 'text', 'user_message', 'question', 'prompt', 'content', 'query' ];

		foreach ( $input_fields as $field => $cfg ) {
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
				if ( ! empty( $variables[ $field ] ) ) {
					$params[ $field ] = $variables[ $field ];
				} elseif ( isset( $variables['node#1'] ) && ! empty( $variables['node#1'][ $field ] ) ) {
					$params[ $field ] = $variables['node#1'][ $field ];
				} elseif ( in_array( $field, $text_aliases, true ) ) {
					// Last resort: try text/message from trigger variables
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

		return $params;
	}

	/**
	 * SINGLE mode slot prep: entity→schema mapping via fast LLM call.
	 *
	 * Converts raw classifier entities (natural language keys) → tool input_fields
	 * (schema-defined keys). Skips LLM if entity keys already match schema.
	 *
	 * Moved from Shell Engine's single_mode_slot_prep().
	 *
	 * @param string $tool_id      Tool ID.
	 * @param array  $entities     Raw entities from classifier.
	 * @param array  $input_fields Tool schema input_fields.
	 * @param string $message      Original user message.
	 * @return array Mapped params (tool schema keys).
	 */
	public static function single_mode_slot_prep( string $tool_id, array $entities, array $input_fields, string $message ): array {
		if ( empty( $input_fields ) ) {
			return $entities;
		}

		// Quick check: if entity keys already match schema keys perfectly, skip LLM
		$schema_keys = array_keys( $input_fields );
		$entity_keys = array_keys( $entities );
		$overlap     = array_intersect( $entity_keys, $schema_keys );

		if ( count( $overlap ) === count( $entity_keys ) && ! empty( $entity_keys ) ) {
			$missing_required = [];
			foreach ( $input_fields as $field => $cfg ) {
				if ( ! empty( $cfg['required'] ) && ( ! isset( $entities[ $field ] ) || $entities[ $field ] === '' ) ) {
					$missing_required[] = $field;
				}
			}
			if ( empty( $missing_required ) ) {
				error_log( self::LOG . ' [SINGLE] Slot prep SKIP — entities already match schema' );
				return $entities;
			}
		}

		// LLM call needed — entity keys don't match schema
		if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
			error_log( self::LOG . ' [SINGLE] bizcity_openrouter_chat not available — returning raw entities' );
			return $entities;
		}

		// Build schema description for LLM
		$schema_desc = [];
		foreach ( $input_fields as $field => $cfg ) {
			$req  = ! empty( $cfg['required'] ) ? 'BẮT BUỘC' : 'tùy chọn';
			$type = $cfg['type'] ?? 'text';
			$desc = $cfg['description'] ?? $cfg['prompt'] ?? '';
			$schema_desc[] = "- {$field} ({$type}, {$req})" . ( $desc ? ": {$desc}" : '' );
		}

		$now = function_exists( 'wp_date' )
			? wp_date( 'Y-m-d H:i (l)', null, wp_timezone() )
			: gmdate( 'Y-m-d H:i' );

		$system = "Bạn là slot mapper cho AI assistant.\n"
			. "Map thông tin từ tin nhắn người dùng → input params cho tool.\n\n"
			. "TOOL: {$tool_id}\n"
			. "INPUT SCHEMA:\n" . implode( "\n", $schema_desc ) . "\n\n"
			. "TIN NHẮN GỐC: \"{$message}\"\n"
			. "ENTITIES ĐÃ TRÍCH: " . wp_json_encode( $entities, JSON_UNESCAPED_UNICODE ) . "\n"
			. "THỜI GIAN HIỆN TẠI: {$now}\n\n"
			. "QUY TẮC:\n"
			. "- Điền TẤT CẢ field có thể suy ra từ tin nhắn + entities + thời gian hiện tại\n"
			. "- Ưu tiên BẮT BUỘC trước\n"
			. "- 'chiều mai' = ngày mai 14:00. 'sáng mai' = ngày mai 09:00. 'tối nay' = hôm nay 19:00\n"
			. "- Nếu chỉ có start_time, end_time = start_time + 1 giờ\n"
			. "- Datetime format: YYYY-MM-DD HH:mm\n"
			. "- Nếu KHÔNG THỂ suy ra → bỏ trống field đó (để hệ thống hỏi user)\n"
			. "- Trả về CHỈ JSON object, không markdown, không giải thích";

		error_log( self::LOG . " [SINGLE] LLM slot prep: tool={$tool_id}, entities=" . wp_json_encode( $entities, JSON_UNESCAPED_UNICODE ) );

		$response = bizcity_openrouter_chat(
			[
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user', 'content' => 'Map tin nhắn → tool input. JSON only.' ],
			],
			[ 'temperature' => 0.1, 'max_tokens' => 500, 'purpose' => 'fast' ]
		);

		if ( empty( $response['success'] ) || empty( $response['message'] ) ) {
			error_log( self::LOG . ' [SINGLE] LLM slot prep failed: ' . ( $response['error'] ?? 'no response' ) );
			return $entities;
		}

		$raw = self::strip_llm_json( $response['message'] );

		if ( preg_match( '/\{[\s\S]*\}/u', $raw, $matches ) ) {
			$mapped = json_decode( $matches[0], true );
			if ( is_array( $mapped ) ) {
				// Only keep fields that exist in schema
				$valid = [];
				foreach ( $mapped as $k => $v ) {
					if ( isset( $input_fields[ $k ] ) && $v !== '' && $v !== null ) {
						$valid[ $k ] = $v;
					}
				}
				error_log( self::LOG . ' [SINGLE] LLM slot prep OK: ' . wp_json_encode( array_keys( $valid ), JSON_UNESCAPED_UNICODE ) );
				return $valid;
			}
		}

		error_log( self::LOG . ' [SINGLE] LLM slot prep: could not parse response' );
		return $entities;
	}

	/**
	 * Pipeline slot fill: LLM context→schema mapping from workflow variables.
	 *
	 * When template resolution leaves required params empty, uses LLM to
	 * map available context from previous pipeline nodes to tool inputs.
	 *
	 * Moved from it_call_tool's prepare_input_with_llm().
	 *
	 * @param string $tool_id   Target tool name.
	 * @param array  $params    Currently resolved params (may have empty values).
	 * @param array  $variables Workflow variables from previous nodes.
	 * @param array  $schema    Tool schema with input_fields.
	 * @return array Params with empty required fields filled by LLM.
	 */
	public static function pipeline_slot_fill( string $tool_id, array $params, array $variables, array $schema ): array {
		$input_fields = $schema['input_fields'] ?? [];
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

		error_log( self::LOG . ' LLM pipeline fill: tool=' . $tool_id . ' missing=' . implode( ',', $missing ) );

		// Build compact context from all node variables
		$context_str = self::build_context_for_llm( $variables );

		// Build schema description
		$schema_desc = [];
		foreach ( $input_fields as $field => $cfg ) {
			$req  = ! empty( $cfg['required'] ) ? 'BẮT BUỘC' : 'tùy chọn';
			$type = $cfg['type'] ?? 'text';
			$desc = $cfg['description'] ?? '';
			$schema_desc[] = "- {$field} ({$type}, {$req}): {$desc}";
		}

		$system = "Bạn là data mapper cho workflow automation.\n"
			. "Map dữ liệu context từ các node trước → input params cho tool.\n\n"
			. "TOOL: {$tool_id}\n"
			. "INPUT SCHEMA:\n" . implode( "\n", $schema_desc ) . "\n\n"
			. "DỮ LIỆU CÓ SẴN TỪ CÁC NODE TRƯỚC:\n{$context_str}\n\n"
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
			error_log( self::LOG . ' LLM pipeline fill failed: ' . ( $response['error'] ?? 'no response' ) );
			return $params;
		}

		$raw = self::strip_llm_json( $response['message'] );

		if ( preg_match( '/\{[\s\S]*\}/u', $raw, $matches ) ) {
			$fixed = json_decode( $matches[0], true );
			if ( is_array( $fixed ) ) {
				error_log( self::LOG . ' LLM pipeline fill OK: ' . wp_json_encode( array_keys( $fixed ), JSON_UNESCAPED_UNICODE ) );
				// Only fill empty fields — preserve existing values
				foreach ( $fixed as $k => $v ) {
					if ( ! isset( $params[ $k ] ) || $params[ $k ] === '' || $params[ $k ] === false || $params[ $k ] === null ) {
						$params[ $k ] = $v;
					}
				}
				return $params;
			}
		}

		error_log( self::LOG . ' LLM pipeline fill: could not parse response' );
		return $params;
	}

	/**
	 * Check which required fields are still missing.
	 *
	 * @param array $params       Resolved parameters.
	 * @param array $input_fields Tool schema input_fields.
	 * @return array List of missing required field names.
	 */
	public static function check_required( array $params, array $input_fields ): array {
		$missing = [];
		foreach ( $input_fields as $field => $cfg ) {
			if ( ! empty( $cfg['required'] ) && ( ! isset( $params[ $field ] ) || $params[ $field ] === '' || $params[ $field ] === null ) ) {
				$missing[] = $field;
			}
		}
		return $missing;
	}

	/**
	 * Build human-readable prompt for missing required fields.
	 *
	 * Common format used by Shell Engine, it_call_tool, it_call_content:
	 *   ✅ filled fields
	 *   ❓ missing required fields with types
	 *
	 * @param string $tool_id      Tool identifier.
	 * @param array  $schema       Full tool schema.
	 * @param array  $params       Current resolved params.
	 * @param array  $missing      Missing field names.
	 * @param array  $input_fields Tool schema input_fields.
	 * @return string Human-readable prompt.
	 */
	public static function build_slot_prompt( string $tool_id, array $schema, array $params, array $missing, array $input_fields ): string {
		$lines   = [];
		$lines[] = '📝 **' . ( $schema['description'] ?? $tool_id ) . '** — cần thêm thông tin:';
		$lines[] = '';

		// Show what we already have
		$filled = [];
		foreach ( $params as $k => $v ) {
			if ( $v !== '' && $v !== null && isset( $input_fields[ $k ] ) ) {
				$label    = $input_fields[ $k ]['description'] ?? $input_fields[ $k ]['prompt'] ?? $k;
				$filled[] = '✅ ' . $label . ': ' . mb_substr( (string) $v, 0, 80 );
			}
		}
		if ( $filled ) {
			$lines[] = implode( "\n", $filled );
			$lines[] = '';
		}

		// Show what's missing
		foreach ( $missing as $field ) {
			$field_cfg = $input_fields[ $field ] ?? [];
			$desc      = $field_cfg['description'] ?? $field_cfg['prompt'] ?? $field;
			$type      = $field_cfg['type'] ?? 'text';
			$lines[]   = '❓ **' . $desc . '** (' . $type . ')';
		}

		$lines[] = '';
		$lines[] = 'Vui lòng cung cấp thông tin còn thiếu.';

		return implode( "\n", $lines );
	}

	/* ================================================================
	 *  Phase 3: Post-Processing
	 * ================================================================ */

	/**
	 * Save studio output artifact based on context hints.
	 *
	 * @param string $tool_id     Tool identifier.
	 * @param string $title       Content title.
	 * @param string $content     Content body.
	 * @param array  $data        Tool output data.
	 * @param array  $context     Execution context.
	 * @param string $save_mode   'always' or 'auto'.
	 * @return int|null Artifact ID if saved, null otherwise.
	 */
	public static function maybe_save_studio_output( string $tool_id, string $title, string $content, array $data, array $context, string $save_mode ): ?int {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return null;
		}

		$session_id = $context['session_id'] ?? '';
		$user_id    = (int) ( $context['user_id'] ?? get_current_user_id() );

		if ( $save_mode === 'always' ) {
			// it_call_content behavior: always save content artifact
			$has_content = ! empty( $content ) || ! empty( $title );
			if ( ! $has_content ) {
				return null;
			}

			return BizCity_Output_Store::save_artifact( [
				'tool_id'    => $tool_id ?: 'tool_wrapper',
				'caller'     => 'pipeline',
				'session_id' => $session_id,
				'user_id'    => $user_id,
				'task_id'    => null,
				'data'       => [
					'title'   => $title ?: 'Content — ' . $tool_id,
					'content' => $content,
				],
			], 'content' );
		}

		if ( $save_mode === 'auto' ) {
			// it_call_tool behavior: check tool_type against CONTENT_TOOL_TYPES
			$tool_type = '';
			if ( class_exists( 'BizCity_Intent_Tools' ) ) {
				$tool_schema = BizCity_Intent_Tools::instance()->get( $tool_id );
				$tool_type   = $tool_schema['tool_type'] ?? '';
			}

			$is_content_tool = in_array( $tool_type, BizCity_Output_Store::CONTENT_TOOL_TYPES, true );
			$has_content     = ! empty( $content ) || ! empty( $title );
			$resource_url    = $data['url'] ?? '';
			$resource_id     = $data['id'] ?? '';

			if ( $is_content_tool && $has_content ) {
				return BizCity_Output_Store::save_artifact( [
					'tool_id'    => $tool_id,
					'caller'     => 'pipeline',
					'session_id' => $session_id,
					'user_id'    => $user_id,
					'task_id'    => null,
					'data'       => [
						'title'   => $title,
						'content' => $content,
					],
					'success'  => true,
					'verified' => true,
				], $tool_type ?: 'content' );
			} elseif ( ! empty( $resource_url ) ) {
				// Distribution tool — update most recent output with URL
				self::update_studio_output_with_url( $session_id, $user_id, $resource_url, (int) ( $resource_id ?: 0 ) );
			}
		}

		return null;
	}

	/**
	 * Save tool execution evidence to intent_conversations table.
	 *
	 * Moved from it_call_tool's save_pipeline_evidence().
	 *
	 * @param string $tool_id    Tool name.
	 * @param array  $params     Input params used.
	 * @param array  $run_result Full BizCity_Tool_Run result.
	 * @param array  $context    Execution context.
	 */
	public static function save_evidence( string $tool_id, array $params, array $run_result, array $context ): void {
		if ( ! class_exists( 'BizCity_Intent_Database' ) ) {
			return;
		}

		$pipeline_id = $context['pipeline_id'] ?? '';
		if ( empty( $pipeline_id ) ) {
			return;
		}

		$db = BizCity_Intent_Database::instance();

		$success    = ! empty( $run_result['success'] );
		$node_id    = $context['node_id'] ?? '';
		$session_id = $context['session_id'] ?? '';
		$user_id    = $context['user_id'] ?? get_current_user_id();
		$data       = $run_result['data'] ?? [];

		$conv_id = 'tool_' . $tool_id . '_' . $pipeline_id . '_n' . $node_id;

		$conv_data = [
			'conversation_id'    => $conv_id,
			'user_id'            => (int) $user_id,
			'session_id'         => $session_id,
			'channel'            => 'pipeline',
			'goal'               => $tool_id,
			'goal_label'         => $data['title'] ?? $tool_id,
			'status'             => $success ? 'COMPLETED' : 'FAILED',
			'parent_pipeline_id' => $pipeline_id,
			'step_index'         => (int) str_replace( 'node_', '', $node_id ),
			'slots_json'         => wp_json_encode( $params, JSON_UNESCAPED_UNICODE ),
			'context_snapshot'   => wp_json_encode( [
				'result'   => [
					'success'        => $run_result['success'] ?? false,
					'message'        => $run_result['message'] ?? '',
					'data'           => $data,
					'missing_fields' => $run_result['missing_fields'] ?? [],
					'duration_ms'    => $run_result['duration_ms'] ?? 0,
				],
				'verified' => $success && ! empty( $data['id'] ),
			], JSON_UNESCAPED_UNICODE ),
		];

		if ( method_exists( $db, 'insert_conversation' ) ) {
			$db->insert_conversation( $conv_data );
		} elseif ( method_exists( $db, 'update_conversation' ) ) {
			$db->update_conversation( $conv_id, $conv_data );
		} else {
			error_log( self::LOG . ' evidence_save FAILED: no insert/update method' );
			return;
		}

		error_log( self::LOG . ' evidence_saved conv_id=' . $conv_id . ' status=' . ( $success ? 'COMPLETED' : 'FAILED' ) );
	}

	/* ================================================================
	 *  Internal Helpers
	 * ================================================================ */

	/**
	 * Build BizCity_Tool_Run context from wrapper context.
	 */
	private static function build_run_context( array $context ): array {
		$run = [
			'caller'       => $context['caller'] ?? 'tool_wrapper',
			'session_id'   => $context['session_id'] ?? '',
			'user_id'      => $context['user_id'] ?? get_current_user_id(),
			'channel'      => $context['channel'] ?? '',
			'conv_id'      => $context['conv_id'] ?? '',
			'goal'         => $context['goal'] ?? '',
			'goal_label'   => $context['goal_label'] ?? '',
			'character_id' => $context['character_id'] ?? '',
			'message_id'   => $context['message_id'] ?? '',
		];

		// Propagate optional context keys
		if ( ! empty( $context['skill_override'] ) ) {
			$run['skill_override'] = $context['skill_override'];
		}
		if ( ! empty( $context['stream_callback'] ) && is_callable( $context['stream_callback'] ) ) {
			$run['stream_callback'] = $context['stream_callback'];
		}
		if ( ! empty( $context['project_id'] ) ) {
			$run['project_id'] = $context['project_id'];
		}

		return $run;
	}

	/**
	 * Build compact context summary from workflow variables for LLM fill.
	 *
	 * Moved from it_call_tool's build_context_for_llm().
	 */
	private static function build_context_for_llm( array $variables ): string {
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
						$summary = self::extract_context_fields( $parsed );
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
	 *
	 * Moved from it_call_tool's extract_context_fields().
	 */
	private static function extract_context_fields( array $result ): array {
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
	 * Update the most recent studio output with a distribution URL.
	 *
	 * Moved from it_call_tool's update_studio_output_with_url().
	 */
	private static function update_studio_output_with_url( string $session_id, int $user_id, string $external_url, int $external_post_id = 0 ): void {
		if ( ! class_exists( 'BCN_Schema_Extend' ) || empty( $external_url ) ) {
			return;
		}

		global $wpdb;
		$table = BCN_Schema_Extend::table_studio_outputs();

		$output_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table}
			 WHERE session_id = %s AND user_id = %d AND (external_url = '' OR external_url IS NULL)
			 ORDER BY created_at DESC LIMIT 1",
			$session_id, $user_id
		) );

		if ( $output_id ) {
			BizCity_Output_Store::update_distribution_result( $output_id, $external_url, $external_post_id );
			error_log( self::LOG . " studio_output updated: id={$output_id} url={$external_url}" );
		}
	}

	/**
	 * Strip markdown code fences from LLM JSON response.
	 */
	private static function strip_llm_json( string $raw ): string {
		$raw = preg_replace( '/^```(?:json)?\s*/m', '', $raw );
		$raw = preg_replace( '/```\s*$/m', '', $raw );
		return trim( $raw );
	}

	/**
	 * Build a standardized error result.
	 */
	private static function error_result( string $message, string $tool_id, float $start_time ): array {
		$duration = (int) ( ( microtime( true ) - $start_time ) * 1000 );
		error_log( self::LOG . " ERROR tool={$tool_id}: {$message}" );

		return [
			'status'         => 'error',
			'success'        => false,
			'message'        => $message,
			'data'           => [],
			'content'        => '',
			'title'          => '',
			'skill_used'     => '',
			'duration_ms'    => $duration,
			'missing_fields' => [],
			'filled_params'  => [],
			'slot_prompt'    => '',
			'raw_result'     => [],
			'verified'       => false,
			'artifact_id'    => null,
		];
	}
}
