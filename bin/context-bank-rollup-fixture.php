<?php
/**
 * Run one disposable Context Bank rollup worker fixture outside Diagnostics CLI.
 *
 * This command is intentionally explicit and bounded. It creates two synthetic
 * ledger source pointers, processes one conversation-state batch, verifies the
 * persisted checkpoint on a second call, then tombstones and removes only the
 * derived fixture pointers and source records.
 *
 * Usage:
 *   php bin/context-bank-rollup-fixture.php --wp-root=/path/to/wp --host=example.com --user=1 --confirm=CB5.1
 *
 * @package BizCity_Twin_AI
 * @subpackage Bin
 * @since 2026-09-03 (PHASE-CB5.1)
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "context-bank-rollup-fixture.php must be run from CLI.\n" );
	exit( 2 );
}

$options = array(
	'wp-root' => '',
	'host' => '',
	'user' => 0,
	'confirm' => '',
	'provision' => false,
	'format' => 'json',
);
foreach ( array_slice( $argv, 1 ) as $argument ) {
	if ( strpos( $argument, '--' ) !== 0 || strpos( $argument, '=' ) === false ) {
		continue;
	}
	list( $key, $value ) = explode( '=', substr( $argument, 2 ), 2 );
	if ( array_key_exists( $key, $options ) ) {
		$options[ $key ] = $value;
	}
}

if ( (string) $options['confirm'] !== 'CB5.1' ) {
	fwrite( STDERR, "Refusing fixture: pass --confirm=CB5.1.\n" );
	exit( 2 );
}
if ( (int) $options['user'] <= 0 ) {
	fwrite( STDERR, "Refusing fixture: pass an explicit --user=<admin-id>.\n" );
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
	// [2026-09-03 Johnny Chu] PHASE-CB5.1 - expose the canonical changelog root to the explicitly requested Site Provisioner path.
	define( 'BIZCITY_DIAGNOSTICS_DIR', $plugin_root . '/core/diagnostics/' );
}
$safe_loader = $plugin_root . '/core/helper/class-bizcity-safe-loader.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) && is_file( $safe_loader ) && is_readable( $safe_loader ) ) {
	require_once $safe_loader;
}
$context_bootstrap = $plugin_root . '/core/context-bank/bootstrap.php';
if ( ! class_exists( 'BizCity_Safe_Loader', false ) || ! is_file( $context_bootstrap ) || ! is_readable( $context_bootstrap ) || ! BizCity_Safe_Loader::require_file( $context_bootstrap, 'context_bank.rollup_fixture' ) ) {
	fwrite( STDERR, "Context Bank bootstrap could not be loaded.\n" );
	exit( 3 );
}

if ( ! class_exists( 'BizCity_Cron_Manager', false ) ) {
	$cron_manager_file = $plugin_root . '/core/cron/includes/class-cron-manager.php';
	if ( is_file( $cron_manager_file ) && is_readable( $cron_manager_file ) ) {
		BizCity_Safe_Loader::require_file( $cron_manager_file, 'context_bank.rollup_fixture.cron_manager' );
	}
	unset( $cron_manager_file );
}

if ( ! empty( $options['provision'] ) ) {
	// [2026-09-03 Johnny Chu] PHASE-CB5.1 - provision only the registered rollup-state installer when an operator explicitly opts in.
	foreach ( array( 'class-diagnostics-table-registry.php', 'class-diagnostics-table-inspector.php', 'class-diagnostics-changelog-loader.php', 'class-diagnostics-auto-create.php', 'class-site-provisioner.php', 'installer-registry.php' ) as $diagnostics_artifact ) {
		$diagnostics_file = $plugin_root . '/core/diagnostics/includes/' . $diagnostics_artifact;
		if ( ! BizCity_Safe_Loader::require_file( $diagnostics_file, 'context_bank.rollup_fixture.' . $diagnostics_artifact ) ) {
			fwrite( STDERR, "Required Site Provisioner artifact could not be loaded.\n" );
			exit( 3 );
		}
	}
	unset( $diagnostics_artifact, $diagnostics_file );
	if ( ! class_exists( 'BizCity_Site_Provisioner' ) || ! method_exists( 'BizCity_Site_Provisioner', 'get_installers' ) ) {
		fwrite( STDERR, "Site Provisioner is unavailable.\n" );
		exit( 3 );
	}
	if ( function_exists( 'bizcity_register_default_installers' ) ) {
		bizcity_register_default_installers();
	}
	$provisioned = false;
	foreach ( BizCity_Site_Provisioner::get_installers() as $installer ) {
		if ( ! is_array( $installer ) || (string) ( $installer['id'] ?? '' ) !== 'context_bank_rollup_state' ) {
			continue;
		}
		call_user_func( $installer['callback'] );
		$provisioned = true;
		break;
	}
	if ( ! $provisioned ) {
		fwrite( STDERR, "Registered rollup-state installer is unavailable.\n" );
		exit( 3 );
	}
}

$failures = array();
$result = array( 'contract' => 'context-bank-rollup-fixture', 'version' => '1', 'blog_id' => (int) get_current_blog_id(), 'host' => (string) $options['host'], 'steps' => array() );
$step = static function ( $label, $status, $detail ) use ( &$result, &$failures ) {
	$result['steps'][] = array( 'label' => (string) $label, 'status' => (string) $status, 'detail' => (string) $detail );
	if ( $status !== 'pass' ) {
		$failures[] = (string) $label;
	}
};

$required_classes = array( 'BizCity_Business_JSONL_File_Store', 'BizCity_Context_Bank_Ledger', 'BizCity_Context_Bank_Rollup_Engine', 'BizCity_Context_Bank_Rollup_Registry', 'BizCity_Context_Bank_Rollup_Worker', 'BizCity_File_Contract_Registry' );
foreach ( $required_classes as $class_name ) {
	if ( ! class_exists( $class_name ) ) {
		$step( 'Loader - ' . $class_name, 'fail', 'Required rollup fixture owner is not loaded.' );
		echo wp_json_encode( array_merge( $result, array( 'status' => 'fail', 'reason' => 'fixture_dependency_missing' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
		exit( 1 );
	}
}
$ledger_table = BizCity_Context_Bank_Ledger::table();
$worker_table = BizCity_Context_Bank_Rollup_Worker::table();
if ( function_exists( 'bizcity_tbl_exists' ) && ( ! bizcity_tbl_exists( $ledger_table ) || ! bizcity_tbl_exists( $worker_table ) ) ) {
	$step( 'Runtime - tenant ledger and rollup state tables exist', 'fail', 'Provision the current tenant through Site Provisioner before running this fixture.' );
	echo wp_json_encode( array_merge( $result, array( 'status' => 'fail', 'reason' => 'rollup_state_not_provisioned' ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	exit( 1 );
}
$step( 'Loader - canonical rollup worker owners are loaded', 'pass', 'Filestore, ledger, reducer, registry and worker APIs are available.' );

$flag = 'bizcity_context_bank_rollups_enabled';
$missing_flag = '__rollup_fixture_flag_missing__';
$previous_flag = get_option( $flag, $missing_flag );
$source_contract = 'core.context_bank.commerce_order';
// [2026-09-02 11:29 AM Johnny Chu - Chu Hoàng Anh] PHASE-CB5 — keep the disposable worker dimension identical to the source conversation identity.
$conversation_id = 990000001;
$dimension_key = (string) $conversation_id;
$source_records = array();
$rollup_record_id = '';
$initial_rollup_record_id = '';
$reopened_rollup_record_id = '';

$cleanup_pointer = static function ( $contract_id, $record_id, $record_kind, $entity_type, $entity_key ) {
	$ledger = BizCity_Context_Bank_Ledger::instance();
	$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( $contract_id, array( 'record_id' => $record_id, 'event_type' => 'delete', 'reason' => 'cb51_fixture_cleanup' ), 'delete' );
	if ( ! is_array( $receipt ) ) {
		return array( 'ok' => false, 'reason' => 'fixture_tombstone_write_failed' );
	}
	$admission = $ledger->record( array( 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'record_kind' => $record_kind, 'event_uuid' => (string) $receipt['event_uuid'], 'source_record_id' => (string) $receipt['event_uuid'], 'entity_type' => $entity_type, 'entity_key' => $entity_key, 'scope_key' => $entity_key, 'operation' => 'delete', 'lifecycle_status' => 'deleted', 'kg_status' => 'not_candidate', 'receipt' => $receipt ) );
	if ( empty( $admission['ok'] ) ) {
		return array( 'ok' => false, 'reason' => (string) ( $admission['reason'] ?? 'fixture_tombstone_admission_failed' ) );
	}
	return $ledger->remove_tombstoned_pointer( array_merge( array( 'contract_id' => $contract_id, 'source_contract_id' => $contract_id, 'record_id' => $record_id, 'operation' => 'delete', 'lifecycle_status' => 'deleted' ), $receipt ), 'cb51_fixture_cleanup' );
};

try {
	update_option( $flag, true, false );
	foreach ( array( 'inbound' => 'crm_message_received', 'outbound' => 'crm_message_sent' ) as $direction => $event_type ) {
		$record_id = 'cb51_fixture_event_' . $direction . '_' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
		$record = array( 'record_id' => $record_id, 'event_type' => $event_type, 'direction' => $direction, 'channel' => 'facebook', 'status' => $direction === 'inbound' ? 'received' : 'sent', 'conversation_id' => $conversation_id, 'identity_uuid' => 'cb51_fixture_identity', 'occurred_at' => $direction === 'inbound' ? '2026-09-02 10:00:00' : '2026-09-02 10:01:00' );
		$receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( $source_contract, $record, 'upsert' );
		if ( ! is_array( $receipt ) ) {
			throw new RuntimeException( 'fixture_source_receipt_failed' );
		}
		$source_records[] = array( 'contract_id' => $source_contract, 'record_id' => $record_id, 'receipt' => $receipt );
		$admission = BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => $source_contract, 'record_id' => $record_id, 'record_kind' => 'event', 'event_uuid' => (string) $receipt['event_uuid'], 'source_record_id' => (string) $receipt['event_uuid'], 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'scope_key' => $dimension_key, 'conversation_id' => $conversation_id, 'identity_uuid' => 'cb51_fixture_identity', 'channel' => 'facebook', 'kg_status' => 'not_candidate', 'receipt' => $receipt ) );
		if ( empty( $admission['ok'] ) ) {
			throw new RuntimeException( 'fixture_source_ledger_failed' );
		}
	}
	$step( 'Runtime - synthetic source pointers admitted', 'pass', 'Two bounded source events were admitted to the current tenant ledger.' );
	$interrupt_once = true;
	$interrupted = array();
	$checkpoint_filter = static function ( $allowed, $rollup_id, $dimension_key, $record_id, $output_hash ) use ( &$interrupt_once, &$interrupted ) {
		if ( $interrupt_once ) {
			$interrupt_once = false;
			$interrupted = array( 'rollup_id' => $rollup_id, 'dimension_key' => $dimension_key, 'record_id' => $record_id, 'output_hash' => $output_hash );
			return false;
		}
		return $allowed;
	};
	add_filter( 'bizcity_context_bank_rollup_before_checkpoint', $checkpoint_filter, 10, 5 );
	$interrupted_run_id = 0;
	$interrupted_worker = static function () use ( $dimension_key, $source_contract, &$interrupted ) {
		$interrupted = BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', $dimension_key, array( 'source_contract_id' => $source_contract, 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'limit' => 10 ) );
	};
	if ( class_exists( 'BizCity_Cron_Manager' ) ) {
		$interrupted_run_id = BizCity_Cron_Manager::instance()->with_synthetic_run( 'lab.context_bank.rollup', $interrupted_worker );
	} else {
		$interrupted_worker();
	}
	remove_filter( 'bizcity_context_bank_rollup_before_checkpoint', $checkpoint_filter, 10 );
	$interrupted_ok = is_array( $interrupted ) && empty( $interrupted['ok'] ) && ! empty( $interrupted['interrupted'] ) && 'rollup_checkpoint_deferred' === (string) ( $interrupted['reason'] ?? '' ) && (string) ( $interrupted['record_id'] ?? '' ) !== '';
	$step( 'Runtime - interruption before checkpoint leaves output resumable', $interrupted_ok ? 'pass' : 'fail', $interrupted_ok ? 'The worker stopped after file and ledger success without advancing the checkpoint.' : 'The interruption did not leave a resumable durable output.' );
	$cron_meta_ok = false;
	if ( $interrupted_run_id > 0 ) {
		global $wpdb;
		$cron_table = $wpdb->prefix . 'bizcity_cron_runs';
		$cron_row = $wpdb->get_row( $wpdb->prepare( 'SELECT meta FROM ' . $cron_table . ' WHERE id = %d LIMIT 1', $interrupted_run_id ), ARRAY_A );
		$cron_meta = is_array( $cron_row ) ? json_decode( (string) ( $cron_row['meta'] ?? '' ), true ) : array();
		$cron_events = is_array( $cron_meta ) && is_array( $cron_meta['events'] ?? null ) ? wp_list_pluck( $cron_meta['events'], 'name' ) : array();
		$cron_meta_ok = in_array( 'rollup_batch_started', $cron_events, true ) && in_array( 'rollup_checkpoint_deferred', $cron_events, true );
	}
	$step( 'Runtime - rollup worker records R-CRON-META', $cron_meta_ok ? 'pass' : 'fail', $cron_meta_ok ? 'Synthetic cron run persisted bounded worker start and checkpoint-deferred events.' : 'Worker Cron Meta evidence was not persisted.' );

	$first = BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', $dimension_key, array( 'source_contract_id' => $source_contract, 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'limit' => 10 ) );
	$first_ok = $interrupted_ok && is_array( $first ) && ! empty( $first['ok'] ) && ! empty( $first['processed'] ) && ! empty( $first['replayed_output'] ) && (int) ( $first['input_count'] ?? 0 ) === 2;
	$rollup_record_id = (string) ( $first['record_id'] ?? '' );
	$step( 'Runtime - worker retries interrupted batch idempotently', $first_ok ? 'pass' : 'fail', $first_ok ? 'Worker reused the durable output and advanced the checkpoint without a duplicate pointer.' : 'Worker retry failed: ' . (string) ( $first['reason'] ?? 'unknown' ) );
	$checkpoint = BizCity_Context_Bank_Rollup_Worker::checkpoint( 'conversation_state', $dimension_key );
	$checkpoint_ok = $first_ok && (string) ( $checkpoint['checkpoint_record_id'] ?? '' ) !== '' && (string) ( $checkpoint['last_output_hash'] ?? '' ) !== '' && (int) ( $checkpoint['processed_count'] ?? 0 ) === 2;
	$step( 'Runtime - checkpoint persisted after file and ledger success', $checkpoint_ok ? 'pass' : 'fail', $checkpoint_ok ? 'Checkpoint contains the last source record, output hash and processed count.' : 'Checkpoint metadata was not persisted as expected.' );
	$second = BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', $dimension_key, array( 'source_contract_id' => $source_contract, 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'limit' => 10 ) );
	$resume_ok = is_array( $second ) && ! empty( $second['ok'] ) && empty( $second['processed'] ) && 'rollup_checkpoint_current' === (string) ( $second['reason'] ?? '' );
	$step( 'Runtime - second worker call resumes from checkpoint without duplicate output', $resume_ok ? 'pass' : 'fail', $resume_ok ? 'The persisted checkpoint made the second bounded call a no-op.' : 'Checkpoint resume did not return rollup_checkpoint_current.' );
	$initial_rollup_record_id = $rollup_record_id;
	$late_record_id = 'cb51_fixture_late_' . strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
	$late_record = array( 'record_id' => $late_record_id, 'event_type' => 'crm_message_received', 'direction' => 'inbound', 'channel' => 'facebook', 'status' => 'received', 'conversation_id' => $conversation_id, 'identity_uuid' => 'cb51_fixture_identity', 'occurred_at' => '2026-09-02 09:59:00', 'superseded_record_id' => $initial_rollup_record_id );
	$late_receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( $source_contract, $late_record, 'upsert' );
	if ( ! is_array( $late_receipt ) ) {
		throw new RuntimeException( 'fixture_late_receipt_failed' );
	}
	$source_records[] = array( 'contract_id' => $source_contract, 'record_id' => $late_record_id, 'receipt' => $late_receipt );
	$late_admission = BizCity_Context_Bank_Ledger::instance()->record( array( 'source_contract_id' => $source_contract, 'record_id' => $late_record_id, 'record_kind' => 'event', 'event_uuid' => (string) $late_receipt['event_uuid'], 'source_record_id' => (string) $late_receipt['event_uuid'], 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'scope_key' => $dimension_key, 'conversation_id' => $conversation_id, 'identity_uuid' => 'cb51_fixture_identity', 'channel' => 'facebook', 'parent_record_id' => $initial_rollup_record_id, 'kg_status' => 'not_candidate', 'receipt' => $late_receipt ) );
	if ( empty( $late_admission['ok'] ) ) {
		throw new RuntimeException( 'fixture_late_ledger_failed' );
	}
	$late_dirty = BizCity_Context_Bank_Rollup_Worker::mark_dirty( 'conversation_state', $dimension_key, '2026-09-02 09:59:00', $late_record_id, $initial_rollup_record_id );
	$late_dirty_ok = is_array( $late_dirty ) && ! empty( $late_dirty['ok'] ) && (string) ( $late_dirty['record_id'] ?? '' ) === $late_record_id && (string) ( $late_dirty['superseded_record_id'] ?? '' ) === $initial_rollup_record_id;
	$step( 'Runtime - late event reopens the affected rollup window', $late_dirty_ok ? 'pass' : 'fail', $late_dirty_ok ? 'Late source evidence marked one tenant rollup dimension dirty with superseded-state metadata.' : 'Late event did not create a durable dirty marker.' );
	$reopened = $late_dirty_ok ? BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', $dimension_key, array( 'source_contract_id' => $source_contract, 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'limit' => 10 ) ) : array();
	$reopened_rollup_record_id = (string) ( $reopened['record_id'] ?? '' );
	$reopened_ok = is_array( $reopened ) && ! empty( $reopened['ok'] ) && ! empty( $reopened['processed'] ) && ! empty( $reopened['reopened'] ) && $reopened_rollup_record_id !== '' && (string) ( $reopened['superseded_record_id'] ?? '' ) === $initial_rollup_record_id && (string) ( $reopened['output_hash'] ?? '' ) !== (string) ( $first['output_hash'] ?? '' );
	$step( 'Runtime - late event rebuilds from canonical evidence', $reopened_ok ? 'pass' : 'fail', $reopened_ok ? 'Worker rebuilt the bounded rollup with a new output hash and preserved the superseded rollup ID.' : 'Late-event rebuild did not produce a superseding rollup output.' );
	$reopened_pointer = $reopened_ok ? BizCity_Context_Bank_Ledger::instance()->find( array( 'source_contract_id' => BizCity_Context_Bank_Rollup_Worker::ROLLUP_CONTRACT_ID, 'record_id' => $reopened_rollup_record_id, 'blog_id' => (int) get_current_blog_id(), 'limit' => 1 ) ) : array();
	$reopened_parent_ok = $reopened_ok && is_array( $reopened_pointer ) && ! empty( $reopened_pointer[0] ) && (string) ( $reopened_pointer[0]['parent_record_id'] ?? '' ) === $initial_rollup_record_id;
	$step( 'Runtime - correction preserves superseded rollup provenance', $reopened_parent_ok ? 'pass' : 'fail', $reopened_parent_ok ? 'The rebuilt rollup pointer references the superseded derived state.' : 'The rebuilt rollup pointer lost superseded-state provenance.' );
	$replay_dirty = $reopened_ok ? BizCity_Context_Bank_Rollup_Worker::mark_dirty( 'conversation_state', $dimension_key, '2026-09-02 09:59:00', $late_record_id, $initial_rollup_record_id ) : array();
	$correction_replay = ! empty( $replay_dirty['ok'] ) ? BizCity_Context_Bank_Rollup_Worker::process( 'conversation_state', $dimension_key, array( 'source_contract_id' => $source_contract, 'entity_type' => 'conversation', 'entity_key' => $dimension_key, 'limit' => 10 ) ) : array();
	$correction_replay_ok = is_array( $correction_replay ) && ! empty( $correction_replay['ok'] ) && ! empty( $correction_replay['processed'] ) && ! empty( $correction_replay['replayed_output'] ) && (string) ( $correction_replay['record_id'] ?? '' ) === $reopened_rollup_record_id && (string) ( $correction_replay['output_hash'] ?? '' ) === (string) ( $reopened['output_hash'] ?? '' );
	$step( 'Runtime - correction replay is idempotent', $correction_replay_ok ? 'pass' : 'fail', $correction_replay_ok ? 'The same correction rebuilt the same output and reused its existing pointer.' : 'Correction replay created a divergent output or pointer.' );
	$cleanup_ok = true;
	foreach ( array_unique( array_filter( array( $initial_rollup_record_id, $reopened_rollup_record_id ) ) ) as $derived_rollup_id ) {
		$cleanup = $cleanup_pointer( BizCity_Context_Bank_Rollup_Worker::ROLLUP_CONTRACT_ID, $derived_rollup_id, 'rollup', 'conversation_state', $dimension_key );
		$cleanup_ok = $cleanup_ok && ! empty( $cleanup['ok'] );
	}
	foreach ( $source_records as $source ) {
		$cleanup = $cleanup_pointer( $source['contract_id'], $source['record_id'], 'event', 'conversation', $dimension_key );
		$cleanup_ok = $cleanup_ok && ! empty( $cleanup['ok'] );
	}
	$step( 'Runtime - fixture tombstone and cleanup', $cleanup_ok ? 'pass' : 'fail', $cleanup_ok ? 'Rollup, late source and original source pointers were tombstoned and removed after checkpoint/correction validation.' : 'One or more fixture pointers could not be cleaned up.' );
} catch ( Throwable $error ) {
	$step( 'Runtime - rollup worker fixture', 'fail', 'Fixture failed: ' . sanitize_key( (string) $error->getMessage() ) );
} finally {
	if ( $previous_flag === $missing_flag ) {
		delete_option( $flag );
	} else {
		update_option( $flag, $previous_flag, false );
	}
}

	$result['status'] = empty( $failures ) ? 'pass' : 'fail';
$result['reason'] = empty( $failures ) ? '' : 'fixture_validation_failed';
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( empty( $failures ) ? 0 : 1 );