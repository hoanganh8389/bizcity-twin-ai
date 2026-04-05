<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Intent
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Rolling Memory — Real-time Conversation Goal Tracker
 *
 * Tracks the current intent conversation in a rolling window of 3-5 messages,
 * scoring bidirectionally (user goal progress vs bot satisfaction).
 *
 * Features:
 *   - Per-conversation rolling state (goal, window summary, scores)
 *   - Bidirectional scoring: user_goal_score (how close to goal) + bot_satisfaction_score
 *   - Auto-summarize on COMPLETED/CANCELLED
 *   - Provides real-time context for Context Builder
 *   - AJAX endpoint for UI display in right drawer
 *
 * Hooks:
 *   - `bizcity_intent_processed` @10  → track every engine result
 *   - `bizcity_chat_message_processed` @15 → after bot reply, update window
 *
 * @package BizCity_Intent
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Rolling_Memory {

    /** @var self|null */
    private static $instance = null;

    /** @var string Table name */
    private $table;

    /** Max messages in rolling window */
    const WINDOW_SIZE = 5;

    /** Score constants */
    const SCORE_MIN = 0;
    const SCORE_MAX = 100;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bizcity_memory_rolling';

        self::ensure_table();

        // Track intent processing results
        add_action( 'bizcity_intent_processed', [ $this, 'on_intent_processed' ], 10, 2 );

        // After bot reply → update rolling window
        add_action( 'bizcity_chat_message_processed', [ $this, 'on_message_processed' ], 15, 1 );

        // AJAX for UI
        add_action( 'wp_ajax_bizcity_rolling_memory_get', [ $this, 'ajax_get_active' ] );
    }

    /* ================================================================
     *  TABLE
     * ================================================================ */

    const DB_VERSION = '1.0';
    const DB_VERSION_OPTION = 'bizcity_memory_rolling_db_ver';

    public static function ensure_table() {
        static $checked = false;
        if ( $checked ) return;
        $checked = true;

        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;

        global $wpdb;
        $table   = $wpdb->prefix . 'bizcity_memory_rolling';

        $charset = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(255) NOT NULL DEFAULT '',
            conversation_id VARCHAR(64) NOT NULL DEFAULT '',

            -- Goal tracking
            goal VARCHAR(100) DEFAULT '',
            goal_label VARCHAR(255) DEFAULT '',

            -- Rolling window (condensed last N turns)
            window_summary TEXT COMMENT 'Condensed summary of last 3-5 turns',
            window_turn_count INT UNSIGNED DEFAULT 0,

            -- Bidirectional scoring
            user_goal_score TINYINT UNSIGNED DEFAULT 0 COMMENT '0-100: proximity to goal',
            bot_satisfaction_score TINYINT UNSIGNED DEFAULT 0 COMMENT '0-100: how satisfied user is with bot',

            -- Status
            status VARCHAR(20) DEFAULT 'active',
            completion_summary TEXT COMMENT 'Final summary when goal completes',

            -- Token tracking
            summary_token_count INT UNSIGNED DEFAULT 0 COMMENT 'Estimated tokens in window_summary',

            -- Counters
            total_turns INT UNSIGNED DEFAULT 0,

            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uniq_conversation (conversation_id),
            KEY idx_user_active (user_id, status),
            KEY idx_session (session_id),
            KEY idx_updated (updated_at)
        ) {$charset};";

        dbDelta( $sql );
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        error_log( "[BizCity_Rolling_Memory] Table {$table} migrated to v" . self::DB_VERSION );
    }

    /* ================================================================
     *  HOOK: bizcity_intent_processed
     *
     *  Called on every engine exit. We create/update rolling memory
     *  based on the intent result.
     * ================================================================ */

    /**
     * @param array $result  Engine result (reply, action, conversation_id, goal, status, ...)
     * @param array $params  Original request params (message, session_id, user_id, ...)
     */
    public function on_intent_processed( $result, $params ) {
        $conv_id    = $result['conversation_id'] ?? '';
        $user_id    = intval( $params['user_id'] ?? 0 );
        $session_id = $params['session_id'] ?? '';
        $message    = $params['message'] ?? '';
        $goal       = $result['goal'] ?? '';
        $goal_label = $result['goal_label'] ?? '';
        $status     = $result['status'] ?? '';
        $action     = $result['action'] ?? '';

        if ( ! $conv_id || ! $user_id ) return;

        // Skip passthrough-only (knowledge/emotion modes with no intent conv)
        if ( $action === 'passthrough' && empty( $goal ) ) return;

        global $wpdb;

        // Get or create rolling memory row
        $row = $this->get_by_conversation( $conv_id );

        if ( ! $row ) {
            // Create new rolling memory entry
            $wpdb->insert( $this->table, [
                'user_id'         => $user_id,
                'session_id'      => $session_id,
                'conversation_id' => $conv_id,
                'goal'            => $goal,
                'goal_label'      => $goal_label,
                'window_summary'  => '',
                'total_turns'     => 1,
                'status'          => 'active',
                'created_at'      => current_time( 'mysql' ),
                'updated_at'      => current_time( 'mysql' ),
            ] );
            $row = $this->get_by_conversation( $conv_id );
        }

        if ( ! $row ) return;

        // Update goal if changed
        $updates = [
            'total_turns' => intval( $row->total_turns ) + 1,
            'updated_at'  => current_time( 'mysql' ),
        ];

        if ( $goal && $goal !== $row->goal ) {
            $updates['goal']       = $goal;
            $updates['goal_label'] = $goal_label;
        }

        // Handle status transitions
        if ( in_array( $status, [ 'COMPLETED', 'CANCELLED', 'CLOSED', 'EXPIRED' ], true ) ) {
            $mapped_status = strtolower( $status );
            if ( $mapped_status === 'closed' ) $mapped_status = 'cancelled';
            if ( $mapped_status === 'expired' ) $mapped_status = 'cancelled';
            $updates['status'] = $mapped_status;

            // Generate completion summary asynchronously
            $this->generate_completion_summary( $row, $result, $params );
        }

        $wpdb->update( $this->table, $updates, [ 'id' => $row->id ] );
    }

    /* ================================================================
     *  HOOK: bizcity_chat_message_processed
     *
     *  After the bot reply is sent, update the rolling window summary
     *  and bidirectional scores.
     * ================================================================ */

    public function on_message_processed( $data ) {
        $intent_ctx = $GLOBALS['bizcity_intent_context'] ?? null;
        if ( ! $intent_ctx ) return;

        $conv_id = $intent_ctx['conversation_id'] ?? '';
        if ( ! $conv_id ) return;

        $row = $this->get_by_conversation( $conv_id );
        if ( ! $row || $row->status !== 'active' ) return;

        // Update window every 5 turns or if window is empty
        $turn_count = intval( $row->total_turns );
        if ( ! empty( $row->window_summary ) && $turn_count > 0 && $turn_count % 5 !== 0 ) {
            return;
        }

        // Throttle: at least 10 seconds between LLM-scored updates
        if ( ! empty( $row->updated_at ) ) {
            $last_update = strtotime( $row->updated_at );
            if ( $last_update && ( time() - $last_update ) < 10 ) {
                return;
            }
        }

        $this->update_window_and_scores( $row );
    }

    /* ================================================================
     *  WINDOW + SCORING — LLM-based update
     * ================================================================ */

    /**
     * Update rolling window summary and bidirectional scores.
     *
     * @param object $row  Rolling memory row.
     */
    private function update_window_and_scores( $row ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) return;

        // Fetch recent turns from the intent conversation
        $conv_mgr = BizCity_Intent_Conversation::instance();
        $turns    = $conv_mgr->get_turns( $row->conversation_id, self::WINDOW_SIZE * 2 );

        if ( count( $turns ) < 2 ) return;

        // Build transcript from last WINDOW_SIZE turns
        $recent = array_slice( $turns, -( self::WINDOW_SIZE * 2 ) );
        $transcript = '';
        foreach ( $recent as $t ) {
            $role = $t['role'] === 'user' ? 'User' : 'Bot';
            $text = mb_substr( $t['content'], 0, 200, 'UTF-8' );
            $transcript .= "{$role}: {$text}\n";
        }

        $goal_text = $row->goal_label ?: $row->goal;
        $prev_summary = $row->window_summary ?: '(chưa có)';

        $prompt = <<<PROMPT
Bạn là hệ thống đánh giá real-time cuộc hội thoại AI chatbot.

Mục tiêu người dùng: {$goal_text}
Tóm tắt trước đó: {$prev_summary}

Đoạn hội thoại gần nhất:
{$transcript}

Hãy trả về JSON (chỉ JSON, không giải thích):
{
  "window_summary": "Tóm tắt 2-3 câu về tiến độ cuộc hội thoại hiện tại",
  "user_goal_score": <0-100: mức độ gần đạt mục tiêu>,
  "bot_satisfaction_score": <0-100: mức độ hài lòng của user với bot>
}

Quy tắc chấm:
- user_goal_score: 0=mới bắt đầu, 30=đã hiểu yêu cầu, 60=đang xử lý, 80=gần xong, 100=hoàn thành
- bot_satisfaction_score: 0=user rất bực, 30=chưa hài lòng, 50=bình thường, 70=hài lòng, 100=rất hài lòng
PROMPT;

        $llm_result = bizcity_openrouter_chat(
            [ [ 'role' => 'user', 'content' => $prompt ] ],
            [
                'purpose'     => 'fast',
                'temperature' => 0.2,
                'max_tokens'  => 300,
            ]
        );

        if ( empty( $llm_result['success'] ) || empty( $llm_result['message'] ) ) return;

        $json = $this->extract_json( $llm_result['message'] );
        if ( ! $json ) return;

        global $wpdb;
        $wpdb->update( $this->table, [
            'window_summary'       => sanitize_text_field( $json['window_summary'] ?? '' ),
            'user_goal_score'      => $this->clamp_score( $json['user_goal_score'] ?? 0 ),
            'bot_satisfaction_score'=> $this->clamp_score( $json['bot_satisfaction_score'] ?? 50 ),
            'window_turn_count'    => count( $recent ),
            'summary_token_count'  => $this->estimate_tokens( $json['window_summary'] ?? '' ),
            'updated_at'           => current_time( 'mysql' ),
        ], [ 'id' => $row->id ] );
    }

    /**
     * Generate a completion summary when conversation ends.
     */
    private function generate_completion_summary( $row, $result, $params ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) return;

        $goal_text = $row->goal_label ?: $row->goal;
        $status    = $result['status'] ?? 'COMPLETED';
        $window    = $row->window_summary ?: '';

        // Fetch last turns for richer summary
        $conv_mgr = BizCity_Intent_Conversation::instance();
        $turns    = $conv_mgr->get_turns( $row->conversation_id, 10 );

        $transcript = '';
        foreach ( $turns as $t ) {
            $role = $t['role'] === 'user' ? 'User' : 'Bot';
            $text = mb_substr( $t['content'], 0, 200, 'UTF-8' );
            $transcript .= "{$role}: {$text}\n";
        }

        $prompt = <<<PROMPT
Hãy tóm tắt cuộc hội thoại vừa {$status}:
Mục tiêu: {$goal_text}
Trạng thái: {$status}
Tóm tắt rolling: {$window}

Hội thoại:
{$transcript}

Trả về 2-3 câu tóm tắt kết quả cuối cùng (tiếng Việt). Nêu rõ: đạt được gì, user hài lòng không, có gì dang dở.
PROMPT;

        $llm_result = bizcity_openrouter_chat(
            [ [ 'role' => 'user', 'content' => $prompt ] ],
            [
                'purpose'     => 'fast',
                'temperature' => 0.3,
                'max_tokens'  => 200,
            ]
        );

        if ( ! empty( $llm_result['success'] ) && ! empty( $llm_result['message'] ) ) {
            global $wpdb;
            $wpdb->update( $this->table, [
                'completion_summary' => sanitize_textarea_field( $llm_result['message'] ),
                'user_goal_score'    => ( $status === 'COMPLETED' ) ? 100 : intval( $row->user_goal_score ),
                'updated_at'         => current_time( 'mysql' ),
            ], [ 'id' => $row->id ] );
        }
    }

    /* ================================================================
     *  PUBLIC API — for Context Builder & UI
     * ================================================================ */

    /**
     * Get rolling memory row by conversation_id.
     *
     * @param  string $conv_id
     * @return object|null
     */
    public function get_by_conversation( $conv_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE conversation_id = %s LIMIT 1",
            $conv_id
        ) );
    }

    /**
     * Get all active rolling memories for a user.
     *
     * @param  int    $user_id
     * @param  string $session_id  Optional: filter to current session.
     * @return array
     */
    public function get_active_for_user( $user_id, $session_id = '' ) {
        global $wpdb;

        if ( $session_id ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE user_id = %d AND session_id = %s AND status = 'active'
                 ORDER BY updated_at DESC LIMIT 5",
                $user_id, $session_id
            ) );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d AND status = 'active'
             ORDER BY updated_at DESC LIMIT 5",
            $user_id
        ) );
    }

    /**
     * Get recently completed rolling memories (last 30 min).
     *
     * @param  int    $user_id
     * @param  int    $minutes  Window in minutes.
     * @return array
     */
    public function get_recently_completed( $user_id, $minutes = 30 ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE user_id = %d AND status IN ('completed','cancelled')
             AND updated_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)
             ORDER BY updated_at DESC LIMIT 5",
            $user_id, $minutes
        ) );
    }

    /**
     * Build context string for injection into system prompt.
     * Used by Context Builder.
     *
     * @param  int    $user_id
     * @param  string $session_id
     * @param  string $current_conv_id  Currently active conversation (if any).
     * @return string
     */
    public function build_context( $user_id, $session_id = '', $current_conv_id = '' ) {
        $actives  = $this->get_active_for_user( $user_id, $session_id );
        $recent   = $this->get_recently_completed( $user_id, 15 );

        if ( empty( $actives ) && empty( $recent ) ) return '';

        $parts = [];

        // Active goals
        foreach ( $actives as $rm ) {
            if ( $rm->conversation_id === $current_conv_id ) continue; // skip current — already in L2
            $label = $rm->goal_label ?: $rm->goal;
            $score = $rm->user_goal_score;
            $line  = "  - [{$score}%] {$label}";
            if ( $rm->window_summary ) {
                $line .= ': ' . mb_substr( $rm->window_summary, 0, 100, 'UTF-8' );
            }
            $parts[] = $line;
        }

        // Recently completed (brief)
        foreach ( $recent as $rm ) {
            $label = $rm->goal_label ?: $rm->goal;
            $emoji = $rm->status === 'completed' ? '✅' : '❌';
            $line  = "  - {$emoji} {$label}";
            if ( $rm->completion_summary ) {
                $line .= ': ' . mb_substr( $rm->completion_summary, 0, 100, 'UTF-8' );
            }
            $parts[] = $line;
        }

        if ( empty( $parts ) ) return '';

        return "## 🔄 ROLLING MEMORY (Theo dõi mục tiêu real-time)\n" . implode( "\n", $parts );
    }

    /**
     * Get enriched context from rolling memory for slot auto-fill.
     * Replaces direct webchat message queries.
     *
     * @param  int    $user_id
     * @param  string $session_id
     * @return string  User's recent substantive intent or empty.
     */
    public function get_recent_user_intent( $user_id, $session_id ) {
        $actives = $this->get_active_for_user( $user_id, $session_id );

        foreach ( $actives as $rm ) {
            if ( ! empty( $rm->window_summary ) ) {
                return $rm->window_summary;
            }
            if ( ! empty( $rm->goal_label ) ) {
                return $rm->goal_label;
            }
        }

        // Fallback: check recently completed
        $recent = $this->get_recently_completed( $user_id, 5 );
        foreach ( $recent as $rm ) {
            if ( ! empty( $rm->completion_summary ) ) {
                return $rm->completion_summary;
            }
        }

        return '';
    }

    /* ================================================================
     *  AJAX — UI endpoint
     * ================================================================ */

    /**
     * AJAX: Get active rolling memory for current user.
     * Used by the right-drawer UI component.
     */
    public function ajax_get_active() {
        check_ajax_referer( 'bizcity_nonce', 'nonce' );

        $user_id    = get_current_user_id();
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

        if ( ! $user_id ) {
            wp_send_json_error( 'Not logged in' );
        }

        $actives  = $this->get_active_for_user( $user_id, $session_id );
        $recent   = $this->get_recently_completed( $user_id, 30 );

        $items = [];

        foreach ( $actives as $rm ) {
            $items[] = [
                'id'              => $rm->id,
                'conversation_id' => $rm->conversation_id,
                'goal'            => $rm->goal,
                'goal_label'      => $rm->goal_label,
                'window_summary'  => $rm->window_summary,
                'user_goal_score' => intval( $rm->user_goal_score ),
                'bot_satisfaction'=> intval( $rm->bot_satisfaction_score ),
                'status'          => $rm->status,
                'total_turns'     => intval( $rm->total_turns ),
                'updated_at'      => $rm->updated_at,
            ];
        }

        foreach ( $recent as $rm ) {
            $items[] = [
                'id'               => $rm->id,
                'conversation_id'  => $rm->conversation_id,
                'goal'             => $rm->goal,
                'goal_label'       => $rm->goal_label,
                'completion_summary'=> $rm->completion_summary,
                'user_goal_score'  => intval( $rm->user_goal_score ),
                'bot_satisfaction' => intval( $rm->bot_satisfaction_score ),
                'status'           => $rm->status,
                'total_turns'      => intval( $rm->total_turns ),
                'updated_at'       => $rm->updated_at,
            ];
        }

        wp_send_json_success( $items );
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    private function clamp_score( $val ) {
        return max( self::SCORE_MIN, min( self::SCORE_MAX, intval( $val ) ) );
    }

    /**
     * Estimate token count for a text string.
     * Vietnamese text: ~1.5 tokens per word (rough heuristic).
     *
     * @param  string $text
     * @return int
     */
    private function estimate_tokens( $text ) {
        if ( empty( $text ) ) return 0;
        // Split on whitespace + punctuation for Vietnamese
        $words = preg_split( '/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
        return (int) ceil( count( $words ) * 1.5 );
    }

    /**
     * Extract JSON from LLM response (handles markdown code fences).
     */
    private function extract_json( $text ) {
        // Try direct parse
        $decoded = json_decode( $text, true );
        if ( is_array( $decoded ) ) return $decoded;

        // Try extracting from code fence
        if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m ) ) {
            $decoded = json_decode( $m[1], true );
            if ( is_array( $decoded ) ) return $decoded;
        }

        // Try finding first { ... }
        if ( preg_match( '/\{[^}]+\}/s', $text, $m ) ) {
            $decoded = json_decode( $m[0], true );
            if ( is_array( $decoded ) ) return $decoded;
        }

        return null;
    }
}
