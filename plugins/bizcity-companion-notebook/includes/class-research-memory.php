<?php
defined( 'ABSPATH' ) || exit;

/**
 * Research Memory — Auto-summarize chat findings into notes every N turns.
 *
 * Triggers:
 *  1. After every 3 chat turns (user+assistant pairs) within a session.
 *  2. On session close (user closes browser tab — via AJAX beacon).
 *
 * Uses cheap LLM (purpose=fast / DeepSeek) for:
 *  - Summarizing research findings from recent messages.
 *  - Extracting keyword tags for later retrieval.
 *
 * Storage: BCN Notes table with note_type = 'research_auto'.
 */
class BCN_Research_Memory {

    const TURN_INTERVAL = 3;
    const TRANSIENT_PREFIX = 'bcn_rm_turn_';

    private static $instance = null;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register hooks for /chat/ integration.
     * Injects research context into Intent Stream for ALL platforms (not just notebook).
     */
    public function register_hooks() {
        // Priority 92: after Context Builder (90), before User Memory (99).
        add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_research_context_filter' ], 92, 2 );
    }

    /**
     * Filter callback: inject keyword-matched research context into system prompt.
     * Works for BOTH /chat/ and /note/ (notebook already has its own injection in inject_source_context,
     * but this provides cross-project research context for /chat/).
     */
    public function inject_research_context_filter( $prompt, $args ) {
        // For NOTEBOOK, research context is already injected via inject_source_context.
        $platform = $args['platform_type'] ?? sanitize_text_field( $_REQUEST['platform_type'] ?? '' );
        if ( $platform === 'NOTEBOOK' ) return $prompt;

        $user_message = $args['message'] ?? '';
        $user_id      = $args['user_id'] ?? 0;
        if ( ! $user_message || ! $user_id ) return $prompt;

        // For /chat/, search across ALL projects for this user.
        $context = $this->build_cross_project_research_context( $user_id, $user_message );
        if ( ! $context ) return $prompt;

        return $prompt . $context;
    }

    /**
     * Build research context from ALL projects for /chat/ mode.
     * Searches ALL note types (research_auto, chat_pinned, manual) by keyword.
     */
    private function build_cross_project_research_context( $user_id, $user_message, $max_tokens = 1500 ) {
        global $wpdb;

        $keywords = $this->extract_search_keywords( $user_message );
        if ( ! $keywords ) return '';

        $table = BCN_Schema_Extend::table_notes();

        // Try individual keyword matching for better recall.
        $keyword_parts = array_filter( explode( ' ', $keywords ) );
        $where_parts = [];
        $params = [ $user_id ];
        foreach ( array_slice( $keyword_parts, 0, 5 ) as $kw ) {
            $kw_like = '%' . $wpdb->esc_like( $kw ) . '%';
            $where_parts[] = '(title LIKE %s OR content LIKE %s OR tags LIKE %s)';
            $params[] = $kw_like;
            $params[] = $kw_like;
            $params[] = $kw_like;
        }

        if ( empty( $where_parts ) ) return '';

        $where_sql = implode( ' OR ', $where_parts );
        $params[] = 8;

        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT title, content, tags, note_type FROM {$table}
             WHERE user_id = %d AND ({$where_sql})
             ORDER BY FIELD(note_type, 'chat_pinned', 'manual', 'auto_pinned', 'research_auto'), created_at DESC
             LIMIT %d",
            ...$params
        ) );

        if ( empty( $results ) ) return '';

        $parts      = [];
        $total_chars = 0;
        $max_chars   = $max_tokens * 4;

        foreach ( $results as $note ) {
            $tags_str = '';
            $tags = json_decode( $note->tags ?? '[]', true );
            if ( ! empty( $tags ) && is_array( $tags ) ) {
                $tags_str = ' [' . implode( ', ', $tags ) . ']';
            }
            $icon = $note->note_type === 'research_auto' ? '🧠' : ( $note->note_type === 'chat_pinned' ? '📌' : ( $note->note_type === 'auto_pinned' ? '💡' : '📝' ) );
            $text = "{$icon} {$note->title}{$tags_str}: " . mb_substr( $note->content, 0, 500 );
            if ( $total_chars + mb_strlen( $text ) > $max_chars ) break;
            $parts[]     = $text;
            $total_chars += mb_strlen( $text );
        }

        if ( empty( $parts ) ) return '';

        return "\n\n## 🧠 NOTEBOOK RESEARCH MEMORY:\n"
             . "Ghi chú nghiên cứu và kiến thức liên quan từ Notebook:\n"
             . implode( "\n", $parts );
    }

    /**
     * Check if a research summary should be generated based on turn count.
     * Called after each assistant response.
     *
     * @param string $project_id
     * @param string $session_id
     * @param int    $user_id
     */
    public function maybe_summarize( $project_id, $session_id, $user_id ) {
        if ( ! $project_id || ! $session_id || ! $user_id ) return;

        $turn_key   = self::TRANSIENT_PREFIX . md5( $session_id );
        $turn_count = (int) get_transient( $turn_key );
        $turn_count++;
        set_transient( $turn_key, $turn_count, DAY_IN_SECONDS );

        if ( $turn_count % self::TURN_INTERVAL !== 0 ) return;

        error_log( "[BCN Research Memory] Turn {$turn_count} → summarize project={$project_id}" );
        $this->summarize_and_save( $project_id, $session_id, $user_id, $turn_count );
    }

    /**
     * Force a summary (e.g. on session close / tab close).
     *
     * @param string $project_id
     * @param string $session_id
     * @param int    $user_id
     */
    public function force_summarize( $project_id, $session_id, $user_id ) {
        if ( ! $project_id || ! $session_id || ! $user_id ) return;

        // Check minimum turns since last summary.
        $turn_key   = self::TRANSIENT_PREFIX . md5( $session_id );
        $turn_count = (int) get_transient( $turn_key );

        // Need at least 2 turns since last summary to bother.
        $remainder = $turn_count % self::TURN_INTERVAL;
        if ( $remainder < 2 && $turn_count >= self::TURN_INTERVAL ) return;
        if ( $turn_count < 2 ) return;

        error_log( "[BCN Research Memory] Force summarize (session close) project={$project_id} turns={$turn_count}" );
        $result = $this->summarize_and_save( $project_id, $session_id, $user_id, $turn_count );

        // Reset turn counter so we don't double-summarize.
        delete_transient( $turn_key );

        return $result;
    }

    /**
     * Frontend-triggered summarize — called by REST API after every N turns.
     * Returns structured result for browser console logging.
     */
    public function trigger_summarize( $project_id, $session_id, $user_id ) {
        if ( ! $project_id || ! $session_id || ! $user_id ) {
            return [ 'skipped' => true, 'reason' => 'missing_params' ];
        }

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [ 'skipped' => true, 'reason' => 'no_llm' ];
        }

        $messages = $this->get_recent_messages( $project_id, $session_id );
        if ( count( $messages ) < 3 ) {
            return [ 'skipped' => true, 'reason' => 'too_few_messages', 'message_count' => count( $messages ) ];
        }

        $result = $this->summarize_and_save( $project_id, $session_id, $user_id, 0 );
        return $result;
    }

    /**
     * Generate a research summary from recent messages and save as a note.
     * Returns structured result array.
     */
    private function summarize_and_save( $project_id, $session_id, $user_id, $turn_count ) {
        // Throttle: prevent duplicate summaries within 30s.
        $lock_key = 'bcn_rm_lock_' . md5( $session_id . $turn_count );
        if ( get_transient( $lock_key ) ) {
            return [ 'skipped' => true, 'reason' => 'throttled' ];
        }
        set_transient( $lock_key, 1, 30 );

        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [ 'skipped' => true, 'reason' => 'no_llm' ];
        }

        // Fetch recent messages since last research summary.
        $messages = $this->get_recent_messages( $project_id, $session_id );
        if ( count( $messages ) < 3 ) {
            return [ 'skipped' => true, 'reason' => 'too_few_messages', 'message_count' => count( $messages ) ];
        }

        // Build conversation text for LLM.
        $conv_text = $this->build_conversation_text( $messages );

        // Step 1: Summarize findings.
        $summary = $this->llm_summarize( $conv_text );
        if ( ! $summary ) {
            return [ 'skipped' => true, 'reason' => 'llm_skip', 'message_count' => count( $messages ) ];
        }

        // Step 2: Extract keywords.
        $keywords = $this->llm_extract_keywords( $summary );

        // Step 3: Generate title.
        $title = $this->llm_generate_title( $summary );

        // Step 4: Save as note.
        $notes = new BCN_Notes();
        $note_id = $notes->create_system( [
            'user_id'    => $user_id,
            'project_id' => $project_id,
            'session_id' => $session_id,
            'title'      => $title,
            'content'    => $summary,
            'tags'       => $keywords,
            'note_type'  => 'research_auto',
            'metadata'   => [ 'turn_count' => $turn_count, 'message_count' => count( $messages ) ],
        ] );

        if ( is_wp_error( $note_id ) ) {
            error_log( '[BCN Research Memory] Failed to save: ' . $note_id->get_error_message() );
            return [ 'error' => true, 'reason' => $note_id->get_error_message() ];
        }

        error_log( "[BCN Research Memory] Saved note #{$note_id} for project={$project_id}" );
        return [
            'saved'         => true,
            'note_id'       => $note_id,
            'title'         => $title,
            'tags'          => $keywords,
            'message_count' => count( $messages ),
        ];
    }

    /**
     * Get recent messages for the project session, excluding already-summarized ones.
     */
    private function get_recent_messages( $project_id, $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        // Find the last research_auto note time to avoid re-summarizing.
        $notes_table = BCN_Schema_Extend::table_notes();
        $last_summary_time = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(created_at) FROM {$notes_table}
             WHERE project_id = %s AND note_type = 'research_auto'",
            $project_id
        ) );

        $where_time = '';
        $params = [ $project_id ];
        if ( $last_summary_time ) {
            $where_time = ' AND m.created_at > %s';
            $params[] = $last_summary_time;
        }

        $params[] = 30; // Max messages to fetch.

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT m.message_from, m.message_text, m.created_at
             FROM {$table} m
             WHERE m.project_id = %s AND m.status = 'active'{$where_time}
             ORDER BY m.created_at ASC
             LIMIT %d",
            ...$params
        ) );
    }

    /**
     * Format messages into readable conversation text.
     */
    private function build_conversation_text( $messages ) {
        $lines = [];
        foreach ( $messages as $msg ) {
            $role = $msg->message_from === 'user' ? 'User' : 'AI';
            $text = mb_substr( wp_strip_all_tags( $msg->message_text ), 0, 1500 );
            $lines[] = "{$role}: {$text}";
        }
        return implode( "\n\n", $lines );
    }

    /**
     * Use LLM to summarize research findings from conversation.
     */
    private function llm_summarize( $conversation_text ) {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'Bạn là trợ lý ghi chép nghiên cứu. Tóm tắt các PHÁT HIỆN và KẾT LUẬN quan trọng từ cuộc hội thoại dưới đây.'
                    . "\n\nQuy tắc:"
                    . "\n- Chỉ ghi lại thông tin MỚI, hữu ích (facts, insights, kết luận)"
                    . "\n- Bỏ qua small talk, câu hỏi lặp, phần AI nói không biết"
                    . "\n- Viết dạng bullet points ngắn gọn, tiếng Việt"
                    . "\n- Mỗi bullet bắt đầu bằng •"
                    . "\n- Tối đa 500 từ"
                    . "\n- Nếu không có phát hiện gì đáng ghi, trả lời đúng 1 từ: SKIP",
            ],
            [
                'role'    => 'user',
                'content' => $conversation_text,
            ],
        ];

        try {
            $result = bizcity_openrouter_chat( $messages, [
                'purpose'     => 'fast',
                'max_tokens'  => 600,
                'temperature' => 0.2,
            ] );
            $text = trim( $result['message'] ?? '' );
            if ( ! $text || strtoupper( $text ) === 'SKIP' ) return null;
            return $text;
        } catch ( \Exception $e ) {
            error_log( '[BCN Research Memory] LLM summarize error: ' . $e->getMessage() );
            return null;
        }
    }

    /**
     * Use LLM to extract keywords/tags from a summary.
     */
    private function llm_extract_keywords( $summary ) {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'Trích xuất 3-7 từ khóa chính từ nội dung sau. Trả lời dạng JSON array, ví dụ: ["từ khóa 1","từ khóa 2"]. Chỉ trả lời JSON array, không giải thích.',
            ],
            [
                'role'    => 'user',
                'content' => mb_substr( $summary, 0, 1000 ),
            ],
        ];

        try {
            $result = bizcity_openrouter_chat( $messages, [
                'purpose'     => 'fast',
                'max_tokens'  => 100,
                'temperature' => 0.1,
            ] );
            $raw = trim( $result['message'] ?? '' );
            // Extract JSON array from response.
            if ( preg_match( '/\[.*\]/us', $raw, $m ) ) {
                $tags = json_decode( $m[0], true );
                if ( is_array( $tags ) ) {
                    return array_slice( array_map( 'sanitize_text_field', $tags ), 0, 10 );
                }
            }
        } catch ( \Exception $e ) {
            error_log( '[BCN Research Memory] LLM keyword extraction error: ' . $e->getMessage() );
        }

        return [];
    }

    /**
     * Generate a short title for the research summary.
     */
    private function llm_generate_title( $summary ) {
        $messages = [
            [
                'role'    => 'system',
                'content' => 'Tóm tắt nội dung sau thành 1 dòng tiêu đề ngắn gọn (tối đa 60 ký tự), bằng tiếng Việt, nêu ý chính. Chỉ trả lời tiêu đề, không giải thích.',
            ],
            [
                'role'    => 'user',
                'content' => mb_substr( $summary, 0, 800 ),
            ],
        ];

        try {
            $result = bizcity_openrouter_chat( $messages, [
                'purpose'     => 'fast',
                'max_tokens'  => 80,
                'temperature' => 0.2,
            ] );
            $title = trim( $result['message'] ?? '', " \t\n\r\0\x0B\"'" );
            if ( $title && mb_strlen( $title ) <= 100 ) return $title;
        } catch ( \Exception $e ) {
            // Fall through.
        }

        return mb_substr( wp_strip_all_tags( $summary ), 0, 60 ) ?: 'Research notes';
    }

    /**
     * Build keyword-filtered research context for injection into LLM prompt.
     *
     * @param string $project_id
     * @param string $user_message  Current user message — used for keyword extraction.
     * @param int    $max_tokens    Max tokens budget.
     * @return string
     */
    public function build_research_context( $project_id, $user_message, $max_tokens = 2000 ) {
        if ( ! $project_id || ! $user_message ) return '';

        // Extract keywords from user message for focused retrieval.
        $keywords = $this->extract_search_keywords( $user_message );
        if ( ! $keywords ) return '';

        $notes = new BCN_Notes();
        $results = $notes->search_by_keyword( $project_id, $keywords, 5 );

        if ( empty( $results ) ) return '';

        $parts      = [];
        $total_chars = 0;
        $max_chars   = $max_tokens * 4;

        foreach ( $results as $note ) {
            $tags_str = '';
            $tags = json_decode( $note->tags ?? '[]', true );
            if ( ! empty( $tags ) && is_array( $tags ) ) {
                $tags_str = ' [Tags: ' . implode( ', ', $tags ) . ']';
            }
            $text = "📝 {$note->title}{$tags_str}\n{$note->content}\n";
            if ( $total_chars + mb_strlen( $text ) > $max_chars ) break;
            $parts[]     = $text;
            $total_chars += mb_strlen( $text );
        }

        if ( empty( $parts ) ) return '';

        return "\n\n## 🧠 RESEARCH MEMORY:\n"
             . "Các ghi chú nghiên cứu liên quan từ các phiên trước:\n\n"
             . implode( "\n---\n", $parts );
    }

    /**
     * Extract search keywords from user message.
     * Simple approach: remove stop words, keep meaningful terms.
     */
    private function extract_search_keywords( $user_message ) {
        // Vietnamese + English stop words.
        $stop_words = [
            'là', 'và', 'của', 'có', 'cho', 'này', 'với', 'được', 'trong', 'không',
            'từ', 'một', 'các', 'những', 'đã', 'sẽ', 'đang', 'về', 'như', 'thế',
            'nào', 'gì', 'nào', 'bạn', 'tôi', 'mình', 'ơi', 'nhé', 'nhỉ', 'hả',
            'the', 'is', 'a', 'an', 'of', 'to', 'in', 'for', 'on', 'with', 'at',
            'hãy', 'xin', 'vui', 'lòng', 'giúp', 'cho', 'biết', 'thêm',
        ];

        $text = mb_strtolower( trim( $user_message ) );
        // Remove punctuation.
        $text = preg_replace( '/[^\p{L}\p{N}\s]/u', ' ', $text );
        $words = array_filter( preg_split( '/\s+/', $text ) );

        $keywords = [];
        foreach ( $words as $w ) {
            if ( mb_strlen( $w ) < 2 ) continue;
            if ( in_array( $w, $stop_words, true ) ) continue;
            $keywords[] = $w;
        }

        return implode( ' ', array_slice( $keywords, 0, 8 ) );
    }
}
