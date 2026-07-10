<?php
/**
 * Action: Publish WordPress post (default: draft for manual review).
 *
 * Resolves title/content/featured image from ctx (typical pattern: LLM compose
 * upstream → tokens). Optional sideload featured image from a remote URL
 * (giải quyết case "đăng bài về web kèm ảnh user gửi từ Zalo turn trước").
 *
 * Output: { post_id, status, edit_url, permalink }.
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Automation\Blocks\Actions
 * @since      AUTOMATION BE-7.C (2026-05-30)
 */

defined( 'ABSPATH' ) || exit;

final class BizCity_Automation_Action_Publish_WP_Post extends BizCity_Automation_Block_Base {

	const STATUS_OPTIONS = array( 'draft', 'pending', 'publish' );

	public function id(): string   { return 'action.publish_wp_post'; }
	public function kind(): string { return 'action'; }

	public function meta(): array {
		return array(
			'label'    => 'Đăng bài WordPress',
			'short'    => 'publish_wp_post',
			'category' => 'output',
			'color'    => '#0e7490',
			'icon'     => 'newspaper',
			'defaults' => array(
				'label'      => 'publish_wp_post',
				'title'      => '{{llm.title}}',
				'content'    => '{{llm.content}}',
				'image_url'  => '{{consume_attachment.attachment_url}}',
				'status'     => 'draft',
				'category'   => '',
				'tags'       => '',
				'author_id'  => 0,
			),
			'fields' => array(
				array( 'name' => 'label',     'label' => 'Tên hiển thị', 'type' => 'text' ),
				array( 'name' => 'title',     'label' => 'Tiêu đề',      'type' => 'text' ),
				array( 'name' => 'content',   'label' => 'Nội dung',     'type' => 'textarea' ),
				array( 'name' => 'image_url', 'label' => 'Ảnh đại diện (URL)', 'type' => 'text' ),
				array( 'name' => 'status',    'label' => 'Trạng thái',   'type' => 'select', 'options' => self::STATUS_OPTIONS ),
				array( 'name' => 'category',  'label' => 'Slug danh mục (CSV)', 'type' => 'text' ),
				array( 'name' => 'tags',      'label' => 'Tags (CSV)',   'type' => 'text' ),
				array( 'name' => 'author_id', 'label' => 'Author ID (0 = trigger.wp_user_id)', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		$title   = trim( (string) $this->resolve( $data['title']   ?? '', $ctx ) );
		$content = (string) $this->resolve( $data['content'] ?? '', $ctx );
		$image   = trim( (string) $this->resolve( $data['image_url'] ?? '', $ctx ) );
		$status  = (string) ( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, self::STATUS_OPTIONS, true ) ) { $status = 'draft'; }

		if ( $title === '' && $content === '' ) {
			return new WP_Error( 'empty_post', 'publish_wp_post: title + content rỗng.' );
		}

		$author = (int) ( $data['author_id'] ?? 0 );
		if ( $author === 0 ) {
			$author = (int) ( $ctx['trigger']['wp_user_id'] ?? 0 );
		}
		if ( $author === 0 ) {
			$author = get_current_user_id();
		}

		$postarr = array(
			'post_title'   => $title !== '' ? $title : wp_trim_words( $content, 12, '…' ),
			'post_content' => $content,
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => $author,
		);

		// Categories — ensure terms exist (slug or name).
		$cat_csv = trim( (string) ( $data['category'] ?? '' ) );
		if ( $cat_csv !== '' ) {
			$cat_ids = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $cat_csv ) ) ) as $slug ) {
				$term = get_term_by( 'slug', sanitize_title( $slug ), 'category' );
				if ( ! $term ) {
					$created = wp_insert_term( $slug, 'category' );
					if ( ! is_wp_error( $created ) ) { $cat_ids[] = (int) $created['term_id']; }
				} else {
					$cat_ids[] = (int) $term->term_id;
				}
			}
			if ( ! empty( $cat_ids ) ) { $postarr['post_category'] = $cat_ids; }
		}

		// Tags.
		$tags = trim( (string) ( $data['tags'] ?? '' ) );
		if ( $tags !== '' ) {
			$postarr['tags_input'] = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) { return $post_id; }

		// Featured image sideload.
		$attach_id = 0;
		if ( $image !== '' && filter_var( $image, FILTER_VALIDATE_URL ) ) {
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			$res = media_sideload_image( $image, $post_id, $title ?: '', 'id' );
			if ( ! is_wp_error( $res ) ) {
				$attach_id = (int) $res;
				set_post_thumbnail( $post_id, $attach_id );
			} else {
				$this->debug( 'sideload_image failed: ' . $res->get_error_message() );
			}
		}

		// PG-S9-fix v3 — get_edit_post_link() returns null khi không có
		// current_user_id (channel inbound chạy unauthenticated). Build URL
		// trực tiếp để reply không bao giờ in "null".
		$edit_url = admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
		if ( is_multisite() ) {
			$edit_url = get_admin_url( get_current_blog_id(),
				'post.php?post=' . (int) $post_id . '&action=edit' );
		}
		// Đọc lại title thật từ post (trường hợp title rỗng → WP tự trim từ content).
		$saved_title = get_the_title( $post_id );
		if ( $saved_title === '' ) { $saved_title = $title; }

		return array(
			'post_id'      => (int) $post_id,
			'title'        => $saved_title,
			'status'       => $status,
			'edit_url'     => $edit_url,
			'permalink'    => (string) get_permalink( $post_id ),
			'attachment_id'=> $attach_id,
			'event_id'     => $this->mirror_to_scheduler( $ctx, (int) $post_id, $saved_title, $status, $image, $author ),
		);
	}

	/**
	 * Mirror the published post into bizcity_crm_events so the Scheduler page
	 * shows the workflow output. Status='done' (already published) so cron
	 * skips it; metadata holds canonical web_post_* fields for parity with
	 * BizCity_Web_Post_Publisher contract.
	 */
	private function mirror_to_scheduler( array $ctx, int $post_id, string $title, string $wp_status, string $image, int $author ): int {
		if ( $post_id <= 0 || ! class_exists( 'BizCity_Automation_CRM_Bridge' ) ) {
			return 0;
		}
		$payload = array(
			'event_type'  => 'web_post',
			'title'       => '[automation] Web post: ' . ( $title !== '' ? $title : "#{$post_id}" ),
			'description' => '',
			'start_at'    => current_time( 'mysql' ),
			'status'      => 'done',
			'source'      => 'workflow',
			'user_id'     => $author,
			'related_id'  => $ctx['_run_id'] ?? '',
			'workflow_id' => $ctx['_workflow_id'] ?? 0,
			// [2026-06-03 Johnny Chu] R-SCH-REPLY — forward inbound{} qua helper.
			'metadata'    => $this->build_event_metadata( $ctx, array(
				'web_post_id'        => $post_id,
				'web_title'          => $title,
				'web_status'         => $wp_status,
				'web_image_url'      => $image,
				'web_permalink'      => (string) get_permalink( $post_id ),
				'web_edit_link'      => (string) get_edit_post_link( $post_id, '' ),
				'web_publish_status' => 'published',
			) ),
		);
		return BizCity_Automation_CRM_Bridge::create_event( $payload );
	}
}
