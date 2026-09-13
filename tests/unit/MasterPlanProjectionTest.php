<?php

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( (string) $url, $component );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { return filter_var( (string) $url, FILTER_SANITIZE_URL ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
}

require_once dirname( __DIR__, 2 ) . '/core/bizcity-llm/includes/class-master-plan-projection.php';

final class MasterPlanProjectionTest extends TestCase {

	public function test_entitlement_is_exact_key_projection_and_never_returns_full_key(): void {
		$projection = BizCity_Master_Plan_Projection::entitlement(
			array(
				'ok'           => true,
				'master_level' => 'master_premium',
				'master_label' => '<b>Master Premium</b>',
				'normalized_tier' => 'premium',
				'key_info'     => array(
					'key_id' => 4478,
					'label' => 'Production',
					'allowed_domain' => 'example.com',
					'key_prefix' => 'biz-abc123',
					'api_key' => 'biz-THIS-MUST-NOT-ESCAPE',
				),
				'fetched_at' => '2026-09-13T10:00:00Z',
			),
			'https://bizcity.vn',
			strtotime( '2026-09-13T10:04:00Z' )
		);

		$this->assertSame( 'exact_key_entitlement', $projection['kind'] );
		$this->assertSame( 'premium', $projection['normalized_tier'] );
		$this->assertSame( 'Master Premium', $projection['master_label'] );
		$this->assertSame( 'fresh', $projection['freshness'] );
		$this->assertArrayNotHasKey( 'api_key', $projection['key_info'] );
	}

	public function test_catalog_is_not_entitlement(): void {
		$catalog = BizCity_Master_Plan_Projection::catalog( array(
			array( 'level' => 'master_pro', 'label' => 'Master Pro', 'price_usd' => 29 ),
			array( 'level' => 'master_premium', 'label' => 'Master Premium', 'price_usd' => 99 ),
		) );

		$this->assertSame( 'public_catalog', $catalog['kind'] );
		$this->assertCount( 2, $catalog['items'] );
		$this->assertArrayNotHasKey( 'master_level', $catalog );
	}

	public function test_action_urls_are_limited_to_https_gateway_origin(): void {
		$actions = BizCity_Master_Plan_Projection::safe_actions(
			array(
				'upgrade_url' => 'https://bizcity.vn/my-account/plans/?key_id=4478',
				'history_url' => 'http://bizcity.vn/insecure',
				'purchase_url' => 'https://evil.example/steal',
				'source' => 'hub_exact_key',
			),
			'https://bizcity.vn'
		);

		$this->assertSame( 'https://bizcity.vn/my-account/plans/?key_id=4478', $actions['upgrade_url'] );
		$this->assertArrayNotHasKey( 'history_url', $actions );
		$this->assertArrayNotHasKey( 'purchase_url', $actions );
		$this->assertSame( 'hub_exact_key', $actions['source'] );
	}

	public function test_old_fetched_projection_becomes_stale(): void {
		$this->assertSame(
			'stale',
			BizCity_Master_Plan_Projection::freshness( '2026-09-13T09:00:00Z', strtotime( '2026-09-13T09:06:00Z' ) )
		);
	}
}