<?php
/**
 * Bizcity TwinChat — Learning Aggregator
 *
 * Wave A (TwinShell Learning Hub) — cross-notebook KPI + analytics for the
 * currently-logged-in user. Powers:
 *
 *   GET /learning/summary    → 5 KPI + 3 active jobs + cleanup info  (cached 30s)
 *   GET /learning/analytics  → time-series (24h | 7d | 30d)          (cached 5min)
 *
 * Scoping rule: a notebook is considered "owned" by user when
 *   bizcity_kg_notebooks.owner_id = $user_id.
 * (manage_options users may use ?scope=site to bypass this — handled in REST.)
 *
 * Caches use per-user transients so cron sweeps + presence pings don't fight.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since      2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Aggregator {

	const SUMMARY_TTL_S   = 30;   // 30s — match SSE heartbeat cadence.
	const ANALYTICS_TTL_S = 300;  // 5min.
	const PRESENCE_TTL_S  = 25;   // matches client ping interval (~20s).

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// ── Public surface ──────────────────────────────────────────────────

	/**
	 * Roll-up snapshot for the user.
	 *
	 * @param int  $user_id
	 * @param bool $site_scope When true (admin only) include all notebooks on the blog.
	 * @return array
	 */
	public function summary( $user_id, $site_scope = false ) {
		$user_id = (int) $user_id;
		$key     = $this->cache_key( 'summary', $user_id, $site_scope );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) ) {
			$cached['_cache'] = 'hit';
			return $cached;
		}

		$nb_ids = $this->user_notebook_ids( $user_id, $site_scope );
		$data   = [
			'scope'      => $site_scope ? 'site' : 'user',
			'user_id'    => $user_id,
			'notebooks'  => count( $nb_ids ),
			'kpi'        => $this->compute_kpi( $nb_ids ),
			'active'     => $this->active_jobs( $nb_ids, 3 ),
			'sparkline'  => $this->sparkline_24h( $nb_ids ),
			'cleanup'    => $this->cleanup_info(),
			'generated'  => time(),
			'_cache'     => 'miss',
		];

		set_transient( $key, $data, self::SUMMARY_TTL_S );
		return $data;
	}

	/**
	 * Time-series for analytics view.
	 *
	 * @param int    $user_id
	 * @param string $range '24h' | '7d' | '30d'
	 * @param bool   $site_scope
	 * @return array
	 */
	public function analytics( $user_id, $range = '24h', $site_scope = false ) {
		$user_id = (int) $user_id;
		$range   = in_array( $range, [ '24h', '7d', '30d' ], true ) ? $range : '24h';
		$key     = $this->cache_key( 'analytics_' . $range, $user_id, $site_scope );
		$cached  = get_transient( $key );
		if ( is_array( $cached ) ) {
			$cached['_cache'] = 'hit';
			return $cached;
		}

		$nb_ids = $this->user_notebook_ids( $user_id, $site_scope );
		$data   = [
			'scope'        => $site_scope ? 'site' : 'user',
			'range'        => $range,
			'jobs_series'  => $this->jobs_series( $nb_ids, $range ),
			'entity_types' => $this->entity_types_distribution( $nb_ids ),
			'top_entities' => $this->top_entities( $nb_ids, 5 ),
			'generated'    => time(),
			'_cache'       => 'miss',
		];

		set_transient( $key, $data, self::ANALYTICS_TTL_S );
		return $data;
	}

	/** Bust both caches (called by sweep + bridge after writes). */
	public function bust( $user_id ) {
		$user_id = (int) $user_id;
		foreach ( [ 'summary', 'analytics_24h', 'analytics_7d', 'analytics_30d' ] as $bucket ) {
			delete_transient( $this->cache_key( $bucket, $user_id, false ) );
			delete_transient( $this->cache_key( $bucket, $user_id, true ) );
		}
	}

	// ── Presence (lightweight ping) ─────────────────────────────────────

	public function mark_presence( $user_id, $active = true ) {
		$user_id = (int) $user_id;
		$key     = 'bzlearn_presence_' . $user_id;
		if ( $active ) {
			set_transient( $key, time(), self::PRESENCE_TTL_S );
		} else {
			delete_transient( $key );
		}
	}

	public function is_present( $user_id ) {
		return (bool) get_transient( 'bzlearn_presence_' . (int) $user_id );
	}

	// ── Internals ───────────────────────────────────────────────────────

	private function cache_key( $bucket, $user_id, $site_scope ) {
		return sprintf( 'bzlearn_%s_%d_%d', $bucket, (int) $user_id, $site_scope ? 1 : 0 );
	}

	/** Return notebook IDs owned by user (or all on blog when site_scope). */
	private function user_notebook_ids( $user_id, $site_scope ) {
		global $wpdb;
		if ( ! class_exists( 'BizCity_KG_Database' ) ) {
			return [];
		}
		$tbl = BizCity_KG_Database::instance()->tbl_notebooks();
		if ( $site_scope ) {
			$rows = $wpdb->get_col( "SELECT id FROM {$tbl}" );
		} else {
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tbl} WHERE owner_id = %d", (int) $user_id ) );
		}
		return array_map( 'intval', $rows ?: [] );
	}

	/** Build a safe SQL `IN (...)` placeholder list; returns empty string if no ids. */
	private function in_clause( array $ids ) {
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( empty( $ids ) ) {
			return '';
		}
		return implode( ',', $ids );
	}

	/**
	 * 5 KPI:
	 *   - learning      (jobs running)
	 *   - queued        (jobs queued)
	 *   - completed_24h (jobs done in last 24h)
	 *   - ghost_chunks  (kg_source_chunks pending without a job covering them in last 24h)
	 *   - understanding (% chunks with extraction_status=done over total scoped chunks)
	 */
	private function compute_kpi( array $nb_ids ) {
		global $wpdb;
		$base = [
			'learning'      => 0,
			'queued'        => 0,
			'completed_24h' => 0,
			'ghost_chunks'  => 0,
			'understanding' => 0,
		];
		$in = $this->in_clause( $nb_ids );
		if ( $in === '' ) {
			return $base;
		}

		$jobs_tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();

		$base['learning']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_tbl} WHERE notebook_id IN ({$in}) AND status='running'" );
		$base['queued']        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_tbl} WHERE notebook_id IN ({$in}) AND status='queued'" );
		$base['completed_24h'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$jobs_tbl} WHERE notebook_id IN ({$in}) AND status='done' AND finished_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR" );

		// Ghost chunks: kg_source_chunks pending extraction, > 5 minutes old, no job covering them.
		if ( class_exists( 'BizCity_KG_Database' ) ) {
			$chunks_tbl = BizCity_KG_Database::instance()->tbl_source_chunks();
			$base['ghost_chunks'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$chunks_tbl}
				 WHERE notebook_id IN ({$in})
				   AND extraction_status='pending'
				   AND created_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE"
			);

			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_tbl} WHERE notebook_id IN ({$in})" );
			$done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_tbl} WHERE notebook_id IN ({$in}) AND extraction_status='done'" );
			if ( $total > 0 ) {
				$base['understanding'] = (int) round( ( $done / $total ) * 100 );
			}
		}

		return $base;
	}

	/** Up to N most-recent active (queued|running) jobs for the user. */
	private function active_jobs( array $nb_ids, $limit = 3 ) {
		global $wpdb;
		$in = $this->in_clause( $nb_ids );
		if ( $in === '' ) {
			return [];
		}
		$jobs_tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$limit    = max( 1, min( 20, (int) $limit ) );
		$rows = $wpdb->get_results(
			"SELECT id, notebook_id, source_id, source_title, status, phase, progress,
			        passages_processed, triplets_extracted, batches_total, batches_done,
			        origin, created_at, started_at
			 FROM {$jobs_tbl}
			 WHERE notebook_id IN ({$in}) AND status IN ('queued','running')
			 ORDER BY (status='running') DESC, COALESCE(started_at, created_at) DESC
			 LIMIT {$limit}",
			ARRAY_A
		);
		return array_map( static function ( $r ) {
			$r['id']                 = (int) $r['id'];
			$r['notebook_id']        = (int) $r['notebook_id'];
			$r['source_id']          = isset( $r['source_id'] ) ? (int) $r['source_id'] : null;
			$r['progress']           = (int) $r['progress'];
			$r['passages_processed'] = (int) $r['passages_processed'];
			$r['triplets_extracted'] = (int) $r['triplets_extracted'];
			$r['batches_total']      = (int) $r['batches_total'];
			$r['batches_done']       = (int) $r['batches_done'];
			return $r;
		}, $rows ?: [] );
	}

	/** Hourly count of finished jobs for last 24h. Returns 24 buckets, oldest first. */
	private function sparkline_24h( array $nb_ids ) {
		global $wpdb;
		$buckets = array_fill( 0, 24, 0 );
		$in = $this->in_clause( $nb_ids );
		if ( $in === '' ) {
			return $buckets;
		}
		$jobs_tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$rows = $wpdb->get_results(
			"SELECT TIMESTAMPDIFF(HOUR, finished_at, UTC_TIMESTAMP()) AS bucket, COUNT(*) AS c
			 FROM {$jobs_tbl}
			 WHERE notebook_id IN ({$in}) AND status='done'
			   AND finished_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR
			 GROUP BY bucket",
			ARRAY_A
		);
		foreach ( $rows ?: [] as $r ) {
			$b = (int) $r['bucket'];
			if ( $b >= 0 && $b < 24 ) {
				// Oldest hour at index 0 → invert.
				$buckets[ 23 - $b ] = (int) $r['c'];
			}
		}
		return $buckets;
	}

	/**
	 * Per-bucket time series for analytics view.
	 *
	 * 24h → 24 hourly buckets, 7d → 7 daily, 30d → 30 daily.
	 * Returns: [{ ts: 'YYYY-MM-DD HH:00', queued: n, running: n, done: n, failed: n }]
	 */
	private function jobs_series( array $nb_ids, $range ) {
		global $wpdb;
		$in = $this->in_clause( $nb_ids );
		if ( $in === '' ) {
			return [];
		}
		$jobs_tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();

		if ( $range === '24h' ) {
			$fmt = '%Y-%m-%d %H:00';
			$interval = '24 HOUR';
			$buckets = 24;
			$step = 'HOUR';
		} elseif ( $range === '7d' ) {
			$fmt = '%Y-%m-%d';
			$interval = '7 DAY';
			$buckets = 7;
			$step = 'DAY';
		} else {
			$fmt = '%Y-%m-%d';
			$interval = '30 DAY';
			$buckets = 30;
			$step = 'DAY';
		}

		$rows = $wpdb->get_results(
			"SELECT DATE_FORMAT(created_at, '{$fmt}') AS ts,
			        SUM(status='queued')  AS queued,
			        SUM(status='running') AS running,
			        SUM(status='done')    AS done,
			        SUM(status='failed')  AS failed
			 FROM {$jobs_tbl}
			 WHERE notebook_id IN ({$in})
			   AND created_at >= UTC_TIMESTAMP() - INTERVAL {$interval}
			 GROUP BY ts
			 ORDER BY ts ASC
			 LIMIT {$buckets}",
			ARRAY_A
		);
		return array_map( static function ( $r ) {
			return [
				'ts'      => (string) $r['ts'],
				'queued'  => (int) $r['queued'],
				'running' => (int) $r['running'],
				'done'    => (int) $r['done'],
				'failed'  => (int) $r['failed'],
			];
		}, $rows ?: [] );
	}

	private function entity_types_distribution( array $nb_ids ) {
		global $wpdb;
		$in = $this->in_clause( $nb_ids );
		if ( $in === '' || ! class_exists( 'BizCity_KG_Database' ) ) {
			return [];
		}
		$tbl  = BizCity_KG_Database::instance()->tbl_entities();
		// Wave B-bis hardening — exclude soft-deleted entities so analytics
		// never surfaces rows the cleanup engine has tombstoned.
		$rows = $wpdb->get_results(
			"SELECT type, COUNT(*) AS c
			 FROM {$tbl}
			 WHERE notebook_id IN ({$in})
			   AND status='approved'
			   AND deleted_at IS NULL
			 GROUP BY type
			 ORDER BY c DESC
			 LIMIT 10",
			ARRAY_A
		);
		return array_map( static function ( $r ) {
			return [ 'type' => (string) $r['type'], 'count' => (int) $r['c'] ];
		}, $rows ?: [] );
	}

	private function top_entities( array $nb_ids, $limit = 5 ) {
		global $wpdb;
		$in = $this->in_clause( $nb_ids );
		if ( $in === '' || ! class_exists( 'BizCity_KG_Database' ) ) {
			return [];
		}
		$tbl   = BizCity_KG_Database::instance()->tbl_entities();
		$limit = max( 1, min( 25, (int) $limit ) );
		// Wave B-bis hardening — exclude soft-deleted entities.
		$rows  = $wpdb->get_results(
			"SELECT id, notebook_id, name, type, weight
			 FROM {$tbl}
			 WHERE notebook_id IN ({$in})
			   AND status='approved'
			   AND deleted_at IS NULL
			 ORDER BY weight DESC, id DESC
			 LIMIT {$limit}",
			ARRAY_A
		);
		return array_map( static function ( $r ) {
			return [
				'id'          => (int) $r['id'],
				'notebook_id' => (int) $r['notebook_id'],
				'name'        => (string) $r['name'],
				'type'        => (string) $r['type'],
				'weight'      => (int) $r['weight'],
			];
		}, $rows ?: [] );
	}

	/** Sweep + cleanup status (Wave A sweep + Wave B cleanup engine). */
	private function cleanup_info() {
		$info = [
			'last_sweep_ts'    => (int) get_option( 'bizcity_twinchat_learning_last_sweep', 0 ),
			'last_sweep_count' => (int) get_option( 'bizcity_twinchat_learning_last_sweep_count', 0 ),
			'last_run'         => null,
			'next_scheduled'   => null,
			'pending_reap'     => [ 'entities' => 0, 'relations' => 0 ],
			'grace_days'       => 30,
		];
		if ( class_exists( 'BizCity_KG_Cleanup_Service' ) ) {
			$cl = BizCity_KG_Cleanup_Service::instance()->get_status();
			$info['last_run']       = $cl['last_run']       ?? null;
			$info['next_scheduled'] = $cl['next_scheduled'] ?? null;
			$info['pending_reap']   = $cl['pending_reap']   ?? $info['pending_reap'];
			$info['grace_days']     = $cl['grace_days']     ?? 30;
		}
		return $info;
	}
}
