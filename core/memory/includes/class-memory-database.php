<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory Database — SQL-based Memory Spec Storage
 *
 * Phase 1.15: CRUD operations on bizcity_memory_specs table.
 * Pattern follows BizCity_Skill_Database (core/skills/).
 *
 * Table: bizcity_memory_specs — stores Markdown content + metadata.
 * Table: bizcity_memory_logs  — append-only audit trail.
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Memory_Database' ) ) {
	return;
}

class BizCity_Memory_Database {

	/** @var self|null */
	private static $instance = null;

	/** @var string DB table name (wp_bizcity_memory_specs) */
	private $table;

	/** @var string Logs table name (wp_bizcity_memory_logs) */
	private $table_logs;

	/** @var string Schema version — bump when adding migrations. */
	const SCHEMA_VERSION = '1.0.0';

	/** @var string wp_options key */
	const SCHEMA_VERSION_KEY = 'bizcity_memory_db_version';

	/**
	 * @return self
	 */
	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		global $wpdb;
		$this->table      = $wpdb->prefix . 'bizcity_memory_specs';
		$this->table_logs = $wpdb->prefix . 'bizcity_memory_logs';
		$this->maybe_create_tables();
	}

	/* ================================================================
	 *  DDL — Table Creation & Migrations
	 * ================================================================ */

	/**
	 * Create tables if schema version doesn't match.
	 *
	 * @return void
	 */
	private function maybe_create_tables() {
		$stored = get_option( self::SCHEMA_VERSION_KEY, '' );
		if ( $stored === self::SCHEMA_VERSION ) {
			return;
		}
		$this->create_tables();
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
	}

	/**
	 * CREATE TABLEs via dbDelta.
	 *
	 * @return void
	 */
	private function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// ── bizcity_memory_specs ──
		// NOTE: dbDelta() does NOT support IF NOT EXISTS — removed per Principle 1.1.8
		$sql_specs = "CREATE TABLE {$this->table} (
			id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			memory_key      VARCHAR(128)    NOT NULL,
			project_id      VARCHAR(36)     NOT NULL DEFAULT '',
			session_id      VARCHAR(64)     NOT NULL DEFAULT '',
			conversation_id VARCHAR(64)     DEFAULT NULL,
			user_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			character_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title           VARCHAR(255)    NOT NULL,
			description     TEXT            DEFAULT NULL,
			content         LONGTEXT        NOT NULL,
			content_hash    VARCHAR(64)     DEFAULT '',
			scope           VARCHAR(20)     NOT NULL DEFAULT 'session',
			skill_id        BIGINT UNSIGNED DEFAULT NULL,
			skill_key       VARCHAR(128)    DEFAULT NULL,
			status          VARCHAR(20)     NOT NULL DEFAULT 'active',
			pipeline_id     VARCHAR(64)     DEFAULT NULL,
			current_step    VARCHAR(100)    DEFAULT NULL,
			resume_state    LONGTEXT        DEFAULT NULL,
			version         INT UNSIGNED    NOT NULL DEFAULT 1,
			created_at      DATETIME        DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			completed_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uk_memory_key (memory_key, user_id, character_id),
			KEY idx_project (project_id),
			KEY idx_session (session_id),
			KEY idx_conversation (conversation_id),
			KEY idx_user (user_id),
			KEY idx_character (character_id),
			KEY idx_status (status),
			KEY idx_skill (skill_id),
			KEY idx_project_session (project_id, session_id),
			KEY idx_user_project (user_id, project_id, status)
		) {$charset};";

		dbDelta( $sql_specs );

		// ── bizcity_memory_logs ──
		// FIX BUG #2: column renamed diff_json→detail_json to match BizCity_Memory_Log::record()
		// FIX BUG #9: removed IF NOT EXISTS (Principle 1.1.8)
		$sql_logs = "CREATE TABLE {$this->table_logs} (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			memory_id   BIGINT UNSIGNED NOT NULL,
			user_id     BIGINT UNSIGNED DEFAULT 0,
			action      VARCHAR(30)     NOT NULL,
			step_name   VARCHAR(100)    DEFAULT NULL,
			detail_json LONGTEXT        DEFAULT NULL,
			created_at  DATETIME        DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_memory (memory_id),
			KEY idx_action (action),
			KEY idx_user (user_id),
			KEY idx_created (created_at)
		) {$charset};";

		dbDelta( $sql_logs );
	}

	/* ================================================================
	 *  Accessors
	 * ================================================================ */

	/**
	 * @return string
	 */
	public function get_table() {
		return $this->table;
	}

	/**
	 * @return string
	 */
	public function get_logs_table() {
		return $this->table_logs;
	}

	/* ================================================================
	 *  CRUD — Create
	 * ================================================================ */

	/**
	 * Insert a new memory spec row.
	 *
	 * @param array $data Memory spec fields.
	 * @return int|false Inserted row ID or false on failure.
	 */
	public function create( $data ) {
		global $wpdb;

		$memory_key = isset( $data['memory_key'] ) ? $data['memory_key'] : '';
		if ( empty( $memory_key ) ) {
			error_log( '[BizCity Memory] create() failed: empty memory_key' );
			return false;
		}

		if ( ! isset( $data['content'] ) ) {
			$data['content'] = '';
		}

		$data['content_hash'] = md5( $data['content'] );

		if ( isset( $data['resume_state'] ) && is_array( $data['resume_state'] ) ) {
			$data['resume_state'] = wp_json_encode( $data['resume_state'], JSON_UNESCAPED_UNICODE );
		}

		$result = $wpdb->insert( $this->table, $data );
		if ( $result === false ) {
			error_log( '[BizCity Memory] create() INSERT failed: ' . $wpdb->last_error );
			return false;
		}

		return $wpdb->insert_id ? (int) $wpdb->insert_id : false;
	}

	/* ================================================================
	 *  CRUD — Read
	 * ================================================================ */

	/**
	 * Get a single memory spec by ID.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
			(int) $id
		), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Get a memory spec by key + scope.
	 *
	 * @param string $memory_key Slug.
	 * @param int    $user_id    User scope.
	 * @param int    $char_id    Character scope.
	 * @return array|null
	 */
	public function get_by_key( $memory_key, $user_id = 0, $char_id = 0 ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$this->table} WHERE memory_key = %s AND user_id = %d AND character_id = %d LIMIT 1",
			$memory_key, (int) $user_id, (int) $char_id
		), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Find active memory spec for a session.
	 *
	 * Search priority:
	 *   1. Same session_id + active
	 *   2. Same project_id + user_id + active (project-level)
	 *
	 * @param array $args {
	 *   @type string $session_id    Webchat session ID.
	 *   @type string $project_id    Project slug/UUID.
	 *   @type int    $user_id       WordPress user ID.
	 *   @type int    $character_id  Twin character ID.
	 * }
	 * @return array|null
	 */
	public function find_active( $args ) {
		global $wpdb;

		$session_id   = isset( $args['session_id'] ) ? $args['session_id'] : '';
		$project_id   = isset( $args['project_id'] ) ? $args['project_id'] : '';
		$user_id      = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$character_id = isset( $args['character_id'] ) ? (int) $args['character_id'] : 0;

		// Priority 1: exact session match
		if ( ! empty( $session_id ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE session_id = %s AND user_id = %d AND character_id = %d AND status = 'active'
				 ORDER BY updated_at DESC LIMIT 1",
				$session_id, $user_id, $character_id
			), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}

		// Priority 2: project-level
		if ( ! empty( $project_id ) ) {
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$this->table}
				 WHERE project_id = %s AND user_id = %d AND character_id = %d AND status = 'active'
				 ORDER BY updated_at DESC LIMIT 1",
				$project_id, $user_id, $character_id
			), ARRAY_A );
			if ( $row ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * List memory specs with filters.
	 *
	 * @param array $filters {
	 *   @type string $project_id    Filter by project.
	 *   @type string $session_id    Filter by session.
	 *   @type int    $user_id       Filter by user.
	 *   @type int    $character_id  Filter by character.
	 *   @type string $status        Filter by status (default: all).
	 *   @type int    $limit         Max results (default: 50).
	 *   @type int    $offset        Pagination offset.
	 * }
	 * @return array
	 */
	public function list_specs( $filters = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $filters['project_id'] ) && $filters['project_id'] !== '' ) {
			$where[]  = 'project_id = %s';
			$params[] = $filters['project_id'];
		}
		if ( isset( $filters['session_id'] ) && $filters['session_id'] !== '' ) {
			$where[]  = 'session_id = %s';
			$params[] = $filters['session_id'];
		}
		if ( isset( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( isset( $filters['character_id'] ) ) {
			$where[]  = 'character_id = %d';
			$params[] = (int) $filters['character_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		$limit  = isset( $filters['limit'] ) ? (int) $filters['limit'] : 50;
		$offset = isset( $filters['offset'] ) ? (int) $filters['offset'] : 0;

		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where )
		     . " ORDER BY updated_at DESC LIMIT %d OFFSET %d";
		$params[] = $limit;
		$params[] = $offset;

		if ( count( $params ) > 0 ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build tree data: projects → sessions → memories.
	 *
	 * @param int $user_id       User scope.
	 * @param int $character_id  Character scope.
	 * @return array Tree structure for REST API.
	 */
	public function get_tree( $user_id = 0, $character_id = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, memory_key, project_id, session_id, conversation_id,
			        title, status, current_step, version, updated_at, created_at
			 FROM {$this->table}
			 WHERE user_id = %d AND character_id = %d AND status != 'archived'
			 ORDER BY project_id ASC, session_id ASC, updated_at DESC",
			(int) $user_id, (int) $character_id
		), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		// Group: project → session → memories
		$projects = array();
		foreach ( $rows as $row ) {
			$pid = ! empty( $row['project_id'] ) ? $row['project_id'] : 'default';
			$sid = ! empty( $row['session_id'] ) ? $row['session_id'] : 'no-session';

			if ( ! isset( $projects[ $pid ] ) ) {
				$projects[ $pid ] = array(
					'project_id'   => $pid,
					'label'        => $pid === 'default' ? 'Không nhóm' : $pid,
					'memory_count' => 0,
					'sessions'     => array(),
				);
			}

			if ( ! isset( $projects[ $pid ]['sessions'][ $sid ] ) ) {
				$projects[ $pid ]['sessions'][ $sid ] = array(
					'session_id'      => $sid,
					'label'           => 'Session ' . $sid,
					'conversation_id' => isset( $row['conversation_id'] ) ? $row['conversation_id'] : null,
					'memories'        => array(),
				);
			}

			$projects[ $pid ]['sessions'][ $sid ]['memories'][] = array(
				'id'           => (int) $row['id'],
				'memory_key'   => $row['memory_key'],
				'title'        => $row['title'],
				'status'       => $row['status'],
				'current_step' => $row['current_step'],
				'version'      => (int) $row['version'],
				'updated_at'   => $row['updated_at'],
				'created_at'   => $row['created_at'],
			);

			$projects[ $pid ]['memory_count']++;
		}

		// Re-index sessions from assoc to list
		foreach ( $projects as &$proj ) {
			$proj['sessions'] = array_values( $proj['sessions'] );
		}
		unset( $proj );

		return array_values( $projects );
	}

	/* ================================================================
	 *  CRUD — Update
	 * ================================================================ */

	/**
	 * Update a memory spec by ID.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public function update( $id, $data ) {
		global $wpdb;

		if ( isset( $data['content'] ) ) {
			$data['content_hash'] = md5( $data['content'] );
		}
		if ( isset( $data['resume_state'] ) && is_array( $data['resume_state'] ) ) {
			$data['resume_state'] = wp_json_encode( $data['resume_state'], JSON_UNESCAPED_UNICODE );
		}

		$result = $wpdb->update( $this->table, $data, array( 'id' => (int) $id ) );
		if ( $result === false ) {
			error_log( '[BizCity Memory] update() failed for id=' . $id . ' — ' . $wpdb->last_error );
			return false;
		}
		return true;
	}

	/* ================================================================
	 *  CRUD — Delete (soft)
	 * ================================================================ */

	/**
	 * Archive a memory spec (soft delete).
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function archive( $id ) {
		return $this->update( (int) $id, array( 'status' => 'archived' ) );
	}

	/**
	 * Hard-delete a memory spec and its logs.
	 *
	 * @param int $id Row ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		$wpdb->delete( $this->table_logs, array( 'memory_id' => (int) $id ) );
		return (bool) $wpdb->delete( $this->table, array( 'id' => (int) $id ) );
	}

	/**
	 * Count memory specs matching filters.
	 *
	 * @param array $filters Same as list_specs().
	 * @return int
	 */
	public function count_specs( $filters = array() ) {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( isset( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( isset( $filters['character_id'] ) ) {
			$where[]  = 'character_id = %d';
			$params[] = (int) $filters['character_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $filters['status'];
		}

		$sql = "SELECT COUNT(*) FROM {$this->table} WHERE " . implode( ' AND ', $where );
		if ( count( $params ) > 0 ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
	}
}
