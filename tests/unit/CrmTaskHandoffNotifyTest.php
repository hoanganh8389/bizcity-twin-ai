<?php
/**
 * PHASE-0.50 C-05 — internal Zalo Bot ping for assigned work.
 *
 * Pure unit test: WordPress options, transients, users and the gateway sender are
 * in-memory stubs. Asserts the notification is opt-in, throttled, never sent for
 * self-assigned work or an unlinked member, and that the text carries no customer
 * data (contract §1.5, Guardrail 6).
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_option' ) ) {
    // Suite-wide stub: also read the ContextBankReconcilerTest store so load order never breaks that test.
    function get_option( $key, $default = false ) {
        foreach ( array( 'bizcity_reconciler_test_options', 'bizcity_options_stub' ) as $store ) {
            if ( isset( $GLOBALS[ $store ] ) && is_array( $GLOBALS[ $store ] ) && array_key_exists( $key, $GLOBALS[ $store ] ) ) {
                return $GLOBALS[ $store ][ $key ];
            }
        }
        return $default;
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( $key ) {
        return array_key_exists( $key, $GLOBALS['bizcity_transients_stub'] ?? array() ) ? $GLOBALS['bizcity_transients_stub'][ $key ] : false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( $key, $value, $ttl = 0 ) {
        $GLOBALS['bizcity_transients_stub'][ $key ] = $value;
        $GLOBALS['bizcity_transient_ttl_stub'][ $key ] = (int) $ttl;
        return true;
    }
}
if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( $user_id ) {
        $names = $GLOBALS['bizcity_users_stub'] ?? array();
        if ( ! isset( $names[ (int) $user_id ] ) ) { return false; }
        return (object) array( 'display_name' => $names[ (int) $user_id ] );
    }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( $path = '' ) { return 'https://example.test' . $path; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $url ) { return (string) $url; }
}

if ( ! class_exists( 'BizCity_Zalobot_User_Linker', false ) ) {
    final class BizCity_Zalobot_User_Linker {
        public static function get_links_for_wp_user( int $wp_user_id ): array {
            return $GLOBALS['bizcity_zalo_links_stub'][ $wp_user_id ] ?? array();
        }
    }
}

if ( ! class_exists( 'BizCity_Gateway_Sender', false ) ) {
    final class BizCity_Gateway_Sender {
        public static function instance() { return new self(); }
        public function send( string $chat_id, string $message, string $type = 'text', array $extra = array() ): array {
            $GLOBALS['bizcity_sent_stub'][] = array( 'chat_id' => $chat_id, 'message' => $message, 'type' => $type, 'extra' => $extra );
            return array( 'sent' => true );
        }
    }
}

if ( ! class_exists( 'WP_Error', false ) ) {
    class WP_Error {
        public $code; public $message;
        public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
        public function get_error_code() { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

/** N-06 — records every dispatch_webhook() call; setUp() below picks what it returns per test. */
if ( ! class_exists( 'BizCity_Automation_Trigger_Matcher', false ) ) {
    final class BizCity_Automation_Trigger_Matcher {
        public static function instance() { return new self(); }
        public function dispatch_webhook( string $slug, array $payload, ?string $token = null ) {
            $GLOBALS['bizcity_webhook_dispatched_stub'][] = array( 'slug' => $slug, 'payload' => $payload, 'token' => $token );
            return $GLOBALS['bizcity_webhook_dispatch_result_stub'] ?? array( 'ok' => true, 'run_id' => 999 );
        }
    }
}

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/class-task-handoff-notify.php';

final class CrmTaskHandoffNotifyTest extends TestCase {

    const LEADER = 10;
    const MEMBER = 42;
    const UNLINKED = 57;

    protected function setUp(): void {
        // The suite shares one process, so another test file may own the get_option()/get_transient() stubs.
        // Seed every known option store and read the throttle back through the same accessor the class uses.
        $GLOBALS['bizcity_options_stub'] = array( BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED => true );
        $GLOBALS['bizcity_reconciler_test_options'][ BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED ] = true;
        $GLOBALS['bizcity_transients_stub'] = array();
        $GLOBALS['bzc_transfer_transients'] = array();
        $GLOBALS['bizcity_transient_ttl_stub'] = array();
        $GLOBALS['bizcity_sent_stub'] = array();
        $GLOBALS['bizcity_users_stub'] = array( self::LEADER => 'Trưởng nhóm Marketing', self::MEMBER => 'Nguyễn Hương' );
        $GLOBALS['bzc_transfer_users'] = array( self::LEADER, self::MEMBER, self::UNLINKED );
        $GLOBALS['bizcity_webhook_dispatched_stub'] = array();
        $GLOBALS['bizcity_webhook_dispatch_result_stub'] = null;
        $GLOBALS['bizcity_zalo_links_stub'] = array(
            self::MEMBER => array(
                array( 'bot_id' => 7, 'zalo_user_id' => 'zu_member', 'status' => 'pending' ),
                array( 'bot_id' => 7, 'zalo_user_id' => 'zu_member_ok', 'status' => 'linked' ),
            ),
            self::UNLINKED => array( array( 'bot_id' => 7, 'zalo_user_id' => 'zu_pending', 'status' => 'pending' ) ),
        );
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['bizcity_options_stub'], $GLOBALS['bizcity_transients_stub'], $GLOBALS['bizcity_transient_ttl_stub'],
            $GLOBALS['bizcity_sent_stub'], $GLOBALS['bizcity_users_stub'], $GLOBALS['bizcity_zalo_links_stub'],
            $GLOBALS['bizcity_webhook_dispatched_stub'], $GLOBALS['bizcity_webhook_dispatch_result_stub']
        );
    }

    public function test_sends_one_internal_ping_to_the_linked_chat(): void {
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 8, self::LEADER, '2026-09-20' );

        $this->assertCount( 1, $GLOBALS['bizcity_sent_stub'] );
        $sent = $GLOBALS['bizcity_sent_stub'][0];
        $this->assertSame( 'zalobot_7_private_zu_member_ok', $sent['chat_id'], 'Only a linked row is a delivery target.' );
        $this->assertSame( 'crm_task_handoff', $sent['extra']['source'] );
        $this->assertStringContainsString( '8 việc mới', $sent['message'] );
        $leader = get_userdata( self::LEADER );
        $this->assertStringContainsString( (string) $leader->display_name, $sent['message'], 'The member sees who assigned the work.' );
        $this->assertStringContainsString( 'Hạn 20/09', $sent['message'] );
        $this->assertStringContainsString( '/gpt/crm/', $sent['message'] );
    }

    /** Contract §1.5 / Guardrail 6: an internal channel never carries customer identity. */
    public function test_message_carries_no_customer_data(): void {
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 2, self::LEADER, '2026-09-20' );
        $message = $GLOBALS['bizcity_sent_stub'][0]['message'];

        // The leader's own display name is internal staff data and may appear; customer identity must not.
        foreach ( array( 'Chị Lan', 'contact', 'conversation', 'task_id' ) as $needle ) {
            $this->assertStringNotContainsString( $needle, $message, "Internal ping leaked '{$needle}'." );
        }
        // No phone-shaped run of digits: the date is the only number besides the task count.
        $this->assertSame( 0, preg_match( '/\d{5,}/', $message ), 'Internal ping contains a phone-shaped digit run.' );
    }

    public function test_second_handoff_within_the_window_is_dropped(): void {
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 1, self::LEADER, null );
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 3, self::LEADER, null );

        $this->assertCount( 1, $GLOBALS['bizcity_sent_stub'] );
        $this->assertSame( 600, BizCity_CRM_Task_Handoff_Notify::THROTTLE_TTL, 'Contract §1.5: one ping per assignee per 10 minutes.' );
        $this->assertNotFalse( get_transient( BizCity_CRM_Task_Handoff_Notify::THROTTLE_PREFIX . self::MEMBER ) );
    }

    public function test_disabled_site_sends_nothing_and_does_not_burn_the_throttle(): void {
        $GLOBALS['bizcity_options_stub'][ BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED ] = false;
        $GLOBALS['bizcity_reconciler_test_options'][ BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED ] = false;

        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 4, self::LEADER, null );

        $this->assertSame( array(), $GLOBALS['bizcity_sent_stub'] );
        $this->assertFalse( get_transient( BizCity_CRM_Task_Handoff_Notify::THROTTLE_PREFIX . self::MEMBER ), 'A disabled site must not burn the throttle.' );
    }

    public function test_self_assigned_work_and_unlinked_members_are_skipped(): void {
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::LEADER, 1, self::LEADER, null );
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::UNLINKED, 1, self::LEADER, null );
        BizCity_CRM_Task_Handoff_Notify::on_assigned( 0, 1, self::LEADER, null );

        $this->assertSame( array(), $GLOBALS['bizcity_sent_stub'] );
    }

    // ── N-06 — leader's own automation workflow instead of the built-in template ──────────────────

    public function test_custom_workflow_dispatches_a_webhook_run_instead_of_the_default_template(): void {
        $GLOBALS['bizcity_options_stub'][ BizCity_CRM_Task_Handoff_Notify::OPTION_WORKFLOW ] = array( 'slug' => 'crm-ping', 'secret' => 's3cr3t' );

        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 5, self::LEADER, '2026-09-20' );

        $this->assertSame( array(), $GLOBALS['bizcity_sent_stub'], 'The default template must not also fire.' );
        $this->assertCount( 1, $GLOBALS['bizcity_webhook_dispatched_stub'] );
        $call = $GLOBALS['bizcity_webhook_dispatched_stub'][0];
        $this->assertSame( 'crm-ping', $call['slug'] );
        $this->assertSame( 's3cr3t', $call['token'] );
        $this->assertSame( 'zalobot_7_private_zu_member_ok', $call['payload']['chat_id'], 'A plain webhook → reply_zalo node reads chat_id straight from the trigger.' );
        $this->assertSame( self::MEMBER, $call['payload']['recipient_user_id'] );
        $this->assertSame( 5, $call['payload']['count'] );
        $this->assertSame( 'task_handoff', $call['payload']['kind'] );
        $this->assertSame( '2026-09-20', $call['payload']['due_date'] );
        foreach ( array( 'Chị Lan', 'contact', 'conversation', 'task_id' ) as $needle ) {
            $this->assertStringNotContainsString( $needle, wp_json_encode( $call['payload'] ), "Custom-workflow payload leaked '{$needle}'." );
        }
    }

    public function test_incomplete_workflow_config_falls_back_to_the_default_template(): void {
        $GLOBALS['bizcity_options_stub'][ BizCity_CRM_Task_Handoff_Notify::OPTION_WORKFLOW ] = array( 'slug' => 'crm-ping', 'secret' => '' );

        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 1, self::LEADER, null );

        $this->assertCount( 1, $GLOBALS['bizcity_sent_stub'] );
        $this->assertSame( array(), $GLOBALS['bizcity_webhook_dispatched_stub'] );
    }

    public function test_custom_workflow_still_honours_the_throttle_and_site_switch(): void {
        $GLOBALS['bizcity_options_stub'][ BizCity_CRM_Task_Handoff_Notify::OPTION_WORKFLOW ] = array( 'slug' => 'crm-ping', 'secret' => 's3cr3t' );

        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 1, self::LEADER, null );
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::MEMBER, 1, self::LEADER, null );
        $this->assertCount( 1, $GLOBALS['bizcity_webhook_dispatched_stub'], 'A throttled ping must not also skip to the custom workflow.' );

        $GLOBALS['bizcity_options_stub'][ BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED ] = false;
        $GLOBALS['bizcity_reconciler_test_options'][ BizCity_CRM_Task_Handoff_Notify::OPTION_ENABLED ] = false;
        BizCity_CRM_Task_Handoff_Notify::on_assigned( self::UNLINKED, 1, self::LEADER, null );
        $this->assertCount( 1, $GLOBALS['bizcity_webhook_dispatched_stub'], 'A disabled site must not reach the custom workflow either.' );
    }
}
