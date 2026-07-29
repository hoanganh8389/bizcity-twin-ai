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
	// 1.4.0 — add jobs.updated_at (auto ON UPDATE CURRENT_TIMESTAMP) so REST rebuild can detect stale lease holders (2026-05-27).
	const SCHEMA_VERSION     = '1.4.0';
	const OPTION_VERSION_KEY = 'bizcity_twinchat_learning_db_version';

	const EVENTS_RING_PER_NB = 1000;

	/** Legacy table base names (pre-1.2.0). Used only by the rename migration. */
	const LEGACY_TABLES = [
		'tc_learning_jobs'    => 'bizcity_kg_learning_jobs',
		'tc_learning_events'  => 'bizcity_kg_learning_events',
		'tc_learning_batches' => 'bizcity_kg_learning_batches',
	];

	private static $instance = null;
	/** Per-blog warm flag so we do not run schema checks repeatedly in one request. */
	private static $ready_blogs = [];
	/** In-request cache for table-existence probes. */
	private static $table_exists_static = [];
	/** In-request cache for column-existence probes. */
	private static $column_exists_static = [];
	/** Re-entrancy guard while maybe_install() is actively migrating. */
	private $is_installing = false;

	/**
	 * [2026-07-23 Johnny Chu] PHASE-0.44 — route learning DB logs to
	 * uploads/.../bizcity_learning_logs so operators can inspect one place.
	 *
	 * @param string $message
	 */
	protected function write_learning_log( $message ) {
		$msg = '[db] ' . (string) $message;
		if ( function_exists( 'bizcity_tc_learning_debug_log' ) ) {
			bizcity_tc_learning_debug_log( $msg );
			return;
		}

		$path = '';
		if ( function_exists( 'bizcity_tc_learning_debug_log_path' ) ) {
			$path = (string) bizcity_tc_learning_debug_log_path( '', true );
		} else {
			$uploads  = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, true, false ) : array();
			$base_dir = ( is_array( $uploads ) && ! empty( $uploads['basedir'] ) )
				? (string) $uploads['basedir']
				: WP_CONTENT_DIR . '/uploads';
			$log_dir  = trailingslashit( wp_normalize_path( $base_dir ) ) . 'bizcity_learning_logs';
			if ( ! is_dir( $log_dir ) ) {
				@wp_mkdir_p( $log_dir );
			}
			$path = trailingslashit( $log_dir ) . gmdate( 'Y-m-d' ) . '.log';
		}

		if ( $path !== '' ) {
			$line = sprintf( "[%s UTC] [TC-Learning] %s\n", gmdate( 'd-M-Y H:i:s' ), $msg );
			@file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
		}
	}

	private function jobs_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_jobs';
	}

	private function events_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_events';
	}

	private function batches_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_kg_learning_batches';
	}

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * [2026-07-27 Johnny Chu] R-SHOW-TABLES — dual-cache key for table probes.
	 */
	private function table_probe_cache_key( $table_name ) {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return 'bz_tbl_' . $blog_id . '_' . sprintf( '%u', crc32( (string) $table_name ) );
	}

	/**
	 * [2026-07-27 Johnny Chu] R-SHOW-TABLES — dual-cache key for column probes.
	 */
	private function column_probe_cache_key( $table_name, $column_name ) {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$sig = strtolower( (string) $table_name . '|' . (string) $column_name );
		return 'bz_col_' . $blog_id . '_' . sprintf( '%u', crc32( $sig ) );
	}

	/**
	 * [2026-07-27 Johnny Chu] R-SHOW-TABLES — canonical table probe via information_schema.
	 */
	public function table_exists( $table_name ) {
		global $wpdb;
		$table_name = trim( (string) $table_name );
		if ( $table_name === '' ) {
			return false;
		}

		$cache_key = $this->table_probe_cache_key( $table_name );
		if ( isset( self::$table_exists_static[ $cache_key ] ) ) {
			return self::$table_exists_static[ $cache_key ];
		}

		$present = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( $present === false ) {
			$prev_supp = $wpdb->suppress_errors( true );
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
				$table_name
			) );
			$wpdb->suppress_errors( $prev_supp );
			wp_cache_set( $cache_key, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}

		self::$table_exists_static[ $cache_key ] = (bool) $present;
		return self::$table_exists_static[ $cache_key ];
	}

	/**
	 * [2026-07-27 Johnny Chu] R-SHOW-TABLES — canonical column probe via information_schema.
	 */
	public function column_exists( $table_name, $column_name ) {
		global $wpdb;
		$table_name  = trim( (string) $table_name );
		$column_name = trim( (string) $column_name );
		if ( $table_name === '' || $column_name === '' ) {
			return false;
		}

		$cache_key = $this->column_probe_cache_key( $table_name, $column_name );
		if ( isset( self::$column_exists_static[ $cache_key ] ) ) {
			return self::$column_exists_static[ $cache_key ];
		}

		$present = wp_cache_get( $cache_key, 'bizcity_tbl' );
		if ( $present === false ) {
			$prev_supp = $wpdb->suppress_errors( true );
			$present = (int) (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1",
				$table_name,
				$column_name
			) );
			$wpdb->suppress_errors( $prev_supp );
			wp_cache_set( $cache_key, $present, 'bizcity_tbl', HOUR_IN_SECONDS );
		}

		self::$column_exists_static[ $cache_key ] = (bool) $present;
		return self::$column_exists_static[ $cache_key ];
	}

	/**
	 * [2026-07-27 Johnny Chu] R-SHOW-TABLES — clear schema probe caches after DDL/migrations.
	 */
	private function invalidate_schema_probe_cache() {
		$tables = [
			$this->jobs_table_name(),
			$this->events_table_name(),
			$this->batches_table_name(),
		];
		foreach ( self::LEGACY_TABLES as $old_base => $new_base ) {
			global $wpdb;
			$tables[] = $wpdb->prefix . $old_base;
			$tables[] = $wpdb->prefix . $new_base;
		}

		$tables = array_values( array_unique( array_filter( $tables ) ) );
		foreach ( $tables as $table ) {
			$t_key = $this->table_probe_cache_key( $table );
			unset( self::$table_exists_static[ $t_key ] );
			wp_cache_delete( $t_key, 'bizcity_tbl' );

			foreach ( [ 'origin', 'restartable_at', 'updated_at' ] as $col ) {
				$c_key = $this->column_probe_cache_key( $table, $col );
				unset( self::$column_exists_static[ $c_key ] );
				wp_cache_delete( $c_key, 'bizcity_tbl' );
			}
		}
	}

	public function table_jobs() {
		if ( ! $this->is_installing ) {
			$this->maybe_install();
		}
		return $this->jobs_table_name();
	}

	public function table_events() {
		if ( ! $this->is_installing ) {
			$this->maybe_install();
		}
		return $this->events_table_name();
	}

	public function table_batches() {
		if ( ! $this->is_installing ) {
			$this->maybe_install();
		}
		return $this->batches_table_name();
	}

	/** Install / upgrade when bumped. Idempotent. */
	public function maybe_install() {
		if ( $this->is_installing ) {
			return;
		}
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( self::$ready_blogs[ $blog_id ] ) ) {
			return;
		}

		$this->is_installing = true;
		$installed = get_option( self::OPTION_VERSION_KEY, '' );

		// Version check FIRST — avoids repeated schema probes on every request when current.
		// required_tables_exist() is only called on real version mismatch.
		if ( $installed === self::SCHEMA_VERSION ) {
			self::$ready_blogs[ $blog_id ] = true;
			$this->is_installing = false;
			return;
		}

		$schema_exists = $this->required_tables_exist();
		// 1.2.0 — rename old tables in place (preserves data + indexes).
		$this->migrate_rename_legacy_tables();
		$this->create_tables();
		// 1.3.0 — additive ALTERs (idempotent: suppress + ignore "Duplicate column" 1060).
		$this->migrate_jobs_origin_columns();
		// 1.4.0 — additive ALTER for jobs.updated_at.
		$this->migrate_jobs_updated_at_column();
		$this->invalidate_schema_probe_cache();
		$schema_exists = $this->required_tables_exist();
		if ( $schema_exists ) {
			update_option( self::OPTION_VERSION_KEY, self::SCHEMA_VERSION, false );
			self::$ready_blogs[ $blog_id ] = true;
		} else {
			$this->write_learning_log( 'install attempted but required tables are still missing.' );
		}
		$this->is_installing = false;
	}

	/** Quick readiness probe for callers before running SELECT/INSERT. */
	public function is_ready() {
		$this->maybe_install();
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( self::$ready_blogs[ $blog_id ] ) ) {
			return true;
		}
		return $this->required_tables_exist();
	}

	/**
	 * Verify all required tables are physically present for the current blog prefix.
	 * This protects against stale version options after partial deploys/migrations.
	 */
	protected function required_tables_exist() {
		$tables = [
			$this->jobs_table_name(),
			$this->events_table_name(),
			$this->batches_table_name(),
		];
		foreach ( $tables as $tbl ) {
			if ( ! $this->table_exists( $tbl ) ) {
				return false;
			}
		}
		return true;
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

		if ( ! $this->column_exists( $jobs, 'origin' ) ) {
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD COLUMN `origin` VARCHAR(20) NOT NULL DEFAULT 'user' AFTER `source_id`" );
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD KEY `idx_origin` (`origin`)" );
		}
		if ( ! $this->column_exists( $jobs, 'restartable_at' ) ) {
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD COLUMN `restartable_at` DATETIME NULL DEFAULT NULL AFTER `finished_at`" );
		}

		$wpdb->suppress_errors( $prev_supp );
		if ( $prev_show ) { $wpdb->show_errors(); }
	}

	/**
	 * 1.4.0 — add `updated_at` (DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
	 * to the jobs table so REST rebuild can detect stale lease holders.
	 *
	 * Idempotent. Errors are suppressed because dbDelta-style detection of new
	 * columns can race with the WPDB router.
	 */
	protected function migrate_jobs_updated_at_column() {
		global $wpdb;
		$jobs = $this->jobs_table_name();
		$prev_show = $wpdb->show_errors;
		$wpdb->hide_errors();
		$prev_supp = $wpdb->suppress_errors( true );

		if ( ! $this->column_exists( $jobs, 'updated_at' ) ) {
			$wpdb->query(
				"ALTER TABLE `{$jobs}` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`"
			);
			$wpdb->query( "ALTER TABLE `{$jobs}` ADD KEY `idx_updated` (`updated_at`)" );
			$this->write_learning_log( 'migrated jobs: added updated_at column (1.4.0)' );
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
			$old_exists = $this->table_exists( $old );
			$new_exists = $this->table_exists( $new );
			if ( $old_exists && ! $new_exists ) {
				// MySQL RENAME TABLE — atomic, preserves data + indexes + AUTO_INCREMENT.
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
				$this->write_learning_log( "migrated {$old} -> {$new}" );
			} elseif ( $old_exists && $new_exists ) {
				// Edge: both present (e.g. partial deploy). Drop legacy to avoid drift.
				$wpdb->query( "DROP TABLE `{$old}`" );
				$this->write_learning_log( "dropped legacy {$old} (target {$new} already exists)" );
			}
		}
		$this->invalidate_schema_probe_cache();
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
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_notebook (notebook_id),
			KEY idx_status (status),
			KEY idx_phase (phase),
			KEY idx_lease (lease_until),
			KEY idx_source (source_id),
			KEY idx_origin (origin),
			KEY idx_updated (updated_at)
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

	/**
	 * [2026-07-27 Johnny Chu] PHASE-0.51 — notebook lifecycle cleanup for
	 * learning rows (jobs/events/batches). Idempotent and notebook-scoped.
	 *
	 * @param int $notebook_id
	 * @return array<string,int>
	 */
	public function delete_for_notebook( $notebook_id ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) {
			return [ 'jobs' => 0, 'events' => 0, 'batches' => 0, 'total' => 0 ];
		}

		$jobs_tbl    = $this->jobs_table_name();
		$events_tbl  = $this->events_table_name();
		$batches_tbl = $this->batches_table_name();

		$prev_supp = $wpdb->suppress_errors( true );
		$deleted_jobs = 0;
		$deleted_events = 0;
		$deleted_batches = 0;

		if ( $this->table_exists( $batches_tbl ) ) {
			$deleted_batches = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$batches_tbl} WHERE notebook_id = %d",
				$notebook_id
			) );
		}
		if ( $this->table_exists( $events_tbl ) ) {
			$deleted_events = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$events_tbl} WHERE notebook_id = %d",
				$notebook_id
			) );
		}
		if ( $this->table_exists( $jobs_tbl ) ) {
			$deleted_jobs = (int) $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$jobs_tbl} WHERE notebook_id = %d",
				$notebook_id
			) );
		}

		$wpdb->suppress_errors( $prev_supp );

		return [
			'jobs'    => max( 0, $deleted_jobs ),
			'events'  => max( 0, $deleted_events ),
			'batches' => max( 0, $deleted_batches ),
			'total'   => max( 0, $deleted_jobs ) + max( 0, $deleted_events ) + max( 0, $deleted_batches ),
		];
	}
}
