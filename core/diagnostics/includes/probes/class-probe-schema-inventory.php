<?php
/**
 * BizCity Diagnostics — schema.inventory probe (Phase 0.41 L9.b+).
 *
 * Meta-probe that walks the full table registry once and reports:
 *   - critical-missing count (drives wizard go/no-go)
 *   - non-critical missing count
 *   - drift count (column inspector ≠ declared)
 *   - auto-fixable subset (rows that have either an installer mapped OR a JSON
 *     changelog entry, both of which the Auto-Fix-All routine can resolve).
 *
 * Status policy:
 *   pass    → 0 missing + 0 drift
 *   fail    → any critical-missing OR any auto-fixable drift
 *   precheck-fail → table inspector class not loaded
 *
 * Does NOT mutate data — read-only. The "Auto-Fix All" remediation lives in
 * a dedicated REST endpoint (`POST /smoke/auto-fix-all`) so it can be invoked
 * separately from probe runs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-05-21 (Phase 0.41 L9.b+)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

final class BizCity_Probe_Schema_Inventory implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'schema.inventory'; }
	public function label(): string       { return 'Schema inventory'; }
	public function description(): string {
		return 'Audit toàn bộ bảng đã đăng ký: critical missing, drift cột, và đếm số bảng có thể Auto-Fix bằng installer hoặc JSON changelog.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 0; } // first in wizard
	public function icon(): string        { return 'list-checks'; }
	public function estimate_ms(): int    { return 300; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Diagnostics_Table_Inspector' ) ) {
			return new WP_Error( 'inspector_missing', 'Table inspector chưa load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$rows = BizCity_Diagnostics_Table_Inspector::inspect_all();

		$json_tables = class_exists( 'BizCity_Diagnostics_Changelog_Loader' )
			? BizCity_Diagnostics_Changelog_Loader::tables()
			: [];

		$total            = count( $rows );
		$missing          = [];
		$critical_missing = [];
		$drift            = [];
		$auto_fixable     = [];

		foreach ( $rows as $r ) {
			$has_installer = class_exists( 'BizCity_Diagnostics_Installer_Resolver' )
				? (bool) BizCity_Diagnostics_Installer_Resolver::for_row( $r )
				: false;
			$has_json = isset( $json_tables[ $r['name'] ] );

			if ( empty( $r['exists'] ) ) {
				$missing[] = $r['physical'];
				if ( ! empty( $r['critical'] ) ) {
					$critical_missing[] = $r['physical'];
				}
				if ( $has_installer || $has_json ) {
					$auto_fixable[] = $r['physical'];
				}
				continue;
			}

			// Drift = column diff status === 'drift'.
			if ( class_exists( 'BizCity_Diagnostics_Column_Inspector' ) ) {
				$diff = BizCity_Diagnostics_Column_Inspector::diff( $r );
				if ( ( $diff['status'] ?? '' ) === 'drift' ) {
					$drift[] = $r['physical'];
					if ( $has_installer || $has_json ) {
						$auto_fixable[] = $r['physical'];
					}
				}
			}
		}

		$ctx->emit_step( [
			'label'  => 'Scan registered tables',
			'status' => 'pass',
			'detail' => sprintf( '%d total · %d missing · %d drift', $total, count( $missing ), count( $drift ) ),
		] );
		$ctx->emit_step( [
			'label'  => 'Critical missing',
			'status' => $critical_missing ? 'fail' : 'pass',
			'detail' => $critical_missing ? implode( ', ', array_slice( $critical_missing, 0, 5 ) ) : 'none',
		] );
		$ctx->emit_step( [
			'label'  => 'Auto-fixable (installer or JSON)',
			'status' => 'pass',
			'detail' => sprintf( '%d / %d', count( $auto_fixable ), count( $missing ) + count( $drift ) ),
		] );

		$needs_fix = $critical_missing || $drift;
		if ( $needs_fix ) {
			$fix_hint = '';
			if ( $auto_fixable ) {
				$fix_url = admin_url( 'tools.php?page=bizcity-diagnostics#smoke-test' );
				$fix_hint = sprintf(
					'Click "🔧 Auto-fix all" trên trang Diagnostics để xử lý %d bảng (URL: %s)',
					count( $auto_fixable ),
					$fix_url
				);
			} else {
				$fix_hint = 'Không có bảng nào auto-fixable. Mở Diagnostics và xử lý từng bảng (cần installer hoặc JSON changelog).';
			}
			return [
				'status'    => 'fail',
				'summary'   => sprintf(
					'%d critical missing · %d drift · %d/%d auto-fixable',
					count( $critical_missing ),
					count( $drift ),
					count( $auto_fixable ),
					count( $missing ) + count( $drift )
				),
				'error'     => $critical_missing
					? sprintf( '%d bảng critical đang thiếu', count( $critical_missing ) )
					: sprintf( '%d bảng drift cột', count( $drift ) ),
				'fix_hint'  => $fix_hint,
				'artifacts' => [
					[ 'kind' => 'missing',          'id' => count( $missing ),          'label' => implode( ', ', array_slice( $missing, 0, 8 ) ) ],
					[ 'kind' => 'critical_missing', 'id' => count( $critical_missing ), 'label' => implode( ', ', $critical_missing ) ],
					[ 'kind' => 'drift',            'id' => count( $drift ),            'label' => implode( ', ', array_slice( $drift, 0, 8 ) ) ],
					[ 'kind' => 'auto_fixable',     'id' => count( $auto_fixable ),     'label' => implode( ', ', array_slice( $auto_fixable, 0, 8 ) ) ],
				],
			];
		}

		return [
			'status'  => 'pass',
			'summary' => sprintf( '%d bảng OK, không có drift hay missing', $total ),
		];
	}

	public function cleanup(): void {
		// Read-only probe — nothing to clean up.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Schema_Inventory';
	return $list;
} );
