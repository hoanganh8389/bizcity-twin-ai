<?php
if (!defined('ABSPATH')) exit;

/**
 * AI Router with Human-in-Loop (HIL)
 * 
 * Phân tích intent từ user message và hỏi thêm thông tin nếu thiếu data.
 * Loop cho đến khi đủ dữ liệu đầu vào rồi mới chuyển sang node tiếp theo.
 * 
 * Use case:
 * - User: "Đăng bài"
 * - AI: "Bạn muốn đăng bài về chủ đề gì?"
 * - User: "Về AI"
 * - AI: "Bạn có ảnh minh họa không?"
 * - User: gửi ảnh
 * - AI: OK đủ data → task_fill = 1 → chuyển sang node tạo bài viết
 * 
 * @since 1.0.0
 */
class WaicAction_ai_router_hil extends WaicAction {
    protected $_code = 'ai_router_hil';
    protected $_order = 99;

    public function __construct($block = null) {
        $this->_name = __('AI Router + HIL', 'ai-copilot-content-generator');
        $this->_desc = __('Phân tích intent và hỏi thêm thông tin cho đến khi đủ dữ liệu', 'ai-copilot-content-generator');
        $this->_sublabel = array('name');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) $this->setSettings();
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = array(
            'name' => array(
                'type' => 'input',
                'label' => __('Tên node', 'ai-copilot-content-generator'),
                'default' => 'AI Router HIL',
            ),
            
            // Input
            'chat_id' => array(
                'type' => 'input',
                'label' => __('Chat ID', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_chat_id}}',
                'variables' => true,
            ),
            'user_text' => array(
                'type' => 'textarea',
                'label' => __('User message', 'ai-copilot-content-generator'),
                'default' => '{{node#1.twf_text}}',
                'rows' => 3,
                'variables' => true,
            ),
            
            // Intent & Required fields
            'intent_definitions' => array(
                'type' => 'textarea',
                'label' => __('Intent definitions (JSON)', 'ai-copilot-content-generator'),
                'default' => 
"{
  \"dang_bai\": {
    \"keywords\": [\"đăng bài\", \"viết bài\", \"tạo bài\", \"post bài\"],
    \"required_fields\": [\"title\", \"image\"],
    \"field_labels\": {
      \"title\": \"tên bài viết\",
      \"image\": \"hình ảnh minh họa\"
    }
  },
  \"tao_san_pham\": {
    \"keywords\": [\"tạo sản phẩm\", \"thêm sản phẩm\", \"đăng sản phẩm\"],
    \"required_fields\": [\"product_name\", \"price\", \"image\"],
    \"field_labels\": {
      \"product_name\": \"tên sản phẩm\",
      \"price\": \"giá bán\",
      \"image\": \"ảnh sản phẩm\"
    }
  }
}",
                'rows' => 12,
                'variables' => true,
                'desc' => __('Định nghĩa các intent và field bắt buộc', 'ai-copilot-content-generator'),
            ),
            
            // Context (optional)
            'context_json' => array(
                'type' => 'textarea',
                'label' => __('Context (JSON)', 'ai-copilot-content-generator'),
                'default' => '',
                'rows' => 4,
                'variables' => true,
                'desc' => __('Thông tin ngữ cảnh bổ sung (tùy chọn)', 'ai-copilot-content-generator'),
            ),
            
            // HIL Settings
            'hil_intro' => array(
                'type' => 'textarea',
                'label' => __('Lời chào đầu (HIL intro)', 'ai-copilot-content-generator'),
                'default' => 'Chào bạn! Để tôi hỗ trợ bạn tốt hơn, vui lòng cho tôi biết thêm một số thông tin.',
                'rows' => 2,
                'variables' => true,
            ),
            'hil_ttl' => array(
                'type' => 'number',
                'label' => __('HIL session timeout (phút)', 'ai-copilot-content-generator'),
                'default' => 30,
                'min' => 1,
                'max' => 1440,
            ),
            
            // AI Model
            'model' => array(
                'type' => 'input',
                'label' => __('AI Model', 'ai-copilot-content-generator'),
                'default' => 'gpt-4.1-mini',
                'variables' => true,
            ),
            'temperature' => array(
                'type' => 'number',
                'label' => __('Temperature', 'ai-copilot-content-generator'),
                'default' => 0.2,
                'step' => 0.01,
                'min' => 0,
                'max' => 2,
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            // Intent analysis
            'intent' => __('Intent detected', 'ai-copilot-content-generator'),
            'intent_confidence' => __('Intent confidence (0-100)', 'ai-copilot-content-generator'),
            
            // HIL state
            'hil_active' => __('HIL active (1/0)', 'ai-copilot-content-generator'),
            'hil_missing_count' => __('Number of missing fields', 'ai-copilot-content-generator'),
            'hil_missing_csv' => __('Missing fields CSV', 'ai-copilot-content-generator'),
            'hil_next_question' => __('Next question to ask', 'ai-copilot-content-generator'),
            
            // Completion
            'task_fill' => __('Fill status (1=complete, 0=need more)', 'ai-copilot-content-generator'),
            'task_data_json' => __('Collected data JSON', 'ai-copilot-content-generator'),
            
            // Raw output
            'raw_response' => __('Raw AI response', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    /**
     * Generate HIL state key
     */
    private function hilKey($blog_id, $chat_id) {
        return 'waic_hil_router_' . $blog_id . '_' . sanitize_key($chat_id);
    }

    /**
     * Match intent from user text
     */
    private function matchIntent($userText, $intentDefs) {
        $userTextLower = mb_strtolower($userText, 'UTF-8');
        
        foreach ($intentDefs as $intent => $config) {
            if (empty($config['keywords'])) continue;
            
            foreach ($config['keywords'] as $keyword) {
                if (mb_strpos($userTextLower, mb_strtolower($keyword, 'UTF-8')) !== false) {
                    return array(
                        'intent' => $intent,
                        'config' => $config,
                        'confidence' => 90 // High confidence for keyword match
                    );
                }
            }
        }
        
        return array(
            'intent' => '',
            'config' => array(),
            'confidence' => 0
        );
    }

    /**
     * Check which fields are missing
     */
    private function getMissingFields($requiredFields, $collectedData, $fieldLabels) {
        $missing = array();
        
        foreach ($requiredFields as $field) {
            if (empty($collectedData[$field])) {
                $label = isset($fieldLabels[$field]) ? $fieldLabels[$field] : $field;
                $missing[$field] = $label;
            }
        }
        
        return $missing;
    }

    /**
     * Generate next question
     */
    private function generateNextQuestion($missingFields) {
        if (empty($missingFields)) return '';
        
        $firstField = array_values($missingFields)[0];
        
        $questions = array(
            "Bạn có thể cho tôi biết {field} không?",
            "Để tiếp tục, tôi cần biết {field}.",
            "Vui lòng cho tôi biết {field}.",
        );
        
        $template = $questions[array_rand($questions)];
        return str_replace('{field}', $firstField, $template);
    }

    public function getResults($taskId, $variables, $step = 0) {
        $blog_id = get_current_blog_id();
        $chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
        $user_text = $this->replaceVariables($this->getParam('user_text'), $variables);
        
        // Parse intent definitions
        $intentDefsRaw = $this->replaceVariables($this->getParam('intent_definitions'), $variables);
        $intentDefs = json_decode($intentDefsRaw, true);
        if (!is_array($intentDefs)) {
            $intentDefs = array();
        }
        
        // Get or create HIL state
        $hilKey = $this->hilKey($blog_id, $chat_id);
        $state = get_transient($hilKey);
        
        if (!is_array($state)) {
            // First time - analyze intent
            $match = $this->matchIntent($user_text, $intentDefs);
            
            if (empty($match['intent'])) {
                // No intent matched
                $this->_results = array(
                    'result' => array(
                        'intent' => '',
                        'intent_confidence' => 0,
                        'hil_active' => 0,
                        'task_fill' => 0,
                        'hil_next_question' => 'Xin lỗi, tôi không hiểu yêu cầu của bạn.',
                    ),
                    'status' => 7, // Error
                    'error' => 'No intent matched',
                );
                return $this->_results;
            }
            
            // Initialize state
            $state = array(
                'intent' => $match['intent'],
                'confidence' => $match['confidence'],
                'required_fields' => $match['config']['required_fields'],
                'field_labels' => $match['config']['field_labels'],
                'collected_data' => array(),
                'created_at' => time(),
            );
            
            $ttl = (int) $this->getParam('hil_ttl') * 60; // Convert minutes to seconds
            set_transient($hilKey, $state, $ttl);
            
            // Send intro message
            $intro = $this->replaceVariables($this->getParam('hil_intro'), $variables);
            if ($intro && function_exists('waic_send_zalo_message')) {
                waic_send_zalo_message($chat_id, $intro);
            }
        }
        
        // TODO: Analyze current user_text to extract data (use AI or regex)
        // For now, mock: assume each response fills one field
        // Real implementation would call AI to extract structured data
        
        // Check missing fields
        $missing = $this->getMissingFields(
            $state['required_fields'],
            $state['collected_data'],
            $state['field_labels']
        );
        
        if (empty($missing)) {
            // All fields collected - complete!
            delete_transient($hilKey);
            
            $this->_results = array(
                'result' => array(
                    'intent' => $state['intent'],
                    'intent_confidence' => $state['confidence'],
                    'hil_active' => 0,
                    'hil_missing_count' => 0,
                    'hil_missing_csv' => '',
                    'hil_next_question' => '',
                    'task_fill' => 1, // COMPLETE
                    'task_data_json' => wp_json_encode($state['collected_data'], JSON_UNESCAPED_UNICODE),
                ),
                'status' => 3, // Success
                'error' => '',
            );
        } else {
            // Still missing fields - ask next question
            $nextQuestion = $this->generateNextQuestion($missing);
            
            // Send question to user
            if ($nextQuestion && function_exists('waic_send_zalo_message')) {
                waic_send_zalo_message($chat_id, $nextQuestion);
            }
            
            $this->_results = array(
                'result' => array(
                    'intent' => $state['intent'],
                    'intent_confidence' => $state['confidence'],
                    'hil_active' => 1,
                    'hil_missing_count' => count($missing),
                    'hil_missing_csv' => implode(', ', array_keys($missing)),
                    'hil_next_question' => $nextQuestion,
                    'task_fill' => 0, // NOT COMPLETE
                    'task_data_json' => wp_json_encode($state['collected_data'], JSON_UNESCAPED_UNICODE),
                ),
                'status' => 2, // Pending (waiting for user input)
                'error' => '',
            );
            
            // Update state with new question
            $state['last_question'] = $nextQuestion;
            $state['last_question_time'] = time();
            $ttl = (int) $this->getParam('hil_ttl') * 60;
            set_transient($hilKey, $state, $ttl);
        }
        
        return $this->_results;
    }
}
