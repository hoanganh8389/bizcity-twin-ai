<?php
/**
 * Workflow Action: BizVideo - Create Video Job
 * 
 * Tạo video từ BizVideo AI (PiAPI Gateway)
 * Dùng cho automation workflow trong bizcity-automation
 * 
 * @package BizCity_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_kling_create_job extends WaicAction {
    protected $_code = 'kling_create_job';
    protected $_order = 101;

    const DEFAULT_ENDPOINT = 'https://api.piapi.ai/api/v1';

    public function __construct( $block = null ) {
        $this->_name = __('BizVideo - Tạo Video Job', 'ai-copilot-content-generator');
        $this->_desc = __('Tạo video từ ảnh hoặc text với BizVideo AI qua PiAPI', 'ai-copilot-content-generator');
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
                'default' => 'BizVideo Create Video',
            ),
            'script_id' => array(
                'type' => 'input',
                'label' => __('Script ID *', 'ai-copilot-content-generator'),
                'default' => '{{node#1.script_id}}',
                'variables' => true,
                'desc' => __('ID script từ node trước. Tự động load tất cả dữ liệu từ DB.', 'ai-copilot-content-generator'),
            ),
            'chat_id' => array(
                'type' => 'input',
                'label' => __('Chat ID (thông báo)', 'ai-copilot-content-generator'),
                'default' => '{{trigger.chat_id}}',
                'variables' => true,
                'desc' => __('Telegram chat ID để gửi thông báo tiến trình', 'ai-copilot-content-generator'),
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
            'task_id' => __('Task ID', 'ai-copilot-content-generator'),
            'job_key' => __('Job Key (transient key)', 'ai-copilot-content-generator'),
            'script_id' => __('Script ID (DB)', 'ai-copilot-content-generator'),
            'job_id' => __('Job ID (DB)', 'ai-copilot-content-generator'),
            'message' => __('Thông báo', 'ai-copilot-content-generator'),
            'error' => __('Lỗi (nếu có)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    /**
     * Parse model string to version and mode
     */
    private function parseModel($model_str) {
        if (strpos($model_str, '|') !== false) {
            list($version, $mode) = explode('|', $model_str);
            return array('version' => $version, 'mode' => $mode);
        }
        return array('version' => '2.6', 'mode' => 'pro');
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        // ⭐ Load TOÀN BỘ dữ liệu từ script_id
        $script_id = (int) trim($this->replaceVariables($this->getParam('script_id'), $variables));
        $chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
        
        if (empty($script_id)) {
            $error = 'Vui lòng cung cấp script_id';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        // Load script từ DB
        if (!class_exists('BizCity_Video_Kling_Database')) {
            $error = 'Plugin BizVideo-Kling chưa được kích hoạt';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        $script_data = BizCity_Video_Kling_Database::get_script($script_id);
        if (!$script_data) {
            $error = 'Không tìm thấy script ID: ' . $script_id;
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }
        
        // Parse metadata
        $metadata = !empty($script_data->metadata) ? json_decode($script_data->metadata, true) : array();
        
        // Lấy TOÀN BỘ thông tin từ script
        $model = $script_data->model;
        $prompt = $script_data->content;
        $duration = (int) $script_data->duration;
        $aspect_ratio = $script_data->aspect_ratio;
        $script_title = $script_data->title;
        
        // Metadata
        $image_url = $metadata['image_url'] ?? '';
        $with_audio = !empty($metadata['with_audio']);
        $voiceover_text = $metadata['tts_text'] ?? '';
        $tts_voice = $metadata['tts_voice'] ?? 'nova';
        $tts_speed = $metadata['tts_speed'] ?? 1.1;
        $bgm_preset = $metadata['background_music'] ?? $metadata['bgm_preset'] ?? '';
        $bgm_volume = $metadata['bgm_volume'] ?? 30;
        $ffmpeg_preset = $metadata['video_effect'] ?? $metadata['ffmpeg_preset'] ?? '';
        
        // ⭐ Debug log loaded data
        error_log('[BizCity-Kling] create_job loaded from script_id=' . $script_id . ': image_url=' . ($image_url ?: 'EMPTY') . ', prompt=' . mb_substr($prompt, 0, 50) . ', duration=' . $duration);
        
        // API key và endpoint tự động lấy từ plugin bizcity-video-kling
        $api_key = get_option('bizcity_video_kling_api_key', '');
        $endpoint = get_option('bizcity_video_kling_endpoint', self::DEFAULT_ENDPOINT);
        $endpoint = untrailingslashit($endpoint);

        // Validate
        if (empty($api_key)) {
            $error = 'Chưa cấu hình API key PiAPI (bizcity_video_kling_api_key)';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        if (empty($prompt)) {
            $error = 'Vui lòng nhập prompt mô tả video';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Parse model
        $parsed = $this->parseModel($model);

        // Build API input
        $api_input = array(
            'prompt' => $prompt,
            'mode' => $parsed['mode'],
            'version' => $parsed['version'],
            'duration' => min($duration, 10), // Max 10s for API
            'aspect_ratio' => $aspect_ratio,
            'cfg_scale' => 0.5,
        );

        // Add audio if enabled
        if ($with_audio) {
            $api_input['with_audio'] = true;
            $api_input['audio'] = true;
        }

        // Add image_url for image-to-video
        if (!empty($image_url)) {
            $api_input['image_url'] = $image_url;
        }

        $payload = array(
            'model' => 'kling',
            'task_type' => 'video_generation',
            'input' => $api_input,
        );

        // Log request
        error_log('[BizCity-Kling] create_job.request: ' . wp_json_encode($payload));

        // Call API
        $response = wp_remote_post($endpoint . '/task', array(
            'timeout' => 120,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-API-Key' => $api_key,
            ),
            'body' => wp_json_encode($payload),
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

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        error_log('[BizCity-Kling] create_job.response: ' . wp_json_encode($body));

        if ($http_code < 200 || $http_code >= 300) {
            $error = 'HTTP Error ' . $http_code;
            if (isset($body['error']['message'])) {
                $error = $body['error']['message'];
            }
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Extract task_id
        $task_id = '';
        if (isset($body['data']['task_id'])) {
            $task_id = $body['data']['task_id'];
        } elseif (isset($body['task_id'])) {
            $task_id = $body['task_id'];
        }

        if (empty($task_id)) {
            $error = 'Không nhận được task_id từ API';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error, 'raw' => $body),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Save to transient for polling
        $job_key = 'kling_job_' . $task_id;
        $job_data = array(
            'task_id' => $task_id,
            'status' => 'pending',
            'prompt' => $prompt,
            'image_url' => $image_url,
            'duration' => $duration,
            'aspect_ratio' => $aspect_ratio,
            'model' => $model,
            'created' => time(),
            // API key và endpoint tự động lấy từ plugin bizcity-video-kling
        );
        set_transient($job_key, $job_data, 7200); // Cache 2 giờ

        // Save to bizcity-video-kling database for monitoring
        $job_id = 0;
        if (class_exists('BizCity_Video_Kling_Database')) {
            // Create job linked to script
            $job_db_data = array(
                'script_id' => $script_id,
                'job_key' => $job_key,
                'task_id' => $task_id,
                'prompt' => $prompt,
                'image_url' => $image_url,
                'duration' => $duration,
                'aspect_ratio' => $aspect_ratio,
                'model' => $model,
                'status' => 'queued',
                'progress' => 0,
                'metadata' => wp_json_encode(array(
                    'api_endpoint' => $endpoint,
                    'with_audio' => $with_audio,
                    'source' => 'automation_workflow',
                    'workflow_task_id' => $taskId,
                )),
            );
            $job_id = BizCity_Video_Kling_Database::create_job($job_db_data);

            error_log('[BizCity-Kling] Saved to DB: script_id=' . $script_id . ', job_id=' . $job_id);
        }

        // Send notification
        if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
            $msg = "🎬 *Đã tạo video job*\n\n";
            $msg .= "📋 Script: {$script_title}\n";
            $msg .= "🆔 Task ID: `{$task_id}`\n";
            if (!empty($image_url)) {
                $msg .= "🖼 Image-to-Video\n";
            }
            $msg .= "⏱ Độ dài: {$duration}s\n";
            $msg .= "📐 Tỷ lệ: {$aspect_ratio}\n\n";
            $msg .= "⏳ Đang xử lý, vui lòng chờ...";
            twf_telegram_send_message($chat_id, $msg);
        }

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'task_id' => $task_id,
                'job_key' => $job_key,
                'script_id' => $script_id,
                'job_id' => $job_id,
                'message' => 'Đã tạo video job thành công',
                'error' => '',
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
