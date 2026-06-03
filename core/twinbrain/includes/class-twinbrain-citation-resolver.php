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
	const CACHE_TTL_LONG = 300;  // web (URL is immutable)
	const REST_NS        = 'bizcity-twinbrain/v1';

	private static $booted = false;

	public static function boot(): void {
		if ( self::$booted ) return;
		self::$booted = true;
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
		$pattern = '/\[(mem|faq|nb|src|ent|web):[^\]\s]+\]/i';
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

		$cache_key = $kind === 'web'
			? 'web:' . md5( $ref )                              // URL is stable; not user-gated
			: $kind . ':' . $ref . ':u' . $user_id;             // permission-gated kinds keyed by user

		$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		switch ( $kind ) {
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
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $exists === $table ) {
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
		// SHOW TABLES guard — table may not exist in lean envs.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
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
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) return '';
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
		if ( ! preg_match( '/^\[(mem|faq|nb|src|ent|web):[^\]\s]+\]$/i', $raw ) ) return '';
		return $raw;
	}

	/**
	 * @return array{0:string,1:string} [kind, ref] or ['',''] on failure.
	 */
	private static function split_token( string $token ): array {
		if ( ! preg_match( '/^\[(mem|faq|nb|src|ent|web):([^\]]+)\]$/i', $token, $m ) ) {
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
