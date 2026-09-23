<?php
/**
 * Bot Studio — Vietnamese free-text date normaliser (PHASE-0.60D S1.7 / Q-D1).
 *
 * Q-D1 answered 2026-09-23: the repo has no shared d/m/Y parser
 * (grep for parse_vn_date|normalize_vn_date|parse_dob → 0 hits; the only
 * date helper, class-scheduler-tools.php::normalize_datetime_value(), is
 * private and ISO-oriented). This is the one home for customer-typed birth
 * dates so the bot, the CRM drawer and the enrichment path never diverge.
 *
 * Rules (doc 0.60D §2.6):
 *   - day/month/year (Vietnamese) first; "03/12/1990" is 3 December.
 *   - ambiguous (both parts ≤ 12 and no textual hint) → status=ambiguous, ask to confirm.
 *   - two-digit year → status=ambiguous (never guess the century).
 *   - impossible date (31/02) → status=invalid.
 *   - future or before 1900 → status=invalid.
 *   - optional time: "7h", "7h sáng", "07:30", "19:00", "7 giờ tối".
 *
 * Pure function — no WordPress dependency, PHP 7.4 syntax only.
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since PHASE-0.60D (2026-09-23)
 */

// [2026-09-23 03:10 PM Claude Fable 5.1] PHASE-0.60D S1.7 — Q-D1 closed: no existing helper, this is the single one.
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_VN_Date {

	const STATUS_OK        = 'ok';
	const STATUS_AMBIGUOUS = 'ambiguous';
	const STATUS_INVALID   = 'invalid';
	const STATUS_NONE      = 'none';

	/**
	 * @param string   $text  Free text the customer typed.
	 * @param int|null $now   Unix timestamp used for the "future" check (test-injectable).
	 * @return array{status:string,date:string,time:string,reason:string}
	 */
	public static function parse( string $text, ?int $now = null ): array {
		$now  = $now ?: time();
		$text = trim( mb_strtolower( $text ) );
		$out  = array( 'status' => self::STATUS_NONE, 'date' => '', 'time' => '', 'reason' => '' );
		if ( $text === '' ) {
			return $out;
		}

		$day = $month = $year = 0;
		$explicit_order = false;

		// 1) "ngày 12 tháng 3 năm 1990" / "12 tháng 3, 1990" — explicit words remove ambiguity.
		if ( preg_match( '/(?:ngày\s*)?(\d{1,2})\s*(?:tháng|thg|\/|-|\.)\s*(\d{1,2})\s*(?:năm|,)?\s*(\d{2,4})/u', $text, $m )
			&& ( strpos( $text, 'tháng' ) !== false || strpos( $text, 'thg' ) !== false ) ) {
			$day = (int) $m[1]; $month = (int) $m[2]; $year_raw = $m[3];
			$explicit_order = true;
		} elseif ( preg_match( '/(?<!\d)(\d{4})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})(?!\d)/', $text, $m ) ) {
			// 2) ISO Y-m-d is unambiguous — checked BEFORE d/m/Y so "1990-03-12" is not read as "90-03-12".
			$year_raw = $m[1]; $month = (int) $m[2]; $day = (int) $m[3];
			$explicit_order = true;
		} elseif ( preg_match( '/(?<!\d)(\d{1,2})\s*[\/\-\.]\s*(\d{1,2})\s*[\/\-\.]\s*(\d{2,4})(?!\d)/', $text, $m ) ) {
			// 3) numeric d/m/Y (Vietnamese order).
			$day = (int) $m[1]; $month = (int) $m[2]; $year_raw = $m[3];
		} else {
			return $out;
		}

		if ( strlen( $year_raw ) === 2 ) {
			$out['status'] = self::STATUS_AMBIGUOUS;
			$out['reason'] = 'two_digit_year';
			return $out;
		}
		$year = (int) $year_raw;

		// d/m vs m/d ambiguity only exists when both ≤ 12 and differ, and no textual hint.
		if ( ! $explicit_order && $day <= 12 && $month <= 12 && $day !== $month ) {
			$out['status'] = self::STATUS_AMBIGUOUS;
			$out['reason'] = 'day_month_order';
			$out['date']   = sprintf( '%04d-%02d-%02d', $year, $month, $day ); // the VN reading, offered for confirmation only.
			return $out;
		}

		if ( ! checkdate( $month, $day, $year ) ) {
			$out['status'] = self::STATUS_INVALID;
			$out['reason'] = 'not_a_date';
			return $out;
		}
		if ( $year < 1900 ) {
			$out['status'] = self::STATUS_INVALID;
			$out['reason'] = 'before_1900';
			return $out;
		}
		$ts = gmmktime( 0, 0, 0, $month, $day, $year );
		if ( $ts > $now ) {
			$out['status'] = self::STATUS_INVALID;
			$out['reason'] = 'in_future';
			return $out;
		}

		$out['status'] = self::STATUS_OK;
		$out['date']   = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		$out['time']   = self::parse_time( $text );
		return $out;
	}

	/** "7h sáng" → 07:00 · "7 giờ tối" → 19:00 · "07:30" → 07:30 · none → ''. */
	public static function parse_time( string $text ): string {
		$text = mb_strtolower( $text );
		if ( ! preg_match( '/(?:lúc\s*|khoảng\s*)?(\d{1,2})\s*(?:h|giờ|:)\s*(\d{2})?\s*(sáng|trưa|chiều|tối|đêm|am|pm)?/u', $text, $m ) ) {
			return '';
		}
		$hour = (int) $m[1];
		$min  = isset( $m[2] ) && $m[2] !== '' ? (int) $m[2] : 0;
		$part = isset( $m[3] ) ? $m[3] : '';
		if ( $hour > 23 || $min > 59 ) {
			return '';
		}
		if ( in_array( $part, array( 'chiều', 'tối', 'đêm', 'pm' ), true ) && $hour < 12 ) {
			$hour += 12;
		}
		if ( 'trưa' === $part && $hour < 11 ) {
			$hour += 12;
		}
		// Reject the date's own numbers being misread as a time ("12/3/1990" has no h/giờ/: after the first number).
		return sprintf( '%02d:%02d', $hour, $min );
	}

	/** Display helper: 1990-03-12 → 12/03/1990. Month/day only when year is unknown. */
	public static function format_vn( string $iso ): string {
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m ) ) {
			return $m[3] . '/' . $m[2] . '/' . $m[1];
		}
		if ( preg_match( '/^(\d{2})-(\d{2})$/', $iso, $m ) ) {
			return $m[2] . '/' . $m[1];
		}
		return $iso;
	}
}
