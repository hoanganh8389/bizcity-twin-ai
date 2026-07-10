<?php
/**
 * BizCity Personal — DB Installer
 *
 * Creates / upgrades the 3 tables for the Personal Assistant module:
 *   - bizcity_personal_finance_categories
 *   - bizcity_personal_finance_entries
 *   - bizcity_personal_journal
 *
 * Schema changelog: core/diagnostics/changelog/modules.personal.json
 *
 * R-DCL compliant: BizCity_Schema_Registry::register() called at file scope
 * AFTER class definition (ngoài mọi hook).
 *
 * R-CACHE: not applicable (write-through installer; read-side cache handled
 * in class-personal-finance.php + class-personal-journal.php).
 *
 * PHP 7.4 compatible — no union types, no nullsafe, no match, no str_contains.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Plugins\BizCityPersonal
 * @since 2026-06-24 (PHASE-HOME W6/W4)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_Personal_Installer' ) ) { return; }

class BizCity_Personal_Installer {

	// [2026-06-24 Johnny Chu] PHASE-HOME W6 — sync with modules.personal.json current_version
	// [2026-06-24 Johnny Chu] PHASE-HOME-ARCH — bumped to 1.2.0: renamed tables bizcity_home_* → bizcity_personal_*
	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — bumped to 1.3.0: added bizcity_personal_notebooks + bizcity_personal_notebook_pages
	// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — bumped to 1.4.0: added bizcity_personal_notebook_chunks (KG service chunks)
	const SCHEMA_VERSION = '1.4.0';
	const VERSION_OPTION = 'bizcity_personal_db_version';

	/**
	 * Idempotent installer — called by BizCity_Schema_Registry on init.
	 * Safe to call on every request (guarded by version check).
	 */
	public static function install() {
		// [2026-06-24 Johnny Chu] PHASE-HOME — version guard (idempotent)
		if ( get_option( self::VERSION_OPTION, '' ) === self::SCHEMA_VERSION ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// ── bizcity_personal_finance_categories ──────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — finance categories table
		dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bizcity_personal_finance_categories` (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			name         VARCHAR(80) NOT NULL DEFAULT '',
			kind         ENUM('income','expense') NOT NULL DEFAULT 'expense',
			icon         VARCHAR(16) NOT NULL DEFAULT '💰',
			color        VARCHAR(16) NOT NULL DEFAULT '#6366f1',
			sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_kind (user_id, kind)
		) $charset_collate;" );

		// ── bizcity_personal_finance_entries ─────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME W6 — finance entries table
		dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bizcity_personal_finance_entries` (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			category_id  BIGINT UNSIGNED NULL DEFAULT NULL,
			kind         ENUM('income','expense') NOT NULL DEFAULT 'expense',
			amount_vnd   BIGINT NOT NULL DEFAULT 0,
			title        VARCHAR(191) NOT NULL DEFAULT '',
			note         TEXT NULL,
			occurred_at  DATE NOT NULL,
			recurring    TINYINT(1) NOT NULL DEFAULT 0,
			source       VARCHAR(32) NOT NULL DEFAULT 'user',
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			journal_id   BIGINT UNSIGNED NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_user_date (user_id, occurred_at),
			KEY idx_user_kind (user_id, kind),
			KEY idx_category  (category_id)
		) $charset_collate;" );

		// ── bizcity_personal_journal ──────────────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME W4 — journal table
		dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bizcity_personal_journal` (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT UNSIGNED NOT NULL,
			entry_date      DATE NOT NULL,
			content         LONGTEXT NOT NULL,
			mood            VARCHAR(8) NULL DEFAULT NULL,
			kg_passage_id   BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY     (id),
			UNIQUE KEY uniq_user_date (user_id, entry_date)
		) $charset_collate;" );

		// [2026-06-24 Johnny Chu] PHASE-HOME — seed default finance categories for new installs
		self::seed_default_categories();

		// ── bizcity_personal_notebooks ────────────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — notebook collections table
		dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bizcity_personal_notebooks` (
			id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id      BIGINT UNSIGNED NOT NULL,
			title        VARCHAR(191) NOT NULL DEFAULT 'Ghi chú',
			description  TEXT NULL,
			icon         VARCHAR(16) NOT NULL DEFAULT '📓',
			color        VARCHAR(16) NOT NULL DEFAULT '#6366f1',
			is_default   TINYINT(1) NOT NULL DEFAULT 0,
			page_count   INT NOT NULL DEFAULT 0,
			sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user (user_id)
		) $charset_collate;" );

		// ── bizcity_personal_notebook_pages ───────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS — individual note pages (DB = index/meta, file = content)
		dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bizcity_personal_notebook_pages` (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id   BIGINT UNSIGNED NOT NULL,
			user_id       BIGINT UNSIGNED NOT NULL,
			title         VARCHAR(191) NOT NULL DEFAULT 'Trang mới',
			content       LONGTEXT NULL,
			excerpt       VARCHAR(255) NULL DEFAULT NULL,
			file_path     VARCHAR(500) NULL DEFAULT NULL,
			tags          TEXT NULL,
			mood          VARCHAR(32) NULL DEFAULT NULL,
			word_count    INT NOT NULL DEFAULT 0,
			kg_source_id  BIGINT UNSIGNED NULL DEFAULT NULL,
			created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY   (id),
			KEY idx_notebook (notebook_id),
			KEY idx_user     (user_id),
			KEY idx_updated  (updated_at)
		) $charset_collate;" );

		// ── bizcity_personal_notebook_chunks ─────────────────────────────────
		// [2026-06-24 Johnny Chu] PHASE-HOME-NOTEBOOKS PATH-B — KG service chunks (Smart Sources Standard schema)
		// Owns the chunk+embedding records that feed bizcity_kg_passages for scope_type='personal_notebook'.
		// parent_fk: notebook_id (maps to bizcity_personal_notebooks.id).
		dbDelta( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}bizcity_personal_notebook_chunks` (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_id        BIGINT UNSIGNED NOT NULL,
			notebook_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id          BIGINT UNSIGNED NOT NULL DEFAULT 0,
			chunk_index      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			content          LONGTEXT NOT NULL,
			token_count      INT NOT NULL DEFAULT 0,
			embedding        LONGBLOB NULL DEFAULT NULL,
			embedding_model  VARCHAR(64) NOT NULL DEFAULT '',
			heading_path     TEXT NULL DEFAULT NULL,
			content_hash     VARCHAR(64) NOT NULL DEFAULT '',
			created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY      (id),
			KEY idx_source   (source_id),
			KEY idx_notebook (notebook_id),
			KEY idx_hash     (content_hash)
		) $charset_collate;" );

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, true );
	}

	/**
	 * Seed default categories for every user who first loads the personal assistant.
	 * Called per-user on-demand (not at install time).
	 *
	 * @param int $user_id
	 */
	public static function maybe_seed_categories_for_user( int $user_id ) {
		// [2026-06-24 Johnny Chu] PHASE-HOME — only seed once per user
		if ( $user_id <= 0 ) {
			return;
		}
		$cache_key = 'pn_cat_seeded_' . $user_id;
		if ( wp_cache_get( $cache_key, 'bizcity_personal' ) ) {
			return;
		}

		global $wpdb;
		$tbl = $wpdb->prefix . 'bizcity_personal_finance_categories';
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM `' . $tbl . '` WHERE user_id = %d',
			$user_id
		) );
		if ( $count > 0 ) {
			wp_cache_set( $cache_key, 1, 'bizcity_personal', 3600 );
			return;
		}

		// [2026-06-24 Johnny Chu] PHASE-HOME — default categories (Vietnamese)
		$defaults = array(
			array( 'kind' => 'expense', 'name' => 'Ăn uống',     'icon' => '🍜', 'color' => '#f97316', 'sort_order' => 1 ),
			array( 'kind' => 'expense', 'name' => 'Đi lại',       'icon' => '🚗', 'color' => '#3b82f6', 'sort_order' => 2 ),
			array( 'kind' => 'expense', 'name' => 'Nhà ở',        'icon' => '🏠', 'color' => '#8b5cf6', 'sort_order' => 3 ),
			array( 'kind' => 'expense', 'name' => 'Mua sắm',      'icon' => '🛍️', 'color' => '#ec4899', 'sort_order' => 4 ),
			array( 'kind' => 'expense', 'name' => 'Giải trí',     'icon' => '🎮', 'color' => '#14b8a6', 'sort_order' => 5 ),
			array( 'kind' => 'expense', 'name' => 'Sức khoẻ',     'icon' => '💊', 'color' => '#ef4444', 'sort_order' => 6 ),
			array( 'kind' => 'expense', 'name' => 'Giáo dục',     'icon' => '📚', 'color' => '#06b6d4', 'sort_order' => 7 ),
			array( 'kind' => 'expense', 'name' => 'Tiết kiệm',    'icon' => '🐷', 'color' => '#10b981', 'sort_order' => 8 ),
			array( 'kind' => 'expense', 'name' => 'Khác',         'icon' => '📦', 'color' => '#6b7280', 'sort_order' => 9 ),
			array( 'kind' => 'income',  'name' => 'Lương',        'icon' => '💼', 'color' => '#10b981', 'sort_order' => 1 ),
			array( 'kind' => 'income',  'name' => 'Kinh doanh',   'icon' => '💰', 'color' => '#f59e0b', 'sort_order' => 2 ),
			array( 'kind' => 'income',  'name' => 'Đầu tư',       'icon' => '📈', 'color' => '#6366f1', 'sort_order' => 3 ),
			array( 'kind' => 'income',  'name' => 'Khác',         'icon' => '🎁', 'color' => '#6b7280', 'sort_order' => 4 ),
		);

		foreach ( $defaults as $cat ) {
			$wpdb->insert(
				$tbl,
				array(
					'user_id'    => $user_id,
					'name'       => $cat['name'],
					'kind'       => $cat['kind'],
					'icon'       => $cat['icon'],
					'color'      => $cat['color'],
					'sort_order' => $cat['sort_order'],
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d' )
			);
		}

		wp_cache_set( $cache_key, 1, 'bizcity_personal', 3600 );
	}

	/**
	 * Placeholder — called only on table creation, not per-user.
	 */
	private static function seed_default_categories() {
		// [2026-06-24 Johnny Chu] PHASE-HOME — per-user seeding happens lazily via REST
	}
}

// ── Schema Registry (R-CR.2 + R-DCL) ─────────────────────────────────────────
// [2026-06-24 Johnny Chu] PHASE-HOME — register 3 tables at file-load time (outside hooks)
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_personal_finance_categories',
		'modules.personal',
		BizCity_Personal_Installer::SCHEMA_VERSION,
		BizCity_Personal_Installer::VERSION_OPTION,
		array( 'BizCity_Personal_Installer', 'install' )
	);
	BizCity_Schema_Registry::register(
		'bizcity_personal_finance_entries',
		'modules.personal',
		BizCity_Personal_Installer::SCHEMA_VERSION,
		BizCity_Personal_Installer::VERSION_OPTION,
		array( 'BizCity_Personal_Installer', 'install' )
	);
	BizCity_Schema_Registry::register(
		'bizcity_personal_journal',
		'modules.personal',
		BizCity_Personal_Installer::SCHEMA_VERSION,
		BizCity_Personal_Installer::VERSION_OPTION,
		array( 'BizCity_Personal_Installer', 'install' )
	);
}
