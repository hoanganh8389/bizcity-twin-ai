<?php
/**
 * Bizcity TwinChat — Notes Service (self-contained).
 *
 * Port of the archived `BCN_Notes` (Companion Notebook) so TwinChat no longer
 * depends on `plugins/_archived/bizcity-companion-notebook/`. Reads/writes the
 * canonical `{prefix}bizcity_memory_notes` table that `BizCity_Memory_Table_Migration`
 * already maintains.
 *
 * Public surface intentionally mirrors what `class-twinchat-notes-controller.php`
 * calls on `BCN_Notes`:
 *   - create( array $data ) : int|WP_Error
 *   - update( int $id, array $data ) : bool
 *   - delete( int $id ) : bool
 *   - get_by_project( string $project_id ) : array
 *   - search_by_keyword( string $project_id, string $keyword, int $limit = 10 ) : array
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Modules\TwinChat\Notes
 * @since      Phase 0.7 — Wave Note-Port-In-Module
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_TwinChat_Notes_Service {

	const TABLE_SUFFIX = 'bizcity_memory_notes';
	const ALLOWED_TYPES = [ 'manual', 'chat_pinned', 'auto_pinned', 'studio_generated', 'research_auto' ];

	/** @return string fully-qualified table name */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Ensure the canonical table exists. Safe to call frequently — gated by a
	 * static cache so we only run `SHOW TABLES LIKE` once per request.
	 */
	public static function ensure_table(): bool {
		static $checked = null;
		if ( $checked !== null ) return $checked;

		global $wpdb;
		$table = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists ) {
			$checked = true;
			return true;
		}

		// Create on-the-fly using the same schema the Companion Notebook plugin
		// used to install. Keeps parity with archived `BCN_Schema_Extend`.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			project_id VARCHAR(50) NOT NULL DEFAULT '',
			session_id VARCHAR(128) NOT NULL DEFAULT '',
			message_id BIGINT UNSIGNED DEFAULT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			content LONGTEXT,
			source_excerpt TEXT,
			tags VARCHAR(500) NOT NULL DEFAULT '[]',
			created_by VARCHAR(10) NOT NULL DEFAULT 'user',
			note_type VARCHAR(30) NOT NULL DEFAULT 'manual',
			is_starred TINYINT(1) NOT NULL DEFAULT 0,
			metadata LONGTEXT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_project (project_id),
			KEY idx_user (user_id),
			KEY idx_session (session_id),
			KEY idx_message (message_id),
			KEY idx_note_type (note_type)
		) {$charset};";

		dbDelta( $sql );

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$checked = (bool) $exists;
		return $checked;
	}

	// ── CRUD ───────────────────────────────────────────────────────────

	public function create( array $data ) {
		global $wpdb;
		self::ensure_table();

		$project_id = sanitize_text_field( $data['project_id'] ?? '' );
		$title      = sanitize_text_field( $data['title'] ?? '' );
		$content    = wp_kses_post( $data['content'] ?? '' );
		$note_type  = sanitize_text_field( $data['note_type'] ?? 'manual' );

		if ( ! in_array( $note_type, self::ALLOWED_TYPES, true ) ) {
			$note_type = 'manual';
		}
		if ( ! $title ) {
			$title = mb_substr( wp_strip_all_tags( $content ), 0, 80 ) ?: 'Ghi chú';
		}

		$ok = $wpdb->insert( self::table(), [
			'user_id'    => get_current_user_id(),
			'project_id' => $project_id,
			'session_id' => sanitize_text_field( $data['session_id'] ?? '' ),
			'message_id' => absint( $data['message_id'] ?? 0 ) ?: null,
			'title'      => $title,
			'content'    => $content,
			'note_type'  => $note_type,
			'is_starred' => ! empty( $data['is_starred'] ) ? 1 : 0,
			'metadata'   => wp_json_encode( $data['metadata'] ?? [] ),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		] );

		if ( $ok === false ) {
			return new WP_Error( 'db_error', 'Could not create note: ' . $wpdb->last_error );
		}

		$id = (int) $wpdb->insert_id;
		do_action( 'bcn_note_created', $id, $project_id );
		// Wave 2.8d D5 — dual-write mirror into unified `bizcity_memory`.
		do_action( 'bizcity_memory_mirror_write', 'note', [
			'id'         => $id,
			'blog_id'    => get_current_blog_id(),
			'user_id'    => get_current_user_id(),
			'session_id' => sanitize_text_field( $data['session_id'] ?? '' ),
			'project_id' => $project_id,
			'title'      => $title,
			'content'    => $content,
			'note_type'  => $note_type,
			'is_starred' => ! empty( $data['is_starred'] ) ? 1 : 0,
			'metadata'   => wp_json_encode( $data['metadata'] ?? [] ),
		], 'insert' );
		return $id;
	}

	public function update( $id, array $data ): bool {
		global $wpdb;
		self::ensure_table();

		$update = [ 'updated_at' => current_time( 'mysql' ) ];
		if ( isset( $data['title'] ) )      $update['title']      = sanitize_text_field( $data['title'] );
		if ( isset( $data['content'] ) )    $update['content']    = wp_kses_post( $data['content'] );
		if ( isset( $data['is_starred'] ) ) $update['is_starred'] = (int) $data['is_starred'];

		$result = (bool) $wpdb->update( self::table(), $update, [
			'id'      => (int) $id,
			'user_id' => get_current_user_id(),
		] );

		if ( $result ) {
			$project_id = $data['project_id'] ?? $this->get_project_id( (int) $id );
			if ( $project_id ) {
				do_action( 'bcn_note_updated', (int) $id, $project_id );
			}
		}

		return $result;
	}

	public function delete( $id ): bool {
		global $wpdb;
		self::ensure_table();

		$project_id = $this->get_project_id( (int) $id );

		$result = (bool) $wpdb->delete( self::table(), [
			'id'      => (int) $id,
			'user_id' => get_current_user_id(),
		] );

		if ( $result && $project_id ) {
			do_action( 'bcn_note_deleted', (int) $id, $project_id );
		}

		return $result;
	}

	public function get_by_project( $project_id ): array {
		global $wpdb;
		self::ensure_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE project_id = %s ORDER BY created_at DESC",
			(string) $project_id
		) );
		return is_array( $rows ) ? $rows : [];
	}

	public function search_by_keyword( $project_id, $keyword, $limit = 10 ): array {
		global $wpdb;
		self::ensure_table();

		$keyword = trim( (string) $keyword );
		if ( ! $keyword ) return $this->get_by_project( $project_id );

		$like = '%' . $wpdb->esc_like( $keyword ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . "
			 WHERE project_id = %s AND (title LIKE %s OR content LIKE %s OR tags LIKE %s)
			 ORDER BY FIELD(note_type, 'chat_pinned', 'manual', 'auto_pinned', 'research_auto'), created_at DESC
			 LIMIT %d",
			(string) $project_id, $like, $like, $like, (int) $limit
		) );
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Fetch ALL notes for a given user (home / Ask Brain context — no notebook filter).
	 * Returns newest-first, capped at $limit.
	 */
	public function get_all_by_user( int $user_id, int $limit = 200 ): array {
		global $wpdb;
		self::ensure_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::table() . " WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
			$user_id,
			max( 1, min( 500, $limit ) )
		) );
		return is_array( $rows ) ? $rows : [];
	}

	private function get_project_id( int $note_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT project_id FROM " . self::table() . " WHERE id = %d LIMIT 1",
			$note_id
		) );
	}
}
