<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicLogic_un_split_path extends WaicLogic {
    protected $_code = 'un_split_path';
    protected $_subtype = 2;
    protected $_order = 3;

    public function __construct( $block = null ) {
        $this->_name = __('Tách mảng', 'ai-copilot-content-generator');
        $this->_desc = __('Tách mảng thành các biến nhỏ để xử lý if else hoặc switch case', 'ai-copilot-content-generator');
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
                'label' => __('Node Name', 'ai-copilot-content-generator'),
                'default' => 'Split',
            ),
            'paths' => array(
                'type' => 'input',
                'label' => __('Paths (comma separated labels)', 'ai-copilot-content-generator'),
                'default' => 'A,B,C',
                'variables' => true,
            ),
            'mode' => array(
                'type' => 'select',
                'label' => __('Mode', 'ai-copilot-content-generator'),
                'default' => 'by_index',
                'options' => array(
                    'by_index' => __('By index (choose branch number)', 'ai-copilot-content-generator'),
                    'by_value' => __('By value (match a label)', 'ai-copilot-content-generator'),
                ),
            ),
            'index_var' => array(
                'type' => 'input',
                'label' => __('Index or variable (1-based)', 'ai-copilot-content-generator'),
                'default' => '1',
                'variables' => true,
                'show' => array('mode' => array('by_index')),
            ),
            'value_var' => array(
                'type' => 'input',
                'label' => __('Value or variable to match', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'show' => array('mode' => array('by_value')),
            ),
        );
    }

    private function parsePaths($raw) {
        $raw = (string) $raw;
        $raw = trim($raw);
        if ($raw === '') return array();
        $parts = explode(',', $raw);
        $out = array();
        foreach ($parts as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;
            $out[] = $p;
        }
        return array_values($out);
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        // Lấy array từ biến đầu vào (ví dụ: {{node#1.missing}})
        $arrayRaw = $this->replaceVariables($this->getParam('paths'), $variables);
        // Nếu là JSON, decode
        $array = is_string($arrayRaw) ? json_decode($arrayRaw, true) : $arrayRaw;
        if (!is_array($array)) {
            // Nếu không phải array, thử parse thủ công (phòng trường hợp truyền vào là "A,B,C")
            $array = $this->parsePaths($arrayRaw);
        }
        if (empty($array)) {
            $this->_results = array(
                'result' => array('items' => $array),
                'error' => __('No array data found', 'ai-copilot-content-generator'),
                'status' => 7,
                'sourceHandle' => 'output-right',
            );
            return $this->_results;
        }

        // Tạo biến node#id.0, node#id.1,... cho từng phần tử
        $nodeId = isset($this->_block['id']) ? $this->_block['id'] : 'split';
        $itemVars = [];
        foreach ($array as $i => $val) {
            $itemVars["node#{$nodeId}.{$i}"] = $val;
        }

        $this->_results = array(
            'result' => array(
                'items' => $array,
                'itemVars' => $itemVars,
            ),
            'error' => '',
            'status' => 3,
        );
        return $this->_results;
    }
}
