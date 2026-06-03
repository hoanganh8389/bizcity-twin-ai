<?php
/**
 * BizCity Diagnostics — bizcoach.pro_sprint probe (Consolidation M7).
 *
 * R-DDV — wrap BizCoach Pro sprint matrix (F.1–F.16, read-only sections) into
 * canonical probe framework. Replaces standalone admin page
 * `tools.php?page=bizcoach-pro-diag` as the smoke source-of-truth.
 *
 * The legacy admin page stays mounted because it owns interactive operator
 * tools (smoke runner POSTs, legacy adopter, coachee browser, F.13 progress
 * board). The probe only mirrors the read-only `compute_fX_tasks()` matrix.
 *
 * Live-network sections (G.6 gateway probes) are intentionally skipped here —
 * trigger those via the operator page with `?bcpro_diag_g6=1`.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M7 (2026-06-02)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_BizCoach_Pro implements BizCity_Diagnostics_Probe {

	/** @var string[] static methods on BizCoach_Pro_Sprint_Diagnostic to aggregate. */
	private static $sections = array(
		'F.1 plugin health'         => 'compute_f1_tasks',
		'F.2 legacy bccm_* schema'  => 'compute_f2_tasks',
		'F.3 template registry'     => 'compute_f3_tasks',
		'F.4 persona/intent'        => 'compute_f4_tasks',
		'F.5 astro pipeline'        => 'compute_f5_tasks',
		'F.6 R-NO-CONFLICT'         => 'compute_f6_tasks',
		'F.9 sprint I (REST)'       => 'compute_f9_tasks',
		'F.11 legacy admin pages'   => 'compute_f11_tasks',
		'F.14 astro persona dialog' => 'compute_f14_tasks',
		'F.15 PHASE-0.1 gateway'    => 'compute_f15_tasks',
		'F.16 cache health'         => 'compute_f16_tasks',
	);

	public function id(): string          { return 'bizcoach.pro_sprint'; }
	public function label(): string       { return 'BizCoach Pro · Sprint Matrix (F.1–F.16)'; }
	public function description(): string {
		return 'Aggregate read-only sections of BizCoach_Pro_Sprint_Diagnostic — plugin health, REST surface, schema facade, template registry, persona/intent providers, astro pipeline, R-NO-CONFLICT, cache health. PASS = no FAIL row.';
	}
	public function severity(): string    { return 'warning'; }
	public function order(): int          { return 90; }
	public function icon(): string        { return 'awards'; }
	public function estimate_ms(): int    { return 2500; }

	public function precondition() {
		if ( ! class_exists( 'BizCoach_Pro_Sprint_Diagnostic' ) ) {
			if ( defined( 'BCPRO_DIR' ) && file_exists( BCPRO_DIR . 'includes/class-sprint-diagnostic.php' ) ) {
				require_once BCPRO_DIR . 'includes/class-sprint-diagnostic.php';
			}
		}
		if ( ! class_exists( 'BizCoach_Pro_Sprint_Diagnostic' ) ) {
			return new WP_Error( 'class_missing',
				'BizCoach_Pro_Sprint_Diagnostic chưa load — bizcoach-pro plugin chưa active hoặc BCPRO_DIR chưa định nghĩa.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps   = array();
		$totals  = array( 'PASS' => 0, 'FAIL' => 0, 'WARN' => 0, 'SKIP' => 0, 'PENDING' => 0 );
		$fails   = array();
		$missing = array();

		foreach ( self::$sections as $label => $method ) {
			if ( ! is_callable( array( 'BizCoach_Pro_Sprint_Diagnostic', $method ) ) ) {
				$missing[] = $method;
				$step = array( 'label' => $label, 'status' => 'fail', 'detail' => $method . '() not callable' );
				$steps[] = $step;
				$ctx->emit_step( $step );
				continue;
			}

			try {
				$rows = call_user_func( array( 'BizCoach_Pro_Sprint_Diagnostic', $method ) );
			} catch ( \Throwable $e ) {
				$step = array( 'label' => $label, 'status' => 'fail', 'detail' => 'exception: ' . $e->getMessage() );
				$steps[] = $step;
				$ctx->emit_step( $step );
				continue;
			}

			if ( ! is_array( $rows ) ) { $rows = array(); }

			$sec = array( 'PASS' => 0, 'FAIL' => 0, 'WARN' => 0, 'SKIP' => 0, 'PENDING' => 0 );
			foreach ( $rows as $r ) {
				$st = isset( $r['status'] ) ? strtoupper( (string) $r['status'] ) : '';
				if ( isset( $sec[ $st ] ) )   { $sec[ $st ]++; }
				if ( isset( $totals[ $st ] ) ) { $totals[ $st ]++; }
				if ( $st === 'FAIL' ) {
					$fails[] = ( isset( $r['id'] ) ? $r['id'] : '?' ) . ': ' . ( isset( $r['check'] ) ? $r['check'] : '' );
				}
			}

			$status = $sec['FAIL'] > 0 ? 'fail' : ( $sec['WARN'] > 0 ? 'warn' : 'pass' );
			$detail = sprintf(
				'%d row · %d PASS · %d FAIL · %d WARN · %d SKIP',
				count( $rows ), $sec['PASS'], $sec['FAIL'], $sec['WARN'], $sec['SKIP']
			);
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		}

		if ( $missing ) {
			return self::fail( $steps,
				sprintf( 'BizCoach Pro: %d compute_fX_tasks() method missing.', count( $missing ) ),
				'methods_missing',
				'Missing: ' . implode( ', ', $missing ) . '. Verify includes/class-sprint-diagnostic.php intact.' );
		}

		if ( $totals['FAIL'] > 0 ) {
			$hint = 'Failed: ' . implode( ' | ', array_slice( $fails, 0, 5 ) );
			if ( count( $fails ) > 5 ) { $hint .= ' (+' . ( count( $fails ) - 5 ) . ' more)'; }
			return self::fail( $steps,
				sprintf( 'BizCoach Pro sprint matrix FAIL — %d rows.', $totals['FAIL'] ),
				'sections_failed', $hint );
		}

		return array(
			'status'  => $totals['WARN'] > 0 ? 'warn' : 'pass',
			'summary' => sprintf(
				'BizCoach Pro: %d PASS · %d WARN · %d SKIP across %d sections.',
				$totals['PASS'], $totals['WARN'], $totals['SKIP'], count( self::$sections )
			),
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
	$list[] = 'BizCity_Probe_BizCoach_Pro';
	return $list;
} );
