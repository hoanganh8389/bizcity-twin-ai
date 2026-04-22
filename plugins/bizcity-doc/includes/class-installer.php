<?php
/**
 * BZDoc Installer — Database table creation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZDoc_Installer {

	/**
	 * Create tables if they don't exist (self-healing — sub-plugin, no activation hook).
	 */
	public static function maybe_create_tables() {
		$current = get_option( 'bzdoc_schema_version', '0' );
		if ( version_compare( $current, BZDOC_SCHEMA_VERSION, '>=' ) ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$sql = "CREATE TABLE {$prefix}bzdoc_documents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			doc_type VARCHAR(20) NOT NULL DEFAULT 'document',
			title VARCHAR(255) NOT NULL DEFAULT '',
			template_name VARCHAR(50) NOT NULL DEFAULT 'blank',
			theme_name VARCHAR(50) NOT NULL DEFAULT 'modern',
			schema_json LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY idx_user_type (user_id, doc_type),
			KEY idx_status (status)
		) {$charset};

		CREATE TABLE {$prefix}bzdoc_project_sources (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			doc_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL DEFAULT '',
			source_type VARCHAR(30) NOT NULL DEFAULT 'file',
			source_url VARCHAR(1000) DEFAULT '',
			attachment_id BIGINT UNSIGNED DEFAULT 0,
			content_text LONGTEXT,
			content_hash VARCHAR(64) DEFAULT '',
			char_count INT UNSIGNED DEFAULT 0,
			token_estimate INT UNSIGNED DEFAULT 0,
			chunk_count INT UNSIGNED DEFAULT 0,
			embedding_model VARCHAR(100) DEFAULT '',
			embedding_status VARCHAR(20) DEFAULT 'pending',
			status VARCHAR(20) DEFAULT 'ready',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_doc (doc_id),
			KEY idx_user (user_id),
			KEY idx_embed_status (embedding_status)
		) {$charset};

		CREATE TABLE {$prefix}bzdoc_project_source_chunks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id BIGINT UNSIGNED NOT NULL,
			doc_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chunk_index INT UNSIGNED DEFAULT 0,
			content TEXT NOT NULL,
			token_count INT UNSIGNED DEFAULT 0,
			embedding LONGTEXT,
			embedding_model VARCHAR(100) DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_source (source_id),
			KEY idx_doc (doc_id),
			KEY idx_source_index (source_id, chunk_index)
		) {$charset};

		CREATE TABLE {$prefix}bzdoc_generations (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			doc_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(20) NOT NULL DEFAULT 'generate',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			prompt TEXT,
			model VARCHAR(100) DEFAULT '',
			tokens_used INT UNSIGNED DEFAULT 0,
			duration_ms INT UNSIGNED DEFAULT 0,
			schema_snapshot LONGTEXT,
			error_message TEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at DATETIME DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_doc (doc_id),
			KEY idx_user_status (user_id, status),
			KEY idx_created (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// v2.2: add schema_snapshot column if missing (dbDelta handles CREATE, but ALTER for existing tables)
		$gen_table = $prefix . 'bzdoc_generations';
		$col_exists = $wpdb->get_var( "SHOW COLUMNS FROM {$gen_table} LIKE 'schema_snapshot'" );
		if ( ! $col_exists ) {
			$wpdb->query( "ALTER TABLE {$gen_table} ADD COLUMN schema_snapshot LONGTEXT AFTER duration_ms" );
		}

		update_option( 'bzdoc_schema_version', BZDOC_SCHEMA_VERSION );
	}
}
