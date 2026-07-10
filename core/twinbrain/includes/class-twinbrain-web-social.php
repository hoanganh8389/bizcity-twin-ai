<?php
/**
 * TwinBrain — Web Research Social Engine (TBR.W14-be-social).
 *
 * Stage 2.5 social listening path: 1 lần Tavily search bị giới hạn vào danh
 * sách domain mạng xã hội + 1 lần LLM synth → perspective row dạng "social".
 * Port từ `tavily-cookbook-main/.../tools/social_media.py` (Tavily Agent
 * Toolkit reference impl) → wall-time mục tiêu ≤ 4-5s.
 *
 * Pipeline (3 SSE events — tái dùng schema của Web_Quick):
 *   1. web_research_started   { mode:'social', query, platform, time_range }
 *   2. web_search_done        { results:[{url,title,snippet,score,platform}] }
 *   3. web_synthesize_done    { answer_md, citation_count, tokens, ms }
 *
 * Hard rules:
 *   - R-GW   — KHÔNG gọi provider trực tiếp; chỉ qua `BizCity_Search_Client`
 *              + `BizCity_LLM_Client`. Social = Tavily search có
 *              `include_domains` (Tavily đã hỗ trợ end-to-end via
 *              `bizcity-llm-router/class-search-router-proxy`).
 *   - R-EVT-1/2 — emit qua `BizCity_Twin_Event_Bus::dispatch()`.
 *   - R-TG (guru policy) — `bizcity_twinbrain_web_mode_effective` gate
 *              cũng áp dụng cho 'social' (logic generic trong
 *              `BizCity_TwinBrain_Guru_Web_Flag::gate_web_mode`).
 *
 * Output perspective row giống Web_Quick (`stance/confidence/answer_md/
 * citations/tokens/ms`) + extra `platform`/`time_range` để FE render section
 * Social Listening (rose theme) khác Web Quick/Deep (cyan).
 *
 * Reference cookbook:
 *   plugins/bizcoach-pro-research/_library/tavily-cookbook-main/
 *     agent-toolkit/tools/social_media.py (PLATFORM_DOMAINS map)
 *     agent-toolkit/use-cases/claude_sdk/social_media_research.py (system prompt)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-22 (Phase 0.36-UNIFIED TBR.W14)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Social {

	const SKILL_KEY        = 'web_search_social';
	const DEFAULT_MAX      = 8;     // social tends to need wider net than Quick
	const SEARCH_TIMEOUT_S = 8;
	const LLM_TIMEOUT_S    = 14;
	const LLM_TEMPERATURE  = 0.25;
	// [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR — bumped from 700 to allow KEY PATTERNS section.
	const LLM_MAX_TOKENS   = 900;
	const SNIPPET_TRUNC    = 480;
	const TITLE_TRUNC      = 160;
	const DEFAULT_TIME     = 'month'; // matches cookbook default

	/**
	 * Platform → root domain map. Khớp 1-1 với
	 * `tavily-cookbook-main/.../social_media.py::PLATFORM_DOMAINS`.
	 */
	const PLATFORM_DOMAINS = [
		'tiktok'    => 'tiktok.com',
		'facebook'  => 'facebook.com',
		'instagram' => 'instagram.com',
		'reddit'    => 'reddit.com',
		'linkedin'  => 'linkedin.com',
		'x'         => 'x.com',
	];

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Run Social Web pipeline.
	 *
	 * @param string $trace_id Brain turn trace id.
	 * @param string $query    User prompt (raw).
	 * @param array  $opts     Optional. Keys:
	 *                          - max (int)        max search results (default 8, cap 15).
	 *                          - guru_id (int)    bound persona (logged only).
	 *                          - platform (string) 'combined' (default) or one of
	 *                                              tiktok/facebook/instagram/reddit/linkedin/x.
	 *                          - time_range (string) day|week|month|year (default month).
	 * @return array Web perspective row.
	 */
	public function run( string $trace_id, string $query, array $opts = [] ): array {
		$turn_start = microtime( true );
		$query      = trim( $query );
		$max        = max( 1, min( 15, (int) ( $opts['max'] ?? self::DEFAULT_MAX ) ) );
		$platform   = $this->sanitize_platform( (string) ( $opts['platform']   ?? 'combined' ) );
		$time_range = $this->sanitize_time(     (string) ( $opts['time_range'] ?? self::DEFAULT_TIME ) );
		$domains    = $this->domains_for( $platform );

		$row = [
			'mode'           => 'social',
			'platform'       => $platform,
			'time_range'     => $time_range,
			'trace_id'       => $trace_id,
			'query'          => $query,
			'results'        => [],
			'extracts'       => [],
			'iterations'     => [],
			'answer_md'      => '',
			'key_patterns'   => [],   // [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR — structured KEY PATTERNS from synthesis
			'citations'      => [],
			'citation_count' => 0,
			'tokens'         => 0,
			'ms'             => 0,
			'http_status'    => 0,
			'error'          => '',
			'stance'         => 'unknown',
			'confidence'     => 0.0,
			'label'          => 'Social Listening',
		];

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$this->emit( 'web_research_started', [
			'trace_id'   => $trace_id,
			'mode'       => 'social',
			'query'      => $query,
			'max'        => $max,
			'platform'   => $platform,
			'time_range' => $time_range,
		] );

		/* ─── Step 1: search via gateway (include_domains = socials) ───── */
		$search_start = microtime( true );
		$results      = $this->do_search( $query, $max, $domains, $time_range );
		$search_ms    = (int) round( ( microtime( true ) - $search_start ) * 1000 );

		if ( is_wp_error( $results ) ) {
			$row['error'] = 'search_failed:' . $results->get_error_code();
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_search_done', [
				'trace_id' => $trace_id,
				'mode'     => 'social',
				'results'  => [],
				'ms'       => $search_ms,
				'error'    => $row['error'],
			] );
			return $row;
		}

		$row['results'] = $this->normalize_results( $results );

		// [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR — Reddit enrichment + engagement scoring.
		// Pattern: trending_research L1-L2 adapted for social context.
		// Reddit gives upvote signal that editorial social search lacks entirely.
		$reddit_items   = ( $platform === 'combined' ) ? $this->fetch_reddit_enrichment( $query, $time_range ) : [];
		$merged_items   = array_merge( $row['results'], $reddit_items );
		$scored_items   = $this->score_and_ground( $merged_items, $query );
		$row['results'] = $scored_items;

		$this->emit( 'web_search_done', [
			'trace_id' => $trace_id,
			'mode'     => 'social',
			'results'  => $row['results'],
			'ms'       => $search_ms,
		] );

		if ( empty( $row['results'] ) ) {
			$row['answer_md'] = sprintf(
				'_Không tìm thấy bài đăng social cho query này trên platform `%s` (time_range=%s). Hãy thử mở rộng time_range hoặc đổi sang Quick/Deep web._',
				$platform, $time_range
			);
			$row['stance'] = 'unknown';
			$row['ms']     = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_synthesize_done', [
				'trace_id'       => $trace_id,
				'mode'           => 'social',
				'answer_md'      => $row['answer_md'],
				'citation_count' => 0,
				'tokens'         => 0,
				'ms'             => 0,
			] );
			return $row;
		}

		/* ─── Step 2: LLM synthesize (social-tuned prompt) ─────────────── */
		$synth_start = microtime( true );
		$synth       = $this->do_synthesize( $trace_id, $query, $row['results'], $platform );
		$synth_ms    = (int) round( ( microtime( true ) - $synth_start ) * 1000 );

		$row['answer_md']      = (string) $synth['answer_md'];
		$row['key_patterns']   = (array)  ( $synth['key_patterns'] ?? [] ); // [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR
		$row['tokens']         = (int) $synth['tokens'];
		$row['http_status']    = (int) $synth['http_status'];
		$row['citations']      = $this->extract_citations( $row['answer_md'], $row['results'] );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0 ? min( 1.0, 0.4 + 0.12 * $row['citation_count'] ) : 0.3;
		$row['stance']         = $row['citation_count'] > 0 ? 'conditional' : 'unknown';

		if ( ! empty( $synth['error'] ) ) {
			$row['error'] = (string) $synth['error'];
		}

		$row['ms'] = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit( 'web_synthesize_done', [
			'trace_id'       => $trace_id,
			'mode'           => 'social',
			'answer_md'      => $row['answer_md'],
			'citation_count' => $row['citation_count'],
			'tokens'         => $row['tokens'],
			'ms'             => $synth_ms,
			'error'          => $row['error'],
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][web-social] trace=%s platform=%s time=%s results=%d cite=%d tokens=%d wall=%dms (search=%d synth=%d)',
				$trace_id, $platform, $time_range, count( $row['results'] ),
				$row['citation_count'], $row['tokens'],
				$row['ms'], $search_ms, $synth_ms
			) );
		}

		return $row;
	}

	/* =================================================================
	 *  Sanitizers
	 * ================================================================ */

	private function sanitize_platform( string $p ): string {
		$p = strtolower( trim( $p ) );
		if ( $p === '' || $p === 'all' || $p === 'combined' ) return 'combined';
		return isset( self::PLATFORM_DOMAINS[ $p ] ) ? $p : 'combined';
	}

	private function sanitize_time( string $t ): string {
		$t = strtolower( trim( $t ) );
		return in_array( $t, [ 'day', 'week', 'month', 'year' ], true ) ? $t : self::DEFAULT_TIME;
	}

	private function domains_for( string $platform ): array {
		if ( $platform === 'combined' ) return array_values( self::PLATFORM_DOMAINS );
		return [ self::PLATFORM_DOMAINS[ $platform ] ?? '' ];
	}

	/* =================================================================
	 *  Search (R-GW) — include_domains constrains to social platforms
	 * ================================================================ */

	private function do_search( string $query, int $max, array $domains, string $time_range ) {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_Error( 'gateway_missing', 'BizCity_Search_Client not loaded' );
		}
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error( 'gateway_not_ready', 'Search gateway not configured' );
		}
		return $client->search( $query, $max, [
			'search_depth'        => 'basic',
			'include_raw_content' => false,
			'include_domains'     => $domains,
			'time_range'          => $time_range,
			'timeout'             => self::SEARCH_TIMEOUT_S,
		] );
	}

	/**
	 * Normalize gateway output. Phụ thêm `platform` (suy luận từ domain).
	 */
	private function normalize_results( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) {
			$raw = $raw['results'];
		}
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$domain   = (string) ( $r['domain'] ?? $this->host_of( $url ) );
			$platform = $this->platform_of( $domain );
			$out[] = [
				'url'         => $url,
				'title'       => mb_substr( (string) ( $r['title'] ?? $url ), 0, self::TITLE_TRUNC ),
				'snippet'     => mb_substr( (string) ( $r['excerpt'] ?? $r['content'] ?? $r['snippet'] ?? '' ), 0, self::SNIPPET_TRUNC ),
				'score'       => isset( $r['score'] ) ? (float) $r['score'] : 0.0,
				'domain'      => $domain,
				'platform'    => $platform,
				'published_at'=> (string) ( $r['published_at'] ?? '' ),
			];
		}
		return $out;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
	}

	private function platform_of( string $host ): string {
		$host = strtolower( $host );
		foreach ( self::PLATFORM_DOMAINS as $name => $root ) {
			if ( $host === $root || str_ends_with( $host, '.' . $root ) ) return $name;
		}
		return 'web';
	}

	/* =================================================================
	 *  LLM synthesize (R-GW)
	 * ================================================================ */

	private function do_synthesize( string $trace_id, string $query, array $results, string $platform ): array {
		$out = [
			'answer_md'    => '',
			'key_patterns' => [],  // [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR
			'tokens'       => 0,
			'http_status'  => 0,
			'error'        => '',
		];

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			$out['error']     = 'llm_client_missing';
			$out['answer_md'] = $this->build_stub_answer( $results );
			return $out;
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			$out['error']     = 'llm_not_ready';
			$out['answer_md'] = $this->build_stub_answer( $results );
			return $out;
		}

		$messages = $this->build_messages( $query, $results, $platform );
		$model    = $this->resolve_model();

		$endpoint = rtrim( $client->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$api_key  = $client->get_api_key();
		$site_url = home_url();

		$body = wp_json_encode( [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => self::LLM_TEMPERATURE,
			'max_tokens'  => self::LLM_MAX_TOKENS,
			'purpose'     => 'twinbrain_web_social',
			'site_url'    => $site_url,
			'timeout'     => self::LLM_TIMEOUT_S,
		] );

		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Content-Type'    => 'application/json',
				'Authorization'   => 'Bearer ' . $api_key,
				'X-Site-URL'      => $site_url,
				'X-Twin-Trace-Id' => $trace_id,
			],
			'body'    => $body,
			'timeout' => self::LLM_TIMEOUT_S,
		] );

		if ( is_wp_error( $response ) ) {
			$out['error']     = 'http_error:' . $response->get_error_code();
			$out['answer_md'] = $this->build_stub_answer( $results );
			return $out;
		}

		$out['http_status'] = (int) wp_remote_retrieve_response_code( $response );
		$raw                = (string) wp_remote_retrieve_body( $response );
		// [2026-06-24 Johnny Chu] HOTFIX-BOM — strip UTF-8 BOM; bizcity.vn gateway prepends 0xEF BB BF → json_decode null on HTTP 200 → gateway_failure:unknown
		if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) { $raw = substr( $raw, 3 ); }
		$decoded            = json_decode( trim( $raw ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			$out['error']     = 'gateway_failure:' . ( $decoded['error'] ?? $decoded['message'] ?? 'unknown' );
			$out['answer_md'] = $this->build_stub_answer( $results );
			return $out;
		}

		$out['answer_md']    = trim( (string) ( $decoded['message'] ?? '' ) );
		$out['tokens']       = (int) ( $decoded['usage']['total_tokens'] ?? 0 );
		// [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR — extract KEY PATTERNS into structured array.
		$out['key_patterns'] = $this->extract_key_patterns( $out['answer_md'] );

		if ( $out['answer_md'] === '' ) {
			$out['answer_md'] = $this->build_stub_answer( $results );
			$out['error']     = 'empty_response';
		}

		return $out;
	}

	private function resolve_model(): string {
		$model = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			$model = BizCity_LLM_Client::instance()->get_model( 'chat' );
		}
		/** Filter: bizcity_twinbrain_web_social_model */
		return (string) apply_filters( 'bizcity_twinbrain_web_social_model', $model );
	}

	// [2026-06-24 Johnny Chu] PHASE-SOCIAL-MPR — Voice Contract prompt (pattern: trending_research LAWs 1-10).
	private function build_messages( string $query, array $results, string $platform ): array {
		$scope    = $platform === 'combined' ? 'TẤT CẢ social platforms (TikTok, Reddit, Instagram, X, Facebook, LinkedIn)' : strtoupper( $platform );
		$n        = count( $results );
		$evidence = $this->build_evidence_block( $results );

		$system = "Bạn là social listening analyst. "
			. "NHIỆM VỤ: tổng hợp signal từ social posts thành báo cáo ngắn gọn, dễ đọc.\n"
			. "Reddit = honest opinions (upvotes = engagement signal mạnh). TikTok = viral trends. X = real-time reactions. LinkedIn = professional takes.\n\n"
			. "CÁC NGUYÊN TẮC BẮT BUỘC (không được vi phạm):\n"
			. "1. KHÔNG có block 'Nguồn:' / 'Sources:' ở cuối bài.\n"
			. "2. KHÔNG có '## Section headers' trong prose.\n"
			. "3. Mọi trích dẫn PHẢI là inline link [tên ngắn](url) — URL lấy từ [social:N#URL] trong evidence.\n"
			. "4. Mở bài PHẢI là 'Điều tôi tìm hiểu được:' — không đặt tiêu đề tùy ý.\n"
			. "5. Mỗi đoạn văn có mở đầu in đậm (**...** 1-5 từ) thay vì subheading.\n"
			. "6. Post có upvotes nhiều = signal đáng tin hơn editorial — ưu tiên dẫn chứng từ reddit khi có.\n"
			. "7. KHÔNG bịa đặt; chỉ dùng dữ liệu trong evidence.\n"
			. "8. Tiếng Việt tự nhiên; tên riêng/kỹ thuật giữ nguyên.";

		$user = "SOCIAL EVIDENCE (top-{$n}, scope={$scope}):\n\n{$evidence}\n"
			. "CÂU HỎI: {$query}\n\n"
			. "Yêu cầu (≤260 từ):\n"
			. "1. Dòng đầu: 'Điều tôi tìm hiểu được: [1-2 câu insight nổi bật].'\n"
			. "2. 2-4 đoạn văn: bold lead-in + sentiment/opinion cluster + platform nguồn + inline citation [tên](url).\n"
			. "3. Kết thúc bằng:\n"
			. "   KEY PATTERNS từ social listening:\n"
			. "   1. [pattern ngắn 1 câu]\n"
			. "   2. ...\n"
			. "   (3-5 patterns)\n"
			. "4. Nếu data không đủ tin cậy, nói rõ trong bài.";

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user ],
		];
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
		// Fallback — port system prompt từ
		// tavily-cookbook-main/.../social_media_research.py.
		return "Bạn là social listening analyst. NHIỆM VỤ: tổng hợp opinions/sentiment từ posts trên TikTok, Reddit, Instagram, X, Facebook, LinkedIn. Reddit = honest opinions; TikTok = trends; X = real-time reactions; LinkedIn = professional takes. Trả lời ≤220 từ với sentiment summary + opinion clusters + citation dạng [social:N#URL]. KHÔNG bịa đặt; chỉ dùng snippets cung cấp.";
	}

	/* =================================================================
	 *  Citations
	 * ================================================================ */

	/**
	 * Extract [social:N#URL] tokens → normalized citation array. Cũng chấp
	 * nhận [web:N#URL] nếu model lỡ rơi sang format cũ (graceful).
	 */
	private function extract_citations( string $answer_md, array $results ): array {
		if ( ! preg_match_all( '/\[(?:social|web):(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $matches, PREG_SET_ORDER ) ) {
			return [];
		}
		$out  = [];
		$seen = [];
		foreach ( $matches as $m ) {
			$n = (int) $m[1];
			if ( $n <= 0 ) continue;
			$key = $n . '|' . ( $m[2] ?? '' );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;

			$result = $results[ $n - 1 ] ?? null;
			$out[]  = [
				'token'     => $m[0],
				'kind'      => 'social',
				'web_index' => $n,
				'web_url'   => $m[2] ?? ( $result['url'] ?? '' ),
				'web_host'  => $result ? $result['domain']   : '',
				'web_title' => $result ? $result['title']    : '',
				'platform'  => $result ? ( $result['platform'] ?? '' ) : '',
			];
		}
		return $out;
	}

	/* =================================================================
	 *  Fallback
	 * ================================================================ */

	private function build_stub_answer( array $results ): string {
		if ( empty( $results ) ) {
			return '_LLM gateway unavailable và không có social posts. Configure bizcity-llm-router._';
		}
		$lines = [ '_LLM synth unavailable — hiển thị top social posts thô:_', '' ];
		foreach ( array_slice( $results, 0, 3 ) as $i => $r ) {
			$lines[] = sprintf(
				'- [social:%d#%s] (%s) **%s** — %s',
				$i + 1,
				$r['url'],
				$r['platform'] ?: 'web',
				$r['title'],
				mb_substr( $r['snippet'], 0, 200 )
			);
		}
		return implode( "\n", $lines );
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
			error_log( '[TwinBrain][web-social][noop-bus] ' . $event_key );
		}
	}

	/* =================================================================
	 *  PHASE-SOCIAL-MPR helpers (2026-06-24 Johnny Chu)
	 *  Pattern: adapted from action.trending_research pipeline L1-L3.
	 * ================================================================ */

	/**
	 * Fetch Reddit posts as engagement-signal enrichment for the social query.
	 * Uses public JSON API (keyless) — same approach as trending_research::fetch_reddit().
	 */
	private function fetch_reddit_enrichment( string $query, string $time_range ): array {
		$scope_t = $time_range === 'day' ? 'day' : ( $time_range === 'week' ? 'week' : 'month' );

		// Infer subreddits from query
		$q_lower    = strtolower( $query );
		$subreddits = [ 'vietnam', 'VietNam' ];
		if ( strpos( $q_lower, 'tiktok' ) !== false ) {
			$subreddits = [ 'TikTok', 'tiktokmarketing' ];
		} elseif ( strpos( $q_lower, 'facebook' ) !== false ) {
			$subreddits = [ 'facebook', 'socialmedia' ];
		} elseif ( strpos( $q_lower, 'instagram' ) !== false ) {
			$subreddits = [ 'Instagram', 'socialmedia' ];
		} elseif ( strpos( $q_lower, 'linkedin' ) !== false ) {
			$subreddits = [ 'linkedin', 'socialmedia' ];
		} elseif ( strpos( $q_lower, 'marketing' ) !== false || strpos( $q_lower, 'xu hướng' ) !== false ) {
			$subreddits = [ 'marketing', 'socialmedia', 'vietnam' ];
		} elseif ( strpos( $q_lower, ' x ' ) !== false || strpos( $q_lower, 'twitter' ) !== false ) {
			$subreddits = [ 'Twitter', 'socialmedia' ];
		}

		$results = [];
		foreach ( array_slice( $subreddits, 0, 2 ) as $sub ) {
			$url = 'https://www.reddit.com/r/' . urlencode( $sub ) . '/search.json'
				. '?q=' . urlencode( $query )
				. '&sort=top&t=' . $scope_t . '&limit=5&restrict_sr=1';

			$resp = wp_remote_get( $url, [
				'timeout'    => 6,
				'user-agent' => 'BizCity TwinBrain/1.0 (social listening; +https://bizcity.vn)',
				'headers'    => [ 'Accept' => 'application/json' ],
			] );
			if ( is_wp_error( $resp ) ) { continue; }

			$body = (string) wp_remote_retrieve_body( $resp );
			if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) { $body = substr( $body, 3 ); }
			$decoded = json_decode( trim( $body ), true );

			if ( ! is_array( $decoded ) || empty( $decoded['data']['children'] ) ) { continue; }

			foreach ( $decoded['data']['children'] as $child ) {
				$post = (array) ( $child['data'] ?? [] );
				if ( empty( $post['title'] ) ) { continue; }
				$results[] = [
					'url'         => 'https://reddit.com' . ( (string) ( $post['permalink'] ?? '' ) ),
					'title'       => mb_substr( (string) ( $post['title'] ?? '' ), 0, self::TITLE_TRUNC ),
					'snippet'     => mb_substr( (string) ( $post['selftext'] ?? $post['title'] ?? '' ), 0, self::SNIPPET_TRUNC ),
					'score'       => min( 1.0, (float) ( $post['score'] ?? 0 ) / 1000.0 ),
					'engagement'  => (int) ( $post['score'] ?? 0 ),  // Reddit upvotes = real engagement signal
					'domain'      => 'reddit.com',
					'platform'    => 'reddit',
					'published_at'=> '',
				];
			}
		}
		return $results;
	}

	/**
	 * Engagement scoring + entity grounding.
	 * Pattern: trending_research L2 (lib/relevance.py + lib/grounding.py).
	 * final_score = 0.6 × relevance + 0.4 × engagement_normalized.
	 * Head-token miss → ×0.3 demotion (decisive, same as last30days grounding.py).
	 */
	private function score_and_ground( array $items, string $query ): array {
		$primary_words = preg_split( '/\s+/u', mb_strtolower( $query ), 3 );
		$head_token    = $primary_words[0] ?? '';

		$max_eng = 1;
		foreach ( $items as $r ) {
			if ( isset( $r['engagement'] ) && $r['engagement'] > $max_eng ) {
				$max_eng = $r['engagement'];
			}
		}

		$scored = [];
		$seen   = [];
		foreach ( $items as $r ) {
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' || isset( $seen[ $url ] ) ) { continue; }
			$seen[ $url ] = true;

			$eng_norm = $max_eng > 0 ? (float) ( $r['engagement'] ?? 0 ) / $max_eng : 0.0;
			$rel      = (float) ( $r['score'] ?? 0.5 );
			$final    = 0.6 * $rel + 0.4 * $eng_norm;

			if ( $head_token !== '' ) {
				$combined = mb_strtolower( ( $r['title'] ?? '' ) . ' ' . ( $r['snippet'] ?? '' ) );
				if ( strpos( $combined, $head_token ) === false ) {
					$final *= 0.3;  // decisive demotion — off-topic post
				}
			}

			$r['_final_score'] = round( $final, 4 );
			$scored[] = $r;
		}

		usort( $scored, function ( $a, $b ) {
			return $b['_final_score'] <=> $a['_final_score'];
		} );

		return $scored;
	}

	/**
	 * Build ranked evidence block for LLM synthesis context.
	 * Includes engagement signal in metadata so LLM can weight accordingly.
	 * Pattern: trending_research build_evidence_block().
	 */
	private function build_evidence_block( array $results ): string {
		if ( empty( $results ) ) { return '_(no social results)_'; }
		$lines = [];
		foreach ( array_slice( $results, 0, 12 ) as $i => $r ) {
			$idx      = $i + 1;
			$url      = (string) ( $r['url']     ?? '' );
			$title    = (string) ( $r['title']   ?? '' );
			$snippet  = mb_substr( (string) ( $r['snippet'] ?? '' ), 0, 280 );
			$platform = (string) ( $r['platform'] ?? 'web' );
			$eng      = (int) ( $r['engagement'] ?? 0 );
			$score    = number_format( (float) ( $r['_final_score'] ?? $r['score'] ?? 0 ), 3 );
			$eng_str  = $eng > 0 ? ", upvotes:{$eng}" : '';
			$lines[]  = "[social:{$idx}#{$url}] ({$platform}, score:{$score}{$eng_str}) {$title}";
			if ( $snippet !== '' ) { $lines[] = "  Snippet: {$snippet}"; }
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Extract KEY PATTERNS numbered list from synthesis text.
	 * Pattern: trending_research extract_key_patterns().
	 */
	private function extract_key_patterns( string $text ): array {
		$patterns = [];
		if ( preg_match( '/KEY PATTERNS[^:]*:(.*?)(?:\n\n|\Z)/si', $text, $m ) ) {
			foreach ( explode( "\n", trim( $m[1] ) ) as $line ) {
				$line = trim( $line );
				$line = preg_replace( '/^[\d]+\.\s*/', '', $line );
				$line = preg_replace( '/^[-*•]\s*/', '', $line );
				if ( strlen( $line ) > 5 ) { $patterns[] = $line; }
			}
		}
		return $patterns;
	}
}
