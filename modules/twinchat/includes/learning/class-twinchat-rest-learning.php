<?php
/**
 * Bizcity TwinChat — Learning REST Controller
 *
 * Phase 4.9 — surfaces the backend learning queue + SSE stream.
 *
 *   POST   /learning/enqueue                  → enqueue a new job
 *   GET    /learning/jobs?notebook_id=        → list recent jobs
 *   GET    /learning/jobs/(?P<id>\d+)         → fetch single job
 *   POST   /learning/jobs/(?P<id>\d+)/cancel  → cancel a queued/running job
 *   GET    /learning/events?notebook_id=&since=  → poll fallback (no SSE)
 *   GET    /learning/stream?notebook_id=         → SSE long-poll
 *
 * Permission: must be logged in AND own the notebook (delegated to KG-Hub
 * scope check via BizCity_KG::scope_visible_to() when available; else
 * fallback to logged-in only).
 *
 * Rate limit: enqueue capped at 20/user/min via transient.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_REST_Learning {

	const RATE_LIMIT_MAX     = 20;
	const RATE_LIMIT_WINDOW  = 60;
	// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-7 — public share monitor is
	// unauthenticated (token-gated), so add light per-token+IP throttling.
	const SHARE_RATE_LIMIT_MAX            = 90;
	const SHARE_RATE_LIMIT_MAX_HEARTBEAT  = 180;
	const SHARE_RATE_LIMIT_WINDOW         = 60;

	private static $instance = null;

	/**
	 * Cache of `bizcity_kg_learning_jobs.updated_at` column existence per blog.
	 * Filled lazily by {@see self::jobs_has_updated_at()}.
	 *
	 * @var array<int,bool>
	 */
	private static $jobs_has_updated_at_cache = [];

	/**
	 * Defensive column probe — true when the jobs table on the current blog
	 * has the `updated_at` column (schema 1.4.0+). False for older subsites
	 * that were created at 1.3.0 and have not yet run the additive migration.
	 *
	 * Used by {@see self::rebuild()} to pick `updated_at` vs `created_at` for
	 * stale-lease detection without throwing "Unknown column".
	 */
	private static function jobs_has_updated_at( string $tbl_jobs ): bool {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( self::$jobs_has_updated_at_cache[ $blog_id ] ) ) {
			return self::$jobs_has_updated_at_cache[ $blog_id ];
		}
		// [2026-07-27 Johnny Chu] R-SHOW-TABLES — migrate column probe from
		// SHOW COLUMNS to information_schema via learning DB helper.
		$has = false;
		if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
			$has = BizCity_TwinChat_Learning_Database::instance()->column_exists( $tbl_jobs, 'updated_at' );
		}
		self::$jobs_has_updated_at_cache[ $blog_id ] = $has;
		return $has;
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		$ns = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';

		register_rest_route( $ns, '/learning/enqueue', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_write' ],
			'callback'            => [ $this, 'enqueue' ],
			'args'                => [
				'notebook_id'  => [ 'type' => 'integer', 'required' => true ],
				'source_id'    => [ 'type' => 'integer' ],
				'source_title' => [ 'type' => 'string' ],
			],
		] );

		register_rest_route( $ns, '/learning/jobs', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_can_read_notebook' ],
			'callback'            => [ $this, 'list_jobs' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'limit'       => [ 'type' => 'integer', 'default' => 20 ],
				'status'      => [ 'type' => 'string' ],
			],
		] );

		// User-initiated rebuild: cancel active jobs, reset passages,
		// optionally clear pending triplets, then enqueue a fresh job.
		register_rest_route( $ns, '/learning/rebuild', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_write' ],
			'callback'            => [ $this, 'rebuild' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'mode'        => [ 'type' => 'string', 'default' => 'soft', 'enum' => [ 'soft', 'hard' ] ],
			],
		] );

		register_rest_route( $ns, '/learning/jobs/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_can_access_job' ],
			'callback'            => [ $this, 'get_job' ],
		] );

		register_rest_route( $ns, '/learning/jobs/(?P<id>\d+)/cancel', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_access_job' ],
			'callback'            => [ $this, 'cancel_job' ],
		] );

		// [2026-06-04 Johnny Chu] HOTFIX — admin-triggered cooldown clear for quota-paused jobs
		register_rest_route( $ns, '/learning/jobs/(?P<id>\d+)/resume', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_manage_cleanup' ],
			'callback'            => [ $this, 'resume_job' ],
		] );

		// Foreground driver tick — the open /twinchat/ tab calls this in a loop
		// to drive a job to completion faster than cron polling. Cron is still
		// scheduled as a fallback when the tab is closed.
		register_rest_route( $ns, '/learning/jobs/(?P<id>\d+)/tick', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_access_job' ],
			'callback'            => [ $this, 'tick_job' ],
		] );

		register_rest_route( $ns, '/learning/events', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_can_read_notebook' ],
			'callback'            => [ $this, 'poll_events' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'since'       => [ 'type' => 'integer', 'default' => 0 ],
				'limit'       => [ 'type' => 'integer', 'default' => 200 ],
			],
		] );

		register_rest_route( $ns, '/learning/stream', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_can_read_notebook' ],
			'callback'            => [ BizCity_TwinChat_Learning_Stream::instance(), 'handle' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'since'       => [ 'type' => 'integer' ],
			],
		] );

		// Parallel worker — internal loopback called by dispatch_parallel_workers().
		// Auth: HMAC token (X-TC-Internal-Token) generated by pipeline, not a WP nonce.
		// Namespace bizcity-twinchat/v1 is already on the mu-plugin REST POST bypass list.
		register_rest_route( $ns, '/learning/passage-worker', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_passage_worker_token' ],
			'callback'            => [ $this, 'passage_worker' ],
			'args'                => [
				'job_id'     => [ 'type' => 'integer', 'required' => true ],
				'passage_id' => [ 'type' => 'integer', 'required' => true ],
				'nb'         => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		// Debug log reader — tails daily file under site uploads:
		// /uploads/sites/{id}/bizcity_learning_logs/YYYY-MM-DD.log
		// Logged-in user only.
		register_rest_route( $ns, '/learning/debug-log', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'read_debug_log' ],
			'args'                => [
				'lines' => [ 'type' => 'integer', 'default' => 200 ],
				'date'  => [ 'type' => 'string', 'description' => 'YYYY-MM-DD (UTC), defaults to today' ],
				'job_id' => [ 'type' => 'integer', 'default' => 0 ],
			],
		] );

		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK — public,
		// no-login read-only view scoped to ONE (notebook_id, source_id) pair
		// via a signed token (BizCity_TwinChat_Learning_Share_Adapter). Never
		// exposes data outside that scope — see public_share_view().
		register_rest_route( $ns, '/learning/share/(?P<token>[A-Za-z0-9\-_.]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'public_share_view' ],
			'args'                => [
				'lines' => [ 'type' => 'integer', 'default' => 300 ],
				'date'  => [ 'type' => 'string', 'description' => 'YYYY-MM-DD (UTC), defaults to latest available' ],
			],
		] );

		// ── Wave A — TwinShell Learning Hub aggregate endpoints ─────────
		register_rest_route( $ns, '/learning/summary', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'get_summary' ],
			'args'                => [
				'scope' => [ 'type' => 'string', 'default' => 'user', 'enum' => [ 'user', 'site' ] ],
			],
		] );

		register_rest_route( $ns, '/learning/analytics', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'get_analytics' ],
			'args'                => [
				'range' => [ 'type' => 'string', 'default' => '24h', 'enum' => [ '24h', '7d', '30d' ] ],
				'scope' => [ 'type' => 'string', 'default' => 'user', 'enum' => [ 'user', 'site' ] ],
			],
		] );

		register_rest_route( $ns, '/learning/presence', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'set_presence' ],
			'args'                => [
				'active' => [ 'type' => 'boolean', 'default' => true ],
			],
		] );

		// ── Wave B — Cleanup engine surface ─────────────────────────────
		register_rest_route( $ns, '/learning/cleanup/status', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'cleanup_status' ],
		] );

		register_rest_route( $ns, '/learning/cleanup/log', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_can_manage_cleanup' ],
			'callback'            => [ $this, 'cleanup_log' ],
			'args'                => [
				'limit'  => [ 'type' => 'integer', 'default' => 50 ],
				'offset' => [ 'type' => 'integer', 'default' => 0 ],
				'stage'  => [ 'type' => 'string' ],
				'run_id' => [ 'type' => 'string' ],
			],
		] );

		register_rest_route( $ns, '/learning/cleanup/run', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_manage_cleanup' ],
			'callback'            => [ $this, 'cleanup_run' ],
		] );

		register_rest_route( $ns, '/learning/cleanup/restore', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_can_manage_cleanup' ],
			'callback'            => [ $this, 'cleanup_restore' ],
			'args'                => [
				'target_table' => [ 'type' => 'string', 'required' => true ],
				'target_id'    => [ 'type' => 'integer', 'required' => true ],
			],
		] );
	}

	// ── Permissions ─────────────────────────────────────────────────────

	public function check_logged_in() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'Must be logged in', [ 'status' => 401 ] );
		}
		return true;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — fail-closed notebook ownership
	 * guard for learning routes.
	 */
	private function check_notebook_access( $notebook_id ) {
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'Invalid notebook_id', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return new WP_Error( 'notebook_service_unavailable', 'Notebook service unavailable', [ 'status' => 503 ] );
		}
		$nb = BizCity_KG_Notebook_Service::instance()->get( $notebook_id );
		if ( ! is_array( $nb ) ) {
			return new WP_Error( 'notebook_not_found', 'Notebook not found', [ 'status' => 404 ] );
		}
		$owner_id = (int) ( $nb['owner_id'] ?? $nb['user_id'] ?? 0 );
		$current  = (int) get_current_user_id();
		if ( $owner_id !== $current && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden_notebook', 'Notebook not accessible', [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — map job id to notebook access.
	 */
	private function check_job_access( $job_id ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 ) {
			return new WP_Error( 'invalid_job', 'Invalid job id', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Job_Queue' ) ) {
			return new WP_Error( 'job_queue_unavailable', 'Learning job queue unavailable', [ 'status' => 503 ] );
		}
		$job = BizCity_TwinChat_Learning_Job_Queue::instance()->get_job( $job_id );
		if ( ! is_array( $job ) ) {
			return new WP_Error( 'job_not_found', 'Job not found', [ 'status' => 404 ] );
		}
		return $this->check_notebook_access( (int) ( $job['notebook_id'] ?? 0 ) );
	}

	public function check_can_read_notebook( WP_REST_Request $req ) {
		$ok = $this->check_logged_in();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return $this->check_notebook_access( (int) $req->get_param( 'notebook_id' ) );
	}

	public function check_can_access_job( WP_REST_Request $req ) {
		$ok = $this->check_logged_in();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return $this->check_job_access( (int) $req->get_param( 'id' ) );
	}

	/**
	 * Resolve uploads base dir (multisite-aware).
	 */
	private function resolve_debug_log_base_dir( $ensure_dir = false ) {
		$uploads = function_exists( 'wp_upload_dir' )
			? wp_upload_dir( null, (bool) $ensure_dir, false )
			: [];

		$base_dir = '';
		if ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			$base_dir = (string) $uploads['basedir'];
		}
		if ( $base_dir === '' ) {
			$base_dir = WP_CONTENT_DIR . '/uploads';
		}
		return trailingslashit( wp_normalize_path( $base_dir ) );
	}

	/**
	 * [2026-07-23 Johnny Chu] PHASE-0.44 — canonical learning log path moved to
	 * uploads/.../bizcity_learning_logs/YYYY-MM-DD.log.
	 */
	private function resolve_debug_log_candidates( $date_ymd = '', $ensure_dir = false ) {
		if ( ! is_string( $date_ymd ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			$date_ymd = gmdate( 'Y-m-d' );
		}

		$paths = [];

		if ( function_exists( 'bizcity_tc_learning_debug_log_path' ) ) {
			$paths[] = (string) bizcity_tc_learning_debug_log_path( $date_ymd, (bool) $ensure_dir );
		}

		$base_dir = $this->resolve_debug_log_base_dir( $ensure_dir );
		$new_dir  = $base_dir . 'bizcity_learning_logs';
		$old_dir  = $base_dir . 'bizcity-logs/tc-learning';

		if ( $ensure_dir && ! is_dir( $new_dir ) ) {
			@wp_mkdir_p( $new_dir );
			@file_put_contents( trailingslashit( $new_dir ) . '.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( trailingslashit( $new_dir ) . 'index.php', "<?php // Silence is golden.\n" );
		}

		$paths[] = trailingslashit( $new_dir ) . $date_ymd . '.log';
		$paths[] = trailingslashit( $old_dir ) . 'tc-learning-' . $date_ymd . '.log';

		$unique = [];
		foreach ( $paths as $p ) {
			$p = trim( (string) $p );
			if ( $p === '' || isset( $unique[ $p ] ) ) {
				continue;
			}
			$unique[ $p ] = true;
		}

		return array_keys( $unique );
	}

	private function resolve_debug_log_path( $date_ymd = '', $ensure_dir = false ) {
		$candidates = $this->resolve_debug_log_candidates( $date_ymd, $ensure_dir );
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return isset( $candidates[0] ) ? $candidates[0] : '';
	}

	private function list_debug_log_dates( $limit = 90 ) {
		$limit = max( 1, min( 365, (int) $limit ) );
		$base  = $this->resolve_debug_log_base_dir( false );
		$dirs  = array(
			$base . 'bizcity_learning_logs',
			$base . 'bizcity-logs/tc-learning',
		);

		$found = [];
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}
			$items = @scandir( $dir );
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $name ) {
				if ( preg_match( '/^(\d{4}-\d{2}-\d{2})\.log$/', (string) $name, $m ) ) {
					$found[ $m[1] ] = true;
					continue;
				}
				if ( preg_match( '/^tc-learning-(\d{4}-\d{2}-\d{2})\.log$/', (string) $name, $m ) ) {
					$found[ $m[1] ] = true;
				}
			}
		}

		$dates = array_keys( $found );
		rsort( $dates, SORT_STRING );
		if ( count( $dates ) > $limit ) {
			$dates = array_slice( $dates, 0, $limit );
		}
		return $dates;
	}

	private function build_debug_log_public_hint( $date_ymd ) {
		$date_ymd = is_string( $date_ymd ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd )
			? $date_ymd
			: gmdate( 'Y-m-d' );
		return '/uploads/sites/{blog_id}/bizcity_learning_logs/' . $date_ymd . '.log';
	}

	private function analyze_debug_log_file( $path, $job_id = 0 ) {
		$job_id = max( 0, (int) $job_id );
		$stats = array(
			'job_id'                    => $job_id > 0 ? $job_id : null,
			'source_lines'              => 0,
			'dispatch_rounds'           => 0,
			'dispatch_fired_total'      => 0,
			'dispatch_parallel_max'     => 0,
			'worker_success'            => 0,
			'worker_errors'             => 0,
			'success_rate_pct'          => 0.0,
			'triplets_total'            => 0,
			'avg_triplets_per_success'  => 0.0,
			'approve_calls'             => 0,
			'approved_relations'        => 0,
			'approve_errors'            => 0,
			'error_buckets'             => array(),
			// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE — driver
			// (cron vs admin-ajax) breakdown, parsed from the `lane=` tag now
			// stamped on dispatch/worker log lines. Lines written before this
			// change have no `lane=` tag and fall into 'unknown'.
			'by_lane'                   => array(
				'cron'    => array( 'dispatch_rounds' => 0, 'worker_success' => 0, 'worker_errors' => 0 ),
				'ajax'    => array( 'dispatch_rounds' => 0, 'worker_success' => 0, 'worker_errors' => 0 ),
				'unknown' => array( 'dispatch_rounds' => 0, 'worker_success' => 0, 'worker_errors' => 0 ),
			),
		);

		if ( ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
			return $stats;
		}

		$fp = @fopen( $path, 'r' );
		if ( ! $fp ) {
			return $stats;
		}

		while ( false !== ( $line = fgets( $fp ) ) ) {
			$stats['source_lines']++;

			if ( $job_id > 0 ) {
				if ( ! preg_match( '/\bjob=(\d+)\b/', $line, $job_match ) || (int) $job_match[1] !== $job_id ) {
					continue;
				}
			}

			if ( preg_match( '/dispatch_parallel_workers\s+job=\d+\s+(?:lane=(\w+)\s+)?(?:owner=\S+\s+)?.*?fired=(\d+)\/(\d+)/', $line, $m ) ) {
				$stats['dispatch_rounds']++;
				$stats['dispatch_fired_total'] += (int) $m[2];
				$stats['dispatch_parallel_max'] = max( (int) $stats['dispatch_parallel_max'], (int) $m[3] );
				$lane_key = isset( $m[1] ) && $m[1] !== '' ? $m[1] : 'unknown';
				if ( ! isset( $stats['by_lane'][ $lane_key ] ) ) {
					$stats['by_lane'][ $lane_key ] = array( 'dispatch_rounds' => 0, 'worker_success' => 0, 'worker_errors' => 0 );
				}
				$stats['by_lane'][ $lane_key ]['dispatch_rounds']++;
				continue;
			}

			// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE — optional
			// `lane=cron|ajax` tag now precedes OK/ERROR; regex tolerates its
			// absence so log lines written before this change still parse.
			if ( preg_match( '/passage_worker\s+job=\d+\s+passage=\d+\s+(?:lane=(\w+)\s+)?OK\s+triplets=(\d+)/', $line, $m ) ) {
				$stats['worker_success']++;
				$stats['triplets_total'] += (int) $m[2];
				$lane_key = isset( $m[1] ) && $m[1] !== '' ? $m[1] : 'unknown';
				if ( ! isset( $stats['by_lane'][ $lane_key ] ) ) {
					$stats['by_lane'][ $lane_key ] = array( 'dispatch_rounds' => 0, 'worker_success' => 0, 'worker_errors' => 0 );
				}
				$stats['by_lane'][ $lane_key ]['worker_success']++;
				continue;
			}

			if ( preg_match( '/passage_worker\s+job=\d+\s+passage=\d+\s+(?:lane=(\w+)\s+)?ERROR\s+\[([^\]]+)\]/', $line, $m ) ) {
				$stats['worker_errors']++;
				$code = sanitize_key( (string) $m[2] );
				if ( $code === '' ) {
					$code = 'unknown';
				}
				if ( ! isset( $stats['error_buckets'][ $code ] ) ) {
					$stats['error_buckets'][ $code ] = 0;
				}
				$stats['error_buckets'][ $code ]++;
				$lane_key = isset( $m[1] ) && $m[1] !== '' ? $m[1] : 'unknown';
				if ( ! isset( $stats['by_lane'][ $lane_key ] ) ) {
					$stats['by_lane'][ $lane_key ] = array( 'dispatch_rounds' => 0, 'worker_success' => 0, 'worker_errors' => 0 );
				}
				$stats['by_lane'][ $lane_key ]['worker_errors']++;
				continue;
			}

			if ( preg_match( '/approve_all_pending\s+returned\s+approved=(\d+)\s+errors=(\d+)/', $line, $m ) ) {
				$stats['approve_calls']++;
				$stats['approved_relations'] += (int) $m[1];
				$stats['approve_errors'] += (int) $m[2];
			}
		}

		fclose( $fp );

		$total_worker = (int) $stats['worker_success'] + (int) $stats['worker_errors'];
		if ( $total_worker > 0 ) {
			$stats['success_rate_pct'] = round( ( (float) $stats['worker_success'] * 100 ) / (float) $total_worker, 2 );
		}
		if ( (int) $stats['worker_success'] > 0 ) {
			$stats['avg_triplets_per_success'] = round( (float) $stats['triplets_total'] / (float) $stats['worker_success'], 2 );
		}

		return $stats;
	}

	/**
	 * Read the tail of the dedicated learning debug log file.
	 * Path: uploads/site-basedir/bizcity_learning_logs/YYYY-MM-DD.log
	 */
	public function read_debug_log( WP_REST_Request $req ) {
		// [2026-07-23 Johnny Chu] PHASE-0.44 — default to latest available log date, support job-filter analytics, and return masked path only.
		$lines = max( 1, min( 2000, (int) $req->get_param( 'lines' ) ?: 200 ) );
		$job_id = max( 0, (int) $req->get_param( 'job_id' ) );
		$requested_date = (string) $req->get_param( 'date' );
		$has_explicit_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $requested_date ) === 1;
		$available_dates = $this->list_debug_log_dates( 120 );

		$date_ymd = '';
		if ( $has_explicit_date ) {
			$date_ymd = $requested_date;
		} elseif ( ! empty( $available_dates ) ) {
			$date_ymd = (string) $available_dates[0];
		} else {
			$date_ymd = gmdate( 'Y-m-d' );
		}

		$path = $this->resolve_debug_log_path( $date_ymd, false );
		$path_hint = $this->build_debug_log_public_hint( $date_ymd );
		if ( ! file_exists( $path ) ) {
			$stats = $this->analyze_debug_log_file( '', $job_id );
			return rest_ensure_response( [
				'ok'    => true,
				'data'  => [
					'path'            => $path_hint,
					'date'            => $date_ymd,
					'requested_date'  => $has_explicit_date ? $requested_date : '',
					'latest_date'     => ! empty( $available_dates ) ? (string) $available_dates[0] : '',
					'available_dates' => $available_dates,
					'exists'          => false,
					'count'           => 0,
					'lines'           => [],
					'stats'           => $stats,
				],
			] );
		}
		// Tail last N lines without loading full file.
		$out  = [];
		$f    = @fopen( $path, 'r' );
		if ( ! $f ) {
			return new WP_Error( 'cannot_read', 'Cannot open log', [ 'status' => 500 ] );
		}
		fseek( $f, 0, SEEK_END );
		$pos    = ftell( $f );
		$buffer = '';
		$found  = 0;
		while ( $pos > 0 && $found <= $lines ) {
			$read = min( 4096, $pos );
			$pos -= $read;
			fseek( $f, $pos );
			$chunk  = (string) fread( $f, $read );
			$buffer = $chunk . $buffer;
			$found  = substr_count( $buffer, "\n" );
		}
		fclose( $f );
		$all  = preg_split( "/\r?\n/", trim( $buffer ) );
		$tail = array_slice( $all, -$lines );
		$stats = $this->analyze_debug_log_file( $path, $job_id );
		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'path'            => $path_hint,
				'date'            => $date_ymd,
				'requested_date'  => $has_explicit_date ? $requested_date : '',
				'latest_date'     => ! empty( $available_dates ) ? (string) $available_dates[0] : $date_ymd,
				'available_dates' => $available_dates,
				'exists'          => true,
				'size'            => (int) filesize( $path ),
				'count'           => count( $tail ),
				'lines'           => $tail,
				'stats'           => $stats,
			],
		] );
	}

	/**
	 * Public, no-login share view — resolves a signed token minted by
	 * {@see BizCity_TwinChat_Learning_Share_Adapter::create_link()} and
	 * returns a read-only payload scoped to exactly the (notebook_id,
	 * source_id[, job_id]) the token was minted for.
	 *
	 * Security (OWASP A01): the token is the ONLY authorization; there is no
	 * WP session here. Every downstream lookup MUST stay inside the resolved
	 * notebook_id/job_id — never fall back to "all jobs"/"whole day" like the
	 * logged-in `read_debug_log` endpoint does.
	 */
	public function public_share_view( WP_REST_Request $req ) {
		// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-6 — monitoring page must
		// always reflect latest learning progress; prevent browser/proxy/CDN
		// caching for this public share endpoint.
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Share_Adapter' ) ) {
			return new WP_Error( 'unavailable', 'Learning share adapter chưa sẵn sàng trên site này.', [ 'status' => 503 ] );
		}
		$token   = (string) $req->get_param( 'token' );
		$rate_ok = $this->check_public_share_rate_limit( $req, $token );
		if ( is_wp_error( $rate_ok ) ) {
			return $rate_ok;
		}
		$claims  = BizCity_TwinChat_Learning_Share_Adapter::instance()->resolve_token( $token );
		if ( is_wp_error( $claims ) ) {
			$data = $claims->get_error_data();
			$status = $claims->get_error_code() === 'token_expired' ? 410 : 403;
			return new WP_Error( $claims->get_error_code(), $claims->get_error_message(), array_merge(
				is_array( $data ) ? $data : [],
				[ 'status' => $status ]
			) );
		}

		$notebook_id = (int) $claims['notebook_id'];
		$source_id   = (int) $claims['source_id'];
		$job_id      = (int) $claims['job_id'];

		// Resolve the job to show: pinned job_id from the token, else the most
		// recent job for this notebook (optionally narrowed to source_id).
		$job = null;
		if ( class_exists( 'BizCity_TwinChat_Learning_Job_Queue' ) ) {
			$queue = BizCity_TwinChat_Learning_Job_Queue::instance();
			if ( $job_id > 0 ) {
				$candidate = $queue->get_job( $job_id );
				if ( $candidate && (int) $candidate['notebook_id'] === $notebook_id ) {
					$job = $candidate;
				}
			}
			if ( ! $job ) {
				$recent = $queue->list_jobs( $notebook_id, [ 'limit' => 20 ] );
				foreach ( $recent as $row ) {
					if ( $source_id > 0 && (int) $row['source_id'] !== $source_id ) {
						continue;
					}
					$job = $row;
					break;
				}
			}
		}
		if ( $job ) {
			$job_id = (int) $job['id'];
		}

		$is_heartbeat = (string) $req->get_param( 'heartbeat' ) === '1';
		$default_lines = $is_heartbeat ? 120 : 300;
		$lines_wanted  = max( 1, min( 1000, (int) $req->get_param( 'lines' ) ?: $default_lines ) );
		$requested_date   = (string) $req->get_param( 'date' );
		$has_explicit_date= preg_match( '/^\d{4}-\d{2}-\d{2}$/', $requested_date ) === 1;
		$available_dates  = $this->list_debug_log_dates( 120 );
		$date_ymd = $has_explicit_date ? $requested_date : ( ! empty( $available_dates ) ? (string) $available_dates[0] : gmdate( 'Y-m-d' ) );
		$path     = $this->resolve_debug_log_path( $date_ymd, false );

		$tail  = [];
		$stats = $this->analyze_debug_log_file( $job_id > 0 && file_exists( $path ) ? $path : '', $job_id );
		if ( $job_id > 0 && file_exists( $path ) ) {
			$tail = $this->read_job_scoped_log_lines( $path, $job_id, $lines_wanted );
		}

		$chunk_rows   = $this->extract_share_chunk_rows( $tail );
		$lane_summary = $this->build_share_lane_summary( $stats, $tail );

		// [2026-07-26 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
		// source learning snapshot (KG tables + source progress events) is
		// the canonical truth for share links; debug log is supplemental.
		$event_limit     = $is_heartbeat ? 80 : 140;
		$chunk_limit     = $is_heartbeat ? 100 : 140;
		$source_snapshot = $this->build_share_source_snapshot( $notebook_id, $source_id, $event_limit, $chunk_limit );
		if ( ! $job && ! empty( $source_snapshot['job'] ) && is_array( $source_snapshot['job'] ) ) {
			$job = $source_snapshot['job'];
		}

		$display_chunks = ! empty( $source_snapshot['chunks'] ) && is_array( $source_snapshot['chunks'] )
			? $source_snapshot['chunks']
			: $chunk_rows;

		$payload = [
			'notebook_id'     => $notebook_id,
			'source_id'       => $source_id,
			'job'             => $job,
			'date'            => $date_ymd,
			'latest_date'     => ! empty( $available_dates ) ? (string) $available_dates[0] : $date_ymd,
			'available_dates' => $available_dates,
			'expires_at'      => gmdate( 'Y-m-d H:i:s', (int) $claims['expires_ts'] ),
			'count'           => count( $tail ),
			'lines'           => $tail,
			'stats'           => $stats,
			'console_chunks'  => $chunk_rows,
			'chunks'          => $display_chunks,
			'lane_summary'    => $lane_summary,
			'source_snapshot' => $source_snapshot,
			'status'          => (string) ( $source_snapshot['status'] ?? 'unknown' ),
			'progress'        => isset( $source_snapshot['progress'] ) ? (float) $source_snapshot['progress'] : 0.0,
			'counts'          => isset( $source_snapshot['counts'] ) && is_array( $source_snapshot['counts'] ) ? $source_snapshot['counts'] : array(),
			'phases'          => isset( $source_snapshot['phases'] ) && is_array( $source_snapshot['phases'] ) ? $source_snapshot['phases'] : array(),
			'events'          => isset( $source_snapshot['events'] ) && is_array( $source_snapshot['events'] ) ? $source_snapshot['events'] : array(),
			'graph_preview'   => isset( $source_snapshot['graph_preview'] ) && is_array( $source_snapshot['graph_preview'] ) ? $source_snapshot['graph_preview'] : array(),
			'raw_log_hint'    => isset( $source_snapshot['raw_log_hint'] ) && is_array( $source_snapshot['raw_log_hint'] ) ? $source_snapshot['raw_log_hint'] : array(),
		];

		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
		// browser opens share link as tabs UI, while API callers keep JSON.
		if ( $this->should_render_share_html( $req ) ) {
			return $this->render_public_share_html_response( $payload );
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => $payload,
		] );
	}

	/**
	 * Scan a daily log file and return only the last N lines that belong to
	 * one job (matched by `\bjob=<id>\b`). Used exclusively by the public
	 * share view so an unauthenticated link can never see another job's
	 * console output. Bounded scan (200k lines max) — daily files rotate.
	 */
	private function read_job_scoped_log_lines( $path, $job_id, $max_lines ) {
		$job_id = (int) $job_id;
		if ( $job_id <= 0 || ! is_string( $path ) || $path === '' || ! file_exists( $path ) ) {
			return array();
		}
		$fp = @fopen( $path, 'r' );
		if ( ! $fp ) {
			return array();
		}
		$max_lines = max( 1, (int) $max_lines );
		$needle    = 'job=' . $job_id;
		$matched   = array();
		$scanned   = 0;
		$scan_cap  = 200000;

		// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-10 — bound scan and
		// keep strict job-scoped filtering for public share token output.
		while ( ( $line = fgets( $fp ) ) !== false ) {
			$scanned++;
			if ( $scanned > $scan_cap ) {
				break;
			}
			if ( strpos( $line, $needle ) === false ) {
				continue;
			}
			if ( ! preg_match( '/\bjob=' . $job_id . '\b/', $line ) ) {
				continue;
			}
			$matched[] = rtrim( $line, "\r\n" );
			if ( count( $matched ) > $max_lines ) {
				array_shift( $matched );
			}
		}
		fclose( $fp );
		return $matched;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * choose browser HTML tabs vs machine JSON for public share route.
	 */
	private function should_render_share_html( WP_REST_Request $req ) {
		$format = sanitize_key( (string) $req->get_param( 'format' ) );
		if ( $format === 'json' ) {
			return false;
		}
		if ( $format === 'html' ) {
			return true;
		}

		$accept = strtolower( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ) );
		if ( strpos( $accept, 'text/html' ) !== false ) {
			return true;
		}
		if ( strpos( $accept, 'application/json' ) !== false ) {
			return false;
		}
		return false;
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-7 — lightweight throttling
	 * for unauthenticated public share monitor endpoint, scoped by token+IP.
	 * Keeps token security model unchanged while reducing abuse surface.
	 *
	 * @return true|WP_Error
	 */
	private function check_public_share_rate_limit( WP_REST_Request $req, string $token ) {
		$token = trim( $token );
		if ( $token === '' ) {
			return true;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$is_heartbeat = (string) $req->get_param( 'heartbeat' ) === '1';
		$limit = $is_heartbeat ? self::SHARE_RATE_LIMIT_MAX_HEARTBEAT : self::SHARE_RATE_LIMIT_MAX;
		$key = 'tc_share_rl_' . md5( (string) get_current_blog_id() . '|' . $ip . '|' . $token );

		$state = get_transient( $key );
		if ( ! is_array( $state ) ) {
			$state = array(
				'count'      => 0,
				'started_at' => time(),
			);
		}

		$started_at = isset( $state['started_at'] ) ? (int) $state['started_at'] : 0;
		$count      = isset( $state['count'] ) ? (int) $state['count'] : 0;
		$now        = time();

		if ( $started_at <= 0 || ( $now - $started_at ) >= self::SHARE_RATE_LIMIT_WINDOW ) {
			$started_at = $now;
			$count      = 0;
		}

		if ( $count >= $limit ) {
			$retry_after = max( 1, self::SHARE_RATE_LIMIT_WINDOW - ( $now - $started_at ) );
			return new WP_Error(
				'rate_limited',
				'Too many learning-share monitor requests. Please retry shortly.',
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		$state['count']      = $count + 1;
		$state['started_at'] = $started_at;
		set_transient( $key, $state, self::SHARE_RATE_LIMIT_WINDOW );

		return true;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * output raw HTML for browser share links (tabs: summary/chunks/console).
	 */
	private function render_public_share_html_response( array $payload ) {
		$html = $this->render_public_share_html( $payload );
		$resp = new WP_REST_Response( $html );
		$resp->header( 'Content-Type', 'text/html; charset=UTF-8' );
		$resp->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$resp->header( 'Pragma', 'no-cache' );
		$resp->header( 'Expires', '0' );
		add_filter( 'rest_pre_serve_request', static function ( $served ) use ( $html ) {
			if ( $served ) {
				return $served;
			}
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return true;
		}, 10, 1 );
		return $resp;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * normalize lane tag from one log line.
	 */
	private function detect_lane_from_log_line( $line ) {
		$line = (string) $line;
		if ( preg_match( '/\blane=(cron|ajax)\b/i', $line, $m ) ) {
			return strtolower( (string) $m[1] );
		}
		if ( strpos( $line, 'owner=ajax-' ) !== false ) {
			return 'ajax';
		}
		if ( strpos( $line, 'owner=cron' ) !== false ) {
			return 'cron';
		}
		return 'unknown';
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * extract per-passage rows so the public share page can show "Chunk done" tab.
	 */
	private function extract_share_chunk_rows( array $lines ) {
		$rows = array();
		foreach ( $lines as $line ) {
			$line = (string) $line;
			$time = '';
			if ( preg_match( '/\[([^\]]+) UTC\]/', $line, $tm ) ) {
				$time = (string) $tm[1];
			}

			if ( preg_match( '/(?:passage_worker|sync_worker)\s+job=\d+\s+passage=(\d+)\s+(?:lane=(\w+)\s+)?OK\s+triplets=(\d+)/', $line, $m ) ) {
				$rows[] = array(
					'time'       => $time,
					'passage_id' => (int) $m[1],
					'lane'       => ! empty( $m[2] ) ? sanitize_key( (string) $m[2] ) : $this->detect_lane_from_log_line( $line ),
					'status'     => 'done',
					'triplets'   => (int) $m[3],
					'error_code' => '',
					'raw'        => $line,
				);
				continue;
			}

			if ( preg_match( '/(?:passage_worker|sync_worker)\s+job=\d+\s+passage=(\d+)\s+(?:lane=(\w+)\s+)?ERROR\s+\[([^\]]+)\]/', $line, $m ) ) {
				$rows[] = array(
					'time'       => $time,
					'passage_id' => (int) $m[1],
					'lane'       => ! empty( $m[2] ) ? sanitize_key( (string) $m[2] ) : $this->detect_lane_from_log_line( $line ),
					'status'     => 'error',
					'triplets'   => 0,
					'error_code' => sanitize_key( (string) $m[3] ),
					'raw'        => $line,
				);
			}
		}

		if ( count( $rows ) > 500 ) {
			$rows = array_slice( $rows, -500 );
		}
		return $rows;
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * summarize cron/ajax ownership and detect lane switch milestones.
	 */
	private function build_share_lane_summary( array $stats, array $lines ) {
		$by_lane = isset( $stats['by_lane'] ) && is_array( $stats['by_lane'] ) ? $stats['by_lane'] : array();
		$cron    = isset( $by_lane['cron'] ) && is_array( $by_lane['cron'] ) ? $by_lane['cron'] : array();
		$ajax    = isset( $by_lane['ajax'] ) && is_array( $by_lane['ajax'] ) ? $by_lane['ajax'] : array();

		$cron_activity = (int) ( $cron['dispatch_rounds'] ?? 0 ) + (int) ( $cron['worker_success'] ?? 0 ) + (int) ( $cron['worker_errors'] ?? 0 );
		$ajax_activity = (int) ( $ajax['dispatch_rounds'] ?? 0 ) + (int) ( $ajax['worker_success'] ?? 0 ) + (int) ( $ajax['worker_errors'] ?? 0 );

		$source_mode = 'unknown';
		if ( $cron_activity > 0 && $ajax_activity > 0 ) {
			$source_mode = 'mixed_auto_switch';
		} elseif ( $cron_activity > 0 ) {
			$source_mode = 'cron';
		} elseif ( $ajax_activity > 0 ) {
			$source_mode = 'admin_ajax';
		}

		$switches  = array();
		$last_lane = '';
		foreach ( $lines as $line ) {
			$line = (string) $line;
			$lane = $this->detect_lane_from_log_line( $line );
			if ( $lane === 'unknown' ) {
				continue;
			}
			if ( $last_lane !== '' && $lane !== $last_lane ) {
				$time = '';
				if ( preg_match( '/\[([^\]]+) UTC\]/', $line, $tm ) ) {
					$time = (string) $tm[1];
				}
				$switches[] = array(
					'time' => $time,
					'from' => $last_lane,
					'to'   => $lane,
					'line' => trim( $line ),
				);
			}
			$last_lane = $lane;
		}

		if ( count( $switches ) > 50 ) {
			$switches = array_slice( $switches, -50 );
		}

		return array(
			'source_mode' => $source_mode,
			'auto_switch' => $source_mode === 'mixed_auto_switch',
			'by_lane'     => $by_lane,
			'switches'    => $switches,
		);
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * build source-scoped learning snapshot (same truth model as drawer
	 * learning-log) for public share links.
	 */
	private function build_share_source_snapshot( $notebook_id, $source_id, $event_limit = 140, $chunk_limit = 140 ) {
		global $wpdb;

		$notebook_id = (int) $notebook_id;
		$source_id   = (int) $source_id;
		$event_limit = max( 10, min( 500, (int) $event_limit ) );
		$chunk_limit = max( 20, min( 400, (int) $chunk_limit ) );

		$counts = array(
			'total'              => 0,
			'done'               => 0,
			'processing'         => 0,
			'pending'            => 0,
			'error'              => 0,
			'skipped'            => 0,
			'triplets_pending'   => 0,
			'entities_approved'  => 0,
			'relations_approved' => 0,
		);

		$empty = array(
			'source_id'        => $source_id,
			'kg_source_id'     => 0,
			'legacy_source_id' => null,
			'notebook_id'      => $notebook_id,
			'title'            => '',
			'status'           => 'unknown',
			'progress'         => 0.0,
			'error_message'    => '',
			'error_code'       => '',
			'retryable'        => false,
			'counts'           => $counts,
			'job'              => null,
			'phases'           => array(),
			'chunks'           => array(),
			'events'           => array(),
			'graph_preview'    => array(
				'nodes' => array(),
				'links' => array(),
				'meta'  => array( 'nodes' => 0, 'links' => 0, 'mode' => 'empty' ),
			),
			'raw_log_hint'     => array(
				'date'    => gmdate( 'Y-m-d' ),
				'markers' => array(),
			),
		);

		if ( $notebook_id <= 0 || $source_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $empty;
		}

		$db          = BizCity_KG_Database::instance();
		$tbl_sources = $db->tbl_sources();
		$tbl_passage = $db->tbl_passages();

		$kg_row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, origin_id, title, status
			   FROM {$tbl_sources}
			  WHERE origin_id = %d AND scope_type = %s AND scope_id = %s
			  ORDER BY id DESC LIMIT 1",
			$source_id,
			'notebook',
			(string) $notebook_id
		), ARRAY_A );
		if ( ! $kg_row ) {
			$kg_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, origin_id, title, status
				   FROM {$tbl_sources}
				  WHERE id = %d AND scope_type = %s AND scope_id = %s LIMIT 1",
				$source_id,
				'notebook',
				(string) $notebook_id
			), ARRAY_A );
		}
		if ( ! $kg_row ) {
			return $empty;
		}

		$kg_source_id     = (int) $kg_row['id'];
		$legacy_source_id = (int) $kg_row['origin_id'];
		$source_ids       = array_values( array_unique( array_filter( array(
			$kg_source_id,
			$legacy_source_id,
		), static function ( $v ) {
			return (int) $v > 0;
		} ) ) );
		if ( empty( $source_ids ) ) {
			$source_ids = array( $kg_source_id );
		}

		$src_ph = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$status_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT extraction_status, COUNT(*) AS n
			   FROM {$tbl_passage}
			  WHERE notebook_id = %d AND source_id IN ({$src_ph})
			  GROUP BY extraction_status",
			array_merge( array( $notebook_id ), $source_ids )
		), ARRAY_A );
		foreach ( (array) $status_rows as $sr ) {
			$bucket = strtolower( (string) ( $sr['extraction_status'] ?? '' ) );
			$n      = (int) ( $sr['n'] ?? 0 );
			if ( ! isset( $counts[ $bucket ] ) ) {
				$bucket = 'pending';
			}
			$counts[ $bucket ] += $n;
			$counts['total']   += $n;
		}

		$chunks      = array();
		$passage_ids = array();
		$passage_set = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$chunk_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, extraction_status, updated_at, content, metadata,
			        storage_ver, file_shard, file_offset, file_length, notebook_id
			   FROM {$tbl_passage}
			  WHERE notebook_id = %d AND source_id IN ({$src_ph})
			  ORDER BY id ASC
			  LIMIT %d",
			array_merge( array( $notebook_id ), $source_ids, array( $chunk_limit ) )
		), ARRAY_A );

		if ( class_exists( 'BizCity_KG_Content_Router' ) && ! empty( $chunk_rows ) ) {
			BizCity_KG_Content_Router::instance()->hydrate_passages( $chunk_rows );
		}

		foreach ( (array) $chunk_rows as $pr ) {
			$passage_id = (int) ( $pr['id'] ?? 0 );
			if ( $passage_id > 0 ) {
				$passage_ids[] = $passage_id;
				$passage_set[ $passage_id ] = true;
			}
		}

		$triplet_by_passage = array();
		if ( ! empty( $passage_ids ) ) {
			$ps_ph  = implode( ',', array_fill( 0, count( $passage_ids ), '%d' ) );
			$tbl_pr = $db->tbl_passage_relations();
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$triplet_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT passage_id, COUNT(*) AS c
				   FROM {$tbl_pr}
				  WHERE passage_id IN ({$ps_ph})
				  GROUP BY passage_id",
				$passage_ids
			), ARRAY_A );
			foreach ( (array) $triplet_rows as $tr ) {
				$triplet_by_passage[ (int) $tr['passage_id'] ] = (int) $tr['c'];
			}
		}

		foreach ( (array) $chunk_rows as $pr ) {
			$pid     = (int) ( $pr['id'] ?? 0 );
			$status  = strtolower( (string) ( $pr['extraction_status'] ?? 'pending' ) );
			if ( ! in_array( $status, array( 'pending', 'processing', 'done', 'error', 'skipped' ), true ) ) {
				$status = 'pending';
			}
			$content = trim( (string) ( $pr['content'] ?? '' ) );
			if ( function_exists( 'mb_substr' ) ) {
				$snippet = mb_substr( $content, 0, 160 );
			} else {
				$snippet = substr( $content, 0, 160 );
			}
			$meta        = array();
			$chunk_index = null;
			if ( isset( $pr['metadata'] ) && is_string( $pr['metadata'] ) && $pr['metadata'] !== '' ) {
				$meta = json_decode( $pr['metadata'], true );
				if ( is_array( $meta ) && isset( $meta['chunk_index'] ) ) {
					$chunk_index = (int) $meta['chunk_index'];
				}
			}
			$chunks[] = array(
				'passage_id'  => $pid,
				'chunk_index' => $chunk_index,
				'status'      => $status,
				'triplets'    => (int) ( $triplet_by_passage[ $pid ] ?? 0 ),
				'updated_at'  => (string) ( $pr['updated_at'] ?? '' ),
				'snippet'     => $snippet,
			);
		}

		$tbl_tq = $db->tbl_triplet_queue();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts['triplets_pending'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			   FROM {$tbl_tq} tq
			   INNER JOIN {$tbl_passage} p ON p.id = tq.passage_id
			  WHERE p.notebook_id = %d
			    AND p.source_id IN ({$src_ph})
			    AND tq.status = %s",
			array_merge( array( $notebook_id ), $source_ids, array( 'pending' ) )
		) );

		$tbl_pe = $db->tbl_passage_entities();
		$tbl_pr = $db->tbl_passage_relations();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts['entities_approved'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT pe.entity_id)
			   FROM {$tbl_pe} pe
			   INNER JOIN {$tbl_passage} p ON p.id = pe.passage_id
			  WHERE p.notebook_id = %d AND p.source_id IN ({$src_ph})",
			array_merge( array( $notebook_id ), $source_ids )
		) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$counts['relations_approved'] = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT pr.relation_id)
			   FROM {$tbl_pr} pr
			   INNER JOIN {$tbl_passage} p ON p.id = pr.passage_id
			  WHERE p.notebook_id = %d AND p.source_id IN ({$src_ph})",
			array_merge( array( $notebook_id ), $source_ids )
		) );

		$job       = null;
		$job_id    = 0;
		$job_phase = '';
		$job_state = '';

		if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
			$ldb = BizCity_TwinChat_Learning_Database::instance();
			if ( $ldb->is_ready() ) {
				$tbl_jobs = $ldb->table_jobs();
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$job_row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, notebook_id, source_id, source_title, user_id, status, phase,
					        progress, passages_processed, triplets_extracted, entities_approved,
					        batches_total, batches_done, entity_ids, error, started_at, finished_at, created_at
					   FROM {$tbl_jobs}
					  WHERE notebook_id = %d AND source_id IN ({$src_ph})
					  ORDER BY id DESC LIMIT 1",
					array_merge( array( $notebook_id ), $source_ids )
				), ARRAY_A );

				if ( is_array( $job_row ) ) {
					$job_id    = (int) $job_row['id'];
					$job_phase = (string) ( $job_row['phase'] ?? '' );
					$job_state = (string) ( $job_row['status'] ?? '' );
					$job = array(
						'id'                 => $job_id,
						'notebook_id'        => (int) $job_row['notebook_id'],
						'source_id'          => (int) $job_row['source_id'],
						'source_title'       => (string) ( $job_row['source_title'] ?? '' ),
						'user_id'            => (int) $job_row['user_id'],
						'status'             => $job_state,
						'phase'              => $job_phase,
						'progress'           => (int) ( $job_row['progress'] ?? 0 ),
						'passages_processed' => (int) ( $job_row['passages_processed'] ?? 0 ),
						'triplets_extracted' => (int) ( $job_row['triplets_extracted'] ?? 0 ),
						'entities_approved'  => (int) ( $job_row['entities_approved'] ?? 0 ),
						'batches_total'      => (int) ( $job_row['batches_total'] ?? 0 ),
						'batches_done'       => (int) ( $job_row['batches_done'] ?? 0 ),
						'error'              => isset( $job_row['error'] ) ? (string) $job_row['error'] : null,
						'started_at'         => isset( $job_row['started_at'] ) ? (string) $job_row['started_at'] : null,
						'finished_at'        => isset( $job_row['finished_at'] ) ? (string) $job_row['finished_at'] : null,
						'created_at'         => (string) ( $job_row['created_at'] ?? '' ),
					);
				}
			}
		}

		$events = array();
		if ( class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
			$progress_rows = BizCity_KG_Source_Progress_Log::get_for_source( $kg_source_id, $event_limit );
			if ( $legacy_source_id > 0 && $legacy_source_id !== $kg_source_id ) {
				$progress_rows = array_merge( $progress_rows, BizCity_KG_Source_Progress_Log::get_for_source( $legacy_source_id, $event_limit ) );
			}
			if ( ! empty( $progress_rows ) ) {
				usort( $progress_rows, static function ( $a, $b ) {
					return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
				} );
				foreach ( $progress_rows as $pe ) {
					$payload = isset( $pe['payload'] ) && is_array( $pe['payload'] ) ? $pe['payload'] : array();
					$event   = (string) ( $pe['event'] ?? 'log' );
					if ( class_exists( 'BizCity_TwinChat_Learning_Events' ) ) {
						// [2026-07-27 Johnny Chu] PHASE-0.51 — enforce public
						// payload allowlist/redaction for share timeline rows.
						$payload = BizCity_TwinChat_Learning_Events::sanitize_payload_for_output( $event, $payload, 'public' );
					}
					$events[] = array(
						'id'         => (int) ( $pe['id'] ?? 0 ),
						'ts'         => (string) ( $pe['created_at'] ?? '' ),
						'level'      => $this->share_source_event_level( $event, $payload ),
						'event'      => $event,
						'message'    => $this->share_source_event_message( $event, $payload ),
						'passage_id' => isset( $pe['passage_id'] ) ? (int) $pe['passage_id'] : 0,
						'job_id'     => 0,
						'payload'    => $payload,
					);
				}
			}
		}

		if ( class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
			$ldb = BizCity_TwinChat_Learning_Database::instance();
			if ( $ldb->is_ready() ) {
				$tbl_events = $ldb->table_events();
				if ( $job_id > 0 ) {
					$event_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, job_id, ts, event, payload
						   FROM {$tbl_events}
						  WHERE notebook_id = %d AND job_id = %d
						  ORDER BY id DESC
						  LIMIT %d",
						$notebook_id,
						$job_id,
						$event_limit
					), ARRAY_A );
				} else {
					$event_rows = $wpdb->get_results( $wpdb->prepare(
						"SELECT id, job_id, ts, event, payload
						   FROM {$tbl_events}
						  WHERE notebook_id = %d
						  ORDER BY id DESC
						  LIMIT %d",
						$notebook_id,
						$event_limit
					), ARRAY_A );
				}
				$event_rows = array_reverse( (array) $event_rows );
				foreach ( $event_rows as $er ) {
					$event   = (string) ( $er['event'] ?? 'log' );
					$payload = array();
					if ( isset( $er['payload'] ) && is_string( $er['payload'] ) && $er['payload'] !== '' ) {
						$decoded = json_decode( $er['payload'], true );
						if ( is_array( $decoded ) ) {
							$payload = $decoded;
						}
					}
					if ( class_exists( 'BizCity_TwinChat_Learning_Events' ) ) {
						$payload = BizCity_TwinChat_Learning_Events::sanitize_payload_for_output( $event, $payload, 'public' );
					}

					$payload_source_id  = isset( $payload['source_id'] ) ? (int) $payload['source_id'] : 0;
					$payload_passage_id = isset( $payload['passage_id'] ) ? (int) $payload['passage_id'] : 0;
					if ( $payload_source_id > 0 ) {
						if ( ! in_array( $payload_source_id, $source_ids, true ) ) {
							continue;
						}
					} elseif ( $payload_passage_id > 0 ) {
						if ( empty( $passage_set ) || ! isset( $passage_set[ $payload_passage_id ] ) ) {
							continue;
						}
					} elseif ( $job_id <= 0 ) {
						continue;
					}

					$events[] = array(
						'id'         => (int) ( $er['id'] ?? 0 ),
						'ts'         => (string) ( $er['ts'] ?? '' ),
						'level'      => $this->share_source_event_level( $event, $payload ),
						'event'      => $event,
						'message'    => $this->share_source_event_message( $event, $payload ),
						'passage_id' => $payload_passage_id,
						'job_id'     => isset( $er['job_id'] ) ? (int) $er['job_id'] : 0,
						'payload'    => $payload,
					);
				}
			}
		}

		if ( count( $events ) > $event_limit ) {
			$events = array_slice( $events, -1 * $event_limit );
		}

		$done     = (int) $counts['done'];
		$skipped  = (int) $counts['skipped'];
		$total    = (int) $counts['total'];
		$progress = $total > 0 ? round( min( 1, max( 0, ( $done + $skipped ) / $total ) ), 4 ) : 0.0;

		$status = 'unknown';
		if ( $job_state === 'failed' || (string) ( $kg_row['status'] ?? '' ) === 'error' ) {
			$status = 'failed';
		} elseif ( $job_state === 'queued' || $job_phase === 'queued' ) {
			$status = 'queued';
		} elseif ( $job_phase === 'approving' ) {
			$status = 'approving';
		} elseif ( $job_phase === 'extracting' || ( $total > 0 && $done < $total ) ) {
			$status = 'extracting';
		} elseif ( $job_state === 'done' || ( $total > 0 && $done >= $total && (int) $counts['triplets_pending'] <= 0 ) ) {
			$status = 'done';
			$progress = $total > 0 ? 1.0 : $progress;
		} elseif ( (string) ( $kg_row['status'] ?? '' ) === 'processing' ) {
			$status = 'materializing';
		}

		$phases = array(
			array(
				'id'          => 'materialize',
				'label'       => 'Materialize text',
				'status'      => $total > 0 ? 'done' : ( (string) ( $kg_row['status'] ?? '' ) === 'processing' ? 'running' : 'pending' ),
				'count_done'  => $total > 0 ? 1 : 0,
				'count_total' => 1,
			),
			array(
				'id'          => 'chunk',
				'label'       => 'Chunking',
				'status'      => $total > 0 ? 'done' : 'pending',
				'count_done'  => $total,
				'count_total' => $total,
			),
			array(
				'id'          => 'extract',
				'label'       => 'Graph extraction',
				'status'      => ( $counts['error'] > 0 && $done === 0 ) ? 'error' : ( $total > 0 && $done >= $total ? 'done' : ( $total > 0 ? 'running' : 'pending' ) ),
				'count_done'  => $done,
				'count_total' => $total,
			),
			array(
				'id'          => 'approve',
				'label'       => 'Approve triplets',
				'status'      => (int) $counts['triplets_pending'] > 0 ? 'running' : ( $done > 0 ? 'done' : 'pending' ),
				'count_done'  => (int) $counts['relations_approved'],
				'count_total' => (int) $counts['relations_approved'] + (int) $counts['triplets_pending'],
			),
		);

		$markers = array_values( array_filter( array(
			$job_id > 0 ? 'job=' . $job_id : '',
			'kg_source=' . $kg_source_id,
			$legacy_source_id > 0 ? 'legacy_source=' . $legacy_source_id : '',
		) ) );

		// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-12 — provide a lightweight,
		// source-scoped KG preview payload for public share visualize panel.
		$graph_preview = $this->build_share_graph_preview( $notebook_id, $passage_ids, 220, 420 );

		return array(
			'source_id'        => $source_id,
			'kg_source_id'     => $kg_source_id,
			'legacy_source_id' => $legacy_source_id > 0 ? $legacy_source_id : null,
			'notebook_id'      => $notebook_id,
			'title'            => (string) ( $kg_row['title'] ?? '' ),
			'status'           => $status,
			'progress'         => $progress,
			'error_message'    => '',
			'error_code'       => '',
			'retryable'        => false,
			'counts'           => $counts,
			'job'              => $job,
			'phases'           => $phases,
			'chunks'           => $chunks,
			'events'           => $events,
			'graph_preview'    => $graph_preview,
			'raw_log_hint'     => array(
				'date'    => gmdate( 'Y-m-d' ),
				'markers' => $markers,
			),
		);
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-12 — build a source-scoped
	 * graph preview (nodes/links) for public share visualization.
	 */
	private function build_share_graph_preview( $notebook_id, array $passage_ids, $max_nodes = 220, $max_links = 420 ) {
		global $wpdb;

		$notebook_id = (int) $notebook_id;
		$max_nodes   = max( 40, min( 400, (int) $max_nodes ) );
		$max_links   = max( 80, min( 1000, (int) $max_links ) );

		$empty = array(
			'nodes' => array(),
			'links' => array(),
			'meta'  => array( 'nodes' => 0, 'links' => 0, 'mode' => 'empty' ),
		);

		if ( $notebook_id <= 0 || empty( $passage_ids ) || ! class_exists( 'BizCity_KG_Database' ) ) {
			return $empty;
		}

		$passage_ids = array_values( array_unique( array_filter( array_map( 'intval', $passage_ids ), static function ( $v ) {
			return $v > 0;
		} ) ) );
		if ( empty( $passage_ids ) ) {
			return $empty;
		}
		if ( count( $passage_ids ) > 260 ) {
			$passage_ids = array_slice( $passage_ids, -260 );
		}

		$db      = BizCity_KG_Database::instance();
		$tbl_pe  = $db->tbl_passage_entities();
		$tbl_pr  = $db->tbl_passage_relations();
		$tbl_ent = $db->tbl_entities();
		$tbl_rel = $db->tbl_relations();

		$ps_ph = implode( ',', array_fill( 0, count( $passage_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$entity_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT entity_id
			   FROM {$tbl_pe}
			  WHERE passage_id IN ({$ps_ph})
			  LIMIT %d",
			array_merge( $passage_ids, array( $max_nodes * 3 ) )
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$relation_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT relation_id
			   FROM {$tbl_pr}
			  WHERE passage_id IN ({$ps_ph})
			  LIMIT %d",
			array_merge( $passage_ids, array( $max_links * 3 ) )
		) );

		$entity_ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) $entity_ids ) ) ) );
		$relation_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $relation_ids ) ) ) );

		$rel_rows = array();
		if ( ! empty( $relation_ids ) ) {
			$rel_ph = implode( ',', array_fill( 0, count( $relation_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rel_rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, head_entity_id, tail_entity_id, predicate, weight
				   FROM {$tbl_rel}
				  WHERE notebook_id = %d
				    AND id IN ({$rel_ph})
				    AND status = %s
				  ORDER BY weight DESC, id DESC
				  LIMIT %d",
				array_merge( array( $notebook_id ), $relation_ids, array( 'approved', $max_links * 2 ) )
			), ARRAY_A );

			foreach ( (array) $rel_rows as $rr ) {
				$entity_ids[] = (int) ( $rr['head_entity_id'] ?? 0 );
				$entity_ids[] = (int) ( $rr['tail_entity_id'] ?? 0 );
			}
			$entity_ids = array_values( array_unique( array_filter( array_map( 'intval', $entity_ids ) ) ) );
		}

		if ( empty( $entity_ids ) ) {
			return $empty;
		}

		$ent_ph = implode( ',', array_fill( 0, count( $entity_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$entity_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, name, type, weight
			   FROM {$tbl_ent}
			  WHERE notebook_id = %d
			    AND id IN ({$ent_ph})
			    AND status = %s
			  ORDER BY weight DESC, id DESC
			  LIMIT %d",
			array_merge( array( $notebook_id ), $entity_ids, array( 'approved', $max_nodes * 2 ) )
		), ARRAY_A );

		if ( empty( $entity_rows ) ) {
			return $empty;
		}

		$entity_rows = array_slice( (array) $entity_rows, 0, $max_nodes );
		$allowed_ids = array();
		$nodes       = array();
		foreach ( $entity_rows as $er ) {
			$eid = (int) ( $er['id'] ?? 0 );
			if ( $eid <= 0 ) {
				continue;
			}
			$allowed_ids[ $eid ] = true;
			$nodes[] = array(
				'id'     => $eid,
				'label'  => (string) ( $er['name'] ?? '' ),
				'type'   => (string) ( $er['type'] ?? 'concept' ),
				'weight' => (int) ( $er['weight'] ?? 1 ),
			);
		}

		$links = array();
		foreach ( (array) $rel_rows as $rr ) {
			$hid = (int) ( $rr['head_entity_id'] ?? 0 );
			$tid = (int) ( $rr['tail_entity_id'] ?? 0 );
			if ( $hid <= 0 || $tid <= 0 || $hid === $tid ) {
				continue;
			}
			if ( ! isset( $allowed_ids[ $hid ] ) || ! isset( $allowed_ids[ $tid ] ) ) {
				continue;
			}
			$links[] = array(
				'id'        => (int) ( $rr['id'] ?? 0 ),
				'source'    => $hid,
				'target'    => $tid,
				'predicate' => (string) ( $rr['predicate'] ?? '' ),
				'weight'    => (int) ( $rr['weight'] ?? 1 ),
			);
			if ( count( $links ) >= $max_links ) {
				break;
			}
		}

		if ( empty( $links ) && count( $nodes ) > 1 ) {
			for ( $i = 1; $i < count( $nodes ); $i++ ) {
				$links[] = array(
					'id'        => $i,
					'source'    => (int) $nodes[ $i - 1 ]['id'],
					'target'    => (int) $nodes[ $i ]['id'],
					'predicate' => 'related',
					'weight'    => 1,
				);
				if ( count( $links ) >= $max_links ) {
					break;
				}
			}
		}

		return array(
			'nodes' => $nodes,
			'links' => $links,
			'meta'  => array(
				'nodes' => count( $nodes ),
				'links' => count( $links ),
				'mode'  => 'source_scoped',
			),
		);
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * event level mapping for source snapshot timeline.
	 */
	private function share_source_event_level( $event, array $payload = array() ) {
		$event = strtolower( (string) $event );
		$level = isset( $payload['level'] ) ? strtolower( (string) $payload['level'] ) : '';
		if ( in_array( $level, array( 'info', 'warn', 'error', 'ok' ), true ) ) {
			return $level;
		}
		if ( $level === 'step' ) {
			return 'info';
		}
		if ( strpos( $event, 'error' ) !== false || $event === 'failed' ) {
			return 'error';
		}
		if ( strpos( $event, 'done' ) !== false || strpos( $event, 'complete' ) !== false ) {
			return 'ok';
		}
		if ( strpos( $event, 'warn' ) !== false ) {
			return 'warn';
		}
		return 'info';
	}

	/**
	 * [2026-07-26 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * readable event message for source snapshot timeline.
	 */
	private function share_source_event_message( $event, array $payload = array() ) {
		if ( isset( $payload['msg'] ) && is_string( $payload['msg'] ) && $payload['msg'] !== '' ) {
			return (string) $payload['msg'];
		}
		$event = strtolower( (string) $event );
		if ( $event === 'progress' ) {
			$processed = isset( $payload['processed'] ) ? (int) $payload['processed'] : 0;
			$remaining = isset( $payload['remaining'] ) ? (int) $payload['remaining'] : 0;
			$triplets  = isset( $payload['total_triplets'] ) ? (int) $payload['total_triplets'] : 0;
			return sprintf( 'Processed %d passages, %d triplets, remaining %d.', $processed, $triplets, $remaining );
		}
		if ( $event === 'passage_done' ) {
			$triplets = isset( $payload['triplets'] ) ? (int) $payload['triplets'] : 0;
			return sprintf( 'Passage extracted successfully (%d triplets).', $triplets );
		}
		if ( $event === 'passage_error' ) {
			$err = isset( $payload['error'] ) ? (string) $payload['error'] : 'unknown_error';
			return 'Passage extraction failed: ' . $err;
		}
		if ( $event === 'batch_done' ) {
			$processed = isset( $payload['processed'] ) ? (int) $payload['processed'] : 0;
			$triplets  = isset( $payload['total_triplets'] ) ? (int) $payload['total_triplets'] : 0;
			$errors    = isset( $payload['errors'] ) ? (int) $payload['errors'] : 0;
			return sprintf( 'Batch done: %d passages, %d triplets, %d errors.', $processed, $triplets, $errors );
		}
		if ( $event === 'done' ) {
			return 'Learning job completed.';
		}
		if ( $event === 'job' && isset( $payload['status'] ) ) {
			return 'Job status: ' . (string) $payload['status'];
		}
		return str_replace( '_', ' ', $event );
	}

	/**
	 * [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-SHARE-LINK —
	 * render simple standalone tabs page for token-based public share links.
	 */
	private function render_public_share_html( array $payload ) {
		$job          = isset( $payload['job'] ) && is_array( $payload['job'] ) ? $payload['job'] : array();
		$job_id       = (int) ( $job['id'] ?? 0 );
		$source_snap  = isset( $payload['source_snapshot'] ) && is_array( $payload['source_snapshot'] ) ? $payload['source_snapshot'] : array();
		$source_counts= isset( $source_snap['counts'] ) && is_array( $source_snap['counts'] ) ? $source_snap['counts'] : array();
		$source_phases= isset( $source_snap['phases'] ) && is_array( $source_snap['phases'] ) ? $source_snap['phases'] : array();
		$source_title = (string) ( $source_snap['title'] ?? '' );
		$source_status= (string) ( $source_snap['status'] ?? 'unknown' );
		$source_progress = isset( $source_snap['progress'] ) ? (float) $source_snap['progress'] : 0.0;
		$job_status   = $source_status !== '' && $source_status !== 'unknown'
			? $source_status
			: (string) ( $job['status'] ?? 'unknown' );
		$job_phase    = (string) ( $job['phase'] ?? '' );
		$lane_summary = isset( $payload['lane_summary'] ) && is_array( $payload['lane_summary'] ) ? $payload['lane_summary'] : array();
		$by_lane      = isset( $lane_summary['by_lane'] ) && is_array( $lane_summary['by_lane'] ) ? $lane_summary['by_lane'] : array();
		$switches     = isset( $lane_summary['switches'] ) && is_array( $lane_summary['switches'] ) ? $lane_summary['switches'] : array();
		$chunks       = isset( $payload['chunks'] ) && is_array( $payload['chunks'] ) ? $payload['chunks'] : array();
		$events       = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : array();
		$lines        = isset( $payload['lines'] ) && is_array( $payload['lines'] ) ? $payload['lines'] : array();
		$stats        = isset( $payload['stats'] ) && is_array( $payload['stats'] ) ? $payload['stats'] : array();
		$graph_preview = isset( $source_snap['graph_preview'] ) && is_array( $source_snap['graph_preview'] ) ? $source_snap['graph_preview'] : array();
		$source_mode  = (string) ( $lane_summary['source_mode'] ?? 'unknown' );

		$cron = isset( $by_lane['cron'] ) && is_array( $by_lane['cron'] ) ? $by_lane['cron'] : array();
		$ajax = isset( $by_lane['ajax'] ) && is_array( $by_lane['ajax'] ) ? $by_lane['ajax'] : array();
		$done_count       = (int) ( $source_counts['done'] ?? 0 );
		$processing_count = (int) ( $source_counts['processing'] ?? 0 );
		$pending_count    = (int) ( $source_counts['pending'] ?? 0 );
		$error_count      = (int) ( $source_counts['error'] ?? 0 );
		$triplet_pending  = (int) ( $source_counts['triplets_pending'] ?? 0 );
		$total_count      = max( 0, (int) ( $source_counts['total'] ?? 0 ) );
		$progress_fill_pct = $total_count > 0 ? (int) round( max( 0, min( 100, ( $done_count / $total_count ) * 100 ) ) ) : 0;

		$console_lines = array_map( 'strval', $lines );
		// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-10 — when job-scoped
		// file log is sparse, fall back to source timeline so console tab
		// still reflects realtime learning activity from canonical events.
		if ( empty( $console_lines ) && ! empty( $events ) ) {
			foreach ( $events as $event_row ) {
				$ts      = isset( $event_row['ts'] ) ? (string) $event_row['ts'] : '';
				$level   = isset( $event_row['level'] ) ? strtoupper( (string) $event_row['level'] ) : 'INFO';
				$event   = isset( $event_row['event'] ) ? (string) $event_row['event'] : 'event';
				$message = isset( $event_row['message'] ) ? (string) $event_row['message'] : '';
				$prefix  = $ts !== '' ? '[' . $ts . ' UTC] ' : '';
				$console_lines[] = trim( $prefix . $level . ' ' . $event . ' ' . $message );
			}
		}
		$console_text = implode( "\n", $console_lines );
		$progress_pct = (int) round( max( 0, min( 100, $source_progress * 100 ) ) );
		$json_link    = '?format=json';

		ob_start();
		?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Learning Share</title>
<style>
body { margin:0; font-family: "Segoe UI", Arial, sans-serif; background: linear-gradient(160deg,#f8fafc,#e2e8f0); color:#0f172a; }
.wrap { max-width: 1100px; margin: 18px auto; padding: 0 14px; }
.card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; box-shadow:0 10px 28px rgba(2,6,23,.06); }
.head { padding:16px 18px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
.title { margin:0; font-size:20px; font-weight:700; }
.muted { color:#64748b; font-size:13px; }
.chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.chip { padding:4px 10px; border:1px solid #cbd5e1; border-radius:999px; font-size:12px; background:#f8fafc; }
.grid { display:grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap:10px; padding:14px 18px; }
.kpi { border:1px solid #e2e8f0; border-radius:10px; padding:10px; background:#f8fafc; }
.kpi b { display:block; font-size:19px; margin-top:4px; }
.tabs { display:flex; gap:8px; padding:0 18px 14px; border-bottom:1px solid #e2e8f0; }
.tab { border:1px solid #cbd5e1; background:#fff; color:#334155; border-radius:9px; padding:7px 12px; font-size:13px; cursor:pointer; }
.tab.active { background:#0f172a; color:#fff; border-color:#0f172a; }
.pane { display:none; padding:14px 18px 18px; }
.pane.active { display:block; }
.process-wrap { padding:0 18px 12px; }
.process-track { width:100%; height:8px; border-radius:999px; background:#e2e8f0; overflow:hidden; }
.process-fill { height:100%; background:linear-gradient(90deg,#ec4899,#f59e0b); width:0%; transition:width .35s ease; }
.status-counters { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.counter-chip { padding:6px 10px; border:1px solid #cbd5e1; border-radius:10px; background:#f8fafc; font-size:12px; color:#334155; }
.viz-panel { border:1px solid #e2e8f0; border-radius:12px; background:linear-gradient(180deg,#f8fbff,#f4f8ff); padding:12px; margin:10px 0 14px; }
.viz-head { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; flex-wrap:wrap; }
.viz-title { font-weight:700; color:#0f172a; font-size:16px; }
.viz-sub { margin-top:2px; font-size:12px; color:#64748b; }
.viz-metrics { display:flex; flex-wrap:wrap; gap:8px; }
.viz-chip { border:1px solid #cbd5e1; background:#fff; color:#334155; border-radius:999px; padding:4px 10px; font-size:12px; }
.viz-chip b { color:#0f172a; }
.viz-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-top:10px; }
.viz-toolbar-left { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.viz-toolbar-right { display:flex; align-items:center; gap:10px; }
.viz-label { color:#94a3b8; font-size:12px; letter-spacing:.04em; text-transform:uppercase; }
.viz-renderer { display:flex; align-items:center; gap:6px; }
.viz-render-btn { border:1px solid #cbd5e1; border-radius:999px; background:#fff; color:#334155; padding:3px 10px; font-size:12px; cursor:pointer; }
.viz-render-btn.active { background:#0f172a; color:#fff; border-color:#0f172a; }
.viz-toggle { display:inline-flex; align-items:center; gap:6px; font-size:12px; color:#475569; }
.viz-toggle input { width:14px; height:14px; }
.viz-canvas { margin-top:8px; border:1px solid #dce7f3; background:#ffffff; border-radius:12px; height:380px; overflow:hidden; position:relative; }
.viz-svg { width:100%; height:100%; display:block; }
.viz-edge { stroke:#dbe7f5; stroke-width:1; opacity:.7; }
.viz-controls { position:absolute; top:10px; right:10px; display:flex; flex-direction:column; gap:8px; z-index:2; }
.viz-control-btn { width:42px; height:42px; border:1px solid #d6dde8; border-radius:10px; background:#fff; color:#334155; font-size:24px; line-height:1; cursor:pointer; box-shadow:0 2px 6px rgba(15,23,42,.08); }
.viz-control-btn:last-child { font-size:18px; }
.viz-tooltip { position:absolute; top:12px; left:12px; z-index:2; border:1px solid #dce3ee; border-radius:10px; background:rgba(255,255,255,.95); box-shadow:0 8px 18px rgba(15,23,42,.10); padding:10px 12px; max-width:220px; }
.viz-tooltip-title { margin:0; font-weight:700; color:#0f172a; font-size:28px; line-height:1.1; }
.viz-tooltip-type { margin-top:4px; color:#334155; font-size:16px; }
.viz-tooltip-meta { margin-top:4px; color:#94a3b8; font-size:12px; }
.viz-legend { margin-top:10px; display:flex; flex-wrap:wrap; gap:12px; color:#475569; font-size:12px; }
.viz-legend-item { display:flex; align-items:center; gap:6px; }
.viz-dot { width:9px; height:9px; border-radius:999px; display:inline-block; border:1px solid rgba(255,255,255,.8); }
.viz-dot.done { background:#a78bfa; }
.viz-dot.processing { background:#f472b6; }
.viz-dot.pending { background:#94a3b8; }
.viz-dot.error { background:#fb7185; }
.viz-dot.person { background:#60a5fa; }
.viz-dot.organization { background:#4ade80; }
.viz-dot.concept { background:#c084fc; }
.viz-dot.other { background:#94a3b8; }
.table { width:100%; border-collapse: collapse; font-size:13px; }
.table th,.table td { border-bottom:1px solid #e2e8f0; padding:8px 6px; text-align:left; vertical-align:top; }
.ok { color:#047857; font-weight:600; }
.err { color:#b91c1c; font-weight:600; }
.chunk-list { display:flex; flex-direction:column; gap:10px; }
.chunk-card { border:1px solid #dbe2ea; border-radius:12px; background:#fff; padding:10px 12px; }
.chunk-head { display:flex; justify-content:space-between; align-items:center; gap:10px; }
.chunk-title { font-weight:600; color:#0f172a; }
.chunk-meta { margin-top:6px; color:#475569; font-size:12px; }
.chunk-snippet { margin-top:8px; color:#334155; font-size:13px; line-height:1.45; white-space:pre-wrap; }
.status-badge { padding:2px 10px; border-radius:999px; font-size:12px; border:1px solid #cbd5e1; background:#f8fafc; color:#334155; }
.status-badge.done { color:#047857; border-color:#6ee7b7; background:#ecfdf5; }
.status-badge.processing { color:#b45309; border-color:#fcd34d; background:#fffbeb; }
.status-badge.pending { color:#1d4ed8; border-color:#93c5fd; background:#eff6ff; }
.status-badge.error { color:#b91c1c; border-color:#fca5a5; background:#fef2f2; }
.code { font-family: Consolas, "Courier New", monospace; font-size:12px; white-space:pre-wrap; background:#0f172a; color:#e2e8f0; border-radius:10px; padding:12px; max-height:440px; overflow:auto; }
.switches { margin:10px 0 0; padding-left:16px; }
.switches li { margin:4px 0; font-size:13px; }
@media (max-width: 900px) { .grid { grid-template-columns: repeat(2,minmax(0,1fr)); } }
@media (max-width: 560px) { .grid { grid-template-columns: 1fr; } .tabs { flex-wrap:wrap; } }
</style>
</head>
<body>
<div class="wrap">
	<div class="card">
		<div class="head">
			<div>
				<h1 class="title">Theo dõi Learning</h1>
				<div class="muted" id="share-summary-line">
					Notebook #<?php echo (int) ( $payload['notebook_id'] ?? 0 ); ?> · Source #<?php echo (int) ( $payload['source_id'] ?? 0 ); ?> · Job #<?php echo (int) $job_id; ?>
				</div>
				<?php if ( $source_title !== '' ) : ?>
					<div id="share-source-title" style="margin-top:6px;font-weight:600;"><?php echo esc_html( $source_title ); ?></div>
				<?php else : ?>
					<div id="share-source-title" style="margin-top:6px;font-weight:600;"></div>
				<?php endif; ?>
				<div class="chips">
					<span class="chip" id="share-chip-mode">mode: <?php echo esc_html( $source_mode ); ?></span>
					<span class="chip" id="share-chip-status">status: <?php echo esc_html( $job_status ); ?></span>
					<span class="chip" id="share-chip-progress">progress: <?php echo (int) $progress_pct; ?>%</span>
					<span class="chip">auto refresh: 10s</span>
					<span class="chip" id="share-chip-phase">phase: <?php echo esc_html( $job_phase !== '' ? $job_phase : 'unknown' ); ?></span>
					<span class="chip">hết hạn link: <?php echo esc_html( (string) ( $payload['expires_at'] ?? '' ) ); ?> UTC</span>
					<span class="chip" id="share-chip-updated">updated: <?php echo esc_html( gmdate( 'H:i:s' ) ); ?> UTC</span>
				</div>
			</div>
			<div class="muted">
				<div id="share-log-date">Ngày log: <?php echo esc_html( (string) ( $payload['date'] ?? '' ) ); ?></div>
				<div id="share-console-count">Dòng console: <?php echo (int) ( $payload['count'] ?? 0 ); ?></div>
				<div id="share-timeline-count">Dòng timeline: <?php echo (int) count( $events ); ?></div>
				<div><a href="<?php echo esc_attr( $json_link ); ?>">Xem JSON</a></div>
			</div>
		</div>

		<div class="process-wrap">
			<div class="process-track" aria-label="learning progress bar">
				<div class="process-fill" id="share-process-fill" style="width: <?php echo (int) $progress_fill_pct; ?>%;"></div>
			</div>
			<div class="status-counters">
				<span class="counter-chip" id="share-counter-done">done: <?php echo (int) $done_count; ?></span>
				<span class="counter-chip" id="share-counter-processing">processing: <?php echo (int) $processing_count; ?></span>
				<span class="counter-chip" id="share-counter-pending">pending: <?php echo (int) $pending_count; ?></span>
				<span class="counter-chip" id="share-counter-error">error: <?php echo (int) $error_count; ?></span>
				<span class="counter-chip" id="share-counter-triplets">triplets pending: <?php echo (int) $triplet_pending; ?></span>
			</div>
		</div>

		<div class="grid">
			<div class="kpi"><span class="muted">Cron dispatch</span><b id="share-kpi-cron"><?php echo (int) ( $cron['dispatch_rounds'] ?? 0 ); ?></b></div>
			<div class="kpi"><span class="muted">Admin-ajax dispatch</span><b id="share-kpi-ajax"><?php echo (int) ( $ajax['dispatch_rounds'] ?? 0 ); ?></b></div>
			<div class="kpi"><span class="muted">Chunk done</span><b id="share-kpi-chunk-done"><?php echo (int) ( $source_counts['done'] ?? 0 ); ?>/<?php echo (int) ( $source_counts['total'] ?? 0 ); ?></b></div>
			<div class="kpi"><span class="muted">Chunk error</span><b id="share-kpi-chunk-error"><?php echo (int) ( $source_counts['error'] ?? 0 ); ?></b></div>
		</div>

		<div class="tabs" role="tablist">
			<button type="button" class="tab active" data-tab="overview">Tổng quan</button>
			<button type="button" class="tab" data-tab="chunks">Chunk done</button>
			<button type="button" class="tab" data-tab="console">Console log</button>
		</div>

		<div class="pane active" data-pane="overview">
			<p class="muted">Nguồn chạy hiện tại: <b id="share-source-mode"><?php echo esc_html( $source_mode ); ?></b>. Khi vừa có cron vừa có admin-ajax, hệ thống tự chuyển lane theo runtime.</p>
			<div class="viz-panel">
				<div class="viz-head">
					<div>
						<div class="viz-title">Knowledge Graph Formation</div>
						<div class="viz-sub">Mô phỏng node-link theo tiến độ extraction và approval realtime.</div>
					</div>
					<div class="viz-metrics">
						<span class="viz-chip">entities: <b id="share-viz-entities"><?php echo (int) ( $source_counts['entities_approved'] ?? 0 ); ?></b></span>
						<span class="viz-chip">relations: <b id="share-viz-relations"><?php echo (int) ( $source_counts['relations_approved'] ?? 0 ); ?></b></span>
						<span class="viz-chip">nodes: <b id="share-viz-nodes"><?php echo (int) max( 28, min( 220, $total_count > 0 ? $total_count : 28 ) ); ?></b></span>
						<span class="viz-chip">phase: <b id="share-viz-phase"><?php echo esc_html( $job_phase !== '' ? $job_phase : 'unknown' ); ?></b></span>
					</div>
				</div>
				<div class="viz-toolbar">
					<div class="viz-toolbar-left">
						<span class="viz-label">renderer</span>
						<div class="viz-renderer">
							<button type="button" class="viz-render-btn active" id="share-viz-renderer-nexus" data-renderer="nexus">Nexus</button>
						</div>
					</div>
					<div class="viz-toolbar-right">
						<label class="viz-toggle"><input type="checkbox" id="share-viz-hide-orphans" />Ẩn node mồ côi</label>
					</div>
				</div>
				<div class="viz-canvas" id="share-viz-graph" aria-label="KG formation visualization">
					<div id="share-viz-stage" style="position:absolute;inset:0;"></div>
					<div class="viz-tooltip" id="share-viz-tooltip" style="display:none;"></div>
					<div class="viz-controls">
						<button type="button" class="viz-control-btn" id="share-viz-zoom-in" aria-label="Zoom in">+</button>
						<button type="button" class="viz-control-btn" id="share-viz-zoom-out" aria-label="Zoom out">-</button>
						<button type="button" class="viz-control-btn" id="share-viz-reset" aria-label="Reset view">&#8857;</button>
					</div>
				</div>
				<div class="viz-legend">
					<span class="viz-legend-item"><span class="viz-dot done"></span>done</span>
					<span class="viz-legend-item"><span class="viz-dot processing"></span>processing</span>
					<span class="viz-legend-item"><span class="viz-dot pending"></span>pending</span>
					<span class="viz-legend-item"><span class="viz-dot error"></span>error</span>
					<span class="viz-legend-item"><span class="viz-dot person"></span>person</span>
					<span class="viz-legend-item"><span class="viz-dot organization"></span>organization</span>
					<span class="viz-legend-item"><span class="viz-dot concept"></span>concept</span>
					<span class="viz-legend-item"><span class="viz-dot other"></span>other</span>
				</div>
			</div>
			<?php if ( ! empty( $source_phases ) ) : ?>
				<table class="table" style="margin-bottom:10px;">
					<thead>
						<tr>
							<th>Phase</th>
							<th>Trạng thái</th>
							<th>Tiến độ</th>
						</tr>
					</thead>
					<tbody id="share-phases-body">
						<?php foreach ( $source_phases as $phase ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $phase['label'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $phase['status'] ?? '' ) ); ?></td>
								<td><?php echo (int) ( $phase['count_done'] ?? 0 ); ?>/<?php echo (int) ( $phase['count_total'] ?? 0 ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( empty( $switches ) ) : ?>
				<p class="muted">Chưa ghi nhận mốc chuyển lane cron/ajax trong tập log này.</p>
			<?php else : ?>
				<p><b>Mốc auto switch cron/ajax:</b></p>
				<ul class="switches">
					<?php foreach ( $switches as $sw ) : ?>
						<li>
							<?php echo esc_html( (string) ( $sw['time'] ?? '' ) ); ?> UTC: <?php echo esc_html( (string) ( $sw['from'] ?? '' ) ); ?> → <?php echo esc_html( (string) ( $sw['to'] ?? '' ) ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>

		<div class="pane" data-pane="chunks">
			<p class="muted" id="share-chunks-empty" <?php echo empty( $chunks ) ? '' : 'style="display:none;"'; ?>>Chưa có chunk nào cho source này.</p>
			<div class="chunk-list" id="share-chunks-list" <?php echo empty( $chunks ) ? 'style="display:none;"' : ''; ?>>
				<?php if ( ! empty( $chunks ) ) : ?>
					<?php foreach ( $chunks as $row ) : ?>
						<?php
						$status = sanitize_key( (string) ( $row['status'] ?? 'pending' ) );
						if ( ! in_array( $status, array( 'done', 'processing', 'pending', 'error' ), true ) ) {
							$status = 'pending';
						}
						$chunk_index = isset( $row['chunk_index'] ) && $row['chunk_index'] !== null ? (int) $row['chunk_index'] : null;
						$title = 'Passage #' . (int) ( $row['passage_id'] ?? 0 );
						if ( $chunk_index !== null ) {
							$title .= ' • chunk ' . $chunk_index;
						}
						?>
						<div class="chunk-card">
							<div class="chunk-head">
								<div class="chunk-title"><?php echo esc_html( $title ); ?></div>
								<span class="status-badge <?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status ); ?></span>
							</div>
							<div class="chunk-meta">triplets: <?php echo (int) ( $row['triplets'] ?? 0 ); ?></div>
							<div class="chunk-meta">updated: <?php echo esc_html( (string) ( $row['updated_at'] ?? ( $row['time'] ?? '' ) ) ); ?></div>
							<?php if ( ! empty( $row['snippet'] ) ) : ?>
								<div class="chunk-snippet"><?php echo esc_html( (string) $row['snippet'] ); ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="pane" data-pane="console">
			<?php if ( trim( $console_text ) === '' ) : ?>
				<p class="muted">Chưa có dòng console theo bộ lọc job hiện tại (job có thể đã hoàn tất ngoài lane log này).</p>
			<?php endif; ?>
			<div class="code" id="share-console-text"><?php echo esc_html( $console_text ); ?></div>
		</div>
	</div>
</div>

<script>
(function () {
	var tabs = document.querySelectorAll('.tab');
	var panes = document.querySelectorAll('.pane');
	function activate(name) {
		for (var i = 0; i < tabs.length; i++) {
			tabs[i].classList.toggle('active', tabs[i].getAttribute('data-tab') === name);
		}
		for (var j = 0; j < panes.length; j++) {
			panes[j].classList.toggle('active', panes[j].getAttribute('data-pane') === name);
		}
	}
	for (var k = 0; k < tabs.length; k++) {
		tabs[k].addEventListener('click', function () {
			activate(this.getAttribute('data-tab'));
		});
	}

	// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-7 — REST heartbeat style
	// polling with partial DOM updates (no full-page reload).
	var refreshMs = 10000;
	var hbUrl = new URL(window.location.href);
	hbUrl.searchParams.set('format', 'json');
	hbUrl.searchParams.set('heartbeat', '1');

	function setText(id, value) {
		var el = document.getElementById(id);
		if (!el) { return; }
		el.textContent = value;
	}

	function escHtml(value) {
		return String(value || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function renderPhases(phases) {
		var body = document.getElementById('share-phases-body');
		if (!body || !Array.isArray(phases)) { return; }
		var html = '';
		for (var i = 0; i < phases.length; i++) {
			var p = phases[i] || {};
			html += '<tr>' +
				'<td>' + escHtml(p.label || '') + '</td>' +
				'<td>' + escHtml(p.status || '') + '</td>' +
				'<td>' + String(parseInt(p.count_done || 0, 10)) + '/' + String(parseInt(p.count_total || 0, 10)) + '</td>' +
			'</tr>';
		}
		body.innerHTML = html;
	}

	function toInt(v) {
		var n = parseInt(v || 0, 10);
		return isNaN(n) ? 0 : n;
	}

	function seeded(index, salt) {
		var x = Math.sin((index + 1) * 12.9898 + salt * 78.233) * 43758.5453;
		return x - Math.floor(x);
	}

	var KG_TYPE_COLORS = {
		person: '#60a5fa',
		organization: '#4ade80',
		location: '#fbbf24',
		event: '#fb923c',
		concept: '#c084fc',
		product: '#f472b6',
		topic: '#22d3ee'
	};

	var KG_STATUS_COLORS = {
		done: '#a78bfa',
		processing: '#f472b6',
		pending: '#94a3b8',
		error: '#fb7185'
	};

	var kgVizState = {
		renderer: 'nexus',
		hideOrphans: false,
		zoom: 1,
		panX: 0,
		panY: 0,
		nodes: [],
		links: [],
		nodeMap: {},
		hoverId: 0,
		dragNodeId: 0,
		panning: false,
		mouseStartX: 0,
		mouseStartY: 0,
		startPanX: 0,
		startPanY: 0,
		alpha: 0,
		rafId: 0,
		dataKey: '',
		bound: false,
		lastCounts: {},
		lastPhases: [],
		lastPreview: {}
	};

	function normalizeNodeStatus(status) {
		var s = String(status || 'pending').toLowerCase();
		if (s !== 'done' && s !== 'processing' && s !== 'pending' && s !== 'error') {
			s = 'pending';
		}
		return s;
	}

	function normalizeNodeType(type) {
		var t = String(type || '').toLowerCase();
		if (!t) {
			return 'other';
		}
		return t;
	}

	function resolveVizPhase(phases) {
		if (!Array.isArray(phases) || phases.length === 0) {
			return 'pending';
		}
		for (var i = 0; i < phases.length; i++) {
			var p = phases[i] || {};
			var st = String(p.status || '').toLowerCase();
			if (st === 'running' || st === 'processing') {
				return String(p.label || p.id || 'running');
			}
		}
		for (var j = phases.length - 1; j >= 0; j--) {
			var d = phases[j] || {};
			if (String(d.status || '').toLowerCase() === 'done') {
				return String(d.label || d.id || 'done');
			}
		}
		return String((phases[0] || {}).label || 'pending');
	}

	function buildStatusPool(counts, targetCount) {
		var done = toInt(counts.done);
		var processing = toInt(counts.processing);
		var pending = toInt(counts.pending);
		var error = toInt(counts.error);
		var total = done + processing + pending + error;
		if (total <= 0) {
			done = 0;
			processing = 0;
			pending = targetCount;
			error = 0;
			total = targetCount;
		}

		var alloc = {
			done: Math.round(targetCount * (done / total)),
			processing: Math.round(targetCount * (processing / total)),
			pending: Math.round(targetCount * (pending / total)),
			error: Math.round(targetCount * (error / total))
		};

		var sum = alloc.done + alloc.processing + alloc.pending + alloc.error;
		while (sum < targetCount) {
			alloc.pending++;
			sum++;
		}
		while (sum > targetCount) {
			if (alloc.pending > 0) {
				alloc.pending--;
			} else if (alloc.processing > 0) {
				alloc.processing--;
			} else if (alloc.done > 0) {
				alloc.done--;
			} else if (alloc.error > 0) {
				alloc.error--;
			}
			sum--;
		}

		var pool = [];
		for (var i = 0; i < alloc.done; i++) { pool.push('done'); }
		for (var j = 0; j < alloc.processing; j++) { pool.push('processing'); }
		for (var k = 0; k < alloc.pending; k++) { pool.push('pending'); }
		for (var h = 0; h < alloc.error; h++) { pool.push('error'); }

		for (var s = pool.length - 1; s > 0; s--) {
			var swapAt = Math.floor(seeded(s, 29) * (s + 1));
			var tmp = pool[s];
			pool[s] = pool[swapAt];
			pool[swapAt] = tmp;
		}
		return pool;
	}

	function buildSyntheticPreview(counts) {
		var totalChunks = toInt(counts.total || 0);
		var nodeCount = totalChunks > 0 ? Math.max(28, Math.min(220, totalChunks)) : 42;
		var pool = buildStatusPool(counts, nodeCount);
		var nodes = [];
		for (var i = 0; i < nodeCount; i++) {
			var st = pool[i] || 'pending';
			nodes.push({
				id: i + 1,
				label: st === 'done' ? ('Entity ' + String(i + 1)) : ('Chunk ' + String(i + 1)),
				type: st === 'processing' ? 'concept' : (st === 'error' ? 'event' : 'person'),
				weight: 1,
				status: st
			});
		}

		var links = [];
		for (var n = 1; n <= nodeCount; n++) {
			var n1 = (n % nodeCount) + 1;
			var n2 = ((n + Math.floor(seeded(n, 15) * Math.max(2, Math.floor(nodeCount / 6)))) % nodeCount) + 1;
			links.push({ id: links.length + 1, source: n, target: n1, predicate: 'related', weight: 1 });
			if (n % 2 === 0) {
				links.push({ id: links.length + 1, source: n, target: n2, predicate: 'related', weight: 1 });
			}
		}

		return {
			nodes: nodes,
			links: links,
			meta: { nodes: nodes.length, links: links.length, mode: 'synthetic' }
		};
	}

	function filterOrphanGraph(preview) {
		if (!kgVizState.hideOrphans || !preview || !Array.isArray(preview.nodes) || !Array.isArray(preview.links)) {
			return preview;
		}
		var connected = {};
		for (var i = 0; i < preview.links.length; i++) {
			var link = preview.links[i] || {};
			connected[toInt(link.source)] = true;
			connected[toInt(link.target)] = true;
		}
		var nodes = [];
		var allow = {};
		for (var n = 0; n < preview.nodes.length; n++) {
			var node = preview.nodes[n] || {};
			var id = toInt(node.id);
			if (id > 0 && connected[id]) {
				nodes.push(node);
				allow[id] = true;
			}
		}
		var links = [];
		for (var l = 0; l < preview.links.length; l++) {
			var edge = preview.links[l] || {};
			var sid = toInt(edge.source);
			var tid = toInt(edge.target);
			if (allow[sid] && allow[tid]) {
				links.push(edge);
			}
		}
		return { nodes: nodes, links: links, meta: preview.meta || {} };
	}

	function inferNodeStatusList(counts, nodeCount) {
		return buildStatusPool(counts, nodeCount);
	}

	function normalizeGraphPreview(counts, preview) {
		var src = preview && Array.isArray(preview.nodes) && preview.nodes.length > 0
			? preview
			: buildSyntheticPreview(counts);
		src = filterOrphanGraph(src);

		var nodes = [];
		var links = [];
		var linkByPair = {};
		var degree = {};
		var nodeStatusPool = inferNodeStatusList(counts, src.nodes.length || 1);

		for (var i = 0; i < src.links.length; i++) {
			var rl = src.links[i] || {};
			var sid = toInt(rl.source);
			var tid = toInt(rl.target);
			if (sid <= 0 || tid <= 0 || sid === tid) {
				continue;
			}
			var minId = sid < tid ? sid : tid;
			var maxId = sid < tid ? tid : sid;
			var pairKey = String(minId) + '|' + String(maxId);
			if (linkByPair[pairKey]) {
				linkByPair[pairKey].weight += Math.max(1, toInt(rl.weight || 1));
				continue;
			}
			var linkObj = {
				id: toInt(rl.id) || (i + 1),
				source: sid,
				target: tid,
				predicate: String(rl.predicate || ''),
				weight: Math.max(1, toInt(rl.weight || 1))
			};
			linkByPair[pairKey] = linkObj;
			links.push(linkObj);
			degree[sid] = (degree[sid] || 0) + 1;
			degree[tid] = (degree[tid] || 0) + 1;
		}

		var mapped = {};
		for (var j = 0; j < src.nodes.length; j++) {
			var rn = src.nodes[j] || {};
			var id = toInt(rn.id);
			if (id <= 0 || mapped[id]) {
				continue;
			}
			mapped[id] = true;
			nodes.push({
				id: id,
				label: String(rn.label || ('Node ' + String(id))),
				type: normalizeNodeType(rn.type || 'other'),
				weight: Math.max(1, toInt(rn.weight || 1)),
				status: normalizeNodeStatus(rn.status || nodeStatusPool[j % nodeStatusPool.length] || 'pending'),
				degree: toInt(degree[id] || 0),
				x: 0, y: 0, vx: 0, vy: 0
			});
		}

		if (nodes.length === 0 && src.nodes.length > 0) {
			for (var p = 0; p < src.nodes.length; p++) {
				nodes.push({
					id: p + 1,
					label: String((src.nodes[p] || {}).label || ('Node ' + String(p + 1))),
					type: normalizeNodeType((src.nodes[p] || {}).type || 'other'),
					weight: 1,
					status: normalizeNodeStatus((src.nodes[p] || {}).status || nodeStatusPool[p % nodeStatusPool.length] || 'pending'),
					degree: 0,
					x: 0, y: 0, vx: 0, vy: 0
				});
			}
		}

		return { nodes: nodes, links: links };
	}

	function computeDataKey(graph) {
		var ids = [];
		for (var i = 0; i < graph.nodes.length && i < 28; i++) {
			ids.push(String(graph.nodes[i].id));
		}
		return (kgVizState.hideOrphans ? '1' : '0') + '|' + graph.nodes.length + '|' + graph.links.length + '|' + ids.join(',');
	}

	function initializeNodePositions(graph, preserveExisting) {
		var host = document.getElementById('share-viz-graph');
		if (!host) { return; }
		var width = Math.max(300, host.clientWidth || 960);
		var height = Math.max(220, host.clientHeight || 380);
		var cx = width / 2;
		var cy = height / 2;
		var radius = Math.min(width, height) * 0.34;

		var prev = {};
		if (preserveExisting) {
			for (var i = 0; i < kgVizState.nodes.length; i++) {
				prev[kgVizState.nodes[i].id] = kgVizState.nodes[i];
			}
		}

		for (var n = 0; n < graph.nodes.length; n++) {
			var node = graph.nodes[n];
			var old = prev[node.id];
			if (old && old.x > 0 && old.y > 0) {
				node.x = old.x;
				node.y = old.y;
				node.vx = old.vx || 0;
				node.vy = old.vy || 0;
				continue;
			}
			var spiral = (2 * Math.PI * n) / Math.max(1, graph.nodes.length) * 3.1;
			var rr = radius * Math.sqrt((n + 1) / Math.max(1, graph.nodes.length));
			node.x = cx + Math.cos(spiral) * rr + (seeded(n, 13) - 0.5) * 24;
			node.y = cy + Math.sin(spiral) * rr + (seeded(n, 17) - 0.5) * 20;
			node.vx = 0;
			node.vy = 0;
		}
	}

	function simulateGraphStep() {
		if (kgVizState.renderer !== 'nexus') {
			return;
		}
		var host = document.getElementById('share-viz-graph');
		if (!host) { return; }
		var nodes = kgVizState.nodes;
		var links = kgVizState.links;
		if (!nodes.length) { return; }

		var width = Math.max(300, host.clientWidth || 960);
		var height = Math.max(220, host.clientHeight || 380);
		var cx = width / 2;
		var cy = height / 2;
		var alpha = kgVizState.alpha;

		for (var i = 0; i < nodes.length; i++) {
			for (var j = i + 1; j < nodes.length; j++) {
				var a = nodes[i];
				var b = nodes[j];
				var dx = b.x - a.x;
				var dy = b.y - a.y;
				var dist = Math.sqrt(dx * dx + dy * dy) || 1;
				var force = (780 * alpha) / (dist * dist);
				var fx = (dx / dist) * force;
				var fy = (dy / dist) * force;
				a.vx -= fx;
				a.vy -= fy;
				b.vx += fx;
				b.vy += fy;
			}
		}

		for (var l = 0; l < links.length; l++) {
			var edge = links[l];
			var s = kgVizState.nodeMap[edge.source];
			var t = kgVizState.nodeMap[edge.target];
			if (!s || !t) { continue; }
			var ex = t.x - s.x;
			var ey = t.y - s.y;
			var ed = Math.sqrt(ex * ex + ey * ey) || 1;
			var targetDist = 92 + Math.min(26, toInt(edge.weight || 1) * 2);
			var spring = (ed - targetDist) * 0.012 * alpha;
			var sx = (ex / ed) * spring;
			var sy = (ey / ed) * spring;
			s.vx += sx;
			s.vy += sy;
			t.vx -= sx;
			t.vy -= sy;
		}

		var bound = Math.max(48, Math.min(width, height) / 2 - 18);
		for (var n = 0; n < nodes.length; n++) {
			var node = nodes[n];
			if (kgVizState.dragNodeId && node.id === kgVizState.dragNodeId) {
				node.vx = 0;
				node.vy = 0;
				continue;
			}
			node.vx += (cx - node.x) * 0.0016 * alpha;
			node.vy += (cy - node.y) * 0.0016 * alpha;
			node.vx *= 0.66;
			node.vy *= 0.66;
			node.x += node.vx;
			node.y += node.vy;

			var bx = node.x - cx;
			var by = node.y - cy;
			var bd = Math.sqrt(bx * bx + by * by) || 1;
			if (bd > bound) {
				var k = bound / bd;
				node.x = cx + bx * k;
				node.y = cy + by * k;
				node.vx *= 0.46;
				node.vy *= 0.46;
			}
		}
	}

	function renderVizTooltip() {
		var tooltip = document.getElementById('share-viz-tooltip');
		if (!tooltip) { return; }
		if (!kgVizState.hoverId || !kgVizState.nodeMap[kgVizState.hoverId]) {
			tooltip.style.display = 'none';
			tooltip.innerHTML = '';
			return;
		}
		var node = kgVizState.nodeMap[kgVizState.hoverId];
		tooltip.style.display = 'block';
		tooltip.innerHTML = '' +
			'<div class="viz-tooltip-title">' + escHtml(node.label || '') + '</div>' +
			'<div class="viz-tooltip-type">' + escHtml(node.type || 'other') + '</div>' +
			'<div class="viz-tooltip-meta">' + String(toInt(node.degree || 0)) + ' links</div>';
	}

	function renderGraphFrame() {
		var host = document.getElementById('share-viz-graph');
		var stage = document.getElementById('share-viz-stage');
		if (!host || !stage) { return; }
		var width = Math.max(300, host.clientWidth || 960);
		var height = Math.max(220, host.clientHeight || 380);

		// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-16 — stronger overdraw
		// reduction for public share: render fewer edges with subtle style.
		var renderLinks = kgVizState.links;
		var maxRenderEdges = Math.max(55, Math.min(95, Math.floor(kgVizState.nodes.length * 0.7)));
		if (renderLinks.length > maxRenderEdges) {
			var sampled = [];
			var step = renderLinks.length / maxRenderEdges;
			for (var si = 0; si < maxRenderEdges; si++) {
				var idx = Math.floor(si * step);
				if (idx >= renderLinks.length) {
					idx = renderLinks.length - 1;
				}
				sampled.push(renderLinks[idx]);
			}
			renderLinks = sampled;
		}

		var edgeParts = [];
		for (var i = 0; i < renderLinks.length; i++) {
			var l = renderLinks[i];
			var s = kgVizState.nodeMap[l.source];
			var t = kgVizState.nodeMap[l.target];
			if (!s || !t) { continue; }
			var mx = (s.x + t.x) / 2;
			var my = (s.y + t.y) / 2 - 16;
			// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-15 — force edges to
			// fixed subtle style: 1px + gray tint, avoid dense black overdraw.
			var edgeOpacity = 0.08;
				edgeParts.push(
					'<path class="viz-edge" d="M ' + s.x.toFixed(2) + ' ' + s.y.toFixed(2) + ' Q ' + mx.toFixed(2) + ' ' + my.toFixed(2) + ' ' + t.x.toFixed(2) + ' ' + t.y.toFixed(2) + '" stroke="#c2cbd8" stroke-opacity="' + edgeOpacity.toFixed(3) + '" stroke-width="0.10" vector-effect="non-scaling-stroke" />'
				);
		}

		var nodeParts = [];
		// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-13 — reduce node size
		// and border thickness for dense public-share KG views.
		var densityScale = 1;
		if (kgVizState.nodes.length >= 180) {
			densityScale = 0.68;
		} else if (kgVizState.nodes.length >= 140) {
			densityScale = 0.76;
		} else if (kgVizState.nodes.length >= 100) {
			densityScale = 0.86;
		}
		for (var n = 0; n < kgVizState.nodes.length; n++) {
			var node = kgVizState.nodes[n];
			var typeColor = KG_TYPE_COLORS[node.type] || '#94a3b8';
			var fill = typeColor;
			var stroke = node.status === 'error' ? '#ef4444' : (node.status === 'processing' ? '#f472b6' : '#ffffff');
			var r = ( 2.6 + Math.min(9.4, Math.sqrt(Math.max(1, node.degree + node.weight)) * 1.22) ) * densityScale;
			var opacity = node.status === 'pending' ? 0.78 : 0.92;
			var strokeWidth = 1.2;
			if (kgVizState.hoverId === node.id) {
				r += 1.6;
				opacity = 1;
				strokeWidth = 1.8;
			}

			nodeParts.push(
				'<g data-node-id="' + String(node.id) + '">' +
					'<circle data-node-id="' + String(node.id) + '" cx="' + node.x.toFixed(2) + '" cy="' + node.y.toFixed(2) + '" r="' + r.toFixed(2) + '" fill="' + fill + '" fill-opacity="' + opacity + '" stroke="' + stroke + '" stroke-width="' + strokeWidth.toFixed(1) + '" />' +
					((kgVizState.hoverId === node.id || (node.degree >= 7 && kgVizState.zoom > 1.1))
						? '<text x="' + node.x.toFixed(2) + '" y="' + (node.y + r + 11).toFixed(2) + '" text-anchor="middle" font-size="11" fill="#334155" style="paint-order:stroke;stroke:#ffffff;stroke-width:3;stroke-linejoin:round;">' + escHtml(String(node.label || '').slice(0, 22)) + '</text>'
						: '') +
				'</g>'
			);
		}

		stage.innerHTML = '' +
			'<svg class="viz-svg" viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="KG formed graph">' +
				'<g transform="translate(' + kgVizState.panX.toFixed(2) + ',' + kgVizState.panY.toFixed(2) + ') scale(' + kgVizState.zoom.toFixed(3) + ')">' +
					edgeParts.join('') + nodeParts.join('') +
				'</g>' +
			'</svg>';

		renderVizTooltip();
	}

	function zoomGraph(delta) {
		kgVizState.zoom = Math.max(0.28, Math.min(3, kgVizState.zoom + delta));
		renderGraphFrame();
	}

	function resetGraphView() {
		kgVizState.zoom = 1;
		kgVizState.panX = 0;
		kgVizState.panY = 0;
		renderGraphFrame();
	}

	function bindVizGraphEvents() {
		if (kgVizState.bound) { return; }
		kgVizState.bound = true;

		var host = document.getElementById('share-viz-graph');
		var stage = document.getElementById('share-viz-stage');
		if (!host || !stage) { return; }

		var zIn = document.getElementById('share-viz-zoom-in');
		var zOut = document.getElementById('share-viz-zoom-out');
		var zReset = document.getElementById('share-viz-reset');
		if (zIn) { zIn.addEventListener('click', function () { zoomGraph(0.22); }); }
		if (zOut) { zOut.addEventListener('click', function () { zoomGraph(-0.22); }); }
		if (zReset) { zReset.addEventListener('click', function () { resetGraphView(); }); }

		var rendererButtons = document.querySelectorAll('.viz-render-btn');
		for (var i = 0; i < rendererButtons.length; i++) {
			rendererButtons[i].addEventListener('click', function () {
				kgVizState.renderer = 'nexus';
				for (var j = 0; j < rendererButtons.length; j++) {
					rendererButtons[j].classList.toggle('active', rendererButtons[j].getAttribute('data-renderer') === 'nexus');
				}
				kgVizState.dataKey = '';
				renderKgVisualization(kgVizState.lastCounts, kgVizState.lastPhases, kgVizState.lastPreview);
			});
		}

		var orphanToggle = document.getElementById('share-viz-hide-orphans');
		if (orphanToggle) {
			orphanToggle.addEventListener('change', function () {
				kgVizState.hideOrphans = !!this.checked;
				kgVizState.dataKey = '';
				renderKgVisualization(kgVizState.lastCounts, kgVizState.lastPhases, kgVizState.lastPreview);
			});
		}

		host.addEventListener('wheel', function (evt) {
			evt.preventDefault();
			zoomGraph(-1 * (evt.deltaY || 0) * 0.001);
		}, { passive: false });

		host.addEventListener('mousedown', function (evt) {
			var target = evt.target;
			var nodeId = target && target.getAttribute ? toInt(target.getAttribute('data-node-id')) : 0;
			if (nodeId > 0) {
				kgVizState.dragNodeId = nodeId;
				kgVizState.alpha = Math.max(0.28, kgVizState.alpha);
				return;
			}
			kgVizState.panning = true;
			kgVizState.mouseStartX = evt.clientX;
			kgVizState.mouseStartY = evt.clientY;
			kgVizState.startPanX = kgVizState.panX;
			kgVizState.startPanY = kgVizState.panY;
		});

		host.addEventListener('mousemove', function (evt) {
			var target = evt.target;
			var nodeId = target && target.getAttribute ? toInt(target.getAttribute('data-node-id')) : 0;
			kgVizState.hoverId = nodeId > 0 ? nodeId : 0;

			if (kgVizState.dragNodeId > 0) {
				var rect = host.getBoundingClientRect();
				var x = (evt.clientX - rect.left - kgVizState.panX) / kgVizState.zoom;
				var y = (evt.clientY - rect.top - kgVizState.panY) / kgVizState.zoom;
				var node = kgVizState.nodeMap[kgVizState.dragNodeId];
				if (node) {
					node.x = x;
					node.y = y;
					node.vx = 0;
					node.vy = 0;
					renderGraphFrame();
				}
				return;
			}

			if (kgVizState.panning) {
				kgVizState.panX = kgVizState.startPanX + (evt.clientX - kgVizState.mouseStartX);
				kgVizState.panY = kgVizState.startPanY + (evt.clientY - kgVizState.mouseStartY);
				renderGraphFrame();
				return;
			}

			renderVizTooltip();
		});

		host.addEventListener('mouseleave', function () {
			kgVizState.hoverId = 0;
			kgVizState.panning = false;
			renderVizTooltip();
		});

		window.addEventListener('mouseup', function () {
			kgVizState.dragNodeId = 0;
			kgVizState.panning = false;
		});
	}

	function animateGraph() {
		if (kgVizState.rafId) {
			cancelAnimationFrame(kgVizState.rafId);
			kgVizState.rafId = 0;
		}
		var step = function () {
			simulateGraphStep();
			renderGraphFrame();
			kgVizState.alpha = kgVizState.alpha * 0.986;
			if (kgVizState.alpha > 0.03 || kgVizState.dragNodeId > 0 || kgVizState.panning) {
				kgVizState.rafId = requestAnimationFrame(step);
			} else {
				kgVizState.rafId = 0;
			}
		};
		kgVizState.rafId = requestAnimationFrame(step);
	}

	function renderKgVisualization(counts, phases, preview) {
		bindVizGraphEvents();
		kgVizState.renderer = 'nexus';
		kgVizState.lastCounts = counts || {};
		kgVizState.lastPhases = Array.isArray(phases) ? phases : [];
		kgVizState.lastPreview = preview && typeof preview === 'object' ? preview : {};

		var graph = normalizeGraphPreview(kgVizState.lastCounts, kgVizState.lastPreview);
		var key = computeDataKey(graph);
		var changed = key !== kgVizState.dataKey;
		if (changed) {
			kgVizState.dataKey = key;
			initializeNodePositions(graph, true);
			kgVizState.nodes = graph.nodes;
			kgVizState.links = graph.links;
			kgVizState.nodeMap = {};
			for (var i = 0; i < kgVizState.nodes.length; i++) {
				kgVizState.nodeMap[kgVizState.nodes[i].id] = kgVizState.nodes[i];
			}
			kgVizState.alpha = 1;
		}

		setText('share-viz-entities', String(toInt((counts || {}).entities_approved || 0)));
		setText('share-viz-relations', String(toInt((counts || {}).relations_approved || 0)));
		setText('share-viz-nodes', String(kgVizState.nodes.length));
		setText('share-viz-phase', resolveVizPhase(phases));

		animateGraph();
	}

	function normalizeStatus(v) {
		var s = String(v || 'pending').toLowerCase();
		if (s !== 'done' && s !== 'processing' && s !== 'pending' && s !== 'error') {
			s = 'pending';
		}
		return s;
	}

	function renderChunks(chunks) {
		var listEl = document.getElementById('share-chunks-list');
		var emptyEl = document.getElementById('share-chunks-empty');
		if (!listEl || !emptyEl) { return; }
		if (!Array.isArray(chunks) || chunks.length === 0) {
			listEl.style.display = 'none';
			emptyEl.style.display = '';
			listEl.innerHTML = '';
			return;
		}

		var html = '';
		for (var i = 0; i < chunks.length; i++) {
			var c = chunks[i] || {};
			var passageId = parseInt(c.passage_id || 0, 10);
			var chunkIndex = c.chunk_index;
			var status = normalizeStatus(c.status);
			var title = 'Passage #' + String(passageId);
			if (chunkIndex !== null && chunkIndex !== undefined && chunkIndex !== '') {
				title += ' • chunk ' + String(parseInt(chunkIndex, 10));
			}
			var updated = String(c.updated_at || c.time || '');
			var triplets = parseInt(c.triplets || 0, 10);
			var snippet = String(c.snippet || '');

			html += '<div class="chunk-card">' +
				'<div class="chunk-head">' +
					'<div class="chunk-title">' + escHtml(title) + '</div>' +
					'<span class="status-badge ' + escHtml(status) + '">' + escHtml(status) + '</span>' +
				'</div>' +
				'<div class="chunk-meta">triplets: ' + String(triplets) + '</div>' +
				'<div class="chunk-meta">updated: ' + escHtml(updated) + '</div>' +
				( snippet ? ('<div class="chunk-snippet">' + escHtml(snippet) + '</div>') : '' ) +
			'</div>';
		}
		listEl.innerHTML = html;
		listEl.style.display = '';
		emptyEl.style.display = 'none';
	}

	function renderConsole(lines, events) {
		if (Array.isArray(lines) && lines.length > 0) {
			return lines.map(function (v) { return String(v || ''); });
		}
		if (!Array.isArray(events) || events.length === 0) {
			return [];
		}
		var out = [];
		for (var i = 0; i < events.length; i++) {
			var e = events[i] || {};
			var ts = String(e.ts || '');
			var level = String(e.level || 'info').toUpperCase();
			var eventName = String(e.event || 'event');
			var message = String(e.message || '');
			var prefix = ts ? ('[' + ts + ' UTC] ') : '';
			out.push((prefix + level + ' ' + eventName + ' ' + message).trim());
		}
		return out;
	}

	function tickHeartbeat() {
		if (document.hidden) { return; }
		var u = new URL(hbUrl.toString());
		u.searchParams.set('_ts', String(Date.now()));
		fetch(u.toString(), {
			method: 'GET',
			headers: { 'Accept': 'application/json' },
			cache: 'no-store'
		}).then(function (res) {
			if (!res.ok) { return null; }
			return res.json();
		}).then(function (json) {
			if (!json || !json.data) { return; }
			var d = json.data || {};
			var snap = d.source_snapshot || {};
			var graphPreview = d.graph_preview || snap.graph_preview || {};
			var counts = snap.counts || d.counts || {};
			var lane = d.lane_summary || {};
			var mode = lane.source_mode || 'unknown';
			var status = snap.status || d.status || 'unknown';
			var progressRaw = (typeof snap.progress === 'number') ? snap.progress : ((typeof d.progress === 'number') ? d.progress : 0);
			var progressPct = Math.max(0, Math.min(100, Math.round(progressRaw * 100)));
			var phase = (d.job && d.job.phase) ? d.job.phase : 'unknown';

			if (typeof snap.title === 'string') {
				setText('share-source-title', snap.title);
			}
			setText('share-chip-mode', 'mode: ' + mode);
			setText('share-chip-status', 'status: ' + status);
			setText('share-chip-progress', 'progress: ' + progressPct + '%');
			setText('share-chip-phase', 'phase: ' + phase);
			setText('share-chip-updated', 'updated: ' + new Date().toISOString().slice(11, 19) + ' UTC');

			setText('share-log-date', 'Ngày log: ' + String(d.date || ''));
			var timelineCount = Array.isArray(d.events) ? d.events.length : 0;
			setText('share-timeline-count', 'Dòng timeline: ' + String(timelineCount));

			setText('share-kpi-cron', String(parseInt(((lane.by_lane || {}).cron || {}).dispatch_rounds || 0, 10)));
			setText('share-kpi-ajax', String(parseInt(((lane.by_lane || {}).ajax || {}).dispatch_rounds || 0, 10)));
			setText('share-kpi-chunk-done', String(parseInt(counts.done || 0, 10)) + '/' + String(parseInt(counts.total || 0, 10)));
			setText('share-kpi-chunk-error', String(parseInt(counts.error || 0, 10)));
			setText('share-source-mode', mode);

			setText('share-counter-done', 'done: ' + String(parseInt(counts.done || 0, 10)));
			setText('share-counter-processing', 'processing: ' + String(parseInt(counts.processing || 0, 10)));
			setText('share-counter-pending', 'pending: ' + String(parseInt(counts.pending || 0, 10)));
			setText('share-counter-error', 'error: ' + String(parseInt(counts.error || 0, 10)));
			setText('share-counter-triplets', 'triplets pending: ' + String(parseInt(counts.triplets_pending || 0, 10)));

			var totalChunks = parseInt(counts.total || 0, 10);
			var doneChunks = parseInt(counts.done || 0, 10);
			var fillPct = totalChunks > 0 ? Math.max(0, Math.min(100, Math.round((doneChunks / totalChunks) * 100))) : 0;
			var fillEl = document.getElementById('share-process-fill');
			if (fillEl) {
				fillEl.style.width = String(fillPct) + '%';
			}

			var phaseRows = snap.phases || d.phases || [];
			renderPhases(phaseRows);
			renderKgVisualization(counts, phaseRows, graphPreview);
			renderChunks(d.chunks || snap.chunks || []);

			var consoleLines = renderConsole(d.lines, d.events);
			setText('share-console-count', 'Dòng console: ' + String(consoleLines.length));
			setText('share-console-text', consoleLines.join('\n'));
		}).catch(function () {
			// Silent by design — keep current UI snapshot when network blips.
		});
	}

	// [2026-07-26 Johnny Chu] PHASE-0.48 HOTFIX-11 — initial KG visualize
	// render so overview graph is visible even before first heartbeat resolves.
	renderKgVisualization(<?php echo wp_json_encode( $source_counts ); ?>, <?php echo wp_json_encode( $source_phases ); ?>, <?php echo wp_json_encode( $graph_preview ); ?>);

	tickHeartbeat();
	setInterval(tickHeartbeat, refreshMs);
})();
</script>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	public function check_can_write( WP_REST_Request $req ) {
		$ok = $this->check_logged_in();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$nb_ok = $this->check_notebook_access( (int) $req->get_param( 'notebook_id' ) );
		if ( is_wp_error( $nb_ok ) ) {
			return $nb_ok;
		}
		// Per-user rate limit on enqueue.
		$key  = 'tc_learn_rate_' . get_current_user_id();
		$ctr  = (int) get_transient( $key );
		if ( $ctr >= self::RATE_LIMIT_MAX ) {
			return new WP_Error( 'rate_limited', 'Too many learning jobs queued — slow down', [ 'status' => 429 ] );
		}
		set_transient( $key, $ctr + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	// ── Handlers ────────────────────────────────────────────────────────

	public function enqueue( WP_REST_Request $req ) {
		$nb           = (int) $req->get_param( 'notebook_id' );
		$source_id    = (int) $req->get_param( 'source_id' );
		$source_title = (string) $req->get_param( 'source_title' );

		$res = BizCity_TwinChat_Learning_Job_Queue::instance()->enqueue( [
			'notebook_id'  => $nb,
			'source_id'    => $source_id,
			'source_title' => $source_title,
			'user_id'      => get_current_user_id(),
		] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'job_id' => (int) $res ] ] );
	}

	/**
	 * Force-rebuild graph for a notebook — user-initiated unstick.
	 *
	 * Both modes ALWAYS run an "unstick" sequence first, regardless of mode,
	 * because the most common reason users press Rebuild is that a job is
	 * silently stuck (loopback dead, lease abandoned, triplets sitting in
	 * the staging queue, etc.). The mode-specific reset runs AFTER unstick.
	 *
	 *   ── Always (force unstick) ──
	 *   A. Cancel stale jobs (status IN queued/running AND updated_at older
	 *      than $stale_secs). This breaks the enqueue dedup that would
	 *      otherwise return the stuck job's ID and do nothing.
	 *   B. Reclaim 'processing' passages older than 30s back to 'pending'.
	 *   C. Flush pending triplet_queue → graph via approve_all_pending().
	 *      Idempotent — safe to call any time, surfaces entities immediately.
	 *   D. Clear sticky 'loopback dead' option so the new job retries the
	 *      fast loopback path instead of the 1-passage-per-tick sync fallback.
	 *
	 *   ── Mode-specific ──
	 *   - soft (default): also reset 'error'/'skipped' passages.
	 *   - hard: cancel ALL active jobs, reset ALL passages, drop 'pending'
	 *           triplet_queue rows. Burns LLM quota — confirm in UI.
	 *
	 *   ── Always (post) ──
	 *   E. Force-enqueue (bypass dedup) so a guaranteed fresh job exists.
	 *   F. Drive one tick synchronously so progress starts within the request.
	 */
	public function rebuild( WP_REST_Request $req ) {
		global $wpdb;

		$nb   = (int) $req->get_param( 'notebook_id' );
		$mode = (string) $req->get_param( 'mode' );
		if ( $mode !== 'hard' ) { $mode = 'soft'; }

		if ( $nb <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id required', [ 'status' => 400 ] );
		}
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return new WP_Error( 'kg_unavailable', 'KG-Hub not loaded', [ 'status' => 500 ] );
		}

		$kg            = BizCity_KG_Database::instance();
		$tbl_passages  = $kg->tbl_passages();
		$tbl_triplets  = $kg->tbl_triplet_queue();
		$queue         = BizCity_TwinChat_Learning_Job_Queue::instance();
		$tbl_jobs      = BizCity_TwinChat_Learning_Database::instance()->table_jobs();

		// Guard: ensure the `updated_at` column exists before the stale-detection
		// query runs. On long-lived sites the table was created at schema 1.3.0
		// (no updated_at) — fall back to created_at when the column is missing
		// so rebuild never throws "Unknown column 'updated_at'".
		$has_updated_at = self::jobs_has_updated_at( $tbl_jobs );
		$ts_col         = $has_updated_at ? 'updated_at' : 'created_at';

		$reset_passages   = 0;
		$reset_triplets   = 0;
		$cancelled_jobs   = 0;
		$reclaimed        = 0;
		$flushed_triplets = 0;

		// ── A. Cancel stale jobs (force unstick — runs in BOTH modes) ──
		// A job that hasn't moved updated_at in 90s is presumed wedged
		// (lease holder died, loopback dropped, etc.). Cancelling it lets
		// the dedup-bypass enqueue below create a fresh job that actually
		// ticks. In hard mode we cancel ALL active jobs (see below).
		$stale_secs = (int) apply_filters( 'bizcity_twinchat_learning_rebuild_stale_secs', 90 );
		if ( $mode === 'hard' ) {
			$stale_ids = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$tbl_jobs}
				  WHERE notebook_id = %d AND status IN ('queued','running')",
				$nb
			) );
		} else {
			$stale_ids = (array) $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$tbl_jobs}
				  WHERE notebook_id = %d
				    AND status IN ('queued','running')
				    AND ( {$ts_col} IS NULL OR {$ts_col} < DATE_SUB(NOW(), INTERVAL %d SECOND) )",
				$nb, $stale_secs
			) );
		}
		foreach ( $stale_ids as $jid ) {
			$jid = (int) $jid;
			if ( $jid > 0 ) {
				$queue->cancel( $jid );
				$cancelled_jobs++;
			}
		}

		// ── B. Reclaim stuck 'processing' passages (always) ────────────
		$reclaimed = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl_passages}
			    SET extraction_status = 'pending', updated_at = NOW()
			  WHERE notebook_id = %d
			    AND extraction_status = 'processing'
			    AND updated_at < DATE_SUB(NOW(), INTERVAL 30 SECOND)",
			$nb
		) );

		// ── C. Flush pending triplet_queue → graph (always, idempotent) ─
		// Even when no extraction has happened in this rebuild, draining the
		// queue is the cheapest way to surface entities that previous ticks
		// extracted but never approved (e.g. job died mid-approve phase).
		if ( class_exists( 'BizCity_KG_Graph_Service' ) ) {
			$flushed_triplets = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl_triplets} WHERE notebook_id = %d AND status = 'pending'",
				$nb
			) );
			if ( $flushed_triplets > 0 ) {
				BizCity_KG_Graph_Service::instance()->approve_all_pending( $nb, get_current_user_id() );
			}
		}

		// ── D. Clear sticky 'loopback dead' option (always) ────────────
		// tick_extract sets this when it detects the parallel-worker
		// loopback is broken; it stays set for 1 hour. Force-rebuild is
		// the user telling us "try the fast path again".
		delete_option( 'bizcity_tc_loopback_dead_ts' );

		// ── Mode-specific passage reset ────────────────────────────────
		if ( $mode === 'hard' ) {
			// Reset all passages → 'pending'.
			$reset_passages = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$tbl_passages}
				    SET extraction_status = 'pending', updated_at = NOW()
				  WHERE notebook_id = %d",
				$nb
			) );
			// Drop unprocessed triplets (the C flush already approved any
			// useful ones; remaining 'pending' rows here are leftovers we
			// want re-extracted from scratch).
			$reset_triplets = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$tbl_triplets}
				  WHERE notebook_id = %d AND status = 'pending'",
				$nb
			) );
		} else {
			// Soft: only re-process error/skipped (B already handled stuck processing).
			$reset_passages = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$tbl_passages}
				    SET extraction_status = 'pending', updated_at = NOW()
				  WHERE notebook_id = %d
				    AND extraction_status IN ('error','skipped')",
				$nb
			) );
			// Reclaimed rows count toward "reset" total for UI clarity.
			$reset_passages += $reclaimed;
		}

		// ── E. Enqueue. Bypass dedup ONLY if we just cancelled stale jobs
		// (or nothing is active). When a healthy job is already running and
		// we just flushed/reclaimed for it, dedup correctly returns its id —
		// no need for a redundant racing job.
		$has_active = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl_jobs}
			  WHERE notebook_id = %d AND status IN ('queued','running')",
			$nb
		) );
		$bypass_dedup = ( $cancelled_jobs > 0 || $has_active === 0 || $mode === 'hard' );
		$disable_dedup = function () { return false; };
		if ( $bypass_dedup ) {
			add_filter( 'bizcity_twinchat_learning_enqueue_dedupe', $disable_dedup, 999 );
		}
		$res = $queue->enqueue( [
			'notebook_id'  => $nb,
			'origin'       => 'rebuild_' . $mode,
			'source_title' => sprintf( '[rebuild:%s]', $mode ),
			'user_id'      => get_current_user_id(),
		] );
		if ( $bypass_dedup ) {
			remove_filter( 'bizcity_twinchat_learning_enqueue_dedupe', $disable_dedup, 999 );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$new_job_id = (int) $res;

		// ── F. Drive one tick synchronously so user sees movement now ──
		// Wrapped in try/catch to never let a tick error abort the rebuild
		// response — the cron sweeper will retry within ~30s.
		$first_tick = null;
		try {
			$first_tick = BizCity_TwinChat_Learning_Pipeline::tick(
				$new_job_id,
				'ajax-' . (int) get_current_user_id()
			);
		} catch ( \Throwable $e ) {
			bizcity_tc_learning_debug_log( sprintf(
				'rebuild job=%d → first tick threw: %s', $new_job_id, $e->getMessage()
			) );
		}

		// ── Announce + return ─────────────────────────────────────────
		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'log', [
			'level' => 'step',
			'msg'   => sprintf(
				'[rebuild:%s] reset=%d reclaimed=%d flushed=%d cancelled=%d dropped=%d → job #%d',
				$mode, $reset_passages, $reclaimed, $flushed_triplets, $cancelled_jobs, $reset_triplets, $new_job_id
			),
		], $new_job_id );

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'job_id'             => $new_job_id,
				'mode'               => $mode,
				'passages_reset'     => $reset_passages,
				'passages_reclaimed' => $reclaimed,
				'triplets_flushed'   => $flushed_triplets,
				'triplets_dropped'   => $reset_triplets,
				'jobs_cancelled'     => $cancelled_jobs,
				'first_tick_phase'   => is_array( $first_tick ) && isset( $first_tick['phase'] )
					? (string) $first_tick['phase']
					: null,
			],
		] );
	}

	public function list_jobs( WP_REST_Request $req ) {
		$nb       = (int) $req->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $nb );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$limit    = (int) $req->get_param( 'limit' );
		$status   = (string) $req->get_param( 'status' );
		$args     = [ 'limit' => $limit ];
		if ( $status !== '' ) {
			$args['statuses'] = array_map( 'trim', explode( ',', $status ) );
		}
		$jobs = BizCity_TwinChat_Learning_Job_Queue::instance()->list_jobs( $nb, $args );
		return rest_ensure_response( [ 'ok' => true, 'data' => $jobs ] );
	}

	public function get_job( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$auth = $this->check_job_access( $id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$job = BizCity_TwinChat_Learning_Job_Queue::instance()->get_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'not_found', 'Job not found', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $job ] );
	}

	public function cancel_job( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$auth = $this->check_job_access( $id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$res = BizCity_TwinChat_Learning_Job_Queue::instance()->cancel( $id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	/**
	 * [2026-06-04 Johnny Chu] HOTFIX — clear quota cooldown and resume a paused job.
	 *
	 * Admin-only (manage_options). Clears:
	 *   1. The per-user cooldown transient set by BizCity_TwinChat_Learning_Quota_Cooldown.
	 *   2. The job's restartable_at column so the next tick() proceeds past the cooldown gate.
	 *
	 * Use when a job is stuck in paused_quota state and the admin has already
	 * raised the daily quota (bizcity_kg_daily_quota_per_user option) or verified
	 * the entitlement bypass is now functional.
	 *
	 * @param WP_REST_Request $req  id (int) — the job ID.
	 */
	public function resume_job( WP_REST_Request $req ) {
		$id    = (int) $req['id'];
		$auth = $this->check_job_access( $id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$queue = BizCity_TwinChat_Learning_Job_Queue::instance();

		$job = $queue->get_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'job_not_found', 'Job not found', [ 'status' => 404 ] );
		}

		$user_id = (int) $job['user_id'];

		// Clear per-user quota cooldown transient.
		$cleared_transient = false;
		if ( $user_id > 0 && class_exists( 'BizCity_TwinChat_Learning_Quota_Cooldown' ) ) {
			BizCity_TwinChat_Learning_Quota_Cooldown::clear( $user_id );
			$cleared_transient = true;
		}

		// Clear restartable_at so tick() cooldown gate no longer blocks the job.
		$queue->update( $id, [ 'restartable_at' => null, 'error' => null ] );

		// Drive one tick immediately so progress starts right away.
		$tick_res = null;
		try {
			$tick_res = BizCity_TwinChat_Learning_Pipeline::tick( $id, 'ajax-' . get_current_user_id() );
		} catch ( \Throwable $e ) {
			// Non-fatal — cron will retry.
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'job_id'            => $id,
				'user_id'           => $user_id,
				'cleared_transient' => $cleared_transient,
				'restartable_at'    => null,
				'tick'              => $tick_res,
			],
		] );
	}

	public function tick_job( WP_REST_Request $req ) {
		$id    = (int) $req['id'];
		$auth = $this->check_job_access( $id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$owner = 'ajax-' . (int) get_current_user_id();
		$res   = BizCity_TwinChat_Learning_Pipeline::tick( $id, $owner );

		// Derive a stable `reason` code so the FE can show a meaningful
		// message instead of just "busy x N". Order matters — most specific
		// reason wins.
		$reason = '';
		if ( ! empty( $res['busy'] ) ) {
			if ( ! empty( $res['paused'] ) ) {
				$reason = 'paused_quota';
			} elseif ( ! empty( $res['reason_code'] ) && (string) $res['reason_code'] === 'learning_worker_capacity' ) {
				$reason = 'worker_capacity';
			} elseif ( ! empty( $res['phase'] ) && $res['phase'] === 'approving' ) {
				$reason = 'approving';
			} elseif ( isset( $res['job']['lease_owner'] ) && (string) $res['job']['lease_owner'] !== '' && (string) $res['job']['lease_owner'] !== $owner ) {
				$reason = 'lease_held_by_other';
			} else {
				$reason = 'tick_busy';
			}
		}

		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'done'        => (bool) $res['done'],
				'busy'        => (bool) $res['busy'],
				'error'       => (bool) $res['error'],
				'phase'       => (string) $res['phase'],
				'reason'      => $reason,
				'paused'      => ! empty( $res['paused'] ),
				'retry_after' => isset( $res['retry_after'] ) ? (int) $res['retry_after'] : 0,
				'lease_owner' => isset( $res['job']['lease_owner'] ) ? (string) $res['job']['lease_owner'] : '',
				'reason_code' => isset( $res['reason_code'] ) ? (string) $res['reason_code'] : '',
				'reason_msg'  => isset( $res['reason_msg'] )  ? (string) $res['reason_msg']  : '',
				'diag'        => isset( $res['diag'] ) && is_array( $res['diag'] ) ? $res['diag'] : null,
				'job'         => $res['job'],
			],
		] );
	}

	public function poll_events( WP_REST_Request $req ) {
		$nb     = (int) $req->get_param( 'notebook_id' );
		$auth = $this->check_notebook_access( $nb );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$since  = (int) $req->get_param( 'since' );
		$limit  = (int) $req->get_param( 'limit' );
		$rows   = BizCity_TwinChat_Learning_Events::instance()->read_since( $nb, $since, $limit );
		$max_id = ! empty( $rows ) ? (int) $rows[ count( $rows ) - 1 ]['id'] : $since;
		return rest_ensure_response( [ 'ok' => true, 'data' => [
			'events'  => $rows,
			'last_id' => $max_id,
		] ] );
	}

	// ── Wave A — Hub aggregate handlers ────────────────────────────────

	public function get_summary( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		$data  = BizCity_TwinChat_Learning_Aggregator::instance()->summary(
			get_current_user_id(), $scope === 'site'
		);
		return rest_ensure_response( [ 'ok' => true, 'data' => $data ] );
	}

	public function get_analytics( WP_REST_Request $req ) {
		$scope = $this->resolve_scope( $req );
		$range = (string) $req->get_param( 'range' );
		$data  = BizCity_TwinChat_Learning_Aggregator::instance()->analytics(
			get_current_user_id(), $range, $scope === 'site'
		);
		return rest_ensure_response( [ 'ok' => true, 'data' => $data ] );
	}

	public function set_presence( WP_REST_Request $req ) {
		$active = (bool) $req->get_param( 'active' );
		BizCity_TwinChat_Learning_Aggregator::instance()->mark_presence( get_current_user_id(), $active );
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'active' => $active ] ] );
	}

	/** site-scope only honoured for users with manage_options. */
	private function resolve_scope( WP_REST_Request $req ) {
		$scope = (string) $req->get_param( 'scope' );
		if ( $scope === 'site' && current_user_can( 'manage_options' ) ) {
			return 'site';
		}
		return 'user';
	}

	// ── Wave B — Cleanup engine handlers ───────────────────────────────

	public function check_can_manage_cleanup() {
		$ok = $this->check_logged_in();
		if ( is_wp_error( $ok ) ) return $ok;
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'bizcity_view_kg_learning' ) ) {
			return new WP_Error( 'rest_forbidden', 'Insufficient capability', [ 'status' => 403 ] );
		}
		return true;
	}

	public function cleanup_status( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			return new WP_Error( 'unavailable', 'Cleanup service not loaded', [ 'status' => 503 ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => BizCity_KG_Cleanup_Service::instance()->get_status() ] );
	}

	public function cleanup_log( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			return new WP_Error( 'unavailable', 'Cleanup service not loaded', [ 'status' => 503 ] );
		}
		$rows = BizCity_KG_Cleanup_Service::instance()->get_log( [
			'limit'  => (int) $req->get_param( 'limit' ),
			'offset' => (int) $req->get_param( 'offset' ),
			'stage'  => (string) $req->get_param( 'stage' ),
			'run_id' => (string) $req->get_param( 'run_id' ),
		] );
		return rest_ensure_response( [ 'ok' => true, 'data' => $rows ] );
	}

	public function cleanup_run( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			return new WP_Error( 'unavailable', 'Cleanup service not loaded', [ 'status' => 503 ] );
		}
		$result = BizCity_KG_Cleanup_Service::instance()->run( [
			'trigger_kind' => 'manual',
			'triggered_by' => (int) get_current_user_id(),
		] );
		// Bust this user's summary cache so the "last_sweep" widget refreshes.
		if ( class_exists( 'BizCity_TwinChat_Learning_Aggregator' ) ) {
			BizCity_TwinChat_Learning_Aggregator::instance()->bust( get_current_user_id() );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $result ] );
	}

	public function cleanup_restore( WP_REST_Request $req ) {
		if ( ! class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			return new WP_Error( 'unavailable', 'Cleanup service not loaded', [ 'status' => 503 ] );
		}
		$target_table = (string) $req->get_param( 'target_table' );
		// Hardening (audit MEDIUM): whitelist the only tables the cleanup engine
		// is allowed to touch. Filter lets future cortex modules opt in.
		$allowed = (array) apply_filters(
			'bizcity_kg_cleanup_restorable_tables',
			[ 'kg_relations', 'kg_entities' ]
		);
		if ( ! in_array( $target_table, $allowed, true ) ) {
			return new WP_Error(
				'invalid_target_table',
				sprintf( 'target_table must be one of: %s', implode( ',', $allowed ) ),
				[ 'status' => 400 ]
			);
		}
		$res = BizCity_KG_Cleanup_Service::instance()->restore(
			$target_table,
			(int) $req->get_param( 'target_id' )
		);
		if ( is_wp_error( $res ) ) return $res;
		return rest_ensure_response( [ 'ok' => true, 'data' => [ 'restored' => true ] ] );
	}

	// ── Parallel worker auth + handler ──────────────────────────────────

	/**
	 * Verify the internal HMAC token for the passage-worker endpoint.
	 *
	 * The token is generated by BizCity_TwinChat_Learning_Pipeline::dispatch_parallel_workers()
	 * using wp_hash( "{job_id}:{passage_id}:passage_worker" ) and passed as the
	 * X-TC-Internal-Token request header. No WP session/cookie needed.
	 */
	public function check_passage_worker_token( WP_REST_Request $req ) {
		$token      = (string) $req->get_header( 'X-TC-Internal-Token' );
		$job_id     = (int) $req->get_param( 'job_id' );
		$passage_id = (int) $req->get_param( 'passage_id' );
		bizcity_tc_learning_debug_log( sprintf( 'passage_worker TOKEN check job=%d passage=%d token_len=%d', $job_id, $passage_id, strlen( $token ) ) );
		if ( $token === '' || $job_id <= 0 || $passage_id <= 0 ) {
			return new WP_Error( 'forbidden', 'Missing token or params', [ 'status' => 403 ] );
		}
		$expected = wp_hash( $job_id . ':' . $passage_id . ':passage_worker' );
		if ( ! hash_equals( $expected, $token ) ) {
			bizcity_tc_learning_debug_log( sprintf( 'passage_worker TOKEN MISMATCH job=%d passage=%d', $job_id, $passage_id ) );
			return new WP_Error( 'forbidden', 'Invalid internal token', [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * Passage worker — extract one passage and atomically update the job counters.
	 *
	 * Called via non-blocking loopback HTTP fired by dispatch_parallel_workers().
	 * Runs in its own PHP-FPM worker process = true concurrency.
	 */
	public function passage_worker( WP_REST_Request $req ) {
		// Keep process alive in case LLM call is slow.
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		if ( ! class_exists( 'BizCity_KG_Triplet_Extractor' ) ) {
			return new WP_Error( 'unavailable', 'KG extractor not loaded', [ 'status' => 503 ] );
		}

		$job_id     = (int) $req->get_param( 'job_id' );
		$passage_id = (int) $req->get_param( 'passage_id' );
		$nb         = (int) $req->get_param( 'nb' );
		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE — lane forwarded by
		// dispatch_parallel_workers() via header; default 'cron' for older/other callers.
		$lane = sanitize_key( (string) $req->get_header( 'x-tc-lane' ) );
		if ( $lane !== 'ajax' ) { $lane = 'cron'; }
		bizcity_tc_learning_debug_log( sprintf( 'passage_worker REST HIT job=%d passage=%d nb=%d lane=%s', $job_id, $passage_id, $nb, $lane ) );

		// CRITICAL: loopback request has NO logged-in session, so get_current_user_id()=0.
		// Cost Guard, ownership checks, and usage logging all key off the current user.
		// Without this, can_extract() returns 'quota_exceeded' against user 0, the extractor
		// marks the passage as 'skipped' (terminal state — never retried), counters never
		// increment, and the job appears to "process" passages without producing triplets.
		// We resolve the real owner from the job row and impersonate them for this request.
		$job_owner = 0;
		if ( class_exists( 'BizCity_TwinChat_Learning_Job_Queue' ) ) {
			$job_row = BizCity_TwinChat_Learning_Job_Queue::instance()->get_job( $job_id );
			if ( $job_row ) {
				$job_owner = (int) $job_row['user_id'];
			}
		}
		if ( $job_owner > 0 ) {
			wp_set_current_user( $job_owner );
		}

		$events = class_exists( 'BizCity_TwinChat_Learning_Events' )
			? BizCity_TwinChat_Learning_Events::instance()
			: null;

		// Breadcrumb so we can SEE workers actually arriving (was missing before).
		if ( $events && $nb > 0 ) {
			$events->push( $nb, 'log', [
				'level' => 'info',
				'msg'   => sprintf( '[worker→] start passage #%d (user=%d)', $passage_id, $job_owner ),
			], $job_id );
		}

		$result = BizCity_KG_Triplet_Extractor::instance()->extract_passage( $passage_id );

		global $wpdb;
		$tbl_jobs = class_exists( 'BizCity_TwinChat_Learning_Database' )
			? BizCity_TwinChat_Learning_Database::instance()->table_jobs()
			: '';

		if ( is_wp_error( $result ) ) {
			// [2026-07-23 Johnny Chu] PHASE-0.44 — log final DB status to diagnose repeated worker retries on same passage.
			$final_status = '';
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$tbl_passages = BizCity_KG_Database::instance()->tbl_passages();
				$final_status = (string) $wpdb->get_var( $wpdb->prepare(
					"SELECT extraction_status FROM {$tbl_passages} WHERE id = %d LIMIT 1",
					$passage_id
				) );
			}
			bizcity_tc_learning_debug_log( sprintf(
				'passage_worker job=%d passage=%d lane=%s ERROR [%s] final_status=%s msg=%s',
				$job_id,
				$passage_id,
				$lane,
				$result->get_error_code(),
				$final_status !== '' ? $final_status : '?',
				$result->get_error_message()
			) );

			// Passage was flipped back to pending (transient) or error/skipped by extract_passage()
			// — nothing to increment. Just push an event so the hub stream shows it.
			if ( $events && $nb > 0 ) {
				$events->push( $nb, 'log', [
					'level' => 'warn',
					'msg'   => sprintf( '[worker] passage #%d error [%s]: %s',
						$passage_id, $result->get_error_code(), $result->get_error_message() ),
				], $job_id );
			}
			return rest_ensure_response( [ 'ok' => false, 'code' => $result->get_error_code(), 'error' => $result->get_error_message() ] );
		}

		$triplets = (int) $result;
		// [2026-07-23 Johnny Chu] PHASE-0.44 — explicit success breadcrumb for worker-level observability.
		bizcity_tc_learning_debug_log( sprintf( 'passage_worker job=%d passage=%d lane=%s OK triplets=%d', $job_id, $passage_id, $lane, $triplets ) );

		// Atomic counters — safe across concurrent PHP-FPM workers.
		if ( $tbl_jobs !== '' ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$tbl_jobs} SET passages_processed = passages_processed + 1,
				 triplets_extracted = triplets_extracted + %d WHERE id = %d",
				$triplets, $job_id
			) );
		}

		// Successful worker arrival = loopback is alive. Clear any stale
		// sticky-dead verdict left over from previous outages so the next
		// tick will use parallel dispatch (3× faster) instead of SYNC mode.
		delete_option( 'bizcity_tc_loopback_dead_ts' );

		// Push per-passage progress event for the Learning Hub stream.
		if ( $events && $nb > 0 ) {
			$job_row2 = class_exists( 'BizCity_TwinChat_Learning_Job_Queue' )
				? BizCity_TwinChat_Learning_Job_Queue::instance()->get_job( $job_id )
				: null;
			$events->push( $nb, 'progress', [
				'in_flight'      => true,
				'worker_passage' => $passage_id,
				'triplets'       => $triplets,
				'passages_total' => $job_row2 ? (int) $job_row2['passages_processed'] : 0,
				'triplets_total' => $job_row2 ? (int) $job_row2['triplets_extracted'] : 0,
			], $job_id );
			// Build a short sample of the actual relations extracted so the UI log
			// is informative rather than just a count.
			$sample_str = '';
			if ( $triplets > 0 && class_exists( 'BizCity_KG_Database' ) ) {
				$kg       = BizCity_KG_Database::instance();
				$samples  = $wpdb->get_results( $wpdb->prepare(
					"SELECT subject, predicate, object, confidence
					   FROM {$kg->tbl_triplet_queue()}
					  WHERE passage_id = %d AND notebook_id = %d
					  ORDER BY id DESC LIMIT 3",
					$passage_id, $nb
				), ARRAY_A ) ?: [];
				if ( $samples ) {
					$parts = array_map( static function ( $r ) {
						return sprintf( '«%s» →[%s]→ «%s»', $r['subject'], $r['predicate'], $r['object'] );
					}, $samples );
					$sample_str = ' · ' . implode( '  ', $parts );
					if ( count( $samples ) < $triplets ) {
						$sample_str .= sprintf( ' …(+%d khác)', $triplets - count( $samples ) );
					}
				}
			}
			$events->push( $nb, 'log', [
				'level' => 'info',
				'msg'   => sprintf( '[worker✓] passage #%d → %d quan hệ%s', $passage_id, $triplets, $sample_str ),
			], $job_id );
		}

		return rest_ensure_response( [ 'ok' => true, 'passage_id' => $passage_id, 'triplets' => $triplets ] );
	}
}
