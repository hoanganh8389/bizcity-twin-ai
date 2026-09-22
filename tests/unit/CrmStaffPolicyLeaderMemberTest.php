<?php
/**
 * PHASE-0.50 UID-03 — permission matrix for the leader/member actions added to
 * BizCity_CRM_Staff_Policy: `task.assign` and `contact.view_by_owner`
 * (R-LEADER-MEMBER R-LM-4/R-LM-5, doc PHASE-0.50 §4.1).
 *
 * Pure unit test: team memberships and capabilities are in-memory stubs, no
 * WordPress bootstrap and no database.
 *
 * Fixture (FX-1 shape):
 *   team 3 "Hà Nội": supervisor #10, lead #20, agent #42 (Hương), agent #57 (Minh)
 *   team 4:          supervisor #30, agent #70
 *   #1  site administrator (manage_options)
 *   #80 inbox handler with no team row (un-teamed agent)
 *   #90 no CRM role at all
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'user_can' ) ) {
    function user_can( $user_id, $capability ) {
        return 'manage_options' === $capability && in_array( (int) $user_id, $GLOBALS['bizcity_staff_policy_test_admins'] ?? array(), true );
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

final class CrmStaffPolicyLeaderMemberTest extends TestCase {

    const ADMIN = 1;
    const SUP = 10;
    const LEAD = 20;
    const HUONG = 42;
    const MINH = 57;
    const SUP_OTHER = 30;
    const AGENT_OTHER = 70;
    const UNTEAMED = 80;
    const NOBODY = 90;

    protected function setUp(): void {
        $GLOBALS['bizcity_staff_policy_test_admins'] = array( self::ADMIN );
        $GLOBALS['bizcity_staff_policy_test_inbox_handlers'] = array( self::UNTEAMED );
        $GLOBALS['bizcity_staff_policy_test_members'] = array(
            array( 'user_id' => self::SUP, 'team_id' => 3, 'member_role' => 'supervisor' ),
            array( 'user_id' => self::LEAD, 'team_id' => 3, 'member_role' => 'lead' ),
            array( 'user_id' => self::HUONG, 'team_id' => 3, 'member_role' => 'agent' ),
            array( 'user_id' => self::MINH, 'team_id' => 3, 'member_role' => 'agent' ),
            array( 'user_id' => self::SUP_OTHER, 'team_id' => 4, 'member_role' => 'supervisor' ),
            array( 'user_id' => self::AGENT_OTHER, 'team_id' => 4, 'member_role' => 'agent' ),
        );
        $this->reset_role_memo();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['bizcity_staff_policy_test_admins'], $GLOBALS['bizcity_staff_policy_test_inbox_handlers'], $GLOBALS['bizcity_staff_policy_test_members'] );
        $this->reset_role_memo();
    }

    /** `role()` memoizes per request; each case needs a fresh memo because fixtures change. */
    private function reset_role_memo(): void {
        $property = new ReflectionProperty( BizCity_CRM_Staff_Policy::class, 'role_memo' );
        $property->setAccessible( true );
        $property->setValue( null, array() );
    }

    /**
     * @return array<string,array{0:int,1:string,2:int,3:string}>
     */
    public function matrix(): array {
        return array(
            // Administrator: tenant-wide, but never assigns a handoff task to themselves.
            'admin assigns agent'                   => array( self::ADMIN, 'task.assign', self::HUONG, 'ok' ),
            'admin assigns other-team agent'        => array( self::ADMIN, 'task.assign', self::AGENT_OTHER, 'ok' ),
            'admin self-assign refused'             => array( self::ADMIN, 'task.assign', self::ADMIN, 'self_not_allowed' ),
            'admin views any owner'                 => array( self::ADMIN, 'contact.view_by_owner', self::MINH, 'ok' ),
            'admin views own contacts'              => array( self::ADMIN, 'contact.view_by_owner', self::ADMIN, 'ok' ),

            // Supervisor: own team, strictly lower rank.
            'supervisor assigns agent'              => array( self::SUP, 'task.assign', self::HUONG, 'ok' ),
            'supervisor assigns lead'               => array( self::SUP, 'task.assign', self::LEAD, 'ok' ),
            'supervisor views agent contacts'       => array( self::SUP, 'contact.view_by_owner', self::MINH, 'ok' ),
            'supervisor other-team agent refused'   => array( self::SUP, 'task.assign', self::AGENT_OTHER, 'different_team' ),
            'supervisor other-team owner refused'   => array( self::SUP, 'contact.view_by_owner', self::AGENT_OTHER, 'different_team' ),
            'supervisor peer supervisor refused'    => array( self::SUP, 'task.assign', self::SUP_OTHER, 'different_team' ),
            'supervisor board without subject'      => array( self::SUP, 'task.assign', 0, 'ok' ),

            // Lead: agents in own team only.
            'lead assigns agent'                    => array( self::LEAD, 'task.assign', self::HUONG, 'ok' ),
            'lead views agent contacts'             => array( self::LEAD, 'contact.view_by_owner', self::HUONG, 'ok' ),
            'lead assigns supervisor refused'       => array( self::LEAD, 'task.assign', self::SUP, 'subject_not_lower_rank' ),
            'lead views supervisor refused'         => array( self::LEAD, 'contact.view_by_owner', self::SUP, 'subject_not_lower_rank' ),
            'lead self-assign refused'              => array( self::LEAD, 'task.assign', self::LEAD, 'self_not_allowed' ),

            // Agent: manages nobody; own contacts only.
            'agent assigns peer refused'            => array( self::HUONG, 'task.assign', self::MINH, 'rank_insufficient' ),
            'agent views peer refused'              => array( self::HUONG, 'contact.view_by_owner', self::MINH, 'rank_insufficient' ),
            'agent views own contacts'              => array( self::HUONG, 'contact.view_by_owner', self::HUONG, 'ok' ),
            'agent self-assign via policy refused'  => array( self::HUONG, 'task.assign', self::HUONG, 'self_not_allowed' ),
            'agent board without subject refused'   => array( self::HUONG, 'task.assign', 0, 'rank_insufficient' ),

            // Un-teamed inbox handler and users without any CRM role.
            'unteamed assigns agent refused'        => array( self::UNTEAMED, 'task.assign', self::HUONG, 'rank_insufficient' ),
            'unteamed views own contacts'           => array( self::UNTEAMED, 'contact.view_by_owner', self::UNTEAMED, 'ok' ),
            'nobody views own contacts refused'     => array( self::NOBODY, 'contact.view_by_owner', self::NOBODY, 'rank_insufficient' ),
            'nobody assigns refused'                => array( self::NOBODY, 'task.assign', self::HUONG, 'rank_insufficient' ),
        );
    }

    /**
     * @dataProvider matrix
     */
    public function test_leader_member_matrix( int $actor, string $action, int $subject, string $expected_code ): void {
        $decision = BizCity_CRM_Staff_Policy::can( $actor, $action, $subject );

        $this->assertSame( $expected_code, $decision['code'] );
        $this->assertSame( 'ok' === $expected_code, $decision['ok'] );
        if ( 'ok' !== $expected_code ) {
            $this->assertNotSame( '', $decision['why'], 'Every denial carries an R-ERROR-UX hint.' );
        }
    }

    public function test_unknown_action_fails_closed_even_for_supervisor(): void {
        $decision = BizCity_CRM_Staff_Policy::can( self::SUP, 'task.delete_all', self::HUONG );

        $this->assertFalse( $decision['ok'] );
        $this->assertSame( 'rank_insufficient', $decision['code'] );
    }

    public function test_manageable_and_visible_rosters_match_the_matrix(): void {
        $this->assertNull( BizCity_CRM_Staff_Policy::manageable_user_ids( self::ADMIN ) );
        $this->assertSame( array( self::LEAD, self::HUONG, self::MINH ), BizCity_CRM_Staff_Policy::manageable_user_ids( self::SUP ) );
        $this->assertSame( array( self::HUONG, self::MINH ), BizCity_CRM_Staff_Policy::manageable_user_ids( self::LEAD ) );
        $this->assertSame( array(), BizCity_CRM_Staff_Policy::manageable_user_ids( self::HUONG ) );
        $this->assertSame( array(), BizCity_CRM_Staff_Policy::manageable_user_ids( self::UNTEAMED ) );

        $visible = BizCity_CRM_Staff_Policy::visible_user_ids( self::LEAD );
        sort( $visible );
        $this->assertSame( array( self::LEAD, self::HUONG, self::MINH ), $visible );
        $this->assertSame( array( self::HUONG ), BizCity_CRM_Staff_Policy::visible_user_ids( self::HUONG ) );
    }

    /**
     * Every subject `can()` accepts for a leader must also be in the roster the
     * board/contacts catalog is built from, and vice versa (R-CRMF-8).
     */
    public function test_can_and_manageable_roster_agree_for_every_team_member(): void {
        $everyone = array( self::SUP, self::LEAD, self::HUONG, self::MINH, self::SUP_OTHER, self::AGENT_OTHER, self::UNTEAMED );
        foreach ( array( self::SUP, self::LEAD, self::HUONG ) as $actor ) {
            $roster = BizCity_CRM_Staff_Policy::manageable_user_ids( $actor );
            foreach ( $everyone as $subject ) {
                if ( $subject === $actor ) { continue; }
                foreach ( array( 'task.assign', 'contact.view_by_owner' ) as $action ) {
                    $this->assertSame(
                        in_array( $subject, $roster, true ),
                        BizCity_CRM_Staff_Policy::can( $actor, $action, $subject )['ok'],
                        "actor #{$actor} {$action} subject #{$subject}"
                    );
                }
            }
        }
    }

    /**
     * Known simplification (doc 0.48F §4B): a user in two teams is scoped to the
     * team of their highest rank. Locked here so widening it is a deliberate change.
     */
    public function test_multi_team_user_is_scoped_to_highest_rank_team(): void {
        $GLOBALS['bizcity_staff_policy_test_members'][] = array( 'user_id' => self::HUONG, 'team_id' => 4, 'member_role' => 'lead' );
        $this->reset_role_memo();

        $this->assertSame( 4, BizCity_CRM_Staff_Policy::primary_team( self::HUONG ) );
        $this->assertSame( 'different_team', BizCity_CRM_Staff_Policy::can( self::SUP, 'task.assign', self::HUONG )['code'] );
        $this->assertTrue( BizCity_CRM_Staff_Policy::can( self::HUONG, 'task.assign', self::AGENT_OTHER )['ok'] );
    }
}
