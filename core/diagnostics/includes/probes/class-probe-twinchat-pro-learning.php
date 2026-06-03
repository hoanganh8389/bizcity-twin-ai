<?php
/**
 * BizCity Diagnostics — twinchat.pro_learning probe (Consolidation M2).
 *
 * R-DDV — wrap PHASE-0.7-MASTER unified roadmap smoke (file/class L1, dependents
 * L2, REST L3) into canonical probe framework.
 *
 * Replaces standalone admin page `tools.php?page=bizcity-pro-learning-diag`
 * (per DIAGNOSTIC-CONSOLIDATION-PLAN.md M2). Business logic stays in
 * `BizCity_Pro_Learning_Diagnostic::run_all()` (still callable for JSON dump
 * + PDF live probe sandbox, just no longer a separate menu).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M2 (2026-06-02)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Twinchat_Pro_Learning implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'twinchat.pro_learning'; }
	public function label(): string       { return 'TwinChat · Pro Learning (PHASE-0.7-MASTER)'; }
	public function description(): string {
		return 'Aggregate PHASE-0.7-MASTER smoke: D0 diagnostic surface, E0 router, E1 adapters, E2 PDF, T0 entitlement, T1 UI, T2 gates, UI errors. PASS = no FAIL row across sections.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 75; }
	public function icon(): string        { return 'welcome-learn-more'; }
	public function estimate_ms(): int    { return 1500; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Pro_Learning_Diagnostic' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_Pro_Learning_Diagnostic chưa load — TwinChat Pro bootstrap?' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$diag    = BizCity_Pro_Learning_Diagnostic::instance();
		$results = $diag->run_all( true ); // force re-run, bypass transient

		if ( ! is_array( $results ) || empty( $results['sections'] ) ) {
			$steps[] = array( 'label' => 'Runtime · run_all()', 'status' => 'fail', 'detail' => 'no sections returned' );
			return self::fail( $steps, 'run_all() returned empty.', 'no_sections',
				'Verify BizCity_Pro_Learning_Diagnostic::run_all() implementation.' );
		}

		$totals = array( 'pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0 );
		$fails  = array();

		foreach ( $results['sections'] as $sec ) {
			$sec_pass = 0; $sec_warn = 0; $sec_fail = 0; $sec_skip = 0;
			$rows = isset( $sec['rows'] ) && is_array( $sec['rows'] ) ? $sec['rows'] : array();
			foreach ( $rows as $r ) {
				$st = isset( $r['status'] ) ? strtolower( (string) $r['status'] ) : '';
				if ( isset( $totals[ $st ] ) ) { $totals[ $st ]++; }
				if ( $st === 'pass' ) { $sec_pass++; }
				elseif ( $st === 'warn' ) { $sec_warn++; }
				elseif ( $st === 'fail' ) { $sec_fail++; $fails[] = ( $r['task'] ?? '?' ) . ': ' . ( $r['check'] ?? '' ); }
				elseif ( $st === 'skip' ) { $sec_skip++; }
			}
			$status = $sec_fail > 0 ? 'fail' : ( $sec_warn > 0 ? 'warn' : 'pass' );
			$steps[] = $s = array(
				'label'  => 'Section · ' . ( $sec['title'] ?? 'unknown' ),
				'status' => $status,
				'detail' => sprintf( '%d PASS · %d WARN · %d FAIL · %d SKIP', $sec_pass, $sec_warn, $sec_fail, $sec_skip ),
			);
			$ctx->emit_step( $s );
		}

		if ( $totals['fail'] > 0 ) {
			$hint = 'Failed rows: ' . implode( ' | ', array_slice( $fails, 0, 5 ) );
			if ( count( $fails ) > 5 ) { $hint .= ' (+' . ( count( $fails ) - 5 ) . ' more)'; }
			return self::fail( $steps,
				sprintf( 'Pro Learning smoke FAIL — %d failing rows.', $totals['fail'] ),
				'rows_failed', $hint );
		}

		$summary = sprintf(
			'%d PASS · %d WARN · %d SKIP across %d sections.',
			$totals['pass'], $totals['warn'], $totals['skip'], count( $results['sections'] )
		);
		return array(
			'status'  => $totals['warn'] > 0 ? 'pass' : 'pass',
			'summary' => $summary,
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
	$list[] = 'BizCity_Probe_Twinchat_Pro_Learning';
	return $list;
} );
