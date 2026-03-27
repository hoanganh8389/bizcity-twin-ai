<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Database Manager — Tables & migrations for Knowledge Module
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Tables: characters, knowledge_sources, knowledge_chunks, character_intents, user_memory
 */
defined('ABSPATH') or die('OOPS...');

class BizCity_Knowledge_Database {
    
    private static $instance = null;
    private static $tables_created = false;
    
    /**
     * Database schema version
     */
    const SCHEMA_VERSION = '3.0.0';
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            // Auto-create tables if needed (for mu-plugins)
            self::maybe_create_tables();
        }
        return self::$instance;
    }
    
    /**
     * Check and create tables if version mismatch
     */
    public static function maybe_create_tables() {
        // Prevent multiple runs per request
        if (self::$tables_created) {
            return;
        }
        self::$tables_created = true;
        
        $current_version = get_option('bizcity_knowledge_db_version', '');
        
        // Create/update tables if version mismatch
        if ($current_version !== self::SCHEMA_VERSION) {
            (new self())->create_tables();
        }
        
        // Always check for legacy migration (independent of table version)
        // This ensures migration runs on each site in multisite environment
        (new self())->maybe_migrate_legacy_knowledge();
    }
    
    /**
     * Create database tables
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        // Characters table
        $table_characters = $wpdb->prefix . 'bizcity_characters';
        $sql_characters = "CREATE TABLE IF NOT EXISTS {$table_characters} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(255) NOT NULL,
            avatar VARCHAR(500) DEFAULT '',
            description TEXT,
            system_prompt TEXT,
            model_id VARCHAR(255) DEFAULT '' COMMENT 'OpenRouter model ID',
            creativity_level DECIMAL(3,2) DEFAULT 0.70 COMMENT 'Temperature 0-1',
            greeting_messages TEXT COMMENT 'JSON array of greeting messages',
            capabilities TEXT COMMENT 'JSON array of capabilities',
            industries TEXT COMMENT 'JSON array of industry tags',
            variables_schema TEXT COMMENT 'JSON schema for output variables',
            settings TEXT COMMENT 'JSON settings',
            status ENUM('draft', 'active', 'published', 'archived') DEFAULT 'draft',
            author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            market_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'ID in bizcity-agent-market',
            total_conversations BIGINT UNSIGNED DEFAULT 0,
            total_messages BIGINT UNSIGNED DEFAULT 0,
            rating DECIMAL(3,2) DEFAULT 0.00,
            owner_type VARCHAR(20) DEFAULT 'standalone' COMMENT 'standalone | provider',
            owner_id VARCHAR(50) DEFAULT '' COMMENT 'provider slug when owner_type=provider',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY author_id (author_id),
            KEY status (status),
            KEY market_id (market_id),
            KEY owner_type_id (owner_type, owner_id)
        ) {$charset_collate};";
        
        dbDelta($sql_characters);
        
        // Knowledge Sources table
        $table_sources = $wpdb->prefix . 'bizcity_knowledge_sources';
        $sql_sources = "CREATE TABLE IF NOT EXISTS {$table_sources} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            character_id BIGINT UNSIGNED NOT NULL,
            source_type ENUM('quick_faq', 'file', 'url', 'fanpage', 'manual') NOT NULL,
            source_name VARCHAR(255) NOT NULL,
            source_url VARCHAR(500) DEFAULT '',
            attachment_id BIGINT UNSIGNED DEFAULT NULL,
            post_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'quick_faq post ID',
            content LONGTEXT,
            content_hash VARCHAR(64) DEFAULT '' COMMENT 'MD5 hash for change detection',
            chunks_count INT UNSIGNED DEFAULT 0,
            status ENUM('pending', 'processing', 'ready', 'error') DEFAULT 'pending',
            error_message TEXT,
            last_synced_at DATETIME DEFAULT NULL,
            settings TEXT COMMENT 'JSON settings',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY character_id (character_id),
            KEY source_type (source_type),
            KEY post_id (post_id),
            KEY status (status)
        ) {$charset_collate};";
        
        dbDelta($sql_sources);
        
        // Knowledge Chunks table (for vector/semantic search)
        $table_chunks = $wpdb->prefix . 'bizcity_knowledge_chunks';
        $sql_chunks = "CREATE TABLE IF NOT EXISTS {$table_chunks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT UNSIGNED NOT NULL,
            character_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED DEFAULT 0,
            content TEXT NOT NULL,
            content_hash VARCHAR(64) DEFAULT '',
            token_count INT UNSIGNED DEFAULT 0,
            embedding LONGTEXT COMMENT 'JSON array of embedding vector',
            metadata TEXT COMMENT 'JSON metadata',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY source_id (source_id),
            KEY character_id (character_id),
            KEY content_hash (content_hash)
        ) {$charset_collate};";
        
        dbDelta($sql_chunks);
        
        // Character Intents table
        $table_intents = $wpdb->prefix . 'bizcity_character_intents';
        $sql_intents = "CREATE TABLE IF NOT EXISTS {$table_intents} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            character_id BIGINT UNSIGNED NOT NULL,
            intent_name VARCHAR(100) NOT NULL,
            intent_description VARCHAR(500) DEFAULT '',
            keywords TEXT COMMENT 'JSON array of keywords',
            examples TEXT COMMENT 'JSON array of example phrases',
            output_variables TEXT COMMENT 'JSON schema for variables to extract',
            action_hook VARCHAR(255) DEFAULT '' COMMENT 'Hook to fire when intent matched',
            priority INT DEFAULT 10,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY character_id (character_id),
            KEY intent_name (intent_name),
            KEY is_active (is_active)
        ) {$charset_collate};";
        
        dbDelta($sql_intents);
        
        // Character Conversations log
        $table_conversations = $wpdb->prefix . 'bizcity_character_conversations';
        $sql_conversations = "CREATE TABLE IF NOT EXISTS {$table_conversations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            character_id BIGINT UNSIGNED NOT NULL,
            session_id VARCHAR(191) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            platform VARCHAR(50) DEFAULT 'webchat',
            message_count INT UNSIGNED DEFAULT 0,
            last_intent VARCHAR(100) DEFAULT '',
            extracted_variables TEXT COMMENT 'JSON of extracted variables',
            satisfaction_rating TINYINT UNSIGNED DEFAULT NULL,
            status ENUM('active', 'closed', 'escalated') DEFAULT 'active',
            started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            ended_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY character_id (character_id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY platform (platform)
        ) {$charset_collate};";
        
        dbDelta($sql_conversations);
        
        // User Memory table — 2-tier: LLM-extracted + user-explicit
        $table_memory = $wpdb->prefix . 'bizcity_memory_users';
        $sql_memory = "CREATE TABLE IF NOT EXISTS {$table_memory} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            blog_id INT UNSIGNED NOT NULL DEFAULT 1,
            user_id BIGINT UNSIGNED DEFAULT 0 COMMENT 'WP user ID (0 = guest)',
            session_id VARCHAR(191) DEFAULT '' COMMENT 'For guest identification',
            memory_tier ENUM('extracted','explicit') NOT NULL DEFAULT 'extracted' COMMENT 'extracted=LLM-analyzed from chat, explicit=user asked to remember',
            memory_type VARCHAR(50) NOT NULL DEFAULT 'fact' COMMENT 'identity|preference|goal|pain|constraint|habit|relationship|fact|request',
            memory_key VARCHAR(191) NOT NULL DEFAULT '' COMMENT 'Slug key e.g. likes:milk_tea',
            memory_text TEXT NOT NULL COMMENT 'Human-readable memory text',
            score TINYINT UNSIGNED DEFAULT 50 COMMENT 'Importance 0-100',
            times_seen INT UNSIGNED DEFAULT 1 COMMENT 'How many times reinforced',
            source_log_ids VARCHAR(500) DEFAULT '' COMMENT 'Comma-separated message IDs that sourced this',
            metadata TEXT COMMENT 'JSON extra data',
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY blog_user (blog_id, user_id),
            KEY blog_session (blog_id, session_id),
            KEY memory_tier (memory_tier),
            KEY memory_type (memory_type),
            UNIQUE KEY unique_memory (blog_id, user_id, session_id, memory_key)
        ) {$charset_collate};";

        dbDelta($sql_memory);

        // Migration: Add new columns for version 1.0.2+
        // Always run this migration to ensure columns exist (force run)
        $this->run_migration_v1_0_3($table_characters);

        // Migration: Add owner_type, owner_id for provider-bound characters (v2.1.0)
        $this->run_migration_v2_1_0($table_characters);

        // Migration v3.0.0: Knowledge Fabric — scope columns on sources + chunks
        $this->run_migration_v3_0_0();
        
        // Update version option
        update_option('bizcity_knowledge_db_version', self::SCHEMA_VERSION);
    }
    
    /**
     * Migration v1.0.3 - Ensure model_id, creativity_level, greeting_messages columns exist
     */
    private function run_migration_v1_0_3($table_characters) {
        global $wpdb;
        
        // Get all existing columns
        $existing_columns = [];
        $columns_result = $wpdb->get_results("SHOW COLUMNS FROM {$table_characters}");
        foreach ($columns_result as $col) {
            $existing_columns[] = $col->Field;
        }
        
        // Add model_id if not exists
        if (!in_array('model_id', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE {$table_characters} 
                ADD COLUMN model_id VARCHAR(255) DEFAULT '' COMMENT 'OpenRouter model ID' AFTER system_prompt");
            if ($result === false) {
                error_log("BizCity Knowledge: Failed to add model_id column - " . $wpdb->last_error);
            }
        }
        
        // Add creativity_level if not exists
        if (!in_array('creativity_level', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE {$table_characters} 
                ADD COLUMN creativity_level DECIMAL(3,2) DEFAULT 0.70 COMMENT 'Temperature 0-1' AFTER model_id");
            if ($result === false) {
                error_log("BizCity Knowledge: Failed to add creativity_level column - " . $wpdb->last_error);
            }
        }
        
        // Add greeting_messages if not exists
        if (!in_array('greeting_messages', $existing_columns)) {
            $result = $wpdb->query("ALTER TABLE {$table_characters} 
                ADD COLUMN greeting_messages TEXT COMMENT 'JSON array of greeting messages' AFTER creativity_level");
            if ($result === false) {
                error_log("BizCity Knowledge: Failed to add greeting_messages column - " . $wpdb->last_error);
            }
        }
    }

    /**
     * Migration v2.1.0 — Add owner_type + owner_id columns to characters table.
     *
     * This enables "Provider Characters" — each plugin agent can have its own
     * Character that serves as a knowledge container for domain-specific RAG.
     *
     * owner_type values:
     *   'standalone' — regular character (assigned to chat sessions, has personality)
     *   'provider'   — provider-bound character (knowledge container for a plugin agent)
     *
     * @param string $table_characters Table name.
     */
    private function run_migration_v2_1_0( $table_characters ) {
        global $wpdb;

        $existing_columns = [];
        $columns_result   = $wpdb->get_results( "SHOW COLUMNS FROM {$table_characters}" );
        foreach ( $columns_result as $col ) {
            $existing_columns[] = $col->Field;
        }

        if ( ! in_array( 'owner_type', $existing_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_characters}
                ADD COLUMN owner_type VARCHAR(20) DEFAULT 'standalone' COMMENT 'standalone | provider' AFTER rating" );
        }

        if ( ! in_array( 'owner_id', $existing_columns, true ) ) {
            $wpdb->query( "ALTER TABLE {$table_characters}
                ADD COLUMN owner_id VARCHAR(50) DEFAULT '' COMMENT 'provider slug when owner_type=provider' AFTER owner_type" );
            $wpdb->query( "ALTER TABLE {$table_characters}
                ADD INDEX idx_owner (owner_type, owner_id)" );
        }
    }

    /**
     * Migration v3.0.0 — Knowledge Fabric: Add scope columns to sources + chunks.
     *
     * Adds user_id, scope, project_id, session_id columns to enable
     * multi-scope knowledge ownership (user / project / session / agent).
     *
     * Existing data is backfilled as scope='agent', user_id=0 (system/admin).
     *
     * @since 3.0.0
     */
    private function run_migration_v3_0_0() {
        global $wpdb;

        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
        $chunks_table  = $wpdb->prefix . 'bizcity_knowledge_chunks';

        // ── Sources table ──
        $src_cols = wp_list_pluck(
            $wpdb->get_results( "SHOW COLUMNS FROM {$sources_table}" ),
            'Field'
        );

        if ( ! in_array( 'scope', $src_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$sources_table}
                ADD COLUMN user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Owner user ID (0=system/agent)' AFTER character_id,
                ADD COLUMN scope      VARCHAR(20) NOT NULL DEFAULT 'agent' COMMENT 'user|project|session|agent' AFTER user_id,
                ADD COLUMN project_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'Project ID for project scope' AFTER scope,
                ADD COLUMN session_id VARCHAR(191) DEFAULT '' COMMENT 'Session ID for session scope' AFTER project_id" );

            $wpdb->query( "ALTER TABLE {$sources_table}
                ADD INDEX idx_user_scope (user_id, scope),
                ADD INDEX idx_project    (project_id),
                ADD INDEX idx_session    (session_id)" );

            // Backfill: all existing data = scope 'agent'
            $wpdb->query( "UPDATE {$sources_table} SET scope = 'agent', user_id = 0 WHERE scope = 'agent'" );

            error_log( 'BizCity Knowledge v3.0.0: Added scope columns to sources table' );
        }

        // ── Chunks table ──
        $chk_cols = wp_list_pluck(
            $wpdb->get_results( "SHOW COLUMNS FROM {$chunks_table}" ),
            'Field'
        );

        if ( ! in_array( 'scope', $chk_cols, true ) ) {
            $wpdb->query( "ALTER TABLE {$chunks_table}
                ADD COLUMN user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER character_id,
                ADD COLUMN scope      VARCHAR(20) NOT NULL DEFAULT 'agent' AFTER user_id,
                ADD COLUMN project_id BIGINT UNSIGNED DEFAULT NULL AFTER scope,
                ADD COLUMN session_id VARCHAR(191) DEFAULT '' AFTER project_id" );

            $wpdb->query( "ALTER TABLE {$chunks_table}
                ADD INDEX idx_user_scope (user_id, scope),
                ADD INDEX idx_project    (project_id),
                ADD INDEX idx_session    (session_id)" );

            // Backfill
            $wpdb->query( "UPDATE {$chunks_table} SET scope = 'agent', user_id = 0 WHERE scope = 'agent'" );

            error_log( 'BizCity Knowledge v3.0.0: Added scope columns to chunks table' );
        }
    }

    /* ================================================================
     *  Provider Character helpers
     * ================================================================ */

    /**
     * Get a provider-bound character by provider ID (slug).
     *
     * @param string $provider_id  Provider slug, e.g. 'tarot', 'bizcoach'.
     * @return object|null
     */
    public function get_provider_character( $provider_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE owner_type = 'provider' AND owner_id = %s LIMIT 1",
            $provider_id
        ) );
    }

    /**
     * Get or create a provider-bound character.
     *
     * If the provider doesn't have a character yet, one is created with sensible
     * defaults. The character serves as a knowledge container — its system_prompt
     * can be left empty (personality comes from the session character instead).
     *
     * @param string $provider_id   Provider slug, e.g. 'tarot'.
     * @param string $provider_name Human-readable name, e.g. 'Tarot Reading'.
     * @return int  Character ID.
     */
    public function get_or_create_provider_character( $provider_id, $provider_name = '' ) {
        $existing = $this->get_provider_character( $provider_id );
        if ( $existing ) {
            return (int) $existing->id;
        }

        if ( empty( $provider_name ) ) {
            $provider_name = ucfirst( $provider_id ) . ' Agent';
        }

        $slug = 'provider-' . sanitize_title( $provider_id );
        $char_id = $this->create_character( [
            'name'        => "📚 {$provider_name} Knowledge",
            'slug'        => $slug,
            'description' => "Knowledge container for {$provider_name} plugin agent. Managed automatically.",
            'status'      => 'active',
            'owner_type'  => 'provider',
            'owner_id'    => $provider_id,
        ] );

        return is_wp_error( $char_id ) ? 0 : (int) $char_id;
    }
    
    /**
     * Legacy Knowledge Migration
     * Migrate from old quick_faq post type to new character-based knowledge system
     */
    public function maybe_migrate_legacy_knowledge() {
        // Check if migration already done
        $migration_done = get_option('bizcity_knowledge_legacy_migration_done', false);
        
        if ($migration_done) {
            return; // Already migrated
        }
        
        // Check if character id = 1 exists
        $default_character = $this->get_character(1);
        
        // Check if quick_faq post type exists
        $has_legacy_faq = $this->check_legacy_faq_exists();
        
        // If no character id=1 or has legacy FAQs, run migration
        if (!$default_character || $has_legacy_faq) {
            $this->run_legacy_migration($has_legacy_faq);
            
            // Mark migration as done
            update_option('bizcity_knowledge_legacy_migration_done', true);
            
            error_log('BizCity Knowledge: Legacy migration completed');
        }
    }
    
    /**
     * Check if legacy quick_faq post type exists with data
     */
    private function check_legacy_faq_exists() {
        global $wpdb;
        
        // Check if post type quick_faq exists
        $count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} 
             WHERE post_type = 'quick_faq' 
             AND post_status IN ('publish', 'draft')"
        );
        
        return $count > 0;
    }
    
    /**
     * Run the legacy migration process
     */
    private function run_legacy_migration($has_legacy_faq) {
        // Step 1: Create default character
        $character_id = $this->create_default_character();
        
        if (is_wp_error($character_id)) {
            error_log('BizCity Knowledge: Failed to create default character - ' . $character_id->get_error_message());
            return;
        }
        
        // Step 2: Migrate legacy FAQ data if exists
        if ($has_legacy_faq) {
            $migrated_count = $this->migrate_quick_faq_to_knowledge($character_id);
            error_log("BizCity Knowledge: Migrated {$migrated_count} legacy FAQ entries");
        } else {
            // Step 3: Create default knowledge entries
            $this->create_default_knowledge_entries($character_id);
            error_log('BizCity Knowledge: Created default knowledge entries');
        }
    }
    
    /**
     * Create default character based on website info
     */
    private function create_default_character() {
        global $wpdb;
        
        // Get website info
        $site_name = get_option('blogname', 'Website');
        $site_description = get_option('blogdescription', 'Trợ lý ảo hỗ trợ khách hàng');
        $admin_email = get_option('admin_email', '');
        
        // Check if character id=1 already exists
        $existing = $this->get_character(1);
        if ($existing) {
            return 1; // Return existing character id
        }
        
        // Create character data
        $character_data = [
            'name' => 'Trợ lý ' . $site_name,
            'slug' => sanitize_title('tro-ly-' . $site_name),
            'avatar' => '',
            'description' => 'Trợ lý ảo thông minh hỗ trợ khách hàng 24/7 cho ' . $site_name . '. ' . $site_description,
            'system_prompt' => "Bạn là trợ lý ảo thông minh của {$site_name}. 

Nhiệm vụ chính của bạn:
- Hỗ trợ khách hàng một cách chuyên nghiệp và thân thiện
- Cung cấp thông tin chính xác về sản phẩm, dịch vụ
- Giải đáp thắc mắc nhanh chóng
- Hướng dẫn khách hàng thực hiện các yêu cầu

Phong cách giao tiếp:
- Thân thiện, lịch sự, chuyên nghiệp
- Ngắn gọn, súc tích nhưng đầy đủ thông tin
- Sử dụng tiếng Việt chuẩn
- Luôn đặt khách hàng lên hàng đầu",
            'model_id' => '', // Will use default from settings
            'creativity_level' => 0.70,
            'greeting_messages' => json_encode([
                'Xin chào! Tôi là trợ lý ảo của ' . $site_name . '. Tôi có thể giúp gì cho bạn?',
                'Chào bạn! Bạn cần hỗ trợ thông tin gì về ' . $site_name . '?',
                'Xin chào! Rất vui được hỗ trợ bạn. Bạn muốn tìm hiểu về điều gì?',
            ]),
            'capabilities' => json_encode([
                'Tư vấn sản phẩm/dịch vụ',
                'Giải đáp thắc mắc',
                'Hướng dẫn sử dụng',
                'Hỗ trợ liên hệ',
            ]),
            'industries' => json_encode(['Tổng hợp']),
            'variables_schema' => json_encode([]),
            'settings' => json_encode([
                'auto_greeting' => true,
                'show_suggestions' => true,
            ]),
            'status' => 'active',
            'author_id' => 1,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];
        
        // Insert with specific ID = 1
        $table = $wpdb->prefix . 'bizcity_characters';
        $wpdb->insert($table, array_merge(['id' => 1], $character_data));
        
        if ($wpdb->insert_id) {
            return $wpdb->insert_id;
        }
        
        return new WP_Error('character_creation_failed', 'Không thể tạo character mặc định');
    }
    
    /**
     * Migrate quick_faq posts to knowledge sources
     */
    private function migrate_quick_faq_to_knowledge($character_id) {
        global $wpdb;
        
        // Get all quick_faq posts
        $faqs = $wpdb->get_results(
            "SELECT ID, post_title, post_content, post_date 
             FROM {$wpdb->posts} 
             WHERE post_type = 'quick_faq' 
             AND post_status IN ('publish', 'draft')
             ORDER BY post_date ASC"
        );
        
        if (empty($faqs)) {
            return 0;
        }
        
        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
        $migrated_count = 0;
        
        foreach ($faqs as $faq) {
            // Get meta data
            $link_faq = get_post_meta($faq->ID, '_link_faq', true);
            $action_faq = get_post_meta($faq->ID, '_action_faq', true);
            
            // Build content
            $content = $faq->post_content;
            
            if (!empty($action_faq)) {
                $content .= "\n\nHành động: " . $action_faq;
            }
            
            if (!empty($link_faq)) {
                $content .= "\n\nXem thêm: " . $link_faq;
            }
            
            // Insert as knowledge source
            $result = $wpdb->insert($sources_table, [
                'character_id' => $character_id,
                'source_type' => 'quick_faq',
                'source_name' => $faq->post_title,
                'content' => $content,
                'source_url' => $link_faq ?: '',
                'post_id' => $faq->ID,
                'status' => 'ready',
                'settings' => json_encode([
                    'migrated_from' => 'quick_faq',
                    'original_id' => $faq->ID,
                ]),
                'created_at' => $faq->post_date,
                'updated_at' => current_time('mysql'),
            ]);
            
            if ($result) {
                $migrated_count++;
            }
        }
        
        return $migrated_count;
    }
    
    /**
     * Create default knowledge entries for new installations
     */
    private function create_default_knowledge_entries($character_id) {
        global $wpdb;
        
        // Get site info
        $site_name = get_option('blogname', 'Website');
        $admin_email = get_option('admin_email', '');
        $site_url = get_option('siteurl', '');
        
        $default_knowledge = [
            [
                'title' => 'Thông tin chủ sở hữu website',
                'content' => "Website {$site_name} được quản lý bởi đội ngũ chuyên nghiệp. Để liên hệ với chủ sở hữu, vui lòng sử dụng thông tin liên hệ được cung cấp trên website.",
            ],
            [
                'title' => 'Liên hệ và hỗ trợ',
                'content' => "Để được hỗ trợ tốt nhất, bạn có thể:\n- Email: {$admin_email}\n- Truy cập trang liên hệ trên website\n- Sử dụng form liên hệ",
            ],
            [
                'title' => 'Cách thức liên hệ với chủ sở hữu',
                'content' => "Nếu bạn cần gặp trực tiếp chủ sở hữu hoặc quản trị viên, vui lòng:\n1. Gửi email tới: {$admin_email}\n2. Ghi rõ tiêu đề và nội dung cần trao đổi\n3. Để lại số điện thoại liên hệ (nếu cần)\n\nĐội ngũ hỗ trợ sẽ phản hồi trong thời gian sớm nhất.",
            ],
            [
                'title' => 'Giờ làm việc và thời gian phản hồi',
                'content' => "Thời gian làm việc: Thứ 2 - Thứ 6, 8:00 - 17:00\nThời gian phản hồi email: Trong vòng 24 giờ làm việc\nHỗ trợ trực tuyến: 24/7 qua chatbot",
            ],
            [
                'title' => 'Thông tin website',
                'content' => "Website: {$site_url}\nTên: {$site_name}\nEmail liên hệ: {$admin_email}",
            ],
        ];
        
        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
        
        foreach ($default_knowledge as $knowledge) {
            $wpdb->insert($sources_table, [
                'character_id' => $character_id,
                'source_type' => 'manual',
                'source_name' => $knowledge['title'],
                'content' => $knowledge['content'],
                'source_url' => '',
                'status' => 'ready',
                'settings' => json_encode([
                    'default_knowledge' => true,
                ]),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
        }
    }
    
    /**
     * Get character by ID (cached)
     */
    public function get_character($id) {
        $cache_key = 'bk_char_' . intval($id);
        $cached = wp_cache_get( $cache_key, 'bizcity_characters' );
        if ( false !== $cached ) {
            return $cached;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
        wp_cache_set( $cache_key, $result, 'bizcity_characters', 1800 );
        return $result;
    }
    
    /**
     * Get character by slug (cached)
     */
    public function get_character_by_slug($slug) {
        $cache_key = 'bk_char_slug_' . sanitize_key($slug);
        $cached = wp_cache_get( $cache_key, 'bizcity_characters' );
        if ( false !== $cached ) {
            return $cached;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        $result = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE slug = %s", $slug));
        wp_cache_set( $cache_key, $result, 'bizcity_characters', 1800 );
        return $result;
    }
    
    /**
     * Get all characters (cached for common queries)
     */
    public function get_characters($args = []) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        
        $defaults = [
            'status' => '',
            'author_id' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'limit' => 50,
            'offset' => 0,
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        // Cache key based on query args
        $cache_key = 'bk_chars_' . md5( serialize( $args ) );
        $cached = wp_cache_get( $cache_key, 'bizcity_characters' );
        if ( false !== $cached ) {
            return $cached;
        }
        
        $where = ['1=1'];
        $values = [];
        
        if (!empty($args['status'])) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        
        if (!empty($args['author_id'])) {
            $where[] = 'author_id = %d';
            $values[] = $args['author_id'];
        }
        
        $where_sql = implode(' AND ', $where);
        $orderby = sanitize_sql_orderby("{$args['orderby']} {$args['order']}") ?: 'created_at DESC';
        
        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $values[] = $args['limit'];
        $values[] = $args['offset'];
        
        if (count($values) > 2) {
            $result = $wpdb->get_results($wpdb->prepare($sql, ...$values));
        } else {
            $result = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE 1=1 ORDER BY {$orderby} LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            ));
        }
        
        wp_cache_set( $cache_key, $result, 'bizcity_characters', 1800 );
        return $result;
    }
    
    /**
     * Create character
     */
    public function create_character($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        
        $defaults = [
            'name' => '',
            'slug' => '',
            'avatar' => '',
            'description' => '',
            'system_prompt' => '',
            'capabilities' => '[]',
            'industries' => '[]',
            'variables_schema' => '{}',
            'settings' => '{}',
            'status' => 'draft',
            'author_id' => get_current_user_id(),
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = sanitize_title($data['name']);
        }
        
        // Ensure JSON fields are strings
        foreach (['capabilities', 'industries', 'variables_schema', 'settings'] as $field) {
            if (is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }
        
        $this->invalidate_character_cache();
        return $wpdb->insert_id;
    }
    
    /**
     * Update character
     */
    public function update_character($id, $data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';
        
        // Ensure JSON fields are strings
        foreach (['capabilities', 'industries', 'variables_schema', 'settings'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }
        
        $result = $wpdb->update($table, $data, ['id' => $id]);
        
        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }
        
        $this->invalidate_character_cache( $id );
        return true;
    }
    
    /**
     * Delete character
     */
    public function delete_character($id) {
        global $wpdb;
        
        // Delete related data
        $wpdb->delete($wpdb->prefix . 'bizcity_knowledge_sources', ['character_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'bizcity_knowledge_chunks', ['character_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'bizcity_character_intents', ['character_id' => $id]);
        $wpdb->delete($wpdb->prefix . 'bizcity_character_conversations', ['character_id' => $id]);
        
        $this->invalidate_character_cache( $id );
        // Delete character
        return $wpdb->delete($wpdb->prefix . 'bizcity_characters', ['id' => $id]);
    }
    
    /**
     * Flush character caches.
     * Clears individual character cache and all list caches.
     *
     * @param int $id  Optional specific character ID to flush.
     */
    private function invalidate_character_cache( $id = 0 ) {
        if ( $id ) {
            wp_cache_delete( 'bk_char_' . intval($id), 'bizcity_characters' );
        }
        // Flush the whole group — covers list caches and slug lookups
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( 'bizcity_characters' );
        } else {
            // Fallback: WordPress default object cache doesn't support flush_group
            wp_cache_delete( 'bk_char_' . intval($id), 'bizcity_characters' );
        }
    }
    
    /**
     * Get knowledge sources for character
     */
    public function get_knowledge_sources($character_id, $status = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';
        
        $where = 'character_id = %d';
        $values = [$character_id];
        
        if (!empty($status)) {
            $where .= ' AND status = %s';
            $values[] = $status;
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC",
            ...$values
        ));
    }
    
    /**
     * Create knowledge source
     */
    public function create_knowledge_source($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';
        
        if (isset($data['settings']) && is_array($data['settings'])) {
            $data['settings'] = json_encode($data['settings'], JSON_UNESCAPED_UNICODE);
        }
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : new WP_Error('db_error', $wpdb->last_error);
    }
    
    /**
     * Get knowledge chunks for character
     */
    public function get_knowledge_chunks($character_id, $limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE character_id = %d ORDER BY id LIMIT %d",
            $character_id,
            $limit
        ));
    }
    
    /**
     * Get chunks by source ID
     */
    public function get_chunks_by_source($source_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, source_id, character_id, chunk_index, content, token_count, 
                    LENGTH(embedding) > 10 as has_embedding, created_at
             FROM {$table} 
             WHERE source_id = %d 
             ORDER BY chunk_index ASC",
            $source_id
        ));
    }
    
    /**
     * Get all chunks for character with source info
     */
    public function get_all_chunks_with_source($character_id, $limit = 500) {
        global $wpdb;
        $chunks_table = $wpdb->prefix . 'bizcity_knowledge_chunks';
        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.id, c.source_id, c.chunk_index, c.content, c.token_count,
                    LENGTH(c.embedding) > 10 as has_embedding, c.created_at,
                    s.source_name, s.source_type
             FROM {$chunks_table} c
             LEFT JOIN {$sources_table} s ON c.source_id = s.id
             WHERE c.character_id = %d 
             ORDER BY c.source_id, c.chunk_index ASC
             LIMIT %d",
            $character_id,
            $limit
        ));
    }
    
    /**
     * Create knowledge chunk
     */
    public function create_chunk($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';
        
        if (isset($data['embedding']) && is_array($data['embedding'])) {
            $data['embedding'] = json_encode($data['embedding']);
        }
        
        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = json_encode($data['metadata'], JSON_UNESCAPED_UNICODE);
        }
        
        $data['content_hash'] = md5($data['content']);
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : new WP_Error('db_error', $wpdb->last_error);
    }
    
    /**
     * Get only chunk embeddings for search (lightweight — skips content/metadata).
     *
     * @param int $character_id
     * @param int $limit
     * @return array Array of objects with {id, embedding}.
     */
    public function get_chunk_embeddings( $character_id, $limit = 1000 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, embedding FROM {$table} WHERE character_id = %d AND embedding IS NOT NULL AND embedding != '' ORDER BY id LIMIT %d",
            $character_id,
            $limit
        ) );
    }

    /**
     * Get full chunk data for specific IDs (after search matching).
     *
     * @param array $ids Array of chunk IDs.
     * @return array Array of full chunk objects keyed by id.
     */
    public function get_chunks_by_ids( array $ids ) {
        global $wpdb;

        if ( empty( $ids ) ) {
            return [];
        }

        $table       = $wpdb->prefix . 'bizcity_knowledge_chunks';
        $ids_clean   = array_map( 'intval', $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids_clean ), '%d' ) );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source_id, character_id, chunk_index, content, token_count, metadata, created_at
             FROM {$table}
             WHERE id IN ({$placeholders})",
            $ids_clean
        ) );

        $keyed = [];
        foreach ( $rows as $row ) {
            $keyed[ $row->id ] = $row;
        }

        return $keyed;
    }

    /**
     * Count chunks for a character (used for cache invalidation keys).
     *
     * @param int $character_id
     * @return int
     */
    public function count_chunks( $character_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE character_id = %d AND embedding IS NOT NULL AND embedding != ''",
            $character_id
        ) );
    }

    /**
     * Get intents for character
     */
    public function get_intents($character_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_character_intents';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE character_id = %d AND is_active = 1 ORDER BY priority ASC",
            $character_id
        ));
    }
    
    /**
     * Create intent
     */
    public function create_intent($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_character_intents';
        
        foreach (['keywords', 'examples', 'output_variables'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }
        
        $result = $wpdb->insert($table, $data);
        
        return $result ? $wpdb->insert_id : new WP_Error('db_error', $wpdb->last_error);
    }
    
    /**
     * Log conversation
     */
    public function log_conversation($data) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_character_conversations';
        
        if (isset($data['extracted_variables']) && is_array($data['extracted_variables'])) {
            $data['extracted_variables'] = json_encode($data['extracted_variables'], JSON_UNESCAPED_UNICODE);
        }
        
        return $wpdb->insert($table, $data);
    }
    
    /**
     * Get character stats
     */
    public function get_character_stats($character_id) {
        global $wpdb;
        
        $conversations_table = $wpdb->prefix . 'bizcity_character_conversations';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_conversations,
                SUM(message_count) as total_messages,
                AVG(satisfaction_rating) as avg_rating
            FROM {$conversations_table}
            WHERE character_id = %d",
            $character_id
        ));
    }

    /* ================================================================
     *  Knowledge Fabric — Scope-aware queries (v3.0.0)
     * ================================================================ */

    /**
     * Get knowledge sources by scope parameters.
     *
     * @param array $params {
     *     @type string $scope       Required: user|project|session|agent
     *     @type int    $user_id     Required for user/project/session scope
     *     @type int    $project_id  Required for project scope
     *     @type string $session_id  Required for session scope
     *     @type int    $character_id Required for agent scope
     *     @type string $status      Optional filter
     * }
     * @return array
     */
    public function get_sources_by_scope( $params ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        $where  = array( 'scope = %s' );
        $values = array( $params['scope'] );

        switch ( $params['scope'] ) {
            case 'user':
                $where[]  = 'user_id = %d';
                $values[] = (int) $params['user_id'];
                break;
            case 'project':
                $where[]  = 'user_id = %d';
                $values[] = (int) $params['user_id'];
                $where[]  = 'project_id = %d';
                $values[] = (int) $params['project_id'];
                break;
            case 'session':
                $where[]  = 'session_id = %s';
                $values[] = $params['session_id'];
                break;
            case 'agent':
                $where[]  = 'character_id = %d';
                $values[] = (int) $params['character_id'];
                break;
        }

        if ( ! empty( $params['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $params['status'];
        }

        $where_sql = implode( ' AND ', $where );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC",
            ...$values
        ) );
    }

    /**
     * Get chunk embeddings by scope (for multi-scope semantic search).
     *
     * @param string $scope       Scope type
     * @param array  $scope_ids   Scope identifiers (user_id, project_id, etc.)
     * @param int    $limit
     * @return array  Array of {id, embedding}
     */
    public function get_chunk_embeddings_by_scope( $scope, $scope_ids, $limit = 500 ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_chunks';

        $where  = array( "scope = %s", "embedding IS NOT NULL", "embedding != ''" );
        $values = array( $scope );

        switch ( $scope ) {
            case 'user':
                $where[]  = 'user_id = %d';
                $values[] = (int) $scope_ids['user_id'];
                break;
            case 'project':
                $where[]  = 'project_id = %d';
                $values[] = (int) $scope_ids['project_id'];
                break;
            case 'session':
                $where[]  = 'session_id = %s';
                $values[] = $scope_ids['session_id'];
                break;
            case 'agent':
                $where[]  = 'character_id = %d';
                $values[] = (int) $scope_ids['character_id'];
                break;
        }

        $where_sql = implode( ' AND ', $where );
        $values[]  = $limit;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, embedding FROM {$table} WHERE {$where_sql} ORDER BY id LIMIT %d",
            ...$values
        ) );
    }

    /**
     * Count sources for a user across all scopes.
     *
     * @param int $user_id
     * @return array  Keyed by scope: [ 'user' => 5, 'project' => 3, ... ]
     */
    public function count_user_sources( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT scope, COUNT(*) as cnt FROM {$table} WHERE user_id = %d GROUP BY scope",
            $user_id
        ) );

        $counts = array( 'user' => 0, 'project' => 0, 'session' => 0 );
        foreach ( $rows as $row ) {
            $counts[ $row->scope ] = (int) $row->cnt;
        }
        return $counts;
    }

    /**
     * Update scope of a source and its chunks (promote/demote).
     *
     * @param int    $source_id
     * @param string $new_scope
     * @param array  $extra_data  Optional: user_id, project_id to update
     * @return bool
     */
    public function update_source_scope( $source_id, $new_scope, $extra_data = array() ) {
        global $wpdb;

        $update = array_merge( array( 'scope' => $new_scope ), $extra_data );

        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_sources',
            $update,
            array( 'id' => $source_id )
        );

        $wpdb->update(
            $wpdb->prefix . 'bizcity_knowledge_chunks',
            $update,
            array( 'source_id' => $source_id )
        );

        return true;
    }

    /**
     * Delete expired session sources older than N hours.
     *
     * @param int $max_age_hours  Default 24
     * @return int  Number of deleted sources
     */
    public function delete_expired_session_sources( $max_age_hours = 24 ) {
        global $wpdb;

        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';
        $chunks_table  = $wpdb->prefix . 'bizcity_knowledge_chunks';

        // Get expired source IDs
        $expired_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$sources_table}
             WHERE scope = 'session'
             AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
            $max_age_hours
        ) );

        if ( empty( $expired_ids ) ) {
            return 0;
        }

        $ids_csv      = implode( ',', array_map( 'intval', $expired_ids ) );
        $deleted_count = count( $expired_ids );

        // Delete chunks first (FK)
        $wpdb->query( "DELETE FROM {$chunks_table} WHERE source_id IN ({$ids_csv})" );

        // Delete sources
        $wpdb->query( "DELETE FROM {$sources_table} WHERE id IN ({$ids_csv})" );

        error_log( "BizCity Knowledge Fabric: Cleaned up {$deleted_count} expired session sources" );

        return $deleted_count;
    }

    /**
     * Update a knowledge source by ID.
     *
     * @param int   $source_id
     * @param array $data
     * @return bool|WP_Error
     */
    public function update_source( $source_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_knowledge_sources';

        if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
            $data['settings'] = wp_json_encode( $data['settings'] );
        }

        $result = $wpdb->update( $table, $data, array( 'id' => $source_id ) );

        return $result !== false ? true : new WP_Error( 'db_error', $wpdb->last_error );
    }

    /**
     * Delete a source and its chunks by source ID.
     *
     * @param int $source_id
     * @return bool
     */
    public function delete_source_and_chunks( $source_id ) {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . 'bizcity_knowledge_chunks', array( 'source_id' => $source_id ) );
        $wpdb->delete( $wpdb->prefix . 'bizcity_knowledge_sources', array( 'id' => $source_id ) );

        return true;
    }
}
