<?php
/**
 * TwinBrain — Citation Resolver (R-BRAIN-2 / TBR.W10-be-citation).
 *
 * Single source of truth for citation token → resolved record. Implements the
 * baseline contract from PHASE-0.17 §2.3 + extends with the `web:` namespace
 * shipped by Wave 2.5 Web Research Fallback Layer (W6/W7).
 *
 * Supported token namespaces:
 *   - `[mem:U#42]` / `[mem:E#7]` / `[mem:R#3]` / `[mem:N#9]`   (user memory)
 *   - `[faq:7]`                                                  (FAQ row)
 *   - `[nb:17]`            (whole notebook)
 *   - `[nb:17/p3]`         (notebook passage)
 *   - `[src:passage-7821]` (source chunk — opaque id)
 *   - `[ent:product-sku-A1]` (KG entity)
 *   - `[astro:natal#2]` / `[astro:report#2/s1]` / `[astro:transit#2/2026-07-05]`
 *   - `[web:1#https://...]` (web result, W6/W7)
 *
 * Hard rules:
 *   - **R-BRAIN-2**: FE NEVER parses tokens — must call this resolver.
 *   - **Permission gate**: each kind has its own `can_user_access()` check.
 *     For `mem:U#N` we verify the memory belongs to current user (placeholder
 *     until BizCity_Twin_Memory layer ships).
 *   - **Cache**: 60s per (token, user_id) via `wp_cache_*`. Web tokens cached
 *     longer (5min) because they are immutable URLs.
 *
 * REST: `GET /wp-json/bizcity-twinbrain/v1/citations/resolve?tokens[]=...`
 * → response keyed by token.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since      2026-05-21 (Phase 0.36-UNIFIED TBR.W10)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Twin_Citation_Resolver {

	const CACHE_GROUP    = 'bizcity_twin_citation_resolver';
	const CACHE_TTL_SHORT= 60;   // mutable kinds (mem/faq/nb/ent/src)
	const CACHE_TTL_LONG = 300;  // web (URL is immupatble)
	const REST_NS        = 'bizcity-twinbrain/v1';

	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) return;
		self::$booted = true;
		// [2026-07-05 Johnny Chu] HOTFIX — fix malformed callback token causing PHP parse error.
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/* =================================================================
	 *  Public API
	 * ================================================================ */

	/**
	 * Resolve a batch of tokens for the current (or given) user.
	 *
	 * @param string[] $tokens   List of raw citation tokens (with or without brackets).
	 * @param int      $user_id  Optional. Defaults to current logged-in user.
	 * @return array<string, array{
	 *   token:string,
	 *   kind:string,
	 *   label:string,
	 *   ref_url:string,
	 *   evidence_excerpt:string,
	 *   can_edit:bool,
	 *   ttl:int,
	 *   error?:string
	 * }> keyed by normalized token.
	 */
	public static function resolve_batch( array $tokens, int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		$out     = [];
		foreach ( $tokens as $raw ) {
			$tok = self::normalize_token( (string) $raw );
			if ( $tok === '' || isset( $out[ $tok ] ) ) continue;
			$out[ $tok ] = self::resolve_one( $tok, $user_id );
		}
		return $out;
	}

	/**
	 * Convenience: resolve all tokens inline in an answer body.
	 *
	 * Returns `{tokens:string[], records:array}`. The FE can use `records`
	 * to render chips; `tokens` is the de-duplicated extracted list.
	 */
	public static function resolve_from_answer( string $answer_md, int $user_id = 0 ): array {
		$tokens = self::extract_tokens( $answer_md );
		return [
			'tokens'  => $tokens,
			'records' => self::resolve_batch( $tokens, $user_id ),
		];
	}

	/**
	 * Extract all known citation tokens from a markdown body.
	 *
	 * @return string[] de-duplicated, normalized (with brackets) token list.
	 */
	public static function extract_tokens( string $answer_md ): array {
		$pattern = '/\[(mem|faq|nb|src|ent|astro|web):[^\]\s]+\]/i';
		if ( ! preg_match_all( $pattern, $answer_md, $m ) ) {
			return [];
		}
		return array_values( array_unique( array_map( [ __CLASS__, 'normalize_token' ], $m[0] ) ) );
	}

	/* =================================================================
	 *  Single-token resolution
	 * ================================================================ */

	private static function resolve_one( string $token, int $user_id ): array {
		[ $kind, $ref ] = self::split_token( $token );
		if ( $kind === '' ) {
			return self::error_record( $token, 'unknown', 'invalid_token' );
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — add blog_id to cache key (multisite collision fix).
		$blog_id   = (int) get_current_blog_id();
		$cache_key = $kind === 'web'
			? 'web:' . md5( $ref )                                                  // URL is immutable, not user-gated
			: 'b' . $blog_id . ':' . $kind . ':' . $ref . ':u' . $user_id;         // permission-gated kinds keyed by blog+user

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		switch ( $kind ) {
			case 'astro':$rec = self::resolve_astro( $token, $ref, $user_id );      break;
			case 'web':  $rec = self::resolve_web( $token, $ref );                break;
			case 'nb':   $rec = self::resolve_notebook( $token, $ref, $user_id ); break;
			case 'faq':  $rec = self::resolve_faq( $token, $ref, $user_id );      break;
			case 'mem':  $rec = self::resolve_memory( $token, $ref, $user_id );   break;
			case 'ent':  $rec = self::resolve_entity( $token, $ref, $user_id );   break;
			case 'src':  $rec = self::resolve_source( $token, $ref, $user_id );   break;
			default:     $rec = self::error_record( $token, $kind, 'unsupported_kind' );
		}

		$ttl = $kind === 'web' ? self::CACHE_TTL_LONG : self::CACHE_TTL_SHORT;
		wp_cache_set( $cache_key, $rec, self::CACHE_GROUP, $ttl );
		return $rec;
	}

	/* =================================================================
	 *  Kind handlers
	 * ================================================================ */

	private static function resolve_web( string $token, string $ref ): array {
		// `1#https://example.com/path` or `1` (legacy).
		$index = 0;
		$url   = '';
		if ( strpos( $ref, '#' ) !== false ) {
			[ $idx_part, $url_part ] = explode( '#', $ref, 2 );
			$index = (int) $idx_part;
			$url   = trim( $url_part );
		} else {
			$index = (int) $ref;
		}

		$valid_url = $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) !== false;
		$host      = '';
		if ( $valid_url ) {
			$h    = wp_parse_url( $url, PHP_URL_HOST );
			$host = is_string( $h ) ? preg_replace( '/^www\./', '', $h ) : '';
		}

		return [
			'token'            => $token,
			'kind'             => 'web',
			'label'            => $host !== '' ? $host : ( '#' . $index ),
			'ref_url'          => $valid_url ? $url : '',
			'evidence_excerpt' => '',
			'can_edit'         => false,                  // web sources never editable
			'ttl'              => self::CACHE_TTL_LONG,
			'meta'             => [
				'web_index' => $index,
				'web_host'  => $host,
				'favicon'   => $valid_url ? ( 'https://www.google.com/s2/favicons?sz=32&domain=' . rawurlencode( $host ) ) : '',
			],
		];
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — resolve astro citation namespace.
	 *
	 * Supported refs:
	 *   - natal#<coachee_id>
	 *   - report#<coachee_id>/s<section_idx>
	 *   - transit#<coachee_id>/<YYYY-MM-DD>
	 *   - transit-range#<coachee_id>/<from>..<to>
	 * Backward-compat:
	 *   - [astro:<type>#https://...] (legacy URL-style token)
	 */
	private static function resolve_astro( string $token, string $ref, int $user_id ): array {
		if ( ! preg_match( '/^([a-z_\-]+)#(.+)$/i', $ref, $m ) ) {
			return self::error_record( $token, 'astro', 'invalid_ref' );
		}

		$astro_type = strtolower( (string) $m[1] );
		$payload    = trim( (string) $m[2] );
		if ( $payload === '' ) {
			return self::error_record( $token, 'astro', 'invalid_ref' );
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — keep URL-style tokens working.
		if ( filter_var( $payload, FILTER_VALIDATE_URL ) ) {
			return self::build_astro_url_record( $token, $astro_type, $payload );
		}

		switch ( $astro_type ) {
			case 'natal':
				return self::resolve_astro_natal( $token, $payload, $user_id );
			case 'report':
				return self::resolve_astro_report( $token, $payload, $user_id );
			case 'transit':
			case 'transit_day':
				return self::resolve_astro_transit( $token, $payload, $user_id, $astro_type );
			case 'transit-range':
			case 'transit_range':
				return self::resolve_astro_transit_range( $token, $payload, $user_id );
			default:
				return self::error_record( $token, 'astro', 'unsupported_astro_type' );
		}
	}

	private static function resolve_astro_natal( string $token, string $payload, int $user_id ): array {
		$coachee_id = (int) $payload;
		if ( $coachee_id <= 0 ) {
			return self::error_record( $token, 'astro', 'invalid_coachee_id' );
		}

		$owner = self::lookup_astro_owner( $coachee_id );
		if ( ! $owner['found'] ) {
			return self::error_record( $token, 'astro', 'not_found' );
		}
		if ( ! self::can_view_astro_owner( (int) $owner['user_id'], $user_id ) ) {
			return self::error_record( $token, 'astro', 'permission_denied' );
		}

		$name    = (string) $owner['full_name'];
		$excerpt = self::lookup_astro_natal_excerpt( $coachee_id );
		$url     = self::build_astro_natal_url( $coachee_id );

		return [
			'token'            => $token,
			'kind'             => 'astro',
			'label'            => 'Natal · ' . ( $name !== '' ? $name : ( '#' . $coachee_id ) ),
			'ref_url'          => $url,
			'evidence_excerpt' => $excerpt,
			'can_edit'         => user_can( $user_id, 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [
				'astro_type'    => 'natal',
				'coachee_id'    => $coachee_id,
				'owner_user_id' => (int) $owner['user_id'],
			],
		];
	}

	private static function resolve_astro_report( string $token, string $payload, int $user_id ): array {
		if ( ! preg_match( '/^(\d+)\/s(\d+)$/i', $payload, $m ) ) {
			return self::error_record( $token, 'astro', 'invalid_report_ref' );
		}

		$coachee_id  = (int) $m[1];
		$section_idx = (int) $m[2];
		if ( $coachee_id <= 0 || $section_idx < 0 ) {
			return self::error_record( $token, 'astro', 'invalid_report_ref' );
		}

		$owner = self::lookup_astro_owner( $coachee_id );
		if ( ! $owner['found'] ) {
			return self::error_record( $token, 'astro', 'not_found' );
		}
		if ( ! self::can_view_astro_owner( (int) $owner['user_id'], $user_id ) ) {
			return self::error_record( $token, 'astro', 'permission_denied' );
		}

		$report_meta = self::lookup_astro_report_section_excerpt( $coachee_id, $section_idx );
		$label_title = (string) ( $report_meta['title'] ?? '' );
		$excerpt     = (string) ( $report_meta['excerpt'] ?? '' );
		$url         = self::build_astro_natal_url( $coachee_id );
		if ( $url !== '' ) {
			$url = add_query_arg( [ 'report_section' => $section_idx ], $url );
		}

		return [
			'token'            => $token,
			'kind'             => 'astro',
			'label'            => $label_title !== ''
				? ( 'Report · ' . $label_title )
				: ( 'Report · s' . $section_idx ),
			'ref_url'          => $url,
			'evidence_excerpt' => $excerpt,
			'can_edit'         => user_can( $user_id, 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [
				'astro_type'    => 'report',
				'coachee_id'    => $coachee_id,
				'section_idx'   => $section_idx,
				'owner_user_id' => (int) $owner['user_id'],
			],
		];
	}

	private static function resolve_astro_transit( string $token, string $payload, int $user_id, string $astro_type ): array {
		if ( ! preg_match( '/^(\d+)\/(\d{4}-\d{2}-\d{2})$/', $payload, $m ) ) {
			return self::error_record( $token, 'astro', 'invalid_transit_ref' );
		}

		$coachee_id = (int) $m[1];
		$date       = (string) $m[2];
		if ( $coachee_id <= 0 ) {
			return self::error_record( $token, 'astro', 'invalid_transit_ref' );
		}

		$owner = self::lookup_astro_owner( $coachee_id );
		if ( ! $owner['found'] ) {
			return self::error_record( $token, 'astro', 'not_found' );
		}
		if ( ! self::can_view_astro_owner( (int) $owner['user_id'], $user_id ) ) {
			return self::error_record( $token, 'astro', 'permission_denied' );
		}

		$transit_meta = self::lookup_astro_transit_excerpt( $coachee_id, $date );
		$url          = self::build_astro_transit_day_url( $coachee_id, $date );

		return [
			'token'            => $token,
			'kind'             => 'astro',
			'label'            => 'Transit · ' . $date,
			'ref_url'          => $url,
			'evidence_excerpt' => (string) ( $transit_meta['excerpt'] ?? '' ),
			'can_edit'         => user_can( $user_id, 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [
				'astro_type'      => $astro_type,
				'coachee_id'      => $coachee_id,
				'date'            => $date,
				'aspects_count'   => (int) ( $transit_meta['aspects_count'] ?? 0 ),
				'retro_count'     => (int) ( $transit_meta['retro_count'] ?? 0 ),
				'owner_user_id'   => (int) $owner['user_id'],
			],
		];
	}

	private static function resolve_astro_transit_range( string $token, string $payload, int $user_id ): array {
		if ( ! preg_match( '/^(\d+)\/(\d{4}-\d{2}-\d{2})\.\.(\d{4}-\d{2}-\d{2})$/', $payload, $m ) ) {
			return self::error_record( $token, 'astro', 'invalid_transit_range_ref' );
		}

		$coachee_id = (int) $m[1];
		$from       = (string) $m[2];
		$to         = (string) $m[3];
		if ( $coachee_id <= 0 ) {
			return self::error_record( $token, 'astro', 'invalid_transit_range_ref' );
		}

		$owner = self::lookup_astro_owner( $coachee_id );
		if ( ! $owner['found'] ) {
			return self::error_record( $token, 'astro', 'not_found' );
		}
		if ( ! self::can_view_astro_owner( (int) $owner['user_id'], $user_id ) ) {
			return self::error_record( $token, 'astro', 'permission_denied' );
		}

		$url = self::build_astro_transit_day_url( $coachee_id, $from );
		if ( $url !== '' ) {
			$url = add_query_arg( [ 'period' => 'custom', 'from' => $from, 'to' => $to ], $url );
		}

		return [
			'token'            => $token,
			'kind'             => 'astro',
			'label'            => 'Transit range · ' . $from . ' → ' . $to,
			'ref_url'          => $url,
			'evidence_excerpt' => 'Khoảng transit từ ' . $from . ' đến ' . $to,
			'can_edit'         => user_can( $user_id, 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [
				'astro_type'    => 'transit_range',
				'coachee_id'    => $coachee_id,
				'from'          => $from,
				'to'            => $to,
				'owner_user_id' => (int) $owner['user_id'],
			],
		];
	}

	private static function build_astro_url_record( string $token, string $astro_type, string $url ): array {
		$host = '';
		$h    = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $h ) ) {
			$host = preg_replace( '/^www\./', '', $h );
		}
		$label = self::astro_type_label( $astro_type );
		if ( $host !== '' ) {
			$label .= ' · ' . $host;
		}

		return [
			'token'            => $token,
			'kind'             => 'astro',
			'label'            => $label,
			'ref_url'          => $url,
			'evidence_excerpt' => '',
			'can_edit'         => false,
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [
				'astro_type'       => $astro_type,
				'astro_url'        => $url,
				'astro_host'       => $host,
				'legacy_url_mode'  => true,
			],
		];
	}

	private static function astro_type_label( string $astro_type ): string {
		$map = [
			'natal'         => 'Natal',
			'report'        => 'Báo cáo',
			'en_report'     => 'Báo cáo EN',
			'transit'       => 'Transit',
			'transit_day'   => 'Transit ngày',
			'transit-range' => 'Transit range',
			'transit_range' => 'Transit range',
		];
		return $map[ $astro_type ] ?? ( 'Astro · ' . $astro_type );
	}

	/**
	 * @return array{found:bool,user_id:int,full_name:string}
	 */
	private static function lookup_astro_owner( int $coachee_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_coachees';
		$exists = bizcity_tbl_exists( $table ); // [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — safe table guard.
		if ( ! $exists ) {
			return [ 'found' => false, 'user_id' => 0, 'full_name' => '' ];
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, user_id, full_name FROM {$table} WHERE id = %d LIMIT 1",
			$coachee_id
		), ARRAY_A );

		if ( ! is_array( $row ) ) {
			return [ 'found' => false, 'user_id' => 0, 'full_name' => '' ];
		}

		return [
			'found'     => true,
			'user_id'   => (int) ( $row['user_id'] ?? 0 ),
			'full_name' => (string) ( $row['full_name'] ?? '' ),
		];
	}

	private static function can_view_astro_owner( int $owner_user_id, int $viewer_user_id ): bool {
		if ( user_can( $viewer_user_id, 'manage_options' ) ) {
			return true;
		}
		if ( $viewer_user_id <= 0 || $owner_user_id <= 0 ) {
			return false;
		}
		return $viewer_user_id === $owner_user_id;
	}

	private static function lookup_astro_natal_excerpt( int $coachee_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro';
		$exists = bizcity_tbl_exists( $table ); // [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — safe table guard.
		if ( ! $exists ) {
			return '';
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT summary FROM {$table} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['summary'] ) ) {
			return '';
		}

		$summary = json_decode( (string) $row['summary'], true );
		if ( ! is_array( $summary ) ) {
			return '';
		}

		if ( isset( $summary['big3'] ) && is_array( $summary['big3'] ) ) {
			$parts = [];
			foreach ( $summary['big3'] as $k => $v ) {
				$val = trim( (string) $v );
				if ( $val === '' ) { continue; }
				$parts[] = ucfirst( (string) $k ) . ': ' . $val;
			}
			if ( ! empty( $parts ) ) {
				return mb_substr( 'Big3 · ' . implode( ' · ', $parts ), 0, 220 );
			}
		}

		if ( isset( $summary['summary'] ) && is_string( $summary['summary'] ) ) {
			return mb_substr( trim( strip_tags( $summary['summary'] ) ), 0, 220 );
		}

		return '';
	}

	/**
	 * @return array{title:string,excerpt:string}
	 */
	private static function lookup_astro_report_section_excerpt( int $coachee_id, int $section_idx ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_astro';
		$exists = bizcity_tbl_exists( $table ); // [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — safe table guard.
		if ( ! $exists ) {
			return [ 'title' => '', 'excerpt' => '' ];
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT llm_report FROM {$table} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! is_array( $row ) || empty( $row['llm_report'] ) ) {
			return [ 'title' => '', 'excerpt' => '' ];
		}

		$decoded = json_decode( (string) $row['llm_report'], true );
		$sections = isset( $decoded['sections'] ) && is_array( $decoded['sections'] ) ? $decoded['sections'] : [];
		$raw = isset( $sections[ $section_idx ] ) ? $sections[ $section_idx ] : '';
		if ( ! is_string( $raw ) || $raw === '' ) {
			return [ 'title' => '', 'excerpt' => '' ];
		}

		$lines = preg_split( '/\r\n|\r|\n/', trim( $raw ) );
		$title = '';
		if ( is_array( $lines ) && ! empty( $lines ) ) {
			$title = trim( ltrim( (string) $lines[0], '# ' ) );
		}
		$excerpt = mb_substr( trim( strip_tags( $raw ) ), 0, 220 );
		return [ 'title' => $title, 'excerpt' => $excerpt ];
	}

	/**
	 * @return array{excerpt:string,aspects_count:int,retro_count:int}
	 */
	private static function lookup_astro_transit_excerpt( int $coachee_id, string $date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bccm_transit_snapshots';
		$exists = bizcity_tbl_exists( $table ); // [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — safe table guard.
		if ( ! $exists ) {
			return [ 'excerpt' => '', 'aspects_count' => 0, 'retro_count' => 0 ];
		}

		$date_col = '';
		if ( self::has_table_column( $table, 'target_date' ) ) {
			$date_col = 'target_date';
		} elseif ( self::has_table_column( $table, 'snap_date' ) ) {
			$date_col = 'snap_date';
		}
		if ( $date_col === '' ) {
			return [ 'excerpt' => '', 'aspects_count' => 0, 'retro_count' => 0 ];
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT planets_json, aspects_json FROM {$table} WHERE coachee_id = %d AND {$date_col} = %s LIMIT 1",
			$coachee_id,
			$date
		), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return [ 'excerpt' => '', 'aspects_count' => 0, 'retro_count' => 0 ];
		}

		$planets = ! empty( $row['planets_json'] ) ? json_decode( (string) $row['planets_json'], true ) : [];
		$aspects = ! empty( $row['aspects_json'] ) ? json_decode( (string) $row['aspects_json'], true ) : [];
		$aspects_count = is_array( $aspects ) ? count( $aspects ) : 0;
		$retro_count = 0;
		if ( is_array( $planets ) ) {
			foreach ( $planets as $planet ) {
				if ( ! is_array( $planet ) ) { continue; }
				$is_retro = ! empty( $planet['is_retro'] )
					|| ( isset( $planet['isRetro'] ) && strtolower( (string) $planet['isRetro'] ) === 'true' )
					|| ! empty( $planet['retrograde'] );
				if ( $is_retro ) {
					$retro_count++;
				}
			}
		}

		$excerpt = 'Transit ' . $date . ' · ' . $aspects_count . ' aspects';
		if ( $retro_count > 0 ) {
			$excerpt .= ' · ℞ ' . $retro_count;
		}
		return [
			'excerpt'       => $excerpt,
			'aspects_count' => $aspects_count,
			'retro_count'   => $retro_count,
		];
	}

	private static function has_table_column( string $table_name, string $column_name ): bool {
		$cache_key = 'col:' . md5( $table_name . '|' . $column_name );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_numeric( $cached ) ) {
			return (int) $cached === 1;
		}

		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1
			   FROM information_schema.COLUMNS
			  WHERE TABLE_SCHEMA = DATABASE()
			    AND TABLE_NAME   = %s
			    AND COLUMN_NAME  = %s
			  LIMIT 1",
			$table_name,
			$column_name
		) );

		wp_cache_set( $cache_key, $present, self::CACHE_GROUP, self::CACHE_TTL_SHORT );
		return $present === 1;
	}

	private static function build_astro_natal_url( int $coachee_id ): string {
		if ( $coachee_id <= 0 ) {
			return '';
		}
		if ( function_exists( 'bccm_get_natal_chart_public_url' ) ) {
			$url = (string) bccm_get_natal_chart_public_url( $coachee_id );
			if ( $url !== '' ) {
				return $url;
			}
		}
		return home_url( '/my-western-astrology/?coachee_id=' . $coachee_id );
	}

	private static function build_astro_transit_day_url( int $coachee_id, string $date ): string {
		if ( $coachee_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}

		$base = '';
		if ( function_exists( 'bcpro_get_transit_public_url' ) ) {
			$base = (string) bcpro_get_transit_public_url( $coachee_id, 'day' );
		}
		if ( $base === '' ) {
			$base = home_url( '/my-transit/?coachee_id=' . $coachee_id . '&period=day' );
		}
		$base = add_query_arg( [ 'period' => 'day' ], $base );
		return add_query_arg( [ 'date' => $date ], $base );
	}

	private static function resolve_notebook( string $token, string $ref, int $user_id ): array {
		// `17` or `17/p3`
		$nb_id = 0; $passage_id = 0;
		if ( strpos( $ref, '/p' ) !== false ) {
			[ $nb_part, $p_part ] = explode( '/p', $ref, 2 );
			$nb_id = (int) $nb_part;
			$passage_id = (int) $p_part;
		} else {
			$nb_id = (int) $ref;
		}
		if ( $nb_id <= 0 ) {
			return self::error_record( $token, 'nb', 'invalid_id' );
		}

		$title = self::lookup_notebook_title( $nb_id );
		$excerpt = $passage_id > 0 ? self::lookup_passage_excerpt( $nb_id, $passage_id ) : '';

		return [
			'token'            => $token,
			'kind'             => 'nb',
			'label'            => $title !== '' ? $title : ( '#' . $nb_id ),
			'ref_url'          => add_query_arg(
				[ 'notebook_id' => $nb_id, 'passage_id' => $passage_id ?: null ],
				admin_url( 'admin.php?page=bizcity-twin-knowledge' )
			),
			'evidence_excerpt' => $excerpt,
			'can_edit'         => current_user_can( 'edit_posts' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [ 'notebook_id' => $nb_id, 'passage_id' => $passage_id ],
		];
	}

	private static function resolve_faq( string $token, string $ref, int $user_id ): array {
		$faq_id = (int) $ref;
		if ( $faq_id <= 0 ) {
			return self::error_record( $token, 'faq', 'invalid_id' );
		}
		return [
			'token'            => $token,
			'kind'             => 'faq',
			'label'            => 'FAQ #' . $faq_id,
			'ref_url'          => add_query_arg(
				[ 'tab' => 'quick-faq', 'faq_id' => $faq_id ],
				admin_url( 'admin.php?page=bizcity-twin-memory' )
			),
			'evidence_excerpt' => '',
			'can_edit'         => current_user_can( 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [ 'faq_id' => $faq_id ],
		];
	}

	private static function resolve_memory( string $token, string $ref, int $user_id ): array {
		// `U#42` | `E#7` | `R#3` | `N#9`
		$kind_letter = ''; $mem_id = 0;
		if ( preg_match( '/^([UERN])#(\d+)$/i', $ref, $m ) ) {
			$kind_letter = strtoupper( $m[1] );
			$mem_id      = (int) $m[2];
		}
		if ( $mem_id <= 0 ) {
			return self::error_record( $token, 'mem', 'invalid_ref' );
		}

		// Permission gate: User memory only visible to its owner (or admin).
		// Full owner lookup deferred to Twin_Memory layer; for now restrict
		// `U#` to current_user_can read or owner-self.
		$can_view = self::can_view_memory( $kind_letter, $mem_id, $user_id );
		if ( ! $can_view ) {
			return self::error_record( $token, 'mem', 'permission_denied' );
		}

		$tier_label = [
			'U' => 'User memory',
			'E' => 'Episodic',
			'R' => 'Rolling summary',
			'N' => 'Note',
		][ $kind_letter ] ?? 'Memory';

		/* Wave 2.8 TBR.MEM-8 — real DB lookup for U-tier rows (others left as
		 * stubs until episodic/rolling writers ship in MEM-5). Falls back to
		 * label-only record when table or row missing. */
		$excerpt = '';
		$mem_type = '';
		$mem_tier = '';
		$is_owner = false;
		if ( $kind_letter === 'U' ) {
			global $wpdb;
			$table = $wpdb->prefix . 'bizcity_memory_users';
			$exists = bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
			if ( $exists ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, user_id, memory_tier, memory_type, memory_text FROM {$table} WHERE id = %d LIMIT 1",
					$mem_id
				) );
				if ( $row ) {
					$row_user = (int) $row->user_id;
					$is_owner = ( $row_user > 0 && $row_user === $user_id );
					if ( ! $is_owner && ! user_can( $user_id, 'manage_options' ) ) {
						return self::error_record( $token, 'mem', 'permission_denied' );
					}
					$txt      = (string) $row->memory_text;
					$excerpt  = mb_substr( $txt, 0, 240 );
					$mem_type = (string) $row->memory_type;
					$mem_tier = (string) $row->memory_tier;
				}
			}
		}

		return [
			'token'            => $token,
			'kind'             => 'mem',
			'label'            => $tier_label . ' #' . $mem_id . ( $mem_type !== '' ? ' · ' . $mem_type : '' ),
			'ref_url'          => add_query_arg(
				[ 'tab' => 'memory', 'tier' => strtolower( $kind_letter ), 'id' => $mem_id ],
				admin_url( 'admin.php?page=bizcity-twin-memory' )
			),
			'evidence_excerpt' => $excerpt,
			'can_edit'         => $is_owner || current_user_can( 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [
				'tier'        => $kind_letter,
				'mem_id'      => $mem_id,
				'memory_type' => $mem_type,
				'memory_tier' => $mem_tier,
			],
		];
	}

	private static function resolve_entity( string $token, string $ref, int $user_id ): array {
		$ent_id = sanitize_title( $ref );
		if ( $ent_id === '' ) {
			return self::error_record( $token, 'ent', 'invalid_id' );
		}
		return [
			'token'            => $token,
			'kind'             => 'ent',
			'label'            => 'Entity: ' . $ent_id,
			'ref_url'          => add_query_arg(
				[ 'tab' => 'graph', 'entity' => $ent_id ],
				admin_url( 'admin.php?page=bizcity-twin-knowledge' )
			),
			'evidence_excerpt' => '',
			'can_edit'         => current_user_can( 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [ 'entity_id' => $ent_id ],
		];
	}

	private static function resolve_source( string $token, string $ref, int $user_id ): array {
		$src_id = sanitize_title( $ref );
		if ( $src_id === '' ) {
			return self::error_record( $token, 'src', 'invalid_id' );
		}
		return [
			'token'            => $token,
			'kind'             => 'src',
			'label'            => 'Source: ' . $src_id,
			'ref_url'          => add_query_arg(
				[ 'tab' => 'sources', 'src' => $src_id ],
				admin_url( 'admin.php?page=bizcity-twin-knowledge' )
			),
			'evidence_excerpt' => '',
			'can_edit'         => current_user_can( 'manage_options' ),
			'ttl'              => self::CACHE_TTL_SHORT,
			'meta'             => [ 'source_id' => $src_id ],
		];
	}

	/* =================================================================
	 *  Lookups (best-effort; fall back to stub when underlying tables
	 *  are absent so the resolver never throws).
	 * ================================================================ */

	private static function lookup_notebook_title( int $nb_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_notebooks';
		$cached = wp_cache_get( 'nb_title:' . $nb_id, self::CACHE_GROUP );
		if ( is_string( $cached ) ) return $cached;
		// Guard — table may not exist in lean envs.
		$exists = bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) {
			wp_cache_set( 'nb_title:' . $nb_id, '', self::CACHE_GROUP, self::CACHE_TTL_SHORT );
			return '';
		}
		$title = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT title FROM {$table} WHERE id = %d LIMIT 1",
			$nb_id
		) );
		wp_cache_set( 'nb_title:' . $nb_id, $title, self::CACHE_GROUP, self::CACHE_TTL_SHORT );
		return $title;
	}

	private static function lookup_passage_excerpt( int $nb_id, int $passage_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_passages';
		$exists = bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) return '';
		$content = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT content FROM {$table} WHERE id = %d AND notebook_id = %d LIMIT 1",
			$passage_id, $nb_id
		) );
		return mb_substr( $content, 0, 240 );
	}

	private static function can_view_memory( string $kind_letter, int $mem_id, int $user_id ): bool {
		// Admins: always.
		if ( user_can( $user_id, 'manage_options' ) ) return true;
		// Stub: trust until Twin_Memory layer enforces owner check.
		// Deny U# memory if user not logged in.
		if ( $kind_letter === 'U' && $user_id <= 0 ) return false;
		return true;
	}

	/* =================================================================
	 *  Token utilities
	 * ================================================================ */

	private static function normalize_token( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) return '';
		// Allow callers to pass either with or without brackets.
		if ( $raw[0] !== '[' ) $raw = '[' . $raw;
		if ( substr( $raw, -1 ) !== ']' ) $raw .= ']';
		// Sanity: must match `[kind:ref]`.
		if ( ! preg_match( '/^\[(mem|faq|nb|src|ent|astro|web):[^\]\s]+\]$/i', $raw ) ) return '';
		return $raw;
	}

	/**
	 * @return array{0:string,1:string} [kind, ref] or ['',''] on failure.
	 */
	private static function split_token( string $token ): array {
		if ( ! preg_match( '/^\[(mem|faq|nb|src|ent|astro|web):([^\]]+)\]$/i', $token, $m ) ) {
			return [ '', '' ];
		}
		return [ strtolower( $m[1] ), $m[2] ];
	}

	private static function error_record( string $token, string $kind, string $error ): array {
		return [
			'token'            => $token,
			'kind'             => $kind,
			'label'            => 'Unresolved',
			'ref_url'          => '',
			'evidence_excerpt' => '',
			'can_edit'         => false,
			'ttl'              => self::CACHE_TTL_SHORT,
			'error'            => $error,
		];
	}

	/* =================================================================
	 *  REST
	 * ================================================================ */

	public static function register_routes(): void {
		register_rest_route( self::REST_NS, '/citations/resolve', [
			'methods'             => 'GET',
			'permission_callback' => function() { return is_user_logged_in(); },
			'args'                => [
				'tokens' => [
					'type'     => 'array',
					'required' => true,
					'items'    => [ 'type' => 'string' ],
				],
			],
			'callback'            => [ __CLASS__, 'handle_rest_resolve' ],
		] );
	}

	public static function handle_rest_resolve( WP_REST_Request $req ) {
		$tokens = (array) $req->get_param( 'tokens' );
		if ( empty( $tokens ) ) {
			return new WP_REST_Response( [ 'success' => true, 'records' => (object) [] ], 200 );
		}
		$records = self::resolve_batch( $tokens, get_current_user_id() );
		return new WP_REST_Response( [
			'success' => true,
			'count'   => count( $records ),
			'records' => $records,
		], 200 );
	}
}

BizCity_Twin_Citation_Resolver::boot();
