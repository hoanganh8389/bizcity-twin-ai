<?php
/**
 * PHASE-0.60D S1.7 / Q-D1 — Vietnamese date normaliser.
 * // [2026-09-23 04:40 PM Claude Fable 5.1] PHASE-0.60D-test
 */

require_once __DIR__ . '/support/bot-studio-stubs.php';
require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-vn-date.php';

use PHPUnit\Framework\TestCase;

final class BotVnDateTest extends TestCase {

	private $now;

	protected function setUp(): void {
		$this->now = gmmktime( 12, 0, 0, 9, 23, 2026 );
	}

	public function test_vietnamese_order_day_first_when_unambiguous(): void {
		$r = BizCity_Bot_VN_Date::parse( '13/03/1990', $this->now );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( '1990-03-13', $r['date'] );
	}

	public function test_three_december_not_march_twelve_when_words_present(): void {
		$r = BizCity_Bot_VN_Date::parse( 'ngày 3 tháng 12 năm 1990', $this->now );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( '1990-12-03', $r['date'] );
	}

	public function test_ambiguous_numeric_asks_for_confirmation_instead_of_guessing(): void {
		$r = BizCity_Bot_VN_Date::parse( '05/06/1990', $this->now );
		$this->assertSame( 'ambiguous', $r['status'] );
		$this->assertSame( 'day_month_order', $r['reason'] );
		$this->assertSame( '1990-06-05', $r['date'], 'the VN reading is offered for confirmation only' );
	}

	public function test_same_day_and_month_is_not_ambiguous(): void {
		$r = BizCity_Bot_VN_Date::parse( '12/12/1990', $this->now );
		$this->assertSame( 'ok', $r['status'] );
	}

	public function test_two_digit_year_is_never_guessed(): void {
		$r = BizCity_Bot_VN_Date::parse( '12/03/90', $this->now );
		$this->assertSame( 'ambiguous', $r['status'] );
		$this->assertSame( 'two_digit_year', $r['reason'] );
	}

	public function test_impossible_date_is_invalid(): void {
		$r = BizCity_Bot_VN_Date::parse( '31/02/1990', $this->now );
		$this->assertSame( 'invalid', $r['status'] );
	}

	public function test_future_and_pre_1900_are_invalid(): void {
		$this->assertSame( 'invalid', BizCity_Bot_VN_Date::parse( '15/10/2030', $this->now )['status'] );
		$this->assertSame( 'invalid', BizCity_Bot_VN_Date::parse( '15/10/1850', $this->now )['status'] );
	}

	public function test_iso_input_accepted(): void {
		$r = BizCity_Bot_VN_Date::parse( '1990-03-12', $this->now );
		$this->assertSame( 'ok', $r['status'] );
		$this->assertSame( '1990-03-12', $r['date'] );
	}

	public function test_time_extraction_vietnamese_day_parts(): void {
		$r = BizCity_Bot_VN_Date::parse( '13/3/1990, khoảng 7h sáng', $this->now );
		$this->assertSame( '07:00', $r['time'] );
		$this->assertSame( '19:30', BizCity_Bot_VN_Date::parse_time( '7 giờ 30 tối' ) );
		$this->assertSame( '', BizCity_Bot_VN_Date::parse_time( 'không nhớ' ) );
	}

	public function test_no_date_returns_none(): void {
		$this->assertSame( 'none', BizCity_Bot_VN_Date::parse( 'mình không muốn nói', $this->now )['status'] );
	}

	public function test_format_vn(): void {
		$this->assertSame( '12/03/1990', BizCity_Bot_VN_Date::format_vn( '1990-03-12' ) );
		$this->assertSame( '12/03', BizCity_Bot_VN_Date::format_vn( '03-12' ) );
	}
}
