<?php
/**
 * BizCity Diagnostics — External Monitoring REST (Phase 0.41 L9.e).
 *
 * Exposes a token-authenticated endpoint that 3rd-party uptime monitors
 * (Uptime Robot, Pingdom, Grafana Synthetics, …) can hit without WP cookies:
 *
 *   GET  /wp-json/bizcity-diagnostics/v1/smoke/external/{probe_id}
 *   POST /wp-json/bizcity-diagnostics/v1/smoke/external/{probe_id}
 *     Header: X-BizCity-Token: <token>   (preferred)
 *     OR query param: ?token=<token>     (fallback for Uptime Robot HEAD-style)
 *
 *   Special probe_id="all" → POST runs the full smoke aggregate.
 *
 * The shared token is auto-generated on first plugin activation and stored
 * in option `bizcity_diag_external_token` (32-char alphanumeric). Admins
 * can rotate it via the Diagnostics page (future T-N).
 *
 * Response is a slim JSON suitable for status pages:
 *   { "ok": true, "probe": "kg.seeding", "status": "pass", "duration_ms": 234, "summary": "…" }
 *
 * Returns HTTP 401 when token is missing/invalid, 404 when probe not found,
 * 503 when smoke runner is not loaded.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics
 * @since      2026-05-21 (Phase 0.41 L9.e)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

final class BizCity_Diagnostics_External_Monitor {

	public const TOKEN_OPTION = 'bizcity_diag_external_token';

	private static $instance = null;

	public static function instance(): self {
		return self::$instance ?: ( self::$instance = new self() );
	}

	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	/** Lazy-generate the token on first read; persist once. */
	public static function get_token(): string {
		$tok = (string) get_option( self::TOKEN_OPTION, '' );
		if ( strlen( $tok ) < 16 ) {
			$tok = wp_generate_password( 32, false, false );
			update_option( self::TOKEN_OPTION, $tok, false );
		}
		return $tok;
	}

	public function register(): void {
		register_rest_route( BIZCITY_DIAGNOSTICS_REST_NS, '/smoke/external/(?P<probe_id>[a-z0-9_.\-]+)', [
			'methods'             => [ 'GET', 'POST' ],
			'permission_callback' => [ $this, 'check_token' ],
			'callback'            => [ $this, 'run' ],
			'args'                => [
				'probe_id' => [ 'type' => 'string', 'required' => true ],
			],
		] );
	}

	public function check_token( $req ): bool {
		$expected = self::get_token();
		if ( $expected === '' ) {
			return false;
		}

		$header = (string) $req->get_header( 'x_bizcity_token' );
		if ( $header === '' && isset( $_SERVER['HTTP_X_BIZCITY_TOKEN'] ) ) {
			$header = (string) $_SERVER['HTTP_X_BIZCITY_TOKEN'];
		}
		$query = (string) $req->get_param( 'token' );

		$got = $header !== '' ? $header : $query;
		return hash_equals( $expected, $got );
	}

	public function run( $req ) {
		if ( ! class_exists( 'BizCity_Diagnostics_Smoke_Runner' ) ) {
			return new WP_Error( 'smoke_runner_unavailable', 'Smoke runner not loaded.', [ 'status' => 503 ] );
		}

		$probe_id = sanitize_key( (string) $req->get_param( 'probe_id' ) );

		if ( $probe_id === 'all' ) {
			$agg = BizCity_Diagnostics_Smoke_Runner::run_all();
			$pass = 0; $fail = 0; $skip = 0;
			foreach ( $agg['results'] as $r ) {
				$s = $r['status'] ?? '';
				if ( $s === 'pass' ) { $pass++; }
				elseif ( $s === 'skipped' ) { $skip++; }
				else { $fail++; }
			}
			return rest_ensure_response( [
				'ok'          => $fail === 0,
				'probe'       => 'all',
				'status'      => $fail === 0 ? 'pass' : 'fail',
				'pass'        => $pass,
				'fail'        => $fail,
				'skipped'     => $skip,
				'duration_ms' => (int) ( $agg['duration_ms'] ?? 0 ),
			] );
		}

		$catalog = BizCity_Diagnostics_Smoke_Runner::catalog();
		if ( ! isset( $catalog[ $probe_id ] ) ) {
			return new WP_Error( 'unknown_probe', sprintf( 'Probe "%s" not found.', $probe_id ), [ 'status' => 404 ] );
		}

		$res = BizCity_Diagnostics_Smoke_Runner::run_probe( $probe_id );
		return rest_ensure_response( [
			'ok'          => ( $res['status'] ?? '' ) === 'pass',
			'probe'       => $probe_id,
			'status'      => (string) ( $res['status'] ?? 'unknown' ),
			'summary'     => (string) ( $res['summary'] ?? '' ),
			'error'       => (string) ( $res['error'] ?? '' ),
			'duration_ms' => (int)    ( $res['duration_ms'] ?? 0 ),
		] );
	}
}
