<?php
/**
 * Scripts Management - Create and manage video scripts
 * 
 * @package BizCity_Video_Kling
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class BizCity_Video_Kling_Scripts {
    
    /**
     * Initialize hooks
     */
    public static function init() {
        add_action( 'wp_ajax_bizcity_kling_save_script', array( __CLASS__, 'ajax_save_script' ) );
        add_action( 'wp_ajax_bizcity_kling_delete_script', array( __CLASS__, 'ajax_delete_script' ) );
        add_action( 'wp_ajax_bizcity_kling_generate_video', array( __CLASS__, 'ajax_generate_video' ) );
        add_action( 'wp_ajax_bizcity_kling_ai_suggest', array( __CLASS__, 'ajax_ai_suggest' ) );
    }
    
    /**
     * Render scripts list page
     */
    public static function render_page() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : 'list';
        $script_id = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
        
        switch ( $action ) {
            case 'new':
                self::render_form();
                break;
            case 'edit':
                self::render_form( $script_id );
                break;
            case 'generate':
                self::render_generate_page( $script_id );
                break;
            default:
                self::render_list();
                break;
        }
    }
    
    /**
     * Render scripts list
     */
    private static function render_list() {
        $scripts = BizCity_Video_Kling_Database::get_scripts( array( 'limit' => 50 ) );
        $nonce = wp_create_nonce( 'bizcity_kling_nonce' );
        
        include BIZCITY_VIDEO_KLING_DIR . 'views/scripts/list.php';
    }
    
    /**
     * Render script form (new/edit)
     */
    private static function render_form( $script_id = 0 ) {
        $script = null;
        $is_edit = $script_id > 0;
        
        if ( $is_edit ) {
            $script = BizCity_Video_Kling_Database::get_script( $script_id );
            if ( ! $script ) {
                wp_die( __( 'Script not found', 'bizcity-video-kling' ) );
            }
        }
        
        // Default values
        $defaults = array(
            'title' => '',
            'content' => '',
            'duration' => 10,
            'aspect_ratio' => '9:16',
            'model' => '2.6|pro',
            'with_audio' => false,
            'metadata' => '{}',
        );
        
        if ( $script ) {
            foreach ( $defaults as $key => $value ) {
                $defaults[ $key ] = $script->$key ?? $value;
            }
        }
        
        $metadata = json_decode( $defaults['metadata'], true ) ?: array();
        
        // Get with_audio from metadata or use default
        if ( isset( $metadata['with_audio'] ) ) {
            $defaults['with_audio'] = (bool) $metadata['with_audio'];
        }
        
        $nonce = wp_create_nonce( 'bizcity_kling_nonce' );
        
        include BIZCITY_VIDEO_KLING_DIR . 'views/scripts/form.php';
    }
    
    /**
     * Render generate video page with heartbeat monitoring
     */
    private static function render_generate_page( $script_id ) {
        if ( ! $script_id ) {
            wp_die( __( 'Script ID required', 'bizcity-video-kling' ) );
        }
        
        $script = BizCity_Video_Kling_Database::get_script( $script_id );
        
        if ( ! $script ) {
            wp_die( __( 'Script not found', 'bizcity-video-kling' ) );
        }
        
        $metadata = json_decode( $script->metadata ?? '{}', true );
        $image_url = $metadata['image_url'] ?? '';
        
        // Check for existing job to monitor
        $existing_job_id = isset( $_GET['job_id'] ) ? intval( $_GET['job_id'] ) : 0;
        $existing_job = null;
        $is_resuming = false;
        
        if ( $existing_job_id ) {
            $existing_job = BizCity_Video_Kling_Database::get_job( $existing_job_id );
            if ( $existing_job && $existing_job->script_id == $script_id ) {
                $is_resuming = true;
            }
        }
        
        $nonce = wp_create_nonce( 'bizcity_kling_nonce' );
        
        // Get all jobs for this script and filter
        $all_jobs = BizCity_Video_Kling_Database::get_jobs_by_script( $script_id );
        
        // Filter pending jobs
        $pending_jobs = array_filter( $all_jobs, function( $job ) {
            return in_array( $job->status, array( 'pending', 'processing', 'queued', 'draft' ) );
        } );
        
        // Recent history (limit to 10)
        $recent_history = array_slice( $all_jobs, 0, 10 );
        
        include BIZCITY_VIDEO_KLING_DIR . 'views/scripts/generate.php';
    }
    
    
    /**
     * AJAX: Save script
     */
    public static function ajax_save_script() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $script_id = intval( $_POST['script_id'] ?? 0 );
        $title = sanitize_text_field( $_POST['title'] ?? '' );
        $content = sanitize_textarea_field( $_POST['content'] ?? '' );
        $duration = intval( $_POST['duration'] ?? 5 );
        $aspect_ratio = sanitize_text_field( $_POST['aspect_ratio'] ?? '9:16' );
        $model = sanitize_text_field( $_POST['model'] ?? 'kling-v1' );
        $with_audio = ! empty( $_POST['with_audio'] );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        $image_attachment_id = intval( $_POST['image_attachment_id'] ?? 0 );
        
        // TTS options
        $tts_enabled = ! empty( $_POST['tts_enabled'] );
        $tts_text = sanitize_textarea_field( $_POST['tts_text'] ?? '' );
        $tts_voice = sanitize_text_field( $_POST['tts_voice'] ?? 'nova' );
        $tts_model = sanitize_text_field( $_POST['tts_model'] ?? 'tts-1-hd' );
        $tts_speed = floatval( $_POST['tts_speed'] ?? 1.0 );
        $ffmpeg_preset = sanitize_text_field( $_POST['ffmpeg_preset'] ?? '' );
        
        // Post-production audio options
        $audio_mode = sanitize_text_field( $_POST['audio_mode'] ?? 'tts' );
        $custom_audio_url = esc_url_raw( $_POST['custom_audio_url'] ?? '' );
        $custom_audio_attachment_id = intval( $_POST['custom_audio_attachment_id'] ?? 0 );
        $custom_audio_volume = intval( $_POST['custom_audio_volume'] ?? 100 );
        $bgm_preset = sanitize_text_field( $_POST['bgm_preset'] ?? '' );
        $bgm_url = esc_url_raw( $_POST['bgm_url'] ?? '' );
        $bgm_attachment_id = intval( $_POST['bgm_attachment_id'] ?? 0 );
        $bgm_volume = intval( $_POST['bgm_volume'] ?? 30 );
        
        // Validate audio mode
        if ( ! in_array( $audio_mode, array( 'none', 'tts', 'custom' ) ) ) {
            $audio_mode = 'tts';
        }
        
        // Validate custom audio volume (0-200%)
        $custom_audio_volume = max( 0, min( 200, $custom_audio_volume ) );
        
        // Validate BGM preset
        $valid_bgm_presets = array( '', 'upbeat_pop', 'electronic_dance', 'happy_acoustic', 'ambient_chill', 'piano_soft', 'lo_fi', 'cinematic_epic', 'emotional_strings', 'inspirational', 'corporate', 'jazz_smooth', 'nature_sounds', 'custom' );
        if ( ! in_array( $bgm_preset, $valid_bgm_presets ) ) {
            $bgm_preset = '';
        }
        
        // Validate BGM volume (0-100%)
        $bgm_volume = max( 0, min( 100, $bgm_volume ) );
        
        // Validate TTS voice
        $valid_voices = array( 'nova', 'shimmer', 'alloy', 'echo', 'fable', 'onyx' );
        if ( ! in_array( $tts_voice, $valid_voices ) ) {
            $tts_voice = 'nova';
        }
        
        // Validate TTS speed
        $tts_speed = max( 0.5, min( 2.0, $tts_speed ) );
        
        // Validate FFmpeg preset
        $valid_presets = array( '', 'zoom_gentle', 'lower_third', 'cinematic', 'vintage', 'modern', 'minimal', 'warm', 'cool', 'dramatic', 'golden_hour', 'vignette' );
        if ( ! in_array( $ffmpeg_preset, $valid_presets ) ) {
            $ffmpeg_preset = '';
        }
        
        if ( empty( $title ) ) {
            wp_send_json_error( array( 'message' => __( 'Title is required', 'bizcity-video-kling' ) ) );
            return;
        }
        
        if ( empty( $content ) ) {
            wp_send_json_error( array( 'message' => __( 'Prompt/Content is required', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Validate duration
        if ( ! in_array( $duration, array( 5, 10, 20, 25, 30, 35, 45, 60 ) ) ) {
            $duration = 5;
        }
        
        // Validate aspect ratio
        if ( ! in_array( $aspect_ratio, array( '9:16', '16:9', '1:1' ) ) ) {
            $aspect_ratio = '9:16';
        }
        
        // Metadata
        $metadata = array(
            'image_url' => $image_url,
            'image_attachment_id' => $image_attachment_id,
            'with_audio' => $with_audio,
            // TTS options
            'tts_enabled' => $tts_enabled,
            'tts_text' => $tts_text,
            'tts_voice' => $tts_voice,
            'tts_model' => $tts_model,
            'tts_speed' => $tts_speed,
            'ffmpeg_preset' => $ffmpeg_preset,
            // Post-production audio
            'audio_mode' => $audio_mode,
            'custom_audio_url' => $custom_audio_url,
            'custom_audio_attachment_id' => $custom_audio_attachment_id,
            'custom_audio_volume' => $custom_audio_volume,
            'bgm_preset' => $bgm_preset,
            'bgm_url' => $bgm_url,
            'bgm_attachment_id' => $bgm_attachment_id,
            'bgm_volume' => $bgm_volume,
        );
        
        $data = array(
            'title' => $title,
            'content' => $content,
            'duration' => $duration,
            'aspect_ratio' => $aspect_ratio,
            'model' => $model,
            'metadata' => json_encode( $metadata ),
        );
        
        if ( $script_id > 0 ) {
            // Update
            $result = BizCity_Video_Kling_Database::update_script( $script_id, $data );
        } else {
            // Create
            $script_id = BizCity_Video_Kling_Database::create_script( $data );
            $result = $script_id > 0;
        }
        
        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to save script', 'bizcity-video-kling' ) ) );
            return;
        }
        
        wp_send_json_success( array(
            'message' => __( 'Script saved', 'bizcity-video-kling' ),
            'script_id' => $script_id,
        ) );
    }
    
    /**
     * AJAX: AI Suggest - Generate script and voiceover from idea
     */
    public static function ajax_ai_suggest() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $idea = sanitize_textarea_field( $_POST['idea'] ?? '' );
        $duration = intval( $_POST['duration'] ?? 10 );
        $style = sanitize_text_field( $_POST['style'] ?? 'engaging' );
        $image_url = esc_url_raw( $_POST['image_url'] ?? '' );
        
        if ( empty( $idea ) ) {
            wp_send_json_error( array( 'message' => __( 'Vui lòng nhập ý tưởng video', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Get OpenAI API key
        $api_key = get_option( 'twf_openai_api_key', '' );
        if ( empty( $api_key ) ) {
            $api_key = get_option( 'bizcity_video_kling_openai_api_key', '' );
        }
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Chưa cấu hình OpenAI API key (twf_openai_api_key)', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Map style to Vietnamese
        $style_map = array(
            'engaging' => 'thu hút, năng động',
            'professional' => 'chuyên nghiệp, doanh nghiệp',
            'emotional' => 'cảm xúc, truyền cảm hứng',
            'funny' => 'hài hước, vui nhộn',
            'dramatic' => 'kịch tính, điện ảnh',
            'calm' => 'nhẹ nhàng, thư giãn',
        );
        $style_text = $style_map[$style] ?? $style;
        
        // Tính số từ phù hợp với duration (tốc độ đọc ~2.5-3 từ/giây tiếng Việt)
        $words_per_second = 2.5;
        $estimated_words = (int) ($duration * $words_per_second);
        $min_words = (int) ($estimated_words * 0.8);
        $max_words = (int) ($estimated_words * 1.1);
        
        // Build prompt for OpenAI (Vietnamese)
        $system_prompt = "Bạn là chuyên gia viết kịch bản video và lồng tiếng sáng tạo. Tạo nội dung cho video ngắn dọc (phong cách TikTok/Reels).

QUAN TRỌNG VỀ THỜI LƯỢNG:
- Video dài {$duration} giây
- Lời thoại phải có khoảng {$min_words}-{$max_words} từ (tốc độ đọc ~2.5 từ/giây)
- Ví dụ: 5s = ~12 từ, 10s = ~25 từ, 15s = ~38 từ, 30s = ~75 từ, 60s = ~150 từ

QUAN TRỌNG VỀ NHÂN VẬT VÀ SẢN PHẨM (nếu có ảnh đầu vào):
- Xác định NHÂN VẬT CHÍNH trong ảnh và giữ nhân vật này NHẤT QUÁN xuyên suốt video
- Xác định SẢN PHẨM trong ảnh và làm sản phẩm trở thành TRỌNG TÂM của kịch bản
- KHÔNG được biến dạng hình ảnh nhân vật hoặc sản phẩm - giữ nguyên đặc điểm nhận dạng
- Mọi chuyển động camera phải giữ nhân vật/sản phẩm trong khung hình
- Kịch bản xoay quanh nhân vật tương tác với sản phẩm một cách tự nhiên

YÊU CẦU CHO VIDEO LOOP (tiếp nối liên tục):
- Khung hình đầu và khung hình cuối phải có thể nối mượt với nhau
- Tư thế/vị trí nhân vật ở cuối video tương tự như đầu video
- Tránh chuyển động đột ngột hoặc thay đổi góc camera lớn ở cuối video
- Background và ánh sáng nhất quán từ đầu đến cuối

TRẢ LỜI CHỈ BẰNG ĐỊNH DẠNG JSON với các trường sau:
- title: Tiêu đề hấp dẫn cho video (tối đa 60 ký tự, tiếng Việt)
- character: Mô tả chi tiết nhân vật chính trong ảnh (ngoại hình, trang phục, đặc điểm nhận dạng) để giữ nhất quán. Nếu không có ảnh, để trống.
- product: Mô tả chi tiết sản phẩm trong ảnh (hình dạng, màu sắc, đặc điểm) để không bị biến dạng. Nếu không có ảnh, để trống.
- prompt: Mô tả chi tiết hình ảnh cho AI tạo video. QUAN TRỌNG: Giữ nhân vật và sản phẩm nhất quán, không biến dạng. Mô tả chuyển động nhẹ nhàng, góc camera ổn định, ánh sáng đều. Kết thúc video với tư thế tương tự đầu video để có thể loop. (150-300 từ, tiếng Việt)
- voiceover: Kịch bản lồng tiếng tự nhiên, sẽ được đọc to trong {$duration} giây. PHẢI có đúng khoảng {$min_words}-{$max_words} từ. Giọng điệu gần gũi, thu hút. Đánh dấu khoảng dừng bằng '...' (tiếng Việt)
- timeline: Array các đoạn script theo thời gian. Mỗi đoạn gồm: start (giây bắt đầu), end (giây kết thúc), phase (tên giai đoạn: hook/main/climax/cta), visual (mô tả hình ảnh - LUÔN đề cập nhân vật và sản phẩm), audio (lời thoại cho đoạn này), camera (góc camera và chuyển động). Chia theo công thức:
  + Hook (20% đầu): Thu hút ngay từ giây đầu - nhân vật xuất hiện với sản phẩm
  + Main (50% giữa): Nội dung chính - nhân vật tương tác với sản phẩm
  + Climax/CTA (30% cuối): Cao trào - quay lại tư thế tương tự đầu video để loop mượt";
        
        $user_prompt = "Ý tưởng video: {$idea}\nThời lượng: {$duration} giây\nPhong cách: {$style_text}";
        
        if ( ! empty( $image_url ) ) {
            $user_prompt .= "\nẢnh đầu vào: {$image_url} (hãy mô tả nhân vật và sản phẩm trong ảnh để giữ nhất quán xuyên suốt video)";
        }
        
        $user_prompt .= "\n\nTạo tiêu đề, mô tả nhân vật, mô tả sản phẩm, prompt tạo video, kịch bản lồng tiếng và timeline bằng định dạng JSON. Toàn bộ nội dung phải bằng tiếng Việt.";
        
        // Call OpenAI API
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60,
            'body' => json_encode( array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array( 'role' => 'system', 'content' => $system_prompt ),
                    array( 'role' => 'user', 'content' => $user_prompt ),
                ),
                'temperature' => 0.8,
                'max_tokens' => 1500,
            ) ),
        ) );
        
        if ( is_wp_error( $response ) ) {
            wp_send_json_error( array( 'message' => 'Lỗi API: ' . $response->get_error_message() ) );
            return;
        }
        
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( isset( $body['error'] ) ) {
            wp_send_json_error( array( 'message' => 'Lỗi OpenAI: ' . ( $body['error']['message'] ?? 'Lỗi không xác định' ) ) );
            return;
        }
        
        $content = $body['choices'][0]['message']['content'] ?? '';
        
        // Parse JSON from response (handle markdown code blocks)
        $content = preg_replace( '/^```json\s*|\s*```$/s', '', trim( $content ) );
        $result = json_decode( $content, true );
        
        if ( ! $result || ! isset( $result['title'] ) ) {
            // Try to extract from non-JSON response
            wp_send_json_error( array( 
                'message' => __( 'Không thể parse kết quả từ AI', 'bizcity-video-kling' ),
                'raw' => $content,
            ) );
            return;
        }
        
        // Parse timeline
        $timeline = isset( $result['timeline'] ) && is_array( $result['timeline'] ) ? $result['timeline'] : array();
        $timeline_json = wp_json_encode( $timeline, JSON_UNESCAPED_UNICODE );
        
        // Build timeline text for display
        $timeline_text = '';
        $phase_labels = array(
            'hook' => '🎬 HOOK',
            'main' => '🎬 NỘI DUNG CHÍNH',
            'climax' => '💥 CAO TRÀO',
            'cta' => '👉 KÊU GỌI HÀNH ĐỘNG',
        );
        foreach ( $timeline as $segment ) {
            $start = isset( $segment['start'] ) ? (int) $segment['start'] : 0;
            $end = isset( $segment['end'] ) ? (int) $segment['end'] : 0;
            $phase = isset( $segment['phase'] ) ? strtolower( $segment['phase'] ) : 'main';
            $visual = isset( $segment['visual'] ) ? $segment['visual'] : '';
            $audio = isset( $segment['audio'] ) ? $segment['audio'] : '';
            $camera = isset( $segment['camera'] ) ? $segment['camera'] : '';
            
            $label = isset( $phase_labels[$phase] ) ? $phase_labels[$phase] : ucfirst( $phase );
            $timeline_text .= "{$label} [{$start}s - {$end}s]\n";
            if ( ! empty( $visual ) ) {
                $timeline_text .= "📷 Hình ảnh: {$visual}\n";
            }
            if ( ! empty( $camera ) ) {
                $timeline_text .= "🎥 Camera: {$camera}\n";
            }
            if ( ! empty( $audio ) ) {
                $timeline_text .= "🎤 Âm thanh: {$audio}\n";
            }
            $timeline_text .= "\n";
        }
        
        wp_send_json_success( array(
            'title' => sanitize_text_field( $result['title'] ?? '' ),
            'character' => sanitize_textarea_field( $result['character'] ?? '' ),
            'product' => sanitize_textarea_field( $result['product'] ?? '' ),
            'prompt' => sanitize_textarea_field( $result['prompt'] ?? '' ),
            'voiceover' => sanitize_textarea_field( $result['voiceover'] ?? '' ),
            'timeline' => $timeline_json,
            'timeline_text' => trim( $timeline_text ),
        ) );
    }
    
    /**
     * AJAX: Delete script
     */
    public static function ajax_delete_script() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $script_id = intval( $_POST['script_id'] ?? 0 );
        
        if ( ! $script_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid script ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $result = BizCity_Video_Kling_Database::delete_script( $script_id );
        
        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Failed to delete script', 'bizcity-video-kling' ) ) );
            return;
        }
        
        wp_send_json_success( array( 'message' => __( 'Script deleted', 'bizcity-video-kling' ) ) );
    }
    
    /**
     * AJAX: Generate video from script (supports auto-extend for videos > 10s)
     */
    public static function ajax_generate_video() {
        check_ajax_referer( 'bizcity_kling_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $script_id = intval( $_POST['script_id'] ?? 0 );
        
        if ( ! $script_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid script ID', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $script = BizCity_Video_Kling_Database::get_script( $script_id );
        
        if ( ! $script ) {
            wp_send_json_error( array( 'message' => __( 'Script not found', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Get API settings
        $api_key = get_option( 'bizcity_video_kling_api_key', '' );
        $endpoint = get_option( 'bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1' );
        
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'API key not configured. Please set it in Settings.', 'bizcity-video-kling' ) ) );
            return;
        }
        
        $metadata = json_decode( $script->metadata ?? '{}', true );
        $image_url = $metadata['image_url'] ?? '';
        $total_duration = (int) $script->duration;
        
        // Use FFmpeg-based concatenation approach (no extend_video needed)
        // This works for ALL models and avoids video distortion from extend_video
        $model = $script->model;
        
        // Calculate segments using FFmpeg presets helper
        // For 15s: [10, 5], for 25s: [10, 10, 5], etc.
        $segment_durations = BizCity_Video_Kling_FFmpeg_Presets::calculate_segments( $total_duration, 10 );
        $num_segments = count( $segment_durations );
        $is_chain = $num_segments > 1;
        $chain_id = $is_chain ? BizCity_Video_Kling_Database::generate_chain_id() : null;
        
        // Create first job (video_generation)
        $job_id = BizCity_Video_Kling_Database::create_job( array(
            'script_id' => $script_id,
            'job_key' => 'kling_' . time() . '_' . wp_rand( 1000, 9999 ),
            'prompt' => $script->content,
            'image_url' => $image_url,
            'duration' => $segment_durations[0], // First segment duration
            'aspect_ratio' => $script->aspect_ratio,
            'model' => $script->model,
            'status' => 'draft',
            'progress' => 0,
            'chain_id' => $chain_id,
            'parent_job_id' => null,
            'segment_index' => 1,
            'total_segments' => $num_segments,
            'is_final' => $num_segments == 1 ? 1 : 0,
            'metadata' => json_encode( array(
                'source' => 'admin',
                'total_duration' => $total_duration,
                'is_chain' => $is_chain,
                'segment_durations' => $segment_durations, // Store all segment durations
                'chain_mode' => 'ffmpeg_concat', // New: use FFmpeg concat instead of extend_video
                // TTS settings from script
                'tts_enabled' => $metadata['tts_enabled'] ?? false,
                'tts_text' => $metadata['tts_text'] ?? '',
                'tts_voice' => $metadata['tts_voice'] ?? 'nova',
                'tts_model' => $metadata['tts_model'] ?? 'tts-1-hd',
                'tts_speed' => $metadata['tts_speed'] ?? 1.0,
                'ffmpeg_preset' => $metadata['ffmpeg_preset'] ?? '',
                // Post-production audio settings
                'audio_mode' => $metadata['audio_mode'] ?? 'tts',
                'custom_audio_url' => $metadata['custom_audio_url'] ?? '',
                'custom_audio_attachment_id' => $metadata['custom_audio_attachment_id'] ?? 0,
                'custom_audio_volume' => $metadata['custom_audio_volume'] ?? 100,
                'bgm_preset' => $metadata['bgm_preset'] ?? '',
                'bgm_url' => $metadata['bgm_url'] ?? '',
                'bgm_attachment_id' => $metadata['bgm_attachment_id'] ?? 0,
                'bgm_volume' => $metadata['bgm_volume'] ?? 30,
            ) ),
        ) );
        
        if ( ! $job_id ) {
            wp_send_json_error( array( 'message' => __( 'Failed to create job', 'bizcity-video-kling' ) ) );
            return;
        }
        
        BizCity_Video_Kling_Job_Monitor::add_log( $job_id, sprintf(
            'Job created: Segment 1/%d (Duration: %ds, Total: %ds)',
            $num_segments,
            $segment_durations[0],
            $total_duration
        ), 'info' );
        
        if ( $is_chain ) {
            BizCity_Video_Kling_Job_Monitor::add_log( $job_id, sprintf(
                'Chain mode: FFmpeg Concat - Sẽ tạo %d video riêng biệt và ghép lại bằng FFmpeg',
                $num_segments
            ), 'info' );
        }
        
        // Prepare API settings
        $settings = array(
            'api_key' => $api_key,
            'endpoint' => $endpoint,
            'model' => $script->model,
        );
        
        $input = array(
            'prompt' => $script->content,
            'duration' => $segment_durations[0], // First segment duration
            'aspect_ratio' => $script->aspect_ratio,
        );
        
        if ( ! empty( $image_url ) ) {
            $input['image_url'] = $image_url;
        }
        
        // Add with_audio if enabled (for sound effects generation)
        $with_audio = $metadata['with_audio'] ?? true;
        if ( $with_audio ) {
            $input['with_audio'] = true;
            BizCity_Video_Kling_Job_Monitor::add_log( $job_id, 'Sound effects enabled', 'info' );
        }
        
        BizCity_Video_Kling_Job_Monitor::add_log( $job_id, 'Calling Kling API (video_generation)...', 'info' );
        
        // Call API
        $result = waic_kling_create_task( $settings, $input );
        
        if ( ! $result['ok'] ) {
            BizCity_Video_Kling_Job_Monitor::add_log( $job_id, 'API Error: ' . ( $result['error'] ?? 'Unknown' ), 'error' );
            
            BizCity_Video_Kling_Database::update_job( $job_id, array(
                'status' => 'failed',
                'error_message' => $result['error'] ?? __( 'Failed to create video task', 'bizcity-video-kling' ),
            ) );
            
            wp_send_json_error( array( 'message' => $result['error'] ?? __( 'Failed to create video task', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Extract task_id
        $data = $result['data'];
        $task_id = $data['task_id'] ?? ( $data['data']['task_id'] ?? null );
        
        if ( ! $task_id ) {
            BizCity_Video_Kling_Job_Monitor::add_log( $job_id, 'Missing task_id in response', 'error' );
            
            BizCity_Video_Kling_Database::update_job( $job_id, array(
                'status' => 'failed',
                'error_message' => __( 'Missing task_id in API response', 'bizcity-video-kling' ),
            ) );
            
            wp_send_json_error( array( 'message' => __( 'Missing task_id in API response', 'bizcity-video-kling' ) ) );
            return;
        }
        
        // Update job with task_id
        BizCity_Video_Kling_Database::update_job( $job_id, array(
            'task_id' => $task_id,
            'status' => 'queued',
            'progress' => 5,
        ) );
        
        BizCity_Video_Kling_Job_Monitor::add_log( $job_id, 'Task created: ' . $task_id, 'success' );
        
        wp_send_json_success( array(
            'message' => $is_chain 
                ? sprintf( __( 'Chain started: %d segments for %ds total (FFmpeg concat)', 'bizcity-video-kling' ), $num_segments, $total_duration )
                : __( 'Video job created', 'bizcity-video-kling' ),
            'job_id' => $job_id,
            'task_id' => $task_id,
            'chain_id' => $chain_id,
            'is_chain' => $is_chain,
            'total_segments' => $num_segments,
            'total_duration' => $total_duration,
            'segment_durations' => $segment_durations,
        ) );
    }
}
