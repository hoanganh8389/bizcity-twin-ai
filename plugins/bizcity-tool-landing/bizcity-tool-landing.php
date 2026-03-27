<?php
/**
 * Plugin Name:       Landing Page Builder
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-landing
 * Description:       AI tạo landing page chuyên nghiệp từ prompt. Thiết kế responsive, tối ưu chuyển đổi — kèm builder trực quan, quản lý media.
 * Short Description: Chat để tạo landing page bán hàng chuyên nghiệp bằng AI — preview & chỉnh sửa online.
 * Quick View:        🚀 Nhập mô tả sản phẩm → AI thiết kế landing page → Preview & Chỉnh sửa
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-landing
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * public:            false
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/landing-page.png
 * Cover URI:         https://s3-alpha.figma.com/hub/file/2217949665163359172/e144c1b1-5c72-460c-bf7b-4569ce04c330-cover.png
 * Template Page:     tool-landing
 * Category:          landing-page, marketing, conversion, builder
 * Tags:              landing page, trang đích, bán hàng, marketing, chuyển đổi, AI builder, responsive
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Tool Landing Page Builder giúp tạo landing page chuyên nghiệp từ
 * mô tả sản phẩm/dịch vụ. AI thiết kế HTML+CSS responsive → preview & chỉnh sửa trực quan.
 *
 * === Tính năng chính ===
 * • Tạo landing page chuyên nghiệp từ mô tả sản phẩm/dịch vụ
 * • 10 conversion pattern: Hero-CTA, Problem-Solution, Feature-Benefit, Testimonial-Proof...
 * • Bảng màu & font tối ưu theo ngành (SaaS, Education, Health, Beauty, F&B...)
 * • Builder trực quan: live preview, code editor, media upload
 * • Tích hợp Intent Engine: chat → AI thiết kế → trả link xem
 * • Tích hợp Notebook Studio: skeleton JSON → landing page từ nội dung dự án
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenRouter API (Claude Sonnet 4.6 recommended)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-landing/ để mở Landing Page Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Landing Page Builder</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZTOOL_LANDING_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_LANDING_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_LANDING_VERSION', '1.0.0' );
define( 'BZTOOL_LANDING_SLUG',    'bizcity-tool-landing' );

require_once BZTOOL_LANDING_DIR . 'includes/class-tools-landing.php';

/* ══════════════════════════════════════════════════════════════
 *  Register Intent Provider — patterns → plans → tools
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-landing',
        'name' => 'BizCity Tool — Landing Page Builder',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first
         */
        'patterns' => [
            '/landing\s*page|tạo landing|thiết kế landing/ui' => [
                'goal'        => 'create_landing',
                'label'       => 'Tạo Landing Page',
                'description' => 'Thiết kế landing page chuyên nghiệp từ mô tả sản phẩm/dịch vụ',
                'extract'     => [ 'message', 'topic', 'product_type' ],
            ],
            '/trang đích|trang bán hàng|trang giới thiệu sản phẩm|trang chuyển đổi/ui' => [
                'goal'        => 'create_landing',
                'label'       => 'Tạo trang đích',
                'description' => 'Tạo trang đích (landing page) chuyển đổi cao',
                'extract'     => [ 'message', 'topic', 'product_type' ],
            ],
            '/sales\s*page|squeeze\s*page|opt[- ]?in\s*page|capture\s*page/ui' => [
                'goal'        => 'create_landing',
                'label'       => 'Tạo Sales Page',
                'description' => 'Tạo sales page / opt-in page chuyên nghiệp',
                'extract'     => [ 'message', 'topic', 'product_type' ],
            ],
            '/tạo trang web|thiết kế trang|làm trang bán|trang quảng cáo/ui' => [
                'goal'        => 'create_landing',
                'label'       => 'Tạo trang web quảng cáo',
                'description' => 'Tạo trang web bán hàng / quảng cáo từ mô tả',
                'extract'     => [ 'message', 'topic', 'product_type' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_landing' => [
                'required_slots' => [
                    'topic' => [
                        'type'   => 'text',
                        'prompt' => 'Mô tả sản phẩm/dịch vụ bạn muốn tạo landing page nhé! Càng chi tiết, trang càng đẹp và chuyên nghiệp 🚀',
                    ],
                ],
                'optional_slots' => [
                    'product_type' => [
                        'type'    => 'choice',
                        'prompt'  => 'Loại sản phẩm/dịch vụ? (bỏ qua để AI tự chọn)',
                        'choices' => [
                            'saas', 'education', 'health', 'beauty', 'fnb',
                            'finance', 'real-estate', 'event', 'app', 'service',
                            'ecommerce', 'consulting', 'nonprofit', 'portfolio', 'other',
                        ],
                        'default' => 'other',
                    ],
                    'template' => [
                        'type'    => 'choice',
                        'prompt'  => 'Kiểu landing page? (bỏ qua để AI tự chọn)',
                        'choices' => [
                            'hero-cta', 'problem-solution', 'feature-benefit',
                            'testimonial-proof', 'pricing-comparison', 'countdown-urgency',
                            'storytelling', 'quiz-funnel', 'video-showcase', 'minimal-zen',
                        ],
                        'default' => 'hero-cta',
                    ],
                ],
                'tool'       => 'create_landing',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'product_type', 'template' ],
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_landing' => [
                'schema' => [
                    'description'  => 'Tạo landing page chuyên nghiệp từ mô tả sản phẩm/dịch vụ — HTML+CSS responsive, tối ưu chuyển đổi',
                    'input_fields' => [
                        'topic'        => [ 'required' => true,  'type' => 'text' ],
                        'product_type' => [ 'required' => false, 'type' => 'choice' ],
                        'template'     => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Landing', 'create_landing' ],
            ],
        ],

        /* ── Examples (Tools Map hints) ─────────────────── */
        'examples' => [
            'create_landing' => [
                'Tạo landing page cho khóa học Digital Marketing online',
                'Thiết kế trang bán hàng cho sản phẩm skincare Hàn Quốc',
                'Landing page giới thiệu app quản lý tài chính cá nhân',
                'Tạo trang đích cho sự kiện hội thảo công nghệ AI',
                'Trang bán hàng cho dịch vụ thiết kế nội thất',
                'Landing page cho phần mềm CRM doanh nghiệp nhỏ',
            ],
        ],

        /* ── Context (optional) ─────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            return "Plugin: BizCity Tool Landing Page Builder\n"
                . "Mục tiêu: Tạo landing page chuyên nghiệp, tối ưu chuyển đổi\n"
                . "Hỗ trợ: 10 conversion patterns, 15 bảng màu theo ngành, responsive mobile-first\n"
                . "Output: HTML+CSS single-file, lưu dưới dạng post + meta, có trang preview/builder online.\n";
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  Register as Notebook Tool — BCN_Notebook_Tool_Registry
 *  Allows Companion Notebook Studio to delegate landing page creation.
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bcn_register_notebook_tools', function ( $registry ) {
    $registry->add( [
        'type'      => 'landing',
        'label'     => 'Landing Page',
        'icon'      => '🚀',
        'mode'      => 'delegate',
        'available' => true,
        'callback'  => function ( array $skeleton ) {
            // Adapter: Skeleton JSON → create_landing.
            $parts = [];

            if ( ! empty( $skeleton['nucleus']['title'] ) ) {
                $parts[] = 'Sản phẩm/Dịch vụ: ' . $skeleton['nucleus']['title'];
            }
            if ( ! empty( $skeleton['nucleus']['thesis'] ) ) {
                $parts[] = 'Giá trị cốt lõi: ' . $skeleton['nucleus']['thesis'];
            }

            // Skeleton tree → sections hints for landing page.
            if ( ! empty( $skeleton['skeleton'] ) ) {
                $parts[] = "\nCấu trúc nội dung:";
                foreach ( $skeleton['skeleton'] as $node ) {
                    $parts[] = '- ' . $node['label'] . ( ! empty( $node['summary'] ) ? ': ' . $node['summary'] : '' );
                    foreach ( $node['children'] ?? [] as $child ) {
                        $parts[] = '  - ' . $child['label'] . ( ! empty( $child['summary'] ) ? ': ' . $child['summary'] : '' );
                    }
                }
            }

            if ( ! empty( $skeleton['key_points'] ) ) {
                $parts[] = "\nĐiểm nổi bật:";
                foreach ( $skeleton['key_points'] as $kp ) {
                    $parts[] = '- ' . $kp;
                }
            }

            if ( ! empty( $skeleton['entities'] ) ) {
                $parts[] = "\nThực thể liên quan:";
                foreach ( array_slice( $skeleton['entities'], 0, 10 ) as $ent ) {
                    $label = is_array( $ent ) ? ( $ent['name'] ?? $ent['label'] ?? '' ) : $ent;
                    if ( $label ) $parts[] = '- ' . $label;
                }
            }

            // Assemble structured skeleton parts into topic (must happen before raw_text append).
            $topic = implode( "\n", $parts );

            // Append actual raw source documents — this is what the AI needs to write
            // relevant landing page content. Skeleton is a distilled summary; _raw_text
            // has the real content from PDFs, web pages, notes, etc.
            $raw_text = $skeleton['_raw_text'] ?? BCN_Studio_Input_Builder::to_text( $skeleton );
            if ( $raw_text ) {
                $topic .= ( $topic ? "\n\n" : '' ) . "=== NỘI DUNG NGUỒN TÀI LIỆU ===\n" . mb_substr( $raw_text, 0, 14000 );
            }

            // Final fallback.
            if ( empty( trim( $topic ) ) ) {
                $topic = $raw_text;
            }

            // Debug trace: log what we're passing to AI (first 800 chars).
            error_log( '[BizCity Landing] Notebook callback topic (' . strlen( $topic ) . ' chars). Skeleton keys: '
                . implode( ', ', array_keys( $skeleton ) ) . "\nTopic preview: " . mb_substr( $topic, 0, 800 ) );

            return BizCity_Tool_Landing::create_landing( [
                'topic'        => $topic,
                'product_type' => 'other',
                'template'     => 'hero-cta',
                '_meta'        => [ '_context' => 'Notebook Studio — Tạo landing page từ skeleton dự án.' ],
            ] );
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Landing Page Studio SPA
 *  /tool-landing/ → Mobile-first builder + history + AI generate
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-landing/?$', 'index.php?bizcity_agent_page=tool-landing', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-landing' ) {
        include BZTOOL_LANDING_DIR . 'views/page-landing.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
