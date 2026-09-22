<?php
/**
 * CRM framework boundary — canonical request actor (PHASE-0.60 C1).
 *
 * @package BizCity_Twin_CRM
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Actor', false ) ) {
	final class BizCity_CRM_Actor {
		private static $memo = array();

		/** @return array{user_id:int,blog_id:int,is_super_admin:bool,is_blog_member:bool,surface:string,staff_role:string} */
		public static function current( string $surface = 'be' ): array {
			$surface = in_array( $surface, array( 'be', 'c' ), true ) ? $surface : 'be';
			$user_id = (int) get_current_user_id();
			$memo_key = $surface . ':' . $user_id . ':' . (int) get_current_blog_id();
			if ( isset( self::$memo[ $memo_key ] ) ) { return self::$memo[ $memo_key ]; }
			$is_super = function_exists( 'is_super_admin' ) && is_super_admin( $user_id );
			$blog_member = $user_id > 0 && function_exists( 'is_user_member_of_blog' ) && is_user_member_of_blog( $user_id, get_current_blog_id() );
			$staff_role = class_exists( 'BizCity_CRM_Staff_Policy' ) ? BizCity_CRM_Staff_Policy::role( $user_id ) : 'none';
			return self::$memo[ $memo_key ] = array(
				'user_id' => $user_id,
				'blog_id' => (int) get_current_blog_id(),
				'is_super_admin' => (bool) $is_super,
				'is_blog_member' => (bool) $blog_member,
				'surface' => $surface,
				'staff_role' => $staff_role,
			);
		}

		public static function is_tenant_admin( ?array $actor = null ): bool {
			$actor = $actor ?: self::current( 'be' );
			return ! empty( $actor['is_super_admin'] ) || ( ! empty( $actor['user_id'] ) && user_can( (int) $actor['user_id'], 'manage_options' ) );
		}
	}
}
