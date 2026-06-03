<?php
/**
 * TwinBrain — Web Research Medical Engine (TBR.W17-med).
 *
 * Stage 2.5 medical-vertical path — 1 lần Tavily search bị giới hạn vào
 * allowlist y khoa (tier A-D) + 1 lần LLM synth → perspective row
 * `mode='med'`. Bám chặt allowlist + bắt buộc disclaimer + cap stance =
 * 'conditional' (KHÔNG bao giờ 'confident') để bảo vệ user khỏi hallucination
 * y tế.
 *
 * Pipeline (3 SSE events — tái dùng schema của Web_Quick/Social):
 *   1. web_research_started   { mode:'med', query, time_range, tier_count }
 *   2. web_search_done        { results:[{url,title,snippet,score,tier}] }
 *   3. web_synthesize_done    { answer_md, citation_count, tokens, ms }
 *
 * Hard rules:
 *   - R-GW   — KHÔNG gọi provider trực tiếp; chỉ qua `BizCity_Search_Client`
 *              + `BizCity_LLM_Client`. Med = Tavily search có
 *              `include_domains` = allowlist (~25 domains tier A-D).
 *   - R-EVT-1/2 — emit qua `BizCity_Twin_Event_Bus::dispatch()`.
 *   - R-TG (guru policy) — `bizcity_twinbrain_web_mode_effective` gate
 *              cũng áp dụng cho 'med' (logic generic trong
 *              `BizCity_TwinBrain_Guru_Web_Flag::gate_web_mode`).
 *   - **Safety cap**: stance MAX = 'conditional' (không bao giờ 'confident')
 *              — y khoa không cho phép phán ngôn tuyệt đối từ web snippet.
 *   - **Disclaimer mandatory**: nếu LLM không kèm dòng disclaimer y tế, code
 *              auto-append ở `do_synthesize()` (safety net).
 *
 * Output perspective row giống Web_Quick + extra:
 *   - `severity` ('info'|'caution'|'critical') ước lượng từ query.
 *   - `disclaimer_appended` (bool) — nếu post-synth phải auto-append.
 *
 * Allowlist nguồn (RFC §2.1):
 *   - Tier A (international auth): pubmed.ncbi.nlm.nih.gov, nih.gov, who.int,
 *     cdc.gov, mayoclinic.org, nejm.org, thelancet.com, jamanetwork.com,
 *     bmj.com, cochranelibrary.com, uptodate.com.
 *   - Tier B (peer-reviewed): nature.com (medicine sub), sciencedirect.com,
 *     pubmed/pmc, medrxiv.org, bmc-* family.
 *   - Tier C (VN authoritative): moh.gov.vn (Bộ Y tế), vietnamplus.vn (health
 *     section), suckhoedoisong.vn (Báo Sức khỏe & Đời sống — cơ quan ngôn
 *     luận Bộ Y tế).
 *   - Tier D (clinical society): heart.org, diabetes.org, cancer.org,
 *     ada.org, idf.org, who.int regions.
 *
 * Filter `bizcity_twinbrain_med_allowlist` cho phép site mở rộng/override.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-27 (Phase 0.36-UNIFIED TBR.W17 / Vertical Web Research Wave 1)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_TwinBrain_Web_Med {

	const SKILL_KEY        = 'web_search_med';
	const DEFAULT_MAX      = 8;
	const SEARCH_TIMEOUT_S = 10;
	const LLM_TIMEOUT_S    = 16;
	const LLM_TEMPERATURE  = 0.15;  // tighter than social (0.25) — fewer creative leaps
	const LLM_MAX_TOKENS   = 900;
	const SNIPPET_TRUNC    = 520;
	const TITLE_TRUNC      = 180;
	const DEFAULT_TIME     = 'year'; // y khoa: bằng chứng ổn định, ưu tiên reviews >6 tháng

	/**
	 * Allowlist y khoa (tier A-D). Domain ROOT (không scheme, không www).
	 * Tavily `include_domains` match cả subdomain (vd `pubmed.ncbi.nlm.nih.gov`
	 * cover `www.ncbi.nlm.nih.gov/pubmed/*`).
	 */
	const ALLOWLIST_TIER_A = [
		'pubmed.ncbi.nlm.nih.gov',
		'ncbi.nlm.nih.gov',
		'nih.gov',
		'who.int',
		'cdc.gov',
		'mayoclinic.org',
		'nejm.org',
		'thelancet.com',
		'jamanetwork.com',
		'bmj.com',
		'cochranelibrary.com',
		'uptodate.com',
		'medlineplus.gov',
	];

	const ALLOWLIST_TIER_B = [
		'nature.com',
		'sciencedirect.com',
		'medrxiv.org',
		'biorxiv.org',
		'biomedcentral.com',
		'springer.com',
		'wiley.com',
		'oxfordacademic.com',
	];

	const ALLOWLIST_TIER_C = [
		'moh.gov.vn',
		'suckhoedoisong.vn',
		'vnvc.vn',           // Trung tâm tiêm chủng — VABIOTECH
		'benhvienthongminh.com', // Cổng Bộ Y tế quản lý
	];

	const ALLOWLIST_TIER_D = [
		'heart.org',
		'diabetes.org',
		'cancer.org',
		'ada.org',
		'idf.org',
		'cancer.gov',
		'arthritis.org',
		'lung.org',
	];

	private static $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	/**
	 * Run Med Web pipeline.
	 *
	 * @param string $trace_id Brain turn trace id.
	 * @param string $query    User prompt (raw).
	 * @param array  $opts     Optional. Keys:
	 *                          - max (int)        max search results (default 8, cap 12).
	 *                          - guru_id (int)    bound persona (logged only).
	 *                          - time_range (string) day|week|month|year (default year).
	 * @return array Web perspective row.
	 */
	public function run( string $trace_id, string $query, array $opts = [] ): array {
		$turn_start = microtime( true );
		$query      = trim( $query );
		$max        = max( 1, min( 12, (int) ( $opts['max'] ?? self::DEFAULT_MAX ) ) );
		$time_range = $this->sanitize_time( (string) ( $opts['time_range'] ?? self::DEFAULT_TIME ) );
		$domains    = $this->effective_allowlist();
		$severity   = $this->estimate_severity( $query );

		$row = [
			'mode'                 => 'med',
			'time_range'           => $time_range,
			'severity'             => $severity,
			'tier_count'           => count( $domains ),
			'trace_id'             => $trace_id,
			'query'                => $query,
			'results'              => [],
			'extracts'             => [],
			'iterations'           => [],
			'answer_md'            => '',
			'citations'            => [],
			'citation_count'       => 0,
			'tokens'               => 0,
			'ms'                   => 0,
			'http_status'          => 0,
			'error'                => '',
			'stance'               => 'unknown',
			'confidence'           => 0.0,
			'label'                => 'Medical Research',
			'disclaimer_appended'  => false,
		];

		if ( $query === '' ) {
			$row['error'] = 'empty_query';
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			return $row;
		}

		$this->emit( 'web_research_started', [
			'trace_id'   => $trace_id,
			'mode'       => 'med',
			'query'      => $query,
			'max'        => $max,
			'time_range' => $time_range,
			'tier_count' => $row['tier_count'],
			'severity'   => $severity,
		] );

		/* ─── Step 1: search via gateway (include_domains = allowlist) ──── */
		$search_start = microtime( true );
		$results      = $this->do_search( $query, $max, $domains, $time_range );
		$search_ms    = (int) round( ( microtime( true ) - $search_start ) * 1000 );

		if ( is_wp_error( $results ) ) {
			$row['error'] = 'search_failed:' . $results->get_error_code();
			$row['ms']    = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_search_done', [
				'trace_id' => $trace_id,
				'mode'     => 'med',
				'results'  => [],
				'ms'       => $search_ms,
				'error'    => $row['error'],
			] );
			return $row;
		}

		$row['results'] = $this->normalize_results( $results );

		$this->emit( 'web_search_done', [
			'trace_id' => $trace_id,
			'mode'     => 'med',
			'results'  => $row['results'],
			'ms'       => $search_ms,
		] );

		if ( empty( $row['results'] ) ) {
			$row['answer_md'] = sprintf(
				"_Không tìm thấy nguồn y khoa chính thống cho query này (time_range=%s, %d domain trong allowlist). Hãy thử reformulate query rõ hơn (vd thêm tên bệnh / hoạt chất / cohort) hoặc tham vấn bác sĩ trực tiếp._\n\n⚕️ Thông tin tham khảo — không thay thế tư vấn y tế chuyên môn.",
				$time_range, count( $domains )
			);
			$row['stance']              = 'unknown';
			$row['disclaimer_appended'] = true;
			$row['ms']                  = (int) round( ( microtime( true ) - $turn_start ) * 1000 );
			$this->emit( 'web_synthesize_done', [
				'trace_id'       => $trace_id,
				'mode'           => 'med',
				'answer_md'      => $row['answer_md'],
				'citation_count' => 0,
				'tokens'         => 0,
				'ms'             => 0,
			] );
			return $row;
		}

		/* ─── Step 2: LLM synthesize (med-tuned prompt) ─────────────────── */
		$synth_start = microtime( true );
		$synth       = $this->do_synthesize( $trace_id, $query, $row['results'], $severity );
		$synth_ms    = (int) round( ( microtime( true ) - $synth_start ) * 1000 );

		$row['answer_md']      = (string) $synth['answer_md'];
		$row['tokens']         = (int) $synth['tokens'];
		$row['http_status']    = (int) $synth['http_status'];

		// Safety net: nếu LLM quên disclaimer, auto-append.
		[ $row['answer_md'], $row['disclaimer_appended'] ] = $this->ensure_disclaimer( $row['answer_md'] );

		$row['citations']      = $this->extract_citations( $row['answer_md'], $row['results'] );
		$row['citation_count'] = count( $row['citations'] );
		$row['confidence']     = $row['citation_count'] > 0 ? min( 0.85, 0.4 + 0.1 * $row['citation_count'] ) : 0.3;
		// Safety cap: med KHÔNG BAO GIỜ 'confident'. Max là 'conditional'.
		$row['stance']         = $row['citation_count'] > 0 ? 'conditional' : 'unknown';

		if ( ! empty( $synth['error'] ) ) {
			$row['error'] = (string) $synth['error'];
		}

		$row['ms'] = (int) round( ( microtime( true ) - $turn_start ) * 1000 );

		$this->emit( 'web_synthesize_done', [
			'trace_id'              => $trace_id,
			'mode'                  => 'med',
			'answer_md'             => $row['answer_md'],
			'citation_count'        => $row['citation_count'],
			'tokens'                => $row['tokens'],
			'ms'                    => $synth_ms,
			'severity'              => $severity,
			'disclaimer_appended'   => $row['disclaimer_appended'],
			'error'                 => $row['error'],
		] );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'[TwinBrain][web-med] trace=%s sev=%s time=%s results=%d cite=%d tokens=%d wall=%dms (search=%d synth=%d) disclaimer_appended=%d',
				$trace_id, $severity, $time_range, count( $row['results'] ),
				$row['citation_count'], $row['tokens'],
				$row['ms'], $search_ms, $synth_ms,
				$row['disclaimer_appended'] ? 1 : 0
			) );
		}

		return $row;
	}

	/* =================================================================
	 *  Sanitizers + helpers
	 * ================================================================ */

	private function sanitize_time( string $t ): string {
		$t = strtolower( trim( $t ) );
		return in_array( $t, [ 'day', 'week', 'month', 'year' ], true ) ? $t : self::DEFAULT_TIME;
	}

	/**
	 * Allowlist hợp nhất (tier A + B + C + D), filterable.
	 *
	 * @return string[] danh sách domain (không trùng).
	 */
	private function effective_allowlist(): array {
		$base = array_unique( array_merge(
			self::ALLOWLIST_TIER_A,
			self::ALLOWLIST_TIER_B,
			self::ALLOWLIST_TIER_C,
			self::ALLOWLIST_TIER_D
		) );
		/**
		 * Filter: bizcity_twinbrain_med_allowlist.
		 *
		 * Cho phép site mở rộng/override danh sách domain y khoa hợp lệ.
		 * KHUYẾN CÁO: chỉ thêm nguồn có editorial board hoặc peer-review.
		 *
		 * @param string[] $domains Danh sách domain hợp nhất 4 tier.
		 */
		$out = (array) apply_filters( 'bizcity_twinbrain_med_allowlist', $base );
		return array_values( array_unique( array_filter( array_map( 'strval', $out ) ) ) );
	}

	/**
	 * Heuristic severity từ query keywords. Dùng cho UI badge + telemetry.
	 *
	 * - critical: cấp cứu / nguy hiểm tính mạng / triệu chứng severe.
	 * - caution:  bệnh mạn / thuốc / interaction.
	 * - info:     wellness / phòng ngừa / kiến thức chung.
	 */
	private function estimate_severity( string $query ): string {
		$q = mb_strtolower( $query );
		$critical_kw = [
			'cấp cứu', 'đột quỵ', 'đau tim', 'khó thở cấp', 'mất ý thức',
			'co giật', 'xuất huyết', 'sốc phản vệ', 'ngộ độc',
			'emergency', 'stroke', 'heart attack', 'anaphylaxis', 'seizure',
		];
		$caution_kw  = [
			'thuốc', 'liều', 'tương tác', 'tác dụng phụ', 'tiểu đường',
			'huyết áp', 'tim mạch', 'ung thư', 'mang thai', 'trẻ em',
			'dosage', 'side effect', 'interaction', 'diabetes', 'pregnancy',
			'chronic', 'cancer', 'hypertension',
		];
		foreach ( $critical_kw as $kw ) {
			if ( mb_strpos( $q, $kw ) !== false ) return 'critical';
		}
		foreach ( $caution_kw as $kw ) {
			if ( mb_strpos( $q, $kw ) !== false ) return 'caution';
		}
		return 'info';
	}

	/* =================================================================
	 *  Search (R-GW) — include_domains constrains to med allowlist
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
			'search_depth'        => 'advanced',  // med rất cần snippet chất lượng
			'include_raw_content' => false,
			'include_domains'     => $domains,
			'time_range'          => $time_range,
			'timeout'             => self::SEARCH_TIMEOUT_S,
		] );
	}

	/**
	 * Normalize gateway output + tag tier để FE render badge tier A/B/C/D.
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
			$domain = (string) ( $r['domain'] ?? $this->host_of( $url ) );
			$tier   = $this->tier_of( $domain );
			$out[]  = [
				'url'          => $url,
				'title'        => mb_substr( (string) ( $r['title'] ?? $url ), 0, self::TITLE_TRUNC ),
				'snippet'      => mb_substr( (string) ( $r['excerpt'] ?? $r['content'] ?? $r['snippet'] ?? '' ), 0, self::SNIPPET_TRUNC ),
				'score'        => isset( $r['score'] ) ? (float) $r['score'] : 0.0,
				'domain'       => $domain,
				'tier'         => $tier,
				'published_at' => (string) ( $r['published_at'] ?? '' ),
			];
		}
		return $out;
	}

	private function host_of( string $url ): string {
		$h = wp_parse_url( $url, PHP_URL_HOST );
		return is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
	}

	private function tier_of( string $host ): string {
		$host = strtolower( $host );
		foreach ( [ 'A' => self::ALLOWLIST_TIER_A, 'B' => self::ALLOWLIST_TIER_B, 'C' => self::ALLOWLIST_TIER_C, 'D' => self::ALLOWLIST_TIER_D ] as $tier => $list ) {
			foreach ( $list as $root ) {
				if ( $host === $root || str_ends_with( $host, '.' . $root ) ) return $tier;
			}
		}
		return 'X'; // out-of-tier (filter cho phép custom mở rộng)
	}

	/* =================================================================
	 *  LLM synthesize (R-GW) — med-tuned prompt
	 * ================================================================ */

	private function do_synthesize( string $trace_id, string $query, array $results, string $severity ): array {
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

		$messages = $this->build_messages( $query, $results, $severity );
		$model    = $this->resolve_model();

		$endpoint = rtrim( $client->get_gateway_url(), '/' ) . '/wp-json/bizcity/v1/llm/chat';
		$api_key  = $client->get_api_key();
		$site_url = home_url();

		$body = wp_json_encode( [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => self::LLM_TEMPERATURE,
			'max_tokens'  => self::LLM_MAX_TOKENS,
			'purpose'     => 'twinbrain_web_med',
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
		/** Filter: bizcity_twinbrain_web_med_model */
		return (string) apply_filters( 'bizcity_twinbrain_web_med_model', $model );
	}

	private function build_messages( string $query, array $results, string $severity ): array {
		$system = $this->load_prompt_template();

		$context = '';
		foreach ( $results as $i => $r ) {
			$idx     = $i + 1;
			$context .= sprintf(
				"[med:%d#%s] (tier %s) %s\nDomain: %s\nSnippet: %s\n\n",
				$idx,
				$r['url'],
				$r['tier'],
				$r['title'],
				$r['domain'],
				trim( $r['snippet'] )
			);
		}
		if ( $context === '' ) {
			$context = '_(no medical results)_';
		}

		$severity_label = [
			'critical' => 'KHẨN CẤP — ưu tiên hướng dẫn cấp cứu + chuyển viện',
			'caution'  => 'THẬN TRỌNG — bệnh lý chuyên khoa, cần chính xác cao',
			'info'     => 'thông thường — kiến thức tham khảo',
		][ $severity ] ?? 'thông thường';

		$user = "NGUỒN Y KHOA (top-" . count( $results ) . ", từ allowlist tier A-D):\n\n{$context}\nCÂU HỎI:\n{$query}\n\n"
		      . "MỨC ĐỘ: {$severity_label}\n\n"
		      . "Yêu cầu trả lời (≤260 từ, Tiếng Việt nếu câu hỏi tiếng Việt):\n"
		      . "1. Trả lời súc tích dựa CHÍNH XÁC trên snippets — KHÔNG bịa thông tin không có.\n"
		      . "2. Citation BẮT BUỘC dạng `[med:N#URL]` cho MỌI mệnh đề có dữ kiện y học.\n"
		      . "3. Nếu sources không nhất quán → nêu rõ \"sources có ý kiến khác nhau\" + cite cả 2 bên.\n"
		      . "4. KHÔNG dùng từ \"chắc chắn\", \"khẳng định\", \"không thể sai\". Dùng \"theo nghiên cứu X\", \"bằng chứng hiện tại cho thấy\".\n"
		      . "5. KẾT BÀI BẮT BUỘC kèm 1 dòng disclaimer: `⚕️ Thông tin tham khảo — không thay thế tư vấn y tế chuyên môn. Hãy gặp bác sĩ cho trường hợp cụ thể.`\n"
		      . ( $severity === 'critical' ? "6. ƯU TIÊN ghi: \"Nếu là cấp cứu, hãy gọi 115 (VN) hoặc đến phòng cấp cứu gần nhất ngay.\"\n" : '' );

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
		// Fallback prompt — synced với seeder definition.
		return "Bạn là medical evidence synthesizer. NHIỆM VỤ: tổng hợp thông tin y khoa từ allowlist (pubmed, nih, who, cdc, mayoclinic, NEJM, Lancet, JAMA, BMJ, Cochrane, Bộ Y tế VN…). RULES BẮT BUỘC: (1) chỉ dùng snippets cung cấp, KHÔNG bịa; (2) citation dạng [med:N#URL]; (3) KHÔNG phán ngôn tuyệt đối — dùng \"theo X\", \"bằng chứng hiện tại\"; (4) luôn kết thúc bằng disclaimer y tế; (5) nếu là triệu chứng cấp cứu, hướng dẫn gọi 115 ngay.";
	}

	/* =================================================================
	 *  Disclaimer safety net
	 * ================================================================ */

	/**
	 * Đảm bảo dòng disclaimer y tế xuất hiện ở cuối câu trả lời. Nếu LLM
	 * đã có, giữ nguyên; nếu thiếu, auto-append. Trả về [answer, appended_bool].
	 *
	 * @return array{0:string,1:bool}
	 */
	private function ensure_disclaimer( string $answer_md ): array {
		$disclaimer_marker = '⚕️';
		if ( $answer_md === '' ) {
			return [ $answer_md, false ];
		}
		if ( mb_strpos( $answer_md, $disclaimer_marker ) !== false ) {
			return [ $answer_md, false ];
		}
		$line = "\n\n⚕️ *Thông tin tham khảo — không thay thế tư vấn y tế chuyên môn. Hãy gặp bác sĩ cho trường hợp cụ thể.*";
		return [ rtrim( $answer_md ) . $line, true ];
	}

	/* =================================================================
	 *  Citations
	 * ================================================================ */

	/**
	 * Extract [med:N#URL] tokens → normalized citation array. Cũng chấp nhận
	 * [web:N#URL] nếu model lỡ rơi sang format chung (graceful) — sẽ tag
	 * kind='med' để FE render badge medical đồng nhất.
	 */
	private function extract_citations( string $answer_md, array $results ): array {
		if ( ! preg_match_all( '/\[(?:med|web):(\d+)(?:#([^\]\s]+))?\]/', $answer_md, $matches, PREG_SET_ORDER ) ) {
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
				'kind'      => 'med',
				'web_index' => $n,
				'web_url'   => $m[2] ?? ( $result['url'] ?? '' ),
				'web_host'  => $result ? $result['domain'] : '',
				'web_title' => $result ? $result['title']  : '',
				'tier'      => $result ? ( $result['tier'] ?? 'X' ) : 'X',
			];
		}
		return $out;
	}

	/* =================================================================
	 *  Fallback
	 * ================================================================ */

	private function build_stub_answer( array $results ): string {
		if ( empty( $results ) ) {
			return "_LLM gateway unavailable và không có nguồn y khoa._\n\n⚕️ *Hãy gặp bác sĩ cho trường hợp cụ thể.*";
		}
		$lines = [ '_LLM synth unavailable — hiển thị top nguồn y khoa thô:_', '' ];
		foreach ( array_slice( $results, 0, 3 ) as $i => $r ) {
			$lines[] = sprintf(
				'- [med:%d#%s] (tier %s) **%s** — %s',
				$i + 1,
				$r['url'],
				$r['tier'],
				$r['title'],
				mb_substr( $r['snippet'], 0, 220 )
			);
		}
		$lines[] = '';
		$lines[] = '⚕️ *Thông tin tham khảo — không thay thế tư vấn y tế chuyên môn.*';
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
			error_log( '[TwinBrain][web-med][noop-bus] ' . $event_key );
		}
	}
}
