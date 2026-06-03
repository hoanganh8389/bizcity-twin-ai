<?php
/**
 * LLM: MPR Thinking timeline (TwinBrain bridge).
 *
 * BE-6.E — gọi `BizCity_TwinBrain_Runtime::start_turn` qua bridge với event
 * capture. Mỗi 9-layer event (`pre_rules_done`, `guru_lookup`, ...,
 * `final_done`) được push thành một row `automation_logs` để FE timeline +
 * SSE stream re-broadcast.
 *
 * Output schema:
 *   {
 *     answer_md:   string,
 *     thinking_md: string,  // concat layer descriptions
 *     citations:   array,
 *     events:      array<{ event_key, payload_summary }>,
 *     layers_count:int,
 *     trace_id:    string,
 *   }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\LLM
 * @since      AUTOMATION BE-6 (2026-05-29)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_LLM_MPR_Think extends BizCity_Automation_Block_Base {

	public function id(): string   { return 'llm.mpr_think'; }
	public function kind(): string { return 'llm'; }
	public function meta(): array {
		return array(
			'label'    => 'MPR Thinking · TwinBrain',
			'short'    => 'mpr',
			'category' => 'llm',
			'color'    => '#a855f7',
			'icon'     => 'sparkles',
			'defaults' => array(
				'label'      => 'MPR Thinking',
				'prompt'     => '{{trigger.text}}',
				'guru_id'    => 0,
				'tool_force' => '',
				'k'          => 8,
			),
			'fields'   => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị',                   'type' => 'text' ),
				array( 'name' => 'prompt',     'label' => 'Prompt ({{trigger.text}} OK)',    'type' => 'textarea' ),
				array( 'name' => 'guru_id',    'label' => 'Guru ID (0 = mặc định)',          'type' => 'number' ),
				array( 'name' => 'tool_force', 'label' => 'Force tool slug (optional)',      'type' => 'text' ),
				array( 'name' => 'k',          'label' => 'K (retrieval depth)',             'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		if ( ! class_exists( 'BizCity_Automation_TwinBrain_Bridge' ) ) {
			return new WP_Error( 'bridge_missing', 'TwinBrain bridge chưa load.', array( 'status' => 503 ) );
		}
		$prompt = trim( (string) $this->resolve( $data['prompt'] ?? '', $ctx ) );
		if ( $prompt === '' ) {
			return new WP_Error( 'invalid_prompt', 'MPR Think: prompt rỗng.', array( 'status' => 422 ) );
		}
		$opts = array(
			'user_id'    => (int) ( $ctx['trigger']['wp_user_id'] ?? get_current_user_id() ),
			'guru_id'    => (int) ( $data['guru_id'] ?? 0 ) ?: (int) ( $ctx['trigger']['character_id'] ?? 0 ),
			'tool_force' => (string) ( $data['tool_force'] ?? '' ),
			'k'          => max( 3, (int) ( $data['k'] ?? 8 ) ),
		);

		$run_id   = (string) ( $ctx['_run_id'] ?? '' );
		// PG fix #4 — propagate chat_id from trigger payload so twin events
		// emitted by TwinBrain inside start_turn() inherit the channel chat
		// scope (used by Inbox + MPR pane to filter by conversation).
		$chat_id  = (string) ( $ctx['trigger']['chat_id'] ?? $ctx['trigger']['user_id'] ?? '' );
		$bridge_context = array(
			'run_id'      => $run_id,
			'chat_id'     => $chat_id,
			'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
			'user_id'     => (int) ( $opts['user_id'] ?? 0 ),
		);
		$events   = array();
		$on_event = function ( $event_key, $payload ) use ( $run_id, &$events ) {
			$summary = is_array( $payload ) ? array_intersect_key( $payload, array_flip( array(
				'trace_id', 'tool', 'tool_slug', 'guru_id', 'guru_label',
				'latency_ms', 'k', 'score', 'reason', 'decision',
				'candidates_count', 'final_text_len', 'tokens',
			) ) ) : array( '_raw' => $payload );
			$events[] = array( 'event_key' => $event_key, 'summary' => $summary );
			// Stream sang automation_logs với block_id sub-key để FE phân biệt.
			if ( $run_id !== '' && class_exists( 'BizCity_Automation_Repo_Runs' ) ) {
				BizCity_Automation_Repo_Runs::append_log( array(
					'run_id'      => $run_id,
					'node_id'     => 'mpr_think',
					'block_id'    => 'llm.mpr_think.event',
					'step'        => 0,
					'status'      => 2, // STATUS_OK
					'input_json'  => wp_json_encode( array( 'event' => $event_key ) ),
					'output_json' => wp_json_encode( $summary ),
					'started_at'  => current_time( 'mysql' ),
					'ended_at'    => current_time( 'mysql' ),
				) );
			}
			do_action( 'bizcity_automation_mpr_event', $run_id, $event_key, $payload );
		};

		$result = BizCity_Automation_TwinBrain_Bridge::run_with_capture( $prompt, $opts, $on_event, $bridge_context );
		if ( is_wp_error( $result ) ) { return $result; }

		// Normalise final answer extraction (TwinBrain return shape may vary by branch).
		$answer = '';
		if ( is_array( $result ) ) {
			$answer = (string) (
				$result['final_text']      ?? $result['answer']
				?? $result['answer_md']    ?? $result['message']
				?? $result['decision']     ?? ''
			);
		}
		$trace = '';
		foreach ( $events as $ev ) {
			if ( ! empty( $ev['summary']['trace_id'] ) ) { $trace = (string) $ev['summary']['trace_id']; break; }
		}

		return array(
			'answer_md'    => $answer,
			'thinking_md'  => self::compose_thinking( $events ),
			'citations'    => is_array( $result['citations'] ?? null ) ? $result['citations'] : array(),
			'events'       => $events,
			'layers_count' => count( $events ),
			'trace_id'     => $trace,
			'raw'          => $result,
		);
	}

	private static function compose_thinking( array $events ): string {
		if ( empty( $events ) ) { return ''; }
		$lines = array();
		foreach ( $events as $i => $ev ) {
			$lines[] = sprintf( '%d. **%s** — %s',
				$i + 1,
				(string) ( $ev['event_key'] ?? '?' ),
				wp_json_encode( $ev['summary'] ?? array(), JSON_UNESCAPED_UNICODE )
			);
		}
		return implode( "\n", $lines );
	}
}
