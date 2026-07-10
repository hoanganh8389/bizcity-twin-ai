<?php
/**
 * BizCity CRM — Daily Rollup (PHASE 0.35 M5.W2).
 *
 * Cron-driven projector: each day at 03:00 site TZ, computes a snapshot of all
 * 8 KPI metrics for "yesterday" and emits a `crm_daily_rollup` event into the
 * canonical event stream. Acts as the projection cache for past-day reports
 * (R-EVT-3 / R-PAR-3) — today's window remains live-aggregated.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Daily_Rollup {

	const CRON_HOOK = 'bizcity_crm_daily_rollup';

	public static function register(): void {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ), 10, 0 );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( self::next_3am_ts(), 'daily', self::CRON_HOOK );
		}
	}

	private static function next_3am_ts(): int {
		try {
			$tz   = wp_timezone();
			$dt   = new DateTimeImmutable( 'tomorrow 03:00', $tz );
			return $dt->getTimestamp();
		} catch ( \Throwable $e ) {
			return time() + 3600;
		}
	}

	/**
	 * Run the rollup for "yesterday" (or a forced date via $force_day_ts).
	 *
	 * @return array { day, from, to, metrics, event_uuid }
	 */
	public static function run( ?int $force_day_ts = null ): array {
		try {
			$tz = wp_timezone();
			if ( $force_day_ts !== null ) {
				$start = ( new DateTimeImmutable( '@' . $force_day_ts ) )->setTimezone( $tz )->setTime( 0, 0, 0 );
			} else {
				$start = ( new DateTimeImmutable( 'yesterday 00:00', $tz ) );
			}
		} catch ( \Throwable $e ) {
			$start = new DateTimeImmutable( 'yesterday 00:00' );
		}
		$from = $start->getTimestamp();
		$to   = $from + 86400;
		$snap = BizCity_CRM_Report_Builder::snapshot( $from, $to, 0 );
		$payload = array(
			'day'     => $start->format( 'Y-m-d' ),
			'from'    => $from,
			'to'      => $to,
			'metrics' => $snap['metrics'],
		);
		$uuid = BizCity_CRM_Event_Emitter::emit( BizCity_CRM_Report_Builder::ROLLUP_EVENT_TYPE, $payload );
		$payload['event_uuid'] = $uuid;
		return $payload;
	}
}
