<?php
if (!defined('ABSPATH')) exit;

/**
 * Logic: Chờ xác nhận từ User (Human-in-the-Loop)
 * Duplicate từ un_delay nhưng chờ HIL complete thay vì timer
 */
class WaicLogic_un_confirm extends WaicLogic {
    protected $_code = 'un_confirm';
    protected $_subtype = 2; // Changed to 2 (branch type) like un_branch
    protected $_order = 0;
    
    public function __construct($block = null) {
        $this->_name = __('Chờ xác nhận (HIL)', 'ai-copilot-content-generator');
        $this->_desc = __('Chờ user xác nhận qua Human-in-the-Loop. Đi nhánh ĐÚNG nếu hoàn tất, SAI nếu timeout.', 'ai-copilot-content-generator');
        $this->_sublabel = array('timeout_minutes', 'chat_id');
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
                'default' => 'Chờ xác nhận',
                'desc' => __('Đặt tên để dễ nhìn trên sơ đồ', 'ai-copilot-content-generator'),
            ),

            'chat_id' => array(
                'type' => 'text',
                'label' => __('Chat ID *', 'ai-copilot-content-generator'),
                'default' => '{{chat_id}}',
                'variables' => true,
                'desc' => __('ID chat để gửi yêu cầu xác nhận và nhận phản hồi từ user', 'ai-copilot-content-generator'),
            ),

            'timeout_minutes' => array(
                'type' => 'number',
                'label' => __('Timeout (phút)', 'ai-copilot-content-generator'),
                'default' => 1,
                'min' => 1,
                'max' => 1440,
                'desc' => __('Thời gian chờ tối đa. Nếu quá thời gian → đi nhánh SAI (timeout)', 'ai-copilot-content-generator'),
            ),

            '__guide' => array(
                'type' => 'textarea',
                'label' => __('Hướng dẫn sử dụng', 'ai-copilot-content-generator'),
                'default' =>
"CÁCH HOẠT ĐỘNG:\n".
"1. Node này sẽ GỬI TIN NHẮN yêu cầu user xác nhận\n".
"2. DỪNG WORKFLOW và chờ user trả lời\n".
"3. Khi user trả lời:\n".
"   - 'Có', 'Đúng', 'Yes', 'OK' → Đi nhánh ĐÚNG (output-then)\n".
"   - 'Không', 'Thôi', 'No', 'Huỷ' → Đi nhánh SAI (output-else)\n".
"4. Nếu TIMEOUT (quá thời gian chờ) → Đi nhánh SAI\n\n".
"VÍ DỤ SỬ DỤNG:\n".
"Chat ID: {{chat_id}}\n".
"Timeout: 30 phút\n".
"→ ĐÚNG: Thực hiện checklist\n".
"→ SAI: Gửi thông báo lỗi\n\n".
"TÍCH HỢP:\n".
"- Zalo webhook tự động update HIL state\n".
"- Hoặc dùng REST API: POST /wp-json/waic/v1/hil/respond",
                'desc' => __('Field này chỉ để xem hướng dẫn, không ảnh hưởng logic.', 'ai-copilot-content-generator'),
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
            'hil_completed' => __('1 = HIL hoàn tất, 0 = đang chờ', 'ai-copilot-content-generator'),
            'hil_timeout' => __('1 = timeout, 0 = bình thường', 'ai-copilot-content-generator'),
            'hil_result_json' => __('Kết quả từ HIL (JSON)', 'ai-copilot-content-generator'),
            'waiting_seconds' => __('Tổng thời gian đã chờ (giây)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    public function getResults($taskId, $variables, $step = 0) {
        // Replace variables
        $chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
        $timeout_minutes = (int) $this->getParam('timeout_minutes', 30);
        
        $error = '';
        $waiting = 0;
        $branch_result = false; // Default: go to ELSE branch
        
        // Validation
        if (empty($chat_id)) {
            $error = __('Chat ID đang trống', 'ai-copilot-content-generator');
            $this->_results = array(
                'result' => array('result' => false),
                'error' => $error,
                'status' => 4, // Error status
                'sourceHandle' => 'output-else',
            );
            return $this->_results;
        }
        
        $now = WaicUtils::getTimestamp();
        $blog_id = get_current_blog_id();
        
        // ⭐ Check HIL state from database/transient
        $hil_state_key = 'waic_hil_' . $blog_id . '_' . $chat_id;
        $hil_state = get_transient($hil_state_key);
        
        $current_run_id = isset($this->_runId) ? $this->_runId : 0;
        
        error_log('[un_confirm] Chat ID: ' . $chat_id . ', HIL state: ' . json_encode($hil_state) . ', Current run_id: ' . $current_run_id);
        
        // Check if HIL state belongs to THIS workflow execution
        $is_valid_state = false;
        if ($hil_state !== false && isset($hil_state['run_id'])) {
            if ($hil_state['run_id'] === $current_run_id) {
                $is_valid_state = true;
                error_log('[un_confirm] Found valid HIL state for current execution');
            } else {
                error_log('[un_confirm] Found OLD HIL state (run_id mismatch), will create new one');
                // Clear old state
                delete_transient($hil_state_key);
                $hil_state = false;
            }
        }
        
        // If no HIL state exists, create one and start waiting
        if ($hil_state === false) {
            $timeout_timestamp = $now + ($timeout_minutes * 60);
            $hil_state = array(
                'status' => 'waiting',
                'started_at' => $now,
                'timeout_at' => $timeout_timestamp,
                'chat_id' => $chat_id,
                'run_id' => isset($this->_runId) ? $this->_runId : 0,
                'node_id' => isset($this->_block['id']) ? $this->_block['id'] : '', // Save node_id for status update
            );
            set_transient($hil_state_key, $hil_state, $timeout_minutes * 60 + 300); // +5 phút buffer
            
            error_log('[un_confirm] Created new HIL state, waiting until: ' . date('Y-m-d H:i:s', $timeout_timestamp));
            
            // Send notification to user asking for confirmation
            if (function_exists('biz_send_message')) {
                biz_send_message($chat_id, "🔔 Vui lòng xác nhận:\nTrả lời 'Có' hoặc 'Đúng' để tiếp tục\nTrả lời 'Không' hoặc 'Thôi' để huỷ");
            }
            
            // Return waiting status
            $waiting = $timeout_timestamp;
        } else {
            // HIL state exists, check status
            switch ($hil_state['status']) {
                case 'confirmed': // User said YES/CÓ/ĐÚNG
                    error_log('[un_confirm] User confirmed! Going to THEN branch');
                    $branch_result = true;
                    delete_transient($hil_state_key); // Clean up
                    break;
                    
                case 'rejected': // User said NO/KHÔNG/SAI
                    error_log('[un_confirm] User rejected! Going to ELSE branch');
                    $branch_result = false;
                    delete_transient($hil_state_key); // Clean up
                    break;
                    
                case 'waiting':
                    // Check if timeout
                    if ($now >= $hil_state['timeout_at']) {
                        error_log('[un_confirm] Timeout reached! Going to ELSE branch');
                        $error = sprintf(__('Timeout: Chờ quá %d phút không có phản hồi', 'ai-copilot-content-generator'), $timeout_minutes);
                        $branch_result = false;
                        delete_transient($hil_state_key); // Clean up
                        
                        // Send timeout notification
                        if (function_exists('biz_send_message') && function_exists('biz_get_zalo_admin_id')) {
                            $chat_ids = biz_get_zalo_admin_id($blog_id);
                            if (is_array($chat_ids)) {
                                foreach ($chat_ids as $admin_chat_id) {
                                    biz_send_message($admin_chat_id, "⏱️ Timeout: Workflow dừng do không nhận được xác nhận sau {$timeout_minutes} phút.");
                                }
                            }
                        }
                    } else {
                        // Still waiting
                        error_log('[un_confirm] Still waiting... Timeout at: ' . date('Y-m-d H:i:s', $hil_state['timeout_at']));
                        $waiting = $hil_state['timeout_at'];
                    }
                    break;
            }
        }
        
        // ⭐ Determine status:
        // - status = 0 (waiting) if still waiting
        // - status = 3 (completed) if confirmed/rejected/timeout
        $status = ($waiting > 0) ? 0 : 3;
        
        // ⭐ CRITICAL: Always return sourceHandle (even when waiting)
        // - When waiting: return temp value, HIL helper will update execution state later
        // - When completed: return final value based on user response
        $sourceHandle = $branch_result ? 'output-then' : 'output-else';
        
        $this->_results = array(
            'result' => array(
                'result' => $branch_result,
                'hil_confirmed' => $branch_result ? 1 : 0,
                'hil_state' => $hil_state,
            ),
            'error' => $error,
            'status' => $status, // 0 = waiting, 3 = completed
            'waiting' => $waiting, // Timestamp to pause workflow
            'sourceHandle' => $sourceHandle, // Branch routing (will be updated by HIL helper)
        );
        
        return $this->_results;
    }
}
