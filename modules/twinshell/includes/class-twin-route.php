<?php
/**
 * Twin Shell — canonical link builder (Twin Route Contract v1, R-ROUTE-4).
 *
 * [2026-09-18 Johnny Chu - Chu Hoàng Anh] PHASE-0-RULE-URL-ROUTE P1 — the ONE place every
 * surface (ActivityBar item, cross-plugin link, email/notification, REST response) is meant
 * to build a URL that opens a plugin inside the shell, instead of hand-writing
 * `page=bizcity-twinchat&plugin=...` or `/twin/?plugin=...` strings. Hand-built strings are
 * exactly how the Channels entry's own `page=bizchat-gateway-spa` used to leak through the
 * relay and silently overwrite the host's `page=` (see PHASE-0-RULE-URL-ROUTE.md R-ROUTE-4).
 *
 * This is additive: nothing in the shell yet calls this class from PHP (P1 only builds the
 * contract). Existing hand-built links (e.g. Channel Gateway's `buildCrmFullUrl()`) migrate to
 * the JS twin `TwinRoute.href()` in P2 without needing this PHP helper to be wired anywhere
 * first — both exist so either side (PHP-rendered menu, REST response) or (React component)
 * can build a canonical link without duplicating the "admin gets the wrapper, everyone else
 * gets /twin/" branching logic.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinShell
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Route {

	/**
	 * Build a URL that opens `$plugin_id` at `$route` inside the shell.
	 *
	 * Admins get the wp-admin wrapper (`admin.php?page=bizcity-twinchat`, native admin bar +
	 * sidebar); everyone else gets the standalone `/twin/`. `$route` is validated per R-ROUTE-7
	 * and always carried as the opaque `r` parameter — it is NEVER concatenated into a physical
	 * path or used as a URL by itself; the shell always joins it to the registry's own entry URL
	 * for `$plugin_id`, so a plugin that later changes how it is hosted does not break a link
	 * anyone already saved or shared.
	 *
	 * @param string $plugin_id Registry id, e.g. 'crm'.
	 * @param string $route     Plugin-relative route, e.g. '/inbox/13/conv/88?rail=channel'.
	 *                          '' (default) opens the plugin's own default screen.
	 * @return string Empty string if `$plugin_id` is empty.
	 */
	public static function url( $plugin_id, $route = '' ) {
		$plugin_id = sanitize_key( (string) $plugin_id );
		if ( '' === $plugin_id ) {
			return '';
		}

		$args = array( 'plugin' => $plugin_id );
		$route = (string) $route;
		if ( '' !== $route && class_exists( 'BizCity_Twin_Shell_Page' ) && BizCity_Twin_Shell_Page::is_safe_route( $route ) ) {
			// add_query_arg() never encodes its values — pre-encode, matching how `_iurl`/`r`
			// are already handled everywhere else they cross a redirect boundary in this module.
			$args['r'] = rawurlencode( $route );
		}

		if ( current_user_can( 'manage_options' ) ) {
			$args['bizcity_admin_wrapper'] = '1';
			return add_query_arg( $args, admin_url( 'admin.php?page=bizcity-twinchat' ) );
		}

		return class_exists( 'BizCity_Twin_Shell_Page' )
			? BizCity_Twin_Shell_Page::shell_url( $args )
			: add_query_arg( $args, home_url( '/twin/' ) );
	}
}
