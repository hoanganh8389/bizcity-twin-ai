<?php
/**
 * PHASE-0.60C D1.3–D1.6 — provider resolution contract + end-anchored host match.
 * // [2026-09-23 04:40 PM Claude Fable 5.1] PHASE-0.60C-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-provider.php';

use PHPUnit\Framework\TestCase;

final class BotProviderTest extends TestCase {

	public function test_branch_4_inherit_follows_site_mode_and_defaults_to_gateway(): void {
		$r = BizCity_Bot_Provider::effective( array(), 'gateway' );
		$this->assertSame( 4, $r['branch'] );
		$this->assertSame( 'gateway', $r['mode'] );
		$r = BizCity_Bot_Provider::effective( array(), 'direct' );
		$this->assertSame( 'direct', $r['mode'] );
		$this->assertSame( 'site', $r['source'] );
	}

	public function test_new_character_with_no_override_always_ends_on_1api_when_site_is_gateway(): void {
		$this->assertSame( 'gateway', BizCity_Bot_Provider::effective( array( 'provider_mode' => 'inherit' ), 'gateway' )['mode'] );
	}

	public function test_branch_2_direct_without_own_key_ignores_override_and_badges(): void {
		// The invariant: the site key must never travel to a foreign base URL.
		$r = BizCity_Bot_Provider::effective( array( 'provider_mode' => 'direct', 'has_own_key' => false ), 'gateway' );
		$this->assertSame( 2, $r['branch'] );
		$this->assertTrue( $r['override_ignored'] );
		$this->assertSame( 'gateway', $r['mode'] );
		$this->assertSame( 'thiếu khóa', $r['badge'] );
	}

	public function test_branch_1_direct_with_own_key_uses_override(): void {
		$r = BizCity_Bot_Provider::effective( array( 'provider_mode' => 'direct', 'has_own_key' => true ), 'gateway' );
		$this->assertSame( 1, $r['branch'] );
		$this->assertSame( 'direct', $r['mode'] );
		$this->assertSame( 'character', $r['source'] );
	}

	public function test_branch_3_gateway_override_forces_1api_even_when_site_is_direct(): void {
		$r = BizCity_Bot_Provider::effective( array( 'provider_mode' => 'gateway' ), 'direct' );
		$this->assertSame( 3, $r['branch'] );
		$this->assertSame( 'gateway', $r['mode'] );
	}

	public function test_host_match_is_end_anchored(): void {
		$this->assertTrue( BizCity_Bot_Provider::host_matches( 'https://generativelanguage.googleapis.com/v1', 'googleapis.com' ) );
		$this->assertTrue( BizCity_Bot_Provider::host_matches( 'https://googleapis.com/x', 'googleapis.com' ) );
		$this->assertFalse( BizCity_Bot_Provider::host_matches( 'https://api.googleapis.com.evil.example/v1', 'googleapis.com' ), 'D1.6: a contains-match would pass this' );
		$this->assertFalse( BizCity_Bot_Provider::host_matches( 'https://notgoogleapis.com/', 'googleapis.com' ) );
		$this->assertFalse( BizCity_Bot_Provider::host_matches( '', 'googleapis.com' ) );
	}

	public function test_gateway_gaps_are_exactly_tts_stt_music(): void {
		$this->assertSame( array( 'tts', 'create_music' ), BizCity_Bot_Provider::gateway_gaps( array( 'web_search', 'tts', 'create_music' ) ) );
		$this->assertSame( array(), BizCity_Bot_Provider::gateway_gaps( array( 'web_search' ) ) );
	}
}
