<?php
/**
 * PHASE-0.63A F-15 — read-only CRM pipeline SLA wiring probe.
 *
 * The probe validates the loaded SLA classes, the deadline queue contract, the
 * pointer-only Context Bank adapter and all current pipeline definitions. It
 * does not create runs, deadlines, Context Bank records or notifications.
 */
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Pipeline_SLA', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Pipeline_SLA implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.pipeline_sla'; }
	public function label(): string { return 'CRM pipeline SLA framework'; }
	public function description(): string { return 'Kiểm lớp SLA, deadline queue, định nghĩa pipeline và adapter Context Bank pointer-only mà không tạo dữ liệu.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 71; }
	public function icon(): string { return 'timer'; }
	public function estimate_ms(): int { return 250; }

	public function precondition() {
		$required = array(
			'BizCity_CRM_Pipeline_Registry',
			'BizCity_CRM_Pipeline_SLA_Clock',
			'BizCity_CRM_Pipeline_SLA_Service',
			'BizCity_CRM_Pipeline_SLA_Runner',
		);
		foreach ( $required as $class ) {
			if ( ! class_exists( $class ) ) {
				return new WP_Error( 'crm_pipeline_sla_dependency_missing', $class . ' chưa được load.' );
			}
		}
		return true;
	}

	public function run( $ctx ): array {
		$ok = true;
		$definitions = BizCity_CRM_Pipeline_Registry::all();
		$required = array( 'pipeline_kind', 'pipeline_def_version', 'data_json' );
		$source = defined( 'BIZCITY_CRM_DIR' ) ? BIZCITY_CRM_DIR . '/includes/class-db-installer.php' : '';
		$source_text = $source !== '' && is_readable( $source ) ? (string) file_get_contents( $source ) : '';
		$schema_ok = false !== strpos( $source_text, 'tbl_pipeline_deadlines' )
			&& false !== strpos( $source_text, 'pipeline_kind' )
			&& false !== strpos( $source_text, 'pipeline_def_version' )
			&& false !== strpos( $source_text, 'data_json' );
		$ctx->emit_step( array(
			'label' => 'SLA classes and deadline storage are loaded',
			'status' => $schema_ok ? 'pass' : 'fail',
			'detail' => $schema_ok ? 'deadline queue and pipeline run/task columns are declared' : 'CRM pipeline schema markers are incomplete',
		) );
		$ok = $ok && $schema_ok;

		$valid = true;
		foreach ( $definitions as $definition ) {
			if ( true !== BizCity_CRM_Pipeline_Registry::validate( $definition ) ) {
				$valid = false;
				break;
			}
		}
		$ctx->emit_step( array(
			'label' => 'All loaded definitions expose SLA-compatible configuration',
			'status' => $valid ? 'pass' : 'fail',
			'detail' => count( $definitions ) . ' definition(s)',
		) );
		$ok = $ok && $valid;

		// [2026-09-22 PHASE-0.63A F-15] parse_offset() returns seconds as an integer; compare the actual contract values.
		$clock_ok = 7200 === BizCity_CRM_Pipeline_SLA_Clock::parse_offset( '+2h' )
			&& -2400 === BizCity_CRM_Pipeline_SLA_Clock::parse_offset( '-40m' );
		$ctx->emit_step( array(
			'label' => 'SLA clock accepts signed offsets',
			'status' => $clock_ok ? 'pass' : 'fail',
			'detail' => $clock_ok ? '+2h and -40m' : 'signed offset parsing failed',
		) );
		$ok = $ok && $clock_ok;

		$adapter_ok = class_exists( 'BizCity_Context_Bank_CRM_Pipeline_Adapter' )
			&& defined( 'BizCity_Context_Bank_CRM_Pipeline_Adapter::CONTRACT_ID' )
			&& 'core.context_bank.crm_pipeline_lifecycle' === BizCity_Context_Bank_CRM_Pipeline_Adapter::CONTRACT_ID;
		$ctx->emit_step( array(
			'label' => 'Context Bank pipeline adapter is pointer-only and identified',
			'status' => $adapter_ok ? 'pass' : 'warn',
			'detail' => $adapter_ok ? BizCity_Context_Bank_CRM_Pipeline_Adapter::CONTRACT_ID : 'Adapter not loaded on this surface; capture remains unavailable.',
		) );
		if ( ! $adapter_ok && class_exists( 'BizCity_Context_Bank_CRM_Pipeline_Adapter' ) ) {
			$ok = false;
		}

		return array(
			'status' => $ok ? 'pass' : 'fail',
			'summary' => $ok ? 'CRM pipeline SLA framework PASS.' : 'CRM pipeline SLA framework FAIL.',
			'error' => $ok ? '' : 'pipeline_sla_wiring_invalid',
			'fix_hint' => $ok ? '' : 'Kiểm loader, deadline schema và pipeline definition contract.',
		);
	}

	public function cleanup(): void {
		// Read-only probe: no fixtures were created.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes['core.crm.pipeline_sla'] = new BizCity_Probe_CRM_Pipeline_SLA();
	return $probes;
} );
