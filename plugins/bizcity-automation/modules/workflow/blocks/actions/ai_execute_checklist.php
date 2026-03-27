<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Execute AI Generated Checklist
 * Tự động chạy các task trong checklist theo thứ tự
 */
class WaicAction_ai_execute_checklist extends WaicAction {
    protected $_code  = 'ai_execute_checklist';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('MCP - Thực thi Checklist (Auto)', 'ai-copilot-content-generator');
        $this->_name = __('Nhận danh sách công việc từ AI bằng MCP tools - phân tích yêu cầu', 'ai-copilot-content-generator');
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
                'rows' => 10,
                'desc' => __('JSON checklist từ AI (node phân tích request)', 'ai-copilot-content-generator'),
            ),

            'auto_execute' => array(
                'type' => 'select',
                'label' => __('Tự động thực thi', 'ai-copilot-content-generator'),
                'default' => '1',
                'options' => array(
                    '1' => __('Yes - Tự động chạy hết', 'ai-copilot-content-generator'),
                    '0' => __('No - Chỉ parse và return', 'ai-copilot-content-generator'),
                ),
            ),
        );
    }
        
    /**
     * THÊM METHOD NÀY
     */
    public function getVariables() {
        if (empty($this->_variables)) {
            $this->setVariables();
        }
        return $this->_variables;
    }

    /**
     * THÊM METHOD NÀY
     */
    public function setVariables() {
        $this->_variables = array(
            'steps_executed' => __('Số bước đã thực thi', 'ai-copilot-content-generator'),
            'step_results' => __('Kết quả từng step (array)', 'ai-copilot-content-generator'),
            'execution_log' => __('Log chi tiết các bước (array)', 'ai-copilot-content-generator'),
            'execution_log_text' => __('Log dạng text (multiline)', 'ai-copilot-content-generator'),
            'final_page_id' => __('ID page cuối cùng (từ step 1)', 'ai-copilot-content-generator'),
            'final_form_id' => __('ID form cuối cùng (từ step 2)', 'ai-copilot-content-generator'),
            'final_page_url' => __('URL page cuối cùng', 'ai-copilot-content-generator'),
            'final_edit_url' => __('Link edit page cuối cùng', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        // Replace variables
        $checklist_json = $this->replaceVariables($this->getParam('checklist_json'), $variables);
        $auto_execute = (int) $this->getParam('auto_execute');

        // Debug logging
        error_log('[ai_execute_checklist] Raw checklist_json param: ' . $this->getParam('checklist_json'));
        error_log('[ai_execute_checklist] After replaceVariables: ' . substr($checklist_json, 0, 500));
        
        // Decode HTML entities (fix &quot; → ")
        $checklist_json = html_entity_decode($checklist_json, ENT_QUOTES, 'UTF-8');
        error_log('[ai_execute_checklist] After html_entity_decode: ' . substr($checklist_json, 0, 500));
        
        error_log('[ai_execute_checklist] Auto execute: ' . $auto_execute);
        error_log('[ai_execute_checklist] Available variables: ' . json_encode(array_keys($variables)));

        // Initialize result variables
        $error = '';
        $steps_executed = 0;
        $execution_log = array();
        $step_results = array();

        // Validation
        if ( empty( $checklist_json ) ) {
            $error = __('Checklist JSON đang trống', 'ai-copilot-content-generator');
            error_log('[ai_execute_checklist] ERROR: Checklist JSON is empty!');
        }

        // Process checklist
        if ( empty( $error ) ) {
            $checklist = json_decode( $checklist_json, true );
            
            error_log('[ai_execute_checklist] Decoded checklist type: ' . gettype($checklist));
            error_log('[ai_execute_checklist] Checklist is_array: ' . (is_array($checklist) ? 'YES' : 'NO'));
            error_log('[ai_execute_checklist] Checklist count: ' . (is_array($checklist) ? count($checklist) : 0));
            
            if ( ! is_array( $checklist ) ) {
                $error = __('Checklist JSON không hợp lệ', 'ai-copilot-content-generator');
                error_log('[ai_execute_checklist] ERROR: Checklist is not array! Type: ' . gettype($checklist));
            } 
            #elseif ( $auto_execute ) {
                error_log('[ai_execute_checklist] Starting execution loop...');
                // Execute từng step
                foreach ( $checklist as $task ) {
                    $step_num = (int) ( $task['step'] ?? 0 );
                    // Support both 'tool' (MCP style) and 'action' (legacy style)
                    $tool = sanitize_key( $task['tool'] ?? $task['action'] ?? '' );
                    $params = is_array( $task['params'] ) ? $task['params'] : array();
                    $description = $this->controlText( $task['description'] ?? '' );

                    $execution_log[] = sprintf(
                        '[Step %d] %s - %s',
                        $step_num,
                        $tool,
                        $description
                    );

                    // Replace variables từ previous steps
                    $params = $this->replaceStepVariables( $params, $step_results );

                    // Execute tool via MCP
                    $result = $this->executeAction( $tool, $params );

                    $step_results[$step_num] = is_array( $result ) ? $result : array();
                    $steps_executed++;
                    back_trace('NOTICE', '[ai_execute_checklist] Step ' . $step_num . ' result: ' . json_encode($result) );

                    if ( ! empty( $result['error'] ) ) {
                        $execution_log[] = '  ❌ Error: ' . $this->controlText( $result['error'] );
                        $error = $this->controlText(
                            sprintf(
                                __('Lỗi tại step %d: %s', 'ai-copilot-content-generator'),
                                $step_num,
                                $result['error']
                            )
                        );
                        break; // Stop on error
                    } else {
                        $execution_log[] = '  ✓ Success';
                    }
                    #back_trace('NOTICE', '[ai_execute_checklist] execution_log  ' . print_r($execution_log, true)  );
                }
            #}
        }

        // Build results
        $this->_results = array(
            'result' => array(
                'steps_executed' => $steps_executed,
                'step_results' => $step_results,
                'execution_log' => $execution_log,
                'execution_log_text' => implode("\n", $execution_log),
                
                // Extract results từ final step
                'final_page_id' => isset($step_results[1]['page_id']) ? (int) $step_results[1]['page_id'] : 0,
                'final_form_id' => isset($step_results[2]['form_id']) ? (int) $step_results[2]['form_id'] : 0,
                'final_page_url' => isset($step_results[1]['view_url']) ? esc_url($step_results[1]['view_url']) : '',
                'final_edit_url' => isset($step_results[1]['edit_url']) ? esc_url($step_results[1]['edit_url']) : '',
            ),
            'error' => $error,
            'status' => empty($error) ? 3 : 7,
        );

        // Send Zalo notification
        if (function_exists('biz_get_zalo_admin_id') && function_exists('biz_send_message')) {
            if (empty($error)) {
                $notification = "✅ *Thực thi hoàn tất*\n\n";
                $notification .= "Steps: {$steps_executed}\n";
                
                if (!empty($this->_results['result']['final_page_url'])) {
                    $notification .= "\n🔗 " . $this->_results['result']['final_page_url'];
                }
            } else {
                $notification = "❌ *Thực thi thất bại*\n\n";
                $notification .= "Error: {$error}";
            }
            
            $chat_ids = biz_get_zalo_admin_id(get_current_blog_id());
            if (is_array($chat_ids)) {
                foreach ($chat_ids as $chat_id) {
                    biz_send_message($chat_id, $notification);
                }
            }
        }

        return $this->_results;
    }

    /**
     * Replace {{step1.field}} trong params
     */
    private function replaceStepVariables( $params, $step_results ) {
        // Recursive function to replace variables in nested arrays
        $replace_recursive = function($data) use (&$replace_recursive, $step_results) {
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $data[$key] = $replace_recursive($value);
                }
                return $data;
            }
            
            if (is_string($data)) {
                // Find all {{stepN.field}} patterns
                if (preg_match_all('/\{\{step(\d+)\.([a-z_]+)\}\}/', $data, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $step_num = (int) $match[1];
                        $field = $match[2];
                        
                        $replacement = '';
                        if (isset($step_results[$step_num])) {
                            $step_data = $step_results[$step_num];
                            
                            // Smart mapping for common fields
                            if ($field === 'id') {
                                $replacement = $step_data['id'] ?? 
                                             $step_data['page_id'] ?? 
                                             $step_data['post_id'] ?? 
                                             $step_data['form_id'] ?? '';
                            } 
                            elseif ($field === 'text') {
                                $replacement = $step_data['text'] ?? 
                                             $step_data['content'] ?? 
                                             $step_data['title'] ?? '';
                            } 
                            else {
                                $replacement = $step_data[$field] ?? '';
                            }
                        }
                        
                        error_log("[ai_execute_checklist] Replacing {$match[0]} with: " . (is_scalar($replacement) ? $replacement : json_encode($replacement)));
                        $data = str_replace($match[0], $replacement, $data);
                    }
                }
            }
            
            return $data;
        };
        
        $result = $replace_recursive($params);
        
        // ⭐ FIX: wp_update_post expects 'ID' (uppercase) not 'id'
        if (is_array($result) && isset($result['id']) && !isset($result['ID'])) {
            $result['ID'] = $result['id'];
            unset($result['id']);
            error_log("[ai_execute_checklist] Mapped 'id' → 'ID' for wp_update_post compatibility");
        }
        
        return $result;
    }

    /**
     * Parse MCP tool result
     */
    private function parseMCPResult($mcpResponse) {
        if (!is_array($mcpResponse)) {
            return array('error' => 'Invalid MCP response');
        }
        
        // Check for error
        if (isset($mcpResponse['error'])) {
            return array(
                'error' => isset($mcpResponse['error']['message']) ? $mcpResponse['error']['message'] : 'Unknown error',
                'error_code' => isset($mcpResponse['error']['code']) ? $mcpResponse['error']['code'] : -1
            );
        }
        
        // Extract result
        if (!isset($mcpResponse['result'])) {
            return array('error' => 'No result in MCP response');
        }
        
        $result = $mcpResponse['result'];
        $output = array('success' => true);
        
        // Parse content
        if (isset($result['content']) && is_array($result['content'])) {
            foreach ($result['content'] as $item) {
                if ($item['type'] === 'text' && !empty($item['text'])) {
                    // Try parse as JSON
                    $parsed = json_decode($item['text'], true);
                    if (is_array($parsed)) {
                        $output = array_merge($output, $parsed);
                    } else {
                        $output['text'] = $item['text'];
                        
                        // Auto-extract ID from common patterns
                        if (preg_match('/ID\s+(\d+)/i', $item['text'], $matches)) {
                            $output['id'] = (int)$matches[1];
                            error_log('[ai_execute_checklist] Auto-extracted ID: ' . $output['id']);
                        }
                    }
                }
            }
        }
        
        return $output;
    }

    /**
     * Execute một action cụ thể thông qua MCP
     */
    private function executeAction( $action, $params ) {
        $action = sanitize_key( $action );
        
        // Debug logging
        error_log('[ai_execute_checklist] Executing MCP tool: ' . $action);
        error_log('[ai_execute_checklist] Tool params: ' . json_encode($params));
        
        // Sử dụng MCP model để dispatch tool
        $mcpModel = WaicFrame::_()->getModule('mcp')->getModel();
        
        // Generate unique ID cho request (must be int)
        $requestId = time() + rand(1000, 9999);
        
        // Gọi dispatchTool từ MCP model
        $mcpResult = $mcpModel->dispatchTool($action, $params, $requestId);
        
        // Debug MCP result
        error_log('[ai_execute_checklist] MCP raw result: ' . json_encode($mcpResult));
        
        // Parse kết quả
        $parsed = $this->parseMCPResult($mcpResult);
        
        error_log('[ai_execute_checklist] Parsed result: ' . json_encode($parsed));
        
        return $parsed;
    }

}
