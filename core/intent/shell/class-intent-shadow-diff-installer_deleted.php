<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent\Shell
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 *
 * Phase 0.16 / Vòng 4 — Task 4.16.9
 * Migration installer for the shadow-mode diff log table.
 *
 * The table records {legacy_response, shell_response, match_score} for each
 * Intent request that runs through both legacy `Intent_Engine::process()` and
 * the new `BizCity_Intent_Shell::handle()`. A daily cron rolls these up into
 * a parity dashboard; once parity ≥99% for 7 consecutive days the rollout
 * percentage is promoted (5% → 25% → 50% → 100%).
 *
 * @since 4.0.0 (Vòng 4)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Intent_Shadow_Diff_Installer {

	const VERSION_OPTION = 'bizcity_intent_shadow_diff_db_version';
	const VERSION        = '1.0.0';
	const TABLE_BASE     = 'bizcity_intent_shadow_diff';

	/** Idempotent installer — run via plugin activation hook. */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = $wpdb->prefix . self::TABLE_BASE;

		dbDelta( "CREATE TABLE `{$table}` (
			`id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			`user_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			`channel`       VARCHAR(40)     NOT NULL DEFAULT '',
			`message_hash`  CHAR(40)        NOT NULL DEFAULT '',
			`message`       TEXT                     NULL,
			`legacy_action` VARCHAR(40)              NULL,
			`shell_action`  VARCHAR(40)              NULL,
			`legacy_resp`   LONGTEXT                 NULL,
			`shell_resp`    LONGTEXT                 NULL,
			`match_score`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			`diff_summary`  TEXT                     NULL,
			`shell_run_id`  VARCHAR(40)              NULL,
			`legacy_ms`     INT UNSIGNED    NOT NULL DEFAULT 0,
			`shell_ms`      INT UNSIGNED    NOT NULL DEFAULT 0,
			`created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			KEY `idx_score_date` (`match_score`, `created_at`),
			KEY `idx_user_date`  (`user_id`, `created_at`),
			KEY `idx_hash`       (`message_hash`)
		) {$charset};" );

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function uninstall(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . self::TABLE_BASE . '`' );
		delete_option( self::VERSION_OPTION );
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_BASE;
	}
}
