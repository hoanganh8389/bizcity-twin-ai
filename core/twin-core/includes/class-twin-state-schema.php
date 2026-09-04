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
 * BizCity Twin State Schema — DDL for the three active Twin state tables.
 *
 * Phase 2 Priority 3 + 4 + 5: Create the Twin state backbone.
 *
 * Tables:
 *   SUPPORT: twin_prompt_specs, twin_milestones, twin_context_logs
 *
 * Uses WordPress dbDelta() for safe migration.
 *
 * @package  BizCity_Twin_Core
 * @version  2.0.0
 * @since    2026-03-27
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Twin_State_Schema {

	const DB_VERSION        = '2.2.1'; // [2026-07-31 Johnny Chu] HOTFIX — rerun state DDL after dbDelta primary-key parser repair.
	const DB_VERSION_OPTION = 'bizcity_twin_state_db_ver';
	const RETENTION_HOOK    = 'bizcity_twin_state_retention';
	const RETENTION_BATCH   = 500;
	const PROMPT_RETENTION_DAYS = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep prompt state for one week.
	const MILESTONE_RETENTION_DAYS = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep milestones for one week.
	const CONTEXT_RETENTION_DAYS = 7; // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep context decisions for one week.

	/* ================================================================
	 * TABLE NAMES
	 * ================================================================ */

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_' . $name;
	}

	public static function prompt_specs_table(): string   { return self::t( 'twin_prompt_specs' ); }
	public static function milestones_table(): string     { return self::t( 'twin_milestones' ); }
	public static function context_logs_table(): string   { return self::t( 'twin_context_logs' ); }

	public static function register_retention_cron(): void {
		if ( ! class_exists( 'BizCity_Cron_Manager' ) ) {
			return;
		}
		BizCity_Cron_Manager::instance()->register( array(
			'id'          => 'core.twin_state.retention',
			'hook'        => self::RETENTION_HOOK,
			'interval'    => 'daily',
			'owner'       => 'core/twin-core',
			'description' => 'Bounded retention sweep for Twin prompt, milestone and context traces.',
			'retention'   => 7, // [2026-08-01 Johnny Chu] PHASE-1.28-RETENTION-7D — keep cron evidence for one week.
		) );
	}

	public static function gc_retention(): void {
		global $wpdb;
		if ( ! $wpdb ) {
			return;
		}

		$targets = array(
			array( self::prompt_specs_table(), self::PROMPT_RETENTION_DAYS, 'prompt_spec_id' ),
			array( self::milestones_table(), self::MILESTONE_RETENTION_DAYS, 'milestone_id' ),
			array( self::context_logs_table(), self::CONTEXT_RETENTION_DAYS, 'log_id' ),
		);
		$deleted = array();
		$failed  = array();
		foreach ( $targets as $target ) {
			$table = $target[0];
			// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — retired context logs are retained only in Twin Event Stream.
			if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && ! BizCity_Legacy_Table_Policy::allow_sql( $table, 'delete' ) ) {
				continue;
			}
			if ( function_exists( 'bizcity_tbl_exists' ) && ! bizcity_tbl_exists( $table ) ) {
				continue;
			}
			$removed = self::delete_old_rows( $table, (int) $target[1], (string) $target[2] );
			if ( false === $removed ) {
				$failed[] = $table;
				continue;
			}
			$deleted[ $table ] = $removed;
		}

		if ( class_exists( 'BizCity_Cron_Manager' ) ) {
			$cron = BizCity_Cron_Manager::instance();
			$cron->note( array( 'counters' => array( 'twin_state_retention_deleted' => array_sum( $deleted ) ) ) );
			foreach ( $deleted as $table => $count ) {
				$cron->note_event( 'twin_state_retention_table', array( 'table' => $table, 'deleted' => (int) $count ) );
			}
			foreach ( $failed as $table ) {
				$cron->note_event( 'twin_state_retention_failed', array( 'table' => $table, 'reason' => 'delete_failed' ) );
			}
		}
	}

	private static function delete_old_rows( string $table, int $retention_days, string $primary_key ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE created_at < (CURRENT_TIMESTAMP - INTERVAL %d DAY) ORDER BY {$primary_key} ASC LIMIT %d",
			max( 1, $retention_days ),
			self::RETENTION_BATCH
		) );
	}

	/* ================================================================
	 * MIGRATION
	 * ================================================================ */

	/**
	 * Create or update the active state tables. Safe to call on every page load.
	 */
	public static function ensure_tables(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();

		// [2026-07-29 Johnny Chu] PHASE-1.21-C — keep only state tables with active consumers.
		self::create_prompt_specs_table( $charset );
		self::create_milestones_table( $charset );
		// [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — do not provision the retired context-log SQL projection.
		if ( ! class_exists( 'BizCity_Legacy_Table_Policy' ) || ! BizCity_Legacy_Table_Policy::install_blocked( self::context_logs_table() ) ) {
			self::create_context_logs_table( $charset );
		}

		$tables = self::check_tables();
		if ( ! empty( $tables['ok'] ) ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}
	}

	/**
	 * Installer-registry compatibility entry point.
	 */
	public static function maybe_install(): void {
		// [2026-07-29 Johnny Chu] PHASE-1.21-C — keep Diagnostics self-heal wired to the schema owner.
		self::ensure_tables();
	}

	/* ----------------------------------------------------------------
	 * 5) SUPPORT: bizcity_twin_prompt_specs
	 * ---------------------------------------------------------------- */
	private static function create_prompt_specs_table( string $charset ): void {
		$table = self::prompt_specs_table();
		// [2026-07-30 Johnny Chu] PHASE-1.22-RETENTION — add an age index for bounded cleanup.
		dbDelta( "CREATE TABLE {$table} (
			prompt_spec_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			PRIMARY KEY (prompt_spec_id),
			trace_id VARCHAR(80) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			session_id VARCHAR(120) NULL,
			project_id BIGINT UNSIGNED NULL,
			intent_conversation_id VARCHAR(120) NULL,
			raw_prompt LONGTEXT NOT NULL,
			prompt_segments_json LONGTEXT NULL,
			objective_list_json LONGTEXT NULL,
			primary_objective TEXT NULL,
			secondary_objectives_json LONGTEXT NULL,
			expected_outputs_json LONGTEXT NULL,
			constraints_json LONGTEXT NULL,
			ambiguity_flags_json LONGTEXT NULL,
			confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
			needs_confirmation TINYINT(1) NOT NULL DEFAULT 0,
			confirmation_questions_json LONGTEXT NULL,
			recommended_mode VARCHAR(50) NULL,
			recommended_path VARCHAR(50) NULL,
			recommended_tools_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			KEY idx_trace_id (trace_id),
			KEY idx_user_blog_created (user_id, blog_id, created_at),
			KEY idx_created_at (created_at),
			KEY idx_needs_confirmation (needs_confirmation)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 6) SUPPORT: bizcity_twin_milestones
	 * ---------------------------------------------------------------- */
	private static function create_milestones_table( string $charset ): void {
		$table = self::milestones_table();
		// [2026-07-30 Johnny Chu] PHASE-1.22-RETENTION — add an age index for bounded cleanup.
		dbDelta( "CREATE TABLE {$table} (
			milestone_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			PRIMARY KEY (milestone_id),
			trace_id VARCHAR(80) NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			journey_id BIGINT UNSIGNED NULL,
			milestone_type VARCHAR(80) NOT NULL,
			milestone_label VARCHAR(255) NULL,
			milestone_score DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
			source_type VARCHAR(80) NULL,
			source_ref_id VARCHAR(120) NULL,
			payload_json LONGTEXT NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			KEY idx_user_blog_occurred (user_id, blog_id, occurred_at),
			KEY idx_trace_id (trace_id),
			KEY idx_milestone_type (milestone_type),
			KEY idx_created_at (created_at)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 7) SUPPORT: bizcity_twin_context_logs
	 * ---------------------------------------------------------------- */
	private static function create_context_logs_table( string $charset ): void {
		$table = self::context_logs_table();
		// [2026-07-30 Johnny Chu] PHASE-1.22-RETENTION — add an age index for bounded cleanup.
		dbDelta( "CREATE TABLE {$table} (
			log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			PRIMARY KEY (log_id),
			trace_id VARCHAR(80) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			path VARCHAR(40) NOT NULL,
			mode VARCHAR(40) NULL,
			decision_type VARCHAR(60) NOT NULL,
			decision_label VARCHAR(120) NULL,
			decision_score DECIMAL(7,4) NULL,
			payload_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			KEY idx_trace_id (trace_id),
			KEY idx_user_blog_created (user_id, blog_id, created_at),
			KEY idx_created_at (created_at),
			KEY idx_path_mode (path, mode)
		) {$charset};" );
	}

	/* ================================================================
	 * UTILITY: Check migration status
	 * ================================================================ */

	/**
	 * Check if all active state tables exist.
	 *
	 * @return array{ok: bool, missing: string[]}
	 */
	public static function check_tables(): array {
		$tables = [
			'twin_prompt_specs',
			'twin_milestones',
		];

		$missing = [];
		foreach ( $tables as $t ) {
			if ( ! BizCity_Twin_Data_Contract::table_exists( $t ) ) {
				$missing[] = $t;
			}
		}

		return [
			'ok'      => empty( $missing ),
			'missing' => $missing,
		];
	}
}

// [2026-07-29 Johnny Chu] PHASE-1.21-C — central registry ownership for the active state schema.
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_twin_prompt_specs',
		'core.twin-core',
		BizCity_Twin_State_Schema::DB_VERSION,
		BizCity_Twin_State_Schema::DB_VERSION_OPTION,
		array( 'BizCity_Twin_State_Schema', 'maybe_install' )
	);
	BizCity_Schema_Registry::register(
		'bizcity_twin_milestones',
		'core.twin-core',
		BizCity_Twin_State_Schema::DB_VERSION,
		BizCity_Twin_State_Schema::DB_VERSION_OPTION,
		array( 'BizCity_Twin_State_Schema', 'maybe_install' )
	);
	BizCity_Schema_Registry::register(
		'bizcity_twin_context_logs',
		'core.twin-core',
		BizCity_Twin_State_Schema::DB_VERSION,
		BizCity_Twin_State_Schema::DB_VERSION_OPTION,
		array( 'BizCity_Twin_State_Schema', 'maybe_install' )
	);
}
