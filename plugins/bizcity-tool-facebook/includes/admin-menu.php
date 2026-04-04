<?php
/**
 * BizCity Tool Facebook — Admin Menu, Dashboard & Settings
 *
 * Standalone model: credentials stored in WP options (bztfb_*).
 * OAuth via own Facebook Developer App (not bizcity-facebook-bot).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function() {

    // Try to attach under existing Facebook menu; fall back to Tools
    $parent = 'bizcity-facebook-bots';

    add_submenu_page(
        $parent,
        'Tool Facebook — Đăng bài AI',
        'AI Đăng bài',
        'manage_options',
        'bizcity-tool-facebook',
        'bztfb_render_admin_page'
    );

    add_submenu_page(
        $parent,
        'Tool Facebook — Cài đặt',
        'Facebook Settings',
        'manage_options',
        'bizcity-facebook-settings',
        'bztfb_render_settings_page'
    );

    add_submenu_page(
        $parent,
        'Tool Facebook — Hướng dẫn sử dụng',
        '📖 Hướng dẫn',
        'manage_options',
        'bizcity-facebook-guide',
        'bztfb_render_guide_page'
    );
} );

/* ════════════════════════════════════════════════════
 * Save settings handler
 * ════════════════════════════════════════════════════ */
add_action( 'admin_post_bztfb_save_settings', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Không có quyền.' );
    check_admin_referer( 'bztfb_settings_nonce' );

    update_option( 'bztfb_app_id',       sanitize_text_field( $_POST['bztfb_app_id']       ?? '' ) );
    update_option( 'bztfb_verify_token', sanitize_text_field( $_POST['bztfb_verify_token'] ?? 'bizfbhook' ) );

    $secret = sanitize_text_field( $_POST['bztfb_app_secret'] ?? '' );
    if ( ! empty( $secret ) && $secret !== '••••••••' ) {
        update_option( 'bztfb_app_secret', $secret );
    }

    wp_redirect( add_query_arg( [ 'page' => 'bizcity-facebook-settings', 'saved' => '1' ], admin_url( 'admin.php' ) ) );
    exit;
} );

/* ════════════════════════════════════════════════════
 * SETTINGS PAGE
 * ════════════════════════════════════════════════════ */
function bztfb_render_settings_page(): void {
    $app_id       = get_option( 'bztfb_app_id', '' );
    $app_secret   = get_option( 'bztfb_app_secret', '' );
    $verify_token = get_option( 'bztfb_verify_token', 'bizfbhook' );
    $webhook_url  = home_url( '/bizfbhook/' );

    $saved   = ! empty( $_GET['saved'] );
    $status  = sanitize_text_field( $_GET['bztfb_status'] ?? '' );
    $pages_n = (int) ( $_GET['bztfb_pages'] ?? 0 );
    $err_msg = sanitize_text_field( urldecode( $_GET['bztfb_msg'] ?? '' ) );

    $connected_pages = class_exists( 'BizCity_FB_Database' ) ? BizCity_FB_Database::get_active_pages() : [];
    ?>
    <div class="wrap">
        <h1>⚙️ BizCity Tool Facebook — Cài đặt</h1>

        <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p>✅ Đã lưu cài đặt.</p></div>
        <?php endif; ?>
        <?php if ( $status === 'connected' ) : ?>
            <div class="notice notice-success is-dismissible">
                <p>🎉 Kết nối thành công! Đã lưu <strong><?php echo esc_html( $pages_n ); ?></strong> Facebook Page.</p>
            </div>
        <?php elseif ( $status === 'disconnected' ) : ?>
            <div class="notice notice-info is-dismissible"><p>Page đã được ngắt kết nối.</p></div>
        <?php elseif ( $status === 'error' ) : ?>
            <div class="notice notice-error is-dismissible">
                <p>❌ Lỗi OAuth: <?php echo esc_html( $err_msg ); ?></p>
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;max-width:1100px;">

            <!-- App Credentials -->
            <div>
                <div class="postbox">
                    <div class="postbox-header"><h2 class="hndle">🔑 Facebook Developer App</h2></div>
                    <div class="inside">
                        <p>Tạo Facebook App tại <a href="https://developers.facebook.com/apps" target="_blank">developers.facebook.com/apps</a> → loại <strong>Business</strong>.</p>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="bztfb_save_settings">
                            <?php wp_nonce_field( 'bztfb_settings_nonce' ); ?>
                            <table class="form-table">
                                <tr>
                                    <th><label for="bztfb_app_id">App ID</label></th>
                                    <td>
                                        <input type="text" id="bztfb_app_id" name="bztfb_app_id"
                                               value="<?php echo esc_attr( $app_id ); ?>" class="regular-text" placeholder="123456789012345">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="bztfb_app_secret">App Secret</label></th>
                                    <td>
                                        <input type="password" id="bztfb_app_secret" name="bztfb_app_secret"
                                               value="<?php echo empty( $app_secret ) ? '' : '••••••••'; ?>" class="regular-text" placeholder="Để trống = giữ nguyên">
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="bztfb_verify_token">Verify Token</label></th>
                                    <td>
                                        <input type="text" id="bztfb_verify_token" name="bztfb_verify_token"
                                               value="<?php echo esc_attr( $verify_token ); ?>" class="regular-text">
                                        <p class="description">Nhập đúng vào Facebook App → Webhooks → Verify Token.</p>
                                    </td>
                                </tr>
                            </table>
                            <?php submit_button( 'Lưu cài đặt', 'primary', 'submit', false ); ?>
                            &nbsp;
                            <?php if ( $app_id ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( 'bztfb_oauth', 'start', home_url( '/' ) ) ); ?>" class="button button-secondary">📲 Kết nối (OAuth)</a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>

                <!-- Webhook Info -->
                <div class="postbox" style="margin-top:16px;">
                    <div class="postbox-header"><h2 class="hndle">🔗 Webhook URL</h2></div>
                    <div class="inside">
                        <code style="display:block;padding:10px;background:#f5f5f5;border-radius:4px;word-break:break-all;"><?php echo esc_html( $webhook_url ); ?></code>
                        <p style="margin-top:8px;">Verify Token: <code><?php echo esc_html( $verify_token ); ?></code></p>
                        <p class="description">Fields: <em>messages, messaging_postbacks, feed</em></p>
                    </div>
                </div>
            </div>

            <!-- Connected Pages -->
            <div>
                <div class="postbox">
                    <div class="postbox-header"><h2 class="hndle">📄 Pages đã kết nối (<?php echo count( $connected_pages ); ?>)</h2></div>
                    <div class="inside">
                        <?php if ( empty( $connected_pages ) ) : ?>
                            <p>Chưa có Page nào. Nhập App Credentials rồi nhấn <strong>Kết nối (OAuth)</strong>.</p>
                        <?php else : ?>
                            <table class="widefat striped">
                                <thead><tr><th>Page</th><th>ID</th><th>Instagram</th><th></th></tr></thead>
                                <tbody>
                                <?php foreach ( $connected_pages as $page ) :
                                    $pid   = $page['page_id']       ?? '';
                                    $pname = $page['page_name']     ?? $pid;
                                    $ig_id = $page['ig_account_id'] ?? '';
                                    $disc_url = wp_nonce_url(
                                        add_query_arg( [ 'bztfb_oauth' => 'disconnect', 'page_id' => $pid ], home_url( '/' ) ),
                                        'bztfb_disconnect_' . $pid
                                    );
                                ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $pname ); ?></strong></td>
                                        <td><code style="font-size:11px;"><?php echo esc_html( $pid ); ?></code></td>
                                        <td><?php echo $ig_id ? '<span style="color:green;">✓ ' . esc_html( $ig_id ) . '</span>' : '—'; ?></td>
                                        <td>
                                            <a href="<?php echo esc_url( $disc_url ); ?>"
                                               onclick="return confirm('Ngắt kết nối Page này?')" class="button button-small">Ngắt</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                        <?php if ( get_option( 'bztfb_app_id' ) ) : ?>
                            <p style="margin-top:12px;">
                                <a href="<?php echo esc_url( add_query_arg( 'bztfb_oauth', 'start', home_url( '/' ) ) ); ?>" class="button button-primary">➕ Kết nối thêm Page</a>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}

/* ════════════════════════════════════════════════════
 * DASHBOARD PAGE
 * ════════════════════════════════════════════════════ */
function bztfb_render_admin_page(): void {
    global $wpdb;

    $log_table      = $wpdb->prefix . 'bztfb_posts_log';
    $comments_table = $wpdb->prefix . 'bztfb_comments';
    $has_log        = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" );

    $total_posted     = $has_log ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$log_table} WHERE status = 'published'" ) : 0;
    $total_pages      = class_exists( 'BizCity_FB_Database' ) ? count( BizCity_FB_Database::get_active_pages() ) : 0;
    $pending_comments = (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$comments_table}'" )
        ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$comments_table} WHERE replied = 0" ) : 0;

    $webhook_url = home_url( '/bizfbhook/' );
    ?>
    <div class="wrap">
        <h1>📣 BizCity Tool Facebook — Dashboard</h1>

        <div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
            <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #1877f2;flex:1;min-width:150px;">
                <h3 style="margin:0;color:#666;font-size:13px;">Bài đã đăng</h3>
                <p style="font-size:2em;margin:8px 0;font-weight:bold;"><?php echo esc_html( $total_posted ); ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #5cb85c;flex:1;min-width:150px;">
                <h3 style="margin:0;color:#666;font-size:13px;">Pages kết nối</h3>
                <p style="font-size:2em;margin:8px 0;font-weight:bold;"><?php echo esc_html( $total_pages ); ?></p>
            </div>
            <div style="background:#fff;padding:20px;border-radius:8px;border-left:4px solid #f0ad4e;flex:1;min-width:150px;">
                <h3 style="margin:0;color:#666;font-size:13px;">Comments chờ reply</h3>
                <p style="font-size:2em;margin:8px 0;font-weight:bold;"><?php echo esc_html( $pending_comments ); ?></p>
            </div>
        </div>

        <div style="background:#fff;padding:20px;border-radius:8px;margin-bottom:20px;">
            <h2 style="margin-top:0;">🔗 Webhook Riêng (Standalone)</h2>
            <code style="display:block;padding:10px;background:#f5f5f5;border-radius:4px;"><?php echo esc_html( $webhook_url ); ?></code>
            <p class="description">
                Facebook App của bạn → Webhooks → cấu hình URL này.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-facebook-settings' ) ); ?>">Cài đặt →</a>
            </p>
        </div>

        <?php if ( $has_log ) :
            $recent = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$log_table} ORDER BY created_at DESC LIMIT %d", 10 ) );
            if ( $recent ) : ?>
            <div style="background:#fff;padding:20px;border-radius:8px;">
                <h2 style="margin-top:0;">📝 Bài đăng gần đây</h2>
                <table class="widefat striped">
                    <thead><tr><th>Page</th><th>Nội dung</th><th>Link</th><th>Ngày</th></tr></thead>
                    <tbody>
                    <?php foreach ( $recent as $row ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $row->page_id ); ?></code></td>
                            <td><?php echo esc_html( mb_substr( $row->content ?? '', 0, 80 ) . '…' ); ?></td>
                            <td><?php echo $row->post_url ? '<a href="' . esc_url( $row->post_url ) . '" target="_blank">Xem ↗</a>' : '—'; ?></td>
                            <td><?php echo esc_html( $row->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif;
        endif; ?>
    </div>
    <?php
}

/* ════════════════════════════════════════════════════
 * AJAX: Flush rewrite rules (kích hoạt /bizfbhook/)
 * ════════════════════════════════════════════════════ */
add_action( 'wp_ajax_bztfb_flush_rewrites', function() {
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    check_ajax_referer( 'bztfb_flush_rewrites' );
    flush_rewrite_rules( true );
    // Verify the rule was registered
    global $wp_rewrite;
    $rules = $wp_rewrite->wp_rewrite_rules();
    $active = isset( $rules['bizfbhook/?$'] ) || isset( $rules['^bizfbhook/?$'] );
    wp_send_json_success( [
        'message' => $active ? '✅ Rewrite rules đã flush thành công. /bizfbhook/ đang hoạt động!' : '⚠️ Flush xong nhưng chưa tìm thấy rule bizfbhook — kiểm tra lại .htaccess.',
        'active'  => $active,
    ] );
} );

/* ════════════════════════════════════════════════════
 * GUIDE PAGE
 * ════════════════════════════════════════════════════ */
function bztfb_render_guide_page(): void {
    $webhook_url  = home_url( '/bizfbhook/' );
    $verify_token = get_option( 'bztfb_verify_token', 'bizfbhook' );
    $app_id       = get_option( 'bztfb_app_id', '' );
    $settings_url = admin_url( 'admin.php?page=bizcity-facebook-settings' );

    // Test webhook live
    $webhook_live    = false;
    $webhook_test_url = add_query_arg( [
        'hub.mode'         => 'subscribe',
        'hub.verify_token' => $verify_token,
        'hub.challenge'    => 'test_ok',
    ], $webhook_url );
    $test_resp = wp_remote_get( $webhook_test_url, [ 'timeout' => 5, 'sslverify' => false ] );
    if ( ! is_wp_error( $test_resp ) && wp_remote_retrieve_body( $test_resp ) === 'test_ok' ) {
        $webhook_live = true;
    }

    $nonce_flush = wp_create_nonce( 'bztfb_flush_rewrites' );
    ?>
    <div class="wrap" style="max-width:960px;">
        <h1>📖 Hướng dẫn sử dụng — BizCity Tool Facebook</h1>
        <p style="color:#666;font-size:15px;">Plugin hoạt động hoàn toàn độc lập — bạn dùng Facebook App riêng, không phụ thuộc bizcity.vn.</p>

        <style>
            .bztfb-guide-step{background:#fff;border-radius:10px;padding:24px 28px;margin-bottom:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);border-left:5px solid #1877f2;}
            .bztfb-guide-step.success{border-left-color:#42b72a;}
            .bztfb-guide-step.warning{border-left-color:#ffc107;}
            .bztfb-guide-step h2{margin:0 0 12px;font-size:18px;display:flex;align-items:center;gap:8px;}
            .bztfb-guide-step ol,.bztfb-guide-step ul{margin:8px 0 0 20px;line-height:1.9;}
            .bztfb-guide-step code{background:#f0f2f5;padding:2px 7px;border-radius:4px;font-size:13px;}
            .bztfb-guide-code-block{background:#1e1e1e;color:#d4d4d4;padding:14px 18px;border-radius:8px;font-size:13px;margin:10px 0;overflow-x:auto;white-space:pre;}
            .bztfb-badge{display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600;}
            .bztfb-badge-ok{background:#d4edda;color:#155724;}
            .bztfb-badge-warn{background:#fff3cd;color:#856404;}
            .bztfb-badge-err{background:#f8d7da;color:#721c24;}
            .bztfb-guide-toc{background:#f8f9fa;border-radius:8px;padding:16px 20px;margin-bottom:24px;display:inline-block;}
            .bztfb-guide-toc a{display:block;padding:3px 0;color:#1877f2;text-decoration:none;font-size:14px;}
            .bztfb-guide-toc a:hover{text-decoration:underline;}
            .bztfb-status-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid #eee;}
            .bztfb-status-row:last-child{border:none;}
        </style>

        <!-- MỤC LỤC -->
        <div class="bztfb-guide-toc">
            <strong style="display:block;margin-bottom:8px;font-size:15px;">📋 Mục lục</strong>
            <a href="#step-1">① Tạo Facebook Developer App</a>
            <a href="#step-2">② Cài đặt App ID / Secret trong WP</a>
            <a href="#step-3">③ Kích hoạt Webhook /bizfbhook/</a>
            <a href="#step-4">④ Cấu hình Webhook trong Facebook App</a>
            <a href="#step-5">⑤ Kết nối Facebook Page (OAuth)</a>
            <a href="#step-6">⑥ Đăng bài qua Chat AI</a>
            <a href="#step-7">⑦ Messenger Chatbot & Comment tự động</a>
            <a href="#step-8">⑧ Kiểm tra & xử lý lỗi</a>
        </div>

        <!-- TRẠNG THÁI HỆ THỐNG -->
        <div class="bztfb-guide-step <?php echo ( $app_id && $webhook_live ) ? 'success' : 'warning'; ?>" style="margin-bottom:28px;">
            <h2>🔍 Trạng thái hiện tại</h2>
            <div>
                <div class="bztfb-status-row">
                    <span style="width:200px;font-weight:600;">App ID</span>
                    <?php if ( $app_id ) : ?>
                        <span class="bztfb-badge bztfb-badge-ok">✅ Đã cấu hình</span>
                        <code><?php echo esc_html( $app_id ); ?></code>
                    <?php else : ?>
                        <span class="bztfb-badge bztfb-badge-err">❌ Chưa nhập</span>
                        <a href="<?php echo esc_url( $settings_url ); ?>">→ Vào Settings để nhập</a>
                    <?php endif; ?>
                </div>
                <div class="bztfb-status-row">
                    <span style="width:200px;font-weight:600;">Webhook URL</span>
                    <code><?php echo esc_html( $webhook_url ); ?></code>
                </div>
                <div class="bztfb-status-row">
                    <span style="width:200px;font-weight:600;">Verify Token</span>
                    <code><?php echo esc_html( $verify_token ); ?></code>
                </div>
                <div class="bztfb-status-row">
                    <span style="width:200px;font-weight:600;">Webhook live?</span>
                    <?php if ( $webhook_live ) : ?>
                        <span class="bztfb-badge bztfb-badge-ok">✅ Đang hoạt động</span>
                        <span style="color:#666;font-size:13px;">Facebook có thể verify được endpoint này.</span>
                    <?php else : ?>
                        <span class="bztfb-badge bztfb-badge-warn">⚠️ Chưa kích hoạt</span>
                        <button id="bztfb-flush-btn" class="button button-primary" style="margin-left:8px;"
                                data-nonce="<?php echo esc_attr( $nonce_flush ); ?>">
                            🔄 Kích hoạt /bizfbhook/ ngay
                        </button>
                        <span id="bztfb-flush-result" style="margin-left:8px;font-size:13px;"></span>
                    <?php endif; ?>
                </div>
                <?php
                $pages_count = class_exists( 'BizCity_FB_Database' ) ? count( BizCity_FB_Database::get_active_pages() ) : 0;
                ?>
                <div class="bztfb-status-row">
                    <span style="width:200px;font-weight:600;">Pages kết nối</span>
                    <?php if ( $pages_count ) : ?>
                        <span class="bztfb-badge bztfb-badge-ok">✅ <?php echo esc_html( $pages_count ); ?> Page</span>
                    <?php else : ?>
                        <span class="bztfb-badge bztfb-badge-warn">⚠️ Chưa có Page nào</span>
                        <span style="color:#666;font-size:13px;margin-left:8px;">— xem Bước 5</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- BƯỚC 1 -->
        <div class="bztfb-guide-step" id="step-1">
            <h2>① Tạo Facebook Developer App</h2>
            <p>Plugin dùng App <strong>của bạn</strong> — không dùng chung app. Điều này giúp bạn toàn quyền kiểm soát.</p>
            <ol>
                <li>Truy cập <a href="https://developers.facebook.com/apps" target="_blank"><strong>developers.facebook.com/apps</strong></a></li>
                <li>Nhấn <strong>Create App</strong> → chọn loại <strong>Business</strong></li>
                <li>Đặt tên App (ví dụ: <em>MyBrand AI Bot</em>) → Create</li>
                <li>Vào <strong>Settings → Basic</strong> → copy <strong>App ID</strong> và <strong>App Secret</strong></li>
                <li>Thêm products: <strong>Messenger</strong> + <strong>Webhooks</strong> (click "Add Product")</li>
                <li>Thêm <code><?php echo esc_html( home_url( '/' ) ); ?></code> vào danh sách <strong>App Domains</strong></li>
            </ol>
            <p>💡 <em>Mẹo:</em> lúc đầu App ở chế độ Development — bạn là Admin nên dùng được đầy đủ. Khi muốn người khác dùng cần submit App Review.</p>
        </div>

        <!-- BƯỚC 2 -->
        <div class="bztfb-guide-step" id="step-2">
            <h2>② Cài đặt App ID / Secret trong WordPress</h2>
            <ol>
                <li>Vào <a href="<?php echo esc_url( $settings_url ); ?>"><strong>Admin → Facebook Settings</strong></a></li>
                <li>Nhập <strong>App ID</strong> và <strong>App Secret</strong> từ Bước 1</li>
                <li>Để nguyên <strong>Verify Token</strong> = <code><?php echo esc_html( $verify_token ); ?></code> (hoặc đổi chuỗi bất kỳ)</li>
                <li>Nhấn <strong>Lưu cài đặt</strong></li>
            </ol>
            <p style="background:#fff3cd;padding:10px 14px;border-radius:6px;margin-top:12px;">
                ⚠️ <strong>Bảo mật:</strong> App Secret phải giữ bí mật tuyệt đối. Không chia sẻ, không commit lên git.
            </p>
        </div>

        <!-- BƯỚC 3 -->
        <div class="bztfb-guide-step" id="step-3">
            <h2>③ Kích hoạt Webhook <code>/bizfbhook/</code></h2>
            <p>Plugin đăng ký URL <code>/bizfbhook/</code> qua WordPress Rewrite API. Cần flush để WordPress "nhớ" URL này.</p>

            <?php if ( $webhook_live ) : ?>
                <p style="background:#d4edda;padding:10px 14px;border-radius:6px;">
                    ✅ <strong>Webhook đang hoạt động tốt!</strong> Facebook có thể kết nối vào
                    <code><?php echo esc_html( $webhook_url ); ?></code>
                </p>
            <?php else : ?>
                <p><strong>Cách 1 (tự động):</strong> Nhấn nút bên dưới:</p>
                <button id="bztfb-flush-btn2" class="button button-primary button-large"
                        data-nonce="<?php echo esc_attr( $nonce_flush ); ?>">
                    🔄 Kích hoạt /bizfbhook/ ngay
                </button>
                <span id="bztfb-flush-result2" style="display:block;margin-top:8px;font-size:14px;"></span>

                <p style="margin-top:16px;"><strong>Cách 2 (thủ công):</strong> Vào <em>Settings → Permalinks</em> → nhấn <strong>Save Changes</strong> (không cần đổi gì).</p>

                <p style="margin-top:16px;"><strong>Cách 3 (nếu dùng Nginx/LiteSpeed):</strong> Thêm rule sau vào server config:</p>
                <div class="bztfb-guide-code-block">location ~ ^/bizfbhook/?$ {
    rewrite ^/bizfbhook/?$ /index.php?bztfb_webhook_route=1 last;
}</div>
            <?php endif; ?>

            <p style="margin-top:12px;">URL webhook của bạn:</p>
            <div style="display:flex;align-items:center;gap:8px;">
                <code style="background:#1877f2;color:#fff;padding:8px 16px;border-radius:6px;font-size:15px;flex:1;">
                    <?php echo esc_html( $webhook_url ); ?>
                </code>
                <button class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>');this.textContent='✅ Đã copy'">
                    📋 Copy
                </button>
            </div>
        </div>

        <!-- BƯỚC 4 -->
        <div class="bztfb-guide-step" id="step-4">
            <h2>④ Cấu hình Webhook trong Facebook App</h2>
            <ol>
                <li>Vào <a href="https://developers.facebook.com/apps/<?php echo esc_attr( $app_id ?: 'YOUR_APP_ID' ); ?>/webhooks" target="_blank">
                    <strong>Facebook App → Webhooks</strong>
                </a></li>
                <li>Nhấn <strong>Add Callback URL</strong></li>
                <li>Điền vào:
                    <ul>
                        <li><strong>Callback URL:</strong> <code><?php echo esc_html( $webhook_url ); ?></code></li>
                        <li><strong>Verify Token:</strong> <code><?php echo esc_html( $verify_token ); ?></code></li>
                    </ul>
                </li>
                <li>Nhấn <strong>Verify and Save</strong> → Facebook sẽ gọi vào URL trên, plugin tự động xác nhận ✅</li>
                <li>Sau khi verify, chọn subscriptions cần thiết:
                    <ul>
                        <li><code>messages</code> — nhận tin nhắn Messenger</li>
                        <li><code>messaging_postbacks</code> — nhận postback (nút quick-reply)</li>
                        <li><code>feed</code> — nhận khi có comment mới trên bài đăng</li>
                    </ul>
                </li>
            </ol>
            <p>Lưu ý: phần cấu hình Webhook nằm ở <strong>Messenger → Settings → Webhooks</strong> (không phải tab Webhooks chung).</p>
        </div>

        <!-- BƯỚC 5 -->
        <div class="bztfb-guide-step" id="step-5">
            <h2>⑤ Kết nối Facebook Page (OAuth)</h2>
            <p>Sau khi lưu App ID/Secret, nhấn nút <strong>Kết nối (OAuth)</strong> để lấy Page Access Token.</p>
            <ol>
                <li>Vào <a href="<?php echo esc_url( $settings_url ); ?>">Admin → Facebook Settings</a></li>
                <li>Nhấn <strong>📲 Kết nối (OAuth)</strong></li>
                <li>Facebook hiện hộp thoại — chọn <strong>tất cả Pages</strong> bạn muốn quản lý → Tiếp tục</li>
                <li>Sau khi đồng ý, plugin tự lưu token vào database</li>
                <li>Trang Settings hiện danh sách Pages đã kết nối</li>
            </ol>
            <p>Mỗi Page được lưu: tên, Page ID, Page Access Token, Instagram Business Account ID (nếu có).</p>
            <p style="background:#e8f4fd;padding:10px 14px;border-radius:6px;">
                💡 Plugin dùng <strong>Long-lived Page Token</strong> (không hết hạn) — bạn chỉ cần kết nối một lần.
            </p>
        </div>

        <!-- BƯỚC 6 -->
        <div class="bztfb-guide-step success" id="step-6">
            <h2>⑥ Đăng bài qua Chat AI</h2>
            <p>Sau khi hoàn thành các bước trên, bạn có thể chat với AI để đăng bài:</p>
            <div style="background:#f0f2f5;border-radius:8px;padding:16px;margin:10px 0;">
                <strong>Ví dụ câu lệnh chat:</strong>
                <ul style="margin:8px 0 0 16px;line-height:1.9;">
                    <li>📝 <em>"Đăng bài Facebook về sản phẩm kem dưỡng da mới, tone chuyên nghiệp"</em></li>
                    <li>📸 <em>"Viết bài Facebook kèm ảnh về khuyến mãi mùa hè, kêu gọi mua hàng"</em></li>
                    <li>🔄 <em>"Tạo 3 bài Facebook về chủ đề sức khỏe, đăng lên Page chính"</em></li>
                    <li>📰 <em>"Viết bài blog rồi đăng link lên Facebook Page"</em> (pipeline đầy đủ)</li>
                </ul>
            </div>
            <p>AI sẽ tự:</p>
            <ul>
                <li>Sinh nội dung từ chủ đề bạn đưa ra</li>
                <li>Tối ưu tone giọng (engaging / professional / storytelling…)</li>
                <li>Gọi Graph API v21.0 đăng lên Page được chọn</li>
                <li>Trả về link bài đăng Facebook</li>
            </ul>
        </div>

        <!-- BƯỚC 7 -->
        <div class="bztfb-guide-step" id="step-7">
            <h2>⑦ Messenger Chatbot & Auto-reply Comment</h2>
            <p>Plugin hook vào sự kiện từ <code>/bizfbhook/</code> và fire WordPress actions để bạn xử lý:</p>

            <strong>Messenger (nhắn tin Fanpage):</strong>
            <div class="bztfb-guide-code-block">// Khi có tin nhắn Messenger mới
add_action( 'bztfb_messenger_message', function( $page_id, $psid, $text, $attachments, $raw ) {
    // Xử lý: gọi AI trả lời, lưu DB, gửi thông báo...
    $api = BizCity_FB_Database::api_for_page( $page_id );
    $api->send_message( $psid, 'Xin chào! Tôi sẽ trả lời bạn ngay.' );
}, 10, 5 );

// Filter để tự động reply (trả về text để auto-reply, false để bỏ qua)
add_filter( 'bztfb_ai_reply_message', function( $reply, $page_id, $psid, $text ) {
    return 'Cảm ơn bạn đã nhắn tin! Chúng tôi sẽ liên hệ lại sớm.';
}, 10, 4 );</div>

            <strong>Comment trên bài đăng:</strong>
            <div class="bztfb-guide-code-block">// Khi có comment mới
add_action( 'bztfb_comment_added', function( $page_id, $post_id, $comment_id, $sender_id, $message, $raw ) {
    // ghi log, phân tích nội dung, trigger AI reply...
}, 10, 6 );

// Auto-reply comment (trả về text để reply, false để bỏ qua)
add_filter( 'bztfb_ai_reply_comment', function( $reply, $page_id, $comment_id, $message ) {
    // Gọi AI để tạo câu trả lời phù hợp
    return false; // false = không auto-reply
}, 10, 4 );</div>
        </div>

        <!-- BƯỚC 8 -->
        <div class="bztfb-guide-step" id="step-8">
            <h2>⑧ Kiểm tra & xử lý lỗi thường gặp</h2>

            <table class="widefat striped" style="margin-top:8px;">
                <thead>
                    <tr><th style="width:35%;">Triệu chứng</th><th>Nguyên nhân</th><th>Cách xử lý</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Facebook báo "The URL couldn't be validated"</td>
                        <td>Webhook chưa hoạt động hoặc Verify Token sai</td>
                        <td>Nhấn <strong>Kích hoạt</strong> ở Bước 3, kiểm tra Verify Token khớp với Settings</td>
                    </tr>
                    <tr>
                        <td>Đăng bài xong không thấy trên Facebook</td>
                        <td>Page chưa kết nối hoặc token hết hạn</td>
                        <td>Vào Settings → ngắt kết nối Page cũ → OAuth lại</td>
                    </tr>
                    <tr>
                        <td>Lỗi "Invalid OAuth access token"</td>
                        <td>App Secret sai hoặc token hết hạn</td>
                        <td>Kiểm tra App Secret trong Settings, thực hiện OAuth lại</td>
                    </tr>
                    <tr>
                        <td>Không nhận được sự kiện Messenger/Comment</td>
                        <td>Webhook subscription chưa được bật</td>
                        <td>Vào Facebook App → Messenger → Settings → chọn Page → bật Webhooks</td>
                    </tr>
                    <tr>
                        <td>URL <code>/bizfbhook/</code> trả về 404</td>
                        <td>Rewrite rules chưa flush</td>
                        <td>Nhấn nút <strong>Kích hoạt</strong> ở trên hoặc vào Settings → Permalinks → Save</td>
                    </tr>
                    <tr>
                        <td>App ở chế độ Development, người khác không nhắn được</td>
                        <td>Chỉ Admin/Tester mới test được khi app chưa live</td>
                        <td>Submit App Review để Live Mode, hoặc thêm tài khoản test vào App Roles</td>
                    </tr>
                </tbody>
            </table>

            <div style="background:#e8f4fd;border-radius:8px;padding:16px;margin-top:16px;">
                <strong>📎 Link hữu ích:</strong>
                <ul style="margin:8px 0 0 16px;">
                    <li><a href="https://developers.facebook.com/docs/messenger-platform/webhooks" target="_blank">Facebook Webhooks Documentation</a></li>
                    <li><a href="https://developers.facebook.com/docs/graph-api/reference/page/feed/" target="_blank">Graph API — Page Feed</a></li>
                    <li><a href="https://developers.facebook.com/tools/explorer/" target="_blank">Graph API Explorer (test token)</a></li>
                    <li><a href="<?php echo esc_url( $settings_url ); ?>">⚙️ Đi đến Trang Settings</a></li>
                    <li><a href="<?php echo esc_url( admin_url( 'options-permalink.php' ) ); ?>">🔗 Flush Permalink (Settings → Permalinks)</a></li>
                </ul>
            </div>
        </div>
    </div>

    <script>
    (function($) {
        function doFlush(btn, resultEl) {
            btn.prop('disabled', true).text('⏳ Đang flush...');
            $.post(ajaxurl, {
                action: 'bztfb_flush_rewrites',
                _ajax_nonce: btn.data('nonce')
            }, function(res) {
                btn.prop('disabled', false).text('🔄 Kích hoạt /bizfbhook/ ngay');
                if (res.success) {
                    resultEl.html('<span style="color:' + (res.data.active ? '#155724' : '#856404') + ';">' + res.data.message + '</span>');
                    if (res.data.active) setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    resultEl.html('<span style="color:#721c24;">❌ ' + (res.data || 'Lỗi không xác định') + '</span>');
                }
            });
        }

        $('#bztfb-flush-btn').on('click', function() {
            doFlush($(this), $('#bztfb-flush-result'));
        });
        $('#bztfb-flush-btn2').on('click', function() {
            doFlush($(this), $('#bztfb-flush-result2'));
        });
    })(jQuery);
    </script>
    <?php
}
