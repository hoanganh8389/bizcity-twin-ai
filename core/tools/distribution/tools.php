<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Tools
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Distribution / Delivery Tool Implementations
 *
 * Each function receives content from upstream pipeline nodes.
 * These tools DELIVER — not generate — content.
 *
 * @package BizCity\TwinAI\Tools\Distribution
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;

/* ================================================================
 * POST TO FACEBOOK
 * ================================================================ */

/**
 * @param array $slots  { content: string, image_url?: string }
 * @return array
 */
function bizcity_dist_post_facebook( array $slots ): array {
	$content   = trim( $slots['content'] ?? '' );
	$image_url = trim( $slots['image_url'] ?? '' );
	// Optional WordPress post URL (from upstream publish_wp_post node)
	$post_url  = trim( $slots['link'] ?? $slots['post_url'] ?? '' );

	if ( empty( $content ) ) {
		return [ 'success' => false, 'error' => 'Missing content to post.' ];
	}

	// Append post URL as CTA if available
	if ( $post_url && filter_var( $post_url, FILTER_VALIDATE_URL ) ) {
		$content = rtrim( $content ) . "\n\n🔗 Đọc thêm: " . $post_url;
	}

	// Multi-page posting via standalone BizCity_FB_Graph_API (no bizcity-facebook-bot dep)
	if ( class_exists( 'BizCity_FB_Graph_API' ) ) {
		$results = BizCity_FB_Graph_API::post_to_pages( $content, $image_url, $post_url );
		if ( ! empty( $results ) ) {
			return [
				'success' => true,
				'message' => 'Đã đăng lên Facebook.',
				'data'    => $results,
			];
		}
	}

	// Legacy fallback: bizcity-facebook-bot mu-plugin (multisite hub only)
	if ( function_exists( 'twf_handle_facebook_multi_page_post' ) ) {
		$result = twf_handle_facebook_multi_page_post( $content, $image_url );
		if ( ! empty( $result ) ) {
			return [
				'success' => true,
				'message' => 'Đã đăng lên Facebook.',
				'data'    => $result,
			];
		}
	}

	if ( function_exists( 'twf_post_to_facebook' ) ) {
		$result = twf_post_to_facebook( $content, $post_url, $image_url, $content );
		return [
			'success' => true,
			'message' => 'Đã đăng lên Facebook (single page).',
			'data'    => $result,
		];
	}

	return [ 'success' => false, 'error' => 'Facebook posting not available. Configure Facebook App in Admin → Facebook → Settings.' ];
}

/* ================================================================
 * SEND EMAIL
 * ================================================================ */

/**
 * @param array $slots  { to, subject, content, cc?, bcc? }
 * @return array
 */
function bizcity_dist_send_email( array $slots ): array {
	$to      = sanitize_email( $slots['to'] ?? '' );
	$subject = sanitize_text_field( $slots['subject'] ?? '' );
	$content = $slots['content'] ?? '';
	$cc      = sanitize_text_field( $slots['cc'] ?? '' );
	$bcc     = sanitize_text_field( $slots['bcc'] ?? '' );

	if ( empty( $to ) || empty( $subject ) || empty( $content ) ) {
		return [ 'success' => false, 'error' => 'Missing to, subject, or content.' ];
	}

	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
	if ( ! empty( $cc ) ) {
		$headers[] = 'Cc: ' . $cc;
	}
	if ( ! empty( $bcc ) ) {
		$headers[] = 'Bcc: ' . $bcc;
	}

	$sent = wp_mail( $to, $subject, $content, $headers );

	return $sent
		? [ 'success' => true, 'message' => "Email đã gửi tới {$to}." ]
		: [ 'success' => false, 'error' => 'wp_mail() failed.' ];
}

/* ================================================================
 * SEND ZALO MESSAGE
 * ================================================================ */

/**
 * @param array $slots  { content, chat_id }
 * @return array
 */
function bizcity_dist_send_zalo( array $slots ): array {
	$content = trim( $slots['content'] ?? '' );
	$chat_id = trim( $slots['chat_id'] ?? '' );

	if ( empty( $content ) || empty( $chat_id ) ) {
		return [ 'success' => false, 'error' => 'Missing content or chat_id.' ];
	}

	// Use gateway sender (handles zalobot_ / zalo_ prefix routing)
	if ( class_exists( 'BizCity_Gateway_Sender' ) ) {
		$sender = new \BizCity_Gateway_Sender();
		$result = $sender->send( $chat_id, $content );
		return [
			'success' => ! is_wp_error( $result ),
			'message' => is_wp_error( $result ) ? $result->get_error_message() : 'Đã gửi tin nhắn Zalo.',
		];
	}

	// Legacy fallback
	if ( function_exists( 'biz_send_message' ) ) {
		biz_send_message( $chat_id, $content );
		return [ 'success' => true, 'message' => 'Đã gửi tin nhắn Zalo (legacy).' ];
	}

	return [ 'success' => false, 'error' => 'Zalo sending functions not available.' ];
}

/* ================================================================
 * PUBLISH WORDPRESS POST
 * ================================================================ */

/**
 * @param array $slots  { title, content, image_url?, status?, category? }
 * @return array
 */
function bizcity_dist_publish_wp_post( array $slots ): array {
	$title     = sanitize_text_field( $slots['title'] ?? '' );
	$content   = $slots['content'] ?? '';
	$image_url = trim( $slots['image_url'] ?? '' );
	$status    = in_array( $slots['status'] ?? 'publish', [ 'publish', 'draft', 'pending' ], true )
		? $slots['status']
		: 'publish';
	$category  = sanitize_text_field( $slots['category'] ?? '' );

	if ( empty( $title ) || empty( $content ) ) {
		return [ 'success' => false, 'error' => 'Missing title or content.' ];
	}

	// Use existing helper if available (handles image + Facebook cross-post)
	if ( function_exists( 'twf_wp_create_post' ) && $status === 'publish' && ! empty( $image_url ) ) {
		$post_id = twf_wp_create_post( $title, $content, $image_url );
		if ( $post_id && ! is_wp_error( $post_id ) ) {
			$post_url = get_permalink( $post_id ) ?: '';
			return [
				'success'  => true,
				'message'  => "Bài viết đã xuất bản: " . $post_url,
				'post_id'  => $post_id,
				'post_url' => $post_url,
			];
		}
	}

	// Direct wp_insert_post
	$post_args = [
		'post_title'   => wp_strip_all_tags( $title ),
		'post_content' => $content,
		'post_status'  => $status,
		'post_author'  => get_current_user_id() ?: 1,
	];

	if ( ! empty( $category ) ) {
		$cat_id = get_cat_ID( $category );
		if ( $cat_id ) {
			$post_args['post_category'] = [ $cat_id ];
		}
	}

	$post_id = wp_insert_post( $post_args, true );

	if ( is_wp_error( $post_id ) ) {
		return [ 'success' => false, 'error' => $post_id->get_error_message() ];
	}

	// Attach featured image if provided
	if ( ! empty( $image_url ) ) {
		_bizcity_dist_attach_featured_image( $post_id, $image_url );
	}

	$post_url = get_permalink( $post_id ) ?: '';
	return [
		'success'  => true,
		'message'  => "Bài viết ({$status}): " . $post_url,
		'post_id'  => $post_id,
		'post_url' => $post_url,
	];
}

/* ================================================================
 * SCHEDULE POST
 * ================================================================ */

/**
 * @param array $slots  { title, content, post_datetime, image_url? }
 * @return array
 */
function bizcity_dist_schedule_post( array $slots ): array {
	$title         = sanitize_text_field( $slots['title'] ?? '' );
	$content       = $slots['content'] ?? '';
	$post_datetime = trim( $slots['post_datetime'] ?? '' );
	$image_url     = trim( $slots['image_url'] ?? '' );

	if ( empty( $title ) || empty( $content ) || empty( $post_datetime ) ) {
		return [ 'success' => false, 'error' => 'Missing title, content, or post_datetime.' ];
	}

	$ts = strtotime( $post_datetime );
	if ( ! $ts || $ts <= time() ) {
		return [ 'success' => false, 'error' => 'post_datetime must be a valid future date.' ];
	}

	$post_id = wp_insert_post( [
		'post_title'    => wp_strip_all_tags( $title ),
		'post_content'  => $content,
		'post_status'   => 'future',
		'post_date'     => date( 'Y-m-d H:i:s', $ts ),
		'post_date_gmt' => get_gmt_from_date( date( 'Y-m-d H:i:s', $ts ) ),
		'post_author'   => get_current_user_id() ?: 1,
	], true );

	if ( is_wp_error( $post_id ) ) {
		return [ 'success' => false, 'error' => $post_id->get_error_message() ];
	}

	if ( ! empty( $image_url ) ) {
		_bizcity_dist_attach_featured_image( $post_id, $image_url );
	}

	return [
		'success'       => true,
		'message'       => "Bài viết lên lịch: {$post_datetime}",
		'post_id'       => $post_id,
		'scheduled_for' => $post_datetime,
	];
}

/* ================================================================
 * INTERNAL HELPER
 * ================================================================ */

/**
 * Download + attach featured image to a post.
 *
 * @param int    $post_id
 * @param string $image_url
 */
function _bizcity_dist_attach_featured_image( int $post_id, string $image_url ): void {
	if ( ! function_exists( 'media_handle_sideload' ) ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	$tmp = download_url( $image_url, 30 );
	if ( is_wp_error( $tmp ) ) {
		return;
	}

	$file_array = [
		'name'     => basename( wp_parse_url( $image_url, PHP_URL_PATH ) ) ?: 'image.jpg',
		'tmp_name' => $tmp,
	];

	$attach_id = media_handle_sideload( $file_array, $post_id );

	if ( ! is_wp_error( $attach_id ) ) {
		set_post_thumbnail( $post_id, $attach_id );
	}
}
