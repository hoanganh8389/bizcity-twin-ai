<?php
/**
 * BizCity TwinBrain Runtime — Não tổng orchestrator entry.
 *
 * Implements the 4-stage pipeline defined in PHASE-0.36-TWINBRAIN-CENTRAL-BRAIN.md §5:
 *   1. Dual selector (notebook + tool intent, parallel)
 *   2. Parallel sub-agents (curl_multi)
 *   3. Optional tool confirmation (sync if user accepts)
 *   4. Synthesizer with locked anti-averaging prompt + citation extract
 *
 * Wave 0 (this commit): synchronous skeleton — selectors & runner are present
 * but return mock empty results until Wave 2-5 lands. Event emission contracts
 * are LIVE so FE/E2E can wire against the final shape today.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Runtime {

	const SURFACE = 'twinbrain';
	// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G5 checklist freshness threshold before compose.
	const ASTRO_CHECKLIST_FRESH_HOURS = 36;

	/** @var self|null */
	private static $instance = null;

	/**
	 * [2026-06-04 Johnny Chu] BS-12 — session_id of the turn currently being
	 * processed. Set by the entry methods (start_turn / complete_turn /
	 * complete_turn_stream) from $opts['session_id'] so emit_event() can
	 * durably persist conversational turns (user_message / assistant_message)
	 * to the event_stream tagged with the right session for replay.
	 * @var string
	 */
	private $current_session_id = '';

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Begin a brain turn. Returns a trace_id the caller can subscribe to via SSE.
	 *
	 * @param string $prompt
	 * @param array  $opts { user_id, k, force_notebooks[], force_tools[], skip_tool_intent? }
	 * @return array { ok, trace_id, sse_url, candidates, tool_candidates }
	 */
	public function start_turn( string $prompt, array $opts = [] ): array {
		$trace_id = $this->new_trace_id();
		$user_id  = isset( $opts['user_id'] ) ? (int) $opts['user_id'] : get_current_user_id();
		// [2026-06-04 Johnny Chu] BS-12 — bind session for durable turn persist.
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );
		$k        = max( 3, min( BIZCITY_TWINBRAIN_K_MAX, (int) ( $opts['k'] ?? BIZCITY_TWINBRAIN_K_DEFAULT ) ) );

		/* ============================================================
		 * PHASE-0.35 / Phase C.1 — Layer 0 token parse (R-MPRT-2).
		 * Peel leading `@guru` + `#tool` tokens before any selector runs.
		 * Caller may also pre-fill opts.guru_id / opts.tool_force.
		 * ========================================================== */
		$pre = array( 'guru_id' => 0, 'guru_label' => '', 'tool_force' => '', 'message_clean' => $prompt, 'tokens' => array() );
		if ( class_exists( 'BizCity_Guru_Token_Parser' ) ) {
			$pre = BizCity_Guru_Token_Parser::parse( $prompt );
		}
		$guru_id    = isset( $opts['guru_id'] )    ? (int) $opts['guru_id']        : (int) $pre['guru_id'];
		$tool_force = isset( $opts['tool_force'] ) ? (string) $opts['tool_force'] : (string) $pre['tool_force'];
		$prompt_eff = ( $pre['message_clean'] !== '' ) ? (string) $pre['message_clean'] : $prompt;

		/* R-MPRT-5 anti-jailbreak (AC #12): #tool with @guru must be in scope. */
		$guru_tools_whitelist = array();
		$reject_reason        = '';
		if ( $guru_id > 0 && class_exists( 'BizCity_Guru_Skill_Bridge' ) ) {
			$guru_tools_whitelist = BizCity_Guru_Skill_Bridge::tools_for_guru( $guru_id );
			if ( $tool_force !== '' && ! empty( $guru_tools_whitelist ) && ! in_array( $tool_force, $guru_tools_whitelist, true ) ) {
				$reject_reason = sprintf(
					"Tool `%s` không thuộc scope của guru `%s`. Các tool khả dụng: %s",
					$tool_force,
					$pre['guru_label'] !== '' ? $pre['guru_label'] : ( '#' . $guru_id ),
					implode( ', ', array_slice( $guru_tools_whitelist, 0, 8 ) )
				);
			}
		}

		$pre_rules_payload = array(
			'trace_id'    => $trace_id,
			'user_id'     => $user_id,
			'surface'     => self::SURFACE,
			'guru_id'     => $guru_id,
			'guru_label'  => (string) $pre['guru_label'],
			'tool_force'  => $tool_force,
			'tokens'      => (array) $pre['tokens'],
			'reject'      => $reject_reason,
		);
		$this->emit_event( 'pre_rules_done', $pre_rules_payload );

		/* PHASE-0.35 / F7.C4.1 — Devlog parity: build `guru_lookup` payload so the
		 * FE console adapter + Timeline UI can render the resolved guru context
		 * (parity with twinchat `[TwinChat][guru_lookup]`). */
		$guru_lookup_payload = array();
		if ( $guru_id > 0 ) {
			$lookup_t0 = microtime( true );
			$guru_lookup_payload = array(
				'trace_id'           => $trace_id,
				'surface'            => self::SURFACE,
				'character_id'       => $guru_id,
				'character_slug'     => (string) ( $pre['guru_slug']  ?? '' ),
				'character_name'     => (string) $pre['guru_label'],
				'l2_sources_count'   => 0, // wired when twinsearch ingest live (W4 RAG enrich)
				'l3_artifacts_count' => 0, // wired when notebook_id ctx forwarded
				'sticky_source'      => 'mention',
				'guru_tools_count'   => count( $guru_tools_whitelist ),
				'guru_tools'         => $guru_tools_whitelist,
				'tool_force'         => $tool_force,
				'latency_ms'         => (int) ( ( microtime( true ) - $lookup_t0 ) * 1000 ),
			);
			$this->emit_event( 'guru_lookup', $guru_lookup_payload );
		}

		if ( $reject_reason !== '' ) {
			return array(
				'ok'              => false,
				'trace_id'        => $trace_id,
				'error'           => 'guru_tool_out_of_scope',
				'message'         => $reject_reason,
				'guru_id'         => $guru_id,
				'tool_force'      => $tool_force,
				'guru_tools'      => $guru_tools_whitelist,
			);
		}

		$this->emit_event( 'user_message', [
			'trace_id' => $trace_id,
			'user_id'  => $user_id,
			'surface'  => self::SURFACE,
			'text'     => $prompt_eff,
			'guru_id'  => $guru_id,
		] );

		/* TBR.SEL-LEX (2026-05-22) — Tokenize prompt ONCE here, share across
		 * all downstream stages regardless of web_mode (off / quick / deep /
		 * social / company). Used by: selector keyword tier, perspective
		 * runner passage rerank, FE highlight (matched_tokens). */
		$keyword_tokens = BizCity_TwinBrain_Notebook_Selector::tokenize_for_search( $prompt_eff );
		$this->emit_event( 'brain_keywords', [
			'trace_id' => $trace_id,
			'tokens'   => $keyword_tokens,
			'count'    => count( $keyword_tokens ),
		] );

		// Stage 1A — notebook selector (parallel-conceptually; sequential in PHP).
		$selector   = BizCity_TwinBrain_Notebook_Selector::instance();
		$candidates = $selector->select( $prompt_eff, $user_id, $k, [
			'force_ids' => $opts['force_notebooks'] ?? [],
			// PHASE-0.35 / F7.D2 — forward guru context so selector can
			// prioritise notebooks bound to the active guru (character_id =
			// guru_id) before falling through cosine → density → recency.
			// Without this, Ask Brain (whole KG) ignores `@tarot` and
			// returns 5 unrelated notebooks → synthesizer says "no notebook
			// can interpret Tarot".
			'guru_id'   => $guru_id,
		] );
		$this->emit_event( 'brain_perspective_selected', [
			'trace_id'              => $trace_id,
			'k'                     => count( $candidates ),
			'candidates'            => $candidates,
			'keyword_tokens'        => $keyword_tokens,
			'prompt_embedding_hash' => substr( hash( 'sha256', $prompt_eff ), 0, 12 ),
		] );

		/* PHASE-0.35 / F7.C4.1 — Devlog parity: emit `guru_layer` summarising
		 * which knowledge layers fed this turn (mirror twinchat `[TwinChat][guru_layer]`).
		 * L1 = guru system_prompt length, L2 = guru-scoped sources (TODO when
		 * RAG enrich live), L3 = candidate notebooks selected as perspective. */
		$guru_layer_payload = array();
		if ( $guru_id > 0 ) {
			$l1_len     = 0;
			$l1_preview = '';
			global $wpdb;
			$tbl    = $wpdb->prefix . 'bizcity_characters';
			$prev   = $wpdb->suppress_errors( true );
			$prompt_text = $wpdb->get_var( $wpdb->prepare(
				"SELECT system_prompt FROM {$tbl} WHERE id = %d LIMIT 1",
				$guru_id
			) );
			$wpdb->suppress_errors( $prev );
			if ( is_string( $prompt_text ) ) {
				$l1_len     = strlen( $prompt_text );
				$l1_preview = mb_substr( $prompt_text, 0, 240 );
			}
			$guru_layer_payload = array(
				'trace_id'         => $trace_id,
				'surface'          => self::SURFACE,
				'character_id'     => $guru_id,
				'character_name'   => (string) $pre['guru_label'],
				'l1_preview_len'   => $l1_len,
				'l1_preview'       => $l1_preview,
				'l2_count'         => 0,
				'l3_count'         => count( $candidates ),
				'via'              => 'notebook_selector(scope=twin_brain)',
			);
			$this->emit_event( 'guru_layer', $guru_layer_payload );
		}

		// Stage 1B — tool intent matcher (skip if explicit @@mention or opt out).
		// Phase C: forward guru_id + merge tool_force into force_tools.
		$tool_candidates = [];
		$force_tools     = (array) ( $opts['force_tools'] ?? [] );
		if ( $tool_force !== '' ) {
			$force_tools[] = $tool_force;
		}
		$force_tools = array_values( array_unique( array_filter( array_map( 'strval', $force_tools ) ) ) );

		if ( empty( $opts['skip_tool_intent'] ) && ! $this->has_explicit_skill_mention( $prompt_eff ) ) {
			$matcher         = BizCity_TwinBrain_Tool_Intent_Matcher::instance();
			$tool_candidates = $matcher->match( $prompt_eff, $user_id, [
				'force_slugs' => $force_tools,
				'guru_id'     => $guru_id,
			] );
			$this->emit_event( 'brain_tool_intent', [
				'trace_id'        => $trace_id,
				'k'               => count( $tool_candidates ),
				'candidates'      => $tool_candidates,
				'threshold'       => BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD,
				'guru_id'         => $guru_id,
				'guru_tool_count' => count( $guru_tools_whitelist ),
			] );
		}

		/* PHASE 0.36-UNIFIED Wave 2.8 (TBR.MEM-3) — Layer 0.5 Memory Recall.
		 * Runs after Pre-rules / Guru lookup so the recall can later be
		 * scoped to the bound guru (deferred to MEM-5). Block + counts
		 * are returned in `$start['memory_*']` for REST to re-emit + for
		 * the Final_Composer (Layer 4.5) to inject into its system prompt.
		 *
		 * Failures are swallowed — never block a turn for a recall miss. */
		$memory_block      = '';
		$memory_recall_evt = [];
		$memory_citations  = [];
		if ( class_exists( 'BizCity_TwinBrain_Memory_Recall' ) ) {
			try {
				$mem_res = BizCity_TwinBrain_Memory_Recall::instance()->collect( $user_id, $prompt_eff, [
					'keyword_tokens' => $keyword_tokens,
				] );
				$memory_block     = (string) ( $mem_res['block']     ?? '' );
				$memory_citations = (array)  ( $mem_res['citations'] ?? [] );
				$memory_recall_evt = [
					'trace_id'   => $trace_id,
					'surface'    => self::SURFACE,
					'counts'     => (array) ( $mem_res['counts'] ?? [ 'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0 ] ),
					'citations'  => $memory_citations,
					'block_len'  => mb_strlen( $memory_block ),
					'latency_ms' => (int) ( $mem_res['latency_ms'] ?? 0 ),
				];
				$this->emit_event( 'memory_recall', $memory_recall_evt );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][memory_recall][error] ' . $e->getMessage() );
				}
			}
		}

		return [
			'ok'              => true,
			'trace_id'        => $trace_id,
			'sse_url'         => rest_url( 'bizcity-twin/v1/stream' ) . '?trace_id=' . rawurlencode( $trace_id ),
			'candidates'      => $candidates,
			'tool_candidates' => $tool_candidates,
			'keyword_tokens'  => $keyword_tokens,
			'guru_id'         => $guru_id,
			'guru_label'      => (string) $pre['guru_label'],
			'tool_force'      => $tool_force,
			'guru_tools'      => $guru_tools_whitelist,
			/* PHASE-0.35 / F7.C4.1 — payloads for REST to re-emit as native SSE. */
			'pre_rules'       => $pre_rules_payload,
			'guru_lookup'     => $guru_lookup_payload,
			'guru_layer'      => $guru_layer_payload,
			/* PHASE 0.36-UNIFIED Wave 2.8 — memory recall block + telemetry. */
			'memory_block'    => $memory_block,
			'memory_recall'   => $memory_recall_evt,
		];
	}

	/**
	 * Continue a turn after Stage 1 by running parallel sub-agents and synthesis.
	 * Wave 0: synchronous, no actual fan-out yet — emits skeleton events so the
	 * FE timeline reducer can be developed against the final shape.
	 */
	public function complete_turn( string $trace_id, string $prompt, array $candidates, array $tool_candidates = [], array $opts = [] ): array {
		// [2026-06-04 Johnny Chu] BS-12 — bind session for durable turn persist.
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );
		$runner       = BizCity_TwinBrain_Perspective_Runner::instance();
		$answers      = $runner->run( $trace_id, $prompt, $candidates, $opts );

		/* PHASE-0.35 / F7.C4 — Layer 5 Tool_Decision (no dispatch yet; that's F7.C5). */
		$tool_decision = $this->decide_tool(
			$trace_id,
			(array) $tool_candidates,
			(string) ( $opts['tool_force'] ?? '' ),
			(int)    ( $opts['guru_id']    ?? 0 )
		);
		$this->emit_event( 'tool_decided', array_merge(
			array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ),
			$tool_decision
		) );

		/* PHASE-0.35 / F7.C5 — Layer 6 Tool_Dispatch (non-stream). */
		$dispatch     = $this->dispatch_tool( $trace_id, $tool_decision, $opts );
		$this->emit_event( 'tool_done', $this->tool_done_payload( $trace_id, $dispatch, $tool_decision ) );
		$tool_results = ! empty( $dispatch['skipped'] )
			? $this->planned_tool_results( $tool_decision )
			: $this->dispatched_tool_results( $dispatch );

		$synth     = BizCity_TwinBrain_Synthesizer::instance();
		$synth_t0  = microtime( true );
		$synthesis = $synth->synthesize( $trace_id, $prompt, $answers, $tool_results );
		$synth_ms  = (int) ( ( microtime( true ) - $synth_t0 ) * 1000 );

		// Resolve cited passages → KG entity ids so the visual panel can light
		// the matching nodes orange (R-BRAIN-1 + parity with notebook editor).
		$cited_entity_ids = $this->resolve_cited_entity_ids( $synthesis['citations'] ?? [] );

		// TBR.F5b (2026-05-13) — also resolve passage_id → source_id + snippet
		// so the FE can flip right-panel to Source tab and load the document
		// inline (no full-page redirect to notebook studio).
		// Combine `synthesis.citations[]` + every `[nb:X/pY]` token mentioned
		// inline in `answer_md` (LLM often references passages parenthetically
		// that aren't echoed in the citations array).
		$inline_pids = $this->extract_inline_passage_refs( (string) ( $synthesis['answer_md'] ?? '' ) );
		$cited_passages = $this->resolve_cited_passages( $synthesis['citations'] ?? [], $inline_pids );

		// Stage 4 telemetry — emit granular brain_synthesize before the
		// final assistant_message so admin replay can show timing & model.
		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $synthesis['model']  ?? '' ),
			'tokens'             => (int)    ( $synthesis['tokens'] ?? 0 ),
			'ms'                 => $synth_ms,
			'consensus_count'    => isset( $synthesis['consensus'] ) ? count( $synthesis['consensus'] ) : 0,
			'tensions_count'     => isset( $synthesis['tensions']  ) ? count( $synthesis['tensions']  ) : 0,
			'citation_count'     => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
			'cited_entity_count' => count( $cited_entity_ids ),
			'cited_passage_count'=> count( $cited_passages ),
			'fallback'           => (string) ( $synthesis['fallback'] ?? '' ),
			'answers_in'         => count( $answers ),
		] );

		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $synthesis['answer_md'] ?? '',
			'synthesis_metadata'   => [
				'consensus'         => $synthesis['consensus']      ?? [],
				'tensions'          => $synthesis['tensions']       ?? [],
				'recommendation'    => $synthesis['recommendation'] ?? '',
				'citations'         => $synthesis['citations']      ?? [],
				'citation_count'    => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
				'cited_entity_ids'  => $cited_entity_ids,
				'cited_passages'    => $cited_passages,
				'model'             => (string) ( $synthesis['model'] ?? '' ),
				'tokens'            => (int)    ( $synthesis['tokens'] ?? 0 ),
				'ms'                => $synth_ms,
				'fallback'          => (string) ( $synthesis['fallback'] ?? '' ),
			],
		] );

		return [
			'ok'                => true,
			'synthesis'         => $synthesis,
			'answers'           => $answers,
			'cited_entity_ids'  => $cited_entity_ids,
			'cited_passages'    => $cited_passages,
		];
	}

	/**
	 * TBR.F6-sse — streaming variant of `complete_turn()`.
	 *
	 * Same wave-0 pipeline (perspectives → synthesis → cite resolve), but
	 * progressively pushes phase events into the provided SSE writer so the
	 * FE can paint each step as soon as it lands. Returns the same shape as
	 * `complete_turn()` for callers that want the final aggregate.
	 *
	 * Phases emitted:
	 *   - perspectives_started   { count }
	 *   - perspective_done       { notebook_id, label, stance, confidence, ms, tokens }
	 *   - perspectives_done      { count, ms }
	 *   - synthesis_started      { }
	 *   - synthesis_done         { answer_md, consensus[], tensions[], citations[], tokens, ms, fallback }
	 *   - cite_resolved          { cited_entity_ids[], cited_passages[] }
	 *   - completed              { duration_ms, trace_id }
	 *
	 * NOTE: token-level streaming inside the synthesizer is deferred until the
	 * gateway adapter exposes a stream channel (PHASE-0.36 sprint .9 / TBR.F6.2).
	 */
	public function complete_turn_stream(
		string $trace_id,
		string $prompt,
		array $candidates,
		array $tool_candidates,
		BizCity_Twin_SSE_Writer $sse,
		array $opts = []
	): array {
		$wall_t0 = microtime( true );
		// [2026-06-04 Johnny Chu] BS-12 — bind session for durable turn persist
		// (covers agent / astro / degrade sub-stream modes delegated below).
		$this->current_session_id = (string) ( $opts['session_id'] ?? '' );

		/* PHASE 0.36-UNIFIED TBR.W20 (2026-05-28) — Agent ReAct mode.
		 * When REST request sets `mode=agent`, bypass the entire MPR
		 * pipeline (Perspective / Web / Tool_Decision / Synthesizer) and
		 * run a ReAct loop over Tool_Registry instead. Memory_Recall
		 * (already done in start_turn) still feeds memory_block; Memory_
		 * Writer (L4.7) runs at the end. Agent_Runner returns its own
		 * answer_md + iterations; Final_Composer is NOT used (agent
		 * outputs final answer directly from ReAct loop or force_final). */
		if ( strtolower( (string) ( $opts['mode'] ?? 'brain' ) ) === 'agent' ) {
			return $this->stream_agent_react( $trace_id, $prompt, $sse, $opts, $wall_t0 );
		}

		/* [2026-06-03 Johnny Chu] HOTFIX — Companion "chat" mode.
		 * Khi FE chọn pill `Chat` (web_mode='chat') → bỏ qua toàn bộ MPR
		 * pipeline (perspectives / web / tool / synthesizer), chỉ chạy
		 * stream_auto_degrade_chat với companion system prompt (đồng cảm,
		 * tâm sự, chỉ dùng memory_block). Khác với auto-degrade tự động
		 * (vốn yêu cầu K=0 + memory ≥ MIN bytes), chat mode là chủ đích
		 * của user nên FORCE bypass mọi điều kiện. */
		if ( strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ) === 'chat' ) {
			$opts['companion_mode'] = true;
			return $this->stream_auto_degrade_chat( $trace_id, $prompt, $sse, $opts, $wall_t0 );
		}

		// [2026-06-04 Johnny Chu] PHASE-A C.3b — Astro mode: bypass MPR pipeline,
		// resolve transit via CAP filter, compose final answer with transit context.
		if ( strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ) === 'astro' ) {
			return $this->stream_astro_mode( $trace_id, $prompt, $sse, $opts, $wall_t0 );
		}

		/* PHASE 0.36-UNIFIED TBR.W18 (2026-05-28) — Brain auto-degrade.
		 * When the turn has ZERO local notebook candidates AND zero tool
		 * candidates AND no web_mode override, but Layer 0.5 Memory_Recall
		 * surfaced a non-empty block (≥ MIN bytes), we don't have anything
		 * to "synthesize" — running Perspective + Synthesizer + Final
		 * Compose on empty input just burns 2 LLM round-trips to produce
		 * an awkward "không có nguồn tham khảo" answer. Instead, bypass
		 * directly to a chat-tone composer that uses ONLY memory_block +
		 * prompt. Skip perspectives / tool / synthesis layers entirely;
		 * Memory_Writer (L4.7) still runs at the end so user dặn còn được
		 * persist.
		 *
		 * Eligibility filter (`bizcity_twinbrain_auto_degrade_eligible`)
		 * lets 3rd-party (guru policy, A/B) opt out by returning false.
		 * MIN bytes filter (`bizcity_twinbrain_auto_degrade_min_memory_bytes`,
		 * default 120) avoids degrading on near-empty recall blocks that
		 * would produce hollow chat answers. */
		$auto_degrade_web_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );
		$auto_degrade_min      = (int) apply_filters(
			'bizcity_twinbrain_auto_degrade_min_memory_bytes',
			120,
			$opts
		);
		$auto_degrade_block    = trim( (string) ( $opts['memory_block'] ?? '' ) );
		$auto_degrade_eligible = (
			empty( $candidates )
			&& empty( $tool_candidates )
			&& $auto_degrade_web_mode === 'off'
			&& strlen( $auto_degrade_block ) >= $auto_degrade_min
		);
		$auto_degrade_eligible = (bool) apply_filters(
			'bizcity_twinbrain_auto_degrade_eligible',
			$auto_degrade_eligible,
			$trace_id,
			$prompt,
			$opts
		);
		if ( $auto_degrade_eligible ) {
			return $this->stream_auto_degrade_chat( $trace_id, $prompt, $sse, $opts, $wall_t0 );
		}

		$sse->emit( 'perspectives_started', [
			'trace_id' => $trace_id,
			'count'    => count( $candidates ),
		] );

		$persp_t0  = microtime( true );
		$runner    = BizCity_TwinBrain_Perspective_Runner::instance();
		$answers   = $runner->run( $trace_id, $prompt, $candidates, $opts );
		$persp_ms  = (int) ( ( microtime( true ) - $persp_t0 ) * 1000 );

		// Re-emit each answer individually so FE can fill timeline rows even
		// though curl_multi finishes them in a batch.
		foreach ( $answers as $a ) {
			$sse->emit( 'perspective_done', [
				'trace_id'    => $trace_id,
				'notebook_id' => (int)    ( $a['notebook_id'] ?? 0 ),
				'label'       => (string) ( $a['label']       ?? '' ),
				'stance'      => (string) ( $a['stance']      ?? '' ),
				'confidence'  => (float)  ( $a['confidence']  ?? 0 ),
				'ms'          => (int)    ( $a['ms']          ?? 0 ),
				'tokens'      => (int)    ( $a['tokens']      ?? 0 ),
				'answer_md'   => (string) ( $a['answer_md']   ?? '' ),
				'reason'      => (string) ( $a['reason']      ?? '' ),
			] );
			$sse->maybe_heartbeat();
		}

		$sse->emit( 'perspectives_done', [
			'trace_id' => $trace_id,
			'count'    => count( $answers ),
			'ms'       => $persp_ms,
		] );

		// [2026-06-04 Johnny Chu] BS-12 — snapshot the notebook-only perspective
		// rows BEFORE the optional web_row append, so session replay can render
		// the same per-notebook cards as a live turn. $web_row pre-init so it is
		// always defined (function scope) at the assistant_message snapshot site.
		$persp_snapshot = $answers;
		$web_row        = array();

		/* PHASE 0.36-UNIFIED / TBR.W9 (2026-05-21) — Stage 2.5 Web Research
		 * Fallback Layer. Dispatched only when FE composer toggle is
		 * non-default ('quick' or 'deep'). The web perspective is appended
		 * to $answers so the Synthesizer (downstream) can run CONSENSUS /
		 * TENSIONS analysis between local notebooks and web sources. SSE
		 * events (web_research_started / web_search_done / web_extract_done /
		 * web_react_step / web_synthesize_done) are emitted by the engine
		 * itself via BizCity_Twin_Event_Bus — re-emit here as native SSE so
		 * BrainThinkingTimeline reducer doesn't have to round-trip the bus. */
		$web_mode = strtolower( (string) ( $opts['web_mode'] ?? 'off' ) );

		/* TBR.W11 — Apply guru policy gate. When a guru is bound, the
		 * `allow_web_fallback` flag on `wp_bizcity_characters` decides whether
		 * the user's requested mode is honored. Default = OFF (R-TG closed
		 * scope). Filter is also exposed so 3rd-party can wire additional
		 * policy (e.g. plan tier, rate limit). */
		$guru_id_eff = (int) ( $opts['guru_id'] ?? 0 );
		$web_mode    = (string) apply_filters(
			'bizcity_twinbrain_web_mode_effective',
			$web_mode,
			$guru_id_eff,
			[ 'trace_id' => $trace_id, 'prompt' => $prompt ]
		);

		if ( $web_mode === 'quick' || $web_mode === 'deep' || $web_mode === 'social' || $web_mode === 'company' || $web_mode === 'med' || $web_mode === 'scholar' || $web_mode === 'nutri' || $web_mode === 'law' || $web_mode === 'tax' || $web_mode === 'gov' ) {
			$web_row = $this->dispatch_web_research( $trace_id, $prompt, $web_mode, $sse, $opts );
			if ( ! empty( $web_row ) ) {
				// Append as an extra perspective row so synthesizer sees it
				// alongside notebook perspectives. The synthesizer treats
				// any row with stance!='unknown' as a real perspective.
				$answers[] = $web_row;
			}
		}

		/* PHASE-0.35 / F7.C4 — Layer 5 Tool_Decision (no dispatch yet). */
		$tool_decision = $this->decide_tool(
			$trace_id,
			(array) $tool_candidates,
			(string) ( $opts['tool_force'] ?? '' ),
			(int)    ( $opts['guru_id']    ?? 0 )
		);
		$sse->emit( 'tool_decided', array_merge(
			array( 'trace_id' => $trace_id ),
			$tool_decision
		) );
		$this->emit_event( 'tool_decided', array_merge(
			array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ),
			$tool_decision
		) );

		/* PHASE-0.35 / F7.C5 — Layer 6 Tool_Dispatch (stream).
		 * Emit `tool_done` between tool_decided and synthesis_started so the
		 * Synthesizer prompt can include real tool output (vs the PLANNED
		 * placeholder) and the FE Timeline gets a Layer-6 row in real time. */
		$dispatch = $this->dispatch_tool( $trace_id, $tool_decision, $opts );
		$tool_done_payload = $this->tool_done_payload( $trace_id, $dispatch, $tool_decision );
		$sse->emit( 'tool_done', $tool_done_payload );
		$this->emit_event( 'tool_done', $tool_done_payload );
		$tool_results = ! empty( $dispatch['skipped'] )
			? $this->planned_tool_results( $tool_decision )
			: $this->dispatched_tool_results( $dispatch );

		$sse->emit( 'synthesis_started', [ 'trace_id' => $trace_id ] );

		$synth     = BizCity_TwinBrain_Synthesizer::instance();
		$synth_t0  = microtime( true );
		$synthesis = $synth->synthesize( $trace_id, $prompt, $answers, $tool_results, $opts );
		$synth_ms  = (int) ( ( microtime( true ) - $synth_t0 ) * 1000 );

		$sse->emit( 'synthesis_done', [
			'trace_id'        => $trace_id,
			'answer_md'       => (string) ( $synthesis['answer_md']      ?? '' ),
			'consensus'       => (array)  ( $synthesis['consensus']      ?? [] ),
			'tensions'        => (array)  ( $synthesis['tensions']       ?? [] ),
			'recommendation'  => (string) ( $synthesis['recommendation'] ?? '' ),
			'citations'       => (array)  ( $synthesis['citations']      ?? [] ),
			'tokens'          => (int)    ( $synthesis['tokens']         ?? 0 ),
			'ms'              => $synth_ms,
			'fallback'        => (string) ( $synthesis['fallback']       ?? '' ),
		] );

		/* PHASE 0.36-UNIFIED TBR.W17 (2026-05-21) — Layer 4.5 Final Compose.
		 * Stream final user-facing answer AFTER synthesis_done, BEFORE
		 * cite_resolved. The synthesizer answer_md remains in the timeline
		 * as the analysis card; this composer's output is what the user
		 * actually reads. Each SSE delta is bridged from chat_stream() →
		 * `final_token` event. Falls back to synthesizer.answer_md on any
		 * stream error so the UI still gets a non-blank message.
		 *
		 * R-EVT-4: piggyback on the existing single SSE channel — only
		 * NEW event types are `final_started` / `final_token` / `final_done`. */

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN A3 — Astro context injection.
		// When prompt contains astro keywords AND we are NOT already in astro mode,
		// quietly enrich $opts with astro context_md so Final Composer can cite
		// natal/report/transit facts. Emits `astro_recall_done` SSE so FE timeline
		// renders an "Astro recall" step BEFORE the synthesis/final rows.
		// Fail-open: any error is swallowed.
		$astro_recall_t0 = microtime( true );
		if (
			class_exists( 'BizCity_TwinBrain_Astro_Recall' )
			&& empty( $opts['extra_context_md'] )
			&& BizCity_TwinBrain_Astro_Recall::prompt_has_astro_intent( $prompt )
		) {
			try {
				$astro_ctx = BizCity_TwinBrain_Astro_Recall::collect_for_user(
					(int) ( $opts['user_id'] ?? get_current_user_id() ),
					$prompt
				);
				$astro_recall_ms = (int) ( ( microtime( true ) - $astro_recall_t0 ) * 1000 );
				if ( ! empty( $astro_ctx['active'] ) && $astro_ctx['context_md'] !== '' ) {
					$opts['extra_context_md']    = $astro_ctx['context_md'];
					$opts['extra_context_label'] = 'THÔNG TIN CHIÊM TINH (natal + transit) — đọc kỹ trước khi trả lời và cite bằng [astro:*] token';
				}
				// Always emit astro_recall_done (active or not) so FE timeline row renders.
				$sse->emit( 'astro_recall_done', array(
					'trace_id'   => $trace_id,
					'active'     => ! empty( $astro_ctx['active'] ),
					'coachee_id' => (int) ( $astro_ctx['coachee_id'] ?? 0 ),
					'counts'     => (array) ( $astro_ctx['counts'] ?? array() ),
					'profile'    => (array) ( $astro_ctx['profile'] ?? array() ),
					'_degraded'  => isset( $astro_ctx['_degraded'] ) ? $astro_ctx['_degraded'] : null,
					'latency_ms' => $astro_recall_ms,
					'source'     => 'recall_layer',
				) );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_recall] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
			}
		}

		$final_t0   = microtime( true );
		$final_seq  = 0;
		$final_keepalive_seq = 0;
		$sse->emit( 'final_started', [ 'trace_id' => $trace_id ] );

		$composer = BizCity_TwinBrain_Final_Composer::instance();
		// [2026-07-07 Johnny Chu] HOTFIX — emit periodic keepalive evidence so
		// FE knows BE is still composing even when no token has arrived yet.
		$final_opts = array_merge( $opts, array(
			'on_keepalive' => static function () use ( $sse, $trace_id, &$final_keepalive_seq ) {
				$final_keepalive_seq++;
				$sse->emit( 'final_keepalive', array(
					'trace_id' => $trace_id,
					'seq'      => $final_keepalive_seq,
					'status'   => 'still_running',
				) );
				$sse->maybe_heartbeat();
			},
		) );
		$final    = $composer->compose_stream(
			$trace_id,
			$prompt,
			$synthesis,
			$answers,
			$final_opts,
			static function ( string $delta, string $accumulated ) use ( $sse, $trace_id, &$final_seq ) {
				$final_seq++;
				$sse->emit( 'final_token', [
					'trace_id' => $trace_id,
					'seq'      => $final_seq,
					'delta'    => $delta,
					'len'      => mb_strlen( $accumulated ),
				] );
			}
		);
		$final_ms = (int) ( ( microtime( true ) - $final_t0 ) * 1000 );

		$final_text = (string) ( $final['answer_md'] ?? '' );

		// [2026-06-10 Johnny Chu] R-QUOTA-KEY — Brain mode quota check (same pattern as astro mode).
		// Emit SSE error BEFORE final_done so FE renders QuotaErrorBanner.
		if ( ! empty( $final['quota_exhausted'] ) ) {
			$hub_tier    = isset( $final['tier'] )          ? (string) $final['tier']         : 'free';
			$period      = isset( $final['quota_period'] )  ? (string) $final['quota_period']  : 'day';
			$period_vi   = $period === 'month' ? 'tháng' : 'ngày';
			$used_req    = isset( $final['used_requests'] )    ? (int)   $final['used_requests']    : 0;
			$cap_req     = isset( $final['cap_requests_day'] ) ? (int)   $final['cap_requests_day'] : 0;
			$used_usd    = isset( $final['used_usd'] )         ? (float) $final['used_usd']         : 0.0;
			$cap_usd     = isset( $final['cap_usd'] )          ? (float) $final['cap_usd']          : 0.0;
			$reset_at    = isset( $final['reset_at'] )         ? (string)$final['reset_at']         : '';
			$master_lvl  = isset( $final['master_level'] )     ? (string)$final['master_level']     : $hub_tier;
			$sse->emit( 'error', array(
				'code'              => 'quota_exhausted',
				'message'           => 'Hệ thống AI đã vượt giới hạn ' . $period_vi . ' (Layer 1 Hub). Liên hệ admin để nâng cấp gói.',
				'quota_exceeded'    => true,
				'hub_quota'         => true,
				'plan'              => $master_lvl,
				'plan_label'        => strtoupper( $master_lvl ) . ' (Hub)',
				'feature'           => 'llm_chat',
				'used_requests'     => $used_req,
				'cap_requests_day'  => $cap_req,
				'used_usd'          => round( $used_usd, 4 ),
				'cap_usd'           => round( $cap_usd, 4 ),
				'limit'             => $cap_usd > 0 ? $cap_usd : $cap_req,
				'used'              => $cap_usd > 0 ? $used_usd : $used_req,
				'resets_at'         => $reset_at,
				'period'            => $period,
				'hint'              => 'Liên hệ admin site để nâng cấp gói BizCity Hub.',
				'help_code'         => 'hub_quota_exhausted',
			) );
		}

		$sse->emit( 'final_done', [
			'trace_id'  => $trace_id,
			'answer_md' => $final_text,
			'tokens'    => (int)    ( $final['tokens']   ?? 0 ),
			'model'     => (string) ( $final['model']    ?? '' ),
			'ms'        => $final_ms,
			'chunks'    => $final_seq,
			'fallback'  => (string) ( $final['fallback'] ?? '' ),
			'success'   => ! empty( $final['success'] ),
		] );

		/* PHASE 0.36-UNIFIED Wave 2.8 TBR.MEM-6 (2026-05-24) — Mode 3 function-
		 * call tools. After final_done, scan $final_text for inline
		 * `<tool name="memory_*">{...}</tool>` blocks emitted by LLM, dispatch
		 * each, rewrite the answer (strip block + insert `[mem:U#<id>]` chip
		 * for memory_remember). Caps: 3 write + 5 recall per turn. Emits 3 new
		 * event types via SSE: memory_tool_call / memory_tool_result /
		 * memory_tool_error. Opt-in via filter
		 * `bizcity_twinbrain_memory_tools_enabled` (default FALSE → Final
		 * Composer omits tool schema → LLM never emits blocks → dispatcher
		 * NO-OPs in find_all_blocks scan). */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) && $final_text !== '' ) {
			try {
				$tool_disp = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->dispatch_from_text(
					$final_text,
					[
						'trace_id'   => $trace_id,
						'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'surface'    => self::SURFACE,
						'on_event'   => function ( string $event_key, array $payload ) use ( $sse ) {
							$sse->emit( $event_key, $payload );
							$this->emit_event( $event_key, $payload );
						},
					]
				);
				if ( ! empty( $tool_disp['ops'] ) ) {
					$sse->emit( 'memory_tool_summary', [
						'trace_id'   => $trace_id,
						'tool_calls' => (int) $tool_disp['tool_calls'],
						'persisted'  => (int) $tool_disp['persisted'],
						'forgotten'  => (int) $tool_disp['forgotten'],
						'recalled'   => (int) $tool_disp['recalled'],
						'skipped'    => (int) $tool_disp['skipped'],
						'errors'     => (array) $tool_disp['errors'],
						'latency_ms' => (int) $tool_disp['latency_ms'],
					] );
					// Promote rewritten text as canonical final answer.
					$rewritten = (string) $tool_disp['rewritten_text'];
					if ( $rewritten !== '' ) {
						$final_text = $rewritten;
						$final['answer_md'] = $rewritten;
					}
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][memory_tool_dispatcher][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
			}
		}

		/* PHASE 0.36-UNIFIED Wave 2.8 (TBR.MEM-7) — Layer 4.7 Memory Writer.
		 * Detect explicit "hãy nhớ ..." phrases in the *prompt* and persist
		 * them to memory_users. Idempotent per trace_id. Mode 1 only — no
		 * LLM extractor yet (deferred to MEM-5). Failures swallowed. */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					[
						'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
					]
				);
				$write_payload = [
					'trace_id'   => $trace_id,
					'persisted'  => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'       => (string) ( $mw['mode']       ?? '' ),
					'ops'        => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms' => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][memory_write][error] ' . $e->getMessage() );
				}
			}

			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — empathic mood
			// sampler. Emits brain_session_mood_sampled every Nth user turn
			// so Memory_Recall Tier F can surface the latest valence/label
			// to Final_Composer. Cheap heuristic — no LLM cost by default.
			$session_id = (string) ( $opts['session_id'] ?? '' );
			if ( $session_id !== '' && method_exists( 'BizCity_TwinBrain_Memory_Writer', 'sample_mood' ) ) {
				try {
					$turn_index = $this->count_user_turns_for_session( $session_id );
					$mood = BizCity_TwinBrain_Memory_Writer::instance()->sample_mood( [
						'trace_id'   => $trace_id,
						'session_id' => $session_id,
						'user_id'    => (int) ( $opts['user_id'] ?? get_current_user_id() ),
						'turn_index' => $turn_index,
						'prompt'     => (string) $prompt,
						'answer'     => (string) $final_text,
					] );
					if ( ( $mood['status'] ?? '' ) === 'sampled' ) {
						$mood_evt = [
							'trace_id'   => $trace_id,
							'session_id' => $session_id,
							'turn_index' => (int)    ( $mood['turn_index'] ?? $turn_index ),
							'valence'    => (float)  ( $mood['valence']    ?? 0.0 ),
							'label'      => (string) ( $mood['label']      ?? '' ),
							'event_uuid' => (string) ( $mood['event_uuid'] ?? '' ),
						];
						$sse->emit( 'mood_sampled', $mood_evt );
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TwinBrain][mood_sampled][error] ' . $e->getMessage() );
					}
				}
			}
		}

		// Promote final_text as the canonical answer_md downstream so
		// citation resolution + assistant_message use the user-visible
		// version. Synth.answer_md stays available via `synthesis_metadata`.
		if ( $final_text !== '' ) {
			$synthesis['answer_md_synth'] = (string) ( $synthesis['answer_md'] ?? '' );
			$synthesis['answer_md']       = $final_text;
		}

		$cited_entity_ids = $this->resolve_cited_entity_ids( $synthesis['citations'] ?? [] );
		$inline_pids      = $this->extract_inline_passage_refs( (string) ( $synthesis['answer_md'] ?? '' ) );
		$cited_passages   = $this->resolve_cited_passages( $synthesis['citations'] ?? [], $inline_pids );

		$sse->emit( 'cite_resolved', [
			'trace_id'         => $trace_id,
			'cited_entity_ids' => $cited_entity_ids,
			'cited_passages'   => $cited_passages,
		] );

		// Mirror complete_turn()'s telemetry so admin replay parity is preserved.
		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $synthesis['model']  ?? '' ),
			'tokens'             => (int)    ( $synthesis['tokens'] ?? 0 ),
			'ms'                 => $synth_ms,
			'consensus_count'    => isset( $synthesis['consensus'] ) ? count( $synthesis['consensus'] ) : 0,
			'tensions_count'     => isset( $synthesis['tensions']  ) ? count( $synthesis['tensions']  ) : 0,
			'citation_count'     => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
			'cited_entity_count' => count( $cited_entity_ids ),
			'cited_passage_count'=> count( $cited_passages ),
			'fallback'           => (string) ( $synthesis['fallback'] ?? '' ),
			'answers_in'         => count( $answers ),
			'streaming'          => true,
		] );
		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $synthesis['answer_md'] ?? '',
			'synthesis_metadata'   => [
				'consensus'        => $synthesis['consensus']      ?? [],
				'tensions'         => $synthesis['tensions']       ?? [],
				'recommendation'   => $synthesis['recommendation'] ?? '',
				'citations'        => $synthesis['citations']      ?? [],
				'citation_count'   => isset( $synthesis['citations'] ) ? count( $synthesis['citations'] ) : 0,
				'cited_entity_ids' => $cited_entity_ids,
				'cited_passages'   => $cited_passages,
				'model'            => (string) ( $synthesis['model'] ?? '' ),
				'tokens'           => (int)    ( $synthesis['tokens'] ?? 0 ),
				'ms'               => $synth_ms,
				'fallback'         => (string) ( $synthesis['fallback'] ?? '' ),
				'streaming'        => true,
				// TBR.W17 — preserve the structural synthesizer output even
				// after we've promoted final-compose text into answer_md.
				'answer_md_synth'  => (string) ( $synthesis['answer_md_synth'] ?? '' ),
				'final_compose'    => [
					'tokens'   => (int)    ( $final['tokens']   ?? 0 ),
					'model'    => (string) ( $final['model']    ?? '' ),
					'ms'       => $final_ms,
					'chunks'   => $final_seq,
					'fallback' => (string) ( $final['fallback'] ?? '' ),
					'success'  => ! empty( $final['success'] ),
				],
			],
			// [2026-06-04 Johnny Chu] BS-12 — persist full turn result_snapshot
			// (FE BrainTurnResponse shape) so session replay renders identically
			// to a live turn: perspective cards, consensus/tensions, fuchsia final
			// answer with citation chips, web pill, trace id and timing.
			'result_snapshot'      => $this->build_turn_snapshot( $trace_id, $opts, array(
				'candidates'      => $candidates,
				'tool_candidates' => $tool_candidates,
				'web_mode'        => $web_mode,
				'mode'            => 'brain',
				'perspectives'    => $persp_snapshot,
				'synthesis'       => array(
					'answer_md'      => (string) ( $synthesis['answer_md_synth'] ?? ( $synthesis['answer_md'] ?? '' ) ),
					'consensus'      => $synthesis['consensus']      ?? array(),
					'tensions'       => $synthesis['tensions']       ?? array(),
					'recommendation' => $synthesis['recommendation'] ?? '',
					'citations'      => $synthesis['citations']      ?? array(),
					'tokens'         => (int) ( $synthesis['tokens'] ?? 0 ),
					'ms'             => $synth_ms,
					'fallback'       => (string) ( $synthesis['fallback'] ?? '' ),
				),
				'final'           => array(
					'answer_md' => $final_text,
					'chunks'    => $final_seq,
					'tokens'    => (int) ( $final['tokens'] ?? 0 ),
					'model'     => (string) ( $final['model'] ?? '' ),
					'ms'        => $final_ms,
					'fallback'  => (string) ( $final['fallback'] ?? '' ),
					'success'   => ! empty( $final['success'] ),
				),
				'web_research'    => $web_row,
				'tool_dispatch'   => $tool_done_payload,
				'cited_entity_ids'=> $cited_entity_ids,
				'cited_passages'  => $cited_passages,
				'duration_ms'     => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
			) ),
		] );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		return [
			'ok'                => true,
			'synthesis'         => $synthesis,
			'answers'           => $answers,
			'cited_entity_ids'  => $cited_entity_ids,
			'cited_passages'    => $cited_passages,
			'duration_ms'       => $wall_ms,
		];
	}

	/**
	 * BS-12 — Build a normalized full-turn snapshot persisted inside the
	 * assistant_message payload. Shape mirrors the FE `BrainTurnResponse`
	 * so session replay (list_turns → tc-brain-load-session) can render a
	 * historical turn identically to a live one without re-running the
	 * (ephemeral, SSE-only) pipeline.
	 *
	 * [2026-06-04 Johnny Chu] BS-12 — session replay full-fidelity snapshot.
	 *
	 * @param string $trace_id
	 * @param array  $opts
	 * @param array  $parts
	 * @return array
	 */
	private function build_turn_snapshot( string $trace_id, array $opts, array $parts ): array {
		$snap = array(
			'trace_id'        => $trace_id,
			'candidates'      => array_values( (array) ( $parts['candidates'] ?? array() ) ),
			'tool_candidates' => array_values( (array) ( $parts['tool_candidates'] ?? array() ) ),
			'keyword_tokens'  => array_values( (array) ( $opts['keyword_tokens'] ?? array() ) ),
			'web_mode'        => (string) ( $parts['web_mode'] ?? strtolower( (string) ( $opts['web_mode'] ?? 'off' ) ) ),
			'mode'            => (string) ( $parts['mode'] ?? 'brain' ),
			'perspectives'    => array(),
			'synthesis'       => array(),
			'final'           => array(),
			'web_research'    => array(),
			'tool_dispatch'   => array(),
			'agent'           => array(),
			'cited_entity_ids'=> array_values( (array) ( $parts['cited_entity_ids'] ?? array() ) ),
			'cited_passages'  => array_values( (array) ( $parts['cited_passages'] ?? array() ) ),
			'duration_ms'     => (int) ( $parts['duration_ms'] ?? 0 ),
		);

		foreach ( (array) ( $parts['perspectives'] ?? array() ) as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$snap['perspectives'][] = array(
				'notebook_id' => (int)    ( $a['notebook_id'] ?? 0 ),
				'label'       => (string) ( $a['label']       ?? '' ),
				'stance'      => (string) ( $a['stance']      ?? '' ),
				'confidence'  => (float)  ( $a['confidence']  ?? 0 ),
				'answer_md'   => (string) ( $a['answer_md']   ?? '' ),
				'reason'      => (string) ( $a['reason']      ?? '' ),
				'ms'          => (int)    ( $a['ms']          ?? 0 ),
				'tokens'      => (int)    ( $a['tokens']      ?? 0 ),
				'citations'   => array_values( (array) ( $a['citations'] ?? array() ) ),
			);
		}

		if ( isset( $parts['synthesis'] ) && is_array( $parts['synthesis'] ) ) {
			$s = $parts['synthesis'];
			$snap['synthesis'] = array(
				'answer_md'      => (string) ( $s['answer_md']      ?? '' ),
				'consensus'      => array_values( (array) ( $s['consensus'] ?? array() ) ),
				'tensions'       => array_values( (array) ( $s['tensions']  ?? array() ) ),
				'recommendation' => (string) ( $s['recommendation'] ?? '' ),
				'citations'      => array_values( (array) ( $s['citations'] ?? array() ) ),
				'tokens'         => (int)    ( $s['tokens'] ?? 0 ),
				'ms'             => (int)    ( $s['ms']     ?? 0 ),
				'fallback'       => (string) ( $s['fallback'] ?? '' ),
			);
		}

		if ( isset( $parts['final'] ) && is_array( $parts['final'] ) ) {
			$f = $parts['final'];
			$snap['final'] = array(
				'answer_md' => (string) ( $f['answer_md'] ?? '' ),
				'streaming' => false,
				'chunks'    => (int)    ( $f['chunks'] ?? 0 ),
				'tokens'    => (int)    ( $f['tokens'] ?? 0 ),
				'model'     => (string) ( $f['model']  ?? '' ),
				'ms'        => (int)    ( $f['ms']     ?? 0 ),
				'fallback'  => (string) ( $f['fallback'] ?? '' ),
				'success'   => ! empty( $f['success'] ),
			);
		}

		if ( ! empty( $parts['web_research'] ) && is_array( $parts['web_research'] ) ) {
			$snap['web_research'] = $parts['web_research'];
		}
		if ( ! empty( $parts['tool_dispatch'] ) && is_array( $parts['tool_dispatch'] ) ) {
			$snap['tool_dispatch'] = $parts['tool_dispatch'];
		}
		if ( ! empty( $parts['agent'] ) && is_array( $parts['agent'] ) ) {
			$snap['agent'] = $parts['agent'];
		}

		return $snap;
	}

	/* =================================================================
	 *  TBR.W20 — Agent ReAct mode (2026-05-28)
	 *  Activated when REST opt `mode=agent`. Runs a generic ReAct loop
	 *  via BizCity_TwinBrain_Agent_Runner using Tool_Registry. Bypasses
	 *  Perspective / Web / Tool_Decision / Synthesizer / Final_Composer
	 *  entirely — Agent_Runner returns its own answer text from either
	 *  a `final` step or force_final synthesis.
	 *
	 *  SSE timeline contract: emits skeleton perspectives_started/done
	 *  + tool_decided + synthesis_started/done with `mode='agent'` markers
	 *  so the FE timeline reducer can collapse them into a single "Agent"
	 *  badge. Final answer streamed via `final_started/token/done` —
	 *  Agent_Runner does NOT support token-level streaming (loop body
	 *  needs each LLM call to complete to parse tool call), so we emit
	 *  the final text as a single chunk after agent_loop_done.
	 * ================================================================ */
	private function stream_agent_react(
		string $trace_id,
		string $prompt,
		BizCity_Twin_SSE_Writer $sse,
		array $opts,
		float $wall_t0
	): array {
		if ( ! class_exists( 'BizCity_TwinBrain_Agent_Runner' ) ) {
			// Hard fallback — emit error event + return empty stub.
			$err = [
				'trace_id' => $trace_id,
				'error'    => 'agent_runner_missing',
				'reason'   => 'BizCity_TwinBrain_Agent_Runner class not loaded',
			];
			$sse->emit( 'agent_loop_done', $err );
			$this->emit_event( 'agent_loop_done', $err );
			return [
				'ok'                => false,
				'synthesis'         => [ 'answer_md' => '', 'fallback' => 'agent_runner_missing' ],
				'answers'           => [],
				'cited_entity_ids'  => [],
				'cited_passages'    => [],
				'duration_ms'       => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
				'mode'              => 'agent',
				'error'             => 'agent_runner_missing',
			];
		}

		/* Emit skeleton phase events so FE timeline doesn't stall. */
		$skel = [ 'trace_id' => $trace_id, 'count' => 0, 'mode' => 'agent' ];
		$sse->emit( 'perspectives_started', $skel );
		$sse->emit( 'perspectives_done',    $skel + [ 'ms' => 0 ] );
		$tool_dec = [ 'decision' => 'agent_loop', 'reason' => 'mode_agent', 'tool' => '' ];
		$sse->emit( 'tool_decided', array_merge( [ 'trace_id' => $trace_id ], $tool_dec ) );
		$this->emit_event( 'tool_decided', array_merge(
			[ 'trace_id' => $trace_id, 'surface' => self::SURFACE ],
			$tool_dec
		) );
		$sse->emit( 'synthesis_started', [ 'trace_id' => $trace_id, 'mode' => 'agent' ] );

		/* Run ReAct loop. Agent_Runner emits agent_loop_started /
		 * agent_step_done / agent_loop_done via the relay callback. */
		$agent  = BizCity_TwinBrain_Agent_Runner::instance();
		$relay  = function ( string $event_key, array $payload ) use ( $sse ) {
			$sse->emit( $event_key, $payload );
			$this->emit_event( $event_key, $payload );
		};

		$agent_opts = [
			'user_id'      => (int)    ( $opts['user_id']      ?? get_current_user_id() ),
			'session_id'   => (string) ( $opts['session_id']   ?? '' ),
			'guru_id'      => (int)    ( $opts['guru_id']      ?? 0 ),
			'memory_block' => (string) ( $opts['memory_block'] ?? '' ),
			'notebook_id'  => (int)    ( $opts['notebook_id']  ?? 0 ),
			'scope'        => isset( $opts['scope'] ) && is_array( $opts['scope'] ) ? $opts['scope'] : null,
		];
		$agent_res = $agent->run( $trace_id, $prompt, $agent_opts, $relay );

		$final_text = (string) ( $agent_res['answer_md'] ?? '' );

		$sse->emit( 'synthesis_done', [
			'trace_id'       => $trace_id,
			'answer_md'      => '',
			'consensus'      => [],
			'tensions'       => [],
			'recommendation' => '',
			'citations'      => [],
			'tokens'         => 0,
			'ms'             => 0,
			'fallback'       => 'agent_mode',
		] );

		/* Stream final as single chunk (no token-level for ReAct). */
		$sse->emit( 'final_started', [ 'trace_id' => $trace_id, 'mode' => 'agent' ] );
		if ( $final_text !== '' ) {
			$sse->emit( 'final_token', [
				'trace_id' => $trace_id,
				'seq'      => 1,
				'delta'    => $final_text,
				'len'      => mb_strlen( $final_text ),
			] );
		}
		$sse->emit( 'final_done', [
			'trace_id'  => $trace_id,
			'answer_md' => $final_text,
			'tokens'    => (int)    ( $agent_res['tokens'] ?? 0 ),
			'model'     => (string) ( $agent_res['model']  ?? '' ),
			'ms'        => (int)    ( $agent_res['ms']     ?? 0 ),
			'chunks'    => $final_text !== '' ? 1 : 0,
			'fallback'  => ! empty( $agent_res['forced_final'] ) ? 'agent_forced_final:' . ( $agent_res['reason'] ?? '' ) : '',
			'success'   => $final_text !== '',
			'mode'      => 'agent',
		] );

		/* Memory tool dispatcher — agent answer may contain inline memory tool blocks. */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) && $final_text !== '' ) {
			try {
				$tool_disp = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->dispatch_from_text(
					$final_text,
					[
						'trace_id'   => $trace_id,
						'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'surface'    => self::SURFACE,
						'on_event'   => function ( string $event_key, array $payload ) use ( $sse ) {
							$sse->emit( $event_key, $payload );
							$this->emit_event( $event_key, $payload );
						},
					]
				);
				if ( ! empty( $tool_disp['ops'] ) ) {
					$sse->emit( 'memory_tool_summary', [
						'trace_id'   => $trace_id,
						'tool_calls' => (int) $tool_disp['tool_calls'],
						'persisted'  => (int) $tool_disp['persisted'],
						'forgotten'  => (int) $tool_disp['forgotten'],
						'recalled'   => (int) $tool_disp['recalled'],
						'skipped'    => (int) $tool_disp['skipped'],
						'errors'     => (array) $tool_disp['errors'],
						'latency_ms' => (int) $tool_disp['latency_ms'],
					] );
					$rewritten = (string) $tool_disp['rewritten_text'];
					if ( $rewritten !== '' ) {
						$final_text = $rewritten;
					}
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][agent][memory_tool_dispatcher][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
			}
		}

		/* Memory writer (L4.7) — persist 'hãy nhớ ...'. */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					[
						'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
					]
				);
				$write_payload = [
					'trace_id'   => $trace_id,
					'persisted'  => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'       => (string) ( $mw['mode']       ?? '' ),
					'ops'        => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms' => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][agent][memory_write][error] ' . $e->getMessage() );
				}
			}

			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — mood sampler (agent path).
			$session_id = (string) ( $opts['session_id'] ?? '' );
			if ( $session_id !== '' && method_exists( 'BizCity_TwinBrain_Memory_Writer', 'sample_mood' ) ) {
				try {
					$turn_index = $this->count_user_turns_for_session( $session_id );
					$mood = BizCity_TwinBrain_Memory_Writer::instance()->sample_mood( [
						'trace_id'   => $trace_id,
						'session_id' => $session_id,
						'user_id'    => (int) ( $opts['user_id'] ?? get_current_user_id() ),
						'turn_index' => $turn_index,
						'prompt'     => (string) $prompt,
						'answer'     => (string) $final_text,
					] );
					if ( ( $mood['status'] ?? '' ) === 'sampled' ) {
						$sse->emit( 'mood_sampled', [
							'trace_id'   => $trace_id,
							'session_id' => $session_id,
							'turn_index' => (int)    ( $mood['turn_index'] ?? $turn_index ),
							'valence'    => (float)  ( $mood['valence']    ?? 0.0 ),
							'label'      => (string) ( $mood['label']      ?? '' ),
							'event_uuid' => (string) ( $mood['event_uuid'] ?? '' ),
						] );
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TwinBrain][agent][mood_sampled][error] ' . $e->getMessage() );
					}
				}
			}
		}

		/* cite_resolved + assistant_message — agent doesn't produce nb/web citations. */
		$sse->emit( 'cite_resolved', [
			'trace_id'         => $trace_id,
			'cited_entity_ids' => [],
			'cited_passages'   => [],
		] );

		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $agent_res['model']  ?? '' ),
			'tokens'             => (int)    ( $agent_res['tokens'] ?? 0 ),
			'ms'                 => (int)    ( $agent_res['ms']     ?? 0 ),
			'consensus_count'    => 0,
			'tensions_count'     => 0,
			'citation_count'     => count( (array) ( $agent_res['citations'] ?? [] ) ),
			'cited_entity_count' => 0,
			'cited_passage_count'=> 0,
			'fallback'           => ! empty( $agent_res['forced_final'] ) ? 'agent_forced_final' : '',
			'answers_in'         => 0,
			'streaming'          => true,
			'mode'               => 'agent',
		] );
		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $final_text,
			'synthesis_metadata'   => [
				'consensus'        => [],
				'tensions'         => [],
				'recommendation'   => '',
				'citations'        => (array) ( $agent_res['citations'] ?? [] ),
				'citation_count'   => count( (array) ( $agent_res['citations'] ?? [] ) ),
				'cited_entity_ids' => [],
				'cited_passages'   => [],
				'model'            => (string) ( $agent_res['model']  ?? '' ),
				'tokens'           => (int)    ( $agent_res['tokens'] ?? 0 ),
				'ms'               => (int)    ( $agent_res['ms']     ?? 0 ),
				'fallback'         => ! empty( $agent_res['forced_final'] ) ? 'agent_forced_final' : '',
				'streaming'        => true,
				'mode'             => 'agent',
				'agent'            => [
					'iter_count'   => count( (array) ( $agent_res['iterations'] ?? [] ) ),
					'tool_runs'    => (array) ( $agent_res['tool_runs'] ?? [] ),
					'forced_final' => ! empty( $agent_res['forced_final'] ),
					'reason'       => (string) ( $agent_res['reason'] ?? '' ),
				],
				'final_compose'    => [
					'tokens'   => (int)    ( $agent_res['tokens'] ?? 0 ),
					'model'    => (string) ( $agent_res['model']  ?? '' ),
					'ms'       => (int)    ( $agent_res['ms']     ?? 0 ),
					'chunks'   => $final_text !== '' ? 1 : 0,
					'fallback' => ! empty( $agent_res['forced_final'] ) ? 'agent_forced_final' : '',
					'success'  => $final_text !== '',
				],
			],
			// [2026-06-04 Johnny Chu] BS-12 — agent turn replay snapshot.
			'result_snapshot'      => $this->build_turn_snapshot( $trace_id, $opts, array(
				'web_mode' => 'off',
				'mode'     => 'agent',
				'final'    => array(
					'answer_md' => $final_text,
					'chunks'    => $final_text !== '' ? 1 : 0,
					'tokens'    => (int) ( $agent_res['tokens'] ?? 0 ),
					'model'     => (string) ( $agent_res['model'] ?? '' ),
					'ms'        => (int) ( $agent_res['ms'] ?? 0 ),
					'success'   => $final_text !== '',
				),
				'agent'    => array(
					'iterations'   => array_values( (array) ( $agent_res['iterations'] ?? array() ) ),
					'iter_count'   => count( (array) ( $agent_res['iterations'] ?? array() ) ),
					'tool_runs'    => array_values( (array) ( $agent_res['tool_runs'] ?? array() ) ),
					'forced_final' => ! empty( $agent_res['forced_final'] ),
					'reason'       => (string) ( $agent_res['reason'] ?? '' ),
					'tokens'       => (int) ( $agent_res['tokens'] ?? 0 ),
					'model'        => (string) ( $agent_res['model'] ?? '' ),
					'ms'           => (int) ( $agent_res['ms'] ?? 0 ),
					'done'         => true,
				),
				'duration_ms' => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
			) ),
		] );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		return [
			'ok'                => true,
			'synthesis'         => [
				'answer_md' => $final_text,
				'fallback'  => ! empty( $agent_res['forced_final'] ) ? 'agent_forced_final' : '',
			],
			'answers'           => [],
			'cited_entity_ids'  => [],
			'cited_passages'    => [],
			'duration_ms'       => $wall_ms,
			'mode'              => 'agent',
			'agent'             => [
				'iter_count'   => count( (array) ( $agent_res['iterations'] ?? [] ) ),
				'tool_runs'    => (array) ( $agent_res['tool_runs'] ?? [] ),
				'iterations'   => (array) ( $agent_res['iterations'] ?? [] ),
				'forced_final' => ! empty( $agent_res['forced_final'] ),
				'reason'       => (string) ( $agent_res['reason'] ?? '' ),
			],
		];
	}

	/* =================================================================
	 *  PHASE-A C.3b — Astro mode pipeline (2026-06-04)
	 *  Short-circuit complete_turn_stream when web_mode='astro'.
	 *  Pipeline:
	 *    1) bizcity_twin_context_artifacts filter → transit passages
	 *    2) Emit skeleton perspectives/synthesis frames (astro label)
	 *    3) Final_Composer::compose_stream with transit synth block
	 *    4) Memory_Writer (L4.7 persist)
	 *    5) cite_resolved + brain_synthesize + assistant_message
	 *  Fail-open: if passages empty or resolver unavailable,
	 *  _degraded key is set and a graceful message is returned.
	 * ================================================================ */
	private function stream_astro_mode(
		string $trace_id,
		string $prompt,
		BizCity_Twin_SSE_Writer $sse,
		array $opts,
		float $wall_t0
	): array {
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — entry.
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );

		// [2026-06-04 Johnny Chu] PHASE-A C.3b DEBUG — verbose error_log so dev có thể copy
		// debug.log để phân tích pipeline astro. Tag chuẩn `[ASTRO-DEBUG]` để grep dễ.
		$astro_log = function ( $step, $payload ) use ( $trace_id ) {
			$json = function_exists( 'wp_json_encode' )
				? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: json_encode( $payload );
			error_log( '[ASTRO-DEBUG][' . $step . '] trace=' . $trace_id . ' ' . $json );
		};
		$astro_log( 'enter', array(
			'user_id'        => $user_id,
			'prompt'         => $prompt,
			'web_mode'       => isset( $opts['web_mode'] ) ? $opts['web_mode'] : '(none)',
			'engine_loaded'  => class_exists( 'BizCity_TwinBrain_Web_Astro' ),
			'cap_callbacks'  => has_filter( 'bizcity_twin_context_artifacts' ),
			'bccm_helper'    => function_exists( 'bccm_get_or_create_user_coachee' ),
		) );

		/* --- [2026-06-04 Johnny Chu] PHASE-A C.3b — FE-spec event #1: subject. */
		$subject_payload = $this->build_astro_subject_payload( $user_id );
		$astro_log( 'step1_subject', $subject_payload );
		$sse->emit( 'astro_subject_resolved', array_merge(
			array( 'trace_id' => $trace_id ),
			$subject_payload
		) );

		/* --- Delegate Step 1+2 to Web_Astro engine (PHASE-A C.3b refactor) ---
		 * Engine handles: LLM-classify period → emit astro_intent_detected →
		 * apply_filters bizcity_twin_context_artifacts → emit astro_artifacts_loaded.
		 * Fail-OPEN if engine class missing (e.g. partial deploy). */
		$passages   = array();
		$cap_source = 'unavailable';
		$degraded   = '';
		$period     = 'day';
		$astro_row  = null;
		if ( class_exists( 'BizCity_TwinBrain_Web_Astro' ) ) {
			$astro_log( 'engine_run_start', array( 'engine' => 'BizCity_TwinBrain_Web_Astro' ) );
			$astro_row  = BizCity_TwinBrain_Web_Astro::instance()->run( $trace_id, $prompt, $opts );
			$passages   = isset( $astro_row['passages'] ) && is_array( $astro_row['passages'] ) ? $astro_row['passages'] : array();
			$cap_source = (string) ( $astro_row['cap_source'] ?? 'unavailable' );
			$degraded   = (string) ( $astro_row['_degraded']  ?? '' );
			$period     = (string) ( $astro_row['period']     ?? 'day' );
			$astro_log( 'engine_run_done', array(
				'period'         => $period,
				'cap_source'     => $cap_source,
				'_degraded'      => $degraded,
				'passages_count' => count( $passages ),
				'cap_ms'         => isset( $astro_row['cap_ms'] ) ? $astro_row['cap_ms'] : null,
				'classify_ms'    => isset( $astro_row['classify_ms'] ) ? $astro_row['classify_ms'] : null,
				'classify_source'=> isset( $astro_row['classify_source'] ) ? $astro_row['classify_source'] : null,
			) );
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — route
			// relation_profile queries to dedicated pair-assessment pipeline.
			if ( (string) ( $astro_row['analysis_mode'] ?? 'transit_each_day' ) === 'relation_profile' ) {
				return $this->stream_astro_relation_mode(
					$trace_id,
					$prompt,
					$sse,
					$opts,
					$wall_t0,
					$subject_payload,
					is_array( $astro_row ) ? $astro_row : array(),
					$passages,
					$cap_source,
					$degraded,
					$astro_log,
					$user_id
				);
			}
		} else {
			$astro_log( 'engine_missing_fallback', array() );
			try {
				$raw = apply_filters( 'bizcity_twin_context_artifacts', array(), 'astro', $user_id, $opts );
				if ( is_array( $raw ) && ! empty( $raw ) ) {
					$passages   = $raw;
					$cap_source = 'filter';
				} else {
					$degraded = 'astro_transit_unavailable';
				}
			} catch ( \Throwable $e ) {
				$degraded = 'astro_cap_exception';
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_mode][cap_filter][legacy_fallback_error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
			}
		}

		/* --- [2026-06-10 Johnny Chu] HOTFIX — re-emit step 1 when engine resolved a
		 * named coachee (e.g. "Kim Thoa hôm nay thế nào").
		 * The initial astro_subject_resolved always used the logged-in user's own coachee.
		 * If the engine found a different coachee from the query, FE must update step 1. */
		if ( isset( $astro_row['coachee_id_resolved'] ) && $astro_row['coachee_id_resolved'] > 0
			&& $astro_row['coachee_id_resolved'] !== (int) ( $subject_payload['coachee_id'] ?? 0 )
		) {
			$resolved_subject = $this->build_astro_subject_payload_for_coachee(
				(int) $astro_row['coachee_id_resolved'],
				(string) ( $astro_row['name_extracted'] ?? '' ),
				$user_id
			);
			$astro_log( 'step1_subject_updated', $resolved_subject );
			$sse->emit( 'astro_subject_resolved', array_merge(
				array( 'trace_id' => $trace_id ),
				$resolved_subject
			) );
			// Use the updated subject for transit payload below.
			$subject_payload = $resolved_subject;
		}

		/* --- [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W1 readiness gate.
		 * If transit artifacts are missing/degraded, dispatch async refetch and retry once.
		 * Fail-OPEN remains unchanged: if retry still empty, pipeline keeps degraded CTA path. */
		$coachee_refetch   = (int) ( $astro_row['coachee_id_resolved'] ?? $subject_payload['coachee_id'] ?? 0 );
		$birth_ready       = ! empty( $subject_payload['birth']['date'] );
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G5 checklist quality gate (MIN_COUNT + freshness).
		$quality_gate    = $this->evaluate_astro_checklist_quality_gate( $coachee_refetch );
		$quality_blocked = ! empty( $quality_gate['blocked'] );
		if ( $quality_blocked ) {
			$degraded = 'astro_checklist_quality_failed';
			$astro_log( 'quality_gate_pre_compose', $quality_gate );
		}
		$refetch_candidate = $quality_blocked || empty( $passages ) || in_array( (string) $degraded, array(
			'astro_artifacts_empty',
			'transit_pending',
			'astro_transit_unavailable',
		), true );
		$retry_succeeded = false; // [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — B1 fix: track to skip 2nd gate.
		$quality_gate_final = $quality_gate;
		if ( $refetch_candidate && $coachee_refetch > 0 && $birth_ready ) {
			$owner_uid = $this->resolve_astro_owner_user_id( $coachee_refetch, $user_id );
			$queued    = $this->dispatch_astro_refetch_job( $coachee_refetch, $owner_uid );

			if ( class_exists( 'BizCoach_Astro_Checklist' ) && $queued ) {
				BizCoach_Astro_Checklist::mark_pending( $coachee_refetch, BizCoach_Astro_Checklist::KEY_TRANSIT );
			}

			$retry_passages = array();
			$retry_cap      = $cap_source;
			$retry_degraded = $degraded;
			$retry_period   = $period;
			if ( class_exists( 'BizCity_TwinBrain_Web_Astro' ) ) {
				$retry_opts = array_merge( $opts, array( '_astro_readiness_retry' => 1 ) );
				$retry_row  = BizCity_TwinBrain_Web_Astro::instance()->run( $trace_id, $prompt, $retry_opts );
				$retry_passages = isset( $retry_row['passages'] ) && is_array( $retry_row['passages'] )
					? $retry_row['passages'] : array();
				if ( ! empty( $retry_passages ) ) {
					$passages        = $retry_passages;
					$cap_source      = (string) ( $retry_row['cap_source'] ?? $cap_source );
					$degraded        = (string) ( $retry_row['_degraded'] ?? '' );
					$period          = (string) ( $retry_row['period'] ?? $period );
					$retry_succeeded = true; // [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — B1 fix.
				}
				$retry_cap      = (string) ( $retry_row['cap_source'] ?? $retry_cap );
				$retry_degraded = (string) ( $retry_row['_degraded'] ?? $retry_degraded );
				$retry_period   = (string) ( $retry_row['period'] ?? $retry_period );
			}

			$refetch_payload = array(
				'trace_id'       => $trace_id,
				'coachee_id'     => $coachee_refetch,
				'owner_user_id'  => $owner_uid,
				'reason'         => (string) ( $degraded !== '' ? $degraded : 'missing_transit_artifacts' ),
				'queued'         => $queued,
				'retry_once'     => true,
				'retry_hit'      => ! empty( $retry_passages ),
				'retry_count'    => count( $retry_passages ),
				'cap_source'     => $retry_cap,
				'period'         => $retry_period,
				'_degraded'      => $retry_degraded,
				'quality_blocked'     => $quality_blocked,
				'quality_failed_keys' => isset( $quality_gate['failed_keys'] ) ? (array) $quality_gate['failed_keys'] : array(),
				'quality_stale_keys'  => isset( $quality_gate['stale_keys'] ) ? (array) $quality_gate['stale_keys'] : array(),
			);
			$astro_log( 'readiness_gate', $refetch_payload );
			$sse->emit( 'astro_refetch_dispatched', $refetch_payload );
			$this->emit_event( 'astro_refetch_dispatched', $refetch_payload );

			// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — emit actionable fallback links
			// so Ask Brain can guide user to data-dispatch flow immediately.
			$action_links = $this->build_astro_action_links( $coachee_refetch );
			$action_payload = array(
				'trace_id'         => $trace_id,
				'reason'           => (string) ( $quality_blocked ? 'astro_checklist_quality_failed' : ( $degraded !== '' ? $degraded : 'astro_refetch_required' ) ),
				'needs_birth_data' => false,
				'coachee_id'       => $coachee_refetch,
				'failed_keys'      => isset( $quality_gate['failed_keys'] ) ? (array) $quality_gate['failed_keys'] : array(),
				'stale_keys'       => isset( $quality_gate['stale_keys'] ) ? (array) $quality_gate['stale_keys'] : array(),
				'astro_url'        => (string) $action_links['dashboard_url'],
				'profile_data_url' => (string) $action_links['profile_data_url'],
				'subjects_url'     => (string) $action_links['subjects_url'],
				'dashboard_url'    => (string) $action_links['dashboard_url'],
				'actions'          => array(
					array(
						'label'   => 'Mo trang du lieu thien van',
						'url'     => (string) $action_links['profile_data_url'],
						'variant' => 'primary',
					),
					array(
						'label'   => 'Nhap/sua ho so coachee',
						'url'     => (string) $action_links['subjects_url'],
						'variant' => 'secondary',
					),
					array(
						'label'   => 'Mo My Astro tong quan',
						'url'     => (string) $action_links['dashboard_url'],
						'variant' => 'secondary',
					),
				),
			);
			$sse->emit( 'astro_data_action_required', $action_payload );
			$this->emit_event( 'astro_data_action_required', $action_payload );
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G5 hard gate before compose.
		// B1 fix: skip second check when retry already populated fresh passages (DB not yet updated).
		if ( ! $retry_succeeded ) {
			$quality_gate_after = $this->evaluate_astro_checklist_quality_gate( $coachee_refetch );
			$quality_gate_final = $quality_gate_after;
			if ( ! empty( $quality_gate_after['blocked'] ) && ! empty( $passages ) ) {
				$degraded = 'astro_checklist_quality_failed';
				$astro_log( 'quality_gate_blocked_compose', $quality_gate_after );
				$passages = array();
			}
		}

		/* --- [2026-06-04 Johnny Chu] PHASE-A C.3b — FE-spec events #2-#4. */
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — Step 2 must honor classifier offset (e.g. ngày mai=+1).
		$time_payload = $this->build_astro_time_payload( $prompt, $period, is_array( $astro_row ) ? $astro_row : array() );
		$astro_log( 'step2_time', $time_payload );
		$sse->emit( 'astro_time_parsed', array_merge(
			array( 'trace_id' => $trace_id ),
			$time_payload
		) );
		$transit_payload = $this->build_astro_transit_payload(
			$subject_payload, $passages, $period, $astro_row
		);
		$astro_log( 'step3_transit', $transit_payload );
		$sse->emit( 'astro_transit_resolved', array_merge(
			array( 'trace_id' => $trace_id ),
			$transit_payload
		) );
		$context_payload = $this->build_astro_context_payload( $passages, $degraded );
		$astro_log( 'step4_context', $context_payload );
		$sse->emit( 'astro_context_appended', array_merge(
			array( 'trace_id' => $trace_id ),
			$context_payload
		) );
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 day-by-day timeline events.
		$day_eval = $this->build_astro_day_evaluation_rows( $passages, $prompt, $astro_row );
		$eachday_composed = array();
		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — compose first, then emit day events
		// so FE cards show detailed each-day analysis instead of short deterministic reason only.
		if ( class_exists( 'BizCity_TwinBrain_Astro_Transit_Eachday_Composer' ) ) {
			$eachday_composed = BizCity_TwinBrain_Astro_Transit_Eachday_Composer::instance()->compose(
				isset( $day_eval['rows'] ) ? (array) $day_eval['rows'] : array(),
				array(
					'query'                 => $prompt,
					'surface'               => 'web_astro',
					'period_label'          => (string) ( $time_payload['label_vi'] ?? '' ),
					'source_url'            => (string) ( $transit_payload['source_url'] ?? '' ),
					// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A15 — widen each-day depth (+50%).
					'target_tokens_per_day' => 930,
				)
			);

			if ( ! empty( $eachday_composed['day_messages'] ) && is_array( $eachday_composed['day_messages'] ) ) {
				foreach ( $day_eval['rows'] as $_i => $_row ) {
					if ( ! is_array( $_row ) ) { continue; }
					$_d = (string) ( $_row['date'] ?? '' );
					if ( $_d === '' ) { continue; }
					if ( isset( $eachday_composed['day_messages'][ $_d ] ) ) {
						$_msg = trim( (string) $eachday_composed['day_messages'][ $_d ] );
						if ( $_msg !== '' ) {
							$day_eval['rows'][ $_i ]['reason'] = $_msg;
							$day_eval['rows'][ $_i ]['detailed_reason'] = $_msg;
						}
					}
				}
			}

			if ( ! empty( $eachday_composed['metrics']['same_counts_across_days'] ) ) {
				$_metrics_payload = array(
					'trace_id'      => $trace_id,
					'same_counts'   => true,
					'values'        => (array) ( $eachday_composed['metrics']['values'] ?? array() ),
					'note'          => 'good/bad/topic/retro/aspects là thống kê theo nhóm tín hiệu; có thể trùng giữa nhiều ngày.',
				);
				$sse->emit( 'astro_day_metrics_explain', $_metrics_payload );
				$this->emit_event( 'astro_day_metrics_explain', $_metrics_payload );
			}
		}

		if ( ! empty( $day_eval['rows'] ) ) {
			$astro_log( 'step4b_day_evaluated', array(
				'days'   => count( $day_eval['rows'] ),
				'topics' => isset( $day_eval['topics'] ) ? $day_eval['topics'] : array(),
			) );
			foreach ( $day_eval['rows'] as $idx => $row ) {
				$payload = array_merge(
					array(
						'trace_id'    => $trace_id,
						'index'       => (int) $idx + 1,
						'total_days'  => count( $day_eval['rows'] ),
						'topics'      => isset( $day_eval['topics'] ) ? $day_eval['topics'] : array(),
					),
					$row
				);
				$sse->emit( 'astro_day_evaluated', $payload );
				$this->emit_event( 'astro_day_evaluated', $payload );
			}
		}

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A6 — emit a compact
		// per-turn astro debug snapshot so QA can verify owner/coachee binding
		// and compare natal/transit JSON from the same trace.
		$astro_debug_trace = $this->build_astro_debug_trace_payload(
			$trace_id,
			$user_id,
			$subject_payload,
			$time_payload,
			$transit_payload,
			is_array( $day_eval ) ? $day_eval : array(),
			is_array( $astro_row ) ? $astro_row : array(),
			$passages,
			$degraded
		);
		$astro_log( 'debug_trace_snapshot', array(
			'user_id'            => isset( $astro_debug_trace['user_id'] ) ? (int) $astro_debug_trace['user_id'] : 0,
			'coachee_id_resolved'=> isset( $astro_debug_trace['coachee_id_resolved'] ) ? (int) $astro_debug_trace['coachee_id_resolved'] : 0,
			'resolve_source'     => isset( $astro_debug_trace['resolve_source'] ) ? (string) $astro_debug_trace['resolve_source'] : '',
			'transit_days_count' => isset( $astro_debug_trace['transit_days_count'] ) ? (int) $astro_debug_trace['transit_days_count'] : 0,
		) );
		$sse->emit( 'astro_debug_trace', $astro_debug_trace );
		$this->emit_event( 'astro_debug_trace', $astro_debug_trace );

		$day_rank = $this->build_astro_day_ranking( isset( $day_eval['rows'] ) ? (array) $day_eval['rows'] : array() );
		if ( ! empty( $day_rank['ranking'] ) ) {
			$rank_payload = array(
				'trace_id'    => $trace_id,
				'total_days'  => (int) ( $day_rank['total_days'] ?? 0 ),
				'topics'      => isset( $day_eval['topics'] ) ? $day_eval['topics'] : array(),
				'ranking'     => $day_rank['ranking'],
				'best_day'    => (string) ( $day_rank['best_day'] ?? '' ),
				'best_score'  => (float) ( $day_rank['best_score'] ?? 0 ),
			);
			$astro_log( 'step4c_day_ranked', array(
				'best_day'   => $rank_payload['best_day'],
				'best_score' => $rank_payload['best_score'],
			) );
			$sse->emit( 'astro_day_ranked', $rank_payload );
			$this->emit_event( 'astro_day_ranked', $rank_payload );
		}

		$final_rec = $this->build_astro_final_recommendation_payload( $day_rank, $period, $transit_payload, $degraded );
		if ( ! empty( $eachday_composed['best_day'] ) ) {
			$final_rec['best_day']   = (string) $eachday_composed['best_day'];
			$final_rec['best_score'] = (float) ( $eachday_composed['best_day_score'] ?? ( $final_rec['best_score'] ?? 0 ) );
			if ( ! empty( $eachday_composed['final_recommendation'] ) ) {
				$final_rec['reason'] = (string) $eachday_composed['final_recommendation'];
			}
		}
		$final_rec['trace_id'] = $trace_id;
		$astro_log( 'step4d_final_recommendation', array(
			'best_day'   => isset( $final_rec['best_day'] ) ? $final_rec['best_day'] : '',
			'best_score' => isset( $final_rec['best_score'] ) ? $final_rec['best_score'] : 0,
			'_degraded'  => isset( $final_rec['_degraded'] ) ? $final_rec['_degraded'] : '',
		) );
		$sse->emit( 'astro_final_recommendation', $final_rec );
		$this->emit_event( 'astro_final_recommendation', $final_rec );
		$astro_log( 'step5_compose_starting', array( 'note' => 'handing off to final composer' ) );

		/* --- Emit skeleton phase events (astro label) --- */
		$sse->emit( 'perspectives_started', [
			'trace_id' => $trace_id,
			'count'    => count( $passages ),
			'mode'     => 'astro',
		] );
		$sse->emit( 'perspectives_done', [
			'trace_id' => $trace_id,
			'count'    => count( $passages ),
			'ms'       => 0,
			'mode'     => 'astro',
		] );
		$tool_decision = [ 'decision' => 'no_op', 'reason' => 'astro_mode', 'tool' => '' ];
		$sse->emit( 'tool_decided', array_merge( [ 'trace_id' => $trace_id ], $tool_decision ) );
		$this->emit_event( 'tool_decided', array_merge(
			[ 'trace_id' => $trace_id, 'surface' => self::SURFACE ],
			$tool_decision
		) );
		$sse->emit( 'synthesis_started', [ 'trace_id' => $trace_id, 'mode' => 'astro' ] );

		/* --- Build pseudo-synth from passages --- */
		$passages_md = '';
		if ( ! empty( $passages ) ) {
			$blocks = [];
			foreach ( $passages as $p ) {
				$title = trim( (string) ( $p['title'] ?? '' ) );
				$body  = trim( (string) ( $p['body']  ?? '' ) );
				if ( $body === '' ) continue;
				$blocks[] = $title !== '' ? "### {$title}\n{$body}" : $body;
			}
			$passages_md = implode( "\n\n", $blocks );
		}

		// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — prepend canonical
		// cached natal context so transit answer grounds on unified profile.
		$subject_context_md = trim( (string) ( $subject_payload['transit_context_md'] ?? '' ) );
		if ( $subject_context_md !== '' ) {
			$passages_md = "## HỒ SƠ CHỦ THỂ (NATAL)\n" . $subject_context_md
				. ( $passages_md !== '' ? "\n\n" . $passages_md : '' );
		}

		$period = $period !== '' ? $period : 'day';

		$day_foreach_md = '';
		if ( ! empty( $eachday_composed['transit_foreach_md'] ) ) {
			$day_foreach_md = (string) $eachday_composed['transit_foreach_md'];
		}
		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — shared composer fallback.
		if ( $day_foreach_md === '' ) {
			$day_foreach_md = $this->build_astro_day_foreach_markdown(
				isset( $day_eval['rows'] ) ? (array) $day_eval['rows'] : array(),
				is_array( $day_rank ) ? $day_rank : array(),
				is_array( $final_rec ) ? $final_rec : array()
			);
		}
		if ( $day_foreach_md !== '' ) {
			$passages_md .= "\n\n" . $day_foreach_md;
		}

		$degraded_msg = '';
		if ( $passages_md === '' ) {
			$degraded   = $degraded !== '' ? $degraded : 'astro_transit_unavailable';
			// [2026-06-08 Johnny Chu] PHASE-A C.3d — astro no-data CTA.
			// Distinguish "missing birth data / no subject" (actionable: user can
			// add DOB + generate chart) from transient "transit unavailable".
			$subj_degraded = (string) ( $subject_payload['_degraded'] ?? '' );
			$needs_birth   = in_array(
				$subj_degraded,
				array( 'astro_birth_data_missing', 'astro_coachee_not_found' ),
				true
			) || in_array(
				$degraded,
				array( 'astro_birth_data_missing', 'transit_no_subject' ),
				true
			);

			// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — unify CTA with
			// iframe hash routes so CSKH can copy the same links from runbook §11.7.
			$action_links      = $this->build_astro_action_links( $coachee_refetch );
			$astro_url         = (string) ( $action_links['dashboard_url'] ?? '' );
			$profile_data_url  = (string) ( $action_links['profile_data_url'] ?? '' );
			$subjects_url      = (string) ( $action_links['subjects_url'] ?? '' );
			$dashboard_url     = (string) ( $action_links['dashboard_url'] ?? '' );
			$quality_failed    = isset( $quality_gate_final['failed_keys'] ) ? (array) $quality_gate_final['failed_keys'] : array();
			$quality_stale     = isset( $quality_gate_final['stale_keys'] ) ? (array) $quality_gate_final['stale_keys'] : array();
			$is_quality_failed = ( $degraded === 'astro_checklist_quality_failed' ) || ! empty( $quality_gate_final['blocked'] );

			$cta_actions = array();
			if ( $is_quality_failed ) {
				$cta_actions = array(
					array(
						'label'   => 'Mo trang du lieu thien van',
						'url'     => $profile_data_url,
						'variant' => 'primary',
					),
					array(
						'label'   => 'Nhap/sua ho so coachee',
						'url'     => $subjects_url,
						'variant' => 'secondary',
					),
					array(
						'label'   => 'Mo My Astro tong quan',
						'url'     => $dashboard_url,
						'variant' => 'secondary',
					),
				);

				$failed_line = ! empty( $quality_failed )
					? ( "\n\nChecklist chua dat: " . implode( ', ', array_map( 'strval', $quality_failed ) ) . '.' )
					: '';
				$stale_line  = ! empty( $quality_stale )
					? ( "\nChecklist can lam moi: " . implode( ', ', array_map( 'strval', $quality_stale ) ) . '.' )
					: '';
				$degraded_msg =
					'Du lieu checklist chiem tinh chua dat nguong chat luong de luan giai.'
					. $failed_line
					. $stale_line
					. "\n\nVui long mo profile, bam 'Tao Day Du Du Lieu' va 'Lam moi transit', roi quay lai hoi lai cau vua roi.";
			} elseif ( $needs_birth ) {
				$cta_actions[] = array(
					'label'   => 'Nhap/sua ho so coachee',
					'url'     => $subjects_url,
					'variant' => 'primary',
				);
				$cta_actions[] = array(
					'label'   => 'Mo trang du lieu thien van',
					'url'     => $profile_data_url,
					'variant' => 'secondary',
				);
				$cta_actions[] = array(
					'label'   => 'Mo My Astro tong quan',
					'url'     => $dashboard_url,
					'variant' => 'secondary',
				);
				$degraded_msg =
					"Mình chưa có **ngày tháng năm sinh** của bạn nên chưa thể luận giải chiêm tinh cho hôm nay.\n\n"
					. 'Vui long vao trang Subjects de nhap/sua ho so, mo profile de tao day du du lieu, '
					. 'sau do quay lai day va hoi lai cau vua roi.';
			} else {
				$cta_actions[] = array(
					'label'   => 'Mo trang du lieu thien van',
					'url'     => $profile_data_url,
					'variant' => 'primary',
				);
				$cta_actions[] = array(
					'label'   => 'Nhap/sua ho so coachee',
					'url'     => $subjects_url,
					'variant' => 'secondary',
				);
				$cta_actions[] = array(
					'label'   => 'Mo My Astro tong quan',
					'url'     => $dashboard_url,
					'variant' => 'secondary',
				);
				$degraded_msg =
					'Xin loi, hien tai du lieu chiem tinh chua du hoac chua moi nhat. '
					. 'Vui long mo profile de tao/day du du lieu va lam moi transit, roi thu lai sau it phut.';
			}

			// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — structured payload
			// for both new event name and legacy FE consumer.
			$cta_payload = array(
				'trace_id'         => $trace_id,
				'reason'           => $degraded,
				'needs_birth_data' => $needs_birth,
				'coachee_id'       => $coachee_refetch,
				'failed_keys'      => $quality_failed,
				'stale_keys'       => $quality_stale,
				'astro_url'        => $astro_url,
				'profile_data_url' => $profile_data_url,
				'subjects_url'     => $subjects_url,
				'dashboard_url'    => $dashboard_url,
				'actions'          => $cta_actions,
			);
			$sse->emit( 'astro_data_action_required', $cta_payload );
			$this->emit_event( 'astro_data_action_required', $cta_payload );
			$sse->emit( 'astro_cta', $cta_payload );
		}


		// [2026-06-10 Johnny Chu] ASTRO-CITE 3 — strip [astro:*#URL] header lines
		// from display version so Consensus panel shows clean transit markdown.
		$passages_md_display = preg_replace( '/^\[astro:[a-z_]+#[^\]\s]+\][^\n]*\n?/m', '', $passages_md );
		$passages_md_display = ltrim( (string) $passages_md_display, "\n" );

		$synth_fake = [
			'answer_md'      => $passages_md_display,
			'consensus'      => [ 'Dữ liệu quá cảnh: ' . $period ],
			'tensions'       => [],
			'recommendation' => '',
			'citations'      => [],
			'tokens'         => 0,
			'model'          => '',
		];
		$sse->emit( 'synthesis_done', [
			'trace_id'       => $trace_id,
			'answer_md'      => $passages_md_display !== '' ? mb_substr( $passages_md_display, 0, 200 ) . '…' : '',
			'consensus'      => $synth_fake['consensus'],
			'tensions'       => [],
			'recommendation' => '',
			'citations'      => [],
			'tokens'         => 0,
			'ms'             => 0,
			'mode'           => 'astro',
		] );

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A3 — Astro Recall step for astro mode.
		// Emitted BEFORE final_started so FE timeline shows "Astro recall" row just before synthesis.
		// In astro mode the data is already fetched by Web_Astro; we re-use $subject_payload +
		// $passages to build the same payload shape without a redundant DB call.
		{
			$_ar_natal    = ( isset( $subject_payload['birth']['date'] ) && $subject_payload['birth']['date'] !== '' ) ? 1 : 0;
			$_ar_transits = count( $passages );
			$_ar_report   = isset( $astro_row['report_sections'] ) ? (int) $astro_row['report_sections'] : 0;
			$_ar_active   = ( (int) ( $subject_payload['coachee_id'] ?? 0 ) > 0 ) && ( $_ar_natal > 0 || $_ar_transits > 0 );
			$_ar_profile  = array(
				'name'    => (string) ( $subject_payload['name'] ?? '' ),
				'is_self' => ! empty( $subject_payload['is_self'] ),
			);
			$_ar_ms  = (int) round( ( microtime( true ) - $wall_t0 ) * 1000 );
			$sse->emit( 'astro_recall_done', array(
				'trace_id'   => $trace_id,
				'active'     => $_ar_active,
				'coachee_id' => (int) ( $subject_payload['coachee_id'] ?? 0 ),
				'counts'     => array(
					'natal'           => $_ar_natal,
					'report_sections' => $_ar_report,
					'transit_days'    => $_ar_transits,
				),
				'profile'    => $_ar_profile,
				'_degraded'  => $degraded !== '' ? $degraded : null,
				'latency_ms' => $_ar_ms,
				'source'     => 'astro_mode_engine',
			) );
		}

		/* --- Layer 4.5 Final Composer (stream) --- */
		$final_t0  = microtime( true );
		$final_seq = 0;
		$final_keepalive_seq = 0;
		$sse->emit( 'final_started', [
			'trace_id' => $trace_id,
			'mode'     => 'astro',
			'degraded' => ( $degraded !== '' ),
		] );

		$composer = BizCity_TwinBrain_Final_Composer::instance();

		if ( $degraded_msg !== '' ) {
			// Fail-open: emit degraded message without LLM call.
			$final_seq++;
			$sse->emit( 'final_token', [
				'trace_id' => $trace_id,
				'seq'      => $final_seq,
				'delta'    => $degraded_msg,
				'len'      => mb_strlen( $degraded_msg ),
			] );
			$final_text = $degraded_msg;
			$final = [
				'success'   => true,
				'answer_md' => $final_text,
				'model'     => '',
				'tokens'    => 0,
				'ms'        => 0,
				'fallback'  => $degraded,
			];
		} else {
			// [2026-06-10 Johnny Chu] ASTRO-CITE 4 — prepend birth block to passages_md
			// so LLM always sees subject birth data even when transit passages are long.
			$birth_block = '';
			$birth = isset( $subject_payload['birth'] ) ? $subject_payload['birth'] : array();
			if ( ! empty( $birth['date'] ) ) {
				$birth_block .= '**Ngày sinh:** ' . (string) $birth['date'] . "\n";
			}
			if ( ! empty( $birth['time'] ) ) {
				$birth_block .= '**Giờ sinh:** ' . (string) $birth['time'] . "\n";
			}
			if ( ! empty( $birth['place'] ) ) {
				$birth_block .= '**Nơi sinh:** ' . (string) $birth['place'] . "\n";
			}
			if ( $birth_block !== '' && empty( $subject_payload['transit_context_md'] ) ) {
				$passages_md = "## Thông tin chủ thể\n" . $birth_block . "\n" . $passages_md;
			}

			$_day_count = isset( $day_eval['rows'] ) && is_array( $day_eval['rows'] )
				? max( 1, count( $day_eval['rows'] ) )
				: 1;
			$_temporal_signal_detected = ! empty( $time_payload['temporal_signal_detected'] );
			$_deep_analysis_requested = ! empty( $time_payload['deep_analysis_requested'] );
			$_deep_focus_domains = isset( $time_payload['deep_focus_domains'] ) && is_array( $time_payload['deep_focus_domains'] )
				? array_values( array_unique( array_filter( array_map( 'sanitize_key', $time_payload['deep_focus_domains'] ) ) ) )
				: array();
			$_subject_line_min = $_temporal_signal_detected ? 20 : 40;
			$_subject_line_max = $_temporal_signal_detected ? 24 : 50;
			$_subject_mode = $_temporal_signal_detected ? 'temporal_signal' : 'subject_default_tomorrow';
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — explicit deep-analysis
			// ask must widen subject section and keep domain-by-domain explanations.
			if ( $_deep_analysis_requested ) {
				if ( $_temporal_signal_detected ) {
					$_subject_line_min = max( $_subject_line_min, 28 );
					$_subject_line_max = max( $_subject_line_max, 36 );
					$_subject_mode = 'temporal_signal_deep';
				} else {
					$_subject_line_min = max( $_subject_line_min, 50 );
					$_subject_line_max = max( $_subject_line_max, 64 );
					$_subject_mode = 'subject_default_tomorrow_deep';
				}
			}
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A15 — widen astro final-compose budget (+50%).
			$_compose_max_tokens = min( 9000, max( 3300, ( $_day_count * 930 ) + 1350 ) );
			$_compose_ans_cap    = min( 7500, max( 1350, ( $_day_count * 975 ) + 675 ) );
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — when prompt has no
			// temporal cue, force subject-first answer depth (40-50 lines).
			if ( ! $_temporal_signal_detected ) {
				$_compose_max_tokens = min( 9000, max( $_compose_max_tokens, 5200 ) );
				$_compose_ans_cap    = min( 7500, max( $_compose_ans_cap, 2800 ) );
			}
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — deep mode needs
			// higher generation budget to avoid collapsing the subject block.
			if ( $_deep_analysis_requested ) {
				$_compose_max_tokens = min( 9000, max( $_compose_max_tokens, 6800 ) );
				$_compose_ans_cap    = min( 7500, max( $_compose_ans_cap, 3600 ) );
			}

			$astro_opts = array_merge( $opts, [
				'web_mode' => 'off', // prevent recursion in composer
				// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A15 — increase creative breadth (+50%).
				'final_compose_temperature' => 1.08,
				'final_compose_max_tokens'  => $_compose_max_tokens,
				'final_compose_ans_cap'     => $_compose_ans_cap,
				'astro_subject_mode'        => $_subject_mode,
				'astro_subject_line_min'    => $_subject_line_min,
				'astro_subject_line_max'    => $_subject_line_max,
				'astro_temporal_signal_detected' => $_temporal_signal_detected,
				'astro_deep_analysis_requested' => $_deep_analysis_requested,
				'astro_focus_domains'       => $_deep_focus_domains,
				// [2026-06-04 Johnny Chu] PHASE-A C.3b — đưa NGUYÊN transit markdown
				// (8KB+) vào prompt, không để Final_Composer cắt còn ANS_TRUNC=1200.
				'extra_context_md'    => $passages_md,
				// [2026-06-10 Johnny Chu] ASTRO-CITE 4 — citation instruction moved to
				// Final Composer system prompt (rule #10) when [astro: tokens detected.
				'extra_context_label' => 'DỮ LIỆU CHIÊM TINH (transit + natal) — đọc kỹ và diễn giải cho user',
				// [2026-07-07 Johnny Chu] HOTFIX — keepalive evidence during long astro compose.
				'on_keepalive' => static function () use ( $sse, $trace_id, &$final_keepalive_seq ) {
					$final_keepalive_seq++;
					$sse->emit( 'final_keepalive', array(
						'trace_id' => $trace_id,
						'seq'      => $final_keepalive_seq,
						'mode'     => 'astro',
						'status'   => 'still_running',
					) );
					$sse->maybe_heartbeat();
				},
			] );
			$astro_log( 'compose_context', array(
				'passages_md_bytes' => strlen( $passages_md ),
				'period'            => $period,
				'day_count'         => $_day_count,
				'max_tokens'        => $_compose_max_tokens,
				'ans_cap'           => $_compose_ans_cap,
				'temporal_signal_detected' => $_temporal_signal_detected,
				'deep_analysis_requested' => $_deep_analysis_requested,
				'deep_focus_domains'  => $_deep_focus_domains,
				'subject_line_min'  => $_subject_line_min,
				'subject_line_max'  => $_subject_line_max,
				'subject_mode'      => $_subject_mode,
			) );
			$final = $composer->compose_stream(
				$trace_id,
				$prompt,
				$synth_fake,
				[],
				$astro_opts,
				function ( $delta, $accumulated ) use ( $sse, $trace_id, &$final_seq ) {
					$final_seq++;
					$sse->emit( 'final_token', [
						'trace_id' => $trace_id,
						'seq'      => $final_seq,
						'delta'    => (string) $delta,
						'len'      => mb_strlen( (string) $accumulated ),
					] );
				}
			);
			$final_text = (string) ( $final['answer_md'] ?? '' );
		}

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — append verified
		// primary source block (natal + day transit URLs) from trusted payload.
		$final_text = $this->append_astro_primary_source_block(
			$final_text,
			$subject_payload,
			isset( $day_eval['rows'] ) && is_array( $day_eval['rows'] ) ? $day_eval['rows'] : array(),
			$transit_payload,
			$passages
		);

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — append deterministic
		// citation basis so user sees where each day conclusion comes from.
		$final_text = $this->append_astro_citation_basis_block(
			$final_text,
			isset( $day_eval['rows'] ) && is_array( $day_eval['rows'] ) ? $day_eval['rows'] : array(),
			$transit_payload
		);
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — explicit token namespace legend: memory vs astro.
		$final_text = $this->append_astro_citation_namespace_block( $final_text );
		$final['answer_md'] = $final_text;

		$final_ms = (int) ( ( microtime( true ) - $final_t0 ) * 1000 );

		// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — Layer 1 hub quota hit inside Final Composer.
		// Emit SSE error BEFORE final_done so FE receives quota_exhausted=true + hub_quota=true
		// and can render QuotaErrorBanner instead of StreamErrorBanner.
		// [2026-06-10 Johnny Chu] R-QUOTA-KEY — forward usage counters for QuotaErrorBanner display.
		if ( ! empty( $final['quota_exhausted'] ) ) {
			$hub_tier    = isset( $final['tier'] )          ? (string) $final['tier']         : 'free';
			$period      = isset( $final['quota_period'] )  ? (string) $final['quota_period']  : 'day';
			$period_vi   = $period === 'month' ? 'tháng' : 'ngày';
			$used_req    = isset( $final['used_requests'] )    ? (int)   $final['used_requests']    : 0;
			$cap_req     = isset( $final['cap_requests_day'] ) ? (int)   $final['cap_requests_day'] : 0;
			$used_usd    = isset( $final['used_usd'] )         ? (float) $final['used_usd']         : 0.0;
			$cap_usd     = isset( $final['cap_usd'] )          ? (float) $final['cap_usd']          : 0.0;
			$reset_at    = isset( $final['reset_at'] )         ? (string)$final['reset_at']         : '';
			$master_lvl  = isset( $final['master_level'] )     ? (string)$final['master_level']     : $hub_tier;
			$sse->emit( 'error', array(
				'code'              => 'quota_exhausted',
				'message'           => 'Hệ thống AI đã vượt giới hạn ' . $period_vi . ' (Layer 1 Hub). Liên hệ admin để nâng cấp gói.',
				'quota_exceeded'    => true,
				'hub_quota'         => true,
				'plan'              => $master_lvl,
				'plan_label'        => strtoupper( $master_lvl ) . ' (Hub)',
				'feature'           => 'llm_chat',
				'used_requests'     => $used_req,
				'cap_requests_day'  => $cap_req,
				'used_usd'          => round( $used_usd, 4 ),
				'cap_usd'           => round( $cap_usd, 4 ),
				'limit'             => $cap_usd > 0 ? $cap_usd : $cap_req,
				'used'              => $cap_usd > 0 ? $used_usd : $used_req,
				'resets_at'         => $reset_at,
				'period'            => $period,
				'hint'              => 'Liên hệ admin site để nâng cấp gói BizCity Hub.',
				'help_code'         => 'hub_quota_exhausted',
			) );
		}

		$sse->emit( 'final_done', [
			'trace_id'  => $trace_id,
			'answer_md' => $final_text,
			'tokens'    => (int)    ( $final['tokens']   ?? 0 ),
			'model'     => (string) ( $final['model']    ?? '' ),
			'ms'        => $final_ms,
			'chunks'    => $final_seq,
			'fallback'  => (string) ( $final['fallback'] ?? '' ),
			'success'   => ! empty( $final['success'] ),
			'mode'      => 'astro',
			'degraded'  => ( $degraded !== '' ),
		] );

		/* --- L4.7 Memory_Writer --- */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) && $final_text !== '' ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					[
						'user_id'    => $user_id,
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
					]
				);
				$write_payload = [
					'trace_id'   => $trace_id,
					'persisted'  => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'       => (string) ( $mw['mode']       ?? '' ),
					'ops'        => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms' => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_mode][memory_write][error] ' . $e->getMessage() );
				}
			}
		}

		/* --- [2026-06-04 Johnny Chu] PHASE-A C.3c — L4.8 Mode Context Memory ---
		 * Persist 1 context summary của lượt astro vào memory tier GẮN session_id
		 * + provenance source_url. Reusable standard cho mọi mode (xem
		 * core/docs/CORE-PHASE-A-MODE-MEMORY.md). Fail-OPEN. */
		if ( class_exists( 'BizCity_TwinBrain_Mode_Memory' ) && $passages_md !== '' ) {
			try {
				$mm_source_url = (string) ( isset( $transit_payload['source_url'] ) ? $transit_payload['source_url'] : '' );
				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — key by coachee+window
				// to prevent wrong-subject mode_context bleed across astro turns.
				$mm_coachee_id = (int) ( $subject_payload['coachee_id'] ?? 0 );
				$mm_from       = (string) ( $time_payload['window']['from'] ?? '' );
				$mm_to         = (string) ( $time_payload['window']['to'] ?? '' );
				$mm_key_hint   = 'coachee:' . $mm_coachee_id . '|period:' . $period;
				if ( $mm_from !== '' || $mm_to !== '' ) {
					$mm_key_hint .= '|window:' . $mm_from . '..' . $mm_to;
				}
				$mm_summary    = function_exists( 'mb_substr' )
					? mb_substr( $passages_md, 0, 1200 )
					: substr( $passages_md, 0, 1200 );
				$mm = BizCity_TwinBrain_Mode_Memory::instance()->persist( array(
					'mode'       => 'astro',
					'trace_id'   => $trace_id,
					'user_id'    => $user_id,
					'session_id' => (string) ( $opts['session_id'] ?? '' ),
					'title'      => 'Chiêm tinh — quá cảnh (' . $period . ') · coachee #' . $mm_coachee_id,
					'summary'    => $mm_summary,
					'source_url' => $mm_source_url,
					'source'     => (string) ( isset( $transit_payload['source'] ) ? $transit_payload['source'] : '' ),
					'period'     => $period,
					'fetched_at' => (string) ( isset( $transit_payload['fetched_at'] ) ? $transit_payload['fetched_at'] : '' ),
					'key_hint'   => $mm_key_hint,
					'extra'      => array(
						'coachee_id' => $mm_coachee_id,
						'window'     => array( 'from' => $mm_from, 'to' => $mm_to ),
					),
				) );
				$this->emit_event( 'mode_memory_persisted', array_merge(
					array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ),
					$mm
				) );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_mode][mode_memory][error] ' . $e->getMessage() );
				}
			}
		}

		/* --- cite_resolved / brain_synthesize / assistant_message --- */
		// [2026-06-10 Johnny Chu] ASTRO-CITE 3 — extract astro citation tokens from LLM answer.
		$astro_citations = $this->extract_astro_citations( $final_text, $passages, $subject_payload );
		$sse->emit( 'cite_resolved', [
			'trace_id'         => $trace_id,
			'cited_entity_ids' => [],
			'cited_passages'   => [],
			'astro_citations'  => $astro_citations,
		] );

		$synthesis = array_merge( $synth_fake, [ 'answer_md' => $final_text ] );

		$this->emit_event( 'brain_synthesize', [
			'trace_id'            => $trace_id,
			'surface'             => self::SURFACE,
			'model'               => (string) ( $final['model']  ?? '' ),
			'tokens'              => (int)    ( $final['tokens'] ?? 0 ),
			'ms'                  => $final_ms,
			'consensus_count'     => count( $synth_fake['consensus'] ),
			'tensions_count'      => 0,
			'citation_count'      => 0,
			'cited_entity_count'  => 0,
			'cited_passage_count' => count( $passages ),
			'fallback'            => $degraded,
			'answers_in'          => count( $passages ),
			'streaming'           => true,
			'mode'                => 'astro',
			'cap_source'          => $cap_source,
		] );
		$this->emit_event( 'assistant_message', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'text'               => $final_text,
			'synthesis_metadata' => [
				'consensus'        => $synth_fake['consensus'],
				'tensions'         => [],
				'recommendation'   => '',
				'citations'        => [],
				'citation_count'   => 0,
				'cited_entity_ids' => [],
				'cited_passages'   => [],
				'model'            => (string) ( $final['model']  ?? '' ),
				'tokens'           => (int)    ( $final['tokens'] ?? 0 ),
				'ms'               => $final_ms,
				'fallback'         => $degraded,
				'streaming'        => true,
				'mode'             => 'astro',
				'final_compose'    => [
					'tokens'   => (int)    ( $final['tokens']   ?? 0 ),
					'model'    => (string) ( $final['model']    ?? '' ),
					'ms'       => $final_ms,
					'chunks'   => $final_seq,
					'fallback' => (string) ( $final['fallback'] ?? '' ),
					'success'  => ! empty( $final['success'] ),
				],
			],
			// [2026-06-04 Johnny Chu] BS-12 — astro turn replay snapshot.
			'result_snapshot'      => $this->build_turn_snapshot( $trace_id, $opts, array(
				'web_mode'   => 'astro',
				'mode'       => 'astro',
				'synthesis'  => array(
					'answer_md' => (string) ( $synth_fake['answer_md_synth'] ?? '' ),
					'consensus' => $synth_fake['consensus'],
					'tensions'  => array(),
				),
				'final'      => array(
					'answer_md' => $final_text,
					'chunks'    => $final_seq,
					'tokens'    => (int) ( $final['tokens'] ?? 0 ),
					'model'     => (string) ( $final['model'] ?? '' ),
					'ms'        => $final_ms,
					'fallback'  => (string) ( $final['fallback'] ?? '' ),
					'success'   => ! empty( $final['success'] ),
				),
				'cited_passages' => $passages,
				'duration_ms'    => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
			) ),
		] );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		return [
			'ok'               => true,
			'synthesis'        => $synthesis,
			'answers'          => $passages,
			'cited_entity_ids' => [],
			'cited_passages'   => [],
			'duration_ms'      => $wall_ms,
			'mode'             => 'astro',
			'cap_source'       => $cap_source,
			'_degraded'        => $degraded !== '' ? $degraded : false,
		];
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — relation-profile
	 * pipeline (subject + partner assessment), bypass day-by-day transit flow.
	 */
	private function stream_astro_relation_mode(
		string $trace_id,
		string $prompt,
		BizCity_Twin_SSE_Writer $sse,
		array $opts,
		float $wall_t0,
		array $subject_payload,
		array $astro_row,
		array $passages,
		string $cap_source,
		string $degraded,
		callable $astro_log,
		int $user_id
	): array {
		$relation_lenses = isset( $astro_row['relation_lenses'] ) && is_array( $astro_row['relation_lenses'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $astro_row['relation_lenses'] ) ) ) )
			: array();
		if ( empty( $relation_lenses ) ) {
			$relation_lenses = array( 'work', 'love', 'business', 'hr' );
		}

		$source_marker = sanitize_key( (string) ( $astro_row['relation_source_marker'] ?? $opts['source_marker'] ?? 'twinbrain_chat' ) );
		if ( ! in_array( $source_marker, array( 'twinbrain_chat', 'zalobot_chat', 'automation' ), true ) ) {
			$source_marker = 'twinbrain_chat';
		}

		$intent_payload = array(
			'trace_id' => $trace_id,
			'analysis_mode' => 'relation_profile',
			'relation_target_name_hint' => (string) ( $astro_row['relation_target_name_hint'] ?? '' ),
			'relation_target_coachee_id' => (int) ( $astro_row['relation_target_coachee_id'] ?? 0 ),
			'relation_lenses' => $relation_lenses,
			'relation_requires_transit_sync' => ! empty( $astro_row['relation_requires_transit_sync'] ),
			'source' => $source_marker,
		);
		$sse->emit( 'astro_relation_intent_detected', $intent_payload );
		$this->emit_event( 'astro_relation_intent_detected', array_merge(
			array( 'surface' => self::SURFACE ),
			$intent_payload
		) );
		$astro_log( 'relation_intent', $intent_payload );

		$assessment = array();
		if ( class_exists( 'BizCity_TwinBrain_Astro_Relation_Assessment_Service' ) ) {
			try {
				$assessment = BizCity_TwinBrain_Astro_Relation_Assessment_Service::instance()->assess_by_query(
					$prompt,
					array(
						'user_id' => $user_id,
						'trace_id' => $trace_id,
						'chat_id' => (string) ( $opts['chat_id'] ?? '' ),
						'subject_coachee_id' => (int) ( $subject_payload['coachee_id'] ?? 0 ),
						'partner_coachee_id' => (int) ( $astro_row['relation_target_coachee_id'] ?? 0 ),
						'partner_name_hint' => (string) ( $astro_row['relation_target_name_hint'] ?? '' ),
						'relation_lenses' => $relation_lenses,
						'source_marker' => $source_marker,
						'sync_days' => max( 1, (int) ( $astro_row['num_days'] ?? 7 ) ),
						'start_offset' => max( 0, (int) ( $astro_row['start_offset'] ?? 1 ) ),
						'surface' => 'twinbrain_chat',
					)
				);
			} catch ( \Throwable $e ) {
				$assessment = array(
					'success' => false,
					'_degraded' => 'relation_assessment_exception',
					'message' => $e->getMessage(),
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_relation][assessment][error] ' . $e->getMessage() );
				}
			}
		} else {
			$assessment = array(
				'success' => false,
				'_degraded' => 'relation_service_missing',
				'message' => 'Relation assessment service chua duoc load.',
			);
		}

		$astro_log( 'relation_assessment', array(
			'success' => ! empty( $assessment['success'] ),
			'sync_status' => (string) ( $assessment['sync_status'] ?? '' ),
			'_degraded' => (string) ( $assessment['_degraded'] ?? '' ),
		) );

		$composed = array();
		if ( ! empty( $assessment['success'] ) && class_exists( 'BizCity_TwinBrain_Astro_Relation_Composer' ) ) {
			try {
				$composed = BizCity_TwinBrain_Astro_Relation_Composer::instance()->compose(
					$assessment,
					array(
						'query' => $prompt,
						'trace_id' => $trace_id,
						'surface' => 'twinbrain_chat',
					)
				);
			} catch ( \Throwable $e ) {
				$composed = array(
					'success' => false,
					'_degraded' => 'relation_compose_exception',
					'message' => $e->getMessage(),
				);
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_relation][compose][error] ' . $e->getMessage() );
				}
			}
		} elseif ( ! empty( $assessment['success'] ) ) {
			$composed = array(
				'success' => false,
				'_degraded' => 'relation_composer_missing',
				'message' => 'Relation composer chua duoc load.',
			);
		}

		$final_text = '';
		$relation_degraded = '';
		if ( ! empty( $assessment['success'] ) && ! empty( $composed['success'] ) ) {
			$final_text = (string) ( $composed['final_answer_md'] ?? '' );
			if ( $final_text === '' ) {
				$final_text = trim( (string) ( $composed['subject_block_md'] ?? '' ) . "\n\n" . (string) ( $composed['relation_block_md'] ?? '' ) );
			}
			$relation_degraded = (string) ( $assessment['_degraded'] ?? $composed['_degraded'] ?? '' );
		}
		if ( $final_text === '' ) {
			$reason = (string) ( $assessment['_degraded'] ?? $composed['_degraded'] ?? 'relation_assessment_failed' );
			$msg = (string) ( $assessment['message'] ?? $composed['message'] ?? '' );
			$astro_setup_url = function_exists( 'home_url' ) ? (string) home_url( '/astro/' ) : '/astro/';
			// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-3 — deep-link My Astro
			// directly to Subjects create flow with optional prefilled partner name.
			$astro_subjects_url = rtrim( $astro_setup_url, '/' ) . '/#/subjects';
			$astro_create_url   = $astro_subjects_url . '?create=1';
			$partner_name_hint = trim( (string) ( $astro_row['relation_target_name_hint'] ?? '' ) );
			$astro_partner_create_url = $astro_create_url;
			if ( $partner_name_hint !== '' ) {
				$astro_partner_create_url .= '&full_name=' . rawurlencode( $partner_name_hint );
			}
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-2 — actionable
			// fallback UX for missing relation profile instead of generic error text.
			switch ( $reason ) {
				case 'relation_subject_missing':
					$final_text = 'Ban chua co ho so chu the de bat dau danh gia moi quan he.';
					$final_text .= "\n\n-> Bam [astro:create_profile#dialog] de tao nhanh ho so chinh chu ngay trong TwinChat.";
					$final_text .= "\n\nHoac mo [My Astro](" . $astro_create_url . ') de tao/sua ho so day du.';
					break;
				case 'relation_partner_missing':
					$partner_label = $partner_name_hint !== '' ? ( '"' . $partner_name_hint . '"' ) : 'doi tac';
					$final_text = 'Chua tim thay ho so ' . $partner_label . ' trong he thong.';
					$final_text .= "\n\n-> Vui long tao ho so doi tac trong [My Astro](" . $astro_partner_create_url . ') roi gui lai cau hoi.';
					break;
				case 'relation_service_missing':
					$final_text = 'Tinh nang relation dang tam thoi chua san sang. Vui long thu lai sau it phut.';
					$final_text .= "\n\nNeu can, ban co the mo [My Astro](" . $astro_setup_url . ') de kiem tra profile va du lieu transit.';
					break;
				default:
					$final_text = 'Xin loi, hien tai chua danh gia duoc do hop cho cap profile nay.';
					if ( $msg !== '' ) {
						$final_text .= "\n\nChi tiet: " . $msg;
					}
					$final_text .= "\n\nGoi y: kiem tra lai profile doi tac va bo sung du lieu natal/transit roi thu lai.";
					break;
			}
			$relation_degraded = $reason;
		}

		$citation_tokens = isset( $assessment['citations'] ) && is_array( $assessment['citations'] )
			? $assessment['citations']
			: array();
		$astro_citations = array();
		foreach ( $citation_tokens as $token ) {
			$token = (string) $token;
			if ( preg_match( '/^\[astro:([a-z_\-]+)#([^\]]+)\]$/', $token, $m ) ) {
				$astro_citations[] = array(
					'token' => $token,
					'kind' => 'astro',
					'type' => (string) $m[1],
					'url' => (string) $m[2],
				);
			}
		}

		$sse->emit( 'perspectives_started', array(
			'trace_id' => $trace_id,
			'count' => 1,
			'mode' => 'astro_relation',
		) );
		$sse->emit( 'perspectives_done', array(
			'trace_id' => $trace_id,
			'count' => 1,
			'ms' => 0,
			'mode' => 'astro_relation',
		) );

		$tool_decision = array( 'decision' => 'no_op', 'reason' => 'astro_relation_mode', 'tool' => '' );
		$sse->emit( 'tool_decided', array_merge( array( 'trace_id' => $trace_id ), $tool_decision ) );
		$this->emit_event( 'tool_decided', array_merge(
			array( 'trace_id' => $trace_id, 'surface' => self::SURFACE ),
			$tool_decision
		) );

		$sse->emit( 'synthesis_started', array( 'trace_id' => $trace_id, 'mode' => 'astro_relation' ) );
		$synth_fake = array(
			'answer_md' => $final_text,
			'consensus' => array( 'Danh gia relation profile theo 4 lens: work/love/business/hr' ),
			'tensions' => array(),
			'recommendation' => '',
			'citations' => array(),
			'tokens' => 0,
			'model' => '',
		);
		$sse->emit( 'synthesis_done', array(
			'trace_id' => $trace_id,
			'answer_md' => mb_substr( $final_text, 0, 240 ),
			'consensus' => $synth_fake['consensus'],
			'tensions' => array(),
			'recommendation' => '',
			'citations' => array(),
			'tokens' => (int) ( $composed['tokens'] ?? 0 ),
			'ms' => 0,
			'mode' => 'astro_relation',
		) );

		$final_t0 = microtime( true );
		$sse->emit( 'final_started', array(
			'trace_id' => $trace_id,
			'mode' => 'astro_relation',
			'degraded' => ( $relation_degraded !== '' ),
		) );
		$final_seq = 1;
		$sse->emit( 'final_token', array(
			'trace_id' => $trace_id,
			'seq' => $final_seq,
			'delta' => $final_text,
			'len' => mb_strlen( $final_text ),
		) );
		$final_ms = (int) ( ( microtime( true ) - $final_t0 ) * 1000 );
		$sse->emit( 'final_done', array(
			'trace_id' => $trace_id,
			'answer_md' => $final_text,
			'tokens' => (int) ( $composed['tokens'] ?? 0 ),
			'model' => (string) ( $composed['model'] ?? '' ),
			'ms' => $final_ms,
			'chunks' => $final_seq,
			'fallback' => (string) ( $composed['fallback'] ?? $relation_degraded ),
			'success' => true,
			'mode' => 'astro_relation',
			'degraded' => ( $relation_degraded !== '' ),
		) );

		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) && $final_text !== '' ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					array(
						'user_id' => $user_id,
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
					)
				);
				$write_payload = array(
					'trace_id' => $trace_id,
					'persisted' => (int) ( $mw['persisted'] ?? 0 ),
					'mode' => (string) ( $mw['mode'] ?? '' ),
					'ops' => (array) ( $mw['ops'] ?? array() ),
					'latency_ms' => (int) ( $mw['latency_ms'] ?? 0 ),
				);
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro_relation][memory_write][error] ' . $e->getMessage() );
				}
			}
		}

		$sse->emit( 'cite_resolved', array(
			'trace_id' => $trace_id,
			'cited_entity_ids' => array(),
			'cited_passages' => array(),
			'astro_citations' => $astro_citations,
		) );

		$this->emit_event( 'brain_synthesize', array(
			'trace_id' => $trace_id,
			'surface' => self::SURFACE,
			'model' => (string) ( $composed['model'] ?? '' ),
			'tokens' => (int) ( $composed['tokens'] ?? 0 ),
			'ms' => $final_ms,
			'consensus_count' => count( $synth_fake['consensus'] ),
			'tensions_count' => 0,
			'citation_count' => count( $astro_citations ),
			'cited_entity_count' => 0,
			'cited_passage_count' => count( $passages ),
			'fallback' => $relation_degraded,
			'answers_in' => count( $passages ),
			'streaming' => true,
			'mode' => 'astro_relation',
			'cap_source' => $cap_source,
		) );
		$this->emit_event( 'assistant_message', array(
			'trace_id' => $trace_id,
			'surface' => self::SURFACE,
			'text' => $final_text,
			'synthesis_metadata' => array(
				'consensus' => $synth_fake['consensus'],
				'tensions' => array(),
				'recommendation' => '',
				'citations' => array(),
				'citation_count' => count( $astro_citations ),
				'cited_entity_ids' => array(),
				'cited_passages' => array(),
				'model' => (string) ( $composed['model'] ?? '' ),
				'tokens' => (int) ( $composed['tokens'] ?? 0 ),
				'ms' => $final_ms,
				'fallback' => $relation_degraded,
				'streaming' => true,
				'mode' => 'astro_relation',
				'final_compose' => array(
					'tokens' => (int) ( $composed['tokens'] ?? 0 ),
					'model' => (string) ( $composed['model'] ?? '' ),
					'ms' => $final_ms,
					'chunks' => $final_seq,
					'fallback' => (string) ( $composed['fallback'] ?? $relation_degraded ),
					'success' => true,
				),
			),
			'result_snapshot' => $this->build_turn_snapshot( $trace_id, $opts, array(
				'web_mode' => 'astro',
				'mode' => 'astro_relation',
				'synthesis' => array(
					'answer_md' => (string) $final_text,
					'consensus' => $synth_fake['consensus'],
					'tensions' => array(),
				),
				'final' => array(
					'answer_md' => $final_text,
					'chunks' => $final_seq,
					'tokens' => (int) ( $composed['tokens'] ?? 0 ),
					'model' => (string) ( $composed['model'] ?? '' ),
					'ms' => $final_ms,
					'fallback' => (string) ( $composed['fallback'] ?? $relation_degraded ),
					'success' => true,
				),
				'cited_passages' => $passages,
				'relation' => array(
					'subject' => isset( $assessment['subject'] ) ? $assessment['subject'] : array(),
					'partner' => isset( $assessment['partner'] ) ? $assessment['partner'] : array(),
					'lenses' => $relation_lenses,
					'sync_status' => (string) ( $assessment['sync_status'] ?? '' ),
				),
				'duration_ms' => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
			) ),
		) );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		return array(
			'ok' => true,
			'synthesis' => $synth_fake,
			'answers' => $passages,
			'cited_entity_ids' => array(),
			'cited_passages' => array(),
			'duration_ms' => $wall_ms,
			'mode' => 'astro_relation',
			'cap_source' => $cap_source,
			'_degraded' => $relation_degraded !== '' ? $relation_degraded : false,
		);
	}

	/* =================================================================
	 *  [2026-06-04 Johnny Chu] PHASE-A C.3b — Astro FE-spec payload builders
	 *  ───────────────────────────────────────────────────────────────────
	 *  These translate runtime data into the 4 SSE event shapes consumed
	 *  by BrainThinkingTimeline.tsx (FE sprint C.3a). See spec:
	 *    core/docs/CORE-PHASE-A-ASTRO-MODE.md §3 (event schemas)
	 *  Each helper is fail-safe: returns a populated payload even on
	 *  partial / missing data, with `_degraded` set to a canonical reason
	 *  bucket so FE renders amber instead of hiding the row.
	 * ================================================================ */

	/**
	 * Build payload for `astro_subject_resolved`.
	 *
	 * @param int $user_id Logged-in WP user id.
	 * @return array Subject payload per spec §3.1.
	 */
	private function build_astro_subject_payload( $user_id ) {
		// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — unified subject profile
		// service shared with automation action.run_astro.
		if ( class_exists( 'BizCity_TwinBrain_Astro_Subject_Profile_Service' ) ) {
			$resolved = BizCity_TwinBrain_Astro_Subject_Profile_Service::instance()->resolve_by_user( (int) $user_id );
			if ( is_array( $resolved ) && ! empty( $resolved['coachee_id'] ) ) {
				return array(
					'user_id'            => (int) ( $resolved['user_id'] ?? (int) $user_id ),
					'coachee_id'         => (int) ( $resolved['coachee_id'] ?? 0 ),
					'coachee_name'       => (string) ( $resolved['coachee_name'] ?? '' ),
					'systems_available'  => isset( $resolved['systems_available'] ) && is_array( $resolved['systems_available'] )
						? $resolved['systems_available']
						: array(),
					'birth'              => isset( $resolved['birth'] ) && is_array( $resolved['birth'] )
						? $resolved['birth']
						: array(),
					'natal_chart_url'    => (string) ( $resolved['natal_chart_url'] ?? '' ),
					'natal_profile_md'   => (string) ( $resolved['natal_profile_md'] ?? '' ),
					'transit_context_md' => (string) ( $resolved['transit_context_md'] ?? '' ),
					'_degraded'          => isset( $resolved['_degraded'] ) ? $resolved['_degraded'] : null,
				);
			}
		}

		$user_id      = (int) $user_id;
		$coachee_id   = 0;
		$coachee_name = '';
		$birth        = array();
		$systems      = array();
		$degraded     = null;

		if ( function_exists( 'bccm_get_self_coachee' ) || function_exists( 'bccm_get_or_create_user_coachee' ) ) {
			try {
				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A5 — resolve "tôi"
				// from canonical self profile first (is_self=1), then fallback create/get.
				$row = null;
				if ( function_exists( 'bccm_get_self_coachee' ) ) {
					$row = bccm_get_self_coachee( $user_id );
				}
				if ( ! is_array( $row ) && function_exists( 'bccm_get_or_create_user_coachee' ) ) {
					$row = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
				}
				if ( is_array( $row ) ) {
					$coachee_id   = (int) ( isset( $row['id'] ) ? $row['id'] : 0 );
					$coachee_name = (string) ( isset( $row['full_name'] ) ? $row['full_name'] : '' );
					// Birth fields — best-effort from common column names.
					$col_map = array(
						'dob'         => 'date',
						'birth_date'  => 'date',
						'birth_time'  => 'time',
						'birth_place' => 'place',
						'birth_tz'    => 'tz',
					);
					foreach ( $col_map as $col => $key ) {
						if ( ! empty( $row[ $col ] ) && empty( $birth[ $key ] ) ) {
							$birth[ $key ] = (string) $row[ $col ];
						}
					}
				} elseif ( is_numeric( $row ) ) {
					$coachee_id = (int) $row;
				}
			} catch ( \Throwable $e ) {
				$degraded = 'astro_subject_lookup_exception';
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][astro][subject][error] ' . $e->getMessage() );
				}
			}
		} else {
			$degraded = 'astro_provider_not_registered';
		}

		if ( $degraded === null && $coachee_id <= 0 ) {
			$degraded = 'astro_coachee_not_found';
		}
		if ( $degraded === null && empty( $birth['date'] ) ) {
			$degraded = 'astro_birth_data_missing';
		}

		// Systems availability — minimal heuristic: birth date present
		// implies western + vedic charts can be computed.
		if ( ! empty( $birth['date'] ) ) {
			$systems = array( 'western', 'vedic' );
		}

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A9 — prefer detailed western report URL before wheel-only page.
		$natal_chart_url = '';
		if ( $coachee_id > 0 && function_exists( 'bcpro_get_astro_public_url' ) ) {
			$natal_chart_url = (string) bcpro_get_astro_public_url( $coachee_id, 'western' );
		}
		if ( $natal_chart_url === '' && $coachee_id > 0 && function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$natal_chart_url = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}

		return array(
			'user_id'           => $user_id,
			'coachee_id'        => $coachee_id,
			'coachee_name'      => $coachee_name,
			'systems_available' => $systems,
			'birth'             => $birth,
			'natal_chart_url'   => $natal_chart_url,
			'_degraded'         => $degraded,
		);
	}

	/**
	 * Build subject payload for a SPECIFIC coachee resolved from a query name.
	 *
	 * Used when the Astro engine finds a named person in the query (e.g. "Kim Thoa")
	 * and resolves them to a coachee_id different from the logged-in user's default.
	 * Emitted as a second `astro_subject_resolved` to update FE step 1 transparency.
	 *
	 * [2026-06-10 Johnny Chu] HOTFIX — step-1 transparency re-emit.
	 *
	 * @param int    $coachee_id    Resolved coachee id.
	 * @param string $name_extracted Name hint the LLM extracted from the query.
	 * @param int    $user_id       Logged-in WP user id (for audit).
	 * @return array Subject payload including name_extracted + resolution_source.
	 */
	private function build_astro_subject_payload_for_coachee( $coachee_id, $name_extracted, $user_id ) {
		// [2026-07-10 Johnny Chu] PHASE-FAA2-TWINBRAIN — use unified subject
		// profile service for explicit coachee path.
		if ( class_exists( 'BizCity_TwinBrain_Astro_Subject_Profile_Service' ) ) {
			$resolved = BizCity_TwinBrain_Astro_Subject_Profile_Service::instance()->resolve_by_coachee(
				(int) $coachee_id,
				(int) $user_id,
				(string) $name_extracted
			);
			if ( is_array( $resolved ) && ! empty( $resolved['coachee_id'] ) ) {
				return array(
					'user_id'            => (int) ( $resolved['user_id'] ?? (int) $user_id ),
					'coachee_id'         => (int) ( $resolved['coachee_id'] ?? 0 ),
					'coachee_name'       => (string) ( $resolved['coachee_name'] ?? '' ),
					'systems_available'  => isset( $resolved['systems_available'] ) && is_array( $resolved['systems_available'] )
						? $resolved['systems_available']
						: array(),
					'birth'              => isset( $resolved['birth'] ) && is_array( $resolved['birth'] )
						? $resolved['birth']
						: array(),
					'natal_chart_url'    => (string) ( $resolved['natal_chart_url'] ?? '' ),
					'natal_profile_md'   => (string) ( $resolved['natal_profile_md'] ?? '' ),
					'transit_context_md' => (string) ( $resolved['transit_context_md'] ?? '' ),
					'name_extracted'     => (string) $name_extracted,
					'resolution_source'  => 'name_from_query',
					'_degraded'          => isset( $resolved['_degraded'] ) ? $resolved['_degraded'] : null,
				);
			}
		}

		global $wpdb;
		$coachee_id     = (int) $coachee_id;
		$user_id        = (int) $user_id;
		$coachee_name   = '';
		$birth          = array();
		$systems        = array();
		$degraded       = null;

		if ( function_exists( 'bccm_tables' ) ) {
			$t = bccm_tables();
			if ( ! empty( $t['profiles'] ) ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM `{$t['profiles']}` WHERE id = %d LIMIT 1",
						$coachee_id
					),
					ARRAY_A
				);
				if ( is_array( $row ) ) {
					$coachee_name = (string) ( isset( $row['full_name'] ) ? $row['full_name'] : '' );
					$col_map = array(
						'dob'         => 'date',
						'birth_date'  => 'date',
						'birth_time'  => 'time',
						'birth_place' => 'place',
						'birth_tz'    => 'tz',
					);
					foreach ( $col_map as $col => $key ) {
						if ( ! empty( $row[ $col ] ) && empty( $birth[ $key ] ) ) {
							$birth[ $key ] = (string) $row[ $col ];
						}
					}
				} else {
					$degraded = 'astro_coachee_not_found';
				}
			} else {
				$degraded = 'astro_provider_not_registered';
			}
		} else {
			$degraded = 'astro_provider_not_registered';
		}

		if ( ! empty( $birth['date'] ) ) {
			$systems = array( 'western', 'vedic' );
		}

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A9 — prefer detailed western report URL before wheel-only page.
		$natal_chart_url = '';
		if ( $coachee_id > 0 && function_exists( 'bcpro_get_astro_public_url' ) ) {
			$natal_chart_url = (string) bcpro_get_astro_public_url( $coachee_id, 'western' );
		}
		if ( $natal_chart_url === '' && $coachee_id > 0 && function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$natal_chart_url = (string) bccm_get_natal_chart_public_url( $coachee_id );
		}

		return array(
			'user_id'           => $user_id,
			'coachee_id'        => $coachee_id,
			'coachee_name'      => $coachee_name,
			'systems_available' => $systems,
			'birth'             => $birth,
			'natal_chart_url'   => $natal_chart_url,
			'name_extracted'    => (string) $name_extracted,
			'resolution_source' => 'name_from_query',
			'_degraded'         => $degraded,
		);
	}

	/**
	 * Build payload for `astro_time_parsed`.
	 *
	 * [2026-06-09 Johnny Chu] HOTFIX — docblock was not closed, making method
	 * invisible to PHP → Fatal "Call to undefined method". Added closing asterisk-slash.
	 *
	 * @param string $raw      Raw prompt text (used as fallback label source).
	 * @param string $period   Resolved period bucket: day|week|month|year|5year.
	 * @param array  $astro_row Classifier row from Web_Astro (label, num_days, start_offset, ...).
	 * @return array Time payload per astro FE spec §3.2.
	 */
	private function build_astro_time_payload( $raw, $period, array $astro_row = array() ) {
		$intent_map = array(
			'day'   => 'today',
			'week'  => 'week',
			'month' => 'month',
			'year'  => 'year',
			'5year' => 'custom',
		);
		$label_map = array(
			'day'   => 'ngày hôm nay',
			'week'  => 'tuần này',
			'month' => '30 ngày tới',
			'year'  => 'năm nay',
			'5year' => '5 năm tới',
		);
		$days_map = array(
			'day'   => 1,
			'week'  => 7,
			'month' => 30,
			'year'  => 365,
			'5year' => 1825,
		);
		$period = isset( $intent_map[ $period ] ) ? $period : 'day';
		$days   = (int) $days_map[ $period ];
		$start_offset = 0;
		$label_vi = (string) $label_map[ $period ];
		$temporal_signal_detected = isset( $astro_row['temporal_signal_detected'] )
			? ! empty( $astro_row['temporal_signal_detected'] )
			: $this->has_astro_temporal_signal_in_prompt( (string) $raw );
		$defaulted_tomorrow = isset( $astro_row['defaulted_tomorrow'] )
			? ! empty( $astro_row['defaulted_tomorrow'] )
			: false;
		$deep_analysis_requested = isset( $astro_row['deep_analysis_requested'] )
			? ! empty( $astro_row['deep_analysis_requested'] )
			: $this->has_astro_deep_analysis_signal_in_prompt( (string) $raw );
		$deep_focus_domains = isset( $astro_row['deep_focus_domains'] ) && is_array( $astro_row['deep_focus_domains'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $astro_row['deep_focus_domains'] ) ) ) )
			: $this->extract_astro_focus_domains_from_prompt( (string) $raw );

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — trust classifier row first.
		if ( isset( $astro_row['start_offset'] ) ) {
			$start_offset = max( 0, (int) $astro_row['start_offset'] );
		}
		if ( isset( $astro_row['num_days'] ) && (int) $astro_row['num_days'] > 0 ) {
			$days = max( 1, (int) $astro_row['num_days'] );
		}
		if ( ! empty( $astro_row['label'] ) ) {
			$label_vi = (string) $astro_row['label'];
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — fallback lexical guard when classifier row is partial.
		$raw_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $raw ) : strtolower( (string) $raw );
		if ( $period === 'day' ) {
			if ( $start_offset <= 0 && strpos( $raw_lc, 'ngày mai' ) !== false ) {
				$start_offset = 1;
				$label_vi = 'ngày mai';
			}
			if ( strpos( $raw_lc, 'ngày kia' ) !== false ) {
				$start_offset = 2;
				$label_vi = 'ngày kia';
			}
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — no temporal/future signal:
			// force default transit window to tomorrow for generic subject questions.
			if ( $start_offset <= 0 && ! $temporal_signal_detected ) {
				$start_offset = 1;
				$label_vi = 'Ngày mai';
				$defaulted_tomorrow = true;
			}
		}

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A9 — timezone-safe day window; avoid current_time('timestamp') double-offset drift.
		$_tz = function_exists( 'wp_timezone' )
			? wp_timezone()
			: new DateTimeZone( ( function_exists( 'wp_timezone_string' ) && wp_timezone_string() !== '' ) ? wp_timezone_string() : 'UTC' );
		try {
			$_base_dt = new DateTimeImmutable( 'now', $_tz );
		} catch ( Exception $e ) {
			$_base_dt = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		}
		$_base_dt = $_base_dt->setTime( 0, 0, 0 );
		$_from_dt = $_base_dt->modify( '+' . $start_offset . ' day' );
		$_to_dt   = $_from_dt->modify( '+' . ( max( 1, $days ) - 1 ) . ' day' );
		$from     = $_from_dt->format( 'Y-m-d' );
		$to       = $_to_dt->format( 'Y-m-d' );

		$intent = $intent_map[ $period ];
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — make day intent explicit by offset for FE step-2 clarity.
		if ( $period === 'day' ) {
			if ( $start_offset >= 2 ) {
				$intent = 'day_after_tomorrow';
			} elseif ( $start_offset === 1 ) {
				$intent = 'tomorrow';
			}
		}

		return array(
			'raw'       => (string) $raw,
			'intent'    => $intent,
			'start_offset' => $start_offset,
			'window'    => array( 'from' => $from, 'to' => $to, 'days' => $days ),
			'label_vi'  => $label_vi,
			'temporal_signal_detected' => $temporal_signal_detected,
			'defaulted_tomorrow' => $defaulted_tomorrow,
			'deep_analysis_requested' => $deep_analysis_requested,
			'deep_focus_domains' => $deep_focus_domains,
			'_degraded' => null,
		);
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — detect temporal/future
	 * signal for astro prompt balancing (subject-first vs time-driven).
	 */
	private function has_astro_temporal_signal_in_prompt( string $raw ): bool {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return false;
		}
		$raw_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw ) : strtolower( $raw );

		if ( preg_match( '/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/u', $raw_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b\d+\s*(?:ngày|tuần|tháng|năm)\b/u', $raw_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:hôm nay|ngày mai|ngày kia|ngày kìa|tuần|tháng|năm|quý|q1|q2|q3|q4|sắp tới|tương lai|khi nào|bao giờ|timeline|thời gian|giai đoạn)\b/u', $raw_lc ) ) {
			return true;
		}

		return false;
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — fallback deep-analysis
	 * signal detector when classifier row is missing fields.
	 */
	private function has_astro_deep_analysis_signal_in_prompt( string $raw ): bool {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return false;
		}
		$raw_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw ) : strtolower( $raw );

		if ( preg_match( '/\b(?:chi tiết|kỹ lưỡng|kĩ lưỡng|phân tích sâu|phân tích kỹ|phân tích kĩ|toàn diện|rất sâu|sâu hơn|cụ thể hơn|đầy đủ hơn|tường tận)\b/u', $raw_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:cuộc đời|đường đời|vận mệnh|sứ mệnh)\b/u', $raw_lc ) ) {
			return true;
		}
		$domains = $this->extract_astro_focus_domains_from_prompt( $raw );
		if ( count( $domains ) >= 2 ) {
			return true;
		}

		return false;
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — extract requested
	 * analysis domains so final composer can force domain coverage.
	 *
	 * @return array<string>
	 */
	private function extract_astro_focus_domains_from_prompt( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return array();
		}
		$raw_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $raw ) : strtolower( $raw );

		$domains = array();
		if ( preg_match( '/\b(?:sự nghiệp|công việc|nghề nghiệp|thăng tiến|việc làm|career)\b/u', $raw_lc ) ) {
			$domains[] = 'career';
		}
		if ( preg_match( '/\b(?:tài chính|tiền bạc|thu nhập|đầu tư|tiết kiệm|nợ|finance)\b/u', $raw_lc ) ) {
			$domains[] = 'finance';
		}
		if ( preg_match( '/\b(?:tình duyên|tình cảm|hôn nhân|yêu đương|người yêu|vợ chồng|relationship|love)\b/u', $raw_lc ) ) {
			$domains[] = 'love';
		}
		if ( preg_match( '/\b(?:gia đình|cha mẹ|con cái|anh chị em|nhà cửa|family)\b/u', $raw_lc ) ) {
			$domains[] = 'family';
		}
		if ( preg_match( '/\b(?:cuộc đời|đường đời|vận mệnh|sứ mệnh|life)\b/u', $raw_lc ) ) {
			$domains[] = 'life';
		}

		return array_values( array_unique( $domains ) );
	}

	/**
	 * Build payload for `astro_transit_resolved`.
	 *
	 * @param array      $subject_payload Output of build_astro_subject_payload().
	 * @param array      $passages        Passages returned by CAP filter.
	 * @param string     $period          Resolved period.
	 * @param array|null $astro_row       Web_Astro engine return row (for ms / _degraded).
	 * @return array Transit payload per spec §3.3.
	 */
	private function build_astro_transit_payload( array $subject_payload, array $passages, $period, $astro_row ) {
		$coachee_id = (int) ( isset( $subject_payload['coachee_id'] ) ? $subject_payload['coachee_id'] : 0 );
		$rows_count = 0;
		$source     = 'unavailable';
		$fetched_at = '';
		// [2026-06-04 Johnny Chu] PHASE-A C.3c — surface provenance link từ passage
		// metadata để FE timeline render "🔗 Nguồn" + Brain có nguồn để cite.
		$source_url = '';

		foreach ( $passages as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$meta = isset( $p['metadata'] ) && is_array( $p['metadata'] ) ? $p['metadata'] : array();
			$kind = (string) ( isset( $meta['kind'] ) ? $meta['kind'] : '' );
			if ( $kind === 'astro_transit_report' || $kind === 'astro_transit_daily' ) {
				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A3 — count daily rows
				// from day_items_raw when CAP returned FAA2 daily range passages.
				if ( $kind === 'astro_transit_daily' ) {
					$daily_rows = isset( $meta['day_items_raw'] ) && is_array( $meta['day_items_raw'] ) ? $meta['day_items_raw'] : array();
					$rows_count += ! empty( $daily_rows ) ? count( $daily_rows ) : 1;
				} else {
					$rows_count++;
				}
				if ( $source === 'unavailable' && ! empty( $meta['source'] ) ) {
					$source = (string) $meta['source'];
				}
				if ( $fetched_at === '' && ! empty( $meta['fetched_at'] ) ) {
					$fetched_at = (string) $meta['fetched_at'];
				}
				if ( $source_url === '' && ! empty( $meta['source_url'] ) ) {
					$source_url = (string) $meta['source_url'];
				}
				if ( $source_url === '' && $kind === 'astro_transit_daily' ) {
					$daily_rows = isset( $meta['day_items_raw'] ) && is_array( $meta['day_items_raw'] ) ? $meta['day_items_raw'] : array();
					if ( ! empty( $daily_rows ) && is_array( $daily_rows[0] ) && ! empty( $daily_rows[0]['day_url'] ) ) {
						$source_url = (string) $daily_rows[0]['day_url'];
					}
				}
			}
		}

		// Fallback: if no transit passage but engine reported cap_source.
		if ( $source === 'unavailable' && is_array( $astro_row ) && ! empty( $astro_row['cap_source'] ) ) {
			$source = (string) $astro_row['cap_source'];
		}

		$ms = is_array( $astro_row ) ? (int) ( isset( $astro_row['cap_ms'] ) ? $astro_row['cap_ms'] : 0 ) : 0;

		$degraded = null;
		if ( is_array( $astro_row ) && ! empty( $astro_row['_degraded'] ) ) {
			$degraded = (string) $astro_row['_degraded'];
		}
		// Also bubble subject-level _degraded so step 3 visibly degrades when
		// upstream (subject) failed — FE then can't blame the resolver.
		if ( $degraded === null && ! empty( $subject_payload['_degraded'] ) ) {
			$degraded = (string) $subject_payload['_degraded'];
		}

		return array(
			'coachee_id' => $coachee_id,
			'period'     => (string) $period,
			'source'     => $source,
			'rows_count' => $rows_count,
			'fetched_at' => $fetched_at,
			'ms'         => $ms,
			'source_url' => $source_url,
			'_degraded'  => $degraded,
		);
	}

	/**
	 * Build payload for `astro_context_appended`.
	 *
	 * @param array  $passages    Final passage array after CAP filter.
	 * @param string $cap_degraded Engine-reported degrade reason (may be empty).
	 * @return array Context payload per spec §3.4.
	 */
	private function build_astro_context_payload( array $passages, $cap_degraded ) {
		$kinds = array();
		$bytes = 0;
		foreach ( $passages as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$body  = isset( $p['body'] ) ? (string) $p['body'] : '';
			$bytes += strlen( $body );
			$meta  = isset( $p['metadata'] ) && is_array( $p['metadata'] ) ? $p['metadata'] : array();
			$kind  = (string) ( isset( $meta['kind'] ) ? $meta['kind']
				: ( isset( $meta['source_type'] ) ? $meta['source_type'] : '' ) );
			if ( $kind !== '' && ! in_array( $kind, $kinds, true ) ) {
				$kinds[] = $kind;
			}
		}
		$degraded = null;
		if ( empty( $passages ) ) {
			$degraded = 'astro_cap_no_passages';
		} elseif ( $cap_degraded !== '' && $cap_degraded !== null ) {
			$degraded = (string) $cap_degraded;
		}
		return array(
			'passages_count' => count( $passages ),
			'kinds'          => $kinds,
			'bytes'          => $bytes,
			'providers'      => empty( $passages ) ? array() : array( 'bizcoach_pro_astro' ),
			'_degraded'      => $degraded,
		);
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A6 — build compact per-turn
	 * debug payload for astro owner/coachee validation in FE console.
	 */
	private function build_astro_debug_trace_payload(
		string $trace_id,
		int $user_id,
		array $subject_payload,
		array $time_payload,
		array $transit_payload,
		array $day_eval,
		array $astro_row,
		array $passages,
		string $degraded
	): array {
		$resolved_coachee_id = (int) ( $astro_row['coachee_id_resolved'] ?? $subject_payload['coachee_id'] ?? 0 );
		$resolve_source      = (string) ( $astro_row['coachee_resolve_source'] ?? '' );
		if ( $resolve_source === '' ) {
			$resolve_source = $resolved_coachee_id > 0 ? 'self_default' : 'unresolved';
		}

		$natal_json = array(
			'user_id'         => $user_id,
			'coachee_id'      => (int) ( $subject_payload['coachee_id'] ?? 0 ),
			'coachee_name'    => (string) ( $subject_payload['coachee_name'] ?? '' ),
			'birth'           => isset( $subject_payload['birth'] ) && is_array( $subject_payload['birth'] )
				? $subject_payload['birth']
				: array(),
			'natal_chart_url' => (string) ( $subject_payload['natal_chart_url'] ?? '' ),
			'_degraded'       => isset( $subject_payload['_degraded'] ) ? $subject_payload['_degraded'] : null,
		);

		$time_json = array(
			'period'      => (string) ( $time_payload['period'] ?? '' ),
			'label'       => (string) ( $time_payload['label'] ?? '' ),
			'window'      => isset( $time_payload['window'] ) && is_array( $time_payload['window'] )
				? $time_payload['window']
				: array(),
			'_degraded'   => isset( $time_payload['_degraded'] ) ? $time_payload['_degraded'] : null,
		);

		$transit_json = array(
			'coachee_id' => (int) ( $transit_payload['coachee_id'] ?? 0 ),
			'period'     => (string) ( $transit_payload['period'] ?? '' ),
			'source'     => (string) ( $transit_payload['source'] ?? '' ),
			'rows_count' => (int) ( $transit_payload['rows_count'] ?? 0 ),
			'fetched_at' => (string) ( $transit_payload['fetched_at'] ?? '' ),
			'source_url' => (string) ( $transit_payload['source_url'] ?? '' ),
			'_degraded'  => isset( $transit_payload['_degraded'] ) ? $transit_payload['_degraded'] : null,
		);

		$day_rows = array();
		$_day_rows = isset( $day_eval['rows'] ) && is_array( $day_eval['rows'] ) ? $day_eval['rows'] : array();
		foreach ( $_day_rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$day_rows[] = array(
				'date'             => (string) ( $row['date'] ?? '' ),
				'date_label'       => (string) ( $row['date_label'] ?? '' ),
				'day_url'          => (string) ( $row['day_url'] ?? '' ),
				'score'            => (float) ( $row['score'] ?? 0 ),
				'confidence'       => (int) ( $row['confidence'] ?? 0 ),
				'confidence_label' => (string) ( $row['confidence_label'] ?? '' ),
				'reason'           => (string) ( $row['reason'] ?? '' ),
				'top_aspects'      => isset( $row['top_aspects'] ) && is_array( $row['top_aspects'] ) ? $row['top_aspects'] : array(),
			);
		}

		$artifact_json = array();
		foreach ( $passages as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$meta = isset( $p['metadata'] ) && is_array( $p['metadata'] ) ? $p['metadata'] : array();
			$kind = (string) ( $meta['kind'] ?? '' );
			if ( $kind === '' || strpos( $kind, 'astro_' ) !== 0 ) { continue; }

			$artifact_json[] = array(
				'title'        => (string) ( $p['title'] ?? '' ),
				'kind'         => $kind,
				'source'       => (string) ( $meta['source'] ?? '' ),
				'source_url'   => (string) ( $meta['source_url'] ?? '' ),
				'rows_count'   => isset( $meta['day_items_raw'] ) && is_array( $meta['day_items_raw'] )
					? count( $meta['day_items_raw'] )
					: 0,
				'fetched_at'   => (string) ( $meta['fetched_at'] ?? '' ),
				'coachee_id'   => isset( $meta['coachee_id'] ) ? (int) $meta['coachee_id'] : 0,
			);
		}

		return array(
			'trace_id'             => $trace_id,
			'user_id'              => $user_id,
			'coachee_id_resolved'  => $resolved_coachee_id,
			'resolve_source'       => $resolve_source,
			'name_extracted'       => (string) ( $astro_row['name_extracted'] ?? '' ),
			'natal_json'           => $natal_json,
			'time_json'            => $time_json,
			'transit_json'         => $transit_json,
			'transit_days_count'   => count( $day_rows ),
			'transit_days_json'    => $day_rows,
			'astro_artifacts_json' => $artifact_json,
			'_degraded'            => $degraded !== '' ? $degraded : ( $transit_payload['_degraded'] ?? null ),
			'fetched_at'           => current_time( 'mysql' ),
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 derive deterministic day rows from astro passages.
	 */
	private function build_astro_day_evaluation_rows( array $passages, string $prompt, $astro_row ): array {
		$topics = $this->infer_astro_topics( $prompt, $astro_row );
		$days   = $this->extract_astro_day_items( $passages );
		if ( empty( $days ) ) {
			return array( 'topics' => $topics, 'rows' => array() );
		}

		$rows = array();
		foreach ( $days as $day ) {
			$score      = 50.0;
			$good_hits  = 0;
			$bad_hits   = 0;
			$topic_hits = 0;
			$aspects    = isset( $day['aspects'] ) && is_array( $day['aspects'] ) ? $day['aspects'] : array();
			// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — build day evidence buckets
			// so FE/UI can render user-facing stats (favorable/challenging/slow/retro)
			// instead of internal technical counters.
			$sorted_aspects = array_values( $aspects );
			usort( $sorted_aspects, function ( $a, $b ) {
				$orb_a = $this->extract_astro_orb_from_line( (string) $a );
				$orb_b = $this->extract_astro_orb_from_line( (string) $b );
				if ( $orb_a === $orb_b ) {
					return strcmp( (string) $a, (string) $b );
				}
				return ( $orb_a < $orb_b ) ? -1 : 1;
			} );
			$favorable_aspects = array();
			$challenging_aspects = array();
			$slow_planet_aspects = array();
			$slow_planets = array();
			foreach ( $aspects as $line ) {
				$line  = (string) $line;
				$delta = $this->score_astro_aspect_line( $line );
				$score += $delta;
				if ( $delta >= 0 ) {
					$good_hits++;
					$favorable_aspects[] = $line;
				} else {
					$bad_hits++;
					$challenging_aspects[] = $line;
				}
				if ( $this->is_astro_slow_planet_line( $line ) ) {
					$slow_planet_aspects[] = $line;
					$slow_planet = $this->extract_astro_transit_planet_from_line( $line );
					if ( $slow_planet !== '' ) {
						$slow_planets[ $slow_planet ] = 1;
					}
				}
				if ( $this->astro_line_matches_topics( $line, $topics ) ) {
					$topic_hits++;
					$score += 1.5;
				}
			}
			$favorable_aspects   = array_values( array_unique( $favorable_aspects ) );
			$challenging_aspects = array_values( array_unique( $challenging_aspects ) );
			$slow_planet_aspects = array_values( array_unique( $slow_planet_aspects ) );
			$slow_planets        = array_values( array_keys( $slow_planets ) );

			$retro_count = (int) ( $day['retro_count'] ?? 0 );
			$score      -= (float) ( $retro_count * 2 );
			// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — deterministic
			// confidence per day so final answer can expose confidence evidence.
			$confidence = $this->compute_astro_day_confidence( array(
				'aspects_count' => count( $aspects ),
				'good_hits'     => $good_hits,
				'bad_hits'      => $bad_hits,
				'topic_hits'    => $topic_hits,
				'retro_count'   => $retro_count,
			) );

			$rows[] = array(
				'date'          => (string) ( $day['date'] ?? '' ),
				'date_label'    => (string) ( $day['date_label'] ?? ( $day['date'] ?? '' ) ),
				'day_url'       => (string) ( $day['day_url'] ?? '' ),
				'score'         => round( $score, 2 ),
				'total_aspects' => count( $aspects ),
				'favorable_count' => $good_hits,
				'challenging_count' => $bad_hits,
				'retrograde_count'  => $retro_count,
				'slow_planet_count' => count( $slow_planet_aspects ),
				'favorable_aspects' => array_slice( $favorable_aspects, 0, 3 ),
				'challenging_aspects' => array_slice( $challenging_aspects, 0, 3 ),
				'slow_planet_aspects' => array_slice( $slow_planet_aspects, 0, 3 ),
				'slow_planets'      => $slow_planets,
				'good_hits'     => $good_hits,
				'bad_hits'      => $bad_hits,
				'topic_hits'    => $topic_hits,
				'retro_count'   => $retro_count,
				'aspects_count' => count( $aspects ),
				'top_aspects'   => array_slice( $sorted_aspects, 0, 3 ),
				'metrics_signature' => substr( md5( implode( '|', array_values( $aspects ) ) ), 0, 10 ),
				'confidence'    => (int) ( $confidence['score'] ?? 0 ),
				'confidence_label' => (string) ( $confidence['label'] ?? '' ),
				'confidence_reason'=> (string) ( $confidence['reason'] ?? '' ),
				'reason'        => 'Tổng quan: ' . count( $aspects ) . ' góc chiếu; thuận lợi ' . $good_hits . '; thử thách ' . $bad_hits . '; nghịch hành ' . $retro_count . '; sao chậm ' . count( $slow_planet_aspects ) . '.',
			);
		}

		usort( $rows, function ( $a, $b ) {
			$sa = (float) ( $a['score'] ?? 0 );
			$sb = (float) ( $b['score'] ?? 0 );
			if ( $sa === $sb ) {
				$da = (string) ( $a['date'] ?? '' );
				$db = (string) ( $b['date'] ?? '' );
				return strcmp( $da, $db );
			}
			return ( $sa > $sb ) ? -1 : 1;
		} );

		return array(
			'topics' => $topics,
			'rows'   => $rows,
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 ranking summary payload.
	 */
	private function build_astro_day_ranking( array $rows ): array {
		if ( empty( $rows ) ) {
			return array(
				'total_days' => 0,
				'ranking'    => array(),
				'best_day'   => '',
				'best_score' => 0,
			);
		}
		$top = array_slice( $rows, 0, 3 );
		$best = $top[0];
		return array(
			'total_days' => count( $rows ),
			'ranking'    => $top,
			'best_day'   => (string) ( $best['date'] ?? '' ),
			'best_score' => (float) ( $best['score'] ?? 0 ),
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 final recommendation event payload.
	 */
	private function build_astro_final_recommendation_payload( array $day_rank, string $period, array $transit_payload, string $degraded ): array {
		$best_day   = (string) ( $day_rank['best_day'] ?? '' );
		$best_score = (float) ( $day_rank['best_score'] ?? 0 );
		if ( $best_day === '' ) {
			return array(
				'period'       => $period,
				'best_day'     => '',
				'best_score'   => 0,
				'source_url'   => (string) ( $transit_payload['source_url'] ?? '' ),
				'reason'       => 'Không đủ dữ liệu day-by-day để chốt ngày tốt nhất.',
				'_degraded'    => $degraded !== '' ? $degraded : 'astro_day_items_empty',
			);
		}
		return array(
			'period'       => $period,
			'best_day'     => $best_day,
			'best_score'   => $best_score,
			'source_url'   => (string) ( $transit_payload['source_url'] ?? '' ),
			'reason'       => 'Ngày có điểm deterministic cao nhất theo aspects/orb/retrograde.',
			'_degraded'    => $degraded !== '' ? $degraded : null,
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — build deterministic foreach block for final composer.
	 */
	private function build_astro_day_foreach_markdown( array $rows, array $day_rank, array $final_rec ): string {
		if ( empty( $rows ) ) {
			return '';
		}

		$rows_by_date = $rows;
		usort( $rows_by_date, function ( $a, $b ) {
			$da = (string) ( $a['date'] ?? '' );
			$db = (string) ( $b['date'] ?? '' );
			return strcmp( $da, $db );
		} );

		$best_day   = (string) ( $day_rank['best_day'] ?? $final_rec['best_day'] ?? '' );
		$best_score = (float) ( $day_rank['best_score'] ?? $final_rec['best_score'] ?? 0 );

		$total_days = count( $rows_by_date );
		$lines = array(
			'## PHÂN TÍCH TRANSIT THEO TỪNG NGÀY (DETERMINISTIC)',
			// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A11 — explicit per-day obligation
			// so LLM cannot skip days with 0 aspects ("no aspects" is itself meaningful data).
			'CÓ ' . $total_days . ' NGÀY CẦN PHÂN TÍCH. BẮT BUỘC luận giải TẤT CẢ ' . $total_days . ' ngày theo thứ tự,',
			'kể cả ngày không có transit aspect (ngày đó có nghĩa là năng lượng trung tính / ổn định, không có áp lực bên ngoài).',
			'Không được bỏ qua hoặc gộp bất kỳ ngày nào. Mỗi ngày cần có đoạn riêng với nhận định và link nguồn.',
		);

		foreach ( $rows_by_date as $r ) {
			$date       = (string) ( $r['date'] ?? '' );
			$date_label = (string) ( $r['date_label'] ?? $date );
			$day_url    = (string) ( $r['day_url'] ?? '' );
			$score      = round( (float) ( $r['score'] ?? 0 ), 2 );
			$total_aspects = (int) ( $r['total_aspects'] ?? $r['aspects_count'] ?? 0 );
			$favorable_count = (int) ( $r['favorable_count'] ?? $r['good_hits'] ?? 0 );
			$challenging_count = (int) ( $r['challenging_count'] ?? $r['bad_hits'] ?? 0 );
			$retro      = (int) ( $r['retrograde_count'] ?? $r['retro_count'] ?? 0 );
			$slow_count = (int) ( $r['slow_planet_count'] ?? 0 );
			$reason     = (string) ( $r['reason'] ?? '' );
			$top_aspects = isset( $r['top_aspects'] ) && is_array( $r['top_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['top_aspects'] ) ) )
				: array();
			$favorable_aspects = isset( $r['favorable_aspects'] ) && is_array( $r['favorable_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['favorable_aspects'] ) ) )
				: array();
			$challenging_aspects = isset( $r['challenging_aspects'] ) && is_array( $r['challenging_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['challenging_aspects'] ) ) )
				: array();
			$slow_planet_aspects = isset( $r['slow_planet_aspects'] ) && is_array( $r['slow_planet_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['slow_planet_aspects'] ) ) )
				: array();
			if ( empty( $favorable_aspects ) && ! empty( $top_aspects ) ) {
				$favorable_aspects = array_slice( $top_aspects, 0, 2 );
			}

			$lines[] = '### ' . $date_label;
			$lines[] = '- score=' . $score
				. ' | tổng quan=' . $total_aspects
				. ' | thuận lợi=' . $favorable_count
				. ' | thử thách=' . $challenging_count
				. ' | nghịch hành=' . $retro
				. ' | sao chậm=' . $slow_count;
			if ( $day_url !== '' ) {
				$lines[] = '- link ngày: [astro:transit_day#' . $day_url . ']';
			}
			if ( ! empty( $favorable_aspects ) ) {
				$lines[] = '- căn cứ thuận lợi: ' . implode( '; ', array_slice( $favorable_aspects, 0, 3 ) );
			}
			if ( ! empty( $challenging_aspects ) ) {
				$lines[] = '- căn cứ thử thách: ' . implode( '; ', array_slice( $challenging_aspects, 0, 3 ) );
			}
			if ( ! empty( $slow_planet_aspects ) ) {
				$lines[] = '- căn cứ sao chậm: ' . implode( '; ', array_slice( $slow_planet_aspects, 0, 3 ) );
			}
			if ( empty( $favorable_aspects ) && empty( $challenging_aspects ) && ! empty( $top_aspects ) ) {
				$lines[] = '- căn cứ transit: ' . implode( '; ', array_slice( $top_aspects, 0, 3 ) );
			}
			if ( empty( $favorable_aspects ) && empty( $challenging_aspects ) && empty( $top_aspects ) ) {
				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A11 — explicit signal so LLM
				// analyses the day rather than skipping it.
				$lines[] = '- căn cứ: KHÔNG CÓ transit aspect nổi bật → ngày TRUNG TÍNH (không áp lực từ sao chậm).';
				$lines[] = '  → BẮT BUỘC vẫn phân tích ngày này: nhận định năng lượng ổn định, phù hợp công việc thường ngày.';
			}
			if ( $reason !== '' ) {
				$lines[] = '- nhận định: ' . $reason;
			}
			if ( $date !== '' && $date === $best_day ) {
				$lines[] = '- cờ: ngày tốt nhất theo scoring deterministic';
			}
		}

		$lines[] = '## KẾT LUẬN CUỐI (DỰA TRÊN TỪNG NGÀY)';
		if ( $best_day !== '' ) {
			$lines[] = '- best_day=' . $best_day . ' | best_score=' . round( $best_score, 2 );
		} else {
			$lines[] = '- không đủ dữ liệu để chốt ngày tốt nhất';
		}
		if ( ! empty( $final_rec['reason'] ) ) {
			$lines[] = '- reason=' . (string) $final_rec['reason'];
		}

		return implode( "\n", $lines );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 parse day markers from markdown passages.
	 */
	private function extract_astro_day_items( array $passages ): array {
		$days = array();
		foreach ( $passages as $p ) {
			if ( ! is_array( $p ) ) { continue; }

			$meta = isset( $p['metadata'] ) && is_array( $p['metadata'] ) ? $p['metadata'] : array();
			// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — prefer raw structured day payload
			// from CAP metadata (day_items_raw/daily/days_json) before markdown parser fallback.
			$meta_days = $this->extract_astro_day_items_from_metadata( $meta );
			if ( ! empty( $meta_days ) ) {
				foreach ( $meta_days as $row ) {
					$date = isset( $row['date'] ) ? (string) $row['date'] : '';
					if ( $date === '' ) { continue; }
					if ( ! isset( $days[ $date ] ) ) {
						$days[ $date ] = array(
							'date'        => $date,
							'date_label'  => (string) ( $row['date_label'] ?? $date ),
							'day_url'     => (string) ( $row['day_url'] ?? '' ),
							'aspects'     => array(),
							'retro_count' => 0,
						);
					}
					if ( $days[ $date ]['day_url'] === '' && ! empty( $row['day_url'] ) ) {
						$days[ $date ]['day_url'] = (string) $row['day_url'];
					}
					$days[ $date ]['aspects'] = array_values( array_filter( array_merge(
						(array) $days[ $date ]['aspects'],
						(array) ( $row['aspects'] ?? array() )
					) ) );
					$days[ $date ]['retro_count'] = max(
						(int) $days[ $date ]['retro_count'],
						(int) ( $row['retro_count'] ?? 0 )
					);
				}
				continue;
			}

			$body = (string) ( $p['body'] ?? '' );
			if ( $body === '' ) { continue; }
			$lines = preg_split( '/\r\n|\r|\n/', $body );
			$current = '';
			foreach ( (array) $lines as $line ) {
				$line = trim( (string) $line );
				if ( $line === '' ) { continue; }

				if ( preg_match( '/^\*\*(\d{4}-\d{2}-\d{2})\*\*/', $line, $m ) || preg_match( '/^###\s*(\d{4}-\d{2}-\d{2})/', $line, $m ) ) {
					$current = (string) $m[1];
					if ( ! isset( $days[ $current ] ) ) {
						$days[ $current ] = array(
							'date'        => $current,
							'date_label'  => $current,
							'day_url'     => '',
							'aspects'     => array(),
							'retro_count' => 0,
						);
					}
					continue;
				}

				if ( $current === '' || ! isset( $days[ $current ] ) ) { continue; }

				$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
				if ( strpos( $line, '℞' ) !== false || strpos( $line_lc, 'nghịch hành' ) !== false ) {
					$days[ $current ]['retro_count']++;
				}

				$is_aspect = strpos( $line, 'Transit' ) !== false
					|| strpos( $line, 'natal' ) !== false
					|| strpos( $line_lc, 'orb' ) !== false
					|| strpos( $line_lc, 'tam hợp' ) !== false
					|| strpos( $line_lc, 'lục hợp' ) !== false
					|| strpos( $line_lc, 'đối' ) !== false
					|| strpos( $line_lc, 'vuông' ) !== false
					|| strpos( $line_lc, 'hợp' ) !== false;
				if ( $is_aspect ) {
					$days[ $current ]['aspects'][] = ltrim( $line, '- ' );
				}
			}
		}

		return array_values( $days );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — normalize raw day payload from CAP metadata.
	 */
	private function extract_astro_day_items_from_metadata( array $meta ): array {
		$raw = array();
		if ( isset( $meta['day_items_raw'] ) && is_array( $meta['day_items_raw'] ) ) {
			$raw = $meta['day_items_raw'];
		} elseif ( isset( $meta['day_items'] ) && is_array( $meta['day_items'] ) ) {
			$raw = $meta['day_items'];
		} elseif ( isset( $meta['daily'] ) && is_array( $meta['daily'] ) ) {
			$raw = $meta['daily'];
		} elseif ( isset( $meta['days_json'] ) && is_string( $meta['days_json'] ) ) {
			$decoded = json_decode( $meta['days_json'], true );
			if ( is_array( $decoded ) ) {
				$raw = $decoded;
			}
		}

		if ( empty( $raw ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw as $day ) {
			if ( ! is_array( $day ) ) { continue; }
			$date = trim( (string) ( $day['date'] ?? '' ) );
			if ( $date === '' ) { continue; }

			$row = array(
				'date'        => $date,
				'date_label'  => (string) ( $day['date_label'] ?? $date ),
				'day_url'     => (string) ( $day['day_url'] ?? '' ),
				'aspects'     => array(),
				'retro_count' => 0,
			);

			$aspects = isset( $day['aspects'] ) && is_array( $day['aspects'] ) ? $day['aspects'] : array();
			foreach ( $aspects as $aspect ) {
				if ( is_string( $aspect ) ) {
					$line = trim( $aspect );
					if ( $line !== '' ) {
						$row['aspects'][] = $line;
					}
					continue;
				}
				if ( ! is_array( $aspect ) ) { continue; }

				$tp  = (string) ( $aspect['transit_planet'] ?? $aspect['transit'] ?? '' );
				$np  = (string) ( $aspect['natal_planet'] ?? $aspect['natal'] ?? '' );
				$asp = (string) ( $aspect['aspect'] ?? $aspect['type'] ?? '' );
				$orb = isset( $aspect['orb'] ) ? (string) $aspect['orb'] : '';

				$line = '';
				if ( $tp !== '' && $np !== '' && $asp !== '' ) {
					$line = 'Transit ' . $tp . ' ' . $asp . ' natal ' . $np;
				} elseif ( $asp !== '' ) {
					$line = $asp;
				}

				if ( $line !== '' ) {
					if ( $orb !== '' ) {
						$line .= ' (' . $orb . '°)';
					}
					$row['aspects'][] = $line;
				}
			}

			if ( isset( $day['retro_count'] ) ) {
				$row['retro_count'] = max( $row['retro_count'], (int) $day['retro_count'] );
			}

			if ( isset( $day['planets'] ) && is_array( $day['planets'] ) ) {
				foreach ( $day['planets'] as $planet ) {
					if ( ! is_array( $planet ) ) { continue; }
					$is_retro = ! empty( $planet['is_retro'] )
						|| ( isset( $planet['isRetro'] ) && $planet['isRetro'] === 'true' );
					if ( $is_retro ) {
						$row['retro_count']++;
					}
				}
			}

			if ( isset( $day['transit_planets'] ) && is_array( $day['transit_planets'] ) ) {
				foreach ( $day['transit_planets'] as $pdata ) {
					if ( ! is_array( $pdata ) ) { continue; }
					$is_retro = ! empty( $pdata['is_retro'] ) || ! empty( $pdata['retrograde'] );
					if ( $is_retro ) {
						$row['retro_count']++;
					}
				}
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — append verified source block.
	 *
	 * Ensures final astro answer always contains canonical links bound to the
	 * current coachee (natal chart + transit/day links) even when LLM outputs
	 * malformed or cross-subject astro tokens.
	 */
	private function append_astro_primary_source_block( string $answer_md, array $subject_payload, array $rows, array $transit_payload, array $passages = array() ): string {
		$answer_md = trim( $answer_md );
		if ( $answer_md === '' ) {
			return $answer_md;
		}
		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A11 — DO NOT early-return when LLM
		// already generated the section. LLM self-generated section only has the best day;
		// canonical block is stripped and re-appended so ALL days get their verified links.
		if ( strpos( $answer_md, '## NGUỒN CHÍNH CHỦ (VERIFIED)' ) !== false ) {
			$answer_md = trim( (string) preg_replace(
				'/\n{0,2}## NGUỒN CHÍNH CHỦ \(VERIFIED\)[\s\S]*?(?=\n## |\z)/u',
				'',
				$answer_md
			) );
		}

		$coachee_id = (int) ( $subject_payload['coachee_id'] ?? 0 );
		$natal_url  = (string) ( $subject_payload['natal_chart_url'] ?? '' );
		$source_url = (string) ( $transit_payload['source_url'] ?? '' );
		$period     = sanitize_key( (string) ( $transit_payload['period'] ?? 'day' ) );

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A7 — fallback URLs from
		// CAP artifact_links to avoid missing natal/transit in final answer.
		$artifact_links = $this->collect_astro_artifact_links_from_passages( $passages );
		if ( $natal_url === '' ) {
			// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A9 — prefer detailed western page link over wheel-only page.
			$natal_url = (string) ( $artifact_links['western_vi'] ?? $artifact_links['wheel'] ?? '' );
		}
		if ( $source_url === '' ) {
			$period_key = 'transit_' . ( $period !== '' ? $period : 'day' );
			$source_url = (string) ( $artifact_links[ $period_key ] ?? '' );
			if ( $source_url === '' ) {
				$source_url = (string) ( $artifact_links['transit_day'] ?? $artifact_links['transit_week'] ?? $artifact_links['transit_month'] ?? $artifact_links['transit_year'] ?? '' );
			}
		}

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — only keep URLs
		// that do not contradict current coachee_id in query string.
		$matches_coachee_url = static function ( string $url, int $expected_coachee_id ): bool {
			if ( $expected_coachee_id <= 0 || $url === '' ) {
				return true;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
				return true;
			}
			$query = array();
			parse_str( (string) $parts['query'], $query );
			if ( ! isset( $query['id'] ) ) {
				return true;
			}
			return (int) $query['id'] === $expected_coachee_id;
		};

		$rows_by_date = $rows;
		usort( $rows_by_date, function ( $a, $b ) {
			$da = (string) ( $a['date'] ?? '' );
			$db = (string) ( $b['date'] ?? '' );
			return strcmp( $da, $db );
		} );

		$lines = array( '## NGUỒN CHÍNH CHỦ (VERIFIED)' );

		if ( $natal_url !== '' && $matches_coachee_url( $natal_url, $coachee_id ) ) {
			$lines[] = '- Bản đồ sao chính chủ: [astro:natal#' . $natal_url . ']';
		} elseif ( $coachee_id > 0 ) {
			$lines[] = '- Bản đồ sao chính chủ: chưa có URL công khai (coachee_id=' . $coachee_id . ').';
		} else {
			$lines[] = '- Bản đồ sao chính chủ: chưa resolve được coachee_id.';
		}

		if ( $source_url !== '' && $matches_coachee_url( $source_url, $coachee_id ) ) {
			$lines[] = '- Transit tổng quan: [astro:transit#' . $source_url . ']';
		} else {
			$lines[] = '- Transit tổng quan: chưa có URL công khai.';
		}

		$has_day_link = false;
		foreach ( $rows_by_date as $r ) {
			$day_url = (string) ( $r['day_url'] ?? '' );
			if ( $day_url === '' ) { continue; }
			if ( ! $matches_coachee_url( $day_url, $coachee_id ) ) { continue; }
			$has_day_link = true;
			$date_label = (string) ( $r['date_label'] ?? ( $r['date'] ?? '' ) );
			if ( $date_label === '' ) {
				$date_label = 'Transit ngày';
			}
			$lines[] = '- ' . $date_label . ': [astro:transit_day#' . $day_url . ']';
		}
		if ( ! $has_day_link ) {
			$lines[] = '- Transit ngày: chưa có day_url public cho cửa sổ hiện tại.';
		}

		if ( count( $lines ) <= 1 ) {
			return $answer_md;
		}

		return $answer_md . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — append deterministic
	 * evidence block so user can see data provenance + day links in final text.
	 */
	private function append_astro_citation_basis_block( string $answer_md, array $rows, array $transit_payload ): string {
		$answer_md = trim( $answer_md );
		if ( $answer_md === '' ) {
			return $answer_md;
		}
		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A11 — strip LLM-generated section first
		// so canonical block (with ALL days) always wins.
		if ( strpos( $answer_md, '## CĂN CỨ DỮ LIỆU TRANSIT' ) !== false ) {
			$answer_md = trim( (string) preg_replace(
				'/\n{0,2}## CĂN CỨ DỮ LIỆU TRANSIT[\s\S]*?(?=\n## |\z)/u',
				'',
				$answer_md
			) );
		}

		$source_url = (string) ( $transit_payload['source_url'] ?? '' );
		$rows_by_date = $rows;
		usort( $rows_by_date, function ( $a, $b ) {
			$da = (string) ( $a['date'] ?? '' );
			$db = (string) ( $b['date'] ?? '' );
			return strcmp( $da, $db );
		} );

		$lines = array( '## CĂN CỨ DỮ LIỆU TRANSIT' );
		if ( $source_url !== '' ) {
			$lines[] = '- Nguồn tổng quan: [astro:transit#' . $source_url . ']';
		}

		$has_day_ref = false;
		foreach ( $rows_by_date as $r ) {
			$date       = (string) ( $r['date'] ?? '' );
			$date_label = (string) ( $r['date_label'] ?? $date );
			$day_url    = (string) ( $r['day_url'] ?? '' );
			$total_aspects = (int) ( $r['total_aspects'] ?? $r['aspects_count'] ?? 0 );
			$favorable_count = (int) ( $r['favorable_count'] ?? $r['good_hits'] ?? 0 );
			$challenging_count = (int) ( $r['challenging_count'] ?? $r['bad_hits'] ?? 0 );
			$retro_count = (int) ( $r['retrograde_count'] ?? $r['retro_count'] ?? 0 );
			$slow_count = (int) ( $r['slow_planet_count'] ?? 0 );
			$top_aspects = isset( $r['top_aspects'] ) && is_array( $r['top_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['top_aspects'] ) ) )
				: array();
			$favorable_aspects = isset( $r['favorable_aspects'] ) && is_array( $r['favorable_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['favorable_aspects'] ) ) )
				: array();
			$challenging_aspects = isset( $r['challenging_aspects'] ) && is_array( $r['challenging_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['challenging_aspects'] ) ) )
				: array();
			$slow_planet_aspects = isset( $r['slow_planet_aspects'] ) && is_array( $r['slow_planet_aspects'] )
				? array_values( array_filter( array_map( 'strval', $r['slow_planet_aspects'] ) ) )
				: array();
			if ( empty( $favorable_aspects ) && ! empty( $top_aspects ) ) {
				$favorable_aspects = array_slice( $top_aspects, 0, 2 );
			}

			if ( $day_url !== '' ) {
				$lines[] = '- ' . $date_label . ': [astro:transit_day#' . $day_url . ']';
				$has_day_ref = true;
			} else {
				$lines[] = '- ' . $date_label . ': không có day_url public.';
			}

			// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — evidence-first
			// ordering: show concrete transit grounds before any internal metrics.
			$lines[] = '  Căn cứ tổng quan: ' . $total_aspects
				. ' góc chiếu; thuận lợi=' . $favorable_count
				. '; thử thách=' . $challenging_count
				. '; nghịch hành=' . $retro_count
				. '; sao chậm=' . $slow_count . '.';

			if ( ! empty( $favorable_aspects ) ) {
				$lines[] = '  Căn cứ thuận lợi: ' . implode( '; ', array_slice( $favorable_aspects, 0, 3 ) );
			}
			if ( ! empty( $challenging_aspects ) ) {
				$lines[] = '  Căn cứ thử thách: ' . implode( '; ', array_slice( $challenging_aspects, 0, 3 ) );
			}
			if ( ! empty( $slow_planet_aspects ) ) {
				$lines[] = '  Căn cứ sao chậm: ' . implode( '; ', array_slice( $slow_planet_aspects, 0, 3 ) );
			}

			if ( empty( $favorable_aspects ) && empty( $challenging_aspects ) && ! empty( $top_aspects ) ) {
				$lines[] = '  Căn cứ transit: ' . implode( '; ', array_slice( $top_aspects, 0, 3 ) );
			} else {
				if ( empty( $favorable_aspects ) && empty( $challenging_aspects ) && empty( $top_aspects ) ) {
				$lines[] = '  Căn cứ: Không có transit aspect nổi bật trong snapshot của ngày này.';
				}
			}
		}

		if ( ! $has_day_ref && $source_url === '' ) {
			return $answer_md;
		}

		return $answer_md . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — append explicit citation
	 * namespace legend so user thấy rõ token nào thuộc memory vs astro.
	 */
	private function append_astro_citation_namespace_block( string $answer_md ): string {
		$answer_md = trim( $answer_md );
		if ( $answer_md === '' ) {
			return $answer_md;
		}
		if ( strpos( $answer_md, '## PHÂN LOẠI CITATION' ) !== false ) {
			return $answer_md;
		}

		$astro_tokens  = array();
		$memory_tokens = array();

		if ( preg_match_all( '/\[(astro:[^\]\s]+)\]/', $answer_md, $m1 ) ) {
			$astro_tokens = array_values( array_unique( (array) $m1[1] ) );
		}
		if ( preg_match_all( '/\[(mem:[^\]\s]+)\]/', $answer_md, $m2 ) ) {
			$memory_tokens = array_values( array_unique( (array) $m2[1] ) );
		}

		if ( empty( $astro_tokens ) && empty( $memory_tokens ) ) {
			return $answer_md;
		}

		$render = function ( array $tokens ): string {
			if ( empty( $tokens ) ) {
				return 'không dùng';
			}
			$tokens = array_values( $tokens );
			$show   = array_slice( $tokens, 0, 12 );
			$parts  = array();
			foreach ( $show as $t ) {
				$parts[] = '[' . $t . ']';
			}
			if ( count( $tokens ) > 12 ) {
				$parts[] = '(+' . ( count( $tokens ) - 12 ) . ' token)';
			}
			return implode( ', ', $parts );
		};

		$lines = array(
			'## PHÂN LOẠI CITATION',
			'- Astro: ' . $render( $astro_tokens ),
			'- Memory: ' . $render( $memory_tokens ),
		);

		return $answer_md . "\n\n" . implode( "\n", $lines );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — deterministic per-day
	 * confidence score used for evidence transparency in final output.
	 */
	private function compute_astro_day_confidence( array $row ): array {
		$aspects_count = max( 0, (int) ( $row['aspects_count'] ?? 0 ) );
		$good_hits     = max( 0, (int) ( $row['good_hits'] ?? 0 ) );
		$bad_hits      = max( 0, (int) ( $row['bad_hits'] ?? 0 ) );
		$topic_hits    = max( 0, (int) ( $row['topic_hits'] ?? 0 ) );
		$retro_count   = max( 0, (int) ( $row['retro_count'] ?? 0 ) );

		$direction_total = $good_hits + $bad_hits;
		if ( $aspects_count <= 0 ) {
			$score = 76 - min( 8, $retro_count * 2 );
			$score = (int) max( 35, min( 95, $score ) );
			$label = $this->label_astro_day_confidence( $score );
			return array(
				'score'  => $score,
				'label'  => $label,
				'reason' => 'no_aspect_day; retro=' . $retro_count,
			);
		}

		$coverage       = min( 1.0, $aspects_count / 3.0 );
		$dominance      = $direction_total > 0 ? abs( $good_hits - $bad_hits ) / (float) $direction_total : 0.0;
		$conflict_ratio = ( $good_hits > 0 && $bad_hits > 0 && $direction_total > 0 )
			? min( $good_hits, $bad_hits ) / (float) $direction_total
			: 0.0;
		$topic_factor = min( 1.0, $topic_hits / 2.0 );

		$score = 52
			+ ( $coverage * 18 )
			+ ( $dominance * 14 )
			+ ( $topic_factor * 8 )
			- ( $conflict_ratio * 10 )
			- min( 8, $retro_count * 1.5 );

		$score = (int) round( max( 35, min( 95, $score ) ) );
		$label = $this->label_astro_day_confidence( $score );

		return array(
			'score' => $score,
			'label' => $label,
			'reason' => 'coverage=' . round( $coverage * 100 )
				. '%, dominance=' . round( $dominance * 100 )
				. '%, conflict=' . round( $conflict_ratio * 100 )
				. '%, topic=' . $topic_hits
				. ', retro=' . $retro_count,
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — confidence label buckets.
	 */
	private function label_astro_day_confidence( int $score ): string {
		if ( $score >= 80 ) {
			return 'cao';
		}
		if ( $score >= 65 ) {
			return 'khá';
		}
		if ( $score >= 50 ) {
			return 'trung bình';
		}
		return 'thấp';
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 aspect scoring (shared with timeline events).
	 */
	private function score_astro_aspect_line( string $line ): float {
		$weights = array(
			'tam hợp'     => 6,
			'trine'       => 6,
			'lục hợp'     => 4,
			'sextile'     => 4,
			'hợp'         => 2,
			'conjunction' => 2,
			'đối'         => -5,
			'opposition'  => -5,
			'vuông'       => -6,
			'square'      => -6,
			'quincunx'    => -3,
			'bất điều hòa'=> -3,
		);
		$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		$base    = 0.0;
		foreach ( $weights as $k => $w ) {
			if ( strpos( $line_lc, $k ) !== false ) {
				$base = (float) $w;
				break;
			}
		}
		$orb = 2.0;
		if ( preg_match( '/\((\d+(?:\.\d+)?)°\)/u', $line, $m ) ) {
			$orb = (float) $m[1];
		}
		$orb_factor = max( 0.2, ( 4.0 - min( 4.0, $orb ) ) / 4.0 );
		return $base * $orb_factor;
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — parse orb from aspect line.
	 */
	private function extract_astro_orb_from_line( string $line ): float {
		if ( preg_match( '/\((\d+(?:\.\d+)?)°\)/u', $line, $m ) ) {
			return (float) $m[1];
		}
		return 99.0;
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — detect transit planet name from line.
	 */
	private function extract_astro_transit_planet_from_line( string $line ): string {
		$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		if ( preg_match( '/transit\s+([^\s]+(?:\s+[^\s]+)?)\s+(tam hợp|trine|lục hợp|sextile|hợp|conjunction|đối|opposition|vuông|square|quincunx|semi(?:-|\s)?sextile|bất điều hòa)/u', $line_lc, $m ) ) {
			$planet = trim( (string) $m[1] );
			$planet = preg_replace( '/\s+/u', ' ', $planet );
			return is_string( $planet ) ? $planet : '';
		}

		$planet_alias = array(
			'jupiter',
			'saturn',
			'uranus',
			'neptune',
			'pluto',
			'sao mộc',
			'sao thổ',
			'sao thiên vương',
			'sao hải vương',
			'sao diêm vương',
		);
		foreach ( $planet_alias as $alias ) {
			if ( strpos( $line_lc, $alias ) !== false ) {
				return $alias;
			}
		}

		return '';
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A14 — classify slow-planet transit lines.
	 */
	private function is_astro_slow_planet_line( string $line ): bool {
		$planet = $this->extract_astro_transit_planet_from_line( $line );
		if ( $planet === '' ) {
			return false;
		}

		return in_array( $planet, array(
			'jupiter',
			'saturn',
			'uranus',
			'neptune',
			'pluto',
			'sao mộc',
			'sao thổ',
			'sao thiên vương',
			'sao hải vương',
			'sao diêm vương',
		), true );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 infer topics from row/query.
	 */
	private function infer_astro_topics( string $prompt, $astro_row ): array {
		$topics = array();
		if ( is_array( $astro_row ) && isset( $astro_row['topics'] ) && is_array( $astro_row['topics'] ) ) {
			foreach ( $astro_row['topics'] as $t ) {
				$t = trim( strtolower( (string) $t ) );
				if ( $t !== '' ) { $topics[] = $t; }
			}
		}
		if ( empty( $topics ) ) {
			$q = function_exists( 'mb_strtolower' ) ? mb_strtolower( $prompt ) : strtolower( $prompt );
			if ( strpos( $q, 'sự nghiệp' ) !== false || strpos( $q, 'công việc' ) !== false ) { $topics[] = 'career'; }
			if ( strpos( $q, 'tài chính' ) !== false || strpos( $q, 'tiền' ) !== false || strpos( $q, 'hợp đồng' ) !== false ) { $topics[] = 'finance'; }
			if ( strpos( $q, 'tình' ) !== false || strpos( $q, 'yêu' ) !== false ) { $topics[] = 'love'; }
		}
		if ( empty( $topics ) ) {
			$topics[] = 'career';
		}
		return array_values( array_unique( $topics ) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — W6 topic-aware aspect line bonus.
	 */
	private function astro_line_matches_topics( string $line, array $topics ): bool {
		$topic_planets = array(
			'career'  => array( 'saturn', 'jupiter', 'sun', 'sao thổ', 'sao mộc', 'mặt trời' ),
			'finance' => array( 'venus', 'jupiter', 'saturn', 'sao kim', 'sao mộc', 'sao thổ' ),
			'love'    => array( 'venus', 'mars', 'moon', 'sao kim', 'sao hỏa', 'mặt trăng' ),
			'health'  => array( 'sun', 'mars', 'saturn', 'chiron', 'mặt trời', 'sao hỏa', 'sao thổ' ),
			'family'  => array( 'moon', 'venus', 'mặt trăng', 'sao kim' ),
			'study'   => array( 'mercury', 'jupiter', 'sao thủy', 'sao mộc' ),
		);
		$line_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $line ) : strtolower( $line );
		foreach ( $topics as $topic ) {
			$topic = strtolower( (string) $topic );
			$planets = isset( $topic_planets[ $topic ] ) ? $topic_planets[ $topic ] : array();
			foreach ( $planets as $planet ) {
				if ( strpos( $line_lc, (string) $planet ) !== false ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — resolve owner uid for async refetch queue.
	 */
	private function resolve_astro_owner_user_id( int $coachee_id, int $fallback_user_id ): int {
		if ( $coachee_id <= 0 ) {
			return max( 0, $fallback_user_id );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_coachees';
		$uid   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT user_id FROM {$table} WHERE id = %d LIMIT 1",
			$coachee_id
		) );
		if ( $uid > 0 ) {
			return $uid;
		}
		return max( 0, $fallback_user_id );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — queue async transit rebuild and trigger wp-cron.
	 */
	private function dispatch_astro_refetch_job( int $coachee_id, int $owner_uid ): bool {
		if ( $coachee_id <= 0 || $owner_uid <= 0 ) {
			return false;
		}
		$args = array( $coachee_id, $owner_uid );
		if ( ! wp_next_scheduled( 'bcpro_async_rebuild_transit', $args ) ) {
			wp_schedule_single_event( time() + 5, 'bcpro_async_rebuild_transit', $args );
		}
		wp_remote_get( site_url( '/?doing_wp_cron' ), array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
		) );
		return true;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — canonical fallback links
	 * for Astro data dispatch + profile remediation flow.
	 */
	private function build_astro_action_links( int $coachee_id ): array {
		$astro_base = function_exists( 'home_url' ) ? (string) home_url( '/astro/' ) : '/astro/';
		$astro_base = rtrim( $astro_base, '/' ) . '/';

		$dashboard_url    = $astro_base . '?bizcity_iframe=1#/dashboard';
		$subjects_url     = $astro_base . '?bizcity_iframe=1#/subjects';
		$profile_data_url = $coachee_id > 0
			? ( $astro_base . '?bizcity_iframe=1#/profiles/' . (int) $coachee_id )
			: $subjects_url;

		return array(
			'dashboard_url'    => $dashboard_url,
			'subjects_url'     => $subjects_url,
			'profile_data_url' => $profile_data_url,
		);
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — G5 checklist quality gate.
	 *
	 * Enforce MIN_COUNT + freshness window before composing astro answer.
	 * Legacy fail-open: if checklist has no real DB rows yet (all updated_at null),
	 * do not block compose.
	 */
	private function evaluate_astro_checklist_quality_gate( int $coachee_id ): array {
		// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — keep checklist gate deterministic
		// after CTA fallback refactor and recovery edits.
		$out = array(
			'blocked'        => false,
			'reason'         => '',
			'failed_keys'    => array(),
			'stale_keys'     => array(),
			'freshness_hours'=> self::ASTRO_CHECKLIST_FRESH_HOURS,
		);

		if ( $coachee_id <= 0 || ! class_exists( 'BizCoach_Astro_Checklist' ) ) {
			return $out;
		}

		$rows = BizCoach_Astro_Checklist::get_for_coachee( $coachee_id );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $out;
		}

		$has_real_rows = false;
		$index         = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) { continue; }
			$key = isset( $row['key'] ) ? (string) $row['key'] : '';
			if ( $key === '' ) { continue; }
			$index[ $key ] = $row;
			if ( ! empty( $row['updated_at'] ) ) {
				$has_real_rows = true;
			}
		}

		// Legacy profiles without checklist bootstrap rows should keep fail-open.
		if ( ! $has_real_rows ) {
			return $out;
		}

		$required_min_counts = array(
			BizCoach_Astro_Checklist::KEY_WESTERN_PLANETS    => 8,
			BizCoach_Astro_Checklist::KEY_WESTERN_HOUSES     => 12,
			BizCoach_Astro_Checklist::KEY_WESTERN_ASPECTS    => 3,
			BizCoach_Astro_Checklist::KEY_WESTERN_WHEEL_CHART=> 1,
			BizCoach_Astro_Checklist::KEY_TRANSIT            => 1,
		);

		$now_ts        = time();
		$fresh_seconds = max( 1, (int) self::ASTRO_CHECKLIST_FRESH_HOURS ) * HOUR_IN_SECONDS;

		foreach ( $required_min_counts as $key => $min_count ) {
			$row = isset( $index[ $key ] ) && is_array( $index[ $key ] ) ? $index[ $key ] : array();
			if ( empty( $row ) ) {
				$out['failed_keys'][] = (string) $key;
				continue;
			}

			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			$count  = isset( $row['count'] ) ? (int) $row['count'] : 0;

			if ( $status === 'failed' || $status === 'pending' || $count < (int) $min_count ) {
				$out['failed_keys'][] = (string) $key;
			}

			$updated_at = (string) ( $row['updated_at'] ?? '' );
			if ( $updated_at !== '' ) {
				$updated_ts = strtotime( $updated_at );
				if ( $updated_ts !== false && ( $now_ts - (int) $updated_ts ) > $fresh_seconds ) {
					$out['stale_keys'][] = (string) $key;
				}
			}
		}

		$out['failed_keys'] = array_values( array_unique( array_map( 'strval', (array) $out['failed_keys'] ) ) );
		$out['stale_keys']  = array_values( array_unique( array_map( 'strval', (array) $out['stale_keys'] ) ) );

		if ( ! empty( $out['failed_keys'] ) ) {
			$out['blocked'] = true;
			$out['reason']  = 'checklist_min_count';
		} elseif ( ! empty( $out['stale_keys'] ) ) {
			$out['blocked'] = true;
			$out['reason']  = 'checklist_stale';
		}

		return $out;
	}

	/**
	 * [2026-06-10 Johnny Chu] ASTRO-CITE 3 — extract astro citation tokens from answer/passages.
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4/A7 — subject-safe URL guard + artifact link fallback.
	 */
	private function extract_astro_citations( $answer_md, $passages, array $subject_payload = array() ) {
		// [2026-07-09 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — restored citation extraction
		// as a dedicated helper so astro citation payload stays stable.
		$answer_md = is_string( $answer_md ) ? $answer_md : '';
		$passages  = is_array( $passages ) ? $passages : array();

		$coachee_id = (int) ( $subject_payload['coachee_id'] ?? 0 );
		$natal_url  = (string) ( $subject_payload['natal_chart_url'] ?? '' );
		$artifact_links = $this->collect_astro_artifact_links_from_passages( $passages );
		if ( $natal_url === '' ) {
			$natal_url = (string) ( $artifact_links['western_vi'] ?? $artifact_links['wheel'] ?? '' );
		}

		$out  = array();
		$seen = array();

		$add_citation = static function ( array &$collector, array &$dedupe, string $type, string $url, string $token = '' ): void {
			$url = trim( $url );
			if ( $url === '' ) { return; }
			$key = $type . '|' . $url;
			if ( isset( $dedupe[ $key ] ) ) { return; }
			$dedupe[ $key ] = true;
			$collector[] = array(
				'token' => $token !== '' ? $token : '[astro:' . $type . '#' . $url . ']',
				'kind'  => 'astro',
				'type'  => $type,
				'url'   => $url,
			);
		};

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — only accept URLs
		// that do not conflict with current coachee_id in query string.
		$matches_coachee_url = static function ( string $url, int $expected_coachee_id ): bool {
			if ( $expected_coachee_id <= 0 || $url === '' ) {
				return true;
			}
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['query'] ) ) {
				return true;
			}
			$query = array();
			parse_str( (string) $parts['query'], $query );
			if ( ! isset( $query['id'] ) ) {
				return true;
			}
			return (int) $query['id'] === $expected_coachee_id;
		};

		if ( $answer_md !== '' && preg_match_all( '/\[astro:([a-z_\-]+)#([^\]\s]+)\]/', $answer_md, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$type = sanitize_key( (string) $m[1] );
				$url  = trim( (string) $m[2] );
				if ( ! preg_match( '#^https?://#i', $url ) ) {
					continue;
				}

				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — same-subject guard.
				if ( $type === 'natal' ) {
					if ( $natal_url !== '' ) {
						if ( $url !== $natal_url ) { continue; }
					} elseif ( ! $matches_coachee_url( $url, $coachee_id ) ) {
						continue;
					}
				} else {
					if ( ! $matches_coachee_url( $url, $coachee_id ) ) {
						continue;
					}
				}

				$add_citation( $out, $seen, $type, $url, (string) $m[0] );
			}
		}

		if ( $natal_url !== '' ) {
			$add_citation( $out, $seen, 'natal', $natal_url, '[astro:natal#' . $natal_url . ']' );
		}

		$transit_fallback = (string) ( $artifact_links['transit_day'] ?? $artifact_links['transit_week'] ?? $artifact_links['transit_month'] ?? $artifact_links['transit_year'] ?? '' );
		if ( $transit_fallback !== '' && $matches_coachee_url( $transit_fallback, $coachee_id ) ) {
			$add_citation( $out, $seen, 'transit', $transit_fallback, '[astro:transit#' . $transit_fallback . ']' );
		}

		foreach ( $passages as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$meta = isset( $p['metadata'] ) && is_array( $p['metadata'] ) ? $p['metadata'] : array();
			$kind = (string) ( $meta['kind'] ?? '' );
			if ( $kind === 'astro_transit_report' || $kind === 'astro_transit_daily' ) {
				$src = (string) ( $meta['source_url'] ?? '' );
				if ( $src !== '' ) {
					if ( $matches_coachee_url( $src, $coachee_id ) ) {
						$add_citation( $out, $seen, 'transit', $src, '[astro:transit#' . $src . ']' );
					}
				}

				if ( $kind === 'astro_transit_daily' && isset( $meta['day_items_raw'] ) && is_array( $meta['day_items_raw'] ) ) {
					foreach ( $meta['day_items_raw'] as $day ) {
						if ( ! is_array( $day ) ) { continue; }
						$day_url = (string) ( $day['day_url'] ?? '' );
						if ( $day_url === '' ) { continue; }
						if ( ! $matches_coachee_url( $day_url, $coachee_id ) ) { continue; }
						$add_citation( $out, $seen, 'transit_day', $day_url, '[astro:transit_day#' . $day_url . ']' );
					}
				}
			}
		}
		return $out;
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A7 — extract unified
	 * artifact links from astro passages metadata.
	 */
	private function collect_astro_artifact_links_from_passages( array $passages ): array {
		$links = array(
			'wheel'         => '',
			'western_vi'    => '',
			'transit_day'   => '',
			'transit_week'  => '',
			'transit_month' => '',
			'transit_year'  => '',
		);

		foreach ( $passages as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$meta = isset( $p['metadata'] ) && is_array( $p['metadata'] ) ? $p['metadata'] : array();
			if ( isset( $meta['artifact_links'] ) && is_array( $meta['artifact_links'] ) ) {
				$al = $meta['artifact_links'];
				foreach ( array_keys( $links ) as $k ) {
					if ( $links[ $k ] === '' && ! empty( $al[ $k ] ) && is_string( $al[ $k ] ) ) {
						$links[ $k ] = (string) $al[ $k ];
					}
				}
			}

			$kind = (string) ( $meta['kind'] ?? '' );
			$src  = (string) ( $meta['source_url'] ?? '' );
			if ( $src !== '' ) {
				if ( ( $kind === 'astro_transit_report' || $kind === 'astro_transit_daily' ) && $links['transit_day'] === '' ) {
					$links['transit_day'] = $src;
				}
				if ( $kind === 'astro_natal_chart' && $links['wheel'] === '' ) {
					$links['wheel'] = $src;
				}
			}
		}

		return $links;
	}

	/* =================================================================
	 *  TBR.W18 — Brain auto-degrade chat path (2026-05-28)
	 *  Short-circuit complete_turn_stream when K=0 candidates && K=0
	 *  tool candidates && web_mode=off && memory_block non-empty.
	 *  Skips Perspective / Tool / Synthesizer layers; runs only:
	 *    1) pipeline_auto_degraded event (FE timeline marker)
	 *    2) Final_Composer::compose_chat_stream (memory-only narrative)
	 *    3) Memory_Tool_Dispatcher (function-call cleanup)
	 *    4) Memory_Writer (L4.7 persist)
	 *    5) cite_resolved + brain_synthesize + assistant_message
	 * ================================================================ */
	private function stream_auto_degrade_chat(
		string $trace_id,
		string $prompt,
		BizCity_Twin_SSE_Writer $sse,
		array $opts,
		float $wall_t0
	): array {
		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );

		$auto_payload = [
			'trace_id'          => $trace_id,
			'surface'           => self::SURFACE,
			'reason'            => 'no_candidates_memory_present',
			'memory_block_len'  => mb_strlen( $memory_block ),
		];
		$sse->emit( 'pipeline_auto_degraded', $auto_payload );
		$this->emit_event( 'pipeline_auto_degraded', $auto_payload );

		/* Emit skeleton phase events so the FE timeline reducer (which
		 * subscribes to perspectives_started / perspectives_done /
		 * synthesis_started / synthesis_done) doesn't stall waiting for
		 * frames that will never arrive. Count=0 + degraded=true lets
		 * the FE collapse these rows into a single "Auto chat" badge. */
		$sse->emit( 'perspectives_started', [
			'trace_id' => $trace_id,
			'count'    => 0,
			'degraded' => true,
		] );
		$sse->emit( 'perspectives_done', [
			'trace_id' => $trace_id,
			'count'    => 0,
			'ms'       => 0,
			'degraded' => true,
		] );
		$tool_decision = [
			'decision' => 'no_op',
			'reason'   => 'auto_degraded',
			'tool'     => '',
		];
		$sse->emit( 'tool_decided', array_merge( [ 'trace_id' => $trace_id ], $tool_decision ) );
		$this->emit_event( 'tool_decided', array_merge(
			[ 'trace_id' => $trace_id, 'surface' => self::SURFACE ],
			$tool_decision
		) );
		$sse->emit( 'synthesis_started', [ 'trace_id' => $trace_id, 'degraded' => true ] );
		$synthesis = [
			'answer_md'       => '',
			'consensus'       => [],
			'tensions'        => [],
			'recommendation'  => '',
			'citations'       => [],
			'tokens'          => 0,
			'model'           => '',
			'fallback'        => 'auto_degraded',
		];
		$sse->emit( 'synthesis_done', [
			'trace_id'       => $trace_id,
			'answer_md'      => '',
			'consensus'      => [],
			'tensions'       => [],
			'recommendation' => '',
			'citations'      => [],
			'tokens'         => 0,
			'ms'             => 0,
			'fallback'       => 'auto_degraded',
		] );

		/* Layer 4.5 — Final Composer (chat variant). */
		$final_t0  = microtime( true );
		$final_seq = 0;
		$final_keepalive_seq = 0;
		$sse->emit( 'final_started', [ 'trace_id' => $trace_id, 'degraded' => true ] );

		$composer = BizCity_TwinBrain_Final_Composer::instance();
		// [2026-07-07 Johnny Chu] HOTFIX — keepalive evidence while chat composer waits.
		$chat_opts = array_merge( $opts, array(
			'on_keepalive' => static function () use ( $sse, $trace_id, &$final_keepalive_seq ) {
				$final_keepalive_seq++;
				$sse->emit( 'final_keepalive', array(
					'trace_id' => $trace_id,
					'seq'      => $final_keepalive_seq,
					'mode'     => 'chat_auto_degrade',
					'status'   => 'still_running',
				) );
				$sse->maybe_heartbeat();
			},
		) );
		$final    = $composer->compose_chat_stream(
			$trace_id,
			$prompt,
			$chat_opts,
			function ( $delta, $accumulated ) use ( $sse, $trace_id, &$final_seq ) {
				$final_seq++;
				$sse->emit( 'final_token', [
					'trace_id' => $trace_id,
					'seq'      => $final_seq,
					'delta'    => (string) $delta,
					'len'      => mb_strlen( (string) $accumulated ),
				] );
			}
		);
		$final_ms   = (int) ( ( microtime( true ) - $final_t0 ) * 1000 );
		$final_text = (string) ( $final['answer_md'] ?? '' );

		$sse->emit( 'final_done', [
			'trace_id'  => $trace_id,
			'answer_md' => $final_text,
			'tokens'    => (int)    ( $final['tokens']   ?? 0 ),
			'model'     => (string) ( $final['model']    ?? '' ),
			'ms'        => $final_ms,
			'chunks'    => $final_seq,
			'fallback'  => (string) ( $final['fallback'] ?? '' ),
			'success'   => ! empty( $final['success'] ),
			'degraded'  => true,
		] );

		/* Memory tool dispatcher — same as main path. */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) && $final_text !== '' ) {
			try {
				$tool_disp = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->dispatch_from_text(
					$final_text,
					[
						'trace_id'   => $trace_id,
						'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
						'surface'    => self::SURFACE,
						'on_event'   => function ( string $event_key, array $payload ) use ( $sse ) {
							$sse->emit( $event_key, $payload );
							$this->emit_event( $event_key, $payload );
						},
					]
				);
				if ( ! empty( $tool_disp['ops'] ) ) {
					$sse->emit( 'memory_tool_summary', [
						'trace_id'   => $trace_id,
						'tool_calls' => (int) $tool_disp['tool_calls'],
						'persisted'  => (int) $tool_disp['persisted'],
						'forgotten'  => (int) $tool_disp['forgotten'],
						'recalled'   => (int) $tool_disp['recalled'],
						'skipped'    => (int) $tool_disp['skipped'],
						'errors'     => (array) $tool_disp['errors'],
						'latency_ms' => (int) $tool_disp['latency_ms'],
					] );
					$rewritten = (string) $tool_disp['rewritten_text'];
					if ( $rewritten !== '' ) {
						$final_text = $rewritten;
						$final['answer_md'] = $rewritten;
					}
				}
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][auto-degrade][memory_tool_dispatcher][error] trace=' . $trace_id . ' ' . $e->getMessage() );
				}
			}
		}

		/* Layer 4.7 — Memory_Writer (persist 'hãy nhớ ...'). */
		if ( class_exists( 'BizCity_TwinBrain_Memory_Writer' ) ) {
			try {
				$mw = BizCity_TwinBrain_Memory_Writer::instance()->extract_and_persist(
					$trace_id,
					$prompt,
					$final_text,
					[
						'user_id'    => (int)    ( $opts['user_id']    ?? get_current_user_id() ),
						'session_id' => (string) ( $opts['session_id'] ?? '' ),
					]
				);
				$write_payload = [
					'trace_id'   => $trace_id,
					'persisted'  => (int)    ( $mw['persisted']  ?? 0 ),
					'mode'       => (string) ( $mw['mode']       ?? '' ),
					'ops'        => (array)  ( $mw['ops']        ?? [] ),
					'latency_ms' => (int)    ( $mw['latency_ms'] ?? 0 ),
				];
				$sse->emit( 'memory_write', $write_payload );
				$this->emit_event( 'memory_write', $write_payload );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[TwinBrain][auto-degrade][memory_write][error] ' . $e->getMessage() );
				}
			}

			// [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — mood sampler (auto-degrade path).
			$session_id = (string) ( $opts['session_id'] ?? '' );
			if ( $session_id !== '' && method_exists( 'BizCity_TwinBrain_Memory_Writer', 'sample_mood' ) ) {
				try {
					$turn_index = $this->count_user_turns_for_session( $session_id );
					$mood = BizCity_TwinBrain_Memory_Writer::instance()->sample_mood( [
						'trace_id'   => $trace_id,
						'session_id' => $session_id,
						'user_id'    => (int) ( $opts['user_id'] ?? get_current_user_id() ),
						'turn_index' => $turn_index,
						'prompt'     => (string) $prompt,
						'answer'     => (string) $final_text,
					] );
					if ( ( $mood['status'] ?? '' ) === 'sampled' ) {
						$sse->emit( 'mood_sampled', [
							'trace_id'   => $trace_id,
							'session_id' => $session_id,
							'turn_index' => (int)    ( $mood['turn_index'] ?? $turn_index ),
							'valence'    => (float)  ( $mood['valence']    ?? 0.0 ),
							'label'      => (string) ( $mood['label']      ?? '' ),
							'event_uuid' => (string) ( $mood['event_uuid'] ?? '' ),
						] );
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TwinBrain][auto-degrade][mood_sampled][error] ' . $e->getMessage() );
					}
				}
			}
		}

		// Promote final text into synthesis.answer_md slot for assistant_message.
		$synthesis['answer_md'] = $final_text;

		/* Cite resolve — empty in auto-degrade path (no notebook citations). */
		$sse->emit( 'cite_resolved', [
			'trace_id'         => $trace_id,
			'cited_entity_ids' => [],
			'cited_passages'   => [],
		] );

		$this->emit_event( 'brain_synthesize', [
			'trace_id'           => $trace_id,
			'surface'            => self::SURFACE,
			'model'              => (string) ( $final['model']  ?? '' ),
			'tokens'             => (int)    ( $final['tokens'] ?? 0 ),
			'ms'                 => $final_ms,
			'consensus_count'    => 0,
			'tensions_count'     => 0,
			'citation_count'     => 0,
			'cited_entity_count' => 0,
			'cited_passage_count'=> 0,
			'fallback'           => 'auto_degraded',
			'answers_in'         => 0,
			'streaming'          => true,
			'auto_degraded'      => true,
		] );
		$this->emit_event( 'assistant_message', [
			'trace_id'             => $trace_id,
			'surface'              => self::SURFACE,
			'text'                 => $final_text,
			'synthesis_metadata'   => [
				'consensus'        => [],
				'tensions'         => [],
				'recommendation'   => '',
				'citations'        => [],
				'citation_count'   => 0,
				'cited_entity_ids' => [],
				'cited_passages'   => [],
				'model'            => (string) ( $final['model']  ?? '' ),
				'tokens'           => (int)    ( $final['tokens'] ?? 0 ),
				'ms'               => $final_ms,
				'fallback'         => 'auto_degraded',
				'streaming'        => true,
				'auto_degraded'    => true,
				'answer_md_synth'  => '',
				'final_compose'    => [
					'tokens'   => (int)    ( $final['tokens']   ?? 0 ),
					'model'    => (string) ( $final['model']    ?? '' ),
					'ms'       => $final_ms,
					'chunks'   => $final_seq,
					'fallback' => (string) ( $final['fallback'] ?? '' ),
					'success'  => ! empty( $final['success'] ),
				],
			],
			// [2026-06-04 Johnny Chu] BS-12 — auto-degrade chat turn replay snapshot.
			'result_snapshot'      => array_merge(
				$this->build_turn_snapshot( $trace_id, $opts, array(
					'web_mode' => 'off',
					'mode'     => 'brain',
					'final'    => array(
						'answer_md' => $final_text,
						'chunks'    => $final_seq,
						'tokens'    => (int) ( $final['tokens'] ?? 0 ),
						'model'     => (string) ( $final['model'] ?? '' ),
						'ms'        => $final_ms,
						'fallback'  => (string) ( $final['fallback'] ?? '' ),
						'success'   => ! empty( $final['success'] ),
					),
					'duration_ms' => (int) ( ( microtime( true ) - $wall_t0 ) * 1000 ),
				) ),
				array( 'auto_degraded' => true )
			),
		] );

		$wall_ms = (int) ( ( microtime( true ) - $wall_t0 ) * 1000 );

		return [
			'ok'                => true,
			'synthesis'         => $synthesis,
			'answers'           => [],
			'cited_entity_ids'  => [],
			'cited_passages'    => [],
			'duration_ms'       => $wall_ms,
			'auto_degraded'     => true,
		];
	}

	/* =================================================================
	 *  Stage 2.5 — Web Research Fallback Layer (TBR.W9)
	 *  PHASE 0.36-UNIFIED §3.5 — dispatcher routes to Quick (W6) or Deep
	 *  (W7) engine based on user-selected web_mode. Emits 5 SSE events
	 *  (web_research_started / web_search_done / web_extract_done /
	 *  web_react_step / web_synthesize_done) so BrainThinkingTimeline can
	 *  render a dedicated Web Research section between perspectives and
	 *  tool_decided rows.
	 * ================================================================ */

	/**
	 * Dispatch Web Research engine + bridge its events to SSE.
	 *
	 * The engines emit via `BizCity_Twin_Event_Bus`. To avoid a double-hop
	 * (bus → wp_options → poll) we register a temporary one-shot listener
	 * here that re-emits each web_* event over the live SSE writer.
	 *
	 * @param string                  $trace_id Brain turn trace id.
	 * @param string                  $prompt   User prompt.
	 * @param string                  $mode     'quick' | 'deep' | 'social' | 'company' | 'med' | 'scholar' | 'nutri' | 'law' | 'tax' | 'gov'.
	 * @param BizCity_Twin_SSE_Writer $sse      Live SSE writer.
	 * @param array                   $opts     Runtime opts (guru_id, web_mode, …).
	 * @return array Web perspective row (or empty array on engine missing).
	 */
	private function dispatch_web_research( string $trace_id, string $prompt, string $mode, BizCity_Twin_SSE_Writer $sse, array $opts ): array {
		$web_events = [
			'web_research_started',
			'web_search_done',
			'web_extract_done',
			'web_react_step',
			'web_synthesize_done',
		];
		$web_events_lookup = array_flip( $web_events );

		// Bridge bus → SSE for the duration of this call. Listener filters
		// by trace_id to stay safe when multiple turns share a request lifecycle.
		// The Twin Event Bus fires `do_action('bizcity_twin_event', $key, $payload)`
		// so we attach to the primary hook with 2 args (R-EVT-1: single channel).
		$bridge = static function ( $event_key, $payload ) use ( $sse, $trace_id, $web_events_lookup ) {
			if ( ! is_string( $event_key ) || ! isset( $web_events_lookup[ $event_key ] ) ) return;
			if ( ! is_array( $payload ) ) return;
			if ( isset( $payload['trace_id'] ) && (string) $payload['trace_id'] !== $trace_id ) return;
			$sse->emit( $event_key, $payload );
			$sse->maybe_heartbeat();
		};
		add_action( 'bizcity_twin_event', $bridge, 10, 2 );

		$row = [];
		try {
			if ( $mode === 'quick' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Quick' ) ) {
					$sse->emit( 'web_research_error', [
						'trace_id' => $trace_id,
						'mode'     => 'quick',
						'error'    => 'engine_missing',
					] );
				} else {
					$row = BizCity_TwinBrain_Web_Quick::instance()->run( $trace_id, $prompt, [
						'guru_id' => (int) ( $opts['guru_id'] ?? 0 ),
					] );
				}
			} elseif ( $mode === 'social' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Social' ) ) {
					$sse->emit( 'web_research_error', [
						'trace_id' => $trace_id,
						'mode'     => 'social',
						'error'    => 'engine_missing',
					] );
				} else {
					$row = BizCity_TwinBrain_Web_Social::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'platform'   => (string) ( $opts['social_platform']   ?? 'combined' ),
						'time_range' => (string) ( $opts['social_time_range'] ?? 'month' ),
					] );
				}
			} elseif ( $mode === 'company' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Company' ) ) {
					$sse->emit( 'web_research_error', [
						'trace_id' => $trace_id,
						'mode'     => 'company',
						'error'    => 'engine_missing',
					] );
				} else {
					$row = BizCity_TwinBrain_Web_Company::instance()->run( $trace_id, $prompt, [
						'guru_id'      => (int) ( $opts['guru_id']      ?? 0 ),
						'website_url'  => (string) ( $opts['company_website_url']  ?? '' ),
						'company_name' => (string) ( $opts['company_name']         ?? '' ),
						'time_range'   => (string) ( $opts['company_time_range']   ?? 'month' ),
					] );
				}
			} elseif ( $mode === 'deep' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Deep' ) ) {
					// TBR.W7 not yet shipped — degrade gracefully to Quick.
					$sse->emit( 'web_research_started', [
						'trace_id' => $trace_id,
						'mode'     => 'deep',
						'query'    => $prompt,
						'note'     => 'deep_engine_pending_fallback_to_quick',
					] );
					if ( class_exists( 'BizCity_TwinBrain_Web_Quick' ) ) {
						$row = BizCity_TwinBrain_Web_Quick::instance()->run( $trace_id, $prompt, [
							'guru_id' => (int) ( $opts['guru_id'] ?? 0 ),
						] );
						$row['mode'] = 'deep'; // mark for FE so badge stays Deep
					}
				} else {
					$row = BizCity_TwinBrain_Web_Deep::instance()->run( $trace_id, $prompt, [
						'guru_id' => (int) ( $opts['guru_id'] ?? 0 ),
					] );
				}
			} elseif ( $mode === 'med' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Med' ) ) {
					$sse->emit( 'web_research_error', [
						'trace_id' => $trace_id,
						'mode'     => 'med',
						'error'    => 'engine_missing',
					] );
				} else {
					$row = BizCity_TwinBrain_Web_Med::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'time_range' => (string) ( $opts['med_time_range'] ?? 'year' ),
					] );
				}
			} elseif ( $mode === 'scholar' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Scholar' ) ) {
					$sse->emit( 'web_research_error', [ 'trace_id' => $trace_id, 'mode' => 'scholar', 'error' => 'engine_missing' ] );
				} else {
					$row = BizCity_TwinBrain_Web_Scholar::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'time_range' => (string) ( $opts['scholar_time_range'] ?? 'year' ),
					] );
				}
			} elseif ( $mode === 'nutri' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Nutri' ) ) {
					$sse->emit( 'web_research_error', [ 'trace_id' => $trace_id, 'mode' => 'nutri', 'error' => 'engine_missing' ] );
				} else {
					$row = BizCity_TwinBrain_Web_Nutri::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'time_range' => (string) ( $opts['nutri_time_range'] ?? 'year' ),
					] );
				}
			} elseif ( $mode === 'law' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Law' ) ) {
					$sse->emit( 'web_research_error', [ 'trace_id' => $trace_id, 'mode' => 'law', 'error' => 'engine_missing' ] );
				} else {
					$row = BizCity_TwinBrain_Web_Law::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'time_range' => (string) ( $opts['law_time_range'] ?? 'year' ),
					] );
				}
			} elseif ( $mode === 'tax' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Tax' ) ) {
					$sse->emit( 'web_research_error', [ 'trace_id' => $trace_id, 'mode' => 'tax', 'error' => 'engine_missing' ] );
				} else {
					$row = BizCity_TwinBrain_Web_Tax::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'time_range' => (string) ( $opts['tax_time_range'] ?? 'year' ),
					] );
				}
			} elseif ( $mode === 'gov' ) {
				if ( ! class_exists( 'BizCity_TwinBrain_Web_Gov' ) ) {
					$sse->emit( 'web_research_error', [ 'trace_id' => $trace_id, 'mode' => 'gov', 'error' => 'engine_missing' ] );
				} else {
					$row = BizCity_TwinBrain_Web_Gov::instance()->run( $trace_id, $prompt, [
						'guru_id'    => (int) ( $opts['guru_id']    ?? 0 ),
						'time_range' => (string) ( $opts['gov_time_range'] ?? 'week' ),
					] );
				}
			}
		} catch ( \Throwable $e ) {
			$sse->emit( 'web_research_error', [
				'trace_id' => $trace_id,
				'mode'     => $mode,
				'error'    => 'engine_throw:' . $e->getMessage(),
			] );
		} finally {
			remove_action( 'bizcity_twin_event', $bridge, 10 );
		}

		return is_array( $row ) ? $row : [];
	}

	/**
	 * [2026-06-03 Johnny Chu] BRAIN-SESSIONS BS-4 — count user_message events
	 * already emitted for the given session_id (current turn included via
	 * `dispatch_v2('user_message', ...)` which runs before final compose).
	 * Used by the mood sampler to honour the configured cadence.
	 */
	private function count_user_turns_for_session( string $session_id ): int {
		if ( $session_id === '' ) return 0;
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_twin_event_stream';
		$prev = $wpdb->suppress_errors( true );
		$n = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl}
			   WHERE session_id = %s AND event_type = 'user_message'",
			$session_id
		) );
		$wpdb->suppress_errors( $prev );
		return $n;
	}

	/**
	 * Resolve cited passage_ids → KG entity_ids via `kg_passage_entities`.
	 * Mirrors the notebook editor pattern (orange cited nodes in graph view).
	 * Capped at 200 entities total; returns sorted unique ints.
	 */
	private function resolve_cited_entity_ids( array $citations ): array {
		if ( empty( $citations ) ) return [];
		$pids = [];
		foreach ( $citations as $c ) {
			$p = (int) ( $c['passage_id'] ?? 0 );
			if ( $p > 0 ) $pids[ $p ] = true;
		}
		if ( empty( $pids ) ) return [];
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_passage_entities();
		$ids = array_keys( $pids );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT entity_id FROM {$tbl} WHERE passage_id IN ({$ph}) LIMIT 200",
			$ids
		) );
		$wpdb->suppress_errors( $prev );

		$out = array_map( 'intval', (array) $rows );
		sort( $out, SORT_NUMERIC );
		return $out;
	}

	/**
	 * Extract all `[nb:X/pY]` (or comma-grouped `[nb:X/pY, nb:X/pY]`) passage
	 * ids from a markdown string. Used to expand the BE-resolved
	 * `cited_passages` map beyond `synthesis.citations[]` so every inline
	 * citation in `answer_md` is clickable in the FE inline Source viewer.
	 */
	private function extract_inline_passage_refs( string $md ): array {
		if ( $md === '' ) return [];
		$pids = [];
		// Match all `nb:DIGITS/pDIGITS` occurrences regardless of brackets.
		if ( preg_match_all( '/nb:\d+\/p(\d+)/i', $md, $mm ) && ! empty( $mm[1] ) ) {
			foreach ( $mm[1] as $p ) {
				$p = (int) $p;
				if ( $p > 0 ) $pids[ $p ] = true;
			}
		}
		return array_keys( $pids );
	}

	/**
	 * Resolve cited passage_ids → metadata for the FE Source viewer.
	 * Returns: `[{notebook_id, passage_id, source_id, source_title,
	 *            heading_path:[], page_no, content_snippet}]` (max 80).
	 * Mirrors `BizCity_KG_REST_Controller::get_entity_passages()` SQL shape.
	 */
	private function resolve_cited_passages( array $citations, array $extra_pids = [] ): array {
		$pids = [];
		foreach ( $citations as $c ) {
			$p = (int) ( $c['passage_id'] ?? 0 );
			if ( $p > 0 ) $pids[ $p ] = true;
		}
		foreach ( $extra_pids as $p ) {
			$p = (int) $p;
			if ( $p > 0 ) $pids[ $p ] = true;
		}
		if ( empty( $pids ) ) return [];
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];

		global $wpdb;
		$db  = BizCity_KG_Database::instance();
		$tp  = $db->tbl_passages();
		$ts  = method_exists( $db, 'tbl_sources' ) ? $db->tbl_sources() : '';
		$ids = array_slice( array_keys( $pids ), 0, 80 );
		$ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$prev = $wpdb->suppress_errors( true );
		if ( $ts ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT p.id AS passage_id, p.notebook_id, p.source_id, p.content, p.metadata,
				        s.title AS source_title
				 FROM {$tp} p
				 LEFT JOIN {$ts} s ON s.id = p.source_id
				 WHERE p.id IN ({$ph})",
				$ids
			), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id AS passage_id, notebook_id, source_id, content, metadata
				 FROM {$tp} WHERE id IN ({$ph})",
				$ids
			), ARRAY_A );
		}
		$wpdb->suppress_errors( $prev );

		$out = [];
		foreach ( (array) $rows as $r ) {
			$content = (string) ( $r['content'] ?? '' );
			$snippet = mb_substr( $content, 0, 240 );
			if ( mb_strlen( $content ) > 240 ) $snippet .= '…';
			$heading_path = [];
			$page_no = null;
			if ( ! empty( $r['metadata'] ) ) {
				$meta = json_decode( (string) $r['metadata'], true );
				if ( is_array( $meta ) ) {
					if ( isset( $meta['heading_path'] ) && is_array( $meta['heading_path'] ) ) {
						$heading_path = array_values( array_map( 'strval', $meta['heading_path'] ) );
					}
					if ( isset( $meta['page_no'] ) ) {
						$page_no = (int) $meta['page_no'];
					}
				}
			}
			$out[] = [
				'notebook_id'     => (int) ( $r['notebook_id'] ?? 0 ),
				'passage_id'      => (int) ( $r['passage_id']  ?? 0 ),
				'source_id'       => (int) ( $r['source_id']   ?? 0 ),
				'source_title'    => (string) ( $r['source_title'] ?? '' ),
				'heading_path'    => $heading_path,
				'page_no'         => $page_no,
				'content_snippet' => $snippet,
			];
		}

		// Backfill: passages with source_id=0 (orphans / pre-Wave-0.6.C ingest)
		// have no clickable source in the FE Source viewer. Look up the first
		// kg_sources row scoped to that notebook and reuse it so the user can
		// at least open the notebook's primary source for context.
		if ( $ts && ! empty( $out ) ) {
			$missing_nb = [];
			foreach ( $out as $row ) {
				if ( (int) $row['source_id'] <= 0 && (int) $row['notebook_id'] > 0 ) {
					$missing_nb[ (int) $row['notebook_id'] ] = true;
				}
			}
			if ( ! empty( $missing_nb ) ) {
				$nb_ids   = array_keys( $missing_nb );
				$nb_ph    = implode( ',', array_fill( 0, count( $nb_ids ), '%s' ) );
				$prev2    = $wpdb->suppress_errors( true );
				$nb_rows  = $wpdb->get_results( $wpdb->prepare(
					"SELECT scope_id, MIN(id) AS source_id, MIN(title) AS title
					 FROM {$ts}
					 WHERE scope_type = 'notebook' AND scope_id IN ({$nb_ph})
					 GROUP BY scope_id",
					array_map( 'strval', $nb_ids )
				), ARRAY_A );
				$wpdb->suppress_errors( $prev2 );
				$nb_map = [];
				foreach ( (array) $nb_rows as $nr ) {
					$nb_map[ (int) $nr['scope_id'] ] = [
						'source_id'    => (int) $nr['source_id'],
						'source_title' => (string) $nr['title'],
					];
				}
				foreach ( $out as &$row ) {
					if ( (int) $row['source_id'] > 0 ) continue;
					$nb = (int) $row['notebook_id'];
					if ( isset( $nb_map[ $nb ] ) ) {
						$row['source_id'] = $nb_map[ $nb ]['source_id'];
						if ( $row['source_title'] === '' ) {
							$row['source_title'] = $nb_map[ $nb ]['source_title'];
						}
					}
				}
				unset( $row );
			}
		}

		return $out;
	}

	/* ============================================================
	 * Helpers
	 * ============================================================ */

	private function new_trace_id(): string {
		// UUID v4 is enough for now; v7 upgrade tracked in PHASE-0.36 sprint .1.
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'tbr_' . wp_generate_uuid4();
		}
		return uniqid( 'tbr_', true );
	}

	private function has_explicit_skill_mention( string $prompt ): bool {
		return (bool) preg_match( '/(^|\s)@@[a-z0-9_\-]+/i', $prompt );
	}

	/**
	 * PHASE-0.35 / F7.C4 — Layer 5 Tool_Decision.
	 *
	 * Pure decision step (no execution): pick the top tool candidate above
	 * threshold (or the forced one) and resolve its schema for hand-off to
	 * the synthesizer prompt. Dispatch is the responsibility of F7.C5.
	 *
	 * Decisions:
	 *   - 'no_tool'        : no candidate or top score below threshold (and not forced).
	 *   - 'await_dispatch' : a tool was picked; F7.C5 / FE confirm flow will execute.
	 *
	 * @return array{
	 *   decision:string, reason:string, threshold:float,
	 *   tool: array{slug:string,score:float,reason:string,forced:bool,schema:?array,needs_approval:bool,tool_class:string,guru_id:int}|null
	 * }
	 */
	private function decide_tool( string $trace_id, array $tool_candidates, string $tool_force, int $guru_id ): array {
		$threshold = (float) BIZCITY_TWINBRAIN_TOOL_INTENT_THRESHOLD;

		if ( empty( $tool_candidates ) ) {
			return array(
				'decision'  => 'no_tool',
				'reason'    => 'no_candidates',
				'threshold' => $threshold,
				'tool'      => null,
			);
		}

		// Forced candidates win regardless of score.
		$top = null;
		foreach ( $tool_candidates as $c ) {
			$r = (string) ( $c['reason'] ?? '' );
			if ( strpos( $r, 'forced' ) === 0 ) { $top = $c; break; }
		}
		if ( $top === null ) {
			$ranked = $tool_candidates;
			usort( $ranked, function ( $a, $b ) {
				$as = (float) ( $a['score'] ?? 0 );
				$bs = (float) ( $b['score'] ?? 0 );
				if ( $as === $bs ) return 0;
				return ( $bs > $as ) ? 1 : -1;
			} );
			$top = $ranked[0];
		}

		$slug      = (string) ( $top['skill_slug'] ?? '' );
		$score     = (float)  ( $top['score']      ?? 0 );
		$reason    = (string) ( $top['reason']     ?? '' );
		$is_forced = ( strpos( $reason, 'forced' ) === 0 );

		if ( $slug === '' ) {
			return array(
				'decision'  => 'no_tool',
				'reason'    => 'empty_slug',
				'threshold' => $threshold,
				'tool'      => null,
			);
		}

		if ( ! $is_forced && $score < $threshold ) {
			return array(
				'decision'  => 'no_tool',
				'reason'    => 'below_threshold',
				'threshold' => $threshold,
				'tool'      => array(
					'slug'    => $slug,
					'score'   => $score,
					'reason'  => $reason,
					'forced'  => false,
				),
			);
		}

		// Best-effort schema + approval lookup. Tool may not be in twin tool
		// registry (some skills live only as bizcity_skills rows for matching).
		$schema     = null;
		$needs_ap   = false;
		$tool_class = '';
		if ( class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$tool = BizCity_Twin_Tool_Registry::instance()->get( $slug );
			if ( $tool ) {
				if ( method_exists( $tool, 'parameters_schema' ) ) {
					$schema = (array) $tool->parameters_schema();
				}
				if ( method_exists( $tool, 'needs_approval' ) ) {
					try { $needs_ap = (bool) $tool->needs_approval( array(), array() ); }
					catch ( \Throwable $e ) { $needs_ap = false; }
				}
				if ( method_exists( $tool, 'tool_class' ) ) {
					try { $tool_class = (string) $tool->tool_class(); }
					catch ( \Throwable $e ) { $tool_class = ''; }
				}
			}
		}

		return array(
			'decision'  => 'await_dispatch',
			'reason'    => $reason,
			'threshold' => $threshold,
			'tool'      => array(
				'slug'           => $slug,
				'score'          => $score,
				'reason'         => $reason,
				'forced'         => $is_forced,
				'schema'         => $schema,
				'needs_approval' => $needs_ap,
				'tool_class'     => $tool_class,
				'guru_id'        => $guru_id,
			),
		);
	}

	/**
	 * Build a synthetic `tool_results` array hinting the Synthesizer that a
	 * tool is planned but has not been executed yet (F7.C5 will replace this
	 * with real results). Keeps the synth prompt aware so it doesn't fabricate
	 * tool output.
	 */
	private function planned_tool_results( array $decision ): array {
		if ( empty( $decision['tool'] ) || ( $decision['decision'] ?? '' ) !== 'await_dispatch' ) {
			return array();
		}
		$slug = (string) ( $decision['tool']['slug'] ?? '' );
		if ( $slug === '' ) return array();

		return array(
			array(
				'skill'  => 'PLANNED:' . $slug,
				'result' => 'AWAITING DISPATCH (Layer 6 / F7.C5). Do not invent the result; reference the planned tool by slug only.',
			),
		);
	}

	/**
	 * PHASE-0.35 / F7.C5 — Layer 6 Tool_Dispatch.
	 *
	 * Pure execution step: invoke the decided tool via Twin_Tool_Registry and
	 * normalise its return shape into a `tool_done` payload. Skips when:
	 *   - no decision made (decision != 'await_dispatch')
	 *   - tool requires HIL approval (Wave 0 — defer to F7.C5.1)
	 *   - tool not registered in Twin_Tool_Registry
	 *
	 * Best-effort: any \Throwable from the tool is caught and surfaced as
	 * `ok=false`. NEVER aborts the synthesis pipeline.
	 *
	 * @param string $trace_id
	 * @param array  $decision  Output of decide_tool().
	 * @param array  $opts      Forwarded ctx (user_id, scope, guru_id…).
	 * @return array{
	 *   ok: bool, skipped: bool, skip_reason: string,
	 *   tool_slug: string, tool_class: string,
	 *   ms: int, summary: string, error: string,
	 *   result: mixed, sources: array, citation_ids: array,
	 *   canvas_open: array|null
	 * }
	 */
	private function dispatch_tool( string $trace_id, array $decision, array $opts = [] ): array {
		$base = array(
			'ok'           => false,
			'skipped'      => true,
			'skip_reason'  => '',
			'tool_slug'    => '',
			'tool_class'   => '',
			'ms'           => 0,
			'summary'      => '',
			'error'        => '',
			'result'       => null,
			'sources'      => array(),
			'citation_ids' => array(),
			'canvas_open'  => null,
		);

		if ( empty( $decision['tool'] ) || ( $decision['decision'] ?? '' ) !== 'await_dispatch' ) {
			$base['skip_reason'] = 'no_decision';
			return $base;
		}

		$tool_meta            = (array) $decision['tool'];
		$slug                 = (string) ( $tool_meta['slug'] ?? '' );
		$base['tool_slug']    = $slug;
		$base['tool_class']   = (string) ( $tool_meta['tool_class'] ?? '' );

		if ( $slug === '' ) {
			$base['skip_reason'] = 'empty_slug';
			return $base;
		}
		if ( ! empty( $tool_meta['needs_approval'] ) ) {
			// HIL flow lives in F7.C5.1 — emit await_user with tool_slug instead
			// of dispatching. For now we just skip and let synthesizer note it.
			$base['skip_reason'] = 'needs_approval';
			return $base;
		}

		// [2026-06-13 Johnny Chu] PHASE-0.40 G3 Wave-B — bizcity_twinbrain_tool_dispatch_gate
		// Allows external plugins (e.g. CRM admin-chat grants) to intercept BEFORE execution.
		// Filter receives ($veto, $tool_meta, $ctx) and may return:
		//   false             → allow (default)
		//   'confirm_pending' → block + confirm token stored by filter handler
		//   'denied'          → block silently (caller logged reason separately)
		$ctx_for_gate = array(
			'trace_id'        => $trace_id,
			'user_id'         => (int) ( $opts['user_id']         ?? get_current_user_id() ),
			'session_id'      => (string) ( $opts['session_id']   ?? '' ),
			'guru_id'         => (int) ( $opts['guru_id']         ?? 0 ),
			'inbound_chat_id' => (string) ( $opts['inbound_chat_id'] ?? '' ),
			'surface'         => self::SURFACE,
		);
		$gate_result = apply_filters( 'bizcity_twinbrain_tool_dispatch_gate', false, $tool_meta, $ctx_for_gate );
		if ( $gate_result === 'confirm_pending' ) {
			$base['skip_reason'] = 'policy_confirm';
			return $base;
		}
		if ( $gate_result === 'denied' ) {
			$base['skip_reason'] = 'policy_deny';
			return $base;
		}

		if ( ! class_exists( 'BizCity_Twin_Tool_Registry' ) ) {
			$base['skip_reason'] = 'registry_missing';
			return $base;
		}

		$tool = BizCity_Twin_Tool_Registry::instance()->get( $slug );
		if ( ! $tool || ! method_exists( $tool, 'execute' ) ) {
			$base['skip_reason'] = 'tool_not_in_registry';
			return $base;
		}

		// Build minimal context for the tool. Tool-specific args are NOT
		// extracted from prompt yet (Wave 0) — call with empty args; tools
		// that need args will gracefully error and the user can retry with
		// `#tool` token + future arg-builder.
		$ctx = array(
			'trace_id'   => $trace_id,
			'user_id'    => (int) ( $opts['user_id']    ?? get_current_user_id() ),
			'session_id' => (string) ( $opts['session_id'] ?? '' ),
			'scope'      => (array) ( $opts['scope']    ?? array() ),
			'surface'    => self::SURFACE,
			'guru_id'    => (int) ( $tool_meta['guru_id'] ?? ( $opts['guru_id'] ?? 0 ) ),
		);

		$base['skipped'] = false;
		$t0  = microtime( true );
		$ret = array();
		try {
			$ret = (array) $tool->execute( array(), $ctx );
		} catch ( \Throwable $e ) {
			$base['ms']    = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			$base['ok']    = false;
			$base['error'] = $e->getMessage();
			return $base;
		}
		$base['ms']           = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$base['ok']           = (bool) ( $ret['ok'] ?? false );
		$base['summary']      = (string) ( $ret['summary'] ?? '' );
		$base['error']        = (string) ( $ret['error']   ?? '' );
		$base['result']       = isset( $ret['result'] ) ? $ret['result'] : null;
		$base['sources']      = isset( $ret['sources'] )      && is_array( $ret['sources'] )      ? $ret['sources']      : array();
		$base['citation_ids'] = isset( $ret['citation_ids'] ) && is_array( $ret['citation_ids'] ) ? $ret['citation_ids'] : array();

		// canvas_open passthrough (R-MPRT §11): tool may attach
		// `{url, type, output_id}` for FE inline panel flip. Also derive a
		// minimal canvas hint when tool returned a notebook_id artifact.
		if ( isset( $ret['canvas_open'] ) && is_array( $ret['canvas_open'] ) ) {
			$base['canvas_open'] = $ret['canvas_open'];
		} elseif ( isset( $ret['artifact'] ) && is_array( $ret['artifact'] ) ) {
			$art = $ret['artifact'];
			$nb  = (int) ( $art['notebook_id'] ?? 0 );
			if ( $nb > 0 ) {
				$base['canvas_open'] = array(
					'type'        => (string) ( $art['type'] ?? 'notebook_artifact' ),
					'notebook_id' => $nb,
					'output_id'   => (int) ( $art['source_id'] ?? 0 ),
				);
			}
		}
		return $base;
	}

	/**
	 * Convert a dispatch result into the `tool_results[]` shape the
	 * Synthesizer expects (so the LLM can cite real output instead of the
	 * `PLANNED:` placeholder).
	 */
	private function dispatched_tool_results( array $dispatch ): array {
		if ( empty( $dispatch['tool_slug'] ) || ! empty( $dispatch['skipped'] ) ) {
			return array();
		}
		$slug    = (string) $dispatch['tool_slug'];
		$summary = (string) $dispatch['summary'];
		$ok      = ! empty( $dispatch['ok'] );
		$payload = array(
			'skill'  => $slug,
			'result' => $ok
				? ( $summary !== '' ? $summary : 'Tool executed successfully (no summary).' )
				: ( 'Tool execution failed: ' . ( (string) ( $dispatch['error'] ?: 'unknown error' ) ) ),
		);
		if ( ! empty( $dispatch['sources'] ) ) {
			$payload['sources'] = $dispatch['sources'];
		}
		return array( $payload );
	}

	/**
	 * Build the `tool_done` SSE/event payload from a dispatch result.
	 * Kept lean — full result object stays server-side; FE gets a summary +
	 * canvas_open hint.
	 */
	private function tool_done_payload( string $trace_id, array $dispatch, array $decision ): array {
		$status = ! empty( $dispatch['skipped'] )
			? 'skipped'
			: ( ! empty( $dispatch['ok'] ) ? 'ok' : 'error' );
		return array(
			'trace_id'        => $trace_id,
			'surface'         => self::SURFACE,
			'tool_slug'       => (string) $dispatch['tool_slug'],
			'tool_class'      => (string) $dispatch['tool_class'],
			'status'          => $status,
			'skipped'         => (bool) $dispatch['skipped'],
			'skip_reason'     => (string) $dispatch['skip_reason'],
			'ms'              => (int) $dispatch['ms'],
			'summary'         => (string) $dispatch['summary'],
			'error'           => (string) $dispatch['error'],
			'sources_count'   => is_array( $dispatch['sources'] )      ? count( $dispatch['sources'] )      : 0,
			'citation_count'  => is_array( $dispatch['citation_ids'] ) ? count( $dispatch['citation_ids'] ) : 0,
			'canvas_open'     => $dispatch['canvas_open'],
			'decision_reason' => (string) ( $decision['reason'] ?? '' ),
		);
	}

	/**
	 * Emit through the canonical Event Bus (R-EVT-1).
	 * Falls back to a low-noise error_log if Event Bus is not booted (e.g. early CLI).
	 */
	private function emit_event( string $event_key, array $payload ): void {
		// [2026-06-04 Johnny Chu] BS-12 — durably persist conversational turns to
		// the event_stream (tagged with session_id) so Brain Session replay can
		// rebuild the full transcript. The V1 dispatch() below is telemetry-only
		// (do_action, no INSERT); user_message / assistant_message MUST survive as
		// rows so list_turns()/the sessions VIEW (turn_count) can read them back.
		if ( ( $event_key === 'user_message' || $event_key === 'assistant_message' )
			&& $this->current_session_id !== ''
			&& class_exists( 'BizCity_Twin_Event_Bus' )
			&& method_exists( 'BizCity_Twin_Event_Bus', 'dispatch_v2' ) ) {
			try {
				$persist_payload = $payload;
				// Taxonomy requires `content`; mirror the user-visible text.
				if ( ! isset( $persist_payload['content'] ) ) {
					$persist_payload['content'] = (string) ( $payload['text'] ?? '' );
				}
				BizCity_Twin_Event_Bus::dispatch_v2(
					$event_key,
					$persist_payload,
					[
						'session_id'   => $this->current_session_id,
						'user_id'      => (int) ( $payload['user_id'] ?? get_current_user_id() ),
						'trace_id'     => (string) ( $payload['trace_id'] ?? '' ),
						'event_source' => 'system',
					]
				);
			} catch ( \Throwable $e ) {
				error_log( '[TwinBrain] turn persist failed: ' . $event_key . ' — ' . $e->getMessage() );
			}
		}

		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $event_key, $payload );
				return;
			} catch ( \Throwable $e ) {
				// Schema validation may throw for new event_types until taxonomy is updated;
				// surface but don't block Wave 0.
				error_log( '[TwinBrain] event dispatch failed: ' . $event_key . ' — ' . $e->getMessage() );
			}
		}
		error_log( '[TwinBrain][noop-bus] ' . $event_key . ' ' . wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
	}
}
