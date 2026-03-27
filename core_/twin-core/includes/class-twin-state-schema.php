<?php
/**
 * BizCity Twin State Schema — DDL for 4 core + 3 support state tables.
 *
 * Phase 2 Priority 3 + 4 + 5: Create the Twin state backbone.
 *
 * Tables:
 *   CORE:    twin_identity, twin_focus_state, twin_timeline_state, twin_journeys
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

	const DB_VERSION        = '2.0';
	const DB_VERSION_OPTION = 'bizcity_twin_state_db_ver';

	/* ================================================================
	 * TABLE NAMES
	 * ================================================================ */

	public static function t( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_' . $name;
	}

	public static function identity_table(): string       { return self::t( 'twin_identity' ); }
	public static function focus_table(): string          { return self::t( 'twin_focus_state' ); }
	public static function timeline_table(): string       { return self::t( 'twin_timeline_state' ); }
	public static function journeys_table(): string       { return self::t( 'twin_journeys' ); }
	public static function prompt_specs_table(): string   { return self::t( 'twin_prompt_specs' ); }
	public static function milestones_table(): string     { return self::t( 'twin_milestones' ); }
	public static function context_logs_table(): string   { return self::t( 'twin_context_logs' ); }

	/* ================================================================
	 * MIGRATION
	 * ================================================================ */

	/**
	 * Create or update all 7 tables. Safe to call on every page load.
	 */
	public static function ensure_tables(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		self::create_identity_table( $charset );
		self::create_focus_table( $charset );
		self::create_timeline_table( $charset );
		self::create_journeys_table( $charset );
		self::create_prompt_specs_table( $charset );
		self::create_milestones_table( $charset );
		self::create_context_logs_table( $charset );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/* ----------------------------------------------------------------
	 * 1) CORE: bizcity_twin_identity
	 * ---------------------------------------------------------------- */
	private static function create_identity_table( string $charset ): void {
		$table = self::identity_table();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			support_style VARCHAR(60) NULL,
			relationship_mode VARCHAR(60) NULL,
			communication_preferences_json LONGTEXT NULL,
			domain_strengths_json LONGTEXT NULL,
			life_goal_hypotheses_json LONGTEXT NULL,
			identity_confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
			source_evidence_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY uniq_user_blog (user_id, blog_id),
			KEY idx_updated_at (updated_at)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 2) CORE: bizcity_twin_focus_state
	 * ---------------------------------------------------------------- */
	private static function create_focus_table( string $charset ): void {
		$table = self::focus_table();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			trace_id VARCHAR(80) NULL,
			current_focus_type VARCHAR(80) NULL,
			current_focus_ref_id VARCHAR(120) NULL,
			current_focus_label VARCHAR(255) NULL,
			focus_score DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
			focus_confidence DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
			open_loops_json LONGTEXT NULL,
			suppression_list_json LONGTEXT NULL,
			next_best_actions_json LONGTEXT NULL,
			last_prompt_spec_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY uniq_user_blog (user_id, blog_id),
			KEY idx_trace_id (trace_id),
			KEY idx_focus_type (current_focus_type)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 3) CORE: bizcity_twin_timeline_state
	 * ---------------------------------------------------------------- */
	private static function create_timeline_table( string $charset ): void {
		$table = self::timeline_table();
		dbDelta( "CREATE TABLE {$table} (
			id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			as_of_date DATE NOT NULL,
			today_context_json LONGTEXT NULL,
			recent_events_json LONGTEXT NULL,
			active_threads_json LONGTEXT NULL,
			tool_events_json LONGTEXT NULL,
			goal_events_json LONGTEXT NULL,
			memory_events_json LONGTEXT NULL,
			note_events_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			UNIQUE KEY uniq_user_blog_date (user_id, blog_id, as_of_date),
			KEY idx_as_of_date (as_of_date)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 4) CORE: bizcity_twin_journeys
	 * ---------------------------------------------------------------- */
	private static function create_journeys_table( string $charset ): void {
		$table = self::journeys_table();
		dbDelta( "CREATE TABLE {$table} (
			journey_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			user_id BIGINT UNSIGNED NOT NULL,
			blog_id BIGINT UNSIGNED NOT NULL,
			journey_type VARCHAR(80) NOT NULL,
			journey_label VARCHAR(255) NOT NULL,
			stage VARCHAR(80) NULL,
			progress_score DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
			milestones_json LONGTEXT NULL,
			pain_points_json LONGTEXT NULL,
			linked_goals_json LONGTEXT NULL,
			linked_notes_json LONGTEXT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			KEY idx_user_blog (user_id, blog_id),
			KEY idx_status (status),
			KEY idx_updated_at (updated_at)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 5) SUPPORT: bizcity_twin_prompt_specs
	 * ---------------------------------------------------------------- */
	private static function create_prompt_specs_table( string $charset ): void {
		$table = self::prompt_specs_table();
		dbDelta( "CREATE TABLE {$table} (
			prompt_spec_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
			KEY idx_needs_confirmation (needs_confirmation)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 6) SUPPORT: bizcity_twin_milestones
	 * ---------------------------------------------------------------- */
	private static function create_milestones_table( string $charset ): void {
		$table = self::milestones_table();
		dbDelta( "CREATE TABLE {$table} (
			milestone_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
			KEY idx_milestone_type (milestone_type)
		) {$charset};" );
	}

	/* ----------------------------------------------------------------
	 * 7) SUPPORT: bizcity_twin_context_logs
	 * ---------------------------------------------------------------- */
	private static function create_context_logs_table( string $charset ): void {
		$table = self::context_logs_table();
		dbDelta( "CREATE TABLE {$table} (
			log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
			KEY idx_path_mode (path, mode)
		) {$charset};" );
	}

	/* ================================================================
	 * UTILITY: Check migration status
	 * ================================================================ */

	/**
	 * Check if all 7 tables exist.
	 *
	 * @return array{ok: bool, missing: string[]}
	 */
	public static function check_tables(): array {
		$tables = [
			'twin_identity',
			'twin_focus_state',
			'twin_timeline_state',
			'twin_journeys',
			'twin_prompt_specs',
			'twin_milestones',
			'twin_context_logs',
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
