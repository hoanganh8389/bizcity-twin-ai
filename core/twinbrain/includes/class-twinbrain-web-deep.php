<?php
/**
 * TwinBrain — Web Research Deep Engine (TBR.W7-be-deep).
 *
 * Stage 2.5 depth path: ReAct agent với max 5 iterations. Mỗi iteration:
 *
 *   1. LLM nhận prompt chứa lịch sử [Thought/Action/Observation]
 *   2. Parse output thành (Thought, Action, Action Input)
 *   3. Nếu Action = `final` → kết thúc + answer
 *   4. Nếu Action = search/extract/crawl → gọi tool (qua R-GW Search_Client)
 *      → summarize observation bằng nano-LLM trước khi feed lại
 *   5. Append vào history, increment iter
 *
 * 5 SSE events:
 *   • web_research_started   { mode:'deep', query }
 *   • web_search_done        (per search call, có thể nhiều lần)
 *   • web_extract_done       (per extract/crawl call)
 *   • web_react_step         { iter, thought, action, action_input, observation, ms }
 *   • web_synthesize_done    { answer_md, citation_count, tokens, ms }
 *
 * Hard rules:
 *   - R-GW   — tools đi qua `BizCity_Search_Client::{search,extract,crawl}`.
 *              LLM đi qua `BizCity_LLM_Client` → gateway `/llm/chat`.
 *   - R-EVT-1 — single channel `BizCity_Twin_Event_Bus::dispatch()`.
 *   - R-SKILL N1 — prompt load từ skill `web_research_deep` (W8 seeder);
 *              fallback hardcoded khi seeder chưa chạy.
 *   - Hard timeout: 6s/iter, 30s total budget. Vượt → force `final`.
 *
 * Port từ `core/research/tavily-chat-main/backend/agent.py` (LangGraph
 * create_react_agent) thành PHP procedural loop — KHÔNG dùng LangChain.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-21 (Phase 0.36-UNIFIED TBR.W7)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Deep {

	const SKILL_KEY        = 'web_research_deep';
	const MAX_ITERATIONS   = 5;
	// TBR.W7-fix-1 (2026-05-21): bumped from 30s/6s → 60s/12s after live
	// repro hit `forced_final:budget_or_iter_cap` on first user turn. Most
	// real-world ReAct iters use 4-8s for gateway LLM round-trip + JSON
	// parse; 6s ceiling was clipping legitimate calls before action emit.
	const TOTAL_BUDGET_S   = 60;
	const ITER_TIMEOUT_S   = 12;
	// Force-final synthesis gets its own (longer) ceiling because it must
	// digest the entire history + citation map in a single call.
	const FORCE_FINAL_TIMEOUT_S = 20;
	const LLM_TEMPERATURE  = 0.3;
	const LLM_MAX_TOKENS   = 800;
	const SEARCH_MAX       = 5;
	const SUMMARY_MAX_CHARS= 1200;
	const OBSERVATION_TRUNC= 600;
	const HISTORY_TRUNC    = 6000; // total chars cap for serialized history

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Run Deep ReAct pipeline.
	 *
	 * @param string $trace_id Brain turn trace id.
	 * @param string $query    User prompt.
	 * @param array  $opts     Optional. Keys: guru_id (int).
	 * @return array Web perspective row.
	 */
	public function run( string $trace_id, string $query, array $opts = [] ): array {
		$turn_start = microtime( true );
		$query      = trim( $query );

		$row = [
			'mode'           => 'deep',
			'trace_id'       => $trace_id,
			'query'          => $query,
			'results'        => [],
			'extracts'       => [],
			'iterations'     => [],
			'answer_md'      => '',
			'citations'      => [],
			'citation_count' => 0,
			'tokens'         => 0,
			'ms'             => 0,
			'http_status'    => 0,
			'error'          => '',
			'stance'         => 'unknown',
			'confidence'     => 0.0,
			'label'          => 'Web Research (Deep)',
		];

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$this->emit( 'web_research_started', [
			'trace_id' => $trace_id,
			'mode'     => 'deep',
			'query'    => $query,
			'max_iter' => self::MAX_ITERATIONS,
		] );

		/* ─── Capability gates ───────────────────────────────────────────── */
		if ( ! class_exists( 'BizCity_Search_Client' ) || ! class_exists( 'BizCity_LLM_Client' ) ) {
			$row['error'] = 'gateway_missing';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit_synth_done( $trace_id, $row, 0 );
			return $row;
		}
		$search = BizCity_Search_Client::instance();
		$llm    = BizCity_LLM_Client::instance();
		if ( ! $search->is_ready() || ! $llm->is_ready() ) {
			$row['error'] = 'gateway_not_ready';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit_synth_done( $trace_id, $row, 0 );
			return $row;
		}

		$prompt_tmpl = $this->load_prompt_template();
		$model       = $this->resolve_model();
		$endpoint    = rtrim( $llm->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$api_key     = $llm->get_api_key();
		$site_url    = home_url();

		$history     = [];          // array of ['thought','action','action_input','observation']
		$all_results = [];          // accumulated for citation map (index → url)
		$total_tokens = 0;
		$final_answer = '';
		$forced_final = false;
		$iter         = 0;

		/* ─── ReAct loop ─────────────────────────────────────────────────── */
		for ( $iter = 1; $iter <= self::MAX_ITERATIONS; $iter++ ) {
			// Total budget guard.
			$elapsed = microtime( true ) - $turn_start;
			if ( $elapsed > self::TOTAL_BUDGET_S ) {
				$forced_final = true;
				break;
			}

			$iter_start = microtime( true );
			$messages   = $this->build_react_messages( $prompt_tmpl, $query, $history, $iter, $all_results );

			$llm_out = $this->llm_call( $endpoint, $api_key, $site_url, $trace_id, $model, $messages );
			$total_tokens += (int) $llm_out['tokens'];

			if ( ! empty( $llm_out['error'] ) ) {
				// LLM down → break and force a synthesis from accumulated history.
				$forced_final = true;
				break;
			}

			$parsed = $this->parse_react_step( (string) $llm_out['content'] );

			// Action = final → done. Use action_input as answer_md.
			if ( $parsed['action'] === 'final' ) {
				$final_answer       = trim( (string) $parsed['action_input'] );
				$iter_ms            = (int) round( ( microtime( true ) - $iter_start ) * 1000 );
				$step_payload = [
					'trace_id'     => $trace_id,
					'iter'         => $iter,
					'thought'      => $parsed['thought'],
					'action'       => 'final',
					'action_input' => mb_substr( $final_answer, 0, 240 ),
					'observation'  => '',
					'ms'           => $iter_ms,
				];
				$row['iterations'][] = $step_payload;
				$this->emit( 'web_react_step', $step_payload );
				break;
			}

			/* Dispatch tool. */
			$tool_start = microtime( true );
			$obs        = $this->dispatch_tool( $trace_id, $parsed['action'], (string) $parsed['action_input'], $all_results, $row );
			$tool_ms    = (int) round( ( microtime( true ) - $tool_start ) * 1000 );

			$obs_str = $this->stringify_observation( $obs );

			$history[] = [
				'thought'      => $parsed['thought'],
				'action'       => $parsed['action'],
				'action_input' => (string) $parsed['action_input'],
				'observation'  => $obs_str,
			];

			$iter_ms       = (int) round( ( microtime( true ) - $iter_start ) * 1000 );
			$step_payload = [
				'trace_id'     => $trace_id,
				'iter'         => $iter,
				'thought'      => $parsed['thought'],
				'action'       => $parsed['action'],
				'action_input' => mb_substr( (string) $parsed['action_input'], 0, 240 ),
				'observation'  => mb_substr( $obs_str, 0, self::OBSERVATION_TRUNC ),
				'ms'           => $iter_ms,
				'tool_ms'      => $tool_ms,
			];
			$row['iterations'][] = $step_payload;
			$this->emit( 'web_react_step', $step_payload );

			// Per-iter timeout guard. Now uses a generous 1.5x ceiling so a slow
			// gateway call that JUST finished doesn't trigger a forced final.
			if ( $iter_ms > (int) ( self::ITER_TIMEOUT_S * 1500 ) ) {
				$forced_final = true;
				break;
			}
		}

		/* ─── Finalization ───────────────────────────────────────────────── */
		if ( $final_answer === '' ) {
			$synth_start = microtime( true );
			$synth       = $this->force_final_synthesis(
				$endpoint, $api_key, $site_url, $trace_id, $model,
				$prompt_tmpl, $query, $history, $all_results
			);
			$total_tokens += (int) $synth['tokens'];
			$final_answer  = (string) $synth['content'];
			if ( $final_answer === '' ) {
				$final_answer = $this->stub_answer_from_history( $history, $all_results );
			}
			if ( $forced_final ) {
				$row['error'] = $row['error'] ?: 'forced_final:budget_or_iter_cap';
			}
		}

		$row['answer_md']      = $final_answer;
		$row['tokens']         = $total_tokens;
		$row['citations']      = $this->extract_citations( $final_answer, $all_results );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0
			? min( 1.0, 0.5 + 0.10 * $row['citation_count'] )
			: 0.35;
		$row['stance']         = $row['citation_count'] > 0 ? 'conditional' : 'unknown';

		// Top-level `results` = unique union of all search results across iters.
		$row['results'] = $all_results;
		$row['ms']      = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit_synth_done( $trace_id, $row, $row['ms'] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][web-deep] trace=%s iters=%d results=%d cite=%d tokens=%d wall=%dms forced=%s',
				$trace_id, count( $row['iterations'] ), count( $all_results ),
				$row['citation_count'], $total_tokens, $row['ms'],
				$forced_final ? 'yes' : 'no'
			) );
		}

		return $row;
	}

	/* =================================================================
	 *  Tool dispatch (R-GW)
	 * ================================================================ */

	/**
	 * Run a ReAct tool action against the Search gateway.
	 * Mutates $all_results (accumulator) and $row['extracts'] for telemetry.
	 *
	 * @return array Observation array (engine-defined per action).
	 */
	private function dispatch_tool( string $trace_id, string $action, string $input, array &$all_results, array &$row ): array {
		$client = BizCity_Search_Client::instance();
		$action = strtolower( trim( $action ) );

		switch ( $action ) {
			case 'search':
				$res = $client->search( $input, self::SEARCH_MAX, [
					'search_depth'        => 'advanced',
					'include_raw_content' => false,
					'timeout'             => self::ITER_TIMEOUT_S,
				] );
				if ( is_wp_error( $res ) ) {
					return [ 'kind' => 'search', 'error' => $res->get_error_code() ];
				}
				$normalized = $this->normalize_search_results( $res );
				$all_results = $this->merge_results( $all_results, $normalized );
				$this->emit( 'web_search_done', [
					'trace_id' => $trace_id,
					'mode'     => 'deep',
					'query'    => $input,
					'results'  => $normalized,
				] );
				return [ 'kind' => 'search', 'results' => $normalized ];

			case 'extract':
				$urls = $this->parse_url_list( $input );
				if ( empty( $urls ) ) {
					return [ 'kind' => 'extract', 'error' => 'no_url' ];
				}
				$res = $client->extract( $urls );
				if ( is_wp_error( $res ) ) {
					return [ 'kind' => 'extract', 'error' => $res->get_error_code() ];
				}
				$summarized = $this->summarize_extract( $res );
				$row['extracts'] = array_merge( $row['extracts'], $summarized );
				$this->emit( 'web_extract_done', [
					'trace_id' => $trace_id,
					'mode'     => 'deep',
					'urls'     => $urls,
					'extracts' => $summarized,
				] );
				return [ 'kind' => 'extract', 'extracts' => $summarized ];

			case 'crawl':
				$url = trim( $input );
				if ( $url === '' || ! method_exists( $client, 'crawl' ) ) {
					return [ 'kind' => 'crawl', 'error' => 'no_url_or_unsupported' ];
				}
				$res = $client->crawl( $url );
				if ( is_wp_error( $res ) ) {
					return [ 'kind' => 'crawl', 'error' => $res->get_error_code() ];
				}
				$summarized = $this->summarize_extract( $res );
				$row['extracts'] = array_merge( $row['extracts'], $summarized );
				$this->emit( 'web_extract_done', [
					'trace_id' => $trace_id,
					'mode'     => 'deep',
					'urls'     => [ $url ],
					'extracts' => $summarized,
				] );
				return [ 'kind' => 'crawl', 'extracts' => $summarized ];

			default:
				return [ 'kind' => 'unknown_action', 'action' => $action ];
		}
	}

	/* =================================================================
	 *  LLM
	 * ================================================================ */

	private function llm_call( string $endpoint, string $api_key, string $site_url, string $trace_id, string $model, array $messages, int $timeout_s = 0 ): array {
		$timeout_s = $timeout_s > 0 ? $timeout_s : self::ITER_TIMEOUT_S;
		$body = wp_json_encode( [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => self::LLM_TEMPERATURE,
			'max_tokens'  => self::LLM_MAX_TOKENS,
			'purpose'     => 'twinbrain_web_deep',
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
			$err = 'http:' . $response->get_error_code();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][web-deep][llm] trace=' . $trace_id . ' err=' . $err . ' msg=' . $response->get_error_message() );
			}
			return [ 'content' => '', 'tokens' => 0, 'error' => $err ];
		}
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			$err = 'gateway:' . ( $decoded['error'] ?? $decoded['message'] ?? 'unknown' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][web-deep][llm] trace=' . $trace_id . ' err=' . $err . ' raw=' . mb_substr( $raw, 0, 400 ) );
			}
			return [ 'content' => '', 'tokens' => 0, 'error' => $err ];
		}
		$content = trim( (string) ( $decoded['message'] ?? '' ) );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $content === '' ) {
			error_log( '[TwinBrain][web-deep][llm] trace=' . $trace_id . ' empty_content keys=' . implode( ',', array_keys( $decoded ) ) );
		}
		return [
			'content' => $content,
			'tokens'  => (int) ( $decoded['usage']['total_tokens'] ?? 0 ),
			'error'   => '',
		];
	}

	private function resolve_model(): string {
		$model = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$model = BizCity_LLM_Client::instance()->get_model( 'chat' );
		}
		/**
		 * Filter: bizcity_twinbrain_web_deep_model
		 * @param string $model Reasoning model id for ReAct agent.
		 */
		return (string) apply_filters( 'bizcity_twinbrain_web_deep_model', $model );
	}

	/* =================================================================
	 *  Prompt + parsing
	 * ================================================================ */

	private function build_react_messages( string $template, string $query, array $history, int $iter, array $all_results ): array {
		$history_str = $this->serialize_history( $history );

		// Brief catalog of results available for citation so the LLM uses
		// stable [web:N#URL] tokens. Indices map to $all_results.
		$citation_map = '';
		foreach ( $all_results as $i => $r ) {
			$citation_map .= sprintf( "  [web:%d#%s] %s\n", $i + 1, $r['url'], $r['title'] );
			if ( $i >= 14 ) { $citation_map .= "  …\n"; break; }
		}
		if ( $citation_map === '' ) {
			$citation_map = '  (chưa có result nào — gọi `search` trước)';
		}

		$user = sprintf(
			"USER QUESTION:\n%s\n\nCITATION MAP (dùng cho [web:N#URL]):\n%s\nITERATION %d/%d\n\nHISTORY:\n%s\n\nĐưa ra Thought + Action + Action Input cho bước tiếp theo. Nếu đã đủ thông tin, dùng Action: final.",
			$query,
			$citation_map,
			$iter,
			self::MAX_ITERATIONS,
			$history_str === '' ? '(empty — đây là iteration đầu tiên)' : $history_str
		);

		return [
			[ 'role' => 'system', 'content' => $template ],
			[ 'role' => 'user',   'content' => $user ],
		];
	}

	private function serialize_history( array $history ): string {
		$out  = '';
		$skip = max( 0, count( $history ) - 4 ); // keep only last 4 for token budget
		foreach ( array_slice( $history, $skip ) as $i => $h ) {
			$out .= sprintf(
				"Iter %d:\n  Thought: %s\n  Action: %s\n  Action Input: %s\n  Observation: %s\n\n",
				$skip + $i + 1,
				mb_substr( (string) $h['thought'], 0, 240 ),
				$h['action'],
				mb_substr( (string) $h['action_input'], 0, 200 ),
				mb_substr( (string) $h['observation'], 0, self::OBSERVATION_TRUNC )
			);
		}
		if ( mb_strlen( $out ) > self::HISTORY_TRUNC ) {
			$out = mb_substr( $out, - self::HISTORY_TRUNC );
		}
		return $out;
	}

	/**
	 * Parse a ReAct step output. Tolerant to format drift; falls back to
	 * treating the whole response as a `final` answer when no Action: line
	 * is found.
	 *
	 * @return array{thought:string, action:string, action_input:string}
	 */
	private function parse_react_step( string $raw ): array {
		$out = [ 'thought' => '', 'action' => 'final', 'action_input' => $raw ];

		if ( preg_match( '/Thought\s*:\s*(.+?)(?=\n\s*(?:Action|Final|$))/is', $raw, $m ) ) {
			$out['thought'] = trim( $m[1] );
		}
		if ( preg_match( '/Action\s*:\s*([a-zA-Z_]+)/i', $raw, $m ) ) {
			$out['action'] = strtolower( trim( $m[1] ) );
		}
		if ( preg_match( '/Action\s+Input\s*:\s*(.+?)(?=\n\s*(?:Observation|Thought|Action|$))/is', $raw, $m ) ) {
			$out['action_input'] = trim( $m[1] );
		} elseif ( $out['action'] === 'final' && preg_match( '/Final\s+Answer\s*:\s*(.+)/is', $raw, $m ) ) {
			$out['action_input'] = trim( $m[1] );
		}

		// Normalize alias actions.
		if ( in_array( $out['action'], [ 'finish', 'done', 'answer' ], true ) ) {
			$out['action'] = 'final';
		}
		if ( ! in_array( $out['action'], [ 'search', 'extract', 'crawl', 'final' ], true ) ) {
			// Unknown action → treat as final with raw payload to avoid infinite loop.
			$out['action']       = 'final';
			$out['action_input'] = $raw;
		}
		return $out;
	}

	private function load_prompt_template(): string {
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			try {
				$skill = BizCity_Skill_Database::instance()->get_by_key( self::SKILL_KEY, 0, 0 );
				if ( is_array( $skill ) && ! empty( $skill['content'] ) ) {
					return (string) $skill['content'];
				}
			} catch ( \Throwable $e ) { /* fallthrough */ }
		}
		return "Bạn là agent ReAct nghiên cứu web (max 5 iter). Tools: search(query), extract(url), crawl(url), final(answer). Output mỗi step theo format:\nThought: <suy nghĩ>\nAction: search|extract|crawl|final\nAction Input: <query/url/câu trả lời>\nTrích dẫn bắt buộc [web:N#URL]. Tiếng Việt nếu câu hỏi tiếng Việt.";
	}

	/* =================================================================
	 *  Force-final synthesis (no `final` action reached)
	 * ================================================================ */

	private function force_final_synthesis(
		string $endpoint, string $api_key, string $site_url, string $trace_id, string $model,
		string $template, string $query, array $history, array $all_results
	): array {
		$history_str = $this->serialize_history( $history );
		$citation_map = '';
		foreach ( $all_results as $i => $r ) {
			$citation_map .= sprintf( "  [web:%d#%s] %s — %s\n", $i + 1, $r['url'], $r['title'], $r['domain'] );
			if ( $i >= 14 ) break;
		}

		// First attempt: ReAct-style synthesis using the original template.
		$user = sprintf(
			"USER QUESTION:\n%s\n\nCITATION MAP:\n%s\nHISTORY (đã hết iteration budget):\n%s\n\nDùng những gì đã thu thập được, tổng hợp câu trả lời cuối cùng (≤400 từ, trích dẫn [web:N#URL]). Nếu thông tin không đủ, ghi rõ.",
			$query,
			$citation_map ?: '  (no results gathered)',
			$history_str ?: '(empty)'
		);

		$out = $this->llm_call( $endpoint, $api_key, $site_url, $trace_id, $model, [
			[ 'role' => 'system', 'content' => $template ],
			[ 'role' => 'user',   'content' => $user ],
		], self::FORCE_FINAL_TIMEOUT_S );

		if ( $out['content'] !== '' ) return $out;

		// TBR.W7-fix-1 retry: drop the ReAct template, use a plain summarizer
		// prompt with snippet context. Many gateway models fail the ReAct
		// instruction tail but happily summarize when asked directly.
		$snippets = '';
		foreach ( array_slice( $all_results, 0, 8 ) as $i => $r ) {
			$snippets .= sprintf( "[web:%d#%s] %s\nDomain: %s\nSnippet: %s\n\n",
				$i + 1, $r['url'], $r['title'], $r['domain'], mb_substr( (string) ( $r['snippet'] ?? '' ), 0, 320 )
			);
		}
		if ( $snippets === '' ) return $out; // truly nothing to summarize

		$retry_sys = "Bạn là trợ lý tổng hợp web. Tổng hợp câu trả lời ≤250 từ dựa CHᢀ trên snippets. Mỗi mệnh đề phải kèm [web:N#URL]. Tiếng Việt.";
		$retry_usr = "WEB SNIPPETS:\n\n{$snippets}CÂU HỐI:\n{$query}\n\nTrả lời tổng hợp ≤250 từ với citation [web:N#URL].";

		return $this->llm_call( $endpoint, $api_key, $site_url, $trace_id, $model, [
			[ 'role' => 'system', 'content' => $retry_sys ],
			[ 'role' => 'user',   'content' => $retry_usr ],
		], self::FORCE_FINAL_TIMEOUT_S );
	}

	private function stub_answer_from_history( array $history, array $all_results ): string {
		if ( empty( $all_results ) ) {
			return '_LLM synth unavailable, không có web result thu thập được. Cần kiểm tra gateway._';
		}
		$lines = [ '_LLM synth unavailable — top web sources thu thập được:_', '' ];
		foreach ( array_slice( $all_results, 0, 5 ) as $i => $r ) {
			$lines[] = sprintf( '- [web:%d#%s] **%s** — %s', $i + 1, $r['url'], $r['title'], $r['domain'] );
		}
		return implode( "\n", $lines );
	}

	/* =================================================================
	 *  Result helpers
	 * ================================================================ */

	private function normalize_search_results( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) $raw = $raw['results'];
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$out[] = [
				'url'     => $url,
				'title'   => mb_substr( (string) ( $r['title'] ?? $url ), 0, 160 ),
				'snippet' => mb_substr( (string) ( $r['excerpt'] ?? $r['content'] ?? $r['snippet'] ?? '' ), 0, 480 ),
				'score'   => isset( $r['score'] ) ? (float) $r['score'] : 0.0,
				'domain'  => (string) ( $r['domain'] ?? $this->host_of( $url ) ),
			];
		}
		return $out;
	}

	private function merge_results( array $accum, array $incoming ): array {
		$seen = [];
		foreach ( $accum as $r ) $seen[ $r['url'] ] = true;
		foreach ( $incoming as $r ) {
			if ( isset( $seen[ $r['url'] ] ) ) continue;
			$accum[] = $r;
			$seen[ $r['url'] ] = true;
		}
		return $accum;
	}

	private function summarize_extract( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) $raw = $raw['results'];
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$content = (string) ( $r['raw_content'] ?? $r['content'] ?? $r['excerpt'] ?? '' );
			$out[] = [
				'url'     => $url,
				'title'   => mb_substr( (string) ( $r['title'] ?? $url ), 0, 160 ),
				'summary' => mb_substr( $content, 0, self::SUMMARY_MAX_CHARS ),
				'domain'  => (string) ( $r['domain'] ?? $this->host_of( $url ) ),
			];
		}
		return $out;
	}

	private function stringify_observation( array $obs ): string {
		if ( ! empty( $obs['error'] ) ) {
			return 'ERROR(' . $obs['kind'] . '): ' . $obs['error'];
		}
		if ( $obs['kind'] === 'search' && ! empty( $obs['results'] ) ) {
			$lines = [];
			foreach ( $obs['results'] as $i => $r ) {
				$lines[] = sprintf( '  %d. [%s] %s — %s', $i + 1, $r['domain'], $r['title'], mb_substr( $r['snippet'], 0, 200 ) );
			}
			return "SEARCH RESULTS:\n" . implode( "\n", $lines );
		}
		if ( in_array( $obs['kind'], [ 'extract', 'crawl' ], true ) && ! empty( $obs['extracts'] ) ) {
			$lines = [];
			foreach ( $obs['extracts'] as $r ) {
				$lines[] = sprintf( "  [%s] %s\n    %s", $r['domain'], $r['title'], mb_substr( $r['summary'], 0, 360 ) );
			}
			return strtoupper( $obs['kind'] ) . " OUTPUT:\n" . implode( "\n", $lines );
		}
		return wp_json_encode( $obs );
	}

	private function parse_url_list( string $input ): array {
		// Accept single URL or JSON/csv list.
		$input = trim( $input );
		if ( $input === '' ) return [];
		if ( $input[0] === '[' ) {
			$decoded = json_decode( $input, true );
			if ( is_array( $decoded ) ) {
				return array_values( array_filter( array_map( 'trim', $decoded ), [ $this, 'is_valid_url' ] ) );
			}
		}
		$parts = preg_split( '/[\s,]+/', $input );
		return array_values( array_filter( $parts, [ $this, 'is_valid_url' ] ) );
	}

	private function is_valid_url( $u ): bool {
		return is_string( $u ) && filter_var( $u, FILTER_VALIDATE_URL ) !== false;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
	}

	/* =================================================================
	 *  Citations
	 * ================================================================ */

	private function extract_citations( string $answer_md, array $results ): array {
		if ( ! preg_match_all( '/\[web:(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $matches, PREG_SET_ORDER ) ) {
			return [];
		}
		$out  = [];
		$seen = [];
		foreach ( $matches as $m ) {
			$n   = (int) $m[1];
			$key = $n . '|' . ( $m[2] ?? '' );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			$result = $results[ $n - 1 ] ?? null;
			$out[]  = [
				'token'     => $m[0],
				'kind'      => 'web',
				'web_index' => $n,
				'web_url'   => $m[2] ?? ( $result['url'] ?? '' ),
				'web_host'  => $result ? $result['domain'] : '',
				'web_title' => $result ? $result['title']  : '',
			];
		}
		return $out;
	}

	/* =================================================================
	 *  Event bus
	 * ================================================================ */

	private function emit( string $event_key, array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try { BizCity_Twin_Event_Bus::dispatch( $event_key, $payload ); return; }
			catch ( \Throwable $e ) { /* fallthrough */ }
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TwinBrain][web-deep][noop-bus] ' . $event_key );
		}
	}

	private function emit_synth_done( string $trace_id, array $row, int $ms ): void {
		$this->emit( 'web_synthesize_done', [
			'trace_id'       => $trace_id,
			'mode'           => 'deep',
			'answer_md'      => (string) $row['answer_md'],
			'citation_count' => (int) $row['citation_count'],
			'tokens'         => (int) $row['tokens'],
			'iterations'     => count( $row['iterations'] ),
			'ms'             => $ms,
			'error'          => (string) $row['error'],
		] );
	}
}
