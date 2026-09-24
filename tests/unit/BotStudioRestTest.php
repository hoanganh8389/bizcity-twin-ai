<?php
/**
 * PHASE-0.60F OW-1 — BizCity_Bot_Studio_REST projection helper tests.
 *
 * Scope: the pure, DB-free pieces of the `/bot-studio/accounts` projection
 * (status bucketing, policy default merge reuse, and the no-dependency
 * fallback shapes). The DB-backed pieces it composes (has-key checks,
 * binding rows, conversation counts) are already covered by
 * BotSecretsRepoTest / BotConfigRepoTest / the binding + CRM repo suites —
 * this file does not re-mock wpdb to re-prove those.
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-1-test
 */

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-rest.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-studio-rest.php';

use PHPUnit\Framework\TestCase;

final class BotStudioRestTest extends TestCase {

	private function call( string $method, array $args ) {
		$ref = new ReflectionMethod( BizCity_Bot_Studio_REST::class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, ...$args );
	}

	public function test_status_bucket_normalizes_online_variants_to_connected(): void {
		foreach ( array( 'connected', 'online', 'active', 'ready', 'CONNECTED', ' Online ' ) as $raw ) {
			$this->assertSame( 'connected', $this->call( 'status_bucket', array( $raw ) ) );
		}
	}

	public function test_status_bucket_passes_through_unknown_bridge_status_as_is(): void {
		$this->assertSame( 'pending_qr', $this->call( 'status_bucket', array( 'pending_qr' ) ) );
		$this->assertSame( 'error', $this->call( 'status_bucket', array( 'error' ) ) );
	}

	public function test_status_bucket_empty_string_means_offline(): void {
		$this->assertSame( 'offline', $this->call( 'status_bucket', array( '' ) ) );
	}

	public function test_policy_summary_with_no_binding_returns_all_defaults(): void {
		$summary = $this->call( 'policy_summary', array( null ) );
		$this->assertSame( array(
			'allowlist_mode'          => 'all',
			'reply_in_group'          => true,
			'passive_listen_in_group' => true,
			'owner_uid_set'           => false,
		), $summary );
	}

	/** Reuses BizCity_Bot_REST::policy_defaults_merged() — must reflect a saved binding's real policy_json, not re-derive its own defaults. */
	public function test_policy_summary_reflects_saved_binding_policy_json(): void {
		$binding = array(
			'policy_json' => wp_json_encode( array(
				'allowlist_mode'          => 'list',
				'allowlist_uids'          => array( 'u1' ),
				'reply_in_group'          => false,
				'passive_listen_in_group' => true,
				'owner_uid'               => 'owner_123',
			) ),
		);
		$summary = $this->call( 'policy_summary', array( $binding ) );
		$this->assertSame( 'list', $summary['allowlist_mode'] );
		$this->assertFalse( $summary['reply_in_group'] );
		$this->assertTrue( $summary['passive_listen_in_group'] );
		$this->assertTrue( $summary['owner_uid_set'] );
	}

	public function test_policy_summary_blank_owner_uid_is_not_set(): void {
		$binding = array( 'policy_json' => wp_json_encode( array( 'owner_uid' => '' ) ) );
		$summary = $this->call( 'policy_summary', array( $binding ) );
		$this->assertFalse( $summary['owner_uid_set'] );
	}

	/**
	 * character_id=0 takes the early-return branch and never touches $wpdb, unlike a real
	 * character_id which would call through to BizCity_Bot_Secrets_Repo::has() — this suite
	 * deliberately does not mock $wpdb (same rule as BotSecretsRepoTest), so only the DB-free
	 * path is exercised here. It still pins that `video` is always false (EB-4 has no secret
	 * field yet, doc §7 EB-4.1) even in the all-false fallback shape.
	 */
	public function test_media_flags_with_no_character_id_are_all_false(): void {
		$this->assertSame(
			array( 'tts' => false, 'stt' => false, 'music' => false, 'video' => false, 'apify' => false ),
			$this->call( 'media_flags', array( 0 ) )
		);
	}

	public function test_crm_stats_with_no_inbox_id_returns_nulls_not_fabricated_zeros(): void {
		$this->assertSame(
			array( 'sessions' => null, 'messages' => null, 'last_message_at' => null ),
			$this->call( 'crm_stats', array( 0 ) )
		);
	}

	public function test_guru_summary_with_no_character_id_is_null(): void {
		$this->assertNull( $this->call( 'guru_summary', array( 0 ) ) );
	}

	/** A-07: usage is counted across ALL platforms, per Guru; junk rows never inflate a count. */
	public function test_guru_usage_counts_groups_bindings_by_character_and_ignores_junk(): void {
		$counts = $this->call( 'guru_usage_counts', array( array(
			array( 'platform' => 'ZALO_PERSONAL', 'character_id' => 5 ),
			array( 'platform' => 'ZALO_PERSONAL', 'character_id' => '5' ),
			array( 'platform' => 'FACEBOOK', 'character_id' => 5 ),
			array( 'platform' => 'ZALO_PERSONAL', 'character_id' => 8 ),
			array( 'platform' => 'ZALO_PERSONAL', 'character_id' => 0 ),
			array( 'platform' => 'ZALO_PERSONAL' ),
			'not-a-row',
		) ) );
		$this->assertSame( array( 5 => 3, 8 => 1 ), $counts );
		$this->assertSame( array(), $this->call( 'guru_usage_counts', array( array() ) ) );
	}

	/* ── PHASE-0.60F OW-2 §4.2 — /bot-studio/sessions helpers ─────────── */

	public function test_message_counts_for_empty_ids_is_empty_without_touching_the_db(): void {
		$this->assertSame( array(), $this->call( 'message_counts_for', array( array() ) ) );
		$this->assertSame( array(), $this->call( 'message_counts_for', array( array( 0, 0 ) ) ), 'zero/invalid ids are filtered out, not queried' );
	}

	public function test_bot_enabled_for_with_no_platform_or_account_is_null_not_false(): void {
		// Null means "cannot resolve", distinct from a real binding with auto_reply=false — the
		// short-circuit here never even reaches BizCity_Channel_Binding, so it is independent of
		// whatever fake/real binding class other test files may have loaded process-wide.
		$this->assertNull( $this->call( 'bot_enabled_for', array( '', 'acc-1' ) ) );
		$this->assertNull( $this->call( 'bot_enabled_for', array( 'ZALO_PERSONAL', '' ) ) );
	}

	/* ── PHASE-0.60F OW-3 §2.4/§5.4A — /bot-studio/identity ───────────── */

	/**
	 * R-CH-IDMEM pin: a group's source_id must never be treated as a personal identity ref.
	 * `rest_identity()`'s group branch returns before touching Identity Hub or CRM at all, so this
	 * is the one piece of that route worth pinning without a WP_REST_Request stub — get the
	 * detector wrong and the whole safety branch silently stops firing.
	 */
	public function test_is_group_ref_detects_the_group_source_id_prefix(): void {
		$this->assertTrue( $this->call( 'is_group_ref', array( 'group:12345' ) ) );
		$this->assertFalse( $this->call( 'is_group_ref', array( '12345' ) ) );
		$this->assertFalse( $this->call( 'is_group_ref', array( '' ) ) );
		$this->assertFalse( $this->call( 'is_group_ref', array( 'not-a-group:12345' ) ), 'must anchor at the start, not just contain "group:"' );
	}
}
