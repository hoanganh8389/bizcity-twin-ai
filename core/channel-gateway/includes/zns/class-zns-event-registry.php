<?php
/**
 * ZNS Event Registry — Khai báo tất cả trigger events + normalize callables.
 *
 * Mọi event được khai báo ở đây, bao gồm:
 * - WooCommerce (đơn hàng các trạng thái)
 * - Contact Form 7
 * - WordPress Users
 * - BizCity CRM
 * - Custom / OTP
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — new class.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-ZNS-AUTO (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_ZNS_Event_Registry' ) ) {
	return;
}

class BizCity_ZNS_Event_Registry {

	/**
	 * Trả về danh sách tất cả trigger events.
	 * Mỗi event gồm: key, label, group, hook, hook_args, placeholders, phone_path, normalize.
	 *
	 * @return array
	 */
	public static function get_all_events() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — full event list
		$events = array(

			// ── WooCommerce ────────────────────────────────────────────────
			array(
				'key'          => 'woo_order_created',
				'label'        => 'WooCommerce: Đơn hàng vừa tạo',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_checkout_order_created',
				'hook_args'    => 1,
				'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_name', 'customer_phone', 'billing_address', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order' ),
			),
			array(
				'key'          => 'woo_payment_complete',
				'label'        => 'WooCommerce: Thanh toán thành công',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_payment_complete',
				'hook_args'    => 1,
				'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_name', 'customer_phone', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order_id' ),
			),
			array(
				'key'          => 'woo_order_processing',
				'label'        => 'WooCommerce: Đơn đang xử lý',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_order_status_processing',
				'hook_args'    => 2,
				'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_name', 'customer_phone', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order_id' ),
			),
			array(
				'key'          => 'woo_order_completed',
				'label'        => 'WooCommerce: Giao hàng hoàn tất',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_order_status_completed',
				'hook_args'    => 2,
				'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_name', 'customer_phone', 'shipping_address', 'product_names', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order_id' ),
			),
			array(
				'key'          => 'woo_order_cancelled',
				'label'        => 'WooCommerce: Đơn bị hủy',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_order_status_cancelled',
				'hook_args'    => 2,
				'placeholders' => array( 'order_id', 'order_number', 'customer_name', 'customer_phone', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order_id' ),
			),
			array(
				'key'          => 'woo_order_refunded',
				'label'        => 'WooCommerce: Hoàn tiền',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_order_status_refunded',
				'hook_args'    => 2,
				'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_name', 'customer_phone', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order_id' ),
			),
			array(
				'key'          => 'woo_order_on_hold',
				'label'        => 'WooCommerce: Đơn chờ xác nhận',
				'group'        => 'woocommerce',
				'hook'         => 'woocommerce_order_status_on-hold',
				'hook_args'    => 2,
				'placeholders' => array( 'order_id', 'order_number', 'order_total', 'customer_name', 'customer_phone', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_woo_order_id' ),
			),

			// ── Contact Form 7 ─────────────────────────────────────────────
			array(
				'key'          => 'cf7_any_form',
				'label'        => 'CF7: Bất kỳ form nào submit',
				'group'        => 'cf7',
				'hook'         => 'wpcf7_mail_sent',
				'hook_args'    => 1,
				'placeholders' => array( 'form_title', 'form_id', 'phone', 'name', 'email', 'site_name' ),
				'phone_path'   => 'phone',
				'normalize'    => array( __CLASS__, 'normalize_cf7' ),
			),

			// ── WordPress Users ─────────────────────────────────────────────
			array(
				'key'          => 'user_registered',
				'label'        => 'WordPress: Đăng ký tài khoản mới',
				'group'        => 'wordpress',
				'hook'         => 'user_register',
				'hook_args'    => 2,
				'placeholders' => array( 'user_login', 'user_email', 'user_display_name', 'user_phone', 'site_name', 'site_url' ),
				'phone_path'   => 'user_phone',
				'normalize'    => array( __CLASS__, 'normalize_user_register' ),
			),
			array(
				'key'          => 'user_password_reset',
				'label'        => 'WordPress: Mật khẩu vừa được đặt lại',
				'group'        => 'wordpress',
				'hook'         => 'after_password_reset',
				'hook_args'    => 2,
				'placeholders' => array( 'user_login', 'user_display_name', 'user_phone', 'site_name' ),
				'phone_path'   => 'user_phone',
				'normalize'    => array( __CLASS__, 'normalize_user_obj' ),
			),

			// ── CRM ─────────────────────────────────────────────────────────
			array(
				'key'          => 'crm_contact_created',
				'label'        => 'CRM: Contact mới được tạo',
				'group'        => 'crm',
				'hook'         => 'bizcity_crm_contact_saved',
				'hook_args'    => 2,
				'placeholders' => array( 'contact_name', 'contact_phone', 'contact_email', 'contact_id', 'site_name' ),
				'phone_path'   => 'contact_phone',
				'normalize'    => array( __CLASS__, 'normalize_crm_contact' ),
			),
			array(
				'key'          => 'crm_invoice_paid',
				'label'        => 'CRM: Hóa đơn đã thanh toán',
				'group'        => 'crm',
				'hook'         => 'bizcity_crm_invoice_paid',
				'hook_args'    => 2,
				'placeholders' => array( 'invoice_number', 'invoice_total', 'customer_name', 'customer_phone', 'site_name' ),
				'phone_path'   => 'customer_phone',
				'normalize'    => array( __CLASS__, 'normalize_crm_invoice' ),
			),

			// ── Custom / OTP ─────────────────────────────────────────────────
			array(
				'key'          => 'bizcity_otp_requested',
				'label'        => 'Custom: OTP được tạo',
				'group'        => 'custom',
				'hook'         => 'bizcity_otp_requested',
				'hook_args'    => 1,
				'placeholders' => array( 'phone', 'otp_code', 'expires_in', 'user_name', 'site_name' ),
				'phone_path'   => 'phone',
				'normalize'    => array( __CLASS__, 'normalize_passthrough' ),
			),
			array(
				'key'          => 'bizcity_appointment_confirmed',
				'label'        => 'Custom: Hẹn lịch xác nhận',
				'group'        => 'custom',
				'hook'         => 'bizcity_appointment_confirmed',
				'hook_args'    => 1,
				'placeholders' => array( 'phone', 'appointment_date', 'appointment_time', 'user_name', 'service_name', 'site_name' ),
				'phone_path'   => 'phone',
				'normalize'    => array( __CLASS__, 'normalize_passthrough' ),
			),
		);

		/**
		 * Filter: cho phép plugin khác đăng ký thêm ZNS events.
		 *
		 * @param array $events Array of event definitions.
		 */
		return apply_filters( 'bizcity_zns_event_registry', $events );
	}

	/**
	 * Trả về per-form CF7 events từ danh sách form đã cấu hình ZNS.
	 * key = 'cf7_form_{form_id}'
	 *
	 * @return array
	 */
	public static function get_cf7_events() {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — dynamic per-form events
		if ( ! function_exists( 'wpcf7_get_contact_form_list' ) && ! class_exists( 'WPCF7_ContactForm' ) ) {
			return array();
		}
		$args  = array( 'posts_per_page' => 200, 'post_status' => 'publish' );
		$forms = get_posts( array_merge( $args, array( 'post_type' => 'wpcf7_contact_form' ) ) );

		$events = array();
		foreach ( $forms as $form ) {
			$fid      = (int) $form->ID;
			$events[] = array(
				'key'          => 'cf7_form_' . $fid,
				'label'        => 'CF7: ' . $form->post_title,
				'group'        => 'cf7',
				'hook'         => 'wpcf7_mail_sent',
				'hook_args'    => 1,
				'placeholders' => array( 'form_title', 'form_id', 'phone', 'name', 'email', 'site_name' ),
				'phone_path'   => 'phone',
				'normalize'    => array( __CLASS__, 'normalize_cf7' ),
				'cf7_form_id'  => $fid,
			);
		}
		return $events;
	}

	// ── Normalize callables ────────────────────────────────────────────────────

	/**
	 * WooCommerce: normalize từ WC_Order object (woocommerce_checkout_order_created).
	 *
	 * @param  mixed $order  WC_Order object.
	 * @return array { phone, placeholders{} }
	 */
	public static function normalize_woo_order( $order ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — WC_Order object
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_phone' ) ) {
			return array();
		}
		return array(
			'phone'        => (string) $order->get_billing_phone(),
			'placeholders' => array(
				'order_id'        => (string) $order->get_id(),
				'order_number'    => (string) $order->get_order_number(),
				'order_total'     => number_format( (float) $order->get_total(), 0, ',', '.' ),
				'customer_name'   => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'customer_phone'  => (string) $order->get_billing_phone(),
				'billing_address' => $order->get_formatted_billing_address(),
				'shipping_address'=> $order->get_formatted_shipping_address(),
				'product_names'   => self::get_order_product_names( $order ),
				'site_name'       => get_bloginfo( 'name' ),
			),
		);
	}

	/**
	 * WooCommerce: normalize từ order_id (woocommerce_order_status_*, woocommerce_payment_complete).
	 *
	 * @param  int   $order_id
	 * @param  mixed $order     Optional WC_Order object (second hook arg).
	 * @return array
	 */
	public static function normalize_woo_order_id( $order_id, $order = null ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — order_id normalize
		if ( ! function_exists( 'wc_get_order' ) ) {
			return array();
		}
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_billing_phone' ) ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order ) {
			return array();
		}
		return self::normalize_woo_order( $order );
	}

	/**
	 * CF7: normalize từ WPCF7_ContactForm (wpcf7_mail_sent).
	 *
	 * @param  mixed $cf7  WPCF7_ContactForm instance.
	 * @return array
	 */
	public static function normalize_cf7( $cf7 ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — CF7 normalize
		if ( ! is_object( $cf7 ) || ! method_exists( $cf7, 'id' ) ) {
			return array();
		}
		$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		$posted     = $submission ? $submission->get_posted_data() : array();

		// Extract phone from common field names
		$phone = '';
		foreach ( array( 'phone', 'dien-thoai', 'so-dien-thoai', 'tel', 'mobile', 'sdt', 'phonenumber' ) as $pf ) {
			if ( ! empty( $posted[ $pf ] ) ) {
				$phone = (string) $posted[ $pf ];
				break;
			}
		}

		$name  = (string) ( $posted['your-name'] ?? $posted['name'] ?? $posted['ho-ten'] ?? '' );
		$email = (string) ( $posted['your-email'] ?? $posted['email'] ?? '' );

		return array(
			'phone'        => $phone,
			'placeholders' => array(
				'form_id'    => (string) $cf7->id(),
				'form_title' => (string) $cf7->title(),
				'phone'      => $phone,
				'name'       => $name,
				'email'      => $email,
				'site_name'  => get_bloginfo( 'name' ),
			),
			'cf7_form_id'  => $cf7->id(),
			'posted'       => $posted,
		);
	}

	/**
	 * WordPress user register: normalize từ user_id + userdata array.
	 *
	 * @param  int   $user_id
	 * @param  array $userdata
	 * @return array
	 */
	public static function normalize_user_register( $user_id, $userdata = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — user_register normalize
		$user  = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return array();
		}
		$phone = (string) ( get_user_meta( $user_id, 'billing_phone', true ) ?: get_user_meta( $user_id, 'phone', true ) );
		return array(
			'phone'        => $phone,
			'user_id'      => $user_id,
			'placeholders' => array(
				'user_login'        => $user->user_login,
				'user_email'        => $user->user_email,
				'user_display_name' => $user->display_name,
				'user_phone'        => $phone,
				'site_name'         => get_bloginfo( 'name' ),
				'site_url'          => get_bloginfo( 'url' ),
			),
		);
	}

	/**
	 * WordPress user object normalize (after_password_reset).
	 *
	 * @param  mixed $user  WP_User object.
	 * @param  mixed $new_pass  (Unused — not forwarded to ZNS, security rule).
	 * @return array
	 */
	public static function normalize_user_obj( $user, $new_pass = '' ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — user object normalize
		if ( ! is_object( $user ) || empty( $user->ID ) ) {
			return array();
		}
		$uid   = (int) $user->ID;
		$phone = (string) ( get_user_meta( $uid, 'billing_phone', true ) ?: get_user_meta( $uid, 'phone', true ) );
		return array(
			'phone'        => $phone,
			'user_id'      => $uid,
			'placeholders' => array(
				'user_login'        => $user->user_login,
				'user_display_name' => $user->display_name,
				'user_phone'        => $phone,
				'site_name'         => get_bloginfo( 'name' ),
			),
		);
	}

	/**
	 * CRM contact normalize.
	 *
	 * @param  int   $contact_id
	 * @param  array $data
	 * @return array
	 */
	public static function normalize_crm_contact( $contact_id, $data = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — CRM contact normalize
		$phone = (string) ( $data['phone'] ?? '' );
		$name  = (string) ( $data['full_name'] ?? $data['name'] ?? '' );
		$email = (string) ( $data['email'] ?? '' );
		return array(
			'phone'        => $phone,
			'placeholders' => array(
				'contact_id'    => (string) $contact_id,
				'contact_name'  => $name,
				'contact_phone' => $phone,
				'contact_email' => $email,
				'site_name'     => get_bloginfo( 'name' ),
			),
		);
	}

	/**
	 * CRM invoice normalize.
	 *
	 * @param  int   $invoice_id
	 * @param  array $data
	 * @return array
	 */
	public static function normalize_crm_invoice( $invoice_id, $data = array() ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — CRM invoice normalize
		$phone = (string) ( $data['customer_phone'] ?? $data['phone'] ?? '' );
		return array(
			'phone'        => $phone,
			'placeholders' => array(
				'invoice_number'  => (string) ( $data['invoice_number'] ?? $invoice_id ),
				'invoice_total'   => (string) ( $data['total'] ?? '' ),
				'customer_name'   => (string) ( $data['customer_name'] ?? '' ),
				'customer_phone'  => $phone,
				'site_name'       => get_bloginfo( 'name' ),
			),
		);
	}

	/**
	 * Passthrough normalize — custom hooks pass $payload array directly.
	 *
	 * @param  array $payload
	 * @return array
	 */
	public static function normalize_passthrough( $payload ) {
		// [2026-06-27 Johnny Chu] PHASE-CG-ZNS-AUTO — passthrough for custom hooks
		if ( ! is_array( $payload ) ) {
			return array();
		}
		$phone = (string) ( $payload['phone'] ?? '' );
		$placeholders = array_merge(
			array( 'site_name' => get_bloginfo( 'name' ) ),
			array_map( 'strval', $payload )
		);
		return array(
			'phone'        => $phone,
			'placeholders' => $placeholders,
		);
	}

	// ── Helpers ────────────────────────────────────────────────────────────────

	/**
	 * Trả về danh sách tên sản phẩm trong đơn hàng, ngăn cách bằng ", ".
	 *
	 * @param  mixed $order  WC_Order
	 * @return string
	 */
	private static function get_order_product_names( $order ) {
		$names = array();
		foreach ( $order->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		return implode( ', ', $names );
	}
}
