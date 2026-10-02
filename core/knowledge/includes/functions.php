<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Helper Functions for Knowledge Module
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

defined('ABSPATH') or die('OOPS...');

/**
 * Get character by ID or slug
 */
function bizcity_get_character($id_or_slug) {
    if (is_numeric($id_or_slug)) {
        return BizCity_Character::get($id_or_slug);
    }
    return BizCity_Character::get_by_slug($id_or_slug);
}

/**
 * Query a character with a message
 */
function bizcity_query_character($character_id, $query, $context = []) {
    return BizCity_Character::query($character_id, $query, $context);
}

/**
 * Get default character for webchat
 */
function bizcity_get_default_character() {
    $default_id = get_option('bizcity_knowledge_default_character');
    
    if (empty($default_id)) {
        return null;
    }
    
    return BizCity_Character::get($default_id);
}

/**
 * Add knowledge source to character
 */
function bizcity_add_knowledge($character_id, $type, $data) {
    switch ($type) {
        case 'quick_faq':
            return BizCity_Knowledge_Source::add_quick_faq($character_id, $data['post_id']);
            
        case 'file':
            return BizCity_Knowledge_Source::add_file($character_id, $data['attachment_id']);
            
        case 'url':
            return BizCity_Knowledge_Source::add_url(
                $character_id, 
                $data['url'], 
                $data['scrape_type'] ?? 'simple_html'
            );
            
        default:
            return new WP_Error('invalid_type', 'Invalid knowledge source type');
    }
}

/**
 * Search knowledge base
 */
function bizcity_search_knowledge($character_id, $query, $limit = 5) {
    return BizCity_Knowledge_Source::search($character_id, $query, $limit);
}

/**
 * Get all quick_faq posts for selection
 */
function bizcity_get_quick_faq_posts() {
    return get_posts([
        'post_type' => 'quick_faq',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
}

/**
 * Parse intent from text using character's configuration
 */
function bizcity_parse_intent($character_id, $text) {
    $character = BizCity_Character::get($character_id);
    
    if (!$character) {
        return [
            'intent' => '',
            'variables' => [],
            'confidence' => 0,
        ];
    }
    
    $knowledge = BizCity_Knowledge_Source::get_knowledge_for_character($character_id);
    // [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-2 — BizCity_Intent_Parser moved to the add-on.
    if ( ! class_exists( 'BizCity_Intent_Parser' ) ) {
        return [ 'intent' => '', 'variables' => [], 'confidence' => 0 ];
    }
    $parser = BizCity_Intent_Parser::instance();

    return $parser->parse($text, $character, $knowledge);
}

/**
 * Hook: Process webchat message with knowledge character
 * 
 * Sử dụng trong bizcity-bot-webchat: 
 * add_filter('bizcity_webchat_process_message', 'bizcity_knowledge_process_webchat', 10, 3);
 */
function bizcity_knowledge_process_webchat($response, $message, $context) {
    // Get character from context or use default
    $character_id = $context['character_id'] ?? get_option('bizcity_knowledge_default_character');
    
    if (empty($character_id)) {
        return $response;
    }
    
    $result = bizcity_query_character($character_id, $message, $context);
    
    // Fire trigger for automation
    do_action('bizcity_automation_trigger', 'character_response', [
        'character_id' => $character_id,
        'message' => $message,
        'response' => $result['response'],
        'intent' => $result['intent'],
        'variables' => $result['variables'],
    ]);
    
    return $result['response'];
}

/**
 * Chat với character qua bizcity-knowledge
 * Hỗ trợ vision (phân tích hình ảnh) nếu có image_data
 * 
 * @param string $message Tin nhắn từ user
 * @param int $character_id ID của character
 * @param string $session_id Session ID
 * @param string $image_data Base64 image data (optional)
 * @return string Reply từ AI
 */
/*
function bizcity_knowledge_chat($message, $character_id, $session_id = '', $image_data = '') {
    // Get character
    $character = null;
    if ($character_id && class_exists('BizCity_Knowledge_Database')) {
        $db = BizCity_Knowledge_Database::instance();
        $character = $db->get_character($character_id);
    }
    
    // Get API key
    $api_key = get_option('twf_openai_api_key');
    if (empty($api_key)) {
        return 'Xin lỗi, hệ thống chưa được cấu hình API key.';
    }
    
    // Build system prompt from character
    $system_prompt = 'Bạn là trợ lý AI thân thiện, hữu ích. Hãy trả lời ngắn gọn, rõ ràng bằng tiếng Việt.';
    if ($character && !empty($character->system_prompt)) {
        $system_prompt = $character->system_prompt;
    }
    
    // Get AI model from character or default
    $model = 'gpt-4o-mini';
    if ($character && !empty($character->ai_model)) {
        $model = $character->ai_model;
    }
    
    // Build messages array
    $messages = [
        ['role' => 'system', 'content' => $system_prompt],
    ];
    
    // Build user message content
    if (!empty($image_data)) {
        // Vision request - use gpt-4o or gpt-4o-mini for vision
        if (strpos($model, 'gpt-4') === false) {
            $model = 'gpt-4o-mini'; // Fallback to vision-capable model
        }
        
        // Build content array with text and image
        $user_content = [];
        
        if (!empty($message)) {
            $user_content[] = [
                'type' => 'text',
                'text' => $message
            ];
        }
        
        // Add image
        $user_content[] = [
            'type' => 'image_url',
            'image_url' => [
                'url' => $image_data, // Already base64 data URL
                'detail' => 'auto'
            ]
        ];
        
        $messages[] = ['role' => 'user', 'content' => $user_content];
    } else {
        // Text only request
        $messages[] = ['role' => 'user', 'content' => $message];
    }
    
    // Call OpenAI API
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1500,
        ]),
        'timeout' => 60,
    ]);
    
    if (is_wp_error($response)) {
        error_log('bizcity_knowledge_chat error: ' . $response->get_error_message());
        return 'Xin lỗi, có lỗi kết nối đến AI.';
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['error'])) {
        error_log('bizcity_knowledge_chat API error: ' . json_encode($body['error']));
        return 'Xin lỗi, có lỗi từ AI: ' . ($body['error']['message'] ?? 'Unknown error');
    }
    
    return $body['choices'][0]['message']['content'] ?? 'Xin lỗi, không thể xử lý câu hỏi của bạn.';
}
*/
/**
 * [2026-10-01 Claude Sonnet 5] CORE-REDUCTION WP-17 K-1 (R-LEAN-4) — bizgpt_chatbot_fallback_ai_response(),
 * bizgpt_chatbot_run_guest_flows(), bizgpt_inbox_assign_flow_id() and bizgpt_return_or_ajax() moved to their
 * real owner, plugins/bizcity-facebook-bot/lib/legacy-functions.php (only that plugin called
 * bizgpt_chatbot_run_guest_flows; the other three were only called from inside it; one of them wrote the
 * Facebook bot's own bizcity_facebook_inbox table).
 */

/**
 * Shortcode: Embed character chat widget
 * Usage: [bizcity_character_chat id="1"]
 */
add_shortcode('bizcity_character_chat', function($atts) {
    // [2026-09-26 Claude Sonnet 5] CORE-REDUCTION WP-10 A3 (R-GP-2/R-GP-4) — this public widget posted anonymously to
    // bizcity-knowledge/v1/characters/{id}/query, which is now admin/API-key only. A visitor must not talk to a Guru directly
    // (customers reach a Guru through its bound channel), so the shortcode renders nothing instead of a chat box that would 401.
    // Pages that still contain [bizcity_character_chat] simply show no widget.
    if ( true ) {
        return '';
    }
    $atts = shortcode_atts([
        'id' => '',
        'style' => 'embed', // embed | float
    ], $atts);
    
    if (empty($atts['id'])) {
        return '<p>Character ID is required.</p>';
    }
    
    $character = BizCity_Character::get($atts['id']);
    
    if (!$character) {
        return '<p>Character not found.</p>';
    }
    
    ob_start();
    ?>
    <div class="bizcity-character-chat" 
         data-character-id="<?php echo esc_attr($character->id); ?>"
         data-style="<?php echo esc_attr($atts['style']); ?>">
        
        <div class="bcc-header">
            <?php if ($character->avatar): ?>
            <img src="<?php echo esc_url($character->avatar); ?>" class="bcc-avatar">
            <?php endif; ?>
            <span class="bcc-name"><?php echo esc_html($character->name); ?></span>
        </div>
        
        <div class="bcc-messages" id="bcc-messages-<?php echo $character->id; ?>">
            <div class="bcc-message bot">
                <div class="bcc-bubble">
                    Xin chào! Tôi là <?php echo esc_html($character->name); ?>. Tôi có thể giúp gì cho bạn?
                </div>
            </div>
        </div>
        
        <div class="bcc-input-area">
            <input type="text" class="bcc-input" placeholder="Nhập tin nhắn...">
            <button class="bcc-send">Gửi</button>
        </div>
    </div>
    
    <style>
        .bizcity-character-chat {
            border: 1px solid #e0e0e0;
            border-radius: 12px;
            overflow: hidden;
            max-width: 400px;
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
        }
        .bcc-header {
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            color: #fff;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .bcc-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .bcc-name { font-weight: 600; }
        .bcc-messages {
            height: 300px;
            overflow-y: auto;
            padding: 15px;
            background: #f8fafc;
        }
        .bcc-message {
            margin-bottom: 12px;
        }
        .bcc-message.bot .bcc-bubble {
            background: #fff;
            border: 1px solid #e0e0e0;
        }
        .bcc-message.user .bcc-bubble {
            background: #6366f1;
            color: #fff;
            margin-left: auto;
        }
        .bcc-bubble {
            max-width: 80%;
            padding: 10px 15px;
            border-radius: 15px;
            display: inline-block;
        }
        .bcc-input-area {
            display: flex;
            gap: 10px;
            padding: 15px;
            background: #fff;
            border-top: 1px solid #e0e0e0;
        }
        .bcc-input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            border-radius: 20px;
            outline: none;
        }
        .bcc-send {
            background: #6366f1;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 20px;
            cursor: pointer;
        }
    </style>
    
    <script>
    (function() {
        var container = document.querySelector('.bizcity-character-chat[data-character-id="<?php echo $character->id; ?>"]');
        var input = container.querySelector('.bcc-input');
        var sendBtn = container.querySelector('.bcc-send');
        var messages = container.querySelector('.bcc-messages');
        
        function sendMessage() {
            var text = input.value.trim();
            if (!text) return;
            
            // Add user message
            var userMsg = document.createElement('div');
            userMsg.className = 'bcc-message user';
            userMsg.innerHTML = '<div class="bcc-bubble">' + text + '</div>';
            messages.appendChild(userMsg);
            messages.scrollTop = messages.scrollHeight;
            
            input.value = '';
            
            // Send to API
            fetch('<?php echo rest_url('bizcity-knowledge/v1/characters/' . $character->id . '/query'); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ query: text })
            })
            .then(r => r.json())
            .then(data => {
                var botMsg = document.createElement('div');
                botMsg.className = 'bcc-message bot';
                botMsg.innerHTML = '<div class="bcc-bubble">' + (data.data.response || 'Xin lỗi, có lỗi xảy ra.') + '</div>';
                messages.appendChild(botMsg);
                messages.scrollTop = messages.scrollHeight;
            });
        }
        
        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') sendMessage();
        });
    })();
    </script>
    <?php
    return ob_get_clean();
});
