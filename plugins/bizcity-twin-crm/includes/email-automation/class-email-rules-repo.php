<?php
/**
 * BizCity CRM — Email Event Rules Repo (PHASE 0.37.1)
 *
 * CRUD for `wp_bizcity_crm_email_event_rules`. Pairs with the dispatcher:
 *   ON event_key → for each enabled rule → render templates → wp_mail / send_via.
 *
 * @package BizCity_Twin_CRM
 * @since   0.37.1
 */

defined( 'ABSPATH' ) || exit;

class BizCity_CRM_Email_Rules_Repo {

	private static function table(): string {
		return BizCity_CRM_DB_Installer_V2::tbl_email_event_rules();
	}

	/**
	 * [2026-06-24 Johnny Chu] R-SHOW-TABLES — dual cache table-existence check.
	 * Uses information_schema instead of SHOW TABLES to avoid metadata scan on multisite.
	 */
	private static function table_exists(): bool {
		static $s = array();
		$tbl = self::table();
		if ( isset( $s[ $tbl ] ) ) {
			return $s[ $tbl ];
		}
		$ck      = 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $tbl );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			global $wpdb;
			$present = (int) (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
					$tbl
				)
			);
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		$s[ $tbl ] = (bool) $present;
		return $s[ $tbl ];
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_rules( string $event_key = '' ): array {
		// [2026-06-24 Johnny Chu] R-SHOW-TABLES — bail early if table not yet created on this blog
		if ( ! self::table_exists() ) {
			return array();
		}
		global $wpdb;
		$sql = "SELECT * FROM " . self::table() . " WHERE deleted_at IS NULL";
		if ( $event_key !== '' ) {
			$sql = $wpdb->prepare( $sql . " AND event_key=%s", $event_key );
		}
		$sql .= " ORDER BY id DESC";
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — decode emoji entities for FE textarea display
		return array_map( array( __CLASS__, 'decode_row' ), $rows );
	}

	public static function get( int $id ): ?array {
		// [2026-06-24 Johnny Chu] R-SHOW-TABLES — bail if table missing
		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM " . self::table() . " WHERE id=%d AND deleted_at IS NULL", $id ),
			ARRAY_A
		);
		if ( ! $row ) { return null; }
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — decode emoji entities for FE textarea display
		return self::decode_row( $row );
	}

	public static function create( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = self::sanitize( $data );
		// [2026-06-20 Johnny Chu] HOTFIX — backward-compat DB không có cột attachment_url
		if ( ! self::has_attachment_url_column() ) {
			unset( $row['attachment_url'] );
		}
		// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — guard new columns on old installs
		if ( ! self::has_reply_type_column() ) {
			unset( $row['reply_type'], $row['ai_config_json'] );
		}
		// [2026-06-24 Johnny Chu] HOTFIX — guard cf7_notice column on old installs
		if ( ! self::has_cf7_notice_column() ) {
			unset( $row['cf7_notice'] );
		}

		if ( empty( $row['name'] ) ) {
			throw new \RuntimeException( 'email_rule_name_required' );
		}
		if ( empty( $row['event_key'] ) ) {
			throw new \RuntimeException( 'email_rule_event_key_required' );
		}
		if ( empty( $row['to_template'] ) ) {
			throw new \RuntimeException( 'email_rule_to_required' );
		}
		if ( empty( $row['subject_template'] ) ) {
			throw new \RuntimeException( 'email_rule_subject_required' );
		}

		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$ok = $wpdb->insert( self::table(), $row );
		if ( false === $ok ) {
			$err = (string) $wpdb->last_error;
			throw new \RuntimeException( 'email_rule_insert_failed: ' . ( $err !== '' ? $err : 'unknown_db_error' ) );
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;
		$row = self::sanitize( $data );
		// [2026-06-20 Johnny Chu] HOTFIX — backward-compat DB không có cột attachment_url
		if ( ! self::has_attachment_url_column() ) {
			unset( $row['attachment_url'] );
		}
		// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — guard new columns on old installs
		if ( ! self::has_reply_type_column() ) {
			unset( $row['reply_type'], $row['ai_config_json'] );
		}
		// [2026-06-24 Johnny Chu] HOTFIX — guard cf7_notice column on old installs
		if ( ! self::has_cf7_notice_column() ) {
			unset( $row['cf7_notice'] );
		}
		$row['updated_at'] = current_time( 'mysql' );
		$wpdb->update( self::table(), $row, array( 'id' => $id ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->update( self::table(), array( 'deleted_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	private static function sanitize( array $d ): array {
		$out = array();
		if ( array_key_exists( 'name', $d ) )             { $out['name']             = (string) $d['name']; }
		if ( array_key_exists( 'event_key', $d ) )        { $out['event_key']        = (string) $d['event_key']; }
		if ( array_key_exists( 'account_id', $d ) )       { $out['account_id']       = $d['account_id'] !== null && $d['account_id'] !== '' ? (int) $d['account_id'] : null; }
		if ( array_key_exists( 'is_enabled', $d ) )       { $out['is_enabled']       = ! empty( $d['is_enabled'] ) ? 1 : 0; }
		if ( array_key_exists( 'to_template', $d ) )      { $out['to_template']      = (string) $d['to_template']; }
		if ( array_key_exists( 'cc_template', $d ) )      { $out['cc_template']      = (string) ( $d['cc_template']  ?? '' ); }
		if ( array_key_exists( 'bcc_template', $d ) )     { $out['bcc_template']     = (string) ( $d['bcc_template'] ?? '' ); }
		if ( array_key_exists( 'subject_template', $d ) ) { $out['subject_template'] = (string) $d['subject_template']; }
		if ( array_key_exists( 'body_template', $d ) ) {
			// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — wp_encode_emoji converts 4-byte emoji
			// (e.g. 📌 U+1F4CC) to HTML entities (&#x1F4CC;) so they survive on MySQL utf8
			// (3-byte max) databases. Email clients render HTML entities as emoji. ✅
			$body = (string) ( $d['body_template'] ?? '' );
			$out['body_template'] = function_exists( 'wp_encode_emoji' ) ? wp_encode_emoji( $body ) : $body;
		}
		if ( array_key_exists( 'conditions_json', $d ) ) {
			$out['conditions_json'] = is_string( $d['conditions_json'] ) ? $d['conditions_json'] : wp_json_encode( $d['conditions_json'] );
		}
		// [2026-06-19 Johnny Chu] PHASE-CG-CF7 — PDF ebook attachment URL
		if ( array_key_exists( 'attachment_url', $d ) ) {
			$url = (string) ( $d['attachment_url'] ?? '' );
			$out['attachment_url'] = $url !== '' ? esc_url_raw( $url ) : null;
		}
		// [2026-06-24 Johnny Chu] HOTFIX — cf7_notice: plain-text source for CF7 thank-you message
		if ( array_key_exists( 'cf7_notice', $d ) ) {
			$txt = (string) ( $d['cf7_notice'] ?? '' );
			$out['cf7_notice'] = function_exists( 'wp_encode_emoji' ) ? wp_encode_emoji( $txt ) : $txt;
		}
		// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — reply_type + ai_config_json
		if ( array_key_exists( 'reply_type', $d ) ) {
			$rt = (string) ( $d['reply_type'] ?? 'template' );
			$out['reply_type'] = in_array( $rt, array( 'template', 'ai_reply' ), true ) ? $rt : 'template';
		}
		if ( array_key_exists( 'ai_config_json', $d ) ) {
			$cfg = $d['ai_config_json'];
			if ( is_array( $cfg ) ) { $cfg = wp_json_encode( $cfg ); }
			$out['ai_config_json'] = is_string( $cfg ) && $cfg !== '' ? $cfg : null;
		}
		return $out;
	}

	/**
	 * Decode emoji HTML entities back to real chars for FE textarea display.
	 * wp_encode_emoji() stored them as &#xXXXX; — this reverses that for editing.
	 * The dispatcher uses the DB value directly (HTML entities are fine for email HTML).
	 *
	 * [2026-06-19 Johnny Chu] PHASE-CG-CF7 — emoji decode for FE
	 */
	private static function decode_row( array $row ): array {
		if ( isset( $row['body_template'] ) && $row['body_template'] !== '' ) {
			$row['body_template'] = html_entity_decode( (string) $row['body_template'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		// [2026-06-24 Johnny Chu] HOTFIX — decode cf7_notice emoji entities for FE textarea
		if ( isset( $row['cf7_notice'] ) && $row['cf7_notice'] !== '' ) {
			$row['cf7_notice'] = html_entity_decode( (string) $row['cf7_notice'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return $row;
	}

	// [2026-06-24 Johnny Chu] PHASE-CF7-AUTO — public alias so REST controller can trigger
	// self-heal (adds reply_type + ai_config_json columns if missing) before querying them.
	public static function ensure_reply_type_column(): bool {
		return self::has_reply_type_column();
	}

	// [2026-06-24 Johnny Chu] HOTFIX — public alias to trigger cf7_notice column self-heal
	public static function ensure_cf7_notice_column(): bool {
		return self::has_cf7_notice_column();
	}

	/**
	 * Self-heal: ensure reply_type + ai_config_json columns exist on older installs.
	 * Uses information_schema (no SHOW COLUMNS) + dual cache (static + wp_cache).
	 * [2026-06-24 Johnny Chu] PHASE-CF7-AUTO
	 */
	private static function has_reply_type_column(): bool {
		static $has_col = null;
		if ( null !== $has_col ) { return (bool) $has_col; }
		global $wpdb;
		$table = self::table();
		$ck    = 'bz_col_' . (int) get_current_blog_id() . '_' . crc32( $table . '.reply_type' );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s LIMIT 1',
				$table, 'reply_type'
			) );
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		if ( ! $present ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `reply_type` VARCHAR(16) NOT NULL DEFAULT 'template', ADD COLUMN `ai_config_json` LONGTEXT NULL" ); // phpcs:ignore
			wp_cache_delete( $ck, 'bizcity_tbl' );
			$present = 1;
		}
		$has_col = (bool) $present;
		return $has_col;
	}

	private static function has_attachment_url_column(): bool {
		static $has_column = null;
		if ( null !== $has_column ) {
			return (bool) $has_column;
		}

		global $wpdb;
		$table = self::table();
		// [2026-06-22 Johnny Chu] EMAIL-ATTACH-FIX — R-SHOW-TABLES: use information_schema.COLUMNS
		// instead of banned SHOW COLUMNS LIKE. Dual-cache: wp_cache (1h) + static.
		$ck      = 'bz_col_' . (int) get_current_blog_id() . '_' . crc32( $table . '.attachment_url' );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
				$table,
				'attachment_url'
			) );
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		if ( ! $present ) {
			// [2026-06-22 Johnny Chu] EMAIL-ATTACH-FIX — self-heal: ADD COLUMN idempotent
			// Column existed in CREATE TABLE schema (installer) but may be missing on
			// old installs created before the column was added. Safe to run.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `attachment_url` TEXT NULL" );
			wp_cache_delete( $ck, 'bizcity_tbl' );
			$present = 1;
		}
		$has_column = (bool) $present;
		return $has_column;
	}

	/**
	 * Self-heal: ensure cf7_notice column exists on older installs.
	 * [2026-06-24 Johnny Chu] HOTFIX
	 */
	private static function has_cf7_notice_column(): bool {
		static $has_col = null;
		if ( null !== $has_col ) { return (bool) $has_col; }
		global $wpdb;
		$table = self::table();
		$ck    = 'bz_col_' . (int) get_current_blog_id() . '_' . crc32( $table . '.cf7_notice' );
		$present = wp_cache_get( $ck, 'bizcity_tbl' );
		if ( false === $present ) {
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s AND COLUMN_NAME=%s LIMIT 1',
				$table, 'cf7_notice'
			) );
			wp_cache_set( $ck, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}
		if ( ! $present ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `cf7_notice` TEXT NULL COMMENT 'Plain-text thank-you message for CF7 auto-reply'" );
			wp_cache_delete( $ck, 'bizcity_tbl' );
			$present = 1;
		}
		$has_col = (bool) $present;
		return $has_col;
	}

	public static function record_fire( int $id, bool $ok, string $err = '' ): void {
		global $wpdb;
		$tbl = self::table();
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$tbl} SET last_fired_at=%s, last_fire_status=%s, last_fire_error=%s, fire_count=fire_count+1, updated_at=%s WHERE id=%d",
			current_time( 'mysql' ),
			$ok ? 'ok' : 'fail',
			$err,
			current_time( 'mysql' ),
			$id
		) );
	}
}
