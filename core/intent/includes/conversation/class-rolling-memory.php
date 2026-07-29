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

    const DB_VERSION = '1.3'; // [2026-07-29 Johnny Chu] R-CH-IDMEM — persist per-blog schema migration memory and retry state.
    const DB_VERSION_OPTION = 'bizcity_memory_rolling_db_ver';
    const MIGRATION_MEMORY_OPTION = 'bizcity_memory_rolling_migration_memory';
    const MIGRATION_RETRY_SECONDS = 3600;

    public static function ensure_table() {
        global $wpdb;
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        $cache_key = $blog_id . ':' . (string) $wpdb->prefix;
        static $checked = [];
        if ( isset( $checked[ $cache_key ] ) ) return;
        $checked[ $cache_key ] = true;

        $table = $wpdb->prefix . 'bizcity_memory_rolling';

        // [2026-07-28 Johnny Chu] HOTFIX P1 — the version option alone is not proof of schema: a
        // tenant can have DB_VERSION_OPTION already bumped while identity_uuid is still missing
        // (produced "Unknown column identity_uuid" at runtime). Verify the column before trusting it.
        $stored_version = get_option( self::DB_VERSION_OPTION );
        if ( $stored_version === self::DB_VERSION && self::has_identity_column( $table ) ) {
            return;
        }

        $migration_state = self::get_migration_state( $table );
        $now             = time();
        if ( 'complete' === (string) ( $migration_state['status'] ?? '' )
            && self::DB_VERSION === (string) ( $migration_state['version'] ?? '' )
            && self::has_identity_column( $table ) ) {
            update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
            return;
        }

        // [2026-07-29 Johnny Chu] R-CH-IDMEM — retain failed ALTER state per blog/table so a
        // routed shard does not repeat the same DDL and error log on every request.
        if ( 'blocked' === (string) ( $migration_state['status'] ?? '' )
            && (int) ( $migration_state['next_retry'] ?? 0 ) > $now ) {
            return;
        }

        // [2026-07-28 Johnny Chu] HOTFIX P1 — circuit-breaker backoff: if a previous attempt on
        // this request-cycle window already failed to add identity_uuid (shard write refused/
        // read-only/unhealthy), don't retry the lock+dbDelta dance on every single request — that
        // floods the log and re-runs DDL on every page load (R-PERF violation).
        $backoff_key = 'bzmem_mig_bo_' . md5( $table );
        if ( get_transient( $backoff_key ) ) {
            return;
        }

        // [2026-07-28 Johnny Chu] HOTFIX P1 — serialize concurrent migration attempts across
        // requests/workers so parallel DROP INDEX / dbDelta calls stop racing each other.
        $lock_name = 'bizcity_memory_migrate_' . md5( $table );
        $got_lock  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
        if ( 1 !== $got_lock ) {
            error_log( '[BizCity_Rolling_Memory] Could not acquire migration lock for ' . $table . ' — another process is likely migrating it.' );
            return;
        }

        try {
            // Re-check after acquiring the lock — another worker may have already finished migrating.
            if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION && self::has_identity_column( $table ) ) {
                return;
            }

            $charset = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';

            // [2026-07-29 Johnny Chu] R-CH-IDMEM — repair the existing tenant
            // table with an explicit ALTER before dbDelta; new installs still
            // fall through to CREATE TABLE below.
            $table_type = $wpdb->get_var( $wpdb->prepare(
                'SELECT TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1',
                $table
            ) );
            if ( 'BASE TABLE' === $table_type && ! self::has_identity_column( $table ) ) {
                $previous = $wpdb->suppress_errors( true );
                $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN identity_uuid CHAR(36) NOT NULL DEFAULT '' AFTER user_id" );
                $wpdb->suppress_errors( $previous );
            }

            $sql = "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                blog_id INT UNSIGNED NOT NULL DEFAULT 1,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                identity_uuid CHAR(36) NOT NULL DEFAULT '',
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

                UNIQUE KEY uniq_identity_conversation (blog_id, identity_uuid, user_id, conversation_id),
                KEY idx_identity_active (blog_id, identity_uuid, status),
                KEY idx_user_active (user_id, status),
                KEY idx_session (session_id),
                KEY idx_updated (updated_at)
            ) {$charset};";

            dbDelta( $sql );

            // [2026-07-28 Johnny Chu] R-CH-IDMEM — remove the pre-UUID key whenever it exists, including installs without a version option.
            $legacy_index_exists = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
                $table,
                'uniq_conversation'
            ) );
            if ( $legacy_index_exists ) {
                $dropped = $wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_conversation" );
                if ( false === $dropped ) {
                    error_log( '[BizCity_Rolling_Memory] Could not drop the legacy rolling uniqueness index.' );
                }
            }

            // [2026-07-28 Johnny Chu] HOTFIX — only mark migration complete after verifying identity_uuid
            // actually exists; dbDelta() can silently no-op on some routed shard connections.
            if ( self::has_identity_column( $table ) ) {
                update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
                self::remember_migration_state( $table, [
                    'version'      => self::DB_VERSION,
                    'status'       => 'complete',
                    'last_attempt' => time(),
                    'next_retry'   => 0,
                    'error_hash'   => '',
                    'last_log'     => time(),
                ] );
                delete_transient( $backoff_key );
                error_log( "[BizCity_Rolling_Memory] Table {$table} migrated to v" . self::DB_VERSION );
            } else {
                // [2026-07-29 Johnny Chu] R-CH-IDMEM — remember the failed ALTER for one hour;
                // only emit the same failure again after one day or when its reason changes.
                $db_error = trim( preg_replace( '/\s+/', ' ', (string) $wpdb->last_error ) );
                if ( strlen( $db_error ) > 180 ) {
                    $db_error = substr( $db_error, 0, 180 );
                }
                $error_hash = md5( $db_error !== '' ? $db_error : 'schema_missing' );
                $last_log   = (int) ( $migration_state['last_log'] ?? 0 );
                $should_log = $error_hash !== (string) ( $migration_state['error_hash'] ?? '' )
                    || ( $last_log + DAY_IN_SECONDS ) <= time();
                self::remember_migration_state( $table, [
                    'version'      => self::DB_VERSION,
                    'status'       => 'blocked',
                    'last_attempt' => time(),
                    'next_retry'   => time() + self::MIGRATION_RETRY_SECONDS,
                    'error_hash'   => $error_hash,
                    'last_log'     => $should_log ? time() : $last_log,
                ] );
                set_transient( $backoff_key, 1, self::MIGRATION_RETRY_SECONDS );
                // [2026-07-28 Johnny Chu] HOTFIX P1 — retain the routed DB failure reason so a
                // silent dbDelta/no-op can be distinguished from read-only or missing privileges.
                if ( $should_log ) {
                    error_log( "[BizCity_Rolling_Memory] Table {$table} migration incomplete — identity_uuid column still missing. Backing off " . self::MIGRATION_RETRY_SECONDS . "s (check shard write path / R-MSDB routing). db_error=" . ( $db_error !== '' ? $db_error : 'none' ) );
                }
            }
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
        }
    }

    /**
     * Cheap information_schema check — not cached because ensure_table() only runs this path
     * when the version option check already failed (rare, once-per-migration-need).
     */
    private static function has_identity_column( $table ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'identity_uuid' LIMIT 1",
            $table
        ) );
    }

    private static function get_migration_state( $table ) {
        $memory = get_option( self::MIGRATION_MEMORY_OPTION, [] );
        $key    = md5( (string) $table );
        return is_array( $memory ) && isset( $memory[ $key ] ) && is_array( $memory[ $key ] )
            ? $memory[ $key ]
            : [];
    }

    private static function remember_migration_state( $table, $state ) {
        $memory = get_option( self::MIGRATION_MEMORY_OPTION, [] );
        if ( ! is_array( $memory ) ) {
            $memory = [];
        }
        $memory[ md5( (string) $table ) ] = $state;
        update_option( self::MIGRATION_MEMORY_OPTION, $memory, false );
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
        // [2026-07-28 Johnny Chu] R-CH-IDMEM — reject rolling writes without a verified stable identity.
        $scope      = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::for_write( $params )
            : null;
        if ( ! $scope ) return;
        $user_id    = (int) $scope['user_id'];
        $identity_uuid = (string) $scope['identity_uuid'];
        $session_id = (string) $scope['session_id'];
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
        $row = $this->get_by_conversation( $conv_id, $identity_uuid );

        if ( ! $row ) {
            // Create new rolling memory entry
            $wpdb->insert( $this->table, [
                'blog_id'         => (int) $scope['blog_id'],
                'user_id'         => $user_id,
                'identity_uuid'   => $identity_uuid,
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
            $row = $this->get_by_conversation( $conv_id, $identity_uuid );
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

        // Wave 2.8d D5 — dual-write mirror into unified `bizcity_memory`.
        // Refetch latest row data so the mirror reflects post-update state.
        $latest = $this->get_by_conversation( $conv_id, $identity_uuid );
        if ( $latest ) {
            do_action( 'bizcity_memory_mirror_write', 'rolling', [
                'blog_id'                => get_current_blog_id(),
                'user_id'                => (int) $latest->user_id,
                'identity_uuid'          => (string) $latest->identity_uuid,
                'session_id'             => (string) $latest->session_id,
                'conversation_id'        => (string) $latest->conversation_id,
                'goal'                   => (string) $latest->goal,
                'goal_label'             => (string) $latest->goal_label,
                'window_summary'         => (string) $latest->window_summary,
                'window_turn_count'      => (int) $latest->window_turn_count,
                'user_goal_score'        => (int) $latest->user_goal_score,
                'bot_satisfaction_score' => (int) $latest->bot_satisfaction_score,
                'status'                 => (string) $latest->status,
            ], 'update' );
        }
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

        $identity_uuid = (string) ( $intent_ctx['identity_uuid'] ?? '' );
        $row = $this->get_by_conversation( $conv_id, $identity_uuid );
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
    public function get_by_conversation( $conv_id, $identity_uuid = '' ) {
        global $wpdb;

        // [2026-07-28 Johnny Chu] R-CH-IDMEM — a conversation id without its UUID is not a safe owner selector.
        if ( trim( (string) $identity_uuid ) === '' ) {
            return null;
        }

        $where  = array( 'conversation_id = %s' );
        $params = array( $conv_id );
        if ( $identity_uuid !== '' ) {
            $where[]  = 'blog_id = %d AND identity_uuid = %s';
            $params[] = get_current_blog_id();
            $params[] = $identity_uuid;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . " LIMIT 1",
            $params
        ) );
    }

    /**
     * Get all active rolling memories for a user.
     *
     * @param  int    $user_id
     * @param  string $session_id  Optional: filter to current session.
     * @return array
     */
    public function get_active_for_user( $user_id, $session_id = '', $identity_uuid = '' ) {
        global $wpdb;
        $scope = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::resolve( array( 'user_id' => (int) $user_id, 'session_id' => $session_id, 'identity_uuid' => $identity_uuid ) )
            : array( 'user_id' => (int) $user_id, 'session_id' => (string) $session_id, 'identity_uuid' => (string) $identity_uuid );
        $where  = array( 'blog_id = %d', "status = 'active'" );
        $params = array( get_current_blog_id() );
        if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
            if ( ! BizCity_Memory_Identity_Scope::append_read_scope( $where, $params, $scope ) ) return array();
        } else {
            $where[] = 'identity_uuid = %s AND user_id = %d';
            $params[] = '';
            $params[] = (int) $scope['user_id'];
        }

        if ( $session_id ) {
            $where[] = 'session_id = %s';
            $params[] = $session_id;
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . "
             ORDER BY updated_at DESC LIMIT 5",
            $params
        ) );
    }

    /**
     * Get recently completed rolling memories (last 30 min).
     *
     * @param  int    $user_id
     * @param  int    $minutes  Window in minutes.
     * @return array
     */
    public function get_recently_completed( $user_id, $minutes = 30, $identity_uuid = '' ) {
        global $wpdb;
        $scope = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::resolve( array( 'user_id' => (int) $user_id, 'identity_uuid' => $identity_uuid ) )
            : array( 'user_id' => (int) $user_id, 'identity_uuid' => (string) $identity_uuid );
        $where  = array( 'blog_id = %d', "status IN ('completed','cancelled')", 'updated_at >= DATE_SUB(NOW(), INTERVAL %d MINUTE)' );
        $params = array( get_current_blog_id(), $minutes );
        if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
            if ( ! BizCity_Memory_Identity_Scope::append_read_scope( $where, $params, $scope ) ) return array();
        } else {
            $where[] = 'identity_uuid = %s AND user_id = %d';
            $params[] = '';
            $params[] = (int) $scope['user_id'];
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE " . implode( ' AND ', $where ) . "
             ORDER BY updated_at DESC LIMIT 5",
            $params
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
    public function build_context( $user_id, $session_id = '', $current_conv_id = '', $identity_uuid = '' ) {
        $actives  = $this->get_active_for_user( $user_id, $session_id, $identity_uuid );
        $recent   = $this->get_recently_completed( $user_id, 15, $identity_uuid );

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
    public function get_recent_user_intent( $user_id, $session_id, $identity_uuid = '' ) {
        $actives = $this->get_active_for_user( $user_id, $session_id, $identity_uuid );

        foreach ( $actives as $rm ) {
            if ( ! empty( $rm->window_summary ) ) {
                return $rm->window_summary;
            }
            if ( ! empty( $rm->goal_label ) ) {
                return $rm->goal_label;
            }
        }

        // Fallback: check recently completed
        $recent = $this->get_recently_completed( $user_id, 5, $identity_uuid );
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

// [2026-07-28 Johnny Chu] R-CR — register rolling schema before the installer can run dbDelta().
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
    BizCity_Schema_Registry::register(
        'bizcity_memory_rolling',
        'core.intent.memory.rolling',
        BizCity_Rolling_Memory::DB_VERSION,
        BizCity_Rolling_Memory::DB_VERSION_OPTION,
        array( 'BizCity_Rolling_Memory', 'ensure_table' )
    );
}
