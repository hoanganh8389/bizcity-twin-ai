<?php
/**
 * BizCity Diagnostics — core.membership.plan_rank probe (SPRINT-5 MBR-RANK).
 *
 * R-DDV 3 layers evidence:
 *
 *   Layer 1 (Disk)    — class-membership-plan-registry.php readable;
 *                       normalize() chứa `rank`, `consumes_seat`, `audience`.
 *   Layer 2 (Loader)  — BizCity_Membership_Plan_Registry::instance()->all()
 *                       trả plan array với rank (int), consumes_seat (bool) cho từng plan.
 *   Layer 3 (Runtime) — free plan có rank=0, consumes_seat=false;
 *                       pro plan có rank≥100, consumes_seat=true;
 *                       plans sorted by rank ascending thoả rank(free) < rank(pro).
 *
 * Read-only. No DB mutation.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-17 (SPRINT-5 MBR-RANK)
 */

// [2026-07-17 Johnny Chu] SPRINT-5 MBR-RANK — Plan rank + consumes_seat DDV probe.
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	$_iface_path = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
		? BIZCITY_DIAGNOSTICS_DIR . 'includes/interface-diagnostics-probe.php'
		: dirname( __DIR__ ) . '/interface-diagnostics-probe.php';
	if ( is_readable( $_iface_path ) ) {
		require_once $_iface_path;
	}
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Membership_Plan_Rank', false ) ) {
	return;
}

final class BizCity_Probe_Membership_Plan_Rank implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.membership.plan_rank'; }
	public function label(): string       { return 'Membership · Plan Rank + consumes_seat (SPRINT-5)'; }
	public function description(): string {
		return 'Disk/Loader/Runtime: BizCity_Membership_Plan_Registry normalize() có rank/consumes_seat/audience; all() trả đúng types; free.rank=0 consumes_seat=false; paid plan rank>=100 consumes_seat=true.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 62; }
	public function icon(): string        { return 'ShieldCheck'; }
	public function estimate_ms(): int    { return 50; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_Error( 'no_class', 'BizCity_Membership_Plan_Registry chưa load — kiểm tra core/membership/bootstrap.php.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		/* ── Layer 1 · Disk ────────────────────────────────────────────── */
		$registry_file = defined( 'BIZCITY_TWIN_AI_DIR' )
			? BIZCITY_TWIN_AI_DIR . 'core/membership/includes/class-membership-plan-registry.php'
			: '';
		$disk_readable = $registry_file !== '' && is_readable( $registry_file );

		// Check that normalize() in the file body uses 'rank' and 'consumes_seat' fields.
		$has_rank_field    = false;
		$has_seat_field    = false;
		$has_audience_field = false;
		if ( $disk_readable ) {
			$src = file_get_contents( $registry_file );
			if ( $src !== false ) {
				$has_rank_field     = ( strpos( $src, "'rank'" ) !== false || strpos( $src, '"rank"' ) !== false );
				$has_seat_field     = ( strpos( $src, "'consumes_seat'" ) !== false || strpos( $src, '"consumes_seat"' ) !== false );
				$has_audience_field = ( strpos( $src, "'audience'" ) !== false || strpos( $src, '"audience"' ) !== false );
			}
		}

		$disk_ok = $disk_readable && $has_rank_field && $has_seat_field;
		$step = array(
			'label'  => 'Disk · registry file + rank/consumes_seat fields',
			'status' => $disk_ok ? 'pass' : ( ! $disk_readable ? 'fail' : 'warn' ),
			'detail' => $disk_readable
				? sprintf(
					'File readable; rank=%s consumes_seat=%s audience=%s',
					$has_rank_field ? 'ok' : 'MISSING',
					$has_seat_field ? 'ok' : 'MISSING',
					$has_audience_field ? 'ok' : 'MISSING'
				)
				: 'File not readable (path: ' . $registry_file . ')',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		/* ── Layer 2 · Loader ──────────────────────────────────────────── */
		$all          = BizCity_Membership_Plan_Registry::instance()->all();
		$loader_ok    = is_array( $all ) && count( $all ) >= 1;
		$all_have_rank = true;
		$all_have_seat = true;
		foreach ( $all as $slug => $plan ) {
			if ( ! isset( $plan['rank'] ) || ! is_int( $plan['rank'] ) ) {
				$all_have_rank = false;
			}
			if ( ! isset( $plan['consumes_seat'] ) || ! is_bool( $plan['consumes_seat'] ) ) {
				$all_have_seat = false;
			}
		}

		$step = array(
			'label'  => 'Loader · all() returns plans with rank (int) + consumes_seat (bool)',
			'status' => ( $loader_ok && $all_have_rank && $all_have_seat ) ? 'pass' : 'fail',
			'detail' => sprintf(
				'%d plans loaded; rank_typed=%s; seat_typed=%s',
				count( $all ),
				$all_have_rank ? 'ok' : 'FAIL — rank missing or not int',
				$all_have_seat ? 'ok' : 'FAIL — consumes_seat missing or not bool'
			),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		/* ── Layer 3 · Runtime ─────────────────────────────────────────── */
		// Assert free plan: rank=0, consumes_seat=false.
		$free_plan = isset( $all['free'] ) ? $all['free'] : null;
		$free_ok   = $free_plan !== null
			&& (int) $free_plan['rank'] === 0
			&& ( isset( $free_plan['consumes_seat'] ) && $free_plan['consumes_seat'] === false );

		$step = array(
			'label'  => 'Runtime · free plan rank=0, consumes_seat=false',
			'status' => $free_ok ? 'pass' : 'fail',
			'detail' => $free_plan === null
				? 'free plan missing'
				: sprintf(
					'rank=%s (expect 0); consumes_seat=%s (expect false)',
					$free_plan['rank'],
					var_export( $free_plan['consumes_seat'] ?? 'MISSING', true )
				),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		// Assert at least one paid plan: rank>=100, consumes_seat=true.
		$paid_ok = false;
		$paid_slug = '';
		foreach ( $all as $slug => $plan ) {
			if ( $slug !== 'free' && (int) $plan['rank'] >= 100 && ( $plan['consumes_seat'] ?? false ) === true ) {
				$paid_ok   = true;
				$paid_slug = $slug;
				break;
			}
		}

		$step = array(
			'label'  => 'Runtime · at least one paid plan with rank>=100, consumes_seat=true',
			'status' => $paid_ok ? 'pass' : ( count( $all ) <= 1 ? 'skip' : 'fail' ),
			'detail' => $paid_ok
				? "Plan '{$paid_slug}' rank=" . $all[ $paid_slug ]['rank'] . ' consumes_seat=true'
				: ( count( $all ) <= 1 ? 'Only free plan defined — SKIP (admin needs to add paid plans)' : 'No paid plan with rank>=100 and consumes_seat=true found' ),
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		// Assert ordering: no paid plan has lower rank than free.
		$order_ok = true;
		$free_rank = $free_plan ? (int) $free_plan['rank'] : 0;
		foreach ( $all as $slug => $plan ) {
			if ( $slug !== 'free' && (int) $plan['rank'] <= $free_rank ) {
				$order_ok = false;
				break;
			}
		}

		$step = array(
			'label'  => 'Runtime · all non-free plans have rank > free.rank',
			'status' => $order_ok ? 'pass' : 'fail',
			'detail' => $order_ok
				? 'Rank ordering correct'
				: 'Found non-free plan with rank <= free.rank — fix numeric rank values',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$pass = $disk_ok && $loader_ok && $all_have_rank && $all_have_seat && $free_ok && $order_ok;

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass
				? sprintf( 'Plan Registry rank/seat contract OK — %d plans', count( $all ) )
				: 'Plan rank / consumes_seat contract không đầy đủ — xem steps',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

// [2026-07-17 Johnny Chu] SPRINT-5 MBR-RANK — register probe in diagnostics catalog filter.
add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Membership_Plan_Rank';
	return $list;
} );
