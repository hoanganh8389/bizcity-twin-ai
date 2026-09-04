<?php
/**
 * Runtime DDV for Group A/B legacy-table install prevention.
 *
 * Every non-quarantine catalog row must be blocked before an installer can
 * create the table, and the central policy must deny the create operation.
 * This probe never calls an installer and never issues SQL.
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

if ( class_exists( 'BizCity_Probe_Legacy_Table_Install_Prevention', false ) ) {
	return;
}

final class BizCity_Probe_Legacy_Table_Install_Prevention implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.legacy_table.install_prevention';
	}

	public function label(): string {
		return 'Legacy tables - Group A/B install prevention';
	}

	public function description(): string {
		return 'Verifies every non-quarantine legacy table is blocked before CREATE TABLE or dbDelta can run.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 18;
	}

	public function icon(): string {
		return 'shield-off';
	}

	public function estimate_ms(): int {
		return 100;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) ) {
			return new WP_Error( 'legacy_policy_missing', 'Legacy table policy is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Registry' ) ) {
			return new WP_Error( 'table_registry_missing', 'Diagnostics table registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-08-28 Johnny Chu] PHASE-1.30-DDV — verify Group A/B install gates for every catalog row without invoking an installer or SQL.
		$steps = array();
		$blocked = 0;
		$checked = 0;
		$failed = array();
		foreach ( BizCity_Diagnostics_Table_Registry::deprecated_tables() as $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || ! empty( $row['quarantine_only'] ) ) {
				continue;
			}
			$name = (string) $row['name'];
			$checked++;
			$install_blocked = BizCity_Legacy_Table_Policy::install_blocked( $name );
			$create_blocked = ! BizCity_Legacy_Table_Policy::allow_sql( $name, 'create' );
			$ok = $install_blocked && $create_blocked;
			if ( $ok ) {
				$blocked++;
			} else {
				$failed[] = $name;
			}
			$step = array(
				'label'  => 'Install gate: ' . $name,
				'status' => $ok ? 'pass' : 'fail',
				'detail' => $ok ? 'install_blocked=true; create SQL denied before installer.' : 'Legacy install/create gate is not fail-closed.',
			);
			$steps[] = $step;
			$ctx->emit_step( $step );
		}
		$all_blocked = $checked > 0 && $blocked === $checked;
		$summary = $all_blocked
			? $checked . ' Group A/B tables are blocked before installation.'
			: count( $failed ) . ' Group A/B tables are not fully blocked before installation.';
		return array(
			'status'  => $all_blocked ? 'pass' : 'fail',
			'summary' => $summary,
			'error'   => implode( ', ', $failed ),
			'steps'   => $steps,
			'checked' => $checked,
			'blocked' => $blocked,
			'failed'  => $failed,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Legacy_Table_Install_Prevention';
	return $list;
} );
