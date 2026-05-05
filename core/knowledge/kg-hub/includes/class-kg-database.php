<?php
/**
 * Bizcity Twin AI — KG-Hub Database Manager
 *
 * Owns the 8 graph tables (notebooks, notebook_sources, passages, entities,
 * relations, passage_entities, passage_relations, triplet_queue).
 *
 * Multisite-shard native: all tables use $wpdb->prefix → per-blog.
 * Clone a site = clone an empty brain.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge\KG_Hub
 * @author     Johnny Chu (Chu Hoàng Anh)
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @since      2026-04-25
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_KG_Database {

	// Bumped in Phase 0.6 to add: kg_sources, kg_mentions, kg_xref, notebook uuid, memory kg_source_uuid.
	// Bumped 0.6.5.1 — fix idempotency: replaced INFORMATION_SCHEMA checks (replica-lag unsafe)
	//                   with suppress+ignore-error ALTER so all blogs re-migrate cleanly.
	// Bumped 0.6.5.2 — fix kg_source_chunks uuid backfill: guard with SHOW COLUMNS (master-safe)
	//                   + explicit ALTER for every chunk column after dbDelta (replica DESCRIBE lag).
	// Bumped 0.6.5.3 — backfill list was missing chunk_index/content/embedding/origin/metadata,
	//                   causing 'Unknown column chunk_index' INSERT failure on blogs renamed
	//                   from kg_passages. All chunk columns now ALTER-ensured.
	// Bumped 0.6.6   — Wave 7 (PHASE-6.1 §8.2): add `studio_id` column on kg_sources to
	//                   federate ownership of sources to a Studio doc (bzdoc) without
	//                   re-cloning into per-plugin tables. Single BIGINT (latest doc wins);
	//                   canonical scope lookup remains scope_type/scope_id/project_id.
	// Bumped 0.6.7   — Vòng 4.5.5e (Rule 8g v2 — 2026-05-02): retire per-source federation
	//                   stamp; add `kg_notebooks.artifacts_json` LONGTEXT JSON map for
	//                   universal artifact ↔ notebook binding across plugins.
	// Bumped 0.6.8   — Vòng 4.5.5e (Rule 8g v2 — 2026-05-02 hotfix): backfill
	//                   `kg_sources.scope_type='notebook' + scope_id=<nb>` from legacy
	//                   `project_id IN ('tc_<nb>','<nb>')` so resolve_sources() can
	//                   drop the v1 OR-clause without losing pre-existing rows.
	const SCHEMA_VERSION = '0.6.8';
	const OPTION_VERSION = 'bizcity_kg_db_version';

	private static $instance        = null;
	/** Per-blog migration cache — multisite cron walks many blogs in one request. */
	private static $migrated_blogs  = [];

	/**
	 * Singleton accessor — auto runs migration on every call to be multisite-safe
	 * (cheap thanks to per-blog static cache below).
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		self::maybe_create_tables();
		return self::$instance;
	}

	/**
	 * Bug fix 2026-04-28 — multisite: migration was previously gated by a single
	 * `$tables_created` flag, so when cron called `switch_to_blog( 11 )` after
	 * already touching blog 1, the v0.6.5 ALTERs never ran on blog 11 → INSERTs
	 * failed with "Unknown column 'project_id' / 'content_hash'". We now key the
	 * cache by current blog id and re-check the per-blog `bizcity_kg_db_version`
	 * option after every switch.
	 */
	public static function maybe_create_tables() {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( isset( self::$migrated_blogs[ $blog_id ] ) ) {
			return;
		}
		self::$migrated_blogs[ $blog_id ] = true;

		$current = get_option( self::OPTION_VERSION, '' );
		if ( $current !== self::SCHEMA_VERSION ) {
			( new self() )->create_tables();
			update_option( self::OPTION_VERSION, self::SCHEMA_VERSION, false );
		}
	}

	// ─── Table name helpers (per-blog via $wpdb->prefix) ───────────────────

	public function tbl_notebooks()           { global $wpdb; return $wpdb->prefix . 'bizcity_kg_notebooks'; }
	public function tbl_notebook_sources()    { global $wpdb; return $wpdb->prefix . 'bizcity_kg_notebook_sources'; }
	public function tbl_passages()            { global $wpdb; return $wpdb->prefix . 'bizcity_kg_passages'; }
	public function tbl_entities()            { global $wpdb; return $wpdb->prefix . 'bizcity_kg_entities'; }
	public function tbl_relations()           { global $wpdb; return $wpdb->prefix . 'bizcity_kg_relations'; }
	public function tbl_passage_entities()    { global $wpdb; return $wpdb->prefix . 'bizcity_kg_passage_entities'; }
	public function tbl_passage_relations()   { global $wpdb; return $wpdb->prefix . 'bizcity_kg_passage_relations'; }
	public function tbl_triplet_queue()       { global $wpdb; return $wpdb->prefix . 'bizcity_kg_triplet_queue'; }
	public function tbl_provenance()          { global $wpdb; return $wpdb->prefix . 'bizcity_kg_provenance'; }
	public function tbl_scope_links()         { global $wpdb; return $wpdb->prefix . 'bizcity_kg_scope_links'; }
	// Phase 0.6 — new central tables.
	public function tbl_sources()             { global $wpdb; return $wpdb->prefix . 'bizcity_kg_sources'; }
	public function tbl_mentions()            { global $wpdb; return $wpdb->prefix . 'bizcity_kg_mentions'; }
	public function tbl_xref()                { global $wpdb; return $wpdb->prefix . 'bizcity_kg_xref'; }
	// Phase 0.6.5 — unified source chunks table (replaces kg_passages, kept as VIEW alias for 1 month).
	public function tbl_source_chunks()       { global $wpdb; return $wpdb->prefix . 'bizcity_kg_source_chunks'; }

	/**
	 * Create / upgrade all KG tables.
	 */
	public function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$cs = $wpdb->get_charset_collate();

		// 1. Notebooks
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_notebooks()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			description TEXT,
			character_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'Optional link to bizcity_characters',
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			color VARCHAR(20) DEFAULT '',
			settings TEXT COMMENT 'JSON: auto_extract, review_required, …',
			stats TEXT COMMENT 'JSON cached counts',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY owner_id (owner_id),
			KEY character_id (character_id)
		) {$cs};" );

		// 2. Notebook ↔ Source M-N
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_notebook_sources()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL COMMENT 'FK → bizcity_knowledge_sources',
			added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY notebook_source (notebook_id, source_id),
			KEY source_id (source_id)
		) {$cs};" );

		// 3. Passages — promoted chunks / notes / chat snippets
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_passages()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			source_id BIGINT UNSIGNED DEFAULT NULL,
			chunk_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → bizcity_knowledge_chunks if promoted',
			origin VARCHAR(100) NOT NULL DEFAULT 'source' COMMENT 'source|note|chat|manual|file:name|url:domain',
			content TEXT NOT NULL,
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			embedding LONGTEXT COMMENT 'JSON float[] — text-embedding-3-small (1536)',
			token_count INT UNSIGNED DEFAULT 0,
			extraction_status ENUM('pending','processing','done','error','skipped') DEFAULT 'pending',
			extraction_error TEXT,
			metadata TEXT COMMENT 'JSON',
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY notebook_id (notebook_id),
			KEY source_id (source_id),
			KEY chunk_id (chunk_id),
			KEY content_hash (content_hash),
			KEY extraction_status (extraction_status)
		) {$cs};" );

		// 4. Entities — graph nodes
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_entities()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			name_normalized VARCHAR(255) NOT NULL COMMENT 'lower+trim, dedup key',
			type VARCHAR(50) NOT NULL DEFAULT 'Other' COMMENT 'Person|Product|Concept|Place|Org|Event|Other',
			description TEXT,
			aliases TEXT COMMENT 'JSON array',
			embedding LONGTEXT COMMENT 'JSON float[]',
			weight INT UNSIGNED DEFAULT 1 COMMENT 'occurrence count',
			status ENUM('pending','approved','rejected') DEFAULT 'approved',
			approved_by BIGINT UNSIGNED DEFAULT NULL,
			metadata TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY notebook_name (notebook_id, name_normalized),
			KEY notebook_id (notebook_id),
			KEY type (type),
			KEY status (status)
		) {$cs};" );

		// 5. Relations — graph edges
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_relations()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			head_entity_id BIGINT UNSIGNED NOT NULL,
			tail_entity_id BIGINT UNSIGNED NOT NULL,
			predicate VARCHAR(255) NOT NULL,
			predicate_normalized VARCHAR(255) NOT NULL,
			relation_text TEXT COMMENT 'subject + predicate + object — used for embedding',
			embedding LONGTEXT,
			weight INT UNSIGNED DEFAULT 1,
			confidence DECIMAL(3,2) DEFAULT 1.00,
			status ENUM('pending','approved','rejected') DEFAULT 'approved',
			approved_by BIGINT UNSIGNED DEFAULT NULL,
			metadata TEXT,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY triplet (notebook_id, head_entity_id, predicate_normalized(100), tail_entity_id),
			KEY notebook_id (notebook_id),
			KEY head_entity_id (head_entity_id),
			KEY tail_entity_id (tail_entity_id),
			KEY status (status)
		) {$cs};" );

		// 6. Passage ↔ Entity provenance
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_passage_entities()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id BIGINT UNSIGNED NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			extracted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY passage_entity (passage_id, entity_id),
			KEY entity_id (entity_id)
		) {$cs};" );

		// 7. Passage ↔ Relation provenance
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_passage_relations()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id BIGINT UNSIGNED NOT NULL,
			relation_id BIGINT UNSIGNED NOT NULL,
			extracted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY passage_relation (passage_id, relation_id),
			KEY relation_id (relation_id)
		) {$cs};" );

		// 8. Triplet review queue
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_triplet_queue()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			passage_id BIGINT UNSIGNED NOT NULL,
			subject VARCHAR(255) NOT NULL,
			predicate VARCHAR(255) NOT NULL,
			object VARCHAR(255) NOT NULL,
			subject_type VARCHAR(50) DEFAULT 'Other',
			object_type VARCHAR(50) DEFAULT 'Other',
			confidence DECIMAL(3,2) DEFAULT 0.50,
			raw_llm_output TEXT,
			status ENUM('pending','approved','rejected','merged') DEFAULT 'pending',
			reviewed_by BIGINT UNSIGNED DEFAULT NULL,
			reviewed_at DATETIME DEFAULT NULL,
			applied_relation_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY notebook_id (notebook_id),
			KEY passage_id (passage_id),
			KEY status (status)
		) {$cs};" );

		// 9. Provenance — Phase 0.5 — links a kg_passage to its origin row in any module table.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_provenance()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id BIGINT UNSIGNED NOT NULL,
			origin_table VARCHAR(64) NOT NULL,
			origin_id BIGINT UNSIGNED NOT NULL,
			origin_type VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'studio_research, chat_message, note, ...',
			extractor VARCHAR(40) NOT NULL DEFAULT 'llm_v1',
			confidence DECIMAL(3,2) DEFAULT 0.50,
			user_verified TINYINT(1) NOT NULL DEFAULT 0,
			user_corrected TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY origin_unique (origin_table, origin_id),
			KEY passage_id (passage_id),
			KEY origin_lookup (origin_table, origin_type)
		) {$cs};" );

		// 10. Phase 0.5 — feedback / decay columns on entities + relations.
		$this->ensure_phase_05_columns();

		// 11. Phase 0.5 Sprint 4.5a — cross-scope reference table.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_scope_links()} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scope_type VARCHAR(32) NOT NULL,
			scope_id   VARCHAR(64) NOT NULL,
			ref_type   VARCHAR(20) NOT NULL COMMENT 'entity|relation|passage',
			ref_id     BIGINT UNSIGNED NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_ref (scope_type, scope_id, ref_type, ref_id),
			KEY ref_lookup (ref_type, ref_id)
		) {$cs};" );

		// 12. Phase 0.5 — usage log (Cost Guard).
		if ( class_exists( 'BizCity_KG_Cost_Guard' ) ) {
			BizCity_KG_Cost_Guard::instance()->ensure_table();
		}

		// 13. Phase 0.6 — unified source layer: kg_sources, kg_mentions, kg_xref.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_sources()} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid            CHAR(36) NOT NULL,
			blog_id         BIGINT UNSIGNED NOT NULL DEFAULT 1,
			origin_plugin   VARCHAR(64) NOT NULL,
			origin_kind     VARCHAR(32) NOT NULL,
			origin_id       BIGINT UNSIGNED DEFAULT NULL,
			title           VARCHAR(512) DEFAULT NULL,
			origin_url      TEXT DEFAULT NULL,
			content_text    LONGTEXT DEFAULT NULL,
			status          VARCHAR(20) NOT NULL DEFAULT 'active',
			scope_type      VARCHAR(32) NOT NULL DEFAULT 'notebook',
			scope_id        VARCHAR(64) NOT NULL DEFAULT '',
			user_id         BIGINT UNSIGNED DEFAULT NULL,
			passage_count   INT UNSIGNED DEFAULT 0,
			embed_model     VARCHAR(128) DEFAULT NULL,
			created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_uuid (uuid),
			KEY idx_scope (scope_type, scope_id),
			KEY idx_blog_origin (blog_id, origin_plugin),
			KEY idx_status (status)
		) {$cs};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_mentions()} (
			id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id BIGINT UNSIGNED NOT NULL,
			entity_id  BIGINT UNSIGNED NOT NULL,
			span_start INT UNSIGNED DEFAULT NULL,
			span_end   INT UNSIGNED DEFAULT NULL,
			span_text  VARCHAR(255) DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_passage (passage_id),
			KEY idx_entity (entity_id)
		) {$cs};" );

		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_xref()} (
			id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cortex        VARCHAR(32) NOT NULL,
			cortex_table  VARCHAR(128) NOT NULL,
			cortex_ref_id BIGINT UNSIGNED NOT NULL,
			kg_ref_type   VARCHAR(20) NOT NULL,
			kg_ref_id     BIGINT UNSIGNED NOT NULL,
			relation      VARCHAR(64) NOT NULL DEFAULT 'mentions',
			meta          TEXT DEFAULT NULL COMMENT 'JSON',
			created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_cortex (cortex, cortex_table, cortex_ref_id),
			KEY idx_kg_ref (kg_ref_type, kg_ref_id),
			KEY idx_relation (relation)
		) {$cs};" );

		// 14. Phase 0.6 — alter existing tables (idempotent).
		$this->ensure_phase_06_columns();

		// 15. Phase 0.6.5 — Wave A: unified sources schema (idempotent).
		$this->migrate_v065_unified_sources();
	}

	/**
	 * Phase 0.5 — Add edit/feedback/decay columns if missing.
	 * Idempotent — safe to call on every migration.
	 */
	private function ensure_phase_05_columns() {
		global $wpdb;
		$add = function( $table, $column, $ddl ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate column' ) ) {
				error_log( "[KG DB] ALTER {$table} ADD COLUMN {$column}: {$err}" );
			}
		};

		foreach ( [ $this->tbl_entities(), $this->tbl_relations() ] as $t ) {
			$add( $t, 'deleted_at',       "deleted_at DATETIME DEFAULT NULL" );
			$add( $t, 'user_verified',    "user_verified TINYINT(1) NOT NULL DEFAULT 0" );
			$add( $t, 'user_corrected',   "user_corrected TINYINT(1) NOT NULL DEFAULT 0" );
			$add( $t, 'last_retrieved_at',"last_retrieved_at DATETIME DEFAULT NULL" );
			$add( $t, 'decay_score',      "decay_score DECIMAL(3,2) NOT NULL DEFAULT 1.00" );
			// Bug fix 2026-04-27 — legacy rows where ALTER TABLE under SQL strict-mode
			// inserted '0000-00-00 00:00:00' instead of NULL caused `deleted_at IS NULL`
			// filters to hide ALL approved entities. Backfill once.
			$wpdb->query( "UPDATE {$t} SET deleted_at = NULL WHERE deleted_at = '0000-00-00 00:00:00'" );
		}

		// v0.5.1 — widen origin column from VARCHAR(20) to VARCHAR(100) on existing tables.
		$passages_tbl = $this->tbl_passages();
		{
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$passages_tbl}` MODIFY COLUMN origin VARCHAR(100) NOT NULL DEFAULT 'source'" );
			$wpdb->suppress_errors( $prev );
		}

		// v0.5.2 — Sprint 4.5a — multi-scope columns + source_table column.
		// Guard: after migrate_v065_unified_sources() runs on a previous boot,
		// tbl_passages() becomes a VIEW alias for kg_source_chunks. ALTER TABLE on
		// a VIEW fails with 'is not of type BASE TABLE'. Skip passages DDL when it
		// is already a VIEW — the columns exist on the underlying kg_source_chunks.
		$passages_is_base = ( $wpdb->get_var(
			"SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$this->tbl_passages()}' LIMIT 1"
		) === 'BASE TABLE' );
		if ( $passages_is_base ) {
			$add( $this->tbl_passages(), 'scope_type',   "scope_type VARCHAR(32) NOT NULL DEFAULT 'notebook'" );
			$add( $this->tbl_passages(), 'scope_id',     "scope_id VARCHAR(64) NOT NULL DEFAULT '0'" );
			$add( $this->tbl_passages(), 'source_table', "source_table VARCHAR(64) NOT NULL DEFAULT ''" );
		}
		$add( $this->tbl_entities(),  'scope_type',   "scope_type VARCHAR(32) NOT NULL DEFAULT 'notebook'" );
		$add( $this->tbl_entities(),  'scope_id',     "scope_id VARCHAR(64) NOT NULL DEFAULT '0'" );
		$add( $this->tbl_relations(), 'scope_type',   "scope_type VARCHAR(32) NOT NULL DEFAULT 'notebook'" );
		$add( $this->tbl_relations(), 'scope_id',     "scope_id VARCHAR(64) NOT NULL DEFAULT '0'" );

		// Index for scope lookups (idempotent).
		$add_index = function( $table, $name, $cols ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$name} ({$cols})" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate key name' ) ) {
				error_log( "[KG DB] ALTER {$table} ADD KEY {$name}: {$err}" );
			}
		};
		if ( $passages_is_base ) {
			$add_index( $this->tbl_passages(), 'idx_scope', 'scope_type, scope_id' );
		}
		$add_index( $this->tbl_entities(),  'idx_scope', 'scope_type, scope_id' );
		$add_index( $this->tbl_relations(), 'idx_scope', 'scope_type, scope_id' );

		// Backfill: rows whose scope_id is still '0' get scope_type='notebook' + scope_id=notebook_id.
		$wpdb->query( "UPDATE {$this->tbl_passages()}  SET scope_type='notebook', scope_id = CAST(notebook_id AS CHAR) WHERE scope_id='0' AND notebook_id > 0" );
		$wpdb->query( "UPDATE {$this->tbl_entities()}  SET scope_type='notebook', scope_id = CAST(notebook_id AS CHAR) WHERE scope_id='0' AND notebook_id > 0" );
		$wpdb->query( "UPDATE {$this->tbl_relations()} SET scope_type='notebook', scope_id = CAST(notebook_id AS CHAR) WHERE scope_id='0' AND notebook_id > 0" );
	}

	/**
	 * Phase 0.6 — Add new columns to existing tables if missing.
	 * Idempotent — safe to call on every migration.
	 */
	private function ensure_phase_06_columns() {
		global $wpdb;

		$add = function( $table, $column, $ddl ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate column' ) ) {
				error_log( "[KG DB] ALTER {$table} ADD COLUMN {$column}: {$err}" );
			}
		};

		// kg_notebooks — stable UUID for cross-plugin references.
		$add( $this->tbl_notebooks(), 'uuid', "uuid CHAR(36) NULL AFTER id" );

		// Vòng 4.5.5e (Rule 8g v2) — Universal artifact federation map.
		// Replaces per-source `studio_id` + `plugin_name` columns on kg_sources.
		// Shape: { "bizcity-doc": [ {id, title, edit_url, created_at}, … ],
		//          "bizcity-tool-image": [ … ], … }
		// Why JSON on notebook (not per-source row): one notebook's source set is
		// shared across many artifacts spanning many plugins. Marrying a source
		// row to a single (studio_id, plugin_name) pair is a category error.
		$add( $this->tbl_notebooks(), 'artifacts_json',
			"artifacts_json LONGTEXT NULL COMMENT 'JSON map: plugin_name → [{id,title,edit_url,created_at}]'" );

		// Ensure UNIQUE key on uuid (safe even if column already existed without it).
		{
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$this->tbl_notebooks()}` ADD UNIQUE KEY uq_notebook_uuid (uuid)" );
			$wpdb->suppress_errors( $prev );
		}

		// Backfill UUID v4 for notebooks that have none yet.
		$notebooks_without_uuid = $wpdb->get_col(
			"SELECT id FROM {$this->tbl_notebooks()} WHERE uuid IS NULL LIMIT 500"
		);
		foreach ( $notebooks_without_uuid as $nb_id ) {
			$wpdb->update(
				$this->tbl_notebooks(),
				[ 'uuid' => wp_generate_uuid4() ],
				[ 'id' => (int) $nb_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}

		// Memory tables — kg_source_uuid for promote pathway (memory → KG entities).
		$memory_tables = [
			$wpdb->prefix . 'bizcity_memory_rolling',
			$wpdb->prefix . 'bizcity_memory_episodic',
			$wpdb->prefix . 'bizcity_memory_notes',
			$wpdb->prefix . 'bizcity_memory_research',
		];
		foreach ( $memory_tables as $mt ) {
			// SHOW TABLES is a write-safe DDL check that doesn't go via INFORMATION_SCHEMA.
			$tbl_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mt ) ) === $mt );
			if ( $tbl_exists ) {
				$add( $mt, 'kg_source_uuid', "kg_source_uuid CHAR(36) DEFAULT NULL" );
			}
		}

		// Vòng 4.5.5e (Rule 8g v2 — 2026-05-02) — backfill scope_type/scope_id on
		// legacy twinchat sources. v1 stamped only `plugin_name='twinchat'` +
		// `project_id IN ('tc_<nb>','<nb>')`. v2 federation key is
		// `(scope_type='notebook', scope_id=<nb>)` ONLY. Without this backfill,
		// removing the v1 OR-clause from resolve_sources() would silently hide
		// every pre-existing notebook source. Idempotent — only updates rows
		// whose scope is still empty.
		$sources_tbl = $this->tbl_sources();
		$cols_ok     = $wpdb->get_var( "SHOW COLUMNS FROM `{$sources_tbl}` LIKE 'project_id'" )
		            && $wpdb->get_var( "SHOW COLUMNS FROM `{$sources_tbl}` LIKE 'scope_type'" );
		if ( $cols_ok ) {
			// Form A: project_id = 'tc_<digits>'  →  scope_id = <digits>
			// Note: use string literal '0' not integer 0 — comparing VARCHAR scope_id
			// to integer 0 causes MySQL strict-mode to coerce all rows to DECIMAL,
			// generating "Truncated incorrect DECIMAL value" for non-numeric scope_ids.
			$wpdb->query(
				"UPDATE `{$sources_tbl}`
				 SET    scope_type = 'notebook',
				        scope_id   = CAST(SUBSTRING(project_id, 4) AS UNSIGNED)
				 WHERE  (scope_type IS NULL OR scope_type = '' OR scope_id IS NULL OR scope_id = '0')
				   AND  project_id REGEXP '^tc_[0-9]+$'"
			);
			// Form B: project_id is purely numeric → it IS the notebook id.
			$wpdb->query(
				"UPDATE `{$sources_tbl}`
				 SET    scope_type = 'notebook',
				        scope_id   = CAST(project_id AS UNSIGNED)
				 WHERE  (scope_type IS NULL OR scope_type = '' OR scope_id IS NULL OR scope_id = '0')
				   AND  project_id REGEXP '^[0-9]+$'"
			);
		}
	}

	/**
	 * Phase 0.6.5 — Wave A: Unified sources schema migration.
	 *
	 * Idempotent. Performs:
	 *   1. ALTER kg_sources — add unified columns (project_id, plugin_name, content_hash, ...)
	 *   2. CREATE kg_source_chunks — single chunk truth-table (replaces kg_passages).
	 *   3. RENAME kg_passages → kg_source_chunks (if old data exists) + create VIEW alias for 1-month compat.
	 *
	 * @since 2026-04-27
	 */
	private function migrate_v065_unified_sources() {
		global $wpdb;

		$cs           = $wpdb->get_charset_collate();
		$sources_tbl  = $this->tbl_sources();
		$chunks_tbl   = $this->tbl_source_chunks();
		$passages_tbl = $this->tbl_passages(); // physical name = bizcity_kg_passages

		// ── A1. ALTER kg_sources — add unified columns (idempotent). ──────
		//
		// Bug fix 2026-04-28: INFORMATION_SCHEMA.COLUMNS/STATISTICS queries are
		// routed to read replica via BizCity_WPDB_Router. On a busy multisite cron
		// that walks 500 blogs, replica lag means the check can return stale results
		// in BOTH directions:
		//   - False-positive (column seen on replica but not yet on master) → ALTER
		//     silently skipped → INSERT later fails with 'Unknown column'.
		//   - False-negative (column added on master but replica lags) → ALTER
		//     re-attempted → MySQL 1060 'Duplicate column name' spam in error log.
		// Fix: skip pre-check, just fire ALTER TABLE + suppress/ignore error 1060/1061.
		$add_col = function( $table, $column, $ddl ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			// 1060 = Duplicate column name — column already exists, this is fine.
			if ( $err && false === strpos( $err, 'Duplicate column' ) ) {
				error_log( "[KG DB] ALTER {$table} ADD COLUMN {$column}: {$err}" );
			}
		};
		$add_index = function( $table, $name, $cols ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$name} ({$cols})" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			// 1061 = Duplicate key name — index already exists, this is fine.
			if ( $err && false === strpos( $err, 'Duplicate key name' ) ) {
				error_log( "[KG DB] ALTER {$table} ADD KEY {$name}: {$err}" );
			}
		};

		// 7 new columns.
		$add_col( $sources_tbl, 'project_id',    "project_id VARCHAR(250) NOT NULL DEFAULT '' AFTER blog_id" );
		$add_col( $sources_tbl, 'plugin_name',   "plugin_name VARCHAR(64) NOT NULL DEFAULT '' AFTER project_id" );
		$add_col( $sources_tbl, 'origin_table',  "origin_table VARCHAR(128) DEFAULT NULL AFTER origin_id" );
		$add_col( $sources_tbl, 'content_hash',  "content_hash CHAR(64) DEFAULT NULL AFTER content_text" );
		$add_col( $sources_tbl, 'embed_status',  "embed_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER embed_model" );
		$add_col( $sources_tbl, 'attachment_id', "attachment_id BIGINT UNSIGNED DEFAULT 0 AFTER embed_status" );
		$add_col( $sources_tbl, 'meta',          "meta LONGTEXT DEFAULT NULL AFTER attachment_id" );
		// Phase 0.6.6 — Wave 7 (PHASE-6.1 §8.2): federation key for Studio docs (bzdoc).
		// Stamps the most-recent bzdoc_documents.id that consumed this source via
		// BZDoc_Notebook_Bridge::generate_from_skeleton(). NOT a unique key — same
		// source may be referenced by multiple docs; canonical lookup stays via
		// (plugin_name, project_id) or (scope_type, scope_id).
		$add_col( $sources_tbl, 'studio_id',     "studio_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER attachment_id" );
		$add_index( $sources_tbl, 'idx_studio',  'studio_id' );

		// Extend scope_id VARCHAR(64) → VARCHAR(250) for UUID + namespace prefixes (doc_, agent_).
		// Suppress errors — MODIFY is safe to re-run and idempotent on MariaDB/MySQL.
		{
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$sources_tbl}` MODIFY COLUMN scope_id VARCHAR(250) NOT NULL DEFAULT ''" );
			$err = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err ) {
				error_log( "[KG DB] ALTER {$sources_tbl} MODIFY scope_id: {$err}" );
			}
		}

		// Indexes — note prefix lengths to stay under utf8mb4 1000-byte key limit.
		$add_index( $sources_tbl, 'idx_project',      'plugin_name(32), project_id(191)' );
		$add_index( $sources_tbl, 'idx_blog_user',    'blog_id, user_id' );
		$add_index( $sources_tbl, 'idx_hash',         'content_hash' );
		$add_index( $sources_tbl, 'idx_embed_status', 'embed_status' );

		// ── A2. CREATE kg_source_chunks (replaces kg_passages). ───────────
		// Use SHOW TABLES LIKE — safe on replica/master because it checks local
		// metadata server, not INFORMATION_SCHEMA which can lag.
		$passages_is_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $passages_tbl ) ) === $passages_tbl );
		// Also confirm it is a BASE TABLE, not an existing VIEW.
		if ( $passages_is_table ) {
			$ttype = $wpdb->get_var( "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$passages_tbl}' LIMIT 1" );
			if ( $ttype !== 'BASE TABLE' ) {
				$passages_is_table = false;
			}
		}
		$chunks_is_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $chunks_tbl ) ) === $chunks_tbl );

		if ( $passages_is_table && ! $chunks_is_table ) {
			// Rename old kg_passages → kg_source_chunks. Preserves all data, FKs, embeddings.
			$wpdb->query( "RENAME TABLE `{$passages_tbl}` TO `{$chunks_tbl}`" );
			$chunks_is_table = true;
		}

		// dbDelta on canonical schema — creates table or fills missing cols if renamed.
		// NOTE: dbDelta internally uses DESCRIBE which can lag on replica. We follow up
		// with explicit suppress+ignore ALTERs for the most critical columns.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$chunks_tbl} (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid              CHAR(36) DEFAULT NULL,
			source_id         BIGINT UNSIGNED NOT NULL,
			blog_id           BIGINT UNSIGNED NOT NULL DEFAULT 1,
			project_id        VARCHAR(250) NOT NULL DEFAULT '',
			plugin_name       VARCHAR(64) NOT NULL DEFAULT '',
			user_id           BIGINT UNSIGNED DEFAULT NULL,
			notebook_id       BIGINT UNSIGNED DEFAULT NULL,
			chunk_index       INT UNSIGNED NOT NULL DEFAULT 0,
			content           LONGTEXT,
			content_hash      CHAR(64) DEFAULT NULL,
			token_count       INT UNSIGNED DEFAULT 0,
			embedding         LONGTEXT DEFAULT NULL,
			embed_model       VARCHAR(128) DEFAULT NULL,
			embed_status      VARCHAR(20) NOT NULL DEFAULT 'pending',
			origin            VARCHAR(100) NOT NULL DEFAULT 'source',
			extraction_status VARCHAR(32) NOT NULL DEFAULT 'pending',
			extraction_error  TEXT DEFAULT NULL,
			scope_type        VARCHAR(32) NOT NULL DEFAULT 'notebook',
			scope_id          VARCHAR(250) NOT NULL DEFAULT '',
			source_table      VARCHAR(64) NOT NULL DEFAULT '',
			meta              LONGTEXT DEFAULT NULL,
			metadata          TEXT DEFAULT NULL,
			created_at        DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at        DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_uuid (uuid),
			KEY idx_source (source_id),
			KEY idx_notebook (notebook_id),
			KEY idx_blog_project (blog_id, plugin_name(32), project_id(191)),
			KEY idx_hash (content_hash),
			KEY idx_embed_status (embed_status),
			KEY idx_scope (scope_type, scope_id(64))
		) {$cs};" );

		// Explicit ALTER for every column dbDelta may have missed due to replica DESCRIBE lag.
		// suppress_errors: 1060 = Duplicate column name → already exists → fine.
		// 2026-04-28 fix: added chunk_index, content, embedding, origin, metadata —
		//   on multisite blogs renamed from kg_passages, dbDelta failed to add these
		//   structural columns silently, which broke all subsequent INSERTs.
		foreach ( [
			'uuid'              => "uuid CHAR(36) DEFAULT NULL",
			'blog_id'           => "blog_id BIGINT UNSIGNED NOT NULL DEFAULT 1",
			'project_id'        => "project_id VARCHAR(250) NOT NULL DEFAULT ''",
			'plugin_name'       => "plugin_name VARCHAR(64) NOT NULL DEFAULT ''",
			'user_id'           => "user_id BIGINT UNSIGNED DEFAULT NULL",
			'notebook_id'       => "notebook_id BIGINT UNSIGNED DEFAULT NULL",
			'chunk_index'       => "chunk_index INT UNSIGNED NOT NULL DEFAULT 0",
			'content'           => "content LONGTEXT",
			'content_hash'      => "content_hash CHAR(64) DEFAULT NULL",
			'token_count'       => "token_count INT UNSIGNED DEFAULT 0",
			'embedding'         => "embedding LONGTEXT DEFAULT NULL",
			'embed_model'       => "embed_model VARCHAR(128) DEFAULT NULL",
			'embed_status'      => "embed_status VARCHAR(20) NOT NULL DEFAULT 'pending'",
			'origin'            => "origin VARCHAR(100) NOT NULL DEFAULT 'source'",
			'extraction_status' => "extraction_status VARCHAR(32) NOT NULL DEFAULT 'pending'",
			'extraction_error'  => "extraction_error TEXT DEFAULT NULL",
			'scope_type'        => "scope_type VARCHAR(32) NOT NULL DEFAULT 'notebook'",
			'scope_id'          => "scope_id VARCHAR(250) NOT NULL DEFAULT ''",
			'source_table'      => "source_table VARCHAR(64) NOT NULL DEFAULT ''",
			'meta'              => "meta LONGTEXT DEFAULT NULL",
			'metadata'          => "metadata TEXT DEFAULT NULL",
		] as $col => $ddl ) {
			$add_col( $chunks_tbl, $col, $ddl );
		}
		// Add chunk_index uniqueness for idempotent re-import (matches PHASE-0.6.5 spec).
		$add_index( $chunks_tbl, 'idx_source_chunk', 'source_id, chunk_index' );
		// Indexes.
		$add_index( $chunks_tbl, 'idx_source',       'source_id' );
		$add_index( $chunks_tbl, 'idx_notebook',     'notebook_id' );
		$add_index( $chunks_tbl, 'idx_blog_project', 'blog_id, plugin_name(32), project_id(191)' );
		$add_index( $chunks_tbl, 'idx_hash',         'content_hash' );
		$add_index( $chunks_tbl, 'idx_embed_status', 'embed_status' );
		$add_index( $chunks_tbl, 'idx_scope',        'scope_type, scope_id(64)' );

		// Backfill UUIDs for rows that lack one (e.g. renamed from kg_passages).
		// Guard: only run if column exists (SHOW COLUMNS is master-safe).
		$uuid_col_exists = (bool) $wpdb->get_var( "SHOW COLUMNS FROM `{$chunks_tbl}` LIKE 'uuid'" );
		if ( $uuid_col_exists ) {
			$rows_no_uuid = $wpdb->get_col( "SELECT id FROM `{$chunks_tbl}` WHERE uuid IS NULL LIMIT 500" );
			foreach ( $rows_no_uuid as $row_id ) {
				$wpdb->update( $chunks_tbl, [ 'uuid' => wp_generate_uuid4() ], [ 'id' => (int) $row_id ], [ '%s' ], [ '%d' ] );
			}
		}

		// ── A3. Re-create kg_passages as a VIEW alias (1-month backward-compat). ──
		// Only create the view if the physical kg_passages table no longer exists.
		// SHOW TABLES is master-safe (no INFORMATION_SCHEMA lag).
		$passages_still_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $passages_tbl ) ) === $passages_tbl )
			&& ( $wpdb->get_var( "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$passages_tbl}' LIMIT 1" ) === 'BASE TABLE' );

		if ( ! $passages_still_table ) {
			// Drop existing view (if any) before re-creating, in case schema added/removed columns.
			$wpdb->query( "DROP VIEW IF EXISTS {$passages_tbl}" );
			// VIEW exposes the columns code-base currently selects from kg_passages.
			$wpdb->query(
				"CREATE OR REPLACE VIEW {$passages_tbl} AS
				 SELECT
					id,
					notebook_id,
					source_id,
					uuid AS chunk_id,
					origin,
					content,
					content_hash,
					embedding,
					token_count,
					extraction_status,
					extraction_error,
					metadata,
					scope_type,
					scope_id,
					source_table,
					created_at,
					updated_at
				 FROM {$chunks_tbl}"
			);
		}

		// Record the legacy-drop deadline (1 month from first migration). Wave D cleanup cron reads this.
		if ( ! get_option( 'bizcity_kg_legacy_drop_at', 0 ) ) {
			update_option( 'bizcity_kg_legacy_drop_at', time() + ( 30 * DAY_IN_SECONDS ), false );
		}
	}

	/**
	 * Drop all KG tables — used by uninstall (NOT by deactivation).
	 * Multisite-aware: caller is responsible for switch_to_blog if needed.
	 */
	public function drop_tables() {
		global $wpdb;
		// Phase 0.6.5 — drop the kg_passages VIEW first (if present) before its underlying table.
		$wpdb->query( "DROP VIEW IF EXISTS {$this->tbl_passages()}" );
		$tables = [
			$this->tbl_triplet_queue(),
			$this->tbl_passage_relations(),
			$this->tbl_passage_entities(),
			$this->tbl_relations(),
			$this->tbl_entities(),
			$this->tbl_source_chunks(), // 0.6.5 — replaces kg_passages.
			$this->tbl_passages(),      // safety: legacy physical table if rename never happened.
			$this->tbl_notebook_sources(),
			$this->tbl_notebooks(),
		];
		foreach ( $tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
		}
		delete_option( self::OPTION_VERSION );
	}

	/**
	 * Encode an embedding vector for storage.
	 * @param float[]|null $vector
	 */
	public static function encode_embedding( $vector ) {
		if ( ! is_array( $vector ) || empty( $vector ) ) {
			return null;
		}
		return wp_json_encode( array_map( 'floatval', $vector ) );
	}

	/**
	 * Decode a stored embedding back into a float array.
	 */
	public static function decode_embedding( $stored ) {
		if ( empty( $stored ) ) {
			return null;
		}
		$decoded = json_decode( $stored, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Normalize an entity / predicate name for dedup.
	 */
	public static function normalize_name( $name ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		$name = preg_replace( '/\s+/u', ' ', $name );
		return mb_strtolower( $name, 'UTF-8' );
	}
}
