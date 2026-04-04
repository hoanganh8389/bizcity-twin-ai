<?php
/**
 * Bizcity Twin AI — Nền tảng AI Companion cá nhân hóa
 * Bizcity Twin AI — Personalized AI Companion Platform
 *
 * Unified User Memory (2-Tier) — Long-term memory for all channels
 *
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Knowledge
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 *
 * Manages long-term memory for users across ALL channels (Webchat, Zalo, Telegram, etc.).
 *
 * 2-Tier Architecture:
 *   Tier 1 (extracted)  — LLM-analyzed memories from conversation history
 *   Tier 2 (explicit)   — User explicitly asked bot to remember something
 *
 * Storage: bizcity_memory_users table
 *
 * Integration:
 *   - Chat Gateway injects memories into system prompt (all channels)
 *   - Mode Classifier detects "hãy nhớ..." → explicit memory
 *   - Cron/manual scan conversations → extracted memories
 *   - Admin menu to view/manage/add memories
 * @since   2.1.0
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

class BizCity_User_Memory {

    /** @var self|null */
    private static $instance = null;

    /** Tier constants */
    const TIER_EXTRACTED = 'extracted';  // LLM-analyzed from chat history
    const TIER_EXPLICIT  = 'explicit';   // User asked bot to remember

    /** Memory types */
    const TYPE_IDENTITY     = 'identity';
    const TYPE_PREFERENCE   = 'preference';
    const TYPE_GOAL         = 'goal';
    const TYPE_PAIN         = 'pain';
    const TYPE_CONSTRAINT   = 'constraint';
    const TYPE_HABIT        = 'habit';
    const TYPE_RELATIONSHIP = 'relationship';
    const TYPE_FACT         = 'fact';
    const TYPE_REQUEST      = 'request';  // explicit user requests

    /** Max memories per user */
    const MAX_PER_USER = 500;

    /** @var bool Flag to prevent double injection (direct + filter) */
    private $already_injected = false;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        // Ensure table exists (auto-create if missing)
        self::ensure_table();

        // ── GLOBAL MEMORY INJECTION — highest priority (99) ──
        // Injects user memory into system prompt AFTER all pipeline instructions.
        // This ensures user preferences (addressing style, language, etc.) override
        // all 6 branches: emotion, reflection, knowledge, planning, coding, execution.
        add_filter( 'bizcity_chat_system_prompt', [ $this, 'inject_memory_into_system_prompt' ], 99, 2 );

        // Hook: detect explicit memory in chat pipeline
        add_action( 'bizcity_intent_mode_processed', [ $this, 'handle_explicit_memory' ], 10, 2 );

        // AJAX: admin manual memory management
        add_action( 'wp_ajax_bizcity_memory_list',          [ $this, 'ajax_list' ] );
        add_action( 'wp_ajax_bizcity_memory_add',           [ $this, 'ajax_add' ] );
        add_action( 'wp_ajax_bizcity_memory_delete',        [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_bizcity_memory_build',         [ $this, 'ajax_build_from_history' ] );
        add_action( 'wp_ajax_bizcity_memory_poll_router',   [ $this, 'ajax_poll_router' ] );
        add_action( 'wp_ajax_bizcity_poll_execution_log', [ $this, 'ajax_poll_execution_log' ] );
    }

    /* ================================================================
     * TABLE HELPER
     * ================================================================ */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'bizcity_memory_users';
    }

    /**
     * Ensure the user_memory table exists.
     * Called once per request; uses a static flag to avoid repeated checks.
     */
    public static function ensure_table() {
        static $checked = false;
        if ( $checked ) {
            return;
        }
        $checked = true;

        global $wpdb;
        $table = self::table();

        // Quick check: does the table exist? (SHOW TABLES works across shards)
        $found = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );

        if ( $found ) {
            return; // Table already exists
        }

        // If last_error indicates shard is down (REFUSE), don't attempt CREATE
        if ( $wpdb->last_error && stripos( $wpdb->last_error, 'REFUSE' ) !== false ) {
            return;
        }

        // Table missing — create it now
        error_log( "[BizCity_User_Memory] Table {$table} not found — creating..." );

        $charset_collate = function_exists( 'bizcity_get_charset_collate' ) ? bizcity_get_charset_collate() : $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
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

        dbDelta( $sql );

        // Verify table was actually created before logging success
        $verify = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
        if ( $verify ) {
            error_log( "[BizCity_User_Memory] Table {$table} created successfully." );
        } else {
            error_log( "[BizCity_User_Memory] Table {$table} creation FAILED (shard may be unavailable)." );
        }
    }

    /* ================================================================
     * TIER 2: EXPLICIT MEMORY — user asks "hãy nhớ..."
     *
     * @param int    $user_id     WP user ID (0 = guest)
     * @param string $session_id  Session ID for guest
     * @param string $content     What to remember
     * @param array  $extra       Extra metadata
     * @return int|false  Inserted/updated row ID
     * ================================================================ */
    public function remember( $user_id, $session_id, $content, $extra = [] ) {
        if ( empty( trim( $content ) ) ) {
            return false;
        }

        $content = $this->extract_memory_content( $content );

        // Generate a slug key
        $key = 'user_request:' . md5( mb_strtolower( trim( $content ) ) );

        return $this->upsert( [
            'user_id'     => $user_id,
            'session_id'  => $session_id,
            'memory_tier' => self::TIER_EXPLICIT,
            'memory_type' => self::TYPE_REQUEST,
            'memory_key'  => $key,
            'memory_text' => $content,
            'score'       => 90,  // explicit = high importance
            'metadata'    => wp_json_encode( $extra ),
        ] );
    }

    /**
     * Remember a URL — crawl, store summary in memory, create knowledge source for Global Character.
     */
    public function remember_url( $user_id, $session_id, $url ) {
        $title        = parse_url( $url, PHP_URL_HOST );
        $content      = "Người dùng chia sẻ link: {$url}";
        $full_content = '';

        // Try Knowledge Web Crawler (static) first, then legacy
        if ( class_exists( 'BizCity_Knowledge_Web_Crawler' ) ) {
            $result = BizCity_Knowledge_Web_Crawler::crawl_single_page( $url );
            if ( ! is_wp_error( $result ) && ! empty( $result['content'] ) ) {
                $title        = $result['title'] ?? $title;
                $full_content = $result['content'];
                $content      = "Link: {$url}\n{$title}\n" . mb_substr( $full_content, 0, 2000, 'UTF-8' );
            }
        } elseif ( class_exists( 'BizCity_Web_Crawler' ) ) {
            $crawler = new BizCity_Web_Crawler();
            $result  = $crawler->crawl_url( $url, [ 'max_depth' => 0 ] );
            if ( ! empty( $result['content'] ) ) {
                $title        = $result['title'] ?? $title;
                $full_content = $result['content'];
                $content      = "Link: {$url}\n{$title}\n" . mb_substr( $full_content, 0, 2000, 'UTF-8' );
            }
        }

        // Save text summary to user_memory
        $mem_result = $this->remember( $user_id, $session_id, $content, [
            'source_url' => $url,
            'crawled'    => ! empty( $full_content ),
        ] );

        // Also create proper knowledge source (chunked + embedded) for Global Character
        if ( ! empty( $full_content ) && mb_strlen( $full_content, 'UTF-8' ) > 100 ) {
            $this->create_global_knowledge_source( $url, $title, $full_content, 'url', [
                'user_id'    => $user_id,
                'session_id' => $session_id,
            ] );
        }

        return $mem_result;
    }

    /**
     * Remember a file — parse, store summary in memory, create knowledge source for Global Character.
     */
    public function remember_file( $user_id, $session_id, $file_url, $file_name = '' ) {
        $content      = "Người dùng upload file: {$file_name}";
        $full_content = '';

        if ( class_exists( 'BizCity_File_Processor' ) ) {
            $processor = BizCity_File_Processor::instance();
            $result    = $processor->process_file_url( $file_url );
            if ( ! empty( $result['content'] ) ) {
                $full_content = $result['content'];
                $content      = "File: {$file_name}\n" . mb_substr( $full_content, 0, 2000, 'UTF-8' );
            }
        }

        // Save text summary to user_memory
        $mem_result = $this->remember( $user_id, $session_id, $content, [
            'source_file' => $file_url,
            'file_name'   => $file_name,
        ] );

        // Also create proper knowledge source (chunked + embedded) for Global Character
        if ( ! empty( $full_content ) && mb_strlen( $full_content, 'UTF-8' ) > 100 ) {
            $this->create_global_knowledge_source( $file_url, $file_name ?: 'Uploaded File', $full_content, 'file', [
                'user_id'    => $user_id,
                'session_id' => $session_id,
            ] );
        }

        return $mem_result;
    }

    /* ================================================================
     * TIER 1: EXTRACTED MEMORY — LLM-analyzed from conversation history
     *
     * Scans bizcity_webchat_messages for a user, sends to LLM to extract
     * key memories (identity, preferences, goals, pain points, etc.)
     *
     * @param array $args  { user_id, session_id, limit, since_id }
     * @return array  { ok, count, inserted, updated }
     * ================================================================ */
    public function build_from_history( $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'user_id'    => 0,
            'session_id' => '',
            'limit'      => 100,
            'since_id'   => 0,
            'blog_id'    => get_current_blog_id(),
        ] );

        $table_msgs = $wpdb->prefix . 'bizcity_webchat_messages';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '$table_msgs'" ) !== $table_msgs ) {
            return [ 'ok' => false, 'error' => 'Messages table not found' ];
        }

        // Fetch recent messages
        $where   = 'WHERE 1=1';
        $params  = [];

        if ( ! empty( $args['session_id'] ) ) {
            $where   .= ' AND session_id = %s';
            $params[] = $args['session_id'];
        } elseif ( (int) $args['user_id'] > 0 ) {
            $where   .= ' AND user_id = %d';
            $params[] = (int) $args['user_id'];
        } else {
            return [ 'ok' => false, 'error' => 'No user_id or session_id' ];
        }

        if ( (int) $args['since_id'] > 0 ) {
            $where   .= ' AND id > %d';
            $params[] = (int) $args['since_id'];
        }

        $sql = "SELECT id, session_id, user_id, message_text, message_from, client_name, created_at
                FROM {$table_msgs}
                {$where}
                ORDER BY id DESC
                LIMIT " . (int) $args['limit'];

        $rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );

        if ( ! $rows ) {
            return [ 'ok' => true, 'count' => 0, 'inserted' => 0, 'updated' => 0 ];
        }

        // Build conversation text
        $conversation = [];
        foreach ( array_reverse( $rows ) as $r ) {
            $role = ( $r['message_from'] === 'bot' || $r['message_from'] === 'assistant' ) ? 'assistant' : 'user';
            $conversation[] = [
                'role' => $role,
                'text' => $r['message_text'],
                'id'   => $r['id'],
            ];
        }

        // Extract via LLM
        $memories = $this->extract_memories_llm( $conversation );

        $inserted = 0;
        $updated  = 0;

        $user_id    = $rows[0]['user_id'] ?? $args['user_id'];
        $session_id = $rows[0]['session_id'] ?? $args['session_id'];

        // Collect source IDs
        $source_ids = implode( ',', array_column( $rows, 'id' ) );

        foreach ( $memories as $mem ) {
            $result = $this->upsert( [
                'user_id'        => (int) $user_id,
                'session_id'     => (string) $session_id,
                'memory_tier'    => self::TIER_EXTRACTED,
                'memory_type'    => $mem['type'] ?? self::TYPE_FACT,
                'memory_key'     => $mem['key'] ?? '',
                'memory_text'    => $mem['text'] ?? '',
                'score'          => intval( $mem['score'] ?? 50 ),
                'source_log_ids' => $source_ids,
            ] );

            if ( $result === 'insert' )  $inserted++;
            if ( $result === 'update' )  $updated++;
        }

        return [
            'ok'       => true,
            'count'    => count( $rows ),
            'inserted' => $inserted,
            'updated'  => $updated,
        ];
    }

    /* ================================================================
     * GET MEMORIES — for injection into system prompt
     *
     * Returns ALL memories for a user (both tiers), sorted by score.
     * Called by Chat Gateway to inject into system_content.
     *
     * @param array $args  { user_id, session_id, limit, memory_tier, memory_type }
     * @return array  Array of memory rows
     * ================================================================ */
    public function get_memories( $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'user_id'     => 0,
            'session_id'  => '',
            'limit'       => 30,
            'memory_tier' => '',
            'memory_type' => '',
            'order_by'    => 'score',
            'blog_id'     => get_current_blog_id(),
        ] );

        $table  = self::table();
        $where  = [ 'blog_id = %d' ];
        $params = [ (int) $args['blog_id'] ];

        if ( (int) $args['user_id'] > 0 ) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }

        if ( ! empty( $args['session_id'] ) ) {
            $where[]  = 'session_id = %s';
            $params[] = $args['session_id'];
        }

        if ( ! empty( $args['memory_tier'] ) ) {
            $where[]  = 'memory_tier = %s';
            $params[] = $args['memory_tier'];
        }

        if ( ! empty( $args['memory_type'] ) ) {
            $where[]  = 'memory_type = %s';
            $params[] = $args['memory_type'];
        }

        $where_sql = implode( ' AND ', $where );
        $order_by  = in_array( $args['order_by'], [ 'score', 'times_seen', 'created_at', 'updated_at' ] )
            ? $args['order_by'] : 'score';

        $sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} DESC LIMIT %d";
        $params[] = (int) $args['limit'];

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
    }

    /* ================================================================
     * BUILD COMPACT MEMORY — lightweight summary for Classifier / Router
     *
     * Returns top memories in a single-line format (~60-80 tokens max).
     * Used by Mode Classifier and Intent Router where full memory is too heavy.
     *
     * @since 4.8.1
     * @param int    $user_id
     * @param string $session_id
     * @return string  Compact memory line, or '' if no memories.
     * ================================================================ */
    public static function build_compact_memory( $user_id, $session_id = '' ) {
        $instance = self::instance();

        // Query by user_id for logged-in users, session_id for anonymous.
        $query_user_id    = (int) $user_id > 0 ? (int) $user_id : 0;
        $query_session_id = (int) $user_id > 0 ? ''              : $session_id;

        $memories = $instance->get_memories( [
            'user_id'    => $query_user_id,
            'session_id' => $query_session_id,
            'limit'      => 5,
            'order_by'   => 'score',
        ] );

        if ( empty( $memories ) ) {
            return '';
        }

        $items = [];
        foreach ( $memories as $mem ) {
            $type = $mem->memory_type ?? 'fact';
            $text = mb_substr( trim( $mem->memory_text ?? '' ), 0, 80 );
            if ( $text ) {
                $items[] = "[{$type}] {$text}";
            }
        }

        if ( empty( $items ) ) {
            return '';
        }

        return "\nUSER MEMORY: " . implode( ' | ', $items );
    }

    /* ================================================================
     * BUILD MEMORY CONTEXT STRING — for Chat Gateway system prompt
     *
     * @param int    $user_id
     * @param string $session_id
     * @return string  Formatted memory context or empty
     * ================================================================ */
    /**
     * Filter callback: inject memory into system prompt at HIGHEST priority.
     *
     * This runs at priority 99 on `bizcity_chat_system_prompt`, ensuring it
     * overrides ALL pipeline instructions (emotion "xưng mình", reflection, etc.).
     * Applied globally to all 6 branches.
     *
     * @param string $prompt  Current system prompt.
     * @param array  $args    Contextual data: user_id, session_id, etc.
     * @return string Modified system prompt with memory appended.
     */
    public function inject_memory_into_system_prompt( $prompt, $args = [] ) {
        // Skip if already injected directly (Layer 0 in intent-stream / chat-gateway)
        if ( $this->already_injected ) {
            return $prompt;
        }

        $user_id    = intval( $args['user_id'] ?? get_current_user_id() );
        $session_id = $args['session_id'] ?? '';

        if ( ! $user_id && empty( $session_id ) ) {
            return $prompt;
        }

        // For logged-in users: query by user_id ONLY (global memory across all sessions).
        // For anonymous: query by session_id ONLY.
        $query_user_id    = $user_id > 0 ? $user_id : 0;
        $query_session_id = $user_id > 0 ? ''       : $session_id;

        $memory_context = $this->build_memory_context( $query_user_id, $query_session_id, $session_id );
        if ( empty( $memory_context ) ) {
            return $prompt;
        }

        return $prompt . $memory_context;
    }

    public function build_memory_context( $user_id, $session_id = '', $log_session_id = '' ) {
        $mem_start = microtime( true );

        // ── Twin Focus Gate: filter memory by mode ──
        $twin_memory_mode = 'all';
        $twin_message     = '';
        if ( class_exists( 'BizCity_Focus_Gate' ) ) {
            $twin_memory_mode = BizCity_Focus_Gate::get_memory_mode();
            $fp = BizCity_Focus_Gate::get_focus_profile();
            $twin_message = $fp['_message'] ?? '';
        }

        // In 'explicit' mode: only load user-requested memories
        $query_args = [
            'user_id'    => $user_id,
            'session_id' => $session_id,
            'limit'      => 30,
            'order_by'   => 'score',
        ];
        if ( $twin_memory_mode === 'explicit' ) {
            $query_args['memory_tier'] = self::TIER_EXPLICIT;
            $query_args['limit']       = 10;
        }

        $memories = $this->get_memories( $query_args );
        $loaded_count = count( $memories );

        if ( empty( $memories ) ) {
            // ── Twin Trace: memory layer → empty ──
            if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                BizCity_Twin_Trace::memory( $twin_memory_mode, 0, 0 );
            }
            return '';
        }

        // In 'relevant' mode: filter by topic match against current message
        if ( $twin_memory_mode === 'relevant' && ! empty( $twin_message ) ) {
            $memories = self::filter_relevant_memories( $memories, $twin_message );
            if ( empty( $memories ) ) {
                // ── Twin Trace: memory filtered to empty ──
                if ( class_exists( 'BizCity_Twin_Trace' ) ) {
                    BizCity_Twin_Trace::memory( $twin_memory_mode, $loaded_count, 0 );
                }
                return '';
            }
        }

        // ── Twin Trace: memory layer injected ──
        if ( class_exists( 'BizCity_Twin_Trace' ) ) {
            BizCity_Twin_Trace::memory( $twin_memory_mode, $loaded_count, count( $memories ) );
        }

        // Separate explicit (user-requested) vs extracted (AI-analyzed)
        $explicit_lines  = [];
        $extracted_lines = [];
        foreach ( $memories as $m ) {
            $line = "- [{$m->memory_type}] {$m->memory_text}";
            if ( $m->memory_tier === self::TIER_EXPLICIT ) {
                $explicit_lines[] = $line;
            } else {
                $extracted_lines[] = $line;
            }
        }

        $ctx = "\n\n---\n\n";
        $ctx .= "## 🧠 KÝ ỨC VỀ USER (Chủ Nhân) — Thông tin bạn đã biết về NGƯỜI DÙNG\n\n";
        $ctx .= "⛔ **RANH GIỚI VAI TRÒ**: Tất cả thông tin bên dưới mô tả NGƯỜI DÙNG (Chủ Nhân), KHÔNG PHẢI bạn (AI). ";
        $ctx .= "Bạn là Trợ lý AI — KHÔNG BAO GIỜ tự xưng bằng tên/danh xưng của user. ";
        $ctx .= "Khi user dặn xưng hô 'mày tao', nghĩa là USER xưng 'tao' và gọi AI là 'mày' — AI xưng lại phù hợp, KHÔNG tự nhận mình là user.\n\n";

        // Explicit memories — user-requested
        if ( ! empty( $explicit_lines ) ) {
            $ctx .= "### 📌 USER ĐÃ DẶN — cách xưng hô & phong cách:\n";
            $ctx .= implode( "\n", $explicit_lines ) . "\n\n";
            $ctx .= "⚠️ **LƯU Ý**: Các mục 📌 ở trên là YÊU CẦU TRỰC TIẾP từ user về cách AI giao tiếp. ";
            $ctx .= "Hãy sử dụng cách xưng hô đó — nhưng nhớ: xưng hô là cách BẠN (AI) gọi user, ";
            $ctx .= "KHÔNG PHẢI để bạn NHẬP VAI thành user.\n\n";
        }

        // Extracted memories — AI-analyzed context
        if ( ! empty( $extracted_lines ) ) {
            $ctx .= "### 🧠 Thông tin đã biết về user (AI đúc kết từ lịch sử):\n";
            $ctx .= implode( "\n", $extracted_lines ) . "\n";
        }

        // ── Log for admin AJAX Console ──
        $tier_counts = [ 'explicit' => count( $explicit_lines ), 'extracted' => count( $extracted_lines ) ];
        $type_list   = [];
        foreach ( $memories as $m ) {
            $type_list[] = $m->memory_type;
        }
        self::log_router_event( [
            'step'             => 'memory_build',
            'message'          => 'build_memory_context()',
            'mode'             => 'memory',
            'functions_called' => 'BizCity_User_Memory::build_memory_context()',
            'pipeline'         => [
                '1:GetMemories'  . ( ! empty( $memories ) ? ' ✓' : ' —' ),
                '2:FilterByScore ✓',
                '3:SplitTiers'   . ( ( $tier_counts['explicit'] + $tier_counts['extracted'] ) > 0 ? ' ✓' : ' —' ),
                '4:FormatOverride' . ( ! empty( $explicit_lines ) ? ' ✓' : ' —' ),
            ],
            'file_line'        => 'class-user-memory.php::build_memory_context',
            'user_id'          => $user_id,
            'query_session_id' => $session_id ?: '(global — all sessions)',
            'memory_count'     => count( $memories ),
            'tier_explicit'    => $tier_counts['explicit'],
            'tier_extracted'   => $tier_counts['extracted'],
            'memory_types'     => array_unique( $type_list ),
            'context_length'   => mb_strlen( $ctx, 'UTF-8' ),
            'build_ms'         => round( ( microtime( true ) - $mem_start ) * 1000, 2 ),
            'preview'          => mb_substr( $ctx, 0, 200, 'UTF-8' ),
        ], $log_session_id ?: $session_id );

        // Mark as injected to prevent filter at pri 99 from double-injecting
        $this->already_injected = true;

        return $ctx;
    }

    /* ================================================================
     * Twin Focus Gate: filter memories by topic relevance.
     *
     * Simple keyword matching — keeps explicit memories always,
     * filters extracted memories by keyword overlap with message.
     *
     * @param array  $memories  Array of memory objects
     * @param string $message   Current user message
     * @return array Filtered memories
     * ================================================================ */
    private static function filter_relevant_memories( array $memories, string $message ): array {
        $msg_lower = mb_strtolower( $message, 'UTF-8' );
        $msg_words = array_filter(
            preg_split( '/[\s,;.!?]+/u', $msg_lower ),
            function ( $w ) { return mb_strlen( $w, 'UTF-8' ) >= 3; }
        );

        if ( empty( $msg_words ) ) {
            return $memories; // can't filter → return all
        }

        $filtered = [];
        foreach ( $memories as $m ) {
            // Always keep explicit (user-requested) memories
            if ( isset( $m->memory_tier ) && $m->memory_tier === self::TIER_EXPLICIT ) {
                $filtered[] = $m;
                continue;
            }
            // Check keyword overlap
            $mem_lower = mb_strtolower( $m->memory_text ?? '', 'UTF-8' );
            foreach ( $msg_words as $word ) {
                if ( mb_strpos( $mem_lower, $word ) !== false ) {
                    $filtered[] = $m;
                    break;
                }
            }
        }

        // If no extracted memories matched, still return explicit ones
        return ! empty( $filtered ) ? $filtered : array_filter( $memories, function ( $m ) {
            return isset( $m->memory_tier ) && $m->memory_tier === self::TIER_EXPLICIT;
        } );
    }

    /* ================================================================
     * HOOK: Handle explicit memory from mode pipeline
     *
     * @param array $pipeline_result
     * @param array $ctx
     * ================================================================ */
    public function handle_explicit_memory( $pipeline_result, $ctx ) {
        $mode_result = $ctx['mode_result'] ?? [];
        $is_memory   = $mode_result['is_memory'] ?? false;

        if ( ! $is_memory ) {
            return;
        }

        $user_id    = intval( $ctx['user_id'] ?? 0 );
        $session_id = $ctx['session_id'] ?? '';
        $message    = $ctx['message'] ?? '';

        if ( empty( $message ) ) {
            return;
        }

        // Check for URL → crawl + remember
        if ( class_exists( 'BizCity_Mode_Classifier' ) ) {
            $url = BizCity_Mode_Classifier::instance()->extract_url( $message );
            if ( $url ) {
                $this->remember_url( $user_id, $session_id, $url );
                return;
            }
        }

        // Regular text memory
        $this->remember( $user_id, $session_id, $message );
    }

    /* ================================================================
     * PUBLIC SAVE — public wrapper used by companion classes (Phase 4.5)
     *
     * Allows BizCity_Emotional_Memory and other companion classes to
     * upsert rows without needing to extend this class.
     *
     * @param array $data  Same signature as upsert()
     * @return string|false  'insert', 'update', or false
     * ================================================================ */
    public function upsert_public( $data ) {
        return $this->upsert( $data );
    }

    /* ================================================================
     * UPSERT — insert or update memory
     *
     * @param array $data
     * @return string|false  'insert', 'update', or false
     * ================================================================ */
    private function upsert( $data ) {
        global $wpdb;

        $table   = self::table();
        $now     = current_time( 'mysql' );
        $blog_id = get_current_blog_id();

        $data = wp_parse_args( $data, [
            'user_id'        => 0,
            'session_id'     => '',
            'memory_tier'    => self::TIER_EXTRACTED,
            'memory_type'    => self::TYPE_FACT,
            'memory_key'     => '',
            'memory_text'    => '',
            'score'          => 50,
            'source_log_ids' => '',
            'metadata'       => '',
        ] );

        // Check existing by unique key
        $exists_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE blog_id = %d AND user_id = %d AND session_id = %s AND memory_key = %s LIMIT 1",
            $blog_id,
            (int) $data['user_id'],
            (string) $data['session_id'],
            (string) $data['memory_key']
        ) );

        if ( $exists_id > 0 ) {
            // Update — merge score, bump times_seen
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$table} SET
                    memory_text = %s,
                    score = GREATEST(score, %d),
                    times_seen = times_seen + 1,
                    source_log_ids = %s,
                    last_seen = %s,
                    updated_at = %s
                 WHERE id = %d",
                $data['memory_text'],
                (int) $data['score'],
                $data['source_log_ids'],
                $now,
                $now,
                $exists_id
            ) );
            return 'update';
        }

        // Enforce limit
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d AND user_id = %d AND session_id = %s",
            $blog_id,
            (int) $data['user_id'],
            (string) $data['session_id']
        ) );

        if ( $count >= self::MAX_PER_USER ) {
            // Delete lowest score
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE blog_id = %d AND user_id = %d AND session_id = %s ORDER BY score ASC, updated_at ASC LIMIT 1",
                $blog_id,
                (int) $data['user_id'],
                (string) $data['session_id']
            ) );
        }

        // Insert
        $wpdb->insert( $table, [
            'blog_id'        => $blog_id,
            'user_id'        => (int) $data['user_id'],
            'session_id'     => (string) $data['session_id'],
            'memory_tier'    => $data['memory_tier'],
            'memory_type'    => $data['memory_type'],
            'memory_key'     => $data['memory_key'],
            'memory_text'    => $data['memory_text'],
            'score'          => (int) $data['score'],
            'times_seen'     => 1,
            'source_log_ids' => $data['source_log_ids'],
            'metadata'       => $data['metadata'] ?: '',
            'last_seen'      => $now,
            'created_at'     => $now,
            'updated_at'     => $now,
        ] );

        return $wpdb->insert_id ? 'insert' : false;
    }

    /* ================================================================
     * LLM: Extract memories from conversation messages
     *
     * Mirrors the logic from BizCity_Zalo_Bot_Memory::extract_memories_llm()
     * but uses bizcity_openrouter_chat() (unified LLM interface).
     *
     * @param array $conversation  [ { role, text, id }, ... ]
     * @return array  [ { type, key, text, score }, ... ]
     * ================================================================ */
    private function extract_memories_llm( $conversation ) {
        if ( ! function_exists( 'bizcity_openrouter_chat' ) ) {
            return [];
        }

        // Build conversation text
        $lines = [];
        foreach ( $conversation as $msg ) {
            $role = $msg['role'] === 'assistant' ? 'Bot' : 'User';
            $lines[] = "[{$role}] {$msg['text']}";
        }
        $messages_text = implode( "\n", $lines );

        if ( mb_strlen( $messages_text, 'UTF-8' ) < 20 ) {
            return [];
        }

        $system = "Bạn là AI chuyên phân tích tâm lý người dùng. Nhiệm vụ: trích xuất \"ký ức\" (memories) quan trọng từ đoạn hội thoại.

⛔ QUY TẮC QUAN TRỌNG VỀ VAI TRÒ:
- Tất cả ký ức được trích xuất là THÔNG TIN VỀ NGƯỜI DÙNG (user/human), KHÔNG PHẢI về AI.
- Trong hội thoại: [user] là NGƯỜI DÙNG, [bot/assistant] là AI trợ lý.
- Luôn viết ở ngôi THỨ BA khi mô tả user: \"User tên Chu\" KHÔNG PHẢI \"Tên Chu\" hay \"Tôi là Chu\".
- VD đúng: \"User tên Chu, làm nghề lập trình\" — VD sai: \"Tên Chu, đang nghiên cứu Twin AI\"

Các loại ký ức cần trích xuất:
1. **identity** - Thông tin cá nhân CỦA USER: tên, tuổi, nghề nghiệp, sở thích
2. **preference** - Sở thích/Không thích CỦA USER: thích gì, ghét gì, ưu tiên gì
3. **goal** - Mục tiêu CỦA USER: muốn đạt được điều gì, kế hoạch tương lai
4. **pain** - Vấn đề/Nỗi đau CỦA USER: stress, lo âu, vấn đề đang gặp
5. **constraint** - Giới hạn CỦA USER: thiếu thời gian, thiếu tiền, ràng buộc
6. **habit** - Thói quen CỦA USER: làm gì thường xuyên, pattern hành vi
7. **relationship** - Quan hệ CỦA USER: gia đình, bạn bè, đồng nghiệp
8. **request** - Yêu cầu USER đặt ra cho AI: cách xưng hô, phong cách
9. **fact** - Sự kiện/Thông tin khác hữu ích VỀ USER

Yêu cầu output:
- JSON array: [{\"type\": \"...\", \"key\": \"...\", \"text\": \"...\", \"score\": 0-100}]
- 'key': slug ngắn gọn (VD: 'likes:milk_tea', 'pain:stress', 'goal:save_money')
- 'text': Câu mô tả chuẩn hóa bằng tiếng Việt, LUÔN dùng ngôi thứ ba (\"User...\")
- 'score': Độ quan trọng 0-100
- Chỉ trích xuất thông tin có giá trị, bỏ qua chào hỏi thông thường.
- Output ONLY the JSON array, nothing else.";

        $user_prompt = "Đây là các tin nhắn:\n\n{$messages_text}\n\nHãy trích xuất JSON array.";

        $response = bizcity_openrouter_chat( [
            'messages' => [
                [ 'role' => 'system',  'content' => $system ],
                [ 'role' => 'user',    'content' => $user_prompt ],
            ],
            'purpose'     => 'router',  // fast model
            'temperature' => 0.2,
            'max_tokens'  => 1500,
        ] );

        if ( ! $response || ! is_array( $response ) ) {
            return [];
        }

        $text = $response['message'] ?? $response['content'] ?? '';
        return $this->parse_llm_output( $text );
    }

    /**
     * Parse LLM JSON output into structured memories
     */
    private function parse_llm_output( $output ) {
        if ( preg_match( '/\[.*\]/s', $output, $matches ) ) {
            $memories = json_decode( $matches[0], true );
            if ( is_array( $memories ) ) {
                $valid = [];
                foreach ( $memories as $mem ) {
                    if ( empty( $mem['text'] ) || empty( $mem['key'] ) ) continue;
                    $valid[] = [
                        'type'  => $mem['type'] ?? self::TYPE_FACT,
                        'key'   => sanitize_title( $mem['key'] ),
                        'text'  => sanitize_text_field( $mem['text'] ),
                        'score' => max( 0, min( 100, intval( $mem['score'] ?? 50 ) ) ),
                    ];
                }
                return $valid;
            }
        }
        return [];
    }

    /**
     * Strip "hãy nhớ / ghi nhớ" prefix from explicit memory content
     */
    private function extract_memory_content( $message ) {
        $patterns = [
            '/^(hãy\s+)?(nhớ|ghi\s+nhớ|remember|lưu|save|học|learn|memorize)\s+(rằng|là|điều\s+này|cái\s+này|thông\s+tin|cho\s+tôi|cho\s+em|giúp)\s*/ui',
            '/^(hãy\s+nhớ|hãy\s+ghi\s+nhớ|hãy\s+lưu|hãy\s+học)\s*/ui',
            '/^(ghi\s+nhớ\s+giúp|lưu\s+giúp|nhớ\s+giúp)\s*/ui',
        ];

        $content = $message;
        foreach ( $patterns as $pattern ) {
            $content = preg_replace( $pattern, '', $content );
        }

        $content = trim( $content );
        return mb_strlen( $content, 'UTF-8' ) >= 5 ? $content : trim( $message );
    }

    /* ================================================================
     * AJAX: List memories for admin
     * ================================================================ */
    public function ajax_list() {
        check_ajax_referer( 'bizcity_chat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $user_id    = intval( $_POST['user_id'] ?? 0 );
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

        $memories = $this->get_memories( [
            'user_id'    => $user_id,
            'session_id' => $session_id,
            'limit'      => 100,
        ] );

        wp_send_json_success( [ 'memories' => $memories ] );
    }

    /* ================================================================
     * AJAX: Add memory manually (admin)
     * ================================================================ */
    public function ajax_add() {
        check_ajax_referer( 'bizcity_chat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $user_id     = intval( $_POST['user_id'] ?? get_current_user_id() );
        $session_id  = sanitize_text_field( $_POST['session_id'] ?? '' );
        $memory_text = sanitize_textarea_field( $_POST['memory_text'] ?? '' );
        $memory_type = sanitize_text_field( $_POST['memory_type'] ?? self::TYPE_FACT );
        $memory_key  = sanitize_title( $_POST['memory_key'] ?? '' );

        if ( empty( $memory_text ) ) {
            wp_send_json_error( 'Memory text required' );
        }

        if ( empty( $memory_key ) ) {
            $memory_key = 'admin:' . md5( $memory_text );
        }

        $result = $this->upsert( [
            'user_id'     => $user_id,
            'session_id'  => $session_id,
            'memory_tier' => self::TIER_EXPLICIT,
            'memory_type' => $memory_type,
            'memory_key'  => $memory_key,
            'memory_text' => $memory_text,
            'score'       => 80,
        ] );

        wp_send_json_success( [ 'result' => $result ] );
    }

    /* ================================================================
     * AJAX: Delete a memory
     * ================================================================ */
    public function ajax_delete() {
        check_ajax_referer( 'bizcity_chat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $memory_id = intval( $_POST['memory_id'] ?? 0 );
        if ( ! $memory_id ) wp_send_json_error( 'Invalid ID' );

        global $wpdb;
        $deleted = $wpdb->delete( self::table(), [ 'id' => $memory_id ] );

        wp_send_json_success( [ 'deleted' => (bool) $deleted ] );
    }

    /* ================================================================
     * AJAX: Trigger memory build from chat history
     * ================================================================ */
    public function ajax_build_from_history() {
        check_ajax_referer( 'bizcity_chat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $user_id    = intval( $_POST['user_id'] ?? 0 );
        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

        $result = $this->build_from_history( [
            'user_id'    => $user_id,
            'session_id' => $session_id,
            'limit'      => 200,
        ] );

        wp_send_json_success( $result );
    }

    /* ================================================================
     * AJAX: Poll router — returns recent mode classifications for debug
     *
     * This powers the AJAX Console on the admin dashboard.
     * It reads the transient log that Intent Engine writes to.
     * ================================================================ */
    public function ajax_poll_router() {
        check_ajax_referer( 'bizcity_chat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden' );

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );

        // Read router log from transient
        $log_key = 'bizcity_router_log_' . ( $session_id ?: get_current_user_id() );
        $logs    = get_transient( $log_key );

        if ( ! $logs || ! is_array( $logs ) ) {
            $logs = [];
        }

        wp_send_json_success( [ 'logs' => $logs ] );
    }

    /* ================================================================
     * AJAX: Poll Execution Log — returns pipeline/tool execution logs
     *
     * Powers the Execution Log panel on the admin dashboard.
     * Reads from BizCity_Execution_Logger transient.
     * ================================================================ */
    public function ajax_poll_execution_log() {
        check_ajax_referer( 'bizcity_chat', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden' );
        }

        $session_id = sanitize_text_field( $_POST['session_id'] ?? '' );
        $filters    = [];

        // Optional filters
        if ( ! empty( $_POST['step'] ) ) {
            $filters['step'] = array_map( 'sanitize_text_field', (array) $_POST['step'] );
        }
        if ( ! empty( $_POST['pipeline_id'] ) ) {
            $filters['pipeline_id'] = sanitize_text_field( $_POST['pipeline_id'] );
        }

        // Use BizCity_Execution_Logger if available
        if ( class_exists( 'BizCity_Execution_Logger' ) ) {
            BizCity_Execution_Logger::set_session( $session_id ?: 'user_' . get_current_user_id() );
            $logs  = BizCity_Execution_Logger::get_logs( '', $filters );
            $stats = BizCity_Execution_Logger::get_stats();
            wp_send_json_success( [
                'logs'  => $logs,
                'stats' => $stats,
            ] );
        }

        // Fallback: read directly from transient
        $log_key = 'bizcity_exec_log_' . ( $session_id ?: 'user_' . get_current_user_id() );
        $logs    = get_transient( $log_key );
        if ( ! $logs || ! is_array( $logs ) ) {
            $logs = [];
        }

        wp_send_json_success( [ 'logs' => $logs, 'stats' => [] ] );
    }

    /**
     * Cached session_id so callers deeper in the stack (Context API,
     * Chat API, OpenRouter) that don't have $session_id in scope
     * still write to the same transient key as the Intent Engine.
     */
    private static $current_log_session_id = '';

    /* ================================================================
     * STATIC: Log router event (called by Intent Engine & pipeline)
     *
     * Stores the last N mode classification events in a transient
     * so the admin AJAX console can poll and display them.
     *
     * @param array $event { step, message, mode, confidence, method, pipeline, … }
     * @param string $session_id  If omitted, reuses the last known session_id.
     * ================================================================ */
    public static function log_router_event( $event, $session_id = '' ) {
        // Inherit / cache session_id across calls in the same request
        if ( $session_id ) {
            self::$current_log_session_id = $session_id;
        } else {
            $session_id = self::$current_log_session_id;
        }

        $user_id = get_current_user_id();
        $log_key = 'bizcity_router_log_' . ( $session_id ?: $user_id );
        $logs    = get_transient( $log_key );

        if ( ! $logs || ! is_array( $logs ) ) {
            $logs = [];
        }

        // Prepend (newest first), keep max 50
        array_unshift( $logs, array_merge( $event, [
            'timestamp'  => current_time( 'mysql' ),
            'session_id' => $session_id,
        ] ) );

        $logs = array_slice( $logs, 0, 50 );

        set_transient( $log_key, $logs, HOUR_IN_SECONDS );
    }

    /* ================================================================
     * GET STATS — summary for admin dashboard
     * ================================================================ */
    public function get_stats( $args = [] ) {
        global $wpdb;

        $table  = self::table();
        $blog_id = get_current_blog_id();

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE blog_id = %d",
            $blog_id
        ) );

        $by_tier = $wpdb->get_results( $wpdb->prepare(
            "SELECT memory_tier, COUNT(*) as count FROM {$table} WHERE blog_id = %d GROUP BY memory_tier",
            $blog_id
        ), ARRAY_A );

        $by_type = $wpdb->get_results( $wpdb->prepare(
            "SELECT memory_type, COUNT(*) as count, AVG(score) as avg_score FROM {$table} WHERE blog_id = %d GROUP BY memory_type ORDER BY count DESC",
            $blog_id
        ), ARRAY_A );

        $unique_users = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT CONCAT(user_id, '|', session_id)) FROM {$table} WHERE blog_id = %d",
            $blog_id
        ) );

        return [
            'total'        => $total,
            'by_tier'      => $by_tier,
            'by_type'      => $by_type,
            'unique_users' => $unique_users,
        ];
    }

    /* ================================================================
     * GLOBAL MEMORY CHARACTER
     *
     * A reserved character per blog that stores knowledge from user-
     * uploaded files, links, and documents.  Its knowledge chunks are
     * automatically searched alongside every character in this blog.
     * ================================================================ */

    /**
     * Get or auto-create the Global Memory Character for this blog.
     *
     * @return int  Character ID (0 on failure)
     */
    public static function get_global_character_id() {
        static $cached = null;
        if ( $cached !== null ) return $cached;

        global $wpdb;
        $table = $wpdb->prefix . 'bizcity_characters';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            $cached = 0;
            return 0;
        }

        $char_id = (int) $wpdb->get_var(
            "SELECT id FROM {$table} WHERE slug = '__global_memory__' LIMIT 1"
        );

        if ( $char_id > 0 ) {
            $cached = $char_id;
            return $cached;
        }

        // Auto-create
        $now = current_time( 'mysql' );
        $wpdb->insert( $table, [
            'name'             => '🌐 Global Memory',
            'slug'             => '__global_memory__',
            'avatar'           => '',
            'description'      => 'Kiến thức ưu tiên — files, links, tài liệu được người dùng gửi yêu cầu học. Tự động áp dụng cho tất cả trợ lý trong blog này.',
            'system_prompt'    => 'Kho kiến thức toàn cục. Thông tin do người dùng chủ động gửi yêu cầu học — ưu tiên cao nhất.',
            'model_id'         => '',
            'creativity_level' => 0.70,
            'status'           => 'active',
            'author_id'        => get_current_user_id() ?: 1,
            'created_at'       => $now,
            'updated_at'       => $now,
        ] );

        $cached = (int) $wpdb->insert_id;
        return $cached;
    }

    /**
     * Create a knowledge source under the Global Memory Character.
     * Enables chunking + embedding for proper semantic search.
     *
     * @param string $source_url   URL or file path
     * @param string $source_name  Human-readable name
     * @param string $content      Full text content
     * @param string $type         'url' or 'file'
     * @param array  $meta         Extra metadata (user_id, session_id, …)
     * @return int|false  Knowledge source ID or false
     */
    private function create_global_knowledge_source( $source_url, $source_name, $content, $type = 'url', $meta = [] ) {
        $global_id = self::get_global_character_id();
        if ( ! $global_id ) return false;

        if ( ! class_exists( 'BizCity_Knowledge_Database' ) ) return false;

        global $wpdb;
        $sources_table = $wpdb->prefix . 'bizcity_knowledge_sources';

        // Skip if identical content already ingested for this character
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$sources_table} WHERE character_id = %d AND content_hash = %s LIMIT 1",
            $global_id,
            md5( $content )
        ) );
        if ( $existing ) return $existing;

        $db = BizCity_Knowledge_Database::instance();

        $source_data = [
            'character_id' => $global_id,
            'source_type'  => $type === 'file' ? 'file' : 'url',
            'source_name'  => mb_substr( $source_name, 0, 255, 'UTF-8' ),
            'source_url'   => $source_url,
            'content'      => $content,
            'content_hash' => md5( $content ),
            'status'       => 'pending',
            'settings'     => wp_json_encode( array_merge( $meta, [
                'origin' => 'user_memory_request',
            ] ) ),
        ];

        $source_id = $db->create_knowledge_source( $source_data );

        if ( is_wp_error( $source_id ) || ! $source_id ) return false;

        // Process: chunk + embed (async-safe — runs inline for now)
        if ( class_exists( 'BizCity_Knowledge_Embedding' ) ) {
            $embedding = BizCity_Knowledge_Embedding::instance();
            $result    = $embedding->process_source( $source_id, $content );

            if ( is_wp_error( $result ) ) {
                $wpdb->update(
                    $sources_table,
                    [ 'status' => 'error', 'error_message' => $result->get_error_message() ],
                    [ 'id' => $source_id ]
                );
                return false;
            }
        }

        return (int) $source_id;
    }

    /* ================================================================
     * GET ALL REQUESTS — paginated list for admin tracking page
     *
     * @param array $args { page, per_page, memory_tier, memory_type, source, search, order_by, order }
     * @return array { items, total, pages, page }
     * ================================================================ */
    public function get_all_requests( $args = [] ) {
        global $wpdb;

        $args = wp_parse_args( $args, [
            'page'        => 1,
            'per_page'    => 30,
            'memory_tier' => '',
            'memory_type' => '',
            'source'      => '',   // 'text', 'file', 'url'
            'search'      => '',
            'order_by'    => 'created_at',
            'order'       => 'DESC',
            'blog_id'     => get_current_blog_id(),
        ] );

        $table  = self::table();
        $where  = [ 'blog_id = %d' ];
        $params = [ (int) $args['blog_id'] ];

        if ( ! empty( $args['memory_tier'] ) ) {
            $where[]  = 'memory_tier = %s';
            $params[] = $args['memory_tier'];
        }

        if ( ! empty( $args['memory_type'] ) ) {
            $where[]  = 'memory_type = %s';
            $params[] = $args['memory_type'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where[]  = 'memory_text LIKE %s';
            $params[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }

        if ( ! empty( $args['source'] ) ) {
            switch ( $args['source'] ) {
                case 'file':
                    $where[] = "metadata LIKE '%source_file%'";
                    break;
                case 'url':
                    $where[] = "metadata LIKE '%source_url%'";
                    break;
                case 'text':
                    $where[] = "metadata NOT LIKE '%source_file%' AND metadata NOT LIKE '%source_url%'";
                    break;
            }
        }

        $where_sql = implode( ' AND ', $where );
        $allowed   = [ 'created_at', 'updated_at', 'score', 'times_seen', 'memory_type' ];
        $order_by  = in_array( $args['order_by'], $allowed, true ) ? $args['order_by'] : 'created_at';
        $order     = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Total count
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
            ...$params
        ) );

        // Paginated results
        $offset   = max( 0, ( (int) $args['page'] - 1 ) * (int) $args['per_page'] );
        $sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d";
        $params[] = (int) $args['per_page'];
        $params[] = $offset;

        $items = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        return [
            'items' => $items ?: [],
            'total' => $total,
            'pages' => (int) ceil( $total / max( 1, (int) $args['per_page'] ) ),
            'page'  => (int) $args['page'],
        ];
    }
}
