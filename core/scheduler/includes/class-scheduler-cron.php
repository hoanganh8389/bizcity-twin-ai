<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

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
	 * Ensure cron is scheduled. Prefers core/cron unified manager when
	 * available so the run gets traced into bizcity_cron_runs; falls back
	 * to direct wp_schedule_event for backward-compat on sites that have
	 * not yet activated the manager.
	 */
	public function schedule(): void {
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->register( array(
				'id'          => 'scheduler.reminder',
				'hook'        => self::REMINDER_HOOK,
				'interval'    => self::INTERVAL_NAME,
				'owner'       => 'core/scheduler',
				'description' => 'Scan due reminders & fire bizcity_scheduler_reminder_fire (every 5min).',
				'singleton'   => true,
				'enabled'     => true,
				'retention'   => 7,
			) );
			return;
		}
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
		$mgr = BizCity_Scheduler_Manager::instance();

		// Self-heal: if the table option says ready but the physical table
		// is missing on this shard, attempt to (re)install before bailing.
		if ( ! $mgr->is_ready() ) {
			$mgr->ensure_schema();
		}

		// Soft guard: still missing → bail silently, surface admin notice,
		// avoid spamming the WordPress database error log every 5 minutes.
		if ( ! $mgr->is_ready() ) {
			if ( function_exists( 'set_transient' ) ) {
				set_transient(
					'bizcity_scheduler_table_missing',
					[
						'table'   => $GLOBALS['wpdb']->prefix . BizCity_Scheduler_Manager::TABLE_NAME,
						'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
						'at'      => time(),
					],
					HOUR_IN_SECONDS
				);
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Scheduler] reminder_scan skipped — bizcity_crm_events table missing on current shard.' );
			}
			return;
		}

		$pending = $mgr->claim_due_reminders();

		// R-CRON-META: log scan summary into bizcity_cron_runs.meta.
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( array(
				'scan' => array(
					'claimed'  => count( $pending ),
					'event_ids' => array_map( static function( $e ) { return (int) $e['id']; }, $pending ),
				),
			) );
		}

		if ( empty( $pending ) ) {
			return;
		}

		$counters = array( 'fired' => 0, 'sent' => 0, 'errors' => 0 );

		foreach ( $pending as $event ) {
			try {
				/**
				 * Fire reminder notification.
				 *
				 * @param array $event  Full event row from DB.
				 */
				do_action( 'bizcity_scheduler_reminder_fire', $event );
				$counters['fired']++;

				// Mark as sent to prevent duplicate firing
				$mgr->mark_reminder_sent( (int) $event['id'] );
				$counters['sent']++;
			} catch ( \Throwable $e ) {
				$mgr->release_reminder_claim( (int) $event['id'] );
				$counters['errors']++;

				if ( class_exists( 'BizCity_Cron_Manager' ) ) {
					BizCity_Cron_Manager::instance()->note_event( 'reminder_fire_exception', array(
						'event_id' => (int) $event['id'],
						'error'    => $e->getMessage(),
					) );
				}

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

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			BizCity_Cron_Manager::instance()->note( array( 'scan' => array(
				'fired'  => $counters['fired'],
				'sent'   => $counters['sent'],
				'errors' => $counters['errors'],
			) ) );
		}
	}
}
