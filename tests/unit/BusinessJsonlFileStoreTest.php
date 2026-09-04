<?php

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_current_blog_id' ) ) {
    // [2026-09-01 Johnny Chu] PHPUNIT-COMPAT - keep the shared multisite stub mutable for FrameworkCliTest.
    function get_current_blog_id() { return (int) ( $GLOBALS['bizcity_framework_cli_test_blog_id'] ?? 1258 ); }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir( $time = null, $create = false ) {
        // [2026-09-01 Johnny Chu] PHPUNIT-COMPAT - resolve the active test fixture root instead of retaining another test's upload path.
        $base = $GLOBALS['bizcity_channel_logger_test_upload']
            ?? $GLOBALS['bizcity_reconciler_test_upload']
            ?? $GLOBALS['bizcity_business_store_test_upload']
            ?? '';
        return array( 'basedir' => $base );
    }
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
    function wp_mkdir_p( $target ) { return is_dir( $target ) || mkdir( $target, 0777, true ); }
}
if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( $scheme = 'auth' ) { return 'business-store-test-salt-' . $scheme; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4() { return sprintf( '%s-%s-4%s-8%s-%s', substr( sha1( uniqid( '', true ) ), 0, 8 ), substr( sha1( uniqid( '', true ) ), 8, 4 ), substr( sha1( uniqid( '', true ) ), 13, 3 ), substr( sha1( uniqid( '', true ) ), 17, 3 ), substr( sha1( uniqid( '', true ) ), 20, 12 ) ); }
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-codec.php';
require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-file-contract-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-business-jsonl-file-store.php';

final class BusinessJsonlFileStoreTest extends TestCase {

    protected function setUp(): void {
        // [2026-09-01 Johnny Chu] CB2.3 — isolate encrypted business filestore receipt tests.
        $GLOBALS['bizcity_business_store_test_upload'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bizcity-business-store-test-' . getmypid();
        BizCity_File_Contract_Registry::register( 'tests.business_records', array(
            'owner_module' => 'tests',
            'label' => 'Business store test records',
            'folder' => 'bizcity-business-test',
            'module' => 'records',
            'schema_version' => '1.0',
            'retention_days' => 30,
            'storage_scope' => 'blog',
        ) );
    }

    protected function tearDown(): void {
        // [2026-09-01 Johnny Chu] CB2.3 — remove only the isolated business store test tree.
        $root = $GLOBALS['bizcity_business_store_test_upload'] ?? '';
        if ( $root === '' || ! is_dir( $root ) ) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getPathname() );
            } else {
                @unlink( $file->getPathname() );
            }
        }
        @rmdir( $root );
    }

    public function test_write_with_receipt_can_be_followed_by_exact_offset_and_hash(): void {
        // [2026-09-01 Johnny Chu] CB2.3 — receipt must follow the exact encrypted JSONL line.
        $receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( 'tests.business_records', array(
            'record_id' => 'record_receipt_001',
            'identity_uuid' => 'identity_receipt_001',
            'value' => 'private payload stays encrypted',
        ) );

        $this->assertIsArray( $receipt );
        $this->assertSame( 'tests.business_records', $receipt['contract_id'] );
        $this->assertSame( 'record_receipt_001', $receipt['record_id'] );
        $this->assertSame( 1258, $receipt['blog_id'] );
        $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}\.jsonl$/', $receipt['relative_file'] );
        $this->assertGreaterThanOrEqual( 0, $receipt['byte_offset'] );
        $this->assertSame( 64, strlen( $receipt['row_hash'] ) );
        $this->assertSame( 64, strlen( $receipt['content_hash'] ) );

        $followed = BizCity_Business_JSONL_File_Store::read_receipt( 'tests.business_records', $receipt );
        $this->assertTrue( $followed['ok'] );
        $this->assertSame( 'record_receipt_001', $followed['record']['record_id'] );
        $this->assertSame( 'private payload stays encrypted', $followed['record']['value'] );
    }

    public function test_legacy_write_returns_boolean_and_retry_folds_to_latest_record(): void {
        // [2026-09-01 Johnny Chu] CB2.3 — preserve boolean callers while append-only retries fold by record_id.
        $this->assertTrue( BizCity_Business_JSONL_File_Store::write( 'tests.business_records', array(
            'record_id' => 'record_retry_001',
            'state' => 'first',
        ) ) );
        $this->assertTrue( BizCity_Business_JSONL_File_Store::write( 'tests.business_records', array(
            'record_id' => 'record_retry_001',
            'state' => 'latest',
        ) ) );

        $rows = BizCity_Business_JSONL_File_Store::query( 'tests.business_records', array(
            'record_id' => 'record_retry_001',
            'limit' => 5,
        ) );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'latest', $rows[0]['state'] );
    }

    public function test_delete_tombstone_removes_record_from_current_query(): void {
        // [2026-09-01 Johnny Chu] CB2.4 — tombstone behavior must not resurrect deleted business records.
        $receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( 'tests.business_records', array(
            'record_id' => 'record_delete_001',
            'state' => 'active',
        ) );
        $this->assertIsArray( $receipt );
        $this->assertTrue( BizCity_Business_JSONL_File_Store::delete( 'tests.business_records', 'record_delete_001' ) );

        $rows = BizCity_Business_JSONL_File_Store::query( 'tests.business_records', array(
            'record_id' => 'record_delete_001',
            'limit' => 5,
        ) );
        $this->assertCount( 0, $rows );
    }

    public function test_missing_and_tampered_pointers_return_stable_failure_buckets(): void {
        // [2026-09-01 Johnny Chu] CB2.4 — missing and changed rows must fail closed without adjacent content.
        $receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( 'tests.business_records', array(
            'record_id' => 'record_pointer_001',
        ) );
        $this->assertIsArray( $receipt );

        $missing = $receipt;
        $missing['relative_file'] = '1900-01-01.jsonl';
        $this->assertSame( 'pointer_missing', BizCity_Business_JSONL_File_Store::read_receipt( 'tests.business_records', $missing )['reason'] );

        $file = $GLOBALS['bizcity_business_store_test_upload'] . '/bizcity-business-test/records/' . $receipt['relative_file'];
        $contents = file_get_contents( $file );
        $this->assertIsString( $contents );
        file_put_contents( $file, '{"tampered":true}\n' . substr( $contents, strpos( $contents, "\n" ) + 1 ) );
        $this->assertSame( 'pointer_hash_mismatch', BizCity_Business_JSONL_File_Store::read_receipt( 'tests.business_records', $receipt )['reason'] );
    }
}
