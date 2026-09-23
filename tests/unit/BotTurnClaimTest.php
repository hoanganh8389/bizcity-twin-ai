<?php
/**
 * PHASE-0.60A W3/W4 — BizCity_Bot_Turn_Claim tests.
 *
 * Covers B4.2/B12.7, the single highest-priority behavior in the whole feature
 * (doc §0.3, §10 risk table "Nghiêm trọng"): a claimed turn must flip
 * bizcity_automation_default_reply_enabled to false for that request, and an
 * unclaimed turn must leave it untouched (default true) — otherwise a customer
 * gets two replies (bot + the built-in Default_Reply safety net).
 *
 * Collaborators (BizCity_Channel_Binding, BizCity_Bot_Config_Repo) are faked so
 * this stays a hermetic unit test with no real $wpdb — matches the plan's own
 * scoping (real WordPress runtime coverage is bin/diagnostics-run.php's job).
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3-test
 */

// [2026-09-23 Claude Sonnet 5] Shared stub convention with BotConfigRepoTest.php /
// ChannelPersonalQuotaTest.php / ContextBankReconcilerTest.php — same backing store
// names, so whichever file's testsuite-discovery order defines these first behaves
// identically either way (see BotConfigRepoTest.php's longer note on this).
if ( ! isset( $GLOBALS['bizcity_reconciler_test_options'] ) ) {
	$GLOBALS['bizcity_reconciler_test_options'] = array();
}
if ( ! isset( $GLOBALS['bizcity_options_stub'] ) ) {
	$GLOBALS['bizcity_options_stub'] = array();
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		foreach ( array( 'bizcity_reconciler_test_options', 'bizcity_options_stub' ) as $store ) {
			if ( isset( $GLOBALS[ $store ] ) && is_array( $GLOBALS[ $store ] ) && array_key_exists( $name, $GLOBALS[ $store ] ) ) {
				return $GLOBALS[ $store ][ $name ];
			}
		}
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bizcity_reconciler_test_options'][ $name ] = $value;
		$GLOBALS['bizcity_options_stub'][ $name ]            = $value;
		return true;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code; private $message; private $data;
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
// [2026-09-23 Claude Sonnet 5] Same load-order-wins fragility as get_option above — this
// suite actually has TWO transient-stub conventions (bzc_transfer_transients in
// CrmContactTransferTest.php/CrmWorkspaceReadAuditTest.php; bizcity_transients_stub +
// bizcity_transient_ttl_stub in CrmTaskHandoffNotifyTest.php). Write-through to all of
// them so whichever file's guard "wins" the declaration race, every file's assertions
// still see the value.
// [2026-09-23 Claude Sonnet 5] Defensive on every access, not just at file-load time —
// CrmTaskHandoffNotifyTest::tearDown() unset()s bizcity_transients_stub after each of its
// own tests (safe under ITS OWN original single-owner assumption; no longer safe now that
// this file's get_transient() also depends on it surviving into later test classes).
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		foreach ( array( 'bzc_transfer_transients', 'bizcity_transients_stub' ) as $store ) {
			if ( array_key_exists( $key, $GLOBALS[ $store ] ?? array() ) ) {
				return $GLOBALS[ $store ][ $key ];
			}
		}
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['bzc_transfer_transients']    = $GLOBALS['bzc_transfer_transients'] ?? array();
		$GLOBALS['bizcity_transients_stub']    = $GLOBALS['bizcity_transients_stub'] ?? array();
		$GLOBALS['bizcity_transient_ttl_stub'] = $GLOBALS['bizcity_transient_ttl_stub'] ?? array();
		$GLOBALS['bzc_transfer_transients'][ $key ]    = $value;
		$GLOBALS['bizcity_transients_stub'][ $key ]    = $value;
		$GLOBALS['bizcity_transient_ttl_stub'][ $key ] = (int) $ttl;
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['bzc_transfer_transients'][ $key ], $GLOBALS['bizcity_transients_stub'][ $key ], $GLOBALS['bizcity_transient_ttl_stub'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
}

if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) {
final class BizCity_Knowledge_Database {
	private static $instance;
	private $rows = array();
	public static function instance(): self {
		if ( ! self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}
	public function seed( int $id, $settings_raw ): void {
		$this->rows[ $id ] = (object) array( 'id' => $id, 'settings' => $settings_raw, 'system_prompt' => 'Persona.' );
	}
	public function get_character( $id ) { return $this->rows[ (int) $id ] ?? null; }
	public function update_character( $id, $data ) {
		if ( isset( $this->rows[ (int) $id ] ) && isset( $data['settings'] ) ) {
			$this->rows[ (int) $id ]->settings = $data['settings'];
		}
		return true;
	}
}
}

/** Fake — a single settable canned binding row, standing in for the real $wpdb-backed resolve(). */
if ( ! class_exists( 'BizCity_Channel_Binding' ) ) {
final class BizCity_Channel_Binding {
	public static $next_binding = null; // set by each test before calling on_normalized()
	public static function resolve( string $platform, string $account_id ): ?array {
		return self::$next_binding;
	}
}
}

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-config-repo.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-office-hours.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-turn-claim.php';

use PHPUnit\Framework\TestCase;

final class BotTurnClaimTest extends TestCase {

	public static function tearDownAfterClass(): void {
		// [2026-09-23 Claude Sonnet 5] This class is the only one in the suite that actually
		// populates the real add_filter registry (bootstrap.php's upgraded hook stub) — clear it
		// so a later test class in the same process never inherits a stale registration.
		$GLOBALS['__bzc_hooks'] = array();
	}

	protected function setUp(): void {
		$GLOBALS['bizcity_reconciler_test_options'] = array();
		$GLOBALS['bizcity_options_stub']            = array();
		$GLOBALS['bzc_transfer_transients']         = array();
		$GLOBALS['bizcity_transients_stub']         = array();
		$GLOBALS['bizcity_transient_ttl_stub']      = array();
		$GLOBALS['__bzc_hooks']                     = array(); // reset the real add_filter/apply_filters registry
		BizCity_Channel_Binding::$next_binding      = null;
		$ref = new ReflectionProperty( BizCity_Knowledge_Database::class, 'rows' );
		$ref->setAccessible( true );
		$ref->setValue( BizCity_Knowledge_Database::instance(), array() );
		BizCity_Bot_Turn_Claim::init();
	}

	private function envelope( array $overrides = array() ): array {
		return array_merge( array(
			'platform'         => 'ZALO_PERSONAL',
			'account_id'       => 'acc-1',
			'chat_id'          => 'chat-1',
			'contact_id'       => 42,
			'chat_kind'        => 'user',
			'mention_detected' => false,
		), $overrides );
	}

	private function binding( array $overrides = array() ): array {
		return array_merge( array(
			'id'                => 7,
			'character_id'      => 5,
			'mode'              => 'auto',
			'office_hours_json' => '', // no restriction configured
		), $overrides );
	}

	private function default_reply_enabled(): bool {
		return apply_filters( 'bizcity_automation_default_reply_enabled', true, array() );
	}

	public function test_qualifying_message_claims_and_disables_default_reply(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding();

		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );

		$this->assertFalse( $this->default_reply_enabled(), 'claiming the turn must disable the default-reply net' );
		$claim = BizCity_Bot_Turn_Claim::consume_claim();
		$this->assertIsArray( $claim );
		$this->assertSame( 5, $claim['character_id'] );
		$this->assertSame( 42, $claim['contact_id'] );
		$this->assertSame( 20, $claim['history_limit'], 'falls back to the bot-settings default' );
	}

	public function test_consume_claim_clears_after_first_read(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding();
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );

		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim(), 'a claim is consumed exactly once' );
	}

	public function test_no_binding_does_not_claim_and_leaves_default_reply_enabled(): void {
		BizCity_Channel_Binding::$next_binding = null;
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );

		$this->assertTrue( $this->default_reply_enabled(), 'no claim must leave the safety net on' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_manual_mode_binding_does_not_claim(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array( 'mode' => 'manual' ) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );

		$this->assertTrue( $this->default_reply_enabled() );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_group_chat_without_mention_does_not_claim(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		$binding = $this->binding( array(
			'office_hours_json' => wp_json_encode( array( 'require_mention_in_group' => true ) ),
		) );
		BizCity_Channel_Binding::$next_binding = $binding;

		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'chat_kind' => 'group', 'mention_detected' => false ) ), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim(), 'group message without @mention must not claim' );

		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'chat_kind' => 'group', 'mention_detected' => true ) ), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim(), 'group message WITH @mention must claim' );
	}

	public function test_paused_after_manual_reply_does_not_claim(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'office_hours_json' => wp_json_encode( array( 'pause_on_manual_reply' => true ) ),
		) );
		BizCity_Bot_Turn_Claim::set_paused( 42, 30 );

		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
		$this->assertTrue( $this->default_reply_enabled() );
	}

	/** B4.1 — the claim must run BEFORE the real decision layer (Trigger_Matcher @30), not merely before the logger @1. */
	public function test_claim_priority_is_below_trigger_matcher_priority(): void {
		// [2026-09-23 04:45 PM Claude Fable 5.1] PHASE-0.60A B4.1 / 0.60D S3.1
		$matcher = (string) file_get_contents( dirname( __DIR__, 2 ) . '/core/automation/includes/class-automation-trigger-matcher.php' );
		$this->assertSame( 1, preg_match( "/add_action\\(\\s*'bizcity_channel_normalized'\\s*,\\s*array\\(\\s*\\\$self\\s*,\\s*'on_channel_normalized'\\s*\\)\\s*,\\s*(\\d+)/", $matcher, $m ), 'matcher hook line not found' );
		$this->assertSame( BizCity_Bot_Turn_Claim::TRIGGER_MATCHER_PRIORITY, (int) $m[1], 'doc §1.1a pins the matcher at 30; update both if it moves' );
		$this->assertLessThan( (int) $m[1], 0 );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-turn-claim.php' );
		$this->assertSame( 1, preg_match( "/add_action\\(\\s*'bizcity_channel_normalized'\\s*,\\s*array\\(\\s*__CLASS__\\s*,\\s*'on_normalized'\\s*\\)\\s*,\\s*0\\s*,/", $src ) );
	}

	/** B4.1a — symmetric bail: a Zone 2 (zalo_oa / zalo_bot) code on the same platform string never claims. */
	public function test_non_personal_code_does_not_claim(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding();
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'raw' => array( 'code' => 'zalo_oa' ) ) ), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
		$this->assertTrue( $this->default_reply_enabled() );
	}

	/** 0.60D S3.3 — a workflow enqueued in the same request marks the claim so the runner yields. */
	public function test_workflow_enqueued_marks_claim_only_when_one_exists(): void {
		BizCity_Bot_Turn_Claim::on_workflow_enqueued( 1, 2, array() );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim(), 'no claim → nothing to mark' );
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding();
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );
		BizCity_Bot_Turn_Claim::on_workflow_enqueued( 1, 2, array() );
		$claim = BizCity_Bot_Turn_Claim::consume_claim();
		$this->assertTrue( $claim['workflow_matched'] );
		$this->assertSame( 2, $claim['workflow_id'] );
	}

	public function test_daily_cap_reached_does_not_claim(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding();
		BizCity_Bot_Config_Repo::save_tuning( array( 'daily_message_cap' => 2 ) );
		BizCity_Bot_Turn_Claim::increment_today_count( 42 );
		BizCity_Bot_Turn_Claim::increment_today_count( 42 );

		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	/* ── PHASE-0.60E EA-1 · ALLOWLIST người gửi (doc §6 EA-1, acceptance E-E6) ── */

	public function test_allowlist_default_mode_is_all_and_still_claims(): void {
		// EA-1.6 — a binding saved before this feature existed (no policy_json at all) must see
		// no behavior change: the default() envelope() carries no allowlist overrides either.
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding(); // policy_json absent entirely.
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_allowlist_explicit_all_mode_claims_regardless_of_sender(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'allowlist_mode' => 'all' ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'identity_temporary' => true, 'user_id' => 'not-on-any-list' ) ), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_allowlist_contacts_only_mode_rejects_temporary_identity(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'allowlist_mode' => 'contacts_only' ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'identity_temporary' => true ) ), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim(), 'a throwaway/guest identity must not claim under contacts_only' );
		$this->assertTrue( $this->default_reply_enabled(), 'EA-1.3 — rejection must leave the default-reply net on' );
	}

	public function test_allowlist_contacts_only_mode_accepts_durable_identity(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'allowlist_mode' => 'contacts_only' ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'identity_temporary' => false ) ), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_allowlist_list_mode_rejects_uid_not_on_list(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'allowlist_mode' => 'list', 'allowlist_uids' => array( 'uid-1', 'uid-2' ) ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'user_id' => 'uid-9' ) ), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
		$this->assertTrue( $this->default_reply_enabled(), 'EA-1.3 — rejection must leave the default-reply net on' );
	}

	public function test_allowlist_list_mode_accepts_uid_on_list(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'allowlist_mode' => 'list', 'allowlist_uids' => array( 'uid-1', 'uid-2' ) ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'user_id' => 'uid-2' ) ), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_allowlist_list_mode_rejects_empty_sender_uid(): void {
		// A sender UID must be present to ever match a list — an empty envelope user_id can
		// never accidentally pass just because the list itself happens to contain ''.
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'allowlist_mode' => 'list', 'allowlist_uids' => array() ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'user_id' => '' ) ), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	/* ── PHASE-0.60E EA-2 · "Trả lời trong nhóm" bật/tắt riêng (doc §6 EA-2) ── */

	public function test_reply_in_group_default_true_still_claims_group_with_mention(): void {
		// EA-2.1 — a binding saved before this feature existed (key absent) must see no behavior
		// change: the group @mention gate still runs on its own.
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding();
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'chat_kind' => 'group', 'mention_detected' => true ) ), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	public function test_reply_in_group_false_rejects_group_message_even_with_mention(): void {
		// EA-2.3 — reply_in_group=false wins over require_mention_in_group: the @mention gate
		// becomes moot, so even an @mentioned group message must not claim.
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'reply_in_group' => false ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'chat_kind' => 'group', 'mention_detected' => true ) ), 'trigger' );
		$this->assertNull( BizCity_Bot_Turn_Claim::consume_claim() );
		$this->assertTrue( $this->default_reply_enabled(), 'EA-1.3-style guarantee also holds for EA-2 rejections' );
	}

	public function test_reply_in_group_false_still_claims_private_chat(): void {
		// EA-2.2 — turning group replies off must not touch private chat on the same Zalo number.
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'reply_in_group' => false ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope( array( 'chat_kind' => 'user' ) ), 'trigger' );
		$this->assertIsArray( BizCity_Bot_Turn_Claim::consume_claim() );
	}

	/* ── PHASE-0.60E EA-3 · claim carries passive_listen_in_group for the context builder ── */

	public function test_claim_carries_passive_listen_in_group_default_true(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding(); // policy_json absent entirely.
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );
		$claim = BizCity_Bot_Turn_Claim::consume_claim();
		$this->assertTrue( $claim['passive_listen_in_group'] );
	}

	public function test_claim_carries_passive_listen_in_group_when_turned_off(): void {
		BizCity_Knowledge_Database::instance()->seed( 5, '' );
		BizCity_Channel_Binding::$next_binding = $this->binding( array(
			'policy_json' => wp_json_encode( array( 'passive_listen_in_group' => false ) ),
		) );
		BizCity_Bot_Turn_Claim::on_normalized( $this->envelope(), 'trigger' );
		$claim = BizCity_Bot_Turn_Claim::consume_claim();
		$this->assertFalse( $claim['passive_listen_in_group'] );
	}
}
