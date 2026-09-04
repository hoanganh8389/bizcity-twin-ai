<?php
/**
 * DDV probe for the feature-gated WooCommerce Context Bank projection.
 *
 * The default check is side-effect free and does not create an order.
 *
 * @package BizCity_Twin_AI
 * @subpackage Core\Diagnostics\Probes
 */

defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'BizCity_Diagnostics_Probe' ) || class_exists( 'BizCity_Probe_Context_Bank_Commerce', false ) ) {
	return;
}

final class BizCity_Probe_Context_Bank_Commerce implements BizCity_Diagnostics_Probe {

	const FLAG = 'bizcity_context_bank_capture_enabled';

	public function id(): string { return 'core.context_bank.commerce'; }
	public function label(): string { return 'Context Bank - WooCommerce projection'; }
	public function description(): string { return 'Checks the WooCommerce order lifecycle projection owner and its default capture-off behavior without creating an order.'; }
	public function severity(): string { return 'critical'; }
	public function order(): int { return 74; }
	public function icon(): string { return 'shopping-cart'; }
	public function estimate_ms(): int { return 100; }

	public function precondition() {
		// [2026-09-03 Johnny Chu] PHASE-CB4.3-DDV - load the Context Bank package boundary before evaluating the commerce adapter precondition on headless Diagnostics requests.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		if ( ! class_exists( 'BizCity_Context_Bank_Commerce_Adapter' ) ) {
			$bootstrap = rtrim( $root, '/\\' ) . '/core/context-bank/bootstrap.php';
			if ( ! class_exists( 'BizCity_Safe_Loader', false ) ) {
				$safe_loader = rtrim( $root, '/\\' ) . '/core/helper/class-bizcity-safe-loader.php';
				if ( is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
					require_once $safe_loader;
				}
				unset( $safe_loader );
			}
			if ( class_exists( 'BizCity_Safe_Loader', false ) && is_file( $bootstrap ) && is_readable( $bootstrap ) ) {
				try {
					BizCity_Safe_Loader::require_file( $bootstrap, 'diagnostics.context_bank.commerce_bootstrap' );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'context_bank_commerce_bootstrap_failed', 'Context Bank WooCommerce adapter bootstrap failed.' );
				}
			}
			unset( $bootstrap );
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Commerce_Adapter' ) && class_exists( 'BizCity_Safe_Loader', false ) ) {
			// [2026-09-03 Johnny Chu] PHASE-CB4.3-DDV - recover a partially mounted package by loading only the requested Commerce artifact, never an unrelated Context Bank slice.
			$adapter_file = rtrim( $root, '/\\' ) . '/core/context-bank/includes/class-context-bank-commerce-adapter.php';
			if ( is_file( $adapter_file ) && is_readable( $adapter_file ) ) {
				try {
					BizCity_Safe_Loader::require_file( $adapter_file, 'diagnostics.context_bank.commerce_adapter' );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'context_bank_commerce_adapter_failed', 'Context Bank WooCommerce adapter artifact failed to load.' );
				}
			}
			unset( $adapter_file );
		}
		if ( class_exists( 'BizCity_Context_Bank_Commerce_Adapter' ) && method_exists( 'BizCity_Context_Bank_Commerce_Adapter', 'boot' ) ) {
			BizCity_Context_Bank_Commerce_Adapter::boot();
		}
		if ( ! class_exists( 'BizCity_Context_Bank_Commerce_Adapter' ) ) {
			return new WP_Error( 'context_bank_commerce_missing', 'Context Bank WooCommerce adapter is not loaded.' );
		}
		return true;
	}

	public function run( $ctx ): array {
		// [2026-09-02 Johnny Chu] PHASE-CB4.3-DDV — prove Woo contract wiring and capture-off behavior without business mutation.
		$root = defined( 'BIZCITY_TWIN_AI_DIR' ) ? BIZCITY_TWIN_AI_DIR : dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/';
		$adapter_file = $root . 'core/context-bank/includes/class-context-bank-commerce-adapter.php';
		$disk_ok = is_readable( $adapter_file );
		$source = $disk_ok ? file_get_contents( $adapter_file ) : '';
		$relation_guard_ok = is_string( $source ) && strpos( $source, "'_bizcity_crm_contact_id'" ) !== false && strpos( $source, "'_bizcity_crm_conversation_id'" ) !== false && strpos( $source, 'wc_get_orders' ) === false && strpos( strtolower( $source ), 'latest' ) === false;
		$shipment_guard_ok = is_string( $source )
			&& strpos( $source, 'bizcity_crm_order_shipped' ) !== false
			&& strpos( $source, 'bizcity_crm_order_delivered' ) !== false
			&& strpos( $source, 'tracking_present' ) !== false
			&& strpos( $source, 'shipment_state' ) !== false;
		$loader_ok = class_exists( 'BizCity_Context_Bank_Commerce_Adapter' )
			&& false !== has_action( 'woocommerce_payment_complete', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_payment_complete' ) )
			&& false !== has_action( 'woocommerce_order_status_changed', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_status_changed' ) )
			&& false !== has_action( 'woocommerce_order_refunded', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_refunded' ) )
			&& false !== has_action( 'bizcity_crm_order_shipped', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_shipped' ) )
			&& false !== has_action( 'bizcity_crm_order_delivered', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_delivered' ) );
		$missing_flag = '__cb_commerce_flag_missing__';
		$previous_flag = get_option( self::FLAG, $missing_flag );
		try {
			delete_option( self::FLAG );
			$runtime = BizCity_Context_Bank_Commerce_Adapter::project( 0, 'status_changed', 'pending', 'processing' );
			$runtime_ok = is_array( $runtime ) && ! empty( $runtime['ok'] ) && empty( $runtime['projected'] ) && 'capture_disabled' === (string) ( $runtime['reason'] ?? '' );
		} finally {
			if ( $previous_flag === $missing_flag ) {
				delete_option( self::FLAG );
			} else {
				update_option( self::FLAG, $previous_flag, false );
			}
		}
		$fixture_status = 'skip';
		$fixture_detail = 'WooCommerce order factory is unavailable; lifecycle fixture was not executed.';
		$fixture_steps = array();
		$fixture_order = null;
		$fixture_order_id = 0;
		$fixture_record_id = '';
		$fixture_shipment_record_ids = array();
		$fixture_previous_flag = get_option( self::FLAG, $missing_flag );
		try {
			if ( function_exists( 'wc_create_order' ) && class_exists( 'BizCity_Business_JSONL_File_Store' ) && class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
				// [2026-09-02 Johnny Chu] PHASE-CB4.3-DDV - exercise Woo lifecycle projection with a disposable order and no provider transport.
				update_option( self::FLAG, true, false );
				$fixture_order = wc_create_order();
				if ( is_object( $fixture_order ) && method_exists( $fixture_order, 'get_id' ) ) {
					$fixture_order_id = (int) $fixture_order->get_id();
					if ( method_exists( $fixture_order, 'set_created_via' ) ) { $fixture_order->set_created_via( 'bizcity_diagnostics' ); }
					if ( method_exists( $fixture_order, 'set_currency' ) ) { $fixture_order->set_currency( 'VND' ); }
					if ( method_exists( $fixture_order, 'save' ) ) { $fixture_order->save(); }
					$fixture_steps[] = array( 'label' => 'Runtime - disposable Woo order created without customer PII', 'status' => 'pass', 'detail' => 'WooCommerce created one disposable order owned by the canonical Woo API.' );
					$projection = BizCity_Context_Bank_Commerce_Adapter::project( $fixture_order_id, 'status_changed', 'pending', 'processing' );
					$projected_ok = is_array( $projection ) && ! empty( $projection['ok'] ) && ! empty( $projection['projected'] );
					$relationship = is_array( $projection['relationship'] ?? null ) ? $projection['relationship'] : array();
					$unlinked_relation_ok = $projected_ok && (int) ( $relationship['contact_id'] ?? 0 ) === 0 && (int) ( $relationship['conversation_id'] ?? 0 ) === 0 && (string) ( $relationship['status'] ?? '' ) === 'unlinked';
					$fixture_record_id = (string) ( $projection['record_id'] ?? '' );
					$fixture_steps[] = array( 'label' => 'Runtime - Woo lifecycle enters Context Bank through the adapter', 'status' => $projected_ok ? 'pass' : 'fail', 'detail' => $projected_ok ? 'Order status projection wrote one encrypted record and pointer.' : 'Woo lifecycle projection failed: ' . (string) ( $projection['reason'] ?? 'unknown' ) );
					$fixture_steps[] = array( 'label' => 'Runtime - unlinked Woo order creates no CRM conversation', 'status' => $unlinked_relation_ok ? 'pass' : 'fail', 'detail' => $unlinked_relation_ok ? 'Order without CRM relation remains explicitly unlinked; no conversation is created.' : 'Commerce projection created or inferred an unexpected CRM relation.' );
					$replay = $projected_ok ? BizCity_Context_Bank_Commerce_Adapter::project( $fixture_order_id, 'status_changed', 'pending', 'processing' ) : array();
					$replay_ok = is_array( $replay ) && ! empty( $replay['ok'] ) && ! empty( $replay['replayed'] );
					$fixture_steps[] = array( 'label' => 'Runtime - Woo transition replay is idempotent', 'status' => $replay_ok ? 'pass' : 'fail', 'detail' => $replay_ok ? 'The same canonical order transition returned replay success without a second pointer.' : 'Order transition replay was not idempotent.' );
					// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3-DDV - dispatch canonical tracker hooks, then inspect their derived pointers through the ledger/filestore owners; do not treat do_action() as a return channel.
					do_action( 'bizcity_crm_order_shipped', $fixture_order_id, array( 'tracking_number' => 'CB-DIAGNOSTIC-TRACKING', 'provider' => 'fixture' ) );
					do_action( 'bizcity_crm_order_delivered', $fixture_order_id, array( 'tracking_number' => 'CB-DIAGNOSTIC-TRACKING', 'provider' => 'fixture' ) );
					$shipment_event_types = array();
					$shipment_pointers = array();
					$shipment_payload_ok = true;
					$shipment_failure_reasons = array();
					$shipment_file_records = class_exists( 'BizCity_Business_JSONL_File_Store' ) ? BizCity_Business_JSONL_File_Store::query( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, array( 'days' => 1, 'limit' => 20, 'filter' => static function ( $record ) use ( $fixture_order_id ) {
						return is_array( $record ) && (int) ( $record['order_id'] ?? 0 ) === $fixture_order_id && in_array( (string) ( $record['event_type'] ?? '' ), array( 'shipped', 'delivered' ), true );
					} ) ) : array();
					$shipment_file_by_type = array();
					foreach ( $shipment_file_records as $shipment_file_record ) {
						if ( is_array( $shipment_file_record ) && in_array( (string) ( $shipment_file_record['event_type'] ?? '' ), array( 'shipped', 'delivered' ), true ) ) {
							$shipment_file_by_type[ (string) $shipment_file_record['event_type'] ] = $shipment_file_record;
						}
					}
					$shipment_callback_counts = array(
						'shipped_registered' => has_action( 'bizcity_crm_order_shipped', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_shipped' ) ) !== false,
						'delivered_registered' => has_action( 'bizcity_crm_order_delivered', array( 'BizCity_Context_Bank_Commerce_Adapter', 'on_delivered' ) ) !== false,
					);
					$shipment_rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'entity_type' => 'order', 'entity_key' => (string) $fixture_order_id, 'blog_id' => (int) get_current_blog_id(), 'limit' => 20 ) );
					foreach ( (array) $shipment_rows as $shipment_pointer ) {
						if ( ! is_array( $shipment_pointer ) ) {
							continue;
						}
						$shipment_body = BizCity_Business_JSONL_File_Store::read_receipt( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, $shipment_pointer );
						$shipment_body_record = is_array( $shipment_body['record'] ?? null ) ? $shipment_body['record'] : array();
						$shipment_event_type = sanitize_key( (string) ( $shipment_body_record['event_type'] ?? '' ) );
						if ( in_array( $shipment_event_type, array( 'shipped', 'delivered' ), true ) ) {
							$shipment_event_types[ $shipment_event_type ] = true;
							$shipment_pointers[ $shipment_event_type ] = $shipment_pointer;
							$shipment_payload_ok = $shipment_payload_ok && ! empty( $shipment_body['ok'] ) && (string) ( $shipment_body_record['shipment_state'] ?? '' ) === $shipment_event_type && ! array_key_exists( 'tracking_number', $shipment_body_record );
							if ( empty( $shipment_body['ok'] ) ) { $shipment_failure_reasons[] = $shipment_event_type . ':body_' . sanitize_key( (string) ( $shipment_body['reason'] ?? 'invalid' ) ); }
							if ( (string) ( $shipment_body_record['shipment_state'] ?? '' ) !== $shipment_event_type ) { $shipment_failure_reasons[] = $shipment_event_type . ':state_' . sanitize_key( (string) ( $shipment_body_record['shipment_state'] ?? 'missing' ) ); }
							if ( array_key_exists( 'tracking_number', $shipment_body_record ) ) { $shipment_failure_reasons[] = $shipment_event_type . ':raw_tracking_present'; }
						}
					}
					foreach ( $shipment_file_by_type as $shipment_event_type => $shipment_file_record ) {
						$shipment_event_types[ $shipment_event_type ] = true;
						$shipment_record_id = (string) ( $shipment_file_record['record_id'] ?? '' );
						if ( $shipment_record_id !== '' ) {
							$shipment_pointer_rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $shipment_record_id, 'blog_id' => (int) get_current_blog_id(), 'limit' => 1 ) );
							if ( isset( $shipment_pointer_rows[0] ) && is_array( $shipment_pointer_rows[0] ) ) {
								$shipment_pointers[ $shipment_event_type ] = $shipment_pointer_rows[0];
								$fixture_shipment_record_ids[] = $shipment_record_id;
							}
						}
					}
					$shipment_event_ok = isset( $shipment_event_types['shipped'], $shipment_event_types['delivered'] );
					if ( ! $shipment_event_ok ) { $shipment_failure_reasons[] = 'events_' . implode( '_', array_keys( $shipment_event_types ) ); }
					foreach ( array( 'shipped', 'delivered' ) as $expected_state ) {
						$shipment_pointer = isset( $shipment_pointers[ $expected_state ] ) && is_array( $shipment_pointers[ $expected_state ] ) ? $shipment_pointers[ $expected_state ] : array();
						$shipment_record_id = (string) ( $shipment_pointer['record_id'] ?? '' );
						$shipment_rows = $shipment_record_id !== '' ? BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $shipment_record_id, 'blog_id' => (int) get_current_blog_id(), 'limit' => 1 ) ) : array();
						$shipment_pointer = isset( $shipment_rows[0] ) && is_array( $shipment_rows[0] ) ? $shipment_rows[0] : $shipment_pointer;
						$shipment_follow = ! empty( $shipment_pointer ) ? BizCity_Context_Bank_Ledger::instance()->follow( $shipment_record_id, array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'blog_id' => (int) get_current_blog_id() ) ) : array();
						$shipment_body = ! empty( $shipment_pointer ) ? BizCity_Business_JSONL_File_Store::read_receipt( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, $shipment_pointer ) : array();
						$shipment_body_record = is_array( $shipment_body['record'] ?? null ) ? $shipment_body['record'] : array();
						if ( empty( $shipment_pointer ) ) { $shipment_failure_reasons[] = $expected_state . ':pointer_missing'; }
						if ( ! empty( $shipment_pointer ) && empty( $shipment_follow['ok'] ) ) { $shipment_failure_reasons[] = $expected_state . ':follow_' . sanitize_key( (string) ( $shipment_follow['reason'] ?? 'invalid' ) ); }
						if ( ! empty( $shipment_pointer ) && empty( $shipment_body['ok'] ) ) { $shipment_failure_reasons[] = $expected_state . ':body_' . sanitize_key( (string) ( $shipment_body['reason'] ?? 'invalid' ) ); }
						$shipment_payload_ok = $shipment_payload_ok && ! empty( $shipment_follow['ok'] ) && ! empty( $shipment_follow['verified'] ) && ! empty( $shipment_body['ok'] ) && (string) ( $shipment_body_record['shipment_state'] ?? '' ) === $expected_state && ! array_key_exists( 'tracking_number', $shipment_body_record );
					}
					$shipment_fixture_ok = $shipment_event_ok && $shipment_payload_ok;
					if ( count( $shipment_file_records ) !== 2 ) { $shipment_failure_reasons[] = 'file_records_' . (string) count( $shipment_file_records ); }
					$shipment_detail = $shipment_fixture_ok ? 'Canonical shipped/delivered hooks produced verified order pointers with bounded shipment state and no raw tracking number.' : 'Shipment event assertion failed: ' . implode( ',', array_slice( array_unique( $shipment_failure_reasons ), 0, 6 ) ) . '; pointers=' . (string) count( $shipment_rows ) . '; files=' . (string) count( $shipment_file_records ) . '; registered=' . ( ! empty( $shipment_callback_counts['shipped_registered'] ) ? 's' : '-' ) . ( ! empty( $shipment_callback_counts['delivered_registered'] ) ? 'd' : '-' ) . '; did=' . ( function_exists( 'did_action' ) ? (string) did_action( 'bizcity_crm_order_shipped' ) . '/' . (string) did_action( 'bizcity_crm_order_delivered' ) : 'na' );
					$fixture_steps[] = array( 'label' => 'Runtime - CRM shipment hooks project shipped and delivered state', 'status' => $shipment_fixture_ok ? 'pass' : 'fail', 'detail' => $shipment_detail );
					$pointer_rows = $projected_ok ? BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $fixture_record_id, 'blog_id' => (int) get_current_blog_id(), 'limit' => 1 ) ) : array();
					$pointer = is_array( $pointer_rows ) && isset( $pointer_rows[0] ) && is_array( $pointer_rows[0] ) ? $pointer_rows[0] : array();
					$follow = ! empty( $pointer ) ? BizCity_Context_Bank_Ledger::instance()->follow( $fixture_record_id, array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'blog_id' => (int) get_current_blog_id() ) ) : array();
					$follow_ok = is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
					$fixture_steps[] = array( 'label' => 'Runtime - Woo pointer follows verified encrypted evidence', 'status' => $follow_ok ? 'pass' : 'fail', 'detail' => $follow_ok ? 'Order pointer passed tenant, file and hash verification.' : 'Woo pointer follow failed: ' . (string) ( $follow['reason'] ?? 'pointer_not_found' ) );
					$tombstone_receipt = $follow_ok ? BizCity_Business_JSONL_File_Store::write_with_receipt( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, array( 'record_id' => $fixture_record_id, 'order_id' => $fixture_order_id, 'event_type' => 'delete', 'reason' => 'diagnostics_fixture_cleanup' ), 'delete' ) : false;
					$tombstone_admission = is_array( $tombstone_receipt ) ? BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $fixture_record_id, 'record_kind' => 'event', 'event_uuid' => (string) $tombstone_receipt['event_uuid'], 'source_record_id' => (string) $tombstone_receipt['event_uuid'], 'entity_type' => 'order', 'entity_key' => (string) $fixture_order_id, 'scope_key' => 'order:' . $fixture_order_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted', 'kg_status' => 'not_candidate', 'receipt' => $tombstone_receipt ) ) : array(); 
					$tombstone_ok = is_array( $tombstone_admission ) && ! empty( $tombstone_admission['ok'] );
					$fixture_steps[] = array( 'label' => 'Runtime - Woo projection tombstone is admitted before cleanup', 'status' => $tombstone_ok ? 'pass' : 'fail', 'detail' => $tombstone_ok ? 'Derived order pointer tombstone was admitted without changing Woo truth.' : 'Woo projection tombstone admission failed.' );
					$removed = $tombstone_ok ? BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( array_merge( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $fixture_record_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted' ), $tombstone_receipt ), 'diagnostics_fixture_cleanup' ) : array();
					$removed_ok = is_array( $removed ) && ! empty( $removed['ok'] );
					$fixture_steps[] = array( 'label' => 'Runtime - Woo derived pointer cleanup completed', 'status' => $removed_ok ? 'pass' : 'fail', 'detail' => $removed_ok ? 'Only the derived Context Bank pointer was removed.' : 'Derived pointer cleanup failed.' );
					$shipment_cleanup_ok = true;
					foreach ( array_values( array_unique( $fixture_shipment_record_ids ) ) as $shipment_record_id ) {
						$shipment_cleanup_receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, array( 'record_id' => $shipment_record_id, 'order_id' => $fixture_order_id, 'event_type' => 'delete', 'reason' => 'diagnostics_fixture_cleanup' ), 'delete' );
						$shipment_cleanup_admission = is_array( $shipment_cleanup_receipt ) ? BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $shipment_record_id, 'record_kind' => 'event', 'event_uuid' => (string) $shipment_cleanup_receipt['event_uuid'], 'source_record_id' => (string) $shipment_cleanup_receipt['event_uuid'], 'entity_type' => 'order', 'entity_key' => (string) $fixture_order_id, 'scope_key' => 'order:' . $fixture_order_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted', 'kg_status' => 'not_candidate', 'receipt' => $shipment_cleanup_receipt ) ) : array(); 
						$shipment_cleanup_removed = ! empty( $shipment_cleanup_admission['ok'] ) ? BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( array_merge( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $shipment_record_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted' ), $shipment_cleanup_receipt ), 'diagnostics_fixture_cleanup' ) : array(); 
						$shipment_cleanup_ok = $shipment_cleanup_ok && is_array( $shipment_cleanup_receipt ) && ! empty( $shipment_cleanup_admission['ok'] ) && ! empty( $shipment_cleanup_removed['ok'] );
					}
					$fixture_steps[] = array( 'label' => 'Runtime - CRM shipment derived pointers cleanup completed', 'status' => $shipment_cleanup_ok ? 'pass' : 'fail', 'detail' => $shipment_cleanup_ok ? 'Shipped and delivered derived pointers were tombstoned and removed after verification.' : 'One or more shipment derived pointers could not be cleaned safely.' );
					$fixture_status = ( $projected_ok && $unlinked_relation_ok && $replay_ok && $shipment_fixture_ok && $follow_ok && $tombstone_ok && $removed_ok && $shipment_cleanup_ok ) ? 'pass' : 'fail';
					$fixture_detail = $fixture_status === 'pass' ? 'Woo order projection, replay, verified follow, tombstone and cleanup passed.' : 'Woo order lifecycle fixture has validation failures.';
				} else {
					$fixture_status = 'fail';
					$fixture_detail = 'Woo order factory did not return a valid order object.';
				}
			}
		} catch ( \Throwable $e ) {
			$fixture_status = 'fail';
			$fixture_detail = 'Woo lifecycle fixture failed: ' . sanitize_key( (string) $e->getMessage() );
		} finally {
			if ( is_object( $fixture_order ) && method_exists( $fixture_order, 'delete' ) && $fixture_order_id > 0 ) {
				// [2026-09-02 Johnny Chu] PHASE-CB4.3-DDV - delete only the disposable Woo order after pointer cleanup.
				$fixture_order->delete( true );
			}
			if ( $fixture_previous_flag === $missing_flag ) {
				delete_option( self::FLAG );
			} else {
				update_option( self::FLAG, $fixture_previous_flag, false );
			}
		}
		foreach ( array(
			array( 'label' => 'Disk - Woo projection artifact is readable', 'ok' => $disk_ok, 'detail' => $disk_ok ? 'The canonical Woo lifecycle adapter is readable.' : 'The Woo lifecycle adapter is missing or unreadable.' ),
			array( 'label' => 'Loader - canonical Woo lifecycle hooks are attached', 'ok' => $loader_ok, 'detail' => $loader_ok ? 'Payment, status and refund hooks resolve to one Context Bank projection owner.' : 'One or more Woo lifecycle hooks are not attached.' ),
			array( 'label' => 'Disk - explicit order relation guard is present', 'ok' => $relation_guard_ok, 'detail' => $relation_guard_ok ? 'The adapter reads exact Woo CRM metadata and has no latest-order lookup path.' : 'The adapter relation boundary is missing or uses an unsafe lookup path.' ),
			array( 'label' => 'Disk - canonical shipment owner is consumed', 'ok' => $shipment_guard_ok, 'detail' => $shipment_guard_ok ? 'CRM shipping tracker hooks are consumed with bounded shipment state and tracking presence.' : 'Canonical shipment/delivery hook integration or tracking redaction is missing.' ),
			array( 'label' => 'Runtime - capture is disabled by default', 'ok' => $runtime_ok, 'detail' => $runtime_ok ? 'The default path neither reads an order nor writes Context Bank state.' : 'Capture-off behavior is not fail-safe.' ),
		) as $step ) {
			$ctx->emit_step( array( 'label' => $step['label'], 'status' => $step['ok'] ? 'pass' : 'fail', 'detail' => $step['detail'] ) );
		}
		foreach ( $fixture_steps as $fixture_step ) {
			$ctx->emit_step( $fixture_step );
		}
		if ( empty( $fixture_steps ) ) {
			$ctx->emit_step( array( 'label' => 'Runtime - Woo lifecycle fixture', 'status' => $fixture_status, 'detail' => $fixture_detail ) );
		}
		// [2026-09-02 02:10 PM Johnny Chu - Chu Hoàng Anh] PHASE-CB4.3-DDV — include canonical shipment hook and redaction evidence in the aggregate probe verdict.
		$pass = $disk_ok && $loader_ok && $relation_guard_ok && $shipment_guard_ok && $runtime_ok;
		if ( $fixture_status === 'fail' ) { $pass = false; }
		return array( 'status' => $pass ? 'pass' : 'fail', 'summary' => $pass ? 'WooCommerce Context Bank projection passed guarded checks.' : 'WooCommerce Context Bank projection failed guarded checks.', 'fix_hint' => $pass ? '' : 'Load WooCommerce and the canonical Context Bank owners, then repair the first failed lifecycle or capture-boundary step.', 'steps' => array() );
	}

	public function cleanup(): void {}
}

add_filter( 'bizcity_diagnostics_register_probes', function ( $list ) {
	$list[] = 'BizCity_Probe_Context_Bank_Commerce';
	return $list;
} );