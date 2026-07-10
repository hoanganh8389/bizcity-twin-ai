<?php
/**
 * BizCity_Schema_Registry
 *
 * Central catalog of every database table managed by bizcity-twin-ai and
 * its bundled modules / satellite plugins.
 *
 * Purpose:
 *  1. Provide core/diagnostics with a complete enumeration of managed tables
 *     without requiring it to import each module's installer class directly.
 *  2. Enforce "register-before-create" convention: any dbDelta / CREATE TABLE
 *     call MUST be preceded by a ::register() call for that table.
 *  3. Single source-of-truth for expected schema versions so the health tracker
 *     can detect drift (stale) or absence (missing) on any blog.
 *
 * This registry CATALOGS tables; it does NOT run migrations itself.
 * Each module retains its own Installer::maybe_install() method called from
 * its bootstrap.php (via add_action('plugins_loaded', ..., 15)).
 * ::get_diagnostic_status() reads the same version options to report status.
 *
 * Usage in an Installer file (after class definition, at file scope):
 *
 *   BizCity_Schema_Registry::register(
 *       'bizcity_kg_sources',           // table base name, no prefix
 *       'core.knowledge.kg-hub',        // module ID
 *       '3.1',                          // current schema version
 *       'bizcity_kg_sources_version',   // WP option key
 *       array( 'BizCity_KG_Installer', 'install_sources_table' )
 *   );
 *
 * @see    docs/rules/PHASE-0-RULE-CENTRAL-REGISTRY.md §3 R-SR
 * @since  1.3.8
 * @author Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 */

defined( 'ABSPATH' ) || exit;

// [2026-06-09 Johnny Chu] R-CR — Central Schema Registry (new file)
final class BizCity_Schema_Registry {

	/** @var array<string, array> table_base => registration entry */
	private static $tables = array();

	/* ── Public API ──────────────────────────────────────────────────── */

	/**
	 * Register a managed database table.
	 *
	 * MUST be called at file-load time in the Installer class file, after the
	 * class definition. MUST be called before any dbDelta() / CREATE TABLE
	 * statement references the table. This is a hard convention enforced by
	 * the diagnostics probe checking ::is_registered() at boot time.
	 *
	 * @param string   $table_base     Table name WITHOUT DB prefix, e.g. 'bizcity_kg_sources'
	 * @param string   $module_id      Owner module ID, e.g. 'core.knowledge.kg-hub'
	 * @param string   $version        Current expected schema version, e.g. '3.1'
	 * @param string   $version_option WP option key storing installed version per blog
	 * @param callable $installer      Idempotent callable running dbDelta for this table
	 */
	public static function register( $table_base, $module_id, $version, $version_option, $installer ) {
		self::$tables[ (string) $table_base ] = array(
			'table_base'     => (string) $table_base,
			'module_id'      => (string) $module_id,
			'version'        => (string) $version,
			'version_option' => (string) $version_option,
			'installer'      => $installer,
		);
	}

	/**
	 * Return all registered table entries.
	 *
	 * @return array[] Entries keyed by table_base, each containing
	 *                 table_base, module_id, version, version_option, installer.
	 */
	public static function get_all() {
		return self::$tables;
	}

	/**
	 * Check whether a table has been registered.
	 *
	 * Guards in probes and installers:
	 *   if ( ! BizCity_Schema_Registry::is_registered('my_table') ) {
	 *       trigger_error('Table my_table used but never registered.', E_USER_WARNING);
	 *   }
	 *
	 * @param  string $table_base Base name WITHOUT prefix.
	 * @return bool
	 */
	public static function is_registered( $table_base ) {
		return isset( self::$tables[ (string) $table_base ] );
	}

	/**
	 * Return diagnostic status for every registered table on the current blog.
	 *
	 * Called by core/diagnostics health probes — NOT on every page request.
	 * Reads one get_option() per table (cheap) plus a SHOW TABLES query per table
	 * (only on the diagnostics admin page).
	 *
	 * @return array[] Each element: {
	 *     table_base        string   Base name without prefix
	 *     module_id         string   Owning module
	 *     expected_version  string   Version declared in ::register()
	 *     installed_version string   Version stored in wp_options (or '' if absent)
	 *     table_exists      bool     True if SHOW TABLES returns the table
	 *     status            string   'ok' | 'stale' | 'missing'
	 * }
	 */
	public static function get_diagnostic_status() {
		global $wpdb;
		$result = array();

		foreach ( self::$tables as $entry ) {
			$installed_ver = (string) get_option( $entry['version_option'], '' );
			$full_name     = $wpdb->prefix . $entry['table_base'];

			// [2026-06-21 Johnny Chu] R-SHOW-TABLES
			$exists = bizcity_tbl_exists( $full_name );

			if ( ! $exists ) {
				$status = 'missing';
			} elseif ( $installed_ver !== $entry['version'] ) {
				$status = 'stale';
			} else {
				$status = 'ok';
			}

			$result[] = array(
				'table_base'        => $entry['table_base'],
				'module_id'         => $entry['module_id'],
				'expected_version'  => $entry['version'],
				'installed_version' => $installed_ver,
				'table_exists'      => $exists,
				'status'            => $status,
			);
		}

		return $result;
	}

	/**
	 * Return a flat list of all registered module IDs.
	 * Useful for diagnostics overview pages.
	 *
	 * @return string[] Unique module_id values.
	 */
	public static function get_module_ids() {
		$ids = array();
		foreach ( self::$tables as $entry ) {
			$ids[ $entry['module_id'] ] = true;
		}
		return array_keys( $ids );
	}
}
