<?php
/**
 * BizCity Scheduler — Cron (Reminder Scanner)
 *
 * Runs every 5 minutes via WP-Cron.
 * Scans for events where reminder time has arrived → fires notification hooks.
 *
 * Extension point:
 *   - bizcity_scheduler_reminder_fire → Channel Gateway sends push / email / admin notice
 *   - bizcity_scheduler_morning_plan  → AI generates daily plan at configured hour
 *
 * @package  BizCity_Scheduler
 * @since    2026-04-01
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Scheduler_Cron {

	private static $instance = null;

	const REMINDER_HOOK  = 'bizcity_scheduler_reminder_scan';
	const INTERVAL_NAME  = 'bizcity_5min';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		add_filter( 'cron_schedules', [ $this, 'add_interval' ] );
		add_action( self::REMINDER_HOOK, [ $this, 'scan_reminders' ] );
		add_action( 'init', [ $this, 'schedule' ] );
	}

	/**
	 * Register 5-minute interval.
	 */
	public function add_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::INTERVAL_NAME ] ) ) {
			$schedules[ self::INTERVAL_NAME ] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every 5 Minutes (Scheduler)',
			];
		}
		return $schedules;
	}

	/**
	 * Ensure cron is scheduled.
	 */
	public function schedule(): void {
		if ( ! wp_next_scheduled( self::REMINDER_HOOK ) ) {
			wp_schedule_event( time(), self::INTERVAL_NAME, self::REMINDER_HOOK );
		}
	}

	/**
	 * Unschedule on deactivation.
	 */
	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::REMINDER_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::REMINDER_HOOK );
		}
	}

	/**
	 * Scan for pending reminders and fire notification hooks.
	 *
	 * Each reminder fires:
	 *   do_action( 'bizcity_scheduler_reminder_fire', $event )
	 *
	 * Consumers can be:
	 *   - Channel Gateway → send Zalo/Messenger/Webchat push
	 *   - Email module    → send email reminder
	 *   - Admin notice    → WordPress admin notification
	 *   - Automation      → trigger workflow on reminder
	 */
	public function scan_reminders(): void {
		$mgr     = BizCity_Scheduler_Manager::instance();
		$pending = $mgr->claim_due_reminders();

		if ( empty( $pending ) ) {
			return;
		}

		foreach ( $pending as $event ) {
			try {
				/**
				 * Fire reminder notification.
				 *
				 * @param array $event  Full event row from DB.
				 */
				do_action( 'bizcity_scheduler_reminder_fire', $event );

				// Mark as sent to prevent duplicate firing
				$mgr->mark_reminder_sent( (int) $event['id'] );
			} catch ( \Throwable $e ) {
				$mgr->release_reminder_claim( (int) $event['id'] );

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( '[Scheduler] Reminder failed: ' . $e->getMessage() );
				}
				continue;
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf(
					'[Scheduler] Reminder fired: #%d "%s" (starts %s)',
					$event['id'],
					$event['title'],
					$event['start_at']
				) );
			}
		}
	}
}
