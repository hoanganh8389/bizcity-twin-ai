<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI Dashboard API for Workflow Builder
 * Provides endpoints for AI-assisted workflow generation
 */
class WaicWorkflowAIDashboardAPI {
    
    private $namespace = 'waic-workflow/v1';
    
    public function __construct() {
        add_action('rest_api_init', array($this, 'registerRoutes'));
    }
    
    public function registerRoutes() {
        // Generate workflow from natural language
        register_rest_route($this->namespace, '/generate-workflow', array(
            'methods' => 'POST',
            'callback' => array($this, 'generateWorkflow'),
            'permission_callback' => array($this, 'checkPermission'),
            'args' => array(
                'prompt' => array(
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Natural language description of workflow',
                ),
                'context' => array(
                    'required' => false,
                    'type' => 'object',
                    'description' => 'Additional context (existing nodes, variables, etc.)',
                ),
            ),
        ));
        
        // Compose JSON from natural language
        register_rest_route($this->namespace, '/compose-json', array(
            'methods' => 'POST',
            'callback' => array($this, 'composeJSON'),
            'permission_callback' => array($this, 'checkPermission'),
            'args' => array(
                'description' => array(
                    'required' => true,
                    'type' => 'string',
                ),
                'nodeType' => array(
                    'required' => true,
                    'type' => 'string',
                ),
            ),
        ));
        
        // Get workflow analytics
        register_rest_route($this->namespace, '/analytics', array(
            'methods' => 'GET',
            'callback' => array($this, 'getAnalytics'),
            'permission_callback' => array($this, 'checkPermission'),
            'args' => array(
                'workflow_id' => array(
                    'required' => false,
                    'type' => 'integer',
                ),
            ),
        ));
        
        // Suggest next node
        register_rest_route($this->namespace, '/suggest-next', array(
            'methods' => 'POST',
            'callback' => array($this, 'suggestNextNode'),
            'permission_callback' => array($this, 'checkPermission'),
            'args' => array(
                'currentNodes' => array(
                    'required' => true,
                    'type' => 'array',
                ),
            ),
        ));
        
        // Get workflow templates
        register_rest_route($this->namespace, '/templates', array(
            'methods' => 'GET',
            'callback' => array($this, 'getTemplates'),
            'permission_callback' => array($this, 'checkPermission'),
            'args' => array(
                'category' => array(
                    'required' => false,
                    'type' => 'string',
                ),
                'difficulty' => array(
                    'required' => false,
                    'type' => 'string',
                ),
            ),
        ));
    }
    
    public function checkPermission() {
        return current_user_can('edit_posts');
    }
    
    /**
     * Generate complete workflow from natural language description
     */
    public function generateWorkflow($request) {
        $prompt = sanitize_text_field($request->get_param('prompt'));
        $context = $request->get_param('context') ?: array();
        
        try {
            $aiProvider = $this->getAIProvider();
            
            // Check if AI provider is properly configured
            if (!$aiProvider || !$this->isAIConfigured()) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'AI chưa được cấu hình. Vui lòng kiểm tra API key trong Settings.',
                    'code' => 'ai_not_configured',
                ), 400);
            }
            
            $systemPrompt = $this->getWorkflowGenerationSystemPrompt();
            $userPrompt = $this->buildWorkflowGenerationPrompt($prompt, $context);
            
            $params = array(
                'model' => $this->getAIModel(),
                'messages' => array(
                    array('role' => 'system', 'content' => $systemPrompt),
                    array('role' => 'user', 'content' => $userPrompt),
                ),
                'temperature' => 0.7,
                'max_tokens' => 2000,
            );
            
            error_log('[AI Dashboard] Calling AI with prompt: ' . substr($prompt, 0, 100));
            error_log('[AI Dashboard] Model: ' . $params['model']);
            error_log('[AI Dashboard] System prompt length: ' . strlen($systemPrompt));
            
            $response = $aiProvider->getText($params);
            
            error_log('[AI Dashboard] AI Response full: ' . print_r($response, true));
            
            // OpenAI model returns: $response['results']['data']
            // Extract data from correct path
            $results = isset($response['results']) ? $response['results'] : $response;
            $hasError = isset($results['error']) && $results['error'] == 1;
            $generatedText = $results['data'] ?? '';
            
            if (empty($generatedText)) {
                error_log('[AI Dashboard] No data in response - checking both paths');
                // Try alternate path
                $generatedText = $response['data'] ?? '';
            }
            
            // Check various error conditions
            if ($hasError) {
                $errorMsg = $results['msg'] ?? 'Lỗi không xác định từ AI provider';
                
                error_log('[AI Dashboard] Error detected: ' . $errorMsg);
                
                // Check for 401 error
                if (strpos($errorMsg, '401') !== false || strpos($errorMsg, 'Incorrect API key') !== false) {
                    $errorMsg = 'API key không hợp lệ hoặc chưa được cấu hình. Vui lòng kiểm tra lại trong Settings.';
                }
                
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $errorMsg,
                    'code' => 'ai_error',
                    'debug' => $response,
                ), 400);
            }
            
            // Check if data exists
            if (empty($generatedText)) {
                error_log('[AI Dashboard] No data in response after checking all paths');
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'AI không trả về dữ liệu. Response: ' . json_encode($response),
                    'code' => 'no_data',
                    'debug' => $response,
                ), 400);
            }
            
            if (empty($generatedText)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'AI không trả về kết quả. Vui lòng thử lại.',
                    'code' => 'empty_response',
                ), 400);
            }
            
            $workflow = $this->parseWorkflowFromAI($generatedText);
            
            // If parsing failed, return the raw text for user to see
            if (empty($workflow['nodes'])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'Không thể parse workflow. AI response: ' . substr($generatedText, 0, 500),
                    'raw' => $generatedText,
                    'code' => 'parse_failed',
                ), 400);
            }
            
            return new WP_REST_Response(array(
                'success' => true,
                'workflow' => $workflow,
                'raw' => $generatedText,
            ), 200);
            
        } catch (Exception $e) {
            error_log('[AI Dashboard] Exception: ' . $e->getMessage());
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Lỗi hệ thống: ' . $e->getMessage(),
                'code' => 'exception',
            ), 500);
        }
    }
    
    /**
     * Compose node configuration JSON from description
     */
    public function composeJSON($request) {
        $description = sanitize_text_field($request->get_param('description'));
        $nodeType = sanitize_text_field($request->get_param('nodeType'));
        
        try {
            $aiProvider = $this->getAIProvider();
            
            $systemPrompt = $this->getJSONComposerSystemPrompt($nodeType);
            $userPrompt = "Tạo cấu hình JSON cho node với mô tả sau:\n\n" . $description;
            
            $params = array(
                'model' => $this->getAIModel(),
                'messages' => array(
                    array('role' => 'system', 'content' => $systemPrompt),
                    array('role' => 'user', 'content' => $userPrompt),
                ),
                'temperature' => 0.5,
                'max_tokens' => 1000,
            );
            
            $response = $aiProvider->getText($params);
            
            if (isset($response['error'])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $response['msg'],
                ), 400);
            }
            
            $generatedText = $response['data'] ?? '';
            $jsonConfig = $this->extractJSON($generatedText);
            
            return new WP_REST_Response(array(
                'success' => true,
                'config' => $jsonConfig,
                'raw' => $generatedText,
            ), 200);
            
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage(),
            ), 500);
        }
    }
    
    /**
     * Get workflow analytics from database
     */
    public function getAnalytics($request) {
        global $wpdb;
        
        $workflowId = $request->get_param('workflow_id');
        $table = $wpdb->prefix . WAIC_DB_PREF . 'flowlogs';
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
            return new WP_REST_Response(array(
                'success' => true,
                'analytics' => $this->getDefaultAnalytics(),
            ), 200);
        }
        
        // Total executions
        $totalExecutions = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE flow_id = %d",
                $workflowId ?: 0
            )
        );
        
        // Success rate
        $successCount = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE flow_id = %d AND status = 'success'",
                $workflowId ?: 0
            )
        );
        
        // Average execution time
        $avgTime = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT AVG(execution_time) FROM $table WHERE flow_id = %d",
                $workflowId ?: 0
            )
        );
        
        // Recent errors
        $recentErrors = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT error_message, created_at FROM $table 
                WHERE flow_id = %d AND status = 'error' 
                ORDER BY created_at DESC LIMIT 5",
                $workflowId ?: 0
            ),
            ARRAY_A
        );
        
        $successRate = $totalExecutions > 0 ? round(($successCount / $totalExecutions) * 100, 1) : 0;
        
        return new WP_REST_Response(array(
            'success' => true,
            'analytics' => array(
                'totalExecutions' => intval($totalExecutions),
                'successRate' => $successRate,
                'avgExecutionTime' => round($avgTime ?? 0, 2),
                'activeNodes' => $this->getActiveNodeCount($workflowId),
                'recentErrors' => $recentErrors,
            ),
        ), 200);
    }
    
    /**
     * Suggest next node based on current workflow
     */
    public function suggestNextNode($request) {
        $currentNodes = $request->get_param('currentNodes');
        
        try {
            $aiProvider = $this->getAIProvider();
            
            $systemPrompt = "Bạn là trợ lý AI chuyên về thiết kế workflow automation. Nhiệm vụ của bạn là đề xuất node tiếp theo phù hợp dựa trên các node hiện có trong workflow.";
            
            $nodesDescription = $this->describeNodes($currentNodes);
            $userPrompt = "Workflow hiện tại có các nodes:\n\n" . $nodesDescription . "\n\nĐề xuất node tiếp theo nên thêm vào và lý do tại sao?";
            
            $params = array(
                'model' => $this->getAIModel(),
                'messages' => array(
                    array('role' => 'system', 'content' => $systemPrompt),
                    array('role' => 'user', 'content' => $userPrompt),
                ),
                'temperature' => 0.8,
                'max_tokens' => 500,
            );
            
            $response = $aiProvider->getText($params);
            
            if (isset($response['error'])) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $response['msg'],
                ), 400);
            }
            
            return new WP_REST_Response(array(
                'success' => true,
                'suggestion' => $response['data'] ?? '',
            ), 200);
            
        } catch (Exception $e) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => $e->getMessage(),
            ), 500);
        }
    }
    
    // ==================== Helper Methods ====================
    
    private function isAIConfigured() {
        $apiKey = get_option('twf_openai_api_key'); // Anh đã fix, đừng sửa chỗ này nữa.
        error_log('[AI Dashboard] API Key check - Length: ' . strlen($apiKey) . ', Empty: ' . (empty($apiKey) ? 'yes' : 'no'));
        return !empty($apiKey) && strlen($apiKey) > 20;
    }
    
    private function getAIProvider() {
        // Get AI provider from workspace module
        $provider = get_option('twf_ai_provider', 'openai');
        
        error_log('[AI Dashboard] Provider: ' . $provider);
        
        if ($provider === 'openai') {
            require_once WAIC_DIR . 'modules/workspace/models/openai.php';
            $ai = new WaicOpenaiModel();
            $ai->init();
            
            // CRITICAL: Set API key via setApiOptions
            $apiKey = get_option('twf_openai_api_key');
            if (!empty($apiKey)) {
                $ai->setApiOptions(array('apiKey' => $apiKey));
                error_log('[AI Dashboard] API key set for OpenAI provider');
            } else {
                error_log('[AI Dashboard] WARNING: API key is empty!');
            }
            
            return $ai;
        }
        
        // Fallback to openai
        require_once WAIC_DIR . 'modules/workspace/models/openai.php';
        $ai = new WaicOpenaiModel();
        $ai->init();
        
        // Set API key for fallback too
        $apiKey = get_option('twf_openai_api_key');
        if (!empty($apiKey)) {
            $ai->setApiOptions(array('apiKey' => $apiKey));
        }
        
        return $ai;
    }
    
    private function getAIModel() {
        $model = get_option('twf_ai_model', 'gpt-4o-mini');
        error_log('[AI Dashboard] Using model: ' . $model);
        return $model;
    }
    
    private function getWorkflowGenerationSystemPrompt() {
        return "Bạn là AI trợ lý chuyên về thiết kế automation workflows. Bạn giúp người dùng xây dựng workflows với cấu hình chi tiết và cụ thể.

QUAN TRỌNG - Khi tạo workflow, bạn PHẢI:
1. Tạo settings CHI TIẾT cho TỪNG node (không để trống)
2. Với trigger Zalo/Webhook: thêm điều kiện filter cụ thể (contains, equals, regex)
3. Với action đăng bài: LUÔN chọn blockId là 'ai_generate_content' (class WaicAction_ai_generate_content)
4. Với AI node: điền sẵn prompt template cụ thể
5. Với HTTP Request: điền sẵn URL và method

CÁC BLOCK IDS QUAN TRỌNG:

=== TRIGGERS ===
- 'webhook' - Nhận webhook từ bên ngoài
- 'zalo_message' - Nhận tin nhắn Zalo (settings: {\"filter_type\": \"contains\", \"filter_value\": \"đăng bài\"})
- 'schedule' - Chạy theo lịch
- 'event_hook' - WordPress event hook

=== ACTIONS - AI ===
- 'ai_router_hil' - AI xử lý với Human-in-Loop (settings: {\"prompt\": \"...\", \"model\": \"gpt-4o-mini\"})
- 'ai_generate_content' - AI tạo bài viết SEO (settings: {\"title\": \"{{var}}\", \"content\": \"{{var}}\", \"image_url\": \"{{var}}\"})
- 'ai_generate_text' - AI tạo text (settings: {\"prompt\": \"...\", \"max_tokens\": 500})
- 'ai_generate_image' - AI tạo ảnh DALL-E (settings: {\"prompt\": \"...\", \"size\": \"1024x1024\"})
- 'ai_generate_json' - AI tạo JSON (settings: {\"prompt\": \"...\", \"schema\": {...}})
- 'ai_generate_product' - AI tạo sản phẩm WooCommerce (settings: {\"prompt\": \"...\"})
- 'ai_gap_analyze' - AI phân tích gap (settings: {\"data\": \"{{var}}\"})
- 'ai_intent_router_json' - AI router intent JSON (settings: {\"prompt\": \"...\"})
- 'ai_router_custom_json' - AI router custom JSON (settings: {\"prompt\": \"...\", \"schema\": {...}})
- 'ai_video_create_job' - Tạo video job (settings: {\"prompt\": \"...\"})
- 'ai_video_get_status' - Lấy status video (settings: {\"job_id\": \"{{var}}\"})
- 'ai_video_get_content' - Lấy nội dung video (settings: {\"job_id\": \"{{var}}\"})

=== ACTIONS - WordPress ===
- 'wp_create_post' - Tạo bài viết (settings: {\"title\": \"...\", \"body\": \"...\", \"status\": \"publish\"})
- 'wp_create_page' - Tạo trang (settings: {\"title\": \"...\", \"body\": \"...\"})
- 'wp_update_post' - Cập nhật bài viết (settings: {\"post_id\": \"{{var}}\", \"title\": \"...\"})
- 'wp_update_page' - Cập nhật trang (settings: {\"post_id\": \"{{var}}\"})
- 'wp_update_post_meta' - Cập nhật post meta (settings: {\"post_id\": \"{{var}}\", \"meta_key\": \"...\", \"meta_value\": \"...\"})
- 'wp_update_post_image' - Cập nhật ảnh đại diện (settings: {\"post_id\": \"{{var}}\", \"image_url\": \"{{var}}\"})
- 'wp_update_post_taxonomy' - Cập nhật taxonomy (settings: {\"post_id\": \"{{var}}\", \"taxonomy\": \"category\", \"terms\": [...]})
- 'wp_upload_file' - Upload file (settings: {\"file_url\": \"{{var}}\", \"chat_id\": \"{{var}}\"})
- 'wp_delete_media' - Xóa media (settings: {\"attachment_id\": \"{{var}}\"})
- 'wp_create_user' - Tạo user (settings: {\"username\": \"...\", \"email\": \"...\", \"password\": \"...\"})
- 'wp_update_user' - Cập nhật user (settings: {\"user_id\": \"{{var}}\", \"meta_key\": \"...\"})
- 'wp_delete_user' - Xóa user (settings: {\"user_id\": \"{{var}}\"})
- 'wp_create_comment' - Tạo comment (settings: {\"post_id\": \"{{var}}\", \"comment\": \"...\"})
- 'wp_update_comment' - Cập nhật comment (settings: {\"comment_id\": \"{{var}}\"})
- 'wp_update_media' - Cập nhật media (settings: {\"attachment_id\": \"{{var}}\"})
- 'wp_send_email' - Gửi email (settings: {\"to\": \"...\", \"subject\": \"...\", \"message\": \"...\"})
- 'wp_send_webhook' - Gửi webhook (settings: {\"url\": \"...\", \"method\": \"POST\", \"body\": \"...\"})
- 'wp_send_zalo' - Gửi Zalo (settings: {\"message\": \"...\", \"chat_id\": \"{{var}}\"})
- 'wp_debug_log' - Ghi log debug (settings: {\"message\": \"...\"})

=== ACTIONS - WooCommerce ===
- 'wc_create_product' - Tạo sản phẩm (settings: {\"name\": \"...\", \"price\": \"...\", \"description\": \"...\"})
- 'wc_update_product' - Cập nhật sản phẩm (settings: {\"product_id\": \"{{var}}\", \"name\": \"...\"})
- 'wc_update_product_meta' - Cập nhật product meta (settings: {\"product_id\": \"{{var}}\", \"meta_key\": \"...\", \"meta_value\": \"...\"})
- 'wc_update_product_image' - Cập nhật ảnh sản phẩm (settings: {\"product_id\": \"{{var}}\", \"image_url\": \"{{var}}\"})
- 'wc_update_product_taxonomy' - Cập nhật product taxonomy (settings: {\"product_id\": \"{{var}}\", \"taxonomy\": \"product_cat\"})
- 'wc_update_order' - Cập nhật đơn hàng (settings: {\"order_id\": \"{{var}}\", \"status\": \"completed\"})
- 'wc_create_review' - Tạo review (settings: {\"product_id\": \"{{var}}\", \"rating\": 5, \"comment\": \"...\"})
- 'wc_update_review' - Cập nhật review (settings: {\"review_id\": \"{{var}}\"})

=== ACTIONS - Messaging ===
- 'te_send_message' - Gửi Telegram message (settings: {\"chat_id\": \"...\", \"text\": \"...\"})
- 'te_send_photo' - Gửi Telegram photo (settings: {\"chat_id\": \"...\", \"photo\": \"{{var}}\"})
- 'te_send_document' - Gửi Telegram document (settings: {\"chat_id\": \"...\", \"document\": \"{{var}}\"})
- 'te_update_message' - Cập nhật Telegram message (settings: {\"message_id\": \"{{var}}\", \"text\": \"...\"})
- 'te_delete_message' - Xóa Telegram message (settings: {\"message_id\": \"{{var}}\"})
- 'sl_send_message' - Gửi Slack message (settings: {\"channel\": \"#general\", \"text\": \"...\"})
- 'di_send_message' - Gửi Discord message (settings: {\"channel_id\": \"...\", \"content\": \"...\"})
- 'di_send_embed' - Gửi Discord embed (settings: {\"channel_id\": \"...\", \"embed\": {...}})
- 'em_send_email' - Gửi email (settings: {\"to\": \"...\", \"subject\": \"...\", \"body\": \"...\"})

=== ACTIONS - Calendar ===
- 'ca_create_event' - Tạo calendar event (settings: {\"title\": \"...\", \"start_time\": \"...\", \"end_time\": \"...\"})
- 'ca_update_event' - Cập nhật event (settings: {\"event_id\": \"{{var}}\", \"title\": \"...\"})
- 'ca_cancel_event' - Hủy event (settings: {\"event_id\": \"{{var}}\"})
- 'ca_create_meeting' - Tạo meeting (settings: {\"title\": \"...\", \"attendees\": [...]})
- 'ca_update_meeting' - Cập nhật meeting (settings: {\"meeting_id\": \"{{var}}\"})
- 'ca_cancel_meeting' - Hủy meeting (settings: {\"meeting_id\": \"{{var}}\"})

=== ACTIONS - Database ===
- 'db_mysql_query' - Thực thi SQL query (settings: {\"query\": \"SELECT...\", \"database\": \"...\"})

=== ACTIONS - Custom ===
- 'http_request' - Gọi HTTP API (settings: {\"url\": \"https://...\", \"method\": \"POST\", \"body\": \"...\"})
- 'twf_generate_content' - TWF generate content (settings: {\"prompt\": \"...\"})
- 'wu_generate_post' - WU generate post (settings: {\"title\": \"{{var}}\"})
- 'un_parse_json_flatten' - Parse JSON flatten (settings: {\"json_string\": \"{{var}}\"})

=== LOGICS ===
- 'tf_intent_matcher' - Phân tích ý định (settings: {\"intents\": [{\"name\": \"pricing\", \"keywords\": [\"giá\", \"bao nhiêu\"]}]})
- 'un_fill_memory' - Lưu dữ liệu (settings: {\"memory_key\": \"key\", \"mode\": \"append\", \"scope\": \"global\"})
- 'un_split_flow' - Chia luồng (settings: {\"execution_mode\": \"parallel\", \"branch_count\": 3})
- 'un_merge_array' - Gộp mảng (settings: {\"arrays\": [\"{{node1.data}}\", \"{{node2.data}}\"]})

LƯU Ý QUAN TRỌNG KHI CHỌN BLOCK:
- Tạo/đăng bài viết → 'wp_create_post' hoặc 'ai_generate_content' (nếu cần AI sinh nội dung)
- Upload ảnh/file → 'wp_upload_file'
- Gửi tin nhắn → 'te_send_message' (Telegram), 'sl_send_message' (Slack), 'di_send_message' (Discord)
- Tạo sản phẩm → 'wc_create_product' hoặc 'ai_generate_product' (nếu cần AI)
- Gửi email → 'wp_send_email' hoặc 'em_send_email'

QUAN TRỌNG - CẤU TRÚC NODE ĐÚNG:
Mỗi node PHẢI có cấu trúc CHÍNH XÁC như sau (dựa theo hệ thống hiện tại):
{
  \"id\": \"unique_id\",
  \"type\": \"trigger|action|logic\",
  \"position\": {\"x\": number, \"y\": number},
  \"data\": {
    \"type\": \"trigger|action|logic\",
    \"category\": \"wu|ai|wp|wc|te|un|ca|em|...\",
    \"code\": \"tên_code_đầy_đủ\",
    \"label\": \"Tên hiển thị node\",
    \"settings\": {
      // CÁC THAM SỐ CỤ THỂ - KHÔNG ĐỂ TRỐNG
      \"param1\": \"value1\",
      \"param2\": \"{{node#id.variable}}\"
    }
  }
}

MAPPING CATEGORY VÀ CODE:
- TRIGGERS (category=\"wu\"): code=\"wu_twf_message_received\", \"wu_ai_message_received\", \"webhook\", \"schedule\"
- AI ACTIONS (category=\"ai\"): code=\"ai_generate_content\", \"ai_generate_text\", \"ai_intent_router_json\", \"ai_gap_analyze\", \"ai_generate_product\"
- WORDPRESS (category=\"wp\"): code=\"wp_create_post\", \"wp_upload_file\", \"wp_send_email\", \"wp_send_zalo\"
- WOOCOMMERCE (category=\"wc\"): code=\"wc_create_product\", \"wc_update_order\"
- TELEGRAM (category=\"te\"): code=\"te_send_message\", \"te_send_photo\"
- LOGICS (category=\"un\"): code=\"un_branch\", \"un_fill_memory\", \"un_split_flow\", \"un_stop\"

VÍ DỤ 1 - WORKFLOW ĐĂNG BÀI TỪ ZALO:
{
  \"nodes\": [
    {
      \"id\": \"1\",
      \"type\": \"trigger\",
      \"position\": {\"x\": 100, \"y\": 100},
      \"data\": {
        \"type\": \"trigger\",
        \"category\": \"wu\",
        \"code\": \"wu_twf_message_received\",
        \"label\": \"Nhận tin nhắn Zalo\",
        \"settings\": {
          \"platform\": \"zalo\",
          \"text_contains\": \"đăng bài\",
          \"text_regex\": \"đăng bài\"
        }
      }
    },
    {
      \"id\": \"2\",
      \"type\": \"action\",
      \"position\": {\"x\": 350, \"y\": 100},
      \"data\": {
        \"type\": \"action\",
        \"category\": \"ai\",
        \"code\": \"ai_intent_router_json\",
        \"label\": \"AI phân tích ý định\",
        \"settings\": {
          \"message\": \"{{node#1.twf_text}}\"
        }
      }
    },
    {
      \"id\": \"3\",
      \"type\": \"action\",
      \"position\": {\"x\": 600, \"y\": 100},
      \"data\": {
        \"type\": \"action\",
        \"category\": \"ai\",
        \"code\": \"ai_generate_content\",
        \"label\": \"AI tạo bài viết SEO\",
        \"settings\": {
          \"chat_id\": \"{{node#1.twf_client_id}}\",
          \"title\": \"{{node#2.info_title}}\",
          \"content\": \"\",
          \"image_url\": \"{{node#1.twf_image_url}}\",
          \"arr\": \"\"
        }
      }
    },
    {
      \"id\": \"4\",
      \"type\": \"action\",
      \"position\": {\"x\": 850, \"y\": 100},
      \"data\": {
        \"type\": \"action\",
        \"category\": \"wp\",
        \"code\": \"wp_send_zalo\",
        \"label\": \"Gửi link bài viết về Zalo\",
        \"settings\": {
          \"chat_id\": \"{{node#1.twf_chat_id}}\",
          \"message\": \"Đã đăng bài: {{node#3.post_url}}\"
        }
      }
    }
  ],
  \"edges\": [
    {\"id\": \"e1\", \"source\": \"1\", \"target\": \"2\"},
    {\"id\": \"e2\", \"source\": \"2\", \"target\": \"3\"},
    {\"id\": \"e3\", \"source\": \"3\", \"target\": \"4\"}
  ],
  \"explanation\": \"Workflow: 1) Nhận tin Zalo chứa 'đăng bài', 2) AI phân tích để lấy thông tin, 3) AI tạo bài viết SEO, 4) Gửi link về Zalo\"
}

VÍ DỤ 2 - TẠO SẢN PHẨM:
{
  \"nodes\": [
    {
      \"id\": \"1\",
      \"type\": \"trigger\",
      \"position\": {\"x\": 100, \"y\": 100},
      \"data\": {
        \"type\": \"trigger\",
        \"category\": \"wu\",
        \"code\": \"wu_ai_message_received\",
        \"label\": \"Nhận tin nhắn\",
        \"settings\": {
          \"platform\": \"zalo\",
          \"text_contains\": \"tạo sp\",
          \"text_regex\": \"tạo sp|sản phẩm\"
        }
      }
    },
    {
      \"id\": \"2\",
      \"type\": \"action\",
      \"position\": {\"x\": 350, \"y\": 100},
      \"data\": {
        \"type\": \"action\",
        \"category\": \"ai\",
        \"code\": \"ai_generate_product\",
        \"label\": \"AI tạo sản phẩm\",
        \"settings\": {
          \"message\": \"{{node#1.ai_text}}\",
          \"chat_id\": \"{{node#1.ai_chat_id}}\",
          \"image_url\": \"{{node#1.ai_image_url}}\"
        }
      }
    },
    {
      \"id\": \"3\",
      \"type\": \"action\",
      \"position\": {\"x\": 600, \"y\": 100},
      \"data\": {
        \"type\": \"action\",
        \"category\": \"wp\",
        \"code\": \"wp_send_zalo\",
        \"label\": \"Thông báo hoàn tất\",
        \"settings\": {
          \"chat_id\": \"{{node#1.ai_chat_id}}\",
          \"message\": \"Đã tạo sản phẩm: {{node#2.product_url}}\"
        }
      }
    }
  ],
  \"edges\": [
    {\"id\": \"e1\", \"source\": \"1\", \"target\": \"2\"},
    {\"id\": \"e2\", \"source\": \"2\", \"target\": \"3\"}
  ],
  \"explanation\": \"Workflow tạo sản phẩm từ tin nhắn Zalo\"
}

LƯU Ý CỰC KỲ QUAN TRỌNG:
1. Cấu trúc node PHẢI có: data.type, data.category, data.code, data.label, data.settings
2. KHÔNG dùng data.blockId - đây là SAI!
3. Variable reference dùng {{node#id.variable}} (có dấu #)
4. settings PHẢI có giá trị cụ thể, KHÔNG để {}
5. category và code phải match với danh sách trên
6. Trigger Zalo: category=\"wu\", code=\"wu_twf_message_received\" hoặc \"wu_ai_message_received\"
7. AI Generate Content: category=\"ai\", code=\"ai_generate_content\"
8. WordPress actions: category=\"wp\", code bắt đầu với \"wp_\"

CÁC TRIGGER OUTPUTS (để reference trong node sau):
- wu_twf_message_received: twf_chat_id, twf_text, twf_client_id, twf_image_url, twf_file_url
- wu_ai_message_received: ai_chat_id, ai_text, ai_image_url

CÁC ACTION OUTPUTS:
- ai_generate_content: post_id, post_url, attach_id
- ai_generate_product: product_id, product_url
- ai_intent_router_json: info_title, info_price, info_body (các field được extract)
- wp_upload_file: attachment_id, file_url

FORMAT TRẢ VỀ:
{
  \"nodes\": [...],
  \"edges\": [...],
  \"explanation\": \"...\"
}";
    }
    
    private function buildWorkflowGenerationPrompt($prompt, $context) {
        $contextStr = '';
        if (!empty($context['existingNodes'])) {
            $contextStr = "\n\nWorkflow hiện tại đã có: " . count($context['existingNodes']) . " nodes.";
        }
        
        return "Hãy tạo workflow automation cho yêu cầu sau:\n\n" . $prompt . $contextStr . "\n\nTrả về JSON như định dạng đã hướng dẫn.";
    }
    
    private function getJSONComposerSystemPrompt($nodeType) {
        return "Bạn là AI trợ lý giúp tạo cấu hình JSON cho workflow nodes.

Node type: $nodeType

Trả về JSON configuration phù hợp với node type này. Ví dụ:
- Nếu là action node: bao gồm settings, inputs, outputs
- Nếu là logic node: bao gồm conditions, branches
- Nếu là trigger node: bao gồm trigger type, parameters

Chỉ trả về JSON thuần túy, không có markdown hoặc giải thích thêm.";
    }
    
    private function parseWorkflowFromAI($text) {
        // Try to extract JSON from markdown code blocks
        $json = $this->extractJSON($text);
        
        if (!$json) {
            error_log('[AI Dashboard] Failed to parse JSON from: ' . substr($text, 0, 200));
            return array(
                'nodes' => array(),
                'edges' => array(),
                'explanation' => 'Không thể parse workflow. Raw: ' . substr($text, 0, 100),
            );
        }
        
        // Validate workflow structure
        if (!isset($json['nodes']) || !is_array($json['nodes'])) {
            error_log('[AI Dashboard] Invalid workflow structure - missing nodes array');
            return array(
                'nodes' => array(),
                'edges' => array(),
                'explanation' => 'Cấu trúc workflow không hợp lệ',
            );
        }
        
        return $json;
    }
    
    private function extractJSON($text) {
        // Remove markdown code blocks first
        $patterns = array(
            '/```json\s*(.*?)\s*```/s',
            '/```\s*(.*?)\s*```/s',
        );
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $jsonText = trim($matches[1]);
                $decoded = json_decode($jsonText, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }
        }
        
        // Try direct decode
        $text = trim($text);
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        
        // Try to find JSON object in text
        if (preg_match('/(\{[\s\S]*\})/s', $text, $matches)) {
            $decoded = json_decode($matches[1], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        
        error_log('[AI Dashboard] JSON decode error: ' . json_last_error_msg() . ' | Text: ' . substr($text, 0, 500));
        
        return null;
    }
    
    private function getDefaultAnalytics() {
        return array(
            'totalExecutions' => 0,
            'successRate' => 0,
            'avgExecutionTime' => 0,
            'activeNodes' => 0,
            'recentErrors' => array(),
        );
    }
    
    private function getActiveNodeCount($workflowId) {
        if (!$workflowId) {
            return 0;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . WAIC_DB_PREF . 'workflows';
        
        $workflow = $wpdb->get_row(
            $wpdb->prepare("SELECT flow_data FROM $table WHERE id = %d", $workflowId),
            ARRAY_A
        );
        
        if (!$workflow) {
            return 0;
        }
        
        $flowData = json_decode($workflow['flow_data'], true);
        return isset($flowData['nodes']) ? count($flowData['nodes']) : 0;
    }
    
    private function describeNodes($nodes) {
        if (empty($nodes)) {
            return "Chưa có nodes nào";
        }
        
        $descriptions = array();
        foreach ($nodes as $index => $node) {
            $num = $index + 1;
            $type = $node['type'] ?? 'unknown';
            $label = $node['data']['label'] ?? 'Untitled';
            $blockId = $node['data']['blockId'] ?? '';
            
            $descriptions[] = "$num. [$type] $label ($blockId)";
        }
        
        return implode("\n", $descriptions);
    }
    
    /**
     * Get workflow templates
     */
    public function getTemplates($request) {
        $category = $request->get_param('category');
        $difficulty = $request->get_param('difficulty');
        
        // Read templates from JSON file
        $templatesFile = WAIC_PATH . 'modules/workflow/data/workflow-templates.json';
        
        if (!file_exists($templatesFile)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Template file not found',
                'templates' => array()
            ), 404);
        }
        
        $templatesJson = file_get_contents($templatesFile);
        $templatesData = json_decode($templatesJson, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Invalid template file format',
                'templates' => array()
            ), 500);
        }
        
        $templates = $templatesData['templates'] ?? array();
        
        // Filter by category if provided
        if (!empty($category)) {
            $templates = array_filter($templates, function($template) use ($category) {
                return isset($template['category']) && $template['category'] === $category;
            });
        }
        
        // Filter by difficulty if provided
        if (!empty($difficulty)) {
            $templates = array_filter($templates, function($template) use ($difficulty) {
                return isset($template['difficulty']) && $template['difficulty'] === $difficulty;
            });
        }
        
        // Re-index array after filtering
        $templates = array_values($templates);
        
        return new WP_REST_Response(array(
            'success' => true,
            'templates' => $templates,
            'total' => count($templates)
        ), 200);
    }
}

// Initialize API
new WaicWorkflowAIDashboardAPI();
