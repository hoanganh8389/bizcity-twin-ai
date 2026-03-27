<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Unified Admin Chat — AJAX endpoint for all admin chat interfaces
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Lưu trữ thống nhất vào bảng bizcity_webchat_messages.
 *
 * @package BizCity_Knowledge
 * @since   1.2.8
 */

defined('ABSPATH') or die('OOPS...');

class BizCity_Admin_Chat {

    /* ─── Singleton ─── */
    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* ─── Constructor ─── */
    public function __construct() {
        // DEPRECATED: AJAX endpoints and localize_vars moved to BizCity_Chat_Gateway (class-chat-gateway.php)
        // Old actions bizcity_admin_chat_send/history/clear are now registered there.
        // This class is kept for get_ai_response_public() only.
    }

    /* ================================================================
     * JS vars – dùng cho cả widget-admin.php và chat.php
     * ================================================================ */
    public function localize_vars() {
        if (!is_admin()) return;

        // Get default character
        $character_id = $this->get_default_character_id();
        $character     = null;
        $characters    = [];

        if (class_exists('BizCity_Knowledge_Database')) {
            $db = BizCity_Knowledge_Database::instance();
            if ($character_id) {
                $character = $db->get_character($character_id);
            }
            // Get all active characters for the selector
            $chars_raw = $db->get_characters(['status' => 'active', 'limit' => 100]);
            foreach ($chars_raw as $ch) {
                $characters[] = [
                    'id'     => (int) $ch->id,
                    'name'   => $ch->name,
                    'avatar' => $ch->avatar ?: '',
                    'model'  => $ch->model_id ?: 'GPT-4o-mini',
                ];
            }
        }

        $data = [
            'ajaxurl'        => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('bizcity_admin_chat'),
            'session_id'     => $this->get_session_id(),
            'user_id'        => get_current_user_id(),
            'character_id'   => $character_id,
            'character_name' => $character ? $character->name : 'AI Assistant',
            'characters'     => $characters,
            'chat_page_url'  => admin_url('admin.php?page=bizcity-knowledge-chat'),
        ];

        // Localize to jQuery (available everywhere in admin)
        wp_localize_script('jquery', 'bizcity_admin_chat_vars', $data);
    }

    /* ================================================================
     * AJAX: Send message
     * ================================================================ */
    public function ajax_send() {
        check_ajax_referer('bizcity_admin_chat', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $message      = sanitize_textarea_field($_POST['message'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        $raw_images   = json_decode(stripslashes($_POST['images'] ?? '[]'), true);
        // Convert base64 images to Media Library URLs
        if ( function_exists( 'bizcity_convert_images_to_media_urls' ) ) {
            $images = bizcity_convert_images_to_media_urls( $raw_images ?: [] );
        } else {
            $images = $raw_images ?: [];
        }
        $history_json = stripslashes($_POST['history'] ?? '[]');

        if (!$character_id) {
            $character_id = $this->get_default_character_id();
        }

        if (!$message && empty($images)) {
            wp_send_json_error(['message' => 'Tin nhắn trống']);
        }

        $session_id = $this->get_session_id();
        $user_id    = get_current_user_id();
        $user       = wp_get_current_user();
        $client_name = $user->display_name ?: $user->user_login;

        /* ── Log user message ── */
        $this->log_message([
            'session_id'   => $session_id,
            'user_id'      => $user_id,
            'client_name'  => $client_name,
            'message_id'   => uniqid('adm_'),
            'message_text' => $message ?: '[Image]',
            'message_from' => 'user',
            'message_type' => !empty($images) ? 'image' : 'text',
            'attachments'  => $images,
            'platform_type' => 'ADMINCHAT',
        ]);

        /* ── Get AI response via knowledge plugin ── */
        $reply_data = $this->get_ai_response($character_id, $message, $images, $session_id, $history_json);

        /* ── Log bot reply ── */
        $this->log_message([
            'session_id'   => $session_id,
            'user_id'      => 0,
            'client_name'  => $reply_data['character_name'] ?? 'AI Assistant',
            'message_id'   => uniqid('adm_bot_'),
            'message_text' => $reply_data['message'],
            'message_from' => 'bot',
            'message_type' => 'text',
            'platform_type' => 'ADMINCHAT',
            'meta'         => [
                'provider'    => $reply_data['provider'] ?? '',
                'model'       => $reply_data['model'] ?? '',
                'usage'       => $reply_data['usage'] ?? [],
                'vision_used' => $reply_data['vision_used'] ?? false,
                'character_id' => $character_id,
            ],
        ]);

        wp_send_json_success([
            'message'      => $reply_data['message'],
            'provider'     => $reply_data['provider'] ?? '',
            'model'        => $reply_data['model'] ?? '',
            'usage'        => $reply_data['usage'] ?? [],
            'vision_used'  => $reply_data['vision_used'] ?? false,
        ]);
    }

    /* ================================================================
     * AJAX: Get history
     * ================================================================ */
    public function ajax_history() {
        check_ajax_referer('bizcity_admin_chat', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $session_id   = sanitize_text_field($_POST['session_id'] ?? '');
        $character_id = intval($_POST['character_id'] ?? 0);
        $limit        = intval($_POST['limit'] ?? 50);

        if (!$session_id) {
            $session_id = $this->get_session_id();
        }

        $history = $this->get_history($session_id, $limit);

        wp_send_json_success($history);
    }

    /* ================================================================
     * AJAX: Clear history
     * ================================================================ */
    public function ajax_clear() {
        check_ajax_referer('bizcity_admin_chat', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        if (!$session_id) {
            $session_id = $this->get_session_id();
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        // Check table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->delete($table, [
                'session_id'    => $session_id,
                'platform_type' => 'ADMINCHAT',
            ]);
        }

        // Also clear conversation record
        $conv_table = $wpdb->prefix . 'bizcity_webchat_conversations';
        if ($wpdb->get_var("SHOW TABLES LIKE '$conv_table'") === $conv_table) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$conv_table} SET status = 'closed', ended_at = NOW() WHERE session_id = %s AND platform_type = 'ADMINCHAT'",
                $session_id
            ));
        }

        wp_send_json_success(['cleared' => true]);
    }

    /* ================================================================
     * Core: Get AI response (public wrapper for external callers)
     * 
     * Used by bizcity-bot-webchat to share the same knowledge pipeline.
     * ================================================================ */
    public function get_ai_response_public($character_id, $message, $images = [], $session_id = '', $history_json = '[]') {
        return $this->get_ai_response($character_id, $message, $images, $session_id, $history_json);
    }

    /* ================================================================
     * Core: Get AI response
     * ================================================================ */
    private function get_ai_response($character_id, $message, $images, $session_id, $history_json = '[]') {
        $result = [
            'message'        => '',
            'character_name' => 'AI Assistant',
            'provider'       => '',
            'model'          => '',
            'usage'          => [],
            'vision_used'    => false,
        ];

        // Get character
        if (!class_exists('BizCity_Knowledge_Database')) {
            $result['message'] = 'Hệ thống Knowledge chưa sẵn sàng.';
            return $result;
        }

        $db = BizCity_Knowledge_Database::instance();
        $character = $character_id ? $db->get_character($character_id) : null;

        if ($character) {
            $result['character_name'] = $character->name;
        }

        // ── Profile Context (user identity — highest priority) ──
        $profile_context = '';
        $transit_context = '';
        if (class_exists('BizCity_Profile_Context')) {
            $user_id_for_profile = get_current_user_id();
            $profile_ctx_instance = BizCity_Profile_Context::instance();
            $profile_context = $profile_ctx_instance->build_user_context(
                $user_id_for_profile,
                $session_id,
                'ADMINCHAT',
                ['coach_type' => '']
            );

            // ── Transit Context (triggered by message intent) ──
            $transit_context = $profile_ctx_instance->build_transit_context(
                $message,
                $user_id_for_profile,
                $session_id,
                'ADMINCHAT'
            );
        }

        // Use Context API for knowledge search (embeddings / quick knowledge)
        $knowledge_context = '';
        if (class_exists('BizCity_Knowledge_Context_API')) {
            $context_api = BizCity_Knowledge_Context_API::instance();
            $ctx = $context_api->build_context($character_id, $message, [
                'max_tokens'     => 3000,
                'include_vision' => !empty($images),
                'images'         => $images,
            ]);
            $knowledge_context = $ctx['context'] ?? '';
        }

        // Supplement: always call bizcity_knowledge_search_character for keyword-based search
        // Ensures all chat paths share the same character knowledge lookup
        if ($character_id && function_exists('bizcity_knowledge_search_character')) {
            $char_keyword_ctx = bizcity_knowledge_search_character($message, $character_id);
            if (!empty($char_keyword_ctx)) {
                if (!empty($knowledge_context)) {
                    // Merge: avoid duplicates by appending only if not already contained
                    if (strpos($knowledge_context, $char_keyword_ctx) === false) {
                        $knowledge_context .= "\n\n---\n\n### Kiến thức bổ sung (keyword search):\n" . $char_keyword_ctx;
                    }
                } else {
                    $knowledge_context = $char_keyword_ctx;
                }
            }
        } elseif ($character_id && !class_exists('BizCity_Knowledge_Context_API')) {
            // Fallback when Context API is not available
            if (function_exists('bizcity_knowledge_search_character')) {
                $knowledge_context = bizcity_knowledge_search_character($message, $character_id);
            }
        }

        // Model
        $model_id = ($character && !empty($character->model_id)) ? $character->model_id : '';
        $supports_vision = false;
        if (class_exists('BizCity_Knowledge_Context_API') && !empty($model_id)) {
            $supports_vision = BizCity_Knowledge_Context_API::instance()->model_supports_vision($model_id);
        } elseif (empty($model_id)) {
            $supports_vision = true; // gpt-4o-mini supports vision
        }

        // Build messages
        $openai_messages = [];

        // System prompt
        $system_content = '';
        if ($character && !empty($character->system_prompt)) {
            $system_content = $character->system_prompt;
        }
        // Inject profile context FIRST (user identity — factual, highest priority)
        if (!empty($profile_context)) {
            $system_content .= "\n\n---\n\n" . $profile_context;
        }
        // Inject transit context (astrology forecast triggered by user intent)
        if (!empty($transit_context)) {
            $system_content .= "\n\n---\n\n" . $transit_context;
        }
        if (!empty($knowledge_context)) {
            $system_content .= "\n\n---\n\n## Kiến thức tham khảo:\n" . $knowledge_context;
        }

        // Final behavioral instruction — MUST be at the end of system prompt
        $system_content .= "\n\n---\n\n## QUY TẮC TRẢ LỜI (BẮT BUỘC — ƯU TIÊN CAO NHẤT):\n";

        if (!empty($profile_context)) {
            $system_content .= "### 📌 Nhận diện người dùng:\n";
            $system_content .= "1. Bạn ĐÃ BIẾT người đang trò chuyện thông qua Hồ Sơ Chủ Nhân ở trên. ";
            $system_content .= "Khi họ hỏi \"tôi là ai\", \"bạn biết tôi không\", hãy trả lời TỰ TIN dựa trên hồ sơ (ví dụ: \"Dạ, bạn là [tên], ...\").\n";
            $system_content .= "2. Luôn gọi người dùng bằng TÊN khi có thể, thể hiện sự thân thiện và cá nhân hóa.\n\n";

            // CORE GROUNDING RULES — always active when profile data exists
            $system_content .= "### 🔒 NỀN TẢNG TRẢ LỜI — LUÔN BÁM THEO DỮ LIỆU:\n";
            $system_content .= "🔴 **QUY TẮC CỐT LÕI**: Mọi câu trả lời về cuộc sống, tương lai, tính cách, sự nghiệp, tài chính, tình cảm, hôn nhân, sức khỏe, tiền bạc, tinh duyên, ngày mai, tuần tới, tháng tới, năm tới ĐỀU PHẢI dựa trên:\n";
            $system_content .= "   a) **Bản đồ chiêm tinh natal** (vị trí các sao lúc sinh) — đã có trong Hồ Sơ Chủ Nhân\n";
            $system_content .= "   b) **Kết quả luận giải (gen_results)** — phân tích SWOT, thần số học, ngũ hành... đã có trong Hồ Sơ\n";
            $system_content .= "   c) **Câu trả lời coaching (answer_json)** — thông tin user tự khai trong các bước tư vấn\n";
            if (!empty($transit_context)) {
                $system_content .= "   d) **Dữ liệu Transit chiêm tinh** — vị trí THỰC TẾ các sao trên bầu trời đã được cung cấp ở trên\n";
            }
            $system_content .= "\n";
            $system_content .= "🚫 **CẤM TUYỆT ĐỐI**:\n";
            $system_content .= "   - KHÔNG được dùng Tarot, bói bài, rút lá, bói toán, bói dịch, hay bất kỳ phương pháp bói nào khác\n";
            $system_content .= "   - KHÔNG được bịa đặt vị trí sao, góc chiếu, hay dữ liệu chiêm tinh không có trong hồ sơ\n";
            $system_content .= "   - KHÔNG được trả lời chung chung mà không tham chiếu dữ liệu cụ thể từ hồ sơ của user\n\n";

            $system_content .= "✅ **YÊU CẦU BẮT BUỘC khi trả lời về tương lai/dự báo/xu hướng/chủ đề cuộc sống**:\n";
            $system_content .= "   - Luôn nhắc TÊN SAO cụ thể + CUNG + GÓC CHIẾU khi phân tích\n";
            $system_content .= "   - Liên hệ trực tiếp với natal chart và gen_results của user\n";
            $system_content .= "   - Tham chiếu các câu trả lời coaching (answer_json) khi liên quan đến chủ đề hỏi\n";
            if (!empty($transit_context)) {
                $system_content .= "   - Sử dụng DỮ LIỆU TRANSIT THỰC TẾ đã cung cấp — nêu rõ sao nào đang ở cung nào, tạo góc chiếu gì với natal\n";
            }
            $system_content .= "\n";
        }

        if (!empty($transit_context)) {
            $system_content .= "### ⭐ ĐẶC BIỆT — DỮ LIỆU TRANSIT:\n";
            $system_content .= "Dữ liệu transit chiêm tinh THỰC TẾ đã được cung cấp phía trên. Bạn PHẢI:\n";
            $system_content .= "- Phân tích dựa HOÀN TOÀN trên vị trí transit thực tế + natal chart\n";
            $system_content .= "- Giải thích cụ thể: sao transit nào, ở cung nào, tạo góc chiếu gì, ảnh hưởng gì\n";
            $system_content .= "- Liên hệ với gen_results và answer_json để cá nhân hóa dự báo\n";
            $system_content .= "- Tuyệt đối KHÔNG chuyển sang Tarot, bói bài, hay phương pháp khác\n\n";
        }

        if (!empty($knowledge_context)) {
            $system_content .= "### 📚 Kiến thức: Ưu tiên sử dụng kiến thức tham khảo để trả lời chính xác. Nếu không có trong kiến thức, trả lời dựa trên hiểu biết chung.\n";
        }
        $system_content .= "### 🗣️ Ngôn ngữ: Trả lời bằng tiếng Việt, thân thiện, tự nhiên.\n";

        if (empty(trim($system_content))) {
            $system_content = "You are a helpful AI assistant. Trả lời ngắn gọn, chính xác bằng tiếng Việt.";
        }
        $openai_messages[] = ['role' => 'system', 'content' => $system_content];

        // History from DB (more reliable than client)
        $db_history = $this->get_history($session_id, 10);
        foreach ($db_history as $msg) {
            $role = ($msg['from'] === 'user') ? 'user' : 'assistant';
            $openai_messages[] = ['role' => $role, 'content' => $msg['msg']];
        }

        // Current message (with images if applicable)
        if (!empty($images) && $supports_vision) {
            $content = [];
            $content[] = ['type' => 'text', 'text' => $message ?: 'Hãy mô tả hoặc phân tích hình ảnh này.'];
            foreach ($images as $img) {
                $url = is_string($img) ? $img : ($img['url'] ?? $img['data'] ?? '');
                if ($url) {
                    $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'auto']];
                }
            }
            $openai_messages[] = ['role' => 'user', 'content' => $content];
            $result['vision_used'] = true;
        } else {
            $openai_messages[] = ['role' => 'user', 'content' => $message];
        }

        // Route to OpenRouter or OpenAI
        if ($character && !empty($character->model_id)) {
            $reply_data = $this->call_openrouter($character, $openai_messages);
        } else {
            $reply_data = $this->call_openai($openai_messages);
        }

        $result['message']  = $reply_data['message'] ?? 'Xin lỗi, không nhận được phản hồi.';
        $result['provider'] = $reply_data['provider'] ?? '';
        $result['model']    = $reply_data['model'] ?? '';
        $result['usage']    = $reply_data['usage'] ?? [];

        return $result;
    }

    /* ================================================================
     * Call OpenAI API
     * ================================================================ */
    private function call_openai($messages) {
        $api_key = get_option('twf_openai_api_key', '');

        if (empty($api_key)) {
            return ['message' => 'Hệ thống chưa cấu hình API key. Vui lòng liên hệ quản trị viên.', 'provider' => 'openai'];
        }

        $model = get_option('openai_model', 'gpt-4o-mini');

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => 0.7,
                'max_tokens'  => 3000,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['message' => 'Lỗi kết nối: ' . $response->get_error_message(), 'provider' => 'openai'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['choices'][0]['message']['content'])) {
            return [
                'message'  => trim($body['choices'][0]['message']['content']),
                'provider' => 'openai',
                'model'    => $model,
                'usage'    => $body['usage'] ?? [],
            ];
        }

        if (isset($body['error']['message'])) {
            error_log('[AdminChat] OpenAI error: ' . $body['error']['message']);
        }

        return ['message' => 'Xin lỗi, không nhận được phản hồi từ AI.', 'provider' => 'openai'];
    }

    /* ================================================================
     * Call OpenRouter API
     * ================================================================ */
    private function call_openrouter($character, $messages) {
        $api_key = get_option('bizcity_openrouter_api_key', '');

        if (empty($api_key)) {
            // Fallback to OpenAI
            return $this->call_openai($messages);
        }

        $model = $character->model_id;

        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => floatval($character->creativity_level ?? 0.7),
                'max_tokens'  => 3000,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['message' => 'Lỗi kết nối OpenRouter: ' . $response->get_error_message(), 'provider' => 'openrouter'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['choices'][0]['message']['content'])) {
            return [
                'message'  => trim($body['choices'][0]['message']['content']),
                'provider' => 'openrouter',
                'model'    => $model,
                'usage'    => $body['usage'] ?? [],
            ];
        }

        if (isset($body['error']['message'])) {
            error_log('[AdminChat] OpenRouter error: ' . $body['error']['message']);
            // Fallback to OpenAI
            return $this->call_openai($messages);
        }

        return ['message' => 'Xin lỗi, không nhận được phản hồi.', 'provider' => 'openrouter'];
    }

    /* ================================================================
     * Helpers
     * ================================================================ */

    /**
     * Log message to bizcity_webchat_messages table
     */
    private function log_message($data) {
        // Use webchat database if available
        if (class_exists('BizCity_WebChat_Database')) {
            BizCity_WebChat_Database::instance()->log_message($data);
            return;
        }

        // Fallback: direct insert
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return; // Table doesn't exist
        }

        $wpdb->insert($table, [
            'conversation_id' => 0,
            'session_id'      => $data['session_id'] ?? '',
            'user_id'         => $data['user_id'] ?? 0,
            'client_name'     => $data['client_name'] ?? '',
            'message_id'      => $data['message_id'] ?? '',
            'message_text'    => $data['message_text'] ?? '',
            'message_from'    => $data['message_from'] ?? 'user',
            'message_type'    => $data['message_type'] ?? 'text',
            'attachments'     => is_array($data['attachments'] ?? null) ? wp_json_encode($data['attachments']) : '',
            'platform_type'   => $data['platform_type'] ?? 'ADMINCHAT',
            'meta'            => isset($data['meta']) ? wp_json_encode($data['meta']) : '',
        ]);
    }

    /**
     * Get conversation history from bizcity_webchat_messages
     */
    private function get_history($session_id, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            return [];
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE session_id = %s AND platform_type = 'ADMINCHAT'
             ORDER BY id ASC
             LIMIT %d",
            $session_id,
            $limit
        ));

        $history = [];
        foreach ($rows as $row) {
            $meta = $row->meta ? json_decode($row->meta, true) : [];
            $attachments = $row->attachments ? json_decode($row->attachments, true) : [];

            // Normalize attachments → flat array of image URLs/data URIs
            $images = [];
            if (is_array($attachments)) {
                foreach ($attachments as $att) {
                    if (is_string($att) && $att !== '') {
                        $images[] = $att; // base64 data URI or URL string
                    } elseif (is_array($att)) {
                        $url = $att['url'] ?? $att['data'] ?? '';
                        if ($url) $images[] = $url;
                    }
                }
            }

            $history[] = [
                'id'          => $row->id,
                'message_id'  => $row->message_id,
                'msg'         => $row->message_text,
                'from'        => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $attachments,
                'images'      => $images,
                'time'        => $row->created_at,
                'meta'        => $meta,
            ];
        }

        return $history;
    }

    /**
     * Session ID dựa trên user_id – mỗi admin user 1 session riêng
     */
    private function get_session_id() {
        $user_id = get_current_user_id();
        $blog_id = get_current_blog_id();

        return 'adminchat_' . $blog_id . '_' . $user_id;
    }

    /**
     * Default character ID
     */
    private function get_default_character_id() {
        // Try webchat settings
        $cid = intval(get_option('bizcity_webchat_default_character_id', 0));

        if (!$cid) {
            // Fallback: pmfacebook_options
            $opts = get_option('pmfacebook_options', []);
            $cid  = isset($opts['default_character_id']) ? intval($opts['default_character_id']) : 0;
        }

        if (!$cid && class_exists('BizCity_Knowledge_Database')) {
            // Fallback: first active character
            $db   = BizCity_Knowledge_Database::instance();
            $chars = $db->get_characters(['status' => 'active', 'limit' => 1]);
            if (!empty($chars)) {
                $cid = $chars[0]->id;
            }
        }

        return $cid;
    }
}
