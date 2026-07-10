<?php
/**
 * Broadcast Installer — tạo bảng bizcity_cg_broadcasts + bizcity_cg_broadcast_recipients.
 *
 * Cache Contract (group: bzcast)
 *   Keys:
 *     broadcast_list_{status}_{page}  — TTL_MEDIUM — danh sách broadcast
 *     broadcast_{id}                  — TTL_MEDIUM — single row
 *     progress_{id}                   — TTL_SHORT  — tiến độ gửi
 *   Invalidations: insert/update/delete → BizCity_Cache::flush_group('bzcast')
 *
 * [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — new installer.
 *
 * @package BizCity_Channel_Gateway
 * @since   PHASE-CG-BROADCAST (2026-06-27)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Broadcast_Installer' ) ) {
	return;
}

class BizCity_Broadcast_Installer {

	const SCHEMA_VERSION  = '1.0.0';
	const VERSION_OPTION  = 'bizcity_cg_broadcast_schema_version';

	/**
	 * Run installer (idempotent, ADD-only via dbDelta).
	 * Called from bootstrap on admin_init or activation.
	 */
	public static function install() {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$t_bc    = $wpdb->prefix . 'bizcity_cg_broadcasts';
		$t_rcpt  = $wpdb->prefix . 'bizcity_cg_broadcast_recipients';

		$sql_broadcasts = "CREATE TABLE `{$t_bc}` (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name          VARCHAR(255)    NOT NULL DEFAULT '',
			type          VARCHAR(20)     NOT NULL DEFAULT 'zns',
			status        VARCHAR(20)     NOT NULL DEFAULT 'draft',
			meta_json     LONGTEXT        NULL,
			total_count   INT UNSIGNED    NOT NULL DEFAULT 0,
			sent_count    INT UNSIGNED    NOT NULL DEFAULT 0,
			failed_count  INT UNSIGNED    NOT NULL DEFAULT 0,
			batch_size    SMALLINT UNSIGNED NOT NULL DEFAULT 10,
			delay_sec     SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			created_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at    DATETIME        NOT NULL,
			started_at    DATETIME        NULL,
			done_at       DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_created (created_at)
		) {$charset};";

		$sql_recipients = "CREATE TABLE `{$t_rcpt}` (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			broadcast_id  BIGINT UNSIGNED NOT NULL,
			name          VARCHAR(255)    NOT NULL DEFAULT '',
			phone         VARCHAR(50)     NULL,
			email         VARCHAR(190)    NULL,
			custom_data   LONGTEXT        NULL,
			status        VARCHAR(20)     NOT NULL DEFAULT 'queued',
			error         TEXT            NULL,
			sent_at       DATETIME        NULL,
			PRIMARY KEY  (id),
			KEY idx_bc_status (broadcast_id, status),
			KEY idx_bc_id     (broadcast_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_broadcasts );
		dbDelta( $sql_recipients );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Check if schema needs upgrade and run install() if so.
	 */
	public static function maybe_upgrade() {
		$current = get_option( self::VERSION_OPTION, '' );
		if ( version_compare( $current, self::SCHEMA_VERSION, '<' ) ) {
			self::install();
		}
	}
}

// [2026-06-27 Johnny Chu] PHASE-CG-BROADCAST — Schema Registry (R-CR.2).
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_cg_broadcasts',
		'core.cg.broadcast',
		BizCity_Broadcast_Installer::SCHEMA_VERSION,
		BizCity_Broadcast_Installer::VERSION_OPTION,
		array( 'BizCity_Broadcast_Installer', 'install' )
	);
}
