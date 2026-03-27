<?php
/**
 * Bizcity Twin AI — WebChat Helper Functions
 * Hàm hỗ trợ WebChat / WebChat utility functions
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

/**
 * Get webchat session ID
 */
function bizcity_webchat_get_session_id() {
    if (!session_id() && session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return session_id();
}

/**
 * Get webchat identity (user or guest)
 */
function bizcity_webchat_get_identity() {
    return [
        'user_id' => get_current_user_id(),
        'session_id' => bizcity_webchat_get_session_id(),
        'is_logged_in' => is_user_logged_in(),
        'display_name' => is_user_logged_in() ? wp_get_current_user()->display_name : 'Guest',
    ];
}

/**
 * Send message from bot to user
 */
function bizcity_webchat_send_bot_message($session_id, $message, $options = []) {
    return BizCity_WebChat_Trigger::instance()->send_message($session_id, $message, $options);
}

/**
 * Log webchat event
 */
function bizcity_webchat_log($message, $context = [], $level = 'info') {
    $log_file = BIZCITY_WEBCHAT_LOGS . 'webchat-' . date('Y-m-d') . '.log';
    
    if (!file_exists(dirname($log_file))) {
        wp_mkdir_p(dirname($log_file));
    }
    
    $entry = sprintf(
        "[%s] [%s] %s %s\n",
        gmdate('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );
    
    file_put_contents($log_file, $entry, FILE_APPEND);
}

/**
 * Start a new task timeline
 */
function bizcity_webchat_start_task($data) {
    return BizCity_WebChat_Timeline::instance()->start_task($data);
}

/**
 * Complete a task
 */
function bizcity_webchat_complete_task($task_id, $summary = []) {
    return BizCity_WebChat_Timeline::instance()->complete_task($task_id, $summary);
}

/**
 * Add step to task timeline
 */
function bizcity_webchat_add_task_step($task_id, $step_data) {
    return BizCity_WebChat_Timeline::instance()->add_step($task_id, $step_data);
}

/**
 * Get conversation history
 */
function bizcity_webchat_get_history($session_id, $limit = 50) {
    return BizCity_WebChat_Database::instance()->get_conversation_history($session_id, $limit);
}

/**
 * Check if webchat is enabled
 */
function bizcity_webchat_is_enabled() {
    return get_option('bizcity_webchat_widget_enabled', true);
}

/**
 * Get webchat settings
 */
function bizcity_webchat_get_settings() {
    return [
        'widget_enabled' => get_option('bizcity_webchat_widget_enabled', true),
        'bot_name' => get_option('bizcity_webchat_bot_name', 'BizChat AI'),
        'bot_avatar' => get_option('bizcity_webchat_bot_avatar', ''),
        'welcome_message' => get_option('bizcity_webchat_welcome', 'Xin chào! Tôi có thể giúp gì cho bạn?'),
        'primary_color' => get_option('bizcity_webchat_primary_color', '#3182f6'),
        'position' => get_option('bizcity_webchat_widget_position', 'bottom-right'),
    ];
}

/**
 * Update webchat settings
 */
function bizcity_webchat_update_settings($settings) {
    foreach ($settings as $key => $value) {
        update_option('bizcity_webchat_' . $key, $value);
    }
    return true;
}

/**
 * Format message for display (with markdown, links, etc.)
 */
function bizcity_webchat_format_message($message) {
    return BizCity_WebChat_Widget::instance()->format_message($message);
}

/**
 * Check if current request is from webchat
 */
function bizcity_is_webchat_request() {
    return (
        isset($_POST['action']) && 
        strpos($_POST['action'], 'bizcity_webchat') === 0
    );
}

/**
 * Get webchat client info
 */
function bizcity_webchat_get_client_info() {
    return [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'referer' => $_SERVER['HTTP_REFERER'] ?? '',
        'page_url' => home_url(add_query_arg(null, null)),
    ];
}

/**
 * Hook: Fire WAIC workflow trigger for webchat
 */
function bizcity_webchat_fire_workflow($trigger_data, $raw_data = []) {
    // Fire general trigger
    do_action('waic_twf_process_flow', $trigger_data, $raw_data);
    
    // Fire webchat-specific trigger
    do_action('waic_twf_process_flow_webchat', $trigger_data, $raw_data);
    
    // Log
    bizcity_webchat_log('Workflow triggered', [
        'platform' => 'webchat',
        'session_id' => $trigger_data['session_id'] ?? '',
        'text' => substr($trigger_data['text'] ?? '', 0, 100),
    ]);
}

/**
 * Integration: Send notification via Telegram
 */
function bizcity_webchat_notify_telegram($message, $chat_id = null) {
    if (function_exists('twf_telegram_send_message')) {
        $chat_id = $chat_id ?: get_option('bizcity_webchat_telegram_chat_id');
        if ($chat_id) {
            return twf_telegram_send_message($chat_id, $message);
        }
    }
    return false;
}

/**
 * Integration: Get AI response
 */
function bizcity_webchat_get_ai_response($message, $context = []) {
    $ai = BizCity_WebChat_AI::instance();
    return $ai->get_response($message, $context['session_id'] ?? '', $context['is_admin'] ?? false);
}

// =====================================================
// Legacy bizgpt-agent compatibility functions
// Các hàm này giữ lại để backward compatibility
// nhưng return empty/fallback vì không còn dùng SQL table cũ
// =====================================================

/**
 * bizgpt_log_chat_message() - Legacy fallback
 * 
 * Hàm này được giữ lại để backward compatibility với các plugin khác
 * như bizcity-admin-hook, bizcity-admin-hook-zalo
 * 
 * @deprecated Sử dụng BizCity_WebChat_Database::log_message() thay thế
 * @return array|false Empty array (fallback)
 */
if (!function_exists('bizgpt_log_chat_message')) :
function bizgpt_log_chat_message($user_id, $msg_text, $from = 'user', $session_id = false, $msg_type = 'web') {
    // Return fallback empty - không còn dùng SQL bảng cũ
    // Nếu cần log, sử dụng BizCity_WebChat_Database thay thế
    return [
        'id' => 0,
        'msg' => $msg_text,
        'from' => $from,
        'time' => current_time('mysql'),
        'user_id' => (int) $user_id,
        'session_id' => $session_id ?: '',
        '_deprecated' => true,
    ];
}
endif;

/**
 * bizgpt_send_chat_message() - Legacy fallback
 * 
 * @deprecated Sử dụng bizcity_webchat_send_bot_message() thay thế
 * @return string Content được truyền vào
 */
if (!function_exists('bizgpt_send_chat_message')) :
function bizgpt_send_chat_message($type, $content, $user_id = null, $session_id = null, $meta = []) {
    // Return content - không còn lưu vào SQL bảng cũ
    return $content;
}
endif;

/**
 * bizgpt_webchat_send_message() - Legacy fallback
 * 
 * @deprecated Sử dụng bizcity_webchat_send_bot_message() thay thế
 * @return string Message được truyền vào
 */
if (!function_exists('bizgpt_webchat_send_message')) :
function bizgpt_webchat_send_message($message, $user_id = null, $session_id = null, $type = 'text', $meta = []) {
    // Return message - không còn lưu vào SQL bảng cũ
    return $message;
}
endif;

/**
 * bizgpt_chatbot_fallback_ai_response() - Legacy fallback
 * 
 * Gọi OpenAI để lấy câu trả lời fallback
 * Hàm này vẫn hoạt động, chuyển sang sử dụng BizCity_WebChat_AI
 * 
 * @param string $api_key OpenAI API key (nếu trống sẽ dùng option)
 * @param string $question Câu hỏi
 * @return string Reply từ AI
 */
if (!function_exists('bizgpt_chatbot_fallback_ai_response')) :
function bizgpt_chatbot_fallback_ai_response($api_key, $question) {
    // Dùng BizCity_WebChat_AI để gọi OpenAI
    $ai = BizCity_WebChat_AI::instance();
    return $ai->get_fallback_response($question, $api_key);
}
endif;
