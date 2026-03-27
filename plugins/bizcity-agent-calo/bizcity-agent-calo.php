<?php
/**
 * Plugin Name:       Calo AI – Nhật ký Bữa ăn AI
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-agent-calo
 * Description:       AI Agent thông minh theo dõi nhật ký bữa ăn hàng ngày. Nhận diện thức ăn từ ảnh, tính calo & macro tự động, thống kê dinh dưỡng theo ngày/tuần/tháng. Giao tiếp hoàn toàn qua chat tự nhiên — không cần form, không cần nhập tay.
 * Short Description: Chat để ghi nhật ký ăn uống, AI tự nhận diện ảnh & tính calo cho bạn.
 * Quick View:        📸 Chụp ảnh → AI nhận diện → Ghi nhật ký calo tự động
 * Version:           1.3.0
 * Requires at least: 6.3
 * Requires PHP:      7.3
 * Author:            Chu Hoàng Anh
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-calo
 * Role:              agent
 * Featured:          true
 * Credit:            100
 * Price:             1000000
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/b94695bd-6b76-464e-acbf-138fe15e5c8a1-scaled.png
 * Template Page:     calo
 * Category:          health, lifestyle, personal
 * Tags:              calo, dinh dưỡng, nutrition, food-log, health, AI agent, ảnh thức ăn, bữa ăn, giảm cân, macro
 * Plan:              pro
 *
 * === Giới thiệu ===
 * Calo AI là AI Agent chuyên theo dõi dinh dưỡng, tích hợp trực tiếp vào luồng
 * chat của nền tảng BizCity. Người dùng chỉ cần nhắn tin tự nhiên hoặc gửi ảnh
 * bữa ăn — AI sẽ tự động nhận diện món, ước tính calo, protein, carb, fat và ghi
 * vào nhật ký cá nhân.
 *
 * === Tính năng chính ===
 * • Nhận diện thức ăn từ ảnh (Vision AI) — chụp là xong, không cần gõ tên món
 * • Tính calo & macro (protein / carb / fat) tự động cho từng bữa
 * • Nhật ký theo ngày — xem lại, sửa, xoá bữa ăn bất cứ lúc nào
 * • Thống kê tuần / tháng bằng biểu đồ trực quan ngay trong chat
 * • Đặt mục tiêu calo và nhận cảnh báo khi vượt giới hạn
 * • Tích hợp intent engine — hoạt động mượt trong pipeline đa bước
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • BizCity Webchat (bizcity-bot-webchat) ≥ 3.0.0
 * • OpenAI API key có quyền truy cập GPT-4o (vision)
 *
 * === Hướng dẫn kích hoạt ===
 * Sau khi mua & kích hoạt từ Marketplace, vào BizCity > Calo AI để cấu hình
 * mục tiêu calo mặc định. Truy cập /calo/ để mở Calo Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Calo AI</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZCALO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZCALO_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZCALO_VERSION', '1.3.1' );
define( 'BZCALO_SLUG',    'bizcity-agent-calo' );

require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BZCALO_DIR . 'includes/install.php';
require_once BZCALO_DIR . 'includes/topics.php';
require_once BZCALO_DIR . 'includes/admin-menu.php';
require_once BZCALO_DIR . 'includes/admin-dashboard.php';
require_once BZCALO_DIR . 'includes/ajax.php';
require_once BZCALO_DIR . 'includes/shortcode.php';
require_once BZCALO_DIR . 'includes/integration-chat.php';
require_once BZCALO_DIR . 'includes/knowledge-binding.php';
require_once BZCALO_DIR . 'views/calo-landing.php';

/* ── Intent Provider ── */
if ( class_exists( 'BizCity_Intent_Provider' ) ) {
    require_once BZCALO_DIR . 'includes/class-intent-provider.php';
    add_action( 'bizcity_intent_register_providers', function( $registry ) {
        $registry->register( new BizCity_Calo_Intent_Provider() );
    } );
}

/* ── Extend Mode Classifier: nutrition keywords → execution mode ── */
add_filter( 'bizcity_mode_execution_patterns', function( $patterns ) {
    // IMPORTANT: keys must be regex strings, values must be confidence floats.
    // Using $patterns[] = '/regex/' would create numeric keys (0,1,2...)
    // which score_all_modes() reads as confidence → broken scoring.
    $patterns['/c[âa]n\s*n[ặa]ng|chi[ềe]u\s*cao|BMI|BMR|TDEE/ui']                                       = 0.90;
    $patterns['/calo|calories|dinh\s*d[ưu][ỡo]ng|protein|carbs?|macro/ui']                                = 0.90;
    $patterns['/gi[ảa]m\s*c[âa]n|t[aă]ng\s*c[âa]n|[aă]n\s*ki[êe]ng|diet/ui']                            = 0.88;
    $patterns['/b[ữứ]a\s*[aă]n|ghi\s*b[ữứ]a|nh[ậa]t\s*k[ýy]\s*[aă]n/ui']                               = 0.90;
    $patterns['/b[éeế]o|g[ầa]y|th[ừư]a\s*c[âa]n|thi[ếe]u\s*c[âa]n/ui']                                  = 0.85;
    $patterns['/s[ứu]c\s*kh[ỏo][eẻ]|ch[ếe]\s*[đd][ộo]\s*[aă]n|kh[ẩa]u\s*ph[ầa]n/ui']                    = 0.85;
    $patterns['/th[ốo]ng\s*k[êe]\s*calo|t[ổo]ng\s*calo|b[áa]o\s*c[áa]o\s*dinh/ui']                       = 0.90;
    $patterns['/[aă]n\s*g[ìi]\s*h[ôo]m\s*nay|n[êe]n\s*[aă]n|g[ợo]i\s*[ýy]\s*b[ữứ]a/ui']                 = 0.88;
    $patterns['/[aă]n\s*nh[ưu]|[aă]n\s*c[ơo]m|[aă]n\s*n[àa]y|ng[ưu][ờo]i.*[aă]n|m[óo]n\s*[aă]n/ui']    = 0.85;
    $patterns['/anh\s*[aă]n|em\s*[aă]n|m[ìi]nh\s*[aă]n|t[ôo]i\s*[aă]n|[đd][ãa]\s*[aă]n|v[ừư]a\s*[aă]n/ui'] = 0.90;
    $patterns['/t[ưu]\s*v[ấa]n\s*dinh|h[ưu][ớo]ng\s*d[ẫa]n\s*[aă]n|l[ờo]i\s*khuy[êe]n\s*[aă]n/ui']      = 0.88;
    $patterns['/t[ốo]i\s*nay\s*[aă]n|tr[ưu]a\s*nay\s*[aă]n|s[áa]ng\s*nay\s*[aă]n|n[ấa]u\s*g[ìi]/ui']    = 0.88;
    return $patterns;
} );

/* ── Rewrite: /calo/ → landing page ── */
add_action( 'init', function() {
    add_rewrite_rule( '^calo/?$', 'index.php?bizcity_agent_page=calo', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'calo' ) {
        include BZCALO_DIR . 'views/page-calo-full.php';
        exit;
    }
} );

/* ── Activation / Deactivation ── */
register_activation_hook( __FILE__, 'bzcalo_activate' );
function bzcalo_activate() {
    bzcalo_install_tables();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'bzcalo_deactivate' );
function bzcalo_deactivate() {
    flush_rewrite_rules();
}

/* ── Auto-migrate DB ── */
add_action( 'admin_init', 'bzcalo_maybe_migrate_db' );
function bzcalo_maybe_migrate_db() {
    $current = get_option( 'bzcalo_db_version', '0' );
    if ( $current === BZCALO_VERSION ) return;
    bzcalo_install_tables();
    update_option( 'bzcalo_db_version', BZCALO_VERSION );
}

/* ── Assets ── */
add_action( 'init', 'bzcalo_register_assets' );
function bzcalo_register_assets() {
    wp_register_style(  'bzcalo-public',  BZCALO_URL . 'assets/public.css',  array(), BZCALO_VERSION );
    wp_register_script( 'bzcalo-public',  BZCALO_URL . 'assets/public.js',   array( 'jquery' ), BZCALO_VERSION, true );
    wp_register_style(  'bzcalo-admin',   BZCALO_URL . 'assets/admin.css',   array(), BZCALO_VERSION );
    wp_register_script( 'bzcalo-admin',   BZCALO_URL . 'assets/admin.js',    array( 'jquery', 'wp-util' ), BZCALO_VERSION, true );
}

add_action( 'admin_enqueue_scripts', 'bzcalo_enqueue_admin' );
function bzcalo_enqueue_admin( $hook ) {
    if ( strpos( $hook, BZCALO_SLUG ) === false ) return;
    wp_enqueue_style( 'bzcalo-admin' );
    wp_enqueue_script( 'bzcalo-admin' );
    wp_localize_script( 'bzcalo-admin', 'BZCALO', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bzcalo_nonce' ),
    ) );
}
