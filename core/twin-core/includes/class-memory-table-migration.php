<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory Table Migration — Unified prefix standardization.
 *
 * Renames all memory tables from scattered names to unified `bizcity_memory_*` prefix.
 * Uses SQL RENAME TABLE for zero-data-loss, atomic migration.
 *
 * Old → New mapping:
 *   bizcity_user_memory          → bizcity_memory_users
 *   bizcity_rolling_memory       → bizcity_memory_rolling
 *   bizcity_episodic_memory      → bizcity_memory_episodic
 *   bizcity_webchat_memory       → bizcity_memory_session
 *   bizcity_webchat_notes        → bizcity_memory_notes
 *   bizcity_webchat_research_jobs → bizcity_memory_research
 *
 * Safe to call on every page load — version-gated.
 *
 * @package  BizCity_Twin_Core
 * @version  1.0
 * @since    2026-06-05
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Memory_Table_Migration {

	const MIGRATION_VERSION = '1.0';
	const OPTION_KEY        = 'bizcity_memory_table_migration_ver';

	/**
	 * Table rename map: old_suffix => new_suffix
	 * Full name = $wpdb->prefix . $suffix
	 */
	const RENAME_MAP = [
		'bizcity_user_memory'           => 'bizcity_memory_users',
		'bizcity_rolling_memory'        => 'bizcity_memory_rolling',
		'bizcity_episodic_memory'       => 'bizcity_memory_episodic',
		'bizcity_webchat_memory'        => 'bizcity_memory_session',
		'bizcity_webchat_notes'         => 'bizcity_memory_notes',
		'bizcity_webchat_research_jobs' => 'bizcity_memory_research',
	];

	/**
	 * Run migration if not already done. Safe for every page load.
	 */
	public static function maybe_migrate(): void {
		if ( get_option( self::OPTION_KEY ) === self::MIGRATION_VERSION ) {
			return;
		}

		global $wpdb;
		$renamed = 0;

		foreach ( self::RENAME_MAP as $old_suffix => $new_suffix ) {
			$old_table = $wpdb->prefix . $old_suffix;
			$new_table = $wpdb->prefix . $new_suffix;

			// Skip if old table doesn't exist
			$old_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $old_table ) );
			if ( ! $old_exists ) {
				continue;
			}

			// Skip if new table already exists (avoid collision)
			$new_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $new_table ) );
			if ( $new_exists ) {
				error_log( "[BizCity_Memory_Migration] Skipping {$old_table} — target {$new_table} already exists." );
				continue;
			}

			// Atomic rename
			$result = $wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" );
			if ( $result !== false ) {
				error_log( "[BizCity_Memory_Migration] Renamed {$old_table} → {$new_table}" );
				$renamed++;
			} else {
				error_log( "[BizCity_Memory_Migration] FAILED to rename {$old_table} → {$new_table}: " . $wpdb->last_error );
			}
		}

		// Also migrate DB version options to new naming
		self::migrate_version_options();

		update_option( self::OPTION_KEY, self::MIGRATION_VERSION );
		error_log( "[BizCity_Memory_Migration] Migration complete — {$renamed} tables renamed." );
	}

	/**
	 * Migrate version option keys to match new table names.
	 */
	private static function migrate_version_options(): void {
		$option_map = [
			'bizcity_rolling_memory_db_ver'  => 'bizcity_memory_rolling_db_ver',
			'bizcity_episodic_memory_db_ver' => 'bizcity_memory_episodic_db_ver',
		];

		foreach ( $option_map as $old_key => $new_key ) {
			$val = get_option( $old_key );
			if ( $val !== false ) {
				update_option( $new_key, $val );
				delete_option( $old_key );
			}
		}
	}
}
