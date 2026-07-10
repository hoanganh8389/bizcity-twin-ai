<?php
/**
 * BizCity Channel Gateway — Order Public Tracking REST (Phase 0.38 W3.3).
 *
 * Namespace: bizcity-channel/v1  (R-CH-NS — public channel namespace, bypassed by mu-plugin)
 * No WP auth required — token is the bearer credential.
 *
 * Endpoints:
 *   GET  /order-tracking/{token}          — Fetch order tracking data for public page.
 *   POST /order-tracking/{token}/csat     — Submit CSAT rating (star + comment).
 *
 * Security:
 *   - Token verified via BizCity_CRM_Order_Public_Token::verify() (HMAC) on every request.
 *   - CSAT: rate-limited per IP via transient (1 submission per order per IP per 24h).
 *   - No PII exposed beyond what customer already knows (order number, status, items).
 *   - OWASP A05: no stack traces / SQL / internal IDs in error responses.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\ChannelGateway
 * @since      PHASE-0.38.W3.3 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CG_Order_Tracking_REST' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W3.3 — public order tracking REST (bizcity-channel/v1)
final class BizCity_CG_Order_Tracking_REST {

	const NS = 'bizcity-channel/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		// GET order tracking data.
		register_rest_route( self::NS, '/order-tracking/(?P<token>[A-Za-z0-9]{8,32})', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'get_tracking' ),
			'permission_callback' => '__return_true', // public, token = bearer
			'args'                => array(
				'token' => array(
					'validate_callback' => array( __CLASS__, 'validate_token_param' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// POST CSAT.
		register_rest_route( self::NS, '/order-tracking/(?P<token>[A-Za-z0-9]{8,32})/csat', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'submit_csat' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'token'   => array(
					'validate_callback' => array( __CLASS__, 'validate_token_param' ),
					'sanitize_callback' => 'sanitize_text_field',
				),
				'rating'  => array(
					'type'              => 'integer',
					'minimum'           => 1,
					'maximum'           => 5,
					'required'          => true,
				),
				'comment' => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Handlers
	// ─────────────────────────────────────────────────────────────────────────

	public static function get_tracking( WP_REST_Request $request ) {
		$token    = (string) $request->get_param( 'token' );
		$order_id = self::resolve_order( $token );
		if ( $order_id <= 0 ) {
			return rest_ensure_response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy đơn hàng.' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return rest_ensure_response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy đơn hàng.' ) );
		}

		// Build safe public payload (no PII beyond what customer already has).
		$tracking_number = (string) $order->get_meta( '_tracking_number',   true );
		$provider        = (string) $order->get_meta( '_tracking_provider', true );

		// Build items list (name + qty only, no price for privacy).
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name' => wp_strip_all_tags( $item->get_name() ),
				'qty'  => (int) $item->get_quantity(),
			);
		}

		// Status label (human-readable Vietnamese).
		$status       = $order->get_status();
		$status_label = self::status_label( $status );

		// Has customer submitted CSAT for this order already?
		$has_csat = false;
		if ( class_exists( 'BizCity_CRM_DB_Installer' ) ) {
			global $wpdb;
			$tbl      = $wpdb->prefix . 'bizcity_crm_order_csat';
			$has_csat = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$tbl}` WHERE order_id = %d",
				$order_id
			) ) > 0;
		}

		return rest_ensure_response( array(
			'ok'             => true,
			'order_number'   => $order->get_order_number(),
			'status'         => $status,
			'status_label'   => $status_label,
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			'items'          => $items,
			'tracking_number'=> $tracking_number,
			'provider'       => $provider,
			'store_name'     => get_bloginfo( 'name' ),
			'has_csat'       => $has_csat,
		) );
	}

	public static function submit_csat( WP_REST_Request $request ) {
		$token    = (string) $request->get_param( 'token' );
		$order_id = self::resolve_order( $token );
		if ( $order_id <= 0 ) {
			return rest_ensure_response( array( 'ok' => false, 'code' => 'not_found', 'message' => 'Không tìm thấy đơn hàng.' ) );
		}

		$rating  = (int) $request->get_param( 'rating' );
		$comment = (string) $request->get_param( 'comment' );

		// Rate-limit: 1 submission per order per IP per 24h.
		$ip          = self::get_client_ip();
		$rate_key    = 'bizcity_csat_' . md5( $order_id . '|' . $ip );
		if ( get_transient( $rate_key ) ) {
			return rest_ensure_response( array(
				'ok'      => false,
				'code'    => 'rate_limited',
				'message' => 'Bạn đã gửi đánh giá cho đơn hàng này rồi.',
			) );
		}

		// Insert.
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_crm_order_csat';
		$wpdb->insert( $tbl, array(
			'order_id'     => $order_id,
			'contact_id'   => null,
			'rating'       => $rating,
			'comment'      => $comment !== '' ? $comment : null,
			'source'       => 'public_tracking',
			'ip'           => substr( $ip, 0, 45 ), // max 45 chars (IPv6)
			'submitted_at' => current_time( 'mysql', true ),
		), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' ) );

		// Set rate-limit transient (24h).
		set_transient( $rate_key, 1, DAY_IN_SECONDS );

		return rest_ensure_response( array(
			'ok'      => true,
			'message' => 'Cảm ơn bạn đã đánh giá! Phản hồi của bạn rất quý giá với chúng tôi.',
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Resolve token to order_id, return 0 on failure.
	 *
	 * @param string $token
	 * @return int
	 */
	private static function resolve_order( string $token ): int {
		if ( ! class_exists( 'BizCity_CRM_Order_Public_Token' ) ) {
			return 0;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		return BizCity_CRM_Order_Public_Token::resolve( $token );
	}

	/**
	 * @param mixed $param
	 * @return bool
	 */
	public static function validate_token_param( $param ): bool {
		return is_string( $param ) && preg_match( '/^[A-Za-z0-9]{8,32}$/', (string) $param ) === 1;
	}

	/**
	 * Map Woo order status to Vietnamese label.
	 *
	 * @param string $status  Woo status slug (without wc- prefix).
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'pending'    => 'Chờ thanh toán',
			'processing' => 'Đang xử lý',
			'on-hold'    => 'Tạm giữ',
			'completed'  => 'Hoàn thành',
			'cancelled'  => 'Đã hủy',
			'refunded'   => 'Đã hoàn tiền',
			'failed'     => 'Thất bại',
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( $status );
	}

	/**
	 * Get client IP safely. Never returns empty string.
	 *
	 * @return string
	 */
	private static function get_client_ip(): string {
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( $forwarded !== '' ) {
			$parts = explode( ',', $forwarded );
			return trim( $parts[0] );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
	}
}
