<?php
/**
 * W8.3 provider-neutral fulfillment contract probe.
 *
 * This probe deliberately does not register a fake provider or call a real
 * fulfillment service. It proves the no-provider boundary stays fail-closed.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-13 (PHASE-0.41-W8.3)
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
if ( class_exists( 'BizCity_Probe_TwinWeb_Fulfillment_Contract', false ) ) { return; }

final class BizCity_Probe_TwinWeb_Fulfillment_Contract implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.employee_fulfillment'; }
	public function label(): string { return 'Twin GPT fulfillment contract'; }
	public function description(): string { return 'Verifies the provider-neutral quote/create/track/ETA contract and fail-closed no-provider boundary without shipping mutation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 76; }
	public function icon(): string { return 'truck'; }
	public function estimate_ms(): int { return 80; }
	public function precondition() {
		if ( ! class_exists( 'BizCity_CRM_Fulfillment_Adapter_Registry' ) || ! interface_exists( 'BizCity_CRM_Fulfillment_Adapter_Interface' ) ) {
			return 'CRM fulfillment interface or registry is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-13 12:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.3 — prove no-provider fulfillment remains unavailable without fake transport or shipping mutation.
		$steps = array();
		$contract_file = dirname( __DIR__, 4 ) . '/plugins/bizcity-twin-crm/includes/class-fulfillment-adapter.php';
		$disk_ok = is_file( $contract_file ) && is_readable( $contract_file );
		$this->emit( $ctx, $steps, 'Disk - fulfillment contract artifact', $disk_ok, $disk_ok ? 'Provider-neutral interface and registry artifact is readable.' : 'Fulfillment contract artifact is missing or unreadable.' );

		$loader_ok = interface_exists( 'BizCity_CRM_Fulfillment_Adapter_Interface' ) && class_exists( 'BizCity_CRM_Fulfillment_Adapter_Registry' );
		$this->emit( $ctx, $steps, 'Loader - fulfillment interface and registry', $loader_ok, $loader_ok ? 'Interface and registry are loaded before the C action surface.' : 'Fulfillment interface or registry is not loaded.' );

		$catalog = BizCity_CRM_Fulfillment_Adapter_Registry::catalog();
		$available = BizCity_CRM_Fulfillment_Adapter_Registry::available();
		$default = BizCity_CRM_Fulfillment_Adapter_Registry::default_adapter();
		$unknown = BizCity_CRM_Fulfillment_Adapter_Registry::get( '__diag_unknown_fulfillment_provider__' );
		$runtime_ok = empty( $catalog ) && empty( $available ) && null === $default && null === $unknown;
		$this->emit( $ctx, $steps, 'Runtime - no-provider fail-closed boundary', $runtime_ok, $runtime_ok ? 'No provider is registered: catalog, available and default adapter remain empty; unknown lookup is rejected without transport or mutation.' : 'A fulfillment provider became available or unknown lookup did not fail closed.' );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$passed = false;
				break;
			}
		}
		return array(
			'status' => $passed ? 'pass' : 'fail',
			'summary' => $passed ? 'W8.3 provider-neutral fulfillment contract passed with no provider enabled.' : 'W8.3 fulfillment contract boundary failed.',
			'error' => $passed ? '' : 'fulfillment_contract_failed',
			'fix_hint' => $passed ? '' : 'Check the fulfillment interface/registry loader and keep unsupported shipping operations fail-closed until a verified provider adapter exists.',
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
	$probes[] = 'BizCity_Probe_TwinWeb_Fulfillment_Contract';
	return $probes;
} );
