<?php
/**
 * BizCity Tool Facebook — Chat Integration
 *
 * Push job completion notifications back to webchat.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Notify chat when a Facebook post job completes.
 *
 * @param int    $job_id     Job ID from bztfb_jobs.
 * @param array  $result     Result data (fb_post_ids, wp_post_id, etc.).
 * @param string $session_id Session identifier.
 */
function bztfb_notify_chat( int $job_id, array $result, string $session_id ): void {
    if ( ! function_exists( 'bizcity_webchat_log_message' ) ) {
        return;
    }

    $fb_links = array();
    $fb_post_ids = $result['fb_post_ids'] ?? array();
    foreach ( $fb_post_ids as $fb_id ) {
        if ( ! empty( $fb_id['page_id'] ) && ! empty( $fb_id['post_id'] ) ) {
            $fb_links[] = "https://facebook.com/{$fb_id['page_id']}/posts/{$fb_id['post_id']}";
        }
    }

    if ( empty( $fb_post_ids ) ) {
        $msg  = "⚠️ **Bài viết đã tạo nhưng chưa đăng được lên Facebook.**\n\n";
        $msg .= "📝 **Tiêu đề:** " . ( $result['title'] ?? 'N/A' ) . "\n";
        if ( ! empty( $result['wp_post_id'] ) ) {
            $wp_url = get_permalink( $result['wp_post_id'] );
            $msg .= "🔗 **Bài WordPress:** [{$wp_url}]({$wp_url})\n";
        }
        $msg .= "❌ Không tìm thấy Facebook Page nào được kết nối.\n";
    } else {
        $msg  = "✅ **Đã đăng bài Facebook thành công!**\n\n";
        $msg .= "📝 **Tiêu đề:** " . ( $result['title'] ?? 'N/A' ) . "\n";
        if ( ! empty( $result['wp_post_id'] ) ) {
            $wp_url = get_permalink( $result['wp_post_id'] );
            $msg .= "🔗 **Bài WordPress:** [{$wp_url}]({$wp_url})\n";
        }
        if ( $fb_links ) {
            $fb_md = array_map( function( $link ) { return "[{$link}]({$link})"; }, $fb_links );
            $msg .= "📣 **Facebook:** " . implode( "\n", $fb_md ) . "\n";
        }
    }

    bizcity_webchat_log_message( $session_id, 'assistant', $msg, array(
        'tool'   => 'tool-facebook',
        'job_id' => $job_id,
    ) );
}
