<?php
/**
 * PHASE-0.50 W1/W2 — "Chuyển phụ trách khách" (BizCity_CRM_Contact_Transfer).
 *
 * Pure unit test: Staff_Policy, inbox scopes, conversations and the audit log are in-memory
 * stubs. No WordPress bootstrap, no database.
 *
 * The two rules under test (doc PHASE-0.50 §4.4 + R-ZP-OWNER 0.48E §E4.2):
 *   1. The actor may only move customers inside their own visible inboxes.
 *   2. The receiver must already reach the conversation through THEIR OWN inbox scope, so a Zalo
 *      Personal inbox of a colleague can never be handed over here.
 *
 * Fixture (FX-1 shape): Hương #42 owns inbox 13 (her Zalo phone), Minh #57 owns inbox 21,
 * the leader #10 sees both. Contact 1 lives in inbox 13, contact 2 in inbox 21, contact 9 nowhere.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id() { return 1; }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) { return $GLOBALS['bzc_transfer_transients'][ $key ] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['bzc_transfer_transients'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( $type = 'mysql' ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
}

if ( ! class_exists( 'WP_Error', false ) ) {
    class WP_Error {
        public $code;
        public $message;
        public $data;
        public function __construct( $code = '', $message = '', $data = array() ) {
            $this->code = $code; $this->message = $message; $this->data = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

// The REAL Staff_Policy runs here (same in-memory team stubs as CrmStaffPolicyLeaderMemberTest, so
// both suites can share one process): `conv.transfer` is checked for real, not faked.
if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        return 'manage_options' === $capability && in_array( (int) $user_id, $GLOBALS['bizcity_staff_policy_test_admins'] ?? array(), true );
    }
}
if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) {
        if ( ! in_array( (int) $user_id, $GLOBALS['bzc_transfer_users'] ?? array(), true ) ) { return false; }
        return (object) array( 'ID' => (int) $user_id, 'roles' => array( 'bizcity_crm_staff' ), 'display_name' => 'User #' . (int) $user_id );
    }
}
if ( ! class_exists( 'BizCity_CRM_Team_Manager', false ) ) {
    final class BizCity_CRM_Team_Manager {
        public static function list_user_memberships( int $user_id ): array {
            return array_values( array_filter( $GLOBALS['bizcity_staff_policy_test_members'] ?? array(), static function ( $row ) use ( $user_id ) {
                return (int) $row['user_id'] === $user_id;
            } ) );
        }
        public static function list_team_members( int $team_id ): array {
            return array_values( array_filter( $GLOBALS['bizcity_staff_policy_test_members'] ?? array(), static function ( $row ) use ( $team_id ) {
                return (int) $row['team_id'] === $team_id;
            } ) );
        }
    }
}
if ( ! class_exists( 'BizCity_CRM_Capabilities', false ) ) {
    final class BizCity_CRM_Capabilities {
        public static function user_can_handle_inbox( int $user_id ): bool {
            return in_array( $user_id, $GLOBALS['bizcity_staff_policy_test_inbox_handlers'] ?? array(), true );
        }
    }
}
require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-staff-policy.php';

if ( ! class_exists( 'BizCity_CRM_Inbox_Access', false ) ) {
    final class BizCity_CRM_Inbox_Access {
        /** user_id => int[]|null (null = tenant admin) */
        public static $allowed = array();
        public static function allowed_inbox_ids( int $user_id ) {
            return array_key_exists( $user_id, self::$allowed ) ? self::$allowed[ $user_id ] : array();
        }
    }
}

if ( ! class_exists( 'BizCity_CRM_Task_Handoff', false ) ) {
    final class BizCity_CRM_Task_Handoff {
        /** user_id => inbox ids the user owns themselves. */
        public static $own_inboxes = array();
        /** contact_id => inbox ids. */
        public static $contact_inboxes = array();
        public static function user_inbox_ids( int $user_id ): array { return self::$own_inboxes[ $user_id ] ?? array(); }
        public static function contact_in_inboxes( int $contact_id, array $inbox_ids ): bool {
            return (bool) array_intersect( self::$contact_inboxes[ $contact_id ] ?? array(), $inbox_ids );
        }
    }
}

if ( ! class_exists( 'BizCity_CRM_Repository', false ) ) {
    final class BizCity_CRM_Repository {
        /** conversation_id => assignee_id after the call. */
        public static $assigned = array();
        public static $reasons = array();
        public static function set_conversation_assignee( int $conv_id, ?int $assignee_id, int $by_user_id = 0, array $ctx = array(), bool $emit = true ): bool {
            self::$assigned[ $conv_id ] = (int) $assignee_id;
            self::$reasons[ $conv_id ] = (string) ( $ctx['reason'] ?? '' );
            return true;
        }
    }
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

if ( ! class_exists( 'BizCity_CRM_DB_Installer_V2', false ) ) {
    final class BizCity_CRM_DB_Installer_V2 {
        public static function tbl_conversations(): string { return 'wp_bizcity_crm_conversations'; }
        public static function tbl_contact_inboxes(): string { return 'wp_bizcity_crm_contact_inboxes'; }
    }
}

/** Minimal $wpdb: the service runs exactly one prepared SELECT per in-scope contact. */
if ( ! class_exists( 'BizCity_Transfer_Test_Wpdb', false ) ) {
    final class BizCity_Transfer_Test_Wpdb {
        /** contact_id => rows returned for the "conversations in inboxes" query. */
        public static $conversations = array();
        public $queries = array();
        public function prepare( $sql, ...$args ) {
            $flat = array();
            foreach ( $args as $arg ) { $flat = array_merge( $flat, is_array( $arg ) ? $arg : array( $arg ) ); }
            return array( 'sql' => $sql, 'args' => $flat );
        }
        public function get_results( $query, $output = ARRAY_A ) {
            $this->queries[] = $query;
            $args = $query['args'];
            $contact_id = (int) $args[ count( $args ) - 1 ];
            $inbox_ids = array_map( 'intval', array_slice( $args, 0, count( $args ) - 2 ) );
            $rows = array();
            foreach ( self::$conversations[ $contact_id ] ?? array() as $row ) {
                if ( in_array( (int) $row['inbox_id'], $inbox_ids, true ) ) { $rows[] = $row; }
            }
            return $rows;
        }
    }
}

final class CrmContactTransferTest extends TestCase {

    const LEADER = 10;   // supervisor, team 3
    const HUONG = 42;    // agent, team 3
    const MINH = 57;     // agent, team 3
    const SUP_OTHER = 30; // supervisor, team 4 — outside the leader's reach

    protected function setUp(): void {
        parent::setUp();
        if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
        require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-contact-transfer.php';

        $GLOBALS['bzc_transfer_transients'] = array();
        $GLOBALS['wpdb'] = new BizCity_Transfer_Test_Wpdb();

        $GLOBALS['bzc_transfer_users'] = array( self::LEADER, self::HUONG, self::MINH, self::SUP_OTHER );
        $GLOBALS['bizcity_staff_policy_test_admins'] = array();
        $GLOBALS['bizcity_staff_policy_test_inbox_handlers'] = array();
        $GLOBALS['bizcity_staff_policy_test_members'] = array(
            array( 'user_id' => self::LEADER, 'team_id' => 3, 'member_role' => 'supervisor' ),
            array( 'user_id' => self::HUONG, 'team_id' => 3, 'member_role' => 'agent' ),
            array( 'user_id' => self::MINH, 'team_id' => 3, 'member_role' => 'agent' ),
            array( 'user_id' => self::SUP_OTHER, 'team_id' => 4, 'member_role' => 'supervisor' ),
        );
        $this->reset_role_memo();
        BizCity_CRM_Inbox_Access::$allowed = array( self::LEADER => array( 13, 21 ), self::HUONG => array( 13 ), self::MINH => array( 21 ) );
        BizCity_CRM_Task_Handoff::$own_inboxes = array( self::HUONG => array( 13 ), self::MINH => array( 21 ), self::LEADER => array( 13, 21 ) );
        BizCity_CRM_Task_Handoff::$contact_inboxes = array( 1 => array( 13 ), 2 => array( 21 ), 9 => array( 99 ) );
        BizCity_Transfer_Test_Wpdb::$conversations = array(
            1 => array( array( 'id' => 101, 'inbox_id' => 13, 'assignee_id' => self::HUONG ) ),
            2 => array( array( 'id' => 201, 'inbox_id' => 21, 'assignee_id' => self::MINH ), array( 'id' => 202, 'inbox_id' => 21, 'assignee_id' => 0 ) ),
        );
        BizCity_CRM_Repository::$assigned = array();
        BizCity_CRM_Repository::$reasons = array();
        BizCity_CRM_Audit_Log::$rows = array();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['bizcity_staff_policy_test_admins'], $GLOBALS['bizcity_staff_policy_test_inbox_handlers'], $GLOBALS['bizcity_staff_policy_test_members'] );
        $this->reset_role_memo();
        parent::tearDown();
    }

    /** `role()` memoizes per request; fixtures change between cases. */
    private function reset_role_memo(): void {
        $property = new ReflectionProperty( BizCity_CRM_Staff_Policy::class, 'role_memo' );
        $property->setAccessible( true );
        $property->setValue( null, array() );
    }

    private function transfer( array $payload, int $actor = self::LEADER ) {
        return BizCity_CRM_Contact_Transfer::transfer( $actor, $payload );
    }

    public function test_moves_conversations_of_a_customer_in_the_receiver_scope(): void {
        $result = $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array( 2 ) ) );

        $this->assertFalse( is_wp_error( $result ) );
        $this->assertSame( 1, $result['contacts_moved'] );
        // 201 already belongs to Minh, only the unassigned 202 changes hands.
        $this->assertSame( 1, $result['conversations_moved'] );
        $this->assertSame( self::MINH, BizCity_CRM_Repository::$assigned[ 202 ] );
        $this->assertSame( 'contact_owner_transferred', BizCity_CRM_Repository::$reasons[ 202 ] );
        $this->assertArrayNotHasKey( 201, BizCity_CRM_Repository::$assigned );
        $this->assertCount( 1, BizCity_CRM_Audit_Log::$rows );
        $this->assertSame( 'owner_transferred', BizCity_CRM_Audit_Log::$rows[0]['action'] );
    }

    public function test_rejects_a_customer_outside_the_receiver_own_inbox_scope(): void {
        // Contact 1 lives in Hương's Zalo Personal inbox; Minh must not get it without a phone transfer.
        $result = $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array( 1 ) ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'transfer_target_out_of_scope', $result->get_error_code() );
        $this->assertSame( array( array( 'contact_id' => 1, 'reason' => 'transfer_target_out_of_scope' ) ), $result->get_error_data()['stripped'] );
        $this->assertSame( array(), BizCity_CRM_Repository::$assigned );
    }

    public function test_strip_policy_moves_the_rest(): void {
        $result = $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array( 1, 2 ), 'out_of_scope_policy' => 'strip' ) );

        $this->assertFalse( is_wp_error( $result ) );
        $this->assertSame( 1, $result['contacts_moved'] );
        $this->assertSame( array( array( 'contact_id' => 1, 'reason' => 'transfer_target_out_of_scope' ) ), $result['stripped'] );
    }

    public function test_customer_outside_the_actor_scope_is_never_touched(): void {
        $result = $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array( 9 ), 'out_of_scope_policy' => 'strip' ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'transfer_target_out_of_scope', $result->get_error_code() );
        $this->assertSame( 'contact_not_in_scope', $result->get_error_data()['stripped'][0]['reason'] );
    }

    public function test_denies_a_receiver_outside_the_actor_team(): void {
        BizCity_CRM_Task_Handoff::$own_inboxes[ self::SUP_OTHER ] = array( 21 );

        $result = $this->transfer( array( 'to_user_id' => self::SUP_OTHER, 'contact_ids' => array( 2 ) ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'member_not_manageable', $result->get_error_code() );
        $this->assertSame( array(), BizCity_CRM_Repository::$assigned );
    }

    public function test_an_agent_cannot_transfer_at_all(): void {
        $result = $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array( 2 ) ), self::HUONG );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'member_not_manageable', $result->get_error_code() );
    }

    public function test_leader_can_take_a_customer_back_for_themselves(): void {
        $result = $this->transfer( array( 'to_user_id' => self::LEADER, 'contact_ids' => array( 2 ) ) );

        $this->assertFalse( is_wp_error( $result ) );
        $this->assertSame( self::LEADER, BizCity_CRM_Repository::$assigned[ 201 ] );
        $this->assertSame( self::LEADER, BizCity_CRM_Repository::$assigned[ 202 ] );
    }

    public function test_receiver_without_any_inbox_is_refused(): void {
        BizCity_CRM_Task_Handoff::$own_inboxes[ self::MINH ] = array();

        $result = $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array( 2 ) ) );

        $this->assertTrue( is_wp_error( $result ) );
        $this->assertSame( 'transfer_target_out_of_scope', $result->get_error_code() );
    }

    public function test_replay_with_the_same_request_id_is_idempotent(): void {
        $payload = array( 'to_user_id' => self::MINH, 'contact_ids' => array( 2 ), 'client_request_id' => 'req-1' );
        $first = $this->transfer( $payload );
        BizCity_CRM_Repository::$assigned = array();

        $second = $this->transfer( $payload );

        $this->assertSame( $first['conversations_moved'], $second['conversations_moved'] );
        $this->assertTrue( $second['duplicate'] );
        $this->assertSame( array(), BizCity_CRM_Repository::$assigned, 'a replay must not reassign anything again' );
    }

    public function test_rejects_an_invalid_receiver_and_an_empty_selection(): void {
        $this->assertSame( 'assignee_invalid', $this->transfer( array( 'to_user_id' => 999, 'contact_ids' => array( 2 ) ) )->get_error_code() );
        $this->assertSame( 'contacts_required', $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => array() ) )->get_error_code() );
        $this->assertSame( 'too_many_subjects', $this->transfer( array( 'to_user_id' => self::MINH, 'contact_ids' => range( 1000, 1300 ) ) )->get_error_code() );
    }
}
