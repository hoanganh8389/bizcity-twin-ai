<?php
/**
 * BizCity Diagnostics — astro.readiness_gate probe (PHASE-FAA2-TWINBRAIN).
 *
 * 3-layer evidence (R-DDV):
 * - Disk: runtime file has readiness-gate symbols.
 * - Loader: runtime class + private gate methods declared.
 * - Runtime: evaluate_astro_checklist_quality_gate() can be invoked and returns
 *   expected shape ({blocked, failed_keys, stale_keys}).
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_Astro_Readiness_Gate', false ) ) {
	return;
}

final class BizCity_Probe_Astro_Readiness_Gate implements BizCity_Diagnostics_Probe {

	public function id(): string       { return 'astro.readiness_gate'; }
	public function label(): string    { return 'Astro Readiness Gate (TwinBrain runtime)'; }
	public function description(): string {
		return 'Kiểm tra quality gate checklist + dispatch refetch trong stream_astro_mode (PHASE-FAA2-TWINBRAIN).';
	}
	public function severity(): string { return 'info'; }
	public function order(): int       { return 44; }
	public function icon(): string     { return 'shield'; }
	public function estimate_ms(): int { return 250; }

	public function precondition() {
		if ( ! defined( 'BIZCITY_TWINBRAIN_DIR' ) ) {
			return 'BIZCITY_TWINBRAIN_DIR chưa định nghĩa — twinbrain chưa active.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-07-05 Johnny Chu] PHASE-FAA2-TWINBRAIN — DDV probe for readiness gate.
		$steps    = array();
		$failures = array();

		$runtime_file = BIZCITY_TWINBRAIN_DIR . 'includes/class-twinbrain-runtime.php';
		$runtime_src  = file_exists( $runtime_file ) ? (string) file_get_contents( $runtime_file ) : '';

		$disk_ok = ( $runtime_src !== '' );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => $disk_ok,
			'msg'   => $disk_ok
				? 'Runtime file exists: class-twinbrain-runtime.php'
				: 'Runtime file missing: ' . $runtime_file,
		);
		if ( ! $disk_ok ) {
			$failures[] = 'runtime_file_missing';
		}

		$has_gate_symbol = ( strpos( $runtime_src, 'evaluate_astro_checklist_quality_gate' ) !== false );
		$has_refetch_symbol = ( strpos( $runtime_src, 'dispatch_astro_refetch_job' ) !== false );
		$has_event_symbol = ( strpos( $runtime_src, 'astro_refetch_dispatched' ) !== false );
		$steps[] = array(
			'layer' => 'disk',
			'ok'    => ( $has_gate_symbol && $has_refetch_symbol && $has_event_symbol ),
			'msg'   => ( $has_gate_symbol && $has_refetch_symbol && $has_event_symbol )
				? 'Readiness-gate symbols present (gate + refetch + event).'
				: 'Missing one of symbols: gate/refetch/event.',
		);
		if ( ! $has_gate_symbol || ! $has_refetch_symbol || ! $has_event_symbol ) {
			$failures[] = 'gate_symbols_missing';
		}

		$class_ok = class_exists( 'BizCity_TwinBrain_Runtime' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => $class_ok,
			'msg'   => $class_ok
				? 'Class BizCity_TwinBrain_Runtime declared.'
				: 'Class BizCity_TwinBrain_Runtime not loaded.',
		);
		if ( ! $class_ok ) {
			$failures[] = 'runtime_class_missing';
		}

		$gate_method_ok = $class_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'evaluate_astro_checklist_quality_gate' );
		$dispatch_ok    = $class_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'dispatch_astro_refetch_job' );
		$steps[] = array(
			'layer' => 'loader',
			'ok'    => ( $gate_method_ok && $dispatch_ok ),
			'msg'   => ( $gate_method_ok && $dispatch_ok )
				? 'Private methods ready: evaluate_astro_checklist_quality_gate + dispatch_astro_refetch_job.'
				: 'Missing one of private readiness methods.',
		);
		if ( ! $gate_method_ok || ! $dispatch_ok ) {
			$failures[] = 'runtime_methods_missing';
		}

		if ( $class_ok && $gate_method_ok && method_exists( 'BizCity_TwinBrain_Runtime', 'instance' ) ) {
			try {
				$inst = BizCity_TwinBrain_Runtime::instance();
				$rm   = new ReflectionMethod( 'BizCity_TwinBrain_Runtime', 'evaluate_astro_checklist_quality_gate' );
				$rm->setAccessible( true );
				$out = $rm->invoke( $inst, 999999999 );
				$shape_ok = is_array( $out )
					&& array_key_exists( 'blocked', $out )
					&& array_key_exists( 'failed_keys', $out )
					&& array_key_exists( 'stale_keys', $out );
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => $shape_ok,
					'msg'   => $shape_ok
						? 'Gate invoke OK — returned blocked/failed_keys/stale_keys shape.'
						: 'Gate invoke returned invalid shape.',
				);
				if ( ! $shape_ok ) {
					$failures[] = 'runtime_gate_shape';
				}
			} catch ( \Throwable $e ) {
				$steps[] = array(
					'layer' => 'runtime',
					'ok'    => false,
					'msg'   => 'Gate invoke exception: ' . $e->getMessage(),
				);
				$failures[] = 'runtime_gate_exception';
			}
		}

		if ( ! empty( $failures ) ) {
			$fatal_keys = array( 'runtime_file_missing', 'runtime_class_missing', 'runtime_methods_missing' );
			$has_fatal  = (bool) array_intersect( $fatal_keys, $failures );
			return array(
				'status'   => $has_fatal ? 'fail' : 'warn',
				'steps'    => $steps,
				'summary'  => 'Astro readiness gate issue(s): ' . implode( ', ', $failures ),
				'error'    => implode( '; ', $failures ),
				'fix_hint' => 'Kiểm tra stream_astro_mode + evaluate_astro_checklist_quality_gate trong class-twinbrain-runtime.php.',
			);
		}

		return array(
			'status'  => 'pass',
			'steps'   => $steps,
			'summary' => 'Astro readiness gate OK — symbols present, methods loaded, runtime shape valid.',
		);
	}

	public function cleanup(): void {
		// Read-only probe.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Astro_Readiness_Gate';
	return $list;
} );
