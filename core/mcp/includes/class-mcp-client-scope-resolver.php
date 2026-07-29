<?php
/**
 * BizCity_MCP_Client_Scope_Resolver — maps an authenticated MCP client
 * context to the exact set of notebook IDs it is allowed to touch.
 *
 * Reuses BizCity_KG_Notebook_Service::list_for_user() (the canonical
 * ACL-respecting notebook listing) as the base "what this WP user can see"
	 * set, then caps it by the current Twin GPT/Channel Gateway policy. The
	 * policy is re-read per request so changes in My MCP policy apply to OAuth
	 * clients without requiring a new key.
 *
 * This is a capability filter only — it NEVER runs its own SQL against
 * bizcity_kg_notebooks; only BizCity_KG_Notebook_Service does that, per the
 * source design doc's "reuse canonical retrieval, no new SQL" constraint.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, notebook scope resolver.
final class BizCity_MCP_Client_Scope_Resolver {

	/**
	 * @param array $auth_ctx Output of BizCity_MCP_Auth::authenticate().
	 * @return int[] Notebook IDs this client may read.
	 */
	public static function allowed_notebook_ids( array $auth_ctx ) {
		$user_id = isset( $auth_ctx['user_id'] ) ? (int) $auth_ctx['user_id'] : 0;
		if ( $user_id <= 0 || ! class_exists( 'BizCity_KG_Notebook_Service' ) ) {
			return array();
		}

		$policy = self::current_policy( $user_id );
		if ( empty( $policy ) ) {
			return array();
		}

		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — resolve the live admin/Guru notebook set first; key rows are snapshots and must not override a saved policy.
		$ids = self::policy_notebook_ids( $policy );
		if ( empty( $policy['mcp_exclusion_mode'] ) && isset( $policy['mcp_allowed_notebook_ids'] ) ) {
			$legacy = self::positive_ids( $policy['mcp_allowed_notebook_ids'] );
			$ids    = empty( $legacy ) ? array() : array_values( array_intersect( $ids, $legacy ) );
		} else {
			$excluded = self::positive_ids( $policy['mcp_excluded_notebook_ids'] );
			$ids      = array_values( array_diff( $ids, $excluded ) );
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	private static function current_policy( $user_id ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — read the saved control-plane policy for every MCP capability check.
		$policy = get_option( 'bizcity_twinweb_grounding_policy_' . (int) get_current_blog_id(), array() );
		if ( ! is_array( $policy ) || empty( $policy ) ) {
			return array();
		}
		$policy['user_id'] = (int) $user_id;
		return $policy;
	}

	private static function policy_notebook_ids( array $policy ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — derive scope from all site administrators in auto mode or the selected Guru's attached notebooks.
		$admin_ids = self::admin_user_ids( isset( $policy['user_id'] ) ? (int) $policy['user_id'] : 0 );
		if ( empty( $admin_ids ) ) {
			return array();
		}

		$guru_id = ! empty( $policy['brain_auto_mode'] ) ? 0 : (int) ( $policy['guru_id'] ?? 0 );
		if ( $guru_id <= 0 && empty( $policy['brain_auto_mode'] ) && class_exists( 'BizCity_TwinWeb_Binding_Bootstrap' ) ) {
			// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — legacy policy fallback follows the saved TWINWEB binding for Guru notebook scope.
			$guru_id = (int) BizCity_TwinWeb_Binding_Bootstrap::resolve_character_id();
		}
		$ids     = array();
		foreach ( $admin_ids as $admin_id ) {
			$rows = BizCity_KG_Notebook_Service::instance()->list_for_user( $admin_id, array( 'limit' => 500 ) );
			foreach ( (array) $rows as $row ) {
				$row_character_id = is_array( $row ) ? (int) ( $row['character_id'] ?? 0 ) : (int) ( $row->character_id ?? 0 );
				$row_id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : (int) ( $row->id ?? 0 );
				if ( $guru_id > 0 && $row_character_id !== $guru_id ) {
					continue;
				}
				$ids[] = $row_id;
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private static function admin_user_ids( $fallback_user_id ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — enumerate only users with the site administrator capability.
		$users = get_users( array( 'capability' => 'manage_options', 'fields' => 'ID', 'number' => 100 ) );
		$ids   = array_values( array_unique( array_filter( array_map( 'intval', (array) $users ) ) ) );
		if ( empty( $ids ) && $fallback_user_id > 0 && user_can( $fallback_user_id, 'manage_options' ) ) {
			$ids = array( (int) $fallback_user_id );
		}
		return $ids;
	}

	private static function positive_ids( $raw ) {
		// [2026-07-28 Johnny Chu] PHASE-0.53-MCP-TWINWEB — normalize notebook IDs without allowing sentinel zero values.
		return array_values( array_unique( array_filter( array_map( 'intval', (array) $raw ), function ( $id ) { return $id > 0; } ) ) );
	}

	/**
	 * @return true|WP_Error
	 */
	public static function assert_notebook_allowed( array $auth_ctx, $notebook_id ) {
		$allowed = self::allowed_notebook_ids( $auth_ctx );
		if ( ! in_array( (int) $notebook_id, $allowed, true ) ) {
			return new WP_Error(
				BizCity_MCP_Error::NOTEBOOK_ACCESS_DENIED,
				'Notebook không thuộc quyền của client này.',
				array( 'status' => 403, 'notebook_id' => (int) $notebook_id )
			);
		}
		return true;
	}
}
