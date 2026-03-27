<?php
/**
 * Plugin Name:       Tarot AI – Xáo Bài Tarot Online
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tarot
 *
 * Description:       AI Agent xem bài Tarot 3 lá online. Xáo bài, rút bài, AI giải nghĩa chi tiết theo hoàn cảnh câu hỏi. Bộ bài 78 lá đầy đủ, quản lý lịch sử bói, shortcode [bizcity_tarot] nhúng vào bất kỳ trang nào.
 * Short Description: Rút 3 lá Tarot → AI giải nghĩa theo câu hỏi — bộ bài 78 lá, lịch sử xem bài.
 * Quick View:        🔮 Đặt câu hỏi → Rút 3 lá Tarot → AI giải nghĩa chi tiết
 * Version:           1.0.4
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Chu Hoàng Anh
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tarot
 * Role:              agent
 * Featured:          true
 * Credit:            100
 * Price:             1000000
 * Icon Path:         assets/tarot-card.png
 * Cover URI:         https://media.bizcity.vn/uploads/2026/02/1708556142243.jpg
 * Template Page:     tarot-profile
 * Plan:              pro
 * Category:          entertainment, lifestyle, astrology
 * Tags:              tarot, bói bài, xem bài, tâm linh, giải nghĩa, AI agent, 78 lá, fortune, divination
 *
 * === Giới thiệu ===
 * Tarot AI là trải nghiệm bói bài Tarot 3 lá trực tuyến, tích hợp AI giải nghĩa
 * chuyên sâu. Bộ bài 78 lá đầy đủ (22 Major Arcana + 56 Minor Arcana) với hình ảnh
 * chi tiết và ý nghĩa mỗi lá.
 *
 * === Tính năng chính ===
 * • Xáo bài & rút 3 lá Tarot với animation trực quan
 * • AI giải nghĩa từng lá theo hoàn cảnh câu hỏi của người dùng
 * • Bộ bài 78 lá — quản lý chi tiết, ý nghĩa ngược & xuôi
 * • Lưu lịch sử xem bài — xem lại, so sánh theo thời gian
 * • Shortcode [bizcity_tarot] nhúng vào bất kỳ trang / bài viết
 * • Tích hợp Chat: hỏi bói qua chat → AI rút bài & giải nghĩa
 * • Trang Tarot Studio tại /tarot-profile/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenAI hoặc Gemini API key (qua BizCity Gateway)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Vào BizCity > Tarot AI để quản lý bộ bài.
 * Truy cập /tarot-profile/ để mở Tarot Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Tarot AI</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ---------------------------------------------------------------
 * CONSTANTS
 * ------------------------------------------------------------- */
define( 'BCT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BCT_URL',     plugin_dir_url( __FILE__ ) );
define( 'BCT_VERSION', '1.0.7' );
define( 'BCT_SLUG',    'bizcity-tarot' );

/* ---------------------------------------------------------------
 * AUTOLOAD INCLUDES
 * ------------------------------------------------------------- */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BCT_DIR . 'includes/install.php';
require_once BCT_DIR . 'includes/topics.php';
require_once BCT_DIR . 'includes/admin-menu.php';
require_once BCT_DIR . 'includes/admin-cards.php';
require_once BCT_DIR . 'includes/admin-crawl.php';
require_once BCT_DIR . 'includes/ajax.php';
require_once BCT_DIR . 'includes/shortcode.php';
require_once BCT_DIR . 'includes/integration-chat.php';
require_once BCT_DIR . 'views/tarot-landing.php';

/* ---- Intent Provider: register Tarot skills with the AI Agent engine ---- */
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
    require_once BCT_DIR . 'includes/class-intent-provider.php';
    add_action( 'bizcity_intent_register_providers', function( $registry ) {
        $registry->register( new BizCity_Tarot_Intent_Provider() );
    } );
}

/* ---------------------------------------------------------------
 * AGENT PROFILE PAGE: /tarot-profile/
 * Trang hồ sơ Tarot frontend — load trong Touch Bar iframe.
 * Slug riêng -profile để không nhầm với landing page /tarot/ do user tạo.
 * ------------------------------------------------------------- */
add_filter( 'template_include', function( $template ) {
    if ( is_page( 'tarot-profile' ) ) {
        $custom = BCT_DIR . 'views/page-tarot-profile.php';
        if ( file_exists( $custom ) ) return $custom;
    }
    return $template;
} );

/* ---------------------------------------------------------------
 * ACTIVATION / DEACTIVATION
 * ------------------------------------------------------------- */
register_activation_hook( __FILE__, 'bct_activate' );
function bct_activate() {
    bct_install_tables();

    // Create profile page if not exists (slug -profile riêng, ko nhầm landing page)
    if ( ! get_page_by_path( 'tarot-profile' ) ) {
        wp_insert_post([
            'post_title'   => 'Hồ sơ Tarot',
            'post_name'    => 'tarot-profile',
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_content' => '<!-- Profile page managed by BizCity Tarot plugin -->',
        ]);
    }

    flush_rewrite_rules();
    delete_option( 'bct_tarot_page_checked' );
}

register_deactivation_hook( __FILE__, 'bct_deactivate' );
function bct_deactivate() {
    flush_rewrite_rules();
}

/* ---------------------------------------------------------------
 * DB MIGRATION  (runs once per version bump via option check)
 * ------------------------------------------------------------- */
add_action( 'admin_init', 'bct_maybe_migrate_db' );
function bct_maybe_migrate_db(): void {
    if ( get_option( 'bct_db_version' ) === BCT_VERSION ) return;
    global $wpdb;
    $t    = bct_tables();
    $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$t['readings']}" );
    if ( ! in_array( 'client_id', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD COLUMN client_id VARCHAR(100) NOT NULL DEFAULT '' AFTER user_id" );
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD INDEX client_id (client_id)" );
    }
    if ( ! in_array( 'platform', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD COLUMN platform VARCHAR(30) NOT NULL DEFAULT '' AFTER client_id" );
        $wpdb->query( "ALTER TABLE {$t['readings']} ADD INDEX platform (platform)" );
    }
    update_option( 'bct_db_version', BCT_VERSION );
}

/* ---------------------------------------------------------------
 * REGISTER ASSETS
 * ------------------------------------------------------------- */
add_action( 'init', 'bct_register_assets' );
function bct_register_assets() {
    wp_register_style(
        'bct-public',
        BCT_URL . 'assets/public.css',
        [],
        BCT_VERSION
    );
    wp_register_script(
        'bct-public',
        BCT_URL . 'assets/public.js',
        [ 'jquery' ],
        BCT_VERSION,
        true
    );
    wp_register_style(
        'bct-admin',
        BCT_URL . 'assets/admin.css',
        [],
        BCT_VERSION
    );
    wp_register_script(
        'bct-admin',
        BCT_URL . 'assets/admin.js',
        [ 'jquery' ],
        BCT_VERSION,
        true
    );
    // Landing page CSS (loaded on demand in shortcode)
    wp_register_style(
        'bct-tarot-landing',
        BCT_URL . 'assets/tarot-landing.css',
        [ 'bct-public' ],
        BCT_VERSION
    );
}

/* ---------------------------------------------------------------
 * ENQUEUE ADMIN ASSETS
 * ------------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', 'bct_enqueue_admin_assets' );
function bct_enqueue_admin_assets( $hook ) {
    if ( strpos( $hook, BCT_SLUG ) === false ) return;
    wp_enqueue_style( 'bct-admin' );
    wp_enqueue_script( 'bct-admin' );
    wp_localize_script( 'bct-admin', 'BCT', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bct_nonce' ),
    ] );
}
