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

	/** @var self|null */
	private static $instance = null;

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
		$final_t0   = microtime( true );
		$final_seq  = 0;
		$sse->emit( 'final_started', [ 'trace_id' => $trace_id ] );

		$composer = BizCity_TwinBrain_Final_Composer::instance();
		$final    = $composer->compose_stream(
			$trace_id,
			$prompt,
			$synthesis,
			$answers,
			$opts,
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
		$sse->emit( 'final_started', [ 'trace_id' => $trace_id, 'degraded' => true ] );

		$composer = BizCity_TwinBrain_Final_Composer::instance();
		$final    = $composer->compose_chat_stream(
			$trace_id,
			$prompt,
			$opts,
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
