<?php
/**
 * TwinBrain — Web Research Company Engine (TBR.W15-be-company, v2).
 *
 * Stage 2.5 company intelligence path. V2 (2026-05-22) port từ
 * `company-research-agent-main` (Pluely's multi-section briefing pipeline):
 * 4 search categories (company / industry / financial / news) + site crawl
 * + 1 comprehensive LLM briefing call → 4-section markdown report giống
 * editor.compile_content_prompt của reference.
 *
 * V1 (single-shot 280-word blurb) → V2 (full briefing) trade-off:
 *  - Wall-time: ~12s → ~25-30s (chấp nhận, founder yêu cầu detail).
 *  - Search calls: 1 → 4 (mỗi cat 1 query, không generate query bằng LLM).
 *  - LLM call: 1 (single comprehensive briefing, max_tokens 2500).
 *
 * Reference impl:
 *   plugins/bizcoach-pro-research/_library/company-research-agent-main/
 *     backend/prompts.py     (COMPANY/INDUSTRY/FINANCIAL/NEWS_BRIEFING_PROMPT
 *                              + COMPILE_CONTENT_PROMPT)
 *     backend/nodes/researchers/*  (4 analyst types — company/industry/
 *                                    financial/news)
 *     backend/nodes/briefing.py    (per-cat briefing generation)
 *     backend/nodes/editor.py      (compile + sweep)
 *
 * Adaptation rationale (cắt LLM calls để fit single PHP request):
 *  - KHÔNG dùng LLM generate queries (-4 LLM calls) → hardcode 4 category
 *    queries deterministic theo template `{company} {industry} {focus}`.
 *  - KHÔNG dùng per-category briefing (-4 LLM calls) → gộp thành 1 comprehensive
 *    call với structured prompt enforcing 4-section template.
 *  - KHÔNG dùng curator dedup (-1 LLM call) → simple URL-set dedup trong PHP.
 *  - KHÔNG dùng sweep pass (-1 LLM call) → tin tưởng model tuân thủ template.
 *  → Tổng LLM calls: 6+ (reference) → 1 (ours). Tổng wall-time ~25-30s.
 *
 * Pipeline (SSE events):
 *   1. web_research_started   { mode:'company', company_name, website_url,
 *                                categories:[company,industry,financial,news] }
 *   2. web_search_done × 4    (per category, mỗi event có `category` field)
 *   3. web_extract_done       (crawl pages, optional)
 *   4. web_synthesize_done    { answer_md, citation_count, tokens, ms }
 *
 * Hard rules: R-GW (all gateway), R-EVT-1/2 (single bus), R-TG (guru gate generic).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-22 (Phase 0.36-UNIFIED TBR.W15-v2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Company {

	const SKILL_KEY        = 'web_search_company';
	const PER_CAT_MAX      = 5;     // results per category search
	const DEFAULT_CRAWL    = 10;
	const CRAWL_MAX_DEPTH  = 1;
	const SEARCH_TIMEOUT_S = 10;
	const CRAWL_TIMEOUT_S  = 30;
	const LLM_TIMEOUT_S    = 35;    // big synth needs headroom
	const LLM_TEMPERATURE  = 0.2;
	const LLM_MAX_TOKENS   = 2500;  // bumped from 900 — 4-section markdown report
	const SNIPPET_TRUNC    = 600;
	const CRAWL_PAGE_TRUNC = 1200;
	const TITLE_TRUNC      = 180;

	/**
	 * Category → focus keyword map. Port từ
	 * `company-research-agent-main/backend/prompts.py::*_ANALYZER_QUERY_PROMPT`
	 * — chuyển từ LLM-generated queries sang deterministic templates để tiết
	 * kiệm 4 LLM calls (tổng ~12s).
	 */
	const CATEGORIES = [
		'company'   => [
			'label' => 'Company Fundamentals',
			'focus' => 'company overview products services leadership business model',
			'topic' => 'general',
			'time'  => 'year',
		],
		'industry'  => [
			'label' => 'Industry & Competition',
			'focus' => 'industry market position competitors market size trends',
			'topic' => 'general',
			'time'  => 'year',
		],
		'financial' => [
			'label' => 'Financial / Funding',
			'focus' => 'funding investment revenue valuation financials',
			'topic' => 'general',
			'time'  => 'year',
		],
		'news'      => [
			'label' => 'Recent News',
			'focus' => 'news announcements partnerships press releases',
			'topic' => 'news',
			'time'  => 'month',
		],
	];

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	public function run( string $trace_id, string $query, array $opts = [] ): array {
		$turn_start = microtime( true );
		$query      = trim( $query );

		$website_url  = $this->normalize_url( (string) ( $opts['website_url']  ?? '' ) );
		$company_name = trim( (string) ( $opts['company_name'] ?? '' ) );
		if ( $website_url === '' ) $website_url  = $this->extract_url_from_query( $query );
		if ( $company_name === '' ) $company_name = $this->guess_company_name( $query, $website_url );

		$industry    = trim( (string) ( $opts['industry']    ?? '' ) );
		$hq_location = trim( (string) ( $opts['hq_location'] ?? '' ) );

		$row = [
			'mode'            => 'company',
			'company_name'    => $company_name,
			'website_url'     => $website_url,
			'industry'        => $industry,
			'hq_location'     => $hq_location,
			'pages_crawled'   => 0,
			'trace_id'        => $trace_id,
			'query'           => $query,
			'results'         => [],      // flat list, all categories merged (citation index source)
			'results_by_cat'  => [],      // { company: [...], industry: [...], ... }
			'extracts'        => [],      // crawl pages
			'iterations'      => [],
			'answer_md'       => '',
			'citations'       => [],
			'citation_count'  => 0,
			'tokens'          => 0,
			'ms'              => 0,
			'http_status'     => 0,
			'error'           => '',
			'stance'          => 'unknown',
			'confidence'      => 0.0,
			'label'           => 'Company Intelligence',
		];

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$this->emit( 'web_research_started', [
			'trace_id'     => $trace_id,
			'mode'         => 'company',
			'query'        => $query,
			'company_name' => $company_name,
			'website_url'  => $website_url,
			'industry'     => $industry,
			'hq_location'  => $hq_location,
			'categories'   => array_keys( self::CATEGORIES ),
		] );

		/* ─── Step 1: 4 categorized searches (sequential) ──────────────── */
		$seen_urls = [];
		foreach ( self::CATEGORIES as $cat_id => $cat_meta ) {
			$cat_start  = microtime( true );
			$cat_query  = $this->build_cat_query( $company_name, $industry, $cat_meta['focus'], $query );
			$raw        = $this->do_search( $cat_query, self::PER_CAT_MAX, $cat_meta['topic'], $cat_meta['time'] );
			$cat_ms     = (int) round( ( microtime( true ) - $cat_start ) * 1000 );

			if ( is_wp_error( $raw ) ) {
				$this->emit( 'web_search_done', [
					'trace_id' => $trace_id, 'mode' => 'company', 'category' => $cat_id,
					'query'    => $cat_query, 'results' => [], 'ms' => $cat_ms,
					'error'    => 'search_failed:' . $raw->get_error_code(),
				] );
				if ( $row['error'] === '' ) $row['error'] = 'search_failed:' . $raw->get_error_code();
				$row['results_by_cat'][ $cat_id ] = [];
				continue;
			}

			$normalized = $this->normalize_results( $raw, $cat_id );
			// Dedup theo URL trên toàn pipeline; result được tag category đầu tiên gặp.
			$kept = [];
			foreach ( $normalized as $r ) {
				if ( isset( $seen_urls[ $r['url'] ] ) ) continue;
				$seen_urls[ $r['url'] ] = true;
				$kept[] = $r;
			}
			$row['results_by_cat'][ $cat_id ] = $kept;
			$row['results']                   = array_merge( $row['results'], $kept );

			$this->emit( 'web_search_done', [
				'trace_id' => $trace_id, 'mode' => 'company', 'category' => $cat_id,
				'query'    => $cat_query, 'results' => $kept, 'ms' => $cat_ms,
			] );
		}

		/* ─── Step 2: crawl website (nếu có URL) ───────────────────────── */
		if ( $website_url !== '' ) {
			$crawl_start = microtime( true );
			$raw_crawl   = $this->do_crawl( $website_url, self::DEFAULT_CRAWL );
			$crawl_ms    = (int) round( ( microtime( true ) - $crawl_start ) * 1000 );

			if ( is_wp_error( $raw_crawl ) ) {
				if ( $row['error'] === '' ) $row['error'] = 'crawl_failed:' . $raw_crawl->get_error_code();
				$this->emit( 'web_extract_done', [
					'trace_id' => $trace_id, 'mode' => 'company',
					'extracts' => [], 'ms' => $crawl_ms,
					'error'    => 'crawl_failed:' . $raw_crawl->get_error_code(),
				] );
			} else {
				$row['extracts']      = $this->normalize_crawl( $raw_crawl );
				$row['pages_crawled'] = count( $row['extracts'] );
				$this->emit( 'web_extract_done', [
					'trace_id' => $trace_id, 'mode' => 'company',
					'extracts' => $row['extracts'], 'ms' => $crawl_ms,
				] );
			}
		}

		// Stub fallback nếu không có gì
		if ( empty( $row['results'] ) && empty( $row['extracts'] ) ) {
			$row['answer_md'] = sprintf(
				'_Không tìm thấy data cho company `%s`. Hãy thử cung cấp website URL chính thức hoặc đổi sang Quick/Deep web._',
				$company_name ?: '(không xác định)'
			);
			$row['ms'] = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_synthesize_done', [
				'trace_id' => $trace_id, 'mode' => 'company',
				'answer_md' => $row['answer_md'], 'citation_count' => 0,
				'tokens' => 0, 'ms' => 0,
			] );
			return $row;
		}

		/* ─── Step 3: comprehensive LLM briefing ──────────────────────── */
		$synth_start = microtime( true );
		$synth = $this->do_synthesize(
			$trace_id, $query, $company_name, $website_url, $industry, $hq_location,
			$row['results_by_cat'], $row['extracts']
		);
		$synth_ms    = (int) round( ( microtime( true ) - $synth_start ) * 1000 );

		$row['answer_md']      = (string) $synth['answer_md'];
		$row['tokens']         = (int) $synth['tokens'];
		$row['http_status']    = (int) $synth['http_status'];
		$row['citations']      = $this->extract_citations( $row['answer_md'], $row['results'], $row['extracts'] );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0 ? min( 1.0, 0.45 + 0.07 * $row['citation_count'] ) : 0.35;
		$row['stance']         = $row['citation_count'] > 0 ? 'conditional' : 'unknown';

		if ( ! empty( $synth['error'] ) ) {
			$row['error'] = (string) $synth['error'];
		}

		$row['ms'] = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit( 'web_synthesize_done', [
			'trace_id'       => $trace_id, 'mode' => 'company',
			'answer_md'      => $row['answer_md'],
			'citation_count' => $row['citation_count'],
			'tokens'         => $row['tokens'],
			'ms'             => $synth_ms,
			'error'          => $row['error'],
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][web-company-v2] trace=%s name=%s url=%s sources=%d crawl=%d cite=%d tokens=%d wall=%dms',
				$trace_id, $company_name, $website_url,
				count( $row['results'] ), $row['pages_crawled'],
				$row['citation_count'], $row['tokens'], $row['ms']
			) );
		}

		return $row;
	}

	/* =================================================================
	 *  Sanitizers / Parsers
	 * ================================================================ */

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) return '';
		if ( ! preg_match( '#^https?://#i', $url ) ) $url = 'https://' . ltrim( $url, '/' );
		$ok = esc_url_raw( $url );
		return is_string( $ok ) ? $ok : '';
	}

	private function extract_url_from_query( string $q ): string {
		if ( preg_match( '#\bhttps?://[^\s\)\]\>\"\']+#i', $q, $m ) ) {
			return $this->normalize_url( $m[0] );
		}
		if ( preg_match( '/\b([a-z0-9][a-z0-9\-]{1,62}(?:\.[a-z]{2,12}){1,3})\b/i', $q, $m ) ) {
			$host = strtolower( $m[1] );
			if ( ! preg_match( '/\.(php|html?|js|css|json|md|txt|pdf|zip)$/i', $host ) ) {
				return $this->normalize_url( $host );
			}
		}
		return '';
	}

	private function guess_company_name( string $q, string $url ): string {
		$clean = trim( preg_replace( '#\bhttps?://[^\s]+#i', '', $q ) );
		$clean = trim( preg_replace( '/\s+/u', ' ', $clean ) );
		$clean = preg_replace(
			'/^(nghi[eê]n c[uứ]u|t[ìi]m hi[eể]u|research|tell me about|company|brand|doanh nghi[eệ]p|nh[ãa]n h[àa]ng|brandname)\s*:?\s*/iu',
			'', $clean ?? ''
		);
		$clean = trim( (string) $clean );

		if ( $clean !== '' ) {
			$parts = preg_split( '/\s+/u', $clean );
			return implode( ' ', array_slice( $parts ?: [], 0, 6 ) );
		}
		if ( $url !== '' ) {
			$host = wp_parse_url( $url, PHP_URL_HOST ) ?: '';
			$host = preg_replace( '/^www\./i', '', $host );
			$root = explode( '.', $host );
			return ucfirst( $root[0] ?? '' );
		}
		return '';
	}

	/**
	 * Build category-specific search query. Port (deterministic) từ
	 * reference's `*_ANALYZER_QUERY_PROMPT` family — chỉ giữ ý tưởng,
	 * không LLM-generate để tiết kiệm 4 LLM calls.
	 */
	private function build_cat_query( string $company_name, string $industry, string $focus, string $raw_query ): string {
		$base = $company_name !== '' ? $company_name : $raw_query;
		$ind  = $industry !== '' ? ( ' ' . $industry ) : '';
		return trim( $base . $ind . ' ' . $focus );
	}

	/* =================================================================
	 *  Search (R-GW)
	 * ================================================================ */

	private function do_search( string $query, int $max, string $topic, string $time_range ) {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_Error( 'gateway_missing', 'BizCity_Search_Client not loaded' );
		}
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error( 'gateway_not_ready', 'Search gateway not configured' );
		}
		return $client->search( $query, $max, [
			'search_depth'        => 'advanced',
			'include_raw_content' => false,
			'topic'               => $topic,
			'time_range'          => $time_range,
			'timeout'             => self::SEARCH_TIMEOUT_S,
		] );
	}

	private function normalize_results( $raw, string $category ): array {
		if ( ! is_array( $raw ) ) return [];
		if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) $raw = $raw['results'];
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$out[] = [
				'url'          => $url,
				'title'        => mb_substr( (string) ( $r['title'] ?? $url ), 0, self::TITLE_TRUNC ),
				'snippet'      => mb_substr( (string) ( $r['excerpt'] ?? $r['content'] ?? $r['snippet'] ?? '' ), 0, self::SNIPPET_TRUNC ),
				'score'        => isset( $r['score'] ) ? (float) $r['score'] : 0.0,
				'domain'       => (string) ( $r['domain'] ?? $this->host_of( $url ) ),
				'published_at' => (string) ( $r['published_at'] ?? '' ),
				'category'     => $category,
			];
		}
		return $out;
	}

	/* =================================================================
	 *  Crawl (R-GW)
	 * ================================================================ */

	private function do_crawl( string $url, int $limit ) {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) {
			return new WP_Error( 'gateway_missing', 'BizCity_Search_Client not loaded' );
		}
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) {
			return new WP_Error( 'gateway_not_ready', 'Search gateway not configured' );
		}
		return $client->crawl( $url, [
			'limit'     => $limit,
			'max_depth' => self::CRAWL_MAX_DEPTH,
		] );
	}

	private function normalize_crawl( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$content = (string) ( $r['raw_content'] ?? $r['content'] ?? '' );
			$content = preg_replace( '/\s+/u', ' ', $content );
			$out[] = [
				'url'     => $url,
				'title'   => mb_substr( (string) ( $r['title'] ?? $url ), 0, self::TITLE_TRUNC ),
				'snippet' => mb_substr( trim( (string) $content ), 0, self::CRAWL_PAGE_TRUNC ),
				'domain'  => $this->host_of( $url ),
			];
		}
		return $out;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
	}

	/* =================================================================
	 *  LLM synthesize — comprehensive briefing
	 * ================================================================ */

	private function do_synthesize(
		string $trace_id, string $query, string $company_name, string $website_url,
		string $industry, string $hq_location,
		array $results_by_cat, array $extracts
	): array {
		$out = [ 'answer_md' => '', 'tokens' => 0, 'http_status' => 0, 'error' => '' ];

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			$out['error']     = 'llm_client_missing';
			$out['answer_md'] = $this->build_stub_answer( $results_by_cat, $extracts );
			return $out;
		}
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) {
			$out['error']     = 'llm_not_ready';
			$out['answer_md'] = $this->build_stub_answer( $results_by_cat, $extracts );
			return $out;
		}

		$messages = $this->build_messages(
			$query, $company_name, $website_url, $industry, $hq_location,
			$results_by_cat, $extracts
		);
		$model    = $this->resolve_model();
		$endpoint = rtrim( $client->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$api_key  = $client->get_api_key();
		$site_url = home_url();

		$body = wp_json_encode( [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => self::LLM_TEMPERATURE,
			'max_tokens'  => self::LLM_MAX_TOKENS,
			'purpose'     => 'twinbrain_web_company_v2',
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
			$out['answer_md'] = $this->build_stub_answer( $results_by_cat, $extracts );
			return $out;
		}

		$out['http_status'] = (int) wp_remote_retrieve_response_code( $response );
		$raw                = (string) wp_remote_retrieve_body( $response );
		// [2026-06-24 Johnny Chu] HOTFIX-BOM — strip UTF-8 BOM; bizcity.vn gateway prepends 0xEF BB BF → json_decode null on HTTP 200 → gateway_failure:unknown
		if ( substr( $raw, 0, 3 ) === "\xEF\xBB\xBF" ) { $raw = substr( $raw, 3 ); }
		$decoded            = json_decode( trim( $raw ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			$out['error']     = 'gateway_failure:' . ( $decoded['error'] ?? $decoded['message'] ?? 'unknown' );
			$out['answer_md'] = $this->build_stub_answer( $results_by_cat, $extracts );
			return $out;
		}

		$out['answer_md'] = trim( (string) ( $decoded['message'] ?? '' ) );
		$out['tokens']    = (int) ( $decoded['usage']['total_tokens'] ?? 0 );

		if ( $out['answer_md'] === '' ) {
			$out['answer_md'] = $this->build_stub_answer( $results_by_cat, $extracts );
			$out['error']     = 'empty_response';
		}
		return $out;
	}

	private function resolve_model(): string {
		$model = '';
		if ( class_exists( 'BizCity_LLM_Client' ) ) {
			// Briefing dài → ưu tiên model 'briefing' / 'long' slot nếu gateway có,
			// fallback 'chat'. Filter `bizcity_twinbrain_web_company_model` override.
			try { $model = BizCity_LLM_Client::instance()->get_model( 'briefing' ); }
			catch ( \Throwable $e ) { $model = ''; }
			if ( $model === '' ) $model = BizCity_LLM_Client::instance()->get_model( 'chat' );
		}
		return (string) apply_filters( 'bizcity_twinbrain_web_company_model', $model );
	}

	/**
	 * Build comprehensive briefing messages. Port (merge + condense) từ
	 * `prompts.py`: COMPANY/INDUSTRY/FINANCIAL/NEWS_BRIEFING_PROMPT +
	 * COMPILE_CONTENT_PROMPT thành 1 system + 1 user message.
	 */
	private function build_messages(
		string $query, string $company_name, string $website_url,
		string $industry, string $hq_location,
		array $results_by_cat, array $extracts
	): array {
		$system = $this->load_prompt_template();

		// Build numbered context — citation index is global, monotonic.
		$n      = 1;
		$blocks = [];
		foreach ( self::CATEGORIES as $cat_id => $cat_meta ) {
			$cat_results = $results_by_cat[ $cat_id ] ?? [];
			if ( empty( $cat_results ) ) continue;
			$lines = [ '### Sources — ' . $cat_meta['label'] ];
			foreach ( $cat_results as $r ) {
				$lines[] = sprintf(
					"[company:%d#%s] %s (%s%s)\n%s",
					$n, $r['url'], $r['title'], $r['domain'],
					$r['published_at'] ? ' · ' . $r['published_at'] : '',
					trim( $r['snippet'] )
				);
				$n++;
			}
			$blocks[] = implode( "\n\n", $lines );
		}
		if ( ! empty( $extracts ) ) {
			$lines = [ '### Sources — Official Website Pages (crawl)' ];
			foreach ( $extracts as $r ) {
				$lines[] = sprintf(
					"[company:%d#%s] %s (%s)\n%s",
					$n, $r['url'], $r['title'], $r['domain'],
					trim( $r['snippet'] )
				);
				$n++;
			}
			$blocks[] = implode( "\n\n", $lines );
		}
		$context = implode( "\n\n---\n\n", $blocks );
		if ( $context === '' ) $context = '_(no sources)_';

		$header = "**COMPANY:** {$company_name}";
		if ( $website_url !== '' ) $header .= " · website {$website_url}";
		if ( $industry    !== '' ) $header .= " · industry {$industry}";
		if ( $hq_location !== '' ) $header .= " · HQ {$hq_location}";

		// Port từ COMPILE_CONTENT_PROMPT + per-cat briefing prompts (merged).
		$user = $header . "\n\n"
		      . "USER QUESTION / FOCUS:\n{$query}\n\n"
		      . "RESEARCH SOURCES (numbered for citation):\n\n{$context}\n\n"
		      . "---\n\n"
		      . "Hãy viết bản **research report đầy đủ** theo cấu trúc CHÍNH XÁC dưới đây (markdown). "
		      . "Mọi mệnh đề có dữ kiện PHẢI có citation `[company:N#URL]` (N = số trong source list trên). "
		      . "Bullet points — KHÔNG đoạn văn dài. KHÔNG nói \"không có thông tin\" — bỏ qua subsection rỗng.\n\n"
		      . "# {$company_name} — Research Report\n\n"
		      . "## Tóm tắt 1 dòng\n"
		      . "_Format: \"{$company_name} là [what] dùng [tech/medium] cho [target audience], hiện đang [stage / scale]\"_\n\n"
		      . "## Company Overview\n"
		      . "### Core Product/Service\n* (sản phẩm/dịch vụ chính, có citation)\n\n"
		      . "### Leadership Team\n* (founders / C-level, có citation)\n\n"
		      . "### Target Market\n* (khách hàng / use case xác thực)\n\n"
		      . "### Key Differentiators\n* (lợi thế cạnh tranh đã chứng minh)\n\n"
		      . "### Business Model\n* (pricing / distribution channel)\n\n"
		      . "## Industry Overview\n"
		      . "### Market Position\n* (segment, market size + year nếu có)\n\n"
		      . "### Direct Competition\n* (tên đối thủ trực tiếp + sản phẩm)\n\n"
		      . "### Competitive Advantages\n* (lợi thế kỹ thuật / vận hành)\n\n"
		      . "### Market Challenges\n* (rủi ro / thách thức đã xác thực)\n\n"
		      . "## Financial Overview\n"
		      . "### Funding & Investment\n* (tổng funding + ngày, từng round + investor)\n\n"
		      . "### Revenue Model\n* (pricing tier / nguồn doanh thu)\n\n"
		      . "## Recent News\n"
		      . "* (sorted newest → oldest, 1 event/bullet, có ngày + citation)\n\n"
		      . "## Đánh giá & Caveats\n"
		      . "* (sentiment chung, độ tin cậy data, vùng thiếu thông tin)\n\n"
		      . "## References\n"
		      . "* Liệt kê các nguồn đã cite (format `[N] Title — domain — URL`)\n\n"
		      . "QUY TẮC NGHIÊM:\n"
		      . "- KHÔNG bịa số liệu. Nếu không có data cho 1 subsection → bỏ qua subsection đó.\n"
		      . "- KHÔNG lặp lại cùng 1 funding round nhiều lần (gộp các round trùng tháng).\n"
		      . "- KHÔNG dùng paragraph dài — chỉ bullet.\n"
		      . "- Mỗi bullet phải standalone, đầy đủ ý.\n"
		      . "- Citation `[company:N#URL]` đặt CUỐI mỗi bullet có dữ kiện.";

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
		// Fallback system message — port từ
		// `company-research-agent-main/backend/prompts.py::EDITOR_SYSTEM_MESSAGE`
		// + briefing instruction merged.
		return "Bạn là expert report editor cho company research, tổng hợp briefings thành báo cáo đầy đủ về company / brandname / nhãn hàng / doanh nghiệp. NGUYÊN TẮC: dùng EXACT structure user yêu cầu (markdown headers), chỉ bullet points (không paragraph), mỗi mệnh đề có dữ kiện PHẢI có citation [company:N#URL]. KHÔNG bịa — ưu tiên facts cụ thể (số liệu, ngày, tên, sản phẩm) hơn marketing language. KHÔNG nói \"không tìm thấy thông tin\" — bỏ qua subsection rỗng. KHÔNG meta-commentary (\"Đây là báo cáo...\"). Chỉ trả về report, không explanation.";
	}

	/* =================================================================
	 *  Citations
	 * ================================================================ */

	private function extract_citations( string $answer_md, array $results, array $extracts ): array {
		if ( ! preg_match_all( '/\[(?:company|web):(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $matches, PREG_SET_ORDER ) ) {
			return [];
		}
		$merged = array_merge( $results, $extracts );
		$out    = [];
		$seen   = [];
		foreach ( $matches as $m ) {
			$n = (int) $m[1];
			if ( $n <= 0 ) continue;
			$key = $n . '|' . ( $m[2] ?? '' );
			if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;

			$result = $merged[ $n - 1 ] ?? null;
			$source = $n <= count( $results ) ? ( $result['category'] ?? 'news' ) : 'website';
			$out[]  = [
				'token'     => $m[0],
				'kind'      => 'company',
				'source'    => $source,
				'web_index' => $n,
				'web_url'   => $m[2] ?? ( $result['url'] ?? '' ),
				'web_host'  => $result ? $result['domain'] : '',
				'web_title' => $result ? $result['title']  : '',
			];
		}
		return $out;
	}

	/* =================================================================
	 *  Fallback stub
	 * ================================================================ */

	private function build_stub_answer( array $results_by_cat, array $extracts ): string {
		$has_data = ! empty( $extracts );
		foreach ( $results_by_cat as $arr ) { if ( ! empty( $arr ) ) { $has_data = true; break; } }
		if ( ! $has_data ) {
			return '_LLM gateway unavailable và không có data. Configure bizcity-llm-router._';
		}
		$lines = [ '_LLM synth unavailable — hiển thị top sources thô:_', '' ];
		$i = 0;
		foreach ( $results_by_cat as $cat_id => $arr ) {
			if ( empty( $arr ) ) continue;
			$lines[] = '### ' . ( self::CATEGORIES[ $cat_id ]['label'] ?? $cat_id );
			foreach ( array_slice( $arr, 0, 3 ) as $r ) {
				$i++;
				$lines[] = sprintf( '- [company:%d#%s] **%s** — %s', $i, $r['url'], $r['title'], mb_substr( $r['snippet'], 0, 180 ) );
			}
		}
		if ( ! empty( $extracts ) ) {
			$lines[] = '### Website Pages';
			foreach ( array_slice( $extracts, 0, 3 ) as $r ) {
				$i++;
				$lines[] = sprintf( '- [company:%d#%s] **%s** — %s', $i, $r['url'], $r['title'], mb_substr( $r['snippet'], 0, 180 ) );
			}
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
			error_log( '[TwinBrain][web-company-v2][noop-bus] ' . $event_key );
		}
	}
}
