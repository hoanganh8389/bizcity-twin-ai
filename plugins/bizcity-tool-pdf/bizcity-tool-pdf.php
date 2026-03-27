<?php
/**
 * Plugin Name:       Tạo Tài Liệu PDF
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-pdf
 * Description:       AI tạo tài liệu A4 đẹp, chuẩn in ấn từ prompt. Xuất HTML single-file, in thành PDF ngay trên trình duyệt — kèm trang xem & lịch sử tài liệu.
 * Short Description: Chat để tạo tài liệu PDF chuyên nghiệp bằng AI — xem & in ngay online.
 * Quick View:        📄 Nhập nội dung → AI soạn tài liệu A4 → Xem & In PDF
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-pdf
 * Role:              agent
 * Featured:          true
 * Notebook:          true
 * public:            false
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/pdf.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/ai-document-pdf-cover.png
 * Template Page:     tool-pdf
 * Category:          document, pdf, report, academic
 * Tags:              pdf, tài liệu, báo cáo, học thuật, kỹ thuật, in ấn, AI tool
 * Plan:              free
 *
 * === Giới thiệu ===
 * BizCity Tool PDF giúp tạo tài liệu A4 chuyên nghiệp từ mô tả hoặc nội dung dự án.
 * AI thiết kế HTML+CSS chuẩn in → preview & in PDF trực tiếp trên trình duyệt.
 *
 * === Tính năng chính ===
 * • Tạo tài liệu A4 từ prompt hoặc skeleton dự án bằng AI
 * • Các loại: Báo cáo, Học thuật, Kỹ thuật, Đề xuất, Tóm tắt điều hành, Hướng dẫn
 * • CSS @page A4 + @media print chuẩn — in thẳng từ trình duyệt
 * • Nút in nổi 🖨 + chế độ print preview tự động
 * • Lưu lịch sử, xem lại, xóa tài liệu
 * • Tích hợp Intent Engine: chat → AI tạo tài liệu → trả link xem
 * • Tích hợp Notebook Studio: skeleton JSON → tài liệu từ nội dung dự án
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) ≥ 2.4.0
 * • OpenRouter API (Claude Sonnet 4.5 recommended)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine và Notebook Studio.
 * Truy cập /tool-pdf/ để mở PDF Studio.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>Tạo Tài Liệu PDF</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZTOOL_PDF_VER',  '1.0.0' );
define( 'BZTOOL_PDF_DIR',  plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_PDF_URL',  plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_PDF_SLUG', 'tool-pdf' );

require_once BZTOOL_PDF_DIR . 'includes/class-tools-pdf.php';

/* ════════════════════════════════════════════════════════════════════
 *  1.  Intent Provider — route PDF requests from Telegram / API
 * ════════════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-pdf',
        'name' => 'BizCity Tool — PDF Document',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first
         */
        'patterns' => [
            '/tạo.*pdf|pdf.*tài\s*liệu|tài\s*liệu.*pdf/ui' => [
                'goal'        => 'create_pdf_document',
                'label'       => 'Tạo tài liệu PDF',
                'description' => 'Tạo tài liệu A4 chuẩn in từ mô tả nội dung',
                'extract'     => [ 'message', 'topic', 'doc_type' ],
            ],
            '/báo\s*cáo|tài\s*liệu|xuất\s*pdf|in\s*(ấn|pdf)|lưu\s*pdf|document\b/ui' => [
                'goal'        => 'create_pdf_document',
                'label'       => 'Tạo tài liệu PDF',
                'description' => 'Tạo tài liệu A4 chuẩn in từ mô tả nội dung',
                'extract'     => [ 'message', 'topic', 'doc_type' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_pdf_document' => [
                'required_slots' => [
                    'topic' => [
                        'type'   => 'text',
                        'prompt' => 'Bạn muốn tạo tài liệu về nội dung gì? Mô tả càng chi tiết, tài liệu càng đẹp và đầy đủ 📄',
                    ],
                ],
                'optional_slots' => [
                    'doc_type' => [
                        'type'    => 'choice',
                        'prompt'  => 'Loại tài liệu? (bỏ qua để AI tự chọn)',
                        'choices' => [ 'auto', 'report', 'academic', 'technical', 'proposal', 'summary', 'guide' ],
                        'default' => 'auto',
                    ],
                ],
                'tool'       => 'create_pdf_document',
                'ai_compose' => false,
                'slot_order' => [ 'topic', 'doc_type' ],
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_pdf_document' => [
                'schema' => [
                    'description'  => 'Tạo tài liệu A4 chuẩn in từ mô tả nội dung — HTML+CSS single-file, in thành PDF từ trình duyệt',
                    'input_fields' => [
                        'topic'    => [ 'required' => true,  'type' => 'text' ],
                        'doc_type' => [ 'required' => false, 'type' => 'choice' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_PDF', 'create_pdf_document' ],
            ],
        ],

        /* ── Examples (Tools Map hints) ─────────────────── */
        'examples' => [
            'create_pdf_document' => [
                'Tạo báo cáo phân tích thị trường thương mại điện tử 2026',
                'Tài liệu kỹ thuật kiến trúc hệ thống microservices',
                'Đề xuất dự án xây dựng ứng dụng mobile cho startup',
                'Tóm tắt điều hành kế hoạch kinh doanh Q2',
                'Hướng dẫn onboarding nhân viên mới',
                'Nghiên cứu học thuật về AI trong giáo dục',
            ],
        ],

        /* ── Context (optional) ─────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            return "Plugin: BizCity Tool PDF\n"
                . "Mục tiêu: Tạo tài liệu A4 chuyên nghiệp, chuẩn in ấn\n"
                . "Hỗ trợ: 6 loại tài liệu, CSS @page A4, @media print, nút in nổi\n"
                . "Output: HTML+CSS single-file, lưu dưới dạng post + meta, có trang xem/in online.\n";
        },
    ] );
} );

/* ════════════════════════════════════════════════════════════════════
 *  2.  Notebook Studio Button — bcn_register_notebook_tools
 * ════════════════════════════════════════════════════════════════════ */
add_action( 'bcn_register_notebook_tools', function( $registry ) {
    $registry->add( [
        'type'      => 'pdf',
        'label'     => 'Tài liệu PDF',
        'icon'      => '📄',
        'color'     => 'blue',
        'mode'      => 'delegate',
        'available' => true,
        'callback'  => function( array $skeleton ) {

            /* ── Build rich topic string from skeleton ── */
            $nucleus    = $skeleton['nucleus']    ?? [];
            $key_points = $skeleton['key_points'] ?? [];
            $entities   = $skeleton['entities']   ?? [];
            $skel_items = $skeleton['skeleton']   ?? [];
            $raw_text   = $skeleton['_raw_text']  ?? '';

            $title  = $nucleus['title']  ?? '';
            $thesis = $nucleus['thesis'] ?? '';

            $topic = '';
            if ( $title )  $topic .= "Tiêu đề: {$title}\n";
            if ( $thesis ) $topic .= "Luận điểm: {$thesis}\n";

            if ( ! empty( $skel_items ) ) {
                $topic .= "\nDàn ý:\n";
                foreach ( $skel_items as $idx => $item ) {
                    $heading = is_array( $item ) ? ( $item['heading'] ?? $item['text'] ?? '' ) : $item;
                    if ( $heading ) $topic .= ( $idx + 1 ) . ". {$heading}\n";
                }
            }

            if ( ! empty( $key_points ) ) {
                $topic .= "\nĐiểm chính:\n";
                foreach ( array_slice( $key_points, 0, 10 ) as $kp ) {
                    $point = is_array( $kp ) ? ( $kp['text'] ?? $kp['point'] ?? '' ) : $kp;
                    if ( $point ) $topic .= "- {$point}\n";
                }
            }

            if ( ! empty( $entities ) ) {
                $names  = array_map( fn( $e ) => is_array( $e ) ? ( $e['name'] ?? $e['text'] ?? '' ) : $e, $entities );
                $names  = array_filter( $names );
                if ( $names ) $topic .= "\nThực thể liên quan: " . implode( ', ', array_slice( $names, 0, 8 ) ) . "\n";
            }

            if ( $raw_text && strlen( $topic ) < 200 ) {
                $topic .= "\nNội dung chi tiết:\n" . mb_substr( $raw_text, 0, 1200 );
            }

            $topic = trim( $topic );
            if ( ! $topic ) {
                $topic = $raw_text ? mb_substr( $raw_text, 0, 600 ) : 'Tài liệu không có nội dung.';
            }

            $meta_context = $skeleton['_meta']['_context'] ?? '';

            return BizCity_Tool_PDF::create_pdf_document( [
                'topic'      => $topic,
                'doc_type'   => 'auto',
                'session_id' => $skeleton['session_id'] ?? '',
                'chat_id'    => $skeleton['chat_id']    ?? '',
                '_meta'      => [ '_context' => $meta_context ],
            ] );
        },
    ] );
} );

/* ════════════════════════════════════════════════════════════════════
 *  3.  Routing — /tool-pdf/ + /tool-pdf/print/
 *      Pattern mirrors bizcity-tool-slide / bizcity-tool-mindmap.
 * ════════════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-pdf/print/?$', 'index.php?bizcity_agent_page=tool-pdf-print', 'top' );
    add_rewrite_rule( '^tool-pdf/?$',       'index.php?bizcity_agent_page=tool-pdf',       'top' );
} );

add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );

add_action( 'template_redirect', function() {
    $page = get_query_var( 'bizcity_agent_page' );

    /* ── /tool-pdf/print/?id=N — output raw printable HTML ── */
    if ( $page === 'tool-pdf-print' ) {
        $post_id = intval( $_GET['id'] ?? 0 );
        if ( ! $post_id ) { wp_die( 'Không tìm thấy tài liệu.', '', [ 'response' => 404 ] ); }

        $html = get_post_meta( $post_id, '_bz_pdf_html', true );
        if ( ! $html ) { wp_die( 'Tài liệu không có nội dung.', '', [ 'response' => 404 ] ); }

        // Inject auto-print trigger before </body>
        $auto_print = '<script>window.addEventListener("load",function(){'
            . 'setTimeout(function(){window.print();},400);});</script>';
        $html = str_ireplace( '</body>', $auto_print . '</body>', $html );

        header( 'Content-Type: text/html; charset=UTF-8' );
        echo $html;
        exit;
    }

    /* ── /tool-pdf/ + /tool-pdf/?id=N — SPA view ── */
    if ( $page === 'tool-pdf' ) {
        include BZTOOL_PDF_DIR . 'views/page-pdf.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );
