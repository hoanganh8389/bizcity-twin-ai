<?php
/**
 * Admin settings page template.
 *
 * Variables available: $is_hub, $user_id, $blog_id, $accounts, $connect_url,
 *                      $connected, $disconnected, $email,
 *                      $hub_url, $hub_domain
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap bzgoogle-wrap">
    <h1>🔗 Google Tools</h1>

    <?php if ( $connected ) : ?>
        <div class="notice notice-success is-dismissible">
            <p>✅ Đã kết nối Google thành công<?php echo $email ? " ({$email})" : ''; ?>!</p>
        </div>
    <?php endif; ?>

    <?php if ( $disconnected ) : ?>
        <div class="notice notice-info is-dismissible">
            <p>Đã ngắt kết nối tài khoản Google.</p>
        </div>
    <?php endif; ?>

    <!-- User's Connected Accounts -->
    <div class="bzgoogle-card">
        <h2>📧 Tài khoản Google đã kết nối</h2>

        <?php if ( ! empty( $accounts ) ) : ?>
            <table class="widefat striped bzgoogle-table">
                <thead>
                    <tr>
                        <th>Email Google</th>
                        <th>Trạng thái</th>
                        <th>Kết nối lúc</th>
                        <th>Cập nhật lúc</th>
                        <th>Scopes</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $accounts as $acc ) :
                        $disconnect_url = wp_nonce_url(
                            add_query_arg( [
                                'account_id' => $acc->id,
                                'return_url' => admin_url( 'admin.php?page=' . BZGoogle_Admin::MENU_SLUG ),
                            ], home_url( '/google-auth/disconnect' ) ),
                            'bzgoogle_disconnect_' . $acc->id
                        );
                        $status_class = $acc->status === 'active' ? 'bzgoogle-active' : 'bzgoogle-inactive';
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $acc->google_email ); ?></strong></td>
                        <td><span class="bzgoogle-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $acc->status ); ?></span></td>
                        <td><?php echo esc_html( $acc->created_at ); ?></td>
                        <td><?php echo esc_html( $acc->updated_at ); ?></td>
                        <td class="bzgoogle-scopes"><?php echo esc_html( $acc->scope ); ?></td>
                        <td>
                            <?php if ( $acc->status === 'active' ) : ?>
                                <a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-secondary"
                                   onclick="return confirm('Bạn có chắc muốn ngắt kết nối?');">
                                    Ngắt kết nối
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="bzgoogle-empty">Bạn chưa kết nối tài khoản Google nào.</p>
        <?php endif; ?>

        <p style="margin-top: 16px;">
            <a href="<?php echo esc_url( $connect_url ); ?>" target="_blank" rel="noopener"
               class="button button-primary button-hero bzgoogle-connect-btn">
                <span class="dashicons dashicons-google" style="margin-top: 4px;"></span>
                Kết nối Google
            </a>
        </p>
        <?php if ( $is_hub ) : ?>
            <p class="description">
                Kết nối tài khoản Google để sử dụng Gmail, Calendar, Drive, Contacts qua chat AI.
                Bạn không cần tạo Google API Console — hệ thống BizCity đã tích hợp sẵn.
            </p>
        <?php else : ?>
            <p class="description">
                Kết nối Google qua Hub trung tâm <strong><?php echo esc_html( $hub_domain ); ?></strong>.
                Bạn sẽ được chuyển sang <code><?php echo esc_html( $hub_domain ); ?></code> để xác thực với Google, sau đó quay về site này.
            </p>
        <?php endif; ?>
    </div>

    <?php
    /* ── Hub Configuration (admin only) ─────────────── */
    if ( $is_hub && current_user_can( 'manage_options' ) ) :
        $client_id     = get_site_option( 'bzgoogle_client_id', '' );
        $has_secret    = ! empty( get_site_option( 'bzgoogle_client_secret', '' ) );
        $callback_url  = BZGoogle_Google_OAuth::get_callback_url();
    ?>
    <div class="bzgoogle-card bzgoogle-hub-config">
        <h2>⚙️ Cấu hình OAuth Hub (Admin)</h2>
        <p class="description">
            Đây là site Hub trung tâm. Cấu hình Google OAuth App để cho phép tất cả site con kết nối Google.
        </p>

        <form method="post" action="options.php">
            <?php settings_fields( 'bzgoogle_hub' ); ?>
            <table class="form-table">
                <tr>
                    <th>Google Client ID</th>
                    <td>
                        <input type="text" name="bzgoogle_client_id_raw" class="regular-text"
                               value="<?php echo esc_attr( $client_id ); ?>"
                               placeholder="xxxxxxxxxxxx.apps.googleusercontent.com" />
                    </td>
                </tr>
                <tr>
                    <th>Google Client Secret</th>
                    <td>
                        <input type="password" name="bzgoogle_client_secret_raw" class="regular-text"
                               placeholder="<?php echo $has_secret ? '••••••••••• (đã lưu)' : 'GOCSPX-xxxxxxxxxxxx'; ?>" />
                        <?php if ( $has_secret ) : ?>
                            <p class="description">Để trống nếu không muốn thay đổi.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Redirect URI</th>
                    <td>
                        <code><?php echo esc_html( $callback_url ); ?></code>
                        <p class="description">Dán URL này vào "Authorized redirect URIs" trong Google Cloud Console.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Lưu cấu hình' ); ?>
        </form>

        <hr />

        <h3>📊 Thống kê Hub</h3>
        <div id="bzgoogle-hub-stats">
            <p>Đang tải...</p>
        </div>

        <h3>📝 Hướng dẫn thiết lập</h3>
        <ol>
            <li>Truy cập <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a></li>
            <li>Tạo project mới hoặc chọn project hiện tại</li>
            <li>Bật các API: Gmail API, Google Calendar API, Google Drive API, People API</li>
            <li>Tạo OAuth 2.0 Client ID (Web application)</li>
            <li>Thêm Redirect URI: <code><?php echo esc_html( $callback_url ); ?></code></li>
            <li>Dán Client ID và Client Secret vào form trên</li>
        </ol>
    </div>
    <?php endif; ?>
</div>
