<?php
/**
 * Bizcity Twin AI — WebChat Memory Builder (LLM-powered)
 * Xây dựng bộ nhớ hội thoại / Build conversation memory using LLM
 *
 * - Read wp_bizcity_webchat_messages
 * - Extract key memories using LLM
 * - Upsert to wp_bizcity_memory_session
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class BizCity_WebChat_Memory {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Get memory table name
     */
    public static function memory_table() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_memory_session';
    }
    
    /**
     * Get messages table name
     */
    public static function messages_table() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_messages';
    }
    
    /**
     * Build memories from messages using LLM
     */
    public static function build_from_messages($args = []) {
        global $wpdb;
        
        $args = wp_parse_args($args, [
            'session_id' => '',
            'user_id'    => 0,
            'limit'      => 200,
            'since_id'   => 0,
        ]);
        
        $table_messages = self::messages_table();
        $table_memory = self::memory_table();
        
        // 1) Fetch messages from users
        $where = "WHERE message_from='user' AND message_text != ''";
        $params = [];
        
        if (!empty($args['session_id'])) {
            $where .= " AND session_id=%s";
            $params[] = $args['session_id'];
        }
        
        if ((int)$args['user_id'] > 0) {
            $where .= " AND user_id=%d";
            $params[] = (int)$args['user_id'];
        }
        
        if ((int)$args['since_id'] > 0) {
            $where .= " AND id>%d";
            $params[] = (int)$args['since_id'];
        }
        
        $sql = "SELECT id, session_id, user_id, client_name, message_text, created_at
                FROM {$table_messages}
                {$where}
                ORDER BY id DESC
                LIMIT " . (int)$args['limit'];
        
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        
        if (!$rows) {
            return ['ok' => true, 'count' => 0, 'inserted' => 0, 'updated' => 0];
        }
        
        $inserted = 0;
        $updated = 0;
        
        // Group messages by session for batch processing
        $session_messages = [];
        foreach ($rows as $r) {
            $session_key = $r['session_id'];
            if (!isset($session_messages[$session_key])) {
                $session_messages[$session_key] = [
                    'session_id' => $r['session_id'],
                    'user_id' => $r['user_id'],
                    'client_name' => $r['client_name'],
                    'messages' => [],
                ];
            }
            $session_messages[$session_key]['messages'][] = $r;
        }
        
        // Process each session's messages
        foreach ($session_messages as $session_data) {
            // Extract memories using LLM
            $memories = self::extract_memories_llm($session_data['messages']);
            
            foreach ($memories as $mem) {
                $mem['session_id'] = (string)$session_data['session_id'];
                $mem['user_id'] = (int)$session_data['user_id'];
                $mem['client_name'] = (string)$session_data['client_name'];
                
                // Find source message IDs
                $source_ids = [];
                foreach ($session_data['messages'] as $msg) {
                    $source_ids[] = $msg['id'];
                }
                $mem['source_message_ids'] = implode(',', $source_ids);
                $mem['last_seen'] = current_time('mysql');
                
                $res = self::upsert_memory($table_memory, $mem);
                if ($res === 'insert') {
                    $inserted++;
                }
                if ($res === 'update') {
                    $updated++;
                }
            }
        }
        
        return [
            'ok' => true,
            'count' => count($rows),
            'inserted' => $inserted,
            'updated' => $updated,
        ];
    }
    
    /**
     * Extract memories from messages using OpenAI LLM
     */
    private static function extract_memories_llm($messages) {
        // Get OpenAI API key from options
        $api_key = get_option('bizcity_webchat_openai_api_key') ?: get_option('twf_openai_api_key');
        if (empty($api_key)) {
            error_log('[BizCity WebChat Memory] OpenAI API key not found');
            return [];
        }
        
        // Build conversation context
        $conversation = [];
        foreach ($messages as $msg) {
            $conversation[] = [
                'role' => 'user',
                'content' => $msg['message_text'],
                'timestamp' => $msg['created_at'],
            ];
        }
        
        // Prepare LLM prompt
        $prompt = self::build_extraction_prompt($conversation);
        
        // Call OpenAI API
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $prompt['system'],
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt['user'],
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 1000,
            ]),
        ]);
        
        if (is_wp_error($response)) {
            error_log('[BizCity WebChat Memory] OpenAI API error: ' . $response->get_error_message());
            return [];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (!isset($body['choices'][0]['message']['content'])) {
            error_log('[BizCity WebChat Memory] Invalid OpenAI response: ' . print_r($body, true));
            return [];
        }
        
        $llm_output = $body['choices'][0]['message']['content'];
        
        // Parse LLM output to extract structured memories
        return self::parse_llm_output($llm_output);
    }
    
    /**
     * Build extraction prompt for LLM
     */
    private static function build_extraction_prompt($conversation) {
        $messages_text = '';
        foreach ($conversation as $msg) {
            $messages_text .= "- " . $msg['content'] . "\n";
        }
        
        $system = "Bạn là trợ lý AI chuyên phân tích tâm lý người dùng. Nhiệm vụ của bạn là trích xuất các \"ký ức\" (memories) quan trọng từ đoạn hội thoại của người dùng.

Các loại ký ức cần trích xuất:
1. **identity** - Thông tin cá nhân: tên, tuổi, nghề nghiệp, sở thích cá nhân
2. **preference** - Sở thích/Không thích: thích gì, ghét gì, ưu tiên gì
3. **goal** - Mục tiêu: muốn đạt được điều gì, kế hoạch tương lai
4. **pain** - Vấn đề/Nỗi đau: stress, lo âu, vấn đề đang gặp phải
5. **constraint** - Giới hạn: thiếu thời gian, thiếu tiền, dị ứng, không thể làm gì, ràng buộc về tài chính/địa lý/sức khỏe
6. **habit** - Thói quen: làm gì thường xuyên, pattern hành vi
7. **relationship** - Quan hệ: gia đình, bạn bè, đồng nghiệp
8. **fact** - Sự kiện/Thông tin: các thông tin khác có thể hữu ích

Yêu cầu output:
- Format JSON array với các object: {\"type\": \"...\", \"key\": \"...\", \"text\": \"...\", \"score\": 0-100}
- \"key\": slug ngắn gọn (VD: \"likes:milk_tea\", \"pain:stress\", \"goal:save_money\")
- \"text\": Câu mô tả chuẩn hóa bằng tiếng Việt
- \"score\": Độ quan trọng (0-100), càng quan trọng/rõ ràng càng cao

Chỉ trích xuất những thông tin có giá trị, bỏ qua lời chào hỏi thông thường.";
        
        $user = "Đây là các tin nhắn của người dùng:\n\n{$messages_text}\n\nHãy trích xuất các memories quan trọng dưới dạng JSON array.";
        
        return [
            'system' => $system,
            'user' => $user,
        ];
    }
    
    /**
     * Parse LLM output to structured memories
     */
    private static function parse_llm_output($output) {
        // Try to extract JSON from output
        if (preg_match('/\[.*\]/s', $output, $matches)) {
            $json = $matches[0];
            $memories = json_decode($json, true);
            
            if (is_array($memories)) {
                $result = [];
                foreach ($memories as $mem) {
                    if (isset($mem['type'], $mem['key'], $mem['text'], $mem['score'])) {
                        $result[] = [
                            'memory_type' => sanitize_text_field($mem['type']),
                            'memory_key' => sanitize_text_field($mem['key']),
                            'memory_text' => sanitize_textarea_field($mem['text']),
                            'score' => min(100, max(0, (int)$mem['score'])),
                        ];
                    }
                }
                return $result;
            }
        }
        
        // Fallback: parse line by line if JSON fails
        error_log('[BizCity WebChat Memory] Failed to parse JSON from LLM output');
        return [];
    }
    
    /**
     * Upsert memory to database
     */
    private static function upsert_memory($table, $mem) {
        global $wpdb;
        
        $now = current_time('mysql');
        
        // Try find existing by (session_id, user_id, memory_key)
        $exists_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE session_id=%s AND user_id=%d AND memory_key=%s
             LIMIT 1",
            (string)$mem['session_id'],
            (int)$mem['user_id'],
            (string)$mem['memory_key']
        ));
        
        if ($exists_id > 0) {
            // Update: increase score + times_seen, concat source_message_ids, last_seen
            $score_increment = max(1, (int)($mem['score'] / 5));
            
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET score = LEAST(100, score + %d),
                     times_seen = times_seen + 1,
                     last_seen = %s,
                     source_message_ids = CONCAT_WS(',', source_message_ids, %s),
                     updated_at = %s
                 WHERE id=%d",
                $score_increment,
                (string)$mem['last_seen'],
                (string)$mem['source_message_ids'],
                $now,
                $exists_id
            ));
            
            return 'update';
        }
        
        // Insert new memory
        $wpdb->insert($table, [
            'session_id' => (string)$mem['session_id'],
            'user_id' => (int)$mem['user_id'],
            'client_name' => (string)$mem['client_name'],
            'memory_type' => (string)$mem['memory_type'],
            'memory_key' => (string)$mem['memory_key'],
            'memory_text' => (string)$mem['memory_text'],
            'score' => (int)$mem['score'],
            'times_seen' => 1,
            'last_seen' => (string)$mem['last_seen'],
            'source_message_ids' => (string)$mem['source_message_ids'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        
        return 'insert';
    }
    
    /**
     * Get memories for a user/session
     */
    public static function get_memories($args = []) {
        global $wpdb;
        
        $args = wp_parse_args($args, [
            'session_id' => '',
            'user_id' => 0,
            'memory_type' => '',
            'limit' => 100,
            'order_by' => 'score',
        ]);
        
        $table = self::memory_table();
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($args['session_id'])) {
            $where[] = 'session_id = %s';
            $params[] = $args['session_id'];
        }
        
        if ((int)$args['user_id'] > 0) {
            $where[] = 'user_id = %d';
            $params[] = (int)$args['user_id'];
        }
        
        if (!empty($args['memory_type'])) {
            $where[] = 'memory_type = %s';
            $params[] = $args['memory_type'];
        }
        
        $where_sql = implode(' AND ', $where);
        
        $order_by = in_array($args['order_by'], ['score', 'times_seen', 'created_at', 'updated_at']) ? $args['order_by'] : 'score';
        
        $sql = "SELECT * FROM {$table}
                WHERE {$where_sql}
                ORDER BY {$order_by} DESC, id DESC
                LIMIT %d";
        
        $params[] = (int)$args['limit'];
        
        return $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
    }
    
    /**
     * Get memory statistics
     */
    public static function get_stats($args = []) {
        global $wpdb;
        
        $args = wp_parse_args($args, [
            'session_id' => '',
        ]);
        
        $table = self::memory_table();
        
        $where = ['1=1'];
        $params = [];
        
        if (!empty($args['session_id'])) {
            $where[] = 'session_id = %s';
            $params[] = $args['session_id'];
        }
        
        $where_sql = implode(' AND ', $where);
        
        // Count by type
        $sql = "SELECT memory_type, COUNT(*) as count, AVG(score) as avg_score
                FROM {$table}
                WHERE {$where_sql}
                GROUP BY memory_type
                ORDER BY count DESC";
        
        $by_type = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        
        // Total count
        $total_sql = "SELECT COUNT(*) as total,
                      SUM(CASE WHEN memory_type='pain' THEN 1 ELSE 0 END) as pain_count,
                      SUM(CASE WHEN memory_type='constraint' THEN 1 ELSE 0 END) as constraint_count,
                      SUM(CASE WHEN memory_type='goal' THEN 1 ELSE 0 END) as goal_count
                      FROM {$table}
                      WHERE {$where_sql}";
        
        $totals = $params ? $wpdb->get_row($wpdb->prepare($total_sql, ...$params), ARRAY_A) : $wpdb->get_row($total_sql, ARRAY_A);
        
        return [
            'by_type' => $by_type,
            'totals' => $totals,
        ];
    }
    
    /**
     * Get memory context for AI (formatted for system prompt)
     */
    public static function get_memory_context($session_id, $limit = 20) {
        $memories = self::get_memories([
            'session_id' => $session_id,
            'limit' => $limit,
            'order_by' => 'score',
        ]);
        
        if (empty($memories)) {
            return '';
        }
        
        $context = "### Thông tin đã biết về người dùng:\n";
        
        $grouped = [];
        foreach ($memories as $mem) {
            $grouped[$mem->memory_type][] = $mem;
        }
        
        $type_names = [
            'identity' => '🆔 Thông tin cá nhân',
            'preference' => '❤️ Sở thích',
            'goal' => '🎯 Mục tiêu',
            'pain' => '😰 Vấn đề/Nỗi đau',
            'constraint' => '⚠️ Giới hạn',
            'habit' => '⏰ Thói quen',
            'relationship' => '👥 Quan hệ',
            'fact' => '📌 Sự kiện',
        ];
        
        foreach ($grouped as $type => $mems) {
            $type_name = $type_names[$type] ?? ucfirst($type);
            $context .= "\n**{$type_name}:**\n";
            foreach ($mems as $mem) {
                $context .= "- {$mem->memory_text} (score: {$mem->score})\n";
            }
        }
        
        return $context;
    }
}
