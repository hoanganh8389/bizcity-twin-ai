<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Plugins\Companion_Notebook
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Plugin Name:       BizCity Companion Notebook
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-companion-notebook
 * Description:       NotebookLM-style Knowledge Companion — tải tài liệu, chat AI, ghi chú thông minh, tạo nội dung Studio (mindmap, slide, bài viết, quiz…).
 * Short Description: Chat AI với tài liệu của bạn, tạo ghi chú & nội dung Studio.
 * Quick View:        📓 Upload tài liệu → Chat AI → Ghi chú & Studio
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-companion-notebook
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/ai.png
 * Cover URI:         https://thumbs.dreamstime.com/b/ai-generated-back-to-school-colorful-study-time-still-life-notebook-pencils-erasers-ruler-scissors-back-to-school-flat-395860541.jpg
 * Template Page:     note
 * Admin Slug:        bizcity-notebook
 * Plan:              free
 * Category:          knowledge, notebook, AI, research
 * Tags:              notebook, tài liệu, ghi chú, AI, chat, studio, mindmap, slide, quiz, research, knowledge
 *
 * === Giới thiệu ===
 * BizCity Companion Notebook là trãi nghiệm NotebookLM-style ngay trong
 * nền tảng BizCity. Tải tài liệu lên, chat với AI về nội dung, ghi chú
 * thông minh và tạo nội dung Studio đa dạng.
 *
 * === Tính năng chính ===
 * • Upload tài liệu (PDF, DOCX, TXT) — AI đọc & phân tích
 * • Chat AI về nội dung tài liệu — hỏi đáp, tóm tắt, phân tích
 * • Ghi chú thông minh — highlight, tag, liên kết
 * • Studio tools: tạo mindmap, slide, bài viết, quiz từ dự án
 * • Quản lý dự án (notebook) — nhóm tài liệu, ghi chú, nguồn tham khảo
 * • Giao diện Notebook Studio tại /note/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • Các tool plugin (mindmap, slide, pdf…) để tạo nội dung Studio
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Truy cập /note/ để mở Notebook Studio.
 * Upload tài liệu và bắt đầu chat với AI.
 *
 * @package BizCity_Companion_Notebook
 */

defined( 'ABSPATH' ) || exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Companion Notebook</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BCN_VERSION', '1.0.0' );
define( 'BCN_PLUGIN_FILE', __FILE__ );
define( 'BCN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BCN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BCN_INCLUDES', BCN_PLUGIN_DIR . 'includes/' );

// Load all classes.
$bcn_classes = [
    'class-schema-extend.php',
    'class-projects.php',
    'class-sources.php',
    'class-source-extractor.php',
    'class-chunker.php',
    'class-embedder.php',
    'class-notes.php',
    'class-research-memory.php',
    'class-messages.php',
    'class-intent-detector.php',
    'class-chat-engine.php',
    'class-notebook-tool-registry.php',
    'class-studio-input-builder.php',
    'class-studio.php',
    'class-studio-tools-content.php',
    'class-research-ranker.php',
    'class-deep-research.php',
    'class-rest-api.php',
    'class-ajax-handler.php',
    'class-admin-page.php',
    'class-core-bridge.php',
    'class-cron.php',
    'class-notebook-plugin.php',
];
foreach ( $bcn_classes as $file ) {
    $path = BCN_INCLUDES . $file;
    if ( file_exists( $path ) ) {
        require_once $path;
    }
}

// Boot.
BCN_Plugin::instance()->boot();

// Boot Notebook Tool Registry (hook-based tool registration).
add_action( 'plugins_loaded', [ 'BCN_Notebook_Tool_Registry', 'boot' ], 20 );

// Boot Research Memory hooks (inject research context into /chat/).
add_action( 'plugins_loaded', function() {
    if ( class_exists( 'BCN_Research_Memory' ) ) {
        BCN_Research_Memory::instance()->register_hooks();
    }
}, 25 );

// Activation / Deactivation.
register_activation_hook( __FILE__, [ BCN_Plugin::instance(), 'activate' ] );
register_deactivation_hook( __FILE__, [ BCN_Plugin::instance(), 'deactivate' ] );

// Helper functions.
function bcn_has_knowledge() {
    return class_exists( 'BizCity_Knowledge' ) || function_exists( 'bizcity_knowledge_active' );
}
function bcn_has_intent() {
    return class_exists( 'BizCity_Intent_Engine' ) || function_exists( 'bizcity_intent_active' );
}


/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Notebook SPA at /note/
 *  Auto-creates a WP page with slug "note" and custom template.
 *  Same pattern as /chat/ page in bizcity-bot-webchat.
 * ══════════════════════════════════════════════════════════════ */

// Auto-create the /note/ page if it doesn't exist.
add_action( 'init', function() {
    $existing = get_page_by_path( 'note' );
    $needs_flush = false;

    if ( $existing ) {
        if ( $existing->post_status !== 'publish' ) {
            wp_update_post( [
                'ID'          => $existing->ID,
                'post_status' => 'publish',
            ] );
            $needs_flush = true;
        }
        if ( get_post_meta( $existing->ID, '_wp_page_template', true ) !== 'bcn-notebook-spa' ) {
            update_post_meta( $existing->ID, '_wp_page_template', 'bcn-notebook-spa' );
        }
    } else {
        $page_id = wp_insert_post( [
            'post_title'   => 'Notebook',
            'post_name'    => 'note',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- Notebook SPA managed by BizCity Companion Notebook -->',
        ] );
        if ( $page_id && ! is_wp_error( $page_id ) ) {
            update_post_meta( $page_id, '_wp_page_template', 'bcn-notebook-spa' );
            $needs_flush = true;
        }
    }

    if ( $needs_flush ) {
        flush_rewrite_rules();
    }

    // SPA sub-routes: /note/project/123 still loads the same page.
    add_rewrite_rule( '^note/(.+?)/?$', 'index.php?pagename=note', 'top' );

    // Auto-flush rewrite rules once when the rule doesn't exist yet.
    $rules = get_option( 'rewrite_rules', [] );
    if ( is_array( $rules ) && ! isset( $rules['^note/(.+?)/?$'] ) ) {
        flush_rewrite_rules( false );
    }
}, 20 );

// Register page template in the template dropdown.
add_filter( 'theme_page_templates', function( $templates ) {
    $templates['bcn-notebook-spa'] = 'Notebook SPA (BizCity)';
    return $templates;
} );

// Load the custom template file when selected.
add_filter( 'template_include', function( $template ) {
    if ( is_page() ) {
        $page_template = get_post_meta( get_the_ID(), '_wp_page_template', true );
        if ( 'bcn-notebook-spa' === $page_template ) {
            $custom = BCN_PLUGIN_DIR . 'templates/page-note.php';
            if ( file_exists( $custom ) ) {
                return $custom;
            }
        }
    }
    return $template;
} );
