<?php
/**
 * BizCity Tool Image — Admin Menu
 *
 * @package BizCity_Tool_Image
 * @since   2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_menu_page(
        'Image AI',
        'Image AI',
        'manage_options',
        'bztimg-dashboard',
        'bztimg_admin_dashboard',
        'dashicons-format-image',
        58
    );
} );

function bztimg_admin_dashboard() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztimg_jobs';
    $total = 0;
    $done  = 0;
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) === $table ) {
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $done  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'completed'" );
    }
    ?>
    <div class="wrap">
        <h1>🎨 BizCity Image AI — Dashboard</h1>
        <div style="display:flex;gap:16px;margin-top:16px;">
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3>📊 Thống kê</h3>
                <p>Tổng ảnh đã tạo: <strong><?php echo esc_html( $total ); ?></strong></p>
                <p>Hoàn thành: <strong><?php echo esc_html( $done ); ?></strong></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3>⚙️ Cài đặt nhanh</h3>
                <p>Model mặc định: <strong><?php echo esc_html( get_option( 'bztimg_default_model', 'flux-pro' ) ); ?></strong></p>
                <p>API Key: <?php echo get_option( 'bztimg_api_key' ) ? '✅ Đã cấu hình' : '❌ Chưa cấu hình'; ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;border:1px solid #e5e7eb;flex:1;">
                <h3>🔗 Liên kết</h3>
                <p><a href="<?php echo esc_url( home_url( '/tool-image/' ) ); ?>" target="_blank">Mở Profile View →</a></p>
            </div>
        </div>
    </div>
    <?php
}
