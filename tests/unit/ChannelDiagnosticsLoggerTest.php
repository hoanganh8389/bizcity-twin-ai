<?php

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id() { return 1258; }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir( $time = null, $create = false ) {
        return array( 'basedir' => $GLOBALS['bizcity_channel_logger_test_upload'] );
    }
}
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . DIRECTORY_SEPARATOR; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}

require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-log-contract-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/helper/class-bizcity-jsonl-file-logger.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/class-channel-file-logger.php';

final class ChannelDiagnosticsLoggerTest extends TestCase {

    protected function setUp(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — isolate structured channel diagnostics writes in a temporary upload root.
        $GLOBALS['bizcity_channel_logger_test_upload'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bizcity-channel-test-' . getmypid();
        $this->register_contracts();
    }

    protected function tearDown(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — remove only the temporary unit-test upload tree.
        $root = $GLOBALS['bizcity_channel_logger_test_upload'] ?? '';
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

    public function test_legacy_write_uses_structured_account_scoped_record(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — verify legacy callers write one normalized Zalo OA record.
        $written = BizCity_Channel_File_Logger::write(
            'zalo_oa',
            'info',
            'message_received',
            'received from customer@example.com',
            array( 'account_id' => 'oa_A', 'platform' => 'ZALO_OA', 'code' => 'zalo_oa', 'trace_id' => 'trace_unit_001' )
        );

        $file = $GLOBALS['bizcity_channel_logger_test_upload'] . '/bizcity-channel-logs/zalo_oa/' . gmdate( 'Y-m-d' ) . '.jsonl';
        $row = json_decode( trim( (string) file_get_contents( $file ) ), true );

        $this->assertTrue( $written );
        $this->assertSame( 'channel-diagnostics-record', $row['contract'] );
        $this->assertSame( 'zalo_oa', $row['channel'] );
        $this->assertSame( 'customer', $row['zone'] );
        $this->assertSame( 'oa_A', $row['account']['account_id'] );
        $this->assertSame( 'success', $row['pipeline_status']['operational_logged'] );
        $this->assertFalse( is_dir( $GLOBALS['bizcity_channel_logger_test_upload'] . '/bizcity-cg-logs' ) );
    }

    public function test_real_channel_without_account_fails_closed(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — missing exact channel account must not receive a synthetic account.
        $result = BizCity_Channel_File_Logger::write_record( array(
            'channel' => 'zalo_personal',
            'event' => 'message_received',
            'producer' => array( 'module' => 'tests/channel', 'version' => '1.0.0' ),
        ) );

        $this->assertFalse( $result['written'] );
        $this->assertSame( 'account_scope_required', $result['reason'] );
    }

    public function test_structured_write_returns_lock_captured_pointer_receipt(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — receipt fields must describe the exact durable channel row.
        $result = BizCity_Channel_File_Logger::write_record( array(
            'channel' => 'zalo_oa',
            'event' => 'receipt_probe',
            'account' => array( 'account_id' => 'oa_A' ),
            'message' => 'bounded receipt probe',
        ) );

        $this->assertTrue( $result['written'] );
        $this->assertSame( 'core.channel_gateway.zalo_oa', $result['contract_id'] );
        $this->assertStringContainsString( '/zalo_oa/', '/' . $result['relative_file'] . '/' );
        $this->assertGreaterThanOrEqual( 0, $result['byte_offset'] );
        $this->assertSame( 64, strlen( $result['row_hash'] ) );
        $this->assertNotSame( '', $result['event_uuid'] );
    }

    public function test_bare_zalo_channel_is_rejected_without_writing(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — generic Zalo is not an addressable product or account scope.
        $result = BizCity_Channel_File_Logger::write_record( array(
            'channel' => 'zalo',
            'event' => 'invalid_channel_probe',
            'account' => array( 'account_id' => 'zalo_A' ),
        ) );

        $this->assertFalse( $result['written'] );
        $this->assertSame( 'invalid_channel', $result['reason'] );
        $this->assertFileDoesNotExist( $GLOBALS['bizcity_channel_logger_test_upload'] . '/bizcity-channel-logs/zalo/' . gmdate( 'Y-m-d' ) . '.jsonl' );
    }

    public function test_zalo_products_remain_separate_with_their_own_zones(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — Bot, OA and Personal are distinct channel contracts and zones.
        BizCity_Channel_File_Logger::write( 'zalo_bot', 'info', 'bot_probe', '', array( 'account_id' => 'bot_A' ) );
        BizCity_Channel_File_Logger::write( 'zalo_oa', 'info', 'oa_probe', '', array( 'account_id' => 'oa_A' ) );
        BizCity_Channel_File_Logger::write( 'zalo_personal', 'info', 'personal_probe', '', array( 'account_id' => 'personal_A' ) );

        $bot = BizCity_Channel_File_Logger::query_records( array( 'channel' => 'zalo_bot', 'account_id' => 'bot_A' ) );
        $oa = BizCity_Channel_File_Logger::query_records( array( 'channel' => 'zalo_oa', 'account_id' => 'oa_A' ) );
        $personal = BizCity_Channel_File_Logger::query_records( array( 'channel' => 'zalo_personal', 'account_id' => 'personal_A' ) );

        $this->assertCount( 1, $bot );
        $this->assertCount( 1, $oa );
        $this->assertCount( 1, $personal );
        $this->assertSame( 'admin', $bot[0]['zone'] );
        $this->assertSame( 'customer', $oa[0]['zone'] );
        $this->assertSame( 'customer', $personal[0]['zone'] );
        $this->assertNotSame( $bot[0]['account']['account_id'], $oa[0]['account']['account_id'] );
        $this->assertNotSame( $oa[0]['account']['account_id'], $personal[0]['account']['account_id'] );
    }

    public function test_query_records_filters_date_and_redacted_text(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — shared reader applies bounded date and q filters to canonical rows.
        BizCity_Channel_File_Logger::write_record( array(
            'channel' => 'zalo_oa',
            'event' => 'search_probe',
            'occurred_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
            'account' => array( 'account_id' => 'oa_A' ),
            'context' => array( 'trace_id' => 'trace_search_A' ),
        ) );
        BizCity_Channel_File_Logger::write_record( array(
            'channel' => 'zalo_oa',
            'event' => 'search_other',
            'occurred_at' => gmdate( 'Y-m-d\\TH:i:s\\Z' ),
            'account' => array( 'account_id' => 'oa_B' ),
            'context' => array( 'trace_id' => 'trace_search_B' ),
        ) );

        $rows = BizCity_Channel_File_Logger::query_records( array(
            'channel' => 'zalo_oa',
            'account_id' => 'oa_A',
            'date' => gmdate( 'Y-m-d' ),
            'q' => 'trace_search_A',
            'limit' => 20,
        ) );

        $this->assertCount( 1, $rows );
        $this->assertSame( 'oa_A', $rows[0]['account']['account_id'] );
        $this->assertSame( 'search_probe', $rows[0]['event'] );
    }

    public function test_query_records_filters_exact_account_and_status(): void {
        // [2026-09-01 Johnny Chu] R-CH-10 — verify one reader can filter channel, account and pipeline status.
        BizCity_Channel_File_Logger::write(
            'zalo_oa',
            'info',
            'message_received',
            '',
            array( 'account_id' => 'oa_A', 'code' => 'zalo_oa', 'trace_id' => 'trace_unit_002' )
        );
        BizCity_Channel_File_Logger::write(
            'zalo_oa',
            'info',
            'message_received',
            '',
            array( 'account_id' => 'oa_B', 'code' => 'zalo_oa', 'trace_id' => 'trace_unit_003' )
        );

        $rows = BizCity_Channel_File_Logger::query_records( array(
            'channel' => 'zalo_oa',
            'account_id' => 'oa_A',
            'operational_logged' => 'success',
            'limit' => 20,
        ) );

        // [2026-09-01 Johnny Chu] PHPUNIT-COMPAT - the exact oa_A filter returns only the single oa_A fixture row.
        $this->assertCount( 1, $rows );
        foreach ( $rows as $row ) {
            $this->assertSame( 'oa_A', $row['account']['account_id'] );
            $this->assertSame( 'success', $row['pipeline_status']['operational_logged'] );
        }
    }

    private function register_contracts(): void {
        BizCity_Log_Contract_Registry::register( 'core.channel_gateway.zalo_bot', array(
            'owner_module' => 'core/channel-gateway',
            'label' => 'Zalo Bot',
            'jsonl_folder' => 'bizcity-channel-logs',
            'jsonl_module' => 'zalo_bot',
            'indexed' => false,
        ) );
        BizCity_Log_Contract_Registry::register( 'core.channel_gateway.zalo_oa', array(
            'owner_module' => 'core/channel-gateway',
            'label' => 'Zalo OA',
            'jsonl_folder' => 'bizcity-channel-logs',
            'jsonl_module' => 'zalo_oa',
            'indexed' => false,
        ) );
        BizCity_Log_Contract_Registry::register( 'core.channel_gateway.zalo_personal', array(
            'owner_module' => 'core/channel-gateway',
            'label' => 'Zalo Personal',
            'jsonl_folder' => 'bizcity-channel-logs',
            'jsonl_module' => 'zalo_personal',
            'indexed' => false,
        ) );
    }
}
