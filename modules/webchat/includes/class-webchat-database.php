<?php
/**
 * Bizcity Twin AI — WebChat Database Handler
 * Quản lý database cho webchat / Manage webchat database: sessions, messages, projects
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Module\Webchat
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @version    3.0.0
 */

defined('ABSPATH') or die('OOPS...');

if (!class_exists('BizCity_WebChat_Database')) {

class BizCity_WebChat_Database {

    /** Schema version — bump to trigger migration */
    const SCHEMA_VERSION = '3.7.0';
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // ============================================
        // V3.0 NEW TABLES: Projects & Sessions
        // ============================================
        
        // Table: webchat_projects - Container for sessions, binds to character_id
        $table_projects = $wpdb->prefix . 'bizcity_webchat_projects';
        $sql_projects = "CREATE TABLE IF NOT EXISTS {$table_projects} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_id VARCHAR(50) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            character_id BIGINT UNSIGNED DEFAULT 0,
            
            name VARCHAR(255) NOT NULL,
            description TEXT,
            icon VARCHAR(32) DEFAULT '📁',
            color VARCHAR(16) DEFAULT '#6366f1',
            
            settings LONGTEXT,
            knowledge_ids TEXT,
            file_ids TEXT,
            
            is_public TINYINT(1) DEFAULT 0,
            is_archived TINYINT(1) DEFAULT 0,
            sort_order INT DEFAULT 0,
            
            session_count INT DEFAULT 0,
            last_activity_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            UNIQUE KEY uniq_project_id (project_id),
            INDEX idx_user (user_id),
            INDEX idx_character (character_id),
            INDEX idx_public (is_public),
            INDEX idx_sort (user_id, sort_order)
        ) {$charset_collate};";
        
        // Table: webchat_sessions - Chat sessions with auto-title & rolling summary
        $table_sessions = $wpdb->prefix . 'bizcity_webchat_sessions';
        $sql_sessions = "CREATE TABLE IF NOT EXISTS {$table_sessions} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            project_id VARCHAR(50) DEFAULT '',
            character_id BIGINT UNSIGNED DEFAULT 0,
            
            title VARCHAR(255) DEFAULT '',
            title_generated TINYINT(1) DEFAULT 0,
            
            client_name VARCHAR(255),
            platform_type VARCHAR(32) DEFAULT 'WEBCHAT',
            status ENUM('active', 'closed', 'archived') DEFAULT 'active',
            
            rolling_summary TEXT,
            summary_updated_at DATETIME,
            context_tokens INT DEFAULT 0,
            
            message_count INT DEFAULT 0,
            last_message_at DATETIME,
            last_message_preview VARCHAR(255),
            
            meta LONGTEXT,

            kci_ratio TINYINT UNSIGNED DEFAULT 80,
            
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,
            
            UNIQUE KEY uniq_session_id (session_id),
            INDEX idx_user (user_id),
            INDEX idx_project (project_id),
            INDEX idx_status (status),
            INDEX idx_platform (platform_type),
            INDEX idx_activity (user_id, last_message_at)
        ) {$charset_collate};";
        
        // ============================================
        // EXISTING TABLES (kept for backward compat)
        // ============================================
        
        // Table: webchat_conversations (legacy - migrate to sessions)
        $table_conversations = $wpdb->prefix . 'bizcity_webchat_conversations';
        $sql_conversations = "CREATE TABLE IF NOT EXISTS {$table_conversations} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            client_name VARCHAR(255),
            title VARCHAR(255) DEFAULT '',
            platform_type VARCHAR(32) DEFAULT 'WEBCHAT',
            status VARCHAR(32) DEFAULT 'active',
            project_id VARCHAR(36) DEFAULT '',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME NULL,
            meta LONGTEXT,
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status),
            INDEX idx_project (user_id, project_id)
        ) {$charset_collate};";
        
        // Table: webchat_messages (updated with session support + plugin_slug for @ mentions)
        $table_messages = $wpdb->prefix . 'bizcity_webchat_messages';
        $sql_messages = "CREATE TABLE IF NOT EXISTS {$table_messages} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conversation_id BIGINT UNSIGNED DEFAULT 0,
            session_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            client_name VARCHAR(255),
            message_id VARCHAR(64),
            message_text LONGTEXT,
            message_from ENUM('user', 'bot', 'system') DEFAULT 'user',
            message_type VARCHAR(32) DEFAULT 'text',
            plugin_slug VARCHAR(128) DEFAULT '',
            tool_name VARCHAR(128) DEFAULT '',
            intent_conversation_id VARCHAR(64) DEFAULT '',
            attachments LONGTEXT,
            tool_calls LONGTEXT,
            input_tokens INT DEFAULT 0,
            output_tokens INT DEFAULT 0,
            is_context_included TINYINT(1) DEFAULT 1,
            importance_score TINYINT UNSIGNED DEFAULT 50,
            platform_type VARCHAR(32) DEFAULT 'WEBCHAT',
            project_id VARCHAR(50) DEFAULT '',
            status VARCHAR(20) DEFAULT 'visible',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            meta LONGTEXT,
            INDEX idx_session (session_id),
            INDEX idx_conversation (conversation_id),
            INDEX idx_message_id (message_id),
            INDEX idx_from (message_from),
            INDEX idx_plugin_slug (plugin_slug),
            INDEX idx_intent_conv (intent_conversation_id),
            INDEX idx_session_plugin (session_id, plugin_slug),
            INDEX idx_created (created_at),
            INDEX idx_status (status)
        ) {$charset_collate};";
        
        // Table: webchat_tasks (Timeline/Task tracking giống Relevance AI)
        $table_tasks = $wpdb->prefix . 'bizcity_webchat_tasks';
        $sql_tasks = "CREATE TABLE IF NOT EXISTS {$table_tasks} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id VARCHAR(64) NOT NULL,
            session_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            triggered_by VARCHAR(255),
            task_name VARCHAR(255),
            task_status ENUM('pending', 'running', 'paused', 'completed', 'failed') DEFAULT 'pending',
            workflow_id BIGINT UNSIGNED DEFAULT 0,
            actions_used INT DEFAULT 0,
            credits_used DECIMAL(10,2) DEFAULT 0,
            run_time_seconds INT DEFAULT 0,
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            meta LONGTEXT,
            UNIQUE KEY uniq_task (task_id),
            INDEX idx_session (session_id),
            INDEX idx_status (task_status),
            INDEX idx_workflow (workflow_id)
        ) {$charset_collate};";
        
        // Table: webchat_task_steps (Timeline steps)
        $table_steps = $wpdb->prefix . 'bizcity_webchat_task_steps';
        $sql_steps = "CREATE TABLE IF NOT EXISTS {$table_steps} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            step_id VARCHAR(64) NOT NULL,
            task_id VARCHAR(64) NOT NULL,
            step_type ENUM('trigger', 'action', 'response', 'tool', 'hil') DEFAULT 'action',
            step_name VARCHAR(255),
            step_status ENUM('pending', 'running', 'completed', 'failed', 'skipped') DEFAULT 'pending',
            input_data LONGTEXT,
            output_data LONGTEXT,
            duration_ms INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME NULL,
            meta LONGTEXT,
            UNIQUE KEY uniq_step (step_id),
            INDEX idx_task (task_id),
            INDEX idx_type (step_type),
            INDEX idx_status (step_status)
        ) {$charset_collate};";
        
        // Table: webchat_tools (Linked tools giống Relevance AI)
        $table_tools = $wpdb->prefix . 'bizcity_webchat_tools';
        $sql_tools = "CREATE TABLE IF NOT EXISTS {$table_tools} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tool_id VARCHAR(64) NOT NULL,
            tool_name VARCHAR(255),
            tool_description TEXT,
            tool_icon VARCHAR(255),
            tool_type VARCHAR(64) DEFAULT 'action',
            tool_config LONGTEXT,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_tool (tool_id),
            INDEX idx_active (is_active)
        ) {$charset_collate};";
        
        // Table: webchat_memory (User memory/profile from LLM)
        $table_memory = $wpdb->prefix . 'bizcity_memory_session';
        $sql_memory = "CREATE TABLE IF NOT EXISTS {$table_memory} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            client_name VARCHAR(255),
            memory_type VARCHAR(32) NOT NULL COMMENT 'identity, preference, goal, pain, constraint, habit, relationship, fact',
            memory_key VARCHAR(128) NOT NULL COMMENT 'Slug key, e.g. likes:milk_tea',
            memory_text TEXT NOT NULL COMMENT 'Normalized description',
            score TINYINT UNSIGNED DEFAULT 50 COMMENT 'Importance score 0-100',
            times_seen INT UNSIGNED DEFAULT 1 COMMENT 'How many times seen',
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            source_message_ids TEXT COMMENT 'Comma-separated message IDs',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_memory (session_id, user_id, memory_key),
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_type (memory_type),
            INDEX idx_score (score)
        ) {$charset_collate};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // V3.0 new tables first
        dbDelta($sql_projects);
        dbDelta($sql_sessions);
        
        // Legacy + updated tables
        dbDelta($sql_conversations);
        dbDelta($sql_messages);
        dbDelta($sql_tasks);
        dbDelta($sql_steps);
        dbDelta($sql_tools);
        dbDelta($sql_memory);

        // Migration: add columns + migrate data
        $this->maybe_upgrade_conversations();
        $this->maybe_upgrade_sessions();
        $this->maybe_migrate_conversations_to_sessions();
    }

    /**
     * Migration: add title + project_id columns to webchat_conversations if missing.
     * Also add plugin_slug column to webchat_messages for @ mention support.
     * Public so it can be called from ensure_tables_exist().
     */
    public function maybe_upgrade_conversations() {
        global $wpdb;
        $table_conversations = $wpdb->prefix . 'bizcity_webchat_conversations';
        $table_messages = $wpdb->prefix . 'bizcity_webchat_messages';

        // Migration 1: Add title + project_id to conversations table
        $cols_conv = $wpdb->get_col( "DESCRIBE {$table_conversations}", 0 );
        if ( ! in_array( 'title', $cols_conv, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_conversations} ADD COLUMN title VARCHAR(255) DEFAULT '' AFTER client_name" );
        }
        if ( ! in_array( 'project_id', $cols_conv, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_conversations} ADD COLUMN project_id VARCHAR(36) DEFAULT '' AFTER status" );
            $wpdb->query( "ALTER TABLE {$table_conversations} ADD INDEX idx_project (user_id, project_id)" );
        }

        // Migration 2: Add plugin_slug to messages table for @ mention support
        $cols_msg = $wpdb->get_col( "DESCRIBE {$table_messages}", 0 );
        if ( ! in_array( 'plugin_slug', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN plugin_slug VARCHAR(128) DEFAULT '' AFTER message_type" );
            $wpdb->query( "ALTER TABLE {$table_messages} ADD INDEX idx_plugin_slug (plugin_slug)" );
            $wpdb->query( "ALTER TABLE {$table_messages} ADD INDEX idx_session_plugin (session_id, plugin_slug)" );
        }

        // Migration 3: Add intent_conversation_id to messages table for intent traceability
        if ( ! in_array( 'intent_conversation_id', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN intent_conversation_id VARCHAR(64) DEFAULT '' AFTER plugin_slug" );
            $wpdb->query( "ALTER TABLE {$table_messages} ADD INDEX idx_intent_conv (intent_conversation_id)" );
        }

        // Migration 4: Add tool_name to messages table for tool tracing
        if ( ! in_array( 'tool_name', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN tool_name VARCHAR(128) DEFAULT '' AFTER plugin_slug" );
        }

        // Migration 5 (v3.5.0): Add status column to messages table
        if ( ! in_array( 'status', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN status VARCHAR(20) DEFAULT 'visible' AFTER platform_type" );
            $wpdb->query( "ALTER TABLE {$table_messages} ADD INDEX idx_status (status)" );
        }

        // Migration 6 (v3.6.0): Add project_id to messages table for Twin Core snapshot
        if ( ! in_array( 'project_id', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN project_id VARCHAR(50) DEFAULT '' AFTER platform_type" );
            $wpdb->query( "ALTER TABLE {$table_messages} ADD INDEX idx_project_id (project_id)" );
        }
    }

    /**
     * Migration (v3.7.0): Add kci_ratio column to webchat_sessions table.
     * KCI Ratio = Knowledge ↔ Execution slider (0-100, default 80 = 80% knowledge).
     */
    public function maybe_upgrade_sessions() {
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'bizcity_webchat_sessions';

        $cols = $wpdb->get_col( "DESCRIBE {$table_sessions}", 0 );
        if ( ! in_array( 'kci_ratio', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sessions} ADD COLUMN kci_ratio TINYINT UNSIGNED DEFAULT 80 AFTER meta" );
        }
    }

    /**
     * Migration: Copy data from webchat_conversations to webchat_sessions.
     * Only runs once (checks if webchat_sessions is empty).
     */
    public function maybe_migrate_conversations_to_sessions() {
        global $wpdb;
        $tbl_sessions = $wpdb->prefix . 'bizcity_webchat_sessions';
        $tbl_conv = $wpdb->prefix . 'bizcity_webchat_conversations';
        $tbl_msg = $wpdb->prefix . 'bizcity_webchat_messages';
        
        // Check if migration already done
        $session_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$tbl_sessions}" );
        if ( $session_count > 0 ) {
            return; // Already migrated
        }
        
        // Migrate conversations to sessions
        $conversations = $wpdb->get_results( "SELECT * FROM {$tbl_conv} ORDER BY id ASC" );
        foreach ( $conversations as $conv ) {
            // Count messages for this conversation
            $msg_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_msg} WHERE session_id = %s",
                $conv->session_id
            ) );
            
            // Get last message info
            $last_msg = $wpdb->get_row( $wpdb->prepare(
                "SELECT message_text, created_at FROM {$tbl_msg} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
                $conv->session_id
            ) );
            
            $wpdb->insert( $tbl_sessions, [
                'session_id'           => $conv->session_id,
                'user_id'              => $conv->user_id,
                'project_id'           => $conv->project_id ?: '',
                'character_id'         => 0,
                'title'                => $conv->title ?: '',
                'title_generated'      => !empty($conv->title) ? 1 : 0,
                'client_name'          => $conv->client_name,
                'platform_type'        => $conv->platform_type,
                'status'               => $conv->status,
                'rolling_summary'      => '',
                'summary_updated_at'   => null,
                'context_tokens'       => 0,
                'message_count'        => $msg_count,
                'last_message_at'      => $last_msg ? $last_msg->created_at : null,
                'last_message_preview' => $last_msg ? mb_substr( $last_msg->message_text, 0, 200 ) : '',
                'meta'                 => $conv->meta,
                'started_at'           => $conv->started_at,
                'ended_at'             => $conv->ended_at,
            ] );
        }
    }
    
    /**
     * Log message
     */
    public function log_message($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        
        $session_id = $data['session_id'] ?? '';
        
        // Get or create conversation
        $conversation_id = $this->get_or_create_conversation($session_id, $data);
        
        $wpdb->insert($table, [
            'conversation_id' => $conversation_id,
            'session_id' => $session_id,
            'user_id' => $data['user_id'] ?? 0,
            'client_name' => $data['client_name'] ?? '',
            'message_id' => $data['message_id'] ?? '',
            'message_text' => $data['message_text'] ?? '',
            'message_from' => $data['message_from'] ?? 'user',
            'message_type' => $data['message_type'] ?? 'text',
            'plugin_slug' => $data['plugin_slug'] ?? '',
            'tool_name' => $data['tool_name'] ?? '',
            'intent_conversation_id' => $data['intent_conversation_id'] ?? '',
            'attachments' => is_array($data['attachments'] ?? null) ? wp_json_encode($data['attachments']) : '',
            'platform_type' => $data['platform_type'] ?? 'WEBCHAT',
            'project_id' => $data['project_id'] ?? '',
            'meta' => isset($data['meta']) ? wp_json_encode($data['meta']) : '',
        ]);

        // Fire hook for global logger (bizcity-bot-agent)
        do_action('bizcity_webchat_message_saved', array_merge($data, [
            'blog_id' => get_current_blog_id(),
        ]));

        // V3: Update session stats + auto-gen title
        $this->update_session_stats_v3( $session_id, $data );
        
        return $conversation_id;
    }

    /**
     * Retroactively update a message row with intent tracking fields.
     * Called after engine processing to stamp the user message (logged before engine runs)
     * with intent_conversation_id and plugin_slug for HIL loop scoping.
     *
     * @param string $message_id  The uniqid-based message_id used during insert.
     * @param array  $fields      Associative array of columns to update.
     */
    public function update_message_tracking( $message_id, $fields ) {
        if ( empty( $message_id ) || empty( $fields ) ) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        $update = [];
        $allowed = [ 'plugin_slug', 'tool_name', 'intent_conversation_id' ];
        foreach ( $allowed as $col ) {
            if ( isset( $fields[ $col ] ) ) {
                $update[ $col ] = $fields[ $col ];
            }
        }
        if ( empty( $update ) ) {
            return;
        }

        $wpdb->update( $table, $update, [ 'message_id' => $message_id ] );
    }
    
    /**
     * Update V3 session stats (message_count, last_message_at, auto-gen title).
     * Auto-creates V3 session record if it doesn't exist.
     *
     * @param string $session_id
     * @param array  $data
     */
    private function update_session_stats_v3( $session_id, $data ) {
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'bizcity_webchat_sessions';
        
        // Check if sessions table exists
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$sessions_table'" ) !== $sessions_table ) {
            return;
        }
        
        // Get session
        $session = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, title, title_generated, message_count FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
            $session_id
        ) );
        
        // Auto-create V3 session if it doesn't exist
        if ( ! $session ) {
            $user_id = $data['user_id'] ?? get_current_user_id();
            $client_name = $data['client_name'] ?? '';
            $platform_type = $data['platform_type'] ?? 'WEBCHAT';
            
            // Check if session_id format matches ADMINCHAT pattern
            if ( strpos( $session_id, 'adminchat_' ) === 0 ) {
                $platform_type = 'ADMINCHAT';
            }
            
            // Create session record
            $wpdb->insert( $sessions_table, [
                'session_id'           => $session_id,
                'user_id'              => (int) $user_id,
                'project_id'           => '',
                'character_id'         => 0,
                'title'                => '',
                'title_generated'      => 0,
                'client_name'          => $client_name,
                'platform_type'        => $platform_type,
                'status'               => 'active',
                'rolling_summary'      => '',
                'summary_updated_at'   => null,
                'context_tokens'       => 0,
                'message_count'        => 0,
                'last_message_at'      => null,
                'last_message_preview' => '',
                'meta'                 => null,
                'started_at'           => current_time('mysql'),
                'ended_at'             => null,
            ] );
            
            // Log auto-create to Router Console
            if ( class_exists( 'BizCity_User_Memory' ) ) {
                BizCity_User_Memory::log_router_event( [
                    'step'             => 'session_auto_create',
                    'message'          => 'V3 session auto-created on first message',
                    'mode'             => 'webchat_db',
                    'functions_called' => 'update_session_stats_v3() → INSERT',
                    'file_line'        => 'class-webchat-database.php',
                    'session_uuid'     => $session_id,
                    'platform_type'    => $platform_type,
                    'user_id'          => (int) $user_id,
                    'status'           => 'success',
                ], $session_id );
            }
            
            // Fetch the newly created session
            $session = $wpdb->get_row( $wpdb->prepare(
                "SELECT id, title, title_generated, message_count FROM {$sessions_table} WHERE session_id = %s LIMIT 1",
                $session_id
            ) );
            
            if ( ! $session ) {
                // Log failure
                if ( class_exists( 'BizCity_User_Memory' ) ) {
                    BizCity_User_Memory::log_router_event( [
                        'step'             => 'session_auto_create',
                        'message'          => 'Failed to create V3 session',
                        'mode'             => 'webchat_db',
                        'functions_called' => 'update_session_stats_v3() → INSERT',
                        'file_line'        => 'class-webchat-database.php',
                        'session_uuid'     => $session_id,
                        'status'           => 'failed',
                        'db_error'         => $wpdb->last_error,
                    ], $session_id );
                }
                return; // Failed to create
            }
        }
        
        $message_text = $data['message_text'] ?? '';
        $message_from = $data['message_from'] ?? 'user';
        $new_count = (int) $session->message_count + 1;
        
        // Prepare update data
        $update = [
            'message_count'        => $new_count,
            'last_message_at'      => current_time('mysql'),
            'last_message_preview' => mb_substr( $message_text, 0, 100 ),
        ];
        
        // Auto-gen title from first USER message if title is empty
        $title_generated_now = false;
        if ( $message_from === 'user' && empty( $session->title ) && (int) $session->title_generated === 0 ) {
            $update['title'] = $this->generate_session_title( $message_text );
            $update['title_generated'] = 1;
            $title_generated_now = true;
        }
        
        $wpdb->update( $sessions_table, $update, [ 'id' => (int) $session->id ] );
        
        // Log to Router Console
        if ( class_exists( 'BizCity_User_Memory' ) ) {
            $log_data = [
                'step'             => 'session_stats_update',
                'message'          => mb_substr( $message_text, 0, 60, 'UTF-8' ),
                'mode'             => 'webchat_db',
                'functions_called' => 'update_session_stats_v3()',
                'file_line'        => 'class-webchat-database.php',
                'session_pk'       => (int) $session->id,
                'session_uuid'     => $session_id,
                'message_count'    => $new_count,
                'title_generated'  => $title_generated_now ? 'yes' : 'no',
            ];
            if ( $title_generated_now ) {
                $log_data['new_title'] = $update['title'];
            }
            BizCity_User_Memory::log_router_event( $log_data, $session_id );
        }
    }
    
    /**
     * Generate a session title from the first user message.
     * Truncates to ~40 chars, tries to keep meaningful words.
     *
     * @param string $message
     * @return string
     */
    private function generate_session_title( $message ) {
        $message = trim( $message );
        if ( empty( $message ) ) {
            return 'Hội thoại mới';
        }
        
        // Remove newlines, collapse whitespace
        $message = preg_replace('/\s+/', ' ', $message);
        
        // If short enough, use as-is
        if ( mb_strlen( $message ) <= 40 ) {
            return $message;
        }
        
        // Truncate at word boundary
        $truncated = mb_substr( $message, 0, 40 );
        $last_space = mb_strrpos( $truncated, ' ' );
        if ( $last_space > 20 ) {
            $truncated = mb_substr( $truncated, 0, $last_space );
        }
        
        return $truncated . '...';
    }
    
    /**
     * Get or create conversation
     */
    public function get_or_create_conversation($session_id, $data = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        
        // Check existing active conversation
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_id = %s AND status = 'active' ORDER BY id DESC LIMIT 1",
            $session_id
        ));
        
        if ($existing) {
            return (int) $existing;
        }
        
        // Create new conversation
        $wpdb->insert($table, [
            'session_id' => $session_id,
            'user_id' => $data['user_id'] ?? 0,
            'client_name' => $data['client_name'] ?? '',
            'platform_type' => $data['platform_type'] ?? 'WEBCHAT',
            'status' => 'active',
        ]);
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get conversation history
     */
    public function get_conversation_history($session_id, $limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id ASC LIMIT %d",
            $session_id,
            $limit
        ));
        
        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'id' => $row->id,
                'message_id' => $row->message_id,
                'msg' => $row->message_text,
                'from' => $row->message_from,
                'client_name' => $row->client_name,
                'attachments' => $row->attachments ? json_decode($row->attachments, true) : [],
                'time' => $row->created_at,
            ];
        }
        
        return $history;
    }
    
    /**
     * Create task (for timeline tracking)
     */
    public function create_task($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_tasks';
        
        $task_id = $data['task_id'] ?? uniqid('task_');
        
        $wpdb->insert($table, [
            'task_id' => $task_id,
            'session_id' => $data['session_id'] ?? '',
            'user_id' => $data['user_id'] ?? 0,
            'triggered_by' => $data['triggered_by'] ?? '',
            'task_name' => $data['task_name'] ?? '',
            'task_status' => 'running',
            'workflow_id' => $data['workflow_id'] ?? 0,
        ]);
        
        return $task_id;
    }
    
    /**
     * Update task
     */
    public function update_task($task_id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_tasks';
        
        return $wpdb->update($table, $data, ['task_id' => $task_id]);
    }
    
    /**
     * Complete task
     */
    public function complete_task($task_id, $status = 'completed') {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_tasks';
        
        return $wpdb->update($table, [
            'task_status' => $status,
            'completed_at' => current_time('mysql'),
        ], ['task_id' => $task_id]);
    }
    
    /**
     * Add task step
     */
    public function add_task_step($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_task_steps';
        
        $step_id = $data['step_id'] ?? uniqid('step_');
        
        $wpdb->insert($table, [
            'step_id' => $step_id,
            'task_id' => $data['task_id'] ?? '',
            'step_type' => $data['step_type'] ?? 'action',
            'step_name' => $data['step_name'] ?? '',
            'step_status' => $data['step_status'] ?? 'pending',
            'input_data' => isset($data['input_data']) ? wp_json_encode($data['input_data']) : '',
            'output_data' => isset($data['output_data']) ? wp_json_encode($data['output_data']) : '',
            'duration_ms' => $data['duration_ms'] ?? 0,
        ]);
        
        return $step_id;
    }
    
    /**
     * Complete task step
     */
    public function complete_task_step($step_id, $output_data = null, $status = 'completed') {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_task_steps';
        
        $update_data = [
            'step_status' => $status,
            'completed_at' => current_time('mysql'),
        ];
        
        if ($output_data !== null) {
            $update_data['output_data'] = wp_json_encode($output_data);
        }
        
        return $wpdb->update($table, $update_data, ['step_id' => $step_id]);
    }
    
    /**
     * Get task with steps (for timeline)
     */
    public function get_task_timeline($task_id) {
        global $wpdb;
        $tasks_table = $wpdb->prefix . 'bizcity_webchat_tasks';
        $steps_table = $wpdb->prefix . 'bizcity_webchat_task_steps';
        
        $task = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$tasks_table} WHERE task_id = %s",
            $task_id
        ));
        
        if (!$task) return null;
        
        $steps = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$steps_table} WHERE task_id = %s ORDER BY id ASC",
            $task_id
        ));
        
        return [
            'task' => $task,
            'steps' => $steps,
        ];
    }
    
    /**
     * Get recent tasks for session
     */
    public function get_session_tasks($session_id, $limit = 10) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_tasks';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT %d",
            $session_id,
            $limit
        ));
    }
    
    /**
     * Get recent tasks (all sessions)
     */
    public function get_recent_tasks($limit = 20) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_tasks';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Get conversation by session ID
     */
    public function get_conversation_by_session($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ));
    }
    
    /**
     * Close conversation
     */
    public function close_conversation($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        
        return $wpdb->update(
            $table,
            [
                'status' => 'closed',
                'ended_at' => current_time('mysql'),
            ],
            ['session_id' => $session_id]
        );
    }
    
    /**
     * Count messages for session
     */
    public function count_messages($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %s",
            $session_id
        ));
    }
    
    /**
     * Get conversations list
     */
    public function get_conversations($status = 'active', $limit = 20, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        
        $where = '';
        if ($status !== 'all') {
            $where = $wpdb->prepare("WHERE status = %s", $status);
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY started_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }
    
    /**
     * Count conversations
     */
    public function count_conversations($status = 'active') {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        
        if ($status === 'all') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %s",
            $status
        ));
    }

    /* ================================================================
     *  Session Management (v3.0.0 — ChatGPT-style chat sessions)
     * ================================================================ */

    /**
     * Create a new chat session (webchat_conversation) with a unique session_id.
     *
     * @param int    $user_id
     * @param string $client_name
     * @param string $platform_type
     * @param string $title  Optional initial title.
     * @return array { id, session_id, title }
     */
    public function create_session( $user_id, $client_name = '', $platform_type = 'ADMINCHAT', $title = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';

        // Use old format for ADMINCHAT: adminchat_{blogId}_{userId}
        // This ensures compatibility with intent system which looks up by session_id
        if ( $platform_type === 'ADMINCHAT' ) {
            $session_id = 'adminchat_' . get_current_blog_id() . '_' . (int) $user_id;
        } else {
            $uuid       = wp_generate_uuid4();
            $session_id = 'wcs_' . $uuid;
        }

        $wpdb->insert( $table, [
            'session_id'    => $session_id,
            'user_id'       => (int) $user_id,
            'client_name'   => $client_name,
            'title'         => $title,
            'platform_type' => $platform_type,
            'status'        => 'active',
        ] );

        $id = $wpdb->insert_id;

        return [
            'id'         => $id,
            'session_id' => $session_id,
            'title'      => $title,
        ];
    }

    /**
     * Update session title.
     *
     * @param int    $id    Primary key.
     * @param string $title New title.
     * @return bool
     */
    public function update_session_title( $id, $title ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        return (bool) $wpdb->update( $table, [ 'title' => $title ], [ 'id' => (int) $id ] );
    }

    /**
     * Update session project_id.
     *
     * @param int    $id         Primary key.
     * @param string $project_id Project UUID or '' to unassign.
     * @return bool
     */
    public function update_session_project( $id, $project_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        return (bool) $wpdb->update( $table, [ 'project_id' => $project_id ], [ 'id' => (int) $id ] );
    }

    /**
     * Get chat sessions for a user, ordered by most recent first.
     *
     * @param int         $user_id
     * @param string|null $platform_type  Filter by platform, or null for all.
     * @param int         $limit
     * @param string|null $project_id     Filter by project (null=all, ''=unassigned).
     * @return array
     */
    public function get_sessions_for_user( $user_id, $platform_type = null, $limit = 30, $project_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';

        $where = $wpdb->prepare( "WHERE user_id = %d AND status = 'active'", $user_id );

        if ( $platform_type ) {
            $where .= $wpdb->prepare( ' AND platform_type = %s', $platform_type );
        }

        if ( $project_id !== null ) {
            $where .= $wpdb->prepare( ' AND project_id = %s', $project_id );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY started_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Get messages for a specific webchat conversation (by conversation PK).
     *
     * @param int $conversation_id  The webchat_conversations.id (PK).
     * @param int $limit
     * @return array
     */
    public function get_messages_by_conversation_id( $conversation_id, $limit = 100 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY id ASC LIMIT %d",
            $conversation_id,
            $limit
        ) );
    }

    /**
     * Get messages by session_id UUID string (e.g. 'wcs_xxx' or 'adminchat_xxx').
     *
     * @param string $session_id  The UUID session_id stored in messages table.
     * @param int    $limit
     * @return array
     */
    public function get_messages_by_session_id( $session_id, $limit = 100 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM {$table} WHERE session_id = %s ORDER BY id ASC LIMIT %d",
            $session_id,
            $limit
        ) );
    }

    /**
     * Get messages by session_id filtered by plugin_slug.
     * Used for gathering context when @ mention is active.
     *
     * @param string $session_id  Session identifier.
     * @param string $plugin_slug Plugin slug to filter by.
     * @param int    $limit       Max messages to return.
     * @return array              Array of message objects.
     */
    public function get_messages_by_session_and_plugin( $session_id, $plugin_slug, $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts 
             FROM {$table} 
             WHERE session_id = %s AND plugin_slug = %s 
             ORDER BY id ASC 
             LIMIT %d",
            $session_id,
            $plugin_slug,
            $limit
        ) );
    }

    /**
     * Get messages scoped to a specific intent conversation.
     * This is the PRIMARY query for HIL loop context — ensures only messages
     * within the same intent goal are returned, not the full session.
     *
     * @param string $intent_conversation_id  UUID from bizcity_intent_conversations.
     * @param int    $limit                   Max messages to return.
     * @return array                          Array of message objects.
     */
    public function get_messages_by_intent_conversation_id( $intent_conversation_id, $limit = 50 ) {
        if ( empty( $intent_conversation_id ) ) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts
             FROM {$table}
             WHERE intent_conversation_id = %s
             ORDER BY id ASC
             LIMIT %d",
            $intent_conversation_id,
            $limit
        ) );
    }

    /**
     * Get recent messages for context within a specific intent conversation.
     * Lightweight version for LLM prompt building (smooth_tool_ask_prompt, etc.).
     *
     * Falls back to session-wide query if intent_conversation_id is empty
     * (backwards compat for pre-migration messages).
     *
     * @param string $intent_conversation_id  UUID from bizcity_intent_conversations.
     * @param string $session_id              Fallback: session UUID.
     * @param int    $limit                   Max messages.
     * @return array                          Array of {message_from, message_text} objects.
     */
    public function get_recent_messages_by_intent_conversation( $intent_conversation_id, $session_id = '', $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        // Primary: query by intent_conversation_id (narrow HIL scope)
        if ( ! empty( $intent_conversation_id ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT message_from, message_text FROM {$table}
                 WHERE intent_conversation_id = %s
                 ORDER BY id DESC LIMIT %d",
                $intent_conversation_id,
                $limit
            ) );
            if ( ! empty( $rows ) ) {
                return array_reverse( $rows );
            }
        }

        // Fallback: session-wide (pre-migration messages without intent_conversation_id)
        if ( ! empty( $session_id ) ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT message_from, message_text FROM {$table}
                 WHERE session_id = %s
                 ORDER BY id DESC LIMIT %d",
                $session_id,
                $limit
            ) );
            return array_reverse( $rows ?: [] );
        }

        return [];
    }

    /**
     * Get the last active plugin_slug in a session.
     * Useful for maintaining context continuity.
     *
     * @param string $session_id  Session identifier.
     * @return string|null        Last plugin_slug or null.
     */
    public function get_last_plugin_slug_in_session( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT plugin_slug 
             FROM {$table} 
             WHERE session_id = %s AND plugin_slug != '' 
             ORDER BY id DESC 
             LIMIT 1",
            $session_id
        ) );
    }

    /**
     * Close (archive) a session.
     *
     * @param int $id  Primary key.
     * @return bool
     */
    public function close_session( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        return (bool) $wpdb->update(
            $table,
            [ 'status' => 'closed', 'ended_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $id ]
        );
    }

    /**
     * Delete a session and its messages.
     *
     * @param int $id  Primary key.
     * @return bool
     */
    public function delete_session( $id ) {
        global $wpdb;
        $tbl_conv = $wpdb->prefix . 'bizcity_webchat_conversations';
        $tbl_msg  = $wpdb->prefix . 'bizcity_webchat_messages';

        // Get session_id for message cleanup
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT session_id FROM {$tbl_conv} WHERE id = %d", $id ) );
        if ( $row ) {
            $wpdb->delete( $tbl_msg, [ 'conversation_id' => (int) $id ] );
        }

        return (bool) $wpdb->delete( $tbl_conv, [ 'id' => (int) $id ] );
    }

    /**
     * Close all active sessions for a user.
     *
     * @param int    $user_id
     * @param string $platform_type
     * @return int  Number of rows updated.
     */
    public function close_all_sessions( $user_id, $platform_type = 'ADMINCHAT' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        return (int) $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET status = 'closed', ended_at = %s WHERE user_id = %d AND platform_type = %s AND status = 'active'",
            current_time( 'mysql' ),
            $user_id,
            $platform_type
        ) );
    }

    /**
     * Get a single session by id.
     *
     * @param int $id Primary key.
     * @return object|null
     */
    public function get_session( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get the first user message text in a session (for auto-titling).
     *
     * @param int $conversation_id  The webchat_conversations.id (PK).
     * @return string
     */
    public function get_first_user_message_in_session( $conversation_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $msg   = $wpdb->get_var( $wpdb->prepare(
            "SELECT message_text FROM {$table} WHERE conversation_id = %d AND message_from = 'user' ORDER BY id ASC LIMIT 1",
            $conversation_id
        ) );
        return $msg ? trim( $msg ) : '';
    }

    /**
     * Get a webchat conversation row by its session_id string (e.g. 'wcs_xxx').
     *
     * @param string $session_id  The unique session_id string.
     * @return object|null
     */
    public function get_session_by_session_id( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_conversations';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ) );
    }

    /**
     * Get recent messages for context building (lightweight: id, message_from, message_text only).
     *
     * @param int $conversation_id  The webchat_conversations.id (PK).
     * @param int $limit            Max messages (from most recent).
     * @return array
     */
    public function get_recent_messages_for_context( $conversation_id, $limit = 15 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';

        // Get last N messages, then reverse to chronological order
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT message_from, message_text FROM {$table}
             WHERE conversation_id = %d
             ORDER BY id DESC LIMIT %d",
            $conversation_id,
            $limit
        ) );

        return array_reverse( $rows ?: [] );
    }

    /* ================================================================
     *  V3.0 NEW: Project Management
     * ================================================================ */

    /**
     * Create a new project.
     *
     * @param int    $user_id
     * @param string $name
     * @param array  $data  Optional: character_id, description, icon, color, settings, is_public
     * @return array { id, project_id, name }
     */
    public function create_project( $user_id, $name, $data = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_projects';

        // Ensure project_id column is wide enough (fix VARCHAR(36) to 50)
        $this->maybe_alter_project_id_column();

        $project_id = 'proj_' . wp_generate_uuid4();

        $result = $wpdb->insert( $table, [
            'project_id'       => $project_id,
            'user_id'          => (int) $user_id,
            'character_id'     => isset($data['character_id']) ? (int) $data['character_id'] : 0,
            'name'             => $name,
            'description'      => $data['description'] ?? '',
            'icon'             => $data['icon'] ?? '📁',
            'color'            => $data['color'] ?? '#6366f1',
            'settings'         => isset($data['settings']) ? wp_json_encode($data['settings']) : null,
            'knowledge_ids'    => $data['knowledge_ids'] ?? '',
            'file_ids'         => $data['file_ids'] ?? '',
            'is_public'        => isset($data['is_public']) ? (int) $data['is_public'] : 0,
            'is_archived'      => 0,
            'sort_order'       => 0,
            'session_count'    => 0,
            'last_activity_at' => current_time('mysql'),
        ] );

        if ( $result === false ) {
            error_log( '[bizcity-webchat] create_project INSERT failed: ' . $wpdb->last_error . ' | project_id=' . $project_id );
        }

        return [
            'id'         => $wpdb->insert_id,
            'project_id' => $project_id,
            'name'       => $name,
        ];
    }

    /**
     * Fix project_id column from VARCHAR(36) to VARCHAR(50) if needed.
     * The 'proj_' prefix + UUID = 41 chars which overflows VARCHAR(36).
     */
    private function maybe_alter_project_id_column() {
        static $done = false;
        if ( $done ) return;
        $done = true;

        global $wpdb;

        // Fix projects table: project_id VARCHAR(36) -> 50
        $tbl_proj = $wpdb->prefix . 'bizcity_webchat_projects';
        $col = $wpdb->get_row( "SHOW COLUMNS FROM `{$tbl_proj}` WHERE Field = 'project_id'" );
        if ( $col && strpos( $col->Type, '36' ) !== false ) {
            $wpdb->query( "ALTER TABLE `{$tbl_proj}` MODIFY COLUMN project_id VARCHAR(50) NOT NULL" );
        }

        // Fix sessions table: project_id VARCHAR(36) -> 50
        $tbl_sess = $wpdb->prefix . 'bizcity_webchat_sessions';
        $col2 = $wpdb->get_row( "SHOW COLUMNS FROM `{$tbl_sess}` WHERE Field = 'project_id'" );
        if ( $col2 && strpos( $col2->Type, '36' ) !== false ) {
            $wpdb->query( "ALTER TABLE `{$tbl_sess}` MODIFY COLUMN project_id VARCHAR(50) DEFAULT ''" );
        }
    }

    /**
     * Get a project by its id (PK).
     *
     * @param int $id  Primary key.
     * @return object|null
     */
    public function get_project( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_projects';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get a project by its project_id UUID.
     *
     * @param string $project_id  UUID like proj_xxx.
     * @return object|null
     */
    public function get_project_by_uuid( $project_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_projects';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE project_id = %s", $project_id ) );
    }

    /**
     * Get all projects for a user.
     *
     * @param int  $user_id
     * @param bool $include_archived  Include archived projects.
     * @return array
     */
    public function get_projects_for_user( $user_id, $include_archived = false ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_projects';

        $where = $wpdb->prepare( "WHERE user_id = %d", $user_id );
        if ( ! $include_archived ) {
            $where .= " AND is_archived = 0";
        }

        return $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, last_activity_at DESC" );
    }

    /**
     * Update a project.
     *
     * @param int   $id    Primary key.
     * @param array $data  Fields to update.
     * @return bool
     */
    public function update_project( $id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_projects';

        $allowed = ['name', 'description', 'icon', 'color', 'character_id', 'settings', 'knowledge_ids', 'file_ids', 'is_public', 'is_archived', 'sort_order'];
        $update = [];
        foreach ( $allowed as $key ) {
            if ( isset($data[$key]) ) {
                if ( $key === 'settings' && is_array($data[$key]) ) {
                    $update[$key] = wp_json_encode($data[$key]);
                } else {
                    $update[$key] = $data[$key];
                }
            }
        }

        if ( empty($update) ) {
            return false;
        }

        $update['updated_at'] = current_time('mysql');
        return (bool) $wpdb->update( $table, $update, [ 'id' => (int) $id ] );
    }

    /**
     * Rename a project.
     *
     * @param int    $id    Primary key.
     * @param string $name  New name.
     * @return bool
     */
    public function rename_project( $id, $name ) {
        return $this->update_project( $id, [ 'name' => $name ] );
    }

    /**
     * Delete a project. Sessions are unassigned (project_id = '').
     *
     * @param int $id  Primary key.
     * @return bool
     */
    public function delete_project( $id ) {
        global $wpdb;
        $tbl_proj = $wpdb->prefix . 'bizcity_webchat_projects';
        $tbl_sess = $wpdb->prefix . 'bizcity_webchat_sessions';

        // Get project_id for session cleanup
        $project = $this->get_project( $id );
        if ( $project ) {
            // Unassign sessions
            $wpdb->update( $tbl_sess, [ 'project_id' => '' ], [ 'project_id' => $project->project_id ] );
        }

        return (bool) $wpdb->delete( $tbl_proj, [ 'id' => (int) $id ] );
    }

    /**
     * Update project session count (cached field).
     *
     * @param string $project_id  UUID.
     * @return void
     */
    public function update_project_session_count( $project_id ) {
        global $wpdb;
        $tbl_proj = $wpdb->prefix . 'bizcity_webchat_projects';
        $tbl_sess = $wpdb->prefix . 'bizcity_webchat_sessions';

        if ( empty($project_id) ) {
            return;
        }

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$tbl_sess} WHERE project_id = %s AND status != 'archived'",
            $project_id
        ) );

        $wpdb->update( $tbl_proj, [
            'session_count'    => $count,
            'last_activity_at' => current_time('mysql'),
        ], [ 'project_id' => $project_id ] );
    }

    /**
     * Search public projects by name.
     *
     * @param string $query  Search query.
     * @param int    $limit  Max results.
     * @return array
     */
    public function search_public_projects( $query, $limit = 10 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_projects';

        $like = '%' . $wpdb->esc_like( $query ) . '%';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE is_public = 1 AND is_archived = 0 AND name LIKE %s ORDER BY session_count DESC LIMIT %d",
            $like,
            $limit
        ) );
    }

    /* ================================================================
     *  V3.0 NEW: Session Management (webchat_sessions table)
     * ================================================================ */

    /**
     * Create a new session in webchat_sessions (V3.0).
     *
     * @param int    $user_id
     * @param string $client_name
     * @param string $platform_type
     * @param string $title
     * @param array  $data  Optional: project_id, character_id
     * @return array { id, session_id, title }
     */
    public function create_session_v3( $user_id, $client_name = '', $platform_type = 'ADMINCHAT', $title = '', $data = [] ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        // V3: Always generate unique session_id (allows multiple sessions per user)
        // Legacy ADMINCHAT format: adminchat_{blog_id}_{user_id} - only 1 session per user
        // New format: wcs_{uuid} - allows multiple sessions
        $session_id = 'wcs_' . wp_generate_uuid4();

        // Inherit character_id from project if not explicitly provided
        $character_id = isset($data['character_id']) ? (int) $data['character_id'] : 0;
        if ( !$character_id && !empty($data['project_id']) ) {
            $project = $this->get_project_by_uuid( $data['project_id'] );
            if ( $project && !empty($project->character_id) ) {
                $character_id = (int) $project->character_id;
            }
        }

        $wpdb->insert( $table, [
            'session_id'           => $session_id,
            'user_id'              => (int) $user_id,
            'project_id'           => $data['project_id'] ?? '',
            'character_id'         => $character_id,
            'title'                => $title,
            'title_generated'      => 0,
            'client_name'          => $client_name,
            'platform_type'        => $platform_type,
            'status'               => 'active',
            'rolling_summary'      => '',
            'summary_updated_at'   => null,
            'context_tokens'       => 0,
            'message_count'        => 0,
            'last_message_at'      => null,
            'last_message_preview' => '',
            'meta'                 => null,
            'started_at'           => current_time('mysql'),
            'ended_at'             => null,
        ] );

        $id = $wpdb->insert_id;

        // Update project session count
        if ( !empty($data['project_id']) ) {
            $this->update_project_session_count( $data['project_id'] );
        }

        return [
            'id'         => $id,
            'session_id' => $session_id,
            'title'      => $title,
        ];
    }

    /**
     * Get a session from webchat_sessions by id.
     *
     * @param int $id  Primary key.
     * @return object|null
     */
    public function get_session_v3( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
    }

    /**
     * Get a session from webchat_sessions by session_id.
     *
     * @param string $session_id  e.g. wcs_xxx or adminchat_xxx
     * @return object|null
     */
    public function get_session_v3_by_session_id( $session_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1",
            $session_id
        ) );
    }

    /**
     * Get sessions from webchat_sessions for a user.
     *
     * @param int         $user_id
     * @param string|null $platform_type  Filter by platform.
     * @param int         $limit
     * @param string|null $project_id     Filter by project (null=all, ''=unassigned).
     * @return array
     */
    public function get_sessions_v3_for_user( $user_id, $platform_type = null, $limit = 30, $project_id = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        $where = $wpdb->prepare( "WHERE user_id = %d AND status = 'active'", $user_id );

        if ( $platform_type ) {
            $where .= $wpdb->prepare( ' AND platform_type = %s', $platform_type );
        }

        if ( $project_id !== null ) {
            $where .= $wpdb->prepare( ' AND project_id = %s', $project_id );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} {$where} ORDER BY last_message_at DESC, started_at DESC LIMIT %d",
            $limit
        ) );
    }

    /**
     * Get sessions by project_id.
     *
     * @param string $project_id  UUID.
     * @param int    $limit
     * @return array
     */
    public function get_sessions_by_project( $project_id, $limit = 50 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE project_id = %s AND status != 'archived' ORDER BY last_message_at DESC LIMIT %d",
            $project_id,
            $limit
        ) );
    }

    /**
     * Update session in webchat_sessions.
     *
     * @param int   $id    Primary key.
     * @param array $data  Fields to update.
     * @return bool
     */
    public function update_session_v3( $id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        $allowed = ['title', 'title_generated', 'project_id', 'character_id', 'status', 'rolling_summary', 'summary_updated_at', 'context_tokens', 'message_count', 'last_message_at', 'last_message_preview', 'ended_at', 'meta'];
        $update = [];
        foreach ( $allowed as $key ) {
            if ( isset($data[$key]) ) {
                if ( $key === 'meta' && is_array($data[$key]) ) {
                    $update[$key] = wp_json_encode($data[$key]);
                } else {
                    $update[$key] = $data[$key];
                }
            }
        }

        if ( empty($update) ) {
            return false;
        }

        return (bool) $wpdb->update( $table, $update, [ 'id' => (int) $id ] );
    }

    /**
     * Move session to a project.
     *
     * @param int    $id          Primary key.
     * @param string $project_id  Project UUID or '' to unassign.
     * @return bool
     */
    public function move_session_to_project( $id, $project_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        // Ensure project_id column is wide enough (VARCHAR(36) -> 50)
        $this->maybe_alter_project_id_column();

        // Get old project_id
        $session = $this->get_session_v3( $id );
        if ( ! $session ) {
            return false;
        }

        $old_project = $session->project_id;
        $result = (bool) $wpdb->update( $table, [ 'project_id' => $project_id ], [ 'id' => (int) $id ] );

        // Update session counts
        if ( $result ) {
            if ( $old_project ) {
                $this->update_project_session_count( $old_project );
            }
            if ( $project_id ) {
                $this->update_project_session_count( $project_id );
            }
        }

        return $result;
    }

    /**
     * Update session rolling summary.
     *
     * @param int    $id       Primary key.
     * @param string $summary  New rolling summary.
     * @return bool
     */
    public function update_session_summary( $id, $summary ) {
        return $this->update_session_v3( $id, [
            'rolling_summary'    => $summary,
            'summary_updated_at' => current_time('mysql'),
        ] );
    }

    /**
     * Update session message stats after new message.
     *
     * @param string $session_id     Session ID string.
     * @param string $message_text   Last message text.
     * @param int    $message_count  New message count (optional, will be counted if 0).
     * @return void
     */
    public function update_session_message_stats( $session_id, $message_text, $message_count = 0 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_sessions';

        // Get session
        $session = $this->get_session_v3_by_session_id( $session_id );
        if ( ! $session ) {
            return;
        }

        // Count messages if not provided
        if ( $message_count <= 0 ) {
            $tbl_msg = $wpdb->prefix . 'bizcity_webchat_messages';
            $message_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$tbl_msg} WHERE session_id = %s",
                $session_id
            ) );
        }

        $wpdb->update( $table, [
            'message_count'        => $message_count,
            'last_message_at'      => current_time('mysql'),
            'last_message_preview' => mb_substr( $message_text, 0, 200 ),
        ], [ 'id' => $session->id ] );
    }

    /**
     * Delete a session from webchat_sessions and its messages.
     *
     * @param int $id  Primary key.
     * @return bool
     */
    public function delete_session_v3( $id ) {
        global $wpdb;
        $tbl_sess = $wpdb->prefix . 'bizcity_webchat_sessions';
        $tbl_msg  = $wpdb->prefix . 'bizcity_webchat_messages';

        $session = $this->get_session_v3( $id );
        if ( ! $session ) {
            return false;
        }

        // Delete messages
        $wpdb->delete( $tbl_msg, [ 'session_id' => $session->session_id ] );

        // Update project count
        if ( $session->project_id ) {
            $this->update_project_session_count( $session->project_id );
        }

        return (bool) $wpdb->delete( $tbl_sess, [ 'id' => (int) $id ] );
    }

    /**
     * Get project context for building LLM context.
     *
     * @param string $project_id  UUID.
     * @return array  Context data.
     */
    public function get_project_context( $project_id ) {
        $project = $this->get_project_by_uuid( $project_id );
        if ( ! $project ) {
            return [];
        }

        $context = [
            'project_name'        => $project->name,
            'project_description' => $project->description,
            'character_id'        => $project->character_id,
            'knowledge_ids'       => $project->knowledge_ids ? explode(',', $project->knowledge_ids) : [],
            'file_ids'            => $project->file_ids ? explode(',', $project->file_ids) : [],
            'settings'            => $project->settings ? json_decode($project->settings, true) : [],
        ];

        // Get rolling summaries from all sessions in project
        $sessions = $this->get_sessions_by_project( $project_id, 20 );
        $summaries = [];
        foreach ( $sessions as $sess ) {
            if ( ! empty($sess->rolling_summary) ) {
                $summaries[] = "【{$sess->title}】\n{$sess->rolling_summary}";
            }
        }
        $context['project_memory'] = implode( "\n---\n", $summaries );

        return $context;
    }
}

} // End class_exists check
