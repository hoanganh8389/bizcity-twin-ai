<?php
/**
 * BizCity Tool Content — Profile View (4-Tab SPA)
 *
 * Route: /tool-content/ (also embedded in Touch Bar iframe)
 *
 * 4-tab layout: ✍️ Tạo bài | 📋 Lịch sử | 💬 Quick Chat | ⚙️ Cài đặt
 *
 * @package BizCity_Tool_Content
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$user_id  = get_current_user_id();
$icon_url = BZTOOL_CONTENT_URL . 'assets/content.png';
$nonce    = wp_create_nonce( 'bztc_nonce' );
$ajax_url = admin_url( 'admin-ajax.php' );

// WP connection settings
$wp_site_url = $user_id ? get_user_meta( $user_id, 'bztc_wp_site_url', true ) : '';
$wp_username = $user_id ? get_user_meta( $user_id, 'bztc_wp_username', true ) : '';
$is_admin    = current_user_can( 'manage_options' );

/* ── Quick Chat workflows ── */
$workflows = [
    [ 'icon' => '✍️', 'label' => 'Viết bài blog', 'desc' => 'AI viết bài → tạo ảnh bìa → đăng lên WordPress', 'tool' => 'write_article', 'msg' => 'Viết bài về lợi ích của thiền định buổi sáng', 'tags' => [ 'AI viết', 'Ảnh bìa', 'Auto publish' ] ],
    [ 'icon' => '🔍', 'label' => 'Viết bài chuẩn SEO', 'desc' => 'AI viết bài với H2/H3, meta desc, focus keyword', 'tool' => 'write_seo_article', 'msg' => 'Viết bài chuẩn SEO về xu hướng thương mại điện tử 2026', 'tags' => [ 'SEO', 'Meta desc', 'H2/H3' ] ],
    [ 'icon' => '🔄', 'label' => 'Viết lại bài cũ', 'desc' => 'Tìm bài viết → AI viết lại nội dung mới', 'tool' => 'rewrite_article', 'msg' => 'Viết lại bài viết mới nhất', 'tags' => [ 'Rewrite', 'Cải thiện' ] ],
    [ 'icon' => '🌐', 'label' => 'Dịch & đăng bài', 'desc' => 'Dịch bài sang EN/JA/KO/ZH/TH → đăng bài mới', 'tool' => 'translate_and_publish', 'msg' => 'Dịch bài viết mới nhất sang tiếng Anh', 'tags' => [ 'Đa ngôn ngữ', 'Translate' ] ],
    [ 'icon' => '📅', 'label' => 'Lên lịch đăng bài', 'desc' => 'AI viết bài + hẹn giờ đăng', 'tool' => 'schedule_post', 'msg' => 'Lên lịch đăng bài vào ngày mai lúc 8h sáng', 'tags' => [ 'Schedule', 'Hẹn giờ' ] ],
];

$tips = [
    [ 'icon' => '💡', 'tool' => 'write_article', 'text' => 'Viết bài về yoga cho người mới bắt đầu' ],
    [ 'icon' => '💡', 'tool' => 'write_seo_article', 'text' => 'Viết bài SEO keyword "du lịch Đà Nẵng 2026"' ],
    [ 'icon' => '💡', 'tool' => 'rewrite_article', 'text' => 'Viết lại bài mới nhất giọng trẻ trung hơn' ],
    [ 'icon' => '💡', 'tool' => 'translate_and_publish', 'text' => 'Dịch bài về marketing sang tiếng Anh' ],
];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>BizCity Tool Content</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f8fafc;color:#1f2937;-webkit-font-smoothing:antialiased;overflow-x:hidden;}

/* ── Layout ── */
.bztc-wrap{max-width:100%;margin:0 auto;padding:12px 14px 32px;}

/* ── Tabs ── */
.bztc-tabs{display:flex;gap:0;border-bottom:2px solid #4f46e5;margin-bottom:16px;overflow-x:auto;}
.bztc-tab{padding:10px 14px;cursor:pointer;border:none;background:#f0f2f5;color:#65676b;font-weight:600;font-size:13px;border-radius:8px 8px 0 0;transition:all .2s;white-space:nowrap;}
.bztc-tab.active{background:#4f46e5;color:#fff;}
.bztc-panel{display:none;}
.bztc-panel.active{display:block;}

/* ── Card ── */
.bztc-card{background:#fff;border-radius:12px;padding:16px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.1);}
.bztc-card h2{font-size:16px;margin:0 0 8px;}

/* ── Form ── */
.bztc-field{margin-bottom:14px;}
.bztc-label{display:block;margin-bottom:4px;font-weight:600;color:#333;font-size:13px;}
.bztc-input{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;}
textarea.bztc-input{min-height:100px;resize:vertical;}
select.bztc-input{appearance:auto;}
.bztc-btn{display:inline-block;padding:10px 20px;border-radius:8px;border:none;cursor:pointer;font-weight:600;font-size:13px;transition:all .2s;}
.bztc-btn-primary{background:#4f46e5;color:#fff;}
.bztc-btn-primary:hover{background:#4338ca;}
.bztc-btn-success{background:#059669;color:#fff;}
.bztc-btn-danger{background:#dc3545;color:#fff;}

/* ── Image Upload ── */
.bztc-image-tabs{display:flex;gap:0;margin-bottom:6px;}
.bztc-image-tab{padding:5px 14px;cursor:pointer;border:1px solid #ddd;background:#f5f5f5;font-size:12px;font-weight:600;color:#65676b;}
.bztc-image-tab:first-child{border-radius:6px 0 0 6px;}
.bztc-image-tab:last-child{border-radius:0 6px 6px 0;}
.bztc-image-tab.active{background:#4f46e5;color:#fff;border-color:#4f46e5;}
.bztc-image-panel{display:none;}
.bztc-image-panel.active{display:block;}
.bztc-upload-area{border:2px dashed #ddd;border-radius:8px;padding:14px;text-align:center;cursor:pointer;transition:all .2s;}
.bztc-upload-area:hover{border-color:#4f46e5;background:#f0f5ff;}
.bztc-upload-area.has-file{border-color:#059669;background:#f0fff0;}
.bztc-file-preview{max-height:100px;border-radius:6px;margin-top:6px;}

/* ── Preview Box ── */
.bztc-preview-box{background:#fff;border:2px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-top:12px;display:none;}
.bztc-preview-box.active{display:block;}
.bztc-preview-header{padding:10px 14px;background:#eef2ff;display:flex;align-items:center;gap:6px;font-weight:600;color:#4f46e5;border-bottom:1px solid #e5e7eb;font-size:14px;}
.bztc-preview-title{padding:10px 14px;font-weight:600;font-size:15px;border-bottom:1px solid #f3f4f6;}
.bztc-preview-content{padding:14px;white-space:pre-wrap;line-height:1.6;font-size:13px;max-height:300px;overflow-y:auto;}
.bztc-preview-image{width:100%;max-height:200px;object-fit:cover;border-top:1px solid #e5e7eb;}
.bztc-preview-actions{padding:10px 14px;background:#f8fafc;border-top:1px solid #e5e7eb;display:flex;gap:6px;flex-wrap:wrap;}

/* ── Result ── */
.bztc-result{margin-top:12px;padding:12px;border-radius:8px;display:none;font-size:13px;}
.bztc-result.success{display:block;background:#d4edda;color:#155724;}
.bztc-result.error{display:block;background:#f8d7da;color:#721c24;}
.bztc-result.loading{display:block;background:#fff3cd;color:#856404;}

/* ── History List ── */
.bztc-history-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #f3f4f6;align-items:flex-start;}
.bztc-history-item:last-child{border-bottom:none;}
.bztc-history-prompt{flex:1;min-width:0;font-size:13px;line-height:1.4;}
.bztc-history-title{font-weight:600;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bztc-history-meta{font-size:11px;color:#9ca3af;margin-top:2px;}
.bztc-history-actions{flex-shrink:0;display:flex;gap:4px;}
.bztc-history-btn{padding:4px 10px;font-size:11px;border-radius:6px;border:1px solid #e5e7eb;background:#fff;cursor:pointer;font-weight:600;}
.bztc-history-btn:hover{background:#f3f4f6;}

/* ── Quick Chat (existing workflow cards) ── */
.tc-cmds{display:flex;flex-direction:column;gap:8px;}
.tc-cmd{display:flex;align-items:flex-start;gap:12px;background:#fff;border-radius:12px;padding:12px 14px;box-shadow:0 1px 4px rgba(0,0,0,.05);border:1px solid #e5e7eb;cursor:pointer;transition:all .2s;text-decoration:none;color:inherit;}
.tc-cmd:hover{border-color:#c7d2fe;box-shadow:0 3px 12px rgba(99,102,241,.1);transform:translateY(-1px);}
.tc-cmd:active{transform:scale(.98);}
.tc-cmd-icon{width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#eef2ff,#e0e7ff);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.tc-cmd-body{flex:1;min-width:0;}
.tc-cmd-label{font-size:13px;font-weight:600;color:#1f2937;}
.tc-cmd-desc{font-size:11px;color:#6b7280;margin-top:2px;line-height:1.3;}
.tc-cmd-tags{display:flex;gap:3px;margin-top:4px;flex-wrap:wrap;}
.tc-cmd-tag{font-size:9px;font-weight:500;padding:1px 6px;border-radius:5px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;}
.tc-tips{display:flex;flex-direction:column;gap:5px;margin-top:10px;}
.tc-tip{display:flex;align-items:center;gap:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;cursor:pointer;transition:all .2s;font-size:12px;color:#92400e;}
.tc-tip:hover{background:#fef3c7;}

/* ── Settings ── */
.bztc-settings-info{padding:10px 14px;background:#e0f2fe;border-radius:8px;color:#0369a1;font-size:13px;margin-bottom:12px;line-height:1.5;}
</style>
</head>
<body>

<div class="bztc-wrap">

    <?php if ( ! $user_id ) : ?>
    <div style="text-align:center;padding:40px 20px;">
        <div style="font-size:48px;margin-bottom:16px;">🔐</div>
        <h2 style="font-size:18px;margin-bottom:8px;">Đăng nhập để bắt đầu</h2>
        <p style="color:#6b7280;margin-bottom:16px;font-size:13px;">Đăng nhập để sử dụng bộ công cụ AI tạo nội dung.</p>
        <a href="<?php echo esc_url( wp_login_url( home_url( '/tool-content/' ) ) ); ?>" class="bztc-btn bztc-btn-primary" style="text-decoration:none;padding:12px 28px;">Đăng nhập</a>
    </div>
    <?php else : ?>

    <!-- ══ Tabs ══ -->
    <div class="bztc-tabs">
        <button class="bztc-tab active" data-tab="create">✍️ Tạo bài</button>
        <button class="bztc-tab" data-tab="history">📋 Lịch sử</button>
        <button class="bztc-tab" data-tab="chat">💬 Quick Chat</button>
        <button class="bztc-tab" data-tab="settings">⚙️ Cài đặt</button>
    </div>

    <!-- ══ Tab 1: Create Post ══ -->
    <div class="bztc-panel active" id="panel-create">
        <div class="bztc-card">
            <h2>✍️ Tạo bài viết bằng AI</h2>
            <p style="color:#6b7280;font-size:12px;margin-bottom:14px;">Nhập chủ đề → AI sinh nội dung → xem trước → sửa → đăng.</p>

            <div class="bztc-field">
                <label class="bztc-label">📝 Chủ đề bài viết</label>
                <textarea class="bztc-input" id="bztc-topic" placeholder="Ví dụ: 10 xu hướng marketing 2026 doanh nghiệp nhỏ cần biết"></textarea>
            </div>

            <div class="bztc-field">
                <label class="bztc-label">🎨 Tone giọng văn</label>
                <select class="bztc-input" id="bztc-tone">
                    <option value="friendly">😊 Thân thiện, gần gũi</option>
                    <option value="professional">💼 Chuyên nghiệp</option>
                    <option value="casual">🎉 Thoải mái, vui vẻ</option>
                    <option value="formal">📋 Trang trọng</option>
                </select>
            </div>

            <div class="bztc-field">
                <label class="bztc-label">🖼️ Hình ảnh (tùy chọn)</label>
                <div class="bztc-image-tabs">
                    <button type="button" class="bztc-image-tab active" data-img-tab="upload">📁 Tải file</button>
                    <button type="button" class="bztc-image-tab" data-img-tab="url">🔗 URL</button>
                </div>
                <div class="bztc-image-panel active" id="bztc-img-upload">
                    <div class="bztc-upload-area" id="bztc-upload-area">
                        <input type="file" id="bztc-file-input" accept="image/*" style="display:none;">
                        <div id="bztc-upload-placeholder">
                            <p style="margin:0;color:#65676b;font-size:12px;">📷 Kéo thả ảnh hoặc <strong style="color:#4f46e5;">bấm chọn file</strong></p>
                            <p style="margin:2px 0 0;font-size:11px;color:#999;">JPG, PNG, GIF, WebP — max 10MB</p>
                        </div>
                        <div id="bztc-upload-preview" style="display:none;">
                            <img id="bztc-file-preview-img" class="bztc-file-preview" src="" alt="">
                            <p id="bztc-file-name" style="margin:2px 0 0;font-size:11px;color:#059669;"></p>
                            <button type="button" id="bztc-remove-file" class="bztc-btn bztc-btn-danger" style="margin-top:4px;padding:3px 10px;font-size:11px;">✕ Xóa</button>
                        </div>
                    </div>
                </div>
                <div class="bztc-image-panel" id="bztc-img-url">
                    <input class="bztc-input" id="bztc-image-url" type="url" placeholder="https://example.com/image.jpg">
                </div>
            </div>

            <button class="bztc-btn bztc-btn-primary" id="bztc-gen-preview" style="width:100%;">🤖 Gen AI — Xem trước</button>

            <div class="bztc-result" id="bztc-result"></div>

            <!-- Preview Box -->
            <div class="bztc-preview-box" id="bztc-preview-box">
                <div class="bztc-preview-header">👁️ Xem trước bài viết</div>
                <div class="bztc-preview-title" id="bztc-preview-title"></div>
                <div class="bztc-preview-content" id="bztc-preview-content"></div>
                <img class="bztc-preview-image" id="bztc-preview-image" src="" alt="" style="display:none;">
                <div class="bztc-preview-actions">
                    <button class="bztc-btn bztc-btn-success" id="bztc-confirm-post">✅ Đăng bài</button>
                    <button class="bztc-btn" id="bztc-regen" style="background:#f0f2f5;color:#333;">🔄 Tạo lại</button>
                    <button class="bztc-btn bztc-btn-danger" id="bztc-cancel-preview" style="padding:8px 14px;">✕ Hủy</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ Tab 2: History ══ -->
    <div class="bztc-panel" id="panel-history">
        <div class="bztc-card">
            <h2>📋 Lịch sử prompt</h2>
            <p style="color:#6b7280;font-size:12px;margin-bottom:12px;">Xem lại các bài đã tạo. Bấm "Dùng lại" để chạy prompt cũ.</p>
            <div id="bztc-history-list"><p style="color:#999;font-size:13px;">Đang tải...</p></div>
        </div>
    </div>

    <!-- ══ Tab 3: Quick Chat ══ -->
    <div class="bztc-panel" id="panel-chat">
        <div class="bztc-card">
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
    <div class="bztc-panel" id="panel-settings">
        <div class="bztc-card">
            <h2>⚙️ Kết nối WordPress</h2>
            <?php if ( $is_admin ) : ?>
            <div class="bztc-settings-info">
                ✅ <strong>Bạn là Admin</strong> — bài viết sẽ tự động đăng trên WordPress hiện tại.<br>
                Nếu muốn đăng bài lên site khác, điền thông tin bên dưới.
            </div>
            <?php else : ?>
            <div class="bztc-settings-info">
                Cấu hình kết nối tới WordPress bên ngoài để đăng bài.<br>
                Cần <strong>Application Password</strong> (WordPress → Users → Application Passwords).
            </div>
            <?php endif; ?>

            <div class="bztc-field">
                <label class="bztc-label">🌐 Site URL</label>
                <input class="bztc-input" id="bztc-wp-url" type="url" value="<?php echo esc_attr( $wp_site_url ); ?>" placeholder="https://your-website.com">
            </div>
            <div class="bztc-field">
                <label class="bztc-label">👤 Username</label>
                <input class="bztc-input" id="bztc-wp-user" type="text" value="<?php echo esc_attr( $wp_username ); ?>" placeholder="admin">
            </div>
            <div class="bztc-field">
                <label class="bztc-label">🔑 Application Password</label>
                <input class="bztc-input" id="bztc-wp-pass" type="password" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx">
                <p style="margin-top:4px;font-size:11px;color:#9ca3af;">
                    Tạo tại: WordPress Admin → Users → Application Passwords.<br>
                    Giống n8n / Zapier — an toàn, không dùng mật khẩu chính.
                </p>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="bztc-btn bztc-btn-primary" id="bztc-save-wp">💾 Lưu & Test</button>
                <button class="bztc-btn" id="bztc-clear-wp" style="background:#f0f2f5;color:#333;">🗑️ Xóa (dùng local)</button>
            </div>
            <div class="bztc-result" id="bztc-wp-result"></div>
        </div>
    </div>

    <?php endif; /* end logged-in check */ ?>

    <div style="text-align:center;margin-top:16px;font-size:10px;color:#9ca3af;">
        Tool Content v<?php echo esc_html( BZTOOL_CONTENT_VERSION ); ?> · AI-powered
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
    document.querySelectorAll('.bztc-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var t = this.getAttribute('data-tab');
            document.querySelectorAll('.bztc-tab').forEach(function(x) { x.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.bztc-panel').forEach(function(x) { x.classList.remove('active'); });
            var panel = document.getElementById('panel-' + t);
            if (panel) panel.classList.add('active');
            if (t === 'history') loadHistory();
        });
    });

    /* ── Image tab switching ── */
    document.querySelectorAll('.bztc-image-tab').forEach(function(tab) {
        tab.addEventListener('click', function() {
            var it = this.getAttribute('data-img-tab');
            document.querySelectorAll('.bztc-image-tab').forEach(function(x) { x.classList.remove('active'); });
            this.classList.add('active');
            document.querySelectorAll('.bztc-image-panel').forEach(function(x) { x.classList.remove('active'); });
            var p = document.getElementById('bztc-img-' + it);
            if (p) p.classList.add('active');
        });
    });

    /* ── File Upload ── */
    var uploadedFile = null, uploadedImageUrl = '';
    var uploadArea = document.getElementById('bztc-upload-area');
    var fileInput  = document.getElementById('bztc-file-input');

    if (uploadArea) {
        uploadArea.addEventListener('click', function(e) {
            if (e.target.id === 'bztc-remove-file' || e.target.closest('#bztc-remove-file')) return;
            fileInput.click();
        });
        uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = '#4f46e5'; });
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
            document.getElementById('bztc-file-preview-img').src = e.target.result;
            document.getElementById('bztc-file-name').textContent = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
            document.getElementById('bztc-upload-placeholder').style.display = 'none';
            document.getElementById('bztc-upload-preview').style.display = 'block';
            uploadArea.classList.add('has-file');
        };
        reader.readAsDataURL(file);
    }

    var removeBtn = document.getElementById('bztc-remove-file');
    if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            uploadedFile = null; uploadedImageUrl = '';
            fileInput.value = '';
            document.getElementById('bztc-upload-placeholder').style.display = 'block';
            document.getElementById('bztc-upload-preview').style.display = 'none';
            uploadArea.classList.remove('has-file');
        });
    }

    function uploadFileToServer(callback) {
        if (!uploadedFile) { callback(null); return; }
        var fd = new FormData();
        fd.append('action', 'bztc_upload_image');
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
    var genBtn = document.getElementById('bztc-gen-preview');
    if (genBtn) genBtn.addEventListener('click', function() {
        var topic = document.getElementById('bztc-topic').value.trim();
        if (!topic) { alert('Nhập chủ đề!'); return; }
        var tone = document.getElementById('bztc-tone').value;
        var resultEl = document.getElementById('bztc-result');

        genBtn.disabled = true; genBtn.textContent = '⏳ AI đang viết...';
        resultEl.className = 'bztc-result loading'; resultEl.textContent = 'Đang tạo nội dung AI...'; resultEl.style.display = 'block';
        document.getElementById('bztc-preview-box').classList.remove('active');

        function doGen(imageUrl) {
            postAjax('bztc_generate_preview', { topic: topic, tone: tone, image_url: imageUrl || '' }, function(res) {
                genBtn.disabled = false; genBtn.textContent = '🤖 Gen AI — Xem trước';
                if (res.success && res.data) {
                    previewData = res.data;
                    previewData.topic = topic;
                    document.getElementById('bztc-preview-title').textContent = res.data.title || '';
                    document.getElementById('bztc-preview-content').innerHTML = res.data.content || '';
                    var img = document.getElementById('bztc-preview-image');
                    var imgSrc = imageUrl || res.data.image_url || '';
                    if (imgSrc) { img.src = imgSrc; img.style.display = 'block'; previewData.image_url = imgSrc; }
                    else { img.style.display = 'none'; }
                    document.getElementById('bztc-preview-box').classList.add('active');
                    resultEl.style.display = 'none';
                } else {
                    resultEl.className = 'bztc-result error';
                    resultEl.textContent = '❌ ' + (res.data || 'Lỗi tạo nội dung');
                }
            });
        }

        var urlImage = (document.getElementById('bztc-image-url') || {}).value || '';
        if (uploadedFile && !uploadedImageUrl) {
            uploadFileToServer(function(url, err) {
                if (err) { genBtn.disabled = false; genBtn.textContent = '🤖 Gen AI — Xem trước'; resultEl.className = 'bztc-result error'; resultEl.textContent = '❌ Upload lỗi: ' + err; return; }
                doGen(url);
            });
        } else { doGen(uploadedImageUrl || urlImage); }
    });

    /* ── Step 2: Confirm & Publish ── */
    var confirmBtn = document.getElementById('bztc-confirm-post');
    if (confirmBtn) confirmBtn.addEventListener('click', function() {
        if (!previewData) return;
        confirmBtn.disabled = true; confirmBtn.textContent = '⏳ Đang đăng...';
        postAjax('bztc_publish_post', {
            title: previewData.title || '',
            content: previewData.content || '',
            image_url: previewData.image_url || '',
            topic: previewData.topic || ''
        }, function(res) {
            confirmBtn.disabled = false; confirmBtn.textContent = '✅ Đăng bài';
            var resultEl = document.getElementById('bztc-result');
            if (res.success) {
                document.getElementById('bztc-preview-box').classList.remove('active');
                var html = '<strong>✅ Đăng bài thành công!</strong>';
                if (res.data && res.data.url) html += '<br>🔗 <a href="' + res.data.url + '" target="_blank" style="color:#155724;font-weight:600;">Xem bài viết</a>';
                if (res.data && res.data.edit_url) html += '<br>✏️ <a href="' + res.data.edit_url + '" target="_blank" style="color:#155724;">Sửa bài</a>';
                resultEl.className = 'bztc-result success'; resultEl.innerHTML = html; resultEl.style.display = 'block';
                document.getElementById('bztc-topic').value = '';
                previewData = null;
            } else {
                resultEl.className = 'bztc-result error'; resultEl.textContent = '❌ ' + (res.data || 'Lỗi đăng bài'); resultEl.style.display = 'block';
            }
        });
    });

    /* ── Regen / Cancel ── */
    var regenBtn = document.getElementById('bztc-regen');
    if (regenBtn) regenBtn.addEventListener('click', function() { genBtn.click(); });
    var cancelBtn = document.getElementById('bztc-cancel-preview');
    if (cancelBtn) cancelBtn.addEventListener('click', function() {
        document.getElementById('bztc-preview-box').classList.remove('active');
        previewData = null;
    });

    /* ── History ── */
    function loadHistory() {
        var list = document.getElementById('bztc-history-list');
        if (!list) return;
        list.innerHTML = '<p style="color:#999;font-size:13px;">Đang tải...</p>';
        postAjax('bztc_poll_history', {}, function(res) {
            if (!res.success || !res.data || !res.data.items || !res.data.items.length) {
                list.innerHTML = '<p style="color:#999;font-size:13px;">Chưa có prompt nào.</p>';
                return;
            }
            var html = '';
            res.data.items.forEach(function(item) {
                html += '<div class="bztc-history-item">'
                    + '<div class="bztc-history-prompt">'
                    + '<div class="bztc-history-title">' + escHtml(item.ai_title || item.prompt) + '</div>'
                    + '<div class="bztc-history-meta">' + escHtml(item.created_at) + ' · ' + escHtml(item.goal || 'write_article') + '</div>'
                    + '</div>'
                    + '<div class="bztc-history-actions">';
                if (item.post_url) html += '<a href="' + escHtml(item.post_url) + '" target="_blank" class="bztc-history-btn">🔗</a>';
                html += '<button class="bztc-history-btn bztc-rerun" data-prompt="' + escAttr(item.prompt) + '">🔄 Dùng lại</button>';
                html += '</div></div>';
            });
            list.innerHTML = html;

            list.querySelectorAll('.bztc-rerun').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var prompt = this.getAttribute('data-prompt');
                    document.getElementById('bztc-topic').value = prompt;
                    document.querySelector('.bztc-tab[data-tab="create"]').click();
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
                    source: 'bizcity-tool-content',
                    plugin_slug: 'bizcity-tool-content',
                    tool_name: toolName,
                    text: slashMsg || msg
                }, '*');
            }
        });
    });

    /* ── WP Settings ── */
    var saveWpBtn = document.getElementById('bztc-save-wp');
    if (saveWpBtn) saveWpBtn.addEventListener('click', function() {
        var url   = document.getElementById('bztc-wp-url').value.trim();
        var user  = document.getElementById('bztc-wp-user').value.trim();
        var pass  = document.getElementById('bztc-wp-pass').value.trim();
        var rEl   = document.getElementById('bztc-wp-result');
        saveWpBtn.disabled = true; saveWpBtn.textContent = '⏳ Testing...';
        postAjax('bztc_save_wp_settings', { site_url: url, username: user, app_password: pass }, function(res) {
            saveWpBtn.disabled = false; saveWpBtn.textContent = '💾 Lưu & Test';
            rEl.className = 'bztc-result ' + (res.success ? 'success' : 'error');
            rEl.textContent = (res.success ? '✅ ' : '❌ ') + (res.data && res.data.message ? res.data.message : res.data || 'Lỗi');
            rEl.style.display = 'block';
        });
    });

    var clearWpBtn = document.getElementById('bztc-clear-wp');
    if (clearWpBtn) clearWpBtn.addEventListener('click', function() {
        document.getElementById('bztc-wp-url').value = '';
        document.getElementById('bztc-wp-user').value = '';
        document.getElementById('bztc-wp-pass').value = '';
        postAjax('bztc_save_wp_settings', { site_url: '' }, function(res) {
            var rEl = document.getElementById('bztc-wp-result');
            rEl.className = 'bztc-result success';
            rEl.textContent = '✅ Đã chuyển về WordPress nội bộ.';
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
