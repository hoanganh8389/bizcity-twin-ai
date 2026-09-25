<?php
/**
 * Bot Studio — public research lookups (PHASE-0.60K K5).
 *
 * Six no-key HTTP clients ported from Libe-Zalo's search tools (arXiv, Semantic Scholar → Crossref → PubMed, GitHub,
 * StackExchange, Hacker News, Wikipedia). Every method returns the same shape:
 *
 *     array{ ok: bool, items: array<int,array{title,url,date,source,snippet}>, error: string, note?: string }
 *
 * An EMPTY result is `ok:false, error:'no_results'` — the model must know nothing was found (Libe-Zalo: empty = failure),
 * unlike "an empty calendar", which is a success. Failures are objects, never strings, so the runner's repeat guard can
 * count them (Libe-Zalo bug: "8 URL fail mà guard không chặn").
 *
 * Everything that comes back is third-party text: BizCity_Bot_Tools formats and FENCES it before the model sees it.
 * Nothing here sends the site admin's email or any customer data to those services.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 */

// [2026-09-24 Claude Sonnet 5] PHASE-0.60K K5
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Research_Client {

	const CACHE_TTL      = 21600; // 6 h — public search results barely move within a chat.
	const CACHE_FAIL_TTL = 300;
	const MAX_QUERY      = 300;
	const DEFAULT_COUNT  = 8;
	const MAX_COUNT      = 15;

	/** Tool id => [method, label]. */
	const TOOLS = array(
		'search_arxiv'         => array( 'arxiv', 'arXiv' ),
		'search_scholar'       => array( 'scholar', 'bài báo khoa học' ),
		'search_github'        => array( 'github', 'GitHub' ),
		'search_stackexchange' => array( 'stackexchange', 'StackExchange' ),
		'search_hackernews'    => array( 'hackernews', 'Hacker News' ),
		'search_wikipedia'     => array( 'wikipedia', 'Wikipedia' ),
	);

	/** @var callable|null test seam: fn(string $url, array $headers, int $timeout): array{code:int,body:string,headers:array}|WP_Error */
	public static $http = null;
	/** @var int test/diagnostic counter of real (uncached) requests. */
	public static $requests = 0;

	/* ── entry ─────────────────────────────────────────────────────────── */

	/**
	 * Run one tool with the model's args. Validates, caches, never throws.
	 *
	 * @return array{ok:bool,items:array,error:string,label?:string,query?:string,note?:string}
	 */
	public static function run( string $tool_id, array $args ): array {
		if ( ! isset( self::TOOLS[ $tool_id ] ) ) {
			return self::fail( 'tool_unknown' );
		}
		$query = trim( (string) preg_replace( '/\s+/u', ' ', (string) ( $args['query'] ?? $args['q'] ?? '' ) ) );
		if ( '' === $query ) {
			return self::fail( 'query_required' );
		}
		$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, self::MAX_QUERY ) : substr( $query, 0, self::MAX_QUERY );
		$count = max( 1, min( self::MAX_COUNT, (int) ( $args['count'] ?? self::DEFAULT_COUNT ) ) );
		$lang  = 'search_wikipedia' === $tool_id ? self::lang( (string) ( $args['lang'] ?? 'auto' ) ) : '';
		if ( 'search_wikipedia' === $tool_id ) {
			$count = min( 12, $count );
		}
		$key    = 'bzbot_research_' . ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0 ) . '_' . md5( $tool_id . '|' . $query . '|' . $count . '|' . $lang );
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		if ( is_array( $cached ) ) {
			return $cached + array( 'label' => self::TOOLS[ $tool_id ][1], 'query' => $query );
		}
		$method = self::TOOLS[ $tool_id ][0];
		try {
			$res = 'wikipedia' === $method ? self::wikipedia( $query, $count, $lang ) : self::$method( $query, $count );
		} catch ( \Throwable $e ) {
			$res = self::fail( 'lookup_exception' );
		}
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $res, $res['ok'] ? self::CACHE_TTL : self::CACHE_FAIL_TTL );
		}
		return $res + array( 'label' => self::TOOLS[ $tool_id ][1], 'query' => $query );
	}

	/* ── the six sources ───────────────────────────────────────────────── */

	public static function arxiv( string $query, int $count ): array {
		$is_id = (bool) preg_match( '/^(?:arxiv:)?(\d{4}\.\d{4,5}|[a-z\-]+(?:\.[A-Z]{2})?\/\d{7})(v\d+)?$/i', $query, $m );
		$url   = $is_id
			? 'https://export.arxiv.org/api/query?id_list=' . rawurlencode( preg_replace( '/^arxiv:/i', '', $query ) )
			: 'https://export.arxiv.org/api/query?search_query=' . rawurlencode( 'all:"' . str_replace( '"', '', $query ) . '"' ) . '&max_results=' . $count . '&sortBy=relevance';
		$res = self::get( $url, array(), 12 );
		if ( ! $res['ok'] ) {
			return self::fail( $res['error'] );
		}
		$prev = libxml_use_internal_errors( true );
		$xml  = simplexml_load_string( $res['body'], 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			return self::fail( 'bad_response' );
		}
		$items = array();
		foreach ( $xml->entry as $entry ) {
			$authors = array();
			foreach ( $entry->author as $a ) {
				$authors[] = trim( (string) $a->name );
			}
			$items[] = self::item(
				(string) $entry->title,
				(string) $entry->id,
				substr( (string) $entry->published, 0, 10 ),
				'arXiv',
				self::cut( trim( implode( ', ', array_slice( $authors, 0, 3 ) ) . ( $authors ? ' — ' : '' ) . (string) $entry->summary ), 400 )
			);
			if ( count( $items ) >= $count ) {
				break;
			}
		}
		return self::done( $items );
	}

	/** Semantic Scholar, then Crossref, then PubMed — the next source only when the previous one is empty or failed. */
	public static function scholar( string $query, int $count ): array {
		$last = 'no_results';
		foreach ( array( 'scholar_semantic', 'scholar_crossref', 'scholar_pubmed' ) as $source ) {
			$res = self::$source( $query, $count );
			if ( $res['ok'] ) {
				return $res;
			}
			$last = $res['error'];
		}
		return self::fail( $last );
	}

	private static function scholar_semantic( string $query, int $count ): array {
		$res = self::get( 'https://api.semanticscholar.org/graph/v1/paper/search?query=' . rawurlencode( $query ) . '&limit=' . $count . '&fields=title,year,authors,externalIds,url,abstract', array(), 12 );
		if ( ! $res['ok'] ) {
			return self::fail( $res['error'] );
		}
		$json  = json_decode( $res['body'], true );
		$items = array();
		foreach ( (array) ( $json['data'] ?? array() ) as $p ) {
			$names   = array_map( static function ( $a ) { return (string) ( $a['name'] ?? '' ); }, array_slice( (array) ( $p['authors'] ?? array() ), 0, 3 ) );
			$doi     = (string) ( $p['externalIds']['DOI'] ?? '' );
			$items[] = self::item( (string) ( $p['title'] ?? '' ), '' !== $doi ? 'https://doi.org/' . $doi : (string) ( $p['url'] ?? '' ), (string) ( $p['year'] ?? '' ), 'Semantic Scholar', self::cut( trim( implode( ', ', array_filter( $names ) ) . ' — ' . (string) ( $p['abstract'] ?? '' ), ' —' ), 400 ) );
		}
		return self::done( $items );
	}

	private static function scholar_crossref( string $query, int $count ): array {
		// `mailto` puts the request in Crossref's polite pool; it is the SITE'S OWN contact, empty (nothing sent) unless a
		// developer opts in through the filter — the admin email is never sent to a third party by default.
		$mail = function_exists( 'apply_filters' ) ? (string) apply_filters( 'bizcity_bot_research_contact_email', '' ) : '';
		$url  = 'https://api.crossref.org/works?query=' . rawurlencode( $query ) . '&rows=' . $count . '&select=title,DOI,author,issued,container-title' . ( '' !== $mail ? '&mailto=' . rawurlencode( $mail ) : '' );
		$res  = self::get( $url, array(), 12 );
		if ( ! $res['ok'] ) {
			return self::fail( $res['error'] );
		}
		$json  = json_decode( $res['body'], true );
		$items = array();
		foreach ( (array) ( $json['message']['items'] ?? array() ) as $w ) {
			$names   = array();
			foreach ( array_slice( (array) ( $w['author'] ?? array() ), 0, 3 ) as $a ) {
				$names[] = trim( (string) ( $a['given'] ?? '' ) . ' ' . (string) ( $a['family'] ?? '' ) );
			}
			$doi     = (string) ( $w['DOI'] ?? '' );
			$items[] = self::item( (string) ( $w['title'][0] ?? '' ), '' !== $doi ? 'https://doi.org/' . $doi : '', (string) ( $w['issued']['date-parts'][0][0] ?? '' ), 'Crossref', self::cut( implode( ', ', array_filter( $names ) ) . ( ! empty( $w['container-title'][0] ) ? ' — ' . $w['container-title'][0] : '' ), 400 ) );
		}
		return self::done( $items );
	}

	private static function scholar_pubmed( string $query, int $count ): array {
		$search = self::get( 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&retmode=json&retmax=' . $count . '&term=' . rawurlencode( $query ), array(), 12 );
		if ( ! $search['ok'] ) {
			return self::fail( $search['error'] );
		}
		$ids = array_values( array_filter( array_map( 'strval', (array) ( json_decode( $search['body'], true )['esearchresult']['idlist'] ?? array() ) ), 'ctype_digit' ) );
		if ( empty( $ids ) ) {
			return self::fail( 'no_results' );
		}
		$sum = self::get( 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esummary.fcgi?db=pubmed&retmode=json&id=' . implode( ',', $ids ), array(), 12 );
		if ( ! $sum['ok'] ) {
			return self::fail( $sum['error'] );
		}
		$result = (array) ( json_decode( $sum['body'], true )['result'] ?? array() );
		$items  = array();
		foreach ( $ids as $id ) {
			$p = (array) ( $result[ $id ] ?? array() );
			if ( empty( $p['title'] ) ) {
				continue;
			}
			$names   = array_map( static function ( $a ) { return (string) ( $a['name'] ?? '' ); }, array_slice( (array) ( $p['authors'] ?? array() ), 0, 3 ) );
			$items[] = self::item( (string) $p['title'], 'https://pubmed.ncbi.nlm.nih.gov/' . $id . '/', substr( (string) ( $p['pubdate'] ?? '' ), 0, 4 ), 'PubMed', self::cut( implode( ', ', array_filter( $names ) ) . ( ! empty( $p['source'] ) ? ' — ' . $p['source'] : '' ), 400 ) );
		}
		return self::done( $items );
	}

	public static function github( string $query, int $count ): array {
		$kind = preg_match( '/\b(issues?|bug|lỗi|loi)\b/iu', $query ) ? 'issues' : 'repositories';
		$q    = 'issues' === $kind ? trim( (string) preg_replace( '/\b(issues?|bug|lỗi|loi)\b/iu', '', $query ) ) : $query;
		$res  = self::get( 'https://api.github.com/search/' . $kind . '?per_page=' . $count . '&q=' . rawurlencode( '' !== $q ? $q : $query ), array( 'Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28' ), 10 );
		if ( ! $res['ok'] ) {
			// No key: 10 searches/minute/IP, and shared hosting shares the IP — say so instead of "failed".
			if ( in_array( $res['code'], array( 403, 429 ), true ) && ( '0' === (string) ( $res['headers']['x-ratelimit-remaining'] ?? '' ) || 429 === $res['code'] ) ) {
				return self::fail( 'rate_limited', array( 'note' => 'GitHub giới hạn tra cứu không khóa — thử lại sau ít phút.' ) );
			}
			return self::fail( $res['error'] );
		}
		$items = array();
		foreach ( (array) ( json_decode( $res['body'], true )['items'] ?? array() ) as $r ) {
			$is_issue = 'issues' === $kind;
			$items[]  = self::item(
				(string) ( $is_issue ? ( $r['title'] ?? '' ) : ( $r['full_name'] ?? '' ) ),
				(string) ( $r['html_url'] ?? '' ),
				substr( (string) ( $r['updated_at'] ?? '' ), 0, 10 ),
				'GitHub',
				self::cut( $is_issue ? ( (string) ( $r['state'] ?? '' ) . ' · ' . (string) ( $r['body'] ?? '' ) ) : ( '★' . (int) ( $r['stargazers_count'] ?? 0 ) . ' · ' . (string) ( $r['description'] ?? '' ) ), 300 )
			);
		}
		return self::done( $items );
	}

	public static function stackexchange( string $query, int $count ): array {
		$site = 'stackoverflow';
		if ( preg_match( '/\b(windows|macos|mac os|excel|word|chrome|firefox|ubuntu desktop)\b/i', $query ) ) {
			$site = 'superuser';
		} elseif ( preg_match( '/\b(nginx|apache|server|docker|kubernetes|linux|dns|vps|ssh|iptables)\b/i', $query ) ) {
			$site = 'serverfault';
		}
		$res = self::get( 'https://api.stackexchange.com/2.3/search/advanced?order=desc&sort=relevance&site=' . $site . '&pagesize=' . $count . '&q=' . rawurlencode( $query ), array(), 10 );
		if ( ! $res['ok'] ) {
			return self::fail( $res['error'] );
		}
		$items = array();
		foreach ( (array) ( json_decode( $res['body'], true )['items'] ?? array() ) as $q ) {
			$items[] = self::item(
				html_entity_decode( (string) ( $q['title'] ?? '' ), ENT_QUOTES, 'UTF-8' ),
				(string) ( $q['link'] ?? '' ),
				! empty( $q['creation_date'] ) ? gmdate( 'Y-m-d', (int) $q['creation_date'] ) : '',
				$site,
				( ! empty( $q['is_answered'] ) ? 'đã có câu trả lời' : 'chưa có câu trả lời' ) . ' · ' . (int) ( $q['score'] ?? 0 ) . ' điểm · ' . (int) ( $q['answer_count'] ?? 0 ) . ' trả lời'
			);
		}
		return self::done( $items );
	}

	public static function hackernews( string $query, int $count ): array {
		$res = self::get( 'https://hn.algolia.com/api/v1/search?tags=story&hitsPerPage=' . $count . '&query=' . rawurlencode( $query ), array(), 10 );
		if ( ! $res['ok'] ) {
			return self::fail( $res['error'] );
		}
		$items = array();
		foreach ( (array) ( json_decode( $res['body'], true )['hits'] ?? array() ) as $h ) {
			$id      = (string) ( $h['objectID'] ?? '' );
			$items[] = self::item(
				(string) ( $h['title'] ?? '' ),
				! empty( $h['url'] ) ? (string) $h['url'] : ( '' !== $id ? 'https://news.ycombinator.com/item?id=' . rawurlencode( $id ) : '' ),
				substr( (string) ( $h['created_at'] ?? '' ), 0, 10 ),
				'Hacker News',
				(int) ( $h['points'] ?? 0 ) . ' điểm · ' . (int) ( $h['num_comments'] ?? 0 ) . ' bình luận'
			);
		}
		return self::done( $items );
	}

	/** `auto` = Vietnamese first, English merged in only when Vietnamese has fewer than 2 hits. Duplicate URLs dropped. */
	public static function wikipedia( string $query, int $count, string $lang = 'auto' ): array {
		$order = 'auto' === $lang ? array( 'vi', 'en' ) : array( $lang );
		$items = array();
		$seen  = array();
		$last  = 'no_results';
		foreach ( $order as $i => $l ) {
			if ( 1 === $i && count( $items ) >= 2 ) {
				break; // Vietnamese was enough.
			}
			$got = self::wiki_one( $l, $query, $count );
			if ( ! $got['ok'] ) {
				$last = $got['error'];
				continue;
			}
			foreach ( $got['items'] as $it ) {
				if ( '' !== $it['url'] && ! isset( $seen[ $it['url'] ] ) ) {
					$seen[ $it['url'] ] = true;
					$items[]            = $it;
				}
			}
		}
		return empty( $items ) ? self::fail( $last ) : self::done( array_slice( $items, 0, $count ) );
	}

	private static function wiki_one( string $lang, string $query, int $count ): array {
		$res = self::get( 'https://' . $lang . '.wikipedia.org/w/api.php?action=query&list=search&format=json&utf8=1&srlimit=' . $count . '&srsearch=' . rawurlencode( $query ), array(), 10 );
		if ( ! $res['ok'] ) {
			return self::fail( $res['error'] );
		}
		$items = array();
		foreach ( (array) ( json_decode( $res['body'], true )['query']['search'] ?? array() ) as $s ) {
			$title   = (string) ( $s['title'] ?? '' );
			$items[] = self::item( $title, 'https://' . $lang . '.wikipedia.org/wiki/' . rawurlencode( str_replace( ' ', '_', $title ) ), substr( (string) ( $s['timestamp'] ?? '' ), 0, 10 ), 'Wikipedia ' . $lang, self::cut( html_entity_decode( strip_tags( (string) ( $s['snippet'] ?? '' ) ), ENT_QUOTES, 'UTF-8' ), 300 ) );
		}
		return self::done( $items );
	}

	/* ── plumbing ──────────────────────────────────────────────────────── */

	/** @return array{ok:bool,code:int,body:string,headers:array,error:string} */
	private static function get( string $url, array $headers, int $timeout ): array {
		self::$requests++;
		// GitHub REQUIRES a User-Agent and Wikimedia asks for a descriptive one with a contact URL.
		$headers += array( 'User-Agent' => 'BizCityBot/1.0 (+' . ( function_exists( 'home_url' ) ? home_url( '/' ) : 'https://bizcity.vn/' ) . ')' );
		$res = is_callable( self::$http )
			? call_user_func( self::$http, $url, $headers, $timeout )
			: ( function_exists( 'wp_remote_get' ) ? wp_remote_get( $url, array( 'timeout' => $timeout, 'headers' => $headers, 'redirection' => 3, 'limit_response_size' => 1048576 ) ) : new \WP_Error( 'no_http', 'no http' ) );
		if ( is_object( $res ) && function_exists( 'is_wp_error' ) && is_wp_error( $res ) ) {
			$msg = strtolower( (string) $res->get_error_message() );
			return array( 'ok' => false, 'code' => 0, 'body' => '', 'headers' => array(), 'error' => false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ? 'timeout' : 'http_error' );
		}
		if ( is_array( $res ) && isset( $res['response'] ) ) { // a real wp_remote_get() array
			$res = array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ), 'headers' => array_change_key_case( (array) ( is_object( $res['headers'] ?? null ) && method_exists( $res['headers'], 'getAll' ) ? $res['headers']->getAll() : ( $res['headers'] ?? array() ) ), CASE_LOWER ) );
		}
		$code = (int) ( $res['code'] ?? 0 );
		return array(
			'ok'      => 200 === $code,
			'code'    => $code,
			'body'    => (string) ( $res['body'] ?? '' ),
			'headers' => array_change_key_case( (array) ( $res['headers'] ?? array() ), CASE_LOWER ),
			'error'   => 200 === $code ? '' : 'http_' . $code,
		);
	}

	private static function item( string $title, string $url, string $date, string $source, string $snippet ): array {
		return array(
			'title'   => self::cut( trim( (string) preg_replace( '/\s+/u', ' ', $title ) ), 200 ),
			'url'     => filter_var( $url, FILTER_VALIDATE_URL ) && preg_match( '#^https?://#i', $url ) ? $url : '',
			'date'    => trim( $date ),
			'source'  => $source,
			'snippet' => self::cut( trim( (string) preg_replace( '/\s+/u', ' ', $snippet ) ), 400 ),
		);
	}

	/** Items without a title are noise; nothing left = the model must be told "no results", never an empty success. */
	private static function done( array $items ): array {
		$items = array_values( array_filter( $items, static function ( $i ) { return '' !== $i['title']; } ) );
		return empty( $items ) ? self::fail( 'no_results' ) : array( 'ok' => true, 'items' => $items, 'error' => '' );
	}

	private static function fail( string $error, array $extra = array() ): array {
		return array( 'ok' => false, 'items' => array(), 'error' => $error ) + $extra;
	}

	private static function cut( string $s, int $max ): string {
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $s ) > $max ) {
			return rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…';
		}
		return $s;
	}

	private static function lang( string $lang ): string {
		$lang = strtolower( trim( $lang ) );
		return in_array( $lang, array( 'vi', 'en', 'auto' ), true ) ? $lang : 'auto';
	}
}
