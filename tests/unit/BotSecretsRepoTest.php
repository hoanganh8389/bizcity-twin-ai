<?php
/**
 * PHASE-0.60E D-E1 — BizCity_Bot_Secrets_Repo pure-helper tests.
 *
 * The class's CRUD methods hit $wpdb directly (get_row/insert/update/delete),
 * and this suite deliberately does not mock $wpdb — no other repo class in
 * this test suite does either (tests/bootstrap.php's own docblock: "For
 * integration testing (REST, hooks, schema), use bin/diagnostics-run.php").
 * This file covers only the framework-pure surface: the field registry and
 * the masking helper, which is the one piece a leaked test double could
 * actually get wrong in a way that matters (never showing more of a real key
 * than the mask allows). Real read/write round-trips are the runtime probe's
 * job (doc §10 E-E9).
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60E D-E1
 */

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-secrets-repo.php';

use PHPUnit\Framework\TestCase;

final class BotSecretsRepoTest extends TestCase {

	public function test_known_fields_match_the_registry(): void {
		$this->assertTrue( BizCity_Bot_Secrets_Repo::is_known_field( 'tts_api_keys' ) );
		$this->assertTrue( BizCity_Bot_Secrets_Repo::is_known_field( 'stt_api_key' ) );
		$this->assertTrue( BizCity_Bot_Secrets_Repo::is_known_field( 'music_api_key' ) );
		$this->assertTrue( BizCity_Bot_Secrets_Repo::is_known_field( 'apify_token' ) );
		$this->assertTrue( BizCity_Bot_Secrets_Repo::is_known_field( 'tavily_api_key' ) );
		$this->assertFalse( BizCity_Bot_Secrets_Repo::is_known_field( 'not_a_real_field' ) );
	}

	public function test_only_tts_is_multi_valued(): void {
		// EB-6 rotation UI is only drawn for TTS in the reference product; STT/music/Apify/Tavily
		// are single "để trống để giữ nguyên" fields — this pins that shape so a future edit to
		// FIELDS can't silently turn a single-value field into a multi one (or vice versa).
		$this->assertTrue( BizCity_Bot_Secrets_Repo::is_multi( 'tts_api_keys' ) );
		$this->assertFalse( BizCity_Bot_Secrets_Repo::is_multi( 'stt_api_key' ) );
		$this->assertFalse( BizCity_Bot_Secrets_Repo::is_multi( 'music_api_key' ) );
		$this->assertFalse( BizCity_Bot_Secrets_Repo::is_multi( 'apify_token' ) );
		$this->assertFalse( BizCity_Bot_Secrets_Repo::is_multi( 'tavily_api_key' ) );
	}

	public function test_mask_shows_only_a_short_prefix_and_suffix(): void {
		$masked = BizCity_Bot_Secrets_Repo::mask( 'AIzaSyDxxxxxxxxxxxxxxxxxxxxxxxxx2Y8E' );
		$this->assertSame( 'AIzaS…2Y8E', $masked );
		$this->assertStringNotContainsString( 'xxxxxxxxxxxxxxxxxxxxxxxxx', $masked, 'the middle of a real key must never appear in the masked form' );
	}

	public function test_mask_never_reveals_a_short_key_in_full(): void {
		// EB-6.2 "không bao giờ trả bản rõ" — even a short/malformed key must not round-trip
		// through mask() unchanged.
		$masked = BizCity_Bot_Secrets_Repo::mask( 'sk123' );
		$this->assertNotSame( 'sk123', $masked );
		$this->assertStringNotContainsString( 'sk123', $masked );
	}

	public function test_mask_handles_empty_string_without_error(): void {
		$this->assertIsString( BizCity_Bot_Secrets_Repo::mask( '' ) );
	}
}
