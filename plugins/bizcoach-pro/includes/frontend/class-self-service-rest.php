<?php
/**
 * BizCoach Pro — Self-Service Astrology Profile REST API
 *
 * REST endpoints scoped to the currently logged-in user.
 * Users can ONLY read/write their OWN profiles — never others'.
 *
 * Namespace : bizcity-bizcoach/v1
 * Routes    : /me/profiles, /me/profiles/{id}, /me/profiles/{id}/generate-chart,
 *             /me/profiles/{id}/share-link
 *
 * @package BizCoach_Pro
 * @since   0.5.0 (PHASE-A A-FE-1 · 2026-06-05)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCoach_Pro_Self_Service_REST' ) ) { return; }

class BizCoach_Pro_Self_Service_REST {

	// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — namespace matches existing astro REST
	const NS = 'bizcity-bizcoach/v1';
	// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — keep v1 aliases for backward
	// compatibility while canonical writer/migration now stamps `_v2` markers.
	const TRANSIT_SOURCE_DO_FETCH       = 'do_transit_fetch';
	const TRANSIT_SOURCE_LEGACY_PREFETCH = 'legacy_prefetch';
	const TRANSIT_SOURCE_DO_FETCH_V2       = 'do_transit_fetch_v2';
	const TRANSIT_SOURCE_LEGACY_PREFETCH_V2 = 'legacy_prefetch_v2';
	// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — v3: re-calculate aspects
	// for empty-aspects rows written before the A10 natal-shape fix.
	const TRANSIT_MIGRATION_VERSION     = '20260706_v3';
	const TRANSIT_MIGRATION_BATCH_SIZE  = 200;
	// [2026-07-09 Johnny Chu] PHASE-A5 — option cache for pro chart responses.
	const PRO_CHART_CACHE_OPTION_PREFIX = 'bcpro_a5_chart_cache_';
	const PRO_CHART_CACHE_INDEX_PREFIX  = 'bcpro_a5_chart_cache_u_';
	const PRO_CHART_CACHE_SCHEMA        = '20260709_v1';
	const PRO_CHART_CACHE_TTL           = 900;
	// [2026-07-09 Johnny Chu] PHASE-A5 — public share tokens for PRO chart tools.
	const PRO_CHART_SHARE_OPTION_PREFIX = 'bcpro_a5_share_';
	const PRO_CHART_SHARE_SCHEMA        = '20260709_v1';
	const PRO_CHART_SHARE_TTL           = 604800;
	// [2026-07-09 Johnny Chu] PHASE-A5 — public share tokens for non-chart tool snapshots.
	const TOOL_SHARE_OPTION_PREFIX      = 'bcpro_a5_tool_share_';
	const TOOL_SHARE_SCHEMA             = '20260709_v1';
	const TOOL_SHARE_TTL                = 604800;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// [2026-06-08 Johnny Chu] HOTFIX — async transit cron hook (scheduled after chart gen)
		add_action( 'bcpro_async_rebuild_transit', array( __CLASS__, 'handle_async_transit' ), 10, 2 );
	}

	public static function register_routes() {
		// List + Create
		register_rest_route( self::NS, '/me/profiles', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_profiles' ),
				'permission_callback' => 'is_user_logged_in',
				// [2026-06-06 Johnny Chu] PHASE-B B-FE-22 — scope=all for admin view
				'args' => array(
					'scope' => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'me',
						'enum'              => array( 'me', 'all' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_profile' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => self::profile_schema_args(),
			),
		) );

		// Update + Delete
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)', array(
			array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'update_profile' ),
				'permission_callback' => array( __CLASS__, 'can_own_profile' ),
				// [2026-07-03 Johnny Chu] PHASE-FAA2-FE — declare args so WP REST
				// validates birth_lat/birth_lng/geoname_id instead of rejecting them
				// with rest_invalid_param (all fields optional for PATCH)
				'args'                => self::profile_schema_args_patch(),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_profile' ),
				'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			),
		) );

		// Generate chart
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/generate-chart', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'generate_chart' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// [2026-06-09 Johnny Chu] PHASE-D D-BE-REGEN-SVG — SVG-only retry endpoint.
		// Does NOT re-run natal calculations; only re-calls chart-svg with stored birth data.
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/regen-svg', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'regen_svg' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			'args'                => array(
				'chart_type' => array( 'type' => 'string', 'required' => false, 'default' => 'western', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-07-09 Johnny Chu] PHASE-FAA2-FE — FAA2 Natal Wheel Chart endpoint.
		// Calls faa2_western::natal_wheel_chart() → S3 dark SVG URL, saves to bccm_astro.chart_svg.
		// Separate from regen-svg (which fetches inline SVG via faa_western) so FE can trigger independently.
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/generate-wheel-chart', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'generate_wheel_chart' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			'args'                => array(
				'chart_type' => array( 'type' => 'string', 'required' => false, 'default' => 'western', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — Astro data checklist (per-coachee fetch status).
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/astro-checklist', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_astro_checklist' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );
		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — Full sequential fetch: Western + Vedic + transit flag.
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/fetch-all-astro', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'fetch_all_astro' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// Share link
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/share-link', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_share_link' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — chart data (traits JSON → AstrologerStudio shape)
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/chart-data', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_chart_data' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// [2026-06-10 Johnny Chu] PHASE-REPORT RPT-BE-1 — report sections meta
		// (list of LLM chapters + which are cached) so FE can render the
		// print-style report skeleton + lazy-load each chapter.
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/report-meta', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_report_meta' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			'args'                => array(
				'chart_type' => array( 'type' => 'string', 'required' => false, 'default' => 'western', 'sanitize_callback' => 'sanitize_key' ),
			),
		) );

		// [2026-06-10 Johnny Chu] PHASE-REPORT RPT-BE-2 — generate/fetch one LLM
		// report chapter. Mirrors legacy AJAX `bccm_llm_section` caching
		// (bccm_astro.llm_report JSON {chart_hash, sections[], generated}) but
		// authed via self-service can_own_profile + X-WP-Nonce (no edit_posts).
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/report-section', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_report_section' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			'args'                => array(
				'chart_type' => array( 'type' => 'string',  'required' => false, 'default' => 'western', 'sanitize_callback' => 'sanitize_key' ),
				'section'    => array( 'type' => 'integer', 'required' => true ),
				'regenerate' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			),
		) );

		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — transit JSON proxy
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/transit', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_transit' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — moon phase proxy
		register_rest_route( self::NS, '/me/moon-phase', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_moon_phase' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-1 — geo search proxy (FE WPProfileFormDialog autocomplete)
		register_rest_route( self::NS, '/geo/search', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'geo_search' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'q'       => array( 'type' => 'string',  'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
				'country' => array( 'type' => 'string',  'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
				'limit'   => array( 'type' => 'integer', 'required' => false, 'default' => 10 ),
			),
		) );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-6 — moon month grid proxy
		register_rest_route( self::NS, '/me/moon-month', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_moon_month' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-3 — saved transit calculations (list)
		register_rest_route( self::NS, '/me/saved-calculations', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_saved_calculations' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'coachee_id'       => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'limit'            => array( 'type' => 'integer', 'required' => false, 'default' => 20 ),
				'offset'           => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'include_details'  => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			),
		) );

		// [2026-07-10 Johnny Chu] PHASE-FAA2 — Transit save history (JSONL under /astro).
		register_rest_route( self::NS, '/me/transit-logs', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'list_transit_logs' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'coachee_id' => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'source'     => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'status'     => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'date'       => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'date_from'  => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'date_to'    => array( 'type' => 'string',  'required' => false, 'default' => '' ),
				'days'       => array( 'type' => 'integer', 'required' => false, 'default' => 30 ),
				'limit'      => array( 'type' => 'integer', 'required' => false, 'default' => 50 ),
				'offset'     => array( 'type' => 'integer', 'required' => false, 'default' => 0 ),
				'include_details' => array( 'type' => 'boolean', 'required' => false, 'default' => false ),
			),
		) );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-4 — saved calc delete
		register_rest_route( self::NS, '/me/saved-calculations/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => array( __CLASS__, 'delete_saved_calculation' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-8 — gateway quota proxy
		register_rest_route( self::NS, '/me/quota', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_quota' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — same-origin entitlement proxy for
		// FE feature gate and PRO/PREMIUM badge behavior.
		register_rest_route( self::NS, '/me/entitlement', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_entitlement' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — same-origin pro chart fetch endpoints.
		register_rest_route( self::NS, '/me/charts/synastry', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_chart_synastry' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — same-origin pro chart fetch endpoints.
		register_rest_route( self::NS, '/me/charts/composite', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_chart_composite' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — same-origin pro chart fetch endpoints.
		register_rest_route( self::NS, '/me/charts/solar-return', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_chart_solar_return' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — same-origin pro chart fetch endpoints.
		register_rest_route( self::NS, '/me/charts/lunar-return', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'handle_chart_lunar_return' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — create a public share token for chart tool results.
		register_rest_route( self::NS, '/me/charts/share', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_pro_chart_share' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — resolve public share token without login.
		register_rest_route( self::NS, '/public/charts/share/(?P<token>[A-Za-z0-9_-]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_public_pro_chart_share' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — create public share token for
		// Relations/Ephemeris/Transits Timeline snapshots.
		register_rest_route( self::NS, '/me/tools/share', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'create_public_tool_share' ),
			'permission_callback' => 'is_user_logged_in',
		) );

		// [2026-07-09 Johnny Chu] PHASE-A5 — resolve non-chart public share token without login.
		register_rest_route( self::NS, '/public/tools/share/(?P<token>[A-Za-z0-9_-]+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_public_tool_share' ),
			'permission_callback' => '__return_true',
		) );

		// [2026-06-07 Johnny Chu] PHASE-C C-BE-2 — per-user usage aggregate (tokens/img/video/freeastro)
		register_rest_route( self::NS, '/me/usage-summary', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_usage_summary' ),
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'range' => array(
					'type'              => 'string',
					'default'           => '30d',
					'enum'              => array( '7d', '30d', '90d' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-9 — manual sync-transit for FE button
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/sync-transit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'sync_transit' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT — read cached 30-day transit from bccm_transit_snapshots
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/transit-cache', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_transit_cache' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			'args'                => array(
				'days'  => array( 'type' => 'integer', 'required' => false, 'default' => 30 ),
				'start' => array( 'type' => 'string',  'required' => false, 'default' => '' ),
			),
		) );

		// [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT — trigger 30-day batch transit fetch (manual rebuild)
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/rebuild-transit', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rebuild_transit_30d' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
		) );

		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — live day-by-day transit range
		// Khác rebuild-transit: không ghi DB, dùng transit_range() có shared planet-position cache.
		// Mục đích: FE query ự "bản đồ sao + 30 ngày tới" trong 1 request.
		register_rest_route( self::NS, '/me/profiles/(?P<id>\d+)/transit-range', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_transit_range' ),
			'permission_callback' => array( __CLASS__, 'can_own_profile' ),
			'args'                => array(
				'start'      => array( 'type' => 'string',  'required' => false, 'default' => '',    'sanitize_callback' => 'sanitize_text_field' ),
				'days'       => array( 'type' => 'integer', 'required' => false, 'default' => 30 ),
				'outer_only' => array( 'type' => 'boolean', 'required' => false, 'default' => true ),
			),
		) );

		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-4 — Relations (Ashtakoot compatibility)
		register_rest_route( self::NS, '/me/relations', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_relations' ),
				'permission_callback' => 'is_user_logged_in',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_relation' ),
				'permission_callback' => 'is_user_logged_in',
				'args'                => array(
					'subject_coachee' => array( 'type' => 'integer', 'required' => true ),
					// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — R-COACHEE.7: canonical enum
					'relation_type'   => array(
						'type'              => 'string',
						'required'          => false,
						'default'           => 'general',
						'sanitize_callback' => 'sanitize_key',
						'enum'              => array( 'general', 'spouse', 'partner', 'family', 'colleague', 'employee', 'friend', 'customer', 'business_partner' ),
					),
				),
			),
		) );

		register_rest_route( self::NS, '/me/relations/(?P<id>\d+)', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_relation' ),
			'permission_callback' => array( __CLASS__, 'can_own_relation' ),
		) );

		register_rest_route( self::NS, '/me/relations/(?P<id>\d+)/interpret', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'interpret_relation' ),
			'permission_callback' => array( __CLASS__, 'can_own_relation' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * Permission helper — verifies coachee belongs to current user (A01)
	 * ------------------------------------------------------------------ */
	public static function can_own_profile( $request ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		// [2026-06-08 Johnny Chu] HOTFIX — admins can manage any profile
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		global $wpdb;
		$tbl = $wpdb->prefix . 'bccm_coachees';
		$uid = (int) get_current_user_id();
		$cid = (int) $request->get_param( 'id' );
		if ( $cid <= 0 ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE id = %d AND user_id = %d LIMIT 1",
			$cid, $uid
		) );
	}

	/* ------------------------------------------------------------------ *
	 * GET /me/profiles
	 *
	 * [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — normalize: group by coachee_id,
	 * return id + chart_types[] + share_urls{} + has_chart to match React types.
	 * [2026-06-06 Johnny Chu] PHASE-B B-FE-22 — scope=all: admins see all users' profiles,
	 * grouped as own (is_own=true) first, then others. Non-admin ignores scope.
	 * ------------------------------------------------------------------ */
	public static function list_profiles( $request = null ) {
		global $wpdb;
		$uid     = (int) get_current_user_id();
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_plans = $wpdb->prefix . 'bccm_action_plans';
		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — canonical self flag source.
		$has_self_col = class_exists( 'BizCoach_Pro_Self_Profile_Manager' )
			&& BizCoach_Pro_Self_Profile_Manager::has_is_self_column();
		$self_select  = $has_self_col ? 'c.is_self AS is_self,' : '0 AS is_self,';

		// [2026-06-06 Johnny Chu] PHASE-B B-FE-22 — scope=all only for manage_options users
		$scope = 'me';
		if ( $request && $request->get_param( 'scope' ) === 'all' && current_user_can( 'manage_options' ) ) {
			$scope = 'all';
		}

		if ( $scope === 'all' ) {
			$rows = $wpdb->get_results(
				"SELECT c.id          AS coachee_id,
				        c.user_id,
				        c.full_name,
				        c.dob,
				        c.phone,
				        c.extra_fields_json,
				        {$self_select}
				        c.created_at  AS coachee_created_at,
				        a.chart_type,
				        a.birth_time,
				        a.birth_place,
				        a.updated_at,
				        CASE WHEN a.summary IS NOT NULL AND a.summary <> '' THEN 1 ELSE 0 END AS has_chart,
				        p.public_key,
				        u.user_login
				 FROM {$t_coach} c
				 LEFT JOIN {$t_astro} a  ON a.coachee_id = c.id
				 LEFT JOIN {$t_plans} p  ON p.coachee_id = c.id AND p.status = 'active'
				 LEFT JOIN {$wpdb->users} u ON u.ID = c.user_id
				 ORDER BY CASE WHEN c.user_id = {$uid} THEN 0 ELSE 1 END ASC, c.id DESC, a.chart_type ASC",
				ARRAY_A
			);
		} else {
			// [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT — ORDER BY c.id ASC so oldest profile
			// (the "ch\u00ednh ch\u1ee7") comes first — matches primaryId = ownProfiles[0] in FE.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT c.id          AS coachee_id,
				        c.user_id,
				        c.full_name,
				        c.dob,
				        c.phone,
				        c.extra_fields_json,
				        {$self_select}
				        c.created_at  AS coachee_created_at,
				        a.chart_type,
				        a.birth_time,
				        a.birth_place,
				        a.updated_at,
				        CASE WHEN a.summary IS NOT NULL AND a.summary <> '' THEN 1 ELSE 0 END AS has_chart,
				        a.chart_svg,
				        p.public_key,
				        '' AS user_login
				 FROM {$t_coach} c
				 LEFT JOIN {$t_astro} a ON a.coachee_id = c.id AND a.chart_type = 'western'
				 LEFT JOIN {$t_plans} p ON p.coachee_id = c.id AND p.status = 'active'
				 WHERE c.user_id = %d
				 ORDER BY c.id ASC",
				$uid
			), ARRAY_A );
		}

		// [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT — track first own-profile ID for is_primary flag.
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-2 — fallback to first-own only when is_self column is absent.
		$first_own_id = null;

		// Group rows by coachee_id — one coachee can have multiple chart types.
		$by_id = array();
		foreach ( (array) $rows as $r ) {
			$cid   = (int) $r['coachee_id'];
			$r_uid = (int) $r['user_id'];
			if ( ! isset( $by_id[ $cid ] ) ) {
				// [2026-06-06 Johnny Chu] PHASE-B B-BE-2 — hoist birth_coords from extra_fields_json
				$coords = self::read_birth_coords( (string) ( $r['extra_fields_json'] ?? '' ) );
				// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — track oldest own profile as fallback primary.
				// Scope=all query may be DESC, so do not rely on first row order.
				if ( $r_uid === $uid && ( $first_own_id === null || $cid < $first_own_id ) ) {
					$first_own_id = $cid;
				}
				// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — honor DB is_self flag for all scopes.
				$is_primary = $has_self_col ? ( (int) ( $r['is_self'] ?? 0 ) === 1 ) : false;
				$by_id[ $cid ] = array(
					'id'           => $cid,
					'user_id'      => $r_uid,
					'owner_login'  => (string) ( $r['user_login'] ?? '' ),
					'is_own'       => $r_uid === $uid,
					'is_primary'   => $is_primary, // [PHASE-FAA2-NEXT] from is_self column
					'full_name'    => (string) $r['full_name'],
					'dob'          => (string) $r['dob'],
					'phone'        => (string) ( $r['phone'] ?? '' ),
					'birth_time'   => (string) ( $r['birth_time'] ?? '' ),
					'birth_place'  => (string) ( $r['birth_place'] ?? '' ),
					'birth_lat'    => isset( $coords['lat'] ) ? (float) $coords['lat'] : null,
					'birth_lng'    => isset( $coords['lng'] ) ? (float) $coords['lng'] : null,
					'birth_tz'     => isset( $coords['tz'] )  ? (string) $coords['tz']  : '',
					'created_at'   => (string) ( $r['coachee_created_at'] ?? '' ),
					'updated_at'   => (string) ( $r['updated_at'] ?? '' ),
					'chart_types'  => array(),
					'has_chart'    => false,
					'chart_svg'    => '',
					'share_urls'   => array(),
				);
			}

			$ct = ! empty( $r['chart_type'] ) ? (string) $r['chart_type'] : '';
			if ( $ct && ! in_array( $ct, $by_id[ $cid ]['chart_types'], true ) ) {
				$by_id[ $cid ]['chart_types'][] = $ct;
			}

			if ( (bool) $r['has_chart'] ) {
				$by_id[ $cid ]['has_chart'] = true;
			}

			// Hoist chart_svg from western row.
			if ( ! empty( $r['chart_svg'] ) && $by_id[ $cid ]['chart_svg'] === '' ) {
				$by_id[ $cid ]['chart_svg'] = (string) $r['chart_svg'];
			}

			// Build share URL for this chart type (natal view)
			if ( $ct && ! empty( $r['public_key'] ) && function_exists( 'bcpro_get_astro_public_url' ) ) {
				$share_url = (string) bcpro_get_astro_public_url( $cid, $ct );
				if ( $share_url ) {
					$by_id[ $cid ]['share_urls'][ 'natal_view_' . $ct ] = $share_url;
				}
			}

			// Prefer western chart's birth_time/birth_place; fallback first available.
			if ( $ct === 'western' || ! $by_id[ $cid ]['birth_time'] ) {
				if ( $r['birth_time'] )  { $by_id[ $cid ]['birth_time']  = (string) $r['birth_time']; }
				if ( $r['birth_place'] ) { $by_id[ $cid ]['birth_place'] = (string) $r['birth_place']; }
				if ( $r['updated_at'] )  { $by_id[ $cid ]['updated_at']  = (string) $r['updated_at']; }
			}
		}

		// [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT — mark first own profile as is_primary.
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-2 — fallback: if no is_self column yet, use first_own_id.
		if ( ! $has_self_col && $first_own_id !== null && isset( $by_id[ $first_own_id ] ) ) {
			$by_id[ $first_own_id ]['is_primary'] = true;
		}

		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — inject full public links
		// so Dashboard/Profile quick tools always have VN report + transit URLs.
		foreach ( $by_id as $cid => $profile_row ) {
			$_links = self::build_public_share_urls( (int) $cid );
			if ( ! empty( $_links ) ) {
				$by_id[ $cid ]['share_urls'] = array_merge( $by_id[ $cid ]['share_urls'], $_links );
			}
			$ocurl = self::build_natal_report_url( $profile_row );
			if ( $ocurl ) {
				$by_id[ $cid ]['share_urls']['natal_report_open_chart'] = $ocurl;
			}
		}

		return rest_ensure_response( array(
			'success'  => true,
			'scope'    => $scope,
			'is_admin' => current_user_can( 'manage_options' ),
			'profiles' => array_values( $by_id ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * POST /me/profiles
	 * ------------------------------------------------------------------ */
	public static function create_profile( $request ) {
		global $wpdb;
		$uid       = (int) get_current_user_id();
		$t_coach   = $wpdb->prefix . 'bccm_coachees';
		$t_astro   = $wpdb->prefix . 'bccm_astro';

		$full_name   = sanitize_text_field( (string) $request->get_param( 'full_name' ) );
		$dob         = sanitize_text_field( (string) $request->get_param( 'dob' ) );
		$birth_time  = sanitize_text_field( (string) ( $request->get_param( 'birth_time' ) ?? '' ) );
		$birth_place = sanitize_text_field( (string) ( $request->get_param( 'birth_place' ) ?? '' ) );
		$chart_type  = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );
		$phone       = sanitize_text_field( (string) ( $request->get_param( 'phone' ) ?? '' ) );
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-2 — R-COACHEE.3: is_self flag for chính chủ
		$is_self_req = ! empty( $request->get_param( 'is_self' ) );

		if ( $full_name === '' || $dob === '' ) {
			return new WP_Error( 'invalid_param', 'full_name và dob là bắt buộc.', array( 'status' => 400 ) );
		}

		if ( ! in_array( $chart_type, array( 'western', 'vedic', 'chinese' ), true ) ) {
			$chart_type = 'western';
		}

		$now = current_time( 'mysql' );

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-2 — collect birth coords for extra_fields_json
		$coords = self::collect_birth_coords( $request );
		$extra  = $coords ? wp_json_encode( array( 'birth_coords' => $coords ), JSON_UNESCAPED_UNICODE ) : null;

		// Insert coachee
		$coach_insert = array(
			'user_id'       => $uid,
			'full_name'     => $full_name,
			'dob'           => $dob,
			'phone'         => $phone,
			'platform_type' => 'WEBCHAT',
			'coach_type'    => 'astrology',
			'created_at'    => $now,
			'updated_at'    => $now,
		);
		if ( $extra !== null ) {
			$coach_insert['extra_fields_json'] = $extra;
		}
		$inserted = $wpdb->insert( $t_coach, $coach_insert );

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', 'Không thể tạo hồ sơ.', array( 'status' => 500 ) );
		}

		$coachee_id = (int) $wpdb->insert_id;

		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — canonical unique-primary via shared manager.
		if ( class_exists( 'BizCoach_Pro_Self_Profile_Manager' )
			&& ( $is_self_req || ! BizCoach_Pro_Self_Profile_Manager::user_has_self( $uid ) ) ) {
			BizCoach_Pro_Self_Profile_Manager::set_self_coachee( $uid, $coachee_id );
		}

		// Insert astro row (empty chart — user triggers generate separately)
		$wpdb->insert( $t_astro, array(
			'coachee_id'  => $coachee_id,
			'user_id'     => $uid,
			'chart_type'  => $chart_type,
			'birth_time'  => $birth_time,
			'birth_place' => $birth_place,
			'created_at'  => $now,
			'updated_at'  => $now,
		) );

		$astro_id = (int) $wpdb->insert_id;

		// [2026-06-08 Johnny Chu] HOTFIX — auto-generate natal chart immediately after create
		$chart_result = self::do_chart_generate_for_coachee( $coachee_id, $uid, $chart_type );
		if ( ! empty( $chart_result['success'] ) ) {
			self::schedule_transit_async( $coachee_id, $uid );
		}

		// [2026-07-09 Johnny Chu] PHASE-A5 — invalidate pro-chart option cache after CRUD create.
		self::invalidate_pro_chart_cache_for_user( $uid );

		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — return normalized profile object for React
		return rest_ensure_response( array(
			'success'         => true,
			'chart_generated' => ! empty( $chart_result['success'] ),
			'chart_message'   => isset( $chart_result['message'] ) ? $chart_result['message'] : '',
			'profile'         => self::fetch_normalized_profile( $coachee_id, $uid ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * PATCH /me/profiles/{id}
	 * ------------------------------------------------------------------ */
	public static function update_profile( $request ) {
		global $wpdb;
		$coachee_id = (int) $request->get_param( 'id' );
		$t_coach    = $wpdb->prefix . 'bccm_coachees';
		$t_astro    = $wpdb->prefix . 'bccm_astro';
		$now        = current_time( 'mysql' );

		// [2026-06-08 Johnny Chu] HOTFIX — admins use profile's actual owner uid for DB ops
		$uid = self::resolve_owner_uid( $coachee_id );
		if ( ! $uid ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		$coach_data = array();
		$astro_data = array();

		if ( $request->get_param( 'full_name' ) !== null ) {
			$coach_data['full_name'] = sanitize_text_field( (string) $request->get_param( 'full_name' ) );
		}
		if ( $request->get_param( 'dob' ) !== null ) {
			$coach_data['dob'] = sanitize_text_field( (string) $request->get_param( 'dob' ) );
		}
		if ( $request->get_param( 'phone' ) !== null ) {
			$coach_data['phone'] = sanitize_text_field( (string) $request->get_param( 'phone' ) );
		}
		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — allow PATCH to set/unset self profile.
		$set_self_explicit = ( $request->get_param( 'is_self' ) !== null );
		$want_self         = $set_self_explicit ? ! empty( $request->get_param( 'is_self' ) ) : false;
		if ( $request->get_param( 'birth_time' ) !== null ) {
			$astro_data['birth_time'] = sanitize_text_field( (string) $request->get_param( 'birth_time' ) );
		}
		if ( $request->get_param( 'birth_place' ) !== null ) {
			$astro_data['birth_place'] = sanitize_text_field( (string) $request->get_param( 'birth_place' ) );
		}

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-2 — merge new coords into extra_fields_json
		$coords = self::collect_birth_coords( $request );
		if ( $coords ) {
			// [2026-07-09 Johnny Chu] PHASE-FAA2-FE FIX — removed AND user_id=%d; profiles with
			// user_id=0 (admin-created) would return empty $existing_extra, losing prior data.
			$existing_extra = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT extra_fields_json FROM {$t_coach} WHERE id = %d LIMIT 1",
				$coachee_id
			) );
			$merged = $existing_extra ? json_decode( $existing_extra, true ) : array();
			if ( ! is_array( $merged ) ) { $merged = array(); }
			$merged['birth_coords'] = $coords;
			$coach_data['extra_fields_json'] = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE );
		}

		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — only reset chart when birth data ACTUALLY
		// differs from stored values. Previously any PATCH containing birth_time/birth_place
		// (even empty string) triggered a reset + failed regen → chart disappeared.
		$birth_changed = false;
		if ( isset( $astro_data['birth_time'] ) || isset( $astro_data['birth_place'] ) || isset( $coach_data['dob'] ) ) {
			$_stored_astro = $wpdb->get_row( $wpdb->prepare(
				"SELECT birth_time, birth_place FROM {$t_astro} WHERE coachee_id = %d ORDER BY id DESC LIMIT 1",
				$coachee_id
			), ARRAY_A );
			$_stored_coach = $wpdb->get_row( $wpdb->prepare(
				"SELECT dob FROM {$t_coach} WHERE id = %d LIMIT 1", $coachee_id
			), ARRAY_A );

			$birth_changed =
				( isset( $astro_data['birth_time'] )  && (string) $astro_data['birth_time']  !== (string) ( $_stored_astro['birth_time']  ?? '' ) )
				|| ( isset( $astro_data['birth_place'] ) && (string) $astro_data['birth_place'] !== (string) ( $_stored_astro['birth_place'] ?? '' ) )
				|| ( isset( $coach_data['dob'] )         && (string) $coach_data['dob']          !== (string) ( $_stored_coach['dob']         ?? '' ) );
		}
		if ( $birth_changed ) {
			$astro_data['summary']    = null;
			$astro_data['llm_report'] = null;
			$astro_data['chart_svg']  = null;
		}

		if ( ! empty( $coach_data ) ) {
			$coach_data['updated_at'] = $now;
			// [2026-07-09 Johnny Chu] PHASE-FAA2-FE FIX — use id only in WHERE; user_id filter
			// silently failed for admin-created profiles with user_id=0. Ownership already
			// verified by can_own_profile + resolve_owner_uid above.
			$wpdb->update( $t_coach, $coach_data, array( 'id' => $coachee_id ) );
		}
		if ( ! empty( $astro_data ) ) {
			$astro_data['updated_at'] = $now;
			// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — UPSERT: INSERT if no row exists, UPDATE if exists.
			// $wpdb->update() silently does nothing when no bccm_astro row exists for this coachee
			// (e.g. profile created before astro table existed, or insert failed at creation).
			// UNIQUE KEY uniq_coachee_chart (coachee_id, chart_type) makes ON DUPLICATE KEY safe.
			// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — ORDER BY id DESC: update the newest row.
			$_existing_astro_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t_astro} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
				$coachee_id
			) );
			if ( $_existing_astro_id ) {
				$wpdb->update( $t_astro, $astro_data, array( 'id' => $_existing_astro_id ) );
			} else {
				// No row exists — insert one with base fields + the incoming data
				$_insert_astro = array_merge( array(
					'coachee_id' => $coachee_id,
					'user_id'    => $uid,
					'chart_type' => 'western',
					'created_at' => $now,
				), $astro_data );
				// strip null values that might violate NOT NULL columns
				unset( $_insert_astro['summary'], $_insert_astro['llm_report'], $_insert_astro['chart_svg'] );
				$wpdb->insert( $t_astro, $_insert_astro );
			}
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — persist self/primary flag change via shared manager.
		if ( $set_self_explicit ) {
			if ( class_exists( 'BizCoach_Pro_Self_Profile_Manager' ) ) {
				if ( $want_self ) {
					BizCoach_Pro_Self_Profile_Manager::set_self_coachee( $uid, $coachee_id );
				} else {
					BizCoach_Pro_Self_Profile_Manager::unset_self_coachee( $uid, $coachee_id );
				}
			}
		}

		// [2026-06-08 Johnny Chu] HOTFIX — auto-generate natal chart after save
		$chart_result = self::do_chart_generate_for_coachee( $coachee_id, $uid, 'western' );
		if ( ! empty( $chart_result['success'] ) ) {
			self::schedule_transit_async( $coachee_id, $uid );
		}

		// [2026-07-09 Johnny Chu] PHASE-A5 — invalidate pro-chart option cache after CRUD update.
		self::invalidate_pro_chart_cache_for_user( $uid );

		// [2026-06-05 Johnny Chu] PHASE-A A-FE-1 — return normalized profile object for React
		return rest_ensure_response( array(
			'success'         => true,
			'chart_reset'     => $birth_changed,
			'chart_generated' => ! empty( $chart_result['success'] ),
			'chart_message'   => isset( $chart_result['message'] ) ? $chart_result['message'] : '',
			'profile'         => self::fetch_normalized_profile( $coachee_id, $uid ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * DELETE /me/profiles/{id}
	 * ------------------------------------------------------------------ */
	public static function delete_profile( $request ) {
		global $wpdb;
		$uid        = (int) get_current_user_id();
		$coachee_id = (int) $request->get_param( 'id' );
		$owner_uid  = (int) self::resolve_owner_uid( $coachee_id );
		$t_coach    = $wpdb->prefix . 'bccm_coachees';
		$t_astro    = $wpdb->prefix . 'bccm_astro';

		$wpdb->delete( $t_astro, array( 'coachee_id' => $coachee_id ) );
		// [2026-06-08 Johnny Chu] HOTFIX — admins can delete any profile (no user_id filter)
		if ( current_user_can( 'manage_options' ) ) {
			$wpdb->delete( $t_coach, array( 'id' => $coachee_id ) );
		} else {
			$wpdb->delete( $t_coach, array( 'id' => $coachee_id, 'user_id' => $uid ) );
		}

		// [2026-07-09 Johnny Chu] PHASE-A5 — invalidate pro-chart option cache after CRUD delete.
		self::invalidate_pro_chart_cache_for_user( $owner_uid > 0 ? $owner_uid : $uid );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/* ------------------------------------------------------------------ *
	 * POST /me/profiles/{id}/generate-chart
	 * ------------------------------------------------------------------ */
	public static function generate_chart( $request ) {
		$coachee_id = (int) $request->get_param( 'id' );
		$chart_type = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );

		if ( ! in_array( $chart_type, array( 'western', 'vedic', 'chinese' ), true ) ) {
			$chart_type = 'western';
		}

		// [2026-06-08 Johnny Chu] HOTFIX — resolve actual owner uid (admin-aware)
		$uid = self::resolve_owner_uid( $coachee_id );
		if ( ! $uid ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		// [2026-06-08 Johnny Chu] HOTFIX — capture debug trace for FE diagnosis.
		// Hooks into bcpro_astro_client_call (fired by BizCoach_Pro_Astro_Client::call
		// after every gateway round-trip) so FE can see exact status + error path.
		$debug_trace = array();
		$trace_hook  = function ( $res, $path, $method ) use ( &$debug_trace ) {
			$debug_trace[] = array(
				'method'     => (string) $method,
				'path'       => (string) $path,
				'success'    => ! empty( $res['success'] ),
				'http'       => $res['http']  ?? null,
				'error'      => $res['error'] ?? null,
				'env_keys'   => is_array( $res['envelope'] ?? null ) ? array_keys( (array) $res['envelope'] ) : array(),
			);
		};
		add_action( 'bcpro_astro_client_call', $trace_hook, 10, 3 );

		$result = self::do_chart_generate_for_coachee( $coachee_id, $uid, $chart_type );

		remove_action( 'bcpro_astro_client_call', $trace_hook, 10 );

		if ( isset( $result['_is_wp_error'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $result['message'],
				'_debug'    => $debug_trace,
			) );
		}
		if ( ! empty( $result['success'] ) ) {
			// Schedule async transit after successful natal chart gen
			self::schedule_transit_async( $coachee_id, $uid );
		}
		// [2026-06-09 Johnny Chu] R-ERROR-UX — detect svg-only failure in debug trace.
		// When natal succeeds but chart-svg endpoint returns non-200, the chart is saved
		// but chart_url stays empty. Surface an explicit svg_error payload so FE can
		// render a code+message+hint banner rather than the generic "chart_url rổng" notice.
		foreach ( $debug_trace as $_dt ) {
			if ( strpos( (string) ( $_dt['path'] ?? '' ), 'chart-svg' ) !== false
				 && empty( $_dt['success'] ) ) {
				$_http_code = (int) ( $_dt['http']['status'] ?? 0 );
				$result['svg_error'] = array(
					'code'    => 'gateway_degraded',
					'message' => 'Không lấy được ảnh bản đồ sao (chart-svg HTTP '
						. ( $_http_code ?: '?' ) . ').',
					'hint'    => 'Dữ liệu hành tinh đã lưu đầy đủ. Bấm “Lấy ảnh bản đồ” '
						. 'để thử lại. Nếu vẫn lỗi, provider ảnh đang tạm thời quá tải — thử lại sau vài phút.',
				);
				break;
			}
		}
		$result['_debug'] = $debug_trace;
		return rest_ensure_response( $result );
	}
	/* ------------------------------------------------------------------ *
	 * [2026-06-09 Johnny Chu] PHASE-D D-BE-REGEN-SVG — SVG-only retry.
	 * Mirror of admin regen_svg_only: reads stored birth data, calls
	 * chart-svg only (no natal re-calc), saves file, updates bccm_astro.
	 * ------------------------------------------------------------------ */
	public static function regen_svg( $request ) {
		$coachee_id = (int) $request->get_param( 'id' );
		$chart_type = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );
		if ( ! in_array( $chart_type, array( 'western', 'vedic', 'chinese' ), true ) ) {
			$chart_type = 'western';
		}

		$uid = self::resolve_owner_uid( $coachee_id );
		if ( ! $uid ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$coachee = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1", $coachee_id
		), ARRAY_A );
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — always pick latest chart row for regen_svg.
		$astro_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_astro} WHERE coachee_id=%d AND chart_type=%s ORDER BY id DESC LIMIT 1",
			$coachee_id, $chart_type
		), ARRAY_A );
		if ( ! $astro_row ) {
			return new WP_Error( 'not_found',
				'Chưa có dữ liệu chiêm tinh cho chart_type=' . $chart_type . '. Tạo biểu đồ trước.',
				array( 'status' => 404 )
			);
		}

		if ( ! function_exists( 'bccm_astro_save_svg_file' ) || ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return new WP_Error( 'module_not_loaded',
				'Module lưu SVG hoặc gateway client chưa sẵn sàng.',
				array( 'status' => 503 )
			);
		}

		// Build birth params (mirror admin regen_svg_only)
		$dob_p   = explode( '-', (string) ( $coachee['dob'] ?? '' ) );
		$time_p  = explode( ':', (string) ( $astro_row['birth_time'] ?? '12:00' ) );
		$off     = (float) ( $astro_row['timezone'] ?? 7 );

		$coords      = self::read_birth_coords( (string) ( $coachee['extra_fields_json'] ?? '' ) );
		$birth_lat   = isset( $coords['lat'] ) ? (float) $coords['lat'] : 21.0285;
		$birth_lng   = isset( $coords['lng'] ) ? (float) $coords['lng'] : 105.8542;
		$birth_tz    = isset( $coords['tz'] )  ? (string) $coords['tz']  : 'Asia/Ho_Chi_Minh';
		$birth_place = (string) ( $astro_row['birth_place'] ?? $coachee['birth_place'] ?? 'Hanoi' );
		if ( $birth_place === '' ) { $birth_place = 'Hanoi'; }

		$svg_birth = array(
			'year'       => (int) ( $dob_p[0]  ?? 1990 ),
			'month'      => (int) ( $dob_p[1]  ?? 1    ),
			'day'        => (int) ( $dob_p[2]  ?? 1    ),
			'hour'       => (int) ( $time_p[0] ?? 12   ),
			'minute'     => (int) ( $time_p[1] ?? 0    ),
			'lat'        => $birth_lat,
			'lng'        => $birth_lng,
			'tz_str'     => $birth_tz,
			'city'       => $birth_place,
			'coachee_id' => $coachee_id,
			'format'     => 'svg',
			'theme_type' => 'light',
			'size'       => 800,
		);

		$svg_inline = '';

		// --- Path A: direct local FAA2 provider (main site / bizcity.vn) ---
		if ( function_exists( 'bcpro_astro_v2_available' ) && bcpro_astro_v2_available( 'faa2_western' )
			 && class_exists( 'BizCity_Astro_Router' ) && function_exists( 'bcpro_astro_birth_to_v2_input' ) ) {
			$bd_v2    = array(
				'latitude' => $birth_lat, 'longitude' => $birth_lng, 'timezone' => $off,
				'year' => $svg_birth['year'], 'month' => $svg_birth['month'], 'day' => $svg_birth['day'],
				'hour' => $svg_birth['hour'], 'minute' => $svg_birth['minute'],
			);
			$input    = bcpro_astro_birth_to_v2_input( $bd_v2 );
			$provider = BizCity_Astro_Router::get_provider( 'faa2_western' );
			$direct   = $provider ? $provider->natal_wheel_chart( array_merge( $input, array( 'format' => 'svg', 'theme_type' => 'light', 'size' => 800 ) ) ) : array();
			if ( ! empty( $direct['success'] ) && ! empty( $direct['svg'] ) ) {
				$svg_inline = (string) $direct['svg'];
			}
			// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — FAA2 wheel commonly returns `url` only.
			if ( $svg_inline === '' && ! empty( $direct['success'] ) && ! empty( $direct['url'] ) ) {
				$fetch = wp_remote_get( (string) $direct['url'], array( 'timeout' => 20, 'sslverify' => false ) );
				if ( ! is_wp_error( $fetch ) && 200 === (int) wp_remote_retrieve_response_code( $fetch ) ) {
					$svg_inline = (string) wp_remote_retrieve_body( $fetch );
				}
			}
		}

		// --- Path B: gateway BizCoach_Pro_Astro_Client (subsite fallback) ---
		if ( $svg_inline === '' ) {
			$gw_res  = BizCoach_Pro_Astro_Client::chart_svg_western( $svg_birth, array( 'timeout' => 30 ) );
			$gw_env  = is_array( $gw_res['envelope'] ?? null ) ? $gw_res['envelope'] : array();

			if ( ! empty( $gw_res['success'] ) ) {
				$svg_inline = (string) ( $gw_env['svg'] ?? '' );
				// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — FAA2 wheel endpoint returns `url`.
				$img_url    = (string) ( $gw_env['image_url'] ?? ( $gw_env['url'] ?? '' ) );

				if ( $svg_inline === '' && $img_url !== '' ) {
					$fetch = wp_remote_get( $img_url, array( 'timeout' => 20, 'sslverify' => false ) );
					if ( ! is_wp_error( $fetch ) && 200 === (int) wp_remote_retrieve_response_code( $fetch ) ) {
						$svg_inline = (string) wp_remote_retrieve_body( $fetch );
					}
				}
				if ( $svg_inline === '' && ! empty( $gw_env['image_base64'] ) ) {
					$ct         = (string) ( $gw_env['content_type'] ?? 'image/png' );
					$svg_inline = 'data:' . $ct . ';base64,' . (string) $gw_env['image_base64'];
				}
			} else {
				$gw_code = (int) ( $gw_res['http']['status'] ?? 0 );
				$gw_err  = (string) ( $gw_res['error'] ?? ( 'http_' . ( $gw_code ?: '?' ) ) );
				return rest_ensure_response( array(
					'success'    => false,
					'_degraded'  => true,
					'code'       => 'gateway_degraded',
					'message'    => 'Không lấy được ảnh bản đồ sao (chart-svg ' . $gw_err . ').',
					'hint'       => 'Provider ảnh đang tạm thời quá tải — thử lại sau vài phút.',
				) );
			}
		}

		if ( $svg_inline === '' ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'gateway_degraded',
				'message'   => 'Không lấy được ảnh bản đồ sao từ cả hai nguồn.',
				'hint'      => 'Thử lại sau vài phút.',
			) );
		}

		// Save SVG file + update bccm_astro row
		$saved = bccm_astro_save_svg_file( $coachee_id, 'natal', $svg_inline );
		if ( is_wp_error( $saved ) ) {
			return new WP_Error( 'save_failed', 'Lưu SVG thất bại: ' . $saved->get_error_message(), array( 'status' => 500 ) );
		}

		$new_url = (string) $saved;
		$row_id  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t_astro} WHERE coachee_id=%d AND chart_type=%s ORDER BY id DESC LIMIT 1",
			$coachee_id, $chart_type
		) );
		if ( $row_id ) {
			$row_tmp = $wpdb->get_row( $wpdb->prepare(
				"SELECT summary, traits FROM {$t_astro} WHERE id=%d", $row_id
			), ARRAY_A );
			$upd = array( 'chart_svg' => $new_url, 'updated_at' => current_time( 'mysql' ) );
			if ( ! empty( $row_tmp['summary'] ) ) {
				$s = json_decode( $row_tmp['summary'], true );
				if ( is_array( $s ) ) { $s['chart_url'] = $new_url; $upd['summary'] = wp_json_encode( $s, JSON_UNESCAPED_UNICODE ); }
			}
			if ( ! empty( $row_tmp['traits'] ) ) {
				$tr = json_decode( $row_tmp['traits'], true );
				if ( is_array( $tr ) ) { $tr['chart_url'] = $new_url; $upd['traits'] = wp_json_encode( $tr, JSON_UNESCAPED_UNICODE ); }
			}
			$wpdb->update( $t_astro, $upd, array( 'id' => $row_id ) );
		}

		// Invalidate caches
		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			BizCoach_Pro_Cache::flush_user_caches( $uid );
		}

		return rest_ensure_response( array(
			'success'   => true,
			'chart_url' => $new_url,
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-07-09 Johnny Chu] PHASE-FAA2-FE — FAA2 Natal Wheel Chart (dark SVG S3 URL).
	 * Calls faa2_western::natal_wheel_chart() → S3 URL, saves to bccm_astro.chart_svg.
	 * Uses existing birth data from coachee row — does NOT re-run full natal calc.
	 * ------------------------------------------------------------------ */
	public static function generate_wheel_chart( WP_REST_Request $req ) {
		$coachee_id = (int) $req->get_param( 'id' );
		$chart_type = sanitize_key( (string) ( $req->get_param( 'chart_type' ) ?: 'western' ) );

		// Resolve actual owner uid (admin-aware), same pattern as regen_svg
		$uid = self::resolve_owner_uid( $coachee_id );
		if ( ! $uid ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$coachee = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1", $coachee_id
		), ARRAY_A );
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — force latest row so wheel URL saves into current chart entry.
		$astro_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_astro} WHERE coachee_id=%d AND chart_type=%s ORDER BY id DESC LIMIT 1",
			$coachee_id, $chart_type
		), ARRAY_A );
		if ( ! $astro_row ) {
			return new WP_Error( 'not_found',
				'Chưa có dữ liệu chiêm tinh. Tạo biểu đồ trước.',
				array( 'status' => 404 )
			);
		}

		if ( ! ( class_exists( 'BizCity_Astro_Router' ) && function_exists( 'bcpro_astro_v2_available' )
			  && bcpro_astro_v2_available( 'faa2_western' ) ) ) {
			return rest_ensure_response( array(
				'success'    => false,
				'_degraded'  => true,
				'code'       => 'gateway_degraded',
				'message'    => 'FAA2 Wheel Chart provider chưa sẵn sàng trên site này.',
				'hint'       => 'Tính năng này cần bizcity-llm-router. Dùng nút "Vẽ lại SVG" thay thế.',
			) );
		}

		$dob_p  = explode( '-', (string) ( $coachee['dob'] ?? '' ) );
		$time_p = explode( ':', (string) ( $astro_row['birth_time'] ?? '12:00' ) );
		$off    = (float) ( $astro_row['timezone'] ?? 7 );

		$coords     = self::read_birth_coords( (string) ( $coachee['extra_fields_json'] ?? '' ) );
		$birth_lat  = isset( $coords['lat'] ) ? (float) $coords['lat'] : 21.0285;
		$birth_lng  = isset( $coords['lng'] ) ? (float) $coords['lng'] : 105.8542;

		$bd_v2 = array(
			'latitude'  => $birth_lat,
			'longitude' => $birth_lng,
			'timezone'  => $off,
			'year'      => (int) ( $dob_p[0] ?? 1990 ),
			'month'     => (int) ( $dob_p[1] ?? 1 ),
			'day'       => (int) ( $dob_p[2] ?? 1 ),
			'hour'      => (int) ( $time_p[0] ?? 12 ),
			'minute'    => (int) ( $time_p[1] ?? 0 ),
		);

		$input    = function_exists( 'bcpro_astro_birth_to_v2_input' ) ? bcpro_astro_birth_to_v2_input( $bd_v2 ) : $bd_v2;
		$provider = BizCity_Astro_Router::get_provider( 'faa2_western' );
		// [2026-07-04 Johnny Chu] PHASE-FAA2-FE FIX — get_provider() can return null if provider
		// is not configured or API key is missing. Null pointer here causes PHP fatal 500.
		if ( ! $provider ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'gateway_degraded',
				'message'   => 'FAA2 provider chưa sẵn sàng (API key chưa cấu hình).',
				'hint'      => 'Kiểm tra cài đặt API key FAA2 trong TwinChat Settings.',
			) );
		}
		$result   = $provider->natal_wheel_chart( $input );

		if ( empty( $result['success'] ) || empty( $result['url'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'code'      => 'gateway_degraded',
				'message'   => 'FAA2 không trả về URL bản đồ sao.',
				'hint'      => 'Thử lại sau vài phút.',
			) );
		}

		$wheel_url = (string) $result['url'];

		// [2026-07-04 Johnny Chu] PHASE-FAA2-FE FIX — FAA2 S3 URLs have short TTL (<24h).
		// Download SVG content and save as a permanent local file so <img src="..."> never
		// expires. Same pattern used by the regen_svg endpoint for consistency.
		if ( $wheel_url !== '' && function_exists( 'bccm_astro_save_svg_file' ) ) {
			$_wc_fetch = wp_remote_get( $wheel_url, array( 'timeout' => 15, 'sslverify' => false ) );
			if ( ! is_wp_error( $_wc_fetch ) && 200 === (int) wp_remote_retrieve_response_code( $_wc_fetch ) ) {
				$_wc_saved = bccm_astro_save_svg_file( $coachee_id, 'natal', (string) wp_remote_retrieve_body( $_wc_fetch ) );
				if ( ! is_wp_error( $_wc_saved ) ) {
					$wheel_url = (string) $_wc_saved; // local permanent URL replaces raw S3 URL
				}
			}
		}

		// Persist URL into bccm_astro row
		$row_id = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t_astro} WHERE coachee_id=%d AND chart_type=%s ORDER BY id DESC LIMIT 1",
			$coachee_id, $chart_type
		) );
		if ( $row_id ) {
			$row_tmp = $wpdb->get_row( $wpdb->prepare(
				"SELECT summary, traits FROM {$t_astro} WHERE id=%d", $row_id
			), ARRAY_A );
			$upd = array( 'chart_svg' => $wheel_url, 'updated_at' => current_time( 'mysql' ) );
			if ( ! empty( $row_tmp['summary'] ) ) {
				$s = json_decode( $row_tmp['summary'], true );
				if ( is_array( $s ) ) { $s['chart_url'] = $wheel_url; $upd['summary'] = wp_json_encode( $s, JSON_UNESCAPED_UNICODE ); }
			}
			if ( ! empty( $row_tmp['traits'] ) ) {
				$tr = json_decode( $row_tmp['traits'], true );
				if ( is_array( $tr ) ) { $tr['chart_url'] = $wheel_url; $upd['traits'] = wp_json_encode( $tr, JSON_UNESCAPED_UNICODE ); }
			}
			$wpdb->update( $t_astro, $upd, array( 'id' => $row_id ) );
		}

		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			BizCoach_Pro_Cache::flush_user_caches( $uid );
		}

		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — update checklist
		if ( class_exists( 'BizCoach_Astro_Checklist' ) ) {
			if ( $wheel_url !== '' ) {
				BizCoach_Astro_Checklist::mark_done( $coachee_id, BizCoach_Astro_Checklist::KEY_WESTERN_WHEEL_CHART, 1 );
			} else {
				BizCoach_Astro_Checklist::mark_failed( $coachee_id, BizCoach_Astro_Checklist::KEY_WESTERN_WHEEL_CHART, 'Empty URL returned' );
			}
		}

		return rest_ensure_response( array(
			'success'   => true,
			'chart_url' => $wheel_url,
			'_source'   => 'faa2_natal_wheel_chart',
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — GET /me/profiles/{id}/astro-checklist
	 * Returns per-coachee data fetch status for all astrological endpoints.
	 * ------------------------------------------------------------------ */
	public static function get_astro_checklist( WP_REST_Request $req ) {
		$coachee_id = (int) $req->get_param( 'id' );
		$uid        = self::resolve_owner_uid( $coachee_id );
		if ( $uid === 0 ) {
			return new WP_Error( 'unauthorized', 'Không có quyền truy cập hồ sơ này.', array( 'status' => 403 ) );
		}

		if ( ! class_exists( 'BizCoach_Astro_Checklist' ) ) {
			return rest_ensure_response( array( 'success' => false, 'message' => 'Checklist module chưa load.', '_degraded' => true ) );
		}

		$checklist = BizCoach_Astro_Checklist::get_for_coachee( $coachee_id );
		$summary   = BizCoach_Astro_Checklist::get_summary( $coachee_id );

		return rest_ensure_response( array(
			'success'   => true,
			'checklist' => $checklist,
			'summary'   => $summary,
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — POST /me/profiles/{id}/fetch-all-astro
	 * Sequential full fetch: Western (planets + houses + aspects + wheel) +
	 * Vedic (planets + extended + navamsa) + transit flag for cron.
	 * Returns step-by-step results for FE console log + updates checklist table.
	 * ------------------------------------------------------------------ */
	public static function fetch_all_astro( WP_REST_Request $req ) {
		// [2026-07-04 Johnny Chu] PHASE-FAA2-FE FIX — fetch_all_astro makes 7 sequential
		// HTTP calls (Western 30s + SVG 30s + S3 15s + Wheel 30s + Vedic×3 8s = 129s worst case).
		// Without set_time_limit, the default PHP limit (30-60s) causes fatal → 500 HTML response.
		@set_time_limit( 300 );  // 5 minutes ceiling (capped by server ini if lower)
		ignore_user_abort( true );  // keep running even if browser disconnects

		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 — full sequential fetch all astro data
		$coachee_id = (int) $req->get_param( 'id' );
		$uid        = self::resolve_owner_uid( $coachee_id );
		if ( $uid === 0 ) {
			return new WP_Error( 'unauthorized', 'Không có quyền truy cập hồ sơ này.', array( 'status' => 403 ) );
		}

		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$coachee = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1", $coachee_id
		), ARRAY_A );
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		// Build birth input
		$dob_parts   = explode( '-', (string) ( $coachee['dob'] ?? '' ) );
		$dob_year    = isset( $dob_parts[0] ) ? (int) $dob_parts[0] : 1990;
		$dob_month   = isset( $dob_parts[1] ) ? (int) $dob_parts[1] : 1;
		$dob_day     = isset( $dob_parts[2] ) ? (int) $dob_parts[2] : 1;

		$astro_row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT birth_time, birth_place FROM {$t_astro}
			 WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		$birth_time  = (string) ( $astro_row['birth_time'] ?? '12:00' );
		if ( $birth_time === '' ) { $birth_time = '12:00'; }
		$bt_parts    = explode( ':', $birth_time );
		$birth_hour  = isset( $bt_parts[0] ) ? (int) $bt_parts[0] : 12;
		$birth_min   = isset( $bt_parts[1] ) ? (int) $bt_parts[1] : 0;

		$coords      = self::read_birth_coords( (string) ( $coachee['extra_fields_json'] ?? '' ) );
		$birth_lat   = isset( $coords['lat'] ) ? (float) $coords['lat'] : 21.0285;
		$birth_lng   = isset( $coords['lng'] ) ? (float) $coords['lng'] : 105.8542;
		$birth_tz    = isset( $coords['tz'] )  ? (string) $coords['tz'] : 'Asia/Ho_Chi_Minh';
		$birth_place = (string) ( $astro_row['birth_place'] ?? $coachee['birth_place'] ?? '' );

		$birth_input = array(
			'year'         => $dob_year,
			'month'        => $dob_month,
			'day'          => $dob_day,
			'hour'         => $birth_hour,
			'minute'       => $birth_min,
			'latitude'     => $birth_lat,
			'longitude'    => $birth_lng,
			'tz_str'       => $birth_tz,
			'timezone'     => 7.0,
			'birth_place'  => $birth_place,
		);

		$steps       = array();
		$has_cl      = class_exists( 'BizCoach_Astro_Checklist' );
		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 FIX — BizCoach_Pro_Astro_Client returns
		// envelope-nested format (envelope.planets), but downstream reads flat $result['planets'].
		// Use bccm_astro_fetch_full_chart_via_gateway_v2 as primary — it returns legacy flat shape.
		$has_gateway = class_exists( 'BizCoach_Pro_Astro_Client' );

		// ---- STEP 1: Western natal (planets + houses + aspects) via hub ----
		$t_w = microtime( true );
		$western_result = null;
		if ( function_exists( 'bccm_astro_fetch_full_chart_via_gateway_v2' ) ) {
			$western_result = bccm_astro_fetch_full_chart_via_gateway_v2( array(
				'year' => $dob_year, 'month' => $dob_month, 'day' => $dob_day,
				'hour' => $birth_hour, 'minute' => $birth_min,
				'latitude' => $birth_lat, 'longitude' => $birth_lng,
				'timezone_str' => $birth_tz, 'timezone' => 7.0,
				'birth_place' => $birth_place, 'name' => (string) ( $coachee['full_name'] ?? '' ),
			) );
		}
		$w_ms = (int) round( ( microtime( true ) - $t_w ) * 1000 );

		$w_planets = 0;
		$w_houses  = 0;
		$w_aspects = 0;
		if ( is_array( $western_result ) && ! empty( $western_result['success'] ) ) {
			$w_planets = count( (array) ( $western_result['planets']  ?? array() ) );
			$w_houses  = count( (array) ( $western_result['houses']   ?? array() ) );
			$w_aspects = count( (array) ( $western_result['aspects']  ?? array() ) );

			// Save Western data to bccm_astro
			if ( function_exists( 'bccm_astro_save_chart' ) ) {
				bccm_astro_save_chart( $coachee_id, $western_result, array(
					'birth_place' => $birth_place,
					'birth_time'  => $birth_time,
					'latitude'    => $birth_lat,
					'longitude'   => $birth_lng,
					'timezone'    => 7.0,
				), $uid );
			}
		}

		$steps[] = array(
			'key'    => 'western_planets',
			'label'  => 'Western — Planets',
			'status' => ( $w_planets >= 10 ) ? 'done' : ( $w_planets > 0 ? 'partial' : 'failed' ),
			'count'  => $w_planets,
			'ms'     => $w_ms,
			'detail' => $w_planets > 0 ? "{$w_planets} planets OK" : 'No planets returned',
			'_source' => is_array( $western_result ) ? ( $western_result['_source'] ?? $western_result['raw_provider'] ?? '' ) : 'error',
		);
		$steps[] = array(
			'key'    => 'western_houses',
			'label'  => 'Western — Houses (12 nhà)',
			'status' => ( $w_houses === 12 ) ? 'done' : ( $w_houses > 0 ? 'partial' : 'failed' ),
			'count'  => $w_houses,
			'ms'     => $w_ms,
			'detail' => $w_houses > 0 ? "{$w_houses}/12 houses" : 'No houses returned',
		);
		$steps[] = array(
			'key'    => 'western_aspects',
			'label'  => 'Western — Aspects',
			'status' => ( $w_aspects >= 5 ) ? 'done' : ( $w_aspects > 0 ? 'partial' : 'failed' ),
			'count'  => $w_aspects,
			'ms'     => $w_ms,
			'detail' => $w_aspects > 0 ? "{$w_aspects} aspects" : 'No aspects returned',
		);

		if ( $has_cl ) {
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_WESTERN_PLANETS, $w_planets >= 10 ? 'done' : ( $w_planets > 0 ? 'partial' : 'failed' ), $w_planets );
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_WESTERN_HOUSES,  $w_houses  === 12 ? 'done' : ( $w_houses  > 0 ? 'partial' : 'failed' ), $w_houses );
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_WESTERN_ASPECTS, $w_aspects >= 5  ? 'done' : ( $w_aspects > 0 ? 'partial' : 'failed' ), $w_aspects );
		}

		// ---- STEP 2: Western Wheel Chart ----
		// [2026-07-05 Johnny Chu] PHASE-FAA2-NEXT FIX-4 — replaced BizCity_Astro_Router
		// (not activated on client blog) with BizCoach_Pro_Astro_Client::chart_svg_western().
		// Flow: client → hub (bizcity.vn /bizcity/v1/astrology/western/chart-svg) →
		//       faa2_western::natal_wheel_chart() → FAA2 S3 URL →
		//       download SVG → bccm_astro_save_svg_file() → local WP URL.
		$t_wh    = microtime( true );
		$wh_url  = '';
		$wh_err  = '';
		if ( class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			try {
				$wh_resp = BizCoach_Pro_Astro_Client::chart_svg_western( $birth_input, array( 'timeout' => 30 ) );
				$wh_env  = (array) ( $wh_resp['envelope'] ?? array() );
				if ( ! empty( $wh_resp['success'] ) && ! empty( $wh_env['success'] ) ) {
					$s3_url = (string) ( $wh_env['url'] ?? '' );
					if ( $s3_url !== '' ) {
						// Download SVG from FAA2 S3 URL and save to local WP uploads.
						$svg_resp = wp_remote_get( $s3_url, array( 'timeout' => 20, 'sslverify' => true ) );
						if ( ! is_wp_error( $svg_resp ) && 200 === (int) wp_remote_retrieve_response_code( $svg_resp ) ) {
							$svg_body = wp_remote_retrieve_body( $svg_resp );
							if ( function_exists( 'bccm_astro_save_svg_file' ) ) {
								$saved = bccm_astro_save_svg_file( $coachee_id, 'natal', $svg_body );
								if ( ! is_wp_error( $saved ) ) {
									$wh_url = $saved; // Local URL: .../bizcoach-astro-charts/{id}_natal.svg
								} else {
									$wh_err = 'SVG save failed: ' . $saved->get_error_message();
									$wh_url = $s3_url; // Fallback: store S3 URL
								}
							} else {
								$wh_url = $s3_url; // bccm_astro_save_svg_file not loaded; store S3 URL
							}
						} else {
							$dl_code = is_wp_error( $svg_resp ) ? $svg_resp->get_error_message() : (int) wp_remote_retrieve_response_code( $svg_resp );
							$wh_err  = 'SVG download failed (' . $dl_code . ')';
							$wh_url  = $s3_url; // Store S3 URL even if download fails
						}
					} else {
						$wh_err = 'Hub returned success but empty URL';
					}
				} else {
					$wh_err = (string) ( $wh_env['message'] ?? $wh_resp['error'] ?? 'Wheel chart failed at hub' );
				}
			} catch ( Exception $e ) {
				$wh_err = $e->getMessage();
			}
		} else {
			$wh_err = 'BizCoach_Pro_Astro_Client not loaded';
		}
		$wh_ms = (int) round( ( microtime( true ) - $t_wh ) * 1000 );

		// Save wheel chart URL to bccm_astro
		if ( $wh_url !== '' ) {
			$row_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$t_astro} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
				$coachee_id
			) );
			if ( $row_id ) {
				$row_tmp = $wpdb->get_row( $wpdb->prepare( "SELECT summary, traits FROM {$t_astro} WHERE id=%d", $row_id ), ARRAY_A );
				$upd     = array( 'chart_svg' => $wh_url, 'updated_at' => current_time( 'mysql' ) );
				if ( ! empty( $row_tmp['summary'] ) ) {
					$s = json_decode( $row_tmp['summary'], true );
					if ( is_array( $s ) ) { $s['chart_url'] = $wh_url; $upd['summary'] = wp_json_encode( $s, JSON_UNESCAPED_UNICODE ); }
				}
				if ( ! empty( $row_tmp['traits'] ) ) {
					$tr = json_decode( $row_tmp['traits'], true );
					if ( is_array( $tr ) ) { $tr['chart_url'] = $wh_url; $upd['traits'] = wp_json_encode( $tr, JSON_UNESCAPED_UNICODE ); }
				}
				$wpdb->update( $t_astro, $upd, array( 'id' => $row_id ) );
			}
		}

		$steps[] = array(
			'key'    => 'western_wheel_chart',
			'label'  => 'Western — Wheel Chart (SVG URL)',
			'status' => $wh_url !== '' ? 'done' : 'failed',
			'count'  => $wh_url !== '' ? 1 : 0,
			'ms'     => $wh_ms,
			'detail' => $wh_url !== '' ? 'URL: ' . substr( $wh_url, 0, 60 ) . '…' : ( $wh_err ?: 'No URL returned' ),
		);
		if ( $has_cl ) {
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_WESTERN_WHEEL_CHART, $wh_url !== '' ? 'done' : 'failed', $wh_url !== '' ? 1 : 0, $wh_err );
		}

		// ---- STEP 3: Vedic full (planets + extended + navamsa via faa2_vedic) ----
		$t_v      = microtime( true );
		$v_result = null;
		$vedic_birth_payload = array(
			'year'    => $dob_year,
			'month'   => $dob_month,
			'day'     => $dob_day,
			'hour'    => $birth_hour,
			'minute'  => $birth_min,
			'lat'     => $birth_lat,
			'lng'     => $birth_lng,
			'tz_str'  => $birth_tz,
			'name'    => (string) ( $coachee['full_name'] ?? '' ),
			'city'    => $birth_place ?: 'Hanoi',
		);
		// [2026-07-05 Johnny Chu] HOTFIX — use BizCoach_Pro_Astro_Client::natal_vedic_faa2_full()
		// instead of calling BizCity_Astro_Router directly. The client has HTTP fallback to
		// bizcity.vn when local FAA2 key not configured — same pattern as western natal.
		if ( class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			try {
				$_v_resp = BizCoach_Pro_Astro_Client::natal_vedic_faa2_full( $vedic_birth_payload, array( 'timeout' => 30 ) );
				if ( ! empty( $_v_resp['success'] ) ) {
					// Flatten envelope so extraction code below works unchanged.
					$_v_env   = (array) ( $_v_resp['envelope'] ?? array() );
					// [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — normalize FAA2 list rows to
					// keyed planet maps so legacy Vedic renderer can resolve Sun/Moon/... rows.
					$_v_extended = (array) ( $_v_env['extended'] ?? array() );
					$_v_planets  = self::normalize_vedic_planet_map( (array) ( $_v_env['planets'] ?? array() ), $_v_extended );
					$_v_navamsa  = self::normalize_vedic_planet_map( (array) ( $_v_env['navamsa'] ?? array() ) );
					// [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — pull Vimshottari payload so
					// public Vedic page has Maha/Antar/date evidence.
					$_v_dasha    = self::fetch_vedic_dasha_payload( $vedic_birth_payload );
					$_v_chart    = self::resolve_saved_svg_url( $coachee_id, 'vedic' );
					$v_result = array(
						'success'  => ! empty( $_v_env['success'] ) || ! empty( $_v_resp['success'] ),
						'planets'  => $_v_planets,
						'extended' => $_v_extended,
						'navamsa'  => $_v_navamsa,
						'dasha'    => $_v_dasha,
						'chart_url'=> $_v_chart,
						'_steps'   => (array) ( $_v_env['_steps']   ?? array() ),
						'message'  => (string) ( $_v_env['message']  ?? $_v_resp['error'] ?? '' ),
					);
				} else {
					$v_result = array( 'success' => false, 'message' => (string) ( $_v_resp['error'] ?? 'Gateway error' ) );
				}
			} catch ( Exception $e ) {
				$v_result = array( 'success' => false, 'message' => $e->getMessage() );
			}
		} elseif ( class_exists( 'BizCity_Astro_Router' ) ) {
			// Fallback: direct router call (only works if FAA2 key is configured locally).
			try {
				BizCity_Astro_Router::boot();
				$_faa2v = BizCity_Astro_Router::get_provider( 'faa2_vedic' );
				if ( $_faa2v && $_faa2v->is_ready() ) {
					$v_result = $_faa2v->vedic_full( $birth_input );
				}
			} catch ( Exception $e ) {
				$v_result = array( 'success' => false, 'message' => $e->getMessage() );
			}
		}
		$v_ms = (int) round( ( microtime( true ) - $t_v ) * 1000 );

		$v_planets  = 0;
		$v_extended = 0;
		$v_navamsa  = 0;
		if ( is_array( $v_result ) && ! empty( $v_result['success'] ) ) {
			// [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — harden fallback/provider shapes.
			$v_result['planets'] = self::normalize_vedic_planet_map(
				(array) ( $v_result['planets'] ?? array() ),
				(array) ( $v_result['extended'] ?? array() )
			);
			$v_result['navamsa'] = self::normalize_vedic_planet_map( (array) ( $v_result['navamsa'] ?? array() ) );
			if ( empty( $v_result['dasha'] ) ) {
				$v_result['dasha'] = self::fetch_vedic_dasha_payload( $vedic_birth_payload );
			}
			if ( empty( $v_result['chart_url'] ) ) {
				$v_result['chart_url'] = self::resolve_saved_svg_url( $coachee_id, 'vedic' );
			}

			$v_planets  = count( (array) ( $v_result['planets']  ?? array() ) );
			$v_extended = count( (array) ( $v_result['extended'] ?? array() ) );
			$v_navamsa  = count( (array) ( $v_result['navamsa']  ?? array() ) );

			$_v_positions = (array) ( $v_result['planets'] ?? array() );
			$_v_summary = array(
				'sun_sign'       => (string) ( $_v_positions['Sun']['sign_en'] ?? '' ),
				'moon_sign'      => (string) ( $_v_positions['Moon']['sign_en'] ?? '' ),
				'ascendant_sign' => (string) ( $_v_positions['Ascendant']['sign_en'] ?? '' ),
				'chart_url'      => (string) ( $v_result['chart_url'] ?? '' ),
				'fetched_at'     => current_time( 'mysql' ),
				'system'         => 'Vedic (Lahiri Ayanamsha)',
				'_source'        => 'faa2_vedic',
			);
			$_v_chart_data = array(
				'planets'    => $_v_positions,
				'navamsa'    => (array) ( $v_result['navamsa'] ?? array() ),
				'dasha'      => (array) ( $v_result['dasha'] ?? array() ),
				'chart_url'  => (string) ( $v_result['chart_url'] ?? '' ),
				'fetched_at' => (string) ( $_v_summary['fetched_at'] ?? current_time( 'mysql' ) ),
				'_source'    => 'faa2_vedic',
				'parsed'     => array(
					'sun_sign'       => (string) ( $_v_summary['sun_sign'] ?? '' ),
					'moon_sign'      => (string) ( $_v_summary['moon_sign'] ?? '' ),
					'ascendant_sign' => (string) ( $_v_summary['ascendant_sign'] ?? '' ),
					'positions'      => $_v_positions,
				),
				'birth_data' => array(
					'year'    => $dob_year,
					'month'   => $dob_month,
					'day'     => $dob_day,
					'hour'    => $birth_hour,
					'minute'  => $birth_min,
					'tz_str'  => $birth_tz,
					'lat'     => $birth_lat,
					'lng'     => $birth_lng,
				),
			);

			// [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — prefer canonical saver for
			// full traits/summary compatibility with legacy renderer paths.
			if ( function_exists( 'bccm_vedic_save_chart' ) ) {
				bccm_vedic_save_chart( $coachee_id, $_v_chart_data, $birth_input, $uid );
			} else {
				$now         = current_time( 'mysql' );
				$vedic_traits = wp_json_encode( array(
					'planets'   => $_v_positions,
					'positions' => $_v_positions,
					'extended'  => (array) ( $v_result['extended'] ?? array() ),
					'navamsa'   => (array) ( $v_result['navamsa'] ?? array() ),
					'dasha'     => (array) ( $v_result['dasha'] ?? array() ),
					'chart_url' => (string) ( $v_result['chart_url'] ?? '' ),
					'_source'   => 'faa2_vedic',
				), JSON_UNESCAPED_UNICODE );
				$vedic_summary = wp_json_encode( $_v_summary, JSON_UNESCAPED_UNICODE );

				$v_existing_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$t_astro} WHERE coachee_id = %d AND chart_type = 'vedic' ORDER BY id DESC LIMIT 1",
					$coachee_id
				) );
				if ( $v_existing_id ) {
					$wpdb->update(
						$t_astro,
						array(
							'summary'    => $vedic_summary,
							'traits'     => $vedic_traits,
							'chart_svg'  => (string) ( $v_result['chart_url'] ?? '' ),
							'updated_at' => $now,
						),
						array( 'id' => $v_existing_id )
					);
				} else {
					$wpdb->insert( $t_astro, array(
						'coachee_id'  => $coachee_id,
						'chart_type'  => 'vedic',
						'summary'     => $vedic_summary,
						'traits'      => $vedic_traits,
						'chart_svg'   => (string) ( $v_result['chart_url'] ?? '' ),
						'birth_time'  => $birth_time,
						'birth_place' => $birth_place,
						'created_at'  => $now,
						'updated_at'  => $now,
					) );
				}
			}
		}

		$v_step_data = is_array( $v_result ) ? ( $v_result['_steps'] ?? array() ) : array();
		$v_err_msg   = is_array( $v_result ) ? ( $v_result['message'] ?? '' ) : 'Provider not ready';

		$steps[] = array(
			'key'    => 'vedic_planets',
			'label'  => 'Vedic — Planets (Rasi)',
			'status' => $v_planets >= 9 ? 'done' : ( $v_planets > 0 ? 'partial' : 'failed' ),
			'count'  => $v_planets,
			'ms'     => self::_extract_step_ms( $v_step_data, 'vedic_planets', $v_ms ),
			'detail' => $v_planets > 0 ? "{$v_planets} planets" : ( $v_err_msg ?: 'Failed' ),
		);
		$steps[] = array(
			'key'    => 'vedic_extended',
			'label'  => 'Vedic — Extended (Nakshatra + Pada)',
			'status' => $v_extended >= 9 ? 'done' : ( $v_extended > 0 ? 'partial' : 'failed' ),
			'count'  => $v_extended,
			'ms'     => self::_extract_step_ms( $v_step_data, 'vedic_extended', $v_ms ),
			'detail' => $v_extended > 0 ? "{$v_extended} planets w/nakshatra" : ( $v_err_msg ?: 'Failed' ),
		);
		$steps[] = array(
			'key'    => 'vedic_navamsa',
			'label'  => 'Vedic — Navamsa D9 Chart',
			'status' => $v_navamsa >= 9 ? 'done' : ( $v_navamsa > 0 ? 'partial' : 'failed' ),
			'count'  => $v_navamsa,
			'ms'     => self::_extract_step_ms( $v_step_data, 'vedic_navamsa', $v_ms ),
			'detail' => $v_navamsa > 0 ? "{$v_navamsa} D9 positions" : ( $v_err_msg ?: 'Failed' ),
		);

		if ( $has_cl ) {
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_VEDIC_PLANETS,  $v_planets  >= 9 ? 'done' : ( $v_planets  > 0 ? 'partial' : 'failed' ), $v_planets );
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_VEDIC_EXTENDED, $v_extended >= 9 ? 'done' : ( $v_extended > 0 ? 'partial' : 'failed' ), $v_extended );
			BizCoach_Astro_Checklist::upsert( $coachee_id, BizCoach_Astro_Checklist::KEY_VEDIC_NAVAMSA,  $v_navamsa  >= 9 ? 'done' : ( $v_navamsa  > 0 ? 'partial' : 'failed' ), $v_navamsa );
		}

		// ---- STEP 4: Transit — schedule cron (flag as pending for cron to pick up) ----
		self::schedule_transit_async( $coachee_id, $uid );
		if ( $has_cl ) {
			// Only mark pending if not already done (avoid overwriting existing transit data)
			$summary = BizCoach_Astro_Checklist::get_summary( $coachee_id );
			$cl_rows = BizCoach_Astro_Checklist::get_for_coachee( $coachee_id );
			$transit_row = null;
			foreach ( $cl_rows as $r ) {
				if ( $r['key'] === BizCoach_Astro_Checklist::KEY_TRANSIT ) { $transit_row = $r; break; }
			}
			if ( ! $transit_row || $transit_row['status'] !== BizCoach_Astro_Checklist::STATUS_DONE ) {
				BizCoach_Astro_Checklist::mark_pending( $coachee_id, BizCoach_Astro_Checklist::KEY_TRANSIT );
			}
		}
		$steps[] = array(
			'key'    => 'transit',
			'label'  => 'Transit (quá cảnh hôm nay)',
			'status' => 'pending',
			'count'  => 0,
			'ms'     => 0,
			'detail' => 'Đã đăng ký cron job (sẽ tính trong 5 giây)',
		);

		// Flush caches
		if ( class_exists( 'BizCoach_Pro_Cache' ) ) {
			BizCoach_Pro_Cache::flush_user_caches( $uid );
		}

		// Final checklist
		$checklist = $has_cl ? BizCoach_Astro_Checklist::get_for_coachee( $coachee_id ) : array();
		$summary   = $has_cl ? BizCoach_Astro_Checklist::get_summary( $coachee_id )     : array();

		return rest_ensure_response( array(
			'success'   => true,
			'steps'     => $steps,
			'checklist' => $checklist,
			'summary'   => $summary,
		) );
	}

	/** Extract step ms from _steps array by key, fallback to total_ms */
	private static function _extract_step_ms( array $steps, string $key, int $fallback ): int {
		foreach ( $steps as $s ) {
			if ( isset( $s['key'] ) && $s['key'] === $key ) {
				return (int) ( $s['ms'] ?? $fallback );
			}
		}
		return $fallback;
	}

	/**
	 * [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — normalize Vedic rows (list/map)
	 * to keyed map by planet name for legacy renderer compatibility.
	 */
	private static function normalize_vedic_planet_map( array $rows, array $extended = array() ): array {
		$mapped = array();
		foreach ( $rows as $row_key => $row_val ) {
			if ( ! is_array( $row_val ) ) {
				continue;
			}
			$name = (string) ( $row_val['name'] ?? $row_val['planet'] ?? ( is_string( $row_key ) ? $row_key : '' ) );
			if ( $name === '' ) {
				continue;
			}
			$extra = array();
			if ( isset( $extended[ $name ] ) && is_array( $extended[ $name ] ) ) {
				$extra = $extended[ $name ];
			}

			$sign_num = (int) ( $row_val['sign_number'] ?? $row_val['sign_num'] ?? $row_val['current_sign'] ?? $extra['current_sign'] ?? 0 );
			$sign_en  = (string) ( $row_val['sign_en'] ?? $row_val['sign'] ?? $extra['sign_en'] ?? self::vedic_sign_name_from_number( $sign_num ) );
			$norm_deg = (float) ( $row_val['norm_degree'] ?? $row_val['sign_degree'] ?? $row_val['normDegree'] ?? $extra['norm_degree'] ?? 0.0 );
			$full_deg = (float) ( $row_val['full_degree'] ?? $row_val['absolute_degree'] ?? $row_val['fullDegree'] ?? $extra['full_degree'] ?? 0.0 );
			$house_no = (int) ( $row_val['house'] ?? $row_val['house_number'] ?? $extra['house_number'] ?? 0 );
			$is_retro = self::normalize_vedic_bool( $row_val['is_retro'] ?? $row_val['retrograde'] ?? $row_val['isRetro'] ?? $extra['retrograde'] ?? false );

			$mapped[ $name ] = array(
				'planet_en'      => $name,
				'sign_en'        => $sign_en,
				'sign'           => $sign_en,
				'sign_number'    => $sign_num,
				'norm_degree'    => $norm_deg,
				'sign_degree'    => $norm_deg,
				'full_degree'    => $full_deg,
				'absolute_degree'=> $full_deg,
				'house'          => $house_no,
				'house_number'   => $house_no,
				'is_retro'       => $is_retro,
				'retrograde'     => $is_retro,
				'nakshatra'      => (string) ( $row_val['nakshatra'] ?? $row_val['nakshatra_name'] ?? $extra['nakshatra_name'] ?? '' ),
				'nakshatra_pada' => (int) ( $row_val['nakshatra_pada'] ?? $extra['nakshatra_pada'] ?? 0 ),
				'sign_lord'      => (string) ( $row_val['sign_lord'] ?? $row_val['zodiac_sign_lord'] ?? $extra['sign_lord'] ?? $extra['zodiac_sign_lord'] ?? '' ),
				'dignity'        => (string) ( $row_val['dignity'] ?? $extra['dignity'] ?? '' ),
			);
		}
		return $mapped;
	}

	/** [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — bool parser for mixed API fields. */
	private static function normalize_vedic_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$norm = strtolower( trim( $value ) );
			return $norm === '1' || $norm === 'true' || $norm === 'yes';
		}
		return ! empty( $value );
	}

	/** [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — sign number → EN name. */
	private static function vedic_sign_name_from_number( int $sign_num ): string {
		$signs = array(
			1 => 'Aries', 2 => 'Taurus', 3 => 'Gemini', 4 => 'Cancer',
			5 => 'Leo', 6 => 'Virgo', 7 => 'Libra', 8 => 'Scorpio',
			9 => 'Sagittarius', 10 => 'Capricorn', 11 => 'Aquarius', 12 => 'Pisces',
		);
		return (string) ( $signs[ $sign_num ] ?? '' );
	}

	/**
	 * [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — optional dasha fetch for
	 * Vimshottari coverage (Maha/Antar/date info) in Vedic public reports.
	 */
	private static function fetch_vedic_dasha_payload( array $birth_payload ): array {
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return array();
		}
		try {
			$resp = BizCoach_Pro_Astro_Client::dasha_vedic( $birth_payload, array( 'timeout' => 30 ) );
			if ( empty( $resp['success'] ) ) {
				return array();
			}
			$env = (array) ( $resp['envelope'] ?? array() );
			if ( isset( $env['dasha'] ) && is_array( $env['dasha'] ) ) {
				return (array) $env['dasha'];
			}
			if ( isset( $env['vimshottari'] ) && is_array( $env['vimshottari'] ) ) {
				return array( 'vimshottari' => (array) $env['vimshottari'] );
			}
			return $env;
		} catch ( Exception $e ) {
			return array();
		}
	}

	/**
	 * [2026-07-11 Johnny Chu] PHASE-VEDIC-FAA2 — resolve saved SVG URL by chart type.
	 */
	private static function resolve_saved_svg_url( int $coachee_id, string $chart_type ): string {
		if ( $coachee_id <= 0 || ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		$base_dir = (string) ( $uploads['basedir'] ?? '' );
		$base_url = (string) ( $uploads['baseurl'] ?? '' );
		if ( $base_dir === '' || $base_url === '' ) {
			return '';
		}
		$chart_type = sanitize_key( $chart_type );
		$file_name  = $coachee_id . '_' . $chart_type . '.svg';
		$file_path  = trailingslashit( $base_dir ) . 'bizcoach-astro-charts/' . $file_name;
		if ( ! file_exists( $file_path ) ) {
			return '';
		}
		return trailingslashit( $base_url ) . 'bizcoach-astro-charts/' . $file_name;
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-08 Johnny Chu] HOTFIX — shared natal chart generation helper.
	 * Called from generate_chart REST, create_profile, and update_profile.
	 * $uid must be the coachee OWNER's user_id (use resolve_owner_uid first).
	 * Returns array with 'success' key; on WP_Error returns array with '_is_wp_error'.
	 * ------------------------------------------------------------------ */
	private static function do_chart_generate_for_coachee( $coachee_id, $uid, $chart_type = 'western' ) {
		if ( ! function_exists( 'bccm_astro_fetch_full_chart' ) ) {
			return array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Module tạo biểu đồ chưa sẵn sàng.',
			);
		}

		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$coachee = $wpdb->get_row( $wpdb->prepare(
			// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — R-COACHEE §6: id only; user_id=0
			// profiles created by admin fail with AND user_id=%d guard.
			"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1",
			$coachee_id
		), ARRAY_A );

		if ( ! $coachee ) {
			return array( 'success' => false, 'message' => 'Hồ sơ không tồn tại.' );
		}

		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — read latest row to avoid old birth_time/birth_place drift.
		$astro_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT birth_time, birth_place FROM {$t_astro}
			 WHERE coachee_id = %d AND chart_type = %s ORDER BY id DESC LIMIT 1",
			$coachee_id, $chart_type
		), ARRAY_A );

		$dob_parts = explode( '-', (string) ( $coachee['dob'] ?? '' ) );
		$dob_year  = isset( $dob_parts[0] ) ? (int) $dob_parts[0] : 1990;
		$dob_month = isset( $dob_parts[1] ) ? (int) $dob_parts[1] : 1;
		$dob_day   = isset( $dob_parts[2] ) ? (int) $dob_parts[2] : 1;

		$birth_time_str = (string) ( $astro_row['birth_time'] ?? '12:00' );
		if ( $birth_time_str === '' ) { $birth_time_str = '12:00'; }
		$bt_parts   = explode( ':', $birth_time_str );
		$birth_hour = isset( $bt_parts[0] ) ? (int) $bt_parts[0] : 12;
		$birth_min  = isset( $bt_parts[1] ) ? (int) $bt_parts[1] : 0;

		$coords      = self::read_birth_coords( (string) ( $coachee['extra_fields_json'] ?? '' ) );
		$birth_lat   = isset( $coords['lat'] ) ? (float) $coords['lat'] : 21.0285;
		$birth_lng   = isset( $coords['lng'] ) ? (float) $coords['lng'] : 105.8542;
		$birth_tz    = isset( $coords['tz'] )  ? (string) $coords['tz'] : 'Asia/Ho_Chi_Minh';
		$birth_place = (string) ( $astro_row['birth_place'] ?? $coachee['birth_place'] ?? '' );

		$birth_data = array(
			'year'         => $dob_year,
			'month'        => $dob_month,
			'day'          => $dob_day,
			'hour'         => $birth_hour,
			'minute'       => $birth_min,
			'latitude'     => $birth_lat,
			'longitude'    => $birth_lng,
			'timezone_str' => $birth_tz,
			'name'         => (string) ( $coachee['full_name'] ?? '' ),
			'timezone'     => 7.0,
			// [2026-06-08 Johnny Chu] HOTFIX — pass birth_place so chart_svg call can use it as 'city'
			'birth_place'  => $birth_place,
		);

		$birth_input = array(
			'birth_place' => $birth_place,
			'birth_time'  => $birth_time_str,
			'latitude'    => $birth_lat,
			'longitude'   => $birth_lng,
			'timezone'    => 7.0,
		);

		$result = bccm_astro_fetch_full_chart( $birth_data );

		if ( is_wp_error( $result ) ) {
			return array(
				'success'      => false,
				'_degraded'    => true,
				'_is_wp_error' => true,
				'message'      => $result->get_error_message(),
			);
		}

		if ( function_exists( 'bccm_astro_save_chart' ) ) {
			bccm_astro_save_chart( $coachee_id, $result, $birth_input, $uid );
		}

		$share_url = '';
		if ( function_exists( 'bcpro_get_astro_public_url' ) ) {
			$share_url = (string) bcpro_get_astro_public_url( $coachee_id, $chart_type, true );
		}

		// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 FIX — bccm_astro_fetch_full_chart_via_gateway_v2()
		// returns 'parsed' but NOT 'summary'. Build summary from parsed + chart_url to avoid empty string.
		$_parsed_s = is_array( $result['parsed'] ?? null ) ? (array) $result['parsed'] : array();
		$_sum_arr  = array(
			'sun_sign'       => $_parsed_s['sun_sign']       ?? '',
			'moon_sign'      => $_parsed_s['moon_sign']      ?? '',
			'ascendant_sign' => $_parsed_s['ascendant_sign'] ?? '',
			'chart_url'      => is_array( $result ) ? (string) ( $result['chart_url'] ?? '' ) : '',
			'_source'        => is_array( $result ) ? (string) ( $result['_source']   ?? '' ) : '',
		);
		$_chart_summary = wp_json_encode( $_sum_arr, JSON_UNESCAPED_UNICODE );

		return array(
			'success'       => true,
			'chart_summary' => $_chart_summary,
			'share_url'     => $share_url,
			'_source'       => is_array( $result ) ? ( (string) ( $result['_source'] ?? '' ) ) : '',
		);
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-08 Johnny Chu] HOTFIX — resolve coachee owner uid.
	 * Admin can manage any profile; regular user must own it.
	 * Returns owner's user_id, or 0 if not found / not authorized.
	 * ------------------------------------------------------------------ */
	private static function resolve_owner_uid( $coachee_id ) {
		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$uid     = (int) get_current_user_id();
		if ( current_user_can( 'manage_options' ) ) {
			$owner_uid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT user_id FROM {$t_coach} WHERE id = %d LIMIT 1",
				$coachee_id
			) );
			// [2026-07-03 Johnny Chu] PHASE-FAA2-FE — admin-created no-account coachees
			// have user_id=0 in DB; return current admin uid so write ops (update/delete)
			// work correctly. Admin owns any coachee they manage.
			return $owner_uid > 0 ? $owner_uid : $uid;
		}
		$exists = (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$t_coach} WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id, $uid
		) );
		return $exists ? $uid : 0;
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-08 Johnny Chu] HOTFIX — schedule async transit rebuild
	 * via WP-Cron (non-blocking, fires 5s after natal chart generation).
	 * ------------------------------------------------------------------ */
	private static function schedule_transit_async( $coachee_id, $uid ) {
		$args = array( (int) $coachee_id, (int) $uid );
		if ( ! wp_next_scheduled( 'bcpro_async_rebuild_transit', $args ) ) {
			wp_schedule_single_event( time() + 5, 'bcpro_async_rebuild_transit', $args );
		}
		// Kick cron runner non-blocking so it fires on this request cycle
		wp_remote_get( site_url( '/?doing_wp_cron' ), array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-08 Johnny Chu] HOTFIX — async transit cron handler.
	 * Fetches today's transit; stores failure in user meta as notice.
	 * ------------------------------------------------------------------ */
	public static function handle_async_transit( $coachee_id, $uid ) {
		global $wpdb;
		$coachee_id = (int) $coachee_id;
		$uid        = (int) $uid;

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			update_user_meta( $uid, 'bcpro_transit_notice', array(
				'coachee_id' => $coachee_id,
				'date'       => current_time( 'Y-m-d' ),
				'error'      => 'Astro client chưa load.',
			) );
			return;
		}

		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$coachee = $wpdb->get_row( $wpdb->prepare(
			// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — R-COACHEE §6: id only for user_id=0 compat
			"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! $coachee ) { return; }

		$natal = $wpdb->get_row( $wpdb->prepare(
			"SELECT birth_time, birth_place FROM {$t_astro}
			 WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! $natal ) { return; }

		// [2026-07-10 Johnny Chu] PHASE-FAA2 — tag async transit save source for JSONL history.
		$res = self::do_transit_fetch(
			$coachee,
			is_array( $natal ) ? $natal : array(),
			current_time( 'Y-m-d' ),
			'day',
			'async_chart_generate'
		);

		if ( empty( $res['success'] ) ) {
			// Store failure notice in user meta — FE can read via /me/transit-notice
			update_user_meta( $uid, 'bcpro_transit_notice', array(
				'coachee_id' => $coachee_id,
				'coachee'    => (string) ( $coachee['full_name'] ?? '' ),
				'date'       => current_time( 'Y-m-d' ),
				'error'      => isset( $res['message'] ) ? (string) $res['message'] : 'Gateway lỗi.',
			) );
			// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 FIX — update checklist KEY_TRANSIT on fail
			if ( class_exists( 'BizCoach_Astro_Checklist' ) ) {
				BizCoach_Astro_Checklist::mark_failed( $coachee_id, BizCoach_Astro_Checklist::KEY_TRANSIT, isset( $res['message'] ) ? (string) $res['message'] : 'Transit gateway lỗi.' );
			}
		} else {
			// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 FIX — update checklist KEY_TRANSIT on success
			if ( class_exists( 'BizCoach_Astro_Checklist' ) ) {
				$t_count = count( (array) ( $res['transits'] ?? $res['data'] ?? array() ) );
				BizCoach_Astro_Checklist::mark_done( $coachee_id, BizCoach_Astro_Checklist::KEY_TRANSIT, $t_count );
			}
		}
	}

	/* ------------------------------------------------------------------ *
	 * GET /me/profiles/{id}/share-link
	 * ------------------------------------------------------------------ */
	public static function get_share_link( $request ) {
		$coachee_id = (int) $request->get_param( 'id' );
		$chart_type = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );

		if ( ! function_exists( 'bcpro_get_astro_public_url' ) ) {
			return new WP_Error( 'module_not_loaded', 'Share URL module chưa load.', array( 'status' => 503 ) );
		}

		$url = (string) bcpro_get_astro_public_url( $coachee_id, $chart_type, true );

		return rest_ensure_response( array( 'success' => true, 'url' => $url ) );
	}

	/* ------------------------------------------------------------------ *
	 * Fetch and normalize a single profile by coachee_id + user_id.
	 *
	 * [2026-06-05 Johnny Chu] PHASE-A A-FE-1
	 * Returns the same shape as list_profiles() items so React can upsert it.
	 * ------------------------------------------------------------------ */
	private static function fetch_normalized_profile( $coachee_id, $uid ) {
		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_plans = $wpdb->prefix . 'bccm_action_plans';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.id AS coachee_id, c.user_id, c.full_name, c.dob, c.phone,
			        c.extra_fields_json,
			        c.created_at AS coachee_created_at,
			        a.chart_type, a.birth_time, a.birth_place, a.updated_at,
			        CASE WHEN a.summary IS NOT NULL AND a.summary <> '' THEN 1 ELSE 0 END AS has_chart,
			        p.public_key
			 FROM {$t_coach} c
			 LEFT JOIN {$t_astro} a ON a.coachee_id = c.id
			 LEFT JOIN {$t_plans} p ON p.coachee_id = c.id AND p.status = 'active'
			 WHERE c.id = %d
			 ORDER BY a.chart_type ASC",
			$coachee_id
			/* [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — removed user_id filter; ownership
			   checked upstream (can_own_profile). SQL comment moved here (not inside prepare
			   string) to avoid wpdb->prepare() treating literal percent-d as placeholder. */
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return null;
		}

		// Use actual DB user_id (may be 0 for admin-created profiles)
		$actual_uid = (int) $rows[0]['user_id'];
		if ( $actual_uid === 0 ) {
			// For display / ownership tracking, fall back to the resolved owner uid
			$actual_uid = $uid;
		}

		// [2026-06-06 Johnny Chu] PHASE-B B-BE-2 — hoist birth_coords from extra_fields_json
		$coords = self::read_birth_coords( (string) ( $rows[0]['extra_fields_json'] ?? '' ) );

		$out = array(
			'id'          => $coachee_id,
			'user_id'     => $actual_uid,
			'full_name'   => (string) $rows[0]['full_name'],
			'dob'         => (string) $rows[0]['dob'],
			'phone'       => (string) ( $rows[0]['phone'] ?? '' ),
			'birth_time'  => '',
			'birth_place' => '',
			'birth_lat'   => isset( $coords['lat'] ) ? (float) $coords['lat'] : null,
			'birth_lng'   => isset( $coords['lng'] ) ? (float) $coords['lng'] : null,
			'birth_tz'    => isset( $coords['tz'] )  ? (string) $coords['tz']  : '',
			'created_at'  => (string) ( $rows[0]['coachee_created_at'] ?? '' ),
			'updated_at'  => '',
			'chart_types' => array(),
			'has_chart'   => false,
			'share_urls'  => array(),
		);

		foreach ( $rows as $r ) {
			$ct = ! empty( $r['chart_type'] ) ? (string) $r['chart_type'] : '';
			if ( $ct && ! in_array( $ct, $out['chart_types'], true ) ) {
				$out['chart_types'][] = $ct;
			}
			if ( (bool) $r['has_chart'] ) { $out['has_chart'] = true; }
			if ( $ct && ! empty( $r['public_key'] ) && function_exists( 'bcpro_get_astro_public_url' ) ) {
				$share_url = (string) bcpro_get_astro_public_url( $coachee_id, $ct );
				if ( $share_url ) { $out['share_urls'][ 'natal_view_' . $ct ] = $share_url; }
			}
			if ( $ct === 'western' || ! $out['birth_time'] ) {
				if ( $r['birth_time'] )  { $out['birth_time']  = (string) $r['birth_time']; }
				if ( $r['birth_place'] ) { $out['birth_place'] = (string) $r['birth_place']; }
				if ( $r['updated_at'] )  { $out['updated_at']  = (string) $r['updated_at']; }
			}
		}

		// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — include full public links for FE quick tools.
		$_links = self::build_public_share_urls( (int) $coachee_id );
		if ( ! empty( $_links ) ) {
			$out['share_urls'] = array_merge( $out['share_urls'], $_links );
		}

		// [2026-06-12 Johnny Chu] PHASE-NATAL-REPORT — inject open-chart natal report URL
		$ocurl = self::build_natal_report_url( $out );
		if ( $ocurl ) {
			$out['share_urls']['natal_report_open_chart'] = $ocurl;
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Build public /natal-report/?data= URL from a profile row array.
	 * Encodes { payload:{...}, createdAt:N } as base64url (URL-safe, no padding).
	 * [2026-06-12 Johnny Chu] PHASE-NATAL-REPORT
	 * ------------------------------------------------------------------ */
	private static function build_natal_report_url( array $p ): string {
		$dob   = isset( $p['dob'] ) ? (string) $p['dob'] : '';
		$parts = explode( '-', $dob );
		$year  = isset( $parts[0] ) ? (int) $parts[0] : 0;
		$month = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$day   = isset( $parts[2] ) ? (int) $parts[2] : 0;
		if ( ! $year || ! $month || ! $day ) { return ''; }

		$bt         = isset( $p['birth_time'] ) ? trim( (string) $p['birth_time'] ) : '';
		$time_parts = $bt !== '' ? explode( ':', $bt ) : array( '12', '0' );
		$hour       = (int) ( isset( $time_parts[0] ) ? $time_parts[0] : 12 );
		$minute     = (int) ( isset( $time_parts[1] ) ? $time_parts[1] : 0 );

		$lat = ( isset( $p['birth_lat'] ) && $p['birth_lat'] !== null ) ? (float) $p['birth_lat'] : 0.0;
		$lng = ( isset( $p['birth_lng'] ) && $p['birth_lng'] !== null ) ? (float) $p['birth_lng'] : 0.0;
		$tz  = ( isset( $p['birth_tz'] ) && $p['birth_tz'] !== '' )    ? (string) $p['birth_tz']  : 'UTC';

		$payload = array(
			// [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX — include coachee_id so
			// public /natal-report can prefer local uploads SVG URL ({id}_natal.svg).
			'coachee_id'   => isset( $p['id'] ) ? (int) $p['id'] : 0,
			'name'         => isset( $p['full_name'] )   ? (string) $p['full_name']   : '',
			'year'         => $year,
			'month'        => $month,
			'day'          => $day,
			'hour'         => $hour,
			'minute'       => $minute,
			'lat'          => $lat,
			'lng'          => $lng,
			'tz_str'       => $tz,
			'city'         => isset( $p['birth_place'] ) ? (string) $p['birth_place'] : '',
			'house_system' => 'placidus',
			'zodiac_type'  => 'tropical',
		);

		$envelope = array(
			'payload'   => $payload,
			'createdAt' => time(),
		);

		$json   = (string) wp_json_encode( $envelope );
		$b64    = base64_encode( $json );
		$b64url = strtr( rtrim( $b64, '=' ), '+/', '-_' );

		return home_url( '/natal-report/?data=' . $b64url );
	}

	/* ------------------------------------------------------------------ *
	 * Build full public share URLs used by Dashboard/Profile quick actions.
	 * [2026-07-05 Johnny Chu] PHASE-NATAL-REPORT FIX
	 * ------------------------------------------------------------------ */
	private static function build_public_share_urls( int $coachee_id ): array {
		if ( $coachee_id <= 0 ) {
			return array();
		}

		$natal_hash = function_exists( 'bccm_generate_natal_chart_hash' )
			? (string) bccm_generate_natal_chart_hash( $coachee_id )
			: substr( md5( $coachee_id . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'bccm' ) ), 0, 16 );

		$report_nonce  = wp_create_nonce( 'bccm_natal_report_full' );
		$transit_nonce = wp_create_nonce( 'bccm_transit_report' );

		$router_ok         = class_exists( 'BizCoach_Pro_Astro_Public_Router' );
		$transit_router_ok = class_exists( 'BizCoach_Pro_Transit_Public_Router' );

		$out = array(
			'natal_chart'      => home_url( '/my-natal-chart/?id=' . $coachee_id . '&hash=' . $natal_hash ),
			'natal_chart_view' => home_url( '/my-natal-chart/?id=' . $coachee_id . '&hash=' . $natal_hash ),
		);

		if ( $router_ok ) {
			$out['natal_report_western'] = BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'western' );
			$out['natal_report_vedic']   = BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'vedic' );
			$out['natal_report_chinese'] = BizCoach_Pro_Astro_Public_Router::get_public_url( $coachee_id, 'chinese' );
		} else {
			$out['natal_report_western'] = admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=western&_wpnonce=' . $report_nonce );
			$out['natal_report_vedic']   = admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=vedic&_wpnonce=' . $report_nonce );
			$out['natal_report_chinese'] = admin_url( 'admin-ajax.php?action=bccm_natal_report_full&coachee_id=' . $coachee_id . '&chart_type=chinese&_wpnonce=' . $report_nonce );
		}

		if ( $transit_router_ok ) {
			$out['transit_day']   = BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'day' );
			$out['transit_week']  = BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'week' );
			$out['transit_month'] = BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'month' );
			$out['transit_year']  = BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'year' );
		} else {
			$out['transit_day']   = admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=day&_wpnonce=' . $transit_nonce );
			$out['transit_week']  = admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=week&_wpnonce=' . $transit_nonce );
			$out['transit_month'] = admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=month&_wpnonce=' . $transit_nonce );
			$out['transit_year']  = admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=year&_wpnonce=' . $transit_nonce );
		}

		foreach ( $out as $k => $v ) {
			if ( ! is_string( $v ) || $v === '' ) {
				unset( $out[ $k ] );
			}
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Schema args for create validation
	 * ------------------------------------------------------------------ */
	private static function profile_schema_args() {
		return array(
			'full_name'   => array( 'type' => 'string', 'required' => true,  'sanitize_callback' => 'sanitize_text_field' ),
			'dob'         => array( 'type' => 'string', 'required' => true ),
			'birth_time'  => array( 'type' => 'string', 'required' => false ),
			'birth_place' => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'chart_type'  => array( 'type' => 'string', 'required' => false, 'enum' => array( 'western', 'vedic', 'chinese' ) ),
			'phone'       => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — allow FE to set/unset primary self profile.
			'is_self'     => array( 'type' => 'boolean', 'required' => false ),
			// [2026-07-07 Johnny Chu] HOTFIX — accept null/string for lat/lng so REST does not reject
			// partial profile saves; collect_birth_coords() will ignore invalid coordinates.
			'birth_lat'   => array( 'type' => array( 'number', 'string', 'null' ), 'required' => false ),
			'birth_lng'   => array( 'type' => array( 'number', 'string', 'null' ), 'required' => false ),
			'birth_tz'    => array( 'type' => 'string', 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			// [2026-07-04 Johnny Chu] PHASE-VEDIC-FAA2 FIX — allow null (type array accepts integer|null).
			// WP REST validates strictly; sending null with type:'integer' returns 400.
			'geoname_id'  => array( 'type' => array( 'integer', 'string', 'null' ), 'required' => false ),
		);
	}

	/**
	 * PATCH schema — same fields as profile_schema_args but ALL optional.
	 * [2026-07-03 Johnny Chu] PHASE-FAA2-FE — WP REST rejects unknown params
	 * unless declared in route args. PATCH is partial-update so required=false.
	 */
	private static function profile_schema_args_patch() {
		$args = self::profile_schema_args();
		foreach ( $args as &$a ) {
			$a['required'] = false;
		}
		unset( $a );
		return $args;
	}

	/* ------------------------------------------------------------------ *
	 * GET /me/profiles/{id}/chart-data
	 *
	 * [2026-06-05 Johnny Chu] PHASE-A A-FE-1
	 * Returns parsed traits JSON in AstrologerStudio-compatible shape so
	 * React components (NatalPlanetPositionsCard, AspectsCard, ZoomableChart)
	 * can consume directly without re-fetching from gateway.
	 * ------------------------------------------------------------------ */
	public static function get_chart_data( $request ) {
		global $wpdb;
		$coachee_id = (int) $request->get_param( 'id' );
		$chart_type = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );
		if ( ! in_array( $chart_type, array( 'western', 'vedic', 'chinese' ), true ) ) {
			$chart_type = 'western';
		}

		$t_astro = $wpdb->prefix . 'bccm_astro';
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — newest row first; older rows can miss traits/aspects/houses.
		$row     = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_astro} WHERE coachee_id = %d AND chart_type = %s ORDER BY id DESC LIMIT 1",
			$coachee_id, $chart_type
		), ARRAY_A );

		if ( ! $row ) {
			return rest_ensure_response( array( 'success' => true, 'has_chart' => false, 'chart_type' => $chart_type ) );
		}

		$traits  = $row['traits']  ? json_decode( (string) $row['traits'],  true ) : array();
		$summary = $row['summary'] ? json_decode( (string) $row['summary'], true ) : array();

		if ( ! is_array( $traits ) )  { $traits  = array(); }
		if ( ! is_array( $summary ) ) { $summary = array(); }

		return rest_ensure_response( array(
			'success'     => true,
			'has_chart'   => ! empty( $row['summary'] ),
			'chart_type'  => $chart_type,
			// [2026-06-07 Johnny Chu] HOTFIX — chart_svg column may store an S3 URL
			// (FAA V1: https://western-astrology.s3.ap-south-1.amazonaws.com/Chart_*.svg)
			// or inline SVG content (V2 gateway). Expose both fields so FE can decide.
			'chart_svg'   => (string) ( $row['chart_svg'] ?? '' ),
			'chart_url'   => (string) ( $traits['chart_url'] ?? $summary['chart_url'] ?? $row['chart_svg'] ?? '' ),
			'birth_time'  => (string) ( $row['birth_time'] ?? '' ),
			'birth_place' => (string) ( $row['birth_place'] ?? '' ),
			'updated_at'  => (string) ( $row['updated_at'] ?? '' ),
			// AstrologerStudio-compatible fields
			'planets'     => isset( $traits['planets'] )  ? $traits['planets']  : array(),
			'houses'      => isset( $traits['houses'] )   ? $traits['houses']   : array(),
			'aspects'     => isset( $traits['aspects'] )  ? $traits['aspects']  : array(),
			'angles'      => isset( $traits['angles'] )   ? $traits['angles']   : array(),
			'big3'        => isset( $summary['big3'] )    ? (array) $summary['big3']    : array(),
			// [2026-06-07 Johnny Chu] HOTFIX — expose full traits so FE PatternsTab reads patterns/special_features
			'traits'      => $traits,
			// Vedic-only
			'vedic'       => isset( $traits['vedic'] )    ? (array) $traits['vedic']    : array(),
			// Chinese-only
			'chinese'     => isset( $traits['chinese'] )  ? (array) $traits['chinese']  : array(),
			// AI narrative
			'llm_report'  => (string) ( $row['llm_report'] ?? '' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-10 Johnny Chu] PHASE-REPORT RPT-BE — shared astro_row resolver.
	 * Mirrors legacy bccm_llm_section_handler: prefer user_id match (the row
	 * the chart generator actually writes), fall back to coachee_id.
	 *
	 * @return array|null  ARRAY_A astro row or null.
	 * ------------------------------------------------------------------ */
	private static function resolve_astro_row( $coachee_id, $chart_type ) {
		global $wpdb;
		$t       = $wpdb->prefix . 'bccm_astro';
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$coachee = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1", $coachee_id
		), ARRAY_A );
		$user_id = $coachee ? (int) ( $coachee['user_id'] ?? 0 ) : 0;

		$row = null;
		if ( $user_id > 0 ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE user_id = %d AND chart_type = %s AND (summary IS NOT NULL OR traits IS NOT NULL) ORDER BY id DESC LIMIT 1",
				$user_id, $chart_type
			), ARRAY_A );
		}
		if ( ! $row ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t} WHERE coachee_id = %d AND chart_type = %s ORDER BY id DESC LIMIT 1",
				$coachee_id, $chart_type
			), ARRAY_A );
		}
		return array( 'row' => $row, 'coachee' => $coachee );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-10 Johnny Chu] PHASE-REPORT RPT-BE-1 — section titles/icons +
	 * cached flags. Lets the React report tab render the chapter skeleton and
	 * know which chapters already exist in SQL before lazy-loading.
	 *
	 * GET /me/profiles/{id}/report-meta?chart_type=western
	 * ------------------------------------------------------------------ */
	public static function get_report_meta( $request ) {
		$coachee_id = (int) $request->get_param( 'id' );
		$chart_type = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );
		if ( ! in_array( $chart_type, array( 'western', 'vedic', 'chinese' ), true ) ) {
			$chart_type = 'western';
		}

		if ( ! function_exists( 'bccm_llm_get_sections' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Mô-đun luận giải chưa sẵn sàng trên máy chủ.',
			) );
		}

		$resolved  = self::resolve_astro_row( $coachee_id, $chart_type );
		$astro_row = $resolved['row'];
		$coachee   = $resolved['coachee'];
		if ( ! $astro_row ) {
			return rest_ensure_response( array(
				'success'    => true,
				'has_chart'  => false,
				'chart_type' => $chart_type,
				'sections'   => array(),
			) );
		}

		$name = $coachee && ! empty( $coachee['full_name'] ) ? $coachee['full_name'] : 'Người dùng';

		if ( $chart_type === 'chinese' && function_exists( 'bccm_chinese_llm_get_sections' ) ) {
			$sections = bccm_chinese_llm_get_sections( '', $name );
		} elseif ( $chart_type === 'vedic' && function_exists( 'bccm_vedic_llm_get_sections' ) ) {
			$sections = bccm_vedic_llm_get_sections( '', $name );
		} else {
			$sections = bccm_llm_get_sections( '', $name );
		}

		$chart_hash = md5( (string) ( $astro_row['updated_at'] ?? '' ) );
		$cached_raw = ! empty( $astro_row['llm_report'] ) ? json_decode( $astro_row['llm_report'], true ) : null;
		$cached_ok  = is_array( $cached_raw ) && ( ( $cached_raw['chart_hash'] ?? '' ) === $chart_hash );
		$cached_arr = $cached_ok && isset( $cached_raw['sections'] ) && is_array( $cached_raw['sections'] )
			? $cached_raw['sections'] : array();

		$out = array();
		foreach ( $sections as $idx => $sec ) {
			$has = isset( $cached_arr[ $idx ] ) && is_string( $cached_arr[ $idx ] ) && $cached_arr[ $idx ] !== '';
			$out[] = array(
				'idx'    => (int) $idx,
				'title'  => (string) ( $sec['title'] ?? ( 'Phần ' . ( $idx + 1 ) ) ),
				'icon'   => (string) ( $sec['icon'] ?? '✨' ),
				'cached' => (bool) $has,
			);
		}

		return rest_ensure_response( array(
			'success'    => true,
			'has_chart'  => true,
			'chart_type' => $chart_type,
			'name'       => (string) $name,
			'chart_hash' => $chart_hash,
			'generated'  => $cached_ok ? (string) ( $cached_raw['generated'] ?? '' ) : '',
			'sections'   => $out,
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-10 Johnny Chu] PHASE-REPORT RPT-BE-2 — lazy LLM chapter loader.
	 * Reuses the canonical caching contract from bccm_llm_section_handler:
	 *   bccm_astro.llm_report = {chart_hash, sections[], generated}
	 *   chart_hash = md5(updated_at)
	 * Returns cached HTML when present (unless regenerate=true), otherwise
	 * calls the LLM gateway, persists to SQL, and returns the rendered HTML.
	 *
	 * GET /me/profiles/{id}/report-section?chart_type=western&section=0&regenerate=0
	 * ------------------------------------------------------------------ */
	public static function get_report_section( $request ) {
		global $wpdb;
		@set_time_limit( 180 );

		$coachee_id  = (int) $request->get_param( 'id' );
		$chart_type  = sanitize_key( (string) ( $request->get_param( 'chart_type' ) ?? 'western' ) );
		$section_idx = (int) $request->get_param( 'section' );
		$regenerate  = (bool) $request->get_param( 'regenerate' );
		if ( ! in_array( $chart_type, array( 'western', 'vedic', 'chinese' ), true ) ) {
			$chart_type = 'western';
		}

		if ( ! function_exists( 'bccm_llm_get_sections' ) || ! function_exists( 'bccm_llm_call_openai' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Mô-đun luận giải chưa sẵn sàng trên máy chủ.',
			) );
		}

		$resolved  = self::resolve_astro_row( $coachee_id, $chart_type );
		$astro_row = $resolved['row'];
		$coachee   = $resolved['coachee'];
		if ( ! $astro_row ) {
			return new WP_Error( 'kg_empty', 'Chưa có bản đồ sao cho hệ ' . $chart_type . '. Hãy sinh biểu đồ trước.', array( 'status' => 404 ) );
		}

		$name = $coachee && ! empty( $coachee['full_name'] ) ? $coachee['full_name'] : 'Người dùng';

		// Build chart context + section list per system.
		if ( $chart_type === 'chinese' && function_exists( 'bccm_chinese_build_chart_context' ) ) {
			$chart_ctx = bccm_chinese_build_chart_context( $astro_row, $coachee );
			$sections  = bccm_chinese_llm_get_sections( $chart_ctx, $name );
		} elseif ( $chart_type === 'vedic' && function_exists( 'bccm_vedic_build_chart_context' ) ) {
			$chart_ctx = bccm_vedic_build_chart_context( $astro_row, $coachee );
			$sections  = function_exists( 'bccm_vedic_llm_get_sections' )
				? bccm_vedic_llm_get_sections( $chart_ctx, $name )
				: bccm_llm_get_sections( $chart_ctx, $name );
		} else {
			$chart_ctx = bccm_llm_build_chart_context( $astro_row, $coachee );
			$sections  = bccm_llm_get_sections( $chart_ctx, $name );
		}

		if ( $section_idx < 0 || $section_idx >= count( $sections ) ) {
			return new WP_Error( 'invalid_param', 'Chỉ số chương không hợp lệ.', array( 'status' => 400 ) );
		}

		$chart_hash = md5( (string) ( $astro_row['updated_at'] ?? '' ) );
		$cached_raw = ! empty( $astro_row['llm_report'] ) ? json_decode( $astro_row['llm_report'], true ) : null;

		// ── Cache hit ──
		if ( ! $regenerate && is_array( $cached_raw ) && ( ( $cached_raw['chart_hash'] ?? '' ) === $chart_hash ) ) {
			$cached_section = isset( $cached_raw['sections'][ $section_idx ] ) ? $cached_raw['sections'][ $section_idx ] : null;
			if ( ! empty( $cached_section ) && is_string( $cached_section ) ) {
				return rest_ensure_response( array(
					'success' => true,
					'idx'     => $section_idx,
					'title'   => (string) ( $sections[ $section_idx ]['title'] ?? '' ),
					'icon'    => (string) ( $sections[ $section_idx ]['icon'] ?? '✨' ),
					'html'    => bccm_llm_md_to_html( $cached_section ),
					'cached'  => true,
				) );
			}
		}

		// ── Generate via LLM gateway ──
		if ( $chart_type === 'chinese' && function_exists( 'bccm_chinese_llm_system_prompt' ) ) {
			$system = bccm_chinese_llm_system_prompt();
		} elseif ( $chart_type === 'vedic' && function_exists( 'bccm_vedic_llm_system_prompt' ) ) {
			$system = bccm_vedic_llm_system_prompt();
		} else {
			$system = bccm_llm_system_prompt();
		}
		$sec    = $sections[ $section_idx ];
		$result = bccm_llm_call_openai( $system, $sec['prompt'], array(
			'max_tokens'  => 10000,
			'temperature' => 0.75,
			'timeout'     => 150,
		) );

		if ( is_wp_error( $result ) ) {
			// [2026-06-09 Johnny Chu] PHASE-D D-BE-QUOTA — surface quota vs transient hint.
			$is_quota = $result->get_error_code() === 'quota_exhausted';
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $result->get_error_message(),
				'hint'      => $is_quota
					? 'Hết quota LLM tháng này. Nâng gói tại bizcity.vn/pricing hoặc liên hệ admin.'
					: 'Thử lại sau ít phút hoặc bấm Tạo lại chương.',
			) );
		}

		// ── Persist to SQL (bccm_astro.llm_report) ──
		if ( ! is_array( $cached_raw ) || ( ( $cached_raw['chart_hash'] ?? '' ) !== $chart_hash ) ) {
			$cached_raw = array( 'sections' => array(), 'generated' => '', 'chart_hash' => $chart_hash );
		}
		$cached_raw['sections'][ $section_idx ] = $result;
		$cached_raw['generated']                = current_time( 'mysql' );

		$wpdb->update(
			$wpdb->prefix . 'bccm_astro',
			array( 'llm_report' => wp_json_encode( $cached_raw, JSON_UNESCAPED_UNICODE ) ),
			array( 'id' => $astro_row['id'] )
		);

		return rest_ensure_response( array(
			'success' => true,
			'idx'     => $section_idx,
			'title'   => (string) ( $sec['title'] ?? '' ),
			'icon'    => (string) ( $sec['icon'] ?? '✨' ),
			'html'    => bccm_llm_md_to_html( $result ),
			'cached'  => false,
		) );
	}

	/* ------------------------------------------------------------------ *
	 * GET /me/profiles/{id}/transit?period=day|week|month|year&date=YYYY-MM-DD
	 *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-5
	 * Direct call to BizCoach_Pro_Astro_Client::transits_western — no more
	 * iframe round-trip through /my-transit/ public router. Caches result in
	 * bccm_transit_snapshots on success so /me/saved-calculations sees it.
	 * ------------------------------------------------------------------ */
	public static function get_transit( $request ) {
		global $wpdb;
		$coachee_id = (int) $request->get_param( 'id' );
		$period     = sanitize_key( (string) ( $request->get_param( 'period' ) ?? 'week' ) );
		$date       = sanitize_text_field( (string) ( $request->get_param( 'date' ) ?? '' ) );
		// [2026-07-10 Johnny Chu] PHASE-FAA2-FE — details=1 enables wheel SVG generation for day detail view only.
		$details    = (bool) $request->get_param( 'details' );
		$uid        = (int) get_current_user_id();

		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year', 'custom' ), true ) ) {
			$period = 'week';
		}

		// Default target date = today (server tz).
		if ( $date === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date = current_time( 'Y-m-d' );
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true,
				'message' => 'Astro client chưa load.' ) );
		}

		// Load coachee + natal row to get birth ctx + coords.
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		// [2026-06-08 Johnny Chu] HOTFIX — admin bypass: admin can view any profile's transit
		if ( current_user_can( 'manage_options' ) ) {
			$coachee = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1",
				$coachee_id
			), ARRAY_A );
		} else {
			$coachee = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t_coach} WHERE id = %d AND user_id = %d LIMIT 1",
				$coachee_id, $uid
			), ARRAY_A );
		}
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		$natal = $wpdb->get_row( $wpdb->prepare(
			"SELECT birth_time, birth_place FROM {$t_astro}
			 WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );

		$coords = self::read_birth_coords( (string) ( $coachee['extra_fields_json'] ?? '' ) );

		// [2026-06-08 Johnny Chu] HOTFIX — get_transit returned blank page because the
		// payload sent `subject{}` while gateway/FAA expects flat natal fields
		// (year/month/day/hour/minute/lat/lng/tz_str/city). FAA replied 422 →
		// success=false → FE blank. Build canonical natal block per FAA docs:
		// https://www.freeastroapi.com/dashboard/docs (/api/v1/transits/calculate).
		$dob_parts  = explode( '-', (string) ( $coachee['dob'] ?? '' ) );
		$dob_year   = isset( $dob_parts[0] ) ? (int) $dob_parts[0] : 0;
		$dob_month  = isset( $dob_parts[1] ) ? (int) $dob_parts[1] : 0;
		$dob_day    = isset( $dob_parts[2] ) ? (int) $dob_parts[2] : 0;

		$bt_str     = (string) ( $natal['birth_time'] ?? '' );
		$time_known = ( $bt_str !== '' );
		if ( ! $time_known ) { $bt_str = '12:00'; }
		$bt_parts   = explode( ':', $bt_str );
		$bt_hour    = isset( $bt_parts[0] ) ? (int) $bt_parts[0] : 12;
		$bt_min     = isset( $bt_parts[1] ) ? (int) $bt_parts[1] : 0;

		$birth_lat   = isset( $coords['lat'] ) ? (float) $coords['lat'] : 21.0285;
		$birth_lng   = isset( $coords['lng'] ) ? (float) $coords['lng'] : 105.8542;
		$birth_tz    = isset( $coords['tz'] )  ? (string) $coords['tz'] : 'Asia/Ho_Chi_Minh';
		$birth_place = (string) ( $natal['birth_place'] ?? '' );
		if ( $birth_place === '' ) { $birth_place = 'Hanoi'; }

		// FAA wants `transit_date` as ISO `YYYY-MM-DDTHH:MM`. Server gives us
		// `YYYY-MM-DD` only → append noon UTC so the wheel reflects mid-day.
		$transit_dt_iso = $date . 'T12:00';

		$payload = array(
			'natal' => array(
				'name'         => (string) $coachee['full_name'],
				'year'         => $dob_year,
				'month'        => $dob_month,
				'day'          => $dob_day,
				'hour'         => $bt_hour,
				'minute'       => $bt_min,
				'time_known'   => $time_known,
				'lat'          => $birth_lat,
				'lng'          => $birth_lng,
				'tz_str'       => $birth_tz,
				'city'         => $birth_place,
				// [2026-06-08 Johnny Chu] HOTFIX — FAA wants 'placidus' canonical name, not 'P'.
				'house_system' => 'placidus',
				'zodiac_type'  => 'tropical',
			),
			'transit_date' => $transit_dt_iso,
			'tz_str'       => $birth_tz,
			// Use natal location as current location (transit-to-natal context).
			'current_city' => $birth_place,
			'current_lat'  => $birth_lat,
			'current_lng'  => $birth_lng,
			'orb_settings' => array(
				'Conjunction' => 8.0,
				'Opposition'  => 8.0,
				'Trine'       => 6.0,
				'Square'      => 6.0,
				'Sextile'     => 4.0,
			),
		);

		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — route through do_transit_fetch() so:
		// 1. FAA2 aspect calculator runs (transit-to-natal aspects are populated).
		// 2. Planets are saved in AstroPoint format (name/position/abs_pos) not raw FAA2
		//    (name_en/absolute_degree) — FE toTransitPlanetArray requires the former.
		// 3. Single canonical persist path (do_transit_fetch handles table creation + upsert).
		// $payload is still built above and passed only to generate_transit_day_wheel_svg_url().
		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — $natal can be null (no natal row) →
		// TypeError fatal if passed directly to array-typed param. Coerce to [].
		// [2026-07-10 Johnny Chu] PHASE-FAA2 — mark FE transit view fetch as manual_view source.
		$fetch_result = self::do_transit_fetch( $coachee, is_array( $natal ) ? $natal : array(), $date, $period, 'manual_view' );
		if ( empty( $fetch_result['success'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => isset( $fetch_result['message'] )
					? (string) $fetch_result['message']
					: 'Transit fetch thất bại.',
			) );
		}
		$planets = isset( $fetch_result['planets'] ) ? (array) $fetch_result['planets'] : array();
		$aspects = isset( $fetch_result['aspects'] ) ? (array) $fetch_result['aspects'] : array();

		// [2026-07-10 Johnny Chu] PHASE-FAA2-FE — day-level public links for Open/Share/Download bar.
		$public_urls = self::build_transit_day_public_urls( $coachee_id, $date );
		// [2026-07-10 Johnny Chu] PHASE-FAA2-FE — default to existing saved wheel SVG URL.
		$wheel_svg_url = self::resolve_western_wheel_svg_url( $coachee_id );
		// [2026-07-10 Johnny Chu] PHASE-FAA2-FE — generate transit day SVG only for explicit details=1 calls.
		if ( $details ) {
			$generated_svg = self::generate_transit_day_wheel_svg_url( $payload, $coachee_id, $date );
			if ( $generated_svg !== '' ) {
				$wheel_svg_url = $generated_svg;
			}
		}

		// Verify do_transit_fetch wrote the row correctly.
		$t_snap      = $wpdb->prefix . 'bccm_transit_snapshots';
		$_verify_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT planets_json FROM {$t_snap} WHERE coachee_id = %d AND target_date = %s LIMIT 1",
			$coachee_id,
			$date
		), ARRAY_A );
		if ( ! $_verify_row || empty( $_verify_row['planets_json'] ) ) {
			error_log( '[bccm_transit] get_transit DB verify failed — row missing after do_transit_fetch'
				. ' coachee_id=' . $coachee_id . ' date=' . $date . ' blog_id=' . (int) get_current_blog_id() );
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Fetch transit có response nhưng chưa lưu dữ liệu thành công vào SQL.',
				'date'      => $date,
			) );
		}
		$_verify_planets = json_decode( (string) $_verify_row['planets_json'], true );
		if ( ! self::has_usable_transit_planets( (array) $_verify_planets ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'SQL đã có row nhưng planets_json không usable.',
				'date'      => $date,
			) );
		}

		return rest_ensure_response( array(
			'success'     => true,
			'target_date' => $date,
			'period'      => $period,
			'planets'     => $planets,
			'aspects'     => $aspects,
			'wheel_svg_url' => $wheel_svg_url,
			'public_urls' => $public_urls,
			'_source'     => isset( $fetch_result['_source'] ) ? $fetch_result['_source'] : 'faa2_western',
		) );
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — validate transit planet payload quality.
	 *
	 * Some broken provider responses contain only Ascendant/MC placeholders, which
	 * should be treated as missing data so rebuild/manual fetch keeps running.
	 *
	 * @param array $planets Decoded planets_json or API planets payload.
	 * @return bool
	 */
	private static function has_usable_transit_planets( $planets ) {
		if ( ! is_array( $planets ) || empty( $planets ) ) {
			return false;
		}

		$core = array(
			'sun' => true,
			'moon' => true,
			'mercury' => true,
			'venus' => true,
			'mars' => true,
			'jupiter' => true,
			'saturn' => true,
			'uranus' => true,
			'neptune' => true,
			'pluto' => true,
		);

		$core_found       = 0;
		$core_with_degree = 0;
		$core_non_zero    = 0;
		$degree_buckets   = array();

		foreach ( $planets as $k => $row ) {
			$name = '';
			if ( is_array( $row ) ) {
				if ( ! empty( $row['name'] ) ) {
					$name = (string) $row['name'];
				} elseif ( ! empty( $row['name_en'] ) ) {
					$name = (string) $row['name_en'];
				} elseif ( ! empty( $row['planet_name'] ) ) {
					$name = (string) $row['planet_name'];
				} elseif ( isset( $row['planet'] ) && is_array( $row['planet'] ) && ! empty( $row['planet']['en'] ) ) {
					$name = (string) $row['planet']['en'];
				}
			}
			if ( $name === '' && is_string( $k ) ) {
				$name = $k;
			}
			$name = strtolower( trim( $name ) );
			if ( isset( $core[ $name ] ) ) {
				$core_found++;
				$degree = self::extract_transit_degree_value( $row );
				if ( null !== $degree ) {
					$core_with_degree++;
					if ( abs( (float) $degree ) > 0.000001 ) {
						$core_non_zero++;
					}
					$degree_buckets[ (string) floor( ( (float) $degree + 3600.0 ) * 10 ) ] = true;
				}
			}
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — reject poison rows where
		// core planets exist by name but all degree values are zero placeholders.
		if ( $core_found < 3 ) {
			return false;
		}
		if ( $core_with_degree <= 0 ) {
			return false;
		}
		if ( $core_non_zero <= 0 ) {
			return false;
		}
		if ( $core_found >= 5 && $core_non_zero < 2 ) {
			return false;
		}
		if ( $core_found >= 5 && count( $degree_buckets ) <= 1 ) {
			return false;
		}

		return true;
	}

	/**
	 * [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — degree extractor for mixed transit schemas.
	 *
	 * @param mixed $row
	 * @return float|null
	 */
	private static function extract_transit_degree_value( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$keys = array(
			'absolute_degree',
			'abs_pos',
			'full_degree',
			'fullDegree',
			'norm_degree',
			'normDegree',
			'position',
			'degree',
			'sign_degree',
		);

		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return (float) $row[ $key ];
			}
		}

		return null;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — manual/admin callable migration tick.
	 *
	 * @param string $table
	 * @return void
	 */
	public static function migrate_transit_snapshots_batch( $table = '' ) {
		global $wpdb;
		$table_name = ( is_string( $table ) && $table !== '' )
			? $table
			: ( $wpdb->prefix . 'bccm_transit_snapshots' );
		self::ensure_transit_snapshot_schema( $table_name );
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — ensure source_marker column/index exists and run normalization tick.
	 */
	private static function ensure_transit_snapshot_schema( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( $table === '' ) {
			return;
		}

		$exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		if ( ! $exists ) {
			return;
		}

		$has_source_marker = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table,
			'source_marker'
		) );
		$table_sql = str_replace( '`', '', $table );
		if ( ! $has_source_marker ) {
			$wpdb->query( "ALTER TABLE `{$table_sql}` ADD COLUMN source_marker VARCHAR(32) NOT NULL DEFAULT '' AFTER label" );
		}

		$has_source_idx = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
			$table,
			'idx_source_marker'
		) );
		if ( ! $has_source_idx ) {
			$wpdb->query( "ALTER TABLE `{$table_sql}` ADD KEY idx_source_marker (source_marker)" );
		}

		self::run_transit_snapshot_migration_tick( $table );
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — normalize legacy
	 * planets_json/aspects_json + label + source_marker (v2 marker rollout).
	 */
	private static function run_transit_snapshot_migration_tick( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( $table === '' ) {
			return;
		}

		$done_key   = 'bcpro_tr_snap_norm_done_' . self::TRANSIT_MIGRATION_VERSION;
		$cursor_key = 'bcpro_tr_snap_norm_cursor_' . self::TRANSIT_MIGRATION_VERSION;
		if ( get_option( $done_key, '' ) === '1' ) {
			return;
		}

		$cursor    = (int) get_option( $cursor_key, 0 );
		$table_sql = str_replace( '`', '', $table );
		$rows      = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, coachee_id, label, planets_json, aspects_json, source_marker
			 FROM `{$table_sql}`
			 WHERE id > %d
			 ORDER BY id ASC
			 LIMIT %d",
			$cursor,
			self::TRANSIT_MIGRATION_BATCH_SIZE
		), ARRAY_A );

		if ( empty( $rows ) ) {
			update_option( $done_key, '1', false );
			delete_option( $cursor_key );
			return;
		}

		$updated = 0;
		$last_id = $cursor;
		foreach ( $rows as $row ) {
			$row_id   = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$last_id  = max( $last_id, $row_id );
			$raw_planets_json = isset( $row['planets_json'] ) ? (string) $row['planets_json'] : '';
			$decoded_planets  = json_decode( $raw_planets_json, true );
			if ( ! is_array( $decoded_planets ) ) {
				$decoded_planets = array();
			}
			$raw_aspects_json = isset( $row['aspects_json'] ) ? (string) $row['aspects_json'] : '';
			$decoded_aspects  = json_decode( $raw_aspects_json, true );
			if ( ! is_array( $decoded_aspects ) ) {
				$decoded_aspects = array();
			}

			$legacy_shape = false;
			$normalized_rows     = self::normalize_transit_planets_for_storage( $decoded_planets, $legacy_shape );
			$normalized_positions = self::normalize_transit_snapshot_positions_map( $normalized_rows );
			$normalized_aspects   = self::normalize_transit_snapshot_aspects( $decoded_aspects, $normalized_positions, array(), 'snapshot_normalized_v2' );

			// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 v3 — re-calculate aspects
			// for rows written before the natal-shape fix. Those rows got 0 aspects because
			// BizCity_Transit_Aspect_Calculator::calc() couldn't read planet name/degree from
			// the assoc-keyed positions map. Migration v2 only normalized shape; it didn't
			// re-run the calculation. v3 detects empty aspects + valid planets + natal in DB
			// and re-calculates purely in PHP (no API call).
			if ( empty( $normalized_aspects )
				&& count( $normalized_positions ) >= 3
				&& isset( $row['coachee_id'] ) && (int) $row['coachee_id'] > 0
				&& class_exists( 'BizCity_Transit_Aspect_Calculator' ) ) {

				$_mig_t_astro = $wpdb->prefix . 'bccm_astro';
				$_mig_natal_json = $wpdb->get_var( $wpdb->prepare(
					"SELECT traits FROM {$_mig_t_astro}
					  WHERE coachee_id = %d AND chart_type = 'western'
					  ORDER BY id DESC LIMIT 1",
					(int) $row['coachee_id']
				) );
				if ( ! $_mig_natal_json ) {
					$_mig_natal_json = $wpdb->get_var( $wpdb->prepare(
						"SELECT traits FROM {$_mig_t_astro} WHERE coachee_id = %d ORDER BY id DESC LIMIT 1",
						(int) $row['coachee_id']
					) );
				}

				if ( $_mig_natal_json ) {
					$_mig_natal_dec = json_decode( (string) $_mig_natal_json, true );
					$_mig_natal_pos = is_array( $_mig_natal_dec )
						? (array) ( $_mig_natal_dec['positions'] ?? array() ) : array();

					if ( ! empty( $_mig_natal_pos ) ) {
						// Transit positions in $normalized_positions are assoc-keyed map
						// (Sun => {full_degree, ...}) — calc() A10 fix handles this format.
						$_mig_raw_asp = BizCity_Transit_Aspect_Calculator::calc(
							$normalized_positions, // transit planets — assoc map (A10-compat)
							$_mig_natal_pos,       // natal planets — assoc map (A10-compat)
							array(),
							false                  // outer_only=false — match legacy calc scope
						);

						if ( ! empty( $_mig_raw_asp ) ) {
							// Build natal sign lookup from absolute degree
							$_mig_ns_lookup = array();
							foreach ( $_mig_natal_pos as $_pname => $_pdata ) {
								if ( is_array( $_pdata ) ) {
									$_ns_deg = isset( $_pdata['full_degree'] ) ? (float) $_pdata['full_degree'] : 0.0;
									$_mig_ns_lookup[ (string) $_pname ] = self::transit_sign_name_from_absolute_degree( $_ns_deg );
								}
							}
							$normalized_aspects = self::normalize_transit_snapshot_aspects(
								$_mig_raw_asp,
								$normalized_positions,
								$_mig_ns_lookup,
								'snapshot_recalc_v3'
							);
						}
					}
				}
			}

			$new_planets_json = wp_json_encode( $normalized_positions, JSON_UNESCAPED_UNICODE );
			$new_aspects_json = wp_json_encode( $normalized_aspects, JSON_UNESCAPED_UNICODE );
			$new_label    = self::normalize_transit_label_value( isset( $row['label'] ) ? (string) $row['label'] : '' );
			$new_source   = self::derive_transit_source_marker(
				isset( $row['label'] ) ? (string) $row['label'] : '',
				$legacy_shape,
				isset( $row['source_marker'] ) ? (string) $row['source_marker'] : ''
			);

			$old_planets_cmp = wp_json_encode( $decoded_planets, JSON_UNESCAPED_UNICODE );
			$old_aspects_cmp = wp_json_encode( $decoded_aspects, JSON_UNESCAPED_UNICODE );
			$old_label    = isset( $row['label'] ) ? (string) $row['label'] : '';
			$old_source   = isset( $row['source_marker'] ) ? (string) $row['source_marker'] : '';

			if ( $old_planets_cmp !== $new_planets_json || $old_aspects_cmp !== $new_aspects_json || $old_label !== $new_label || $old_source !== $new_source ) {
				$res = $wpdb->query( $wpdb->prepare(
					"UPDATE `{$table_sql}`
					 SET planets_json = %s, aspects_json = %s, label = %s, source_marker = %s
					 WHERE id = %d",
					$new_planets_json,
					$new_aspects_json,
					$new_label,
					$new_source,
					$row_id
				) );
				if ( false !== $res ) {
					$updated++;
				}
			}
		}

		update_option( $cursor_key, (string) $last_id, false );
		if ( count( $rows ) < self::TRANSIT_MIGRATION_BATCH_SIZE ) {
			update_option( $done_key, '1', false );
			delete_option( $cursor_key );
		}

		if ( $updated > 0 ) {
			// [2026-07-06 Johnny Chu] HOTFIX — quiet migration info logs to reduce transit log noise.
			// error_log( '[bccm_transit] migration_tick normalized rows=' . $updated
			// 	. ' blog_id=' . (int) get_current_blog_id() );
		}
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — normalize snapshot label into canonical period tokens.
	 */
	private static function normalize_transit_label_value( $label ) {
		$label = strtolower( trim( (string) $label ) );
		$allowed = array( 'day', 'week', 'month', 'year', '5year', 'custom_year' );
		return in_array( $label, $allowed, true ) ? $label : 'day';
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — source marker heuristic
	 * upgraded to `_v2` markers so old/new snapshot shapes are distinguishable.
	 */
	private static function derive_transit_source_marker( $raw_label, $legacy_shape, $source_marker ) {
		$source_marker = sanitize_key( (string) $source_marker );
		if ( $source_marker === self::TRANSIT_SOURCE_DO_FETCH_V2 || $source_marker === self::TRANSIT_SOURCE_LEGACY_PREFETCH_V2 ) {
			return $source_marker;
		}
		if ( $source_marker === self::TRANSIT_SOURCE_DO_FETCH ) {
			return self::TRANSIT_SOURCE_DO_FETCH_V2;
		}
		if ( $source_marker === self::TRANSIT_SOURCE_LEGACY_PREFETCH ) {
			return self::TRANSIT_SOURCE_LEGACY_PREFETCH_V2;
		}

		$raw_label = strtolower( trim( (string) $raw_label ) );
		$allowed   = array( 'day', 'week', 'month', 'year', '5year', 'custom_year' );
		if ( $legacy_shape || ( $raw_label !== '' && ! in_array( $raw_label, $allowed, true ) ) ) {
			return self::TRANSIT_SOURCE_LEGACY_PREFETCH_V2;
		}

		return self::TRANSIT_SOURCE_DO_FETCH_V2;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — normalize legacy/map planets_json to canonical list rows.
	 *
	 * @param array $planets
	 * @param bool  $legacy_shape
	 * @return array
	 */
	private static function normalize_transit_planets_for_storage( array $planets, &$legacy_shape = false ) {
		$legacy_shape = false;
		if ( empty( $planets ) ) {
			return array();
		}

		$rows = array();
		if ( self::is_list_array( $planets ) ) {
			$rows = $planets;
		} else {
			$legacy_shape = true;
			foreach ( $planets as $planet_key => $planet_row ) {
				if ( is_array( $planet_row ) ) {
					$planet_row['__planet_key'] = (string) $planet_key;
					$rows[] = $planet_row;
				}
			}
		}

		$normalized = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			if ( isset( $row['norm_degree'] ) || isset( $row['sign_vi'] ) || isset( $row['is_retro'] ) ) {
				$legacy_shape = true;
			}

			$name = '';
			if ( ! empty( $row['name'] ) ) {
				$name = (string) $row['name'];
			} elseif ( ! empty( $row['name_en'] ) ) {
				$name = (string) $row['name_en'];
			} elseif ( ! empty( $row['planet_name'] ) ) {
				$name = (string) $row['planet_name'];
			} elseif ( ! empty( $row['key'] ) ) {
				$name = (string) $row['key'];
			} elseif ( ! empty( $row['__planet_key'] ) ) {
				$name = (string) $row['__planet_key'];
			}
			$name = trim( $name );
			$name = self::canonical_transit_planet_name( $name );
			if ( $name === '' ) {
				continue;
			}

			$sign = '';
			if ( ! empty( $row['sign'] ) ) {
				$sign = (string) $row['sign'];
			} elseif ( ! empty( $row['sign_en'] ) ) {
				$sign = (string) $row['sign_en'];
			} elseif ( isset( $row['zodiac_sign'] ) && is_array( $row['zodiac_sign'] )
				&& isset( $row['zodiac_sign']['name'] ) && is_array( $row['zodiac_sign']['name'] )
				&& ! empty( $row['zodiac_sign']['name']['en'] ) ) {
				$sign = (string) $row['zodiac_sign']['name']['en'];
			}

			$position = null;
			foreach ( array( 'position', 'norm_degree', 'normDegree', 'sign_degree' ) as $pos_key ) {
				if ( isset( $row[ $pos_key ] ) && is_numeric( $row[ $pos_key ] ) ) {
					$position = (float) $row[ $pos_key ];
					break;
				}
			}

			$abs_pos = self::extract_transit_degree_value( $row );
			if ( null === $position && null !== $abs_pos ) {
				$position = fmod( (float) $abs_pos, 30.0 );
				if ( $position < 0 ) {
					$position += 30.0;
				}
			}

			$sign_num = 0;
			if ( isset( $row['sign_num'] ) && is_numeric( $row['sign_num'] ) ) {
				$sign_num = (int) $row['sign_num'];
			} elseif ( isset( $row['sign_number'] ) && is_numeric( $row['sign_number'] ) ) {
				$sign_num = (int) $row['sign_number'];
			} elseif ( $sign !== '' ) {
				$sign_num = self::transit_sign_number_from_name( $sign );
			}

			$house = null;
			if ( isset( $row['house'] ) && $row['house'] !== '' && null !== $row['house'] ) {
				$house = is_numeric( $row['house'] ) ? (string) (int) $row['house'] : (string) $row['house'];
			}

			$retrograde = self::normalize_transit_bool(
				isset( $row['retrograde'] ) ? $row['retrograde']
					: ( isset( $row['is_retro'] ) ? $row['is_retro']
					: ( isset( $row['isRetro'] ) ? $row['isRetro'] : false ) )
			);

			$normalized[] = array(
				'name'       => $name,
				'position'   => null !== $position ? (float) $position : 0.0,
				'abs_pos'    => null !== $abs_pos ? (float) $abs_pos : ( null !== $position ? (float) $position : 0.0 ),
				'sign'       => $sign,
				'sign_num'   => $sign_num,
				'house'      => $house,
				'retrograde' => $retrograde,
			);
		}

		return $normalized;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — list-shape detector compatible with PHP 7.4.
	 */
	private static function is_list_array( array $rows ) {
		$idx = 0;
		foreach ( $rows as $k => $_v ) {
			if ( (string) $k !== (string) $idx ) {
				return false;
			}
			$idx++;
		}
		return true;
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — normalize mixed bool representations.
	 */
	private static function normalize_transit_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value !== 0;
		}
		$txt = strtolower( trim( (string) $value ) );
		return in_array( $txt, array( '1', 'true', 'yes', 'y', 'on' ), true );
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — sign name to ordinal lookup.
	 */
	private static function transit_sign_number_from_name( $sign_name ) {
		$map = array(
			'aries' => 1,
			'taurus' => 2,
			'gemini' => 3,
			'cancer' => 4,
			'leo' => 5,
			'virgo' => 6,
			'libra' => 7,
			'scorpio' => 8,
			'sagittarius' => 9,
			'capricorn' => 10,
			'aquarius' => 11,
			'pisces' => 12,
		);
		$key = strtolower( trim( (string) $sign_name ) );
		return isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — normalize transit planets
	 * to legacy positions map used by /my-transit/ report renderer.
	 */
	private static function normalize_transit_snapshot_positions_map( array $planets ) {
		if ( empty( $planets ) ) {
			return array();
		}

		$is_list = self::is_list_array( $planets );
		$rows = $is_list ? $planets : array_values( $planets );
		$keys = $is_list ? array() : array_keys( $planets );
		$planet_vi = function_exists( 'bccm_planet_names_vi' ) ? (array) bccm_planet_names_vi() : array();
		$sign_meta = self::transit_sign_meta_catalog();
		$positions = array();

		foreach ( $rows as $planet_idx => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name_raw = '';
			if ( ! empty( $row['name'] ) ) {
				$name_raw = (string) $row['name'];
			} elseif ( ! empty( $row['name_en'] ) ) {
				$name_raw = (string) $row['name_en'];
			} elseif ( ! empty( $row['planet_name'] ) ) {
				$name_raw = (string) $row['planet_name'];
			} elseif ( ! empty( $row['planet'] ) && is_array( $row['planet'] ) && ! empty( $row['planet']['en'] ) ) {
				$name_raw = (string) $row['planet']['en'];
			} elseif ( ! $is_list && isset( $keys[ $planet_idx ] ) && is_string( $keys[ $planet_idx ] ) ) {
				$name_raw = (string) $keys[ $planet_idx ];
			}
			$name = self::canonical_transit_planet_name( $name_raw );
			if ( $name === '' ) {
				continue;
			}

			$sign_en = '';
			if ( ! empty( $row['sign_en'] ) ) {
				$sign_en = (string) $row['sign_en'];
			} elseif ( ! empty( $row['sign'] ) ) {
				$sign_en = (string) $row['sign'];
			} elseif ( isset( $row['zodiac_sign'] ) && is_array( $row['zodiac_sign'] )
				&& isset( $row['zodiac_sign']['name'] ) && is_array( $row['zodiac_sign']['name'] )
				&& ! empty( $row['zodiac_sign']['name']['en'] ) ) {
				$sign_en = (string) $row['zodiac_sign']['name']['en'];
			}

			$abs_pos = self::extract_transit_degree_value( $row );
			if ( null === $abs_pos ) {
				$abs_pos = 0.0;
			}
			$norm_degree = null;
			foreach ( array( 'position', 'norm_degree', 'normDegree', 'sign_degree' ) as $pos_key ) {
				if ( isset( $row[ $pos_key ] ) && is_numeric( $row[ $pos_key ] ) ) {
					$norm_degree = (float) $row[ $pos_key ];
					break;
				}
			}
			if ( null === $norm_degree ) {
				$norm_degree = fmod( (float) $abs_pos, 30.0 );
				if ( $norm_degree < 0 ) {
					$norm_degree += 30.0;
				}
			}

			$meta_key = strtolower( trim( $sign_en ) );
			$meta = isset( $sign_meta[ $meta_key ] )
				? $sign_meta[ $meta_key ]
				: array( 'vi' => $sign_en, 'symbol' => '' );

			$positions[ $name ] = array(
				'planet_vi'   => isset( $planet_vi[ $name ] ) ? (string) $planet_vi[ $name ] : $name,
				'sign_en'     => $sign_en,
				'sign_vi'     => (string) $meta['vi'],
				'sign_symbol' => (string) $meta['symbol'],
				'norm_degree' => (float) $norm_degree,
				'full_degree' => (float) $abs_pos,
				'is_retro'    => self::normalize_transit_bool(
					isset( $row['retrograde'] ) ? $row['retrograde']
						: ( isset( $row['is_retro'] ) ? $row['is_retro']
						: ( isset( $row['isRetro'] ) ? $row['isRetro'] : false ) )
				),
			);
		}

		return $positions;
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — build natal sign lookup
	 * by canonical planet name for aspect payload enrichment.
	 */
	private static function build_transit_natal_sign_lookup( array $natal_planets ) {
		$lookup = array();
		foreach ( $natal_planets as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = self::canonical_transit_planet_name( (string) ( $row['name'] ?? $row['name_en'] ?? '' ) );
			if ( $name === '' ) {
				continue;
			}
			$sign = '';
			if ( ! empty( $row['sign'] ) ) {
				$sign = (string) $row['sign'];
			} elseif ( ! empty( $row['sign_en'] ) ) {
				$sign = (string) $row['sign_en'];
			}
			$lookup[ $name ] = $sign;
		}
		return $lookup;
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — classify aspect nature
	 * to keep snapshot compatibility with legacy transit report statistics.
	 */
	private static function transit_aspect_nature_from_name( $aspect_name ) {
		$aspect_name = trim( (string) $aspect_name );
		if ( in_array( $aspect_name, array( 'Trine', 'Sextile', 'Conjunction' ), true ) ) {
			return 'harmonious';
		}
		if ( in_array( $aspect_name, array( 'Square', 'Opposition' ), true ) ) {
			return 'challenging';
		}
		return 'neutral';
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — local zodiac lookup
	 * for snapshot writer (English name -> Vietnamese label + symbol).
	 */
	private static function transit_sign_meta_catalog() {
		return array(
			'aries'       => array( 'vi' => 'Bạch Dương', 'symbol' => '♈' ),
			'taurus'      => array( 'vi' => 'Kim Ngưu', 'symbol' => '♉' ),
			'gemini'      => array( 'vi' => 'Song Tử', 'symbol' => '♊' ),
			'cancer'      => array( 'vi' => 'Cự Giải', 'symbol' => '♋' ),
			'leo'         => array( 'vi' => 'Sư Tử', 'symbol' => '♌' ),
			'virgo'       => array( 'vi' => 'Xử Nữ', 'symbol' => '♍' ),
			'libra'       => array( 'vi' => 'Thiên Bình', 'symbol' => '♎' ),
			'scorpio'     => array( 'vi' => 'Bọ Cạp', 'symbol' => '♏' ),
			'sagittarius' => array( 'vi' => 'Nhân Mã', 'symbol' => '♐' ),
			'capricorn'   => array( 'vi' => 'Ma Kết', 'symbol' => '♑' ),
			'aquarius'    => array( 'vi' => 'Bảo Bình', 'symbol' => '♒' ),
			'pisces'      => array( 'vi' => 'Song Ngư', 'symbol' => '♓' ),
		);
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — normalize aspect rows
	 * to legacy-compatible schema used by /my-transit/ templates and FE parsers.
	 */
	private static function normalize_transit_snapshot_aspects( array $aspects, array $positions = array(), array $natal_sign_lookup = array(), $default_source = 'snapshot_normalized_v2' ) {
		if ( empty( $aspects ) ) {
			return array();
		}
		$normalized = array();

		foreach ( $aspects as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$tp = self::canonical_transit_planet_name( (string) ( $row['transit_planet'] ?? $row['transit_point'] ?? $row['planet_1'] ?? $row['p1_name'] ?? $row['p1'] ?? '' ) );
			$np = self::canonical_transit_planet_name( (string) ( $row['natal_planet'] ?? $row['natal_point'] ?? $row['planet_2'] ?? $row['p2_name'] ?? $row['p2'] ?? '' ) );
			$aspect = trim( (string) ( $row['aspect'] ?? $row['type'] ?? $row['type_en'] ?? '' ) );
			if ( $tp === '' || $np === '' || $aspect === '' ) {
				continue;
			}

			$orb = isset( $row['orb'] ) && is_numeric( $row['orb'] ) ? (float) $row['orb'] : 0.0;
			$transit_deg = isset( $row['transit_degree'] ) && is_numeric( $row['transit_degree'] )
				? (float) $row['transit_degree']
				: ( isset( $row['transit_deg'] ) && is_numeric( $row['transit_deg'] )
					? (float) $row['transit_deg']
					: ( isset( $positions[ $tp ]['full_degree'] ) ? (float) $positions[ $tp ]['full_degree'] : 0.0 ) );
			$natal_deg = isset( $row['natal_degree'] ) && is_numeric( $row['natal_degree'] )
				? (float) $row['natal_degree']
				: ( isset( $row['natal_deg'] ) && is_numeric( $row['natal_deg'] ) ? (float) $row['natal_deg'] : 0.0 );

			$transit_sign = '';
			if ( ! empty( $row['transit_sign'] ) ) {
				$transit_sign = (string) $row['transit_sign'];
			} elseif ( isset( $positions[ $tp ]['sign_en'] ) ) {
				$transit_sign = (string) $positions[ $tp ]['sign_en'];
			} else {
				$transit_sign = self::transit_sign_name_from_absolute_degree( $transit_deg );
			}

			$natal_sign = '';
			if ( ! empty( $row['natal_sign'] ) ) {
				$natal_sign = (string) $row['natal_sign'];
			} elseif ( isset( $natal_sign_lookup[ $np ] ) ) {
				$natal_sign = (string) $natal_sign_lookup[ $np ];
			} else {
				$natal_sign = self::transit_sign_name_from_absolute_degree( $natal_deg );
			}

			$forming = isset( $row['forming'] )
				? self::normalize_transit_bool( $row['forming'] )
				: ( isset( $row['applying'] ) ? self::normalize_transit_bool( $row['applying'] ) : false );
			$transit_retro = isset( $row['transit_retro'] )
				? self::normalize_transit_bool( $row['transit_retro'] )
				: ( isset( $positions[ $tp ]['is_retro'] ) ? self::normalize_transit_bool( $positions[ $tp ]['is_retro'] ) : false );
			$is_exact = isset( $row['is_exact'] )
				? self::normalize_transit_bool( $row['is_exact'] )
				: ( $orb <= 1.0 );
			$nature = ! empty( $row['nature'] )
				? (string) $row['nature']
				: self::transit_aspect_nature_from_name( $aspect );

			$normalized[] = array(
				'transit_planet' => $tp,
				'natal_planet'   => $np,
				'natal_point'    => $np,
				'planet_1'       => $tp,
				'planet_2'       => $np,
				'aspect'         => $aspect,
				'type'           => $aspect,
				'angle'          => isset( $row['angle'] ) && is_numeric( $row['angle'] ) ? (float) $row['angle'] : 0.0,
				'orb'            => $orb,
				'applying'       => $forming,
				'forming'        => $forming,
				'is_major'       => in_array( $aspect, array( 'Conjunction', 'Opposition', 'Trine', 'Square', 'Sextile' ), true ),
				'transit_sign'   => $transit_sign,
				'natal_sign'     => $natal_sign,
				'transit_degree' => $transit_deg,
				'natal_degree'   => $natal_deg,
				'transit_retro'  => $transit_retro,
				'is_exact'       => $is_exact,
				'nature'         => $nature,
				'_source'        => isset( $row['_source'] ) ? (string) $row['_source'] : (string) $default_source,
			);
		}

		return $normalized;
	}

	/**
	 * [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — infer zodiac sign name
	 * from absolute degree when sign field is missing in legacy rows.
	 */
	private static function transit_sign_name_from_absolute_degree( $degree ) {
		if ( ! is_numeric( $degree ) ) {
			return '';
		}
		$deg = fmod( (float) $degree, 360.0 );
		if ( $deg < 0 ) {
			$deg += 360.0;
		}
		$signs = array(
			'Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo',
			'Libra', 'Scorpio', 'Sagittarius', 'Capricorn', 'Aquarius', 'Pisces',
		);
		$idx = (int) floor( $deg / 30.0 );
		return isset( $signs[ $idx ] ) ? $signs[ $idx ] : '';
	}

	/**
	 * [2026-07-06 Johnny Chu] HOTFIX — canonicalize mixed planet labels/keys
	 * (e.g. sun, true_node, asc) to English names used by aspect calculator.
	 */
	private static function canonical_transit_planet_name( $name ) {
		$name = trim( (string) $name );
		if ( $name === '' ) {
			return '';
		}

		$_k = strtolower( preg_replace( '/[^a-z0-9]+/', '_', $name ) );
		$_k = trim( $_k, '_' );

		$map = array(
			'sun' => 'Sun',
			'moon' => 'Moon',
			'mercury' => 'Mercury',
			'venus' => 'Venus',
			'mars' => 'Mars',
			'jupiter' => 'Jupiter',
			'saturn' => 'Saturn',
			'uranus' => 'Uranus',
			'neptune' => 'Neptune',
			'pluto' => 'Pluto',
			'asc' => 'Ascendant',
			'ascendant' => 'Ascendant',
			'lagna' => 'Ascendant',
			'mc' => 'MC',
			'ic' => 'IC',
			'desc' => 'Descendant',
			'dsc' => 'Descendant',
			'descendant' => 'Descendant',
			'chiron' => 'Chiron',
			'lilith' => 'Lilith',
			'true_node' => 'True Node',
			'north_node' => 'True Node',
			'rahu' => 'True Node',
			'mean_node' => 'Mean Node',
		);

		if ( isset( $map[ $_k ] ) ) {
			return $map[ $_k ];
		}

		return $name;
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2-FE — Build public transit links for a specific day.
	 *
	 * @return array{day_html:string,day_md:string,day_json:string}
	 */
	private static function build_transit_day_public_urls( $coachee_id, $date ) {
		$coachee_id = (int) $coachee_id;
		$date       = (string) $date;
		if ( $coachee_id <= 0 || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return array( 'day_html' => '', 'day_md' => '', 'day_json' => '' );
		}

		if ( class_exists( 'BizCoach_Pro_Transit_Public_Router' ) ) {
			return array(
				'day_html' => (string) BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'day', array( 'date' => $date ) ),
				'day_md'   => (string) BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'day', array( 'date' => $date, 'format' => 'md' ) ),
				'day_json' => (string) BizCoach_Pro_Transit_Public_Router::get_public_url( $coachee_id, 'day', array( 'date' => $date, 'format' => 'json' ) ),
			);
		}

		$transit_nonce = wp_create_nonce( 'bccm_transit_report' );
		$base_fallback = admin_url( 'admin-ajax.php?action=bccm_transit_report&coachee_id=' . $coachee_id . '&period=day&date=' . rawurlencode( $date ) . '&_wpnonce=' . $transit_nonce );
		return array(
			'day_html' => $base_fallback,
			'day_md'   => $base_fallback,
			'day_json' => $base_fallback,
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2-FE — Resolve latest local wheel SVG URL.
	 */
	private static function resolve_western_wheel_svg_url( $coachee_id ) {
		global $wpdb;
		$coachee_id = (int) $coachee_id;
		if ( $coachee_id <= 0 ) {
			return '';
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT chart_svg, summary, traits FROM {$wpdb->prefix}bccm_astro WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! $row ) {
			return '';
		}

		$candidates = array();
		if ( ! empty( $row['chart_svg'] ) && is_string( $row['chart_svg'] ) ) {
			$candidates[] = (string) $row['chart_svg'];
		}
		$summary = ! empty( $row['summary'] ) ? json_decode( (string) $row['summary'], true ) : array();
		$traits  = ! empty( $row['traits'] )  ? json_decode( (string) $row['traits'], true )  : array();
		foreach ( array( 'transit_chart_url', 'chart_url', 'wheel_svg_url' ) as $k ) {
			if ( is_array( $summary ) && ! empty( $summary[ $k ] ) && is_string( $summary[ $k ] ) ) {
				$candidates[] = (string) $summary[ $k ];
			}
			if ( is_array( $traits ) && ! empty( $traits[ $k ] ) && is_string( $traits[ $k ] ) ) {
				$candidates[] = (string) $traits[ $k ];
			}
		}

		foreach ( $candidates as $url ) {
			if ( $url !== '' && preg_match( '/\.svg(\?|$)/i', $url ) ) {
				return $url;
			}
		}
		return isset( $candidates[0] ) ? (string) $candidates[0] : '';
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2-FE — Generate transit bi-wheel SVG URL for selected date.
	 */
	private static function generate_transit_day_wheel_svg_url( array $payload, $coachee_id, $date ) {
		if ( ! class_exists( 'BizCity_Astro_Router' ) ) {
			return '';
		}
		$provider = BizCity_Astro_Router::get_provider( 'faa_western' );
		if ( ! $provider || ! method_exists( $provider, 'is_ready' ) || ! $provider->is_ready() || ! method_exists( $provider, 'transit_chart_svg' ) ) {
			return '';
		}

		$input = $payload;
		$input['format']       = 'svg';
		$input['theme_type']   = 'light';
		$input['size']         = 860;
		$input['transit_date'] = $date . 'T12:00';
		$out = $provider->transit_chart_svg( $input );
		if ( ! is_array( $out ) || empty( $out['success'] ) || empty( $out['svg'] ) ) {
			return '';
		}

		if ( function_exists( 'bccm_astro_save_svg_file' ) ) {
			$saved = bccm_astro_save_svg_file( (int) $coachee_id, 'transit_day', (string) $out['svg'] );
			if ( ! is_wp_error( $saved ) && is_string( $saved ) && $saved !== '' ) {
				return $saved;
			}
		}

		return '';
	}

	/* ------------------------------------------------------------------ *
	 * GET /me/moon-phase
	 *
	 * [2026-06-05 Johnny Chu] PHASE-A A-FE-1
	 * Proxy BizCoach_Pro_Astro_Client::moon_phase() — fail-OPEN.
	 * ------------------------------------------------------------------ */
	public static function get_moon_phase( $request ) {
		$tz = sanitize_text_field( (string) ( $request->get_param( 'tz' ) ?? '' ) );

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true, 'message' => 'Astro client chưa load.' ) );
		}

		// [2026-06-07 Johnny Chu] HOTFIX — BizCoach_Pro_Astro_Client is all-static, no instance().
		// [2026-06-08 Johnny Chu] HOTFIX — (a) gateway expects 'tz_str' not 'tz'; (b) add
		// include_zodiac=true so FAA returns moon/sun longitude for degreesBetweenSunMoon;
		// (c) unwrap $result['envelope']['raw'] so FE reads phase_name/illumination at top-level.
		$params = array( 'include_zodiac' => 'true' );
		if ( $tz !== '' ) {
			$params['tz_str'] = $tz;
		}
		$result = BizCoach_Pro_Astro_Client::moon_phase( $params );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true, 'message' => $result->get_error_message() ) );
		}

		if ( ! empty( $result['success'] ) ) {
			$envelope = is_array( $result['envelope'] ?? null ) ? $result['envelope'] : array();
			// [2026-06-08 Johnny Chu] HOTFIX — gateway wraps FAA response in envelope['raw'].
			// Flatten raw into top level so FE reads phase_name/illumination/moon_longitude directly.
			if ( isset( $envelope['raw'] ) && is_array( $envelope['raw'] ) ) {
				$envelope = array_merge( $envelope['raw'], $envelope );
			}
			$envelope['success'] = true;
			return rest_ensure_response( $envelope );
		}

		return rest_ensure_response( array(
			'success'   => false,
			'_degraded' => true,
			'message'   => $result['error'] ?? 'gateway_error',
		) );
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-1 — GET /geo/search
	 *
	 * Proxy → BizCoach_Pro_Astro_Client::geo_search → gateway → freeastroapi.
	 * Fail-OPEN: returns 200 + { geonames: [], _degraded: true } on any
	 * gateway issue so FE autocomplete simply shows "no results".
	 * ================================================================== */
	public static function geo_search( $request ) {
		$q       = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$country = sanitize_text_field( (string) ( $request->get_param( 'country' ) ?? '' ) );
		$limit   = max( 1, min( 25, (int) ( $request->get_param( 'limit' ) ?? 10 ) ) );

		if ( strlen( $q ) < 2 ) {
			return rest_ensure_response( array( 'geonames' => array() ) );
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array(
				'geonames'  => array(),
				'_degraded' => true,
				'message'   => 'Astro client chưa load.',
			) );
		}

		$params = array( 'q' => $q, 'limit' => $limit );
		if ( $country !== '' ) { $params['country'] = $country; }

		$resp = BizCoach_Pro_Astro_Client::geo_search( $params );

		if ( is_wp_error( $resp ) || empty( $resp['success'] ) ) {
			return rest_ensure_response( array(
				'geonames'  => array(),
				'_degraded' => true,
				'message'   => is_wp_error( $resp )
					? $resp->get_error_message()
					: ( is_array( $resp ) && isset( $resp['error'] ) ? (string) $resp['error'] : 'Gateway lỗi.' ),
			) );
		}

		$env = isset( $resp['envelope'] ) && is_array( $resp['envelope'] ) ? $resp['envelope'] : array();
		// Gateway returns either:
		//   GeoNames format: { geonames: [{geonameId, name, lat, lng, ...}] }
		//   FAA2 format:     array of { location_name, latitude, longitude, timezone, timezone_offset, ... }
		//   Generic:         { data: [...] } or { results: [...] }
		$rows = array();
		if ( isset( $env['geonames'] ) && is_array( $env['geonames'] ) ) {
			$rows = $env['geonames'];
		} elseif ( isset( $env['data'] ) && is_array( $env['data'] ) ) {
			$rows = $env['data'];
		} elseif ( isset( $env['results'] ) && is_array( $env['results'] ) ) {
			$rows = $env['results'];
		} elseif ( is_array( $env ) && isset( $env[0] ) ) {
			// FAA2 returns a bare array at top-level
			$rows = $env;
		}

		// [2026-07-03 Johnny Chu] PHASE-FAA2-FE — normalize FAA2 /geo-details items so FE
		// GeoResult interface is satisfied. FAA2 items have 'location_name' instead of 'name',
		// and 'timezone' (IANA string) instead of {timeZoneId}.
		$normalized = array();
		foreach ( (array) $rows as $item ) {
			if ( ! is_array( $item ) ) { continue; }
			// Detect FAA2 shape by presence of 'location_name'
			if ( isset( $item['location_name'] ) ) {
				$normalized[] = array(
					'name'          => (string) ( $item['location_name'] ?? '' ),
					'complete_name' => (string) ( $item['complete_name'] ?? $item['location_name'] ?? '' ),
					'lat'           => (float)  ( $item['latitude']      ?? 0 ),
					'lng'           => (float)  ( $item['longitude']     ?? 0 ),
					'latitude'      => (float)  ( $item['latitude']      ?? 0 ),
					'longitude'     => (float)  ( $item['longitude']     ?? 0 ),
					'timezone_iana' => (string) ( $item['timezone']      ?? '' ),
					'timezone_offset' => (float) ( $item['timezone_offset'] ?? 0 ),
					'countryName'   => (string) ( $item['country']              ?? '' ),
					'country'       => (string) ( $item['country']              ?? '' ),
					'adminName1'    => (string) ( $item['administrative_zone_1'] ?? '' ),
				);
			} else {
				// Legacy geonames shape — pass through unchanged
				$normalized[] = $item;
			}
		}

		// Return both keys for FE compat: 'geonames' (legacy) and 'results' (new)
		return rest_ensure_response( array(
			'results'  => array_values( $normalized ),
			'geonames' => array_values( $normalized ),
		) );
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-6 — GET /me/moon-month
	 *
	 * Wraps BizCoach_Pro_Astro_Client::moon_month — full calendar grid.
	 * ================================================================== */
	public static function get_moon_month( $request ) {
		$year  = (int) ( $request->get_param( 'year' )  ?? (int) current_time( 'Y' ) );
		$month = (int) ( $request->get_param( 'month' ) ?? (int) current_time( 'n' ) );
		$tz    = sanitize_text_field( (string) ( $request->get_param( 'tz' ) ?? '' ) );

		$year  = max( 1900, min( 2100, $year ) );
		$month = max( 1, min( 12, $month ) );

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true,
				'message' => 'Astro client chưa load.' ) );
		}

		$params = array( 'year' => $year, 'month' => $month );
		if ( $tz !== '' ) { $params['tz'] = $tz; }

		$resp = BizCoach_Pro_Astro_Client::moon_month( $params );
		if ( is_wp_error( $resp ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true,
				'message' => $resp->get_error_message() ) );
		}
		if ( empty( $resp['success'] ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true,
				'message' => is_array( $resp ) && isset( $resp['error'] ) ? (string) $resp['error'] : 'Gateway lỗi.' ) );
		}

		$env = isset( $resp['envelope'] ) ? $resp['envelope'] : array();
		return rest_ensure_response( array(
			'success' => true,
			'year'    => $year,
			'month'   => $month,
			'days'    => isset( $env['days'] ) ? $env['days'] : ( $env['data'] ?? array() ),
		) );
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-3 — GET /me/saved-calculations
	 *
	 * Lists rows from bccm_transit_snapshots scoped to current user via
	 * JOIN on bccm_coachees.user_id. Optional filter: ?coachee_id=.
	 * Paginated: ?limit=20 ?offset=0.
	 * ================================================================== */
	public static function list_saved_calculations( $request ) {
		global $wpdb;
		$uid    = (int) get_current_user_id();
		$cid    = (int) ( $request->get_param( 'coachee_id' ) ?? 0 );
		$limit  = max( 1, min( 50, (int) ( $request->get_param( 'limit' )  ?? 20 ) ) );
		$offset = max( 0, (int) ( $request->get_param( 'offset' ) ?? 0 ) );
		// [2026-07-10 Johnny Chu] PHASE-FAA2 — optional explain payload for FE trace modal.
		$include_details = (bool) $request->get_param( 'include_details' );

		$t_snap  = $wpdb->prefix . 'bccm_transit_snapshots';
		$t_coach = $wpdb->prefix . 'bccm_coachees';

		$where  = 's.user_id = %d';
		$params = array( $uid );
		if ( $cid > 0 ) {
			$where   .= ' AND s.coachee_id = %d';
			$params[] = $cid;
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t_snap} s WHERE {$where}",
			$params
		) );

		$params_q   = $params;
		$params_q[] = $limit;
		$params_q[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT s.id, s.coachee_id, s.target_date, s.label,
			        s.source_marker, s.planets_json, s.aspects_json, s.fetched_at,
			        c.full_name
			 FROM {$t_snap} s
			 INNER JOIN {$t_coach} c ON c.id = s.coachee_id AND c.user_id = s.user_id
			 WHERE {$where}
			 ORDER BY s.target_date DESC, s.id DESC
			 LIMIT %d OFFSET %d",
			$params_q
		), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $r ) {
			$planets = $r['planets_json'] ? json_decode( (string) $r['planets_json'], true ) : array();
			$aspects = $r['aspects_json'] ? json_decode( (string) $r['aspects_json'], true ) : array();
			$items[] = array(
				'id'             => (int) $r['id'],
				'coachee_id'     => (int) $r['coachee_id'],
				'coachee_name'   => (string) ( $r['full_name'] ?? '' ),
				'target_date'    => (string) $r['target_date'],
				'label'          => (string) ( $r['label'] ?? '' ),
				'source_marker'  => (string) ( $r['source_marker'] ?? '' ),
				'planets_count'  => is_array( $planets ) ? count( $planets ) : 0,
				'aspects_count'  => is_array( $aspects ) ? count( $aspects ) : 0,
				'fetched_at'     => (string) ( $r['fetched_at'] ?? '' ),
			);
			if ( $include_details ) {
				$items[ count( $items ) - 1 ]['snapshot_json'] = array(
					'id'            => (int) $r['id'],
					'coachee_id'    => (int) $r['coachee_id'],
					'coachee_name'  => (string) ( $r['full_name'] ?? '' ),
					'target_date'   => (string) $r['target_date'],
					'label'         => (string) ( $r['label'] ?? '' ),
					'source_marker' => (string) ( $r['source_marker'] ?? '' ),
					'fetched_at'    => (string) ( $r['fetched_at'] ?? '' ),
					'planets'       => is_array( $planets ) ? $planets : array(),
					'aspects'       => is_array( $aspects ) ? $aspects : array(),
				);
			}
		}

		return rest_ensure_response( array(
			'success' => true,
			'total'   => $total,
			'limit'   => $limit,
			'offset'  => $offset,
			'items'   => $items,
		) );
	}

	/* ================================================================== *
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — GET /me/transit-logs
	 *
	 * Reads transit save history from channel-style JSONL log files:
	 * uploads/.../bizcity-channel-logs/astro/YYYY-MM-DD.jsonl
	 * Includes manual + rebuild + cron rows emitted by do_transit_fetch().
	 * ================================================================== */
	public static function list_transit_logs( $request ) {
		$uid      = (int) get_current_user_id();
		$is_admin = current_user_can( 'manage_options' );

		$coachee_id = (int) ( $request->get_param( 'coachee_id' ) ?? 0 );
		$source     = sanitize_key( (string) ( $request->get_param( 'source' ) ?? '' ) );
		$status     = sanitize_key( (string) ( $request->get_param( 'status' ) ?? '' ) );
		$date       = sanitize_text_field( (string) ( $request->get_param( 'date' ) ?? '' ) );
		$date_from  = sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?? '' ) );
		$date_to    = sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?? '' ) );
		$days       = max( 1, min( 180, (int) ( $request->get_param( 'days' ) ?? 30 ) ) );
		$limit      = max( 1, min( 100, (int) ( $request->get_param( 'limit' ) ?? 50 ) ) );
		$offset     = max( 0, (int) ( $request->get_param( 'offset' ) ?? 0 ) );
		// [2026-07-10 Johnny Chu] PHASE-FAA2 — optional explain payload for FE trace modal.
		$include_details = (bool) $request->get_param( 'include_details' );

		if ( $source === 'all' ) {
			$source = '';
		}
		if ( ! in_array( $status, array( '', 'success', 'failed' ), true ) ) {
			$status = '';
		}

		if ( $date !== '' ) {
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return new WP_Error( 'invalid_param', 'Tham số date phải theo định dạng YYYY-MM-DD.', array( 'status' => 400 ) );
			}
			$date_from = $date;
			$date_to   = $date;
		} else {
			if ( $date_to === '' ) {
				$date_to = current_time( 'Y-m-d' );
			}
			if ( $date_from === '' ) {
				$date_from = gmdate( 'Y-m-d', strtotime( $date_to . ' -' . ( $days - 1 ) . ' days' ) );
			}
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			return new WP_Error( 'invalid_param', 'date_from/date_to phải theo định dạng YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		if ( $date_from > $date_to ) {
			$_tmp      = $date_from;
			$date_from = $date_to;
			$date_to   = $_tmp;
		}

		$log_dir = self::get_transit_log_directory();
		if ( $log_dir === '' || ! is_dir( $log_dir ) ) {
			return rest_ensure_response( array(
				'success'    => true,
				'_degraded'  => true,
				'message'    => 'Transit log directory chưa tồn tại.',
				'total'      => 0,
				'limit'      => $limit,
				'offset'     => $offset,
				'date_from'  => $date_from,
				'date_to'    => $date_to,
				'items'      => array(),
			) );
		}

		$files = self::list_transit_log_files( $log_dir, $date_from, $date_to );
		$all   = array();

		foreach ( $files as $file_path ) {
			$lines = @file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! is_array( $lines ) || empty( $lines ) ) {
				continue;
			}
			for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
				$raw = self::decode_transit_log_line( $lines[ $i ] );
				if ( ! is_array( $raw ) || ! self::is_transit_snapshot_log_row( $raw ) ) {
					continue;
				}

				$ctx          = isset( $raw['ctx'] ) && is_array( $raw['ctx'] ) ? $raw['ctx'] : array();
				$entry_uid    = isset( $ctx['user_id'] ) ? (int) $ctx['user_id'] : 0;
				$entry_cid    = isset( $ctx['coachee_id'] ) ? (int) $ctx['coachee_id'] : 0;
				$entry_source = sanitize_key( (string) ( $ctx['save_source'] ?? '' ) );
				$entry_status = sanitize_key( (string) ( $ctx['status'] ?? '' ) );

				if ( ! $is_admin && $entry_uid !== $uid ) {
					continue;
				}
				if ( $coachee_id > 0 && $entry_cid !== $coachee_id ) {
					continue;
				}
				if ( $source !== '' && $entry_source !== $source ) {
					continue;
				}
				if ( $status !== '' && $entry_status !== $status ) {
					continue;
				}

				$target_date = isset( $ctx['target_date'] ) ? (string) $ctx['target_date'] : '';
				if ( $target_date === '' && ! empty( $raw['ts'] ) ) {
					$target_date = substr( (string) $raw['ts'], 0, 10 );
				}
				if ( $target_date !== '' && ( $target_date < $date_from || $target_date > $date_to ) ) {
					continue;
				}

				$all[] = array(
					'timestamp'     => isset( $raw['ts'] ) ? (string) $raw['ts'] : '',
					'target_date'   => $target_date,
					'source'        => $entry_source,
					'status'        => $entry_status,
					'coachee_id'    => $entry_cid,
					'user_id'       => $entry_uid,
					'period'        => isset( $ctx['period'] ) ? (string) $ctx['period'] : '',
					'provider'      => isset( $ctx['provider'] ) ? (string) $ctx['provider'] : '',
					'fetch_path'    => isset( $ctx['fetch_path'] ) ? (string) $ctx['fetch_path'] : '',
					'planets_count' => isset( $ctx['planets_count'] ) ? (int) $ctx['planets_count'] : 0,
					'aspects_count' => isset( $ctx['aspects_count'] ) ? (int) $ctx['aspects_count'] : 0,
					'http_status'   => isset( $ctx['http_status'] ) ? (int) $ctx['http_status'] : 0,
					'transport'     => isset( $ctx['transport'] ) ? (string) $ctx['transport'] : '',
					'message'       => isset( $ctx['message'] ) ? (string) $ctx['message'] : ( isset( $raw['msg'] ) ? (string) $raw['msg'] : '' ),
				);
				if ( $include_details ) {
					$all[ count( $all ) - 1 ]['log_json'] = array(
						'timestamp'     => isset( $raw['ts'] ) ? (string) $raw['ts'] : '',
						'event'         => isset( $raw['event'] ) ? (string) $raw['event'] : '',
						'level'         => isset( $raw['level'] ) ? (string) $raw['level'] : '',
						'channel'       => isset( $raw['channel'] ) ? (string) $raw['channel'] : 'astro',
						'status'        => $entry_status,
						'source'        => $entry_source,
						'coachee_id'    => $entry_cid,
						'user_id'       => $entry_uid,
						'target_date'   => $target_date,
						'period'        => isset( $ctx['period'] ) ? (string) $ctx['period'] : '',
						'provider'      => isset( $ctx['provider'] ) ? (string) $ctx['provider'] : '',
						'fetch_path'    => isset( $ctx['fetch_path'] ) ? (string) $ctx['fetch_path'] : '',
						'planets_count' => isset( $ctx['planets_count'] ) ? (int) $ctx['planets_count'] : 0,
						'aspects_count' => isset( $ctx['aspects_count'] ) ? (int) $ctx['aspects_count'] : 0,
						'http_status'   => isset( $ctx['http_status'] ) ? (int) $ctx['http_status'] : 0,
						'transport'     => isset( $ctx['transport'] ) ? (string) $ctx['transport'] : '',
						'message'       => isset( $ctx['message'] ) ? (string) $ctx['message'] : ( isset( $raw['msg'] ) ? (string) $raw['msg'] : '' ),
					);
				}
			}
		}

		$total = count( $all );
		$items = array_slice( $all, $offset, $limit );

		return rest_ensure_response( array(
			'success'    => true,
			'total'      => $total,
			'limit'      => $limit,
			'offset'     => $offset,
			'date_from'  => $date_from,
			'date_to'    => $date_to,
			'items'      => $items,
		) );
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-4 — DELETE /me/saved-calculations/{id}
	 *
	 * IDOR-safe: verifies ownership via JOIN on bccm_coachees.user_id,
	 * not just snapshot.id.
	 * ================================================================== */
	public static function delete_saved_calculation( $request ) {
		global $wpdb;
		$uid     = (int) get_current_user_id();
		$snap_id = (int) $request->get_param( 'id' );
		$t_snap  = $wpdb->prefix . 'bccm_transit_snapshots';
		$t_coach = $wpdb->prefix . 'bccm_coachees';

		$owned = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT s.id FROM {$t_snap} s
			 INNER JOIN {$t_coach} c ON c.id = s.coachee_id AND c.user_id = %d
			 WHERE s.id = %d
			 LIMIT 1",
			$uid, $snap_id
		) );

		if ( ! $owned ) {
			return new WP_Error( 'not_found', 'Bản ghi không tồn tại hoặc không thuộc về bạn.',
				array( 'status' => 404 ) );
		}

		$wpdb->delete( $t_snap, array( 'id' => $snap_id ) );
		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — resolve astro JSONL log directory.
	 *
	 * @return string
	 */
	private static function get_transit_log_directory() {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return '';
		}
		$uploads = wp_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( $base === '' ) {
			return '';
		}
		return trailingslashit( $base ) . 'bizcity-channel-logs/astro';
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — list daily JSONL files in descending date.
	 *
	 * @param string $log_dir
	 * @param string $date_from
	 * @param string $date_to
	 * @return array
	 */
	private static function list_transit_log_files( $log_dir, $date_from, $date_to ) {
		$files = glob( trailingslashit( $log_dir ) . '*.jsonl' );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return array();
		}

		rsort( $files, SORT_STRING );
		$out = array();
		foreach ( $files as $path ) {
			$base = basename( (string) $path, '.jsonl' );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $base ) ) {
				continue;
			}
			if ( $base < $date_from || $base > $date_to ) {
				continue;
			}
			$out[] = (string) $path;
		}
		return $out;
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — decode one JSONL row safely.
	 *
	 * @param string $line
	 * @return array|null
	 */
	private static function decode_transit_log_line( $line ) {
		$line = trim( (string) $line );
		if ( $line === '' ) {
			return null;
		}
		$decoded = json_decode( $line, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		if ( ! isset( $decoded['ctx'] ) || ! is_array( $decoded['ctx'] ) ) {
			$decoded['ctx'] = array();
		}
		return $decoded;
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — only keep transit save history rows.
	 *
	 * @param array $row
	 * @return bool
	 */
	private static function is_transit_snapshot_log_row( array $row ) {
		$event = sanitize_key( (string) ( $row['event'] ?? '' ) );
		if ( $event === 'transit_snapshot_saved' || $event === 'transit_snapshot_save_failed' ) {
			return true;
		}
		$ctx = isset( $row['ctx'] ) && is_array( $row['ctx'] ) ? $row['ctx'] : array();
		return isset( $ctx['kind'] ) && (string) $ctx['kind'] === 'transit_snapshot_save';
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-2 — birth coords helpers
	 * ================================================================== */

	/**
	 * Pull birth_lat/birth_lng/birth_tz from request → normalised array or null.
	 *
	 * @param WP_REST_Request $request
	 * @return array|null { lat: float, lng: float, tz: string, place_label: string, geoname_id: int }
	 */
	private static function collect_birth_coords( $request ) {
		$lat = $request->get_param( 'birth_lat' );
		$lng = $request->get_param( 'birth_lng' );
		$tz  = $request->get_param( 'birth_tz' );
		$gid = $request->get_param( 'geoname_id' );
		$lbl = $request->get_param( 'birth_place' );

		// Need at least lat+lng or tz to bother persisting.
		$has_coords = is_numeric( $lat ) && is_numeric( $lng );
		$has_tz     = is_string( $tz )  && $tz !== '';
		if ( ! $has_coords && ! $has_tz ) {
			return null;
		}

		$out = array();
		if ( $has_coords ) {
			$lat_f = (float) $lat;
			$lng_f = (float) $lng;
			if ( $lat_f >= -90 && $lat_f <= 90 && $lng_f >= -180 && $lng_f <= 180 ) {
				$out['lat'] = $lat_f;
				$out['lng'] = $lng_f;
			}
		}
		if ( $has_tz ) {
			// IANA tz validation: basic charset + slash pattern.
			$tz_s = sanitize_text_field( (string) $tz );
			if ( preg_match( '~^[A-Za-z][A-Za-z0-9_+/\-]+$~', $tz_s ) ) {
				$out['tz'] = $tz_s;
			}
		}
		if ( is_string( $lbl ) && $lbl !== '' ) {
			$out['place_label'] = sanitize_text_field( $lbl );
		}
		if ( is_numeric( $gid ) ) {
			$out['geoname_id'] = (int) $gid;
		}

		return empty( $out ) ? null : $out;
	}

	/**
	 * Decode birth_coords block out of extra_fields_json. Safe default.
	 *
	 * @param string $extra_json Raw JSON from bccm_coachees.extra_fields_json.
	 * @return array { lat?: float, lng?: float, tz?: string }
	 */
	private static function read_birth_coords( $extra_json ) {
		if ( $extra_json === '' ) { return array(); }
		$decoded = json_decode( $extra_json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['birth_coords'] ) || ! is_array( $decoded['birth_coords'] ) ) {
			return array();
		}
		return $decoded['birth_coords'];
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-8 — GET /me/quota
	 *
	 * Proxies BizCoach_Pro_Astro_Client::quota() → gateway /astrology/quota.
	 * Returns daily quota state. Fail-OPEN: returns _degraded on error.
	 * ================================================================== */
	public static function get_quota( $request ) {
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Astro client chưa load.',
			) );
		}

		$resp = BizCoach_Pro_Astro_Client::quota();
		if ( is_wp_error( $resp ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => $resp->get_error_message(),
			) );
		}
		if ( empty( $resp['success'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => is_array( $resp ) && isset( $resp['error'] ) ? (string) $resp['error'] : 'Gateway lỗi.',
			) );
		}

		$env = isset( $resp['envelope'] ) ? $resp['envelope'] : array();
		return rest_ensure_response( array_merge(
			array( 'success' => true ),
			is_array( $env ) ? $env : array( 'data' => $env )
		) );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — GET /me/entitlement
	 *
	 * Same-origin proxy to hub entitlement so FE can drive feature gates and
	 * PRO badge state from real permissions (tier + features).
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function get_entitlement( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( array(
				'success'   => false,
				'code'      => 'auth_required',
				'message'   => 'Vui lòng đăng nhập để xem quyền truy cập.',
				'hint'      => 'Đăng nhập rồi thử lại.',
				'help_code' => 'auth_required',
			) );
		}

		$fallback = array(
			'success'   => true,
			'tier'      => 'free',
			'features'  => array(),
			'_degraded' => true,
			'message'   => 'Không đọc được entitlement từ hub; dùng mặc định free.',
		);

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return rest_ensure_response( $fallback );
		}

		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm || ! method_exists( $llm, 'get_entitlement' ) ) {
			return rest_ensure_response( $fallback );
		}

		$ent = $llm->get_entitlement( $uid, array( 'timeout' => 8 ) );
		if ( is_wp_error( $ent ) || ! is_array( $ent ) ) {
			$fallback['message'] = is_wp_error( $ent ) ? $ent->get_error_message() : $fallback['message'];
			return rest_ensure_response( $fallback );
		}

		$tier = sanitize_key( (string) ( $ent['tier'] ?? 'free' ) );
		if ( ! in_array( $tier, array( 'free', 'paid', 'enterprise' ), true ) ) {
			$tier = 'free';
		}

		$features_in = isset( $ent['features'] ) && is_array( $ent['features'] ) ? $ent['features'] : array();
		$features    = array();
		foreach ( $features_in as $fkey => $fval ) {
			if ( is_string( $fkey ) && $fkey !== '' ) {
				$features[ self::normalize_feature_key( $fkey ) ] = true;
				continue;
			}
			if ( is_string( $fval ) && $fval !== '' ) {
				$features[ self::normalize_feature_key( $fval ) ] = true;
			}
		}

		return rest_ensure_response( array(
			'success'   => true,
			'tier'      => $tier,
			'features'  => $features,
			'_degraded' => ! empty( $ent['_degraded'] ),
			'cached'    => ! empty( $ent['cached'] ),
			'message'   => isset( $ent['message'] ) ? sanitize_text_field( (string) $ent['message'] ) : '',
		) );
	}

	/* ================================================================== *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-9 — POST /me/profiles/{id}/sync-transit
	 *
	 * Manual single-profile transit fetch (used by FE "Sync Transit" button).
	 * Fetches today + tomorrow for period=day, persists snapshot, returns
	 * success + last_synced timestamp. Fail-OPEN. (R-CRON-META note not
	 * called here — this is a REST action, not cron context.)
	 * ================================================================== */
	public static function sync_transit( $request ) {
		global $wpdb;
		$coachee_id = (int) $request->get_param( 'id' );
		$uid        = (int) get_current_user_id();

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array( 'success' => false, '_degraded' => true,
				'message' => 'Astro client chưa load.' ) );
		}

		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — admin can rebuild any coachee,
		// including migrated records with user_id=0.
		if ( current_user_can( 'manage_options' ) ) {
			$coachee = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t_coach} WHERE id = %d LIMIT 1",
				$coachee_id
			), ARRAY_A );
		} else {
			$coachee = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$t_coach} WHERE id = %d AND user_id = %d LIMIT 1",
				$coachee_id, $uid
			), ARRAY_A );
		}
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}
		$natal = $wpdb->get_row( $wpdb->prepare(
			"SELECT birth_time, birth_place FROM {$t_astro}
			 WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! $natal ) {
			return rest_ensure_response( array( 'success' => false,
				'message' => 'Chưa sinh bản đồ sao Western — cần sinh bản đồ trước.' ) );
		}

		// [2026-07-10 Johnny Chu] PHASE-FAA2 — mark Sync Transit button flow in history.
		$result = self::do_transit_fetch( $coachee, $natal, current_time( 'Y-m-d' ), 'day', 'manual_sync' );
		if ( ! $result['success'] ) {
			return rest_ensure_response( $result + array( '_degraded' => true ) );
		}

		return rest_ensure_response( array(
			'success'     => true,
			'target_date' => $result['target_date'],
			'period'      => 'day',
			'planets'     => $result['planets'],
			'last_synced' => current_time( 'mysql' ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-06 Johnny Chu] PHASE-B B-BE-9 — shared transit fetch helper
	 * (used by both sync_transit REST and BizCoach_Pro_Transit_Cron).
	 * Returns array with success, target_date, planets, aspects keys.
	 * ------------------------------------------------------------------ */
	// [2026-07-10 Johnny Chu] PHASE-FAA2 — add optional $save_source to classify manual/rebuild/cron writes.
	public static function do_transit_fetch( array $coachee, array $natal, string $date, string $period, string $save_source = '' ) {
		global $wpdb;
		$coachee_id = (int) $coachee['id'];
		$uid        = (int) $coachee['user_id'];
		// [2026-07-10 Johnny Chu] PHASE-FAA2 — normalize source so manual/rebuild/cron history can be queried.
		$save_source = self::resolve_transit_save_source( $save_source );
		$coords     = self::read_birth_coords( (string) ( $coachee['extra_fields_json'] ?? '' ) );

		// [2026-06-08 Johnny Chu] HOTFIX — same as get_transit(): FAA expects flat
		// natal{year,month,day,hour,minute,lat,lng,tz_str,city} block, NOT subject{}.
		// Old payload caused 422 → success=false → snapshot never persisted.
		$dob_parts  = explode( '-', (string) ( $coachee['dob'] ?? '' ) );
		$dob_year   = isset( $dob_parts[0] ) ? (int) $dob_parts[0] : 0;
		$dob_month  = isset( $dob_parts[1] ) ? (int) $dob_parts[1] : 0;
		$dob_day    = isset( $dob_parts[2] ) ? (int) $dob_parts[2] : 0;

		$bt_str     = (string) ( $natal['birth_time'] ?? '' );
		$time_known = ( $bt_str !== '' );
		if ( ! $time_known ) { $bt_str = '12:00'; }
		$bt_parts   = explode( ':', $bt_str );
		$bt_hour    = isset( $bt_parts[0] ) ? (int) $bt_parts[0] : 12;
		$bt_min     = isset( $bt_parts[1] ) ? (int) $bt_parts[1] : 0;

		$birth_lat   = isset( $coords['lat'] ) ? (float) $coords['lat'] : 21.0285;
		$birth_lng   = isset( $coords['lng'] ) ? (float) $coords['lng'] : 105.8542;
		$birth_tz    = isset( $coords['tz'] )  ? (string) $coords['tz'] : 'Asia/Ho_Chi_Minh';
		$birth_place = (string) ( $natal['birth_place'] ?? '' );
		if ( $birth_place === '' ) { $birth_place = 'Hanoi'; }

		$payload = array(
			'natal' => array(
				'name'         => (string) $coachee['full_name'],
				'year'         => $dob_year,
				'month'        => $dob_month,
				'day'          => $dob_day,
				'hour'         => $bt_hour,
				'minute'       => $bt_min,
				'time_known'   => $time_known,
				'lat'          => $birth_lat,
				'lng'          => $birth_lng,
				'tz_str'       => $birth_tz,
				'city'         => $birth_place,
				// [2026-06-08 Johnny Chu] HOTFIX — FAA wants 'placidus' canonical, not 'P'.
				'house_system' => 'placidus',
				'zodiac_type'  => 'tropical',
			),
			'transit_date' => $date . 'T12:00',
			'tz_str'       => $birth_tz,
			'current_city' => $birth_place,
			'current_lat'  => $birth_lat,
			'current_lng'  => $birth_lng,
			'orb_settings' => array(
				'Conjunction' => 8.0, 'Opposition' => 8.0,
				'Trine'       => 6.0, 'Square'     => 6.0, 'Sextile' => 4.0,
			),
		);

		// [2026-06-29 Johnny Chu] PHASE-ASTRO-MIGRATE — FAA2 Transit Snapshot (early-return path)
		// Try faa2_western (freeastrologyapi.com) FIRST — geocentric noon + PHP aspect calculator.
		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — call FAA2 even when natal_planets empty:
		// transit_with_aspects() handles empty natal gracefully (returns planets + aspects=[]).
		// Natal traits are OPTIONAL for transit planet positions; required only for aspects.
		if ( class_exists( 'BizCity_Astro_Router' ) && class_exists( 'Astro_Provider_FAA2_Western' ) ) {
			$_faa2_prov = BizCity_Astro_Router::get_provider( 'faa2_western' );
			if ( $_faa2_prov && method_exists( $_faa2_prov, 'is_ready' ) && $_faa2_prov->is_ready() ) {
				$_t_astro_q    = $wpdb->prefix . 'bccm_astro';
				// [2026-07-06 Johnny Chu] HOTFIX — use western traits row + schema fallback (positions/planets)
				// so transit aspects do not go empty when traits.planets is missing.
				$_traits_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT traits FROM {$_t_astro_q} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
					$coachee_id
				), ARRAY_A );
				if ( ! $_traits_row ) {
					// Back-compat fallback for legacy rows missing chart_type.
					$_traits_row = $wpdb->get_row( $wpdb->prepare(
						"SELECT traits FROM {$_t_astro_q} WHERE coachee_id = %d ORDER BY id DESC LIMIT 1",
						$coachee_id
					), ARRAY_A );
				}
				$_traits_raw = $_traits_row && isset( $_traits_row['traits'] )
					? (string) $_traits_row['traits']
					: '';
				$_natal_pl = array();
				$_natal_src = 'none';
				$_natal_raw_count = 0;
				$_natal_norm_count = 0;
				if ( $_traits_raw ) {
					$_tr_dec = json_decode( $_traits_raw, true );
					if ( is_array( $_tr_dec ) ) {
						if ( isset( $_tr_dec['positions'] ) && is_array( $_tr_dec['positions'] ) && ! empty( $_tr_dec['positions'] ) ) {
							$_natal_pl = (array) $_tr_dec['positions'];
							$_natal_src = 'traits.positions';
						} elseif ( isset( $_tr_dec['planets'] ) && is_array( $_tr_dec['planets'] ) && ! empty( $_tr_dec['planets'] ) ) {
							$_natal_pl = (array) $_tr_dec['planets'];
							$_natal_src = 'traits.planets';
						} elseif ( isset( $_tr_dec['natal'] ) && is_array( $_tr_dec['natal'] ) ) {
							if ( isset( $_tr_dec['natal']['positions'] ) && is_array( $_tr_dec['natal']['positions'] ) && ! empty( $_tr_dec['natal']['positions'] ) ) {
								$_natal_pl = (array) $_tr_dec['natal']['positions'];
								$_natal_src = 'traits.natal.positions';
							} elseif ( isset( $_tr_dec['natal']['planets'] ) && is_array( $_tr_dec['natal']['planets'] ) && ! empty( $_tr_dec['natal']['planets'] ) ) {
								$_natal_pl = (array) $_tr_dec['natal']['planets'];
								$_natal_src = 'traits.natal.planets';
							}
						}
					}
				}
				// [2026-07-06 Johnny Chu] HOTFIX — normalize natal map/list to canonical list rows
				// before calling FAA2 aspect calculator (map rows often miss `name` field).
				$_natal_raw_count = count( $_natal_pl );
				$_natal_legacy_shape = false;
				$_natal_pl = self::normalize_transit_planets_for_storage( (array) $_natal_pl, $_natal_legacy_shape );
				$_natal_norm_count = count( $_natal_pl );
				// [2026-07-06 Johnny Chu] HOTFIX — include a short normalized-name preview for runtime trace.
				$_natal_names_preview = array();
				foreach ( (array) $_natal_pl as $_np_row ) {
					if ( ! is_array( $_np_row ) ) {
						continue;
					}
					$_np_name = isset( $_np_row['name'] ) ? trim( (string) $_np_row['name'] ) : '';
					if ( $_np_name !== '' ) {
						$_natal_names_preview[] = $_np_name;
					}
					if ( count( $_natal_names_preview ) >= 8 ) {
						break;
					}
				}
				// [2026-07-06 Johnny Chu] HOTFIX — quiet FAA2 natal extract info logs to reduce transit log noise.
				// error_log( '[bccm_transit] do_transit_fetch FAA2 natal extract'
				// 	. ' coachee_id=' . $coachee_id
				// 	. ' source=' . $_natal_src
				// 	. ' raw_count=' . $_natal_raw_count
				// 	. ' norm_count=' . $_natal_norm_count
				// 	. ' legacy_shape=' . ( $_natal_legacy_shape ? '1' : '0' )
				// 	. ' names=' . implode( '|', $_natal_names_preview ) );
				// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — always call FAA2 (was gated on !empty natal).
				// Without natal: gets accurate transit planet positions, aspects=[].
				// With natal: gets transit positions + transit-to-natal aspects.
				$_faa2_snap = $_faa2_prov->transit_with_aspects( array(
						'transit_date'  => $date,
						'natal_planets' => $_natal_pl,
						'outer_only'    => false,
					) );
				if ( ! empty( $_faa2_snap['success'] ) ) {
					// [2026-07-09 Johnny Chu] PHASE-FAA2-FE FIX — Convert FAA2 normalized planet list to AstroPoint[].
						// normalize_planets() (in faa2-western provider) returns items with keys:
						//   name_en, key, absolute_degree, sign_degree, sign_en, sign_number, house, retrograde, fullDegree
						// NOT full_degree / norm_degree / is_retro (those are raw FAA2 API keys, before normalization).
						// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — corrected degree key names.
						$_sign_num_map = array(
							'Aries' => 1, 'Taurus' => 2, 'Gemini' => 3, 'Cancer' => 4,
							'Leo' => 5, 'Virgo' => 6, 'Libra' => 7, 'Scorpio' => 8,
							'Sagittarius' => 9, 'Capricorn' => 10, 'Aquarius' => 11, 'Pisces' => 12,
						);
						$_faa2_pl = array();
						foreach ( (array) ( $_faa2_snap['transit_planets'] ?? array() ) as $_p ) {
							// name_en is set by normalize_planets(); key is lowercase (e.g. 'sun')
							$_pn = (string) ( $_p['name_en'] ?? '' );
							if ( $_pn === '' ) { continue; }
							$_sign_en = (string) ( $_p['sign_en'] ?? '' );
							// normalize_planets() uses absolute_degree (not full_degree) and sign_degree (not norm_degree).
							$_full_deg = (float) ( $_p['absolute_degree'] ?? $_p['fullDegree'] ?? $_p['full_degree'] ?? $_p['abs_pos'] ?? 0 );
							$_norm_deg = (float) ( $_p['sign_degree']     ?? $_p['norm_degree'] ?? 0 );
							$_house_v  = isset( $_p['house'] ) ? $_p['house'] : null;
							$_house_s  = is_numeric( $_house_v ) ? (string) (int) $_house_v : (string) ( $_house_v ?? '' );
							// normalize_planets() already casts retrograde to bool; raw FAA2 uses string "True"/"False" via is_retro.
							$_retro = isset( $_p['retrograde'] ) ? (bool) $_p['retrograde']
								: ( isset( $_p['is_retro'] ) ? ( strtolower( (string) $_p['is_retro'] ) === 'true' ) : false );
							// sign_number from normalize_planets() is sign_number (not sign_num).
							$_snum = isset( $_p['sign_number'] ) ? (int) $_p['sign_number']
								: ( isset( $_sign_num_map[ $_sign_en ] ) ? (int) $_sign_num_map[ $_sign_en ] : 0 );
							$_faa2_pl[] = array(
								'name'       => $_pn,
								'position'   => $_norm_deg > 0 ? $_norm_deg : fmod( $_full_deg, 30 ),
								'abs_pos'    => $_full_deg,
								'sign'       => $_sign_en,
								'sign_num'   => $_snum,
								'house'      => $_house_s !== '' ? $_house_s : null,
								'retrograde' => $_retro,
							);
						}
						$_faa2_pl_final = ! empty( $_faa2_pl ) ? $_faa2_pl
							: (array) ( $_faa2_snap['transit_planets'] ?? array() );
						// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — snapshot writer compatibility:
						// persist planets_json as legacy positions map (Sun=>{...}) for /my-transit/ renderer.
						$_faa2_pl_snapshot = self::normalize_transit_snapshot_positions_map( (array) $_faa2_pl_final );
						$_natal_sign_lookup = self::build_transit_natal_sign_lookup( (array) $_natal_pl );
						// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — normalized aspect
						// contract shared by writer + migration to prevent renderer field gaps.
						$_faa2_asp = self::normalize_transit_snapshot_aspects(
							(array) ( $_faa2_snap['aspects'] ?? array() ),
							$_faa2_pl_snapshot,
							$_natal_sign_lookup,
							'faa2_transit_calc'
						);
						// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — only accept payloads
						// that contain real transit planets (Sun..Pluto).
						if ( self::has_usable_transit_planets( (array) $_faa2_pl_final ) ) {
							// [2026-07-06 Johnny Chu] HOTFIX — quiet FAA2 success-path info logs to reduce transit log noise.
							// error_log( '[bccm_transit] do_transit_fetch FAA2 path OK'
							// 	. ' coachee_id=' . $coachee_id . ' date=' . $date
							// 	. ' natal_source=' . $_natal_src
							// 	. ' natal_count=' . count( $_natal_pl )
							// 	. ' planets=' . count( $_faa2_pl_final )
							// 	. ' aspects=' . count( $_faa2_asp ) );
							// DB write (same table as legacy FAA path)
							$_t_snap2 = $wpdb->prefix . 'bccm_transit_snapshots';
							$_tbl2 = (bool) $wpdb->get_var( $wpdb->prepare(
								'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
								$_t_snap2
							) );
							if ( ! $_tbl2 ) {
								require_once ABSPATH . 'wp-admin/includes/upgrade.php';
								$_cc2 = $wpdb->get_charset_collate();
								dbDelta( "CREATE TABLE IF NOT EXISTS {$_t_snap2} (
									id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
									coachee_id   BIGINT UNSIGNED NOT NULL,
									user_id      BIGINT UNSIGNED NULL DEFAULT NULL,
									target_date  DATE NOT NULL,
									label        VARCHAR(64) NOT NULL DEFAULT '',
									source_marker VARCHAR(32) NOT NULL DEFAULT '',
									planets_json LONGTEXT NULL,
									aspects_json LONGTEXT NULL,
									fetched_at   DATETIME NOT NULL,
									PRIMARY KEY (id),
									UNIQUE KEY uniq_coachee_date (coachee_id, target_date),
									KEY idx_user_id (user_id),
									KEY idx_source_marker (source_marker),
									KEY idx_target_date (target_date)
								) {$_cc2};" );
							}
							// [2026-07-06 Johnny Chu] HOTFIX — schema guard + migration tick before writes.
							self::ensure_transit_snapshot_schema( $_t_snap2 );
							$_db2 = $wpdb->query( $wpdb->prepare(
								"INSERT INTO {$_t_snap2} (coachee_id, user_id, target_date, label, source_marker, planets_json, aspects_json, fetched_at)
								 VALUES (%d, %d, %s, %s, %s, %s, %s, %s)
								 ON DUPLICATE KEY UPDATE planets_json=VALUES(planets_json), aspects_json=VALUES(aspects_json),
								                         label=VALUES(label), source_marker=VALUES(source_marker), fetched_at=VALUES(fetched_at)",
								$coachee_id, $uid, $date, $period,
								self::TRANSIT_SOURCE_DO_FETCH_V2,
								wp_json_encode( $_faa2_pl_snapshot, JSON_UNESCAPED_UNICODE ),
								wp_json_encode( $_faa2_asp, JSON_UNESCAPED_UNICODE ),
								current_time( 'mysql' )
							) );
							if ( $_db2 !== false ) {
								do_action( 'bccm_transit_snapshot_saved', $coachee_id, $uid, $date );
								// [2026-07-10 Johnny Chu] PHASE-FAA2 — write JSONL history for FAA2 success path.
								self::write_transit_save_history_log(
									'success',
									$save_source,
									$coachee_id,
									$uid,
									$date,
									$period,
									count( $_faa2_pl_snapshot ),
									count( $_faa2_asp ),
									'faa2_western',
									'transit_with_aspects',
									'',
									array( 'http_status' => 200, 'transport' => 'in_process' )
								);
								// [2026-07-06 Johnny Chu] HOTFIX — quiet FAA2 saved-ok info logs to reduce transit log noise.
								// error_log( '[bccm_transit] do_transit_fetch FAA2 SAVED OK'
								// 	. ' blog_id=' . (int) get_current_blog_id()
								// 	. ' table=' . $wpdb->prefix . 'bccm_transit_snapshots'
								// 	. ' coachee_id=' . $coachee_id . ' date=' . $date
								// 	. ' planets=' . count( $_faa2_pl_final )
								// 	. ' aspects=' . count( $_faa2_asp ) );
								return array(
									'success'     => true,
									'target_date' => $date,
									'planets'     => $_faa2_pl_final,
									'aspects'     => $_faa2_asp,
									'_source'     => 'faa2_western',
								);
							}
							// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — brace fix: DB write failed, fall through to legacy.
							// [2026-07-10 Johnny Chu] PHASE-FAA2 — log FAA2 DB write failure before fallback.
							self::write_transit_save_history_log(
								'failed',
								$save_source,
								$coachee_id,
								$uid,
								$date,
								$period,
								count( $_faa2_pl_snapshot ),
								count( $_faa2_asp ),
								'faa2_western',
								'db_upsert',
								'Lưu transit FAA2 vào DB thất bại: ' . (string) $wpdb->last_error,
								array( 'http_status' => 500, 'transport' => 'in_process' )
							);
							error_log( '[bccm_transit] do_transit_fetch FAA2 DB write failed'
								. ' blog_id=' . (int) get_current_blog_id()
								. ' coachee_id=' . $coachee_id . ' date=' . $date
								. ' err=' . (string) $wpdb->last_error );
						} else {
							error_log( '[bccm_transit] do_transit_fetch FAA2 unusable planets payload'
								. ' blog_id=' . (int) get_current_blog_id()
								. ' coachee_id=' . $coachee_id . ' date=' . $date
								. ' planets=' . count( (array) $_faa2_pl_final ) );
						}
					} else {
						error_log( '[bccm_transit] do_transit_fetch FAA2 transit_with_aspects failed'
							. ' coachee_id=' . $coachee_id . ' date=' . $date
							. ' msg=' . (string) ( $_faa2_snap['message'] ?? $_faa2_snap['error'] ?? 'unknown' ) );
					}
			}
		}
		// Legacy FAA path (freeastroapi.com) — fallback when FAA2 unavailable or natal not in DB.

		$result = BizCoach_Pro_Astro_Client::transits_western( $payload );
		if ( is_wp_error( $result ) || empty( $result['success'] ) ) {
			$_msg = is_wp_error( $result )
				? $result->get_error_message()
				: ( isset( $result['error'] ) ? (string) $result['error'] : 'Gateway lỗi.' );
			// [2026-07-10 Johnny Chu] PHASE-FAA2 — log transport/provider failure for history UI.
			self::write_transit_save_history_log(
				'failed',
				$save_source,
				$coachee_id,
				$uid,
				$date,
				$period,
				0,
				0,
				'legacy_western',
				'transits_western',
				$_msg,
				array(
					'http_status' => isset( $result['http']['status'] ) ? (int) $result['http']['status'] : 0,
					'transport'   => isset( $result['http']['transport'] ) ? (string) $result['http']['transport'] : '',
				)
			);
			return array(
				'success' => false,
				'message' => $_msg,
			);
		}

		// [2026-06-08 Johnny Chu] HOTFIX — V2 normalizer key is `transits`, not `planets`.
		// [2026-06-28 Johnny Chu] HOTFIX — also check $env['data']['transits'] and $env['data']['aspects']
		// because call_in_process wraps the router response body as-is; different router versions
		// put transit data at envelope.transits (in-process flat) or envelope.data.transits (remote JSON).
		$env     = isset( $result['envelope'] ) && is_array( $result['envelope'] ) ? $result['envelope'] : array();
		$planets = isset( $env['transits'] )       ? $env['transits']
		         : ( isset( $env['data']['transits'] ) ? $env['data']['transits']
		         : ( isset( $env['planets'] )           ? $env['planets']
		         : ( isset( $env['data']['planets'] )   ? $env['data']['planets'] : array() ) ) );
		$aspects = isset( $env['aspects'] )           ? $env['aspects']
		         : ( isset( $env['data']['aspects'] )   ? $env['data']['aspects']   : array() );

		// [2026-06-28 Johnny Chu] HOTFIX — bail early if planets empty: never store [] silently.
		// Storing empty JSON causes report page to always show "Dữ liệu transit chưa sẵn sàng"
		// even though the button said "✅ 7/7 ngày" (success was based only on API 200, not DB content).
		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — reject low-quality payloads
		// (e.g. Ascendant-only) so FE can trigger rebuild/manual fetch correctly.
		if ( ! self::has_usable_transit_planets( (array) $planets ) ) {
			$env_keys  = implode( ',', array_keys( $env ) );
			$data_keys = isset( $env['data'] ) && is_array( $env['data'] )
				? implode( ',', array_keys( $env['data'] ) ) : 'n/a';
			// [2026-06-28 Johnny Chu] HOTFIX — deep-dump the 'raw' value to diagnose transport issue.
			$transport  = isset( $result['http']['transport'] ) ? (string) $result['http']['transport'] : 'unknown';
			$http_status = isset( $result['http']['status'] ) ? (int) $result['http']['status'] : 0;
			$raw_detail = '';
			// [2026-06-28 Johnny Chu] HOTFIX — use array_key_exists (not isset) so null value is detected.
			if ( array_key_exists( 'raw', $env ) ) {
				$rv = $env['raw'];
				if ( is_null( $rv ) ) {
					// json_decode returned null = invalid JSON body from remote (HTML redirect? empty body?)
					$raw_detail = ' raw=NULL(invalid_json_or_empty_body)';
				} elseif ( is_bool( $rv ) ) {
					$raw_detail = ' raw=' . ( $rv ? 'TRUE' : 'FALSE' );
				} elseif ( is_string( $rv ) ) {
					$raw_detail = ' raw_string=' . substr( $rv, 0, 400 );
				} elseif ( is_array( $rv ) ) {
					$raw_detail = ' raw_array_keys=[' . implode( ',', array_keys( $rv ) ) . ']';
				} elseif ( is_object( $rv ) ) {
					$raw_detail = ' raw_object_class=' . get_class( $rv );
				} else {
					$raw_detail = ' raw_type=' . gettype( $rv );
				}
			}
			error_log( '[bccm_transit] do_transit_fetch EMPTY planets after envelope parse'
				. ' coachee_id=' . $coachee_id . ' date=' . $date
				. ' blog_id=' . (int) get_current_blog_id()
				. ' transport=' . $transport . ' http_status=' . $http_status
				. ' env_keys=[' . $env_keys . ']'
				. ' env.data_keys=[' . $data_keys . ']'
				. $raw_detail );
			// [2026-07-10 Johnny Chu] PHASE-FAA2 — log parse/quality failure to JSONL history.
			self::write_transit_save_history_log(
				'failed',
				$save_source,
				$coachee_id,
				$uid,
				$date,
				$period,
				is_array( $planets ) ? count( $planets ) : 0,
				is_array( $aspects ) ? count( $aspects ) : 0,
				'legacy_western',
				'transits_parse',
				'API trả về thành công nhưng không có dữ liệu hành tinh hợp lệ.',
				array(
					'http_status' => $http_status,
					'transport'   => $transport,
				)
			);
			return array(
				'success' => false,
				'message' => 'API trả về thành công nhưng không có dữ liệu hành tinh hợp lệ.'
					. ' transport=' . $transport . ' http=' . $http_status
					. ' env_keys=[' . $env_keys . '] data_keys=[' . $data_keys . ']'
					. $raw_detail,
			);
		}

		$t_snap = $wpdb->prefix . 'bccm_transit_snapshots';

		// [2026-06-28 Johnny Chu] HOTFIX — R-SHOW-TABLES: use information_schema, not SHOW TABLES.
		// Also auto-create the table when missing (do_transit_fetch previously assumed it existed,
		// causing silent INSERT failure → API returned success but DB was empty → transit page empty).
		$tbl_exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$t_snap
		) );
		// [2026-07-06 Johnny Chu] HOTFIX — quiet legacy table-state info logs to reduce transit log noise.
		if ( $tbl_exists ) {
			// error_log( '[bccm_transit] do_transit_fetch: table EXISTS — ' . $t_snap
			// 	. ' blog_id=' . (int) get_current_blog_id() . ' coachee_id=' . $coachee_id . ' date=' . $date );
		} else {
			// error_log( '[bccm_transit] do_transit_fetch: table MISSING — auto-creating ' . $t_snap
			// 	. ' blog_id=' . (int) get_current_blog_id() );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset_collate = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE IF NOT EXISTS {$t_snap} (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				coachee_id   BIGINT UNSIGNED NOT NULL,
				user_id      BIGINT UNSIGNED NULL DEFAULT NULL,
				target_date  DATE NOT NULL,
				label        VARCHAR(64) NOT NULL DEFAULT '',
				source_marker VARCHAR(32) NOT NULL DEFAULT '',
				planets_json LONGTEXT NULL,
				aspects_json LONGTEXT NULL,
				fetched_at   DATETIME NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uniq_coachee_date (coachee_id, target_date),
				KEY idx_user_id (user_id),
				KEY idx_source_marker (source_marker),
				KEY idx_target_date (target_date)
			) {$charset_collate};" );
		}

		// [2026-07-06 Johnny Chu] HOTFIX — schema guard + migration tick before writes.
		self::ensure_transit_snapshot_schema( $t_snap );
		// [2026-07-06 Johnny Chu] PHASE-FAA2-TWINBRAIN A10 — align legacy fallback writer
		// to v2 snapshot schema so /my-transit/ consumes a single normalized shape.
		$planets_snapshot = self::normalize_transit_snapshot_positions_map( (array) $planets );
		$aspects_snapshot = self::normalize_transit_snapshot_aspects( (array) $aspects, $planets_snapshot, array(), 'legacy_western' );

		$db_result = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t_snap} (coachee_id, user_id, target_date, label, source_marker, planets_json, aspects_json, fetched_at)
			 VALUES (%d, %d, %s, %s, %s, %s, %s, %s)
			 ON DUPLICATE KEY UPDATE planets_json=VALUES(planets_json), aspects_json=VALUES(aspects_json),
			                         label=VALUES(label), source_marker=VALUES(source_marker), fetched_at=VALUES(fetched_at)",
			$coachee_id, $uid, $date, $period,
			self::TRANSIT_SOURCE_DO_FETCH_V2,
			wp_json_encode( $planets_snapshot, JSON_UNESCAPED_UNICODE ),
			wp_json_encode( $aspects_snapshot, JSON_UNESCAPED_UNICODE ),
			current_time( 'mysql' )
		) );

		if ( $db_result === false ) {
			// [2026-07-10 Johnny Chu] PHASE-FAA2 — log DB upsert failure in transit save history.
			self::write_transit_save_history_log(
				'failed',
				$save_source,
				$coachee_id,
				$uid,
				$date,
				$period,
				is_array( $planets_snapshot ) ? count( $planets_snapshot ) : 0,
				is_array( $aspects_snapshot ) ? count( $aspects_snapshot ) : 0,
				'legacy_western',
				'db_upsert',
				'Lưu transit DB thất bại: ' . (string) $wpdb->last_error,
				array(
					'http_status' => isset( $result['http']['status'] ) ? (int) $result['http']['status'] : 0,
					'transport'   => isset( $result['http']['transport'] ) ? (string) $result['http']['transport'] : '',
				)
			);
			return array(
				'success' => false,
				'message' => 'Lưu transit DB thất bại: ' . (string) $wpdb->last_error,
			);
		}

		// Fire cache invalidation so /my-transit/ public router picks up fresh data.
		do_action( 'bccm_transit_snapshot_saved', $coachee_id, $uid, $date );
		// [2026-07-10 Johnny Chu] PHASE-FAA2 — write JSONL history for legacy success path.
		self::write_transit_save_history_log(
			'success',
			$save_source,
			$coachee_id,
			$uid,
			$date,
			$period,
				is_array( $planets_snapshot ) ? count( $planets_snapshot ) : 0,
				is_array( $aspects_snapshot ) ? count( $aspects_snapshot ) : 0,
			'legacy_western',
			'transits_western',
			'',
			array(
				'http_status' => isset( $result['http']['status'] ) ? (int) $result['http']['status'] : 0,
				'transport'   => isset( $result['http']['transport'] ) ? (string) $result['http']['transport'] : '',
			)
		);

		// [2026-07-06 Johnny Chu] HOTFIX — quiet legacy saved-ok info logs to reduce transit log noise.
		// error_log( '[bccm_transit] do_transit_fetch LEGACY SAVED OK'
		// 	. ' blog_id=' . (int) get_current_blog_id()
		// 	. ' table=' . $t_snap
		// 	. ' coachee_id=' . $coachee_id . ' date=' . $date
		// 	. ' planets=' . ( is_array( $planets ) ? count( $planets ) : 0 )
		// 	. ' aspects=' . ( is_array( $aspects ) ? count( $aspects ) : 0 ) );

		return array(
			'success'     => true,
			'target_date' => $date,
			'planets'     => $planets,
			'aspects'     => $aspects,
		);
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — normalize save-source labels for transit history.
	 *
	 * @param string $save_source
	 * @return string
	 */
	private static function resolve_transit_save_source( $save_source ) {
		$save_source = sanitize_key( (string) $save_source );
		if ( $save_source !== '' ) {
			return $save_source;
		}

		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$hook = (string) current_filter();
			if ( class_exists( 'BizCoach_Pro_Transit_Cron' ) ) {
				if ( $hook === (string) BizCoach_Pro_Transit_Cron::HOOK ) {
					return 'cron_daily';
				}
				if ( $hook === (string) BizCoach_Pro_Transit_Cron::BATCH_HOOK ) {
					return 'cron_batch_30d';
				}
				if ( $hook === (string) BizCoach_Pro_Transit_Cron::WEEKLY_7D_HOOK ) {
					return 'cron_weekly_7d';
				}
			}
			if ( $hook === 'bcpro_async_rebuild_transit' ) {
				return 'async_chart_generate';
			}
			return 'cron';
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$action = '';
			if ( isset( $_REQUEST['action'] ) ) {
				$action = sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) );
			}
			if ( $action === 'bccm_transit_fetch_day' ) {
				return 'manual_admin_ajax';
			}
			return 'manual_ajax';
		}

		return 'manual';
	}

	/**
	 * [2026-07-10 Johnny Chu] PHASE-FAA2 — write one transit save history row to astro JSONL.
	 *
	 * @param string $status
	 * @param string $save_source
	 * @param int    $coachee_id
	 * @param int    $uid
	 * @param string $date
	 * @param string $period
	 * @param int    $planets_count
	 * @param int    $aspects_count
	 * @param string $provider
	 * @param string $fetch_path
	 * @param string $message
	 * @param array  $extra
	 * @return void
	 */
	private static function write_transit_save_history_log(
		$status,
		$save_source,
		$coachee_id,
		$uid,
		$date,
		$period,
		$planets_count,
		$aspects_count,
		$provider,
		$fetch_path,
		$message = '',
		array $extra = array()
	) {
		$status = sanitize_key( (string) $status );
		if ( $status !== 'success' && $status !== 'failed' ) {
			$status = 'failed';
		}

		$ctx = array_merge( array(
			'kind'          => 'transit_snapshot_save',
			'status'        => $status,
			'save_source'   => self::resolve_transit_save_source( (string) $save_source ),
			'coachee_id'    => (int) $coachee_id,
			'user_id'       => (int) $uid,
			'target_date'   => (string) $date,
			'period'        => sanitize_key( (string) $period ),
			'planets_count' => (int) $planets_count,
			'aspects_count' => (int) $aspects_count,
			'provider'      => sanitize_key( (string) $provider ),
			'fetch_path'    => sanitize_key( (string) $fetch_path ),
			'message'       => (string) $message,
		), $extra );

		$event = ( $status === 'success' ) ? 'transit_snapshot_saved' : 'transit_snapshot_save_failed';
		$msg   = ( $status === 'success' ) ? 'Transit snapshot saved' : 'Transit snapshot save failed';

		try {
			if ( class_exists( 'BizCoach_Pro_Astro_Log', false ) ) {
				if ( $status === 'success' ) {
					BizCoach_Pro_Astro_Log::ok( $event, $msg, $ctx );
				} else {
					BizCoach_Pro_Astro_Log::fail( $event, $msg, $ctx );
				}
				return;
			}

			if ( class_exists( 'BizCity_Channel_File_Logger', false ) ) {
				BizCity_Channel_File_Logger::write(
					'astro',
					( $status === 'success' ) ? 'info' : 'error',
					$event,
					$msg,
					$ctx
				);
			}
		} catch ( \Throwable $e ) {
			error_log( '[bccm_transit] write_transit_save_history_log failed: ' . $e->getMessage() );
		}
	}

	/* ------------------------------------------------------------------ *
	 * C-BE-2  GET /me/usage-summary
	 * ------------------------------------------------------------------ */

	/**
	 * [2026-06-07 Johnny Chu] PHASE-C C-BE-2 — per-user usage summary.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function get_usage_summary( $request ) {
		$uid   = (int) get_current_user_id();
		$range = (string) $request->get_param( 'range' );
		if ( ! in_array( $range, array( '7d', '30d', '90d' ), true ) ) {
			$range = '30d';
		}

		// [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — always return a stable
		// usage payload shape so FE UsagePage can render even on degraded paths.
		if ( ! class_exists( 'BizCoach_Pro_Usage_Report' ) ) {
			return rest_ensure_response( self::normalize_usage_summary_payload( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Usage report module chưa load.',
			), $range ) );
		}

		$summary = BizCoach_Pro_Usage_Report::summary( $uid, $range );
		return rest_ensure_response( self::normalize_usage_summary_payload( $summary, $range ) );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-TWINSHELL-IMPL — normalize usage payload
	 * contract for FE (range/today/history/plan) on both success and degraded paths.
	 *
	 * @param mixed  $payload Raw payload from BizCoach_Pro_Usage_Report::summary().
	 * @param string $fallback_range Requested range fallback.
	 * @return array
	 */
	private static function normalize_usage_summary_payload( $payload, $fallback_range ) {
		$allowed_ranges = array( '7d', '30d', '90d' );
		$range          = in_array( $fallback_range, $allowed_ranges, true ) ? $fallback_range : '30d';

		$out = array(
			'success'   => false,
			'range'     => $range,
			'today'     => array(
				'by_feature' => array(),
				'tokens'     => array(
					'prompt'     => 0,
					'completion' => 0,
					'total'      => 0,
					'calls'      => 0,
				),
				'cost_usd'   => 0,
				'by_service' => array(),
			),
			'history'   => array(),
			'plan'      => 'free',
			'_degraded' => true,
		);

		if ( ! is_array( $payload ) ) {
			return $out;
		}

		$out['success'] = ! empty( $payload['success'] );
		$out['_degraded'] = isset( $payload['_degraded'] )
			? (bool) $payload['_degraded']
			: ! $out['success'];

		if ( isset( $payload['message'] ) ) {
			$out['message'] = sanitize_text_field( (string) $payload['message'] );
		}

		if ( isset( $payload['range'] ) && in_array( (string) $payload['range'], $allowed_ranges, true ) ) {
			$out['range'] = (string) $payload['range'];
		}

		if ( isset( $payload['plan'] ) ) {
			$plan = sanitize_key( (string) $payload['plan'] );
			if ( $plan !== '' ) {
				$out['plan'] = $plan;
			}
		}

		$today = ( isset( $payload['today'] ) && is_array( $payload['today'] ) )
			? $payload['today']
			: array();

		$today_tokens = ( isset( $today['tokens'] ) && is_array( $today['tokens'] ) )
			? $today['tokens']
			: array();

		$out['today']['tokens'] = array(
			'prompt'     => isset( $today_tokens['prompt'] ) ? (int) $today_tokens['prompt'] : 0,
			'completion' => isset( $today_tokens['completion'] ) ? (int) $today_tokens['completion'] : 0,
			'total'      => isset( $today_tokens['total'] ) ? (int) $today_tokens['total'] : 0,
			'calls'      => isset( $today_tokens['calls'] ) ? (int) $today_tokens['calls'] : 0,
		);

		$out['today']['cost_usd'] = isset( $today['cost_usd'] ) ? (float) $today['cost_usd'] : 0;

		$by_service = ( isset( $today['by_service'] ) && is_array( $today['by_service'] ) )
			? $today['by_service']
			: array();
		foreach ( $by_service as $service_key => $service_row ) {
			if ( ! is_array( $service_row ) ) {
				continue;
			}
			$key = sanitize_key( (string) $service_key );
			if ( $key === '' ) {
				continue;
			}
			$out['today']['by_service'][ $key ] = array(
				'calls'  => isset( $service_row['calls'] ) ? (int) $service_row['calls'] : 0,
				'tokens' => isset( $service_row['tokens'] ) ? (int) $service_row['tokens'] : 0,
			);
		}

		$by_feature = ( isset( $today['by_feature'] ) && is_array( $today['by_feature'] ) )
			? $today['by_feature']
			: array();
		foreach ( $by_feature as $feature_key => $feature_row ) {
			if ( ! is_array( $feature_row ) ) {
				continue;
			}
			$key = sanitize_key( (string) $feature_key );
			if ( $key === '' ) {
				continue;
			}
			$out['today']['by_feature'][ $key ] = array(
				'used'      => isset( $feature_row['used'] ) ? (int) $feature_row['used'] : 0,
				'limit'     => isset( $feature_row['limit'] ) ? (int) $feature_row['limit'] : 0,
				'remaining' => isset( $feature_row['remaining'] ) ? (int) $feature_row['remaining'] : 0,
			);
		}

		$history = ( isset( $payload['history'] ) && is_array( $payload['history'] ) )
			? $payload['history']
			: array();
		foreach ( $history as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$date = isset( $row['date'] ) ? sanitize_text_field( (string) $row['date'] ) : '';
			if ( $date === '' ) {
				continue;
			}
			$out['history'][] = array(
				'date'     => $date,
				'calls'    => isset( $row['calls'] ) ? (int) $row['calls'] : 0,
				'tokens'   => isset( $row['tokens'] ) ? (int) $row['tokens'] : 0,
				'cost_usd' => isset( $row['cost_usd'] ) ? (float) $row['cost_usd'] : 0,
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT
	 * GET /me/profiles/{id}/transit-cache?days=30&start=YYYY-MM-DD
	 *
	 * Reads pre-cached transit rows from bccm_transit_snapshots.
	 * Returns up to `days` rows starting from `start` (default: today).
	 * Each row: { date, planets[], aspects[], cached_at }
	 * ------------------------------------------------------------------ */
	public static function get_transit_cache( $request ) {
		global $wpdb;
		$coachee_id = (int) $request->get_param( 'id' );
		$days       = max( 1, min( 90, (int) ( $request->get_param( 'days' ) ?? 30 ) ) );
		$start      = sanitize_text_field( (string) ( $request->get_param( 'start' ) ?? '' ) );

		if ( $start === '' || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start ) ) {
			$start = current_time( 'Y-m-d' );
		}

		$end = date( 'Y-m-d', strtotime( $start . ' +' . ( $days - 1 ) . ' days' ) );

		$t_snap = $wpdb->prefix . 'bccm_transit_snapshots';
		// [2026-07-06 Johnny Chu] HOTFIX — ensure source_marker column exists and run one migration tick.
		self::ensure_transit_snapshot_schema( $t_snap );
		// [2026-07-06 Johnny Chu] HOTFIX — quiet transit-cache read info logs to reduce transit log noise.
		// error_log( '[bccm_transit] get_transit_cache READ'
		// 	. ' blog_id=' . (int) get_current_blog_id()
		// 	. ' table=' . $t_snap
		// 	. ' coachee_id=' . $coachee_id . ' start=' . $start . ' days=' . $days );
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT target_date, label, source_marker, planets_json, aspects_json, fetched_at
			 FROM {$t_snap}
			 WHERE coachee_id = %d AND target_date BETWEEN %s AND %s
			 ORDER BY target_date ASC
			 LIMIT 90",
			$coachee_id, $start, $end
		), ARRAY_A );

		$items = array();
		$found_dates = array();
		$missing_map = array();
		foreach ( (array) $rows as $r ) {
			$planets = $r['planets_json'] ? json_decode( $r['planets_json'], true ) : array();
			$aspects = $r['aspects_json'] ? json_decode( $r['aspects_json'], true ) : array();
			// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — treat unusable rows
			// (empty or Ascendant-only) as missing so FE shows build-by-day actions.
			if ( ! self::has_usable_transit_planets( (array) $planets ) ) {
				$missing_map[ (string) $r['target_date'] ] = true;
				continue;
			}
			$items[] = array(
				'date'      => $r['target_date'],
				'label'     => self::normalize_transit_label_value( isset( $r['label'] ) ? (string) $r['label'] : '' ),
				'source_marker' => isset( $r['source_marker'] ) ? (string) $r['source_marker'] : '',
				'planets'   => $planets,
				'aspects'   => $aspects,
				'cached_at' => $r['fetched_at'],
			);
			$found_dates[] = (string) $r['target_date'];
		}

		$missing_dates = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d = date( 'Y-m-d', strtotime( $start . ' +' . $i . ' days' ) );
			if ( ! in_array( $d, $found_dates, true ) ) {
				$missing_map[ $d ] = true;
			}
		}
		foreach ( array_keys( $missing_map ) as $d ) {
			if ( $d >= $start && $d <= $end ) {
				$missing_dates[] = $d;
			}
		}

		return rest_ensure_response( array(
			'success'       => true,
			'coachee_id'    => $coachee_id,
			'start'         => $start,
			'end'           => $end,
			'days'          => $days,
			'rows'          => $items,
			'missing_dates' => $missing_dates,
			'has_full_cache'=> empty( $missing_dates ),
		) );
	}

	/* ------------------------------------------------------------------ *
	 * [2026-06-07 Johnny Chu] PHASE-D D-BE-TRANSIT
	 * POST /me/profiles/{id}/rebuild-transit
	 *
	 * Triggers a 30-day batch transit fetch for the profile.
	 * Stores each day in bccm_transit_snapshots. Throttled: only refetches
	 * days that are missing OR stale (> 7 days old). Returns summary.
	 * ------------------------------------------------------------------ */
	public static function rebuild_transit_30d( $request ) {
		global $wpdb;
		$coachee_id = (int) $request->get_param( 'id' );
		$days       = 30;
		$start      = current_time( 'Y-m-d' );

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Astro client chưa load.',
			) );
		}

		// [2026-06-08 Johnny Chu] HOTFIX — admin-aware: resolve actual owner uid
		$uid = self::resolve_owner_uid( $coachee_id );
		if ( ! $uid ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';
		$t_snap  = $wpdb->prefix . 'bccm_transit_snapshots';

		$coachee = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t_coach} WHERE id = %d AND user_id = %d LIMIT 1",
			$coachee_id, $uid
		), ARRAY_A );
		if ( ! $coachee ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.', array( 'status' => 404 ) );
		}

		$natal = $wpdb->get_row( $wpdb->prepare(
			"SELECT birth_time, birth_place FROM {$t_astro}
			 WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );
		if ( ! $natal ) {
			return rest_ensure_response( array(
				'success'   => false,
				'message'   => 'Cần sinh biểu đồ natal trước khi tính transit.',
			) );
		}

		// [2026-07-05 Johnny Chu] PHASE-FAA2-FE FIX — Rebuild là lệnh "ghi đè bắt buộc" do user
		// chủ động bấm. KHÔNG skip dù ngày đã có data cũ trong DB. do_transit_fetch dùng
		// ON DUPLICATE KEY UPDATE nên mỗi ngày luôn được overwrite với data mới nhất.
		// (Cache skip chỉ áp dụng cho auto smart-fill trong useSmartTransit, không cho rebuild.)
		$end     = date( 'Y-m-d', strtotime( $start . ' +' . ( $days - 1 ) . ' days' ) );
		$fetched = 0;
		$skipped = 0;
		$failed  = 0;
		$errors  = array();

		for ( $i = 0; $i < $days; $i++ ) {
			$date = date( 'Y-m-d', strtotime( $start . ' +' . $i . ' days' ) );

			// [2026-07-10 Johnny Chu] PHASE-FAA2 — mark manual rebuild flow in transit save history.
			$res = self::do_transit_fetch(
				$coachee,
				is_array( $natal ) ? $natal : array(),
				$date,
				'day',
				'manual_rebuild'
			);
			if ( ! empty( $res['success'] ) ) {
				$fetched++;
			} else {
				$failed++;
				$errors[] = $date . ': ' . ( $res['message'] ?? 'unknown' );
				// [2026-07-10 Johnny Chu] PHASE-FAA2-FE — keep scanning all 30 days even with intermittent failures.
			}
		}

		return rest_ensure_response( array(
			'success'  => $failed === 0,
			'fetched'  => $fetched,
			'skipped'  => $skipped,
			'failed'   => $failed,
			'errors'   => $errors,
			'message'  => $failed > 0
				? "Hoàn thành với {$failed} lỗi. Thử lại sau."
				: "Đã lấy {$fetched} ngày transit thành công.",
		) );
	}

	/* ------------------------------------------------------------------
	 * get_transit_range() — Live day-by-day transit for "bản đồ sao + 30 ngày tới"
	 *
	 * Khác rebuild_transit_30d:
	 *   - KHÔNG ghi DB → chỉ trả kết quả JSON trực tiếp
	 *   - Dùng faa2_western::transit_range() — 1 API call/ngày chưa cache
	 *   - Planet positions cached by DATE (shared across all coachees — geocentric)
	 *   - Aspect calc = PHP thuần (0 API call)
	 *
	 * Use case: FE query "bản đồ sao + 30 ngày tới" trong 1 request.
	 * ------------------------------------------------------------------ */

	public static function get_transit_range( $request ) {
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — live transit-range handler
		global $wpdb;

		$coachee_id = (int) $request->get_param( 'id' );
		$start      = sanitize_text_field( (string) ( $request->get_param( 'start' ) ?? '' ) );
		$num_days   = min( 90, max( 1, (int) ( $request->get_param( 'days' ) ?? 30 ) ) );
		$outer_only = (bool) $request->get_param( 'outer_only' );

		if ( $start === '' ) {
			$start = current_time( 'Y-m-d' );
		}

		// 1. Load natal planets from DB
		$t_astro   = $wpdb->prefix . 'bccm_astro';
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — ORDER BY id DESC for freshest traits data.
		$natal_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT traits FROM {$t_astro} WHERE coachee_id = %d AND chart_type = 'western' ORDER BY id DESC LIMIT 1",
			$coachee_id
		), ARRAY_A );

		if ( ! $natal_row || empty( $natal_row['traits'] ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Cần sinh biểu đồ natal trước. Vào tab "Bản đồ sao" → nhấn "Tạo biểu đồ".',
				'hint'      => 'Nhấn nút "Tạo biểu đồ chiêm tinh" để generate natal chart.',
			) );
		}

		// Decode traits JSON — natal planets live at traits.positions[]
		$traits        = json_decode( $natal_row['traits'], true );
		$natal_planets = is_array( $traits ) ? (array) ( $traits['positions'] ?? array() ) : array();

		if ( empty( $natal_planets ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Biểu đồ natal chưa có dữ liệu planets. Thử tạo lại biểu đồ.',
			) );
		}

		// [2026-07-06 Johnny Chu] HOTFIX — normalize natal payload (map/list) to canonical
		// list rows so FAA2 transit-range aspect calc can read planet names/degrees.
		$_natal_legacy_shape = false;
		$natal_planets = self::normalize_transit_planets_for_storage( (array) $natal_planets, $_natal_legacy_shape );
		if ( empty( $natal_planets ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Không thể chuẩn hóa dữ liệu natal planets. Thử tạo lại biểu đồ.',
			) );
		}

		// 2. Get FAA2 provider
		if ( ! class_exists( 'BizCity_Astro_Router' ) || ! class_exists( 'Astro_Provider_FAA2_Western' ) ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'Astro provider FAA2 chưa load.',
			) );
		}

		BizCity_Astro_Router::boot();
		$faa2 = BizCity_Astro_Router::get_provider( 'faa2_western' );

		if ( ! $faa2 || ! $faa2->is_ready() ) {
			return rest_ensure_response( array(
				'success'   => false,
				'_degraded' => true,
				'message'   => 'FAA2 API key chưa cấu hình.',
				'hint'      => 'Vào Network Admin → Cài đặt → Astrology Gateway → điền "FAA2 API Key" (freeastrologyapi.com).',
			) );
		}

		// 3. Call transit_range() — 1 API call/uncached day, aspect calc pure PHP
		$_tr_input = array(
			'start_date'    => $start,
			'num_days'      => $num_days,
			'natal_planets' => $natal_planets,
			'outer_only'    => $outer_only,
		);
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — log before call (R-CH-FILE-LOG)
		if ( class_exists( 'BizCoach_Pro_Astro_Log', false ) ) {
			BizCoach_Pro_Astro_Log::info( 'transit_range_request',
				'REST /me/profiles/{id}/transit-range calling FAA2',
				array( 'coachee_id' => $coachee_id, 'num_days' => $num_days, 'start' => $start, 'outer_only' => $outer_only )
			);
		}
		$result = $faa2->transit_range( $_tr_input );
		// [2026-07-03 Johnny Chu] PHASE-ASTRO-MIGRATE — log result
		if ( class_exists( 'BizCoach_Pro_Astro_Log', false ) ) {
			BizCoach_Pro_Astro_Log::transit_range_call( $_tr_input, $result, $coachee_id, 'self_service_rest' );
		}

		if ( empty( $result['success'] ) ) {
			return rest_ensure_response( array_merge( $result, array( '_degraded' => true ) ) );
		}

		return rest_ensure_response( $result );
	}

	/* ------------------------------------------------------------------
	 * [2026-07-09 Johnny Chu] PHASE-A5 — PRO chart handlers (/me/charts/*)
	 * ------------------------------------------------------------------ */

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — POST /me/charts/share
	 * Create a public share token for Synastry/Composite/Solar/Lunar result.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function create_pro_chart_share( $request ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để tạo link chia sẻ.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			) );
		}

		$mode_raw = sanitize_text_field( (string) ( $request->get_param( 'mode' ) ?: '' ) );
		$mode     = self::normalize_pro_chart_mode( $mode_raw );
		if ( $mode === '' ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Chế độ chart không hợp lệ để chia sẻ.',
				'Chọn lại công cụ Synastry/Composite/Solar Return/Lunar Return rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		$result = $request->get_param( 'result' );
		if ( ! is_array( $result ) || empty( $result['success'] ) || ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Dữ liệu chart chưa hợp lệ để tạo link chia sẻ.',
				'Chạy chart thành công rồi tạo link public share.',
				'invalid_param_generic'
			) );
		}

		$public_result = $result;
		if ( isset( $public_result['_debug'] ) ) {
			unset( $public_result['_debug'] );
		}

		$encoded_size = strlen( (string) wp_json_encode( $public_result ) );
		if ( $encoded_size > 350000 ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Dữ liệu chart quá lớn để chia sẻ public.',
				'Giảm dữ liệu đầu vào rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		$token      = wp_generate_password( 24, false, false );
		$option_key = self::PRO_CHART_SHARE_OPTION_PREFIX . $token;
		$now        = time();
		$ttl        = max( 3600, (int) self::PRO_CHART_SHARE_TTL );
		$expires_at = $now + $ttl;

		$record = array(
			'schema'     => self::PRO_CHART_SHARE_SCHEMA,
			'uid'        => $uid,
			'mode'       => $mode,
			'result'     => $public_result,
			'created_at' => gmdate( 'c', $now ),
			'expires_at' => $expires_at,
		);

		update_option( $option_key, $record, false );

		$share_url = home_url( '/astro/#/charts/' . $mode . '?share_token=' . rawurlencode( $token ) );

		return rest_ensure_response( array(
			'success'    => true,
			'token'      => $token,
			'url'        => $share_url,
			'mode'       => $mode,
			'expires_at' => gmdate( 'c', $expires_at ),
		) );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — GET /public/charts/share/{token}
	 * Resolve a public share token and return chart payload for FE rendering.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function get_public_pro_chart_share( $request ) {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( $token === '' || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $token ) ) {
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Link chia sẻ không hợp lệ.',
				'Kiểm tra lại link public share.',
				'not_found'
			) );
		}

		$option_key = self::PRO_CHART_SHARE_OPTION_PREFIX . $token;
		$record     = get_option( $option_key, null );
		if ( ! is_array( $record ) ) {
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Không tìm thấy nội dung cho link chia sẻ này.',
				'Tạo lại link public share mới.',
				'not_found'
			) );
		}

		$expires_at = isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0;
		if ( $expires_at > 0 && $expires_at < time() ) {
			delete_option( $option_key );
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Link chia sẻ đã hết hạn.',
				'Tạo lại link public share mới.',
				'not_found'
			) );
		}

		$mode   = self::normalize_pro_chart_mode( isset( $record['mode'] ) ? (string) $record['mode'] : '' );
		$result = isset( $record['result'] ) && is_array( $record['result'] ) ? $record['result'] : array();
		if ( $mode === '' || empty( $result['success'] ) || ! isset( $result['data'] ) || ! is_array( $result['data'] ) ) {
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Nội dung link chia sẻ không còn hợp lệ.',
				'Tạo lại link public share mới.',
				'not_found'
			) );
		}

		return rest_ensure_response( array(
			'success'    => true,
			'mode'       => $mode,
			'result'     => $result,
			'created_at' => isset( $record['created_at'] ) ? (string) $record['created_at'] : '',
			'expires_at' => $expires_at > 0 ? gmdate( 'c', $expires_at ) : '',
		) );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — POST /me/tools/share
	 * Create a public share token for Relations/Ephemeris/Transits Timeline.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function create_public_tool_share( $request ) {
		// [2026-07-09 Johnny Chu] PHASE-A5 — tokenized anonymous share for 3 non-chart tools.
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return rest_ensure_response( self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để tạo link chia sẻ.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			) );
		}

		$tool_raw = sanitize_text_field( (string) ( $request->get_param( 'tool' ) ?: '' ) );
		$tool     = self::normalize_public_tool_share_slug( $tool_raw );
		if ( $tool === '' ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Công cụ chia sẻ không hợp lệ.',
				'Chọn Relations, Ephemeris hoặc Transits Timeline rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		$snapshot = $request->get_param( 'snapshot' );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = $request->get_param( 'payload' );
		}
		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Dữ liệu hiển thị chưa hợp lệ để tạo link chia sẻ.',
				'Tải dữ liệu thành công rồi tạo lại public share.',
				'invalid_param_generic'
			) );
		}

		$public_snapshot = self::sanitize_public_tool_share_snapshot( $snapshot );
		$encoded_size    = strlen( (string) wp_json_encode( $public_snapshot ) );
		if ( $encoded_size > 450000 ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Dữ liệu quá lớn để chia sẻ public.',
				'Giảm phạm vi dữ liệu rồi thử tạo link lại.',
				'invalid_param_generic'
			) );
		}

		$token      = wp_generate_password( 24, false, false );
		$option_key = self::TOOL_SHARE_OPTION_PREFIX . $token;
		$now        = time();
		$ttl        = max( 3600, (int) self::TOOL_SHARE_TTL );
		$expires_at = $now + $ttl;

		$record = array(
			'schema'     => self::TOOL_SHARE_SCHEMA,
			'uid'        => $uid,
			'tool'       => $tool,
			'snapshot'   => $public_snapshot,
			'created_at' => gmdate( 'c', $now ),
			'expires_at' => $expires_at,
		);

		update_option( $option_key, $record, false );

		$share_url = home_url( '/astro/#/' . $tool . '?share_token=' . rawurlencode( $token ) );

		return rest_ensure_response( array(
			'success'    => true,
			'token'      => $token,
			'url'        => $share_url,
			'tool'       => $tool,
			'expires_at' => gmdate( 'c', $expires_at ),
		) );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — GET /public/tools/share/{token}
	 * Resolve a non-chart public share token and return snapshot for rendering.
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public static function get_public_tool_share( $request ) {
		// [2026-07-09 Johnny Chu] PHASE-A5 — anonymous snapshot read endpoint.
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) );
		if ( $token === '' || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $token ) ) {
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Link chia sẻ không hợp lệ.',
				'Kiểm tra lại link public share.',
				'not_found'
			) );
		}

		$option_key = self::TOOL_SHARE_OPTION_PREFIX . $token;
		$record     = get_option( $option_key, null );
		if ( ! is_array( $record ) ) {
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Không tìm thấy nội dung cho link chia sẻ này.',
				'Tạo lại link public share mới.',
				'not_found'
			) );
		}

		$expires_at = isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0;
		if ( $expires_at > 0 && $expires_at < time() ) {
			delete_option( $option_key );
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Link chia sẻ đã hết hạn.',
				'Tạo lại link public share mới.',
				'not_found'
			) );
		}

		$tool     = self::normalize_public_tool_share_slug( isset( $record['tool'] ) ? (string) $record['tool'] : '' );
		$snapshot = isset( $record['snapshot'] ) && is_array( $record['snapshot'] ) ? $record['snapshot'] : array();
		if ( $tool === '' || empty( $snapshot ) ) {
			return rest_ensure_response( self::error_payload(
				'not_found',
				'Nội dung link chia sẻ không còn hợp lệ.',
				'Tạo lại link public share mới.',
				'not_found'
			) );
		}

		return rest_ensure_response( array(
			'success'    => true,
			'tool'       => $tool,
			'snapshot'   => $snapshot,
			'created_at' => isset( $record['created_at'] ) ? (string) $record['created_at'] : '',
			'expires_at' => $expires_at > 0 ? gmdate( 'c', $expires_at ) : '',
		) );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — normalize non-chart tool slug for FE routes.
	 *
	 * @param string $tool
	 * @return string
	 */
	private static function normalize_public_tool_share_slug( $tool ) {
		$tool = strtolower( trim( (string) $tool ) );
		$tool = str_replace( '_', '-', $tool );
		$tool = preg_replace( '/[^a-z-]+/', '', $tool );
		if ( $tool === 'transits' || $tool === 'timeline' ) {
			$tool = 'transits-timeline';
		}
		$allowed = array( 'relations', 'ephemeris', 'transits-timeline' );
		return in_array( $tool, $allowed, true ) ? $tool : '';
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — recursively strip debug fields from public snapshots.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function sanitize_public_tool_share_snapshot( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $key => $item ) {
			if ( is_string( $key ) && strtolower( $key ) === '_debug' ) {
				continue;
			}
			$out[ $key ] = self::sanitize_public_tool_share_snapshot( $item );
		}

		return $out;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — normalize chart mode slug for FE routes.
	 *
	 * @param string $mode
	 * @return string
	 */
	private static function normalize_pro_chart_mode( $mode ) {
		$mode = strtolower( trim( (string) $mode ) );
		$mode = str_replace( '_', '-', $mode );
		$mode = preg_replace( '/[^a-z-]+/', '', $mode );
		$allowed = array( 'synastry', 'composite', 'solar-return', 'lunar-return' );
		return in_array( $mode, $allowed, true ) ? $mode : '';
	}

	/** POST /me/charts/synastry */
	public static function handle_chart_synastry( $request ) {
		// [2026-07-09 Johnny Chu] PHASE-A5 — enforce entitlement gate before expensive calls.
		$gate = self::require_chart_feature( 'astro.synastry' );
		if ( true !== $gate ) {
			return rest_ensure_response( $gate );
		}

		$profile_a_id = (int) $request->get_param( 'profile_a_id' );
		$profile_b_id = (int) $request->get_param( 'profile_b_id' );
		if ( $profile_a_id <= 0 || $profile_b_id <= 0 || $profile_a_id === $profile_b_id ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Cặp hồ sơ Synastry không hợp lệ.',
				'Chọn 2 hồ sơ khác nhau rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		if ( self::resolve_owner_uid( $profile_a_id ) === 0 || self::resolve_owner_uid( $profile_b_id ) === 0 ) {
			return rest_ensure_response( self::error_payload(
				'permission_denied',
				'Bạn không có quyền truy cập một trong hai hồ sơ.',
				'Chọn hồ sơ thuộc tài khoản của bạn hoặc liên hệ quản trị viên.',
				'permission_denied'
			) );
		}

		$subject_a = self::build_chart_subject( $profile_a_id );
		$subject_b = self::build_chart_subject( $profile_b_id );
		if ( is_wp_error( $subject_a ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $subject_a ) );
		}
		if ( is_wp_error( $subject_b ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $subject_b ) );
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( self::error_payload(
				'module_not_loaded',
				'Astro gateway client chưa sẵn sàng.',
				'Tải lại trang hoặc liên hệ quản trị viên.',
				'module_not_loaded',
				true
			) );
		}

		$payload = array(
			'subject_a'            => $subject_a['subject'],
			'subject_b'            => $subject_b['subject'],
			'house_system'         => sanitize_text_field( (string) ( $request->get_param( 'house_system' ) ?: 'placidus' ) ),
			'orb_profile'          => sanitize_text_field( (string) ( $request->get_param( 'orb_profile' ) ?: 'standard' ) ),
			'include_house_overlay'=> (bool) $request->get_param( 'include_house_overlay' ),
		);

		$profile_refs = array(
			'a' => array( 'id' => $subject_a['id'], 'name' => $subject_a['name'] ),
			'b' => array( 'id' => $subject_b['id'], 'name' => $subject_b['name'] ),
		);

		// [2026-07-09 Johnny Chu] PHASE-A5 — option cache hit before gateway call.
		$uid       = (int) get_current_user_id();
		$cache_key = self::build_pro_chart_cache_option_key( 'synastry', $uid, $payload, $profile_refs );
		$cached    = self::get_pro_chart_cached_response( $cache_key );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$gw = BizCoach_Pro_Astro_Client::synastry_western( $payload, array( 'timeout' => 30 ) );
		$response = self::normalize_pro_chart_response( 'synastry', $gw, $profile_refs );
		$response = self::with_pro_chart_debug( $response, $cache_key, false, $payload, $gw );
		self::set_pro_chart_cached_response( $cache_key, 'synastry', $uid, $payload, $gw, $response );
		return rest_ensure_response( $response );
	}

	/** POST /me/charts/composite */
	public static function handle_chart_composite( $request ) {
		// [2026-07-09 Johnny Chu] PHASE-A5 — enforce entitlement gate before expensive calls.
		$gate = self::require_chart_feature( 'astro.composite' );
		if ( true !== $gate ) {
			return rest_ensure_response( $gate );
		}

		$profile_a_id = (int) $request->get_param( 'profile_a_id' );
		$profile_b_id = (int) $request->get_param( 'profile_b_id' );
		if ( $profile_a_id <= 0 || $profile_b_id <= 0 || $profile_a_id === $profile_b_id ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Cặp hồ sơ Composite không hợp lệ.',
				'Chọn 2 hồ sơ khác nhau rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		if ( self::resolve_owner_uid( $profile_a_id ) === 0 || self::resolve_owner_uid( $profile_b_id ) === 0 ) {
			return rest_ensure_response( self::error_payload(
				'permission_denied',
				'Bạn không có quyền truy cập một trong hai hồ sơ.',
				'Chọn hồ sơ thuộc tài khoản của bạn hoặc liên hệ quản trị viên.',
				'permission_denied'
			) );
		}

		$subject_a = self::build_chart_subject( $profile_a_id );
		$subject_b = self::build_chart_subject( $profile_b_id );
		if ( is_wp_error( $subject_a ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $subject_a ) );
		}
		if ( is_wp_error( $subject_b ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $subject_b ) );
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( self::error_payload(
				'module_not_loaded',
				'Astro gateway client chưa sẵn sàng.',
				'Tải lại trang hoặc liên hệ quản trị viên.',
				'module_not_loaded',
				true
			) );
		}

		$payload = array(
			'subject_a'    => $subject_a['subject'],
			'subject_b'    => $subject_b['subject'],
			'house_system' => sanitize_text_field( (string) ( $request->get_param( 'house_system' ) ?: 'placidus' ) ),
			'orb_profile'  => sanitize_text_field( (string) ( $request->get_param( 'orb_profile' ) ?: 'standard' ) ),
		);

		$profile_refs = array(
			'a' => array( 'id' => $subject_a['id'], 'name' => $subject_a['name'] ),
			'b' => array( 'id' => $subject_b['id'], 'name' => $subject_b['name'] ),
		);

		// [2026-07-09 Johnny Chu] PHASE-A5 — option cache hit before gateway call.
		$uid       = (int) get_current_user_id();
		$cache_key = self::build_pro_chart_cache_option_key( 'composite', $uid, $payload, $profile_refs );
		$cached    = self::get_pro_chart_cached_response( $cache_key );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$gw = BizCoach_Pro_Astro_Client::composite_western( $payload, array( 'timeout' => 30 ) );
		$response = self::normalize_pro_chart_response( 'composite', $gw, $profile_refs );
		$response = self::with_pro_chart_debug( $response, $cache_key, false, $payload, $gw );
		self::set_pro_chart_cached_response( $cache_key, 'composite', $uid, $payload, $gw, $response );
		return rest_ensure_response( $response );
	}

	/** POST /me/charts/solar-return */
	public static function handle_chart_solar_return( $request ) {
		// [2026-07-09 Johnny Chu] PHASE-A5 — enforce entitlement gate before expensive calls.
		$gate = self::require_chart_feature( 'astro.solar_return' );
		if ( true !== $gate ) {
			return rest_ensure_response( $gate );
		}

		$profile_id  = (int) $request->get_param( 'profile_id' );
		$target_year = (int) $request->get_param( 'target_year' );
		if ( $profile_id <= 0 || $target_year < 1900 || $target_year > 2100 ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Tham số Solar Return không hợp lệ.',
				'Kiểm tra hồ sơ và target_year rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		if ( self::resolve_owner_uid( $profile_id ) === 0 ) {
			return rest_ensure_response( self::error_payload(
				'permission_denied',
				'Bạn không có quyền truy cập hồ sơ này.',
				'Chọn hồ sơ thuộc tài khoản của bạn hoặc liên hệ quản trị viên.',
				'permission_denied'
			) );
		}

		$subject = self::build_chart_subject( $profile_id );
		if ( is_wp_error( $subject ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $subject ) );
		}

		$return_location = self::resolve_return_location( $request, $subject['subject'] );
		if ( is_wp_error( $return_location ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $return_location ) );
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( self::error_payload(
				'module_not_loaded',
				'Astro gateway client chưa sẵn sàng.',
				'Tải lại trang hoặc liên hệ quản trị viên.',
				'module_not_loaded',
				true
			) );
		}

		$payload = array(
			'subject'            => $subject['subject'],
			'target_year'        => $target_year,
			'return_location'    => $return_location,
			'house_system'       => sanitize_text_field( (string) ( $request->get_param( 'house_system' ) ?: 'placidus' ) ),
			'search_window_days' => max( 1, min( 30, (int) ( $request->get_param( 'search_window_days' ) ?: 8 ) ) ),
		);

		$profile_refs = array(
			'a' => array( 'id' => $subject['id'], 'name' => $subject['name'] ),
		);

		// [2026-07-09 Johnny Chu] PHASE-A5 — option cache hit before gateway call.
		$uid       = (int) get_current_user_id();
		$cache_key = self::build_pro_chart_cache_option_key( 'solar_return', $uid, $payload, $profile_refs );
		$cached    = self::get_pro_chart_cached_response( $cache_key );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$gw = BizCoach_Pro_Astro_Client::solar_return_western( $payload, array( 'timeout' => 30 ) );
		$response = self::normalize_pro_chart_response( 'solar_return', $gw, $profile_refs );
		$response = self::with_pro_chart_debug( $response, $cache_key, false, $payload, $gw );
		self::set_pro_chart_cached_response( $cache_key, 'solar_return', $uid, $payload, $gw, $response );
		return rest_ensure_response( $response );
	}

	/** POST /me/charts/lunar-return */
	public static function handle_chart_lunar_return( $request ) {
		// [2026-07-09 Johnny Chu] PHASE-A5 — enforce entitlement gate before expensive calls.
		$gate = self::require_chart_feature( 'astro.lunar_return' );
		if ( true !== $gate ) {
			return rest_ensure_response( $gate );
		}

		$profile_id   = (int) $request->get_param( 'profile_id' );
		$target_year  = (int) $request->get_param( 'target_year' );
		$target_month = (int) $request->get_param( 'target_month' );
		if ( $profile_id <= 0 || $target_year < 1900 || $target_year > 2100 || $target_month < 1 || $target_month > 12 ) {
			return rest_ensure_response( self::error_payload(
				'invalid_param',
				'Tham số Lunar Return không hợp lệ.',
				'Kiểm tra hồ sơ, target_year và target_month rồi thử lại.',
				'invalid_param_generic'
			) );
		}

		if ( self::resolve_owner_uid( $profile_id ) === 0 ) {
			return rest_ensure_response( self::error_payload(
				'permission_denied',
				'Bạn không có quyền truy cập hồ sơ này.',
				'Chọn hồ sơ thuộc tài khoản của bạn hoặc liên hệ quản trị viên.',
				'permission_denied'
			) );
		}

		$subject = self::build_chart_subject( $profile_id );
		if ( is_wp_error( $subject ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $subject ) );
		}

		$return_location = self::resolve_return_location( $request, $subject['subject'] );
		if ( is_wp_error( $return_location ) ) {
			return rest_ensure_response( self::wp_error_to_payload( $return_location ) );
		}

		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return rest_ensure_response( self::error_payload(
				'module_not_loaded',
				'Astro gateway client chưa sẵn sàng.',
				'Tải lại trang hoặc liên hệ quản trị viên.',
				'module_not_loaded',
				true
			) );
		}

		$payload = array(
			'subject'            => $subject['subject'],
			'target_year'        => $target_year,
			'target_month'       => $target_month,
			'return_location'    => $return_location,
			'house_system'       => sanitize_text_field( (string) ( $request->get_param( 'house_system' ) ?: 'placidus' ) ),
			'search_window_days' => max( 1, min( 30, (int) ( $request->get_param( 'search_window_days' ) ?: 4 ) ) ),
		);

		$profile_refs = array(
			'a' => array( 'id' => $subject['id'], 'name' => $subject['name'] ),
		);

		// [2026-07-09 Johnny Chu] PHASE-A5 — option cache hit before gateway call.
		$uid       = (int) get_current_user_id();
		$cache_key = self::build_pro_chart_cache_option_key( 'lunar_return', $uid, $payload, $profile_refs );
		$cached    = self::get_pro_chart_cached_response( $cache_key );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		$gw = BizCoach_Pro_Astro_Client::lunar_return_western( $payload, array( 'timeout' => 30 ) );
		$response = self::normalize_pro_chart_response( 'lunar_return', $gw, $profile_refs );
		$response = self::with_pro_chart_debug( $response, $cache_key, false, $payload, $gw );
		self::set_pro_chart_cached_response( $cache_key, 'lunar_return', $uid, $payload, $gw, $response );
		return rest_ensure_response( $response );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — entitlement gate for PRO chart routes.
	 *
	 * @param string $feature_key
	 * @return true|array
	 */
	private static function require_chart_feature( $feature_key ) {
		$uid = (int) get_current_user_id();
		if ( $uid <= 0 ) {
			return self::error_payload(
				'auth_required',
				'Vui lòng đăng nhập để sử dụng tính năng này.',
				'Đăng nhập rồi thử lại.',
				'auth_required'
			);
		}

		if ( ! class_exists( 'BizCity_LLM_Client' ) ) {
			return self::error_payload(
				'gateway_degraded',
				'Không kết nối được dịch vụ phân quyền.',
				'Thử lại sau vài phút hoặc liên hệ quản trị viên.',
				'gateway_degraded',
				true
			);
		}

		$llm = BizCity_LLM_Client::instance();
		if ( ! $llm || ! method_exists( $llm, 'get_entitlement' ) ) {
			return self::error_payload(
				'gateway_degraded',
				'Không đọc được thông tin gói từ hub.',
				'Thử lại sau vài phút hoặc liên hệ quản trị viên.',
				'gateway_degraded',
				true
			);
		}

		$ent = $llm->get_entitlement( $uid, array( 'timeout' => 8 ) );
		if ( is_wp_error( $ent ) || ! is_array( $ent ) ) {
			$_msg = is_wp_error( $ent ) ? $ent->get_error_message() : 'Không đọc được entitlement từ hub.';
			return self::error_payload(
				'gateway_degraded',
				$_msg,
				'Thử lại sau vài phút hoặc liên hệ quản trị viên.',
				'gateway_degraded',
				true
			);
		}

		$features_in = isset( $ent['features'] ) && is_array( $ent['features'] ) ? $ent['features'] : array();
		$features    = array();
		foreach ( $features_in as $fkey => $fval ) {
			if ( is_string( $fkey ) && $fkey !== '' ) {
				$features[ self::normalize_feature_key( $fkey ) ] = true;
				continue;
			}
			if ( is_string( $fval ) && $fval !== '' ) {
				$features[ self::normalize_feature_key( $fval ) ] = true;
			}
		}

		if ( empty( $features[ self::normalize_feature_key( $feature_key ) ] ) ) {
			return self::error_payload(
				'tier_required',
				'Tính năng này yêu cầu gói PRO trở lên.',
				'Nâng cấp gói để mở khóa tính năng này.',
				'plan_upgrade_required'
			);
		}

		return true;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — normalize feature keys while preserving dot notation.
	 *
	 * @param string $feature_key
	 * @return string
	 */
	private static function normalize_feature_key( $feature_key ) {
		$k = strtolower( trim( (string) $feature_key ) );
		$k = preg_replace( '/[^a-z0-9_.-]+/', '', $k );
		return is_string( $k ) ? $k : '';
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — build canonical subject payload from profile.
	 *
	 * @param int $coachee_id
	 * @return array|WP_Error
	 */
	private static function build_chart_subject( $coachee_id ) {
		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT c.id, c.full_name, c.dob, c.extra_fields_json,
			        a.birth_time, a.birth_place, a.timezone, a.latitude, a.longitude
			 FROM {$t_coach} c
			 LEFT JOIN {$t_astro} a ON a.coachee_id = c.id AND a.chart_type = 'western'
			 WHERE c.id = %d
			 ORDER BY a.id DESC
			 LIMIT 1",
			$coachee_id
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Hồ sơ không tồn tại.' );
		}

		$dob = (string) ( $row['dob'] ?? '' );
		if ( $dob === '' ) {
			return new WP_Error( 'invalid_param', 'Hồ sơ chưa có ngày sinh.' );
		}

		$birth_time = (string) ( $row['birth_time'] ?? '' );
		if ( ! preg_match( '/^\d{1,2}:\d{2}/', $birth_time ) ) {
			$birth_time = '12:00';
		}
		$time_parts = explode( ':', $birth_time );
		$hh = str_pad( (string) max( 0, min( 23, (int) ( $time_parts[0] ?? 12 ) ) ), 2, '0', STR_PAD_LEFT );
		$mm = str_pad( (string) max( 0, min( 59, (int) ( $time_parts[1] ?? 0 ) ) ), 2, '0', STR_PAD_LEFT );
		$birth_time = $hh . ':' . $mm;

		$coords = self::read_birth_coords( (string) ( $row['extra_fields_json'] ?? '' ) );
		$lat    = isset( $coords['lat'] ) ? (float) $coords['lat'] : (float) ( $row['latitude'] ?? 21.0285 );
		$lon    = isset( $coords['lng'] ) ? (float) $coords['lng'] : (float) ( $row['longitude'] ?? 105.8542 );
		$tz     = isset( $coords['tz'] ) ? (string) $coords['tz'] : 'Asia/Ho_Chi_Minh';

		$offset_minutes = (int) round( (float) ( $row['timezone'] ?? 7.0 ) * 60 );
		if ( $offset_minutes === 0 ) {
			try {
				$dt = new DateTime( $dob . ' ' . $birth_time, new DateTimeZone( $tz ) );
				$offset_minutes = (int) round( $dt->getOffset() / 60 );
			} catch ( Exception $e ) {
				$offset_minutes = 420;
			}
		}

		return array(
			'id'   => (int) $row['id'],
			'name' => (string) ( $row['full_name'] ?? ( 'Profile #' . (int) $row['id'] ) ),
			'subject' => array(
				'name'           => (string) ( $row['full_name'] ?? '' ),
				'dob'            => $dob,
				'time'           => $birth_time,
				'lat'            => $lat,
				'lon'            => $lon,
				'offset_minutes' => $offset_minutes,
				'tz'             => $tz,
			),
		);
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — resolve return location (birth|custom).
	 *
	 * @param WP_REST_Request $request
	 * @param array           $subject
	 * @return array|WP_Error
	 */
	private static function resolve_return_location( $request, $subject ) {
		$mode = sanitize_key( (string) ( $request->get_param( 'return_location_mode' ) ?: 'birth' ) );
		if ( $mode !== 'custom' ) {
			return array(
				'lat'            => (float) ( $subject['lat'] ?? 0.0 ),
				'lon'            => (float) ( $subject['lon'] ?? 0.0 ),
				'offset_minutes' => (int) ( $subject['offset_minutes'] ?? 420 ),
				'tz'             => (string) ( $subject['tz'] ?? 'Asia/Ho_Chi_Minh' ),
			);
		}

		$raw = $request->get_param( 'return_location' );
		if ( ! is_array( $raw ) ) {
			return new WP_Error( 'invalid_param', 'return_location không hợp lệ.' );
		}

		$lat = isset( $raw['lat'] ) ? (float) $raw['lat'] : null;
		$lon = isset( $raw['lon'] ) ? (float) $raw['lon'] : null;
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 ) {
			return new WP_Error( 'invalid_param', 'Toạ độ return_location không hợp lệ.' );
		}

		$offset_minutes = isset( $raw['offset_minutes'] ) ? (int) $raw['offset_minutes'] : (int) ( $subject['offset_minutes'] ?? 420 );
		$tz             = isset( $raw['tz'] ) ? sanitize_text_field( (string) $raw['tz'] ) : (string) ( $subject['tz'] ?? 'Asia/Ho_Chi_Minh' );

		return array(
			'lat'            => $lat,
			'lon'            => $lon,
			'offset_minutes' => $offset_minutes,
			'tz'             => $tz,
		);
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — deterministic option key for pro chart cache.
	 *
	 * @param string $chart_type
	 * @param int    $uid
	 * @param array  $payload
	 * @param array  $profile_refs
	 * @return string
	 */
	private static function build_pro_chart_cache_option_key( $chart_type, $uid, $payload, $profile_refs ) {
		$fingerprint = md5( wp_json_encode( array(
			'schema'       => self::PRO_CHART_CACHE_SCHEMA,
			'chart_type'   => (string) $chart_type,
			'uid'          => (int) $uid,
			'profile_refs' => (array) $profile_refs,
			'payload'      => (array) $payload,
		) ) );

		return self::PRO_CHART_CACHE_OPTION_PREFIX . $fingerprint;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — user index option key for cache invalidation.
	 *
	 * @param int $uid
	 * @return string
	 */
	private static function get_pro_chart_cache_index_key( $uid ) {
		return self::PRO_CHART_CACHE_INDEX_PREFIX . (int) $uid;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — register cache key into per-user index.
	 *
	 * @param int    $uid
	 * @param string $option_key
	 * @return void
	 */
	private static function track_pro_chart_cache_key( $uid, $option_key ) {
		$uid = (int) $uid;
		$key = (string) $option_key;
		if ( $uid <= 0 || $key === '' ) {
			return;
		}

		$index_key = self::get_pro_chart_cache_index_key( $uid );
		$keys      = get_option( $index_key, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		if ( ! in_array( $key, $keys, true ) ) {
			$keys[] = $key;
			update_option( $index_key, array_values( $keys ), false );
		}
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — invalidate all pro-chart cache entries for one user.
	 *
	 * @param int $uid
	 * @return void
	 */
	private static function invalidate_pro_chart_cache_for_user( $uid ) {
		$uid = (int) $uid;
		if ( $uid <= 0 ) {
			return;
		}

		$index_key = self::get_pro_chart_cache_index_key( $uid );
		$keys      = get_option( $index_key, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		// Legacy fallback: pre-index cache entries (created before this patch).
		if ( empty( $keys ) ) {
			global $wpdb;
			$like = $wpdb->esc_like( self::PRO_CHART_CACHE_OPTION_PREFIX ) . '%';
			$all  = $wpdb->get_col( $wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			) );
			if ( is_array( $all ) ) {
				foreach ( $all as $_opt_key ) {
					$_opt = get_option( (string) $_opt_key, null );
					if ( is_array( $_opt ) && (int) ( $_opt['uid'] ?? 0 ) === $uid ) {
						$keys[] = (string) $_opt_key;
					}
				}
			}
		}

		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		foreach ( $keys as $_opt_key ) {
			if ( $_opt_key !== '' ) {
				delete_option( $_opt_key );
			}
		}

		delete_option( $index_key );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — read cached pro chart response from option.
	 *
	 * @param string $option_key
	 * @return array|null
	 */
	private static function get_pro_chart_cached_response( $option_key ) {
		$cached = get_option( (string) $option_key, null );
		if ( ! is_array( $cached ) ) {
			return null;
		}

		$expires_at = isset( $cached['expires_at'] ) ? (int) $cached['expires_at'] : 0;
		if ( $expires_at > 0 && $expires_at < time() ) {
			delete_option( (string) $option_key );
			if ( isset( $cached['uid'] ) ) {
				$index_key = self::get_pro_chart_cache_index_key( (int) $cached['uid'] );
				$keys      = get_option( $index_key, array() );
				if ( is_array( $keys ) && ! empty( $keys ) ) {
					$_left = array_values( array_filter( array_map( 'strval', $keys ), function ( $_k ) use ( $option_key ) {
						return $_k !== (string) $option_key;
					} ) );
					if ( ! empty( $_left ) ) {
						update_option( $index_key, $_left, false );
					} else {
						delete_option( $index_key );
					}
				}
			}
			return null;
		}

		$response = isset( $cached['response'] ) && is_array( $cached['response'] )
			? $cached['response']
			: null;
		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			return null;
		}

		if ( ! isset( $response['meta'] ) || ! is_array( $response['meta'] ) ) {
			$response['meta'] = array();
		}
		$response['meta']['cached'] = true;

		$debug = isset( $response['_debug'] ) && is_array( $response['_debug'] )
			? $response['_debug']
			: array();
		$debug['cache'] = array(
			'hit'         => true,
			'storage'     => 'wp_option',
			'option_key'  => (string) $option_key,
			'saved_at'    => isset( $cached['saved_at'] ) ? (string) $cached['saved_at'] : '',
			'expires_at'  => $expires_at > 0 ? gmdate( 'c', $expires_at ) : '',
			'ttl_seconds' => $expires_at > 0 ? max( 0, $expires_at - time() ) : 0,
		);
		if ( isset( $cached['gateway_response'] ) && is_array( $cached['gateway_response'] ) ) {
			$debug['gateway_json'] = $cached['gateway_response'];
		}
		if ( isset( $cached['request_payload'] ) && is_array( $cached['request_payload'] ) ) {
			$debug['request_payload'] = $cached['request_payload'];
		}
		$response['_debug'] = $debug;

		return $response;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — persist success response + raw gateway JSON to option.
	 *
	 * @param string $option_key
	 * @param string $chart_type
	 * @param int    $uid
	 * @param array  $payload
	 * @param array  $gateway
	 * @param array  $response
	 * @return void
	 */
	private static function set_pro_chart_cached_response( $option_key, $chart_type, $uid, $payload, $gateway, $response ) {
		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			return;
		}

		$now = time();
		$ttl = (int) self::PRO_CHART_CACHE_TTL;
		$expires_at = $now + max( 30, $ttl );

		$record = array(
			'schema'           => self::PRO_CHART_CACHE_SCHEMA,
			'chart_type'       => (string) $chart_type,
			'uid'              => (int) $uid,
			'saved_at'         => gmdate( 'c', $now ),
			'expires_at'       => $expires_at,
			'ttl_seconds'      => max( 30, $ttl ),
			'request_payload'  => is_array( $payload ) ? $payload : array(),
			'gateway_response' => is_array( $gateway ) ? $gateway : array(),
			'response'         => $response,
		);

		update_option( (string) $option_key, $record, false );
		self::track_pro_chart_cache_key( (int) $uid, (string) $option_key );
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — inject debug metadata/json into response.
	 *
	 * @param array  $response
	 * @param string $option_key
	 * @param bool   $cache_hit
	 * @param array  $payload
	 * @param array  $gateway
	 * @return array
	 */
	private static function with_pro_chart_debug( $response, $option_key, $cache_hit, $payload, $gateway ) {
		if ( ! is_array( $response ) ) {
			return $response;
		}

		$debug = isset( $response['_debug'] ) && is_array( $response['_debug'] )
			? $response['_debug']
			: array();

		$debug['cache'] = array(
			'hit'         => (bool) $cache_hit,
			'storage'     => 'wp_option',
			'option_key'  => (string) $option_key,
			'ttl_seconds' => (int) self::PRO_CHART_CACHE_TTL,
		);
		$debug['request_payload'] = is_array( $payload ) ? $payload : array();
		$debug['gateway_json']    = is_array( $gateway ) ? $gateway : array();

		$response['_debug'] = $debug;
		return $response;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — normalize gateway response for FE.
	 *
	 * @param string $chart_type
	 * @param array  $gateway
	 * @param array  $profile_refs
	 * @return array
	 */
	private static function normalize_pro_chart_response( $chart_type, $gateway, $profile_refs ) {
		if ( ! is_array( $gateway ) ) {
			return self::error_payload(
				'gateway_degraded',
				'Hub không trả phản hồi hợp lệ.',
				'Thử lại sau vài phút.',
				'gateway_degraded',
				true
			);
		}

		$env = isset( $gateway['envelope'] ) && is_array( $gateway['envelope'] ) ? $gateway['envelope'] : array();
		if ( empty( $gateway['success'] ) || ( isset( $env['success'] ) && empty( $env['success'] ) ) ) {
			$code = sanitize_key( (string) ( $env['code'] ?? '' ) );
			if ( $code === 'tier_required' ) {
				return self::error_payload(
					'tier_required',
					'Tính năng này yêu cầu gói PRO trở lên.',
					'Nâng cấp gói để mở khóa tính năng này.',
					'plan_upgrade_required'
				);
			}

			$message = (string) ( $env['message'] ?? $gateway['error'] ?? 'Gateway chưa sẵn sàng cho endpoint này.' );
			$help    = $code !== '' ? $code : 'gateway_degraded';
			return self::error_payload(
				'gateway_degraded',
				$message,
				'Kiểm tra kết nối hub hoặc thử lại sau vài phút.',
				$help,
				true
			);
		}

		$data = isset( $env['data'] ) ? $env['data'] : $env;
		$cache = isset( $env['cache'] ) && is_array( $env['cache'] ) ? $env['cache'] : array();

		return array(
			'success'      => true,
			'chart_type'   => $chart_type,
			'profile_refs' => $profile_refs,
			'data'         => $data,
			'meta'         => array(
				'provider'   => (string) ( $env['provider'] ?? '' ),
				'latency_ms' => isset( $env['latency_ms'] ) ? (int) $env['latency_ms'] : (int) ( $gateway['http']['latency_ms'] ?? 0 ),
				'cached'     => ! empty( $cache['hit'] ) || ! empty( $env['cached'] ),
			),
			'_degraded'    => ! empty( $env['_degraded'] ),
		);
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — unified error payload builder.
	 *
	 * @param string $code
	 * @param string $message
	 * @param string $hint
	 * @param string $help_code
	 * @param bool   $degraded
	 * @return array
	 */
	private static function error_payload( $code, $message, $hint, $help_code, $degraded = false ) {
		$payload = array(
			'success'   => false,
			'code'      => (string) $code,
			'message'   => (string) $message,
			'hint'      => (string) $hint,
			'help_code' => (string) $help_code,
		);

		if ( class_exists( 'BizCity_Error_Payload' ) ) {
			$_from_helper = BizCity_Error_Payload::make( (string) $code, (string) $message, (string) $hint, (string) $help_code );
			if ( is_array( $_from_helper ) ) {
				$payload = array_merge( $payload, $_from_helper );
			}
		}

		if ( $degraded ) {
			$payload['_degraded'] = true;
		}

		return $payload;
	}

	/**
	 * [2026-07-09 Johnny Chu] PHASE-A5 — convert WP_Error to R-ERROR-UX payload.
	 *
	 * @param WP_Error $error
	 * @return array
	 */
	private static function wp_error_to_payload( $error ) {
		$code = is_wp_error( $error ) ? (string) $error->get_error_code() : 'invalid_param';
		$msg  = is_wp_error( $error ) ? (string) $error->get_error_message() : 'Yêu cầu không hợp lệ.';
		return self::error_payload(
			$code ?: 'invalid_param',
			$msg !== '' ? $msg : 'Yêu cầu không hợp lệ.',
			'Kiểm tra dữ liệu đầu vào rồi thử lại.',
			'invalid_param_generic'
		);
	}

	/* ------------------------------------------------------------------
	 * [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT BE-4 — Relations handlers
	 * ------------------------------------------------------------------ */

	/** Permission: relation belongs to current user. */
	public static function can_own_relation( $request ) {
		if ( ! is_user_logged_in() ) { return false; }
		if ( current_user_can( 'manage_options' ) ) { return true; }
		global $wpdb;
		$rid = (int) $request->get_param( 'id' );
		$uid = (int) get_current_user_id();
		if ( $rid <= 0 ) { return false; }
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}bccm_astro_relations WHERE id=%d AND user_id=%d LIMIT 1",
			$rid, $uid
		) );
	}

	/** GET /me/relations — list all relations of the current user's chính chủ. */
	public static function list_relations( $request ) {
		$uid = (int) get_current_user_id();
		if ( ! function_exists( 'bccm_get_self_coachee' ) ) {
			return rest_ensure_response( array( 'success' => true, 'relations' => array() ) );
		}
		$self = bccm_get_self_coachee( $uid );
		if ( ! $self ) {
			return rest_ensure_response( array( 'success' => true, 'relations' => array(), '_no_self' => true ) );
		}
		$owner_id  = (int) $self['id'];
		$relations = class_exists( 'BizCoach_Pro_Relation_Manager' )
			? BizCoach_Pro_Relation_Manager::instance()->get_for_owner( $owner_id )
			: array();
		// Attach subject name from bccm_coachees
		if ( ! empty( $relations ) ) {
			global $wpdb;
			$subject_ids = array_unique( array_map( function( $r ) { return (int) $r['subject_coachee']; }, $relations ) );
			$placeholders = implode( ',', array_fill( 0, count( $subject_ids ), '%d' ) );
			$names_rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, full_name, dob FROM {$wpdb->prefix}bccm_coachees WHERE id IN ($placeholders)",
				...$subject_ids
			), ARRAY_A );
			$names_map = array();
			foreach ( (array) $names_rows as $nr ) {
				$names_map[ (int) $nr['id'] ] = $nr;
			}
			foreach ( $relations as &$rel ) {
				$s_id           = (int) $rel['subject_coachee'];
				$rel['subject'] = isset( $names_map[ $s_id ] ) ? $names_map[ $s_id ] : null;
				// Parse score_json to extract kootams summary
				if ( ! empty( $rel['score_json'] ) ) {
					$decoded = json_decode( $rel['score_json'], true );
					$rel['score_parsed'] = is_array( $decoded ) ? $decoded : null;
					unset( $rel['score_json'] ); // keep payload lean
				}
				if ( ! empty( $rel['interpretation'] ) ) {
					$rel['interpretation'] = json_decode( $rel['interpretation'], true );
				}
			}
			unset( $rel );
		}
		return rest_ensure_response( array( 'success' => true, 'relations' => $relations ) );
	}

	/** GET /me/relations/{id} — single relation detail. */
	public static function get_relation( $request ) {
		$rid = (int) $request->get_param( 'id' );
		if ( ! class_exists( 'BizCoach_Pro_Relation_Manager' ) ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — normalize relation error payload.
			return BizCity_Error_Payload::make(
				'module_not_loaded',
				'Module quan hệ chưa được tải.',
				'Kiểm tra plugin đã bật và tải lại trang.',
				'module_not_loaded'
			);
		}
		$row = BizCoach_Pro_Relation_Manager::instance()->get( $rid );
		if ( ! $row ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — normalize relation not-found payload.
			return BizCity_Error_Payload::make(
				'not_found',
				'Quan hệ không tồn tại hoặc đã bị xóa.',
				'Kiểm tra lại mục quan hệ và thử làm mới danh sách.',
				'not_found'
			);
		}
		if ( ! empty( $row['score_json'] ) ) {
			$row['score_parsed'] = json_decode( $row['score_json'], true );
		}
		if ( ! empty( $row['interpretation'] ) ) {
			$row['interpretation'] = json_decode( $row['interpretation'], true );
		}
		return rest_ensure_response( array( 'success' => true, 'relation' => $row ) );
	}

	/**
	 * POST /me/relations — create or fetch Ashtakoot relation for subject_coachee.
	 * Calls gateway Ashtakoot and saves score.
	 */
	public static function create_relation( $request ) {
		$uid             = (int) get_current_user_id();
		$subject_coachee = (int) $request->get_param( 'subject_coachee' );
		$relation_type = sanitize_key( (string) ( $request->get_param( 'relation_type' ) ?? 'general' ) );
		// [2026-07-04 Johnny Chu] PHASE-FAA2-NEXT — R-COACHEE.7: whitelist guard (belt-and-suspenders)
		$_allowed_types = array( 'general', 'spouse', 'partner', 'family', 'colleague', 'employee', 'friend', 'customer', 'business_partner' );
		if ( ! in_array( $relation_type, $_allowed_types, true ) ) { $relation_type = 'general'; }

		if ( ! function_exists( 'bccm_get_self_coachee' ) ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — use canonical help_code for module load errors.
			return BizCity_Error_Payload::make( 'module_not_loaded', 'Module chính chủ chưa sẵn sàng.', 'Thử tải lại trang.', 'module_not_loaded' );
		}

		$self = bccm_get_self_coachee( $uid );
		if ( ! $self ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — use not_found help_code for missing resource.
			return BizCity_Error_Payload::make( 'not_found', 'Bạn chưa có hồ sơ chính chủ.', 'Tạo hồ sơ chính chủ trước.', 'not_found' );
		}
		$owner_coachee = (int) $self['id'];
		if ( $subject_coachee <= 0 || $subject_coachee === $owner_coachee ) {
			return BizCity_Error_Payload::make( 'invalid_param', 'Subject không hợp lệ.', 'Chọn một hồ sơ khác để so khớp.', 'invalid_param_generic' );
		}

		if ( ! class_exists( 'BizCoach_Pro_Relation_Manager' ) ) {
			return BizCity_Error_Payload::make( 'module_not_loaded', 'Relation Manager chưa load.', 'Liên hệ admin.', 'module_not_loaded' );
		}
		$mgr = BizCoach_Pro_Relation_Manager::instance();
		$relation_id = $mgr->upsert( $owner_coachee, $subject_coachee, 'ashtakoot', array(
			'user_id' => $uid, 'relation_type' => $relation_type,
		) );

		if ( ! $relation_id ) {
			return BizCity_Error_Payload::make( 'invalid_param', 'Không thể tạo relation.', 'Thử lại sau.', 'invalid_param_generic' );
		}

		// Attempt Ashtakoot computation now (synchronous for simplicity)
		$score_result = self::_compute_ashtakoot( $owner_coachee, $subject_coachee );
		if ( ! empty( $score_result['success'] ) ) {
			$mgr->save_score( $relation_id, $score_result['envelope'] );
		}

		$relation = $mgr->get( $relation_id );
		return rest_ensure_response( array(
			'success'         => true,
			'relation_id'     => $relation_id,
			'score_computed'  => ! empty( $score_result['success'] ),
			'score_message'   => $score_result['message'] ?? '',
			'relation'        => $relation,
		) );
	}

	/**
	 * POST /me/relations/{id}/interpret — generate LLM interpretation for a relation.
	 */
	public static function interpret_relation( $request ) {
		$rid = (int) $request->get_param( 'id' );
		if ( ! class_exists( 'BizCoach_Pro_Relation_Manager' ) ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — use canonical help_code for module load errors.
			return BizCity_Error_Payload::make( 'module_not_loaded', 'Relation Manager chưa load.', 'Liên hệ admin.', 'module_not_loaded' );
		}
		$mgr = BizCoach_Pro_Relation_Manager::instance();
		$row = $mgr->get( $rid );
		if ( ! $row ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — normalize relation not-found payload.
			return BizCity_Error_Payload::make(
				'not_found',
				'Quan hệ không tồn tại hoặc đã bị xóa.',
				'Kiểm tra lại mục quan hệ và thử làm mới danh sách.',
				'not_found'
			);
		}
		// Return cached interpretation if already generated
		if ( ! empty( $row['interpretation'] ) ) {
			$interp = json_decode( $row['interpretation'], true );
			if ( ! empty( $interp['sections'] ) ) {
				return rest_ensure_response( array( 'success' => true, 'interpretation' => $interp, '_cached' => true ) );
			}
		}
		if ( empty( $row['score_json'] ) ) {
			return BizCity_Error_Payload::make( 'invalid_param', 'Chưa có điểm Ashtakoot.', 'Tính điểm trước khi sinh luận giải.', 'invalid_param_generic' );
		}
		// Use LLM to generate interpretation
		if ( ! class_exists( 'BizCity_LLM_Client' ) || ! BizCity_LLM_Client::instance()->is_ready() ) {
			// [2026-07-07 Johnny Chu] R-ERROR-UX — explicit degraded payload for gateway readiness.
			return BizCity_Error_Payload::make(
				'gateway_degraded',
				'Gateway AI chưa kết nối.',
				'Kiểm tra API key và thử lại sau vài phút.',
				'gateway_degraded'
			);
		}
		$score_data = json_decode( $row['score_json'], true );
		$total      = $row['total_score'] ?? 0;
		$out_of     = $row['out_of'] ?? 36;
		$subject_name = '';
		if ( ! empty( $row['subject_coachee'] ) ) {
			global $wpdb;
			$subject_name = (string) $wpdb->get_var( $wpdb->prepare(
				"SELECT full_name FROM {$wpdb->prefix}bccm_coachees WHERE id=%d LIMIT 1",
				(int) $row['subject_coachee']
			) );
		}
		$prompt = "Dựa trên điểm Ashtakoot (Vedic compatibility) giữa chính chủ và {$subject_name}:\n"
			. "Tổng điểm: {$total}/{$out_of}\n"
			. "Chi tiết 8 kootam: " . wp_json_encode( $score_data, JSON_UNESCAPED_UNICODE ) . "\n\n"
			. "Hãy luận giải ngắn gọn (3-5 đoạn) bằng tiếng Việt: "
			. "1. Tổng quan mức độ hòa hợp, 2. Điểm nổi bật tốt, 3. Điểm cần chú ý, 4. Lời khuyên.";
		$resp = BizCity_LLM_Client::instance()->chat( array(
			array( 'role' => 'user', 'content' => $prompt ),
		), array( 'purpose' => 'interpretation', 'max_tokens' => 800 ) );
		if ( empty( $resp['success'] ) || empty( $resp['message'] ) ) {
			return BizCity_Error_Payload::make(
				'gateway_degraded',
				'LLM chưa phản hồi kết quả luận giải.',
				'Thử lại sau vài phút.',
				'gateway_degraded'
			);
		}
		$sections = array( array( 'title' => 'Luận giải quan hệ', 'content' => $resp['message'] ) );
		$mgr->save_interpretation( $rid, $sections );
		return rest_ensure_response( array( 'success' => true, 'interpretation' => array( 'sections' => $sections, 'generated' => gmdate( 'c' ) ) ) );
	}

	/**
	 * Internal: compute Ashtakoot score via gateway client.
	 * Returns { success, envelope, message }.
	 */
	private static function _compute_ashtakoot( $owner_coachee, $subject_coachee ) {
		// [2026-07-07 Johnny Chu] PHASE-FAA2-NEXT — P0 wrapper mismatch fix: use canonical BizCoach wrapper.
		if ( ! class_exists( 'BizCoach_Pro_Astro_Client' ) ) {
			return array( 'success' => false, 'message' => 'BizCoach_Pro_Astro_Client chưa load.' );
		}

		global $wpdb;
		$t_coach = $wpdb->prefix . 'bccm_coachees';
		$t_astro = $wpdb->prefix . 'bccm_astro';

		// Load birth data for both coachees
		$load = function( $cid ) use ( $wpdb, $t_coach, $t_astro ) {
			$c = $wpdb->get_row( $wpdb->prepare( "SELECT dob, extra_fields_json FROM `{$t_coach}` WHERE id=%d LIMIT 1", $cid ), ARRAY_A );
			$a = $wpdb->get_row( $wpdb->prepare( "SELECT birth_time, latitude, longitude, timezone FROM `{$t_astro}` WHERE coachee_id=%d AND chart_type='western' ORDER BY id DESC LIMIT 1", $cid ), ARRAY_A );
			return array( 'c' => $c, 'a' => $a );
		};

		$owner_data   = $load( $owner_coachee );
		$subject_data = $load( $subject_coachee );

		// Build payload helper
		$build_person = function( $data ) {
			$c = $data['c'];
			$a = $data['a'];
			if ( ! $c || ! $a ) { return null; }
			$dob  = $c['dob'] ?? '';
			$bt   = $a['birth_time'] ?? '';
			$lat  = (float) ( $a['latitude'] ?? 0 );
			$lng  = (float) ( $a['longitude'] ?? 0 );
			$tz   = (float) ( $a['timezone'] ?? 7.0 );
			if ( ! $dob ) { return null; }
			$d    = explode( '-', $dob );
			$year = (int) ( $d[0] ?? 0 );
			$mon  = (int) ( $d[1] ?? 0 );
			$day  = (int) ( $d[2] ?? 0 );
			$hrs  = 12; $mins = 0; $secs = 0; // default noon if no birth_time
			if ( $bt && preg_match( '/^(\d{1,2}):(\d{2})/', $bt, $m ) ) {
				$hrs  = (int) $m[1];
				$mins = (int) $m[2];
			}
			if ( ! $year || ! $lat ) { return null; }
			return array(
				'year' => $year, 'month' => $mon, 'date' => $day,
				'hours' => $hrs, 'minutes' => $mins, 'seconds' => $secs,
				'latitude' => $lat, 'longitude' => $lng, 'timezone' => $tz,
			);
		};

		$owner_person   = $build_person( $owner_data );
		$subject_person = $build_person( $subject_data );

		if ( ! $owner_person || ! $subject_person ) {
			return array( 'success' => false, 'message' => 'Thiếu ngày/nơi sinh cho một trong hai hồ sơ. Vui lòng bổ sung để tính hợp.' );
		}

		// By convention male=owner, female=subject (flag for FE if assumed)
		$payload = array(
			'male'   => $owner_person,
			'female' => $subject_person,
			'config' => array( 'observation_point' => 'topocentric', 'language' => 'en', 'ayanamsha' => 'lahiri' ),
			'_gender_assumed' => true,
		);

		$resp = BizCoach_Pro_Astro_Client::ashtakoot( $payload );
		if ( ! is_array( $resp ) || empty( $resp['success'] ) ) {
			$resp_env = ( is_array( $resp ) && ! empty( $resp['envelope'] ) && is_array( $resp['envelope'] ) )
				? $resp['envelope']
				: array();
			$err_msg = ( is_array( $resp ) && ! empty( $resp['error'] ) )
				? (string) $resp['error']
				: ( $resp_env['message'] ?? 'Ashtakoot API lỗi.' );
			return array( 'success' => false, 'message' => $err_msg );
		}

		$resp_env = ! empty( $resp['envelope'] ) && is_array( $resp['envelope'] )
			? $resp['envelope']
			: array();

		$envelope = array(
			'out_of'      => $resp_env['out_of'] ?? 36,
			'total_score' => $resp_env['total_score'] ?? null,
			'kootams'     => $resp_env['kootams'] ?? array(),
			'raw'         => $resp_env['raw'] ?? $resp_env,
		);
		return array( 'success' => true, 'envelope' => $envelope, 'message' => '' );
	}
}

