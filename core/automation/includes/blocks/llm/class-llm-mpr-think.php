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
				'command'    => '',
				'answer_depth' => '',
				'goal_case'  => '',
			),
			'fields'   => array(
				array( 'name' => 'label',      'label' => 'Tên hiển thị',                   'type' => 'text' ),
				array( 'name' => 'prompt',     'label' => 'Prompt ({{trigger.text}} OK)',    'type' => 'textarea' ),
				array( 'name' => 'guru_id',    'label' => 'Guru ID (0 = mặc định)',          'type' => 'number' ),
				array( 'name' => 'tool_force', 'label' => 'Force tool slug (optional)',      'type' => 'text' ),
				array( 'name' => 'k',          'label' => 'K (retrieval depth)',             'type' => 'number' ),
				array( 'name' => 'command',    'label' => 'TwinBrain command (optional)',    'type' => 'text' ),
				array( 'name' => 'answer_depth', 'label' => 'Answer depth (optional)',        'type' => 'text' ),
				array( 'name' => 'goal_case',  'label' => 'Goal case (optional)',             'type' => 'text' ),
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
		// [2026-08-11 Johnny Chu] PHASE-TBR-NOTE-THUKY-ZALO — keep @note/@thuky as a validated runtime directive, not prompt content.
		$command = sanitize_key( trim( (string) $this->resolve( $data['command'] ?? '', $ctx ) ) );
		if ( $command === '' ) {
			$command = self::extract_command( $prompt );
		}
		if ( $command !== '' ) {
			$prompt = self::strip_command( $prompt, $command );
		}
		if ( $prompt === '' ) {
			return new WP_Error( 'invalid_prompt', 'MPR Think: nội dung sau command rỗng.', array( 'status' => 422 ) );
		}
		// [2026-07-16 Johnny Chu] PHASE-TWINWEB F4 — resolve owner from automation context, never from current session fallback.
		$owner_user_id = $this->resolve_owner_user_id( $ctx );
		if ( $owner_user_id <= 0 ) {
			return new WP_Error( 'owner_missing', 'MPR Think: không resolve được owner user_id.', array( 'status' => 422 ) );
		}
		$opts = array(
			'user_id'    => $owner_user_id,
			'guru_id'    => (int) ( $data['guru_id'] ?? 0 ) ?: (int) ( $ctx['trigger']['character_id'] ?? 0 ),
			'tool_force' => (string) ( $data['tool_force'] ?? '' ),
			'k'          => max( 3, (int) ( $data['k'] ?? 8 ) ),
			'session_id' => self::resolve_session_id( $ctx ),
			'platform'   => (string) ( $ctx['trigger']['platform'] ?? 'ZALO_BOT' ),
			'channel'    => (string) ( $ctx['trigger']['channel'] ?? 'zalo_bot' ),
			'surface'    => 'zalobot',
			'chat_kind'  => sanitize_key( (string) ( $ctx['trigger']['chat_kind'] ?? 'private' ) ),
			'account_id' => (string) ( $ctx['trigger']['account_id'] ?? $ctx['trigger']['bot_id'] ?? '' ),
			'external_user_id' => (string) ( $ctx['trigger']['sender_user_id'] ?? $ctx['trigger']['user_id'] ?? '' ),
			'chat_id'    => (string) ( $ctx['trigger']['chat_id'] ?? '' ),
			'wp_user_id' => $owner_user_id,
		);
		$answer_depth = sanitize_key( trim( (string) $this->resolve( $data['answer_depth'] ?? '', $ctx ) ) );
		$goal_case    = sanitize_key( trim( (string) $this->resolve( $data['goal_case'] ?? '', $ctx ) ) );
		if ( $answer_depth !== '' ) { $opts['answer_depth'] = $answer_depth; }
		if ( $goal_case !== '' ) { $opts['goal_case'] = $goal_case; }
		if ( $command !== '' ) { $opts['conversation_route'] = $command; }

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
			'complete'    => true,
		);
		$events   = array();
		$on_event = function ( $event_key, $payload ) use ( $run_id, &$events ) {
			// [2026-08-11 Johnny Chu] PHASE-TBR-NOTE-THUKY-ZALO — retain compact Goal/Notebook evidence for Zalo workflow audit without leaking raw context.
			$summary = is_array( $payload ) ? array_intersect_key( $payload, array_flip( array(
				'trace_id', 'tool', 'tool_slug', 'guru_id', 'guru_label',
				'latency_ms', 'k', 'score', 'reason', 'decision',
				'candidates_count', 'final_text_len', 'tokens', 'stage',
				'goal_id', 'scoreboard_version', 'obligation_count',
				'answer_depth', 'notebook_count', 'passage_count',
				'final_context_count', 'rerank_degraded', 'degraded',
				'route', 'success',
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
			'command'      => $command,
			'goal_case'    => (string) ( $opts['goal_case'] ?? '' ),
			'answer_depth' => (string) ( $opts['answer_depth'] ?? '' ),
			'thinking_md'  => self::compose_thinking( $events ),
			'citations'    => is_array( $result['citations'] ?? null ) ? $result['citations'] : array(),
			'events'       => $events,
			'layers_count' => count( $events ),
			'trace_id'     => $trace,
			'raw'          => $result,
		);
	}

	private static function extract_command( string $prompt ): string {
		if ( preg_match( '/^@(note|thuky)(?:\s|$)/iu', ltrim( $prompt ), $match ) ) {
			return sanitize_key( strtolower( (string) $match[1] ) );
		}
		return '';
	}

	private static function strip_command( string $prompt, string $command ): string {
		$pattern = '/^@' . preg_quote( $command, '/' ) . '(?:\s+|$)/iu';
		return trim( (string) preg_replace( $pattern, '', ltrim( $prompt ), 1 ) );
	}

	private static function resolve_session_id( array $ctx ): string {
		$trigger = is_array( $ctx['trigger'] ?? null ) ? $ctx['trigger'] : array();
		$chat_id = (string) ( $trigger['chat_id'] ?? '' );
		if ( sanitize_key( (string) ( $trigger['chat_kind'] ?? 'private' ) ) === 'group' ) {
			return $chat_id;
		}
		$bot_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $trigger['bot_id'] ?? $trigger['account_id'] ?? '' ) );
		$user_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) ( $trigger['sender_user_id'] ?? $trigger['user_id'] ?? '' ) );
		if ( $bot_id !== '' && $user_id !== '' ) {
			return 'zalobot_' . $bot_id . '_' . $user_id;
		}
		return $chat_id;
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
