<?php
/**
 * PHASE-0.60G G2 — trigger.bot_turn_completed: a workflow can now react to "the Guru bot just replied".
 *
 * Pure helpers run in-process. The real Trigger_Matcher::on_bot_turn_completed() runs in its own PHP
 * process (support/bot-turn-completed-matcher-harness.php) against tiny Repo/Trace fakes so the real hook
 * registration, account/guru filtering, dedup and never-throw guarantee are exercised without $wpdb/cron.
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60G G2 — test, not shipped code.
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/automation/includes/blocks/interface-block.php';
require_once dirname( __DIR__, 2 ) . '/core/automation/includes/blocks/abstract-block.php';
require_once dirname( __DIR__, 2 ) . '/core/automation/includes/blocks/triggers/class-trigger-bot-turn-completed.php';

use PHPUnit\Framework\TestCase;

final class AutomationBotTurnCompletedTriggerTest extends TestCase {

	private function event( array $over = array() ): array {
		return array_merge( array(
			'conversation_id' => 9, 'contact_id' => 42, 'character_id' => 5, 'message_id' => 77,
			'account_id' => '3', 'chat_id' => 'zalop_3_5555', 'trace_id' => 'trace-1',
		), $over );
	}

	public function test_block_identity_matches_the_workflow_trigger_type(): void {
		$b = new BizCity_Automation_Trigger_Bot_Turn_Completed();
		$this->assertSame( 'trigger.bot_turn_completed', $b->id() );
		$this->assertSame( 'trigger', $b->kind() );
		$this->assertSame( 'bot_turn_completed', BizCity_Automation_Trigger_Bot_Turn_Completed::TRIGGER_TYPE );
		// `trigger.<code>` is how the FE/runner derive the trigger_type — they must agree.
		$this->assertSame( 'trigger.' . BizCity_Automation_Trigger_Bot_Turn_Completed::TRIGGER_TYPE, $b->id() );
		$fields = array_column( $b->meta()['fields'], null, 'name' );
		$this->assertSame( 'text', $fields['instance_id']['type'], 'account filter is plain text: the channel registry has no Zalo personal accounts' );
		$this->assertArrayHasKey( 'guru_id', $fields );
	}

	public function test_build_payload_carries_ids_and_a_no_reentry_guard(): void {
		$p = BizCity_Automation_Trigger_Bot_Turn_Completed::build_payload( $this->event() );
		$this->assertSame( 9, $p['conversation_id'] );
		$this->assertSame( 42, $p['contact_id'] );
		$this->assertSame( 5, $p['character_id'] );
		$this->assertSame( 77, $p['message_id'] );
		$this->assertSame( '3', $p['account_id'] );
		$this->assertSame( '3', $p['instance_id'] );
		$this->assertSame( 'bot_turn_completed', $p['_trigger'] );
		$this->assertTrue( $p['_no_automation_reentry'], 'a reply-back workflow must never re-enter the inbound matcher' );
		$this->assertSame( 'ZALO_PERSONAL', $p['inbound']['platform'] );
		$this->assertSame( '77', $p['inbound']['message_id'] );
		$this->assertArrayNotHasKey( 'reply', $p, 'reply text stays in CRM, read by message_id' );
	}

	public function test_build_payload_rejects_events_that_cannot_be_attached_to_a_conversation(): void {
		$this->assertSame( array(), BizCity_Automation_Trigger_Bot_Turn_Completed::build_payload( $this->event( array( 'conversation_id' => 0 ) ) ) );
		$this->assertSame( array(), BizCity_Automation_Trigger_Bot_Turn_Completed::build_payload( 'not-an-array' ) );
		$this->assertSame( array(), BizCity_Automation_Trigger_Bot_Turn_Completed::build_payload( null ) );
	}

	public function test_reject_reason_filters_by_account_and_guru_and_empty_config_accepts_all(): void {
		$p = BizCity_Automation_Trigger_Bot_Turn_Completed::build_payload( $this->event() );
		$r = 'BizCity_Automation_Trigger_Bot_Turn_Completed::reject_reason';
		$this->assertSame( '', call_user_func( $r, array(), $p ) );
		$this->assertSame( '', call_user_func( $r, array( 'instance_id' => '3', 'guru_id' => 5 ), $p ) );
		$this->assertSame( '', call_user_func( $r, array( 'account_id' => ' 3 ' ), $p ), 'account_id alias + trim, same as the inbound matcher' );
		$this->assertSame( 'account_mismatch', call_user_func( $r, array( 'instance_id' => '4' ), $p ) );
		$this->assertSame( 'guru_mismatch', call_user_func( $r, array( 'guru_id' => 6 ), $p ) );
		$this->assertSame( '', call_user_func( $r, array( 'guru_id' => 0 ), $p ), 'guru_id=0 means shared across gurus' );
	}

	public function test_wiring_is_present_in_every_layer_that_must_know_the_trigger(): void {
		$root = dirname( __DIR__, 2 ) . '/core/automation/';
		$read = static function ( $rel ) use ( $root ) { return (string) file_get_contents( $root . $rel ); };
		$this->assertStringContainsString( "'bot_turn_completed'", $read( 'includes/class-automation-repo-workflows.php' ), 'TRIGGER_TYPES allowlist (else save is rejected)' );
		$this->assertStringContainsString( 'class-trigger-bot-turn-completed.php', $read( 'bootstrap.php' ) );
		$this->assertStringContainsString( 'BizCity_Automation_Trigger_Bot_Turn_Completed', $read( 'includes/blocks/class-block-registry.php' ) );
		$this->assertMatchesRegularExpression( "/add_action\(\s*'bizcity_bot_turn_completed'\s*,\s*array\(\s*\\\$self\s*,\s*'on_bot_turn_completed'/", $read( 'includes/class-automation-trigger-matcher.php' ) );
		$this->assertStringContainsString( "id: 'trigger.bot_turn_completed'", $read( 'frontend/src/blocks/registry.js' ) );
		$this->assertStringContainsString( "'bot_turn_completed'", $read( 'frontend/src/runtime/runner.js' ), 'Chạy thử must listen, not run blindly' );
	}

	public function test_real_matcher_enqueues_only_workflows_whose_account_and_guru_match_and_dedups_the_turn(): void {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/bot-turn-completed-matcher-harness.php' ) . ' 2>&1';
		$raw = (string) shell_exec( $cmd );
		$out = json_decode( $raw, true );
		$this->assertIsArray( $out, 'harness must print JSON, got: ' . substr( $raw, 0, 400 ) );

		$this->assertTrue( $out['hooked'], 'init() registers the bizcity_bot_turn_completed hook' );
		$this->assertSame( array( 1, 2 ), $out['first_ids'], 'only accepting, enabled, same-trigger workflows run' );
		$this->assertSame( 11, $out['first_payload']['_owner_user_id'], 'owner = workflow creator (the customer contact is never the owner)' );
		$this->assertSame( 9, $out['first_payload']['conversation_id'] );
		$this->assertTrue( $out['first_payload']['_no_automation_reentry'] );
		$this->assertContains( 'bot_turn_account_mismatch', $out['traces'] );
		$this->assertContains( 'bot_turn_guru_mismatch', $out['traces'] );
		$this->assertContains( 'bot_turn_fired', $out['traces'] );

		$this->assertSame( 2, $out['after_duplicate'], 'same trace fired twice in one request = one enqueue' );
		$this->assertSame( 4, $out['after_second_turn'], 'a different turn is a new run' );
		$this->assertSame( 4, $out['after_invalid'], 'an event without a conversation is ignored' );
		$this->assertTrue( $out['invalid_traced'] );
		$this->assertFalse( $out['broken_repo_threw'], 'a broken repository must never throw back into the bot turn runner' );
		$this->assertSame( 4, $out['after_broken_repo'] );
	}
}
