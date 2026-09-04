<?php
/**
 * Project WooCommerce order lifecycle into Context Bank.
 *
 * WooCommerce remains the canonical order, payment and product owner. This
 * adapter writes an encrypted event record and a rebuildable ledger pointer;
 * it does not create a commerce shadow ledger or copy customer PII.
 *
 * @package BizCity_Twin_AI
 * @subpackage Context_Bank
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Context_Bank_Commerce_Adapter', false ) ) {
	return;
}

final class BizCity_Context_Bank_Commerce_Adapter {

	const CONTRACT_ID = 'core.context_bank.commerce_order';
	const FEATURE_FLAG = 'bizcity_context_bank_capture_enabled';

	private static $booted = false;

	public static function boot() {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — attach one idempotent Woo lifecycle projection owner without changing Woo truth.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 40, 1 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 40, 4 );
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_refunded' ), 40, 2 );
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3 — consume canonical CRM shipping tracker events through the existing order projection owner.
		add_action( 'bizcity_crm_order_shipped', array( __CLASS__, 'on_shipped' ), 40, 2 );
		add_action( 'bizcity_crm_order_delivered', array( __CLASS__, 'on_delivered' ), 40, 2 );
	}

	public static function on_payment_complete( $order_id ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — normalize Woo payment completion through the shared order projection.
		return self::project( (int) $order_id, 'payment_complete', '', 'paid' );
	}

	public static function on_status_changed( $order_id, $old_status, $new_status, $order = null ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — normalize Woo status transitions without changing the canonical order owner.
		unset( $order );
		return self::project( (int) $order_id, 'status_changed', sanitize_key( (string) $old_status ), sanitize_key( (string) $new_status ) );
	}

	public static function on_refunded( $order_id, $refund_id = 0 ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — retain the canonical Woo refund identity in the projection event.
		return self::project( (int) $order_id, 'refunded', '', 'refunded', (int) $refund_id );
	}

	public static function on_shipped( $order_id, $shipment = array() ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3 — preserve CRM tracker ownership while projecting bounded shipment state.
		return self::project( (int) $order_id, 'shipped', '', 'processing', 0, self::shipment_provenance( $shipment, 'shipped' ) );
	}

	public static function on_delivered( $order_id, $shipment = array() ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3 — project canonical delivery completion without calling a carrier or creating a second shipment log.
		return self::project( (int) $order_id, 'delivered', 'processing', 'completed', 0, self::shipment_provenance( $shipment, 'delivered' ) );
	}

	/**
	 * Project one Woo lifecycle event through the encrypted business filestore.
	 *
	 * @param int    $order_id Woo order ID.
	 * @param string $event_type Normalized lifecycle event.
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @param int    $refund_id Optional refund ID.
	 * @return array<string,mixed>
	 */
	public static function project( $order_id, $event_type, $old_status = '', $new_status = '', $refund_id = 0, array $provenance = array() ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — keep commerce capture disabled by default and fail closed before reading Woo data.
		if ( ! self::capture_enabled() ) {
			return array( 'ok' => true, 'projected' => false, 'reason' => 'capture_disabled' );
		}
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'commerce_adapter_dependency_missing' );
		}
		$order = wc_get_order( $order_id );
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) || (int) $order->get_id() !== $order_id ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'woo_order_not_found' );
		}
		$event_type = sanitize_key( (string) $event_type );
		$new_status = sanitize_key( (string) $new_status );
		$old_status = sanitize_key( (string) $old_status );
		$modified = method_exists( $order, 'get_date_modified' ) && $order->get_date_modified()
			? (string) $order->get_date_modified()->date( 'c' )
			: gmdate( 'c' );
		$event_key = $order_id . '|' . $event_type . '|' . $old_status . '|' . $new_status . '|' . (int) $refund_id . '|' . $modified;
		$record_id = 'woo_order_' . $order_id . '_' . substr( hash( 'sha256', $event_key ), 0, 32 );
		$blog_id = (int) get_current_blog_id();
		$relation = self::resolve_order_relation( $order );
		$inventory = isset( $provenance['inventory'] ) && is_array( $provenance['inventory'] ) ? $provenance['inventory'] : array();
		$shipment = isset( $provenance['shipment'] ) && is_array( $provenance['shipment'] ) ? $provenance['shipment'] : array();
		$ledger = BizCity_Context_Bank_Ledger::instance();
		$existing = $ledger->find( array( 'blog_id' => $blog_id, 'source_contract_id' => self::CONTRACT_ID, 'record_id' => $record_id, 'limit' => 1 ) );
		if ( ! empty( $existing[0] ) ) {
			return array( 'ok' => true, 'projected' => true, 'replayed' => true, 'record_id' => $record_id );
		}
		$items = array();
		if ( method_exists( $order, 'get_items' ) ) {
			foreach ( (array) $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
					continue;
				}
				$product_id = (int) $item->get_product_id();
				$quantity = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
				$sku = '';
				$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
				if ( is_object( $product ) && method_exists( $product, 'get_sku' ) ) {
					$sku = sanitize_text_field( (string) $product->get_sku() );
				}
				$items[] = array( 'product_id' => $product_id, 'sku' => $sku, 'quantity' => max( 0, $quantity ) );
			}
		}
		$record = array(
			'record_id' => $record_id,
			'blog_id' => $blog_id,
			'event_type' => $event_type,
			'event_key' => hash( 'sha256', $event_key ),
			'order_id' => $order_id,
			'order_status' => $new_status !== '' ? $new_status : ( method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '' ),
			'payment_state' => self::payment_state( $order, $event_type, $new_status ),
			'fulfillment_state' => self::fulfillment_state( $new_status ),
			'shipment_state' => (string) ( $shipment['state'] ?? ( $event_type === 'delivered' ? 'delivered' : ( $event_type === 'shipped' ? 'shipped' : '' ) ) ),
			'shipment' => array(
				'tracking_present' => ! empty( $shipment['tracking_present'] ),
				'provider' => sanitize_key( (string) ( $shipment['provider'] ?? '' ) ),
			),
			'refund_state' => $event_type === 'refunded' ? 'refunded' : '',
			'currency' => method_exists( $order, 'get_currency' ) ? sanitize_key( (string) $order->get_currency() ) : '',
			'customer_user_id' => (int) $relation['customer_user_id'],
			'contact_id' => (int) $relation['contact_id'],
			'conversation_id' => (int) $relation['conversation_id'],
			'conversation_ids' => (array) $relation['conversation_ids'],
			'relation_status' => (string) $relation['status'],
			'relation_source' => (string) $relation['source'],
			'refund_id' => max( 0, (int) $refund_id ),
			'parent_record_id' => (string) ( $provenance['parent_record_id'] ?? '' ),
			'inventory' => array(
				'warehouse_id' => sanitize_key( (string) ( $inventory['warehouse_id'] ?? '' ) ),
				'source_version' => sanitize_text_field( (string) ( $inventory['source_version'] ?? '' ) ),
			),
			'items' => $items,
			'occurred_at' => $modified,
		);
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::CONTRACT_ID, $record, 'upsert' );
		if ( ! is_array( $receipt ) ) {
			return array( 'ok' => false, 'projected' => false, 'reason' => 'commerce_filestore_write_failed' );
		}
		$admission = $ledger->record( array(
			'source_contract_id' => self::CONTRACT_ID,
			'record_id' => $record_id,
			'record_kind' => 'event',
			'event_uuid' => (string) $receipt['event_uuid'],
			'source_record_id' => hash( 'sha256', $event_key ),
			'user_id' => (int) ( $record['customer_user_id'] ?? 0 ),
			'contact_id' => (int) ( $record['contact_id'] ?? 0 ),
			'conversation_id' => (int) ( $record['conversation_id'] ?? 0 ),
			'parent_record_id' => (string) ( $record['parent_record_id'] ?? '' ),
			'entity_type' => 'order',
			'entity_key' => (string) $order_id,
			'scope_key' => 'order:' . $order_id,
			'rollup_window' => 'lifecycle',
			'provenance_ref' => 'woo-order:' . $order_id,
			'kg_status' => 'not_candidate',
			'receipt' => $receipt,
		) );
		return ! empty( $admission['ok'] ) ? array( 'ok' => true, 'projected' => true, 'replayed' => ! empty( $admission['replayed'] ), 'record_id' => $record_id, 'relationship' => array( 'contact_id' => (int) $relation['contact_id'], 'conversation_id' => (int) $relation['conversation_id'], 'conversation_ids' => (array) $relation['conversation_ids'], 'status' => (string) $relation['status'], 'source' => (string) $relation['source'] ), 'inventory' => $record['inventory'] ) : array( 'ok' => false, 'projected' => false, 'reason' => (string) ( $admission['reason'] ?? 'commerce_ledger_admission_failed' ) );
	}

	private static function shipment_provenance( $shipment, $state = '' ) {
		// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3 — accept bounded shipment metadata from the canonical CRM tracker and omit raw tracking numbers.
		$shipment = is_array( $shipment ) ? $shipment : array();
		return array( 'shipment' => array(
			'state' => isset( $shipment['state'] ) && (string) $shipment['state'] !== '' ? sanitize_key( (string) $shipment['state'] ) : sanitize_key( (string) $state ),
			'tracking_present' => ! empty( $shipment['tracking_number'] ),
			'provider' => isset( $shipment['provider'] ) ? sanitize_key( (string) $shipment['provider'] ) : '',
		) );
	}

	private static function resolve_order_relation( $order ) {
		// [2026-09-02 Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3 — read only exact CRM relation metadata attached to this Woo order; never create a conversation or select an arbitrary contact/order.
		$contact_id = 0;
		$conversation_id = 0;
		$conversation_ids = array();
		if ( method_exists( $order, 'get_meta' ) ) {
			$contact_id = absint( $order->get_meta( '_bizcity_crm_contact_id', true ) );
			$conversation_id = absint( $order->get_meta( '_bizcity_crm_conversation_id', true ) );
			$stored_conversation_ids = $order->get_meta( '_bizcity_crm_conversation_ids', true );
			if ( is_array( $stored_conversation_ids ) ) {
				foreach ( $stored_conversation_ids as $stored_conversation_id ) {
					$stored_conversation_id = absint( $stored_conversation_id );
					if ( $stored_conversation_id > 0 && ! in_array( $stored_conversation_id, $conversation_ids, true ) ) {
						$conversation_ids[] = $stored_conversation_id;
					}
				}
			}
		}
		if ( $conversation_id > 0 && ! in_array( $conversation_id, $conversation_ids, true ) ) { $conversation_ids[] = $conversation_id; }
		$customer_user_id = method_exists( $order, 'get_user_id' ) ? absint( $order->get_user_id() ) : 0;
		return array(
			'contact_id' => $contact_id,
			'conversation_id' => $conversation_id > 0 ? $conversation_id : ( ! empty( $conversation_ids ) ? (int) $conversation_ids[0] : 0 ),
			'conversation_ids' => $conversation_ids,
			'customer_user_id' => $customer_user_id,
			'status' => ( $contact_id > 0 || $conversation_id > 0 || $customer_user_id > 0 ) ? 'explicit' : 'unlinked',
			'source' => ( $contact_id > 0 || $conversation_id > 0 ) ? 'woo_crm_order_metadata' : ( $customer_user_id > 0 ? 'woo_customer_id' : 'none' ),
		);
	}

	private static function payment_state( $order, $event_type, $new_status ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — derive bounded payment state without persisting payment credentials or billing data.
		if ( $event_type === 'payment_complete' || in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			return 'paid';
		}
		if ( $event_type === 'refunded' || $new_status === 'refunded' ) {
			return 'refunded';
		}
		return method_exists( $order, 'is_paid' ) && $order->is_paid() ? 'paid' : 'pending';
	}

	private static function fulfillment_state( $status ) {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — map Woo status to a bounded fulfillment state for order rollups.
		$status = sanitize_key( (string) $status );
		if ( $status === 'completed' ) { return 'completed'; }
		if ( in_array( $status, array( 'processing', 'on-hold' ), true ) ) { return 'in_progress'; }
		if ( in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true ) ) { return 'stopped'; }
		return $status;
	}

	private static function capture_enabled() {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3 — read the tenant-scoped capture flag with a fail-safe false default.
		return function_exists( 'get_option' ) && (bool) get_option( self::FEATURE_FLAG, false );
	}
}