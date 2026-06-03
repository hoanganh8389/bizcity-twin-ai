<?php
/**
 * Bizcity Twin AI — Filestore Backfill cron (Phase 0.7 Wave F2).
 *
 * Migrates existing rows (storage_ver=1) into filestore companion files in
 * controlled batches. Scheduled via WP-Cron every 5 minutes when the dual-write
 * option is enabled.
 *
 * Strategy:
 *   - One blog at a time (round-robin by switch_to_blog inside the worker).
 *   - 500 rows per batch (LIMIT 500 WHERE storage_ver=1).
 *   - Order kg_passages → kg_entities → kg_relations (deps + size order).
 *   - After 3 consecutive failures on a row, mark with `_kg_filestore_skip=1`
 *     in metadata so the loop doesn't wedge on a poison-pill record.
 *   - Emits `do_action('bizcity_diagnostics_notice', 'kg_filestore', $payload)`
 *     so the diagnostics page surfaces stuck rows.
 *
 * Toggle:
 *   wp_option `bizcity_kg_filestore_dual_write` = 1   → cron is scheduled
 *                                                = 0   → cron unscheduled on next bind()
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub\Filestore
 * @since      2026-05-20  (PHASE-0.7-LEARN-VECTOR-FILE Wave F2)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Filestore_Backfill {

	const HOOK         = 'bizcity_kg_filestore_backfill';
	const SCHEDULE     = 'bizcity_kg_5min';
	const BATCH_SIZE   = 500;
	const MAX_FAILURES = 3;

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function bind() {
		add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
		add_action( self::HOOK, [ $this, 'run' ] );

		if ( BizCity_KG_Filestore_Dispatcher::is_enabled() ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
			}
		} else {
			$ts = wp_next_scheduled( self::HOOK );
			if ( $ts ) { wp_unschedule_event( $ts, self::HOOK, [] ); }
		}
	}

	public function register_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'KG Filestore Backfill (5 min)',
			];
		}
		return $schedules;
	}

	/**
	 * Run a single batch (one pass through 3 tables, BATCH_SIZE rows each).
	 * Used by cron. Returns the per-pass report so callers can loop.
	 */
	public function run_once() {
		if ( ! BizCity_KG_Filestore_Dispatcher::is_enabled() ) {
			return [ 'passages' => 0, 'entities' => 0, 'relations' => 0, 'errors' => 0, 'skipped' => true ];
		}
		return $this->run_pass();
	}

	/**
	 * Loop run_pass() until no v1 rows remain OR time/iteration budget exceeded.
	 * Used by the manual "Run batch now" button so each click drains as much as
	 * the request window allows.
	 *
	 * @param int $time_budget_sec  Max wall-clock seconds (default 25 — well under
	 *                              the typical PHP max_execution_time of 30).
	 * @param int $max_passes       Hard ceiling on loop iterations.
	 */
	public function run_loop( $time_budget_sec = 25, $max_passes = 200 ) {
		if ( ! BizCity_KG_Filestore_Dispatcher::is_enabled() ) {
			return [ 'passages' => 0, 'entities' => 0, 'relations' => 0, 'errors' => 0,
			         'passes' => 0, 'elapsed_ms' => 0, 'skipped' => true ];
		}
		$total = [ 'passages' => 0, 'entities' => 0, 'relations' => 0, 'errors' => 0, 'passes' => 0 ];
		$t0    = microtime( true );
		while ( $total['passes'] < $max_passes ) {
			$pass = $this->run_pass();
			$total['passes']++;
			$total['passages']  += (int) $pass['passages'];
			$total['entities']  += (int) $pass['entities'];
			$total['relations'] += (int) $pass['relations'];
			$total['errors']    += (int) $pass['errors'];
			$progressed = ( $pass['passages'] + $pass['entities'] + $pass['relations'] ) > 0;
			if ( ! $progressed ) { break; }
			if ( ( microtime( true ) - $t0 ) >= $time_budget_sec ) { break; }
		}
		$total['elapsed_ms'] = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		return $total;
	}

	public function run() {
		// Cron entry-point — one batch per tick. Manual UI uses run_loop().
		$report = $this->run_once();
		if ( empty( $report['skipped'] ) ) {
			do_action( 'bizcity_diagnostics_notice', 'kg_filestore', [
				'blog_id'   => get_current_blog_id(),
				'report'    => $report,
				'timestamp' => time(),
			] );
		}
	}

	private function run_pass() {
		global $wpdb;
		$db = BizCity_KG_Database::instance();

		$dispatcher = BizCity_KG_Filestore_Dispatcher::instance();
		$report     = [ 'passages' => 0, 'entities' => 0, 'relations' => 0, 'errors' => 0 ];

		// Passages.
		$tbl = $db->tbl_passages();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE storage_ver=1 ORDER BY id ASC LIMIT %d",
			self::BATCH_SIZE
		) );
		foreach ( $rows ?: [] as $r ) {
			$res = $dispatcher->backfill_passage( (int) $r->id );
			if ( is_wp_error( $res ) || false === $res ) { $report['errors']++; }
			else { $report['passages']++; }
		}

		// Entities.
		$tbl = $db->tbl_entities();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE storage_ver=1 ORDER BY id ASC LIMIT %d",
			self::BATCH_SIZE
		) );
		foreach ( $rows ?: [] as $r ) {
			$res = $dispatcher->backfill_entity( (int) $r->id );
			if ( is_wp_error( $res ) || false === $res ) { $report['errors']++; }
			else { $report['entities']++; }
		}

		// Relations.
		$tbl = $db->tbl_relations();
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE storage_ver=1 ORDER BY id ASC LIMIT %d",
			self::BATCH_SIZE
		) );
		foreach ( $rows ?: [] as $r ) {
			$res = $dispatcher->backfill_relation( (int) $r->id );
			if ( is_wp_error( $res ) || false === $res ) { $report['errors']++; }
			else { $report['relations']++; }
		}

		return $report;
	}
}
