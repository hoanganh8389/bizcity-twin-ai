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

		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'progress', [
			'phase'      => 'extracting_triplets',
			'passage_id' => (int) ( $args['passage_id'] ?? 0 ),
			'triplets'   => (int) ( $args['triplets'] ?? 0 ),
			'cache_hit'  => (bool) ( $args['cache_hit'] ?? false ),
			'msg'        => sprintf(
				/* translators: %1$d: passage id, %2$d: triplet count */
				__( 'Extract đoạn #%1$d → %2$d triplet', 'bizcity-twin-ai' ),
				(int) ( $args['passage_id'] ?? 0 ),
				(int) ( $args['triplets'] ?? 0 )
			),
		] );
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

		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'log', [
			'level'      => 'warn',
			'phase'      => 'extracting_triplets',
			'passage_id' => (int) ( $args['passage_id'] ?? 0 ),
			'msg'        => sprintf(
				/* translators: %1$d: passage id, %2$s: error message */
				__( 'Lỗi extract đoạn #%1$d: %2$s', 'bizcity-twin-ai' ),
				(int) ( $args['passage_id'] ?? 0 ),
				(string) ( $args['error'] ?? 'unknown' )
			),
		] );
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

		// 1) Batch progress row — UI consumes for KPI.
		BizCity_TwinChat_Learning_Events::instance()->push( $nb, 'progress', [
			'phase'          => 'batch_done',
			'processed'      => $processed,
			'total_triplets' => $total,
			'errors'         => $errors,
			'remaining'      => $remaining,
			'elapsed_s'      => (float) ( $args['elapsed_s'] ?? 0 ),
		] );

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
			'level' => $errors > 0 ? 'warn' : 'ok',
			'phase' => 'batch_done',
			'msg'   => $msg,
		] );

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
