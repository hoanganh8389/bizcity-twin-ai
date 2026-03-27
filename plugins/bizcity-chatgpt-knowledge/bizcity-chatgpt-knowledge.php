<?php
/**
 * Plugin Name:       ChatGPT Knowledge – Trợ lý Kiến thức AI
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-chatgpt-knowledge
 *
 * Description:       AI Agent chuyên trả lời kiến thức chuyên sâu — powered by OpenAI ChatGPT (GPT-4o). Override knowledge pipeline bằng ChatGPT cho câu trả lời dài, đầy đủ. Tạo chủ đề kiến thức, hỗ trợ reasoning, phân tích sâu.
 * Short Description: Chat AI trả lời kiến thức chuyên sâu bằng OpenAI ChatGPT — phân tích, giải thích, reasoning.
 * Quick View:        🤖 Hỏi bất kỳ → ChatGPT trả lời chuyên sâu → Lưu chủ đề kiến thức
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Chu Hoàng Anh
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-chatgpt-knowledge
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/12220-original1.jpg
 * Template Page:     chatgpt-knowledge
 * Plan:              pro
 * Category:          knowledge, productivity, ai
 * Tags:              chatgpt, openai, knowledge, kiến thức, AI, GPT-4o, reasoning, phân tích, research
 *
 * === Giới thiệu ===
 * ChatGPT Knowledge là AI Agent chuyên trả lời kiến thức chuyên sâu, tích hợp
 * trực tiếp vào luồng chat BizCity. Powered by OpenAI GPT-4o — reasoning mạnh,
 * phân tích đa chiều, trả lời dài và đầy đủ. Override knowledge pipeline mặc định.
 *
 * === Tính năng chính ===
 * • Trả lời kiến thức chuyên sâu bằng OpenAI ChatGPT (GPT-4o)
 * • Reasoning & phân tích sâu — giải thích từng bước
 * • Quản lý chủ đề kiến thức — tạo, lưu, xem lại lịch sử
 * • Tích hợp Knowledge binding — gắn context tùy chỉnh cho mỗi chủ đề
 * • Override knowledge pipeline mặc định khi được kích hoạt
 * • Giao diện Knowledge Studio tại /chatgpt-knowledge/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenAI API key (GPT-4o) qua BizCity Gateway
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Agent tự đăng ký vào Knowledge pipeline.
 * Truy cập /chatgpt-knowledge/ để mở Knowledge Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>ChatGPT Knowledge</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */

define( 'BZCK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZCK_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZCK_VERSION', '1.0.0' );
define( 'BZCK_SLUG',    'bizcity-chatgpt-knowledge' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BZCK_DIR . 'includes/install.php';
require_once BZCK_DIR . 'includes/topics.php';
require_once BZCK_DIR . 'includes/admin-menu.php';
require_once BZCK_DIR . 'includes/admin-dashboard.php';
require_once BZCK_DIR . 'includes/ajax.php';
require_once BZCK_DIR . 'includes/shortcode.php';
require_once BZCK_DIR . 'includes/integration-chat.php';
require_once BZCK_DIR . 'includes/knowledge-binding.php';
require_once BZCK_DIR . 'includes/class-chatgpt-knowledge.php';
require_once BZCK_DIR . 'includes/class-tools-chatgpt-knowledge.php';
require_once BZCK_DIR . 'views/chatgpt-knowledge-landing.php';

/* ═══════════════════════════════════════════════
   INTENT PROVIDER — Goal patterns + Plans + Tools
   Follow bizcity-tool-content pattern (PLUGIN-STANDARD.md)
   ═══════════════════════════════════════════════ */

add_action( 'bizcity_intent_register_providers', function( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) {
        // Fallback: class-based provider if register_plugin helper not available
        if ( class_exists( 'BizCity_Intent_Provider' ) ) {
            require_once BZCK_DIR . 'includes/class-intent-provider.php';
            $registry->register( new BizCity_ChatGPT_Knowledge_Intent_Provider() );
        }
        return;
    }

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'chatgpt-knowledge',
        'name' => 'ChatGPT Knowledge — Trợ lý Kiến thức AI (OpenAI)',

        /* ── Goal patterns (Router) ── */
        'patterns' => [
            '/hỏi chatgpt|chatgpt trả lời|hỏi gpt|gpt trả lời|ask chatgpt|chatgpt cho tôi biết|nhờ chatgpt'
            . '|chatgpt giải thích|chatgpt phân tích|dùng chatgpt|chatgpt viết|chatgpt tạo|chatgpt giúp'
            . '|tìm hiểu.*chatgpt|nghiên cứu.*chatgpt|học.*chatgpt|tổng hợp.*chatgpt|phân tích.*chatgpt'
            . '|chatgpt.*tìm hiểu|chatgpt.*nghiên cứu|chatgpt.*học|chatgpt.*tổng hợp|chatgpt.*phân tích'
            . '|hỏi gpt|dùng gpt|nhờ gpt|gpt giải thích|gpt phân tích/ui' => [
                'goal'        => 'ask_chatgpt',
                'label'       => 'Hỏi ChatGPT',
                'description' => 'Dùng ChatGPT (OpenAI) để hỏi, tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích BẤT KỲ nội dung nào',
                'extract'     => [ 'question' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ── */
        'plans' => [
            'ask_chatgpt' => [
                'required_slots' => [
                    'question' => [
                        'type'   => 'text',
                        'label'  => 'Câu hỏi / chủ đề cần tìm hiểu cụ thể. KHÔNG phải câu lệnh.',
                        'prompt' => '❓ Bạn muốn hỏi ChatGPT điều gì? Gõ câu hỏi của bạn:',
                    ],
                ],
                'optional_slots' => [],
                'tool'       => 'ask_chatgpt',
                'ai_compose' => false,
                'slot_order' => [ 'question' ],
            ],
        ],

        /* ── Tools (callbacks) ── */
        'tools' => [
            'ask_chatgpt' => [
                'schema' => [
                    'description'  => 'Hỏi ChatGPT (OpenAI) — tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích kiến thức chuyên sâu',
                    'input_fields' => [
                        'question' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tools_ChatGPT_Knowledge', 'ask_chatgpt' ],
            ],
        ],

        /* ── Context (inject domain knowledge into AI prompt) ── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $ctx_parts = [];

            // User search preferences
            $pref = get_user_meta( $user_id, 'bzck_preferences', true );
            if ( $pref && is_array( $pref ) ) {
                if ( ! empty( $pref['expertise_level'] ) ) {
                    $levels = [ 'beginner' => 'Cơ bản', 'intermediate' => 'Trung cấp', 'advanced' => 'Nâng cao' ];
                    $ctx_parts[] = "Trình độ người dùng: " . ( $levels[ $pref['expertise_level'] ] ?? $pref['expertise_level'] );
                }
                if ( ! empty( $pref['interests'] ) ) {
                    $ctx_parts[] = "Lĩnh vực quan tâm: {$pref['interests']}";
                }
            }

            // Recent search topics for conversation continuity
            global $wpdb;
            $table = $wpdb->prefix . 'bzck_search_history';
            if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
                $recent = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT query_text FROM {$table}
                     WHERE user_id = %d ORDER BY created_at DESC LIMIT 5",
                    $user_id
                ) );
                if ( $recent ) {
                    $ctx_parts[] = "Chủ đề đã hỏi gần đây: " . implode( ', ', $recent );
                }
            }

            return $ctx_parts ? implode( "\n", $ctx_parts ) : '';
        },

        /* ── System instructions ── */
        'instructions' => function ( $goal ) {
            return 'Bạn là trợ lý kiến thức AI chuyên sâu, powered by ChatGPT (OpenAI GPT-4o). '
                 . 'Trả lời chi tiết, đầy đủ, có cấu trúc. Dùng headings, bullet points, ví dụ cụ thể. '
                 . 'Trả lời bằng tiếng Việt.';
        },

        /* ── Knowledge character binding ── */
        'knowledge_character_id' => function_exists( 'bzck_get_knowledge_character_id' )
            ? bzck_get_knowledge_character_id() : 0,
    ] );
} );

/* ═══════════════════════════════════════════════
   KNOWLEDGE PIPELINE — Loaded but NOT registered as provider.
   ChatGPT now operates as an execution tool (via Intent Provider
   patterns like "dùng chatgpt", "hỏi chatgpt").
   Knowledge mode uses the system's built-in OpenRouter pipeline.
   ═══════════════════════════════════════════════ */

if ( class_exists( 'BizCity_Mode_Pipeline' ) ) {
    require_once BZCK_DIR . 'includes/class-chatgpt-knowledge-pipeline.php';
    // Pipeline class loaded for potential direct use by tools,
    // but NOT registered as a knowledge provider — keeping knowledge mode built-in.
}

/* ═══════════════════════════════════════════════
   MODE CLASSIFIER PATTERNS — REMOVED
   ChatGPT Knowledge no longer overrides knowledge mode.
   Broad knowledge queries now stay in the built-in pipeline.
   Explicit mentions ("dùng chatgpt", "hỏi chatgpt") are handled
   by the Intent Provider goals → execution mode.
   ═══════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════
   AGENT PAGE: /chatgpt-knowledge/ frontend
   ═══════════════════════════════════════════════ */

add_action( 'init', function() {
    add_rewrite_rule( '^chatgpt-knowledge/?$', 'index.php?bizcity_agent_page=chatgpt-knowledge', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'chatgpt-knowledge' ) {
        include BZCK_DIR . 'views/page-chatgpt-knowledge-full.php';
        exit;
    }
} );

/* ═══════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
   ═══════════════════════════════════════════════ */

register_activation_hook( __FILE__, 'bzck_activate' );
function bzck_activate() {
    bzck_install_tables();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bzck_deactivate' );
function bzck_deactivate() {
    flush_rewrite_rules();
}

/* ── DB migration ── */
add_action( 'admin_init', 'bzck_maybe_migrate_db' );
function bzck_maybe_migrate_db() {
    if ( get_option( 'bzck_db_version' ) === BZCK_VERSION ) return;
    bzck_install_tables();
    update_option( 'bzck_db_version', BZCK_VERSION );
}

/* ═══════════════════════════════════════════════
   REGISTER ASSETS
   ═══════════════════════════════════════════════ */

add_action( 'init', 'bzck_register_assets' );
function bzck_register_assets() {
    wp_register_style(  'bzck-public',  BZCK_URL . 'assets/public.css',  [], BZCK_VERSION );
    wp_register_script( 'bzck-public',  BZCK_URL . 'assets/public.js',   [ 'jquery' ], BZCK_VERSION, true );
    wp_register_style(  'bzck-admin',   BZCK_URL . 'assets/admin.css',   [], BZCK_VERSION );
    wp_register_script( 'bzck-admin',   BZCK_URL . 'assets/admin.js',    [ 'jquery', 'wp-util' ], BZCK_VERSION, true );
}

add_action( 'admin_enqueue_scripts', 'bzck_enqueue_admin' );
function bzck_enqueue_admin( $hook ) {
    if ( strpos( $hook, BZCK_SLUG ) === false ) return;
    wp_enqueue_style( 'bzck-admin' );
    wp_enqueue_script( 'bzck-admin' );
    wp_localize_script( 'bzck-admin', 'BZCK', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bzck_nonce' ),
    ] );
}
