<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data Transformation - Binary ↔ JSON (base64)
 * - json_to_base64: encode JSON string -> base64
 * - base64_to_json: decode base64 -> JSON string + parsed array
 */
class WaicLogic_tf_binary_json extends WaicLogic {
    protected $_code = 'tf_binary_json';
    protected $_subtype = 2;
    protected $_order = 13;

    public function __construct( $block = null ) {
        $this->_name = __('Binary ↔ JSON', 'ai-copilot-content-generator');
        $this->_desc = __('Chuyển JSON <-> base64 (mô phỏng binary).', 'ai-copilot-content-generator');
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
                'default' => 'BINJSON',
            ),
            'mode' => array(
                'type' => 'select',
                'label' => __('Mode', 'ai-copilot-content-generator'),
                'default' => 'base64_to_json',
                'options' => array(
                    'base64_to_json' => __('Base64 → JSON', 'ai-copilot-content-generator'),
                    'json_to_base64' => __('JSON → Base64', 'ai-copilot-content-generator'),
                ),
            ),
            'input' => array(
                'type' => 'textarea',
                'label' => __('Input', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 8,
                'variables' => true,
            ),
            'pretty' => array(
                'type' => 'select',
                'label' => __('Pretty JSON', 'ai-copilot-content-generator'),
                'default' => 1,
                'options' => array(0 => __('no', 'ai-copilot-content-generator'), 1 => __('yes', 'ai-copilot-content-generator')),
                'show' => array('mode' => array('base64_to_json')),
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) {
            $this->_variables = array(
                'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
                'base64' => __('Base64 output', 'ai-copilot-content-generator'),
                'json' => __('JSON string output', 'ai-copilot-content-generator'),
                'data_json' => __('Parsed data (JSON string)', 'ai-copilot-content-generator'),
            );
        }
        return $this->_variables;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $mode = $this->getParam('mode');
        $input = $this->replaceVariables($this->getParam('input'), $variables);

        $result = array('ok' => 1);

        if ($mode === 'json_to_base64') {
            // allow raw text too, not only json
            $result['base64'] = base64_encode((string) $input);
        } else {
            $pretty = (int) $this->getParam('pretty', 1, 1) === 1;
            $decoded = base64_decode((string) $input, true);
            if ($decoded === false) {
                $this->_results = array(
                    'result' => array('ok' => 0),
                    'error' => __('Invalid base64 input', 'ai-copilot-content-generator'),
                    'status' => 7,
                );
                return $this->_results;
            }

            $result['json'] = (string) $decoded;
            $parsed = WaicUtils::jsonDecode($decoded);
            if (!is_null($parsed)) {
                $result['data_json'] = WaicUtils::jsonEncode($parsed, $pretty);
            } else {
                $result['data_json'] = '';
            }
        }

        $this->_results = array(
            'result' => $result,
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
