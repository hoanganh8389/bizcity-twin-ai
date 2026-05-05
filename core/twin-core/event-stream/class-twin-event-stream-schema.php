<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Twin_Core
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity_Twin_Event_Stream_Schema — DDL for the single canonical event store.
 *
 * Phase 0.12 Wave A — `bizcity_twin_event_stream` is the ONLY append-allowed
 * table. All state changes flow through BizCity_Twin_Event_Bus::dispatch().
 * Every other "log/audit/event" table becomes a projection (CQRS read view).
 *
 * Per R-EVT-2 — DO NOT add new event/log tables. Extend taxonomy instead.
 * Per R-EVT-6 — append-only. No UPDATE. DELETE only via retention cron > 365d.
 *
 * @since 2026-04-29
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_Event_Stream_Schema {

	const DB_VERSION        = '0.12.1';
	const DB_VERSION_OPTION = 'bizcity_twin_event_stream_db_ver';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_twin_event_stream';
	}

	/**
	 * Create / migrate the event stream table. Safe to call repeatedly.
	 */
	public static function ensure_table(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = function_exists( 'bizcity_get_charset_collate' )
			? bizcity_get_charset_collate()
			: $wpdb->get_charset_collate();

		$table = self::table();

		// NOTE: dbDelta requires PRIMARY KEY on its own line and exact spacing.
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_uuid CHAR(36) NOT NULL,
			trace_id VARCHAR(64) NOT NULL,
			conversation_id BIGINT UNSIGNED NULL,
			session_id VARCHAR(64) NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			event_type VARCHAR(48) NOT NULL,
			event_source VARCHAR(32) NOT NULL,
			parent_event_id BIGINT UNSIGNED NULL,
			parent_event_uuid CHAR(36) NULL,
			payload_json LONGTEXT NOT NULL,
			schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME(3) NOT NULL,
			created_epoch_ms BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_event_uuid (event_uuid),
			KEY idx_trace (trace_id, created_epoch_ms),
			KEY idx_user_time (user_id, created_epoch_ms),
			KEY idx_type_time (event_type, created_epoch_ms),
			KEY idx_session (session_id, created_epoch_ms),
			KEY idx_parent (parent_event_id),
			KEY idx_blog_time (blog_id, created_epoch_ms)
		) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
