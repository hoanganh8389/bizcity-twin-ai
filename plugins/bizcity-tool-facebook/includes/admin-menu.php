<?php
/**
 * BizCity Tool Facebook — Admin Menu & Dashboard
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {
    add_submenu_page(
        'bizcity-facebook-bots',
        'Tool Facebook Dashboard',
        'AI Đăng bài',
        'manage_options',
        'bizcity-tool-facebook',
        'bztfb_render_admin_page'
    );
} );

function bztfb_render_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'bztfb_jobs';

    $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    $completed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'completed' ) );
    $pending   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status IN (%s, %s)", 'pending', 'generating' ) );

    // Connected pages
    $pages = get_option( 'fb_pages_connected', array() );
    $page_count = is_array( $pages ) ? count( $pages ) : 0;

    // Central webhook status
    $main_site_url = network_site_url( '/facehook/' );
    ?>
    <div class="wrap bizcity-tool-facebook-wrap">
        <h1>📣 BizCity Tool Facebook — Dashboard</h1>

        <div class="bztfb-stats" style="display:flex;gap:16px;margin:20px 0;">
            <div class="bztfb-stat-card" style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #1877f2;flex:1;">
                <h3 style="margin:0;color:#666;">Tổng bài đã đăng</h3>
                <p style="font-size:2em;margin:8px 0;font-weight:bold;"><?php echo esc_html( $completed ); ?></p>
            </div>
            <div class="bztfb-stat-card" style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #f0ad4e;flex:1;">
                <h3 style="margin:0;color:#666;">Đang xử lý</h3>
                <p style="font-size:2em;margin:8px 0;font-weight:bold;"><?php echo esc_html( $pending ); ?></p>
            </div>
            <div class="bztfb-stat-card" style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #5cb85c;flex:1;">
                <h3 style="margin:0;color:#666;">Page đã kết nối</h3>
                <p style="font-size:2em;margin:8px 0;font-weight:bold;"><?php echo esc_html( $page_count ); ?></p>
            </div>
        </div>

        <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;">
            <h2>🔗 Central Webhook</h2>
            <p>Webhook URL dùng chung cho toàn bộ multisite (không cần cấu hình riêng cho từng domain):</p>
            <code style="display:block;padding:10px;background:#f5f5f5;border-radius:4px;font-size:14px;">
                <?php echo esc_html( $main_site_url ); ?>
            </code>
            <p class="description">Cấu hình URL này trong Facebook Developer App → Webhooks.</p>
        </div>

        <div style="background:#fff;padding:20px;border-radius:8px;">
            <h2>📝 Bài đăng gần đây</h2>
            <?php
            $recent = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
                    10
                )
            );
            if ( $recent ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>ID</th><th>Tiêu đề</th><th>Trạng thái</th><th>Ngày tạo</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $recent as $job ) : ?>
                        <tr>
                            <td><?php echo esc_html( $job->id ); ?></td>
                            <td><?php echo esc_html( wp_trim_words( $job->ai_title ?: $job->topic, 10 ) ); ?></td>
                            <td>
                                <span class="bztfb-status bztfb-status-<?php echo esc_attr( $job->status ); ?>">
                                    <?php echo esc_html( $job->status ); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html( $job->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p>Chưa có bài đăng nào. Sử dụng chat để đăng bài lên Facebook!</p>
            <?php endif; ?>
        </div>
    </div>
    <style>
    .bztfb-status { padding: 3px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
    .bztfb-status-completed { background: #d4edda; color: #155724; }
    .bztfb-status-pending, .bztfb-status-generating { background: #fff3cd; color: #856404; }
    .bztfb-status-posting { background: #cce5ff; color: #004085; }
    .bztfb-status-failed { background: #f8d7da; color: #721c24; }
    </style>
    <?php
}
