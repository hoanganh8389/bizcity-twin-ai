<?php
/**
 * PHASE-0.60H D-H5 — Libe-Zalo action parity: per-turn gates (group-only, owner-only), confirm,
 * ids taken from the claim (never from model args), and bridge-capability status.
 * // [2026-09-24 Claude Opus 5.5] PHASE-0.60H D-H5-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-config-repo.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tool-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vertical-tools.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tools.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-zalo-actions.php';

use PHPUnit\Framework\TestCase;

final class BotZaloActionsTest extends TestCase {

	/** @var array */
	private $calls = array();

	protected function setUp(): void {
		$this->calls = array();
		BizCity_Bot_Zalo_Actions::$capabilities = null;
		BizCity_Bot_Zalo_Actions::$runner       = function ( $account, $action, $body ) {
			$this->calls[] = compact( 'account', 'action', 'body' );
			return array( 'success' => true, 'poll_id' => 77 );
		};
	}

	protected function tearDown(): void {
		BizCity_Bot_Zalo_Actions::$capabilities = null;
		BizCity_Bot_Zalo_Actions::$runner       = null;
	}

	private function all_ids(): array {
		return array_map( static function ( $id ) {
			return array( 'id' => $id );
		}, array_keys( BizCity_Bot_Zalo_Actions::tools() ) );
	}

	private function group_claim( string $sender = '111', string $owner = '111' ): array {
		return array( 'account_id' => '3', 'chat_kind' => 'group', 'group_id' => '5550001', 'source_id' => 'group:5550001', 'sender_uid' => $sender, 'owner_uid' => $owner, 'external_message_id' => '7001234567', 'conversation_id' => 9, 'message_id' => 0 );
	}

	private function dm_claim( string $sender = '222', string $owner = '111' ): array {
		return array( 'account_id' => '3', 'chat_kind' => 'user', 'group_id' => '', 'source_id' => $sender, 'sender_uid' => $sender, 'owner_uid' => $owner, 'external_message_id' => '7001234568', 'conversation_id' => 9, 'message_id' => 0 );
	}

	public function test_every_requested_libe_zalo_command_has_its_own_tool_id(): void {
		$ids = array_keys( BizCity_Bot_Zalo_Actions::tools() );
		foreach ( array( 'create_poll', 'lock_poll', 'send_sticker', 'react_message', 'recall_message', 'rename_group', 'create_group', 'kick_group_member', 'transfer_group_owner', 'set_group_deputy', 'list_pending_group_members', 'review_pending_group_member', 'set_group_member_blocked', 'set_group_invite_link', 'change_group_avatar', 'pin_group_note', 'add_group_members' ) as $id ) {
			$this->assertContains( $id, $ids );
		}
		$this->assertNotContains( 'group_admin', $ids, 'no umbrella tool with an action enum' );
	}

	public function test_status_follows_the_bridge_capability_list(): void {
		$by = array_column( BizCity_Bot_Zalo_Actions::catalog_rows(), null, 'id' );
		$this->assertSame( 'needs_bridge', $by['create_poll']['status'], 'no bridge client / old bridge → needs_bridge, never available' );
		BizCity_Bot_Zalo_Actions::$capabilities = array( 'create_poll', 'send_sticker' );
		$by = array_column( BizCity_Bot_Zalo_Actions::catalog_rows(), null, 'id' );
		$this->assertSame( 'available', $by['create_poll']['status'] );
		$this->assertSame( 'needs_bridge', $by['rename_group']['status'], 'an action the running sidecar does not advertise stays locked' );
	}

	public function test_customer_in_a_private_chat_gets_only_harmless_thread_tools(): void {
		$ids = array_column( BizCity_Bot_Zalo_Actions::filter_for_turn( $this->all_ids(), $this->dm_claim() ), 'id' );
		sort( $ids );
		$this->assertSame( array( 'react_message', 'send_sticker' ), $ids );
	}

	public function test_non_owner_in_a_group_never_sees_admin_tools(): void {
		$ids = array_column( BizCity_Bot_Zalo_Actions::filter_for_turn( $this->all_ids(), $this->group_claim( '999', '111' ) ), 'id' );
		sort( $ids );
		$this->assertSame( array( 'create_poll', 'get_group_info', 'lock_poll', 'react_message', 'send_sticker' ), $ids );
	}

	public function test_empty_owner_uid_locks_owner_tools_even_for_matching_empty_sender(): void {
		$ids = array_column( BizCity_Bot_Zalo_Actions::filter_for_turn( $this->all_ids(), $this->group_claim( '', '' ) ), 'id' );
		$this->assertNotContains( 'kick_group_member', $ids );
		$this->assertNotContains( 'rename_group', $ids );
	}

	public function test_owner_in_a_group_gets_the_full_admin_set(): void {
		$ids = array_column( BizCity_Bot_Zalo_Actions::filter_for_turn( $this->all_ids(), $this->group_claim() ), 'id' );
		$this->assertCount( count( BizCity_Bot_Zalo_Actions::tools() ), $ids );
	}

	public function test_react_is_dropped_when_the_turn_has_no_zalo_message_id(): void {
		$claim = $this->dm_claim();
		$claim['external_message_id'] = 'composer:abc';
		$ids = array_column( BizCity_Bot_Zalo_Actions::filter_for_turn( $this->all_ids(), $claim ), 'id' );
		$this->assertNotContains( 'react_message', $ids );
	}

	public function test_executor_refuses_owner_tools_for_a_non_owner_even_when_called_directly(): void {
		$res = BizCity_Bot_Zalo_Actions::run( 'rename_group', array( 'name' => 'Hacked', 'confirm' => 'YES' ), $this->group_claim( '999', '111' ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'owner_only', $res['error'] );
		$this->assertSame( array(), $this->calls, 'the bridge is never called' );
	}

	public function test_destructive_tools_need_confirm_yes(): void {
		$res = BizCity_Bot_Zalo_Actions::run( 'kick_group_member', array( 'user_id' => '12345' ), $this->group_claim() );
		$this->assertSame( 'confirm_required', $res['error'] );
		$this->assertSame( array(), $this->calls );
		$res = BizCity_Bot_Zalo_Actions::run( 'kick_group_member', array( 'user_id' => '12345', 'confirm' => 'YES' ), $this->group_claim() );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( 'kick_member', $this->calls[0]['action'] );
	}

	public function test_group_id_comes_from_the_turn_never_from_model_args(): void {
		BizCity_Bot_Zalo_Actions::run( 'rename_group', array( 'name' => 'Nhóm mới', 'group_id' => '8888888', 'confirm' => 'YES' ), $this->group_claim() );
		$this->assertSame( '5550001', $this->calls[0]['body']['group_id'] );
		$this->assertSame( 'Nhóm mới', $this->calls[0]['body']['name'] );
	}

	public function test_create_poll_body_and_result_reach_the_model(): void {
		$res = BizCity_Bot_Zalo_Actions::run( 'create_poll', array( 'question' => 'Ăn gì?', 'options' => array( 'Phở', ' ', 'Cơm' ), 'multi' => true ), $this->group_claim( '999' ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( array( 'Phở', 'Cơm' ), $this->calls[0]['body']['options'] );
		$this->assertTrue( $this->calls[0]['body']['allow_multi'] );
		$this->assertStringContainsString( 'poll_id=77', $res['content'] );
		$bad = BizCity_Bot_Zalo_Actions::run( 'create_poll', array( 'question' => 'x', 'options' => array( 'A' ) ), $this->group_claim( '999' ) );
		$this->assertSame( 'poll_needs_two_options', $bad['error'] );
	}

	public function test_react_uses_the_inbound_zalo_msg_id_and_dm_thread(): void {
		BizCity_Bot_Zalo_Actions::run( 'react_message', array( 'icon' => 'heart' ), $this->dm_claim() );
		$this->assertSame( '7001234568', $this->calls[0]['body']['msg_id'] );
		$this->assertSame( '222', $this->calls[0]['body']['thread_id'] );
		$this->assertSame( 'user', $this->calls[0]['body']['thread_kind'] );
	}

	public function test_bridge_refusal_is_reported_not_swallowed(): void {
		BizCity_Bot_Zalo_Actions::$runner = static function () {
			return array( 'success' => false, 'code' => 'zalo_refused', 'message' => 'not admin' );
		};
		$res = BizCity_Bot_Zalo_Actions::run( 'kick_group_member', array( 'user_id' => '12345', 'confirm' => 'YES' ), $this->group_claim() );
		$this->assertFalse( $res['ok'] );
		$this->assertStringStartsWith( 'zalo_refused', $res['error'] );
	}

	public function test_presence_is_skipped_when_the_bridge_does_not_advertise_it(): void {
		BizCity_Bot_Zalo_Actions::presence( 'typing', $this->dm_claim() );
		$this->assertSame( array(), $this->calls, 'old bridge → no call at all, never a 404 mid-turn' );
	}

	public function test_presence_typing_and_react_use_the_turn_thread_and_configured_icon(): void {
		BizCity_Bot_Zalo_Actions::$capabilities = array( 'typing', 'react' );
		$claim = $this->group_claim( '999' ) + array( 'react_icon' => 'like' );
		BizCity_Bot_Zalo_Actions::presence( 'typing', $claim );
		BizCity_Bot_Zalo_Actions::presence( 'react', $claim );
		$this->assertSame( 'typing', $this->calls[0]['action'] );
		$this->assertSame( array( 'thread_id' => '5550001', 'thread_kind' => 'group', 'duration_ms' => 60000 ), $this->calls[0]['body'] );
		$this->assertSame( 'react', $this->calls[1]['action'] );
		$this->assertSame( 'like', $this->calls[1]['body']['icon'] );
		$this->assertSame( '7001234567', $this->calls[1]['body']['msg_id'] );
	}

	public function test_presence_never_throws_into_the_turn(): void {
		BizCity_Bot_Zalo_Actions::$capabilities = array( 'typing' );
		BizCity_Bot_Zalo_Actions::$runner       = static function () {
			throw new RuntimeException( 'bridge down' );
		};
		BizCity_Bot_Zalo_Actions::presence( 'typing', $this->dm_claim() );
		$this->assertTrue( true );
	}

	public function test_get_group_info_reads_this_groups_members_as_fenced_data(): void {
		BizCity_Bot_Zalo_Actions::$runner = function ( $account, $action, $body ) {
			$this->calls[] = compact( 'account', 'action', 'body' );
			return array( 'success' => true, 'members' => array( array( 'id' => '12345', 'displayName' => 'Nam' ) ) );
		};
		$res = BizCity_Bot_Zalo_Actions::run( 'get_group_info', array( 'group_id' => '999' ), $this->group_claim( '999' ) );
		$this->assertTrue( $res['ok'] );
		$this->assertSame( '5550001', $this->calls[0]['body']['group_id'] );
		$this->assertStringContainsString( 'Nam (uid=12345)', $res['content'] );
		$this->assertStringContainsString( BizCity_Bot_Tools::FENCE_OPEN, $res['content'] );
	}

	public function test_registry_turn_gate_applies_the_zalo_filter(): void {
		$tools = BizCity_Bot_Tool_Registry::effective_for_turn( $this->all_ids(), $this->dm_claim() );
		$this->assertNotContains( 'kick_group_member', array_column( $tools, 'id' ) );
	}
}
