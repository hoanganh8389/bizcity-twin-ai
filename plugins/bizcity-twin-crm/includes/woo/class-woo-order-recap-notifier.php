<?php
/**
 * BizCity CRM — Woo Order Recap Notifier (Phase 0.38 Order Fulfillment Hub).
 *
 * Lắng nghe các event đơn hàng và gửi recap message đến đúng kênh qua
 * BizCity_Gateway_Sender. Log mỗi lần gửi vào bizcity_crm_order_recap_log.
 *
 * ## Recap types (5)
 *  - new_order         → fired by action.create_woo_order (bizcity_crm_order_created)
 *  - payment_received  → woocommerce_payment_complete
 *  - processing        → woocommerce_order_status_processing
 *  - shipped           → woocommerce_order_status_completed (mapping VN: completed = shipped+delivered)
 *                        OR woocommerce_order_status_on-hold→completed (nếu COD)
 *  - delivered         → custom hook bizcity_crm_order_delivered (fired by shipping tracker W4)
 *                        fallback: woocommerce_order_status_completed khi có _tracking_number
 *
 * ## Platform detection
 *  Đọc _bizcity_inbound_platform + _bizcity_inbound_chat_id từ Woo order meta.
 *  Nếu không có → skip (đơn không có inbound channel context).
 *
 * ## Template resolution
 *  Template files: includes/woo/recap-templates/<type>-<platform>.php
 *  Fallback: <type>-webchat.php nếu platform không có template riêng.
 *
 * @package    BizCity_Twin_CRM\Woo
 * @since      PHASE-0.38.W2.1 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Order_Recap_Notifier' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W2.1 — Order Recap Notifier: hooks + send + log
final class BizCity_CRM_Woo_Order_Recap_Notifier {

	/** Singleton */
	private static $instance = null;

	/** @var string Absolute path to recap-templates/ dir */
	private $tpl_dir;

	/** @var string Store name (lazily resolved) */
	private $store_name = '';

	private function __construct() {
		$this->tpl_dir = __DIR__ . '/recap-templates/';
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function boot(): void {
		$notifier = self::instance();

		// new_order — fired by action.create_woo_order block (W1.4).
		add_action( 'bizcity_crm_order_created', array( $notifier, 'on_new_order' ), 10, 3 );

		// payment_received
		add_action( 'woocommerce_payment_complete', array( $notifier, 'on_payment_received' ), 10, 1 );

		// processing
		add_action( 'woocommerce_order_status_processing', array( $notifier, 'on_processing' ), 10, 2 );

		// shipped / delivered via Woo status completed.
		add_action( 'woocommerce_order_status_completed', array( $notifier, 'on_completed' ), 10, 2 );

		// Custom delivered hook (fired by W4 Shipping Tracker when status = DELIVERED).
		add_action( 'bizcity_crm_order_delivered', array( $notifier, 'on_delivered' ), 10, 2 );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Hook handlers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param int    $order_id
	 * @param object $order    WC_Order instance.
	 * @param array  $ctx      Extra context (platform, chat_id, tracking_url, payment_url).
	 */
	public function on_new_order( $order_id, $order = null, array $ctx = array() ): void {
		$order_id = (int) $order_id;
		if ( ! $order instanceof WC_Abstract_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) { return; }

		// Use ctx passed by action block (may override meta).
		$platform    = isset( $ctx['platform'] ) ? (string) $ctx['platform'] : '';
		$chat_id     = isset( $ctx['chat_id'] )  ? (string) $ctx['chat_id']  : '';
		$tracking_url = isset( $ctx['tracking_url'] ) ? (string) $ctx['tracking_url'] : '';
		$payment_url  = isset( $ctx['payment_url'] )  ? (string) $ctx['payment_url']  : '';

		// Fallback to order meta.
		if ( $platform === '' ) {
			$platform = (string) $order->get_meta( '_bizcity_inbound_platform', true );
		}
		if ( $chat_id === '' ) {
			$chat_id = (string) $order->get_meta( '_bizcity_inbound_chat_id', true );
		}

		$this->send_recap( $order, 'new_order', $platform, $chat_id, array(
			'payment_url'  => $payment_url,
			'tracking_url' => $tracking_url,
		) );
	}

	public function on_payment_received( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) { return; }
		$this->send_from_order_meta( $order, 'payment_received' );
	}

	public function on_processing( $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Abstract_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order ) { return; }
		$this->send_from_order_meta( $order, 'processing' );
	}

	/**
	 * Woo 'completed' maps to either 'shipped' (has tracking) or 'delivered' (no tracker / manual).
	 */
	public function on_completed( $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Abstract_Order ) {
			$order = wc_get_order( (int) $order_id );
		}
		if ( ! $order ) { return; }

		$tracking = (string) $order->get_meta( '_tracking_number', true );
		$recap_type = ( $tracking !== '' ) ? 'shipped' : 'delivered';
		$this->send_from_order_meta( $order, $recap_type );
	}

	/**
	 * Explicit delivered hook from W4 Shipping Tracker.
	 *
	 * @param int   $order_id
	 * @param array $extra    May contain tracking_number, provider.
	 */
	public function on_delivered( $order_id, array $extra = array() ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) { return; }
		$this->send_from_order_meta( $order, 'delivered', $extra );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Core send logic
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Read platform/chat_id from order meta and delegate to send_recap().
	 *
	 * @param WC_Order $order
	 * @param string   $recap_type
	 * @param array    $extra_vars   Additional template vars.
	 */
	private function send_from_order_meta( $order, string $recap_type, array $extra_vars = array() ): void {
		$platform = (string) $order->get_meta( '_bizcity_inbound_platform', true );
		$chat_id  = (string) $order->get_meta( '_bizcity_inbound_chat_id',  true );
		$this->send_recap( $order, $recap_type, $platform, $chat_id, $extra_vars );
	}

	/**
	 * Build template vars, load template, send, log result.
	 *
	 * @param WC_Order $order
	 * @param string   $recap_type   one of: new_order, payment_received, processing, shipped, delivered
	 * @param string   $platform     FACEBOOK|ZALO|WEBCHAT|...
	 * @param string   $chat_id
	 * @param array    $extra_vars   Merge into $vars (caller-supplied overrides).
	 */
	private function send_recap( $order, string $recap_type, string $platform, string $chat_id, array $extra_vars = array() ): void {
		if ( $chat_id === '' || $platform === '' ) {
			// No inbound context — skip silently (not an error).
			return;
		}

		// Guard: deduplicate — don't resend same type for same order within 60 s.
		if ( $this->already_sent_recently( (int) $order->get_id(), $recap_type, 60 ) ) {
			return;
		}

		// Build template vars.
		$tracking_number = (string) $order->get_meta( '_tracking_number', true );
		$provider        = (string) $order->get_meta( '_tracking_provider', true );
		$public_token    = (string) $order->get_meta( '_bizcity_public_token', true );
		$tracking_url    = $public_token !== '' ? home_url( '/o/' . $public_token ) : ( $extra_vars['tracking_url'] ?? '' );
		$payment_url     = $order->needs_payment() ? $order->get_checkout_payment_url() : ( $extra_vars['payment_url'] ?? '' );

		$items_summary = $this->build_items_summary( $order );

		$vars = array(
			'order_number'      => $order->get_order_number(),
			'order_total'       => number_format( (float) $order->get_total(), 0, ',', '.' ),
			'currency'          => $order->get_currency(),
			'items_summary'     => $items_summary,
			'shipping_name'     => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'shipping_phone'    => $order->get_billing_phone(),
			'shipping_addr1'    => $order->get_shipping_address_1(),
			'shipping_city'     => $order->get_shipping_city(),
			'payment_method_title' => $order->get_payment_method_title(),
			'payment_url'       => $payment_url,
			'tracking_url'      => $tracking_url,
			'tracking_number'   => $tracking_number,
			'shipping_provider' => $provider,
			'store_name'        => $this->get_store_name(),
		);
		// Merge extra vars (caller overrides).
		foreach ( $extra_vars as $k => $val ) {
			if ( isset( $vars[ $k ] ) ) {
				$vars[ $k ] = (string) $val;
			}
		}

		// Load template.
		$message = $this->render_template( $recap_type, $platform, $vars );
		if ( $message === '' ) {
			$this->log_recap( (int) $order->get_id(), $recap_type, $platform, $chat_id, 'failed', 'Template not found' );
			return;
		}

		// Send.
		$gateway_msg_id = '';
		$send_error     = '';
		$sent           = false;

		if ( class_exists( 'BizCity_Gateway_Sender' ) ) {
			$result = BizCity_Gateway_Sender::instance()->send( $chat_id, $message );
			$sent   = ! empty( $result['sent'] );
			if ( ! $sent ) {
				$send_error = isset( $result['error'] ) ? (string) $result['error'] : 'unknown';
			}
		} else {
			$send_error = 'BizCity_Gateway_Sender not loaded';
		}

		// Log.
		$this->log_recap(
			(int) $order->get_id(),
			$recap_type,
			$platform,
			$chat_id,
			$sent ? 'sent' : 'failed',
			$send_error,
			$gateway_msg_id
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Render a recap template file and return the message string.
	 *
	 * @param string $recap_type
	 * @param string $platform    Raw platform string (e.g. FACEBOOK, zalo, WEBCHAT).
	 * @param array  $vars
	 * @return string  Empty string if no template found.
	 */
	private function render_template( string $recap_type, string $platform, array $vars ): string {
		$slug     = strtolower( $platform );
		// Normalize common aliases.
		$alias_map = array(
			'fb'       => 'facebook',
			'facebook' => 'facebook',
			'zalo'     => 'zalo',
			'webchat'  => 'webchat',
			'telegram' => 'webchat', // fallback
		);
		$slug = isset( $alias_map[ $slug ] ) ? $alias_map[ $slug ] : 'webchat';

		$file = $this->tpl_dir . $recap_type . '-' . $slug . '.php';

		// Fallback to webchat.
		if ( ! file_exists( $file ) ) {
			$file = $this->tpl_dir . $recap_type . '-webchat.php';
		}
		if ( ! file_exists( $file ) ) {
			return '';
		}

		$fn = require $file;
		if ( ! is_callable( $fn ) ) {
			return '';
		}
		$msg = call_user_func( $fn, $vars );
		return is_string( $msg ) ? trim( $msg ) : '';
	}

	/**
	 * Build a short items summary string (max 5 items, then "+N more").
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	private function build_items_summary( $order ): string {
		$parts = array();
		$i     = 0;
		foreach ( $order->get_items() as $item ) {
			if ( $i >= 5 ) {
				$remaining = count( $order->get_items() ) - 5;
				$parts[]   = "... +{$remaining} sản phẩm khác";
				break;
			}
			$name = $item->get_name();
			$qty  = $item->get_quantity();
			$parts[] = "  • {$name} × {$qty}";
			$i++;
		}
		return implode( "\n", $parts );
	}

	/**
	 * Check if a recap of $type was sent for this order in the last $within_seconds.
	 *
	 * @param int    $order_id
	 * @param string $recap_type
	 * @param int    $within_seconds
	 * @return bool
	 */
	private function already_sent_recently( int $order_id, string $recap_type, int $within_seconds ): bool {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_crm_order_recap_log';
		$since = gmdate( 'Y-m-d H:i:s', time() - $within_seconds );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$tbl}` WHERE order_id=%d AND recap_type=%s AND status='sent' AND sent_at >= %s",
			$order_id, $recap_type, $since
		) );
		return $count > 0;
	}

	/**
	 * Insert a row into bizcity_crm_order_recap_log.
	 *
	 * @param int    $order_id
	 * @param string $recap_type
	 * @param string $platform
	 * @param string $chat_id
	 * @param string $status          sent|failed
	 * @param string $error
	 * @param string $gateway_msg_id
	 */
	private function log_recap( int $order_id, string $recap_type, string $platform, string $chat_id, string $status, string $error = '', string $gateway_msg_id = '' ): void {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_crm_order_recap_log';
		$wpdb->insert( $tbl, array(
			'order_id'       => $order_id,
			'recap_type'     => $recap_type,
			'platform'       => $platform,
			'chat_id'        => $chat_id,
			'gateway_msg_id' => $gateway_msg_id !== '' ? $gateway_msg_id : null,
			'status'         => $status,
			'error'          => $error !== '' ? $error : null,
			'sent_at'        => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}

	/**
	 * Lazily get store name (get_bloginfo is cheap but avoid calling on every instantiation).
	 *
	 * @return string
	 */
	private function get_store_name(): string {
		if ( $this->store_name === '' ) {
			$this->store_name = get_bloginfo( 'name' );
		}
		return $this->store_name;
	}
}
