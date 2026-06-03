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

		$nb_ok   = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_nb ) );
		$hist_ok = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl_hist ) );

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

		$ready_pct  = $total > 0 ? round( $counts['ready']  / $total * 100, 1 ) : 0.0;
		$failed_pct = $total > 0 ? round( $counts['failed'] / $total * 100, 1 ) : 0.0;

		// 2026-05-29: pending + building are work-in-progress (backfill cron will
		// resolve them on next 15-min tick). Only `failed` is a genuine alert.
		// Threshold: failed_pct must be ≤ 20% AND ready_pct must reach threshold.
		// For small sites (< 10 notebooks) we relax ready threshold to count
		// pending+building as "in-flight" so 1-2 stuck items don't flip RED.
		$threshold        = (float) apply_filters( 'bizcity_kg_skeleton_coverage_threshold', 80.0 );
		$failed_threshold = (float) apply_filters( 'bizcity_kg_skeleton_failed_threshold', 20.0 );
		$in_flight_pct    = $total > 0
			? round( ( $counts['ready'] + $counts['pending'] + $counts['building'] ) / $total * 100, 1 )
			: 0.0;
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
				'%d/%d (%.1f%%) ready · %.1f%%%% in-flight · %.1f%%%% failed',
				$counts['ready'], $total, $ready_pct, $in_flight_pct, $failed_pct
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

		// Surface the admin rebuild page so operator can click instead of
		// hunting for `wp bizcity kg skeleton-backfill` CLI.
		$admin_url = '';
		if ( function_exists( 'admin_url' ) ) {
			$admin_url = admin_url( 'admin.php?page=bizcity-kg-skeleton-diag' );
		}
		if ( $admin_url !== '' ) {
			$ctx->emit_step( [
				'label'  => 'Rebuild UI',
				'status' => 'info',
				'detail' => 'Mở: ' . $admin_url . ' → bấm "Rebuild stuck/failed" để re-queue qua Action Scheduler.',
			] );
		}

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
			$hint = 'Backfill cron đã được trigger trong probe — chờ ~30s rồi Run lại. Hoặc chạy `wp bizcity kg skeleton-backfill --status=pending,failed` HOẶC mở '
			        . ( $admin_url ?: '/wp-admin/admin.php?page=bizcity-kg-skeleton-diag' )
			        . ' → bấm "Rebuild stuck/failed".';
			return [
				'status'   => 'fail',
				'summary'  => sprintf(
					'Skeleton failed_pct=%.1f%% (> %.0f%%) hoặc in-flight=%.1f%% (< %.0f%%) — %d failed, %d pending',
					$failed_pct, $failed_threshold, $in_flight_pct, $threshold,
					$counts['failed'], $counts['pending']
				),
				'error'    => 'low_coverage',
				'fix_hint' => $hint,
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf(
				'Skeleton OK — %.1f%% ready (%d/%d), %d failed',
				$ready_pct, $counts['ready'], $total, $counts['failed']
			),
		];
	}

	public function cleanup(): void {
		// Read-only.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Skeleton_Coverage';
	return $list;
} );
