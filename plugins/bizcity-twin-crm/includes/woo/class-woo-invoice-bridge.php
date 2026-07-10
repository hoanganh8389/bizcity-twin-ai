<?php
/**
 * BizCity CRM — Woo Invoice Bridge (PHASE 0.35 M-CRM.M8.W4).
 *
 * Mirrors WC_Order status changes onto matching `bizcity_crm_invoices`
 * rows when the option `bizcity_crm_woo_auto_invoice` is enabled.
 * The invoice's `wc_order_id` column (added in migrate_phase_040) is
 * the link.
 *
 * Status mirror map (Woo → CRM invoice):
 *   processing → sent
 *   completed  → paid
 *   on-hold    → sent
 *   refunded   → refunded
 *   failed     → voided
 *   cancelled  → voided
 *
 * Loop guard: a static `$mirror_in_flight` flag prevents the
 * payment-flip in {@see BizCity_CRM_Invoice_Repository::record_payment()}
 * from looping back into Woo when it triggers `crm_invoice_paid`.
 *
 * Auto-create policy: if `bizcity_crm_woo_auto_invoice` is on AND the
 * order has no linked invoice yet, a draft invoice is created on the
 * `processing` transition (NOT on `pending` to avoid orphans from
 * abandoned carts).
 *
 * @package BizCity_Twin_CRM\Woo
 * @since   1.11.0 (2026-05-13)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Woo_Invoice_Bridge' ) ) { return; }

final class BizCity_CRM_Woo_Invoice_Bridge {

	private static bool $mirror_in_flight = false;

	const STATUS_MAP = array(
		'processing' => 'sent',
		'on-hold'    => 'sent',
		'completed'  => 'paid',
		'refunded'   => 'refunded',
		'failed'     => 'voided',
		'cancelled'  => 'voided',
	);

	public static function register(): void {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 4 );
	}

	/**
	 * @param int $order_id
	 * @param string $from
	 * @param string $to
	 * @param mixed $order WC_Order
	 */
	public static function on_order_status_changed( int $order_id, string $from, string $to, $order ): void {
		if ( self::$mirror_in_flight ) { return; }
		if ( ! is_object( $order ) ) {
			if ( ! function_exists( 'wc_get_order' ) ) { return; }
			$order = wc_get_order( $order_id );
			if ( ! $order ) { return; }
		}

		$auto = (bool) get_option( 'bizcity_crm_woo_auto_invoice', false );
		$invoice_id = self::find_invoice_for_order( $order_id );

		if ( ! $invoice_id && $auto && $to === 'processing' ) {
			$invoice_id = self::create_invoice_from_order( $order );
		}
		if ( ! $invoice_id ) { return; }

		$new_status = self::STATUS_MAP[ $to ] ?? null;
		if ( ! $new_status ) { return; }
		if ( ! class_exists( 'BizCity_CRM_Invoice_Repository' ) ) { return; }

		self::$mirror_in_flight = true;
		try {
			BizCity_CRM_Invoice_Repository::transition( $invoice_id, $new_status );
		} catch ( \Throwable $e ) {
			// Invalid transition is fine (state machine guards it). Log via Twin Event.
			do_action( 'bizcity_crm_woo_invoice_mirror_skipped', array(
				'invoice_id' => $invoice_id,
				'order_id'   => $order_id,
				'from'       => $from,
				'to'         => $to,
				'mapped_to'  => $new_status,
				'reason'     => $e->getMessage(),
			) );
		} finally {
			self::$mirror_in_flight = false;
		}
	}

	public static function in_flight(): bool { return self::$mirror_in_flight; }

	/* ---------------------------------------------------------------- */
	/* Lookups                                                          */
	/* ---------------------------------------------------------------- */

	public static function find_invoice_for_order( int $order_id ): int {
		if ( $order_id <= 0 ) { return 0; }
		global $wpdb;
		$tbl = BizCity_CRM_DB_Installer_V2::tbl_crm_invoices();
		// Defensive: column exists only after migrate_phase_040.
		$col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$tbl}` LIKE %s", 'wc_order_id' ) );
		if ( ! $col ) { return 0; }
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM `{$tbl}` WHERE wc_order_id=%d AND deleted_at IS NULL LIMIT 1",
			$order_id
		) );
	}

	/**
	 * Create a draft+sent invoice from a paid-flow Woo order. Skipped if
	 * the bridge is missing the customer link (so we never create a
	 * dangling invoice). Returns invoice_id or 0.
	 */
	public static function create_invoice_from_order( $order ): int {
		if ( ! class_exists( 'BizCity_CRM_Invoice_Repository' ) ) { return 0; }
		if ( ! class_exists( 'BizCity_CRM_Woo_Customer_Bridge' ) ) { return 0; }

		$contact_id = BizCity_CRM_Woo_Customer_Bridge::resolve_contact_for_order( $order );
		if ( ! $contact_id ) { return 0; }

		$lines = array();
		foreach ( $order->get_items() as $it ) {
			$lines[] = array(
				'description' => (string) $it->get_name(),
				'quantity'    => (float) ( method_exists( $it, 'get_quantity' ) ? $it->get_quantity() : 1 ),
				'unit_price'  => (float) ( method_exists( $it, 'get_subtotal' ) ? ( $it->get_subtotal() / max( 1, (int) $it->get_quantity() ) ) : 0 ),
			);
		}

		try {
			$invoice_id = BizCity_CRM_Invoice_Repository::create( array(
				'contact_id' => $contact_id,
				'currency'   => (string) $order->get_currency(),
				'issue_date' => current_time( 'Y-m-d' ),
				'lines'      => $lines,
				'notes'      => 'Tự động tạo từ đơn Woo #' . $order->get_id(),
			) );
		} catch ( \Throwable $e ) {
			do_action( 'bizcity_crm_woo_invoice_create_failed', array(
				'order_id' => $order->get_id(),
				'reason'   => $e->getMessage(),
			) );
			return 0;
		}

		if ( $invoice_id ) {
			BizCity_CRM_Invoice_Repository::link_to_woo_order( $invoice_id, (int) $order->get_id() );
			// Move draft → sent so the status mirror can advance to paid/refunded.
			try {
				BizCity_CRM_Invoice_Repository::transition( $invoice_id, BizCity_CRM_Invoice_Repository::STATUS_SENT );
			} catch ( \Throwable $e ) { /* state already advanced */ }
		}
		return (int) $invoice_id;
	}
}
