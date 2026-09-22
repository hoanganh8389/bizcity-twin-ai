<?php
/**
 * CRM framework boundary — canonical action authority (PHASE-0.60 C2).
 *
 * This first slice centralizes the existing menu/Inbox decisions without
 * changing resource scope or REST behavior.
 *
 * @package BizCity_Twin_CRM
 */
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BizCity_CRM_Authority', false ) ) {
	final class BizCity_CRM_Authority {
		public static function can( string $action, array $resource = array(), ?array $actor = null ): array {
			$actor = $actor ?: BizCity_CRM_Actor::current( 'be' );
			$user_id = (int) ( $actor['user_id'] ?? 0 );
			$admin = BizCity_CRM_Actor::is_tenant_admin( $actor );
			$ok = false;
			$required = '';
			switch ( $action ) {
				case 'crm.inbox.open':
					$ok = $admin || self::has_crm_staff( $user_id );
					$required = 'bizcity_crm_handle_inbox|staff_policy|manage_options|manage_network';
					break;
				case 'crm.inbox.read':
					$ok = $admin || ( $user_id > 0 && self::has_crm_staff( $user_id ) );
					$required = 'inbox_scope';
					break;
				case 'crm.inbox.handle':
					$ok = $admin || ( class_exists( 'BizCity_CRM_Capabilities' ) && BizCity_CRM_Capabilities::user_can_handle_inbox( $user_id ) );
					$required = 'bizcity_crm_handle_inbox+inbox_scope';
					break;
				case 'crm.channel.manage':
				case 'crm.settings.manage':
				case 'crm.rules.manage':
					$ok = $admin || ( 'crm.rules.manage' === $action && current_user_can( 'bizcity_crm_manage_rules' ) );
					$required = 'manage_options|manage_network';
					break;
				case 'crm.team.manage':
					$ok = $admin || ( class_exists( 'BizCity_CRM_Staff_Policy' ) && BizCity_CRM_Staff_Policy::can( $user_id, 'team.dashboard' )['ok'] );
					$required = 'staff_policy';
					break;
				case 'crm.work.lead':
					$ok = $admin || ( class_exists( 'BizCity_CRM_Staff_Policy' ) && BizCity_CRM_Staff_Policy::can( $user_id, 'task.assign' )['ok'] );
					$required = 'staff_policy.task.assign';
					break;
				case 'crm.reports.view':
					$ok = $admin || current_user_can( 'bizcity_crm_view_reports' );
					$required = 'bizcity_crm_view_reports+report_scope';
					break;
				case 'crm.sales.write':
					$ok = $admin || self::has_crm_staff( $user_id );
					$required = 'crm_staff';
					break;
				case 'crm.ai.use':
					$ok = $admin || self::has_crm_staff( $user_id );
					$required = 'crm_staff+inbox_scope';
					break;
				case 'crm.channel.self_connect':
					$ok = $admin || self::has_crm_staff( $user_id );
					$required = 'crm_staff';
					break;
			}
			return array( 'ok' => (bool) $ok, 'code' => $ok ? 'ok' : 'permission_denied', 'why' => $ok ? '' : 'Tài khoản chưa được cấp quyền cho thao tác CRM này.', 'required' => $required, 'action' => $action );
		}

		public static function menu_cap( string $action ): string {
			if ( function_exists( 'is_super_admin' ) && is_super_admin() ) { return 'manage_network'; }
			if ( in_array( $action, array( 'crm.inbox.open', 'crm.inbox.read', 'crm.inbox.handle' ), true ) && self::has_crm_staff( get_current_user_id() ) ) { return 'bizcity_crm_handle_inbox'; }
		if ( 'crm.rules.manage' === $action ) { return 'bizcity_crm_manage_rules'; }
		return 'manage_options';
		}

		private static function has_crm_staff( int $user_id ): bool {
			if ( $user_id <= 0 ) { return false; }
			if ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_network' ) || user_can( $user_id, 'bizcity_crm_handle_inbox' ) ) { return true; }
			return class_exists( 'BizCity_CRM_Staff_Policy' ) && BizCity_CRM_Staff_Policy::role( $user_id ) !== 'none';
		}
	}
}
