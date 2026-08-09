<?php
/**
 * BizCity_MCP_Tool_Policy — per-blog admin allowlist for MCP tools.
 *
 * This is a SECOND, independent gate layered on top of the existing
 * wp-config.php wave rollback constants (BIZCITY_MCP_*_TOOLS_ENABLED):
 *
 *   wp-config constant  → "is this wave even loaded on this deploy?"
 *                         (devops/rollback decision, PHASE-0.54-MCP §11)
 *   BizCity_MCP_Tool_Policy → "which of the loaded tools may THIS site's
 *                         admin actually expose to MCP clients?"
 *                         (site admin decision, Channel Gateway → MCP Access
 *                         → "Cấu hình tools" checkbox UI)
 *
 * A tool is effectively callable only when BOTH gates allow it. Policy is
 * stored per-blog in wp_options (single JSON map keyed by tool name) —
 * no new DB table, no R-DCL schema entry needed.
 *
 * Fail-closed default: any tool not explicitly present in the stored map
 * (including tools added by a later plugin update, before the admin has
 * ever saved the settings screen) falls back to `default_enabled_for()`,
 * which only allow-lists `brain.*` reads plus the simple content authoring
 * actions (create/update draft, list/get posts, list templates). Every
 * other domain (page.*, business.*, report.*, commerce.*, document.*) is
 * OFF until an admin explicitly checks the box.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-30 (PHASE-0.54-MCP Wave Q)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — new file, admin tool allowlist on top of wave rollback flags.
final class BizCity_MCP_Tool_Policy {

	const OPTION = 'bizcity_mcp_tool_policy';

	/**
	 * Tool name prefixes that are ON by default (read-only Brain tools).
	 * @var string[]
	 */
	const DEFAULT_ENABLED_PREFIXES = array( 'brain.' );

	/**
	 * Individual tool names that are ON by default even though their
	 * domain prefix (`content.`) is otherwise opt-in. These are exactly
	 * the "đăng bài viết, quản lý bài viết đơn giản" actions requested by
	 * the site admin default policy.
	 * @var string[]
	 */
	const DEFAULT_ENABLED_TOOLS = array(
		'content.list_posts',
		'content.get_post',
		'content.get_templates',
		'content.create_draft',
		'content.update_draft',
	);

	/**
	 * Diagnostics probe sentinel — bypasses the admin policy gate so
	 * dispatch-reachability tests exercise the real handler regardless of
	 * the site's current checkbox state. Scope checks still apply the
	 * normal way; this only bypasses the *policy* layer, matching the
	 * existing `scopes => ['*']` bypass already used by core.mcp.gateway.
	 */
	const DIAGNOSTICS_CLIENT_ID = '__diagnostics__';

	/**
	 * @return bool True when the tool is enabled for MCP clients on this site.
	 */
	public static function is_enabled( $tool_name, array $ctx = array() ) {
		if ( isset( $ctx['client_id'] ) && (string) $ctx['client_id'] === self::DIAGNOSTICS_CLIENT_ID ) {
			// [2026-07-30 Johnny Chu] PHASE-0.54-MCP Wave Q — diagnostics dispatch-proof steps bypass the admin policy gate on purpose.
			return true;
		}
		$name = self::normalize_name( $tool_name );
		$map  = self::get_enabled_map();
		if ( array_key_exists( $name, $map ) ) {
			return (bool) $map[ $name ];
		}
		return self::default_enabled_for( $name );
	}

	/**
	 * @return bool True when a tool is ON by the built-in default rule
	 * (used both as the fallback for unknown tools and to pre-check the
	 * settings UI the first time it renders, before anything is saved).
	 */
	public static function default_enabled_for( $tool_name ) {
		$name = self::normalize_name( $tool_name );
		foreach ( self::DEFAULT_ENABLED_PREFIXES as $prefix ) {
			if ( strpos( $name, $prefix ) === 0 ) {
				return true;
			}
		}
		return in_array( $name, self::DEFAULT_ENABLED_TOOLS, true );
	}

	/**
	 * @return array<string,bool> Stored policy map (tool_name => enabled). Empty when never saved.
	 */
	public static function get_enabled_map() {
		$stored = get_option( self::OPTION, null );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $name => $enabled ) {
			$out[ self::normalize_name( $name ) ] = (bool) $enabled;
		}
		return $out;
	}

	/**
	 * Persist a full policy map derived from the admin's checkbox selection.
	 *
	 * @param string[] $enabled_tool_names   Tool names the admin left checked.
	 * @param string[] $all_known_tool_names Every tool name currently registered (from the tool registry), so unchecked tools are explicitly recorded as false rather than silently falling back to defaults forever.
	 * @return array<string,bool> The saved map.
	 */
	public static function save( array $enabled_tool_names, array $all_known_tool_names ) {
		$enabled = array();
		foreach ( $enabled_tool_names as $name ) {
			$enabled[ self::normalize_name( $name ) ] = true;
		}
		$map = array();
		foreach ( $all_known_tool_names as $name ) {
			$normalized = self::normalize_name( $name );
			if ( $normalized === '' ) {
				continue;
			}
			$map[ $normalized ] = isset( $enabled[ $normalized ] );
		}
		update_option( self::OPTION, $map, false );
		return $map;
	}

	/**
	 * Tool names use `[a-z0-9_]+(\.[a-z0-9_]+)+` (e.g. `brain.search`,
	 * `commerce.list_orders`) — sanitize_key() would strip the dot, so a
	 * dedicated normalizer is used instead.
	 */
	private static function normalize_name( $name ) {
		return preg_replace( '/[^a-z0-9_.]/', '', strtolower( (string) $name ) );
	}
}
