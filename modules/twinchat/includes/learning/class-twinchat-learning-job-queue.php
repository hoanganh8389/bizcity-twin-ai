<?php
/**
 * Bizcity TwinChat — Learning Job Queue
 *
 * Phase 4.9 — durable per-notebook learning queue.
 *
 *   1) enqueue() inserts a tc_learning_jobs row (status=queued)
 *      and schedules a background action `bizcity_twinchat_learning_run`
 *      with the new job id.
 *   2) Action Scheduler (preferred) → group `bizcity_twinchat_learning_$nb`
 *      enforces serial execution per notebook so multiple sources uploaded
 *      back-to-back don't collide on the triplet queue.
 *   3) Fallback (no AS): wp-cron single event + non-blocking POST to wp-cron.php
 *      to wake the worker immediately.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Job_Queue {

	const HOOK_RUN = 'bizcity_twinchat_learning_run';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Insert + schedule.
	 *
	 * @param array $args { notebook_id (int, required), source_id (int), source_title (string), user_id (int) }
	 * @return int|WP_Error new job id
	 */
	public function enqueue( array $args ) {
		global $wpdb;
		$notebook_id  = (int) ( $args['notebook_id'] ?? 0 );
		$source_id    = isset( $args['source_id'] ) ? (int) $args['source_id'] : 0;
		$source_title = isset( $args['source_title'] ) ? substr( (string) $args['source_title'], 0, 250 ) : '';
		$user_id      = (int) ( $args['user_id'] ?? get_current_user_id() );
		// Wave A — track who/what enqueued the job (user|sweep|backfill|api).
		$origin       = isset( $args['origin'] ) ? sanitize_key( (string) $args['origin'] ) : 'user';
		if ( $origin === '' ) { $origin = 'user'; }

		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id required' );
		}

		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();

		// ── Per-notebook coalescing (idempotent enqueue) ────────────────
		// Bug fix 2026-05-04: REST `/learning/enqueue` and the
		// `bizcity_twinchat_after_ingest` auto-enqueue used to insert a NEW
		// job for every source attached. Result: 5 sources uploaded back-to-
		// back created 5 RUNNING jobs on the same notebook. Each tick:
		//   - all 5 jobs grab the same 'pending' passage IDs (race in Step 4)
		//   - only one extracts; the other 4 get extraction_status='done' early
		//     and increment passages_processed by 1 → fake counter movement
		//     defeats the loopback_dead heuristic
		// Plus 5× LLM quota burn on duplicate calls when the race actually
		// double-dispatches.
		//
		// Fix: if an active job (queued|running, phase NOT in 'approving'/'done')
		// already covers this notebook, return its ID instead. The active
		// tick_extract reads ALL pending passages of the notebook so newly-
		// ingested source passages will be picked up automatically.
		// `approving` phase is allowed to coexist with new `extracting` jobs
		// because approve runs separately on already-extracted triplets.
		$dedupe = (bool) apply_filters( 'bizcity_twinchat_learning_enqueue_dedupe', true, $notebook_id, $args );
		if ( $dedupe ) {
			$existing = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$tbl}
				 WHERE notebook_id = %d
				   AND status IN ('queued','running')
				   AND ( phase IS NULL OR phase IN ('queued','extracting') )
				 ORDER BY id ASC LIMIT 1",
				$notebook_id
			) );
			if ( $existing > 0 ) {
				// Touch updated_at + announce coverage so FE keeps streaming.
				BizCity_TwinChat_Learning_Events::instance()->push( $notebook_id, 'log', [
					'level' => 'info',
					'msg'   => sprintf(
						'[enqueue] Notebook #%d đã có job #%d đang chạy — gộp source #%d (%s) vào job hiện tại.',
						$notebook_id, $existing, $source_id, $source_title !== '' ? $source_title : '(no-title)'
					),
				], $existing );
				// Make sure the worker is awake to pick up the new passages.
				$this->schedule( $existing, $notebook_id );
				return $existing;
			}
		}

		$ok = $wpdb->insert( $tbl, [
			'notebook_id'  => $notebook_id,
			'source_id'    => $source_id > 0 ? $source_id : null,
			'origin'       => $origin,
			'source_title' => $source_title,
			'user_id'      => $user_id,
			'status'       => 'queued',
			'created_at'   => current_time( 'mysql', true ),
		] );
		if ( ! $ok ) {
			return new WP_Error( 'insert_failed', $wpdb->last_error ?: 'Failed to insert learning job' );
		}
		$job_id = (int) $wpdb->insert_id;

		// Announce queued (also drives FE foreground tick driver via SSE).
		BizCity_TwinChat_Learning_Events::instance()->push( $notebook_id, 'job', [
			'job_id'       => $job_id,
			'status'       => 'queued',
			'phase'        => 'queued',
			'source_id'    => $source_id,
			'source_title' => $source_title,
		], $job_id );

		$this->schedule( $job_id, $notebook_id );

		return $job_id;
	}

	/** Schedule the worker hook. Prefer Action Scheduler when available. */
	protected function schedule( $job_id, $notebook_id ) {
		$args  = [ (int) $job_id ];
		$group = 'bizcity_twinchat_learning_' . (int) $notebook_id;

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_RUN, $args, $group );
			return;
		}
		// Fallback: wp-cron single event + spawn cron now (best-effort).
		if ( ! wp_next_scheduled( self::HOOK_RUN, $args ) ) {
			wp_schedule_single_event( time(), self::HOOK_RUN, $args );
		}
		$this->spawn_cron();
	}

	/** Best-effort non-blocking ping to wp-cron.php to fire the scheduled event right away. */
	protected function spawn_cron() {
		$url = site_url( 'wp-cron.php?doing_wp_cron=' . microtime( true ) );
		wp_remote_post( $url, [
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
		] );
	}

	// ── CRUD ─────────────────────────────────────────────────────────────

	public function get_job( $job_id ) {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tbl} WHERE id=%d", (int) $job_id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function list_jobs( $notebook_id, array $args = [] ) {
		global $wpdb;
		$tbl       = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$limit     = max( 1, min( 200, (int) ( $args['limit'] ?? 50 ) ) );
		$statuses  = isset( $args['statuses'] ) && is_array( $args['statuses'] ) ? array_filter( array_map( 'sanitize_key', $args['statuses'] ) ) : [];
		$where     = [ 'notebook_id=%d' ];
		$wargs     = [ (int) $notebook_id ];
		if ( ! empty( $statuses ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
			$where[]      = "status IN ({$placeholders})";
			$wargs        = array_merge( $wargs, $statuses );
		}
		$wargs[] = $limit;
		$rows    = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl} WHERE " . implode( ' AND ', $where ) . " ORDER BY id DESC LIMIT %d",
			$wargs
		), ARRAY_A );
		return array_map( [ $this, 'hydrate' ], (array) $rows );
	}

	public function update( $job_id, array $fields ) {
		global $wpdb;
		$tbl   = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$allow = [ 'status', 'phase', 'progress', 'passages_processed', 'triplets_extracted', 'entities_approved', 'batches_total', 'batches_done', 'entity_ids', 'error', 'started_at', 'finished_at', 'lease_owner', 'lease_until' ];
		$row   = [];
		foreach ( $allow as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$row[ $k ] = $fields[ $k ];
			}
		}
		if ( empty( $row ) ) {
			return 0;
		}
		if ( isset( $row['entity_ids'] ) && is_array( $row['entity_ids'] ) ) {
			$row['entity_ids'] = wp_json_encode( array_values( $row['entity_ids'] ) );
		}
		return (int) $wpdb->update( $tbl, $row, [ 'id' => (int) $job_id ] );
	}

	/**
	 * Atomically acquire the worker lease on a job.
	 * Multiple lanes (cron + ajax tick) call this concurrently — only one
	 * UPDATE wins per (lease_until expired) window.
	 *
	 * @param int    $job_id
	 * @param string $owner   eg. 'cron' or 'ajax-<user_id>'
	 * @param int    $ttl_s   how long the lease is valid (default 30s)
	 * @return bool true if we now hold the lease, false if someone else does
	 */
	public function acquire_lease( $job_id, $owner, $ttl_s = 30 ) {
		global $wpdb;
		$tbl   = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 5, (int) $ttl_s ) );

		// Steal lease only when (no holder) OR (existing lease expired) OR (we already hold it).
		$rows = (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl}
			   SET lease_owner=%s, lease_until=%s
			 WHERE id=%d
			   AND status NOT IN ('done','failed','cancelled')
			   AND ( lease_until IS NULL OR lease_until < %s OR lease_owner=%s )",
			$owner, $until, (int) $job_id, $now, $owner
		) );
		return $rows > 0;
	}

	/** Extend the lease (call between batches). */
	public function extend_lease( $job_id, $owner, $ttl_s = 30 ) {
		global $wpdb;
		$tbl   = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$until = gmdate( 'Y-m-d H:i:s', time() + max( 5, (int) $ttl_s ) );
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET lease_until=%s WHERE id=%d AND lease_owner=%s",
			$until, (int) $job_id, $owner
		) );
	}

	/** Release the lease so another worker can pick the job up. */
	public function release_lease( $job_id, $owner ) {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		return (int) $wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET lease_owner=NULL, lease_until=NULL WHERE id=%d AND lease_owner=%s",
			(int) $job_id, $owner
		) );
	}

	// ── Batch ledger ─────────────────────────────────────────────────────

	public function start_batch( $job_id, $notebook_id, $batch_no, $phase, $owner ) {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_batches();
		$wpdb->insert( $tbl, [
			'job_id'      => (int) $job_id,
			'notebook_id' => (int) $notebook_id,
			'batch_no'    => (int) $batch_no,
			'phase'       => (string) $phase,
			'status'      => 'running',
			'owner'       => (string) $owner,
			'started_at'  => current_time( 'mysql', true ),
		] );
		return (int) $wpdb->insert_id;
	}

	public function finish_batch( $batch_id, array $stats, $error = null ) {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_batches();
		$wpdb->update( $tbl, [
			'status'         => $error === null ? 'done' : 'failed',
			'passages_count' => (int) ( $stats['passages_count'] ?? 0 ),
			'triplets_count' => (int) ( $stats['triplets_count'] ?? 0 ),
			'errors_count'   => (int) ( $stats['errors_count']   ?? 0 ),
			'finished_at'    => current_time( 'mysql', true ),
			'error'          => $error !== null ? substr( (string) $error, 0, 1000 ) : null,
		], [ 'id' => (int) $batch_id ] );
	}

	public function next_batch_no( $job_id ) {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_batches();
		$max = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(batch_no) FROM {$tbl} WHERE job_id=%d",
			(int) $job_id
		) );
		return $max + 1;
	}

	public function cancel( $job_id ) {
		$job = $this->get_job( $job_id );
		if ( ! $job ) {
			return new WP_Error( 'not_found', 'Job not found' );
		}
		if ( in_array( $job['status'], [ 'done', 'failed', 'cancelled' ], true ) ) {
			return $job;
		}
		$this->update( $job_id, [
			'status'      => 'cancelled',
			'finished_at' => current_time( 'mysql', true ),
		] );
		BizCity_TwinChat_Learning_Events::instance()->push(
			(int) $job['notebook_id'],
			'job',
			[ 'job_id' => (int) $job_id, 'status' => 'cancelled' ],
			(int) $job_id
		);
		return $this->get_job( $job_id );
	}

	protected function hydrate( array $row ) {
		$row['id']                 = (int) $row['id'];
		$row['notebook_id']        = (int) $row['notebook_id'];
		$row['source_id']          = isset( $row['source_id'] ) ? (int) $row['source_id'] : 0;
		$row['user_id']            = (int) $row['user_id'];
		$row['progress']           = (int) $row['progress'];
		$row['passages_processed'] = (int) $row['passages_processed'];
		$row['triplets_extracted'] = (int) $row['triplets_extracted'];
		$row['entities_approved']  = (int) $row['entities_approved'];
		$row['phase']              = isset( $row['phase'] ) ? (string) $row['phase'] : 'queued';
		$row['batches_total']      = isset( $row['batches_total'] ) ? (int) $row['batches_total'] : 0;
		$row['batches_done']       = isset( $row['batches_done'] )  ? (int) $row['batches_done']  : 0;
		if ( ! empty( $row['entity_ids'] ) ) {
			$dec                = json_decode( $row['entity_ids'], true );
			$row['entity_ids']  = is_array( $dec ) ? array_values( array_map( 'intval', $dec ) ) : [];
		} else {
			$row['entity_ids'] = [];
		}
		return $row;
	}
}
