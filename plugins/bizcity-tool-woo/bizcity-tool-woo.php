<?php
/**
 * Plugin Name:       BizCity Tool — WooCommerce
 * Plugin URI:        https://bizcity.vn/marketplace/bizcity-tool-woo
 * Description:       Bộ công cụ AI quản lý WooCommerce: tạo/sửa sản phẩm, tạo đơn hàng, thống kê doanh thu, top sản phẩm, tra cứu khách hàng, báo cáo kho. Tất cả qua chat AI.
 * Short Description: Chat AI quản lý WooCommerce — sản phẩm, đơn hàng, kho hàng, doanh thu.
 * Quick View:        🛒 Chat → Tạo SP / Đơn hàng / Báo cáo doanh thu tự động
 * Version:           2.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            BizCity
 * Author URI:        https://bizcity.vn
 * Text Domain:       bizcity-tool-woo
 * Role:              agent
 * Featured:          true
 * Credit:            0
 * Price:             0
 * Icon Path:         /assets/icon.png
 * Cover URI:         https://media.bizcity.vn/uploads/sites/1258/2026/03/The-Best-AI-Content-Tools-For-WooCommerce-Stores-Visser-Labs1.png
 * Template Page:     tool-woo
 * Plan:              pro
 * Category:          ecommerce, woocommerce, bán hàng
 * Tags:              woocommerce, sản phẩm, đơn hàng, kho hàng, doanh thu, inventory, AI tool, ecommerce, báo cáo
 *
 * === Giới thiệu ===
 * BizCity Tool WooCommerce cho phép quản lý toàn bộ cửa hàng WooCommerce
 * qua chat AI. Không cần vào admin — AI xử lý từ đăng sản phẩm, tạo đơn
 * hàng đến xuất báo cáo doanh thu, kho hàng.
 *
 * === Tính năng chính ===
 * • Tạo & sửa sản phẩm (tên, giá, ảnh, danh mục) qua chat
 * • Tạo đơn hàng mới từ mô tả tự nhiên
 * • Thống kê doanh thu theo ngày / tuần / tháng
 * • Top sản phẩm bán chạy, top khách hàng
 * • Tra cứu khách hàng theo SĐT
 * • Báo cáo xuất nhập tồn kho
 * • Tạo phiếu nhập kho bằng AI
 * • Pipeline-ready: output product_id / order_id cho bước tiếp theo
 *
 * === Yêu cầu hệ thống ===
 * • BizCity Intent Engine (bizcity-intent) >= 2.4.0
 * • WooCommerce >= 7.0
 * • bizcity-openrouter (AI gateway)
 *
 * === Hướng dẫn kích hoạt ===
 * Kích hoạt plugin. Tool tự đăng ký vào Intent Engine.
 * Truy cập /tool-woo/ để mở WooCommerce Studio.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Twin AI Core Dependency ── */
if ( ! defined( 'BIZCITY_TWIN_AI_VERSION' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>BizCity Tool — WooCommerce</strong> yêu cầu plugin <strong>Bizcity Twin AI</strong> được cài đặt và kích hoạt. ';
        echo 'Tải về tại <a href="https://github.com/hoanganh8389/bizcity-twin-ai/" target="_blank">github.com/hoanganh8389/bizcity-twin-ai</a>.';
        echo '</p></div>';
    });
    return;
}

define( 'BZTOOL_WOO_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BZTOOL_WOO_URL',     plugin_dir_url( __FILE__ ) );
define( 'BZTOOL_WOO_VERSION', '2.0.0' );
define( 'BZTOOL_WOO_SLUG',    'bizcity-tool-woo' );

require_once BZTOOL_WOO_DIR . 'includes/class-tools-woo.php';
require_once BZTOOL_WOO_DIR . 'includes/class-post-type.php';
require_once BZTOOL_WOO_DIR . 'includes/class-ajax-woo.php';
require_once BZTOOL_WOO_DIR . 'includes/admin-menu.php';

/* ── CPT registration on init ── */
BizCity_Woo_Post_Type::init();
add_action( 'init', [ 'BizCity_Ajax_Woo', 'init' ] );

/* ══════════════════════════════════════════════════════════════
 *  Register Intent Provider — one array config, no class needed
 * ══════════════════════════════════════════════════════════════ */
add_action( 'bizcity_intent_register_providers', function ( $registry ) {

    bizcity_intent_register_plugin( $registry, [

        'id'   => 'tool-woo',
        'name' => 'BizCity Tool — WooCommerce (Quản lý cửa hàng)',

        /* ── Goal patterns (Router) ─────────────────────────
         * ORDER MATTERS: specific patterns first
         */
        'patterns' => [
            // Products — specific first
            '/sửa sản phẩm|cập nhật sản phẩm|chỉnh giá|đổi tên sp|edit product|update sp/ui' => [
                'goal' => 'edit_product', 'label' => 'Sửa sản phẩm',
                'description' => 'Sửa/cập nhật thông tin sản phẩm WooCommerce đã có (tên, giá, mô tả, ảnh, danh mục)',
                'extract' => [ 'product_id', 'field', 'new_value' ],
            ],
            '/tạo sản phẩm|đăng sản phẩm|thêm sản phẩm|thêm hàng|tao san pham/ui' => [
                'goal' => 'create_product', 'label' => 'Tạo sản phẩm',
                'description' => 'Tạo sản phẩm WooCommerce MỚI (áo, quần, giày, đồ ăn, dịch vụ...) với tên, giá, mô tả, ảnh',
                'extract' => [ 'title', 'price', 'description', 'image_url' ],
            ],

            // Orders
            '/tạo đơn hàng|tạo đơn|đặt hàng|đơn hàng mới|tao don hang/ui' => [
                'goal' => 'create_order', 'label' => 'Tạo đơn hàng',
                'description' => 'Tạo đơn hàng WooCommerce mới cho khách (tên khách, SĐT, sản phẩm, địa chỉ)',
                'extract' => [ 'customer_name', 'products', 'phone' ],
            ],

            // Customer — before generic stats
            '/tìm khách|tra khách|check khách|tìm sdt|tra sdt|khách hàng.*\d{8,}/ui' => [
                'goal' => 'find_customer', 'label' => 'Tìm khách hàng',
                'description' => 'Tra cứu thông tin khách hàng theo SĐT, tên, email',
                'extract' => [ 'phone', 'search_term' ],
            ],

            // Inventory — specific before generic stats
            '/nhập kho|phiếu nhập|tạo phiếu nhập|warehouse receipt/ui' => [
                'goal' => 'warehouse_receipt', 'label' => 'Tạo phiếu nhập kho',
                'description' => 'Tạo phiếu nhập kho hàng hóa (tên SP, số lượng, giá mua)',
                'extract' => [ 'content' ],
            ],
            '/xuất nhập tồn|xnt|báo cáo kho|tồn kho|inventory/ui' => [
                'goal' => 'inventory_report', 'label' => 'Báo cáo kho',
                'description' => 'Xem báo cáo xuất nhập tồn kho, số lượng hàng còn, hàng đã bán',
                'extract' => [ 'from_date', 'to_date' ],
            ],

            // Stats — specific before generic
            '/top sản phẩm|hàng bán chạy|sản phẩm bán nhiều|product stats/ui' => [
                'goal' => 'product_stats', 'label' => 'Top sản phẩm bán chạy',
                'description' => 'Thống kê top sản phẩm bán chạy nhất theo thời gian',
                'extract' => [ 'so_ngay' ],
            ],
            '/top khách|thống kê khách|khách hàng vip|customer stats/ui' => [
                'goal' => 'customer_stats', 'label' => 'Top khách hàng',
                'description' => 'Thống kê top khách hàng mua nhiều nhất, khách VIP',
                'extract' => [ 'so_ngay' ],
            ],
            '/báo cáo|thống kê|doanh thu|doanh số|report|đơn hôm nay|đơn tuần|xem đơn/ui' => [
                'goal' => 'order_stats', 'label' => 'Thống kê đơn hàng',
                'description' => 'Báo cáo doanh thu, thống kê đơn hàng theo ngày/tuần/tháng',
                'extract' => [ 'so_ngay', 'from_date', 'to_date' ],
            ],
        ],

        /* ── Plans (Planner slot gathering) ─────────────── */
        'plans' => [
            'create_product' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả sản phẩm cần tạo (tên, giá, mô tả, danh mục):', 'no_auto_map' => true ],
                ],
                'optional_slots' => [
                    'image_url' => [ 'type' => 'image', 'prompt' => 'Ảnh sản phẩm? Gửi ảnh hoặc link. Gõ "bỏ qua" nếu không có.', 'default' => '' ],
                ],
                'tool' => 'create_product', 'ai_compose' => false,
                'slot_order' => [ 'topic', 'image_url' ],
            ],
            'edit_product' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Sản phẩm cần sửa gì? (ví dụ: sửa giá SP #123 thành 200k)', 'no_auto_map' => true ],
                ],
                'optional_slots' => [],
                'tool' => 'edit_product', 'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
            'create_order' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả đơn hàng (khách hàng, sản phẩm, SĐT, địa chỉ):', 'no_auto_map' => true ],
                ],
                'optional_slots' => [],
                'tool' => 'create_order', 'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
            'order_stats' => [
                'required_slots' => [],
                'optional_slots' => [
                    'so_ngay'   => [ 'type' => 'number', 'default' => 7 ],
                    'from_date' => [ 'type' => 'date' ],
                    'to_date'   => [ 'type' => 'date' ],
                ],
                'tool' => 'order_stats', 'ai_compose' => false,
                'slot_order' => [],
            ],
            'product_stats' => [
                'required_slots' => [],
                'optional_slots' => [
                    'so_ngay' => [ 'type' => 'number', 'default' => 3 ],
                ],
                'tool' => 'product_stats', 'ai_compose' => false,
                'slot_order' => [],
            ],
            'customer_stats' => [
                'required_slots' => [],
                'optional_slots' => [
                    'so_ngay' => [ 'type' => 'number', 'default' => 3 ],
                ],
                'tool' => 'customer_stats', 'ai_compose' => false,
                'slot_order' => [],
            ],
            'find_customer' => [
                'required_slots' => [
                    'phone' => [ 'type' => 'text', 'prompt' => 'Số điện thoại khách hàng cần tra cứu?' ],
                ],
                'optional_slots' => [],
                'tool' => 'find_customer', 'ai_compose' => false,
                'slot_order' => [ 'phone' ],
            ],
            'inventory_report' => [
                'required_slots' => [],
                'optional_slots' => [
                    'from_date' => [ 'type' => 'date' ],
                    'to_date'   => [ 'type' => 'date' ],
                ],
                'tool' => 'inventory_report', 'ai_compose' => false,
                'slot_order' => [],
            ],
            'warehouse_receipt' => [
                'required_slots' => [
                    'topic' => [ 'type' => 'text', 'prompt' => 'Mô tả phiếu nhập kho (tên SP, số lượng, giá mua):', 'no_auto_map' => true ],
                ],
                'optional_slots' => [],
                'tool' => 'warehouse_receipt', 'ai_compose' => false,
                'slot_order' => [ 'topic' ],
            ],
        ],

        /* ── Tools (callbacks) ──────────────────────────── */
        'tools' => [
            'create_product' => [
                'schema' => [
                    'description'  => 'Tạo sản phẩm WooCommerce mới (AI phân tích → upload ảnh → tạo SP)',
                    'input_fields' => [
                        'topic'     => [ 'required' => true,  'type' => 'text' ],
                        'image_url' => [ 'required' => false, 'type' => 'image' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'create_product' ],
            ],
            'edit_product' => [
                'schema' => [
                    'description'  => 'Sửa/cập nhật thông tin sản phẩm WooCommerce đã có',
                    'input_fields' => [
                        'topic' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'edit_product' ],
            ],
            'create_order' => [
                'schema' => [
                    'description'  => 'Tạo đơn hàng WooCommerce mới (AI phân tích → tạo đơn + billing)',
                    'input_fields' => [
                        'topic' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'create_order' ],
            ],
            'order_stats' => [
                'schema' => [
                    'description'  => 'Thống kê doanh thu, đơn hàng theo ngày/tuần/tháng',
                    'input_fields' => [
                        'so_ngay'   => [ 'required' => false, 'type' => 'number' ],
                        'from_date' => [ 'required' => false, 'type' => 'date' ],
                        'to_date'   => [ 'required' => false, 'type' => 'date' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'order_stats' ],
            ],
            'product_stats' => [
                'schema' => [
                    'description'  => 'Top sản phẩm bán chạy nhất theo thời gian',
                    'input_fields' => [
                        'so_ngay' => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'product_stats' ],
            ],
            'customer_stats' => [
                'schema' => [
                    'description'  => 'Top khách hàng mua nhiều nhất, khách VIP',
                    'input_fields' => [
                        'so_ngay' => [ 'required' => false, 'type' => 'number' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'customer_stats' ],
            ],
            'find_customer' => [
                'schema' => [
                    'description'  => 'Tra cứu thông tin khách hàng theo SĐT, tên, email',
                    'input_fields' => [
                        'phone' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'find_customer' ],
            ],
            'inventory_report' => [
                'schema' => [
                    'description'  => 'Báo cáo xuất nhập tồn kho, hàng còn, hàng đã bán',
                    'input_fields' => [
                        'from_date' => [ 'required' => false, 'type' => 'date' ],
                        'to_date'   => [ 'required' => false, 'type' => 'date' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'inventory_report' ],
            ],
            'warehouse_receipt' => [
                'schema' => [
                    'description'  => 'Tạo phiếu nhập kho hàng hóa (tên SP, số lượng, giá mua)',
                    'input_fields' => [
                        'topic' => [ 'required' => true, 'type' => 'text' ],
                    ],
                ],
                'callback' => [ 'BizCity_Tool_Woo', 'warehouse_receipt' ],
            ],
        ],

        /* ── Context (optional) ─────────────────────────── */
        'context' => function ( $goal, $slots, $user_id, $conversation ) {
            $goals = [
                'create_product'    => 'Tạo sản phẩm WooCommerce mới từ mô tả',
                'edit_product'      => 'Sửa thông tin sản phẩm WooCommerce',
                'create_order'      => 'Tạo đơn hàng WooCommerce mới',
                'order_stats'       => 'Thống kê doanh thu đơn hàng',
                'product_stats'     => 'Báo cáo top sản phẩm bán chạy',
                'customer_stats'    => 'Thống kê top khách hàng',
                'find_customer'     => 'Tra cứu khách hàng theo SĐT',
                'inventory_report'  => 'Báo cáo xuất nhập tồn kho',
                'warehouse_receipt' => 'Tạo phiếu nhập kho từ mô tả',
            ];
            return "Plugin: BizCity WooCommerce Tools\n"
                . 'Mục tiêu: ' . ( $goals[ $goal ] ?? $goal ) . "\n"
                . "Hỗ trợ: quản lý sản phẩm, đơn hàng, kho hàng, doanh thu.\n"
                . "Định dạng số điện thoại VN: 0xx xxxx xxxx. Đơn vị tiền: VNĐ.\n";
        },
    ] );
} );

/* ══════════════════════════════════════════════════════════════
 *  TEMPLATE PAGE — Agent Profile with guided commands
 *  Touch Bar clicks → /tool-woo/?bizcity_iframe=1 → profile view
 * ══════════════════════════════════════════════════════════════ */
add_action( 'init', function() {
    add_rewrite_rule( '^tool-woo/?$', 'index.php?bizcity_agent_page=tool-woo', 'top' );
} );
add_filter( 'query_vars', function( $vars ) {
    if ( ! in_array( 'bizcity_agent_page', $vars, true ) ) $vars[] = 'bizcity_agent_page';
    return $vars;
} );
add_action( 'template_redirect', function() {
    if ( get_query_var( 'bizcity_agent_page' ) === 'tool-woo' ) {
        include BZTOOL_WOO_DIR . 'views/page-agent-profile.php';
        exit;
    }
} );

register_activation_hook( __FILE__, function() { flush_rewrite_rules(); } );
register_deactivation_hook( __FILE__, function() { flush_rewrite_rules(); } );