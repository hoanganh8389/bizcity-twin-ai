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
 * BizCity Skill-Tool Map — Two-Way Mapping between Skills & Tools
 *
 * Phase 1.4e (R7): bizcity_skill_tool_map table.
 * Enables:
 *   - FROM SKILL → see linked tools
 *   - FROM TOOL → see linked skills (for resolve_skill)
 *
 * Binding types:
 *   primary    — tool always uses this skill
 *   secondary  — optional, user can override
 *   suggested  — AI-recommended, not confirmed
 *
 * @package  BizCity_Skills
 * @since    2026-04-03
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Tool_Map {

	/** @var self|null */
	private static $instance = null;

	/** @var string */
	private $table;

	const SCHEMA_VERSION     = '1.0.0';
	const SCHEMA_VERSION_KEY = 'bizcity_skill_tool_map_db_version';

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_skill_tool_map';
		$this->maybe_create_table();
	}

	/* ================================================================
	 *  DDL
	 * ================================================================ */

	private function maybe_create_table(): void {
		$stored = get_option( self::SCHEMA_VERSION_KEY, '' );
		if ( $stored === self::SCHEMA_VERSION ) {
			return;
		}
		$this->create_table();
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
	}

	private function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			skill_id    BIGINT UNSIGNED NOT NULL COMMENT 'FK → bizcity_skills.id',
			tool_key    VARCHAR(128)    NOT NULL COMMENT 'Tool name (e.g. generate_blog_content)',
			binding     ENUM('primary','secondary','suggested') DEFAULT 'primary',
			created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uk_skill_tool (skill_id, tool_key),
			KEY idx_tool (tool_key),
			KEY idx_skill (skill_id)
		) {$charset};";

		dbDelta( $sql );
	}

	/* ================================================================
	 *  CRUD
	 * ================================================================ */

	/**
	 * Link a skill to a tool.
	 *
	 * @param int    $skill_id Skill row ID.
	 * @param string $tool_key Tool name.
	 * @param string $binding  primary|secondary|suggested.
	 * @return int|false Map row ID or false.
	 */
	public function link( int $skill_id, string $tool_key, string $binding = 'primary' ) {
		global $wpdb;

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$this->table} WHERE skill_id = %d AND tool_key = %s LIMIT 1",
			$skill_id, $tool_key
		) );

		if ( $existing ) {
			$wpdb->update( $this->table, [ 'binding' => $binding ], [ 'id' => $existing ] );
			return (int) $existing;
		}

		$wpdb->insert( $this->table, [
			'skill_id' => $skill_id,
			'tool_key' => $tool_key,
			'binding'  => $binding,
		] );
		return $wpdb->insert_id ?: false;
	}

	/**
	 * Remove a skill-tool link.
	 */
	public function unlink( int $skill_id, string $tool_key ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( $this->table, [
			'skill_id' => $skill_id,
			'tool_key' => $tool_key,
		] );
	}

	/**
	 * Remove all links for a skill.
	 */
	public function unlink_all_for_skill( int $skill_id ): int {
		global $wpdb;
		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table} WHERE skill_id = %d",
			$skill_id
		) );
	}

	/* ================================================================
	 *  Two-Way Queries
	 * ================================================================ */

	/**
	 * FROM SKILL → get linked tools.
	 *
	 * @param int $skill_id
	 * @return array [ { tool_key, binding } ]
	 */
	public function get_tools_for_skill( int $skill_id ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT tool_key, binding FROM {$this->table} WHERE skill_id = %d ORDER BY binding ASC",
			$skill_id
		), ARRAY_A ) ?: [];
	}

	/**
	 * FROM TOOL → get linked skill IDs.
	 *
	 * @param string $tool_key Tool name.
	 * @return array [ { skill_id, binding } ]
	 */
	public function get_skills_for_tool( string $tool_key ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT skill_id, binding FROM {$this->table} WHERE tool_key = %s ORDER BY binding ASC",
			$tool_key
		), ARRAY_A ) ?: [];
	}

	/**
	 * FROM TOOL → find best matching skill (with user scope).
	 *
	 * Uses skill_tool_map JOIN bizcity_skills — returns the highest-priority
	 * active skill, preferring personal skills over global.
	 *
	 * @param string $tool_key Tool name.
	 * @param int    $user_id  Current user (0 = global only).
	 * @return array|null { id, title, content, category, user_id, binding }
	 */
	public function resolve_skill_for_tool( string $tool_key, int $user_id = 0 ): ?array {
		global $wpdb;

		$skills_table = $wpdb->prefix . 'bizcity_skills';

		$sql = "SELECT s.id, s.title, s.content, s.category, s.user_id, m.binding
				FROM {$this->table} m
				JOIN {$skills_table} s ON s.id = m.skill_id
				WHERE m.tool_key = %s
				  AND s.status = 'active'
				  AND (s.user_id = 0 OR s.user_id = %d)
				ORDER BY
				  CASE WHEN s.user_id = %d THEN 0 ELSE 1 END,
				  FIELD(m.binding, 'primary', 'secondary', 'suggested'),
				  s.priority ASC
				LIMIT 1";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $tool_key, $user_id, $user_id ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Seed mapping from existing skills' tools_json.
	 * Reads bizcity_skills.tools_json and creates map entries.
	 *
	 * @return int Number of mappings created.
	 */
	public function seed_from_skills_tools_json(): int {
		global $wpdb;

		$skills_table = $wpdb->prefix . 'bizcity_skills';
		$rows = $wpdb->get_results(
			"SELECT id, tools_json FROM {$skills_table} WHERE tools_json IS NOT NULL AND tools_json != '' AND tools_json != '[]'",
			ARRAY_A
		);

		$count = 0;
		foreach ( $rows as $row ) {
			$tools = json_decode( $row['tools_json'], true );
			if ( ! is_array( $tools ) ) {
				continue;
			}
			foreach ( $tools as $tool_key ) {
				$tool_key = trim( $tool_key );
				if ( $tool_key && $this->link( (int) $row['id'], $tool_key, 'primary' ) ) {
					$count++;
				}
			}
		}

		error_log( "[SKILL-TOOL-MAP] Seeded {$count} mappings from tools_json." );
		return $count;
	}
}
