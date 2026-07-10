<?php
/**
 * BizCoach Pro — Astrology Persona REST endpoints.
 *
 * Ports the legacy `BizCoach_Persona_Rest` from the deleted
 * `plugins/bizcoach-map/` plugin so the React `PersonalArtifactDialog`
 * keeps working when an admin binds a Twin Guru character to the new
 * `bizcoach_astro` persona provider.
 *
 * Namespace is preserved verbatim (`bizcity-bizcoach/v1`) so the FE API
 * helpers (`api.listBizcoachProfiles`, `api.bizcoachIngestPersonaLink`)
 * do not need a route rewrite. The bccm_* tables this class reads are
 * now owned by `BizCoach_Pro_Installer`; the helper functions
 * (`bccm_generate_natal_chart_hash`, `bccm_get_natal_chart_public_url`,
 * AJAX `bccm_natal_report_full` / `bccm_transit_report`) are loaded at
 * runtime by `BizCoach_Pro_Legacy_Adopter::boot()` from the `legacy/`
 * snapshot.
 *
 * Routes:
 *   GET  /persona/profiles                 → list current user's coachees + chart status
 *   GET  /persona/links/{coachee_id}       → resolve share URLs (natal view, AI reports, transits)
 *   POST /persona/ingest                   → server-side fetch + sanitize + KG ingest
 *
 * @since 0.2.0  Sprint K (2026-05-15)
 * @see   class-astro-provider.php
 * @see   PROVIDER-CANON.md §8
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Astro_Rest' ) ) { return; }

class BizCoach_Pro_Astro_Rest {

	const NAMESPACE = 'bizcity-bizcoach/v1';

	public static function init() {
		// If `rest_api_init` already fired (e.g. another mu-plugin called
		// rest_get_server() during plugins_loaded before us), the regular
		// add_action is a no-op for THIS request — register the routes now
		// so the first REST hit of the request still sees them.
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		if ( did_action( 'rest_api_init' ) ) {
			self::register_routes();
		}
	}

	public static function register_routes() {
		register_rest_route( self::NAMESPACE, '/persona/profiles', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_profiles' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/persona/transit-profiles', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_transit_profiles' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/persona/links/(?P<coachee_id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_links' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
		] );
		register_rest_route( self::NAMESPACE, '/persona/ingest', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'ingest_link' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
		] );

		// [2026-06-09 Johnny Chu] PHASE-D D-BE-EN-REPORT — English natal report (LLM + option cache)
		register_rest_route( self::NAMESPACE, '/astro/english-report/(?P<coachee_id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_english_report' ],
				'permission_callback' => [ __CLASS__, 'permission' ],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'generate_english_report' ],
				'permission_callback' => [ __CLASS__, 'permission' ],
			],
		] );
	}

	public static function permission() {
		return is_user_logged_in();
	}

	public static function list_profiles( WP_REST_Request $req ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$limit   = max( 1, min( 100, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
		$search  = trim( (string) $req->get_param( 'search' ) );
		$is_admin_scope = current_user_can( 'manage_options' );

		// Producer returns ONLY DB-derived rows + chart kinds map. We do NOT
		// cache wp_create_nonce() output (rebuilt per-request inside build_links_for).
		// Cache key includes user_id+version (CACHE-STRATEGY.md §5) + admin flag
		// (admin sees all rows) + search/limit (different result sets).
		$producer = function () use ( $wpdb, $user_id, $limit, $search, $is_admin_scope ) {
			$coachees_tbl = $wpdb->prefix . 'bccm_coachees';
			$astro_tbl    = $wpdb->prefix . 'bccm_astro';

			$where  = [ '1=1' ];
			$params = [];
			if ( ! $is_admin_scope ) {
				$where[]  = 'user_id = %d';
				$params[] = $user_id;
			}
			if ( $search !== '' ) {
				$where[]  = '(full_name LIKE %s OR phone LIKE %s)';
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$params[] = $like;
				$params[] = $like;
			}

			$sql = "SELECT id, full_name, phone, dob, platform_type, updated_at
			        FROM {$coachees_tbl}
			        WHERE " . implode( ' AND ', $where ) . "
			        ORDER BY updated_at DESC
			        LIMIT %d";
			$params[] = $limit;
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();

			if ( empty( $rows ) ) {
				return array( 'rows' => array(), 'chart_map' => array() );
			}

			$ids       = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
			$ids_csv   = implode( ',', $ids );
			$chart_rows = $wpdb->get_results(
				"SELECT coachee_id, chart_type FROM {$astro_tbl} WHERE coachee_id IN ({$ids_csv})",
				ARRAY_A
			);
			$chart_map = array();
			foreach ( (array) $chart_rows as $cr ) {
				$cid = (int) $cr['coachee_id'];
				$chart_map[ $cid ][] = (string) $cr['chart_type'];
			}
			return array( 'rows' => $rows, 'chart_map' => $chart_map );
		};

		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			// Admin scope sees cross-user data → use synthetic uid=0 namespace
			// so admin views don't pollute regular users' cached lists.
			$cache_uid = $is_admin_scope ? 0 : (int) $user_id;
			$ver       = BizCoach_Pro_Cache::get_user_version( $cache_uid );
			$key       = sprintf( 'astro:user:%d:v:%d:s:%s:lim:%d',
				$cache_uid, $ver, md5( $search ), $limit );
			$data = BizCoach_Pro_Cache::remember( 'bcpro_coachee_idx', $key, 600, $producer );
		} else {
			$data = $producer();
		}

		$rows      = is_array( $data ) && isset( $data['rows'] )      ? (array) $data['rows']      : array();
		$chart_map = is_array( $data ) && isset( $data['chart_map'] ) ? (array) $data['chart_map'] : array();

		if ( empty( $rows ) ) {
			return rest_ensure_response( [ 'ok' => true, 'data' => [] ] );
		}

		$out = [];
		foreach ( $rows as $r ) {
			$cid    = (int) $r['id'];
			$kinds  = $chart_map[ $cid ] ?? [];
			$out[]  = [
				'id'            => $cid,
				'full_name'     => (string) $r['full_name'],
				'phone'         => (string) ( $r['phone'] ?? '' ),
				'dob'           => (string) ( $r['dob'] ?? '' ),
				'platform_type' => (string) ( $r['platform_type'] ?? '' ),
				'updated_at'    => (string) ( $r['updated_at'] ?? '' ),
				'has_natal'     => ! empty( $kinds ),
				'chart_types'   => array_values( array_unique( $kinds ) ),
				'view_url'      => admin_url( 'admin.php?page=bccm_user_profiles&action=view&coachee_id=' . $cid ),
				'edit_url'      => admin_url( 'admin.php?page=bccm_user_profiles&action=edit&coachee_id=' . $cid ),
				'links'         => self::build_links_for( $cid ), // contains nonces — rebuilt per request
			];
		}

		return rest_ensure_response( [ 'ok' => true, 'data' => $out ] );
	}

	/**
	 * GET /persona/transit-profiles
	 *
	 * Returns coachees that already have transit snapshots for the current
	 * user in the requested horizon (default: next 30 days).
	 */
	public static function list_transit_profiles( WP_REST_Request $req ) {
		global $wpdb;

		$user_id = get_current_user_id();
		$limit   = max( 1, min( 100, (int) ( $req->get_param( 'limit' ) ?: 50 ) ) );
		$search  = trim( (string) $req->get_param( 'search' ) );
		$days    = max( 1, min( 90, (int) ( $req->get_param( 'days' ) ?: 30 ) ) );
		$start   = current_time( 'Y-m-d' );
		$end     = gmdate( 'Y-m-d', strtotime( $start . ' +' . $days . ' days' ) );

		$coachees_tbl = $wpdb->prefix . 'bccm_coachees';
		$astro_tbl    = $wpdb->prefix . 'bccm_astro';
		$snap_tbl     = $wpdb->prefix . 'bccm_transit_snapshots';

		// [2026-07-06 Johnny Chu] HOTFIX — transit list must scope by current user_id in bccm_transit_snapshots.
		$where  = array(
			'c.user_id = %d',
			's.user_id = %d',
			's.target_date BETWEEN %s AND %s',
		);
		$params = array( $user_id, $user_id, $start, $end );
		if ( $search !== '' ) {
			$where[]  = '(c.full_name LIKE %s OR c.phone LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$params[] = $limit;

		$sql = "SELECT c.id, c.full_name, c.phone, c.dob, c.platform_type, c.updated_at,
		               COUNT(DISTINCT s.target_date) AS transit_days
		          FROM {$coachees_tbl} c
		          INNER JOIN {$snap_tbl} s ON s.coachee_id = c.id
		         WHERE " . implode( ' AND ', $where ) . "
		      GROUP BY c.id, c.full_name, c.phone, c.dob, c.platform_type, c.updated_at
		      ORDER BY transit_days DESC, c.updated_at DESC
		         LIMIT %d";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return rest_ensure_response( array( 'ok' => true, 'data' => array() ) );
		}

		$ids       = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$ids_csv   = implode( ',', $ids );
		$chart_rows = $wpdb->get_results(
			"SELECT coachee_id, chart_type FROM {$astro_tbl} WHERE coachee_id IN ({$ids_csv})",
			ARRAY_A
		);
		$chart_map = array();
		foreach ( (array) $chart_rows as $cr ) {
			$cid = (int) $cr['coachee_id'];
			$chart_map[ $cid ][] = (string) $cr['chart_type'];
		}

		$out = array();
		foreach ( $rows as $r ) {
			$cid   = (int) $r['id'];
			$kinds = $chart_map[ $cid ] ?? array();
			$out[] = array(
				'id'            => $cid,
				'full_name'     => (string) $r['full_name'],
				'phone'         => (string) ( $r['phone'] ?? '' ),
				'dob'           => (string) ( $r['dob'] ?? '' ),
				'platform_type' => (string) ( $r['platform_type'] ?? '' ),
				'updated_at'    => (string) ( $r['updated_at'] ?? '' ),
				'has_natal'     => ! empty( $kinds ),
				'chart_types'   => array_values( array_unique( $kinds ) ),
				'transit_days'  => (int) ( $r['transit_days'] ?? 0 ),
				'view_url'      => admin_url( 'admin.php?page=bccm_user_profiles&action=view&coachee_id=' . $cid ),
				'edit_url'      => admin_url( 'admin.php?page=bccm_user_profiles&action=edit&coachee_id=' . $cid ),
				'links'         => self::build_links_for( $cid ),
			);
		}

		return rest_ensure_response( array( 'ok' => true, 'data' => $out ) );
	}

	public static function get_links( WP_REST_Request $req ) {
		$cid = (int) $req['coachee_id'];
		if ( ! $cid ) {
			return new WP_Error( 'bad_request', 'coachee_id required', [ 'status' => 400 ] );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			global $wpdb;
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bccm_coachees WHERE id = %d",
				$cid
			) );
			if ( $owner !== get_current_user_id() ) {
				return new WP_Error( 'forbidden', 'Not your coachee', [ 'status' => 403 ] );
			}
		}
		return rest_ensure_response( [
			'ok'   => true,
			'data' => self::build_links_for( $cid ),
		] );
	}

	/**
	 * Build share URLs the dialog renders. Functions
	 * `bccm_generate_natal_chart_hash` and the AJAX handlers
	 * (`bccm_natal_report_full`, `bccm_transit_report`) are loaded by
	 * the legacy adopter at runtime; we fall back to a deterministic
	 * hash if the function is missing so links remain dereferenceable.
	 */
	private static function build_links_for( int $coachee_id ): array {
		$natal_hash    = function_exists( 'bccm_generate_natal_chart_hash' )
			? bccm_generate_natal_chart_hash( $coachee_id )
			: substr( md5( $coachee_id . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bccm' ) ), 0, 16 );
		$report_nonce  = wp_create_nonce( 'bccm_natal_report_full' );
		$transit_nonce = wp_create_nonce( 'bccm_transit_report' );
		// Sprint H.6 — prefer hash-protected public URLs (shareable) over admin-ajax (admin-only).
		$router_ok = class_exists( 'BizCoach_Pro_Astro_Public_Router' );
		// [2026-06-04 Johnny Chu] PHASE-A C.0a — transit URLs unified to /my-transit/ public router
		// (HMAC hash, share-friendly, no nonce). Falls back to admin-ajax only when router class missing.
		$transit_router_ok = class_exists( 'BizCoach_Pro_Transit_Public_Router' );
		$transit_url       = function ( $period ) use ( $transit_router_ok, $coachee_id, $transit_nonce ) {
			if ( $transit_router_ok ) {
				return BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, $period );
			}
			return admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=' . $period . '&_wpnonce=' . $transit_nonce );
		};

		return [
			'natal_chart_view' => [
				'label' => '🌟 Natal Chart View',
				'url'   => home_url( '/my-natal-chart/?id=' . $coachee_id . '&hash=' . $natal_hash ),
				'kind'  => 'astro_natal_chart',
			],
			'natal_report_western' => [
				'label' => '🤖 AI Reading — Western',
				'url'   => $router_ok
					? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'western' )
					: admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . $report_nonce ),
				'kind'  => 'astro_natal_chart',
			],
			'natal_report_vedic' => [
				'label' => '🕉️ AI Reading — Vedic',
				'url'   => $router_ok
					? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'vedic' )
					: admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . $report_nonce ),
				'kind'  => 'astro_natal_chart',
			],
			'natal_report_chinese' => [
				'label' => '☯️ AI Reading — Chinese (Tứ Trụ)',
				'url'   => $router_ok
					? BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'chinese' )
					: admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=chinese&_wpnonce=' . $report_nonce ),
				'kind'  => 'astro_natal_chart',
			],
			// [2026-06-04 Johnny Chu] PHASE-A C.0a — transit_day added (use case astro "hôm nay").
			'transit_day' => [
				'label' => '🌅 Transit — Today',
				'url'   => $transit_url( 'day' ),
				'kind'  => 'astro_transit_report',
			],
			'transit_week' => [
				'label' => '🔮 Transit — Next Week',
				'url'   => $transit_url( 'week' ),
				'kind'  => 'astro_transit_report',
			],
			'transit_month' => [
				'label' => '🔮 Transit — Next Month',
				'url'   => $transit_url( 'month' ),
				'kind'  => 'astro_transit_report',
			],
			'transit_year' => [
				'label' => '🔮 Transit — Next Year',
				'url'   => $transit_url( 'year' ),
				'kind'  => 'astro_transit_report',
			],
		];
	}

	/**
	 * POST /persona/ingest  body={notebook_id, coachee_id, link_key}
	 * Server-side fetches the link with current user's cookies, sanitizes
	 * HTML → markdown, then ingests via BizCity_KG. Mirrors the legacy flow.
	 */
	public static function ingest_link( WP_REST_Request $req ) {
		$notebook_id = (int) $req->get_param( 'notebook_id' );
		$coachee_id  = (int) $req->get_param( 'coachee_id' );
		$link_key    = sanitize_key( (string) $req->get_param( 'link_key' ) );

		if ( ! $notebook_id || ! $coachee_id || ! $link_key ) {
			return new WP_Error( 'bad_request', 'notebook_id, coachee_id, link_key required', [ 'status' => 400 ] );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			global $wpdb;
			$owner = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bccm_coachees WHERE id = %d",
				$coachee_id
			) );
			if ( $owner !== get_current_user_id() ) {
				return new WP_Error( 'forbidden', 'Not your coachee', [ 'status' => 403 ] );
			}
		}

		$links = self::build_links_for( $coachee_id );
		if ( ! isset( $links[ $link_key ] ) ) {
			return new WP_Error( 'invalid_link', 'Unknown link key: ' . $link_key, [ 'status' => 400 ] );
		}
		$link = $links[ $link_key ];

		global $wpdb;
		$coachee_name = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT full_name FROM {$wpdb->prefix}bccm_coachees WHERE id = %d",
			$coachee_id
		) );
		if ( $coachee_name === '' ) { $coachee_name = 'Coachee #' . $coachee_id; }

		$canonical_url = remove_query_arg( '_wpnonce', $link['url'] );
		$kg_tbl        = class_exists( 'BizCity_KG_Database' )
			? BizCity_KG_Database::instance()->tbl_sources()
			: $wpdb->prefix . 'bizcity_kg_sources';
		$existing_origin_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT origin_id FROM {$kg_tbl}
			  WHERE scope_type    = 'notebook'
			    AND scope_id      = %s
			    AND origin_plugin = 'twinchat'
			    AND origin_url    = %s
			  LIMIT 1",
			(string) $notebook_id,
			$canonical_url
		) );
		if ( $existing_origin_id > 0 ) {
			return rest_ensure_response( [
				'ok'        => true,
				'duplicate' => true,
				'data'      => [ 'source_id' => $existing_origin_id ],
			] );
		}

		$cookies = [];
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new WP_Http_Cookie( [
				'name'  => $name,
				'value' => $value,
			] );
		}

		// Markdown-First fast path (R-SMF-3).
		$md_url = add_query_arg( [ '_format' => 'md' ], $link['url'] );
		$resp   = wp_remote_get( $md_url, [
			'timeout'     => 30,
			'redirection' => 5,
			'cookies'     => $cookies,
			'sslverify'   => false,
			'headers'     => [
				'User-Agent' => 'BizCity-Twin/1.0 (persona ingest)',
				'Accept'     => 'text/markdown,text/plain;q=0.9,text/html;q=0.5',
			],
		] );

		$markdown_native = false;
		if ( ! is_wp_error( $resp ) ) {
			$ct = (string) wp_remote_retrieve_header( $resp, 'content-type' );
			if ( stripos( $ct, 'text/markdown' ) !== false ) {
				$markdown_native = true;
			}
		}

		if ( ! $markdown_native ) {
			$resp = wp_remote_get( $link['url'], [
				'timeout'     => 30,
				'redirection' => 5,
				'cookies'     => $cookies,
				'sslverify'   => false,
				'headers'     => [
					'User-Agent' => 'BizCity-Twin/1.0 (persona ingest)',
				],
			] );
		}

		if ( is_wp_error( $resp ) ) {
			return new WP_Error(
				'fetch_failed',
				'Cannot reach link: ' . $resp->get_error_message(),
				[ 'status' => 502 ]
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 400 ) {
			return new WP_Error(
				'fetch_http_' . $code,
				'Source returned HTTP ' . $code,
				[ 'status' => 502 ]
			);
		}

		$body = (string) wp_remote_retrieve_body( $resp );
		$text = '';

		if ( $markdown_native ) {
			$text = $body;
		} else {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) ) {
				$candidates = [];
				$walk = function ( $node ) use ( &$walk, &$candidates ) {
					if ( is_string( $node ) ) {
						if ( strlen( $node ) > 30 ) { $candidates[] = $node; }
						return;
					}
					if ( is_array( $node ) ) {
						foreach ( $node as $v ) { $walk( $v ); }
					}
				};
				$walk( $decoded );
				if ( ! empty( $candidates ) ) {
					$text = implode( "\n\n", array_unique( $candidates ) );
				}
			}
			if ( $text === '' ) { $text = $body; }
		}

		if ( class_exists( 'BizCity_Source_HTML_Sanitizer' ) ) {
			$sanitized = BizCity_Source_HTML_Sanitizer::to_markdown( $text, [
				'min_length'   => 40,
				'strip_qm'     => true,
				'strip_chrome' => true,
				'keep_links'   => true,
				'keep_images'  => false,
			] );
			if ( is_wp_error( $sanitized ) ) {
				return new WP_Error(
					'empty_content',
					'Source returned empty/short content after sanitize: ' . $sanitized->get_error_message() . '. Try the View Detail page first to make sure the report has been generated.',
					[ 'status' => 422 ]
				);
			}
			$text = $sanitized;
		} else {
			$text = wp_strip_all_tags( $text );
			$text = preg_replace( '/[ \t]+/', ' ', (string) $text );
			$text = preg_replace( "/\n{3,}/", "\n\n", (string) $text );
			$text = trim( (string) $text );
			if ( $text === '' || strlen( $text ) < 40 ) {
				return new WP_Error(
					'empty_content',
					'Source returned empty/short content (' . strlen( $text ) . ' chars).',
					[ 'status' => 422 ]
				);
			}
		}

		if ( ! class_exists( 'BizCity_KG' ) ) {
			return new WP_Error( 'no_kg', 'Knowledge Graph service unavailable', [ 'status' => 500 ] );
		}

		$title = $link['label'] . ' — ' . $coachee_name;
		$res = BizCity_KG::ingest(
			[ 'plugin' => 'twinchat', 'scope_id' => $notebook_id ],
			[
				'type'     => 'text',
				'title'    => $title,
				'content'  => $text,
				'url'      => $canonical_url,
				'metadata' => [
					'provider'        => 'bizcoach_astro',
					'coachee_id'      => $coachee_id,
					'link_key'        => $link_key,
					'source_kind'     => $link['kind'],
					'format'          => 'markdown',
					'sanitized_at'    => time(),
					'markdown_native' => $markdown_native,
				],
			]
		);
		if ( is_wp_error( $res ) ) { return $res; }

		// Cache invalidation — KG ingest writes a new source row; flush any
		// cached profiles list for this user (the "ingested" indicator may
		// change). See CACHE-STRATEGY.md §4.
		do_action( 'bcpro/cache/invalidate', 'coachee', array(
			'id'      => $coachee_id,
			'user_id' => get_current_user_id(),
		) );

		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// [2026-06-09 Johnny Chu] PHASE-D D-BE-EN-REPORT — English natal report
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * GET /astro/english-report/{coachee_id}
	 * Returns cached English natal report or null if not yet generated.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response
	 */
	public static function get_english_report( WP_REST_Request $req ) {
		$coachee_id = (int) $req->get_param( 'coachee_id' );
		if ( ! $coachee_id ) {
			return new WP_Error( 'bad_request', 'coachee_id required', [ 'status' => 400 ] );
		}

		if ( ! self::_can_access_coachee( $coachee_id ) ) {
			return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
		}

		$stored = get_option( 'bccm_natal_en_' . $coachee_id, null );
		if ( ! is_array( $stored ) || empty( $stored['content'] ) ) {
			return rest_ensure_response( [ 'ok' => true, 'data' => null ] );
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'content'      => (string) $stored['content'],
				'generated_at' => (string) ( $stored['generated_at'] ?? '' ),
			],
		] );
	}

	/**
	 * POST /astro/english-report/{coachee_id}
	 * Generate (or regenerate) English natal report via LLM gateway, persist in options.
	 *
	 * Body (JSON, optional): { "regenerate": true }
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public static function generate_english_report( WP_REST_Request $req ) {
		$coachee_id = (int) $req->get_param( 'coachee_id' );
		if ( ! $coachee_id ) {
			return new WP_Error( 'bad_request', 'coachee_id required', [ 'status' => 400 ] );
		}

		if ( ! self::_can_access_coachee( $coachee_id ) ) {
			return new WP_Error( 'forbidden', 'Access denied', [ 'status' => 403 ] );
		}

		$regenerate = (bool) $req->get_param( 'regenerate' );

		// Return cached unless regenerate requested.
		if ( ! $regenerate ) {
			$stored = get_option( 'bccm_natal_en_' . $coachee_id, null );
			if ( is_array( $stored ) && ! empty( $stored['content'] ) ) {
				return rest_ensure_response( [
					'ok'   => true,
					'data' => [
						'content'      => (string) $stored['content'],
						'generated_at' => (string) ( $stored['generated_at'] ?? '' ),
						'cached'       => true,
					],
				] );
			}
		}

		// Need LLM helpers from legacy/lib/astro-report-llm.php.
		if ( ! function_exists( 'bccm_llm_build_chart_context' ) || ! function_exists( 'bccm_llm_call_openai' ) ) {
			return new WP_Error(
				'module_not_loaded',
				'LLM report helpers are not loaded. Ensure legacy adopter has booted.',
				[ 'status' => 503 ]
			);
		}

		global $wpdb;
		$coachee = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bccm_coachees WHERE id=%d", $coachee_id ),
			ARRAY_A
		);
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Coachee not found', [ 'status' => 404 ] );
		}

		// Load western astro row (English report is western-only).
		$user_id   = (int) ( $coachee['user_id'] ?? 0 );
		$astro_row = null;
		if ( $user_id ) {
			$astro_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}bccm_astro WHERE user_id=%d AND chart_type='western' AND traits IS NOT NULL",
					$user_id
				),
				ARRAY_A
			);
		}
		if ( ! $astro_row ) {
			$astro_row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}bccm_astro WHERE coachee_id=%d AND chart_type='western'",
					$coachee_id
				),
				ARRAY_A
			);
		}
		if ( ! $astro_row ) {
			return new WP_Error(
				'not_found',
				'No western natal chart found for this coachee. Generate the chart first.',
				[ 'status' => 404 ]
			);
		}

		// Build chart context (Vietnamese helper — reuse for data, prompt will be in English).
		$chart_ctx = bccm_llm_build_chart_context( $astro_row, $coachee );
		$name      = sanitize_text_field( $coachee['full_name'] ?? 'the native' );

		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — remove years-of-experience
		// claim from internal English system prompt.
		$system = <<<'PROMPT'
You are an expert Western astrologer writing detailed, professional English natal chart interpretations. Your reports are insightful, poetic yet grounded, and connect astrological symbolism to real-life patterns.

WRITING STYLE:
- Professional, warm, and empowering — never fatalistic
- Clear English accessible to people unfamiliar with astrology
- Rich in metaphor and psychological depth
- Specific and personalized — always reference the actual chart data
- Do not mention years of experience or self-promotional credentials

FORMAT RULES:
- Use Markdown: ## for main headings, ### for sub-headings, **bold**, *italic*, bullet lists
- Write in full paragraphs — no bullet lists for interpretive content
- Do NOT truncate or summarize — provide complete analysis
PROMPT;

		$user = "Write a comprehensive English natal chart report for **{$name}** based on the data below.\n\n"
			. "Structure your report with these sections:\n"
			. "## Overview & Birth Chart Signature\n"
			. "## ☉ Sun — Core Identity & Life Purpose\n"
			. "## ☽ Moon — Emotional Nature & Inner World\n"
			. "## ↑ Ascendant — Outer Self & Approach to Life\n"
			. "## ☿ Mercury & ♀ Venus — Mind, Communication & Love\n"
			. "## ♂ Mars — Drive, Ambition & Action\n"
			. "## ♃ Jupiter & ♄ Saturn — Growth, Wisdom & Life Lessons\n"
			. "## 🌌 Outer Planets — Generational Themes & Transformation\n"
			. "## 🏛️ House Emphases — Key Life Areas\n"
			. "## 🔗 Major Aspects & Chart Patterns\n"
			. "## 🌟 Soul Purpose & Life Guidance\n\n"
			. "Write at least 2000 words total. Be specific and reference the actual planetary positions.\n\n"
			. "CHART DATA:\n{$chart_ctx}";

		$result = bccm_llm_call_openai( $system, $user, [
			'max_tokens'  => 8000,
			'temperature' => 0.72,
			'timeout'     => 180,
		] );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();
			$msg  = $result->get_error_message();
			error_log( '[BizCoach-Pro] English report LLM error (' . $code . '): ' . $msg );
			return new WP_Error(
				$code,
				$msg,
				[ 'status' => 'quota_exhausted' === $code ? 429 : 502 ]
			);
		}

		$content = (string) $result;
		if ( $content === '' ) {
			return new WP_Error( 'llm_error', 'LLM returned empty content.', [ 'status' => 502 ] );
		}

		$generated_at = gmdate( 'Y-m-d H:i:s' );
		update_option(
			'bccm_natal_en_' . $coachee_id,
			[
				'content'      => $content,
				'generated_at' => $generated_at,
			],
			false // autoload=no — accessed only on demand
		);

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'content'      => $content,
				'generated_at' => $generated_at,
				'cached'       => false,
			],
		] );
	}

	/**
	 * Check whether the current user may access a coachee's data.
	 *
	 * @param int $coachee_id
	 * @return bool
	 */
	private static function _can_access_coachee( $coachee_id ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bccm_coachees WHERE id=%d",
				$coachee_id
			)
		);
		return $owner === get_current_user_id();
	}
}

BizCoach_Pro_Astro_Rest::init();
