<?php
/**
 * BizCity Episodic Memory — Long-term Event Storage
 *
 * Stores significant events from conversations: pain points, satisfaction moments,
 * successful/cancelled goals, tool preferences, habit patterns.
 *
 * Unlike User Memory (identity/preferences), Episodic Memory tracks EVENTS:
 *   - "User tried HeyGen for avatar, was satisfied"
 *   - "User cancelled tarot reading twice"
 *   - "User gets frustrated when bot asks too many questions"
 *
 * Two ingestion paths:
 *   1. Real-time: on conversation COMPLETED/CANCELLED → extract events
 *   2. Cron (daily): aggregate patterns from recent conversations → habits
 *
 * @package BizCity_Intent
 * @since   4.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Episodic_Memory {

    /** @var self|null */
    private static $instance = null;

    /** @var string */
    private $table;

    /** Event types */
    const TYPE_GOAL_SUCCESS   = 'goal_success';
    const TYPE_GOAL_CANCEL    = 'goal_cancel';
    const TYPE_PAIN_POINT     = 'pain_point';
    const TYPE_SATISFACTION   = 'satisfaction';
    const TYPE_TOOL_USAGE     = 'tool_usage';
    const TYPE_HABIT          = 'habit';
    const TYPE_DECISION       = 'decision';
    const TYPE_PREF_CHANGE    = 'preference_change';

    /** Max events per user */
    const MAX_PER_USER = 500;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'bizcity_memory_episodic';

        self::ensure_table();

        // Real-time: on intent completion → extract events
        add_action( 'bizcity_intent_processed', [ $this, 'on_intent_processed' ], 12, 2 );

        // Daily cron: aggregate habits
        add_action( 'bizcity_episodic_daily_aggregate', [ $this, 'cron_daily_aggregate' ] );
    }

    /* ================================================================
     *  TABLE
     * ================================================================ */

    const DB_VERSION = '1.0';
    const DB_VERSION_OPTION = 'bizcity_memory_episodic_db_ver';

    public static function ensure_table() {
        static $checked = false;
        if ( $checked ) return;
        $checked = true;

        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_memory_episodic';

        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            blog_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_id VARCHAR(255) DEFAULT '',

            -- Event classification
            event_type VARCHAR(50) NOT NULL DEFAULT 'fact',
            event_key VARCHAR(191) NOT NULL DEFAULT '',
            event_text TEXT NOT NULL,

            -- Source context
            source_conversation_id VARCHAR(64) DEFAULT '',
            source_goal VARCHAR(100) DEFAULT '',
            source_tool VARCHAR(100) DEFAULT '',

            -- Scoring
            importance TINYINT UNSIGNED DEFAULT 50,
            times_seen INT UNSIGNED DEFAULT 1,
            token_count INT UNSIGNED DEFAULT 0 COMMENT 'Estimated tokens in event_text',

            -- Metadata
            metadata TEXT,

            -- Timestamps
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_user (blog_id, user_id),
            KEY idx_event_type (event_type),
            KEY idx_source_conv (source_conversation_id),
            KEY idx_source_tool (source_tool),
            KEY idx_last_seen (last_seen),
            UNIQUE KEY unique_event (blog_id, user_id, event_key)
        ) {$charset};";

        dbDelta( $sql );
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
        error_log( "[BizCity_Episodic_Memory] Table {$table} migrated to v" . self::DB_VERSION );
    }

    /* ================================================================
     *  REAL-TIME INGESTION — on conversation complete/cancel
     * ================================================================ */

    /**
     * @param array $result  Engine result.
     * @param array $params  Original request params.
     */
    public function on_intent_processed( $result, $params ) {
        $status  = $result['status'] ?? '';
        $conv_id = $result['conversation_id'] ?? '';
        $user_id = intval( $params['user_id'] ?? 0 );
        $goal    = $result['goal'] ?? '';
        $action  = $result['action'] ?? '';
        $meta    = $result['meta'] ?? [];

        if ( ! $user_id || ! $conv_id ) return;

        // Only record events on terminal states + tool executions
        $is_terminal = in_array( $status, [ 'COMPLETED', 'CANCELLED', 'CLOSED' ], true );
        $is_tool     = in_array( $action, [ 'complete', 'call_tool' ], true ) && ! empty( $meta['tool_name'] );

        if ( ! $is_terminal && ! $is_tool ) return;

        $blog_id    = get_current_blog_id();
        $session_id = $params['session_id'] ?? '';
        $tool_name  = $meta['tool_name'] ?? '';
        $goal_label = $result['goal_label'] ?? $goal;

        // ── 1. Goal success/cancel event ──
        if ( $is_terminal && $goal ) {
            $type = ( $status === 'COMPLETED' ) ? self::TYPE_GOAL_SUCCESS : self::TYPE_GOAL_CANCEL;
            $key  = "{$type}:{$goal}:" . wp_hash( $conv_id );

            $text = ( $status === 'COMPLETED' )
                ? "Hoàn thành mục tiêu «{$goal_label}»"
                : "Hủy/đóng mục tiêu «{$goal_label}»";

            // Enrich with completion summary from Rolling Memory
            $completion_summary = '';
            if ( class_exists( 'BizCity_Rolling_Memory' ) ) {
                $rm_row = BizCity_Rolling_Memory::instance()->get_by_conversation( $conv_id );
                if ( $rm_row && $rm_row->completion_summary ) {
                    $text .= '. ' . $rm_row->completion_summary;
                    $completion_summary = $rm_row->completion_summary;
                }
            }

            $this->upsert_event( [
                'blog_id'                 => $blog_id,
                'user_id'                 => $user_id,
                'session_id'              => $session_id,
                'event_type'              => $type,
                'event_key'               => $key,
                'event_text'              => $text,
                'source_conversation_id'  => $conv_id,
                'source_goal'             => $goal,
                'source_tool'             => $tool_name,
                'importance'              => ( $status === 'COMPLETED' ) ? 70 : 40,
                'metadata'                => wp_json_encode( [
                    'goal_label'          => $goal_label,
                    'completion_summary'  => $completion_summary,
                    'action'              => $action,
                ] ),
            ] );
        }

        // ── 2. Tool usage event ──
        if ( $is_tool && $tool_name ) {
            $tool_key = self::TYPE_TOOL_USAGE . ":{$goal}:{$tool_name}";

            $this->upsert_event( [
                'blog_id'                => $blog_id,
                'user_id'                => $user_id,
                'session_id'             => $session_id,
                'event_type'             => self::TYPE_TOOL_USAGE,
                'event_key'              => $tool_key,
                'event_text'             => "Sử dụng tool «{$tool_name}» cho «{$goal_label}»",
                'source_conversation_id' => $conv_id,
                'source_goal'            => $goal,
                'source_tool'            => $tool_name,
                'importance'             => 50,
            ] );
        }

        // ── 3. Post-tool satisfaction ──
        if ( $action === 'post_tool_satisfied' ) {
            $completed_goal = $meta['completed_goal'] ?? $goal_label;
            $this->upsert_event( [
                'blog_id'                => $blog_id,
                'user_id'                => $user_id,
                'session_id'             => $session_id,
                'event_type'             => self::TYPE_SATISFACTION,
                'event_key'              => self::TYPE_SATISFACTION . ":{$goal}:" . wp_hash( $conv_id ),
                'event_text'             => "User hài lòng với kết quả «{$completed_goal}»",
                'source_conversation_id' => $conv_id,
                'source_goal'            => $goal,
                'importance'             => 80,
            ] );
        }
    }

    /* ================================================================
     *  CRON — Daily aggregate
     *
     *  Scans completed conversations from the last 24h,
     *  extracts habit patterns via LLM.
     * ================================================================ */

    public function cron_daily_aggregate() {
        global $wpdb;

        $rm_table = $wpdb->prefix . 'bizcity_memory_rolling';

        // Get completed conversations from last 24h, grouped by user
        $rows = $wpdb->get_results(
            "SELECT user_id, GROUP_CONCAT(goal_label SEPARATOR ' | ') AS goals,
                    GROUP_CONCAT(completion_summary SEPARATOR ' || ') AS summaries,
                    COUNT(*) AS conv_count
             FROM {$rm_table}
             WHERE status IN ('completed','cancelled')
             AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY user_id
             HAVING conv_count >= 2
             LIMIT 50"
        );

        if ( empty( $rows ) ) return;

        foreach ( $rows as $row ) {
            $this->extract_habits_for_user( intval( $row->user_id ), $row->goals, $row->summaries );
        }
    }

    /**
     * LLM-based habit extraction for a user from recent conversation summaries.
     */
    private function extract_habits_for_user( $user_id, $goals, $summaries ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) return;

        $prompt = <<<PROMPT
Phân tích các mục tiêu và kết quả hội thoại của người dùng trong 24h qua:

Mục tiêu: {$goals}
Tóm tắt: {$summaries}

Trích xuất các thói quen/xu hướng đáng chú ý. Trả về JSON array:
[
  {"habit": "mô tả thói quen/xu hướng ngắn gọn", "importance": <50-90>}
]

Chỉ trả về nếu thực sự phát hiện pattern rõ ràng. Nếu không có → trả [].
PROMPT;

        $llm = bizcity_openrouter_chat(
            [ [ 'role' => 'user', 'content' => $prompt ] ],
            [
                'purpose'     => 'fast',
                'temperature' => 0.3,
                'max_tokens'  => 400,
            ]
        );

        if ( empty( $llm['success'] ) || empty( $llm['message'] ) ) return;

        $habits = $this->extract_json_array( $llm['message'] );
        if ( ! $habits || ! is_array( $habits ) ) return;

        $blog_id = get_current_blog_id();

        foreach ( $habits as $h ) {
            if ( empty( $h['habit'] ) ) continue;

            $key = self::TYPE_HABIT . ':' . md5( mb_strtolower( $h['habit'] ) );

            $this->upsert_event( [
                'blog_id'    => $blog_id,
                'user_id'    => $user_id,
                'event_type' => self::TYPE_HABIT,
                'event_key'  => $key,
                'event_text' => sanitize_text_field( $h['habit'] ),
                'importance' => intval( $h['importance'] ?? 60 ),
                'metadata'   => wp_json_encode( [ 'source' => 'daily_cron', 'date' => current_time( 'Y-m-d' ) ] ),
            ] );
        }
    }

    /* ================================================================
     *  PUBLIC API — for Context Builder
     * ================================================================ */

    /**
     * Build episodic context string for system prompt injection.
     *
     * @param  int    $user_id
     * @param  string $current_goal  Current goal (to boost relevant events).
     * @return string
     */
    public function build_context( $user_id, $current_goal = '' ) {
        global $wpdb;

        $blog_id = get_current_blog_id();

        // Get top events by importance + recency
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, event_text, importance, times_seen, source_goal, source_tool, last_seen
             FROM {$this->table}
             WHERE blog_id = %d AND user_id = %d
             ORDER BY importance DESC, last_seen DESC
             LIMIT 15",
            $blog_id, $user_id
        ) );

        if ( empty( $events ) ) return '';

        $lines = [];

        // Prioritize: events related to current goal first
        $related   = [];
        $unrelated = [];
        foreach ( $events as $e ) {
            if ( $current_goal && $e->source_goal === $current_goal ) {
                $related[] = $e;
            } else {
                $unrelated[] = $e;
            }
        }

        $sorted = array_merge( $related, $unrelated );
        $sorted = array_slice( $sorted, 0, 8 ); // keep compact

        foreach ( $sorted as $e ) {
            $emoji = $this->type_emoji( $e->event_type );
            $freq  = $e->times_seen > 1 ? " (×{$e->times_seen})" : '';
            $lines[] = "  - {$emoji} {$e->event_text}{$freq}";
        }

        if ( empty( $lines ) ) return '';

        return "## 📖 EPISODIC MEMORY (Lịch sử trải nghiệm)\n" . implode( "\n", $lines );
    }

    /**
     * Check if user has used a specific tool before.
     *
     * @param  int    $user_id
     * @param  string $tool_name
     * @return object|null  Event row or null.
     */
    public function has_tool_history( $user_id, $tool_name ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE blog_id = %d AND user_id = %d AND event_type = %s AND source_tool = %s
             ORDER BY last_seen DESC LIMIT 1",
            get_current_blog_id(), $user_id, self::TYPE_TOOL_USAGE, $tool_name
        ) );
    }

    /**
     * Get all habits for a user.
     *
     * @param  int   $user_id
     * @return array
     */
    public function get_habits( $user_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE blog_id = %d AND user_id = %d AND event_type = %s
             ORDER BY importance DESC, times_seen DESC
             LIMIT 20",
            get_current_blog_id(), $user_id, self::TYPE_HABIT
        ) );
    }

    /* ================================================================
     *  UPSERT — insert or update on duplicate key
     * ================================================================ */

    private function upsert_event( array $data ) {
        global $wpdb;

        $data = wp_parse_args( $data, [
            'blog_id'                => get_current_blog_id(),
            'user_id'                => 0,
            'session_id'             => '',
            'event_type'             => 'fact',
            'event_key'              => '',
            'event_text'             => '',
            'source_conversation_id' => '',
            'source_goal'            => '',
            'source_tool'            => '',
            'importance'             => 50,
            'times_seen'             => 1,
            'metadata'               => null,
        ] );

        // Enforce limits — delete oldest if over MAX_PER_USER
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE blog_id = %d AND user_id = %d",
            $data['blog_id'], $data['user_id']
        ) );
        if ( $count >= self::MAX_PER_USER ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE blog_id = %d AND user_id = %d
                 ORDER BY importance ASC, updated_at ASC
                 LIMIT 10",
                $data['blog_id'], $data['user_id']
            ) );
        }

        // Check if event_key already exists
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, times_seen FROM {$this->table}
             WHERE blog_id = %d AND user_id = %d AND event_key = %s",
            $data['blog_id'], $data['user_id'], $data['event_key']
        ) );

        if ( $existing ) {
            // Update: bump times_seen + importance
            $new_importance = min( 100, intval( $data['importance'] ) + 5 );
            $wpdb->update( $this->table, [
                'event_text'  => $data['event_text'],
                'times_seen'  => intval( $existing->times_seen ) + 1,
                'importance'  => $new_importance,
                'token_count' => $this->estimate_tokens( $data['event_text'] ),
                'last_seen'   => current_time( 'mysql' ),
                'metadata'    => $data['metadata'],
            ], [ 'id' => $existing->id ] );
        } else {
            // Insert new
            $wpdb->insert( $this->table, [
                'blog_id'                => $data['blog_id'],
                'user_id'                => $data['user_id'],
                'session_id'             => $data['session_id'],
                'event_type'             => $data['event_type'],
                'event_key'              => $data['event_key'],
                'event_text'             => $data['event_text'],
                'source_conversation_id' => $data['source_conversation_id'],
                'source_goal'            => $data['source_goal'],
                'source_tool'            => $data['source_tool'],
                'importance'             => intval( $data['importance'] ),
                'times_seen'             => 1,
                'token_count'            => $this->estimate_tokens( $data['event_text'] ),
                'metadata'               => $data['metadata'],
                'last_seen'              => current_time( 'mysql' ),
                'created_at'             => current_time( 'mysql' ),
            ] );
        }
    }

    /* ================================================================
     *  HELPERS
     * ================================================================ */

    /**
     * Rough token estimate — 1 token ≈ 4 chars for mixed vi/en.
     */
    private function estimate_tokens( string $text ): int {
        return (int) ceil( mb_strlen( $text ) / 4 );
    }

    private function type_emoji( $type ) {
        $map = [
            self::TYPE_GOAL_SUCCESS => '✅',
            self::TYPE_GOAL_CANCEL  => '❌',
            self::TYPE_PAIN_POINT   => '😤',
            self::TYPE_SATISFACTION => '😊',
            self::TYPE_TOOL_USAGE   => '🔧',
            self::TYPE_HABIT        => '🔄',
            self::TYPE_DECISION     => '🎯',
            self::TYPE_PREF_CHANGE  => '🔀',
        ];
        return $map[ $type ] ?? '📌';
    }

    /**
     * Extract JSON array from LLM response.
     */
    private function extract_json_array( $text ) {
        $decoded = json_decode( $text, true );
        if ( is_array( $decoded ) ) return $decoded;

        if ( preg_match( '/```(?:json)?\s*(\[.*?\])\s*```/s', $text, $m ) ) {
            $decoded = json_decode( $m[1], true );
            if ( is_array( $decoded ) ) return $decoded;
        }

        if ( preg_match( '/\[.*\]/s', $text, $m ) ) {
            $decoded = json_decode( $m[0], true );
            if ( is_array( $decoded ) ) return $decoded;
        }

        return null;
    }
}
