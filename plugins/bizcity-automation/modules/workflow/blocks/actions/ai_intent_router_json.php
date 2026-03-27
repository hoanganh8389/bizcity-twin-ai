<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * BizCity - Intent Router (Hard Prompt) -> JSON + parsed variables
 * - Gọi OpenAI để trả về JSON theo schema cố định
 * - Parse JSON và export biến phẳng để workflow/logic dùng ngay
 */
class WaicAction_ai_intent_router_json extends WaicAction {
    protected $_code  = 'ai_intent_router_json';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('BizCity - AI router mặc định', 'ai-copilot-content-generator');
        $this->_desc = __('Được cài đặt trong cấu hình + trích xuất dữ liệu, trả JSON và biến đã parse', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) $this->setSettings();
        return $this->_settings;
    }

    public function setSettings() {
        $modelOptions = WaicFrame::_()->getModule('options')->getModel();

        // Try to keep the node's supported types in sync with Router options (Settings > Router)
        $supportedTypesDefault = "huong_dan\nviet_bai\ntao_video\ntao_san_pham\ntra_loi_khach\nkhac";
        $typesListOpt = $this->bizcityGetRouterOption('types_list');
        if (is_string($typesListOpt) && trim($typesListOpt) !== '') {
            $supportedTypesDefault = trim($typesListOpt);
        } else {
            $typesJsonOpt = (string) $this->bizcityGetRouterOption('types_json');
            $val = json_decode($typesJsonOpt, true);
            if (is_array($val) && !empty($val)) {
                $types = array();
                foreach ($val as $row) {
                    if (!is_array($row)) continue;
                    $type = empty($row['type']) ? '' : trim((string)$row['type']);
                    if ($type !== '') $types[] = $type;
                }
                if (!empty($types)) {
                    $supportedTypesDefault = implode("\n", $types);
                }
            }
        }

        $this->_settings = array(
            'name' => array(
                'type' => 'input',
                'label' => __('Node Name', 'ai-copilot-content-generator'),
                'default' => '',
            ),
            'model' => array(
                'type' => 'select',
                'label' => __('Model', 'ai-copilot-content-generator'),
                'default' => $modelOptions->getDefaults('api', 'model'),
                'options' => $modelOptions->getVariations('api', 'model', 'open-ai'),
            ),
            'tokens' => array(
                'type' => 'number',
                'label' => __('Max Tokens', 'ai-copilot-content-generator'),
                'default' => 1200,
            ),
            'temperature' => array(
                'type' => 'number',
                'label' => __('Temperature', 'ai-copilot-content-generator'),
                'default' => 0.2,
                'step' => '0.01',
                'min' => 0,
                'max' => 2,
            ),

            'message' => array(
                'type' => 'textarea',
                'label' => __('Tin nhắn đầu vào (message)', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_text}}',
                'rows' => 3,
                'variables' => true,
                'desc' => __('Nội dung người dùng gửi vào để router phân loại.', 'ai-copilot-content-generator'),
            ),

            'use_router_options' => array(
                'type' => 'select',
                'label' => __('Dùng Router options (Settings) để tạo prompt', 'ai-copilot-content-generator'),
                'default' => 1,
                'options' => array(
                    1 => __('Có', 'ai-copilot-content-generator'),
                    0 => __('Không (dùng hard prompt)', 'ai-copilot-content-generator'),
                ),
            ),

            // BizCity: show supported types as a separate field (always visible)
            'supported_types' => array(
                'type' => 'textarea',
                'label' => __('Các type hỗ trợ (dùng cho Logic/Switch)', 'ai-copilot-content-generator'),
                'default' => $supportedTypesDefault,
                'rows' => 10,
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            // debug
            'json_raw' => __('JSON Raw (string)', 'ai-copilot-content-generator'),
            'json' => __('JSON (string)', 'ai-copilot-content-generator'),

            // schema fields
            'type' => __('type', 'ai-copilot-content-generator'),
            'confidence' => __('confidence (0..100)', 'ai-copilot-content-generator'),
            'reply' => __('reply', 'ai-copilot-content-generator'),

            'info_title' => __('info.title', 'ai-copilot-content-generator'),
            'info_content' => __('info.content', 'ai-copilot-content-generator'),
            'info_keywords' => __('info.keywords', 'ai-copilot-content-generator'),
            'info_category' => __('info.category', 'ai-copilot-content-generator'),
            'info_product_name' => __('info.product_name', 'ai-copilot-content-generator'),
            'info_price' => __('info.price', 'ai-copilot-content-generator'),
            'info_image_url' => __('info.image_url', 'ai-copilot-content-generator'),
            'info_audio_url' => __('info.audio_url', 'ai-copilot-content-generator'),

            // status
            'ok' => __('OK (1/0)', 'ai-copilot-content-generator'),
        );

        // Add dynamic variables from Router options mapping
        $map = $this->bizcityParseOutputMap($this->bizcityGetRouterOption('output_map'));
        foreach ($map as $var => $d) {
            if (!isset($this->_variables[$var])) {
                $this->_variables[$var] = $var;
            }
        }

        return $this->_variables;
    }

    private function bizcityGetRouterOption($key, $default = '') {
        $m = WaicFrame::_()->getModule('options')->getModel();
        $val = $m->get('router', $key);
        if (false === $val || $val === '' || $val === null) {
            return $m->getDefaults('router', $key, $default);
        }
        return $val;
    }

    private function bizcityParseOutputMap($text) {
        $text = (string) $text;
        $lines = preg_split('/\r\n|\r|\n/', trim($text));
        $out = array();

        foreach ((array)$lines as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (strpos($line, '#') === 0 || strpos($line, '//') === 0 || strpos($line, ';') === 0) continue;

            $parts = explode('=', $line, 2);
            if (count($parts) < 2) continue;

            $var = trim((string)$parts[0]);
            $rhs = trim((string)$parts[1]);
            if ($var === '' || $rhs === '') continue;

            $path = $rhs;
            $type = 'text';
            if (preg_match('/^(.*?)(?::(text|number|int|float|bool|json))$/i', $rhs, $m)) {
                $path = trim((string)$m[1]);
                $type = strtolower((string)$m[2]);
            }

            if ($path === '') continue;
            $out[$var] = array('path' => $path, 'type' => $type);
        }

        return $out;
    }

    private function bizcitySchemaFromMap($map) {
        $schema = array(
            'type' => '',
            'confidence' => 0,
            'reply' => '',
        );

        foreach ((array)$map as $d) {
            $path = $d['path'];
            $type = $d['type'];

            $exampleVal = '';
            if ($type === 'int' || $type === 'number') $exampleVal = 0;
            if ($type === 'float') $exampleVal = 0;
            if ($type === 'bool') $exampleVal = false;
            if ($type === 'json') $exampleVal = array();

            $parts = explode('.', $path);
            $cur =& $schema;
            foreach ($parts as $i => $p) {
                $p = trim((string)$p);
                if ($p === '') continue 2;

                $isLast = ($i === count($parts) - 1);
                if ($isLast) {
                    $cur[$p] = $exampleVal;
                } else {
                    if (!isset($cur[$p]) || !is_array($cur[$p])) {
                        $cur[$p] = array();
                    }
                    $cur =& $cur[$p];
                }
            }
        }

        return $schema;
    }

    private function bizcityFormatTypesForPrompt() {
        $raw = (string) $this->bizcityGetRouterOption('types_json');
        $val = json_decode($raw, true);
        if (!is_array($val) || empty($val)) {
            $fallback = (string) $this->bizcityGetRouterOption('types_list');
			if (trim($fallback) === '') {
				$fallback = (string) $this->getParam('supported_types');
			}
            $lines = preg_split('/\r\n|\r|\n/', trim($fallback));
            $lines = array_values(array_filter(array_map('trim', (array)$lines)));
            return empty($lines) ? '' : ('- ' . implode("\n- ", $lines));
        }

        $out = array();
        foreach ($val as $row) {
            if (!is_array($row)) continue;
            $type = empty($row['type']) ? '' : trim((string)$row['type']);
            if ($type === '') continue;
            $desc = empty($row['desc']) ? '' : trim((string)$row['desc']);
            $instruction = empty($row['instruction']) ? '' : trim((string)$row['instruction']);

            $line = $type;
            if ($desc !== '') $line .= ' — ' . $desc;
            if ($instruction !== '') $line .= ' | ' . $instruction;
            $out[] = $line;
        }
        return empty($out) ? '' : ('- ' . implode("\n- ", $out));
    }

    private function bizcityBuildPrompt($variables, $message) {
        $useOptions = (int) $this->getParam('use_router_options') === 1;
        if (!$useOptions) {
            return $this->replaceVariables($this->bizcityHardPrompt(), $variables);
        }

        $template = (string) $this->bizcityGetRouterOption('prompt_template');
        if (trim($template) === '') {
            return $this->replaceVariables($this->bizcityHardPrompt(), $variables);
        }

        $map = $this->bizcityParseOutputMap($this->bizcityGetRouterOption('output_map'));
        $schema = $this->bizcitySchemaFromMap($map);
        $schemaJson = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $typesText = $this->bizcityFormatTypesForPrompt();

        $prompt = str_replace(
            array('{message}', '{types}', '{schema}'),
            array((string)$message, (string)$typesText, (string)$schemaJson),
            $template
        );

        return $this->replaceVariables($prompt, $variables);
    }

    private function bizcityHardPrompt() {
        return
"Bạn là bộ phân loại yêu cầu và trích xuất dữ liệu cho hệ thống Workflow.

INPUT (tin nhắn người dùng):
{{node#1.twf_text}}

YÊU CẦU BẮT BUỘC:
- Chỉ trả về DUY NHẤT 1 JSON hợp lệ. Không thêm chữ, không markdown.
- Không được bọc trong ```json.
- Nếu thiếu dữ liệu: dùng \"\" hoặc 0 hoặc null.

OUTPUT JSON SCHEMA:
{
  \"type\": \"huong_dan|viet_bai|tao_video|tao_san_pham|tra_loi_khach|khac\",
  \"confidence\": 0,
  \"reply\": \"\",
  \"info\": {
    \"title\": \"\",
    \"content\": \"\",
    \"keywords\": \"\",
    \"category\": \"\",
    \"product_name\": \"\",
    \"price\": 0,
    \"image_url\": \"\",
    \"audio_url\": \"\"
  }
}";
    }

    private function bizcityNormalizeJsonRaw($text) {
        $text = trim((string)$text);

        // remove ```json ... ``` fences if any
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
        }

        // try to cut noise before first { or [
        $posObj = strpos($text, '{');
        $posArr = strpos($text, '[');
        if ($posObj === false && $posArr === false) return $text;

        $start = $posObj;
        if ($start === false || ($posArr !== false && $posArr < $posObj)) {
            $start = $posArr;
        }

        return trim(substr($text, $start));
    }

    private function bizcityGet($arr, $path, $default = '') {
        if (!is_array($arr)) return $default;
        $parts = explode('.', $path);
        $cur = $arr;
        foreach ($parts as $p) {
            if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
            $cur = $cur[$p];
        }
        return $cur;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        $apiOptions = array(
            'engine' => 'bizcity', // CHANGED
            'model' => $this->getParam('model'),
            'tokens' => $this->getParam('tokens'),
            'temperature' => $this->getParam('temperature'),
        );

        $aiProvider = WaicFrame::_()->getModule('workspace')->getModel('aiprovider')->getInstance($apiOptions);
        if (!$aiProvider) {
            return false;
        }

        $aiProvider->init($taskId);
        if (!$aiProvider->setApiOptions($apiOptions)) {
            return false;
        }

        $message = (string) $this->replaceVariables($this->getParam('message'), $variables);
        $prompt = $this->bizcityBuildPrompt($variables, $message);

        $result = $aiProvider->getText(array('prompt' => $prompt));
        $error = $result['error'];

        $data = $error ? '' : $this->controlText($result['data']);
        $jsonRaw = $error ? '' : $this->bizcityNormalizeJsonRaw($data);

        $decoded = array();
        $parseError = '';

        if (!$error) {
            $val = json_decode($jsonRaw, true);
            if (!is_array($val)) {
                $parseError = 'Invalid JSON';
            } else {
                $decoded = $val;
            }
        }

        $ok = (!$error && $parseError === '') ? 1 : 0;

        $out = array(
            'ok' => $ok,
            'json_raw' => $jsonRaw,
			'json' => $ok ? json_encode($decoded, JSON_UNESCAPED_UNICODE) : '',

            'type' => (string) $this->bizcityGet($decoded, 'type', ''),
            'confidence' => (int) $this->bizcityGet($decoded, 'confidence', 0),
            'reply' => (string) $this->bizcityGet($decoded, 'reply', ''),

            'info_title' => (string) $this->bizcityGet($decoded, 'info.title', ''),
            'info_content' => (string) $this->bizcityGet($decoded, 'info.content', ''),
            'info_keywords' => (string) $this->bizcityGet($decoded, 'info.keywords', ''),
            'info_category' => (string) $this->bizcityGet($decoded, 'info.category', ''),
            'info_product_name' => (string) $this->bizcityGet($decoded, 'info.product_name', ''),
            'info_price' => (float) $this->bizcityGet($decoded, 'info.price', 0),
            'info_image_url' => (string) $this->bizcityGet($decoded, 'info.image_url', ''),
            'info_audio_url' => (string) $this->bizcityGet($decoded, 'info.audio_url', ''),
        );

        // Apply dynamic mapping (can add/override variables)
        $map = $this->bizcityParseOutputMap($this->bizcityGetRouterOption('output_map'));
        foreach ($map as $var => $d) {
            $path = $d['path'];
            $type = $d['type'];
            $val = $this->bizcityGet($decoded, $path, '');

            if ($type === 'int' || $type === 'number') {
                $val = (int) $val;
            } else if ($type === 'float') {
                $val = (float) $val;
            } else if ($type === 'bool') {
                $val = (int) $val ? 1 : 0;
            } else if ($type === 'json') {
                if (is_string($val)) {
                    $tmp = json_decode($val, true);
                    $val = is_array($tmp) ? $tmp : $val;
                }
                if (is_array($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                }
            } else {
                $val = is_scalar($val) || is_null($val) ? (string) $val : json_encode($val, JSON_UNESCAPED_UNICODE);
            }

            $out[$var] = $val;
        }

        $this->_results = array(
            'result' => $ok ? $out : array('ok' => 0, 'json_raw' => $jsonRaw),
            'error' => $error ? $this->controlText($result['msg']) : $parseError,
            'tokens' => empty($result['tokens']) ? array() : ((int)$result['tokens']),
            'status' => $ok ? 3 : 7,
        );

        return $this->_results;
    }
}