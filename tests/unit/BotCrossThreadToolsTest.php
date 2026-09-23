<?php
/**
 * PHASE-0.60E EA-7 (D-E2, user-approved) — list_threads/read_thread execution.
 *
 * The per-turn gate itself (owner_uid match · private chat) is covered by
 * BotToolRegistryTest.php's effective_for_turn() tests; this file covers what
 * happens once a turn has already been let through: the opaque thread_ref
 * hand-off between list_threads and read_thread, and that every read is
 * logged (EA-7.4).
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60E EA-7
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-config-repo.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tool-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vertical-tools.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tools.php';

use PHPUnit\Framework\TestCase;

final class BotCrossThreadToolsTest extends TestCase {

	/** @var array<int,array{event:string,ctx:array}> */
	private $logged = array();

	protected function setUp(): void {
		$GLOBALS['bzc_transfer_transients']    = array();
		$GLOBALS['bizcity_transients_stub']    = array();
		$GLOBALS['bizcity_transient_ttl_stub'] = array();
		BizCity_Zalo_Bridge_Client::$next_candidates_response = array( 'success' => true, 'groups' => array() );
		BizCity_Zalo_Bridge_Client::$next_history_response    = array( 'success' => true, 'messages' => array() );
		BizCity_Zalo_Bridge_Client::$last_history_call        = null;
		$this->logged = array();
		// [2026-09-23 Claude Sonnet 5] test seam, not a global class fake — a fake
		// BizCity_Channel_File_Logger here would win the class-declaration race for the whole
		// PHPUnit process and break ChannelDiagnosticsLoggerTest.php, which needs the real one.
		BizCity_Bot_Tools::$log_writer = function ( string $event, array $ctx ) {
			$this->logged[] = array( 'event' => $event, 'ctx' => $ctx );
		};
	}

	protected function tearDown(): void {
		BizCity_Bot_Tools::$log_writer = null;
	}

	private function claim(): array {
		return array( 'account_id' => 'acc-1', 'contact_id' => 42, 'owner_uid' => 'owner-1', 'sender_uid' => 'owner-1', 'chat_kind' => 'user' );
	}

	public function test_list_threads_counts_groups_without_names_and_logs_the_read(): void {
		BizCity_Zalo_Bridge_Client::$next_candidates_response = array(
			'success' => true,
			'groups'  => array(
				array( 'thread_ref' => 'gh1.aaa', 'group_id_hash' => 'h1' ),
				array( 'thread_ref' => 'gh1.bbb', 'group_id_hash' => 'h2' ),
			),
		);
		$res = BizCity_Bot_Tools::run( 'list_threads', array(), $this->claim() );
		$this->assertTrue( $res['ok'] );
		$this->assertStringContainsString( 'Nhóm 1', $res['content'] );
		$this->assertStringContainsString( 'Nhóm 2', $res['content'] );
		$this->assertStringNotContainsString( 'gh1.aaa', $res['content'], 'the opaque thread_ref must never reach the model' );

		$log = end( $this->logged );
		$this->assertSame( 'bot_cross_thread_list_threads', $log['event'] );
		$this->assertSame( 2, $log['ctx']['message_count'] );
		$this->assertSame( 42, $log['ctx']['contact_id'] );
	}

	public function test_read_thread_uses_the_ref_remembered_from_list_threads(): void {
		BizCity_Zalo_Bridge_Client::$next_candidates_response = array(
			'success' => true,
			'groups'  => array( array( 'thread_ref' => 'gh1.aaa' ), array( 'thread_ref' => 'gh1.bbb' ) ),
		);
		BizCity_Bot_Tools::run( 'list_threads', array(), $this->claim() );

		BizCity_Zalo_Bridge_Client::$next_history_response = array(
			'success'  => true,
			'messages' => array(
				array( 'content' => 'chào cả nhóm', 'is_self' => false ),
				array( 'content' => 'ok nhé', 'is_self' => true ),
			),
		);
		$res = BizCity_Bot_Tools::run( 'read_thread', array( 'index' => 2 ), $this->claim() );
		$this->assertTrue( $res['ok'] );
		$this->assertStringContainsString( 'chào cả nhóm', $res['content'] );
		$this->assertSame( array( 'acc-1', 'gh1.bbb', 20 ), BizCity_Zalo_Bridge_Client::$last_history_call, 'index 2 must resolve to the SECOND remembered thread_ref' );

		$log = end( $this->logged );
		$this->assertSame( 'bot_cross_thread_read_thread', $log['event'] );
		$this->assertSame( 2, $log['ctx']['thread_index'] );
		$this->assertSame( 2, $log['ctx']['message_count'] );
	}

	public function test_read_thread_without_a_prior_list_call_fails_closed(): void {
		$res = BizCity_Bot_Tools::run( 'read_thread', array( 'index' => 1 ), $this->claim() );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'thread_index_unknown', $res['error'] );
	}

	public function test_read_thread_rejects_an_out_of_range_index(): void {
		BizCity_Zalo_Bridge_Client::$next_candidates_response = array( 'success' => true, 'groups' => array( array( 'thread_ref' => 'gh1.aaa' ) ) );
		BizCity_Bot_Tools::run( 'list_threads', array(), $this->claim() );
		$res = BizCity_Bot_Tools::run( 'read_thread', array( 'index' => 5 ), $this->claim() );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'thread_index_unknown', $res['error'] );
	}

	public function test_a_different_contact_cannot_read_another_contacts_remembered_threads(): void {
		BizCity_Zalo_Bridge_Client::$next_candidates_response = array( 'success' => true, 'groups' => array( array( 'thread_ref' => 'gh1.aaa' ) ) );
		BizCity_Bot_Tools::run( 'list_threads', array(), $this->claim() );
		$other_claim = array_merge( $this->claim(), array( 'contact_id' => 99 ) );
		$res = BizCity_Bot_Tools::run( 'read_thread', array( 'index' => 1 ), $other_claim );
		$this->assertFalse( $res['ok'], 'the thread index cache is per-contact — one conversation cannot spend another\'s list' );
	}
}
