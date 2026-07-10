<?php
/**
 * BizCity CRM — Working Hours helper (PHASE 0.35 M4.W1).
 *
 * Encapsulates "is this inbox open right now?" logic. Falls back to
 * Repository::default_working_hours_grid() when no rows exist for the inbox
 * so brand-new installs don't break SLA evaluation.
 *
 * Time math is intentionally done in the *site* timezone (wp_timezone()) —
 * SLA dashboards operate against business-local hours, not server UTC.
 *
 * @package BizCity_Twin_CRM
 * @since   PHASE 0.35 M4.W1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Working_Hours {

	/** @var array<int,array<int,array>> per-inbox dow=>row cache (process-local). */
	private static $grid_cache = array();

	/**
	 * Is the inbox considered "open" at the given unix timestamp?
	 *
	 * @param int               $inbox_id
	 * @param int|null          $ts        Unix epoch (defaults to now).
	 * @param DateTimeZone|null $tz        Defaults to wp_timezone().
	 * @return bool
	 */
	public static function is_open( int $inbox_id, ?int $ts = null, ?DateTimeZone $tz = null ): bool {
		$check = self::check( $inbox_id, $ts, $tz );
		return (bool) $check['open'];
	}

	/**
	 * Detailed open/closed answer (used by REST + diag).
	 *
	 * @return array{open:bool, reason:string, day_of_week:int, local_time:string, schedule:?array}
	 */
	public static function check( int $inbox_id, ?int $ts = null, ?DateTimeZone $tz = null ): array {
		$ts = $ts ?? time();
		$tz = $tz ?? wp_timezone();
		try {
			$dt = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
		} catch ( \Throwable $e ) {
			return array( 'open' => false, 'reason' => 'tz_error:' . $e->getMessage(), 'day_of_week' => -1, 'local_time' => '', 'schedule' => null );
		}
		$dow        = (int) $dt->format( 'w' ); // 0=Sun..6=Sat
		$local_time = $dt->format( 'H:i:s' );

		$row = self::lookup_row( $inbox_id, $dow );
		if ( ! $row ) {
			return array( 'open' => false, 'reason' => 'no_schedule', 'day_of_week' => $dow, 'local_time' => $local_time, 'schedule' => null );
		}
		if ( empty( $row['is_open'] ) ) {
			return array( 'open' => false, 'reason' => 'closed_day', 'day_of_week' => $dow, 'local_time' => $local_time, 'schedule' => $row );
		}
		// Same-day window comparison (string compare on H:i:s is safe & cheap).
		$open_t  = (string) $row['open_time'];
		$close_t = (string) $row['close_time'];
		// Support overnight crossover (e.g. 22:00 → 02:00 next day) when close < open.
		$within = ( $open_t <= $close_t )
			? ( $local_time >= $open_t && $local_time <  $close_t )
			: ( $local_time >= $open_t || $local_time <  $close_t );
		return array(
			'open'        => (bool) $within,
			'reason'      => $within ? 'in_window' : 'outside_window',
			'day_of_week' => $dow,
			'local_time'  => $local_time,
			'schedule'    => $row,
		);
	}

	/**
	 * Sum of "closed" seconds between two timestamps (used by SLA evaluator
	 * when policy is `only_during_business_hours = true`). Walks each minute
	 * boundary — accurate enough for SLA purposes (minute-grain) and avoids
	 * the cross-DST corner cases of analytical interval math.
	 *
	 * Intentionally capped at 14 days to prevent runaway loops on stale rows.
	 */
	public static function closed_seconds_between( int $inbox_id, int $start_ts, int $end_ts ): int {
		if ( $end_ts <= $start_ts ) { return 0; }
		$max = 14 * 86400;
		if ( $end_ts - $start_ts > $max ) { $end_ts = $start_ts + $max; }
		$tz      = wp_timezone();
		$grid    = self::load_grid( $inbox_id );
		if ( ! $grid ) { return 0; }
		$closed  = 0;
		// Walk in 60-second steps. For a 1-day interval this is 1440 iterations — cheap.
		for ( $t = $start_ts; $t < $end_ts; $t += 60 ) {
			$dt   = ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz );
			$dow  = (int) $dt->format( 'w' );
			$row  = $grid[ $dow ] ?? null;
			$open = false;
			if ( $row && ! empty( $row['is_open'] ) ) {
				$lt    = $dt->format( 'H:i:s' );
				$o     = (string) $row['open_time'];
				$c     = (string) $row['close_time'];
				$open  = ( $o <= $c ) ? ( $lt >= $o && $lt < $c ) : ( $lt >= $o || $lt < $c );
			}
			if ( ! $open ) { $closed += 60; }
		}
		return $closed;
	}

	private static function lookup_row( int $inbox_id, int $dow ): ?array {
		$grid = self::load_grid( $inbox_id );
		return $grid[ $dow ] ?? null;
	}

	/** @return array<int,array> Map dow=>row (with defaults filled in for missing days). */
	private static function load_grid( int $inbox_id ): array {
		if ( isset( self::$grid_cache[ $inbox_id ] ) ) { return self::$grid_cache[ $inbox_id ]; }
		$rows   = BizCity_CRM_Repository::list_working_hours( $inbox_id );
		$by_dow = array();
		foreach ( $rows as $r ) {
			$by_dow[ (int) $r['day_of_week'] ] = $r;
		}
		// Fill missing days from defaults so DST-anniversary or partial-edits don't blank a slot.
		if ( count( $by_dow ) < 7 ) {
			foreach ( BizCity_CRM_Repository::default_working_hours_grid() as $d ) {
				$dow = (int) $d['day_of_week'];
				if ( ! isset( $by_dow[ $dow ] ) ) { $by_dow[ $dow ] = $d; }
			}
		}
		ksort( $by_dow );
		self::$grid_cache[ $inbox_id ] = $by_dow;
		return $by_dow;
	}

	/** Drop the in-memory grid cache (used by REST PUT after edits). */
	public static function invalidate_cache( int $inbox_id ): void {
		unset( self::$grid_cache[ $inbox_id ] );
	}
}
