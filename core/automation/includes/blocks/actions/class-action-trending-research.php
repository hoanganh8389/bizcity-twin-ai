<?php
/**
 * Action: Trending Research (Multi-Source MPR Pipeline).
 *
 * Inspired by last30days-skill v3.8 — implements the same multi-source
 * parallel-fetch → engagement-score → LLM-synthesis MPR pipeline adapted
 * for the BizCity/TwinBrain stack.
 *
 * Pipeline layers (mirrors last30days SKILL.md §STEP contract):
 *   L0 · Topic Resolution   — detect entity, resolve platform handles, build query plan
 *   L1 · Parallel Fetch     — Reddit (public JSON), TikTok (search), Web (Tavily), YouTube
 *   L2 · Signal Scoring     — engagement score (upvotes/views/shares), entity grounding check
 *   L3 · Cluster Merge      — deduplicate same story across sources, cross-source merge
 *   L4 · LLM Synthesis      — ranked evidence → "What I learned:" prose + KEY PATTERNS
 *   L5 · Output Format      — badge + citations + sources_text (Zalo-safe)
 *
 * Output vars:
 *   {{n_X.answer_md}}       — "What I learned:" prose markdown với inline citations
 *   {{n_X.key_patterns}}    — KEY PATTERNS list as JSON array of strings
 *   {{n_X.sources_text}}    — plain-text source list (Zalo-safe, no markdown)
 *   {{n_X.top_sources}}     — JSON array [{url, title, source, score, engagement}]
 *   {{n_X.source_count}}    — số nguồn tìm được
 *   {{n_X.scope}}           — "1d" | "7d" | "30d"
 *   {{n_X.platforms_used}}  — comma-separated list of platforms with results
 *   {{n_X.ok}}              — bool
 *   {{n_X.ms}}              — wall-time ms
 *   {{n_X.error}}           — error string nếu ok=false
 *
 * Automation use-case:
 *   trigger.cron (09:00 daily) → action.trending_research (topic=auto, scope=1d)
 *   → action.reply_zalo | action.send_email | action.publish_fb_post
 *
 * [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — initial implementation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      PHASE-TRENDING W1 (2026-06-24)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Trending_Research extends BizCity_Automation_Block_Base {

	const SCOPE_OPTIONS    = array( '1d', '7d', '30d' );
	const PLATFORM_OPTIONS = array( 'web', 'reddit', 'tiktok', 'youtube', 'news' );
	const OUTPUT_OPTIONS   = array( 'full', 'compact', 'key_patterns_only' );

	/** Default LLM timeout for synthesis step (seconds). */
	const LLM_TIMEOUT_S = 60;

	/** Max sources to pass to LLM synthesis context (token budget). */
	const MAX_SYNTHESIS_SOURCES = 12;

	public function id(): string   { return 'action.trending_research'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Tìm Trending',
			'short'    => 'trending_research',
			'category' => 'ai',
			'color'    => '#7c3aed',
			'icon'     => 'trending-up',
			'defaults' => array(
				'label'     => 'trending_research',
				'topic'     => '{{trigger.text}}',
				'scope'     => '1d',
				'platforms' => 'web,reddit,tiktok',
				'language'  => 'vi',
				'output'    => 'full',
			),
			'fields' => array(
				array( 'name' => 'label',     'label' => 'Tên hiển thị',          'type' => 'text' ),
				array(
					'name'  => 'topic',
					'label' => 'Chủ đề tìm kiếm',
					'type'  => 'textarea',
					'hint'  => 'Hỗ trợ {{trigger.text}}, {{vars.topic}}. Ví dụ: "xu hướng mạng xã hội hôm nay", "TikTok trending VN"',
				),
				array(
					'name'    => 'scope',
					'label'   => 'Khoảng thời gian',
					'type'    => 'select',
					'options' => self::SCOPE_OPTIONS,
					'hint'    => '1d = hôm nay · 7d = 7 ngày · 30d = 30 ngày qua',
				),
				array(
					'name'  => 'platforms',
					'label' => 'Nguồn tìm kiếm',
					'type'  => 'text',
					'hint'  => 'Comma-separated: web, reddit, tiktok, youtube, news. Mặc định: web,reddit,tiktok',
				),
				array(
					'name'    => 'language',
					'label'   => 'Ngôn ngữ kết quả',
					'type'    => 'select',
					'options' => array( 'vi', 'en' ),
					'hint'    => 'vi = tiếng Việt (mặc định) · en = English',
				),
				array(
					'name'    => 'output',
					'label'   => 'Định dạng output',
					'type'    => 'select',
					'options' => self::OUTPUT_OPTIONS,
					'hint'    => 'full = prose + patterns + sources · compact = 3-para summary · key_patterns_only = numbered list',
				),
			),
		);
	}

	// -------------------------------------------------------------------------
	// Main execute
	// -------------------------------------------------------------------------

	public function execute( array $ctx, array $data ) {
		// [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — MPR pipeline execute.
		$t0 = microtime( true );

		$topic = trim( (string) $this->resolve( $data['topic'] ?? '{{trigger.text}}', $ctx ) );
		if ( $topic === '' ) {
			$this->note_event( 'trending_research_skipped', array( 'reason' => 'invalid_param', 'detail' => 'topic empty' ) );
			return $this->_empty_result( 'invalid_param', $t0 );
		}

		$scope     = (string) ( $data['scope']    ?? '1d' );
		$language  = (string) ( $data['language'] ?? 'vi' );
		$output    = (string) ( $data['output']   ?? 'full' );

		if ( ! in_array( $scope, self::SCOPE_OPTIONS, true ) ) { $scope = '1d'; }
		if ( ! in_array( $output, self::OUTPUT_OPTIONS, true ) ) { $output = 'full'; }

		$platforms = array_filter(
			array_map( 'trim', explode( ',', (string) ( $data['platforms'] ?? 'web,reddit,tiktok' ) ) )
		);
		$platforms = array_values( array_intersect( $platforms, self::PLATFORM_OPTIONS ) );
		if ( empty( $platforms ) ) { $platforms = array( 'web' ); }

		// --- L0: Topic Resolution (entity detection + query expansion) -------
		$query_plan = $this->build_query_plan( $topic, $scope, $language );

		// --- L1: Parallel Fetch across platforms -----------------------------
		$raw_results = $this->fetch_all_platforms( $platforms, $query_plan, $scope );

		if ( empty( $raw_results ) ) {
			$this->note_event( 'trending_research_no_results', array(
				'topic'     => $topic,
				'scope'     => $scope,
				'platforms' => implode( ',', $platforms ),
			) );
			// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — pass $topic so msg_1..4 show meaningful text.
			return $this->_empty_result( 'no_results', $t0, $topic );
		}

		// --- L2: Signal Scoring + entity grounding ---------------------------
		$scored = $this->score_and_filter( $raw_results, $topic );

		// --- L3: Cluster Merge (deduplicate cross-source) --------------------
		$clusters = $this->merge_clusters( $scored );

		// --- L4: LLM Synthesis -----------------------------------------------
		$synthesis = $this->synthesize( $topic, $clusters, $query_plan, $language, $output );

		// --- L5: Format output -----------------------------------------------
		$top_sources = array_slice( $clusters, 0, self::MAX_SYNTHESIS_SOURCES );
		$ms          = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		$platforms_used = implode( ',', array_unique( array_column( $raw_results, 'platform' ) ) );

		$this->note_event( 'trending_research_ok', array(
			'topic'          => $topic,
			'scope'          => $scope,
			'platforms'      => implode( ',', $platforms ),
			'source_count'   => count( $clusters ),
			'ms'             => $ms,
		) );

		// [2026-07-05 Johnny Chu] PHASE-IMG-TPL — top_url: plain-text URL of #1 source for Zalo.
		// Zalo không hỗ trợ markdown [text](url) hoặc href → gửi plain URL để tự thành link.
		$top_url = (string) ( $clusters[0]['url'] ?? '' );

		return array(
			'answer_md'      => (string) ( $synthesis['answer_md']    ?? '' ),
			'key_patterns'   => (array)  ( $synthesis['key_patterns'] ?? array() ),
			'sources_text'   => (string) ( $synthesis['sources_text'] ?? '' ),
			'top_sources'    => array_map( array( $this, 'format_source_item' ), $top_sources ),
			'source_count'   => count( $clusters ),
			'scope'          => $scope,
			'platforms_used' => $platforms_used,
			'top_url'        => $top_url,
			'ok'             => true,
			'ms'             => $ms,
			'error'          => '',
			// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — 4 Zalo message chunks.
			'msg_1'          => (string) ( $synthesis['msg_1'] ?? '' ),
			'msg_2'          => (string) ( $synthesis['msg_2'] ?? '' ),
			'msg_3'          => (string) ( $synthesis['msg_3'] ?? '' ),
			'msg_4'          => (string) ( $synthesis['msg_4'] ?? '' ),
		);
	}

	// -------------------------------------------------------------------------
	// L0 · Topic Resolution → JSON query plan
	// -------------------------------------------------------------------------

	/**
	 * Build a query plan from the topic string.
	 * Pattern from last30days SKILL.md §Step 0.75 (--plan JSON schema).
	 *
	 * @return array {
	 *   primary_entity: string,       // stripped entity without intent modifier
	 *   intent_modifier: string,      // "trending", "review", "viral" etc.
	 *   search_queries: string[],     // 3-5 expanded search strings
	 *   scope_days: int,              // 1 | 7 | 30
	 *   reddit_subreddits: string[],  // guessed relevant subreddits
	 *   tiktok_hashtags: string[],    // guessed hashtags
	 *   language: string,
	 * }
	 */
	private function build_query_plan( string $topic, string $scope, string $language ): array {
		// [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — entity resolution.
		// Pattern: last30days §Step 0.75 — "YOU generate the JSON query plan."
		// For cron/automation (no reasoning model in-loop), use deterministic
		// expansion; Phase W2 will call BizCity_LLM_Client for full plan generation.
		$scope_days = array( '1d' => 1, '7d' => 7, '30d' => 30 )[ $scope ] ?? 1;

		// Strip trailing intent modifiers (pattern from last30days grounding.py)
		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — extended intent stripping.
		// Using str_ireplace for Vietnamese (對 \b fails on Unicode). Order matters: longer phrases first.
		$intent_words = array(
			'xu hướng về', 'xu hướng', 'xu huong ve', 'xu huong',
			'tìm xu hướng', 'tim xu huong', 'tìm hiểu về', 'tìm về', 'tim ve',
			'nghên cứu về', 'nghien cuu ve', 'về chủ đề', 've chu de',
			'trending', 'viral', 'hot', 'mới nhất', 'moi nhat',
			'hôm nay', 'hom nay', 'tuần này', 'tuan nay',
			'review', 'tin tức', 'tin tuc',
		);
		$primary = $topic;
		foreach ( $intent_words as $w ) {
			$primary = str_ireplace( $w, ' ', $primary );
		}
		$primary = trim( preg_replace( '/\s{2,}/u', ' ', $primary ) );
		if ( $primary === '' ) { $primary = $topic; }

		// Expand to 3 search variants (scope-aware)
		$scope_suffix = $scope === '1d' ? ' hôm nay' : ( $scope === '7d' ? ' tuần này' : ' tháng này' );
		$queries      = array(
			$topic . $scope_suffix,
			$primary . ' xu hướng ' . date( 'Y' ),
			$primary . ' trending mạng xã hội',
		);
		if ( $language === 'en' ) {
			$queries = array(
				$topic . ( $scope === '1d' ? ' today' : ' this week' ),
				$primary . ' trending ' . date( 'Y' ),
				$primary . ' viral social media',
			);
		}

		// Guess Vietnamese subreddits / hashtags from topic keywords
		$tiktok_hashtags = array(
			'#' . preg_replace( '/\s+/', '', $primary ),
			'#trending',
			'#xuhuong',
		);
		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — topic-category subreddit mapping.
		// Previously only checked 'mạng xã hội' and 'tiktok'; most topics fell back to
		// 'vietnam/VietNam/learnVietnamese' which have zero niche-topic content → no_results.
		$subreddits = $this->detect_subreddits( $primary, $topic );
		if ( false !== stripos( $topic, 'tiktok' ) ) {
			$tiktok_hashtags[] = '#tiktoktrending';
		}

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — English queries for Reddit (English-dominant platform).
		$english_hint    = $this->topic_to_english_hint( $primary );
		$english_queries = array(
			$english_hint . ' trending ' . date( 'Y' ),
			$english_hint . ( $scope === '1d' ? ' today' : ( $scope === '7d' ? ' this week' : ' this month' ) ),
		);

		return array(
			'primary_entity'     => $primary,
			'intent_modifier'    => 'trending',
			'search_queries'     => $queries,
			'english_queries'    => $english_queries,
			'scope_days'         => $scope_days,
			'reddit_subreddits'  => $subreddits,
			'tiktok_hashtags'    => $tiktok_hashtags,
			'language'           => $language,
		);
	}

	// -------------------------------------------------------------------------
	// L1 · Parallel Fetch (multi-platform)
	// -------------------------------------------------------------------------

	private function fetch_all_platforms( array $platforms, array $plan, string $scope ): array {
		// [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — platform dispatcher.
		// Pattern: last30days fanout.py (parallel platform calls).
		// PHP 7.4: sequential (not truly parallel); future W2 uses wp_remote_post async.
		$all = array();

		foreach ( $platforms as $platform ) {
			try {
				switch ( $platform ) {
					case 'web':
						$results = $this->fetch_web( $plan, $scope );
						break;
					case 'reddit':
						$results = $this->fetch_reddit( $plan, $scope );
						break;
					case 'tiktok':
						$results = $this->fetch_tiktok( $plan, $scope );
						break;
					case 'youtube':
						$results = $this->fetch_youtube( $plan, $scope );
						break;
					case 'news':
						$results = $this->fetch_news( $plan, $scope );
						break;
					default:
						$results = array();
				}
				foreach ( $results as &$r ) { $r['platform'] = $platform; }
				unset( $r );
				$all = array_merge( $all, $results );
			} catch ( \Throwable $e ) {
				$this->note_event( 'trending_fetch_error', array(
					'platform' => $platform,
					'reason'   => 'timeout',
					'error'    => $e->getMessage(),
				) );
			}
		}

		return $all;
	}

	/**
	 * Web fetch via BizCity_Search_Client (Tavily under the hood).
	 * Pattern: last30days lib/web_search_keyless.py + lib/rerank.py.
	 */
	private function fetch_web( array $plan, string $scope ): array {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) { return array(); }
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) { return array(); }

		$results = array();
		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — BizCity_Search_Client::search() returns flat array, NOT ['results'=>[...]].
		// Use the first 2 expanded queries (token-budget aware)
		foreach ( array_slice( $plan['search_queries'], 0, 2 ) as $q ) {
			$resp = $client->search( $q, 6, array(
				'include_answer' => false,
				'topic'          => 'news',
			) );
			if ( is_wp_error( $resp ) || ! is_array( $resp ) ) { continue; }
			// search() returns flat array of items
			$items = isset( $resp['results'] ) ? $resp['results'] : $resp;
			foreach ( $items as $r ) {
				if ( ! is_array( $r ) || empty( $r['url'] ) ) { continue; }
				$results[] = array(
					'url'        => (string) ( $r['url']     ?? '' ),
					'title'      => (string) ( $r['title']   ?? '' ),
					'snippet'    => (string) ( $r['content'] ?? $r['excerpt'] ?? '' ),
					'score'      => (float)  ( $r['score']   ?? 0.5 ),
					'engagement' => 0,   // web has no engagement signal
					'source'     => 'web',
				);
			}
		}
		return $results;
	}

	/**
	 * Reddit public JSON (keyless, same as last30days lib/reddit_keyless.py).
	 * Uses Reddit's undocumented public API — no auth needed.
	 * Pattern from last30days: "Public JSON gives you threads + top comments."
	 *
	 * [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — added global Reddit fallback.
	 * When per-subreddit returns nothing (niche topic on wrong sub), fall back to
	 * searching all of Reddit (no restrict_sr) — always finds results for any topic.
	 */
	private function fetch_reddit( array $plan, string $scope ): array {
		$results = array();
		$scope_t = $scope === '1d' ? 'day' : ( $scope === '7d' ? 'week' : 'month' );
		$query   = $plan['search_queries'][0] ?? $plan['primary_entity'];

		foreach ( $plan['reddit_subreddits'] as $sub ) {
			$url = 'https://www.reddit.com/r/' . urlencode( $sub ) . '/search.json'
				. '?q=' . urlencode( $query )
				. '&sort=top&t=' . $scope_t . '&limit=5&restrict_sr=1';

			$resp = wp_remote_get( $url, array(
				'timeout'    => 8,
				'user-agent' => 'BizCity TwinBrain/1.0 (trending research; +https://bizcity.vn)',
				'headers'    => array( 'Accept' => 'application/json' ),
			) );
			if ( is_wp_error( $resp ) ) { continue; }

			$body    = (string) wp_remote_retrieve_body( $resp );
			if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) { $body = substr( $body, 3 ); }
			$decoded = json_decode( trim( $body ), true );

			if ( ! is_array( $decoded ) || empty( $decoded['data']['children'] ) ) { continue; }

			foreach ( $decoded['data']['children'] as $child ) {
				$post = (array) ( $child['data'] ?? array() );
				if ( empty( $post['title'] ) ) { continue; }
				$results[] = array(
					'url'        => 'https://reddit.com' . ( (string) ( $post['permalink'] ?? '' ) ),
					'title'      => (string) ( $post['title']    ?? '' ),
					'snippet'    => (string) ( $post['selftext'] ?? $post['title'] ?? '' ),
					'score'      => min( 1.0, (float) ( $post['score'] ?? 0 ) / 1000.0 ),
					'engagement' => (int) ( $post['score'] ?? 0 ),
					'comments'   => (int) ( $post['num_comments'] ?? 0 ),
					'source'     => 'reddit:r/' . $sub,
				);
			}
		}

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — global Reddit fallback.
		// Prioritize English queries — Reddit is predominantly English.
		// Use t=week in fallback (broader net: daily scope too narrow for global search).
		if ( empty( $results ) ) {
			$fallback_t = ( $scope_t === 'day' ) ? 'week' : $scope_t;
			// All English queries first (simpler is better — avoid "trending 2026" filter issue),
			// then the stripped primary entity, then Vietnamese as last resort.
			$english_qs = (array) ( $plan['english_queries'] ?? array() );
			$primary_en = (string) ( $plan['primary_entity'] ?? '' );
			$fallback_queries = array_filter( array_unique( array_merge(
				$english_qs,
				$primary_en !== '' ? array( $primary_en ) : array(),
				array_slice( $plan['search_queries'], 0, 1 )
			) ) );
			foreach ( $fallback_queries as $fq ) {
				$url = 'https://www.reddit.com/search.json'
					. '?q=' . urlencode( $fq )
					// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — use $fallback_t (week) for broader results.
					. '&sort=top&t=' . $fallback_t . '&limit=6';

				$resp = wp_remote_get( $url, array(
					'timeout'    => 10,
					'user-agent' => 'BizCity TwinBrain/1.0 (trending research; +https://bizcity.vn)',
					'headers'    => array( 'Accept' => 'application/json' ),
				) );
				if ( is_wp_error( $resp ) ) { continue; }

				$body    = (string) wp_remote_retrieve_body( $resp );
				if ( substr( $body, 0, 3 ) === "\xEF\xBB\xBF" ) { $body = substr( $body, 3 ); }
				$decoded = json_decode( trim( $body ), true );

				if ( ! is_array( $decoded ) || empty( $decoded['data']['children'] ) ) { continue; }

				foreach ( $decoded['data']['children'] as $child ) {
					$post = (array) ( $child['data'] ?? array() );
					if ( empty( $post['title'] ) ) { continue; }
					$sub_name = (string) ( $post['subreddit'] ?? 'reddit' );
					$results[] = array(
						'url'        => 'https://reddit.com' . ( (string) ( $post['permalink'] ?? '' ) ),
						'title'      => (string) ( $post['title']    ?? '' ),
						'snippet'    => (string) ( $post['selftext'] ?? $post['title'] ?? '' ),
						'score'      => min( 1.0, (float) ( $post['score'] ?? 0 ) / 1000.0 ),
						'engagement' => (int) ( $post['score'] ?? 0 ),
						'comments'   => (int) ( $post['num_comments'] ?? 0 ),
						'source'     => 'reddit:r/' . $sub_name,
					);
				}
				if ( ! empty( $results ) ) { break; } // first query that yields results is enough
			}
		}

		return $results;
	}

	/**
	 * TikTok search via BizCity_Search_Client extract (crawl URL).
	 * Fallback: parse TikTok search URL via Tavily extract.
	 * Pattern: last30days lib/tiktok.py (SCRAPECREATORS_API_KEY optional).
	 */
	private function fetch_tiktok( array $plan, string $scope ): array {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) { return array(); }
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) { return array(); }

		$results  = array();
		$hashtag  = ltrim( $plan['tiktok_hashtags'][0] ?? '#trending', '#' );
		$qs       = urlencode( $plan['search_queries'][0] ?? $plan['primary_entity'] );

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — use flat array return + is_wp_error guard.
		// Use Tavily web search targeting tiktok.com
		$resp = $client->search( 'site:tiktok.com ' . $plan['search_queries'][0], 5, array(
			'include_answer' => false,
		) );

		if ( ! is_wp_error( $resp ) && is_array( $resp ) ) {
			$items = isset( $resp['results'] ) ? $resp['results'] : $resp;
			foreach ( $items as $r ) {
				if ( ! is_array( $r ) || empty( $r['url'] ) ) { continue; }
				$results[] = array(
					'url'        => (string) ( $r['url']     ?? '' ),
					'title'      => (string) ( $r['title']   ?? '' ),
					'snippet'    => (string) ( $r['content'] ?? $r['excerpt'] ?? '' ),
					'score'      => (float)  ( $r['score']   ?? 0.4 ),
					'engagement' => 0,  // views unknown without API key
					'source'     => 'tiktok',
				);
			}
		}
		return $results;
	}

	/**
	 * YouTube search (web-fallback via Tavily).
	 * Pattern: last30days lib/youtube_yt.py (yt-dlp transcript) —
	 * fallback without yt-dlp: search youtube.com via Tavily.
	 */
	private function fetch_youtube( array $plan, string $scope ): array {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) { return array(); }
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) { return array(); }

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — flat array return + is_wp_error guard.
		$resp = $client->search( 'site:youtube.com ' . $plan['search_queries'][0], 4, array(
			'include_answer' => false,
		) );

		if ( is_wp_error( $resp ) || ! is_array( $resp ) ) { return array(); }
		$items = isset( $resp['results'] ) ? $resp['results'] : $resp;
		if ( empty( $items ) ) { return array(); }

		$results = array();
		foreach ( $items as $r ) {
			if ( ! is_array( $r ) || empty( $r['url'] ) ) { continue; }
			$results[] = array(
				'url'        => (string) ( $r['url']     ?? '' ),
				'title'      => (string) ( $r['title']   ?? '' ),
				'snippet'    => (string) ( $r['content'] ?? $r['excerpt'] ?? '' ),
				'score'      => (float)  ( $r['score']   ?? 0.4 ),
				'engagement' => 0,
				'source'     => 'youtube',
			);
		}
		return $results;
	}

	/**
	 * News search (Google News RSS / Tavily).
	 * Pattern: last30days lib/web_search_keyless.py news-bias.
	 */
	private function fetch_news( array $plan, string $scope ): array {
		if ( ! class_exists( 'BizCity_Search_Client' ) ) { return array(); }
		$client = BizCity_Search_Client::instance();
		if ( ! $client->is_ready() ) { return array(); }

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — flat array return + is_wp_error guard.
		$resp = $client->search( $plan['search_queries'][0] . ' tin tức', 6, array(
			'include_answer' => false,
			'topic'          => 'news',
		) );

		if ( is_wp_error( $resp ) || ! is_array( $resp ) ) { return array(); }
		$items = isset( $resp['results'] ) ? $resp['results'] : $resp;
		if ( empty( $items ) ) { return array(); }

		$results = array();
		foreach ( $items as $r ) {
			if ( ! is_array( $r ) || empty( $r['url'] ) ) { continue; }
			$results[] = array(
				'url'        => (string) ( $r['url']     ?? '' ),
				'title'      => (string) ( $r['title']   ?? '' ),
				'snippet'    => (string) ( $r['content'] ?? $r['excerpt'] ?? '' ),
				'score'      => (float)  ( $r['score']   ?? 0.5 ),
				'engagement' => 0,
				'source'     => 'news',
			);
		}
		return $results;
	}

	// -------------------------------------------------------------------------
	// L2 · Signal Scoring + Entity Grounding
	// -------------------------------------------------------------------------

	/**
	 * Score and filter results.
	 * Pattern: last30days lib/relevance.py + lib/grounding.py.
	 *
	 * Scoring formula (mirrors last30days fusion score):
	 *   final_score = 0.6 * relevance_score + 0.4 * engagement_normalized
	 * Entity grounding: head-token of primary entity must appear in title/snippet.
	 */
	private function score_and_filter( array $results, string $topic ): array {
		// [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — scoring + grounding filter.
		$primary_words = preg_split( '/\s+/', strtolower( $topic ), 3 );
		$head_token    = $primary_words[0] ?? '';

		// Max engagement across corpus (for normalization)
		$max_eng = 1;
		foreach ( $results as $r ) {
			if ( ( $r['engagement'] ?? 0 ) > $max_eng ) { $max_eng = $r['engagement']; }
		}

		$scored = array();
		foreach ( $results as $r ) {
			if ( empty( $r['url'] ) ) { continue; }

			$eng_norm = $max_eng > 0 ? (float) ( $r['engagement'] ?? 0 ) / $max_eng : 0.0;
			$rel      = (float) ( $r['score'] ?? 0.5 );
			$final    = 0.6 * $rel + 0.4 * $eng_norm;

			// Entity grounding: decisive demotion if head token absent (pattern: grounding.py)
			if ( $head_token !== '' ) {
				$combined = strtolower( ( $r['title'] ?? '' ) . ' ' . ( $r['snippet'] ?? '' ) );
				if ( strpos( $combined, $head_token ) === false ) {
					$final *= 0.3;   // decisive demotion (same as last30days grounding.py)
				}
			}

			$r['_final_score'] = round( $final, 4 );
			$scored[] = $r;
		}

		// Sort desc by final score
		usort( $scored, function ( $a, $b ) {
			return $b['_final_score'] <=> $a['_final_score'];
		} );

		return $scored;
	}

	// -------------------------------------------------------------------------
	// L3 · Cluster Merge (deduplication)
	// -------------------------------------------------------------------------

	/**
	 * Cross-source cluster merge.
	 * Pattern: last30days lib/cluster.py + lib/dedupe.py.
	 * Simple approach: exact URL dedup + title similarity (≥70% word overlap = merge).
	 */
	private function merge_clusters( array $scored ): array {
		// [2026-06-24 Johnny Chu] PHASE-TRENDING W1 — dedup by URL then title similarity.
		$seen_urls   = array();
		$clusters    = array();

		foreach ( $scored as $item ) {
			$url = (string) ( $item['url'] ?? '' );
			if ( isset( $seen_urls[ $url ] ) ) { continue; }
			$seen_urls[ $url ] = true;

			// Title-similarity check against existing clusters
			$merged = false;
			$title  = strtolower( (string) ( $item['title'] ?? '' ) );
			$t_words = array_filter( explode( ' ', $title ) );
			foreach ( $clusters as $idx => &$cluster ) {
				$ct_words = array_filter( explode( ' ', strtolower( (string) ( $cluster['title'] ?? '' ) ) ) );
				if ( empty( $ct_words ) || empty( $t_words ) ) { continue; }
				$inter    = count( array_intersect( $t_words, $ct_words ) );
				$union    = count( array_unique( array_merge( $t_words, $ct_words ) ) );
				$jaccard  = $union > 0 ? $inter / $union : 0;
				if ( $jaccard >= 0.55 ) {
					// Merge: keep higher-scoring item's metadata, append source
					if ( $item['_final_score'] > $cluster['_final_score'] ) {
						$cluster = array_merge( $cluster, $item );
					}
					$cluster['_sources'] = array_unique( array_merge(
						(array) ( $cluster['_sources'] ?? array( $cluster['source'] ?? 'web' ) ),
						array( $item['source'] ?? 'web' )
					) );
					$merged = true;
					break;
				}
			}
			unset( $cluster );

			if ( ! $merged ) {
				$item['_sources'] = array( $item['source'] ?? 'web' );
				$clusters[]       = $item;
			}
		}

		return array_slice( $clusters, 0, 20 );
	}

	// -------------------------------------------------------------------------
	// L4 · LLM Synthesis
	// -------------------------------------------------------------------------

	/**
	 * LLM synthesis — ranked evidence → prose.
	 * Pattern: last30days §OUTPUT CONTRACT + Voice Contract LAWs 1-10.
	 *
	 * Synthesis prompt mirrors last30days SKILL.md Output Contract:
	 *   - "What I learned:" opener (LAW 2 equivalent)
	 *   - Bold lead-in paragraphs (no ## section headers — LAW 4 equivalent)
	 *   - KEY PATTERNS numbered list at end (LAW 4)
	 *   - No trailing "Sources:" block (LAW 1)
	 *   - Inline [title](url) citations (LAW 8)
	 */
	private function synthesize( string $topic, array $clusters, array $plan, string $language, string $output ): array {
		if ( empty( $clusters ) ) {
			return $this->_empty_synthesis( $topic );
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return $this->_fallback_synthesis( $topic, $clusters );
		}

		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm->is_ready() ) {
			return $this->_fallback_synthesis( $topic, $clusters );
		}

		// Build evidence block (mirrors last30days <!-- EVIDENCE FOR SYNTHESIS --> format)
		$evidence = $this->build_evidence_block( $clusters, $plan );
		$lang_instruction = $language === 'vi'
			? 'Viết bằng tiếng Việt. Các từ kỹ thuật hoặc tên riêng giữ nguyên tiếng Anh.'
			: 'Write in English.';

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — 4-section output for Zalo multi-message reply.
		// LLM must output exactly 4 sections delimited by ===MARKER=== on their own lines.
		// parse_four_sections() splits them into msg_1..msg_4 for chained reply_zalo nodes.
		$system = "Bạn là nhà phân tích xu hướng mạng xã hội. Tổng hợp evidence thành BỐN PHẦN RÕ RÀNG.\n\n"
			. "FORMAT BẮT BUỘC — sao chép CHÍNH XÁC 4 marker dưới đây, mỗi marker trên 1 dòng riêng:\n\n"
			. "===RESEARCH===\n"
			. "Điều tôi tìm hiểu được: [câu mở đầu 1-2 câu]\n\n"
			. "**[Chủ điểm 1]** [1-2 câu kèm citation [source:N]]\n\n"
			. "**[Chủ điểm 2]** [1-2 câu kèm citation]\n\n"
			. "**[Chủ điểm 3 nếu có]** [1-2 câu]\n\n"
			. "===SOURCES===\n"
			. "[Danh sách plain text — KHÔNG markdown — mỗi dòng: N. [platform] Tên bài viết]\n\n"
			. "===ANALYSIS===\n"
			. "Đồng thuận:\n- [điểm 1]\n- [điểm 2]\n\n"
			. "Khác biệt:\n- [điểm 1]\n- [điểm 2]\n\n"
			. "===PATTERNS===\n"
			. "Key patterns:\n1. [pattern]\n2. [pattern]\n3. [pattern]\n4. [pattern]\n5. [pattern]\n\n"
			. "Kết luận: [1-2 câu tổng kết]\n\n"
			. "RULES: {$lang_instruction} "
			. "Citations [source:N] hoặc [reddit:N]. "
			. "SOURCES: plain text KHÔNG có URL dài, KHÔNG markdown. "
			. "KHÔNG bịa đặt thông tin ngoài evidence. "
			. "KHÔNG thêm section nào ngoài 4 marker trên.";

		$user_msg = "Chủ đề: {$topic}\nThời gian: {$plan['scope_days']} ngày gần nhất\n\n"
			. "<!-- EVIDENCE -->\n{$evidence}\n<!-- END EVIDENCE -->";

		try {
			$resp = $llm->chat(
				array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user',   'content' => $user_msg ),
				),
				array(
					'purpose'     => 'twinbrain_trending',
					'max_tokens'  => 1400,
					'temperature' => 0.3,
					'timeout'     => self::LLM_TIMEOUT_S,
				)
			);

			$answer_md = trim( (string) ( $resp['message'] ?? '' ) );
			if ( $answer_md === '' ) {
				return $this->_fallback_synthesis( $topic, $clusters );
			}

			// Extract KEY PATTERNS into structured array (for output var)
			$key_patterns = $this->extract_key_patterns( $answer_md );

			// Build sources_text (Zalo-safe: no markdown links)
			$sources_text = $this->build_sources_text( $clusters );

			// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — parse 4 Zalo message chunks.
			$chunks = $this->parse_four_sections( $answer_md, $topic, $clusters );

			return array(
				'answer_md'    => $answer_md,
				'key_patterns' => $key_patterns,
				'sources_text' => $sources_text,
				'msg_1'        => $chunks['msg_1'],
				'msg_2'        => $chunks['msg_2'],
				'msg_3'        => $chunks['msg_3'],
				'msg_4'        => $chunks['msg_4'],
			);
		} catch ( \Throwable $e ) {
			return $this->_fallback_synthesis( $topic, $clusters );
		}
	}

	// -------------------------------------------------------------------------
	// Helper — Evidence block builder
	// -------------------------------------------------------------------------

	/**
	 * Build ranked evidence block for synthesis context.
	 * Pattern: last30days lib/render.py (--emit=compact evidence format).
	 */
	private function build_evidence_block( array $clusters, array $plan ): string {
		$lines = array();
		$lines[] = '## Ranked Evidence Clusters (Top ' . count( $clusters ) . ')';
		$lines[] = '';

		foreach ( array_slice( $clusters, 0, self::MAX_SYNTHESIS_SOURCES ) as $i => $c ) {
			$idx      = $i + 1;
			$title    = (string) ( $c['title']   ?? '' );
			$url      = (string) ( $c['url']     ?? '' );
			$snippet  = mb_substr( (string) ( $c['snippet'] ?? '' ), 0, 300 );
			$score    = number_format( (float) ( $c['_final_score'] ?? 0 ), 3 );
			$eng      = (int) ( $c['engagement'] ?? 0 );
			$sources  = implode( '+', (array) ( $c['_sources'] ?? array( $c['source'] ?? 'web' ) ) );

			$lines[] = "### {$idx}. {$title} (score {$score}, sources: {$sources}" . ( $eng > 0 ? ", engagement:{$eng}" : '' ) . ')';
			if ( $url !== '' ) { $lines[] = "   URL: {$url}"; }
			if ( $snippet !== '' ) { $lines[] = "   Snippet: {$snippet}"; }
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Fallback synthesis when LLM unavailable — plain text from top clusters.
	 * Pattern: last30days lib/quality_nudge.py (degraded-run fallback).
	 */
	private function _fallback_synthesis( string $topic, array $clusters ): array {
		$lines = array( "Điều tôi tìm hiểu được về **{$topic}**:" );

		foreach ( array_slice( $clusters, 0, 5 ) as $c ) {
			$title   = (string) ( $c['title']   ?? '' );
			$snippet = mb_substr( (string) ( $c['snippet'] ?? '' ), 0, 200 );
			$url     = (string) ( $c['url']     ?? '' );
			if ( $title === '' ) { continue; }
			$lines[] = '';
			$lines[] = '**' . $title . '**' . ( $url ? ' — [xem](' . $url . ')' : '' );
			if ( $snippet ) { $lines[] = $snippet; }
		}

		$key_patterns = array_map( function ( $c ) {
			return (string) ( $c['title'] ?? '' );
		}, array_slice( $clusters, 0, 5 ) );
		$key_patterns = array_filter( $key_patterns );
		$answer_text  = implode( "\n", $lines );

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — build msg_1..4 from fallback text.
		$chunks = $this->parse_four_sections( $answer_text, $topic, $clusters );

		return array(
			'answer_md'    => $answer_text,
			'key_patterns' => array_values( $key_patterns ),
			'sources_text' => $this->build_sources_text( $clusters ),
			'msg_1'        => (string) ( $chunks['msg_1'] ?? '' ),
			'msg_2'        => (string) ( $chunks['msg_2'] ?? '' ),
			'msg_3'        => (string) ( $chunks['msg_3'] ?? '' ),
			'msg_4'        => (string) ( $chunks['msg_4'] ?? '' ),
		);
	}

	private function _empty_synthesis( string $topic ): array {
		$msg = "Không tìm thấy kết quả nào cho chủ đề **{$topic}** trong khoảng thời gian đã chọn.";
		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — include 4 chunks even for empty result.
		return array(
			'answer_md'    => $msg,
			'key_patterns' => array(),
			'sources_text' => '',
			'msg_1'        => "📊 XU HƯỚNG: {$topic}\n────────────────────\n" . $msg,
			'msg_2'        => "🔗 NGUỒN THAM KHẢO (0 nguồn):\n(Không có nguồn)",
			'msg_3'        => "💡 PHÂN TÍCH:\n(Không đủ dữ liệu để phân tích)",
			'msg_4'        => "✅ KẺT LUẬN:\n(Không tìm thấy kết quả)",
		);
	}

	/**
	 * Extract numbered KEY PATTERNS from answer_md.
	 * Looks for lines matching "1. ...", "2. ..." after the KEY PATTERNS label.
	 */
	private function extract_key_patterns( string $md ): array {
		$patterns = array();
		$in_list  = false;

		foreach ( explode( "\n", $md ) as $line ) {
			$line = trim( $line );
			if ( stripos( $line, 'KEY PATTERNS' ) !== false ) {
				$in_list = true;
				continue;
			}
			if ( $in_list && preg_match( '/^\d+\.\s+(.+)/', $line, $m ) ) {
				$patterns[] = trim( $m[1] );
			} elseif ( $in_list && $line === '' ) {
				// empty line OK, continue
			} elseif ( $in_list && $line !== '' && ! preg_match( '/^\d+\./', $line ) ) {
				break;  // end of list
			}
		}

		return $patterns;
	}

	/**
	 * Build Zalo-safe plain-text source list (no markdown).
	 * Pattern: last30days lib/render.py (sources_text for non-streaming channels).
	 */
	private function build_sources_text( array $clusters ): string {
		$lines = array( 'Nguồn tham khảo:' );
		foreach ( array_slice( $clusters, 0, 8 ) as $i => $c ) {
			$title   = (string) ( $c['title']   ?? '' );
			$url     = (string) ( $c['url']     ?? '' );
			$sources = implode( '+', (array) ( $c['_sources'] ?? array( $c['source'] ?? 'web' ) ) );
			if ( $title === '' && $url === '' ) { continue; }
			$n = $i + 1;
			$lines[] = "{$n}. [{$sources}] " . ( $title ?: $url );
		}
		return implode( "\n", $lines );
	}

	private function format_source_item( array $c ): array {
		return array(
			'url'        => (string) ( $c['url']          ?? '' ),
			'title'      => (string) ( $c['title']        ?? '' ),
			'source'     => (string) ( $c['source']       ?? 'web' ),
			'score'      => round( (float) ( $c['_final_score'] ?? 0 ), 3 ),
			'engagement' => (int) ( $c['engagement']      ?? 0 ),
		);
	}

	// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — parse 4-section LLM output into Zalo message chunks.
	// LLM is prompted to output ===RESEARCH=== ===SOURCES=== ===ANALYSIS=== ===PATTERNS=== markers.
	private function parse_four_sections( string $text, string $topic, array $clusters ): array {
		$sections = array( 'RESEARCH' => '', 'SOURCES' => '', 'ANALYSIS' => '', 'PATTERNS' => '' );
		$current  = null;

		foreach ( explode( "\n", $text ) as $line ) {
			$trimmed = trim( $line );
			if ( preg_match( '/^===([A-Z]+)===$/', $trimmed, $m ) && isset( $sections[ $m[1] ] ) ) {
				$current = $m[1];
			} elseif ( null !== $current ) {
				$sections[ $current ] .= $line . "\n";
			}
		}
		foreach ( $sections as $k => &$v ) { $v = trim( $v ); }
		unset( $v );

		// Fallback: if no markers found, put full text in RESEARCH.
		$has_markers = ( $sections['RESEARCH'] !== '' );

		// SOURCES: if LLM section empty, build from clusters.
		if ( $sections['SOURCES'] === '' ) {
			$sections['SOURCES'] = $this->build_sources_text( $clusters );
		}

		$n   = count( $clusters );
		$sep = "\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}\u{2500}";

		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — actual UTF-8 emoji + Vietnamese (was \uXXXX literal bug).
		return array(
			'msg_1' => "📊 XU HƯỚNG: {$topic}\n{$sep}\n" . ( $has_markers ? $sections['RESEARCH'] : $text ),
			'msg_2' => "🔗 NGUỒN THAM KHẢO ({$n} nguồn):\n" . $sections['SOURCES'],
			'msg_3' => "💡 PHÂN TÍCH:\n" . ( $sections['ANALYSIS'] !== '' ? $sections['ANALYSIS'] : '(Không đủ dữ liệu)' ),
			'msg_4' => "✅ KẾT LUẬN:\n" . ( $sections['PATTERNS'] !== '' ? $sections['PATTERNS'] : '(Không có Key Patterns)' ),
		);
	}

	// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 — map Vietnamese topic to English hint for Reddit queries.
	private function topic_to_english_hint( string $primary ): string {
		// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — actual UTF-8 keys (was \uXXXX literal, never matched).
		$map = array(
			'dinh dưỡng'  => 'nutrition',
			'ăn uống'    => 'food diet',
			'thực phẩm' => 'food',
			'sức khỏe'  => 'health wellness',
			'sức khoẻ'  => 'health wellness',
			'mạng xã hội' => 'social media',
			'công nghệ' => 'technology',
			'trí tuệ'  => 'artificial intelligence AI',
			' ai '          => 'artificial intelligence',
			'tài chính' => 'personal finance',
			'chứng khoán' => 'stock market',
			'đầu tư' => 'investing',
			'bitcoin'       => 'bitcoin cryptocurrency',
			'crypto'        => 'cryptocurrency',
			'thể thao'  => 'sports',
			'bóng đá' => 'football soccer',
			'âm nhạc'   => 'music',
			'phim'          => 'movies film',
			'giải trí' => 'entertainment',
			'kinh doanh'    => 'business',
			'startup'       => 'startup entrepreneur',
			'giáo dục' => 'education',
			'du lịch'   => 'travel',
			'thời trang' => 'fashion',
			'làm đẹp' => 'beauty skincare',
			'tiktok'        => 'TikTok social',
			'facebook'      => 'Facebook social media',
		);
		$t = mb_strtolower( $primary );
		foreach ( $map as $vi => $en ) {
			if ( false !== mb_strpos( $t, $vi ) ) { return $en; }
		}
		return $primary; // Fallback: use as-is (works for English topics)
	}

	// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — topic-to-subreddit mapping.
	// Returns 2-3 subreddits most likely to have content for the given topic.
	private function detect_subreddits( string $primary, string $topic ): array {
		$t = mb_strtolower( $primary . ' ' . $topic );

		// Nutrition / food / health
		if ( $this->_kw( $t, array( 'dinh dưỡng', 'ăn uống', 'thực phẩm', 'ăn kiêng', 'nutrition', 'food', 'diet', 'healthy', 'sức khỏe', 'health' ) ) ) {
			return array( 'nutrition', 'HealthyFood', 'Fitness' );
		}
		// Tech / AI / software
		if ( $this->_kw( $t, array( 'công nghệ', 'phần mềm', 'điện thoại', 'máy tính', 'ai ', 'trí tuệ', 'tech', 'software', 'hardware', 'programming', 'app', 'smartphone' ) ) ) {
			return array( 'technology', 'artificial', 'MachineLearning' );
		}
		// Finance / crypto / investing
		if ( $this->_kw( $t, array( 'tài chính', 'chứng khoán', 'đầu tư', 'tiền', 'crypto', 'bitcoin', 'finance', 'investing', 'stock', 'market' ) ) ) {
			return array( 'personalfinance', 'investing', 'CryptoCurrency' );
		}
		// Social media / marketing
		if ( $this->_kw( $t, array( 'mạng xã hội', 'social', 'marketing', 'quảng cáo', 'content', 'brand', 'influencer' ) ) ) {
			return array( 'socialmedia', 'marketing', 'TikTok' );
		}
		// TikTok specific
		if ( $this->_kw( $t, array( 'tiktok' ) ) ) {
			return array( 'TikTok', 'tiktokmarketing', 'socialmedia' );
		}
		// Entertainment / movies / music
		if ( $this->_kw( $t, array( 'giải trí', 'phim', 'âm nhạc', 'music', 'movie', 'netflix', 'game', 'gaming', 'nhạc', 'ca sĩ' ) ) ) {
			return array( 'movies', 'Music', 'entertainment' );
		}
		// Sports
		if ( $this->_kw( $t, array( 'thể thao', 'bóng đá', 'football', 'soccer', 'sport', 'athlete', 'olympic' ) ) ) {
			return array( 'soccer', 'sports', 'worldnews' );
		}
		// Politics / news
		if ( $this->_kw( $t, array( 'chính trị', 'news', 'tin tức', 'thời sự', 'bầu cử', 'politics', 'government' ) ) ) {
			return array( 'worldnews', 'news', 'geopolitics' );
		}
		// Business / startup
		if ( $this->_kw( $t, array( 'kinh doanh', 'startup', 'business', 'entrepreneur', 'doanh nghiệp' ) ) ) {
			return array( 'business', 'entrepreneur', 'startups' );
		}
		// Default: popular (high-traffic, always has results) + Vietnam
		return array( 'popular', 'VietNam' );
	}

	/** Keyword helper: returns true if $text contains any of $keywords. */
	private function _kw( string $text, array $keywords ): bool {
		foreach ( $keywords as $kw ) {
			if ( false !== mb_strpos( $text, $kw ) ) { return true; }
		}
		return false;
	}

	// [2026-06-25 Johnny Chu] PHASE-TRENDING W1 FIX — add $topic + msg_1..4 so reply_zalo resolves vars (not literal text).
	private function _empty_result( string $error, float $t0 = 0.0, string $topic = '' ): array {
		$label = $topic !== '' ? $topic : '(không rõ)';
		$note  = 'no_results' === $error
			? 'Không tìm thấy dữ liệu mới về chủ đề này. Hãy thử lại sau hoặc đổi từ khoá.'
			: 'Chủ đề trống — vui lòng nhập nội dung cần tìm.';
		return array(
			'answer_md'      => '',
			'key_patterns'   => array(),
			'sources_text'   => '',
			'top_sources'    => array(),
			'source_count'   => 0,
			'scope'          => '1d',
			'platforms_used' => '',
			'ok'             => false,
			'ms'             => $t0 > 0 ? (int) round( ( microtime( true ) - $t0 ) * 1000 ) : 0,
			'error'          => $error,
			'msg_1'          => "📊 XU HƯỚNG: {$label}\n────────────────────\n{$note}",
			'msg_2'          => "🔗 NGUỒN THAM KHẢO (0 nguồn):\n(Không tìm thấy nguồn)",
			'msg_3'          => "💡 PHÂN TÍCH:\n(Không đủ dữ liệu để phân tích)",
			'msg_4'          => "✅ KẾT LUẬN:\nVui lòng thử lại sau hoặc thay đổi từ khoá.",
		);
	}
}
