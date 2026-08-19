<?php
/**
 * Deterministic temporal context resolver for MPR/Goal stages.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\TwinBrain
 * @since 2026-08-16
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinBrain_Temporal_Context_Resolver', false ) ) {
	return;
}

final class BizCity_TwinBrain_Temporal_Context_Resolver {

	/**
	 * @return array<string,mixed>
	 */
	public static function resolve( string $prompt, array $opts = array() ): array {
		// [2026-08-16 Johnny Chu] MPR-V5-TEMPORAL — resolve common relative/date ranges without a provider call.
		$timezone = self::timezone( $opts );
		$now = new DateTimeImmutable( 'now', $timezone );
		$text = strtolower( trim( preg_replace( '/\s+/u', ' ', $prompt ) ) );
		$start = null;
		$end = null;
		$granularity = 'none';
		$reason = 'not_requested';
		$matched = false;

		if ( preg_match( '/\b(hôm nay|hom nay|today)\b/u', $text ) ) {
			$start = $now->setTime( 0, 0, 0 );
			$end = $start->modify( '+1 day' )->modify( '-1 second' );
			$granularity = 'day';
			$reason = 'relative_today';
			$matched = true;
		} elseif ( preg_match( '/\b(ngày mai|ngay mai|tomorrow)\b/u', $text ) ) {
			$start = $now->modify( '+1 day' )->setTime( 0, 0, 0 );
			$end = $start->modify( '+1 day' )->modify( '-1 second' );
			$granularity = 'day';
			$reason = 'relative_tomorrow';
			$matched = true;
		} elseif ( preg_match( '/\b(hôm qua|hom qua|yesterday)\b/u', $text ) ) {
			$start = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
			$end = $start->modify( '+1 day' )->modify( '-1 second' );
			$granularity = 'day';
			$reason = 'relative_yesterday';
			$matched = true;
		} elseif ( preg_match( '/\b(tuần này|tuan nay|this week)\b/u', $text ) ) {
			$start = $now->modify( 'monday this week' )->setTime( 0, 0, 0 );
			$end = $start->modify( '+7 days' )->modify( '-1 second' );
			$granularity = 'week';
			$reason = 'relative_current_week';
			$matched = true;
		} elseif ( preg_match( '/\b(tháng này|thang nay|this month)\b/u', $text ) ) {
			$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			$end = $start->modify( 'last day of this month' )->setTime( 23, 59, 59 );
			$granularity = 'month';
			$reason = 'relative_current_month';
			$matched = true;
		} elseif ( preg_match( '/\b(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})\b/', $text, $match ) ) {
			$date = DateTimeImmutable::createFromFormat( '!d/m/Y', $match[1] . '/' . $match[2] . '/' . $match[3], $timezone );
			if ( $date instanceof DateTimeImmutable ) {
				$start = $date->setTime( 0, 0, 0 );
				$end = $start->modify( '+1 day' )->modify( '-1 second' );
				$granularity = 'day';
				$reason = 'explicit_date';
				$matched = true;
			}
		}

		return array(
			'resolved'    => true,
			'required'    => false,
			'timezone'    => $timezone->getName(),
			'range_start' => $start instanceof DateTimeImmutable ? $start->format( DATE_ATOM ) : '',
			'range_end'   => $end instanceof DateTimeImmutable ? $end->format( DATE_ATOM ) : '',
			'granularity' => $granularity,
			'source'      => $matched ? 'prompt_deterministic' : 'none',
			'confidence'  => $matched ? 1.0 : 1.0,
			'reason_code' => $reason,
		);
	}

	private static function timezone( array $opts ): DateTimeZone {
		$name = trim( (string) ( $opts['timezone'] ?? '' ) );
		if ( $name === '' && function_exists( 'wp_timezone_string' ) ) {
			$name = (string) wp_timezone_string();
		}
		if ( $name === '' ) {
			$name = date_default_timezone_get();
		}
		try {
			return new DateTimeZone( $name );
		} catch ( Exception $e ) {
			return new DateTimeZone( 'UTC' );
		}
	}
}
