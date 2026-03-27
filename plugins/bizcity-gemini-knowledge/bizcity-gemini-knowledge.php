<?php
/**
 * Plugin Name:       Gemini Knowledge – Trợ lý Kiến thức AI
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-gemini-knowledge
 *
 * Description:       AI Agent chuyên trả lời kiến thức chuyên sâu, tìm kiếm thông tin, giải thích chi tiết — powered by Google Gemini. Thay thế knowledge pipeline mặc định bằng Gemini cho câu trả lời dài, đầy đủ. Tạo chủ đề kiến thức, lưu lịch sử, tích hợp trực tiếp vào chat.
 * Short Description: Chat AI trả lời kiến thức chuyên sâu bằng Google Gemini — tìm kiếm, giải thích, phân tích chi tiết.
 * Quick View:        🧠 Hỏi bất kỳ → Gemini AI trả lời chuyên sâu → Lưu chủ đề kiến thức
 * Version:           1.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Chu Hoàng Anh
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-gemini-knowledge
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/Gemini-3-co-gi-moi-61.jpg
 * Template Page:     gemini-knowledge
 * Plan:              free
 * Category:          knowledge, productivity, ai
 * Tags:              gemini, knowledge, kiến thức, AI, tìm kiếm, giải thích, trả lời, research, Google
 *
 * === Giới thiệu ===
 * Gemini Knowledge là AI Agent chuyên trả lời kiến thức chuyên sâu, tích hợp
 * trực tiếp vào luồng chat BizCity. Powered by Google Gemini — trả lời dài,
 * đầy đủ, có nguồn tham khảo. Thay thế knowledge pipeline mặc định.
 *
 * === Tính năng chính ===
 * • Trả lời kiến thức chuyên sâu bằng Google Gemini (Gemini 2.5 Flash)
 * • Tìm kiếm thông tin, giải thích chi tiết, phân tích đa chiều
 * • Quản lý chủ đề kiến thức — tạo, lưu, xem lại lịch sử
 * • Tích hợp Knowledge binding — gắn context tùy chỉnh cho mỗi chủ đề
 * • Override knowledge pipeline mặc định khi được kích hoạt
 * • Giao diện Knowledge Studio tại /gemini-knowledge/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • Google Gemini API key (qua BizCity Gateway hoặc OpenRouter)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Agent tự đăng ký vào Knowledge pipeline.
 * Truy cập /gemini-knowledge/ để mở Knowledge Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Gemini Knowledge</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */

define( 'BZGK_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZGK_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZGK_VERSION', '1.1.0' );
define( 'BZGK_SLUG',    'bizcity-gemini-knowledge' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BZGK_DIR . 'includes/install.php';
require_once BZGK_DIR . 'includes/topics.php';
require_once BZGK_DIR . 'includes/admin-menu.php';
require_once BZGK_DIR . 'includes/admin-dashboard.php';
require_once BZGK_DIR . 'includes/ajax.php';
require_once BZGK_DIR . 'includes/shortcode.php';
require_once BZGK_DIR . 'includes/integration-chat.php';
require_once BZGK_DIR . 'includes/knowledge-binding.php';
require_once BZGK_DIR . 'includes/class-gemini-knowledge.php';
require_once BZGK_DIR . 'includes/class-tools-gemini-knowledge.php';
require_once BZGK_DIR . 'views/gemini-knowledge-landing.php';

/* ═══════════════════════════════════════════════
   INTENT PROVIDER — Goal patterns + Plans + Tools
   Follow bizcity-tool-content pattern (PLUGIN-STANDARD.md)
   ═══════════════════════════════════════════════ */

add_action( 'bizcity_intent_register_providers', function( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) {
        // Fallback: class-based provider if register_plugin helper not available
        if ( class_exists( 'BizCity_Intent_Provider' ) ) {
            require_once BZGK_DIR . 'includes/class-intent-provider.php';
            $registry->register( new BizCity_Gemini_Knowledge_Intent_Provider() );
        }
        return;
    }

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'gemini-knowledge',
        'name' => 'Gemini Knowledge — Trợ lý Kiến thức AI (Google)',

        /* ── Goal patterns (Router) ── */
        'patterns' => [
            '/hỏi gemini|gemini trả lời|google trả lời|ask gemini|gemini cho tôi biết|nhờ gemini'
            . '|gemini giải thích|gemini phân tích|dùng gemini|google gemini|gemini viết|gemini tạo|gemini giúp'
            . '|tìm hiểu.*gemini|nghiên cứu.*gemini|học.*gemini|tổng hợp.*gemini|phân tích.*gemini'
            . '|gemini.*tìm hiểu|gemini.*nghiên cứu|gemini.*học|gemini.*tổng hợp|gemini.*phân tích/ui' => [
                'goal'        => 'ask_gemini',
                'label'       => 'Hỏi Gemini',
                'description' => 'Dùng Google Gemini để hỏi, tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích BẤT KỲ nội dung nào',
                'extract'     => [ 'question' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ── */
        'plans' => [
            'ask_gemini' => [
                'required_slots' => [
                    'question' => [
                        'type'   => 'text',
                        'label'  => 'Câu hỏi / chủ đề cần tìm hiểu cụ thể. KHÔNG phải câu lệnh.',
                        'prompt' => '❓ Bạn muốn hỏi Gemini điều gì? Gõ câu hỏi của bạn:',
                    ],
                ],
                'optional_slots' => [],
                'tool'       => 'ask_gemini',
                'ai_compose' => false,
                'slot_order' => [ 'question' ],
            ],
        ],

        /* ── Tools (callbacks) ── */
        'tools' => [
            'ask_gemini' => [
                'schema' => [
                    'description'  => 'Hỏi Google Gemini — tìm hiểu, nghiên cứu, học tập, tổng hợp, phân tích kiến thức chuyên sâu',
                    'input_fields' => [
                        'question' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tools_Gemini_Knowledge', 'ask_gemini' ],
            ],
        ],

        /* ── Context (inject domain knowledge into AI prompt) ── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $ctx_parts = [];

            // User search preferences
            $pref = get_user_meta( $user_id, 'bzgk_preferences', true );
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
            $table = $wpdb->prefix . 'bzgk_search_history';
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
            return 'Bạn là trợ lý kiến thức AI chuyên sâu, powered by Google Gemini. '
                 . 'Trả lời chi tiết, đầy đủ, có cấu trúc. Dùng headings, bullet points, ví dụ cụ thể. '
                 . 'Trả lời bằng tiếng Việt.';
        },

        /* ── Knowledge character binding ── */
        'knowledge_character_id' => function_exists( 'bzgk_get_knowledge_character_id' )
            ? bzgk_get_knowledge_character_id() : 0,
    ] );
} );

/* ═══════════════════════════════════════════════
   KNOWLEDGE PIPELINE — Loaded but NOT registered as provider.
   Gemini now operates as an execution tool (via Intent Provider
   patterns like "dùng gemini", "hỏi gemini").
   Knowledge mode uses the system's built-in OpenRouter pipeline.
   ═══════════════════════════════════════════════ */

if ( class_exists( 'BizCity_Mode_Pipeline' ) ) {
    require_once BZGK_DIR . 'includes/class-gemini-knowledge-pipeline.php';
    // Pipeline class loaded for potential direct use by tools,
    // but NOT registered as a knowledge provider — keeping knowledge mode built-in.
}

/* ═══════════════════════════════════════════════
   MODE CLASSIFIER PATTERNS — REMOVED
   Gemini Knowledge no longer overrides knowledge mode.
   Broad knowledge queries now stay in the built-in pipeline.
   Explicit mentions ("dùng gemini", "hỏi gemini") are handled
   by the Intent Provider goals → execution mode.
   ═══════════════════════════════════════════════ */

/* ═══════════════════════════════════════════════
   AGENT PAGE: /gemini-knowledge/ frontend
   ═══════════════════════════════════════════════ */

add_action( 'init', function() {
    add_rewrite_rule( '^gemini-knowledge/?$', 'index.php?bizcity_agent_page=gemini-knowledge', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'gemini-knowledge' ) {
        include BZGK_DIR . 'views/page-gemini-knowledge-full.php';
        exit;
    }
} );

/* ═══════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
   ═══════════════════════════════════════════════ */

register_activation_hook( __FILE__, 'bzgk_activate' );
function bzgk_activate() {
    bzgk_install_tables();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bzgk_deactivate' );
function bzgk_deactivate() {
    flush_rewrite_rules();
}

/* ── DB migration ── */
add_action( 'admin_init', 'bzgk_maybe_migrate_db' );
function bzgk_maybe_migrate_db() {
    if ( get_option( 'bzgk_db_version' ) === BZGK_VERSION ) return;
    bzgk_install_tables();
    update_option( 'bzgk_db_version', BZGK_VERSION );
}

/* ═══════════════════════════════════════════════
   REGISTER ASSETS
   ═══════════════════════════════════════════════ */

add_action( 'init', 'bzgk_register_assets' );
function bzgk_register_assets() {
    wp_register_style(  'bzgk-public',  BZGK_URL . 'assets/public.css',  [], BZGK_VERSION );
    wp_register_script( 'bzgk-public',  BZGK_URL . 'assets/public.js',   [ 'jquery' ], BZGK_VERSION, true );
    wp_register_style(  'bzgk-admin',   BZGK_URL . 'assets/admin.css',   [], BZGK_VERSION );
    wp_register_script( 'bzgk-admin',   BZGK_URL . 'assets/admin.js',    [ 'jquery', 'wp-util' ], BZGK_VERSION, true );
}

add_action( 'admin_enqueue_scripts', 'bzgk_enqueue_admin' );
function bzgk_enqueue_admin( $hook ) {
    if ( strpos( $hook, BZGK_SLUG ) === false ) return;
    wp_enqueue_style( 'bzgk-admin' );
    wp_enqueue_script( 'bzgk-admin' );
    wp_localize_script( 'bzgk-admin', 'BZGK', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bzgk_nonce' ),
    ] );
}
