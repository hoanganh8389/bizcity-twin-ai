<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_ai_video_get_content extends WaicAction {
    protected $_code = 'ai_video_get_content';
    protected $_order = 4;

    const BASE = 'https://api.openai.com/v1/videos/';

    public function __construct( $block = null ) {
        $this->_name = __('OpenAI Video - Get Content', 'ai-copilot-content-generator');
        $this->_desc = __('GET /v1/videos/{video_id}/content (download bytes -> save -> url)', 'ai-copilot-content-generator');
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
            'filename' => array(
                'type' => 'input',
                'label' => __('Filename (optional)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('VD: sora-{{node#120.video_id}}.mp4', 'ai-copilot-content-generator'),
            ),
        );
    }

    public function getVariables() { if (empty($this->_variables)) $this->setVariables(); return $this->_variables; }

    public function setVariables() {
        $this->_variables = array(
            'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
            'video_url' => __('Video URL', 'ai-copilot-content-generator'),
            'file_path' => __('Saved file path', 'ai-copilot-content-generator'),
            'http_code' => __('HTTP status code', 'ai-copilot-content-generator'),
            'error_message' => __('Error message', 'ai-copilot-content-generator'),
            'raw' => __('Raw (string)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    private function getApiKey() {
        return (string) WaicFrame::_()->getModule('options')->get('api', 'api_key');
    }

    private function saveBytesToUploads($bytes, $filename = '') {
        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('upload_dir_error', (string) $uploads['error']);
        }

        $baseDir = rtrim((string) $uploads['basedir'], '/\\');
        $baseUrl = rtrim((string) $uploads['baseurl'], '/\\');

        $subDir = $baseDir . DIRECTORY_SEPARATOR . 'bizcity-videos';
        if (!file_exists($subDir)) {
            wp_mkdir_p($subDir);
        }

        if ($filename === '') {
            $filename = 'video-' . gmdate('Ymd-His') . '.mp4';
        }

        // sanitize filename
        $filename = preg_replace('/[^a-zA-Z0-9\-\._]/', '-', $filename);
        if (stripos($filename, '.mp4') === false) {
            $filename .= '.mp4';
        }

        $path = $subDir . DIRECTORY_SEPARATOR . $filename;
        $ok = @file_put_contents($path, $bytes);

        if ($ok === false) {
            return new WP_Error('write_failed', 'Cannot write video file.');
        }

        $url = $baseUrl . '/bizcity-videos/' . rawurlencode($filename);
        return array('path' => $path, 'url' => $url);
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $apiKey = $this->getApiKey();
        $videoId = trim((string) $this->replaceVariables($this->getParam('video_id'), $variables));
        $filename = trim((string) $this->replaceVariables($this->getParam('filename'), $variables));

        if ($apiKey === '' || $videoId === '') {
            $err = ($apiKey === '') ? 'Missing OpenAI API key.' : 'Missing video_id.';
            $this->_results = array('result' => array('ok' => 0), 'error' => $err, 'status' => 7);
            return $this->_results;
        }

        $url = self::BASE . rawurlencode($videoId) . '/content';

        $resp = wp_remote_request($url, array(
            'timeout' => 200,
            'method' => 'GET',
            'headers' => array(
                'Authorization' => 'Bearer ' . $apiKey,
            ),
        ));

        if (is_wp_error($resp)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'video_url' => '', 'file_path' => '', 'http_code' => 0, 'error_message' => $resp->get_error_message(), 'raw' => ''),
                'error' => $resp->get_error_message(),
                'status' => 7,
            );
            return $this->_results;
        }

        $httpCode = (int) wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);

        // Some APIs may return JSON error; detect & pass through
        $maybeJson = json_decode((string)$body, true);
        if (is_array($maybeJson) && !empty($maybeJson['error']['message'])) {
            $errMsg = (string) $maybeJson['error']['message'];
            $this->_results = array(
                'result' => array('ok' => 0, 'video_url' => '', 'file_path' => '', 'http_code' => $httpCode, 'error_message' => $errMsg, 'raw' => (string)$body),
                'error' => $errMsg,
                'status' => 7,
            );
            return $this->_results;
        }

        if ($httpCode < 200 || $httpCode >= 300 || $body === '') {
            $errMsg = 'HTTP error ' . $httpCode;
            $this->_results = array(
                'result' => array('ok' => 0, 'video_url' => '', 'file_path' => '', 'http_code' => $httpCode, 'error_message' => $errMsg, 'raw' => is_string($body) ? $body : ''),
                'error' => $errMsg,
                'status' => 7,
            );
            return $this->_results;
        }

        $saved = $this->saveBytesToUploads($body, $filename);
        if (is_wp_error($saved)) {
            $this->_results = array(
                'result' => array('ok' => 0, 'video_url' => '', 'file_path' => '', 'http_code' => $httpCode, 'error_message' => $saved->get_error_message(), 'raw' => ''),
                'error' => $saved->get_error_message(),
                'status' => 7,
            );
            return $this->_results;
        }

        $this->_results = array(
            'result' => array(
                'ok' => 1,
                'video_url' => (string) $saved['url'],
                'file_path' => (string) $saved['path'],
                'http_code' => $httpCode,
                'error_message' => '',
                'raw' => '',
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}