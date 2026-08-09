<?php
/**
 * BizCity Journal Entry storage.
 *
 * Journal entries are the canonical authoring object. The skills registry and
 * KG source tables are separate runtime/learning projections.
 *
 * @package Bizcity_Twin_AI
 * @subpackage Core\Skills
 * @since 2026-08-02
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

if ( class_exists( 'BizCity_Journal_Database', false ) ) {
	return;
}

final class BizCity_Journal_Database {

	const SCHEMA_VERSION = '1.0.0';
	const SCHEMA_VERSION_KEY = 'bizcity_journal_db_version';
	const CACHE_GROUP = 'journal';

	private static $instance = null;
	private $table;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'bizcity_journal_entries';
	}

	public function get_table(): string {
		return $this->table;
	}

	/**
	 * Install the per-blog Journal table when the central provisioner invokes it.
	 */
	public static function maybe_install(): void {
		self::instance()->ensure_schema();
	}

	private function ensure_schema(): void {
		$stored = get_option( self::SCHEMA_VERSION_KEY, '' );
		if ( self::SCHEMA_VERSION === $stored ) {
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$this->table} (
			id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			owner_user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			workspace_id        VARCHAR(64) NOT NULL DEFAULT 'notion',
			title               VARCHAR(255) NOT NULL DEFAULT '',
			body                LONGTEXT NOT NULL,
			status              ENUM('draft','captured','published','archived') NOT NULL DEFAULT 'draft',
			source_type         VARCHAR(32) NOT NULL DEFAULT 'menu',
			source_ref          VARCHAR(191) NOT NULL DEFAULT '',
			idempotency_key     VARCHAR(191) NOT NULL DEFAULT '',
			revision            BIGINT UNSIGNED NOT NULL DEFAULT 1,
			learning_status     ENUM('not_queued','queued','processing','learned','failed','retryable') NOT NULL DEFAULT 'not_queued',
			learning_error      VARCHAR(500) NOT NULL DEFAULT '',
			notebook_id         BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kg_source_id        BIGINT UNSIGNED NOT NULL DEFAULT 0,
			metadata            LONGTEXT DEFAULT NULL,
			created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_idempotency_key (idempotency_key),
			KEY idx_owner_status (owner_user_id, status),
			KEY idx_workspace_updated (workspace_id, updated_at),
			KEY idx_learning_status (learning_status),
			KEY idx_kg_source (kg_source_id)
		) {$charset};";

		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — provision Journal
		// storage through the WordPress schema path, then stamp the version.
		dbDelta( $sql );
		update_option( self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, true );
	}

	/**
	 * Create or return one entry for an idempotency key.
	 *
	 * @return array|WP_Error
	 */
	public function create( array $data ) {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — Journal save is
		// independent from KG/LLM availability and safe on webhook replay.
		$owner = (int) ( $data['owner_user_id'] ?? 0 );
		$title = sanitize_text_field( (string) ( $data['title'] ?? '' ) );
		$body  = wp_kses_post( (string) ( $data['body'] ?? '' ) );
		$key   = sanitize_text_field( (string) ( $data['idempotency_key'] ?? '' ) );
		if ( $key === '' ) {
			// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — menu-created
			// entries need a unique key even when no replay key is supplied.
			$key = 'generated:' . wp_generate_uuid4();
		}
		if ( $owner <= 0 || $title === '' || trim( wp_strip_all_tags( $body ) ) === '' ) {
			return new WP_Error( 'journal_invalid_entry', 'Nhật ký cần có người sở hữu, tiêu đề và nội dung.' );
		}

		if ( $key !== '' ) {
			$existing = $this->get_by_idempotency_key( $key );
			if ( $existing ) {
				return $existing;
			}
		}

		global $wpdb;
		$inserted = $wpdb->insert( $this->table, $this->normalize_write_data( $data, $owner, $title, $body, $key ) );
		if ( false === $inserted ) {
			if ( $key !== '' ) {
				$existing = $this->get_by_idempotency_key( $key );
				if ( $existing ) {
					return $existing;
				}
			}
			return new WP_Error( 'journal_save_failed', 'Không thể lưu nhật ký.' );
		}

		$id = (int) $wpdb->insert_id;
		$this->flush_cache();
		return $this->get( $id, $owner );
	}

	/**
	 * List entries visible to an owner. Administrators may request all entries.
	 */
	public function list_entries( int $owner_user_id, string $workspace_id = '', int $limit = 50, int $offset = 0, bool $all = false ): array {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — cache reads by blog,
		// owner, workspace, pagination, and visibility scope.
		$limit  = min( 100, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$cache_key = 'entries_' . (int) get_current_blog_id() . '_' . ( $all ? 'all' : (int) $owner_user_id ) . '_' . md5( $workspace_id . ':' . $limit . ':' . $offset );
		$cached = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : array();
		}

		global $wpdb;
		$where = array( "status != 'archived'" );
		$params = array();
		if ( ! $all ) {
			$where[] = 'owner_user_id = %d';
			$params[] = $owner_user_id;
		}
		if ( $workspace_id !== '' ) {
			$where[] = 'workspace_id = %s';
			$params[] = sanitize_key( $workspace_id );
		}
		$params[] = $limit;
		$params[] = $offset;
		$sql = "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: array();
		$this->cache_set( $cache_key, $rows );
		return $rows;
	}

	public function get( int $id, int $owner_user_id = 0, bool $all = false ) {
		if ( $id <= 0 ) {
			return null;
		}
		$cache_key = 'entry_' . (int) get_current_blog_id() . '_' . $id;
		$cached = $this->cache_get( $cache_key );
		$row = false !== $cached ? $cached : null;
		if ( false === $cached ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id ), ARRAY_A );
			$this->cache_set( $cache_key, $row ?: array() );
		}
		if ( ! is_array( $row ) || empty( $row ) ) {
			return null;
		}
		if ( ! $all && $owner_user_id > 0 && (int) $row['owner_user_id'] !== $owner_user_id ) {
			return null;
		}
		return $row;
	}

	public function get_by_idempotency_key( string $key ): ?array {
		$key = trim( $key );
		if ( $key === '' ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE idempotency_key = %s LIMIT 1", $key ), ARRAY_A );
		return $row ?: null;
	}

	/**
	 * Update an owned entry and increment its revision.
	 *
	 * @return array|WP_Error
	 */
	public function update( int $id, int $owner_user_id, array $data, bool $all = false ) {
		// [2026-08-02 Johnny Chu] PHASE-SKILLS-JOURNAL — ownership is checked
		// before any Journal mutation.
		$row = $this->get( $id, $owner_user_id, $all );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy nhật ký.' );
		}
		$write = array();
		if ( array_key_exists( 'title', $data ) ) {
			$write['title'] = sanitize_text_field( (string) $data['title'] );
		}
		if ( array_key_exists( 'body', $data ) ) {
			$write['body'] = wp_kses_post( (string) $data['body'] );
		}
		if ( array_key_exists( 'status', $data ) && in_array( $data['status'], array( 'draft', 'captured', 'published', 'archived' ), true ) ) {
			$write['status'] = $data['status'];
		}
		if ( array_key_exists( 'metadata', $data ) ) {
			$write['metadata'] = is_array( $data['metadata'] ) ? wp_json_encode( $data['metadata'], JSON_UNESCAPED_UNICODE ) : (string) $data['metadata'];
		}
		if ( empty( $write ) ) {
			return $row;
		}
		$write['revision'] = (int) $row['revision'] + 1;
		global $wpdb;
		if ( false === $wpdb->update( $this->table, $write, array( 'id' => $id ) ) ) {
			return new WP_Error( 'journal_update_failed', 'Không thể cập nhật nhật ký.' );
		}
		$this->flush_cache();
		return $this->get( $id, $owner_user_id, $all );
	}

	public function archive( int $id, int $owner_user_id, bool $all = false ) {
		return $this->update( $id, $owner_user_id, array( 'status' => 'archived' ), $all );
	}

	/**
	 * Link a learning projection without changing the Journal body/revision.
	 *
	 * @return array|WP_Error
	 */
	public function mark_learning_projection( int $id, int $owner_user_id, array $projection ) {
		$row = $this->get( $id, $owner_user_id );
		if ( ! $row ) {
			return new WP_Error( 'journal_not_found', 'Không tìm thấy nhật ký.' );
		}

		$status = sanitize_key( (string) ( $projection['learning_status'] ?? 'queued' ) );
		$allowed = array( 'not_queued', 'queued', 'processing', 'learned', 'failed', 'retryable' );
		if ( ! in_array( $status, $allowed, true ) ) {
			$status = 'queued';
		}
		$metadata = $projection['metadata'] ?? null;
		$write = array(
			'learning_status' => $status,
			'learning_error'  => sanitize_text_field( (string) ( $projection['learning_error'] ?? '' ) ),
			'notebook_id'     => (int) ( $projection['notebook_id'] ?? $row['notebook_id'] ),
			'kg_source_id'    => (int) ( $projection['kg_source_id'] ?? $row['kg_source_id'] ),
		);
		if ( null !== $metadata ) {
			$write['metadata'] = is_array( $metadata ) ? wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ) : (string) $metadata;
		}

		global $wpdb;
		if ( false === $wpdb->update( $this->table, $write, array( 'id' => $id ) ) ) {
			return new WP_Error( 'journal_projection_update_failed', 'Không thể cập nhật trạng thái học của nhật ký.' );
		}
		$this->flush_cache();
		return $this->get( $id, $owner_user_id );
	}

	private function normalize_write_data( array $data, int $owner, string $title, string $body, string $key ): array {
		$metadata = $data['metadata'] ?? array();
		return array(
			'owner_user_id'   => $owner,
			'workspace_id'    => sanitize_key( (string) ( $data['workspace_id'] ?? 'notion' ) ) ?: 'notion',
			'title'           => $title,
			'body'            => $body,
			'status'          => in_array( $data['status'] ?? 'draft', array( 'draft', 'captured', 'published' ), true ) ? $data['status'] : 'draft',
			'source_type'     => sanitize_key( (string) ( $data['source_type'] ?? 'menu' ) ) ?: 'menu',
			'source_ref'      => sanitize_text_field( (string) ( $data['source_ref'] ?? '' ) ),
			'idempotency_key' => $key,
			'revision'        => 1,
			'learning_status' => 'not_queued',
			'notebook_id'     => (int) ( $data['notebook_id'] ?? 0 ),
			'kg_source_id'    => (int) ( $data['kg_source_id'] ?? 0 ),
			'metadata'        => is_array( $metadata ) ? wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ) : (string) $metadata,
		);
	}

	private function flush_cache(): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::flush_group( self::CACHE_GROUP );
		}
	}

	private function cache_get( string $key ) {
		if ( class_exists( 'BizCity_Cache' ) ) {
			return BizCity_Cache::get( self::CACHE_GROUP, $key );
		}
		return false;
	}

	private function cache_set( string $key, $value ): void {
		if ( class_exists( 'BizCity_Cache' ) ) {
			BizCity_Cache::set( self::CACHE_GROUP, $key, $value, defined( 'BizCity_Cache::TTL_MEDIUM' ) ? BizCity_Cache::TTL_MEDIUM : 300 );
		}
	}
}

if ( class_exists( 'BizCity_Schema_Registry' ) ) {
	BizCity_Schema_Registry::register(
		'bizcity_journal_entries',
		'core.skills',
		BizCity_Journal_Database::SCHEMA_VERSION,
		BizCity_Journal_Database::SCHEMA_VERSION_KEY,
		array( 'BizCity_Journal_Database', 'maybe_install' )
	);
}
