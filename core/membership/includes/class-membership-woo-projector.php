<?php
/**
 * Bizcity Twin AI — Membership Woo Projector
 *
 * SPRINT-9 WC-1/WC-2.
 *
 * Idempotent projector from eligible Woo paid orders to Membership assignment.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-07-17
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Membership_Woo_Projector', false ) ) {
	return;
}

class BizCity_Membership_Woo_Projector {

	const PROJECTION_VERSION = '1.0.0';
	const SOURCE             = 'woo_order';

	const META_VERSION           = '_bizcity_membership_projection_version';
	const META_STATUS            = '_bizcity_membership_projection_status';
	const META_OFFER_CODE        = '_bizcity_membership_offer_code';
	const META_PLAN_SLUG         = '_bizcity_membership_plan_slug';
	const META_DURATION_COUNT    = '_bizcity_membership_duration_count';
	const META_DURATION_UNIT     = '_bizcity_membership_duration_unit';
	const META_USER_ID           = '_bizcity_membership_user_id';
	const META_SUBSCRIPTION_ID   = '_bizcity_membership_subscription_id';
	const META_STARTED_AT        = '_bizcity_membership_started_at';
	const META_EXPIRES_AT        = '_bizcity_membership_expires_at';
	const META_SEAT_DELTA        = '_bizcity_membership_seat_delta';
	const META_PROJECTED_AT      = '_bizcity_membership_projected_at';
	const META_LAST_REASON       = '_bizcity_membership_last_reason';
	const META_PROJECTION_KEY    = '_bizcity_membership_projection_key';
	const META_PAYMENT_ID        = '_bizcity_membership_payment_id';
	const META_ORDER_ITEM_ID     = '_bizcity_membership_order_item_id';
	const META_PRODUCT_ID        = '_bizcity_membership_product_id';
	const META_VARIATION_ID      = '_bizcity_membership_variation_id';

	const ITEM_META_OFFER_CODE     = '_bizcity_membership_offer_code';
	const ITEM_META_PLAN_SLUG      = '_bizcity_membership_plan_slug';
	const ITEM_META_DURATION_COUNT = '_bizcity_membership_duration_count';
	const ITEM_META_DURATION_UNIT  = '_bizcity_membership_duration_unit';
	const ITEM_META_GRANT_MODE     = '_bizcity_membership_grant_mode';

	const USER_META_OFFER_CODE      = 'bizcity_member_offer_code';
	const USER_META_OFFER_PLAN      = 'bizcity_member_offer_plan_slug';
	const USER_META_OFFER_ORDER_ID  = 'bizcity_member_offer_order_id';
	const USER_META_OFFER_APPLIED_AT = 'bizcity_member_offer_applied_at';

	/** @var bool */
	private static $booted = false;

	/** @var int|null */
	private static $seat_used_cache = null;

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( ! self::woo_ready() ) {
			return;
		}

		// [2026-07-17 Johnny Chu] SPRINT-9 WC-1 — register one idempotent projector for all paid Woo signals.
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_payment_complete' ), 30, 1 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_order_status_processing' ), 30, 2 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_order_status_completed' ), 30, 2 );
		// [2026-07-18 Johnny Chu] SPRINT-24 WC-3 — reverse local grant evidence when Woo order is refunded/cancelled.
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 30, 2 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'on_order_status_refunded' ), 30, 2 );
		add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'on_order_status_cancelled' ), 30, 2 );
	}

	public static function woo_ready() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * @param int $order_id
	 * @return void
	 */
	public static function on_payment_complete( $order_id ) {
		self::project_order( (int) $order_id, 'woocommerce_payment_complete' );
	}

	/**
	 * @param int      $order_id
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_order_status_processing( $order_id, $order = null ) {
		unset( $order );
		self::project_order( (int) $order_id, 'woocommerce_order_status_processing' );
	}

	/**
	 * @param int      $order_id
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_order_status_completed( $order_id, $order = null ) {
		unset( $order );
		self::project_order( (int) $order_id, 'woocommerce_order_status_completed' );
	}

	/**
	 * @param int $order_id
	 * @param int $refund_id
	 * @return void
	 */
	public static function on_order_refunded( $order_id, $refund_id = 0 ) {
		self::reverse_projection( (int) $order_id, 'refunded', 'woocommerce_order_refunded', (int) $refund_id );
	}

	/**
	 * @param int      $order_id
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_order_status_refunded( $order_id, $order = null ) {
		unset( $order );
		self::reverse_projection( (int) $order_id, 'refunded', 'woocommerce_order_status_refunded', 0 );
	}

	/**
	 * @param int      $order_id
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_order_status_cancelled( $order_id, $order = null ) {
		unset( $order );
		self::reverse_projection( (int) $order_id, 'cancelled', 'woocommerce_order_status_cancelled', 0 );
	}

	/**
	 * Reverse a previously-applied Woo projection when Woo lifecycle invalidates the order.
	 * Only clears membership if the user's current Woo grant still points at this order.
	 *
	 * @param int    $order_id Woo order ID.
	 * @param string $status   refunded|cancelled.
	 * @param string $source   Hook/source bucket.
	 * @param int    $refund_id Woo refund ID.
	 * @return array
	 */
	public static function reverse_projection( $order_id, $status = 'cancelled', $source = '', $refund_id = 0 ) {
		// [2026-07-18 Johnny Chu] SPRINT-24 WC-3 — fail-safe local reversal for refunded/cancelled Woo orders.
		$order_id = (int) $order_id;
		$status   = sanitize_key( (string) $status );
		$status   = in_array( $status, array( 'refunded', 'cancelled' ), true ) ? $status : 'cancelled';
		$source   = sanitize_key( (string) $source );
		$source   = $source !== '' ? $source : 'manual_reversal';

		if ( $order_id <= 0 || ! self::woo_ready() ) {
			return array( 'ok' => false, 'status' => 'failed', 'reason' => 'order_invalid' );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) ) {
			return array( 'ok' => false, 'status' => 'failed', 'reason' => 'order_missing' );
		}

		$existing_status = sanitize_key( (string) $order->get_meta( self::META_STATUS, true ) );
		if ( $existing_status !== 'applied' ) {
			return array(
				'ok'       => true,
				'status'   => 'ignored',
				'reason'   => 'not_applied',
				'order_id' => $order_id,
			);
		}

		$user_id = (int) $order->get_meta( self::META_USER_ID, true );
		if ( $user_id <= 0 ) {
			$user_id = (int) $order->get_user_id();
		}
		$payment_id = (int) $order->get_meta( self::META_PAYMENT_ID, true );
		$cleared    = false;

		if ( $payment_id > 0 && class_exists( 'BizCity_Membership_Payments' ) ) {
			BizCity_Membership_Payments::instance()->mark_refunded( $payment_id, $refund_id > 0 ? 'woo_refund_' . $refund_id : 'woo_' . $status . '_' . $order_id );
		}

		if ( $user_id > 0 && class_exists( 'BizCity_Membership_Manager' ) ) {
			$current_order_id = (int) ( class_exists( 'BizCity_User_Meta_Cache' )
				? BizCity_User_Meta_Cache::get( $user_id, self::USER_META_OFFER_ORDER_ID, 0 )
				: get_user_meta( $user_id, self::USER_META_OFFER_ORDER_ID, true ) );
			if ( $current_order_id === $order_id ) {
				BizCity_Membership_Manager::instance()->clear_plan( $user_id, BizCity_Membership_Manager::STATUS_CANCELLED );
				delete_user_meta( $user_id, self::USER_META_OFFER_CODE );
				delete_user_meta( $user_id, self::USER_META_OFFER_PLAN );
				delete_user_meta( $user_id, self::USER_META_OFFER_ORDER_ID );
				delete_user_meta( $user_id, self::USER_META_OFFER_APPLIED_AT );
				if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
					BizCity_User_Meta_Cache::invalidate( $user_id, self::USER_META_OFFER_CODE );
					BizCity_User_Meta_Cache::invalidate( $user_id, self::USER_META_OFFER_PLAN );
					BizCity_User_Meta_Cache::invalidate( $user_id, self::USER_META_OFFER_ORDER_ID );
					BizCity_User_Meta_Cache::invalidate( $user_id, self::USER_META_OFFER_APPLIED_AT );
				}
				self::flush_after_projection( $user_id );
				$cleared = true;
			}
		}

		self::mark_projection( $order, $status, array(
			'reason'       => $source,
			'user_id'      => $user_id,
			'projected_at' => current_time( 'mysql' ),
		) );

		do_action( 'bizcity_membership_woo_projection_reversed', (int) $order_id, $user_id, $status, array(
			'payment_id' => $payment_id,
			'refund_id'  => (int) $refund_id,
			'cleared'    => $cleared,
			'source'     => $source,
		) );

		return array(
			'ok'       => true,
			'status'   => $status,
			'reason'   => $source,
			'order_id' => $order_id,
			'user_id'  => $user_id,
			'cleared'  => $cleared,
		);
	}

	/**
	 * Run projection for one Woo order.
	 *
	 * @param int    $order_id
	 * @param string $source
	 * @return array
	 */
	public static function project_order( $order_id, $source = '' ) {
		$order_id = (int) $order_id;
		$source   = sanitize_key( (string) $source );
		$source   = $source !== '' ? $source : 'manual';

		if ( $order_id <= 0 || ! self::woo_ready() ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'reason' => 'order_invalid',
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'reason' => 'order_missing',
			);
		}

		$existing_status = sanitize_key( (string) $order->get_meta( self::META_STATUS, true ) );
		if ( $existing_status === 'applied' ) {
			return array(
				'ok'       => true,
				'status'   => 'already_applied',
				'reason'   => 'idempotent',
				'order_id' => $order_id,
			);
		}

		if ( ! self::order_is_eligible( $order ) ) {
			self::mark_projection( $order, 'pending', array(
				'reason'       => 'not_paid',
				'projected_at' => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'pending',
				'reason'   => 'not_paid',
				'order_id' => $order_id,
			);
		}

		$user_id = (int) $order->get_user_id();
		if ( $user_id <= 0 ) {
			self::mark_projection( $order, 'failed', array(
				'reason'       => 'user_missing',
				'projected_at' => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'failed',
				'reason'   => 'user_missing',
				'order_id' => $order_id,
			);
		}

		$offer = self::resolve_offer_from_order( $order );
		if ( is_wp_error( $offer ) ) {
			$reason = sanitize_key( (string) $offer->get_error_code() );
			if ( $reason === '' ) {
				$reason = 'offer_missing';
			}
			self::mark_projection( $order, 'failed', array(
				'reason'       => $reason,
				'projected_at' => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'failed',
				'reason'   => $reason,
				'order_id' => $order_id,
			);
		}

		$transition = self::resolve_transition( $user_id, $offer );
		if ( is_wp_error( $transition ) ) {
			$reason = sanitize_key( (string) $transition->get_error_code() );
			if ( $reason === '' ) {
				$reason = 'transition_error';
			}
			self::mark_projection( $order, 'failed', array(
				'reason'        => $reason,
				'offer_code'    => $offer['offer_code'],
				'plan_slug'     => $offer['plan_slug'],
				'duration_count'=> (int) $offer['duration_count'],
				'duration_unit' => $offer['duration_unit'],
				'order_item_id' => (int) $offer['order_item_id'],
				'product_id'    => (int) $offer['product_id'],
				'variation_id'  => (int) $offer['variation_id'],
				'projected_at'  => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'failed',
				'reason'   => $reason,
				'order_id' => $order_id,
			);
		}

		$projection_key = self::build_projection_key( $order_id, $offer );
		$seat_delta     = isset( $transition['seat_delta'] ) ? (int) $transition['seat_delta'] : 0;
		$capacity       = self::evaluate_capacity( $order_id, $user_id, $offer, $seat_delta );
		if ( empty( $capacity['available'] ) ) {
			$reason = isset( $capacity['bucket'] ) ? sanitize_key( (string) $capacity['bucket'] ) : 'capacity_blocked';
			if ( $reason === '' ) {
				$reason = 'capacity_blocked';
			}
			self::mark_projection( $order, 'capacity_blocked', array(
				'reason'         => $reason,
				'offer_code'     => $offer['offer_code'],
				'plan_slug'      => $offer['plan_slug'],
				'duration_count' => (int) $offer['duration_count'],
				'duration_unit'  => $offer['duration_unit'],
				'user_id'        => $user_id,
				'seat_delta'     => $seat_delta,
				'order_item_id'  => (int) $offer['order_item_id'],
				'product_id'     => (int) $offer['product_id'],
				'variation_id'   => (int) $offer['variation_id'],
				'projection_key' => $projection_key,
				'projected_at'   => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'capacity_blocked',
				'reason'   => $reason,
				'order_id' => $order_id,
			);
		}

		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			self::mark_projection( $order, 'failed', array(
				'reason'       => 'manager_missing',
				'projected_at' => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'failed',
				'reason'   => 'manager_missing',
				'order_id' => $order_id,
			);
		}

		$valid_until = isset( $transition['valid_until'] ) ? (string) $transition['valid_until'] : '';
		$subscription_id = BizCity_Membership_Manager::instance()->set_plan(
			$user_id,
			$offer['plan_slug'],
			$valid_until,
			self::SOURCE
		);

		if ( ! $subscription_id ) {
			self::mark_projection( $order, 'failed', array(
				'reason'         => 'plan_apply_failed',
				'offer_code'     => $offer['offer_code'],
				'plan_slug'      => $offer['plan_slug'],
				'duration_count' => (int) $offer['duration_count'],
				'duration_unit'  => $offer['duration_unit'],
				'user_id'        => $user_id,
				'order_item_id'  => (int) $offer['order_item_id'],
				'product_id'     => (int) $offer['product_id'],
				'variation_id'   => (int) $offer['variation_id'],
				'projection_key' => $projection_key,
				'projected_at'   => current_time( 'mysql' ),
			) );
			return array(
				'ok'       => false,
				'status'   => 'failed',
				'reason'   => 'plan_apply_failed',
				'order_id' => $order_id,
			);
		}

		$payment_id = self::record_projection_payment( $order, $user_id, (int) $subscription_id, $offer, $projection_key );

		// [2026-07-17 Johnny Chu] SPRINT-9 WC-1 — keep latest applied Woo offer context for /me subscription UI.
		// [2026-07-27 Johnny Chu] R-PERF — persist Woo offer metadata through the unified cache contract.
		if ( class_exists( 'BizCity_User_Meta_Cache' ) ) {
			BizCity_User_Meta_Cache::set( $user_id, self::USER_META_OFFER_CODE, $offer['offer_code'] );
			BizCity_User_Meta_Cache::set( $user_id, self::USER_META_OFFER_PLAN, $offer['plan_slug'] );
			BizCity_User_Meta_Cache::set( $user_id, self::USER_META_OFFER_ORDER_ID, (int) $order_id );
			BizCity_User_Meta_Cache::set( $user_id, self::USER_META_OFFER_APPLIED_AT, current_time( 'mysql' ) );
		} else {
			update_user_meta( $user_id, self::USER_META_OFFER_CODE, $offer['offer_code'] );
			update_user_meta( $user_id, self::USER_META_OFFER_PLAN, $offer['plan_slug'] );
			update_user_meta( $user_id, self::USER_META_OFFER_ORDER_ID, (int) $order_id );
			update_user_meta( $user_id, self::USER_META_OFFER_APPLIED_AT, current_time( 'mysql' ) );
		}

		self::mark_projection( $order, 'applied', array(
			'reason'          => 'capacity_available',
			'offer_code'      => $offer['offer_code'],
			'plan_slug'       => $offer['plan_slug'],
			'duration_count'  => (int) $offer['duration_count'],
			'duration_unit'   => $offer['duration_unit'],
			'user_id'         => $user_id,
			'subscription_id' => (int) $subscription_id,
			'started_at'      => current_time( 'mysql' ),
			'expires_at'      => $valid_until,
			'seat_delta'      => $seat_delta,
			'order_item_id'   => (int) $offer['order_item_id'],
			'product_id'      => (int) $offer['product_id'],
			'variation_id'    => (int) $offer['variation_id'],
			'projection_key'  => $projection_key,
			'payment_id'      => (int) $payment_id,
			'projected_at'    => current_time( 'mysql' ),
		) );

		self::flush_after_projection( $user_id );

		do_action( 'bizcity_membership_woo_projection_applied', (int) $order_id, $user_id, $offer, array(
			'subscription_id' => (int) $subscription_id,
			'payment_id'      => (int) $payment_id,
			'seat_delta'      => $seat_delta,
			'source'          => $source,
		) );

		return array(
			'ok'              => true,
			'status'          => 'applied',
			'reason'          => 'capacity_available',
			'order_id'        => $order_id,
			'user_id'         => $user_id,
			'offer_code'      => $offer['offer_code'],
			'plan_slug'       => $offer['plan_slug'],
			'subscription_id' => (int) $subscription_id,
			'payment_id'      => (int) $payment_id,
			'expires_at'      => $valid_until,
		);
	}

	/**
	 * Build seat-capacity snapshot for admin dashboards.
	 *
	 * @return array
	 */
	public static function get_capacity_snapshot() {
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 — expose canonical seat capacity payload for Twin GPT commerce tab.
		$limit = self::resolve_seat_limit();
		$used  = self::count_seat_used();

		$degraded_reasons = array();
		if ( $limit <= 0 ) {
			$degraded_reasons[] = 'seat_limit_unknown';
		}

		$at_capacity = ( $limit > 0 && $used >= $limit );
		$over_capacity = ( $limit > 0 && $used > $limit );

		return array(
			'seat_limit'       => $limit > 0 ? (int) $limit : null,
			'seat_used'        => (int) $used,
			'seat_remaining'   => $limit > 0 ? max( 0, (int) $limit - (int) $used ) : null,
			'at_capacity'      => $at_capacity,
			'over_capacity'    => $over_capacity,
			'capacity_bucket'  => $at_capacity ? 'capacity_blocked' : 'capacity_available',
			'_degraded'        => ! empty( $degraded_reasons ),
			'degraded_reasons' => $degraded_reasons,
		);
	}

	/**
	 * List recent Woo orders that already have projection status markers.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function get_projection_queue( $limit = 20 ) {
		// [2026-07-17 Johnny Chu] SPRINT-10 SB-3 — expose recent projection rows for Commerce/Woo admin observability.
		$limit = max( 1, min( 100, (int) $limit ) );

		$summary = array(
			'total'            => 0,
			'applied'          => 0,
			'pending'          => 0,
			'failed'           => 0,
			'capacity_blocked' => 0,
			// [2026-07-18 Johnny Chu] SPRINT-24 WC-3 — expose Woo reversal lifecycle buckets in Commerce dashboard.
			'refunded'         => 0,
			'cancelled'        => 0,
			'other'            => 0,
		);

		if ( ! self::woo_ready() || ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'items'            => array(),
				'summary'          => $summary,
				'_degraded'        => true,
				'degraded_reasons' => array( 'woo_inactive' ),
			);
		}

		$orders = wc_get_orders( array(
			'limit'      => $limit,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'return'     => 'objects',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => self::META_STATUS,
					'compare' => 'EXISTS',
				),
			),
		) );

		$items = array();
		foreach ( (array) $orders as $order ) {
			if ( ! $order || ! is_object( $order ) ) {
				continue;
			}

			$projection_status = sanitize_key( (string) $order->get_meta( self::META_STATUS, true ) );
			if ( $projection_status === '' ) {
				continue;
			}

			$summary['total']++;
			if ( isset( $summary[ $projection_status ] ) ) {
				$summary[ $projection_status ]++;
			} else {
				$summary['other']++;
			}

			$created_at = '';
			if ( method_exists( $order, 'get_date_created' ) ) {
				$created = $order->get_date_created();
				if ( $created && method_exists( $created, 'getTimestamp' ) ) {
					$created_at = gmdate( 'c', (int) $created->getTimestamp() );
				}
			}

			$items[] = array(
				'order_id'          => (int) $order->get_id(),
				'order_number'      => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order->get_id(),
				'created_at'        => $created_at,
				'order_status'      => method_exists( $order, 'get_status' ) ? sanitize_key( (string) $order->get_status() ) : '',
				'projection_status' => $projection_status,
				'reason'            => sanitize_key( (string) $order->get_meta( self::META_LAST_REASON, true ) ),
				'offer_code'        => sanitize_key( (string) $order->get_meta( self::META_OFFER_CODE, true ) ),
				'plan_slug'         => sanitize_key( (string) $order->get_meta( self::META_PLAN_SLUG, true ) ),
				'user_id'           => (int) $order->get_user_id(),
				'total'             => (float) $order->get_total(),
				'currency'          => sanitize_text_field( (string) $order->get_currency() ),
				'is_paid'           => method_exists( $order, 'is_paid' ) ? (bool) $order->is_paid() : false,
				'projected_at'      => (string) $order->get_meta( self::META_PROJECTED_AT, true ),
			);
		}

		return array(
			'items'            => $items,
			'summary'          => $summary,
			'_degraded'        => false,
			'degraded_reasons' => array(),
		);
	}

	/**
	 * @param WC_Order $order
	 * @return bool
	 */
	private static function order_is_eligible( $order ) {
		if ( ! $order || ! is_object( $order ) ) {
			return false;
		}
		if ( method_exists( $order, 'is_paid' ) && $order->is_paid() ) {
			return true;
		}
		if ( method_exists( $order, 'has_status' ) ) {
			return (bool) $order->has_status( array( 'processing', 'completed' ) );
		}
		return false;
	}

	/**
	 * Resolve a single membership offer from order items.
	 *
	 * @param WC_Order $order
	 * @return array|WP_Error
	 */
	private static function resolve_offer_from_order( $order ) {
		if ( ! class_exists( 'BizCity_Membership_Woo_Mapper' ) ) {
			return new WP_Error( 'mapper_missing', 'Membership Woo mapper class is missing.' );
		}

		$items = $order->get_items( 'line_item' );
		if ( ! is_array( $items ) || empty( $items ) ) {
			return new WP_Error( 'offer_missing', 'Order has no line items.' );
		}

		$map = BizCity_Membership_Woo_Mapper::instance()->get_map();
		if ( ! is_array( $map ) || empty( $map['items'] ) || ! is_array( $map['items'] ) ) {
			$map = BizCity_Membership_Woo_Mapper::instance()->rebuild_index();
		}
		$map_items = isset( $map['items'] ) && is_array( $map['items'] ) ? $map['items'] : array();

		$candidates = array();
		foreach ( $items as $item_id => $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}

			$offer = self::read_item_snapshot( $item );
			if ( is_array( $offer ) && ! empty( $offer['offer_code'] ) && ! empty( $offer['plan_slug'] ) ) {
				$offer['order_item_id'] = (int) $item_id;
				$offer['product_id']    = (int) $item->get_product_id();
				$offer['variation_id']  = (int) $item->get_variation_id();
				$candidates[] = $offer;
				continue;
			}

			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$mapped       = self::resolve_offer_from_map( $map_items, $product_id, $variation_id );
			if ( empty( $mapped ) ) {
				continue;
			}
			$mapped['order_item_id'] = (int) $item_id;
			$candidates[]            = $mapped;
		}

		if ( empty( $candidates ) ) {
			return new WP_Error( 'offer_missing', 'No eligible membership offer in this order.' );
		}

		$by_code = array();
		foreach ( $candidates as $candidate ) {
			$code = sanitize_key( (string) $candidate['offer_code'] );
			if ( $code === '' ) {
				continue;
			}
			if ( ! isset( $by_code[ $code ] ) ) {
				$by_code[ $code ] = $candidate;
			}
		}

		if ( count( $by_code ) !== 1 ) {
			return new WP_Error( 'offer_conflict', 'Order has multiple membership offers.' );
		}

		$offer = reset( $by_code );
		if ( ! is_array( $offer ) || empty( $offer['plan_slug'] ) ) {
			return new WP_Error( 'offer_invalid', 'Offer payload is invalid.' );
		}

		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_Error( 'plan_registry_missing', 'Membership plan registry is missing.' );
		}
		$plan_slug = sanitize_key( (string) $offer['plan_slug'] );
		if ( ! BizCity_Membership_Plan_Registry::instance()->exists( $plan_slug ) ) {
			return new WP_Error( 'plan_missing', 'Target plan slug does not exist.' );
		}

		$offer['plan_slug']      = $plan_slug;
		$offer['duration_count'] = max( 1, (int) ( $offer['duration_count'] ?? 1 ) );
		$offer['duration_unit']  = self::sanitize_duration_unit( (string) ( $offer['duration_unit'] ?? 'month' ) );
		$offer['grant_mode']     = self::sanitize_grant_mode( (string) ( $offer['grant_mode'] ?? 'replace' ) );

		if ( $offer['duration_unit'] === '' ) {
			return new WP_Error( 'duration_invalid', 'Offer duration unit is invalid.' );
		}

		return $offer;
	}

	/**
	 * @param array $map_items
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @return array
	 */
	private static function resolve_offer_from_map( array $map_items, $product_id, $variation_id ) {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;

		if ( $variation_id > 0 ) {
			foreach ( $map_items as $code => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				if ( (int) ( $row['variation_id'] ?? 0 ) !== $variation_id ) {
					continue;
				}
				return array(
					'offer_code'     => sanitize_key( (string) $code ),
					'plan_slug'      => sanitize_key( (string) ( $row['plan_slug'] ?? '' ) ),
					'duration_count' => max( 1, (int) ( $row['duration_count'] ?? 1 ) ),
					'duration_unit'  => self::sanitize_duration_unit( (string) ( $row['duration_unit'] ?? 'month' ) ),
					'grant_mode'     => self::sanitize_grant_mode( (string) ( $row['grant_mode'] ?? 'replace' ) ),
					'product_id'     => (int) ( $row['product_id'] ?? $product_id ),
					'variation_id'   => (int) ( $row['variation_id'] ?? $variation_id ),
				);
			}
		}

		foreach ( $map_items as $code => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (int) ( $row['product_id'] ?? 0 ) !== $product_id ) {
				continue;
			}
			if ( (int) ( $row['variation_id'] ?? 0 ) > 0 ) {
				continue;
			}
			return array(
				'offer_code'     => sanitize_key( (string) $code ),
				'plan_slug'      => sanitize_key( (string) ( $row['plan_slug'] ?? '' ) ),
				'duration_count' => max( 1, (int) ( $row['duration_count'] ?? 1 ) ),
				'duration_unit'  => self::sanitize_duration_unit( (string) ( $row['duration_unit'] ?? 'month' ) ),
				'grant_mode'     => self::sanitize_grant_mode( (string) ( $row['grant_mode'] ?? 'replace' ) ),
				'product_id'     => (int) ( $row['product_id'] ?? $product_id ),
				'variation_id'   => 0,
			);
		}

		return array();
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @return array
	 */
	private static function read_item_snapshot( $item ) {
		$offer_code = sanitize_key( (string) $item->get_meta( self::ITEM_META_OFFER_CODE, true ) );
		$plan_slug  = sanitize_key( (string) $item->get_meta( self::ITEM_META_PLAN_SLUG, true ) );
		if ( $offer_code === '' || $plan_slug === '' ) {
			return array();
		}

		return array(
			'offer_code'     => $offer_code,
			'plan_slug'      => $plan_slug,
			'duration_count' => max( 1, (int) $item->get_meta( self::ITEM_META_DURATION_COUNT, true ) ),
			'duration_unit'  => self::sanitize_duration_unit( (string) $item->get_meta( self::ITEM_META_DURATION_UNIT, true ) ),
			'grant_mode'     => self::sanitize_grant_mode( (string) $item->get_meta( self::ITEM_META_GRANT_MODE, true ) ),
		);
	}

	/**
	 * @param int   $user_id
	 * @param array $offer
	 * @return array|WP_Error
	 */
	private static function resolve_transition( $user_id, array $offer ) {
		if ( ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return new WP_Error( 'plan_registry_missing', 'Plan registry is missing.' );
		}
		$registry = BizCity_Membership_Plan_Registry::instance();
		$target_slug = sanitize_key( (string) ( $offer['plan_slug'] ?? '' ) );
		if ( $target_slug === '' || ! $registry->exists( $target_slug ) ) {
			return new WP_Error( 'plan_missing', 'Target plan does not exist.' );
		}

		$active_sub = self::get_active_subscription_row( (int) $user_id );
		$current_slug = $active_sub && ! empty( $active_sub['plan_slug'] )
			? sanitize_key( (string) $active_sub['plan_slug'] )
			: 'free';
		$current_plan = $registry->get( $current_slug );
		$target_plan  = $registry->get( $target_slug );

		$current_rank = isset( $current_plan['rank'] ) ? (int) $current_plan['rank'] : 0;
		$target_rank  = isset( $target_plan['rank'] ) ? (int) $target_plan['rank'] : 0;
		if ( $target_rank < $current_rank ) {
			return new WP_Error( 'downgrade_not_supported', 'Downgrade projection is blocked.' );
		}

		$duration_count = max( 1, (int) ( $offer['duration_count'] ?? 1 ) );
		$duration_unit  = self::sanitize_duration_unit( (string) ( $offer['duration_unit'] ?? 'month' ) );
		if ( $duration_unit === '' ) {
			return new WP_Error( 'duration_invalid', 'Duration unit is invalid.' );
		}

		$now_ts      = (int) current_time( 'timestamp' );
		$current_exp = self::timestamp_from_datetime( $active_sub && ! empty( $active_sub['expiration_date'] ) ? (string) $active_sub['expiration_date'] : '' );
		$target_exp  = '';

		if ( $duration_unit !== 'lifetime' ) {
			if ( $target_rank === $current_rank ) {
				$base_ts   = max( $now_ts, $current_exp );
				$new_exp_t = self::add_duration_ts( $base_ts, $duration_count, $duration_unit );
			} else {
				$candidate = self::add_duration_ts( $now_ts, $duration_count, $duration_unit );
				$new_exp_t = max( (int) $candidate, (int) $current_exp );
			}
			if ( $new_exp_t <= 0 ) {
				return new WP_Error( 'duration_invalid', 'Cannot compute target expiration.' );
			}
			$target_exp = gmdate( 'Y-m-d H:i:s', $new_exp_t );
		}

		$current_consumes = self::plan_consumes_seat( $current_slug );
		$target_consumes  = self::plan_consumes_seat( $target_slug );
		$seat_delta       = 0;
		if ( ! $current_consumes && $target_consumes ) {
			$seat_delta = 1;
		} elseif ( $current_consumes && ! $target_consumes ) {
			$seat_delta = -1;
		}

		return array(
			'current_plan' => $current_slug,
			'target_plan'  => $target_slug,
			'valid_until'  => $target_exp,
			'seat_delta'   => $seat_delta,
		);
	}

	/**
	 * @param int   $order_id
	 * @param int   $user_id
	 * @param array $offer
	 * @param int   $seat_delta
	 * @return array
	 */
	private static function evaluate_capacity( $order_id, $user_id, array $offer, $seat_delta ) {
		$seat_delta = (int) $seat_delta;
		if ( $seat_delta <= 0 ) {
			return array(
				'available' => true,
				'bucket'    => 'capacity_available',
				'limit'     => null,
				'used'      => self::count_seat_used(),
			);
		}

		$used  = self::count_seat_used();
		$limit = self::resolve_seat_limit();
		$snapshot = array(
			'available' => true,
			'bucket'    => 'capacity_available',
			'used'      => $used,
			'limit'     => $limit,
			'remaining' => $limit > 0 ? max( 0, $limit - $used ) : null,
		);

		$snapshot = apply_filters( 'bizcity_membership_woo_capacity_snapshot', $snapshot, (int) $order_id, (int) $user_id, $offer, $seat_delta );
		if ( ! is_array( $snapshot ) ) {
			$snapshot = array();
		}

		$used  = isset( $snapshot['used'] ) ? max( 0, (int) $snapshot['used'] ) : $used;
		$limit = isset( $snapshot['limit'] ) ? (int) $snapshot['limit'] : $limit;

		$available = array_key_exists( 'available', $snapshot ) ? (bool) $snapshot['available'] : true;
		$bucket    = isset( $snapshot['bucket'] ) ? sanitize_key( (string) $snapshot['bucket'] ) : 'capacity_available';
		if ( $bucket === '' ) {
			$bucket = 'capacity_available';
		}

		if ( $available && $limit > 0 && ( $used + $seat_delta ) > $limit ) {
			$available = false;
			$bucket    = 'capacity_blocked';
		}

		// [2026-07-17 Johnny Chu] SPRINT-9 WC-2 — optional fail-closed toggle for unknown seat limit.
		$fail_closed_unknown = (bool) apply_filters( 'bizcity_membership_woo_capacity_fail_closed', false, $order_id, $user_id, $offer );
		if ( $available && $limit <= 0 && $fail_closed_unknown ) {
			$available = false;
			$bucket    = 'capacity_unavailable';
		}

		return array(
			'available' => $available,
			'bucket'    => $available ? 'capacity_available' : $bucket,
			'limit'     => $limit > 0 ? $limit : null,
			'used'      => $used,
			'remaining' => $limit > 0 ? max( 0, $limit - $used ) : null,
		);
	}

	/**
	 * @return int
	 */
	private static function resolve_seat_limit() {
		$raw = apply_filters( 'bizcity_membership_woo_seat_limit', null );
		if ( is_numeric( $raw ) && (int) $raw > 0 ) {
			return (int) $raw;
		}

		$candidates = array(
			get_option( 'bizcity_hub_member_seat_limit', null ),
			get_option( 'bizcity_hub_member_seats_limit', null ),
			get_option( 'bizcity_hub_member_seat_cap', null ),
		);
		foreach ( $candidates as $candidate ) {
			if ( is_numeric( $candidate ) && (int) $candidate > 0 ) {
				return (int) $candidate;
			}
		}

		return 0;
	}

	/**
	 * @return int
	 */
	private static function count_seat_used() {
		if ( null !== self::$seat_used_cache ) {
			return (int) self::$seat_used_cache;
		}

		if ( class_exists( 'BizCity_Membership_Manager' ) && method_exists( 'BizCity_Membership_Manager', 'count_seat_used' ) ) {
			// [2026-07-17 Johnny Chu] SPRINT-11 PGM-3 — use canonical manager counter to avoid duplicate seat-count logic.
			self::$seat_used_cache = max( 0, (int) BizCity_Membership_Manager::instance()->count_seat_used() );
			return (int) self::$seat_used_cache;
		}

		if ( ! class_exists( 'BizCity_Membership_Manager' ) ) {
			self::$seat_used_cache = 0;
			return 0;
		}

		global $wpdb;
		$table = BizCity_Membership_Manager::instance()->table();
		if ( ! self::table_exists( $table ) ) {
			self::$seat_used_cache = 0;
			return 0;
		}

		$now = current_time( 'mysql' );
		// [2026-07-17 Johnny Chu] SPRINT-9 WC-2 — distinct active seat users: latest active row per user, skip admins.
		$sql = $wpdb->prepare(
			"SELECT user_id, plan_slug
			 FROM {$table}
			 WHERE status = %s
			   AND ( expiration_date IS NULL OR expiration_date = '' OR expiration_date >= %s )
			 ORDER BY id DESC",
			BizCity_Membership_Manager::STATUS_ACTIVE,
			$now
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = is_array( $rows ) ? $rows : array();

		$seen = array();
		$used = 0;
		foreach ( $rows as $row ) {
			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $user_id <= 0 || isset( $seen[ $user_id ] ) ) {
				continue;
			}
			$seen[ $user_id ] = 1;
			if ( self::is_admin_user( $user_id ) ) {
				continue;
			}
			$plan_slug = sanitize_key( (string) ( $row['plan_slug'] ?? '' ) );
			if ( self::plan_consumes_seat( $plan_slug ) ) {
				$used++;
			}
		}

		self::$seat_used_cache = $used;
		return $used;
	}

	/**
	 * @param string $table_name
	 * @return bool
	 */
	private static function table_exists( $table_name ) {
		$table_name = (string) $table_name;
		if ( $table_name === '' ) {
			return false;
		}
		if ( function_exists( 'bizcity_tbl_exists' ) ) {
			return (bool) bizcity_tbl_exists( $table_name );
		}

		global $wpdb;
		$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table_name
		) );
		return $present === 1;
	}

	/**
	 * @param int $user_id
	 * @return bool
	 */
	private static function is_admin_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( function_exists( 'user_can' ) && user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->roles ) ) {
			return false;
		}
		return in_array( 'administrator', (array) $user->roles, true );
	}

	/**
	 * @param int $user_id
	 * @return array|null
	 */
	private static function get_active_subscription_row( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_Membership_Manager' ) ) {
			return null;
		}

		global $wpdb;
		$table = BizCity_Membership_Manager::instance()->table();
		if ( ! self::table_exists( $table ) ) {
			return null;
		}

		$now = current_time( 'mysql' );
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE user_id = %d
			   AND status = %s
			   AND ( expiration_date IS NULL OR expiration_date = '' OR expiration_date >= %s )
			 ORDER BY start_date DESC, id DESC
			 LIMIT 1",
			$user_id,
			BizCity_Membership_Manager::STATUS_ACTIVE,
			$now
		);
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param string $plan_slug
	 * @return bool
	 */
	private static function plan_consumes_seat( $plan_slug ) {
		$plan_slug = sanitize_key( (string) $plan_slug );
		if ( $plan_slug === '' || ! class_exists( 'BizCity_Membership_Plan_Registry' ) ) {
			return false;
		}
		$plan = BizCity_Membership_Plan_Registry::instance()->get( $plan_slug );
		return ! empty( $plan['consumes_seat'] );
	}

	/**
	 * @param int   $start_ts
	 * @param int   $count
	 * @param string $unit
	 * @return int
	 */
	private static function add_duration_ts( $start_ts, $count, $unit ) {
		$start_ts = (int) $start_ts;
		$count    = max( 1, (int) $count );
		$unit     = self::sanitize_duration_unit( (string) $unit );
		if ( $start_ts <= 0 || $unit === '' || $unit === 'lifetime' ) {
			return 0;
		}

		switch ( $unit ) {
			case 'day':
				return (int) strtotime( '+' . $count . ' days', $start_ts );
			case 'week':
				return (int) strtotime( '+' . $count . ' weeks', $start_ts );
			case 'month':
				return (int) strtotime( '+' . $count . ' months', $start_ts );
			case 'year':
				return (int) strtotime( '+' . $count . ' years', $start_ts );
			default:
				return 0;
		}
	}

	/**
	 * @param string $value
	 * @return int
	 */
	private static function timestamp_from_datetime( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) {
			return 0;
		}
		$ts = strtotime( $value );
		return $ts ? (int) $ts : 0;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	private static function sanitize_duration_unit( $value ) {
		$value = sanitize_key( (string) $value );
		$allowed = array( 'day', 'week', 'month', 'year', 'lifetime' );
		return in_array( $value, $allowed, true ) ? $value : '';
	}

	/**
	 * @param string $value
	 * @return string
	 */
	private static function sanitize_grant_mode( $value ) {
		$value = sanitize_key( (string) $value );
		$allowed = array( 'replace', 'extend', 'stack' );
		return in_array( $value, $allowed, true ) ? $value : 'replace';
	}

	/**
	 * @param int   $order_id
	 * @param array $offer
	 * @return string
	 */
	private static function build_projection_key( $order_id, array $offer ) {
		$parts = array(
			(int) get_current_blog_id(),
			(int) $order_id,
			(int) ( $offer['order_item_id'] ?? 0 ),
			sanitize_key( (string) ( $offer['offer_code'] ?? '' ) ),
			self::PROJECTION_VERSION,
		);
		return md5( implode( ':', $parts ) );
	}

	/**
	 * @param WC_Order $order
	 * @param int      $user_id
	 * @param int      $subscription_id
	 * @param array    $offer
	 * @param string   $projection_key
	 * @return int
	 */
	private static function record_projection_payment( $order, $user_id, $subscription_id, array $offer, $projection_key ) {
		if ( ! class_exists( 'BizCity_Membership_Payments' ) ) {
			return 0;
		}

		$order_id = (int) $order->get_id();
		$item_id  = isset( $offer['order_item_id'] ) ? (int) $offer['order_item_id'] : 0;
		$txn_id   = sanitize_text_field( (string) $order->get_transaction_id() );
		if ( $txn_id === '' ) {
			$txn_id = 'woo_' . $order_id . '_' . $item_id . '_' . sanitize_key( (string) $offer['offer_code'] );
		}
		$txn_id = substr( $txn_id, 0, 120 );

		$amount = (float) $order->get_total();
		$item_total = 0.0;
		if ( $item_id > 0 ) {
			$item = $order->get_item( $item_id );
			if ( $item && is_object( $item ) ) {
				$item_total = (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}
		if ( $item_total > 0 ) {
			$amount = $item_total;
		}

		$row_id = BizCity_Membership_Payments::instance()->record( array(
			'user_id'         => (int) $user_id,
			'subscription_id' => (int) $subscription_id,
			'plan_slug'       => (string) $offer['plan_slug'],
			'status'          => BizCity_Membership_Payments::STATUS_COMPLETED,
			'amount'          => $amount,
			'currency'        => $order->get_currency() ? (string) $order->get_currency() : 'USD',
			'gateway'         => 'woo',
			'transaction_id'  => $txn_id,
			'payer_email'     => sanitize_email( (string) $order->get_billing_email() ),
			'paid_at'         => current_time( 'mysql' ),
			'meta'            => array(
				'order_id'          => $order_id,
				'order_item_id'     => $item_id,
				'offer_code'        => (string) $offer['offer_code'],
				'product_id'        => (int) ( $offer['product_id'] ?? 0 ),
				'variation_id'      => (int) ( $offer['variation_id'] ?? 0 ),
				'projection_key'    => (string) $projection_key,
				'projection_version'=> self::PROJECTION_VERSION,
			),
		) );

		return (int) $row_id;
	}

	/**
	 * @param WC_Order $order
	 * @param string   $status
	 * @param array    $payload
	 * @return void
	 */
	private static function mark_projection( $order, $status, array $payload = array() ) {
		$status = sanitize_key( (string) $status );
		if ( $status === '' ) {
			$status = 'failed';
		}

		$order->update_meta_data( self::META_VERSION, self::PROJECTION_VERSION );
		$order->update_meta_data( self::META_STATUS, $status );
		$order->update_meta_data( self::META_PROJECTED_AT, isset( $payload['projected_at'] ) ? (string) $payload['projected_at'] : current_time( 'mysql' ) );

		$meta_map = array(
			'offer_code'      => self::META_OFFER_CODE,
			'plan_slug'       => self::META_PLAN_SLUG,
			'duration_count'  => self::META_DURATION_COUNT,
			'duration_unit'   => self::META_DURATION_UNIT,
			'user_id'         => self::META_USER_ID,
			'subscription_id' => self::META_SUBSCRIPTION_ID,
			'started_at'      => self::META_STARTED_AT,
			'expires_at'      => self::META_EXPIRES_AT,
			'seat_delta'      => self::META_SEAT_DELTA,
			'projection_key'  => self::META_PROJECTION_KEY,
			'payment_id'      => self::META_PAYMENT_ID,
			'order_item_id'   => self::META_ORDER_ITEM_ID,
			'product_id'      => self::META_PRODUCT_ID,
			'variation_id'    => self::META_VARIATION_ID,
		);
		foreach ( $meta_map as $key => $meta_key ) {
			if ( array_key_exists( $key, $payload ) ) {
				$order->update_meta_data( $meta_key, $payload[ $key ] );
			}
		}

		$reason = isset( $payload['reason'] ) ? sanitize_key( (string) $payload['reason'] ) : '';
		if ( $reason !== '' ) {
			$order->update_meta_data( self::META_LAST_REASON, $reason );
		}

		$order->save();
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private static function flush_after_projection( $user_id ) {
		$user_id = (int) $user_id;
		self::$seat_used_cache = null;

		// [2026-07-17 Johnny Chu] SPRINT-9 WC-2 — invalidate membership + TwinWeb effective-config + TwinShell menu caches.
		if ( $user_id > 0 && class_exists( 'BizCity_Membership_Entitlement' ) ) {
			BizCity_Membership_Entitlement::instance()->flush_cache( $user_id );
		}
		do_action( 'bizcity_twinweb_flush_effective_config', (int) get_current_blog_id() );
		if ( class_exists( 'BizCity_Twin_Shell_Registry' ) ) {
			BizCity_Twin_Shell_Registry::instance()->reset_cache();
		}
	}
}
