<?php
/**
 * PHASE 0.37.2 — Comment adapter.
 *
 * Hook: `wp_insert_comment` — chỉ capture comment có email & approved (>=0).
 * Bỏ qua trackback/pingback và comment do user đã login (đã có user_id).
 *
 * @package BizCity\CRM\LeadCapture
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BizCity_CRM_Lead_Source_Comment {

	public static function register(): void {
		add_action( 'wp_insert_comment', array( __CLASS__, 'on_insert' ), 20, 2 );
	}

	public static function on_insert( int $comment_id, $comment ): void {
		if ( ! $comment ) { return; }
		if ( ! empty( $comment->comment_type ) && ! in_array( $comment->comment_type, array( '', 'comment' ), true ) ) {
			return;
		}
		$email = sanitize_email( (string) $comment->comment_author_email );
		if ( ! $email || ! is_email( $email ) ) { return; }

		$post_id    = (int) $comment->comment_post_ID;
		$post_title = get_the_title( $post_id );

		$payload = array(
			'email'     => $email,
			'full_name' => (string) $comment->comment_author,
			'message'   => wp_strip_all_tags( (string) $comment->comment_content ),
			'meta'      => array(
				'comment_id' => $comment_id,
				'post_id'    => $post_id,
				'post_title' => $post_title,
				'post_url'   => get_permalink( $post_id ),
				'ip'         => (string) $comment->comment_author_IP,
				'ua'         => (string) $comment->comment_agent,
			),
		);

		$res = BizCity_CRM_Lead_Capture_Engine::capture( $payload, 'comment' );
		if ( is_wp_error( $res ) && function_exists( 'error_log' ) ) {
			error_log( '[BizCity CRM] Comment capture failed: ' . $res->get_error_message() );
		}
	}
}
