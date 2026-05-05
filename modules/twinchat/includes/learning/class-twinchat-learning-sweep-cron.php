<?php
/**
 * Bizcity TwinChat — Learning Sweep Cron
 *
 * Wave A (TwinShell Learning Hub) — periodic guardrail that re-enqueues
 * "ghost" chunks: kg_source_chunks rows that are still extraction_status=pending
 * AND not covered by any active learning job.
 *
 * Why this exists:
 *   The realtime extractor depends on KG-Hub action hooks firing reliably.
 *   When Action Scheduler crashes or dbDelta-router drops a tick, chunks can
 *   sit in `pending` forever without progress events. The sweep is a safety
 *   net that runs every 15 minutes per-blog, picks up at most 20 stranded
 *   chunks, and enqueues a `origin=sweep` learning job per affected notebook.
 *
 * Cap: LIMIT 20 chunks/tick → bounded LLM cost even on neglected installs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since      2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Sweep_Cron {

	const HOOK            = 'bizcity_kg_learning_sweep';
	const SCHEDULE_KEY    = 'bizcity_twinchat_learning_15min';
	const SCHEDULE_S      = 900; // 15 min
	const STALE_AFTER_MIN = 5;   // chunks older than 5 min still pending → ghost candidate
	const MAX_PER_TICK    = 20;
	const LOCK_KEY        = 'bizcity_twinchat_learning_sweep_lock';
	const LOCK_TTL_S      = 120;
	const OPT_LAST_TS     = 'bizcity_twinchat_learning_last_sweep';
	const OPT_LAST_COUNT  = 'bizcity_twinchat_learning_last_sweep_count';

	public static function bind() {
		add_filter( 'cron_schedules', [ __CLASS__, 'register_schedule' ] );
		add_action( self::HOOK, [ __CLASS__, 'tick' ] );
		add_action( 'init', [ __CLASS__, 'maybe_schedule' ], 20 );
	}

	public static function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_KEY ] ) ) {
			$schedules[ self::SCHEDULE_KEY ] = [
				'interval' => self::SCHEDULE_S,
				'display'  => __( 'Every 15 minutes (TwinChat learning sweep)', 'bizcity-twin-ai' ),
			];
		}
		return $schedules;
	}

	/** Per-blog scheduling — uses get_option so each multisite blog ticks independently. */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE_KEY, self::HOOK );
		}
	}

	/**
	 * One sweep tick. Idempotent + lock-guarded.
	 *
	 * Strategy:
	 *   1. SELECT up to MAX_PER_TICK (notebook_id, source_id) DISTINCT pairs from
	 *      kg_source_chunks where extraction_status='pending' AND created_at < now-5min.
	 *   2. For each pair, skip if there's an open job (queued|running) covering it.
	 *   3. Else enqueue with origin='sweep'.
	 */
	public static function tick() {
		// Emergency kill-switch (when cron option is corrupt or sweep is firing
		// every request due to wp-cron rebuild loop). Set in wp-config.php:
		//   define('DISABLE_BIZCITY_LEARNING_SWEEP', true);
		if ( defined( 'DISABLE_BIZCITY_LEARNING_SWEEP' ) && DISABLE_BIZCITY_LEARNING_SWEEP ) {
			return;
		}
		// Filter-based throttle (lighter alternative to constant).
		if ( ! apply_filters( 'bizcity_twinchat_learning_sweep_enabled', true ) ) {
			return;
		}

		// Hard rate-limit independent of object-cache transient: if sweep fired
		// within the last 60 seconds (per-blog wp_options row), skip. This
		// survives object-cache flushes and cron-storm conditions where the
		// transient lock evaporates.
		$last_ts = (int) get_option( self::OPT_LAST_TS, 0 );
		$min_gap = (int) apply_filters( 'bizcity_twinchat_learning_sweep_min_gap_s', 60 );
		if ( $last_ts > 0 && ( time() - $last_ts ) < $min_gap ) {
			return;
		}

		// Single-runner lock: avoid double sweeps when wp-cron + AS overlap.
		if ( get_transient( self::LOCK_KEY ) ) {
			return;
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL_S );

		// PHASE-0.13 Wave 10c — tag every KG event fired during this tick as
		// triggered by the sweep cron, so the evidence trail can prove the
		// loop is sweep-driven (not user-driven).
		$tag = static function () { return 'cron:sweep'; };
		add_filter( 'bizcity_kg_progress_log_trigger', $tag );

		try {
			self::do_tick();
		} finally {
			remove_filter( 'bizcity_kg_progress_log_trigger', $tag );
			delete_transient( self::LOCK_KEY );
		}
	}

	protected static function do_tick() {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ||
		     ! class_exists( 'BizCity_TwinChat_Learning_Database' ) ||
		     ! class_exists( 'BizCity_TwinChat_Learning_Job_Queue' ) ) {
			return;
		}

		$chunks_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
		$jobs_tbl   = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$cap        = (int) self::MAX_PER_TICK;
		$stale      = (int) self::STALE_AFTER_MIN;

		// Stranded (notebook, source) pairs.
		// PHASE-0.13 Wave 10d — exclude chat-promoted passages (`source_id IS NULL`).
		// They are owned by BizCity_KG_Auto_Promoter and have their own learning
		// pipeline; sweeping them here was creating a runaway loop where every
		// 15-min tick re-enqueued the same chat backlog forever.
		$pairs = $wpdb->get_results( $wpdb->prepare(
			"SELECT notebook_id, source_id, COUNT(*) AS n
			 FROM {$chunks_tbl}
			 WHERE extraction_status = 'pending'
			   AND notebook_id IS NOT NULL
			   AND source_id IS NOT NULL
			   AND source_id > 0
			   AND created_at < UTC_TIMESTAMP() - INTERVAL %d MINUTE
			 GROUP BY notebook_id, source_id
			 ORDER BY n DESC
			 LIMIT %d",
			$stale, $cap
		), ARRAY_A );

		if ( empty( $pairs ) ) {
			update_option( self::OPT_LAST_TS, time(), false );
			update_option( self::OPT_LAST_COUNT, 0, false );
			return;
		}

		$enqueued = 0;
		$queue    = BizCity_TwinChat_Learning_Job_Queue::instance();

		foreach ( $pairs as $p ) {
			$nb  = (int) $p['notebook_id'];
			$sid = isset( $p['source_id'] ) ? (int) $p['source_id'] : 0;
			if ( $nb <= 0 ) { continue; }

			// Skip when an open job already covers this notebook+source.
			$open = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$jobs_tbl}
				 WHERE notebook_id = %d
				   AND status IN ('queued','running')
				   AND ( source_id = %d OR ( %d = 0 AND source_id IS NULL ) )",
				$nb, $sid, $sid
			) );
			if ( $open > 0 ) { continue; }

			// Find an owner_id to attribute the job to (for cache busting + UI).
			$owner_id = 0;
			if ( class_exists( 'BizCity_KG_Database' ) ) {
				$nb_tbl   = BizCity_KG_Database::instance()->tbl_notebooks();
				$owner_id = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT owner_id FROM {$nb_tbl} WHERE id = %d", $nb
				) );
			}

			$res = $queue->enqueue( [
				'notebook_id'  => $nb,
				'source_id'    => $sid,
				'source_title' => '[sweep]',
				'user_id'      => $owner_id,
				'origin'       => 'sweep',
			] );
			if ( ! is_wp_error( $res ) ) {
				$enqueued++;
				if ( $owner_id > 0 && class_exists( 'BizCity_TwinChat_Learning_Aggregator' ) ) {
					BizCity_TwinChat_Learning_Aggregator::instance()->bust( $owner_id );
				}
				// PHASE-0.13 Wave 10c — record the enqueue so each loop is provable.
				if ( class_exists( 'BizCity_KG_Source_Progress_Log' ) ) {
					BizCity_KG_Source_Progress_Log::record( [
						'notebook_id'  => $nb,
						'source_id'    => $sid > 0 ? $sid : null,
						'event'        => 'sweep_enqueued',
						'triggered_by' => 'cron:sweep',
						'payload'      => [
							'pending_chunks' => (int) $p['n'],
							'owner_id'       => $owner_id,
						],
					] );
				}
			}
		}

		update_option( self::OPT_LAST_TS, time(), false );
		update_option( self::OPT_LAST_COUNT, $enqueued, false );

		if ( $enqueued > 0 && function_exists( 'error_log' ) ) {
			error_log( sprintf( '[TwinChat Learning Sweep] enqueued %d sweep job(s) on blog %d', $enqueued, get_current_blog_id() ) );
		}
	}
}
