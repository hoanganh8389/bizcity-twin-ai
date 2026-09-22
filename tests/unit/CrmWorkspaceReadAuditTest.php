<?php
/**
 * PHASE-0.48F F-UID-05 / T3-05 — a manager reading an employee's workspace is audited
 * once per (actor, subject, day). Master roadmap M3-04.
 *
 * Pure unit test: audit log and transients are in-memory stubs (shared with other suites
 * in the same process, so ids here are unique to this file).
 */

use PHPUnit\Framework\TestCase;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return $GLOBALS['bzc_transfer_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['bzc_transfer_transients'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
}
if ( ! class_exists( 'BizCity_CRM_Audit_Log', false ) ) {
    final class BizCity_CRM_Audit_Log {
        public static $rows = array();
        public static function log( string $entity_type, int $entity_id, string $action, ?array $before = null, ?array $after = null, array $opts = array() ) {
            self::$rows[] = compact( 'entity_type', 'entity_id', 'action', 'before', 'after', 'opts' );
            return count( self::$rows );
        }
    }
}
require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-staff-rest.php';

final class CrmWorkspaceReadAuditTest extends TestCase {

    private const LEADER = 7710;
    private const MEMBER = 7742;

    protected function setUp(): void {
        parent::setUp();
        BizCity_CRM_Audit_Log::$rows = array();
    }

    private function rows_for( int $subject ): array {
        return array_values( array_filter( BizCity_CRM_Audit_Log::$rows, static function ( $row ) use ( $subject ) {
            return 'workspace_viewed' === $row['action'] && $subject === $row['entity_id'];
        } ) );
    }

    public function test_first_read_of_the_day_writes_one_row_without_customer_data(): void {
        $this->assertTrue( BizCity_CRM_Staff_REST::record_workspace_read( self::LEADER, self::MEMBER, 'workspace' ) );

        $rows = $this->rows_for( self::MEMBER );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'crm_staff', $rows[0]['entity_type'] );
        $this->assertSame( self::LEADER, $rows[0]['opts']['user_id'] );
        $this->assertSame( array( 'source', 'day' ), array_keys( $rows[0]['after'] ) );
        $this->assertStringStartsWith( 'workspace_read:' . self::LEADER . ':' . self::MEMBER . ':', $rows[0]['opts']['event_uuid'] );
    }

    public function test_later_reads_the_same_day_are_not_audited_again(): void {
        BizCity_CRM_Staff_REST::record_workspace_read( self::LEADER, self::MEMBER + 1, 'workspace' );
        $this->assertFalse( BizCity_CRM_Staff_REST::record_workspace_read( self::LEADER, self::MEMBER + 1, 'scope_user_list' ) );
        $this->assertCount( 1, $this->rows_for( self::MEMBER + 1 ) );
    }

    public function test_another_leader_reading_the_same_member_gets_its_own_row(): void {
        BizCity_CRM_Staff_REST::record_workspace_read( self::LEADER, self::MEMBER + 2, 'workspace' );
        $this->assertTrue( BizCity_CRM_Staff_REST::record_workspace_read( self::LEADER + 1, self::MEMBER + 2, 'workspace' ) );
        $this->assertCount( 2, $this->rows_for( self::MEMBER + 2 ) );
    }

    public function test_reading_your_own_workspace_or_invalid_ids_is_not_audited(): void {
        $this->assertFalse( BizCity_CRM_Staff_REST::record_workspace_read( self::MEMBER + 3, self::MEMBER + 3, 'workspace' ) );
        $this->assertFalse( BizCity_CRM_Staff_REST::record_workspace_read( 0, self::MEMBER + 3, 'workspace' ) );
        $this->assertFalse( BizCity_CRM_Staff_REST::record_workspace_read( self::LEADER, 0, 'workspace' ) );
        $this->assertCount( 0, $this->rows_for( self::MEMBER + 3 ) );
    }
}
