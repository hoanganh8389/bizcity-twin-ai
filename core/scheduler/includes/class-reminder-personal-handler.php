<?php
/**
 * Reminder Personal Handler
 *
 * Handles `bizcity_scheduler_reminder_fire` for event_type='reminder_personal'.
 * At fire time, resolves the target channel (inbound → user_meta → site option),
 * composes "⏰ Nhắc lịch: {title}" message and delivers via BizCity_Gateway_Sender.
 * Suppresses the generic "✅ Đã nhắc xong" completion-notifier message by setting
 * metadata.notify.enabled = false before marking the event done.
 *
 * Flow:
 *   1. bizcity_scheduler_reminder_fire fires (BizCity_Scheduler_Cron::scan_reminders)
 *   2. on_fire() (priority 28) picks up event_type='reminder_personal'
 *   3. Resolves target: metadata.inbound → user_meta → site option → filter
 *   4. Composes and sends reminder message via BizCity_Gateway_Sender
 *   5. Suppresses completion notifier by setting metadata.notify.enabled=false
 *   6. Marks event status='done' via BizCity_Scheduler_Manager::update_event()
 *      → fires bizcity_scheduler_event_completed (completion notifier skips due to flag)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-15 (R-UNIFY)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Reminder_Personal_Handler' ) ) {
	return;
}

final class BizCity_Reminder_Personal_Handler {

	/**
	 * Attach the fire listener.
	 *
	 * Called from scheduler/bootstrap.php.
	 */
	public static function init() {
		// [2026-06-15 Johnny Chu] R-UNIFY — handler priority 28, bau trước HIL (5)
		// và sau Zalo-specific handler (30). Chỉ xử lý reminder_personal.
		add_action( 'bizcity_scheduler_reminder_fire', array( __CLASS__, 'on_fire' ), 28, 1 );
	}

	/**
	 * Handle the reminder fire.
	 *
	 * @param object|array $event  Scheduler event row.
	 */
	public static function on_fire( $event ) {
		// [2026-06-15 Johnny Chu] R-UNIFY — cast to array.
		$row = self::to_array( $event );
		if ( ( $row['event_type'] ?? '' ) !== 'reminder_personal' ) {
			return;
		}

		$event_id = (int) ( $row['id'] ?? 0 );
		$meta     = self::decode_meta( $row );

		// Resolve send target.
		$target = self::resolve_target( $row, $meta );
		if ( null === $target ) {
			error_log( sprintf(
				'[Reminder Personal] No target resolved for event #%d — no inbound, no default channel.',
				$event_id
			) );
			return;
		}

		// Compose reminder message.
		$msg = self::compose_message( $row, $meta );

		/**
		 * Filter to customise the reminder message before sending.
		 *
		 * @param string $msg     Message text.
		 * @param array  $row     Event row.
		 * @param array  $meta    Decoded metadata.
		 * @param array  $target  { platform, chat_id }.
		 */
		$msg = (string) apply_filters( 'bizcity_reminder_personal_message', $msg, $row, $meta, $target );

		// Dispatch.
		$result = self::dispatch( $target, $msg );

		// Cron meta evidence (R-CRON-META).
		self::note_cron( $event_id, $target, $result );

		// Suppress generic completion-notifier done message.
		$meta['notify']['enabled'] = false;
		$meta['delivery'] = array(
			'status'   => ! empty( $result['sent'] ) ? 'sent' : 'failed',
			'platform' => isset( $result['platform'] ) ? (string) $result['platform'] : (string) $target['platform'],
			'chat_id'  => (string) $target['chat_id'],
			'sent_at'  => current_time( 'mysql' ),
			'error'    => empty( $result['sent'] ) ? ( isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ) : null,
		);

		// Mark event done — triggers bizcity_scheduler_event_completed (skipped by notify.enabled=false).
		if ( class_exists( 'BizCity_Scheduler_Manager' ) && $event_id > 0 ) {
			BizCity_Scheduler_Manager::instance()->update_event( $event_id, array(
				'status'   => 'done',
				'metadata' => $meta,
			) );
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Build the reminder message text.
	 *
	 * @param array $row
	 * @param array $meta
	 * @return string
	 */
	private static function compose_message( array $row, array $meta ) {
		$title         = trim( (string) ( $row['title'] ?? '' ) );
		$reminder_text = trim( (string) ( $meta['reminder_text'] ?? '' ) );

		$msg = '⏰ Nhắc lịch: ' . $title;
		if ( $reminder_text !== '' && $reminder_text !== $title ) {
			$msg .= "\n" . $reminder_text;
		}
		return $msg;
	}

	/**
	 * Resolve target { platform, chat_id } using same priority chain as
	 * BizCity_Scheduler_Completion_Notifier::resolve_target().
	 *
	 * Order:
	 *   1. metadata.notify.target (per-event override)
	 *   2. metadata.inbound (provenance from creation channel)
	 *   3. user_meta 'bizcity_default_notify_channel' (admin binding)
	 *   4. option  'bizcity_default_notify_channel'  (site-wide default)
	 *   5. filter  'bizcity_scheduler_resolve_default_channel'
	 *
	 * @param array $row
	 * @param array $meta
	 * @return array|null  { platform, chat_id } or null if unresolvable.
	 */
	private static function resolve_target( array $row, array $meta ) {
		// 1. Per-event override.
		if (
			isset( $meta['notify']['target'] ) && is_array( $meta['notify']['target'] )
			&& ! empty( $meta['notify']['target']['platform'] )
			&& ! empty( $meta['notify']['target']['chat_id'] )
		) {
			return array(
				'platform' => (string) $meta['notify']['target']['platform'],
				'chat_id'  => (string) $meta['notify']['target']['chat_id'],
			);
		}
		// 2. Inbound provenance.
		if (
			isset( $meta['inbound'] ) && is_array( $meta['inbound'] )
			&& ! empty( $meta['inbound']['platform'] )
			&& ! empty( $meta['inbound']['chat_id'] )
		) {
			return array(
				'platform' => (string) $meta['inbound']['platform'],
				'chat_id'  => (string) $meta['inbound']['chat_id'],
			);
		}
		// 3. Owner default channel (user_meta).
		$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $user_id > 0 ) {
			// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
			$pref = class_exists( 'BizCity_User_Meta_Cache' )
				? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_default_notify_channel', array() )
				: get_user_meta( $user_id, 'bizcity_default_notify_channel', true );
			if ( is_array( $pref ) && ! empty( $pref['platform'] ) && ! empty( $pref['chat_id'] ) ) {
				return array(
					'platform' => (string) $pref['platform'],
					'chat_id'  => (string) $pref['chat_id'],
				);
			}
		}
		// 4. Site-wide default.
		$global = get_option( 'bizcity_default_notify_channel', array() );
		if ( is_array( $global ) && ! empty( $global['platform'] ) && ! empty( $global['chat_id'] ) ) {
			return array(
				'platform' => (string) $global['platform'],
				'chat_id'  => (string) $global['chat_id'],
			);
		}
		// 5. Filter.
		$filtered = apply_filters( 'bizcity_scheduler_resolve_default_channel', null, $row, $meta );
		if ( is_array( $filtered ) && ! empty( $filtered['platform'] ) && ! empty( $filtered['chat_id'] ) ) {
			return array(
				'platform' => (string) $filtered['platform'],
				'chat_id'  => (string) $filtered['chat_id'],
			);
		}
		return null;
	}

	/**
	 * Dispatch via BizCity_Gateway_Sender (fail-OPEN).
	 *
	 * @param array  $target  { platform, chat_id }
	 * @param string $msg
	 * @return array  { sent, error, platform }
	 */
	private static function dispatch( array $target, $msg ) {
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return array( 'sent' => false, 'error' => 'gateway_unavailable', 'platform' => $target['platform'] );
		}
		try {
			$result = BizCity_Gateway_Sender::instance()->send(
				(string) $target['chat_id'],
				(string) $msg,
				'text',
				array()
			);
			if ( ! is_array( $result ) ) {
				return array( 'sent' => false, 'error' => 'invalid_sender_result', 'platform' => $target['platform'] );
			}
			return $result;
		} catch ( \Exception $e ) {
			return array( 'sent' => false, 'error' => 'exception:' . $e->getMessage(), 'platform' => $target['platform'] );
		}
	}

	/**
	 * Write cron meta evidence (R-CRON-META).
	 *
	 * @param int   $event_id
	 * @param array $target
	 * @param array $result
	 */
	private static function note_cron( $event_id, array $target, array $result ) {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		$cron = BizCity_Cron_Manager::instance();
		$cron->note( array(
			'event_id'  => $event_id,
			'platform'  => (string) $target['platform'],
			'chat_id'   => (string) $target['chat_id'],
			'counters'  => array( 'reminder_personal_fired' => 1 ),
		) );
		if ( empty( $result['sent'] ) ) {
			$cron->note_event( 'reminder_personal_failed', array(
				'event_id' => $event_id,
				'platform' => (string) $target['platform'],
				'reason'   => isset( $result['error'] ) ? (string) $result['error'] : 'unknown_error',
				'error'    => isset( $result['error'] ) ? (string) $result['error'] : '',
			) );
		}
	}

	/**
	 * Decode metadata from event row.
	 *
	 * @param array $row
	 * @return array
	 */
	private static function decode_meta( array $row ) {
		$raw = isset( $row['metadata'] ) ? $row['metadata'] : '';
		if ( is_array( $raw ) ) {
			return $raw;
		}
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return array();
	}

	/**
	 * Cast event to array.
	 *
	 * @param mixed $event
	 * @return array
	 */
	private static function to_array( $event ) {
		if ( is_array( $event ) ) {
			return $event;
		}
		if ( is_object( $event ) ) {
			return (array) $event;
		}
		return array();
	}
}
