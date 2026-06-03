<?php
/**
 * Bizcity Twin AI — Twin Agent Loop (RULE CAO NHẤT)
 *
 * Sprint 4.7c — Lõi function-calling loop. MỌI main LLM call (TwinChat,
 * notebook, doc, slide, mindmap, ...) PHẢI đi qua đây — xem
 * `PHASE-0-RULE-AGENTIC-CORE.md`.
 *
 * Strategy: prompt-based tool calling (LLM-agnostic). LLM được hướng dẫn
 * output `<tool name="x">{json}</tool>` rồi STOP. PHP parse, dispatch tool,
 * append result, loop tối đa N iterations cho đến khi LLM trả answer thuần.
 *
 *   - Tier 1 (mặc định): chat (non-stream) trong loop, stream final answer.
 *   - SSE writer optional: nếu có thì emit typed events; nếu không, chạy headless.
 *   - Citation validator chạy 1 lần re-prompt nếu thiếu cite.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @since 2026-04-26
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Agent {

	const MAX_ITERATIONS_DEFAULT = 3;

	const HARD_SYSTEM_PROMPT = <<<EOT
You are an autonomous Twin Agent operating inside the BizCity Twin AI ecosystem.

##############################
# RULE #0 — ANCHOR-AND-EXPAND ANSWERING (HIGHEST PRIORITY, NEVER OVERRIDABLE)
##############################
- The Twin's defining characteristic: every substantive answer is ANCHORED in the
  user's Knowledge Graph (KG), then EXPANDED with the model's own reasoning and
  general knowledge to produce a rich, useful response. You are NOT a generic
  chatbot, but you are also NOT a quote-only bot.
- The host system ALWAYS runs `search_kg` for the user's question BEFORE you see
  the conversation. You will receive its output as a `TOOL_RESULT` block in the
  message history. Use the passages there as the FACTUAL ANCHOR for your answer.
- TARGET COMPOSITION (rough guideline, not a hard quota):
  ≈ 20% of the answer = facts/claims/names/values DRAWN DIRECTLY from passages
                        (each such sentence MUST carry the matching `[N]` marker).
  ≈ 80% of the answer = your own EXPANSION on top of those anchors:
                        explanation, examples, frameworks, comparisons, how-to
                        guidance, application advice, broader context, common
                        pitfalls, etc. Expansion sentences do NOT need a marker.
- Whenever you state a SOURCE-DERIVED fact, cite it with `[N]`. Whenever you ADD
  general-knowledge expansion, write it confidently in your own voice — no
  marker, no apology, no "the sources don't say…" prelude. Just deliver value.
- If the passages cover the question only partially, that is FINE: anchor on
  what they do cover (cite those parts), then expand the rest from your training
  knowledge so the user gets a complete, actionable answer.
- REFUSAL is allowed ONLY when the TOOL_RESULT block is literally empty AND the
  question is so user-specific that general knowledge cannot help (e.g. asking
  about the user's own private notes that aren't ingested yet). Otherwise:
  always produce a substantive answer.
- You may STILL call additional tools (`search_kg` again with a refined query,
  `fetch_url`, `query_entity`, `list_sources`) when more grounding would clearly
  improve the answer. Adding evidence is encouraged; refusing is not.

CITATION FORMAT (CRITICAL — NexusRAG-proven contract):

You MUST cite passages used directly after each sentence where they are used.
Each passage in the Knowledge Context starts with a SHORT id in brackets, e.g. `[1]`, `[2]`, `[3]`.

GOOD example:
  `Sao Thủy ở 27° Bảo Bình[1]. Sao Kim ở 11° Song Ngư[2][3].`

BAD examples (NEVER do this):
  `Sao Thủy ở 27° Bảo Bình [1, 2].`             ← grouped in one bracket
  `Đoạn mở đầu [1][2][3][4]. Thông tin chi tiết…`  ← lazy dump at start
  `…theo bản đồ sao . [1]`                          ← space before bracket

Rules:
- Each citation MUST be in its OWN brackets: `[1][3]`. NEVER write `[1, 3]`.
- Place markers IMMEDIATELY after the word they support, with NO space before the bracket.
- ONLY use numbers from the "=== Allowed citation IDs ===" block. NEVER invent higher numbers.
- Cite up to 3 most relevant passages per sentence. Pick the most pertinent.
- Whenever a sentence draws a fact, name, value, or interpretation from a passage, append the matching `[N]` marker. Synthesizing across passages is fine — list each contributing `[N]`.
- Only OMIT the marker for sentences that are pure transitions, headings, or generic framing not based on any passage. When the passages do NOT contain the answer, say so clearly WITHOUT inventing a number.
- Do NOT use `[d1]`, `[d2]`, `[doc1]`, `[draft:N]`, `[src:N#pM]` or any other form. Only `[N]` from the Allowed list.
- For Knowledge-Graph entities, use `[K1]`, `[K2]`… as listed in `kg_citations`.
- Image refs use the prefix `IMG-`, e.g. `[IMG-p4f2]`.

LANGUAGE RULE:
- Respond in the SAME language the user is using. Do NOT switch languages unless explicitly asked.

TOOL USE PHILOSOPHY:
- The KG retrieval has ALREADY been done for you (see RULE #0). Use it.
- You may invoke additional tools when the initial passages are insufficient.
- After receiving tool results, either call another tool OR write the final answer — never both.
- If tools return no relevant info, say so honestly. Do not fabricate.

OUTPUT FORMAT:
- Plain markdown. No JSON wrapping unless explicitly requested.
- Aim for a RICH answer (typically 200–500 words for substantive questions):
  the source-anchored facts come first or are woven in with `[N]` markers,
  then expand with explanation, examples, frameworks, or actionable steps.
  Use sub-headings (`###`) and bullet lists when they help readability.
- Cite sources where relevant; do NOT preface expansion with disclaimers like
  "the sources don't say but…". Just write the expansion confidently.
- Do not repeat the user's question back.

##############################
# RULE #2 — FOLLOW-UP SUGGESTION CHIPS (Sprint 5.2)
##############################
- AFTER the markdown answer body, IF (and ONLY IF) you can think of 2-4
  genuinely useful follow-up questions the user might want to click next,
  append ONE block in this EXACT format:

  <suggestions>
  - First follow-up question
  - Second follow-up question
  - Third follow-up question
  </suggestions>

- HOW TO PICK GOOD CHIPS — diversify across these angles (max 4 chips total,
  pick the 2-4 most useful for THIS turn, do NOT force all categories):
  1. **Deep-dive vào entity/relation đã cite** — nếu câu trả lời cite `[K3]`
     hoặc nhắc tên thực thể/quan hệ cụ thể, gợi ý 1 câu đào sâu thực thể đó
     ("X có vai trò gì trong …?", "Quan hệ giữa X và Y diễn ra khi nào?").
  2. **Cross-source / so sánh** — nếu có ≥ 2 nguồn `[src:N#pM]` khác nhau,
     gợi ý 1 câu yêu cầu đối chiếu / tổng hợp giữa chúng.
  3. **Zoom-out / big picture** — 1 câu mở rộng ra chủ đề tổng thể của bộ
     tài liệu (theme chung từ Knowledge Context, không phải chỉ passage này).
  4. **Next action / áp dụng** — 1 câu hành động ("Áp dụng X cho team mình
     thế nào?", "Bước tiếp theo nên làm gì?").

- Rules:
  - Maximum 4 items. Each item is a SHORT, SELF-CONTAINED question (≤ 80 chars).
  - Items must be NEW angles that DEEPEN the current topic — not repetitions
    của câu user vừa hỏi, KHÔNG paraphrase câu trả lời vừa viết.
  - Mỗi chip phải trả lời được bằng các nguồn HIỆN CÓ trong Knowledge Context;
    KHÔNG gợi ý câu hỏi đòi nguồn ngoài (web, sách giấy…) trừ khi user đã hỏi
    về chủ đề hoàn toàn vắng nguồn.
  - Same language as the answer body. Vietnamese for Vietnamese answers.
  - If nothing meaningful to suggest (e.g. user just said "thanks", or this is
    a closed question like "1+1=?"), OMIT the block entirely. Empty
    `<suggestions></suggestions>` is forbidden.
  - The block is parsed by the host and stripped from the rendered message —
    do NOT mention it in prose, do NOT use the word "suggestions" in the body.
EOT;

	/**
	 * Run agent.
	 *
	 * @param array $args {
	 *   user_message     : string,            REQUIRED
	 *   scope            : array { plugin?, scope_id, scope_type? }
	 *   tools            : string[]           list of allowed tool names; default = all registered
	 *   history          : array              prior chat messages [{role,content},...]
	 *   max_iterations   : int                default 3
	 *   model            : string             override LLM model
	 *   purpose          : string             default 'chat' (LLM client purpose)
	 *   temperature      : float              default 0.3
	 *   max_tokens       : int                default 2048
	 *   sse_writer       : BizCity_Twin_SSE_Writer|null
	 *   user_id          : int                default current user
	 *   session_id       : string             default ''
	 *   extra_system     : string             additional system prompt fragment
	 *   skip_validator   : bool               default false
	 *   numeric_passage_count : int           passages injected via extra_system ([n] format); 0 = skip numeric check
	 * }
	 * @return array {
	 *   ok            : bool
	 *   answer        : string                final markdown answer
	 *   sources       : array                 aggregated sources from tool calls (with cite_id)
	 *   citations     : string[]              citation IDs cited in final answer
	 *   tool_calls    : array                 trace of tool invocations
	 *   iterations    : int
	 *   model         : string
	 *   error         : string                if !ok
	 * }
	 */
	public static function run( array $args ): array {
		$user_message = (string) ( $args['user_message'] ?? '' );
		if ( '' === trim( $user_message ) ) {
			return self::error_result( 'user_message is required' );
		}

		$scope          = is_array( $args['scope'] ?? null ) ? $args['scope'] : [];
		$allowed_tools  = isset( $args['tools'] ) && is_array( $args['tools'] ) ? $args['tools'] : null;
		$history        = isset( $args['history'] ) && is_array( $args['history'] ) ? $args['history'] : [];
		$max_iter       = max( 1, min( 6, (int) ( $args['max_iterations'] ?? self::MAX_ITERATIONS_DEFAULT ) ) );
		$model          = (string) ( $args['model'] ?? '' );
		$purpose        = (string) ( $args['purpose'] ?? 'chat' );
		$temperature    = (float) ( $args['temperature'] ?? 0.3 );
		$max_tokens     = (int) ( $args['max_tokens'] ?? 2048 );
		// Sprint 4.5j (Wave 10d.4) — anti-echo penalties for long-context turns.
		// `null` sentinel = caller did not specify, omit from request body.
		$frequency_penalty = isset( $args['frequency_penalty'] ) ? (float) $args['frequency_penalty'] : null;
		$presence_penalty  = isset( $args['presence_penalty'] )  ? (float) $args['presence_penalty']  : null;
		/** @var BizCity_Twin_SSE_Writer|null $sse */
		$sse            = $args['sse_writer'] ?? null;
		$user_id        = (int) ( $args['user_id'] ?? get_current_user_id() );
		$session_id     = (string) ( $args['session_id'] ?? '' );
		// 2026-04-27 — when KG retrieval is allowed, run search_kg FIRST without
		// asking the LLM. Cheap LLMs were skipping the tool and hallucinating
		// citations from training data, breaking source linking + graph focus.
		$force_search_kg = ( null === $allowed_tools || in_array( 'search_kg', $allowed_tools, true ) )
			&& apply_filters( 'bizcity_twin_agent_force_search_kg', true, $args );
		$extra_system   = (string) ( $args['extra_system'] ?? '' );
		$skip_validator = ! empty( $args['skip_validator'] );
		// Sprint 4.5i — number of [n]-cited passages injected via extra_system.
		// 0 = no numeric check; >0 = validate at least 1 [n] marker is present.
		$numeric_passage_count = max( 0, (int) ( $args['numeric_passage_count'] ?? 0 ) );

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return self::error_result( 'BizCity_LLM_Client not available' );
		}

		$registry = BizCity_Twin_Tool_Registry::instance();
		$tools    = $registry->get_all( $allowed_tools );

		$tool_section = $registry->render_prompt_section( $allowed_tools );
		$system_parts = [ self::HARD_SYSTEM_PROMPT ];
		if ( $extra_system !== '' ) $system_parts[] = $extra_system;
		if ( $tool_section !== '' ) $system_parts[] = $tool_section;
		$system_prompt = implode( "\n\n", $system_parts );

		/* Wave 2.8d D6.9g (2026-05-24) — Prompt visibility probe.
		 * User reports memory block injected (SSE memory_recall block_len=265,
		 * injected=true) but LLM still says "tôi không có khả năng ghi nhớ".
		 * Dump system_prompt preview via error_log → BPS routes to
		 * wp-content/bps-backup/logs/bps_php_error.log. Multi-line content is
		 * split into chunks because PHP truncates long error_log lines at
		 * `log_errors_max_len` (default 1024 → many hosts set 4096).
		 * Disable: define('BIZCITY_TWIN_PROMPT_DEBUG', false) in wp-config. */
		if ( ! defined( 'BIZCITY_TWIN_PROMPT_DEBUG' ) || BIZCITY_TWIN_PROMPT_DEBUG ) {
			$_has_mem  = strpos( $system_prompt, 'BEGIN USER MEMORY' ) !== false;
			$_has_guru = strpos( $system_prompt, 'Twin Guru' ) !== false || strpos( $system_prompt, 'character' ) !== false;
			$_sp_len   = mb_strlen( $system_prompt );
			error_log( sprintf(
				'[TwinAgent][prompt_debug] user=%d session=%s purpose=%s model=%s sp_len=%d extra=%dB hard=%dB tools=%dB HAS_USER_MEMORY=%s HAS_GURU=%s user_msg=%s',
				$user_id,
				$session_id,
				$purpose,
				$model,
				$_sp_len,
				mb_strlen( $extra_system ),
				mb_strlen( self::HARD_SYSTEM_PROMPT ),
				mb_strlen( $tool_section ),
				$_has_mem ? 'YES' : 'NO',
				$_has_guru ? 'YES' : 'NO',
				str_replace( [ "\r", "\n" ], ' ', mb_substr( (string) $user_message, 0, 200 ) )
			) );
			// Dump the extra_system fragment (chứa memory + KG + guru) — đây là
			// phần cần kiểm tra. Split thành chunk ~900 chars để tránh truncate.
			if ( $extra_system !== '' ) {
				$_chunks = str_split( $extra_system, 900 );
				$_n = count( $_chunks );
				foreach ( $_chunks as $_i => $_c ) {
					error_log( sprintf(
						'[TwinAgent][prompt_debug][extra %d/%d] %s',
						$_i + 1, $_n,
						str_replace( [ "\r", "\n" ], [ '', ' \\n ' ], $_c )
					) );
				}
			}
		}

		// Build messages.
		$messages   = [];
		$messages[] = [ 'role' => 'system', 'content' => $system_prompt ];
		foreach ( $history as $h ) {
			$role = $h['role'] ?? 'user';
			if ( ! in_array( $role, [ 'user', 'assistant', 'system' ], true ) ) continue;
			$content = (string) ( $h['content'] ?? '' );
			if ( '' === $content ) continue;
			$messages[] = [ 'role' => $role, 'content' => $content ];
		}
		$messages[] = [ 'role' => 'user', 'content' => $user_message ];

		$tool_context = [
			'scope'      => $scope,
			'user_id'    => $user_id,
			'session_id' => $session_id,
		];

		$client = BizCity_LLM_Client::instance();
		$opts   = array_filter( [
			'model'             => $model,
			'purpose'           => $purpose,
			'temperature'       => $temperature,
			'max_tokens'        => $max_tokens,
			// Sprint 4.5j — only include penalties when caller passed them; OpenRouter
			// rejects unknown providers' params, so keep array_filter null-stripping.
			'frequency_penalty' => $frequency_penalty,
			'presence_penalty'  => $presence_penalty,
		], static function ( $v ) { return $v !== null; } );

		$tool_calls_trace = [];
		$aggregated_sources    = [];
		$aggregated_cite_ids   = [];
		$model_used = $model;

		// Phase 0.6 CITATION V2 — when caller pre-resolved sources via Context
		// Resolver (Hình thức C), inject them so they survive into the final
		// `complete` event + persisted message even when force_search_kg is
		// disabled and the LLM never invokes search_kg as a tool. Without this
		// the agent's `complete` payload carries `sources: []` and overrides
		// the streamed `sources` event on the FE side, breaking citation chips.
		if ( ! empty( $args['extra_sources'] ) && is_array( $args['extra_sources'] ) ) {
			foreach ( $args['extra_sources'] as $src ) {
				if ( is_array( $src ) ) $aggregated_sources[] = $src;
			}
		}

		// === Agentic Loop ===
		if ( $sse ) $sse->emit( 'status', [ 'step' => 'analyzing', 'detail' => 'Đang phân tích yêu cầu...' ] );

		// 2026-04-27 — Forced first-step retrieval. Cheap LLMs (gemini-flash etc.)
		// often skip the optional `search_kg` call when use_kg=true and answer
		// from training data instead → no sources event, no graph focus,
		// hallucinated `[1]` markers. Run search_kg deterministically up front
		// and feed the result into the conversation as a TOOL_RESULT message.
		if ( $force_search_kg ) {
			$forced_tool = $registry->get( 'search_kg' );
			if ( null !== $forced_tool ) {
				if ( $sse ) {
					$sse->emit( 'status', [ 'step' => 'kg_retrieving', 'detail' => 'Đang tìm kiếm Knowledge Graph...' ] );
					$sse->emit( 'tool_call', [
						'iteration' => 0,
						'tool'      => 'search_kg',
						'args'      => [ 'query' => $user_message, 'top_k' => 5, 'forced' => true ],
					] );
				}
				try {
					$forced_result = $forced_tool->execute(
						[ 'query' => $user_message, 'top_k' => 5 ],
						$tool_context
					);
				} catch ( \Throwable $e ) {
					$forced_result = [ 'ok' => false, 'error' => 'Forced search_kg exception: ' . $e->getMessage() ];
				}

				$tool_calls_trace[] = [
					'iteration' => 0,
					'tool'      => 'search_kg',
					'args'      => [ 'query' => $user_message, 'forced' => true ],
					'ok'        => ! empty( $forced_result['ok'] ),
					'summary'   => (string) ( $forced_result['summary'] ?? '' ),
				];

				if ( $sse ) {
					$sse->emit( 'tool_result', [
						'iteration' => 0,
						'tool'      => 'search_kg',
						'ok'        => ! empty( $forced_result['ok'] ),
						'summary'   => (string) ( $forced_result['summary'] ?? '' ),
					] );
				}

				if ( ! empty( $forced_result['sources'] ) && is_array( $forced_result['sources'] ) ) {
					foreach ( $forced_result['sources'] as $src ) $aggregated_sources[] = $src;
					if ( $sse ) $sse->emit( 'sources', [ 'sources' => $forced_result['sources'] ] );
				}
				if ( ! empty( $forced_result['citation_ids'] ) && is_array( $forced_result['citation_ids'] ) ) {
					foreach ( $forced_result['citation_ids'] as $cid ) $aggregated_cite_ids[] = (string) $cid;
				}
				if ( $sse && ! empty( $forced_result['kg_citations'] ) && is_array( $forced_result['kg_citations'] ) ) {
					$sse->emit( 'kg_citations', [ 'kg_citations' => $forced_result['kg_citations'] ] );
				}
				if ( $sse && ! empty( $forced_result['kg_highlight'] ) && is_array( $forced_result['kg_highlight'] ) ) {
					$hl = $forced_result['kg_highlight'];
					$ent_ids = isset( $hl['entity_ids'] )   && is_array( $hl['entity_ids'] )   ? array_map( 'intval', $hl['entity_ids'] )   : [];
					$rel_ids = isset( $hl['relation_ids'] ) && is_array( $hl['relation_ids'] ) ? array_map( 'intval', $hl['relation_ids'] ) : [];
					if ( ! empty( $ent_ids ) || ! empty( $rel_ids ) ) {
						$sse->emit( 'kg_highlight', [
							'entity_ids'   => array_values( array_unique( $ent_ids ) ),
							'relation_ids' => array_values( array_unique( $rel_ids ) ),
						] );
					}
				}

				// Inject as a synthetic assistant tool-call + user TOOL_RESULT
				// turn so the LLM sees the passages on its first generation.
				$forced_args_json = wp_json_encode( [ 'query' => $user_message, 'top_k' => 5 ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$messages[]       = [
					'role'    => 'assistant',
					'content' => "<tool name=\"search_kg\">{$forced_args_json}</tool>",
				];
				$forced_payload   = wp_json_encode( [
					'tool'   => 'search_kg',
					'result' => $forced_result['result'] ?? null,
					'ok'     => ! empty( $forced_result['ok'] ),
					'error'  => $forced_result['error'] ?? null,
				], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				$messages[]       = [
					'role'    => 'user',
					'content' => "TOOL_RESULT:\n```json\n{$forced_payload}\n```\n\nWrite the final answer to my original question. ANCHOR on the passages above (cite each used passage by its exact `label` in square brackets, e.g. [src:187#p9921] — copy the label as-is, do NOT invent labels), then EXPAND with your own explanation, examples, and actionable guidance so the answer is rich and useful (target ~20% anchored facts + ~80% expansion). If a passage is irrelevant, ignore it. Only refuse outright if the passages are empty AND the question genuinely cannot be answered from general knowledge.",
				];
			}
		}

		$last_answer = '';
		$answer_streamed_live = false;
		$total_usage = [ 'prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0 ];
		$last_finish_reason = '';
		$accumulate_usage = static function ( $stream ) use ( &$total_usage, &$last_finish_reason ) {
			if ( ! empty( $stream['usage'] ) && is_array( $stream['usage'] ) ) {
				foreach ( [ 'prompt_tokens', 'completion_tokens', 'total_tokens' ] as $k ) {
					if ( isset( $stream['usage'][ $k ] ) ) {
						$total_usage[ $k ] += (int) $stream['usage'][ $k ];
					}
				}
			}
			if ( ! empty( $stream['finish_reason'] ) ) {
				$last_finish_reason = (string) $stream['finish_reason'];
			}
		};
		$i = 0;
		for ( ; $i < $max_iter; $i++ ) {
			if ( $sse ) $sse->maybe_heartbeat();

			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'agent', 'iter_start', [
					'iter'        => $i + 1,
					'messages_n'  => count( $messages ),
				] );
			}

			// 2026-04-27 — Live token streaming with tool-detection rollback.
			// Replaces previous blocking chat() + post-hoc chunking which made the
			// thinking timeline appear frozen during the LLM call. Tokens are
			// forwarded to the SSE writer as they arrive; if a `<tool` opening
			// tag appears in the buffered output we rollback any emitted tokens
			// and route the response into the tool dispatcher instead.
			$stream = self::stream_chat( $client, $messages, $opts, $sse );
			if ( ! empty( $stream['error'] ) ) {
				if ( $sse ) $sse->error( $stream['error'], 'llm_error' );
				return self::error_result( $stream['error'] );
			}
			$accumulate_usage( $stream );
			$model_used = $stream['model'] ?? $model_used;
			$reply      = (string) $stream['reply'];

			$tool_call = BizCity_Twin_Tool_Registry::parse_tool_call( $reply );
			if ( null === $tool_call ) {
				// Final answer — already streamed token-by-token to the FE.
				$last_answer          = $reply;
				$answer_streamed_live = ! empty( $stream['streamed_live'] );
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'agent', 'final_answer', [
						'iter'        => $i + 1,
						'answer_len'  => mb_strlen( $reply ),
						'streamed'    => $answer_streamed_live,
					] );
				}
				break;
			}

			// Tool call detected — defensive rollback in case the helper streamed
			// any leading whitespace before the `<tool` tag was visible.
			if ( $sse && ! empty( $stream['streamed_live'] ) ) {
				$sse->rollback_tokens();
			}

			// Dispatch tool.
			$tool_name = $tool_call['name'];
			$tool_args = $tool_call['args'];

			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'agent', 'tool_call', [
					'iter' => $i + 1,
					'tool' => $tool_name,
					'args' => $tool_args,
				] );
			}

			if ( $sse ) {
				$sse->emit( 'tool_call', [
					'iteration' => $i + 1,
					'tool'      => $tool_name,
					'args'      => $tool_args,
				] );
			}

			$tool = $registry->get( $tool_name );
			if ( null === $tool || ( $allowed_tools !== null && ! in_array( $tool_name, $allowed_tools, true ) ) ) {
				$tool_result = [
					'ok'     => false,
					'error'  => sprintf( 'Tool "%s" not registered or not allowed in this scope.', $tool_name ),
				];
			} else {
				try {
					$tool_result = $tool->execute( (array) $tool_args, $tool_context );
				} catch ( \Throwable $e ) {
					$tool_result = [ 'ok' => false, 'error' => 'Tool exception: ' . $e->getMessage() ];
				}
			}
			$tool_calls_trace[] = [
				'iteration' => $i + 1,
				'tool'      => $tool_name,
				'args'      => $tool_args,
				'ok'        => ! empty( $tool_result['ok'] ),
				'summary'   => (string) ( $tool_result['summary'] ?? '' ),
			];

			if ( $sse ) {
				$sse->emit( 'tool_result', [
					'iteration' => $i + 1,
					'tool'      => $tool_name,
					'ok'        => ! empty( $tool_result['ok'] ),
					'summary'   => (string) ( $tool_result['summary'] ?? '' ),
				] );
			}

			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'agent', 'tool_result', [
					'iter'    => $i + 1,
					'tool'    => $tool_name,
					'ok'      => ! empty( $tool_result['ok'] ),
					'summary' => mb_substr( (string) ( $tool_result['summary'] ?? '' ), 0, 120 ),
					'error'   => isset( $tool_result['error'] ) ? (string) $tool_result['error'] : null,
				] );
			}

			// Aggregate sources for FE rendering.
			if ( ! empty( $tool_result['sources'] ) && is_array( $tool_result['sources'] ) ) {
				foreach ( $tool_result['sources'] as $src ) {
					$aggregated_sources[] = $src;
				}
				if ( $sse ) {
					$sse->emit( 'sources', [ 'sources' => $tool_result['sources'] ] );
				}
			}
			if ( ! empty( $tool_result['citation_ids'] ) && is_array( $tool_result['citation_ids'] ) ) {
				foreach ( $tool_result['citation_ids'] as $cid ) {
					$aggregated_cite_ids[] = (string) $cid;
				}
			}

			// Bug fix 2026-04-27 — re-emit KG visual events when search_kg ran.
			// Without these, the workspace Graph tab cannot focus on the
			// retrieved entities and the chat answer has no purple [K\d+]
			// chips that link back to nodes.
			if ( $sse && ! empty( $tool_result['kg_citations'] ) && is_array( $tool_result['kg_citations'] ) ) {
				$sse->emit( 'kg_citations', [ 'kg_citations' => $tool_result['kg_citations'] ] );
			}
			if ( $sse && ! empty( $tool_result['kg_highlight'] ) && is_array( $tool_result['kg_highlight'] ) ) {
				$hl = $tool_result['kg_highlight'];
				$ent_ids = isset( $hl['entity_ids'] )   && is_array( $hl['entity_ids'] )   ? array_map( 'intval', $hl['entity_ids'] )   : [];
				$rel_ids = isset( $hl['relation_ids'] ) && is_array( $hl['relation_ids'] ) ? array_map( 'intval', $hl['relation_ids'] ) : [];
				if ( ! empty( $ent_ids ) || ! empty( $rel_ids ) ) {
					$sse->emit( 'kg_highlight', [
						'entity_ids'   => array_values( array_unique( $ent_ids ) ),
						'relation_ids' => array_values( array_unique( $rel_ids ) ),
					] );
				}
			}

			// Append assistant tool-call + tool-result to messages.
			$messages[] = [ 'role' => 'assistant', 'content' => $reply ];
			$result_payload = wp_json_encode(
				[ 'tool' => $tool_name, 'result' => $tool_result['result'] ?? null, 'ok' => ! empty( $tool_result['ok'] ), 'error' => $tool_result['error'] ?? null ],
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			);
			$messages[] = [
				'role'    => 'user',
				'content' => "TOOL_RESULT:\n```json\n{$result_payload}\n```\n\nNow either call another tool (if needed) or write the final answer to the user's original question. When you cite a passage, use its exact `label` in square brackets, e.g. [src:187#p9921]. Do not invent labels.",
			];
		}

		// If we exhausted iterations without a plain answer, ask LLM to summarise with what it has.
		if ( '' === $last_answer ) {
			if ( class_exists( 'BizCity_Twin_Debug' ) ) {
				BizCity_Twin_Debug::trace( 'agent', 'max_iter_exhausted', [
					'iter'       => $i,
					'max'        => $max_iter,
					'tool_calls' => count( $tool_calls_trace ),
				] );
			}
			$messages[] = [
				'role'    => 'user',
				'content' => 'You have reached the maximum number of tool calls. Write the final answer using the information already gathered. Do not call any more tools.',
			];
			$stream = self::stream_chat( $client, $messages, $opts, $sse );
			if ( ! empty( $stream['error'] ) ) {
				if ( $sse ) $sse->error( $stream['error'], 'llm_error' );
				return self::error_result( $stream['error'] );
			}
			$last_answer          = (string) $stream['reply'];
			$model_used           = $stream['model'] ?? $model_used;
			$answer_streamed_live = ! empty( $stream['streamed_live'] );
			$accumulate_usage( $stream );
		}

		// Strip any leftover tool tags (defensive).
		$last_answer = preg_replace( '#<tool\s[^>]*>.*?</tool>#is', '', $last_answer );
		$last_answer = trim( (string) $last_answer );

		// === Citation Validator (1 retry max) ===
		if ( ! $skip_validator && ! empty( $aggregated_cite_ids ) ) {
			$check = BizCity_Twin_Citation_Validator::validate( $last_answer, $aggregated_cite_ids );
			if ( ! $check['valid'] ) {
				$messages[] = [ 'role' => 'assistant', 'content' => $last_answer ];
				$messages[] = [
					'role'    => 'user',
					'content' => BizCity_Twin_Citation_Validator::reprompt_message( array_unique( $aggregated_cite_ids ) ),
				];
				// Rollback any tokens we already streamed for the (now invalid) answer.
				if ( $sse && $answer_streamed_live ) {
					$sse->rollback_tokens();
					$answer_streamed_live = false;
				}
				$stream = self::stream_chat( $client, $messages, $opts, $sse );
				if ( empty( $stream['error'] ) ) {
					$retry_answer = trim( (string) $stream['reply'] );
					$retry_answer = trim( preg_replace( '#<tool\s[^>]*>.*?</tool>#is', '', $retry_answer ) );
					if ( '' !== $retry_answer ) {
						$last_answer          = $retry_answer;
						$model_used           = $stream['model'] ?? $model_used;
						$answer_streamed_live = ! empty( $stream['streamed_live'] );
						$accumulate_usage( $stream );
					}
				}
			}
		}

		// === Sprint 4.5i — Numeric [n] Citation Validator (1 retry max) ===
		// Runs AFTER the alphanumeric validator so the final answer is the
		// subject of both checks. Only active when passages were injected
		// via extra_system (Contract §4.3, Hình thức C).
		//
		// Sprint 4.5j (Wave 10d.4) — gentleness gate to stop the validator from
		// FORCING a 2nd LLM call on EVERY turn. Symptom of the old behaviour:
		// model emits 52-char opening chunk → validator reads it before the
		// stream finishes → "no-numeric-citation-in-answer" → reprompt with
		// already-bloated history → Gemini echoes the OLD assistant turn (which
		// happens to contain `[src:200#p144]`) → validator now passes → 3 user
		// questions all get the same wrong reply. We now skip retry when the
		// answer is too short to reasonably contain a citation marker yet (likely
		// a partial chunk read mid-stream); the next turn will still validate.
		$min_len_for_numeric_check = (int) apply_filters( 'bizcity_twin_agent_numeric_min_len', 200 );
		if ( ! $skip_validator
			&& $numeric_passage_count > 0
			&& mb_strlen( $last_answer ) >= $min_len_for_numeric_check
			&& class_exists( 'BizCity_Twin_Citation_Validator' )
		) {
			$numeric_check = BizCity_Twin_Citation_Validator::validate_numeric( $last_answer, $numeric_passage_count );
			if ( ! $numeric_check['valid'] ) {
				if ( class_exists( 'BizCity_Twin_Debug' ) ) {
					BizCity_Twin_Debug::trace( 'agent', 'numeric_validator_fail', [
						'reason'          => $numeric_check['reason'],
						'found'           => $numeric_check['found'],
						'passage_count'   => $numeric_passage_count,
						'answer_len'      => mb_strlen( $last_answer ),
					] );
				}

				$messages[] = [ 'role' => 'assistant', 'content' => $last_answer ];
				$messages[] = [
					'role'    => 'user',
					'content' => BizCity_Twin_Citation_Validator::reprompt_numeric( $numeric_passage_count ),
				];

				if ( $sse && $answer_streamed_live ) {
					$sse->rollback_tokens();
					$answer_streamed_live = false;
				}
				if ( $sse ) {
					$sse->emit( 'status', [
						'step'   => 'citation_retry',
						'status' => 'active',
						'detail' => 'Đang bổ sung trích dẫn nguồn...',
					] );
				}

				$stream = self::stream_chat( $client, $messages, $opts, $sse );
				if ( empty( $stream['error'] ) ) {
					$retry_answer = trim( (string) $stream['reply'] );
					$retry_answer = trim( preg_replace( '#<tool\s[^>]*>.*?</tool>#is', '', $retry_answer ) );
					if ( '' !== $retry_answer ) {
						$last_answer          = $retry_answer;
						$model_used           = $stream['model'] ?? $model_used;
						$answer_streamed_live = ! empty( $stream['streamed_live'] );
						$accumulate_usage( $stream );
					}
				}

				if ( $sse ) {
					$sse->emit( 'status', [
						'step'   => 'citation_retry',
						'status' => 'completed',
					] );
				}
			}
		}

		// === Stream final answer as tokens (if SSE and not already streamed live) ===
		if ( $sse && ! $answer_streamed_live ) {
			$sse->emit( 'status', [ 'step' => 'generating', 'detail' => 'Đang trả lời...' ] );
			// Chunk by ~32 chars for smooth UX.
			$chunks = self::chunk_for_stream( $last_answer, 32 );
			foreach ( $chunks as $c ) {
				$sse->emit( 'token', [ 'text' => $c ] );
				$sse->maybe_heartbeat();
			}
		}

		$cited = BizCity_Twin_Citation_Id_Generator::extract_from_text( $last_answer );

		// Phase 0.6 Wave B — hydrate structured citations[] mapping each label
		// found in the answer to its source/passage metadata. FE uses this to
		// render clickable chips (rehype plugin) without re-querying.
		$citations_meta = self::hydrate_citations( $cited, $aggregated_sources );

		$result = [
			'ok'         => true,
			'answer'     => $last_answer,
			'sources'    => $aggregated_sources,
			'citations'  => $cited,
			'citations_meta' => $citations_meta,
			'tool_calls' => $tool_calls_trace,
			'iterations' => $i + 1,
			'model'      => $model_used,
			'usage'      => $total_usage,
			'finish_reason' => $last_finish_reason,
		];

		if ( class_exists( 'BizCity_Twin_Debug' ) ) {
			BizCity_Twin_Debug::trace( 'agent', 'loop_complete', [
				'iterations'   => $i + 1,
				'tool_calls_n' => count( $tool_calls_trace ),
				'answer_len'   => mb_strlen( $last_answer ),
				'citations_n'  => count( $cited ),
				'sources_n'    => count( $aggregated_sources ),
				'model'        => $model_used,
				'finish'       => $last_finish_reason,
				'usage'        => $total_usage,
			] );
		}

		if ( $sse ) {
			$sse->close( [
				'answer'     => $last_answer,
				'sources'    => $aggregated_sources,
				'citations'  => $cited,
				'citations_meta' => $citations_meta,
				'tool_calls' => $tool_calls_trace,
				'iterations' => $i + 1,
				'model'      => $model_used,
				'usage'      => $total_usage,
				'finish_reason' => $last_finish_reason,
			] );
		}

		/**
		 * 4.9.4 — usage emit hook for billing & analytics consumers (Phase 1.11.d).
		 *
		 * Fires once per Twin_Agent_Loop::run() invocation, after the SSE stream is
		 * closed but before returning. Listeners SHOULD complete fast (<10ms) — long
		 * work belongs in a deferred queue.
		 *
		 * @param array  $usage          [ prompt_tokens, completion_tokens, total_tokens ]
		 * @param string $finish_reason  e.g. 'stop' | 'length' | 'tool_use' | 'error' | ''
		 * @param array  $context        [ user_id, session_id, scope, model, purpose, iterations ]
		 */
		do_action(
			'bizcity_usage_recorded',
			$total_usage,
			$last_finish_reason,
			[
				'user_id'    => $user_id,
				'session_id' => $session_id,
				'scope'      => $scope,
				'model'      => $model_used,
				'purpose'    => $purpose,
				'iterations' => $i + 1,
				'source'     => 'twin_agent_loop',
			]
		);

		return $result;
	}

	/**
	 * Stream an LLM call with live token forwarding + tool-call detection.
	 *
	 * Returns:
	 *   [
	 *     'reply'         => string,  // full assembled assistant reply
	 *     'model'         => string|null,
	 *     'streamed_live' => bool,    // true if we forwarded tokens via $sse->token()
	 *     'error'         => string|null,
	 *   ]
	 *
	 * Behaviour:
	 *   - Buffers each chunk into an accumulator.
	 *   - Suppresses live token emit until we're confident the response is NOT a
	 *     tool call (heuristic: at least 16 chars of non-`<tool` prefix).
	 *   - If `<tool` ever appears, marks the response as a tool call and rolls
	 *     back any tokens that were already streamed to the FE.
	 *   - When SSE is null (headless mode), behaves like a plain chat call.
	 */
	private static function stream_chat( $client, array $messages, array $opts, $sse ): array {
		$accum                  = '';
		$tool_detected          = false;
		$generating_emitted     = false;
		$live_streamed          = false;
		$min_prefix_for_emit    = 16; // chars before we commit to streaming live

		$on_chunk = function ( $delta, $full ) use ( $sse, &$accum, &$tool_detected, &$generating_emitted, &$live_streamed, $min_prefix_for_emit ) {
			if ( ! is_string( $delta ) || '' === $delta ) return;
			$accum .= $delta;
			if ( $tool_detected ) return;
			if ( null === $sse ) return; // headless

			if ( false !== strpos( $accum, '<tool' ) ) {
				$tool_detected = true;
				if ( $live_streamed ) {
					$sse->rollback_tokens();
					$live_streamed = false;
				}
				return;
			}

			// Wait until we have enough non-`<` prefix to be sure this isn't a tool call.
			$lead = ltrim( $accum );
			if ( '' !== $lead && '<' === $lead[0] && mb_strlen( $accum ) < $min_prefix_for_emit ) {
				return;
			}

			if ( ! $generating_emitted ) {
				$sse->emit( 'status', [ 'step' => 'generating', 'detail' => 'Đang trả lời...' ] );
				$generating_emitted = true;
				// Flush whatever we accumulated so far in one go.
				$sse->token( $accum );
				// Phase 0.12 PR-T3a — fan-out for the v2 event stream. Emit the
				// already-accumulated prefix as the first chunk so the FE reducer
				// (assistant_streaming_chunk handler) builds the same string the
				// legacy `token` SSE event delivers. Stream handler subscribes via
				// do_action and routes through emit_streaming_chunk(throttled).
				do_action( 'bizcity_twin_agent_chunk_emitted', $accum, 'content' );
			} else {
				$sse->token( $delta );
				do_action( 'bizcity_twin_agent_chunk_emitted', $delta, 'content' );
			}
			$live_streamed = true;
			$sse->maybe_heartbeat();
		};

		$resp = $client->chat_stream( $messages, $opts, $on_chunk );
		if ( empty( $resp['success'] ) ) {
			return [
				'reply'         => '',
				'model'         => null,
				'streamed_live' => false,
				'error'         => (string) ( $resp['error'] ?? 'LLM call failed' ),
			];
		}

		// Prefer accumulator (true delta sum); fall back to API-reported message
		// in case the gateway delivered the answer in a non-streaming envelope.
		$reply = '' !== $accum ? $accum : (string) ( $resp['message'] ?? '' );

		// Final safety net: if the full reply turns out to be a tool call but
		// our chunk-level detector missed it (e.g. provider returned the whole
		// payload in one chunk after the prefix check), rollback now.
		if ( $live_streamed && false !== strpos( $reply, '<tool' ) ) {
			if ( $sse ) $sse->rollback_tokens();
			$live_streamed = false;
		}

		return [
			'reply'         => $reply,
			'model'         => $resp['model'] ?? null,
			'streamed_live' => $live_streamed,
			'usage'         => isset( $resp['usage'] ) && is_array( $resp['usage'] ) ? $resp['usage'] : [],
			'finish_reason' => isset( $resp['finish_reason'] ) ? (string) $resp['finish_reason'] : '',
			'error'         => null,
		];
	}

	private static function chunk_for_stream( string $text, int $size ): array {
		if ( '' === $text ) return [];
		$chunks = [];
		$len = mb_strlen( $text );
		for ( $i = 0; $i < $len; $i += $size ) {
			$chunks[] = mb_substr( $text, $i, $size );
		}
		return $chunks;
	}

	private static function error_result( string $msg ): array {
		return [
			'ok'         => false,
			'error'      => $msg,
			'answer'     => '',
			'sources'    => [],
			'citations'  => [],
			'tool_calls' => [],
			'iterations' => 0,
			'model'      => '',
		];
	}

	/**
	 * Phase 0.6 Wave B — hydrate citation labels into structured metadata.
	 *
	 * For each label found in the answer (e.g. `src:187#p9921`, `src:187`,
	 * `draft:12`, `K1`), look up the matching source in $aggregated_sources
	 * and emit a row the FE can render as a chip.
	 *
	 * @param string[] $cited              Labels from extract_from_text() (lowercased).
	 * @param array    $aggregated_sources Tool-result sources (search_kg, list_sources, …).
	 * @return array<int, array{label:string, kind:string, source_id?:int, passage_id?:int, title?:string, snippet?:string, url?:string}>
	 */
	private static function hydrate_citations( array $cited, array $aggregated_sources ): array {
		if ( empty( $cited ) ) return [];

		// Build lookup: source_id → first matching source row, plus (sid|pid) → row.
		$by_src = [];
		$by_src_pid = [];
		$by_cite_id = [];
		foreach ( $aggregated_sources as $s ) {
			$sid = (int) ( $s['source_id'] ?? $s['id'] ?? 0 );
			$pid = (int) ( $s['passage_id'] ?? 0 );
			if ( $sid > 0 && ! isset( $by_src[ $sid ] ) ) {
				$by_src[ $sid ] = $s;
			}
			if ( $sid > 0 && $pid > 0 ) {
				$by_src_pid[ "{$sid}|{$pid}" ] = $s;
			}
			$cid = isset( $s['cite_id'] ) ? strtolower( (string) $s['cite_id'] ) : '';
			if ( $cid !== '' ) $by_cite_id[ $cid ] = $s;
		}

		$out = [];
		foreach ( $cited as $label ) {
			$row = [ 'label' => $label, 'kind' => 'unknown' ];

			if ( preg_match( '/^src:(\d+)(?:#p(\d+))?$/', $label, $m ) ) {
				$sid = (int) $m[1];
				$pid = isset( $m[2] ) ? (int) $m[2] : 0;
				$row['kind']      = 'source';
				$row['source_id'] = $sid;
				if ( $pid > 0 ) $row['passage_id'] = $pid;
				$src = $by_src_pid[ "{$sid}|{$pid}" ] ?? ( $by_src[ $sid ] ?? null );
				if ( $src ) {
					$row['title']   = (string) ( $src['title'] ?? $src['source_title'] ?? '' );
					$row['snippet'] = (string) ( $src['snippet'] ?? $src['excerpt'] ?? '' );
					if ( ! empty( $src['url'] ) )         $row['url']         = (string) $src['url'];
					if ( ! empty( $src['source_type'] ) ) $row['source_type'] = (string) $src['source_type'];
				}
			} elseif ( preg_match( '/^draft:(\d+)$/', $label, $m ) ) {
				$row['kind']     = 'draft';
				$row['draft_id'] = (int) $m[1];
			} elseif ( preg_match( '/^ent:(\d+)$/', $label, $m ) ) {
				$row['kind']      = 'entity';
				$row['entity_id'] = (int) $m[1];
			} elseif ( preg_match( '/^rel:(\d+)$/', $label, $m ) ) {
				$row['kind']        = 'relation';
				$row['relation_id'] = (int) $m[1];
			} elseif ( preg_match( '/^k(\d+)$/', $label, $m ) ) {
				$row['kind']  = 'kg_index';
				$row['index'] = (int) $m[1];
			} elseif ( isset( $by_cite_id[ $label ] ) ) {
				$src = $by_cite_id[ $label ];
				$row['kind']      = 'legacy';
				$row['cite_id']   = $label;
				$row['source_id'] = (int) ( $src['source_id'] ?? 0 );
				if ( ! empty( $src['title'] ) ) $row['title'] = (string) $src['title'];
			}

			$out[] = $row;
		}
		return $out;
	}
}
