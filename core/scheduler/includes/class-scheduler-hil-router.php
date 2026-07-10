<?php
/**
 * BizCity Scheduler — HIL Router (Human-In-The-Loop confirm flow).
 *
 * R-SCH §7 / PHASE-SCHEDULER-HIL-CONFIRM.md (Wave SCH-NC W6).
 *
 * Listens to `bizcity_channel_message_received` BEFORE
 * `BizCity_Automation_Trigger_Matcher` (priority 5 < 30) and tries to match
 * user reply against the most recent draft `reminder_personal` event for
 * (platform, chat_id) within 5 minutes:
 *
 *   - "ok" / "✅" / "đồng ý" / "chốt"     → status='active'  (cron sẽ fire)
 *   - "hủy" / "huỷ" / "❌" / "cancel"     → status='cancelled'
 *   - "sửa <giờ mới>"                     → patch start_at, giữ draft, send envelope mới
 *
 * Nếu KHÔNG match keyword → return false → automation matcher xử lý bình thường.
 *
 * Hooks:
 *   - bizcity_scheduler_hil_confirmed( $event_id, $event_row )
 *   - bizcity_scheduler_hil_cancelled( $event_id, $event_row, $reason )
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W6)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_HIL_Router' ) ) {
	return;
}

final class BizCity_Scheduler_HIL_Router {

	/** Window TTL for matching draft → reply (seconds). */
	const WINDOW_SECONDS = 300; // 5 minutes (R-SCH HIL §4 timeout).

	/** @var bool */
	private static $booted = false;

	/**
	 * Idempotent bootstrap.
	 */
	public static function init() {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — HIL Router idempotent boot.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		// Priority 5 — fire BEFORE automation matcher (priority 30) so HIL
		// reply không kích hoạt workflow rác.
		add_action( 'bizcity_channel_message_received', array( __CLASS__, 'maybe_handle' ), 5, 1 );
	}

	/**
	 * Channel inbound listener.
	 *
	 * @param array $payload Channel envelope (platform, chat_id, message/text…).
	 * @return void
	 */
	public static function maybe_handle( $payload ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — match draft event by (platform, chat_id).
		if ( ! is_array( $payload ) ) {
			return;
		}
		$platform_raw = (string) ( $payload['platform'] ?? '' );
		$chat_id      = (string) ( $payload['chat_id'] ?? '' );
		$text         = trim( (string) ( $payload['message'] ?? $payload['text'] ?? '' ) );
		if ( $platform_raw === '' || $chat_id === '' || $text === '' ) {
			return;
		}
		// Skip ASSISTANT echo loop guard.
		if ( strtoupper( (string) ( $payload['channel_role'] ?? '' ) ) === 'ASSISTANT' ) {
			return;
		}
		$platform = class_exists( 'BizCity_Scheduler_Inbound_Provenance' )
			? BizCity_Scheduler_Inbound_Provenance::normalize_platform( $platform_raw )
			: strtoupper( $platform_raw );

		$verb = self::classify_reply( $text );
		if ( $verb === '' ) {
			return; // No keyword match → let downstream matcher handle.
		}

		$event = self::find_recent_draft( $platform, $chat_id );
		if ( ! $event ) {
			return;
		}

		switch ( $verb ) {
			case 'confirm':
				self::confirm( $event );
				break;
			case 'cancel':
				self::cancel( $event, 'user' );
				break;
			case 'edit':
				self::edit( $event, $text );
				break;
		}
	}

	/**
	 * Classify a user reply.
	 *
	 * @param string $text
	 * @return string '' | 'confirm' | 'cancel' | 'edit'
	 */
	public static function classify_reply( $text ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — keyword classifier (VI-first).
		$norm = mb_strtolower( trim( (string) $text ) );
		if ( $norm === '' ) {
			return '';
		}

		// Strip leading emoji-ish prefix (✅ / ❌).
		$strip = preg_replace( '/^[\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}\s]+/u', '', $norm );
		$strip = is_string( $strip ) ? $strip : $norm;

		// Edit verbs (priority — must check before short-confirm).
		if ( preg_match( '/^(s[uưử]a|đ[oổ]i|change|edit)\s+/u', $strip ) ) {
			return 'edit';
		}

		$confirms = array( 'ok', 'oki', 'okie', 'okay', '✅', 'đồng ý', 'dong y', 'chốt', 'chot', 'yes', 'y', 'ừ', 'ừm', 'ừa', 'duyệt', 'duyet' );
		$cancels  = array( 'hủy', 'huy', 'huỷ', '❌', 'no', 'n', 'cancel', 'không', 'khong', 'bỏ', 'bo', 'thôi', 'thoi' );

		if ( in_array( $strip, $confirms, true ) || in_array( $norm, $confirms, true ) ) {
			return 'confirm';
		}
		if ( in_array( $strip, $cancels, true ) || in_array( $norm, $cancels, true ) ) {
			return 'cancel';
		}
		return '';
	}

	/**
	 * Find the most recent draft reminder_personal event for (platform, chat_id)
	 * within WINDOW_SECONDS.
	 *
	 * @param string $platform
	 * @param string $chat_id
	 * @return array|null  Event row as array, hoặc null.
	 */
	public static function find_recent_draft( $platform, $chat_id ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — JSON_EXTRACT lookup theo
		// metadata.inbound.platform + metadata.inbound.chat_id.
		global $wpdb;
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return null;
		}
		$tbl   = BizCity_Scheduler_Manager::instance()->get_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW_SECONDS );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$tbl}
			 WHERE event_type = %s
			   AND status     = %s
			   AND created_at >= %s
			   AND JSON_EXTRACT(metadata, '$.inbound.platform') = %s
			   AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.inbound.chat_id')) = %s
			 ORDER BY id DESC
			 LIMIT 1",
			'reminder_personal',
			'draft',
			$since,
			wp_json_encode( $platform ),
			$chat_id
		);
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Actions
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * @param array $event
	 * @return void
	 */
	private static function confirm( array $event ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — flip draft → active.
		$id  = (int) $event['id'];
		$mgr = BizCity_Scheduler_Manager::instance();

		$meta = self::decode_meta( $event );
		$meta['hil'] = array(
			'state'       => 'confirmed',
			'confirmed_at' => current_time( 'mysql' ),
		);
		$res = $mgr->update_event( $id, array(
			'status'   => 'active',
			'metadata' => $meta,
		) );
		if ( is_wp_error( $res ) ) {
			error_log( '[scheduler.hil] confirm failed #' . $id . ': ' . $res->get_error_message() );
			return;
		}
		$row = $mgr->get_event( $id );
		do_action( 'bizcity_scheduler_hil_confirmed', $id, $row );

		$title = isset( $event['title'] ) ? (string) $event['title'] : '';
		$start = isset( $event['start_at'] ) ? (string) $event['start_at'] : '';
		$msg   = sprintf( '✅ Đã chốt: "%s" lúc %s. Em sẽ nhắc đúng giờ.', $title, $start );
		$msg   = apply_filters( 'bizcity_scheduler_hil_confirmed_message', $msg, $event, $meta );
		self::send( $meta, $msg );
	}

	/**
	 * @param array  $event
	 * @param string $reason 'user'|'timeout'
	 * @return void
	 */
	public static function cancel( array $event, $reason = 'user' ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — flip draft → cancelled.
		$id  = (int) $event['id'];
		$mgr = BizCity_Scheduler_Manager::instance();

		$meta = self::decode_meta( $event );
		$meta['hil'] = array(
			'state'        => 'cancelled',
			'cancelled_at' => current_time( 'mysql' ),
			'reason'       => (string) $reason,
		);
		$res = $mgr->update_event( $id, array(
			'status'   => 'cancelled',
			'metadata' => $meta,
		) );
		if ( is_wp_error( $res ) ) {
			error_log( '[scheduler.hil] cancel failed #' . $id . ': ' . $res->get_error_message() );
			return;
		}
		$row = $mgr->get_event( $id );
		do_action( 'bizcity_scheduler_hil_cancelled', $id, $row, $reason );

		$title = isset( $event['title'] ) ? (string) $event['title'] : '';
		$msg   = $reason === 'timeout'
			? sprintf( '⏱️ Hết 5 phút không thấy phản hồi → em đã hủy nhắc "%s". Sếp gõ lại nếu cần.', $title )
			: sprintf( '❌ Đã hủy nhắc "%s".', $title );
		$msg   = apply_filters( 'bizcity_scheduler_hil_cancelled_message', $msg, $event, $meta, $reason );
		self::send( $meta, $msg );
	}

	/**
	 * Try to re-parse new datetime from "sửa 19h30" / "sửa 8h tối".
	 *
	 * @param array  $event
	 * @param string $text
	 * @return void
	 */
	private static function edit( array $event, $text ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — patch start_at theo từ "sửa <giờ>".
		if ( ! preg_match( '/^(?:s[uưử]a|đ[oổ]i|change|edit)\s+(.+)$/u', mb_strtolower( $text ), $m ) ) {
			return;
		}
		$when = trim( (string) $m[1] );
		if ( $when === '' ) {
			return;
		}
		$ts = strtotime( $when );
		if ( ! $ts || $ts < time() ) {
			$meta = self::decode_meta( $event );
			self::send( $meta, sprintf( '⚠️ Em chưa hiểu giờ "%s". Sếp gõ dạng "sửa 19h30" hoặc "sửa ngày mai 8h".', $when ) );
			return;
		}
		$new_start = gmdate( 'Y-m-d H:i:s', $ts );

		$id   = (int) $event['id'];
		$mgr  = BizCity_Scheduler_Manager::instance();
		$meta = self::decode_meta( $event );
		$meta['hil'] = array(
			'state'      => 'edited',
			'edited_at'  => current_time( 'mysql' ),
			'edit_input' => $when,
		);
		$res = $mgr->update_event( $id, array(
			'start_at' => $new_start,
			'metadata' => $meta,
		) );
		if ( is_wp_error( $res ) ) {
			error_log( '[scheduler.hil] edit failed #' . $id . ': ' . $res->get_error_message() );
			return;
		}

		$row = $mgr->get_event( $id );
		// Re-send envelope với giờ mới — giữ status='draft'.
		$envelope = self::compose_envelope( is_object( $row ) ? (array) $row : (array) $row, $meta );
		self::send( $meta, $envelope );
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Public helper — for tool to send envelope
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * Compose & send the confirmation envelope. Used by
	 * `BizCity_TwinBrain_Scheduler_Tool_Set_Reminder` after creating draft.
	 *
	 * @param array $row   Event row.
	 * @param array $meta  Decoded metadata.
	 * @return array { sent, error, platform }
	 */
	public static function send_envelope( array $row, array $meta ) {
		// [2026-06-03 Johnny Chu] SCH-NC W6 — public API for tool.
		$msg = self::compose_envelope( $row, $meta );
		return self::send( $meta, $msg );
	}

	/**
	 * @param array $row
	 * @param array $meta
	 * @return string
	 */
	private static function compose_envelope( array $row, array $meta ) {
		$title    = isset( $row['title'] ) ? (string) $row['title'] : '';
		$start_at = isset( $row['start_at'] ) ? (string) $row['start_at'] : '';
		$rmin     = isset( $row['reminder_min'] ) ? (int) $row['reminder_min'] : 0;
		$lines = array(
			'🔔 Em vừa hiểu là sếp muốn em nhắc:',
			'   • Việc:   "' . $title . '"',
			'   • Lúc:    ' . $start_at,
		);
		if ( $rmin > 0 ) {
			$lines[] = '   • Nhắc trước: ' . $rmin . ' phút';
		}
		$lines[] = '';
		$lines[] = 'Sếp gõ:';
		$lines[] = '   ✅  hoặc  OK     → để em chốt';
		$lines[] = '   ❌  hoặc  Hủy    → bỏ qua';
		$lines[] = '   sửa <giờ mới>    → vd: "sửa 19h30"';
		$msg = implode( "\n", $lines );
		return apply_filters( 'bizcity_scheduler_hil_envelope', $msg, $row, $meta );
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Internal helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * @param array $event
	 * @return array
	 */
	private static function decode_meta( array $event ) {
		$raw = isset( $event['metadata'] ) ? $event['metadata'] : '';
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
	 * @param array  $meta
	 * @param string $msg
	 * @return array { sent, error, platform }
	 */
	private static function send( array $meta, $msg ) {
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return array( 'sent' => false, 'error' => 'gateway_unavailable', 'platform' => '' );
		}
		$chat_id = isset( $meta['inbound']['chat_id'] ) ? (string) $meta['inbound']['chat_id'] : '';
		if ( $chat_id === '' ) {
			return array( 'sent' => false, 'error' => 'no_chat_id', 'platform' => '' );
		}
		try {
			$result = BizCity_Gateway_Sender::instance()->send( $chat_id, (string) $msg, 'text', array() );
			return is_array( $result ) ? $result : array( 'sent' => false, 'error' => 'invalid_sender_result', 'platform' => '' );
		} catch ( \Throwable $e ) {
			return array( 'sent' => false, 'error' => 'exception:' . $e->getMessage(), 'platform' => '' );
		}
	}
}
