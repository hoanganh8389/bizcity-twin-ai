<?php
if (!defined('ABSPATH')) exit;

/**
 * HIL Confirmation for Checklist Validation
 * Xác nhận và bổ sung thông tin thiếu trong checklist
 */
class WaicLogic_un_confirm_checklist extends WaicLogic {
    protected $_code = 'un_confirm_checklist';
    protected $_order = 0;

    public function __construct($block = null) {
        $this->_name = __('Chờ xác nhận checklist (HIL)', 'ai-copilot-content-generator');
        $this->_desc = __('Validate checklist và yêu cầu bổ sung thông tin thiếu', 'ai-copilot-content-generator');
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
            'checklist_json' => array(
                'type' => 'textarea',
                'label' => __('Checklist JSON *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 6,
                'desc' => __('Checklist từ AI analyze ({{node#X.checklist_json}})', 'ai-copilot-content-generator'),
            ),
            
            'user_message' => array(
                'type' => 'textarea',
                'label' => __('Yêu cầu gốc của user', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 3,
                'desc' => __('Tin nhắn gốc để validate context', 'ai-copilot-content-generator'),
            ),

            'timeout' => array(
                'type' => 'input',
                'label' => __('Timeout (phút)', 'ai-copilot-content-generator'),
                'default' => '10',
                'desc' => __('Thời gian chờ user trả lời', 'ai-copilot-content-generator'),
            ),
        );
    }

    public function getOutputs() {
        return array(
            'output-confirmed' => array(
                'label' => __('Đã xác nhận', 'ai-copilot-content-generator'),
                'color' => '#10b981',
            ),
            'output-rejected' => array(
                'label' => __('Từ chối/Timeout', 'ai-copilot-content-generator'),
                'color' => '#ef4444',
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) {
            $this->setVariables();
        }
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'status' => __('confirmed/rejected/timeout', 'ai-copilot-content-generator'),
            'checklist_json' => __('Checklist JSON đã validate/bổ sung', 'ai-copilot-content-generator'),
            'checklist' => __('Checklist array (PHP)', 'ai-copilot-content-generator'),
            'user_response' => __('Câu trả lời của user', 'ai-copilot-content-generator'),
            'missing_info' => __('Thông tin còn thiếu (nếu có)', 'ai-copilot-content-generator'),
        );
    }

    public function getResults($taskId, $variables, $step = 0) {
        // Get params
        $checklist_json = $this->replaceVariables($this->getParam('checklist_json'), $variables);
        $user_message = $this->replaceVariables($this->getParam('user_message'), $variables);
        $timeout = (int) $this->getParam('timeout') ?: 10;

        // Get chat_id and run_id
        $chat_id = $this->replaceVariables('{{node#chat_id}}', $variables) ?: '0';
        $run_id = $this->runId ?: 0;

        error_log("[un_confirm_checklist] Chat ID: {$chat_id}, Checklist length: " . strlen($checklist_json) . ", Current run_id: {$run_id}");

        // Decode checklist
        $checklist = json_decode(html_entity_decode($checklist_json), true);
        if (!is_array($checklist)) {
            return array(
                'result' => array(),
                'error' => __('Invalid checklist JSON', 'ai-copilot-content-generator'),
                'status' => 7,
            );
        }

        // Check HIL state
        $hil_state = false;
        if (function_exists('waic_hil_get_state')) {
            $hil_state = waic_hil_get_state($chat_id);
            error_log("[un_confirm_checklist] HIL state: " . json_encode($hil_state));

            // Validate run_id
            if ($hil_state !== false && isset($hil_state['run_id'])) {
                if ($hil_state['run_id'] != $run_id) {
                    error_log("[un_confirm_checklist] Run ID mismatch! HIL: {$hil_state['run_id']}, Current: {$run_id}. Clearing old state.");
                    waic_hil_clear_state($chat_id);
                    $hil_state = false;
                }
            }
        }

        // Validate checklist và tìm missing info
        $validation = $this->validateChecklist($checklist, $user_message);
        
        if ($hil_state === false || !isset($hil_state['status'])) {
            // Chưa có state → Tạo mới và gửi notification
            
            if (empty($validation['missing_info'])) {
                // Checklist đã đủ thông tin → Auto confirm
                error_log("[un_confirm_checklist] Checklist complete, auto-confirming");
                
                return array(
                    'result' => array(
                        'status' => 'confirmed',
                        'checklist_json' => json_encode($checklist, JSON_UNESCAPED_UNICODE),
                        'checklist' => $checklist,
                        'user_response' => 'auto_confirmed',
                        'missing_info' => '',
                    ),
                    'sourceHandle' => 'output-confirmed',
                    'error' => '',
                    'status' => 3,
                );
            }

            // Có thông tin thiếu → Tạo HIL state
            $timeout_at = time() + ($timeout * 60);
            
            if (function_exists('waic_hil_get_state')) {
                $hil_state_key = 'waic_hil_checklist_' . $chat_id;
                set_transient($hil_state_key, array(
                    'status' => 'waiting',
                    'started_at' => time(),
                    'timeout_at' => $timeout_at,
                    'chat_id' => $chat_id,
                    'run_id' => $run_id,
                    'node_id' => $this->nodeId,
                    'checklist' => $checklist,
                    'user_message' => $user_message,
                    'missing_info' => $validation['missing_info'],
                    'questions' => $validation['questions'],
                ), $timeout * 60);

                error_log("[un_confirm_checklist] Created new HIL state, waiting until: " . date('Y-m-d H:i:s', $timeout_at));
            }

            // Gửi notification
            $this->sendValidationRequest($chat_id, $checklist, $validation);

            return array(
                'result' => array(
                    'status' => 'waiting',
                    'missing_info' => implode(', ', $validation['missing_info']),
                ),
                'sourceHandle' => 'output-rejected',
                'error' => '',
                'status' => 0, // Waiting
            );
        }

        // Có HIL state → Check status
        if ($hil_state['status'] === 'confirmed') {
            error_log("[un_confirm_checklist] User confirmed with additional info");
            
            // Merge user response vào checklist
            $updated_checklist = $this->mergeUserResponse(
                $hil_state['checklist'],
                $hil_state['user_response'],
                $hil_state['missing_info']
            );

            // Clear state
            if (function_exists('waic_hil_clear_state')) {
                waic_hil_clear_state($chat_id);
            }

            return array(
                'result' => array(
                    'status' => 'confirmed',
                    'checklist_json' => json_encode($updated_checklist, JSON_UNESCAPED_UNICODE),
                    'checklist' => $updated_checklist,
                    'user_response' => $hil_state['user_response'],
                    'missing_info' => '',
                ),
                'sourceHandle' => 'output-confirmed',
                'error' => '',
                'status' => 3,
            );
        }

        if ($hil_state['status'] === 'rejected') {
            error_log("[un_confirm_checklist] User rejected");
            
            if (function_exists('waic_hil_clear_state')) {
                waic_hil_clear_state($chat_id);
            }

            return array(
                'result' => array(
                    'status' => 'rejected',
                    'user_response' => $hil_state['response'] ?? '',
                ),
                'sourceHandle' => 'output-rejected',
                'error' => '',
                'status' => 3,
            );
        }

        // Still waiting hoặc timeout
        if (time() > $hil_state['timeout_at']) {
            error_log("[un_confirm_checklist] Timeout reached");
            
            if (function_exists('waic_hil_clear_state')) {
                waic_hil_clear_state($chat_id);
            }

            return array(
                'result' => array(
                    'status' => 'timeout',
                ),
                'sourceHandle' => 'output-rejected',
                'error' => '',
                'status' => 3,
            );
        }

        return array(
            'result' => array(
                'status' => 'waiting',
            ),
            'sourceHandle' => 'output-rejected',
            'error' => '',
            'status' => 0,
        );
    }

    /**
     * Validate checklist và tìm thông tin thiếu
     */
    private function validateChecklist($checklist, $user_message) {
        $missing_info = array();
        $questions = array();

        foreach ($checklist as $step) {
            $tool = $step['tool'] ?? '';
            $params = $step['params'] ?? array();

            // Check từng tool cụ thể
            switch ($tool) {
                case 'wp_create_post':
                    if (empty($params['post_title'])) {
                        $missing_info[] = 'post_title';
                        $questions[] = "📝 Tiêu đề bài viết/sản phẩm?";
                    }
                    if (empty($params['post_type'])) {
                        $missing_info[] = 'post_type';
                        $questions[] = "📂 Loại nội dung (post/product/page)?";
                    }
                    break;

                case 'ai_generate_text':
                    if (empty($params['prompt']) && empty($params['topic'])) {
                        $missing_info[] = 'content_topic';
                        $questions[] = "✍️ Chủ đề nội dung cần tạo?";
                    }
                    break;

                case 'wp_update_post':
                    // ID sẽ được fill từ step trước, không cần check
                    break;
            }
        }

        return array(
            'missing_info' => $missing_info,
            'questions' => $questions,
        );
    }

    /**
     * Gửi notification yêu cầu bổ sung thông tin
     */
    private function sendValidationRequest($chat_id, $checklist, $validation) {
        if (!function_exists('biz_send_message')) {
            return;
        }

        $message = "📋 *Xác nhận kế hoạch thực hiện*\n\n";
        
        // List các bước
        $message .= "🔹 *Các bước thực hiện:*\n";
        foreach ($checklist as $i => $step) {
            $step_num = $i + 1;
            $desc = $step['description'] ?? $step['tool'];
            $message .= "{$step_num}. {$desc}\n";
        }

        // Missing info nếu có
        if (!empty($validation['questions'])) {
            $message .= "\n⚠️ *Cần bổ sung thông tin:*\n";
            foreach ($validation['questions'] as $q) {
                $message .= "• {$q}\n";
            }
            $message .= "\n💬 *Vui lòng cung cấp thông tin còn thiếu để tiếp tục.*";
        } else {
            $message .= "\n✅ Trả lời *'Có'* để xác nhận thực hiện\n";
            $message .= "❌ Trả lời *'Không'* để huỷ";
        }

        biz_send_message($chat_id, $message);
    }

    /**
     * Merge user response vào checklist
     */
    private function mergeUserResponse($checklist, $user_response, $missing_info) {
        // Call AI để parse user response và update checklist
        $api_key = get_option('twf_openai_api_key');
        if (empty($api_key)) {
            return $checklist; // Fallback: return original
        }

        $prompt = "Original checklist:\n" . json_encode($checklist, JSON_PRETTY_PRINT) . "\n\n";
        $prompt .= "Missing info: " . implode(', ', $missing_info) . "\n\n";
        $prompt .= "User response: {$user_response}\n\n";
        $prompt .= "Please update the checklist params with info from user response. Return ONLY the updated JSON checklist.";

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'model' => 'gpt-4o-mini',
                'messages' => array(
                    array('role' => 'system', 'content' => 'You are a helpful assistant that updates checklist JSON based on user input.'),
                    array('role' => 'user', 'content' => $prompt),
                ),
                'temperature' => 0.3,
            )),
        ));

        if (!is_wp_error($response)) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($body['choices'][0]['message']['content'])) {
                $ai_text = $body['choices'][0]['message']['content'];
                
                // Parse JSON
                if (preg_match('/\[.*\]/s', $ai_text, $matches)) {
                    $updated = json_decode($matches[0], true);
                    if (is_array($updated)) {
                        return $updated;
                    }
                }
            }
        }

        return $checklist; // Fallback
    }
}