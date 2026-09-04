<?php
/**
 * Context Bank ledger physical-shard and receipt round-trip probe.
 *
 * Verifies the pointer-only ledger on the current routed blog. It never
 * provisions schema itself; Site Provisioner owns that operation.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Ledger', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Ledger implements BizCity_Diagnostics_Probe {

	const CONTRACT_ID = 'core.knowledge.user_memory';
	const SENTINEL = '__healthtest_context_bank_ledger_lark21';

	public function id(): string {
		return 'core.context_bank.ledger';
	}

	public function label(): string {
		return 'Context Bank ledger - physical shard and receipt';
	}

	public function description(): string {
		return 'Checks the current-blog pointer ledger schema, receipt admission, idempotent lookup and tombstone round-trip without storing a memory payload in SQL.';
	}

	public function severity(): string {
		return 'critical';
	}

	public function order(): int {
		return 82;
	}

	public function icon(): string {
		return 'database';
	}

	public function estimate_ms(): int {
		return 800;
	}

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return new WP_Error( 'context_bank_ledger_missing', 'Context Bank ledger class is not loaded.' );
		}
		if ( ! class_exists( 'BizCity_File_Contract_Registry' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) ) {
			return new WP_Error( 'context_bank_filestore_missing', 'Business filestore contract/store is not loaded.' );
		}
		if ( ! BizCity_File_Contract_Registry::has( self::CONTRACT_ID ) ) {
			return new WP_Error( 'context_bank_source_contract_missing', 'Source memory filestore contract is not registered.' );
		}
		if ( ! class_exists( 'BizCity_Table_Metadata' ) ) {
			return new WP_Error( 'table_metadata_missing', 'Canonical table metadata helper is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		$steps = array();
		$blog_id = (int) get_current_blog_id();
		$table = BizCity_Context_Bank_Ledger::table();
		$emit = function ( $label, $status, $detail ) use ( $ctx, &$steps ) {
			$step = array( 'label' => $label, 'status' => $status, 'detail' => $detail );
			$steps[] = $step;
			$ctx->emit_step( $step );
		};

		if ( $blog_id <= 0 ) {
			return array( 'status' => 'fail', 'summary' => 'Current blog is not resolved.', 'fix_hint' => 'Run the probe only after tenant/blog routing is resolved.', 'steps' => $steps );
		}
		$route = BizCity_Context_Bank_Ledger::route_evidence();
		$route_ok = is_array( $route ) && ! empty( $route['ok'] );
		$emit( 'Runtime - current shard and keymeta route', $route_ok ? 'pass' : 'fail', $route_ok ? 'Tenant route verified before ledger SQL.' : 'Tenant route refused: ' . (string) ( $route['reason'] ?? 'unknown' ) );
		if ( ! $route_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Tenant route evidence is not verified.', 'fix_hint' => 'Resolve the mapped tenant shard and verify wp_keymeta.dbname before running the ledger probe.', 'steps' => $steps );
		}
		$table_exists = function_exists( 'bizcity_tbl_exists' ) && bizcity_tbl_exists( $table );
		$emit( 'Physical shard - ledger table exists', $table_exists ? 'pass' : 'skip', $table_exists ? $table . ' on blog ' . $blog_id : 'Ledger is not provisioned on the current shard; run Site Provisioner context_bank.' );
		if ( ! $table_exists ) {
			return array( 'status' => 'skip', 'summary' => 'Context Bank ledger is not provisioned on the current shard.', 'fix_hint' => 'Run Diagnostics -> Site Provisioner -> context_bank, then rerun core.context_bank.ledger.', 'steps' => $steps );
		}

		$required = array( 'blog_id', 'site_id', 'record_id', 'event_uuid', 'source_contract_id', 'contract_version', 'record_kind', 'identity_uuid', 'relative_file', 'byte_offset', 'row_hash', 'content_hash', 'operation', 'lifecycle_status', 'provenance_ref', 'created_at', 'updated_at' );
		$columns_ok = BizCity_Table_Metadata::columns_exist( $table, $required );
		$forbidden = array( 'memory_text', 'content', 'embedding', 'payload' );
		$forbidden_found = false;
		foreach ( $forbidden as $column ) {
			if ( BizCity_Table_Metadata::column_exists( $table, $column ) ) {
				$forbidden_found = true;
				break;
			}
		}
		$emit( 'Physical shard - pointer-only columns', $columns_ok && ! $forbidden_found ? 'pass' : 'fail', $columns_ok && ! $forbidden_found ? 'Required pointer fields present; forbidden payload columns absent.' : 'Required pointer field missing or forbidden payload column found.' );
		if ( ! $columns_ok || $forbidden_found ) {
			return array( 'status' => 'fail', 'summary' => 'Context Bank ledger schema is not pointer-only.', 'fix_hint' => 'Compare the physical shard with core.context-bank.json v1.1.0; do not add payload columns.', 'steps' => $steps );
		}

		$foreign_blog_id = $blog_id + 1;
		$foreign_find = BizCity_Context_Bank_Ledger::instance()->find( array( 'blog_id' => $foreign_blog_id, 'limit' => 1 ) );
		$foreign_query = BizCity_Context_Bank_Ledger::instance()->query( array( 'blog_id' => $foreign_blog_id, 'limit' => 1 ) );
		$tenant_isolated = empty( $foreign_find ) && is_array( $foreign_query ) && (string) ( $foreign_query['reason'] ?? '' ) === 'tenant_scope_denied';
		$emit( 'Runtime - foreign blog scope refusal', $tenant_isolated ? 'pass' : 'fail', $tenant_isolated ? 'Foreign blog filter was refused before tenant rows were returned.' : 'Foreign blog filter was not refused.' );
		if ( ! $tenant_isolated ) {
			return array( 'status' => 'fail', 'summary' => 'Foreign blog scope was not refused.', 'fix_hint' => 'Keep the current blog and routed database as server-owned scope dimensions.', 'steps' => $steps );
		}

		$explain_shapes = array(
			array( 'record_kind' => 'event', 'identity_uuid' => 'id_synthetic_alpha_701', 'date_from' => '2026-08-25', 'date_to' => '2026-09-01', 'limit' => 100 ),
			array( 'record_kind' => 'rollup', 'entity_type' => 'conversation', 'limit' => 20 ),
			array( 'record_kind' => 'rollup', 'identity_uuid' => 'id_synthetic_alpha_701', 'entity_type' => 'product', 'entity_key' => 'product_synthetic_3001', 'limit' => 50 ),
			array( 'record_kind' => 'rollup', 'entity_type' => 'sku', 'entity_key' => 'sku_synthetic_4001', 'secondary_type' => 'warehouse', 'secondary_key' => 'warehouse_synthetic_beta_1', 'limit' => 10 ),
			array( 'record_kind' => 'rollup', 'entity_type' => 'order', 'entity_key' => 'order_synthetic_5001', 'limit' => 25 ),
			array( 'record_kind' => 'memory', 'identity_uuid' => 'id_synthetic_alpha_701', 'limit' => 40 ),
			array( 'record_kind' => 'rule', 'identity_uuid' => 'id_synthetic_alpha_701', 'limit' => 40 ),
			array( 'record_kind' => 'event', 'notebook_id' => 7001, 'limit' => 30 ),
			array( 'record_kind' => 'rollup', 'entity_type' => 'inventory', 'limit' => 30 ),
			array( 'kg_status' => 'pending', 'limit' => 25 ),
			array( 'record_kind' => 'event', 'entity_key' => 'product_synthetic_3001', 'limit' => 20 ),
			array( 'record_kind' => 'rollup', 'entity_type' => 'sku', 'rollup_window' => '2026-08', 'limit' => 10 ),
			array( 'record_kind' => 'rollup', 'lifecycle_status' => 'active', 'limit' => 10 ),
			array( 'record_kind' => 'event', 'scope_key' => 'id_synthetic_alpha_701', 'limit' => 20 ),
			array( 'record_kind' => 'relation', 'entity_type' => 'order', 'limit' => 25 ),
			array( 'record_kind' => 'rollup', 'identity_uuid' => 'id_synthetic_alpha_701', 'limit' => 50 ),
		);
		$explain_ok = true;
		foreach ( $explain_shapes as $shape ) {
			$plan = BizCity_Context_Bank_Ledger::instance()->explain( $shape );
			if ( ! is_array( $plan ) || empty( $plan['ok'] ) ) {
				$explain_ok = false;
				break;
			}
		}
		$emit( 'Runtime - approved CB0.3 EXPLAIN shapes', $explain_ok ? 'pass' : 'fail', $explain_ok ? '16 fixture-derived bounded ledger shapes returned an execution plan.' : 'At least one approved ledger EXPLAIN shape failed.' );
		if ( ! $explain_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Approved Context Bank EXPLAIN coverage failed.', 'fix_hint' => 'Run EXPLAIN only for typed CB0.3 ledger predicates and inspect the target shard indexes.', 'steps' => $steps );
		}

		$record_id = 'cb_probe_' . strtolower( substr( md5( self::SENTINEL . '|' . $blog_id . '|' . wp_rand() ), 0, 24 ) );
		$identity_uuid = 'cb_probe_identity_' . substr( md5( (string) $blog_id ), 0, 16 );
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, array(
			'record_id' => $record_id,
			'memory_type' => 'diagnostic',
			'memory_text' => self::SENTINEL,
			'identity_uuid' => $identity_uuid,
		), 'upsert' );
		$receipt_ok = is_array( $receipt ) && (string) ( $receipt['record_id'] ?? '' ) === $record_id && (int) ( $receipt['blog_id'] ?? 0 ) === $blog_id;
		$emit( 'Runtime - lock-captured filestore receipt', $receipt_ok ? 'pass' : 'fail', $receipt_ok ? 'record_id=' . $record_id : 'Filestore receipt is missing or has the wrong tenant.' );
		if ( ! $receipt_ok ) {
			return array( 'status' => 'fail', 'summary' => 'Filestore receipt could not be admitted.', 'fix_hint' => 'Verify the registered source contract and BizCity_Business_JSONL_File_Store::write_with_receipt() on this shard.', 'steps' => $steps );
		}

		$reference = array(
			'memory_class' => 'user',
			'record_kind' => 'memory',
			'blog_id' => $blog_id,
			'user_id' => (int) get_current_user_id(),
			'identity_uuid' => $identity_uuid,
			'source_record_id' => $record_id,
			'trace_id' => 'cb_probe_trace_' . substr( md5( $record_id ), 0, 24 ),
			'provenance_ref' => 'probe:' . self::SENTINEL,
			'receipt' => $receipt,
		);
		do_action( 'bizcity_context_bank_reference_write', $reference );
		$rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'record_id' => $record_id, 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => $blog_id, 'limit' => 5 ) );
		$ledger_ok = isset( $rows[0] ) && (string) ( $rows[0]['record_id'] ?? '' ) === $record_id && (string) ( $rows[0]['relative_file'] ?? '' ) === (string) $receipt['relative_file'];
		$emit( 'Runtime - receipt admitted to tenant ledger', $ledger_ok ? 'pass' : 'fail', $ledger_ok ? 'pointer row found for ' . $record_id : 'Pointer row was not found or does not match the receipt.' );
		$follow = $ledger_ok ? BizCity_Context_Bank_Ledger::instance()->follow( $record_id, array( 'source_contract_id' => self::CONTRACT_ID, 'blog_id' => $blog_id ) ) : array();
		$follow_ok = is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
		$emit( 'Runtime - verified bounded pointer follow', $follow_ok ? 'pass' : 'fail', $follow_ok ? 'Exact line/hash/record identity verified.' : 'Pointer follow failed: ' . (string) ( $follow['reason'] ?? 'unknown' ) );
		$replay = BizCity_Context_Bank_Ledger::instance()->record( $reference );
		$replay_ok = is_array( $replay ) && ! empty( $replay['ok'] ) && ! empty( $replay['replayed'] );
		$emit( 'Runtime - identical receipt replay', $replay_ok ? 'pass' : 'fail', $replay_ok ? 'Same event/pointer is idempotent.' : 'Replay was not accepted as idempotent.' );
		$conflict_receipt = $receipt;
		$conflict_receipt['row_hash'] = substr( (string) $receipt['row_hash'], 0, 63 ) . ( (string) $receipt['row_hash'][63] === '0' ? '1' : '0' );
		$conflict = BizCity_Context_Bank_Ledger::instance()->record( array_merge( $reference, array( 'receipt' => $conflict_receipt ) ) );
		$conflict_ok = is_array( $conflict ) && (string) ( $conflict['reason'] ?? '' ) === 'pointer_conflict';
		$emit( 'Runtime - same-event pointer conflict', $conflict_ok ? 'pass' : 'fail', $conflict_ok ? 'Changed hash is rejected without overwrite.' : 'Changed hash was not rejected.' );

		$delete_receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, array( 'record_id' => $record_id ), 'delete' );
		if ( is_array( $delete_receipt ) ) {
			do_action( 'bizcity_context_bank_reference_write', array( 'record_kind' => 'memory', 'user_id' => (int) get_current_user_id(), 'identity_uuid' => $identity_uuid, 'receipt' => $delete_receipt, 'lifecycle_status' => 'deleted' ) );
		}
		$delete_ok = is_array( $delete_receipt );
		$emit( 'Runtime - tombstone pointer update', $delete_ok ? 'pass' : 'fail', $delete_ok ? 'Filestore tombstone receipt admitted.' : 'Filestore tombstone receipt was not created.' );

		return array(
			'status' => $ledger_ok && $follow_ok && $replay_ok && $conflict_ok && $delete_ok ? 'pass' : 'fail',
			'summary' => $ledger_ok && $follow_ok && $replay_ok && $conflict_ok && $delete_ok ? 'Context Bank ledger physical-shard receipt round-trip passed.' : 'Context Bank ledger receipt round-trip failed.',
			'fix_hint' => $ledger_ok && $follow_ok && $replay_ok && $conflict_ok && $delete_ok ? '' : 'Check tenant routing, receipt validation, idempotency, pointer conflict handling, bounded follow and pointer-only ledger schema.',
			'steps' => $steps,
		);
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Ledger';
	return $list;
} );
