<?php
/**
 * PHASE-0.63A WP-1.8 — CRM pipeline definition registry probe.
 *
 * This is read-only: it validates the server-owned catalog and reports malformed
 * registrations instead of importing, editing, or seeding a pipeline definition.
 */
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Pipeline_Registry', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Pipeline_Registry implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.crm.pipeline_registry'; }
	public function label(): string { return 'CRM pipeline definition registry'; }
	public function description(): string { return 'Kiểm catalog pipeline đã load, contract definition, version archive và registration issues.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 70; }
	public function icon(): string { return 'workflow'; }
	public function estimate_ms(): int { return 200; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Pipeline_Registry' ) ) {
			return new WP_Error( 'crm_pipeline_registry_missing', 'Pipeline Registry chưa được load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$registry = 'BizCity_CRM_Pipeline_Registry';
		$issues   = $registry::registration_issues();
		$all      = $registry::all();
		$valid    = true;
		$invalid  = array();

		foreach ( $all as $kind => $definition ) {
			$result = $registry::validate( $definition );
			if ( true !== $result ) {
				$valid      = false;
				$invalid[]  = (string) $kind;
			}
		}

		$registry_ok = empty( $issues ) && $valid;
		$ctx->emit_step( array(
			'label'  => 'Registry registrations are valid and key-stable',
			'status' => empty( $issues ) ? 'pass' : 'fail',
			'detail' => empty( $issues ) ? count( $all ) . ' pipeline(s)' : implode( '; ', $issues ),
		) );

		$ctx->emit_step( array(
			'label'  => 'Loaded definitions satisfy pipeline-definition@1.0.0',
			'status' => $valid ? 'pass' : 'fail',
			'detail' => $valid ? count( $all ) . ' definition(s) validated' : 'Invalid kind(s): ' . implode( ', ', $invalid ),
		) );

		return array(
			'status'  => $registry_ok ? 'pass' : 'fail',
			'summary' => $registry_ok ? 'CRM pipeline registry PASS.' : 'CRM pipeline registry FAIL.',
			'error'   => $registry_ok ? '' : ( ! empty( $issues ) ? 'pipeline_registry_registration_invalid' : 'pipeline_registry_definition_invalid' ),
			'fix_hint' => $registry_ok ? '' : 'Sửa registration key/definition hoặc version archive rồi chạy lại probe.',
		);
	}

	public function cleanup(): void {
		// Read-only probe: no fixture or cleanup is required.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes['core.crm.pipeline_registry'] = new BizCity_Probe_CRM_Pipeline_Registry();
	return $probes;
} );
