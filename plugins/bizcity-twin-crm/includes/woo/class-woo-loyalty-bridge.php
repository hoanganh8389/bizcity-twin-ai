<?php
/**
 * BizCity CRM — Loyalty Bridge (Phase 0.38 W4.2).
 *
 * Bridges order lifecycle events to the loyalty/points system
 * (currently `bizcity-twin-crm/includes/campaigns/class-loyalty-bridge.php`).
 *
 * Hooks:
 *   woocommerce_payment_complete → award payment_points  (if loyalty enabled)
 *   bizcity_crm_order_delivered  → award delivery_points (if loyalty enabled)
 *
 * This class is a THIN bridge — all business logic stays in the existing
 * BizCity_CRM_Loyalty_Bridge. It only translates Woo order context into
 * the loyalty event format expected by the existing bridge.
 *
 * Guards:
 *   - class_exists('BizCity_CRM_Loyalty_Bridge') before any call.
 *   - function_exists('wc_get_order') before any Woo call.
 *   - Idempotent: loyalty bridge is responsible for de-dup.
 *
 * @package    BizCity_Twin_CRM\Woo
 * @since      PHASE-0.38.W4.2 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Loyalty_Bridge' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W4.2 — thin loyalty bridge: order events → points
final class BizCity_CRM_Woo_Loyalty_Bridge {

	public static function boot(): void {
		add_action( 'woocommerce_payment_complete',  array( __CLASS__, 'on_payment' ),   10, 1 );
		add_action( 'bizcity_crm_order_delivered',   array( __CLASS__, 'on_delivered' ), 10, 2 );
		add_action( 'bizcity_crm_order_created',     array( __CLASS__, 'on_created' ),   10, 3 );
	}

	/**
	 * Award points on payment completion.
	 *
	 * @param int $order_id
	 */
	public static function on_payment( $order_id ): void {
		if ( ! self::loyalty_ready() ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order ) {
			return;
		}
		$contact_id = self::resolve_contact_id( $order );
		if ( $contact_id <= 0 ) {
			return;
		}
		/**
		 * @see BizCity_CRM_Loyalty_Bridge::award_points()
		 * Expected signature: award_points(int $contact_id, string $event_type, array $ctx)
		 */
		if ( method_exists( 'BizCity_CRM_Loyalty_Bridge', 'award_points' ) ) {
			BizCity_CRM_Loyalty_Bridge::award_points( $contact_id, 'order_payment', array(
				'order_id'    => (int) $order->get_id(),
				'order_total' => (float) $order->get_total(),
				'currency'    => $order->get_currency(),
				'source'      => 'woo_order',
			) );
		}
	}

	/**
	 * Award bonus points on delivery confirmation.
	 *
	 * @param int   $order_id
	 * @param array $extra
	 */
	public static function on_delivered( $order_id, array $extra = array() ): void {
		if ( ! self::loyalty_ready() ) {
			return;
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
		if ( ! $order ) {
			return;
		}
		$contact_id = self::resolve_contact_id( $order );
		if ( $contact_id <= 0 ) {
			return;
		}
		if ( method_exists( 'BizCity_CRM_Loyalty_Bridge', 'award_points' ) ) {
			BizCity_CRM_Loyalty_Bridge::award_points( $contact_id, 'order_delivered', array(
				'order_id'    => (int) $order->get_id(),
				'order_total' => (float) $order->get_total(),
				'currency'    => $order->get_currency(),
				'source'      => 'woo_order',
			) );
		}
	}

	/**
	 * Hook into order created to link Woo order → CRM contact if possible.
	 *
	 * @param int    $order_id
	 * @param object $order     WC_Order.
	 * @param array  $ctx
	 */
	public static function on_created( $order_id, $order = null, array $ctx = array() ): void {
		// No points on creation — just opportunity to link contact.
		// Future: assign contact_id to order for loyalty de-dup.
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return bool
	 */
	private static function loyalty_ready(): bool {
		return class_exists( 'BizCity_CRM_Loyalty_Bridge' );
	}

	/**
	 * Resolve CRM contact_id from Woo order meta / billing email.
	 *
	 * @param object $order  WC_Order.
	 * @return int  0 if not found.
	 */
	private static function resolve_contact_id( $order ): int {
		// 1) Try meta set by action block or previous sync.
		$cid = (int) $order->get_meta( '_bizcity_crm_contact_id', true );
		if ( $cid > 0 ) {
			return $cid;
		}

		// 2) Lookup by billing email via CRM contacts table.
		$email = $order->get_billing_email();
		if ( $email !== '' && class_exists( 'BizCity_CRM_Contact' ) && method_exists( 'BizCity_CRM_Contact', 'find_by_email' ) ) {
			$contact = BizCity_CRM_Contact::find_by_email( $email );
			if ( $contact && isset( $contact->id ) ) {
				return (int) $contact->id;
			}
		}

		// 3) Lookup by inbound chat_id from order meta.
		$chat_id = (string) $order->get_meta( '_bizcity_inbound_chat_id', true );
		if ( $chat_id !== '' ) {
			global $wpdb;
			$tbl = $wpdb->prefix . 'bizcity_crm_contacts';
			$cid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$tbl}` WHERE chat_id = %s LIMIT 1",
				$chat_id
			) );
			if ( $cid > 0 ) {
				return $cid;
			}
		}

		return 0;
	}
}
