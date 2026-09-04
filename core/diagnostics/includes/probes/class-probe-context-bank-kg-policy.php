<?php
/**
 * Deterministic Context Bank KG candidate policy probe.
 *
 * No KG-Hub call, provider call or storage mutation is performed.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_KG_Policy', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_KG_Policy implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB6.2-DDV — expose deterministic KG candidate gate evidence.
		return 'core.context_bank.kg_policy';
	}

	public function label(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB6.2-DDV — label the KG policy probe.
		return 'Context Bank - KG candidate policy';
	}

	public function description(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB6.2-DDV — describe raw-event rejection and approved rollup checks.
		return 'Checks deterministic KG candidate rejection for raw events and approved selection for verified stable rollups without calling KG-Hub.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 72; }
	public function icon(): string { return 'filter'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_KG_Candidate_Policy' ) ) {
			return new WP_Error( 'context_bank_kg_policy_missing', 'Context Bank KG candidate policy is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-CB6.2-DDV — prove raw event denial, rollup approval and unverified evidence rejection.
		$raw = BizCity_Context_Bank_KG_Candidate_Policy::evaluate( array( 'source_contract_id' => 'core.twin_core.context_bank_event', 'record_kind' => 'event', 'lifecycle_status' => 'active' ), array( 'authorized' => true, 'pointer_verified' => true ) );
		$rollup = BizCity_Context_Bank_KG_Candidate_Policy::evaluate( array( 'source_contract_id' => 'core.context-bank', 'record_id' => 'rollup_fixture', 'record_kind' => 'rollup', 'provenance_ref' => 'context-bank:rollup_fixture', 'rollup_version' => '1.0.0', 'evidence_refs' => array( array( 'record_id' => 'event_fixture' ) ), 'lifecycle_status' => 'active', 'confidence' => 0.95, 'stable' => true ), array( 'authorized' => true, 'pointer_verified' => true, 'confidence_threshold' => 0.75 ) );
		$unverified = BizCity_Context_Bank_KG_Candidate_Policy::evaluate( array( 'source_contract_id' => 'core.context-bank', 'record_id' => 'rollup_fixture', 'record_kind' => 'rollup', 'provenance_ref' => 'context-bank:rollup_fixture', 'rollup_version' => '1.0.0', 'evidence_refs' => array( array( 'record_id' => 'event_fixture' ) ), 'lifecycle_status' => 'active', 'confidence' => 0.95, 'stable' => true ), array( 'authorized' => true, 'pointer_verified' => false ) );
		$missing_provenance = BizCity_Context_Bank_KG_Candidate_Policy::evaluate( array( 'source_contract_id' => 'core.context-bank', 'record_kind' => 'rollup', 'lifecycle_status' => 'active', 'confidence' => 0.95, 'stable' => true ), array( 'authorized' => true, 'pointer_verified' => true ) );
		$checks = array(
			array( 'label' => 'Raw event rejected', 'ok' => empty( $raw['candidate'] ) && (string) $raw['reason'] === 'record_kind_not_candidate', 'detail' => 'Raw Event Stream evidence stays out of KG candidates.' ),
			array( 'label' => 'Verified stable rollup approved', 'ok' => ! empty( $rollup['candidate'] ) && (string) $rollup['reason'] === 'approved_summary_rollup', 'detail' => 'Stable verified summary rollup passes the policy threshold.' ),
			array( 'label' => 'Unverified evidence rejected', 'ok' => empty( $unverified['candidate'] ) && (string) $unverified['reason'] === 'evidence_unverified', 'detail' => 'KG promotion requires verified Context Bank evidence.' ),
			array( 'label' => 'Missing provenance rejected', 'ok' => empty( $missing_provenance['candidate'] ) && (string) $missing_provenance['reason'] === 'provenance_missing', 'detail' => 'KG candidates require a stable record and reversible provenance reference.' ),
		);
		$pass = true;
		foreach ( $checks as $check ) {
			$step = array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] );
			$ctx->emit_step( $step );
			$pass = $pass && $check['ok'];
		}
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'KG candidate policy passed raw-event rejection and verified-rollup approval checks.' : 'KG candidate policy failed.', 'fix_hint' => $pass ? '' : 'Check candidate kind, pointer verification, stability and confidence gates.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_KG_Policy';
	return $list;
} );