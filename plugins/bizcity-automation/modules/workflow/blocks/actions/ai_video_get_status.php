<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_ai_video_get_status extends WaicAction {
    protected $_code = 'ai_video_get_status';
    protected $_order = 3;

    const BASE = 'https://api.openai.com/v1/videos/';

    public function __construct( $block = null ) {
        $this->_name = __('OpenAI Video - Get Status', 'ai-copilot-content-generator');
        $this->_desc = __('GET /v1/videos/{video_id}', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() { if (empty($this->_settings)) $this->setSettings(); return $this->_settings; }

    public function setSettings() {
        $this->_settings = array(
            'name' => array('type' => 'input', 'label' => __('Node Name', 'ai-copilot-content-generator'), 'default' => ''),
            'video_id' => array(
                'type' => 'input',
                'label' => __('Video ID *', 'ai-copilot-content-generator'),
                'default' => '',
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
            'done' => __('Done (1/0)', 'ai-copilot-content-generator'),
            'http_code' => __('HTTP status code', 'ai-copilot-content-generator'),
            'error_message' => __('Error message', 'ai-copilot-content-generator'),
            'raw' => __('Raw JSON', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    private function getApiKey() {
        return (string) WaicFrame::_()->getModule('options')->get('api', 'api_key');
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $apiKey = $this->getApiKey();
        $videoId = trim((string) $this->replaceVariables($this->getParam('video_id'), $variables));

        if ($apiKey === '' || $videoId === '') {
            $err = ($apiKey === '') ? 'Missing OpenAI API key.' : 'Missing video_id.';
            $this->_results = array('result' => array('ok' => 0), 'error' => $err, 'status' => 7);
            return $this->_results;
        }

        $url = self::BASE . rawurlencode($videoId);

        $resp = wp_remote_request($url, array(
            'timeout' => 200,
            'method' => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
            ),
        ));

        if (is_wp_error($resp)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'video_id' => $videoId, 'status' => '', 'done' => 0, 'http_code' => 0, 'error_message' => $resp->get_error_message(), 'raw' => ''),
                'error' => $resp->get_error_message(),
                'status' => 7,
            );
            return $this->_results;
        }

        $httpCode = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $decoded = json_decode($raw, true);

        $status = is_array($decoded) ? (string) ($decoded['status'] ?? '') : '';
        $errMsg = '';
        if (is_array($decoded) && !empty($decoded['error']['message'])) {
            $errMsg = (string) $decoded['error']['message'];
        } elseif ($httpCode >= 400) {
            $errMsg = 'HTTP error ' . $httpCode;
        }

        // Best-effort "done" detection
        $doneStatuses = array('succeeded', 'completed', 'done', 'success');
        $done = in_array(strtolower($status), $doneStatuses, true) ? 1 : 0;

        $ok = ($httpCode >= 200 && $httpCode < 300) ? 1 : 0;

        $this->_results = array(
            'result' => array(
                'ok' => $ok,
                'video_id' => $videoId,
                'status' => $status,
                'done' => $done,
                'http_code' => $httpCode,
                'error_message' => $errMsg,
                'raw' => $raw,
            ),
            'error' => $ok ? '' : ($errMsg !== '' ? $errMsg : 'Get status failed.'),
            'status' => $ok ? 3 : 7,
        );
        return $this->_results;
    }
}