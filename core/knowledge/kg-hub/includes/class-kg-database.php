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
	// Bumped 0.21.0  — PHASE-0.21 Wave 1: Guru UUID namespace. Adds nullable
	//                   `character_uuid CHAR(36)` to kg_source_chunks, kg_entities,
	//                   kg_relations, kg_sources for instant-attach virtual merge
	//                   (no row duplication when notebook attaches a guru). Plus
	//                   bizcity_notebook_character_attachments table linking
	//                   notebooks ↔ characters by guru_uuid (read-only by default).
	// Bumped 0.22.0  — PHASE-0.3 §4.9 Wave 2 (Identity Algorithm, 2026-05-08):
	//                   adds `id_kind`, `canonical_id`, `identity_source`,
	//                   `identity_score` on kg_entities + new kg_passage_identities
	//                   table to persist regex-extracted IDs (sku/order/customer/…).
	//                   Backward-compatible: columns nullable, retrieval still
	//                   ignores them; tool wrapper + prompt overlay simply prefer
	//                   the persisted canonical_id over on-the-fly regex when
	//                   present. Backfill runs via WP-CLI (see class-kg-identity-backfill.php).
	// Bumped 0.23.0  — PHASE-0-RULE-SKELETON Sprint 0★ (Skeleton-First Rule, 2026-05-11):
	//                   adds 4 columns to kg_notebooks for the per-notebook
	//                   reflected skeleton (skeleton_json LONGTEXT, skeleton_version INT,
	//                   skeleton_built_at DATETIME, skeleton_status VARCHAR(20)).
	//                   Backward-compatible: nullable, retrieval ignores them. The
	//                   skeleton is built async by BZKG_Notebook_Skeleton_Service
	//                   on a debounced cron and consumed via BZKG_Skeleton_Adapter.
	// Bumped 0.24.0  — PHASE-0.7-LEARN-VECTOR-FILE Wave F0 (2026-05-20):
	//                   adds gating + offset columns for rolling content→filestore
	//                   migration on kg_passages/kg_entities/kg_relations.
	//                     - storage_ver TINYINT (1=inline legacy, 2=filestore)
	//                     - file_shard INT, file_offset BIGINT, file_length INT
	//                       (passages only — random-access O(1) into shard .md)
	//                   Backward-compatible: nullable, default storage_ver=1 so
	//                   legacy code continues reading from MySQL columns. Wave F1
	//                   dual-writer flips to 2 only after file flush + sha256 verify.
	const SCHEMA_VERSION = '0.27.0'; // 2026-05-28: bump to match JSON changelog; triggers create_tables() on blogs still at 0.24.0 (creates bizcity_kg_skeleton_history + perspective cols)
	const OPTION_VERSION = 'bizcity_kg_db_version';

	private static $instance        = null;
	/** Per-blog migration cache — multisite cron walks many blogs in one request. */
	private static $migrated_blogs  = [];
	/** Per-request attached-guru cache, keyed by notebook_id. */
	private $attached_guru_cache    = [];

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
	// Phase 0.6.5 — unified source chunks table.
	// HOTFIX 2026-05-06: Phase 0.6.5 RENAME (kg_passages → kg_source_chunks) was rolled back on prod (blog 1258).
	// Canonical truth on disk is `bizcity_kg_passages`. All Wave 3.x code calls tbl_source_chunks() —
	// route it to the same table to avoid "Table doesn't exist" silent zeros.
	// See /memories/repo/bizcity-twin-ai-schema.md.
	public function tbl_source_chunks()       { global $wpdb; return $wpdb->prefix . 'bizcity_kg_passages'; }
	// Phase 0.21 — Guru marketplace virtual-attach map (notebook ↔ character).
	public function tbl_notebook_character_attachments() { global $wpdb; return $wpdb->prefix . 'bizcity_notebook_character_attachments'; }

	// ─── Wave 1.3 helpers — virtual-merge retrieval ────────────────────

	/**
	 * Return guru_uuid list for all gurus attached to a notebook.
	 * Used by build_virtual_merge_where() — cached once per request via static map.
	 *
	 * @param  int      $notebook_id
	 * @return string[]
	 */
	public function get_attached_guru_uuids( $notebook_id ) {
		$notebook_id = (int) $notebook_id;
		if ( ! isset( $this->attached_guru_cache[ $notebook_id ] ) ) {
			global $wpdb;
			$this->attached_guru_cache[ $notebook_id ] = $wpdb->get_col( $wpdb->prepare(
				"SELECT guru_uuid FROM {$this->tbl_notebook_character_attachments()} WHERE notebook_id = %d",
				$notebook_id
			) ) ?: [];
		}
		return $this->attached_guru_cache[ $notebook_id ];
	}

	/**
	 * Bust the per-request attached-guru cache (call after attach/detach writes).
	 *
	 * @param int|null $notebook_id Specific notebook to flush, or null = flush all.
	 */
	public function flush_attached_guru_cache( $notebook_id = null ) {
		if ( null === $notebook_id ) {
			$this->attached_guru_cache = [];
			return;
		}
		unset( $this->attached_guru_cache[ (int) $notebook_id ] );
	}

	// ─── Phase 0.21 Wave 3.1 — attach/detach guru API ───────────────────

	/**
	 * Attach a guru (character) to a notebook.
	 *
	 * @param  int    $notebook_id
	 * @param  string $guru_uuid
	 * @param  array  $args  { source?='self', read_only?=1, attached_by?=current_user, attached_version?='' }
	 * @return array|WP_Error  { id, notebook_id, guru_uuid, source, read_only, attached_at, attached_by, attached_version }
	 */
	public function attach_guru( $notebook_id, $guru_uuid, array $args = [] ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		$guru_uuid   = strtolower( trim( (string) $guru_uuid ) );
		if ( $notebook_id <= 0 ) {
			return new WP_Error( 'kg_attach_bad_notebook', 'notebook_id must be > 0' );
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guru_uuid ) ) {
			return new WP_Error( 'kg_attach_bad_uuid', 'guru_uuid must be a UUIDv4' );
		}
		// Validate notebook exists.
		$nb_exists = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->tbl_notebooks()} WHERE id = %d", $notebook_id
		) );
		if ( ! $nb_exists ) {
			return new WP_Error( 'kg_attach_notebook_missing', sprintf( 'notebook %d not found', $notebook_id ) );
		}
		// Validate guru exists in characters table.
		$char_tbl = $wpdb->prefix . 'bizcity_characters';
		$char_id  = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$char_tbl} WHERE guru_uuid = %s LIMIT 1", $guru_uuid
		) );
		if ( ! $char_id ) {
			return new WP_Error( 'kg_attach_guru_missing', sprintf( 'No character with guru_uuid %s', $guru_uuid ) );
		}
		$source = isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'self';
		if ( ! in_array( $source, [ 'marketplace', 'share_link', 'self', 'imported' ], true ) ) {
			$source = 'self';
		}
		$row = [
			'notebook_id'      => $notebook_id,
			'guru_uuid'        => $guru_uuid,
			'attached_at'      => current_time( 'mysql' ),
			'attached_by'      => isset( $args['attached_by'] ) ? (int) $args['attached_by'] : (int) get_current_user_id(),
			'source'           => $source,
			'read_only'        => isset( $args['read_only'] ) ? (int) (bool) $args['read_only'] : 1,
			'attached_version' => isset( $args['attached_version'] ) ? substr( (string) $args['attached_version'], 0, 20 ) : null,
		];
		$tbl = $this->tbl_notebook_character_attachments();
		// Idempotent — UNIQUE KEY (notebook_id, guru_uuid) prevents dup; on conflict update mutable fields.
		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id FROM {$tbl} WHERE notebook_id = %d AND guru_uuid = %s",
			$notebook_id, $guru_uuid
		), ARRAY_A );
		if ( $existing ) {
			$wpdb->update( $tbl, [
				'source'           => $row['source'],
				'read_only'        => $row['read_only'],
				'attached_version' => $row['attached_version'],
			], [ 'id' => (int) $existing['id'] ] );
			$id = (int) $existing['id'];
		} else {
			$ok = $wpdb->insert( $tbl, $row );
			if ( false === $ok ) {
				return new WP_Error( 'kg_attach_db_error', 'Failed to insert attachment: ' . $wpdb->last_error );
			}
			$id = (int) $wpdb->insert_id;
		}
		$this->flush_attached_guru_cache( $notebook_id );
		return array_merge( [ 'id' => $id ], $row );
	}

	/**
	 * Detach a guru from a notebook.
	 *
	 * @param  int    $notebook_id
	 * @param  string $guru_uuid
	 * @return array|WP_Error  { deleted: int }
	 */
	public function detach_guru( $notebook_id, $guru_uuid ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		$guru_uuid   = strtolower( trim( (string) $guru_uuid ) );
		if ( $notebook_id <= 0 || $guru_uuid === '' ) {
			return new WP_Error( 'kg_detach_bad_args', 'notebook_id + guru_uuid required' );
		}
		$deleted = (int) $wpdb->delete(
			$this->tbl_notebook_character_attachments(),
			[ 'notebook_id' => $notebook_id, 'guru_uuid' => $guru_uuid ]
		);
		$this->flush_attached_guru_cache( $notebook_id );
		return [ 'deleted' => $deleted ];
	}

	/**
	 * List gurus attached to a notebook with character meta (name/slug/version/bin info).
	 *
	 * @param  int $notebook_id
	 * @return array<int, array>
	 */
	public function list_attached_gurus( $notebook_id ) {
		global $wpdb;
		$notebook_id = (int) $notebook_id;
		if ( $notebook_id <= 0 ) return [];
		$tbl  = $this->tbl_notebook_character_attachments();
		$char = $wpdb->prefix . 'bizcity_characters';
		$sql = $wpdb->prepare(
			"SELECT a.id AS attachment_id, a.notebook_id, a.guru_uuid, a.source, a.read_only,
			        a.attached_at, a.attached_by, a.attached_version,
			        c.id AS character_id, c.name, c.slug, c.version, c.visibility,
			        c.bin_path, c.bin_dim, c.bin_count, c.embed_model
			   FROM {$tbl} a
			   LEFT JOIN {$char} c ON c.guru_uuid = a.guru_uuid
			  WHERE a.notebook_id = %d
			  ORDER BY a.attached_at DESC",
			$notebook_id
		);
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Build SQL WHERE fragment implementing virtual-merge:
	 *   in-house notebook rows  +  rows from attached-guru namespaces.
	 *
	 * Per PHASE-0.21 §2.4:
	 *   WHERE (notebook_id = ? AND character_uuid IS NULL)
	 *      OR character_uuid IN (attached_guru_uuids)
	 *
	 * The returned string is already fully escaped (via $wpdb->prepare).
	 * Embed directly inside WHERE (...).
	 *
	 * @param  int    $notebook_id
	 * @param  string $prefix  Optional table-alias with trailing dot, e.g. 'r.'.
	 * @return string
	 */
	public function build_virtual_merge_where( $notebook_id, $prefix = '' ) {
		global $wpdb;
		$nb   = $prefix . 'notebook_id';
		$uuid = $prefix . 'character_uuid';
		$uuids = $this->get_attached_guru_uuids( (int) $notebook_id );
		if ( empty( $uuids ) ) {
			return $wpdb->prepare( "{$nb} = %d AND {$uuid} IS NULL", (int) $notebook_id );
		}
		$ph = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
		return $wpdb->prepare(
			"({$nb} = %d AND {$uuid} IS NULL) OR {$uuid} IN ({$ph})",
			...array_merge( [ (int) $notebook_id ], $uuids )
		);
	}

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

		// 16. Phase 0.21 — Wave 1: Guru UUID namespace + attachments (idempotent).
		$this->migrate_v021_guru_namespace();

		// 17. PHASE-0.3 §4.9 Wave 2 — identity columns + passage_identities (idempotent).
		$this->migrate_v022_identity_columns();

		// 18. PHASE-0-RULE-SKELETON Sprint 0★ — notebook skeleton columns (idempotent).
		$this->migrate_v023_skeleton_columns();

		// 19. PHASE-0.7-LEARN-VECTOR-FILE Wave F0 — filestore gating columns (idempotent).
		$this->migrate_v024_filestore_columns();

		// 20. PHASE-6.6-SKELETON-DOC S3.1 — skeleton history (per-version archive).
		dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}bizcity_kg_skeleton_history (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id BIGINT UNSIGNED NOT NULL,
			version INT UNSIGNED NOT NULL,
			skeleton_json LONGTEXT NOT NULL,
			trigger_reason VARCHAR(32) NOT NULL DEFAULT 'ingest' COMMENT 'ingest|notes_pinned|manual|backfill',
			llm_model VARCHAR(64) DEFAULT NULL,
			token_in INT UNSIGNED DEFAULT NULL,
			token_out INT UNSIGNED DEFAULT NULL,
			cost_cents INT UNSIGNED DEFAULT NULL,
			built_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uk_notebook_version (notebook_id, version),
			KEY idx_notebook_built (notebook_id, built_at)
		) {$cs};" );
	}

	/**
	 * PHASE-0.7-LEARN-VECTOR-FILE Wave F0 — gating columns for rolling
	 * content→filestore migration on kg_passages / kg_entities / kg_relations.
	 *
	 * Adds (all NULLABLE / DEFAULT, additive only — rollback = DROP COLUMN):
	 *
	 *   kg_passages:
	 *     storage_ver TINYINT UNSIGNED DEFAULT 1   (1=inline, 2=filestore)
	 *     file_shard  INT UNSIGNED DEFAULT NULL    (shard idx = floor(id/SHARD_SIZE))
	 *     file_offset BIGINT UNSIGNED DEFAULT NULL (body byte offset inside shard .md)
	 *     file_length INT UNSIGNED DEFAULT NULL    (body byte length)
	 *
	 *   kg_entities:
	 *     storage_ver TINYINT UNSIGNED DEFAULT 1
	 *     jsonl_line  INT UNSIGNED DEFAULT NULL    (0-based line idx in entities.jsonl)
	 *
	 *   kg_relations:
	 *     storage_ver TINYINT UNSIGNED DEFAULT 1
	 *     jsonl_line  INT UNSIGNED DEFAULT NULL    (0-based line idx in relations.jsonl)
	 *
	 * DEFAULT storage_ver=1 is intentional — existing rows stay readable from
	 * MySQL columns until Wave F2 backfill flips them to 2 atomically.
	 *
	 * Idempotent: suppress Duplicate-column errors (multisite shard re-migrate).
	 *
	 * @since 0.24.0 (2026-05-20)
	 * @link  PHASE-0.7-LEARN-VECTOR-FILE.md §1.4 §3.1
	 */
	private function migrate_v024_filestore_columns() {
		global $wpdb;

		$add_col = function ( $table, $column, $ddl ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate column' ) ) {
				error_log( "[KG DB 0.24] ALTER {$table} ADD COLUMN {$column}: {$err}" );
			}
		};
		$add_index = function ( $table, $name, $cols ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$name} ({$cols})" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate key name' ) ) {
				error_log( "[KG DB 0.24] ALTER {$table} ADD KEY {$name}: {$err}" );
			}
		};

		// ── A. Passages — gate + offset map. ─────────────────────────────
		// tbl_passages() is canonically kg_passages (HOTFIX 2026-05-06) — BASE
		// TABLE on most blogs. On blogs where Phase 0.6.5 ran AND was kept (rare),
		// it may be a VIEW; ALTER VIEW fails → suppress handles it.
		$p = $this->tbl_passages();
		$add_col( $p, 'storage_ver',
			"storage_ver TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'P0.7-LVF: 1=inline,2=filestore'" );
		$add_col( $p, 'file_shard',
			"file_shard INT UNSIGNED DEFAULT NULL COMMENT 'P0.7-LVF: passages/{shard}.md index'" );
		$add_col( $p, 'file_offset',
			"file_offset BIGINT UNSIGNED DEFAULT NULL COMMENT 'P0.7-LVF: body byte offset in shard'" );
		$add_col( $p, 'file_length',
			"file_length INT UNSIGNED DEFAULT NULL COMMENT 'P0.7-LVF: body byte length'" );
		// Compound index for the backfill cron: WHERE storage_ver=1 LIMIT 500.
		$add_index( $p, 'idx_storage_ver', 'storage_ver, id' );

		// ── B. Entities — gate + jsonl line index. ───────────────────────
		$e = $this->tbl_entities();
		$add_col( $e, 'storage_ver',
			"storage_ver TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'P0.7-LVF: 1=inline,2=filestore'" );
		$add_col( $e, 'jsonl_line',
			"jsonl_line INT UNSIGNED DEFAULT NULL COMMENT 'P0.7-LVF: 0-based line in entities.jsonl'" );
		$add_index( $e, 'idx_storage_ver', 'storage_ver, id' );

		// ── C. Relations — gate + jsonl line index. ──────────────────────
		$r = $this->tbl_relations();
		$add_col( $r, 'storage_ver',
			"storage_ver TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'P0.7-LVF: 1=inline,2=filestore'" );
		$add_col( $r, 'jsonl_line',
			"jsonl_line INT UNSIGNED DEFAULT NULL COMMENT 'P0.7-LVF: 0-based line in relations.jsonl'" );
		$add_index( $r, 'idx_storage_ver', 'storage_ver, id' );
	}

	/**
	 * PHASE-0-RULE-SKELETON Sprint 0★ — add 4 nullable columns to kg_notebooks
	 * so the reflection pipeline can persist a per-notebook skeleton + version.
	 *
	 * Idempotent (suppress + ignore Duplicate column).
	 *
	 * @since 2026-05-11
	 */
	private function migrate_v023_skeleton_columns() {
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

		$nb = $this->tbl_notebooks();

		// Reflected skeleton JSON — see PHASE-0-RULE-SKELETON §13 for shape.
		$add( $nb, 'skeleton_json',
			"skeleton_json LONGTEXT NULL COMMENT 'Reflected notebook skeleton JSON (nucleus, skeleton[], key_points, entities, meta)'" );

		// Monotonic counter — bumped each successful rebuild. Used by
		// is_artifact_stale() so consumers can detect when their cached artifact
		// was generated against an older skeleton.
		$add( $nb, 'skeleton_version',
			"skeleton_version INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Monotonic skeleton revision counter'" );

		$add( $nb, 'skeleton_built_at',
			"skeleton_built_at DATETIME NULL COMMENT 'When the current skeleton_json was persisted'" );

		// Lifecycle status: pending | building | ready | stale | failed
		$add( $nb, 'skeleton_status',
			"skeleton_status VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'Skeleton lifecycle: pending|building|ready|stale|failed'" );

		// Index for selector dropdown queries ("notebooks with ready skeleton").
		{
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$nb}` ADD KEY idx_nb_skeleton_status (skeleton_status)" );
			$wpdb->suppress_errors( $prev );
		}
	}

	/**
	 * Per-blog table name for the identity cache populated by
	 * BizCity_KG_Identity_Extractor (one row per (passage_id, id_kind, canonical_id)).
	 */
	public function tbl_passage_identities() { global $wpdb; return $wpdb->prefix . 'bizcity_kg_passage_identities'; }

	/**
	 * PHASE-0.3 §4.9 Wave 2 — Identity Algorithm.
	 *
	 * Adds 4 nullable columns to kg_entities so a regex-resolved identity
	 * (e.g. {id_kind:'sku', canonical_id:'FS 369I'}) can be persisted alongside
	 * the existing fuzzy `name_normalized` UNIQUE key, AND creates a per-passage
	 * cache so the tool wrapper does not have to re-run regex on every query.
	 *
	 * Why columns NULLABLE + non-unique key (not generated UNIQUE identity_key):
	 *   - One canonical_id can legitimately appear in multiple entity rows during
	 *     transition (alias entities, mis-extracted variants).
	 *   - Hard UNIQUE would BREAK existing INSERTs from triplet extractor on first
	 *     boot before backfill completes. Nullable = additive, zero-risk.
	 *   - Promotion to UNIQUE is a future, gated migration after backfill is verified.
	 *
	 * Idempotent. Safe to call on every boot.
	 *
	 * @since 0.22.0 (2026-05-08)
	 */
	private function migrate_v022_identity_columns() {
		global $wpdb;
		$cs = $wpdb->get_charset_collate();

		$add_col = function ( $table, $column, $ddl ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate column' ) ) {
				error_log( "[KG DB 0.22] ALTER {$table} ADD COLUMN {$column}: {$err}" );
			}
		};
		$add_index = function ( $table, $name, $cols ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$name} ({$cols})" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate key name' ) ) {
				error_log( "[KG DB 0.22] ALTER {$table} ADD KEY {$name}: {$err}" );
			}
		};

		// ── A. Identity columns on kg_entities. ──────────────────────────
		$add_col( $this->tbl_entities(), 'id_kind',
			"id_kind VARCHAR(32) DEFAULT NULL COMMENT 'PHASE-0.3: sku|order|invoice|contract|customer|employee|version|endpoint|campaign|location|tx'" );
		$add_col( $this->tbl_entities(), 'canonical_id',
			"canonical_id VARCHAR(190) DEFAULT NULL COMMENT 'PHASE-0.3: case-preserving canonical form, e.g. FS 369I'" );
		$add_col( $this->tbl_entities(), 'identity_source',
			"identity_source VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none|auto|user_confirmed|imported'" );
		$add_col( $this->tbl_entities(), 'identity_score',
			"identity_score DECIMAL(3,2) DEFAULT NULL COMMENT 'extractor confidence 0.00-1.00'" );

		// Lookup index — non-unique on purpose (see method docblock).
		// Use prefix(64) on canonical_id to stay under 767-byte InnoDB index limit
		// when combined with notebook_id BIGINT.
		$add_index( $this->tbl_entities(), 'idx_identity', 'notebook_id, id_kind, canonical_id(64)' );

		// ── B. New table: per-passage identity cache. ────────────────────
		// Populated by the backfill CLI and the auto-tag hook in the tool wrapper
		// hot path. Read by `class-tool-search-kg.php` to skip on-the-fly regex.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$this->tbl_passage_identities()} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			passage_id      BIGINT UNSIGNED NOT NULL,
			notebook_id     BIGINT UNSIGNED NOT NULL,
			id_kind         VARCHAR(32) NOT NULL,
			canonical_id    VARCHAR(190) NOT NULL,
			evidence_span   VARCHAR(255) DEFAULT NULL,
			occurrences     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
			score           DECIMAL(3,2) NOT NULL DEFAULT 1.00,
			source          VARCHAR(20) NOT NULL DEFAULT 'auto' COMMENT 'auto|user_confirmed|imported',
			extractor_ver   VARCHAR(20) NOT NULL DEFAULT 'regex_v1',
			created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_pid_kind_canon (passage_id, id_kind, canonical_id),
			KEY idx_notebook_canon (notebook_id, id_kind, canonical_id(64)),
			KEY idx_passage (passage_id)
		) {$cs};" );
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

		// Ensure owner_id exists (may be missing on installations created before
		// the column was added to the schema). DEFAULT 0 = unowned/system notebook.
		$add( $this->tbl_notebooks(), 'owner_id',
			"owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'WordPress user ID who owns this notebook'" );

		// Ensure KEY exists for owner_id (safe: MySQL ignores duplicate key names).
		{
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$this->tbl_notebooks()}` ADD KEY idx_nb_owner_id (owner_id)" );
			$wpdb->suppress_errors( $prev );
		}

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

		// HOTFIX 2026-05-08 — Self-healing for blogs corrupted by the
		// pre-HOTFIX run of this migration.
		//
		// Background: HOTFIX 2026-05-06 aliased tbl_source_chunks() == tbl_passages()
		// (both → bizcity_kg_passages). Blogs that successfully ran v0.6.5 BEFORE
		// that hotfix have their data in physical `bizcity_kg_source_chunks` and
		// `bizcity_kg_passages` as a VIEW over it. The original A2/A3 logic below
		// — written when the two helpers returned distinct names — would on those
		// blogs DROP VIEW kg_passages then CREATE VIEW kg_passages AS SELECT FROM
		// kg_passages (self-recursive), nuking the only handle to the data and
		// flooding error.log every request.
		//
		// Heal once: if the helpers now collide AND the canonical name is missing
		// AND a stranded `bizcity_kg_source_chunks` exists with data, rename it
		// back to the canonical name so subsequent ALTERs target a real BASE TABLE.
		if ( $chunks_tbl === $passages_tbl ) {
			$stranded = $wpdb->prefix . 'bizcity_kg_source_chunks';
			$canonical_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $passages_tbl ) ) === $passages_tbl );
			$stranded_exists  = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stranded ) ) === $stranded );
			if ( ! $canonical_exists && $stranded_exists ) {
				$prev = $wpdb->suppress_errors( true );
				$wpdb->query( "RENAME TABLE `{$stranded}` TO `{$passages_tbl}`" );
				$err = $wpdb->last_error;
				$wpdb->suppress_errors( $prev );
				if ( $err ) {
					error_log( "[KG DB heal-2026-05-08] RENAME {$stranded} → {$passages_tbl}: {$err}" );
				} else {
					error_log( "[KG DB heal-2026-05-08] Restored {$passages_tbl} from stranded {$stranded}" );
				}
			} elseif ( $canonical_exists && $stranded_exists ) {
				// Both exist (rare): canonical may be a VIEW pointing at stranded.
				// Drop the VIEW first then RENAME — preserves base-table data.
				$ttype = $wpdb->get_var( "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$passages_tbl}' LIMIT 1" );
				if ( $ttype === 'VIEW' ) {
					$prev = $wpdb->suppress_errors( true );
					$wpdb->query( "DROP VIEW IF EXISTS `{$passages_tbl}`" );
					$wpdb->query( "RENAME TABLE `{$stranded}` TO `{$passages_tbl}`" );
					$err = $wpdb->last_error;
					$wpdb->suppress_errors( $prev );
					if ( $err ) {
						error_log( "[KG DB heal-2026-05-08] DROP VIEW + RENAME for {$passages_tbl}: {$err}" );
					} else {
						error_log( "[KG DB heal-2026-05-08] Replaced VIEW {$passages_tbl} with renamed BASE TABLE" );
					}
				}
			}
		}

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

		// HOTFIX 2026-05-08 — When helpers collide (post-2026-05-06 alias), the
		// RENAME below would be `RENAME TABLE x TO x` which MySQL rejects. Skip.
		if ( $chunks_tbl !== $passages_tbl && $passages_is_table && ! $chunks_is_table ) {
			// Rename old kg_passages → kg_source_chunks. Preserves all data, FKs, embeddings.
			$wpdb->query( "RENAME TABLE `{$passages_tbl}` TO `{$chunks_tbl}`" );
			$chunks_is_table = true;
		}

		// dbDelta on canonical schema — creates table or fills missing cols if renamed.
		// NOTE: dbDelta internally uses DESCRIBE which can lag on replica. We follow up
		// with explicit suppress+ignore ALTERs for the most critical columns.
		// HOTFIX 2026-05-14: source_id is DEFAULT NULL (not NOT NULL) because
		// BizCity_KG_Auto_Promoter inserts chat:user / chat:assistant rows
		// (origin=chat:*) where there is no parent kg_sources row → source_id=NULL.
		dbDelta( "CREATE TABLE IF NOT EXISTS {$chunks_tbl} (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid              CHAR(36) DEFAULT NULL,
			source_id         BIGINT UNSIGNED DEFAULT NULL,
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
			// HOTFIX 2026-05-14 — when tbl_source_chunks() === tbl_passages() (i.e.
			// HOTFIX 2026-05-06 collision: both helpers point at bizcity_kg_passages),
			// the legacy `chunk_id` column from the original tbl_passages() CREATE
			// in step 3 may be missing on blogs whose dbDelta DESCRIBE was stale due
			// to replica lag. Auto_Promoter INSERT then errors with
			// "Unknown column 'chunk_id'". Force-add via the suppress+ignore loop.
			'chunk_id'          => "chunk_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'FK → bizcity_knowledge_chunks if promoted'",
			// source_id is added separately below via MODIFY to drop the NOT NULL constraint
			// without losing data (add_col only handles ADD, not MODIFY).
		] as $col => $ddl ) {
			$add_col( $chunks_tbl, $col, $ddl );
		}

		// HOTFIX 2026-05-14 — Drop the NOT NULL constraint on source_id so chat
		// session passages (origin=chat:*, source_id=NULL) stop failing with
		// "Column 'source_id' cannot be null". Idempotent — MODIFY is safe to re-run.
		{
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$chunks_tbl}` MODIFY COLUMN source_id BIGINT UNSIGNED DEFAULT NULL" );
			$err = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err ) {
				error_log( "[KG DB] ALTER {$chunks_tbl} MODIFY source_id nullable: {$err}" );
			}
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
		//
		// HOTFIX 2026-05-08 — When helpers collide ($chunks_tbl === $passages_tbl)
		// the VIEW would be self-referential: CREATE VIEW kg_passages AS
		// SELECT … FROM kg_passages — MySQL accepts the parse then errors at
		// resolution time, AND the prior DROP VIEW would have already nuked the
		// only handle to the data. Skip the entire VIEW dance in that mode.
		$passages_still_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $passages_tbl ) ) === $passages_tbl )
			&& ( $wpdb->get_var( "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$passages_tbl}' LIMIT 1" ) === 'BASE TABLE' );

		if ( ! $passages_still_table && $chunks_tbl !== $passages_tbl ) {
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
	 * Phase 0.21 — Wave 1: Guru UUID namespace + virtual-attach map.
	 *
	 * Adds nullable `character_uuid CHAR(36)` to the 4 KG core tables so a guru's
	 * passages/entities/relations/sources can co-exist with user-owned rows in the
	 * SAME tables. Retrieval queries virtual-merge via:
	 *
	 *     WHERE (scope_type='notebook' AND scope_id=:nb)
	 *        OR character_uuid IN (SELECT guru_uuid FROM ..._attachments WHERE notebook_id=:nb)
	 *
	 * This avoids row duplication when a notebook attaches a guru. Read-only is
	 * enforced at the facade layer (BizCity_KG_Source_Service / Graph_Service),
	 * NOT by SQL trigger — to keep export/import portability.
	 *
	 * Idempotent. Safe to re-run on every boot.
	 *
	 * @since 0.21.0 (2026-05-06)
	 */
	private function migrate_v021_guru_namespace() {
		global $wpdb;

		$cs = $wpdb->get_charset_collate();

		// ── A. Closures ─ same suppress+ignore-error pattern as 0.6.5. ────
		$add_col = function ( $table, $column, $ddl ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$ddl}" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate column' ) ) {
				error_log( "[KG DB 0.21] ALTER {$table} ADD COLUMN {$column}: {$err}" );
			}
		};
		$add_index = function ( $table, $name, $cols ) use ( $wpdb ) {
			$prev = $wpdb->suppress_errors( true );
			$wpdb->query( "ALTER TABLE `{$table}` ADD KEY {$name} ({$cols})" );
			$err  = $wpdb->last_error;
			$wpdb->suppress_errors( $prev );
			if ( $err && false === strpos( $err, 'Duplicate key name' ) ) {
				error_log( "[KG DB 0.21] ALTER {$table} ADD KEY {$name}: {$err}" );
			}
		};

		// ── B. Add character_uuid to 4 core tables. ───────────────────────
		// kg_source_chunks (replaces kg_passages physically; kg_passages is now a VIEW).
		$add_col( $this->tbl_source_chunks(), 'character_uuid',
			"character_uuid CHAR(36) DEFAULT NULL COMMENT 'PHASE-0.21 guru namespace: NULL=user-owned, set=guru-owned/read-only'" );
		$add_col( $this->tbl_entities(),      'character_uuid',
			"character_uuid CHAR(36) DEFAULT NULL COMMENT 'PHASE-0.21 guru namespace'" );
		$add_col( $this->tbl_relations(),     'character_uuid',
			"character_uuid CHAR(36) DEFAULT NULL COMMENT 'PHASE-0.21 guru namespace'" );
		$add_col( $this->tbl_sources(),       'character_uuid',
			"character_uuid CHAR(36) DEFAULT NULL COMMENT 'PHASE-0.21 guru namespace'" );

		$add_index( $this->tbl_source_chunks(), 'idx_character_uuid', 'character_uuid' );
		$add_index( $this->tbl_entities(),      'idx_character_uuid', 'character_uuid' );
		$add_index( $this->tbl_relations(),     'idx_character_uuid', 'character_uuid' );
		$add_index( $this->tbl_sources(),       'idx_character_uuid', 'character_uuid' );

		// ── C. Drop+rebuild VIEW kg_passages so it exposes character_uuid. ──
		// VIEW was created in migrate_v065_unified_sources() WITHOUT character_uuid;
		// retriever code that reads via the legacy VIEW must see the new column.
		$passages_tbl = $this->tbl_passages();
		$ttype        = $wpdb->get_var( "SELECT TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$passages_tbl}' LIMIT 1" );
		if ( $ttype === 'VIEW' ) {
			$wpdb->query( "DROP VIEW IF EXISTS {$passages_tbl}" );
			$chunks_tbl = $this->tbl_source_chunks();
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
					character_uuid,
					created_at,
					updated_at
				 FROM {$chunks_tbl}"
			);
		}

		// ── D. Create virtual-attach map (notebook ↔ character). ──────────
		$att_tbl = $this->tbl_notebook_character_attachments();
		dbDelta( "CREATE TABLE IF NOT EXISTS {$att_tbl} (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			notebook_id      BIGINT UNSIGNED NOT NULL,
			guru_uuid        CHAR(36) NOT NULL,
			attached_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
			attached_by      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source           VARCHAR(20) NOT NULL DEFAULT 'self' COMMENT 'marketplace|share_link|self|imported',
			read_only        TINYINT(1) NOT NULL DEFAULT 1,
			attached_version VARCHAR(20) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_nb_guru (notebook_id, guru_uuid),
			KEY idx_guru (guru_uuid),
			KEY idx_notebook (notebook_id)
		) {$cs};" );
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
			$this->tbl_notebook_character_attachments(), // 0.21 — guru attachments.
			$this->tbl_passage_identities(),            // 0.22 — identity cache.
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
