<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id() { return (int) ( $GLOBALS['bizcity_reconciler_test_blog_id'] ?? 1258 ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return 7; }
}
if ( ! function_exists( 'current_user_can' ) ) {
    function current_user_can( $capability ) { return ! empty( $GLOBALS['bizcity_reconciler_test_caps'][ $capability ] ); }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir( $time = null, $create = false ) { return array( 'basedir' => $GLOBALS['bizcity_reconciler_test_upload'] ); }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $target ) { return is_dir( $target ) || mkdir( $target, 0777, true ); }
}
if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) { return 'reconciler-test-salt-' . $scheme; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4() { return sha1( uniqid( '', true ) ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) { return trim( (string) $value ); }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['bizcity_reconciler_test_options'] ) ? $GLOBALS['bizcity_reconciler_test_options'][ $key ] : $default; }
}
if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value, $autoload = null ) { $GLOBALS['bizcity_reconciler_test_options'][ $key ] = $value; return true; }
}

final class ContextBankReconcilerFakeWpdb {
    public $prefix = 'wp_';
    public $dbname = 'reconciler_test_db';
    public $insert_id = 0;
    public $fail_queries = false;

    public function prepare( $sql, $params = array() ) {
        if ( ! is_array( $params ) ) {
            $params = func_get_args();
            array_shift( $params );
        }
        foreach ( $params as $param ) {
            $sql = preg_replace( '/%[ds]/', is_int( $param ) ? (string) $param : "'" . addslashes( (string) $param ) . "'", $sql, 1 );
        }
        return $sql;
    }

    public function get_results( $sql, $output = ARRAY_A ) { return array(); }

    public function query( $sql ) {
        if ( $this->fail_queries ) {
            return false;
        }
        $this->insert_id++;
        return 1;
    }
}

$GLOBALS['wpdb'] = new ContextBankReconcilerFakeWpdb();
$GLOBALS['bizcity_reconciler_test_options'] = array();
$GLOBALS['bizcity_reconciler_test_caps'] = array( 'read' => true );

require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-codec.php';
require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-file-contract-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-business-jsonl-file-store.php';
require_once dirname( __DIR__, 2 ) . '/core/context-bank/includes/class-context-bank-access.php';
require_once dirname( __DIR__, 2 ) . '/core/context-bank/includes/class-context-bank-ledger.php';
require_once dirname( __DIR__, 2 ) . '/core/context-bank/includes/class-context-bank-reconciler.php';

final class ContextBankReconcilerTest extends TestCase {

    protected function setUp(): void {
        // [2026-09-01 Johnny Chu] CB3.4 — isolate bounded reconciliation and resume evidence.
        $GLOBALS['bizcity_reconciler_test_upload'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bizcity-reconciler-test-' . getmypid();
        BizCity_File_Contract_Registry::register( 'tests.reconcile_records', array(
            'owner_module' => 'tests',
            'label' => 'Reconciler test records',
            'folder' => 'bizcity-reconciler-test',
            'module' => 'records',
            'schema_version' => '1.0',
            'retention_days' => 30,
            'storage_scope' => 'blog',
        ) );
    }

    protected function tearDown(): void {
        $root = $GLOBALS['bizcity_reconciler_test_upload'] ?? '';
        if ( $root === '' || ! is_dir( $root ) ) {
            return;
        }
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $iterator as $file ) {
            $file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
        }
        @rmdir( $root );
    }

    public function test_two_bounded_batches_persist_and_resume_signed_checkpoint(): void {
        // [2026-09-01 Johnny Chu] CB3.4 — checkpoint must advance only by bounded file pages and reject tampering.
        $receipts = array();
        foreach ( array( 'reconcile_001', 'reconcile_002', 'reconcile_003' ) as $record_id ) {
            $receipts[] = BizCity_Business_JSONL_File_Store::write_with_receipt( 'tests.reconcile_records', array(
                'record_id' => $record_id,
                'record_kind' => 'memory',
                'identity_uuid' => 'identity_' . $record_id,
            ) );
        }
        $this->assertIsArray( $receipts[0] );
        $file = $receipts[0]['relative_file'];

        $first = BizCity_Context_Bank_Reconciler::run_batch( 'reconcile_run_001', 'tests.reconcile_records', $file, array(), 2 );
        $this->assertTrue( $first['ok'] );
        $this->assertSame( 2, $first['processed'] );
        $this->assertSame( 2, $first['inspected'] );
        $this->assertGreaterThan( 0, $first['next_checkpoint']['byte_offset'] );

        $second = BizCity_Context_Bank_Reconciler::run_batch( 'reconcile_run_001', 'tests.reconcile_records', $file, $first['next_checkpoint'], 2 );
        $this->assertTrue( $second['ok'] );
        $this->assertSame( 1, $second['processed'] );
        $this->assertGreaterThan( $first['next_checkpoint']['byte_offset'], $second['next_checkpoint']['byte_offset'] );
        $this->assertSame( $second['next_checkpoint'], BizCity_Context_Bank_Reconciler::load_checkpoint( 'reconcile_run_001' ) );

        $tampered = $second['next_checkpoint'];
        $tampered['byte_offset']++;
        $rejected = BizCity_Context_Bank_Reconciler::validate_checkpoint( $tampered, 'tests.reconcile_records', $file );
        $this->assertFalse( $rejected['ok'] );
        $this->assertSame( 'checkpoint_signature_invalid', $rejected['reason'] );
    }

    public function test_pointer_failure_matrix_fails_closed_for_malformed_scope(): void {
        // [2026-09-01 Johnny Chu] CB3.3 — preserve stable failure buckets for malformed pointer fields and paths.
        $ledger = BizCity_Context_Bank_Ledger::instance();
        $missing = $ledger->verify_pointer( array( 'source_contract_id' => 'tests.reconcile_records' ) );
        $this->assertSame( 'pointer_field_missing', $missing['reason'] );

        $invalid_path = $ledger->verify_pointer( array(
            'source_contract_id' => 'tests.reconcile_records',
            'record_id' => 'record_invalid_path',
            'relative_file' => '../2026-09-01.jsonl',
            'byte_offset' => 0,
            'row_hash' => str_repeat( 'a', 64 ),
        ) );
        $this->assertSame( 'pointer_path_invalid', $invalid_path['reason'] );
    }

    public function test_access_scope_forces_owner_and_rejects_foreign_user_filter(): void {
        // [2026-09-01 Johnny Chu] PHASE-CB-MVP — prove posted owner filters cannot expand a user's Context Bank scope.
        $own = BizCity_Context_Bank_Access::scope_filters( array( 'user_id' => 7 ) );
        $this->assertTrue( $own['ok'] );
        $this->assertSame( 7, $own['filters']['wp_user_id'] );
        $this->assertFalse( BizCity_Context_Bank_Access::scope_filters( array( 'user_id' => 99 ) )['ok'] );

        $GLOBALS['bizcity_reconciler_test_caps']['manage_options'] = true;
        $admin = BizCity_Context_Bank_Access::scope_filters( array( 'user_id' => 99 ) );
        $this->assertTrue( $admin['ok'] );
        $this->assertSame( 'tenant_admin', $admin['scope'] );
        unset( $GLOBALS['bizcity_reconciler_test_caps']['manage_options'] );
    }

    public function test_pointer_authorization_rechecks_owner_scope(): void {
        // [2026-09-01 Johnny Chu] PHASE-CB-MVP — owner reads succeed while a foreign pointer is denied before file access.
        $allowed = BizCity_Context_Bank_Access::authorize_pointer( array( 'blog_id' => 1258, 'wp_user_id' => 7 ) );
        $this->assertTrue( $allowed['ok'] );
        $denied = BizCity_Context_Bank_Access::authorize_pointer( array( 'blog_id' => 1258, 'wp_user_id' => 99 ) );
        $this->assertFalse( $denied['ok'] );
        $this->assertSame( 'context_bank_owner_scope_denied', $denied['reason'] );
    }

    public function test_pointer_removal_requires_receipt_bearing_tombstone(): void {
        // [2026-09-01 Johnny Chu] PHASE-CB3.4 — pointer cleanup cannot bypass the canonical tombstone state.
        $GLOBALS['bizcity_reconciler_test_caps']['manage_options'] = true;
        $result = BizCity_Context_Bank_Ledger::instance()->remove_tombstoned_pointer( array(
            'blog_id' => 1258,
            'source_contract_id' => 'tests.reconcile_records',
            'record_id' => 'record_active',
            'event_uuid' => 'event-active',
            'operation' => 'upsert',
            'lifecycle_status' => 'active',
        ), 'test_cleanup' );
        unset( $GLOBALS['bizcity_reconciler_test_caps']['manage_options'] );
        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'tombstone_required_before_pointer_removal', $result['reason'] );
    }

    public function test_ledger_sql_failure_is_degraded_and_foreign_blog_scope_is_empty(): void {
        // [2026-09-01 Johnny Chu] CB3.2/CB3.3 — prove SQL failure is explicit and a foreign blog cannot query this ledger.
        $receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( 'tests.reconcile_records', array(
            'record_id' => 'ledger_sql_failure',
            'record_kind' => 'memory',
            'identity_uuid' => 'identity_sql_failure',
        ) );
        $this->assertIsArray( $receipt );

        $GLOBALS['wpdb']->fail_queries = true;
        $result = BizCity_Context_Bank_Ledger::instance()->record( array(
            'record_kind' => 'memory',
            'identity_uuid' => 'identity_sql_failure',
            'source_record_id' => 'ledger_sql_failure',
            'receipt' => $receipt,
        ) );
        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'ledger_degraded', $result['reason'] );
        $GLOBALS['wpdb']->fail_queries = false;

        $foreign = BizCity_Context_Bank_Ledger::instance()->find( array( 'blog_id' => 1526 ) );
        $this->assertSame( array(), $foreign );
        $foreign_query = BizCity_Context_Bank_Ledger::instance()->query( array( 'blog_id' => 1526, 'limit' => 1 ) );
        $this->assertSame( 'tenant_scope_denied', $foreign_query['reason'] );
    }
}