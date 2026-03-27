<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Data Transformation - Rename keys
 * - Lấy 1 object/array từ ref (vd: node#12.data_json / node#12.some_field)
 * - Rename theo mapping nhiều dòng: old_key = new_key
 * - Xuất json + biến phẳng (data[new_key])
 */
class WaicLogic_tf_rename_keys extends WaicLogic {
    protected $_code = 'tf_rename_keys';
    protected $_subtype = 2;
    protected $_order = 11;

    public function __construct( $block = null ) {
        $this->_name = __('Rename Keys', 'ai-copilot-content-generator');
        $this->_desc = __('Đổi tên keys trong một object/JSON theo mapping.', 'ai-copilot-content-generator');
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
                'default' => 'RENAME',
            ),
            'source_ref' => array(
                'type' => 'input',
                'label' => __('Nguồn dữ liệu (ref)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('Nhập dạng: node#12.some_key. Nếu some_key là JSON string sẽ tự parse.', 'ai-copilot-content-generator'),
            ),
            'mapping' => array(
                'type' => 'textarea',
                'label' => __('Mapping (old = new)', 'ai-copilot-content-generator'),
                'default' => "old_key = new_key\nprice = product_price",
                'rows' => 10,
                'desc' => __('Mỗi dòng: old_key = new_key. Chỉ áp dụng cho top-level keys.', 'ai-copilot-content-generator'),
            ),
            'keep_unmapped' => array(
                'type' => 'select',
                'label' => __('Giữ key không có mapping', 'ai-copilot-content-generator'),
                'default' => 1,
                'options' => array(0 => __('no', 'ai-copilot-content-generator'), 1 => __('yes', 'ai-copilot-content-generator')),
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) {
            $this->_variables = array(
                'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
                'data_json' => __('Object sau rename (JSON string)', 'ai-copilot-content-generator'),
                'count' => __('Số key đã rename', 'ai-copilot-content-generator'),
            );
        }
        return $this->_variables;
    }

    private function resolveRefValue($ref, $variables) {
        $ref = trim((string) $ref);
        if ($ref === '') return null;

        // allow variables in ref itself
        $ref = $this->replaceVariables($ref, $variables);

        $parts = explode('.', $ref, 2);
        if (count($parts) === 2) {
            $node = $parts[0];
            $key = $parts[1];
            if (isset($variables[$node]) && isset($variables[$node][$key])) {
                return $variables[$node][$key];
            }
            return null;
        }

        return isset($variables[$ref]) ? $variables[$ref] : null;
    }

    private function parseMapping($text) {
        $map = array();
        $lines = preg_split('/\r\n|\r|\n/', (string) $text);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;
            if (strpos($line, '#') === 0) continue;
            if (strpos($line, '//') === 0) continue;

            $pos = strpos($line, '=');
            if ($pos === false) continue;

            $old = trim(substr($line, 0, $pos));
            $new = trim(substr($line, $pos + 1));
            if ($old === '' || $new === '') continue;

            $map[$old] = $new;
        }
        return $map;
    }

    private function normalizeSource($value) {
        if (is_array($value)) return $value;

        // attempt json decode
        if (is_string($value)) {
            $decoded = WaicUtils::jsonDecode($value);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array();
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $src = $this->resolveRefValue($this->getParam('source_ref'), $variables);
        $data = $this->normalizeSource($src);

        $map = $this->parseMapping($this->getParam('mapping'));
        $keep = (int) $this->getParam('keep_unmapped', 1, 1) === 1;

        $out = array();
        $renamedCount = 0;

        // If keep unmapped, start from original
        if ($keep) {
            $out = $data;
        }

        foreach ($map as $old => $new) {
            if (array_key_exists($old, $data)) {
                $out[$new] = $data[$old];
                if (!$keep && $new !== $old) {
                    $renamedCount++;
                } else if ($keep) {
                    // remove old key when keep enabled
                    if ($new !== $old) {
                        unset($out[$old]);
                        $renamedCount++;
                    }
                }
            }
        }

        // Export variables (flat): data[newKey]
        $result = array(
            'ok' => 1,
            'count' => $renamedCount,
            'data_json' => WaicUtils::jsonEncode($out, true),
        );

        if (is_array($out)) {
            foreach ($out as $k => $v) {
                // make it easy to reference nested-ish values
                $result['data[' . $k . ']'] = $v;
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
