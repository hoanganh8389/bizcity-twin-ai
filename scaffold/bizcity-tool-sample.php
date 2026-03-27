<?php
/**
 * Plugin Name:       BizCity Tool — Sample
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-sample
 * Description:       [MÔ TẢ] — Plugin mẫu scaffold theo PLUGIN-STANDARD v2.0
 * Short Description: [MÔ TẢ NGẮN] cho Touch Bar
 * Quick View:        🔧 Chat → [Input] → AI → [Output]
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-sample
 * Role:              agent
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/.../cover.png
 * Template Page:     tool-sample
 * Category:          tools
 * Tags:              AI tool, sample
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — Sample</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

/* ══════════════════════════════════════════
 *  Constants & Loader
 * ══════════════════════════════════════════ */
define( 'BZTOOL_SAMPLE_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_SAMPLE_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_SAMPLE_VERSION', '1.0.0' );
define( 'BZTOOL_SAMPLE_SLUG',    'bizcity-tool-sample' );

require_once BZTOOL_SAMPLE_DIR . 'includes/class-tools-sample.php';

/* ══════════════════════════════════════════
 *  PILLAR 2 — Intent Provider Registration
 * ══════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-sample',
        'name' => 'BizCity Tool — Sample',

        /* ── Goal Patterns (Router) ──────────────────────
         *  ORDER MATTERS: specific → generic (top → bottom)
         *  Primary tool = LAST pattern (generic catch-all)
         */
        'patterns' => [
            /* SECONDARY — specific patterns first */
            '/sửa sample|update sample|edit sample/ui' => [
                'goal'        => 'edit_sample',
                'label'       => 'Sửa Sample',
                'description' => 'Sửa/cập nhật sample đã có',
                'extract'     => [ 'sample_id', 'instruction' ],
            ],

            /* PRIMARY — generic pattern last */
            '/tạo sample|create sample|làm sample/ui' => [
                'goal'        => 'create_sample',
                'label'       => 'Tạo Sample',
                'description' => 'Tạo sample MỚI từ mô tả',
                'extract'     => [ 'topic' ],
            ],
        ],

        /* ── Plans (Planner — slot gathering) ────────── */
        'plans' => [
            'create_sample' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả sample bạn muốn tạo:' ],
                ],
                'optional_slots' => [
                    'image_url' => [ 'type' => 'image', 'prompt' => 'Gửi ảnh (tùy chọn). Gõ "bỏ qua" nếu không có.', 'default' => '' ],
                ],
                'tool'       => 'create_sample',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'image_url' ],
            ],
            'edit_sample' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Sample cần sửa gì? (ví dụ: sửa sample #123)' ],
                ],
                'optional_slots' => [],
                'tool'       => 'edit_sample',
                'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
        ],

        /* ── Tools (callbacks + schema BẮT BUỘC) ─────── */
        'tools' => [
            /* PRIMARY TOOL */
            'create_sample' => [
                'schema' => [
                    'description'  => 'Tạo sample mới từ mô tả (AI phân tích → xử lý → tạo)',
                    'input_fields' => [
                        'topic'     => [ 'required' => true,  'type' => 'text' ],
                        'image_url' => [ 'required' => false, 'type' => 'image' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Sample', 'create_sample' ],
            ],
            /* SECONDARY TOOL */
            'edit_sample' => [
                'schema' => [
                    'description'  => 'Sửa/cập nhật sample đã có',
                    'input_fields' => [
                        'topic' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Sample', 'edit_sample' ],
            ],
        ],

        /* ── Context (inject domain data vào AI) ─────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals = [
                'create_sample' => 'Tạo sample mới từ mô tả',
                'edit_sample'   => 'Sửa/cập nhật sample đã có',
            ];
            return "Plugin: BizCity Tool — Sample\n"
                . 'Mục tiêu: ' . ( $goals[ $goal ] ?? $goal ) . "\n"
                . "Hỗ trợ: tạo và quản lý samples.\n"
                . "Ngôn ngữ: Tiếng Việt.\n";
        },

        /* ── Examples (gợi ý cho tool map) ────────────── */
        'examples' => [
            'create_sample' => [
                'Tạo sample mô tả sản phẩm mới',
                'Làm sample giới thiệu công ty',
            ],
            'edit_sample' => [
                'Sửa sample #123',
                'Cập nhật nội dung sample mới nhất',
            ],
        ],
    ] );
} );

/* ══════════════════════════════════════════
 *  PILLAR 1 — Profile View Route
 * ══════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-sample/?$', 'index.php?bizcity_agent_page=tool-sample', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-sample' ) {
        include BZTOOL_SAMPLE_DIR . 'views/page-agent-profile.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
