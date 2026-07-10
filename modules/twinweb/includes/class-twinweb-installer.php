<?php
/**
 * TwinWeb — DB Installer
 *
 * Creates `bizcity_twinweb_threads` table.
 * Wave 1: table created on activation + version bump.
 *
 * Schema: modules/twinweb/docs/PHASE-0-TWINWEB-APP-MENUS.md §2.1
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Installer' ) ) { return; }

class BizCity_TwinWeb_Installer {

	const VERSION        = '1.0.2'; // [2026-06-22 Johnny Chu] PHASE-TWINWEB — add project_id col to threads (no new table)
	const VERSION_OPTION = 'bizcity_twinweb_db_version';

	/**
	 * Run installer if schema version is stale.
	 * Call from bootstrap.php at plugins_loaded.
	 */
	public static function maybe_install() {
		$installed = get_option( self::VERSION_OPTION, '' );
		if ( version_compare( $installed, self::VERSION, '>=' ) ) {
			return;
		}
		self::install();
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — threads table (per-user chat thread list)
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';
		$sql   = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			guest_sid   VARCHAR(64)     NOT NULL DEFAULT '',
			app_type    VARCHAR(30)     NOT NULL DEFAULT 'chat',
			title       VARCHAR(255)    NOT NULL DEFAULT '',
			pinned      TINYINT(1)      NOT NULL DEFAULT 0,
			archived    TINYINT(1)      NOT NULL DEFAULT 0,
			last_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			meta_json   LONGTEXT,
			PRIMARY KEY (id),
			KEY idx_user   (user_id),
			KEY idx_guest  (guest_sid(32)),
			KEY idx_app    (app_type),
			KEY idx_last   (last_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — register with Schema Registry (R-CR.2)
		if ( class_exists( 'BizCity_Schema_Registry' ) ) {
			BizCity_Schema_Registry::register(
				'bizcity_twinweb_threads',
				'modules.twinweb',
				self::VERSION,
				self::VERSION_OPTION,
				array( __CLASS__, 'install' )
			);
		}

		// [2026-06-22 Johnny Chu] PHASE-TWINWEB — add project_id column for project grouping.
		// Uses bizcity_webchat_projects (existing table) — no new table created.
		// idempotent ALTER via information_schema check (R-SHOW-TABLES).
		self::ensure_project_id_column();

		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — auto-create WP page with [bizcity_twin]
		// shortcode so admins have a ready-to-use public URL without manual setup.
		self::maybe_create_page();
	}

	/**
	 * Create a WordPress page titled "Twin AI" with [bizcity_twin] shortcode
	 * if no such page exists yet. Idempotent — skips if option already set.
	 */
	public static function maybe_create_page() {
		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — option guards idempotency
		if ( get_option( 'bizcity_twinweb_page_id' ) ) {
			return;
		}

		// Check if any published page already has the shortcode
		$existing = get_pages( array(
			's'          => '[bizcity_twin]',
			'post_status' => 'publish',
		) );
		if ( ! empty( $existing ) ) {
			update_option( 'bizcity_twinweb_page_id', $existing[0]->ID );
			return;
		}

		$page_id = wp_insert_post( array(
			'post_title'   => 'Twin AI',
			'post_name'    => 'twin-ai',
			'post_content' => '[bizcity_twin height="100vh"]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'meta_input'   => array( '_bizcity_twinweb_page' => '1' ),
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'bizcity_twinweb_page_id', (int) $page_id );
		}
	}

	/**
	 * Idempotent ALTER: add project_id column to bizcity_twinweb_threads.
	 *
	 * [2026-06-22 Johnny Chu] PHASE-TWINWEB — project grouping stores project_id
	 * inside the existing threads table. No new table is created. Projects live
	 * in bizcity_webchat_projects (existing webchat table).
	 *
	 * Uses information_schema check per R-SHOW-TABLES (never SHOW TABLES).
	 */
	public static function ensure_project_id_column() {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_twinweb_threads';

		// Table must exist before we can ALTER it
		$tbl_exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
		if ( ! $tbl_exists ) {
			return;
		}

		$col_exists = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table, 'project_id'
		) );
		if ( $col_exists ) {
			return; // already there
		}

		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN project_id VARCHAR(50) NOT NULL DEFAULT '' AFTER user_id, ADD KEY idx_project (project_id)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
