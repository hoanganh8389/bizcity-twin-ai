<?php
/**
 * PHASE-0.60H D-H2 / D-H2b — the "system owner" that lets a fully-automated Bot Studio conversation send media.
 *
 * First tests ever written for BizCity_CRM_Outbound_Dispatcher. They run the REAL dispatcher and the REAL
 * BizCity_CRM_System_Owner in their own PHP process (support/outbound-dispatcher-system-owner-harness.php).
 * Each scenario stops at `channel_adapter_unavailable` on purpose: reaching it PROVES the owner gate and the
 * attachment validation passed; any earlier code names the gate that refused.
 *
 * The decision under test is deliberately narrow (D-H2b): ONLY `ai_autoreply`, ONLY with attachments, ONLY for an
 * inbox with an active auto-replying bot binding. The tests pin BOTH directions so nobody widens it by accident:
 * what is allowed AND what must still be refused.
 *
 * // [2026-09-24 Claude Sonnet 5] PHASE-0.60H — test, not shipped code.
 */

use PHPUnit\Framework\TestCase;

final class OutboundDispatcherSystemOwnerTest extends TestCase {

	private static $out = null;

	private static function out(): array {
		if ( null === self::$out ) {
			$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/support/outbound-dispatcher-system-owner-harness.php' ) . ' 2>&1';
			$raw = (string) shell_exec( $cmd );
			$out = json_decode( $raw, true );
			if ( ! is_array( $out ) ) {
				self::fail( 'harness must print JSON, got: ' . substr( $raw, 0, 500 ) );
			}
			self::$out = $out;
		}
		return self::$out;
	}

	/* ── what IS allowed ─────────────────────────────────────────────────────── */

	public function test_ai_autoreply_media_on_a_bound_zalo_personal_inbox_is_owned_by_the_system_owner(): void {
		$s = self::out()['s1_ai_autoreply_media_passes_as_system_owner'];
		$this->assertSame( 'channel_adapter_unavailable', $s['code'], 'owner gate AND attachment validation both passed' );
		$this->assertSame( 'system_owner', $s['owner_source'], 'recorded as its own source so an audit can tell it from a person' );
		$this->assertSame( 77, $s['on_behalf_of'] );
		$this->assertTrue( $s['owner_ok'] );
	}

	public function test_a_human_assignee_still_wins_over_the_system_owner(): void {
		$s = self::out()['s9_human_assignee_wins'];
		$this->assertSame( 'conversation_assignee', $s['owner_source'] );
		$this->assertSame( 12, $s['on_behalf_of'] );
		$this->assertSame( 'channel_adapter_unavailable', $s['code'] );
	}

	/* ── what must STILL be refused (the D-H2b boundary) ─────────────────────── */

	public function test_other_system_sources_keep_the_old_text_only_behaviour(): void {
		$out = self::out();
		foreach ( array( 's2_kg_reply_media_still_denied', 's2b_automation_media_still_denied' ) as $case ) {
			$this->assertSame( 'permission_denied', $out[ $case ]['code'], $case );
			$this->assertSame( 'inbox_capability', $out[ $case ]['owner_source'], $case );
			$this->assertSame( 0, $out[ $case ]['on_behalf_of'], $case );
		}
	}

	public function test_a_plain_text_bot_reply_is_untouched_and_keeps_the_capability_anchor(): void {
		$s = self::out()['s3_ai_autoreply_text_only_unchanged'];
		$this->assertSame( 'inbox_capability', $s['owner_source'], 'no attachment ⇒ no reason to introduce another owner' );
		$this->assertSame( 0, $s['on_behalf_of'] );
		$this->assertSame( 'channel_adapter_unavailable', $s['code'], 'text sends were never blocked' );
	}

	public function test_no_active_auto_replying_binding_means_no_system_owner(): void {
		$out = self::out();
		foreach ( array( 's4_no_binding', 's5_binding_auto_reply_off', 's8_not_a_zalo_personal_inbox' ) as $case ) {
			$this->assertSame( 'permission_denied', $out[ $case ]['code'], $case );
			$this->assertSame( 'inbox_capability', $out[ $case ]['owner_source'], $case );
		}
	}

	public function test_a_privileged_system_owner_fails_closed(): void {
		$s = self::out()['s7_privileged_system_owner_fails_closed'];
		$this->assertSame( 'permission_denied', $s['code'], 'if the account ever gains manage_options it must stop being trusted' );
		$this->assertSame( 'inbox_capability', $s['owner_source'] );
	}

	/** The point of the ownership rule: the bot can only send files the bot itself authored. */
	public function test_a_file_authored_by_someone_else_is_refused_even_with_a_system_owner(): void {
		$s = self::out()['s6_foreign_file_refused'];
		$this->assertSame( 'permission_denied', $s['code'] );
		$this->assertFalse( $s['owner_ok'], 'validate_attachments() compares post_author to the SYSTEM owner, unchanged' );
	}

	/* ── the shared resolver the bot tool calls ───────────────────────────────── */

	public function test_the_bot_tool_and_the_dispatcher_ask_the_same_question(): void {
		$s = self::out()['s10a_shared_resolver_existing_owner'];
		$this->assertSame( 77, $s['user_id'] );
		$this->assertSame( 'system_owner', $s['owner_source'] );
	}

	public function test_the_system_user_is_created_on_first_use_only_for_a_bound_conversation_and_only_once(): void {
		$out = self::out();
		$this->assertSame( 0, $out['s10b_first_use_no_create_flag']['inserts'], 'the send path / a read never creates users' );
		$this->assertSame( 0, $out['s10b_first_use_no_create_flag']['anchor']['user_id'] );

		$made = $out['s10c_first_use_creates_when_bound'];
		$this->assertSame( 1, $made['inserts'] );
		$this->assertSame( 100, $made['anchor']['user_id'] );
		$this->assertSame( 100, $made['stored_option'] );
		$this->assertSame( '', $made['role'], 'no role at all — not subscriber, not staff' );
		$this->assertSame( 'bizcity-system-bot@invalid.invalid', $made['email'], 'reserved .invalid TLD: can never receive real mail' );

		$this->assertSame( 1, $out['s10d_creation_is_idempotent']['inserts'], 'a second bot turn does not create a second user' );
		$this->assertSame( 0, $out['s10e_unbound_conversation_never_creates']['inserts'], 'an unbound conversation never causes a user to be created' );
		$this->assertSame( 0, $out['s10e_unbound_conversation_never_creates']['anchor']['user_id'] );
	}

	/* ── BizCity_CRM_System_Owner itself ──────────────────────────────────────── */

	public function test_an_existing_account_with_that_login_that_is_privileged_is_never_adopted(): void {
		$s = self::out()['s11a_existing_privileged_account_refused'];
		$this->assertSame( 0, $s['ensure'] );
		$this->assertSame( 0, $s['option'], 'nothing stored: someone else registered the login with real rights' );
	}

	public function test_a_deleted_system_user_is_recreated_only_when_asked_to(): void {
		$s = self::out()['s11b_deleted_user_is_recreated'];
		$this->assertSame( 0, $s['resolve_no_create'] );
		$this->assertGreaterThan( 0, $s['resolve_create'] );
	}

	public function test_the_system_user_cannot_log_in_and_is_hidden_from_assignee_pickers(): void {
		$s = self::out()['s11c_login_blocked'];
		$this->assertTrue( $s['system_is_error'] );
		$this->assertSame( 'bizcity_system_user', $s['system_code'] );
		$this->assertTrue( $s['staff_untouched'], 'other users authenticate exactly as before' );
		$this->assertTrue( $s['error_passthrough'], 'an existing auth error is passed through untouched' );
		$this->assertSame( array( 77 ), $s['exclude_ids'] );
		$this->assertSame( array( true, false ), $s['is_system_owner'] );
	}
}
