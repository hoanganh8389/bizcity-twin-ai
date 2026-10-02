<?php
/**
 * CRM answer for the delegated agent modes of agent-mode-access@1 (PHASE-0.87 CL-13, contract §4).
 *
 * sales / orders / stock / customers are site business data: the agent may use them for a user only when that user is a
 * CRM Staff_Policy `admin` or `supervisor`. Tenant admins are already allowed by the resolver before this filter runs.
 * D-TAA-7 (owner, 2026-10-01; CL-15): `lead` and `agent` also get `customers`, limited to the contacts assigned to them
 * (`customers_scope()` = `person`); the customers pack for them is a per-person pack.
 *
 * // @axis twin-agent-axis@1 block owner_agent
 *
 * @package BizCity_Twin_CRM
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Agent_Mode_Delegate', false ) ) {
	final class BizCity_CRM_Agent_Mode_Delegate {

		const MODES         = array( 'sales', 'orders', 'stock', 'customers' );
		const ROLES         = array( 'admin', 'supervisor' );
		const LIMITED_ROLES = array( 'lead', 'agent' ); // customers only, own assigned contacts (D-TAA-7)

		/** @var array<string,callable> test seam: role(user_id): string */
		public static $readers = array();

		public static function register(): void {
			add_filter( 'bizcity_agent_mode_delegate', array( __CLASS__, 'answer' ), 10, 3 );
			// A staff role change can grant or remove a business mode: tell the bundle re-sync (AMA-6).
			add_action( 'bizcity_crm_staff_role_changed', array( __CLASS__, 'changed' ), 10, 0 );
		}

		/** @param mixed $allowed */
		public static function answer( $allowed, $mode, $user_id ) {
			if ( ! in_array( (string) $mode, self::MODES, true ) ) {
				return $allowed;
			}
			if ( 'customers' === (string) $mode ) {
				return '' !== self::customers_scope( (int) $user_id );
			}
			return in_array( self::role( (int) $user_id ), self::ROLES, true );
		}

		/** Whose customers this person may see through the agent: `shop` (all), `person` (assigned to them) or '' (none). */
		public static function customers_scope( int $user_id ): string {
			$role = self::role( $user_id );
			if ( in_array( $role, self::ROLES, true ) ) {
				return 'shop';
			}
			return in_array( $role, self::LIMITED_ROLES, true ) ? 'person' : '';
		}

		public static function changed(): void {
			if ( class_exists( 'BizCity_Agent_Mode_Access' ) ) {
				BizCity_Agent_Mode_Access::changed();
			}
		}

		private static function role( int $user_id ): string {
			if ( isset( self::$readers['role'] ) ) {
				return (string) call_user_func( self::$readers['role'], $user_id );
			}
			return class_exists( 'BizCity_CRM_Staff_Policy' ) ? BizCity_CRM_Staff_Policy::role( $user_id ) : 'none';
		}
	}
}
