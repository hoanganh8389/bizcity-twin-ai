<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Skill Library — Database Layer
 *
 * Table: bizcity_skills       — Skill definitions (metadata + markdown body)
 * Table: bizcity_skill_logs   — Usage tracking (which skills injected when)
 *
 * Skills sit between Knowledge (what AI knows) and Tools (what AI executes).
 * A skill teaches AI HOW to perform a task — step-by-step instructions,
 * guardrails, templates, and patterns.
 *
 * @package  BizCity_Knowledge
 * @since    2026-03-31
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Skill_Database {

	const DB_VERSION     = '1.0.0';
	const DB_VERSION_KEY = 'bizcity_skills_db_ver';

	private static $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		self::maybe_create_tables();
	}

	/* ================================================================
	 *  Table Creation / Migration
	 * ================================================================ */

	public static function maybe_create_tables(): void {
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$skills_table = $wpdb->prefix . 'bizcity_skills';
		$logs_table   = $wpdb->prefix . 'bizcity_skill_logs';

		$sql = "CREATE TABLE {$skills_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			skill_key VARCHAR(191) NOT NULL,
			title VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description TEXT,
			content_md LONGTEXT,
			triggers_json TEXT,
			modes_json TEXT,
			related_tools_json TEXT,
			related_plugins_json TEXT,
			priority INT UNSIGNED NOT NULL DEFAULT 50,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			version VARCHAR(20) NOT NULL DEFAULT '1.0',
			source_type VARCHAR(20) NOT NULL DEFAULT 'db',
			category VARCHAR(100) DEFAULT '',
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			use_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_used_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY skill_key (skill_key),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY category (category),
			KEY priority (priority)
		) {$charset};

		CREATE TABLE {$logs_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			skill_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_id VARCHAR(191) DEFAULT '',
			goal VARCHAR(128) DEFAULT '',
			mode VARCHAR(50) DEFAULT '',
			matched_by VARCHAR(50) DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY skill_id (skill_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/* ================================================================
	 *  CRUD — Skills
	 * ================================================================ */

	/**
	 * Get all skills, optionally filtered.
	 */
	public function get_all( array $filters = [] ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';

		$where  = '1=1';
		$params = [];

		if ( ! empty( $filters['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $filters['status'];
		}
		if ( ! empty( $filters['category'] ) ) {
			$where   .= ' AND category = %s';
			$params[] = $filters['category'];
		}
		if ( ! empty( $filters['mode'] ) ) {
			$where   .= ' AND modes_json LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filters['mode'] ) . '%';
		}
		if ( ! empty( $filters['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where   .= ' AND (title LIKE %s OR description LIKE %s OR triggers_json LIKE %s OR skill_key LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$order = 'ORDER BY priority ASC, title ASC';
		$sql   = "SELECT * FROM {$table} WHERE {$where} {$order}";

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$results = $wpdb->get_results( $sql );
		return $results ? $results : [];
	}

	/**
	 * Get a single skill by ID.
	 */
	public function get_by_id( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
	}

	/**
	 * Get a single skill by skill_key.
	 */
	public function get_by_key( string $key ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE skill_key = %s", $key ) );
	}

	/**
	 * Save a skill (insert or update).
	 *
	 * @param array $data Skill fields.
	 * @return int|false  Skill ID on success, false on failure.
	 */
	public function save( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';

		// Sanitize JSON fields
		$json_fields = [ 'triggers_json', 'modes_json', 'related_tools_json', 'related_plugins_json' ];
		foreach ( $json_fields as $f ) {
			if ( isset( $data[ $f ] ) && is_array( $data[ $f ] ) ) {
				$data[ $f ] = wp_json_encode( $data[ $f ], JSON_UNESCAPED_UNICODE );
			}
		}

		// Auto-generate slug from title if not provided
		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = sanitize_title( $data['title'] );
		}

		// Auto-generate skill_key from slug if not provided
		if ( empty( $data['skill_key'] ) && ! empty( $data['slug'] ) ) {
			$data['skill_key'] = $data['slug'];
		}

		$allowed = [
			'skill_key', 'title', 'slug', 'description', 'content_md',
			'triggers_json', 'modes_json', 'related_tools_json', 'related_plugins_json',
			'priority', 'status', 'version', 'source_type', 'category', 'author_id',
		];
		$save_data = array_intersect_key( $data, array_flip( $allowed ) );

		if ( ! empty( $data['id'] ) ) {
			// Update
			$id = (int) $data['id'];
			$save_data['updated_at'] = current_time( 'mysql' );
			$result = $wpdb->update( $table, $save_data, [ 'id' => $id ] );
			return $result !== false ? $id : false;
		}

		// Insert
		$save_data['created_at'] = current_time( 'mysql' );
		$save_data['updated_at'] = current_time( 'mysql' );
		$result = $wpdb->insert( $table, $save_data );
		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Delete a skill by ID.
	 */
	public function delete( int $id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';
		return (bool) $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Check if a skill_key or slug already exists (excluding given ID).
	 */
	public function key_exists( string $key, int $exclude_id = 0 ): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';
		$sql   = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE (skill_key = %s OR slug = %s) AND id != %d",
			$key, $key, $exclude_id
		);
		return (int) $wpdb->get_var( $sql ) > 0;
	}

	/* ================================================================
	 *  Search & Matching — used by Twin Core
	 * ================================================================ */

	/**
	 * Find skills matching a mode + goal + tool + message keywords.
	 *
	 * @param array $criteria {
	 *   @type string   $mode     Current mode (planning, execution, etc.)
	 *   @type string   $goal     Active goal ID
	 *   @type string   $tool     Tool being invoked
	 *   @type string   $plugin   Plugin slug
	 *   @type string   $message  User's message (for keyword matching)
	 *   @type int      $limit    Max skills to return
	 * }
	 * @return array Matched skills sorted by relevance score.
	 */
	public function find_matching( array $criteria ): array {
		$mode    = $criteria['mode'] ?? '';
		$goal    = $criteria['goal'] ?? '';
		$tool    = $criteria['tool'] ?? '';
		$plugin  = $criteria['plugin'] ?? '';
		$message = mb_strtolower( $criteria['message'] ?? '', 'UTF-8' );
		$limit   = (int) ( $criteria['limit'] ?? 3 );

		$all_active = $this->get_all( [ 'status' => 'active' ] );
		if ( empty( $all_active ) ) {
			return [];
		}

		$scored = [];
		foreach ( $all_active as $skill ) {
			$score = 0;
			$reasons = [];

			// Mode match
			$modes = json_decode( $skill->modes_json ?: '[]', true ) ?: [];
			if ( $mode && in_array( $mode, $modes, true ) ) {
				$score += 30;
				$reasons[] = 'mode:' . $mode;
			}

			// Tool match
			$tools = json_decode( $skill->related_tools_json ?: '[]', true ) ?: [];
			if ( $tool && in_array( $tool, $tools, true ) ) {
				$score += 25;
				$reasons[] = 'tool:' . $tool;
			}
			if ( $goal && in_array( $goal, $tools, true ) ) {
				$score += 25;
				$reasons[] = 'goal_as_tool:' . $goal;
			}

			// Plugin match
			$plugins = json_decode( $skill->related_plugins_json ?: '[]', true ) ?: [];
			if ( $plugin && in_array( $plugin, $plugins, true ) ) {
				$score += 20;
				$reasons[] = 'plugin:' . $plugin;
			}

			// Trigger keyword match
			$triggers = json_decode( $skill->triggers_json ?: '[]', true ) ?: [];
			if ( $message && ! empty( $triggers ) ) {
				foreach ( $triggers as $trigger ) {
					if ( mb_stripos( $message, $trigger ) !== false ) {
						$score += 15;
						$reasons[] = 'trigger:' . $trigger;
						break; // One trigger match is enough
					}
				}
			}

			// Priority bonus (lower priority number = higher score bonus)
			$priority_bonus = max( 0, 10 - ( (int) $skill->priority / 10 ) );
			$score += $priority_bonus;

			if ( $score > 0 ) {
				$scored[] = [
					'skill'   => $skill,
					'score'   => $score,
					'reasons' => $reasons,
				];
			}
		}

		// Sort by score DESC
		usort( $scored, fn( $a, $b ) => $b['score'] <=> $a['score'] );

		return array_slice( $scored, 0, $limit );
	}

	/* ================================================================
	 *  Usage Logging
	 * ================================================================ */

	/**
	 * Log a skill usage event.
	 */
	public function log_usage( int $skill_id, array $ctx = [] ): void {
		global $wpdb;
		$log_table   = $wpdb->prefix . 'bizcity_skill_logs';
		$skill_table = $wpdb->prefix . 'bizcity_skills';

		$wpdb->insert( $log_table, [
			'skill_id'   => $skill_id,
			'user_id'    => $ctx['user_id'] ?? get_current_user_id(),
			'session_id' => $ctx['session_id'] ?? '',
			'goal'       => $ctx['goal'] ?? '',
			'mode'       => $ctx['mode'] ?? '',
			'matched_by' => $ctx['matched_by'] ?? '',
			'created_at' => current_time( 'mysql' ),
		] );

		// Increment use_count + last_used_at
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$skill_table} SET use_count = use_count + 1, last_used_at = %s WHERE id = %d",
			current_time( 'mysql' ),
			$skill_id
		) );
	}

	/**
	 * Get usage stats for a skill.
	 */
	public function get_usage_stats( int $skill_id, int $days = 30 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skill_logs';

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE skill_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
			$skill_id, $days
		) );

		$by_mode = $wpdb->get_results( $wpdb->prepare(
			"SELECT mode, COUNT(*) as cnt FROM {$table} WHERE skill_id = %d AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) GROUP BY mode ORDER BY cnt DESC",
			$skill_id, $days
		), ARRAY_A );

		return [
			'total_last_n_days' => $total,
			'days'              => $days,
			'by_mode'           => $by_mode ?: [],
		];
	}

	/**
	 * Get distinct categories.
	 */
	public function get_categories(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'bizcity_skills';
		$rows  = $wpdb->get_col( "SELECT DISTINCT category FROM {$table} WHERE category != '' ORDER BY category ASC" );
		return $rows ?: [];
	}
}
