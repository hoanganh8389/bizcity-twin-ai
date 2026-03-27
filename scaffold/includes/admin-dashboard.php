<?php
/**
 * Admin dashboard & data management pages.
 *
 * @package BizCity_{Name}
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ═══════════════════════════════════════════════
   Dashboard page
   ═══════════════════════════════════════════════ */
function bz{prefix}_page_dashboard() {
    global $wpdb;
    $t = bz{prefix}_tables();

    $total_items   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['items']}" );
    $total_history = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t['history']}" );
    $today_count   = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$t['history']} WHERE DATE(created_at) = %s",
        current_time( 'Y-m-d' )
    ) );
    ?>
    <div class="wrap">
        <h1>📊 BizCity {Name} — Dashboard</h1>
        <div style="display:flex;gap:20px;margin-top:20px;">
            <div class="bz{prefix}-stat-card">
                <h3><?php echo $total_items; ?></h3>
                <p>Dữ liệu</p>
            </div>
            <div class="bz{prefix}-stat-card">
                <h3><?php echo $total_history; ?></h3>
                <p>Lượt sử dụng</p>
            </div>
            <div class="bz{prefix}-stat-card">
                <h3><?php echo $today_count; ?></h3>
                <p>Hôm nay</p>
            </div>
        </div>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════
   Data management page
   ═══════════════════════════════════════════════ */
function bz{prefix}_page_data() {
    global $wpdb;
    $t = bz{prefix}_tables();
    $items = $wpdb->get_results( "SELECT * FROM {$t['items']} ORDER BY sort_order, id", ARRAY_A );
    ?>
    <div class="wrap">
        <h1>🗂️ Quản lý dữ liệu</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Slug</th>
                    <th>Tên</th>
                    <th>Category</th>
                    <th>Thứ tự</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $item ) : ?>
                <tr>
                    <td><?php echo esc_html( $item['id'] ); ?></td>
                    <td><?php echo esc_html( $item['slug'] ); ?></td>
                    <td><?php echo esc_html( $item['name_vi'] ); ?></td>
                    <td><?php echo esc_html( $item['category'] ); ?></td>
                    <td><?php echo esc_html( $item['sort_order'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════
   History page
   ═══════════════════════════════════════════════ */
function bz{prefix}_page_history() {
    global $wpdb;
    $t = bz{prefix}_tables();
    $rows = $wpdb->get_results(
        "SELECT * FROM {$t['history']} ORDER BY created_at DESC LIMIT 50",
        ARRAY_A
    );
    ?>
    <div class="wrap">
        <h1>📜 Lịch sử sử dụng</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>Platform</th>
                    <th>Chủ đề</th>
                    <th>Thời gian</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row['id'] ); ?></td>
                    <td><?php echo esc_html( $row['user_id'] ?: $row['client_id'] ); ?></td>
                    <td><?php echo esc_html( $row['platform'] ); ?></td>
                    <td><?php echo esc_html( $row['topic'] ); ?></td>
                    <td><?php echo esc_html( $row['created_at'] ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* ═══════════════════════════════════════════════
   Settings page
   ═══════════════════════════════════════════════ */
function bz{prefix}_page_settings() {
    if ( isset( $_POST['bz{prefix}_save_settings'] ) && check_admin_referer( 'bz{prefix}_settings' ) ) {
        update_option( 'bz{prefix}_items_count', (int) ( $_POST['items_count'] ?? 3 ) );
        echo '<div class="updated"><p>✅ Đã lưu cài đặt.</p></div>';
    }
    $items_count = get_option( 'bz{prefix}_items_count', 3 );
    ?>
    <div class="wrap">
        <h1>⚙️ Cài đặt</h1>
        <form method="post">
            <?php wp_nonce_field( 'bz{prefix}_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th>Số lượng items mặc định</th>
                    <td>
                        <input type="number" name="items_count" value="<?php echo esc_attr( $items_count ); ?>" min="1" max="20" />
                    </td>
                </tr>
            </table>
            <button type="submit" name="bz{prefix}_save_settings" class="button button-primary">💾 Lưu cài đặt</button>
        </form>
    </div>
    <?php
}
