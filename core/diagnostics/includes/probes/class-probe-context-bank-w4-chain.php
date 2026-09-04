<?php
/**
 * DDV probe for the W4 Context Bank chain boundary.
 *
 * This probe validates owner wiring only. The two-tenant archive-to-MPR chain
 * remains a separate runtime gate and is never inferred from class existence.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_W4_Chain', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_W4_Chain implements BizCity_Diagnostics_Probe {

	public function id(): string { return 'core.context_bank.w4_chain'; }
	public function label(): string { return 'Context Bank - W4 owner chain'; }
	public function description(): string { return 'Checks W4 archive, ledger, rollup, KG and MPR owner wiring while keeping two-tenant runtime evidence explicit.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 77; }
	public function icon(): string { return 'git-branch'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() { return true; }

	public function run( $ctx ): array {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB-W4-DDV — prove W4 owner artifacts and report the two-tenant runtime gate separately.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$files = array(
			'archive' => $root . 'core/context-bank/includes/class-context-bank-channel-archive-adapter.php',
			'ledger' => $root . 'core/context-bank/includes/class-context-bank-ledger.php',
			'rollup' => $root . 'core/context-bank/includes/class-context-bank-rollup-engine.php',
			'worker' => $root . 'core/context-bank/includes/class-context-bank-rollup-worker.php',
			'kg' => $root . 'core/context-bank/includes/class-context-bank-kg-bridge.php',
			'mpr' => $root . 'core/twinbrain/includes/class-twinbrain-notebook-source-layer.php',
		);
		$disk_ok = true;
		foreach ( $files as $file ) {
			$disk_ok = $disk_ok && is_readable( $file );
		}
		$loader_ok = class_exists( 'BizCity_Context_Bank_Channel_Archive_Adapter' )
			&& class_exists( 'BizCity_Context_Bank_Ledger' )
			&& class_exists( 'BizCity_Context_Bank_Rollup_Engine' )
			&& class_exists( 'BizCity_Context_Bank_Rollup_Worker' )
			&& class_exists( 'BizCity_Context_Bank_KG_Bridge' )
			&& class_exists( 'BizCity_TwinBrain_Notebook_Source_Layer' );
		$source = file_get_contents( $files['mpr'] );
		$mpr_contract_ok = is_string( $source ) && strpos( $source, 'context_bank_source_refs' ) !== false && strpos( $source, 'context-retrieval-pack@1.0.0' ) !== false;
		$runtime_status = 'deferred';
		$runtime_detail = 'Two-tenant/two-account archive -> ledger -> rollup -> KG provenance -> MPR evidence is not executed by this structural probe.';
		foreach ( array(
			array( 'label' => 'Disk - W4 owner artifacts are readable', 'status' => $disk_ok ? 'pass' : 'fail', 'detail' => $disk_ok ? 'Archive, ledger, rollup, worker, KG and MPR artifacts are readable.' : 'One or more W4 owner artifacts are missing or unreadable.' ),
			array( 'label' => 'Loader - W4 owners are loaded', 'status' => $loader_ok ? 'pass' : 'fail', 'detail' => $loader_ok ? 'W4 owner classes are available through their module boundaries.' : 'One or more W4 owner classes are unavailable.' ),
			array( 'label' => 'Contract - MPR retrieval envelope is present', 'status' => $mpr_contract_ok ? 'pass' : 'fail', 'detail' => $mpr_contract_ok ? 'W0.20 exposes Context Bank refs with a versioned retrieval-pack marker.' : 'MPR retrieval-pack metadata contract is incomplete.' ),
			array( 'label' => 'Runtime - two-tenant W4 chain', 'status' => $runtime_status, 'detail' => $runtime_detail ),
		) as $step ) {
			$ctx->emit_step( $step );
		}
		$pass = $disk_ok && $loader_ok && $mpr_contract_ok;
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'W4 owner chain wiring passed; two-tenant runtime remains deferred.' : 'W4 owner chain wiring is incomplete.', 'fix_hint' => $pass ? 'Run the approved two-tenant fixture and preserve run_id, blog_id, account scope and per-probe evidence.' : 'Restore the missing W4 owner artifact or retrieval contract before attempting the two-tenant canary.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_W4_Chain';
	return $list;
} );