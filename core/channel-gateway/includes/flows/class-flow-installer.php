<?php
/**
 * BizCity Channel Gateway — Flows · Installer
 *
 * Manages the `wp_bizcity_crm_flows` table lifecycle.
 *
 * NOTE 2026-05-26: renamed from `wp_bizcity_cg_flows` to align with the
 * `bizcity_crm_*` CRM prefix family (R-DCL v1.1.0). Backward-compat:
 *  - if legacy `wp_bizcity_cg_flows` table exists & new table is empty → auto-copy.
 *  - option `bizcity_cg_flows_*` read once then mirrored to `bizcity_crm_flows_*`.
 *
 * Schema source of truth (R-DCL):
 *   core/diagnostics/changelog/modules.flows.json
 *
 * Auto-create is delegated to `BizCity_Diagnostics_Auto_Create` probe (ADD-only).
 * Here we only expose table name helpers + a one-shot migration importer
 * from the legacy `wp_bizgpt_custom_flows` table (read-only copy).
 *
 * @package   BizCity_Twin_AI
 * @subpackage Channel_Gateway\Flows
 * @since      PHASE-N (2026-05-25)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_CG_Flow_Installer {

	const TABLE             = 'bizcity_crm_flows';
	const LEGACY_TBL        = 'bizgpt_custom_flows';
	const INTERIM_TBL       = 'bizcity_cg_flows';                       // 2026-05-25 → 2026-05-26 interim name.
	const OPT_MIGRATED      = 'bizcity_crm_flows_migrated_from_bizgpt';
	const OPT_VERSION       = 'bizcity_crm_flows_db_version';
	const OPT_MIGRATED_OLD  = 'bizcity_cg_flows_migrated_from_bizgpt';   // back-compat read.
	const OPT_VERSION_OLD   = 'bizcity_cg_flows_db_version';             // back-compat read.
	const CACHE_GROUP       = 'bizcity_crm_flows';

	/**
	 * Schema/migration version. BẮT BUỘC khớp `current_version` trong
	 * `core/diagnostics/changelog/modules.flows.json` (R-DCL).
	 *
	 * Bump version + thêm case mới trong run_versioned_migrations() khi
	 * cần force migration chạy lại trên site đã update.
	 */
	const DB_VERSION = '1.1.0';

	/** @return string Fully-prefixed table name (`wp_bizcity_crm_flows`). */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/** @return string Interim (pre-rename) table name (`wp_bizcity_cg_flows`) — checked once during migration. */
	public static function interim_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::INTERIM_TBL;
	}

	/**
	 * Read stored DB version with backward-compat fallback (old option key).
	 * Returns '0.0.0' if never installed.
	 */
	public static function read_db_version(): string {
		$v = (string) get_option( self::OPT_VERSION, '' );
		if ( $v !== '' ) { return $v; }
		$old = (string) get_option( self::OPT_VERSION_OLD, '' );
		if ( $old !== '' ) {
			update_option( self::OPT_VERSION, $old, false );
			return $old;
		}
		return '0.0.0';
	}

	/**
	 * Read OPT_MIGRATED with backward-compat fallback.
	 * If only the legacy option name exists, mirror it into the new option name once.
	 */
	public static function read_migrated_flag(): int {
		$new = (int) get_option( self::OPT_MIGRATED, 0 );
		if ( $new ) { return $new; }
		$old = (int) get_option( self::OPT_MIGRATED_OLD, 0 );
		if ( $old ) {
			update_option( self::OPT_MIGRATED, $old, false );
			return $old;
		}
		return 0;
	}

	/** @return string Legacy fully-prefixed table name (`wp_bizgpt_custom_flows`). */
	public static function legacy_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::LEGACY_TBL;
	}

	public static function table_exists( string $tbl ): bool {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) === $tbl;
	}

	/**
	 * Ensure `wp_bizcity_crm_flows` exists. Uses dbDelta (ADD-only, R-DCL compliant).
	 * Safe to call on every admin page load — dbDelta is idempotent.
	 *
	 * @return bool True if table existed or was just created.
	 */
	public static function ensure_table(): bool {
		global $wpdb;
		$tbl     = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$tbl} (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message           VARCHAR(255) NOT NULL DEFAULT '',
  message_khong_dau VARCHAR(255) NOT NULL DEFAULT '',
  shortcode         TEXT NULL,
  action_type       VARCHAR(32) NOT NULL DEFAULT 'run_shortcode',
  action_config     LONGTEXT NULL,
  prompt            LONGTEXT NULL,
  reply_mode        VARCHAR(16) NOT NULL DEFAULT 'direct',
  output_json       LONGTEXT NULL,
  reminder_delay    INT NOT NULL DEFAULT 0,
  reminder_unit     VARCHAR(10) NOT NULL DEFAULT 'minutes',
  reminder_text     TEXT NULL,
  delay_only        TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_action_type (action_type),
  KEY idx_reminder (reminder_delay),
  KEY idx_message_kd (message_khong_dau(64))
) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return self::table_exists( $tbl );
	}

	/**
	 * Version-gated migration entrypoint. Idempotent. Strategy:
	 *
	 *  1. If stored DB version === DB_VERSION → skip (no-op fast path).
	 *  2. Ensure dst table `wp_bizcity_crm_flows` exists.
	 *  3. **RENAME path** — if interim `wp_bizcity_cg_flows` exists AND dst is empty:
	 *       DROP empty dst → RENAME interim TO dst. Atomic, no duplicate
	 *       (R-DCL-NAME: single canonical name per logical table).
	 *  4. **Cleanup path** — if interim exists but dst already populated:
	 *       DROP interim (anti-duplicate).
	 *  5. **COPY path** — else if legacy `wp_bizgpt_custom_flows` exists and dst empty:
	 *       INSERT SELECT preserving id + reply_mode default 'direct'. The legacy
	 *       table belongs to a different plugin so we copy rather than rename.
	 *  6. Bump `OPT_VERSION` to `DB_VERSION` + set `OPT_MIGRATED = 1`.
	 *
	 * @return array{ok:bool, copied:int, reason?:string, from?:string, to?:string}
	 */
	public static function maybe_migrate_from_legacy(): array {
		global $wpdb;

		// Fast path: already at current schema version.
		if ( self::read_db_version() === self::DB_VERSION ) {
			return array( 'ok' => true, 'copied' => 0, 'reason' => 'version_current', 'from' => self::DB_VERSION, 'to' => self::DB_VERSION );
		}

		$from_version = self::read_db_version();
		$dst          = self::table();
		$interim      = self::interim_table();
		$src          = self::legacy_table();

		if ( ! self::table_exists( $dst ) ) {
			self::ensure_table();
		}
		if ( ! self::table_exists( $dst ) ) {
			return array( 'ok' => false, 'copied' => 0, 'reason' => 'dst_missing', 'from' => $from_version );
		}

		$dst_count     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$dst}" );
		$interim_exist = self::table_exists( $interim );
		$interim_count = $interim_exist ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$interim}" ) : 0;

		$result = array( 'ok' => true, 'copied' => 0, 'reason' => 'noop', 'from' => $from_version, 'to' => self::DB_VERSION );

		// === Path A: dst empty + interim has data → atomic RENAME ===
		if ( $dst_count === 0 && $interim_exist && $interim_count > 0 ) {
			$wpdb->query( "DROP TABLE {$dst}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ok = $wpdb->query( "RENAME TABLE {$interim} TO {$dst}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $ok ) {
				// Fallback: re-create dst and COPY so we don't lose data.
				self::ensure_table();
				$copied_i = $wpdb->query( "INSERT INTO {$dst} SELECT * FROM {$interim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( false === $copied_i ) {
					return array( 'ok' => false, 'copied' => 0, 'reason' => 'rename_and_copy_failed: ' . $wpdb->last_error, 'from' => $from_version );
				}
				$wpdb->query( "DROP TABLE {$interim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result['copied'] = (int) $copied_i;
				$result['reason'] = 'copied_then_dropped_interim';
			} else {
				$result['copied'] = $interim_count;
				$result['reason'] = 'renamed_interim_to_dst';
			}
		}
		// === Path B: interim lingers (empty OR dst already populated) → DROP cleanup ===
		elseif ( $interim_exist ) {
			$wpdb->query( "DROP TABLE {$interim}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$result['reason'] = ( $dst_count > 0 ) ? 'dropped_interim_dst_populated' : 'dropped_interim_empty';
		}
		// === Path C: no interim, dst empty, legacy bizgpt table exists → COPY ===
		elseif ( $dst_count === 0 && self::table_exists( $src ) ) {
			$sql = "INSERT INTO {$dst}
			          (id, message, message_khong_dau, shortcode, action_type, action_config,
			           prompt, reply_mode, reminder_delay, reminder_unit, reminder_text, delay_only, updated_at)
			        SELECT id, message, message_khong_dau, shortcode, action_type, action_config,
			               prompt, 'direct' AS reply_mode,
			               COALESCE(reminder_delay, 0), COALESCE(reminder_unit, 'minutes'),
			               reminder_text, COALESCE(delay_only, 0), updated_at
			          FROM {$src}";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$copied = $wpdb->query( $sql );
			if ( false === $copied ) {
				return array( 'ok' => false, 'copied' => 0, 'reason' => 'sql_error: ' . $wpdb->last_error, 'from' => $from_version );
			}
			$result['copied'] = (int) $copied;
			$result['reason'] = 'copied_from_bizgpt_custom_flows';
		}

		// Stamp version + back-compat flag.
		update_option( self::OPT_VERSION, self::DB_VERSION, false );
		update_option( self::OPT_MIGRATED, 1, false );

		return $result;
	}
}
