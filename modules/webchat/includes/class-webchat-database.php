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
    const SCHEMA_VERSION = '5.1.0'; // [2026-07-31 Johnny Chu] PHASE-1.22-TOOL-CATALOG — retire the unused webchat_tools catalog.

    /**
     * Table lifecycle policy. The message projection stays active under the
     * core contract; legacy projections are frozen before any DROP decision.
     */
    private static $table_policy = array(
        'bizcity_webchat_messages'    => 'core_active',
        'bizcity_webchat_projects'    => 'retired',
        'bizcity_webchat_sessions'    => 'quarantine',
        'bizcity_webchat_conversations' => 'quarantine',
        'bizcity_webchat_tasks'       => 'retired',
        'bizcity_webchat_task_steps'  => 'retired',
        'bizcity_memory_session'      => 'retired',
    );

    /**
     * Return the lifecycle policy for a bare table name.
     */
    public static function table_policy( $bare_name ) {
        return isset( self::$table_policy[ $bare_name ] )
            ? self::$table_policy[ $bare_name ]
            : 'unclassified';
    }

    /**
     * Check whether a table is currently blocked for new writes.
     */
    public static function table_write_blocked( $bare_name ) {
        // [2026-08-26 Johnny Chu] PHASE-1.30-CENTRAL-POLICY — WebChat write gates follow the central lifecycle state when available.
        // [2026-08-27 Johnny Chu] PHASE-1.30-WEBCHAT-FREEZE — preserve the stricter local write-freeze for staged projections in quarantine.
        if ( self::table_policy( $bare_name ) === 'quarantine_write_block' ) {
            return true;
        }
        if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && BizCity_Legacy_Table_Policy::is_legacy( $bare_name ) ) {
            return ! BizCity_Legacy_Table_Policy::allow_sql( $bare_name, 'write' );
        }
        return self::table_policy( $bare_name ) === 'quarantine_write_block';
    }

    /**
     * Check a policy table's physical presence without issuing raw metadata SQL.
     */
    public static function table_exists_for_policy( $bare_name ) {
        // [2026-08-25 Johnny Chu] PHASE-1.29-WEBCHAT-QUARANTINE — expose a safe existence check for compatibility readers.
        if ( class_exists( 'BizCity_Legacy_Table_Policy' ) && ! BizCity_Legacy_Table_Policy::allow_sql( $bare_name, 'read' ) ) {
            return false;
        }
        return self::physical_table_exists( $bare_name );
    }

    /**
     * Check physical existence without issuing a missing-table query.
     */
    private static function physical_table_exists( $bare_name ) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — resolve only the requested table; retired callers are gated before this helper.
        global $wpdb;
        $table = $wpdb->prefix . $bare_name;
        return function_exists( 'bizcity_tbl_exists' )
            ? bizcity_tbl_exists( $table )
            : ( function_exists( 'bizcity_table_exists' ) ? bizcity_table_exists( $table ) : false );
    }
    
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
    public function create_messages_table() {
        // [2026-08-25 Johnny Chu] PHASE-1.29-WEBCHAT-CORE-MESSAGE — expose only the core message DDL without provisioning quarantined projections.
        $this->create_tables( 'messages' );
        global $wpdb;
        if ( function_exists( 'bizcity_tbl_invalidate' ) ) {
            bizcity_tbl_invalidate( $wpdb->prefix . 'bizcity_webchat_messages' );
        }
    }

    public function create_tables( $scope = 'all' ) {
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
        
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — session metadata/state is owned by modules.webchat.session_state; no session SQL DDL is emitted.
        
        // ============================================
        // EXISTING TABLES (kept for backward compat)
        // ============================================
        
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
            finish_reason VARCHAR(32) DEFAULT '',
            is_context_included TINYINT(1) DEFAULT 1,
            importance_score TINYINT UNSIGNED DEFAULT 50,
            platform_type VARCHAR(32) DEFAULT 'WEBCHAT',
            project_id VARCHAR(50) DEFAULT '',
            status VARCHAR(20) DEFAULT 'visible',
            todo_id BIGINT UNSIGNED DEFAULT NULL,
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
        
        // [2026-08-25 Johnny Chu] PHASE-1.29-SAFE-LOADER — load dbDelta only through the guarded Core helper.
        if ( ! class_exists( 'BizCity_Safe_Loader', false )
            || ! BizCity_Safe_Loader::require_file( ABSPATH . 'wp-admin/includes/upgrade.php', 'wordpress.dbdelta' )
            || ! function_exists( 'dbDelta' ) ) {
            return;
        }

        if ( 'messages' === $scope ) {
            dbDelta($sql_messages);
            return;
        }
        
        // V3.0 new tables first
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — projects are owned by the notebook repository; never recreate the retired SQL projection.
        if ( ! self::table_write_blocked( 'bizcity_webchat_projects' ) ) {
            dbDelta($sql_projects);
        }
        // Canonical message projection; conversation metadata is represented by marker rows in this table.
        dbDelta($sql_messages);
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — no conversation-table installer remains.

        // Migration: add message columns and retain existing non-conversation support tables.
        $this->maybe_upgrade_conversations();
    }

    /**
     * Migration: add message/support columns for the active WebChat projection.
     * Public so it can be called from ensure_tables_exist().
     */
    public function maybe_upgrade_conversations() {
        global $wpdb;
        $table_messages = $wpdb->prefix . 'bizcity_webchat_messages';

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

        // Migration 8 (v3.9.1): Add todo_id to messages table for pipeline todo tracking
        if ( ! in_array( 'todo_id', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN todo_id BIGINT UNSIGNED DEFAULT NULL AFTER status" );
            $wpdb->query( "ALTER TABLE {$table_messages} ADD INDEX idx_todo_id (todo_id)" );
        }

        // Migration 9 (v4.0.0 — Phase 1.8): Add rating + is_pinned columns to messages
        if ( ! in_array( 'rating', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN rating VARCHAR(10) DEFAULT '' AFTER meta" );
        }
        if ( ! in_array( 'is_pinned', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN is_pinned TINYINT(1) DEFAULT 0 AFTER rating" );
        }

        // Migration 13 (v5.1 — Sprint 4.5): Add input_tokens + output_tokens if missing.
        // These columns exist in the CREATE TABLE DDL but may be absent on tables created
        // before this DDL version was deployed.
        if ( ! in_array( 'input_tokens', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN input_tokens INT DEFAULT 0 AFTER attachments" );
        }
        if ( ! in_array( 'output_tokens', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN output_tokens INT DEFAULT 0 AFTER input_tokens" );
        }

        // Migration 14 (Phase 0.6 — Sprint 0.6.16): persist LLM `finish_reason`
        // alongside per-direction token counts so we can power billing dashboards
        // (Phase 1.11.d) and detect truncated responses (`length`, `content_filter`).
        if ( ! in_array( 'finish_reason', $cols_msg, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_messages} ADD COLUMN finish_reason VARCHAR(32) DEFAULT '' AFTER output_tokens" );
        }

        // Migration 10 (v4.0.0 — Phase 1.8): Create webchat_notes table
        $table_notes = $wpdb->prefix . 'bizcity_webchat_notes';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_notes} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            note_type VARCHAR(32) DEFAULT 'quick_note',
            title VARCHAR(500) DEFAULT '',
            content LONGTEXT,
            source_message_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_type (note_type)
        ) {$charset_collate};" );

        // Migration 11 (v4.0.0 — Phase 1.8): Create webchat_sources table.
        // NOTE: columns below are the v1 DDL. Newer columns added by tail
        // migrations 12/15 (project_id, source_url, content_text, attachment_id,
        // content_hash, char_count, token_estimate, embedding_model,
        // error_message, metadata). Don't expand this CREATE — keep it minimal
        // so old shards converge through ALTERs to the same final schema.
        $table_sources = $wpdb->prefix . 'bizcity_webchat_sources';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS {$table_sources} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(128) DEFAULT '',
            user_id BIGINT UNSIGNED DEFAULT 0,
            source_type VARCHAR(32) DEFAULT 'url',
            title VARCHAR(500) DEFAULT '',
            url TEXT,
            content LONGTEXT,
            embedding_status VARCHAR(20) DEFAULT 'pending',
            chunk_count INT UNSIGNED DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_session (session_id),
            INDEX idx_user (user_id),
            INDEX idx_status (embedding_status)
        ) {$charset_collate};" );

        // Migration 12 (v5.0 — Notebook/Chat split): Add project_id + normalise column names.
        // Architecture: chat sources use session_id; notebook sources use project_id.
        // source_url / content_text replace legacy url / content for new rows.
        $cols_sources = $wpdb->get_col( "DESCRIBE {$table_sources}", 0 ) ?: [];
        if ( ! in_array( 'project_id', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN project_id VARCHAR(50) DEFAULT '' AFTER user_id" );
            $wpdb->query( "ALTER TABLE {$table_sources} ADD INDEX idx_project (project_id)" );
        }
        if ( ! in_array( 'source_url', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN source_url VARCHAR(1000) DEFAULT '' AFTER title" );
        }
        if ( ! in_array( 'content_text', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN content_text LONGTEXT AFTER source_url" );
        }
        if ( ! in_array( 'attachment_id', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN attachment_id BIGINT UNSIGNED DEFAULT NULL AFTER content_text" );
        }

        // Migration 15 (v3.11.0 — Sprint 4.5d): TwinChat ingest columns.
        // BizCity_TwinChat_Sources_Database::insert_source() writes these columns
        // (sha256 content_hash for dedup via find_by_hash, char_count/token_estimate
        // for cost analytics, embedding_model for vector provenance, error_message
        // + metadata for ingest debugging). Older shards (e.g. blog 1458) created
        // the table before these columns were added → INSERT fails with "Unknown
        // column 'content_hash'" and the sources sidebar shows empty. Add them
        // idempotently.
        $cols_sources = $wpdb->get_col( "DESCRIBE {$table_sources}", 0 ) ?: $cols_sources;
        if ( ! in_array( 'content_hash', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN content_hash CHAR(64) DEFAULT '' AFTER content_text" );
            $wpdb->query( "ALTER TABLE {$table_sources} ADD INDEX idx_project_hash (project_id, content_hash)" );
        }
        if ( ! in_array( 'char_count', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN char_count INT UNSIGNED DEFAULT 0 AFTER content_hash" );
        }
        if ( ! in_array( 'token_estimate', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN token_estimate INT UNSIGNED DEFAULT 0 AFTER char_count" );
        }
        if ( ! in_array( 'embedding_model', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN embedding_model VARCHAR(64) DEFAULT '' AFTER chunk_count" );
        }
        if ( ! in_array( 'error_message', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN error_message TEXT NULL AFTER embedding_status" );
        }
        if ( ! in_array( 'metadata', $cols_sources, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_sources} ADD COLUMN metadata LONGTEXT NULL AFTER error_message" );
        }
    }

    /**
     * Migration (v3.7.0): Add kci_ratio column to webchat_sessions table.
     * KCI Ratio = Knowledge ↔ Execution slider (0-100, default 80 = 80% knowledge).
     */
    public function maybe_upgrade_sessions() {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — retained compatibility entry point no longer inspects or alters session SQL.
        return true;
    }

    /**
     * Legacy conversation migration is intentionally disabled. New session
     * metadata is represented by a marker row in webchat_messages.
     */
    public function maybe_migrate_conversations_to_sessions() {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — retain the public compatibility method without reading or writing the retired conversation table.
        return 0;
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
        
        $insert_data = [
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
        ];
        // Insert message first (without todo_id to avoid column-missing failures)
        $inserted = $wpdb->insert($table, $insert_data);
        
        if ( $inserted === false ) {
            error_log( '[WebChat DB] log_message INSERT FAILED: ' . $wpdb->last_error );
            error_log( '[WebChat DB] Table: ' . $table . ' | session_id: ' . $session_id . ' | blog_id: ' . get_current_blog_id() );
            return false; // Signal failure to callers
        }
        
        $msg_row_id = $wpdb->insert_id;
        
        // Update todo_id separately (safe — only if column exists and value provided)
        if ( ! empty( $data['todo_id'] ) && $msg_row_id > 0 ) {
            $wpdb->update( $table, [ 'todo_id' => intval( $data['todo_id'] ) ], [ 'id' => $msg_row_id ] );
        }

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
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — message writes update session counters and title through the filestore owner.
        if ( class_exists( 'BizCity_WebChat_Session_State' ) ) {
            BizCity_WebChat_Session_State::instance()->update_message_stats( $session_id, $data );
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — use a canonical marker row in webchat_messages instead of the retired conversation table.
        return $this->get_or_create_conversation_marker( $session_id, $data );
    }
    
    /**
     * Get conversation history
     */
    // [2026-08-02 Johnny Chu] PHASE-TWIN-SURFACE-ISOLATION — keep consumer WebChat history separate from TwinChat/admin rows.
    public function get_conversation_history($session_id, $limit = 50, $offset = 0, $platform_type = 'WEBCHAT') {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $offset = max( 0, (int) $offset );
        $platform_type = strtoupper( (string) $platform_type );
        if ( $platform_type === '' ) {
            $platform_type = 'WEBCHAT';
        }
        
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_id = %s AND platform_type = %s AND message_type != 'conversation_meta' ORDER BY id ASC LIMIT %d, %d",
            $session_id,
            $platform_type,
            $offset,
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — task state is owned by Goal/Event Stream; preserve the legacy API without SQL fallback.
        return '';
    }
    
    /**
     * Update task
     */
    public function update_task($task_id, $data) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — retired task projection updates are refused before any SQL.
        return false;
    }
    
    /**
     * Complete task
     */
    public function complete_task($task_id, $status = 'completed') {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — task completion is recorded by the canonical event owner, not this retired projection.
        return false;
    }
    
    /**
     * Add task step
     */
    public function add_task_step($data) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — task-step events belong to the canonical timeline owner; no legacy row is created.
        return '';
    }
    
    /**
     * Complete task step
     */
    public function complete_task_step($step_id, $output_data = null, $status = 'completed') {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — retired task-step updates are refused before any SQL.
        return false;
    }
    
    /**
     * Get task with steps (for timeline)
     */
    public function get_task_timeline($task_id) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — legacy task timelines degrade until the canonical Event Stream reader is wired.
        return null;
    }
    
    /**
     * Get recent tasks for session
     */
    public function get_session_tasks($session_id, $limit = 10) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — session task reads return an empty compatibility result without touching SQL.
        return array();
    }
    
    /**
     * Get recent tasks (all sessions)
     */
    public function get_recent_tasks($limit = 20) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — recent task reads return an empty compatibility result without touching SQL.
        return array();
    }
    
    /**
     * Build a message-owned conversation marker and return its message ID.
     */
    private function get_or_create_conversation_marker( $session_id, $data = [] ) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — create one deterministic metadata marker per tenant, platform, and session.
        global $wpdb;
        $session_id = sanitize_text_field( (string) $session_id );
        $platform_type = strtoupper( (string) ( $data['platform_type'] ?? 'WEBCHAT' ) );
        if ( $platform_type === '' ) {
            $platform_type = 'WEBCHAT';
        }
        $message_id = 'conversation_meta_' . substr( hash( 'sha256', get_current_blog_id() . '|' . $platform_type . '|' . $session_id ), 0, 40 );
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $marker = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, meta FROM {$table} WHERE message_id = %s AND message_type = 'conversation_meta' LIMIT 1",
            $message_id
        ) );
        if ( $marker ) {
            // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — reopen a closed marker when a new message starts the same stable session again.
            $marker_meta = json_decode( (string) $marker->meta, true );
            if ( is_array( $marker_meta ) && ( $marker_meta['status'] ?? 'active' ) !== 'active' ) {
                $this->update_conversation_marker( (int) $marker->id, array( 'status' => 'active', 'ended_at' => null ) );
            }
            return (int) $marker->id;
        }
        $meta = array(
            'conversation_meta' => true,
            'title'             => sanitize_text_field( $data['title'] ?? '' ),
            'status'            => 'active',
            'ended_at'          => null,
        );
        $inserted = $wpdb->insert( $table, array(
            'conversation_id'     => 0,
            'session_id'          => $session_id,
            'user_id'             => (int) ( $data['user_id'] ?? get_current_user_id() ),
            'client_name'         => sanitize_text_field( $data['client_name'] ?? '' ),
            'message_id'          => $message_id,
            'message_text'        => '',
            'message_from'        => 'system',
            'message_type'        => 'conversation_meta',
            'is_context_included' => 0,
            'platform_type'       => $platform_type,
            'project_id'          => sanitize_text_field( $data['project_id'] ?? '' ),
            'status'              => 'visible',
            'meta'                => wp_json_encode( $meta ),
        ) );
        return false === $inserted ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Return one conversation view derived from message rows.
     */
    private function message_conversation_view( $session_id, $platform_type = '' ) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — derive the compatibility conversation object from canonical message rows.
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $platform_type = strtoupper( (string) $platform_type );
        if ( $platform_type === '' ) {
            $platform_type = strpos( (string) $session_id, 'adminchat_' ) === 0 ? 'ADMINCHAT' : 'WEBCHAT';
        }
        $summary = $wpdb->get_row( $wpdb->prepare(
            "SELECT MIN(id) AS first_id, MAX(user_id) AS user_id, MAX(client_name) AS client_name,
                    MAX(project_id) AS project_id, MIN(created_at) AS started_at,
                    MAX(created_at) AS last_message_at, SUM(message_type != 'conversation_meta') AS message_count,
                    MAX(CASE WHEN message_type = 'conversation_meta' THEN meta ELSE '' END) AS conversation_meta
             FROM {$table}
             WHERE session_id = %s AND platform_type = %s",
            $session_id,
            $platform_type
        ) );
        if ( ! $summary || (int) $summary->first_id <= 0 ) {
            return null;
        }
        $meta = json_decode( (string) $summary->conversation_meta, true );
        $meta = is_array( $meta ) ? $meta : array();
        $marker_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_id = %s AND platform_type = %s AND message_type = 'conversation_meta' ORDER BY id ASC LIMIT 1",
            $session_id,
            $platform_type
        ) );
        return (object) array(
            'id'              => $marker_id > 0 ? $marker_id : (int) $summary->first_id,
            'session_id'      => (string) $session_id,
            'user_id'         => (int) $summary->user_id,
            'client_name'     => (string) $summary->client_name,
            'title'           => (string) ( $meta['title'] ?? '' ),
            'platform_type'   => $platform_type,
            'status'          => (string) ( $meta['status'] ?? 'active' ),
            'project_id'      => (string) $summary->project_id,
            'started_at'      => (string) $summary->started_at,
            'last_message_at' => (string) $summary->last_message_at,
            'message_count'   => max( 0, (int) $summary->message_count ),
            'ended_at'        => isset( $meta['ended_at'] ) ? $meta['ended_at'] : null,
            'meta'            => $meta,
        );
    }

    /**
     * Update metadata stored in the message-owned conversation marker.
     */
    private function update_conversation_marker( $id, array $fields ) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — update only the marker metadata owned by webchat_messages.
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $marker = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, meta FROM {$table} WHERE id = %d AND message_type = 'conversation_meta' LIMIT 1",
            (int) $id
        ) );
        if ( ! $marker ) {
            return false;
        }
        $meta = json_decode( (string) $marker->meta, true );
        $meta = is_array( $meta ) ? $meta : array();
        foreach ( array( 'title', 'status', 'ended_at' ) as $key ) {
            if ( array_key_exists( $key, $fields ) ) {
                $meta[ $key ] = $fields[ $key ];
            }
        }
        $update = array( 'meta' => wp_json_encode( $meta ) );
        if ( array_key_exists( 'project_id', $fields ) ) {
            $update['project_id'] = sanitize_text_field( (string) $fields['project_id'] );
        }
        return false !== $wpdb->update( $table, $update, array( 'id' => (int) $id ) );
    }

    /**
     * Get conversation by session ID
     */
    public function get_conversation_by_session($session_id) {
        return $this->message_conversation_view( $session_id );
    }
    
    /**
     * Close conversation
     */
    public function close_conversation($session_id) {
        $conversation = $this->message_conversation_view( $session_id );
        $marker_id = $conversation ? (int) $conversation->id : $this->get_or_create_conversation_marker( $session_id );
        return $marker_id > 0 && $this->update_conversation_marker( $marker_id, array( 'status' => 'closed', 'ended_at' => current_time( 'mysql' ) ) );
    }
    
    /**
     * Count messages for session
     */
    public function count_messages($session_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND message_type != 'conversation_meta'",
            $session_id
        ));
    }
    
    /**
     * Get conversations list
     */
    public function get_conversations($status = 'active', $limit = 20, $offset = 0) {
        global $wpdb;
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — list grouped message sessions while preserving the legacy conversation object shape.
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $groups = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, platform_type, MAX(created_at) AS last_message_at
             FROM {$table}
             WHERE session_id <> ''
             GROUP BY session_id, platform_type
             ORDER BY last_message_at DESC
             LIMIT %d OFFSET %d",
            max( 1, (int) $limit * 3 ),
            max( 0, (int) $offset )
        ) );
        $conversations = array();
        foreach ( $groups as $group ) {
            $conversation = $this->message_conversation_view( $group->session_id, $group->platform_type );
            if ( $conversation && ( 'all' === $status || $conversation->status === $status ) ) {
                $conversations[] = $conversation;
                if ( count( $conversations ) >= (int) $limit ) {
                    break;
                }
            }
        }
        return $conversations;
    }
    
    /**
     * Count conversations
     */
    public function count_conversations($status = 'active') {
        global $wpdb;
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — count distinct tenant/platform/session groups from messages.
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $groups = $wpdb->get_results( "SELECT session_id, platform_type FROM {$table} WHERE session_id <> '' GROUP BY session_id, platform_type" );
        $count = 0;
        foreach ( $groups as $group ) {
            $conversation = $this->message_conversation_view( $group->session_id, $group->platform_type );
            if ( $conversation && ( 'all' === $status || $conversation->status === $status ) ) {
                $count++;
            }
        }
        return $count;
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — create legacy-compatible sessions as message-owned markers.

        // Use old format for ADMINCHAT: adminchat_{blogId}_{userId}
        // This ensures compatibility with intent system which looks up by session_id
        if ( $platform_type === 'ADMINCHAT' ) {
            $session_id = 'adminchat_' . get_current_blog_id() . '_' . (int) $user_id;
        } else {
            $uuid       = wp_generate_uuid4();
            $session_id = 'wcs_' . $uuid;
        }

        $id = $this->get_or_create_conversation_marker( $session_id, array(
            'user_id'       => (int) $user_id,
            'client_name'   => $client_name,
            'title'         => $title,
            'platform_type' => $platform_type,
        ) );

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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — persist title in the message-owned marker metadata.
        $session = $this->get_session( $id );
        if ( ! $session ) {
            return false;
        }
        $marker_id = $this->get_or_create_conversation_marker( $session->session_id, array(
            'user_id'       => $session->user_id,
            'client_name'   => $session->client_name,
            'platform_type' => $session->platform_type,
            'project_id'    => $session->project_id,
        ) );
        return $marker_id > 0 && $this->update_conversation_marker( $marker_id, array( 'title' => sanitize_text_field( $title ) ) );
    }

    /**
     * Update session project_id.
     *
     * @param int    $id         Primary key.
     * @param string $project_id Project UUID or '' to unassign.
     * @return bool
     */
    public function update_session_project( $id, $project_id ) {
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — persist project assignment on the message-owned marker.
        $session = $this->get_session( $id );
        if ( ! $session ) {
            return false;
        }
        $marker_id = $this->get_or_create_conversation_marker( $session->session_id, array(
            'user_id'       => $session->user_id,
            'client_name'   => $session->client_name,
            'platform_type' => $session->platform_type,
        ) );
        return $marker_id > 0 && $this->update_conversation_marker( $marker_id, array( 'project_id' => $project_id ) );
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — derive user sessions from message groups, never from webchat_conversations.
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $groups = $wpdb->get_results( $wpdb->prepare(
            "SELECT session_id, platform_type, MAX(created_at) AS last_message_at
             FROM {$table}
             WHERE user_id = %d AND session_id <> ''
             GROUP BY session_id, platform_type
             ORDER BY last_message_at DESC
             LIMIT %d",
            (int) $user_id,
            max( 1, (int) $limit * 3 )
        ) );
        $sessions = array();
        foreach ( $groups as $group ) {
            if ( $platform_type && strtoupper( $platform_type ) !== strtoupper( $group->platform_type ) ) {
                continue;
            }
            $session = $this->message_conversation_view( $group->session_id, $group->platform_type );
            if ( ! $session || 'active' !== $session->status ) {
                continue;
            }
            if ( $project_id !== null && (string) $session->project_id !== (string) $project_id ) {
                continue;
            }
            $sessions[] = $session;
            if ( count( $sessions ) >= (int) $limit ) {
                break;
            }
        }
        return $sessions;
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
            "SELECT * FROM {$table} WHERE conversation_id = %d AND message_type != 'conversation_meta' ORDER BY id ASC LIMIT %d",
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
            "SELECT *, UNIX_TIMESTAMP(created_at) AS created_ts FROM {$table} WHERE session_id = %s AND message_type != 'conversation_meta' ORDER BY id ASC LIMIT %d",
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
                 WHERE session_id = %s AND plugin_slug = %s AND message_type != 'conversation_meta'
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
                 WHERE intent_conversation_id = %s AND message_type != 'conversation_meta'
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
                 WHERE session_id = %s AND message_type != 'conversation_meta'
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — close the marker-backed session without touching the retired table.
        $session = $this->get_session( $id );
        if ( ! $session ) {
            return false;
        }
        $marker_id = $this->get_or_create_conversation_marker( $session->session_id, array(
            'user_id'       => $session->user_id,
            'client_name'   => $session->client_name,
            'platform_type' => $session->platform_type,
            'project_id'    => $session->project_id,
            'title'         => $session->title,
        ) );
        return $marker_id > 0 && $this->update_conversation_marker( $marker_id, array( 'status' => 'closed', 'ended_at' => current_time( 'mysql' ) ) );
    }

    /**
     * Delete a session and its messages.
     *
     * @param int $id  Primary key.
     * @return bool
     */
    public function delete_session( $id ) {
        global $wpdb;
        $tbl_msg  = $wpdb->prefix . 'bizcity_webchat_messages';

        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — delete the canonical message group addressed by its metadata marker.
        $marker = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_id, platform_type FROM {$tbl_msg} WHERE id = %d AND message_type = 'conversation_meta' LIMIT 1",
            (int) $id
        ) );
        if ( $marker ) {
            $deleted = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$tbl_msg} WHERE session_id = %s AND platform_type = %s",
                $marker->session_id,
                $marker->platform_type
            ) );
            return false !== $deleted;
        }
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$tbl_msg} WHERE conversation_id = %d",
            (int) $id
        ) );
        return false !== $deleted && $deleted > 0;
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — close user/platform marker rows one by one to preserve marker metadata.
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $markers = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND platform_type = %s AND message_type = 'conversation_meta'",
            (int) $user_id,
            strtoupper( $platform_type )
        ) );
        $closed = 0;
        foreach ( $markers as $marker ) {
            if ( $this->update_conversation_marker( $marker->id, array( 'status' => 'closed', 'ended_at' => current_time( 'mysql' ) ) ) ) {
                $closed++;
            }
        }
        return $closed;
    }

    /**
     * Get a single session by id.
     *
     * @param int $id Primary key.
     * @return object|null
     */
    public function get_session( $id ) {
        global $wpdb;
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — resolve a legacy numeric session ID through the message marker.
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $marker = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_id, platform_type FROM {$table} WHERE id = %d AND message_type = 'conversation_meta' LIMIT 1",
            (int) $id
        ) );
        if ( $marker ) {
            return $this->message_conversation_view( $marker->session_id, $marker->platform_type );
        }
        $legacy_message = $wpdb->get_row( $wpdb->prepare(
            "SELECT session_id, platform_type FROM {$table} WHERE conversation_id = %d ORDER BY id ASC LIMIT 1",
            (int) $id
        ) );
        return $legacy_message ? $this->message_conversation_view( $legacy_message->session_id, $legacy_message->platform_type ) : null;
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
            "SELECT message_text FROM {$table} WHERE conversation_id = %d AND message_from = 'user' AND message_type != 'conversation_meta' ORDER BY id ASC LIMIT 1",
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
        // [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-CONVERSATION-UNIFY — resolve session identity from canonical message rows.
        return $this->message_conversation_view( $session_id );
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
             WHERE conversation_id = %d AND message_type != 'conversation_meta'
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
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — retired project schema repair is disabled.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return;
        }
        static $done = false;
        if ( $done ) return;
        $done = true;

        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — do not read the retired project projection.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return null;
        }
        global $wpdb;

        // Fix projects table: project_id VARCHAR(36) -> 50
        $tbl_proj = $wpdb->prefix . 'bizcity_webchat_projects';
        $col = $wpdb->get_row( "SHOW COLUMNS FROM `{$tbl_proj}` WHERE Field = 'project_id'" );
        if ( $col && strpos( $col->Type, '36' ) !== false ) {
            $wpdb->query( "ALTER TABLE `{$tbl_proj}` MODIFY COLUMN project_id VARCHAR(50) NOT NULL" );
        }

        // Session project state is owned by the encrypted session-state store.
    }

    /**
     * Get a project by its id (PK).
     *
     * @param int $id  Primary key.
     * @return object|null
     */
    public function get_project( $id ) {
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — do not read the retired project projection.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return null;
        }
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
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — project lists are served by the canonical notebook repository.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return array();
        }
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
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — retired project updates are refused.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return false;
        }
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
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — retired project deletes are refused.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return false;
        }
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
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — do not maintain counters on the retired project projection.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return;
        }
        global $wpdb;
        $tbl_proj = $wpdb->prefix . 'bizcity_webchat_projects';
        // Get project_id for session cleanup
        $project = $this->get_project( $id );
        if ( $project ) {
            // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — project cleanup is delegated to the session-state owner.
            if ( class_exists( 'BizCity_WebChat_Session_State' ) ) {
                foreach ( BizCity_WebChat_Session_State::instance()->list_by_project( $project->project_id, 5000 ) as $session ) {
                    BizCity_WebChat_Session_State::instance()->update( $session->id, array( 'project_id' => '' ) );
                }
            }
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
        // [2026-09-01 Johnny Chu] PHASE-1.30-DEAD-SQL-COHORT — public project search has no legacy SQL owner.
        if ( ! self::table_exists_for_policy( 'bizcity_webchat_projects' ) ) {
            return array();
        }
        if ( empty($project_id) ) {
            return;
        }
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — project counts are derived from filestore state and are no longer mirrored into SQL.
        return;
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
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — create V3 sessions through the canonical filestore owner.
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->create( $user_id, $client_name, $platform_type, $title, $data )
            : array( 'id' => 0, 'session_id' => '', 'title' => $title );
    }

    /**
     * Get a session from webchat_sessions by id.
     *
     * @param int $id  Primary key.
     * @return object|null
     */
    public function get_session_v3( $id ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — read V3 session state from encrypted filestore.
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->get_by_id( $id )
            : null;
    }

    /**
     * Get a session from webchat_sessions by session_id.
     *
     * @param string $session_id  e.g. wcs_xxx or adminchat_xxx
     * @return object|null
     */
    public function get_session_v3_by_session_id( $session_id ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — read by stable session identity through the filestore owner.
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->get_by_session( $session_id )
            : null;
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
    public function get_sessions_v3_for_user( $user_id, $platform_type = null, $limit = 30, $project_id = null, $status = 'active' ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — list V3 sessions from folded encrypted records.
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->list_for_user( $user_id, $platform_type, $limit, $project_id, $status )
            : array();
    }

    /**
     * Get sessions by project_id.
     *
     * @param string $project_id  UUID.
     * @param int    $limit
     * @return array
     */
    public function get_sessions_by_project( $project_id, $limit = 50 ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — list project sessions through the filestore owner.
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->list_by_project( $project_id, $limit )
            : array();
    }

    /**
     * Update session in webchat_sessions.
     *
     * @param int   $id    Primary key.
     * @param array $data  Fields to update.
     * @return bool
     */
    public function update_session_v3( $id, $data ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — route all V3 session state mutations to encrypted filestore.
        if ( ! class_exists( 'BizCity_WebChat_Session_State' ) ) {
            return false;
        }
        return BizCity_WebChat_Session_State::instance()->update( $id, $data );
    }

    /**
     * Move session to a project.
     *
     * @param int    $id          Primary key.
     * @param string $project_id  Project UUID or '' to unassign.
     * @return bool
     */
    public function move_session_to_project( $id, $project_id ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — move project assignment in the canonical state record.
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->update( $id, array( 'project_id' => $project_id ) )
            : false;
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
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — update message counters in filestore after canonical message write.
        if ( class_exists( 'BizCity_WebChat_Session_State' ) ) {
            BizCity_WebChat_Session_State::instance()->update_message_stats( $session_id, array(
                'message_text' => $message_text,
                'message_count' => $message_count,
            ) );
        }
    }

    /**
     * Delete a session from webchat_sessions and its messages.
     *
     * @param int $id  Primary key.
     * @return bool
     */
    public function delete_session_v3( $id ) {
        // [2026-09-03 03:52 PM Johnny Chu - Chu Hoàng Anh] PHASE-1.30-SESSION-STATE-FILESTORE — tombstone state and delete canonical messages without session SQL.
        $session = $this->get_session_v3( $id );
        if ( ! $session ) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_webchat_messages';
        $wpdb->delete( $table, array( 'session_id' => $session->session_id, 'platform_type' => $session->platform_type ) );
        return class_exists( 'BizCity_WebChat_Session_State' )
            ? BizCity_WebChat_Session_State::instance()->delete( $id )
            : false;
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

// [2026-09-03 Johnny Chu - Chu Hoàng Anh] PHASE-1.30-WEBCHAT-DEAD-SQL — retired task/step/session-memory projections are intentionally absent from the schema registry.
