<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_ai_video_create_job extends WaicAction {
    protected $_code = 'ai_video_create_job';
    protected $_order = 2;

    const ENDPOINT = 'https://api.openai.com/v1/videos';

    // NEW: supported models (keep in sync with OpenAI error message)
    const SUPPORTED_MODELS = array(
        'sora-2',
        'sora-2-pro',
        'sora-2-2025-10-06',
        'sora-2-pro-2025-10-06',
        'sora-2-2025-12-08',
    );

    public function __construct( $block = null ) {
        $this->_name = __('OpenAI Video - Create Job', 'ai-copilot-content-generator');
        $this->_desc = __('POST /v1/videos (create video job)', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() { if (empty($this->_settings)) $this->setSettings(); return $this->_settings; }

    public function setSettings() {
        $modelOptions = array();
        foreach (self::SUPPORTED_MODELS as $m) {
            $modelOptions[$m] = $m;
        }

        $this->_settings = array(
            'name' => array('type' => 'input', 'label' => __('Node Name', 'ai-copilot-content-generator'), 'default' => ''),

            // CHANGED: default + make it a select to avoid invalid values
            'model' => array(
                'type' => 'select',
                'label' => __('Model', 'ai-copilot-content-generator'),
                'default' => 'sora-2',
                'options' => $modelOptions,
            ),

            'prompt' => array(
                'type' => 'textarea',
                'label' => __('Prompt *', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 8,
                'variables' => true,
            ),
            'params_json' => array(
                'type' => 'textarea',
                'label' => __('Extra params (JSON, optional)', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 6,
                'desc' => __('VD: {"duration":5,"size":"1080p"}', 'ai-copilot-content-generator'),
                'variables' => true,
            ),
        );
    }

    public function getVariables() { if (empty($this->_variables)) $this->setVariables(); return $this->_variables; }

    public function setVariables() {
        $this->_variables = array(
            'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
            'video_id' => __('Video ID', 'ai-copilot-content-generator'),
            'status' => __('Status', 'ai-copilot-content-generator'),
            'http_code' => __('HTTP status code', 'ai-copilot-content-generator'),
            'error_message' => __('Error message', 'ai-copilot-content-generator'),
            'raw' => __('Raw JSON', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    private function getApiKey() {
        return (string) WaicFrame::_()->getModule('options')->get('api', 'api_key');
    }

    // NEW: normalize/validate model, map legacy "sora" -> "sora-2"
    private function normalizeModel($model) {
        $model = trim((string) $model);
        if ($model === '' || strtolower($model) === 'sora') {
            $model = 'sora-2';
        }
        if (!in_array($model, self::SUPPORTED_MODELS, true)) {
            // fallback to safe default
            $model = 'sora-2';
        }
        return $model;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            $err = 'Missing OpenAI API key. Please update api_key in system settings.';
            $this->_results = array('result' => array('ok' => 0), 'error' => $err, 'status' => 7);
            $blog_id = get_current_blog_id();
            $client_id = bizgpt_get_client_id_from_transient($blog_id);
            if ($client_id) {
                twf_telegram_send_message('zalo_' . $client_id, "Sếp ơi, em không thể tạo video được vì thiếu API key OpenAI ạ. Sếp vui lòng cập nhật API key trong phần Tự động hóa -> Cài đặt (Settings) giúp em nhé!");
            }
            return $this->_results;
        }

        $model  = $this->normalizeModel($this->getParam('model'));
        $prompt = $this->replaceVariables($this->getParam('prompt'), $variables);

        $body = array(
            'model'  => $model,
            'prompt' => $prompt,
        );

        $extraRaw = $this->replaceVariables((string)$this->getParam('params_json'), $variables);
        if (trim($extraRaw) !== '') {
            $extra = json_decode($extraRaw, true);
            if (is_array($extra)) {
                $body = array_merge($body, $extra);
            }
        }

        $resp = wp_remote_post(self::ENDPOINT, array(
            'timeout' => 200,
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($resp)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'video_id' => '', 'status' => '', 'http_code' => 0, 'error_message' => $resp->get_error_message(), 'raw' => ''),
                'error' => $resp->get_error_message(),
                'status' => 7,
            );
            return $this->_results;
        }

        $httpCode = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $decoded = json_decode($raw, true);

        $videoId = is_array($decoded) ? (string) ($decoded['id'] ?? '') : '';
        $status  = is_array($decoded) ? (string) ($decoded['status'] ?? '') : '';

        $errMsg = '';
        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $errMsg = (string) $decoded['error']['message'];
        } elseif ($httpCode >= 400) {
            $errMsg = 'HTTP error ' . $httpCode;
        }

        $ok = ($httpCode >= 200 && $httpCode < 300 && $videoId !== '') ? 1 : 0;

        $this->_results = array(
            'result' => array(
                'ok' => $ok,
                'video_id' => $videoId,
                'status' => $status,
                'http_code' => $httpCode,
                'error_message' => $errMsg,
                'raw' => $raw,
            ),
            'error' => $ok ? '' : ($errMsg !== '' ? $errMsg : 'Create video job failed.'),
            'status' => $ok ? 3 : 7,
        );
        return $this->_results;
    }
}