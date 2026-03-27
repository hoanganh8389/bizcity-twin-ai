<?php
/**
 * BizCity Gemini Knowledge — Tool Callbacks
 *
 * Kiến trúc "Developer-packaged Pipeline":
 *   Intent Provider khai báo goal_patterns + required_slots
 *   → Intent Engine nhận diện goal → Planner hỏi user nếu thiếu fields
 *   → Khi đủ slots → call_tool → Tool callback xử lý pipeline
 *
 * @package BizCity_Gemini_Knowledge
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BizCity_Tools_Gemini_Knowledge {

    /** Plugin display name for personality prefix */
    const PLUGIN_LABEL = 'Gemini Knowledge';

    /* ══════════════════════════════════════════════════════
     *  TOOL: ask_gemini — Hỏi Gemini kiến thức chuyên sâu
     *
     *  Tools Map Input & Duy Trì Ngữ Cảnh:
     *    Engine build _meta trước khi gọi execute().
     *    Tool nhận $slots (bao gồm _meta) → extract _context.
     *    Pattern A: append ai_context vào system prompt.
     *
     *  Personality inject:
     *    "Mình dùng công cụ Gemini Knowledge để tìm hiểu
     *     cho bạn như sau, bạn xem có phù hợp ko nhé"
     * ══════════════════════════════════════════════════════ */

    /**
     * @param array $slots { question, category, depth, _meta }
     * @return array Tool Output Envelope
     */
    public static function ask_gemini( array $slots ): array {
        $question = self::extract_question( $slots );

        if ( empty( $question ) ) {
            return [
                'success'        => false,
                'complete'       => false,
                'message'        => '❓ Bạn muốn hỏi gì? Gõ câu hỏi kiến thức cần tìm hiểu.',
                'missing_fields' => [ 'question' ],
                'data'           => [],
            ];
        }

        if ( ! class_exists( 'BizCity_Gemini_Knowledge' ) ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => '⚠️ Gemini Knowledge chưa được kích hoạt.',
                'data'     => [],
            ];
        }

        // ── Extract _meta (Tools Map Input) ──
        $meta       = $slots['_meta']   ?? [];
        $ai_context = $meta['_context'] ?? '';

        // Options from slots
        $options = [ 'user_id' => get_current_user_id() ];
        $depth = $slots['depth'] ?? '';
        if ( $depth === 'brief' ) {
            $options['max_tokens'] = 500;
        } elseif ( $depth === 'detailed' ) {
            $options['max_tokens'] = 4000;
        }

        // ── Pattern A: inject dual context vào AI call ──
        if ( $ai_context ) {
            $options['ai_context'] = $ai_context;
        }

        // ── Personality prefix: giữ phong cách "mình dùng tool X" ──
        $options['personality_prefix'] = 'QUAN TRỌNG — Phong cách trả lời: '
            . 'Bắt đầu câu trả lời bằng cụm tương tự: '
            . '"Mình dùng công cụ ' . self::PLUGIN_LABEL . ' để tìm hiểu cho bạn như sau, '
            . 'bạn xem có phù hợp ko nhé:" rồi mới đưa ra nội dung chi tiết. '
            . 'Giữ giọng thân thiện, gần gũi (xưng "mình", gọi "bạn").';

        $gemini = BizCity_Gemini_Knowledge::instance();
        $result = $gemini->ask( $question, $options );

        if ( $result['success'] ) {
            return [
                'success'  => true,
                'complete' => true,
                'message'  => $result['answer'],
                'data'     => [
                    'type'   => 'knowledge_answer',
                    'model'  => $result['model'] ?? '',
                    'tokens' => $result['usage'] ?? [],
                    'query'  => $question,
                ],
            ];
        }

        return [
            'success'  => false,
            'complete' => true,
            'message'  => '⚠️ Gemini không phản hồi: ' . ( $result['error'] ?? 'Unknown error' ),
            'data'     => [],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  TOOL: gemini_save_bookmark — Lưu bookmark câu trả lời
     * ══════════════════════════════════════════════════════ */

    /**
     * @param array $slots { query, answer, tags }
     * @return array Tool Output Envelope
     */
    public static function gemini_save_bookmark( array $slots ): array {
        $query  = $slots['query']  ?? $slots['question'] ?? '';
        $answer = $slots['answer'] ?? $slots['message']  ?? '';

        if ( empty( $query ) || empty( $answer ) ) {
            return [
                'success'  => false,
                'complete' => false,
                'message'  => '❓ Cần có câu hỏi và câu trả lời để bookmark. Bạn muốn lưu nội dung gì?',
                'data'     => [],
            ];
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => '⚠️ Bạn cần đăng nhập để lưu bookmark.',
                'data'     => [],
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bzgk_bookmarks';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => '⚠️ Bảng bookmark chưa được tạo.',
                'data'     => [],
            ];
        }

        $tags  = $slots['tags'] ?? '';
        $model = $slots['model'] ?? '';

        $wpdb->insert( $table, [
            'user_id'     => $user_id,
            'query_text'  => mb_substr( $query, 0, 500 ),
            'answer_text' => $answer,
            'model_used'  => $model,
            'tags'        => $tags,
            'created_at'  => current_time( 'mysql' ),
        ] );

        return [
            'success'  => true,
            'complete' => true,
            'message'  => '✅ Đã lưu bookmark thành công!',
            'data'     => [
                'type' => 'bookmark',
                'id'   => $wpdb->insert_id,
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  TOOL: gemini_search_history — Xem lịch sử tìm kiếm
     * ══════════════════════════════════════════════════════ */

    /**
     * @param array $slots { limit }
     * @return array Tool Output Envelope
     */
    public static function gemini_search_history( array $slots ): array {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => '⚠️ Bạn cần đăng nhập để xem lịch sử.',
                'data'     => [],
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'bzgk_search_history';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            return [
                'success'  => false,
                'complete' => true,
                'message'  => '⚠️ Chưa có lịch sử tìm kiếm.',
                'data'     => [],
            ];
        }

        $limit = min( max( (int) ( $slots['limit'] ?? 10 ), 1 ), 50 );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT query_text, model_used, is_success, created_at
             FROM {$table}
             WHERE user_id = %d
             ORDER BY created_at DESC
             LIMIT %d",
            $user_id, $limit
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [
                'success'  => true,
                'complete' => true,
                'message'  => '📭 Chưa có lịch sử tìm kiếm nào.',
                'data'     => [ 'type' => 'search_history', 'items' => [] ],
            ];
        }

        $lines = [ "📜 **Lịch sử tìm kiếm gần đây** ({$limit} mục):\n" ];
        foreach ( $rows as $i => $row ) {
            $idx    = $i + 1;
            $status = $row['is_success'] ? '✅' : '❌';
            $date   = wp_date( 'd/m H:i', strtotime( $row['created_at'] ) );
            $lines[] = "{$idx}. {$status} {$row['query_text']} — *{$row['model_used']}* ({$date})";
        }

        return [
            'success'  => true,
            'complete' => true,
            'message'  => implode( "\n", $lines ),
            'data'     => [
                'type'  => 'search_history',
                'items' => $rows,
                'count' => count( $rows ),
            ],
        ];
    }

    /* ══════════════════════════════════════════════════════
     *  Helper: extract question from various slot keys
     * ══════════════════════════════════════════════════════ */

    private static function extract_question( array $slots ): string {
        foreach ( [ 'question', 'topic', 'message', 'query', 'content' ] as $key ) {
            if ( ! empty( $slots[ $key ] ) && is_string( $slots[ $key ] ) ) {
                return trim( $slots[ $key ] );
            }
        }
        return '';
    }
}
