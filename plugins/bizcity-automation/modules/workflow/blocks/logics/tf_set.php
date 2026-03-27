<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data Transformation - Set fields
 * - Tạo biến mới (hoặc ghi đè) cho workflow
 * - Hỗ trợ nhiều dòng theo format: key = value
 * - value hỗ trợ variables: {{node#12.some_var}}
 */
class WaicLogic_tf_set extends WaicLogic {
    protected $_code = 'tf_set';
    protected $_subtype = 2;
    protected $_order = 10;

    public function __construct( $block = null ) {
        $this->_name = __('Set (Assign Fields)', 'ai-copilot-content-generator');
        $this->_desc = __('Gán/ghi đè nhiều field để dùng cho các node sau. Mỗi dòng: key = value', 'ai-copilot-content-generator');
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
                'label' => __('Tên node', 'ai-copilot-content-generator'),
                'default' => 'SET',
            ),
            'mode' => array(
                'type' => 'select',
                'label' => __('Cách nhập', 'ai-copilot-content-generator'),
                'default' => 'lines',
                'options' => array(
                    'lines' => __('Nhiều dòng: key = value', 'ai-copilot-content-generator'),
                    'json' => __('JSON object (mỗi key sẽ thành biến)', 'ai-copilot-content-generator'),
                ),
            ),
            'assignments' => array(
                'type' => 'textarea',
                'label' => __('Fields', 'ai-copilot-content-generator'),
                'default' => "title = {{node#1.twf_text}}\nstatus = draft",
                'rows' => 10,
                'variables' => true,
                'show' => array('mode' => array('lines')),
                'desc' => __('Mỗi dòng: key = value. Key có thể chứa [] (vd: meta[foo]).', 'ai-copilot-content-generator'),
            ),
            'json_object' => array(
                'type' => 'textarea',
                'label' => __('JSON Object', 'ai-copilot-content-generator'),
                'default' => "{\n  \"title\": \"{{node#1.twf_text}}\",\n  \"status\": \"draft\"\n}",
                'rows' => 10,
                'variables' => true,
                'show' => array('mode' => array('json')),
            ),
            'value_type' => array(
                'type' => 'select',
                'label' => __('Kiểu value', 'ai-copilot-content-generator'),
                'default' => 'auto',
                'options' => array(
                    'auto' => __('Auto (number/bool/null/json/text)', 'ai-copilot-content-generator'),
                    'text' => __('Text', 'ai-copilot-content-generator'),
                    'number' => __('Number', 'ai-copilot-content-generator'),
                    'bool' => __('Boolean', 'ai-copilot-content-generator'),
                    'json' => __('JSON (array/object)', 'ai-copilot-content-generator'),
                ),
                'show' => array('mode' => array('lines')),
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) {
            $this->_variables = array(
                'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
                'count' => __('Số field đã set', 'ai-copilot-content-generator'),
                'data_json' => __('Toàn bộ field (JSON string)', 'ai-copilot-content-generator'),
            );
        }
        return $this->_variables;
    }

    private function parseAutoValue($raw) {
        $raw = trim((string) $raw);
        if ($raw === '') return '';

        $lower = strtolower($raw);
        if ($lower === 'null') return null;
        if ($lower === 'true') return true;
        if ($lower === 'false') return false;

        // number
        if (is_numeric($raw)) {
            return (strpos($raw, '.') !== false) ? (float) $raw : (int) $raw;
        }

        // json object/array
        if ((strpos($raw, '{') === 0 && substr($raw, -1) === '}') || (strpos($raw, '[') === 0 && substr($raw, -1) === ']')) {
            $decoded = WaicUtils::jsonDecode($raw);
            if (!is_null($decoded)) {
                return $decoded;
            }
        }

        return $raw;
    }

    private function castValue($raw, $type) {
        $raw = trim((string) $raw);
        switch ($type) {
            case 'text':
                return (string) $raw;
            case 'number':
                return (float) $raw;
            case 'bool':
                $v = strtolower($raw);
                return ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on');
            case 'json':
                $decoded = WaicUtils::jsonDecode($raw);
                return is_null($decoded) ? array() : $decoded;
            case 'auto':
            default:
                return $this->parseAutoValue($raw);
        }
    }

    private function parseLines($text, $variables, $valueType) {
        $out = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '//') === 0) continue;

            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $pos));
            $valRaw = trim(substr($line, $pos + 1));
            if ($key === '') continue;

            $valRaw = $this->replaceVariables($valRaw, $variables);
            $out[$key] = $this->castValue($valRaw, $valueType);
        }
        return $out;
    }

    private function parseJsonObject($text, $variables) {
        $text = $this->replaceVariables((string) $text, $variables);
        $decoded = WaicUtils::jsonDecode($text);
        if (is_array($decoded)) {
            return $decoded;
        }
        return array();
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $mode = $this->getParam('mode');
        $valueType = $this->getParam('value_type');

        $data = array();
        if ($mode === 'json') {
            $data = $this->parseJsonObject($this->getParam('json_object'), $variables);
        } else {
            $data = $this->parseLines($this->getParam('assignments'), $variables, $valueType);
        }

        // Export: top-level keys + meta
        $result = $data;
        $result['ok'] = 1;
        $result['count'] = is_array($data) ? count($data) : 0;
        $result['data_json'] = WaicUtils::jsonEncode($data, true);

        $this->_results = array(
            'result' => $result,
            'error' => '',
            'status' => 3,
        );

        return $this->_results;
    }
}
