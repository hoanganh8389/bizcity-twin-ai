<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Chat API — Hierarchical search + OpenAI API integration
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
 * fake chat function for backward compatibility
 * 
 */
// Call the ChatGPT API
if(!function_exists('chatbot_chatgpt_call_omni')) {
    function chatbot_chatgpt_call_omni($api_key, $message, $is_global = false, $id_kich_ban = false, $id_from_global = false, $kich_ban_lang = false, $context_extend= false, $ai_writing_plugin = false) {
    // For backward compatibility, just return a dummy response
        return bizcity_knowledge_chat($message, $character_id = 0, $session_id = '', $image_data = '');
    } 
} 
  
/**
 * Main chat function - Public API
 * 
 * @param string $message User message
 * @param int $character_id Character ID (0 = use default from pmfacebook_options)
 * @param string $session_id Session ID
 * @param string $image_data Base64 image data (optional)
 * @return string AI reply
 */
if ( ! function_exists( 'bizcity_knowledge_chat' ) ) :
function bizcity_knowledge_chat($message, $character_id = 0, $session_id = '', $image_data = '') {
    
    // Get default character from webchat settings
    if (empty($character_id)) {
        // Try webchat settings first
        $character_id = intval(get_option('bizcity_webchat_default_character_id', 0));
        
        // Fallback to pmfacebook_options if exists
        if (empty($character_id)) {
            $bot_setup = get_option('pmfacebook_options', []);
            $character_id = isset($bot_setup['default_character_id']) ? intval($bot_setup['default_character_id']) : 0;
        }
    }
    
    // Search for knowledge
    $knowledge_context = '';
    $image_context = '';
    
    // Image search if image provided
    if (!empty($image_data) && !empty($character_id)) {
        $image_context = bizcity_knowledge_search_image_embeddings($image_data, $character_id);
    }
    
    if (empty($character_id)) {
        // Mode 1: Legacy quick_faq search
        $knowledge_context = bizcity_knowledge_search_legacy_faq($message);
    } else {
        // Mode 2: Character knowledge search
        $knowledge_context = bizcity_knowledge_search_character($message, $character_id);
        
        // Fallback to legacy if character has no data
        if (empty($knowledge_context)) {
            $knowledge_context = bizcity_knowledge_search_legacy_faq($message);
        }
    }
    
    // Combine text and image contexts
    $combined_context = $knowledge_context;
    if (!empty($image_context)) {
        $combined_context .= "\n\n=== RELATED IMAGES ===\n\n" . $image_context;
    }
    
    // Build prompt and call OpenAI
    $reply = bizcity_knowledge_call_openai($message, $combined_context, $character_id, $session_id, $image_data);
    
    // Log conversation
    bizcity_knowledge_log_conversation($message, $reply, $character_id, $session_id);
    
    return $reply;
}
endif; // function_exists bizcity_knowledge_chat

/**
 * Search trong quick_faq posts (legacy mode)
 * 
 * @param string $query Search query
 * @return string Knowledge context
 */
function bizcity_knowledge_search_legacy_faq($query) {
    
    $args = [
        'post_type' => 'quick_faq',
        'posts_per_page' => 5,
        's' => $query, // WordPress search
        'post_status' => 'publish',
        'orderby' => 'relevance',
    ];
    
    $faq_query = new WP_Query($args);
    
    if (!$faq_query->have_posts()) {
        return '';
    }
    
    $context_parts = [];
    
    while ($faq_query->have_posts()) {
        $faq_query->the_post();
        
        $title = get_the_title();
        $content = get_the_content();
        $action = get_post_meta(get_the_ID(), '_action_faq', true);
        $link = get_post_meta(get_the_ID(), '_link_faq', true);
        
        $faq_text = "Q: {$title}\nA: {$content}";
        
        if (!empty($action)) {
            $faq_text .= "\nAction: {$action}";
        }
        
        if (!empty($link)) {
            $faq_text .= "\nLink: {$link}";
        }
        
        $context_parts[] = $faq_text;
    }
    
    wp_reset_postdata();
    
    return implode("\n\n---\n\n", $context_parts);
}

/**
 * Search trong knowledge sources của character
 * 
 * @param string $query Search query
 * @param int $character_id Character ID
 * @return string Knowledge context
 */
function bizcity_knowledge_search_character($query, $character_id) {
    // Start timing
    $search_start = microtime( true );

    global $wpdb;
    $table_sources = $wpdb->prefix . 'bizcity_knowledge_sources';
    $table_chunks = $wpdb->prefix . 'bizcity_knowledge_chunks';
    
    // Check if tables exist
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_sources'") !== $table_sources) {
        return '';
    }
    
    // Simple keyword search - tìm trong content column
    // Trong tương lai có thể nâng cấp lên vector search
    $keywords = explode(' ', $query);
    $keywords = array_slice($keywords, 0, 5); // Chỉ lấy 5 từ khóa đầu
    
    $like_conditions = [];
    foreach ($keywords as $keyword) {
        if (strlen($keyword) >= 3) { // Bỏ qua từ quá ngắn
            $like_conditions[] = $wpdb->prepare("content LIKE %s", '%' . $wpdb->esc_like($keyword) . '%');
        }
    }
    
    if (empty($like_conditions)) {
        return '';
    }
    
    $where_like = implode(' OR ', $like_conditions);
    
    $sql = $wpdb->prepare(
        "SELECT content, source_type, source_url 
         FROM $table_sources 
         WHERE character_id = %d 
         AND status = 'ready'
         AND ($where_like)
         ORDER BY id DESC
         LIMIT 5",
        $character_id
    );
    
    $results = $wpdb->get_results($sql);
    
    if (empty($results)) {
        return '';
    }
    
    $context_parts = [];
    
    foreach ($results as $row) {
        $source_info = '';
        
        if ($row->source_type === 'url' && !empty($row->source_url)) {
            $source_info = " [Source: {$row->source_url}]";
        } elseif ($row->source_type === 'legacy_faq') {
            $source_info = " [Source: Training FAQ]";
        } elseif ($row->source_type === 'file') {
            $source_info = " [Source: Uploaded File]";
        } elseif ($row->source_type === 'lifemap') {
            $source_info = " [Source: Life Map – Bản đồ cuộc đời chủ nhân]";
        }
        
        $context_parts[] = trim($row->content) . $source_info;
    }

    $result_text = implode("\n\n---\n\n", $context_parts);

    // ── Log for admin AJAX Console ──
    if ( class_exists( 'BizCity_User_Memory' ) ) {
        BizCity_User_Memory::log_router_event( [
            'step'             => 'keyword_search',
            'message'          => mb_substr( $query, 0, 120, 'UTF-8' ),
            'mode'             => 'keyword',
            'functions_called' => 'bizcity_knowledge_search_character()',
            'pipeline'         => [ 'ExtractKeywords', 'SQLSearch', 'FormatResults' ],
            'file_line'        => 'class-chat-api.php:~L210',
            'character_id'     => $character_id,
            'keywords'         => array_slice( $keywords, 0, 5 ),
            'results_count'    => count( $results ),
            'source_types'     => array_unique( array_column( $results, 'source_type' ) ),
            'context_length'   => mb_strlen( $result_text, 'UTF-8' ),
            'search_ms'        => round( ( microtime( true ) - $search_start ) * 1000, 2 ),
        ] );
    }

    return $result_text;
}

/**
 * Call OpenAI API với knowledge context
 * 
 * @param string $message User message
 * @param string $knowledge_context Knowledge from search
 * @param int $character_id Character ID
 * @param string $session_id Session ID
 * @param string $image_data Base64 image data (optional)
 * @return string AI reply
 */
function bizcity_knowledge_call_openai($message, $knowledge_context, $character_id, $session_id, $image_data = '') {
    
    // Get character info
    $character_name = 'AI Assistant';
    $character_prompt = '';
    
    if (!empty($character_id)) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        
        $character = $wpdb->get_row($wpdb->prepare(
            "SELECT name, system_prompt FROM $table WHERE id = %d",
            $character_id
        ));
        
        if ($character) {
            $character_name = $character->name;
            $character_prompt = $character->system_prompt;
        }
    }
    
    // Build system prompt
    $system_prompt = !empty($character_prompt) ? $character_prompt : "You are a helpful AI assistant named {$character_name}.";
    
    // Nhấn mạnh vai trò chuyên gia tư vấn
    if (!empty($character_id)) {
        $system_prompt .= "\n\nBạn là một chuyên gia trong lĩnh vực của mình. Hãy trả lời đầy đủ, chi tiết, chuyên sâu với giọng điệu chuyên nghiệp nhưng thân thiện. Cung cấp phân tích cụ thể, ví dụ minh họa, và lời khuyên thiết thực khi phù hợp.";
    }
    
    if (!empty($knowledge_context)) {
        $system_prompt .= "\n\n=== KIẾN THỨC CHUYÊN MÔN ===\n\n{$knowledge_context}\n\nHãy sử dụng kiến thức trên để đưa ra câu trả lời chính xác, chi tiết và hữu ích. Kết hợp với chuyên môn của bạn để tư vấn sâu. Nếu kiến thức không liên quan, hãy dựa vào chuyên môn của bạn để trả lời.";
    }
    
    // Get conversation history
    $history = bizcity_knowledge_get_history($session_id, 5);
    
    // Build messages array
    $messages = [
        ['role' => 'system', 'content' => $system_prompt]
    ];
    
    foreach ($history as $item) {
        if ($item['role'] === 'user') {
            $messages[] = ['role' => 'user', 'content' => $item['message']];
        } else {
            $messages[] = ['role' => 'assistant', 'content' => $item['message']];
        }
    }
    
    // Add current user message (with image if provided)
    if (!empty($image_data)) {
        // Use vision model with image
        $messages[] = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $message
                ],
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $image_data // Base64 data URL
                    ]
                ]
            ]
        ];
    } else {
        // Text only
        $messages[] = ['role' => 'user', 'content' => $message];
    }
    
    // Get OpenAI API key
    $api_key = get_option('twf_openai_api_key', '');
    
    if (empty($api_key)) {
        return 'Xin lỗi, hệ thống chưa được cấu hình API key. Vui lòng liên hệ quản trị viên.';
    }
    
    // Select model - use vision model if image provided
    $model = get_option('openai_model', 'gpt-4o-mini');
    if (!empty($image_data)) {
        // Force vision-capable model
        $model = 'gpt-4o'; // gpt-4o supports vision
    }
    
    // Call OpenAI API
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body' => json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.8,
            'max_tokens' => 3000,
        ])
    ]);
    
    if (is_wp_error($response)) {
        return 'Xin lỗi, có lỗi kết nối đến OpenAI. Vui lòng thử lại sau.';
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['choices'][0]['message']['content'])) {
        return trim($body['choices'][0]['message']['content']);
    }
    
    if (isset($body['error']['message'])) {
        error_log('OpenAI API Error: ' . $body['error']['message']);
        return 'Xin lỗi, có lỗi từ OpenAI API. Vui lòng thử lại sau.';
    }
    
    return 'Xin lỗi, không nhận được phản hồi từ AI. Vui lòng thử lại sau.';
}

/**
 * Get conversation history from session
 * 
 * @param string $session_id Session ID
 * @param int $limit Number of messages
 * @return array History
 */
function bizcity_knowledge_get_history($session_id, $limit = 10) {
    
    if (empty($session_id)) {
        return [];
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_knowledge_conversations';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        return [];
    }
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT role, message FROM $table 
         WHERE session_id = %s 
         ORDER BY created_at DESC 
         LIMIT %d",
        $session_id,
        $limit
    ), ARRAY_A);
    
    return array_reverse($results); // Đảo ngược để cũ → mới
}

/**
 * Log conversation to database
 * 
 * @param string $user_message User message
 * @param string $ai_reply AI reply
 * @param int $character_id Character ID
 * @param string $session_id Session ID
 */
function bizcity_knowledge_log_conversation($user_message, $ai_reply, $character_id, $session_id) {
    
    global $wpdb;
    $table = $wpdb->prefix . 'bizcity_knowledge_conversations';
    
    // Create table if not exists
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        session_id varchar(255) NOT NULL,
        character_id bigint(20) unsigned DEFAULT 0,
        user_id bigint(20) unsigned DEFAULT 0,
        role varchar(20) NOT NULL,
        message longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY session_id (session_id),
        KEY character_id (character_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    
    // Insert user message
    $wpdb->insert($table, [
        'session_id' => $session_id,
        'character_id' => $character_id,
        'user_id' => get_current_user_id(),
        'role' => 'user',
        'message' => $user_message,
    ]);
    
    // Insert AI reply
    $wpdb->insert($table, [
        'session_id' => $session_id,
        'character_id' => $character_id,
        'user_id' => get_current_user_id(),
        'role' => 'assistant',
        'message' => $ai_reply,
    ]);
}

/**
 * Search trong image embeddings của character
 * 
 * @param string $image_data Base64 image data
 * @param int $character_id Character ID
 * @return string Image context
 */
function bizcity_knowledge_search_image_embeddings($image_data, $character_id) {
    
    global $wpdb;
    $table_chunks = $wpdb->prefix . 'bizcity_knowledge_chunks';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_chunks'") !== $table_chunks) {
        return '';
    }
    
    // For now, just search for image chunks by character
    // In the future, implement actual vector similarity search with OpenAI embeddings
    
    $sql = $wpdb->prepare(
        "SELECT c.chunk_text, c.chunk_metadata, s.source_url, s.source_type
         FROM $table_chunks c
         LEFT JOIN {$wpdb->prefix}bizcity_knowledge_sources s ON c.source_id = s.id
         WHERE s.character_id = %d 
         AND s.status = 'ready'
         AND (c.chunk_metadata LIKE %s OR c.chunk_metadata LIKE %s)
         ORDER BY c.id DESC
         LIMIT 3",
        $character_id,
        '%image%',
        '%photo%'
    );
    
    $results = $wpdb->get_results($sql);
    
    if (empty($results)) {
        return '';
    }
    
    $context_parts = [];
    
    foreach ($results as $row) {
        $metadata = json_decode($row->chunk_metadata, true);
        $image_info = '';
        
        if (isset($metadata['image_url'])) {
            $image_info = "Image URL: {$metadata['image_url']}";
        }
        
        if (isset($metadata['alt_text'])) {
            $image_info .= "\nAlt Text: {$metadata['alt_text']}";
        }
        
        if (isset($metadata['caption'])) {
            $image_info .= "\nCaption: {$metadata['caption']}";
        }
        
        if (!empty($row->chunk_text)) {
            $image_info .= "\nContext: {$row->chunk_text}";
        }
        
        if (!empty($row->source_url)) {
            $image_info .= "\n[Source: {$row->source_url}]";
        }
        
        $context_parts[] = $image_info;
    }
    
    return implode("\n\n---\n\n", $context_parts);
}
