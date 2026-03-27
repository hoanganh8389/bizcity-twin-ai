<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WaicAction_ai_generate_json extends WaicAction {
    protected $_code = 'ai_generate_json';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('BizCity - phân tách JSON (JSON Raw)', 'ai-copilot-content-generator');
        $this->_desc = __('GPT tạo JSON (đầu ra dạng chuỗi JSON để parse ở node sau)', 'ai-copilot-content-generator');
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
        $modelOptions = WaicFrame::_()->getModule('options')->getModel();
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
                'default' => 2048,
            ),
            'temperature' => array(
                'type' => 'number',
                'label' => __('Temperature', 'ai-copilot-content-generator'),
                'default' => 0.2,
                'step' => '0.01',
                'min' => 0,
                'max' => 2,
            ),
            'enforce_json' => array(
                'type' => 'checkbox',
                'label' => __('Ép AI chỉ trả JSON hợp lệ', 'ai-copilot-content-generator'),
                'default' => 1,
            ),
            'prompt' => array (
                'type' => 'textarea',
                'label' => __('Prompt *', 'ai-copilot-content-generator'),
                // BizCity: default prompt template for routing/intents
                'default' =>
"Bạn là bộ phân loại yêu cầu và trích xuất dữ liệu cho hệ thống Workflow.

INPUT (tin nhắn người dùng):
{{node#1.twf_text}}

YÊU CẦU BẮT BUỘC:
- Chỉ trả về DUY NHẤT 1 JSON hợp lệ. Không thêm chữ, không markdown.
- Không được bọc trong ```json.
- Nếu thiếu dữ liệu: dùng \"\" hoặc 0 hoặc null.

OUTPUT JSON SCHEMA:
{
  \"type\": \"huong_dan|viet_bai|tao_bai|tao_san_pham|tra_loi_khach|khac\",
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
}

QUY TẮC:
- type: chọn 1 giá trị phù hợp nhất với yêu cầu.
- confidence: số 0..100.
- reply: câu trả lời ngắn gọn để gửi lại cho khách (nếu cần).
- info: chỉ điền các field liên quan, field không liên quan để rỗng.",
                'rows' => 12,
                'variables' => true,
                'desc' => __('Mẫu prompt mặc định để AI trả JSON theo schema. Anh có thể chỉnh type/schema theo nhu cầu.', 'ai-copilot-content-generator'),
            ),
        );
    }


    public function getVariables() {
        $this->_variables = array(
            'json_raw' => __('JSON Raw (string)', 'ai-copilot-content-generator'),
            'content'  => __('Generated Text (same as json_raw)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    private function bizcityNormalizeJsonRaw($text) {
        $text = trim((string)$text);

        // remove ```json ... ``` fences if any
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/is', $text, $m)) {
            $text = trim($m[1]);
        }

        return $text;
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

        $prompt = $this->replaceVariables($this->getParam('prompt'), $variables);

        if ((int)$this->getParam('enforce_json') === 1) {
            $prompt .= "\n\n";
            $prompt .= "YÊU CẦU BẮT BUỘC:\n";
            $prompt .= "- Chỉ trả về DUY NHẤT JSON hợp lệ (không markdown, không giải thích).\n";
            $prompt .= "- Nếu thiếu dữ liệu: dùng \"\" hoặc 0 hoặc null.\n";
        }

        $result = $aiProvider->getText(array('prompt' => $prompt));
        $error = $result['error'];

        $data = $error ? '' : $this->controlText($result['data']);
        $jsonRaw = $error ? '' : $this->bizcityNormalizeJsonRaw($data);

        $this->_results = array(
            'result' => $error ? array() : array(
                'json_raw' => $jsonRaw,
                'content' => $jsonRaw, // compatibility
            ),
            'error' => $error ? $this->controlText($result['msg']) : '',
            'tokens' => empty($result['tokens']) ? array() : ((int)$result['tokens']),
            'status' => $error ? 7 : 3,
        );

        return $this->_results;
    }
}