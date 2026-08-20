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
	const JOB_ID          = 'kg.learning_sweep';
	// [2026-07-15 Johnny Chu] R-CRON-TIER Wave 2 — legacy 15m key kept as fallback only.
	const SCHEDULE_KEY    = 'bizcity_twinchat_learning_15min';
	const SCHEDULE_S      = 900; // 15 min
	const STALE_AFTER_MIN = 5;   // chunks older than 5 min still pending → ghost candidate
	const MAX_PER_TICK    = 20;
	const LOCK_KEY        = 'bizcity_twinchat_learning_sweep_lock';
	const LOCK_TTL_S      = 120;
	const OPT_LAST_TS     = 'bizcity_twinchat_learning_last_sweep';
	const OPT_LAST_COUNT  = 'bizcity_twinchat_learning_last_sweep_count';

	/**
	 * [2026-07-23 Johnny Chu] PHASE-0.44 — unify sweep logs into the
	 * dedicated learning log file (uploads/.../bizcity_learning_logs/YYYY-MM-DD.log)
	 * instead of global PHP error logs.
	 *
	 * @param string $message
	 */
	protected static function write_learning_log( $message ) {
		$msg = '[sweep] ' . (string) $message;

		if ( function_exists( 'bizcity_tc_learning_debug_log' ) ) {
			bizcity_tc_learning_debug_log( $msg );
			return;
		}

		$path = '';
		if ( function_exists( 'bizcity_tc_learning_debug_log_path' ) ) {
			$path = (string) bizcity_tc_learning_debug_log_path( '', true );
		} else {
			$uploads  = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, true, false ) : array();
			$base_dir = ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) )
				? (string) $uploads['basedir']
				: WP_CONTENT_DIR . '/uploads';
			$log_dir  = trailingslashit( wp_normalize_path( $base_dir ) ) . 'bizcity_learning_logs';
			if ( ! is_dir( $log_dir ) ) {
				@wp_mkdir_p( $log_dir );
			}
			$path = trailingslashit( $log_dir ) . gmdate( 'Y-m-d' ) . '.log';
		}

		if ( $path !== '' ) {
			$line = sprintf( "[%s UTC] [TC-Learning] %s\n", gmdate( 'd-M-Y H:i:s' ), $msg );
			@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
		}

		if ( apply_filters( 'bizcity_twinchat_learning_sweep_mirror_error_log', false ) && function_exists( 'error_log' ) ) {
			error_log( '[TwinChat Learning Sweep] ' . (string) $message );
		}
	}

	public static function bind() {
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — diagnostics must not
		// register a production learning sweep callback or schedule hook.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		add_filter( 'cron_schedules', [ __CLASS__, 'register_schedule' ] );
		add_action( self::HOOK, [ __CLASS__, 'tick' ] );
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			add_action( 'init', [ __CLASS__, 'maybe_schedule' ], 20 );
		} elseif ( is_admin() ) {
			add_action( 'current_screen', [ __CLASS__, 'maybe_schedule_admin_screen' ], 20 );
		}
	}

	// [2026-07-30 Johnny Chu] PHASE-1.22-RUNTIME — avoid cron-array reads/writes on unrelated admin screens.
	public static function maybe_schedule_admin_screen( $screen ) {
		$page      = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$screen_id = $screen && isset( $screen->id ) ? (string) $screen->id : '';
		if ( strpos( $page, 'bizcity-twinchat' ) === false && strpos( $screen_id, 'bizcity-kg' ) === false ) {
			return;
		}
		self::maybe_schedule();
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
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — do not register,
		// reschedule, or synchronize learning sweep cron in diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
		// [2026-07-15 Johnny Chu] R-CRON-TIER Wave 2 — tier-based interval
		// (free 10m / pro 5m / premium 1m), reschedule only when changed.
		$want = self::desired_schedule();
		$ts   = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			$cur = wp_get_schedule( self::HOOK );
			if ( $cur !== $want ) {
				wp_unschedule_event( $ts, self::HOOK );
				$ts = false;
			}
		}

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			// [2026-07-25 Johnny Chu] PHASE-0.46 W4.5 — register in core/cron so sweep runs are visible in cron registry/logs.
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => self::JOB_ID,
				'hook'        => self::HOOK,
				'interval'    => $want,
				'owner'       => 'modules/twinchat/learning',
				'description' => 'Sweep pending KG chunks and enqueue missing learning jobs.',
				'retention'   => 7,
			) );
		} elseif ( ! $ts ) {
			wp_schedule_event( time() + 60, $want, self::HOOK );
		}

		// [2026-07-15 Johnny Chu] R-CRON-TIER Wave 2 — legacy compatibility:
		// if old hook `bizcity_kg_broadcast_tick` still exists on this site, align
		// it to tier interval; if hook has no handlers, leave unchanged for safety.
		self::sync_legacy_broadcast_hook( $want );
	}

	/**
	 * Keep legacy hook in sync for upgraded sites.
	 *
	 * @param string $want_schedule Desired schedule key.
	 */
	protected static function sync_legacy_broadcast_hook( $want_schedule ) {
		$hook = 'bizcity_kg_broadcast_tick';
		$ts   = wp_next_scheduled( $hook );
		if ( ! $ts ) {
			return;
		}

		if ( ! has_action( $hook ) ) {
			return;
		}

		$cur = wp_get_schedule( $hook );
		if ( $cur !== $want_schedule ) {
			wp_unschedule_event( $ts, $hook );
			wp_schedule_event( time() + 60, $want_schedule, $hook );
		}
	}

	/**
	 * Resolve schedule name for current license tier.
	 *
	 * Priority:
	 *   1) BizCity_Cron_Tier_Settings (if loaded)
	 *   2) Direct option read (network-aware) → bizcity_tier_{N}min
	 *   3) Legacy fallback self::SCHEDULE_KEY (15 min)
	 */
	protected static function desired_schedule() {
		if ( class_exists( 'BizCity_Cron_Tier_Settings' ) ) {
			return (string) BizCity_Cron_Tier_Settings::current_schedule_name();
		}

		$tier = strtolower( trim( (string) get_option( 'bizcity_hub_master_level', 'free' ) ) );
		$map  = array(
			'free'       => 10,
			'pro'        => 5,
			'premium'    => 1,
			'enterprise' => 1,
		);

		$stored = is_multisite()
			? get_site_option( 'bizcity_cron_tier_minutes', array() )
			: get_option( 'bizcity_cron_tier_minutes', array() );
		if ( is_array( $stored ) ) {
			foreach ( $map as $k => $def ) {
				if ( isset( $stored[ $k ] ) ) {
					$m = (int) $stored[ $k ];
					if ( $m < 1 ) { $m = 1; }
					if ( $m > 1440 ) { $m = 1440; }
					$map[ $k ] = $m;
				}
			}
		}

		if ( ! isset( $map[ $tier ] ) ) {
			$tier = 'free';
		}

		$name      = 'bizcity_tier_' . (int) $map[ $tier ] . 'min';
		$schedules = wp_get_schedules();
		if ( isset( $schedules[ $name ] ) ) {
			return $name;
		}

		return self::SCHEDULE_KEY;
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
		// [2026-08-20 Johnny Chu] R-CLI-ASYNC-ISOLATION — a queued sweep must
		// not query ghost chunks or enqueue learning jobs in diagnostics CLI.
		if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
			return;
		}
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

		// Force per-blog schema migration. On fresh blogs (e.g. multisite
		// 1157/1149 that never opened a notebook) this CREATEs the KG tables
		// on the master so the SELECT below has something to read.
		// Cheap thanks to the static `$migrated_blogs` cache inside the class.
		BizCity_KG_Database::instance();

		$chunks_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
		$jobs_tbl   = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$cap        = (int) self::MAX_PER_TICK;
		$stale      = (int) self::STALE_AFTER_MIN;

		// [2026-07-27 Johnny Chu] R-SHOW-TABLES — information_schema probe
		// via learning DB helper to avoid SHOW TABLES full-scan behavior.
		$exists = BizCity_TwinChat_Learning_Database::instance()->table_exists( $chunks_tbl );
		if ( ! $exists ) {
			// Reset last_error so the next caller does not see our suppressed
			// "table doesn't exist" probe error and misclassify it.
			$wpdb->last_error = '';
			update_option( self::OPT_LAST_TS, time(), false );
			update_option( self::OPT_LAST_COUNT, 0, false );
			return;
		}

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
		$prev_cnt = (int) get_option( self::OPT_LAST_COUNT, -1 );
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

			// PHASE-0.7 Pro-Tier: skip sweep enqueue when the owner is in
			// quota cooldown (otherwise sweep keeps re-creating jobs that
			// immediately pause, burning the singleton-guard slot).
			if ( $owner_id > 0
			     && class_exists( 'BizCity_TwinChat_Learning_Quota_Cooldown' )
			     && BizCity_TwinChat_Learning_Quota_Cooldown::get_block( $owner_id ) ) {
				continue;
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

		// [2026-07-23 Johnny Chu] PHASE-0.44 — avoid noisy duplicate sweep logs every minute when count is unchanged.
		$verbose = (bool) apply_filters( 'bizcity_twinchat_learning_sweep_verbose_log', false, $enqueued, $prev_cnt );
		if ( $enqueued > 0 && ( $verbose || $enqueued !== $prev_cnt ) ) {
			self::write_learning_log( sprintf( 'enqueued %d sweep job(s) on blog %d', $enqueued, get_current_blog_id() ) );
		}
	}
}
