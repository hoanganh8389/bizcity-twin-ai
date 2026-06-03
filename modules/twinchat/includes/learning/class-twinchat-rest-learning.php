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
		global $wpdb;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( self::$jobs_has_updated_at_cache[ $blog_id ] ) ) {
			return self::$jobs_has_updated_at_cache[ $blog_id ];
		}
		$prev_supp = $wpdb->suppress_errors( true );
		$col = $wpdb->get_var( $wpdb->prepare(
			"SHOW COLUMNS FROM `{$tbl_jobs}` LIKE %s",
			'updated_at'
		) );
		$wpdb->suppress_errors( $prev_supp );
		$has = ! empty( $col );
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
			'permission_callback' => [ $this, 'check_logged_in' ],
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
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'get_job' ],
		] );

		register_rest_route( $ns, '/learning/jobs/(?P<id>\d+)/cancel', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'cancel_job' ],
		] );

		// Foreground driver tick — the open /twinchat/ tab calls this in a loop
		// to drive a job to completion faster than cron polling. Cron is still
		// scheduled as a fallback when the tab is closed.
		register_rest_route( $ns, '/learning/jobs/(?P<id>\d+)/tick', [
			'methods'             => WP_REST_Server::CREATABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'tick_job' ],
		] );

		register_rest_route( $ns, '/learning/events', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'poll_events' ],
			'args'                => [
				'notebook_id' => [ 'type' => 'integer', 'required' => true ],
				'since'       => [ 'type' => 'integer', 'default' => 0 ],
				'limit'       => [ 'type' => 'integer', 'default' => 200 ],
			],
		] );

		register_rest_route( $ns, '/learning/stream', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
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

		// Debug log reader — tail of /uploads/tc-learning-debug/pipeline.log.
		// Logged-in user only. Used to diagnose loopback / sync fallback issues
		// when php.ini error_log is silently dropping messages.
		register_rest_route( $ns, '/learning/debug-log', [
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => [ $this, 'check_logged_in' ],
			'callback'            => [ $this, 'read_debug_log' ],
			'args'                => [
				'lines' => [ 'type' => 'integer', 'default' => 200 ],
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
	 * Read the tail of the dedicated learning debug log file.
	 * Path: WP_CONTENT_DIR/uploads/tc-learning-debug/pipeline.log
	 */
	public function read_debug_log( WP_REST_Request $req ) {
		$lines = max( 1, min( 2000, (int) $req->get_param( 'lines' ) ?: 200 ) );
		$path  = WP_CONTENT_DIR . '/uploads/tc-learning-debug/pipeline.log';
		if ( ! file_exists( $path ) ) {
			return rest_ensure_response( [
				'ok'    => true,
				'data'  => [ 'path' => $path, 'exists' => false, 'lines' => [] ],
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
		return rest_ensure_response( [
			'ok'   => true,
			'data' => [
				'path'   => $path,
				'exists' => true,
				'size'   => (int) filesize( $path ),
				'count'  => count( $tail ),
				'lines'  => $tail,
			],
		] );
	}

	public function check_can_write( WP_REST_Request $req ) {
		$ok = $this->check_logged_in();
		if ( is_wp_error( $ok ) ) {
			return $ok;
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
		$job = BizCity_TwinChat_Learning_Job_Queue::instance()->get_job( $id );
		if ( ! $job ) {
			return new WP_Error( 'not_found', 'Job not found', [ 'status' => 404 ] );
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $job ] );
	}

	public function cancel_job( WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$res = BizCity_TwinChat_Learning_Job_Queue::instance()->cancel( $id );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( [ 'ok' => true, 'data' => $res ] );
	}

	public function tick_job( WP_REST_Request $req ) {
		$id    = (int) $req['id'];
		$owner = 'ajax-' . (int) get_current_user_id();
		$res   = BizCity_TwinChat_Learning_Pipeline::tick( $id, $owner );

		// Derive a stable `reason` code so the FE can show a meaningful
		// message instead of just "busy x N". Order matters — most specific
		// reason wins.
		$reason = '';
		if ( ! empty( $res['busy'] ) ) {
			if ( ! empty( $res['paused'] ) ) {
				$reason = 'paused_quota';
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
		bizcity_tc_learning_debug_log( sprintf( 'passage_worker REST HIT job=%d passage=%d nb=%d', $job_id, $passage_id, $nb ) );

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
