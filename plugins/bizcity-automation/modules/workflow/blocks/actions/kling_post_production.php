<?php
/**
 * Workflow Action: Kling - Post Production
 * 
 * Hậu kỳ video: ghép TTS voiceover, background music, và video effects
 * Dùng cho automation workflow trong bizcity-automation
 * 
 * Yêu cầu: Plugin bizcity-video-kling phải được cài đặt và kích hoạt
 * 
 * @package BizCity_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_kling_post_production extends WaicAction {
    protected $_code = 'kling_post_production';
    protected $_order = 104;

    public function __construct( $block = null ) {
        $this->_name = __('BizVideo - Hậu Kỳ Video', 'ai-copilot-content-generator');
        $this->_desc = __('Ghép TTS voiceover, nhạc nền và hiệu ứng video', 'ai-copilot-content-generator');
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
                'default' => 'BizVideo Post Production',
            ),
            'job_id' => array(
                'type' => 'input',
                'label' => __('Job ID *', 'ai-copilot-content-generator'),
                'default' => '{{node#2.job_id}}',
                'variables' => true,
                'desc' => __('Job ID từ node Create Job. Tự động load video, metadata, voiceover, BGM từ DB.', 'ai-copilot-content-generator'),
            ),
            'video_path' => array(
                'type' => 'input',
                'label' => __('Video Path (optional)', 'ai-copilot-content-generator'),
                'default' => '{{node#8.file_path}}',
                'variables' => true,
                'desc' => __('Đường dẫn video local từ node Fetch Video. Bỏ trống để tự động lấy từ DB.', 'ai-copilot-content-generator'),
            ),
            'chat_id' => array(
                'type' => 'input',
                'label' => __('Chat ID (thông báo)', 'ai-copilot-content-generator'),
                'default' => '{{trigger.chat_id}}',
                'variables' => true,
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
            'output_path' => __('Đường dẫn file output', 'ai-copilot-content-generator'),
            'output_url' => __('URL file output', 'ai-copilot-content-generator'),
            'tts_path' => __('Đường dẫn file TTS', 'ai-copilot-content-generator'),
            'message' => __('Thông báo', 'ai-copilot-content-generator'),
            'error' => __('Lỗi (nếu có)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    /**
     * Get FFmpeg path
     */
    private function getFFmpegPath() {
        // Check if BizCity_Video_Kling_FFmpeg_Presets class exists
        if (class_exists('BizCity_Video_Kling_FFmpeg_Presets')) {
            return BizCity_Video_Kling_FFmpeg_Presets::get_ffmpeg_path();
        }

        // Fallback
        $path = get_option('bizcity_video_kling_ffmpeg_path', '');
        if (!empty($path) && file_exists($path)) {
            return $path;
        }

        // Common paths
        $common_paths = array(
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            'C:\\ffmpeg\\bin\\ffmpeg.exe',
            'ffmpeg',
        );

        foreach ($common_paths as $try_path) {
            if (@is_executable($try_path)) {
                return $try_path;
            }
        }

        return 'ffmpeg';
    }

    /**
     * Generate TTS audio using OpenAI
     */
    private function generateTTS($text, $options = array()) {
        // Check if BizCity_Video_Kling_OpenAI_TTS class exists
        if (class_exists('BizCity_Video_Kling_OpenAI_TTS')) {
            $result = BizCity_Video_Kling_OpenAI_TTS::generate_and_save($text, 'voiceover_' . time(), $options);
            return $result;
        }

        // Fallback: Direct OpenAI API call
        $api_key = get_option('twf_openai_api_key', '');
        if (empty($api_key)) {
            $api_key = get_option('bizcity_video_kling_openai_api_key', '');
        }

        if (empty($api_key)) {
            return array('success' => false, 'error' => 'Thiếu OpenAI API key');
        }

        $voice = $options['voice'] ?? 'nova';
        $speed = $options['speed'] ?? 1.0;

        $response = wp_remote_post('https://api.openai.com/v1/audio/speech', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'timeout' => 120,
            'body' => wp_json_encode(array(
                'model' => 'tts-1-hd',
                'input' => $text,
                'voice' => $voice,
                'speed' => $speed,
                'response_format' => 'mp3',
            )),
        ));

        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $error_data = json_decode(wp_remote_retrieve_body($response), true);
            return array('success' => false, 'error' => $error_data['error']['message'] ?? "HTTP $code");
        }

        $audio_content = wp_remote_retrieve_body($response);

        // Save to file
        $upload_dir = wp_upload_dir();
        $audio_dir = $upload_dir['basedir'] . '/bizcity-kling-audio/';
        if (!file_exists($audio_dir)) {
            wp_mkdir_p($audio_dir);
        }

        $filename = 'voiceover_' . time() . '.mp3';
        $file_path = $audio_dir . $filename;
        file_put_contents($file_path, $audio_content);

        return array(
            'success' => true,
            'path' => $file_path,
            'url' => $upload_dir['baseurl'] . '/bizcity-kling-audio/' . $filename,
        );
    }

    /**
     * Get BGM path from preset or URL
     */
    private function getBGMPath($preset, $url = '', $duration = 30) {
        if (!empty($url)) {
            // Download from URL
            $tmp = download_url($url, 60);
            if (is_wp_error($tmp)) {
                return array('success' => false, 'error' => $tmp->get_error_message());
            }
            return array('success' => true, 'path' => $tmp, 'temp' => true);
        }

        // Map preset to mood
        $preset_to_mood = array(
            'upbeat_pop' => 'happy',
            'happy_acoustic' => 'happy',
            'calm_ambient' => 'calm',
            'dramatic_cinematic' => 'dramatic',
            'inspirational_corporate' => 'inspirational',
            'romantic_soft' => 'romantic',
            'energetic_edm' => 'energetic',
        );
        $mood = $preset_to_mood[$preset] ?? 'happy';

        // Get from Music Library if class exists
        if (class_exists('BizCity_Video_Kling_Music_Library')) {
            // Get tracks for mood and duration
            $tracks = BizCity_Video_Kling_Music_Library::get_tracks($duration, $mood);
            
            // If no tracks for this duration, try suggest_from_prompt
            if (empty($tracks)) {
                $suggestions = BizCity_Video_Kling_Music_Library::suggest_from_prompt($mood, $duration);
                if (!empty($suggestions)) {
                    $tracks = array($suggestions[0]); // Get top suggestion
                }
            }
            
            // Pick random track
            if (!empty($tracks)) {
                $track = $tracks[array_rand($tracks)];
                $path = BizCity_Video_Kling_Music_Library::get_track_path($track);
                if (!empty($path) && file_exists($path)) {
                    return array('success' => true, 'path' => $path, 'temp' => false);
                }
            }
        }

        // Fallback: no BGM available
        return array('success' => false, 'error' => 'BGM preset không khả dụng: ' . $preset);
    }

    /**
     * Get video filter for preset
     */
    private function getVideoFilter($preset) {
        $filters = array(
            'modern' => 'eq=contrast=1.1:brightness=0.05:saturation=1.1',
            'cinematic' => 'eq=contrast=1.15:brightness=-0.02:saturation=0.9,colorbalance=rs=0.05:gs=-0.02:bs=0.1',
            'vintage' => 'colorbalance=rs=0.1:gs=-0.05:bs=-0.15,eq=saturation=0.7:brightness=0.05,vignette',
            'dramatic' => 'eq=contrast=1.3:brightness=-0.05:saturation=0.8',
            'warm' => 'colorbalance=rs=0.15:gs=0.05:bs=-0.1,eq=brightness=0.03',
            'cool' => 'colorbalance=rs=-0.1:gs=0.02:bs=0.15,eq=brightness=0.02',
            'minimal' => 'eq=contrast=1.05:brightness=0.02:saturation=0.95',
            'golden_hour' => 'colorbalance=rs=0.2:gs=0.08:bs=-0.1,eq=brightness=0.05:saturation=1.1',
            'zoom_gentle' => 'zoompan=z=\'min(zoom+0.0015,1.3)\':d=125:x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=1080x1920',
            'vignette' => 'vignette=PI/4',
        );

        return $filters[$preset] ?? '';
    }

    /**
     * Execute FFmpeg command
     */
    private function executeFFmpeg($cmd) {
        error_log('[BizCity-FFmpeg] Executing: ' . $cmd);

        // Use class method if available
        if (class_exists('BizCity_Video_Kling_FFmpeg_Presets')) {
            return BizCity_Video_Kling_FFmpeg_Presets::execute($cmd);
        }

        // Fallback
        $output = shell_exec($cmd . ' 2>&1');
        $success = $output !== null && stripos($output, 'error') === false;

        return array(
            'success' => $success,
            'output' => $output,
        );
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $job_id = (int) trim($this->replaceVariables($this->getParam('job_id'), $variables));
        $video_path_param = trim($this->replaceVariables($this->getParam('video_path'), $variables));
        $chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
        
        if (empty($job_id)) {
            $error = 'Vui lòng cung cấp job_id';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        // Load job data từ DB
        if (!class_exists('BizCity_Video_Kling_Database')) {
            $error = 'Plugin BizVideo-Kling chưa được kích hoạt';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        $job_data = BizCity_Video_Kling_Database::get_job($job_id);
        if (!$job_data) {
            $error = 'Không tìm thấy job_id: ' . $job_id;
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        // Load script data từ job
        $script_data = null;
        $metadata = array();
        if (!empty($job_data->script_id)) {
            $script_data = BizCity_Video_Kling_Database::get_script($job_data->script_id);
            if ($script_data && !empty($script_data->metadata)) {
                $metadata = json_decode($script_data->metadata, true) ?: array();
            }
        }
        
        // ⭐ Debug log job_data
        error_log('[BizCity-PostProd] ========== START POST PRODUCTION ==========');
        error_log('[BizCity-PostProd] job_data: ' . wp_json_encode(array(
            'job_id' => $job_id,
            'attachment_id' => $job_data->attachment_id ?? 'empty',
            'media_url' => $job_data->media_url ?? 'empty',
            'video_url' => $job_data->video_url ?? 'empty',
            'status' => $job_data->status ?? 'unknown',
        )));
        
        // Lấy video_path - Priority:
        // 1) video_path param từ node Fetch Video (fastest, no DB query)
        // 2) Local file from attachment_id
        // 3) Download from media_url (CDN)
        $video_path = '';
        $video_is_temp = false;
        
        // ⭐ Priority 1: Direct path từ param (từ node Fetch Video)
        if (!empty($video_path_param) && file_exists($video_path_param)) {
            $video_path = $video_path_param;
            error_log('[BizCity-PostProd] ✅ Using video_path from param: ' . $video_path);
        }
        // Priority 2: Try local file from attachment_id
        elseif (!empty($job_data->attachment_id)) {
            $local_path = get_attached_file($job_data->attachment_id);
            error_log('[BizCity-PostProd] Checking attachment_id=' . $job_data->attachment_id . ': ' . ($local_path ?: 'EMPTY'));
            
            if (!empty($local_path) && file_exists($local_path)) {
                $video_path = $local_path;
                error_log('[BizCity-PostProd] ✅ Using local file from DB: ' . $video_path);
            }
        }
        
        // Priority 3: Download from media_url if local not found (only if needed)
        if (empty($video_path) && !empty($job_data->media_url)) {
            error_log('[BizCity-PostProd] Local file not found, downloading from CDN: ' . $job_data->media_url);
            
            // Download to temp
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $temp_file = download_url($job_data->media_url, 300); // 5 min timeout
            
            if (is_wp_error($temp_file)) {
                error_log('[BizCity-PostProd] ❌ CDN download failed: ' . $temp_file->get_error_message());
            } else {
                $video_path = $temp_file;
                $video_is_temp = true;
                error_log('[BizCity-PostProd] ✅ Downloaded from CDN to temp: ' . $video_path);
            }
        }
        
        if (empty($video_path) || !file_exists($video_path)) {
            $error = 'Không tìm thấy file video cho job_id: ' . $job_id . ' (checked param + local + CDN)';
            error_log('[BizCity-PostProd] ❌ ' . $error);
            
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        // TTS settings từ metadata
        $tts_enabled = !empty($metadata['tts_enabled']);
        $voiceover_text = $metadata['tts_text'] ?? '';
        $tts_voice = $metadata['tts_voice'] ?? 'nova';
        $tts_speed = $metadata['tts_speed'] ?? 1.1;
        $tts_volume = 100;

        // BGM settings từ metadata
        $bgm_enabled = !empty($metadata['bgm_enabled']) || (!empty($voiceover_text) && !empty($metadata['background_music']));
        $bgm_preset = $metadata['background_music'] ?? $metadata['bgm_preset'] ?? 'upbeat_pop';
        $bgm_url = '';
        $bgm_volume = $metadata['bgm_volume'] ?? 30;
        
        // Get duration from script
        $video_duration = $script_data ? (int) $script_data->duration : 30;

        // Video effect từ metadata
        $ffmpeg_preset = $metadata['video_effect'] ?? $metadata['ffmpeg_preset'] ?? 'modern';

        // Output filename
        $script_title = ($script_data && !empty($script_data->title)) ? $script_data->title : 'final-video';
        $output_filename = sanitize_file_name($script_title) . '-' . time() . '.mp4';

        // Output path
        $upload_dir = wp_upload_dir();
        $output_dir = $upload_dir['basedir'] . '/bizcity-videos/';
        if (!file_exists($output_dir)) {
            wp_mkdir_p($output_dir);
        }
        $output_path = $output_dir . $output_filename;
        $output_url = $upload_dir['baseurl'] . '/bizcity-videos/' . rawurlencode($output_filename);

        // Step 1: Generate TTS if enabled
        $tts_path = '';
        if ($tts_enabled && !empty($voiceover_text)) {
            if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
                twf_telegram_send_message($chat_id, "🎙️ Đang tạo voiceover...");
            }

            $tts_result = $this->generateTTS($voiceover_text, array(
                'voice' => $tts_voice,
                'speed' => $tts_speed,
            ));

            if (!$tts_result['success']) {
                $error = 'TTS Error: ' . ($tts_result['error'] ?? 'Unknown');
                error_log('[BizCity-PostProd] ' . $error);
                // Continue without TTS
            } else {
                $tts_path = $tts_result['path'];
            }
        }

        // Step 2: Get BGM
        $bgm_path = '';
        $bgm_temp = false;
        if ($bgm_enabled) {
            $bgm_result = $this->getBGMPath($bgm_preset, $bgm_url, $video_duration);
            if ($bgm_result['success']) {
                $bgm_path = $bgm_result['path'];
                $bgm_temp = $bgm_result['temp'] ?? false;
            } else {
                error_log('[BizCity-PostProd] BGM Error: ' . ($bgm_result['error'] ?? 'Unknown'));
            }
        }

        // Step 3: Build FFmpeg command
        $ffmpeg = $this->getFFmpegPath();
        $video_filter = $this->getVideoFilter($ffmpeg_preset);

        // Determine audio mixing strategy
        $has_tts = !empty($tts_path) && file_exists($tts_path);
        $has_bgm = !empty($bgm_path) && file_exists($bgm_path);

        error_log('[BizCity-PostProd] Processing: tts=' . ($has_tts ? 'yes' : 'no') . ', bgm=' . ($has_bgm ? 'yes' : 'no') . ', filter=' . $ffmpeg_preset);

        if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
            twf_telegram_send_message($chat_id, "🎬 Đang hậu kỳ video...");
        }

        // Build complex filter
        $filter_complex = '';
        $inputs = '-i ' . escapeshellarg($video_path);
        $maps = '';
        $audio_codec = '-c:a aac';

        // Add audio inputs
        if ($has_tts) {
            $inputs .= ' -i ' . escapeshellarg($tts_path);
        }
        if ($has_bgm) {
            $inputs .= ' -i ' . escapeshellarg($bgm_path);
        }

        // Build filter_complex
        $filter_parts = array();

        // Video filter
        if (!empty($video_filter)) {
            $filter_parts[] = "[0:v]{$video_filter}[vout]";
            $maps .= '-map "[vout]"';
        } else {
            $maps .= '-map 0:v';
        }

        // Audio mixing
        $tts_vol = $tts_volume / 100.0;
        $bgm_vol = $bgm_volume / 100.0;

        if ($has_tts && $has_bgm) {
            // Mix TTS + BGM
            $tts_idx = 1;
            $bgm_idx = 2;
            $filter_parts[] = "[{$tts_idx}:a]volume={$tts_vol}[tts];[{$bgm_idx}:a]volume={$bgm_vol}[bgm];[tts][bgm]amix=inputs=2:duration=longest[aout]";
            $maps .= ' -map "[aout]"';
        } elseif ($has_tts) {
            // TTS only
            $filter_parts[] = "[1:a]volume={$tts_vol}[aout]";
            $maps .= ' -map "[aout]"';
        } elseif ($has_bgm) {
            // BGM only
            $filter_parts[] = "[1:a]volume={$bgm_vol}[aout]";
            $maps .= ' -map "[aout]"';
        } else {
            // No audio
            $audio_codec = '-an';
        }

        if (!empty($filter_parts)) {
            $filter_complex = '-filter_complex "' . implode(';', $filter_parts) . '"';
        }

        // Build full command
        $cmd = sprintf(
            '%s -y %s %s %s -c:v libx264 -preset fast -crf 23 %s -shortest %s',
            escapeshellarg($ffmpeg),
            $inputs,
            $filter_complex,
            $maps,
            $audio_codec,
            escapeshellarg($output_path)
        );

        // Execute
        $result = $this->executeFFmpeg($cmd);

        // Cleanup temp files
        if ($bgm_temp && !empty($bgm_path) && file_exists($bgm_path)) {
            @unlink($bgm_path);
        }
        
        // ⭐ Cleanup temp video if downloaded from CDN
        if ($video_is_temp && !empty($video_path) && file_exists($video_path)) {
            @unlink($video_path);
            error_log('[BizCity-PostProd] Cleaned up temp video: ' . $video_path);
        }

        if (!$result['success'] || !file_exists($output_path)) {
            $error = 'FFmpeg Error: ' . ($result['error'] ?? $result['output'] ?? 'Unknown');
            error_log('[BizCity-PostProd] ' . $error);
            
            // ⭐ Also cleanup temp video on error
            if ($video_is_temp && !empty($video_path) && file_exists($video_path)) {
                @unlink($video_path);
            }

            if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
                twf_telegram_send_message($chat_id, "❌ Hậu kỳ thất bại:\n{$error}");
            }

            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // ⭐ Update database with final video
        if (class_exists('BizCity_Video_Kling_Database')) {
            // Upload to Media Library
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            
            $file_array = array(
                'name' => $output_filename,
                'tmp_name' => $output_path,
            );
            
            // Copy to temp location for media_handle_sideload
            $temp_file = wp_tempnam($output_filename);
            @copy($output_path, $temp_file);
            $file_array['tmp_name'] = $temp_file;
            
            $final_attachment_id = media_handle_sideload($file_array, 0);
            
            if (!is_wp_error($final_attachment_id)) {
                $final_url = wp_get_attachment_url($final_attachment_id);
                
                BizCity_Video_Kling_Database::update_job($job_id, array(
                    'media_url'     => $final_url,
                    'attachment_id' => $final_attachment_id,
                    'status'        => 'post_production_completed',
                ));
                
                error_log('[BizCity-Kling] post_production: Updated DB with final video, attachment_id=' . $final_attachment_id);
                
                // Use Media Library URL for output
                $output_url = $final_url;
            } else {
                error_log('[BizCity-Kling] post_production: Failed to upload to Media Library: ' . $final_attachment_id->get_error_message());
            }
        }
        
        // Success
        if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
            $msg = "✅ *Hậu kỳ video hoàn tất!*\n\n";
            $msg .= "🎥 Output: {$output_url}\n";
            if ($has_tts) {
                $msg .= "🎙️ Voiceover: ✓\n";
            }
            if ($has_bgm) {
                $msg .= "🎵 Nhạc nền: {$bgm_preset}\n";
            }
            if (!empty($ffmpeg_preset)) {
                $msg .= "✨ Hiệu ứng: {$ffmpeg_preset}";
            }
            twf_telegram_send_message($chat_id, $msg);
        }

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'output_path' => $output_path,
                'output_url' => $output_url,
                'tts_path' => $tts_path,
                'message' => 'Hậu kỳ video hoàn tất',
                'error' => '',
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
