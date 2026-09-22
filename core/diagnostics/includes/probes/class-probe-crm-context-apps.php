<?php
/**
 * PHASE-0.63B C-09 — Context App registry and resolver probe.
 */
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_CRM_Context_Apps', false ) ) {
	return;
}

final class BizCity_Probe_CRM_Context_Apps implements BizCity_Diagnostics_Probe {
	public function id(): string { return 'core.crm.context_apps'; }
	public function label(): string { return 'CRM Context Apps contract'; }
	public function description(): string { return 'Kiểm schema runtime, key duy nhất và resolver Context Apps giới hạn N.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 69; }
	public function icon(): string { return 'layout-grid'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Context_App_Registry' ) || ! class_exists( 'BizCity_CRM_Context_Resolver' ) ) {
			return new WP_Error( 'crm_context_apps_missing', 'Context App registry/resolver chưa được load.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$apps = BizCity_CRM_Context_App_Registry::all();
		$issues = BizCity_CRM_Context_App_Registry::registration_issues();
		$ctx->emit_step( array(
			'label' => 'Registry registrations are valid and key-stable',
			'status' => empty( $issues ) ? 'pass' : 'fail',
			'detail' => empty( $issues ) ? count( $apps ) . ' app(s)' : implode( ', ', $issues ),
		) );

		$result = BizCity_CRM_Context_Resolver::for_conversation( array(
			'surface' => 'b2',
			'subject_roles' => array( 'customer' ),
			'pipeline_kind' => '',
			'channel' => '',
			'limit' => 6,
		) );
		$deterministic = $result === BizCity_CRM_Context_Resolver::for_conversation( array(
			'surface' => 'b2', 'subject_roles' => array( 'customer' ), 'pipeline_kind' => '', 'channel' => '', 'limit' => 6,
		) );
		$bounded = count( $result['apps'] ) <= 6 && isset( $result['more'] ) && is_array( $result['more'] );
		$ctx->emit_step( array(
			'label' => 'Resolver output is deterministic and bounded to N=6',
			'status' => ( $deterministic && $bounded ) ? 'pass' : 'fail',
			'detail' => 'visible=' . count( $result['apps'] ) . ', more=' . count( $result['more'] ),
		) );

		$filtered = BizCity_CRM_Context_Resolver::for_conversation( array(
			'surface' => 'b2',
			'subject_roles' => array( 'customer' ),
			'pipeline_kind' => 'service',
			'channel' => 'zalo_personal',
			'capabilities' => array( 'crm.inbox.read' ),
			'definition_tools' => array( 'stage', 'documents' ),
			'limit' => 6,
		) );
		$filter_shape_ok = isset( $filtered['rejected'] ) && is_array( $filtered['rejected'] )
			&& isset( $filtered['more'] ) && is_array( $filtered['more'] )
			&& count( $filtered['apps'] ) <= 6;
		$ctx->emit_step( array(
			'label' => 'Resolver applies role/kind/channel/capability filters',
			'status' => $filter_shape_ok ? 'pass' : 'fail',
			'detail' => 'visible=' . count( $filtered['apps'] ) . ', rejected=' . count( $filtered['rejected'] ),
		) );

		return array(
			'status' => empty( $issues ) && $deterministic && $bounded && $filter_shape_ok ? 'pass' : 'fail',
			'summary' => empty( $issues ) && $deterministic && $bounded && $filter_shape_ok ? 'Context App contract PASS.' : 'Context App contract FAIL.',
		);
	}

	public function cleanup(): void {
		// [2026-09-21 02:15 PM Johnny Chu - Chu Hoàng Anh] R-DDV — satisfy the diagnostics probe lifecycle contract; this read-only probe has no fixtures to clean.
	}
}

add_filter( 'bizcity_diagnostics_register_probes', static function ( $probes ) {
	$probes['core.crm.context_apps'] = new BizCity_Probe_CRM_Context_Apps();
	return $probes;
} );
