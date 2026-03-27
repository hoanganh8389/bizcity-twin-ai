<?php
/**
 * Bizcity Twin AI — Chatbot Shortcode Handler
 * Xử lý shortcode [chatbot] / Handle [chatbot] shortcode for chat interface
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

defined('ABSPATH') or die('OOPS...');

/**
 * Shortcode [chatbot] - Hiển thị chat interface
 */
function bizcity_webchat_chatbot_shortcode($atts = [], $content = null, $tag = '') {
    
    ob_start();
    
    global $session_id, $user_id;
    
    // Get current user
    $user_id = get_current_user_id();
    
    // Get unique session ID
    $session_id = isset($_COOKIE['bizcity_session_id']) ? sanitize_text_field($_COOKIE['bizcity_session_id']) : '';
    if (empty($session_id)) {
        $session_id = 'sess_' . wp_generate_uuid4();
        setcookie('bizcity_session_id', $session_id, time() + (86400 * 30), '/');
    }
    
    // Parse shortcode attributes
    $atts = shortcode_atts([
        'style' => 'embedded',      // embedded, floating, popup
        'character_id' => '',       // ID của character từ bizcity-knowledge
        'show' => 'active',         // all, active, published - lọc characters trong sidebar
        'height' => '600px',
        'width' => '100%',
        'position' => 'bottom-right' // for floating style
    ], $atts, $tag);
    
    $style = sanitize_text_field($atts['style']);
    $character_id = intval($atts['character_id']);
    $show = sanitize_text_field($atts['show']);
    
    // Styles và scripts đã được enqueue trong bootstrap
    // Chỉ cần đảm bảo chúng có sẵn
    if (!wp_style_is('bizcity-webchat-chatbot', 'enqueued')) {
        wp_enqueue_style('bizcity-webchat-chatbot');
    }
    if (!wp_script_is('bizcity-webchat-widget', 'enqueued')) {
        wp_enqueue_script('bizcity-webchat-widget');
    }
    
    // Render chat interface based on style
    if ($style === 'floating') {
        return render_floating_chat($character_id, $atts);
    } elseif ($style === 'popup') {
        return render_popup_chat($character_id, $atts);
    } else {
        return render_embedded_chat($character_id, $atts);
    }
    
    return ob_get_clean();
}

/**
 * Render Embedded Chat (hiển thị ngay trong trang)
 * ChatGPT-style UI với sidebar characters & conversations
 */
function render_embedded_chat($character_id, $atts) {
    // Load character data
    if (class_exists('BizCity_Knowledge_Database')) {
        $db = BizCity_Knowledge_Database::instance();
        $character = $character_id ? $db->get_character($character_id) : null;
        
        // Lọc characters theo parameter 'show'
        $show = isset($atts['show']) ? $atts['show'] : 'active';
        $query_args = ['limit' => 100];
        
        if ($show === 'all') {
            // Không filter status - lấy tất cả
        } elseif ($show === 'published') {
            $query_args['status'] = 'published';
        } else {
            $query_args['status'] = 'active';
        }
        
        $characters = $db->get_characters($query_args);
        
        // If no character selected, use first active one
        if (!$character && !empty($characters)) {
            $character = $characters[0];
            $character_id = $character->id;
        }
    } else {
        $character = null;
        $characters = [];
    }
    
    // Get greeting message
    $greeting_messages = [];
    if ($character && !empty($character->greeting_messages)) {
        $greeting_messages = json_decode($character->greeting_messages, true) ?: [];
    }
    $random_greeting = !empty($greeting_messages) ? $greeting_messages[array_rand($greeting_messages)] : 'Xin chào! Tôi có thể giúp gì cho bạn?';
    
    $height = esc_attr($atts['height']);
    $width = esc_attr($atts['width']);
    
    // Get session ID
    global $session_id;
    $current_session = $session_id ?? ($_COOKIE['bizcity_session_id'] ?? '');
    
    ob_start();
    ?>
    <style>
        /* ========================================
         * ChatGPT-Style UI - BizCity WebChat
         * Modern, clean interface inspired by ChatGPT/Claude
         * ======================================== */
        
        /* Reset và base styles */
        .bk-chat-wrap * {
            box-sizing: border-box;
        }
        
        /* Override lazy load for all images - CRITICAL */
        .bk-chat-wrap img {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        
        /* Main Container */
        .bk-chat-wrap {
            display: flex;
            height: <?php echo $height; ?>;
            width: <?php echo $width; ?>;
            background: #f7f7f8;
            border-radius: 16px;
            overflow: hidden;
            margin: 20px 0;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        /* ========== SIDEBAR ========== */
        .bk-chat-sidebar {
            width: 280px;
            background: linear-gradient(180deg, #1a1a2e 0%, #16162a 100%);
            display: flex;
            flex-direction: column;
            border-radius: 16px 0 0 16px;
        }

        /* New Chat Button */
        .bk-new-chat-btn {
            margin: 16px;
            padding: 14px 20px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            border-radius: 12px;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s ease;
        }

        .bk-new-chat-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.4);
        }

        .bk-new-chat-btn svg {
            width: 18px;
            height: 18px;
        }

        /* Characters Section */
        .bk-sidebar-section {
            padding: 0 12px;
            margin-bottom: 8px;
        }

        .bk-section-header {
            padding: 12px;
            font-size: 11px;
            font-weight: 600;
            color: #8b8b9e;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bk-section-clear {
            font-size: 11px;
            color: #ef4444;
            cursor: pointer;
            text-transform: none;
            font-weight: 500;
        }

        .bk-section-clear:hover {
            text-decoration: underline;
        }

        .bk-characters-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .bk-character-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            margin-bottom: 4px;
        }

        .bk-character-item:hover {
            background: rgba(255,255,255,0.08);
        }

        .bk-character-item.active {
            background: rgba(99, 102, 241, 0.2);
            border: 1px solid rgba(99, 102, 241, 0.3);
        }

        .bk-char-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            overflow: hidden;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .bk-char-avatar img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }

        .bk-char-placeholder {
            font-size: 18px;
        }

        .bk-char-info {
            flex: 1;
            min-width: 0;
        }

        .bk-char-name {
            font-weight: 600;
            color: #fff;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bk-char-model {
            font-size: 11px;
            color: #8b8b9e;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bk-char-active-dot {
            width: 8px;
            height: 8px;
            background: #10b981;
            border-radius: 50%;
            flex-shrink: 0;
            box-shadow: 0 0 8px #10b981;
        }

        /* Conversations/History Section */
        .bk-conversations-section {
            flex: 1;
            overflow-y: auto;
            padding: 0 12px;
        }

        .bk-conversation-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            margin-bottom: 2px;
            color: #d1d1db;
        }

        .bk-conversation-item:hover {
            background: rgba(255,255,255,0.06);
        }

        .bk-conversation-item.active {
            background: rgba(255,255,255,0.1);
        }

        .bk-conversation-item svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
            opacity: 0.7;
        }

        .bk-conversation-title {
            flex: 1;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .bk-conversation-actions {
            display: none;
            gap: 4px;
        }

        .bk-conversation-item:hover .bk-conversation-actions {
            display: flex;
        }

        .bk-conv-action-btn {
            width: 24px;
            height: 24px;
            border: none;
            background: transparent;
            color: #8b8b9e;
            cursor: pointer;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }

        .bk-conv-action-btn:hover {
            background: rgba(255,255,255,0.1);
            color: #fff;
        }

        /* Sidebar Footer */
        .bk-sidebar-footer {
            padding: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }

        .bk-settings-btn {
            width: 100%;
            padding: 10px 12px;
            background: transparent;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            color: #d1d1db;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            transition: all 0.15s ease;
        }

        .bk-settings-btn:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.2);
        }

        /* ========== MAIN CHAT AREA ========== */
        .bk-chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 0 16px 16px 0;
        }

        /* Chat Header */
        .bk-chat-header {
            padding: 16px 24px;
            background: #fff;
            border-bottom: 1px solid #e5e5e5;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .bk-chat-header-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .bk-chat-avatar {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            overflow: hidden;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .bk-chat-avatar img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }

        .bk-chat-header-info h2 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #1a1a1a;
        }

        .bk-chat-meta {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 4px;
        }

        .bk-model-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 6px;
            background: #e0e7ff;
            color: #4338ca;
            font-weight: 500;
        }

        .bk-status-badge {
            font-size: 11px;
            color: #10b981;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .bk-status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            background: #10b981;
            border-radius: 50%;
        }

        .bk-chat-header-actions {
            display: flex;
            gap: 8px;
        }

        .bk-header-btn {
            width: 36px;
            height: 36px;
            border: 1px solid #e5e5e5;
            background: #fff;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #666;
            transition: all 0.15s ease;
            padding: 0;
        }

        .bk-header-btn:hover {
            background: #f5f5f5;
            border-color: #d1d1d1;
        }

        /* ========== MESSAGES AREA ========== */
        .bk-chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 24px;
            background: #fafafa;
        }

        .bk-message {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            animation: fadeInUp 0.3s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .bk-message-user {
            flex-direction: row-reverse;
        }

        .bk-message-avatar {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            overflow: hidden;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 16px;
            color: #fff;
        }

        .bk-message-avatar img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }

        .bk-message-user .bk-message-avatar {
            background: linear-gradient(135deg, #f59e0b, #ef4444);
        }

        .bk-message-content {
            max-width: 75%;
            min-width: 60px;
        }

        .bk-message-bubble {
            padding: 14px 18px;
            border-radius: 16px;
            line-height: 1.6;
            font-size: 14px;
            word-wrap: break-word;
        }

        .bk-message-assistant .bk-message-bubble {
            background: #fff;
            color: #1a1a1a;
            border: 1px solid #e5e5e5;
            border-radius: 16px 16px 16px 4px;
        }

        .bk-message-user .bk-message-bubble {
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: #fff;
            border-radius: 16px 16px 4px 16px;
        }

        /* Markdown rendered content */
        .bk-md { white-space: normal; }
        .bk-md h2, .bk-md h3, .bk-md h4 { line-height: 1.3; }
        .bk-md h2:first-child, .bk-md h3:first-child, .bk-md h4:first-child { margin-top: 0; }
        .bk-md strong { font-weight: 700; }
        .bk-md em { font-style: italic; }
        .bk-md ul, .bk-md ol { line-height: 1.6; }
        .bk-md li { margin-bottom: 2px; }
        .bk-md pre { white-space: pre-wrap; word-break: break-word; }
        .bk-md pre code { background: none; padding: 0; }
        .bk-md code { font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; }
        .bk-md a { color: #6366f1; text-decoration: underline; }
        .bk-md img { max-width: 100%; border-radius: 8px; }
        .bk-md table { border-collapse: collapse; width: 100%; margin: 6px 0; font-size: 13px; }
        .bk-md th, .bk-md td { border: 1px solid #e5e5e5; padding: 6px 10px; text-align: left; }
        .bk-md th { background: #f9fafb; font-weight: 600; }

        .bk-message-time {
            font-size: 11px;
            color: #999;
            padding: 6px 4px 0;
        }

        .bk-message-user .bk-message-time {
            text-align: right;
        }

        /* Message Images - CRITICAL FIX */
        .bk-message-images {
            display: flex !important;
            flex-wrap: wrap !important;
            gap: 8px !important;
            margin-bottom: 10px !important;
        }

        .bk-message-image {
            display: block !important;
            max-width: 240px !important;
            max-height: 180px !important;
            width: auto !important;
            height: auto !important;
            border-radius: 12px !important;
            object-fit: cover !important;
            visibility: visible !important;
            opacity: 1 !important;
            cursor: pointer;
            transition: transform 0.2s ease;
        }

        .bk-message-image:hover {
            transform: scale(1.02);
        }

        /* Override lazy load plugins */
        .bk-chat-wrap .bk-message-image.lazy,
        .bk-chat-wrap .bk-message-image.lazyload,
        .bk-chat-wrap .bk-message-image[data-src] {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }

        /* Typing Indicator */
        .bk-typing-indicator {
            display: flex;
            gap: 6px;
            padding: 8px 0;
        }

        .bk-typing-dot {
            width: 8px;
            height: 8px;
            background: #6366f1;
            border-radius: 50%;
            animation: typingBounce 1.4s infinite ease-in-out;
        }

        .bk-typing-dot:nth-child(1) { animation-delay: 0s; }
        .bk-typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .bk-typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typingBounce {
            0%, 60%, 100% {
                transform: translateY(0);
                opacity: 0.4;
            }
            30% {
                transform: translateY(-8px);
                opacity: 1;
            }
        }

        /* ========== IMAGE PREVIEW AREA ========== */
        .bk-image-preview-area {
            padding: 12px 24px;
            background: #f5f5f5;
            border-top: 1px solid #e5e5e5;
            display: none;
        }

        .bk-image-preview-area.active {
            display: block;
        }

        .bk-image-previews {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .bk-image-preview-item {
            position: relative;
            width: 72px;
            height: 72px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #e5e5e5;
            background: #fff;
        }

        .bk-image-preview-item img {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
        }

        .bk-remove-image {
            position: absolute;
            top: -6px;
            right: -6px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #1a1a1a;
            color: #fff;
            border: 2px solid #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            padding: 0;
            transition: all 0.15s ease;
        }

        .bk-remove-image:hover {
            background: #ef4444;
            transform: scale(1.1);
        }

        /* ========== INPUT AREA ========== */
        .bk-chat-input-wrap {
            padding: 16px 24px 24px;
            background: #fff;
            border-top: 1px solid #e5e5e5;
        }

        .bk-input-container {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            background: #f5f5f5;
            border: 2px solid #e5e5e5;
            border-radius: 16px;
            padding: 6px 8px 6px 16px;
            transition: all 0.2s ease;
        }

        .bk-input-container:focus-within {
            border-color: #6366f1;
            background: #fff;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .bk-input-actions {
            display: flex;
            gap: 4px;
            align-items: center;
        }

        .bk-input-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: transparent;
            border: none;
            color: #666;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s ease;
            padding: 0;
        }

        .bk-input-btn:hover {
            background: #e5e5e5;
            color: #333;
        }

        .bk-input-btn svg {
            width: 20px;
            height: 20px;
        }

        #chat-input {
            flex: 1;
            border: none;
            outline: none;
            background: transparent;
            resize: none;
            font-size: 14px;
            line-height: 1.5;
            font-family: inherit;
            max-height: 150px;
            min-height: 24px;
            padding: 10px 0;
            color: #1a1a1a;
        }

        #chat-input::placeholder {
            color: #999;
        }

        .bk-send-btn {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            border: none;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
            padding: 0;
        }

        .bk-send-btn:hover:not(:disabled) {
            transform: scale(1.05);
            box-shadow: 0 4px 16px rgba(99, 102, 241, 0.4);
        }

        .bk-send-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .bk-send-btn svg {
            width: 20px;
            height: 20px;
        }

        .bk-chat-info {
            margin-top: 10px;
            font-size: 12px;
            color: #6366f1;
            display: flex;
            align-items: center;
            gap: 6px;
            min-height: 18px;
        }

        /* ========== EMPTY STATE ========== */
        .bk-empty-chat {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 40px;
            text-align: center;
        }

        .bk-empty-icon {
            width: 80px;
            height: 80px;
            border-radius: 24px;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
            font-size: 40px;
        }

        .bk-empty-chat h3 {
            margin: 0 0 8px 0;
            color: #1a1a1a;
            font-size: 20px;
            font-weight: 600;
        }

        .bk-empty-chat p {
            color: #666;
            margin: 0;
            font-size: 14px;
        }

        /* ========== SCROLLBAR ========== */
        .bk-chat-wrap ::-webkit-scrollbar {
            width: 6px;
        }

        .bk-chat-wrap ::-webkit-scrollbar-track {
            background: transparent;
        }

        .bk-chat-wrap ::-webkit-scrollbar-thumb {
            background: rgba(0,0,0,0.15);
            border-radius: 3px;
        }

        .bk-chat-wrap ::-webkit-scrollbar-thumb:hover {
            background: rgba(0,0,0,0.25);
        }

        /* Dark scrollbar for sidebar */
        .bk-chat-sidebar ::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15);
        }

        .bk-chat-sidebar ::-webkit-scrollbar-thumb:hover {
            background: rgba(255,255,255,0.25);
        }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 768px) {
            .bk-chat-wrap {
                flex-direction: column;
                height: 100vh;
                border-radius: 0;
            }

            .bk-chat-sidebar {
                width: 100%;
                max-height: 200px;
                border-radius: 0;
            }

            .bk-chat-main {
                border-radius: 0;
            }

            .bk-message-content {
                max-width: 85%;
            }
        }
    </style>

    <div class="bk-chat-wrap">
        <!-- Chat Sidebar - ChatGPT Style -->
        <div class="bk-chat-sidebar">
            <!-- New Chat Button -->
            <button type="button" class="bk-new-chat-btn" id="new-chat-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14"/>
                </svg>
                New chat
            </button>

            <!-- Characters Section -->
            <div class="bk-sidebar-section">
                <div class="bk-section-header">
                    <span>🤖 Characters</span>
                </div>
                <div class="bk-characters-list">
                    <?php if (!empty($characters)): ?>
                        <?php foreach ($characters as $char): ?>
                            <div class="bk-character-item <?php echo $char->id == $character_id ? 'active' : ''; ?>" 
                                 data-character-id="<?php echo $char->id; ?>">
                                <div class="bk-char-avatar">
                                    <?php if (!empty($char->avatar)): ?>
                                        <img src="<?php echo esc_url($char->avatar); ?>" alt="" loading="eager" data-no-lazy="1" data-skip-lazy="1">
                                    <?php else: ?>
                                        <span class="bk-char-placeholder">🤖</span>
                                    <?php endif; ?>
                                </div>
                                <div class="bk-char-info">
                                    <div class="bk-char-name"><?php echo esc_html($char->name); ?></div>
                                    <div class="bk-char-model">
                                        <?php 
                                        $desc = !empty($char->description) ? $char->description : 'AI Assistant';
                                        $words = explode(' ', strip_tags($desc));
                                        echo esc_html(implode(' ', array_slice($words, 0, 15)) . (count($words) > 15 ? '...' : ''));
                                        ?>
                                    </div>
                                </div>
                                <?php if ($char->id == $character_id): ?>
                                    <span class="bk-char-active-dot"></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: #8b8b9e; font-size: 13px;">
                            Chưa có character
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Conversations History Section -->
            <div class="bk-sidebar-section" style="flex: 1; display: flex; flex-direction: column; overflow: hidden;">
                <div class="bk-section-header">
                    <span>💬 Conversations</span>
                    <span class="bk-section-clear" id="clear-all-conversations">Clear All</span>
                </div>
                <div class="bk-conversations-section" id="conversations-list">
                    <!-- Conversations will be loaded dynamically -->
                    <div style="padding: 20px; text-align: center; color: #666; font-size: 12px;">
                        Loading...
                    </div>
                </div>
            </div>

            <!-- Sidebar Footer -->
            <div class="bk-sidebar-footer">
                <button type="button" class="bk-settings-btn" id="clear-history-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                    Xóa lịch sử chat
                </button>
            </div>
        </div>

        <!-- Chat Main Area -->
        <div class="bk-chat-main">
            <?php if ($character): ?>
                <!-- Chat Header -->
                <div class="bk-chat-header">
                    <div class="bk-chat-header-left">
                        <div class="bk-chat-avatar">
                            <?php if (!empty($character->avatar)): ?>
                                <img src="<?php echo esc_url($character->avatar); ?>" alt="" loading="eager" data-no-lazy="1">
                            <?php else: ?>
                                <span style="font-size: 24px; color: #fff;">🤖</span>
                            <?php endif; ?>
                        </div>
                        <div class="bk-chat-header-info">
                            <h2><?php echo esc_html($character->name); ?></h2>
                            <div class="bk-chat-meta">
                                <span class="bk-model-badge">
                                    <?php 
                                    $desc = !empty($character->description) ? $character->description : 'AI Assistant';
                                    $words = explode(' ', strip_tags($desc));
                                    echo esc_html(implode(' ', array_slice($words, 0, 15)) . (count($words) > 15 ? '...' : ''));
                                    ?>
                                </span>
                                <span class="bk-status-badge">Online</span>
                            </div>
                        </div>
                    </div>
                    <div class="bk-chat-header-actions">
                        <button type="button" class="bk-header-btn" id="refresh-chat-btn" title="Làm mới">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M23 4v6h-6M1 20v-6h6M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Chat Messages -->
                <div class="bk-chat-messages" id="chat-messages">
                    <!-- Initial greeting message -->
                    <div class="bk-message bk-message-assistant">
                        <div class="bk-message-avatar">
                            <?php if (!empty($character->avatar)): ?>
                                <img src="<?php echo esc_url($character->avatar); ?>" alt="" loading="eager" data-no-lazy="1">
                            <?php else: ?>
                                <span>🤖</span>
                            <?php endif; ?>
                        </div>
                        <div class="bk-message-content">
                            <div class="bk-message-bubble"><?php echo esc_html($random_greeting); ?></div>
                            <div class="bk-message-time"><?php echo date('H:i'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Image Preview Area -->
                <div class="bk-image-preview-area" id="image-preview-area">
                    <div class="bk-image-previews" id="image-previews"></div>
                </div>

                <!-- Chat Input -->
                <div class="bk-chat-input-wrap">
                    <div class="bk-input-container">
                        <div class="bk-input-actions">
                            <button type="button" id="attach-image" class="bk-input-btn" title="Đính kèm hình ảnh">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                                    <circle cx="8.5" cy="8.5" r="1.5"/>
                                    <polyline points="21 15 16 10 5 21"/>
                                </svg>
                            </button>
                        </div>
                        <input type="file" id="image-input" accept="image/*" multiple style="display:none;">
                        <textarea id="chat-input" placeholder="Nhập tin nhắn..." rows="1"></textarea>
                        <button type="button" id="send-message" class="bk-send-btn" disabled title="Gửi">
                            <svg viewBox="0 0 24 24" fill="currentColor">
                                <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                            </svg>
                        </button>
                    </div>
                    <div class="bk-chat-info" id="chat-info"></div>
                </div>
            <?php else: ?>
                <div class="bk-empty-chat">
                    <div class="bk-empty-icon">💬</div>
                    <h3>Chọn một character để bắt đầu chat</h3>
                    <p>Chọn AI assistant từ danh sách bên trái</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        const ChatInterface = {
            characterId: <?php echo $character_id ? $character_id : 'null'; ?>,
            characterName: '<?php echo $character ? esc_js($character->name) : 'AI'; ?>',
            characterAvatar: '<?php echo $character && !empty($character->avatar) ? esc_url($character->avatar) : ''; ?>',
            sessionId: '<?php echo esc_js($current_session); ?>',
            ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('bizcity_webchat'); ?>',
            messages: [],
            conversations: [],
            pendingImages: [],
            currentConversationId: null,
            
            init() {
                if (!this.characterId) return;
                
                this.bindEvents();
                this.loadConversations();
                this.loadHistory();
                this.checkVisionSupport();
            },
            
            checkVisionSupport() {
                const modelId = '<?php echo $character ? esc_js($character->model_id ?? '') : ''; ?>';
                const visionModels = ['gpt-4o', 'gpt-4-vision', 'claude-3', 'gemini', 'gpt-4o-mini', 'gpt-4'];
                const supportsVision = visionModels.some(m => modelId.toLowerCase().includes(m)) || !modelId;
                
                if (supportsVision) {
                    $('#attach-image').show();
                } else {
                    $('#attach-image').hide();
                }
            },
            
            bindEvents() {
                const self = this;
                
                // Switch character  
                $('.bk-character-item').on('click', function() {
                    const charId = $(this).data('character-id');
                    if (charId != self.characterId) {
                        const url = new URL(window.location.href);
                        url.searchParams.set('character_id', charId);
                        window.location.href = url.toString();
                    }
                });
                
                // New chat button
                $('#new-chat-btn').on('click', () => this.startNewConversation());
                
                // Clear history button
                $('#clear-history-btn').on('click', () => this.clearAllHistory());
                
                // Clear all conversations
                $('#clear-all-conversations').on('click', () => this.clearAllHistory());
                
                // Refresh chat
                $('#refresh-chat-btn').on('click', () => this.loadHistory());
                
                // Attach image button
                $('#attach-image').on('click', () => $('#image-input').click());
                
                // Image file selected
                $('#image-input').on('change', (e) => this.handleImageSelect(e.target.files));
                
                // Send message
                $('#send-message').on('click', () => this.sendMessage());
                
                // Enter to send
                $('#chat-input').on('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
                
                // Enable/disable send button
                $('#chat-input').on('input', () => this.updateSendButton());
                
                // Auto-resize textarea
                $('#chat-input').on('input', function() {
                    this.style.height = 'auto';
                    this.style.height = Math.min(this.scrollHeight, 150) + 'px';
                });
            },
            
            // ========== CONVERSATIONS ==========
            loadConversations() {
                const storageKey = 'bk_conversations_' + this.characterId;
                const data = localStorage.getItem(storageKey);
                
                if (data) {
                    try {
                        this.conversations = JSON.parse(data);
                    } catch (e) {
                        this.conversations = [];
                    }
                }
                
                this.renderConversations();
            },
            
            saveConversations() {
                const storageKey = 'bk_conversations_' + this.characterId;
                localStorage.setItem(storageKey, JSON.stringify(this.conversations));
                this.renderConversations();
            },
            
            renderConversations() {
                const $container = $('#conversations-list');
                $container.empty();
                
                if (this.conversations.length === 0) {
                    $container.html(`
                        <div style="padding: 20px; text-align: center; color: #8b8b9e; font-size: 12px;">
                            Chưa có hội thoại nào
                        </div>
                    `);
                    return;
                }
                
                // Group by date
                const today = new Date().toDateString();
                const yesterday = new Date(Date.now() - 86400000).toDateString();
                
                const groups = {
                    today: [],
                    yesterday: [],
                    older: []
                };
                
                this.conversations.forEach(conv => {
                    const convDate = new Date(conv.timestamp).toDateString();
                    if (convDate === today) {
                        groups.today.push(conv);
                    } else if (convDate === yesterday) {
                        groups.yesterday.push(conv);
                    } else {
                        groups.older.push(conv);
                    }
                });
                
                const self = this;
                
                const renderGroup = (items, label) => {
                    if (items.length === 0) return;
                    
                    $container.append(`<div style="padding: 8px 12px; font-size: 11px; color: #666; text-transform: uppercase; letter-spacing: 0.5px;">${label}</div>`);
                    
                    items.forEach(conv => {
                        const isActive = conv.id === self.currentConversationId ? 'active' : '';
                        const title = conv.title || 'New conversation';
                        
                        const $item = $(`
                            <div class="bk-conversation-item ${isActive}" data-conv-id="${conv.id}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                                </svg>
                                <span class="bk-conversation-title">${self.escapeHtml(title)}</span>
                                <div class="bk-conversation-actions">
                                    <button class="bk-conv-action-btn bk-delete-conv" data-conv-id="${conv.id}" title="Xóa">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        `);
                        
                        $item.on('click', function(e) {
                            if (!$(e.target).closest('.bk-delete-conv').length) {
                                self.loadConversation(conv.id);
                            }
                        });
                        
                        $item.find('.bk-delete-conv').on('click', function(e) {
                            e.stopPropagation();
                            self.deleteConversation(conv.id);
                        });
                        
                        $container.append($item);
                    });
                };
                
                renderGroup(groups.today, 'Hôm nay');
                renderGroup(groups.yesterday, 'Hôm qua');
                renderGroup(groups.older, 'Trước đó');
            },
            
            startNewConversation() {
                this.currentConversationId = 'conv_' + Date.now();
                this.messages = [];
                
                // Clear UI
                const $container = $('#chat-messages');
                $container.empty();
                
                // Show greeting
                const avatarHtml = this.characterAvatar 
                    ? `<img src="${this.characterAvatar}" alt="" loading="eager" data-no-lazy="1">`
                    : '<span>🤖</span>';
                    
                $container.append(`
                    <div class="bk-message bk-message-assistant">
                        <div class="bk-message-avatar">${avatarHtml}</div>
                        <div class="bk-message-content">
                            <div class="bk-message-bubble"><?php echo esc_js($random_greeting); ?></div>
                            <div class="bk-message-time">${new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}</div>
                        </div>
                    </div>
                `);
                
                this.saveConversations();
                $('#chat-input').focus();
            },
            
            loadConversation(convId) {
                const conv = this.conversations.find(c => c.id === convId);
                if (!conv) return;
                
                this.currentConversationId = convId;
                this.messages = conv.messages || [];
                this.renderMessages();
                this.renderConversations();
            },
            
            deleteConversation(convId) {
                this.conversations = this.conversations.filter(c => c.id !== convId);
                this.saveConversations();
                
                if (this.currentConversationId === convId) {
                    this.startNewConversation();
                }
            },
            
            // ========== MESSAGES ==========
            loadHistory() {
                const historyKey = 'bk_chat_' + this.characterId;
                const history = localStorage.getItem(historyKey);
                
                if (history) {
                    try {
                        this.messages = JSON.parse(history);
                        if (this.messages.length > 0) {
                            this.renderMessages();
                        }
                    } catch (e) {
                        console.error('Failed to load history:', e);
                    }
                }
            },
            
            saveHistory() {
                const historyKey = 'bk_chat_' + this.characterId;
                localStorage.setItem(historyKey, JSON.stringify(this.messages));
                
                // Also update conversation
                this.updateCurrentConversation();
            },
            
            updateCurrentConversation() {
                if (!this.currentConversationId) {
                    this.currentConversationId = 'conv_' + Date.now();
                }
                
                // Get title from first user message
                const firstUserMsg = this.messages.find(m => m.role === 'user');
                const title = firstUserMsg 
                    ? (firstUserMsg.content || 'Hình ảnh').substring(0, 40) + (firstUserMsg.content?.length > 40 ? '...' : '')
                    : 'New conversation';
                
                const existingIndex = this.conversations.findIndex(c => c.id === this.currentConversationId);
                
                const convData = {
                    id: this.currentConversationId,
                    title: title,
                    timestamp: Date.now(),
                    messages: this.messages
                };
                
                if (existingIndex >= 0) {
                    this.conversations[existingIndex] = convData;
                } else {
                    this.conversations.unshift(convData);
                }
                
                // Keep only last 50 conversations
                this.conversations = this.conversations.slice(0, 50);
                this.saveConversations();
            },
            
            clearAllHistory() {
                if (!confirm('Bạn có chắc chắn muốn xóa tất cả lịch sử chat?')) return;
                
                this.messages = [];
                this.conversations = [];
                
                const historyKey = 'bk_chat_' + this.characterId;
                const convKey = 'bk_conversations_' + this.characterId;
                
                localStorage.removeItem(historyKey);
                localStorage.removeItem(convKey);
                
                this.startNewConversation();
            },
            
            renderMessages() {
                const $container = $('#chat-messages');
                $container.empty();
                
                if (this.messages.length === 0) {
                    const avatarHtml = this.characterAvatar 
                        ? `<img src="${this.characterAvatar}" alt="" loading="eager" data-no-lazy="1">`
                        : '<span>🤖</span>';
                        
                    $container.append(`
                        <div class="bk-message bk-message-assistant">
                            <div class="bk-message-avatar">${avatarHtml}</div>
                            <div class="bk-message-content">
                                <div class="bk-message-bubble"><?php echo esc_js($random_greeting); ?></div>
                                <div class="bk-message-time">${new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })}</div>
                            </div>
                        </div>
                    `);
                    return;
                }
                
                this.messages.forEach(msg => {
                    this.appendMessage(msg.role, msg.content, msg.timestamp, false, msg.images || []);
                });
                
                this.scrollToBottom();
            },
            
            sendMessage() {
                const $input = $('#chat-input');
                const text = $input.val().trim();
                
                if (!text && this.pendingImages.length === 0) return;
                
                // Prepare image data (CRITICAL: save before clearing)
                const imageDataUrls = this.pendingImages.map(img => img.dataUrl);
                
                // Add user message
                const timestamp = Date.now();
                const messageData = {
                    role: 'user',
                    content: text,
                    timestamp: timestamp,
                    images: imageDataUrls.length > 0 ? imageDataUrls : undefined
                };
                
                this.messages.push(messageData);
                this.appendMessage('user', text, timestamp, true, imageDataUrls);
                this.saveHistory();
                
                // Clear input and images AFTER saving
                $input.val('').css('height', 'auto');
                this.clearImagePreviews();
                $('#send-message').prop('disabled', true);
                
                // Show typing indicator
                this.showTypingIndicator();
                
                // Send to AI
                this.getAIResponse(text, imageDataUrls);
            },
            
            showTypingIndicator() {
                const $container = $('#chat-messages');
                const avatarHtml = this.characterAvatar 
                    ? `<img src="${this.characterAvatar}" alt="" loading="eager" data-no-lazy="1">`
                    : '<span>🤖</span>';
                    
                const typingHtml = `
                    <div class="bk-message bk-message-assistant" id="typing-indicator">
                        <div class="bk-message-avatar">${avatarHtml}</div>
                        <div class="bk-message-content">
                            <div class="bk-message-bubble">
                                <div class="bk-typing-indicator">
                                    <span class="bk-typing-dot"></span>
                                    <span class="bk-typing-dot"></span>
                                    <span class="bk-typing-dot"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                $container.append(typingHtml);
                this.scrollToBottom();
            },
            
            hideTypingIndicator() {
                $('#typing-indicator').remove();
            },
            
            getAIResponse(userMessage, images = []) {
                const self = this;
                
                // Build FormData for SSE stream
                const formData = new FormData();
                formData.append('action', 'bizcity_chat_stream');
                formData.append('platform_type', 'WEBCHAT');
                formData.append('message', userMessage);
                formData.append('session_id', this.sessionId);
                formData.append('character_id', this.characterId);
                formData.append('_wpnonce', this.nonce);
                
                if (images && images.length > 0) {
                    formData.append('image_data', images[0]);
                }
                
                // Replace typing indicator with streaming bot bubble
                this.hideTypingIndicator();
                const timestamp = Date.now();
                const time = new Date(timestamp).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
                const $container = $('#chat-messages');
                
                const avatarHtml = this.characterAvatar
                    ? '<img src="' + this.escapeHtml(this.characterAvatar) + '" alt="" loading="eager" data-no-lazy="1">'
                    : '<span>🤖</span>';
                
                const $msg = $('<div class="bk-message bk-message-assistant" id="bk-streaming-msg"></div>');
                const $avatar = $('<div class="bk-message-avatar"></div>').html(avatarHtml);
                const $contentWrap = $('<div class="bk-message-content"></div>');
                const $bubble = $('<div class="bk-message-bubble bk-md"></div>').html('<em style="opacity:0.6">đang xử lý...</em>');
                const $time = $('<div class="bk-message-time"></div>').text(time);
                $contentWrap.append($bubble).append($time);
                $msg.append($avatar).append($contentWrap);
                $container.append($msg);
                this.scrollToBottom();
                
                let fullText = '';
                
                fetch(this.ajaxUrl, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                }).then(function(response) {
                    if (!response.ok || !response.body) throw new Error('Stream unavailable');
                    
                    const reader = response.body.getReader();
                    const decoder = new TextDecoder();
                    let buffer = '';
                    
                    function processStream() {
                        return reader.read().then(function(result) {
                            if (result.done) {
                                self._finalizeStream(fullText, timestamp, $bubble, $msg);
                                return;
                            }
                            
                            buffer += decoder.decode(result.value, { stream: true });
                            const lines = buffer.split('\n');
                            buffer = lines.pop(); // keep incomplete line
                            
                            let eventType = '';
                            for (let i = 0; i < lines.length; i++) {
                                const line = lines[i].trim();
                                if (line.startsWith('event:')) {
                                    eventType = line.substring(6).trim();
                                } else if (line.startsWith('data:')) {
                                    const dataStr = line.substring(5).trim();
                                    try {
                                        const data = JSON.parse(dataStr);
                                        if (eventType === 'chunk' && data.delta) {
                                            fullText = data.full || (fullText + data.delta);
                                            $bubble.html(self.formatMessage(fullText));
                                            self.scrollToBottom();
                                        } else if (eventType === 'status' && data.text) {
                                            // Show thinking/processing status to user
                                            $bubble.html('<span style="font-size:13px;opacity:.85">' + self.escapeHtml(data.text) + '</span>');
                                            self.scrollToBottom();
                                        } else if (eventType === 'done') {
                                            fullText = data.message || fullText;
                                        } else if (eventType === 'error') {
                                            $bubble.html('❌ ' + self.escapeHtml(data.message || 'Lỗi xử lý'));
                                        }
                                    } catch(e) { /* skip malformed data */ }
                                    eventType = '';
                                }
                            }
                            
                            return processStream();
                        });
                    }
                    
                    return processStream();
                }).catch(function(err) {
                    console.warn('SSE stream failed, falling back to AJAX:', err);
                    $msg.remove(); // remove streaming bubble
                    self.showTypingIndicator();
                    self._getAIResponseFallback(userMessage, images);
                });
            },
            
            _finalizeStream(fullText, timestamp, $bubble, $msg) {
                $msg.removeAttr('id');
                const reply = fullText || 'Cảm ơn bạn đã nhắn tin!';
                $bubble.html(this.formatMessage(reply));
                this.messages.push({
                    role: 'assistant',
                    content: reply,
                    timestamp: timestamp
                });
                this.saveHistory();
                this.scrollToBottom();
            },
            
            _getAIResponseFallback(userMessage, images) {
                const data = {
                    action: 'bizcity_chat_send',
                    platform_type: 'WEBCHAT',
                    message: userMessage,
                    session_id: this.sessionId,
                    character_id: this.characterId,
                    _wpnonce: this.nonce
                };
                if (images && images.length > 0) {
                    data.image_data = images[0];
                }
                
                $.ajax({
                    url: this.ajaxUrl,
                    method: 'POST',
                    data: data,
                    dataType: 'text',
                    success: (response) => {
                        try {
                            this.hideTypingIndicator();
                            if (typeof response === 'string') {
                                response = response.replace(/^\uFEFF/, '');
                                const jsonStart = response.indexOf('{');
                                if (jsonStart > 0) response = response.substring(jsonStart);
                                const jsonEnd = response.lastIndexOf('}');
                                if (jsonEnd > 0 && jsonEnd < response.length - 1) response = response.substring(0, jsonEnd + 1);
                                try { response = JSON.parse(response); } catch (e) {
                                    this.appendMessage('assistant', '❌ Lỗi xử lý dữ liệu từ server.', Date.now());
                                    return;
                                }
                            }
                            if (response.success) {
                                const reply = response.data?.reply || 'Cảm ơn bạn đã nhắn tin!';
                                const ts = Date.now();
                                this.messages.push({ role: 'assistant', content: reply, timestamp: ts });
                                this.appendMessage('assistant', reply, ts);
                                this.saveHistory();
                            } else {
                                const errorMsg = response.data?.message || 'Có lỗi xảy ra. Vui lòng thử lại.';
                                this.appendMessage('assistant', '❌ ' + errorMsg, Date.now());
                            }
                        } catch (e) {
                            this.appendMessage('assistant', '❌ Lỗi xử lý phản hồi: ' + e.message, Date.now());
                        }
                    },
                    error: (xhr, status, error) => {
                        this.hideTypingIndicator();
                        this.appendMessage('assistant', '❌ Không thể kết nối đến server. Vui lòng thử lại.', Date.now());
                    }
                });
            },
            
            appendMessage(role, content, timestamp, scroll = true, images = []) {
                const $container = $('#chat-messages');
                const time = new Date(timestamp).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' });
                
                // Build message element
                const $message = $('<div class="bk-message"></div>');
                $message.addClass(role === 'user' ? 'bk-message-user' : 'bk-message-assistant');
                
                const $content = $('<div class="bk-message-content"></div>');
                const $avatar = $('<div class="bk-message-avatar"></div>');
                
                // Build images using DOM elements - CRITICAL: bypass lazy-load
                if (images && images.length > 0) {
                    const $imagesDiv = $('<div class="bk-message-images"></div>');
                    images.forEach(imgUrl => {
                        const img = document.createElement('img');
                        img.src = imgUrl;
                        img.alt = 'Hình ảnh';
                        img.className = 'bk-message-image no-lazy skip-lazy';
                        img.loading = 'eager';
                        img.decoding = 'sync';
                        img.setAttribute('data-no-lazy', '1');
                        img.setAttribute('data-skip-lazy', '1');
                        img.setAttribute('data-lazy-srcset', '');
                        img.style.cssText = 'display:block !important; visibility:visible !important; opacity:1 !important; max-width:200px; border-radius:8px;';
                        $imagesDiv.append(img);
                    });
                    $content.append($imagesDiv);
                }
                
                // Build bubble
                const $bubble = $('<div class="bk-message-bubble bk-md"></div>');
                if (role === 'user') {
                    $bubble.html(content ? this.escapeHtml(content) : '<em style="opacity:0.7;">Hình ảnh đính kèm</em>');
                } else {
                    $bubble.html(this.formatMessage(content));
                }
                $content.append($bubble);
                
                // Build time
                const $time = $('<div class="bk-message-time"></div>').text(time);
                $content.append($time);
                
                // Build avatar
                if (role === 'user') {
                    $avatar.html('<span>👤</span>');
                    $message.append($content).append($avatar);
                } else {
                    if (this.characterAvatar) {
                        const avatarImg = document.createElement('img');
                        avatarImg.src = this.characterAvatar;
                        avatarImg.alt = '';
                        avatarImg.loading = 'eager';
                        avatarImg.setAttribute('data-no-lazy', '1');
                        $avatar.append(avatarImg);
                    } else {
                        $avatar.html('<span>🤖</span>');
                    }
                    $message.append($avatar).append($content);
                }
                
                $container.append($message);
                
                if (scroll) {
                    this.scrollToBottom();
                }
            },
            
            formatMessage(text) {
                if (!text) return '';
                // If already contains HTML tags, return as-is
                if (/<\/?(?:div|p|br|h[1-6]|ul|ol|li|strong|em|table|tr|td|th|blockquote|pre|code|span|a|img)[\s>]/i.test(text)) {
                    return text;
                }
                var t = this.escapeHtml(text);
                // Code blocks: ```...```
                t = t.replace(/```([\s\S]*?)```/g, '<pre style="background:#f3f4f6;color:#1f2937;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px;margin:8px 0"><code>$1</code></pre>');
                // Headings: ### / ## / #
                t = t.replace(/^### (.+)$/gm, '<h4 style="margin:8px 0 4px;font-size:14px;font-weight:700">$1</h4>');
                t = t.replace(/^## (.+)$/gm, '<h3 style="margin:8px 0 4px;font-size:15px;font-weight:700">$1</h3>');
                t = t.replace(/^# (.+)$/gm, '<h2 style="margin:8px 0 4px;font-size:16px;font-weight:700">$1</h2>');
                // Bold + Italic: ***text***
                t = t.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
                // Bold: **text**
                t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                // Italic: *text*
                t = t.replace(/\*(.+?)\*/g, '<em>$1</em>');
                // Inline code: `text`
                t = t.replace(/`([^`]+)`/g, '<code style="background:#f0f0f0;padding:2px 6px;border-radius:4px;font-size:12px">$1</code>');
                // Unordered list: - item
                t = t.replace(/((?:^|\n)- .+(?:\n- .+)*)/g, function(block) {
                    var items = block.trim().split('\n').map(function(line) {
                        return '<li>' + line.replace(/^- /, '') + '</li>';
                    }).join('');
                    return '<ul style="margin:6px 0;padding-left:20px">' + items + '</ul>';
                });
                // Ordered list: 1. item
                t = t.replace(/((?:^|\n)\d+\. .+(?:\n\d+\. .+)*)/g, function(block) {
                    var items = block.trim().split('\n').map(function(line) {
                        return '<li>' + line.replace(/^\d+\.\s*/, '') + '</li>';
                    }).join('');
                    return '<ol style="margin:6px 0;padding-left:20px">' + items + '</ol>';
                });
                // Line breaks
                t = t.replace(/\n/g, '<br>');
                // Clean up <br> around block elements
                t = t.replace(/(<\/(?:h[2-4]|ul|ol|pre|li)>)<br>/g, '$1');
                t = t.replace(/<br>(<(?:h[2-4]|ul|ol|pre))/g, '$1');
                return t;
            },
            
            escapeHtml(text) {
                if (!text) return '';
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            },
            
            scrollToBottom() {
                const $container = $('#chat-messages');
                setTimeout(() => {
                    $container.scrollTop($container[0].scrollHeight);
                }, 50);
            },
            
            // ========== IMAGE HANDLING ==========
            handleImageSelect(files) {
                if (!files || files.length === 0) return;
                
                Array.from(files).forEach(file => {
                    if (!file.type.startsWith('image/')) {
                        alert('Chỉ chấp nhận file hình ảnh');
                        return;
                    }
                    
                    if (this.pendingImages.length >= 5) {
                        alert('Tối đa 5 hình ảnh');
                        return;
                    }
                    
                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const dataUrl = e.target.result;
                        this.pendingImages.push({
                            dataUrl: dataUrl,
                            file: file
                        });
                        this.renderImagePreviews();
                        this.updateSendButton();
                    };
                    reader.readAsDataURL(file);
                });
                
                $('#image-input').val('');
            },
            
            renderImagePreviews() {
                const $container = $('#image-previews');
                const self = this;
                $container.empty();
                
                this.pendingImages.forEach((img, index) => {
                    // Create image element entirely via JS to bypass lazy-load plugins
                    const $preview = $('<div class="bk-image-preview-item"></div>').attr('data-index', index);
                    
                    // Create img element directly and set src immediately
                    const img_el = document.createElement('img');
                    img_el.src = img.dataUrl;
                    img_el.alt = 'Preview';
                    img_el.loading = 'eager';
                    img_el.decoding = 'sync';
                    img_el.setAttribute('data-no-lazy', '1');
                    img_el.setAttribute('data-skip-lazy', '1');
                    img_el.setAttribute('data-lazy-srcset', '');
                    img_el.className = 'no-lazy skip-lazy';
                    img_el.style.cssText = 'display:block !important; visibility:visible !important; opacity:1 !important;';
                    
                    $preview.append(img_el);
                    $preview.append($('<button type="button" class="bk-remove-image"></button>').attr('data-index', index).text('✕'));
                    $container.append($preview);
                });
                
                $container.find('.bk-remove-image').on('click', function(e) {
                    e.preventDefault();
                    const index = $(this).data('index');
                    self.pendingImages.splice(index, 1);
                    self.renderImagePreviews();
                    self.updateSendButton();
                });
                
                if (this.pendingImages.length > 0) {
                    $('#image-preview-area').addClass('active');
                    $('#chat-info').html('📷 Hình ảnh đính kèm - AI sẽ phân tích');
                } else {
                    $('#image-preview-area').removeClass('active');
                    $('#chat-info').html('');
                }
            },
            
            clearImagePreviews() {
                this.pendingImages = [];
                $('#image-previews').empty();
                $('#image-preview-area').removeClass('active');
                $('#chat-info').html('');
            },
            
            updateSendButton() {
                const text = $('#chat-input').val().trim();
                const hasContent = text.length > 0 || this.pendingImages.length > 0;
                $('#send-message').prop('disabled', !hasContent);
            }
        };
        
        ChatInterface.init();
    });
    </script>
    <?php
    return ob_get_clean();
}

/**
 * Render Floating Chat (nút nổi ở góc màn hình)
 */
function render_floating_chat($character_id, $atts) {
    // To implement
    return '<div class="bizcity-chat-floating">Floating chat - Coming soon</div>';
}

/**
 * Render Popup Chat (popup khi click)
 */
function render_popup_chat($character_id, $atts) {
    // To implement
    return '<div class="bizcity-chat-popup">Popup chat - Coming soon</div>';
}

// Register shortcodes
add_shortcode('chatbot', 'bizcity_webchat_chatbot_shortcode');
add_shortcode('bizcity_chat', 'bizcity_webchat_chatbot_shortcode');
add_shortcode('webchat', 'bizcity_webchat_chatbot_shortcode');
