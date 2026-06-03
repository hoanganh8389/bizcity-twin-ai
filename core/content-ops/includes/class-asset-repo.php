<?php
/**
 * Content Ops — Brand Asset repo
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_Asset_Repo {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bizcity_brand_assets';
	}

	public static function query( array $args = array() ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['blog_id'] ) ) {
			$where[]  = 'blog_id=%d';
			$params[] = (int) $args['blog_id'];
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type=%s';
			$params[] = (string) $args['type'];
		}

		$limit  = isset( $args['per_page'] ) ? max( 1, min( 200, (int) $args['per_page'] ) ) : 40;
		$page   = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset = ( $page - 1 ) * $limit;

		$sql      = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return array(
			'items' => $rows ?: array(),
			'page'  => $page,
		);
	}

	public static function create( array $data ): int {
		global $wpdb;
		$data = wp_parse_args(
			$data,
			array(
				'blog_id'    => get_current_blog_id(),
				'type'       => 'image',
				'url'        => '',
				'mime'       => '',
				'title'      => '',
				'tags_json'  => null,
				'source'     => 'upload',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}
}
