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
	 * Dedicated debug logger for the learning pipeline. Writes to a guaranteed
	 * file location that bypasses php.ini error_log configuration. Enables
	 * post-mortem diagnosis when WP_DEBUG_LOG / hosting-provided error_log
	 * silently drops messages.
	 *
	 * Disable by defining BIZCITY_TC_LEARNING_DEBUG = false (default true while debugging).
	 */
	function bizcity_tc_learning_debug_log( $msg ) {
		if ( defined( 'BIZCITY_TC_LEARNING_DEBUG' ) && BIZCITY_TC_LEARNING_DEBUG === false ) {
			return;
		}
		$log_dir = WP_CONTENT_DIR . '/uploads/tc-learning-debug';
		if ( ! is_dir( $log_dir ) ) {
			@wp_mkdir_p( $log_dir );
			// Block direct browser access.
			@file_put_contents( $log_dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			@file_put_contents( $log_dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		$line = sprintf( "[%s] %s\n", gmdate( 'Y-m-d H:i:s' ), (string) $msg );
		@file_put_contents( $log_dir . '/pipeline.log', $line, FILE_APPEND | LOCK_EX );
		// Also try error_log in case it works on this host.
		@error_log( '[TC-Learning] ' . $msg );
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
	 *   - 2026-05-26 raised 3→5 (Pro-Tier Wave). The quota cooldown now stops
	 *     runaway jobs cheaply, so we can afford the larger fan-out. Ops can
	 *     lower back to 3 on hosts with < 20 FPM processes via:
	 *       add_filter('bizcity_twinchat_learning_parallel_workers', fn() => 3);
	 * Set to 1 to revert to sequential mode.
	 */
	const PARALLEL_WORKERS = 5;

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
		$owner    = 'cron';

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
			return [ 'done' => false, 'busy' => true, 'error' => false, 'phase' => $job['phase'], 'job' => $job ];
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
			$cg   = BizCity_KG_Cost_Guard::instance();
			$cap  = $cg->quota_per_user();
			$used = method_exists( $cg, 'user_passages_today' ) ? (int) $cg->user_passages_today( $user_id ) : 0;
			// Site-wide USD cap still applies (admins shouldn't escape this).
			$site_cap   = method_exists( $cg, 'daily_cap_usd' ) ? (float) $cg->daily_cap_usd() : 0.0;
			$site_spent = method_exists( $cg, 'spent_today_usd' ) ? (float) $cg->spent_today_usd() : 0.0;

			$err = null;
			if ( $site_cap > 0 && $site_spent >= $site_cap ) {
				$err = new WP_Error( 'cap_exceeded', sprintf(
					'Site-wide daily cap reached: $%.2f / $%.2f', $site_spent, $site_cap
				) );
			} elseif ( $used + 1 > $cap ) {
				$err = new WP_Error( 'quota_exceeded', sprintf(
					'User %d quota: %d / %d passages today', $user_id, $used, $cap
				) );
			}

			if ( $err ) {
				$paused = self::pause_for_quota( $job_id, $nb, $user_id, $err, $events );
				$queue->release_lease( $job_id, $owner );
				return [
					'done'        => false,
					'busy'        => true,
					'error'       => false,
					'paused'      => true,
					'phase'       => 'extracting',
					'job'         => $queue->get_job( $job_id ),
					'retry_after' => $paused['retry_after'] ?? ( time() + 900 ),
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
		// them NOW (don't wait the orphan timeout) so the sync fallback
		// can re-process them this tick.
		// NOTE: race-safe — atomic UPDATE returns affected rows, only one tick
		// wins the reclaim. Subsequent ticks see processing_count=0.
		if ( $inflight > 0 && $loopback_dead ) {
			$reclaimed = (int) $wpdb->query( $wpdb->prepare(
				"UPDATE {$db->tbl_passages()}
				    SET extraction_status = 'pending', updated_at = NOW()
				  WHERE notebook_id = %d
				    AND extraction_status = 'processing'
				    AND updated_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)",
				$nb, $orphan_timeout_s
			) );
			if ( $reclaimed > 0 ) {
				bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → reclaim %d stuck \'processing\' rows', $job_id, $reclaimed ) );
				$events->push( $nb, 'log', [
					'level' => 'warn',
					'msg'   => sprintf( '[reclaim] Reset %d passage \'processing\' \u2192 \'pending\' (loopback dead).', $reclaimed ),
				], $job_id );
			}
		}

		// ── Step 2: fetch next batch of pending passages ────────────────
		$parallel = (int) apply_filters( 'bizcity_twinchat_learning_parallel_workers', self::PARALLEL_WORKERS, $nb );
		$parallel = max( 1, min( 10, $parallel ) );
		if ( $loopback_dead ) {
			// Sync mode — process exactly 1 passage per tick.
			$parallel = 1;
		}

		$error_retry_s = (int) apply_filters( 'bizcity_twinchat_learning_error_retry_s', 300 );
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$db->tbl_passages()}
			 WHERE notebook_id = %d
			   AND (
			         extraction_status = 'pending'
			         OR (
			              extraction_status = 'processing'
			              AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
			            )
			         OR extraction_status = 'skipped'
			         OR (
			              extraction_status = 'error'
			              AND updated_at < DATE_SUB(NOW(), INTERVAL %d SECOND)
			            )
			       )
			 ORDER BY created_at ASC LIMIT %d",
			$nb, $orphan_timeout_s, $error_retry_s, $parallel
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
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$db->tbl_passages()}
			    SET extraction_status = 'processing', updated_at = NOW()
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
		if ( $loopback_dead ) {
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → SYNC fallback, %d passage(s): [%s]', $job_id, count( $ids ), implode( ',', $ids ) ) );
			$events->push( $nb, 'log', [
				'level' => 'warn',
				'msg'   => sprintf(
					'[fallback] Loopback workers chưa tăng counter sau %d batch — chuyển sang chạy đồng bộ trong tick.',
					$dispatched_rounds
				),
			], $job_id );
			$dispatched = self::run_workers_sync( $job_id, $ids, $nb, (int) $job['user_id'] );
		} else {
			// Each worker = 1 non-blocking HTTP request → processed by a separate
			// PHP-FPM process concurrently. No Action Scheduler needed.
			bizcity_tc_learning_debug_log( sprintf( 'tick_extract job=%d → LOOPBACK dispatch, %d passage(s): [%s]', $job_id, count( $ids ), implode( ',', $ids ) ) );
			$dispatched = self::dispatch_parallel_workers( $job_id, $ids, $nb, (int) $job['user_id'] );
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

		$lane = ( strpos( (string) $owner, 'ajax' ) === 0 ) ? 'ajax' : 'cron';
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
	protected static function dispatch_parallel_workers( $job_id, array $passage_ids, $nb, $user_id = 0 ) {
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

		bizcity_tc_learning_debug_log( sprintf( 'dispatch_parallel_workers job=%d → fired=%d/%d url=%s host=%s timeout=%.1fs', $job_id, $dispatched, count( $passage_ids ), $loopback_url, $origin_host, $timeout ) );

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
		$queue  = BizCity_TwinChat_Learning_Job_Queue::instance();

		$payload = null;
		if ( class_exists( 'BizCity_TwinChat_Learning_Quota_Cooldown' ) ) {
			$existing = BizCity_TwinChat_Learning_Quota_Cooldown::get_block( (int) $user_id );
			if ( $existing && ! empty( $existing['retry_after'] ) && (int) $existing['retry_after'] > time() ) {
				// Already blocked — no re-emit, just propagate.
				return $existing;
			}
			$payload = BizCity_TwinChat_Learning_Quota_Cooldown::apply_block( (int) $user_id, (string) $code, (string) $reason );
		}
		$retry_after = isset( $payload['retry_after'] ) ? (int) $payload['retry_after'] : ( time() + 3600 );

		// Stamp the job so all tick lanes (cron + ajax) short-circuit cheaply.
		$queue->update( $job_id, [
			'status'         => 'running', // keep status so resume is implicit
			'error'          => sprintf( '[%s] %s', $code, $reason ),
			'restartable_at' => gmdate( 'Y-m-d H:i:s', $retry_after ),
		] );

		// Emit ONE structured event for FE banner.
		if ( $events ) {
			$events->push( $nb, 'log', [
				'level'       => 'warn',
				'msg'         => sprintf(
					'[quota] %s — Tự động tiếp tục lúc %s UTC hoặc nâng cấp Pro để chạy ngay.',
					$reason,
					gmdate( 'H:i', $retry_after )
				),
				'code'        => $code,
				'retry_after' => $retry_after,
			], $job_id );
			$events->push( $nb, 'quota_exhausted', [
				'code'        => $code,
				'message'     => $reason,
				'retry_after' => $retry_after,
				'user_id'     => (int) $user_id,
				'used'        => isset( $payload['used'] ) ? (int) $payload['used'] : null,
				'cap'         => isset( $payload['cap'] ) ? (int) $payload['cap'] : null,
			], $job_id );
		}

		bizcity_tc_learning_debug_log( sprintf(
			'tick job=%d user=%d PAUSED [%s] until %s — %s',
			$job_id, $user_id, $code, gmdate( 'Y-m-d H:i:s', $retry_after ), $reason
		) );

		return $payload ?: [
			'code'        => $code,
			'reason'      => $reason,
			'retry_after' => $retry_after,
		];
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
	protected static function run_workers_sync( $job_id, array $passage_ids, $nb, $user_id = 0 ) {
		if ( ! class_exists( 'BizCity_KG_Triplet_Extractor' ) ) {
			return 0;
		}
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
			bizcity_tc_learning_debug_log( sprintf( 'sync_worker job=%d passage=%d START (user=%d)', $job_id, $pid, $user_id ) );
			if ( $events ) {
				$events->push( $nb, 'log', [
					'level' => 'info',
					'msg'   => sprintf( '[sync→] start passage #%d (user=%d)', $pid, $user_id ),
				], $job_id );
			}

			$result = BizCity_KG_Triplet_Extractor::instance()->extract_passage( $pid );

			if ( is_wp_error( $result ) ) {
				bizcity_tc_learning_debug_log( sprintf( 'sync_worker job=%d passage=%d ERROR [%s]: %s', $job_id, $pid, $result->get_error_code(), $result->get_error_message() ) );
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
				if ( $code === 'quota_exceeded' || $code === 'cap_exceeded' ) {
					self::pause_for_quota( $job_id, $nb, $user_id, $result, $events );
					break;
				}
				continue;
			}

			$triplets = (int) $result;
			bizcity_tc_learning_debug_log( sprintf( 'sync_worker job=%d passage=%d OK → %d triplets', $job_id, $pid, $triplets ) );
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
