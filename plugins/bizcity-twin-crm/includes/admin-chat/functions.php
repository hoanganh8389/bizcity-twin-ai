<?php
/**
 * BizCity CRM — Admin Chat global helpers (PHASE 3.5 Wave A).
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bizcity_crm_issue_magic_link' ) ) {
	/**
	 * Convenience wrapper for BizCity_CRM_Magic_Link::issue().
	 *
	 * Usage from gitignored bot plugin (e.g. bizcity-zalo-bot):
	 *
	 *     $res = bizcity_crm_issue_magic_link( array(
	 *         'platform' => 'ZALO',
	 *         'chat_id'  => $client_id,
	 *         'bot_id'   => $bot_id,
	 *     ) );
	 *     if ( ! is_wp_error( $res ) ) { $url = $res['url']; }
	 *
	 * @param array $args See BizCity_CRM_Magic_Link::issue.
	 * @return array|WP_Error
	 */
	function bizcity_crm_issue_magic_link( array $args ) {
		if ( ! class_exists( 'BizCity_CRM_Magic_Link' ) ) {
			return new WP_Error( 'bizcity_crm_magic_link_unavailable', 'BizCity CRM not loaded.' );
		}
		return BizCity_CRM_Magic_Link::issue( $args );
	}
}
