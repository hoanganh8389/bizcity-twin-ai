<?php
/**
 * BizCity Tool Woo — Agent Profile (4-Tab SPA)
 *
 * Route: /tool-woo/ (also embedded in Touch Bar iframe)
 *
 * 4-tab layout: 🛍️ Tạo SP | 📋 Lịch sử | 💬 Quick Chat | ⚙️ Cài đặt
 *
 * @package BizCity_Tool_Woo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id  = get_current_user_id();
$icon_url = BZTOOL_WOO_URL . 'assets/icon.png';
$nonce    = wp_create_nonce( 'bztw_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );

// WooCommerce connection settings
$wp_site_url = $user_id ? get_user_meta( $user_id, 'bztw_wp_site_url', true ) : '';
$wc_ck       = $user_id ? get_user_meta( $user_id, 'bztw_wc_consumer_key', true ) : '';
$is_admin    = current_user_can( 'manage_options' );

/* ── Quick Chat workflows — 9 goals ── */
$workflows = [
    [ 'icon' => '🛍️', 'label' => 'Tạo sản phẩm', 'desc' => 'AI phân tích mô tả → tạo sản phẩm WooCommerce + giá + ảnh', 'tool' => 'create_product', 'msg' => 'Tạo sản phẩm áo thun cotton trắng giá 150k', 'tags' => [ 'AI parse', 'Ảnh SP', 'Auto create' ] ],
    [ 'icon' => '✏️', 'label' => 'Sửa sản phẩm', 'desc' => 'Sửa giá, tên, mô tả, danh mục sản phẩm bằng lệnh chat', 'tool' => 'update_product', 'msg' => 'Sửa giá sản phẩm áo thun trắng thành 200k', 'tags' => [ 'Edit', 'Giá', 'Mô tả' ] ],
    [ 'icon' => '📦', 'label' => 'Tạo đơn hàng', 'desc' => 'AI tự phân tích khách hàng, SP, thanh toán', 'tool' => 'create_order', 'msg' => 'Tạo đơn hàng cho Nguyễn Văn A, 2 áo thun trắng, SĐT 0901234567', 'tags' => [ 'AI parse', 'POS' ] ],
    [ 'icon' => '📊', 'label' => 'Thống kê doanh thu', 'desc' => 'Doanh thu, tổng đơn theo khoảng thời gian', 'tool' => 'revenue_report', 'msg' => 'Thống kê doanh thu 7 ngày gần nhất', 'tags' => [ 'Doanh thu', 'Chart' ] ],
    [ 'icon' => '🏆', 'label' => 'Top sản phẩm', 'desc' => 'Sản phẩm bán chạy nhất theo số lượng', 'tool' => 'top_products', 'msg' => 'Top sản phẩm bán chạy tuần này', 'tags' => [ 'Best seller' ] ],
    [ 'icon' => '👥', 'label' => 'Top khách hàng', 'desc' => 'Khách hàng mua nhiều nhất, chi tiêu cao', 'tool' => 'top_customers', 'msg' => 'Top khách hàng tháng này', 'tags' => [ 'VIP', 'Doanh số' ] ],
    [ 'icon' => '🔍', 'label' => 'Tra cứu khách hàng', 'desc' => 'Tìm khách theo SĐT, xem lịch sử đơn', 'tool' => 'find_customer', 'msg' => 'Tìm khách hàng 0901234567', 'tags' => [ 'SĐT', 'Lịch sử đơn' ] ],
    [ 'icon' => '📋', 'label' => 'Báo cáo kho', 'desc' => 'Xuất nhập tồn kho, sản phẩm tồn thấp', 'tool' => 'inventory_report', 'msg' => 'Báo cáo tồn kho tháng này', 'tags' => [ 'XNT', 'Tồn kho' ] ],
    [ 'icon' => '📥', 'label' => 'Nhập kho', 'desc' => 'AI phân tích → tạo phiếu nhập kho tự động', 'tool' => 'stock_in', 'msg' => 'Nhập kho 50 áo thun trắng giá mua 80k/cái', 'tags' => [ 'Phiếu nhập' ] ],
];

$tips = [
    [ 'icon' => '💡', 'tool' => 'create_product', 'text' => 'Tạo sản phẩm bánh mì chả lụa giá 25k' ],
    [ 'icon' => '💡', 'tool' => 'update_product', 'text' => 'Sửa giá SP #123 thành 300k, giảm còn 250k' ],
    [ 'icon' => '💡', 'tool' => 'create_order', 'text' => 'Tạo đơn hàng cho chị Lan, 3 bánh mì, SĐT 090xxx' ],
    [ 'icon' => '💡', 'tool' => 'revenue_report', 'text' => 'Thống kê doanh thu tuần này' ],
    [ 'icon' => '💡', 'tool' => 'find_customer', 'text' => 'Tìm khách hàng 0987654321' ],
    [ 'icon' => '💡', 'tool' => 'stock_in', 'text' => 'Nhập kho 100 hộp trà xanh giá mua 15k' ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BizCity Tool Woo – Agent</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1f2937;-webkit-font-smoothing:antialiased;overflow-x:hidden;}

/* ── Layout ── */
.bztw-wrap{max-width:100%;margin:0 auto;padding:12px 14px 32px;}

/* ── Tabs ── */
.bztw-tabs{display:flex;gap:0;border-bottom:2px solid #059669;margin-bottom:16px;overflow-x:auto;}
.bztw-tab{padding:10px 14px;cursor:pointer;border:none;background:#f0f2f5;color:#65676b;font-weight:600;font-size:13px;border-radius:8px 8px 0 0;transition:all .2s;white-space:nowrap;}
.bztw-tab.active{background:#059669;color:#fff;}
.bztw-panel{display:none;}
.bztw-panel.active{display:block;}

/* ── Card ── */
.bztw-card{background:#fff;border-radius:12px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.1);}
.bztw-card h2{font-size:16px;margin:0 0 8px;}

/* ── Form ── */
.bztw-field{margin-bottom:14px;}
.bztw-label{display:block;margin-bottom:4px;font-weight:600;color:#333;font-size:13px;}
.bztw-input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;}
textarea.bztw-input{min-height:100px;resize:vertical;}
select.bztw-input{appearance:auto;}
.bztw-btn{display:inline-block;padding:10px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;font-size:13px;transition:all .2s;}
.bztw-btn-primary{background:#059669;color:#fff;}
.bztw-btn-primary:hover{background:#047857;}
.bztw-btn-success{background:#16a34a;color:#fff;}
.bztw-btn-danger{background:#dc3545;color:#fff;}

/* ── Image Upload ── */
.bztw-image-tabs{display:flex;gap:0;margin-bottom:6px;}
.bztw-image-tab{padding:5px 14px;cursor:pointer;border:1px solid #ddd;background:#f5f5f5;font-size:12px;font-weight:600;color:#65676b;}
.bztw-image-tab:first-child{border-radius:6px 0 0 6px;}
.bztw-image-tab:last-child{border-radius:0 6px 6px 0;}
.bztw-image-tab.active{background:#059669;color:#fff;border-color:#059669;}
.bztw-image-panel{display:none;}
.bztw-image-panel.active{display:block;}
.bztw-upload-area{border:2px dashed #ddd;border-radius:8px;padding:14px;text-align:center;cursor:pointer;transition:all .2s;}
.bztw-upload-area:hover{border-color:#059669;background:#f0fff4;}
.bztw-upload-area.has-file{border-color:#059669;background:#f0fff4;}
.bztw-file-preview{max-height:100px;border-radius:6px;margin-top:6px;}

/* ── Preview Box ── */
.bztw-preview-box{background:#fff;border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-top:12px;display:none;}
.bztw-preview-box.active{display:block;}
.bztw-preview-header{padding:10px 14px;background:#ecfdf5;display:flex;align-items:center;gap:6px;font-weight:600;color:#059669;border-bottom:1px solid #e5e7eb;font-size:14px;}
.bztw-preview-row{padding:8px 14px;display:flex;gap:8px;border-bottom:1px solid #f3f4f6;font-size:13px;align-items:center;}
.bztw-preview-row-label{font-weight:600;color:#6b7280;min-width:70px;}
.bztw-preview-row-value{flex:1;}
.bztw-preview-desc{padding:14px;white-space:pre-wrap;line-height:1.6;font-size:13px;max-height:250px;overflow-y:auto;}
.bztw-preview-image{width:100%;max-height:180px;object-fit:cover;border-top:1px solid #e5e7eb;}
.bztw-preview-actions{padding:10px 14px;background:#f8fafc;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;}

/* ── Result ── */
.bztw-result{margin-top:12px;padding:12px;border-radius:8px;display:none;font-size:13px;}
.bztw-result.success{display:block;background:#d4edda;color:#155724;}
.bztw-result.error{display:block;background:#f8d7da;color:#721c24;}
.bztw-result.loading{display:block;background:#fff3cd;color:#856404;}

/* ── History List ── */
.bztw-history-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #f3f4f6;align-items:flex-start;}
.bztw-history-item:last-child{border-bottom:none;}
.bztw-history-prompt{flex:1;min-width:0;font-size:13px;line-height:1.4;}
.bztw-history-title{font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bztw-history-meta{font-size:11px;color:#9ca3af;margin-top:2px;}
.bztw-history-actions{flex-shrink:0;display:flex;gap:4px;}
.bztw-history-btn{padding:4px 10px;font-size:11px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-weight:600;}
.bztw-history-btn:hover{background:#f3f4f6;}

/* ── Quick Chat (workflow cards) ── */
.tc-cmds{display:flex;flex-direction:column;gap:8px;}
.tc-cmd{display:flex;align-items:flex-start;gap:12px;background:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid #e5e7eb;cursor:pointer;transition:all .2s;text-decoration:none;color:inherit;}
.tc-cmd:hover{border-color:#a7f3d0;box-shadow:0 3px 12px rgba(16,185,129,.1);transform:translateY(-1px);}
.tc-cmd:active{transform:scale(.98);}
.tc-cmd-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#ecfdf5,#d1fae5);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.tc-cmd-body{flex:1;min-width:0;}
.tc-cmd-label{font-size:13px;font-weight:600;color:#1f2937;}
.tc-cmd-desc{font-size:11px;color:#6b7280;margin-top:2px;line-height:1.3;}
.tc-cmd-tags{display:flex;gap:3px;margin-top:4px;flex-wrap:wrap;}
.tc-cmd-tag{font-size:9px;font-weight:500;padding:1px 6px;border-radius:5px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.tc-tips{display:flex;flex-direction:column;gap:5px;margin-top:10px;}
.tc-tip{display:flex;align-items:center;gap:8px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:8px 12px;cursor:pointer;transition:all .2s;font-size:12px;color:#065f46;}
.tc-tip:hover{background:#d1fae5;}

/* ── Settings ── */
.bztw-settings-info{padding:10px 14px;background:#ecfdf5;border-radius:8px;color:#065f46;font-size:13px;margin-bottom:12px;line-height:1.5;}
</style>
</head>
<body>

<div class="bztw-wrap">

    <?php if ( ! $user_id ) : ?>
    <div style="text-align:center;padding:40px 20px;">
        <div style="font-size:48px;margin-bottom:16px;">🔐</div>
        <h2 style="font-size:18px;margin-bottom:8px;">Đăng nhập để bắt đầu</h2>
        <p style="color:#6b7280;margin-bottom:16px;font-size:13px;">Đăng nhập để sử dụng bộ công cụ AI quản lý WooCommerce.</p>
        <a href="<?php echo esc_url( wp_login_url( home_url( '/tool-woo/' ) ) ); ?>" class="bztw-btn bztw-btn-primary" style="text-decoration:none;padding:12px 28px;">Đăng nhập</a>
    </div>
    <?php else : ?>

    <!-- ══ Tabs ══ -->
    <div class="bztw-tabs">
        <button class="bztw-tab active" data-tab="create">🛍️ Tạo SP</button>
        <button class="bztw-tab" data-tab="history">📋 Lịch sử</button>
        <button class="bztw-tab" data-tab="chat">💬 Quick Chat</button>
        <button class="bztw-tab" data-tab="settings">⚙️ Cài đặt</button>
    </div>

    <!-- ══ Tab 1: Create Product ══ -->
    <div class="bztw-panel active" id="panel-create">
        <div class="bztw-card">
            <h2>🛍️ Tạo sản phẩm bằng AI</h2>
            <p style="color:#6b7280;font-size:12px;margin-bottom:14px;">Mô tả sản phẩm → AI phân tích (tên, giá, mô tả, danh mục) → xem trước → đăng lên WooCommerce.</p>

            <div class="bztw-field">
                <label class="bztw-label">📝 Mô tả sản phẩm</label>
                <textarea class="bztw-input" id="bztw-topic" placeholder="Ví dụ: Áo thun cotton trắng nam nữ unisex, giá 150k, chất liệu mềm mại thoáng mát"></textarea>
            </div>

            <div class="bztw-field">
                <label class="bztw-label">🖼️ Hình ảnh sản phẩm (tùy chọn)</label>
                <div class="bztw-image-tabs">
                    <button type="button" class="bztw-image-tab active" data-img-tab="upload">📁 Tải file</button>
                    <button type="button" class="bztw-image-tab" data-img-tab="url">🔗 URL</button>
                </div>
                <div class="bztw-image-panel active" id="bztw-img-upload">
                    <div class="bztw-upload-area" id="bztw-upload-area">
                        <input type="file" id="bztw-file-input" accept="image/*" style="display:none;">
                        <div id="bztw-upload-placeholder">
                            <p style="margin:0;color:#65676b;font-size:12px;">📷 Kéo thả ảnh hoặc <strong style="color:#059669;">bấm chọn file</strong></p>
                            <p style="margin:2px 0 0;font-size:11px;color:#999;">JPG, PNG, GIF, WebP — max 10MB</p>
                        </div>
                        <div id="bztw-upload-preview" style="display:none;">
                            <img id="bztw-file-preview-img" class="bztw-file-preview" src="" alt="">
                            <p id="bztw-file-name" style="margin:2px 0 0;font-size:11px;color:#059669;"></p>
                            <button type="button" id="bztw-remove-file" class="bztw-btn bztw-btn-danger" style="margin-top:4px;padding:3px 10px;font-size:11px;">✕ Xóa</button>
                        </div>
                    </div>
                </div>
                <div class="bztw-image-panel" id="bztw-img-url">
                    <input class="bztw-input" id="bztw-image-url" type="url" placeholder="https://example.com/product-image.jpg">
                </div>
            </div>

            <button class="bztw-btn bztw-btn-primary" id="bztw-gen-preview" style="width:100%;">🤖 Gen AI — Xem trước</button>

            <div class="bztw-result" id="bztw-result"></div>

            <!-- Preview Box -->
            <div class="bztw-preview-box" id="bztw-preview-box">
                <div class="bztw-preview-header">👁️ Xem trước sản phẩm</div>
                <div class="bztw-preview-row">
                    <span class="bztw-preview-row-label">📦 Tên SP</span>
                    <span class="bztw-preview-row-value" id="bztw-preview-title"></span>
                </div>
                <div class="bztw-preview-row">
                    <span class="bztw-preview-row-label">💰 Giá</span>
                    <span class="bztw-preview-row-value" id="bztw-preview-price"></span>
                </div>
                <div class="bztw-preview-row">
                    <span class="bztw-preview-row-label">📂 Danh mục</span>
                    <span class="bztw-preview-row-value" id="bztw-preview-category"></span>
                </div>
                <div class="bztw-preview-desc" id="bztw-preview-desc"></div>
                <img class="bztw-preview-image" id="bztw-preview-image" src="" alt="" style="display:none;">
                <div class="bztw-preview-actions">
                    <button class="bztw-btn bztw-btn-success" id="bztw-confirm-product">✅ Tạo sản phẩm</button>
                    <button class="bztw-btn" id="bztw-regen" style="background:#f0f2f5;color:#333;">🔄 Tạo lại</button>
                    <button class="bztw-btn bztw-btn-danger" id="bztw-cancel-preview" style="padding:8px 14px;">✕ Hủy</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Tab 2: History ══ -->
    <div class="bztw-panel" id="panel-history">
        <div class="bztw-card">
            <h2>📋 Lịch sử tạo sản phẩm</h2>
            <p style="color:#6b7280;font-size:12px;margin-bottom:12px;">Xem lại các sản phẩm đã tạo. Bấm "Dùng lại" để chạy lại prompt cũ.</p>
            <div id="bztw-history-list"><p style="color:#999;font-size:13px;">Đang tải...</p></div>
        </div>
    </div>

    <!-- ══ Tab 3: Quick Chat ══ -->
    <div class="bztw-panel" id="panel-chat">
        <div class="bztw-card">
            <h2>💬 Quick Chat</h2>
            <p style="color:#6b7280;font-size:12px;margin-bottom:12px;">Chạm workflow → gửi lệnh trực tiếp vào chat AI.</p>
        </div>
        <div class="tc-cmds">
            <?php foreach ( $workflows as $cmd ) : ?>
            <div class="tc-cmd" data-msg="<?php echo esc_attr( $cmd['msg'] ); ?>" data-tool="<?php echo esc_attr( $cmd['tool'] ); ?>">
                <div class="tc-cmd-icon"><?php echo $cmd['icon']; ?></div>
                <div class="tc-cmd-body">
                    <div class="tc-cmd-label"><?php echo esc_html( $cmd['label'] ); ?></div>
                    <div class="tc-cmd-desc"><?php echo esc_html( $cmd['desc'] ); ?></div>
                    <?php if ( ! empty( $cmd['tags'] ) ) : ?>
                    <div class="tc-cmd-tags">
                        <?php foreach ( $cmd['tags'] as $tag ) : ?>
                        <span class="tc-cmd-tag"><?php echo esc_html( $tag ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="tc-tips" style="margin-top:12px;">
            <?php foreach ( $tips as $tip ) : ?>
            <div class="tc-tip" data-msg="<?php echo esc_attr( $tip['text'] ); ?>" data-tool="<?php echo esc_attr( $tip['tool'] ); ?>">
                <span style="font-size:14px;"><?php echo $tip['icon']; ?></span>
                <span style="flex:1;"><?php echo esc_html( $tip['text'] ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ══ Tab 4: Settings ══ -->
    <div class="bztw-panel" id="panel-settings">
        <div class="bztw-card">
            <h2>⚙️ Kết nối WooCommerce</h2>
            <?php if ( $is_admin ) : ?>
            <div class="bztw-settings-info">
                ✅ <strong>Bạn là Admin</strong> — sản phẩm sẽ tự động tạo trên WooCommerce hiện tại.<br>
                Nếu muốn tạo sản phẩm trên site khác, điền thông tin bên dưới.
            </div>
            <?php else : ?>
            <div class="bztw-settings-info">
                Cấu hình kết nối tới WooCommerce bên ngoài.<br>
                Cần <strong>Consumer Key & Consumer Secret</strong> (WooCommerce → Settings → REST API → "Add Key").
            </div>
            <?php endif; ?>

            <div class="bztw-field">
                <label class="bztw-label">🌐 Site URL</label>
                <input class="bztw-input" id="bztw-wp-url" type="url" value="<?php echo esc_attr( $wp_site_url ); ?>" placeholder="https://your-shop.com">
            </div>
            <div class="bztw-field">
                <label class="bztw-label">🔑 Consumer Key</label>
                <input class="bztw-input" id="bztw-wc-ck" type="text" value="<?php echo esc_attr( $wc_ck ); ?>" placeholder="ck_xxxxxxxxxxxxxxxxxxxxxxxx">
            </div>
            <div class="bztw-field">
                <label class="bztw-label">🔒 Consumer Secret</label>
                <input class="bztw-input" id="bztw-wc-cs" type="password" placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxx">
                <p style="margin-top:4px;font-size:11px;color:#9ca3af;">
                    Tạo tại: WooCommerce → Settings → Advanced → REST API → Add Key.<br>
                    Quyền: <strong>Read/Write</strong>. Giống Zapier / n8n — an toàn, không dùng mật khẩu chính.
                </p>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="bztw-btn bztw-btn-primary" id="bztw-save-wp">💾 Lưu & Test</button>
                <button class="bztw-btn" id="bztw-clear-wp" style="background:#f0f2f5;color:#333;">🗑️ Xóa (dùng local)</button>
            </div>
            <div class="bztw-result" id="bztw-wp-result"></div>
        </div>
    </div>

    <?php endif; /* end logged-in check */ ?>

    <div style="text-align:center;margin-top:16px;font-size:10px;color:#9ca3af;">
        Tool WooCommerce v<?php echo esc_html( BZTOOL_WOO_VERSION ); ?> · AI-powered
    </div>
</div>

<script>
(function() {
    'use strict';
    var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
    var nonce   = <?php echo wp_json_encode( $nonce ); ?>;

    /* ── Helper: fetch wrapper ── */
    function postAjax(action, data, callback) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', nonce);
        for (var k in data) { if (data.hasOwnProperty(k)) fd.append(k, data[k]); }
        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(callback)
            .catch(function() { callback({ success: false, data: 'Lỗi kết nối server.' }); });
    }

    /* ── Tab navigation ── */
    document.querySelectorAll('.bztw-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var t = this.getAttribute('data-tab');
            document.querySelectorAll('.bztw-tab').forEach(function(x) { x.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.bztw-panel').forEach(function(x) { x.classList.remove('active'); });
            var panel = document.getElementById('panel-' + t);
            if (panel) panel.classList.add('active');
            if (t === 'history') loadHistory();
        });
    });

    /* ── Image tab switching ── */
    document.querySelectorAll('.bztw-image-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var it = this.getAttribute('data-img-tab');
            document.querySelectorAll('.bztw-image-tab').forEach(function(x) { x.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.bztw-image-panel').forEach(function(x) { x.classList.remove('active'); });
            var p = document.getElementById('bztw-img-' + it);
            if (p) p.classList.add('active');
        });
    });

    /* ── File Upload ── */
    var uploadedFile = null, uploadedImageUrl = '';
    var uploadArea = document.getElementById('bztw-upload-area');
    var fileInput  = document.getElementById('bztw-file-input');

    if (uploadArea) {
        uploadArea.addEventListener('click', function(e) {
            if (e.target.id === 'bztw-remove-file' || e.target.closest('#bztw-remove-file')) return;
            fileInput.click();
        });
        uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = '#059669'; });
        uploadArea.addEventListener('dragleave', function(e) { e.preventDefault(); this.style.borderColor = uploadedFile ? '#059669' : '#ddd'; });
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            var f = e.dataTransfer.files;
            if (f.length && f[0].type.startsWith('image/')) handleFile(f[0]);
        });
    }
    if (fileInput) {
        fileInput.addEventListener('change', function() { if (this.files.length) handleFile(this.files[0]); });
    }

    function handleFile(file) {
        if (file.size > 10*1024*1024) { alert('File quá lớn (max 10MB)'); return; }
        uploadedFile = file;
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('bztw-file-preview-img').src = e.target.result;
            document.getElementById('bztw-file-name').textContent = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
            document.getElementById('bztw-upload-placeholder').style.display = 'none';
            document.getElementById('bztw-upload-preview').style.display = 'block';
            uploadArea.classList.add('has-file');
        };
        reader.readAsDataURL(file);
    }

    var removeBtn = document.getElementById('bztw-remove-file');
    if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            uploadedFile = null; uploadedImageUrl = '';
            fileInput.value = '';
            document.getElementById('bztw-upload-placeholder').style.display = 'block';
            document.getElementById('bztw-upload-preview').style.display = 'none';
            uploadArea.classList.remove('has-file');
        });
    }

    function uploadFileToServer(callback) {
        if (!uploadedFile) { callback(null); return; }
        var fd = new FormData();
        fd.append('action', 'bztw_upload_image');
        fd.append('nonce', nonce);
        fd.append('image', uploadedFile);
        fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                if (res.success) { uploadedImageUrl = res.data.url; callback(res.data.url); }
                else { callback(null, res.data || 'Lỗi upload'); }
            })
            .catch(function() { callback(null, 'Lỗi kết nối server'); });
    }

    /* ── Preview data ── */
    var previewData = null;

    /* ── Step 1: Generate Preview ── */
    var genBtn = document.getElementById('bztw-gen-preview');
    if (genBtn) genBtn.addEventListener('click', function() {
        var topic = document.getElementById('bztw-topic').value.trim();
        if (!topic) { alert('Nhập mô tả sản phẩm!'); return; }
        var resultEl = document.getElementById('bztw-result');

        genBtn.disabled = true; genBtn.textContent = '⏳ AI đang phân tích...';
        resultEl.className = 'bztw-result loading'; resultEl.textContent = 'Đang phân tích sản phẩm...'; resultEl.style.display = 'block';
        document.getElementById('bztw-preview-box').classList.remove('active');

        function doGen(imageUrl) {
            postAjax('bztw_generate_preview', { topic: topic, image_url: imageUrl || '' }, function(res) {
                genBtn.disabled = false; genBtn.textContent = '🤖 Gen AI — Xem trước';
                if (res.success && res.data) {
                    previewData = res.data;
                    previewData.topic = topic;
                    document.getElementById('bztw-preview-title').textContent = res.data.title || '';
                    document.getElementById('bztw-preview-price').textContent = res.data.price ? (Number(res.data.price).toLocaleString('vi-VN') + ' VNĐ') : 'Chưa có';
                    document.getElementById('bztw-preview-category').textContent = res.data.category || 'Chưa phân loại';
                    document.getElementById('bztw-preview-desc').innerHTML = res.data.description || '';
                    var img = document.getElementById('bztw-preview-image');
                    var imgSrc = imageUrl || res.data.image_url || '';
                    if (imgSrc) { img.src = imgSrc; img.style.display = 'block'; previewData.image_url = imgSrc; }
                    else { img.style.display = 'none'; }
                    document.getElementById('bztw-preview-box').classList.add('active');
                    resultEl.style.display = 'none';
                } else {
                    resultEl.className = 'bztw-result error';
                    resultEl.textContent = '❌ ' + (res.data || 'Lỗi phân tích sản phẩm');
                }
            });
        }

        var urlImage = (document.getElementById('bztw-image-url') || {}).value || '';
        if (uploadedFile && !uploadedImageUrl) {
            uploadFileToServer(function(url, err) {
                if (err) { genBtn.disabled = false; genBtn.textContent = '🤖 Gen AI — Xem trước'; resultEl.className = 'bztw-result error'; resultEl.textContent = '❌ Upload lỗi: ' + err; return; }
                doGen(url);
            });
        } else { doGen(uploadedImageUrl || urlImage); }
    });

    /* ── Step 2: Confirm & Publish Product ── */
    var confirmBtn = document.getElementById('bztw-confirm-product');
    if (confirmBtn) confirmBtn.addEventListener('click', function() {
        if (!previewData) return;
        confirmBtn.disabled = true; confirmBtn.textContent = '⏳ Đang tạo SP...';
        postAjax('bztw_publish_product', {
            title: previewData.title || '',
            description: previewData.description || '',
            price: previewData.price || '',
            category: previewData.category || '',
            image_url: previewData.image_url || '',
            topic: previewData.topic || ''
        }, function(res) {
            confirmBtn.disabled = false; confirmBtn.textContent = '✅ Tạo sản phẩm';
            var resultEl = document.getElementById('bztw-result');
            if (res.success) {
                document.getElementById('bztw-preview-box').classList.remove('active');
                var html = '<strong>✅ ' + escHtml(res.data.message || 'Tạo sản phẩm thành công!') + '</strong>';
                if (res.data && res.data.url) html += '<br>🔗 <a href="' + res.data.url + '" target="_blank" style="color:#155724;font-weight:600;">Xem sản phẩm</a>';
                if (res.data && res.data.edit_url) html += '<br>✏️ <a href="' + res.data.edit_url + '" target="_blank" style="color:#155724;">Sửa sản phẩm</a>';
                resultEl.className = 'bztw-result success'; resultEl.innerHTML = html; resultEl.style.display = 'block';
                document.getElementById('bztw-topic').value = '';
                previewData = null;
            } else {
                resultEl.className = 'bztw-result error'; resultEl.textContent = '❌ ' + (res.data || 'Lỗi tạo sản phẩm'); resultEl.style.display = 'block';
            }
        });
    });

    /* ── Regen / Cancel ── */
    var regenBtn = document.getElementById('bztw-regen');
    if (regenBtn) regenBtn.addEventListener('click', function() { genBtn.click(); });
    var cancelBtn = document.getElementById('bztw-cancel-preview');
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
        document.getElementById('bztw-preview-box').classList.remove('active');
        previewData = null;
    });

    /* ── History ── */
    function loadHistory() {
        var list = document.getElementById('bztw-history-list');
        if (!list) return;
        list.innerHTML = '<p style="color:#999;font-size:13px;">Đang tải...</p>';
        postAjax('bztw_poll_history', {}, function(res) {
            if (!res.success || !res.data || !res.data.items || !res.data.items.length) {
                list.innerHTML = '<p style="color:#999;font-size:13px;">Chưa có sản phẩm nào.</p>';
                return;
            }
            var html = '';
            res.data.items.forEach(function(item) {
                html += '<div class="bztw-history-item">'
                    + '<div class="bztw-history-prompt">'
                    + '<div class="bztw-history-title">' + escHtml(item.ai_title || item.prompt) + '</div>'
                    + '<div class="bztw-history-meta">' + escHtml(item.created_at) + ' · ' + escHtml(item.goal || 'create_product') + '</div>'
                    + '</div>'
                    + '<div class="bztw-history-actions">';
                if (item.product_url) html += '<a href="' + escHtml(item.product_url) + '" target="_blank" class="bztw-history-btn">🔗</a>';
                html += '<button class="bztw-history-btn bztw-rerun" data-prompt="' + escAttr(item.prompt) + '">🔄 Dùng lại</button>';
                html += '</div></div>';
            });
            list.innerHTML = html;

            list.querySelectorAll('.bztw-rerun').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var prompt = this.getAttribute('data-prompt');
                    document.getElementById('bztw-topic').value = prompt;
                    document.querySelector('.bztw-tab[data-tab="create"]').click();
                });
            });
        });
    }

    /* ── Quick Chat: postMessage to parent ── */
    function buildSlashMessage(msg, toolName) {
        var base = (msg || '').trim();
        var tool = (toolName || '').trim();
        if (!base || !tool) return base;
        if (base.indexOf('/') === 0) return base;
        return '/' + tool + ' ' + base;
    }

    document.querySelectorAll('[data-msg]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            e.preventDefault();
            var msg = this.getAttribute('data-msg');
            var toolName = this.getAttribute('data-tool') || '';
            if (!msg) return;
            var slashMsg = buildSlashMessage(msg, toolName);
            this.style.transform = 'scale(0.96)'; this.style.opacity = '0.7';
            var self = this;
            setTimeout(function() { self.style.transform = ''; self.style.opacity = ''; }, 200);
            if (window.parent && window.parent !== window) {
                window.parent.postMessage({
                    type: 'bizcity_agent_command',
                    source: 'bizcity-tool-woo',
                    plugin_slug: 'bizcity-tool-woo',
                    tool_name: toolName,
                    text: slashMsg || msg
                }, '*');
            }
        });
    });

    /* ── WooCommerce Settings ── */
    var saveWpBtn = document.getElementById('bztw-save-wp');
    if (saveWpBtn) saveWpBtn.addEventListener('click', function() {
        var url  = document.getElementById('bztw-wp-url').value.trim();
        var ck   = document.getElementById('bztw-wc-ck').value.trim();
        var cs   = document.getElementById('bztw-wc-cs').value.trim();
        var rEl  = document.getElementById('bztw-wp-result');
        saveWpBtn.disabled = true; saveWpBtn.textContent = '⏳ Testing...';
        postAjax('bztw_save_wp_settings', { site_url: url, consumer_key: ck, consumer_secret: cs }, function(res) {
            saveWpBtn.disabled = false; saveWpBtn.textContent = '💾 Lưu & Test';
            rEl.className = 'bztw-result ' + (res.success ? 'success' : 'error');
            rEl.textContent = (res.success ? '✅ ' : '❌ ') + (res.data && res.data.message ? res.data.message : res.data || 'Lỗi');
            rEl.style.display = 'block';
        });
    });

    var clearWpBtn = document.getElementById('bztw-clear-wp');
    if (clearWpBtn) clearWpBtn.addEventListener('click', function() {
        document.getElementById('bztw-wp-url').value = '';
        document.getElementById('bztw-wc-ck').value = '';
        document.getElementById('bztw-wc-cs').value = '';
        postAjax('bztw_save_wp_settings', { site_url: '' }, function(res) {
            var rEl = document.getElementById('bztw-wp-result');
            rEl.className = 'bztw-result success';
            rEl.textContent = '✅ Đã chuyển về WooCommerce nội bộ.';
            rEl.style.display = 'block';
        });
    });

    /* ── Helpers ── */
    function escHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function escAttr(s) { return (s || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
})();
</script>
</body>
</html>
