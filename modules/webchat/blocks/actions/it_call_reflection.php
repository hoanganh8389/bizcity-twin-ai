<?php
/**
 * WaicAction Block: it_call_reflection — Pipeline Reflection + Error Recovery
 *
 * Final block in the 5-step agentic pipeline. Responsibilities:
 *   1. Verify: Read intent_todos + studio_outputs → confirm each step produced output
 *   2. Reflect: Generate LLM-assisted quality summary (score 0-100)
 *   3. Error: Identify failed steps → send retry button to user
 *   4. Distribute: Optional CPT creation (bizcity-auto-tool post) if content is ready
 *   5. Report: Send completion message to chat + studio_outputs entry
 *
 * Differs from it_summary_verifier (the Phase 1.1 precursor):
 *   - Reads studio_outputs per-step (not just todos)
 *   - Generates retry-from-step buttons for error recovery
 *   - Optional CPT creation + distribution link
 *   - Emits structured pipeline trace for Working Panel
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\WebChat\Blocks\Actions
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      Phase 1.10 Sprint 3
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class WaicAction_it_call_reflection extends WaicAction {

	protected $_code  = 'it_call_reflection';
	protected $_order = 999;

	/** @var string Log prefix */
	private const LOG_PREFIX = '[it_call_reflection]';

	/** Expected pipeline blocks that should produce studio_outputs */
	private const EXPECTED_TOOLS = [
		'it_todos_planner',
		'it_call_research',
		'it_call_memory',
		'it_call_content',
	];

	public function __construct( $block = null ) {
		$this->_name = __( '🎯 Reflection — Verify + Summarize', 'bizcity-twin-ai' );
		$this->_desc = __( 'Kiểm tra kết quả pipeline, tạo reflection summary, hỗ trợ retry lỗi.', 'bizcity-twin-ai' );
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
			'create_cpt' => [
				'type'    => 'select',
				'label'   => __( 'Create public post?', 'bizcity-twin-ai' ),
				'default' => '0',
				'options' => [
					'1' => __( 'Yes — tạo bài viết công khai', 'bizcity-twin-ai' ),
					'0' => __( 'No — chỉ lưu trong Studio', 'bizcity-twin-ai' ),
				],
			],
			'max_retries' => [
				'type'    => 'select',
				'label'   => __( 'Max retries per failed step', 'bizcity-twin-ai' ),
				'default' => '2',
				'options' => [ '0' => '0', '1' => '1', '2' => '2', '3' => '3' ],
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
			'pipeline_score' => __( 'Overall pipeline score (0-100)', 'bizcity-twin-ai' ),
			'completed'      => __( 'Number of completed steps', 'bizcity-twin-ai' ),
			'total'          => __( 'Total number of steps', 'bizcity-twin-ai' ),
			'reflection'     => __( 'Reflection summary text', 'bizcity-twin-ai' ),
			'external_url'   => __( 'Published post URL (if CPT created)', 'bizcity-twin-ai' ),
			'has_errors'     => __( 'true/false — whether any step failed', 'bizcity-twin-ai' ),
			'failed_steps'   => __( 'Comma-separated failed step codes', 'bizcity-twin-ai' ),
		];
	}

	/* ================================================================
	 *  getResults — Main execution
	 * ================================================================ */

	public function getResults( $taskId, $variables, $step = 0 ) {
		$start_time = microtime( true );

		$create_cpt  = (int) $this->getParam( 'create_cpt', '0' );
		$max_retries = (int) $this->getParam( 'max_retries', '2' );

		// Override from block instance settings (getParams() reads $this->_block['data']['settings'])
		$block_settings = $this->getParams();
		if ( isset( $block_settings['create_cpt'] ) ) {
			$create_cpt = (int) $block_settings['create_cpt'];
		}
		if ( isset( $block_settings['max_retries'] ) ) {
			$max_retries = (int) $block_settings['max_retries'];
		}

		// ── Execution state ──
		$session_id  = $variables['_session_id'] ?? '';
		$user_id     = (int) ( $variables['_user_id'] ?? 0 );
		$pipeline_id = $variables['_pipeline_id'] ?? '';
		$channel     = $variables['_channel'] ?? 'webchat';

		error_log( self::LOG_PREFIX . ' START pipeline=' . $pipeline_id . ' session=' . $session_id );

		// ── Trace: execute_start ──
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_start', [
			'node_code'  => 'it_call_reflection',
			'label'      => 'Đang kiểm tra kết quả pipeline...',
			'session_id' => $session_id,
		], 'info', 0 );

		// ══════════════════════════════════════
		//  STEP 1: Read todo progress
		// ══════════════════════════════════════
		$progress = [
			'total'     => 0,
			'completed' => 0,
			'failed'    => 0,
			'pending'   => 0,
			'avg_score' => 0,
		];

		if ( class_exists( 'BizCity_Intent_Todos' ) && $pipeline_id ) {
			$progress = BizCity_Intent_Todos::get_progress( $pipeline_id );
		}

		$completed  = (int) ( $progress['completed'] ?? 0 );
		$total      = (int) ( $progress['total'] ?? 0 );
		$failed_cnt = (int) ( $progress['failed'] ?? 0 );
		$avg_score  = (float) ( $progress['avg_score'] ?? 0 );

		// ══════════════════════════════════════
		//  STEP 2: Verify studio_outputs exist for each expected step
		// ══════════════════════════════════════
		$studio_entries  = $this->get_session_studio_outputs( $session_id );
		$verified_tools  = [];
		$missing_outputs = [];

		foreach ( self::EXPECTED_TOOLS as $tool_code ) {
			$found = false;
			foreach ( $studio_entries as $entry ) {
				if ( ( $entry->tool_id ?? '' ) === $tool_code ) {
					$found = true;
					$verified_tools[] = $tool_code;
					break;
				}
			}
			if ( ! $found ) {
				$missing_outputs[] = $tool_code;
			}
		}

		error_log( self::LOG_PREFIX . ' Studio verified: [' . implode( ',', $verified_tools ) . '] missing: [' . implode( ',', $missing_outputs ) . ']' );

		// ══════════════════════════════════════
		//  STEP 3: Identify failed steps from node variables
		// ══════════════════════════════════════
		$failed_steps = [];
		foreach ( $variables as $key => $val ) {
			if ( strpos( $key, 'node#' ) !== 0 || ! is_array( $val ) ) {
				continue;
			}
			// Check for error indicators
			if ( ! empty( $val['error'] ) || ( isset( $val['success'] ) && $val['success'] === 'false' ) ) {
				$tool = $val['_block_code'] ?? $val['_tool_id'] ?? $key;
				$failed_steps[] = $tool;
			}
		}

		// Also check todos for failed items
		if ( class_exists( 'BizCity_Intent_Todos' ) && $pipeline_id ) {
			$failed_todo = BizCity_Intent_Todos::find_failed_step( $pipeline_id );
			if ( $failed_todo && ! empty( $failed_todo['tool_name'] ) ) {
				if ( ! in_array( $failed_todo['tool_name'], $failed_steps, true ) ) {
					$failed_steps[] = $failed_todo['tool_name'];
				}
			}
		}

		$has_errors = ! empty( $failed_steps ) || $failed_cnt > 0;

		// ══════════════════════════════════════
		//  STEP 4: Calculate pipeline score
		// ══════════════════════════════════════
		$math_score = $total > 0
			? round( ( $completed / $total ) * 100 )
			: 0;

		// Penalize for missing outputs
		if ( ! empty( $missing_outputs ) ) {
			$penalty = count( $missing_outputs ) * 10;
			$math_score = max( 0, $math_score - $penalty );
		}

		// Hybrid: 70% math + 30% avg_score (if available)
		$pipeline_score = $avg_score > 0
			? (int) round( $math_score * 0.7 + $avg_score * 0.3 )
			: $math_score;

		// ══════════════════════════════════════
		//  STEP 5: Build reflection text
		// ══════════════════════════════════════
		$reflection_parts = [];
		$reflection_parts[] = sprintf( '📊 **Pipeline Score: %d/100**', $pipeline_score );
		$reflection_parts[] = sprintf( '✅ %d/%d bước hoàn tất', $completed, $total );

		if ( $failed_cnt > 0 ) {
			$reflection_parts[] = sprintf( '⚠️ %d bước lỗi: %s', $failed_cnt, implode( ', ', $failed_steps ) );
		}

		// Studio verification
		if ( ! empty( $verified_tools ) ) {
			$reflection_parts[] = '📋 Studio outputs verified: ' . implode( ', ', $verified_tools );
		}
		if ( ! empty( $missing_outputs ) ) {
			$reflection_parts[] = '❌ Missing studio outputs: ' . implode( ', ', $missing_outputs );
		}

		// Gather content excerpt from it_call_content output
		$content_excerpt = '';
		foreach ( $variables as $key => $val ) {
			if ( strpos( $key, 'node#' ) !== 0 || ! is_array( $val ) ) {
				continue;
			}
			if ( ! empty( $val['content'] ) && ( ! empty( $val['title'] ) || ! empty( $val['skill_used'] ) ) ) {
				$content_excerpt = mb_substr( $val['content'], 0, 200 );
				break;
			}
		}

		if ( $content_excerpt ) {
			$reflection_parts[] = '📝 Content preview: ' . $content_excerpt . '...';
		}

		// ══════════════════════════════════════
		//  STEP 5b: Optional LLM quality assessment
		// ══════════════════════════════════════
		$llm_assessment = $this->run_llm_assessment( $pipeline_score, $completed, $total, $failed_steps, $verified_tools, $content_excerpt );
		if ( $llm_assessment ) {
			$reflection_parts[] = '🤖 AI: ' . $llm_assessment['text'];
			// Blend LLM score: 60% math + 40% LLM
			if ( ! empty( $llm_assessment['score'] ) ) {
				$pipeline_score = (int) round( $pipeline_score * 0.6 + $llm_assessment['score'] * 0.4 );
			}
		}

		$reflection = implode( "\n", $reflection_parts );

		// ══════════════════════════════════════
		//  STEP 6: Error recovery — retry buttons
		// ══════════════════════════════════════
		if ( $has_errors && $max_retries > 0 ) {
			$this->send_retry_message( $session_id, $user_id, $channel, $pipeline_id, $failed_steps, $max_retries );
		}

		// ══════════════════════════════════════
		//  STEP 7: Optional CPT creation
		// ══════════════════════════════════════
		$external_url = '';
		$content_output_id = 0;

		if ( $create_cpt && ! $has_errors && ! empty( $content_excerpt ) ) {
			$cpt_result = $this->create_distribution_post( $variables, $user_id, $pipeline_id );
			$external_url = $cpt_result['url'] ?? '';
			$content_output_id = $cpt_result['output_id'] ?? 0;

			if ( $external_url ) {
				$reflection_parts[] = '🔗 Đã đăng: [' . $external_url . '](' . $external_url . ')';
				$reflection = implode( "\n", $reflection_parts );
			}
		}

		// ══════════════════════════════════════
		//  STEP 8: Mark todos as completed
		// ══════════════════════════════════════
		if ( class_exists( 'BizCity_Intent_Todos' ) && $pipeline_id ) {
			BizCity_Intent_Todos::update_status( $pipeline_id, [
				'node_id'   => $this->getId() ?? 'reflection',
				'tool_name' => 'it_call_reflection',
				'event'     => 'completed',
				'score'     => $pipeline_score,
			] );
		}

		// ══════════════════════════════════════
		//  STEP 9: Studio outputs entry for reflection
		// ══════════════════════════════════════
		$this->save_reflection_studio_output( $session_id, $user_id, $taskId, $pipeline_score, $reflection );

		// ══════════════════════════════════════
		//  STEP 10: Send completion message to chat
		// ══════════════════════════════════════
		$this->send_completion_message( $session_id, $user_id, $channel, $pipeline_id, $pipeline_score, $completed, $total, $external_url, $has_errors );

		// ── Trace: execute_done ──
		$elapsed_ms = round( ( microtime( true ) - $start_time ) * 1000, 1 );
		do_action( 'bizcity_intent_pipeline_log', 'mw:execute_done', [
			'node_code'  => 'it_call_reflection',
			'label'      => sprintf( 'Reflection — %d/100, %d/%d bước', $pipeline_score, $completed, $total ),
			'has_error'  => $has_errors ? 'true' : 'false',
			'session_id' => $session_id,
		], $has_errors ? 'warn' : 'info', (int) $elapsed_ms );

		// Fire pipeline_complete trace
		do_action( 'bizcity_intent_pipeline_log', 'mw:pipeline_complete', [
			'pipeline_id'    => $pipeline_id,
			'pipeline_score' => $pipeline_score,
			'completed'      => $completed,
			'total'          => $total,
		], 'info', 0 );

		error_log( self::LOG_PREFIX . ' DONE score=' . $pipeline_score . ' completed=' . $completed . '/' . $total . ' errors=' . ( $has_errors ? 'yes' : 'no' ) . ' (' . $elapsed_ms . 'ms)' );

		// ── Phase 1.15: Finalize memory spec (BUG #5 fix) ──
		// Lifecycle: CREATE (Shell 3.5) → SEED (execute_pipeline) → UPDATE (Ngón 4) → FINALIZE (here)
		// Principle 1.1.4: "Mỗi memory spec phải có đường đi từ CREATE → FINALIZE"
		$_pipeline_ctx = $variables['_pipeline_context'] ?? [];
		$_p115_mem_id  = ! empty( $_pipeline_ctx['memory_id'] )
			? (int) $_pipeline_ctx['memory_id']
			: ( ! empty( $variables['_phase115_memory_id'] ) ? (int) $variables['_phase115_memory_id'] : 0 );

		if ( $_p115_mem_id && class_exists( 'BizCity_Memory_Manager' ) ) {
			$_mgr = BizCity_Memory_Manager::instance();

			// Update ## Current with final reflection result
			$_mgr->update_current( $_p115_mem_id, array(
				'step'  => 'it_call_reflection (completed)',
				'score' => (string) $pipeline_score,
				'next'  => $has_errors ? 'retry_failed_steps' : 'done',
			), 'it_call_reflection' );

			// Finalize with resume state
			$_mgr->finalize( $_p115_mem_id, array(
				'last_completed'      => 'it_call_reflection',
				'last_output_summary' => 'Pipeline score=' . $pipeline_score . ' completed=' . $completed . '/' . $total,
				'next_action'         => $has_errors ? 'retry: ' . implode( ',', $failed_steps ) : 'none',
				'can_resume'          => $has_errors,
			) );

			error_log( self::LOG_PREFIX . ' [Phase1.15] Finalized: mem_id=' . $_p115_mem_id . ' score=' . $pipeline_score );
		}

		return [
			'result' => [
				'pipeline_score' => (string) $pipeline_score,
				'completed'      => (string) $completed,
				'total'          => (string) $total,
				'reflection'     => $reflection,
				'external_url'   => $external_url,
				'has_errors'     => $has_errors ? 'true' : 'false',
				'failed_steps'   => implode( ',', $failed_steps ),
			],
			'error'  => '',
			'status' => 3,
		];
	}

	/* ================================================================
	 *  Helpers
	 * ================================================================ */

	/**
	 * Query studio_outputs for current session to verify each step produced output.
	 *
	 * @param string $session_id
	 * @return array DB rows
	 */
	private function get_session_studio_outputs( $session_id ) {
		if ( empty( $session_id ) || ! class_exists( 'BCN_Schema_Extend' ) ) {
			return [];
		}

		global $wpdb;
		$table = BCN_Schema_Extend::table_studio_outputs();

		// Resolve project_id from session (studio_outputs uses project_id, not session_id)
		$sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
		$project_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT project_id FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
			$session_id
		) );

		if ( empty( $project_id ) ) {
			return [];
		}

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, tool_id, tool_type, title, status, created_at
			 FROM {$table}
			 WHERE project_id = %s AND caller = 'pipeline'
			 ORDER BY created_at ASC",
			$project_id
		) );

		return $results ? $results : [];
	}

	/**
	 * Run optional LLM quality assessment.
	 *
	 * @return array|null { score: int, text: string }
	 */
	private function run_llm_assessment( $score, $completed, $total, array $failed, array $verified, $content_excerpt ) {
		if ( ! function_exists( 'bizcity_openrouter_chat' ) || $total < 1 ) {
			return null;
		}

		$prompt = "Đánh giá chất lượng kết quả pipeline tự động:\n"
			. "- Tiến độ: {$completed}/{$total} bước OK"
			. ( count( $failed ) > 0 ? ', lỗi: ' . implode( ', ', $failed ) : '' ) . "\n"
			. '- Studio outputs verified: ' . implode( ', ', $verified ) . "\n";

		if ( $content_excerpt ) {
			$prompt .= "- Content preview: " . mb_substr( $content_excerpt, 0, 150 ) . "\n";
		}

		$prompt .= "\nTrả về JSON (không giải thích thêm):\n{\"score\": <0-100>, \"assessment\": \"<nhận xét ngắn 1-2 câu>\"}";

		$ai = bizcity_openrouter_chat( [
			[ 'role' => 'system', 'content' => 'Bạn đánh giá chất lượng pipeline. Chỉ trả JSON.' ],
			[ 'role' => 'user',   'content' => $prompt ],
		], [ 'temperature' => 0.3, 'max_tokens' => 200 ] );

		$raw = $ai['message'] ?? '';
		// Extract JSON
		$start_pos = strpos( $raw, '{' );
		$end_pos   = strrpos( $raw, '}' );
		if ( $start_pos === false || $end_pos === false ) {
			return null;
		}

		$parsed = json_decode( substr( $raw, $start_pos, $end_pos - $start_pos + 1 ), true );
		if ( ! $parsed ) {
			return null;
		}

		return [
			'score' => min( 100, max( 0, (int) ( $parsed['score'] ?? 0 ) ) ),
			'text'  => sanitize_text_field( $parsed['assessment'] ?? '' ),
		];
	}

	/**
	 * Send retry message with step-specific buttons.
	 */
	private function send_retry_message( $session_id, $user_id, $channel, $pipeline_id, array $failed_steps, $max_retries ) {
		if ( empty( $session_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		$lines = [ '⚠️ **Phát hiện lỗi trong pipeline**' ];
		foreach ( $failed_steps as $step_code ) {
			$lines[] = "- `{$step_code}` — lỗi (max retries: {$max_retries})";
		}
		$lines[] = '';
		$lines[] = '**Hành động:**';
		foreach ( $failed_steps as $step_code ) {
			$lines[] = "• [🔄 Chạy lại {$step_code}] → reply: `/retry {$step_code}`";
		}
		$lines[] = '• [⏭️ Bỏ qua] → reply: `/skip_errors`';
		$lines[] = '• [📋 Xem chi tiết] → reply: `/pipeline_detail`';

		$msg = implode( "\n", $lines );

		BizCity_WebChat_Database::instance()->log_message( [
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => 'Reflection Bot',
			'message_id'    => uniqid( 'refl_retry_' ),
			'message_text'  => $msg,
			'message_from'  => 'bot',
			'message_type'  => 'pipeline_progress',
			'platform_type' => ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT',
			'tool_name'     => 'it_call_reflection',
			'meta'          => wp_json_encode( [
				'action'       => 'reflection_error',
				'failed_steps' => $failed_steps,
				'pipeline_id'  => $pipeline_id,
				'max_retries'  => $max_retries,
			] ),
		] );
	}

	/**
	 * Create a bizcity-auto-tool CPT post from it_call_content output.
	 *
	 * @return array { url: string, post_id: int, output_id: int }
	 */
	private function create_distribution_post( array $variables, $user_id, $pipeline_id ) {
		$content_title   = '';
		$content_body    = '';
		$content_node_id = '';

		// Find it_call_content node output
		foreach ( $variables as $key => $val ) {
			if ( strpos( $key, 'node#' ) !== 0 || ! is_array( $val ) ) {
				continue;
			}
			if ( ! empty( $val['content'] ) && ( ! empty( $val['title'] ) || ! empty( $val['skill_used'] ) ) ) {
				$content_title = $val['title'] ?? 'Bài viết tự động';
				$content_body  = $val['content'];
				$content_node_id = $key;
				break;
			}
		}

		if ( empty( $content_body ) ) {
			error_log( self::LOG_PREFIX . ' CPT creation skipped — no content found' );
			return [ 'url' => '', 'post_id' => 0, 'output_id' => 0 ];
		}

		$post_id = wp_insert_post( [
			'post_type'    => 'bizcity-auto-tool',
			'post_title'   => sanitize_text_field( $content_title ),
			'post_content' => wp_kses_post( $content_body ),
			'post_status'  => 'publish',
			'post_author'  => $user_id ?: get_current_user_id(),
			'meta_input'   => [
				'_pipeline_id' => $pipeline_id,
				'_created_by'  => 'it_call_reflection',
			],
		] );

		if ( is_wp_error( $post_id ) ) {
			error_log( self::LOG_PREFIX . ' CPT insert error: ' . $post_id->get_error_message() );
			return [ 'url' => '', 'post_id' => 0, 'output_id' => 0 ];
		}

		$url = get_permalink( $post_id );

		// Update studio_outputs with distribution link
		$output_id = 0;
		if ( class_exists( 'BizCity_Output_Store' ) && class_exists( 'BCN_Schema_Extend' ) ) {
			global $wpdb;
			$table = BCN_Schema_Extend::table_studio_outputs();

			// Find the content studio output
			$sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
			$project_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT project_id FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
				$variables['_session_id'] ?? ''
			) );

			if ( $project_id ) {
				$output_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id FROM {$table}
					 WHERE project_id = %s AND caller = 'pipeline' AND tool_type = 'content'
					 ORDER BY created_at DESC LIMIT 1",
					$project_id
				) );

				if ( $output_row ) {
					$output_id = (int) $output_row->id;
					BizCity_Output_Store::update_distribution_result( $output_id, $url, $post_id );
				}
			}
		}

		error_log( self::LOG_PREFIX . ' CPT created: post_id=' . $post_id . ' url=' . $url );

		return [ 'url' => $url, 'post_id' => $post_id, 'output_id' => $output_id ];
	}

	/**
	 * Save reflection summary to studio_outputs.
	 */
	private function save_reflection_studio_output( $session_id, $user_id, $task_id, $score, $reflection ) {
		if ( ! class_exists( 'BizCity_Output_Store' ) ) {
			return;
		}

		BizCity_Output_Store::save_artifact( [
			'tool_id'    => 'it_call_reflection',
			'caller'     => 'pipeline',
			'session_id' => $session_id,
			'user_id'    => (int) $user_id,
			'task_id'    => $task_id ?: null,
			'data'       => [
				'title'   => sprintf( 'Reflection — Hoàn tất %d/100', $score ),
				'content' => $reflection,
			],
		], 'reflection' );
	}

	/**
	 * Send final completion message to user chat.
	 */
	private function send_completion_message( $session_id, $user_id, $channel, $pipeline_id, $score, $completed, $total, $external_url, $has_errors ) {
		if ( empty( $session_id ) || ! class_exists( 'BizCity_WebChat_Database' ) ) {
			return;
		}

		$icon = $has_errors ? '⚠️' : '✅';
		$msg  = sprintf(
			"%s **Hoàn tất %d/%d bước** — Pipeline Score: %d/100",
			$icon,
			$completed,
			$total,
			$score
		);

		if ( $external_url ) {
			$msg .= "\n🔗 Đã đăng tại: [" . $external_url . ']('. $external_url . ')';
		}

		if ( $has_errors ) {
			$msg .= "\n\n⚡ Một số bước gặp lỗi. Reply `/retry <step>` để chạy lại.";
		}

		BizCity_WebChat_Database::instance()->log_message( [
			'session_id'    => $session_id,
			'user_id'       => 0,
			'client_name'   => 'Reflection Bot',
			'message_id'    => uniqid( 'refl_done_' ),
			'message_text'  => $msg,
			'message_from'  => 'bot',
			'message_type'  => 'pipeline_progress',
			'platform_type' => ( $channel === 'adminchat' ) ? 'ADMINCHAT' : 'WEBCHAT',
			'tool_name'     => 'it_call_reflection',
			'meta'          => wp_json_encode( [
				'action'         => 'reflection_done',
				'pipeline_id'    => $pipeline_id,
				'pipeline_score' => $score,
				'completed'      => $completed,
				'total'          => $total,
				'external_url'   => $external_url,
				'has_errors'     => $has_errors,
			] ),
		] );
	}
}
