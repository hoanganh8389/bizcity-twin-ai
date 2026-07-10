<?php
/**
 * Bizcity Twin AI — Membership_Bridge_PMS
 *
 * PHASE-MEMBERSHIP M7 (optional).
 *
 * Soft bridge to the "Paid Member Subscriptions" (PMS) plugin when a client
 * already runs it. If PMS is active, a user's active PMS subscription plan can
 * be mapped to one of OUR membership slugs (free/pro/plus) via an admin-defined
 * map (option bizcity_membership_pms_map: { pms_plan_id => membership_slug }).
 *
 * This is OPT-IN and fully guarded: when PMS is not installed the bridge is a
 * no-op. We NEVER require core/membership/_library/paid-member/ at runtime —
 * that folder is reference source only.
 *
 * PHP 7.4-safe — no union types, no nullsafe, no match, no enums.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Membership
 * @since      2026-06-04
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Membership_Bridge_PMS {

	const OPT_MAP = 'bizcity_membership_pms_map';

	/**
	 * Activate the bridge only when PMS is present.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! self::pms_active() ) {
			return;
		}
		// Priority 5 so an explicit subscription row still wins (Manager runs its
		// own resolution first; this filter only nudges the final slug).
		add_filter( 'bizcity_membership_plan_for_user', array( __CLASS__, 'map_plan' ), 5, 2 );
	}

	/**
	 * Whether the Paid Member Subscriptions plugin is active.
	 *
	 * @return bool
	 */
	public static function pms_active() {
		return class_exists( 'PMS_Member' )
			|| function_exists( 'pms_get_member_subscriptions' )
			|| function_exists( 'pms_get_subscription_plans' );
	}

	/**
	 * Map a PMS active subscription to one of our membership slugs.
	 *
	 * @param string $slug    slug resolved so far by the Manager
	 * @param int    $user_id
	 * @return string
	 */
	public static function map_plan( $slug, $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'pms_get_member_subscriptions' ) ) {
			return $slug;
		}

		$map = get_option( self::OPT_MAP, array() );
		if ( ! is_array( $map ) || empty( $map ) ) {
			return $slug;
		}

		$subs = pms_get_member_subscriptions( array( 'user_id' => $user_id ) );
		if ( ! is_array( $subs ) || empty( $subs ) ) {
			return $slug;
		}

		foreach ( $subs as $sub ) {
			$status = is_array( $sub ) && isset( $sub['status'] ) ? (string) $sub['status']
				: ( is_object( $sub ) && isset( $sub->status ) ? (string) $sub->status : '' );
			if ( $status !== 'active' ) {
				continue;
			}
			$plan_id = is_array( $sub ) && isset( $sub['subscription_plan_id'] ) ? (string) $sub['subscription_plan_id']
				: ( is_object( $sub ) && isset( $sub->subscription_plan_id ) ? (string) $sub->subscription_plan_id : '' );
			if ( $plan_id === '' || ! isset( $map[ $plan_id ] ) ) {
				continue;
			}
			$mapped = sanitize_key( (string) $map[ $plan_id ] );
			if ( $mapped !== '' && BizCity_Membership_Plan_Registry::instance()->exists( $mapped ) ) {
				return $mapped;
			}
		}

		return $slug;
	}
}
