<?php
/**
 * BizCity Video Kling — Cron Poll & Chat Notification (Pillar 3)
 *
 * WP-Cron handler that:
 * 1. Polls Kling API for video job status
 * 2. When complete → fetches video → TTS if needed → FFmpeg merge
 * 3. Pushes result back to user's chat via BizCity_Chat_Gateway
 *    (same protocol as Tarot — async result delivered to chat session)
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Video_Kling_Cron_Chat {

    /** Max poll attempts before giving up */
    const MAX_POLLS = 120; // 120 × 15s = 30 minutes

    /** Poll interval in seconds */
    const POLL_INTERVAL = 15;

    /**
     * Register hooks — called from bootstrap.php (not admin-only!)
     */
    public static function init() {
        // Cron event handler
        add_action( 'bvk_intent_poll_video', [ __CLASS__, 'handle_poll' ] );

        // Hook into existing workflow completion for Zalo + chat notification
        add_action( 'waic_kling_video_completed', [ __CLASS__, 'on_video_completed' ], 10, 2 );
    }

    /**
     * Cron handler: Poll a single job, reschedule if still processing
     *
     * @param int $job_id The DB job ID
     */
    public static function handle_poll( $job_id ) {
        $poll_key = 'bvk_intent_poll_' . $job_id;
        $poll     = get_transient( $poll_key );

        if ( ! is_array( $poll ) ) {
            error_log( "[BVK-Cron] No poll data for job #{$job_id}" );
            return;
        }

        $task_id      = $poll['task_id'] ?? '';
        $api_settings = $poll['api_settings'] ?? [];
        $poll_count   = ( $poll['poll_count'] ?? 0 ) + 1;
        $created      = $poll['created'] ?? time();

        if ( empty( $task_id ) ) {
            self::mark_failed( $job_id, 'Missing task_id in poll data' );
            delete_transient( $poll_key );
            return;
        }

        // Update poll count
        $poll['poll_count'] = $poll_count;
        set_transient( $poll_key, $poll, 2 * HOUR_IN_SECONDS );

        // Call Kling API to check status
        $result = waic_kling_get_task( $api_settings, $task_id );

        if ( empty( $result['ok'] ) ) {
            error_log( "[BVK-Cron] API error for job #{$job_id}: " . ( $result['error'] ?? 'unknown' ) );

            // Reschedule if under max polls
            if ( $poll_count < self::MAX_POLLS ) {
                wp_schedule_single_event( time() + self::POLL_INTERVAL, 'bvk_intent_poll_video', [ $job_id ] );
            } else {
                self::mark_failed( $job_id, 'Timeout after ' . ( $poll_count * self::POLL_INTERVAL ) . ' seconds' );
                self::notify_user_chat( $job_id, 'failed' );
                delete_transient( $poll_key );
            }
            return;
        }

        $payload = $result['data'];
        $status  = waic_kling_normalize_status( $payload );

        // ── COMPLETED ──
        if ( in_array( $status, [ 'succeeded', 'success', 'completed', 'done' ], true ) ) {
            $video_url = waic_kling_extract_video_url( $payload );

            // Update job in DB
            $update_data = [
                'status'   => 'completed',
                'progress' => 100,
            ];
            if ( $video_url ) {
                $update_data['video_url'] = $video_url;
            }
            BizCity_Video_Kling_Database::update_job( $job_id, $update_data );
            BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'video_completed', [ 'url' => $video_url ] );

            // Try to download to Media Library
            $media_url = self::download_to_media( $job_id, $video_url );
            if ( $media_url ) {
                BizCity_Video_Kling_Database::update_job( $job_id, [ 'media_url' => $media_url ] );
            }

            // Process TTS if needed
            self::process_tts_if_needed( $job_id );

            // Notify user via chat
            self::notify_user_chat( $job_id, 'completed' );

            // Cleanup
            delete_transient( $poll_key );

            // Also fire standard hook for Zalo/workflow integration
            $job = BizCity_Video_Kling_Database::get_job( $job_id );
            do_action( 'waic_kling_video_completed', $job_id, $job );

            error_log( "[BVK-Cron] Job #{$job_id} completed! Video: " . ( $media_url ?: $video_url ) );
            return;
        }

        // ── FAILED ──
        if ( in_array( $status, [ 'failed', 'error', 'canceled' ], true ) ) {
            self::mark_failed( $job_id, 'Task failed: ' . $status );
            self::notify_user_chat( $job_id, 'failed' );
            delete_transient( $poll_key );

            $job = BizCity_Video_Kling_Database::get_job( $job_id );
            do_action( 'waic_kling_video_failed', $job_id, $job );
            return;
        }

        // ── STILL PROCESSING ──
        $progress = self::estimate_progress( $poll_count );
        BizCity_Video_Kling_Database::update_job( $job_id, [
            'status'   => 'processing',
            'progress' => $progress,
        ] );

        if ( $poll_count < self::MAX_POLLS ) {
            wp_schedule_single_event( time() + self::POLL_INTERVAL, 'bvk_intent_poll_video', [ $job_id ] );
        } else {
            self::mark_failed( $job_id, 'Timeout after ' . ( $poll_count * self::POLL_INTERVAL ) . 's' );
            self::notify_user_chat( $job_id, 'failed' );
            delete_transient( $poll_key );
        }
    }

    /**
     * PILLAR 3: Push result to user's chat session
     *
     * Inserts a bot message directly into bizcity_webchat_messages
     * so the frontend's bizcity_webchat_session_poll picks it up
     * via since_id. No LLM call — just a direct notification.
     *
     * @param int    $job_id Job DB ID
     * @param string $status 'completed' or 'failed'
     */
    public static function notify_user_chat( $job_id, $status ) {
        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        if ( ! $job ) return;

        // Get session context from job metadata
        $meta = ! empty( $job->metadata ) ? json_decode( $job->metadata, true ) : [];
        $session_id      = $meta['session_id'] ?? '';
        $chat_id         = $meta['chat_id'] ?? '';
        $conversation_id = $meta['conversation_id'] ?? '';
        $user_id         = (int) ( $meta['user_id'] ?? $job->created_by ?? 0 );

        // Build notification message
        if ( $status === 'completed' ) {
            $video_url = $job->media_url ?: $job->video_url;
            $msg  = "🎬 **Video hoàn thành!**\n\n";
            $msg .= "📋 **Prompt:** " . mb_strimwidth( $job->prompt ?: 'N/A', 0, 80, '...' ) . "\n";
            $msg .= "⏱️ **Thời lượng:** {$job->duration}s | 📐 {$job->aspect_ratio}\n";
            if ( $video_url ) {
                $msg .= "\n▶️ **Xem video:** {$video_url}\n";
            }
            $msg .= "\nVideo đã sẵn sàng! Bạn có thể tải về hoặc chia sẻ lên TikTok/Reels.";
        } else {
            $msg  = "❌ **Video không thành công**\n\n";
            $msg .= "📋 **Prompt:** " . mb_strimwidth( $job->prompt ?: 'N/A', 0, 80, '...' ) . "\n";
            $msg .= "💡 **Lỗi:** " . ( $job->error_message ?: 'Không rõ nguyên nhân' ) . "\n";
            $msg .= "\nBạn có thể thử lại với prompt khác hoặc ảnh khác nhé!";
        }

        // ── Method 1: Direct insert into webchat_messages (preferred) ──
        // Frontend's bizcity_webchat_session_poll picks this up via since_id
        if ( $session_id && class_exists( 'BizCity_WebChat_Database' ) ) {
            try {
                $db     = BizCity_WebChat_Database::instance();
                $msg_id = $db->log_message( [
                    'session_id'             => $session_id,
                    'user_id'                => 0, // bot
                    'client_name'            => 'AI Assistant',
                    'message_id'             => 'bvk_notify_' . $job_id . '_' . time(),
                    'message_text'           => $msg,
                    'message_from'           => 'bot',
                    'message_type'           => 'text',
                    'platform_type'          => 'WEBCHAT',
                    'plugin_slug'            => 'bizcity-video-kling',
                    'tool_name'              => 'create_video',
                    'intent_conversation_id' => $conversation_id,
                    'meta'                   => [
                        'job_id'    => $job_id,
                        'status'    => $status,
                        'video_url' => $job->media_url ?: $job->video_url,
                    ],
                ] );

                if ( $msg_id ) {
                    error_log( "[BVK-Cron] Chat notification sent for job #{$job_id} (webchat_messages id={$msg_id})" );
                    return;
                }
            } catch ( \Throwable $e ) {
                error_log( "[BVK-Cron] WebChat DB error: " . $e->getMessage() );
            }
        }

        // ── Method 2: Push via Zalo/Telegram (fallback) ──
        if ( $chat_id ) {
            if ( class_exists( 'BizCity_Video_Kling_Job_Monitor' ) ) {
                BizCity_Video_Kling_Job_Monitor::notify_zalo_admins_video_status( $job, $msg, $chat_id );
            } elseif ( function_exists( 'twf_telegram_send_message' ) ) {
                twf_telegram_send_message( $chat_id, strip_tags( str_replace( '**', '', $msg ) ) );
            }
        }

        // ── Method 3: Transient fallback (profile page poll) ──
        $notify_key = 'bvk_notify_' . $user_id;
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
     * Hook handler for existing workflow completion
     */
    public static function on_video_completed( $job_id, $job_data ) {
        // Only notify if this job came from intent engine
        if ( is_object( $job_data ) ) {
            $meta = ! empty( $job_data->metadata ) ? json_decode( $job_data->metadata, true ) : [];
        } else {
            $meta = $job_data['metadata'] ?? [];
            if ( is_string( $meta ) ) $meta = json_decode( $meta, true ) ?: [];
        }

        $source = $meta['source'] ?? '';
        if ( $source === 'intent_engine' ) {
            // Already handled by our cron poll → notify_user_chat
            return;
        }

        // For workflow/admin-created jobs, still notify via Zalo
        // (existing behavior preserved)
    }

    /**
     * Download video to WP Media Library
     */
    private static function download_to_media( $job_id, $video_url ) {
        if ( empty( $video_url ) ) return '';

        // Use existing helper if available
        if ( function_exists( 'waic_kling_download_video_to_media' ) ) {
            $result = waic_kling_download_video_to_media( $video_url, "kling-video-{$job_id}" );
            if ( ! empty( $result['url'] ) ) {
                if ( ! empty( $result['attachment_id'] ) ) {
                    BizCity_Video_Kling_Database::update_job( $job_id, [
                        'attachment_id' => $result['attachment_id'],
                    ] );
                }
                BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'video_fetched' );
                return $result['url'];
            }
        }

        return '';
    }

    /**
     * Generate TTS and merge audio if voiceover was requested
     */
    private static function process_tts_if_needed( $job_id ) {
        $job = BizCity_Video_Kling_Database::get_job( $job_id );
        if ( ! $job ) return;

        $meta = ! empty( $job->metadata ) ? json_decode( $job->metadata, true ) : [];
        $voiceover = $meta['voiceover'] ?? '';

        if ( empty( $voiceover ) ) return;

        // Check if TTS class is available
        if ( ! class_exists( 'BizCity_Video_Kling_TTS' ) ) return;

        $video_path = '';
        if ( $job->attachment_id ) {
            $video_path = get_attached_file( $job->attachment_id );
        }
        if ( empty( $video_path ) || ! file_exists( $video_path ) ) return;

        try {
            $tts = new BizCity_Video_Kling_TTS();
            $audio_path = $tts->generate( $voiceover, [
                'voice' => 'nova',
                'model' => 'tts-1',
                'speed' => 1.0,
            ] );

            if ( $audio_path && file_exists( $audio_path ) ) {
                BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'tts_generated', [ 'path' => $audio_path ] );

                // Merge video + audio using FFmpeg
                if ( class_exists( 'BizCity_FFmpeg_Presets' ) ) {
                    $output_path = str_replace( '.mp4', '-voiced.mp4', $video_path );
                    $merged = BizCity_FFmpeg_Presets::merge_video_audio( $video_path, $audio_path, $output_path );

                    if ( $merged && file_exists( $output_path ) ) {
                        BizCity_Video_Kling_Database::set_checkpoint( $job_id, 'audio_merged', [ 'path' => $output_path ] );
                        // Update media URL to voiced version
                        $upload_dir = wp_upload_dir();
                        $media_url  = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $output_path );
                        BizCity_Video_Kling_Database::update_job( $job_id, [ 'media_url' => $media_url ] );
                    }
                }

                // Cleanup temp audio
                if ( file_exists( $audio_path ) ) {
                    wp_delete_file( $audio_path );
                }
            }
        } catch ( \Throwable $e ) {
            error_log( "[BVK-Cron] TTS error for job #{$job_id}: " . $e->getMessage() );
        }
    }

    /**
     * Mark job as failed
     */
    private static function mark_failed( $job_id, $error ) {
        BizCity_Video_Kling_Database::update_job( $job_id, [
            'status'        => 'failed',
            'error_message' => $error,
        ] );
        error_log( "[BVK-Cron] Job #{$job_id} failed: {$error}" );
    }

    /**
     * Estimate progress % based on poll count
     */
    private static function estimate_progress( $poll_count ) {
        // Logarithmic curve: fast at start, slow near 100
        $base = min( 90, $poll_count * 3 );
        return max( 10, $base );
    }
}
