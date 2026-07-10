<?php
/**
 * BizCity Diagnostics — channel.phase_037 probe (Consolidation M3).
 *
 * R-DDV — wrap PHASE-0.37-UNIFY-CHANNEL-FB-ZALO-EMAIL task matrix into
 * canonical probe framework. Replaces standalone admin page
 * `tools.php?page=bizcity-channel-phase-037-diag` (per
 * DIAGNOSTIC-CONSOLIDATION-PLAN.md M3).
 *
 * Business logic stays in `BizCity_Channel_Phase_037_Diagnostic::compute_tasks()`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M3 (2026-06-02)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Channel_Phase_037', false ) ) {
	return;
}

final class BizCity_Probe_Channel_Phase_037 implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'channel.phase_037'; }
	public function label(): string       { return 'Channel Gateway · PHASE 0.37 Unify (FB/Zalo/Email)'; }
	public function description(): string {
		return 'Aggregate PHASE-0.37 task matrix (T-P0.37.M.W.T) — file/class existence, hook attachment, REST registration, R-CH compliance. PASS = no FAIL row.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 80; }
	public function icon(): string        { return 'networking'; }
	public function estimate_ms(): int    { return 1500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Channel_Phase_037_Diagnostic' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Channel_Phase_037_Diagnostic chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$diag  = BizCity_Channel_Phase_037_Diagnostic::instance();
		$tasks = method_exists( $diag, 'compute_tasks' ) ? $diag->compute_tasks() : array();
		if ( ! is_array( $tasks ) || ! $tasks ) {
			$steps[] = array( 'label' => 'Runtime · compute_tasks()', 'status' => 'fail', 'detail' => 'no tasks returned' );
			return self::fail( $steps, 'compute_tasks() returned empty.', 'no_tasks',
				'Verify BizCity_Channel_Phase_037_Diagnostic implementation.' );
		}

		$totals = array( 'pass' => 0, 'fail' => 0, 'skip' => 0, 'not_started' => 0, 'in_progress' => 0 );
		$fails  = array();
		foreach ( $tasks as $t ) {
			$st = isset( $t['status'] ) ? (string) $t['status'] : '';
			if ( isset( $totals[ $st ] ) ) { $totals[ $st ]++; }
			if ( $st === 'fail' ) {
				$fails[] = ( $t['id'] ?? '?' ) . ': ' . ( $t['label'] ?? '' );
			}
		}

		$steps[] = array(
			'label'  => 'Runtime · task matrix totals',
			'status' => $totals['fail'] > 0 ? 'fail' : 'pass',
			'detail' => sprintf(
				'%d PASS · %d FAIL · %d SKIP · %d in_progress · %d not_started (total %d)',
				$totals['pass'], $totals['fail'], $totals['skip'],
				$totals['in_progress'], $totals['not_started'], count( $tasks )
			),
		);
		$ctx->emit_step( end( $steps ) );

		if ( $totals['fail'] > 0 ) {
			$hint = 'Failed: ' . implode( ' | ', array_slice( $fails, 0, 5 ) );
			if ( count( $fails ) > 5 ) { $hint .= ' (+' . ( count( $fails ) - 5 ) . ' more)'; }
			return self::fail( $steps,
				sprintf( 'PHASE 0.37 task matrix FAIL — %d rows.', $totals['fail'] ),
				'tasks_failed', $hint );
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'PHASE 0.37 unify: %d PASS · %d in_progress · %d not_started · %d SKIP.',
				$totals['pass'], $totals['in_progress'], $totals['not_started'], $totals['skip'] ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void { /* read-only probe */ }

	private static function fail( array $steps, string $summary, string $error, string $hint ): array {
		return array(
			'status'   => 'fail',
			'summary'  => $summary,
			'error'    => $error,
			'fix_hint' => $hint,
			'steps'    => $steps,
		);
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Channel_Phase_037';
	return $list;
} );
