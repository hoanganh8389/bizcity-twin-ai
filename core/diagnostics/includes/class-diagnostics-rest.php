<?php
/**
 * Diagnostics REST — GET /wp-json/bizcity-diagnostics/v1/tables
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-20
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_REST {

	private static $instance = null;

	public static function instance(): self {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/tables', [
			'methods'             => 'GET',
			'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			'callback'            => function () {
				return rest_ensure_response( [
					'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
					'summary' => BizCity_Diagnostics_Table_Inspector::summary(),
					'tables'  => BizCity_Diagnostics_Table_Inspector::inspect_all(),
				] );
			},
		] );

		// Error Reporter telemetry — public POST, rate-limited per IP.
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/error-report', [
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'submit_error_report' ],
			'args'                => [
				'code'        => [ 'type' => 'string', 'required' => true ],
				'module'      => [ 'type' => 'string' ],
				'http_status' => [ 'type' => 'integer' ],
				'title'       => [ 'type' => 'string' ],
				'detail'      => [ 'type' => 'string' ],
				'context'     => [ 'type' => 'object' ],
				'url'         => [ 'type' => 'string' ],
				'source'      => [ 'type' => 'string' ],
			],
		] );

		// ── Phase 0.41 L9.a — Smoke Wizard endpoints ─────────────────────
		$admin_only = function () { return current_user_can( 'manage_options' ); };

		// GET /smoke/probes — catalog (sorted, JSON-safe).
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/probes', [
			'methods'             => 'GET',
			'permission_callback' => $admin_only,
			'callback'            => function () {
				return rest_ensure_response( [
					'probes'       => BizCity_Diagnostics_Smoke_Runner::describe_catalog(),
					// Phase 0.41 L9.f — persisted per-probe last result so the
					// FE wizard can show prior PASS/FAIL chips on open without
					// re-running. Map keyed by probe id.
					'last_results' => BizCity_Diagnostics_Smoke_Runner::get_last_results(),
				] );
			},
		] );

		// POST /smoke/run — run single probe by id.
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/run', [
			'methods'             => 'POST',
			'permission_callback' => $admin_only,
			'callback'            => [ $this, 'run_smoke_probe' ],
			'args'                => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// POST /smoke/run-all — run sequential, returns aggregate.
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/run-all', [
			'methods'             => 'POST',
			'permission_callback' => $admin_only,
			'callback'            => function () {
				return rest_ensure_response( BizCity_Diagnostics_Smoke_Runner::run_all() );
			},
		] );

		// POST /smoke/auto-fix-all — Phase 0.41 L9.b+ — installers + JSON auto-create sweep.
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/auto-fix-all', [
			'methods'             => 'POST',
			'permission_callback' => $admin_only,
			'callback'            => function () {
				return rest_ensure_response( BizCity_Diagnostics_Smoke_Runner::auto_fix_all() );
			},
		] );

		// GET /smoke/installers — list registered installers (mirror admin page registry).
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/installers', [
			'methods'             => 'GET',
			'permission_callback' => $admin_only,
			'callback'            => function () {
				if ( ! class_exists( 'BizCity_Site_Provisioner' ) ) {
					return rest_ensure_response( [ 'installers' => [] ] );
				}
				$out = [];
				foreach ( BizCity_Site_Provisioner::get_installers() as $i ) {
					$out[] = [
						'id'           => (string) ( $i['id'] ?? '' ),
						'label'        => (string) ( $i['label'] ?? '' ),
						'version_opt'  => (string) ( $i['version_opt'] ?? '' ),
						'expected_ver' => (string) ( $i['expected_ver'] ?? '' ),
						'current_ver'  => ! empty( $i['version_opt'] ) ? (string) get_option( $i['version_opt'], '' ) : '',
					];
				}
				return rest_ensure_response( [ 'installers' => $out ] );
			},
		] );

		// POST /smoke/run-installer — single installer by id (mirror admin URL bizcity_run_installer=).
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/run-installer', [
			'methods'             => 'POST',
			'permission_callback' => $admin_only,
			'callback'            => function ( $req ) {
				$id = sanitize_key( (string) $req->get_param( 'id' ) );
				if ( $id === '' || ! class_exists( 'BizCity_Site_Provisioner' ) ) {
					return new WP_Error( 'invalid_id', 'Missing installer id or provisioner not loaded.', [ 'status' => 400 ] );
				}
				$row = BizCity_Site_Provisioner::run_one( $id, true );
				if ( $row === null ) {
					return new WP_Error( 'not_found', 'Installer id not registered.', [ 'status' => 404 ] );
				}
				return rest_ensure_response( $row );
			},
			'args' => [
				'id' => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// GET /wizard/eligibility — first-time + critical-regression check.
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/wizard/eligibility', [
			'methods'             => 'GET',
			'permission_callback' => $admin_only,
			'callback'            => [ $this, 'wizard_eligibility' ],
		] );

		// POST /wizard/mark-seen — flip user meta so modal does not auto-show.
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/wizard/mark-seen', [
			'methods'             => 'POST',
			'permission_callback' => $admin_only,
			'callback'            => [ $this, 'wizard_mark_seen' ],
		] );

		// [2026-06-05 Johnny Chu] R-ERROR-UX — Admin error-log viewer endpoints.

		// GET /error-reports — paginated list of stored error reports (admin only).
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/error-reports', [
			'methods'             => 'GET',
			'permission_callback' => $admin_only,
			'callback'            => [ $this, 'get_error_reports' ],
			'args'                => [
				'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
				'per_page' => [ 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 200 ],
				'code'     => [ 'type' => 'string' ],
				'module'   => [ 'type' => 'string' ],
			],
		] );

		// DELETE /error-reports — clear all stored reports (admin only).
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/error-reports', [
			'methods'             => 'DELETE',
			'permission_callback' => $admin_only,
			'callback'            => [ $this, 'clear_error_reports' ],
		] );
	}

	/**
	 * POST /error-report — accept a user-facing error report, persist via
	 * BizCity_Error_Reporter::record(), enforce a per-IP rate-limit.
	 *
	 * @param WP_REST_Request $req
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_error_report( $req ) {
		if ( ! class_exists( 'BizCity_Error_Reporter' ) ) {
			return new WP_Error( 'reporter_unavailable', 'Error reporter not loaded.', [ 'status' => 503 ] );
		}
		if ( ! BizCity_Error_Reporter::rate_check() ) {
			return new WP_Error( 'rate_limited', 'Too many reports. Try again later.', [ 'status' => 429 ] );
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

		$id = BizCity_Error_Reporter::record( [
			'code'        => (string) $req->get_param( 'code' ),
			'module'      => (string) $req->get_param( 'module' ),
			'http_status' => (int)    $req->get_param( 'http_status' ),
			'title'       => (string) $req->get_param( 'title' ),
			'detail'      => (string) $req->get_param( 'detail' ),
			'context'     => (array)  ( $req->get_param( 'context' ) ?: [] ),
			'url'         => (string) $req->get_param( 'url' ),
			'user_agent'  => $ua,
			'source'      => (string) ( $req->get_param( 'source' ) ?: 'fe' ),
		] );

		return rest_ensure_response( [
			'ok' => true,
			'id' => $id,
		] );
	}

	// ─── Phase 0.41 L9.a — Smoke Wizard handlers ────────────────────────

	private const WIZARD_SEEN_META_PREFIX        = 'bizcity_diag_wizard_seen_blog_';
	private const WIZARD_CRITICAL_LAST_USERMETA  = 'bizcity_diag_wizard_critical_last_shown';
	private const WIZARD_CRITICAL_CAP_SECONDS    = 86400; // 24h

	/**
	 * POST /smoke/run — wrap runner with WP_Error envelope on bad input.
	 */
	public function run_smoke_probe( $req ) {
		$id      = (string) $req->get_param( 'id' );
		$catalog = BizCity_Diagnostics_Smoke_Runner::catalog();
		if ( ! isset( $catalog[ $id ] ) ) {
			return new WP_Error( 'unknown_probe', sprintf( 'Probe id "%s" không tồn tại.', $id ), [ 'status' => 404 ] );
		}
		$res = BizCity_Diagnostics_Smoke_Runner::run_probe( $id );
		return rest_ensure_response( $res );
	}

	/**
	 * GET /wizard/eligibility
	 *
	 * Trả về { should_show, reason, critical_issues? }. Reason:
	 *   - 'first-time'          → user chưa xem wizard cho blog này.
	 *   - 'critical-regression' → table critical missing AND override cap đã hết.
	 *   - 'none'                → đã xem rồi và không có critical issue.
	 */
	public function wizard_eligibility() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$user_id = get_current_user_id();
		$meta_key = self::WIZARD_SEEN_META_PREFIX . $blog_id;
		// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
		$mc   = class_exists( 'BizCity_User_Meta_Cache' );
		$seen = (int) ( $mc ? BizCity_User_Meta_Cache::get( $user_id, $meta_key, 0 ) : get_user_meta( $user_id, $meta_key, true ) );

		// Critical regression check — only if table inspector is available.
		$critical_missing = [];
		if ( class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			$rows = BizCity_Diagnostics_Table_Inspector::inspect_all();
			foreach ( $rows as $r ) {
				if ( ! empty( $r['critical'] ) && empty( $r['exists'] ) ) {
					$critical_missing[] = $r['physical'] ?? $r['name'] ?? '?';
				}
			}
		}

		if ( ! $seen ) {
			return rest_ensure_response( [
				'should_show'      => true,
				'reason'           => 'first-time',
				'critical_issues'  => $critical_missing,
			] );
		}

		if ( $critical_missing ) {
			$last_shown = (int) ( $mc ? BizCity_User_Meta_Cache::get( $user_id, self::WIZARD_CRITICAL_LAST_USERMETA, 0 ) : get_user_meta( $user_id, self::WIZARD_CRITICAL_LAST_USERMETA, true ) );
			if ( ( time() - $last_shown ) > self::WIZARD_CRITICAL_CAP_SECONDS ) {
				update_user_meta( $user_id, self::WIZARD_CRITICAL_LAST_USERMETA, time() );
				return rest_ensure_response( [
					'should_show'     => true,
					'reason'          => 'critical-regression',
					'critical_issues' => $critical_missing,
				] );
			}
		}

		return rest_ensure_response( [
			'should_show'     => false,
			'reason'          => 'none',
			'critical_issues' => $critical_missing,
		] );
	}

	/**
	 * POST /wizard/mark-seen — persist user_meta so first-time gate flips.
	 */
	public function wizard_mark_seen() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$user_id = get_current_user_id();
		update_user_meta( $user_id, self::WIZARD_SEEN_META_PREFIX . $blog_id, time() );
		return rest_ensure_response( [ 'ok' => true, 'blog_id' => $blog_id, 'user_id' => $user_id ] );
	}
}
