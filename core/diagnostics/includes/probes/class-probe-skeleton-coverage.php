<?php
/**
 * BizCity Diagnostics — knowledge.skeleton.coverage probe (Phase 6.6 S1.2).
 *
 * Surfaces the per-notebook skeleton lifecycle so operators can spot
 * regressions in the trigger-now pipeline (R-SK-DOC §15.1) without
 * SSH'ing into the DB.
 *
 * Steps emitted:
 *   1. tables present — bizcity_kg_notebooks + bizcity_kg_skeleton_history.
 *   2. service class loaded — BizCity_KG_Skeleton_Service + Adapter.
 *   3. coverage — % notebooks with status='ready' (FAIL when < threshold).
 *   4. lifecycle breakdown — counts per skeleton_status bucket.
 *   5. history depth — avg history rows per notebook (informational).
 *
 * Threshold default 80% (filterable). With 0 notebooks the probe SKIPs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-24 (Phase 6.6 S1.2)
 * @see        PHASE-6.6-SKELETON-DOC.md  S1.2
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Skeleton_Coverage', false ) ) {
	return;
}

final class BizCity_Probe_Skeleton_Coverage implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'knowledge.skeleton.coverage'; }
	public function label(): string       { return 'KG Notebook Skeleton coverage'; }
	public function description(): string {
		return 'Theo dõi tỷ lệ notebook có skeleton ready + breakdown trạng thái (pending/building/failed/stale).';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 62; }
	public function icon(): string        { return 'layers'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		global $wpdb;

		$tbl_nb   = $wpdb->prefix . 'bizcity_kg_notebooks';
		$tbl_hist = $wpdb->prefix . 'bizcity_kg_skeleton_history';

		// [2026-07-09 Johnny Chu] HOTFIX — R-SHOW-TABLES compliance for diagnostics probes.
		$nb_ok   = $this->table_exists( $tbl_nb );
		$hist_ok = $this->table_exists( $tbl_hist );

		$ctx->emit_step( [
			'label'  => 'bizcity_kg_notebooks',
			'status' => $nb_ok ? 'pass' : 'fail',
			'detail' => $nb_ok ? $tbl_nb : 'missing',
		] );
		$ctx->emit_step( [
			'label'  => 'bizcity_kg_skeleton_history',
			'status' => $hist_ok ? 'pass' : 'warn',
			'detail' => $hist_ok ? $tbl_hist : 'missing — chạy installer kg-hub để tạo (S3.1)',
		] );

		$svc_ok = class_exists( 'BizCity_KG_Skeleton_Service' )
		          && class_exists( 'BizCity_KG_Skeleton_Adapter' );
		$ctx->emit_step( [
			'label'  => 'Skeleton service classes',
			'status' => $svc_ok ? 'pass' : 'fail',
			'detail' => $svc_ok
				? 'BizCity_KG_Skeleton_Service + Adapter loaded'
				: 'service or adapter missing',
		] );

		// Confirm the trigger_now() entry-point exists (Phase 6.6 contract).
		$trigger_ok = $svc_ok && method_exists( 'BizCity_KG_Skeleton_Service', 'trigger_now' );
		$ctx->emit_step( [
			'label'  => 'trigger_now() entry-point',
			'status' => $trigger_ok ? 'pass' : 'fail',
			'detail' => $trigger_ok
				? 'Phase 6.6 trigger-driven pipeline available'
				: 'Service quá cũ — vẫn còn cron debounce (schedule_rebuild)',
		] );

		if ( ! $nb_ok ) {
			return [
				'status'   => 'fail',
				'summary'  => 'kg_notebooks table missing',
				'fix_hint' => 'Mở Diagnostics → Repair Hub và chạy installer kg-hub.',
			];
		}

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_nb}" );
		if ( $total === 0 ) {
			$ctx->emit_step( [
				'label'  => 'Coverage',
				'status' => 'skip',
				'detail' => '0 notebooks — chưa có gì để đo',
			] );
			return [
				'status'  => 'skip',
				'summary' => '0 notebooks — chưa có dữ liệu',
			];
		}

		// Lifecycle breakdown.
		$buckets = $wpdb->get_results(
			"SELECT COALESCE(NULLIF(skeleton_status,''),'(empty)') AS status,
			        COUNT(*) AS n
			   FROM {$tbl_nb}
			  GROUP BY status",
			ARRAY_A
		);
		$counts = [
			'ready' => 0, 'pending' => 0, 'building' => 0,
			'stale' => 0, 'failed'  => 0, '(empty)'  => 0,
		];
		if ( is_array( $buckets ) ) {
			foreach ( $buckets as $b ) {
				$k = (string) $b['status'];
				$counts[ $k ] = (int) $b['n'];
			}
		}

		// [2026-07-09 Johnny Chu] HOTFIX — `(empty)` means notebook chưa vào lifecycle
		// (chưa có source hoặc chưa trigger). Exclude from coverage denominator to
		// avoid false FAIL when active pipeline itself is healthy.
		$active_total = max( 0, $total - $counts['(empty)'] );
		if ( $active_total === 0 ) {
			$ctx->emit_step( [
				'label'  => 'Coverage',
				'status' => 'skip',
				'detail' => sprintf( 'All notebooks are (empty): %d/%d — chưa có lifecycle để đo', $counts['(empty)'], $total ),
			] );
			return [
				'status'  => 'skip',
				'summary' => sprintf( 'All notebooks are empty (%d/%d) — coverage skipped.', $counts['(empty)'], $total ),
			];
		}

		$ready_pct  = round( $counts['ready']  / $active_total * 100, 1 );
		$failed_pct = round( $counts['failed'] / $active_total * 100, 1 );

		// 2026-05-29: pending + building are work-in-progress (backfill cron will
		// resolve them on next 15-min tick). Only `failed` is a genuine alert.
		// Threshold: failed_pct must be ≤ 20% AND ready_pct must reach threshold.
		// For small sites (< 10 notebooks) we relax ready threshold to count
		// pending+building as "in-flight" so 1-2 stuck items don't flip RED.
		$threshold        = (float) apply_filters( 'bizcity_kg_skeleton_coverage_threshold', 80.0 );
		$failed_threshold = (float) apply_filters( 'bizcity_kg_skeleton_failed_threshold', 20.0 );
		$in_flight_pct    = round( ( $counts['ready'] + $counts['pending'] + $counts['building'] ) / $active_total * 100, 1 );
		// Auto-trigger backfill if there's any work pending — non-blocking, no result wait.
		if ( ( $counts['failed'] + $counts['pending'] + $counts['building'] ) > 0
			&& class_exists( 'BizCity_KG_Skeleton_Backfill_Cron' )
			&& method_exists( 'BizCity_KG_Skeleton_Backfill_Cron', 'run' ) ) {
			try {
				BizCity_KG_Skeleton_Backfill_Cron::run();
			} catch ( \Throwable $e ) {
				// noop — surfaced via lifecycle breakdown below.
			}
		}
		$cov_ok = ( $failed_pct <= $failed_threshold ) && ( $in_flight_pct >= $threshold );

		$ctx->emit_step( [
			'label'  => sprintf( 'Coverage ≥ %.0f%% (ready+pending+building), failed ≤ %.0f%%', $threshold, $failed_threshold ),
			'status' => $cov_ok ? 'pass' : 'fail',
			'detail' => sprintf(
				'%d/%d active (%.1f%%) ready · %.1f%%%% in-flight · %.1f%%%% failed · empty=%d/%d excluded',
				$counts['ready'], $active_total, $ready_pct, $in_flight_pct, $failed_pct, $counts['(empty)'], $total
			),
		] );

		$ctx->emit_step( [
			'label'  => 'Lifecycle breakdown',
			'status' => $counts['failed'] > 0 ? 'warn' : 'pass',
			'detail' => sprintf(
				'ready=%d, pending=%d, building=%d, stale=%d, failed=%d, empty=%d',
				$counts['ready'], $counts['pending'], $counts['building'],
				$counts['stale'], $counts['failed'], $counts['(empty)']
			),
		] );

		// Per-notebook details for failed + pending so operator knows which
		// rows to rebuild without SSH'ing into the DB (Phase 6.6 S1.2 follow-up
		// 2026-05-28: prior version only emitted counts → operators saw a red
		// row but had no actionable list).
		$problem_notebooks = [];
		if ( $counts['failed'] > 0 || $counts['pending'] > 0 || $counts['(empty)'] > 0 ) {
			$rows = $wpdb->get_results(
				"SELECT id, name, owner_id,
				        COALESCE(NULLIF(skeleton_status,''),'(empty)') AS s,
				        skeleton_version, skeleton_built_at, updated_at
				   FROM {$tbl_nb}
				  WHERE skeleton_status IN ('failed','pending','building','')
				     OR skeleton_status IS NULL
				  ORDER BY FIELD(skeleton_status,'failed','building','pending','') DESC, updated_at DESC
				  LIMIT 25",
				ARRAY_A
			);
			if ( is_array( $rows ) ) {
				foreach ( $rows as $r ) {
					$problem_notebooks[] = sprintf(
						'#%d [%s] %s (owner=%d, v=%s, built=%s)',
						(int) $r['id'],
						(string) $r['s'],
						(string) ( $r['name'] ?: '(unnamed)' ),
						(int) $r['owner_id'],
						$r['skeleton_version'] !== null ? (string) $r['skeleton_version'] : '-',
						$r['skeleton_built_at'] ?: '-'
					);
				}
			}
		}
		if ( ! empty( $problem_notebooks ) ) {
			$ctx->emit_step( [
				'label'  => 'Notebooks cần rebuild',
				'status' => $counts['failed'] > 0 ? 'fail' : 'warn',
				'detail' => implode( "\n", $problem_notebooks ),
			] );
		}

		// [2026-06-04 Johnny Chu] SKEL-FAIL-REASON — surface per-notebook fail reasons
		// for every failed notebook so operator knows exact root cause without log access.
		if ( $counts['failed'] > 0
			&& class_exists( 'BizCity_KG_Skeleton_Service' )
			&& defined( 'BizCity_KG_Skeleton_Service::FAIL_OPT_PREFIX' ) ) {

			$fail_lines = [];
			$failed_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name FROM {$tbl_nb}
					  WHERE skeleton_status = 'failed'
					  ORDER BY updated_at DESC
					  LIMIT 15"
				),
				ARRAY_A
			);
			if ( is_array( $failed_rows ) ) {
				foreach ( $failed_rows as $r ) {
					$opt = get_option( BizCity_KG_Skeleton_Service::FAIL_OPT_PREFIX . (int) $r['id'] );
					if ( $opt ) {
						$d = json_decode( (string) $opt, true );
						$reason = is_array( $d ) ? ( $d['reason'] ?? 'unknown' ) : 'unknown';
						$detail = is_array( $d ) ? ( $d['detail'] ?? '' ) : '';
						$ts     = is_array( $d ) ? ( $d['ts']     ?? '' ) : '';
						$line   = sprintf( '#%d [%s] reason=%s ts=%s',
							(int) $r['id'], (string) ( $r['name'] ?: '(unnamed)' ),
							$reason, $ts
						);
						if ( $detail !== '' ) {
							$line .= "\n    → " . mb_substr( $detail, 0, 150 );
						}
					} else {
						// No option stored yet — legacy failure before SKEL-FAIL-REASON.
						global $wpdb;
						$tbl_pas = BizCity_KG_Database::instance()->tbl_passages();
						$pc = (int) $wpdb->get_var(
							$wpdb->prepare( "SELECT COUNT(*) FROM {$tbl_pas} WHERE notebook_id = %d", (int) $r['id'] )
						);
						$line = sprintf( '#%d [%s] reason=unknown (passages_count=%d — bấm Rebuild để ghi lại reason)',
							(int) $r['id'], (string) ( $r['name'] ?: '(unnamed)' ), $pc
						);
					}
					$fail_lines[] = $line;
				}
			}
			if ( ! empty( $fail_lines ) ) {
				$ctx->emit_step( [
					'label'  => 'Fail reasons per notebook',
					'status' => 'fail',
					'detail' => implode( "\n\n", $fail_lines ),
				] );
			}
		}

		// [2026-06-03 Johnny Chu] CONSOLIDATION-M1 — slug `bizcity-kg-skeleton-diag`
		// đã retire submenu (probe `kg.skeleton`). Surface CLI hint thay vì admin URL chết.
		$ctx->emit_step( [
			'label'  => 'Rebuild hint',
			'status' => 'info',
			'detail' => 'CLI: `wp bizcity diag skeleton-rebuild --stuck=1` (hoặc `--notebook=<id>`) để re-queue qua Action Scheduler.',
		] );

		// History depth — informational only.
		if ( $hist_ok ) {
			$hist_total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_hist}" );
			$hist_per_nb  = $total > 0 ? round( $hist_total / $total, 2 ) : 0.0;
			$ctx->emit_step( [
				'label'  => 'History depth',
				'status' => 'pass',
				'detail' => sprintf(
					'%d rows total, avg %.2f versions/notebook',
					$hist_total, $hist_per_nb
				),
			] );
		}

		if ( ! $cov_ok ) {
			// [2026-06-03 Johnny Chu] CONSOLIDATION-M1 — bỏ URL chết, giữ CLI hint.
			$hint = 'Backfill cron đã được trigger trong probe — chờ ~30s rồi Run lại. '
			        . 'Hoặc chạy `wp bizcity kg skeleton-backfill --status=pending,failed` hoặc '
			        . '`wp bizcity diag skeleton-rebuild --stuck=1`.';
			return [
				'status'   => 'fail',
				'summary'  => sprintf(
					'Skeleton failed_pct=%.1f%% (> %.0f%%) hoặc in-flight=%.1f%% (< %.0f%%) — %d failed, %d pending (active=%d, empty=%d)',
					$failed_pct, $failed_threshold, $in_flight_pct, $threshold,
					$counts['failed'], $counts['pending'], $active_total, $counts['(empty)']
				),
				'error'    => 'low_coverage',
				'fix_hint' => $hint,
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Skeleton OK — %.1f%% ready (%d/%d active), %d failed (empty=%d/%d excluded)',
				$ready_pct, $counts['ready'], $active_total, $counts['failed'], $counts['(empty)'], $total
			),
		];
	}

	private function table_exists( string $table_name ): bool {
		global $wpdb;
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
	}

	public function cleanup(): void {
		// Read-only.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Skeleton_Coverage';
	return $list;
} );
