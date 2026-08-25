<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BZPB_Installer {

	private static $schema_version_key = 'bzpb_schema_version';

	public static function maybe_create_tables(): void {
		$current = get_option( self::$schema_version_key, '0' );
		if ( version_compare( $current, BZPB_SCHEMA_VERSION, '>=' ) ) {
			return;
		}
		self::create_tables();
		update_option( self::$schema_version_key, BZPB_SCHEMA_VERSION );
	}

	private static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		
		// Projects table
		$projects_table = $wpdb->prefix . 'bzpb_projects';
		$projects_sql = "CREATE TABLE {$projects_table} (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id           BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title             VARCHAR(255)    NOT NULL DEFAULT '',
			site_config       LONGTEXT        NOT NULL,
			thumbnail_url     VARCHAR(500)    NOT NULL DEFAULT '',
			status            VARCHAR(20)     NOT NULL DEFAULT 'draft',
			is_template       TINYINT(1)      NOT NULL DEFAULT 0,
			published_page_id BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at        DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at        DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY (id),
			KEY idx_user_id (user_id),
			KEY idx_status  (status)
		) {$charset};";

		// Submissions table
		$submissions_table = $wpdb->prefix . 'bzpb_submissions';
		$submissions_sql = "CREATE TABLE {$submissions_table} (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name         VARCHAR(255)    NOT NULL DEFAULT '',
			email        VARCHAR(255)    NOT NULL DEFAULT '',
			phone        VARCHAR(50)     NOT NULL DEFAULT '',
			subject      VARCHAR(500)    NOT NULL DEFAULT '',
			message      LONGTEXT        NOT NULL,
			full_data    LONGTEXT        NOT NULL,
			form_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			form_title   VARCHAR(255)    NOT NULL DEFAULT '',
			user_agent   VARCHAR(500)    NOT NULL DEFAULT '',
			ip_address   VARCHAR(100)    NOT NULL DEFAULT '',
			source       VARCHAR(20)     NOT NULL DEFAULT 'unknown',
			status       VARCHAR(20)     NOT NULL DEFAULT 'unread',
			submitted_at DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			read_at      DATETIME        NULL DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_status (status),
			KEY idx_email (email),
			KEY idx_submitted_at (submitted_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// Generations (version history) table
		$generations_table = $wpdb->prefix . 'bzpb_generations';
		$generations_sql = "CREATE TABLE {$generations_table} (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			project_id       BIGINT UNSIGNED NOT NULL,
			user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action           VARCHAR(50)     NOT NULL DEFAULT 'generate',
			status           VARCHAR(20)     NOT NULL DEFAULT 'completed',
			prompt           TEXT            NOT NULL DEFAULT '',
			model            VARCHAR(100)    NOT NULL DEFAULT '',
			tokens_used      INT UNSIGNED    NOT NULL DEFAULT 0,
			duration_ms      INT UNSIGNED    NOT NULL DEFAULT 0,
			config_snapshot  LONGTEXT        NOT NULL,
			error_message    TEXT            NOT NULL DEFAULT '',
			created_at       DATETIME        NOT NULL DEFAULT '0000-00-00 00:00:00',
			completed_at     DATETIME        NULL DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_project_id (project_id),
			KEY idx_user_id (user_id),
			KEY idx_created_at (created_at)
		) {$charset};";

		dbDelta( $projects_sql );
		dbDelta( $submissions_sql );
		dbDelta( $generations_sql );
	}
}
