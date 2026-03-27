<?php
/**
 * Plugin Name:       BizCity Tool — Content
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-content
 * Description:       Bộ công cụ AI tạo & đăng bài WordPress: viết bài chuẩn SEO từ chủ đề, lên lịch đăng tự động, output URL bài đăng để truyền vào pipeline kế tiếp (chia sẻ Facebook, tạo ảnh bìa, v.v.).
 * Short Description: Chat để viết bài WordPress chuẩn SEO và lên lịch đăng tự động.
 * Quick View:        ✍️ Nhập chủ đề → AI viết bài → Đăng / Lên lịch ngay
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-content
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/content.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1065/2026/03/ai-writing-tools1.png
 * Template Page:     tool-content
 * Plan:              free
 * Category:          content, blog, wordpress, SEO
 * Tags:              blog, viết bài, SEO, content, wordpress, lên lịch, schedule, post, AI tool
 *
 * === Giới thiệu ===
 * BizCity Tool Content tích hợp AI vào quy trình tạo nội dung WordPress. Chỉ
 * cần nói chủ đề qua chat, AI tự viết bài hoàn chỉnh với tiêu đề, nội dung,
 * meta description chuẩn SEO và tự động lên lịch hoặc đăng ngay.
 *
 * === Tính năng chính ===
 * • Viết bài đầy đủ (tiêu đề H1–H3, nội dung, excerpt) từ chủ đề bất kỳ
 * • Lên lịch đăng bài theo ngày / giờ cụ thể
 * • Gắn category, tag, featured image URL từ pipeline
 * • Output post_id + post_url để tiếp tục trong pipeline đa bước
 * • Nhận title / content từ step trước (tích hợp pipeline chain)
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenAI API key (GPT-4o hoặc tương đương)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Kết hợp bizcity-tool-image để tạo ảnh bìa trước khi đăng bài.
 * Truy cập /tool-content/ để mở Content Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — Content</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZTOOL_CONTENT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_CONTENT_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_CONTENT_VERSION', '1.0.0' );
define( 'BZTOOL_CONTENT_SLUG',    'bizcity-tool-content' );

require_once BZTOOL_CONTENT_DIR . 'includes/class-tools-content.php';
require_once BZTOOL_CONTENT_DIR . 'includes/class-post-type.php';
require_once BZTOOL_CONTENT_DIR . 'includes/class-ajax-content.php';
require_once BZTOOL_CONTENT_DIR . 'includes/admin-menu.php';

/* ── CPT registration on init ── */
BizCity_Content_Post_Type::init();

/* ── AJAX init ── */
add_action( 'init', array( 'BizCity_Ajax_Content', 'init' ) );

/* ══════════════════════════════════════════════════════════════
 *  Register Intent Provider — one array config, no class needed
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-content',
        'name' => 'BizCity Tool — Content (Đăng bài WordPress)',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first
         */
        'patterns' => [
            '/viết lại|rewrite|chỉnh sửa bài|sửa lại bài|biên tập lại/ui' => [
                'goal' => 'rewrite_article', 'label' => 'Viết lại bài viết',
                'description' => 'Viết lại / chỉnh sửa / biên tập nội dung một bài viết WordPress đã có',
                'extract' => [ 'post_id', 'slug', 'instruction', 'tone' ],
            ],
            '/viết bài.*seo|bài.*chuẩn seo|seo.*bài viết|bài viết seo|write.*seo/ui' => [
                'goal' => 'write_seo_article', 'label' => 'Viết bài chuẩn SEO',
                'description' => 'Viết bài blog/content MỚI chuẩn SEO từ chủ đề hoặc từ khóa. Bao gồm outline, nội dung, meta SEO',
                'extract' => [ 'message', 'focus_keyword', 'tone', 'length' ],
            ],
            '/dịch bài|translate.*post|dịch.*đăng|dịch bài viết|dịch sang/ui' => [
                'goal' => 'translate_and_publish', 'label' => 'Dịch và đăng bài',
                'description' => 'Dịch một bài viết ĐÃ CÓ sang ngôn ngữ khác (en, ja, ko, zh, th) và đăng bản dịch',
                'extract' => [ 'post_id', 'slug', 'target_lang', 'tone' ],
            ],
            '/lên lịch đăng|hẹn đăng|schedule post|đăng lúc|đăng vào lúc|đăng bài sau/ui' => [
                'goal' => 'schedule_post', 'label' => 'Lên lịch đăng bài',
                'description' => 'Hẹn giờ / lên lịch đăng bài vào thời điểm cụ thể trong tương lai',
                'extract' => [ 'message', 'datetime' ],
            ],
            '/viết bài|đăng bài|viet bai|soạn bài|tạo bài viết|tạo post|write article/ui' => [
                'goal' => 'write_article', 'label' => 'Viết bài đăng web',
                'description' => 'Viết bài blog/content MỚI và đăng lên WordPress. Chủ đề tự do, không yêu cầu SEO đặc biệt',
                'extract' => [ 'message', 'topic', 'tone', 'length', 'image_url' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'write_article' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Tuyệt! Bạn muốn viết về chủ đề gì nè? Mô tả càng chi tiết thì bài viết càng hay 😊', 'no_auto_map' => true ],
                ],
                'optional_slots' => [
                    'tone'      => [ 'type' => 'choice', 'choices' => [ 'casual', 'professional', 'friendly', 'formal' ] ],
                    'length'    => [ 'type' => 'choice', 'choices' => [ 'short', 'medium', 'long' ] ],
                    'title'     => [ 'type' => 'text' ],
                    'image_url' => [ 'type' => 'image', 'prompt' => 'Bạn có ảnh bìa sẵn không? Gửi ảnh hoặc paste link nhé. Nếu chưa có, gõ "bỏ qua" — mình sẽ tự tạo ảnh cho bạn! 🎨', 'default' => '' ],
                ],
                'tool' => 'write_article', 'ai_compose' => false,
                'slot_order' => [ 'topic', 'image_url' ],
            ],
            'write_seo_article' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Bạn muốn viết bài SEO về chủ đề gì? Cho mình từ khóa chính nhé 🔑', 'no_auto_map' => true ],
                ],
                'optional_slots' => [
                    'focus_keyword' => [ 'type' => 'text', 'prompt' => 'Từ khóa SEO chính (focus keyword)?' ],
                    'tone'          => [ 'type' => 'choice', 'choices' => [ 'professional', 'casual', 'friendly' ] ],
                    'length'        => [ 'type' => 'choice', 'choices' => [ 'medium', 'long' ] ],
                    'image_url'     => [ 'type' => 'image' ],
                ],
                'tool' => 'write_seo_article', 'ai_compose' => false,
                'slot_order' => [ 'topic', 'focus_keyword' ],
            ],
            'rewrite_article' => [
                'required_slots' => [
                    'post_id' => [ 'type' => 'text', 'prompt' => 'Bạn muốn viết lại bài nào? Gõ tiêu đề, từ khóa, ID hoặc "mới nhất" nhé 📝' ],
                ],
                'optional_slots' => [
                    'instruction' => [ 'type' => 'text', 'prompt' => 'Có yêu cầu đặc biệt gì cho bài viết lại không?', 'default' => '' ],
                    'tone'        => [ 'type' => 'choice', 'choices' => [ 'casual', 'professional', 'friendly' ] ],
                ],
                'tool' => 'rewrite_article', 'ai_compose' => false,
                'slot_order' => [ 'post_id', 'instruction' ],
            ],
            'translate_and_publish' => [
                'required_slots' => [
                    'post_id'     => [ 'type' => 'text', 'prompt' => 'Bạn muốn dịch bài nào? Gõ tiêu đề, từ khóa, ID hoặc "mới nhất":' ],
                    'target_lang' => [ 'type' => 'choice', 'prompt' => 'Dịch sang ngôn ngữ nào?', 'choices' => [ 'en', 'ja', 'ko', 'zh', 'th' ] ],
                ],
                'optional_slots' => [
                    'tone' => [ 'type' => 'choice', 'choices' => [ 'natural', 'formal', 'casual' ] ],
                ],
                'tool' => 'translate_and_publish', 'ai_compose' => false,
                'slot_order' => [ 'post_id', 'target_lang' ],
            ],
            'schedule_post' => [
                'required_slots' => [
                    'topic'    => [ 'type' => 'text', 'prompt' => 'Bạn muốn đăng bài gì? Mô tả chủ đề hoặc nội dung:', 'no_auto_map' => true ],
                    'datetime' => [ 'type' => 'text', 'prompt' => 'Bạn muốn đăng vào lúc nào? (ví dụ: 20/01/2025 08:00)' ],
                ],
                'optional_slots' => [],
                'tool' => 'schedule_post', 'ai_compose' => false,
                'slot_order' => [ 'topic', 'datetime' ],
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'write_article' => [
                'schema' => [
                    'description'  => 'Viết bài blog/content MỚI và đăng lên WordPress (3 bước: AI viết → ảnh bìa → đăng)',
                    'input_fields' => [
                        'topic'     => [ 'required' => true,  'type' => 'text' ],
                        'tone'      => [ 'required' => false, 'type' => 'choice' ],
                        'length'    => [ 'required' => false, 'type' => 'choice' ],
                        'title'     => [ 'required' => false, 'type' => 'text' ],
                        'image_url' => [ 'required' => false, 'type' => 'image' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Content', 'write_article' ],
            ],
            'write_seo_article' => [
                'schema' => [
                    'description'  => 'Viết bài chuẩn SEO (outline + nội dung + meta SEO + ảnh + đăng)',
                    'input_fields' => [
                        'topic'         => [ 'required' => true,  'type' => 'text' ],
                        'focus_keyword' => [ 'required' => false, 'type' => 'text' ],
                        'tone'          => [ 'required' => false, 'type' => 'choice' ],
                        'length'        => [ 'required' => false, 'type' => 'choice' ],
                        'image_url'     => [ 'required' => false, 'type' => 'image' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Content', 'write_seo_article' ],
            ],
            'rewrite_article' => [
                'schema' => [
                    'description'  => 'Viết lại / biên tập nội dung một bài viết WordPress đã có',
                    'input_fields' => [
                        'post_id'     => [ 'required' => true,  'type' => 'text' ],
                        'instruction' => [ 'required' => false, 'type' => 'text' ],
                        'tone'        => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Content', 'rewrite_article' ],
            ],
            'translate_and_publish' => [
                'schema' => [
                    'description'  => 'Dịch bài viết đã có sang ngôn ngữ khác và đăng bản dịch',
                    'input_fields' => [
                        'post_id'     => [ 'required' => true,  'type' => 'text' ],
                        'target_lang' => [ 'required' => true,  'type' => 'choice' ],
                        'tone'        => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Content', 'translate_and_publish' ],
            ],
            'schedule_post' => [
                'schema' => [
                    'description'  => 'Hẹn giờ / lên lịch đăng bài vào thời điểm cụ thể',
                    'input_fields' => [
                        'topic'    => [ 'required' => true,  'type' => 'text' ],
                        'datetime' => [ 'required' => true,  'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Content', 'schedule_post' ],
            ],
        ],

        /* ── Context (optional) ─────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals = [
                'write_article'         => 'Viết bài mới và đăng lên WordPress',
                'write_seo_article'     => 'Viết bài chuẩn SEO (outline → nội dung → SEO meta → ảnh → đăng)',
                'rewrite_article'       => 'Viết lại nội dung bài viết đã có',
                'translate_and_publish' => 'Dịch bài viết sang ngôn ngữ khác và đăng bản mới',
                'schedule_post'         => 'Lên lịch đăng bài vào thời điểm chỉ định',
            ];
            return "Plugin: BizCity Content Tools\n"
                . 'Mục tiêu: ' . ( $goals[ $goal ] ?? $goal ) . "\n"
                . "Hỗ trợ: viết bài blog, viết lại bài, bài chuẩn SEO, dịch bài, lên lịch đăng bài.\n"
                . "Yêu cầu: Bài viết tiếng Việt, tối thiểu 700 từ, chuẩn HTML thân thiện SEO.\n";
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Agent Profile with guided commands
 *  Touch Bar clicks → /tool-content/?bizcity_iframe=1 → profile view
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-content/?$', 'index.php?bizcity_agent_page=tool-content', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-content' ) {
        include BZTOOL_CONTENT_DIR . 'views/page-agent-profile.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
