<?php
/**
 * Bizcity Twin AI — WebChat Memory Builder (LLM-powered)
 * Xây dựng bộ nhớ hội thoại / Build conversation memory using LLM
 *
 * - Read wp_bizcity_webchat_messages
 * - Extract key memories using LLM
 * - Persist folded memory records in the encrypted business filestore;
 *   bizcity_memory_session is legacy read/migration state only.
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

    // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — canonical encrypted business-record contract for WebChat session memory.
    const BUSINESS_CONTRACT_ID = 'modules.webchat.session_memory';
    
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

        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-MEMORY-FILESTORE — memory extraction requires the canonical filestore; legacy SQL is never a write fallback.
        if ( ! self::is_filestore_available() ) {
            return [ 'ok' => false, 'degraded' => true, 'reason' => 'legacy_memory_quarantined', 'inserted' => 0, 'updated' => 0 ];
        }
        
        $args = wp_parse_args($args, [
            'session_id' => '',
            'user_id'    => 0,
            'limit'      => 200,
            'since_id'   => 0,
        ]);
        
        $table_messages = self::messages_table();
        
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
                
                $res = self::upsert_memory( self::memory_table(), $mem );
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
        // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — canonical write path is encrypted filestore with folded record state.
        $file_result = self::write_filestore_memory( $mem );
        if ( is_array( $file_result ) && ! empty( $file_result['op'] ) ) {
            return (string) $file_result['op'];
        }

        // [2026-09-01 Johnny Chu] PHASE-CB4.4 — Context Bank filestore is the only new WebChat memory payload writer; SQL fallback is disabled.
        return 'quarantined';
    }

    // [2026-08-28 Johnny Chu] PHASE-1.30-DDV — expose the canonical session-memory owner boundary for runtime parity probes and approved callers.
    public static function upsert_public( $mem ) {
        return self::upsert_memory( self::memory_table(), (array) $mem );
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

        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-MEMORY-FILESTORE — filestore is the only memory read owner.
        $file_rows = self::query_filestore_memories( array(
            'session_id'  => (string) $args['session_id'],
            'user_id'     => (int) $args['user_id'],
            'memory_type' => (string) $args['memory_type'],
            'limit'       => (int) $args['limit'],
            'order_by'    => (string) $order_by,
            'order'       => 'DESC',
        ) );
        if ( ! empty( $file_rows ) ) {
            return array_map( function ( $row ) {
                return (object) $row;
            }, $file_rows );
        }

        return [];
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

        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-MEMORY-FILESTORE — aggregate only canonical filestore rows.
        $file_rows = self::query_filestore_memories( array(
            'session_id' => (string) $args['session_id'],
            'limit'      => 2000,
            'order_by'   => 'updated_at',
            'order'      => 'DESC',
        ) );
        if ( ! empty( $file_rows ) ) {
            $by_type_map = array();
            $total = 0;
            $pain_count = 0;
            $constraint_count = 0;
            $goal_count = 0;
            foreach ( $file_rows as $row ) {
                $type = (string) ( $row['memory_type'] ?? '' );
                $score = (int) ( $row['score'] ?? 0 );
                if ( ! isset( $by_type_map[ $type ] ) ) {
                    $by_type_map[ $type ] = array( 'count' => 0, 'score_sum' => 0 );
                }
                $by_type_map[ $type ]['count']++;
                $by_type_map[ $type ]['score_sum'] += $score;
                $total++;
                if ( $type === 'pain' ) {
                    $pain_count++;
                }
                if ( $type === 'constraint' ) {
                    $constraint_count++;
                }
                if ( $type === 'goal' ) {
                    $goal_count++;
                }
            }
            $by_type = array();
            foreach ( $by_type_map as $type => $meta ) {
                $avg_score = $meta['count'] > 0 ? ( $meta['score_sum'] / $meta['count'] ) : 0;
                $by_type[] = array(
                    'memory_type' => $type,
                    'count'       => $meta['count'],
                    'avg_score'   => $avg_score,
                );
            }
            return [
                'by_type' => $by_type,
                'totals'  => [
                    'total'            => $total,
                    'pain_count'       => $pain_count,
                    'constraint_count' => $constraint_count,
                    'goal_count'       => $goal_count,
                ],
            ];
        }

        return [
            'by_type' => [],
            'totals' => [ 'total' => 0, 'pain_count' => 0, 'constraint_count' => 0, 'goal_count' => 0 ],
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

    // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — session-memory migration requires a registered encrypted business-record contract.
    private static function is_filestore_available() {
        return class_exists( 'BizCity_File_Contract_Registry' )
            && class_exists( 'BizCity_Business_JSONL_File_Store' )
            && BizCity_File_Contract_Registry::has( self::BUSINESS_CONTRACT_ID );
    }

    // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — derive stable record identity from session owner + memory key.
    private static function filestore_record_id( $mem ) {
        $scope = (string) get_current_blog_id() . '|'
            . (string) ( $mem['session_id'] ?? '' ) . '|'
            . (int) ( $mem['user_id'] ?? 0 ) . '|'
            . (string) ( $mem['memory_key'] ?? '' );
        if ( class_exists( 'BizCity_Codec' ) && function_exists( 'wp_salt' ) ) {
            return 'ws_' . BizCity_Codec::hmac_sha256( $scope, wp_salt( 'auth' ), false );
        }
        return 'ws_' . hash( 'sha256', $scope );
    }

    // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — normalize folded session-memory records for legacy-compatible readers.
    private static function normalize_filestore_memory( $row ) {
        $defaults = array(
            'id'                 => 0,
            'legacy_id'          => 0,
            'record_id'          => '',
            'blog_id'            => get_current_blog_id(),
            'session_id'         => '',
            'user_id'            => 0,
            'client_name'        => '',
            'memory_type'        => 'fact',
            'memory_key'         => '',
            'memory_text'        => '',
            'score'              => 50,
            'times_seen'         => 1,
            'last_seen'          => '',
            'source_message_ids' => '',
            'created_at'         => '',
            'updated_at'         => '',
        );
        $row = wp_parse_args( (array) $row, $defaults );
        $row['id']         = (int) ( $row['legacy_id'] ?? $row['id'] ?? 0 );
        $row['user_id']    = (int) $row['user_id'];
        $row['score']      = (int) $row['score'];
        $row['times_seen'] = max( 1, (int) $row['times_seen'] );
        return $row;
    }

    // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — file-first upsert preserving legacy insert/update semantics.
    private static function write_filestore_memory( $mem ) {
        if ( ! self::is_filestore_available() ) {
            return false;
        }

        $now = current_time( 'mysql' );
        $record = self::normalize_filestore_memory( array_merge( (array) $mem, array(
            'blog_id'    => get_current_blog_id(),
            'last_seen'  => (string) ( $mem['last_seen'] ?? $now ),
            'updated_at' => $now,
        ) ) );
        if ( $record['session_id'] === '' || $record['memory_key'] === '' || trim( (string) $record['memory_text'] ) === '' ) {
            return false;
        }

        $record['record_id'] = self::filestore_record_id( $record );
        $existing = BizCity_Business_JSONL_File_Store::find( self::BUSINESS_CONTRACT_ID, $record['record_id'], array(
            'blog_id' => get_current_blog_id(),
        ) );

        $op = 'insert';
        if ( ! empty( $existing ) ) {
            $op = 'update';
            $existing = self::normalize_filestore_memory( $existing );
            $score_increment = max( 1, (int) ( $record['score'] / 5 ) );
            $record['score'] = min( 100, (int) $existing['score'] + $score_increment );
            $record['times_seen'] = (int) $existing['times_seen'] + 1;
            $record['created_at'] = (string) ( $existing['created_at'] ?: $now );
            $record['legacy_id']  = (int) ( $existing['legacy_id'] ?? 0 );
            $source_parts = array_filter( array( (string) ( $existing['source_message_ids'] ?? '' ), (string) $record['source_message_ids'] ) );
            $record['source_message_ids'] = implode( ',', $source_parts );
        } else {
            $record['times_seen'] = 1;
            $record['created_at'] = $now;
        }

        $receipt = BizCity_Business_JSONL_File_Store::write_with_receipt( self::BUSINESS_CONTRACT_ID, $record, 'upsert' );
        if ( ! is_array( $receipt ) ) {
            return false;
        }

        do_action( 'bizcity_memory_mirror_write', 'session', [
            'id'            => (int) ( $record['legacy_id'] ?? 0 ),
            'blog_id'       => get_current_blog_id(),
            'session_id'    => (string)$record['session_id'],
            'user_id'       => (int)$record['user_id'],
            'memory_type'   => (string)$record['memory_type'],
            'memory_key'    => (string)$record['memory_key'],
            'memory_text'   => (string)$record['memory_text'],
            'score'         => (int)$record['score'],
            'metadata'      => '',
            'filestore_receipt' => $receipt,
        ], $op );

        return array(
            'op'      => $op,
            'receipt' => $receipt,
        );
    }

    // [2026-08-28 Johnny Chu] R-FILESTORE-BUSINESS — scoped session-memory read helper used by context and stats callers.
    private static function query_filestore_memories( $args = array() ) {
        if ( ! self::is_filestore_available() ) {
            return array();
        }

        $session_id = (string) ( $args['session_id'] ?? '' );
        $user_id = (int) ( $args['user_id'] ?? 0 );
        $memory_type = (string) ( $args['memory_type'] ?? '' );
        $limit = max( 1, (int) ( $args['limit'] ?? 100 ) );
        $order_by = in_array( (string) ( $args['order_by'] ?? 'score' ), array( 'score', 'times_seen', 'created_at', 'updated_at' ), true )
            ? (string) $args['order_by']
            : 'score';
        $order = strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) === 'ASC' ? 'ASC' : 'DESC';

        $query = array(
            'blog_id' => get_current_blog_id(),
            'limit'   => $limit * 8,
            'days'    => 365,
            'filter'  => function ( $row ) use ( $session_id, $memory_type ) {
                if ( $session_id !== '' && (string) ( $row['session_id'] ?? '' ) !== $session_id ) {
                    return false;
                }
                if ( $memory_type !== '' && (string) ( $row['memory_type'] ?? '' ) !== $memory_type ) {
                    return false;
                }
                return true;
            },
        );
        if ( $user_id > 0 ) {
            $query['user_id'] = $user_id;
        }

        // [2026-09-01 Johnny Chu] PHASE-CB4.5 — session memory reads follow Context Bank pointers and verified filestore receipts.
        if ( function_exists( 'bizcity_context_bank_load_memory_runtime' ) ) {
            bizcity_context_bank_load_memory_runtime();
        }
        $rows = class_exists( 'BizCity_Context_Bank_Memory_Adapter' )
            ? BizCity_Context_Bank_Memory_Adapter::query( self::BUSINESS_CONTRACT_ID, $query )
            : array();
        if ( empty( $rows ) ) {
            return array();
        }
        $rows = array_map( array( 'BizCity_WebChat_Memory', 'normalize_filestore_memory' ), $rows );
        usort( $rows, function ( $a, $b ) use ( $order_by, $order ) {
            $left = $a[ $order_by ] ?? '';
            $right = $b[ $order_by ] ?? '';
            if ( is_numeric( $left ) && is_numeric( $right ) ) {
                $cmp = (float) $left <=> (float) $right;
            } else {
                $cmp = strcmp( (string) $left, (string) $right );
            }
            return $order === 'ASC' ? $cmp : ( 0 - $cmp );
        } );
        return array_slice( $rows, 0, $limit );
    }
}
