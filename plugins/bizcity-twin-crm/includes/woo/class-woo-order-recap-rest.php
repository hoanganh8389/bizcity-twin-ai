<?php
/**
 * BizCity CRM — Order Recap REST Controller (Phase 0.38 W2.3).
 *
 * Namespace: bizcity-crm/v1
 *
 * Endpoints:
 *   POST /orders/{id}/resend-recap   — Resend or send a recap of specified type
 *                                      to the order's inbound channel.
 *   GET  /orders/{id}/recap-log      — Fetch recap send history for an order.
 *
 * Auth: manage_woocommerce (shop manager / admin).
 *
 * @package    BizCity_Twin_CRM\Woo
 * @since      PHASE-0.38.W2.3 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Order_Recap_REST' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W2.3 — REST: resend-recap + recap-log endpoints
final class BizCity_CRM_Order_Recap_REST {

	public static function register_routes(): void {
		$ns = BIZCITY_CRM_REST_NS; // bizcity-crm/v1

		// POST /orders/{id}/resend-recap
		register_rest_route( $ns, '/orders/(?P<id>\d+)/resend-recap', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'resend_recap' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'args'                => array(
				'id'          => array(
					'validate_callback' => 'is_numeric',
					'sanitize_callback' => 'absint',
					'required'          => true,
				),
				'recap_type'  => array(
					'type'              => 'string',
					'enum'              => array( 'new_order', 'payment_received', 'processing', 'shipped', 'delivered' ),
					'required'          => false,
					'default'           => 'new_order',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'platform'    => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'chat_id'     => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// GET /orders/{id}/recap-log
		register_rest_route( $ns, '/orders/(?P<id>\d+)/recap-log', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_recap_log' ),
			'permission_callback' => array( __CLASS__, 'can_manage' ),
			'args'                => array(
				'id' => array(
					'validate_callback' => 'is_numeric',
					'sanitize_callback' => 'absint',
					'required'          => true,
				),
			),
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Handlers
	// ─────────────────────────────────────────────────────────────────────────

	public static function resend_recap( WP_REST_Request $request ) {
		$order_id   = (int) $request->get_param( 'id' );
		$recap_type = (string) $request->get_param( 'recap_type' );
		$platform   = (string) $request->get_param( 'platform' );
		$chat_id    = (string) $request->get_param( 'chat_id' );

		if ( ! function_exists( 'wc_get_order' ) ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return rest_ensure_response( BizCity_Error_Payload::make(
					'module_not_loaded',
					'WooCommerce chưa kích hoạt.',
					'Kích hoạt WooCommerce để sử dụng tính năng này.',
					'woo_not_active'
				) );
			}
			return new WP_Error( 'module_not_loaded', 'WooCommerce not active.', array( 'status' => 500 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return rest_ensure_response( BizCity_Error_Payload::make(
					'not_found',
					"Không tìm thấy đơn hàng #{$order_id}.",
					'Kiểm tra lại Order ID.',
					'order_not_found'
				) );
			}
			return new WP_Error( 'not_found', "Order #{$order_id} not found.", array( 'status' => 404 ) );
		}

		// Resolve platform / chat_id: request params override order meta.
		if ( $platform === '' ) {
			$platform = (string) $order->get_meta( '_bizcity_inbound_platform', true );
		}
		if ( $chat_id === '' ) {
			$chat_id = (string) $order->get_meta( '_bizcity_inbound_chat_id', true );
		}

		if ( $platform === '' || $chat_id === '' ) {
			if ( class_exists( 'BizCity_Error_Payload' ) ) {
				return rest_ensure_response( BizCity_Error_Payload::make(
					'invalid_param',
					'Đơn hàng này không có thông tin kênh gửi (platform/chat_id).',
					'Nhập platform và chat_id vào body request hoặc đặt order meta _bizcity_inbound_platform + _bizcity_inbound_chat_id.',
					'order_no_inbound_channel'
				) );
			}
			return new WP_Error( 'invalid_param', 'No inbound channel context.', array( 'status' => 400 ) );
		}

		if ( ! class_exists( 'BizCity_CRM_Woo_Order_Recap_Notifier' ) ) {
			return new WP_Error( 'module_not_loaded', 'Recap notifier not loaded.', array( 'status' => 500 ) );
		}

		$notifier = BizCity_CRM_Woo_Order_Recap_Notifier::instance();

		// Dispatch correct method based on recap_type.
		switch ( $recap_type ) {
			case 'new_order':
				$notifier->on_new_order( $order_id, $order, array(
					'platform' => $platform,
					'chat_id'  => $chat_id,
				) );
				break;
			case 'payment_received':
				$notifier->on_payment_received( $order_id );
				break;
			case 'processing':
				$notifier->on_processing( $order_id, $order );
				break;
			case 'shipped':
				// Temporarily set order meta override so on_completed picks shipped type.
				$notifier->on_completed( $order_id, $order );
				break;
			case 'delivered':
				$notifier->on_delivered( $order_id );
				break;
		}

		return rest_ensure_response( array(
			'ok'         => true,
			'order_id'   => $order_id,
			'recap_type' => $recap_type,
			'platform'   => $platform,
			'chat_id'    => $chat_id,
		) );
	}

	public static function get_recap_log( WP_REST_Request $request ) {
		$order_id = (int) $request->get_param( 'id' );
		global $wpdb;
		$tbl  = $wpdb->prefix . 'bizcity_crm_order_recap_log';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, recap_type, platform, chat_id, status, error, sent_at
			   FROM `{$tbl}`
			  WHERE order_id = %d
			  ORDER BY sent_at DESC
			  LIMIT 100",
			$order_id
		), ARRAY_A );

		return rest_ensure_response( array(
			'ok'       => true,
			'order_id' => $order_id,
			'rows'     => $rows ? $rows : array(),
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Permission
	// ─────────────────────────────────────────────────────────────────────────

	public static function can_manage(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}
}
