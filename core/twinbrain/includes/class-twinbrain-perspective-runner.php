<?php
/**
 * BizCity TwinBrain Perspective Runner — Stage 2.
 *
 * Fan-out parallel sub-agent calls (curl_multi_exec) directly to the
 * bizcity-llm-router gateway (POST /wp-json/bizcity/v1/llm/chat) — one
 * handle per selected notebook. Each handle prompts a small LLM (haiku/flash)
 * with: notebook label + top-N recent passages + user question. Returns a
 * structured { stance, confidence, answer_md, citations } row per notebook.
 *
 * Design notes (PHASE-0.36 sprint .4 / R-MPR-4 / R-GW):
 *   • PARALLEL fan-out — wall time = max(sub) + sync overhead, NOT sum(N).
 *   • Hard per-handle timeout = HARD_TIMEOUT_MS (8s); on timeout we drop the
 *     handle and emit `{stance:'unknown', confidence:0}` for that notebook so
 *     the synthesizer keeps running with N-1 perspectives instead of dying.
 *   • We DO NOT call BizCity_LLM_Client::chat() in a loop — that would be
 *     sequential and blow latency budget. We talk to the gateway endpoint
 *     directly via curl_multi while still respecting R-GW (the only target
 *     URL is the canonical llm-router endpoint, never a model provider).
 *   • Mini-RAG for this sprint = recency-only top-N (LIMIT 5 by id DESC). A
 *     proper cosine ranker over kg_passages.embedding will land in a follow-up
 *     once the embedding column has been backfilled across all blogs.
 *   • stub_answer() is kept as fallback when (a) curl unavailable, (b) gateway
 *     not configured (no API key), or (c) candidates empty — so this class
 *     stays safe in dev/CI without a live router.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinBrain
 * @since      2026-05-10
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Perspective_Runner {

	const HARD_TIMEOUT_S        = 8;
	const HARD_TIMEOUT_MS       = 8000;
	const CONNECT_TIMEOUT_S     = 3;
	const PER_NOTEBOOK_PASSAGES = 5;
	const PASSAGE_TRUNC_CHARS   = 600;
	const SYNTH_TEMPERATURE     = 0.3;
	const MAX_TOKENS            = 600;
	/* PHASE-0.35 / F7.E3 — deeper budget when guru bound (persona
	 * answers must include structured sections — e.g. Tarot: meaning /
	 * upright / reversed / situation / advice). Cap ~500 words ≈ ~1500
	 * tokens; safe for haiku/flash sub-agents. */
	const MAX_TOKENS_GURU       = 1500;
	const PERSONA_PROMPT_TRUNC  = 1200;

	private static $instance = null;
	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Fan-out parallel sub-agent calls; one per candidate notebook.
	 *
	 * @param string $trace_id   Brain turn trace id (event correlation).
	 * @param string $prompt     User prompt.
	 * @param array  $candidates Output of Notebook_Selector::select().
	 * @param array  $opts       PHASE-0.35 / F7.E3 — optional context. Keys:
	 *                           - guru_id  (int)    bound persona id (>0 → deeper budget).
	 *                           - guru_meta(array)  optional cached {slug,name,system_prompt}.
	 * @return array<int,array> Per-notebook perspective answer rows.
	 */
	public function run( string $trace_id, string $prompt, array $candidates, array $opts = [] ): array {
		if ( empty( $candidates ) ) {
			return [];
		}

		/* 2026-05-22 — Founder mandate: TURN OFF per-perspective LLM calls.
		 * The MPR timeline only needs to FIND sources per notebook (similarity
		 * search). The Final Composer (master brain) does the final reasoning
		 * with both internal `[nb:X/pY]` and web `[web:N#URL]` citations.
		 * To re-enable LLM fan-out, register:
		 *   add_filter( 'bizcity_twinbrain_perspectives_skip_llm', '__return_false' );
		 */
		$skip_llm = (bool) apply_filters( 'bizcity_twinbrain_perspectives_skip_llm', true, $trace_id, $prompt, $candidates, $opts );
		if ( $skip_llm ) {
			return $this->run_sources_only( $trace_id, $prompt, $candidates, $opts );
		}

		$guru_id   = isset( $opts['guru_id'] ) ? (int) $opts['guru_id'] : 0;
		$guru_meta = $this->resolve_guru_meta( $guru_id, isset( $opts['guru_meta'] ) ? (array) $opts['guru_meta'] : array() );
		$max_tok   = ( $guru_id > 0 ) ? self::MAX_TOKENS_GURU : self::MAX_TOKENS;

		// Capability + config gates — fall back to stub on any miss so the
		// turn still completes (synthesizer will see all 'unknown' stances
		// and surface a degraded answer instead of a 500).
		$can_parallel = function_exists( 'curl_multi_init' )
			&& function_exists( 'curl_multi_add_handle' )
			&& function_exists( 'curl_multi_exec' )
			&& $this->gateway_ready();

		if ( ! $can_parallel ) {
			return $this->run_stub_fallback( $trace_id, $prompt, $candidates, 'gateway_unavailable' );
		}

		$turn_start  = microtime( true );
		$endpoint    = $this->gateway_endpoint();
		$api_key     = $this->gateway_api_key();
		$model       = $this->resolve_sub_agent_model();
		$site_url    = home_url();

		// Build one curl handle per candidate. We capture per-handle metadata
		// in $handles[] so the result loop can correlate response → notebook.
		$mh      = curl_multi_init();
		$handles = [];

		foreach ( $candidates as $cand ) {
			$nb_id = (int) ( $cand['notebook_id'] ?? 0 );
			if ( $nb_id <= 0 ) {
				continue;
			}
			$label    = (string) ( $cand['label'] ?? '' );
			$passages = $this->fetch_recent_passages( $nb_id, self::PER_NOTEBOOK_PASSAGES );
			$messages = $this->build_messages( $label, $prompt, $passages, $guru_meta );

			$body = wp_json_encode( [
				'model'       => $model,
				'messages'    => $messages,
				'temperature' => self::SYNTH_TEMPERATURE,
				'max_tokens'  => $max_tok,
				'purpose'     => 'twinbrain_perspective',
				'site_url'    => $site_url,
				'timeout'     => self::HARD_TIMEOUT_S,
			] );

			$ch = curl_init( $endpoint );
			curl_setopt_array( $ch, [
				CURLOPT_POST           => true,
				CURLOPT_HTTPHEADER     => [
					'Content-Type: application/json',
					'Authorization: Bearer ' . $api_key,
					'X-Site-URL: ' . $site_url,
					'X-Twin-Trace-Id: ' . $trace_id,
				],
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT_MS     => self::HARD_TIMEOUT_MS,
				CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
				CURLOPT_FOLLOWLOCATION => false,
			] );
			curl_multi_add_handle( $mh, $ch );

			$handles[] = [
				'ch'        => $ch,
				'notebook'  => $cand,
				'nb_id'     => $nb_id,
				'label'     => $label,
				'passages'  => $passages,
				'started'   => microtime( true ),
			];
		}

		if ( empty( $handles ) ) {
			curl_multi_close( $mh );
			return [];
		}

		// Drive the multi-handle until all requests complete or every one
		// has hit CURLOPT_TIMEOUT_MS. curl_multi_select() blocks until at
		// least one handle has activity; we cap each iteration at 1s so a
		// dead select() can't hang past the global budget.
		$still_running = null;
		do {
			$status = curl_multi_exec( $mh, $still_running );
			if ( $still_running ) {
				curl_multi_select( $mh, 1.0 );
			}
		} while ( $still_running > 0 && $status === CURLM_OK );

		// Drain results, build per-notebook perspective rows.
		$answers = [];
		foreach ( $handles as $h ) {
			$ch       = $h['ch'];
			$nb_id    = $h['nb_id'];
			$cand     = $h['notebook'];
			$started  = $h['started'];
			$err_no   = curl_errno( $ch );
			$http     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
			$raw_body = ( $err_no === 0 ) ? (string) curl_multi_getcontent( $ch ) : '';
			$ms       = (int) round( ( microtime( true ) - $started ) * 1000 );

			curl_multi_remove_handle( $mh, $ch );
			curl_close( $ch );

			$parsed = $this->parse_gateway_response( $raw_body, $http, $err_no );

			$payload = [
				'trace_id'    => $trace_id,
				'notebook_id' => $nb_id,
				'label'       => (string) $h['label'],
				'stance'      => (string) $parsed['stance'],
				'confidence'  => (float)  $parsed['confidence'],
				'answer_md'   => (string) $parsed['answer_md'],
				'citations'   => (array)  $parsed['citations'],
				'tokens'      => (int)    $parsed['tokens'],
				'ms'          => $ms,
				'http_status' => $http,
				'error'       => $parsed['error'],
				'model'       => $model,
				'reason'      => (string) ( $cand['reason'] ?? '' ),
			];

			$this->emit( 'brain_perspective_answer', $payload );
			$answers[] = $payload;
		}

		curl_multi_close( $mh );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$wall_ms = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$ok      = count( array_filter( $answers, function ( $a ) { return $a['stance'] !== 'unknown' || $a['confidence'] > 0; } ) );
			error_log( sprintf( '[TwinBrain][runner] trace=%s K=%d ok=%d wall=%dms', $trace_id, count( $handles ), $ok, $wall_ms ) );
		}

		return $answers;
	}

	/* =================================================================
	 *  Prompt construction
	 * ================================================================ */

	private function build_messages( string $label, string $prompt, array $passages, array $guru_meta = array() ): array {
		$context = '';
		foreach ( $passages as $i => $p ) {
			$snippet = mb_substr( (string) $p['content'], 0, self::PASSAGE_TRUNC_CHARS );
			$context .= sprintf( "[nb:%d/p%d] %s\n\n", (int) $p['notebook_id'], (int) $p['id'], trim( $snippet ) );
		}
		if ( $context === '' ) {
			$context = '_(notebook has no indexed passages — answer from label/topic alone)_';
		}

		$nickname = $label !== '' ? $label : ( 'Notebook' );

		/* PHASE-0.35 / F7.E3 — when guru bound, prepend persona system
		 * prompt and lift word cap to ≤500 so persona-rich domains
		 * (Tarot, coaching, legal) can deliver the structured framing
		 * the persona was authored for. Without this, every perspective
		 * gets the same generic <=200 word cap → Tarot answers feel anaemic.
		 */
		$has_guru   = ! empty( $guru_meta['system_prompt'] );
		$word_cap   = $has_guru ? 500 : 200;
		$guru_block = '';
		if ( $has_guru ) {
			$persona = (string) $guru_meta['system_prompt'];
			if ( mb_strlen( $persona ) > self::PERSONA_PROMPT_TRUNC ) {
				$persona = mb_substr( $persona, 0, self::PERSONA_PROMPT_TRUNC ) . "…";
			}
			$gname = (string) ( $guru_meta['name'] ?? '' );
			$gslug = (string) ( $guru_meta['slug'] ?? '' );
			$guru_block = "\n\nVAI TRÒ PERSONA (Twin Guru @{$gslug} — {$gname}):\n{$persona}\n\nKHI VAI TRÒ NÀY ĐƯỢC KÍCH HOẠT:\n- Bám sát khung cấu trúc persona quy định (ví dụ Tarot: ý nghĩa lá bài · thuận · nghịch · tình huống áp dụng · lời khuyên cuối).\n- Khi context có passage → trích citation [nb:ID/pID]; khi không → dùng tri thức persona mô tả rõ \"theo persona @{$gslug}\".";
		}

		$system = <<<SYS
Bạn là chuyên gia "{$nickname}" — chỉ nhìn vấn đề qua lăng kính của notebook này.
Trả lời chỉ dựa trên CONTEXT bên dưới + tri thức chung của vai trò "{$nickname}".
KHÔNG được vờ trung lập, KHÔNG hedge — phải có lập trường rõ ràng.{$guru_block}

ĐẦU RA BẮT BUỘC theo format chính xác:
STANCE: yes|no|conditional|unknown
CONFIDENCE: 0.0-1.0
ANSWER:
<≤{$word_cap} từ markdown — lý do từ góc lăng kính "{$nickname}", trích citation [nb:ID/pID] khi dùng passage>
CAVEATS:
<1 dòng: góc nhìn này KHÔNG thấy điều gì>
SYS;

		$user = "CONTEXT (notebook \"{$nickname}\"):\n\n{$context}\n\nCÂU HỎI:\n{$prompt}";

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user ],
		];
	}

	/**
	 * PHASE-0.35 / F7.E3 — resolve persona meta for the bound guru. Cheap
	 * single read against `bizcity_characters` (cached upstream by the
	 * Knowledge Database). Returns empty array when guru_id <= 0 or row
	 * missing so callers can treat it as "no persona context".
	 */
	private function resolve_guru_meta( int $guru_id, array $cached = array() ): array {
		if ( $guru_id <= 0 ) return array();
		if ( ! empty( $cached['system_prompt'] ) ) return $cached;
		if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) return array();
		$row = BizCity_Knowledge_Database::instance()->get_character( $guru_id );
		if ( ! $row ) return array();
		return array(
			'slug'          => isset( $row->slug )          ? (string) $row->slug          : ( isset( $row['slug'] )          ? (string) $row['slug']          : '' ),
			'name'          => isset( $row->name )          ? (string) $row->name          : ( isset( $row['name'] )          ? (string) $row['name']          : '' ),
			'system_prompt' => isset( $row->system_prompt ) ? (string) $row->system_prompt : ( isset( $row['system_prompt'] ) ? (string) $row['system_prompt'] : '' ),
		);
	}

	/**
	 * Parse the structured STANCE/CONFIDENCE/ANSWER block out of the LLM reply.
	 * Returns a normalized row even when parsing fails.
	 */
	private function parse_gateway_response( string $raw_body, int $http, int $err_no ): array {
		$out = [
			'stance'     => 'unknown',
			'confidence' => 0.0,
			'answer_md'  => '',
			'citations'  => [],
			'tokens'     => 0,
			'error'      => '',
		];

		if ( $err_no !== 0 ) {
			$out['error'] = 'curl_errno_' . $err_no;
			return $out;
		}
		if ( $http < 200 || $http >= 300 ) {
			$out['error'] = 'http_' . $http;
			return $out;
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			$out['error'] = 'invalid_json';
			return $out;
		}
		if ( empty( $decoded['success'] ) ) {
			$out['error'] = (string) ( $decoded['error'] ?? $decoded['message'] ?? 'gateway_failure' );
			return $out;
		}

		$message = (string) ( $decoded['message'] ?? '' );
		$out['tokens'] = (int) ( $decoded['usage']['total_tokens'] ?? 0 );

		// Extract STANCE / CONFIDENCE / ANSWER blocks. Be permissive on
		// whitespace/case — small models occasionally drift on format.
		if ( preg_match( '/STANCE\s*:\s*(yes|no|conditional|unknown)/i', $message, $m ) ) {
			$out['stance'] = strtolower( $m[1] );
		}
		if ( preg_match( '/CONFIDENCE\s*:\s*([0-9]*\.?[0-9]+)/i', $message, $m ) ) {
			$out['confidence'] = max( 0.0, min( 1.0, (float) $m[1] ) );
		}
		if ( preg_match( '/ANSWER\s*:\s*(.+?)(?:\n\s*CAVEATS\s*:|\z)/is', $message, $m ) ) {
			$out['answer_md'] = trim( $m[1] );
		} else {
			// Fallback: use the full message minus the header lines.
			$out['answer_md'] = trim( preg_replace( '/^(STANCE|CONFIDENCE)\s*:.+$/im', '', $message ) );
		}

		// Citations live inline as `[nb:17/p3]` tokens — extract for index.
		if ( preg_match_all( '/\[nb:(\d+)\/p(\d+)\]/', $out['answer_md'], $cites ) ) {
			foreach ( $cites[0] as $i => $token ) {
				$out['citations'][] = [
					'token'       => $token,
					'kind'        => 'nb',
					'notebook_id' => (int) $cites[1][ $i ],
					'passage_id'  => (int) $cites[2][ $i ],
				];
			}
		}

		return $out;
	}

	/* =================================================================
	 *  Sources-only mode (founder mandate 2026-05-22)
	 * ================================================================
	 * Skip per-notebook LLM call. For each candidate notebook:
	 *   1) Run similarity search (vector cosine via KG Retriever) over the
	 *      user prompt to pull top-N matching passages from THIS notebook.
	 *   2) Pack the passage snippets directly into `answer_md` with inline
	 *      `[nb:X/pY]` citation tokens — the Final Composer will reason over
	 *      them alongside web sources to write the final user-facing answer.
	 *   3) Stance is set to 'sources_only' (synthesizer treats this the same
	 *      as 'unknown' but the citations array is fully populated).
	 */
	private function run_sources_only( string $trace_id, string $prompt, array $candidates, array $opts = array() ): array {
		$answers = [];
		$turn_start = microtime( true );

		// TBR.SEL-LEX (2026-05-22) — receive prompt tokens from runtime;
		// recompute if absent (defensive) so direct callers still work.
		$tokens = isset( $opts['keyword_tokens'] ) ? (array) $opts['keyword_tokens'] : array();
		if ( empty( $tokens ) && class_exists( 'BizCity_TwinBrain_Notebook_Selector' ) ) {
			$tokens = BizCity_TwinBrain_Notebook_Selector::tokenize_for_search( $prompt );
		}

		foreach ( $candidates as $cand ) {
			$nb_id = (int) ( $cand['notebook_id'] ?? 0 );
			if ( $nb_id <= 0 ) continue;

			$started   = microtime( true );
			$passages  = $this->fetch_passages( $nb_id, $prompt, self::PER_NOTEBOOK_PASSAGES );

			// TBR.SEL-LEX — when retriever returns nothing (no embedding /
			// notebook empty), try keyword-LIKE pass to grab any passages
			// containing user tokens. Better than empty perspective.
			if ( empty( $passages ) && ! empty( $tokens ) ) {
				$passages = $this->fetch_passages_by_keyword( $nb_id, $tokens, self::PER_NOTEBOOK_PASSAGES );
			}

			// TBR.SEL-LEX — rerank: passages matching more tokens go first.
			// Tag each passage with matched_tokens (FE highlights via <mark>).
			if ( ! empty( $tokens ) ) {
				$passages = $this->annotate_and_rerank_passages( $passages, $tokens );
			}

			$ms = (int) round( ( microtime( true ) - $started ) * 1000 );

			$citations = [];
			$lines     = [];
			foreach ( $passages as $p ) {
				$pid = (int) ( $p['id'] ?? 0 );
				if ( $pid <= 0 ) continue;
				$body  = trim( (string) ( $p['content'] ?? '' ) );
				$snip  = mb_substr( $body, 0, self::PASSAGE_TRUNC_CHARS );
				$token = sprintf( '[nb:%d/p%d]', $nb_id, $pid );
				$lines[] = $token . ' ' . $snip;
				$citations[] = [
					'token'          => $token,
					'kind'           => 'nb',
					'notebook_id'    => $nb_id,
					'passage_id'     => $pid,
					'matched_tokens' => isset( $p['matched_tokens'] ) ? (array) $p['matched_tokens'] : array(),
				];
			}

			$has_hits  = ! empty( $lines );
			$answer_md = $has_hits
				? implode( "\n\n", $lines )
				: '_(notebook không có passage nào khớp từ khóa câu hỏi)_';

			$payload = [
				'trace_id'       => $trace_id,
				'notebook_id'    => $nb_id,
				'label'          => (string) ( $cand['label'] ?? '' ),
				'stance'         => $has_hits ? 'sources_only' : 'unknown',
				'confidence'     => $has_hits ? 1.0 : 0.0,
				'answer_md'      => $answer_md,
				'citations'      => $citations,
				'keyword_tokens' => $tokens,
				'tokens'         => 0,
				'ms'             => $ms,
				'http_status'    => 0,
				'error'          => '',
				'model'          => 'sources-only',
				'reason'         => (string) ( $cand['reason'] ?? '' ),
			];

			$this->emit( 'brain_perspective_answer', $payload );
			$answers[] = $payload;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$wall_ms = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$hits    = count( array_filter( $answers, static function ( $a ) { return $a['stance'] === 'sources_only'; } ) );
			error_log( sprintf( '[TwinBrain][runner][sources-only] trace=%s K=%d hits=%d tokens=%d wall=%dms',
				$trace_id, count( $candidates ), $hits, count( $tokens ), $wall_ms ) );
		}

		return $answers;
	}

	/**
	 * TBR.SEL-LEX + R-TB-HYDRATE (2026-05-27) — keyword fallback per notebook
	 * when vector retriever returns nothing.
	 *
	 * PRE-FIX: `WHERE content LIKE %s` on raw DB column. Broken under R-VFS
	 * v2 because `bizcity_kg_passages.content` is NULL/empty when storage_ver=2
	 * (body lives in `.bin` shard). LIKE matched 0 rows even when the .md
	 * shard contained the keyword → `matched 0/Y` on every turn → Final
	 * Composer fell back to web-only answers.
	 *
	 * POST-FIX: overfetch recent rows (with shard cols) → hydrate body via
	 * `Content_Router::hydrate_passages()` → filter in PHP via `mb_stripos`.
	 * Slower than LIKE but the ONLY correct path under R-VFS v2.
	 *
	 * See `docs/TWINBRAIN-RULE-PASSAGE-HYDRATION.md` §2b.
	 */
	private function fetch_passages_by_keyword( int $notebook_id, array $tokens, int $limit ): array {
		if ( empty( $tokens ) ) return array();

		// Overfetch hydrated rows from recency window. Cap at 60 to keep
		// shard reads bounded (Content_Router caches per-passage body so a
		// follow-up turn on the same notebook stays cheap).
		$overfetch_cap = max( 30, min( 60, $limit * 6 ) );
		$rows = $this->fetch_recent_passages( $notebook_id, $overfetch_cap );
		if ( empty( $rows ) ) return array();

		$matched   = array();
		$want_keep = max( 1, $limit * 3 ); // keep headroom for rerank
		foreach ( $rows as $r ) {
			$body = (string) ( $r['content'] ?? '' );
			if ( $body === '' ) continue;
			foreach ( $tokens as $tok ) {
				$pos = function_exists( 'mb_stripos' ) ? mb_stripos( $body, $tok ) : stripos( $body, $tok );
				if ( $pos !== false ) { $matched[] = $r; break; }
			}
			if ( count( $matched ) >= $want_keep ) break;
		}
		return $matched;
	}

	/**
	 * TBR.SEL-LEX — tag passages with matched_tokens + sort: higher overlap
	 * first, ties broken by passage id DESC. Tokens compared case-insensitive
	 * via mb_stripos.
	 */
	private function annotate_and_rerank_passages( array $passages, array $tokens ): array {
		if ( empty( $passages ) || empty( $tokens ) ) return $passages;
		foreach ( $passages as &$p ) {
			$body    = (string) ( $p['content'] ?? '' );
			$matched = array();
			foreach ( $tokens as $tok ) {
				$pos = function_exists( 'mb_stripos' ) ? mb_stripos( $body, $tok ) : stripos( $body, $tok );
				if ( $pos !== false ) $matched[] = $tok;
			}
			$p['matched_tokens'] = $matched;
			$p['_match_score']   = count( $matched );
		}
		unset( $p );
		usort( $passages, static function ( $a, $b ) {
			$diff = ( (int) ( $b['_match_score'] ?? 0 ) ) - ( (int) ( $a['_match_score'] ?? 0 ) );
			if ( $diff !== 0 ) return $diff;
			return ( (int) ( $b['id'] ?? 0 ) ) - ( (int) ( $a['id'] ?? 0 ) );
		} );
		foreach ( $passages as &$p ) { unset( $p['_match_score'] ); }
		unset( $p );
		return $passages;
	}

	/**
	 * Vector similarity search per notebook, with recency fallback.
	 *
	 * Tries `BizCity_KG_Retriever::search()` first (embedding cosine via
	 * `BizCity_KG_Vector_File_Store`; auto-falls back to keyword inside the
	 * retriever if the query embedding call fails). If retriever class is
	 * unavailable or returns nothing, falls back to recency LIMIT 5.
	 */
	private function fetch_passages( int $notebook_id, string $prompt, int $limit ): array {
		$prompt = trim( $prompt );
		if ( $prompt !== '' && class_exists( 'BizCity_KG_Retriever' ) ) {
			try {
				$out = BizCity_KG_Retriever::instance()->search( $notebook_id, $prompt, $limit );
				$hits = is_array( $out ) ? ( $out['results'] ?? [] ) : [];
				if ( ! empty( $hits ) ) {
					return array_map( static function ( $h ) use ( $notebook_id ) {
						return [
							'id'          => (int) ( $h['passage_id'] ?? $h['id'] ?? 0 ),
							'notebook_id' => $notebook_id,
							'content'     => (string) ( $h['snippet'] ?? $h['content'] ?? '' ),
						];
					}, $hits );
				}
			} catch ( \Throwable $e ) {
				/* fall through to recency below */
			}
		}
		return $this->fetch_recent_passages( $notebook_id, $limit );
	}

	/* =================================================================
	 *  Mini-RAG (recency-only for sprint .4; cosine in follow-up)
	 * ================================================================ */

	/**
	 * Recency-ordered passages for a notebook, with body hydrated from the
	 * `.bin` shard (R-VFS v2 / R-TB-HYDRATE).
	 *
	 * SELECT MUST include shard cols (`storage_ver, file_shard, file_offset,
	 * file_length`) so `BizCity_KG_Content_Router::hydrate_passages()` can
	 * resolve body for v2 rows. Without hydration the `content` column is
	 * NULL/empty under v2 → every downstream consumer (matched_tokens tagger,
	 * answer_md builder, Final Composer) sees empty bodies → final answer
	 * collapses to web-only.
	 *
	 * See `docs/TWINBRAIN-RULE-PASSAGE-HYDRATION.md` §2a.
	 */
	private function fetch_recent_passages( int $notebook_id, int $limit ): array {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) return [];
		$db = BizCity_KG_Database::instance();
		if ( ! method_exists( $db, 'tbl_passages' ) ) return [];
		$tbl = $db->tbl_passages();
		$prev = $wpdb->suppress_errors( true );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, notebook_id, content,
			        storage_ver, file_shard, file_offset, file_length
			 FROM {$tbl}
			 WHERE notebook_id = %d
			 ORDER BY id DESC
			 LIMIT %d",
			$notebook_id, max( 1, $limit )
		), ARRAY_A );
		$wpdb->suppress_errors( $prev );
		if ( empty( $rows ) || ! is_array( $rows ) ) return [];
		if ( class_exists( 'BizCity_KG_Content_Router' ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $rows );
		}
		return $rows;
	}

	/* =================================================================
	 *  Gateway helpers (R-GW)
	 * ================================================================ */

	private function gateway_ready(): bool {
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) return false;
		try {
			return BizCity_LLM_Client::instance()->is_ready();
		} catch ( \Throwable $e ) { return false; }
	}

	private function gateway_endpoint(): string {
		$base = class_exists( 'BizCity_LLM_Client' )
			? BizCity_LLM_Client::instance()->get_gateway_url()
			: 'https://bizcity.vn';
		return rtrim( $base, '/' ) . '/wp-json/bizcity/v1/llm/chat';
	}

	private function gateway_api_key(): string {
		return class_exists( 'BizCity_LLM_Client' )
			? BizCity_LLM_Client::instance()->get_api_key()
			: '';
	}

	private function resolve_sub_agent_model(): string {
		// Allow site override via filter so ops can swap haiku→flash without
		// redeploy. Default = whatever the LLM client maps to the 'chat' purpose.
		$model = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$model = BizCity_LLM_Client::instance()->get_model( 'chat' );
		}
		/**
		 * Filter: bizcity_twinbrain_sub_agent_model
		 * @param string $model The resolved sub-agent model id.
		 */
		return (string) apply_filters( 'bizcity_twinbrain_sub_agent_model', $model );
	}

	/* =================================================================
	 *  Fallback (no curl / no key / dev mode)
	 * ================================================================ */

	private function run_stub_fallback( string $trace_id, string $prompt, array $candidates, string $reason ): array {
		$answers = [];
		foreach ( $candidates as $cand ) {
			$nb_id = (int) ( $cand['notebook_id'] ?? 0 );
			$start = microtime( true );
			$answer = $this->stub_answer( $nb_id, $prompt );
			$payload = [
				'trace_id'    => $trace_id,
				'notebook_id' => $nb_id,
				'label'       => (string) ( $cand['label'] ?? '' ),
				'stance'      => (string) $answer['stance'],
				'confidence'  => (float)  $answer['confidence'],
				'answer_md'   => (string) $answer['answer_md'],
				'citations'   => (array)  $answer['citations'],
				'tokens'      => 0,
				'ms'          => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'http_status' => 0,
				'error'       => 'stub_' . $reason,
				'model'       => 'stub',
				'reason'      => (string) ( $cand['reason'] ?? '' ),
			];
			$this->emit( 'brain_perspective_answer', $payload );
			$answers[] = $payload;
		}
		return $answers;
	}

	private function stub_answer( int $nb_id, string $prompt ): array {
		return [
			'stance'     => 'unknown',
			'confidence' => 0.0,
			'answer_md'  => sprintf(
				'_TwinBrain stub fallback for notebook #%d (gateway unavailable). Configure bizcity-llm-router to enable real perspectives._',
				$nb_id
			),
			'citations'  => [],
			'tokens'     => 0,
		];
	}

	private function emit( string $event_key, array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) {
			try { BizCity_Twin_Event_Bus::dispatch( $event_key, $payload ); return; }
			catch ( \Throwable $e ) { /* fallthrough */ }
		}
		error_log( '[TwinBrain][noop-bus] ' . $event_key );
	}
}
