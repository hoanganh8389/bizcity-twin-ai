<?php
/**
 * Context Bank server-owned scope resolver probe.
 *
 * No storage query or provider call is performed.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Scope', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Scope implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB7.1-DDV — expose server-owned scope evidence.
		return 'core.context_bank.scope';
	}

	public function label(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB7.1-DDV — label scope authorization probe.
		return 'Context Bank - server-owned scope';
	}

	public function description(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB7.1-DDV — describe posted-owner rejection and bounded policy output.
		return 'Checks that retrieval scope uses current server tenant/user, ignores posted owner hints, and emits bounded private-memory policy metadata.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 73; }
	public function icon(): string { return 'shield'; }
	public function estimate_ms(): int { return 80; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Scope_Resolver' ) ) {
			return new WP_Error( 'context_bank_scope_missing', 'Context Bank scope resolver is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-CB7.1-DDV — prove untrusted owner hints do not expand the server-owned retrieval scope.
		$resolved = BizCity_Context_Bank_Scope_Resolver::resolve( array( 'user_id' => 999999, 'blog_id' => 999999, 'mode' => 'hybrid', 'channel' => 'twinchat' ) );
		$current_user = (int) get_current_user_id();
		$current_blog = (int) get_current_blog_id();
		$owner_ok = $current_user > 0 ? (int) ( $resolved['owner_user_id'] ?? 0 ) === $current_user : (string) ( $resolved['effective_mode'] ?? '' ) === 'skip';
		$tenant_ok = (int) ( $resolved['blog_id'] ?? 0 ) === $current_blog;
		$budget_ok = (int) ( $resolved['budgets']['max_rows'] ?? 0 ) === 50 && (int) ( $resolved['budgets']['max_pointer_follows'] ?? 0 ) === 10;
		$checks = array(
			array( 'label' => 'Posted owner ignored', 'ok' => $owner_ok, 'detail' => 'Scope owner is derived from the current authenticated user.' ),
			array( 'label' => 'Current tenant enforced', 'ok' => $tenant_ok, 'detail' => 'Scope blog_id is derived from the current tenant context.' ),
			array( 'label' => 'Retrieval budgets bounded', 'ok' => $budget_ok, 'detail' => 'Rows, pointer follows and time/decryption budgets are explicit.' ),
		);
		$pass = true;
		foreach ( $checks as $check ) {
			$step = array( 'label' => $check['label'], 'status' => $check['ok'] ? 'pass' : 'fail', 'detail' => $check['detail'] );
			$ctx->emit_step( $step );
			$pass = $pass && $check['ok'];
		}
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Server-owned Context Bank scope passed tenant, owner and budget checks.' : 'Context Bank scope resolver failed.', 'fix_hint' => $pass ? '' : 'Check current tenant/user resolution and bounded policy budgets.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Scope';
	return $list;
} );