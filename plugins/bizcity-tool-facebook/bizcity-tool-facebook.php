<?php
/**
 * Plugin Name:       Facebook tool by BizCity — AI đăng bài & Quản lý Page
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-facebook
 * Description:       Bộ công cụ AI đăng bài tự động lên Facebook Page. Standalone — Facebook App riêng, OAuth riêng, webhook /bizfbhook/ riêng. Hỗ trợ Messenger chatbot, theo dõi comment, đăng ảnh/video, Instagram Reels, Groups.
 * Short Description: Chat → AI viết & đăng Facebook. Standalone OAuth, /bizfbhook/, Messenger, Comments, IG.
 * Quick View:        📣 AI viết & đăng Facebook — Standalone App, Messenger, Comments, IG Reels
 * Version:           2.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-facebook
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/Add-a-heading-61.png
 * Template Page:     tool-facebook
 * Category:          social, facebook, marketing, mạng xã hội
 * Tags:              facebook, social media, đăng bài, fanpage, marketing, tự động, AI tool, page, webhook
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Tool Facebook là bộ công cụ AI tự động đăng bài lên Facebook Page.
 * Kết nối Page, AI tạo nội dung hấp dẫn, đăng kèm ảnh — hỗ trợ multisite
 * dùng chung Central Webhook không cần tạo app riêng.
 *
 * === Tính năng chính ===
 * • AI viết bài & đăng lên Facebook Page tự động
 * • Kết nối nhiều Page, quản lý bài đăng tập trung
 * • Central Webhook /facehook/ cho multisite — 1 endpoint, nhiều subsite
 * • Pipeline-ready: output post_id + post_url cho bước tiếp
 * • Phù hợp kết hợp bizcity-tool-content + bizcity-tool-image
 * • Giao diện Facebook Studio tại /tool-facebook/
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Twin AI Core
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • Facebook Page Access Token
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Vào Settings để kết nối Facebook Page.
 * Truy cập /tool-facebook/ để mở Facebook Studio.
 */

defined( 'ABSPATH' ) or die( 'OOPS...' );

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — Facebook</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ═══════════════════════════════════════════════
   CONSTANTS
   ═══════════════════════════════════════════════ */
define( 'BZTOOL_FB_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_FB_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_FB_VERSION', '2.1.0' );
define( 'BZTOOL_FB_SLUG',    'tool-facebook' );

/* ═══════════════════════════════════════════════
   AUTOLOAD INCLUDES
   ═══════════════════════════════════════════════ */
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

/* ── Standalone infrastructure (no bizcity-facebook-bot dependency) ── */
require_once BZTOOL_FB_DIR . 'includes/class-fb-graph-api.php';
require_once BZTOOL_FB_DIR . 'includes/class-fb-database.php';
require_once BZTOOL_FB_DIR . 'includes/class-fb-webhook.php';
require_once BZTOOL_FB_DIR . 'includes/class-fb-oauth.php';

require_once BZTOOL_FB_DIR . 'includes/install.php';
require_once BZTOOL_FB_DIR . 'includes/class-tools-facebook.php';
require_once BZTOOL_FB_DIR . 'includes/class-ajax-facebook.php';
require_once BZTOOL_FB_DIR . 'includes/admin-menu.php';
require_once BZTOOL_FB_DIR . 'includes/integration-chat.php';
require_once BZTOOL_FB_DIR . 'includes/class-channel-adapter.php';
require_once BZTOOL_FB_DIR . 'includes/class-intent-provider.php';

/* ── Boot standalone services ── */
add_action( 'plugins_loaded', function() {
    BizCity_FB_Database::install();
    BizCity_FB_Webhook::instance();
    BizCity_FB_OAuth::instance();
}, 5 );

/* ── Register Channel Adapter with twin-ai Gateway Bridge ── */
add_action( 'bizcity_register_channel', function( $bridge ) {
    if ( class_exists( 'BizCity_Facebook_Channel_Adapter' ) ) {
        $bridge->register_adapter( new BizCity_Facebook_Channel_Adapter() );
    }
} );

/* ═══════════════════════════════════════════════
   POST TYPE: biz_facebook (AI-generated FB posts)
   Extracted from bizcity-admin-hook/flows/bizgpt_facebook.php
   ═══════════════════════════════════════════════ */
add_action( 'init', function() {
    $labels = array(
        'name'               => 'Bài FB do AI tạo',
        'singular_name'      => 'Bài đăng FB AI',
        'menu_name'          => 'Bài đăng FB AI',
        'add_new'            => 'Thêm bài',
        'add_new_item'       => 'Thêm bài Facebook mới',
        'edit_item'          => 'Chỉnh sửa bài',
        'new_item'           => 'Bài mới',
        'view_item'          => 'Xem bài',
        'view_items'         => 'Xem các bài',
        'search_items'       => 'Tìm bài Facebook',
        'not_found'          => 'Không tìm thấy.',
        'not_found_in_trash' => 'Không có bài nào trong thùng rác.',
    );

    register_post_type( 'biz_facebook', array(
        'labels'              => $labels,
        'public'              => true,
        'publicly_queryable'  => true,
        'exclude_from_search' => false,
        'show_ui'             => true,
        'show_in_nav_menus'   => true,
        'has_archive'         => true,
        'rewrite'             => array( 'slug' => 'biz-facebook', 'with_front' => false ),
        'show_in_rest'        => true,
        'supports'            => array( 'title', 'editor', 'thumbnail', 'author', 'excerpt' ),
        'show_in_menu'        => 'bizcity-facebook-bots',
        'menu_icon'           => 'dashicons-facebook-alt',
    ) );
} );

/* REST fields for clean text (Zapier, Telegram, etc.) */
add_action( 'rest_api_init', function() {
    register_rest_field( 'biz_facebook', 'plain_title', array(
        'get_callback' => function( $post_arr ) {
            return bztfb_clean_plain_text( get_the_title( $post_arr['id'] ) );
        },
        'schema' => array( 'description' => 'Tiêu đề sạch cho Facebook/Zapier', 'type' => 'string' ),
    ) );
    register_rest_field( 'biz_facebook', 'plain_content', array(
        'get_callback' => function( $post_arr ) {
            return bztfb_clean_plain_text( get_post_field( 'post_content', $post_arr['id'] ) );
        },
        'schema' => array( 'description' => 'Nội dung sạch cho Facebook/Zapier', 'type' => 'string' ),
    ) );
} );

/**
 * Clean HTML to plain text for external platforms.
 */
function bztfb_clean_plain_text( string $html ): string {
    $text = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
    $text = str_ireplace( array( '<p>', '</p>', '<br>', '<br/>', '<br />' ), "\n\n", $text );
    $text = wp_strip_all_tags( $text );
    return trim( preg_replace( "/[\r\n]{3,}/", "\n\n", $text ) );
}

/* ═══════════════════════════════════════════════
   PILLAR 2 — Intent Provider (bizcity_intent_register_plugin)
   Primary tool: create_facebook_post
   Secondary: post_facebook (pipeline), list_facebook_posts
   ═══════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    if ( ! function_exists( 'bizcity_intent_register_plugin' ) ) {
        if ( class_exists( 'BizCity_Intent_Provider' ) ) {
            $registry->register( new BizCity_Tool_Facebook_Intent_Provider() );
        }
        return;
    }

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-facebook',
        'name' => 'BizCity Tool — Facebook (AI Đăng bài & Quản lý Page)',

        /* ── Goal patterns (Router) ───────────────────── */
        'patterns' => [
            /* List posts */
            '/danh sách.*facebook|list.*facebook.*post|bài.*facebook.*gần đây|xem.*bài.*fb|bài đã đăng fb/ui' => [
                'goal'        => 'list_facebook_posts',
                'label'       => 'Xem danh sách bài FB đã đăng',
                'description' => 'Liệt kê các bài Facebook AI đã tạo gần đây',
                'extract'     => [ 'limit' ],
            ],
            /* Primary: Create & post Facebook content */
            '/đăng facebook|đăng fb|post facebook|tạo bài facebook|viết bài fb|đăng lên facebook|chia sẻ.*fb|chia sẻ.*facebook|share.*facebook|đăng fanpage|post.*fanpage|bài.*facebook|nội dung.*facebook|content.*facebook/ui' => [
                'goal'        => 'create_facebook_post',
                'label'       => 'Tạo & đăng bài Facebook',
                'description' => 'AI tạo nội dung hấp dẫn từ chủ đề, kèm ảnh, đăng lên một hoặc nhiều Facebook Page',
                'extract'     => [ 'topic', 'image_url', 'page_id', 'tone' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ──────────── */
        'plans' => [
            'create_facebook_post' => [
                'required_slots' => [
                    'topic' => [
                        'type'        => 'text',
                        'prompt'      => '📝 Bạn muốn đăng bài về chủ đề gì? Mô tả càng chi tiết (tone, đối tượng, CTA) thì bài càng hay!',
                        'no_auto_map' => true,
                    ],
                ],
                'optional_slots' => [
                    'image_url' => [
                        'type'    => 'image',
                        'prompt'  => '🖼️ Gửi URL ảnh hoặc upload ảnh (bỏ qua để AI tự tạo):',
                        'default' => '',
                    ],
                    'tone' => [
                        'type'    => 'choice',
                        'choices' => [
                            'engaging'      => '🔥 Thu hút, viral',
                            'professional'  => '💼 Chuyên nghiệp',
                            'friendly'      => '😊 Thân thiện, gần gũi',
                            'promotional'   => '🎉 Khuyến mãi, sale',
                            'storytelling'  => '📖 Kể chuyện',
                        ],
                        'prompt'  => 'Tone bài viết:',
                        'default' => 'engaging',
                    ],
                    'page_id' => [
                        'type'    => 'text',
                        'prompt'  => 'Facebook Page ID (để trống = đăng tất cả page đã kết nối):',
                        'default' => '',
                    ],
                ],
                'tool'       => 'create_facebook_post',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'image_url', 'tone' ],
            ],
            'list_facebook_posts' => [
                'required_slots' => [],
                'optional_slots' => [
                    'limit' => [ 'type' => 'choice', 'choices' => [ '5' => '5 bài', '10' => '10 bài', '20' => '20 bài' ], 'default' => '10' ],
                ],
                'tool'       => 'list_facebook_posts',
                'ai_compose' => false,
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_facebook_post' => [
                'schema' => [
                    'description'  => 'AI viết bài Facebook hấp dẫn từ chủ đề, tự sinh tiêu đề + nội dung + hashtag, kèm ảnh, đăng lên Page. Output wp_post_id + fb_post_ids.',
                    'input_fields' => [
                        'topic'     => [ 'required' => true,  'type' => 'text',   'description' => 'Chủ đề / yêu cầu nội dung bài Facebook' ],
                        'image_url' => [ 'required' => false, 'type' => 'image',  'description' => 'URL ảnh kèm bài (pipeline: $step[N].data.image_url)' ],
                        'tone'      => [ 'required' => false, 'type' => 'choice', 'description' => 'Tone: engaging, professional, friendly, promotional, storytelling' ],
                        'page_id'   => [ 'required' => false, 'type' => 'text',   'description' => 'Page ID cụ thể (mặc định = tất cả page)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Facebook', 'create_facebook_post' ],
            ],
            'post_facebook' => [
                'schema' => [
                    'description'  => 'Đăng nội dung đã có sẵn lên Facebook Page (pipeline: nhận title/content/image từ bước trước).',
                    'input_fields' => [
                        'message'   => [ 'required' => false, 'type' => 'text',  'description' => 'Nội dung bài Facebook' ],
                        'image_url' => [ 'required' => false, 'type' => 'url',   'description' => 'URL ảnh (pipeline)' ],
                        'content'   => [ 'required' => false, 'type' => 'text',  'description' => 'Nội dung từ pipeline' ],
                        'title'     => [ 'required' => false, 'type' => 'text',  'description' => 'Tiêu đề từ pipeline' ],
                        'url'       => [ 'required' => false, 'type' => 'url',   'description' => 'URL bài viết từ pipeline' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Facebook', 'post_facebook' ],
            ],
            'list_facebook_posts' => [
                'schema' => [
                    'description'  => 'Xem danh sách bài Facebook AI đã tạo gần đây',
                    'input_fields' => [
                        'limit' => [ 'required' => false, 'type' => 'choice', 'description' => 'Số bài (5, 10, 20)' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Facebook', 'list_facebook_posts' ],
            ],
        ],

        /* ── Context ──────────────────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $model = get_option( 'bztfb_ai_model', 'gpt-4o' );
            $pages = get_option( 'fb_pages_connected', array() );
            $page_names = array();
            if ( is_array( $pages ) ) {
                foreach ( $pages as $p ) {
                    $page_names[] = ( $p['name'] ?? '' ) . ' (' . ( $p['id'] ?? '' ) . ')';
                }
            }

            $ctx  = "Plugin: BizCity Tool Facebook (AI Đăng bài)\n";
            $ctx .= "Model AI: {$model}\n";
            $ctx .= "Pages đã kết nối: " . ( $page_names ? implode( ', ', $page_names ) : 'Chưa có' ) . "\n\n";
            $ctx .= "Khi user muốn đăng bài Facebook:\n";
            $ctx .= "1. Hỏi chủ đề/nội dung muốn đăng\n";
            $ctx .= "2. Hỏi có ảnh kèm không (user gửi URL hoặc bỏ qua để AI tự tạo)\n";
            $ctx .= "3. Hỏi tone bài viết (thu hút / chuyên nghiệp / thân thiện / khuyến mãi / kể chuyện)\n";
            $ctx .= "4. Xác nhận rồi gọi tool create_facebook_post\n";
            $ctx .= "5. Bài sẽ tự động lưu vào WordPress (post type biz_facebook) và đăng lên tất cả Page\n\n";
            $ctx .= "Output: wp_post_id + fb_post_ids → dùng trong pipeline.\n";
            $ctx .= "Tool post_facebook: dùng cho pipeline (nhận content/image từ bước trước, đăng trực tiếp).\n";
            return $ctx;
        },

        /* ── System instructions ── */
        'instructions' => function ( $goal ) {
            return 'Bạn là trợ lý AI chuyên tạo nội dung Facebook Marketing. '
                 . 'Khi nhận yêu cầu đăng bài: hỏi chủ đề → tone → ảnh → xác nhận → tạo & đăng. '
                 . 'Nội dung phải hấp dẫn, có emoji, hashtag, CTA rõ ràng. Tối đa 300 chữ. '
                 . 'Trả lời tiếng Việt, thân thiện, chuyên nghiệp.';
        },
    ] );
} );

/* ═══════════════════════════════════════════════
   PILLAR 1 — Profile View Route: /tool-facebook/
   ═══════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-facebook/?$', 'index.php?bizcity_agent_page=tool-facebook', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-facebook' ) {
        include BZTOOL_FB_DIR . 'views/page-facebook-profile.php';
        exit;
    }
} );

/* ═══════════════════════════════════════════════
   ACTIVATION / DEACTIVATION
   ═══════════════════════════════════════════════ */
register_activation_hook( __FILE__, function() {
    bztfb_install_tables();
    if ( ! get_option( 'bztfb_ai_model' ) ) {
        add_option( 'bztfb_ai_model', 'gpt-4o' );
    }
    flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
} );
