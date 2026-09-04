<?php
/**
 * DDV probe for missing gateway credential degradation.
 *
 * The option filter is request-local and read-only. This probe never writes an
 * option and never calls a provider; production credential/runtime smoke is a
 * separate evidence class.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since 2026-09-02 (PHASE-0.41-CRM-ONE-BRAIN)
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	$_bizcity_safe_loader = dirname( __DIR__, 4 ) . '/core/helper/class-bizcity-safe-loader.php';
	if ( is_file( $_bizcity_safe_loader ) && is_readable( $_bizcity_safe_loader ) ) {
		require_once $_bizcity_safe_loader;
	}
	unset( $_bizcity_safe_loader );
}
if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
	return;
}
if ( ! class_exists( 'BizCity_LLM_Client', false ) ) {
	BizCity_Safe_Loader::require_file( dirname( __DIR__, 3 ) . '/bizcity-llm/includes/class-llm-client.php', 'bizcity_llm.client' );
}
if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false )
	&& ! BizCity_Safe_Loader::require_file( dirname( __DIR__ ) . '/interface-diagnostics-probe.php', 'diagnostics.probe_interface' ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Framework_Credential_Degradation', false ) ) {
	return;
}

final class BizCity_Probe_Framework_Credential_Degradation implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.framework.credential_degradation'; }
	public function label(): string { return 'Framework missing credential degradation'; }
	public function description(): string { return 'Kiểm tra thiếu gateway credential trả trạng thái degraded mà không gọi provider hoặc gây fatal.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 22; }
	public function icon(): string { return 'shield-alert'; }
	public function estimate_ms(): int { return 100; }
	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-0.41-W6 — verify request-local missing-credential degradation without option writes or provider calls.
		$steps = array();
		$loader_ok = class_exists( 'BizCity_LLM_Client', false ) && method_exists( 'BizCity_LLM_Client', 'instance' ) && method_exists( 'BizCity_LLM_Client', 'is_ready' );
		$steps[] = array(
			'label'  => 'Loader - gateway client readiness contract is loaded',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'BizCity_LLM_Client readiness is available without loading a provider.' : 'Gateway client readiness contract is unavailable.',
		);
		if ( ! $loader_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Gateway client readiness contract is unavailable.', 'fix_hint' => 'Load the client through Safe Loader before running the credential degradation probe.', 'steps' => $steps );
		}

		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'pre_option_bizcity_llm_api_key', array( 'BizCity_Probe_Framework_Credential_Degradation', 'empty_key' ), 999, 3 );
		}
		$ready = null;
		$threw = false;
		try {
			$ready = BizCity_LLM_Client::instance()->is_ready();
		} catch ( \Throwable $e ) {
			$threw = true;
		}
		if ( function_exists( 'remove_filter' ) ) {
			remove_filter( 'pre_option_bizcity_llm_api_key', array( 'BizCity_Probe_Framework_Credential_Degradation', 'empty_key' ), 999 );
		}
		$degraded_ok = ! $threw && false === $ready;
		$steps[] = array(
			'label'  => 'Runtime - missing credential degrades without fatal or provider call',
			'status' => $degraded_ok ? 'pass' : 'fail',
			'detail' => $degraded_ok ? 'Request-local empty-key simulation returned is_ready=false; no provider request was made.' : 'Missing credential readiness did not fail closed.',
		);

		return array(
			'status'  => $degraded_ok ? 'pass' : 'fail',
			'summary' => $degraded_ok ? 'Missing credential degradation passed as synthetic local evidence.' : 'Missing credential degradation failed.',
			'fix_hint'=> $degraded_ok ? '' : 'Keep missing gateway credentials fail-closed and provider-free; rerun this focused probe.',
			'steps'   => $steps,
		);
	}

	public static function empty_key( $value, $option, $default ) {
		return '';
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $probes ) {
	$probes[] = 'BizCity_Probe_Framework_Credential_Degradation';
	return $probes;
} );
