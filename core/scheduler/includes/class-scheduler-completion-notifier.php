<?php
/**
 * BizCity Scheduler — Completion Notifier (R-SCH §3, SCH-NC W4)
 *
 * Listen `bizcity_scheduler_event_completed` + `bizcity_scheduler_event_failed`.
 * Khi event chuyển status → done, tự reply lại đúng channel inbound đã tạo
 * việc (Zalo / FB / Telegram / WebChat) qua BizCity_Gateway_Sender.
 *
 * Fallback resolve khi event không có metadata.inbound:
 *   1. user_meta('bizcity_default_notify_channel') = { platform, chat_id }
 *   2. site option 'bizcity_default_notify_channel'
 *   3. skip — event vẫn hiện trên dashboard.
 *
 * Per-event opt-out: metadata.notify = false → skip.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Scheduler
 * @since      2026-06-03 (PHASE-SCHEDULER-NERVE-CENTER W4)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Scheduler_Completion_Notifier' ) ) {
	return;
}

final class BizCity_Scheduler_Completion_Notifier {

	/** @var bool */
	private static $booted = false;

	/**
	 * Bootstrap — gọi từ scheduler bootstrap.php.
	 *
	 * @return void
	 */
	public static function init() {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — register listeners.
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'bizcity_scheduler_event_completed', [ __CLASS__, 'on_completed' ], 10, 2 );
		add_action( 'bizcity_scheduler_event_failed',    [ __CLASS__, 'on_failed' ],    10, 3 );
	}

	/**
	 * Handle event_completed.
	 *
	 * @param int            $event_id
	 * @param object|array   $event Row hoặc object từ Manager.
	 * @return void
	 */
	public static function on_completed( $event_id, $event ) {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — reply-back về inbound channel.
		$row = self::row_to_array( $event );
		if ( empty( $row ) ) {
			return;
		}
		$meta = self::decode_meta( $row );

		// Per-event opt-out.
		if ( isset( $meta['notify'] ) && $meta['notify'] === false ) {
			return;
		}
		if ( isset( $meta['notify'] ) && is_array( $meta['notify'] ) && isset( $meta['notify']['enabled'] ) && $meta['notify']['enabled'] === false ) {
			return;
		}

		$target = self::resolve_target( $row, $meta );
		if ( ! $target ) {
			return;
		}

		$msg = self::compose_done_message( $row, $meta );

		/**
		 * Filter cho phép module khác override message.
		 *
		 * @param string $msg
		 * @param array  $row    Event row.
		 * @param array  $meta   Decoded metadata.
		 * @param array  $target { platform, chat_id }
		 */
		$msg = apply_filters( 'bizcity_scheduler_completion_message', $msg, $row, $meta, $target );

		$result = self::dispatch( $target, $msg );

		// Persist delivery audit vào metadata.
		self::patch_delivery( (int) $event_id, $target, $result );
	}

	/**
	 * Handle event_failed.
	 *
	 * @param int          $event_id
	 * @param object|array $event
	 * @param string       $reason
	 * @return void
	 */
	public static function on_failed( $event_id, $event, $reason = '' ) {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — notify failure cho user inbound.
		$row = self::row_to_array( $event );
		if ( empty( $row ) ) {
			return;
		}
		$meta = self::decode_meta( $row );
		if ( isset( $meta['notify'] ) && $meta['notify'] === false ) {
			return;
		}
		$target = self::resolve_target( $row, $meta );
		if ( ! $target ) {
			return;
		}
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$msg   = sprintf( '⚠️ Việc "%s" (#%d) lỗi: %s', $title, (int) $event_id, $reason !== '' ? $reason : 'unknown' );
		$msg   = apply_filters( 'bizcity_scheduler_failure_message', $msg, $row, $meta, $target, $reason );
		$result = self::dispatch( $target, $msg );
		self::patch_delivery( (int) $event_id, $target, $result );
	}

	/* ──────────────────────────────────────────────────────────────
	 *  Helpers
	 * ────────────────────────────────────────────────────────────── */

	/**
	 * @param object|array $event
	 * @return array
	 */
	private static function row_to_array( $event ) {
		if ( is_array( $event ) ) {
			return $event;
		}
		if ( is_object( $event ) ) {
			return (array) $event;
		}
		return [];
	}

	/**
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
		return [];
	}

	/**
	 * Resolve { platform, chat_id } theo thứ tự:
	 *   1. metadata.notify.target
	 *   2. metadata.inbound
	 *   3. user_meta 'bizcity_default_notify_channel'
	 *   4. option 'bizcity_default_notify_channel'
	 *
	 * @param array $row
	 * @param array $meta
	 * @return array|null  { platform, chat_id } hoặc null nếu skip.
	 */
	private static function resolve_target( array $row, array $meta ) {
		// 1. Per-event override.
		if ( isset( $meta['notify']['target'] ) && is_array( $meta['notify']['target'] ) ) {
			$t = $meta['notify']['target'];
			if ( ! empty( $t['platform'] ) && ! empty( $t['chat_id'] ) ) {
				return [ 'platform' => (string) $t['platform'], 'chat_id' => (string) $t['chat_id'] ];
			}
		}
		// 2. Inbound provenance.
		if ( isset( $meta['inbound'] ) && is_array( $meta['inbound'] ) ) {
			$in = $meta['inbound'];
			if ( ! empty( $in['platform'] ) && ! empty( $in['chat_id'] ) ) {
				return [ 'platform' => (string) $in['platform'], 'chat_id' => (string) $in['chat_id'] ];
			}
		}
		// 3. Owner default channel (user_meta).
		$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $user_id > 0 ) {
			// [2026-06-22 Johnny Chu] R-PERF — route via BizCity_User_Meta_Cache to avoid WP meta prime
			$pref = class_exists( 'BizCity_User_Meta_Cache' )
				? BizCity_User_Meta_Cache::get( $user_id, 'bizcity_default_notify_channel', array() )
				: get_user_meta( $user_id, 'bizcity_default_notify_channel', true );
			if ( is_array( $pref ) && ! empty( $pref['platform'] ) && ! empty( $pref['chat_id'] ) ) {
				return [ 'platform' => (string) $pref['platform'], 'chat_id' => (string) $pref['chat_id'] ];
			}
		}
		// 4. Site-wide default.
		$global = get_option( 'bizcity_default_notify_channel', [] );
		if ( is_array( $global ) && ! empty( $global['platform'] ) && ! empty( $global['chat_id'] ) ) {
			return [ 'platform' => (string) $global['platform'], 'chat_id' => (string) $global['chat_id'] ];
		}

		/**
		 * Filter cho phép module khác cung cấp target tự động.
		 *
		 * @param array|null $target
		 * @param array      $row
		 * @param array      $meta
		 */
		$filtered = apply_filters( 'bizcity_scheduler_resolve_default_channel', null, $row, $meta );
		if ( is_array( $filtered ) && ! empty( $filtered['platform'] ) && ! empty( $filtered['chat_id'] ) ) {
			return [ 'platform' => (string) $filtered['platform'], 'chat_id' => (string) $filtered['chat_id'] ];
		}
		return null;
	}

	/**
	 * Compose tin nhắn done — Vietnamese fixed (D2 LOCKED).
	 *
	 * @param array $row
	 * @param array $meta
	 * @return string
	 */
	private static function compose_done_message( array $row, array $meta ) {
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		$type  = isset( $row['event_type'] ) ? (string) $row['event_type'] : '';
		$id    = isset( $row['id'] ) ? (int) $row['id'] : 0;

		$verbs = [
			'fb_post'             => 'đăng FB',
			'web_post'            => 'đăng web',
			'reminder_zalo'       => 'gửi nhắc Zalo',
			'telegram_send'       => 'gửi Telegram',
			'reminder_personal'   => 'nhắc',
			'automation_workflow' => 'chạy automation',
			'woo_product_create'  => 'tạo sản phẩm',
			'woo_product_edit'    => 'cập nhật sản phẩm',
			'woo_order_create'    => 'tạo đơn hàng',
			'lead_report'         => 'làm báo cáo',
		];
		$verb = isset( $verbs[ $type ] ) ? $verbs[ $type ] : 'xử lý';

		// Adapter có label tốt hơn — ưu tiên dùng nếu có.
		if ( class_exists( 'BizCity_Scheduler_Adapter_Registry' ) ) {
			$adapter = BizCity_Scheduler_Adapter_Registry::get( $type );
			if ( $adapter !== null ) {
				$label = (string) $adapter->label();
				if ( $label !== '' ) {
					$verb = mb_strtolower( $label, 'UTF-8' );
				}
			}
		}

		// Permalink đính kèm nếu có (fb_post, web_post).
		$extras = [];
		if ( ! empty( $meta['fb_permalink'] ) ) {
			$extras[] = (string) $meta['fb_permalink'];
		} elseif ( ! empty( $meta['web_permalink'] ) ) {
			$extras[] = (string) $meta['web_permalink'];
		}

		$base = sprintf( '✅ Đã %s xong: %s (#%d)', $verb, $title, $id );
		if ( ! empty( $extras ) ) {
			$base .= "\n🔗 " . implode( ' · ', $extras );
		}
		return $base;
	}

	/**
	 * @param array  $target { platform, chat_id }
	 * @param string $msg
	 * @return array { sent: bool, error: string, platform: string }
	 */
	private static function dispatch( array $target, $msg ) {
		// [2026-06-03 Johnny Chu] SCH-NC W4 — gateway sender hoặc fail-OPEN.
		if ( ! class_exists( 'BizCity_Gateway_Sender' ) ) {
			return [ 'sent' => false, 'error' => 'gateway_unavailable', 'platform' => $target['platform'] ];
		}
		try {
			$result = BizCity_Gateway_Sender::instance()->send(
				(string) $target['chat_id'],
				(string) $msg,
				'text',
				[]
			);
			if ( ! is_array( $result ) ) {
				return [ 'sent' => false, 'error' => 'invalid_sender_result', 'platform' => $target['platform'] ];
			}
			return $result;
		} catch ( \Throwable $e ) {
			return [ 'sent' => false, 'error' => 'exception:' . $e->getMessage(), 'platform' => $target['platform'] ];
		}
	}

	/**
	 * Persist delivery audit vào metadata (R-SCH §2 metadata.delivery).
	 *
	 * @param int   $event_id
	 * @param array $target
	 * @param array $result
	 * @return void
	 */
	private static function patch_delivery( $event_id, array $target, array $result ) {
		if ( ! class_exists( 'BizCity_Scheduler_Manager' ) ) {
			return;
		}
		$mgr   = BizCity_Scheduler_Manager::instance();
		$event = $mgr->get_event( (int) $event_id );
		if ( ! $event ) {
			return;
		}
		$meta = [];
		if ( ! empty( $event->metadata ) && is_string( $event->metadata ) ) {
			$decoded = json_decode( $event->metadata, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		$meta['delivery'] = [
			'status'     => ! empty( $result['sent'] ) ? 'sent' : 'failed',
			'platform'   => isset( $result['platform'] ) ? (string) $result['platform'] : (string) $target['platform'],
			'chat_id'    => (string) $target['chat_id'],
			'sent_at'    => current_time( 'mysql' ),
			'message_id' => isset( $result['message_id'] ) ? (string) $result['message_id'] : null,
			'error'      => empty( $result['sent'] ) ? ( isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ) : null,
		];
		$mgr->update_event( (int) $event_id, [ 'metadata' => $meta ] );
	}
}
