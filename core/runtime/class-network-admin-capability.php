<?php
/**
 * BizCity Twin Brain — Network Super-Admin Capability Bridge
 *
 * R-MSDB/R-DDV: on the mapped multisite network, a Network Super Admin can
 * legitimately have no local `administrator` row (and therefore no
 * `manage_options`) on the blog serving a given mapped domain — see
 * class-channel-rest-api.php HOTFIX-CHANNEL-SUPER-ADMIN (2026-09-21) and
 * class-admin-menu-spa.php HOTFIX (2026-09-19) for the incident this
 * codifies.
 *
 * Every REST permission_callback / admin_menu capability gate across the
 * plugin family that must stay reachable to Super Admins should call
 * self::can_manage() / self::menu_cap() instead of re-deriving the same
 * `manage_options || (is_super_admin() && manage_network)` expression by
 * hand — that duplication is exactly what let several sibling REST
 * controllers fall out of sync with the menu gate that lets the page open
 * in the first place (PHASE-0.63 zalo-bridge/api-gateway 403 incident).
 *
 * Loaded directly from bizcity-twin-ai.php before any module bootstrap, the
 * same way core/runtime/class-rewrite-flush-registry.php is, so it is always
 * available regardless of module load order.
 *
 * @package    Bizcity_Twin_Claw
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Network_Admin_Capability', false ) ) {
	return;
}

final class BizCity_Network_Admin_Capability {

	/**
	 * True for a per-site administrator (manage_options) OR a Network Super
	 * Admin who also holds manage_network. Use this in every REST
	 * permission_callback that should behave like a site "administrator"
	 * check.
	 *
	 * @param int $user_id Optional. 0 = current user.
	 */
	public static function can_manage( int $user_id = 0 ): bool {
		if ( $user_id > 0 ) {
			return user_can( $user_id, 'manage_options' )
				|| ( function_exists( 'is_super_admin' ) && is_super_admin( $user_id ) && user_can( $user_id, 'manage_network' ) );
		}
		return current_user_can( 'manage_options' )
			|| ( function_exists( 'is_super_admin' ) && is_super_admin() && current_user_can( 'manage_network' ) );
	}

	/**
	 * Capability string for add_menu_page()/add_submenu_page(). WordPress
	 * checks this stored capability directly (before any callback runs), so
	 * a Network Super Admin without a local blog role needs 'manage_network'
	 * here or the menu item — and the page behind it — silently disappears.
	 */
	public static function menu_cap(): string {
		return ( function_exists( 'is_super_admin' ) && is_super_admin() ) ? 'manage_network' : 'manage_options';
	}
}
