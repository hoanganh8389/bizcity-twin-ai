<?php
/**
 * BizCity Tool HeyGen — Cron Poll & Chat Notification (Pillar 3)
 *
 * WP-Cron handler that:
 * 1. Polls HeyGen API for video job status
 * 2. When complete → downloads video → pushes to WP Media
 * 3. Pushes result back to user's chat via BizCity_WebChat_Database
 *
 * @package BizCity_Tool_HeyGen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_HeyGen_Cron_Chat {

    /** Max poll attempts before giving up */
    const MAX_POLLS = 120; // 120 × 20s = 40 minutes

    /** Poll interval in seconds */
    const POLL_INTERVAL = 20;

    /**
     * Register hooks — called from bootstrap.php (not admin-only!)
     */
    public static function init() {
        add_action( 'bthg_poll_video', [ __CLASS__, 'handle_poll' ] );
    }

    /**
     * Cron handler: Poll a single job, reschedule if still processing
     *
     * @param int $job_id The DB job ID
     */
    public static function handle_poll( $job_id ) {
        // Guard: if job is already completed in DB, skip to prevent race condition
        $current_job = BizCity_Tool_HeyGen_Database::get_job( $job_id );
        if ( $current_job && $current_job->status === 'completed' ) {
            error_log( "[BTHG-Cron] Job #{$job_id} already completed, skipping poll." );
            delete_transient( 'bthg_poll_' . $job_id );
            return;
        }

        $poll_key = 'bthg_poll_' . $job_id;
        $poll     = get_transient( $poll_key );

        if ( ! is_array( $poll ) ) {
            error_log( "[BTHG-Cron] No poll data for job #{$job_id}" );
            return;
        }

        $task_id    = $poll['task_id'] ?? '';
        $poll_count = ( $poll['poll_count'] ?? 0 ) + 1;

        if ( empty( $task_id ) ) {
            self::mark_failed( $job_id, 'Missing task_id in poll data' );
            delete_transient( $poll_key );
            return;
        }

        // Update poll count
        $poll['poll_count'] = $poll_count;
        set_transient( $poll_key, $poll, 2 * HOUR_IN_SECONDS );

        // Call HeyGen API to check status
        $result = bizcity_heygen_get_video_status( $task_id );

        if ( empty( $result['ok'] ) ) {
            error_log( "[BTHG-Cron] API error for job #{$job_id}: " . ( $result['error'] ?? 'unknown' ) );

            if ( $poll_count < self::MAX_POLLS ) {
                wp_schedule_single_event( time() + self::POLL_INTERVAL, 'bthg_poll_video', [ $job_id ] );
            } else {
                self::mark_failed( $job_id, 'Timeout after ' . ( $poll_count * self::POLL_INTERVAL ) . ' seconds' );
                self::notify_user_chat( $job_id, 'failed' );
                delete_transient( $poll_key );
            }
            return;
        }

        $status = bizcity_heygen_normalize_status( $result['status'] ?? 'processing' );

        // ── COMPLETED ──
        if ( $status === 'completed' ) {
            $video_url = $result['video_url'] ?? '';

            $update_data = [
                'status'   => 'completed',
                'progress' => 100,
            ];
            if ( $video_url ) {
                $update_data['video_url'] = $video_url;
            }
            BizCity_Tool_HeyGen_Database::update_job( $job_id, $update_data );
            BizCity_Tool_HeyGen_Database::set_checkpoint( $job_id, 'video_completed', [ 'url' => $video_url ] );

            // Try to download to Media Library
            $media = self::download_to_media( $job_id, $video_url );
            if ( ! empty( $media['ok'] ) ) {
                BizCity_Tool_HeyGen_Database::update_job( $job_id, [
                    'media_url'     => $media['media_url'],
                    'attachment_id' => $media['attachment_id'],
                ] );
                BizCity_Tool_HeyGen_Database::set_checkpoint( $job_id, 'media_uploaded' );
            }

            // Notify user via chat
            self::notify_user_chat( $job_id, 'completed' );

            // Cleanup
            delete_transient( $poll_key );

            error_log( "[BTHG-Cron] Job #{$job_id} completed! Video: " . ( $media['media_url'] ?? $video_url ) );
            return;
        }

        // ── FAILED ──
        if ( $status === 'failed' ) {
            // Extract error from various possible HeyGen response shapes
            $err_data  = $result['data'] ?? [];
            $error_msg = $err_data['error']['message']
                ?? $err_data['error']
                ?? $err_data['message']
                ?? $err_data['msg']
                ?? $err_data['detail']
                ?? '';
            if ( is_array( $error_msg ) ) {
                $error_msg = wp_json_encode( $error_msg, JSON_UNESCAPED_UNICODE );
            }
            if ( empty( $error_msg ) ) {
                $error_msg = 'Task failed (HeyGen status: failed). Raw: ' . mb_strimwidth( wp_json_encode( $err_data, JSON_UNESCAPED_UNICODE ), 0, 300, '...' );
            }
            error_log( '[BTHG-Cron] Job #' . $job_id . ' failed. Error: ' . $error_msg );
            self::mark_failed( $job_id, $error_msg );
            self::notify_user_chat( $job_id, 'failed' );
            delete_transient( $poll_key );
            return;
        }

        // ── STILL PROCESSING ──
        $progress = self::estimate_progress( $poll_count );
        BizCity_Tool_HeyGen_Database::update_job( $job_id, [
            'status'   => 'processing',
            'progress' => $progress,
        ] );

        if ( $poll_count < self::MAX_POLLS ) {
            wp_schedule_single_event( time() + self::POLL_INTERVAL, 'bthg_poll_video', [ $job_id ] );
        } else {
            self::mark_failed( $job_id, 'Timeout after ' . ( $poll_count * self::POLL_INTERVAL ) . 's' );
            self::notify_user_chat( $job_id, 'failed' );
            delete_transient( $poll_key );
        }
    }

    /**
     * PILLAR 3: Push result to user's chat session
     *
     * Direct insert into bizcity_webchat_messages — no LLM call.
     */
    public static function notify_user_chat( $job_id, $status ) {
        $job = BizCity_Tool_HeyGen_Database::get_job( $job_id );
        if ( ! $job ) return;

        $meta            = ! empty( $job->metadata ) ? json_decode( $job->metadata, true ) : [];
        $session_id      = $meta['session_id'] ?? '';
        $conversation_id = $meta['conversation_id'] ?? '';
        $chat_id         = $meta['chat_id'] ?? '';
        $user_id         = (int) ( $meta['user_id'] ?? $job->created_by ?? 0 );

        // Build notification message
        if ( $status === 'completed' ) {
            $video_url = $job->media_url ?: $job->video_url;
            $msg  = "🎬 **Video lipsync hoàn thành!**\n\n";
            $msg .= "👤 **Nhân vật:** " . ( $meta['character_name'] ?? 'N/A' ) . "\n";
            $msg .= "📝 **Script:** " . mb_strimwidth( $job->script ?: 'N/A', 0, 80, '...' ) . "\n";
            if ( $video_url ) {
                $msg .= "\n▶️ **Xem video:** {$video_url}\n";
            }
            $msg .= "\nVideo đã sẵn sàng! Bạn có thể tải về hoặc chia sẻ.";
        } else {
            $msg  = "❌ **Video lipsync không thành công**\n\n";
            $msg .= "📝 **Script:** " . mb_strimwidth( $job->script ?: 'N/A', 0, 80, '...' ) . "\n";
            $msg .= "💡 **Lỗi:** " . ( $job->error_message ?: 'Không rõ nguyên nhân' ) . "\n";
            $msg .= "\nBạn có thể thử lại với script khác nhé!";
        }

        // ── Method 1: Direct insert into webchat_messages (preferred) ──
        if ( $session_id && class_exists( 'BizCity_WebChat_Database' ) ) {
            try {
                $db     = BizCity_WebChat_Database::instance();
                $msg_id = $db->log_message( [
                    'session_id'             => $session_id,
                    'user_id'                => 0,
                    'client_name'            => 'AI Assistant',
                    'message_id'             => 'bthg_notify_' . $job_id . '_' . time(),
                    'message_text'           => $msg,
                    'message_from'           => 'bot',
                    'message_type'           => 'text',
                    'platform_type'          => 'WEBCHAT',
                    'plugin_slug'            => 'bizcity-tool-heygen',
                    'tool_name'              => 'create_lipsync_video',
                    'intent_conversation_id' => $conversation_id,
                    'meta'                   => [
                        'job_id'    => $job_id,
                        'status'    => $status,
                        'video_url' => $job->media_url ?: $job->video_url,
                    ],
                ] );

                if ( $msg_id ) {
                    error_log( "[BTHG-Cron] Chat notification sent for job #{$job_id} (webchat_messages id={$msg_id})" );
                    return;
                }
            } catch ( \Throwable $e ) {
                error_log( "[BTHG-Cron] WebChat DB error: " . $e->getMessage() );
            }
        }

        // ── Method 2: Transient fallback (profile page poll) ──
        $notify_key = 'bthg_notify_' . $user_id;
        $existing   = get_transient( $notify_key ) ?: [];
        $existing[] = [
            'job_id'  => $job_id,
            'status'  => $status,
            'message' => $msg,
            'time'    => current_time( 'mysql' ),
        ];
        if ( count( $existing ) > 10 ) {
            $existing = array_slice( $existing, -10 );
        }
        set_transient( $notify_key, $existing, HOUR_IN_SECONDS );
    }

    /**
     * Download video to WP Media Library
     */
    private static function download_to_media( $job_id, $video_url ) {
        if ( empty( $video_url ) ) {
            return [ 'ok' => false, 'error' => 'Empty video URL' ];
        }

        return bizcity_heygen_download_to_media( $video_url, "heygen-video-{$job_id}.mp4" );
    }

    /**
     * Mark job as failed
     */
    private static function mark_failed( $job_id, $error ) {
        BizCity_Tool_HeyGen_Database::update_job( $job_id, [
            'status'        => 'failed',
            'error_message' => $error,
        ] );
        error_log( "[BTHG-Cron] Job #{$job_id} failed: {$error}" );
    }

    /**
     * Estimate progress based on poll count
     */
    private static function estimate_progress( $poll_count ) {
        return max( 10, min( 90, $poll_count * 3 ) );
    }
}
