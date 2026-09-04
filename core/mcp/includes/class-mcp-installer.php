<?php
/**
 * BizCity_MCP_Installer — R-CR/R-DCL compliant installer for the 4 tables
 * owned by core/mcp.
 *
 * Tables:
 *  - bizcity_mcp_api_keys           client credential -> scope/notebook binding
 *  - bizcity_mcp_retrieval_snapshots  immutable brain.search results (R7)
 *  - bizcity_mcp_context_packs        document.build_context_pack output (Wave E)
 *  - bizcity_mcp_audit_log            retired legacy projection; MCP audit is JSONL-only
 *
 * R-DCL: schema is declared in core/diagnostics/changelog/core.mcp.json
 * BEFORE this file's dbDelta() calls (see that file for column-level history).
 * R-CR: ::register() calls at file scope below, before install() ever runs.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\MCP
 * @since      2026-07-27 (PHASE-0.53-MCP Wave A)
 */

defined( 'ABSPATH' ) || exit;

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — new file, installer for 4 MCP tables.
final class BizCity_MCP_Installer {

	const DB_VERSION        = '1.1.0'; // [2026-07-28 Johnny Chu] PHASE-0.53-MCP — R-DCL 1.1.0 ownership/audit indexes.
	const DB_VERSION_OPTION = 'bizcity_mcp_db_version';

	/**
	 * Idempotent — only runs dbDelta() when the stored option differs from
	 * DB_VERSION. Called from core/mcp/bootstrap.php on plugins_loaded.
	 */
	public static function ensure() {
		$installed = (string) get_option( self::DB_VERSION_OPTION, '' );
		// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — cloned shards can carry an updated version option while MCP tables are still physically missing.
		if ( $installed === self::DB_VERSION && self::has_required_tables() ) {
			return;
		}
		self::install();
		if ( self::has_required_tables() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	private static function has_required_tables(): bool {
		global $wpdb;

		$required_tables = array(
			$wpdb->prefix . 'bizcity_mcp_api_keys',
			$wpdb->prefix . 'bizcity_mcp_retrieval_snapshots',
			$wpdb->prefix . 'bizcity_mcp_context_packs',
		);
		foreach ( $required_tables as $table_name ) {
			if ( ! self::table_exists( $table_name ) ) {
				return false;
			}
		}

		return true;
	}

	private static function table_exists( string $table_name ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table_name
			)
		);
	}

	/**
	 * Idempotent dbDelta install/upgrade for all 4 tables. Safe to call
	 * multiple times (dbDelta only ADDs missing tables/columns/indexes,
	 * never DROPs — per R-DCL auto-create safety contract).
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;
		$sql             = array();

		$sql[] = "CREATE TABLE {$p}bizcity_mcp_api_keys (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			key_hash CHAR(64) NOT NULL,
			client_id VARCHAR(100) NOT NULL DEFAULT '',
			client_name VARCHAR(150) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			scopes TEXT NULL,
			allowed_notebook_ids TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_used_at DATETIME NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY key_hash (key_hash),
			KEY client_id (client_id),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}bizcity_mcp_retrieval_snapshots (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			snapshot_uuid VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			client_id VARCHAR(100) NOT NULL DEFAULT '',
			cache_key CHAR(64) NOT NULL,
			original_query TEXT NULL,
			normalized_query TEXT NULL,
			scope_json LONGTEXT NULL,
			profile_name VARCHAR(100) NOT NULL DEFAULT '',
			profile_version VARCHAR(32) NOT NULL DEFAULT '',
			kg_revision VARCHAR(100) NOT NULL DEFAULT '',
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY snapshot_uuid (snapshot_uuid),
			KEY reuse_lookup (client_id, cache_key, expires_at),
			KEY user_id (user_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}bizcity_mcp_context_packs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			pack_uuid VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			client_id VARCHAR(100) NOT NULL DEFAULT '',
			document_type VARCHAR(50) NOT NULL DEFAULT '',
			source_refs_json LONGTEXT NULL,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY pack_uuid (pack_uuid),
			KEY client_id (client_id),
			KEY user_created (user_id, created_at)
		) {$charset_collate};";


		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	const AUDIT_RETENTION_HOOK  = 'bizcity_mcp_audit_log_retention';
	const AUDIT_RETENTION_DAYS  = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep SQL MCP rollback window for one week.
	const AUDIT_RETENTION_BATCH = 500;

	/**
	 * [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — register bounded audit cleanup.
	 * Full evidence already dual-writes to JSONL via BizCity_MCP_File_Logger, so the SQL
	 * table only needs to retain a short recent window for relational lookups.
	 */
	public static function register_retention_cron(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => 'core.mcp.audit_log_retention',
			'hook'        => self::AUDIT_RETENTION_HOOK,
			'interval'    => 'daily',
			'owner'       => 'core/mcp',
			'description' => 'Bounded retention sweep for the MCP call audit log (full evidence remains in JSONL).',
			'retention'   => self::AUDIT_RETENTION_DAYS,
		) );
	}

	/**
	 * [2026-08-01 Johnny Chu] PHASE-1.24-LOG-RETENTION — delete old rows only from the scheduled cron context.
	 */
	public static function gc_audit_log(): void {
		// [2026-08-27 Johnny Chu] PHASE-1.30-JSONL-ONLY — retired MCP SQL retention is disabled; MCP JSONL owns retention and approved cleanup owns DROP.
		$deleted = 0;
		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'mcp_audit_log_retention_deleted' => $deleted ) ) );
			$cron->note_event( 'mcp_audit_log_retention', array( 'deleted' => $deleted, 'retention_days' => self::AUDIT_RETENTION_DAYS ) );
		}
	}
}

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — R-CR register-before-create, 3 active tables.
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register( 'bizcity_mcp_api_keys', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
	BizCity_Schema_Registry::register( 'bizcity_mcp_retrieval_snapshots', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
	BizCity_Schema_Registry::register( 'bizcity_mcp_context_packs', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
}
