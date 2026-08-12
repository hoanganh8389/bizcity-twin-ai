<?php
/**
 * BizCity TwinBrain Final Composer — Stage 4.5 (PHASE 0.36-UNIFIED · TBR.W16).
 *
 * Layer 4.5 sits BETWEEN Synthesizer (Layer 4) and Citation Resolver
 * (Layer 7). Its job is to take the structured Synthesizer output —
 * `{consensus[], tensions[], recommendation, answer_md, citations[]}`
 * — plus the raw inputs (perspectives + web rows + tool results) and
 * produce a **streaming, narrative final answer** that the user actually
 * sees in the chat. The Synthesizer answer_md remains in the timeline
 * as the "behind the scenes" analysis card; this composer's output is
 * the headline.
 *
 * Why a separate layer (vs just streaming the Synthesizer call):
 *   1. Synthesizer must return STRUCTURED JSON (consensus / tensions
 *      arrays). Streaming JSON tokens to the UI is unhelpful — the FE
 *      cannot incrementally render half-parsed objects without flicker.
 *   2. Final Composer outputs FREE-FORM markdown — every token can be
 *      rendered immediately, citation chips hot-swap as `[nb:X/pY]` /
 *      `[web:N#URL]` tokens close.
 *   3. Decouples "what the panel of perspectives concluded" from "what
 *      the user reads" — important when persona / guru voicing differs
 *      from clinical synthesis style.
 *
 * Streaming contract (R-EVT-4):
 *   on_token($delta, $accumulated)  — called per SSE delta from gateway
 *   returns: {
 *     success, answer_md, model, tokens, ms, error,
 *     fallback (when LLM down → echoes synthesizer.answer_md unchanged)
 *   }
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-21 (PHASE 0.36-UNIFIED · TBR.W16)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Final_Composer {

	const PURPOSE         = 'twinbrain_final_compose';
	const PURPOSE_WISDOM  = 'twinbrain_wisdom'; // Legacy explicit override only; default user-facing composition uses chat.
	const TEMPERATURE     = 0.35;
	const MAX_TOKENS      = 2700; // [2026-08-02 Johnny Chu] PHASE-TWINWEB-NB-DEPTH — raise normal Notebook final output budget for sourced answers.
	const MAX_TOKENS_GURU = 3200; // [2026-07-18 Johnny Chu] PHASE-TWINWEB-C-ENDUSER — Guru-bound answers can carry more sourced detail.
	const TIMEOUT_S       = 60;

	/** Truncation caps for prompt context blocks. */
	const ANS_TRUNC      = 1200;
	const WEB_SNIPPET    = 220;
	const MAX_WEB_ROWS   = 8;
	const MAX_NB_ROWS    = 6;
	const NOTEBOOK_DEPTH_DEFAULT = 'normal'; // [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — product-level answer-depth profile default for Notebook mode.

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Compose final answer with streaming token delivery.
	 *
	 * @param string        $trace_id    Turn trace id (for gateway logs).
	 * @param string        $prompt      User prompt.
	 * @param array         $synth       Synthesizer output (full row).
	 * @param array         $answers     All perspective rows (local nb + web).
	 * @param array         $opts        { guru_id?, model?, locale? }
	 * @param callable|null $on_token    Optional fn($delta, $accumulated) for SSE relay.
	 * @return array { success, answer_md, model, tokens, ms, fallback, error }
	 */
	public function compose_stream(
		string $trace_id,
		string $prompt,
		array $synth,
		array $answers,
		array $opts = [],
		$on_token = null
	): array {
		$t0 = microtime( true );
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — resolve depth/purpose before gateway gate so fallback metadata stays consistent.
		$has_guru = ! empty( $opts['guru_id'] );
		$depth_profile = $this->resolve_notebook_depth_profile( $prompt, $opts, $has_guru );
		$opts['notebook_depth_profile']      = (string) $depth_profile['profile'];
		$opts['notebook_depth_profile_meta'] = $depth_profile;
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — classify list/product intent before prompt build and post-compose enforcement.
		$answer_intent = $this->resolve_answer_intent( $prompt, $opts );
		$opts['answer_intent']      = (string) ( $answer_intent['intent'] ?? 'general' );
		$opts['answer_intent_meta'] = $answer_intent;
		// [2026-08-05 Johnny Chu] V4.1/V4.2 — resolve a deterministic answer skeleton before composing prose.
		$answer_skeleton = $this->resolve_answer_skeleton( $prompt, $answer_intent, $opts );
		$opts['answer_skeleton'] = $answer_skeleton;
		$followup_contract = $this->resolve_followup_contract( $opts );
		$opts['named_evidence_candidates'] = $this->extract_named_evidence_candidates( $opts );
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — extract concrete product entities from evidence excerpts before composing.
		$opts['product_entities'] = isset( $opts['product_entities'] ) && is_array( $opts['product_entities'] )
			? $opts['product_entities']
			: $this->extract_product_entities( $opts );
		$llm_purpose = $this->resolve_llm_purpose( $depth_profile, $opts );

		// Degrade gracefully when gateway not configured: just echo the
		// synthesizer's answer_md so the FE row still gets populated.
		if ( ! $this->gateway_ready() ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — attachment questions must still return Vision/File intake result on final fallback.
			$ans = $this->build_multimodal_fallback_answer( $opts );
			if ( '' === $ans ) {
				$ans = (string) ( $synth['answer_md'] ?? '' );
			}
			$source_contract = $this->apply_notebook_source_contract( $ans, $opts );
			$skeleton_validation = $this->validate_answer_skeleton( (string) $source_contract['answer_md'], $answer_skeleton, $opts );
			$ans = (string) $skeleton_validation['answer_md'];
			if ( is_callable( $on_token ) && $ans !== '' ) {
				// Single emit so FE final-row still renders something.
				call_user_func( $on_token, $ans, $ans );
			}
			return [
				'success'   => $ans !== '',
				'answer_md' => $ans,
				'model'     => '',
				'tokens'    => 0,
				'ms'        => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'fallback'  => 'gateway_unavailable',
				'error'     => '',
				'llm_purpose' => $llm_purpose,
				'answer_intent' => (string) ( $answer_intent['intent'] ?? 'general' ),
				'skeleton_id' => (string) $answer_skeleton['id'],
				'required_sections' => (array) $answer_skeleton['required_sections'],
				'skeleton_quality' => (string) $skeleton_validation['quality'],
				'skeleton_violations' => (array) $skeleton_validation['violations'],
				'structure_gate' => (string) $skeleton_validation['structure_gate'],
				'safety_gate' => (string) $skeleton_validation['safety_gate'],
				'entity_gate' => (string) $skeleton_validation['entity_gate'],
				'next_action_gate' => (string) $skeleton_validation['next_action_gate'],
				'followup_gate' => (string) ( $skeleton_validation['followup_gate'] ?? 'not_required' ),
				'followup_contract' => (array) ( $skeleton_validation['followup_contract'] ?? $followup_contract ),
				'named_evidence_count' => count( (array) ( $opts['named_evidence_candidates'] ?? array() ) ),
				'product_entity_count' => count( (array) ( $opts['product_entities'] ?? array() ) ),
				'product_name_entity_count' => count( $this->filter_product_name_entities( (array) ( $opts['product_entities'] ?? array() ) ) ),
				'notebook_depth_profile' => (string) $depth_profile['profile'],
				'notebook_depth_budget'  => $depth_profile,
				'invalid_notebook_citations_stripped' => (int) $source_contract['invalid_count'],
				'invalid_notebook_citation_tokens'    => (array) $source_contract['invalid_tokens'],
			];
		}

		$messages = $this->build_messages( $prompt, $synth, $answers, $opts );
		$req_surface = sanitize_key( (string) ( $opts['surface'] ?? 'twinbrain' ) );
		$usage_flow  = ( $req_surface === 'twinweb' ) ? 'b2b2c' : 'b2b';
		$usage_channel = ( $req_surface === 'twinweb' ) ? 'twinclient' : 'twinchat';

		$client = BizCity_LLM_Client::instance();
		$model  = (string) ( $opts['model'] ?? '' );
		if ( $model === '' ) {
			$model = (string) apply_filters(
				'bizcity_twinbrain_final_compose_model',
				$client->get_model( $llm_purpose ),
				$llm_purpose,
				$depth_profile,
				$opts
			);
		}

		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — allow per-call
		// temperature/max_tokens override so astro mode can widen output depth.
		$temperature = isset( $opts['final_compose_temperature'] )
			? (float) $opts['final_compose_temperature']
			: self::TEMPERATURE;
		if ( $temperature < 0 ) {
			$temperature = 0;
		} elseif ( $temperature > 1.5 ) {
			$temperature = 1.5;
		}

		$max_tokens = isset( $depth_profile['max_tokens'] ) ? (int) $depth_profile['max_tokens'] : ( $has_guru ? self::MAX_TOKENS_GURU : self::MAX_TOKENS );
		if ( isset( $opts['final_compose_max_tokens'] ) ) {
			$max_tokens = max( 200, (int) $opts['final_compose_max_tokens'] );
		}

		// Accumulator wrapper so we capture full text even if caller passes
		// no on_token (probe mode).
		$accumulated = '';
		$delta_n     = 0;
		$relay = function ( $delta, $full ) use ( &$accumulated, &$delta_n, $on_token ) {
			$accumulated = (string) $full;
			$delta_n++;
			if ( is_callable( $on_token ) ) {
				call_user_func( $on_token, (string) $delta, (string) $full );
			}
		};

		$result = $client->chat_stream(
			$messages,
			[
				'purpose'     => $llm_purpose,
				'flow'        => $usage_flow,
				'surface'     => $req_surface,
				'channel'     => $usage_channel,
				'model'       => $model,
				'temperature' => $temperature,
				'max_tokens'  => $max_tokens,
				'timeout'     => self::TIMEOUT_S,
				// [2026-07-07 Johnny Chu] HOTFIX — forward keepalive callback so
				// runtime can emit `final_keepalive` SSE while waiting next token.
				'on_keepalive' => isset( $opts['on_keepalive'] ) ? $opts['on_keepalive'] : null,
				// Trace id propagation lets the gateway link this stream to
				// the parent turn in usage / debug logs.
				'extra_body'  => [
					'site_url'   => home_url(),
					'trace_id'   => $trace_id,
					'twinbrain_purpose' => $llm_purpose,
					'notebook_depth_profile' => (string) $depth_profile['profile'],
					'answer_intent' => (string) ( $answer_intent['intent'] ?? 'general' ),
				],
			],
			$relay
		);

		$elapsed = (int) ( ( microtime( true ) - $t0 ) * 1000 );

		// Streaming returned empty / errored → fall back to synthesizer answer
		// to keep the user-facing message non-blank.
		$final_text = trim( (string) ( $result['message'] ?? $accumulated ) );
		if ( empty( $result['success'] ) || $final_text === '' ) {
			$fallback_text = (string) ( $synth['answer_md'] ?? '' );
			$source_contract = $this->apply_notebook_source_contract( $fallback_text, $opts );
			$skeleton_validation = $this->validate_answer_skeleton( (string) $source_contract['answer_md'], $answer_skeleton, $opts );
			$fallback_text = (string) $skeleton_validation['answer_md'];
			if ( is_callable( $on_token ) && $fallback_text !== '' && $delta_n === 0 ) {
				// FE never received any deltas — emit synth as a single chunk.
				call_user_func( $on_token, $fallback_text, $fallback_text );
			} elseif ( is_callable( $on_token ) && ! empty( $skeleton_validation['followup_contract']['rendered'] ) ) {
				// [2026-08-10 Johnny Chu] GOAL-FOLLOWUP-1 — relay deterministic follow-up repair after streamed model text.
				call_user_func( $on_token, "\n\n**Một câu hỏi để mình tiếp tục:** " . (string) $skeleton_validation['followup_contract']['question'], $fallback_text );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][final-compose] trace=' . $trace_id
					. ' fallback err=' . ( $result['error'] ?? 'empty' )
					. ' deltas=' . $delta_n
					. ' synth_len=' . mb_strlen( $fallback_text )
				);
			}
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — pass quota_exhausted + quota_layer
			// through so runtime can emit SSE error event for FE QuotaErrorBanner.
			// [2026-06-10 Johnny Chu] R-QUOTA-KEY — also forward usage counters.
			return [
				'success'           => $fallback_text !== '',
				'answer_md'         => $fallback_text,
				'model'             => (string) ( $result['model'] ?? $model ),
				'tokens'            => (int) ( $result['usage']['total_tokens'] ?? 0 ),
				'ms'                => $elapsed,
				'fallback'          => 'stream_empty:' . ( $result['error'] ?? 'unknown' ),
				'error'             => (string) ( $result['error'] ?? '' ),
				'quota_exhausted'   => ! empty( $result['quota_exhausted'] ),
				'quota_layer'       => isset( $result['quota_layer'] )     ? (string) $result['quota_layer']     : '',
				'tier'              => isset( $result['tier'] )             ? (string) $result['tier']             : '',
				'used_requests'     => isset( $result['used_requests'] )    ? (int)    $result['used_requests']    : 0,
				'cap_requests_day'  => isset( $result['cap_requests_day'] ) ? (int)    $result['cap_requests_day'] : 0,
				'used_usd'          => isset( $result['used_usd'] )         ? (float)  $result['used_usd']         : 0.0,
				'cap_usd'           => isset( $result['cap_usd'] )          ? (float)  $result['cap_usd']          : 0.0,
				'reset_at'          => isset( $result['reset_at'] )         ? (string) $result['reset_at']         : '',
				'quota_period'      => isset( $result['quota_period'] )     ? (string) $result['quota_period']     : 'day',
				'master_level'      => isset( $result['master_level'] )     ? (string) $result['master_level']     : '',
				'llm_purpose'       => $llm_purpose,
				'answer_intent'     => (string) ( $answer_intent['intent'] ?? 'general' ),
				'skeleton_id'       => (string) $answer_skeleton['id'],
				'required_sections' => (array) $answer_skeleton['required_sections'],
				'skeleton_quality' => (string) $skeleton_validation['quality'],
				'skeleton_violations' => (array) $skeleton_validation['violations'],
				'structure_gate' => (string) $skeleton_validation['structure_gate'],
				'safety_gate' => (string) $skeleton_validation['safety_gate'],
				'entity_gate' => (string) $skeleton_validation['entity_gate'],
				'next_action_gate' => (string) $skeleton_validation['next_action_gate'],
				'followup_gate' => (string) ( $skeleton_validation['followup_gate'] ?? 'not_required' ),
				'followup_contract' => (array) ( $skeleton_validation['followup_contract'] ?? $followup_contract ),
				'named_evidence_count' => count( (array) ( $opts['named_evidence_candidates'] ?? array() ) ),
				'product_entity_count' => count( (array) ( $opts['product_entities'] ?? array() ) ),
				'product_name_entity_count' => count( $this->filter_product_name_entities( (array) ( $opts['product_entities'] ?? array() ) ) ),
				'notebook_depth_profile' => (string) $depth_profile['profile'],
				'notebook_depth_budget'  => $depth_profile,
				'invalid_notebook_citations_stripped' => (int) $source_contract['invalid_count'],
				'invalid_notebook_citation_tokens'    => (array) $source_contract['invalid_tokens'],
			];
		}

		$source_contract = $this->apply_notebook_source_contract( $final_text, $opts );
		$skeleton_validation = $this->validate_answer_skeleton( (string) $source_contract['answer_md'], $answer_skeleton, $opts );
		$final_text = (string) $skeleton_validation['answer_md'];
		if ( is_callable( $on_token ) && ! empty( $skeleton_validation['followup_contract']['rendered'] ) ) {
			// [2026-08-10 Johnny Chu] GOAL-FOLLOWUP-1 — relay deterministic follow-up repair after streamed model text.
			call_user_func( $on_token, "\n\n**Một câu hỏi để mình tiếp tục:** " . (string) $skeleton_validation['followup_contract']['question'], $final_text );
		}

		return [
			'success'         => true,
			'answer_md'       => $final_text,
			'model'           => (string) ( $result['model'] ?? $model ),
			'tokens'          => (int) ( $result['usage']['total_tokens'] ?? 0 ),
			'ms'              => $elapsed,
			'fallback'        => '',
			'error'           => '',
			'quota_exhausted' => false,
			'quota_layer'     => '',
			'tier'            => '',
			'llm_purpose'     => $llm_purpose,
			'answer_intent'   => (string) ( $answer_intent['intent'] ?? 'general' ),
			'skeleton_id'     => (string) $answer_skeleton['id'],
			'required_sections' => (array) $answer_skeleton['required_sections'],
			'skeleton_quality' => (string) $skeleton_validation['quality'],
			'skeleton_violations' => (array) $skeleton_validation['violations'],
			'structure_gate' => (string) $skeleton_validation['structure_gate'],
			'safety_gate' => (string) $skeleton_validation['safety_gate'],
			'entity_gate' => (string) $skeleton_validation['entity_gate'],
			'next_action_gate' => (string) $skeleton_validation['next_action_gate'],
			'followup_gate' => (string) ( $skeleton_validation['followup_gate'] ?? 'not_required' ),
			'followup_contract' => (array) ( $skeleton_validation['followup_contract'] ?? $followup_contract ),
			'named_evidence_count' => count( (array) ( $opts['named_evidence_candidates'] ?? array() ) ),
			'product_entity_count' => count( (array) ( $opts['product_entities'] ?? array() ) ),
			'product_name_entity_count' => count( $this->filter_product_name_entities( (array) ( $opts['product_entities'] ?? array() ) ) ),
			'notebook_depth_profile' => (string) $depth_profile['profile'],
			'notebook_depth_budget'  => $depth_profile,
			'invalid_notebook_citations_stripped' => (int) $source_contract['invalid_count'],
			'invalid_notebook_citation_tokens'    => (array) $source_contract['invalid_tokens'],
		];
	}

	/**
	 * Resolve product-level answer depth for Notebook mode.
	 *
	 * @param string $prompt
	 * @param array  $opts
	 * @param bool   $has_guru
	 * @return array{profile:string,ans_cap:int,max_tokens:int,evidence_contract:string,source_block_required:bool,reason:string}
	 */
	public function resolve_notebook_depth_profile( string $prompt, array $opts = array(), bool $has_guru = false ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — map Notebook answer depth to word/token budgets without exposing raw knobs to FE users.
		$has_notebook_sources = ! empty( $opts['notebook_source_map'] ) || ! empty( $opts['notebook_source_block_md'] );
		$explicit = '';
		foreach ( array( 'notebook_answer_depth', 'notebook_depth_profile', 'answer_depth_profile' ) as $key ) {
			if ( isset( $opts[ $key ] ) && (string) $opts[ $key ] !== '' ) {
				$explicit = sanitize_key( (string) $opts[ $key ] );
				break;
			}
		}

		$profile = $explicit !== '' ? $explicit : self::NOTEBOOK_DEPTH_DEFAULT;
		$reason  = $explicit !== '' ? 'explicit_option' : 'default';
		$prompt_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $prompt ) : strtolower( $prompt );

		if ( $explicit === '' ) {
			$brief_markers = array( 'ngắn gọn', 'tom tat', 'tóm tắt', 'brief', 'short', 'tl;dr' );
			$deep_markers  = array( 'phân tích sâu', 'phan tich sau', 'chi tiết', 'chi tiet', 'kỹ lưỡng', 'ky luong', 'đào sâu', 'dao sau', 'deep dive', 'long-form', 'dài hơn', 'dai hon' );
			$audit_markers = array( 'audit', 'kiểm chứng', 'kiem chung', 'trace nguồn', 'trace nguon', 'đối chiếu nguồn', 'doi chieu nguon', 'training gap', 'gap report' );

			foreach ( $brief_markers as $marker ) {
				if ( strpos( $prompt_lc, $marker ) !== false ) {
					$profile = 'brief';
					$reason  = 'prompt_brief_marker';
					break;
				}
			}
			if ( $profile === self::NOTEBOOK_DEPTH_DEFAULT ) {
				foreach ( $audit_markers as $marker ) {
					if ( strpos( $prompt_lc, $marker ) !== false ) {
						$profile = 'audit';
						$reason  = 'prompt_audit_marker';
						break;
					}
				}
			}
			if ( $profile === self::NOTEBOOK_DEPTH_DEFAULT ) {
				foreach ( $deep_markers as $marker ) {
					if ( strpos( $prompt_lc, $marker ) !== false ) {
						$profile = 'deep';
						$reason  = 'prompt_deep_marker';
						break;
					}
				}
			}
			if ( $profile === self::NOTEBOOK_DEPTH_DEFAULT && $has_guru && $has_notebook_sources ) {
				$profile = 'deep';
				$reason  = 'guru_notebook_default_deep';
			}
		}

		if ( ! in_array( $profile, array( 'brief', 'normal', 'deep', 'audit' ), true ) ) {
			$profile = self::NOTEBOOK_DEPTH_DEFAULT;
			$reason  = 'invalid_profile_defaulted';
		}

		$budgets = array(
			'brief'  => array( 'ans_cap' => 450,  'max_tokens' => 1400, 'evidence_contract' => 'short answer; keep Notebook source block visible' ),
			'normal' => array( 'ans_cap' => $has_guru ? 1600 : 1800, 'max_tokens' => $has_guru ? self::MAX_TOKENS_GURU : self::MAX_TOKENS, 'evidence_contract' => 'sourced synthesis with main claims cited' ),
			'deep'   => array( 'ans_cap' => 1800, 'max_tokens' => 4200, 'evidence_contract' => 'multi-section analysis; cover agreement, tension, and recommendation' ), // [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.19 — no user-facing training-gap section in final answers.
			'audit'  => array( 'ans_cap' => 2200, 'max_tokens' => 5200, 'evidence_contract' => 'audit trail; compare notebooks and cite each major claim' ),
		);

		$budget = $budgets[ $profile ];
		$budget['profile'] = $profile;
		$budget['source_block_required'] = $has_notebook_sources;
		$budget['reason'] = $reason;

		return (array) apply_filters( 'bizcity_twinbrain_notebook_depth_profile', $budget, $prompt, $opts, $has_guru );
	}

	/**
	 * Resolve LLM purpose for Final Composer based on Notebook depth.
	 *
	 * @param array $depth_profile
	 * @param array $opts
	 * @return string
	 */
	public function resolve_llm_purpose( array $depth_profile, array $opts = array() ): string {
		// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G1 — keep every user-facing vertical on the canonical chat model; depth changes budget only.
		if ( isset( $opts['final_compose_purpose'] ) && (string) $opts['final_compose_purpose'] !== '' ) {
			return sanitize_key( (string) $opts['final_compose_purpose'] );
		}
		$profile = isset( $depth_profile['profile'] ) ? sanitize_key( (string) $depth_profile['profile'] ) : self::NOTEBOOK_DEPTH_DEFAULT;
		$purpose = self::PURPOSE;
		return (string) apply_filters( 'bizcity_twinbrain_final_compose_purpose', $purpose, $depth_profile, $opts );
	}

	/**
	 * Resolve the V4 answer shape without an additional LLM call.
	 *
	 * @return array{id:string,domain:string,required_sections:array<int,string>,safety_level:string}
	 */
	private function resolve_answer_skeleton( string $prompt, array $answer_intent, array $opts = array() ): array {
		// [2026-08-05 Johnny Chu] V4.2 — choose output shape from intent/domain; greetings stay compact and health answers get safety structure.
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $prompt ) : strtolower( $prompt );
		$health_markers = array( 'bé', 'con', 'sữa', 'lactose', 'táo bón', 'tiêu chảy', 'sốt', 'đau', 'thuốc', 'bác sĩ', 'chẩn đoán', 'cân nặng', 'chiều cao' );
		$is_health = false;
		foreach ( $health_markers as $marker ) {
			if ( false !== mb_strpos( $text, $marker ) ) {
				$is_health = true;
				break;
			}
		}
		$intent = sanitize_key( (string) ( $answer_intent['intent'] ?? 'general' ) );
		if ( $is_health ) {
			return array(
				'id' => 'health.consulting.v1',
				'domain' => 'health',
				'required_sections' => array( 'conclusion_short', 'known_information', 'analysis', 'next_steps', 'safety_notes', 'next_question', 'sources' ),
				'safety_level' => 'high',
			);
		}
		if ( in_array( $intent, array( 'list_products', 'list_named_entities' ), true ) ) {
			return array(
				'id' => 'product.list.v1',
				'domain' => 'product',
				'required_sections' => array( 'conclusion_short', 'verified_entities', 'analysis', 'next_steps', 'sources' ),
				'safety_level' => 'normal',
			);
		}
		if ( 'comparison' === $intent ) {
			return array(
				'id' => 'comparison.v1',
				'domain' => 'general',
				'required_sections' => array( 'conclusion_short', 'comparison', 'tradeoffs', 'next_steps', 'sources' ),
				'safety_level' => 'normal',
			);
		}
		if ( 'fact_lookup' === $intent ) {
			return array(
				'id' => 'fact_lookup.v1',
				'domain' => 'general',
				'required_sections' => array( 'conclusion_short', 'known_information', 'sources', 'limitations' ),
				'safety_level' => 'normal',
			);
		}
		if ( in_array( $intent, array( 'troubleshooting', 'task_execution' ), true ) ) {
			return array(
				'id' => 'troubleshooting.v1',
				'domain' => 'general',
				'required_sections' => array( 'conclusion_short', 'known_information', 'analysis', 'next_steps', 'limitations' ),
				'safety_level' => 'normal',
			);
		}
		if ( in_array( $intent, array( 'casual', 'general' ), true ) && mb_strlen( trim( $prompt ) ) < 40 ) {
			return array(
				'id' => 'casual.compact.v1',
				'domain' => 'general',
				'required_sections' => array( 'direct_answer' ),
				'safety_level' => 'normal',
			);
		}
		return array(
			'id' => 'consulting.v1',
			'domain' => 'general',
			'required_sections' => array( 'conclusion_short', 'known_information', 'analysis', 'next_steps', 'next_question', 'sources' ),
			'safety_level' => 'normal',
		);
	}

	/**
	 * Validate and safely normalize the V4 answer shape after composition.
	 *
	 * @return array{answer_md:string,quality:string,violations:array<int,string>,structure_gate:string,safety_gate:string,entity_gate:string,next_action_gate:string}
	 */
	private function validate_answer_skeleton( string $answer_md, array $skeleton, array $opts = array() ): array {
		// [2026-08-05 Johnny Chu] V4.4 — validate structure/evidence-sensitive labels without inventing missing factual claims.
		$text = trim( $answer_md );
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		$required = (array) ( $skeleton['required_sections'] ?? array() );
		$headings = array(
			'conclusion_short' => array( 'kết luận ngắn', 'kết luận nhanh' ),
			'known_information' => array( 'mình đang dựa trên thông tin nào', 'dữ kiện đã biết', 'thông tin đã biết' ),
			'analysis' => array( 'phân tích chính', 'phân tích chi tiết', 'phân tích không chẩn đoán' ),
			'next_steps' => array( 'nên làm gì tiếp theo', 'việc nên làm', 'giải pháp toàn diện' ),
			'safety_notes' => array( 'lưu ý an toàn', 'khi cần chuyên gia', 'khi cần khám', 'red flags' ),
			'next_question' => array( 'một thông tin mình cần thêm', 'thông tin mình cần thêm', 'cần biết thêm' ),
			'sources' => array( 'nguồn tham chiếu', 'nguồn từ notebook', 'nguồn tham khảo' ),
			'verified_entities' => array( 'các sản phẩm/dòng sản phẩm được xác minh', 'kết quả nguồn' ),
			'comparison' => array( 'so sánh', 'điểm giống/khác' ),
			'tradeoffs' => array( 'trade-off', 'đánh đổi' ),
			'limitations' => array( 'giới hạn', 'hạn chế' ),
		);
		$missing = array();
		foreach ( $required as $section ) {
			$section = sanitize_key( (string) $section );
			if ( $section === 'direct_answer' ) {
				continue;
			}
			$found = false;
			foreach ( (array) ( $headings[ $section ] ?? array() ) as $heading ) {
				if ( false !== strpos( $lower, mb_strtolower( $heading ) ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $section;
			}
		}

		$violations = array();
		$answer_safety = (string) ( $skeleton['safety_level'] ?? 'normal' );
		$safety_gate = 'pass';
		if ( $answer_safety === 'high' && in_array( 'safety_notes', $missing, true ) ) {
			// Safe format repair only: this does not diagnose or add a treatment claim.
			$text .= "\n\n## Lưu ý an toàn / khi cần chuyên gia\nĐây là thông tin tham khảo, không thay thế đánh giá của bác sĩ hoặc chuyên gia dinh dưỡng. Nếu triệu chứng kéo dài, nặng lên hoặc có dấu hiệu bất thường, hãy đưa bé đi khám.\n";
			$safety_gate = 'repaired';
			$missing = array_values( array_diff( $missing, array( 'safety_notes' ) ) );
		} elseif ( $answer_safety === 'high' ) {
			$safety_gate = 'pass';
		}
		$has_products = ! empty( $this->filter_product_name_entities( (array) ( $opts['product_entities'] ?? array() ) ) );
		$entity_gate = 'pass';
		if ( (string) ( $skeleton['domain'] ?? '' ) === 'product' && ! $has_products ) {
			$entity_gate = 'degraded';
			$violations[] = 'verified_product_entity_missing';
			$text = str_replace( array( '## Các dòng sữa tìm thấy từ nội dung', '## Các sản phẩm/dòng sản phẩm được xác minh' ), '## Kết quả nguồn', $text );
			if ( false === strpos( $text, 'chưa trích được tên sản phẩm cụ thể' ) ) {
				$text .= "\n\nChưa trích được tên sản phẩm cụ thể từ nội dung nguồn; tên file chỉ được xem là source title.\n";
			}
		}
		$next_action_gate = in_array( 'next_question', $required, true )
			? ( in_array( 'next_question', $missing, true ) ? 'degraded' : 'pass' )
			: 'not_required';
		if ( $next_action_gate === 'degraded' ) {
			$violations[] = 'next_best_action_section_missing';
		}
		if ( ! empty( $missing ) ) {
			$violations[] = 'sections_missing:' . implode( ',', $missing );
		}
		$structure_gate = empty( $missing ) ? 'pass' : 'degraded';
		if ( $structure_gate === 'degraded' ) {
			$violations[] = 'required_sections_missing';
		}
		$followup = $this->resolve_followup_contract( $opts );
		if ( ! empty( $followup['required'] ) && ! empty( $followup['question'] ) ) {
			$question = (string) $followup['question'];
			if ( false === stripos( $text, $question ) ) {
				$text .= "\n\n**Một câu hỏi để mình tiếp tục:** " . $question;
				$followup['rendered'] = true;
				$followup['gate'] = 'repaired';
			}
		}
		$quality = empty( $violations ) ? 'ok' : 'bounded_fallback';
		return array(
			'answer_md' => trim( $text ),
			'quality' => $quality,
			'violations' => array_values( array_unique( $violations ) ),
			'structure_gate' => $structure_gate,
			'safety_gate' => $safety_gate,
			'entity_gate' => $entity_gate,
			'next_action_gate' => $next_action_gate,
			'followup_gate' => (string) ( $followup['gate'] ?? 'not_required' ),
			'followup_contract' => $followup,
		);
	}

	/**
	 * Resolve one contextual follow-up from the canonical Goal Loop state.
	 *
	 * @return array{required:bool,source:string,goal_id:string,question:string,question_kind:string,target_ref:string,rendered:bool,gate:string}
	 */
	private function resolve_followup_contract( array $opts ): array {
		$goal = is_array( $opts['goal_loop_state'] ?? null )
			? $opts['goal_loop_state']
			: ( is_array( $opts['goal_loop'] ?? null ) ? $opts['goal_loop'] : array() );
		$goal_id = sanitize_text_field( (string) ( $goal['goal_id'] ?? '' ) );
		if ( $goal_id === '' ) {
			return array(
				'required' => true,
				'source' => 'triage',
				'goal_id' => '',
				'question' => 'Bạn muốn mình tiếp tục hỗ trợ mục tiêu cụ thể nào?',
				'question_kind' => 'next_goal',
				'target_ref' => '',
				'rendered' => false,
				'gate' => 'pending',
			);
		}
		$action = is_array( $goal['next_best_action'] ?? null ) ? $goal['next_best_action'] : array();
		$question = trim( (string) ( $action['question_text'] ?? '' ) );
		if ( $question === '' ) {
			$question = trim( (string) ( $action['label'] ?? '' ) );
		}
		if ( $question === '' ) {
			$primary_goal = trim( (string) ( $goal['primary_goal'] ?? '' ) );
			$question = $primary_goal !== ''
				? 'Bạn muốn mình cùng bạn chốt bước tiếp theo nào cho mục tiêu này?'
				: 'Bạn muốn mình tiếp tục hỗ trợ mục tiêu nào?';
		}
		return array(
			'required' => true,
			'source' => 'goal_loop',
			'goal_id' => $goal_id,
			'question' => sanitize_text_field( $question ),
			'question_kind' => sanitize_key( (string) ( $action['kind'] ?? $action['type'] ?? 'next_goal' ) ),
			'target_ref' => sanitize_text_field( (string) ( $action['ref'] ?? $action['target_field'] ?? '' ) ),
			'rendered' => false,
			'gate' => 'pending',
		);
	}

	private function append_followup_contract( string $answer, array $opts ): array {
		$contract = $this->resolve_followup_contract( $opts );
		if ( empty( $contract['required'] ) || $contract['question'] === '' ) {
			return array( 'answer_md' => $answer, 'contract' => $contract );
		}
		if ( false === stripos( $answer, (string) $contract['question'] ) ) {
			$answer = trim( $answer ) . "\n\n**Một câu hỏi để mình tiếp tục:** " . $contract['question'];
			$contract['rendered'] = true;
		}
		$contract['gate'] = 'pass';
		return array( 'answer_md' => $answer, 'contract' => $contract );
	}

	/**
	 * Resolve user answer intent before Final Composer writes the user-facing text.
	 *
	 * @param string $prompt
	 * @param array  $opts
	 * @return array{intent:string,requires_named_evidence:bool,reason:string}
	 */
	private function resolve_answer_intent( string $prompt, array $opts = array() ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — detect list/entity/product questions so answers name concrete source-title lines first.
		$explicit = isset( $opts['answer_intent'] ) ? sanitize_key( (string) $opts['answer_intent'] ) : '';
		if ( $explicit !== '' ) {
			return array(
				'intent'                  => $explicit,
				'requires_named_evidence' => in_array( $explicit, array( 'list_products', 'list_named_entities' ), true ),
				'reason'                  => 'explicit_option',
			);
		}

		$prompt_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $prompt ) : strtolower( $prompt );
		$list_markers = array( 'kể tên', 'ke ten', 'liệt kê', 'liet ke', 'dòng nào', 'dong nao', 'sản phẩm nào', 'san pham nao', 'hãng nào', 'hang nao', 'loại nào', 'loai nao', 'các dòng', 'cac dong' );
		$product_markers = array( 'sữa', 'sua', 'dòng sữa', 'dong sua', 'sữa công thức', 'sua cong thuc', 'sản phẩm', 'san pham', 'thương hiệu', 'thuong hieu', 'brand', 'product' );

		$has_list_marker = false;
		foreach ( $list_markers as $marker ) {
			if ( strpos( $prompt_lc, $marker ) !== false ) {
				$has_list_marker = true;
				break;
			}
		}

		$has_product_marker = false;
		foreach ( $product_markers as $marker ) {
			if ( strpos( $prompt_lc, $marker ) !== false ) {
				$has_product_marker = true;
				break;
			}
		}

		if ( $has_list_marker && $has_product_marker ) {
			return array( 'intent' => 'list_products', 'requires_named_evidence' => true, 'reason' => 'prompt_list_product_marker' );
		}
		if ( $has_list_marker ) {
			return array( 'intent' => 'list_named_entities', 'requires_named_evidence' => true, 'reason' => 'prompt_list_entity_marker' );
		}

		return array( 'intent' => 'general', 'requires_named_evidence' => false, 'reason' => 'default' );
	}

	/**
	 * Extract concrete source-title/name candidates from TwinSearch and source-file briefs.
	 *
	 * @param array $opts
	 * @return array<int,array{title:string,notebook:string,source:string,citation:string,reason:string}>
	 */
	private function extract_named_evidence_candidates( array $opts ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — make product/source-title evidence explicit for list answers.
		$rows = array();
		$seen = array();
		$push = function ( $title, $notebook, $source, $citation, $reason ) use ( &$rows, &$seen ) {
			$title = trim( wp_strip_all_tags( (string) $title ) );
			if ( $title === '' || preg_match( '/^source\s*#\d+$/i', $title ) ) {
				return;
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$rows[] = array(
				'title'    => mb_substr( $title, 0, 160 ),
				'notebook' => mb_substr( trim( wp_strip_all_tags( (string) $notebook ) ), 0, 120 ),
				'source'   => mb_substr( trim( wp_strip_all_tags( (string) $source ) ), 0, 120 ),
				'citation' => $this->normalize_notebook_citation( (string) $citation ),
				'reason'   => mb_substr( trim( wp_strip_all_tags( (string) $reason ) ), 0, 220 ),
			);
		};

		foreach ( array_slice( (array) ( $opts['search_context_results'] ?? array() ), 0, 12 ) as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$push(
				(string) ( $hit['source_title'] ?? '' ),
				(string) ( $hit['notebook_title'] ?? '' ),
				(string) ( $hit['origin_kind'] ?? 'TwinSearch' ),
				(string) ( $hit['citation'] ?? '' ),
				$this->first_non_empty_text( array( (string) ( $hit['context_excerpt'] ?? '' ), (string) ( $hit['snippet'] ?? '' ), 'TwinSearch top hit for the user query' ) )
			);
		}

		foreach ( array_slice( (array) ( $opts['source_file_briefs'] ?? array() ), 0, 12 ) as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			$citations = array_values( (array) ( $brief['key_citations'] ?? array() ) );
			$claims    = array_values( (array) ( $brief['source_claims'] ?? array() ) );
			$push(
				(string) ( $brief['source_title'] ?? '' ),
				'Notebook #' . (int) ( $brief['notebook_id'] ?? 0 ),
				'Source #' . (int) ( $brief['source_id'] ?? 0 ),
				(string) ( $citations[0] ?? '' ),
				$this->first_non_empty_text( array( (string) ( $claims[0] ?? '' ), (string) ( $brief['rank_reason'] ?? '' ), 'Source-file brief matched the user query' ) )
			);
		}

		return array_slice( $rows, 0, 10 );
	}

	/**
	 * Extract product/entity names from evidence text, not from document titles.
	 *
	 * @param array $opts
	 * @return array<int,array{name:string,normalized_name:string,source_title:string,notebook_title:string,source_id:int,passage_id:int,citation:string,evidence_excerpt:string,confidence:string,match_reason:string}>
	 */
	public function extract_product_entities( array $opts ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — product entity extraction layer for list-products answers.
		$entities = array();
		$seen     = array();
		$push = function ( $name, array $ctx ) use ( &$entities, &$seen ) {
			$name = $this->clean_product_entity_name( (string) $name );
			if ( ! $this->is_valid_product_entity_name( $name ) ) {
				return;
			}
			$excerpt = trim( wp_strip_all_tags( (string) ( $ctx['evidence_excerpt'] ?? '' ) ) );
			$class = $this->classify_product_entity( $name, $excerpt );
			if ( (string) ( $class['entity_type'] ?? '' ) === 'claim_phrase' ) {
				return;
			}
			$normalized = $this->normalize_product_entity_name( $name );
			if ( $normalized === '' || isset( $seen[ $normalized ] ) ) {
				return;
			}
			$seen[ $normalized ] = true;
			$citation = $this->normalize_notebook_citation( (string) ( $ctx['citation'] ?? '' ) );
			$confidence = (string) ( $class['confidence'] ?? 'medium' );
			if ( $citation === '' && $confidence === 'high' ) {
				$confidence = 'medium';
			}

			$entities[] = array(
				'name'             => mb_substr( $name, 0, 140 ),
				'normalized_name'  => $normalized,
				'entity_type'      => (string) ( $class['entity_type'] ?? 'category_trait' ),
				'is_product_name'  => ! empty( $class['is_product_name'] ),
				'source_title'     => mb_substr( trim( wp_strip_all_tags( (string) ( $ctx['source_title'] ?? '' ) ) ), 0, 160 ),
				'notebook_title'   => mb_substr( trim( wp_strip_all_tags( (string) ( $ctx['notebook_title'] ?? '' ) ) ), 0, 140 ),
				'source_id'        => (int) ( $ctx['source_id'] ?? 0 ),
				'passage_id'       => (int) ( $ctx['passage_id'] ?? 0 ),
				'citation'         => $citation,
				'evidence_excerpt' => mb_substr( $excerpt, 0, 360 ),
				'confidence'       => $confidence,
				'match_reason'     => mb_substr( trim( (string) ( $ctx['match_reason'] ?? 'milk_phrase_in_evidence' ) ), 0, 120 ),
			);
		};

		foreach ( array_slice( (array) ( $opts['search_context_results'] ?? array() ), 0, 12 ) as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$excerpt = $this->first_non_empty_text( array( (string) ( $hit['context_excerpt'] ?? '' ), (string) ( $hit['snippet'] ?? '' ) ) );
			foreach ( $this->extract_product_names_from_text( $excerpt ) as $name ) {
				$push( $name, array(
					'source_title'     => (string) ( $hit['source_title'] ?? '' ),
					'notebook_title'   => (string) ( $hit['notebook_title'] ?? '' ),
					'source_id'        => (int) ( $hit['source_id'] ?? 0 ),
					'passage_id'       => (int) ( $hit['first_passage_id'] ?? 0 ),
					'citation'         => (string) ( $hit['citation'] ?? '' ),
					'evidence_excerpt' => $excerpt,
					'match_reason'     => 'search_context_result_excerpt',
				) );
			}
		}

		foreach ( array_slice( (array) ( $opts['source_file_briefs'] ?? array() ), 0, 12 ) as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			$source_title = (string) ( $brief['source_title'] ?? '' );
			$source_id    = (int) ( $brief['source_id'] ?? 0 );
			$notebook_id  = (int) ( $brief['notebook_id'] ?? 0 );
			$citations    = array_values( (array) ( $brief['key_citations'] ?? array() ) );
			foreach ( array_slice( (array) ( $brief['source_claims'] ?? array() ), 0, 8 ) as $claim ) {
				$excerpt = trim( wp_strip_all_tags( (string) $claim ) );
				foreach ( $this->extract_product_names_from_text( $excerpt ) as $name ) {
					$push( $name, array(
						'source_title'     => $source_title,
						'notebook_title'   => 'Notebook #' . $notebook_id,
						'source_id'        => $source_id,
						'passage_id'       => 0,
						'citation'         => (string) ( $citations[0] ?? '' ),
						'evidence_excerpt' => $excerpt,
						'match_reason'     => 'source_claim',
					) );
				}
			}
			foreach ( array_slice( (array) ( $brief['search_context_hits'] ?? array() ), 0, 10 ) as $hit ) {
				if ( ! is_array( $hit ) ) {
					continue;
				}
				$excerpt = trim( wp_strip_all_tags( (string) ( $hit['excerpt'] ?? '' ) ) );
				foreach ( $this->extract_product_names_from_text( $excerpt ) as $name ) {
					$citation = (string) ( $hit['token'] ?? ( $citations[0] ?? '' ) );
					$ref = $this->parse_notebook_citation_ref( $citation );
					$push( $name, array(
						'source_title'     => $source_title,
						'notebook_title'   => 'Notebook #' . $notebook_id,
						'source_id'        => $source_id,
						'passage_id'       => (int) ( $ref['passage_id'] ?? 0 ),
						'citation'         => $citation,
						'evidence_excerpt' => $excerpt,
						'match_reason'     => 'source_file_search_context_hit',
					) );
				}
			}
		}

		usort( $entities, function ( $a, $b ) {
			$rank = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
			$ra = ( ! empty( $a['is_product_name'] ) ? 10 : 0 ) + ( isset( $rank[ (string) ( $a['confidence'] ?? '' ) ] ) ? $rank[ (string) $a['confidence'] ] : 0 );
			$rb = ( ! empty( $b['is_product_name'] ) ? 10 : 0 ) + ( isset( $rank[ (string) ( $b['confidence'] ?? '' ) ] ) ? $rank[ (string) $b['confidence'] ] : 0 );
			if ( $ra === $rb ) {
				return mb_strlen( (string) ( $a['name'] ?? '' ) ) <=> mb_strlen( (string) ( $b['name'] ?? '' ) );
			}
			return $rb <=> $ra;
		} );

		return array_slice( $entities, 0, 12 );
	}

	/** @return array<int,string> */
	private function extract_product_names_from_text( string $text ): array {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( $text === '' ) {
			return array();
		}
		$names = array();
		$brand_pattern = '/\b(HiPP|Kendamil|Bellamy\'?s|LittleOak|Meiji|Aptamil|Friso|NAN|Enfamil|Morinaga|Glico|Similac|Blackmores|S-26|Nutifood|Vinamilk|Dielac|ColosBaby|Optimum\s+Gold)(?:\s+(?:Goat|A2|Organic|Premium|Gold|Comfort|Gentle|Pro|Plus|Infant|Follow[- ]?On|Toddler|OPO)){0,4}\b/iu';
		if ( preg_match_all( $brand_pattern, $text, $brand_matches ) ) {
			foreach ( (array) ( $brand_matches[0] ?? array() ) as $raw ) {
				$name = $this->clean_product_entity_name( (string) $raw );
				if ( $this->is_valid_product_entity_name( $name ) ) {
					$names[] = $name;
				}
			}
		}
		if ( preg_match_all( '/(?:các\s+dòng\s+|dòng\s+)?(sữa\s+[^\.,;:\n\(\)\[\]]{2,110})/iu', $text, $matches ) ) {
			foreach ( (array) ( $matches[1] ?? array() ) as $raw ) {
				$name = $this->clean_product_entity_name( (string) $raw );
				if ( $this->is_valid_product_entity_name( $name ) ) {
					$names[] = $name;
				}
			}
		}
		return array_values( array_unique( $names ) );
	}

	private function clean_product_entity_name( string $name ): string {
		$name = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );
		$name = preg_replace( '/^(các\s+dòng\s+|dòng\s+)/iu', '', $name );
		$name = preg_split( '/\s+(?:giúp|khi|được\s+nhắc|được\s+tìm|phù\s+hợp|cho\s+thấy|là\s+một|có\s+khả\s+năng)\b/iu', $name )[0] ?? $name;
		$name = trim( $name, " \t\n\r\0\x0B-–—:;,." );
		$words = preg_split( '/\s+/u', $name );
		if ( is_array( $words ) && count( $words ) > 14 ) {
			$name = implode( ' ', array_slice( $words, 0, 14 ) );
		}
		return trim( $name );
	}

	private function is_valid_product_entity_name( string $name ): bool {
		$name = trim( $name );
		if ( $name === '' || mb_strlen( $name ) < 6 ) {
			return false;
		}
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$reject_fragments = array( 'top 10', 'chủ đề', 'chu de', 'bảng cập nhật', 'bang cap nhat', 'hôm qua', 'hom qua', 'nguyễn', 'nguyen', 'thu trang', 'táo bón chức năng', 'tao bon chuc nang' );
		foreach ( $reject_fragments as $fragment ) {
			if ( strpos( $lc, $fragment ) !== false ) {
				return false;
			}
		}
		$too_generic = array( 'sữa', 'sữa công thức', 'sữa cho bé', 'sữa bột', 'sữa mẹ' );
		if ( in_array( $lc, $too_generic, true ) ) {
			return false;
		}
		if ( preg_match( '/\b(hipp|kendamil|bellamy|littleoak|meiji|aptamil|friso|nan|enfamil|morinaga|glico|similac|blackmores|s-26|nutifood|vinamilk|dielac|colosbaby|optimum\s+gold)\b/i', $lc ) ) {
			return true;
		}
		return strpos( $lc, 'sữa' ) !== false || strpos( $lc, 'sua' ) !== false;
	}

	/** @return array{entity_type:string,is_product_name:bool,confidence:string} */
	private function classify_product_entity( string $name, string $excerpt ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.16 — avoid treating source claims/categories as product names.
		$lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		if ( preg_match( '/\b(hipp|kendamil|bellamy|littleoak|meiji|aptamil|friso|nan|enfamil|morinaga|glico|similac|blackmores|s-26|nutifood|vinamilk|dielac|colosbaby|optimum\s+gold)\b/i', $name ) ) {
			return array( 'entity_type' => 'product_name', 'is_product_name' => true, 'confidence' => 'high' );
		}
		if ( preg_match( '/\b(A2|OPO|GOS\/FOS|Lactoferrin|Organic|Premium|Clean Label)\b/iu', $name . ' ' . $excerpt ) && preg_match( '/sữa\s+(dê|hữu\s+cơ|organic|a2|có|bổ\s+sung|chứa|ứng\s+dụng)/iu', $name ) ) {
			return array( 'entity_type' => 'category_trait', 'is_product_name' => false, 'confidence' => 'medium' );
		}
		foreach ( array( 'vẫn táo bón', 'van tao bon', 'bột thông thường', 'bot thong thuong', 'dùng đâu', 'dung dau', 'thanh mát', 'thanh mat', 'trị táo bón', 'tri tao bon', 'cho bé', 'cho be', 'công thức', 'cong thuc' ) as $fragment ) {
			if ( strpos( $lc, $fragment ) !== false ) {
				return array( 'entity_type' => 'claim_phrase', 'is_product_name' => false, 'confidence' => 'low' );
			}
		}
		return array( 'entity_type' => 'claim_phrase', 'is_product_name' => false, 'confidence' => 'low' );
	}

	private function normalize_product_entity_name( string $name ): string {
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		$name = preg_replace( '/\s+/u', ' ', trim( $name ) );
		return is_string( $name ) ? $name : '';
	}

	/** @return array{notebook_id:int,passage_id:int} */
	private function parse_notebook_citation_ref( string $citation ): array {
		if ( preg_match( '/\[?nb:(\d+)\/p(\d+)\]?/i', trim( $citation ), $m ) ) {
			return array( 'notebook_id' => (int) $m[1], 'passage_id' => (int) $m[2] );
		}
		return array( 'notebook_id' => 0, 'passage_id' => 0 );
	}

	private function normalize_notebook_citation( string $citation ): string {
		$citation = trim( $citation );
		if ( preg_match( '/\[?nb:(\d+)\/p(\d+)\]?/i', $citation, $m ) ) {
			return '[nb:' . (int) $m[1] . '/p' . (int) $m[2] . ']';
		}
		return '';
	}

	private function first_non_empty_text( array $values ): string {
		foreach ( $values as $value ) {
			$value = trim( wp_strip_all_tags( (string) $value ) );
			if ( $value !== '' ) {
				return $value;
			}
		}
		return '';
	}

	private function render_named_evidence_candidates_compact( array $candidates ): string {
		if ( empty( $candidates ) ) {
			return '';
		}
		$out = array();
		foreach ( array_slice( $candidates, 0, 10 ) as $idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = sprintf(
				'%d. name/source_title: %s · notebook: %s · source: %s · citation: %s · why: %s',
				$idx + 1,
				(string) ( $row['title'] ?? '' ),
				(string) ( $row['notebook'] ?? '' ),
				(string) ( $row['source'] ?? '' ),
				(string) ( $row['citation'] ?? '' ),
				mb_substr( (string) ( $row['reason'] ?? '' ), 0, 180 )
			);
		}
		return implode( "\n", $out );
	}

	private function render_product_entities_compact( array $entities ): string {
		$entities = $this->filter_product_name_entities( $entities );
		if ( empty( $entities ) ) {
			return 'PRODUCT ENTITIES: [] (no concrete product_name rows; category/claim rows are not valid product names)';
		}
		$out = array( 'PRODUCT ENTITIES:' );
		foreach ( array_slice( $entities, 0, 12 ) as $idx => $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$name = trim( wp_strip_all_tags( (string) ( $entity['name'] ?? '' ) ) );
			if ( $name === '' ) {
				continue;
			}
			$out[] = sprintf(
				'%d. entity: %s · source_title: %s · excerpt: %s · confidence: %s · citation: %s',
				$idx + 1,
				$name,
				trim( wp_strip_all_tags( (string) ( $entity['source_title'] ?? '' ) ) ),
				mb_substr( trim( wp_strip_all_tags( (string) ( $entity['evidence_excerpt'] ?? '' ) ) ), 0, 220 ),
				trim( (string) ( $entity['confidence'] ?? 'medium' ) ),
				$this->normalize_notebook_citation( (string) ( $entity['citation'] ?? '' ) )
			);
		}
		return implode( "\n", $out );
	}

	private function render_product_entities_answer_prefix( array $entities ): string {
		$entities = $this->filter_product_name_entities( $entities );
		if ( empty( $entities ) ) {
			return '';
		}
		$lines = array(
			'## Các dòng sữa tìm thấy từ nội dung',
			'',
			'| Dòng sữa/entity | Source title | Evidence excerpt | Confidence | Citation |',
			'|---|---|---|---|---|',
		);
		foreach ( array_slice( $entities, 0, 8 ) as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$name = trim( wp_strip_all_tags( (string) ( $entity['name'] ?? '' ) ) );
			if ( $name === '' ) {
				continue;
			}
			$lines[] = '| ' . $this->escape_markdown_table_cell( $name )
				. ' | ' . $this->escape_markdown_table_cell( trim( wp_strip_all_tags( (string) ( $entity['source_title'] ?? '' ) ) ) )
				. ' | ' . $this->escape_markdown_table_cell( mb_substr( trim( wp_strip_all_tags( (string) ( $entity['evidence_excerpt'] ?? '' ) ) ), 0, 180 ) )
				. ' | ' . $this->escape_markdown_table_cell( trim( (string) ( $entity['confidence'] ?? 'medium' ) ) )
				. ' | ' . $this->escape_markdown_table_cell( $this->normalize_notebook_citation( (string) ( $entity['citation'] ?? '' ) ) )
				. ' |';
		}
		$lines[] = '';
		return trim( implode( "\n", $lines ) );
	}

	private function should_prepend_product_entities( string $answer_md, array $entities ): bool {
		$entities = $this->filter_product_name_entities( $entities );
		if ( empty( $entities ) ) {
			return false;
		}
		$head = wp_strip_all_tags( mb_substr( $answer_md, 0, 1400 ) );
		$head_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $head ) : strtolower( $head );
		if ( strpos( $head_lc, 'các dòng sữa tìm thấy từ nội dung' ) !== false ) {
			return false;
		}
		$checked = 0;
		foreach ( array_slice( $entities, 0, 5 ) as $entity ) {
			$name = trim( (string) ( is_array( $entity ) ? ( $entity['name'] ?? '' ) : '' ) );
			if ( $name === '' ) {
				continue;
			}
			$checked++;
			$name_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
			if ( strpos( $head_lc, $name_lc ) === false ) {
				return true;
			}
		}
		return $checked === 0;
	}

	private function render_no_product_entities_notice(): string {
		// [2026-08-05 Johnny Chu] V4-SKELETON — keep the missing-entity notice domain-neutral; source titles are references, never product names.
		return "## Sản phẩm/dòng hàng tìm thấy từ nội dung\n\nCó file liên quan, nhưng chưa trích được tên sản phẩm hoặc dòng hàng cụ thể từ nội dung.";
	}

	/** @return array<int,array<string,mixed>> */
	private function filter_product_name_entities( array $entities ): array {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.16 — only true product-name entities may populate the product-list table.
		$out = array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			if ( ! empty( $entity['is_product_name'] ) || (string) ( $entity['entity_type'] ?? '' ) === 'product_name' ) {
				$out[] = $entity;
			}
		}
		return $out;
	}

	private function escape_markdown_table_cell( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $value ) ) );
		$value = str_replace( array( "\n", "\r", '|' ), array( ' ', ' ', '\\|' ), $value );
		return $value !== '' ? $value : '—';
	}

	private function render_named_evidence_answer_prefix( array $candidates ): string {
		// [2026-08-05 Johnny Chu] V3.5 — source-title fallback is evidence only; never label a document title as a milk/product name.
		if ( empty( $candidates ) ) {
			return '';
		}
		$lines = array(
			'## Tài liệu/source title tìm thấy trước',
			'',
			'| Tài liệu / source title | Notebook/source file | Vì sao liên quan | Citation |',
			'|---|---|---|---|',
		);
		foreach ( array_slice( $candidates, 0, 8 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$title = str_replace( array( "\n", '|' ), array( ' ', '\|' ), (string) ( $row['title'] ?? '' ) );
			$source = trim( (string) ( $row['notebook'] ?? '' ) . ( ! empty( $row['source'] ) ? ' · ' . (string) $row['source'] : '' ) );
			$source = str_replace( array( "\n", '|' ), array( ' ', '\|' ), $source );
			$reason = str_replace( array( "\n", '|' ), array( ' ', '\|' ), mb_substr( (string) ( $row['reason'] ?? '' ), 0, 140 ) );
			$citation = (string) ( $row['citation'] ?? '' );
			$lines[] = '| ' . $title . ' | ' . $source . ' | ' . ( $reason !== '' ? $reason : 'Khớp truy vấn trong TwinSearch/source-file evidence.' ) . ' | ' . ( $citation !== '' ? $citation : '—' ) . ' |';
		}
		$lines[] = '';
		$lines[] = 'Dưới đây là phần giải thích/evidence từ notebook cho từng nhóm liên quan.';
		return trim( implode( "\n", $lines ) );
	}

	private function should_prepend_named_evidence( string $answer_md, array $candidates ): bool {
		if ( empty( $candidates ) ) {
			return false;
		}
		$head = wp_strip_all_tags( mb_substr( $answer_md, 0, 900 ) );
		$head_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $head ) : strtolower( $head );
		$hit_count = 0;
		foreach ( array_slice( $candidates, 0, 6 ) as $row ) {
			$title = isset( $row['title'] ) ? trim( (string) $row['title'] ) : '';
			if ( $title === '' ) {
				continue;
			}
			$title_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
			if ( strpos( $head_lc, $title_lc ) !== false ) {
				$hit_count++;
			}
		}
		return $hit_count < 2;
	}

	/**
	 * Apply deterministic Notebook Source Layer contract to final text.
	 *
	 * @param string $answer_md
	 * @param array  $opts
	 * @return array{answer_md:string,invalid_count:int,invalid_tokens:array<int,string>}
	 */
	private function apply_notebook_source_contract( string $answer_md, array $opts ): array {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — append source block and strip hallucinated notebook citations.
		$source_map   = isset( $opts['notebook_source_map'] ) && is_array( $opts['notebook_source_map'] ) ? $opts['notebook_source_map'] : array();
		$source_block = trim( (string) ( $opts['notebook_source_block_md'] ?? '' ) );
		if ( empty( $source_map ) || $source_block === '' || ! class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' ) ) {
			return array( 'answer_md' => $answer_md, 'invalid_count' => 0, 'invalid_tokens' => array() );
		}

		$text = trim( $answer_md );
		$answer_intent = isset( $opts['answer_intent_meta'] ) && is_array( $opts['answer_intent_meta'] )
			? $opts['answer_intent_meta']
			: array( 'intent' => (string) ( $opts['answer_intent'] ?? 'general' ), 'requires_named_evidence' => false, 'reason' => 'runtime' );
		$named_candidates = isset( $opts['named_evidence_candidates'] ) && is_array( $opts['named_evidence_candidates'] )
			? $opts['named_evidence_candidates']
			: $this->extract_named_evidence_candidates( $opts );
		$product_entities = isset( $opts['product_entities'] ) && is_array( $opts['product_entities'] )
			? $opts['product_entities']
			: $this->extract_product_entities( $opts );
		$product_name_entities = $this->filter_product_name_entities( $product_entities );
		if ( ! empty( $answer_intent['requires_named_evidence'] ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — product-list answers must use product_entities or an explicit no-entity notice, never source titles as product names.
			if ( ! empty( $product_name_entities ) && $this->should_prepend_product_entities( $text, $product_name_entities ) ) {
				$prefix = $this->render_product_entities_answer_prefix( $product_name_entities );
				if ( $prefix !== '' ) {
					$text = $prefix . "\n\n" . $text;
				}
			} elseif ( empty( $product_name_entities ) && strpos( $text, 'chưa trích được tên dòng sữa cụ thể' ) === false ) {
				$text = $this->render_no_product_entities_notice() . "\n\n" . $text;
			}
		} elseif ( ! empty( $named_candidates ) && $this->should_prepend_named_evidence( $text, $named_candidates ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — keep legacy source-title guard only for non-product named-entity answers.
			$prefix = $this->render_named_evidence_answer_prefix( $named_candidates );
			if ( $prefix !== '' ) {
				$text = $prefix . "\n\n" . $text;
			}
		}
		if ( $text !== '' && strpos( $text, '### Nguồn từ Notebook' ) === false ) {
			$text .= "\n\n" . $source_block;
		}

		$clean = BizCity_TwinBrain_Notebook_Source_Layer::instance()->strip_invalid_citations( $text, $source_map );
		if ( ! empty( $clean['invalid_tokens'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TwinBrain][notebook_source_layer] stripped invalid citations: ' . implode( ',', (array) $clean['invalid_tokens'] ) );
		}
		return $clean;
	}

	/* =================================================================
	 *  Prompt
	 * ================================================================ */

	private function build_messages( string $prompt, array $synth, array $answers, array $opts ): array {
		$has_guru = ! empty( $opts['guru_id'] );
		$depth_profile = isset( $opts['notebook_depth_profile_meta'] ) && is_array( $opts['notebook_depth_profile_meta'] )
			? $opts['notebook_depth_profile_meta']
			: $this->resolve_notebook_depth_profile( $prompt, $opts, $has_guru );
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — answer word caps now follow Notebook depth profiles.
		$ans_cap  = isset( $depth_profile['ans_cap'] ) ? (int) $depth_profile['ans_cap'] : ( $has_guru ? 1100 : 750 );
		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A13 — per-call answer
		// cap override for long-form astro each-day replies.
		if ( isset( $opts['final_compose_ans_cap'] ) ) {
			$ans_cap = max( 300, (int) $opts['final_compose_ans_cap'] );
		}

		// Split answers same as Synthesizer for symmetry.
		$nb_lines  = [];
		$web_lines = [];
		foreach ( $answers as $a ) {
			$mode = (string) ( $a['mode'] ?? '' );
			if ( $mode === 'quick' || $mode === 'deep' ) {
				$web_lines[] = $a;
				continue;
			}
			$nb_lines[] = $a;
		}

		$nb_block  = $this->render_nb_compact( array_slice( $nb_lines,  0, self::MAX_NB_ROWS ) );
		$web_block = $this->render_web_compact( array_slice( $web_lines, 0, self::MAX_WEB_ROWS ) );

		// Synthesizer summary — give the LLM the structured findings so it
		// doesn't have to re-derive them; its job is purely VOICE + flow.
		$consensus = (array) ( $synth['consensus'] ?? [] );
		$tensions  = (array) ( $synth['tensions']  ?? [] );
		$rec       = trim( (string) ( $synth['recommendation'] ?? '' ) );
		$synth_md  = trim( (string) ( $synth['answer_md']      ?? '' ) );

		$synth_block = "### B\u00c1O C\u00c1O T\u1eea SYNTHESIZER\n";
		if ( $consensus ) {
			$synth_block .= "**Consensus:**\n- " . implode( "\n- ", array_map( 'wp_strip_all_tags', $consensus ) ) . "\n";
		}
		if ( $tensions ) {
			$synth_block .= "\n**Tensions:**\n- " . implode( "\n- ", array_map( 'wp_strip_all_tags', $tensions ) ) . "\n";
		}
		if ( $rec !== '' ) {
			$synth_block .= "\n**Recommendation:** " . wp_strip_all_tags( $rec ) . "\n";
		}
		if ( $synth_md !== '' ) {
			$synth_block .= "\n**Synthesizer answer (raw):**\n" . mb_substr( wp_strip_all_tags( $synth_md ), 0, self::ANS_TRUNC );
		}

		$has_web = ! empty( $web_lines );
		$web_rule = $has_web
			? "5. Khi tr\u00edch ngu\u1ed3n web, B\u1eaeT BU\u1ed8C d\u00f9ng token `[web:<N>#<URL>]` (\u0111\u00fang index trong CITATION MAP \u1edf khung WEB SOURCES). KH\u00d4NG \u0111\u1ed5i th\u00e0nh footnote s\u1ed1 ho\u1eb7c [^1]."
			: "5. Turn n\u00e0y kh\u00f4ng c\u00f3 ngu\u1ed3n web \u2014 ch\u1ec9 d\u00f9ng [nb:X/pY] khi tr\u00edch notebook.";

		$persona_hint = $has_guru
			? "\n6. Gi\u1eef gi\u1ecdng v\u0103n persona/guru \u0111ang b\u1ecb b\u00ecnh \u2014 KH\u00d4NG d\u00f9ng gi\u1ecdng b\u00e1o c\u00e1o trung t\u00ednh n\u1ebfu persona y\u00eau c\u1ea7u kh\u00e1c (vd persona Tarot dung gi\u1ecdng huy\u1ec1n b\u00ed; persona m\u1eb9 d\u00f9ng gi\u1ecdng \u1ea5m \u00e1p)."
			: '';

		// [2026-07-18 Johnny Chu] PHASE-TWINWEB — surface-level grounding/prompt policy overrides Guru defaults for TwinWeb final answers.
		$twinweb_policy = $this->twinweb_grounding_policy( $opts );
		$twinweb_prompt_block = is_array( $twinweb_policy )
			? $this->render_twinweb_policy_block( $twinweb_policy, $has_web )
			: '';

		$system = <<<SYS
B\u1ea1n l\u00e0 Final Composer c\u1ee7a TwinBrain \u2014 vi\u1ebft c\u00e2u tr\u1ea3 l\u1eddi cu\u1ed1i c\u00f9ng cho user.

NGUY\u00caN T\u1eaeC:
1. \u0110\u00e2y l\u00e0 tin nh\u1eafn user s\u1ebd \u0111\u1ecdc \u2014 vi\u1ebft m\u01b0\u1ee3t, t\u1ef1 nhi\u00ean, ti\u1ebfng Vi\u1ec7t. Markdown nh\u1eb9 (heading, list khi c\u1ea7n).
2. D\u00f9ng B\u00c1O C\u00c1O T\u1eea SYNTHESIZER l\u00e0m x\u01b0\u01a1ng s\u1ed1ng. KH\u00d4NG ph\u1ea3n b\u00e1c synthesizer; nhi\u1ec7m v\u1ee5 c\u1ee7a b\u1ea1n l\u00e0 di\u1ec5n \u0111\u1ea1t l\u1ea1i th\u00e0nh c\u00e2u tr\u1ea3 l\u1eddi h\u00fau \u00edch.
3. T\u1ed1i \u0111a {$ans_cap} t\u1eeb. C\u1ea5u tr\u00fac \u0111\u1ec1 ngh\u1ecb: m\u1edf b\u00e0i ng\u1eafn \u2192 \u0111i\u1ec3m ch\u00ednh (consensus) \u2192 l\u01b0u \u00fd / m\u00e2u thu\u1eabn (tensions) \u2192 (n\u1ebfu c\u00f3) khuy\u1ebfn ngh\u1ecb \u2192 k\u1ebft.
4. M\u1ed7i lu\u1eadn \u0111i\u1ec3m c\u00f3 ngu\u1ed3n notebook PH\u1ea2I k\u00e8m citation `[nb:<notebook_id>/p<passage_id>]` (sao ch\u00e9p \u0111\u00fang token t\u1eeb synthesizer).
{$web_rule}{$persona_hint}
7. Khi th\u00f4ng tin m\u00e2u thu\u1eabn, n\u00eau r\u00f5 \u201cm\u1ed9t s\u1ed1 ngu\u1ed3n n\u00f3i X, ngu\u1ed3n kh\u00e1c n\u00f3i Y\u201d \u2014 KH\u00d4NG trung b\u00ecnh ho\u00e1.
8. KH\u00d4NG xu\u1ea5t JSON, KH\u00d4NG ```fence. Ch\u1ec9 markdown thu\u1ea7n.
9. N\u1ebfu kh\u1ed1i MEMORY (\ud83e\udde0) ph\u00eda d\u01b0\u1edbi cung c\u1ea5p th\u00f4ng tin user \u0111\u00e3 d\u1eb7n / s\u1edf th\u00edch / m\u1ee5c ti\u00eau li\u00ean quan c\u00e2u h\u1ecfi \u2192 t\u00f4n tr\u1ecdng v\u00e0 echo token `[mem:U#<id>]` (ho\u1eb7c `[mem:E#<id>]`, `[mem:R#<id>]`) ngay c\u1ea1nh c\u00e2u v\u0103n s\u1eed d\u1ee5ng memory \u0111\u00f3. KH\u00d4NG b\u1ecf qua y\u00eau c\u1ea7u user \u0111\u00e3 d\u1eb7n.
SYS;

		// [2026-08-05 Johnny Chu] V4.2 — give the model a bounded section contract while keeping prose generation flexible.
		$answer_skeleton = is_array( $opts['answer_skeleton'] ?? null ) ? $opts['answer_skeleton'] : array();
		if ( ! empty( $answer_skeleton['id'] ) ) {
			$required_sections = implode( ', ', array_map( 'sanitize_key', (array) ( $answer_skeleton['required_sections'] ?? array() ) ) );
			$system .= "\n\n## ANSWER SKELETON CONTRACT\n"
				. "skeleton_id: " . sanitize_key( (string) $answer_skeleton['id'] ) . "\n"
				. "required_sections: " . $required_sections . "\n"
				. "Giữ đúng thứ tự ưu tiên: kết luận ngắn trước, phân tích sau, hành động tiếp theo và giới hạn/safety khi cần. Không hiển thị tên section nội bộ nếu skeleton không yêu cầu heading đó.\n";
			if ( (string) ( $answer_skeleton['safety_level'] ?? '' ) === 'high' ) {
				$system .= "- Đây là câu hỏi health/medical: phân biệt dữ kiện user cung cấp, dữ kiện có nguồn và suy luận; dùng ngôn ngữ có điều kiện; thêm lưu ý an toàn/red flags; không chẩn đoán hoặc đưa định lượng cứng nếu evidence không đủ.\n";
			}
		}

		if ( $twinweb_prompt_block !== '' ) {
			$system .= "\n\n" . $twinweb_prompt_block;
		}

		// Wave 2.8 TBR.MEM-6 — Mode 3 function-call tools (default ON từ
		// 2026-05-24 sau khi probe `twinbrain.memory.tool-calls` PASS). Filter
		// `bizcity_twinbrain_memory_tools_enabled` cho phép tắt khẩn cấp.
		// Khi ON, append schema 3 tool vào system prompt → LLM được phép emit
		// `<tool name="memory_*">{...}</tool>` inline trong câu trả lời.
		// Dispatcher (gọi từ Runtime sau final_done) parse + execute + rewrite
		// chip `[mem:U#<id>]`.
		$tools_enabled = (bool) apply_filters(
			'bizcity_twinbrain_memory_tools_enabled',
			true,
			$opts
		);
		if ( $tools_enabled && class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) ) {
			$tools_block = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->render_prompt_section();
			if ( $tools_block !== '' ) {
				$system .= "\n\n" . $tools_block
					. "\n## MEMORY TOOL USAGE NOTES\n"
					. "- CH\u1ec8 d\u00f9ng memory tool khi th\u1ef1c s\u1ef1 c\u1ea7n (user y\u00eau c\u1ea7u, ho\u1eb7c nh\u1eadn th\u1ea5y fact m\u1edbi quan tr\u1ecdng).\n"
					. "- Tool block c\u00f3 th\u1ec3 \u0111\u1eb7t INLINE gi\u1eefa c\u00e2u tr\u1ea3 l\u1eddi \u2014 system s\u1ebd t\u1ef1 strip block v\u00e0 thay b\u1eb1ng citation `[mem:U#<id>]` (cho memory_remember).\n"
					. "- KH\u00d4NG g\u1ecdi `memory_remember` cho th\u00f4ng tin \u0111\u00e3 c\u00f3 trong memory recall block \u1edf d\u01b0\u1edbi.\n"
					. "- KH\u00d4NG g\u1ecdi `memory_recall` n\u1ebfu memory block \u0111\u00e3 \u0111\u1ee7 th\u00f4ng tin.\n"
					. "- T\u1ed1i \u0111a 3 write call (`memory_remember` + `memory_forget` c\u1ed9ng d\u1ed3n) + 5 `memory_recall` m\u1ed7i turn.";
			}
		}

		$notebook_source_block = trim( (string) ( $opts['notebook_source_block_md'] ?? '' ) );
		$notebook_source_map   = isset( $opts['notebook_source_map'] ) && is_array( $opts['notebook_source_map'] ) ? $opts['notebook_source_map'] : array();
		$source_file_briefs    = isset( $opts['source_file_briefs'] ) && is_array( $opts['source_file_briefs'] ) ? $opts['source_file_briefs'] : array();
		$final_context_chunks  = isset( $opts['final_context_chunks'] ) && is_array( $opts['final_context_chunks'] ) ? $opts['final_context_chunks'] : array();
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.14 — answer-intent/list-products contract context.
		$answer_intent = isset( $opts['answer_intent_meta'] ) && is_array( $opts['answer_intent_meta'] )
			? $opts['answer_intent_meta']
			: $this->resolve_answer_intent( $prompt, $opts );
		$product_entities = isset( $opts['product_entities'] ) && is_array( $opts['product_entities'] )
			? $opts['product_entities']
			: $this->extract_product_entities( $opts );
		$product_name_entities = $this->filter_product_name_entities( $product_entities );
		$named_evidence_candidates = isset( $opts['named_evidence_candidates'] ) && is_array( $opts['named_evidence_candidates'] )
			? $opts['named_evidence_candidates']
			: $this->extract_named_evidence_candidates( $opts );
		if ( $notebook_source_block !== '' && ! empty( $notebook_source_map ) ) {
			// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — make notebook source identity a hard composer contract.
			$system .= "\n\n## NOTEBOOK SOURCE CONTRACT\n"
				. "- Câu trả lời đang ở Notebook/Ask Brain mode. Notebook là nguồn nội bộ chính, không phải phụ lục debug.\n"
				. "- Answer depth profile: `" . (string) ( $depth_profile['profile'] ?? self::NOTEBOOK_DEPTH_DEFAULT ) . "`; budget: tối đa {$ans_cap} từ; contract: " . (string) ( $depth_profile['evidence_contract'] ?? 'sourced synthesis' ) . ".\n"
				. "- Khi dùng dữ kiện từ notebook, copy đúng token `[nb:<notebook_id>/p<passage_id>]` trong NOTEBOOK SOURCE MAP.\n"
				. "- Phải nêu rõ notebook title/id khi tổng hợp hoặc khi có mâu thuẫn giữa nguồn.\n"
				. "- Với profile `deep` hoặc `audit`, ưu tiên cấu trúc: Kết luận ngắn → Phân tích từ notebook → Đồng thuận/mâu thuẫn → Khuyến nghị.\n"
				. "- Nếu suy luận ngoài notebook, ghi rõ đó là suy luận/mở rộng, không gắn citation giả.\n"
				. "- Tuyệt đối không tạo token `[nb:0/p0]` hoặc passage id không có trong source map.";
			if ( ! empty( $source_file_briefs ) ) {
				// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT W0.9 — force Ask Brain to reason by uploaded source file and Search Core context when briefs exist.
				$system .= "\n- W0.7 Source File Deep Layer đang bật: với profile `deep` hoặc `audit`, BẮT BUỘC có mục `Phân tích theo file nguồn`, nêu từng file/source đã dùng, citation chính và relation triples nếu có.";
				$system .= "\n- W0.9 Search Context Layer đang bật: đọc các dòng `search_hit` trong SOURCE FILE BRIEF MAP như context bổ sung từ Search Core. Khi dùng search_hit, copy đúng token [nb:X/pY] đi kèm.";
				if ( ! empty( $final_context_chunks ) ) {
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — final context chunks are the canonical Graph/vector/rerank pack.
					$system .= "\n- W0.20 FINAL CONTEXT CHUNKS đang bật: đây là pack canonical Graph → retrieval top30 → rerank → top5-8. Ưu tiên các chunk này cho mọi factual claim; các block source map/search/file brief còn lại là evidence phụ và UI trace.";
					$system .= "\n- Khi chunk có `citation`, copy đúng citation đó. Không dùng source title/category thay thế nội dung chunk.";
				}
				// [2026-08-05 Johnny Chu] V4-SKELETON — separate document identity from verified product identity in every domain.
				$system .= "\n- W0.11 Product-name contract: với câu hỏi liệt kê sản phẩm, dòng hàng hoặc thương hiệu cụ thể (sữa, thực phẩm, thuốc, thiết bị...), chỉ dùng row `product_entities` có `entity_type=product_name` hoặc `is_product_name=true` để nêu tên. `source_title` chỉ là tên tài liệu tham chiếu, tuyệt đối không được coi là tên sản phẩm hoặc tự suy ra tên từ tiêu đề file. Với mỗi product entity được đề cập, copy đúng citation token tương ứng.";
				$system .= "\n- Nếu PRODUCT ENTITIES không có tên sản phẩm đã xác minh, phải nói rõ chưa trích được tên cụ thể; không được biến tên file, tên notebook, category hoặc claim phrase thành tên sản phẩm.";
				if ( ! empty( $answer_intent['requires_named_evidence'] ) ) {
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — product_entities beats source-title scaffold for product list answers.
					$system .= "\n- W0.16 Product Entity contract: intent=`" . (string) ( $answer_intent['intent'] ?? 'list_products' ) . "`. Chỉ các row `entity_type=product_name` hoặc `is_product_name=true` mới được xem là tên dòng sữa/sản phẩm. Nếu có PRODUCT ENTITIES hợp lệ, BẮT BUỘC mở đầu bằng `## Các dòng sữa tìm thấy từ nội dung`, rồi bảng 5 cột: `Dòng sữa/entity` · `Source title` · `Evidence excerpt` · `Confidence` · `Citation`. Không dùng tiêu đề file/tài liệu/category/claim phrase thay thế tên dòng sữa.";
					$system .= "\n- Nếu PRODUCT ENTITIES rỗng hoặc chỉ có category/claim rows nhưng source files có liên quan, phải nói đúng: `Có file liên quan, nhưng chưa trích được tên dòng sữa cụ thể từ nội dung.` Sau đó mới giải thích bằng evidence hiện có. Tuyệt đối không giả vờ bảng source-title/category là tên dòng sữa.";
					if ( ! empty( $product_name_entities ) ) {
						$system .= "\n- PRODUCT ENTITIES hợp lệ đã trích được: copy nguyên văn entity name từ block PRODUCT ENTITIES, ít nhất 3 dòng đầu nếu có. Sau bảng mới phân tích GOS/FOS, Lactoferrin, A2/OPO hoặc khác biệt giữa nguồn.";
					}
				}
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 cross-notebook graph contract.
				$_cross_links = (array) ( $opts['cross_notebook_links'] ?? array() );
				if ( ! empty( $_cross_links ) ) {
					$system .= "\n- W0.12 Cross-Notebook Graph đang bật: mục CROSS-NOTEBOOK ENTITY GRAPH liệt kê các cặp notebook chia sẻ entity/token chung. Khi trả lời, nếu có liên hệ giữa hai notebook (ví dụ cùng đề cập một thành phần dinh dưỡng), hãy nêu rõ mối liên hệ đó và dùng citation từ TỪNG notebook để minh chứng — đây là điểm mạnh so sánh chéo duy nhất mà user không thể tự đọc.";
				}
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.19 — training gap report remains internal evidence, not a user-facing answer section.
				$_gap_report = (array) ( $opts['training_gap_report'] ?? array() );
				if ( ! empty( $_gap_report ) ) {
					$system .= "\n- W0.19 Báo cáo thiếu hụt dữ liệu là evidence nội bộ cho UI/debug. Không thêm phần khuyến nghị bổ sung dữ liệu vào câu trả lời cuối, trừ khi user hỏi riêng về audit nguồn.";
				}
			}
		}

		$user_parts = [
			"## C\u00c2U H\u1ed0I C\u1ee6A USER\n" . $prompt,
			$synth_block,
		];
		$goal_loop_brief = trim( (string) ( $opts['goal_loop_brief'] ?? '' ) );
		if ( $goal_loop_brief !== '' ) {
			// [2026-08-01 Johnny Chu] PHASE-TWIN-GOAL-LOOP-G2 — keep the final answer accountable to the active goal and open loops.
			$system .= "\n\n## TWIN GOAL LOOP CONTRACT\n"
				. "Đây là goal đang mở của người dùng. Trả lời lượt này phải phục vụ goal, không tuyên bố hoàn thành nếu chưa có evidence. Nếu còn open loop, nêu bước tiếp theo rõ ràng.\n";
			$user_parts[] = "### ACTIVE GOAL BRIEF\n" . mb_substr( $goal_loop_brief, 0, 3000 );
		}
		// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — apply the pre-final PASS/PATCH scoreboard as an internal answer-completeness contract.
		$final_gate = is_array( $opts['final_gate'] ?? null ) ? $opts['final_gate'] : array();
		$scoreboard = is_array( $final_gate['scoreboard'] ?? null ) ? $final_gate['scoreboard'] : array();
		$fallback_policy = sanitize_key( (string) ( $final_gate['fallback_policy'] ?? 'normal_answer' ) );
		$score_rows = (array) ( $scoreboard['rows'] ?? array() );
		$obligation_map = array();
		foreach ( (array) ( $opts['goal_contract']['answer_obligations'] ?? array() ) as $obligation ) {
			if ( is_array( $obligation ) && ! empty( $obligation['id'] ) ) {
				$obligation_map[ (string) $obligation['id'] ] = (string) ( $obligation['question'] ?? '' );
			}
		}
		if ( ! empty( $score_rows ) ) {
			$score_lines = array();
			foreach ( array_slice( $score_rows, 0, 20 ) as $score_row ) {
				if ( ! is_array( $score_row ) || empty( $score_row['obligation_id'] ) ) {
					continue;
				}
				$obligation_id = (string) $score_row['obligation_id'];
				$route = strtoupper( (string) ( $score_row['route'] ?? 'PATCH' ) );
				$line = $obligation_id . ' [' . $route . ', coverage=' . (string) ( $score_row['coverage'] ?? 0 ) . ']';
				if ( ! empty( $obligation_map[ $obligation_id ] ) ) {
					$line .= ': ' . $obligation_map[ $obligation_id ];
				}
				if ( ! empty( $score_row['gap'] ) ) {
					$line .= ' | gap: ' . sanitize_text_field( (string) $score_row['gap'] );
				}
				$score_lines[] = '- ' . $line;
			}
			if ( ! empty( $score_lines ) ) {
				$system .= "\n\n## RESOLUTION SCOREBOARD\n"
					. "Bổ sung mọi obligation có route PATCH vào câu trả lời nếu evidence hiện có đủ. Không bỏ qua obligation MUST. Không bịa evidence cho route RETRIEVE; nếu gate_reason cho phép fallback, nói rõ giới hạn thông tin.\n"
					. implode( "\n", $score_lines );
			}
		}
		if ( $fallback_policy === 'answer_with_limit_notice' ) {
			// [2026-08-04 Johnny Chu] R-MPR-GOALBOARD — bounded fallback must be honest about unresolved evidence and must not claim completion.
			$system .= "\n\n## FINAL GATE FALLBACK POLICY\n"
				. "Gate chưa mở hoàn toàn. Trả lời phần có evidence, nói ngắn gọn thông tin nào còn chưa xác minh, và đưa ra bước tiếp theo cụ thể. Không nói 'đã kiểm tra đầy đủ', 'chắc chắn', hoặc tuyên bố goal đã hoàn tất nếu scoreboard còn RETRIEVE.\n";
		}

		$subject_ctx = trim( (string) ( $opts['subject_context_md'] ?? '' ) );
		if ( $subject_ctx !== '' ) {
			// [2026-07-19 Johnny Chu] PHASE-TWIN-GPT-PROFILE-GROUNDING — subject-first profile contract for non-Astro Notebook/vertical answers.
			$subject_label = trim( (string) ( $opts['subject_context_label'] ?? 'HỒ SƠ CUSTOMER' ) );
			$system .= "\n\n## SUBJECT PROFILE CONTRACT\n"
				. "- Luôn xác định chủ thể/customer trước khi trả lời. Hồ sơ dưới đây là ngữ cảnh cá nhân hóa, không phải citation nguồn.\n"
				. "- Khi kết hợp với Notebook, Notebook/source map vẫn là nguồn kiến thức doanh nghiệp và phải dùng citation [nb:X/pY] cho claim từ Notebook.\n"
				. "- Không bịa thêm dữ kiện hồ sơ. Nếu thiếu trường quan trọng, nói rõ đang trả lời với hồ sơ chưa đủ và gợi ý customer hoàn tất Hồ sơ AI.\n"
				. "- Riêng Astro vẫn dùng contract [astro:*] và block Chủ thể/Transit/Kết luận nếu extra_context_md chứa dữ liệu chiêm tinh.";
			$user_parts[] = "### " . ( $subject_label !== '' ? $subject_label : 'HỒ SƠ CUSTOMER' ) . "\n" . mb_substr( $subject_ctx, 0, 6000 );
		}

		$multimodal_ctx = trim( (string) ( $opts['multimodal_context_md'] ?? '' ) );
		if ( $multimodal_ctx !== '' ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — Final Composer must consume vision/file intake facts separately from Notebook citations.
			$system .= "\n\n## MULTIMODAL INTAKE CONTRACT\n"
				. "- Nếu MULTIMODAL INTAKE CONTEXT có vision/file facts thành công, dùng facts đó để trả lời câu hỏi về ảnh/file trước khi dùng Notebook; với câu hỏi kiểu `ảnh gì đây`, mở đầu bằng kết quả Vision LLM.\n"
				. "- Visual/file observations không được gắn citation [nb:*] giả. Chỉ cite Notebook khi claim đến từ Notebook Source Map.\n"
				. "- Nếu degraded=true hoặc reason_bucket khác rỗng, mở đầu bằng `Vision LLM chưa phân tích được ảnh/file` và nêu reason_bucket; không được trả lời như thể Notebook là nguồn phân tích ảnh.\n"
				. "- Khi Notebook evidence không liên quan đến ảnh/file, không ép citation từ Notebook vào câu trả lời.";
			$user_parts[] = "### MULTIMODAL INTAKE CONTEXT\n" . mb_substr( $multimodal_ctx, 0, 7000 );
		}

		/* [2026-06-04 Johnny Chu] PHASE-A C.3b — Full-context injection.
		 * Astro (và các mode cần ngữ cảnh dài) truyền `extra_context_md` để
		 * đưa NGUYÊN dữ liệu (vd transit markdown 8KB) vào prompt mà KHÔNG bị
		 * cắt bởi ANS_TRUNC=1200 như nhánh synthesizer. Cap rộng (12KB) để
		 * tránh prompt nổ token; filter cho phép tuỳ chỉnh. */
		$extra_ctx = trim( (string) ( $opts['extra_context_md'] ?? '' ) );
		if ( $extra_ctx !== '' ) {
			$extra_cap = (int) apply_filters(
				'bizcity_twinbrain_final_compose_extra_ctx_cap',
				12000,
				$opts
			);
			$extra_label = (string) ( $opts['extra_context_label'] ?? 'FULL CONTEXT DATA (read carefully)' );
			$user_parts[] = "### " . $extra_label . "\n"
				. mb_substr( $extra_ctx, 0, max( 1000, $extra_cap ) );

			// [2026-06-10 Johnny Chu] ASTRO-CITE 3 — inject explicit citation rule
			// into system prompt when astro token URLs are present in context.
			// Without this, LLM treats [astro:*#URL] as data, not as citable links.
			if ( strpos( $extra_ctx, '[astro:' ) !== false ) {
				$astro_subject_line_min = isset( $opts['astro_subject_line_min'] )
					? max( 8, (int) $opts['astro_subject_line_min'] )
					: 20;
				$astro_subject_line_max = isset( $opts['astro_subject_line_max'] )
					? max( $astro_subject_line_min, (int) $opts['astro_subject_line_max'] )
					: max( $astro_subject_line_min, 24 );
				$astro_subject_mode = isset( $opts['astro_subject_mode'] )
					? sanitize_key( (string) $opts['astro_subject_mode'] )
					: 'temporal_signal';
				$astro_deep_analysis_requested = ! empty( $opts['astro_deep_analysis_requested'] );
				$astro_focus_domains = isset( $opts['astro_focus_domains'] ) && is_array( $opts['astro_focus_domains'] )
					? array_values( array_unique( array_filter( array_map( 'sanitize_key', $opts['astro_focus_domains'] ) ) ) )
					: array();

				$system .= "\n10. Trong phần DỮ LIỆU CHIÊM TINH đầu context có các token dạng `[astro:natal#URL]` và `[astro:transit#URL]`. "
					. "KHI đề cập bản đồ sao cá nhân hoặc lịch quá cảnh trong câu trả lời, BẮT BUỘC copy nguyên token đó vào đúng vị trí câu văn. "
					. "Ví dụ: \"...ảnh hưởng đến bản đồ sao của bạn [astro:natal#https://...]\". "
					. "KHÔNG viết lại URL, KHÔNG bỏ token, KHÔNG thay bằng dấu ngoặc vuông khác.";
				// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — astro response contract: 3 blocks + citation boundary.
				$system .= "\n11. Đây là chế độ ASTRO. BẮT BUỘC chia câu trả lời thành ĐÚNG 3 block, theo thứ tự:\n"
					. "- `## 1) Chủ thể` : xác định người được luận + thông tin natal/birth liên quan, có [astro:natal#URL] nếu dùng dữ liệu bản đồ sao.\n"
					. "- `## 2) Transit` : phân tích ảnh hưởng theo cửa sổ thời gian đã parse (không đảo lộn ngày), có [astro:transit#URL] hoặc [astro:transit_day#URL] cho từng luận điểm.\n"
					. "- `## 3) Kết luận` : kết luận ngắn gọn và hành động gợi ý.\n"
					. "Không thêm block thứ 4, không gộp 3 block vào một đoạn.";
				$system .= "\n12. Ranh giới citation bắt buộc: token `[mem:*]` chỉ dùng cho dữ kiện memory; token `[astro:*]` chỉ dùng cho dữ kiện chiêm tinh. "
					. "Không được dùng chéo namespace trong cùng luận điểm.";
			}
			if ( strpos( $extra_ctx, '## PHÂN TÍCH TRANSIT THEO TỪNG NGÀY (DETERMINISTIC)' ) !== false ) {
				$system .= "\n13. Với câu hỏi transit nhiều ngày, BẮT BUỘC trả lời theo thứ tự từng ngày trong cửa sổ (đủ tất cả ngày), "
					. "mỗi ngày có nhận định ngắn. Sau đó mới có mục 'Kết luận cuối' chọn 1 ngày tốt nhất dựa trên dữ liệu từng ngày. "
					. "KHÔNG được bỏ qua phần foreach theo ngày.";
			}
				// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — enforce subject depth contract.
				$system .= "\n14. Block `## 1) Chủ thể` BẮT BUỘC dài khoảng {$astro_subject_line_min}-{$astro_subject_line_max} dòng. "
					. "Không rút gọn còn vài câu. Ưu tiên diễn giải natal + tính cách + động lực sự nghiệp + xu hướng hành vi, "
					. "và gắn token [astro:natal#...] / [mem:*] đúng namespace khi dùng dữ kiện.";
				if ( strpos( $astro_subject_mode, 'subject_default_tomorrow' ) === 0 ) {
					$system .= "\n15. Mode này là KHÔNG có tín hiệu thời gian trong câu hỏi: "
						. "Block `## 2) Transit` mặc định nói về ngày mai (start_offset=1), giữ ngắn gọn hơn block Chủ thể, "
						. "và vẫn phải có [astro:transit_day#...] hoặc [astro:transit#...] cho kết luận transit.";
				} else {
					$system .= "\n15. Mode này có tín hiệu thời gian/tương lai: vẫn giữ block Chủ thể ở độ dài yêu cầu trên, "
						. "sau đó mới mở rộng block Transit theo đúng cửa sổ thời gian đã parse.";
				}
				// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — hard rule for
				// deep-analysis prompts (chi tiết/kỹ lưỡng/phân tích sâu).
				if ( $astro_deep_analysis_requested ) {
					$domain_labels = array(
						'career'  => 'sự nghiệp/công việc',
						'finance' => 'tài chính/tiền bạc',
						'love'    => 'tình duyên/tình cảm',
						'family'  => 'gia đình',
						'life'    => 'cuộc đời/đường đời',
					);
					$focus_label_rows = array();
					foreach ( $astro_focus_domains as $_d ) {
						if ( isset( $domain_labels[ $_d ] ) ) {
							$focus_label_rows[] = $domain_labels[ $_d ];
						}
					}
					if ( empty( $focus_label_rows ) ) {
						$focus_label_rows = array_values( $domain_labels );
					}
					$system .= "\n16. User đang yêu cầu phân tích sâu/chi tiết: trong block `## 1) Chủ thể`, "
						. "bắt buộc triển khai thành nhiều đoạn rõ ràng theo các trục: "
						. implode( '; ', $focus_label_rows )
						. ". Mỗi trục nêu hiện trạng + điểm mạnh + rủi ro + hành động gợi ý ngắn.";
					$system .= "\n17. Khi có mode deep, tuyệt đối không trả lời chung chung. "
						. "Nếu thiếu dữ kiện cho một trục, phải nói rõ giả định và mức chắc chắn; "
						. "không được bỏ qua trục user đã hỏi.";
				}
		}

		/* Wave 2.8 (TBR.MEM-3) — prepend Memory Recall block to user message
		 * so the LLM sees user identity / preferences / explicit "hãy nhớ"
		 * notes before reading synthesizer output. Block already includes
		 * `[mem:U#<id>]` tokens for citation echo. */
		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );
		if ( $memory_block !== '' ) {
			array_unshift( $user_parts, $memory_block );
		}
		if ( $nb_block !== '' ) {
			$user_parts[] = "### NOTEBOOK PERSPECTIVES (compact)\n" . $nb_block;
		}
		if ( ! empty( $answer_intent['requires_named_evidence'] ) ) {
			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.15 — product entity block is the primary list-products evidence.
			$user_parts[] = "### ANSWER INTENT + PRODUCT ENTITIES (W0.15)\n"
				. "intent: `" . (string) ( $answer_intent['intent'] ?? 'list_products' ) . "`; reason: `" . (string) ( $answer_intent['reason'] ?? '' ) . "`\n"
				. "Task: answer the user's product-list question from product_name rows only. If this block is empty, say no concrete product entity was extracted; do not substitute source/document titles or category traits.\n"
				. ( ! empty( $product_name_entities ) ? $this->render_product_entities_compact( $product_name_entities ) : 'PRODUCT ENTITIES: [] (no concrete product_name rows)' );
			if ( empty( $product_name_entities ) && ! empty( $named_evidence_candidates ) ) {
				$user_parts[] = "### SOURCE-TITLE FALLBACK FOR DEBUG ONLY (do not present as product names)\n" . $this->render_named_evidence_candidates_compact( $named_evidence_candidates );
			}
		}
		if ( $notebook_source_block !== '' && ! empty( $notebook_source_map ) ) {
			$source_json = (string) wp_json_encode( $notebook_source_map, JSON_UNESCAPED_UNICODE );
			$source_file_json = ! empty( $source_file_briefs ) ? (string) wp_json_encode( $source_file_briefs, JSON_UNESCAPED_UNICODE ) : '[]';
			$source_file_block = $this->render_source_file_briefs_compact( $source_file_briefs );
			$final_context_block = $this->render_final_context_chunks_compact( $final_context_chunks );

			// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4 cross-notebook graph block for LLM synthesis.
			$cross_links = (array) ( $opts['cross_notebook_links'] ?? array() );
			$cross_graph_block = $this->render_cross_notebook_links_compact( $cross_links );

			$user_parts[] = ( $final_context_block !== '' ? "### FINAL CONTEXT CHUNKS (W0.20 — read first)\n" . $final_context_block . "\n\n" : '' )
				. "### NOTEBOOK SOURCE MAP (canonical)\n"
				. $notebook_source_block
				. "\n\n```json\n"
				. mb_substr( $source_json, 0, 10000 )
				. "\n```"
				. ( $source_file_block !== '' ? "\n\n### SOURCE FILE BRIEF MAP (read first for deep/audit)\n" . $source_file_block : '' )
				. "\n\n### SOURCE FILE DEEP BRIEFS (W0.7)\n```json\n"
				. mb_substr( $source_file_json, 0, 8000 )
				. "\n```"
				// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — append cross-notebook graph section.
				. ( $cross_graph_block !== '' ? "\n\n### CROSS-NOTEBOOK ENTITY GRAPH (W0.12)\n" . $cross_graph_block : '' );
		}
		if ( $web_block !== '' ) {
			$user_parts[] = "### WEB SOURCES + CITATION MAP\n" . $web_block;
		}
		$user_parts[] = "Vi\u1ebft c\u00e2u tr\u1ea3 l\u1eddi cu\u1ed1i c\u00f9ng cho user theo nguy\u00ean t\u1eafc tr\u00ean.";

		$user_content = implode( "\n\n", $user_parts );
		$image_urls = array();
		foreach ( (array) ( $opts['images'] ?? array() ) as $image_url ) {
			$image_url = is_array( $image_url ) ? (string) ( $image_url['url'] ?? '' ) : (string) $image_url;
			if ( $image_url !== '' && filter_var( $image_url, FILTER_VALIDATE_URL ) && ! in_array( $image_url, $image_urls, true ) ) {
				$image_urls[] = $image_url;
			}
		}
		if ( ! empty( $image_urls ) ) {
			// [2026-08-02 Johnny Chu] PHASE-ZALO-VISION — send image URLs as multimodal user content so Final Composer/Luna can inspect the actual image.
			$content = array( array( 'type' => 'text', 'text' => $user_content ) );
			foreach ( $image_urls as $image_url ) {
				$content[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => $image_url, 'detail' => 'auto' ) );
			}
			return array(
				array( 'role' => 'system', 'content' => $system ),
				array( 'role' => 'user', 'content' => $content ),
			);
		}

		return array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user_content ),
		);
	}

	/**
	 * TwinWeb prompt/grounding policy. Returns null for non-TwinWeb or disabled config.
	 */
	private function twinweb_grounding_policy( array $opts ) {
		$surface = isset( $opts['surface'] ) ? sanitize_key( (string) $opts['surface'] ) : '';
		$policy  = isset( $opts['twinweb_grounding_policy'] ) && is_array( $opts['twinweb_grounding_policy'] )
			? $opts['twinweb_grounding_policy']
			: array();
		if ( $surface !== 'twinweb' || empty( $policy ) || empty( $policy['enabled'] ) ) {
			return null;
		}
		return $policy;
	}

	/**
	 * Render TwinWeb grounding and prompt overrides. Appended after the base
	 * composer rules so it intentionally has higher priority on TwinWeb turns.
	 */
	private function render_twinweb_policy_block( array $policy, bool $has_web ): string {
		$profile = isset( $policy['profile'] ) ? sanitize_key( (string) $policy['profile'] ) : 'balanced';
		$citation_mode = isset( $policy['citation_mode'] ) ? sanitize_key( (string) $policy['citation_mode'] ) : 'key_claims';
		$lines = array(
			'## TWINWEB OVERRIDE POLICY',
			'- Surface: TwinWeb. This policy overrides Guru grounding defaults for this final answer.',
			'- Grounding profile: `' . $profile . '`; citation mode: `' . $citation_mode . '`.',
		);

		if ( $profile === 'loose' ) {
			$lines[] = '- Replace stricter citation rules above: answer naturally and helpfully; use notebook/web citations for important sourced facts, but do not cite every connective sentence or example.';
			$lines[] = '- You may reason beyond RAG when useful, but explicitly mark it as inference/general guidance when it is not directly in notebook/web sources.';
		} elseif ( $profile === 'balanced' ) {
			$lines[] = '- Replace stricter citation rules above: cite key claims and source-backed recommendations; avoid repetitive citations on paraphrase, transitions, and minor examples.';
		} else {
			$lines[] = '- Keep strict grounding: every source-backed claim needs the correct notebook/web token.';
		}

		if ( ! $has_web ) {
			$lines[] = '- No web source block is present this turn; use notebook citations when citing RAG, and label non-RAG reasoning clearly.';
		}

		$twinweb_prompt = trim( wp_strip_all_tags( (string) ( $policy['twinweb_system_prompt'] ?? '' ) ) );
		if ( $twinweb_prompt !== '' ) {
			$lines[] = "\n## TWINWEB SYSTEM PROMPT\n" . mb_substr( $twinweb_prompt, 0, 1800 );
		}

		$override_guru = ! empty( $policy['override_guru'] );
		$guru_prompt   = trim( wp_strip_all_tags( (string) ( $policy['guru_system_prompt'] ?? '' ) ) );
		if ( $override_guru && $guru_prompt !== '' ) {
			$lines[] = "\n## GURU SYSTEM PROMPT OVERRIDE\n" . mb_substr( $guru_prompt, 0, 1800 );
		}

		return implode( "\n", $lines );
	}

	private function render_nb_compact( array $rows ): string {
		if ( ! $rows ) return '';
		$out = [];
		foreach ( $rows as $a ) {
			$out[] = sprintf(
				'- **%s** (id=%d, stance=%s/%.2f): %s',
				(string) ( $a['label']       ?? '' ),
				(int)    ( $a['notebook_id'] ?? 0 ),
				(string) ( $a['stance']      ?? 'unknown' ),
				(float)  ( $a['confidence']  ?? 0.0 ),
				mb_substr( wp_strip_all_tags( (string) ( $a['answer_md'] ?? '' ) ), 0, 360 )
			);
		}
		return implode( "\n", $out );
	}

	private function render_source_file_briefs_compact( array $briefs ): string {
		// [2026-07-18 Johnny Chu] PHASE-TBR-NB-MOAT — make W0.7 source-file briefs easy for the composer to follow.
		if ( empty( $briefs ) ) {
			return '';
		}
		$out = array();
		foreach ( array_slice( $briefs, 0, 10 ) as $brief ) {
			if ( ! is_array( $brief ) ) {
				continue;
			}
			$title = trim( (string) ( $brief['source_title'] ?? '' ) );
			if ( $title === '' ) {
				$title = 'Source #' . (int) ( $brief['source_id'] ?? 0 );
			}
			$citations = array_slice( array_values( (array) ( $brief['key_citations'] ?? array() ) ), 0, 6 );
			$tokens = array_slice( array_values( (array) ( $brief['matched_tokens'] ?? array() ) ), 0, 8 );
			$out[] = sprintf(
				'- Source `%d` · %s · coverage=%s · used=%d/%d · citations=%s',
				(int) ( $brief['source_id'] ?? 0 ),
				$title,
				(string) ( $brief['coverage'] ?? 'weak' ),
				(int) ( $brief['passage_count_used'] ?? 0 ),
				(int) ( $brief['passage_count_total'] ?? 0 ),
				empty( $citations ) ? 'none' : implode( ', ', $citations )
			);
			if ( ! empty( $tokens ) ) {
				$out[] = '  matched_tokens: ' . implode( ', ', array_map( 'strval', $tokens ) );
			}
			$claims = array_slice( array_values( (array) ( $brief['source_claims'] ?? array() ) ), 0, 3 );
			foreach ( $claims as $claim ) {
				$claim = trim( wp_strip_all_tags( (string) $claim ) );
				if ( $claim !== '' ) {
					$out[] = '  claim: ' . mb_substr( $claim, 0, 220 );
				}
			}
			$search_hits = array_slice( array_values( (array) ( $brief['search_context_hits'] ?? array() ) ), 0, 6 );
			foreach ( $search_hits as $hit ) {
				if ( ! is_array( $hit ) ) {
					continue;
				}
				$excerpt = trim( wp_strip_all_tags( (string) ( $hit['excerpt'] ?? '' ) ) );
				$token = trim( (string) ( $hit['token'] ?? '' ) );
				if ( $excerpt !== '' && $token !== '' ) {
					// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.10 — show deeper backend Search Core context explicitly in final composer prompt.
					$out[] = '  search_hit: ' . $token . ' ' . mb_substr( $excerpt, 0, 900 );
				}
			}
			$triples = array_slice( array_values( (array) ( $brief['relation_triples'] ?? array() ) ), 0, 4 );
			foreach ( $triples as $triple ) {
				if ( ! is_array( $triple ) ) {
					continue;
				}
				$out[] = sprintf(
					'  triple: %s --%s--> %s',
					mb_substr( trim( (string) ( $triple['subject'] ?? '' ) ), 0, 120 ),
					mb_substr( trim( (string) ( $triple['predicate'] ?? 'related_to' ) ), 0, 80 ),
					mb_substr( trim( (string) ( $triple['object'] ?? '' ) ), 0, 120 )
				);
			}
			$gaps = array_slice( array_values( (array) ( $brief['source_gaps'] ?? array() ) ), 0, 2 );
			foreach ( $gaps as $gap ) {
				$gap = trim( wp_strip_all_tags( (string) $gap ) );
				if ( $gap !== '' ) {
					$out[] = '  training_gap: ' . mb_substr( $gap, 0, 180 );
				}
			}
		}
		return implode( "\n", $out );
	}

	private function render_final_context_chunks_compact( array $chunks ): string {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.20 — render canonical reranked top chunks before broader source-map evidence.
		if ( empty( $chunks ) ) {
			return '';
		}
		$out = array();
		foreach ( array_slice( $chunks, 0, 8 ) as $idx => $chunk ) {
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			$citation = trim( (string) ( $chunk['citation'] ?? '' ) );
			$title = trim( (string) ( $chunk['source_title'] ?? '' ) );
			if ( $title === '' ) {
				$title = trim( (string) ( $chunk['notebook_title'] ?? 'Notebook #' . (int) ( $chunk['notebook_id'] ?? 0 ) ) );
			}
			$excerpt = trim( wp_strip_all_tags( (string) ( $chunk['excerpt'] ?? '' ) ) );
			if ( $excerpt === '' ) {
				continue;
			}
			$out[] = sprintf(
				'%d. %s · score=%d · reason=%s · source=%s',
				$idx + 1,
				$citation !== '' ? $citation : '[no-citation]',
				(int) ( $chunk['rerank_score'] ?? 0 ),
				(string) ( $chunk['rerank_reason'] ?? 'search_rank' ),
				mb_substr( $title, 0, 120 )
			);
			$out[] = '   excerpt: ' . mb_substr( $excerpt, 0, 900 );
		}
		return implode( "\n", $out );
	}

	// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.12 — N4: render cross-notebook entity graph section for LLM prompt.
	private function render_cross_notebook_links_compact( array $links ): string {
		if ( empty( $links ) ) {
			return '';
		}
		$out = array();
		foreach ( array_slice( $links, 0, 8 ) as $link ) {
			$nb_ids   = (array) ( $link['notebook_ids'] ?? array() );
			$nb_titles = (array) ( $link['notebook_titles'] ?? array() );
			$tokens   = (array) ( $link['shared_tokens'] ?? array() );
			$cit_a    = (array) ( $link['citations_a'] ?? array() );
			$cit_b    = (array) ( $link['citations_b'] ?? array() );
			$count    = (int) ( $link['shared_count'] ?? count( $tokens ) );

			$label_a = trim( (string) ( $nb_titles[0] ?? 'nb' . ( $nb_ids[0] ?? '?' ) ) );
			$label_b = trim( (string) ( $nb_titles[1] ?? 'nb' . ( $nb_ids[1] ?? '?' ) ) );

			$token_str = implode( ', ', array_slice( $tokens, 0, 8 ) );
			$cit_a_str = implode( ' ', array_slice( $cit_a, 0, 4 ) );
			$cit_b_str = implode( ' ', array_slice( $cit_b, 0, 4 ) );

			$line = sprintf(
				'link: [%s] <--> [%s] · shared_entities(%d): %s',
				mb_substr( $label_a, 0, 60 ),
				mb_substr( $label_b, 0, 60 ),
				$count,
				mb_substr( $token_str, 0, 200 )
			);
			if ( $cit_a_str !== '' || $cit_b_str !== '' ) {
				$line .= sprintf(
					' · citations_a: %s · citations_b: %s',
					mb_substr( $cit_a_str, 0, 120 ),
					mb_substr( $cit_b_str, 0, 120 )
				);
			}
			$out[] = $line;
		}
		return implode( "\n", $out );
	}

	// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MOAT W0.13 — N5: render training gap report section for LLM training loop.
	private function render_training_gap_report_compact( array $report ): string {
		if ( empty( $report ) ) {
			return '';
		}
		$out = array();
		foreach ( array_slice( $report, 0, 5 ) as $entry ) {
			$nb_title = mb_substr( trim( (string) ( $entry['notebook_title'] ?? 'Notebook #' . ( $entry['notebook_id'] ?? '?' ) ) ), 0, 80 );
			$weak     = (int) ( $entry['weak_source_count'] ?? 0 );
			$total    = (int) ( $entry['total_source_count'] ?? 0 );
			$pct      = (int) ( $entry['gap_coverage_pct'] ?? 0 );

			$out[] = sprintf( 'notebook: [%s] — weak %d/%d files (%d%%)', $nb_title, $weak, $total, $pct );

			$sources = array_slice( (array) ( $entry['gap_sources'] ?? array() ), 0, 4 );
			foreach ( $sources as $src ) {
				$out[] = '  weak_file: ' . mb_substr( trim( (string) $src ), 0, 180 );
			}
			$suggestions = array_slice( (array) ( $entry['suggested_content'] ?? array() ), 0, 3 );
			foreach ( $suggestions as $sug ) {
				$out[] = '  suggest: ' . mb_substr( trim( (string) $sug ), 0, 200 );
			}
		}
		return implode( "\n", $out );
	}

	private function render_web_compact( array $rows ): string {
		if ( ! $rows ) return '';
		$out = [];
		foreach ( $rows as $row ) {
			$mode    = strtoupper( (string) ( $row['mode'] ?? 'web' ) );
			$results = (array) ( $row['results'] ?? [] );
			$ans     = trim( wp_strip_all_tags( (string) ( $row['answer_md'] ?? '' ) ) );

			$out[] = sprintf( '#### WEB · %s (%d results)', $mode, count( $results ) );
			if ( $ans !== '' ) {
				$out[] = '_' . mb_substr( $ans, 0, 600 ) . '_';
			}
			foreach ( array_slice( $results, 0, 8 ) as $i => $r ) {
				$out[] = sprintf(
					'  [web:%d#%s] %s — %s — %s',
					$i + 1,
					(string) ( $r['url']    ?? '' ),
					(string) ( $r['title']  ?? '' ),
					(string) ( $r['domain'] ?? '' ),
					mb_substr( (string) ( $r['snippet'] ?? '' ), 0, self::WEB_SNIPPET )
				);
			}
		}
		return implode( "\n", $out );
	}

	/* =================================================================
	 *  Chat-mode compose (TBR.W18 — 2026-05-28)
	 * ================================================================ */

	/**
	 * Stream a casual chat answer using ONLY memory_block + prompt.
	 *
	 * Used by Runtime auto-degrade branch when K=0 candidates,
	 * K=0 tool candidates, web_mode=off but Memory_Recall produced
	 * a non-empty block (≥ MIN bytes). Bypasses Perspective / Web /
	 * Tool / Synthesizer layers — only the user-facing answer is
	 * generated. The prompt is intentionally lighter: no "BÁO CÁO
	 * TỪ SYNTHESIZER" framing, no citation enforcement (memory
	 * citations `[mem:U#<id>]` still allowed because memory_block
	 * embeds them).
	 *
	 * @param string        $trace_id  Turn trace id.
	 * @param string        $prompt    User prompt.
	 * @param array         $opts      { memory_block, guru_id?, model?, locale? }
	 * @param callable|null $on_token  fn($delta, $accumulated) for SSE relay.
	 * @return array {success, answer_md, model, tokens, ms, fallback, error}
	 */
	public function compose_chat_stream(
		string $trace_id,
		string $prompt,
		array $opts = [],
		$on_token = null
	): array {
		$t0 = microtime( true );

		$memory_block = trim( (string) ( $opts['memory_block'] ?? '' ) );
		$followup_contract = $this->resolve_followup_contract( $opts );

		if ( ! $this->gateway_ready() ) {
			$msg = $memory_block !== ''
				? "Mình đã ghi nhớ một số thông tin về bạn nhưng hiện chưa kết nối được hệ thống LLM. Vui lòng thử lại sau."
				: '';
			if ( $msg !== '' ) {
				$repaired = $this->append_followup_contract( $msg, $opts );
				$msg = (string) $repaired['answer_md'];
				$followup_contract = (array) $repaired['contract'];
			}
			if ( is_callable( $on_token ) && $msg !== '' ) {
				call_user_func( $on_token, $msg, $msg );
			}
			return [
				'success'   => $msg !== '',
				'answer_md' => $msg,
				'model'     => '',
				'tokens'    => 0,
				'ms'        => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
				'fallback'  => 'gateway_unavailable',
				'error'     => '',
			];
		}

		$has_guru = ! empty( $opts['guru_id'] );
		// [2026-07-18 Johnny Chu] PHASE-TWINWEB-C-ENDUSER — chat fallback should satisfy C users with fuller replies.
		$ans_cap  = $has_guru ? 900 : 650;

		$persona_hint = $has_guru
			? "\n6. Giữ giọng persona/guru đang được bind — không dùng giọng báo cáo trung tính."
			: '';

		// [2026-06-03 Johnny Chu] HOTFIX — Companion mode: empathic system
		// prompt khi internal casual_fast_path chọn companion_mode. Dùng memory để
		// thấu cảm / tâm sự / đồng hành, không phải đưa kiến thức.
		$companion = ! empty( $opts['companion_mode'] );

		if ( $companion ) {
			$system = <<<SYS
Bạn là **người bạn đồng hành** của user — một presence ấm áp, lắng nghe, biết ghi nhớ những gì user đã chia sẻ. KHÔNG có notebook / web research / tool nào cho turn này, và đó là chủ đích — user muốn TRÒ CHUYỆN, không cần tra cứu kiến thức.

NGUYÊN TẮC GIAO TIẾP:
1. Giọng văn ấm, gần gũi, tự nhiên như nhắn tin với một người bạn thân. Tiếng Việt. Tối đa {$ans_cap} từ.
2. Ưu tiên **lắng nghe và phản chiếu cảm xúc** trước, gợi ý / lời khuyên sau (và chỉ khi user thực sự xin). Đặt câu hỏi mở khi phù hợp để user mở lòng tiếp.
3. Dùng MEMORY BLOCK để nhớ tên / sở thích / chuyện cũ user đã kể — gọi đúng tên, nhắc lại context cũ một cách tinh tế (không liệt kê dài dòng). Khi nhắc tới fact từ memory, vẫn echo `[mem:U#<id>]` / `[mem:E#<id>]` / `[mem:R#<id>]` ngay cạnh câu văn.
4. KHÔNG bịa fact ngoài MEMORY BLOCK. Nếu user hỏi info cụ thể không có trong memory → thành thật "mình chưa biết phần này", và quay lại dòng cảm xúc / câu chuyện chính.
5. KHÔNG dùng heading lớn / bullet list / fence code / JSON. Văn xuôi, 1-3 đoạn ngắn. Có thể dùng emoji nhẹ nhàng (1-2 cái) khi phù hợp tâm trạng.{$persona_hint}

NHỚ: User mở chế độ này vì cần một người bạn, không cần một trợ lý kiến thức.
SYS;
		} else {
			$system = <<<SYS
Bạn là TwinBrain ở **chế độ trò chuyện** — không có notebook / web research / tool nào cho turn này. Chỉ có MEMORY BLOCK (lịch sử ngắn + ghi chú user dặn) và câu hỏi hiện tại.

NGUYÊN TẮC:
1. Trả lời tự nhiên, ngắn gọn, giọng trò chuyện (chat), tiếng Việt. Tối đa {$ans_cap} từ.
2. KHÔNG bịa fact ngoài MEMORY BLOCK. Nếu user hỏi info ngoài phạm vi memory → trả lời chân thật "Mình chưa có thông tin chi tiết về ý này, bạn cho mình thêm context nhé?" hoặc gợi ý user gắn notebook / bật web search.
3. Nếu MEMORY BLOCK chứa fact liên quan câu hỏi → echo token `[mem:U#<id>]` / `[mem:E#<id>]` / `[mem:R#<id>]` ngay cạnh câu văn sử dụng fact đó.
4. KHÔNG dùng heading lớn / bullet list trừ khi câu trả lời thực sự là enumerate. Ưu tiên 1-3 đoạn văn ngắn.
5. KHÔNG xuất JSON, KHÔNG ```fence.{$persona_hint}
SYS;
		}
		if ( ! empty( $followup_contract['required'] ) && $followup_contract['question'] !== '' ) {
			// [2026-08-10 Johnny Chu] GOAL-FOLLOWUP-1 — chat/companion path must preserve the active Goal Loop question.
			$system .= "\n\n## GOAL LOOP FOLLOW-UP CONTRACT\n"
				. "Sau câu trả lời, luôn kết thúc bằng đúng một câu hỏi thúc đẩy goal sau đây: "
				. $followup_contract['question'] . "\n"
				. "Không thay bằng câu hỏi chung chung và không hỏi lại dữ kiện đã có.\n";
		}

		// Memory tool schema (opt-in) — same filter as compose_stream.
		$tools_enabled = (bool) apply_filters(
			'bizcity_twinbrain_memory_tools_enabled',
			true,
			$opts
		);
		if ( $tools_enabled && class_exists( 'BizCity_TwinBrain_Memory_Tool_Dispatcher' ) ) {
			$tools_block = BizCity_TwinBrain_Memory_Tool_Dispatcher::instance()->render_prompt_section();
			if ( $tools_block !== '' ) {
				$system .= "\n\n" . $tools_block
					. "\n## MEMORY TOOL USAGE NOTES (chat mode)\n"
					. "- Chế độ chat chủ yếu để trả lời nhanh; chỉ gọi `memory_remember` khi user dặn rõ ('hãy nhớ ...').\n"
					. "- KHÔNG gọi `memory_recall` — block memory đã có sẵn ở dưới.\n"
					. "- Tối đa 2 write call mỗi turn.";
			}
		}

		$user_parts = [];
		if ( $memory_block !== '' ) {
			$user_parts[] = $memory_block;
		}
		$user_parts[] = "## CÂU HỎI CỦA USER\n" . $prompt;
		$user_parts[] = "Trả lời theo nguyên tắc ở system prompt.";

		$messages = [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => implode( "\n\n", $user_parts ) ],
		];

		$req_surface = sanitize_key( (string) ( $opts['surface'] ?? 'twinbrain' ) );
		$usage_flow  = ( $req_surface === 'twinweb' ) ? 'b2b2c' : 'b2b';
		$usage_channel = ( $req_surface === 'twinweb' ) ? 'twinclient' : 'twinchat';

		$client = BizCity_LLM_Client::instance();
		$model  = (string) ( $opts['model'] ?? '' );
		if ( $model === '' ) {
			$model = (string) apply_filters(
				'bizcity_twinbrain_final_compose_chat_model',
				$client->get_model( 'chat' )
			);
		}

		$accumulated = '';
		$delta_n     = 0;
		$relay = function ( $delta, $full ) use ( &$accumulated, &$delta_n, $on_token ) {
			$accumulated = (string) $full;
			$delta_n++;
			if ( is_callable( $on_token ) ) {
				call_user_func( $on_token, (string) $delta, (string) $full );
			}
		};

		$result = $client->chat_stream(
			$messages,
			[
				'purpose'     => self::PURPOSE . '_chat',
				'flow'        => $usage_flow,
				'surface'     => $req_surface,
				'channel'     => $usage_channel,
				'model'       => $model,
				'temperature' => self::TEMPERATURE,
				'max_tokens'  => $has_guru ? self::MAX_TOKENS_GURU : self::MAX_TOKENS,
				'timeout'     => self::TIMEOUT_S,
				// [2026-07-07 Johnny Chu] HOTFIX — forward keepalive callback so
				// runtime can emit `final_keepalive` SSE while waiting next token.
				'on_keepalive' => isset( $opts['on_keepalive'] ) ? $opts['on_keepalive'] : null,
				'extra_body'  => [
					'site_url' => home_url(),
					'trace_id' => $trace_id,
					'mode'     => $companion ? 'chat_companion' : 'chat_auto_degrade',
				],
			],
			$relay
		);

		$elapsed    = (int) ( ( microtime( true ) - $t0 ) * 1000 );
		$final_text = trim( (string) ( $result['message'] ?? $accumulated ) );
		$followup_result = array( 'answer_md' => $final_text, 'contract' => $followup_contract );
		if ( $final_text !== '' ) {
			$followup_result = $this->append_followup_contract( $final_text, $opts );
			$final_text = (string) $followup_result['answer_md'];
			if ( is_callable( $on_token ) && ! empty( $followup_result['contract']['rendered'] ) ) {
				// [2026-08-10 Johnny Chu] GOAL-FOLLOWUP-1 — relay deterministic question repair after chat stream completion.
				call_user_func( $on_token, "\n\n**Một câu hỏi để mình tiếp tục:** " . (string) $followup_result['contract']['question'], $final_text );
			}
		}

		if ( empty( $result['success'] ) || $final_text === '' ) {
			$fallback_text = $memory_block !== ''
				? "Mình chưa tổng hợp được câu trả lời đầy đủ, nhưng có ghi nhớ một số thông tin liên quan. Bạn có thể hỏi lại với context cụ thể hơn không?"
				: '';
			if ( $fallback_text !== '' ) {
				$fallback_result = $this->append_followup_contract( $fallback_text, $opts );
				$fallback_text = (string) $fallback_result['answer_md'];
				$followup_result = $fallback_result;
			}
			if ( is_callable( $on_token ) && $fallback_text !== '' && $delta_n === 0 ) {
				call_user_func( $on_token, $fallback_text, $fallback_text );
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][final-compose-chat] trace=' . $trace_id
					. ' fallback err=' . ( $result['error'] ?? 'empty' )
					. ' deltas=' . $delta_n
				);
			}
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — pass quota_exhausted + quota_layer through.
			return [
				'success'         => $fallback_text !== '',
				'answer_md'       => $fallback_text,
				'model'           => (string) ( $result['model'] ?? $model ),
				'tokens'          => (int) ( $result['usage']['total_tokens'] ?? 0 ),
				'ms'              => $elapsed,
				'fallback'        => 'chat_stream_empty:' . ( $result['error'] ?? 'unknown' ),
				'error'           => (string) ( $result['error'] ?? '' ),
				'quota_exhausted' => ! empty( $result['quota_exhausted'] ),
				'quota_layer'     => isset( $result['quota_layer'] ) ? (string) $result['quota_layer'] : '',
				'tier'            => isset( $result['tier'] ) ? (string) $result['tier'] : '',
				'followup_gate'   => (string) ( $followup_result['contract']['gate'] ?? 'not_required' ),
				'followup_contract' => (array) ( $followup_result['contract'] ?? $followup_contract ),
			];
		}

		return [
			'success'         => true,
			'answer_md'       => $final_text,
			'model'           => (string) ( $result['model'] ?? $model ),
			'tokens'          => (int) ( $result['usage']['total_tokens'] ?? 0 ),
			'ms'              => $elapsed,
			'fallback'        => '',
			'error'           => '',
			'quota_exhausted' => false,
			'quota_layer'     => '',
			'tier'            => '',
			'followup_gate'   => (string) ( $followup_result['contract']['gate'] ?? 'not_required' ),
			'followup_contract' => (array) ( $followup_result['contract'] ?? $followup_contract ),
		];
	}

	/* =================================================================
	 *  Helpers
	 * ================================================================ */

	private function build_multimodal_fallback_answer( array $opts ): string {
		// [2026-07-19 Johnny Chu] PHASE-TBR-NB-MULTIMODAL — return visible Vision/File result when final compose cannot call LLM.
		$pack = isset( $opts['multimodal_ingest_pack'] ) && is_array( $opts['multimodal_ingest_pack'] )
			? $opts['multimodal_ingest_pack']
			: array();
		if ( empty( $pack ) || empty( $pack['attachments'] ) ) {
			return '';
		}

		foreach ( (array) ( $pack['vision'] ?? array() ) as $vision ) {
			if ( ! is_array( $vision ) || empty( $vision['success'] ) ) {
				continue;
			}
			$summary = trim( (string) ( $vision['summary'] ?? '' ) );
			$ocr     = array_values( array_filter( array_map( 'strval', (array) ( $vision['ocr_text'] ?? array() ) ) ) );
			$entities = array_values( array_filter( array_map( 'strval', (array) ( $vision['entities'] ?? array() ) ) ) );
			$lines = array( 'Theo Vision LLM, ảnh đính kèm có nội dung chính như sau:' );
			if ( '' !== $summary ) {
				$lines[] = '';
				$lines[] = $summary;
			}
			if ( ! empty( $ocr ) ) {
				$lines[] = '';
				$lines[] = 'Chữ đọc được trong ảnh: ' . implode( ', ', array_slice( $ocr, 0, 8 ) ) . '.';
			}
			if ( ! empty( $entities ) ) {
				$lines[] = '';
				$lines[] = 'Entity nhận diện: ' . implode( ', ', array_slice( $entities, 0, 8 ) ) . '.';
			}
			return trim( implode( "\n", $lines ) );
		}

		if ( ! empty( $pack['degraded'] ) ) {
			$reason = trim( (string) ( $pack['reason_bucket'] ?? 'vision_unavailable' ) );
			return 'Vision LLM chưa phân tích được ảnh/file đính kèm. Reason: `' . ( '' !== $reason ? $reason : 'vision_unavailable' ) . '`. Tôi chỉ có metadata/tên file, chưa có nội dung hình ảnh thật để mô tả.';
		}

		return '';
	}

	private function gateway_ready(): bool {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return false;
		$c = BizCity_LLM_Client::instance();
		return method_exists( $c, 'is_ready' ) ? $c->is_ready() : true;
	}
}
