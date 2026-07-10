<?php
/**
 * BizCity Scheduler — HIL Timeout Cron.
 *
 * Sweep mỗi phút: bất kỳ event `reminder_personal` ở `status='draft'` quá
 * 5 phút kể từ `created_at` → flip sang `cancelled` + gửi tin timeout về
 * inbound channel.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_HIL_Cron' ) ) {
	return;
}

final class BizCity_Scheduler_HIL_Cron {

	const CRON_HOOK     = 'bizcity_scheduler_hil_timeout';
	const CRON_INTERVAL = 'every_minute';
	const TIMEOUT_SEC   = 300; // 5 minutes.

	/** @var bool */
	private static $booted = false;

	public static function init() {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — HIL cron idempotent boot.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'cron_schedules', array( __CLASS__, 'register_interval' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_sweep' ) );

		// [2026-06-14 Johnny Chu] GAP-4 — register via CronManager when available so
		// HIL sweeps are traced in bizcity_cron_runs + visible in admin page.
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => 'scheduler.hil_timeout',
				'hook'        => self::CRON_HOOK,
				'interval'    => self::CRON_INTERVAL,
				'owner'       => 'core/scheduler',
				'description' => 'Sweep expired HIL draft reminder_personal events every minute.',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 3,
			) );
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Register `every_minute` interval if not present.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public static function register_interval( $schedules ) {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		if ( ! isset( $schedules[ self::CRON_INTERVAL ] ) ) {
			$schedules[ self::CRON_INTERVAL ] = array(
				'interval' => 60,
				'display'  => __( 'Every minute', 'bizcity-twin-ai' ),
			);
		}
		return $schedules;
	}

	/**
	 * Cron callback. Find expired drafts and cancel via HIL Router.
	 *
	 * @return void
	 */
	public static function run_sweep() {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — sweep expired drafts.
		global $wpdb;
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		$tbl    = BizCity_Scheduler_Manager::instance()->get_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TIMEOUT_SEC );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$tbl}
			 WHERE event_type = %s
			   AND status     = %s
			   AND created_at < %s
			 ORDER BY id ASC
			 LIMIT 50",
			'reminder_personal',
			'draft',
			$cutoff
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return;
		}

		// [R-CRON-META] note counters via Cron_Manager when available.
		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! class_exists( 'BizCity_Scheduler_HIL_Router' ) ) {
				break;
			}
			BizCity_Scheduler_HIL_Router::cancel( $row, 'timeout' );
			$count++;
			if ( $cron ) {
				$cron->note_event( 'hil_timeout_cancelled', array(
					'event_id'   => (int) $row['id'],
					'created_at' => (string) ( $row['created_at'] ?? '' ),
				) );
			}
		}
		if ( $cron && $count > 0 ) {
			$cron->note( array( 'counters' => array( 'hil_timeout_cancelled' => $count ) ) );
		}
	}

	/**
	 * Unschedule cron — called from plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}
}
