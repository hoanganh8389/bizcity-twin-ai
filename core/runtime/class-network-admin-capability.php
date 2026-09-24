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
		// [2026-09-24] HOTFIX-SINGLE-SITE-MENU-CAP — on single-site is_super_admin() is true for every
		// administrator (delete_users) but nobody holds manage_network, so the menu locked out the admin.
		// See docs/audits/SINGLE-SITE-ADMIN-MENU-CAP-REGRESSION-2026-09-24.md.
		return self::is_network_super_admin() ? 'manage_network' : 'manage_options';
	}

	/**
	 * True only for a real Network Super Admin — requires multisite, where
	 * `manage_network` actually exists.
	 *
	 * @param int $user_id Optional. 0 = current user.
	 */
	public static function is_network_super_admin( int $user_id = 0 ): bool {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() || ! function_exists( 'is_super_admin' ) ) {
			return false;
		}
		return $user_id > 0 ? is_super_admin( $user_id ) : is_super_admin();
	}

	/**
	 * [2026-09-23 Claude Sonnet 5] Network Super Admin capability gap — GLOBAL fix.
	 *
	 * self::can_manage()/self::menu_cap() only protect call sites that were
	 * edited to use them. Every OTHER plugin on this network — sibling plugins
	 * like bizcity-llm-router, bizcity-zalo-bot, bizcity-pagebuilder, and any
	 * future one — still gates its own admin pages/AJAX/REST with the bare
	 * `current_user_can( 'manage_options' )` / `add_menu_page( ..., 'manage_options', ... )`
	 * pattern, and has no reason to know about or call into bizcity-twin-ai's
	 * helper. Patching each of those call sites one repo at a time doesn't
	 * scale and will always be one file behind.
	 *
	 * Hooking `user_has_cap` here fixes it at the one point WordPress itself
	 * funnels every `current_user_can()`/`user_can()` check through — including
	 * the capability check `add_menu_page()`/`add_submenu_page()` register and
	 * WP core uses to decide whether a sidebar item renders at all. A Network
	 * Super Admin with no local per-blog `administrator` row on the mapped
	 * domain now transparently has `manage_options` true everywhere, in every
	 * plugin, with zero code changes required in any of them.
	 *
	 * Deliberately narrow: only the single primitive capability `manage_options`
	 * is injected, and only for confirmed Network Super Admins — this does not
	 * grant any other capability (e.g. `edit_users`, `install_plugins`) that a
	 * real site administrator role would separately carry.
	 */
	/**
	 * [2026-09-23 Claude Sonnet 5] MUST be called from the require site
	 * (bizcity-twin-ai.php), unconditionally, NOT from the bottom of this
	 * file. PHP performs compile-time early binding for an unconditional
	 * top-level class declaration, so `class_exists( __CLASS__, false )`
	 * is already true by the time this file's own top-of-file idempotency
	 * guard runs — even on the very first, only load — which made a
	 * trailing `self::bootstrap()` call placed after the class in this
	 * same file silently never execute (confirmed via ReflectionClass +
	 * ad hoc CLI diagnostics: class fully defined and every method
	 * individually callable, but add_filter() in bootstrap() never ran).
	 */
	private static $booted = false;

	public static function bootstrap(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'user_has_cap', array( __CLASS__, 'filter_user_has_cap' ), 10, 4 );
	}

	/**
	 * @param array   $allcaps All capabilities of the user.
	 * @param array   $caps    Required primitive capabilities for the requested capability.
	 * @param array   $args    [0] Requested capability, [1] user ID, [2..] additional args.
	 * @param WP_User $user    The user object.
	 * @return array
	 */
	public static function filter_user_has_cap( $allcaps, $caps, $args, $user ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			return $allcaps;
		}
		// [2026-09-24] HOTFIX-SINGLE-SITE-MENU-CAP — single-site is_super_admin() means "has delete_users";
		// without the multisite guard any custom role with delete_users would be granted manage_options.
		if ( empty( $user->ID ) || ! self::is_network_super_admin( (int) $user->ID ) ) {
			return $allcaps;
		}
		$allcaps['manage_options'] = true;
		return $allcaps;
	}
}
