<?php
/**
 * BizCity CRM — SLA clock (PHASE-0.63A WP-3.1/3.2). **LANE A OWNS THIS FILE.**
 *
 * Signature stub created by lane S (master §4 S-3). Lane E also depends on this class (WP-9.3 replaces
 * the non-converging working-hours walk of the conversation SLA with it), so lane A ships this file
 * first and the rest of WP-3 after.
 *
 * Two rules that are not negotiable when this gets implemented:
 *  1. The deadline is computed ONCE, at creation, and stored absolute. The 60s tick never walks a
 *     calendar — that is exactly what forced the old SLA tick from 1 minute down to 3
 *     (`class-working-hours.php:83-106`).
 *  2. The calendar walk iterates to convergence (max 30 rounds, 30-day ceiling), because adding
 *     working hours can push the deadline past the end of a window and require another pass.
 *
 * @package BizCity_Twin_CRM
 * @since 2026-09-21 (PHASE-0.63A WP-3, stub)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Pipeline_SLA_Clock', false ) ) {
	return;
}

final class BizCity_CRM_Pipeline_SLA_Clock {

	const MAX_ROUNDS   = 30;
	const MAX_SPAN_DAYS = 30;

	/**
	 * Absolute deadline for an anchor plus a (possibly negative) offset, walked through a calendar.
	 *
	 * @param int    $anchor_ts Unix timestamp of the anchor moment.
	 * @param string $offset    `+15m`, `+2h`, `-40m` …
	 * @param array  $calendar  Named calendar windows, or empty for 24x7.
	 * @return int|WP_Error Unix timestamp.
	 */
	public static function due_from( int $anchor_ts, string $offset, array $calendar = array() ) {
		// [2026-09-21 PHASE-0.63A] Compute and persist an absolute deadline; the runner must not walk calendars.
		$seconds = self::parse_offset( $offset );
		if ( is_wp_error( $seconds ) ) {
			return $seconds;
		}
		if ( 0 === $seconds || empty( $calendar ) ) {
			return $anchor_ts + $seconds;
		}

		$calendar = self::normalise_calendar( $calendar );
		if ( is_wp_error( $calendar ) ) {
			return $calendar;
		}

		$direction = $seconds < 0 ? -1 : 1;
		$remaining = abs( $seconds );
		$current   = $anchor_ts;
		$iterations = 0;
		$limit      = self::MAX_ROUNDS * 100;

		while ( $remaining > 0 && $iterations < $limit ) {
			$interval = $direction > 0
				? self::next_work_interval( $current, $calendar )
				: self::previous_work_interval( $current, $calendar );
			if ( is_wp_error( $interval ) ) {
				return $interval;
			}

			$cursor = $direction > 0
				? max( $current, $interval['start'] )
				: min( $current, $interval['end'] );
			$available = $direction > 0
				? ( $interval['end'] - $cursor )
				: ( $cursor - $interval['start'] );
			if ( $available <= 0 ) {
				return new WP_Error( 'sla_clock_no_progress', 'Lịch SLA không tạo được tiến triển.' );
			}
			$take    = min( $remaining, $available );
			$current = $cursor + ( $direction * $take );
			$remaining -= $take;
			$iterations++;
		}

		if ( $remaining > 0 ) {
			return new WP_Error( 'sla_clock_span_exceeded', 'Khoảng SLA vượt giới hạn 30 ngày hoặc lịch không có giờ mở.' );
		}
		if ( abs( $current - $anchor_ts ) > self::MAX_SPAN_DAYS * 86400 ) {
			return new WP_Error( 'sla_clock_span_exceeded', 'Khoảng SLA vượt giới hạn 30 ngày.' );
		}
		return $current;
	}

	/** Parse `+90m` / `-2h` / `+3d` into seconds. @return int|WP_Error */
	public static function parse_offset( string $offset ) {
		if ( ! preg_match( '/^([+-]?)([0-9]{1,5})(m|h|d|w)$/', trim( $offset ), $matches ) ) {
			return new WP_Error( 'invalid_sla_offset', 'Độ lệch SLA phải có dạng +15m, +2h, -40m hoặc +1d.' );
		}
		$units = array( 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800 );
		$sign  = '-' === $matches[1] ? -1 : 1;
		return $sign * (int) $matches[2] * $units[ $matches[3] ];
	}

	/** Resolve an `at` rung (`-20%`, `0`, `+20m`) against a deadline span. @return int|WP_Error */
	public static function rung_at( int $anchor_ts, int $due_ts, string $at ) {
		$at = trim( $at );
		if ( '0' === $at ) {
			return $due_ts;
		}
		if ( preg_match( '/^([+-])([0-9]{1,3})%$/', $at, $matches ) ) {
			$delta = (int) round( abs( $due_ts - $anchor_ts ) * ( (int) $matches[2] / 100 ) );
			return ( '+' === $matches[1] ) ? $due_ts + $delta : $due_ts - $delta;
		}
		$seconds = self::parse_offset( $at );
		return is_wp_error( $seconds ) ? $seconds : $due_ts + $seconds;
	}

	/** Working seconds between two moments inside a named calendar. @return int|WP_Error */
	public static function elapsed_within( int $from_ts, int $to_ts, array $calendar = array() ) {
		if ( $to_ts <= $from_ts ) {
			return 0;
		}
		if ( empty( $calendar ) ) {
			return $to_ts - $from_ts;
		}
		$calendar = self::normalise_calendar( $calendar );
		if ( is_wp_error( $calendar ) ) {
			return $calendar;
		}

		$tz       = self::timezone();
		$from_day = ( new DateTimeImmutable( '@' . $from_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '-1 day' );
		$to_day   = ( new DateTimeImmutable( '@' . $to_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '+1 day' );
		$total    = 0;
		for ( $day = $from_day; $day <= $to_day; $day = $day->modify( '+1 day' ) ) {
			foreach ( self::intervals_for_day( $day, $calendar ) as $interval ) {
				$total += max( 0, min( $to_ts, $interval['end'] ) - max( $from_ts, $interval['start'] ) );
			}
		}
		return $total;
	}

	/** @return array|WP_Error */
	private static function normalise_calendar( array $calendar ) {
		$out = array();
		foreach ( $calendar as $index => $window ) {
			if ( ! is_array( $window ) || ! isset( $window['dow'], $window['from'], $window['to'] ) ) {
				return new WP_Error( 'invalid_sla_calendar', 'Lịch SLA có một cửa sổ không hợp lệ.' );
			}
			$dows = is_array( $window['dow'] ) ? $window['dow'] : array( $window['dow'] );
			if ( ! preg_match( '/^[0-2][0-9]:[0-5][0-9](?::[0-5][0-9])?$/', (string) $window['from'] )
				|| ! preg_match( '/^[0-2][0-9]:[0-5][0-9](?::[0-5][0-9])?$/', (string) $window['to'] ) ) {
				return new WP_Error( 'invalid_sla_calendar', 'Lịch SLA có giờ mở hoặc đóng không hợp lệ.' );
			}
			foreach ( $dows as $dow ) {
				if ( filter_var( $dow, FILTER_VALIDATE_INT ) === false || (int) $dow < 0 || (int) $dow > 6 ) {
					return new WP_Error( 'invalid_sla_calendar', 'Lịch SLA có ngày trong tuần không hợp lệ.' );
				}
				$out[] = array( 'dow' => (int) $dow, 'from' => substr( (string) $window['from'], 0, 5 ), 'to' => substr( (string) $window['to'], 0, 5 ) );
			}
		}
		return $out;
	}

	/** @return array<int,array{start:int,end:int}> */
	private static function intervals_for_day( DateTimeImmutable $day, array $calendar ): array {
		$intervals = array();
		foreach ( $calendar as $window ) {
			if ( (int) $day->format( 'w' ) !== $window['dow'] ) {
				continue;
			}
			$start = self::at_local_time( $day, $window['from'] );
			$end   = self::at_local_time( $day, $window['to'] );
			if ( $end <= $start ) {
				$end = self::at_local_time( $day->modify( '+1 day' ), $window['to'] );
			}
			if ( $end > $start ) {
				$intervals[] = array( 'start' => $start, 'end' => $end );
			}
		}
		return $intervals;
	}

	/** @return array|WP_Error */
	private static function next_work_interval( int $ts, array $calendar ) {
		$tz   = self::timezone();
		$base = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '-1 day' );
		$best = null;
		for ( $i = 0; $i <= self::MAX_SPAN_DAYS + 1; $i++ ) {
			foreach ( self::intervals_for_day( $base->modify( '+' . $i . ' day' ), $calendar ) as $interval ) {
				if ( $interval['end'] <= $ts ) { continue; }
				$interval['start'] = max( $interval['start'], $ts );
				if ( null === $best || $interval['start'] < $best['start'] ) { $best = $interval; }
			}
		}
		return null === $best ? new WP_Error( 'sla_clock_no_window', 'Lịch SLA không có cửa sổ mở trong 30 ngày.' ) : $best;
	}

	/** @return array|WP_Error */
	private static function previous_work_interval( int $ts, array $calendar ) {
		$tz   = self::timezone();
		$base = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 )->modify( '-1 day' );
		$best = null;
		for ( $i = 0; $i <= self::MAX_SPAN_DAYS + 1; $i++ ) {
			foreach ( self::intervals_for_day( $base->modify( '+' . $i . ' day' ), $calendar ) as $interval ) {
				if ( $interval['start'] >= $ts ) { continue; }
				$interval['end'] = min( $interval['end'], $ts );
				if ( null === $best || $interval['end'] > $best['end'] ) { $best = $interval; }
			}
		}
		return null === $best ? new WP_Error( 'sla_clock_no_window', 'Lịch SLA không có cửa sổ mở trong 30 ngày.' ) : $best;
	}

	private static function at_local_time( DateTimeImmutable $day, string $time ): int {
		$parts = array_map( 'intval', explode( ':', $time ) );
		return $day->setTime( $parts[0], $parts[1], $parts[2] ?? 0 )->getTimestamp();
	}

	private static function timezone(): DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
}
