<?php
/**
 * BizCity Diagnostics — kg.bin.schema probe (Consolidation M5).
 *
 * R-DDV — verify KG .bin canonical chunks table integrity:
 *
 *   PASS  · mode=hotfix   (kg_passages canonical, hotfix active)
 *   PASS  · mode=migrated (kg_source_chunks canonical, migration done)
 *   FAIL  · mode=broken   (helper points to non-existent / empty table)
 *
 * Smoke portion of `tools.php?page=bizcity-kg-bin-diagnostic` (kept as
 * operator console with repair tools per DIAGNOSTIC-CONSOLIDATION-PLAN.md M5).
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      Consolidation M5 (2026-06-02)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';


// [2026-06-08 Johnny Chu] HOTFIX — double-load guard (bootstrap may include via filter AND direct require).
if ( class_exists( 'BizCity_Probe_KG_Bin_Schema', false ) ) {
	return;
}

final class BizCity_Probe_KG_Bin_Schema implements BizCity_Diagnostics_Probe {

	public function id(): string          { return 'kg.bin.schema'; }
	public function label(): string       { return 'KG · .bin canonical schema (kg_passages / kg_source_chunks)'; }
	public function description(): string {
		return 'Verify tbl_source_chunks() resolves to a non-empty BASE TABLE — accept HOTFIX (kg_passages) or migrated (kg_source_chunks). FAIL if helper points to missing/empty table.';
	}
	public function severity(): string    { return 'critical'; }
	public function order(): int          { return 65; }
	public function icon(): string        { return 'database'; }
	public function estimate_ms(): int    { return 400; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_KG_Bin_Diagnostic' ) ) {
			return new WP_Error( 'class_missing', 'BizCity_KG_Bin_Diagnostic chưa load — KG-Hub bootstrap?' );
		}
		if ( ! method_exists( 'BizCity_KG_Bin_Diagnostic', 'audit_schema' ) ) {
			return new WP_Error( 'method_missing', 'audit_schema() chưa có — verify class version.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();

		$diag  = BizCity_KG_Bin_Diagnostic::instance();
		$audit = $diag->audit_schema();

		$mode = isset( $audit['mode'] ) ? (string) $audit['mode'] : 'broken';

		$steps[] = $s = array(
			'label'  => 'Runtime · tbl_source_chunks() resolves',
			'status' => 'pass',
			'detail' => 'effective=' . ( $audit['effective_tbl'] ?? '?' ) . ' · schema_version=' . ( $audit['schema_version'] ?? '?' ),
		);
		$ctx->emit_step( $s );

		$psg = $audit['passages'] ?? array();
		$chk = $audit['chunks']   ?? array();
		$steps[] = $s = array(
			'label'  => 'Runtime · kg_passages',
			'status' => ! empty( $psg['exists'] ) ? 'pass' : 'warn',
			'detail' => ! empty( $psg['exists'] )
				? ( ( $psg['type'] ?? '?' ) . ' · ' . (int) ( $psg['rows'] ?? 0 ) . ' rows' )
				: 'NOT EXISTS',
		);
		$ctx->emit_step( $s );

		$steps[] = $s = array(
			'label'  => 'Runtime · kg_source_chunks',
			'status' => ! empty( $chk['exists'] ) ? 'pass' : 'warn',
			'detail' => ! empty( $chk['exists'] )
				? ( ( $chk['type'] ?? '?' ) . ' · ' . (int) ( $chk['rows'] ?? 0 ) . ' rows' )
				: 'NOT EXISTS',
		);
		$ctx->emit_step( $s );

		$steps[] = $s = array(
			'label'  => 'Runtime · canonical mode',
			'status' => $mode === 'broken' ? 'fail' : 'pass',
			'detail' => 'mode=' . $mode,
		);
		$ctx->emit_step( $s );

		if ( $mode === 'broken' ) {
			return self::fail( $steps,
				sprintf(
					'KG .bin canonical table broken — tbl_source_chunks() trỏ về %s nhưng table %s.',
					$audit['effective_tbl'] ?? '?',
					! empty( $chk['exists'] ) ? 'rỗng' : 'không tồn tại'
				),
				'broken_canonical',
				'Mở Tools → KG .bin Diagnostic → Section 0 schema audit → Force migrate hoặc verify HOTFIX trong class-kg-database.php::tbl_source_chunks().'
			);
		}

		return array(
			'status'  => 'pass',
			'summary' => sprintf( 'KG .bin canonical mode=%s · passages=%d rows · chunks=%d rows.',
				$mode, (int) ( $psg['rows'] ?? 0 ), (int) ( $chk['rows'] ?? 0 ) ),
			'steps'   => $steps,
		);
	}

	public function cleanup(): void { /* read-only */ }

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
	$list[] = 'BizCity_Probe_KG_Bin_Schema';
	return $list;
} );
