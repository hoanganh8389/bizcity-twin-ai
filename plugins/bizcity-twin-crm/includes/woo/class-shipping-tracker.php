<?php
/**
 * BizCity CRM — Shipping Tracker Cron (Phase 0.38 W4.1).
 *
 * Polls Woo orders in 'processing' or 'on-hold' status that have a
 * `_tracking_number` meta set. For each order, checks if the Woo order
 * status has moved to 'completed' (which in the VN bridge plugin = delivered).
 *
 * When a status change is detected:
 *   1. Inserts a row into `bizcity_crm_shipment_status_log`.
 *   2. Fires `bizcity_crm_order_delivered` hook (if status is now completed).
 *   3. Fires `bizcity_crm_order_shipped` hook (if status becomes processing
 *      and tracking_number just appeared — e.g. COD flow).
 *
 * Design:
 *   - Runs every 30 minutes via WP-Cron.
 *   - Reads `_tracking_number` (set by Woo VN shipping bridge plugin — Q2 locked).
 *   - Does NOT call external shipping provider APIs — reads only local Woo state.
 *   - Idempotent: de-duplicates via `_bizcity_shipment_last_status` meta.
 *   - R-CRON-META: uses BizCity_Cron_Manager::note() / note_event().
 *
 * @package    BizCity_Twin_CRM\Woo
 * @since      PHASE-0.38.W4.1 (2026-06-07)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Shipping_Tracker' ) ) { return; }

// [2026-06-07 Johnny Chu] PHASE-0.38.W4.1 — shipping tracker cron: poll status changes
final class BizCity_CRM_Shipping_Tracker {

	const HOOK     = 'bizcity_crm_shipping_tracker_run';
	const INTERVAL = 'bizcity_every_30min';
	const JOB_ID   = 'crm.shipping_tracker';

	public static function boot(): void {
		// Register custom interval if not yet registered.
		add_filter( 'cron_schedules', array( __CLASS__, 'add_interval' ) );
		add_action( self::HOOK, array( __CLASS__, 'run' ) );

		// Register with BizCity_Cron_Manager (if available).
		add_action( 'init', array( __CLASS__, 'register_job' ), 20 );
	}

	public static function add_interval( array $schedules ): array {
		if ( ! isset( $schedules[ self::INTERVAL ] ) ) {
			$schedules[ self::INTERVAL ] = array(
				'interval' => 30 * MINUTE_IN_SECONDS,
				'display'  => 'Every 30 Minutes',
			);
		}
		return $schedules;
	}

	public static function register_job(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			// Fallback: schedule directly via WP-Cron.
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + 60, self::INTERVAL, self::HOOK );
			}
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => self::JOB_ID,
			'hook'        => self::HOOK,
			'interval'    => self::INTERVAL,
			'owner'       => 'bizcity-twin-crm',
			'description' => 'Poll Woo orders with tracking_number for status changes (Phase 0.38)',
			'singleton'   => true,
			'enabled'     => true,
			'retention'   => 14,
		) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Main run
	// ─────────────────────────────────────────────────────────────────────────

	public static function run(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;

		// Fetch orders that have a tracking number (processing / completed / on-hold).
		$orders = wc_get_orders( array(
			'status'   => array( 'processing', 'on-hold', 'completed' ),
			'meta_key' => '_tracking_number',
			'limit'    => 100,
			'return'   => 'objects',
		) );

		$counters = array(
			'checked'   => 0,
			'shipped'   => 0,
			'delivered' => 0,
			'unchanged' => 0,
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Abstract_Order ) {
				continue;
			}
			$counters['checked']++;
			self::process_order( $order, $counters );
		}

		// R-CRON-META: note counters.
		if ( $cron ) {
			$cron->note( array( 'counters' => $counters ) );
		}
	}

	/**
	 * Process a single order: detect status change, log, fire hooks.
	 *
	 * @param WC_Order $order
	 * @param array    $counters  Passed by reference (PHP arrays copy-on-assign but we mutate keys).
	 */
	private static function process_order( $order, array &$counters ): void {
		$order_id        = (int) $order->get_id();
		$current_status  = $order->get_status();
		$tracking_number = (string) $order->get_meta( '_tracking_number',           true );
		$provider        = (string) $order->get_meta( '_tracking_provider',         true );
		$last_status     = (string) $order->get_meta( '_bizcity_shipment_last_status', true );

		if ( $tracking_number === '' ) {
			return;
		}

		// No change — skip.
		if ( $last_status === $current_status ) {
			$counters['unchanged']++;
			return;
		}

		// Log status change.
		self::log_status_change( $order_id, $tracking_number, $provider, $last_status, $current_status );

		// Update last-known status meta.
		$order->update_meta_data( '_bizcity_shipment_last_status', $current_status );
		$order->save_meta_data();

		$cron = class_exists( 'BizCity_Cron_Manager' ) ? BizCity_Cron_Manager::instance() : null;

		// Fire hooks based on new status.
		if ( $current_status === 'completed' ) {
			$counters['delivered']++;
			// Fire delivered hook → Recap Notifier W2 listens.
			do_action( 'bizcity_crm_order_delivered', $order_id, array(
				'tracking_number' => $tracking_number,
				'provider'        => $provider,
			) );
			if ( $cron ) {
				$cron->note_event( 'shipment_delivered', array(
					'order_id'        => $order_id,
					'tracking_number' => $tracking_number,
					'provider'        => $provider,
				) );
			}
		} elseif ( $current_status === 'processing' && $last_status === '' ) {
			// Order just got a tracking number for the first time.
			$counters['shipped']++;
			do_action( 'bizcity_crm_order_shipped', $order_id, array(
				'tracking_number' => $tracking_number,
				'provider'        => $provider,
			) );
			if ( $cron ) {
				$cron->note_event( 'shipment_shipped', array(
					'order_id'        => $order_id,
					'tracking_number' => $tracking_number,
					'provider'        => $provider,
				) );
			}
		}
	}

	/**
	 * Insert a row into bizcity_crm_shipment_status_log.
	 *
	 * @param int    $order_id
	 * @param string $tracking_number
	 * @param string $provider
	 * @param string $old_status
	 * @param string $new_status
	 */
	private static function log_status_change( int $order_id, string $tracking_number, string $provider, string $old_status, string $new_status ): void {
		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_crm_shipment_status_log';
		$wpdb->insert( $tbl, array(
			'order_id'       => $order_id,
			'tracking_number'=> $tracking_number,
			'provider'       => $provider !== '' ? $provider : null,
			'old_status'     => $old_status !== '' ? $old_status : null,
			'new_status'     => $new_status,
			'raw_payload'    => null,
			'changed_at'     => current_time( 'mysql', true ),
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
	}
}
