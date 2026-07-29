<?php
/**
 * Bizcity TwinChat — Learning Extractor Bridge
 *
 * PHASE-0.7 Wave 0 (2026-04-29) — listens to KG-Hub triplet extractor
 * action hooks and pushes mirrored events into `tc_learning_events` so
 * the existing /learning/stream SSE surface a "đang học triplet" feed
 * even when extraction runs from cron (silent path) instead of an
 * explicit /learning/enqueue job.
 *
 * Decoupling: KG-Hub fires `do_action('bizcity_kg_extraction_*', $args)`
 * with zero dependency on TwinChat. This bridge is the only wire-up.
 *
 * Throttling: per-passage `progress` events are coalesced — at most 1 row
 * per (notebook,second) to avoid flooding tc_learning_events when a cron
 * tick processes 25 passages in <2s. The batch_done event always fires.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Extractor_Bridge {

	/** Throttle window in seconds for per-passage progress events. */
	const PROGRESS_THROTTLE_S = 1;

	/** Track last-emit timestamp per notebook to coalesce bursts. */
	private static $last_progress_ts = [];
	/** Passage -> source cache to avoid repeated lookups in one request. */
	private static $passage_source_cache = [];
	/** Notebook -> active job cache to keep bridge correlation cheap. */
	private static $active_job_cache = [];

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — best-effort source resolution for
	 * passage-scoped bridge events.
	 */
	private static function resolve_source_id( $notebook_id, $args ) {
		$source_id = (int) ( $args['source_id'] ?? 0 );
		if ( $source_id > 0 ) {
			return $source_id;
		}
		$passage_id = (int) ( $args['passage_id'] ?? 0 );
		if ( $passage_id <= 0 || ! class_exists( 'BizCity_KG_Database' ) ) {
			return 0;
		}

		$cache_key = $passage_id . ':' . (int) $notebook_id;
		if ( isset( self::$passage_source_cache[ $cache_key ] ) ) {
			return (int) self::$passage_source_cache[ $cache_key ];
		}

		global $wpdb;
		$tbl = BizCity_KG_Database::instance()->tbl_passages();
		$sid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT source_id FROM {$tbl} WHERE id = %d AND notebook_id = %d LIMIT 1",
			$passage_id,
			(int) $notebook_id
		) );
		self::$passage_source_cache[ $cache_key ] = $sid;
		return $sid;
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — best-effort active job correlation
	 * for notebook-level extractor bridge events.
	 */
	private static function resolve_job_id( $notebook_id, $source_id, $args ) {
		$job_id = (int) ( $args['job_id'] ?? 0 );
		if ( $job_id > 0 ) {
			return $job_id;
		}
		if ( $notebook_id <= 0 || ! class_exists( 'BizCity_TwinChat_Learning_Database' ) ) {
			return 0;
		}

		$cache_key = (int) $notebook_id . ':' . (int) $source_id;
		if ( array_key_exists( $cache_key, self::$active_job_cache ) ) {
			return (int) self::$active_job_cache[ $cache_key ];
		}

		global $wpdb;
		$jobs_tbl = BizCity_TwinChat_Learning_Database::instance()->table_jobs();
		if ( $source_id > 0 ) {
			$job_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$jobs_tbl}
				 WHERE notebook_id = %d
				   AND source_id = %d
				   AND status IN ('queued','running')
				 ORDER BY CASE WHEN status = 'running' THEN 0 ELSE 1 END, id DESC
				 LIMIT 1",
				(int) $notebook_id,
				(int) $source_id
			) );
		}
		if ( $job_id <= 0 ) {
			$job_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$jobs_tbl}
				 WHERE notebook_id = %d
				   AND status IN ('queued','running')
				 ORDER BY CASE WHEN status = 'running' THEN 0 ELSE 1 END, id DESC
				 LIMIT 1",
				(int) $notebook_id
			) );
		}

		self::$active_job_cache[ $cache_key ] = $job_id > 0 ? $job_id : 0;
		return (int) self::$active_job_cache[ $cache_key ];
	}

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — normalized extractor bridge event
	 * context. Unknown identities remain null; bridge never invents ownership.
	 */
	private static function build_context( $args, $phase, $status, $progress = null, $reason = null ) {
		$nb         = (int) ( $args['notebook_id'] ?? 0 );
		$passage_id = (int) ( $args['passage_id'] ?? 0 );
		$source_id  = self::resolve_source_id( $nb, $args );
		$job_id     = self::resolve_job_id( $nb, $source_id, $args );

		return [
			'notebook_id' => $nb,
			'job_id'      => $job_id > 0 ? $job_id : null,
			'source_id'   => $source_id > 0 ? $source_id : null,
			'passage_id'  => $passage_id > 0 ? $passage_id : null,
			'phase'       => (string) $phase,
			'status'      => (string) $status,
			'progress'    => is_numeric( $progress ) ? (float) $progress : null,
			'reason'      => is_string( $reason ) && $reason !== '' ? sanitize_key( $reason ) : null,
		];
	}

	public static function bind() {
		add_action( 'bizcity_kg_extraction_passage_done',  [ __CLASS__, 'on_passage_done'  ], 10, 1 );
		add_action( 'bizcity_kg_extraction_passage_error', [ __CLASS__, 'on_passage_error' ], 10, 1 );
		add_action( 'bizcity_kg_extraction_batch_done',    [ __CLASS__, 'on_batch_done'    ], 10, 1 );
	}

	/**
	 * Per-passage progress (throttled to 1/notebook/sec).
	 *
	 * @param array $args { notebook_id:int, passage_id:int, triplets:int, cache_hit:bool }
	 */
	public static function on_passage_done( $args ) {
		if ( ! is_array( $args ) ) return;
		$nb = (int) ( $args['notebook_id'] ?? 0 );
		if ( $nb <= 0 ) return;
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Events' ) ) return;

		$now = time();
		$last = self::$last_progress_ts[ $nb ] ?? 0;
		if ( ( $now - $last ) < self::PROGRESS_THROTTLE_S ) {
			return; // throttle — batch_done will carry the final tally
		}
		self::$last_progress_ts[ $nb ] = $now;
		$ctx = self::build_context( $args, 'extract', 'processing' );

		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'progress', [
			'phase'      => 'extracting_triplets',
			'status'     => 'processing',
			'job_id'     => $ctx['job_id'],
			'source_id'  => $ctx['source_id'],
			'notebook_id'=> $ctx['notebook_id'],
			'passage_id' => (int) ( $args['passage_id'] ?? 0 ),
			'triplets'   => (int) ( $args['triplets'] ?? 0 ),
			'cache_hit'  => (bool) ( $args['cache_hit'] ?? false ),
			'progress'   => null,
			'reason'     => null,
			'msg'        => sprintf(
				/* translators: %1$d: passage id, %2$d: triplet count */
				__( 'Extract đoạn #%1$d → %2$d triplet', 'bizcity-twin-ai' ),
				(int) ( $args['passage_id'] ?? 0 ),
				(int) ( $args['triplets'] ?? 0 )
			),
		], (int) ( $ctx['job_id'] ?? 0 ) );
	}

	/**
	 * Per-passage error → emit a 'log' row (warn level) so user sees red.
	 *
	 * @param array $args { notebook_id:int, passage_id:int, error:string }
	 */
	public static function on_passage_error( $args ) {
		if ( ! is_array( $args ) ) return;
		$nb = (int) ( $args['notebook_id'] ?? 0 );
		if ( $nb <= 0 ) return;
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Events' ) ) return;
		$reason = isset( $args['reason'] ) ? (string) $args['reason'] : '';
		if ( $reason === '' ) {
			$reason = isset( $args['error_code'] ) ? (string) $args['error_code'] : 'extract_error';
		}
		$ctx = self::build_context( $args, 'extract', 'error', null, $reason );

		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'log', [
			'level'      => 'warn',
			'phase'      => 'extracting_triplets',
			'status'     => 'error',
			'job_id'     => $ctx['job_id'],
			'source_id'  => $ctx['source_id'],
			'notebook_id'=> $ctx['notebook_id'],
			'passage_id' => (int) ( $args['passage_id'] ?? 0 ),
			'reason'     => $ctx['reason'],
			'msg'        => sprintf(
				/* translators: %1$d: passage id, %2$s: error message */
				__( 'Lỗi extract đoạn #%1$d: %2$s', 'bizcity-twin-ai' ),
				(int) ( $args['passage_id'] ?? 0 ),
				(string) ( $args['error'] ?? 'unknown' )
			),
		], (int) ( $ctx['job_id'] ?? 0 ) );
	}

	/**
	 * Batch tick complete (cron or manual) — always fired, never throttled.
	 *
	 * @param array $args { notebook_id, processed, total_triplets, errors,
	 *                      remaining, time_exceeded, elapsed_s }
	 */
	public static function on_batch_done( $args ) {
		if ( ! is_array( $args ) ) return;
		$nb = (int) ( $args['notebook_id'] ?? 0 );
		if ( $nb <= 0 ) return;
		if ( ! class_exists( 'BizCity_TwinChat_Learning_Events' ) ) return;

		// Reset throttle so next batch starts fresh.
		unset( self::$last_progress_ts[ $nb ] );

		$processed = (int) ( $args['processed']      ?? 0 );
		$total     = (int) ( $args['total_triplets'] ?? 0 );
		$remaining = (int) ( $args['remaining']      ?? 0 );
		$errors    = (int) ( $args['errors']         ?? 0 );
		$status    = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		if ( $status === '' ) {
			$status = $errors > 0 ? 'partial' : ( $processed > 0 ? 'ok' : 'idle' );
		}
		$denominator = $processed + $remaining;
		$progress = $denominator > 0 ? ( (float) $processed / (float) $denominator ) : null;
		$reason = '';
		if ( ! empty( $args['throttle_bail'] ) || (int) ( $args['throttled'] ?? 0 ) > 0 ) {
			$reason = 'rate_limited';
		} elseif ( ! empty( $args['time_exceeded'] ) ) {
			$reason = 'time_budget_exceeded';
		}
		$ctx = self::build_context( $args, 'extract', $status, $progress, $reason );

		// 1) Batch progress row — UI consumes for KPI.
		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'progress', [
			'phase'          => 'batch_done',
			'status'         => $status,
			'job_id'         => $ctx['job_id'],
			'source_id'      => $ctx['source_id'],
			'notebook_id'    => $ctx['notebook_id'],
			'progress'       => $ctx['progress'],
			'reason'         => $ctx['reason'],
			'processed'      => $processed,
			'total_triplets' => $total,
			'errors'         => $errors,
			'throttled'      => (int) ( $args['throttled'] ?? 0 ),
			'throttle_bail'  => ! empty( $args['throttle_bail'] ),
			'remaining'      => $remaining,
			'elapsed_s'      => (float) ( $args['elapsed_s'] ?? 0 ),
		], (int) ( $ctx['job_id'] ?? 0 ) );

		// 2) Human-readable log line.
		$msg = sprintf(
			/* translators: %1$d processed, %2$d triplets, %3$d remaining */
			__( '✓ Đã học %1$d đoạn → +%2$d triplet (còn %3$d đoạn chờ)', 'bizcity-twin-ai' ),
			$processed, $total, $remaining
		);
		if ( $errors > 0 ) {
			$msg .= ' · ' . sprintf( __( '%d lỗi', 'bizcity-twin-ai' ), $errors );
		}
		if ( ! empty( $args['time_exceeded'] ) ) {
			$msg .= ' · ' . __( '(hết time-budget, sẽ tiếp tục ở tick sau)', 'bizcity-twin-ai' );
		}

		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'log', [
			'level'       => $errors > 0 ? 'warn' : 'ok',
			'phase'       => 'batch_done',
			'status'      => $status,
			'job_id'      => $ctx['job_id'],
			'source_id'   => $ctx['source_id'],
			'notebook_id' => $ctx['notebook_id'],
			'reason'      => $ctx['reason'],
			'msg'         => $msg,
		], (int) ( $ctx['job_id'] ?? 0 ) );

		// Wave A — bust hub aggregator cache for the notebook owner so the
		// /learning/summary endpoint reflects the new triplet count within 30s.
		if ( class_exists( 'BizCity_TwinChat_Learning_Aggregator' ) && class_exists( 'BizCity_KG_Database' ) ) {
			global $wpdb;
			$nb_tbl   = BizCity_KG_Database::instance()->tbl_notebooks();
			$owner_id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT owner_id FROM {$nb_tbl} WHERE id = %d", $nb
			) );
			if ( $owner_id > 0 ) {
				BizCity_TwinChat_Learning_Aggregator::instance()->bust( $owner_id );
			}
		}
	}
}
