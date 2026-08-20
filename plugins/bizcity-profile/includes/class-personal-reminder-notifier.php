<?php
/**
 * BizCity Personal — Reminder Notifier (W7)
 *
 * Hooks into `bizcity_scheduler_reminder_fire` to push Zalo Bot
 * notifications when a task or calendar event reminder fires.
 *
 * Flow:
 *   1. `BizCity_Scheduler_Cron::scan_reminders()` fires `bizcity_scheduler_reminder_fire`
 *      for each due event.
 *   2. This class picks it up at priority 10.
 *   3. For events owned by a linked WP user, fetches Zalo Bot chat_id(s)
 *      via `BizCity_Zalobot_User_Linker::get_links_for_wp_user()`.
 *   4. Sends via `BizCity_Gateway_Sender` (fail-OPEN, no exception bubbles up).
 *   5. Logs R-CRON-META note_event on success/failure.
 *
 * R-ZONE: Zalo Bot is Zone 2. Notifications go outbound — no zone conflict.
 *
 * R-STAMP: every edit stamped [YYYY-MM-DD Johnny Chu] PHASE-HOME W7
 * PHP 7.4 compatible — no union types, no nullsafe, no str_contains, no match.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME W7)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Reminder_Notifier' ) ) { return; }

class BizCity_Personal_Reminder_Notifier {

	/** @var self|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// [2026-06-24 Johnny Chu] PHASE-HOME W7 — hook at priority 10 (after cron marks event)
		add_action( 'bizcity_scheduler_reminder_fire', array( $this, 'on_reminder_fire' ), 10, 1 );
	}

	/**
	 * Called for each due event by the scheduler cron.
	 *
	 * @param array $event  Full row from bizcity_crm_events (ARRAY_A).
	 */
	public function on_reminder_fire( $event ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W7 — bail guards
		if ( ! is_array( $event ) || empty( $event ) ) {
			return;
		}

		$user_id = (int) ( isset( $event['user_id'] ) ? $event['user_id'] : 0 );
		if ( $user_id <= 0 ) {
			return;
		}

		// Require Zalo Bot user linker (plugin may not be active)
		if ( ! class_exists( 'BizCity_Zalobot_User_Linker' ) ) {
			return;
		}
		// Require Gateway Sender
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return;
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME W7 — get all linked Zalo Bot accounts for user
		$links = BizCity_Zalobot_User_Linker::get_links_for_wp_user( $user_id );
		if ( empty( $links ) ) {
			return;
		}

		$message = $this->build_notification( $event );

		foreach ( $links as $link ) {
			// Only send to fully-linked rows
			if ( ( isset( $link['status'] ) ? $link['status'] : '' ) !== 'linked' ) {
				continue;
			}

			$zalo_user_id = (string) ( isset( $link['zalo_user_id'] ) ? $link['zalo_user_id'] : '' );
			$bot_id       = (int)    ( isset( $link['bot_id'] )       ? $link['bot_id']       : 0 );

			if ( $zalo_user_id === '' || $bot_id <= 0 ) {
				continue;
			}

			// [2026-06-24 Johnny Chu] PHASE-HOME W7 — chat_id format: zalobot_{bot_id}_{zalo_user_id}
			$chat_id  = 'zalobot_' . $bot_id . '_' . $zalo_user_id;
			$event_id = (int) ( isset( $event['id'] ) ? $event['id'] : 0 );

			try {
				$result = BizCity_Gateway_Sender::instance()->send( $chat_id, $message );

				if ( class_exists( 'BizCity_Cron_Manager' ) ) {
					$sent = isset( $result['sent'] ) && $result['sent'];
					BizCity_Cron_Manager::instance()->note_event(
						$sent ? 'personal_reminder_sent' : 'personal_reminder_send_failed',
						array(
							'event_id'     => $event_id,
							'user_id'      => $user_id,
							'chat_id'      => $chat_id,
							'bot_id'       => $bot_id,
							'event_type'   => isset( $event['event_type'] ) ? $event['event_type'] : '',
							'error'        => $sent ? '' : ( isset( $result['error'] ) ? $result['error'] : 'unknown' ),
						)
					);
				}
			} catch ( Exception $e ) {
				// [2026-06-24 Johnny Chu] PHASE-HOME W7 — fail-OPEN: log, never bubble up
				if ( class_exists( 'BizCity_Cron_Manager' ) ) {
					BizCity_Cron_Manager::instance()->note_event( 'personal_reminder_exception', array(
						'event_id'        => $event_id,
						'user_id'         => $user_id,
						'chat_id'         => $chat_id,
						'reason'          => 'timeout',
						'error'           => $e->getMessage(),
						'exception_class' => get_class( $e ),
					) );
				}
				error_log( '[bizcity-personal] Reminder notification failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Build a user-friendly notification message.
	 *
	 * Format:
	 *   🔔 Nhắc lịch: Họp nhóm · 14:30 hôm nay
	 *   ✅ Nhắc việc: Nộp báo cáo · 09:00 ngày mai
	 *
	 * @param array $event
	 * @return string
	 */
	private function build_notification( $event ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME W7 — build notification text
		$event_type = (string) ( isset( $event['event_type'] ) ? $event['event_type'] : '' );
		$title      = (string) ( isset( $event['title'] )      ? $event['title']      : 'Không có tiêu đề' );
		$start_at   = (string) ( isset( $event['start_at'] )   ? $event['start_at']   : '' );

		// Emoji + label by type
		$type_labels = array(
			'task'     => array( 'emoji' => '✅', 'label' => 'Nhắc việc' ),
			'meeting'  => array( 'emoji' => '📅', 'label' => 'Nhắc lịch' ),
			'reminder' => array( 'emoji' => '🔔', 'label' => 'Nhắc nhở' ),
			'event'    => array( 'emoji' => '📌', 'label' => 'Sự kiện' ),
		);

		$info  = isset( $type_labels[ $event_type ] ) ? $type_labels[ $event_type ] : array( 'emoji' => '🔔', 'label' => 'Nhắc' );
		$emoji = $info['emoji'];
		$label = $info['label'];

		// Format start time (relative to now)
		$time_str = '';
		if ( $start_at ) {
			$start_ts  = strtotime( $start_at );
			$now_ts    = current_time( 'timestamp' );
			$today     = current_time( 'Y-m-d' );
			$start_day = date( 'Y-m-d', $start_ts );
			$time_part = date( 'H:i', $start_ts );

			if ( $start_day === $today ) {
				$time_str = ' · ' . $time_part . ' hôm nay';
			} elseif ( $start_day === date( 'Y-m-d', $now_ts + DAY_IN_SECONDS ) ) {
				$time_str = ' · ' . $time_part . ' ngày mai';
			} else {
				$time_str = ' · ' . $time_part . ' ' . date( 'd/m', $start_ts );
			}
		}

		return $emoji . ' ' . $label . ': ' . $title . $time_str;
	}
}
