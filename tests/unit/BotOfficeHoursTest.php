<?php
/**
 * PHASE-0.60A W3 — BizCity_Bot_Office_Hours polarity tests.
 *
 * E10 polarity: staff ON DUTY (in hours) = bot SILENT. Staff OFF DUTY = bot replies.
 * Fully deterministic — every assertion pins $now_ts explicitly, never real "now".
 *
 * // [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3-test
 */

require_once dirname( __DIR__, 2 ) . '/core/channel-gateway/includes/bot/class-bot-office-hours.php';

use PHPUnit\Framework\TestCase;

final class BotOfficeHoursTest extends TestCase {

	private function office_hours(): array {
		return array(
			'enabled' => true,
			'timezone' => 'UTC',
			'days' => array(
				'mon' => array( array( 'start' => '08:00', 'end' => '17:30' ) ),
			),
		);
	}

	/** Monday 2026-09-21 10:00 UTC — inside the configured window. */
	public function test_in_hours_means_staff_on_duty_bot_silent(): void {
		$ts = gmmktime( 10, 0, 0, 9, 21, 2026 );
		$this->assertTrue( BizCity_Bot_Office_Hours::is_staff_on_duty( $this->office_hours(), $ts ) );
	}

	/** Monday 2026-09-21 20:00 UTC — after the configured window. */
	public function test_out_of_hours_means_staff_off_duty_bot_replies(): void {
		$ts = gmmktime( 20, 0, 0, 9, 21, 2026 );
		$this->assertFalse( BizCity_Bot_Office_Hours::is_staff_on_duty( $this->office_hours(), $ts ) );
	}

	/** Tuesday — no range configured for that day at all. */
	public function test_day_with_no_configured_range_is_off_duty(): void {
		$ts = gmmktime( 10, 0, 0, 9, 22, 2026 ); // Tue
		$this->assertFalse( BizCity_Bot_Office_Hours::is_staff_on_duty( $this->office_hours(), $ts ) );
	}

	/** enabled=false must never block the bot, regardless of time. */
	public function test_disabled_office_hours_never_blocks_bot(): void {
		$oh = $this->office_hours();
		$oh['enabled'] = false;
		$ts = gmmktime( 10, 0, 0, 9, 21, 2026 ); // would be "in hours" if enabled
		$this->assertFalse( BizCity_Bot_Office_Hours::is_staff_on_duty( $oh, $ts ) );
	}

	/** Window boundaries: start is inclusive, end is exclusive. */
	public function test_window_boundaries_start_inclusive_end_exclusive(): void {
		$oh = $this->office_hours();
		$start = gmmktime( 8, 0, 0, 9, 21, 2026 );
		$end   = gmmktime( 17, 30, 0, 9, 21, 2026 );
		$this->assertTrue( BizCity_Bot_Office_Hours::is_staff_on_duty( $oh, $start ), 'start boundary should be on-duty' );
		$this->assertFalse( BizCity_Bot_Office_Hours::is_staff_on_duty( $oh, $end ), 'end boundary should already be off-duty' );
	}

	/** Missing/empty office_hours entirely (character never configured it) — must not block (B1.7 safe default). */
	public function test_empty_office_hours_defaults_to_never_blocking(): void {
		$this->assertFalse( BizCity_Bot_Office_Hours::is_staff_on_duty( array() ) );
	}
}
