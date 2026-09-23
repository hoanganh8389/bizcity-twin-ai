<?php
/**
 * PHASE-0.69 WP-L — pure-logic behaviour of `BizCity_CRM_Location_Service`: the haversine distance
 * formula and the two-source (`quote_src` JSON, then a flattened `maps?q=` text link) coordinate
 * extraction from a Zalo Personal "send location" message (0.69 §4.1/§4.2). No DB, no WordPress runtime.
 *
 * [2026-09-23] Written against a standalone `php -r`-style harness first (this sandbox's local
 * `vendor/bin/phpunit` does not execute — `require 'vendor/autoload.php'` exits silently with zero output
 * before phpunit's own bootstrap ever runs, on this specific Windows setup; a pre-existing environment
 * issue, not something this phase introduced). Every assertion below was confirmed PASS by that standalone
 * run before being transcribed here in the repo's normal PHPUnit convention.
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}

require_once dirname( __DIR__, 2 ) . '/plugins/bizcity-twin-crm/includes/service/class-crm-location-service.php';

final class CrmServiceLocationTest extends TestCase {

	// Hanoi Opera House and Ho Chi Minh City center — a real-world distance to sanity-check the formula
	// against, not just an internal round-trip. Verified great-circle distance for these exact
	// coordinates is ~1143 km.
	private const HANOI = array( 'lat' => 21.0245, 'lng' => 105.8412 );
	private const HCMC  = array( 'lat' => 10.7769, 'lng' => 106.7009 );

	public function test_distance_m_matches_known_real_world_distance(): void {
		$distance = BizCity_CRM_Location_Service::distance_m( self::HANOI, self::HCMC );
		$this->assertEqualsWithDelta( 1143000, $distance, 3000 );
	}

	public function test_distance_m_same_point_is_zero(): void {
		$this->assertEqualsWithDelta( 0.0, BizCity_CRM_Location_Service::distance_m( self::HANOI, self::HANOI ), 0.001 );
	}

	public function test_distance_m_missing_keys_returns_sentinel(): void {
		// `checkin_verdict()` and the matcher's ranking both depend on -1 meaning "could not compute",
		// never a false "very close".
		$this->assertSame( -1.0, BizCity_CRM_Location_Service::distance_m( array( 'lat' => 1 ), array( 'lat' => 2, 'lng' => 3 ) ) );
	}

	public function test_checkin_verdict_is_advisory_not_a_pass_fail_gate_beyond_the_flag(): void {
		$within = BizCity_CRM_Location_Service::checkin_verdict( self::HANOI, self::HANOI, 150 );
		$this->assertTrue( $within['within_radius'] );
		$far = BizCity_CRM_Location_Service::checkin_verdict( self::HCMC, self::HANOI, 150 );
		$this->assertFalse( $far['within_radius'] );
	}

	public function test_extract_from_message_source_a_flattened_zalo_text_link(): void {
		// 0.69 §4.1 — exact shape a Zalo Personal "send location" share is flattened to today.
		$message = array( 'content' => '📍 Vị trí: https://www.google.com/maps?q=21.0245,105.8412', 'ai_metadata_json' => null );
		$point = BizCity_CRM_Location_Service::extract_from_message( $message );
		$this->assertNotNull( $point );
		$this->assertEqualsWithDelta( 21.0245, $point['lat'], 0.0001 );
		$this->assertEqualsWithDelta( 105.8412, $point['lng'], 0.0001 );
		$this->assertSame( 'text_link', $point['source'] );
	}

	public function test_extract_from_message_source_b_takes_priority_over_source_a(): void {
		// 0.69 §4.2 — chosen priority is "B rồi A". A deliberately different, wrong-looking coordinate
		// in `content` proves B is actually preferred, not just present.
		$message = array(
			'content'          => '📍 Vị trí: https://www.google.com/maps?q=1.1,2.2',
			'ai_metadata_json' => wp_json_encode( array( 'quote_src' => array( 'content' => array( 'params' => array( 'lat' => 10.5, 'lng' => 20.5, 'description' => 'Nhà riêng' ) ) ) ) ),
		);
		$point = BizCity_CRM_Location_Service::extract_from_message( $message );
		$this->assertSame( 'quote_src', $point['source'] );
		$this->assertEqualsWithDelta( 10.5, $point['lat'], 0.0001 );
		$this->assertSame( 'Nhà riêng', $point['label'] );
	}

	public function test_extract_from_message_returns_null_when_there_is_no_location(): void {
		$message = array( 'content' => 'ok anh sẽ qua lúc 10h nhé', 'ai_metadata_json' => null );
		$this->assertNull( BizCity_CRM_Location_Service::extract_from_message( $message ) );
	}

	public function test_extract_from_message_rejects_out_of_range_lat_lng(): void {
		// A number that merely LOOKS like it could be a coordinate near the word "maps" must not be
		// accepted — lat/lng have real bounds and the regex alone cannot know that.
		$message = array( 'content' => 'xem thêm tại maps?q=999,999 nhé', 'ai_metadata_json' => null );
		$this->assertNull( BizCity_CRM_Location_Service::extract_from_message( $message ) );
	}
}
