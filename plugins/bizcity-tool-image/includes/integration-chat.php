<?php
/**
 * BizCity Tool Image — Chat Integration (Pillar 3)
 *
 * Push notifications to webchat when image generation is complete.
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Notify user chat when a job completes (called by tool callback).
 */
function bztimg_notify_chat( int $job_id, int $user_id, string $image_url, string $session_id ) {
    if ( empty( $session_id ) || ! function_exists( 'bizcity_webchat_log_message' ) ) {
        return;
    }

    bizcity_webchat_log_message( [
        'session_id' => $session_id,
        'role'       => 'assistant',
        'content'    => "🎨 Ảnh AI đã tạo xong! Job #{$job_id}\n🔗 {$image_url}",
        'user_id'    => $user_id,
    ] );
}
