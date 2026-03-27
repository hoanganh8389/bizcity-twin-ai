<?php
/**
 * BizCity Tool Facebook — Profile View
 * Route: /tool-facebook/
 *
 * 4-tab layout: Create | Monitor | Settings | Pages
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();
if ( ! $user_id ) {
    wp_redirect( wp_login_url( home_url( '/tool-facebook/' ) ) );
    exit;
}

$pages = get_option( 'fb_pages_connected', array() );
if ( ! is_array( $pages ) ) $pages = array();

$user_page_id = get_user_meta( $user_id, 'bztfb_user_page', true );
$user_page    = null;
if ( $user_page_id && is_array( $pages ) ) {
    foreach ( $pages as $p ) {
        if ( ( $p['id'] ?? '' ) === $user_page_id ) {
            $user_page = $p;
            break;
        }
    }
}

// Plan A: Facebook profile for tester request
$fb_profile   = get_user_meta( $user_id, 'bztfb_facebook_profile', true );
$tester_requested = get_user_meta( $user_id, 'bztfb_tester_requested_at', true );

// Plan B: User's own Developer App config
$user_app_id     = get_user_meta( $user_id, 'bztfb_user_app_id', true );
$user_app_secret = get_user_meta( $user_id, 'bztfb_user_app_secret', true );
$user_oauth_url  = class_exists( 'BizCity_Facebook_OAuth' ) ? BizCity_Facebook_OAuth::get_user_oauth_url() : null;

// User's own bots from bizcity_facebook_bots table (Plan B)
$user_bots = array();
if ( class_exists( 'BizCity_Facebook_Bot_Database' ) ) {
    $user_bots = BizCity_Facebook_Bot_Database::instance()->get_bots_by_user( $user_id );
}
// If user has own bot but no page from site options, resolve from bot
if ( ! $user_page && $user_page_id && ! empty( $user_bots ) ) {
    foreach ( $user_bots as $bot ) {
        if ( $bot->page_id === $user_page_id ) {
            $user_page = array(
                'id'           => $bot->page_id,
                'name'         => $bot->bot_name,
                'access_token' => $bot->page_access_token,
            );
            break;
        }
    }
}

$nonce = wp_create_nonce( 'bztfb_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );
$main_webhook_url = class_exists( 'BizCity_Facebook_Central_Webhook' )
    ? BizCity_Facebook_Central_Webhook::get_webhook_url()
    : ( is_multisite() ? network_site_url( '/facehook/' ) : home_url( '/?facehook=1' ) );

get_header();
?>
<style>
.bztfb-wrap { max-width: 960px; margin: 30px auto; padding: 0 16px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.bztfb-tabs { display: flex; gap: 0; border-bottom: 2px solid #1877f2; margin-bottom: 24px; }
.bztfb-tab { padding: 12px 24px; cursor: pointer; border: none; background: #f0f2f5; color: #65676b; font-weight: 600; border-radius: 8px 8px 0 0; transition: all .2s; }
.bztfb-tab.active { background: #1877f2; color: #fff; }
.bztfb-panel { display: none; }
.bztfb-panel.active { display: block; }
.bztfb-card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
.bztfb-btn { display: inline-block; padding: 10px 24px; border-radius: 8px; border: none; cursor: pointer; font-weight: 600; font-size: 14px; transition: all .2s; }
.bztfb-btn-primary { background: #1877f2; color: #fff; }
.bztfb-btn-primary:hover { background: #166fe5; }
.bztfb-btn-danger { background: #dc3545; color: #fff; }
.bztfb-btn-success { background: #42b72a; color: #fff; }
.bztfb-input { width: 100%; padding: 10px 14px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
textarea.bztfb-input { min-height: 120px; resize: vertical; }
.bztfb-label { display: block; margin-bottom: 6px; font-weight: 600; color: #333; }
.bztfb-field { margin-bottom: 16px; }
.bztfb-pages-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px; }
.bztfb-page-card { background: #f8f9fa; border-radius: 8px; padding: 16px; border: 1px solid #e4e6eb; }
.bztfb-page-card h4 { margin: 0 0 8px; }
.bztfb-page-card code { font-size: 12px; color: #65676b; }
.bztfb-job-row { display: flex; align-items: center; gap: 12px; padding: 12px 0; border-bottom: 1px solid #e4e6eb; }
.bztfb-job-row:last-child { border-bottom: none; }
.bztfb-status { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; }
.bztfb-status-completed { background: #d4edda; color: #155724; }
.bztfb-status-pending, .bztfb-status-generating { background: #fff3cd; color: #856404; }
.bztfb-status-posting { background: #cce5ff; color: #004085; }
.bztfb-status-failed { background: #f8d7da; color: #721c24; }
.bztfb-result { margin-top: 16px; padding: 16px; border-radius: 8px; display: none; }
.bztfb-result.success { display: block; background: #d4edda; color: #155724; }
.bztfb-result.error { display: block; background: #f8d7da; color: #721c24; }
.bztfb-result.loading { display: block; background: #fff3cd; color: #856404; }
.bztfb-preview-box { background: #fff; border: 2px solid #e4e6eb; border-radius: 12px; overflow: hidden; margin-top: 16px; display: none; }
.bztfb-preview-box.active { display: block; }
.bztfb-preview-header { padding: 12px 16px; background: #f0f2f5; display: flex; align-items: center; gap: 8px; font-weight: 600; color: #1877f2; border-bottom: 1px solid #e4e6eb; }
.bztfb-preview-content { padding: 16px; white-space: pre-wrap; line-height: 1.6; font-size: 14px; }
.bztfb-preview-image { width: 100%; max-height: 400px; object-fit: cover; border-top: 1px solid #e4e6eb; }
.bztfb-preview-actions { padding: 12px 16px; background: #f0f2f5; border-top: 1px solid #e4e6eb; display: flex; gap: 8px; align-items: center; }
.bztfb-file-upload-area { border: 2px dashed #ddd; border-radius: 8px; padding: 16px; text-align: center; cursor: pointer; transition: all .2s; position: relative; }
.bztfb-file-upload-area:hover { border-color: #1877f2; background: #f0f5ff; }
.bztfb-file-upload-area.has-file { border-color: #42b72a; background: #f0fff0; }
.bztfb-file-preview { max-height: 120px; border-radius: 6px; margin-top: 8px; }
.bztfb-image-tabs { display: flex; gap: 0; margin-bottom: 8px; }
.bztfb-image-tab { padding: 6px 16px; cursor: pointer; border: 1px solid #ddd; background: #f5f5f5; font-size: 13px; font-weight: 600; color: #65676b; }
.bztfb-image-tab:first-child { border-radius: 6px 0 0 6px; }
.bztfb-image-tab:last-child { border-radius: 0 6px 6px 0; }
.bztfb-image-tab.active { background: #1877f2; color: #fff; border-color: #1877f2; }
.bztfb-image-panel { display: none; }
.bztfb-image-panel.active { display: block; }
</style>

<div class="bztfb-wrap">
    <h1 style="display:flex;align-items:center;gap:8px;">📣 Facebook Tool</h1>
    <p style="color:#65676b;">Tạo & đăng bài AI lên Facebook Page — quản lý kết nối page — xem lịch sử.</p>

    <!-- Tabs -->
    <div class="bztfb-tabs">
        <button class="bztfb-tab active" data-tab="create">✍️ Tạo bài</button>
        <button class="bztfb-tab" data-tab="monitor">📊 Lịch sử</button>
        <button class="bztfb-tab" data-tab="pages">🔗 Pages</button>
        <button class="bztfb-tab" data-tab="settings">⚙️ Cài đặt</button>
    </div>

    <!-- Tab 1: Create Post -->
    <div class="bztfb-panel active" id="panel-create">
        <div class="bztfb-card">
            <h2>Tạo bài Facebook bằng AI</h2>
            <p style="color:#65676b;">Nhập chủ đề → AI sinh nội dung → xem trước → đồng ý rồi mới đăng.</p>

            <div class="bztfb-field">
                <label class="bztfb-label">📝 Chủ đề / Nội dung bài viết</label>
                <textarea class="bztfb-input" id="bztfb-topic" placeholder="Ví dụ: Khuyến mãi mùa hè 50% tất cả sản phẩm, tone vui vẻ..."></textarea>
            </div>

            <div class="bztfb-field">
                <label class="bztfb-label">🖼️ Hình ảnh (tùy chọn)</label>
                <div class="bztfb-image-tabs">
                    <button type="button" class="bztfb-image-tab active" data-img-tab="upload">📁 Tải file</button>
                    <button type="button" class="bztfb-image-tab" data-img-tab="url">🔗 URL</button>
                </div>
                <div class="bztfb-image-panel active" id="img-panel-upload">
                    <div class="bztfb-file-upload-area" id="bztfb-upload-area">
                        <input type="file" id="bztfb-file-input" accept="image/*" style="display:none;">
                        <div id="bztfb-upload-placeholder">
                            <p style="margin:0;color:#65676b;">📷 Kéo thả ảnh vào đây hoặc <strong style="color:#1877f2;">bấm để chọn file</strong></p>
                            <p style="margin:4px 0 0;font-size:12px;color:#999;">JPG, PNG, GIF, WebP — tối đa 10MB</p>
                        </div>
                        <div id="bztfb-upload-preview" style="display:none;">
                            <img id="bztfb-file-preview-img" class="bztfb-file-preview" src="" alt="Preview">
                            <p id="bztfb-file-name" style="margin:4px 0 0;font-size:12px;color:#42b72a;"></p>
                            <button type="button" id="bztfb-remove-file" class="bztfb-btn bztfb-btn-danger" style="margin-top:6px;padding:4px 12px;font-size:12px;">✕ Xóa ảnh</button>
                        </div>
                    </div>
                </div>
                <div class="bztfb-image-panel" id="img-panel-url">
                    <input class="bztfb-input" id="bztfb-image" type="url" placeholder="https://example.com/image.jpg">
                </div>
            </div>

            <div class="bztfb-field">
                <label class="bztfb-label">📣 Page đăng bài</label>
                <?php if ( $user_page ) : ?>
                    <p style="margin:4px 0;padding:10px 14px;background:#e8f5e9;border-radius:8px;border:1px solid #c8e6c9;">
                        ✅ <strong><?php echo esc_html( $user_page['name'] ?? $user_page['id'] ); ?></strong>
                        <small style="color:#65676b;">(Page mặc định của bạn — thay đổi ở tab <strong>Pages</strong>)</small>
                    </p>
                    <input type="hidden" id="bztfb-user-page-id" value="<?php echo esc_attr( $user_page['id'] ); ?>">
                <?php elseif ( $pages ) : ?>
                    <p style="color:#f0ad4e;">⚠️ Bạn chưa chọn Page mặc định. Vào tab <strong>Pages</strong> để chọn, hoặc chọn bên dưới:</p>
                    <?php foreach ( $pages as $page ) : ?>
                        <label style="display:block;margin:4px 0;">
                            <input type="checkbox" class="bztfb-page-check" value="<?php echo esc_attr( $page['id'] ); ?>" checked>
                            <?php echo esc_html( $page['name'] ?? $page['id'] ); ?>
                        </label>
                    <?php endforeach; ?>
                <?php elseif ( $user_bots ) : ?>
                    <p style="color:#f0ad4e;">⚠️ Bạn chưa chọn Page mặc định. Vào tab <strong>Pages</strong> để chọn, hoặc chọn bên dưới:</p>
                    <?php foreach ( $user_bots as $bot ) : ?>
                        <label style="display:block;margin:4px 0;">
                            <input type="checkbox" class="bztfb-page-check" value="<?php echo esc_attr( $bot->page_id ); ?>" checked>
                            <?php echo esc_html( $bot->bot_name ); ?>
                        </label>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p style="color:#999;">Chưa kết nối Page nào. Vào tab <strong>Cài đặt</strong> để cấu hình Developer App.</p>
                <?php endif; ?>
            </div>

            <button class="bztfb-btn bztfb-btn-primary" id="bztfb-gen-preview" <?php echo ( $user_page || $pages || $user_bots ) ? '' : 'disabled'; ?>>
                🤖 Gen AI — Xem trước
            </button>

            <div class="bztfb-result" id="bztfb-result"></div>

            <!-- Preview Box -->
            <div class="bztfb-preview-box" id="bztfb-preview-box">
                <div class="bztfb-preview-header">
                    👁️ Xem trước bài đăng
                </div>
                <div class="bztfb-preview-content" id="bztfb-preview-content"></div>
                <img class="bztfb-preview-image" id="bztfb-preview-image" src="" alt="" style="display:none;">
                <div class="bztfb-preview-actions">
                    <button class="bztfb-btn bztfb-btn-success" id="bztfb-confirm-post">✅ Đồng ý — Đăng bài</button>
                    <button class="bztfb-btn" id="bztfb-regen" style="background:#f0f2f5;color:#333;">🔄 Tạo lại</button>
                    <button class="bztfb-btn bztfb-btn-danger" id="bztfb-cancel-preview" style="padding:8px 16px;">✕ Hủy</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab 2: Monitor -->
    <div class="bztfb-panel" id="panel-monitor">
        <div class="bztfb-card">
            <h2>Lịch sử đăng bài</h2>
            <div id="bztfb-jobs-list"><p style="color:#999;">Đang tải...</p></div>
        </div>
    </div>

    <!-- Tab 3: Pages -->
    <div class="bztfb-panel" id="panel-pages">
        <div class="bztfb-card">
            <h2>📄 Fanpage đã kết nối</h2>
            <?php if ( ! empty( $user_bots ) ) : ?>
                <p style="color:#65676b;">Danh sách các Fanpage bạn đã kết nối qua Developer App.</p>
                <div class="bztfb-pages-grid">
                    <?php foreach ( $user_bots as $bot ) : ?>
                        <div class="bztfb-page-card" style="border-color:#42b72a;">
                            <h4><?php echo esc_html( $bot->bot_name ); ?></h4>
                            <code>Page ID: <?php echo esc_html( $bot->page_id ); ?></code>
                            <br><small style="color:#42b72a;">✅ Developer App</small>
                            <div style="margin-top:8px;">
                                <?php if ( $user_page_id === $bot->page_id ) : ?>
                                    <span style="display:inline-block;padding:4px 12px;background:#d4edda;color:#155724;border-radius:4px;font-size:12px;font-weight:600;">⭐ Page mặc định</span>
                                <?php else : ?>
                                    <button class="bztfb-btn bztfb-set-default-page" data-page-id="<?php echo esc_attr( $bot->page_id ); ?>" style="padding:4px 12px;font-size:12px;background:#e3f2fd;color:#0d47a1;border:1px solid #90caf9;border-radius:4px;cursor:pointer;">Đặt làm mặc định</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p style="color:#999;">Chưa kết nối Fanpage nào.</p>
                <p style="color:#65676b;">Vui lòng vào tab <strong>⚙️ Cài đặt</strong> để cấu hình Developer App và kết nối Facebook.</p>
            <?php endif; ?>
        </div>

        <?php if ( $user_oauth_url ) : ?>
        <div class="bztfb-card">
            <h2>🔗 Kết nối thêm Page</h2>
            <p style="color:#65676b;">Bấm nút bên dưới để kết nối thêm Fanpage từ tài khoản Facebook của bạn.</p>
            <a href="<?php echo esc_url( $user_oauth_url ); ?>" target="_blank" rel="noopener" class="bztfb-btn bztfb-btn-success" style="text-decoration:none;font-size:16px;padding:12px 32px;">
                🔗 Kết nối Facebook (App của bạn)
            </a>
        </div>
        <?php elseif ( empty( $user_bots ) ) : ?>
        <div class="bztfb-card">
            <h2>🔗 Kết nối Facebook</h2>
            <p style="color:#65676b;">Để kết nối Fanpage, bạn cần cấu hình Developer App trước.</p>
            <button class="bztfb-btn bztfb-btn-primary bztfb-go-settings">⚙️ Đi đến Cài đặt</button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab 4: Settings -->
    <div class="bztfb-panel" id="panel-settings">

        <!-- Plan A: Facebook Profile for Tester Request (hidden, focus Plan B) -->
        <div class="bztfb-card" style="border-left: 4px solid #1877f2; display:none;">
            <h2>👤 Phương án A — Đăng ký Tester</h2>
            <p style="color:#65676b;">Nhập Facebook URL, username hoặc Facebook ID của bạn. <strong>Super Admin</strong> sẽ thêm bạn vào danh sách Tester của Facebook App để bạn có thể sử dụng tính năng đăng nhập Facebook.</p>
            <div class="bztfb-field">
                <label class="bztfb-label">🔗 Facebook Profile</label>
                <input class="bztfb-input" id="bztfb-fb-profile" type="text"
                    value="<?php echo esc_attr( $fb_profile ); ?>"
                    placeholder="VD: https://facebook.com/username hoặc 100012345678 hoặc username.fb"
                    style="max-width:500px;">
                <p style="margin-top:8px;color:#65676b;font-size:13px;">
                    💡 <strong>Cách lấy thông tin:</strong><br>
                    • <strong>URL:</strong> Mở Facebook → vào trang cá nhân → copy URL trên thanh địa chỉ (VD: <code>https://facebook.com/nguyen.van.a</code>)<br>
                    • <strong>Username:</strong> Phần sau facebook.com/ trong URL (VD: <code>nguyen.van.a</code>)<br>
                    • <strong>Facebook ID:</strong> Vào <a href="https://findmyfbid.in/" target="_blank" rel="noopener">findmyfbid.in</a> → dán URL profile → lấy số ID (VD: <code>100012345678</code>)
                </p>
            </div>
            <?php if ( $tester_requested ) : ?>
                <p style="padding:8px 14px;background:#d4edda;border-radius:8px;color:#155724;">
                    ✅ Đã gửi yêu cầu lúc <strong><?php echo esc_html( $tester_requested ); ?></strong>. Vui lòng chờ Super Admin duyệt.
                </p>
            <?php endif; ?>
            <button class="bztfb-btn bztfb-btn-primary" id="bztfb-save-fb-profile">📩 Gửi yêu cầu Tester</button>
            <span id="bztfb-fb-profile-msg" style="margin-left:10px;"></span>
        </div>

        <!-- Plan B: User's Own Facebook Developer App -->
        <div class="bztfb-card" style="border-left: 4px solid #42b72a;">
            <h2>🛠️ Phương án B — Tự cấu hình Developer App</h2>
            <p style="color:#65676b;">Nếu bạn có tài khoản <a href="https://developers.facebook.com/" target="_blank" rel="noopener">Facebook Developer</a>, tự tạo App và cấu hình để kết nối trực tiếp. Phương án này độc lập với cấu hình của Site Admin.</p>
            <details <?php echo ( $user_app_id ? 'open' : '' ); ?>>
                <summary style="cursor:pointer;font-weight:600;color:#333;margin-bottom:12px;">Mở cấu hình Developer App ▾</summary>
                <div style="margin-top:12px;">
                    <p style="padding:10px 14px;background:#fff3cd;border-radius:8px;color:#856404;font-size:13px;">
                        📋 <strong>Hướng dẫn tạo Facebook App:</strong><br>
                        1. Vào <a href="https://developers.facebook.com/apps/" target="_blank">developers.facebook.com/apps</a> → <strong>Create App</strong><br>
                        &nbsp;&nbsp;&nbsp;→ Chọn <strong>Other</strong> → <strong>Other</strong> → chọn loại <strong>Business</strong> → bấm <strong>Next</strong><br>
                        &nbsp;&nbsp;&nbsp;→ Đặt tên App → chọn Business Account (nếu có) → bấm <strong>Create App</strong><br>
                        2. Vào <strong>App Settings → Basic</strong>:<br>
                        &nbsp;&nbsp;&nbsp;→ Copy <strong>App ID</strong> và <strong>App Secret</strong><br>
                        &nbsp;&nbsp;&nbsp;→ Dán vào ô <strong>Privacy Policy URL</strong>: <code style="background:#f0f0f0;padding:2px 6px;border-radius:3px;">https://bizgpt.vn/chinh-sach-bao-mat-quyen-rieng-tu/</code><br>
                        &nbsp;&nbsp;&nbsp;→ Tải <strong>App Icon</strong> (logo 1024x1024) → bấm <strong>Save Changes</strong><br>
                        3. Thêm sản phẩm <strong>Facebook Login for Business</strong><br>
                        4. Vào <strong>Facebook Login → Settings</strong> → thêm <strong>Valid OAuth Redirect URI</strong>:<br>
                        <code style="display:inline-block;margin:4px 0;padding:4px 8px;background:#f0f0f0;border-radius:4px;"><?php echo esc_html( home_url( '/?biz_fb_oauth=callback' ) ); ?></code><br>
                        5. Dán App ID và App Secret vào form bên dưới → Lưu → bấm <strong>Kết nối Facebook</strong><br>
                        6. Sau khi test thành công, bấm <strong>Go Live</strong> (ở banner trên cùng) để App chuyển sang Live Mode
                    </p>
                    <p style="margin-top:8px;padding:8px 14px;background:#e3f2fd;border-radius:8px;color:#0d47a1;font-size:13px;">
                        🎬 <strong>Video hướng dẫn chi tiết:</strong>
                        <a href="https://youtu.be/W9o3fMk7evU" target="_blank" rel="noopener" style="color:#1565c0;font-weight:600;">Xem trên YouTube ↗</a>
                    </p>
                    <div class="bztfb-field">
                        <label class="bztfb-label">App ID</label>
                        <input class="bztfb-input" id="bztfb-user-app-id" type="text"
                            value="<?php echo esc_attr( $user_app_id ); ?>"
                            placeholder="VD: 1234567890123456"
                            style="max-width:400px;">
                    </div>
                    <div class="bztfb-field">
                        <label class="bztfb-label">App Secret</label>
                        <input class="bztfb-input" id="bztfb-user-app-secret" type="password"
                            value="<?php echo esc_attr( $user_app_secret ); ?>"
                            placeholder="VD: abc123def456..."
                            style="max-width:400px;">
                    </div>
                    <button class="bztfb-btn bztfb-btn-primary" id="bztfb-save-user-app">💾 Lưu App Config</button>
                    <span id="bztfb-user-app-msg" style="margin-left:10px;"></span>

                    <?php if ( $user_oauth_url ) : ?>
                        <div style="margin-top:16px;padding:12px;background:#e8f5e9;border-radius:8px;">
                            <p style="margin:0 0 8px;">✅ App đã cấu hình. Bấm nút bên dưới để kết nối Facebook bằng App của bạn:</p>
                            <a href="<?php echo esc_url( $user_oauth_url ); ?>" target="_blank" rel="noopener" class="bztfb-btn bztfb-btn-success" style="text-decoration:none;">
                                🔗 Kết nối Facebook (App của bạn)
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $user_bots ) ) : ?>
                        <div style="margin-top:16px;">
                            <h3 style="margin:0 0 8px;">📄 Pages của bạn (qua Developer App)</h3>
                            <div class="bztfb-pages-grid">
                                <?php foreach ( $user_bots as $bot ) : ?>
                                    <div class="bztfb-page-card" style="border-color:#42b72a;">
                                        <h4><?php echo esc_html( $bot->bot_name ); ?></h4>
                                        <code>Page ID: <?php echo esc_html( $bot->page_id ); ?></code>
                                        <br><small style="color:#42b72a;">✅ Developer App</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>

        <!-- OAuth Redirect URI Info -->
        <div class="bztfb-card" style="border-left: 4px solid #65676b;">
            <h2>ℹ️ Thông tin kỹ thuật</h2>
            <table style="width:100%;border-collapse:collapse;">
                <tr>
                    <td style="padding:6px 0;font-weight:600;width:200px;">OAuth Redirect URI (Admin):</td>
                    <td><code><?php
                        $hub_url = class_exists( 'BizCity_Facebook_Central_Webhook' )
                            ? BizCity_Facebook_Central_Webhook::get_hub_site_url()
                            : network_site_url();
                        echo esc_html( trailingslashit( $hub_url ) . '?biz_fb_oauth=callback' );
                    ?></code></td>
                </tr>
                <tr>
                    <td style="padding:6px 0;font-weight:600;">OAuth Redirect URI (User):</td>
                    <td><code><?php echo esc_html( home_url( '/?biz_fb_oauth=callback' ) ); ?></code></td>
                </tr>
                <tr>
                    <td style="padding:6px 0;font-weight:600;">Central Webhook:</td>
                    <td><code><?php echo esc_html( $main_webhook_url ); ?></code></td>
                </tr>
            </table>
        </div>

        <!-- AI Settings -->
        <div class="bztfb-card">
            <h2>🤖 Cài đặt AI</h2>
            <div class="bztfb-field">
                <label class="bztfb-label">Model AI tạo nội dung</label>
                <select class="bztfb-input" id="bztfb-ai-model">
                    <option value="gpt-4o" <?php selected( get_option( 'bztfb_ai_model', 'gpt-4o' ), 'gpt-4o' ); ?>>GPT-4o</option>
                    <option value="gpt-4o-mini" <?php selected( get_option( 'bztfb_ai_model', 'gpt-4o' ), 'gpt-4o-mini' ); ?>>GPT-4o Mini</option>
                </select>
            </div>
            <button class="bztfb-btn bztfb-btn-primary" id="bztfb-save-settings">💾 Lưu cài đặt</button>
            <div class="bztfb-result" id="bztfb-settings-result"></div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    var ajaxUrl = '<?php echo esc_js( $ajax_url ); ?>';
    var nonce   = '<?php echo esc_js( $nonce ); ?>';

    // Tab navigation
    $('.bztfb-tab').on('click', function() {
        var tab = $(this).data('tab');
        $('.bztfb-tab').removeClass('active');
        $(this).addClass('active');
        $('.bztfb-panel').removeClass('active');
        $('#panel-' + tab).addClass('active');
        if (tab === 'monitor') loadJobs();
    });

    // Image tab switching
    $('.bztfb-image-tab').on('click', function() {
        var tab = $(this).data('img-tab');
        $('.bztfb-image-tab').removeClass('active');
        $(this).addClass('active');
        $('.bztfb-image-panel').removeClass('active');
        $('#img-panel-' + tab).addClass('active');
    });

    // File upload: click area to trigger input
    var uploadedFile = null;
    var uploadedImageUrl = '';
    $('#bztfb-upload-area').on('click', function(e) {
        if ($(e.target).closest('#bztfb-remove-file').length || e.target.id === 'bztfb-file-input') return;
        document.getElementById('bztfb-file-input').click();
    });

    // File upload: drag & drop
    $('#bztfb-upload-area').on('dragover', function(e) {
        e.preventDefault(); e.stopPropagation();
        $(this).css('border-color', '#1877f2');
    }).on('dragleave', function(e) {
        e.preventDefault(); e.stopPropagation();
        $(this).css('border-color', uploadedFile ? '#42b72a' : '#ddd');
    }).on('drop', function(e) {
        e.preventDefault(); e.stopPropagation();
        var files = e.originalEvent.dataTransfer.files;
        if (files.length && files[0].type.startsWith('image/')) {
            handleFileSelect(files[0]);
        }
    });

    $('#bztfb-file-input').on('change', function() {
        if (this.files.length) handleFileSelect(this.files[0]);
    });

    function handleFileSelect(file) {
        if (file.size > 10 * 1024 * 1024) { alert('File quá lớn (max 10MB)'); return; }
        uploadedFile = file;
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#bztfb-file-preview-img').attr('src', e.target.result);
            $('#bztfb-file-name').text(file.name + ' (' + (file.size / 1024).toFixed(0) + ' KB)');
            $('#bztfb-upload-placeholder').hide();
            $('#bztfb-upload-preview').show();
            $('#bztfb-upload-area').addClass('has-file');
        };
        reader.readAsDataURL(file);
    }

    $('#bztfb-remove-file').on('click', function(e) {
        e.stopPropagation();
        uploadedFile = null;
        uploadedImageUrl = '';
        $('#bztfb-file-input').val('');
        $('#bztfb-upload-placeholder').show();
        $('#bztfb-upload-preview').hide();
        $('#bztfb-upload-area').removeClass('has-file');
    });

    // Upload file to server, returns attachment URL
    function uploadFileToServer(callback) {
        if (!uploadedFile) { callback(null); return; }
        var fd = new FormData();
        fd.append('action', 'bztfb_upload_image');
        fd.append('nonce', nonce);
        fd.append('image', uploadedFile);
        $.ajax({
            url: ajaxUrl, type: 'POST', data: fd,
            processData: false, contentType: false,
            success: function(res) {
                if (res.success) {
                    uploadedImageUrl = res.data.url;
                    callback(res.data.url);
                } else {
                    callback(null, res.data || 'Lỗi upload');
                }
            },
            error: function() { callback(null, 'Lỗi kết nối server'); }
        });
    }

    // Helper: get page IDs
    function getPageIds() {
        var ids = [];
        var userPageId = $('#bztfb-user-page-id').val();
        if (userPageId) { ids.push(userPageId); }
        else { $('.bztfb-page-check:checked').each(function() { ids.push($(this).val()); }); }
        return ids;
    }

    // Store preview data for confirm step
    var previewData = null;

    // Step 1: Gen AI Preview
    $('#bztfb-gen-preview').on('click', function() {
        var btn = $(this);
        var topic = $('#bztfb-topic').val().trim();
        if (!topic) { alert('Nhập chủ đề!'); return; }

        btn.prop('disabled', true).text('⏳ AI đang tạo...');
        $('#bztfb-result').attr('class', 'bztfb-result loading').text('Đang tạo nội dung AI... vui lòng chờ.').show();
        $('#bztfb-preview-box').removeClass('active');

        function doGenerate(imageUrl) {
            $.post(ajaxUrl, {
                action: 'bztfb_generate_preview',
                nonce: nonce,
                topic: topic,
                image_url: imageUrl || ''
            }, function(res) {
                btn.prop('disabled', false).text('🤖 Gen AI — Xem trước');
                if (res.success && res.data) {
                    previewData = res.data;
                    previewData.page_ids = getPageIds();
                    // Show preview
                    var content = '';
                    if (res.data.title) content += '📌 ' + res.data.title + '\n\n';
                    content += res.data.content || '';
                    $('#bztfb-preview-content').text(content);
                    var imgSrc = imageUrl || res.data.image_url || '';
                    if (imgSrc) {
                        $('#bztfb-preview-image').attr('src', imgSrc).show();
                        previewData.image_url = imgSrc;
                    } else {
                        $('#bztfb-preview-image').hide();
                    }
                    $('#bztfb-preview-box').addClass('active');
                    $('#bztfb-result').hide();
                } else {
                    $('#bztfb-result').attr('class', 'bztfb-result error').text('❌ ' + (res.data || 'Lỗi tạo nội dung')).show();
                }
            }).fail(function() {
                btn.prop('disabled', false).text('🤖 Gen AI — Xem trước');
                $('#bztfb-result').attr('class', 'bztfb-result error').text('❌ Lỗi kết nối server.').show();
            });
        }

        // Check if user uploaded a file
        var urlImage = $('#bztfb-image').val().trim();
        if (uploadedFile && !uploadedImageUrl) {
            uploadFileToServer(function(url, err) {
                if (err) {
                    btn.prop('disabled', false).text('🤖 Gen AI — Xem trước');
                    $('#bztfb-result').attr('class', 'bztfb-result error').text('❌ Upload ảnh lỗi: ' + err).show();
                    return;
                }
                doGenerate(url);
            });
        } else {
            doGenerate(uploadedImageUrl || urlImage);
        }
    });

    // Step 2: Confirm & Publish
    $('#bztfb-confirm-post').on('click', function() {
        if (!previewData) return;
        var btn = $(this);
        btn.prop('disabled', true).text('⏳ Đang đăng...');

        $.post(ajaxUrl, {
            action: 'bztfb_publish_post',
            nonce: nonce,
            title: previewData.title,
            content: previewData.content,
            image_url: previewData.image_url || '',
            page_ids: previewData.page_ids || []
        }, function(res) {
            btn.prop('disabled', false).text('✅ Đồng ý — Đăng bài');
            if (res.success) {
                $('#bztfb-preview-box').removeClass('active');
                var successHtml = '<strong>✅ Đăng bài thành công!</strong>';
                if (res.data && res.data.wp_post_id) {
                    var editUrl = ajaxUrl.replace('admin-ajax.php', 'post.php?post=' + res.data.wp_post_id + '&action=edit');
                    successHtml += '<br>📝 <a href="' + editUrl + '" target="_blank" style="color:#155724;font-weight:600;">Sửa bài WordPress</a>';
                }
                if (res.data && res.data.fb_post_ids && res.data.fb_post_ids.length) {
                    res.data.fb_post_ids.forEach(function(fb) {
                        if (fb.link) {
                            successHtml += '<br>📣 <a href="' + fb.link + '" target="_blank" style="color:#155724;font-weight:600;">Xem trên Facebook</a>';
                        }
                    });
                }
                $('#bztfb-result').attr('class', 'bztfb-result success').html(successHtml).show();
                $('#bztfb-topic').val('');
                $('#bztfb-image').val('');
                // Reset file
                uploadedFile = null; uploadedImageUrl = '';
                $('#bztfb-file-input').val('');
                $('#bztfb-upload-placeholder').show();
                $('#bztfb-upload-preview').hide();
                $('#bztfb-upload-area').removeClass('has-file');
                previewData = null;
            } else {
                $('#bztfb-result').attr('class', 'bztfb-result error').text('❌ ' + (res.data || 'Lỗi đăng bài')).show();
            }
        }).fail(function() {
            btn.prop('disabled', false).text('✅ Đồng ý — Đăng bài');
            $('#bztfb-result').attr('class', 'bztfb-result error').text('❌ Lỗi kết nối server.').show();
        });
    });

    // Regen: gen again with same topic
    $('#bztfb-regen').on('click', function() {
        $('#bztfb-gen-preview').trigger('click');
    });

    // Cancel preview
    $('#bztfb-cancel-preview').on('click', function() {
        $('#bztfb-preview-box').removeClass('active');
        previewData = null;
    });

    // Connect Page
    $('#bztfb-connect').on('click', function() {
        var token = $('#bztfb-token').val().trim();
        if (!token) { alert('Nhập Access Token!'); return; }
        $(this).prop('disabled', true).text('Đang kết nối...');
        $.post(ajaxUrl, { action: 'bztfb_connect_page', nonce: nonce, access_token: token }, function(res) {
            $('#bztfb-connect').prop('disabled', false).text('Kết nối Page');
            if (res.success) {
                $('#bztfb-connect-result').attr('class', 'bztfb-result success').text('✅ ' + res.data.message);
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $('#bztfb-connect-result').attr('class', 'bztfb-result error').text('❌ ' + res.data);
            }
        });
    });

    // Disconnect Page
    $(document).on('click', '.bztfb-disconnect', function() {
        if (!confirm('Ngắt kết nối Page này?')) return;
        var pageId = $(this).data('page-id');
        $.post(ajaxUrl, { action: 'bztfb_disconnect_page', nonce: nonce, page_id: pageId }, function(res) {
            if (res.success) location.reload();
        });
    });

    // Register Routes
    $('#bztfb-register-routes').on('click', function() {
        $(this).prop('disabled', true).text('Đang đồng bộ...');
        $.post(ajaxUrl, { action: 'bztfb_register_route', nonce: nonce }, function(res) {
            $('#bztfb-register-routes').prop('disabled', false).text('🔄 Đồng bộ Routes');
            alert(res.success ? '✅ ' + res.data : '❌ ' + res.data);
        });
    });

    // Set default page from Pages tab
    $(document).on('click', '.bztfb-set-default-page', function() {
        var btn = $(this);
        var pageId = btn.data('page-id');
        btn.prop('disabled', true).text('⏳...');
        $.post(ajaxUrl, { action: 'bztfb_set_user_page', nonce: nonce, page_id: pageId }, function(res) {
            btn.prop('disabled', false);
            if (res.success) {
                location.reload();
            } else {
                alert('❌ ' + (res.data || 'Lỗi'));
            }
        });
    });

    // Go to Settings tab
    $(document).on('click', '.bztfb-go-settings', function() {
        $('.bztfb-tab[data-tab="settings"]').click();
    });

    // Save user page assignment
    $('#bztfb-save-user-page').on('click', function() {
        var btn = $(this);
        var pageId = $('#bztfb-user-page-select').val();
        btn.prop('disabled', true);
        $.post(ajaxUrl, { action: 'bztfb_set_user_page', nonce: nonce, page_id: pageId }, function(res) {
            btn.prop('disabled', false);
            if (res.success) {
                $('#bztfb-user-page-msg').text('✅ ' + res.data.message).css('color', '#155724').show();
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                $('#bztfb-user-page-msg').text('❌ ' + (res.data || 'Lỗi')).css('color', '#721c24').show();
            }
        });
    });

    // Save Settings
    $('#bztfb-save-settings').on('click', function() {
        $.post(ajaxUrl, {
            action: 'bztfb_save_settings',
            nonce: nonce,
            ai_model: $('#bztfb-ai-model').val()
        }, function(res) {
            $('#bztfb-settings-result').attr('class', 'bztfb-result ' + (res.success ? 'success' : 'error'))
                .text(res.success ? '✅ Đã lưu' : '❌ Lỗi').show();
        });
    });

    // Plan A: Save Facebook Profile for Tester Request
    $('#bztfb-save-fb-profile').on('click', function() {
        var btn = $(this);
        var fbProfile = $('#bztfb-fb-profile').val().trim();
        btn.prop('disabled', true);
        $.post(ajaxUrl, { action: 'bztfb_save_fb_profile', nonce: nonce, fb_profile: fbProfile }, function(res) {
            btn.prop('disabled', false);
            if (res.success) {
                $('#bztfb-fb-profile-msg').text('✅ ' + res.data.message).css('color', '#155724').show();
            } else {
                $('#bztfb-fb-profile-msg').text('❌ ' + (res.data || 'Lỗi')).css('color', '#721c24').show();
            }
        });
    });

    // Plan B: Save User's Own Facebook App Config
    $('#bztfb-save-user-app').on('click', function() {
        var btn = $(this);
        var appId = $('#bztfb-user-app-id').val().trim();
        var appSecret = $('#bztfb-user-app-secret').val().trim();
        if (!appId || !appSecret) { alert('Nhập App ID và App Secret!'); return; }
        btn.prop('disabled', true);
        $.post(ajaxUrl, {
            action: 'bztfb_save_user_app',
            nonce: nonce,
            user_app_id: appId,
            user_app_secret: appSecret
        }, function(res) {
            btn.prop('disabled', false);
            if (res.success) {
                $('#bztfb-user-app-msg').text('✅ ' + res.data.message).css('color', '#155724').show();
                setTimeout(function() { location.reload(); }, 1500);
            } else {
                $('#bztfb-user-app-msg').text('❌ ' + (res.data || 'Lỗi')).css('color', '#721c24').show();
            }
        });
    });

    // Load jobs
    function loadJobs() {
        $.post(ajaxUrl, { action: 'bztfb_poll_jobs', nonce: nonce }, function(res) {
            if (!res.success) return;
            var html = '';
            var jobs = res.data.jobs || [];
            if (!jobs.length) { html = '<p style="color:#999;">Chưa có bài đăng nào.</p>'; }
            jobs.forEach(function(job) {
                var title = job.ai_title || job.topic || '(Không có tiêu đề)';
                var fullTitle = title;
                if (title.length > 50) title = title.substring(0, 50) + '...';
                html += '<div class="bztfb-job-row" style="flex-wrap:wrap;">';
                html += '<span style="flex:1;font-weight:600;min-width:200px;">' + $('<span>').text(title).html() + '</span>';
                html += '<span class="bztfb-status bztfb-status-' + job.status + '">' + job.status + '</span>';
                html += '<span style="color:#999;font-size:12px;min-width:130px;">' + (job.created_at || '') + '</span>';
                // FB & WP links
                var fbIds = [];
                try { fbIds = typeof job.fb_post_ids === 'string' ? JSON.parse(job.fb_post_ids) : (job.fb_post_ids || []); } catch(e) {}
                if (job.status === 'completed') {
                    if (fbIds.length) {
                        fbIds.forEach(function(fb) {
                            if (fb.link) html += '<a href="' + fb.link + '" target="_blank" style="font-size:11px;color:#1877f2;text-decoration:none;margin-left:6px;">📣 Facebook ↗</a>';
                        });
                    }
                    if (job.wp_post_id) {
                        var wpEdit = ajaxUrl.replace('admin-ajax.php', 'post.php?post=' + job.wp_post_id + '&action=edit');
                        html += '<a href="' + wpEdit + '" target="_blank" style="font-size:11px;color:#0d47a1;text-decoration:none;margin-left:6px;">📝 WP ↗</a>';
                    }
                }
                html += '<span style="display:flex;gap:4px;margin-left:8px;">';
                if (job.status === 'failed' || job.status === 'completed') {
                    html += '<button class="bztfb-btn bztfb-job-retry" data-id="' + job.id + '" '
                         + 'data-title="' + $('<span>').text(job.ai_title || job.topic || '').html() + '" '
                         + 'data-content="' + $('<span>').text(job.ai_content || '').html() + '" '
                         + 'data-image="' + $('<span>').text(job.image_url || '').html() + '" '
                         + 'style="padding:4px 10px;font-size:11px;background:#fff3cd;color:#856404;border:1px solid #ffc107;border-radius:4px;cursor:pointer;">\uD83D\uDD04 Retry</button>';
                }
                html += '<button class="bztfb-btn bztfb-job-edit" data-id="' + job.id + '" '
                     + 'data-title="' + $('<span>').text(job.ai_title || job.topic || '').html() + '" '
                     + 'data-content="' + $('<span>').text(job.ai_content || '').html() + '" '
                     + 'data-image="' + $('<span>').text(job.image_url || '').html() + '" '
                     + 'style="padding:4px 10px;font-size:11px;background:#e3f2fd;color:#0d47a1;border:1px solid #90caf9;border-radius:4px;cursor:pointer;">\u270F\uFE0F Edit</button>';
                html += '</span>';
                html += '</div>';
            });
            $('#bztfb-jobs-list').html(html);
        });
    }

    // Retry: re-post the same content
    $(document).on('click', '.bztfb-job-retry', function() {
        var btn = $(this);
        var title   = btn.attr('data-title');
        var content = btn.attr('data-content');
        var image   = btn.attr('data-image');
        if (!content) { alert('Không có nội dung để retry.'); return; }
        btn.prop('disabled', true).text('⏳...');
        $.post(ajaxUrl, {
            action: 'bztfb_publish_post',
            nonce: nonce,
            title: title,
            content: content,
            image_url: image,
            page_ids: getPageIds()
        }, function(res) {
            btn.prop('disabled', false).text('\uD83D\uDD04 Retry');
            if (res.success) {
                var retryMsg = 'Đã đăng lại thành công!';
                if (res.data && res.data.fb_post_ids && res.data.fb_post_ids.length) {
                    res.data.fb_post_ids.forEach(function(fb) {
                        if (fb.link) retryMsg += '\n📣 ' + fb.link;
                    });
                }
                alert('\u2705 ' + retryMsg);
                loadJobs();
            } else {
                alert('\u274C ' + (res.data || 'Lỗi đăng lại'));
            }
        }).fail(function() {
            btn.prop('disabled', false).text('\uD83D\uDD04 Retry');
            alert('\u274C Lỗi kết nối server.');
        });
    });

    // Edit: load content back to create tab for editing
    $(document).on('click', '.bztfb-job-edit', function() {
        var title   = $(this).attr('data-title');
        var content = $(this).attr('data-content');
        var image   = $(this).attr('data-image');
        // Switch to Create tab
        $('.bztfb-tab').removeClass('active');
        $('.bztfb-tab[data-tab="create"]').addClass('active');
        $('.bztfb-panel').removeClass('active');
        $('#panel-create').addClass('active');
        // Fill in data
        var full = '';
        if (title) full += title + '\n\n';
        if (content) full += content;
        $('#bztfb-topic').val(full);
        if (image) {
            // Switch to URL tab, fill URL
            $('.bztfb-image-tab').removeClass('active');
            $('.bztfb-image-tab[data-img-tab="url"]').addClass('active');
            $('.bztfb-image-panel').removeClass('active');
            $('#img-panel-url').addClass('active');
            $('#bztfb-image').val(image);
        }
        // Scroll to top
        $('html, body').animate({ scrollTop: $('#panel-create').offset().top - 50 }, 300);
    });
});
</script>
<?php
get_footer();
