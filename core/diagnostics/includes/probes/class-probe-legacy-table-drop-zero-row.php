<?php
/**
 * Runtime DDV for the legacy-table zero-row DROP guard.
 *
 * Uses the policy decision with synthetic counts and does not create, delete,
 * or drop a physical table during diagnostics.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-08-28
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) ) {
	return;
}

if ( class_exists( 'BizCity_Probe_Legacy_Table_Drop_Zero_Row', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_Drop_Zero_Row implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.legacy_table.drop_zero_row_guard';
	}

	public function label(): string {
		return 'Legacy tables - non-empty DROP guard';
	}

	public function description(): string {
		return 'Verifies a non-empty legacy table is refused by the shared zero-row DROP policy without mutating a physical table.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 23;
	}

	public function icon(): string {
		return 'shield-alert';
	}

	public function estimate_ms(): int {
		return 50;
	}

	public function precondition() {
		return class_exists( 'BizCity_Legacy_Table_Policy' ) && method_exists( 'BizCity_Legacy_Table_Policy', 'zero_row_drop_allowed' )
			? true
			: new WP_Error( 'legacy_zero_row_guard_missing', 'Legacy zero-row DROP guard is not loaded.' );
	}

	public function run( $ctx ): array {
		// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — execute both zero-row decisions and keep the probe non-destructive.
		$steps = array();
		$pass = true;
		$emit = function ( $label, $ok, $detail ) use ( $ctx, &$steps, &$pass ) {
			$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
		};

		$non_empty_refused = ! BizCity_Legacy_Table_Policy::zero_row_drop_allowed( 1 );
		$emit(
			'Runtime - non-empty count refuses DROP',
			$non_empty_refused,
			$non_empty_refused ? 'count=1 is refused before DROP.' : 'count=1 was incorrectly accepted for DROP.'
		);

		$zero_row_allowed = BizCity_Legacy_Table_Policy::zero_row_drop_allowed( 0 );
		$emit(
			'Runtime - zero-row count passes count gate',
			$zero_row_allowed,
			$zero_row_allowed ? 'count=0 passes the count gate; approval and physical checks remain required.' : 'count=0 was incorrectly refused by the count gate.'
		);

		$approval_refused = ! BizCity_Legacy_Table_Policy::can_drop( 'bizcity_intent_logs' );
		$emit(
			'Runtime - approval gate remains required',
			$approval_refused,
			$approval_refused ? 'Unapproved retired table is still not drop-eligible.' : 'Unapproved retired table became drop-eligible.'
		);

		return array(
			'status'  => $pass ? 'pass' : 'fail',
			'summary' => $pass ? 'Non-empty legacy tables are refused by the zero-row DROP guard.' : 'Legacy zero-row DROP guard has a runtime failure.',
			'steps'   => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_Drop_Zero_Row';
	return $list;
} );
