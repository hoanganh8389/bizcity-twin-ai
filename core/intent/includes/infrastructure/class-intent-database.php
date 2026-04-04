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
 * BizCity Intent — Database Layer
 *
 * Creates and manages the intent conversation tables.
 * Uses the blog-specific prefix so each site in multisite has its own data.
 *
 * Tables:
 *   {prefix}bizcity_intent_conversations  — conversation state machine
 *   {prefix}bizcity_intent_turns          — individual message turns within a conversation
 *   {prefix}bizcity_intent_prompt_logs    — full prompt + intent + context log per request
 *
 * @package BizCity_Intent
 * @since   1.0.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_Intent_Database {

    /** @var self|null */
    private static $instance = null;

    /** @var wpdb */
    private $wpdb;

    /** @var string */
    private $table_conversations;

    /** @var string */
    private $table_turns;

    /** @var string */
    private $table_prompt_logs;

    /** @var string */
    private $table_todos;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_conversations = $wpdb->prefix . 'bizcity_intent_conversations';
        $this->table_turns         = $wpdb->prefix . 'bizcity_intent_turns';
        $this->table_prompt_logs   = $wpdb->prefix . 'bizcity_intent_prompt_logs';
        $this->table_todos         = $wpdb->prefix . 'bizcity_intent_todos';
    }

    /**
     * Get table name for conversations.
     *
     * @return string
     */
    public function conversations_table() {
        return $this->table_conversations;
    }

    /**
     * Get table name for turns.
     *
     * @return string
     */
    public function turns_table() {
        return $this->table_turns;
    }

    /**
     * Get table name for prompt logs.
     *
     * @return string
     */
    public function prompt_logs_table() {
        return $this->table_prompt_logs;
    }

    /**
     * Get table name for todos.
     *
     * @return string
     */
    public function todos_table() {
        return $this->table_todos;
    }

    /**
     * Create tables if they don't exist.
     * Called once on plugins_loaded.
     */
    public function maybe_create_tables() {
        $option_key = 'bizcity_intent_db_version';
        $current    = get_option( $option_key, '0' );

        if ( version_compare( $current, BIZCITY_INTENT_VERSION, '>=' ) ) {
            return;
        }

        $charset = $this->wpdb->get_charset_collate();

        // ── Conversations table ──
        $sql_conv = "CREATE TABLE IF NOT EXISTS {$this->table_conversations} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            session_id VARCHAR(255) DEFAULT '',
            channel VARCHAR(50) DEFAULT 'webchat',
            character_id INT UNSIGNED DEFAULT 0,
            project_id VARCHAR(36) DEFAULT '',

            -- Goal / state machine
            goal VARCHAR(100) DEFAULT '',
            goal_label VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'ACTIVE',
            slots_json LONGTEXT,
            waiting_for TEXT,
            waiting_field VARCHAR(100) DEFAULT '',

            -- Context
            rolling_summary TEXT,
            open_loops TEXT,
            context_snapshot LONGTEXT,

            -- Counters
            turn_count INT UNSIGNED DEFAULT 0,

            -- Timestamps
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            last_activity_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            KEY idx_user_channel (user_id, channel, status),
            KEY idx_session_status (session_id, status),
            UNIQUE KEY idx_conversation_id (conversation_id),
            KEY idx_status (status),
            KEY idx_last_activity (last_activity_at),
            KEY idx_project (user_id, project_id)
        ) {$charset};";

        // ── Turns table (message history within a conversation) ──
        $sql_turns = "CREATE TABLE IF NOT EXISTS {$this->table_turns} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id VARCHAR(64) NOT NULL,
            turn_index INT UNSIGNED DEFAULT 0,
            role VARCHAR(20) DEFAULT 'user',
            content LONGTEXT,
            attachments LONGTEXT,
            intent VARCHAR(50) DEFAULT '',
            slots_delta LONGTEXT,
            tool_calls LONGTEXT,
            meta LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            KEY idx_conv_id (conversation_id),
            KEY idx_conv_turn (conversation_id, turn_index)
        ) {$charset};";

        $this->wpdb->query( $sql_conv );
        $this->wpdb->query( $sql_turns );

        // ── Prompt Logs table (full request telemetry) ──
        $sql_prompt_logs = "CREATE TABLE IF NOT EXISTS {$this->table_prompt_logs} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(255) DEFAULT '',
            conversation_id VARCHAR(64) DEFAULT '',
            user_id BIGINT UNSIGNED DEFAULT 0,
            channel VARCHAR(50) DEFAULT 'webchat',
            character_id INT UNSIGNED DEFAULT 0,
            blog_id INT UNSIGNED DEFAULT 0,

            -- Input
            message LONGTEXT,
            images_count TINYINT UNSIGNED DEFAULT 0,

            -- Mode Classification
            detected_mode VARCHAR(30) DEFAULT '',
            mode_confidence DECIMAL(4,3) DEFAULT 0.000,
            mode_method VARCHAR(255) DEFAULT '',

            -- Intent (execution mode only)
            intent_key VARCHAR(100) DEFAULT '',
            goal VARCHAR(100) DEFAULT '',
            goal_label VARCHAR(255) DEFAULT '',
            slots_json LONGTEXT,

            -- Context assembled
            context_summary LONGTEXT,
            context_layers_json LONGTEXT,

            -- Execution
            pipeline_class VARCHAR(100) DEFAULT '',
            pipeline_action VARCHAR(30) DEFAULT '',
            tool_calls_json LONGTEXT,
            provider_used VARCHAR(50) DEFAULT '',
            executor_trace_id VARCHAR(64) DEFAULT '',
            planner_plan_id VARCHAR(64) DEFAULT '',

            -- Response
            response_summary TEXT,
            response_action VARCHAR(30) DEFAULT '',
            duration_ms DECIMAL(10,2) DEFAULT 0.00,

            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            KEY idx_session (session_id),
            KEY idx_user_channel (user_id, channel),
            KEY idx_mode (detected_mode),
            KEY idx_intent (intent_key),
            KEY idx_created (created_at),
            KEY idx_conv (conversation_id)
        ) {$charset};";

        $this->wpdb->query( $sql_prompt_logs );

        // ── ToDos table (pipeline step tracking) ──
        $sql_todos = "CREATE TABLE IF NOT EXISTS {$this->table_todos} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pipeline_id VARCHAR(64) NOT NULL,
            step_index INT UNSIGNED DEFAULT 0,
            tool_name VARCHAR(100) DEFAULT '',
            label VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'PENDING',
            score TINYINT UNSIGNED DEFAULT 0,
            output_summary TEXT,
            error_message TEXT,
            user_id BIGINT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

            KEY idx_pipeline (pipeline_id),
            KEY idx_pipeline_status (pipeline_id, status),
            KEY idx_tool (tool_name),
            KEY idx_user_pipeline (user_id, pipeline_id)
        ) {$charset};";

        $this->wpdb->query( $sql_todos );

        // ── v3.9.0: Add pipeline columns to conversations ──
        if ( version_compare( $current, '3.9.0', '<' ) ) {
            $conv_cols = $this->wpdb->get_col( "SHOW COLUMNS FROM {$this->table_conversations}" );
            if ( is_array( $conv_cols ) && ! in_array( 'parent_pipeline_id', $conv_cols, true ) ) {
                $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD COLUMN parent_pipeline_id VARCHAR(64) DEFAULT '' AFTER project_id" );
                $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD COLUMN step_index INT UNSIGNED DEFAULT 0 AFTER parent_pipeline_id" );
                $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD KEY idx_pipeline (parent_pipeline_id)" );
            }
        }

        // ── Migrate: add project_id column if missing ──
        $cols = $this->wpdb->get_col( "SHOW COLUMNS FROM {$this->table_conversations}" );
        if ( is_array( $cols ) && ! in_array( 'project_id', $cols, true ) ) {
            $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD COLUMN project_id VARCHAR(36) DEFAULT '' AFTER character_id" );
            $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD KEY idx_project (user_id, project_id)" );
        }

        // ── v3.8.0: Widen mode_method VARCHAR(100) → VARCHAR(255) for composite method strings ──
        if ( version_compare( $current, '3.8.0', '<' ) ) {
            $this->wpdb->query( "ALTER TABLE {$this->table_prompt_logs} MODIFY COLUMN mode_method VARCHAR(255) DEFAULT ''" );
        }

        // ── v3.9.1: Add todo_id to conversations + skeleton columns to todos ──
        if ( version_compare( $current, '3.9.1', '<' ) ) {
            $conv_cols_v391 = $this->wpdb->get_col( "SHOW COLUMNS FROM {$this->table_conversations}" );
            if ( is_array( $conv_cols_v391 ) && ! in_array( 'todo_id', $conv_cols_v391, true ) ) {
                $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD COLUMN todo_id BIGINT UNSIGNED DEFAULT NULL AFTER step_index" );
                $this->wpdb->query( "ALTER TABLE {$this->table_conversations} ADD KEY idx_todo (todo_id)" );
            }

            $todo_cols = $this->wpdb->get_col( "SHOW COLUMNS FROM {$this->table_todos}" );
            if ( is_array( $todo_cols ) && ! in_array( 'slots_skeleton_json', $todo_cols, true ) ) {
                $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN slots_skeleton_json LONGTEXT AFTER error_message" );
                $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN depends_on_steps TEXT AFTER slots_skeleton_json" );
                $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN pipeline_timeout INT UNSIGNED DEFAULT 300 AFTER depends_on_steps" );
            }
        }

        // ── v4.0.0: Add pipeline ↔ graph mapping columns to todos ──
        if ( version_compare( $current, '4.0.0', '<' ) ) {
            $todo_cols_v4 = $this->wpdb->get_col( "SHOW COLUMNS FROM {$this->table_todos}" );
            if ( is_array( $todo_cols_v4 ) ) {
                // B5 fix: per-column idempotent checks — partial migration won't skip remaining columns
                if ( ! in_array( 'task_id', $todo_cols_v4, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN task_id BIGINT UNSIGNED DEFAULT NULL AFTER pipeline_id" );
                }
                if ( ! in_array( 'pipeline_version', $todo_cols_v4, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN pipeline_version SMALLINT UNSIGNED DEFAULT 1 AFTER task_id" );
                }
                if ( ! in_array( 'node_id', $todo_cols_v4, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN node_id VARCHAR(32) DEFAULT NULL AFTER step_index" );
                }
                if ( ! in_array( 'node_code', $todo_cols_v4, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN node_code VARCHAR(64) DEFAULT NULL AFTER tool_name" );
                }
                if ( ! in_array( 'node_input_json', $todo_cols_v4, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN node_input_json LONGTEXT DEFAULT NULL AFTER slots_skeleton_json" );
                }
                if ( ! in_array( 'node_output_json', $todo_cols_v4, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD COLUMN node_output_json LONGTEXT DEFAULT NULL AFTER node_input_json" );
                }
                // Indexes — check via SHOW INDEX to avoid duplicate key error
                $existing_indexes = $this->wpdb->get_col( "SHOW INDEX FROM {$this->table_todos}", 2 );
                if ( is_array( $existing_indexes ) && ! in_array( 'idx_task', $existing_indexes, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD KEY idx_task (task_id)" );
                }
                if ( is_array( $existing_indexes ) && ! in_array( 'idx_node', $existing_indexes, true ) ) {
                    $this->wpdb->query( "ALTER TABLE {$this->table_todos} ADD KEY idx_node (pipeline_id, node_id)" );
                }
            }
        }

        // ── Classification Cache table ──
        if ( class_exists( 'BizCity_Intent_Classify_Cache' ) ) {
            BizCity_Intent_Classify_Cache::instance()->maybe_create_table();
        }

        // ── Logs table (pipeline telemetry) ──
        if ( class_exists( 'BizCity_Intent_Logger' ) ) {
            BizCity_Intent_Logger::instance()->maybe_create_table();
        }

        update_option( $option_key, BIZCITY_INTENT_VERSION );
    }

    /* ================================================================
     *  Conversation CRUD
     * ================================================================ */

    /**
     * Insert a new conversation.
     *
     * @param array $data
     * @return string|false conversation_id or false
     */
    public function insert_conversation( array $data ) {
        $conversation_id = $data['conversation_id'] ?? $this->generate_conversation_id();

        $inserted = $this->wpdb->insert( $this->table_conversations, [
            'conversation_id' => $conversation_id,
            'user_id'         => intval( $data['user_id'] ?? 0 ),
            'session_id'      => $data['session_id']  ?? '',
            'channel'         => $data['channel']      ?? 'webchat',
            'character_id'    => intval( $data['character_id'] ?? 0 ),
            'goal'            => $data['goal']         ?? '',
            'goal_label'      => $data['goal_label']   ?? '',
            'status'          => $data['status']       ?? 'ACTIVE',
            'slots_json'      => wp_json_encode( $data['slots'] ?? new stdClass() ),
            'waiting_for'     => $data['waiting_for']  ?? '',
            'waiting_field'   => $data['waiting_field'] ?? '',
            'rolling_summary' => $data['rolling_summary'] ?? '',
            'open_loops'      => wp_json_encode( $data['open_loops'] ?? [] ),
            'last_activity_at' => current_time( 'mysql' ),
        ] );

        return $inserted ? $conversation_id : false;
    }

    /**
     * Get a conversation by conversation_id.
     *
     * @param string $conversation_id
     * @return object|null
     */
    public function get_conversation( $conversation_id ) {
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table_conversations} WHERE conversation_id = %s",
            $conversation_id
        ) );
    }

    /**
     * Find the most recent ACTIVE or WAITING_USER conversation for user+channel.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return object|null
     */
    public function find_active_conversation( $user_id, $channel = 'webchat', $session_id = '' ) {
        $where_parts = [ "status IN ('ACTIVE', 'WAITING_USER')" ];
        $params      = [];

        if ( $user_id > 0 ) {
            $where_parts[] = 'user_id = %d';
            $params[]      = $user_id;
        }
        if ( ! empty( $session_id ) ) {
            $where_parts[] = 'session_id = %s';
            $params[]      = $session_id;
        }
        if ( ! empty( $channel ) ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $channel;
        }

        // Expire old conversations (> 30 minutes inactive)
        $where_parts[] = 'last_activity_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)';

        $where = implode( ' AND ', $where_parts );
        $sql   = "SELECT * FROM {$this->table_conversations} WHERE {$where} ORDER BY last_activity_at DESC LIMIT 1";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        return $this->wpdb->get_row( $sql );
    }

    /**
     * Update conversation fields.
     *
     * @param string $conversation_id
     * @param array  $data
     * @return bool
     */
    public function update_conversation( $conversation_id, array $data ) {
        $update = [];
        $format = [];

        $field_map = [
            'goal'            => '%s',
            'goal_label'      => '%s',
            'status'          => '%s',
            'slots_json'      => '%s',
            'waiting_for'     => '%s',
            'waiting_field'   => '%s',
            'rolling_summary' => '%s',
            'open_loops'      => '%s',
            'context_snapshot' => '%s',
            'turn_count'      => '%d',
            'completed_at'    => '%s',
            'character_id'    => '%d',
        ];

        foreach ( $field_map as $field => $fmt ) {
            if ( array_key_exists( $field, $data ) ) {
                $update[ $field ] = $data[ $field ];
                $format[]         = $fmt;
            }
        }

        if ( empty( $update ) ) {
            return false;
        }

        // Always touch last_activity_at
        $update['last_activity_at'] = current_time( 'mysql' );
        $format[] = '%s';

        return (bool) $this->wpdb->update(
            $this->table_conversations,
            $update,
            [ 'conversation_id' => $conversation_id ],
            $format,
            [ '%s' ]
        );
    }

    /**
     * Expire stale conversations (> 30 min inactive).
     */
    public function expire_stale() {
        $this->wpdb->query(
            "UPDATE {$this->table_conversations}
             SET status = 'EXPIRED'
             WHERE status IN ('ACTIVE','WAITING_USER')
               AND last_activity_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        );
    }

    /**
     * Find the most recently expired/closed conversation with a goal
     * for the same user+channel+session (within last 2 hours).
     * Used for O10 resume confirmation.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return object|null
     */
    public function find_expired_conversation( $user_id, $channel, $session_id ) {
        $where = "status IN ('EXPIRED','CLOSED') AND goal != '' AND last_activity_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)";
        $params = [];

        if ( (int) $user_id > 0 ) {
            $where   .= ' AND user_id = %d';
            $params[] = $user_id;
        } elseif ( $session_id ) {
            $where   .= ' AND session_id = %s';
            $params[] = $session_id;
        } else {
            return null;
        }

        $where   .= ' AND channel = %s';
        $params[] = $channel;

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table_conversations} WHERE {$where} ORDER BY last_activity_at DESC LIMIT 1",
            ...$params
        ) );
    }

    /**
     * Find the most recently COMPLETED conversation with a goal
     * for the same user+channel+session (within last 2 minutes).
     * Used for post-tool satisfaction detection.
     *
     * @since v4.0.0 Phase 13 — Dual Context Architecture
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return object|null
     */
    public function find_recently_completed_conversation( $user_id, $channel, $session_id ) {
        $where  = "status = 'COMPLETED' AND goal != '' AND completed_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
        $params = [];

        if ( (int) $user_id > 0 ) {
            $where   .= ' AND user_id = %d';
            $params[] = $user_id;
        } elseif ( $session_id ) {
            $where   .= ' AND session_id = %s';
            $params[] = $session_id;
        } else {
            return null;
        }

        $where   .= ' AND channel = %s';
        $params[] = $channel;

        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table_conversations} WHERE {$where} ORDER BY completed_at DESC LIMIT 1",
            ...$params
        ) );
    }

    /* ================================================================
     *  Turns CRUD
     * ================================================================ */

    /**
     * Insert a turn (message) into a conversation.
     *
     * @param array $data
     * @return int|false Turn ID or false.
     */
    public function insert_turn( array $data ) {
        $inserted = $this->wpdb->insert( $this->table_turns, [
            'conversation_id' => $data['conversation_id'] ?? '',
            'turn_index'      => intval( $data['turn_index'] ?? 0 ),
            'role'            => $data['role']        ?? 'user',
            'content'         => $data['content']     ?? '',
            'attachments'     => wp_json_encode( $data['attachments'] ?? [] ),
            'intent'          => $data['intent']      ?? '',
            'slots_delta'     => wp_json_encode( $data['slots_delta'] ?? [] ),
            'tool_calls'      => wp_json_encode( $data['tool_calls'] ?? [] ),
            'meta'            => wp_json_encode( $data['meta'] ?? [] ),
        ] );

        return $inserted ? $this->wpdb->insert_id : false;
    }

    /**
     * Get turns for a conversation (ordered by turn_index).
     *
     * @param string $conversation_id
     * @param int    $limit
     * @return array
     */
    public function get_turns( $conversation_id, $limit = 50 ) {
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table_turns}
             WHERE conversation_id = %s
             ORDER BY turn_index ASC
             LIMIT %d",
            $conversation_id,
            $limit
        ) );
    }

    /**
     * Count turns in a conversation.
     *
     * @param string $conversation_id
     * @return int
     */
    public function count_turns( $conversation_id ) {
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_turns} WHERE conversation_id = %s",
            $conversation_id
        ) );
    }

    /* ================================================================
     *  Conversation listing & bulk ops
     * ================================================================ */

    /**
     * Get conversations for a user, ordered by last activity.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @param int    $limit
     * @return array
     */
    public function get_conversations_for_user( $user_id, $channel = '', $session_id = '', $limit = 30, $project_id = null ) {
        $where_parts = [];
        $params      = [];

        if ( $user_id > 0 ) {
            $where_parts[] = 'user_id = %d';
            $params[]      = $user_id;
        }
        if ( ! empty( $session_id ) ) {
            $where_parts[] = 'session_id = %s';
            $params[]      = $session_id;
        }
        if ( ! empty( $channel ) ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $channel;
        }
        if ( $project_id !== null ) {
            $where_parts[] = 'project_id = %s';
            $params[]      = $project_id;
        }

        // Exclude expired older than 7 days
        $where_parts[] = "NOT (status = 'EXPIRED' AND last_activity_at < DATE_SUB(NOW(), INTERVAL 7 DAY))";

        $where = ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';
        $sql   = "SELECT conversation_id, session_id, goal, goal_label, status, turn_count, created_at, last_activity_at, project_id
                  FROM {$this->table_conversations}
                  {$where}
                  ORDER BY last_activity_at DESC
                  LIMIT %d";
        $params[] = $limit;

        if ( count( $params ) > 1 ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        } else {
            $sql = $this->wpdb->prepare( $sql, $limit );
        }

        return $this->wpdb->get_results( $sql );
    }

    /**
     * Get the first user message in a conversation (for title fallback).
     *
     * @param string $conversation_id
     * @return string
     */
    public function get_first_user_message( $conversation_id ) {
        $content = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT content FROM {$this->table_turns}
             WHERE conversation_id = %s AND role = 'user'
             ORDER BY turn_index ASC
             LIMIT 1",
            $conversation_id
        ) );
        return $content ? $content : '';
    }

    /**
     * Update conversation's project_id.
     *
     * @param string $conversation_id
     * @param string $project_id
     * @return bool
     */
    public function update_conversation_project( $conversation_id, $project_id ) {
        return (bool) $this->wpdb->update(
            $this->table_conversations,
            [ 'project_id' => $project_id ],
            [ 'conversation_id' => $conversation_id ],
            [ '%s' ],
            [ '%s' ]
        );
    }

    /**
     * Close all active conversations for a user.
     *
     * @param int    $user_id
     * @param string $channel
     * @param string $session_id
     * @return int Number of rows affected.
     */
    public function close_all_for_user( $user_id, $channel = '', $session_id = '' ) {
        $where_parts = [ "status IN ('ACTIVE','WAITING_USER')" ];
        $params      = [];

        if ( $user_id > 0 ) {
            $where_parts[] = 'user_id = %d';
            $params[]      = $user_id;
        }
        if ( ! empty( $session_id ) ) {
            $where_parts[] = 'session_id = %s';
            $params[]      = $session_id;
        }
        if ( ! empty( $channel ) ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $channel;
        }

        $where = implode( ' AND ', $where_parts );
        $sql   = "UPDATE {$this->table_conversations} SET status = 'CLOSED' WHERE {$where}";

        if ( ! empty( $params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$params );
        }

        return (int) $this->wpdb->query( $sql );
    }

    /* ================================================================
     *  Helpers
     * ================================================================ */

    /**
     * Generate a unique conversation ID.
     *
     * @return string
     */
    public function generate_conversation_id() {
        return 'conv_' . wp_generate_uuid4();
    }

    /* ================================================================
     *  Prompt Logs — Full request telemetry
     * ================================================================ */

    /**
     * Insert a prompt log entry.
     *
     * Called at the end of BizCity_Intent_Engine::process() to record
     * every user prompt with its classified mode, detected intent,
     * assembled context, tool calls, and response summary.
     *
     * @param  array $data  {
     *   @type string $session_id
     *   @type string $conversation_id
     *   @type int    $user_id
     *   @type string $channel
     *   @type int    $character_id
     *   @type int    $blog_id
     *   @type string $message             Raw prompt text.
     *   @type int    $images_count
     *   @type string $detected_mode       emotion|reflection|knowledge|planning|coding|execution
     *   @type float  $mode_confidence
     *   @type string $mode_method
     *   @type string $intent_key          e.g. write_article (execution mode only)
     *   @type string $goal
     *   @type string $goal_label
     *   @type array  $slots               Current slot values.
     *   @type string $context_summary     Human-readable summary of assembled context.
     *   @type array  $context_layers      Structured context layers used.
     *   @type string $pipeline_class      Which pipeline handled (or 'intent_engine').
     *   @type string $pipeline_action     reply|compose|passthrough|call_tool|complete
     *   @type array  $tool_calls          List of tool names called.
     *   @type string $provider_used       AI provider used (if compose).
     *   @type string $executor_trace_id
     *   @type string $planner_plan_id
     *   @type string $response_summary    First 500 chars of the response.
     *   @type string $response_action     reply|passthrough|complete
     *   @type float  $duration_ms         Total processing time.
     * }
     * @return int|false  Log entry ID or false.
     */
    public function insert_prompt_log( array $data ) {
        $inserted = $this->wpdb->insert( $this->table_prompt_logs, [
            'session_id'         => $data['session_id']         ?? '',
            'conversation_id'    => $data['conversation_id']    ?? '',
            'user_id'            => intval( $data['user_id']    ?? 0 ),
            'channel'            => $data['channel']            ?? 'webchat',
            'character_id'       => intval( $data['character_id'] ?? 0 ),
            'blog_id'            => intval( $data['blog_id']    ?? get_current_blog_id() ),
            'message'            => $data['message']            ?? '',
            'images_count'       => intval( $data['images_count'] ?? 0 ),
            'detected_mode'      => $data['detected_mode']      ?? '',
            'mode_confidence'    => floatval( $data['mode_confidence'] ?? 0 ),
            'mode_method'        => $data['mode_method']        ?? '',
            'intent_key'         => $data['intent_key']         ?? '',
            'goal'               => $data['goal']               ?? '',
            'goal_label'         => $data['goal_label']         ?? '',
            'slots_json'         => wp_json_encode( $data['slots'] ?? new stdClass() ),
            'context_summary'    => $data['context_summary']    ?? '',
            'context_layers_json' => wp_json_encode( $data['context_layers'] ?? [] ),
            'pipeline_class'     => $data['pipeline_class']     ?? '',
            'pipeline_action'    => $data['pipeline_action']    ?? '',
            'tool_calls_json'    => wp_json_encode( $data['tool_calls'] ?? [] ),
            'provider_used'      => $data['provider_used']      ?? '',
            'executor_trace_id'  => $data['executor_trace_id']  ?? '',
            'planner_plan_id'    => $data['planner_plan_id']    ?? '',
            'response_summary'   => $data['response_summary']   ?? '',
            'response_action'    => $data['response_action']    ?? '',
            'duration_ms'        => floatval( $data['duration_ms'] ?? 0 ),
        ] );

        return $inserted ? $this->wpdb->insert_id : false;
    }

    /**
     * Query prompt logs with filters for admin dashboard.
     *
     * @param  array $filters  { mode, intent_key, channel, user_id, date_from, date_to, search }
     * @param  int   $limit
     * @param  int   $offset
     * @return array
     */
    public function get_prompt_logs( array $filters = [], $limit = 100, $offset = 0 ) {
        $where_parts = [];
        $params      = [];

        if ( ! empty( $filters['mode'] ) ) {
            $where_parts[] = 'detected_mode = %s';
            $params[]      = $filters['mode'];
        }
        if ( ! empty( $filters['intent_key'] ) ) {
            $where_parts[] = 'intent_key = %s';
            $params[]      = $filters['intent_key'];
        }
        if ( ! empty( $filters['channel'] ) ) {
            $where_parts[] = 'channel = %s';
            $params[]      = $filters['channel'];
        }
        if ( ! empty( $filters['user_id'] ) ) {
            $where_parts[] = 'user_id = %d';
            $params[]      = intval( $filters['user_id'] );
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where_parts[] = 'created_at >= %s';
            $params[]      = $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where_parts[] = 'created_at <= %s';
            $params[]      = $filters['date_to'];
        }
        if ( ! empty( $filters['search'] ) ) {
            $where_parts[] = 'message LIKE %s';
            $params[]      = '%' . $this->wpdb->esc_like( $filters['search'] ) . '%';
        }

        $where = ! empty( $where_parts ) ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';
        $sql   = "SELECT * FROM {$this->table_prompt_logs} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A ) ?: [];
    }

    /**
     * Get prompt log stats for admin overview.
     *
     * @param  int $days  Look-back period.
     * @return array
     */
    public function get_prompt_log_stats( $days = 7 ) {
        $since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $total = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_prompt_logs} WHERE created_at >= %s", $since
        ) );

        $by_mode = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT detected_mode AS mode, COUNT(*) AS cnt
             FROM {$this->table_prompt_logs}
             WHERE created_at >= %s
             GROUP BY detected_mode ORDER BY cnt DESC",
            $since
        ), ARRAY_A ) ?: [];

        $by_intent = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT intent_key, COUNT(*) AS cnt
             FROM {$this->table_prompt_logs}
             WHERE created_at >= %s AND intent_key != ''
             GROUP BY intent_key ORDER BY cnt DESC LIMIT 20",
            $since
        ), ARRAY_A ) ?: [];

        $avg_duration = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT ROUND(AVG(duration_ms), 2)
             FROM {$this->table_prompt_logs}
             WHERE created_at >= %s", $since
        ) ) ?: 0;

        $per_day = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS cnt
             FROM {$this->table_prompt_logs}
             WHERE created_at >= %s
             GROUP BY DATE(created_at) ORDER BY day DESC",
            $since
        ), ARRAY_A ) ?: [];

        return [
            'total'        => $total,
            'by_mode'      => $by_mode,
            'by_intent'    => $by_intent,
            'avg_duration' => $avg_duration,
            'per_day'      => $per_day,
        ];
    }
}
