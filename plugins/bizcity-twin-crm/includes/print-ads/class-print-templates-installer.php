<?php
/**
 * BizCity CRM — Print-Ads schema installer (M-PA.W1).
 *
 * Self-contained dbDelta installer for two tables:
 *   • {prefix}bzcrm_print_templates    — template library (3 sources: local_seed, bizcity_remote, user_custom)
 *   • {prefix}bzcrm_print_generations  — audit trail of LLM-generated print-ad attachments
 *
 * Version tracked via option `bzcrm_print_ads_db_ver`.
 * Schema source of truth: core/diagnostics/changelog/modules.crm.print-ads.json (R-DCL).
 *
 * @package BizCity_Twin_CRM
 * @since   0.32.3 (M-PA.W1)
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Print_Templates_Installer', false ) ) { return; }

final class BizCity_CRM_Print_Templates_Installer {

	const DB_VERSION    = '1.0.0';
	const OPTION_KEY    = 'bzcrm_print_ads_db_ver';
	const OPTION_SEEDED = 'bzcrm_print_ads_seeded';

	public static function tbl_templates(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzcrm_print_templates';
	}

	public static function tbl_generations(): string {
		global $wpdb;
		return $wpdb->prefix . 'bzcrm_print_generations';
	}

	/**
	 * Run dbDelta + first-time seed. Idempotent.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$t1 = self::tbl_templates();
		$t2 = self::tbl_generations();

		// NOTE: dbDelta is picky about spacing and KEY syntax. Two spaces after PRIMARY KEY.
		$sql1 = "CREATE TABLE {$t1} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(120) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT 'local_seed',
			remote_id VARCHAR(64) NULL,
			template_type VARCHAR(40) NOT NULL DEFAULT 'print_ad',
			title VARCHAR(190) NOT NULL DEFAULT '',
			description TEXT NULL,
			ref_image_url TEXT NULL,
			base_prompt LONGTEXT NOT NULL,
			qr_slot_json LONGTEXT NULL,
			brand_slot_json LONGTEXT NULL,
			target_aspect VARCHAR(20) NOT NULL DEFAULT '1:1',
			recommended_model VARCHAR(40) NOT NULL DEFAULT 'flux-pro',
			sort_order INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_slug (slug),
			UNIQUE KEY uniq_source_remote (source, remote_id),
			KEY idx_type_status (template_type, status, sort_order)
		) {$charset};";

		$sql2 = "CREATE TABLE {$t2} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT(20) UNSIGNED NOT NULL,
			template_id BIGINT(20) UNSIGNED NOT NULL,
			attachment_id BIGINT(20) UNSIGNED NULL,
			model VARCHAR(40) NOT NULL DEFAULT '',
			merged_prompt LONGTEXT NULL,
			overrides_json LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			error TEXT NULL,
			created_by BIGINT(20) UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_campaign (campaign_id, created_at),
			KEY idx_template (template_id),
			KEY idx_attachment (attachment_id)
		) {$charset};";

		dbDelta( $sql1 );
		dbDelta( $sql2 );

		update_option( self::OPTION_KEY, self::DB_VERSION, false );

		// First-time seed (idempotent).
		self::maybe_seed();
	}

	public static function maybe_upgrade(): void {
		$current = (string) get_option( self::OPTION_KEY, '' );
		if ( version_compare( $current, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * Trigger seed loader from data/print-templates-seed.json. Skips if any
	 * row exists in templates table (so admins can clear it manually then
	 * call self::seed_from_file(true) to re-import).
	 */
	public static function maybe_seed(): void {
		global $wpdb;
		$tbl = self::tbl_templates();
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl}" );
		if ( $count > 0 ) {
			return;
		}
		require_once BIZCITY_CRM_DIR . '/includes/print-ads/seed-print-templates.php';
		bzcrm_seed_print_templates( false );
		update_option( self::OPTION_SEEDED, gmdate( 'c' ), false );
	}
}
