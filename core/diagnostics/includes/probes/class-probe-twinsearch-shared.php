<?php
/**
 * BizCity Diagnostics — shared Core TwinSearch probe.
 *
 * R-DDV layers: Disk, Loader, Runtime contract.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 * @since      2026-07-14
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/interface-diagnostics-probe.php';

if ( class_exists( 'BizCity_Probe_TwinSearch_Shared', false ) ) {
	return;
}

final class BizCity_Probe_TwinSearch_Shared implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.twinsearch.shared'; }
	public function label(): string { return 'Core TwinSearch · Shared document search'; }
	public function description(): string { return 'Verifies shared notebook/user/blog/character document-search core used by TwinChat and planned TwinWeb.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 74; }
	public function icon(): string { return 'search'; }
	public function estimate_ms(): int { return 20; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-07-14 Johnny Chu] PHASE-0.43 — DDV evidence for shared TwinSearch core.
		$steps = array();
		$core_file = dirname( dirname( dirname( __DIR__ ) ) ) . '/twinsearch/includes/class-twinsearch-core.php';
		$disk_ok = is_readable( $core_file );
		$step = array(
			'label'  => 'Disk · core/twinsearch engine',
			'status' => $disk_ok ? 'pass' : 'fail',
			'detail' => $disk_ok ? 'class-twinsearch-core.php readable' : 'shared engine file missing',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$loader_ok = class_exists( 'BizCity_TwinSearch_Core' )
			&& method_exists( 'BizCity_TwinSearch_Core', 'search_documents' )
			&& method_exists( 'BizCity_TwinSearch_Core', 'resolve_scope' )
			&& method_exists( 'BizCity_TwinSearch_Core', 'search_document_matches' );
		$step = array(
			'label'  => 'Loader · shared class contract',
			'status' => $loader_ok ? 'pass' : 'fail',
			'detail' => $loader_ok ? 'class + search_documents + resolve_scope + search_document_matches loaded' : 'shared class contract unavailable',
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$runtime_ok = false;
		$runtime_detail = 'loader unavailable';
		if ( $loader_ok ) {
			$result = BizCity_TwinSearch_Core::instance()->search_documents( array(
				'query'    => '',
				'scope'    => 'notebook',
				'page'     => 1,
				'per_page' => 20,
			) );
			$runtime_ok = is_array( $result )
				&& isset( $result['results'], $result['notebook_ids'], $result['tokens'] )
				&& is_array( $result['results'] );
			$runtime_detail = $runtime_ok ? 'empty-query contract returned canonical payload' : 'canonical result keys missing';
		}
		$step = array(
			'label'  => 'Runtime · canonical payload',
			'status' => $runtime_ok ? 'pass' : 'fail',
			'detail' => $runtime_detail,
		);
		$steps[] = $step;
		$ctx->emit_step( $step );

		$pass = $disk_ok && $loader_ok && $runtime_ok;
		return array(
			'status'   => $pass ? 'pass' : 'fail',
			'summary'  => $pass ? 'Shared TwinSearch core is ready.' : 'Shared TwinSearch core is incomplete.',
			'error'    => $pass ? '' : 'twinsearch_contract_failed',
			'fix_hint' => $pass ? '' : 'Check core/twinsearch bootstrap load order and class contract.',
			'steps'    => $steps,
		);
	}

	public function cleanup(): void { /* Read-only. */ }
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_TwinSearch_Shared';
	return $list;
} );
