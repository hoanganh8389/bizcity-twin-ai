<?php
/**
 * PHASE-0.60F OW-4 (doc §6.1 G-10) — BizCity_Bot_Apify_Client guard-clause tests.
 *
 * Same rule as BotSecretsRepoTest.php: this suite does not mock $wpdb, so only the DB-free
 * validation branches (character_id<=0, unknown platform, invalid URL — all checked BEFORE any
 * BizCity_Bot_Config_Repo/BizCity_Bot_Secrets_Repo read) are covered here. The actual HTTP call to
 * Apify is exercised only by a real, funded-key smoke test (not part of this suite).
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60F OW-4-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-apify-client.php';

use PHPUnit\Framework\TestCase;

final class BotApifyClientTest extends TestCase {

	public function test_rejects_missing_character_id_before_any_db_read(): void {
		$result = BizCity_Bot_Apify_Client::scrape( 0, 'facebook', 'https://facebook.com/example' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
	}

	public function test_rejects_unknown_platform(): void {
		$result = BizCity_Bot_Apify_Client::scrape( 5, 'twitter', 'https://twitter.com/example' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_param', $result->get_error_code() );
		$this->assertSame( 'bot_apify_platform_unknown', $result->get_error_data()['help_code'] );
	}

	public function test_rejects_non_http_url(): void {
		foreach ( array( 'facebook', 'tiktok', 'youtube', 'shopee' ) as $platform ) {
			$result = BizCity_Bot_Apify_Client::scrape( 5, $platform, 'not-a-url' );
			$this->assertInstanceOf( WP_Error::class, $result, $platform );
			$this->assertSame( 'bot_apify_url_invalid', $result->get_error_data()['help_code'], $platform );
		}
	}

	public function test_all_four_documented_platforms_map_to_a_distinct_actor_field(): void {
		$ref = new ReflectionClassConstant( BizCity_Bot_Apify_Client::class, 'PLATFORM_ACTOR_FIELD' );
		$map = $ref->getValue();
		$this->assertSame(
			array( 'facebook' => 'actor_facebook', 'tiktok' => 'actor_tiktok', 'youtube' => 'actor_youtube', 'shopee' => 'actor_shopee' ),
			$map
		);
	}
}
