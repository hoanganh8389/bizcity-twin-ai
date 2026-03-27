<?php
/**
 * Bizcity Twin AI — WebChat AI Handler
 * Xử lý AI responses cho webchat / Process AI responses for webchat
 *
 * Integration with LLM providers (OpenAI, BizGPT, etc.)
 * Tích hợp với các nhà cung cấp LLM (OpenAI, BizGPT, ...)
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_WebChat_AI {
    
    private static $instance = null;
    
    private $api_key;
    private $model;
    private $max_tokens;
    private $temperature;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Ưu tiên dùng API key riêng của webchat, nếu không có thì dùng key chung của hệ thống
        $webchat_api_key = get_option('bizcity_webchat_openai_api_key', '');
        $this->api_key = !empty($webchat_api_key) ? $webchat_api_key : get_option('twf_openai_api_key', '');
        $this->model = get_option('bizcity_webchat_ai_model', 'gpt-4o-mini');
        $this->max_tokens = get_option('bizcity_webchat_ai_max_tokens', 1024);
        $this->temperature = get_option('bizcity_webchat_ai_temperature', 0.7);
    }
    
    /**
     * Get AI response
     * 
     * @param string $message Tin nhắn
     * @param string $session_id Session ID
     * @param string $context 'admin' hoặc 'frontend'
     */
    public function get_response($message, $session_id = '', $context = 'frontend') {
        // Check if AI is configured
        if (empty($this->api_key)) {
            return $this->get_fallback_response($message);
        }
        
        // Build system prompt based on context (admin panel vs frontend)
        $system_prompt = $this->build_system_prompt($context);
        
        // Get conversation history for context
        $history = $this->get_conversation_context($session_id);
        
        // Build messages array
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
        ];
        
        // Add history
        foreach ($history as $msg) {
            $messages[] = [
                'role' => $msg['from'] === 'user' ? 'user' : 'assistant',
                'content' => $msg['msg'],
            ];
        }
        
        // Add current message
        $messages[] = ['role' => 'user', 'content' => $message];
        
        // Call OpenAI API
        $response = $this->call_openai($messages);
        
        if (is_wp_error($response)) {
            bizcity_webchat_log('AI Error', ['error' => $response->get_error_message()], 'error');
            return $this->get_fallback_response($message);
        }
        
        return [$response];
    }
    
    /**
     * Build system prompt based on context
     * 
     * @param string $context 'admin' = admin panel context, 'frontend' = frontend context
     */
    private function build_system_prompt($context = 'frontend') {
        $site_name = get_bloginfo('name');
        $site_description = get_bloginfo('description');
        
        $base_prompt = "Bạn là trợ lý AI của {$site_name}. {$site_description}\n";
        $base_prompt .= "Hãy trả lời ngắn gọn, thân thiện và hữu ích. Sử dụng tiếng Việt.\n";
        
        if ($context === 'admin') {
            // Context: đang ở admin panel
            $base_prompt .= "\nĐây là admin panel. Bạn có thể hỗ trợ các tác vụ quản trị như:\n";
            $base_prompt .= "- Tìm kiếm và quản lý bài viết, sản phẩm\n";
            $base_prompt .= "- Xem thông tin đơn hàng\n";
            $base_prompt .= "- Thống kê doanh thu\n";
            $base_prompt .= "- Các tác vụ quản trị khác\n";
        } else {
            // Context: đang ở frontend (chủ yếu phục vụ automation triggers)
            $base_prompt .= "\nBạn đang hỗ trợ khách hàng trên website. Nhiệm vụ chính:\n";
            $base_prompt .= "- Tìm kiếm sản phẩm và giải đáp thắc mắc\n";
            $base_prompt .= "- Tra cứu đơn hàng và hỗ trợ mua hàng\n";
            $base_prompt .= "- Kích hoạt các automation workflows khi cần\n";
            $base_prompt .= "- Hướng dẫn khách hàng liên hệ CSKH nếu cần thiết\n";
        }
        
        // Add custom prompt if configured
        $custom_prompt = get_option('bizcity_webchat_custom_prompt', '');
        if (!empty($custom_prompt)) {
            $base_prompt .= "\n" . $custom_prompt;
        }
        
        return $base_prompt;
    }
    
    /**
     * Get conversation context
     */
    private function get_conversation_context($session_id, $limit = 10) {
        if (empty($session_id)) {
            return [];
        }
        
        $db = BizCity_WebChat_Database::instance();
        $history = $db->get_conversation_history($session_id, $limit);
        
        // Filter only recent messages
        return array_slice($history, -$limit);
    }
    
    /**
     * Call OpenAI API
     */
    private function call_openai($messages) {
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 60,
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('openai_error', $body['error']['message'] ?? 'Unknown error');
        }
        
        return $body['choices'][0]['message']['content'] ?? '';
    }
    
   
    /**
     * Search products
     */
    private function search_products($keyword) {
        if (!function_exists('wc_get_products')) {
            return ['Chức năng tìm kiếm sản phẩm chưa được kích hoạt.'];
        }
        
        $args = [
            'post_type' => 'product',
            's' => $keyword,
            'posts_per_page' => 3,
            'post_status' => 'publish',
        ];
        
        $query = new WP_Query($args);
        
        if (!$query->have_posts()) {
            return ["Không tìm thấy sản phẩm nào với từ khóa '{$keyword}'. Bạn thử từ khóa khác nhé!"];
        }
        
        $results = "Sản phẩm tìm thấy:\n";
        
        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;
            
            $results .= sprintf(
                "\n• **%s**\n  Giá: %s\n  [Xem chi tiết](%s)\n",
                $product->get_name(),
                $product->get_price_html(),
                get_permalink($post->ID)
            );
        }
        
        return [$results];
    }
    
    /**
     * Analyze intent from message
     */
    public function analyze_intent($message) {
        $message_lower = mb_strtolower($message, 'UTF-8');
        
        $intents = [
            'greeting' => ['xin chào', 'hello', 'hi', 'chào'],
            'product_search' => ['tìm sản phẩm', 'tìm kiếm', 'mua'],
            'order_tracking' => ['đơn hàng', 'theo dõi', 'tracking'],
            'support' => ['liên hệ', 'hỗ trợ', 'giúp đỡ'],
            'price_inquiry' => ['giá', 'bao nhiêu', 'chi phí'],
            'farewell' => ['tạm biệt', 'bye', 'cảm ơn'],
        ];
        
        foreach ($intents as $intent => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($message_lower, $keyword) !== false) {
                    return $intent;
                }
            }
        }
        
        return 'general';
    }
    
    /**
     * Generate response with tools/function calling (for advanced AI features)
     */
    public function get_response_with_tools($message, $session_id, $available_tools = []) {
        if (empty($this->api_key) || empty($available_tools)) {
            return $this->get_response($message, $session_id);
        }
        
        // Convert tools to OpenAI function format
        $functions = $this->convert_tools_to_functions($available_tools);
        
        $system_prompt = $this->build_system_prompt(true);
        
        $messages = [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => $message],
        ];
        
        $url = 'https://api.openai.com/v1/chat/completions';
        
        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'functions' => $functions,
            'function_call' => 'auto',
            'max_tokens' => $this->max_tokens,
        ];
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($body),
            'timeout' => 60,
        ]);
        
        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Check if function was called
        $choice = $body['choices'][0] ?? null;
        if ($choice && isset($choice['message']['function_call'])) {
            $function_call = $choice['message']['function_call'];
            return [
                'type' => 'function_call',
                'function' => $function_call['name'],
                'arguments' => json_decode($function_call['arguments'], true),
            ];
        }
        
        return [
            'type' => 'message',
            'content' => $choice['message']['content'] ?? '',
        ];
    }
    
    /**
     * Convert tools to OpenAI functions format
     */
    private function convert_tools_to_functions($tools) {
        $functions = [];
        
        foreach ($tools as $tool) {
            $functions[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'] ?? [
                    'type' => 'object',
                    'properties' => [],
                ],
            ];
        }
        
        return $functions;
    }
    
    /**
     * Get fallback AI response (public wrapper)
     * 
     * Hỗ trợ legacy function bizgpt_chatbot_fallback_ai_response()
     * 
     * @param string $question Câu hỏi
     * @param string $api_key API key (optional, nếu trống dùng config)
     * @return string Reply từ AI
     */
    public function get_fallback_response($question, $api_key = '') {
        // Sử dụng API key truyền vào hoặc dùng config
        $use_api_key = !empty($api_key) ? $api_key : $this->api_key;
        
        if (empty($use_api_key)) {
            // Fallback đơn giản khi không có API key
            $responses = $this->get_simple_fallback_response($question);
            return is_array($responses) ? implode("\n", $responses) : $responses;
        }
        
        // Call OpenAI trực tiếp với system prompt đơn giản
        $messages = [
            [
                'role' => 'system',
                'content' => 'Bạn là trợ lý AI trực tuyến cho khách hàng mua sắm. Hãy tư vấn tiếng Việt thân thiện, rõ ràng, ngắn gọn (tối đa 5 câu cho mỗi câu hỏi), không dùng markdown/headings, luôn hướng khách hàng quay lại đặt câu hỏi về sản phẩm, mua hàng tại shop. Không đề cập mình là AI, chỉ trả lời chuyên nghiệp.'
            ],
            [
                'role' => 'user',
                'content' => $question
            ]
        ];
        
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $use_api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4.1-nano',
                'messages' => $messages,
                'max_tokens' => 400,
                'temperature' => 0.7,
            ]),
            'timeout' => 60,
        ]);
        
        if (is_wp_error($response)) {
            return 'Xin lỗi, tôi đang tạm thời không kết nối được AI. Bạn vui lòng thử lại sau!';
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['choices'][0]['message']['content'])) {
            return trim($body['choices'][0]['message']['content']);
        }
        
        return 'Xin lỗi, tôi chưa có câu trả lời phù hợp. Bạn hãy thử lại hoặc đặt câu hỏi khác!';
    }
    
    /**
     * Simple fallback response (no API)
     */
    private function get_simple_fallback_response($message) {
        $message_lower = mb_strtolower($message, 'UTF-8');
        
        // Greeting
        if (preg_match('/(xin chào|hello|hi|chào)/i', $message_lower)) {
            return ['Xin chào! Tôi có thể giúp gì cho bạn?'];
        }
        
        // Product search
        if (strpos($message_lower, 'tìm') !== false || strpos($message_lower, 'sản phẩm') !== false) {
            return ['Bạn muốn tìm sản phẩm gì? Hãy cho tôi biết tên hoặc từ khóa.'];
        }
        
        // Order
        if (strpos($message_lower, 'đơn hàng') !== false) {
            return ['Để tra cứu đơn hàng, vui lòng cung cấp mã đơn hàng hoặc số điện thoại đặt hàng.'];
        }
        
        // Default
        return ['Cảm ơn bạn đã liên hệ. Bạn có thể yêu cầu: "tìm sản phẩm...", "theo dõi đơn hàng", hoặc "liên hệ hỗ trợ".'];
    }
}
