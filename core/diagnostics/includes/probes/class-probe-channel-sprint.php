<?php
/**
 * BizCity Diagnostics — channel.sprint probe (Consolidation M4).
 *
 * R-DDV — wrap PHASE-0.31-INTEGRATE-CHANNEL-GATEWAY-UNIFY sprint matrix into
 * canonical probe framework. Replaces standalone admin page
 * `tools.php?page=bizcity-channel-gateway-sprint-diag`.
 *
 * Strategy: invoke `BizCity_Channel_Gateway_Sprint_Diagnostic::collect_results()`
 * which runs render_page() under output buffering and returns the task_row
 * stream. PASS = no FAIL row.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M4 (2026-06-02)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Channel_Sprint implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'channel.sprint'; }
	public function label(): string       { return 'Channel Gateway · Sprint Matrix (PHASE-0.31)'; }
	public function description(): string {
		return 'Aggregate Channel Gateway sprint task_row results — file/class/hook/REST/registry/SPA bundle. PASS = no FAIL row.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 81; }
	public function icon(): string        { return 'admin-network'; }
	public function estimate_ms(): int    { return 3000; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Channel_Gateway_Sprint_Diagnostic' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Channel_Gateway_Sprint_Diagnostic chưa load.' );
		}
		if ( ! method_exists( 'BizCity_Channel_Gateway_Sprint_Diagnostic', 'collect_results' ) ) {
			return new WP_Error( 'method_missing', 'collect_results() chưa có — verify class version.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$diag = BizCity_Channel_Gateway_Sprint_Diagnostic::instance();
		$rows = $diag->collect_results();
		if ( ! is_array( $rows ) || ! $rows ) {
			$steps[] = array( 'label' => 'Runtime · collect_results()', 'status' => 'fail', 'detail' => 'no rows returned' );
			return self::fail( $steps, 'collect_results() returned empty.', 'no_rows',
				'Verify render_page() task_row() calls execute.' );
		}

		$totals = array( 'PASS' => 0, 'FAIL' => 0, 'SKIP' => 0, 'WARN' => 0 );
		$fails  = array();
		foreach ( $rows as $r ) {
			$st = isset( $r['status'] ) ? strtoupper( (string) $r['status'] ) : '';
			if ( isset( $totals[ $st ] ) ) { $totals[ $st ]++; }
			if ( $st === 'FAIL' ) {
				$fails[] = ( $r['task'] ?? '?' ) . ': ' . ( $r['check'] ?? '' );
			}
		}

		$steps[] = $s = array(
			'label'  => 'Runtime · sprint matrix totals',
			'status' => $totals['FAIL'] > 0 ? 'fail' : 'pass',
			'detail' => sprintf( '%d PASS · %d FAIL · %d WARN · %d SKIP (total %d)',
				$totals['PASS'], $totals['FAIL'], $totals['WARN'], $totals['SKIP'], count( $rows ) ),
		);
		$ctx->emit_step( $s );

		if ( $totals['FAIL'] > 0 ) {
			$hint = 'Failed: ' . implode( ' | ', array_slice( $fails, 0, 5 ) );
			if ( count( $fails ) > 5 ) { $hint .= ' (+' . ( count( $fails ) - 5 ) . ' more)'; }
			return self::fail( $steps,
				sprintf( 'Sprint matrix FAIL — %d rows.', $totals['FAIL'] ),
				'rows_failed', $hint );
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'Sprint matrix: %d PASS · %d WARN · %d SKIP.',
				$totals['PASS'], $totals['WARN'], $totals['SKIP'] ),
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
	$list[] = 'BizCity_Probe_Channel_Sprint';
	return $list;
} );
