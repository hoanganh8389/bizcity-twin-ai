<?php
/**
 * Bizcity Twin AI — WebChat Trigger Handler
 * Xử lý tin nhắn web chat & trigger workflow / Process web chat messages & trigger workflows
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

if (!class_exists('BizCity_WebChat_Trigger')) {

class BizCity_WebChat_Trigger {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Process webhook từ external source
     */
    public function process_webhook($data) {
        $platform_type = $data['platform_type'] ?? 'WEBCHAT';
        $event = $data['event'] ?? '';
        $session_id = $data['session_id'] ?? '';
        $user_id = $data['user_id'] ?? 0;
        $message = $data['message'] ?? [];
        $client_name = $data['client_name'] ?? 'Guest';
        
        // Chỉ xử lý event message.create
        if ($event !== 'message.create') {
            return ['status' => 'ignored', 'reason' => 'Event not supported'];
        }
        
        $message_id = $message['message_id'] ?? '';
        $message_text = sanitize_text_field($message['text'] ?? '');
        $attachments = $message['attachments'] ?? [];
        
        // Lock để tránh xử lý trùng
        if (!empty($message_id)) {
            $lock_key = 'bizcity_webchat_lock_' . $message_id;
            if (get_transient($lock_key)) {
                return ['status' => 'skipped', 'reason' => 'Duplicate message'];
            }
            set_transient($lock_key, true, 120);
        }
        
        return $this->process_message($data);
    }
    
    /**
     * Process message (từ AJAX hoặc webhook)
     */
    public function process_message($data) {
        $platform_type = $data['platform_type'] ?? 'WEBCHAT';
        $session_id = $data['session_id'] ?? '';
        $user_id = $data['user_id'] ?? 0;
        $client_name = $data['client_name'] ?? 'Guest';
        $message = $data['message'] ?? [];
        
        $message_id = $message['message_id'] ?? uniqid('wcm_');
        $message_text = sanitize_text_field($message['text'] ?? '');
        $attachments = $message['attachments'] ?? [];
        
        // Log message vào database
        $db = BizCity_WebChat_Database::instance();
        $conversation_id = $db->log_message([
            'session_id' => $session_id,
            'user_id' => $user_id,
            'client_name' => $client_name,
            'message_id' => $message_id,
            'message_text' => $message_text,
            'message_from' => 'user',
            'attachments' => $attachments,
            'platform_type' => $platform_type,
        ]);
        
        // Build trigger payload (tương thích với WAIC triggers)
        $twf_trigger = $this->build_trigger_payload($data, $message_text, $attachments);
        
        // Check Human-in-the-Loop (HIL)
        $chat_id = 'webchat_' . $session_id;
        $hil_handled = $this->check_hil($chat_id, $message_text);
        
        if ($hil_handled) {
            return [
                'status' => 'hil_handled',
                'message' => 'Đang chờ xác nhận từ hệ thống...',
            ];
        }
        
        // 1. Fire WAIC workflow trigger TRƯỚC để automation có cơ hội xử lý
        $workflow_handled = $this->fire_workflow_trigger($twf_trigger, $data);
        
        // 2. Nếu workflow đã xử lý, không cần chạy bizgpt_chatbot_run_guest_flows
        if ($workflow_handled) {
            // Lấy replies từ workflow (đã được log trong fire_workflow_trigger)
            $replies = apply_filters('bizcity_webchat_workflow_replies', [], $session_id, $message_text);
            
            return [
                'status' => 'workflow_handled',
                'replies' => $replies,
                'conversation_id' => $conversation_id,
                'task_id' => $twf_trigger['task_id'] ?? '',
            ];
        }
        
        // 3. Nếu không có workflow nào xử lý, chạy bizgpt_chatbot_run_guest_flows
        #// Phân loại theo context: admin panel hay frontend
        #$is_admin_context = is_admin();
        
        #if ($is_admin_context) {
            // Đang ở admin panel: chạy admin flows
        #    $replies = $this->run_admin_flows($message_text, $session_id, $twf_trigger);
        #} else {
            // Đang ở frontend: chạy guest flows với bizgpt_chatbot_run_guest_flows
            $replies = $this->run_guest_flows($message_text, $session_id, $twf_trigger);
        #}
        
        // Log bot replies
        foreach ((array)$replies as $reply) {
            $db->log_message([
                'session_id' => $session_id,
                'user_id' => 0,
                'client_name' => 'BizChat Bot',
                'message_id' => uniqid('bcm_'),
                'message_text' => $reply,
                'message_from' => 'bot',
                'attachments' => [],
                'platform_type' => $platform_type,
            ]);
        }
        
        return [
            'status' => 'success',
            'replies' => $replies,
            'conversation_id' => $conversation_id,
            'task_id' => $twf_trigger['task_id'] ?? '',
        ];
    }
    
    /**
     * Build trigger payload tương thích với WAIC
     */
    private function build_trigger_payload($data, $message_text, $attachments) {
        $session_id = $data['session_id'] ?? '';
        $user_id = $data['user_id'] ?? 0;
        
        // Phân loại attachment
        $attachment_url = '';
        $attachment_type = '';
        $image_url = '';
        $audio_url = '';
        
        if (!empty($attachments) && is_array($attachments)) {
            $first_attachment = $attachments[0] ?? [];
            
            // Handle both URL and base64 data formats
            if (isset($first_attachment['url'])) {
                // Traditional URL format
                $attachment_url = $first_attachment['url'];
            } elseif (isset($first_attachment['data'])) {
                // New base64 data format from image preview workflow
                $attachment_url = $first_attachment['data'];
            }
            
            // Determine attachment type
            if (isset($first_attachment['type'])) {
                // Explicit type from new format
                $attachment_type = $first_attachment['type'];
            } else {
                // Classify by URL/data for legacy format
                $attachment_type = $this->classify_attachment($attachment_url);
            }
            
            if ($attachment_type === 'image') {
                $image_url = $attachment_url;
            } elseif ($attachment_type === 'audio') {
                $audio_url = $attachment_url;
            }
        }
        
        $task_id = uniqid('task_');
        
        return [
            'platform' => 'webchat',
            'client_id' => $session_id,
            'chat_id' => $session_id,
            'user_id' => $user_id,
            'text' => $message_text,
            'raw' => $data,
            'attachment_url' => $attachment_url,
            'attachment_type' => $attachment_type,
            'image_url' => $image_url,
            'audio_url' => $audio_url,
            'twf_platform' => 'webchat',
            'twf_client_id' => $session_id,
            'twf_chat_id' => $session_id,
            'twf_text' => $message_text,
            'message_id' => $data['message']['message_id'] ?? '',
            'task_id' => $task_id,
        ];
    }
    
    /**
     * Check Human-in-the-Loop
     */
    private function check_hil($chat_id, $message_text) {
        if (function_exists('waic_hil_maybe_handle_incoming')) {
            return waic_hil_maybe_handle_incoming($chat_id, $message_text, get_current_blog_id());
        }
        return false;
    }
    
    /**
     * Run admin flows (khi ở admin panel context)
     */
    private function run_admin_flows($message_text, $session_id, $twf_trigger) {
        // Sử dụng function có sẵn từ bizgpt-agent nếu có
        if (function_exists('bizgpt_chatbot_run_admin_flows')) {
            return (array) bizgpt_chatbot_run_admin_flows($message_text, $session_id, 'webchat');
        }
        
        // Fallback: AI response với admin context
        return $this->get_ai_response($message_text, $session_id, 'admin');
    }
    

    
    /**
     * Get AI response
     * 
     * @param string $message_text Tin nhắn
     * @param string $session_id Session ID
     * @param string $context 'admin' hoặc 'frontend'
     */
    private function get_ai_response($message_text, $session_id, $context = 'frontend') {
        $ai = BizCity_WebChat_AI::instance();
        return $ai->get_response($message_text, $session_id, $context);
    }
    
    /**
     * Fire WAIC workflow trigger
     * 
     * @return bool True nếu có workflow đã xử lý message, false nếu không
     */
    private function fire_workflow_trigger($twf_trigger, $raw_data) {
        // Set transient để các hàm khác có thể sử dụng
        set_transient('hook_data', [
            'user_id'     => $twf_trigger['user_id'] ?? 0,
            'client_id'   => $twf_trigger['client_id'] ?? '',
            'session_id'  => $twf_trigger['session_id'] ?? $twf_trigger['chat_id'] ?? '',
            'page_id'     => '',
            'platform'    => 'webchat',
            'client_name' => $raw_data['client_name'] ?? 'Guest',
        ], 10 * MINUTE_IN_SECONDS);
        
        // Flag để kiểm tra xem có workflow nào đã xử lý chưa
        $handled = false;
        
        // Filter cho phép workflow báo hiệu đã xử lý message
        // Workflow có thể hook vào filter này và return true nếu đã xử lý
        $handled = apply_filters('bizcity_webchat_workflow_handle_message', $handled, $twf_trigger, $raw_data);
        
        // Fire WAIC hooks — $args[0] PHẢI là trigger array để wu_gateway/wu_twf bắt được
        do_action('waic_twf_process_flow', $twf_trigger, is_array($raw_data) ? $raw_data : []);
        do_action('waic_twf_process_flow_webchat', $twf_trigger, $raw_data);
        
        // Fire hook để WAIC có thể bắt (boot hooked flows per-blog)
        if (function_exists('bizcity_aiwu_fire_twf_process_flow')) {
            bizcity_aiwu_fire_twf_process_flow($twf_trigger, is_array($raw_data) ? $raw_data : []);
        }
        
        // Fire custom hook cho webchat
        do_action('bizcity_webchat_message_received', $twf_trigger, $raw_data);
        
        // Fire image-specific hook nếu có image
        if (!empty($twf_trigger['image_url'])) {
            do_action('bizcity_webchat_image_received', $twf_trigger, $raw_data);
        }
        
        // Send to admin Telegram (nếu được cấu hình)
        $this->notify_admin_telegram($twf_trigger, $raw_data);
        
        return $handled;
    }
    
    /**
     * Gửi thông báo đến admin qua Telegram
     */
    private function notify_admin_telegram($twf_trigger, $raw_data) {
        if (!function_exists('twf_telegram_send_message')) return;
        if (!function_exists('twf_list_client_ids_by_blog_id')) return;
        
        $chat_ids = twf_list_client_ids_by_blog_id(get_current_blog_id());
        if (empty($chat_ids)) return;
        
        $client_name = $raw_data['client_name'] ?? 'Guest';
        $message_text = $twf_trigger['text'] ?? '';
        $session_id = $twf_trigger['session_id'] ?? $twf_trigger['chat_id'] ?? '';
        $user_id = $twf_trigger['user_id'] ?? 0;
        $blog_name = get_bloginfo('name');
        $blog_domain = parse_url(home_url(), PHP_URL_HOST);
        
        $msg = "💬 <b>Khách từ webchat: </b><code>$blog_name - $blog_domain</code> \n"
             . "🗨️ <b>Khách nhắn:</b> $message_text\n\n"
             . "👤 <b>user_id:</b> <code>$user_id</code>\n"
             . "👤 <b>Tên khách:</b> <code>$client_name</code>\n"
             . "🔑 <b>Mã id phiên:</b> <code>$session_id</code>\n"
             . "📩 <b>Hướng dẫn:</b>\n"
             . "Nhắn: <code>Trả lời tới khách session_id: $session_id nội dung là: ...</code>\n"
             . "Bot sẽ gửi tin nhắn cho khách giúp bạn 🧠";
        
        foreach ($chat_ids as $chat_id) {
            twf_telegram_send_message($chat_id, $msg, 'HTML');
        }
    }
    
    /**
     * Classify attachment type
     */
    private function classify_attachment($url) {
        if (empty($url)) return '';
        
        $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        
        $audio_exts = ['aac', 'm4a', 'mp3', 'wav', 'ogg', 'oga', 'webm'];
        $image_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $video_exts = ['mp4', 'mov', 'avi', 'wmv'];
        $doc_exts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        
        if (in_array($extension, $audio_exts)) return 'audio';
        if (in_array($extension, $image_exts)) return 'image';
        if (in_array($extension, $video_exts)) return 'video';
        if (in_array($extension, $doc_exts)) return 'document';
        
        return 'unknown';
    }
    
    /**
     * Send message to user (callback từ workflow)
     */
    public function send_message($session_id, $message, $options = []) {
        // Log message
        $db = BizCity_WebChat_Database::instance();
        $db->log_message([
            'session_id' => $session_id,
            'user_id' => 0,
            'client_name' => 'BizChat Bot',
            'message_id' => uniqid('bcm_'),
            'message_text' => $message,
            'message_from' => 'bot',
            'attachments' => $options['attachments'] ?? [],
            'platform_type' => 'WEBCHAT',
        ]);
        
        // Cũng log vào bảng bizgpt_chat_history nếu có (để tương thích ngược)
        if (function_exists('bizgpt_log_chat_message')) {
            bizgpt_log_chat_message(0, $message, 'bot', $session_id);
        }
        
        // Push to realtime if available (WebSocket, SSE, etc.)
        do_action('bizcity_webchat_push_message', $session_id, $message, $options);
        
        return true;
    }
    
    /**
     * Run guest flows with image support
     */
    private function run_guest_flows($message_text, $session_id, $twf_trigger) {
        // Try bizcity-knowledge first (supports image data)
        if (function_exists('bizcity_knowledge_chat')) {
            $character_id = 0; // Default character
            $image_data = '';
            
            // Extract image data if available
            if (!empty($twf_trigger['image_url'])) {
                $image_data = $twf_trigger['image_url'];
            }
            
            // Call bizcity_knowledge_chat with image support
            $reply = bizcity_knowledge_chat($message_text, $character_id, $session_id, $image_data);
            
            return is_array($reply) ? [$reply] : (empty($reply) ? ['Xin lỗi, tôi chưa hiểu câu hỏi của bạn.'] : [$reply]);
        }
        
        // Check legacy function for backward compatibility
        if (function_exists('bizgpt_chatbot_run_guest_flows')) {
            // Pass image data to the AI system if available
            if (!empty($twf_trigger['image_url'])) {
                $image_data = $twf_trigger['image_url'];
                
                // If it's base64 data, we may need to process it differently
                if (strpos($image_data, 'data:image/') === 0) {
                    // It's base64 - may need to save to temp file or handle directly
                    $enhanced_message = $message_text . "\n[Có kèm hình ảnh]";
                } else {
                    // It's URL
                    $enhanced_message = $message_text . "\n[Có kèm hình ảnh: " . $image_data . "]";
                }
            } else {
                $enhanced_message = $message_text;
            }
            
            // Call the guest flows function with enhanced message
            return bizgpt_chatbot_run_guest_flows($enhanced_message, $session_id, $twf_trigger);
        }
        
        // Fallback if no AI function available
        return ['Xin lỗi, tôi chưa hiểu câu hỏi của bạn.'];
    }
}

} // End class_exists check
