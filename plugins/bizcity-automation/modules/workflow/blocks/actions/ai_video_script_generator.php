<?php
/**
 * Workflow Action: AI Video Script Generator
 * 
 * Tạo kịch bản video từ AI với output: title, prompt, voiceover, background_music, video_effect
 * Dựa trên class-scripts.php ajax_ai_suggest
 * 
 * @package BizCity_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_ai_video_script_generator extends WaicAction {
    protected $_code = 'ai_video_script_generator';
    protected $_order = 100;

    public function __construct( $block = null ) {
        $this->_name = __('AI Tạo Kịch Bản Video', 'ai-copilot-content-generator');
        $this->_desc = __('Sinh kịch bản, lồng tiếng, nhạc nền và hiệu ứng cho video từ AI', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) {
            $this->setSettings();
        }
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = array(
            'name' => array(
                'type' => 'input',
                'label' => __('Tên Node', 'ai-copilot-content-generator'),
                'default' => '',
            ),
            'chat_id' => array(
                'type' => 'input',
                'label' => __('Chat ID (phản hồi)', 'ai-copilot-content-generator'),
                'default' => '{{trigger.chat_id}}',
                'variables' => true,
            ),
            'idea' => array(
                'type' => 'textarea',
                'label' => __('Ý tưởng video *', 'ai-copilot-content-generator'),
                'default' => '{{trigger.text}}',
                'variables' => true,
                'rows' => 4,
                'desc' => __('Mô tả ý tưởng video. Có thể dùng {{trigger.text}}', 'ai-copilot-content-generator'),
            ),
            'image_url' => array(
                'type' => 'input',
                'label' => __('URL Ảnh (nếu có)', 'ai-copilot-content-generator'),
                'default' => '{{trigger.image_url}}',
                'variables' => true,
                'desc' => __('Ảnh đầu vào cho video. Có thể dùng {{trigger.image_url}}', 'ai-copilot-content-generator'),
            ),
            'duration' => array(
                'type' => 'select',
                'label' => __('Thời lượng (giây)', 'ai-copilot-content-generator'),
                'default' => '10',
                'options' => array(
                    '5'  => '5 giây',
                    '10' => '10 giây',
                    '15' => '15 giây',
                    '20' => '20 giây',
                    '30' => '30 giây',
                    '45' => '45 giây',
                    '60' => '60 giây',
                ),
            ),
            'style' => array(
                'type' => 'select',
                'label' => __('Phong cách', 'ai-copilot-content-generator'),
                'default' => 'engaging',
                'options' => array(
                    'engaging' => 'Thu hút - Năng động',
                    'professional' => 'Chuyên nghiệp - Doanh nghiệp',
                    'emotional' => 'Cảm xúc - Truyền cảm hứng',
                    'funny' => 'Hài hước - Vui nhộn',
                    'dramatic' => 'Kịch tính - Điện ảnh',
                    'calm' => 'Nhẹ nhàng - Thư giãn',
                ),
            ),
            
            // Database sync settings
            'save_to_db' => array(
                'type' => 'select',
                'label' => __('Lưu vào Database', 'ai-copilot-content-generator'),
                'default' => '1',
                'options' => array(
                    '0' => 'Không',
                    '1' => 'Có - Lưu vào bizcity-video-kling',
                ),
                'desc' => __('Lưu kịch bản vào DB để có thể tạo video sau', 'ai-copilot-content-generator'),
            ),
            'model' => array(
                'type' => 'select',
                'label' => __('Model (cho script)', 'ai-copilot-content-generator'),
                'default' => '2.6|pro',
                'options' => array(
                    '1.5|std'  => 'Kling v1.5 Standard',
                    '1.5|pro'  => 'Kling v1.5 Pro',
                    '1.6|std'  => 'Kling v1.6 Standard',
                    '1.6|pro'  => 'Kling v1.6 Pro',
                    '2.1|std'  => 'Kling v2.1 Standard',
                    '2.1|pro'  => 'Kling v2.1 Pro',
                    '2.5|std'  => 'Kling v2.5 Standard',
                    '2.5|pro'  => 'Kling v2.5 Pro',
                    '2.6|std'  => 'Kling v2.6 Standard',
                    '2.6|pro'  => 'Kling v2.6 Pro (Mới nhất)',
                ),
            ),
            'aspect_ratio' => array(
                'type' => 'select',
                'label' => __('Tỷ Lệ Khung Hình', 'ai-copilot-content-generator'),
                'default' => '9:16',
                'options' => array(
                    '9:16' => '9:16 (TikTok/Reels)',
                    '16:9' => '16:9 (YouTube)',
                    '1:1'  => '1:1 (Vuông)',
                ),
            ),
            'tts_voice' => array(
                'type' => 'select',
                'label' => __('Giọng TTS', 'ai-copilot-content-generator'),
                'default' => 'nova',
                'options' => array(
                    'nova' => 'Nova (Nữ - mặc định)',
                    'shimmer' => 'Shimmer (Nữ)',
                    'alloy' => 'Alloy (Trung tính)',
                    'onyx' => 'Onyx (Nam)',
                    'echo' => 'Echo (Nam)',
                    'fable' => 'Fable (Nam)',
                ),
            ),
            'tts_speed' => array(
                'type' => 'input',
                'label' => __('Tốc độ TTS (0.5-2.0)', 'ai-copilot-content-generator'),
                'default' => '1.1',
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) {
            $this->setVariables();
        }
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
            'script_id' => __('Script ID (DB) - Dùng cho các node tiếp theo', 'ai-copilot-content-generator'),
            'title' => __('Tiêu đề video', 'ai-copilot-content-generator'),
            'error' => __('Lỗi (nếu có)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $idea = trim($this->replaceVariables($this->getParam('idea'), $variables));
        $image_url = trim($this->replaceVariables($this->getParam('image_url'), $variables));
        $duration = (int) $this->getParam('duration', 30);
        $style = $this->getParam('style', 'engaging');
        $chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
        
        // ⭐ Debug log input variables
        error_log('[BizCity-AI-Script] ========== START AI SCRIPT GENERATOR ==========');
        error_log('[BizCity-AI-Script] idea: ' . mb_substr($idea, 0, 100) . '...');
        error_log('[BizCity-AI-Script] image_url: ' . ($image_url ?: 'EMPTY'));
        error_log('[BizCity-AI-Script] Available variables keys: ' . implode(', ', array_keys($variables)));
        
        // Database sync settings
        $save_to_db = (bool) $this->getParam('save_to_db', 1);
        $model = $this->getParam('model', '2.6|pro');
        $aspect_ratio = $this->getParam('aspect_ratio', '9:16');
        $tts_voice = $this->getParam('tts_voice', 'nova');
        $tts_speed = max(0.5, min(2.0, (float) $this->getParam('tts_speed', 1.1)));

        if (empty($idea)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => 'Vui lòng nhập ý tưởng video'),
                'error' => 'Vui lòng nhập ý tưởng video',
                'status' => 7,
            );
            return $this->_results;
        }

        // Get OpenAI API key
        $api_key = get_option('twf_openai_api_key', '');
        if (empty($api_key)) {
            $api_key = get_option('bizcity_video_kling_openai_api_key', '');
        }

        if (empty($api_key)) {
            $error = 'Chưa cấu hình OpenAI API key (twf_openai_api_key)';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
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

        // Build prompt for OpenAI
        $system_prompt = "Bạn là chuyên gia viết kịch bản video và lồng tiếng sáng tạo. Tạo nội dung cho video ngắn dọc (phong cách TikTok/Reels).

QUAN TRỌNG VỀ THỜI LƯỢNG:
- Video dài {$duration} giây
- Lời thoại phải có khoảng {$min_words}-{$max_words} từ (tốc độ đọc ~2.5 từ/giây)
- Ví dụ: 5s = ~12 từ, 10s = ~25 từ, 15s = ~38 từ, 30s = ~75 từ, 60s = ~150 từ

QUAN TRỌNG VỀ NHÂN VẬT VÀ SẢN PHẨM (nếu có ảnh đầu vào):
- Xác định NHÂN VẬT CHÍNH trong ảnh và giữ nhân vật này NHẤT QUÁN xuyên suốt video
- Xác định SẢN PHẨM trong ảnh và để nhân vật cùng sản phẩm trở thành TRỌNG TÂM của kịch bản
- KHÔNG được biến dạng hình ảnh nhân vật hoặc biến dạng sản phẩm - giữ nguyên đặc điểm nhận dạng
- Mọi chuyển động camera phải giữ nhân vật cùng sản phẩm trong khung hình
- Kịch bản xoay quanh nhân vật tương tác với sản phẩm một cách tự nhiên

YÊU CẦU CHO VIDEO LOOP (tiếp nối liên tục):
- Khung hình đầu và khung hình cuối phải có thể nối mượt với nhau
- Tư thế/vị trí nhân vật ở cuối video tương tự như đầu video
- Tránh chuyển động đột ngột hoặc thay đổi góc camera lớn ở cuối video
- Background và ánh sáng nhất quán từ đầu đến cuối

TRẢ LỜI CHỈ BẰNG ĐỊNH DẠNG JSON với các trường sau:
- title: Tiêu đề hấp dẫn cho video (tối đa 60 ký tự, tiếng Việt)
- character: Mô tả chi tiết nhân vật chính trong ảnh (ngoại hình, trang phục, đặc điểm nhận dạng) để giữ nhất quán
- product: Mô tả chi tiết sản phẩm trong ảnh (hình dạng, màu sắc, đặc điểm) để không bị biến dạng
- prompt: Mô tả chi tiết hình ảnh cho AI tạo video. QUAN TRỌNG: Giữ nhân vật và sản phẩm nhất quán, không biến dạng. Mô tả chuyển động nhẹ nhàng, góc camera ổn định, ánh sáng đều. Kết thúc video với tư thế tương tự đầu video để có thể loop. (150-300 từ, tiếng Việt)
- voiceover: Kịch bản lồng tiếng tự nhiên, sẽ được đọc to trong {$duration} giây. PHẢI có đúng khoảng {$min_words}-{$max_words} từ. Giọng điệu gần gũi, thu hút. Đánh dấu khoảng dừng bằng '...' (tiếng Việt)
- timeline: Array các đoạn script theo thời gian. Mỗi đoạn gồm: start (giây bắt đầu), end (giây kết thúc), phase (tên giai đoạn: hook/main/climax/cta), visual (mô tả hình ảnh - LUÔN đề cập nhân vật và sản phẩm), audio (lời thoại cho đoạn này), camera (góc camera và chuyển động). Chia theo công thức:
  + Hook (20% đầu): Thu hút ngay từ giây đầu - nhân vật xuất hiện với sản phẩm
  + Main (50% giữa): Nội dung chính - nhân vật tương tác với sản phẩm
  + Climax/CTA (30% cuối): Cao trào - quay lại tư thế tương tự đầu video để loop mượt
- background_music: Gợi ý thể loại nhạc nền phù hợp. Chọn một trong: upbeat_pop, happy_acoustic, calm_ambient, dramatic_cinematic, inspirational_corporate, romantic_soft, energetic_edm
- video_effect: Gợi ý hiệu ứng video. Chọn một trong: modern, cinematic, vintage, dramatic, warm, cool, minimal, golden_hour, zoom_gentle";
        
        $user_prompt = "Ý tưởng video: {$idea}\nThời lượng: {$duration} giây\nPhong cách: {$style_text}";
        
        if (!empty($image_url)) {
            $user_prompt .= "\nẢnh đầu vào: {$image_url} (hãy mô tả cách sử dụng ảnh này trong video)";
        }
        
        $user_prompt .= "\n\nTạo tiêu đề, prompt tạo video, kịch bản lồng tiếng, gợi ý nhạc nền và hiệu ứng bằng định dạng JSON. Toàn bộ nội dung phải bằng tiếng Việt.";

        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 60,
            'body' => wp_json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => $system_prompt),
                    array('role' => 'user', 'content' => $user_prompt),
                ),
                'temperature' => 0.8,
                'max_tokens' => 1500,
            )),
        ));

        if (is_wp_error($response)) {
            $error = 'API Error: ' . $response->get_error_message();
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            $error = 'OpenAI Error: ' . ($body['error']['message'] ?? 'Unknown error');
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        // Parse JSON from response (handle markdown code blocks)
        $content = preg_replace('/^```json\s*|\s*```$/s', '', trim($content));
        $result = json_decode($content, true);

        if (!$result || !isset($result['title'])) {
            $error = 'Không thể parse kết quả từ AI';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error, 'raw' => $content),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Set defaults for missing fields
        $title = sanitize_text_field($result['title'] ?? '');
        $character = sanitize_textarea_field($result['character'] ?? '');
        $product = sanitize_textarea_field($result['product'] ?? '');
        $prompt = sanitize_textarea_field($result['prompt'] ?? '');
        $voiceover = sanitize_textarea_field($result['voiceover'] ?? '');
        $background_music = sanitize_text_field($result['background_music'] ?? 'upbeat_pop');
        $video_effect = sanitize_text_field($result['video_effect'] ?? 'modern');
        
        // Parse timeline
        $timeline = isset($result['timeline']) && is_array($result['timeline']) ? $result['timeline'] : array();
        $timeline_json = wp_json_encode($timeline, JSON_UNESCAPED_UNICODE);
        
        // Build timeline text for display
        $timeline_text = '';
        $phase_labels = array(
            'hook' => '🎬 HOOK',
            'main' => '🎬 NỘI DUNG CHÍNH',
            'climax' => '💥 CAO TRÀO',
            'cta' => '👉 KÊU GỌI HÀNH ĐỘNG',
        );
        foreach ($timeline as $segment) {
            $start = isset($segment['start']) ? (int)$segment['start'] : 0;
            $end = isset($segment['end']) ? (int)$segment['end'] : 0;
            $phase = isset($segment['phase']) ? strtolower($segment['phase']) : 'main';
            $visual = isset($segment['visual']) ? $segment['visual'] : '';
            $audio = isset($segment['audio']) ? $segment['audio'] : '';
            $camera = isset($segment['camera']) ? $segment['camera'] : '';
            
            $label = isset($phase_labels[$phase]) ? $phase_labels[$phase] : ucfirst($phase);
            $timeline_text .= "{$label} [{$start}s - {$end}s]\n";
            if (!empty($visual)) {
                $timeline_text .= "📷 Hình ảnh: {$visual}\n";
            }
            if (!empty($camera)) {
                $timeline_text .= "🎥 Camera: {$camera}\n";
            }
            if (!empty($audio)) {
                $timeline_text .= "🎤 Âm thanh: {$audio}\n";
            }
            $timeline_text .= "\n";
        }

        // Validate background_music
        $valid_bgm = array('upbeat_pop', 'happy_acoustic', 'calm_ambient', 'dramatic_cinematic', 'inspirational_corporate', 'romantic_soft', 'energetic_edm');
        if (!in_array($background_music, $valid_bgm, true)) {
            $background_music = 'upbeat_pop';
        }

        // Validate video_effect
        $valid_effects = array('modern', 'cinematic', 'vintage', 'dramatic', 'warm', 'cool', 'minimal', 'golden_hour', 'zoom_gentle');
        if (!in_array($video_effect, $valid_effects, true)) {
            $video_effect = 'modern';
        }

        // ⭐ Save to bizcity-video-kling database
        $script_id = 0;
        $save_error = '';
        
        if ($save_to_db) {
            // ⭐ Ensure BizCity_Video_Kling_Database class is loaded
            if (!class_exists('BizCity_Video_Kling_Database')) {
                $db_file = WP_CONTENT_DIR . '/plugins/bizcity-video-kling/includes/class-database.php';
                if (file_exists($db_file)) {
                    require_once $db_file;
                    error_log('[BizCity-AI-Script] ⚠️ Manually loaded BizCity_Video_Kling_Database from plugins/');
                } else {
                    error_log('[BizCity-AI-Script] ❌ BizCity_Video_Kling_Database class not found! File missing: ' . $db_file);
                    $save_error = 'Plugin bizcity-video-kling not found';
                }
            }
            
            if (class_exists('BizCity_Video_Kling_Database')) {
                try {
                    // Build script metadata
                    $script_metadata = array(
                        'image_url' => $image_url,
                        'with_audio' => false,
                        'tts_enabled' => !empty($voiceover),
                        'tts_text' => $voiceover,
                        'tts_voice' => $tts_voice,
                        'tts_model' => 'tts-1-hd',
                        'tts_speed' => $tts_speed,
                        'ffmpeg_preset' => $video_effect,
                        'audio_mode' => !empty($voiceover) ? 'tts' : 'none',
                        'bgm_preset' => $background_music,
                        'bgm_volume' => 30,
                        'source' => 'automation_ai_script_generator',
                        'workflow_task_id' => $taskId,
                        'character' => $character,
                        'product' => $product,
                        'timeline' => $timeline,
                        'idea' => $idea,
                        'style' => $style,
                    );

                    // Create script in database
                    $script_data = array(
                        'title' => !empty($title) ? $title : 'Auto: ' . mb_substr($idea, 0, 50),
                        'content' => $prompt,
                        'duration' => $duration,
                        'aspect_ratio' => $aspect_ratio,
                        'model' => $model,
                        'metadata' => wp_json_encode($script_metadata, JSON_UNESCAPED_UNICODE),
                    );
                    $script_id = BizCity_Video_Kling_Database::create_script($script_data);

                    if ($script_id > 0) {
                        error_log('[BizCity-AI-Script] ✅ Saved script #' . $script_id . ' | title=' . $title . ' | image_url=' . ($image_url ?: 'none'));
                        
                        // ⭐ Send success notification immediately
                        if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
                            $success_msg = "✅ *Kịch bản đã tạo xong*\n\n";
                            $success_msg .= "💾 *Script ID:* #{$script_id}\n";
                            $success_msg .= "📌 *Tiêu đề:* {$title}\n";
                            $success_msg .= "⏱ *Thời lượng:* {$duration}s\n";
                            twf_telegram_send_message($chat_id, $success_msg);
                        }
                    } else {
                        error_log('[BizCity-AI-Script] ❌ Failed to save script to DB (insert_id = 0)');
                        $save_error = 'Failed to insert script (insert_id = 0)';
                    }
                } catch (Exception $e) {
                    error_log('[BizCity-AI-Script] ❌ Exception when saving script: ' . $e->getMessage());
                    $save_error = 'Exception: ' . $e->getMessage();
                }
            }
        }

        // Send detailed notification to chat if chat_id provided
        if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
            // Tin nhắn chi tiết mục tiêu video
            $msg = "📝 *Chi tiết kịch bản*\n\n";
            if (!empty($character)) {
                $msg .= "👤 *Nhân vật:* {$character}\n";
            }
            if (!empty($product)) {
                $msg .= "📦 *Sản phẩm:* {$product}\n";
            }
            $msg .= "🎵 *Nhạc nền:* {$background_music}\n";
            $msg .= "✨ *Hiệu ứng:* {$video_effect}\n\n";
            $msg .= "🎤 *Lồng tiếng:*\n{$voiceover}";
            twf_telegram_send_message($chat_id, $msg);
            
            // Tin nhắn timeline chi tiết
            if (!empty($timeline_text)) {
                $msg2 = "🎬 *Kịch bản chi tiết ({$duration}s)*\n\n";
                $msg2 .= $timeline_text;
                twf_telegram_send_message($chat_id, $msg2);
            }
        }
        
        // Send error notification if save failed
        if (!empty($save_error) && !empty($chat_id) && function_exists('twf_telegram_send_message')) {
            $error_msg = "⚠️ *Lưu kịch bản thất bại*\n\n";
            $error_msg .= "❌ *Lỗi:* {$save_error}\n\n";
            $error_msg .= "Kịch bản vẫn được tạo bởi AI nhưng không lưu vào database. Kiểm tra plugin bizcity-video-kling.";
            twf_telegram_send_message($chat_id, $error_msg);
        }

        // ⭐ Check if save_to_db was required but failed
        if ($save_to_db && $script_id == 0) {
            $error = 'Không thể lưu kịch bản vào database. ' . ($save_error ?: 'Unknown error');
            error_log('[BizCity-AI-Script] ⛔ WORKFLOW STOPPED: ' . $error);
            
            $this->_results = array(
                'result' => array(
                    'ok' => 0,
                    'script_id' => 0,
                    'title' => $title,
                    'error' => $error,
                ),
                'error' => $error,
                'status' => 7, // Error status - workflow will stop
            );
            return $this->_results;
        }

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'script_id' => $script_id,
                'title' => $title,
                'error' => '',
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
