<?php
/**
 * BizCity Twin AI — Guru Bridge schema installer (Phase B / F7.B1).
 *
 * Idempotent dbDelta migration for two bridge tables wiring a Guru
 * (= row in `wp_bizcity_characters`) to (a) tool/skill ids registered by
 * Persona Tool Providers and (b) provider classes themselves.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Persona
 * @since      1.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Guru_Bridge_Installer', false ) ) {
	return;
}

class BizCity_Guru_Bridge_Installer {

	const SCHEMA_VERSION  = '1.0.0';
	const OPTION_VERSION  = 'bizcity_guru_bridge_schema_version';
	const TABLE_SKILLS    = 'bizcity_guru_skills';
	const TABLE_PROVIDERS = 'bizcity_guru_providers';

	/** Run on plugins_loaded — install/upgrade if version drifted. */
	public static function maybe_install(): void {
		if ( get_option( self::OPTION_VERSION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
		update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$tbl_s   = $wpdb->prefix . self::TABLE_SKILLS;
		$tbl_p   = $wpdb->prefix . self::TABLE_PROVIDERS;

		dbDelta(
			"CREATE TABLE {$tbl_s} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				guru_id BIGINT UNSIGNED NOT NULL,
				tool_id VARCHAR(191) NOT NULL,
				tool_class VARCHAR(20) NOT NULL DEFAULT 'producer',
				priority INT NOT NULL DEFAULT 100,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_guru_tool (guru_id, tool_id),
				KEY idx_guru (guru_id, enabled, priority)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tbl_p} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				guru_id BIGINT UNSIGNED NOT NULL,
				provider_class VARCHAR(191) NOT NULL,
				scope_json LONGTEXT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY uniq_guru_provider (guru_id, provider_class),
				KEY idx_guru (guru_id, enabled)
			) {$charset};"
		);
	}

	public static function table_skills(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SKILLS;
	}

	public static function table_providers(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_PROVIDERS;
	}

	public static function table_exists( string $table ): bool {
		return bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
	}
}
