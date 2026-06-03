<?php
/**
 * TwinBrain — Memory Writer (Layer 4.7 — Wave 2.8 TBR.MEM-4).
 *
 * Persists user "remember this" requests detected in the prompt to the
 * `bizcity_memory_users` table via BizCity_User_Memory. MVP scope = Mode 1
 * (deterministic regex on VN/EN explicit phrasing). Modes 2 (LLM extractor)
 * + 3 (MemGPT function-call) deferred to MEM-5/6.
 *
 * Idempotent per trace_id — Runtime caches the dispatch outcome in a static
 * map so re-emits don't double-insert.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-22 (Phase 0.36-UNIFIED Wave 2.8 TBR.MEM-4)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Memory_Writer {

	const EXPLICIT_SCORE     = 80;
	const EXTRACTED_SCORE    = 55;
	const LLM_MIN_TOTAL_CHARS = 80;   // prompt+answer must be ≥ this to invoke LLM (gate per doc §2.8)
	const LLM_MAX_INPUT_CHARS = 6000; // truncate combined transcript fed to extractor
	const LLM_DEDUPE_TTL      = 86400; // 24h transient TTL
	const LLM_MAX_MEMORIES    = 6;    // hard cap rows persisted per turn

	/** @var self|null */
	private static $instance = null;

	/** @var array<string,bool> trace_id → dispatched */
	private static $seen = [];

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Extract memory phrases from the prompt+answer and persist.
	 *
	 * Pipeline:
	 *   1. Mode 1 — deterministic regex on explicit "hãy nhớ ..." / "remember ..."
	 *      → tier=explicit, score=80.
	 *   2. Mode 2 — LLM extractor (purpose=fast, nano model) when prompt+answer
	 *      is long enough AND cost-guard allows AND not deduped in last 24h.
	 *      Returns implicit preferences/identity/goals → tier=extracted, score=55.
	 *      Skipped by passing opts.enable_llm=false (e.g. probe explicit-only).
	 *
	 * @param string $trace_id     Brain turn id (idempotency key).
	 * @param string $prompt       User prompt (raw).
	 * @param string $final_answer Composer answer (Mode 2 only).
	 * @param array  $ctx          { user_id, session_id, enable_llm? bool=true }
	 * @return array { ops:array, persisted:int, mode:string, latency_ms:int }
	 */
	public function extract_and_persist( string $trace_id, string $prompt, string $final_answer, array $ctx = [] ): array {
		$t0 = microtime( true );
		if ( $trace_id !== '' && isset( self::$seen[ $trace_id ] ) ) {
			return [ 'ops' => [], 'persisted' => 0, 'mode' => 'cached', 'latency_ms' => 0 ];
		}
		if ( $trace_id !== '' ) self::$seen[ $trace_id ] = true;

		$user_id    = (int)    ( $ctx['user_id']    ?? get_current_user_id() );
		$session_id = (string) ( $ctx['session_id'] ?? '' );
		$enable_llm = ! isset( $ctx['enable_llm'] ) || (bool) $ctx['enable_llm'];

		// Guest with no session — skip entirely (can't persist).
		if ( $user_id <= 0 && $session_id === '' ) {
			return $this->empty_result( $t0, 'no_owner' );
		}
		if ( ! class_exists( 'BizCity_User_Memory' ) ) {
			return $this->empty_result( $t0, 'class_missing' );
		}

		$mem       = BizCity_User_Memory::instance();
		$ops       = [];
		$persisted = 0;
		$modes     = [];

		// ─── Mode 1 — regex explicit ──────────────────────────────────────
		$candidates = $this->extract_explicit( $prompt );
		if ( ! empty( $candidates ) ) {
			$modes[] = 'regex-mode1';
			foreach ( $candidates as $cand ) {
				$text = (string) $cand['text'];
				if ( $text === '' ) continue;
				$key  = 'explicit:' . md5( mb_strtolower( trim( $text ) ) );
				$res  = $mem->upsert_public( [
					'user_id'        => $user_id,
					'session_id'     => $user_id > 0 ? '' : $session_id,
					'memory_tier'    => 'explicit',
					'memory_type'    => $cand['type'] ?? 'request',
					'memory_key'     => $key,
					'memory_text'    => $text,
					'score'          => self::EXPLICIT_SCORE,
					'metadata'       => wp_json_encode( [
						'source'   => 'twinbrain.writer.mode1',
						'trace_id' => $trace_id,
						'match'    => $cand['match'] ?? '',
					] ),
				] );
				$ops[] = [
					'op'   => $res ?: 'noop',
					'mode' => 'mode1',
					'type' => $cand['type'] ?? 'request',
					'key'  => $key,
					'text' => mb_substr( $text, 0, 120 ),
				];
				if ( $res === 'insert' || $res === 'update' ) $persisted++;
			}
		}

		// ─── Mode 2 — LLM extractor (implicit preferences) ───────────────
		$llm_status = 'skipped';
		if ( $enable_llm ) {
			$llm_out = $this->extract_with_llm( $trace_id, $prompt, $final_answer, $user_id, $session_id );
			$llm_status = (string) $llm_out['status'];
			if ( $llm_status === 'ok' ) {
				$modes[] = 'llm-mode2';
				foreach ( (array) $llm_out['memories'] as $mm ) {
					$text = (string) ( $mm['text'] ?? '' );
					if ( $text === '' ) continue;
					$type = (string) ( $mm['type'] ?? 'fact' );
					$key  = 'extracted:' . md5( mb_strtolower( trim( $type . '|' . $text ) ) );
					$res  = $mem->upsert_public( [
						'user_id'        => $user_id,
						'session_id'     => $user_id > 0 ? '' : $session_id,
						'memory_tier'    => 'extracted',
						'memory_type'    => $type,
						'memory_key'     => $key,
						'memory_text'    => $text,
						'score'          => (int) ( $mm['score'] ?? self::EXTRACTED_SCORE ),
						'metadata'       => wp_json_encode( [
							'source'   => 'twinbrain.writer.mode2',
							'trace_id' => $trace_id,
							'model'    => (string) ( $llm_out['model'] ?? '' ),
						] ),
					] );
					$ops[] = [
						'op'   => $res ?: 'noop',
						'mode' => 'mode2',
						'type' => $type,
						'key'  => $key,
						'text' => mb_substr( $text, 0, 120 ),
					];
					if ( $res === 'insert' || $res === 'update' ) $persisted++;
				}
			}
		} else {
			$llm_status = 'disabled';
		}

		if ( empty( $modes ) && empty( $ops ) ) {
			return $this->empty_result( $t0, 'no_match', $llm_status );
		}

		return [
			'ops'        => $ops,
			'persisted'  => $persisted,
			'mode'       => implode( '+', $modes ?: [ 'none' ] ),
			'llm_status' => $llm_status,
			'latency_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		];
	}

	/* =================================================================
	 *  Mode 1 — deterministic regex on VN + EN explicit phrasing.
	 * ================================================================ */

	private function extract_explicit( string $prompt ): array {
		$prompt = trim( $prompt );
		if ( $prompt === '' ) return [];

		// Patterns map: each capture group #1 = the actual fact to remember.
		// Anchored on common VN explicit triggers + EN "remember"/"please remember".
		$patterns = [
			// VN: "hãy nhớ X" / "ghi nhớ X" / "nhớ giúp X" / "lưu lại X"
			'/(?:^|[\.\!\?,;]\s*)(?:h[ãa]y\s+|l[àa]m\s+ơn\s+)?(?:ghi\s+nhớ|nhớ\s+giúp|nhớ\s+rằng|nhớ|lưu\s+lại|lưu\s+ý)\s*(?:l[àa]\s+|r[ằa]ng\s+|:\s*)?([^\.\!\?\n]{6,400})/iu',
			// EN: "remember that ..." / "please remember ..."
			'/(?:please\s+)?remember(?:\s+that)?[:,]?\s+([^\.\!\?\n]{6,400})/i',
		];

		$out  = [];
		$seen = [];
		foreach ( $patterns as $re ) {
			if ( ! preg_match_all( $re, $prompt, $m ) ) continue;
			foreach ( $m[1] as $i => $hit ) {
				$text = $this->sanitize( $hit );
				if ( $text === '' ) continue;
				$dedup = mb_strtolower( $text );
				if ( isset( $seen[ $dedup ] ) ) continue;
				$seen[ $dedup ] = true;
				$out[] = [
					'text'  => $text,
					'type'  => $this->guess_type( $text ),
					'match' => trim( $m[0][ $i ] ),
				];
			}
		}
		return $out;
	}

	private function sanitize( string $hit ): string {
		$hit = trim( wp_strip_all_tags( $hit ) );
		$hit = preg_replace( '/\s+/u', ' ', $hit );
		// Strip trailing connector words / dangling punctuation.
		$hit = preg_replace( '/[\s,;\.\!\?]+$/u', '', $hit );
		if ( mb_strlen( $hit ) < 6 ) return '';
		return $hit;
	}

	private function guess_type( string $text ): string {
		$l = mb_strtolower( $text, 'UTF-8' );
		if ( preg_match( '/\b(tôi|mình|em|anh|chị|i\s+am|my\s+name)\b/u', $l ) ) return 'identity';
		if ( preg_match( '/\b(thích|prefer|yêu|love|ghét|hate|ưu tiên)\b/u', $l ) ) return 'preference';
		if ( preg_match( '/\b(mục tiêu|muốn|goal|kpi|đạt|đích)\b/u', $l ) )       return 'goal';
		if ( preg_match( '/\b(xưng|gọi|address|tone|giọng)\b/u', $l ) )            return 'preference';
		return 'request';
	}

	private function empty_result( float $t0, string $reason, string $llm_status = 'skipped' ): array {
		return [
			'ops'        => [],
			'persisted'  => 0,
			'mode'       => $reason,
			'llm_status' => $llm_status,
			'latency_ms' => (int) ( ( microtime( true ) - $t0 ) * 1000 ),
		];
	}

	/* =================================================================
	 *  Mode 2 — LLM extractor (implicit preferences / identity / goals).
	 *
	 *  Gated by:
	 *    • prompt + final_answer length ≥ LLM_MIN_TOTAL_CHARS
	 *    • BizCity_LLM_Client class loaded
	 *    • BizCity_KG_Cost_Guard::can_extract() if class loaded
	 *    • 24h dedupe transient on (user_id, hash(prompt+answer))
	 *    • filter `bizcity_twinbrain_memory_writer_enable_llm` (default true)
	 *
	 *  On success → records usage via cost_guard so it counts toward quota.
	 *  On any failure → returns status flag; caller treats as soft-skip.
	 * ================================================================ */
	private function extract_with_llm( string $trace_id, string $prompt, string $final_answer, int $user_id, string $session_id ): array {
		$default = [ 'status' => 'skipped', 'memories' => [], 'model' => '' ];

		if ( ! apply_filters( 'bizcity_twinbrain_memory_writer_enable_llm', true, $user_id ) ) {
			return [ 'status' => 'disabled_filter' ] + $default;
		}
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return [ 'status' => 'no_llm_client' ] + $default;
		}

		$prompt = trim( $prompt );
		$answer = trim( $final_answer );
		$total  = mb_strlen( $prompt ) + mb_strlen( $answer );
		if ( $total < self::LLM_MIN_TOTAL_CHARS ) {
			return [ 'status' => 'too_short' ] + $default;
		}

		// Dedupe per (user, conversation-hash) for 24h.
		$dedupe_hash = md5( $user_id . '|' . $session_id . '|' . $prompt . '|' . $answer );
		$dedupe_key  = 'bizcity_twb_mw_seen_' . $dedupe_hash;
		if ( get_transient( $dedupe_key ) ) {
			return [ 'status' => 'deduped_24h' ] + $default;
		}

		// Cost guard — reuse KG quota (memory extract counts as 1 passage worth).
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$guard = BizCity_KG_Cost_Guard::instance();
			$ok    = $guard->can_extract( $user_id, 1 );
			if ( is_wp_error( $ok ) ) {
				return [ 'status' => 'cost_blocked:' . $ok->get_error_code() ] + $default;
			}
		}

		$prompt_t  = mb_substr( $prompt, 0, (int) ( self::LLM_MAX_INPUT_CHARS / 2 ) );
		$answer_t  = mb_substr( $answer, 0, (int) ( self::LLM_MAX_INPUT_CHARS / 2 ) );
		$system    = $this->llm_system_prompt();
		$user_msg  = $this->llm_user_prompt( $prompt_t, $answer_t );

		try {
			$client = BizCity_LLM_Client::instance();
			$res    = $client->chat( [
				[ 'role' => 'system', 'content' => $system ],
				[ 'role' => 'user',   'content' => $user_msg ],
			], [
				'purpose'     => apply_filters( 'bizcity_twinbrain_memory_writer_llm_purpose', 'fast' ),
				'temperature' => 0.1,
				'max_tokens'  => 400,
				'response_format' => [ 'type' => 'json_object' ],
			] );
		} catch ( \Throwable $e ) {
			return [ 'status' => 'llm_exception:' . substr( $e->getMessage(), 0, 80 ) ] + $default;
		}

		if ( empty( $res['success'] ) ) {
			return [ 'status' => 'llm_fail:' . ( $res['error'] ?? 'unknown' ) ] + $default;
		}

		$raw = (string) ( $res['message'] ?? '' );
		$memories = $this->parse_llm_output( $raw );
		if ( empty( $memories ) ) {
			set_transient( $dedupe_key, 1, self::LLM_DEDUPE_TTL );
			return [ 'status' => 'empty_extract', 'memories' => [], 'model' => (string) ( $res['model'] ?? '' ) ];
		}

		// Cap rows + record usage.
		$memories = array_slice( $memories, 0, self::LLM_MAX_MEMORIES );

		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			$usage = (array) ( $res['usage'] ?? [] );
			BizCity_KG_Cost_Guard::instance()->record_usage( [
				'user_id'       => $user_id,
				'operation'     => 'extract',
				'input_tokens'  => (int) ( $usage['prompt_tokens']     ?? $usage['input_tokens']  ?? 0 ),
				'output_tokens' => (int) ( $usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0 ),
				'meta'          => [ 'twinbrain_memory_writer' => true, 'trace_id' => $trace_id ],
			] );
		}

		set_transient( $dedupe_key, 1, self::LLM_DEDUPE_TTL );

		return [
			'status'   => 'ok',
			'memories' => $memories,
			'model'    => (string) ( $res['model'] ?? '' ),
		];
	}

	private function llm_system_prompt(): string {
		return "Bạn là Memory Extractor cho một AI trợ lý cá nhân (port Mem0).\n"
		     . "Nhiệm vụ: đọc 1 lượt hội thoại (user prompt + AI answer) và trích những FACT lâu dài về user.\n"
		     . "CHỈ trích thông tin có giá trị nhớ lâu (identity, preference, goal, constraint, habit, relationship, pain). KHÔNG trích câu hỏi, KHÔNG trích sự kiện tạm thời, KHÔNG trích nội dung do AI tự nói về mình.\n"
		     . "Output STRICTLY JSON: {\"memories\":[{\"text\":\"<câu ngắn 1-200 chars, ngôi thứ 3 hoặc 1 đều được>\",\"type\":\"identity|preference|goal|pain|constraint|habit|relationship|fact\",\"score\":50-90}]}\n"
		     . "Nếu không có fact đáng nhớ, trả {\"memories\":[]}. Không thêm prose. Không markdown.";
	}

	private function llm_user_prompt( string $prompt, string $answer ): string {
		return "USER PROMPT:\n" . $prompt . "\n\nAI ANSWER:\n" . $answer . "\n\nTrích memories (JSON):";
	}

	private function parse_llm_output( string $raw ): array {
		$raw = trim( $raw );
		if ( $raw === '' ) return [];
		// Strip code fences if model wrapped despite response_format.
		$raw = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', $raw );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || ! isset( $data['memories'] ) || ! is_array( $data['memories'] ) ) return [];
		$out = [];
		foreach ( $data['memories'] as $row ) {
			if ( ! is_array( $row ) ) continue;
			$text = trim( (string) ( $row['text'] ?? '' ) );
			if ( $text === '' || mb_strlen( $text ) > 220 ) continue;
			$type  = sanitize_key( (string) ( $row['type'] ?? 'fact' ) ) ?: 'fact';
			$score = (int) ( $row['score'] ?? self::EXTRACTED_SCORE );
			$score = max( 30, min( 95, $score ) );
			$out[] = [ 'text' => $text, 'type' => $type, 'score' => $score ];
		}
		return $out;
	}
}
