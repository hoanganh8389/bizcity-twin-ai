<?php
/**
 * Deterministic Context Bank rollup reducer probe.
 *
 * This probe has no durable write, worker enqueue or provider call.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Rollup', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Rollup implements BizCity_Diagnostics_Probe {

	public function id(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB5.1-DDV — expose deterministic reducer evidence.
		return 'core.context_bank.rollup';
	}

	public function label(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB5.1-DDV — label the pure rollup probe.
		return 'Context Bank - deterministic rollup reducer';
	}

	public function description(): string {
		// [2026-09-01 Johnny Chu] PHASE-CB5.1-DDV — describe replay-order and payload-redaction coverage.
		return 'Checks registered rollup reduction, stable replay hash, bounded evidence references and rejection of unknown rollup IDs without durable side effects.';
	}

	public function severity(): string { return 'critical'; }
	public function order(): int { return 71; }
	public function icon(): string { return 'chart'; }
	public function estimate_ms(): int { return 150; }

	public function precondition() {
		if ( ! class_exists( 'BizCity_Context_Bank_Rollup_Engine' ) || ! class_exists( 'BizCity_Context_Bank_Rollup_Registry' ) || ! class_exists( 'BizCity_Context_Bank_Rollup_Worker' ) ) {
			return new WP_Error( 'context_bank_rollup_engine_missing', 'Context Bank rollup reducer or registry is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-CB5.2-DDV — prove replay deduplication, delivery exclusion, channel dimensions and order lifecycle reduction.
		$records = array(
			array( 'record_id' => 'event_b', 'source_contract_id' => 'tests.events', 'source_record_id' => 'source_b', 'event_uuid' => 'event-b', 'content_hash' => str_repeat( 'b', 64 ), 'occurred_at' => '2026-09-01 10:02:00', 'event_type' => 'crm_message_sent', 'direction' => 'outbound', 'channel' => 'facebook', 'status' => 'sent', 'memory_text' => 'must not be reduced' ),
			array( 'record_id' => 'event_a', 'source_contract_id' => 'tests.events', 'source_record_id' => 'source_a', 'event_uuid' => 'event-a', 'content_hash' => str_repeat( 'a', 64 ), 'occurred_at' => '2026-09-01 10:01:00', 'event_type' => 'crm_message_received', 'direction' => 'inbound', 'channel' => 'zalo_oa', 'status' => 'received', 'memory_text' => 'must not be reduced' ),
			array( 'record_id' => 'event_delivery', 'source_contract_id' => 'tests.events', 'source_record_id' => 'source_delivery', 'event_uuid' => 'event-delivery', 'content_hash' => str_repeat( 'd', 64 ), 'occurred_at' => '2026-09-01 10:03:00', 'event_type' => 'crm_message_delivery_updated', 'direction' => 'outbound', 'channel' => 'facebook', 'status' => 'delivered' ),
			array( 'record_id' => 'event_a_replay', 'source_contract_id' => 'tests.events', 'source_record_id' => 'source_a_replay', 'event_uuid' => 'event-a', 'content_hash' => str_repeat( 'a', 64 ), 'occurred_at' => '2026-09-01 10:04:00', 'event_type' => 'crm_message_received', 'direction' => 'inbound', 'channel' => 'zalo_oa', 'status' => 'received' ),
		);
		$first = BizCity_Context_Bank_Rollup_Engine::reduce( 'conversation_state', $records, array( 'conversation' => 'conversation_fixture' ) );
		$reordered = array_reverse( $records );
		$second = BizCity_Context_Bank_Rollup_Engine::reduce( 'conversation_state', $reordered, array( 'conversation' => 'conversation_fixture' ) );
		$hash_ok = ! empty( $first['ok'] ) && ! empty( $second['ok'] ) && (string) $first['result']['output_hash'] === (string) $second['result']['output_hash'];
		$state = is_array( $first['result']['state'] ?? null ) ? $first['result']['state'] : array();
		$state_ok = $hash_ok && (int) ( $state['message_count'] ?? 0 ) === 2 && (int) ( $state['inbound_count'] ?? 0 ) === 1 && (int) ( $state['outbound_count'] ?? 0 ) === 1 && count( (array) ( $state['channels'] ?? array() ) ) === 2;
		$ref_ok = $state_ok && count( $first['result']['evidence_refs'] ) === 2 && ! array_key_exists( 'memory_text', $first['result'] ) && ! array_key_exists( 'memory_text', $first['result']['evidence_refs'][0] );
		$conversation_lifecycle = array(
			array( 'record_id' => 'conversation_resolved', 'source_contract_id' => 'tests.events', 'source_record_id' => 'conversation_resolved', 'event_uuid' => 'conversation-resolved', 'occurred_at' => '2026-09-01 10:05:00', 'event_type' => 'conversation_resolved', 'status' => 'resolved', 'outcome' => 'answered' ),
			array( 'record_id' => 'conversation_reopened', 'source_contract_id' => 'tests.events', 'source_record_id' => 'conversation_reopened', 'event_uuid' => 'conversation-reopened', 'occurred_at' => '2026-09-01 10:06:00', 'event_type' => 'conversation_reopened', 'status' => 'open', 'outcome' => 'follow_up' ),
		);
		$lifecycle_first = BizCity_Context_Bank_Rollup_Engine::reduce( 'conversation_state', $conversation_lifecycle, array( 'conversation' => 'conversation_fixture' ) );
		$lifecycle_second = BizCity_Context_Bank_Rollup_Engine::reduce( 'conversation_state', array_reverse( $conversation_lifecycle ), array( 'conversation' => 'conversation_fixture' ) );
		$lifecycle_state = is_array( $lifecycle_first['result']['state'] ?? null ) ? $lifecycle_first['result']['state'] : array();
		$lifecycle_second_state = is_array( $lifecycle_second['result']['state'] ?? null ) ? $lifecycle_second['result']['state'] : array();
		$lifecycle_ok = ! empty( $lifecycle_first['ok'] ) && ! empty( $lifecycle_second['ok'] ) && (string) ( $lifecycle_state['conversation_status'] ?? '' ) === 'open' && (int) ( $lifecycle_state['reopen_count'] ?? 0 ) === 1 && (string) ( $lifecycle_state['last_outcome'] ?? '' ) === 'follow_up' && preg_match( '/^[a-f0-9]{16}$/', (string) ( $lifecycle_state['validity_version'] ?? '' ) ) && (string) $lifecycle_state['validity_version'] === (string) ( $lifecycle_second_state['validity_version'] ?? '' );
		$unknown = BizCity_Context_Bank_Rollup_Engine::reduce( 'unknown_rollup', $records );
		$unknown_ok = empty( $unknown['ok'] ) && (string) ( $unknown['reason'] ?? '' ) === 'rollup_not_registered';
		$order = BizCity_Context_Bank_Rollup_Engine::reduce( 'order_lifecycle', array(
			array( 'record_id' => 'order_event_a', 'source_contract_id' => 'tests.commerce', 'event_uuid' => 'order-event-a', 'occurred_at' => '2026-09-01 11:00:00', 'event_type' => 'payment_complete', 'order_id' => 123, 'order_status' => 'processing', 'payment_state' => 'paid', 'fulfillment_state' => 'in_progress' ),
			array( 'record_id' => 'order_event_b', 'source_contract_id' => 'tests.commerce', 'event_uuid' => 'order-event-b', 'occurred_at' => '2026-09-01 12:00:00', 'event_type' => 'refunded', 'order_id' => 123, 'order_status' => 'refunded', 'payment_state' => 'refunded', 'refund_state' => 'refunded', 'fulfillment_state' => 'stopped' ),
		), array( 'order' => '123' ) );
		$order_state = is_array( $order['result']['state'] ?? null ) ? $order['result']['state'] : array();
		$order_ok = ! empty( $order['ok'] ) && (string) ( $order_state['payment_state'] ?? '' ) === 'refunded' && (string) ( $order_state['last_status'] ?? '' ) === 'refunded';
		$order_relations = BizCity_Context_Bank_Rollup_Engine::reduce( 'order_lifecycle', array(
			array( 'record_id' => 'relation_a', 'source_contract_id' => 'tests.commerce', 'event_uuid' => 'relation-a', 'occurred_at' => '2026-09-01 10:00:00', 'event_type' => 'order_created', 'order_id' => 123, 'conversation_id' => 41, 'contact_id' => 51, 'status' => 'processing' ),
			array( 'record_id' => 'relation_b', 'source_contract_id' => 'tests.commerce', 'event_uuid' => 'relation-b', 'occurred_at' => '2026-09-01 11:00:00', 'event_type' => 'order_updated', 'order_id' => 123, 'conversation_id' => 42, 'contact_id' => 51, 'status' => 'processing', 'superseded_record_id' => 'relation_a' ),
		), array( 'order' => '123' ) );
		$relation_state = is_array( $order_relations['result']['state'] ?? null ) ? $order_relations['result']['state'] : array();
		$order_relation_ok = ! empty( $order_relations['ok'] ) && count( (array) ( $relation_state['related_conversations'] ?? array() ) ) === 2 && count( (array) ( $relation_state['related_contacts'] ?? array() ) ) === 1 && (int) ( $relation_state['correction_count'] ?? 0 ) === 1 && in_array( 'relation_a', (array) ( $relation_state['superseded_record_ids'] ?? array() ), true );
		$affinity = BizCity_Context_Bank_Rollup_Engine::reduce( 'customer_product_affinity', array(
			array( 'record_id' => 'affinity_a', 'source_contract_id' => 'tests.behavior', 'event_uuid' => 'affinity-a', 'occurred_at' => '2026-09-01 10:00:00', 'event_type' => 'product_view', 'customer_user_id' => 0, 'identity_uuid' => 'anonymous-1', 'product_id' => 99, 'sku' => 'SKU-99' ),
			array( 'record_id' => 'affinity_bot', 'source_contract_id' => 'tests.behavior', 'event_uuid' => 'affinity-bot', 'occurred_at' => '2026-09-01 10:01:00', 'event_type' => 'product_click', 'traffic_type' => 'bot', 'identity_uuid' => 'bot-1', 'product_id' => 99 ),
		), array( 'identity' => 'anonymous-1', 'product' => '99' ) );
		$affinity_state = is_array( $affinity['result']['state'] ?? null ) ? $affinity['result']['state'] : array();
		$affinity_ok = ! empty( $affinity['ok'] ) && (string) ( $affinity_state['identity_kind'] ?? '' ) === 'anonymous' && (int) ( $affinity_state['views_30d'] ?? 0 ) === 1 && (int) ( $affinity_state['clicks_30d'] ?? 0 ) === 0 && (string) ( $affinity_state['score_version'] ?? '' ) === 'affinity_v1' && (int) ( $affinity_state['product_id'] ?? 0 ) === 99;
		$inventory = BizCity_Context_Bank_Rollup_Engine::reduce( 'sku_inventory', array(
			array( 'record_id' => 'inventory_bad', 'source_contract_id' => 'tests.inventory', 'event_uuid' => 'inventory-bad', 'occurred_at' => '2026-09-01 10:00:00', 'on_hand' => -1, 'reserved' => 'invalid', 'available' => 4, 'warehouse_id' => 7, 'sku' => 'SKU-7', 'source_version' => 'stock-v3' ),
		), array( 'sku' => 'SKU-7', 'warehouse' => '7' ) );
		$inventory_state = is_array( $inventory['result']['state'] ?? null ) ? $inventory['result']['state'] : array();
		$inventory_ok = ! empty( $inventory['ok'] ) && (string) ( $inventory_state['status'] ?? '' ) === 'invalid_quantity' && in_array( 'invalid_quantity', (array) ( $inventory_state['error_buckets'] ?? array() ), true ) && count( (array) ( $inventory_state['invalid_quantity_fields'] ?? array() ) ) === 2 && (string) ( $inventory_state['source_version'] ?? '' ) === 'stock-v3' && (float) ( $inventory_state['available'] ?? 0 ) === 4.0;
		$conversation_mismatch = BizCity_Context_Bank_Rollup_Engine::reduce( 'conversation_state', array( array( 'record_id' => 'conversation_other', 'conversation_id' => 2, 'event_uuid' => 'conversation-other', 'occurred_at' => '2026-09-01 10:00:00', 'event_type' => 'crm_message_received' ) ), array( 'conversation' => '1' ) );
		$conversation_mismatch_ok = empty( $conversation_mismatch['ok'] ) && (string) ( $conversation_mismatch['reason'] ?? '' ) === 'rollup_conversation_dimension_mismatch';
		$sku_mismatch = BizCity_Context_Bank_Rollup_Engine::reduce( 'sku_inventory', array( array( 'record_id' => 'sku_other', 'sku' => 'SKU-8', 'warehouse_id' => 7, 'event_uuid' => 'sku-other', 'occurred_at' => '2026-09-01 10:00:00', 'on_hand' => 2 ) ), array( 'sku' => 'SKU-7', 'warehouse' => '7' ) );
		$sku_mismatch_ok = empty( $sku_mismatch['ok'] ) && (string) ( $sku_mismatch['reason'] ?? '' ) === 'rollup_sku_dimension_mismatch';
		$worker_loaded = class_exists( 'BizCity_Context_Bank_Rollup_Worker' )
			&& method_exists( 'BizCity_Context_Bank_Rollup_Worker', 'acquire_lease' )
			&& method_exists( 'BizCity_Context_Bank_Rollup_Worker', 'process' );
		$lease_blocked = BizCity_Context_Bank_Rollup_Worker::acquire_lease( 'conversation_state', 'diagnostics_fixture' );
		$process_blocked = BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', 'diagnostics_fixture', array( 'entity_type' => 'conversation', 'entity_key' => 'diagnostics_fixture' ) );
		$worker_isolated = defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI
			&& is_array( $lease_blocked ) && empty( $lease_blocked['ok'] ) && 'diagnostics_cli_isolated' === (string) ( $lease_blocked['reason'] ?? '' )
			&& is_array( $process_blocked ) && empty( $process_blocked['ok'] ) && 'diagnostics_cli_isolated' === (string) ( $process_blocked['reason'] ?? '' );
		foreach ( array(
			array( 'label' => 'Deterministic replay output hash', 'status' => $hash_ok ? 'pass' : 'fail', 'detail' => $hash_ok ? 'Input order changes do not change output_hash.' : 'Replay output hash changed.' ),
			array( 'label' => 'Conversation state reduction', 'status' => $state_ok ? 'pass' : 'fail', 'detail' => $state_ok ? 'Two bounded message events reduced to one inbound and one outbound.' : 'Conversation measures are incorrect.' ),
			array( 'label' => 'Conversation reopen state machine', 'status' => $lifecycle_ok ? 'pass' : 'fail', 'detail' => $lifecycle_ok ? 'Resolved conversation reopens deterministically with outcome and validity version.' : 'Conversation reopen state or validity version is incorrect.' ),
			array( 'label' => 'Evidence bounded and payload-free', 'status' => $ref_ok ? 'pass' : 'fail', 'detail' => $ref_ok ? 'Evidence references contain IDs/hashes only.' : 'Payload field leaked into rollup result.' ),
			array( 'label' => 'Unknown rollup rejection', 'status' => $unknown_ok ? 'pass' : 'fail', 'detail' => $unknown_ok ? 'Unregistered rollup ID fails closed.' : 'Unknown rollup was accepted.' ),
			array( 'label' => 'Order lifecycle reduction', 'status' => $order_ok ? 'pass' : 'fail', 'detail' => $order_ok ? 'Payment and refund metadata reduce to the latest bounded order state.' : 'Order lifecycle state did not reduce correctly.' ),
			array( 'label' => 'Order relation and correction state', 'status' => $order_relation_ok ? 'pass' : 'fail', 'detail' => $order_relation_ok ? 'Multiple conversations remain related and superseded provenance is retained.' : 'Order relation or correction state was collapsed.' ),
			array( 'label' => 'Affinity identity and traffic isolation', 'status' => $affinity_ok ? 'pass' : 'fail', 'detail' => $affinity_ok ? 'Anonymous identity remains anonymous, bot traffic is excluded and score version is explicit.' : 'Affinity identity or traffic filtering is incorrect.' ),
			array( 'label' => 'Inventory quantity failure bucket', 'status' => $inventory_ok ? 'pass' : 'fail', 'detail' => $inventory_ok ? 'Negative and non-numeric quantities produce an explicit bounded failure state.' : 'Invalid inventory quantities were not classified explicitly.' ),
			array( 'label' => 'Conversation source dimension isolation', 'status' => $conversation_mismatch_ok ? 'pass' : 'fail', 'detail' => $conversation_mismatch_ok ? 'A source conversation outside the requested dimension fails before reduction.' : 'A mismatched conversation source was accepted.' ),
			array( 'label' => 'Inventory source dimension isolation', 'status' => $sku_mismatch_ok ? 'pass' : 'fail', 'detail' => $sku_mismatch_ok ? 'A source SKU outside the requested dimension fails before reduction.' : 'A mismatched SKU source was accepted.' ),
			array( 'label' => 'Durable rollup worker is loaded', 'status' => $worker_loaded ? 'pass' : 'fail', 'detail' => $worker_loaded ? 'Lease and process APIs are available through the Context Bank worker owner.' : 'Durable rollup worker is not loaded.' ),
			array( 'label' => 'Diagnostics CLI blocks rollup lease and worker entry', 'status' => $worker_isolated ? 'pass' : 'fail', 'detail' => $worker_isolated ? 'Both direct lease acquisition and worker processing return diagnostics_cli_isolated without side effects.' : 'A rollup worker boundary was not isolated from Diagnostics CLI.' ),
		) as $step ) {
			$ctx->emit_step( $step );
		}
		$pass = $hash_ok && $state_ok && $lifecycle_ok && $ref_ok && $unknown_ok && $order_ok && $order_relation_ok && $affinity_ok && $inventory_ok && $conversation_mismatch_ok && $sku_mismatch_ok && $worker_loaded && $worker_isolated;
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'Deterministic Context Bank rollup reducer passed replay, bounded evidence and registry rejection checks.' : 'Context Bank rollup reducer failed.', 'fix_hint' => $pass ? '' : 'Check rollup registry lookup, stable ordering, reducer measures and payload field allowlist.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Rollup';
	return $list;
} );