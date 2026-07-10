<?php
/**
 * TwinBrain — Web Research Astro Engine (PHASE-A C.3b · 2026-06-04).
 *
 * Engine riêng cho conversation-surface mode `astro`, song song với
 * `class-twinbrain-web-deep.php` (ReAct) và `class-twinbrain-web-law.php`
 * (vertical search). KHÔNG gọi Tavily — nguồn là **artifact nội bộ** của
 * coachee (natal chart + transit) lấy qua CAP filter
 * `bizcity_twin_context_artifacts` (RFC §0.3) → `BizCoach_Pro_Astro_Transit_Resolver`.
 *
 * Pipeline (multi-step, observable qua MPR Thinking Timeline):
 *
 *   1. emit `astro_research_started`  { trace_id, query }
 *   2. classify period via LLM mini (fail-safe regex `bccm_transit_detect_intent`)
 *      → emit `astro_intent_detected` { period, label, source:llm|regex|default, ms, tokens }
 *   3. apply_filters('bizcity_twin_context_artifacts','astro', $user_id, …)
 *      → resolver tự DB-first lookup natal + transit
 *   4. emit `astro_artifacts_loaded` { passage_count, cap_source, _degraded, ms }
 *
 * KHÔNG compose final answer ở đây. Caller (`stream_astro_mode()`) cầm
 * passages về và đẩy qua `BizCity_TwinBrain_Final_Composer::compose_stream()`
 * để streaming SSE `final_token` cho user — giữ y nguyên hot-path hiện tại.
 *
 * Hard rules:
 *   - R-GW-8     LLM classify đi qua `BizCity_LLM_Client::chat()` (purpose=classify).
 *   - R-EVT-1    Event dispatch qua `BizCity_Twin_Event_Bus::dispatch()` duy nhất.
 *   - R-CAP-2    Fail-OPEN: provider vắng → return passages=[] + _degraded reason.
 *   - R-PP-4     Passage shape giữ nguyên từ resolver (`{title, body, metadata}`).
 *   - PHP 7.4    No union return type, no nullsafe, no match.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-06-04 (PHASE-A C.3b)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Astro {

	const LLM_CLASSIFY_TIMEOUT_S = 8;
	const LLM_CLASSIFY_MAX_TOKENS = 32;
	const LLM_TEMPERATURE         = 0.0;
	const DEFAULT_PERIOD          = 'day';

	/** Whitelist returnable từ classifier (khớp resolver PERIODS). */
	const ALLOWED_PERIODS = [ 'day', 'week', 'month', 'year', '5year' ];

	/**
	 * Extended period map — includes num_days + render_mode.
	 * Used when LLM classifier returns a period token.
	 */
	const PERIOD_META = [
		'day'   => [ 'num_days' => 1,    'render_mode' => 'daily',    'label' => 'Hôm nay' ],
		'week'  => [ 'num_days' => 7,    'render_mode' => 'daily',    'label' => '7 ngày tới' ],
		'month' => [ 'num_days' => 30,   'render_mode' => 'overview', 'label' => '1 tháng tới' ],
		'year'  => [ 'num_days' => 365,  'render_mode' => 'overview', 'label' => '1 năm tới' ],
		'5year' => [ 'num_days' => 1825, 'render_mode' => 'overview', 'label' => '5 năm tới' ],
	];

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Run Astro engine.
	 *
	 * @param string $trace_id
	 * @param string $query
	 * @param array  $opts { user_id, coachee_id?, session_id?, guru_id? }
	 * @return array {
	 *     period, label, classify_source, classify_ms, classify_tokens,
	 *     passages, cap_source, cap_ms, _degraded, ms, error
	 * }
	 */
	public function run( string $trace_id, string $query, array $opts = array() ): array {
		// [2026-06-04 Johnny Chu] PHASE-A C.3b — Web_Astro engine entry.
		$t0      = microtime( true );
		$query   = trim( $query );
		$user_id = (int) ( $opts['user_id'] ?? get_current_user_id() );

		$row = array(
			'mode'               => 'astro',
			'trace_id'           => $trace_id,
			'query'              => $query,
			'period'             => self::DEFAULT_PERIOD,
			'label'              => '',
			'num_days'           => 1,           // [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE
			'render_mode'        => 'daily',     // 'daily' | 'overview' | 'fallback'
			'requested_days'     => 0,           // original requested when > 30 (fallback)
			'classify_source'    => 'default',
			'classify_ms'        => 0,
			'classify_tokens'    => 0,
			'passages'           => array(),
			'cap_source'         => 'unavailable',
			'cap_ms'             => 0,
			'_degraded'          => '',
			'ms'                 => 0,
			'error'              => '',
			'temporal_signal_detected' => false,
			'defaulted_tomorrow' => false,
			'deep_analysis_requested' => false,
			'deep_focus_domains' => array(),
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — relation-mode classifier fields.
			'analysis_mode' => 'transit_each_day',
			'relation_query_detected' => false,
			'relation_target_name_hint' => '',
			'relation_target_coachee_id' => 0,
			'relation_lenses' => array(),
			'relation_requires_transit_sync' => false,
			'relation_source_marker' => 'twinbrain_chat',
			// [2026-06-10 Johnny Chu] HOTFIX — expose resolved coachee for transparency.
			'coachee_id_resolved' => 0,
			'coachee_resolve_source' => 'self_default',
			'name_extracted'     => '',
		);

		$this->emit( 'astro_research_started', array(
			'trace_id' => $trace_id,
			'mode'     => 'astro',
			'query'    => $query,
			'user_id'  => $user_id,
		) );

		if ( $query === '' ) {
			$row['error']     = 'empty_query';
			$row['_degraded'] = 'empty_query';
			$row['ms']        = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			return $row;
		}

		/* ─── Step 1: classify period ─────────────────────────────────── */
		$cls_t0       = microtime( true );
		$classified   = $this->classify_period( $query );
		$row['period']          = (string) $classified['period'];
		$row['label']           = (string) $classified['label'];
		$row['classify_source'] = (string) $classified['source'];
		$row['classify_tokens'] = (int)    $classified['tokens'];
		$row['classify_ms']     = (int) round( ( microtime( true ) - $cls_t0 ) * 1000 );
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — forward num_days + render_mode
		$row['num_days']        = (int)    ( $classified['num_days']      ?? 1 );
		$row['render_mode']     = (string) ( $classified['render_mode']   ?? 'daily' );
		$row['requested_days']  = (int)    ( $classified['requested_days'] ?? 0 );
		// [2026-07-04 Johnny Chu] PHASE-ASTRO-WORKFLOW — forward start_offset + topics
		$row['start_offset']    = (int)    ( $classified['start_offset']  ?? 0 );
		$row['topics']          = is_array( $classified['topics'] ?? null ) ? (array) $classified['topics'] : array();
		$row['temporal_signal_detected'] = ! empty( $classified['temporal_signal_detected'] );
		$row['defaulted_tomorrow'] = ! empty( $classified['defaulted_tomorrow'] );
		$row['deep_analysis_requested'] = ! empty( $classified['deep_analysis_requested'] );
		$row['deep_focus_domains'] = isset( $classified['deep_focus_domains'] ) && is_array( $classified['deep_focus_domains'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $classified['deep_focus_domains'] ) ) ) )
			: array();
		$row['analysis_mode'] = (string) ( $classified['analysis_mode'] ?? 'transit_each_day' );
		$row['relation_query_detected'] = ! empty( $classified['relation_query_detected'] );
		$row['relation_target_name_hint'] = (string) ( $classified['relation_target_name_hint'] ?? '' );
		$row['relation_target_coachee_id'] = (int) ( $classified['relation_target_coachee_id'] ?? 0 );
		$row['relation_lenses'] = isset( $classified['relation_lenses'] ) && is_array( $classified['relation_lenses'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $classified['relation_lenses'] ) ) ) )
			: array();
		$row['relation_requires_transit_sync'] = ! empty( $classified['relation_requires_transit_sync'] );
		$row['relation_source_marker'] = $this->normalize_relation_source_marker( (string) ( $opts['source_marker'] ?? '' ) );

		$this->emit( 'astro_intent_detected', array(
			'trace_id'    => $trace_id,
			'period'      => $row['period'],
			'label'       => $row['label'],
			'num_days'    => $row['num_days'],
			'render_mode' => $row['render_mode'],
			'source'      => $row['classify_source'],
			'tokens'      => $row['classify_tokens'],
			'temporal_signal_detected' => (bool) $row['temporal_signal_detected'],
			'defaulted_tomorrow' => (bool) $row['defaulted_tomorrow'],
			'deep_analysis_requested' => (bool) $row['deep_analysis_requested'],
			'deep_focus_domains' => (array) $row['deep_focus_domains'],
			'analysis_mode' => (string) $row['analysis_mode'],
			'relation_query_detected' => (bool) $row['relation_query_detected'],
			'relation_target_name_hint' => (string) $row['relation_target_name_hint'],
			'relation_target_coachee_id' => (int) $row['relation_target_coachee_id'],
			'relation_lenses' => (array) $row['relation_lenses'],
			'relation_requires_transit_sync' => (bool) $row['relation_requires_transit_sync'],
			'relation_source_marker' => (string) $row['relation_source_marker'],
			'ms'          => $row['classify_ms'],
		) );

		if ( $row['analysis_mode'] === 'relation_profile' ) {
			$this->emit( 'astro_relation_intent_detected', array(
				'trace_id' => $trace_id,
				'analysis_mode' => (string) $row['analysis_mode'],
				'relation_target_name_hint' => (string) $row['relation_target_name_hint'],
				'relation_target_coachee_id' => (int) $row['relation_target_coachee_id'],
				'relation_lenses' => (array) $row['relation_lenses'],
				'relation_requires_transit_sync' => (bool) $row['relation_requires_transit_sync'],
				'source' => (string) $row['relation_source_marker'],
			) );
		}

		/* ─── Step 2: resolve artifacts via CAP filter ────────────────── */
		$cap_t0   = microtime( true );
		$passages = array();
		try {
			// [2026-06-08 Johnny Chu] HOTFIX — resolve named coachee from query
			// (e.g. "của Kim Thoa") so admin can ask about clients.
			// [2026-06-10 Johnny Chu] HOTFIX — also return name_extracted for step-1 transparency.
			$coachee_id_hint = isset( $opts['coachee_id'] ) ? (int) $opts['coachee_id'] : 0;
			$analysis_mode   = (string) ( $row['analysis_mode'] ?? 'transit_each_day' );
			$name_extracted  = '';
			$resolve_source  = $coachee_id_hint > 0 ? 'opts' : 'self_default';

			if ( $analysis_mode === 'relation_profile' ) {
				$row['relation_target_coachee_id'] = isset( $opts['partner_coachee_id'] )
					? (int) $opts['partner_coachee_id']
					: (int) $row['relation_target_coachee_id'];
				if ( empty( $row['relation_target_name_hint'] ) && ! empty( $opts['partner_name_hint'] ) ) {
					$row['relation_target_name_hint'] = trim( (string) $opts['partner_name_hint'] );
				}
				if ( empty( $row['relation_target_name_hint'] ) ) {
					$row['relation_target_name_hint'] = $this->extract_relation_target_name_hint( $query );
				}
				if ( (int) $row['relation_target_coachee_id'] <= 0 && $row['relation_target_name_hint'] !== '' ) {
					$row['relation_target_coachee_id'] = $this->db_find_coachee_by_name( (string) $row['relation_target_name_hint'], $user_id );
				}
				$this->emit( 'astro_relation_intent_detected', array(
					'trace_id' => $trace_id,
					'analysis_mode' => 'relation_profile',
					'relation_target_name_hint' => (string) $row['relation_target_name_hint'],
					'relation_target_coachee_id' => (int) $row['relation_target_coachee_id'],
					'relation_lenses' => (array) $row['relation_lenses'],
					'relation_requires_transit_sync' => (bool) $row['relation_requires_transit_sync'],
					'source' => (string) $row['relation_source_marker'],
				) );
			}

			if ( $coachee_id_hint <= 0 && $analysis_mode !== 'relation_profile' ) {
				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — ownership-scoped name resolution.
				// Never resolve coachee by name outside current user's ownership unless admin.
				$resolved        = $this->resolve_coachee_id_from_query( $query, $user_id );
				$coachee_id_hint = (int) ( $resolved['id'] ?? 0 );
				$name_extracted  = (string) ( $resolved['name'] ?? '' );
				if ( $coachee_id_hint > 0 ) {
					$resolve_source = 'name_from_query';
				}
			}

			if ( $coachee_id_hint <= 0 ) {
				// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A5 — default "tôi"/no-subject
				// path must resolve to canonical self profile (is_self=1).
				$_self = null;
				if ( function_exists( 'bccm_get_self_coachee' ) ) {
					$_self = bccm_get_self_coachee( $user_id );
				} elseif ( function_exists( 'bccm_get_or_create_user_coachee' ) ) {
					$_self = bccm_get_or_create_user_coachee( $user_id, 'WEBCHAT', 'mental_coach' );
				}
				if ( is_array( $_self ) ) {
					$coachee_id_hint = isset( $_self['id'] ) ? (int) $_self['id'] : ( isset( $_self['coachee_id'] ) ? (int) $_self['coachee_id'] : 0 );
				} elseif ( is_numeric( $_self ) ) {
					$coachee_id_hint = (int) $_self;
				}
				if ( $coachee_id_hint > 0 ) {
					$resolve_source = 'self_default';
				}
			}
			// Store for return — caller runtime will emit updated astro_subject_resolved.
			$row['coachee_id_resolved'] = $coachee_id_hint;
			$row['coachee_resolve_source'] = $resolve_source;
			$row['name_extracted']      = $name_extracted;
			if ( $coachee_id_hint > 0 ) {
				$this->emit( 'astro_coachee_resolved', array(
					'trace_id'       => $trace_id,
					'coachee_id'     => $coachee_id_hint,
					'name_extracted' => $name_extracted,
					'source'         => $resolve_source,
				) );
			}
			$cap_opts = array_merge( $opts, array(
				'period'          => $row['period'],
				'num_days'        => $row['num_days'],
				// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — forward offset/topics so CAP resolves đúng cửa sổ (hôm nay/ngày mai/ngày kia).
				'start_offset'    => (int) $row['start_offset'],
				'topics'          => (array) $row['topics'],
				'analysis_mode'   => (string) $row['analysis_mode'],
				'relation_lenses' => (array) $row['relation_lenses'],
				'relation_target_coachee_id' => (int) $row['relation_target_coachee_id'],
				'relation_target_name_hint' => (string) $row['relation_target_name_hint'],
				'relation_requires_transit_sync' => (bool) $row['relation_requires_transit_sync'],
				'relation_source_marker' => (string) $row['relation_source_marker'],
				'time_label'      => (string) $row['label'],
				'render_mode'     => $row['render_mode'],
				'requested_days'  => $row['requested_days'],
				'message'         => $query,
				'trace_id'        => $trace_id,
				'user_id'         => $user_id,
				'coachee_id'      => $coachee_id_hint,
			) );
			$raw = apply_filters( 'bizcity_twin_context_artifacts', array(), 'astro', $user_id, $cap_opts );
			if ( is_array( $raw ) && ! empty( $raw ) ) {
				$passages          = $raw;
				$row['cap_source'] = $this->infer_cap_source( $raw );
			} else {
				$row['_degraded'] = 'astro_artifacts_empty';
			}
		} catch ( \Throwable $e ) {
			$row['_degraded'] = 'astro_cap_exception';
			$row['error']     = $e->getMessage();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][web-astro][cap_filter][error] trace=' . $trace_id . ' ' . $e->getMessage() );
			}
		}
		$row['passages'] = $passages;
		$row['cap_ms']   = (int) round( ( microtime( true ) - $cap_t0 ) * 1000 );

		$this->emit( 'astro_artifacts_loaded', array(
			'trace_id'      => $trace_id,
			'passage_count' => count( $passages ),
			'cap_source'    => $row['cap_source'],
			'_degraded'     => $row['_degraded'],
			'ms'            => $row['cap_ms'],
		) );

		$row['ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][web-astro] trace=%s period=%s(%s) passages=%d cap=%s degraded=%s ms=%d',
				$trace_id, $row['period'], $row['classify_source'],
				count( $passages ), $row['cap_source'], $row['_degraded'], $row['ms']
			) );
		}

		return $row;
	}

	/* =================================================================
	 *  Period classification
	 * ================================================================ */

	/**
	 * Classify intent period from query.
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A12 — LLM-first intent classifier.
	 * LLM returns full JSON {period, num_days, start_offset, label, render_mode}.
	 * Regex bccm_transit_detect_intent() is fallback (when LLM fails) + topics extractor.
	 *
	 * @return array { period, label, num_days, start_offset, render_mode, source, tokens, topics }
	 */
	private function classify_period( string $query ): array {
		$has_temporal_signal = $this->has_temporal_signal_in_query( $query );
		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — detect explicit
		// deep-analysis ask + requested life domains from user query.
		$deep_analysis_requested = $this->has_deep_analysis_signal_in_query( $query );
		$deep_focus_domains      = $this->extract_focus_domains_from_query( $query );
		// Load legacy transit lib for topics extraction (cheap, always needed).
		if ( ! function_exists( 'bccm_transit_detect_intent' ) && defined( 'BCPRO_DIR' ) ) {
			$_intent_file = BCPRO_DIR . 'legacy/lib/astro-transit.php';
			if ( file_exists( $_intent_file ) ) {
				require_once $_intent_file;
			}
		}

		$out = array(
			'period'       => self::DEFAULT_PERIOD,
			'label'        => 'Hôm nay',
			'num_days'     => 1,
			'render_mode'  => 'daily',
			'source'       => 'default',
			'tokens'       => 0,
			'start_offset' => 0,
			'topics'       => array(),
			'temporal_signal_detected' => $has_temporal_signal,
			'defaulted_tomorrow'       => false,
			'deep_analysis_requested'  => $deep_analysis_requested,
			'deep_focus_domains'       => $deep_focus_domains,
			// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — relation-mode defaults.
			'analysis_mode' => 'transit_each_day',
			'relation_query_detected' => false,
			'relation_target_name_hint' => '',
			'relation_target_coachee_id' => 0,
			'relation_lenses' => array(),
			'relation_requires_transit_sync' => false,
		);

		// Always extract topics via regex — needed for day scoring regardless of period source.
		if ( function_exists( 'bccm_transit_detect_intent' ) ) {
			$_rx = bccm_transit_detect_intent( $query );
			if ( is_array( $_rx ) && isset( $_rx['topics'] ) && is_array( $_rx['topics'] ) ) {
				$out['topics'] = $_rx['topics'];
			}
		}

		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A12 — LLM-first.
		// Regex keyword matching is the fallback only; its ordering bugs (e.g.
		// "3 ngày tới" matches 'ngày tới' in $tomorrow_patterns → num_days=1 not 3)
		// make it unsuitable as the primary source.
		$llm_success = false;
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$llm = BizCity_LLM_Client::instance();
			if ( $llm->is_ready() ) {
				$messages = array(
					array( 'role' => 'system', 'content' => $this->build_classify_system_prompt() ),
					array( 'role' => 'user',   'content' => 'Tin nhắn user: ' . $query ),
				);
				try {
					$resp = $llm->chat( $messages, array(
						'purpose'     => 'twinbrain_astro_classify',
						'temperature' => self::LLM_TEMPERATURE,
						'max_tokens'  => self::LLM_CLASSIFY_MAX_TOKENS,
						'timeout'     => self::LLM_CLASSIFY_TIMEOUT_S,
					) );
					if ( ! empty( $resp['success'] ) ) {
						$out['tokens'] = (int) ( $resp['usage']['total_tokens'] ?? 0 );
						$parsed = $this->parse_classify_response( (string) ( $resp['message'] ?? '' ) );
						if ( $parsed !== null ) {
							$out['period']       = $parsed['period'];
							$out['num_days']     = $parsed['num_days'];
							$out['start_offset'] = $parsed['start_offset'];
							$out['label']        = $parsed['label'];
							$out['render_mode']  = $parsed['render_mode'];
							$out['source']       = 'llm';
							$llm_success         = true;
						}
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TwinBrain][web-astro][classify][llm][exception] ' . $e->getMessage() );
					}
				}
			}
		}

		// Regex fallback — only when LLM unavailable or failed.
		if ( ! $llm_success && function_exists( 'bccm_transit_detect_intent' ) ) {
			$intent = bccm_transit_detect_intent( $query );
			if ( is_array( $intent ) && ! empty( $intent['period'] ) && in_array( (string) $intent['period'], self::ALLOWED_PERIODS, true ) ) {
				$rp = (string) $intent['period'];
				$out['period']         = $rp;
				$out['label']          = (string) ( $intent['label']          ?? $this->label_for_period( $rp ) );
				$out['num_days']       = (int)    ( $intent['num_days']       ?? ( self::PERIOD_META[ $rp ]['num_days']    ?? 1 ) );
				$out['render_mode']    = (string) ( $intent['render_mode']    ?? ( self::PERIOD_META[ $rp ]['render_mode'] ?? 'daily' ) );
				$out['start_offset']   = (int)    ( $intent['start_offset']   ?? 0 );
				$out['requested_days'] = (int)    ( $intent['requested_days'] ?? 0 );
				$out['source']         = 'regex';
			}
		}

		// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A12 — cap per-day tối đa 31 ngày.
		// Nếu LLM hoặc regex trả num_days > 31 → chuyển sang fallback mode:
		// CAP filter sẽ emit passage thông báo + fall-through sang legacy overview.
		if ( (int) $out['num_days'] > 31 ) {
			$out['requested_days'] = (int) $out['num_days'];
			$out['render_mode']    = 'fallback';
			$out['num_days']       = 31;
		}

		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — no-time-signal default:
		// when user asks generic astro (no temporal/future cue), transit defaults to tomorrow.
		if ( ! $has_temporal_signal ) {
			$out['period']             = 'day';
			$out['num_days']           = 1;
			$out['start_offset']       = 1;
			$out['label']              = 'Ngày mai';
			$out['render_mode']        = 'daily';
			$out['defaulted_tomorrow'] = true;
			if ( $out['source'] === 'default' ) {
				$out['source'] = 'default_tomorrow';
			}
		}

		// [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — relation query detection.
		$relation_query_detected = $this->is_relation_query( $query );
		if ( $relation_query_detected ) {
			$out['analysis_mode'] = 'relation_profile';
			$out['relation_query_detected'] = true;
			$out['relation_target_name_hint'] = $this->extract_relation_target_name_hint( $query );
			$out['relation_target_coachee_id'] = 0;
			$out['relation_lenses'] = $this->extract_relation_lenses_from_query( $query );
			$out['relation_requires_transit_sync'] = true;

			if ( (int) $out['num_days'] < 7 ) {
				$out['period'] = 'week';
				$out['num_days'] = 7;
				$out['label'] = '7 ngày tới';
			}
			if ( (int) $out['start_offset'] < 1 ) {
				$out['start_offset'] = 1;
			}
			$out['render_mode'] = 'daily';
		}

		return $out;
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — relation query detector.
	 */
	private function is_relation_query( string $query ): bool {
		$q = trim( $query );
		if ( $q === '' ) {
			return false;
		}
		$q_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );
		return (bool) preg_match( '/\b(?:hồ sơ|ho so|người này|nguoi nay|đối tác|doi tac|có hợp|co hop|hợp không|hop khong|hợp tác|hop tac|kết bạn|ket ban|nhân sự|nhan su|đánh giá mối quan hệ|danh gia moi quan he|compatibility)\b/u', $q_lc );
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — lens mapper.
	 *
	 * @return array<string>
	 */
	private function extract_relation_lenses_from_query( string $query ): array {
		$q = trim( $query );
		if ( $q === '' ) {
			return array( 'work', 'love', 'business', 'hr' );
		}
		$q_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );
		$lenses = array();

		if ( preg_match( '/\b(?:công việc|cong viec|sự nghiệp|su nghiep|công sự|cong su|career)\b/u', $q_lc ) ) {
			$lenses[] = 'work';
		}
		if ( preg_match( '/\b(?:tình cảm|tinh cam|tình duyên|tinh duyen|kết bạn|ket ban|yêu|yeu|love)\b/u', $q_lc ) ) {
			$lenses[] = 'love';
		}
		if ( preg_match( '/\b(?:hợp tác làm ăn|hop tac lam an|đầu tư|dau tu|đồng sáng lập|dong sang lap|business)\b/u', $q_lc ) ) {
			$lenses[] = 'business';
		}
		if ( preg_match( '/\b(?:nhân sự|nhan su|quản lý đội ngũ|quan ly doi ngu|tuyển dụng|tuyen dung|giao việc|giao viec|hr)\b/u', $q_lc ) ) {
			$lenses[] = 'hr';
		}

		if ( empty( $lenses ) ) {
			return array( 'work', 'love', 'business', 'hr' );
		}

		return array_values( array_unique( $lenses ) );
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — light target name hint extractor.
	 */
	private function extract_relation_target_name_hint( string $query ): string {
		$q = trim( $query );
		if ( $q === '' ) {
			return '';
		}

		if ( preg_match( '/(?:với|voi|của|cua|về|ve)\s+([\p{L}][\p{L}\s\.]{1,50})/u', $q, $m ) ) {
			$name = trim( preg_replace( '/\s+/u', ' ', (string) $m[1] ) );
			if ( mb_strlen( $name ) >= 2 && mb_strlen( $name ) <= 50 ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN REL-1 — normalize source marker.
	 */
	private function normalize_relation_source_marker( string $raw ): string {
		$raw = sanitize_key( trim( $raw ) );
		if ( in_array( $raw, array( 'twinbrain_chat', 'zalobot_chat', 'automation' ), true ) ) {
			return $raw;
		}
		return 'twinbrain_chat';
	}

	// [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A12 — rich JSON prompt so LLM returns
	// period + num_days + start_offset + label + render_mode in a single call.
	private function build_classify_system_prompt(): string {
		return "Bạn là classifier ý định thời gian cho chiêm tinh. Đọc tin nhắn user, trả về JSON.\n"
			. "Format: {\"period\":\"<p>\",\"num_days\":<n>,\"start_offset\":<s>,\"label\":\"<l>\",\"render_mode\":\"<m>\"}\n"
			. "\nTrường:\n"
			. "- period: day | week | month | year | 5year\n"
			. "- num_days: số ngày transit cần lấy. \"N ngày tới\" = N. \"N tuần tới\" = N×7. \"nửa tháng\" = 15. \"1 tháng\" = 30.\n"
			. "- start_offset: ngày bắt đầu offset từ hôm nay. hôm nay=0. \"ngày mai\" / \"ngày tới\" / range bắt đầu ngày mai=1. \"ngày kia\" / \"ngày kìa\"=2.\n"
			. "- label: nhãn tiếng Việt ngắn gọn\n"
			. "- render_mode: daily (num_days≤30) | overview (num_days>30)\n"
			. "\nVí dụ:\n"
			. "  \"hôm nay\"        → {\"period\":\"day\",\"num_days\":1,\"start_offset\":0,\"label\":\"Hôm nay\",\"render_mode\":\"daily\"}\n"
			. "  \"ngày mai\"        → {\"period\":\"day\",\"num_days\":1,\"start_offset\":1,\"label\":\"Ngày mai\",\"render_mode\":\"daily\"}\n"
			. "  \"ngày kia\"        → {\"period\":\"day\",\"num_days\":1,\"start_offset\":2,\"label\":\"Ngày kia\",\"render_mode\":\"daily\"}\n"
			. "  \"ngày kìa\"        → {\"period\":\"day\",\"num_days\":1,\"start_offset\":2,\"label\":\"Ngày kìa\",\"render_mode\":\"daily\"}\n"
			. "  \"2 ngày tới\"      → {\"period\":\"day\",\"num_days\":2,\"start_offset\":1,\"label\":\"2 ngày tới\",\"render_mode\":\"daily\"}\n"
			. "  \"3 ngày tới\"      → {\"period\":\"day\",\"num_days\":3,\"start_offset\":1,\"label\":\"3 ngày tới\",\"render_mode\":\"daily\"}\n"
			. "  \"5 ngày tới\"      → {\"period\":\"week\",\"num_days\":5,\"start_offset\":1,\"label\":\"5 ngày tới\",\"render_mode\":\"daily\"}\n"
			. "  \"10 ngày tới\"     → {\"period\":\"week\",\"num_days\":10,\"start_offset\":1,\"label\":\"10 ngày tới\",\"render_mode\":\"daily\"}\n"
			. "  \"nửa tháng tới\"   → {\"period\":\"month\",\"num_days\":15,\"start_offset\":1,\"label\":\"Nửa tháng tới\",\"render_mode\":\"daily\"}\n"
			. "  \"1 tuần tới\"      → {\"period\":\"week\",\"num_days\":7,\"start_offset\":1,\"label\":\"1 tuần tới\",\"render_mode\":\"daily\"}\n"
			. "  \"tuần tới\"        → {\"period\":\"week\",\"num_days\":7,\"start_offset\":1,\"label\":\"7 ngày tới\",\"render_mode\":\"daily\"}\n"
			. "  \"2 tuần tới\"      → {\"period\":\"week\",\"num_days\":14,\"start_offset\":1,\"label\":\"2 tuần tới\",\"render_mode\":\"daily\"}\n"
			. "  \"1 tháng tới\"     → {\"period\":\"month\",\"num_days\":30,\"start_offset\":1,\"label\":\"1 tháng tới\",\"render_mode\":\"overview\"}\n"
			. "  \"tháng tới\"       → {\"period\":\"month\",\"num_days\":30,\"start_offset\":1,\"label\":\"1 tháng tới\",\"render_mode\":\"overview\"}\n"
			. "  \"năm tới\"         → {\"period\":\"year\",\"num_days\":365,\"start_offset\":0,\"label\":\"1 năm tới\",\"render_mode\":\"overview\"}\n"
			. "  không rõ           → {\"period\":\"day\",\"num_days\":1,\"start_offset\":1,\"label\":\"Ngày mai\",\"render_mode\":\"daily\"}\n"
			. "KHÔNG giải thích. KHÔNG markdown. CHỈ JSON.";
	}

	/**
	 * [2026-07-07 Johnny Chu] PHASE-FAA2-TWINBRAIN A12 — parse rich JSON response.
	 * Returns array {period, num_days, start_offset, label, render_mode} or null.
	 * Backward-compat: also accepts plain period token string.
	 */
	private function parse_classify_response( string $raw ): ?array {
		$raw = trim( $raw );
		if ( $raw === '' ) return null;

		// Strip markdown code fences.
		$raw = (string) preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = (string) preg_replace( '/\s*```$/', '', trim( $raw ) );
		$raw = trim( $raw );

		// Try full JSON object {period, num_days, start_offset, label, render_mode}.
		if ( isset( $raw[0] ) && $raw[0] === '{' ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) && isset( $json['period'] ) ) {
				$period = strtolower( trim( (string) ( $json['period'] ?? '' ) ) );
				if ( ! in_array( $period, self::ALLOWED_PERIODS, true ) ) return null;
				$num_days     = max( 1, (int) ( $json['num_days'] ?? 1 ) );
				$start_offset = max( 0, (int) ( $json['start_offset'] ?? 0 ) );
				$label        = (string) ( $json['label'] ?? $this->label_for_period( $period ) );
				$raw_mode     = (string) ( $json['render_mode'] ?? ( $num_days <= 30 ? 'daily' : 'overview' ) );
				$render_mode  = in_array( $raw_mode, array( 'daily', 'overview', 'fallback' ), true )
					? $raw_mode : ( $num_days <= 30 ? 'daily' : 'overview' );
				return array(
					'period'       => $period,
					'num_days'     => $num_days,
					'start_offset' => $start_offset,
					'label'        => $label,
					'render_mode'  => $render_mode,
				);
			}
		}

		// Backward compat: plain period token.
		$raw_lc = strtolower( $raw );
		foreach ( self::ALLOWED_PERIODS as $p ) {
			if ( preg_match( '/\b' . preg_quote( $p, '/' ) . '\b/', $raw_lc ) ) {
				$meta = self::PERIOD_META[ $p ] ?? array();
				return array(
					'period'       => $p,
					'num_days'     => $meta['num_days'] ?? 1,
					'start_offset' => 0,
					'label'        => $this->label_for_period( $p ),
					'render_mode'  => $meta['render_mode'] ?? 'daily',
				);
			}
		}
		return null;
	}

	private function label_for_period( string $period ): string {
		$map = array(
			'day'   => 'Hôm nay',
			'week'  => 'Tuần này',
			'month' => 'Tháng này',
			'year'  => 'Năm nay',
			'5year' => '5 năm tới',
		);
		return isset( $map[ $period ] ) ? $map[ $period ] : 'Hôm nay';
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A16 — detect explicit
	 * temporal/future cue in user query.
	 */
	private function has_temporal_signal_in_query( string $query ): bool {
		$q = trim( $query );
		if ( $q === '' ) {
			return false;
		}
		$q_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );

		if ( preg_match( '/\b\d{1,2}[\/\-]\d{1,2}(?:[\/\-]\d{2,4})?\b/u', $q_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:20\d{2}|19\d{2})\b/u', $q_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b\d+\s*(?:ngày|tuần|tháng|năm)\b/u', $q_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:hôm nay|ngày mai|ngày kia|ngày kìa|tuần này|tuần tới|tháng này|tháng tới|năm nay|năm tới|quý|q1|q2|q3|q4|sắp tới|tương lai|khi nào|bao giờ|timeline|thời gian|giai đoạn)\b/u', $q_lc ) ) {
			return true;
		}

		return false;
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — detect cues that user
	 * explicitly asks a detailed / deep analysis.
	 */
	private function has_deep_analysis_signal_in_query( string $query ): bool {
		$q = trim( $query );
		if ( $q === '' ) {
			return false;
		}
		$q_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );

		if ( preg_match( '/\b(?:chi tiết|kỹ lưỡng|kĩ lưỡng|phân tích sâu|phân tích kỹ|phân tích kĩ|toàn diện|rất sâu|sâu hơn|cụ thể hơn|đầy đủ hơn|tường tận)\b/u', $q_lc ) ) {
			return true;
		}
		if ( preg_match( '/\b(?:cuộc đời|đường đời|vận mệnh|sứ mệnh)\b/u', $q_lc ) ) {
			return true;
		}

		$domains = $this->extract_focus_domains_from_query( $q );
		if ( count( $domains ) >= 2 ) {
			return true;
		}

		return false;
	}

	/**
	 * [2026-07-08 Johnny Chu] PHASE-FAA2-TWINBRAIN A17 — map query to explicit
	 * analysis domains used by final composer depth contract.
	 *
	 * @return array<string>
	 */
	private function extract_focus_domains_from_query( string $query ): array {
		$q = trim( $query );
		if ( $q === '' ) {
			return array();
		}
		$q_lc = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q ) : strtolower( $q );

		$domains = array();
		if ( preg_match( '/\b(?:sự nghiệp|công việc|nghề nghiệp|thăng tiến|việc làm|career)\b/u', $q_lc ) ) {
			$domains[] = 'career';
		}
		if ( preg_match( '/\b(?:tài chính|tiền bạc|thu nhập|đầu tư|tiết kiệm|nợ|finance)\b/u', $q_lc ) ) {
			$domains[] = 'finance';
		}
		if ( preg_match( '/\b(?:tình duyên|tình cảm|hôn nhân|yêu đương|người yêu|vợ chồng|relationship|love)\b/u', $q_lc ) ) {
			$domains[] = 'love';
		}
		if ( preg_match( '/\b(?:gia đình|cha mẹ|con cái|anh chị em|nhà cửa|family)\b/u', $q_lc ) ) {
			$domains[] = 'family';
		}
		if ( preg_match( '/\b(?:cuộc đời|đường đời|vận mệnh|sứ mệnh|life)\b/u', $q_lc ) ) {
			$domains[] = 'life';
		}

		return array_values( array_unique( $domains ) );
	}

	/* =================================================================
	 *  CAP source inference
	 * ================================================================ */

	/**
	 * Inspect passage metadata to derive a single `cap_source` label.
	 * Resolver tags first transit passage with metadata.source.
	 */
	private function infer_cap_source( array $passages ): string {
		foreach ( $passages as $p ) {
			if ( is_array( $p ) && isset( $p['metadata']['source'] ) && $p['metadata']['source'] !== '' ) {
				return (string) $p['metadata']['source'];
			}
		}
		return 'filter';
	}

	/* =================================================================
	 *  Event bus
	 * ================================================================ */

	private function emit( string $event_key, array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try {
				BizCity_Twin_Event_Bus::dispatch( $event_key, $payload );
				return;
			} catch ( \Throwable $e ) { /* fallthrough */ }
		}
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TwinBrain][web-astro][noop-bus] ' . $event_key );
		}
	}

	/* =================================================================
	 *  Coachee name resolution
	 * ================================================================ */

	/**
	 * Extract a person name from the query then look up the coachee in DB.
	 * Uses a single LLM call (Gemini 2.0 Flash) — no regex fallback.
	 *
	 * [2026-06-09 Johnny Chu] R-CH-NS — replaced regex fallback with LLM-only
	 * path. Model explicit: google/gemini-2.0-flash-001. Regex removed entirely.
	 * [2026-06-10 Johnny Chu] HOTFIX — return array {id, name} for step-1 transparency.
	 *
	 * @param string $query Raw user query.
	 * @return array { id: int, name: string }  coachee id and extracted name.
	 */
	private function resolve_coachee_id_from_query( string $query, int $owner_user_id = 0 ): array {
		$name_hint = $this->extract_person_name_llm( $query );
		if ( $name_hint === '' ) {
			return array( 'id' => 0, 'name' => '' );
		}
		$id = $this->db_find_coachee_by_name( $name_hint, $owner_user_id );
		return array( 'id' => $id, 'name' => $name_hint );
	}

	/**
	 * Mini LLM call — extract a Vietnamese person name from an arbitrary query.
	 *
	 * Model : google/gemini-2.0-flash-001 (explicit, cheapest flash in catalog).
	 * Tokens: 20 max (just a short name).
	 * Temp  : 0.0 (deterministic).
	 * Timeout: 5 s.
	 *
	 * Returns the name string, or '' when no name is present / on failure.
	 *
	 * [2026-06-09 Johnny Chu] R-CH-NS — explicit model instead of purpose routing.
	 */
	private function extract_person_name_llm( string $query ): string {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return '';
		}
		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return '';
		}
		$messages = array(
			array(
				'role'    => 'system',
				'content' => "Bạn là name-extractor. Đọc câu hỏi và trả về TÊN RIÊNG của người được nhắc tới.\n"
					. "Quy tắc:\n"
					. "- Trả về họ và tên đầy đủ (ví dụ: \"Kim Thoa\", \"Trần Kim Thoa\").\n"
					. "- Nếu KHÔNG có tên người cụ thể → trả về: NULL\n"
					. "- KHÔNG giải thích. KHÔNG markdown. CHỈ tên hoặc NULL.",
			),
			array(
				'role'    => 'user',
				'content' => $query,
			),
		);
		try {
			$resp = $llm->chat( $messages, array(
				'model'       => 'google/gemini-2.0-flash-001',
				'purpose'     => 'fast',
				'temperature' => 0.0,
				'max_tokens'  => 20,
				'timeout'     => 5,
			) );
			if ( empty( $resp['success'] ) ) {
				return '';
			}
			$raw = trim( (string) ( $resp['message'] ?? '' ) );
			$raw = trim( $raw, '"\'`*_ ' );
			if ( $raw === '' || strtoupper( $raw ) === 'NULL' || strtolower( $raw ) === 'null' ) {
				return '';
			}
			// Reject implausible responses: > 5 words or < 2 chars.
			$words = preg_split( '/\s+/u', $raw );
			if ( ! is_array( $words ) || count( $words ) > 5 || mb_strlen( $raw ) < 2 ) {
				return '';
			}
			return $raw;
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TwinBrain][web-astro][name_extract][exception] ' . $e->getMessage() );
			}
			return '';
		}
	}

	/**
	 * Look up the most-recently-updated coachee whose full_name LIKE %name%.
	 *
	 * @param string $name Name hint to search.
	 * @return int  coachee id or 0 if not found / bccm_tables unavailable.
	 */
	private function db_find_coachee_by_name( string $name, int $owner_user_id = 0 ): int {
		if ( ! function_exists( 'bccm_tables' ) ) {
			return 0;
		}
		global $wpdb;
		$t = bccm_tables();
		if ( empty( $t['profiles'] ) ) {
			return 0;
		}
		$like = '%' . $wpdb->esc_like( trim( $name ) ) . '%';

		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A4 — block cross-owner lookup.
		// Non-admin users are restricted to their own profiles.
		$is_admin = function_exists( 'current_user_can' ) && current_user_can( 'manage_options' );
		if ( ! $is_admin ) {
			if ( $owner_user_id <= 0 ) {
				return 0;
			}
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t['profiles']} WHERE user_id = %d AND full_name LIKE %s ORDER BY updated_at DESC LIMIT 1",
				$owner_user_id,
				$like
			) );
		} else {
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t['profiles']} WHERE full_name LIKE %s ORDER BY updated_at DESC LIMIT 1",
				$like
			) );
		}
		return $id;
	}
}
