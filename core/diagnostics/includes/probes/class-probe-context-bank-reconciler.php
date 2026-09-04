<?php
/**
 * DDV probe for bounded Context Bank file-to-ledger reconciliation.
 *
 * The runtime assertions use scope and checkpoint fixtures only. They do not
 * append business data, provision schema or advance a production cursor.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Reconciler', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Reconciler implements BizCity_Diagnostics_Probe {

	public function id(): string {
		return 'core.context_bank.reconciler';
	}

	public function label(): string {
		return 'Context Bank - bounded reconciler';
	}

	public function description(): string {
		return 'Checks tenant-bound reconciliation scope, signed checkpoint rejection and fail-closed cursor persistence.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 83; }
	public function icon(): string { return 'refresh'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Reconciler' ) ) {
			return new WP_Error( 'context_bank_reconciler_missing', 'Context Bank reconciler is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-CB3.4-DDV — prove reconciliation failure paths without durable business mutation.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$reconciler_file = $root . 'core/context-bank/includes/class-context-bank-reconciler.php';
		$source = is_readable( $reconciler_file ) ? file_get_contents( $reconciler_file ) : '';
		$disk_ok = is_string( $source ) && $source !== '';
		$loader_ok = class_exists( 'BizCity_Context_Bank_Reconciler' )
			&& method_exists( 'BizCity_Context_Bank_Reconciler', 'run_batch' )
			&& method_exists( 'BizCity_Context_Bank_Reconciler', 'validate_checkpoint' );
		$failure_path_ok = is_string( $source )
			&& strpos( $source, "'source_cursor_regressed'" ) !== false
			&& strpos( $source, "'reconcile_checkpoint_persist_failed'" ) !== false
			&& strpos( $source, "'reconcile_reader_exception'" ) !== false
			&& strpos( $source, "'reconcile_ledger_exception'" ) !== false
			&& strpos( $source, 'if ( ! self::save_checkpoint( $run_id, $next ) )' ) !== false
			&& strpos( $source, 'reconcile_checkpoint_advanced' ) !== false;

		$invalid_scope = BizCity_Context_Bank_Reconciler::run_batch( '', 'core.knowledge.user_memory', 'not-a-date.txt', array(), 1, 50 );
		$invalid_scope_ok = is_array( $invalid_scope )
			&& empty( $invalid_scope['ok'] )
			&& (string) ( $invalid_scope['reason'] ?? '' ) === 'reconcile_scope_invalid'
			&& empty( $invalid_scope['next_checkpoint'] );

		$checkpoint_ok = false;
		$checkpoint_tamper_ok = false;
		if ( function_exists( 'get_current_blog_id' ) && (int) get_current_blog_id() > 0
			&& class_exists( 'BizCity_File_Contract_Registry' )
			&& BizCity_File_Contract_Registry::has( 'core.knowledge.user_memory' ) ) {
			$file = gmdate( 'Y-m-d' ) . '.jsonl';
			$checkpoint = BizCity_Context_Bank_Reconciler::make_checkpoint( 'diagnostics_reconciler', 'core.knowledge.user_memory', $file );
			$checkpoint_result = BizCity_Context_Bank_Reconciler::validate_checkpoint( $checkpoint, 'core.knowledge.user_memory', $file );
			$checkpoint_ok = is_array( $checkpoint_result ) && ! empty( $checkpoint_result['ok'] );
			$tampered = $checkpoint;
			$tampered['byte_offset'] = (int) $tampered['byte_offset'] + 1;
			$tampered_result = BizCity_Context_Bank_Reconciler::validate_checkpoint( $tampered, 'core.knowledge.user_memory', $file );
			$checkpoint_tamper_ok = is_array( $tampered_result ) && empty( $tampered_result['ok'] ) && (string) ( $tampered_result['reason'] ?? '' ) === 'checkpoint_signature_invalid';
		}

		$steps = array(
			array( 'label' => 'Disk - reconciler artifact is readable', 'ok' => $disk_ok, 'detail' => $disk_ok ? 'Canonical reconciler source is readable.' : 'Reconciler source is missing or unreadable.' ),
			array( 'label' => 'Loader - bounded reconciliation API is loaded', 'ok' => $loader_ok, 'detail' => $loader_ok ? 'run_batch() and checkpoint validation are available.' : 'Reconciler class or required methods are unavailable.' ),
			array( 'label' => 'Loader - failure paths guard checkpoint advancement', 'ok' => $failure_path_ok, 'detail' => $failure_path_ok ? 'Cursor, reader, ledger and checkpoint failures have explicit bounded buckets.' : 'A reconciliation failure path can report success without cursor evidence.' ),
			array( 'label' => 'Runtime - malformed scope fails closed', 'ok' => $invalid_scope_ok, 'detail' => $invalid_scope_ok ? 'Invalid run/file scope returns without creating checkpoint state.' : 'Malformed scope was accepted or produced resumable state.' ),
			array( 'label' => 'Runtime - signed checkpoint validates', 'ok' => $checkpoint_ok, 'detail' => $checkpoint_ok ? 'Current-tenant checkpoint signature and contract scope validate.' : 'A valid current-tenant checkpoint could not be validated.' ),
			array( 'label' => 'Runtime - tampered checkpoint is refused', 'ok' => $checkpoint_tamper_ok, 'detail' => $checkpoint_tamper_ok ? 'Changing the cursor without resigning is rejected.' : 'Tampered checkpoint state was accepted.' ),
		);
		$pass = true;
		foreach ( $steps as $step ) {
			$pass = $pass && ! empty( $step['ok'] );
			$ctx->emit_step( array( 'label' => $step['label'], 'status' => $step['ok'] ? 'pass' : 'fail', 'detail' => $step['detail'] ) );
		}
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Context Bank reconciler passed bounded scope and checkpoint failure-path checks.' : 'Context Bank reconciler failure-path checks failed.', 'fix_hint' => $pass ? '' : 'Keep reconciliation tenant-bound and refuse cursor advancement unless checkpoint persistence is confirmed.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Reconciler';
	return $list;
} );
