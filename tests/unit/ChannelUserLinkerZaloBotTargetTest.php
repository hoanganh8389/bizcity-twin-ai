<?php
/**
 * PHASE-0.50 R-LM-8 — BizCity_Channel_User_Linker::zalo_bot_target_for_user().
 *
 * A member's Zalo Bot is the bot bound to their first `user_id`, through either bind path:
 *   (1) admin binds in the Channel Gateway BE  → canonical `bizcity_channel_user_links` row
 *   (2) owner connects in `/gpt/` Kênh của tôi → linked row + `bizcity_twinweb_mychannels` selection
 * plus legacy `bizcity_zalobot_user_links` rows. Pure unit test: $wpdb, user meta and the legacy
 * linker are in-memory stubs, no WordPress bootstrap.
 *
 * Stubs are guarded and read shared globals so this file can share one PHPUnit process with
 * CrmTaskHandoffNotifyTest (which stubs the legacy linker the same way).
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_current_blog_id' ) ) {
    function get_current_blog_id() { return 1; }
}
if ( ! function_exists( 'bizcity_tbl_exists' ) ) {
    function bizcity_tbl_exists( $table ) { return ! empty( $GLOBALS['bzc_linker_table_present'] ); }
}
if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $user_id, $key = '', $single = false ) {
        return $GLOBALS['bzc_linker_user_meta'][ (int) $user_id ][ $key ] ?? '';
    }
}
if ( ! class_exists( 'BizCity_Zalobot_User_Linker', false ) ) {
    final class BizCity_Zalobot_User_Linker {
        public static function get_links_for_wp_user( int $wp_user_id ): array {
            return $GLOBALS['bizcity_zalo_links_stub'][ $wp_user_id ] ?? array();
        }
    }
}

/** Minimal $wpdb for the one canonical-link SELECT the resolver runs. */
final class BizCity_Linker_Test_Wpdb {
    public $prefix = 'wp_';
    public $dbname = 'test';
    public function prepare( $sql, ...$args ) { return array( 'sql' => $sql, 'args' => $args ); }
    public function get_results( $query, $output = 'ARRAY_A' ) {
        list( $blog_id, $platform, $wp_user_id, $status ) = $query['args'];
        $rows = array();
        foreach ( $GLOBALS['bzc_linker_rows'] ?? array() as $row ) {
            if ( (int) $row['blog_id'] === (int) $blog_id && $row['platform'] === $platform && (int) $row['wp_user_id'] === (int) $wp_user_id && $row['status'] === $status ) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}

final class ChannelUserLinkerZaloBotTargetTest extends TestCase {

    const HUONG = 42;
    const MINH = 57;

    private $previous_wpdb;

    protected function setUp(): void {
        parent::setUp();
        if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
        $this->previous_wpdb = array( 'set' => array_key_exists( 'wpdb', $GLOBALS ), 'value' => $GLOBALS['wpdb'] ?? null );
        require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/class-channel-user-linker.php';
        $GLOBALS['wpdb'] = new BizCity_Linker_Test_Wpdb();
        $GLOBALS['bzc_linker_table_present'] = true;
        $GLOBALS['bzc_linker_rows'] = array();
        $GLOBALS['bzc_linker_user_meta'] = array();
        $GLOBALS['bizcity_zalo_links_stub'] = array();
        $this->reset_table_memo();
    }

    protected function tearDown(): void {
        // Put back exactly what other test files installed (ContextBankReconcilerTest sets its fake at include time).
        if ( is_array( $this->previous_wpdb ) && $this->previous_wpdb['set'] ) {
            $GLOBALS['wpdb'] = $this->previous_wpdb['value'];
        } else {
            unset( $GLOBALS['wpdb'] );
        }
        unset( $GLOBALS['bzc_linker_table_present'], $GLOBALS['bzc_linker_rows'], $GLOBALS['bzc_linker_user_meta'], $GLOBALS['bizcity_zalo_links_stub'] );
        $this->reset_table_memo();
        parent::tearDown();
    }

    private function reset_table_memo(): void {
        $property = new ReflectionProperty( BizCity_Channel_User_Linker::class, 'table_exists' );
        $property->setAccessible( true );
        $property->setValue( null, array() );
    }

    /** Blog id: another test file may own the get_current_blog_id() stub, so never hard-code it. */
    private function canonical( int $user_id, int $bot_id, string $zalo_user_id, string $status = 'linked', int $blog_offset = 0 ): void {
        $GLOBALS['bzc_linker_rows'][] = array( 'blog_id' => (int) get_current_blog_id() + $blog_offset,'platform' => 'ZALO_BOT', 'wp_user_id' => $user_id, 'status' => $status, 'account_id' => (string) $bot_id, 'external_user_id' => $zalo_user_id );
    }

    public function test_admin_bind_in_the_channel_gateway_is_found(): void {
        $this->canonical( self::HUONG, 7, 'zu_huong' );

        $target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::HUONG );

        $this->assertSame( array( 'bot_id' => 7, 'chat_id' => 'zalobot_7_private_zu_huong', 'source' => 'channel_link' ), $target );
    }

    public function test_legacy_link_is_used_when_there_is_no_canonical_row(): void {
        $GLOBALS['bizcity_zalo_links_stub'][ self::HUONG ] = array(
            array( 'bot_id' => 9, 'zalo_user_id' => 'zu_pending', 'status' => 'pending' ),
            array( 'bot_id' => 9, 'zalo_user_id' => 'zu_legacy', 'status' => 'linked' ),
        );

        $target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::HUONG );

        $this->assertSame( 'zalobot_9_private_zu_legacy', $target['chat_id'] );
        $this->assertSame( 'legacy_link', $target['source'] );
    }

    public function test_my_channels_selection_picks_among_the_user_real_links(): void {
        $this->canonical( self::HUONG, 7, 'zu_first' );
        $this->canonical( self::HUONG, 8, 'zu_chosen' );
        $GLOBALS['bzc_linker_user_meta'][ self::HUONG ]['bizcity_twinweb_mychannels'] = array( 'selected_zalo_bot_id' => 8, 'selected_zalo_chat_id' => 'zalobot_8_private_zu_chosen' );

        $target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::HUONG );

        $this->assertSame( 'zalobot_8_private_zu_chosen', $target['chat_id'] );
        $this->assertSame( 'mychannels', $target['source'] );
    }

    public function test_a_selected_chat_that_is_not_linked_to_the_user_is_ignored(): void {
        // Hương points her selection at Minh's chat: it must never become her delivery target.
        $this->canonical( self::HUONG, 7, 'zu_huong' );
        $this->canonical( self::MINH, 7, 'zu_minh' );
        $GLOBALS['bzc_linker_user_meta'][ self::HUONG ]['bizcity_twinweb_mychannels'] = array( 'selected_zalo_chat_id' => 'zalobot_7_private_zu_minh' );

        $target = BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::HUONG );

        $this->assertSame( 'zalobot_7_private_zu_huong', $target['chat_id'] );
    }

    public function test_unbound_user_gets_nothing_and_pending_or_unlinked_rows_do_not_count(): void {
        $this->canonical( self::MINH, 7, 'zu_pending', 'pending' );
        $this->canonical( self::MINH, 7, 'zu_gone', 'unlinked' );
        $GLOBALS['bzc_linker_user_meta'][ self::MINH ]['bizcity_twinweb_mychannels'] = array( 'selected_zalo_chat_id' => 'zalobot_7_private_zu_pending' );

        $this->assertSame( array(), BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::MINH ) );
        $this->assertSame( array(), BizCity_Channel_User_Linker::zalo_bot_target_for_user( 0 ) );
    }

    public function test_links_of_another_blog_are_ignored(): void {
        $this->canonical( self::HUONG, 7, 'zu_other_site', 'linked', 1 );

        $this->assertSame( array(), BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::HUONG ) );
    }

    public function test_the_same_identity_in_both_tables_is_counted_once_with_canonical_first(): void {
        $this->canonical( self::HUONG, 7, 'zu_huong' );
        $GLOBALS['bizcity_zalo_links_stub'][ self::HUONG ] = array( array( 'bot_id' => 7, 'zalo_user_id' => 'zu_huong', 'status' => 'linked' ) );

        $this->assertSame( 'channel_link', BizCity_Channel_User_Linker::zalo_bot_target_for_user( self::HUONG )['source'] );
    }
}
