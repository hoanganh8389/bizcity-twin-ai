<?php
/**
 * BizCity Diagnostics — Module Registry probe (Phase 0.99.3 dogfood).
 *
 * Surfaces the inventory built by `BizCity_Module_Registry` so the smoke
 * wizard can answer:
 *
 *   - Did `bizcity_register_module` filter wire correctly at plugins_loaded@20?
 *   - Are any modules failing requirements (php/wp/dep)?
 *   - Did any module throw during boot()?
 *
 * Status mapping:
 *   - PASS  : 0 modules registered (allowed) OR all booted=true.
 *   - FAIL  : ≥ 1 module booted=false.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      1.0.0  (Phase 0.99.3 — 2026-06-01)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_Module_Registry', false ) ) {
	return;
}

final class BizCity_Probe_Module_Registry implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'core.module-registry'; }
	public function label(): string       { return 'Module Registry · `bizcity_register_module`'; }
	public function description(): string {
		return 'Liệt kê mọi module sub-plugin đăng ký qua filter `bizcity_register_module` và xác nhận boot() chạy không exception.';
	}
	public function severity(): string    { return 'info'; }
	public function order(): int          { return 5; }
	public function icon(): string        { return 'puzzle-piece'; }
	public function estimate_ms(): int    { return 50; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Module_Registry' ) ) {
			return 'BizCity_Module_Registry chưa load (Phase 0.99.3).';
		}
		return true;
	}

	public function run( $ctx ): array {
		$inventory = BizCity_Module_Registry::instance()->inventory();
		$total     = count( $inventory );
		$failed    = [];
		$booted    = 0;
		$steps     = [];

		if ( $total === 0 ) {
			return [
				'status'  => 'pass',
				'summary' => 'No modules registered via `bizcity_register_module` (legacy plugins_loaded init pattern still supported).',
				'steps'   => [
					[ 'label' => 'Filter discovery', 'status' => 'pass', 'detail' => '0 modules pushed onto bizcity_register_module.' ],
				],
			];
		}

		foreach ( $inventory as $id => $row ) {
			$is_ok    = ! empty( $row['booted'] );
			$detail   = sprintf( 'v%s · %dms', $row['version'] ?: '0.0.0', (int) $row['duration_ms'] );
			if ( ! $is_ok ) {
				$detail .= ' · ' . ( $row['error'] !== '' ? $row['error'] : 'unknown error' );
				$failed[] = $id;
			} else {
				$booted++;
			}
			$steps[] = [
				'label'  => $id,
				'status' => $is_ok ? 'pass' : 'fail',
				'detail' => $detail,
			];
		}

		if ( ! empty( $failed ) ) {
			return [
				'status'   => 'fail',
				'summary'  => sprintf( '%d/%d module booted; %d failed: %s', $booted, $total, count( $failed ), implode( ', ', $failed ) ),
				'error'    => 'Some registered modules failed boot() — see steps for per-module reason.',
				'fix_hint' => 'Check that requires() in each module matches the runtime PHP/WP version; verify module classes exist; review error_log for boot() exceptions.',
				'steps'    => $steps,
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( 'All %d module booted via `bizcity_register_module`.', $total ),
			'steps'   => $steps,
		];
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list   = is_array( $list ) ? $list : [];
	$list[] = 'BizCity_Probe_Module_Registry';
	return $list;
} );
