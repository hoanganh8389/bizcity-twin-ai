<?php
/**
 * BizCity_MCP_Installer — R-CR/R-DCL compliant installer for the 4 tables
 * owned by core/mcp.
 *
 * Tables:
 *  - bizcity_mcp_api_keys           client credential -> scope/notebook binding
 *  - bizcity_mcp_retrieval_snapshots  immutable brain.search results (R7)
 *  - bizcity_mcp_context_packs        document.build_context_pack output (Wave E)
 *  - bizcity_mcp_audit_log            per-call audit trail (R-CRON-META-style evidence)
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
		if ( get_option( self::DB_VERSION_OPTION, '' ) === self::DB_VERSION ) {
			return;
		}
		self::install();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
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

		$sql[] = "CREATE TABLE {$p}bizcity_mcp_audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			trace_id VARCHAR(64) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			client_id VARCHAR(100) NOT NULL DEFAULT '',
			client_name VARCHAR(150) NOT NULL DEFAULT '',
			tool_name VARCHAR(100) NOT NULL DEFAULT '',
			request_hash CHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
			input_meta_json LONGTEXT NULL,
			output_meta_json LONGTEXT NULL,
			error_code VARCHAR(60) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY tool_name (tool_name),
			KEY client_id (client_id),
			KEY created_at (created_at),
			KEY trace_id (trace_id),
			KEY user_created (user_id, created_at)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}

// [2026-07-27 Johnny Chu] PHASE-0.53-MCP — R-CR register-before-create, 4 tables.
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register( 'bizcity_mcp_api_keys', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
	BizCity_Schema_Registry::register( 'bizcity_mcp_retrieval_snapshots', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
	BizCity_Schema_Registry::register( 'bizcity_mcp_context_packs', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
	BizCity_Schema_Registry::register( 'bizcity_mcp_audit_log', 'core.mcp', BizCity_MCP_Installer::DB_VERSION, BizCity_MCP_Installer::DB_VERSION_OPTION, array( 'BizCity_MCP_Installer', 'install' ) );
}
