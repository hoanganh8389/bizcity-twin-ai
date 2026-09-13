<?php
/**
 * W8.6 metadata-only before/after action evidence probe.
 *
 * The probe does not execute or record a business mutation. It verifies the
 * evidence envelope and the registered order lifecycle rollup boundary.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-13 (PHASE-0.41-W8.6)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) { return; }
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) { return; }
if ( class_exists( 'BizCity_Probe_TwinWeb_Action_Evidence', false ) ) { return; }

final class BizCity_Probe_TwinWeb_Action_Evidence implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.employee_action_evidence'; }
	public function label(): string { return 'Twin GPT action evidence contract'; }
	public function description(): string { return 'Verifies metadata-only before/after action evidence, correlation fields and order lifecycle rollup registration without executing a business mutation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 77; }
	public function icon(): string { return 'clipboard-check'; }
	public function estimate_ms(): int { return 80; }
	public function precondition() {
		if ( ! class_exists( 'BizCity_Twin_Action_Evidence' ) ) {
			return 'Twin action evidence helper is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-13 01:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.6 — prove redacted before/after evidence and rollup contract without mutation or audit write.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/' : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$helper_file = $root . 'core/twin-core/includes/class-twin-action-evidence.php';
		$disk_ok = is_file( $helper_file ) && is_readable( $helper_file );
		$this->emit( $ctx, $steps, 'Disk - action evidence helper artifact', $disk_ok, $disk_ok ? 'Metadata-only evidence helper is readable.' : 'Action evidence helper artifact is missing or unreadable.' );

		$loader_ok = class_exists( 'BizCity_Twin_Action_Evidence' ) && method_exists( 'BizCity_Twin_Action_Evidence', 'build' ) && method_exists( 'BizCity_Twin_Action_Evidence', 'record' );
		$this->emit( $ctx, $steps, 'Loader - action evidence API', $loader_ok, $loader_ok ? 'Evidence build/record API is loaded before governed mutation consumers.' : 'Evidence API is incomplete.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'W8.6 action evidence loader failed.', 'error' => 'action_evidence_loader_failed', 'fix_hint' => 'Load the central action evidence helper before mutation consumers.', 'steps' => $steps );
		}

		$before = array( 'order_id' => 123, 'status' => 'pending', 'payment_token' => 'must-not-leak', 'customer_note' => 'private payload' );
		$after = array( 'order_id' => 123, 'status' => 'processing', 'payment_token' => 'must-not-leak', 'customer_note' => 'private payload' );
		$evidence = BizCity_Twin_Action_Evidence::build(
			array(
				'trace_id' => 'diag-w8-6-trace',
				'idempotency_key' => 'diag-w8-6-key',
				'action' => 'order.create',
				'resource' => array( 'type' => 'order', 'ref' => 'draft:123' ),
			),
			$before,
			$after,
			array( 'owner' => 'woo_order_adapter', 'outcome' => 'prepared', 'order_event_type' => 'order_created', 'context_status' => 'pending' )
		);
		$serialized = wp_json_encode( $evidence );
		$envelope_ok = (string) ( $evidence['contract'] ?? '' ) === 'business-action-evidence'
			&& (string) ( $evidence['action'] ?? '' ) === 'order.create'
			&& (string) ( $evidence['trace_id'] ?? '' ) === 'diag-w8-6-trace'
			&& (string) ( $evidence['idempotency_key'] ?? '' ) === 'diag-w8-6-key'
			&& (string) ( $evidence['resource_type'] ?? '' ) === 'order'
			&& (string) ( $evidence['resource_ref'] ?? '' ) === 'draft:123'
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $evidence['before_state_hash'] ?? '' ) )
			&& preg_match( '/^[a-f0-9]{64}$/', (string) ( $evidence['after_state_hash'] ?? '' ) )
			&& ! empty( $evidence['changed'] )
			&& false === strpos( (string) $serialized, 'must-not-leak' )
			&& false === strpos( (string) $serialized, 'private payload' )
			&& ! array_key_exists( 'before_state', $evidence )
			&& ! array_key_exists( 'after_state', $evidence );
		$this->emit( $ctx, $steps, 'Runtime - redacted before/after evidence envelope', $envelope_ok, $envelope_ok ? 'Hashes and correlation are present; state payload is absent and no audit write was performed.' : 'Evidence envelope is incomplete or contains protected state.' );

		$rollup_ok = class_exists( 'BizCity_Context_Bank_Rollup_Registry' )
			&& is_array( BizCity_Context_Bank_Rollup_Registry::get( 'order_lifecycle' ) );
		$this->emit( $ctx, $steps, 'Loader - order lifecycle rollup contract', $rollup_ok, $rollup_ok ? 'order_lifecycle is registered; producer/worker wiring remains a separate gate.' : 'order_lifecycle rollup is not registered.' );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$passed = false;
				break;
			}
		}
		return array(
			'status' => $passed ? 'pass' : 'fail',
			'summary' => $passed ? 'W8.6 metadata-only action evidence contract passed; mutation and rollup producer remain gated.' : 'W8.6 action evidence contract failed.',
			'error' => $passed ? '' : 'action_evidence_contract_failed',
			'fix_hint' => $passed ? '' : 'Check the redacted before/after envelope and order_lifecycle rollup registration before wiring a governed mutation.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_Action_Evidence';
	return $probes;
} );
