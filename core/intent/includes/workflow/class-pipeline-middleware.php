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
 * BizCity Pipeline Middleware
 *
 * Subscribes to executor hooks (waic_pipeline_pre_execute / waic_pipeline_post_execute).
 * Handles HIL slot gathering, variable injection, evidence saving, verify, and todos checkpoint.
 *
 * Phase 1.1 v1.3 — Executor Middleware architecture.
 * Only active when executionState contains pipeline_id (manual workflows untouched).
 *
 * Fallback: Every critical operation is wrapped in try-catch. If middleware fails,
 * execution proceeds normally (graceful degradation). Trace logs fire on all 3 channels.
 *
 * @package BizCity_Intent
 * @since   3.9.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Pipeline_Middleware {

	/** @var self|null */
	private static $instance = null;

	/** @var float Middleware start time for per-request profiling. */
	private $mw_start = 0;

	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot: register hooks on the automation executor.
	 */
	public function boot() {
		add_filter( 'waic_pipeline_pre_execute',  [ $this, 'pre_execute' ],  10, 4 );
		add_filter( 'waic_pipeline_post_execute', [ $this, 'post_execute' ], 10, 5 );
		add_action( 'waic_pipeline_node_failed',  [ $this, 'on_node_failed' ], 10, 5 );
	}

	/* ================================================================
	 *  TRACE LOGGING — 3-channel output (error_log + SSE + Job Trace)
	 * ================================================================ */

	/**
	 * Fire a trace log to all available channels.
	 *
	 * @param string $step        Granular step name (e.g. 'mw:schema_resolve', 'mw:execute_error').
	 * @param string $pipeline_id Pipeline ID (for grouping).
	 * @param string $node_code   Block code being executed.
	 * @param string $message     Human-readable label (shown in bizc-working).
	 * @param array  $data        Structured data payload.
	 * @param string $level       info | warn | error
	 */
	private function trace( $step, $pipeline_id, $node_code, $message, array $data = [], $level = 'info' ) {
		$elapsed_ms = $this->mw_start > 0 ? round( ( microtime( true ) - $this->mw_start ) * 1000, 2 ) : 0;
		$prefix     = "[Pipeline MW] [{$step}] [{$node_code}]";

		// Channel 1: error_log (always available)
		$log_msg = "{$prefix} {$message}";
		if ( ! empty( $data ) ) {
			$compact = [];
			foreach ( $data as $k => $v ) {
				$compact[] = $k . '=' . ( is_array( $v ) ? wp_json_encode( $v ) : $v );
			}
			$log_msg .= ' | ' . implode( ', ', $compact );
		}
		error_log( $log_msg );

		// Channel 2: SSE pipeline_log → bizc-working (granular step name)
		if ( has_action( 'bizcity_intent_pipeline_log' ) ) {
			do_action( 'bizcity_intent_pipeline_log', $step, array_merge( [
				'pipeline_id' => $pipeline_id,
				'node_code'   => $node_code,
				'label'       => $message,
			], $data ), $level, $elapsed_ms );
		}

		// Channel 3: Job Trace (if one is active — appears in WorkingIndicator)
		if ( class_exists( 'BizCity_Job_Trace' ) ) {
			$trace = BizCity_Job_Trace::current();
			if ( $trace ) {
				$trace->log( "{$prefix} {$message}", $data, $level );
			}
		}
	}

	/* ================================================================
	 *  PRE-EXECUTE — HIL Gather + Variable Injection
	 * ================================================================ */

	/**
	 * Pre-execute middleware.
	 *
	 * Wrapped in top-level try-catch: on ANY exception, returns $context
	 * unchanged so the block runs normally (graceful degradation).
	 *
	 * @param array  $context        [ 'proceed', 'node', 'variables', 'block' ]
	 * @param string $pipeline_id    Pipeline identifier.
	 * @param string $node_code      Block code (e.g. 'ai_generate_content').
	 * @param array  $execution_state Full executor state.
	 * @return array Modified context (may include 'waiting' or 'injected_node').
	 */
	public function pre_execute( $context, $pipeline_id, $node_code, $execution_state ) {
		$this->mw_start = microtime( true );

		try {
			return $this->do_pre_execute( $context, $pipeline_id, $node_code, $execution_state );
		} catch ( \Throwable $e ) {
			// ── FALLBACK: middleware crashed → let block run as-is ──
			$this->trace( 'mw:error', $pipeline_id, $node_code, 'Pre-execute EXCEPTION — fallback to normal execution', [
				'exception' => $e->getMessage(),
				'file'      => basename( $e->getFile() ) . ':' . $e->getLine(),
			], 'error' );
			return $context;
		}
	}

	/**
	 * Inner pre-execute logic (extracted for try-catch wrapping).
	 */
	private function do_pre_execute( $context, $pipeline_id, $node_code, $execution_state ) {
		$node      = $context['node'];
		$variables = $context['variables'];
		$block     = $context['block'];
		$node_id   = $node['id'] ?? '';

		// Skip implicit pipeline nodes (they handle their own logic)
		if ( in_array( $node_code, [ 'it_todos_planner', 'it_summary_verifier' ], true ) ) {
			$this->trace( 'mw:schema_resolve', $pipeline_id, $node_code, 'Skipped (implicit pipeline node)' );
			return $context;
		}

		$this->trace( 'mw:schema_resolve', $pipeline_id, $node_code, $node_code . ': Phân tích cấu hình', [
			'node_id' => $node_id,
		] );

		// 1. Resolve schema (with fallback)
		$schema = [];
		try {
			$schema = BizCity_Block_Schema_Adapter::resolve( $node_code, $block );
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, 'Schema resolve failed — chạy block không HIL', [
				'operation' => 'schema_resolve',
				'error'     => $e->getMessage(),
			], 'warn' );
			return $context;
		}

		if ( empty( $schema ) ) {
			$this->trace( 'mw:schema_resolve', $pipeline_id, $node_code, $node_code . ': Không cần HIL', [
				'fields' => [],
			] );
			return $context;
		}

		$this->trace( 'mw:schema_resolve', $pipeline_id, $node_code, $node_code . ': Phân tích cấu hình', [
			'fields'   => array_keys( $schema ),
			'required' => array_keys( array_filter( $schema, function( $f ) { return ! empty( $f['required'] ); } ) ),
		] );

		// 2. Check HIL state (for resume after waiting)
		$hil_state = $this->get_hil_state( $pipeline_id, $node_id );

		if ( $hil_state === 'rejected' ) {
			$this->trace( 'mw:hil_rejected', $pipeline_id, $node_code, $node_code . ': Bỏ qua (người dùng từ chối)' );
			$this->safe_update_todo( $pipeline_id, $node_code, 'CANCELLED' );
			$context['waiting'] = false;
			$context['proceed'] = false;
			return $context;
		}

		if ( $hil_state ) {
			$this->trace( 'mw:hil_check', $pipeline_id, $node_code, $node_code . ': Kiểm tra HIL', [ 'hil_state' => $hil_state ] );
		}

		// 3. Resolve filled values from workflow variables
		$filled = $this->resolve_from_variables( $schema, $variables, $node );

		$this->trace( 'mw:variables_resolved', $pipeline_id, $node_code, $node_code . ': Thu thập biến (' . count( $filled ) . '/' . count( $schema ) . ')', [
			'filled_count' => count( $filled ),
			'total'        => count( $schema ),
			'filled_keys'  => array_keys( $filled ),
		] );

		// 4. Check missing required fields
		$missing = BizCity_Block_Schema_Adapter::check_missing( $schema, $filled );

		// 5. All required present → inject & proceed
		if ( empty( $missing ) ) {
			$this->trace( 'mw:inject', $pipeline_id, $node_code, $node_code . ': Nạp dữ liệu', [
				'injected_keys' => array_keys( $filled ),
			] );
			$context['injected_node'] = $this->inject_into_node( $node, $filled );
			$this->clear_hil_state( $pipeline_id, $node_id );
			return $context;
		}

		// 6. Missing required + already confirmed with HIL answers → merge & proceed
		if ( $hil_state === 'confirmed' ) {
			$hil_answers = $this->get_hil_answers( $pipeline_id, $node_id );
			$this->trace( 'mw:hil_resume', $pipeline_id, $node_code, $node_code . ': Tiếp tục (đã nhận dữ liệu)', [
				'hil_keys' => array_keys( $hil_answers ),
			] );
			$merged = array_merge( $filled, $hil_answers );
			$context['injected_node'] = $this->inject_into_node( $node, $merged );
			$this->clear_hil_state( $pipeline_id, $node_id );
			return $context;
		}

		// 7. Missing required + no HIL yet → send prompt & pause
		$missing_names = array_column( $missing, 'name' );
		$this->trace( 'mw:hil_waiting', $pipeline_id, $node_code, $node_code . ': Chờ người dùng', [
			'missing' => $missing_names,
		] );

		$this->send_hil_prompt( $pipeline_id, $node_code, $missing, $execution_state );
		$this->set_hil_state( $pipeline_id, $node_id, 'waiting' );
		$this->safe_update_todo( $pipeline_id, $node_code, 'WAITING_USER' );

		$context['waiting'] = time() + 86400; // 24h timeout
		$context['result']  = [
			'hil_missing' => $missing_names,
			'tool'        => $node_code,
		];
		return $context;
	}

	/* ================================================================
	 *  POST-EXECUTE — Evidence + Verify + ToDos Checkpoint
	 * ================================================================ */

	/**
	 * Post-execute middleware.
	 *
	 * Wrapped in top-level try-catch: on ANY exception, returns $result
	 * unchanged so the pipeline continues (graceful degradation).
	 *
	 * @param array  $result          Block getResults() output.
	 * @param string $pipeline_id     Pipeline identifier.
	 * @param string $node_code       Block code.
	 * @param array  $node            Full node object.
	 * @param array  $execution_state Full executor state.
	 * @return array Unmodified result (pass-through).
	 */
	public function post_execute( $result, $pipeline_id, $node_code, $node, $execution_state ) {
		$this->mw_start = microtime( true );

		try {
			return $this->do_post_execute( $result, $pipeline_id, $node_code, $node, $execution_state );
		} catch ( \Throwable $e ) {
			// ── FALLBACK: middleware crashed → return result unchanged ──
			$this->trace( 'mw:error', $pipeline_id, $node_code, 'Post-execute EXCEPTION — result passed through', [
				'exception' => $e->getMessage(),
				'file'      => basename( $e->getFile() ) . ':' . $e->getLine(),
			], 'error' );
			return $result;
		}
	}

	/**
	 * Inner post-execute logic (extracted for try-catch wrapping).
	 */
	private function do_post_execute( $result, $pipeline_id, $node_code, $node, $execution_state ) {
		// Skip implicit pipeline nodes
		if ( in_array( $node_code, [ 'it_todos_planner', 'it_summary_verifier' ], true ) ) {
			return $result;
		}

		$node_id     = $node['id'] ?? '';
		$success     = empty( $result['error'] ) && ( ( $result['status'] ?? 0 ) === 3 );
		$result_data = $result['result'] ?? $result;

		$this->trace( 'mw:execute_done', $pipeline_id, $node_code, $node_code . ': ' . ( $success ? 'Xong' : 'Lỗi' ), [
			'node_id'  => $node_id,
			'success'  => $success ? 'true' : 'false',
			'has_error'=> ! empty( $result['error'] ) ? 'true' : 'false',
		] );

		// 1. Verify: check resource actually exists
		$verified = false;
		try {
			$verified = $this->verify_result( $node_code, $result_data );
			$this->trace( 'mw:verify', $pipeline_id, $node_code, $node_code . ': Xác minh kết quả', [
				'verified' => $verified ? 'true' : 'false',
			] );
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, $node_code . ': Verify failed', [
				'operation' => 'verify',
				'error'     => $e->getMessage(),
			], 'warn' );
		}

		// 2. Evidence → intent_conversations (isolated try-catch)
		try {
			if ( class_exists( 'BizCity_Intent_Pipeline_Evidence' ) ) {
				$step_index = $execution_state['node_step_map'][ $node_id ] ?? 0;
				$user_id    = $execution_state['user_id'] ?? 0;
				$session_id = $execution_state['session_id'] ?? '';

				$evidence_id = BizCity_Intent_Pipeline_Evidence::save( [
					'pipeline_id' => $pipeline_id,
					'step_index'  => $step_index,
					'tool_name'   => $node_code,
					'user_id'     => $user_id,
					'session_id'  => $session_id,
					'result'      => $result,
					'verified'    => $verified,
				] );

				$this->trace( 'mw:evidence_saved', $pipeline_id, $node_code, $node_code . ': Lưu bằng chứng', [
					'evidence_id' => $evidence_id ?: 'failed',
					'step_index'  => $step_index,
				] );
			}
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, $node_code . ': Evidence save failed', [
				'operation' => 'evidence_save',
				'error'     => $e->getMessage(),
			], 'error' );
		}

		// 3. ToDos checkpoint (isolated try-catch)
		try {
			if ( class_exists( 'BizCity_Intent_Todos' ) ) {
				$output_summary = '';
				if ( $success && is_array( $result_data ) ) {
					$parts = [];
					if ( ! empty( $result_data['post_id'] ) )  $parts[] = 'post #' . $result_data['post_id'];
					if ( ! empty( $result_data['post_url'] ) )  $parts[] = $result_data['post_url'];
					if ( ! empty( $result_data['id'] ) )        $parts[] = 'id #' . $result_data['id'];
					if ( ! empty( $result_data['url'] ) )       $parts[] = $result_data['url'];
					if ( ! empty( $result_data['message'] ) )   $parts[] = mb_substr( $result_data['message'], 0, 100 );
					$output_summary = implode( ' | ', $parts );
				}

				$todo_status = $success ? 'COMPLETED' : 'FAILED';
				$todo_score  = $verified ? 95 : ( $success ? 75 : 0 );

				// B8 fix: capture node_input_json from node settings and node_output_json from result
				$node_input  = $node['data']['settings'] ?? [];
				$node_output = is_array( $result_data ) ? $result_data : [];

				BizCity_Intent_Todos::update_status(
					$pipeline_id,
					$node_code,
					$todo_status,
					[
						'node_id'          => $node_id,
						'score'            => $todo_score,
						'output_summary'   => $output_summary,
						'error_message'    => $result['error'] ?? '',
						'node_input_json'  => $node_input,
						'node_output_json' => $node_output,
					]
				);

				$this->trace( 'mw:todo_checkpoint', $pipeline_id, $node_code, 'Tiến độ: ' . $todo_status, [
					'status' => $todo_status,
					'score'  => $todo_score,
				] );
			}
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, $node_code . ': Todo update failed', [
				'operation' => 'todo_update',
				'error'     => $e->getMessage(),
			], 'error' );
		}

		// 4. Clear HIL state
		$this->clear_hil_state( $pipeline_id, $node_id );

		// 5. Send node result message to user chat (Execute Messenger)
		try {
			if ( class_exists( 'BizCity_Pipeline_Messenger' ) ) {
				$step_index = $execution_state['node_step_map'][ $node_id ] ?? 0;
				// B9 fix: node_step_map keys are node IDs ("1","4","5"), not block codes
				// Count only action nodes by examining the nodes array in execution state
				$all_nodes   = $execution_state['nodes'] ?? [];
				$total_steps = 0;
				if ( ! empty( $all_nodes ) ) {
					foreach ( $all_nodes as $n ) {
						$ntype = $n['type'] ?? '';
						$ncode = $n['data']['code'] ?? '';
						if ( $ntype === 'action' && ! in_array( $ncode, [ 'it_todos_planner', 'it_summary_verifier' ], true ) ) {
							$total_steps++;
						}
					}
				} else {
					// Fallback: subtract known overhead (trigger=1, planner=1, verifier=1)
					$total_steps = max( 1, count( $execution_state['node_step_map'] ?? [] ) - 3 );
				}

				if ( $success ) {
					BizCity_Pipeline_Messenger::send_node_result(
						$execution_state,
						$node_code,
						is_array( $result_data ) ? $result_data : [],
						$step_index,
						$total_steps
					);
				} else {
					BizCity_Pipeline_Messenger::send_error(
						$execution_state,
						$node_code,
						$result['error'] ?? 'Unknown error',
						$step_index,
						$total_steps
					);
				}

				$this->trace( 'mw:messenger_sent', $pipeline_id, $node_code, $node_code . ': Gửi kết quả vào chat', [
					'step'    => $step_index,
					'total'   => $total_steps,
					'success' => $success ? 'true' : 'false',
				] );
			}
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, 'Messenger send failed', [
				'operation' => 'messenger_send',
				'error'     => $e->getMessage(),
			], 'warn' );
		}

		$total_ms = round( ( microtime( true ) - $this->mw_start ) * 1000, 2 );
		$this->trace( 'mw:bridge_next', $pipeline_id, $node_code, 'Chuyển sang bước tiếp theo', [
			'middleware_ms' => $total_ms,
		] );

		return $result;
	}

	/* ================================================================
	 *  Safe Helpers (never throw)
	 * ================================================================ */

	/**
	 * Safe todo update — wraps in try-catch so failure is logged, not fatal.
	 */
	private function safe_update_todo( $pipeline_id, $node_code, $status, array $extra = [] ) {
		try {
			if ( class_exists( 'BizCity_Intent_Todos' ) ) {
				BizCity_Intent_Todos::update_status( $pipeline_id, $node_code, $status, $extra );
			}
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, 'Todo update failed', [
				'operation' => 'todo_update',
				'error'     => $e->getMessage(),
			], 'warn' );
		}
	}

	/* ================================================================
	 *  Variable Resolution
	 * ================================================================ */

	/**
	 * Resolve field values from workflow variables for a given schema.
	 *
	 * Checks flat variables, node-scoped variables (node#N.field), and
	 * the block's existing param values (from builder settings).
	 *
	 * @param array $schema    Pipeline schema fields.
	 * @param array $variables All workflow variables.
	 * @param array $node      Current node object.
	 * @return array Key-value map of resolved values.
	 */
	private function resolve_from_variables( array $schema, array $variables, array $node ) {
		$resolved = [];
		$settings = $node['data']['settings'] ?? [];

		foreach ( $schema as $key => $field ) {
			// 1. Existing setting already has a concrete (non-template) value
			$setting_val = $settings[ $key ] ?? '';
			if ( is_string( $setting_val ) && $setting_val !== '' && strpos( $setting_val, '{{' ) === false ) {
				$resolved[ $key ] = $setting_val;
				continue;
			}

			// 2. Template value → try to resolve from variables
			if ( is_string( $setting_val ) && strpos( $setting_val, '{{' ) !== false ) {
				$resolved_val = $this->resolve_template( $setting_val, $variables );
				if ( $resolved_val !== '' && $resolved_val !== $setting_val ) {
					$resolved[ $key ] = $resolved_val;
					continue;
				}
			}

			// 3. Direct variable match (flat or any node scope)
			if ( isset( $variables[ $key ] ) && $variables[ $key ] !== '' ) {
				$resolved[ $key ] = $variables[ $key ];
				continue;
			}

			// 4. Search in node-scoped variables
			foreach ( $variables as $var_key => $var_val ) {
				if ( strpos( $var_key, 'node#' ) === 0 && is_array( $var_val ) ) {
					if ( isset( $var_val[ $key ] ) && $var_val[ $key ] !== '' ) {
						$resolved[ $key ] = $var_val[ $key ];
						break;
					}
				}
			}
		}

		return $resolved;
	}

	/**
	 * Resolve a {{node#N.field}} template string against variables.
	 *
	 * @param string $template Template string.
	 * @param array  $variables Workflow variables.
	 * @return string Resolved value or empty string.
	 */
	private function resolve_template( $template, array $variables ) {
		return preg_replace_callback( '/\{\{(node#\d+)\.([^}]+)\}\}/', function( $m ) use ( $variables ) {
			$node_key  = $m[1];
			$field_key = $m[2];
			if ( isset( $variables[ $node_key ][ $field_key ] ) ) {
				return (string) $variables[ $node_key ][ $field_key ];
			}
			return '';
		}, $template );
	}

	/* ================================================================
	 *  Node Injection
	 * ================================================================ */

	/**
	 * Inject resolved values into node settings so block reads them via getParam().
	 *
	 * @param array $node  Full node object (will NOT be mutated).
	 * @param array $slots Key-value resolved field values.
	 * @return array New node object with injected settings.
	 */
	private function inject_into_node( array $node, array $slots ) {
		$injected = $node;
		foreach ( $slots as $key => $value ) {
			$injected['data']['settings'][ $key ] = $value;
		}
		return $injected;
	}

	/* ================================================================
	 *  HIL State Management (transient-based)
	 * ================================================================ */

	/**
	 * Get HIL transient key.
	 */
	private function hil_key( $pipeline_id, $node_id ) {
		return 'waic_hil_' . md5( $pipeline_id . '_' . $node_id );
	}

	/**
	 * Get current HIL state for a node.
	 *
	 * @return string|false 'waiting', 'confirmed', 'rejected', or false.
	 */
	private function get_hil_state( $pipeline_id, $node_id ) {
		$data = get_transient( $this->hil_key( $pipeline_id, $node_id ) );
		if ( ! is_array( $data ) ) {
			return false;
		}
		return $data['status'] ?? false;
	}

	/**
	 * Set HIL state for a node.
	 */
	private function set_hil_state( $pipeline_id, $node_id, $status, $answers = [] ) {
		set_transient( $this->hil_key( $pipeline_id, $node_id ), [
			'status'    => $status,
			'answers'   => $answers,
			'timestamp' => time(),
		], 86400 ); // 24h TTL
	}

	/**
	 * Get HIL answers (user-provided slot values).
	 *
	 * @return array Key-value answers.
	 */
	private function get_hil_answers( $pipeline_id, $node_id ) {
		$data = get_transient( $this->hil_key( $pipeline_id, $node_id ) );
		if ( ! is_array( $data ) ) {
			return [];
		}
		return $data['answers'] ?? [];
	}

	/**
	 * Clear HIL state after execution or cancel.
	 */
	private function clear_hil_state( $pipeline_id, $node_id ) {
		delete_transient( $this->hil_key( $pipeline_id, $node_id ) );
	}

	/**
	 * Send HIL prompt to user asking for missing fields.
	 *
	 * @param string $pipeline_id     Pipeline ID.
	 * @param string $node_code       Block code.
	 * @param array  $missing         List of missing field schemas.
	 * @param array  $execution_state Executor state (has session_id, etc.).
	 */
	private function send_hil_prompt( $pipeline_id, $node_code, array $missing, array $execution_state ) {
		$session_id = $execution_state['session_id'] ?? '';
		if ( empty( $session_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, 'Cannot send HIL prompt', [
				'operation' => 'hil_prompt',
			], 'warn' );
			return;
		}

		try {
			$field_labels = [];
			foreach ( $missing as $field ) {
				$field_labels[] = '• ' . ( $field['label'] ?? $field['name'] );
			}

			$msg = sprintf(
				"🔔 **%s** cần thêm thông tin:\n%s\n\nHãy cung cấp các thông tin trên để tiếp tục.",
				$node_code,
				implode( "\n", $field_labels )
			);

			BizCity_WebChat_Database::instance()->log_message( [
				'session_id'              => $session_id,
				'user_id'                 => $execution_state['user_id'] ?? 0,
				'message_id'              => 'hil_' . uniqid( '', true ),
				'message_text'            => $msg,
				'message_from'            => 'bot',
				'platform_type'           => 'ADMINCHAT',
				'tool_name'               => 'pipeline_hil',
				'intent_conversation_id'  => $execution_state['intent_conversation_id'] ?? '',
				'meta'                    => [
					'type'        => 'hil_prompt',
					'source'      => 'pipeline_messenger',
					'pipeline_id' => $pipeline_id,
				],
			] );

			$this->trace( 'mw:hil_waiting', $pipeline_id, $node_code, $node_code . ': Gửi yêu cầu HIL', [
				'session_id'    => $session_id,
				'missing_count' => count( $missing ),
			] );
		} catch ( \Throwable $e ) {
			$this->trace( 'mw:fallback', $pipeline_id, $node_code, 'HIL prompt send failed', [
				'operation' => 'hil_prompt',
				'error'     => $e->getMessage(),
			], 'error' );
		}
	}

	/* ================================================================
	 *  Verify
	 * ================================================================ */

	/**
	 * Basic verification: check if result contains evidence of real resource.
	 *
	 * @param string $node_code  Block code.
	 * @param array  $result_data Result data from block.
	 * @return bool Whether result is verified.
	 */
	private function verify_result( $node_code, $result_data ) {
		if ( ! is_array( $result_data ) ) {
			return false;
		}

		// Check for post-like resources
		$post_id = $result_data['post_id'] ?? ( $result_data['id'] ?? 0 );
		if ( ! empty( $post_id ) && is_numeric( $post_id ) && function_exists( 'get_post' ) ) {
			$post = get_post( (int) $post_id );
			if ( $post && $post->post_status !== 'trash' ) {
				return true;
			}
		}

		// Check for URL evidence
		if ( ! empty( $result_data['url'] ) || ! empty( $result_data['post_url'] ) ) {
			return true;
		}

		// Check for explicit success flag
		if ( isset( $result_data['success'] ) && $result_data['success'] ) {
			return true;
		}

		return false;
	}

	/* ================================================================
	 *  Node Failed Handler — for Error 500 recovery
	 * ================================================================ */

	/**
	 * Handle block execution failure (getResults() threw exception).
	 *
	 * Fired by execute-api.php via do_action('waic_pipeline_node_failed')
	 * AFTER the outer catch block. This ensures todos get updated even when
	 * the block crashes and post_execute never fires.
	 *
	 * @param string $pipeline_id     Pipeline identifier.
	 * @param string $node_code       Block code that failed.
	 * @param array  $node            Full node object.
	 * @param string $error_message   Exception message.
	 * @param array  $execution_state Full executor state.
	 */
	public function on_node_failed( $pipeline_id, $node_code, $node, $error_message, $execution_state ) {
		$this->mw_start = microtime( true );

		try {
			$node_id = $node['id'] ?? '';

			$this->trace( 'mw:execute_error', $pipeline_id, $node_code, $node_code . ': Lỗi thực thi', [
				'error'   => mb_substr( $error_message, 0, 200 ),
				'node_id' => $node_id,
			], 'error' );

			// Update todo → FAILED
			$this->safe_update_todo( $pipeline_id, $node_code, 'FAILED', [
				'score'         => 0,
				'error_message' => mb_substr( $error_message, 0, 500 ),
			] );

			// Save error evidence
			try {
				if ( class_exists( 'BizCity_Intent_Pipeline_Evidence' ) ) {
					$step_index = $execution_state['node_step_map'][ $node_id ] ?? 0;
					BizCity_Intent_Pipeline_Evidence::save( [
						'pipeline_id' => $pipeline_id,
						'step_index'  => $step_index,
						'tool_name'   => $node_code,
						'user_id'     => $execution_state['user_id'] ?? 0,
						'session_id'  => $execution_state['session_id'] ?? '',
						'result'      => [ 'error' => $error_message, 'status' => -1 ],
						'verified'    => false,
					] );
				}
			} catch ( \Throwable $e ) {
				error_log( '[Pipeline MW] on_node_failed: evidence save also failed: ' . $e->getMessage() );
			}

			// Send error message to user with retry/skip options
			$this->send_error_message( $pipeline_id, $node_code, $error_message, $execution_state );

			// Clear HIL state
			$this->clear_hil_state( $pipeline_id, $node_id );

		} catch ( \Throwable $e ) {
			error_log( '[Pipeline MW] on_node_failed CRASHED: ' . $e->getMessage() );
		}
	}

	/**
	 * Send error message to user with retry/skip/cancel options.
	 *
	 * @param string $pipeline_id     Pipeline ID.
	 * @param string $node_code       Block code that failed.
	 * @param string $error_message   Error description.
	 * @param array  $execution_state Executor state.
	 */
	private function send_error_message( $pipeline_id, $node_code, $error_message, array $execution_state ) {
		$session_id = $execution_state['session_id'] ?? '';
		if ( empty( $session_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		try {
			$safe_error = mb_substr( $error_message, 0, 200 );
			$msg = sprintf(
				"❌ **%s** gặp lỗi: %s\n\n🔄 Thử lại · ⏭️ Bỏ qua · ❌ Dừng pipeline",
				$node_code,
				$safe_error
			);

			BizCity_WebChat_Database::instance()->log_message( [
				'session_id'              => $session_id,
				'user_id'                 => $execution_state['user_id'] ?? 0,
				'message_id'              => 'perr_' . uniqid( '', true ),
				'message_text'            => $msg,
				'message_from'            => 'bot',
				'platform_type'           => 'ADMINCHAT',
				'tool_name'               => 'pipeline_error',
				'intent_conversation_id'  => $execution_state['intent_conversation_id'] ?? '',
				'meta'                    => [
					'type'        => 'error',
					'source'      => 'pipeline_messenger',
					'pipeline_id' => $pipeline_id,
				],
			] );
		} catch ( \Throwable $e ) {
			error_log( '[Pipeline MW] send_error_message failed: ' . $e->getMessage() );
		}
	}
}
