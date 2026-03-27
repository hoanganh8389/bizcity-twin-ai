<?php
/**
 * Workflow Action: Kling - Poll Status
 * 
 * Kiểm tra trạng thái video job từ Kling AI
 * Dùng cho automation workflow trong bizcity-automation
 * 
 * @package BizCity_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_kling_poll_status extends WaicAction {
    protected $_code = 'kling_poll_status';
    protected $_order = 102;

    const DEFAULT_ENDPOINT = 'https://api.piapi.ai/api/v1';

    public function __construct( $block = null ) {
        $this->_name = __('BizVideo - Kiểm Tra Trạng Thái', 'ai-copilot-content-generator');
        $this->_desc = __('Kiểm tra trạng thái video job từ BizVideo AI', 'ai-copilot-content-generator');
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
                'default' => 'BizVideo Poll Status',
            ),
            'job_id' => array(
                'type' => 'input',
                'label' => __('Job ID *', 'ai-copilot-content-generator'),
                'default' => '{{node#2.job_id}}',
                'variables' => true,
                'desc' => __('Job ID từ node Create Job. Tự động lấy task_id từ DB.', 'ai-copilot-content-generator'),
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
            'task_id' => __('Task ID', 'ai-copilot-content-generator'),
            'status' => __('Trạng thái (pending/processing/completed/failed)', 'ai-copilot-content-generator'),
            'done' => __('Hoàn thành (1/0)', 'ai-copilot-content-generator'),
            'video_url' => __('Video URL (nếu completed)', 'ai-copilot-content-generator'),
            'progress' => __('Tiến độ (%)', 'ai-copilot-content-generator'),
            'message' => __('Thông báo', 'ai-copilot-content-generator'),
            'error' => __('Lỗi (nếu có)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    /**
     * Extract video URL from API response
     * Match với waic_kling_extract_video_url() trong bizcity-video-kling plugin
     */
    private function extractVideoUrl($data) {
        // PiAPI format: output.works[0].video.resource (CHÍNH XÁC!)
        if (!empty($data['output']['works'][0]['video']['resource'])) {
            $url = $data['output']['works'][0]['video']['resource'];
            error_log('[BizCity-Kling] extractVideoUrl found at: output.works[0].video.resource = ' . $url);
            return $url;
        }
        
        // Also check data wrapper
        if (!empty($data['data']['output']['works'][0]['video']['resource'])) {
            $url = $data['data']['output']['works'][0]['video']['resource'];
            error_log('[BizCity-Kling] extractVideoUrl found at: data.output.works[0].video.resource = ' . $url);
            return $url;
        }

        // Fallback candidates for other formats
        $candidates = array(
            'output.video_url' => isset($data['output']['video_url']) ? $data['output']['video_url'] : null,
            'data.output.video_url' => isset($data['data']['output']['video_url']) ? $data['data']['output']['video_url'] : null,
            'data.output.url' => isset($data['data']['output']['url']) ? $data['data']['output']['url'] : null,
            'output.url' => isset($data['output']['url']) ? $data['output']['url'] : null,
            'result.video_url' => isset($data['result']['video_url']) ? $data['result']['video_url'] : null,
            'data.result.video_url' => isset($data['data']['result']['video_url']) ? $data['data']['result']['video_url'] : null,
            'task.output.video_url' => isset($data['task']['output']['video_url']) ? $data['task']['output']['video_url'] : null,
            'video_url' => isset($data['video_url']) ? $data['video_url'] : null,
        );

        foreach ($candidates as $path => $url) {
            if (!empty($url) && is_string($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                error_log('[BizCity-Kling] extractVideoUrl found at fallback: ' . $path . ' = ' . $url);
                return $url;
            }
        }

        // Debug: Log data keys to understand structure
        if (is_array($data)) {
            error_log('[BizCity-Kling] extractVideoUrl NOT FOUND. Top keys: ' . implode(', ', array_keys($data)));
            if (isset($data['output']) && is_array($data['output'])) {
                error_log('[BizCity-Kling] extractVideoUrl output keys: ' . implode(', ', array_keys($data['output'])));
            }
        }

        return '';
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $job_id = (int) trim($this->replaceVariables($this->getParam('job_id'), $variables));
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

        // ⭐ Load task_id từ DB
        if (!class_exists('BizCity_Video_Kling_Database')) {
            $error = 'Plugin BizVideo-Kling chưa được kích hoạt';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        $job_data_db = BizCity_Video_Kling_Database::get_job($job_id);
        if (!$job_data_db || empty($job_data_db->task_id)) {
            $error = 'Không tìm thấy job_id hoặc task_id: ' . $job_id;
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        $task_id = $job_data_db->task_id;

        // API key và endpoint tự động lấy từ plugin bizcity-video-kling
        $api_key = get_option('bizcity_video_kling_api_key', '');
        $endpoint = get_option('bizcity_video_kling_endpoint', self::DEFAULT_ENDPOINT);
        $endpoint = untrailingslashit($endpoint);

        // Job data from transient
        $job_key = 'kling_job_' . $task_id;
        $job_data = get_transient($job_key);

        if (empty($api_key)) {
            $error = 'Chưa cấu hình API key (bizcity_video_kling_api_key)';
            $this->_results = array(
                'result' => array('ok' => 0, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Call API to get status
        $url = $endpoint . '/task/' . rawurlencode($task_id);

        $response = wp_remote_get($url, array(
            'timeout' => 60,
            'headers' => array(
                'X-API-Key' => $api_key,
            ),
        ));

        if (is_wp_error($response)) {
            $error = 'API Error: ' . $response->get_error_message();
            $this->_results = array(
                'result' => array('ok' => 0, 'task_id' => $task_id, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);
        
        // ⭐ Check JSON parse error
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = 'JSON Parse Error: ' . json_last_error_msg() . '. Raw response: ' . mb_substr($raw_body, 0, 200);
            error_log('[BizCity-Kling] poll_status.json_error: ' . $error);
            $this->_results = array(
                'result' => array('ok' => 0, 'task_id' => $task_id, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        error_log('[BizCity-Kling] poll_status.response: ' . wp_json_encode($body));

        if ($http_code < 200 || $http_code >= 300) {
            $error = 'HTTP Error ' . $http_code;
            if (isset($body['error']['message'])) {
                $error = $body['error']['message'];
            }
            $this->_results = array(
                'result' => array('ok' => 0, 'task_id' => $task_id, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Extract status
        $status = 'pending';
        if (isset($body['data']['status'])) {
            $status = strtolower($body['data']['status']);
        } elseif (isset($body['status'])) {
            $status = strtolower($body['status']);
        }

        // Extract progress
        $progress = 0;
        if (isset($body['data']['progress'])) {
            $progress = (int) $body['data']['progress'];
        }

        // Check done status
        $done_statuses = array('succeeded', 'success', 'completed', 'done', 'complete');
        $done = in_array($status, $done_statuses, true) ? 1 : 0;
        
        error_log('[BizCity-Kling] poll_status: status=' . $status . ', done=' . $done);

        // Extract video URL if done
        $video_url = '';
        if ($done) {
            $video_url = $this->extractVideoUrl($body);
            error_log('[BizCity-Kling] poll_status extractVideoUrl(body): ' . ($video_url ?: '(empty)'));
            
            if (empty($video_url) && isset($body['data'])) {
                $video_url = $this->extractVideoUrl($body['data']);
                error_log('[BizCity-Kling] poll_status extractVideoUrl(body.data): ' . ($video_url ?: '(empty)'));
            }
        }

        // Update transient with new status
        if (is_array($job_data)) {
            $job_data['status'] = $status;
            $job_data['progress'] = $progress;
            $job_data['raw_status'] = $body;
            if (!empty($video_url)) {
                $job_data['video_url'] = $video_url;
            }
            set_transient($job_key, $job_data, 7200);
        }

        // ⭐ Sync status to bizcity-video-kling database using job_id directly
        if (class_exists('BizCity_Video_Kling_Database')) {
            // Map status to DB format
            $db_status = $status;
            if (in_array($status, array('pending', 'queued'))) {
                $db_status = 'queued';
            } elseif (in_array($status, array('processing', 'running'))) {
                $db_status = 'processing';
            } elseif ($done) {
                $db_status = 'completed';
            } elseif (in_array($status, array('failed', 'error'))) {
                $db_status = 'failed';
            }

            $update_data = array(
                'status' => $db_status,
                'progress' => $progress,
            );
            if (!empty($video_url)) {
                $update_data['video_url'] = $video_url;
                error_log('[BizCity-Kling] poll_status: Updating video_url in DB: ' . $video_url);
            }

            BizCity_Video_Kling_Database::update_job($job_id, $update_data);
            error_log('[BizCity-Kling] poll_status: Synced to DB job_id=' . $job_id . ', status=' . $db_status . ', video_url=' . ($video_url ?: 'empty'));
        }

        // Build message
        $message = "Trạng thái: {$status}";
        if ($progress > 0) {
            $message .= " ({$progress}%)";
        }
        if ($done && !empty($video_url)) {
            $message = "Video đã hoàn thành!";
        }

        // Failed status
        if (in_array($status, array('failed', 'error'), true)) {
            $error_msg = $body['data']['error']['message'] ?? $body['error']['message'] ?? 'Task failed';
            
            // ⭐ Sync failed status to DB using job_id directly
            if (class_exists('BizCity_Video_Kling_Database')) {
                BizCity_Video_Kling_Database::update_job($job_id, array(
                    'status' => 'failed',
                    'error_message' => $error_msg,
                ));
            }
            
            if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
                twf_telegram_send_message($chat_id, "❌ *Video job thất bại*\n\nTask: `{$task_id}`\nLỗi: {$error_msg}");
            }

            $this->_results = array(
                'result' => array(
                    'ok' => 0,
                    'task_id' => $task_id,
                    'status' => $status,
                    'done' => 0,
                    'error' => $error_msg,
                ),
                'error' => $error_msg,
                'status' => 7,
            );
            return $this->_results;
        }

        // Send update notification if chat_id provided and done
        if (!empty($chat_id) && $done && function_exists('twf_telegram_send_message')) {
            $msg = "✅ *Video đã hoàn thành!*\n\n";
            $msg .= "📋 Task ID: `{$task_id}`\n";
            $msg .= "🎥 Đang tải video xuống...";
            twf_telegram_send_message($chat_id, $msg);
        }

        // ⭐ Debug log kết quả cuối cùng
        error_log('[BizCity-Kling] poll_status FINAL: status=' . $status . ', done=' . $done . ', video_url=' . ($video_url ?: '(empty)'));

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'task_id' => $task_id,
                'status' => $status,
                'done' => $done,
                'video_url' => $video_url,
                'progress' => $progress,
                'message' => $message,
                'error' => '',
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
