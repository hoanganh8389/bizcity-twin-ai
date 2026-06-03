<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Cron
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * BizCity_Cron_REST — REST controller for `/wp-json/bizcity-cron/v1/*`.
 *
 *   GET  /jobs                 — list jobs + health
 *   GET  /jobs/(?P<id>[^/]+)   — single job detail + recent runs
 *   POST /jobs/(?P<id>[^/]+)/run — synchronous run_now (manage_options)
 *   GET  /retries              — pending+dead retry rows
 *
 * Same payload shape as the MCP cron tools (see class-cron-mcp.php) so the
 * future agent layer can just forward without remapping.
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Cron_REST {

	const NS = 'bizcity-cron/v1';

	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes(): void {
		register_rest_route( self::NS, '/jobs', [
			'methods'             => 'GET',
			'permission_callback' => [ __CLASS__, 'permission_read' ],
			'callback'            => [ __CLASS__, 'list_jobs' ],
		] );
		register_rest_route( self::NS, '/jobs/(?P<id>[A-Za-z0-9_.\-]+)', [
			'methods'             => 'GET',
			'permission_callback' => [ __CLASS__, 'permission_read' ],
			'callback'            => [ __CLASS__, 'get_job' ],
			'args'                => [ 'id' => [ 'required' => true, 'type' => 'string' ] ],
		] );
		register_rest_route( self::NS, '/jobs/(?P<id>[A-Za-z0-9_.\-]+)/run', [
			'methods'             => 'POST',
			'permission_callback' => [ __CLASS__, 'permission_write' ],
			'callback'            => [ __CLASS__, 'run_job' ],
			'args'                => [ 'id' => [ 'required' => true, 'type' => 'string' ] ],
		] );
		register_rest_route( self::NS, '/retries', [
			'methods'             => 'GET',
			'permission_callback' => [ __CLASS__, 'permission_read' ],
			'callback'            => [ __CLASS__, 'list_retries' ],
		] );
	}

	public static function permission_read(): bool {
		return current_user_can( 'manage_options' );
	}
	public static function permission_write(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function list_jobs(): WP_REST_Response {
		return new WP_REST_Response( [
			'ok'   => true,
			'jobs' => BizCity_Cron_Manager::instance()->all(),
		], 200 );
	}

	public static function get_job( WP_REST_Request $req ): WP_REST_Response {
		$id   = sanitize_text_field( (string) $req['id'] );
		$mgr  = BizCity_Cron_Manager::instance();
		$jobs = $mgr->all();
		$job  = null;
		foreach ( $jobs as $j ) {
			if ( $j['job_id'] === $id ) { $job = $j; break; }
		}
		if ( ! $job ) {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'unknown_job' ], 404 );
		}
		return new WP_REST_Response( [
			'ok'           => true,
			'job'          => $job,
			'recent_runs'  => $mgr->recent_runs( $id, 20 ),
		], 200 );
	}

	public static function run_job( WP_REST_Request $req ): WP_REST_Response {
		$id  = sanitize_text_field( (string) $req['id'] );
		$res = BizCity_Cron_Manager::instance()->run_now( $id );
		return new WP_REST_Response( $res, $res['ok'] ? 200 : 500 );
	}

	public static function list_retries(): WP_REST_Response {
		global $wpdb;
		$t = $wpdb->prefix . BizCity_Cron_Manager::TABLE_RETRIES;
		$wpdb->suppress_errors( true );
		$rows = (array) $wpdb->get_results(
			"SELECT job_id, attempt, status, next_run_at, last_error, updated_at FROM {$t} WHERE status IN ('pending','dead') ORDER BY next_run_at ASC LIMIT 100",
			ARRAY_A
		);
		$wpdb->suppress_errors( false );
		return new WP_REST_Response( [ 'ok' => true, 'retries' => $rows ], 200 );
	}
}
