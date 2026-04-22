<?php
/**
 * Plugin Name:       Mindmap
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-mindmap
 * Description:       AI tạo mindmap, flowchart, sơ đồ quy trình từ prompt. Lưu + xem dưới dạng Mermaid, kèm trang editor tương tác mobile-first.
 * Short Description: Chat để vẽ mindmap, flowchart, lưu đồ bằng AI — xem & chỉnh sửa online.
 * Quick View:        🧠 Nhập mô tả → AI vẽ mindmap/flow → Xem & Chỉnh sửa
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-mindmap
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * public:            false
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/mindmap.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/ai-tao-mindmap-11.jpg
 * Template Page:     tool-mindmap
 * Category:          diagram, mindmap, flowchart, mermaid
 * Tags:              mindmap, flowchart, diagram, mermaid, sơ đồ, lưu đồ, quy trình, AI tool
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Tool Mindmap giúp tạo mindmap, flowchart, sơ đồ quy trình, lưu đồ
 * từ ngôn ngữ tự nhiên. AI tạo Mermaid syntax → render đồ họa → lưu & quản lý.
 *
 * === Tính năng chính ===
 * • Tạo mindmap, flowchart, sequence, class, gantt, pie, state diagram từ prompt
 * • AI tự nhận diện loại sơ đồ phù hợp hoặc user chọn
 * • Trang editor tương tác (như mermaid.live) — mobile first
 * • Lưu lịch sử, xem lại, chỉnh sửa, xóa
 * • Tích hợp Intent Engine: chat → AI vẽ sơ đồ → trả link xem
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenRouter API (Gemini Flash recommended)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-mindmap/ để mở Mindmap Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Mindmap</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZTOOL_MINDMAP_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_MINDMAP_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_MINDMAP_VERSION', '1.0.0' );
define( 'BZTOOL_MINDMAP_SLUG',    'bizcity-tool-mindmap' );

require_once BZTOOL_MINDMAP_DIR . 'includes/class-tools-mindmap.php';

/* ══════════════════════════════════════════════════════════════
 *  Register Intent Provider — patterns → plans → tools
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-mindmap',
        'name' => 'BizCity Tool — Mindmap & Diagram',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first
         */
        'patterns' => [
            '/mindmap|mind\s*map|sơ đồ tư duy|bản đồ tư duy/ui' => [
                'goal'        => 'create_mindmap',
                'label'       => 'Tạo Mindmap',
                'description' => 'Vẽ sơ đồ tư duy (mindmap) từ chủ đề hoặc mô tả',
                'extract'     => [ 'message', 'topic' ],
            ],
            '/flowchart|flow\s*chart|lưu đồ|sơ đồ luồng|biểu đồ luồng/ui' => [
                'goal'        => 'create_flowchart',
                'label'       => 'Tạo Flowchart',
                'description' => 'Vẽ lưu đồ / flowchart mô tả quy trình, luồng xử lý',
                'extract'     => [ 'message', 'topic' ],
            ],
            '/sơ đồ quy trình|quy trình làm|workflow|luồng công việc|process.*diagram/ui' => [
                'goal'        => 'create_process',
                'label'       => 'Vẽ sơ đồ quy trình',
                'description' => 'Vẽ sơ đồ quy trình / workflow từ mô tả chi tiết',
                'extract'     => [ 'message', 'topic' ],
            ],
            '/vẽ sơ đồ|tạo sơ đồ|vẽ biểu đồ|tạo biểu đồ|diagram|mermaid|vẽ.*đồ|sơ đồ/ui' => [
                'goal'        => 'create_diagram',
                'label'       => 'Vẽ sơ đồ',
                'description' => 'Vẽ sơ đồ bất kỳ (mindmap, flowchart, sequence, class, gantt, pie…) từ mô tả',
                'extract'     => [ 'message', 'topic', 'diagram_type' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_mindmap' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Bạn muốn vẽ mindmap về chủ đề gì? Mô tả càng chi tiết, mindmap càng đẹp 🧠' ],
                ],
                'optional_slots' => [],
                'tool'       => 'create_diagram',
                'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
            'create_flowchart' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả quy trình / luồng xử lý bạn muốn vẽ flowchart nhé 📊' ],
                ],
                'optional_slots' => [],
                'tool'       => 'create_diagram',
                'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
            'create_process' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả quy trình bạn muốn tạo sơ đồ nhé ⚙️' ],
                ],
                'optional_slots' => [],
                'tool'       => 'create_diagram',
                'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
            'create_diagram' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả sơ đồ bạn muốn vẽ — có thể là mindmap, flowchart, sequence, class diagram... 🎨' ],
                ],
                'optional_slots' => [
                    'diagram_type' => [
                        'type'    => 'choice',
                        'prompt'  => 'Loại sơ đồ? (bỏ qua để AI tự chọn)',
                        'choices' => [ 'mindmap', 'flowchart', 'sequence', 'class', 'gantt', 'pie', 'state', 'auto' ],
                        'default' => 'auto',
                    ],
                ],
                'tool'       => 'create_diagram',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'diagram_type' ],
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_diagram' => [
                'schema' => [
                    'description'  => 'Vẽ sơ đồ (mindmap, flowchart, sequence, class, gantt, pie, state) bằng Mermaid',
                    'input_fields' => [
                        'topic'        => [ 'required' => true,  'type' => 'text' ],
                        'diagram_type' => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Mindmap', 'create_diagram' ],
            ],
        ],

        /* ── Examples (Tools Map hints) ─────────────────── */
        'examples' => [
            'create_diagram' => [
                'Vẽ mindmap về Digital Marketing',
                'Tạo flowchart quy trình tuyển dụng nhân sự',
                'Sơ đồ quy trình xử lý đơn hàng e-commerce',
                'Mindmap kế hoạch kinh doanh năm 2026',
                'Vẽ sơ đồ sequence cho API thanh toán',
                'Tạo gantt chart dự án phát triển app',
            ],
        ],

        /* ── Context (optional) ─────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals = [
                'create_mindmap'   => 'Vẽ mindmap / sơ đồ tư duy',
                'create_flowchart' => 'Vẽ flowchart / lưu đồ',
                'create_process'   => 'Vẽ sơ đồ quy trình / workflow',
                'create_diagram'   => 'Vẽ sơ đồ tùy chọn (Mermaid)',
            ];
            return "Plugin: BizCity Tool Mindmap\n"
                . 'Mục tiêu: ' . ( $goals[ $goal ] ?? $goal ) . "\n"
                . "Hỗ trợ: mindmap, flowchart, sequence diagram, class diagram, gantt, pie chart, state diagram\n"
                . "Output: Mermaid syntax, lưu dưới dạng post + meta, có trang xem/sửa online.\n";
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  Register as Notebook Tool — BCN_Notebook_Tool_Registry
 *  Allows Companion Notebook Studio to delegate mindmap creation.
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bcn_register_notebook_tools', function ( $registry ) {
    $registry->add( [
        'type'      => 'mindmap',
        'label'     => 'Bản đồ tư duy',
        'icon'      => '🗺️',
        'color'     => 'pink',
        'mode'      => 'delegate',
        'available' => true,
        'callback'  => function ( array $skeleton ) {
            // Adapter: Skeleton JSON → create_diagram.
            // Build rich topic from skeleton structure instead of raw text.
            $parts = [];

            if ( ! empty( $skeleton['nucleus']['title'] ) ) {
                $parts[] = 'Chủ đề chính: ' . $skeleton['nucleus']['title'];
            }
            if ( ! empty( $skeleton['nucleus']['thesis'] ) ) {
                $parts[] = 'Luận điểm: ' . $skeleton['nucleus']['thesis'];
            }

            // Skeleton tree → structure hints for mindmap.
            if ( ! empty( $skeleton['skeleton'] ) ) {
                $parts[] = "\nCấu trúc:";
                foreach ( $skeleton['skeleton'] as $node ) {
                    $parts[] = '- ' . $node['label'] . ( ! empty( $node['summary'] ) ? ': ' . $node['summary'] : '' );
                    foreach ( $node['children'] ?? [] as $child ) {
                        $parts[] = '  - ' . $child['label'] . ( ! empty( $child['summary'] ) ? ': ' . $child['summary'] : '' );
                    }
                }
            }

            if ( ! empty( $skeleton['key_points'] ) ) {
                $parts[] = "\nĐiểm chính:";
                foreach ( $skeleton['key_points'] as $kp ) {
                    $parts[] = '- ' . $kp;
                }
            }

            $topic = implode( "\n", $parts );

            // Fallback: raw text if skeleton extraction failed.
            if ( empty( $topic ) ) {
                $topic = BCN_Studio_Input_Builder::to_text( $skeleton );
            }

            return BizCity_Tool_Mindmap::create_diagram( [
                'topic'        => $topic,
                'diagram_type' => 'graph TD',
                '_meta'        => [
                    '_context' => mb_substr( $topic, 0, 1200 ),
                    'channel'  => 'notebook',
                    'blog_id'  => get_current_blog_id(),
                ],
            ] );
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Mindmap Studio SPA
 *  /tool-mindmap/ → Mobile-first editor + history + AI generate
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-mindmap/?$', 'index.php?bizcity_agent_page=tool-mindmap', 'top' );
    add_rewrite_rule( '^mindmap/?$', 'index.php?bizcity_agent_page=mindmap', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    $page = get_query_var( 'bizcity_agent_page' );
    if ( $page === 'tool-mindmap' ) {
        include BZTOOL_MINDMAP_DIR . 'views/page-mindmap.php';
        exit;
    }
    if ( $page === 'mindmap' ) {
        include BZTOOL_MINDMAP_DIR . 'views/page-mindmap-react.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
