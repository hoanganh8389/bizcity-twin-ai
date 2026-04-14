<?php
/**
 * CRUD for bizcity_creator_files table.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BZCC_File_Manager {

	/* ── Read ── */

	public static function get_by_id( int $id ): ?object {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	public static function get_by_user( int $user_id, string $status = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		$where = $wpdb->prepare( "WHERE user_id = %d", $user_id );
		if ( $status ) {
			$where .= $wpdb->prepare( " AND status = %s", $status );
		}

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} {$where} ORDER BY updated_at DESC LIMIT %d OFFSET %d",
			$limit,
			$offset
		) );
	}

	public static function get_by_intent_conversation( string $conv_id ): ?object {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE intent_conversation_id = %s ORDER BY id DESC LIMIT 1",
			$conv_id
		) );
	}

	public static function get_by_project( string $project_id ): array {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$t} WHERE project_id = %s ORDER BY created_at ASC",
			$project_id
		) );
	}

	/* ── Write ── */

	public static function insert( array $data ): int {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		$data = self::sanitize( $data );

		// Defaults for NOT NULL LONGTEXT columns
		$data = array_merge( [
			'form_data' => '{}',
			'outline'   => '',
		], $data );

		$data['created_at'] = current_time( 'mysql', true );
		$data['updated_at'] = $data['created_at'];

		if ( empty( $data['user_id'] ) ) {
			$data['user_id'] = get_current_user_id();
		}

		$result = $wpdb->insert( $t, $data );
		if ( $result === false ) {
			error_log( 'BZCC_File_Manager::insert failed: ' . $wpdb->last_error );
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		$data = self::sanitize( $data );
		$data['updated_at'] = current_time( 'mysql', true );

		return (bool) $wpdb->update( $t, $data, [ 'id' => $id ] );
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		return (bool) $wpdb->delete( $t, [ 'id' => $id ] );
	}

	/* ── Lifecycle ── */

	/**
	 * Get files with template + category metadata (for history page).
	 */
	public static function get_by_user_with_meta( int $user_id, int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$t_files    = BZCC_Installer::table_files();
		$t_tpl      = BZCC_Installer::table_templates();
		$t_cat      = BZCC_Installer::table_categories();
		$t_chunk    = BZCC_Installer::table_chunk_meta();

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT f.*,
			        t.title        AS template_title,
			        t.icon_emoji   AS template_emoji,
			        t.slug         AS template_slug,
			        c.title        AS category_name,
			        (SELECT GROUP_CONCAT(DISTINCT cm.platform)
			         FROM {$t_chunk} cm WHERE cm.file_id = f.id) AS platforms_csv
			 FROM {$t_files} f
			 LEFT JOIN {$t_tpl} t ON t.id = f.template_id
			 LEFT JOIN {$t_cat} c ON c.id = t.category_id
			 WHERE f.user_id = %d
			 ORDER BY f.updated_at DESC
			 LIMIT %d OFFSET %d",
			$user_id,
			$limit,
			$offset
		) );
	}

	/**
	 * Count total files for a user.
	 */
	public static function count_by_user( int $user_id ): int {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$t} WHERE user_id = %d",
			$user_id
		) );
	}

	/**
	 * Search files with filters (for REST API).
	 */
	public static function search_by_user( int $user_id, string $status = '', string $search = '', int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$t_files = BZCC_Installer::table_files();
		$t_tpl   = BZCC_Installer::table_templates();
		$t_cat   = BZCC_Installer::table_categories();
		$t_chunk = BZCC_Installer::table_chunk_meta();

		$where = 'WHERE f.user_id = %d';
		$args  = [ $user_id ];

		if ( $status ) {
			$where .= ' AND f.status = %s';
			$args[] = $status;
		}

		if ( $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where  .= ' AND (f.title LIKE %s OR t.title LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}

		$args[] = $limit;
		$args[] = $offset;

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT f.*,
			        t.title      AS template_title,
			        t.icon_emoji AS template_emoji,
			        c.title      AS category_name,
			        (SELECT GROUP_CONCAT(DISTINCT cm.platform)
			         FROM {$t_chunk} cm WHERE cm.file_id = f.id) AS platforms_csv
			 FROM {$t_files} f
			 LEFT JOIN {$t_tpl} t ON t.id = f.template_id
			 LEFT JOIN {$t_cat} c ON c.id = t.category_id
			 {$where}
			 ORDER BY f.updated_at DESC
			 LIMIT %d OFFSET %d",
			...$args
		) );
	}

	public static function increment_chunk_done( int $id ): void {
		global $wpdb;
		$t = BZCC_Installer::table_files();

		$wpdb->query( $wpdb->prepare(
			"UPDATE {$t} SET chunk_done = chunk_done + 1, updated_at = %s WHERE id = %d",
			current_time( 'mysql', true ),
			$id
		) );

		// Auto-complete when all chunks done
		$file = self::get_by_id( $id );
		if ( $file && $file->chunk_count > 0 && $file->chunk_done >= $file->chunk_count ) {
			self::update( $id, [ 'status' => 'completed' ] );
		}
	}

	public static function set_outline( int $id, string $outline_json, int $chunk_count ): bool {
		return self::update( $id, [
			'outline'        => $outline_json,
			'outline_status' => 'approved',
			'chunk_count'    => $chunk_count,
			'status'         => 'generating',
		] );
	}

	/* ── Sanitize ── */

	private static function sanitize( array $data ): array {
		$allowed = [
			'user_id', 'template_id', 'project_id', 'session_id',
			'intent_conversation_id', 'form_data', 'outline',
			'outline_status', 'memory_spec_id', 'title',
			'status', 'chunk_count', 'chunk_done',
			'created_at', 'updated_at',
		];

		return array_intersect_key( $data, array_flip( $allowed ) );
	}
}
