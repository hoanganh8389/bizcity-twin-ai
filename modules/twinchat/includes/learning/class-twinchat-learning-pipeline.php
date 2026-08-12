<?php
/**
 * Bizcity TwinChat — Learning Pipeline
 *
 * Phase 4.9 (1.1.0) — hybrid execution:
 *
 *   • Cron / Action Scheduler hook `bizcity_twinchat_learning_run` loops
 *     `tick()` for ~25s before yielding so wp-cron stays responsive.
 *   • REST `POST /learning/jobs/{id}/tick` calls `tick()` once per HTTP
 *     request from the open `/twinchat/` tab. Browser drives the loop in
 *     the foreground for near-instant feedback.
 *
 * Both lanes use a row-level lease (Job_Queue::acquire_lease) so they never
 * double-process the same job. If the foreground tab closes mid-run, the
 * cron lane resumes from where it left off because the KG primitives are
 * idempotent on passage state.
 *
 *   phase: queued → extracting → approving → done
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( ! function_exists( 'bizcity_tc_learning_debug_log' ) ) {
	/**
	 * Resolve the daily TC-Learning log file path in site-specific uploads.
	 *
	 * Multisite-safe: wp_upload_dir() returns each site's own basedir
	 * (e.g. /uploads/sites/{blog_id}).
	 *
	 * [2026-06-08 Johnny Chu] HOTFIX — changed dir to bizcity_learning_logs/
	 * and filename to YYYY-MM-DD.log for easier reading/access per-site.
	 * Path: /uploads/sites/{blog_id}/bizcity_learning_logs/YYYY-MM-DD.log
	 */
	function bizcity_tc_learning_debug_log_path( $date_ymd = '', $ensure_dir = true ) {
		if ( ! is_string( $date_ymd ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_ymd ) ) {
			$date_ymd = gmdate( 'Y-m-d' );
		}

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

		$log_dir = trailingslashit( wp_normalize_path( $base_dir ) ) . 'bizcity_learning_logs';
		if ( $ensure_dir && ! is_dir( $log_dir ) ) {
			@wp_mkdir_p( $log_dir );
			// Block direct browser access.
			@file_put_contents( trailingslashit( $log_dir ) . '.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( trailingslashit( $log_dir ) . 'index.php', "<?php // Silence is golden.\n" );
		}

		return trailingslashit( $log_dir ) . $date_ymd . '.log';
	}

	/**
	 * Dedicated debug logger for the learning pipeline. Writes to a guaranteed
	 * daily file under site uploads (multisite-safe):
	 *   /uploads/sites/{id}/bizcity-logs/tc-learning/tc-learning-YYYY-MM-DD.log
	 *
	 * Disable by defining BIZCITY_TC_LEARNING_DEBUG = false (default true while debugging).
	 */
	function bizcity_tc_learning_debug_log( $msg ) {
		if ( defined( 'BIZCITY_TC_LEARNING_DEBUG' ) && BIZCITY_TC_LEARNING_DEBUG === false ) {
			return;
		}
		$line = sprintf( "[%s UTC] [TC-Learning] %s\n", gmdate( 'd-M-Y H:i:s' ), (string) $msg );
		@file_put_contents( bizcity_tc_learning_debug_log_path( '', true ), $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Append a structured, tenant-scoped audit event beside the readable log.
	 *
	 * @param string $event
	 * @param array  $context
	 * @return void
	 */
	function bizcity_tc_learning_audit_log( $event, array $context = array() ) {
		static $request_id = '';
		if ( $request_id === '' ) {
			$header_request_id = isset( $_SERVER['HTTP_X_REQUEST_ID'] ) ? sanitize_key( (string) $_SERVER['HTTP_X_REQUEST_ID'] ) : '';
			$request_id = $header_request_id !== ''
				? substr( $header_request_id, 0, 80 )
				: ( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'req-', true ) );
		}
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, true, false ) : array();
		$base_dir = is_array( $uploads ) && ! empty( $uploads['basedir'] )
			? (string) $uploads['basedir']
			: ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/uploads' : '' );
		if ( $base_dir === '' ) {
			error_log( '[TC-Learning] event=audit_write_failed reason=missing_upload_dir event_name=' . sanitize_key( (string) $event ) );
			return;
		}

		$dir = trailingslashit( wp_normalize_path( $base_dir ) ) . 'bizcity_learning_logs/audit';
		if ( ! is_dir( $dir ) ) {
			@wp_mkdir_p( $dir );
			@file_put_contents( trailingslashit( $dir ) . '.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( trailingslashit( $dir ) . 'index.php', "<?php // Silence is golden.\n" );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			error_log( '[TC-Learning] event=audit_write_failed reason=directory_not_writable event_name=' . sanitize_key( (string) $event ) );
			return;
		}

		$job_id = isset( $context['job_id'] ) ? (int) $context['job_id'] : 0;
		$passage_id = isset( $context['passage_id'] ) ? (int) $context['passage_id'] : 0;
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		$record = array_merge(
			array(
				'ts'             => gmdate( 'c' ),
				'event_id'       => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'tc-', true ),
				'request_id'     => $request_id,
				'trace_id'       => $job_id > 0 ? sprintf( 'tc-%d-j%d%s', $blog_id, $job_id, $passage_id > 0 ? '-p' . $passage_id : '' ) : $request_id,
				'blog_id'        => $blog_id,
				'event'          => sanitize_key( (string) $event ),
				'schema_version' => '1.0',
			),
			$context
		);
		$line = wp_json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( is_string( $line ) ) {
			$written = @file_put_contents( trailingslashit( $dir ) . gmdate( 'Y-m-d' ) . '.jsonl', $line . PHP_EOL, FILE_APPEND | LOCK_EX );
			if ( false === $written ) {
				error_log( '[TC-Learning] event=audit_write_failed reason=file_append_failed event_name=' . sanitize_key( (string) $event ) . ' request_id=' . $request_id );
			}
		}
	}
}

class BizCity_TwinChat_Learning_Pipeline {

	const MAX_LOOPS          = 30;
	// Phase 0.18 — bumped 25 → 50 after the 429 throttle fix in the LLM
	// extractor. Filterable so ops can dial down if rate-limit errors return.
	const EXTRACT_BATCH      = 50;
	const LEASE_TTL_S        = 30;
	const CRON_TIME_BUDGET_S = 25;

	/**
	 * Default number of parallel loopback workers per dispatch round.
	 * Filterable via `bizcity_twinchat_learning_parallel_workers`.
	 * Cap: [1, 10] — PHP-FPM pool is finite.
	 *
	 * History:
	 *   - 2026-05-04 lowered 5→3 (FPM pool exhaustion 500/522 from Cloudflare).
	 *   - 2026-05-26 raised 3→5 (Pro-Tier Wave).
	 *   - 2026-08-03 lowered 5→2 after shared FPM saturation and 524 traces.
	 * Ops can lower this to 1 via the filter; the runtime clamp below prevents
	 * a site-level override from exceeding the production safety cap.
	 */
	const PARALLEL_WORKERS = 2; // [2026-08-03 Johnny Chu] HOTFIX — cap Learning fan-out for shared PHP-FPM.
	const MAX_PARALLEL_WORKERS = 2; // [2026-08-03 Johnny Chu] HOTFIX — enforce the P1 production ceiling after filters.
	const MAX_ACTIVE_WORKERS = 2; // [2026-08-03 Johnny Chu] P1 — cap processing passages per blog before dispatch.

	/**
	 * Delay (seconds) before re-running an in-flight job. Lower = faster
	 * throughput, higher = less FPM pressure. Filterable.
	 */
	const BUSY_RESCHEDULE_S  = 5;

	private static $bound = false;

	public static function bind() {
		if ( self::$bound ) {
			return;
		}
		self::$bound = true;
		add_action(
			BizCity_TwinChat_Learning_Job_Queue::HOOK_RUN,
			[ __CLASS__, 'run' ],
			10, 1
		);
	}

	/**
	 * Cron / Action Scheduler entry point. Drains as much of the job as we
	 * can within CRON_TIME_BUDGET_S, then yields.
	 *
	 * @param int $job_id
	 */
	public static function run( $job_id ) {
		$job_id = (int) $job_id;
		@set_time_limit( 0 );
		@ignore_user_abort( true );

		$deadline = microtime( true ) + self::CRON_TIME_BUDGET_S;
		// [2026-07-23 Johnny Chu] PHASE-0.44 — unique owner token per run()
		// to avoid same-label contention across concurrent cron processes.
		$owner    = 'cron-' . substr( md5( (string) microtime( true ) . ':' . (string) wp_rand() ), 0, 8 );

		for ( $loops = 0; $loops < self::MAX_LOOPS; $loops++ ) {
			$res = self::tick( $job_id, $owner );
			if ( $res['done']  ) { return; }
			if ( $res['busy']  ) {
				// Paused (quota cooldown) — wait until retry_after, capped 1h.
				if ( ! empty( $res['paused'] ) && ! empty( $res['retry_after'] ) ) {
					$delay = max( 60, min( HOUR_IN_SECONDS, (int) $res['retry_after'] - time() ) );
					self::reschedule( $job_id, $delay );
					return;
				}
				// Fast cadence — workers fired non-blocking; come back ASAP so the
				// next batch can dispatch instead of waiting half a minute.
				$busy_delay = (int) apply_filters( 'bizcity_twinchat_learning_busy_delay_s', self::BUSY_RESCHEDULE_S );
				$busy_delay = max( 2, min( 60, $busy_delay ) );
				self::reschedule( $job_id, $busy_delay );
				return;
			}
			if ( $res['error'] ) { return; }
			if ( microtime( true ) >= $deadline ) {
				self::reschedule( $job_id, 5 );
				return;
			}
		}
		self::reschedule( $job_id, 5 );
	}

	protected static function reschedule( $job_id, $delay_s ) {
		$args = [ (int) $job_id ];
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + (int) $delay_s, BizCity_TwinChat_Learning_Job_Queue::HOOK_RUN, $args, 'bizcity_twinchat_learning' );
			return;
		}
		if ( ! wp_next_scheduled( BizCity_TwinChat_Learning_Job_Queue::HOOK_RUN, $args ) ) {
			wp_schedule_single_event( time() + (int) $delay_s, BizCity_TwinChat_Learning_Job_Queue::HOOK_RUN, $args );
		}
	}

	/**
	 * Run ONE batch (extract OR approve) of a job. Safe to call concurrently
	 * from cron and ajax — only one lane wins the lease.
	 *
	 * @param int    $job_id
	 * @param string $owner   'cron' or 'ajax-<user_id>'
	 * @return array { done, busy, error, phase, job }
	 */
	public static function tick( $job_id, $owner = 'cron' ) {
		$job_id = (int) $job_id;
		$queue  = BizCity_TwinChat_Learning_Job_Queue::instance();
		$events = BizCity_TwinChat_Learning_Events::instance();

		$job = $queue->get_job( $job_id );
		if ( ! $job ) {
			return [ 'done' => true, 'busy' => false, 'error' => false, 'phase' => 'missing', 'job' => null ];
		}
		if ( in_array( $job['status'], [ 'done', 'failed', 'cancelled' ], true ) ) {
			return [ 'done' => true, 'busy' => false, 'error' => false, 'phase' => $job['phase'], 'job' => $job ];
		}

		// ── Cooldown gate ───────────────────────────────────────────────
		// PHASE-0.7 Wave Pro-Tier (2026-05-26): if `restartable_at` is in the
		// future, this job was paused (most likely by quota_exceeded). Yield
		// cheaply so cron/ajax tick don't spam logs or burn LLM calls.
		if ( ! empty( $job['restartable_at'] ) ) {
			$resume_ts = strtotime( (string) $job['restartable_at'] . ' UTC' );
			if ( $resume_ts && $resume_ts > time() ) {
				$user_id = (int) $job['user_id'];
				// Cheap proactive re-check: if the user upgraded plan / admin
				// granted extra quota, lift the block immediately so we don't
				// wait until midnight.
				if ( $user_id > 0
				     && class_exists( 'BizCity_TwinChat_Learning_Quota_Cooldown' )
				     && BizCity_TwinChat_Learning_Quota_Cooldown::is_quota_available_again( $user_id ) ) {
					BizCity_TwinChat_Learning_Quota_Cooldown::clear( $user_id );
					$queue->update( $job_id, [ 'restartable_at' => null, 'error' => null ] );
					$job = $queue->get_job( $job_id );
				} else {
					return [
						'done'        => false,
						'busy'        => true,
						'error'       => false,
						'paused'      => true,
						'phase'       => $job['phase'],
						'job'         => $job,
						'retry_after' => $resume_ts,
					];
				}
			}
		}

		// ── Notebook-level singleton guard ──────────────────────────────
		// ARCHITECTURAL FIX 2026-05-04: tick_extract reads ALL pending
		// passages of the notebook (not filtered by source_id), so multiple
		// active jobs on the same notebook race on the same passage pool —
		// burning quota and corrupting the loopback_dead heuristic. Enforce
		// "1 worker per notebook" by deferring to the canonical (smallest-id)
		// active job. Newly enqueued duplicates get auto-cancelled and merge
		// into the canonical job, which already covers their passages.
		if ( apply_filters( 'bizcity_twinchat_learning_singleton_guard', true ) ) {
			global $wpdb;
			$tbl_jobs   = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
			$canonical  = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl_jobs}
				 WHERE notebook_id = %d
				   AND status IN ('queued','running')
				   AND ( phase IS NULL OR phase IN ('queued','extracting','approving') )
				 ORDER BY id ASC LIMIT 1",
				(int) $job['notebook_id']
			) );
			if ( $canonical > 0 && $canonical !== $job_id ) {
				bizcity_tc_learning_debug_log( sprintf(
					'tick job=%d nb=%d → MERGED into canonical job #%d (singleton guard)',
					$job_id, (int) $job['notebook_id'], $canonical
				) );
				$queue->update( $job_id, [
					'status'      => 'cancelled',
					'phase'       => 'done',
					'error'       => sprintf( 'merged-into-#%d', $canonical ),
					'finished_at' => current_time( 'mysql', true ),
				] );
				$events->push( (int) $job['notebook_id'], 'job', [
					'job_id'       => $job_id,
					'status'       => 'cancelled',
					'merged_into'  => $canonical,
					'reason'       => 'singleton-guard',
				], $job_id );
				return [ 'done' => true, 'busy' => false, 'error' => false, 'phase' => 'done', 'job' => $queue->get_job( $job_id ) ];
			}
		}

		if ( ! $queue->acquire_lease( $job_id, $owner, self::LEASE_TTL_S ) ) {
			$held_job = $queue->get_job( $job_id );
			$lease_until = isset( $held_job['lease_until'] ) ? (string) $held_job['lease_until'] : '';
			$retry_after = $lease_until !== '' ? strtotime( $lease_until . ' UTC' ) : time() + self::LEASE_TTL_S;
			// [2026-08-10 Johnny Chu] PHASE-0.49-LEARNING-OBSERVABILITY - expose lease reason and audit context.
			bizcity_tc_learning_audit_log( 'tick_busy', array(
				'busy_reason' => 'lease',
				'blog_id'     => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'notebook_id' => (int) $job['notebook_id'],
				'job_id'      => $job_id,
				'lane'        => strpos( (string) $owner, 'ajax' ) === 0 ? 'ajax' : 'cron',
				'owner'       => (string) $owner,
				'lease_owner' => isset( $held_job['lease_owner'] ) ? (string) $held_job['lease_owner'] : '',
				'lease_until' => $lease_until,
				'retry_after' => $retry_after,
			) );
			return [
				'done'        => false,
				'busy'        => true,
				'error'       => false,
				'phase'       => $job['phase'],
				'busy_reason'  => 'lease',
				'retry_after' => $retry_after,
				'job'         => $held_job ?: $job,
			];
		}

		// Re-read after lease.
		$job = $queue->get_job( $job_id );
		$nb  = (int) $job['notebook_id'];

		// First touch — mark running + emit `job` event.
		if ( $job['status'] !== 'running' ) {
			$queue->update( $job_id, [
				'status'     => 'running',
				'phase'      => $job['phase'] === 'queued' ? 'extracting' : $job['phase'],
				'started_at' => $job['started_at'] ?: current_time( 'mysql', true ),
				'progress'   => max( 5, (int) $job['progress'] ),
			] );
			$events->push( $nb, 'job', [
				'job_id'       => $job_id,
				'status'       => 'running',
				'source_id'    => (int) $job['source_id'],
				'source_title' => (string) $job['source_title'],
				'owner'        => $owner,
			], $job_id );
			$lane = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
			$events->push( $nb, 'log', [
				'level' => 'step',
				'msg'   => $job['source_title']
					? sprintf( '[%s] Twin bắt đầu học «%s»…', $lane, $job['source_title'] )
					: sprintf( '[%s] Twin bắt đầu học nguồn vừa tải…', $lane ),
			], $job_id );
			$job = $queue->get_job( $job_id );
		}

		try {
			if ( ! class_exists( 'BizCity_KG_Triplet_Extractor' ) || ! class_exists( 'BizCity_KG_Graph_Service' ) ) {
				throw new Exception( 'KG Hub services not available' );
			}

			$phase = $job['phase'] ?: 'extracting';

			if ( $phase === 'extracting' || $phase === 'queued' ) {
				$result = self::tick_extract( $job, $owner );
				$queue->release_lease( $job_id, $owner );
				return $result;
			}

			if ( $phase === 'approving' ) {
				$result = self::tick_approve( $job, $owner );
				$queue->release_lease( $job_id, $owner );
				return $result;
			}

			$queue->update( $job_id, [ 'phase' => 'done', 'status' => 'done', 'progress' => 100, 'finished_at' => current_time( 'mysql', true ) ] );
			$queue->release_lease( $job_id, $owner );
			return [ 'done' => true, 'busy' => false, 'error' => false, 'phase' => 'done', 'job' => $queue->get_job( $job_id ) ];
		} catch ( Exception $e ) {
			$queue->update( $job_id, [
				'status'      => 'failed',
				'phase'       => 'done',
				'error'       => substr( $e->getMessage(), 0, 1000 ),
				'finished_at' => current_time( 'mysql', true ),
			] );
			$events->push( $nb, 'log', [ 'level' => 'error', 'msg' => 'Twin gặp lỗi: ' . $e->getMessage() ], $job_id );
			$events->push( $nb, 'done', [
				'job_id' => $job_id,
				'failed' => true,
				'error'  => $e->getMessage(),
			], $job_id );
			$queue->release_lease( $job_id, $owner );
			return [ 'done' => true, 'busy' => false, 'error' => true, 'phase' => 'done', 'job' => $queue->get_job( $job_id ) ];
		}
	}

	// ── Phase implementations ──────────────────────────────────────────

	/** Extract one batch of passages → triplets using parallel loopback workers. */
	protected static function tick_extract( array $job, $owner ) {
		global $wpdb;
		$queue    = BizCity_TwinChat_Learning_Job_Queue::instance();
		$events   = BizCity_TwinChat_Learning_Events::instance();
		$job_id   = (int) $job['id'];
		$nb       = (int) $job['notebook_id'];
		$db       = BizCity_KG_Database::instance();

		// ── Pro/Free tier quota gate ────────────────────────────────────
		// PHASE-0.7 Wave Pro-Tier (2026-05-26): probe cost-guard ONCE per tick.
		// Before this gate, a quota-exhausted user would re-trigger SYNC fallback
		// every 3s, emitting `sync_worker ... ERROR [quota_exceeded]` to logs
		// indefinitely (observed user=12 50/50 looping at 03:20 UTC).
		//
		// IMPORTANT (2026-05-26 follow-up): we DO NOT call $cost_guard->can_extract()
		// here, because the LLM Router exempts admins (manage_options) AND the
		// explicit exempt-users list — for *learning cron* that means an admin
		// notebook would grow _kg_passages / _kg_relations without bound. The
		// learning pipeline is a background batch job, not an interactive call,
		// so it MUST honor the per-user daily quota regardless of role. We probe
		// the raw counters and ignore the exemption filter.
		$user_id = (int) $job['user_id'];
		if ( $user_id <= 0 ) {
			// Refuse to run unowned jobs — they can't be quota-attributed.
			// Mark failed so cron / sweep stop re-firing.
			$queue->update( $job_id, [
				'status'      => 'failed',
				'error'       => 'job has no user_id; cannot attribute quota',
				'finished_at' => current_time( 'mysql', 1 ),
			] );
			$queue->release_lease( $job_id, $owner );
			if ( $events ) {
				$events->push( $nb, 'log', [
					'level' => 'error',
					'msg'   => '[quota] Job thiếu user_id — đã dừng để tránh ghi không kiểm soát.',
				], $job_id );
			}
			bizcity_tc_learning_debug_log( sprintf( 'tick job=%d FAILED — user_id=0', $job_id ) );
			return [ 'done' => true, 'busy' => false, 'error' => true, 'phase' => 'failed', 'job' => $queue->get_job( $job_id ) ];
		}
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			// Single source of truth: BizCity_KG_Cost_Guard::can_extract() now
			// returns a WP_Error whose message + data carry the full diagnostic
			// chain (filter hook status, entitlement tier/balance/bypass, etc.)
			// so the FE can show actionable hints instead of just "quota X/Y".
			$cg     = BizCity_KG_Cost_Guard::instance();
			$verdict = $cg->can_extract( $user_id, 1 );
			$err     = is_wp_error( $verdict ) ? $verdict : null;

			if ( $err ) {
				$paused = self::pause_for_quota( $job_id, $nb, $user_id, $err, $events );
				$queue->release_lease( $job_id, $owner );
				$diag = (array) $err->get_error_data();
				return [
					'done'        => false,
					'busy'        => true,
					'error'       => false,
					'paused'      => true,
					'busy_reason'  => 'quota_pause',
					'phase'       => 'extracting',
					'job'         => $queue->get_job( $job_id ),
					'retry_after' => $paused['retry_after'] ?? ( time() + 900 ),
					'reason_code' => $err->get_error_code(),
					'reason_msg'  => $err->get_error_message(),
					'diag'        => $diag,
				];
			}
		}

		// Detect dead loopback up-front so we can override the in-flight gate.
		// (Otherwise stuck 'processing' passages — a SYMPTOM of dead loopback —
		// keep us in the in-flight branch for 5 minutes per round, masking the
		// real problem and never reaching the sync fallback below.)
		$dispatched_rounds = (int) $job['batches_done'];
		$counter_progress  = (int) $job['passages_processed'];

		// Heuristic: loopback is dead if we've fired far more dispatch rounds
		// than passages actually completed. Threshold = +3 rounds.
		//
		// FRAGILE PROBLEM: gap heuristic flips back to "alive" if a duplicate
		// job's sync increments `passages_processed` (early-exit on 'done'
		// passages still counts as success). Then we retry the broken loopback
		// → 30s yield → repeat → super slow.
		//
		// FIX: persist "dead" verdict in wp_options once detected. Stays dead
		// for the whole site until admin clears via filter or option delete.
		// Lifetime: 1 hour (auto-clears so a fixed loopback eventually retries).
		$sticky_dead_ts = (int) get_option( 'bizcity_tc_loopback_dead_ts', 0 );
		$sticky_window  = (int) apply_filters( 'bizcity_twinchat_learning_loopback_dead_ttl_s', HOUR_IN_SECONDS );
		$sticky_dead    = ( $sticky_dead_ts > 0 && ( time() - $sticky_dead_ts ) < $sticky_window );

		$gap_dead      = ( $dispatched_rounds - $counter_progress >= 3 );
		$loopback_dead = ( $sticky_dead || $gap_dead );

		// Persist newly-detected death so siblings/future ticks bypass loopback.
		if ( $gap_dead && ! $sticky_dead ) {
			update_option( 'bizcity_tc_loopback_dead_ts', time(), false );
		}

		// Noisy every-tick status log — commented out 2026-05-09 (normal behaviour, not an error).
		// bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d nb=%d owner=%s batches_done=%d passages_processed=%d gap=%d loopback_dead=%s',
		// 	$job_id, $nb, (string) $owner, $dispatched_rounds, $counter_progress,
		// 	$dispatched_rounds - $counter_progress, $loopback_dead ? 'YES' : 'no'
		// ) );

		// ── Step 1: check in-flight workers from previous dispatch ─────
		// Passages stuck as 'processing' for >30s are considered orphaned
		// (PHP-FPM killed the worker, or loopback HTTP silently dropped).
		// 30s is enough to cover one normal LLM call (~3-8s) plus margin;
		// shorter than the previous 5-min timeout so a dead loopback doesn't
		// block the job for 5 minutes per round.
		$orphan_timeout_s = (int) apply_filters( 'bizcity_twinchat_learning_orphan_timeout_s', 30 );
		$inflight = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$db->tbl_passages()}
			 WHERE notebook_id = %d
			   AND extraction_status = 'processing'
			   AND updated_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)",
			$nb, $orphan_timeout_s
		) );

		if ( $inflight > 0 && ! $loopback_dead ) {
			// Workers still running — yield back to caller, retry on next tick.
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → yield (in_flight=%d, orphan_in=%ds, not dead yet)', $job_id, $inflight, $orphan_timeout_s ) );
			$queue->extend_lease( $job_id, $owner, self::LEASE_TTL_S );
			$events->push( $nb, 'progress', [
				'in_flight' => $inflight,
				'waiting'   => true,
				'owner'     => $owner,
			], $job_id );
			return [ 'done' => false, 'busy' => false, 'error' => false, 'phase' => 'extracting', 'job' => $job ];
		}

		// Loopback is proven dead and we have stuck 'processing' rows — reclaim
		// them with a short grace age to avoid stealing rows that JUST started
		// in another tick/process.
		// NOTE: race-safe — atomic UPDATE returns affected rows, only one tick
		// wins the reclaim. Subsequent ticks see processing_count=0.
		if ( $inflight > 0 && $loopback_dead ) {
			// [2026-07-23 Johnny Chu] PHASE-0.44 — grace window avoids duplicate
			// sync_worker START on the same passage (observed on job=13 passage=6660).
			$reclaim_min_age_s = (int) apply_filters( 'bizcity_twinchat_learning_reclaim_min_age_s', 5 );
			$reclaim_min_age_s = max( 1, min( $orphan_timeout_s, $reclaim_min_age_s ) );
			// [2026-07-24 Johnny Chu] PHASE-0.46-PASSAGE-CLAIM — stale workers release their claim marker before retry.
			$reclaimed = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$db->tbl_passages()}
				    SET extraction_status = 'pending', extraction_error = '', updated_at = NOW()
				  WHERE notebook_id = %d
				    AND extraction_status = 'processing'
				    AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
				$nb, $reclaim_min_age_s
			) );
			if ( $reclaimed > 0 ) {
				bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → reclaim %d stuck \'processing\' rows', $job_id, $reclaimed ) );
				$events->push( $nb, 'log', [
					'level' => 'warn',
					'msg'   => sprintf( '[reclaim] Reset %d passage \'processing\' \u2192 \'pending\' (loopback dead).', $reclaimed ),
				], $job_id );
			} else {
				bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → wait (loopback dead, in_flight=%d, reclaim_age=%ds)', $job_id, $inflight, $reclaim_min_age_s ) );
				$queue->extend_lease( $job_id, $owner, self::LEASE_TTL_S );
				return [ 'done' => false, 'busy' => true, 'error' => false, 'phase' => 'extracting', 'job' => $job ];
			}
		}

		// ── Step 2: fetch next batch of pending passages ────────────────
		$parallel = (int) apply_filters( 'bizcity_twinchat_learning_parallel_workers', self::PARALLEL_WORKERS, $nb );
		// [2026-08-03 Johnny Chu] HOTFIX — prevent a filter/site override from reopening the FPM fan-out storm.
		$parallel = max( 1, min( self::MAX_PARALLEL_WORKERS, $parallel ) );
		if ( $loopback_dead ) {
			// Sync mode — process exactly 1 passage per tick.
			$parallel = 1;
		}

		// [2026-08-03 Johnny Chu] P1 — bound concurrent passage workers across
		// notebooks in this blog, not only within the current notebook. This is
		// the local safety boundary available before a network-wide semaphore is
		// introduced; callers receive a delayed retry instead of another dispatch.
		// [2026-08-04 Johnny Chu] HOTFIX — reclaim stale processing rows across
		// all notebooks before counting blog-wide worker capacity.
		$global_orphan_timeout_s = (int) apply_filters(
			'bizcity_twinchat_learning_global_orphan_timeout_s',
			$orphan_timeout_s
		);
		$global_orphan_timeout_s = max( 30, min( 300, $global_orphan_timeout_s ) );
		$global_reclaimed = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$db->tbl_passages()}
			    SET extraction_status = 'pending', extraction_error = '', updated_at = NOW()
			  WHERE extraction_status = 'processing'
			    AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)",
			$global_orphan_timeout_s
		) );
		if ( $global_reclaimed > 0 ) {
			bizcity_tc_learning_debug_log( sprintf(
				'tick_extract job=%d → reclaim %d stale processing row(s) across blog before capacity check',
				$job_id,
				$global_reclaimed
			) );
		}
		$worker_snapshot = $wpdb->get_row(
			"SELECT COUNT(*) AS active_workers, MIN(updated_at) AS oldest_processing_at
			 FROM {$db->tbl_passages()} WHERE extraction_status = 'processing'",
			ARRAY_A
		);
		$active_workers = (int) ( $worker_snapshot['active_workers'] ?? 0 );
		$oldest_processing_at = (string) ( $worker_snapshot['oldest_processing_at'] ?? '' );
		$worker_capacity = self::MAX_ACTIVE_WORKERS - $active_workers;
		if ( $worker_capacity <= 0 ) {
			$retry_after = time() + 15;
			// [2026-08-10 Johnny Chu] PHASE-0.49-LEARNING-OBSERVABILITY - audit blog-wide worker capacity.
			bizcity_tc_learning_audit_log( 'tick_busy', array(
				'busy_reason'          => 'worker_capacity',
				'blog_id'              => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'notebook_id'          => $nb,
				'job_id'               => $job_id,
				'lane'                 => strpos( (string) $owner, 'ajax' ) === 0 ? 'ajax' : 'cron',
				'owner'                => (string) $owner,
				'active_workers'       => $active_workers,
				'worker_cap'           => self::MAX_ACTIVE_WORKERS,
				'oldest_processing_at' => $oldest_processing_at,
				'retry_after'          => $retry_after,
			) );
			bizcity_tc_learning_debug_log( sprintf(
				'tick_extract job=%d → worker capacity full active=%d cap=%d retry_after=%d',
				$job_id, $active_workers, self::MAX_ACTIVE_WORKERS, $retry_after
			) );
			$queue->extend_lease( $job_id, $owner, self::LEASE_TTL_S );
			return [
				'done'        => false,
				'busy'        => true,
				'error'       => false,
				'phase'       => 'extracting',
				'busy_reason'  => 'worker_capacity',
				'active_workers' => $active_workers,
				'worker_cap'  => self::MAX_ACTIVE_WORKERS,
				'oldest_processing_at' => $oldest_processing_at,
				'reason_code' => 'learning_worker_capacity',
				'retry_after' => $retry_after,
				'job'         => $job,
			];
		}
		$parallel = min( $parallel, $worker_capacity );

		// [2026-07-23 Johnny Chu] PHASE-0.44 — throttle retry for transient pending/skipped rows to stop hot-loop redispatch.
		$pending_retry_s = (int) apply_filters( 'bizcity_twinchat_learning_pending_retry_s', 60 );
		$error_retry_s   = (int) apply_filters( 'bizcity_twinchat_learning_error_retry_s', 300 );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()}
			 WHERE notebook_id = %d
			   AND (
			         (
			             extraction_status = 'pending'
			             AND (
			                  extraction_error IS NULL
			                  OR extraction_error = ''
			                  OR updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
			             )
			         )
			         OR (
			              extraction_status = 'processing'
			              AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
			            )
			         OR (
			              extraction_status = 'skipped'
			              AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
			            )
			         OR (
			              extraction_status = 'error'
			              AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
			            )
			       )
			 ORDER BY created_at ASC LIMIT %d",
			$nb, $pending_retry_s, $orphan_timeout_s, $error_retry_s, $error_retry_s, $parallel
		) );

		// ── Step 3: no pending passages → transition to approving ───────
		if ( empty( $ids ) ) {
			$batch_no = $queue->next_batch_no( $job_id );
			$batch_id = $queue->start_batch( $job_id, $nb, $batch_no, 'extract', $owner );
			$queue->finish_batch( $batch_id, [ 'passages_count' => 0, 'triplets_count' => 0, 'errors_count' => 0 ] );

			$totals_p = (int) $job['passages_processed'];
			$totals_t = (int) $job['triplets_extracted'];
			$lane     = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
			$events->push( $nb, 'log', [
				'level' => 'info',
				'msg'   => sprintf( '[%s] Twin đã đọc hết nguồn — %d đoạn / %d quan hệ → chuyển duyệt.', $lane, $totals_p, $totals_t ),
			], $job_id );
			// Pre-approve flush: drain any remaining triplets (below incremental
			// threshold) before handing off to tick_approve, so entities that
			// accumulated in small batches are not silently lost.
			if ( class_exists( 'BizCity_KG_Database' ) && class_exists( 'BizCity_KG_Graph_Service' ) ) {
				$tbl_tq  = BizCity_KG_Database::instance()->tbl_triplet_queue();
				$tq_left = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$tbl_tq} WHERE notebook_id = %d AND status = 'pending'",
					$nb
				) );
				if ( $tq_left > 0 ) {
					bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → pre-approve flush: %d pending triplets before approving phase', $job_id, $tq_left ) );
					BizCity_KG_Graph_Service::instance()->approve_all_pending( $nb, (int) $job['user_id'] );
				}
			}

			$queue->update( $job_id, [ 'phase' => 'approving', 'progress' => 80, 'batches_done' => (int) $job['batches_done'] + 1 ] );
			return [ 'done' => false, 'busy' => false, 'error' => false, 'phase' => 'approving', 'job' => $queue->get_job( $job_id ) ];
		}

		// ── Step 4: mark passages 'processing' atomically ───────────────
		// Prevents other cron/ajax lanes from double-dispatching the same
		// passages. Use a CONDITIONAL UPDATE that only flips rows still in a
		// claimable state — if a sibling tick already claimed them between
		// our SELECT and UPDATE, $rows_affected drops below count($ids) and
		// we drop the lost rows from the dispatch list.
		$ids_csv = implode( ',', array_map( 'intval', $ids ) );
		// [2026-07-24 Johnny Chu] PHASE-0.46-PASSAGE-CLAIM — clear the prior error marker before a new worker claims the passage.
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$db->tbl_passages()}
			    SET extraction_status = 'processing', extraction_error = '', updated_at = NOW()
			  WHERE id IN ({$ids_csv})
			    AND extraction_status IN ('pending','skipped','error')",
			[]
		) );
		$claimed = $wpdb->get_col(
			"SELECT id FROM {$db->tbl_passages()}
			  WHERE id IN ({$ids_csv})
			    AND extraction_status = 'processing'
			    AND updated_at >= DATE_SUB(NOW(), INTERVAL 2 SECOND)"
		);
		if ( count( $claimed ) !== count( $ids ) ) {
			// Only log when ALL rows lost (= total contention worth investigating).
			// Partial loss (e.g. 1/3) is expected when ajax + cron lanes tick
			// concurrently — atomic claim correctly prevents double-dispatch,
			// no harm done; logging it just adds noise.
			if ( empty( $claimed ) ) {
				// Race condition log — commented out 2026-05-09. Normal when ajax + cron
				// lanes tick concurrently; atomic claim correctly prevents double-dispatch.
				// bizcity_tc_learning_debug_log( sprintf(
				// 	'tick_extract job=%d → race lost ALL %d passage(s) to sibling tick',
				// 	$job_id, count( $ids )
				// ) );
			}
		}
		if ( empty( $claimed ) ) {
			// All rows were claimed by a sibling tick — yield to avoid empty dispatch.
			$queue->extend_lease( $job_id, $owner, self::LEASE_TTL_S );
			return [ 'done' => false, 'busy' => false, 'error' => false, 'phase' => 'extracting', 'job' => $job ];
		}
		$ids = array_map( 'intval', $claimed );

		// ── Step 5: dispatch (loopback) OR run synchronously (fallback) ─
		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE — compute lane once
		// up-front and stamp it into every dispatch-level log line so the persisted
		// daily file log (not just the ephemeral SSE stream) shows whether cron or
		// admin-ajax drove this batch. See PHASE-0.48-LEARNING-LOG-TRACE.md.
		$lane = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
		if ( $loopback_dead ) {
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d lane=%s owner=%s → SYNC fallback, %d passage(s): [%s]', $job_id, $lane, $owner, count( $ids ), implode( ',', $ids ) ) );
			$events->push( $nb, 'log', [
				'level' => 'warn',
				'msg'   => sprintf(
					'[fallback] Loopback workers chưa tăng counter sau %d batch — chuyển sang chạy đồng bộ trong tick.',
					$dispatched_rounds
				),
			], $job_id );
			$dispatched = self::run_workers_sync( $job_id, $ids, $nb, (int) $job['user_id'], $owner );
		} else {
			// Each worker = 1 non-blocking HTTP request → processed by a separate
			// PHP-FPM process concurrently. No Action Scheduler needed.
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d lane=%s owner=%s → LOOPBACK dispatch, %d passage(s): [%s]', $job_id, $lane, $owner, count( $ids ), implode( ',', $ids ) ) );
			$dispatched = self::dispatch_parallel_workers( $job_id, $ids, $nb, (int) $job['user_id'], $owner );
		}

		$batch_no = $queue->next_batch_no( $job_id );
		$batch_id = $queue->start_batch( $job_id, $nb, $batch_no, 'extract-parallel', $owner );
		// Mark batch dispatched; actual finish counts update atomically via passage_worker REST handler.
		$queue->finish_batch( $batch_id, [ 'passages_count' => $dispatched, 'triplets_count' => 0, 'errors_count' => 0 ] );
		$queue->extend_lease( $job_id, $owner, self::LEASE_TTL_S );
		$queue->update( $job_id, [
			'phase'       => 'extracting',
			'progress'    => min( 75, 10 + (int) ( ( (int) $job['batches_done'] + 1 ) * 5 ) ),
			'batches_done'=> (int) $job['batches_done'] + 1,
		] );

		$events->push( $nb, 'log', [
			'level' => 'info',
			'msg'   => sprintf( '[%s|parallel] Dispatched %d workers (passages: %s).', $lane, $dispatched, implode( ', ', $ids ) ),
		], $job_id );

		// ── Step 6: incremental approve every N triplets ────────────────
		// Architectural fix 2026-05-04: previously approve_all_pending() only
		// ran when ALL passages were extracted (could be 47+ minutes for 562
		// passages). User saw "0 entities approved" and assumed system broken.
		// Now we drain the triplet queue periodically so entities/relations
		// surface in the graph as soon as they're extracted.
		// Threshold lowered from 50 → 5 (2026-05-11): with Vietnamese short-section
		// documents each passage yields 1-3 triplets; 50 would never trigger.
		$incr_threshold = (int) apply_filters( 'bizcity_twinchat_learning_incremental_approve_threshold', 5 );
		if ( $incr_threshold > 0 && class_exists( 'BizCity_KG_Database' ) && class_exists( 'BizCity_KG_Graph_Service' ) ) {
			// MUST use BizCity_KG_Database::tbl_triplet_queue() — multisite-aware.
			// $wpdb->prefix is wrong when cron runs outside blog context.
			$tbl_tq = BizCity_KG_Database::instance()->tbl_triplet_queue();
			$tq = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$tbl_tq} WHERE notebook_id = %d AND status = 'pending'",
				$nb
			) );
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → triplet queue pending=%d (threshold=%d)', $job_id, $tq, $incr_threshold ) );
			if ( $tq >= $incr_threshold ) {
				bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → incremental approve %d pending triplets', $job_id, $tq ) );
				$res = BizCity_KG_Graph_Service::instance()->approve_all_pending( $nb, (int) $job['user_id'] );
				if ( ! is_wp_error( $res ) ) {
					$delta_appr = (int) ( $res['approved'] ?? 0 );
					bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → approve_all_pending returned approved=%d errors=%d', $job_id, $delta_appr, (int) ( $res['errors'] ?? 0 ) ) );
					if ( $delta_appr > 0 ) {
						$queue->update( $job_id, [
							'entities_approved' => (int) $job['entities_approved'] + $delta_appr,
						] );
						$events->push( $nb, 'log', [
							'level' => 'ok',
							'msg'   => sprintf( '[approve+] +%d quan hệ vào graph (incremental).', $delta_appr ),
						], $job_id );
					}
				} else {
					bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → approve_all_pending WP_ERROR: %s', $job_id, $res->get_error_message() ) );
				}
			}
		} else {
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → incremental approve SKIPPED (kg_db=%s, kg_svc=%s)', $job_id, class_exists( 'BizCity_KG_Database' ) ? 'yes' : 'NO', class_exists( 'BizCity_KG_Graph_Service' ) ? 'yes' : 'NO' ) );
		}

		return [ 'done' => false, 'busy' => false, 'error' => false, 'phase' => 'extracting', 'in_flight' => $dispatched, 'job' => $queue->get_job( $job_id ) ];
	}

	/**
	 * Fire N non-blocking loopback HTTP requests to the passage-worker REST endpoint.
	 *
	 * Each request is handled by a separate PHP-FPM process = true parallelism.
	 * Action Scheduler is NOT required (and is blocked on this multisite).
	 *
	 * Auth: HMAC token wp_hash("{job_id}:{passage_id}:passage_worker") passed as
	 * X-TC-Internal-Token header — verified in BizCity_TwinChat_REST_Learning::check_passage_worker_token().
	 *
	 * @param int   $job_id
	 * @param int[] $passage_ids  already marked 'processing' in DB
	 * @param int   $nb           notebook_id
	 * @param int   $user_id      for tracing
	 * @return int  count dispatched
	 */
	protected static function dispatch_parallel_workers( $job_id, array $passage_ids, $nb, $user_id = 0, $owner = 'cron' ) {
		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE — forward the
		// driving lane to the worker process via header so passage_worker()'s
		// own log lines are tagged too (not just the dispatcher's).
		$lane         = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
		$ns           = defined( 'BIZCITY_TWINCHAT_REST_NS' ) ? BIZCITY_TWINCHAT_REST_NS : 'bizcity-twinchat/v1';
		$public_url   = rest_url( $ns . '/learning/passage-worker' );

		// Loopback strategy — fire to the public URL through Cloudflare.
		// Rationale: 127.0.0.1 / Apache vhost binding is unreliable on this host
		// (the dispatcher reports `fired=3/3` but workers never arrive at the
		// REST endpoint). Going through the public URL means each request gets
		// served by a fresh PHP-FPM process via the normal HTTPS path that
		// the rest of the site already uses — the exact same path the FE uses
		// for `/learning/jobs/{id}/tick`, which we know works.
		//
		// Cost: an extra TLS handshake + Cloudflare hop per worker. Mitigation:
		// `blocking=false` so we never wait for it.
		//
		// Filterable: ops can switch back to 127.0.0.1 with
		//   add_filter( 'bizcity_twinchat_learning_loopback_use_public', '__return_true' );
		// or override the rewrite IP with `bizcity_twinchat_learning_loopback_ip`.
		$parts       = wp_parse_url( $public_url );
		$origin_host = $parts['host'] ?? '';
		$use_127     = (bool) apply_filters( 'bizcity_twinchat_learning_loopback_use_127', false );

		if ( $use_127 ) {
			$loopback_ip  = (string) apply_filters( 'bizcity_twinchat_learning_loopback_ip', '127.0.0.1' );
			$prefer_http  = (bool) apply_filters( 'bizcity_twinchat_learning_loopback_prefer_http', true );
			$scheme       = ( $prefer_http ) ? 'http://' : ( ( isset( $parts['scheme'] ) && $parts['scheme'] === 'https' ) ? 'https://' : 'http://' );
			$loopback_url = $scheme . $loopback_ip . ( $parts['path'] ?? '' );
		} else {
			// Public URL — same scheme/host as the visible site.
			$loopback_url = $public_url;
		}

		// Connect timeout — 5 s through Cloudflare HTTPS (TLS handshake ~200 ms +
		// CF edge round-trip); generous because we don't wait for the response.
		$timeout = (float) apply_filters( 'bizcity_twinchat_learning_loopback_timeout', 5.0 );

		$events = class_exists( 'BizCity_TwinChat_Learning_Events' )
			? BizCity_TwinChat_Learning_Events::instance() : null;

		$dispatched = 0;
		foreach ( $passage_ids as $pid ) {
			$token = wp_hash( (int) $job_id . ':' . (int) $pid . ':passage_worker' );
			$res = wp_remote_post( $loopback_url, [
				'blocking'    => false,
				'timeout'     => $timeout,
				'redirection' => 0,
				'sslverify'   => false, // loopback IP won't match cert SAN
				'body'        => [
					'job_id'     => (int) $job_id,
					'passage_id' => (int) $pid,
					'nb'         => (int) $nb,
				],
				'headers'     => [
					'Host'                => $origin_host,
					'X-TC-Internal-Token' => $token,
					'X-TC-User-Id'        => (int) $user_id,
					// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE
					'X-TC-Lane'           => $lane,
				],
			] );

			if ( is_wp_error( $res ) ) {
				bizcity_tc_learning_debug_log( sprintf( 'dispatch passage=%d WP_Error: %s', $pid, $res->get_error_message() ) );
				// blocking=false rarely returns WP_Error, but log if it does.
				if ( $events ) {
					$events->push( $nb, 'log', [
						'level' => 'warn',
						'msg'   => sprintf( '[dispatch] passage #%d loopback fail: %s', $pid, $res->get_error_message() ),
					], $job_id );
				}
				continue;
			}
			$dispatched++;
		}

		bizcity_tc_learning_debug_log( sprintf( 'dispatch_parallel_workers job=%d lane=%s owner=%s → fired=%d/%d url=%s host=%s timeout=%.1fs', $job_id, $lane, $owner, $dispatched, count( $passage_ids ), $loopback_url, $origin_host, $timeout ) );

		// Confirmation log so we can see workers were fired (or not).
		if ( $events ) {
			$events->push( $nb, 'log', [
				'level' => 'info',
				'msg'   => sprintf( '[dispatch] %d/%d workers fired → %s (Host: %s)',
					$dispatched, count( $passage_ids ), $loopback_url, $origin_host ),
			], $job_id );
		}

		return $dispatched;
	}

	/**
	 * Pause a job because the cost-guard rejected the user (quota_exceeded /
	 * cap_exceeded). Stamps `restartable_at` (UTC midnight by default — matches
	 * cost guard daily bucket), persists ONE structured event for the FE
	 * banner, and writes a single debug log line. Subsequent ticks short-circuit
	 * via the `restartable_at` gate at the top of {@see tick()} — no log spam,
	 * no LLM calls.
	 *
	 * Safe to call multiple times: the transient + restartable_at idempotency
	 * means re-emission only fires when the block transitions OFF→ON.
	 *
	 * @param int                  $job_id
	 * @param int                  $nb       notebook id (for events)
	 * @param int                  $user_id  the user whose quota tripped
	 * @param WP_Error             $err      cost-guard error
	 * @param object|null          $events   BizCity_TwinChat_Learning_Events|null
	 * @return array {code,reason,retry_after,used,cap}
	 */
	protected static function pause_for_quota( $job_id, $nb, $user_id, $err, $events = null ) {
		$code   = $err->get_error_code();
		$reason = $err->get_error_message();
		$diag   = (array) $err->get_error_data();
		$queue  = BizCity_TwinChat_Learning_Job_Queue::instance();
		$current_job = $queue->get_job( (int) $job_id );
		$already_paused = $current_job && ! empty( $current_job['restartable_at'] )
			&& strtotime( (string) $current_job['restartable_at'] . ' UTC' ) > time();

		$payload = null;
		if ( class_exists( 'BizCity_TwinChat_Learning_Quota_Cooldown' ) ) {
			$existing = BizCity_TwinChat_Learning_Quota_Cooldown::get_block( (int) $user_id );
			if ( $existing && ! empty( $existing['retry_after'] ) && (int) $existing['retry_after'] > time() ) {
				// [2026-08-10 Johnny Chu] PHASE-0.49-LEARNING-OBSERVABILITY - reuse the user cooldown but still stamp this job.
				$payload = $existing;
			}
			if ( ! $payload ) {
				$payload = BizCity_TwinChat_Learning_Quota_Cooldown::apply_block( (int) $user_id, (string) $code, (string) $reason );
			}
		}
		$retry_after = isset( $payload['retry_after'] ) ? (int) $payload['retry_after'] : ( time() + 3600 );
		// [2026-08-10 Johnny Chu] PHASE-0.49-LEARNING-OBSERVABILITY - audit job-wide quota pause.
		bizcity_tc_learning_audit_log( 'job_paused', array(
			'reason_code'     => (string) $code,
			'error_class'     => 'resource_job_wide',
			'blog_id'         => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'notebook_id'     => (int) $nb,
			'job_id'          => (int) $job_id,
			'user_id'         => (int) $user_id,
			'retry_after'     => $retry_after,
			'restartable_at'  => gmdate( 'c', $retry_after ),
			'decision'        => 'pause_job',
			'raw_error_code'  => (string) $code,
			'normalized_code' => (string) $code,
		) );

		// Stamp the job so all tick lanes (cron + ajax) short-circuit cheaply.
		$queue->update( $job_id, [
			'status'         => 'running', // keep status so resume is implicit
			'error'          => sprintf( '[%s] %s', $code, $reason ),
			'restartable_at' => gmdate( 'Y-m-d H:i:s', $retry_after ),
		] );

		// [2026-06-08 Johnny Chu] R-TRAINING-QUOTA — 3-layer aware event.
		// layer field drives FE to show right CTA (upgrade plan vs admin cap vs hub credit).
		$layer       = isset( $diag['layer'] ) ? (string) $diag['layer'] : ( $code === 'cap_exceeded' ? 'site_cap' : 'end_user' );
		$plan_slug   = isset( $diag['user_plan'] )  ? (string) $diag['user_plan']  : '';
		$plan_label  = isset( $diag['plan_label'] ) ? (string) $diag['plan_label'] : '';
		$upgrade_url = isset( $diag['upgrade_url'] ) ? (string) $diag['upgrade_url'] : home_url( '/pricing' );
		$admin_url   = isset( $diag['admin_url'] )  ? (string) $diag['admin_url']  : admin_url( 'admin.php?page=bizcity-kg-hub-settings' );

		// Emit ONE structured event for FE banner.
		if ( $events && ! $already_paused ) {
			$events->push( $nb, 'log', [
				'level'       => 'warn',
				'msg'         => sprintf(
					'[quota] %s — Tự động tiếp tục lúc %s UTC hoặc nâng cấp gói để chạy ngay.',
					$reason,
					gmdate( 'H:i', $retry_after )
				),
				'code'        => $code,
				'retry_after' => $retry_after,
			], $job_id );
			$events->push( $nb, 'quota_exhausted', [
				'code'        => $code,
				'message'     => $reason,
				'layer'       => $layer,
				'retry_after' => $retry_after,
				'user_id'     => (int) $user_id,
				'used'        => isset( $diag['used'] ) ? (int) $diag['used'] : ( isset( $payload['used'] ) ? (int) $payload['used'] : null ),
				'cap'         => isset( $diag['cap'] )  ? (int) $diag['cap']  : ( isset( $payload['cap'] )  ? (int) $payload['cap']  : null ),
				'user_plan'   => $plan_slug,
				'plan_label'  => $plan_label,
				'upgrade_url' => $upgrade_url,
				'admin_url'   => $layer === 'site_cap' ? $admin_url : null,
				'hub_url'     => isset( $diag['hub_url'] ) ? (string) $diag['hub_url'] : null,
				'diag'        => [
					'quota_per_user'       => isset( $diag['quota_per_user'] )       ? (int)    $diag['quota_per_user']       : null,
					'quota_filter_hooked'  => isset( $diag['quota_filter_hooked'] )  ? (bool)   $diag['quota_filter_hooked']  : null,
					'exempt_filter_hooked' => isset( $diag['exempt_filter_hooked'] ) ? (bool)   $diag['exempt_filter_hooked'] : null,
					'entitlement_status'   => isset( $diag['entitlement_status'] )   ? (string) $diag['entitlement_status']   : null,
					'entitlement_tier'     => isset( $diag['entitlement_tier'] )     ? (string) $diag['entitlement_tier']     : null,
					'entitlement_balance'  => isset( $diag['entitlement_balance'] )  ? (float)  $diag['entitlement_balance']  : null,
					'entitlement_bypass'   => isset( $diag['entitlement_bypass'] )   ? (bool)   $diag['entitlement_bypass']   : null,
				],
			], $job_id );
		}

		// [2026-06-08 Johnny Chu] R-TRAINING-QUOTA — structured log entry.
		bizcity_tc_learning_debug_log( sprintf(
			'QUOTA-GATE job=%d user=%d plan=%s layer=%s code=%s used=%s/%s retry=%s',
			$job_id, $user_id, $plan_slug ?: '?', $layer, $code,
			isset( $diag['used'] ) ? (string) $diag['used'] : '?',
			isset( $diag['cap'] )  ? (string) $diag['cap']  : '?',
			gmdate( 'Y-m-d H:i:s', $retry_after )
		) );

		return $payload ?: [
			'code'        => $code,
			'reason'      => $reason,
			'retry_after' => $retry_after,
		];
	}

	/**
	 * Bridge an asynchronous worker quota failure into the canonical job pause.
	 *
	 * @param int      $job_id
	 * @param int      $nb
	 * @param int      $user_id
	 * @param WP_Error $err
	 * @return array
	 */
	public static function pause_from_worker( $job_id, $nb, $user_id, $err ) {
		// [2026-08-10 Johnny Chu] PHASE-0.49-LEARNING-OBSERVABILITY - unify loopback and cron quota policy.
		return self::pause_for_quota(
			(int) $job_id,
			(int) $nb,
			(int) $user_id,
			$err,
			class_exists( 'BizCity_TwinChat_Learning_Events' ) ? BizCity_TwinChat_Learning_Events::instance() : null
		);
	}

	/**
	 * Synchronous fallback — extract passages inline within the current tick.
	 *
	 * Used when {@see dispatch_parallel_workers()} keeps firing but the
	 * counter never moves (loopback HTTP silently dropped on this host).
	 * Slow but reliable: 1 LLM call per tick, counter updates atomically.
	 *
	 * Mirrors the body of {@see BizCity_TwinChat_REST_Learning::passage_worker()}
	 * minus the HTTP boundary.
	 *
	 * @return int number of passages processed (== count($passage_ids) unless extractor missing)

	 * Synchronous fallback — extract passages inline within the current tick.
	 *
	 * Used when {@see dispatch_parallel_workers()} keeps firing but the
	 * counter never moves (loopback HTTP silently dropped on this host).
	 * Slow but reliable: 1 LLM call per tick, counter updates atomically.
	 *
	 * Mirrors the body of {@see BizCity_TwinChat_REST_Learning::passage_worker()}
	 * minus the HTTP boundary.
	 *
	 * @return int number of passages processed (== count($passage_ids) unless extractor missing)
	 */
	protected static function run_workers_sync( $job_id, array $passage_ids, $nb, $user_id = 0, $owner = 'cron' ) {
		if ( ! class_exists( 'BizCity_KG_Triplet_Extractor' ) ) {
			return 0;
		}
		// [2026-07-25 Johnny Chu] PHASE-0.48-LEARNING-LOG-TRACE — SYNC fallback
		// always runs inline in whichever process called tick() (cron or ajax),
		// so the owner passed in IS the true driver — tag every line with it.
		$lane = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
		global $wpdb;
		$tbl_jobs = class_exists( 'BizCity_TwinChat_Learning_Database' )
			? BizCity_TwinChat_Learning_Database::instance()->table_jobs() : '';
		$events = class_exists( 'BizCity_TwinChat_Learning_Events' )
			? BizCity_TwinChat_Learning_Events::instance() : null;

		// Worker context — same impersonation logic as REST passage_worker.
		if ( $user_id > 0 && get_current_user_id() === 0 ) {
			wp_set_current_user( $user_id );
		}

		$processed = 0;
		foreach ( $passage_ids as $pid ) {
			$pid = (int) $pid;
			bizcity_tc_learning_debug_log( sprintf( 'sync_worker job=%d passage=%d lane=%s owner=%s START (user=%d)', $job_id, $pid, $lane, $owner, $user_id ) );
			if ( $events ) {
				$events->push( $nb, 'log', [
					'level' => 'info',
					'msg'   => sprintf( '[sync→] start passage #%d (user=%d)', $pid, $user_id ),
				], $job_id );
			}

			$result = BizCity_KG_Triplet_Extractor::instance()->extract_passage( $pid );

			if ( is_wp_error( $result ) ) {
				bizcity_tc_learning_debug_log( sprintf( 'sync_worker job=%d passage=%d lane=%s owner=%s ERROR [%s]: %s', $job_id, $pid, $lane, $owner, $result->get_error_code(), $result->get_error_message() ) );
				if ( $events ) {
					$events->push( $nb, 'log', [
						'level' => 'warn',
						'msg'   => sprintf( '[sync] passage #%d error [%s]: %s',
							$pid, $result->get_error_code(), $result->get_error_message() ),
					], $job_id );
				}
				// Defensive cooldown: tick_extract should have caught this, but
				// if quota tripped mid-loop (e.g. another tab burned the last
				// slot), stop now — don't iterate remaining passages.
				$code = $result->get_error_code();
				if ( in_array( $code, array( 'quota_exceeded', 'quota_exhausted', 'cap_exceeded' ), true ) ) {
					self::pause_for_quota( $job_id, $nb, $user_id, $result, $events );
					break;
				}
				continue;
			}

			$triplets = (int) $result;
			bizcity_tc_learning_debug_log( sprintf( 'sync_worker job=%d passage=%d lane=%s owner=%s OK → %d triplets', $job_id, $pid, $lane, $owner, $triplets ) );
			if ( $tbl_jobs !== '' ) {
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$tbl_jobs} SET passages_processed = passages_processed + 1,
					 triplets_extracted = triplets_extracted + %d WHERE id = %d",
					$triplets, $job_id
				) );
			}
			$processed++;

			if ( $events ) {
				$events->push( $nb, 'log', [
					'level' => 'info',
					'msg'   => sprintf( '[sync✓] passage #%d → %d quan hệ', $pid, $triplets ),
				], $job_id );
			}
		}
		return $processed;
	}

	/** Approve all pending triplets, finalise + notify. */
	protected static function tick_approve( array $job, $owner ) {
		$queue    = BizCity_TwinChat_Learning_Job_Queue::instance();
		$events   = BizCity_TwinChat_Learning_Events::instance();
		$job_id   = (int) $job['id'];
		$nb       = (int) $job['notebook_id'];
		$user_id  = (int) $job['user_id'];
		$batch_no = $queue->next_batch_no( $job_id );
		$batch_id = $queue->start_batch( $job_id, $nb, $batch_no, 'approve', $owner );

		$lane = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
		$events->push( $nb, 'log', [ 'level' => 'step', 'msg' => sprintf( '[%s] Twin đang duyệt và ghi vào não bộ…', $lane ) ], $job_id );

		$approved = BizCity_KG_Graph_Service::instance()->approve_all_pending( $nb, $user_id );
		if ( is_wp_error( $approved ) ) {
			$queue->finish_batch( $batch_id, [], $approved->get_error_message() );
			throw new Exception( 'Approve lỗi: ' . $approved->get_error_message() );
		}

		$count_appr = (int) ( $approved['approved'] ?? 0 );
		$count_err  = (int) ( $approved['errors']   ?? 0 );
		$entity_ids = isset( $approved['entity_ids'] ) && is_array( $approved['entity_ids'] )
			? array_values( array_map( 'intval', $approved['entity_ids'] ) )
			: [];

		$queue->finish_batch( $batch_id, [
			'passages_count' => 0,
			'triplets_count' => $count_appr,
			'errors_count'   => $count_err,
		] );

		$started_ts  = $job['started_at'] ? strtotime( $job['started_at'] . ' UTC' ) : time();
		$duration_ms = (int) ( ( time() - $started_ts ) * 1000 );

		$queue->update( $job_id, [
			'status'             => 'done',
			'phase'              => 'done',
			'progress'           => 100,
			'entities_approved'  => $count_appr,
			'entity_ids'         => $entity_ids,
			'batches_done'       => (int) $job['batches_done'] + 1,
			'finished_at'        => current_time( 'mysql', true ),
		] );

		$events->push( $nb, 'log', [
			'level' => 'ok',
			'msg'   => sprintf( 'Twin đã ghi nhớ: %d entities mới, %d quan hệ.', $count_appr, (int) $job['triplets_extracted'] ),
		], $job_id );

		$events->push( $nb, 'done', [
			'job_id'       => $job_id,
			'source_id'    => (int) $job['source_id'],
			'source_title' => (string) $job['source_title'],
			'duration_ms'  => $duration_ms,
			'entity_ids'   => $entity_ids,
			'stats'        => [
				'passages_processed' => (int) $job['passages_processed'],
				'triplets_extracted' => (int) $job['triplets_extracted'],
				'entities_approved'  => $count_appr,
				'errors'             => $count_err,
			],
		], $job_id );

		$final = $queue->get_job( $job_id );
		if ( $final ) {
			BizCity_TwinChat_Learning_Notifier::instance()->notify( $final );
		}

		return [ 'done' => true, 'busy' => false, 'error' => false, 'phase' => 'done', 'job' => $final ];
	}
}
