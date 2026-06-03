<?php
/**
 * TwinBrain Sheets — Schema Installer (Wave 2.8e TBR.TOOL-S1).
 *
 * Tạo 2 bảng artifact cho `sheet_enrich` tool:
 *   - {prefix}bizcity_sheets        (sheet metadata + aggregate cost)
 *   - {prefix}bizcity_sheet_cells   (per-cell value + sources_json + audit)
 *
 * R-DCL canon: schema declared in
 *   core/diagnostics/changelog/twinbrain.sheets.json
 * Mọi column / index thay đổi PHẢI bump version trong JSON đó trước khi sửa
 * DDL ở đây (validator exit 0 bắt buộc). KHÔNG DROP / MODIFY column trong
 * dbDelta — chỉ ADD. DROP đi qua Site Provisioner manual.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\TwinBrain\Sheets
 * @since      Wave 2.8e (2026-05-24)
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinBrain_Sheets_Installer {

	const TABLE_SHEETS         = 'bizcity_sheets';
	const TABLE_CELLS          = 'bizcity_sheet_cells';
	const DB_VERSION           = '1.0.0';
	const DB_VERSION_OPTION    = 'bizcity_twinbrain_sheets_db_ver';

	/** @var BizCity_TwinBrain_Sheets_Installer|null */
	private static $instance = null;

	public static function instance(): self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'plugins_loaded', [ $this, 'maybe_install' ], 30 );
	}

	public static function sheets_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SHEETS;
	}

	public static function cells_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_CELLS;
	}

	public function maybe_install(): void {
		static $checked = false;
		if ( $checked ) return;
		$checked = true;

		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		if ( $this->install() ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	public function install(): bool {
		global $wpdb;
		$sheets = self::sheets_table();
		$cells  = self::cells_table();

		$charset = function_exists( 'bizcity_get_charset_collate' )
			? bizcity_get_charset_collate()
			: $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql_sheets = "CREATE TABLE {$sheets} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id INT UNSIGNED NOT NULL DEFAULT 1,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(255) NOT NULL DEFAULT '',
			headers_json TEXT NULL,
			research_mode ENUM('fast','deep') NOT NULL DEFAULT 'fast',
			status ENUM('draft','enriching','complete','error') NOT NULL DEFAULT 'draft',
			row_count INT UNSIGNED NOT NULL DEFAULT 0,
			col_count INT UNSIGNED NOT NULL DEFAULT 0,
			cell_count INT UNSIGNED NOT NULL DEFAULT 0,
			source_count INT UNSIGNED NOT NULL DEFAULT 0,
			tavily_cost_cents INT UNSIGNED NOT NULL DEFAULT 0,
			total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			ingest_to_kg TINYINT(1) NOT NULL DEFAULT 0,
			visibility ENUM('private','shared') NOT NULL DEFAULT 'private',
			trace_id VARCHAR(64) NOT NULL DEFAULT '',
			last_error TEXT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user_updated (blog_id, user_id, updated_at),
			KEY idx_status (status),
			KEY idx_trace (trace_id)
		) {$charset};";

		$sql_cells = "CREATE TABLE {$cells} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			sheet_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			blog_id INT UNSIGNED NOT NULL DEFAULT 1,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			row_idx INT UNSIGNED NOT NULL DEFAULT 0,
			col_idx INT UNSIGNED NOT NULL DEFAULT 0,
			column_name VARCHAR(191) NOT NULL DEFAULT '',
			value LONGTEXT NULL,
			is_context TINYINT(1) NOT NULL DEFAULT 0,
			sources_json TEXT NULL,
			enrichment_trace TEXT NULL,
			query_used TEXT NULL,
			tavily_cost_cents INT UNSIGNED NOT NULL DEFAULT 0,
			llm_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
			status ENUM('empty','enriched','error') NOT NULL DEFAULT 'empty',
			last_error TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_cell (sheet_id, row_idx, col_idx),
			KEY idx_sheet_status (sheet_id, status),
			KEY idx_owner (blog_id, user_id)
		) {$charset};";

		dbDelta( $sql_sheets );
		dbDelta( $sql_cells );

		$ok_sheets = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sheets ) );
		$ok_cells  = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cells ) );

		if ( ! $ok_sheets || ! $ok_cells ) {
			error_log( '[BizCity_TwinBrain_Sheets_Installer] dbDelta missing tables — sheets=' . (int) $ok_sheets . ' cells=' . (int) $ok_cells );
			return false;
		}
		error_log( '[BizCity_TwinBrain_Sheets_Installer] Tables installed @ v' . self::DB_VERSION );
		return true;
	}
}
