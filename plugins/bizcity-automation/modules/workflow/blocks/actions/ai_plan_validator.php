<?php
if (!defined('ABSPATH')) exit;

/**
 * AI Plan Validator - Critic/Evaluator Layer
 * Kiểm tra plan vs MCP schemas, tìm missing required fields
 * Khởi tạo Human-in-the-Loop nếu thiếu thông tin
 */
class WaicAction_ai_plan_validator extends WaicAction {
    protected $_code  = 'ai_plan_validator';
    protected $_order = 0;

    public function __construct($block = null) {
        $this->_name = __('AI Plan Validator - Kiểm tra kế hoạch', 'ai-copilot-content-generator');
        $this->_desc = __('Validate plan với MCP schemas, yêu cầu user cung cấp thông tin còn thiếu', 'ai-copilot-content-generator');
        $this->setBlock($block);
    }

    public function getSettings() {
        if (empty($this->_settings)) $this->setSettings();
        return $this->_settings;
    }

    public function setSettings() {
        $this->_settings = array(
            'chat_id' => array(
                'type' => 'text',
                'label' => __('Chat ID *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'desc' => __('ID chat của user (để HIL)', 'ai-copilot-content-generator'),
            ),

            'checklist_json' => array(
                'type' => 'textarea',
                'label' => __('Checklist JSON *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 10,
                'desc' => __('Plan JSON từ ai_analyze_create_request', 'ai-copilot-content-generator'),
            ),

            'user_text' => array(
                'type' => 'textarea',
                'label' => __('User Message', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 3,
                'desc' => __('Message gốc từ user (để context)', 'ai-copilot-content-generator'),
            ),

            'skip_validation' => array(
                'type' => 'select',
                'label' => __('Skip Validation', 'ai-copilot-content-generator'),
                'default' => '0',
                'options' => array(
                    '0' => __('No - Validate đầy đủ', 'ai-copilot-content-generator'),
                    '1' => __('Yes - Chạy luôn không check', 'ai-copilot-content-generator'),
                ),
            ),
        );
    }

    public function getVariables() {
        if (empty($this->_variables)) $this->setVariables();
        return $this->_variables;
    }

    public function setVariables() {
        $this->_variables = array(
            'is_valid' => __('1 = plan hợp lệ, 0 = còn thiếu', 'ai-copilot-content-generator'),
            'missing_fields' => __('Danh sách fields còn thiếu (array)', 'ai-copilot-content-generator'),
            'missing_count' => __('Số lượng fields thiếu', 'ai-copilot-content-generator'),
            'validation_message' => __('Thông báo validation', 'ai-copilot-content-generator'),
            'hil_started' => __('1 = đã khởi tạo HIL, 0 = không cần', 'ai-copilot-content-generator'),
            'validated_checklist' => __('Checklist đã validate (JSON)', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    public function getResults($taskId, $variables, $step = 0) {
        // Replace variables
        $chat_id = $this->replaceVariables($this->getParam('chat_id'), $variables);
        $checklist_json = $this->replaceVariables($this->getParam('checklist_json'), $variables);
        $user_text = $this->replaceVariables($this->getParam('user_text'), $variables);
        $skip_validation = (int) $this->getParam('skip_validation');

        // Debug
        error_log('[ai_plan_validator] Chat ID: ' . $chat_id);
        error_log('[ai_plan_validator] Checklist preview: ' . substr($checklist_json, 0, 200));
        error_log('[ai_plan_validator] Skip validation: ' . $skip_validation);

        // Initialize result variables
        $error = '';
        $is_valid = 0;
        $missing_fields = array();
        $validation_message = '';
        $hil_started = 0;

        // Check if HIL already completed
        if (!function_exists('waic_hil_wait_or_continue')) {
            $helper_path = WP_CONTENT_DIR . '/mu-plugins/bizcity-admin-hook/includes/flows/tasklist.php';
            if (file_exists($helper_path)) {
                require_once $helper_path;
            }
        }
        
        // ⭐ CHECK HIL STATUS using helper
        if (function_exists('waic_hil_wait_or_continue')) {
            $blog_id = get_current_blog_id();
            $hil_status = waic_hil_wait_or_continue($chat_id, 1800, $blog_id);
            
            error_log('[ai_plan_validator] HIL status: ' . json_encode($hil_status));
            
            if ($hil_status['status'] === 'completed') {
                error_log('[ai_plan_validator] HIL already completed, plan is valid');
                
                return array(
                    'result' => array(
                        'is_valid' => 1,
                        'missing_fields' => array(),
                        'missing_count' => 0,
                        'validation_message' => 'HIL completed, all fields provided',
                        'hil_started' => 0,
                        'validated_checklist' => $checklist_json,
                        'hil_answers' => $hil_status['answers'],
                    ),
                    'error' => '',
                    'status' => 3,
                );
            }
        }

        // Validation
        if (empty($chat_id)) {
            $error = __('Chat ID đang trống', 'ai-copilot-content-generator');
        } elseif (empty($checklist_json)) {
            $error = __('Checklist JSON đang trống', 'ai-copilot-content-generator');
        }

        // Load helper functions
        if (!function_exists('waic_hil_start')) {
            $helper_path = WP_CONTENT_DIR . '/mu-plugins/bizcity-admin-hook/includes/flows/tasklist.php';
            if (file_exists($helper_path)) {
                require_once $helper_path;
            } else {
                $error = 'Helper tasklist.php not found';
            }
        }

        // Process validation
        if (empty($error)) {
            $checklist = json_decode($checklist_json, true);
            
            if (!is_array($checklist)) {
                $error = __('Checklist JSON không hợp lệ', 'ai-copilot-content-generator');
            } else {
                // Skip validation if enabled
                if ($skip_validation) {
                    $is_valid = 1;
                    $validation_message = 'Validation skipped by config';
                } else {
                    // Validate plan với MCP schemas
                    $validation = $this->validatePlanWithMCP($checklist, $user_text);
                    
                    $missing_fields = $validation['missing_fields'];
                    $is_valid = empty($missing_fields) ? 1 : 0;
                    
                    error_log('[ai_plan_validator] Missing fields: ' . json_encode($missing_fields));
                    
                    if (!$is_valid) {
                        // Khởi tạo HIL state
                        if (function_exists('waic_hil_start')) {
                            $hil_state = array(
                                'intent' => 'validate_plan',
                                'entity' => $user_text,
                                'missing' => $missing_fields,
                                'tone' => 'professional',
                            );
                            
                            $blog_id = get_current_blog_id();
                            $state = waic_hil_start($hil_state, $chat_id, $blog_id);
                            
                            error_log('[ai_plan_validator] HIL state created: ' . json_encode($state));
                            
                            // Gửi câu hỏi đầu tiên
                            if (function_exists('waic_hil_ask_next')) {
                                waic_hil_ask_next($blog_id, $chat_id);
                                $hil_started = 1;
                                $validation_message = 'Missing fields detected, HIL started';
                            }
                        }
                    } else {
                        $validation_message = 'All required fields present';
                    }
                }
            }
        }

        // Build results
        $waiting_timestamp = 0;
        
        // ⭐ Determine status:
        // - status = 7 (error) nếu có lỗi
        // - status = 0 (waiting) khi HIL started (chờ user trả lời)
        // - status = 3 (completed) khi validation pass hoặc HIL completed
        $status = 3; // Default: success
        if (!empty($error)) {
            $status = 7; // Error
        } elseif ($hil_started) {
            $status = 0; // Waiting for HIL
            // ⭐ Set waiting timestamp to pause workflow
            if (function_exists('waic_pause_for_hil')) {
                $blog_id = get_current_blog_id();
                $waiting_timestamp = waic_pause_for_hil($chat_id, 1800, $blog_id);
            } else {
                // Fallback: tính waiting thủ công
                $waiting_timestamp = time() + 1800; // 30 minutes
            }
        }
        
        $this->_results = array(
            'result' => array(
                'is_valid' => $is_valid,
                'missing_fields' => $missing_fields,
                'missing_count' => count($missing_fields),
                'validation_message' => $this->controlText($validation_message),
                'hil_started' => $hil_started,
                'validated_checklist' => $checklist_json,
            ),
            'error' => $error,
            'status' => $status, // ⭐ 0 = waiting, 3 = completed, 7 = error
            'waiting' => $waiting_timestamp, // ⭐ Timestamp to pause workflow
        );
        $chat_ids = biz_get_zalo_admin_id(get_current_blog_id());
        if (is_array($chat_ids)) {
            foreach ($chat_ids as $admin_chat_id) {
                biz_send_message($admin_chat_id, $checklist_json);
            }
        }
        // Send Zalo notification
        if ($hil_started && function_exists('biz_send_message') && function_exists('biz_get_zalo_admin_id')) {
            $notification = "⚠️ *Cần xác nhận thông tin*\n\n";
            $notification .= "Thiếu " . count($missing_fields) . " thông tin:\n";
            foreach (array_slice($missing_fields, 0, 5) as $field) {
                $notification .= "- {$field}\n";
            }
            
            $chat_ids = biz_get_zalo_admin_id(get_current_blog_id());
            if (is_array($chat_ids)) {
                foreach ($chat_ids as $admin_chat_id) {
                    biz_send_message($admin_chat_id, $notification);
                }
            }
        }

        return $this->_results;
    }

    /**
     * Validate plan với MCP tool schemas
     */
    private function validatePlanWithMCP($checklist, $user_text) {
        $missing_fields = array();
        
        // Get MCP model to access real schemas with required_description
        $mcpModel = WaicFrame::_()->getModule('mcp')->getModel();
        $allTools = $mcpModel->getTools(); // ⭐ FIX: Dùng getTools() thay vì getAllTools()
        
        error_log('[ai_plan_validator] Total tools loaded: ' . count($allTools));
        
        foreach ($checklist as $step) {
            $tool = $step['tool'] ?? $step['action'] ?? '';
            $params = $step['params'] ?? array();
            
            if (empty($tool)) continue;
            
            error_log('[ai_plan_validator] Validating tool: ' . $tool . ' with params: ' . json_encode($params));
            
            // Get REAL schema from MCP (with required_description)
            $toolSchema = $allTools[$tool] ?? null;
            
            if (!$toolSchema) {
                error_log('[ai_plan_validator] No schema found for tool: ' . $tool);
                continue;
            }
            
            $inputSchema = $toolSchema['inputSchema'] ?? array();
            $properties = $inputSchema['properties'] ?? array();
            $required = $inputSchema['required'] ?? array();
            
            error_log('[ai_plan_validator] Tool ' . $tool . ' required fields: ' . json_encode($required));
            
            foreach ($required as $field) {
                $value = $params[$field] ?? '';
                
                // ⭐ CHECK required_description để skip dynamic IDs
                $fieldSchema = $properties[$field] ?? array();
                $required_desc = $fieldSchema['required_description'] ?? '';
                
                error_log('[ai_plan_validator] Field: ' . $field . ' | Value: ' . $value . ' | Required_desc: ' . $required_desc);
                
                // SKIP nếu là DYNAMIC ID (lấy từ step trước)
                if (stripos($required_desc, 'DYNAMIC ID') !== false || stripos($required_desc, '⚠️') !== false) {
                    error_log('[ai_plan_validator] Skipping dynamic field: ' . $field . ' for tool: ' . $tool);
                    continue;
                }
                
                // Check if value is empty or is a variable reference that may not exist
                if (empty($value)) {
                    $missing_fields[] = $field . ' (cho ' . $tool . ')';
                } elseif ($this->isUnresolvedVariable($value)) {
                    $missing_fields[] = $field . ' (cho ' . $tool . ')';
                }
            }
        }
        
        return array(
            'missing_fields' => array_unique($missing_fields),
        );
    }

    /**
     * Get MCP tool schema (DEPRECATED - now using real MCP schemas in validatePlanWithMCP)
     */
    private function getMCPToolSchema($toolName) {
        // Fallback cứng schema (deprecated, giờ dùng real MCP schema)
        $schemas = array(
            'wp_create_post' => array(
                'required' => array('post_title', 'post_content'),
            ),
            'wp_create_cf7_form' => array(
                'required' => array('form_title'),
            ),
            'wp_attach_form_to_page' => array(
                'required' => array('page_id', 'form_id'),
            ),
            'wp_create_product' => array(
                'required' => array('product_name', 'price'),
            ),
            'wp_update_post' => array(
                'required' => array('post_id'),
            ),
        );
        
        return $schemas[$toolName] ?? null;
    }

    /**
     * Check if value is unresolved variable reference
     */
    private function isUnresolvedVariable($value) {
        // Check if contains {{...}} pattern
        return is_string($value) && preg_match('/\{\{[^}]+\}\}/', $value);
    }
}
