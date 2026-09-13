<?php
/**
 * W8.4 prepare-only confirmation boundary probe.
 *
 * Exercises the generic confirmation helper and TwinWeb prepare endpoint
 * without creating an order, payment artifact or shipping side effect.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-13 (PHASE-0.41-W8.4)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) { require_once $_bizcity_safe_loader; }
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) { return; }
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) { return; }
if ( class_exists( 'BizCity_Probe_TwinWeb_Order_Confirmation', false ) ) { return; }

final class BizCity_Probe_TwinWeb_Order_Confirmation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'modules.twin_gpt.crm_order_confirmation'; }
	public function label(): string { return 'Twin GPT governed order confirmation'; }
	public function description(): string { return 'Verifies prepare-only confirmation token binding, one-time consumption and fail-closed replay/foreign identity behavior without commerce mutation.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 75; }
	public function icon(): string { return 'shield-check'; }
	public function estimate_ms(): int { return 120; }
	public function precondition() {
		return class_exists( 'BizCity_Twin_Action_Confirmation' ) ? true : 'Generic Twin action confirmation helper is not loaded.';
	}

	public function run( $ctx ): array {
		// [2026-09-13 10:30 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W8.4 — prove confirmation binding and one-time consume without enabling commerce mutations.
		$steps = array();
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) { return array( 'status' => 'skip', 'summary' => 'Authenticated diagnostics user is required.', 'fix_hint' => 'Run the focused probe with an authenticated operator.', 'steps' => array() ); }
		$hash = hash( 'sha256', 'diag-order-confirmation-' . wp_generate_uuid4() );
		$resource = 'conversation:0:contact:0';
		$issued = BizCity_Twin_Action_Confirmation::issue( 'order.create', $resource, $hash, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ) );
		$issued_ok = ! empty( $issued['confirmation_token'] ) && (string) ( $issued['action'] ?? '' ) === 'order.create' && (string) ( $issued['request_hash'] ?? '' ) === $hash;
		$this->emit( $ctx, $steps, 'Runtime - issue bound prepare token', $issued_ok, $issued_ok ? 'Token carries action/resource hash metadata and no commerce side effect.' : 'Confirmation issue response is incomplete.' );
		if ( ! $issued_ok ) { return array( 'status' => 'fail', 'summary' => 'W8.4 confirmation issue failed.', 'error' => 'confirmation_issue_failed', 'fix_hint' => 'Check the generic Twin confirmation helper binding and TTL.', 'steps' => $steps ); }

		$token = (string) $issued['confirmation_token'];
		$first = BizCity_Twin_Action_Confirmation::consume( $token, 'order.create', $resource, $hash, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ) );
		$first_ok = true === $first;
		$this->emit( $ctx, $steps, 'Runtime - first consume succeeds', $first_ok, $first_ok ? 'Exact action/resource/hash/user consumed the token once.' : 'Exact confirmation token was rejected.' );

		$replay = BizCity_Twin_Action_Confirmation::consume( $token, 'order.create', $resource, $hash, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ) );
		$replay_ok = is_wp_error( $replay ) && 'confirmation_invalid' === $replay->get_error_code();
		$this->emit( $ctx, $steps, 'Runtime - replay consume is rejected', $replay_ok, $replay_ok ? 'One-time token cannot be consumed twice.' : 'Confirmation replay was not rejected.' );

		$foreign = BizCity_Twin_Action_Confirmation::issue( 'order.create', $resource, $hash, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ) );
		$foreign_result = BizCity_Twin_Action_Confirmation::consume( (string) $foreign['confirmation_token'], 'order.create', $resource, $hash, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id + 1 ) );
		$foreign_ok = is_wp_error( $foreign_result ) && 'confirmation_invalid' === $foreign_result->get_error_code();
		$this->emit( $ctx, $steps, 'Runtime - foreign user consume is rejected', $foreign_ok, $foreign_ok ? 'Token is bound to the issuing user and tenant.' : 'Foreign user was able to consume the token.' );
		BizCity_Twin_Action_Confirmation::consume( (string) $foreign['confirmation_token'], 'order.create', $resource, $hash, array( 'blog_id' => (int) get_current_blog_id(), 'user_id' => $user_id ) );

		$passed = true;
		foreach ( $steps as $step ) { if ( 'fail' === (string) ( $step['status'] ?? '' ) ) { $passed = false; break; } }
		return array( 'status' => $passed ? 'pass' : 'fail', 'summary' => $passed ? 'W8.4 prepare-only confirmation boundary passed.' : 'W8.4 confirmation boundary failed.', 'error' => $passed ? '' : 'order_confirmation_contract_failed', 'fix_hint' => $passed ? '' : 'Inspect token binding, one-time consumption and user/tenant scope.', 'steps' => $steps );
	}

	public function cleanup(): void {}

	private function emit( $ctx, array &$steps, string $label, bool $ok, string $detail ): void {
		$step = array( 'label' => $label, 'status' => $ok ? 'pass' : 'fail', 'detail' => $detail );
		$steps[] = $step;
		$ctx->emit_step( $step );
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_TwinWeb_Order_Confirmation';
	return $probes;
} );
