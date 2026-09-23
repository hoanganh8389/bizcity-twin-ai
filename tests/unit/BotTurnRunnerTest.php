<?php
/**
 * PHASE-0.60A W3/W4 + PHASE-0.60D S3.4 — the four collision branches (E-D5), park/lock, pause arming,
 * hybrid drafts, one honest sentence when the provider dies (B4.7).
 *
 * Collaborators are fakes/seams; no provider, no Zalo, no $wpdb (B12.2).
 * // [2026-09-23 04:45 PM Claude Fable 5.1] PHASE-0.60A-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-config-repo.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-office-hours.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vn-date.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tool-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vertical-tools.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-astro-tool.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tools.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-context-builder.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-turn-claim.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-turn-runner.php';

use PHPUnit\Framework\TestCase;

final class BotTurnRunnerTest extends TestCase {

	private $sent      = array();
	private $scheduled = array();

	public static function tearDownAfterClass(): void {
		$GLOBALS['__bzc_hooks'] = array();
	}

	protected function setUp(): void {
		$GLOBALS['bizcity_reconciler_test_options'] = array();
		$GLOBALS['bizcity_options_stub']            = array();
		$GLOBALS['bzc_transfer_transients']         = array();
		$GLOBALS['bizcity_transients_stub']         = array();
		$GLOBALS['bizcity_transient_ttl_stub']      = array();
		$GLOBALS['__bzc_hooks']                     = array();
		BizCity_CRM_Repository::reset();
		BizCity_Channel_Binding::$next_binding = array( 'id' => 7, 'character_id' => 5, 'mode' => 'auto', 'office_hours_json' => '' );
		$ref = new ReflectionProperty( BizCity_Knowledge_Database::class, 'rows' );
		$ref->setAccessible( true );
		$ref->setValue( BizCity_Knowledge_Database::instance(), array() );
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_CRM_Repository::$conversations[9] = array( 'id' => 9, 'inbox_id' => 2, 'contact_id' => 42, 'source_id' => '5555' );
		BizCity_CRM_Repository::$messages = array(
			array( 'id' => 1, 'conversation_id' => 9, 'message_type' => 'incoming', 'content' => 'shop ơi còn size M ko' ),
		);
		BizCity_Bot_Config_Repo::save_tuning( array( 'max_tool_steps' => 0, 'debounce_seconds' => 8 ) );
		$this->sent      = array();
		$this->scheduled = array();
		$sent      = &$this->sent;
		$scheduled = &$this->scheduled;
		BizCity_Bot_Turn_Runner::$sender    = static function ( array $claim, string $text, array $meta ) use ( &$sent ) { $sent[] = $text; return array( 'ok' => true, 'message_id' => 77, 'error' => '' ); };
		BizCity_Bot_Turn_Runner::$scheduler = static function ( int $contact_id, int $delay ) use ( &$scheduled ) { $scheduled[] = array( $contact_id, $delay ); };
		BizCity_Bot_Turn_Runner::$llm       = static function ( $character, array $messages, array $claim ) { return array( 'success' => true, 'message' => 'Dạ còn ạ.', 'error' => '' ); };
		BizCity_Bot_Turn_Claim::init();
		BizCity_Bot_Turn_Runner::init();
		BizCity_Bot_Turn_Claim::consume_claim(); // ensure no stale claim from another test
	}

	protected function tearDown(): void {
		BizCity_Bot_Turn_Runner::$sender = BizCity_Bot_Turn_Runner::$scheduler = BizCity_Bot_Turn_Runner::$llm = null;
		$GLOBALS['__bzc_hooks'] = array();
	}

	private function envelope(): array {
		return array( 'platform' => 'ZALO_PERSONAL', 'account_id' => '3', 'chat_id' => 'zalop_3_5555', 'contact_id' => 42, 'chat_kind' => 'user', 'message_text_clean' => 'shop ơi còn size M ko', 'message_id' => 'z1', 'raw' => array( 'code' => 'zalo_personal' ) );
	}

	private function persisted(): array {
		return array( 'message_id' => 1, 'conversation_id' => 9, 'inbox_id' => 2, 'contact_id' => 42, 'adapter_code' => 'zalo_personal', 'direction' => 'incoming' );
	}

	private function claim(): array {
		return array( 'character_id' => 5, 'binding_id' => 7, 'account_id' => '3', 'chat_id' => 'zalop_3_5555', 'chat_kind' => 'user', 'contact_id' => 42, 'mode' => 'auto', 'text' => 'shop ơi còn size M ko', 'external_message_id' => 'z1', 'history_limit' => 20, 'bypass_notebook' => true, 'context_source' => 'crm', 'character_off' => array(), 'binding_off' => array(), 'workflow_matched' => false, 'conversation_id' => 9, 'message_id' => 1 );
	}

	/* S3.4 (b) — no workflow, bot claims → exactly one scheduled turn, safety net off. */
	public function test_claim_without_workflow_schedules_one_debounced_turn(): void {
		do_action( 'bizcity_channel_normalized', $this->envelope(), 'trigger' );
		$this->assertFalse( apply_filters( 'bizcity_automation_default_reply_enabled', true, array() ) );
		do_action( 'bizcity_crm_message_persisted', $this->persisted() );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( array( 42, 8 ), $this->scheduled[0] );
		$this->assertSame( 'waiting', BizCity_Bot_Turn_Claim::active_contacts()['42']['state'] );
	}

	/* S3.4 (a) — a workflow matched (enqueued) in the same request → the bot yields, nothing scheduled. */
	public function test_workflow_enqueued_makes_bot_yield(): void {
		do_action( 'bizcity_channel_normalized', $this->envelope(), 'trigger' );
		do_action( 'bizcity_automation_run_enqueued', 123, 44, array() );
		$this->assertTrue( BizCity_Bot_Turn_Claim::peek_claim()['workflow_matched'] );
		do_action( 'bizcity_crm_message_persisted', $this->persisted() );
		$this->assertCount( 0, $this->scheduled, 'workflow wins; bot never schedules a reply' );
		$this->assertCount( 0, $this->sent );
	}

	/* S3.4 (c) — no claim (manual binding) → default-reply net untouched, nothing scheduled. */
	public function test_no_claim_leaves_default_reply_on_and_schedules_nothing(): void {
		BizCity_Channel_Binding::$next_binding = array( 'id' => 7, 'character_id' => 5, 'mode' => 'manual', 'office_hours_json' => '' );
		do_action( 'bizcity_channel_normalized', $this->envelope(), 'trigger' );
		$this->assertTrue( apply_filters( 'bizcity_automation_default_reply_enabled', true, array() ) );
		do_action( 'bizcity_crm_message_persisted', $this->persisted() );
		$this->assertCount( 0, $this->scheduled );
	}

	/* S3.4 (d) / B4.7 — provider dead → still exactly ONE honest message, never the raw error. */
	public function test_provider_failure_sends_exactly_one_fallback_sentence(): void {
		BizCity_Bot_Turn_Runner::$llm = static function () { return array( 'success' => false, 'message' => '', 'error' => 'HTTP 500 upstream key invalid sk-abc' ); };
		$res = BizCity_Bot_Turn_Runner::run_turn( $this->claim() );
		$this->assertSame( 'fallback', $res['status'] );
		$this->assertCount( 1, $this->sent );
		$this->assertSame( BizCity_Bot_Turn_Runner::FALLBACK_TEXT, $this->sent[0] );
		$this->assertStringNotContainsString( 'sk-abc', $this->sent[0] );
		$this->assertFalse( BizCity_Bot_Turn_Runner::is_locked( 42 ), 'lock released in finally' );
	}

	public function test_successful_turn_sends_once_counts_cap_and_fires_completed_hook(): void {
		$fired = array();
		add_action( 'bizcity_bot_turn_completed', static function ( $payload ) use ( &$fired ) { $fired[] = $payload; }, 10, 1 );
		$res = BizCity_Bot_Turn_Runner::run_turn( $this->claim() );
		$this->assertSame( 'sent', $res['status'] );
		$this->assertSame( array( 'Dạ còn ạ.' ), $this->sent );
		$this->assertSame( 1, BizCity_Bot_Turn_Claim::today_count( 42 ) );
		$this->assertCount( 1, $fired );
		$this->assertSame( 9, $fired[0]['conversation_id'] );
		$this->assertSame( 77, $fired[0]['message_id'] );
		$this->assertArrayNotHasKey( '42', BizCity_Bot_Turn_Claim::active_contacts() );
	}

	public function test_hybrid_mode_stores_a_draft_note_and_never_sends(): void {
		$claim = $this->claim();
		$claim['mode'] = 'hybrid';
		$res = BizCity_Bot_Turn_Runner::run_turn( $claim );
		$this->assertSame( 'draft', $res['status'] );
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 1, BizCity_CRM_Repository::$inserted );
		$this->assertSame( 'private_note', BizCity_CRM_Repository::$inserted[0]['message_type'] );
		$this->assertSame( 'hybrid', BizCity_CRM_Repository::$inserted[0]['responder_kind'] );
		$this->assertStringContainsString( 'Dạ còn ạ.', BizCity_CRM_Repository::$inserted[0]['content'] );
	}

	public function test_busy_thread_parks_instead_of_running(): void {
		$claim = $this->claim();
		set_transient( 'bzbot_debounce_' . get_current_blog_id() . '_42', $claim, 120 );
		set_transient( 'bzbot_lock_' . get_current_blog_id() . '_42', time(), 60 ); // another turn is running
		BizCity_Bot_Turn_Runner::on_run_turn_cron( 42 );
		$this->assertCount( 0, $this->sent );
		$this->assertCount( 1, $this->scheduled, 'parked → rescheduled, not queued' );
		$parked = get_transient( 'bzbot_debounce_' . get_current_blog_id() . '_42' );
		$this->assertSame( 1, $parked['parks'] );
		$this->assertSame( 'parked', BizCity_Bot_Turn_Claim::active_contacts()['42']['state'] );
	}

	public function test_cron_fire_reruns_conditions_and_drops_when_binding_switched_to_manual(): void {
		set_transient( 'bzbot_debounce_' . get_current_blog_id() . '_42', $this->claim(), 120 );
		BizCity_Channel_Binding::$next_binding = array( 'id' => 7, 'character_id' => 5, 'mode' => 'manual', 'office_hours_json' => '' );
		BizCity_Bot_Turn_Runner::on_run_turn_cron( 42 );
		$this->assertCount( 0, $this->sent, 'E11 — disabling the binding silences the bot immediately' );
		$this->assertFalse( get_transient( 'bzbot_debounce_' . get_current_blog_id() . '_42' ) );
	}

	public function test_manual_outgoing_row_arms_pause_and_drops_pending_turn(): void {
		set_transient( 'bzbot_debounce_' . get_current_blog_id() . '_42', $this->claim(), 120 );
		do_action( 'bizcity_crm_message_inserted', 500, array( 'conversation_id' => 9, 'message_type' => 'outgoing', 'responder_kind' => 'manual' ) );
		$this->assertTrue( BizCity_Bot_Turn_Claim::is_paused( 42 ) );
		$this->assertFalse( get_transient( 'bzbot_debounce_' . get_current_blog_id() . '_42' ) );
		// A bot's own outgoing row must NOT pause the bot.
		$GLOBALS['bzc_transfer_transients'] = array(); $GLOBALS['bizcity_transients_stub'] = array();
		do_action( 'bizcity_crm_message_inserted', 501, array( 'conversation_id' => 9, 'message_type' => 'outgoing', 'responder_kind' => 'auto' ) );
		$this->assertFalse( BizCity_Bot_Turn_Claim::is_paused( 42 ) );
	}
}
