<?php
/**
 * BizCity Tool HeyGen — Tool Callbacks for Intent Engine
 *
 * Static methods called by bizcity_intent_register_plugin() tools config.
 * Each method receives ($slots, $context) and returns tool output envelope.
 *
 * Primary tool: create_lipsync_video
 * Secondary tools: list_characters, check_video_status
 *
 * @package BizCity_Tool_HeyGen
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Ensure API library is loaded (guard against load-order issues)
require_once dirname( __DIR__ ) . '/lib/heygen_api.php';

class BizCity_Tool_HeyGen {

    /**
     * PRIMARY TOOL: create_lipsync_video
     *
     * Chọn nhân vật + nhập script → tạo video lipsync → schedule cron poll.
     *
     * @param array $slots  Input slots from intent engine
     * @param array $context Pipeline context
     * @return array Tool output envelope
     */
    public static function create_lipsync_video( $slots, $context = [] ) {
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
        $character_id = intval( $slots['character_id'] ?? 0 );
        $script       = sanitize_textarea_field( $slots['script'] ?? $slots['message'] ?? '' );
        $mode         = sanitize_text_field( $slots['mode'] ?? 'text' );

        if ( empty( $script ) ) {
            return [
                'success' => false,
                'message' => 'Cần nhập lời thoại / script để tạo video.',
                'data'    => [],
            ];
        }

        // ── Get character ──
        $character = null;
        if ( $character_id ) {
            $character = BizCity_Tool_HeyGen_Database::get_character( $character_id );
        }

        // If no character_id, try first active character
        if ( ! $character ) {
            $chars = BizCity_Tool_HeyGen_Database::get_active_characters();
            $character = ! empty( $chars ) ? $chars[0] : null;
        }

        if ( ! $character ) {
            return [
                'success' => false,
                'message' => 'Chưa có nhân vật AI nào được cấu hình. Admin cần tạo nhân vật trước.',
                'data'    => [],
            ];
        }

        if ( empty( $character->voice_id ) && $mode !== 'audio' ) {
            return [
                'success' => false,
                'message' => "Nhân vật \"{$character->name}\" chưa có voice_id. Admin cần clone voice trước, hoặc chọn chế độ Audio Upload.",
                'data'    => [ 'character_id' => $character->id ],
            ];
        }

        // Audio mode: need audio from slots (uploaded) or character's voice_sample_url
        $audio_url_from_slot = ! empty( $slots['audio_url'] ) ? esc_url_raw( $slots['audio_url'] ) : '';
        if ( $mode === 'audio' && empty( $audio_url_from_slot ) && empty( $character->voice_sample_url ) ) {
            return [
                'success' => false,
                'message' => "Chế độ Audio cần file âm thanh. Hãy upload audio hoặc cấu hình voice sample cho nhân vật.",
                'data'    => [ 'character_id' => $character->id ],
            ];
        }

        // ── Determine avatar source ──
        // /v2/video accepts avatar_id OR image_url (mutually exclusive)
        $avatar_id = $character->avatar_id ?: '';
        $image_url = $character->image_url ?: '';

        if ( empty( $avatar_id ) && empty( $image_url ) ) {
            return [
                'success' => false,
                'message' => "Nhân vật \"{$character->name}\" chưa có avatar_id hoặc ảnh đại diện (image_url).",
                'data'    => [ 'character_id' => $character->id ],
            ];
        }

        // ── Validate mode ──
        $allowed_modes = [ 'text', 'audio' ];
        if ( ! in_array( $mode, $allowed_modes, true ) ) {
            $mode = 'text';
        }

        // ── Call HeyGen API (/v2/video — flat structure) ──
        $api_params = [
            'script'   => $script,
            'voice_id' => $character->voice_id ?: '',
            'mode'     => $mode,
        ];

        // Character source: prefer avatar_id, fallback to image_url
        if ( ! empty( $avatar_id ) ) {
            $api_params['avatar_id'] = $avatar_id;
        } else {
            $api_params['image_url'] = $image_url;
        }

        // Audio mode: prefer audio from slot (uploaded), fallback to character's voice_sample_url
        if ( $mode === 'audio' ) {
            $api_params['audio_url'] = $audio_url_from_slot ?: $character->voice_sample_url;
        }

        $api_result = bizcity_heygen_create_video( $api_params );

        if ( empty( $api_result['ok'] ) ) {
            return [
                'success' => false,
                'message' => 'Lỗi gửi yêu cầu tạo video: ' . ( $api_result['error'] ?? 'Unknown error' ),
                'data'    => [ 'character_id' => $character->id ],
            ];
        }

        $task_id = $api_result['video_id'] ?? '';

        // ── Create job in DB ──
        $job_id = BizCity_Tool_HeyGen_Database::create_job( [
            'character_id' => $character->id,
            'job_key'      => 'heygen_intent_' . uniqid(),
            'task_id'      => $task_id,
            'script'       => $script,
            'voice_id'     => $character->voice_id ?: null,
            'avatar_id'    => $avatar_id ?: null,
            'image_url'    => $image_url ?: null,
            'mode'         => $mode,
            'status'       => 'queued',
            'progress'     => 5,
            'metadata'     => wp_json_encode( [
                'submitted_at'    => current_time( 'mysql' ),
                'source'          => 'intent_engine',
                'session_id'      => $slots['session_id'] ?? '',
                'chat_id'         => $meta['message_id'] ?? '',
                'conversation_id' => $meta['conv_id'] ?? '',
                'user_id'         => $user_id,
                'character_name'  => $character->name,
            ], JSON_UNESCAPED_UNICODE ),
            'created_by'   => $user_id,
        ] );

        // Set initial checkpoint
        if ( $job_id ) {
            BizCity_Tool_HeyGen_Database::set_checkpoint( $job_id, 'video_submitted', [ 'task_id' => $task_id ] );
        }

        // ── Schedule cron poll ──
        if ( $task_id && $job_id ) {
            self::schedule_poll_cron( $job_id, $task_id );
        }

        // ── Build response ──
        $msg  = "🎬 **Đã bắt đầu tạo video lipsync!**\n\n";
        $msg .= "👤 **Nhân vật:** {$character->name}\n";
        $msg .= "📝 **Lời thoại:** " . mb_strimwidth( $script, 0, 100, '...' ) . "\n";
        $msg .= "🎙️ **Mode:** " . ( $mode === 'text' ? 'Text → TTS → Lipsync' : 'Audio → Lipsync' ) . "\n";
        $msg .= "\n⏳ Video đang được HeyGen xử lý. Mình sẽ gửi kết quả về đây khi hoàn thành!";

        return [
            'success' => true,
            'message' => $msg,
            'data'    => [
                'job_id'       => $job_id,
                'task_id'      => $task_id,
                'character_id' => $character->id,
                'status'       => 'queued',
            ],
        ];
    }

    /**
     * SECONDARY TOOL: list_characters
     *
     * Liệt kê nhân vật AI đã cấu hình.
     */
    public static function list_characters( $slots, $context = [] ) {
        $characters = BizCity_Tool_HeyGen_Database::get_active_characters();

        if ( empty( $characters ) ) {
            return [
                'success' => true,
                'message' => 'Chưa có nhân vật AI nào. Admin cần tạo nhân vật trong trang profile / cài đặt.',
                'data'    => [ 'characters' => [] ],
            ];
        }

        $msg = "👥 **Danh sách nhân vật AI:**\n\n";
        foreach ( $characters as $i => $c ) {
            $voice_icon = $c->voice_id ? '✅' : '❌';
            $avatar_icon = ( $c->avatar_id || $c->image_url ) ? '✅' : '❌';

            $msg .= ( $i + 1 ) . ". **{$c->name}**\n";
            $msg .= "   🎙️ Voice: {$voice_icon} | 👤 Avatar: {$avatar_icon}\n";
            if ( $c->description ) {
                $msg .= "   📝 " . mb_strimwidth( $c->description, 0, 60, '...' ) . "\n";
            }
            $msg .= "\n";
        }
        $msg .= "💡 Gõ tên nhân vật + lời thoại để tạo video. Ví dụ: \"Nhân vật A nói: Xin chào...\"";

        return [
            'success' => true,
            'message' => $msg,
            'data'    => [ 'characters' => array_map( function( $c ) {
                return [ 'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug ];
            }, $characters ) ],
        ];
    }

    /**
     * SECONDARY TOOL: check_video_status
     *
     * Kiểm tra trạng thái video job gần nhất.
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
        $jobs_table = BizCity_Tool_HeyGen_Database::get_table_name( 'jobs' );

        $job_id = intval( $slots['job_id'] ?? 0 );

        if ( $job_id ) {
            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$jobs_table} WHERE id = %d AND created_by = %d",
                $job_id, $user_id
            ) );
        } else {
            $job = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$jobs_table} WHERE created_by = %d ORDER BY created_at DESC LIMIT 1",
                $user_id
            ) );
        }

        if ( ! $job ) {
            return [
                'success' => true,
                'message' => 'Không tìm thấy video job nào. Hãy tạo video mới nhé!',
                'data'    => [],
            ];
        }

        $status_icons = [
            'draft'     => '📝', 'queued'     => '⏳',
            'processing' => '🔄', 'completed' => '✅', 'failed' => '❌',
        ];
        $icon = $status_icons[ $job->status ] ?? '❓';

        $msg = "{$icon} **Trạng thái video #{$job->id}**\n\n";
        $msg .= "📋 **Script:** " . mb_strimwidth( $job->script ?: 'N/A', 0, 80, '...' ) . "\n";
        $msg .= "📊 **Status:** {$job->status} ({$job->progress}%)\n";
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
     * Schedule WP-Cron to poll job status
     */
    private static function schedule_poll_cron( $job_id, $task_id ) {
        $poll_key = 'bthg_poll_' . $job_id;
        set_transient( $poll_key, [
            'job_id'     => $job_id,
            'task_id'    => $task_id,
            'created'    => time(),
            'poll_count' => 0,
        ], 2 * HOUR_IN_SECONDS );

        wp_schedule_single_event( time() + 20, 'bthg_poll_video', [ $job_id ] );
    }
}
