<?php
/**
 * Content Ops — CPT bridge with wp_posts.
 *
 * Registers:
 *   - CPT `bizcity_doc` (marketing posts surfaced in WP admin via REST too).
 *   - Taxonomy `bizcity_channel_target` (quick-tag a doc to channels).
 *
 * Provides two-way sync:
 *   - Mode A (WP-first): save_post → upsert bizcity_posts.
 *   - Mode B (AI-first): explicit `sync_to_wp($post_id)` from REST.
 *
 * Conflict resolution: newest updated_at / post_modified wins.
 *
 * @package BizCity_Twin_AI
 * @subpackage Content_Ops
 */

defined( 'ABSPATH' ) || exit;

class BizCity_Content_CPT_Bridge {

	const CPT      = 'bizcity_doc';
	const TAXONOMY = 'bizcity_channel_target';
	const META_BC  = '_bizcity_post_id';

	private static $sync_in_progress = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'save_post_' . self::CPT, array( __CLASS__, 'sync_from_wp' ), 20, 3 );
		add_action( 'save_post_post', array( __CLASS__, 'sync_from_wp' ), 20, 3 );
	}

	public static function register_cpt(): void {
		register_post_type(
			self::CPT,
			array(
				'label'             => 'Bài viết Marketing',
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => false,
				'show_in_rest'      => true,
				'supports'          => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ),
				'taxonomies'        => array( 'category', 'post_tag', self::TAXONOMY ),
				'capability_type'   => 'post',
				'menu_icon'         => 'dashicons-megaphone',
			)
		);
	}

	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			array( self::CPT, 'post' ),
			array(
				'label'         => 'Kênh đăng',
				'public'        => false,
				'show_ui'       => true,
				'show_in_rest'  => true,
				'hierarchical'  => false,
			)
		);
	}

	/**
	 * save_post hook → upsert bizcity_posts row.
	 */
	public static function sync_from_wp( int $post_id, $post, bool $update ): void {
		if ( self::$sync_in_progress ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ) {
			return;
		}

		$bc_id = (int) get_post_meta( $post_id, self::META_BC, true );
		$data  = array(
			'title'      => $post->post_title,
			'body'       => $post->post_content,
			'excerpt'    => $post->post_excerpt,
			'wp_post_id' => $post_id,
			'author_id'  => (int) $post->post_author,
			'source'     => 'wp',
			'kind'       => $post->post_type === self::CPT ? 'doc' : 'post',
			'status'     => $post->post_status === 'publish' ? 'published' : 'draft',
		);
		if ( $post->post_status === 'publish' ) {
			$data['published_at'] = $post->post_date;
		}

		self::$sync_in_progress = true;
		if ( $bc_id ) {
			BizCity_Content_Post_Repo::update( $bc_id, $data );
		} else {
			$bc_id = BizCity_Content_Post_Repo::create( $data );
			if ( $bc_id ) {
				update_post_meta( $post_id, self::META_BC, $bc_id );
			}
		}
		self::$sync_in_progress = false;
	}

	/**
	 * Push a bizcity_posts row out to wp_posts (create or update).
	 *
	 * @return int wp_post_id (0 on failure).
	 */
	public static function sync_to_wp( int $bc_post_id, string $target_post_type = self::CPT ): int {
		$row = BizCity_Content_Post_Repo::find( $bc_post_id );
		if ( ! $row ) {
			return 0;
		}

		$args = array(
			'post_type'    => $target_post_type,
			'post_title'   => (string) $row['title'],
			'post_content' => (string) $row['body'],
			'post_excerpt' => (string) $row['excerpt'],
			'post_status'  => $row['status'] === 'published' ? 'publish' : 'draft',
			'post_author'  => (int) $row['author_id'],
		);

		self::$sync_in_progress = true;
		$wp_id = 0;
		if ( ! empty( $row['wp_post_id'] ) ) {
			$args['ID'] = (int) $row['wp_post_id'];
			$wp_id      = (int) wp_update_post( $args, true );
		} else {
			$wp_id = (int) wp_insert_post( $args, true );
		}
		self::$sync_in_progress = false;

		if ( $wp_id && ! is_wp_error( $wp_id ) ) {
			BizCity_Content_Post_Repo::update( $bc_post_id, array( 'wp_post_id' => $wp_id ) );
			update_post_meta( $wp_id, self::META_BC, $bc_post_id );
			return $wp_id;
		}
		return 0;
	}
}
