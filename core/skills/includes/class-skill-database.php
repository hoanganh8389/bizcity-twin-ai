<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Database — SQL-based Skill Storage
 *
 * Phase 1.4a: Replaces file-based .md storage with SQL table.
 * Table: bizcity_skills — CRUD + scoring via SQL pre-filter + in-memory scoring.
 *
 * Migration from .md: migrate_files_to_sql() reads BIZCITY_SKILLS_LIBRARY,
 * parses YAML front-matter, INSERTs into bizcity_skills.
 *
 * @package  BizCity_Skills
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

// Guard: knowledge module has a legacy copy with the same class name
if ( class_exists( 'BizCity_Skill_Database' ) ) {
	return;
}

class BizCity_Skill_Database {

	/** @var self|null */
	private static $instance = null;

	/** @var string DB table name (wp_bizcity_skills) */
	private $table;

	/** @var string Schema version — bump when adding migrations. */
	const SCHEMA_VERSION = '1.4.0';

	/** @var string wp_options key */
	const SCHEMA_VERSION_KEY = 'bizcity_skills_db_version';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_skills';
		$this->maybe_create_table();
	}

	/* ================================================================
	 *  DDL — Table Creation & Migrations
	 * ================================================================ */

	/**
	 * Create table if schema version doesn't match.
	 */
	private function maybe_create_table(): void {
		$stored = get_option( self::SCHEMA_VERSION_KEY, '' );
		if ( $stored === self::SCHEMA_VERSION ) {
			return;
		}
		$this->create_table();
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
	}

	/**
	 * CREATE TABLE bizcity_skills via dbDelta.
	 */
	private function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$this->table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			skill_key       VARCHAR(128)    NOT NULL COMMENT 'slug unique: marketing-blog-v1',
			user_id         BIGINT UNSIGNED DEFAULT 0 COMMENT '0=global, >0=per-user',
			character_id    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Twin character sở hữu',
			title           VARCHAR(255)    NOT NULL,
			description     TEXT            DEFAULT NULL,
			category        VARCHAR(64)     DEFAULT 'general',
			triggers_json   TEXT            DEFAULT NULL COMMENT 'JSON array of trigger keywords',
			slash_commands   VARCHAR(512)   DEFAULT NULL COMMENT 'comma-separated: /write_article,/blog',
			modes           VARCHAR(255)    DEFAULT NULL COMMENT 'comma-separated: content,execution',
			tools_json      TEXT            DEFAULT NULL COMMENT 'JSON array of tool names',
			content         LONGTEXT        NOT NULL COMMENT 'Markdown body — LLM prompt instructions',
			content_hash    VARCHAR(64)     DEFAULT '' COMMENT 'MD5 for cache bust',
			pipeline_json   LONGTEXT        DEFAULT NULL COMMENT 'JSON: chain, blocks, skip_* for pipeline config',
			priority        INT UNSIGNED    DEFAULT 50 COMMENT '0=highest, 100=lowest',
			status          ENUM('draft','active','archived') DEFAULT 'draft',
			version         VARCHAR(32)     DEFAULT '1.0',
			created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uk_skill_key_user (skill_key, user_id, character_id),
			KEY idx_character (character_id),
			KEY idx_user (user_id),
			KEY idx_category (category),
			KEY idx_status (status),
			KEY idx_priority (priority)
		) {$charset};";

		dbDelta( $sql );
	}

	/**
	 * Get the table name.
	 */
	public function get_table(): string {
		return $this->table;
	}

	/* ================================================================
	 *  CRUD Operations
	 * ================================================================ */

	/**
	 * Insert or update a skill row.
	 *
	 * @param array $data Skill data fields.
	 * @return int|false Inserted/updated row ID, or false on failure.
	 */
	public function upsert( array $data ) {
		global $wpdb;

		$skill_key    = $data['skill_key'] ?? '';
		$user_id      = (int) ( $data['user_id'] ?? 0 );
		$character_id = (int) ( $data['character_id'] ?? 0 );

		if ( empty( $skill_key ) ) {
			error_log( '[BizCity Skills] upsert() failed: empty skill_key' );
			return false;
		}

		// Allow empty content — frontmatter-only skills are valid
		if ( ! isset( $data['content'] ) ) {
			$data['content'] = '';
		}

		// Auto-generate content_hash
		$data['content_hash'] = md5( $data['content'] );

		// Encode arrays to JSON
		if ( isset( $data['triggers_json'] ) && is_array( $data['triggers_json'] ) ) {
			$data['triggers_json'] = wp_json_encode( $data['triggers_json'], JSON_UNESCAPED_UNICODE );
		}
		if ( isset( $data['tools_json'] ) && is_array( $data['tools_json'] ) ) {
			$data['tools_json'] = wp_json_encode( $data['tools_json'], JSON_UNESCAPED_UNICODE );
		}
		if ( isset( $data['pipeline_json'] ) && is_array( $data['pipeline_json'] ) ) {
			$data['pipeline_json'] = wp_json_encode( $data['pipeline_json'], JSON_UNESCAPED_UNICODE );
		}
		// Comma-separated arrays
		if ( isset( $data['slash_commands'] ) && is_array( $data['slash_commands'] ) ) {
			$data['slash_commands'] = implode( ',', $data['slash_commands'] );
		}
		if ( isset( $data['modes'] ) && is_array( $data['modes'] ) ) {
			$data['modes'] = implode( ',', $data['modes'] );
		}

		// Check existing
		$existing_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table} WHERE skill_key = %s AND user_id = %d AND character_id = %d LIMIT 1",
			$skill_key, $user_id, $character_id
		) );

		if ( $existing_id ) {
			$result = $wpdb->update( $this->table, $data, [ 'id' => $existing_id ] );
			if ( $result === false ) {
				error_log( '[BizCity Skills] upsert() UPDATE failed for skill_key=' . $skill_key . ' — ' . $wpdb->last_error );
				return false;
			}
			return (int) $existing_id;
		}

		$result = $wpdb->insert( $this->table, $data );
		if ( $result === false ) {
			error_log( '[BizCity Skills] upsert() INSERT failed for skill_key=' . $skill_key . ' — ' . $wpdb->last_error );
			return false;
		}
		return $wpdb->insert_id ?: false;
	}

	/**
	 * Get a single skill by ID.
	 *
	 * @param int $id Skill row ID.
	 * @return array|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			$id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Get a skill by key + scope.
	 *
	 * @param string $skill_key  Skill slug.
	 * @param int    $user_id    0 = global.
	 * @param int    $char_id    Character ID.
	 * @return array|null
	 */
	public function get_by_key( string $skill_key, int $user_id = 0, int $char_id = 0 ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE skill_key = %s AND user_id = %d AND character_id = %d LIMIT 1",
			$skill_key, $user_id, $char_id
		), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Find a skill by slash command (e.g. '/write_article').
	 *
	 * Searches the `slash_commands` column (comma-separated values).
	 *
	 * @param string $command  Slash command with or without leading '/'.
	 * @return array|null      Skill row or null.
	 */
	public function get_by_slash_command( string $command ): ?array {
		$command = ltrim( trim( $command ), '/' );
		if ( $command === '' ) {
			return null;
		}
		global $wpdb;
		// Strip leading slashes from both input and stored values for consistent matching
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE status = 'active' AND FIND_IN_SET( %s, REPLACE( REPLACE( slash_commands, '/', '' ), ' ', '' ) ) > 0 LIMIT 1",
			$command
		), ARRAY_A );

		if ( $row ) {
			error_log( '[SkillDB] get_by_slash: FIND_IN_SET hit | cmd=' . $command . ' | key=' . ( $row['skill_key'] ?? '' ) );
			return $row;
		}

		// Fallback: frontend sends skill_key (e.g. cc_slug) but slash_commands stores just the slug
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE status = 'active' AND skill_key = %s LIMIT 1",
			$command
		), ARRAY_A );

		if ( $row ) {
			error_log( '[SkillDB] get_by_slash: skill_key fallback hit | cmd=' . $command . ' | key=' . ( $row['skill_key'] ?? '' ) );
		} else {
			error_log( '[SkillDB] get_by_slash: NO MATCH | cmd=' . $command );
		}

		return $row ?: null;
	}

	/**
	 * Delete a skill by ID.
	 *
	 * @param int $id Skill row ID.
	 * @return bool
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table, [ 'id' => $id ] );
	}

	/**
	 * List skills with optional filters.
	 *
	 * @param array $filters {
	 *   @type int    $character_id  Filter by character.
	 *   @type int    $user_id       Filter by user (0 = global only).
	 *   @type string $category      Filter by category.
	 *   @type string $status        Filter by status (default: 'active').
	 *   @type int    $limit         Max results (default: 50).
	 *   @type int    $offset        Pagination offset.
	 * }
	 * @return array
	 */
	public function list_skills( array $filters = [] ): array {
		global $wpdb;

		$where  = [ '1=1' ];
		$params = [];

		if ( isset( $filters['character_id'] ) ) {
			$where[]  = 'character_id = %d';
			$params[] = (int) $filters['character_id'];
		}
		if ( isset( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = $filters['category'];
		}

		$status   = $filters['status'] ?? 'active';
		$where[]  = 'status = %s';
		$params[] = $status;

		$limit  = (int) ( $filters['limit'] ?? 50 );
		$offset = (int) ( $filters['offset'] ?? 0 );

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where )
		     . " ORDER BY priority ASC, updated_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
	}

	/* ================================================================
	 *  Skill Matching — SQL pre-filter + in-memory scoring
	 * ================================================================ */

	/**
	 * Find best matching skill for given criteria.
	 *
	 * @param array $criteria {
	 *   @type string $tool           Tool name to match.
	 *   @type string $slash_command  Slash command (e.g. /write_article).
	 *   @type string $mode           Current mode (e.g. content, execution).
	 *   @type string $message        User message for trigger keyword matching.
	 *   @type int    $user_id        User ID (0=global only).
	 *   @type int    $character_id   Character ID.
	 *   @type int    $limit          Max results (default: 1).
	 * }
	 * @return array|null Best match { id, title, content, category, user_id, score } or null.
	 */
	public function find_matching( array $criteria ): ?array {
		global $wpdb;

		$char_id = (int) ( $criteria['character_id'] ?? 0 );
		$user_id = (int) ( $criteria['user_id'] ?? 0 );
		$limit   = (int) ( $criteria['limit'] ?? 1 );

		// SQL pre-filter: active skills for this character + user scope
		$where  = [ "status = 'active'" ];
		$params = [];

		if ( $char_id > 0 ) {
			$where[]  = '(character_id = %d OR character_id = 0)';
			$params[] = $char_id;
		}

		if ( $user_id > 0 ) {
			$where[]  = '(user_id = 0 OR user_id = %d)';
			$params[] = $user_id;
		} else {
			$where[] = 'user_id = 0';
		}

		// Pre-filter by mode via LIKE (optional)
		if ( ! empty( $criteria['mode'] ) ) {
			$where[]  = '(modes IS NULL OR modes = %s OR modes LIKE %s)';
			$params[] = $criteria['mode'];
			$params[] = '%' . $wpdb->esc_like( $criteria['mode'] ) . '%';
		}

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where )
		     . " ORDER BY priority ASC LIMIT 50";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
		if ( empty( $rows ) ) {
			return null;
		}

		// In-memory scoring
		$scored = [];
		foreach ( $rows as $row ) {
			$score = $this->score_skill( $row, $criteria );
			if ( $score >= 15 ) {
				$row['_score'] = $score;
				$scored[] = $row;
			}
		}

		if ( empty( $scored ) ) {
			return null;
		}

		usort( $scored, fn( $a, $b ) => $b['_score'] <=> $a['_score'] );

		$results = array_slice( $scored, 0, $limit );
		return $limit === 1 ? $results[0] : $results;
	}

	/**
	 * Score a skill row against criteria.
	 * Mirrors the scoring algorithm from BizCity_Skill_Manager::find_matching().
	 *
	 * @param array $row      Skill DB row.
	 * @param array $criteria Search criteria.
	 * @return int Score (threshold: ≥15).
	 */
	private function score_skill( array $row, array $criteria ): int {
		$score = 0;

		$tools    = json_decode( $row['tools_json'] ?? '[]', true ) ?: [];
		$triggers = json_decode( $row['triggers_json'] ?? '[]', true ) ?: [];
		$slashes  = array_filter( array_map( 'trim', explode( ',', $row['slash_commands'] ?? '' ) ) );
		$modes    = array_filter( array_map( 'trim', explode( ',', $row['modes'] ?? '' ) ) );

		// Slash command exact match (+30) — checks slash_commands column AND skill_key
		if ( ! empty( $criteria['slash_command'] ) ) {
			$cmd = strtolower( ltrim( $criteria['slash_command'], '/' ) );
			$matched_slash = false;
			foreach ( $slashes as $sc ) {
				if ( strtolower( ltrim( $sc, '/' ) ) === $cmd ) {
					$matched_slash = true;
					break;
				}
			}
			// Fallback: skill_key match (virtual skills use skill_key as implicit slash)
			if ( ! $matched_slash && ! empty( $row['skill_key'] ) ) {
				if ( strtolower( $row['skill_key'] ) === $cmd ) {
					$matched_slash = true;
				}
			}
			if ( $matched_slash ) {
				$score += 30;
			}
		}

		// Mode match (+30)
		if ( ! empty( $criteria['mode'] ) && in_array( $criteria['mode'], $modes, true ) ) {
			$score += 30;
		}

		// Tool match (+25)
		if ( ! empty( $criteria['tool'] ) && in_array( $criteria['tool'], $tools, true ) ) {
			$score += 25;
		}

		// Goal as tool (+25)
		if ( ! empty( $criteria['goal'] ) && in_array( $criteria['goal'], $tools, true ) ) {
			$score += 25;
		}

		// Trigger keyword in message (+15)
		if ( ! empty( $criteria['message'] ) ) {
			$msg_lower = mb_strtolower( $criteria['message'] );
			foreach ( $triggers as $t ) {
				if ( $t && mb_stripos( $msg_lower, mb_strtolower( $t ) ) !== false ) {
					$score += 15;
					break;
				}
			}
		}

		// Priority bonus (0–10): lower priority value = higher bonus
		$priority = (int) ( $row['priority'] ?? 50 );
		$score += max( 0, 10 - intval( $priority / 10 ) );

		// Personal skill bonus (+10)
		if ( $row['user_id'] > 0 && ! empty( $criteria['user_id'] ) && (int) $row['user_id'] === (int) $criteria['user_id'] ) {
			$score += 10;
		}

		return $score;
	}

	/* ================================================================
	 *  Migration: .md files → SQL
	 * ================================================================ */

	/**
	 * Migrate existing .md skill files to SQL.
	 * Safe to run multiple times — uses REPLACE via upsert().
	 *
	 * @return int Number of skills migrated.
	 */
	public function migrate_files_to_sql(): int {
		if ( ! defined( 'BIZCITY_SKILLS_LIBRARY' ) || ! is_dir( BIZCITY_SKILLS_LIBRARY ) ) {
			return 0;
		}

		if ( ! class_exists( 'BizCity_Skill_Manager' ) ) {
			return 0;
		}

		$manager = BizCity_Skill_Manager::instance();
		$skills  = $manager->get_all_skills();
		$count   = 0;

		foreach ( $skills as $sk ) {
			$fm = $sk['frontmatter'];
			if ( empty( $sk['content'] ) && empty( $fm['title'] ) ) {
				continue;
			}

			// Derive skill_key from path or title
			$skill_key = $fm['name'] ?? sanitize_title( $fm['title'] ?? basename( $sk['path'], '.md' ) );

			$result = $this->upsert( [
				'skill_key'      => $skill_key,
				'character_id'   => 0,
				'user_id'        => 0,
				'title'          => $fm['title'] ?? basename( $sk['path'], '.md' ),
				'description'    => $fm['description'] ?? '',
				'category'       => $this->detect_category( $sk['path'] ),
				'triggers_json'  => $fm['triggers'] ?? [],
				'slash_commands' => $fm['slash_commands'] ?? [],
				'modes'          => $fm['modes'] ?? [],
				'tools_json'     => array_merge(
					(array) ( $fm['related_tools'] ?? [] ),
					(array) ( $fm['tools'] ?? [] )
				),
				'content'        => $sk['content'],
				'priority'       => (int) ( $fm['priority'] ?? 50 ),
				'status'         => $fm['status'] ?? 'active',
				'version'        => $fm['version'] ?? '1.0',
			] );

			if ( $result ) {
				$count++;
			}
		}

		error_log( "[SKILL-DB] Migrated {$count} skills from .md files to SQL." );
		return $count;
	}

	/**
	 * Detect category from file path (subfolder name).
	 */
	private function detect_category( string $path ): string {
		$parts = explode( '/', ltrim( $path, '/' ) );
		if ( count( $parts ) > 1 ) {
			return sanitize_title( $parts[0] );
		}
		return 'general';
	}
}
