<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * AI Analyze User Request and Generate Task Checklist
 * Phân tích yêu cầu của user và sinh checklist công việc
 */
class WaicAction_ai_analyze_create_request extends WaicAction {
    protected $_code  = 'ai_analyze_create_request';
    protected $_order = 0;

    public function __construct( $block = null ) {
        $this->_name = __('AI - phân tích yêu cầu → Tạo checklist', 'ai-copilot-content-generator');
        $this->_name = __('Dựa trên MCP, tạo checklist các việc cần thực thực hiện', 'ai-copilot-content-generator');
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
            'user_message' => array(
                'type' => 'textarea',
                'label' => __('Tin nhắn người dùng *', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 4,
                'desc' => __('Nội dung yêu cầu từ user (thường lấy từ trigger Zalo)', 'ai-copilot-content-generator'),
            ),

            'context' => array(
                'type' => 'textarea',
                'label' => __('Context thêm (tùy chọn)', 'ai-copilot-content-generator'),
                'default' => '',
                'variables' => true,
                'rows' => 3,
                'desc' => __('Thông tin thêm về user, lịch sử chat, v.v.', 'ai-copilot-content-generator'),
            ),

            'max_tokens' => array(
                'type' => 'input',
                'label' => __('Max tokens', 'ai-copilot-content-generator'),
                'default' => '1000',
                'desc' => __('Giới hạn độ dài response', 'ai-copilot-content-generator'),
            ),
        );
    }
    
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
            'task_type' => __('Loại task (landing_page, blog_post, etc.)', 'ai-copilot-content-generator'),
            'checklist' => __('Checklist array (dạng PHP array)', 'ai-copilot-content-generator'),
            'checklist_json' => __('Checklist JSON (string để pass sang node khác)', 'ai-copilot-content-generator'),
            'needs_approval' => __('Có cần duyệt không (0/1)', 'ai-copilot-content-generator'),
            'estimated_time' => __('Thời gian ước tính (giây)', 'ai-copilot-content-generator'),
            'ai_response' => __('Full AI response (raw text)', 'ai-copilot-content-generator'),
            'user_message' => __('Tin nhắn gốc của user', 'ai-copilot-content-generator'),
        );
        return $this->_variables;
    }

    public function getResults( $taskId, $variables, $step = 0 ) {
        // Replace variables
        $user_message = $this->replaceVariables($this->getParam('user_message'), $variables);
        $context = $this->replaceVariables($this->getParam('context'), $variables);
        $max_tokens = (int) $this->getParam('max_tokens') ?: 1000;

        // Initialize result variables
        $error = '';
        $task_type = 'unknown';
        $checklist = array();
        $needs_approval = false;
        $estimated_time = 0;
        $ai_response = '';

        // Validation
        if ( empty( $user_message ) ) {
            $error = __('User message đang trống', 'ai-copilot-content-generator');
        }

        // Get OpenAI API key
        if ( empty( $error ) ) {
            $api_key = get_option('twf_openai_api_key');
            if ( empty( $api_key ) ) {
                $error = __('Chưa cấu hình OpenAI API key (twf_openai_api_key)', 'ai-copilot-content-generator');
            }
        }
        '';

        // Process AI request
        if ( empty( $error ) ) {
            // Build prompts
            $system_prompt = $this->getSystemPrompt();
            $user_prompt = "Yêu cầu của khách hàng:\n{$user_message}";
            if ( ! empty( $context ) ) {
                $user_prompt .= "\n\nContext:\n{$context}";
            }

            // Call OpenAI API với gpt-4o (flagship model, balance tốt giữa speed & intelligence)
            $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
                'timeout' => 45,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body' => json_encode( array(
                    'model' => 'gpt-4o',
                    'messages' => array(
                        array(
                            'role' => 'system',
                            'content' => $system_prompt,
                        ),
                        array(
                            'role' => 'user',
                            'content' => $user_prompt,
                        ),
                    ),
                    'temperature' => 0.3, // Giảm temperature để output JSON ổn định hơn
                    'max_tokens' => $max_tokens,
                ) ),
            ) );

            if ( is_wp_error( $response ) ) {
                $error = $this->controlText(
                    sprintf(
                        __('Lỗi gọi OpenAI API: %s', 'ai-copilot-content-generator'),
                        $response->get_error_message()
                    )
                );
            } else {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                
                // Debug: Log response body để troubleshoot
                error_log('[ai_analyze_create_request] OpenAI Response Body: ' . print_r($body, true));

                // Check for error in response
                if ( isset( $body['error'] ) ) {
                    $error = $this->controlText(
                        sprintf(
                            __('OpenAI API error: %s', 'ai-copilot-content-generator'),
                            $body['error']['message'] ?? 'Unknown error'
                        )
                    );
                } elseif ( ! isset( $body['choices'][0]['message']['content'] ) ) {
                    $error = __('OpenAI response không hợp lệ - thiếu content', 'ai-copilot-content-generator');
                    error_log('[ai_analyze_create_request] Response structure: ' . json_encode(array_keys($body)));
                } else {
                    $ai_response = $this->controlText( trim( $body['choices'][0]['message']['content'] ) );
                    error_log('[ai_analyze_create_request] AI Response Length: ' . strlen($ai_response));
                    
                    // Parse JSON từ AI response
                    $parsed = $this->parseAIResponse( $ai_response );
                    
                    if ( $parsed && is_array( $parsed ) ) {
                        $task_type = sanitize_key( $parsed['task_type'] ?? 'unknown' );
                        $checklist = is_array( $parsed['checklist'] ) ? $parsed['checklist'] : array();
                        $needs_approval = ! empty( $parsed['needs_approval'] );
                        $estimated_time = (int) ( $parsed['estimated_time'] ?? 0 );
                    } else {
                        $error = __('Không parse được JSON từ AI response', 'ai-copilot-content-generator');
                    }
                }
            }
        }
        back_trace('Result', ' Analyze create '.print_r( array(
            'error' => $error,
            'task_type' => $task_type,
            'checklist' => $checklist,
            'needs_approval' => $needs_approval,
            'estimated_time' => $estimated_time,
            'ai_response' => $ai_response,
        ), true ) );

        // Build results
        $this->_results = array(
            'result' => $error ? array() : array(
                'task_type' => $task_type,
                'checklist' => $checklist,
                'checklist_json' => json_encode($checklist, JSON_UNESCAPED_UNICODE),
                'needs_approval' => $needs_approval ? 1 : 0,
                'estimated_time' => $estimated_time,
                'ai_response' => $ai_response,
                'user_message' => $this->controlText($user_message),
            ),
            'error' => $error,
            'status' => empty($error) ? 3 : 7,
        );

        // Send Zalo notification
        if (function_exists('biz_get_zalo_admin_id') && function_exists('biz_send_message')) {
            $notification = "📋 *Phân tích yêu cầu hoàn tất*\n\n";
            $notification .= "Task: {$task_type}\n";
            $notification .= "Steps: " . count($checklist) . "\n";
            $notification .= "Estimated: {$estimated_time}s\n";
            if ($needs_approval) {
                $notification .= "⚠️ Cần duyệt trước khi thực thi";
            } else {
                $notification .= "✓ Sẵn sàng tự động thực thi";
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
     * System prompt cho AI
     */
    private function getSystemPrompt() {
        // Lấy danh sách tools từ MCP
        $mcpModel = WaicFrame::_()->getModule('mcp')->getModel();
        $tools = $mcpModel->getToolsList();
        
        // Group tools by category
        $toolsByCategory = array();
        foreach ($tools as $tool) {
            $category = isset($tool['category']) ? $tool['category'] : 'Other';
            if (!isset($toolsByCategory[$category])) {
                $toolsByCategory[$category] = array();
            }
            $toolsByCategory[$category][] = $tool;
        }
        
        // Build tools documentation
        $toolsDoc = "WordPress MCP Tools:\n";
        foreach ($toolsByCategory as $category => $categoryTools) {
            $toolsDoc .= "\n{$category}:\n";
            foreach ($categoryTools as $tool) {
                $toolsDoc .= "- {$tool['name']}: {$tool['description']}\n";
                if (!empty($tool['inputSchema']['required'])) {
                    $toolsDoc .= "  Required: " . implode(', ', $tool['inputSchema']['required']) . "\n";
                }
            }
        }
        
        return "Bạn là AI chuyên tạo automation workflow cho WordPress.

{$toolsDoc}

QUAN TRỌNG - MCP tools trả về TEXT không phải JSON:
Output: \"Post created ID 12391\" hoặc \"Form created ID 456\"
Hệ thống TỰ ĐỘNG parse ID từ pattern này.

QUY TẮC SỬ DỤNG ID:
1. Mỗi wp_create_* tự động tạo cả {{stepN.text}} và {{stepN.id}}
2. Không cần extract_id nữa - hệ thống auto-detect
3. Dùng {{step1.id}} trực tiếp sau create

QUY TẮC PARAMETER NAMES (QUAN TRỌNG):
- wp_update_post: Dùng 'ID' (HOA) không phải 'id' (thường)
- wp_create_post: Không cần ID (tool tự tạo)
- ai_generate_text: Dùng cho tạo nội dung văn bản (thay vì search)

Ví dụ workflow ĐÚNG tạo sản phẩm có AI content:
Step 1: wp_create_post (post_title=\"Tên\") → tự động tạo step1.id
Step 2: ai_generate_text (prompt=\"Viết về...\", word_count=300) → step2.text
Step 3: wp_update_post (ID={{step1.id}}, fields={post_content=\"{{step2.text}}\"}) → OK!

CẤM:
- Dùng 'id' cho wp_update_post (phải dùng 'ID' hoa)
- Dùng tool 'search' để tạo nội dung (search chỉ để TÌM KIẾM bài viết)
- Tạo extract_id step (không cần thiết)

Output JSON format:
{
  \"task_type\": \"landing_page|blog_post|product_management|...\",
  \"checklist\": [
    {\"step\": 1, \"tool\": \"wp_create_post\", \"description\": \"...\", \"params\": {...}},
    {\"step\": 2, \"tool\": \"ai_generate_text\", \"description\": \"...\", \"params\": {\"prompt\": \"...\", \"word_count\": 300}},
    {\"step\": 3, \"tool\": \"wp_update_post\", \"description\": \"...\", \"params\": {\"ID\": \"{{step1.id}}\", \"fields\": {\"post_content\": \"{{step2.text}}\"}}}
  ],
  \"needs_approval\": false,
  \"estimated_time\": 60,
  \"summary\": \"...\"
}";
    }

    /**
     * Parse AI response JSON
     */
    private function parseAIResponse( $text ) {
        // Tìm JSON block
        if ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
            $json = json_decode( $matches[0], true );
            
            if ( is_array( $json ) && isset( $json['task_type'], $json['checklist'] ) ) {
                return $json;
            }
        }
        
        return null;
    }
}
