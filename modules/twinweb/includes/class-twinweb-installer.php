<?php
/**
 * TwinWeb — DB Installer
 *
 * Creates `bizcity_twinweb_threads` and `bizcity_twinweb_artifact_jobs` tables.
 * Wave 1: table created on activation + version bump.
 *
 * Schema: modules/twinweb/docs/PHASE-0-TWINWEB-APP-MENUS.md §2.1
 *
 * PHP 7.4 compatible.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Modules\TwinWeb
 * @since 2026-06-17 (PHASE-TWINWEB Wave 1)
 */
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_TwinWeb_Installer' ) ) { return; }

class BizCity_TwinWeb_Installer {

	const VERSION        = '1.2.4'; // [2026-07-31 Johnny Chu] HOTFIX — replace TwinWeb dbDelta with guarded CREATE TABLE to eliminate malformed ALTER generation.
	const VERSION_OPTION = 'bizcity_twinweb_db_version';
	const THREADS_BASE   = 'bizcity_twinweb_threads';
	const JOBS_BASE      = 'bizcity_twinweb_artifact_jobs';

	/**
	 * Run installer if schema version is stale.
	 * Call from bootstrap.php at plugins_loaded.
	 */
	public static function maybe_install() {
		$installed = get_option( self::VERSION_OPTION, '' );
		if ( version_compare( $installed, self::VERSION, '>=' ) ) {
			global $wpdb;
			// [2026-08-28 Johnny Chu] PHASE-1.31-N2 — do not trust version stamp alone; cloned shards can have a current option with missing physical TwinWeb tables.
			$threads_table = $wpdb->prefix . self::THREADS_BASE;
			$jobs_table    = $wpdb->prefix . self::JOBS_BASE;
			if ( self::table_exists( $threads_table ) && self::table_exists( $jobs_table ) ) {
				return;
			}
		}
		if ( self::install() ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}

	public static function install() {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — register schema before any dbDelta call (R-CR.2).
		self::register_schema();

		// [2026-07-18 Johnny Chu] PHASE-TWIN-GPT-C-ENDUSER C-8-FIX — split thread-table repair from page provisioning so REST can self-heal storage only.
		$threads_ok = self::ensure_threads_table();

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — provision durable AT-7 artifact jobs table.
		$jobs_ok = self::ensure_artifact_jobs_table();

		// [2026-06-18 Johnny Chu] PHASE-TWINWEB — auto-create WP page with [bizcity_twin]
		// shortcode so admins have a ready-to-use public URL without manual setup.
		self::maybe_create_page();
		return $threads_ok && $jobs_ok;
	}

	/**
	 * Register TwinWeb tables in the central Schema Registry.
	 */
	public static function register_schema() {
		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — R-CR catalog entries for Twin GPT storage.
		if ( ! class_exists( 'BizCity_Schema_Registry' ) ) {
			return;
		}

		BizCity_Schema_Registry::register(
			self::THREADS_BASE,
			'modules.twinweb',
			self::VERSION,
			self::VERSION_OPTION,
			array( __CLASS__, 'install' )
		);
		BizCity_Schema_Registry::register(
			self::JOBS_BASE,
			'modules.twinweb',
			self::VERSION,
			self::VERSION_OPTION,
			array( __CLASS__, 'install' )
		);
	}

	private static function table_exists( $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
	}

	public static function ensure_threads_table() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// [2026-06-17 Johnny Chu] PHASE-TWINWEB — threads table (per-user chat thread list)
		$table = $wpdb->prefix . self::THREADS_BASE;
		$sql   = "CREATE TABLE {$table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			project_id  VARCHAR(50)     NOT NULL DEFAULT '',
			notebook_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			guest_sid   VARCHAR(64)     NOT NULL DEFAULT '',
			app_type    VARCHAR(30)     NOT NULL DEFAULT 'chat',
			title       VARCHAR(255)    NOT NULL DEFAULT '',
			pinned      TINYINT(1)      NOT NULL DEFAULT 0,
			archived    TINYINT(1)      NOT NULL DEFAULT 0,
			last_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			meta_json   LONGTEXT,
			PRIMARY KEY (id),
			KEY idx_user   (user_id),
			KEY idx_project (project_id),
			KEY idx_notebook (notebook_id),
			KEY idx_guest  (guest_sid(32)),
			KEY idx_app    (app_type),
			KEY idx_last   (last_at)
		) {$charset};";

		// [2026-07-31 Johnny Chu] HOTFIX — never run dbDelta for TwinWeb tables; its ALTER diff emitted empty clauses on tenant shards.
		if ( ! self::table_exists( $table ) ) {
			$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $table . ' ' . substr( $sql, strpos( $sql, '(' ) ) );
		}

		wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table ), 'bizcity_tbl' );

		// [2026-06-22 Johnny Chu] PHASE-TWINWEB — add project_id column for project grouping.
		// Uses bizcity_webchat_projects (existing table) — no new table created.
		// idempotent ALTER via information_schema check (R-SHOW-TABLES).
		self::ensure_project_id_column();
		self::ensure_notebook_id_column();
		return (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
			$table
		) );
	}

	/**
	 * Repair the project grouping column and index on existing installations.
	 */
	private static function ensure_project_id_column() {
		global $wpdb;
		$table = $wpdb->prefix . self::THREADS_BASE;

		// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — fail closed when the thread table is unavailable.
		$table_exists = self::table_exists( $table );
		if ( ! $table_exists ) {
			return;
		}

		$column_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table,
			'project_id'
		) );
		if ( ! $column_exists ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — add the missing project grouping column idempotently.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN project_id VARCHAR(50) NOT NULL DEFAULT '' AFTER user_id" );
		}

		$index_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
			$table,
			'idx_project'
		) );
		if ( ! $index_exists ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — add the index required by project filtering and drag/drop.
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_project (project_id)" );
		}

		wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table ), 'bizcity_tbl' );
		return self::table_exists( $table );
	}

	/**
	 * Add the canonical KG notebook association without removing legacy project_id.
	 */
	private static function ensure_notebook_id_column() {
		global $wpdb;
		$table = $wpdb->prefix . self::THREADS_BASE;

		$column_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s LIMIT 1',
			$table,
			'notebook_id'
		) );
		if ( ! $column_exists ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — add notebook_id as the canonical Project identity.
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN notebook_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER project_id" );
		}

		$index_exists = $wpdb->get_var( $wpdb->prepare(
			'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
			$table,
			'idx_notebook'
		) );
		if ( ! $index_exists ) {
			// [2026-07-30 Johnny Chu] PHASE-TWINWEB-UNIFIED-SOURCES — index notebook filtering and drag/drop reads.
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_notebook (notebook_id)" );
		}

		wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table ), 'bizcity_tbl' );
	}

	/**
	 * Idempotent CREATE/ADD for durable Agent Tool artifact jobs.
	 */
	public static function ensure_artifact_jobs_table() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — durable async state for Canvas polling/replay.
		$table = $wpdb->prefix . self::JOBS_BASE;
		$sql   = "CREATE TABLE {$table} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id        VARCHAR(40)     NOT NULL DEFAULT '',
			run_id        VARCHAR(80)     NOT NULL DEFAULT '',
			thread_id     VARCHAR(80)     NOT NULL DEFAULT '',
			message_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			guest_sid     VARCHAR(64)     NOT NULL DEFAULT '',
			tool_slug     VARCHAR(96)     NOT NULL DEFAULT '',
			artifact_type VARCHAR(32)     NOT NULL DEFAULT '',
			status        VARCHAR(24)     NOT NULL DEFAULT 'queued',
			progress      TINYINT UNSIGNED NOT NULL DEFAULT 0,
			reason_bucket VARCHAR(64)     NOT NULL DEFAULT '',
			owner_job_id  VARCHAR(80)     NOT NULL DEFAULT '',
			artifact_id   VARCHAR(80)     NOT NULL DEFAULT '',
			status_url    TEXT NULL,
			preview_url   TEXT NULL,
			download_url  TEXT NULL,
			input_json    LONGTEXT NULL,
			result_json   LONGTEXT NULL,
			error_payload LONGTEXT NULL,
			attempt_count INT UNSIGNED    NOT NULL DEFAULT 0,
			created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			started_at    DATETIME NULL DEFAULT NULL,
			finished_at   DATETIME NULL DEFAULT NULL,
			last_poll_at  DATETIME NULL DEFAULT NULL,
			next_poll_at  DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_job_id (job_id),
			KEY idx_owner_status (owner_user_id, status, updated_at),
			KEY idx_guest_status (guest_sid(32), status, updated_at),
			KEY idx_thread (thread_id, created_at),
			KEY idx_tool_status (tool_slug, status),
			KEY idx_next_poll (status, next_poll_at),
			KEY idx_owner_job (owner_job_id)
		) {$charset};";

		// [2026-07-31 Johnny Chu] HOTFIX — avoid dbDelta's malformed ALTER diff path for artifact-job tables too.
		if ( ! self::table_exists( $table ) ) {
			$wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $table . ' ' . substr( $sql, strpos( $sql, '(' ) ) );
		}

		wp_cache_delete( 'bz_tbl_' . (int) get_current_blog_id() . '_' . crc32( $table ), 'bizcity_tbl' );
		return self::table_exists( $table );
	}

	/**
	 * Create a WordPress page titled "Twin AI" with [bizcity_twin] shortcode
	 * if no such page exists yet.
	 *
	 * If option already set, keeps idempotency and only migrates managed
	 * legacy slug /twin-ai/ -> /gpt/.
	 */
	public static function maybe_create_page() {
		$target_slug = 'gpt';

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — keep option idempotent but migrate
		// managed legacy slug /twin-ai/ -> /gpt/ when page_id already exists.
		$page_id = (int) get_option( 'bizcity_twinweb_page_id', 0 );
		if ( $page_id > 0 ) {
			$page = get_post( $page_id );
			if ( $page && 'page' === $page->post_type && 'trash' !== (string) $page->post_status ) {
				self::maybe_migrate_page_slug( $page_id, $target_slug );
				return;
			}

			// [2026-07-15 Johnny Chu] PHASE-TWINWEB — stale option recovery.
			// If saved page_id points to deleted/trash/non-page, clear and recreate.
			delete_option( 'bizcity_twinweb_page_id' );
		}

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — prefer canonical /gpt/ page if it exists.
		$target_page = get_page_by_path( $target_slug, OBJECT, 'page' );
		if ( $target_page ) {
			update_option( 'bizcity_twinweb_page_id', (int) $target_page->ID );
			return;
		}

		// Check if any published page already has the shortcode
		$existing = get_pages( array(
			's'          => '[bizcity_twin]',
			'post_status' => 'publish',
		) );
		if ( ! empty( $existing ) ) {
			update_option( 'bizcity_twinweb_page_id', $existing[0]->ID );
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB — migrate only managed page slug.
			self::maybe_migrate_page_slug( (int) $existing[0]->ID, $target_slug );
			return;
		}

		$page_id = wp_insert_post( array(
			'post_title'   => 'Twin GPT', // [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — canonical product name.
			// [2026-07-15 Johnny Chu] PHASE-TWINWEB — default public URL is /gpt/.
			'post_name'    => $target_slug,
			'post_content' => '[bizcity_twin height="100vh"]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_author'  => 1,
			'meta_input'   => array( '_bizcity_twinweb_page' => '1' ),
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'bizcity_twinweb_page_id', (int) $page_id );
		}
	}

	/**
	 * Migrate managed TwinWeb page slug from legacy /twin-ai/ to /gpt/.
	 *
	 * Only applies to page marked by _bizcity_twinweb_page meta.
	 * This avoids changing admin-customized pages unexpectedly.
	 *
	 * @param int    $page_id Target page ID.
	 * @param string $target_slug Target slug.
	 */
	private static function maybe_migrate_page_slug( $page_id, $target_slug ) {
		$post = get_post( $page_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return;
		}

		$managed_flag = (string) get_post_meta( $page_id, '_bizcity_twinweb_page', true );
		if ( $managed_flag !== '1' ) {
			return;
		}

		// [2026-07-15 Johnny Chu] PHASE-TWIN-GPT-CP TG-0 — migrate only known legacy managed titles.
		$legacy_titles = array( 'Twin AI', 'TwinWeb', 'Twin Web' );
		if ( in_array( (string) $post->post_title, $legacy_titles, true ) ) {
			wp_update_post( array(
				'ID'         => (int) $page_id,
				'post_title' => 'Twin GPT',
			) );
		}

		$current_slug = sanitize_title( (string) $post->post_name );
		if ( $current_slug === $target_slug ) {
			return;
		}

		// [2026-07-15 Johnny Chu] PHASE-TWINWEB — migrate only known legacy slug.
		// Keep admin-customized slug untouched.
		if ( $current_slug !== 'twin-ai' ) {
			return;
		}

		$conflict = get_page_by_path( $target_slug, OBJECT, 'page' );
		if ( $conflict && (int) $conflict->ID !== (int) $page_id ) {
			return;
		}

		wp_update_post( array(
			'ID'        => (int) $page_id,
			'post_name' => $target_slug,
		) );
	}

	// [2026-07-31 Johnny Chu] HOTFIX — keep the single canonical project-column repair above; remove the legacy duplicate method that caused production method redeclare fatals.
}

// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — file-scope R-CR registration, before any installer execution.
BizCity_TwinWeb_Installer::register_schema();

// [2026-07-20 Johnny Chu] PHASE-TWIN-GPT-AGENT-TOOLS — expose TwinWeb schema to Site Provisioner repair flow.
add_filter( 'bizcity_register_installers', function ( $list ) {
	$list = is_array( $list ) ? $list : array();
	$list[] = array(
		'id'           => 'twinweb',
		'label'        => 'Twin GPT public frontend (threads/artifact jobs)',
		'callback'     => array( 'BizCity_TwinWeb_Installer', 'maybe_install' ),
		'version_opt'  => BizCity_TwinWeb_Installer::VERSION_OPTION,
		'expected_ver' => BizCity_TwinWeb_Installer::VERSION,
	);
	return $list;
}, 10, 1 );
