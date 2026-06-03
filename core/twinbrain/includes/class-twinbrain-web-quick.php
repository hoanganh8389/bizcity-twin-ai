<?php
/**
 * TwinBrain — Web Research Quick Engine (TBR.W6-be-quick).
 *
 * Stage 2.5 fast path: 1 lần search Tavily (qua gateway R-GW) + 1 lần LLM
 * synth → perspective row dạng web. Mục tiêu wall-time ≤ 4s.
 *
 * Pipeline (3 SSE events):
 *   1. web_research_started   { mode:'quick', query }
 *   2. web_search_done        { results:[{url,title,snippet,score}] }
 *   3. web_synthesize_done    { answer_md, citation_count, tokens, ms }
 *
 * Hard rules:
 *   - R-GW   — TUYỆT ĐỐI không gọi provider trực tiếp; chỉ qua
 *              `BizCity_Search_Client` (search) + `BizCity_LLM_Client` (chat).
 *   - R-EVT-1/2 — emit qua `BizCity_Twin_Event_Bus::dispatch()`; KHÔNG mở
 *              log table mới.
 *   - R-SKILL N1 — load prompt template từ `bizcity_skills.content`
 *              (skill_key=`web_search_quick`); fallback hardcoded khi seeder
 *              chưa chạy.
 *
 * Output perspective row tương thích với synthesizer (`stance/confidence/
 * answer_md/citations/tokens/ms`) + extra fields `web_*` để FE render section
 * Web Research riêng biệt (PHASE 0.36-UNIFIED §3.5).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-21 (Phase 0.36-UNIFIED TBR.W6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Quick {

	const SKILL_KEY        = 'web_search_quick';
	const DEFAULT_MAX      = 5;
	const SEARCH_TIMEOUT_S = 6;
	const LLM_TIMEOUT_S    = 12;
	const LLM_TEMPERATURE  = 0.2;
	const LLM_MAX_TOKENS   = 600;
	const SNIPPET_TRUNC    = 480;
	const TITLE_TRUNC      = 160;

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Run Quick Web pipeline.
	 *
	 * @param string $trace_id  Brain turn trace id (event correlation).
	 * @param string $query     User prompt (raw, untouched).
	 * @param array  $opts      Optional. Keys:
	 *                          - max (int)       max search results (default 5, hard cap 10).
	 *                          - guru_id (int)   bound persona id (logged, not influencing search).
	 *                          - site_url(string) override site_url (default home_url()).
	 * @return array Web perspective row (see class doc).
	 */
	public function run( string $trace_id, string $query, array $opts = [] ): array {
		$turn_start = microtime( true );
		$query      = trim( $query );
		$max        = max( 1, min( 10, (int) ( $opts['max'] ?? self::DEFAULT_MAX ) ) );

		$row = [
			'mode'           => 'quick',
			'trace_id'       => $trace_id,
			'query'          => $query,
			'results'        => [],
			'extracts'       => [],
			'iterations'    => [],
			'answer_md'      => '',
			'citations'      => [],
			'citation_count' => 0,
			'tokens'         => 0,
			'ms'             => 0,
			'http_status'    => 0,
			'error'          => '',
			'stance'         => 'unknown',
			'confidence'     => 0.0,
			'label'          => 'Web Search (Quick)',
		];

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$this->emit( 'web_research_started', [
			'trace_id' => $trace_id,
			'mode'     => 'quick',
			'query'    => $query,
			'max'      => $max,
		] );

		/* ─── Step 1: search via gateway ─────────────────────────────────── */
		$search_start = microtime( true );
		$results      = $this->do_search( $query, $max );
		$search_ms    = (int) round( ( microtime( true ) - $search_start ) * 1000 );

		if ( is_wp_error( $results ) ) {
			$row['error'] = 'search_failed:' . $results->get_error_code();
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_search_done', [
				'trace_id' => $trace_id,
				'mode'     => 'quick',
				'results'  => [],
				'ms'       => $search_ms,
				'error'    => $row['error'],
			] );
			return $row;
		}

		$row['results'] = $this->normalize_results( $results );

		$this->emit( 'web_search_done', [
			'trace_id' => $trace_id,
			'mode'     => 'quick',
			'results'  => $row['results'],
			'ms'       => $search_ms,
		] );

		if ( empty( $row['results'] ) ) {
			$row['answer_md'] = '_Không tìm thấy kết quả web cho câu hỏi này. Hãy thử Deep Web hoặc reformulate query._';
			$row['stance']    = 'unknown';
			$row['ms']        = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_synthesize_done', [
				'trace_id'       => $trace_id,
				'mode'           => 'quick',
				'answer_md'      => $row['answer_md'],
				'citation_count' => 0,
				'tokens'         => 0,
				'ms'             => 0,
			] );
			return $row;
		}

		/* ─── Step 2: LLM synthesize ─────────────────────────────────────── */
		$synth_start = microtime( true );
		$synth       = $this->do_synthesize( $trace_id, $query, $row['results'] );
		$synth_ms    = (int) round( ( microtime( true ) - $synth_start ) * 1000 );

		$row['answer_md']      = (string) $synth['answer_md'];
		$row['tokens']         = (int) $synth['tokens'];
		$row['http_status']    = (int) $synth['http_status'];
		$row['citations']      = $this->extract_citations( $row['answer_md'], $row['results'] );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0 ? min( 1.0, 0.4 + 0.15 * $row['citation_count'] ) : 0.3;
		$row['stance']         = $row['citation_count'] > 0 ? 'conditional' : 'unknown';

		if ( ! empty( $synth['error'] ) ) {
			$row['error'] = (string) $synth['error'];
		}

		$row['ms'] = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit( 'web_synthesize_done', [
			'trace_id'       => $trace_id,
			'mode'           => 'quick',
			'answer_md'      => $row['answer_md'],
			'citation_count' => $row['citation_count'],
			'tokens'         => $row['tokens'],
			'ms'             => $synth_ms,
			'error'          => $row['error'],
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][web-quick] trace=%s results=%d cite=%d tokens=%d wall=%dms (search=%d synth=%d)',
				$trace_id, count( $row['results'] ), $row['citation_count'], $row['tokens'],
				$row['ms'], $search_ms, $synth_ms
			) );
		}

		return $row;
	}

	/* =================================================================
	 *  Search (R-GW)
	 * ================================================================ */

	private function do_search( string $query, int $max ) {
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
			'timeout'             => self::SEARCH_TIMEOUT_S,
		] );
	}

	/**
	 * Normalize gateway output → FE-friendly shape (matches WebSearchResult
	 * type in BrainThinkingTimeline.tsx).
	 */
	private function normalize_results( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		// Gateway có thể trả `['results'=>[...]]` hoặc array trực tiếp.
		if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) {
			$raw = $raw['results'];
		}
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$out[] = [
				'url'         => $url,
				'title'       => mb_substr( (string) ( $r['title'] ?? $url ), 0, self::TITLE_TRUNC ),
				'snippet'     => mb_substr( (string) ( $r['excerpt'] ?? $r['content'] ?? $r['snippet'] ?? '' ), 0, self::SNIPPET_TRUNC ),
				'score'       => isset( $r['score'] ) ? (float) $r['score'] : 0.0,
				'domain'      => (string) ( $r['domain'] ?? $this->host_of( $url ) ),
				'published_at' => (string) ( $r['published_at'] ?? '' ),
			];
		}
		return $out;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
	}

	/* =================================================================
	 *  LLM synthesize (R-GW)
	 * ================================================================ */

	private function do_synthesize( string $trace_id, string $query, array $results ): array {
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

		$messages = $this->build_messages( $query, $results );
		$model    = $this->resolve_model();

		$endpoint = rtrim( $client->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$api_key  = $client->get_api_key();
		$site_url = home_url();

		$body = wp_json_encode( [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => self::LLM_TEMPERATURE,
			'max_tokens'  => self::LLM_MAX_TOKENS,
			'purpose'     => 'twinbrain_web_quick',
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
		/**
		 * Filter: bizcity_twinbrain_web_quick_model
		 * @param string $model Sub-agent model id for Quick web synth.
		 */
		return (string) apply_filters( 'bizcity_twinbrain_web_quick_model', $model );
	}

	/**
	 * Build chat messages array. Prompt template từ skill `web_search_quick`,
	 * fallback inline khi skill chưa seed.
	 */
	private function build_messages( string $query, array $results ): array {
		$system = $this->load_prompt_template();

		$context = '';
		foreach ( $results as $i => $r ) {
			$idx = $i + 1;
			$context .= sprintf(
				"[web:%d#%s] %s\nDomain: %s\nSnippet: %s\n\n",
				$idx,
				$r['url'],
				$r['title'],
				$r['domain'],
				trim( $r['snippet'] )
			);
		}
		if ( $context === '' ) {
			$context = '_(no search results)_';
		}

		$user = "WEB SEARCH RESULTS (top-" . count( $results ) . "):\n\n{$context}\nCÂU HỎI:\n{$query}\n\nTrả lời bằng tổng hợp ≤200 từ, citation bắt buộc dạng [web:N#URL] cho mọi mệnh đề.";

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user',   'content' => $user ],
		];
	}

	/**
	 * Load prompt template từ `bizcity_skills.content`. Fallback hardcoded
	 * nếu seeder chưa chạy hoặc skill table chưa tồn tại.
	 */
	private function load_prompt_template(): string {
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			try {
				$skill = BizCity_Skill_Database::instance()->get_by_key( self::SKILL_KEY, 0, 0 );
				if ( is_array( $skill ) && ! empty( $skill['content'] ) ) {
					return (string) $skill['content'];
				}
			} catch ( \Throwable $e ) { /* fallthrough */ }
		}
		// Fallback minimal prompt — vẫn enforce citation contract.
		return "Bạn là trợ lý web search nhanh. Tổng hợp câu trả lời ≤200 từ dựa CHỈ trên snippets cung cấp. Mọi mệnh đề phải có citation [web:N#URL]. KHÔNG bịa thông tin ngoài snippets. Nếu snippets không đủ, nói rõ 'Snippets chưa đủ, cần Deep Web'.";
	}

	/* =================================================================
	 *  Citations
	 * ================================================================ */

	/**
	 * Extract [web:N#URL] tokens from answer_md → normalized citation array.
	 * N is 1-based index aligning with `$results`.
	 */
	private function extract_citations( string $answer_md, array $results ): array {
		if ( ! preg_match_all( '/\[web:(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $matches, PREG_SET_ORDER ) ) {
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
	 *  Fallback (gateway/LLM down)
	 * ================================================================ */

	private function build_stub_answer( array $results ): string {
		if ( empty( $results ) ) {
			return '_LLM gateway unavailable và không có kết quả web. Configure bizcity-llm-router._';
		}
		$lines = [ '_LLM synth unavailable — hiển thị top results thô:_', '' ];
		foreach ( array_slice( $results, 0, 3 ) as $i => $r ) {
			$lines[] = sprintf(
				'- [web:%d#%s] **%s** — %s',
				$i + 1,
				$r['url'],
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
			error_log( '[TwinBrain][web-quick][noop-bus] ' . $event_key );
		}
	}
}
