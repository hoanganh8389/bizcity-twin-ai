<?php
/**
 * Notification Center — WP Hook Dispatcher (PHASE-CG-NOTIFY-BINDINGS)
 *
 * Intercepts WP/WooCommerce events và forward tới Zalo Bot + Email SMTP
 * theo cài đặt trong `bizcity_cg_notify_settings`.
 *
 * Supported events:
 *   order_new     — woocommerce_new_order
 *   cf7_submit    — wpcf7_mail_sent
 *   user_register — user_register
 *
 * Zalo send path:  Zalo Bot API (https://api.zalo.me/v3/message/cs)
 *                  dùng bot_token từ bizcity_zalo_bots table + user_id target.
 * Email send path: wp_mail() — goes through SMTP override nếu có.
 *
 * R-GW-8 / fail-open: dispatcher KHÔNG throw, mọi lỗi chỉ error_log + return.
 * PHP 7.4: không có union return type, không có nullsafe.
 *
 * @package    BizCity_Twin_AI
 * @subpackage Channel_Gateway
 * @since      PHASE-CG-NOTIFY-BINDINGS (2026-06-13)
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WP notification dispatcher.
class BizCity_Notify_Dispatcher {

	const OPTION_KEY = 'bizcity_cg_notify_settings';

	/** Cached settings for the current request (lazy). */
	private static $settings = null;

	/**
	 * @return void
	 */
	public static function init() {
		// Only register hooks once.
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		// WooCommerce — new order (priority 20, after WC creates the order).
		add_action( 'woocommerce_new_order', array( __CLASS__, 'on_new_order' ), 20, 1 );

		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — additional WooCommerce events.
		add_action( 'woocommerce_payment_complete',       array( __CLASS__, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_cancelled' ), 20, 1 );
		add_action( 'woocommerce_low_stock',              array( __CLASS__, 'on_low_stock' ),        20, 1 );
		add_action( 'woocommerce_no_stock',               array( __CLASS__, 'on_no_stock' ),         20, 1 );

		// Contact Form 7 — mail sent.
		add_action( 'wpcf7_mail_sent', array( __CLASS__, 'on_cf7_mail_sent' ), 20, 1 );

		// WordPress — new user registration.
		add_action( 'user_register', array( __CLASS__, 'on_user_register' ), 20, 1 );

		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WP comment + post publish.
		add_action( 'comment_post',           array( __CLASS__, 'on_comment_new' ),    20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_post_published' ), 20, 3 );
	}

	// -------------------------------------------------------------------------
	// Hook handlers
	// -------------------------------------------------------------------------

	/**
	 * @param int $order_id
	 * @return void
	 */
	public static function on_payment_complete( $order_id ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WooCommerce payment complete.
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		$total = method_exists( $order, 'get_formatted_order_total' )
			? wp_strip_all_tags( $order->get_formatted_order_total() )
			: (string) $order->get_total() . ' ' . get_woocommerce_currency();
		$msg = sprintf(
			"[BizCity] ✅ Thanh toán xác nhận #%d\nKhách: %s\nGiá trị: %s",
			(int) $order_id,
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			$total
		);
		self::dispatch( 'order_payment_complete', $msg, array( 'order_id' => (int) $order_id ) );
	}

	/**
	 * @param int $order_id
	 * @return void
	 */
	public static function on_order_cancelled( $order_id ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WooCommerce order cancelled.
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		$msg = sprintf(
			"[BizCity] ❌ Đơn hàng bị hủy #%d\nKhách: %s",
			(int) $order_id,
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
		);
		self::dispatch( 'order_cancelled', $msg, array( 'order_id' => (int) $order_id ) );
	}

	/**
	 * @param WC_Product $product
	 * @return void
	 */
	public static function on_low_stock( $product ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WooCommerce low stock.
		if ( ! is_object( $product ) ) {
			return;
		}
		$qty = method_exists( $product, 'get_stock_quantity' ) ? (int) $product->get_stock_quantity() : 0;
		$msg = sprintf(
			"[BizCity] ⚠️ Tồn kho thấp\nSản phẩm: %s\nCòn lại: %d",
			$product->get_name(),
			$qty
		);
		self::dispatch( 'low_stock', $msg );
	}

	/**
	 * @param WC_Product $product
	 * @return void
	 */
	public static function on_no_stock( $product ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WooCommerce out of stock.
		if ( ! is_object( $product ) ) {
			return;
		}
		$msg = sprintf(
			"[BizCity] 🔴 Hết hàng\nSản phẩm: %s",
			$product->get_name()
		);
		// Reuse the same low_stock event code so admin doesn't need a separate checkbox.
		self::dispatch( 'low_stock', $msg );
	}

	/**
	 * @param int    $comment_id
	 * @param int    $comment_approved  0|1|'spam'
	 * @return void
	 */
	public static function on_comment_new( $comment_id, $comment_approved ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — new approved comment.
		if ( 1 !== (int) $comment_approved ) {
			return; // skip spam / pending
		}
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment ) {
			return;
		}
		$post_title = get_the_title( (int) $comment->comment_post_ID );
		$msg = sprintf(
			"[BizCity] 💬 Bình luận mới\nBài viết: %s\nTừ: %s\n%s",
			$post_title,
			$comment->comment_author,
			wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 20 )
		);
		self::dispatch( 'comment_new', $msg, array( 'comment_id' => (int) $comment_id ) );
	}

	/**
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 * @return void
	 */
	public static function on_post_published( $new_status, $old_status, $post ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — post goes to publish.
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return; // not a new publish transition
		}
		if ( ! is_object( $post ) || ! in_array( $post->post_type, array( 'post', 'page', 'product' ), true ) ) {
			return;
		}
		$msg = sprintf(
			"[BizCity] 📝 Bài viết xuất bản\n\"%s\"\nXem: %s",
			$post->post_title,
			get_permalink( $post->ID )
		);
		self::dispatch( 'post_published', $msg, array( 'post_id' => (int) $post->ID ) );
	}

	/**
	 * @param int $order_id
	 * @return void
	 */
	public static function on_new_order( $order_id ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — WooCommerce order hook.
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$total = method_exists( $order, 'get_formatted_order_total' )
			? wp_strip_all_tags( $order->get_formatted_order_total() )
			: (string) $order->get_total() . ' ' . get_woocommerce_currency();

		$msg = sprintf(
			"[BizCity] Đơn hàng mới #%d\nKhách: %s\nGiá trị: %s\nXem: %s",
			(int) $order_id,
			$name ? $name : 'Ẩn danh',
			$total,
			admin_url( 'post.php?post=' . (int) $order_id . '&action=edit' )
		);

		self::dispatch( 'order_new', $msg, array( 'order_id' => (int) $order_id ) );
	}

	/**
	 * @param WPCF7_ContactForm $contact_form
	 * @return void
	 */
	public static function on_cf7_mail_sent( $contact_form ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — CF7 mail sent hook.
		$title = ( is_object( $contact_form ) && method_exists( $contact_form, 'title' ) )
			? $contact_form->title()
			: 'Contact Form 7';

		$submitter = '';
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$sub = WPCF7_Submission::get_instance();
			if ( $sub ) {
				$data = $sub->get_posted_data();
				foreach ( array( 'your-name', 'name', 'fullname', 'ten', 'ho-ten' ) as $k ) {
					if ( ! empty( $data[ $k ] ) ) {
						$submitter = sanitize_text_field( (string) $data[ $k ] );
						break;
					}
				}
			}
		}

		$msg = sprintf(
			"[BizCity] Form CF7 vừa được gửi\nForm: %s%s",
			$title,
			$submitter ? "\nNgười gửi: $submitter" : ''
		);

		self::dispatch( 'cf7_submit', $msg );
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — new user registration hook.
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		$msg = sprintf(
			"[BizCity] Người dùng mới đăng ký\n%s (%s)",
			$user->display_name ? $user->display_name : $user->user_login,
			$user->user_email
		);

		self::dispatch( 'user_register', $msg, array( 'user_id' => (int) $user_id ) );
	}

	// -------------------------------------------------------------------------
	// Core dispatch
	// -------------------------------------------------------------------------

	/**
	 * @param string $event_code
	 * @param string $msg
	 * @param array  $ctx
	 * @return void
	 */
	private static function dispatch( $event_code, $msg, $ctx = array() ) {
		$settings = self::get_settings();

		if ( empty( $settings['notify_events'] ) ) {
			return;
		}
		if ( ! in_array( $event_code, (array) $settings['notify_events'], true ) ) {
			return;
		}

		// Zalo Bot channel.
		$bot_id  = isset( $settings['zalo_bot_id'] ) ? (int) $settings['zalo_bot_id'] : 0;
		$chat_id = isset( $settings['zalo_notify_chat_id'] ) ? (string) $settings['zalo_notify_chat_id'] : '';
		if ( $bot_id > 0 && '' !== $chat_id ) {
			self::send_zalo( $bot_id, $chat_id, $msg );
		}

		// Email channel.
		$smtp_uid = isset( $settings['email_smtp_uid'] ) ? (string) $settings['email_smtp_uid'] : '';
		if ( '' !== $smtp_uid ) {
			$recipients = isset( $settings['email_recipients'] ) && is_array( $settings['email_recipients'] ) && count( $settings['email_recipients'] ) > 0
				? $settings['email_recipients']
				: array( get_bloginfo( 'admin_email' ) );
			self::send_email( $recipients, $msg );
		}
	}

	// -------------------------------------------------------------------------
	// Zalo send
	// -------------------------------------------------------------------------

	/**
	 * @param int    $bot_id
	 * @param string $chat_id
	 * @param string $msg
	 * @return void
	 */
	private static function send_zalo( $bot_id, $chat_id, $msg ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — Zalo Bot send via direct API.
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}

		$table = $wpdb->prefix . 'bizcity_zalo_bots';
		// [2026-06-28 Johnny Chu] R-SHOW-TABLES — information_schema + wp_cache dual cache (no SHOW TABLES)
		$_ck_bot = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table );
		$_p_bot  = wp_cache_get( $_ck_bot, 'bizcity_tbl' );
		if ( false === $_p_bot ) {
			$_p_bot = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			) );
			wp_cache_set( $_ck_bot, $_p_bot, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		if ( ! $_p_bot ) {
			return;
		}

		$bot = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT bot_token FROM `{$table}` WHERE id = %d AND status = 'active' LIMIT 1",
			$bot_id
		) );

		if ( ! $bot || empty( $bot->bot_token ) ) {
			error_log( "BizCity_Notify_Dispatcher: bot #{$bot_id} not found or inactive." );
			return;
		}

		$response = wp_remote_post( 'https://api.zalo.me/v3/message/cs', array(
			'timeout' => 8,
			'headers' => array(
				'Content-Type' => 'application/json',
				'access_token' => $bot->bot_token,
			),
			'body'    => wp_json_encode( array(
				'recipient' => array( 'user_id' => $chat_id ),
				'message'   => array( 'text' => wp_strip_all_tags( $msg ) ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			error_log( 'BizCity_Notify_Dispatcher (Zalo): ' . $response->get_error_message() );
		}
	}

	// -------------------------------------------------------------------------
	// Email send
	// -------------------------------------------------------------------------

	/**
	 * @param array  $recipients
	 * @param string $msg
	 * @return void
	 */
	private static function send_email( $recipients, $msg ) {
		// [2026-06-13 Johnny Chu] PHASE-CG-NOTIFY-BINDINGS — email via wp_mail (SMTP override applied automatically).
		$subject = '[BizCity] ' . wp_strip_all_tags( mb_substr( $msg, 0, 80 ) );
		$body    = wp_strip_all_tags( $msg );

		$sent = wp_mail(
			$recipients,
			$subject,
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		if ( ! $sent ) {
			error_log( 'BizCity_Notify_Dispatcher (Email): wp_mail returned false.' );
		}
	}

	// -------------------------------------------------------------------------
	// Settings loader (lazy, cached per-request)
	// -------------------------------------------------------------------------

	/**
	 * @return array
	 */
	private static function get_settings() {
		if ( null !== self::$settings ) {
			return self::$settings;
		}

		$raw = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		self::$settings = $raw;
		return self::$settings;
	}
}
