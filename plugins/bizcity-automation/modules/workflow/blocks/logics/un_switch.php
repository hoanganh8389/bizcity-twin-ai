<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicLogic_un_switch extends WaicLogic {
    protected $_code = 'un_switch';
    protected $_subtype = 2;
    protected $_order = 2;

    public function __construct( $block = null ) {
        $this->_name = __('Switch / Case (Chuyển / Trường hợp)', 'ai-copilot-content-generator');
        $this->_desc = __('So khớp giá trị và rẽ nhánh theo nhiều case. Nhánh cuối (Case 3) là DEFAULT.', 'ai-copilot-content-generator');
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
                'default' => 'SWITCH',
            ),
            'criteria' => array(
                'type' => 'input',
                'label' => __('Giá trị cần so khớp (criteria)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
            ),
            'match' => array(
                'type' => 'select',
                'label' => __('Kiểu so khớp', 'ai-copilot-content-generator'),
                'default' => 'equals',
                'options' => array(
                    'equals'   => __('Bằng nhau (equals)', 'ai-copilot-content-generator'),
                    'contains' => __('Chứa (contains)', 'ai-copilot-content-generator'),
                ),
            ),
            'compare' => array(
                'type' => 'select',
                'label' => __('So sánh theo', 'ai-copilot-content-generator'),
                'default' => 'text',
                'options' => array(
                    'text'   => __('Chuỗi (Text)', 'ai-copilot-content-generator'),
                    'number' => __('Số (Number)', 'ai-copilot-content-generator'),
                ),
            ),

            // Case 1/2: nhập nhiều giá trị, phân tách bằng dấu phẩy
            'case_1' => array(
                'type' => 'input',
                'label' => __('Case 1 - Values (phân tách bằng dấu phẩy)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
            ),
            'case_2' => array(
                'type' => 'input',
                'label' => __('Case 2 - Values (phân tách bằng dấu phẩy)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
            ),

            // Case 3: DEFAULT (không match theo values)
            'case_3' => array(
                'type' => 'input',
                'label' => __('Case 3 (DEFAULT) - nhánh mặc định', 'ai-copilot-content-generator'),
                'default' => 'DEFAULT',
                'readonly' => true,
                'desc' => __('Nhánh này luôn chạy khi criteria không khớp Case 1/2.', 'ai-copilot-content-generator'),
            ),
        );
    }

    private function parseCsvValues($raw, $isNumber) {
        $raw = (string) $raw;
        $raw = trim($raw);
        if ($raw === '') return array();

        $parts = explode(',', $raw);
        $out = array();
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;
            $out[] = $isNumber ? (float) $p : (string) $p;
        }
        return array_values(array_unique($out));
    }

    private function matchOne($criteria, $candidate, $mode) {
        if ($mode === 'contains') {
            return WaicUtils::mbstrpos((string) $criteria, (string) $candidate) !== false;
        }
        return $criteria == $candidate;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $criteria = $this->replaceVariables($this->getParam('criteria'), $variables);
        $mode = $this->getParam('match');
        $isNumber = ($this->getParam('compare') === 'number');

        $criteriaNorm = $isNumber ? (float) $criteria : (string) $criteria;

        $matchedCase = 0;

        // CHỈ match case 1..2. Case 3 là DEFAULT.
        for ($i = 1; $i <= 2; $i++) {
            $raw = $this->replaceVariables($this->getParam('case_' . $i), $variables);
            $list = $this->parseCsvValues($raw, $isNumber);

            if (empty($list)) continue;

            foreach ($list as $candidate) {
                if ($this->matchOne($criteriaNorm, $candidate, $mode)) {
                    $matchedCase = $i;
                    break 2;
                }
            }
        }

        // Default -> output-case-3
        $sourceHandle = $matchedCase ? ('output-case-' . $matchedCase) : 'output-case-3';

        $this->_results = array(
            'result' => array(
                'criteria' => $criteriaNorm,
                'matched_case' => $matchedCase,
                'handle' => $sourceHandle,
            ),
            'error' => '',
            'status' => 3,
            'sourceHandle' => $sourceHandle,
        );

        return $this->_results;
    }
}