<?php
/**
 * BizCity Video Kling — Tool Callbacks for Intent Engine
 *
 * Static methods called by bizcity_intent_register_plugin() tools config.
 * Each method receives ($slots, $context) and returns tool output envelope.
 *
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tool_Kling {

    /**
     * PRIMARY TOOL: create_video
     *
     * Nhận ảnh + prompt + thông số → tạo script + submit job → schedule cron poll.
     * Khi cron hoàn thành sẽ gửi kết quả về chat qua filter.
     *
     * @param array $slots  Input slots from intent engine
     * @param array $context Pipeline context (_meta, user_id, conversation_id, session_id, chat_id...)
     * @return array Tool output envelope
     */
    public static function create_video( $slots, $context = [] ) {
        // Engine calls call_user_func($cb, $slots) — $context is always []
        // Read user_id from $slots (injected by engine) or fallback
        $user_id = $slots['user_id'] ?? get_current_user_id();
        $meta    = $slots['_meta'] ?? [];
        if ( ! $user_id ) {
            return [
                'success' => false,
                'message' => 'Bạn cần đăng nhập để tạo video.',
                'data'    => [],
            ];
        }

        // ── Extract slots ──
        $prompt       = sanitize_textarea_field( $slots['message'] ?? $slots['prompt'] ?? '' );
        $image_url    = esc_url_raw( $slots['image_url'] ?? '' );
        $duration     = max( 5, min( 60, intval( $slots['duration'] ?? 10 ) ) );
        $aspect_ratio = sanitize_text_field( $slots['aspect_ratio'] ?? '9:16' );
        $voiceover    = sanitize_textarea_field( $slots['voiceover_text'] ?? '' );
        $model        = sanitize_text_field( $slots['model'] ?? '2.6|pro' );

        // Default prompt when image-only (no text prompt)
        if ( empty( $prompt ) && ! empty( $image_url ) ) {
            $prompt = 'Tạo video chuyển động tự nhiên, cinematic từ ảnh này';
        }

        // Validate
        if ( empty( $prompt ) && empty( $image_url ) ) {
            return [
                'success' => false,
                'message' => 'Cần ít nhất một mô tả (prompt) hoặc ảnh để tạo video.',
                'data'    => [],
            ];
        }

        // Validate aspect ratio
        $allowed_ratios = [ '9:16', '16:9', '1:1' ];
        if ( ! in_array( $aspect_ratio, $allowed_ratios, true ) ) {
            $aspect_ratio = '9:16';
        }

        // ── Create script record ──
        $metadata = [
            'image_url'   => $image_url,
            'with_audio'  => ! empty( $voiceover ),
            'tts_enabled' => ! empty( $voiceover ),
            'tts_text'    => $voiceover,
            'audio_mode'  => ! empty( $voiceover ) ? 'tts' : 'none',
            'source'      => 'intent_engine',
            'session_id'  => $slots['session_id'] ?? '',
            'chat_id'     => $meta['message_id'] ?? '',
            'conversation_id' => $meta['conv_id'] ?? '',
        ];

        $script_id = BizCity_Video_Kling_Database::create_script( [
            'title'        => mb_strimwidth( $prompt ?: 'Video từ ảnh', 0, 200, '...' ),
            'content'      => $prompt,
            'duration'     => $duration,
            'aspect_ratio' => $aspect_ratio,
            'model'        => $model,
            'metadata'     => wp_json_encode( $metadata, JSON_UNESCAPED_UNICODE ),
            'created_by'   => $user_id,
        ] );

        if ( ! $script_id ) {
            return [
                'success' => false,
                'message' => 'Lỗi tạo kịch bản video. Vui lòng thử lại.',
                'data'    => [],
            ];
        }

        // ── Calculate segments ──
        $max_segment  = 10; // Kling API max 10s per task
        $total_segments = max( 1, (int) ceil( $duration / $max_segment ) );
        $segment_duration = min( $duration, $max_segment );

        // ── Submit first segment to Kling API ──
        $api_settings = [
            'api_key'  => get_option( 'bizcity_video_kling_api_key', '' ),
            'endpoint' => get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' ),
            'model'    => $model,
        ];

        $api_input = [
            'prompt'       => $prompt,
            'duration'     => $segment_duration,
            'aspect_ratio' => $aspect_ratio,
        ];
        if ( $image_url ) {
            $api_input['image_url'] = $image_url;
        }

        $result = waic_kling_create_task( $api_settings, $api_input );

        if ( empty( $result['ok'] ) ) {
            return [
                'success' => false,
                'message' => 'Lỗi gửi yêu cầu tạo video: ' . ( $result['error'] ?? 'Unknown error' ),
                'data'    => [ 'script_id' => $script_id ],
            ];
        }

        // ── Extract task_id from API response ──
        $task_id = $result['data']['data']['task_id'] ?? '';
        if ( empty( $task_id ) ) {
            $task_id = $result['data']['task_id'] ?? '';
        }

        // ── Create job in DB ──
        $chain_id = 'chain_' . $script_id . '_' . time();
        $job_id   = BizCity_Video_Kling_Database::create_job( [
            'script_id'      => $script_id,
            'job_key'        => 'kling_intent_' . uniqid(),
            'task_id'        => $task_id,
            'prompt'         => $prompt,
            'image_url'      => $image_url,
            'duration'       => $segment_duration,
            'aspect_ratio'   => $aspect_ratio,
            'model'          => $model,
            'status'         => 'queued',
            'progress'       => 5,
            'chain_id'       => $total_segments > 1 ? $chain_id : null,
            'segment_index'  => 1,
            'total_segments' => $total_segments,
            'is_final'       => $total_segments === 1 ? 1 : 0,
            'metadata'       => wp_json_encode( [
                'submitted_at' => current_time( 'mysql' ),
                'source'       => 'intent_engine',
                'session_id'   => $slots['session_id'] ?? '',
                'chat_id'      => $meta['message_id'] ?? '',
                'conversation_id' => $meta['conv_id'] ?? '',
                'user_id'      => $user_id,
                'voiceover'    => $voiceover,
            ], JSON_UNESCAPED_UNICODE ),
            'created_by'     => $user_id,
        ] );

        // ── Schedule cron poll ──
        if ( $task_id ) {
            self::schedule_poll_cron( $job_id, $task_id, $api_settings );
        }

        // ── Build response ──
        $duration_label = $duration . 's';
        $ratio_label    = $aspect_ratio === '9:16' ? 'dọc TikTok' : ( $aspect_ratio === '16:9' ? 'ngang YouTube' : 'vuông' );

        $msg  = "🎬 **Đã bắt đầu tạo video!**\n\n";
        $msg .= "📋 **Kịch bản:** " . mb_strimwidth( $prompt, 0, 80, '...' ) . "\n";
        if ( $image_url ) {
            $msg .= "🖼️ **Ảnh gốc:** Đã nhận\n";
        }
        $msg .= "⏱️ **Thời lượng:** {$duration_label}\n";
        $msg .= "📐 **Tỷ lệ:** {$ratio_label}\n";
        if ( $voiceover ) {
            $msg .= "🎙️ **Lời thoại:** Có — sẽ ghép TTS sau khi video hoàn thành\n";
        }
        if ( $total_segments > 1 ) {
            $msg .= "🔗 **Segments:** {$total_segments} đoạn × {$segment_duration}s → ghép FFmpeg\n";
        }
        $msg .= "\n⏳ Video đang được AI xử lý. Mình sẽ gửi kết quả về đây khi hoàn thành nhé!";

        return [
            'success' => true,
            'message' => $msg,
            'data'    => [
                'job_id'     => $job_id,
                'script_id'  => $script_id,
                'task_id'    => $task_id,
                'status'     => 'queued',
                'duration'   => $duration,
                'segments'   => $total_segments,
            ],
        ];
    }

    /**
     * SECONDARY TOOL: check_video_status
     *
     * Kiểm tra trạng thái video job gần nhất của user.
     */
    public static function check_video_status( $slots, $context = [] ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        if ( ! $user_id ) {
            return [
                'success' => false,
                'message' => 'Bạn cần đăng nhập để xem trạng thái video.',
                'data'    => [],
            ];
        }

        global $wpdb;
        $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );

        $job_id = intval( $slots['job_id'] ?? 0 );

        if ( $job_id ) {
            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$jobs_table} WHERE id = %d AND created_by = %d",
                $job_id, $user_id
            ) );
        } else {
            // Lấy job gần nhất
            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$jobs_table} WHERE created_by = %d ORDER BY created_at DESC LIMIT 1",
                $user_id
            ) );
        }

        if ( ! $job ) {
            return [
                'success' => true,
                'message' => 'Không tìm thấy video job nào. Hãy thử tạo video mới nhé!',
                'data'    => [],
            ];
        }

        $status_icons = [
            'draft'      => '📝',
            'queued'     => '⏳',
            'processing' => '🔄',
            'completed'  => '✅',
            'failed'     => '❌',
        ];
        $icon = $status_icons[ $job->status ] ?? '❓';

        $msg = "{$icon} **Trạng thái video #{$job->id}**\n\n";
        $msg .= "📋 **Prompt:** " . mb_strimwidth( $job->prompt ?: 'N/A', 0, 80, '...' ) . "\n";
        $msg .= "📊 **Status:** {$job->status} ({$job->progress}%)\n";
        $msg .= "⏱️ **Thời lượng:** {$job->duration}s | 📐 {$job->aspect_ratio}\n";
        $msg .= "📅 **Tạo lúc:** {$job->created_at}\n";

        if ( $job->status === 'completed' ) {
            $video_url = $job->media_url ?: $job->video_url;
            if ( $video_url ) {
                $msg .= "\n🎬 **Video:** {$video_url}\n";
            }
        } elseif ( $job->status === 'failed' ) {
            $msg .= "\n❗ **Lỗi:** " . ( $job->error_message ?: 'Không rõ' ) . "\n";
        }

        return [
            'success' => true,
            'message' => $msg,
            'data'    => [
                'job_id'    => $job->id,
                'status'    => $job->status,
                'progress'  => $job->progress,
                'video_url' => $job->media_url ?: $job->video_url ?: '',
            ],
        ];
    }

    /**
     * SECONDARY TOOL: list_my_videos
     *
     * Liệt kê video đã tạo gần đây.
     */
    public static function list_my_videos( $slots, $context = [] ) {
        $user_id = $slots['user_id'] ?? get_current_user_id();
        if ( ! $user_id ) {
            return [
                'success' => false,
                'message' => 'Bạn cần đăng nhập để xem danh sách video.',
                'data'    => [],
            ];
        }

        global $wpdb;
        $jobs_table = BizCity_Video_Kling_Database::get_table_name( 'jobs' );
        $limit = max( 1, min( 10, intval( $slots['limit'] ?? 5 ) ) );

        $jobs = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, prompt, status, progress, video_url, media_url, duration, aspect_ratio, model, created_at
             FROM {$jobs_table}
             WHERE created_by = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $user_id, $limit
        ), ARRAY_A );

        if ( empty( $jobs ) ) {
            return [
                'success' => true,
                'message' => "Bạn chưa có video nào. Hãy thử tạo video mới nhé! 🎬",
                'data'    => [ 'videos' => [] ],
            ];
        }

        $status_icons = [
            'draft' => '📝', 'queued' => '⏳', 'processing' => '🔄',
            'completed' => '✅', 'failed' => '❌',
        ];

        $msg = "🎬 **{$limit} video gần nhất:**\n\n";
        foreach ( $jobs as $i => $j ) {
            $icon  = $status_icons[ $j['status'] ] ?? '❓';
            $title = mb_strimwidth( $j['prompt'] ?: 'No prompt', 0, 50, '...' );
            $video = $j['media_url'] ?: $j['video_url'];

            $msg .= ( $i + 1 ) . ". {$icon} **{$title}**\n";
            $msg .= "   ⏱ {$j['duration']}s | 📐 {$j['aspect_ratio']} | {$j['status']} | {$j['created_at']}\n";
            if ( $video && $j['status'] === 'completed' ) {
                $msg .= "   ▶ {$video}\n";
            }
            $msg .= "\n";
        }

        return [
            'success' => true,
            'message' => $msg,
            'data'    => [ 'videos' => $jobs ],
        ];
    }

    /**
     * Schedule WP-Cron to poll job status
     *
     * @param int    $job_id       DB job ID
     * @param string $task_id      PiAPI task ID
     * @param array  $api_settings API config
     */
    private static function schedule_poll_cron( $job_id, $task_id, $api_settings ) {
        // Store poll context in transient
        $poll_key = 'bvk_intent_poll_' . $job_id;
        set_transient( $poll_key, [
            'job_id'      => $job_id,
            'task_id'     => $task_id,
            'api_settings' => [
                'api_key'  => $api_settings['api_key'] ?? '',
                'endpoint' => $api_settings['endpoint'] ?? '',
            ],
            'created'     => time(),
            'poll_count'  => 0,
        ], 2 * HOUR_IN_SECONDS );

        // Schedule first poll in 15 seconds
        wp_schedule_single_event( time() + 15, 'bvk_intent_poll_video', [ $job_id ] );
    }
}
