<?php
/**
 * TwinBrain — Web Research Scholar Engine (TBR.W17-scholar).
 *
 * Quick-variant cho học thuật. 1 Tavily search (advanced + include_domains
 * = allowlist học thuật) + 1 LLM synth → perspective row `mode='scholar'`.
 * Citation namespace `[sch:N#URL]`. KHÔNG disclaimer (học thuật neutral).
 *
 * Allowlist: arxiv, doi, nature, sciencedirect, pubmed, medrxiv, biorxiv,
 * jstor, springer, wiley, ieee, acm, semanticscholar, ssrn, mit, stanford.
 *
 * Prompt rules: cite kèm (Tác giả, Năm) trong text + [sch:N#URL] sau.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-28 (TBR.W17 Wave 1 / vertical scholar)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Scholar {

	const SKILL_KEY        = 'web_search_scholar';
	const DEFAULT_MAX      = 8;
	const SEARCH_TIMEOUT_S = 10;
	const LLM_TIMEOUT_S    = 16;
	const LLM_TEMPERATURE  = 0.2;
	const LLM_MAX_TOKENS   = 900;
	const SNIPPET_TRUNC    = 520;
	const TITLE_TRUNC      = 180;
	const DEFAULT_TIME     = 'year';

	const ALLOWLIST_TIER_A = [
		'arxiv.org', 'doi.org', 'nature.com', 'science.org', 'sciencedirect.com',
		'pubmed.ncbi.nlm.nih.gov', 'ncbi.nlm.nih.gov',
	];
	const ALLOWLIST_TIER_B = [
		'medrxiv.org', 'biorxiv.org', 'springer.com', 'wiley.com', 'tandfonline.com',
		'oxfordacademic.com', 'cambridge.org', 'jstor.org',
	];
	const ALLOWLIST_TIER_C = [
		'ieee.org', 'ieeexplore.ieee.org', 'acm.org', 'dl.acm.org', 'semanticscholar.org',
		'scholar.google.com', 'ssrn.com', 'researchgate.net',
	];
	const ALLOWLIST_TIER_D = [
		'mit.edu', 'stanford.edu', 'harvard.edu', 'ox.ac.uk', 'cam.ac.uk',
		'mlr.press', 'openreview.net', 'aclanthology.org',
	];

	private static $instance = null;
	public static function instance(): self { if ( null === self::$instance ) self::$instance = new self(); return self::$instance; }

	public function run( string $trace_id, string $query, array $opts = [] ): array {
		$turn_start = microtime( true );
		$query      = trim( $query );
		$max        = max( 1, min( 12, (int) ( $opts['max'] ?? self::DEFAULT_MAX ) ) );
		$time_range = $this->sanitize_time( (string) ( $opts['time_range'] ?? self::DEFAULT_TIME ) );
		$domains    = $this->effective_allowlist();

		$row = [
			'mode' => 'scholar', 'time_range' => $time_range, 'tier_count' => count( $domains ),
			'trace_id' => $trace_id, 'query' => $query,
			'results' => [], 'extracts' => [], 'iterations' => [],
			'answer_md' => '', 'citations' => [], 'citation_count' => 0,
			'tokens' => 0, 'ms' => 0, 'http_status' => 0, 'error' => '',
			'stance' => 'unknown', 'confidence' => 0.0, 'label' => 'Scholar Research',
		];

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$this->emit( 'web_research_started', [
			'trace_id' => $trace_id, 'mode' => 'scholar', 'query' => $query,
			'max' => $max, 'time_range' => $time_range, 'tier_count' => $row['tier_count'],
		] );

		$search_start = microtime( true );
		$results      = $this->do_search( $query, $max, $domains, $time_range );
		$search_ms    = (int) round( ( microtime( true ) - $search_start ) * 1000 );

		if ( is_wp_error( $results ) ) {
			$row['error'] = 'search_failed:' . $results->get_error_code();
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_search_done', [ 'trace_id' => $trace_id, 'mode' => 'scholar', 'results' => [], 'ms' => $search_ms, 'error' => $row['error'] ] );
			return $row;
		}

		$row['results'] = $this->normalize_results( $results );
		$this->emit( 'web_search_done', [ 'trace_id' => $trace_id, 'mode' => 'scholar', 'results' => $row['results'], 'ms' => $search_ms ] );

		if ( empty( $row['results'] ) ) {
			$row['answer_md'] = sprintf( "_Không tìm thấy paper học thuật cho query này (time_range=%s, %d domain). Thử thêm tên field/method/dataset._", $time_range, count( $domains ) );
			$row['ms']        = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_synthesize_done', [ 'trace_id' => $trace_id, 'mode' => 'scholar', 'answer_md' => $row['answer_md'], 'citation_count' => 0, 'tokens' => 0, 'ms' => 0 ] );
			return $row;
		}

		$synth_start = microtime( true );
		$synth       = $this->do_synthesize( $trace_id, $query, $row['results'] );
		$synth_ms    = (int) round( ( microtime( true ) - $synth_start ) * 1000 );

		$row['answer_md']      = (string) $synth['answer_md'];
		$row['tokens']         = (int) $synth['tokens'];
		$row['http_status']    = (int) $synth['http_status'];
		$row['citations']      = $this->extract_citations( $row['answer_md'], $row['results'] );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0 ? min( 0.9, 0.45 + 0.1 * $row['citation_count'] ) : 0.3;
		$row['stance']         = $row['citation_count'] >= 2 ? 'confident' : ( $row['citation_count'] > 0 ? 'conditional' : 'unknown' );
		if ( ! empty( $synth['error'] ) ) $row['error'] = (string) $synth['error'];
		$row['ms'] = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit( 'web_synthesize_done', [
			'trace_id' => $trace_id, 'mode' => 'scholar',
			'answer_md' => $row['answer_md'], 'citation_count' => $row['citation_count'],
			'tokens' => $row['tokens'], 'ms' => $synth_ms, 'error' => $row['error'],
		] );
		return $row;
	}

	private function sanitize_time( string $t ): string { $t = strtolower( trim( $t ) ); return in_array( $t, [ 'day', 'week', 'month', 'year' ], true ) ? $t : self::DEFAULT_TIME; }

	private function effective_allowlist(): array {
		$base = array_unique( array_merge( self::ALLOWLIST_TIER_A, self::ALLOWLIST_TIER_B, self::ALLOWLIST_TIER_C, self::ALLOWLIST_TIER_D ) );
		/** Filter: bizcity_twinbrain_scholar_allowlist */
		$out  = (array) apply_filters( 'bizcity_twinbrain_scholar_allowlist', $base );
		return array_values( array_unique( array_filter( array_map( 'strval', $out ) ) ) );
	}

	private function do_search( string $query, int $max, array $domains, string $time_range ) {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) return new WP_Error( 'gateway_missing', 'BizCity_Search_Client not loaded' );
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) return new WP_Error( 'gateway_not_ready', 'Search gateway not configured' );
		return $client->search( $query, $max, [
			'search_depth' => 'advanced', 'include_raw_content' => false,
			'include_domains' => $domains, 'time_range' => $time_range, 'timeout' => self::SEARCH_TIMEOUT_S,
		] );
	}

	private function normalize_results( $raw ): array {
		if ( ! is_array( $raw ) ) return [];
		if ( isset( $raw['results'] ) && is_array( $raw['results'] ) ) $raw = $raw['results'];
		$out = [];
		foreach ( $raw as $r ) {
			if ( ! is_array( $r ) ) continue;
			$url = (string) ( $r['url'] ?? '' );
			if ( $url === '' ) continue;
			$domain = (string) ( $r['domain'] ?? $this->host_of( $url ) );
			$out[]  = [
				'url' => $url,
				'title' => mb_substr( (string) ( $r['title'] ?? $url ), 0, self::TITLE_TRUNC ),
				'snippet' => mb_substr( (string) ( $r['excerpt'] ?? $r['content'] ?? $r['snippet'] ?? '' ), 0, self::SNIPPET_TRUNC ),
				'score' => isset( $r['score'] ) ? (float) $r['score'] : 0.0,
				'domain' => $domain, 'tier' => $this->tier_of( $domain ),
				'published_at' => (string) ( $r['published_at'] ?? '' ),
			];
		}
		return $out;
	}

	private function host_of( string $url ): string { $h = wp_parse_url( $url, PHP_URL_HOST ); return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : ''; }

	private function tier_of( string $host ): string {
		$host = strtolower( $host );
		foreach ( [ 'A' => self::ALLOWLIST_TIER_A, 'B' => self::ALLOWLIST_TIER_B, 'C' => self::ALLOWLIST_TIER_C, 'D' => self::ALLOWLIST_TIER_D ] as $tier => $list ) {
			foreach ( $list as $root ) if ( $host === $root || str_ends_with( $host, '.' . $root ) ) return $tier;
		}
		return 'X';
	}

	private function do_synthesize( string $trace_id, string $query, array $results ): array {
		$out = [ 'answer_md' => '', 'tokens' => 0, 'http_status' => 0, 'error' => '' ];
		if ( ! class_exists( 'BizCity_LLM_Client' ) ) { $out['error'] = 'llm_client_missing'; $out['answer_md'] = $this->build_stub_answer( $results ); return $out; }
		$client = BizCity_LLM_Client::instance();
		if ( ! $client->is_ready() ) { $out['error'] = 'llm_not_ready'; $out['answer_md'] = $this->build_stub_answer( $results ); return $out; }

		$messages = $this->build_messages( $query, $results );
		$model    = (string) apply_filters( 'bizcity_twinbrain_web_scholar_model', $client->get_model( 'chat' ) );
		$endpoint = rtrim( $client->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$body     = wp_json_encode( [
			'model' => $model, 'messages' => $messages,
			'temperature' => self::LLM_TEMPERATURE, 'max_tokens' => self::LLM_MAX_TOKENS,
			'purpose' => 'twinbrain_web_scholar', 'site_url' => home_url(), 'timeout' => self::LLM_TIMEOUT_S,
		] );
		$response = wp_remote_post( $endpoint, [
			'headers' => [
				'Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $client->get_api_key(),
				'X-Site-URL' => home_url(), 'X-Twin-Trace-Id' => $trace_id,
			],
			'body' => $body, 'timeout' => self::LLM_TIMEOUT_S,
		] );
		if ( is_wp_error( $response ) ) { $out['error'] = 'http_error:' . $response->get_error_code(); $out['answer_md'] = $this->build_stub_answer( $results ); return $out; }
		$out['http_status'] = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || empty( $decoded['success'] ) ) { $out['error'] = 'gateway_failure:' . ( $decoded['error'] ?? $decoded['message'] ?? 'unknown' ); $out['answer_md'] = $this->build_stub_answer( $results ); return $out; }
		$out['answer_md'] = trim( (string) ( $decoded['message'] ?? '' ) );
		$out['tokens']    = (int) ( $decoded['usage']['total_tokens'] ?? 0 );
		if ( $out['answer_md'] === '' ) { $out['answer_md'] = $this->build_stub_answer( $results ); $out['error'] = 'empty_response'; }
		return $out;
	}

	private function build_messages( string $query, array $results ): array {
		$system = $this->load_prompt_template();
		$context = '';
		foreach ( $results as $i => $r ) {
			$idx = $i + 1;
			$context .= sprintf( "[sch:%d#%s] (tier %s) %s\nDomain: %s\nSnippet: %s\n\n", $idx, $r['url'], $r['tier'], $r['title'], $r['domain'], trim( $r['snippet'] ) );
		}
		if ( $context === '' ) $context = '_(no scholar results)_';
		$user = "NGUỒN HỌC THUẬT (top-" . count( $results ) . ", từ allowlist tier A-D):\n\n{$context}\nCÂU HỎI:\n{$query}\n\n"
		      . "Yêu cầu (≤260 từ, Tiếng Việt nếu câu hỏi tiếng Việt):\n"
		      . "1. Tổng hợp CHÍNH XÁC từ snippets — KHÔNG bịa kết quả/số liệu.\n"
		      . "2. Citation BẮT BUỘC: trong text ghi (Tác giả/Tổ chức, Năm) NẾU snippet có; sau đó kèm `[sch:N#URL]`.\n"
		      . "3. Nếu paper xung đột → nêu rõ + cite cả 2 bên.\n"
		      . "4. Phân biệt rõ: pre-print (medrxiv/biorxiv/arxiv) vs peer-reviewed.\n"
		      . "5. KHÔNG dùng \"chắc chắn\". Dùng \"theo nghiên cứu X (Năm)\", \"meta-analysis Y cho thấy\".";
		return [ [ 'role' => 'system', 'content' => $system ], [ 'role' => 'user', 'content' => $user ] ];
	}

	private function load_prompt_template(): string {
		if ( class_exists( 'BizCity_Skill_Database' ) ) {
			try { $skill = BizCity_Skill_Database::instance()->get_by_key( self::SKILL_KEY, 0, 0 ); if ( is_array( $skill ) && ! empty( $skill['content'] ) ) return (string) $skill['content']; }
			catch ( \Throwable $e ) { /* fallthrough */ }
		}
		return "Bạn là academic evidence synthesizer. Tổng hợp paper từ allowlist (arxiv, doi, nature, sciencedirect, pubmed, ieee, acm, semanticscholar...). RULES: (1) chỉ dùng snippets; (2) citation (Author, Year) + [sch:N#URL]; (3) phân biệt pre-print vs peer-reviewed; (4) KHÔNG phán ngôn tuyệt đối.";
	}

	private function extract_citations( string $answer_md, array $results ): array {
		if ( ! preg_match_all( '/\[(?:sch|web):(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $matches, PREG_SET_ORDER ) ) return [];
		$out = []; $seen = [];
		foreach ( $matches as $m ) {
			$n = (int) $m[1]; if ( $n <= 0 ) continue;
			$key = $n . '|' . ( $m[2] ?? '' ); if ( isset( $seen[ $key ] ) ) continue;
			$seen[ $key ] = true;
			$result = $results[ $n - 1 ] ?? null;
			$out[]  = [ 'token' => $m[0], 'kind' => 'sch', 'web_index' => $n, 'web_url' => $m[2] ?? ( $result['url'] ?? '' ), 'web_host' => $result ? $result['domain'] : '', 'web_title' => $result ? $result['title'] : '', 'tier' => $result ? ( $result['tier'] ?? 'X' ) : 'X' ];
		}
		return $out;
	}

	private function build_stub_answer( array $results ): string {
		if ( empty( $results ) ) return "_LLM gateway unavailable và không có paper._";
		$lines = [ '_LLM synth unavailable — top paper thô:_', '' ];
		foreach ( array_slice( $results, 0, 3 ) as $i => $r ) {
			$lines[] = sprintf( '- [sch:%d#%s] (tier %s) **%s** — %s', $i + 1, $r['url'], $r['tier'], $r['title'], mb_substr( $r['snippet'], 0, 220 ) );
		}
		return implode( "\n", $lines );
	}

	private function emit( string $event_key, array $payload ): void {
		if ( class_exists( 'BizCity_Twin_Event_Bus' ) ) { try { BizCity_Twin_Event_Bus::dispatch( $event_key, $payload ); return; } catch ( \Throwable $e ) { /* fallthrough */ } }
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) error_log( '[TwinBrain][web-scholar][noop-bus] ' . $event_key );
	}
}
