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
	const DB_VERSION        = '1.2.0'; // [2026-07-28 Johnny Chu] R-CH-IDMEM — identity-scoped unified memory owner.
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
		if ( $checked && get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		// [2026-08-21 Johnny Chu] DIAGNOSTICS-SCHEMA-SELF-HEAL — only persist the schema stamp after physical verification succeeds.
		if ( $this->install() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
			$checked = true;
		}
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
			identity_uuid CHAR(36) NOT NULL DEFAULT '',
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
			UNIQUE KEY uq_memory_owner_key (blog_id, user_id, session_id, identity_uuid, memory_class, memory_key),
			KEY idx_user_class (user_id, memory_class, status),
			KEY idx_identity_class (blog_id, identity_uuid, memory_class, status),
			KEY idx_class_score (memory_class, score, updated_at),
			KEY idx_conversation (conversation_id, status),
			KEY idx_notebook (notebook_id, memory_class),
			KEY idx_last_seen (last_seen),
			KEY idx_legacy_class (memory_class, legacy_id)
		) {$charset};";

		// [2026-08-25 Johnny Chu] PHASE-1.24 — Diagnostics must reconcile both fresh and partial unified tables through the additive schema owner; dbDelta on a partially-created table emitted invalid ALTER ADD statements.
		$existing = function_exists( 'bizcity_tbl_exists' )
			? (bool) bizcity_tbl_exists( $table )
			: (bool) $wpdb->get_var( $wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
				$table
			) );
		$diagnostics_context = defined( 'BIZCITY_DIAGNOSTICS_CLI' ) && BIZCITY_DIAGNOSTICS_CLI;
		// [2026-08-25 Johnny Chu] PHASE-1.24 — load the additive schema owner lazily so probe execution does not depend on diagnostics bootstrap order.
		if ( $diagnostics_context && ! class_exists( 'BizCity_Diagnostics_Auto_Create' ) ) {
			$diagnostics_dir = defined( 'BIZCITY_DIAGNOSTICS_DIR' )
				? trailingslashit( BIZCITY_DIAGNOSTICS_DIR )
				: dirname( __DIR__, 2 ) . '/diagnostics/';
			if ( ! class_exists( 'BizCity_Diagnostics_Changelog_Loader' ) && is_readable( $diagnostics_dir . 'includes/class-diagnostics-changelog-loader.php' ) ) {
				require_once $diagnostics_dir . 'includes/class-diagnostics-changelog-loader.php';
			}
			if ( is_readable( $diagnostics_dir . 'includes/class-diagnostics-auto-create.php' ) ) {
				require_once $diagnostics_dir . 'includes/class-diagnostics-auto-create.php';
			}
			unset( $diagnostics_dir );
		}
		if ( class_exists( 'BizCity_Diagnostics_Auto_Create' ) && ( $existing || $diagnostics_context ) ) {
			$reconcile = BizCity_Diagnostics_Auto_Create::run( self::TABLE_SUFFIX );
			if ( empty( $reconcile['ok'] ) ) {
				error_log( '[BizCity_Memory_Unified_Installer] additive schema reconcile failed for ' . $table . ' action=' . (string) ( $reconcile['action'] ?? 'unknown' ) );
				return false;
			}
		} elseif ( $diagnostics_context ) {
			// [2026-08-25 Johnny Chu] PHASE-1.24 — never fall back to dbDelta in the headless Diagnostics context when the additive schema owner is unavailable.
			error_log( '[BizCity_Memory_Unified_Installer] Diagnostics schema owner unavailable for ' . $table . ' path=' . ( defined( 'BIZCITY_DIAGNOSTICS_DIR' ) ? 'configured' : 'derived' ) );
			return false;
		} else {
			dbDelta( $sql );
		}
		// [2026-08-21 Johnny Chu] R-METADATA-CACHE — discard any pre-DDL false result before verifying the new unified table.
		if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
			bizcity_tbl_invalidate( $table );
		}
		$exists = bizcity_tbl_exists( $table ); // [2026-06-21 Johnny Chu] R-SHOW-TABLES
		if ( ! $exists ) {
			error_log( '[BizCity_Memory_Unified_Installer] schema install failed for ' . $table . ( $diagnostics_context ? ' via diagnostics auto-create' : ' via dbDelta' ) );
			return false;
		}
		error_log( '[BizCity_Memory_Unified_Installer] Table ' . $table . ' installed @ v' . self::DB_VERSION );
		unset( $diagnostics_context );
		return true;
	}
}

// [2026-07-31 Johnny Chu] R-CR — register unified memory schema before the feature-flagged installer can call dbDelta().
// [2026-07-31 Johnny Chu] HOTFIX — `self::` is invalid at file scope (outside a class body) and caused a
// production fatal ("Cannot access self:: when no class scope is active") on every request. Use the
// fully-qualified class name instead, matching the pattern used by every other Schema Registry callsite.
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		BizCity_Memory_Unified_Installer::TABLE_SUFFIX,
		'core.memory.unified',
		BizCity_Memory_Unified_Installer::DB_VERSION,
		BizCity_Memory_Unified_Installer::DB_VERSION_OPTION,
		array( BizCity_Memory_Unified_Installer::instance(), 'install' )
	);
}
