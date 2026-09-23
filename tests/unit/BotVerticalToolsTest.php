<?php
/**
 * PHASE-0.60D S2.1–S2.5, E-D3/E-D4/E-D6 — vertical delegation + disclaimer survival.
 * // [2026-09-23 04:40 PM Claude Fable 5.1] PHASE-0.60D-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-config-repo.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-tool-registry.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vertical-tools.php';

use PHPUnit\Framework\TestCase;

final class BotVerticalToolsTest extends TestCase {

	public function test_allowed_verticals_accepts_json_or_array_and_normalises(): void {
		$this->assertSame( array( 'med', 'quick' ), BizCity_Bot_Vertical_Tools::allowed_verticals( (object) array( 'allowed_verticals' => '["med","QUICK","med",""]' ) ) );
		$this->assertSame( array(), BizCity_Bot_Vertical_Tools::allowed_verticals( (object) array( 'allowed_verticals' => 'not json' ) ) );
		$this->assertSame( array(), BizCity_Bot_Vertical_Tools::allowed_verticals( (object) array() ) );
	}

	public function test_med_run_returns_frame_with_disclaimer_obligation(): void {
		$res = BizCity_Bot_Vertical_Tools::run( 'med', array( 'query' => 'đau đầu' ), array() );
		$this->assertTrue( $res['ok'] );
		$this->assertStringContainsString( 'VERTICAL: MED', $res['content'] );
		$this->assertNotSame( '', $res['disclaimer'] );
		$this->assertStringContainsString( $res['disclaimer'], $res['content'] );
	}

	public function test_woo_bizops_is_refused_even_when_called_directly(): void {
		// E-D4 — a stranger asking for revenue never gets a frame that could reach real data.
		$res = BizCity_Bot_Vertical_Tools::run( 'woo_bizops', array( 'query' => 'doanh thu tháng này' ), array() );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'vertical_not_allowed', $res['error'] );
		$this->assertSame( '', $res['content'] );
	}

	public function test_guest_blocked_vertical_is_refused(): void {
		$res = BizCity_Bot_Vertical_Tools::run( 'astro', array(), array() );
		$this->assertFalse( $res['ok'] );
	}

	public function test_trim_for_zalo_keeps_disclaimer_intact(): void {
		$disclaimer = BizCity_Bot_Vertical_Tools::disclaimers()['med'];
		$long = str_repeat( 'Nội dung y khoa dài. ', 200 ) . $disclaimer;
		$out  = BizCity_Bot_Vertical_Tools::trim_for_zalo( $long, 500, $disclaimer );
		$this->assertLessThanOrEqual( 500, mb_strlen( $out ) );
		$this->assertStringEndsWith( $disclaimer, $out, 'E-D6 — trimming for Zalo must never cut the disclaimer' );
		$this->assertSame( 1, substr_count( $out, $disclaimer ), 'disclaimer appears exactly once' );
	}

	public function test_trim_for_zalo_without_disclaimer_is_plain_trim(): void {
		$out = BizCity_Bot_Vertical_Tools::trim_for_zalo( str_repeat( 'a', 100 ), 50 );
		$this->assertSame( 50, mb_strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
		$this->assertSame( 'ngắn', BizCity_Bot_Vertical_Tools::trim_for_zalo( 'ngắn', 50 ) );
	}
}
