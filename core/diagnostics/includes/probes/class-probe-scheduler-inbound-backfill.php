<?php
/**
 * BizCity Diagnostics — scheduler.inbound_backfill probe (SCH-NC W10).
 *
 * Quét legacy events thiếu `metadata.inbound{}` và phân loại thành 6 cases.
 * Mỗi case = 1 step PASS (count = 0) hoặc FAIL (count > 0 + fix link).
 *
 * Repair: mỗi case có 1 installer riêng đăng ký qua `bizcity_register_installers`
 * (id = `scheduler_backfill_inbound__<case>`). Click "🔧 Fix" trong Diagnostics
 * page sẽ trigger `BizCity_Site_Provisioner::run_one()` → gọi
 * `BizCity_Scheduler_Inbound_Backfiller::apply($case)` idempotent.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Wave SCH-NC W10 (2026-06-03)
 */

// [2026-06-03 Johnny Chu] SCH-NC W10 — backfill probe (replaces deleted CLI script).

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Scheduler_Inbound_Backfill', false ) ) {
	return;
}

final class BizCity_Probe_Scheduler_Inbound_Backfill implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'scheduler.inbound_backfill'; }
	public function label(): string       { return 'Scheduler · Inbound Provenance Backfill (SCH-NC W10)'; }
	public function description(): string {
		return 'Phát hiện legacy events thiếu metadata.inbound{} theo 6 cases (TwinBrain, Workflow, CRM legacy, orphan, corrupt). Per-case "🔧 Fix" qua Site Provisioner.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 51; }
	public function icon(): string        { return 'database'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Scheduler_Manager chưa load.' );
		}
		if ( ! class_exists( 'BizCity_Scheduler_Inbound_Backfiller' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Scheduler_Inbound_Backfiller chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$days   = (int) BizCity_Scheduler_Inbound_Backfiller::DEFAULT_DAYS;
		$cases  = BizCity_Scheduler_Inbound_Backfiller::cases();
		$counts = BizCity_Scheduler_Inbound_Backfiller::scan( $days );

		$steps   = array();
		$any_pending = 0;

		foreach ( $cases as $cid => $meta ) {
			$count = isset( $counts[ $cid ] ) ? (int) $counts[ $cid ] : 0;
			$ok    = ( $count === 0 );
			if ( ! $ok ) {
				$any_pending += $count;
			}

			$detail = $ok
				? sprintf( '0 rows pending (window=%dd, scope=%s)', $days, $meta['where'] )
				: sprintf(
					'%d rows pending → click "🔧 Fix · %s" trong Site Provisioner (installer id: scheduler_backfill_inbound__%s).',
					$count,
					$cid,
					$cid
				);

			$step = array(
				'label'  => sprintf( 'Case %s — %s', strtoupper( $cid ), $meta['label'] ),
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $detail,
			);
			if ( ! $ok ) {
				$step['fix_hint'] = self::fix_url( $cid );
			}
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		// Aggregate footer step.
		$total_step = array(
			'label'  => 'Total pending across cases',
			'status' => $any_pending === 0 ? 'pass' : 'fail',
			'detail' => $any_pending === 0
				? 'Toàn bộ events trong window có metadata.inbound{}.'
				: sprintf(
					'%d rows total → có thể chạy installer "scheduler_backfill_inbound__all" để apply tất cả.',
					$any_pending
				),
		);
		if ( $any_pending > 0 ) {
			$total_step['fix_hint'] = self::fix_url( 'all' );
		}
		$steps[] = $total_step;
		$ctx->emit_step( $total_step );

		// [2026-06-04 Johnny Chu] SCH-BC W6 — cache_layers_ready step.
		// Verify L0 memo (static prop reflection) + L2 done-opt prefix const
		// + L1 runtime memoize: call scan() twice and assert get_num_queries()
		// stays flat on the second call.
		$cache_step = self::evaluate_cache_layers( $days );
		$steps[]    = $cache_step;
		$ctx->emit_step( $cache_step );

		return array(
			'status'  => $any_pending === 0 ? 'pass' : 'fail',
			'summary' => $any_pending === 0
				? sprintf( 'Inbound backfill: 0 legacy rows pending (last %dd window across 6 cases).', $days )
				: sprintf( 'Inbound backfill: %d legacy rows cần repair — xem fix_hint từng case.', $any_pending ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}

	/* ── helpers ───────────────────────────────────────────────────── */

	/**
	 * [2026-06-04 Johnny Chu] SCH-BC W6 — runtime evidence for 3-tier cache.
	 *
	 * Disk:    constants `DONE_OPT_PREFIX`, `CACHE_GROUP` exist on backfiller.
	 * Loader:  `invalidate_all()` + `init()` static methods callable.
	 * Runtime: scan() second call must add 0 queries (memoized in L0/L1).
	 */
	private static function evaluate_cache_layers( int $days ): array {
		$cls = 'BizCity_Scheduler_Inbound_Backfiller';

		$has_done_prefix = defined( $cls . '::DONE_OPT_PREFIX' );
		$has_cache_group = defined( $cls . '::CACHE_GROUP' );
		$has_invalidate  = method_exists( $cls, 'invalidate_all' );
		$has_init        = method_exists( $cls, 'init' );

		if ( ! $has_done_prefix || ! $has_cache_group || ! $has_invalidate || ! $has_init ) {
			return array(
				'label'    => 'Cache layers ready (SCH-BC W1-W4)',
				'status'   => 'fail',
				'detail'   => sprintf(
					'Disk/Loader missing — DONE_OPT_PREFIX=%s, CACHE_GROUP=%s, invalidate_all=%s, init=%s.',
					$has_done_prefix ? 'yes' : 'NO',
					$has_cache_group ? 'yes' : 'NO',
					$has_invalidate  ? 'yes' : 'NO',
					$has_init        ? 'yes' : 'NO'
				),
				'fix_hint' => 'Reload backfiller class — kiểm tra core/scheduler/includes/class-scheduler-inbound-backfiller.php đã update lên SCH-BC W1-W4.',
			);
		}

		// Runtime: prime + measure query delta.
		BizCity_Scheduler_Inbound_Backfiller::scan( $days ); // prime
		$q0 = function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0;
		BizCity_Scheduler_Inbound_Backfiller::scan( $days ); // should hit memo
		$q1 = function_exists( 'get_num_queries' ) ? (int) get_num_queries() : 0;
		$delta = $q1 - $q0;

		$ok = ( $delta === 0 );
		return array(
			'label'  => 'Cache layers ready (SCH-BC W1-W4)',
			'status' => $ok ? 'pass' : 'warn',
			'detail' => $ok
				? sprintf( 'Disk+Loader OK · Runtime: scan() lần 2 thêm 0 query (L0 memo hit, window=%dd).', $days )
				: sprintf( 'Disk+Loader OK · Runtime: scan() lần 2 thêm %d query — memo có thể bị reset giữa 2 lời gọi (kiểm tra `static $memo_scan`).', $delta ),
		);
	}

	private static function fix_url( string $case_id ): string {
		$installer_id = 'scheduler_backfill_inbound__' . $case_id;
		$url = add_query_arg(
			array(
				'page'                   => 'bizcity-diagnostics',
				'bizcity_run_installer'  => $installer_id,
				'_wpnonce'               => wp_create_nonce( 'bizcity_run_installer_' . $installer_id ),
			),
			admin_url( 'tools.php' )
		);
		return $url;
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = new BizCity_Probe_Scheduler_Inbound_Backfill();
	return $list;
} );
