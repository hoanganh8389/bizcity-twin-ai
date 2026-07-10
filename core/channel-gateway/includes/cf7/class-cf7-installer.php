<?php
/**
 * CF7 Channel — DB Installer
 *
 * Creates `bizcity_cf7_submissions` table.
 * R-CR.2: register via BizCity_Schema_Registry before dbDelta.
 * R-DCL:  changelog at core/diagnostics/changelog/modules.cf7-channel.json.
 *
 * @package BizCity_Channel_Gateway
 * @since   2026-06-13
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_CF7_Installer {

	// [2026-07-03 Johnny Chu] PHASE-0.46 FIX — bump to 1.2.0: add gift_orders_json column (store WC order IDs per submission)
	const SCHEMA_VERSION  = '1.2.0';
	const VERSION_OPTION  = 'bizcity_cf7_channel_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_cf7_submissions';
	}

	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SCHEMA_VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		// [2026-06-13 Johnny Chu] PHASE-CG-CF7 — create bizcity_cf7_submissions
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		global $wpdb;
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE `{$t}` (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id          BIGINT UNSIGNED NOT NULL,
			form_title       VARCHAR(190)    NOT NULL DEFAULT '',
			raw_data         LONGTEXT        NOT NULL,
			mapped_data      LONGTEXT        NULL,
			email            VARCHAR(190)    NULL,
			phone            VARCHAR(32)     NULL,
			crm_contact_id   BIGINT UNSIGNED NULL,
			crm_action       VARCHAR(16)     NULL,
			crm_error        TEXT            NULL,
			source_url       TEXT            NULL,
			user_agent       VARCHAR(255)    NULL,
			ip_address       VARCHAR(45)     NULL,
			submitted_at     DATETIME        NOT NULL,
			deleted_at       DATETIME        NULL DEFAULT NULL,
			gift_orders_json LONGTEXT        NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_form      (form_id),
			KEY idx_email     (email),
			KEY idx_phone     (phone),
			KEY idx_contact   (crm_contact_id),
			KEY idx_submitted (submitted_at),
			KEY idx_deleted   (deleted_at)
		) {$charset};";

		dbDelta( $sql );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION );
	}

	public static function table_exists(): bool {
		global $wpdb;
		$t = self::table();
		return bizcity_tbl_exists( $t ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
	}
}
