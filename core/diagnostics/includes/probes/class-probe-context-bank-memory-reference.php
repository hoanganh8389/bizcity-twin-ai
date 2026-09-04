<?php
/**
 * DDV probe for the five memory-family Context Bank references.
 *
 * Writes synthetic encrypted records, admits their receipts into the tenant
 * pointer ledger, follows them through the shared adapter, and tombstones them.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe', false ) ) {
	return;
}
if ( class_exists( 'BizCity_Probe_Context_Bank_Memory_Reference', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Memory_Reference implements BizCity_Diagnostics_Probe {

	private $records = array();

	public function id(): string { return 'core.context_bank.memory_reference'; }
	public function label(): string { return 'Context Bank - five memory references'; }
	public function description(): string { return 'Verifies all five encrypted memory contracts admit receipts, follow verified pointers, and invalidate them with receipt-bearing tombstones.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 69; }
	public function icon(): string { return 'database'; }
	public function estimate_ms(): int { return 800; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_File_Contract_Registry' ) ) {
			return new WP_Error( 'memory_filestore_missing', 'Encrypted memory filestore is not loaded.' );
		}
		if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
			bizcity_context_bank_load_memory_runtime();
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Memory_Adapter' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return new WP_Error( 'memory_adapter_missing', 'Context Bank memory adapter is not loaded.' );
		}
		if ( get_current_user_id() <= 0 ) {
			return new WP_Error( 'admin_required', 'The memory reference probe requires a logged-in user.' );
		}
		if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( BizCity_Context_Bank_Ledger::table() ) ) {
			return new WP_Error( 'ledger_not_provisioned', 'Context Bank ledger is not provisioned on the current tenant shard.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-01 Johnny Chu] PHASE-CB4.5 — exercise every memory contract through receipt admission, pointer follow and tombstone invalidation.
		$steps = array();
		$pass = true;
		$blog_id = (int) get_current_blog_id();
		$user_id = (int) get_current_user_id();
		$families = BizCity_Context_Bank_Memory_Adapter::contracts();
		foreach ( $families as $memory_class => $contract_id ) {
			$record_id = 'cb_mem_probe_' . sanitize_key( $memory_class ) . '_' . substr( md5( $blog_id . '|' . $user_id . '|' . $memory_class . '|' . microtime( true ) ), 0, 16 );
			$record = array(
				'record_id' => $record_id,
				'blog_id' => $blog_id,
				'user_id' => $user_id,
				'identity_uuid' => 'probe-' . $user_id,
				'session_id' => 'cb-memory-probe',
				'memory_class' => $memory_class,
				'memory_key' => 'probe:' . $memory_class,
				'memory_text' => 'Context Bank memory reference probe.',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			);
			$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( $contract_id, $record, 'upsert' );
			$admission = is_array( $receipt ) ? BizCity_Context_Bank_Memory_Adapter::admit( $memory_class, array(
				'user_id' => $user_id,
				'identity_uuid' => 'probe-' . $user_id,
				'receipt' => $receipt,
			) ) : array( 'ok' => false, 'reason' => 'filestore_write_failed' );
			$rows = ! empty( $admission['ok'] ) ? BizCity_Context_Bank_Memory_Adapter::query( $contract_id, array( 'blog_id' => $blog_id, 'user_id' => $user_id, 'record_id' => $record_id, 'limit' => 2 ) ) : array();
			$ok = is_array( $receipt ) && ! empty( $admission['ok'] ) && count( $rows ) === 1;
			$step = array( 'label' => 'Memory reference: ' . $memory_class, 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'receipt admitted and verified pointer followed.' : (string) ( $admission['reason'] ?? 'memory reference query failed.' ) );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
			$this->records[] = array( 'memory_class' => $memory_class, 'contract_id' => $contract_id, 'record_id' => $record_id, 'receipt' => $receipt );
		}

		foreach ( $this->records as $item ) {
			if ( ! is_array( $item['receipt'] ?? null ) ) {
				continue;
			}
			$delete_receipt = BizCity_Business_JSONL_File_Store::delete_with_receipt( $item['contract_id'], $item['record_id'], array( 'blog_id' => $blog_id, 'user_id' => $user_id ) );
			$tombstone = is_array( $delete_receipt ) ? BizCity_Context_Bank_Memory_Adapter::tombstone( $item['memory_class'], array( 'user_id' => $user_id, 'identity_uuid' => 'probe-' . $user_id, 'receipt' => $delete_receipt ) ) : array( 'ok' => false );
			$ok = is_array( $delete_receipt ) && ! empty( $tombstone['ok'] );
			$step = array( 'label' => 'Memory tombstone: ' . $item['memory_class'], 'status' => $ok ? 'pass' : 'fail', 'detail' => $ok ? 'delete receipt admitted and pointer invalidated.' : (string) ( $tombstone['reason'] ?? 'tombstone admission failed.' ) );
			$steps[] = $step;
			$ctx->emit_step( $step );
			$pass = $pass && $ok;
		}

		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Five memory contracts admit and follow Context Bank references with tombstones.' : 'One or more memory contracts failed Context Bank reference admission or follow.', 'steps' => $steps );
	}

	public function cleanup(): void {
		foreach ( $this->records as $item ) {
			if ( is_array( $item['receipt'] ?? null ) ) {
				BizCity_Business_JSONL_File_Store::delete( $item['contract_id'], $item['record_id'], array( 'blog_id' => get_current_blog_id(), 'user_id' => get_current_user_id() ) );
			}
		}
		$this->records = array();
	}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Memory_Reference';
	return $list;
} );
