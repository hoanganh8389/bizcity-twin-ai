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
 * bizgpt_chatbot_fallback_ai_response() - Fallback AI response
 * 
 * Gọi OpenAI để lấy câu trả lời fallback
 * Migrate từ bizgpt-agent.php
 * 
 * @param string $api_key OpenAI API key (nếu trống sẽ dùng option)
 * @param string $question Câu hỏi
 * @return string Reply từ AI
 */
if (!function_exists('bizgpt_chatbot_fallback_ai_response')) :
function bizgpt_chatbot_fallback_ai_response($api_key, $question) {
    // Ưu tiên sử dụng BizCity_WebChat_AI nếu có
    if (class_exists('BizCity_WebChat_AI')) {
        $ai = BizCity_WebChat_AI::instance();
        return $ai->get_fallback_response($question, $api_key);
    }
    
    // Fallback: gọi trực tiếp OpenAI
    if (empty($api_key)) {
        $api_key = get_option('twf_openai_api_key');
    }
    
    if (empty($api_key)) {
        return 'Xin lỗi, hệ thống chưa được cấu hình API key.';
    }
    
    // Gọi OpenAI trực tiếp
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => 'gpt-3.5-turbo',
            'messages' => [
                ['role' => 'system', 'content' => 'Bạn là trợ lý AI hữu ích, trả lời ngắn gọn và thân thiện.'],
                ['role' => 'user', 'content' => $question],
            ],
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]),
        'timeout' => 30,
    ]);
    
    if (is_wp_error($response)) {
        return 'Xin lỗi, có lỗi kết nối đến AI.';
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    return $body['choices'][0]['message']['content'] ?? 'Xin lỗi, không thể xử lý câu hỏi của bạn.';
}
endif;

/**
 * bizgpt_chatbot_run_guest_flows() - Full implementation
 * 
 * Migrate từ bizgpt-agent/float_chatbot_front.php
 * Logic flow: Tùy biến theo xem sản phẩm, tìm kiếm, đặt hàng ...
 * 
 * @param string $question Câu hỏi từ khách
 * @param string $platform Platform (webchat, zalo, FB_MESS, etc)
 * @param mixed $data_hook Data từ hook (client_id, page_id, conversation)
 * @param string $context Context bổ sung
 * @return array Messages array
 */
if (!function_exists('bizgpt_chatbot_run_guest_flows')) :
function bizgpt_chatbot_run_guest_flows($question, $platform = 'webchat', $data_hook = '', $context = '') {
    global $bot_setup;  // Setup bot từ file bizgpt/setup_bot.php
    
    $api_key    = get_option('twf_openai_api_key');
    $identity   = function_exists('bizgpt_get_webchat_identity') 
                    ? bizgpt_get_webchat_identity() 
                    : ['user_id' => get_current_user_id(), 'session_id' => session_id()];
    $user_id    = $identity['user_id'];
    $session_id = $identity['session_id'];
    
    // From zalo và facebook
    $client_id   = @$data_hook['client_id'];
    $page_id     = @$data_hook['page_id'];
    $client_name = @$data_hook['conversation']['client_name'];
    
    // Lưu vào transient để có thể dùng lại sau này
    set_transient('hook_data', [
        'user_id'     => $user_id,
        'client_id'   => $client_id,
        'session_id'  => $session_id,
        'page_id'     => $page_id,
        'platform'    => $platform,
        'client_name' => $client_name,
    ], 10 * MINUTE_IN_SECONDS);
    
    // 1) Log lại tin nhắn user
    if (function_exists('bizgpt_log_chat_message')) {
        bizgpt_log_chat_message($user_id, $question, 'user', $session_id);
    }
    
    $current_blog_id = get_current_blog_id();
    $blog_name = get_bloginfo('name');
    $blog_detail = function_exists('get_blog_details') ? get_blog_details($current_blog_id) : null;
    $blog_domain = is_object($blog_detail) ? $blog_detail->domain : '';
    
    // 2) Gửi admin Telegram (nếu cần)
    if ($platform && function_exists('twf_telegram_send_message') && function_exists('twf_list_client_ids_by_blog_id')) {
        $msg = "💬 <b>Khách từ $platform: </b><code>$blog_name - $blog_domain</code> \n"
             . "🗨️ <b>Khách nhắn:</b> $question\n\n"
             . "👤 <b>user_id:</b> <code>$user_id</code>\n"
             . "👤 <b>Tên khách:</b> <code>$client_name</code>\n"
             . "👤 <b>Mã định danh:</b> <code>$client_id</code>\n"
             . "🔑 <b>Mã id phiên:</b> <code>$session_id</code>\n"
             . "📩 <b>Hướng dẫn:</b>\n"
             . "Nhắn: <code>Trả lời tới khách session_id: $session_id nội dung là: ...</code>\n"
             . "Em sẽ gửi tin nhắn cho khách giúp sếp 🧠";
        
        $chat_ids = twf_list_client_ids_by_blog_id(get_current_blog_id());
        foreach ($chat_ids as $chat_id) {
            twf_telegram_send_message($chat_id, $msg, 'HTML', null);
        }
    }
    
    // 3) Ưu tiên logic theo setup: shortcode -> text -> ai
    $using_flow_shortcode    = !empty($bot_setup['using_flow_shortcode']);
    $using_flow_text         = !empty($bot_setup['using_flow_text']);
    $using_ai                = !empty($bot_setup['using_ai']);
    
    $using_fb_flow_shortcode = !empty($bot_setup['using_fb_flow_shortcode']);
    $using_fb_flow_text      = !empty($bot_setup['using_fb_flow_text']);
    $using_fb_ai             = !empty($bot_setup['using_fb_ai']);
    
    // Xác định kênh Facebook
    $is_fb = in_array((string)$platform, ['FB_MESS','FB_COMMENT','FB','facebook'], true);
    
    // Cờ hiệu lực theo kênh (WEB vs FB)
    $allow_shortcode = $is_fb ? $using_fb_flow_shortcode : $using_flow_shortcode;
    $allow_text      = $is_fb ? $using_fb_flow_text      : $using_flow_text;
    $allow_ai        = $is_fb ? $using_fb_ai             : $using_ai;
    
    // (1) using_flow_shortcode: ưu tiên custom flow + return luôn
    if (function_exists('bizgpt_handle_guest_flow') && function_exists('bizgpt_match_custom_flow')) {
        $key = "bizgpt_flow_ctx_$session_id";
        $ctx = get_transient($key) ?: ['flow_id' => 0, 'params' => []];
        
        // Luôn cố gắng match intent & trích params mới từ câu hỏi
        $cf = bizgpt_match_custom_flow($question);
        
        if (!empty($cf['flow_id'])) {
            $ctx['flow_id'] = intval($cf['flow_id']);
            $ctx['params']  = array_merge($ctx['params'], $cf['output']['params'] ?? []);
            set_transient($key, $ctx, 10 * MINUTE_IN_SECONDS);
            
            // Gán flow_id vào inbox (zalo/fb) để cron dùng
            if (function_exists('bizgpt_inbox_assign_flow_id')) {
                bizgpt_inbox_assign_flow_id($cf['flow_id'], $client_id, $platform, $page_id);
            }
        }
        
        // Nếu đã có flow_id => handle và return luôn
        if (!empty($ctx['flow_id'])) {
            $msgs = bizgpt_handle_guest_flow($question);
            if (!empty($msgs)) {
                return bizgpt_return_or_ajax($msgs, $platform);
            }
        }
    }
    
    // (2) TEXT FLOW: ưu tiên theo kênh
    if ($allow_text && function_exists('bizgpt_parse_comment_flow') && function_exists('bizgpt_comment_flow_reply_by_router')) {
        $allow_dynamic_ai_in_text = $is_fb ? $allow_ai : false;
        
        $parsed = bizgpt_parse_comment_flow($question, $allow_dynamic_ai_in_text);
        $router = $parsed['router'] ?? 'default_flow';
        $reply  = $parsed['reply']  ?? '';
        
        $msg = bizgpt_comment_flow_reply_by_router($router, (array)$data_hook);
        
        if (empty($msg) && !empty($reply)) {
            $msg = $reply;
        }
        
        // Match được kịch bản => trả luôn
        if (!empty($msg)) {
            $rec = function_exists('bizgpt_log_chat_message') 
                    ? bizgpt_log_chat_message($user_id, $msg, 'bot', $session_id)
                    : ['msg_text' => $msg, 'msg_from' => 'bot'];
            return bizgpt_return_or_ajax([$rec], $platform);
        }
        
        // Không match text flow
        if (!(!$is_fb && $allow_ai)) {
            $text = $is_fb
                ? ''
                : 'Xin lỗi, mình chưa tìm thấy nội dung phù hợp. Bạn thử nhập từ khóa rõ hơn giúp mình nhé.';
            $rec = function_exists('bizgpt_log_chat_message')
                    ? bizgpt_log_chat_message($user_id, $text, 'bot', $session_id)
                    : ['msg_text' => $text, 'msg_from' => 'bot'];
            return bizgpt_return_or_ajax([$rec], $platform);
        }
    }
    
    // (3) AI: chỉ chạy nếu cờ theo kênh cho phép
    if ($allow_ai) {
        $ctxText = '';
        if ($client_name) {
            $ctxText .= 'Tôi tên là ' . $client_name . '. ';
        }
        if ($context) {
            $ctxText .= $context;
        }
        
        // Thử sử dụng Knowledge Character trước
        $character_id = get_option('bizcity_knowledge_default_character');
        if ($character_id && class_exists('BizCity_Character')) {
            $result = bizcity_query_character($character_id, $question, [
                'platform' => $platform,
                'data_hook' => $data_hook,
                'context' => $ctxText,
            ]);
            $text = $result['response'] ?? '';
        }
        
        // Fallback vào AI nếu không có character hoặc không có kết quả
        if (empty($text)) {
            if (function_exists('chatbot_chatgpt_call_omni')) {
                $text = chatbot_chatgpt_call_omni($api_key, $ctxText . $question);
            } else {
                $text = bizgpt_chatbot_fallback_ai_response($api_key, $ctxText . $question);
            }
        }
        
        $rec = function_exists('bizgpt_log_chat_message')
                ? bizgpt_log_chat_message($user_id, $text, 'bot', $session_id)
                : ['msg_text' => $text, 'msg_from' => 'bot'];
        $msgs = [$rec];
        
    } else {
        // (4) Không cho trả lời theo kênh này
        $text = $is_fb
            ? 'Xin lỗi, hiện tại fanpage chưa bật chế độ trả lời tự động.'
            : 'Xin lỗi, hiện tại bot chưa được thiết lập để trả lời (AI đang tắt).';
        
        $rec = function_exists('bizgpt_log_chat_message')
                ? bizgpt_log_chat_message($user_id, $text, 'bot', $session_id)
                : ['msg_text' => $text, 'msg_from' => 'bot'];
        $msgs = [$rec];
    }
    
    // 5) Gửi thông báo đến admin Zalo
    if ($platform === 'webchat' && function_exists('send_notice_to_zalo_admin')) {
        $current_user = wp_get_current_user();
        $user_name = $current_user ? $current_user->user_login : '';
        foreach ($msgs as $m) {
            send_notice_to_zalo_admin($text ?? '', $session_id, $user_name, $blog_domain, 'Web Chat');
        }
    }
    
    // 6) Trả về
    $msgs = apply_filters('bizgpt_after_handle_guest_flows', $msgs, $platform, $question);
    
    foreach ($msgs as &$m) {
        $m['transcript'] = $question;
    }
    
    return bizgpt_return_or_ajax($msgs, $platform);
}
endif;

//Cập nhật khi đang xử lý chatbot và có flow_id
function bizgpt_inbox_assign_flow_id($flow_id, $client_id, $platform='FB_MESS', $page_id='') {
    global $wpdb;
    // Lấy cấu hình page_id nếu cần lọc
    $bot_setup = get_option('pmfacebook_options'); // Hoặc chỗ bạn lưu page_id
   # $page_id = $bot_setup['pageid'] ?? '';

    // Nếu không đủ dữ liệu thì bỏ qua
   # if (!$client_id || !$platform || !$page_id) return;

    $table = $wpdb->prefix . 'bizcity_facebook_inbox'; // bizcity-facebook-bot đã mirgrate từ bizgpt_inbox sang bizcity_facebook_inbox

    // Update message gần nhất của client trên platform đó<br />
	$sql = $wpdb->prepare("
        UPDATE $table
        SET flow_id = %d
        WHERE client_id = %s AND message_type = 'client'  AND platform_type = %s AND page_id = %s
        ORDER BY id DESC
        LIMIT 1
    ", $flow_id, $client_id, $platform, $page_id);
	back_trace('NOTICE', 'bizgpt_inbox_assign_flow_id: '.$sql);
    $wpdb->query($sql);
	
}
/**
 * bizgpt_return_or_ajax() - Helper function
 * 
 * Nếu platform = webchat VÀ đang là AJAX request thì gửi JSON response
 * Ngược lại trả về mảng để caller xử lý tiếp
 * 
 * @param array $msgs Messages
 * @param string $platform Platform
 * @return array|void
 */
if (!function_exists('bizgpt_return_or_ajax')) :
function bizgpt_return_or_ajax(array $msgs, string $platform) {
    // Chỉ send JSON và exit nếu là AJAX request từ frontend
    // Nếu được gọi từ internal code (như webchat trigger), return array thay vì exit
    if ($platform === 'webchat' && wp_doing_ajax() && !defined('BIZCITY_WEBCHAT_INTERNAL_CALL')) {
        wp_send_json_success($msgs);
        exit;
    }
    return $msgs;
}
endif;

/**
 * Shortcode: Embed character chat widget
 * Usage: [bizcity_character_chat id="1"]
 */
add_shortcode('bizcity_character_chat', function($atts) {
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
