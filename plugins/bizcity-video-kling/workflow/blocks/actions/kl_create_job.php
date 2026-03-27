<?php
/**
 * Workflow Action: Kling - Create Job
 * 
 * Tạo video generation task và lưu task_id để polling
 */

if (!defined('ABSPATH')) exit;

require_once BIZCITY_VIDEO_KLING_DIR . 'lib/kling_api.php';

class WaicAction_kl_create_job extends WaicAction {
    protected $_code  = 'kl_create_job';
    protected $_order = 210;

    public function __construct($block = null) {
        $this->_name = __('Kling - Create Job', 'bizcity-video-kling');
        $this->_desc = __('Tạo video từ ảnh hoặc text với Kling AI', 'bizcity-video-kling');
        $this->_sublabel = ['name'];
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) $this->setSettings();
        return $this->_settings;
    }

    public function setSettings() {
        $models = waic_kling_get_models();
        $model_options = [];
        foreach ($models as $key => $model) {
            $model_options[$key] = $model['label'];
        }
        
        $this->_settings = [
            'name' => [
                'type' => 'input',
                'label' => __('Tên Node', 'bizcity-video-kling'),
                'default' => 'Tạo Video Kling',
            ],
            
            // API Settings
            'api_key' => [
                'type' => 'input',
                'label' => __('API Key PiAPI', 'bizcity-video-kling'),
                'default' => '',
                'desc' => __('Để trống để dùng API key từ Settings', 'bizcity-video-kling'),
            ],
            'endpoint' => [
                'type' => 'input',
                'label' => __('API Endpoint', 'bizcity-video-kling'),
                'default' => 'https://api.piapi.ai/api/v1',
            ],
            'model' => [
                'type' => 'select',
                'label' => __('Model', 'bizcity-video-kling'),
                'default' => 'kling-v1',
                'options' => $model_options,
            ],
            
            // Task Settings
            'task_type' => [
                'type' => 'select',
                'label' => __('Loại Task', 'bizcity-video-kling'),
                'default' => 'image_to_video',
                'options' => waic_kling_get_task_types(),
            ],
            'image_url' => [
                'type' => 'input',
                'label' => __('URL Ảnh', 'bizcity-video-kling'),
                'default' => '{{trigger.image_url}}',
                'desc' => __('Cho image_to_video. Có thể dùng {{variable}}', 'bizcity-video-kling'),
            ],
            'prompt' => [
                'type' => 'textarea',
                'label' => __('Prompt Mô Tả', 'bizcity-video-kling'),
                'default' => '{{trigger.text}}',
                'placeholder' => 'A dynamic video showing...',
            ],
            
            // Video Settings
            'duration' => [
                'type' => 'select',
                'label' => __('Độ Dài (giây)', 'bizcity-video-kling'),
                'default' => '30',
                'options' => [
                    '5'  => '5s',
                    '10' => '10s',
                    '15' => '15s',
                    '20' => '20s',
                    '30' => '30s',
                ],
            ],
            'aspect_ratio' => [
                'type' => 'select',
                'label' => __('Tỷ Lệ Khung Hình', 'bizcity-video-kling'),
                'default' => '9:16',
                'options' => waic_kling_get_aspect_ratios(),
            ],
            
            // Job Management
            'job_id' => [
                'type' => 'input',
                'label' => __('Job ID', 'bizcity-video-kling'),
                'default' => '{{trigger.id}}',
                'desc' => __('Để trống sẽ tự tạo. Dùng {{trigger.id}} để track theo trigger.', 'bizcity-video-kling'),
            ],
            'store_ttl' => [
                'type' => 'input',
                'label' => __('Thời gian lưu cache (giây)', 'bizcity-video-kling'),
                'default' => '7200',
                'desc' => __('2 giờ = 7200 giây', 'bizcity-video-kling'),
            ],
        ];
    }

    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'ok'        => __('OK (1/0)', 'bizcity-video-kling'),
            'job_id'    => __('Job ID', 'bizcity-video-kling'),
            'task_id'   => __('Task ID', 'bizcity-video-kling'),
            'store_key' => __('Transient Key', 'bizcity-video-kling'),
            'message'   => __('Message', 'bizcity-video-kling'),
            'error'     => __('Error message', 'bizcity-video-kling'),
        );
        return $this->_variables;
    }

    public function getResults($taskId, $variables, $step = 0) {
        // Get settings with variable replacement
        $api_key = trim($this->replaceVariables($this->getParam('api_key'), $variables));
        if (empty($api_key)) {
            $api_key = get_option('bizcity_video_kling_api_key', '');
        }
        
        $endpoint = trim($this->replaceVariables($this->getParam('endpoint'), $variables));
        if (empty($endpoint)) {
            $endpoint = get_option('bizcity_video_kling_endpoint', 'https://api.piapi.ai/api/v1');
        }

        $s = array(
            'api_key'      => $api_key,
            'endpoint'     => $endpoint,
            'model'        => $this->getParam('model', 'kling-v1'),
            'task_type'    => $this->getParam('task_type', 'image_to_video'),
            'image_url'    => $this->replaceVariables($this->getParam('image_url'), $variables),
            'prompt'       => $this->replaceVariables($this->getParam('prompt'), $variables),
            'duration'     => $this->getParam('duration', 30),
            'aspect_ratio' => $this->getParam('aspect_ratio', '9:16'),
            'job_id'       => $this->replaceVariables($this->getParam('job_id'), $variables),
            'store_ttl'    => $this->getParam('store_ttl', 7200),
        );

        // Generate job_id
        $job_id = !empty($s['job_id']) ? (string)$s['job_id'] : uniqid('kling_', true);
        $ttl    = max(300, (int)$s['store_ttl']);

        // Build input
        $prompt = trim((string)$s['prompt']);
        if (empty($prompt)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => 'Prompt is required'),
                'error' => 'Prompt is required',
                'status' => 7,
            );
            return $this->_results;
        }
        
        $duration = (int)$s['duration'];
        if ($duration <= 0) $duration = 30;

        $input = array(
            'prompt'       => $prompt,
            'duration'     => $duration,
            'aspect_ratio' => (string)$s['aspect_ratio'],
        );

        // Add image_url for image_to_video
        if ($s['task_type'] === 'image_to_video') {
            $image_url = trim((string)$s['image_url']);
            if (empty($image_url)) {
                $this->_results = array(
                    'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => 'Image URL required'),
                    'error' => 'Image URL is required for image_to_video',
                    'status' => 7,
                );
                return $this->_results;
            }
            $input['image_url'] = $image_url;
        }

        waic_kling_log('create_job.request', array('job_id' => $job_id, 'input' => $input));

        // Call API
        $r = waic_kling_create_task($s, $input);
        if (!$r['ok']) {
            waic_kling_log('create_job.error', $r);
            $this->_results = array(
                'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => $r['error'] ?? 'Create task failed'),
                'error' => $r['error'] ?? 'Create task failed',
                'status' => 7,
            );
            return $this->_results;
        }

        // Extract task_id (PiAPI format)
        $data = $r['data'];
        $task_id = $data['task_id'] ?? ($data['data']['task_id'] ?? null);
        
        if (!$task_id) {
            waic_kling_log('create_job.missing_task_id', $data);
            $this->_results = array(
                'result' => array('ok' => 0, 'job_id' => $job_id, 'error' => 'Missing task_id'),
                'error' => 'Missing task_id in response',
                'status' => 7,
            );
            return $this->_results;
        }

        // Store job info in transient
        $key = waic_kling_job_key($job_id);
        $job = array(
            'job_id'      => $job_id,
            'task_id'     => $task_id,
            'created'     => time(),
            'status'      => 'created',
            'input'       => $input,
            'settings'    => $s,
            'raw_create'  => $data,
        );
        set_transient($key, $job, $ttl);

        waic_kling_log('create_job.success', array('job_id' => $job_id, 'task_id' => $task_id));

        // Return result
        $this->_results = array(
            'result' => array(
                'ok'        => 1,
                'job_id'    => $job_id,
                'task_id'   => $task_id,
                'store_key' => $key,
                'message'   => sprintf(__('Đã tạo job %s với task_id %s', 'bizcity-video-kling'), $job_id, $task_id),
            ),
            'error' => '',
            'status' => 1,
        );
        return $this->_results;
    }
}
