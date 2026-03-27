<?php
/**
 * Workflow Action: Kling - Fetch Video
 * 
 * Tải video về từ Kling AI và lưu vào Media Library
 * Dùng cho automation workflow trong bizcity-automation
 * 
 * @package BizCity_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_kling_fetch_video extends WaicAction {
    protected $_code = 'kling_fetch_video';
    protected $_order = 103;
    
    const DEFAULT_ENDPOINT = 'https://api.piapi.ai/api/v1';

    public function __construct( $block = null ) {
        $this->_name = __('BizVideo - Tải Video', 'ai-copilot-content-generator');
        $this->_desc = __('Tải video từ BizVideo về Media Library', 'ai-copilot-content-generator');
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
                'default' => 'BizVideo Fetch Video',
            ),
            'job_id' => array(
                'type' => 'input',
                'label' => __('Job ID *', 'ai-copilot-content-generator'),
                'default' => '{{node#2.job_id}}',
                'variables' => true,
                'desc' => __('Job ID từ node Create Job. Tự động lấy video_url và lưu vào Media Library.', 'ai-copilot-content-generator'),
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
            'video_url' => __('Video URL (remote)', 'ai-copilot-content-generator'),
            'local_url' => __('Local URL (sau khi tải)', 'ai-copilot-content-generator'),
            'file_path' => __('Đường dẫn file', 'ai-copilot-content-generator'),
            'attachment_id' => __('Attachment ID', 'ai-copilot-content-generator'),
            'mode' => __('Chế độ', 'ai-copilot-content-generator'),
            'message' => __('Thông báo', 'ai-copilot-content-generator'),
            'error' => __('Lỗi (nếu có)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    /**
     * Save video to uploads folder
     */
    private function saveToUploads($video_url, $filename) {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return array('ok' => false, 'error' => $uploads['error']);
        }

        $base_dir = rtrim($uploads['basedir'], '/\\');
        $base_url = rtrim($uploads['baseurl'], '/\\');

        $sub_dir = $base_dir . DIRECTORY_SEPARATOR . 'bizcity-videos';
        if (!file_exists($sub_dir)) {
            wp_mkdir_p($sub_dir);
        }

        // Download video
        $tmp = download_url($video_url, 300);
        if (is_wp_error($tmp)) {
            return array('ok' => false, 'error' => 'Download failed: ' . $tmp->get_error_message());
        }

        // Sanitize filename
        $filename = preg_replace('/[^a-zA-Z0-9\-\._]/', '-', $filename);
        if (stripos($filename, '.mp4') === false) {
            $filename .= '.mp4';
        }

        $path = $sub_dir . DIRECTORY_SEPARATOR . $filename;
        $ok = @rename($tmp, $path);

        if (!$ok) {
            @copy($tmp, $path);
            @unlink($tmp);
        }

        if (!file_exists($path)) {
            return array('ok' => false, 'error' => 'Cannot save video file');
        }

        $url = $base_url . '/bizcity-videos/' . rawurlencode($filename);
        return array('ok' => true, 'path' => $path, 'url' => $url);
    }

    /**
     * Save video to Media Library
     */
    private function saveToMediaLibrary($video_url, $filename) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Download video
        $tmp = download_url($video_url, 300);
        if (is_wp_error($tmp)) {
            return array('ok' => false, 'error' => 'Download failed: ' . $tmp->get_error_message());
        }

        // Sanitize filename
        $filename = preg_replace('/[^a-zA-Z0-9\-\._]/', '-', $filename);
        if (stripos($filename, '.mp4') === false) {
            $filename .= '.mp4';
        }

        $file = array(
            'name' => $filename,
            'tmp_name' => $tmp,
        );

        $attach_id = media_handle_sideload($file, 0);

        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            return array('ok' => false, 'error' => 'Upload failed: ' . $attach_id->get_error_message());
        }

        $url = wp_get_attachment_url($attach_id);
        $path = get_attached_file($attach_id);

        return array(
            'ok' => true,
            'attachment_id' => $attach_id,
            'url' => $url,
            'path' => $path,
        );
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
        
        // ⭐ Load job data từ DB
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
        
        $task_id = $job_data->task_id;
        $video_url = $job_data->video_url ?? '';
        $script_title = '';
        
        // Debug: Log loaded job data
        error_log('[BizCity-Kling] fetch_video: Loaded from DB - job_id=' . $job_id . ', task_id=' . $task_id . ', video_url from DB=' . ($video_url ?: '(empty)') . ', status=' . ($job_data->status ?? 'unknown'));
        
        // Lấy script title nếu có
        if (!empty($job_data->script_id)) {
            $script_data = BizCity_Video_Kling_Database::get_script($job_data->script_id);
            if ($script_data) {
                $script_title = $script_data->title;
            }
        }
        
        // ⭐ Fallback: Tự poll API nếu vẫn chưa có video_url
        if (empty($video_url) && !empty($task_id)) {
            error_log('[BizCity-Kling] fetch_video: video_url still empty, polling API for task_id=' . $task_id);
            
            $api_key = get_option('bizcity_video_kling_api_key', '');
            $endpoint = get_option('bizcity_video_kling_endpoint', self::DEFAULT_ENDPOINT);
            $endpoint = untrailingslashit($endpoint);
            
            if (!empty($api_key)) {
                $url = $endpoint . '/task/' . rawurlencode($task_id);
                $response = wp_remote_get($url, array(
                    'timeout' => 60,
                    'headers' => array('X-API-Key' => $api_key),
                ));
                
                if (!is_wp_error($response)) {
                    $raw_body = wp_remote_retrieve_body($response);
                    $body = json_decode($raw_body, true);
                    
                    // ⭐ Check JSON parse error
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        error_log('[BizCity-Kling] fetch_video JSON parse error: ' . json_last_error_msg() . ', raw: ' . mb_substr($raw_body, 0, 200));
                    } else {
                        error_log('[BizCity-Kling] fetch_video poll response: ' . wp_json_encode($body));
                        
                        // Extract video_url: try resource first, then resource_without_watermark as fallback
                        $extracted_url = $body['data']['output']['works'][0]['video']['resource'] ?? '';
                        if ( empty( $extracted_url ) ) {
                            $extracted_url = $body['data']['output']['works'][0]['video']['resource_without_watermark'] ?? '';
                            if ( ! empty( $extracted_url ) ) {
                                error_log( '[BizCity-Kling] fetch_video: Using resource_without_watermark fallback: ' . $extracted_url );
                            }
                        }

                        if ( ! empty( $extracted_url ) ) {
                            $video_url = $extracted_url;
                            error_log('[BizCity-Kling] fetch_video: Extracted video_url from API: ' . $video_url);
                            
                            // Update DB
                            if ( class_exists( 'BizCity_Video_Kling_Database' ) ) {
                                BizCity_Video_Kling_Database::update_job( $job_id, array( 'video_url' => $video_url, 'status' => 'completed' ) );
                            }

                            // Update transient
                            $job_key = 'kling_job_' . $task_id;
                            $job_data_t = get_transient($job_key);
                            if (is_array($job_data_t)) {
                                $job_data_t['video_url'] = $video_url;
                                $job_data_t['status'] = 'completed';
                                set_transient($job_key, $job_data_t, 7200);
                            }
                        }
                    }
                }
            }
        }

        if (empty($video_url)) {
            $error = 'Thiếu video URL - Video có thể chưa hoàn thành';
            error_log('[BizCity-Kling] fetch_video ERROR: ' . $error);

            // Notify Zalo admins to follow up on job monitor
            if ( function_exists( 'twf_list_client_ids_by_blog_id' ) && function_exists( 'send_zalo_botbanhang' ) ) {
                $script_id = $job_data->script_id ?? 0;
                if ( $script_id ) {
                    $monitor_url = admin_url( 'admin.php?page=bizcity-kling-scripts&action=generate&id=' . intval( $script_id ) . '&job_id=' . intval( $job_id ) );
                } else {
                    $monitor_url = admin_url( 'admin.php?page=bizcity-kling-scripts' );
                }
                $list_url = admin_url( 'admin.php?page=bizcity-kling-scripts' );

                $notify_msg = "🎬 Video đang được tạo (job #{$job_id})\n\n";
                $notify_msg .= "📊 Theo dõi tiến trình tại:\n{$monitor_url}\n\n";
                $notify_msg .= "📋 Danh sách kịch bản:\n{$list_url}";

                $client_ids = twf_list_client_ids_by_blog_id( get_current_blog_id() );
                $notified = array();
                foreach ( (array) $client_ids as $cid ) {
                    if ( ! empty( $cid ) && ! in_array( $cid, $notified, true ) ) {
                        send_zalo_botbanhang( $notify_msg, $cid, 'text' );
                        $notified[] = $cid;
                    }
                }
            }

            // Also notify single chat_id if provided in workflow
            if ( !empty( $chat_id ) && function_exists( 'twf_telegram_send_message' ) ) {
                $monitor_url_fallback = isset( $monitor_url ) ? $monitor_url : admin_url( 'admin.php?page=bizcity-kling-scripts' );
                twf_telegram_send_message( $chat_id,
                    "🎬 Video đang được tạo (job #{$job_id})\n\n📊 Theo dõi:\n{$monitor_url_fallback}"
                );
            }

            $this->_results = array(
                'result' => array('ok' => 0, 'task_id' => $task_id, 'error' => $error),
                'error' => $error,
                'status' => 7,
            );
            return $this->_results;
        }

        // Generate filename từ script_title hoặc task_id
        $filename = !empty($script_title) 
            ? sanitize_file_name($script_title) . '.mp4'
            : 'bizvideo-' . $task_id . '.mp4';

        error_log('[BizCity-Kling] fetch_video: ' . wp_json_encode(array(
            'task_id' => $task_id,
            'video_url' => $video_url,
            'filename' => $filename,
        )));

        // Tải về Media Library
        $result = $this->saveToMediaLibrary($video_url, $filename);

        if (!$result['ok']) {
            $this->_results = array(
                'result' => array('ok' => 0, 'task_id' => $task_id, 'error' => $result['error']),
                'error' => $result['error'],
                'status' => 7,
            );
            return $this->_results;
        }

        // Update transient
        if (!empty($task_id)) {
            $job_key = 'kling_job_' . $task_id;
            $job_data = get_transient($job_key);
            if (is_array($job_data)) {
                $job_data['local_url'] = $result['url'];
                $job_data['attachment_id'] = $result['attachment_id'];
                set_transient($job_key, $job_data, 7200);
            }

            // ⭐ Sync to bizcity-video-kling database using job_id directly
            if (class_exists('BizCity_Video_Kling_Database')) {
                $update_data = array(
                    'media_url' => $result['url'],
                    'attachment_id' => $result['attachment_id'],
                    'status' => 'completed',
                );
                
                error_log('[BizCity-Kling] fetch_video: Updating DB with: ' . wp_json_encode($update_data));
                
                $updated = BizCity_Video_Kling_Database::update_job($job_id, $update_data);
                
                if ($updated) {
                    error_log('[BizCity-Kling] ✅ fetch_video synced to DB: job_id=' . $job_id);
                    
                    // Verify what was saved
                    $verify_job = BizCity_Video_Kling_Database::get_job($job_id);
                    if ($verify_job) {
                        error_log('[BizCity-Kling] Verified DB save: attachment_id=' . ($verify_job->attachment_id ?? 'empty') . ', media_url=' . ($verify_job->media_url ?? 'empty'));
                    }
                } else {
                    error_log('[BizCity-Kling] ❌ fetch_video FAILED to update DB for job_id=' . $job_id);
                }
            }
        }

        // Send notification
        if (!empty($chat_id) && function_exists('twf_telegram_send_message')) {
            twf_telegram_send_message($chat_id, "✅ *Video đã tải về Media Library*\n\n📎 ID: {$result['attachment_id']}\n🔗 " . $result['url']);
        }

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'task_id' => $task_id,
                'video_url' => $video_url,
                'local_url' => $result['url'],
                'file_path' => $result['path'],
                'attachment_id' => $result['attachment_id'],
                'mode' => 'media',
                'message' => 'Đã tải video về Media Library',
                'error' => '',
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
