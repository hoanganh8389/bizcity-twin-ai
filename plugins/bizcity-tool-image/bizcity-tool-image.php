<?php
/**
 * Plugin Name:       Tạo ảnh AI chuyên nghiệp — BizCity Tool Image
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-image
 * Description:       Tạo ảnh AI chuyên nghiệp từ văn bản bằng FLUX.2, Gemini, Seedream, GPT-5 Image. Kho prompt mẫu, lịch sử tạo ảnh, chia sẻ và upload Media Library.
 * Short Description: Chat gửi prompt → AI tạo ảnh (FLUX.2/Gemini/GPT-5) → Lưu / Chia sẻ / Dùng trong pipeline.
 * Quick View:        🎨 Prompt → AI tạo ảnh FLUX.2/Gemini → Lưu Media / Pipeline
 * Version:           2.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Icon Path:         /assets/icon.png
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/6-fun-and-easy-ways-to-generate-ai-images-for-free-with-flux-11.jpg
 * Template Page:     tool-image
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-image
 * Plan:              free
 * Category:          image, ai, creative, tạo ảnh
 * Tags:              image, FLUX.2, Gemini, Seedream, GPT-5 Image, tạo ảnh, creative, AI tool, generative
 *
 * === Giới thiệu ===
 * BizCity Tool Image là bộ công cụ tạo ảnh AI chuyên nghiệp, tích hợp đa
 * model hàng đầu: FLUX.2, Gemini Imagen, Seedream, GPT-5 Image. Gửi prompt
 * qua chat → AI tạo ảnh → lưu Media Library hoặc dùng trong pipeline.
 *
 * === Tính năng chính ===
 * • Tạo ảnh từ prompt bằng nhiều model: FLUX.2, Gemini, Seedream, GPT-5 Image
 * • Kho prompt mẫu theo chủ đề (sản phẩm, quảng cáo, ảnh bìa, minh họa…)
 * • Lịch sử tạo ảnh — xem lại, tải lại, xóa
 * • Upload vào WordPress Media Library tự động
 * • Pipeline-ready: output image_url cho bước tiếp theo (đăng bài, tạo video…)
 * • Giao diện Image Studio tại /tool-image/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • BizCity Gateway hoặc OpenRouter API
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-image/ để mở Image Studio.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — Image</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */
define( 'BZTIMG_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTIMG_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTIMG_VERSION', '2.0.0' );
define( 'BZTIMG_SLUG',    'tool-image' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

require_once BZTIMG_DIR . 'includes/install.php';
require_once BZTIMG_DIR . 'includes/class-tools-image.php';
require_once BZTIMG_DIR . 'includes/class-ajax-image.php';
require_once BZTIMG_DIR . 'includes/admin-menu.php';
require_once BZTIMG_DIR . 'includes/integration-chat.php';
require_once BZTIMG_DIR . 'includes/class-intent-provider.php';

/* ═══════════════════════════════════════════════
   PILLAR 2 — Intent Provider (bizcity_intent_register_plugin)
   Primary tool: generate_image
   Secondary: list_my_images, generate_product_image
   ═══════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) {
        if ( class_exists( 'BizCity_Intent_Provider' ) ) {
            $registry->register( new BizCity_Tool_Image_Intent_Provider() );
        }
        return;
    }

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-image',
        'name' => 'BizCity Tool — Image AI (FLUX.2 / Gemini / GPT-5)',

        /* ── Goal patterns (Router) ───────────────────── */
        'patterns' => [
            /* List images */
            '/danh sách ảnh|list.*image|ảnh.*gần đây|ảnh.*của tôi|my.*image|xem.*ảnh.*đã tạo/ui' => [
                'goal'        => 'list_my_images',
                'label'       => 'Xem danh sách ảnh đã tạo',
                'description' => 'Liệt kê các ảnh AI đã tạo gần đây',
                'extract'     => [ 'limit' ],
            ],
            /* Primary: Generate image — auto-detect purpose from prompt */
            '/tạo ảnh|vẽ ảnh|tạo hình|generate image|tạo hình ảnh|vẽ|minh họa|sinh ảnh|image.*ai|ảnh.*ai|flux.*ảnh|dall.?e|gpt.*image|render.*ảnh|tao anh|ve anh|ảnh.*flux|ảnh.*gemini|seedream|ảnh sản phẩm|product.*image|ảnh.*bán hàng|chân dung|portrait/ui' => [
                'goal'        => 'generate_image',
                'label'       => 'Tạo ảnh AI',
                'description' => 'Tạo ảnh AI — tự nhận diện mục đích (sản phẩm / chân dung / phong cảnh / social / ẩm thực) và enhance prompt phù hợp',
                'extract'     => [ 'prompt', 'purpose', 'size', 'style' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ──────────── */
        'plans' => [
            'generate_image' => [
                'required_slots' => [
                    'prompt' => [
                        'type'   => 'text',
                        'prompt' => '🎨 Mô tả ảnh bạn muốn tạo (chi tiết phong cách, màu sắc, bố cục):',
                    ],
                ],
                'optional_slots' => [
                    'image_url' => [
                        'type'    => 'image',
                        'prompt'  => '📷 Gửi ảnh tham chiếu hoặc dán link ảnh (hoặc bỏ qua):',
                        'default' => '',
                    ],
                    'purpose' => [
                        'type'    => 'choice',
                        'choices' => [
                            'product'   => '📦 Sản phẩm (studio, styled scene, luxury)',
                            'portrait'  => '👤 Chân dung (bokeh, studio)',
                            'landscape' => '🌄 Phong cảnh (golden hour, vivid)',
                            'social'    => '📱 Social Media (bold, trending)',
                            'food'      => '🍜 Ẩm thực (flat lay, warm tones)',
                            'general'   => '🎨 Khác / Tự do',
                        ],
                        'prompt'  => 'Bạn muốn tạo ảnh cho mục đích gì?',
                        'default' => '',
                    ],
                    'size' => [
                        'type'    => 'choice',
                        'choices' => [
                            '1024x1024' => '1:1 Vuông',
                            '1024x1536' => '2:3 Dọc',
                            '1536x1024' => '3:2 Ngang',
                            '768x1344'  => '9:16 Story',
                            '1344x768'  => '16:9 Landscape',
                        ],
                        'prompt'  => 'Kích thước ảnh:',
                        'default' => '1024x1024',
                    ],
                    'style' => [
                        'type'    => 'choice',
                        'choices' => [
                            'auto'          => 'Tự động',
                            'photorealistic' => 'Chân thực',
                            'artistic'       => 'Nghệ thuật',
                            'anime'          => 'Anime',
                            'illustration'   => 'Minh họa',
                        ],
                        'prompt'  => 'Phong cách:',
                        'default' => 'auto',
                    ],
                ],
                'tool'       => 'generate_image',
                'ai_compose' => false,
                'slot_order' => [ 'prompt', 'purpose', 'image_url' ],
            ],
            'list_my_images' => [
                'required_slots' => [],
                'optional_slots' => [
                    'limit' => [ 'type' => 'choice', 'choices' => [ '5' => '5 ảnh', '10' => '10 ảnh', '20' => '20 ảnh' ], 'default' => '10' ],
                ],
                'tool'       => 'list_my_images',
                'ai_compose' => false,
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'generate_image' => [
                'schema' => [
                    'description'  => 'Tạo ảnh AI — tự nhận diện mục đích (sản phẩm / chân dung / phong cảnh / social / ẩm thực) và enhance prompt phù hợp. Model mặc định từ cài đặt.',
                    'input_fields' => [
                        'prompt'    => [ 'required' => true,  'type' => 'text',   'description' => 'Mô tả chi tiết ảnh cần tạo' ],
                        'purpose'   => [ 'required' => false, 'type' => 'choice', 'description' => 'Mục đích: product, portrait, landscape, social, food, general (tự detect nếu bỏ trống)' ],
                        'image_url' => [ 'required' => false, 'type' => 'image',  'description' => 'URL ảnh tham chiếu (img2img)' ],
                        'size'      => [ 'required' => false, 'type' => 'choice', 'description' => 'Kích thước: 1024x1024, 1024x1536, 1536x1024, 768x1344, 1344x768' ],
                        'style'     => [ 'required' => false, 'type' => 'choice', 'description' => 'Phong cách: auto, photorealistic, artistic, anime, illustration' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Image', 'generate_image' ],
            ],
            'list_my_images' => [
                'schema' => [
                    'description'  => 'Xem danh sách ảnh AI đã tạo gần đây với link xem và trạng thái',
                    'input_fields' => [
                        'limit' => [ 'required' => false, 'type' => 'choice', 'description' => 'Số lượng ảnh (5, 10, 20)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Image', 'list_my_images' ],
            ],
        ],

        /* ── Context ──────────────────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $model = get_option( 'bztimg_default_model', 'flux-pro' );
            $ctx  = "Plugin: BizCity Tool Image (AI Image Generator)\n";
            $ctx .= "Model mặc định: {$model} — KHÔNG cần hỏi user chọn model.\n";
            $ctx .= "Khi user gửi prompt tạo ảnh:\n";
            $ctx .= "  1. Tự detect mục đích từ prompt (product/portrait/landscape/social/food)\n";
            $ctx .= "  2. Nếu detect được → hỏi thêm ảnh tham chiếu (upload/URL) → rồi tạo ảnh\n";
            $ctx .= "  3. Nếu KHÔNG rõ mục đích → hỏi: 'Bạn muốn tạo ảnh cho mục đích gì?'\n";
            $ctx .= "  4. Với product/portrait: LUÔN hỏi ảnh tham chiếu trước khi tạo (user có thể bỏ qua)\n";
            $ctx .= "Purpose styles:\n";
            $ctx .= "  - product: Studio-grade product photo, styled environment with props, cinematic lighting, luxury aesthetic, magazine-quality\n";
            $ctx .= "  - portrait: Professional portrait, soft natural lighting, shallow DOF, bokeh, studio quality\n";
            $ctx .= "  - landscape: Golden hour, vivid colors, wide angle, dramatic sky\n";
            $ctx .= "  - social: Bold colors, modern typography, trending aesthetic\n";
            $ctx .= "  - food: Flat lay, warm tones, natural light, appetizing styling\n";
            $ctx .= "Output image_url dùng trong pipeline: write_article, post_facebook, create_product.\n";
            return $ctx;
        },

        /* ── System instructions ── */
        'instructions' => function ( $goal ) {
            return 'Bạn là trợ lý tạo ảnh AI chuyên nghiệp. '
                 . 'Khi nhận prompt tạo ảnh: tự nhận diện mục đích (sản phẩm / chân dung / phong cảnh / social / ẩm thực) rồi enhance prompt. '
                 . 'Sau khi xác định mục đích, LUÔN hỏi user gửi ảnh tham chiếu (upload hoặc dán URL) trước khi tạo — user có thể bỏ qua. '
                 . 'Không hỏi chọn model — dùng model mặc định. '
                 . 'Trả lời tiếng Việt, thân thiện, gợi ý prompt chi tiết.';
        },
    ] );
} );

/* ═══════════════════════════════════════════════
   PILLAR 1 — Profile View Route: /tool-image/
   ═══════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-image/?$', 'index.php?bizcity_agent_page=tool-image', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-image' ) {
        include BZTIMG_DIR . 'views/page-image-profile.php';
        exit;
    }
} );

/* ═══════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
   ═══════════════════════════════════════════════ */
register_activation_hook( __FILE__, function() {
    bztimg_install_tables();
    if ( ! get_option( 'bztimg_api_key' ) ) {
        add_option( 'bztimg_api_key', '' );
    }
    if ( ! get_option( 'bztimg_api_endpoint' ) ) {
        add_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' );
    }
    if ( ! get_option( 'bztimg_default_model' ) ) {
        add_option( 'bztimg_default_model', 'flux-pro' );
    }
    if ( ! get_option( 'bztimg_default_size' ) ) {
        add_option( 'bztimg_default_size', '1024x1024' );
    }
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );

/* ── DB migration ── */
add_action( 'admin_init', function() {
    if ( get_option( 'bztimg_db_version' ) === BZTIMG_VERSION ) return;
    bztimg_install_tables();
    // Auto-migrate PiAPI endpoint → OpenRouter
    $endpoint = get_option( 'bztimg_api_endpoint', '' );
    if ( empty( $endpoint ) || strpos( $endpoint, 'piapi.ai' ) !== false ) {
        update_option( 'bztimg_api_endpoint', 'https://openrouter.ai/api/v1' );
    }
    update_option( 'bztimg_db_version', BZTIMG_VERSION );
} );

/* ═══════════════════════════════════════════════
   REGISTER ASSETS
   ═══════════════════════════════════════════════ */
add_action( 'init', function() {
    wp_register_style(  'bztimg-admin', BZTIMG_URL . 'assets/admin.css', [], BZTIMG_VERSION );
    wp_register_script( 'bztimg-admin', BZTIMG_URL . 'assets/admin.js', [ 'jquery' ], BZTIMG_VERSION, true );
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, BZTIMG_SLUG ) === false ) return;
    wp_enqueue_style( 'bztimg-admin' );
    wp_enqueue_script( 'bztimg-admin' );
    wp_localize_script( 'bztimg-admin', 'BZTIMG', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'bztimg_nonce' ),
    ] );
} );
