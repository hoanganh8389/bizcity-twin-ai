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

    const DB_VERSION = '1.3'; // [2026-07-29 Johnny Chu] R-CH-IDMEM — persist per-blog schema migration memory and retry state.
    const DB_VERSION_OPTION = 'bizcity_memory_episodic_db_ver';
    const MIGRATION_MEMORY_OPTION = 'bizcity_memory_episodic_migration_memory';
    const MIGRATION_RETRY_SECONDS = 3600;

    public static function ensure_table() {
        global $wpdb;
        $blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
        $cache_key = $blog_id . ':' . (string) $wpdb->prefix;
        static $checked = [];
        if ( isset( $checked[ $cache_key ] ) ) return;
        $checked[ $cache_key ] = true;

        $table = $wpdb->prefix . 'bizcity_memory_episodic';

        // [2026-07-28 Johnny Chu] HOTFIX P1 — the version option alone is not proof of schema: a
        // tenant can have DB_VERSION_OPTION already bumped while identity_uuid is still missing
        // (produced "Unknown column identity_uuid" at runtime, e.g. cron_daily_aggregate()).
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
            error_log( '[BizCity_Episodic_Memory] Could not acquire migration lock for ' . $table . ' — another process is likely migrating it.' );
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

            // [2026-07-28 Johnny Chu] R-CH-IDMEM — remove the UUID-only key whenever it exists, including installs without a version option.
            $legacy_index_exists = (bool) $wpdb->get_var( $wpdb->prepare(
                'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s LIMIT 1',
                $table,
                'unique_event'
            ) );
            if ( $legacy_index_exists ) {
                $dropped = $wpdb->query( "ALTER TABLE {$table} DROP INDEX unique_event" );
                if ( false === $dropped ) {
                    error_log( '[BizCity_Episodic_Memory] Could not drop the previous episodic uniqueness index.' );
                }
            }

            $sql = "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                blog_id INT UNSIGNED NOT NULL DEFAULT 1,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                identity_uuid CHAR(36) NOT NULL DEFAULT '',
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
                KEY idx_identity (blog_id, identity_uuid),
                KEY idx_event_type (event_type),
                KEY idx_source_conv (source_conversation_id),
                KEY idx_source_tool (source_tool),
                KEY idx_last_seen (last_seen),
                UNIQUE KEY unique_event (blog_id, identity_uuid, user_id, event_key)
            ) {$charset};";

            dbDelta( $sql );

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
                error_log( "[BizCity_Episodic_Memory] Table {$table} migrated to v" . self::DB_VERSION );
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
                    error_log( "[BizCity_Episodic_Memory] Table {$table} migration incomplete — identity_uuid column still missing. Backing off " . self::MIGRATION_RETRY_SECONDS . "s (check shard write path / R-MSDB routing). db_error=" . ( $db_error !== '' ? $db_error : 'none' ) );
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
     *  REAL-TIME INGESTION — on conversation complete/cancel
     * ================================================================ */

    /**
     * @param array $result  Engine result.
     * @param array $params  Original request params.
     */
    public function on_intent_processed( $result, $params ) {
        $status  = $result['status'] ?? '';
        $conv_id = $result['conversation_id'] ?? '';
        // [2026-07-28 Johnny Chu] R-CH-IDMEM — event ownership must resolve before an episodic row is created.
        $scope   = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::for_write( $params )
            : null;
        if ( ! $scope ) return;
        $user_id = (int) $scope['user_id'];
        $identity_uuid = (string) $scope['identity_uuid'];
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
                // [2026-07-28 Johnny Chu] R-CH-IDMEM — never resolve a rolling row by conversation_id alone.
                $rm_row = BizCity_Rolling_Memory::instance()->get_by_conversation( $conv_id, $identity_uuid );
                if ( $rm_row && $rm_row->completion_summary ) {
                    $text .= '. ' . $rm_row->completion_summary;
                    $completion_summary = $rm_row->completion_summary;
                }
            }

            $this->upsert_event( [
                'blog_id'                 => $blog_id,
                'user_id'                 => $user_id,
                'identity_uuid'           => $identity_uuid,
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
                'identity_uuid'          => $identity_uuid,
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
                'identity_uuid'          => $identity_uuid,
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

        // [2026-07-28 Johnny Chu] HOTFIX P1 — runtime schema guard: a tenant whose rolling table
        // hasn't finished the identity_uuid migration yet (e.g. lock contention on a busy shard)
        // must not be queried directly — this previously threw "Unknown column identity_uuid".
        $has_identity = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'identity_uuid' LIMIT 1",
            $rm_table
        ) );
        if ( ! $has_identity ) {
            error_log( '[BizCity_Episodic_Memory] Skipping daily aggregate — identity_uuid column missing on ' . $rm_table . ' (schema not migrated yet).' );
            return;
        }

        // Get completed conversations from last 24h, grouped by user
        $rows = $wpdb->get_results(
            "SELECT identity_uuid, MAX(user_id) AS user_id, GROUP_CONCAT(goal_label SEPARATOR ' | ') AS goals,
                    GROUP_CONCAT(completion_summary SEPARATOR ' || ') AS summaries,
                    COUNT(*) AS conv_count
             FROM {$rm_table}
             WHERE identity_uuid <> '' AND status IN ('completed','cancelled')
             AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY identity_uuid
             HAVING conv_count >= 2
             LIMIT 50"
        );

        if ( empty( $rows ) ) return;

        foreach ( $rows as $row ) {
            $this->extract_habits_for_user( intval( $row->user_id ), $row->goals, $row->summaries, (string) $row->identity_uuid );
        }
    }

    /**
     * LLM-based habit extraction for a user from recent conversation summaries.
     */
    private function extract_habits_for_user( $user_id, $goals, $summaries, $identity_uuid = '' ) {
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
                'identity_uuid' => (string) $identity_uuid,
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
    public function build_context( $user_id, $current_goal = '', $identity_uuid = '' ) {
        global $wpdb;

        $blog_id = get_current_blog_id();
		$scope = class_exists( 'BizCity_Memory_Identity_Scope' )
			? BizCity_Memory_Identity_Scope::resolve( array( 'user_id' => (int) $user_id, 'identity_uuid' => $identity_uuid ) )
			: array( 'user_id' => (int) $user_id, 'identity_uuid' => (string) $identity_uuid );
		$where  = array( 'blog_id = %d' );
		$params = array( $blog_id );
		if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
			if ( ! BizCity_Memory_Identity_Scope::append_read_scope( $where, $params, $scope ) ) return '';
		} elseif ( (int) $scope['user_id'] > 0 ) {
			$where[] = 'identity_uuid = %s AND user_id = %d';
			$params[] = '';
			$params[] = (int) $scope['user_id'];
		} else {
			return '';
		}

        // Get top events by importance + recency
        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT event_type, event_text, importance, times_seen, source_goal, source_tool, last_seen
             FROM {$this->table}
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY importance DESC, last_seen DESC
             LIMIT 15",
            $params
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
    public function has_tool_history( $user_id, $tool_name, $identity_uuid = '' ) {
        global $wpdb;
        $scope = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::resolve( array( 'user_id' => (int) $user_id, 'identity_uuid' => $identity_uuid ) )
            : array( 'user_id' => (int) $user_id, 'identity_uuid' => (string) $identity_uuid );
        $where = array( 'blog_id = %d' );
        $params = array( get_current_blog_id() );
        if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
            if ( ! BizCity_Memory_Identity_Scope::append_read_scope( $where, $params, $scope ) ) return null;
        } else {
            $where[] = 'identity_uuid = %s AND user_id = %d';
            $params[] = '';
            $params[] = (int) $scope['user_id'];
        }
        $where[] = 'event_type = %s';
        $where[] = 'source_tool = %s';
        $params[] = self::TYPE_TOOL_USAGE;
        $params[] = $tool_name;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY last_seen DESC LIMIT 1",
            $params
        ) );
    }

    /**
     * Get all habits for a user.
     *
     * @param  int   $user_id
     * @return array
     */
    public function get_habits( $user_id, $identity_uuid = '' ) {
        global $wpdb;
        $scope = class_exists( 'BizCity_Memory_Identity_Scope' )
            ? BizCity_Memory_Identity_Scope::resolve( array( 'user_id' => (int) $user_id, 'identity_uuid' => $identity_uuid ) )
            : array( 'user_id' => (int) $user_id, 'identity_uuid' => (string) $identity_uuid );
        $where = array( 'blog_id = %d' );
        $params = array( get_current_blog_id() );
        if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
            if ( ! BizCity_Memory_Identity_Scope::append_read_scope( $where, $params, $scope ) ) return array();
        } else {
            $where[] = 'identity_uuid = %s AND user_id = %d';
            $params[] = '';
            $params[] = (int) $scope['user_id'];
        }
        $where[] = 'event_type = %s';
        $params[] = self::TYPE_HABIT;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE " . implode( ' AND ', $where ) . "
             ORDER BY importance DESC, times_seen DESC
             LIMIT 20",
            $params
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
            'identity_uuid'          => '',
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

        // [2026-07-28 Johnny Chu] R-CH-IDMEM — every new episodic event is normalized to a durable UUID owner.
        if ( class_exists( 'BizCity_Memory_Identity_Scope' ) ) {
            $data = BizCity_Memory_Identity_Scope::prepare_write( $data );
            if ( ! is_array( $data ) ) return false;
        } elseif ( empty( $data['identity_uuid'] ) ) {
            return false;
        }

        // Enforce limits — delete oldest if over MAX_PER_USER
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE blog_id = %d AND identity_uuid = %s",
            $data['blog_id'], $data['identity_uuid']
        ) );
        if ( $count >= self::MAX_PER_USER ) {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE blog_id = %d AND identity_uuid = %s
                 ORDER BY importance ASC, updated_at ASC
                 LIMIT 10",
                $data['blog_id'], $data['identity_uuid']
            ) );
        }

        // Check if event_key already exists
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, times_seen FROM {$this->table}
             WHERE blog_id = %d AND identity_uuid = %s AND event_key = %s",
            $data['blog_id'], $data['identity_uuid'], $data['event_key']
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
            // Wave 2.8d D5 — dual-write mirror into unified `bizcity_memory`.
            do_action( 'bizcity_memory_mirror_write', 'episodic', array_merge( $data, [ 'id' => (int) $existing->id, 'importance' => $new_importance ] ), 'update' );
        } else {
            // Insert new
            $wpdb->insert( $this->table, [
                'blog_id'                => $data['blog_id'],
                'user_id'                => $data['user_id'],
                'identity_uuid'          => $data['identity_uuid'],
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
            // Wave 2.8d D5 — dual-write mirror into unified `bizcity_memory`.
            do_action( 'bizcity_memory_mirror_write', 'episodic', array_merge( $data, [ 'id' => (int) $wpdb->insert_id ] ), 'insert' );
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

// [2026-07-28 Johnny Chu] R-CR — register episodic schema before the installer can run dbDelta().
if ( class_exists( 'BizCity_Schema_Registry' ) ) {
    BizCity_Schema_Registry::register(
        'bizcity_memory_episodic',
        'core.intent.memory.episodic',
        BizCity_Episodic_Memory::DB_VERSION,
        BizCity_Episodic_Memory::DB_VERSION_OPTION,
        array( 'BizCity_Episodic_Memory', 'ensure_table' )
    );
}
