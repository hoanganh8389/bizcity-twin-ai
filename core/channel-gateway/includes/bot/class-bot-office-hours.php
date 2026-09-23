<?php
/**
 * Bot Studio — office hours helper (PHASE-0.60A W3).
 *
 * Pure function: office_hours_json + "now" → is staff on duty right now?
 * Polarity (doc E10): staff ON DUTY (in hours) = bot SILENT.
 * Staff OFF DUTY (out of hours) = bot replies.
 *
 * office_hours shape:
 *   {
 *     "enabled": bool,                 // false = no restriction, bot can reply any time
 *     "timezone": "Asia/Ho_Chi_Minh",  // IANA name; falls back to site timezone
 *     "days": { "mon": [{"start":"08:00","end":"17:30"}], "tue": [...], ... },
 *     "pause_on_manual_reply": bool,
 *     "require_mention_in_group": bool
 *   }
 *
 * @package BizCity_Twin_AI
 * @subpackage Channel_Gateway\Bot
 * @since 1.0.0 (PHASE-0.60A W3)
 */

// [2026-09-23 Claude Sonnet 5] PHASE-0.60A W3
defined( 'ABSPATH' ) || exit;

final class BizCity_Bot_Office_Hours {

	const DAY_KEYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	public static function defaults(): array {
		return array(
			'enabled'                  => false,
			'timezone'                 => '',
			'days'                     => array(),
			'pause_on_manual_reply'    => true,
			'require_mention_in_group' => true,
		);
	}

	/**
	 * @param array $office_hours Decoded office_hours_json (may be partial/empty).
	 * @param int   $now_ts       Unix timestamp to evaluate against (defaults to now); test-injectable.
	 */
	public static function is_staff_on_duty( array $office_hours, int $now_ts = 0 ): bool {
		$office_hours = array_merge( self::defaults(), $office_hours );
		if ( empty( $office_hours['enabled'] ) ) {
			return false; // no restriction configured → staff never blocks the bot
		}

		$tz_name = (string) $office_hours['timezone'];
		if ( $tz_name === '' ) {
			$tz_name = function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC';
		}
		try {
			$tz = new \DateTimeZone( $tz_name );
		} catch ( \Throwable $e ) {
			$tz = new \DateTimeZone( 'UTC' );
		}

		$now  = $now_ts > 0 ? ( new \DateTime( '@' . $now_ts ) )->setTimezone( $tz ) : new \DateTime( 'now', $tz );
		$day  = self::DAY_KEYS[ (int) $now->format( 'w' ) ];
		$mins = ( (int) $now->format( 'H' ) ) * 60 + (int) $now->format( 'i' );

		$ranges = isset( $office_hours['days'][ $day ] ) && is_array( $office_hours['days'][ $day ] )
			? $office_hours['days'][ $day ]
			: array();

		foreach ( $ranges as $range ) {
			if ( ! is_array( $range ) || ! isset( $range['start'], $range['end'] ) ) {
				continue;
			}
			$start = self::minutes_from_hhmm( (string) $range['start'] );
			$end   = self::minutes_from_hhmm( (string) $range['end'] );
			if ( $start === null || $end === null ) {
				continue;
			}
			if ( $mins >= $start && $mins < $end ) {
				return true;
			}
		}
		return false;
	}

	private static function minutes_from_hhmm( string $hhmm ): ?int {
		if ( ! preg_match( '/^([01]?\d|2[0-3]):([0-5]\d)$/', $hhmm, $m ) ) {
			return null;
		}
		return ( (int) $m[1] ) * 60 + (int) $m[2];
	}
}
