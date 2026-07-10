<?php
/**
 * BizCity_Cache_Registry
 *
 * Central catalog of every cache group managed by bizcity-twin-ai and its
 * bundled modules / satellite plugins. Mirrors the pattern of
 * BizCity_Schema_Registry (DB tables) and BizCity_Rewrite_Flush_Registry.
 *
 * Purpose:
 *  1. Provide core/diagnostics with a complete enumeration of managed cache
 *     groups without importing each module's Manager class directly.
 *  2. Allow the Diagnostics panel to list, inspect, and flush any group.
 *  3. Enforce the "declare-before-use" convention so developers know which
 *     groups exist and what keys live in them.
 *  4. Enable bulk flush (flush all groups for a module) from admin UI.
 *
 * This registry CATALOGS groups; it does NOT call BizCity_Cache methods
 * itself (except flush_all_groups() utility). Each Manager retains its own
 * BizCity_Cache::get/set/flush_group calls per R-CACHE rule.
 *
 * ## Usage — in a Manager or bootstrap file (file-load time, outside hooks):
 *
 *   BizCity_Cache_Registry::register( 'bzcc', 'modules.content-creator', array(
 *       'templates_all'           => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'All templates' ),
 *       'templates_active'        => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Active templates' ),
 *       'template_id_{id}'        => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Single template by ID' ),
 *       'skill_sync_fp'           => array( 'ttl' => BizCity_Cache::TTL_MEDIUM, 'desc' => 'Skill-sync fingerprint' ),
 *   ) );
 *
 * ## Group prefix standard (copied from R-CACHE rule in copilot-instructions.md):
 *   bzcc · bztimg · bzdoc · bzpb · bcpro · kg · chat · shell · sched · auto · market
 *
 * @see    docs/rules/PHASE-0-RULE-CACHE.md
 * @see    core/helper/class-bizcity-cache.php  (BizCity_Cache — actual get/set)
 * @since  1.14.1 (2026-06-21)
 * @author Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-21 Johnny Chu] R-CACHE — Central Cache Registry (new file)
final class BizCity_Cache_Registry {

	/**
	 * All registered groups.
	 * Shape: [ group => [ 'module_id' => string, 'keys' => array ] ]
	 *
	 * @var array<string, array>
	 */
	private static $groups = array();

	/* ── Public API ──────────────────────────────────────────────────── */

	/**
	 * Register a cache group.
	 *
	 * MUST be called at file-load time (outside any hook) in the Manager class
	 * file or bootstrap, after the class definition. No DB access.
	 *
	 * @param string $group     Cache group prefix, e.g. 'bzcc'.
	 * @param string $module_id Owner module ID, e.g. 'modules.content-creator'.
	 * @param array  $keys      Key catalog: [ key_pattern => [ 'ttl' => int, 'desc' => string ] ]
	 *                          Wildcards use {placeholder}: 'template_id_{id}'
	 */
	public static function register( $group, $module_id, array $keys = array() ) {
		self::$groups[ (string) $group ] = array(
			'group'     => (string) $group,
			'module_id' => (string) $module_id,
			'keys'      => $keys,
		);
	}

	/**
	 * Return all registered group entries.
	 *
	 * @return array[]
	 */
	public static function get_all() {
		return self::$groups;
	}

	/**
	 * Return the entry for a single group, or null if not registered.
	 *
	 * @param string $group
	 * @return array|null
	 */
	public static function get( $group ) {
		return self::$groups[ (string) $group ] ?? null;
	}

	/**
	 * Check whether a group is registered.
	 *
	 * @param string $group
	 * @return bool
	 */
	public static function is_registered( $group ) {
		return isset( self::$groups[ (string) $group ] );
	}

	/**
	 * Return all groups registered by a given module.
	 *
	 * @param string $module_id
	 * @return array[]
	 */
	public static function get_by_module( $module_id ) {
		return array_filter(
			self::$groups,
			function ( $entry ) use ( $module_id ) {
				return $entry['module_id'] === $module_id;
			}
		);
	}

	/**
	 * Flush all registered groups via BizCity_Cache::flush_group().
	 *
	 * Admin utility — use from diagnostics panel only.
	 *
	 * @return int Number of groups flushed.
	 */
	public static function flush_all_groups() {
		$n = 0;
		foreach ( array_keys( self::$groups ) as $group ) {
			BizCity_Cache::flush_group( $group );
			$n++;
		}
		return $n;
	}

	/**
	 * Flush all groups belonging to a module.
	 *
	 * @param string $module_id
	 * @return int Number of groups flushed.
	 */
	public static function flush_module( $module_id ) {
		$n = 0;
		foreach ( self::$groups as $group => $entry ) {
			if ( $entry['module_id'] === $module_id ) {
				BizCity_Cache::flush_group( $group );
				$n++;
			}
		}
		return $n;
	}
}
