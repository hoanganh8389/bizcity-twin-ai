<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Helper_Legacy
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * Legacy Intent Provider — Business automation goal patterns.
 *
 * Migrated from: mu-plugins/bizcity-admin-hook/includes/class-intent-provider.php
 * Date: 2026-03-30
 *
 * @package BizCity_Twin_AI
 * @subpackage Helper_Legacy
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'BizCity_AdminHook_Intent_Provider' ) ) :
class BizCity_AdminHook_Intent_Provider extends BizCity_Intent_Provider {

    /* ── Identity ── */

    public function get_id() {
        return 'admin-hook';
    }

    public function get_name() {
        return 'BizCity Admin Hook — Tự động hóa kinh doanh';
    }

    /* ── Goal Patterns (Router) ── */

    public function get_goal_patterns() {
        return [
            // Product management
            '/tạo sản phẩm|đăng sản phẩm|thêm sản phẩm|tao san pham/ui' => [
                'goal' => 'create_product', 'label' => 'Tạo sản phẩm',
                'extract' => [ 'title', 'price', 'description', 'image_url' ],
            ],
            '/sửa sản phẩm|edit product|chỉnh sửa sp|cập nhật sp|update product/ui' => [
                'goal' => 'edit_product', 'label' => 'Sửa sản phẩm',
                'extract' => [ 'product_id', 'field', 'new_value' ],
            ],

            // Reports & statistics
            '/báo cáo|thống kê|report|doanh thu|doanh số/ui' => [
                'goal' => 'report', 'label' => 'Báo cáo thống kê',
                'extract' => [ 'report_type', 'date_range', 'metric' ],
            ],
            '/xuất nhập tồn|xnt|tồn kho|inventory/ui' => [
                'goal' => 'inventory_report', 'label' => 'Báo cáo xuất nhập tồn',
                'extract' => [ 'from_date', 'to_date', 'so_ngay' ],
            ],

            // Orders
            '/danh sách đơn hàng|đơn hàng|orders/ui' => [
                'goal' => 'list_orders', 'label' => 'Xem đơn hàng',
                'extract' => [ 'date_range', 'status_filter' ],
            ],
            '/tạo đơn hàng|đơn hàng mới|tạo đơn|tao don/ui' => [
                'goal' => 'create_order', 'label' => 'Tạo đơn hàng',
                'extract' => [ 'customer_name', 'products', 'phone' ],
            ],

            // Social & Content
            '/đăng facebook|đăng fb|post facebook|đăng bài facebook/ui' => [
                'goal' => 'post_facebook', 'label' => 'Đăng bài Facebook',
                'extract' => [ 'content', 'image_url', 'page_id' ],
            ],
            '/viết bài|viet bai|đăng bài|soạn bài|tạo bài viết/ui' => [
                'goal' => 'write_article', 'label' => 'Viết bài',
                'extract' => [ 'topic', 'tone', 'length' ],
            ],

            // Customer
            '/tìm khách hàng|khách hàng|customer|danh sách kh/ui' => [
                'goal' => 'find_customer', 'label' => 'Tìm khách hàng',
                'extract' => [ 'search_term', 'phone', 'name' ],
            ],
            '/thống kê khách hàng|top khách|khách hàng vip|customer stats/ui' => [
                'goal' => 'customer_stats', 'label' => 'Thống kê khách hàng',
                'extract' => [ 'so_ngay', 'from_date', 'to_date' ],
            ],

            // Scheduling
            '/nhắc việc|reminder|nhắc nhở|hẹn lịch|lên lịch/ui' => [
                'goal' => 'set_reminder', 'label' => 'Nhắc việc',
                'extract' => [ 'what', 'when', 'repeat' ],
            ],

            // Video
            '/tạo video|làm video|quay video|video sản phẩm|video quảng cáo|tao video|video clip/ui' => [
                'goal' => 'create_video', 'label' => 'Tạo video',
                'extract' => [ 'title', 'content', 'duration', 'image_url' ],
            ],

            // Product/Inventory stats
            '/thống kê hàng hóa|top sản phẩm|hàng bán chạy|product stats/ui' => [
                'goal' => 'product_stats', 'label' => 'Thống kê hàng hóa',
                'extract' => [ 'so_ngay', 'from_date', 'to_date' ],
            ],
            '/nhật ký xuất nhập|nhật ký kho|nhat ky xnt|inventory journal/ui' => [
                'goal' => 'inventory_journal', 'label' => 'Nhật ký xuất nhập',
                'extract' => [ 'from_date', 'to_date', 'so_ngay' ],
            ],
            '/nhập kho|phiếu nhập|nhap kho|warehouse receipt/ui' => [
                'goal' => 'warehouse_receipt', 'label' => 'Nhập kho',
                'extract' => [ 'content' ],
            ],

            // Help
            '/hướng dẫn|hdsd|help|cách sử dụng|guide/ui' => [
                'goal' => 'help_guide', 'label' => 'Hướng dẫn sử dụng',
                'extract' => [ 'topic' ],
            ],
        ];
    }

    /* ── Plans — deferred to class-intent-planner.php ── */

    public function get_plans() {
        return [];
    }

    /* ── Tools — registered via BizCity_Intent_Tools::init_builtin() ── */

    public function get_tools() {
        return [];
    }

    /* ── Context Building ── */

    public function build_context( $goal, array $slots, $user_id, array $conversation ) {
        $report_goals = [
            'report', 'inventory_report', 'customer_stats',
            'product_stats', 'inventory_journal', 'list_orders',
        ];

        if ( in_array( $goal, $report_goals, true ) ) {
            return "=== NGÀY GIỜ HIỆN TẠI ===\n"
                . "Ngày: " . date_i18n( 'd/m/Y' ) . "\n"
                . "Giờ: " . date_i18n( 'H:i' ) . "\n"
                . "Thứ: " . date_i18n( 'l' );
        }

        return '';
    }

    /**
     * System instructions for business automation goals.
     */
    public function get_system_instructions( $goal ) {
        $is_content = in_array( $goal, [ 'write_article', 'post_facebook' ], true );
        if ( $is_content ) {
            return "Bạn là chuyên gia content marketing. Viết nội dung hấp dẫn, phù hợp target audience. "
                . "Sử dụng emoji và format rõ ràng. Tối ưu cho engagement trên nền tảng tương ứng.";
        }

        $is_report = in_array( $goal, [ 'report', 'inventory_report', 'customer_stats', 'product_stats' ], true );
        if ( $is_report ) {
            return "Trình bày dữ liệu rõ ràng, có bảng/danh sách. Highlight số liệu quan trọng. "
                . "Đưa ra nhận xét ngắn gọn về xu hướng (tăng/giảm so với kỳ trước nếu có).";
        }

        return '';
    }
}
endif; // class_exists guard
