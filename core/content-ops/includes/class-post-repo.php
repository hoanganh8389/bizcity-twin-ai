<?php
/**
 * Content Ops — Repo helpers (low-level CRUD)
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_Post_Repo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_posts';
	}

	public static function targets_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_post_targets';
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d AND deleted_at IS NULL', $id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	public static function query( array $args = array() ): array {
		global $wpdb;
		$where  = array( 'deleted_at IS NULL' );
		$params = array();

		if ( ! empty( $args['blog_id'] ) ) {
			$where[]  = 'blog_id=%d';
			$params[] = (int) $args['blog_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status=%s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source=%s';
			$params[] = (string) $args['source'];
		}
		if ( ! empty( $args['q'] ) ) {
			$where[]  = '(title LIKE %s OR body LIKE %s)';
			$like     = '%' . $wpdb->esc_like( (string) $args['q'] ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$limit  = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 25;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset = ( $page - 1 ) * $limit;

		$sql       = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[]  = $limit;
		$params[]  = $offset;
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where );
		$count     = (int) $wpdb->get_var(
			count( $params ) > 2 ? $wpdb->prepare( $count_sql, array_slice( $params, 0, -2 ) ) : $count_sql
		);

		return array(
			'items' => $rows ?: array(),
			'total' => $count,
			'page'  => $page,
			'pages' => (int) ceil( $count / max( 1, $limit ) ),
		);
	}

	public static function create( array $data ): int {
		global $wpdb;
		$now  = current_time( 'mysql' );
		$data = wp_parse_args(
			$data,
			array(
				'blog_id'      => get_current_blog_id(),
				'author_id'    => get_current_user_id(),
				'title'        => '',
				'body'         => '',
				'excerpt'      => '',
				'media_json'   => null,
				'kind'         => 'post',
				'status'       => 'draft',
				'source'       => 'manual',
				'tone'         => '',
				'meta_json'    => null,
				'wp_post_id'   => null,
				'scheduled_at' => null,
				'created_at'   => $now,
				'updated_at'   => $now,
			)
		);
		$data['content_hash'] = self::hash_content( $data );

		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at']   = current_time( 'mysql' );
		if ( isset( $data['title'] ) || isset( $data['body'] ) ) {
			$current              = self::find( $id );
			$merged               = array_merge( (array) $current, $data );
			$data['content_hash'] = self::hash_content( $merged );
		}
		return (bool) $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	public static function soft_delete( int $id ): bool {
		return self::update( $id, array( 'deleted_at' => current_time( 'mysql' ), 'status' => 'archived' ) );
	}

	public static function hash_content( array $row ): string {
		return hash( 'sha256', (string) ( $row['title'] ?? '' ) . '|' . (string) ( $row['body'] ?? '' ) );
	}

	/* ---------- targets ---------- */

	public static function list_targets( int $post_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::targets_table() . ' WHERE post_id=%d ORDER BY id ASC', $post_id ),
			ARRAY_A
		);
		return $rows ?: array();
	}

	public static function attach_target( int $post_id, string $platform, string $instance_id ): int {
		global $wpdb;
		$wpdb->insert(
			self::targets_table(),
			array(
				'post_id'        => $post_id,
				'blog_id'        => get_current_blog_id(),
				'platform'       => $platform,
				'instance_id'    => $instance_id,
				'publish_status' => 'pending',
				'created_at'     => current_time( 'mysql' ),
				'updated_at'     => current_time( 'mysql' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function delete_target( int $target_id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::targets_table(), array( 'id' => $target_id ) );
	}

	public static function update_target( int $target_id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return (bool) $wpdb->update( self::targets_table(), $data, array( 'id' => $target_id ) );
	}
}
