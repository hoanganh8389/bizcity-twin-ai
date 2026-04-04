<?php
/**
 * BizCity Twin AI — Action Block: Pipeline Summary Verifier
 *
 * Auto-injected as the LAST action node in generated pipelines.
 * Aggregates results from all previous steps, calculates pipeline score,
 * generates a reflection summary, and sends final message to user.
 *
 * Phase 1.1 — G4: Pipeline completion with score + reflection.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @since      3.9.1
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WaicAction_it_summary_verifier extends WaicAction {
	protected $_code  = 'it_summary_verifier';
	protected $_order = 999;

	public function __construct( $block = null ) {
		$this->_name = __( '🎉 Summary Verifier — Tổng kết', 'bizcity-twin-ai' );
		$this->_desc = __( 'Tổng kết kết quả pipeline: điểm số, reflection, gửi báo cáo cho người dùng.', 'bizcity-twin-ai' );
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
			'pipeline_score' => __( 'Overall pipeline score (0-100)', 'bizcity-twin-ai' ),
			'completed'      => __( 'Number of completed steps', 'bizcity-twin-ai' ),
			'total'          => __( 'Total number of steps', 'bizcity-twin-ai' ),
			'reflection'     => __( 'Reflection / summary text', 'bizcity-twin-ai' ),
		];
	}

	public function getResults( $taskId, $variables, $step = 0 ) {
		$label = $this->replaceVariables( $this->getParam( 'pipeline_label', '' ), $variables );

		$execution_state = $this->getExecutionState( $variables );
		$pipeline_id     = $execution_state['pipeline_id'] ?? '';

		// Gather progress from TODOs
		$progress = [
			'total'     => 0,
			'completed' => 0,
			'failed'    => 0,
			'avg_score' => 0,
		];

		if ( class_exists( 'BizCity_Intent_Todos' ) && $pipeline_id ) {
			$progress = BizCity_Intent_Todos::get_progress( $pipeline_id );
		}

		$completed = $progress['completed'];
		$total     = $progress['total'];
		$avg_score = $progress['avg_score'] ?? 0;

		// Calculate pipeline score
		$pipeline_score = $total > 0
			? round( ( $completed / $total ) * 100 * ( $avg_score > 0 ? $avg_score / 100 : 0.8 ) )
			: 0;

		// Build reflection
		$reflection_parts = [];
		if ( $label ) {
			$reflection_parts[] = "**{$label}**";
		}
		$reflection_parts[] = "📊 {$completed}/{$total} bước thành công";

		if ( $progress['failed'] > 0 ) {
			$reflection_parts[] = "⚠️ {$progress['failed']} bước lỗi";
		}

		// Gather evidence summaries
		$evidence_text = '';
		if ( class_exists( 'BizCity_Intent_Pipeline_Evidence' ) && $pipeline_id ) {
			$evidences = BizCity_Intent_Pipeline_Evidence::get_pipeline_evidence( $pipeline_id );
			$ev_lines  = [];
			foreach ( $evidences as $ev ) {
				$tool   = $ev['tool_name'] ?? '';
				$output = $ev['output_summary'] ?? '';
				if ( $tool && $output ) {
					$ev_lines[] = "• {$tool}: {$output}";
				}
			}
			if ( $ev_lines ) {
				$evidence_text = "\n📝 Chi tiết:\n" . implode( "\n", $ev_lines );
			}
		}

		// ── Phase 1.1h: LLM quality assessment ──
		$llm_assessment = '';
		if ( function_exists( 'bizcity_openrouter_chat' ) && $evidence_text && $total > 0 ) {
			$assess_prompt = "Đánh giá chất lượng kết quả pipeline tự động:\n"
				. "Tiến độ: {$completed}/{$total} bước thành công"
				. ( $progress['failed'] > 0 ? ", {$progress['failed']} bước lỗi" : '' ) . "\n"
				. $evidence_text . "\n\n"
				. "Trả về JSON (không giải thích thêm):\n"
				. "{\"score\": <0-100>, \"assessment\": \"<nhận xét ngắn 1-2 câu>\"}";

			$ai = bizcity_openrouter_chat( [
				[ 'role' => 'system', 'content' => 'Bạn đánh giá chất lượng pipeline. Chỉ trả JSON.' ],
				[ 'role' => 'user',   'content' => $assess_prompt ],
			], [ 'temperature' => 0.3, 'max_tokens' => 200 ] );

			$raw_json = $ai['message'] ?? '';
			// Extract JSON from response
			if ( ( $pos = strpos( $raw_json, '{' ) ) !== false ) {
				$raw_json = substr( $raw_json, $pos );
			}
			if ( ( $pos = strrpos( $raw_json, '}' ) ) !== false ) {
				$raw_json = substr( $raw_json, 0, $pos + 1 );
			}
			$parsed = json_decode( $raw_json, true );

			if ( $parsed ) {
				$llm_score = min( 100, max( 0, (int) ( $parsed['score'] ?? 0 ) ) );
				$llm_assessment = sanitize_text_field( $parsed['assessment'] ?? '' );

				// Hybrid score: weight 60% math + 40% LLM
				if ( $llm_score > 0 ) {
					$pipeline_score = (int) round( $pipeline_score * 0.6 + $llm_score * 0.4 );
				}

				if ( $llm_assessment ) {
					$reflection_parts[] = "🤖 AI: {$llm_assessment}";
				}
			}
		}

		$reflection_parts[] = "💯 Pipeline Score: {$pipeline_score}/100";

		$reflection = implode( "\n", $reflection_parts ) . $evidence_text;

		// Mark one-shot trigger as completed (if applicable)
		if ( class_exists( 'BizCity_One_Shot_Trigger' ) && $pipeline_id ) {
			$this->complete_one_shot( $pipeline_id, $completed, $total, $pipeline_score );
		}

		// Send summary to user chat
		if ( class_exists( 'BizCity_Pipeline_Messenger' ) && ! empty( $execution_state['session_id'] ) ) {
			BizCity_Pipeline_Messenger::send_summary(
				$execution_state,
				$completed,
				$total,
				$evidence_text
			);
		}

		// Fire pipeline_complete trace
		if ( has_action( 'bizcity_intent_pipeline_log' ) ) {
			do_action( 'bizcity_intent_pipeline_log', 'mw:pipeline_complete', [
				'pipeline_id'    => $pipeline_id,
				'pipeline_score' => $pipeline_score,
				'completed'      => $completed,
				'total'          => $total,
			], 'info', 0 );
		}

		return [
			'result' => [
				'pipeline_score' => $pipeline_score,
				'completed'      => $completed,
				'total'          => $total,
				'reflection'     => $reflection,
			],
			'pipeline_score' => $pipeline_score,
			'completed'      => $completed,
			'total'          => $total,
			'reflection'     => $reflection,
		];
	}

	/**
	 * Complete the one-shot trigger for this pipeline.
	 */
	private function complete_one_shot( $pipeline_id, $completed, $total, $score ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_pipeline_oneshot';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE pipeline_id = %s AND state = 'running' LIMIT 1",
			$pipeline_id
		) );

		if ( $row && class_exists( 'BizCity_One_Shot_Trigger' ) ) {
			BizCity_One_Shot_Trigger::instance()->complete( (int) $row->id, [
				'completed' => $completed,
				'total'     => $total,
				'score'     => $score,
			] );
		}
	}

	/**
	 * Extract execution state from variables.
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
