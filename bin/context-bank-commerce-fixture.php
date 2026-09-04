<?php
/**
 * Run a disposable linked WooCommerce Context Bank lifecycle fixture outside Diagnostics CLI.
 *
 * Usage:
 *   php bin/context-bank-commerce-fixture.php --wp-root=/path/to/wp --host=example.com --blog=1511 --user=3539 --confirm=G3
 *
 * @package BizCity_Twin_AI
 * @subpackage Bin
 * @since 2026-09-02 (PHASE-CB-G3)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-commerce-fixture.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array( 'wp-root' => '', 'host' => '', 'blog' => 0, 'user' => 0, 'confirm' => '', 'provision' => false );
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( strpos( $argument, '--' ) !== 0 || strpos( $argument, '=' ) === false ) {
		continue;
	}
	list( $key, $value ) = explode( '=', substr( $argument, 2 ), 2 );
	if ( array_key_exists( $key, $options ) ) {
		$options[ $key ] = $value;
	}
}

if ( (string) $options['confirm'] !== 'G3' ) {
	fwrite( STDERR, "Refusing fixture: pass --confirm=G3.\n" );
	exit( 2 );
}
if ( (int) $options['blog'] <= 0 || (int) $options['user'] <= 0 ) {
	fwrite( STDERR, "Refusing fixture: pass explicit --blog=<id> and --user=<admin-id>.\n" );
	exit( 2 );
}
if ( (string) $options['host'] === '' || preg_match( '/[^A-Za-z0-9.:-]/', (string) $options['host'] ) ) {
	fwrite( STDERR, "Refusing fixture: pass --host=example.com.\n" );
	exit( 2 );
}
$wp_root = (string) $options['wp-root'];
if ( $wp_root === '' ) {
	$wp_root = (string) ( getenv( 'BIZCITY_WP_ROOT' ) ?: '' );
}
if ( $wp_root === '' || ! is_file( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) || ! is_readable( rtrim( $wp_root, '/\\' ) . '/wp-load.php' ) ) {
	fwrite( STDERR, "Cannot locate readable wp-load.php. Use --wp-root=/path/to/wordpress.\n" );
	exit( 2 );
}
if ( defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI ) {
	fwrite( STDERR, "Refusing fixture inside Diagnostics CLI context.\n" );
	exit( 2 );
}

$_SERVER['HTTP_HOST'] = (string) $options['host'];
$_SERVER['SERVER_NAME'] = (string) $options['host'];
define( 'WP_USE_THEMES', false );
require rtrim( $wp_root, '/\\' ) . '/wp-load.php';
if ( function_exists( 'wp_set_current_user' ) ) {
	wp_set_current_user( (int) $options['user'] );
}
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
	fwrite( STDERR, "Refusing fixture: --user must have manage_options.\n" );
	exit( 3 );
}

$plugin_root = dirname( __DIR__ );
if ( ! defined( 'BIZCITY_DIAGNOSTICS_DIR' ) ) {
	define( 'BIZCITY_DIAGNOSTICS_DIR', $plugin_root . '/core/diagnostics/' );
}
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
$context_bootstrap = $plugin_root . '/core/context-bank/bootstrap.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $context_bootstrap ) || ! is_readable( $context_bootstrap ) || ! BizCity_Safe_Loader::require_file( $context_bootstrap, 'context_bank.commerce_fixture' ) ) {
	fwrite( STDERR, "Context Bank bootstrap could not be loaded.\n" );
	exit( 3 );
}

$original_blog = (int) get_current_blog_id();
$target_blog = (int) $options['blog'];
$switched = false;
if ( $target_blog !== $original_blog ) {
	$switched = switch_to_blog( $target_blog );
	if ( ! $switched ) {
		fwrite( STDERR, "Target blog switch failed closed.\n" );
		exit( 3 );
	}
}
$result = array( 'contract' => 'context-bank-commerce-fixture', 'version' => '1', 'host' => (string) $options['host'], 'blog_id' => $target_blog, 'steps' => array(), 'status' => 'fail', 'reason' => '' );
$failures = array();
$deferred = array();
$step = static function ( $label, $status, $detail ) use ( &$result, &$failures, &$deferred ) {
	$result['steps'][] = array( 'label' => (string) $label, 'status' => (string) $status, 'detail' => (string) $detail );
	if ( $status === 'fail' ) {
		$failures[] = (string) $label;
	}
	if ( $status === 'deferred' ) {
		$deferred[] = (string) $label;
	}
};
$missing_flag = '__cb_g3_flag_missing__';
$previous_flag = get_option( 'bizcity_context_bank_capture_enabled', $missing_flag );
$order = null;
$order_id = 0;
$product = null;
$product_id = 0;
$record_ids = array();
$cleanup_pointer = static function ( $record_id ) {
	$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, array( 'record_id' => $record_id, 'event_type' => 'delete', 'reason' => 'g3_fixture_cleanup' ), 'delete' );
	if ( ! is_array( $receipt ) ) {
		return false;
	}
	$admission = BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $record_id, 'record_kind' => 'event', 'event_uuid' => (string) $receipt['event_uuid'], 'source_record_id' => (string) $receipt['event_uuid'], 'entity_type' => 'order', 'entity_key' => (string) $GLOBALS['_bizcity_g3_fixture_order_id'], 'scope_key' => 'order:' . (string) $GLOBALS['_bizcity_g3_fixture_order_id'], 'operation' => 'delete', 'lifecycle_status' => 'deleted', 'kg_status' => 'not_candidate', 'receipt' => $receipt ) );
	if ( empty( $admission['ok'] ) ) {
		return false;
	}
	return ! empty( BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( array_merge( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $record_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted' ), $receipt ), 'g3_fixture_cleanup' )['ok'] );
};

try {
	if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
		$step( 'Runtime - WooCommerce lifecycle owner available', 'deferred', 'WooCommerce order/product APIs are unavailable on the selected tenant.' );
		$result['reason'] = 'woocommerce_runtime_unavailable';
		throw new RuntimeException( 'woocommerce_runtime_unavailable' );
	}
	if ( ! class_exists( 'BizCity_Context_Bank_Commerce_Adapter' ) || ! class_exists( 'BizCity_Business_JSONL_File_Store' ) || ! class_exists( 'BizCity_Context_Bank_Ledger' ) ) {
		$step( 'Loader - Commerce Context Bank owners available', 'fail', 'Commerce adapter, business filestore or ledger is unavailable.' );
		throw new RuntimeException( 'commerce_fixture_dependency_missing' );
	}
	if ( ! empty( $options['provision'] ) && class_exists( 'BizCity_Site_Provisioner' ) ) {
		foreach ( array( 'class-diagnostics-table-registry.php', 'class-diagnostics-table-inspector.php', 'class-diagnostics-changelog-loader.php', 'class-diagnostics-auto-create.php', 'class-site-provisioner.php', 'installer-registry.php' ) as $artifact ) {
			BizCity_Safe_Loader::require_file( $plugin_root . '/core/diagnostics/includes/' . $artifact, 'context_bank.commerce_fixture.' . $artifact );
		}
		if ( function_exists( 'bizcity_register_default_installers' ) ) {
			bizcity_register_default_installers();
		}
		foreach ( BizCity_Site_Provisioner::get_installers() as $installer ) {
			if ( is_array( $installer ) && (string) ( $installer['id'] ?? '' ) === 'context_bank' ) {
				call_user_func( $installer['callback'] );
				break;
			}
		}
	}
	if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( BizCity_Context_Bank_Ledger::table() ) ) {
			$step( 'Runtime - Context Bank ledger is provisioned', 'fail', 'Provision the selected tenant before running the fixture.' );
			throw new RuntimeException( 'context_bank_not_provisioned' );
	}
	$step( 'Loader - Commerce Context Bank owners available', 'pass', 'Woo, adapter, encrypted filestore and pointer ledger owners are loaded.' );
	update_option( 'bizcity_context_bank_capture_enabled', true, false );
	$order = wc_create_order();
	if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
		throw new RuntimeException( 'woo_order_create_failed' );
	}
	$order_id = (int) $order->get_id();
	$GLOBALS['_bizcity_g3_fixture_order_id'] = $order_id;
	$product = new WC_Product_Simple();
	$product->set_name( 'Context Bank G3 disposable product' );
	$product->set_sku( 'cb-g3-' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) ) );
	$product->set_regular_price( '1000' );
	$product->set_price( '1000' );
	$product->save();
	$product_id = (int) $product->get_id();
	$order->set_created_via( 'bizcity_context_bank_fixture' );
	$order->set_currency( 'VND' );
	$order->add_product( $product, 2 );
	$order->update_meta_data( '_bizcity_crm_contact_id', 700001 );
	$order->update_meta_data( '_bizcity_crm_conversation_id', 800001 );
	$order->update_meta_data( '_bizcity_crm_conversation_ids', array( 800001, 800002 ) );
	$order->save();
	$step( 'Runtime - linked disposable Woo order created', 'pass', 'Woo created one disposable order with exact CRM relation metadata and one SKU.' );
	$events = array(
		array( 'name' => 'created', 'old' => '', 'new' => 'pending', 'refund' => 0 ),
		array( 'name' => 'payment_complete', 'old' => '', 'new' => 'paid', 'refund' => 0 ),
		array( 'name' => 'status_changed', 'old' => 'pending', 'new' => 'processing', 'refund' => 0 ),
		array( 'name' => 'status_changed', 'old' => 'processing', 'new' => 'cancelled', 'refund' => 0 ),
		array( 'name' => 'refunded', 'old' => '', 'new' => 'refunded', 'refund' => 900001 ),
		array( 'name' => 'shipment', 'old' => 'processing', 'new' => 'shipped', 'refund' => 0 ),
		array( 'name' => 'delivery', 'old' => 'shipped', 'new' => 'delivered', 'refund' => 0 ),
	);
	$parent = '';
	foreach ( $events as $event ) {
		$projection = BizCity_Context_Bank_Commerce_Adapter::project( $order_id, $event['name'], $event['old'], $event['new'], $event['refund'], array( 'parent_record_id' => $parent, 'inventory' => array( 'warehouse_id' => 'wh_fixture', 'source_version' => 'inventory-fixture-v1' ) ) );
		$ok = is_array( $projection ) && ! empty( $projection['ok'] ) && ! empty( $projection['projected'] );
		if ( $ok ) {
			$record_ids[] = (string) $projection['record_id'];
			$parent = (string) $projection['record_id'];
		}
		$step( 'Runtime - lifecycle event ' . $event['name'] . ' projected', $ok ? 'pass' : 'fail', $ok ? 'Canonical Woo event produced one deterministic Context Bank pointer.' : 'Projection failed: ' . (string) ( $projection['reason'] ?? 'unknown' ) );
	}
	$record_ids = array_values( array_unique( $record_ids ) );
	$relation = end( $projection ) && is_array( $projection['relationship'] ?? null ) ? $projection['relationship'] : array();
	$relation_ok = (int) ( $relation['contact_id'] ?? 0 ) === 700001 && (array) ( $relation['conversation_ids'] ?? array() ) === array( 800001, 800002 );
	$step( 'Runtime - explicit contact and multiple conversation relations preserved', $relation_ok ? 'pass' : 'fail', $relation_ok ? 'Exact Woo CRM relation metadata survived projection without latest-conversation lookup.' : 'Linked contact or conversation relation list was not preserved.' );
	$correction_parent_id = (string) $parent;
	$correction = BizCity_Context_Bank_Commerce_Adapter::project( $order_id, 'refunded', 'completed', 'refunded', 900002, array( 'parent_record_id' => $correction_parent_id, 'inventory' => array( 'warehouse_id' => 'wh_fixture', 'source_version' => 'inventory-fixture-v1' ) ) );
	$correction_ok = is_array( $correction ) && ! empty( $correction['ok'] ) && ! empty( $correction['projected'] );
	$correction_record_id = (string) ( $correction['record_id'] ?? '' );
	if ( $correction_record_id !== '' ) {
		$record_ids[] = $correction_record_id;
	}
	$correction_pointer_rows = $correction_record_id !== '' ? BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $correction_record_id, 'blog_id' => $target_blog, 'limit' => 1 ) ) : array();
	$correction_pointer = isset( $correction_pointer_rows[0] ) && is_array( $correction_pointer_rows[0] ) ? $correction_pointer_rows[0] : array();
	$correction_ok = $correction_ok && (string) ( $correction_pointer['parent_record_id'] ?? '' ) === $correction_parent_id;
	$correction_replay = $correction_ok ? BizCity_Context_Bank_Commerce_Adapter::project( $order_id, 'refunded', 'completed', 'refunded', 900002, array( 'parent_record_id' => $correction_parent_id, 'inventory' => array( 'warehouse_id' => 'wh_fixture', 'source_version' => 'inventory-fixture-v1' ) ) ) : array();
	$correction_replay_ok = is_array( $correction_replay ) && ! empty( $correction_replay['ok'] ) && ! empty( $correction_replay['replayed'] );
	$step( 'Runtime - late commerce correction preserves superseded parent and replays idempotently', $correction_ok && $correction_replay_ok ? 'pass' : 'fail', $correction_ok && $correction_replay_ok ? 'The correction points to the prior lifecycle state and the same correction replays without a duplicate pointer.' : 'Commerce correction parent provenance or replay idempotency failed.' );
	$replay = BizCity_Context_Bank_Commerce_Adapter::project( $order_id, 'delivery', 'shipped', 'delivered', 0, array( 'inventory' => array( 'warehouse_id' => 'wh_fixture', 'source_version' => 'inventory-fixture-v1' ) ) );
	$replay_ok = is_array( $replay ) && ! empty( $replay['ok'] ) && ! empty( $replay['replayed'] );
	$step( 'Runtime - lifecycle replay is idempotent', $replay_ok ? 'pass' : 'fail', $replay_ok ? 'The same deterministic delivery event returned replay success without a duplicate pointer.' : 'Lifecycle replay did not return idempotent success.' );
	$inventory_ok = is_array( $projection['inventory'] ?? null ) && (string) ( $projection['inventory']['warehouse_id'] ?? '' ) === 'wh_fixture' && (string) ( $projection['inventory']['source_version'] ?? '' ) === 'inventory-fixture-v1';
	$step( 'Runtime - bounded warehouse and inventory source provenance carried', $inventory_ok ? 'pass' : 'fail', $inventory_ok ? 'The projection carried bounded warehouse/source-version provenance supplied by the explicit producer context.' : 'Warehouse/source-version provenance was missing or unbounded.' );
	$follow_ok = true;
	foreach ( $record_ids as $record_id ) {
		$follow = BizCity_Context_Bank_Ledger::instance()->follow( $record_id, array( 'blog_id' => $target_blog, 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID ) );
		$follow_ok = $follow_ok && is_array( $follow ) && ! empty( $follow['ok'] ) && ! empty( $follow['verified'] );
	}
	$step( 'Runtime - every lifecycle pointer follows verified encrypted evidence', $follow_ok ? 'pass' : 'fail', $follow_ok ? 'All deterministic lifecycle pointers passed tenant, receipt and hash verification.' : 'At least one lifecycle pointer failed verified follow.' );
	$payload_safe = true;
	foreach ( array_values( array_unique( $record_ids ) ) as $record_id ) {
		$payload_pointer_rows = BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, 'record_id' => $record_id, 'blog_id' => $target_blog, 'limit' => 1 ) );
		$payload_pointer = isset( $payload_pointer_rows[0] ) && is_array( $payload_pointer_rows[0] ) ? $payload_pointer_rows[0] : array();
		$payload_body = ! empty( $payload_pointer ) ? BizCity_Business_JSONL_File_Store::read_receipt( BizCity_Context_Bank_Commerce_Adapter::CONTRACT_ID, $payload_pointer ) : array();
		$payload_text = is_array( $payload_body['record'] ?? null ) ? strtolower( (string) wp_json_encode( $payload_body['record'] ) ) : '';
		$payload_safe = $payload_safe && ! preg_match( '/"(?:password|api_key|access_token|bearer|secret|card_number|cvv|payment_token|tracking_number)"\s*:/', $payload_text );
	}
	$step( 'Runtime - projected commerce payload excludes payment secrets and raw PII', $payload_safe ? 'pass' : 'fail', $payload_safe ? 'Every disposable lifecycle payload omitted secret/payment fields and raw tracking numbers.' : 'A projected lifecycle payload contains a forbidden secret or raw sensitive field.' );
	$step( 'Runtime - canonical inventory producer integration', 'deferred', 'The workspace has no registered production inventory producer; fixture proves only the bounded producer-context contract.' );
	$result['status'] = empty( $failures ) ? ( empty( $deferred ) ? 'pass' : 'partial' ) : 'fail';
} catch ( Throwable $error ) {
	if ( $result['reason'] === '' ) {
		$result['reason'] = sanitize_key( (string) $error->getMessage() );
	}
	if ( empty( $failures ) && empty( $deferred ) ) {
		$result['status'] = 'fail';
	}
} finally {
	foreach ( array_values( array_unique( $record_ids ) ) as $record_id ) {
		if ( ! $cleanup_pointer( $record_id ) ) {
			$failures[] = 'cleanup_' . $record_id;
		}
	}
	if ( is_object( $order ) && method_exists( $order, 'delete' ) && $order_id > 0 ) {
		$order->delete( true );
	}
	if ( is_object( $product ) && method_exists( $product, 'delete' ) && $product_id > 0 ) {
		$product->delete( true );
	}
	if ( $previous_flag === $missing_flag ) {
		delete_option( 'bizcity_context_bank_capture_enabled' );
	} else {
		update_option( 'bizcity_context_bank_capture_enabled', $previous_flag, false );
	}
	if ( $switched ) {
		restore_current_blog();
	}
	if ( ! empty( $failures ) ) {
		$result['status'] = 'fail';
		$result['reason'] = $result['reason'] !== '' ? $result['reason'] : 'fixture_cleanup_or_projection_failure';
	}
	$result['cleanup'] = array( 'record_count' => count( array_unique( $record_ids ) ), 'order_deleted' => $order_id > 0, 'product_deleted' => $product_id > 0, 'blog_restored' => (int) get_current_blog_id() === $original_blog );
}

$result['failures'] = array_values( array_unique( $failures ) );
$result['deferred'] = array_values( array_unique( $deferred ) );
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $result['status'] === 'pass' ? 0 : 1 );
