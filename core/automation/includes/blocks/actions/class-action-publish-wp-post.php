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
				'image_urls' => '{{consume_attachment.attachment_urls}}',
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
				array( 'name' => 'image_urls', 'label' => 'Nhiều ảnh (JSON/CSV)', 'type' => 'textarea' ),
				array( 'name' => 'status',    'label' => 'Trạng thái',   'type' => 'select', 'options' => self::STATUS_OPTIONS ),
				array( 'name' => 'category',  'label' => 'Slug danh mục (CSV)', 'type' => 'text' ),
				array( 'name' => 'tags',      'label' => 'Tags (CSV)',   'type' => 'text' ),
				array( 'name' => 'author_id', 'label' => 'Author ID (0 = trigger.wp_user_id)', 'type' => 'number' ),
			),
		);
	}

	public function execute( array $ctx, array $data ) {
		// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — attach WP draft/publish outcome to My Content artifact.
		$content_artifact_id = class_exists( 'BizCity_Content_Artifact_Service' )
			? BizCity_Content_Artifact_Service::create_or_get_from_ctx( $ctx, array( 'content_type' => 'wp_post' ) )
			: 0;
		$title   = trim( (string) $this->resolve( $data['title']   ?? '', $ctx ) );
		$content = (string) $this->resolve( $data['content'] ?? '', $ctx );
		$image_urls = $this->resolve_image_urls( $ctx, $data );
		$image   = (string) ( $image_urls[0] ?? '' );
		$status  = (string) ( $data['status'] ?? 'draft' );
		if ( ! in_array( $status, self::STATUS_OPTIONS, true ) ) { $status = 'draft'; }

		if ( $title === '' && $content === '' ) {
			if ( $content_artifact_id > 0 ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'empty_post', '_bizcity_error_message' => 'publish_wp_post: title + content rỗng.' ) );
			}
			return new WP_Error( 'empty_post', 'publish_wp_post: title + content rỗng.' );
		}

		$author = (int) ( $data['author_id'] ?? 0 );
		if ( $author === 0 ) {
			// [2026-07-16 Johnny Chu] PHASE-TWINWEB F4 — enforce owner continuity; do not infer author from current session user.
			$author = $this->resolve_owner_user_id( $ctx );
		}
		if ( $author <= 0 ) {
			if ( $content_artifact_id > 0 ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => 'owner_missing', '_bizcity_error_message' => 'publish_wp_post: không resolve được owner user_id.' ) );
			}
			return new WP_Error( 'owner_missing', 'publish_wp_post: không resolve được owner user_id.' );
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
		if ( is_wp_error( $post_id ) ) {
			if ( $content_artifact_id > 0 ) {
				BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'failed', array( '_bizcity_error_code' => $post_id->get_error_code(), '_bizcity_error_message' => $post_id->get_error_message() ) );
			}
			return $post_id;
		}

		// Featured image + gallery sideload.
		$attach_id = 0;
		$attach_ids = array();
		if ( ! empty( $image_urls ) ) {
			// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — sideload all canonical images, first image remains featured image for backwards compatibility.
			if ( ! function_exists( 'media_sideload_image' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}
			foreach ( $image_urls as $idx => $img_url ) {
				if ( ! filter_var( $img_url, FILTER_VALIDATE_URL ) ) { continue; }
				$res = media_sideload_image( $img_url, $post_id, ( $title ?: 'automation-image' ) . '-' . ( $idx + 1 ), 'id' );
				if ( ! is_wp_error( $res ) ) {
					$aid = (int) $res;
					$attach_ids[] = $aid;
					if ( $attach_id <= 0 ) {
						$attach_id = $aid;
						set_post_thumbnail( $post_id, $attach_id );
					}
				} else {
					$this->debug( 'sideload_image failed: ' . $res->get_error_message() );
				}
			}
			if ( count( $attach_ids ) > 1 && strpos( $content, '[gallery' ) === false ) {
				$content .= "\n\n" . '[gallery ids="' . implode( ',', array_map( 'intval', $attach_ids ) ) . '"]';
				wp_update_post( array( 'ID' => (int) $post_id, 'post_content' => $content ) );
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

		if ( $content_artifact_id > 0 ) {
			BizCity_Content_Artifact_Service::mark_stage( $content_artifact_id, 'wp_draft_ready', array(
				'_bizcity_wp_post_id'  => (int) $post_id,
				'_bizcity_wp_edit_url' => $edit_url,
			) );
			BizCity_Content_Artifact_Service::append_trace( $content_artifact_id, array(
				'stage' => 'wp_draft_ready', 'source' => 'automation', 'block_id' => $this->id(), 'status' => 'ok',
				'run_id' => (string) ( $ctx['_run_id'] ?? '' ), 'workflow_id' => (int) ( $ctx['_workflow_id'] ?? 0 ),
				'message' => 'WordPress post created.', 'ctx' => array( 'post_id' => (int) $post_id, 'status' => $status, 'attachment_ids' => $attach_ids ),
			) );
		}

		return array(
			'post_id'      => (int) $post_id,
			'title'        => $saved_title,
			'status'       => $status,
			'edit_url'     => $edit_url,
			'permalink'    => (string) get_permalink( $post_id ),
			'attachment_id'=> $attach_id,
			'attachment_ids' => $attach_ids,
			'image_urls'   => $image_urls,
			'event_id'     => $this->mirror_to_scheduler( $ctx, (int) $post_id, $saved_title, $content, $status, $image, $image_urls, $author ),
		);
	}

	/**
	 * Mirror the published post into bizcity_crm_events so the Scheduler page
	 * shows the workflow output. Status='done' (already published) so cron
	 * skips it; metadata holds canonical web_post_* fields for parity with
	 * BizCity_Web_Post_Publisher contract.
	 */
	private function mirror_to_scheduler( array $ctx, int $post_id, string $title, string $content, string $wp_status, string $image, array $images, int $author ): int {
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
				// [2026-08-13 Johnny Chu] HOTFIX-WEB-POST-METADATA — Scheduler web_post requires the rendered body for CRM bridge validation.
				'web_content'        => $content,
				'web_status'         => $wp_status,
				'web_image_url'      => $image,
				'web_image_urls'     => $images,
				'web_permalink'      => (string) get_permalink( $post_id ),
				'web_edit_link'      => (string) get_edit_post_link( $post_id, '' ),
				'web_publish_status' => 'published',
				// [2026-07-21 Johnny Chu] PHASE-2-TWIN-GPT-MY-CONTENT-TRACE — keep scheduler web_post projection attached to the existing My Plan artifact.
				'content_id'         => class_exists( 'BizCity_Content_Artifact_Service' ) ? BizCity_Content_Artifact_Service::create_or_get_from_ctx( $ctx, array( 'content_type' => 'wp_post' ) ) : 0,
			) ),
		);
		return BizCity_Automation_CRM_Bridge::create_event( $payload );
	}

	private function resolve_image_urls( array $ctx, array $data ): array {
		// [2026-07-21 Johnny Chu] R-AUTO-MULTI-ATTACH — WordPress posts consume canonical attachment_urls[] and keep image_url as first-image fallback.
		$urls = array();
		if ( array_key_exists( 'image_urls', $data ) ) { $this->append_url_value( $this->resolve( $data['image_urls'], $ctx ), $urls ); }
		if ( array_key_exists( 'image_url', $data ) ) { $this->append_url_value( $this->resolve( $data['image_url'], $ctx ), $urls ); }
		foreach ( array( 'consume', 'consume_attachment', 'capture', 'trigger' ) as $key ) {
			if ( isset( $ctx[ $key ] ) && is_array( $ctx[ $key ] ) ) { $this->append_url_value( $ctx[ $key ]['attachment_urls'] ?? array(), $urls ); }
		}
		if ( isset( $ctx['trigger']['_resume'] ) && is_array( $ctx['trigger']['_resume'] ) ) { $this->append_url_value( $this->extract_urls_from_state( $ctx['trigger']['_resume'] ), $urls ); }
		$out = array();
		foreach ( $urls as $url ) { $url = trim( (string) $url ); if ( $url !== '' && filter_var( $url, FILTER_VALIDATE_URL ) ) { $out[ $url ] = $url; } }
		return array_values( $out );
	}

	private function extract_urls_from_state( array $state ): array {
		$urls = array();
		$this->append_url_value( $state['attachment_urls'] ?? array(), $urls );
		if ( ! empty( $state['attachments'] ) && is_array( $state['attachments'] ) ) {
			foreach ( $state['attachments'] as $item ) { if ( is_array( $item ) ) { $this->append_url_value( $item['wp_url'] ?? $item['url'] ?? $item['source_url'] ?? '', $urls ); } }
		}
		$this->append_url_value( $state['attachment_url'] ?? '', $urls );
		return $urls;
	}

	private function append_url_value( $value, array &$urls ): void {
		if ( is_array( $value ) ) { foreach ( $value as $item ) { $this->append_url_value( $item, $urls ); } return; }
		$value = trim( (string) $value );
		if ( $value === '' || strpos( $value, '{{' ) !== false ) { return; }
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) { $this->append_url_value( $decoded, $urls ); return; }
		foreach ( preg_split( '/[\r\n,]+/', $value ) as $part ) { $part = trim( (string) $part ); if ( $part !== '' ) { $urls[] = $part; } }
	}
}
