<?php
/**
 * Plugin Name:       Tạo Slide
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-slide
 * Description:       AI tạo bài trình bày (slide) từ prompt kịch bản. Render bằng Reveal.js, kèm trang editor & trình chiếu mobile-first.
 * Short Description: Chat để tạo slide trình bày bằng AI — xem & chỉnh sửa online.
 * Quick View:        🎬 Nhập kịch bản → AI tạo slide → Trình chiếu & Chỉnh sửa
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-slide
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * public:            false
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/slide.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/01-how-to-use-ai-to-generate-a-powerpoint-presentation-cover1.png
 * Template Page:     tool-slide
 * Category:          presentation, slide, reveal
 * Tags:              slide, presentation, reveal.js, trình bày, trình chiếu, AI tool
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Tool Slide giúp tạo bài trình bày (slide) từ ngôn ngữ tự nhiên.
 * AI tạo HTML slide Reveal.js → render trình chiếu → lưu & quản lý.
 *
 * === Tính năng chính ===
 * • Tạo slide từ prompt kịch bản bằng AI
 * • Chọn theme: light, dark, solarized, moon, blood, night, serif, simple, beige, league
 * • Trang editor tương tác — mobile first (giống tool-mindmap)
 * • Lưu lịch sử, xem lại, chỉnh sửa, xóa
 * • Trình chiếu fullscreen
 * • Tích hợp Intent Engine: chat → AI tạo slide → trả link xem
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenRouter API (Gemini Flash recommended)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-slide/ để mở Slide Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Tạo Slide</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZTOOL_SLIDE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_SLIDE_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_SLIDE_VERSION', '1.0.0' );
define( 'BZTOOL_SLIDE_SLUG',    'bizcity-tool-slide' );

require_once BZTOOL_SLIDE_DIR . 'includes/class-tools-slide.php';

/* ══════════════════════════════════════════════════════════════
 *  Register Intent Provider — patterns → plans → tools
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-slide',
        'name' => 'BizCity Tool — Slide Presentation',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first
         */
        'patterns' => [
            '/tạo slide|tạo bài trình bày|tạo presentation|tạo bài thuyết trình|làm slide|tạo trình chiếu/ui' => [
                'goal'        => 'create_slide',
                'label'       => 'Tạo Slide trình bày',
                'description' => 'Tạo bài trình bày (slide) từ kịch bản hoặc chủ đề',
                'extract'     => [ 'message', 'topic' ],
            ],
            '/slide|trình bày|presentation|thuyết trình|trình chiếu|pitch\s*deck|keynote/ui' => [
                'goal'        => 'create_slide',
                'label'       => 'Tạo Slide trình bày',
                'description' => 'Tạo bài trình bày (slide) từ kịch bản hoặc chủ đề',
                'extract'     => [ 'message', 'topic', 'slide_theme' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_slide' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Bạn muốn tạo slide về chủ đề gì? Mô tả kịch bản càng chi tiết, slide càng đẹp 🎬' ],
                ],
                'optional_slots' => [
                    'slide_theme' => [
                        'type'    => 'choice',
                        'prompt'  => 'Chọn theme slide? (bỏ qua để AI tự chọn)',
                        'choices' => [ 'white', 'black', 'moon', 'night', 'serif', 'simple', 'solarized', 'blood', 'beige', 'league', 'auto' ],
                        'default' => 'auto',
                    ],
                    'num_slides' => [
                        'type'    => 'number',
                        'prompt'  => 'Bao nhiêu slide? (bỏ qua để AI tự quyết)',
                        'default' => 0,
                    ],
                ],
                'tool'       => 'create_slide',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'slide_theme', 'num_slides' ],
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_slide' => [
                'schema' => [
                    'description'  => 'Tạo bài trình bày (slide) bằng Reveal.js từ kịch bản hoặc chủ đề',
                    'input_fields' => [
                        'topic'       => [ 'required' => true,  'type' => 'text' ],
                        'slide_theme' => [ 'required' => false, 'type' => 'choice' ],
                        'num_slides'  => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Slide', 'create_slide' ],
            ],
        ],

        /* ── Examples (Tools Map hints) ─────────────────── */
        'examples' => [
            'create_slide' => [
                'Tạo slide thuyết trình về Digital Marketing 2026',
                'Làm bài trình bày kế hoạch kinh doanh Q2',
                'Tạo pitch deck cho startup AI',
                'Slide giới thiệu sản phẩm mới',
                'Trình bày báo cáo doanh thu tháng 3',
                'Tạo slide đào tạo nhân viên mới',
            ],
        ],

        /* ── Context (optional) ─────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            return "Plugin: BizCity Tool Slide\n"
                . "Mục tiêu: Tạo bài trình bày (slide) từ kịch bản\n"
                . "Hỗ trợ: Reveal.js presentation, nhiều theme, fullscreen trình chiếu\n"
                . "Output: HTML slide Reveal.js, lưu dưới dạng post + meta, có trang xem/sửa online.\n";
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  Notebook Studio Button — bcn_register_notebook_tools
 *  Allows Companion Notebook Studio to delegate slide creation.
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bcn_register_notebook_tools', function ( $registry ) {
    $registry->add( [
        'type'      => 'slide',
        'label'     => 'Slide trình bày',
        'icon'      => '🎬',
        'color'     => 'purple',
        'mode'      => 'delegate',
        'available' => true,
        'callback'  => function ( array $skeleton ) {
            $parts = [];

            if ( ! empty( $skeleton['nucleus']['title'] ) ) {
                $parts[] = 'Chủ đề: ' . $skeleton['nucleus']['title'];
            }
            if ( ! empty( $skeleton['nucleus']['thesis'] ) ) {
                $parts[] = 'Luận điểm: ' . $skeleton['nucleus']['thesis'];
            }

            // Skeleton tree → slide structure hints.
            if ( ! empty( $skeleton['skeleton'] ) ) {
                $parts[] = "\nDàn ý slide:";
                foreach ( $skeleton['skeleton'] as $node ) {
                    $label   = is_array( $node ) ? ( $node['label'] ?? $node['heading'] ?? $node['text'] ?? '' ) : $node;
                    $summary = is_array( $node ) ? ( $node['summary'] ?? '' ) : '';
                    $parts[] = '- ' . $label . ( $summary ? ': ' . $summary : '' );
                    foreach ( ( is_array( $node ) ? ( $node['children'] ?? [] ) : [] ) as $child ) {
                        $clabel = is_array( $child ) ? ( $child['label'] ?? $child['text'] ?? '' ) : $child;
                        if ( $clabel ) $parts[] = '  - ' . $clabel;
                    }
                }
            }

            if ( ! empty( $skeleton['key_points'] ) ) {
                $parts[] = "\nĐiểm chính:";
                foreach ( $skeleton['key_points'] as $kp ) {
                    $text = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : $kp;
                    if ( $text ) $parts[] = '- ' . $text;
                }
            }

            $topic = implode( "\n", $parts );

            // Fallback: raw text if skeleton extraction failed.
            if ( empty( trim( $topic ) ) ) {
                $topic = $skeleton['_raw_text'] ?? '';
            }

            $meta_context = $skeleton['_meta']['_context'] ?? '';

            return BizCity_Tool_Slide::create_slide( [
                'topic'        => $topic,
                'slide_theme'  => 'auto',
                'num_slides'   => 0,
                'session_id'   => $skeleton['session_id'] ?? '',
                'chat_id'      => $skeleton['chat_id']    ?? '',
                '_meta'        => [
                    '_context' => $meta_context ?: mb_substr( $topic, 0, 1200 ),
                    'channel'  => 'notebook',
                    'blog_id'  => get_current_blog_id(),
                ],
            ] );
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Slide Studio SPA
 *  /tool-slide/ → Mobile-first editor + history + AI generate
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-slide/?$', 'index.php?bizcity_agent_page=tool-slide', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-slide' ) {
        include BZTOOL_SLIDE_DIR . 'views/page-slide.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
