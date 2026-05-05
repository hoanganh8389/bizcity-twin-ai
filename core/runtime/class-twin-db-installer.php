<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Runtime
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * BizCity_Twin_DB_Installer
 *
 * Dedicated DB installer for the Phase 0.13 / Vòng 2 runtime tables.
 * Intentionally isolated from BizCity_Trace_Store so schema changes
 * here don't collide with legacy trace tables.
 *
 * Tables managed:
 *   {prefix}bizcity_twin_runs  — RunState persistence (one row per agent run)
 *   {prefix}bizcity_twin_hil   — HIL decisions inbox (approve/reject signals)
 *
 * Usage:
 *   BizCity_Twin_DB_Installer::install();   // idempotent, uses dbDelta
 *   BizCity_Twin_DB_Installer::maybe_install(); // only if version mismatch
 *
 * @since 1.13.0
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Twin_DB_Installer {

	/**
	 * Schema version stored in wp_options (per-blog on multisite).
	 * Bump this whenever you change column definitions.
	 */
	const VERSION        = '1.0.0';
	const VERSION_OPTION = 'bizcity_twin_db_version';

	/**
	 * Table base names (without prefix).
	 * Access via static::runs_table() / static::hil_table() to get full prefixed name.
	 */
	const RUNS_BASE = 'bizcity_twin_runs';
	const HIL_BASE  = 'bizcity_twin_hil';

	/* ----------------------------------------------------------------
	 * Public API
	 * ---------------------------------------------------------------- */

	/**
	 * Install or upgrade tables only when the stored version doesn't match.
	 * Cheap to call on every request (reads one option, bails early when current).
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::VERSION ) {
			return;
		}
		self::install();
	}

	/**
	 * Unconditionally run dbDelta — creates tables if absent, adds missing columns.
	 * Safe to call multiple times (dbDelta is additive only).
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$runs    = $wpdb->prefix . self::RUNS_BASE;
		$hil     = $wpdb->prefix . self::HIL_BASE;

		// ── bizcity_twin_runs ─────────────────────────────────────────
		// One row per agent run.  state_json holds the full BizCity_Twin_RunState.
		dbDelta( "CREATE TABLE `{$runs}` (
			`id`               BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`run_id`           VARCHAR(40)      NOT NULL,
			`conversation_id`  VARCHAR(80)      NOT NULL DEFAULT '',
			`agent_name`       VARCHAR(64)      NOT NULL DEFAULT '',
			`user_id`          BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			`status`           VARCHAR(20)      NOT NULL DEFAULT 'running',
			`state_json`       LONGTEXT         NOT NULL,
			`interruptions`    LONGTEXT                  NULL,
			`context_snapshot` LONGTEXT                  NULL,
			`parent_run_id`    VARCHAR(40)               NULL DEFAULT NULL,
			`trace_id`         VARCHAR(80)      NOT NULL DEFAULT '',
			`created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `uniq_run_id` (`run_id`),
			KEY `idx_conv`        (`conversation_id`, `updated_at`),
			KEY `idx_user_status` (`user_id`, `status`),
			KEY `idx_paused`      (`status`, `updated_at`)
		) {$charset};" );

		// ── bizcity_twin_hil ──────────────────────────────────────────
		// One row per tool-call decision (approve / reject).
		// REPLACE INTO is used so re-decisions overwrite gracefully.
		dbDelta( "CREATE TABLE `{$hil}` (
			`id`         BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
			`run_id`     VARCHAR(40)      NOT NULL,
			`call_id`    VARCHAR(80)      NOT NULL,
			`decision`   VARCHAR(20)      NOT NULL DEFAULT 'approved',
			`reason`     TEXT                      NULL,
			`decided_by` BIGINT UNSIGNED  NOT NULL DEFAULT 0,
			`created_at` DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (`id`),
			UNIQUE KEY `uniq_run_call` (`run_id`, `call_id`),
			KEY `idx_run` (`run_id`)
		) {$charset};" );

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Drop both tables (called on plugin uninstall — use with care).
	 */
	public static function uninstall(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . self::RUNS_BASE . '`' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . self::HIL_BASE . '`' );
		delete_option( self::VERSION_OPTION );
	}

	/* ----------------------------------------------------------------
	 * Helpers for other classes (avoid hard-coding table names)
	 * ---------------------------------------------------------------- */

	/**
	 * Full prefixed name of the runs table for the current blog.
	 * Always reads $wpdb->prefix so it works correctly on multisite.
	 */
	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RUNS_BASE;
	}

	/**
	 * Full prefixed name of the HIL decisions table for the current blog.
	 */
	public static function hil_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::HIL_BASE;
	}
}
