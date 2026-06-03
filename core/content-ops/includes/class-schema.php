<?php
/**
 * Content Ops — Schema Installer (idempotent ADD-only)
 *
 * Source of truth for table layout: core/diagnostics/changelog/core.content-ops.json
 *
 * R-DCL: NO DROP / NO MODIFY in auto-create. Migration tay phải qua Site Provisioner.
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_Ops_Schema {

	const VERSION       = '1.0.0';
	const OPTION_KEY    = 'bizcity_content_ops_db_version';

	public static function maybe_install(): void {
		$installed = (string) get_option( self::OPTION_KEY, '' );
		if ( $installed === self::VERSION ) {
			return;
		}
		self::install();
		update_option( self::OPTION_KEY, self::VERSION, false );
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$wpdb->prefix}bizcity_posts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wp_post_id BIGINT UNSIGNED NULL DEFAULT NULL,
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(500) NOT NULL DEFAULT '',
			body LONGTEXT NULL,
			excerpt TEXT NULL,
			media_json LONGTEXT NULL,
			kind VARCHAR(32) NOT NULL DEFAULT 'post',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			source VARCHAR(20) NOT NULL DEFAULT 'manual',
			scheduled_at DATETIME NULL DEFAULT NULL,
			published_at DATETIME NULL DEFAULT NULL,
			tone VARCHAR(64) NOT NULL DEFAULT '',
			meta_json LONGTEXT NULL,
			content_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL DEFAULT NULL,
			deleted_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_blog_status (blog_id, status),
			KEY idx_blog_scheduled (blog_id, scheduled_at),
			KEY idx_blog_published (blog_id, published_at),
			KEY idx_wp_post (wp_post_id),
			KEY idx_author (author_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}bizcity_post_targets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			platform VARCHAR(32) NOT NULL DEFAULT '',
			instance_id VARCHAR(128) NOT NULL DEFAULT '',
			channel_message_id VARCHAR(255) NOT NULL DEFAULT '',
			publish_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			published_at DATETIME NULL DEFAULT NULL,
			error TEXT NULL,
			response_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_post (post_id),
			KEY idx_platform (platform),
			KEY idx_status (publish_status),
			KEY idx_blog_published (blog_id, published_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}bizcity_brand_assets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			attachment_id BIGINT UNSIGNED NULL DEFAULT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'image',
			url VARCHAR(1000) NOT NULL DEFAULT '',
			mime VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			tags_json TEXT NULL,
			usage_count INT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(20) NOT NULL DEFAULT 'upload',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_blog_type (blog_id, type),
			KEY idx_attachment (attachment_id)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}bizcity_schedule_queue (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			target_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			run_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			lock_token VARCHAR(64) NOT NULL DEFAULT '',
			lock_expires_at DATETIME NULL DEFAULT NULL,
			last_error TEXT NULL,
			finished_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_due (status, run_at),
			KEY idx_post (post_id),
			KEY idx_lock (lock_expires_at)
		) {$charset};" );

		dbDelta( "CREATE TABLE {$wpdb->prefix}bizcity_ai_jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NULL DEFAULT NULL,
			kind VARCHAR(32) NOT NULL DEFAULT '',
			model VARCHAR(128) NOT NULL DEFAULT '',
			prompt_hash CHAR(64) NOT NULL DEFAULT '',
			request_json LONGTEXT NULL,
			response_json LONGTEXT NULL,
			tokens_in INT UNSIGNED NOT NULL DEFAULT 0,
			tokens_out INT UNSIGNED NOT NULL DEFAULT 0,
			cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0,
			latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'ok',
			error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_blog_kind (blog_id, kind),
			KEY idx_post (post_id),
			KEY idx_created (created_at)
		) {$charset};" );
	}

	/**
	 * Return list of expected tables for diagnostic probe.
	 */
	public static function tables(): array {
		global $wpdb;
		return array(
			$wpdb->prefix . 'bizcity_posts',
			$wpdb->prefix . 'bizcity_post_targets',
			$wpdb->prefix . 'bizcity_brand_assets',
			$wpdb->prefix . 'bizcity_schedule_queue',
			$wpdb->prefix . 'bizcity_ai_jobs',
		);
	}
}
