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
	 * Pass $force=true to bypass schema-version check (used when a doc insert fails).
	 */
	public static function maybe_create_tables( bool $force = false ) {
		$current = get_option( 'bzdoc_schema_version', '0' );
		if ( ! $force && version_compare( $current, BZDOC_SCHEMA_VERSION, '>=' ) ) {
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
			notebook_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY idx_user_type (user_id, doc_type),
			KEY idx_status (status),
			KEY idx_notebook (notebook_id)
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

		// v2.3: notebook_id binding column on documents (TwinShell primitive integration).
		$doc_table = $prefix . 'bzdoc_documents';
		$nb_col = $wpdb->get_var( "SHOW COLUMNS FROM {$doc_table} LIKE 'notebook_id'" );
		if ( ! $nb_col ) {
			$wpdb->query( "ALTER TABLE {$doc_table} ADD COLUMN notebook_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER status" );
			$wpdb->query( "ALTER TABLE {$doc_table} ADD KEY idx_notebook (notebook_id)" );
		}

		// v2.4 (Sprint 0★ S0.2 — PHASE-0-RULE-SKELETON): snapshot the notebook
		// skeleton_version at the moment a document was generated. Used by the
		// FE stale-banner (S0.14) to warn when the upstream skeleton has moved
		// beyond the version this document was authored against.
		$ssv_col = $wpdb->get_var( "SHOW COLUMNS FROM {$doc_table} LIKE 'source_skeleton_version'" );
		if ( ! $ssv_col ) {
			$wpdb->query( "ALTER TABLE {$doc_table} ADD COLUMN source_skeleton_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER notebook_id" );
		}

		// v2.5 (PHASE-0-RULE-OUTPUT-FILES — R-OF Documents Hub):
		// generator/origin/job_id/media_id/parent_event_uuid columns + indexes.
		// Enables CRM Documents tab + Notebook Files tab to render the unified
		// upload-or-generated stream from a single canonical store.
		self::ensure_v2_5_columns( $doc_table );

		update_option( 'bzdoc_schema_version', BZDOC_SCHEMA_VERSION );
	}

	/**
	 * v2.5 column patch — idempotent, safe to call multiple times.
	 * Adds the R-OF columns + matching indexes + backfills legacy rows.
	 */
	private static function ensure_v2_5_columns( string $doc_table ): void {
		global $wpdb;

		$add_col = function ( string $col, string $ddl ) use ( $wpdb, $doc_table ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SHOW COLUMNS FROM {$doc_table} LIKE %s",
				$col
			) );
			if ( ! $exists ) {
				$wpdb->query( "ALTER TABLE {$doc_table} {$ddl}" );
			}
		};

		$add_col( 'generator',         "ADD COLUMN generator VARCHAR(64) NOT NULL DEFAULT '' AFTER source_skeleton_version" );
		$add_col( 'origin',            "ADD COLUMN origin VARCHAR(20) NOT NULL DEFAULT 'generated' AFTER generator" );
		$add_col( 'job_id',            "ADD COLUMN job_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER origin" );
		$add_col( 'media_id',          "ADD COLUMN media_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER job_id" );
		$add_col( 'file_url',          "ADD COLUMN file_url TEXT NULL AFTER media_id" );
		$add_col( 'mime',              "ADD COLUMN mime VARCHAR(100) NOT NULL DEFAULT '' AFTER file_url" );
		$add_col( 'size_bytes',        "ADD COLUMN size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER mime" );
		$add_col( 'parent_event_uuid', "ADD COLUMN parent_event_uuid CHAR(36) NULL DEFAULT NULL AFTER size_bytes" );

		$add_index = function ( string $name, string $cols ) use ( $wpdb, $doc_table ) {
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SHOW INDEX FROM {$doc_table} WHERE Key_name = %s",
				$name
			) );
			if ( ! $exists ) {
				$wpdb->query( "ALTER TABLE {$doc_table} ADD KEY {$name} ({$cols})" );
			}
		};

		$add_index( 'idx_nb_doctype',  'notebook_id, doc_type' );
		$add_index( 'idx_origin_st',   'origin, status' );
		$add_index( 'idx_gen_doctype', 'generator, doc_type' );
		$add_index( 'idx_media',       'media_id' );

		// Backfill legacy rows: existing rows have no job_id / generator → mark
		// as native bizcity-doc generated content (the only writer pre-2.5).
		$wpdb->query( "UPDATE {$doc_table} SET generator = 'bizcity-doc' WHERE generator = ''" );
		$wpdb->query( "UPDATE {$doc_table} SET origin = 'generated' WHERE origin = ''" );
	}
}

