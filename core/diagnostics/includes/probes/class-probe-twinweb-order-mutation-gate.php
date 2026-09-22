<?php
/**
 * W8.7 C order-mutation fail-closed route gate.
 *
 * This probe verifies that Twin GPT does not expose a create-order route while
 * the governed confirmation, idempotency, evidence, lifecycle and notification
 * owner is incomplete. It does not create a fixture or call WooCommerce.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-13 (PHASE-0.41-W8.7)
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
if ( class_exists( 'BizCity_Probe_TwinWeb_Order_Mutation_Gate', false ) ) { return; }

final class BizCity_Probe_TwinWeb_Order_Mutation_Gate implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.employee_order_mutation_gate'; }
	public function label(): string { return 'Twin GPT order mutation gate'; }
	public function description(): string { return 'Verifies that the C surface remains read/draft/prepare-only until a governed order.create owner exists; no WooCommerce or provider side effect is executed.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 78; }
	public function icon(): string { return 'shield-off'; }
	public function estimate_ms(): int { return 80; }
	public function precondition() {
		if ( ! class_exists( 'BizCity_TwinWeb_REST' ) || ! method_exists( 'BizCity_TwinWeb_REST', 'register_routes' ) ) {
			return 'TwinWeb REST owner is not loaded.';
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-13 02:00 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.7 — prove the C order mutation route remains absent until all governed owners are ready.
		$steps = array();
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? rtrim( (string) BIZCITY_TWIN_AI_DIR, '/\\' ) . '/' : dirname( dirname( dirname( dirname( __DIR__ ) ) ) ) . '/';
		$rest_file = $root . 'modules/twinweb/includes/class-twinweb-rest.php';
		$disk_ok = is_file( $rest_file ) && is_readable( $rest_file );
		$this->emit( $ctx, $steps, 'Disk - TwinWeb REST owner', $disk_ok, $disk_ok ? 'TwinWeb REST owner is readable.' : 'TwinWeb REST owner is missing or unreadable.' );

		$loader_ok = class_exists( 'BizCity_TwinWeb_REST' ) && method_exists( 'BizCity_TwinWeb_REST', 'register_routes' );
		$this->emit( $ctx, $steps, 'Loader - TwinWeb REST owner', $loader_ok, $loader_ok ? 'TwinWeb REST route owner is loaded.' : 'TwinWeb REST route owner is not loaded.' );
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'W8.7 order mutation gate loader failed.', 'error' => 'order_mutation_gate_loader_failed', 'fix_hint' => 'Load the TwinWeb REST owner before checking the C mutation boundary.', 'steps' => $steps );
		}

		// [2026-09-13 02:15 PM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.7 — register only the TwinWeb route owner instead of replaying the whole REST bootstrap and unrelated schema installers.
		BizCity_TwinWeb_REST::instance()->register_routes();
		$routes = function_exists( 'rest_get_server' ) ? (array) rest_get_server()->get_routes() : array();
		$confirmation_route = '/bizcity-twinweb/v1/crm/inbox/conversations/(?P<id>\\d+)/order-draft/confirmation';
		$create_route = '/bizcity-twinweb/v1/crm/inbox/conversations/(?P<id>\\d+)/order-create';
		$confirmation_ok = isset( $routes[ $confirmation_route ] );
		$create_absent = ! isset( $routes[ $create_route ] );
		$this->emit( $ctx, $steps, 'Runtime - prepare route exists and create route is absent', $confirmation_ok && $create_absent, $confirmation_ok && $create_absent ? 'Prepare-only confirmation is exposed; C order.create route remains fail-closed.' : 'The C route inventory does not match the read/prepare-only boundary.' );

		$passed = true;
		foreach ( $steps as $step ) {
			if ( 'fail' === (string) ( $step['status'] ?? '' ) ) {
				$passed = false;
				break;
			}
		}
		return array(
			'status' => $passed ? 'pass' : 'fail',
			'summary' => $passed ? 'W8.7 C order mutation remains fail-closed until the governed owner is ready.' : 'W8.7 C order mutation gate failed.',
			'error' => $passed ? '' : 'order_mutation_gate_failed',
			'fix_hint' => $passed ? '' : 'Keep order.create disabled and inspect TwinWeb route registration before enabling a governed mutation owner.',
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
	$probes[] = 'BizCity_Probe_TwinWeb_Order_Mutation_Gate';
	return $probes;
} );
