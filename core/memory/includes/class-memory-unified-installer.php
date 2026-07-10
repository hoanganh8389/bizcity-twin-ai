<?php
/**
 * BizCity Memory — Unified Table Installer (Wave 2.8d TBR.MEM-D4).
 *
 * Tạo bảng `{prefix}bizcity_memory` (unified) thay thế dần 5 bảng:
 *   bizcity_memory_users     (class='user')
 *   bizcity_memory_episodic  (class='episodic')
 *   bizcity_memory_rolling   (class='rolling')
 *   bizcity_memory_session   (class='session')
 *   bizcity_memory_notes     (class='note')
 *
 * Behind feature flag `bizcity_memory_unified_enabled` (default FALSE).
 *
 * Roadmap:
 *   - D4 (this file): install table + flag, KHÔNG dual-write, KHÔNG read.
 *   - D5: dual-write (BizCity_User_Memory + Episodic + Rolling ghi đồng thời).
 *   - D6: cutover read path (Memory_Recall::collect đọc bảng mới).
 *   - D7: drop 5 bảng legacy + bump R-DCL v2.0.0.
 *
 * Spec đầy đủ: core/memory/PHASE-MEMORY-CONSOLIDATION.md
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @since      Wave 2.8d (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Memory_Unified_Installer {

	const TABLE_SUFFIX      = 'bizcity_memory';
	const DB_VERSION        = '1.1.0';
	const DB_VERSION_OPTION = 'bizcity_memory_unified_db_ver';
	const FLAG_FILTER       = 'bizcity_memory_unified_enabled';
	const FLAG_OPTION       = 'bizcity_memory_unified_enabled'; // admin toggle

	/** @var BizCity_Memory_Unified_Installer|null */
	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Run on plugins_loaded after R-DCL auto-create stage (priority 30 ~= late).
		add_action( 'plugins_loaded', [ $this, 'maybe_install' ], 30 );
	}

	/**
	 * Fully-qualified table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Feature flag: is the unified table enabled?
	 *
	 * Resolution order (later wins):
	 *   1. Default = FALSE (legacy-only mode).
	 *   2. Option `bizcity_memory_unified_enabled` (admin toggle, Wave 2.8d D6.7).
	 *   3. Filter `bizcity_memory_unified_enabled` (code overrides for probes / tests).
	 */
	public static function is_enabled(): bool {
		$opt = get_option( self::FLAG_OPTION, null );
		$default = ( $opt === null ) ? false : ( $opt === '1' || $opt === 1 || $opt === true || $opt === 'yes' );
		return (bool) apply_filters( self::FLAG_FILTER, $default );
	}

	/**
	 * Install table on plugins_loaded, gated by flag + version option.
	 * Idempotent — safe to call multiple times.
	 */
	public function maybe_install(): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		static $checked = false;
		if ( $checked ) {
			return;
		}
		$checked = true;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		$this->install();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Run dbDelta to create the unified table.
	 * Schema mirrors `core/memory/PHASE-MEMORY-CONSOLIDATION.md §2.1`.
	 */
	public function install(): bool {
		global $wpdb;
		$table = self::table();

		$charset = function_exists( 'bizcity_get_charset_collate' )
			? bizcity_get_charset_collate()
			: $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id INT UNSIGNED NOT NULL DEFAULT 1,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id VARCHAR(191) NOT NULL DEFAULT '',
			conversation_id VARCHAR(64) NOT NULL DEFAULT '',
			notebook_id BIGINT UNSIGNED NOT NULL DEFAULT 0,

			memory_class ENUM('user','episodic','rolling','session','note') NOT NULL DEFAULT 'user',

			legacy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,

			memory_tier ENUM('explicit','extracted','llm','manual') NOT NULL DEFAULT 'explicit',
			memory_type VARCHAR(64) NOT NULL DEFAULT 'fact',
			memory_key VARCHAR(191) NOT NULL DEFAULT '',
			memory_text LONGTEXT NULL,

			event_type VARCHAR(64) NULL,
			importance TINYINT UNSIGNED NOT NULL DEFAULT 0,

			goal VARCHAR(191) NULL,
			goal_label VARCHAR(191) NULL,
			window_summary TEXT NULL,
			window_turn_count INT UNSIGNED NOT NULL DEFAULT 0,
			user_goal_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			bot_satisfaction_score TINYINT UNSIGNED NOT NULL DEFAULT 0,
			status ENUM('active','completed','cancelled','expired') NOT NULL DEFAULT 'active',

			score TINYINT UNSIGNED NOT NULL DEFAULT 50,
			times_seen INT UNSIGNED NOT NULL DEFAULT 1,
			source_log_ids TEXT NULL,
			metadata LONGTEXT NULL,

			last_seen DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

			PRIMARY KEY  (id),
			UNIQUE KEY uq_memory_owner_key (blog_id, user_id, session_id, memory_class, memory_key),
			KEY idx_user_class (user_id, memory_class, status),
			KEY idx_class_score (memory_class, score, updated_at),
			KEY idx_conversation (conversation_id, status),
			KEY idx_notebook (notebook_id, memory_class),
			KEY idx_last_seen (last_seen),
			KEY idx_legacy_class (memory_class, legacy_id)
		) {$charset};";

		dbDelta( $sql );

		$exists = bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) {
			error_log( '[BizCity_Memory_Unified_Installer] dbDelta FAILED for ' . $table );
			return false;
		}
		error_log( '[BizCity_Memory_Unified_Installer] Table ' . $table . ' installed @ v' . self::DB_VERSION );
		return true;
	}
}
