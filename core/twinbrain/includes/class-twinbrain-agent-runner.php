<?php
/**
 * TwinBrain — Agent ReAct Runner (TBR.W20 / Phase 0.36-UNIFIED Wave 2.9).
 *
 * Generic ReAct loop over `BizCity_Twin_Tool_Registry`. Pattern copied
 * from `BizCity_TwinBrain_Web_Deep` (Stage 2.5 deep web research) but
 * swaps the fixed search/extract/crawl tool pool with the full Twin
 * Tool Registry, filtered by an allowed whitelist.
 *
 * Phase 1 default whitelist = retriever + memory tools only:
 *   - `search_kg`     — passage search over notebook KG
 *   - `list_sources`  — enumerate notebook sources
 *   - `fetch_url`     — fetch arbitrary URL (with safety guards in tool)
 *   - `query_entity`  — KG entity lookup
 *   - `memory_remember` / `memory_recall` / `memory_forget`
 *
 * Producer/distributor tools (`sheet_enrich`, `content_creator_*`) are
 * EXCLUDED by default for safety (they have side effects: DB writes,
 * publishing). Site can opt-in via filter `bizcity_twinbrain_agent_allowed_tools`.
 *
 * SSE events (R-EVT-1, single channel):
 *   • agent_loop_started  { trace_id, max_iter, tools[] }
 *   • agent_step_done     { trace_id, iter, thought, tool, args, summary, ms }
 *   • agent_loop_done     { trace_id, iter_count, final_text_len, tokens, ms, forced_final, reason }
 *
 * Hard rules:
 *   - R-GW    — LLM via `BizCity_LLM_Client` (gateway /llm/chat).
 *   - R-EVT-1 — events via `BizCity_Twin_Event_Bus::dispatch()` + SSE.
 *   - Wall budget: 60s total. Per-iter LLM timeout 15s. Per-tool 12s.
 *   - Cap 5 tool calls/turn; force `final` after.
 *
 * Returns an array compatible with `complete_turn_stream()` shape so
 * Runtime can plug it in via the same return contract.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-28 (Phase 0.36-UNIFIED · TBR.W20)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Agent_Runner {

	const MAX_ITERATIONS    = 5;
	const TOTAL_BUDGET_S    = 60;
	const ITER_TIMEOUT_S    = 15;
	const TOOL_TIMEOUT_S    = 12;
	const LLM_TEMPERATURE   = 0.3;
	const LLM_MAX_TOKENS    = 900;
	const FORCE_FINAL_TIMEOUT_S = 22;
	const HISTORY_TRUNC     = 6000;
	const TOOL_RESULT_TRUNC = 700;

	/** Default whitelist — safe retriever + memory tools only. */
	const DEFAULT_ALLOWED = [
		'search_kg',
		'list_sources',
		'fetch_url',
		'query_entity',
		'memory_remember',
		'memory_recall',
		'memory_forget',
	];

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Run agent ReAct loop with streaming SSE emission.
	 *
	 * @param string $trace_id
	 * @param string $prompt
	 * @param array  $opts     { user_id, session_id, guru_id, memory_block, scope, notebook_id, allowed_tools[] }
	 * @param callable|null $on_event Optional fn(string $event_key, array $payload) to relay SSE.
	 * @return array {
	 *   ok, answer_md, iterations[], tool_runs[], citations[],
	 *   tokens, model, ms, forced_final, reason
	 * }
	 */
	public function run( string $trace_id, string $prompt, array $opts = [], $on_event = null ): array {
		$turn_start = microtime( true );
		$prompt     = trim( $prompt );

		$out = [
			'ok'           => true,
			'answer_md'    => '',
			'iterations'   => [],
			'tool_runs'    => [],
			'citations'    => [],
			'tokens'       => 0,
			'model'        => '',
			'ms'           => 0,
			'forced_final' => false,
			'reason'       => '',
			'error'        => '',
		];

		if ( $prompt === '' ) {
			$out['ok']     = false;
			$out['error']  = 'empty_prompt';
			$out['reason'] = 'empty_prompt';
			$out['ms']     = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $out;
		}

		/* ─── Tool whitelist resolution ──────────────────────────────────── */
		$requested_allow = isset( $opts['allowed_tools'] ) && is_array( $opts['allowed_tools'] )
			? array_values( array_filter( array_map( 'strval', $opts['allowed_tools'] ) ) )
			: self::DEFAULT_ALLOWED;

		/**
		 * Filter: bizcity_twinbrain_agent_allowed_tools
		 * Site can extend/restrict tool whitelist (e.g. add `sheet_enrich`
		 * when guru is a "data ops" persona).
		 *
		 * @param string[] $allowed
		 * @param string   $trace_id
		 * @param array    $opts
		 */
		$allowed = (array) apply_filters(
			'bizcity_twinbrain_agent_allowed_tools',
			$requested_allow,
			$trace_id,
			$opts
		);
		$allowed = array_values( array_unique( array_filter( array_map( 'strval', $allowed ) ) ) );

		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$out['ok']     = false;
			$out['error']  = 'registry_missing';
			$out['reason'] = 'registry_missing';
			$out['ms']     = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $out;
		}

		$registry = BizCity_Twin_Tool_Registry::instance();
		$tools    = $registry->get_all( $allowed );
		$tool_names = array_keys( $tools );

		$this->emit( $on_event, 'agent_loop_started', [
			'trace_id' => $trace_id,
			'max_iter' => self::MAX_ITERATIONS,
			'tools'    => $tool_names,
		] );

		if ( empty( $tools ) ) {
			// No tools available — degrade to direct LLM answer instead of looping.
			$direct = $this->direct_answer( $trace_id, $prompt, $opts, $on_event );
			$out['answer_md']    = $direct['content'];
			$out['tokens']      += (int) $direct['tokens'];
			$out['model']        = (string) $direct['model'];
			$out['forced_final'] = true;
			$out['reason']       = 'no_tools_available';
			$out['ms']           = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit_loop_done( $on_event, $trace_id, $out, 0 );
			return $out;
		}

		/* ─── Capability gates ───────────────────────────────────────────── */
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			$out['ok']     = false;
			$out['error']  = 'llm_client_missing';
			$out['reason'] = 'llm_client_missing';
			$out['ms']     = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit_loop_done( $on_event, $trace_id, $out, 0 );
			return $out;
		}
		$llm = BizCity_LLM_Client::instance();
		if ( method_exists( $llm, 'is_ready' ) && ! $llm->is_ready() ) {
			$out['ok']     = false;
			$out['error']  = 'gateway_not_ready';
			$out['reason'] = 'gateway_not_ready';
			$out['ms']     = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit_loop_done( $on_event, $trace_id, $out, 0 );
			return $out;
		}

		$model = $this->resolve_model();
		$out['model'] = $model;

		$tool_context = $this->build_tool_context( $opts );

		$history       = []; // each: { thought, tool, args, summary, raw_result_truncated }
		$iter          = 0;
		$final_answer  = '';
		$forced_final  = false;
		$reason        = '';
		$tool_run_log  = [];

		for ( $iter = 1; $iter <= self::MAX_ITERATIONS; $iter++ ) {
			$elapsed = microtime( true ) - $turn_start;
			if ( $elapsed > self::TOTAL_BUDGET_S ) {
				$forced_final = true;
				$reason       = 'total_budget_exceeded';
				break;
			}

			$iter_start = microtime( true );

			// Build messages: system = registry prompt + agent instructions,
			// user = original prompt + history block + next-step nudge.
			$messages = $this->build_messages( $registry, $tools, $prompt, $opts, $history, $iter );

			$llm_out = $this->llm_call( $trace_id, $model, $messages, self::ITER_TIMEOUT_S );
			$out['tokens'] += (int) $llm_out['tokens'];

			if ( ! empty( $llm_out['error'] ) ) {
				$forced_final = true;
				$reason       = 'llm_error:' . $llm_out['error'];
				break;
			}

			$llm_text = (string) $llm_out['content'];
			$call     = BizCity_Twin_Tool_Registry::parse_tool_call( $llm_text );

			if ( $call === null ) {
				// No <tool> block → treat as final answer.
				$final_answer = trim( $llm_text );
				$out['iterations'][] = [
					'iter'    => $iter,
					'thought' => $this->extract_thought( $llm_text ),
					'tool'    => 'final',
					'args'    => [],
					'summary' => mb_substr( $final_answer, 0, 240 ),
					'ms'      => (int) round( ( microtime( true ) - $iter_start ) * 1000 ),
				];
				$this->emit( $on_event, 'agent_step_done', $out['iterations'][ count( $out['iterations'] ) - 1 ] + [ 'trace_id' => $trace_id ] );
				break;
			}

			$tool_name = (string) $call['name'];
			$tool_args = (array)  $call['args'];

			if ( ! isset( $tools[ $tool_name ] ) ) {
				// LLM hallucinated tool name. Record observation + continue.
				$history[] = [
					'thought' => $this->extract_thought( $llm_text ),
					'tool'    => $tool_name,
					'args'    => $tool_args,
					'summary' => 'ERROR: tool "' . $tool_name . '" không có trong whitelist. Available: ' . implode( ', ', $tool_names ),
				];
				$iter_ms = (int) round( ( microtime( true ) - $iter_start ) * 1000 );
				$payload = [
					'trace_id' => $trace_id,
					'iter'     => $iter,
					'thought'  => $history[ count( $history ) - 1 ]['thought'],
					'tool'     => $tool_name,
					'args'     => $tool_args,
					'summary'  => $history[ count( $history ) - 1 ]['summary'],
					'ms'       => $iter_ms,
					'error'    => 'unknown_tool',
				];
				$out['iterations'][] = $payload;
				$this->emit( $on_event, 'agent_step_done', $payload );
				continue;
			}

			/* Execute tool. */
			$thought   = $this->extract_thought( $llm_text );
			$tool      = $tools[ $tool_name ];
			$tool_t0   = microtime( true );
			$exec      = $this->execute_tool_safe( $tool, $tool_args, $tool_context );
			$tool_ms   = (int) round( ( microtime( true ) - $tool_t0 ) * 1000 );

			$summary   = $this->tool_summary( $tool_name, $exec );
			$truncated = $this->truncate_result_for_history( $exec );

			$history[] = [
				'thought' => $thought,
				'tool'    => $tool_name,
				'args'    => $tool_args,
				'summary' => $summary,
				'result'  => $truncated,
			];

			// Collect tool citations.
			if ( ! empty( $exec['citation_ids'] ) && is_array( $exec['citation_ids'] ) ) {
				foreach ( $exec['citation_ids'] as $cid ) {
					$out['citations'][] = [ 'tool' => $tool_name, 'id' => (string) $cid ];
				}
			}

			$tool_run_log[] = [
				'iter'    => $iter,
				'tool'    => $tool_name,
				'ok'      => ! empty( $exec['ok'] ),
				'ms'      => $tool_ms,
				'error'   => (string) ( $exec['error'] ?? '' ),
				'summary' => $summary,
			];

			$iter_ms = (int) round( ( microtime( true ) - $iter_start ) * 1000 );
			$step_payload = [
				'trace_id' => $trace_id,
				'iter'     => $iter,
				'thought'  => $thought,
				'tool'     => $tool_name,
				'args'     => $tool_args,
				'summary'  => $summary,
				'ms'       => $iter_ms,
				'tool_ms'  => $tool_ms,
				'ok'       => ! empty( $exec['ok'] ),
				'error'    => (string) ( $exec['error'] ?? '' ),
			];
			$out['iterations'][] = $step_payload;
			$this->emit( $on_event, 'agent_step_done', $step_payload );
		}

		/* ─── Force-final synthesis if loop ended without final answer ─── */
		if ( $final_answer === '' ) {
			$forced_final = true;
			$reason       = $reason ?: 'iter_cap';
			$synth = $this->force_final( $trace_id, $model, $prompt, $opts, $history );
			$out['tokens'] += (int) $synth['tokens'];
			$final_answer   = (string) $synth['content'];
			if ( $final_answer === '' ) {
				$final_answer = $this->stub_from_history( $history );
			}
		}

		$out['answer_md']    = $final_answer;
		$out['tool_runs']    = $tool_run_log;
		$out['forced_final'] = $forced_final;
		$out['reason']       = $reason;
		$out['ms']           = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit_loop_done( $on_event, $trace_id, $out, count( $tool_run_log ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][agent-runner] trace=%s iters=%d tool_runs=%d tokens=%d wall=%dms forced=%s reason=%s',
				$trace_id,
				count( $out['iterations'] ),
				count( $tool_run_log ),
				$out['tokens'],
				$out['ms'],
				$forced_final ? 'yes' : 'no',
				$reason
			) );
		}

		return $out;
	}

	/* =================================================================
	 *  Tool execution
	 * ================================================================ */

	private function execute_tool_safe( BizCity_Twin_Tool $tool, array $args, array $context ): array {
		try {
			$res = $tool->execute( $args, $context );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][agent-runner][tool-exec] ' . $tool->name() . ' threw: ' . $e->getMessage() );
			}
			return [ 'ok' => false, 'error' => 'exception:' . $e->getMessage() ];
		}
		if ( ! is_array( $res ) ) {
			return [ 'ok' => false, 'error' => 'non_array_result' ];
		}
		// Normalize
		if ( ! isset( $res['ok'] ) ) {
			$res['ok'] = empty( $res['error'] );
		}
		return $res;
	}

	private function tool_summary( string $tool_name, array $exec ): string {
		if ( ! empty( $exec['summary'] ) ) {
			return mb_substr( (string) $exec['summary'], 0, 240 );
		}
		if ( ! empty( $exec['error'] ) ) {
			return 'ERROR(' . $tool_name . '): ' . mb_substr( (string) $exec['error'], 0, 200 );
		}
		if ( isset( $exec['result'] ) ) {
			$j = wp_json_encode( $exec['result'] );
			return mb_substr( (string) $j, 0, 240 );
		}
		return $tool_name . ' OK';
	}

	private function truncate_result_for_history( array $exec ): string {
		$payload = [
			'ok'      => ! empty( $exec['ok'] ),
			'summary' => isset( $exec['summary'] ) ? (string) $exec['summary'] : '',
			'result'  => isset( $exec['result'] )  ? $exec['result']  : null,
			'sources' => isset( $exec['sources'] ) ? $exec['sources'] : null,
		];
		$json = (string) wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return mb_substr( $json, 0, self::TOOL_RESULT_TRUNC );
	}

	private function build_tool_context( array $opts ): array {
		$ctx = [
			'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
			'session_id' => (string) ( $opts['session_id'] ?? '' ),
			'surface'    => 'twinbrain.agent',
		];
		// Pass scope so tools like search_kg can resolve notebook.
		if ( ! empty( $opts['scope'] ) && is_array( $opts['scope'] ) ) {
			$ctx['scope'] = $opts['scope'];
		} elseif ( ! empty( $opts['notebook_id'] ) ) {
			$ctx['scope'] = [
				'type'     => 'notebook',
				'scope_id' => (int) $opts['notebook_id'],
				'id'       => (int) $opts['notebook_id'],
			];
		}
		return $ctx;
	}

	/* =================================================================
	 *  LLM
	 * ================================================================ */

	private function llm_call( string $trace_id, string $model, array $messages, int $timeout_s ): array {
		$llm      = BizCity_LLM_Client::instance();
		$endpoint = rtrim( $llm->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$api_key  = $llm->get_api_key();
		$site_url = home_url();

		$body = wp_json_encode( [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => self::LLM_TEMPERATURE,
			'max_tokens'  => self::LLM_MAX_TOKENS,
			'purpose'     => 'twinbrain_agent_react',
			'site_url'    => $site_url,
			'timeout'     => $timeout_s,
		] );

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Content-Type'    => 'application/json',
				'Authorization'   => 'Bearer ' . $api_key,
				'X-Site-URL'      => $site_url,
				'X-Twin-Trace-Id' => $trace_id,
			],
			'body'    => $body,
			'timeout' => $timeout_s,
		] );

		if ( is_wp_error( $response ) ) {
			return [ 'content' => '', 'tokens' => 0, 'error' => 'http:' . $response->get_error_code() ];
		}
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			$err = 'gateway:' . ( $decoded['error'] ?? $decoded['message'] ?? 'unknown' );
			return [ 'content' => '', 'tokens' => 0, 'error' => $err ];
		}
		$content = trim( (string) ( $decoded['message'] ?? '' ) );
		return [
			'content' => $content,
			'tokens'  => (int) ( $decoded['usage']['total_tokens'] ?? 0 ),
			'error'   => '',
		];
	}

	private function direct_answer( string $trace_id, string $prompt, array $opts, $on_event ): array {
		$model = $this->resolve_model();
		$msgs  = [
			[ 'role' => 'system', 'content' => "Bạn là TwinBrain Agent nhưng turn này không có tool nào khả dụng. Trả lời trực tiếp dựa trên kiến thức nội tại, tiếng Việt, ≤300 từ. Nếu không chắc, nói rõ." ],
			[ 'role' => 'user',   'content' => $prompt ],
		];
		$out = $this->llm_call( $trace_id, $model, $msgs, self::FORCE_FINAL_TIMEOUT_S );
		$out['model'] = $model;
		return $out;
	}

	private function force_final( string $trace_id, string $model, string $prompt, array $opts, array $history ): array {
		$history_str = $this->serialize_history( $history );
		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );

		$system = "Bạn là TwinBrain Agent đang ở bước SYNTHESIZE cuối cùng (đã hết iteration budget). Tổng hợp tool results trong HISTORY thành câu trả lời cho user. ≤350 từ, tiếng Việt, markdown nhẹ. Nếu không đủ data, nói rõ phần nào còn thiếu.";
		$user_parts = [];
		if ( $memory_block !== '' ) $user_parts[] = $memory_block;
		$user_parts[] = "## CÂU HỎI\n" . $prompt;
		$user_parts[] = "## TOOL HISTORY\n" . ( $history_str === '' ? '(không có tool run nào)' : $history_str );
		$user_parts[] = "Trả lời cuối cùng theo system prompt.";

		return $this->llm_call( $trace_id, $model, [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => implode( "\n\n", $user_parts ) ],
		], self::FORCE_FINAL_TIMEOUT_S );
	}

	private function stub_from_history( array $history ): string {
		if ( empty( $history ) ) {
			return '_Agent gateway down — không có tool result nào thu thập được._';
		}
		$lines = [ '_LLM synth unavailable — tool runs đã thực hiện:_', '' ];
		foreach ( array_slice( $history, 0, 5 ) as $h ) {
			$lines[] = sprintf( '- **%s**: %s', $h['tool'], mb_substr( (string) ( $h['summary'] ?? '' ), 0, 200 ) );
		}
		return implode( "\n", $lines );
	}

	private function resolve_model(): string {
		$model = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$model = BizCity_LLM_Client::instance()->get_model( 'chat' );
		}
		/** Filter: bizcity_twinbrain_agent_model — override reasoning model. */
		return (string) apply_filters( 'bizcity_twinbrain_agent_model', $model );
	}

	/* =================================================================
	 *  Prompt build
	 * ================================================================ */

	private function build_messages( BizCity_Twin_Tool_Registry $registry, array $tools, string $prompt, array $opts, array $history, int $iter ): array {
		$tool_section = $registry->render_prompt_section( array_keys( $tools ) );
		$history_str  = $this->serialize_history( $history );
		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );

		$system = "Bạn là **TwinBrain Agent** — chạy theo vòng lặp ReAct để giải quyết câu hỏi user bằng tools.\n\n"
			. "QUY TRÌNH MỖI BƯỚC:\n"
			. "1. Viết ngắn 1-2 câu suy nghĩ (Thought) prefix `Thought:`.\n"
			. "2. Quyết định: gọi 1 tool HOẶC viết câu trả lời cuối.\n"
			. "   - Gọi tool: emit ĐÚNG format `<tool name=\"...\">{\"...\"}</tool>` rồi DỪNG.\n"
			. "   - Trả lời cuối: viết câu trả lời tiếng Việt, KHÔNG có `<tool>` block.\n"
			. "3. Tối đa " . self::MAX_ITERATIONS . " tool call mỗi turn. Sau đó system sẽ force synthesize.\n"
			. "4. Khi tool trả citation_id (vd `[a3x9]`, `[mem:U#101]`), echo lại trong câu trả lời cuối.\n"
			. "5. KHÔNG gọi tool lặp với args giống hệt lần trước — đọc lại HISTORY.\n\n"
			. $tool_section;

		$user_parts = [];
		if ( $memory_block !== '' ) {
			$user_parts[] = $memory_block;
		}
		$user_parts[] = "## CÂU HỎI USER\n" . $prompt;
		$user_parts[] = "## ITERATION " . $iter . "/" . self::MAX_ITERATIONS;
		$user_parts[] = "## TOOL HISTORY\n" . ( $history_str === '' ? '(empty — iteration đầu tiên)' : $history_str );
		$user_parts[] = "Bước tiếp theo (Thought + <tool> HOẶC câu trả lời cuối):";

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => implode( "\n\n", $user_parts ) ],
		];
	}

	private function serialize_history( array $history ): string {
		if ( empty( $history ) ) return '';
		$out  = '';
		$skip = max( 0, count( $history ) - 4 ); // keep last 4 for budget
		foreach ( array_slice( $history, $skip ) as $i => $h ) {
			$args_str = wp_json_encode( $h['args'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$out .= sprintf(
				"Step %d:\n  Thought: %s\n  Tool: %s\n  Args: %s\n  Result: %s\n\n",
				$skip + $i + 1,
				mb_substr( (string) ( $h['thought'] ?? '' ), 0, 240 ),
				(string) ( $h['tool'] ?? '' ),
				mb_substr( (string) $args_str, 0, 200 ),
				mb_substr( (string) ( $h['summary'] ?? '' ), 0, 300 )
			);
		}
		if ( mb_strlen( $out ) > self::HISTORY_TRUNC ) {
			$out = mb_substr( $out, - self::HISTORY_TRUNC );
		}
		return $out;
	}

	private function extract_thought( string $llm_text ): string {
		if ( preg_match( '/Thought\s*:\s*(.+?)(?=\n\s*(?:<tool|Action|Final|$))/is', $llm_text, $m ) ) {
			return trim( $m[1] );
		}
		// Fallback: first non-empty line that isn't <tool>.
		foreach ( preg_split( '/\r?\n/', $llm_text ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || strpos( $line, '<tool' ) === 0 ) continue;
			return mb_substr( $line, 0, 240 );
		}
		return '';
	}

	/* =================================================================
	 *  Event bus
	 * ================================================================ */

	private function emit( $on_event, string $event_key, array $payload ): void {
		if ( is_callable( $on_event ) ) {
			call_user_func( $on_event, $event_key, $payload );
		}
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try { BizCity_Twin_Event_Bus::dispatch( $event_key, $payload ); }
			catch ( \Throwable $e ) { /* swallow */ }
		}
	}

	private function emit_loop_done( $on_event, string $trace_id, array $out, int $tool_run_count ): void {
		$this->emit( $on_event, 'agent_loop_done', [
			'trace_id'       => $trace_id,
			'iter_count'     => count( $out['iterations'] ),
			'tool_run_count' => $tool_run_count,
			'final_text_len' => mb_strlen( (string) $out['answer_md'] ),
			'tokens'         => (int) $out['tokens'],
			'model'          => (string) $out['model'],
			'ms'             => (int) $out['ms'],
			'forced_final'   => ! empty( $out['forced_final'] ),
			'reason'         => (string) ( $out['reason'] ?? '' ),
			'citations'      => (array)  $out['citations'],
		] );
	}
}
