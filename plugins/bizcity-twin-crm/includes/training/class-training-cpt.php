<?php
/**
 * BizCity CRM — Training Doc CPT (PHASE-0.57 T1-01).
 *
 * `bzcrm_training_doc` holds the editable content behind the built-in
 * "Hướng dẫn dùng Twin CRM" training notebook. Admin edits the post like any
 * other WordPress post; the seeder (class-training-seeder.php) syncs
 * publish/trash state into the KG notebook source.
 *
 * @package BizCity_Twin_CRM
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BizCity_CRM_Training_CPT', false ) ) {
	return;
}

final class BizCity_CRM_Training_CPT {

	const POST_TYPE  = 'bzcrm_training_doc';
	const META_DOC_ID          = '_bzcrm_training_doc_id';
	const META_TEMPLATE_HASH   = '_bzcrm_training_template_hash';

	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 5 );
	}

	// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T1-01 — admin/manage_options-only training doc CPT, nested under the CRM menu.
	public static function register_post_type(): void {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type( self::POST_TYPE, array(
			'labels'              => array(
				'name'          => __( 'Tài liệu đào tạo', 'bizcity-twin-crm' ),
				'singular_name' => __( 'Tài liệu đào tạo', 'bizcity-twin-crm' ),
				'add_new_item'  => __( 'Thêm tài liệu đào tạo', 'bizcity-twin-crm' ),
				'edit_item'     => __( 'Sửa tài liệu đào tạo', 'bizcity-twin-crm' ),
				'all_items'     => __( 'Tài liệu đào tạo', 'bizcity-twin-crm' ),
				'search_items'  => __( 'Tìm tài liệu đào tạo', 'bizcity-twin-crm' ),
				'not_found'     => __( 'Chưa có tài liệu đào tạo nào.', 'bizcity-twin-crm' ),
			),
			'public'              => false,
			'show_ui'             => true,
			'show_in_menu'        => 'bizcity-crm',
			'show_in_rest'        => false,
			'supports'            => array( 'title', 'editor', 'revisions' ),
			'menu_icon'           => 'dashicons-welcome-learn-more',
			'hierarchical'        => false,
			'map_meta_cap'        => true,
			// [2026-09-19 Johnny Chu - Chu Hoàng Anh] PHASE-0.57 T1-01 — keep editing admin-only for wave 1; supervisor authoring is a documented simplification, not a blocker for the core seed/ingest flow.
			'capabilities'        => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
				'delete_posts'       => 'manage_options',
			),
		) );
	}

	/**
	 * Find a training doc post by its stable template doc_id (any status).
	 *
	 * @return WP_Post|null
	 */
	public static function find_by_doc_id( string $doc_id ) {
		$doc_id = sanitize_key( $doc_id );
		if ( '' === $doc_id ) {
			return null;
		}
		$posts = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'trash' ),
			'meta_key'       => self::META_DOC_ID,
			'meta_value'     => $doc_id,
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		return $posts ? $posts[0] : null;
	}
}
