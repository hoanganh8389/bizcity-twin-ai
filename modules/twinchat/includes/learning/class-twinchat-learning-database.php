<?php
/**
 * Bizcity TwinChat — Learning Database
 *
 * Phase 4.9 — backend learning pipeline storage:
 *   bizcity_kg_learning_jobs    : one row per ingest → learning run (queued | running | done | failed | cancelled).
 *   bizcity_kg_learning_events  : ring-buffer of events streamed to the SSE client (logs, progress, done, chat).
 *   bizcity_kg_learning_batches : per-batch ledger so cron + ajax-tick can share progress and resume after crash.
 *
 * Schema 1.2.0 (2026-04-28) renames legacy `tc_learning_*` tables in place
 * (RENAME TABLE) for unified naming consistent with the rest of bizcity_kg_*.
 *
 * All tables are scoped per notebook so retention can be pruned cheaply.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Learning
 * @since 2026-04-28
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Learning_Database {

	// 1.0.0 — initial (jobs + events)
	// 1.1.0 — hybrid exec: jobs.phase / lease_owner / lease_until / batches_total / batches_done + new tc_learning_batches table
	// 1.2.0 — rename legacy tc_learning_* → bizcity_kg_learning_* (unified naming for cross-plugin tracing)
	// 1.3.0 — Wave A (TwinShell Learning Hub): add jobs.origin (user|sweep|backfill|api) + jobs.restartable_at (cleanup window)
	const SCHEMA_VERSION     = '1.3.0';
	const OPTION_VERSION_KEY = 'bizcity_twinchat_learning_db_version';

	const EVENTS_RING_PER_NB = 1000;

	/** Legacy table base names (pre-1.2.0). Used only by the rename migration. */
	const LEGACY_TABLES = [
		'tc_learning_jobs'    => 'bizcity_kg_learning_jobs',
		'tc_learning_events'  => 'bizcity_kg_learning_events',
		'tc_learning_batches' => 'bizcity_kg_learning_batches',
	];

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function table_jobs() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_jobs';
	}

	public function table_events() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_events';
	}

	public function table_batches() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_batches';
	}

	/** Install / upgrade when bumped. Idempotent. */
	public function maybe_install() {
		$installed = get_option( self::OPTION_VERSION_KEY, '' );
		if ( $installed === self::SCHEMA_VERSION ) {
			return;
		}
		// 1.2.0 — rename old tables in place (preserves data + indexes).
		$this->migrate_rename_legacy_tables();
		$this->create_tables();
		// 1.3.0 — additive ALTERs (idempotent: suppress + ignore "Duplicate column" 1060).
		$this->migrate_jobs_origin_columns();
		update_option( self::OPTION_VERSION_KEY, self::SCHEMA_VERSION, false );
	}

	/**
	 * 1.3.0 — add `origin` + `restartable_at` columns to jobs table.
	 *
	 * Idempotent. Errors are suppressed because dbDelta-style detection of new
	 * columns can race with the WPDB router (similar to create_tables()).
	 */
	protected function migrate_jobs_origin_columns() {
		global $wpdb;
		$jobs = $this->table_jobs();
		$prev_show = $wpdb->show_errors;
		$wpdb->hide_errors();
		$prev_supp = $wpdb->suppress_errors( true );

		// Check existing columns once → only ALTER what is missing.
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `{$jobs}`" );
		$cols = is_array( $cols ) ? array_map( 'strtolower', $cols ) : [];

		if ( ! in_array( 'origin', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD COLUMN `origin` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `source_id`" );
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD KEY `idx_origin` (`origin`)" );
		}
		if ( ! in_array( 'restartable_at', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD COLUMN `restartable_at` DATETIME NULL DEFAULT NULL AFTER `finished_at`" );
		}

		$wpdb->suppress_errors( $prev_supp );
		if ( $prev_show ) { $wpdb->show_errors(); }
	}

	/**
	 * Rename legacy `{prefix}tc_learning_*` tables to `{prefix}bizcity_kg_learning_*`.
	 *
	 * Idempotent: only renames when the legacy table exists AND the target does
	 * not. Errors are suppressed (router noise) and logged once.
	 */
	protected function migrate_rename_legacy_tables() {
		global $wpdb;
		$prev_supp = $wpdb->suppress_errors( true );
		foreach ( self::LEGACY_TABLES as $old_base => $new_base ) {
			$old = $wpdb->prefix . $old_base;
			$new = $wpdb->prefix . $new_base;
			$old_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) );
			$new_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) );
			if ( $old_exists && ! $new_exists ) {
				// MySQL RENAME TABLE — atomic, preserves data + indexes + AUTO_INCREMENT.
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
				if ( function_exists( 'error_log' ) ) {
					error_log( "[TwinChat Learning DB] migrated {$old} → {$new}" );
				}
			} elseif ( $old_exists && $new_exists ) {
				// Edge: both present (e.g. partial deploy). Drop legacy to avoid drift.
				$wpdb->query( "DROP TABLE `{$old}`" );
				if ( function_exists( 'error_log' ) ) {
					error_log( "[TwinChat Learning DB] dropped legacy {$old} (target {$new} already exists)" );
				}
			}
		}
		$wpdb->suppress_errors( $prev_supp );
	}

	public function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$jobs    = $this->table_jobs();
		$events  = $this->table_events();
		$batches = $this->table_batches();

		$sql_jobs = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			source_id BIGINT UNSIGNED NULL,
			origin VARCHAR(20) NOT NULL DEFAULT 'user',
			source_title VARCHAR(255) NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			phase VARCHAR(20) NOT NULL DEFAULT 'queued',
			lease_owner VARCHAR(64) NULL,
			lease_until DATETIME NULL DEFAULT NULL,
			progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
			passages_processed INT UNSIGNED NOT NULL DEFAULT 0,
			triplets_extracted INT UNSIGNED NOT NULL DEFAULT 0,
			entities_approved INT UNSIGNED NOT NULL DEFAULT 0,
			batches_total INT UNSIGNED NOT NULL DEFAULT 0,
			batches_done INT UNSIGNED NOT NULL DEFAULT 0,
			entity_ids LONGTEXT NULL,
			error TEXT NULL,
			started_at DATETIME NULL DEFAULT NULL,
			finished_at DATETIME NULL DEFAULT NULL,
			restartable_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_notebook (notebook_id),
			KEY idx_status (status),
			KEY idx_phase (phase),
			KEY idx_lease (lease_until),
			KEY idx_source (source_id),
			KEY idx_origin (origin)
		) {$charset};";

		$sql_events = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			job_id BIGINT UNSIGNED NULL,
			ts DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			event VARCHAR(32) NOT NULL,
			payload LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_notebook_id (notebook_id, id),
			KEY idx_job (job_id)
		) {$charset};";

		// Batch ledger — one row per extract/approve sub-step.
		// Lets cron + ajax tick share progress and resume after crash.
		$sql_batches = "CREATE TABLE {$batches} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED NOT NULL,
			notebook_id BIGINT UNSIGNED NOT NULL,
			batch_no INT UNSIGNED NOT NULL,
			phase VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			owner VARCHAR(64) NULL,
			passages_count INT UNSIGNED NOT NULL DEFAULT 0,
			triplets_count INT UNSIGNED NOT NULL DEFAULT 0,
			errors_count INT UNSIGNED NOT NULL DEFAULT 0,
			started_at DATETIME NULL DEFAULT NULL,
			finished_at DATETIME NULL DEFAULT NULL,
			error TEXT NULL,
			PRIMARY KEY  (id),
			KEY idx_job (job_id, batch_no),
			KEY idx_status (status)
		) {$charset};";

		// Suppress dbDelta's noisy "Duplicate column name / Duplicate key name"
		// warnings: the BizCity_WPDB_Router can route the introspection
		// queries differently from the ALTERs which makes dbDelta re-issue
		// already-applied column adds. The end state is correct.
		$prev_show = $wpdb->show_errors;
		$wpdb->hide_errors();
		$prev_supp = $wpdb->suppress_errors( true );

		dbDelta( $sql_jobs );
		dbDelta( $sql_events );
		dbDelta( $sql_batches );

		$wpdb->suppress_errors( $prev_supp );
		if ( $prev_show ) { $wpdb->show_errors(); }
	}

	/** Trim old events for a notebook so the ring buffer never grows past N rows. */
	public function trim_events( $notebook_id, $keep = self::EVENTS_RING_PER_NB ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		$keep        = max( 100, (int) $keep );
		$tbl         = $this->table_events();

		$cutoff_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE notebook_id=%d ORDER BY id DESC LIMIT 1 OFFSET %d",
			$notebook_id, $keep
		) );
		if ( ! $cutoff_id ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$tbl} WHERE notebook_id=%d AND id <= %d",
			$notebook_id, (int) $cutoff_id
		) );
	}
}
