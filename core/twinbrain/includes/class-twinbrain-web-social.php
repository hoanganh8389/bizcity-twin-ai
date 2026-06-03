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
	const LLM_MAX_TOKENS   = 700;
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
			'answer_md'   => '',
			'tokens'      => 0,
			'http_status' => 0,
			'error'       => '',
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
		$decoded            = json_decode( $raw, true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			$out['error']     = 'gateway_failure:' . ( $decoded['error'] ?? $decoded['message'] ?? 'unknown' );
			$out['answer_md'] = $this->build_stub_answer( $results );
			return $out;
		}

		$out['answer_md'] = trim( (string) ( $decoded['message'] ?? '' ) );
		$out['tokens']    = (int) ( $decoded['usage']['total_tokens'] ?? 0 );

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

	private function build_messages( string $query, array $results, string $platform ): array {
		$system = $this->load_prompt_template();

		$context = '';
		foreach ( $results as $i => $r ) {
			$idx = $i + 1;
			$context .= sprintf(
				"[social:%d#%s] (%s) %s\nDomain: %s\nSnippet: %s\n\n",
				$idx,
				$r['url'],
				$r['platform'] ?: 'web',
				$r['title'],
				$r['domain'],
				trim( $r['snippet'] )
			);
		}
		if ( $context === '' ) {
			$context = '_(no social results)_';
		}

		$scope = $platform === 'combined' ? 'TẤT CẢ social platforms' : strtoupper( $platform );
		$user  = "SOCIAL POSTS (top-" . count( $results ) . ", scope={$scope}):\n\n{$context}\nCÂU HỎI:\n{$query}\n\n"
		      . "Yêu cầu trả lời (≤220 từ):\n"
		      . "1. Tóm tắt sentiment chung (positive / negative / mixed) và lý do.\n"
		      . "2. Liệt kê 2-4 chủ đề / opinion clusters nổi bật. Mỗi cluster ghi rõ platform nguồn.\n"
		      . "3. Citation BẮT BUỘC dạng [social:N#URL] cho mọi mệnh đề có dữ kiện.\n"
		      . "4. Nếu posts không đủ tin cậy (vd quá ít, off-topic), nói rõ.";

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
}
