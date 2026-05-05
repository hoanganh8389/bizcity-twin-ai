<?php
/**
 * Bizcity TwinChat — Learning SSE Stream Controller
 *
 * Phase 4.9 — long-poll SSE handler that streams `tc_learning_events` rows
 * to the browser. Uses the standard pattern proven in twinchat-stream-handler:
 *   - set_time_limit(0); ignore_user_abort(false); ob_end_flush();
 *   - 500 ms poll interval, 15 s heartbeat, 90 s connection cap
 *   - X-Accel-Buffering: no   to defeat nginx/CDN buffering
 *   - retry: 5000             so EventSource reconnects after Cloudflare cuts
 *
 * Client side uses native `EventSource` which auto-reconnects with the
 * `Last-Event-ID` header — we honour it to resume the cursor.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Stream {

	const POLL_USEC      = 500000;     // 0.5 s
	const HEARTBEAT_S    = 15;
	// Lowered 90→25 s on 2026-05-04 — each open SSE pins one PHP-FPM worker.
	// With parallel learning workers added, 90 s hold + 5 worker requests
	// saturated the pool behind Cloudflare → 522/500 on new requests.
	// EventSource auto-reconnects with Last-Event-ID, so cycling fast is safe.
	const MAX_DURATION_S = 25;
	const STALE_AFTER_S  = 30;         // emit `event: stale` if no row for this long while a job is `running`

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * SSE endpoint handler.
	 *
	 * @param WP_REST_Request $req
	 */
	public function handle( WP_REST_Request $req ) {
		$nb = (int) $req->get_param( 'notebook_id' );
		if ( $nb <= 0 ) {
			return new WP_Error( 'invalid_notebook', 'notebook_id required', [ 'status' => 400 ] );
		}

		// Resolve cursor: Last-Event-ID header (auto-reconnect) > ?since= > latest.
		$last_event_id = (int) ( $_SERVER['HTTP_LAST_EVENT_ID'] ?? 0 );
		$since         = (int) $req->get_param( 'since' );
		$cursor        = $last_event_id > 0 ? $last_event_id : $since;
		if ( $cursor <= 0 ) {
			$cursor = BizCity_TwinChat_Learning_Events::instance()->latest_id( $nb );
		}

		// SSE headers.
		nocache_headers();
		header( 'Content-Type: text/event-stream; charset=utf-8' );
		header( 'X-Accel-Buffering: no' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Connection: keep-alive' );

		@set_time_limit( 0 );
		@ignore_user_abort( false );
		while ( ob_get_level() > 0 ) {
			@ob_end_flush();
		}

		// Reconnect interval for the browser (5 s).
		echo "retry: 5000\n\n";
		@flush();

		$start         = time();
		$last_beat     = $start;
		$last_event    = $start;   // wall-clock of last data row pushed
		$stale_emitted = false;    // only emit `stale` once per connection
		$events        = BizCity_TwinChat_Learning_Events::instance();

		// 2026-04-29 fix — kh\u1eafc ph\u1ee5c "loop v\u00f4 h\u1ea1n m\u00e0 response tr\u1eafng tinh".
		// N\u1ebfu kh\u00f4ng c\u00f2n job n\u00e0o queued/running tr\u00ean DB v\u00e0 cursor \u0111\u00e3 b\u1eaft k\u1ecbp event m\u1edbi nh\u1ea5t,
		// emit `event: idle` r\u1ed3i \u0111\u00f3ng connection. FE l\u1eafng nghe `idle` \u0111\u1ec3 close()
		// EventSource th\u00f4i auto-reconnect \u2014 ti\u1ebft ki\u1ec7m worker + xo\u00e1 spam log.
		if ( $this->no_active_jobs( $nb ) && $cursor >= $events->latest_id( $nb ) ) {
			echo "event: idle\n";
			echo 'data: ' . wp_json_encode( [ 'notebook_id' => $nb, 'reason' => 'no_pending_jobs' ] ) . "\n\n";
			@flush();
			exit;
		}

		while ( true ) {
			if ( connection_aborted() ) {
				break;
			}
			if ( ( time() - $start ) >= self::MAX_DURATION_S ) {
				// Clean exit — client EventSource auto-reconnects with Last-Event-ID.
				break;
			}

			$rows = $events->read_since( $nb, $cursor, 100 );
			foreach ( $rows as $row ) {
				$cursor = (int) $row['id'];
				$this->send_event( $row );
				$last_beat  = time();
				$last_event = time();
			}

			// 2026-04-30 — Stale-job watchdog. If a job is `running` but emitted
			// nothing for STALE_AFTER_S, ship a `stale` event with details so the
			// FE can show actionable info (job_id / started_at / minutes silent)
			// instead of silently reconnecting forever.
			if ( ! $stale_emitted && ( time() - $last_event ) >= self::STALE_AFTER_S ) {
				$stale_jobs = $this->find_stale_running_jobs( $nb, self::STALE_AFTER_S );
				if ( ! empty( $stale_jobs ) ) {
					echo "event: stale\n";
					echo 'data: ' . wp_json_encode( [
						'notebook_id'   => $nb,
						'silent_for_s'  => time() - $last_event,
						'jobs'          => $stale_jobs,
						'reason'        => 'no_event_from_running_job',
						'suggestion'    => 'Cancel job or wait — worker may be blocked.',
					] ) . "\n\n";
					@flush();
					$stale_emitted = true;
					// Exit so the browser stops auto-reconnecting; FE chooses what to do next.
					exit;
				} else {
					// No `running` learning job either. Before declaring the
					// queue drained, give the welcome lane a chance — its LLM
					// call can run for 5–15 s after learning finishes and
					// closing the SSE here would drop the welcome `chat`
					// event on the floor (Sprint 5.1).
					if ( ! $this->no_active_jobs( $nb ) ) {
						$last_event = time(); // reset watchdog, keep listening.
					} else {
						echo "event: idle\n";
						echo 'data: ' . wp_json_encode( [ 'notebook_id' => $nb, 'reason' => 'queue_drained' ] ) . "\n\n";
						@flush();
						exit;
					}
				}
			}

			if ( ( time() - $last_beat ) >= self::HEARTBEAT_S ) {
				echo ": ping " . time() . "\n\n";
				@flush();
				$last_beat = time();
			}

			usleep( self::POLL_USEC );
		}

		exit; // stop WP from appending body
	}

	protected function send_event( array $row ) {
		$json = wp_json_encode( [
			'id'      => $row['id'],
			'job_id'  => $row['job_id'],
			'ts'      => $row['ts'],
			'payload' => $row['payload'],
		] );
		// SSE frame.
		echo 'id: ' . (int) $row['id'] . "\n";
		echo 'event: ' . preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $row['event'] ) . "\n";
		echo 'data: ' . $json . "\n\n";
		@flush();
	}

	/**
	 * Quick check: are there any queued/running learning jobs for this notebook?
	 * Used by `handle()` to short-circuit the long-poll when the queue is empty.
	 *
	 * 2026-05-01 — also count welcome jobs (Sprint 5.1). Welcome runs LLM after
	 * learning, so if we only checked learning we'd close the SSE before the
	 * welcome bubble has been pushed onto the ring buffer.
	 */
	protected function no_active_jobs( int $notebook_id ): bool {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$cnt = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$tbl}
			  WHERE notebook_id = %d AND status IN ('queued','running')",
			$notebook_id
		) );
		if ( $cnt > 0 ) {
			return false;
		}
		if ( class_exists( 'BizCity_TwinChat_Welcome_Database' ) ) {
			$wtbl = BizCity_TwinChat_Welcome_Database::instance()->table_jobs();
			// Suppress errors in case the welcome table hasn't been installed yet.
			$prev = $wpdb->suppress_errors( true );
			$wcnt = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wtbl}
				  WHERE notebook_id = %d AND status IN ('queued','running')",
				$notebook_id
			) );
			$wpdb->suppress_errors( $prev );
			if ( $wcnt > 0 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Return jobs that are still marked `running` but have not reported any
	 * progress in the last `$silent_for_s` seconds (looking at `started_at`
	 * as a coarse proxy when no `last_event_ts` column exists).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function find_stale_running_jobs( int $notebook_id, int $silent_for_s ): array {
		global $wpdb;
		$tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $silent_for_s );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source_id, status, started_at, created_at
			   FROM {$tbl}
			  WHERE notebook_id = %d
			    AND status IN ('queued','running')
			    AND ( started_at IS NULL OR started_at < %s )
			  ORDER BY id ASC
			  LIMIT 10",
			$notebook_id,
			$cutoff
		), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}
		$out = [];
		foreach ( $rows as $r ) {
			$started = ! empty( $r['started_at'] ) ? strtotime( $r['started_at'] . ' UTC' ) : 0;
			$out[] = [
				'job_id'      => (int) $r['id'],
				'source_id'   => (int) $r['source_id'],
				'status'      => (string) $r['status'],
				'started_at'  => (string) $r['started_at'],
				'silent_min'  => $started > 0 ? (int) floor( ( time() - $started ) / 60 ) : null,
			];
		}
		return $out;
	}
}
