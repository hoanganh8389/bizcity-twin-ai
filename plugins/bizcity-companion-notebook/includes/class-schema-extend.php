<?php
defined( 'ABSPATH' ) || exit;

/**
 * Schema Extend — Adds 5 tables to webchat schema.
 * webchat_sources, webchat_source_extractor, webchat_source_chunks,
 * memory_notes, webchat_studio_outputs
 */
class BCN_Schema_Extend {
    const SCHEMA_VERSION = '5.1.0';
    const OPTION_KEY     = 'bcn_schema_version';

    public static function table_sources() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_sources';
    }

    public static function table_source_extractor() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_source_extractor';
    }

    public static function table_source_chunks() {
        global $wpdb;   
        return $wpdb->prefix . 'bizcity_webchat_source_chunks';
    }

    public static function table_notes() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_memory_notes';
    }

    public static function table_studio_outputs() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_studio_outputs';
    }

    public static function table_project_skeletons() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_webchat_project_skeletons';
    }

    public static function table_research_jobs() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_memory_research';
    }

    public static function maybe_upgrade() {
        $current = get_option( self::OPTION_KEY, '' );
        if ( $current !== self::SCHEMA_VERSION ) {
            self::extend_tables();
            self::migrate_v2();
            self::migrate_v3();
            self::migrate_v4();
            self::migrate_v5();
            update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
        }
    }

    /**
     * Migration for v5.1.0 — add FULLTEXT index on notes for keyword search.
     */
    private static function migrate_v5() {
        global $wpdb;
        $t = self::table_notes();
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) ) return;

        // Check if FULLTEXT index already exists.
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$t}` WHERE Key_name = 'ft_search'" );
        if ( empty( $indexes ) ) {
            $wpdb->query( "ALTER TABLE `{$t}` ADD FULLTEXT INDEX `ft_search` (`title`, `content`, `tags`)" );
        }
    }

    /**
     * Migration for v5.0.0 — add project_id column to webchat_messages.
     * Enables direct project-scoped queries without fragile session JOIN.
     */
    private static function migrate_v4() {
        global $wpdb;

        $t = $wpdb->prefix . 'bizcity_webchat_messages';

        // Guard: table may not exist yet (e.g. fresh install without webchat core).
        if ( ! $wpdb->get_var( "SHOW TABLES LIKE '{$t}'" ) ) {
            return;
        }

        $cols = $wpdb->get_col( "DESCRIBE {$t}", 0 );

        if ( ! in_array( 'project_id', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE `{$t}`
                ADD COLUMN `project_id` VARCHAR(50) NOT NULL DEFAULT '' AFTER `session_id`,
                ADD KEY `idx_notebook_project` (`project_id`, `platform_type`, `status`)" );
        }

        // Backfill: copy project_id from sessions for existing NOTEBOOK messages.
        $s = $wpdb->prefix . 'bizcity_webchat_sessions';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$s}'" ) ) {
            $wpdb->query( "UPDATE `{$t}` m
                INNER JOIN `{$s}` s ON m.session_id = s.session_id
                SET m.project_id = s.project_id
                WHERE m.platform_type = 'NOTEBOOK'
                  AND (m.project_id = '' OR m.project_id IS NULL)" );
        }
    }

    /**
     * Migration for v3.0.0 — add webchat_project_skeletons table.
     */
    private static function migrate_v3() {
        // Table is created by extend_tables() via dbDelta.
        // Additionally, clean up legacy wp_options skeleton cache.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bcn\_skeleton\_%'" );
    }

    /**
     * Migration for v2.0.0 — add new columns to existing tables.
     * dbDelta handles ADD COLUMN but we do ALTER for safety.
     */
    private static function migrate_v2() {
        global $wpdb;

        // Sources: add embedding_status, chunk_count, embedding_model
        $t1 = self::table_sources();
        $cols = $wpdb->get_col( "DESCRIBE {$t1}", 0 );

        if ( ! in_array( 'embedding_status', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$t1}
                ADD COLUMN embedding_status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER status,
                ADD COLUMN chunk_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER token_estimate,
                ADD COLUMN embedding_model VARCHAR(100) NOT NULL DEFAULT '' AFTER chunk_count,
                ADD KEY idx_embed_status (embedding_status)" );
        }

        // Notes: add source_excerpt, tags, created_by
        $t3 = self::table_notes();
        $cols3 = $wpdb->get_col( "DESCRIBE {$t3}", 0 );

        if ( ! in_array( 'tags', $cols3, true ) ) {
            $wpdb->query( "ALTER TABLE {$t3}
                ADD COLUMN source_excerpt TEXT AFTER content,
                ADD COLUMN tags VARCHAR(500) NOT NULL DEFAULT '[]' AFTER source_excerpt,
                ADD COLUMN created_by VARCHAR(10) NOT NULL DEFAULT 'user' AFTER tags" );
        }

        // Studio outputs: add input_snapshot, updated_at, version
        $t4 = self::table_studio_outputs();
        $cols4 = $wpdb->get_col( "DESCRIBE {$t4}", 0 );

        if ( ! in_array( 'input_snapshot', $cols4, true ) ) {
            $wpdb->query( "ALTER TABLE {$t4}
                ADD COLUMN input_snapshot LONGTEXT AFTER token_count,
                ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
                ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER error_message" );
        }
    }

    public static function extend_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── webchat_sources ──
        $t1 = self::table_sources();
        $sql1 = "CREATE TABLE {$t1} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            project_id VARCHAR(50) NOT NULL DEFAULT '',
            session_id VARCHAR(128) NOT NULL DEFAULT '',
            intent_conversation_id VARCHAR(64) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            source_type VARCHAR(30) NOT NULL DEFAULT 'text',
            source_url VARCHAR(1000) NOT NULL DEFAULT '',
            attachment_id BIGINT UNSIGNED DEFAULT NULL,
            content_text LONGTEXT,
            content_hash VARCHAR(64) NOT NULL DEFAULT '',
            char_count INT UNSIGNED NOT NULL DEFAULT 0,
            token_estimate INT UNSIGNED NOT NULL DEFAULT 0,
            chunk_count INT UNSIGNED NOT NULL DEFAULT 0,
            embedding_model VARCHAR(100) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'ready',
            embedding_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            error_message TEXT,
            metadata LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_project (project_id),
            KEY idx_user (user_id),
            KEY idx_session (session_id),
            KEY idx_hash (content_hash),
            KEY idx_intent (intent_conversation_id),
            KEY idx_type_status (source_type, status),
            KEY idx_embed_status (embedding_status)
        ) {$charset};";

        // ── webchat_source_extractor ──
        $t2 = self::table_source_extractor();
        $sql2 = "CREATE TABLE {$t2} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            extractor_type VARCHAR(30) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT,
            input_url VARCHAR(1000) NOT NULL DEFAULT '',
            output_chars INT UNSIGNED NOT NULL DEFAULT 0,
            processing_time_ms INT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_status (status),
            KEY idx_retry (status, attempt_count)
        ) {$charset};";

        // ── webchat_source_chunks (NEW v2.0) ──
        $t2b = self::table_source_chunks();
        $sql2b = "CREATE TABLE {$t2b} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            project_id VARCHAR(50) NOT NULL DEFAULT '',
            chunk_index INT UNSIGNED NOT NULL DEFAULT 0,
            content TEXT NOT NULL,
            token_count INT UNSIGNED NOT NULL DEFAULT 0,
            embedding LONGTEXT,
            embedding_model VARCHAR(100) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_source (source_id),
            KEY idx_project (project_id),
            KEY idx_source_index (source_id, chunk_index)
        ) {$charset};";

        // ── memory_notes ──
        $t3 = self::table_notes();
        $sql3 = "CREATE TABLE {$t3} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            project_id VARCHAR(50) NOT NULL DEFAULT '',
            session_id VARCHAR(128) NOT NULL DEFAULT '',
            message_id BIGINT UNSIGNED DEFAULT NULL,
            title VARCHAR(255) NOT NULL DEFAULT '',
            content LONGTEXT,
            source_excerpt TEXT,
            tags VARCHAR(500) NOT NULL DEFAULT '[]',
            created_by VARCHAR(10) NOT NULL DEFAULT 'user',
            note_type VARCHAR(30) NOT NULL DEFAULT 'manual',
            is_starred TINYINT(1) NOT NULL DEFAULT 0,
            metadata LONGTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_project (project_id),
            KEY idx_user (user_id),
            KEY idx_session (session_id),
            KEY idx_message (message_id),
            KEY idx_type (note_type)
        ) {$charset};";

        // ── webchat_studio_outputs ──
        $t4 = self::table_studio_outputs();
        $sql4 = "CREATE TABLE {$t4} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            project_id VARCHAR(50) NOT NULL DEFAULT '',
            tool_type VARCHAR(30) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            content LONGTEXT,
            content_format VARCHAR(20) NOT NULL DEFAULT 'json',
            source_count INT UNSIGNED NOT NULL DEFAULT 0,
            note_count INT UNSIGNED NOT NULL DEFAULT 0,
            token_count INT UNSIGNED NOT NULL DEFAULT 0,
            input_snapshot LONGTEXT,
            external_post_id BIGINT UNSIGNED DEFAULT NULL,
            external_url VARCHAR(500) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'ready',
            error_message TEXT,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_project_tool (project_id, tool_type),
            KEY idx_user (user_id),
            KEY idx_post (external_post_id)
        ) {$charset};";

        // ── webchat_project_skeletons ──
        $t5 = self::table_project_skeletons();
        $sql5 = "CREATE TABLE {$t5} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id VARCHAR(50) NOT NULL DEFAULT '',
            skeleton_json LONGTEXT NOT NULL,
            note_count INT UNSIGNED NOT NULL DEFAULT 0,
            source_count INT UNSIGNED NOT NULL DEFAULT 0,
            version VARCHAR(10) NOT NULL DEFAULT '1.0',
            status VARCHAR(20) NOT NULL DEFAULT 'ready',
            generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_project (project_id),
            KEY idx_status (status)
        ) {$charset};";

        dbDelta( $sql1 );
        dbDelta( $sql2 );
        dbDelta( $sql2b );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
        dbDelta( $sql5 );

        // ── memory_research ──
        $t6 = self::table_research_jobs();
        $sql6 = "CREATE TABLE {$t6} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id VARCHAR(50) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            query TEXT NOT NULL,
            status ENUM('pending','running','completed','failed') NOT NULL DEFAULT 'pending',
            total_urls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            processed_urls SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            result_json LONGTEXT,
            error_message TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_project (project_id),
            KEY idx_status (status),
            KEY idx_user (user_id)
        ) {$charset};";
        dbDelta( $sql6 );
    }

    public static function drop_extended_tables() {
        global $wpdb;
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_source_chunks() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_sources() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_source_extractor() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_notes() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_studio_outputs() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_project_skeletons() );
        $wpdb->query( "DROP TABLE IF EXISTS " . self::table_research_jobs() );
        delete_option( self::OPTION_KEY );
    }
}
