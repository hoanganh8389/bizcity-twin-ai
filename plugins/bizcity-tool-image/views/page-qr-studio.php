<?php
/**
 * QR Studio — Full-page view
 *
 * Two-panel layout:
 *   Left  — QR image input (URL param ?qr= or file upload)
 *   Right — Template gallery from bizcity.vn via proxy REST
 *
 * @package BizCity_Tool_Image
 * @since   4.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* ── Data ── */
$nonce       = wp_create_nonce( 'bztimg_nonce' );
$rest_nonce  = wp_create_nonce( 'wp_rest' );
$ajax_url    = admin_url( 'admin-ajax.php' );
$rest_root   = rest_url( 'bizcity-channel/v1' );
$user        = wp_get_current_user();
wp_enqueue_media(); /* WP Media uploader — needed for in-page media picker */

/* Validate qr param — must be http/https only */
$qr_from_url = '';
if ( isset( $_GET['qr'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $raw = esc_url_raw( wp_unslash( $_GET['qr'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( $raw && preg_match( '#^https?://#i', $raw ) ) {
        $qr_from_url = $raw;
    }
}

/* Validate img param — any http/https image URL to pre-load into the image slot */
$img_from_url = '';
if ( isset( $_GET['img'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $raw_img = esc_url_raw( wp_unslash( $_GET['img'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( $raw_img && preg_match( '#^https?://#i', $raw_img ) ) {
        $img_from_url = $raw_img;
    }
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>GPT Images Studio — <?php bloginfo( 'name' ); ?></title>
<?php wp_head(); ?>
<style>
/* ── Hide WP admin bar / Query Monitor / floating chat buttons ── */
html { margin-top: 0 !important; }
#wpadminbar,
#query-monitor,
#query-monitor-main,
.qm-show,
#bizchat-float-btn,
.bizchat-float-btn,
.bizchat-float,
.bizgpt-float-btn,
.twinchat-float-btn,
.tawk-min-container,
#fb-root,
.fb-customerchat,
.facebook-chat,
.elementor-location-popup,
body > .uk-iconnav,
body.admin-bar { margin-top: 0 !important; padding-top: 0 !important; }
#wpadminbar { display: none !important; }
#query-monitor, .qm, [id^="qm-"] { display: none !important; }
#bizchat-float-btn, .bizchat-float-btn, .bizchat-float, .bizgpt-float-btn,
.twinchat-float-btn, .tawk-min-container, .fb-customerchat { display: none !important; }

/* ── Reset ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; color: #1a1a2e; overflow: hidden; }

/* ── Top bar ── */
.qrs-topbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    height: 56px; background: #fff; border-bottom: 1px solid #e8eaf0;
    display: flex; align-items: center; padding: 0 20px; gap: 12px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
}
.qrs-logo { font-weight: 700; font-size: 16px; color: #6b46c1; display: flex; align-items: center; gap: 7px; }
.qrs-logo span { font-size: 22px; }
.qrs-topbar-spacer { flex: 1; }
.qrs-topbar-user { font-size: 13px; color: #666; display: flex; align-items: center; gap: 6px; }

/* ── Main 2-panel layout ── */
.qrs-layout {
    display: grid;
    grid-template-columns: 27% 1fr;
    height: 100vh;
    padding-top: 56px;
}

/* ═══════ LEFT PANEL ═══════ */
.qrs-left {
    background: #fff;
    border-right: 1px solid #e8eaf0;
    overflow-y: auto;
    padding: 20px 18px;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.qrs-section-label {
    font-size: 11px; font-weight: 700; color: #888;
    text-transform: uppercase; letter-spacing: .6px; margin-bottom: 6px;
    display: flex; align-items: center; gap: 6px;
}
.qrs-section-label-num {
    width: 20px; height: 20px; background: #6b46c1; color: #fff;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
}

/* Upload zone */
.qrs-upload-zone {
    border: 2px dashed #d0d4e0; border-radius: 12px;
    padding: 28px 16px; text-align: center; cursor: pointer;
    background: #fafbff; transition: all .2s; position: relative;
}
.qrs-upload-zone:hover, .qrs-upload-zone.drag-over {
    border-color: #6b46c1; background: #f3f0ff;
}
.qrs-upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.qrs-upload-icon { font-size: 36px; line-height: 1; margin-bottom: 8px; }
.qrs-upload-label { font-size: 14px; font-weight: 600; color: #333; }
.qrs-upload-hint { font-size: 12px; color: #aaa; margin-top: 4px; }

/* QR preview */
.qrs-qr-preview {
    display: none; border-radius: 12px; border: 1px solid #e0d4ff;
    overflow: hidden; background: #faf8ff; aspect-ratio: 1;
    align-items: center; justify-content: center; position: relative;
}
.qrs-qr-preview.visible { display: flex; }
.qrs-qr-preview img { width: 100%; height: 100%; object-fit: contain; display: block; }
.qrs-qr-remove {
    position: absolute; top: 8px; right: 8px;
    background: rgba(0,0,0,.45); color: #fff; border: none;
    border-radius: 50%; width: 28px; height: 28px; cursor: pointer;
    font-size: 13px; display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.qrs-qr-remove:hover { background: rgba(220,38,38,.8); }

/* URL input row */
.qrs-url-row { display: flex; gap: 7px; margin-top: 8px; }
.qrs-url-input {
    flex: 1; border: 1px solid #ddd; border-radius: 8px;
    padding: 8px 11px; font-size: 13px; outline: none; transition: border-color .2s;
    min-width: 0;
}
.qrs-url-input:focus { border-color: #6b46c1; }
.qrs-url-btn {
    padding: 8px 14px; border-radius: 8px; border: none;
    background: #6b46c1; color: #fff; font-size: 13px; cursor: pointer;
    white-space: nowrap; transition: background .2s;
}
.qrs-url-btn:hover { background: #5a38a8; }
.qrs-qrgen-btn {
    padding: 8px 12px; border-radius: 8px; border: none;
    background: #059669; color: #fff; font-size: 13px; cursor: pointer;
    white-space: nowrap; transition: background .2s;
}
.qrs-qrgen-btn:hover { background: #047857; }

/* Status messages */
.qrs-status {
    font-size: 12px; padding: 8px 12px; border-radius: 8px; margin-top: 6px;
    display: none;
}
.qrs-status.visible { display: block; }
.qrs-status.info    { background: #eff6ff; color: #1d4ed8; }
.qrs-status.error   { background: #fef2f2; color: #dc2626; }
.qrs-status.loading { background: #f9fafb; color: #666; }
.qrs-status.success { background: #f0fdf4; color: #16a34a; }

/* Selected template info */
.qrs-sel-tpl {
    display: none; background: #f5f0ff; border-radius: 10px;
    padding: 10px 12px; border: 1px solid #e0d4ff;
    align-items: center; gap: 10px;
}
.qrs-sel-tpl.visible { display: flex; }
.qrs-sel-thumb { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; background: #e8e0ff; flex-shrink: 0; }
.qrs-sel-info { flex: 1; min-width: 0; }
.qrs-sel-name { font-size: 13px; font-weight: 600; color: #6b46c1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.qrs-sel-size { font-size: 11px; color: #999; margin-top: 2px; }
.qrs-sel-clear { background: none; border: none; cursor: pointer; color: #bbb; font-size: 16px; padding: 0 4px; }
.qrs-sel-clear:hover { color: #dc2626; }

/* Generate button */
.qrs-generate-btn {
    width: 100%; padding: 13px; border-radius: 12px; border: none;
    background: linear-gradient(135deg, #6b46c1, #a855f7);
    color: #fff; font-size: 15px; font-weight: 600; cursor: pointer;
    transition: opacity .2s; display: flex; align-items: center;
    justify-content: center; gap: 8px;
}
.qrs-generate-btn:disabled { opacity: .45; cursor: not-allowed; }
.qrs-generate-btn:hover:not(:disabled) { opacity: .88; }

/* Result area */
.qrs-result { display: none; }
.qrs-result.visible { display: block; }
.qrs-result img { width: 100%; border-radius: 12px; border: 1px solid #e8eaf0; margin-bottom: 10px; }
.qrs-result-actions { display: flex; gap: 8px; }
.qrs-result-actions a,
.qrs-result-actions button {
    flex: 1; padding: 9px; border-radius: 8px; text-align: center;
    font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer;
}
.qrs-btn-dl  { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.qrs-btn-rst { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

/* ═══════ RIGHT PANEL ═══════ */
.qrs-right {
    overflow-y: auto; padding: 20px 18px;
    display: flex; flex-direction: column; gap: 14px;
}
.qrs-right-heading { font-size: 18px; font-weight: 700; color: #1a1a2e; }
.qrs-right-sub { font-size: 13px; color: #888; margin-top: 3px; }

/* Degraded banner */
.qrs-degraded {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px;
    padding: 9px 13px; font-size: 12px; color: #92400e;
    display: none; gap: 7px; align-items: center;
}
.qrs-degraded.visible { display: flex; }

/* Toolbar */
.qrs-toolbar {
    background: #fff; border-radius: 12px; padding: 10px 14px;
    border: 1px solid #e8eaf0; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;
}
.qrs-search {
    flex: 1; min-width: 140px; padding: 7px 11px;
    border: 1px solid #ddd; border-radius: 8px; font-size: 13px; outline: none;
    transition: border-color .2s;
}
.qrs-search:focus { border-color: #6b46c1; }
.qrs-cats { display: flex; gap: 6px; flex-wrap: wrap; }
.qrs-cat-btn {
    padding: 5px 13px; border-radius: 20px; font-size: 12px; font-weight: 500;
    border: 1px solid #e0e0e0; background: #f8f8f8; cursor: pointer;
    transition: all .15s; white-space: nowrap;
}
.qrs-cat-btn.active  { background: #6b46c1; color: #fff; border-color: #6b46c1; }
.qrs-cat-btn:hover:not(.active) { background: #f0ebff; border-color: #9c7ae0; color: #6b46c1; }

/* Template grid */
.qrs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
    gap: 12px;
}
.qrs-card {
    background: #fff; border: 2px solid transparent; border-radius: 12px;
    overflow: hidden; cursor: pointer; transition: all .18s; position: relative;
}
.qrs-card:hover  { border-color: #6b46c1; transform: translateY(-2px); box-shadow: 0 4px 16px rgba(107,70,193,.15); }
.qrs-card.active { border-color: #6b46c1; box-shadow: 0 0 0 3px rgba(107,70,193,.18); }
.qrs-card-thumb { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; background: #f0f2f5; }
.qrs-card-placeholder {
    width: 100%; aspect-ratio: 1;
    background: linear-gradient(135deg, #e8eaf0, #d0d4e8);
    display: flex; align-items: center; justify-content: center; font-size: 34px;
}
.qrs-card-body { padding: 7px 9px 9px; }
.qrs-card-name { font-size: 12px; font-weight: 600; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.qrs-card-meta { font-size: 11px; color: #aaa; margin-top: 2px; }
.qrs-card-check {
    position: absolute; top: 6px; right: 6px;
    width: 22px; height: 22px; background: #6b46c1; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 12px; opacity: 0; transition: opacity .15s;
}
.qrs-card.active .qrs-card-check { opacity: 1; }

/* Skeleton */
.qrs-skeleton { background: #f0f2f5; border-radius: 12px; overflow: hidden; animation: qrs-pulse 1.4s ease infinite; }
.qrs-skeleton-sq { width: 100%; aspect-ratio: 1; }
.qrs-skeleton-ln { height: 11px; margin: 8px 10px 4px; border-radius: 4px; background: #e0e2ea; }
.qrs-skeleton-ln.s { width: 55%; height: 9px; }
@keyframes qrs-pulse { 0%,100%{opacity:1} 50%{opacity:.45} }

/* Empty / error state */
.qrs-empty { text-align: center; padding: 40px 20px; color: #bbb; }
.qrs-empty-icon { font-size: 48px; margin-bottom: 10px; }
.qrs-empty p { font-size: 14px; }

/* Pagination */
.qrs-pagination { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; }

/* Responsive */
@media (max-width: 800px) {
    body { overflow: auto; }
    .qrs-layout { grid-template-columns: 1fr; grid-template-rows: auto 1fr; height: auto; }
    .qrs-left { height: auto; border-right: none; border-bottom: 1px solid #e8eaf0; overflow: visible; }
    .qrs-right { height: auto; overflow: visible; }
    .qrs-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
}
</style>
</head>
<body>

<!-- Top bar -->
<header class="qrs-topbar">
    <div class="qrs-logo"><span>🎨</span> GPT Images Studio</div>
    <div class="qrs-topbar-spacer"></div>
    <div class="qrs-topbar-user">
        <span>👤</span>
        <?php echo esc_html( $user->display_name ); ?>
    </div>
</header>

<div class="qrs-layout">

    <!-- ═══════════ LEFT PANEL ═══════════ -->
    <aside class="qrs-left">

        <!-- Step 1: QR image -->
        <div>
            <p class="qrs-section-label"><span class="qrs-section-label-num">1</span>Ảnh QR của bạn</p>

            <!-- Preview (initially hidden) -->
            <div id="qrs-preview" class="qrs-qr-preview">
                <img id="qrs-preview-img" src="" alt="QR Image">
                <button class="qrs-qr-remove" id="qrs-remove-btn" title="Xóa ảnh">✕</button>
            </div>

            <!-- Upload drop zone (shown when no image) -->
            <div id="qrs-upload-zone" class="qrs-upload-zone">
                <input type="file" id="qrs-file-input" accept="image/jpeg,image/png,image/webp">
                <div class="qrs-upload-icon">⬆️</div>
                <p class="qrs-upload-label">Tải ảnh QR lên</p>
                <p class="qrs-upload-hint">Kéo thả hoặc click • JPG, PNG, WebP</p>
            </div>

            <!-- URL load -->
            <div class="qrs-url-row">
                <input type="url" id="qrs-url-input" class="qrs-url-input"
                       placeholder="URL ảnh QR hoặc link cần tạo QR..."
                       value="<?php echo esc_attr( $qr_from_url ); ?>">
                <button id="qrs-url-btn" class="qrs-url-btn" title="Tải ảnh từ URL">Tải</button>
                <button id="qrs-qrgen-btn" class="qrs-qrgen-btn" title="Tạo mã QR từ URL này rồi load vào">📱 Tạo QR</button>
                <button id="qrs-media-pick-btn" class="qrs-url-btn" style="background:#1d4ed8;padding:8px 10px;font-size:15px" title="Chọn / tải ảnh từ Media Library">🖼</button>
            </div>

            <div id="qrs-upload-status" class="qrs-status"></div>
        </div>

        <!-- Step 2: Template info -->
        <div>
            <p class="qrs-section-label"><span class="qrs-section-label-num">2</span>Template đã chọn</p>
            <div id="qrs-sel-tpl" class="qrs-sel-tpl">
                <img id="qrs-sel-thumb" class="qrs-sel-thumb" src="" alt="">
                <div class="qrs-sel-info">
                    <div id="qrs-sel-name" class="qrs-sel-name"></div>
                    <div id="qrs-sel-size" class="qrs-sel-size"></div>
                </div>
                <button class="qrs-sel-clear" id="qrs-sel-clear-btn" title="Bỏ chọn">✕</button>
            </div>
            <p id="qrs-sel-empty" style="font-size:12px;color:#bbb">Chọn template bên phải →</p>

            <!-- Prompt block moved to right panel -->
        </div>

        <!-- Generate -->
        <div>
            <button id="qrs-generate-btn" class="qrs-generate-btn" disabled>
                ✨ Tạo ảnh bằng AI
            </button>

            <!-- Model picker -->
            <div style="margin-top:8px;display:flex;align-items:center;gap:6px;font-size:12px;color:#555">
                <label for="qrs-model" style="font-weight:600;white-space:nowrap">🤖 Model:</label>
                <select id="qrs-model" style="flex:1;padding:6px 8px;border:1px solid #ddd;border-radius:6px;font-size:12px;background:#fff;cursor:pointer">
                    <option value="nano-banana-pro" selected>🍌 Nano Banana Pro — Gemini 3 Pro (đẹp nhất, ~30s)</option>
                    <option value="nano-banana">🍌 Nano Banana — Gemini 2.5 Flash (nhanh, rẻ)</option>
                    <option value="gpt-image">🧠 GPT-5 Image (OpenAI, chi tiết cao)</option>
                    <option value="gpt-image-mini">🧠 GPT-5 Image Mini (rẻ nhất)</option>
                </select>
            </div>

            <label style="display:flex;align-items:center;gap:6px;margin-top:8px;padding:8px 10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;cursor:pointer;font-size:13px;color:#065f46">
                <input type="checkbox" id="qrs-lock-qr" checked style="margin:0">
                <span>🔒 <strong>Khoá QR gốc</strong> — overlay QR pixel-perfect sau AI (đảm bảo QR scan được 100%)</span>
            </label>
            <div id="qrs-gen-status" class="qrs-status"></div>
            <div id="qrs-canva-wrap" style="display:none;margin-top:8px">
                <button id="qrs-canva-btn" class="qrs-generate-btn"
                    style="background:linear-gradient(135deg,#1e40af,#3b82f6)">
                    🎨 Mở trong Canva Editor
                </button>
            </div>
        </div>

        <!-- Result moved to right panel -->

    </aside>

    <!-- ═══════════ RIGHT PANEL ═══════════ -->
    <main class="qrs-right">

        <!-- Kết quả output ảnh AI — on top -->
        <div id="qrs-result" class="qrs-result" style="border-top:1px solid #e8eaf0;padding-top:16px">
            <img id="qrs-result-img" src="" alt="Kết quả QR Studio">
            <div id="qrs-media-badge" class="qrs-media-badge" style="display:none;margin:8px 0;padding:8px 10px;border-radius:8px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-size:13px;line-height:1.4">
                <span id="qrs-media-badge-text">✓ Đã lưu vào Media Library</span>
            </div>
            <div class="qrs-result-actions">
                <a id="qrs-dl-btn" class="qrs-btn-dl" download="qr-studio.png">⬇ Tải về</a>
                <a id="qrs-media-btn" class="qrs-btn-dl" target="_blank" rel="noopener"
                    style="display:none;background:#f0fdf4;color:#15803d;border-color:#bbf7d0">🗂 Mở trong Media</a>
                <button id="qrs-canva-edit-btn" class="qrs-btn-dl"
                    style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe">✏️ Sửa tiếp</button>
                <button id="qrs-reset-btn" class="qrs-btn-rst">🔄 Làm lại</button>
            </div>
        </div>

        <!-- Prompt mẫu — on top (auto-fill khi chọn template) -->
        <div id="qrs-sel-prompt-wrap" style="display:none;border-top:1px solid #e8eaf0;padding-top:16px;margin-top:4px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
                <span style="font-size:12px;font-weight:700;color:#6b46c1;text-transform:uppercase;letter-spacing:.5px">📝 Prompt mẫu</span>
                <button id="qrs-sel-prompt-copy" type="button"
                    style="background:#6b46c1;color:#fff;border:0;border-radius:5px;padding:4px 12px;font-size:12px;cursor:pointer;transition:background .2s">
                    📋 Copy
                </button>
            </div>
            <textarea id="qrs-sel-prompt" readonly
                style="width:100%;min-height:100px;max-height:180px;padding:9px;font-size:12px;font-family:'SF Mono',Menlo,monospace;border:1px solid #e0d4f7;border-radius:8px;background:#faf7ff;color:#333;resize:vertical;line-height:1.5"
                placeholder="(Template này chưa có prompt mẫu)"></textarea>
            <p id="qrs-sel-prompt-meta" style="font-size:11px;color:#999;margin-top:4px"></p>
        </div>

        <div>
            <h2 class="qrs-right-heading">📚 Thư viện Template QR</h2>
            <p class="qrs-right-sub">Chọn 1 template để kết hợp với mã QR của bạn</p>
        </div>

        <!-- Degraded banner (gateway unreachable) -->
        <div id="qrs-degraded" class="qrs-degraded">
            ⚠️ Không thể kết nối tới thư viện BizCity.
            Vui lòng kiểm tra cấu hình BizCity API key trong <a href="<?php echo esc_url( admin_url( 'admin.php?page=bizcity-twinchat-settings' ) ); ?>">Settings</a>.
        </div>

        <!-- Search + categories toolbar -->
        <div class="qrs-toolbar">
            <input type="text" id="qrs-search" class="qrs-search" placeholder="🔍 Tìm template...">
            <div class="qrs-cats" id="qrs-cats">
                <button class="qrs-cat-btn active" data-cat="">Tất cả</button>
            </div>
        </div>

        <!-- Template grid -->
        <div id="qrs-grid" class="qrs-grid"></div>

        <!-- Pagination -->
        <div id="qrs-pagination" class="qrs-pagination"></div>

    </main>

</div>

<?php wp_footer(); ?>

<script>console.log('[QR-Studio] view version: 2026-05-27-nano-banana-pro');(function () {
    'use strict';

    /* ── Config ── */
    var REST    = <?php echo wp_json_encode( rtrim( $rest_root, '/' ) ); ?>;
    var AJAX    = <?php echo wp_json_encode( $ajax_url ); ?>;
    var NONCE   = <?php echo wp_json_encode( $nonce ); ?>;
    var RNONCE  = <?php echo wp_json_encode( $rest_nonce ); ?>;
    var MEDIA_LIB_URL = <?php echo wp_json_encode( admin_url( 'upload.php' ) ); ?>;
    var POST_EDIT_URL = <?php echo wp_json_encode( admin_url( 'post.php' ) ); ?>;

    /* ── State ── */
    var qrUrl         = <?php echo wp_json_encode( $qr_from_url ); ?>;
    var imgFromUrl    = <?php echo wp_json_encode( $img_from_url ); ?>;
    var selectedTpl   = null;
    var activeCat     = '';
    var searchVal     = '';
    var currentPage   = 1;
    var searchTimer   = null;
    var isGenerating  = false;

    /* ── DOM ── */
    var elUploadZone  = document.getElementById('qrs-upload-zone');
    var elFileInput   = document.getElementById('qrs-file-input');
    var elPreview     = document.getElementById('qrs-preview');
    var elPreviewImg  = document.getElementById('qrs-preview-img');
    var elRemoveBtn   = document.getElementById('qrs-remove-btn');
    var elUrlInput    = document.getElementById('qrs-url-input');
    var elUrlBtn      = document.getElementById('qrs-url-btn');
    var elUploadStat  = document.getElementById('qrs-upload-status');
    var elSelTpl      = document.getElementById('qrs-sel-tpl');
    var elSelThumb    = document.getElementById('qrs-sel-thumb');
    var elSelName     = document.getElementById('qrs-sel-name');
    var elSelSize     = document.getElementById('qrs-sel-size');
    var elSelEmpty    = document.getElementById('qrs-sel-empty');
    var elSelClear    = document.getElementById('qrs-sel-clear-btn');
    var elGenBtn      = document.getElementById('qrs-generate-btn');
    var elGenStat     = document.getElementById('qrs-gen-status');
    var elResult      = document.getElementById('qrs-result');
    var elResultImg   = document.getElementById('qrs-result-img');
    var elDlBtn       = document.getElementById('qrs-dl-btn');
    var elResetBtn    = document.getElementById('qrs-reset-btn');
    var elGrid        = document.getElementById('qrs-grid');
    var elCats        = document.getElementById('qrs-cats');
    var elSearch      = document.getElementById('qrs-search');
    var elPagination  = document.getElementById('qrs-pagination');
    var elDegraded    = document.getElementById('qrs-degraded');

    /* Card list cache (used by delegation) */
    var currentItems = [];

    /* ── Event delegation: handle clicks on .qrs-card (survives re-render) ── */
    elGrid.addEventListener('click', function(e) {
        var card = e.target.closest('.qrs-card');
        if (!card || !elGrid.contains(card)) return;
        var id  = String(card.dataset.id);
        var tpl = currentItems.find(function(t) { return String(t.id) === id; });
        if (tpl) selectTemplate(tpl);
    });
    elGrid.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var card = e.target.closest('.qrs-card');
        if (!card) return;
        e.preventDefault();
        var id  = String(card.dataset.id);
        var tpl = currentItems.find(function(t) { return String(t.id) === id; });
        if (tpl) selectTemplate(tpl);
    });

    /* ── Helpers ── */
    function showStatus(el, msg, type) {
        el.textContent = msg;
        el.className = 'qrs-status ' + (type || 'info') + (msg ? ' visible' : '');
    }

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    /* ── QR image management ── */
    function setQrUrl(url) {
        qrUrl = url || '';
        if (qrUrl) {
            elPreviewImg.src = qrUrl;
            elPreview.classList.add('visible');
            elUploadZone.style.display = 'none';
        } else {
            elPreview.classList.remove('visible');
            elUploadZone.style.display = '';
            elPreviewImg.src = '';
        }
        syncGenerateBtn();
    }

    function syncGenerateBtn() {
        elGenBtn.disabled = !(qrUrl && selectedTpl);
    }

    /* ── Template selection ── */
    function selectTemplate(tpl) {
        selectedTpl = tpl;

        // Update cards
        elGrid.querySelectorAll('.qrs-card').forEach(function(c) {
            c.classList.toggle('active', String(c.dataset.id) === String(tpl.id));
        });

        // Update info panel
        elSelThumb.src = tpl.thumbnail_url || '';
        elSelThumb.style.display = tpl.thumbnail_url ? 'block' : 'none';
        elSelName.textContent = tpl.name;
        elSelSize.textContent = (tpl.canvas_width || '?') + ' × ' + (tpl.canvas_height || '?') + ' px';
        elSelTpl.classList.add('visible');
        elSelEmpty.style.display = 'none';
        syncGenerateBtn();

        // Fetch detail to get template_json.prompt (?preview=1 → no download increment)
        loadPromptForTemplate(tpl.id);
    }

    function loadPromptForTemplate(tplId) {
        var elWrap   = document.getElementById('qrs-sel-prompt-wrap');
        var elArea   = document.getElementById('qrs-sel-prompt');
        var elMeta   = document.getElementById('qrs-sel-prompt-meta');
        if (!elWrap || !elArea) return;

        elWrap.style.display = 'block';
        elArea.value         = '';
        elArea.placeholder   = 'Đang tải prompt mẫu...';
        elMeta.textContent   = '';

        fetch(REST + '/qr-studio/templates/' + tplId + '?preview=1', {
            headers: { 'X-WP-Nonce': RNONCE }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (!json || !json.success || !json.data) {
                elArea.placeholder = '(Không tải được prompt — có thể template đã bị xóa)';
                return;
            }
            var d = json.data;
            // Cache full template (with template_json) on selectedTpl
            if (selectedTpl && String(selectedTpl.id) === String(d.id)) {
                selectedTpl = Object.assign({}, selectedTpl, d);
            }
            var tj = d.template_json;
            if (typeof tj === 'string') {
                try { tj = JSON.parse(tj); } catch (e) { tj = null; }
            }
            var prompt = (tj && tj.prompt) ? String(tj.prompt) : '';
            if (prompt) {
                elArea.value = prompt;
                elArea.placeholder = '';
                var meta = [];
                if (tj.source) meta.push('Nguồn: ' + tj.source);
                if (tj.author) meta.push('Tác giả: ' + tj.author);
                if (tj.tweet)  meta.push('<a href="' + tj.tweet + '" target="_blank" rel="noopener">Xem tweet gốc ↗</a>');
                elMeta.innerHTML = meta.join(' · ');
            } else {
                elArea.placeholder = '(Template này chưa có prompt mẫu trong thư viện)';
                elMeta.textContent = '';
            }
        })
        .catch(function() {
            elArea.placeholder = '(Lỗi mạng khi tải prompt)';
        });
    }

    /* Copy prompt to clipboard */
    (function(){
        var btn = document.getElementById('qrs-sel-prompt-copy');
        if (!btn) return;
        btn.addEventListener('click', function() {
            var area = document.getElementById('qrs-sel-prompt');
            if (!area || !area.value) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(area.value).then(function() {
                    var old = btn.textContent;
                    btn.textContent = '✅ Đã copy';
                    setTimeout(function() { btn.textContent = old; }, 1500);
                });
            } else {
                area.select();
                document.execCommand('copy');
                var old = btn.textContent;
                btn.textContent = '✅ Đã copy';
                setTimeout(function() { btn.textContent = old; }, 1500);
            }
        });
    }());

    function clearTemplate() {
        selectedTpl = null;
        elGrid.querySelectorAll('.qrs-card').forEach(function(c) { c.classList.remove('active'); });
        elSelTpl.classList.remove('visible');
        elSelEmpty.style.display = '';
        var elPW = document.getElementById('qrs-sel-prompt-wrap');
        if (elPW) elPW.style.display = 'none';
        syncGenerateBtn();
    }

    /* ── Load categories ── */
    function loadCategories() {
        fetch(REST + '/qr-studio/categories', {
            headers: { 'X-WP-Nonce': RNONCE }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (!json.success || !Array.isArray(json.data)) return;
            json.data.forEach(function(cat) {
                var btn = document.createElement('button');
                btn.className = 'qrs-cat-btn';
                btn.dataset.cat = cat.slug;
                btn.textContent = cat.name;
                btn.addEventListener('click', function() { setCat(cat.slug); });
                elCats.appendChild(btn);
            });
        })
        .catch(function() {});
    }

    function setCat(slug) {
        activeCat = slug;
        currentPage = 1;
        elCats.querySelectorAll('.qrs-cat-btn').forEach(function(btn) {
            btn.classList.toggle('active', btn.dataset.cat === slug);
        });
        loadTemplates();
    }

    /* ── Load templates ── */
    function loadTemplates() {
        elGrid.innerHTML = renderSkeletons(8);
        elPagination.innerHTML = '';

        var params = new URLSearchParams({ page: currentPage, per_page: 20 });
        if (activeCat) params.set('category', activeCat);
        if (searchVal) params.set('search', searchVal);

        fetch(REST + '/qr-studio/templates?' + params.toString(), {
            headers: { 'X-WP-Nonce': RNONCE }
        })
        .then(function(r) { return r.json(); })
        .then(function(json) {
            if (json._degraded) {
                elDegraded.classList.add('visible');
            } else {
                elDegraded.classList.remove('visible');
            }

            var items = (json.data && json.data.items) ? json.data.items : [];
            var pages = (json.data && json.data.pages) ? json.data.pages : 1;

            renderTemplates(items);
            renderPagination(pages);
        })
        .catch(function() {
            elGrid.innerHTML = '<div class="qrs-empty"><div class="qrs-empty-icon">❌</div><p>Không thể tải template. Vui lòng thử lại.</p></div>';
        });
    }

    function renderSkeletons(n) {
        var html = '';
        for (var i = 0; i < n; i++) {
            html += '<div class="qrs-skeleton"><div class="qrs-skeleton-sq"></div><div class="qrs-skeleton-ln"></div><div class="qrs-skeleton-ln s"></div></div>';
        }
        return html;
    }

    function renderTemplates(items) {
        if (!items.length) {
            elGrid.innerHTML = '<div class="qrs-empty"><div class="qrs-empty-icon">🗂️</div><p>Không tìm thấy template phù hợp.</p></div>';
            currentItems = [];
            return;
        }
        currentItems = items;

        elGrid.innerHTML = items.map(function(tpl) {
            var thumb = tpl.thumbnail_url
                ? '<img class="qrs-card-thumb" src="' + escHtml(tpl.thumbnail_url) + '" alt="' + escHtml(tpl.name) + '" loading="lazy">'
                : '<div class="qrs-card-placeholder">🎨</div>';
            var isActive = selectedTpl && String(selectedTpl.id) === String(tpl.id) ? ' active' : '';
            return '<div class="qrs-card' + isActive + '" data-id="' + tpl.id + '" role="button" tabindex="0">'
                + thumb
                + '<div class="qrs-card-check">✓</div>'
                + '<div class="qrs-card-body">'
                +   '<div class="qrs-card-name">' + escHtml(tpl.name) + '</div>'
                +   '<div class="qrs-card-meta">' + (tpl.canvas_width || '?') + '×' + (tpl.canvas_height || '?') + '</div>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    function renderPagination(pages) {
        if (pages <= 1) { elPagination.innerHTML = ''; return; }
        var html = '';
        for (var p = 1; p <= Math.min(pages, 10); p++) {
            html += '<button class="qrs-cat-btn' + (p === currentPage ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
        }
        elPagination.innerHTML = html;
        elPagination.querySelectorAll('[data-page]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                currentPage = parseInt(btn.dataset.page, 10);
                loadTemplates();
            });
        });
    }

    /* ── File upload ── */
    elFileInput.addEventListener('change', function() {
        var file = elFileInput.files[0];
        if (file) uploadFile(file);
    });
    elUploadZone.addEventListener('dragover', function(e) {
        e.preventDefault(); elUploadZone.classList.add('drag-over');
    });
    elUploadZone.addEventListener('dragleave', function() {
        elUploadZone.classList.remove('drag-over');
    });
    elUploadZone.addEventListener('drop', function(e) {
        e.preventDefault(); elUploadZone.classList.remove('drag-over');
        var file = e.dataTransfer.files[0];
        if (file) uploadFile(file);
    });

    function uploadFile(file) {
        showStatus(elUploadStat, 'Đang tải lên...', 'loading');
        var fd = new FormData();
        fd.append('action', 'bztimg_qr_upload_image');
        fd.append('nonce', NONCE);
        fd.append('file', file);

        fetch(AJAX, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.success) {
                    setQrUrl(json.data.url);
                    showStatus(elUploadStat, '', '');
                } else {
                    showStatus(elUploadStat, json.data && json.data.message ? json.data.message : 'Lỗi tải ảnh.', 'error');
                }
            })
            .catch(function() { showStatus(elUploadStat, 'Lỗi kết nối.', 'error'); });
    }

    /* ── URL load ── */
    elUrlBtn.addEventListener('click', function() {
        var url = elUrlInput.value.trim();
        if (url && /^https?:\/\//i.test(url)) {
            setQrUrl(url);
            showStatus(elUploadStat, '', '');
        } else {
            showStatus(elUploadStat, 'URL phải bắt đầu bằng http:// hoặc https://.', 'error');
        }
    });
    elUrlInput.addEventListener('keydown', function(e) { if (e.key === 'Enter') elUrlBtn.click(); });

    /* ── WP Media picker (nút 🖼 trong URL row) ── */
    (function() {
        var btn = document.getElementById('qrs-media-pick-btn');
        if (!btn) return;
        var frame;
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (typeof wp === 'undefined' || !wp.media) {
                showStatus(elUploadStat, '⚠️ WP Media chưa sẵn sàng. Thử tải lại trang.', 'error');
                return;
            }
            if (!frame) {
                frame = wp.media({
                    title:    'Chọn ảnh QR / ảnh mẫu',
                    button:   { text: 'Dùng ảnh này' },
                    multiple: false,
                    library:  { type: 'image' },
                });
                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();
                    var url = (att.sizes && att.sizes.full) ? att.sizes.full.url : att.url;
                    if (url) {
                        setQrUrl(url);
                        if (elUrlInput) elUrlInput.value = url;
                        showStatus(elUploadStat, '✅ Đã chọn ảnh từ Media Library.', 'success');
                    }
                });
            }
            frame.open();
        });
    }());

    /* ── Tạo QR từ URL ── */
    var elQrGenBtn = document.getElementById('qrs-qrgen-btn');
    if (elQrGenBtn) {
        elQrGenBtn.addEventListener('click', function() {
            var dataUrl = elUrlInput.value.trim();
            if (!dataUrl) {
                showStatus(elUploadStat, 'Nhập URL cần tạo QR trước.', 'error');
                return;
            }
            var qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=480x480&data=' + encodeURIComponent(dataUrl);
            showStatus(elUploadStat, '📱 Đang tạo mã QR...', 'loading');
            setQrUrl(qrSrc);
            showStatus(elUploadStat, '✅ Đã tạo QR từ: ' + escHtml(dataUrl.length > 60 ? dataUrl.slice(0, 60) + '…' : dataUrl), 'success');
        });
    }

    /* ── Remove QR ── */
    elRemoveBtn.addEventListener('click', function() {
        elFileInput.value = '';
        elUrlInput.value = '';
        setQrUrl('');
        showStatus(elUploadStat, '', '');
    });

    /* ── Clear template ── */
    elSelClear.addEventListener('click', clearTemplate);

    /* ── Search ── */
    elSearch.addEventListener('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            searchVal   = elSearch.value.trim();
            currentPage = 1;
            loadTemplates();
        }, 350);
    });

    /* ── Generate / Apply template — AI Generative (Nano Banana Pro, ref images = template + QR) ── */
    elGenBtn.addEventListener('click', function() {
        if (!qrUrl || !selectedTpl || isGenerating) return;

        isGenerating = true;
        elGenBtn.disabled = true;
        document.getElementById('qrs-canva-wrap').style.display = 'none';
        var elModelSel = document.getElementById('qrs-model');
        var chosenModel = (elModelSel && elModelSel.value) ? elModelSel.value : 'nano-banana-pro';
        var modelLabel  = elModelSel && elModelSel.selectedOptions[0] ? elModelSel.selectedOptions[0].textContent.split('—')[0].trim() : chosenModel;
        showStatus(elGenStat, '🎨 ' + modelLabel + ' đang sáng tạo ảnh (~20–40s)...', 'loading');
        elResult.classList.remove('visible');

        var fd = new FormData();
        fd.append('template_id', selectedTpl.id);
        fd.append('qr_url',      qrUrl);
        fd.append('model',       chosenModel);

        fetch(REST + '/qr-studio/generate', {
            method:  'POST',
            headers: { 'X-WP-Nonce': RNONCE },
            body:    fd
        })
        .then(function(r) { return r.json().then(function(j){ return { ok: r.ok, json: j }; }); })
        .then(function(env) {
            var json = env.json || {};
            if (!env.ok || !json.success) {
                var msg = json.message || 'AI generate thất bại.';
                throw new Error(msg);
            }
            var d   = json.data || {};
            var url = d.image_url || d.url || (d.thumbnail_url || '');
            if (!url) throw new Error('AI không trả về image_url.');

            var attId  = parseInt(d.attachment_id || 0, 10) || 0;

            /* ── 🔒 QR Lock: overlay original QR onto AI image to guarantee scannability ── */
            var lockChk = document.getElementById('qrs-lock-qr');
            var doLock  = lockChk && lockChk.checked;

            var finalizeUI = function(finalUrl, overlayUsed) {
                var okText = overlayUsed
                    ? '✅ Đã tạo + overlay QR gốc (100% scan được). Nhấn ⬇ để tải về.'
                    : (attId
                        ? '✅ Đã tạo + lưu vào Media Library (#' + attId + '). Nhấn ⬇ để tải về.'
                        : '✅ Đã tạo xong bằng AI! Nhấn ⬇ để tải về.');
                showStatus(elGenStat, okText, 'success');
                elResultImg.src  = finalUrl;
                elDlBtn.href     = finalUrl;
                elDlBtn.download = 'qr-studio-ai-' + Date.now() + '.png';
                elDlBtn.target   = '_blank';
                elResult.classList.add('visible');

                var elMediaBadge = document.getElementById('qrs-media-badge');
                var elMediaTxt   = document.getElementById('qrs-media-badge-text');
                var elMediaBtn   = document.getElementById('qrs-media-btn');
                if (attId && elMediaBadge && elMediaBtn && !overlayUsed) {
                    elMediaTxt.innerHTML = '✓ Đã lưu vào <strong>Media Library</strong> · ID <code>#' + attId + '</code>';
                    elMediaBadge.style.display = 'block';
                    elMediaBtn.href = POST_EDIT_URL + '?post=' + attId + '&action=edit';
                    elMediaBtn.style.display = 'inline-flex';
                } else if (overlayUsed && elMediaBadge && elMediaTxt) {
                    elMediaTxt.innerHTML = '🔒 QR gốc đã được overlay (file chỉ tồn tại trong trình duyệt — tải về để lưu)';
                    elMediaBadge.style.display = 'block';
                    if (elMediaBtn) elMediaBtn.style.display = 'none';
                } else if (elMediaBadge && elMediaBtn) {
                    elMediaBadge.style.display = 'none';
                    elMediaBtn.style.display   = 'none';
                }

                window.dispatchEvent(new CustomEvent('qrstudio:generated', {
                    detail: { image_url: finalUrl, template_id: selectedTpl.id, qr_url: qrUrl, overlay: overlayUsed }
                }));
            };

            if (doLock) {
                showStatus(elGenStat, '🔒 Đang overlay QR gốc lên ảnh AI...', 'loading');
                overlayQrOnImage(url, qrUrl, selectedTpl)
                    .then(function(res) {
                        if (res.success && res.dataUrl) {
                            finalizeUI(res.dataUrl, true);
                        } else {
                            /* Overlay failed (CORS / tainted canvas) — fall back to raw AI image */
                            showStatus(elGenStat, '⚠️ Không overlay được QR gốc (CORS): ' + (res.error || '') + ' — dùng ảnh AI gốc.', 'error');
                            setTimeout(function() { finalizeUI(url, false); }, 800);
                        }
                    });
            } else {
                finalizeUI(url, false);
            }
        })
        .catch(function(e) {
            showStatus(elGenStat, '❌ ' + e.message + ' — bạn có thể thử lại hoặc dùng Canva Editor.', 'error');
            document.getElementById('qrs-canva-wrap').style.display = 'block';
        })
        .finally(function() {
            isGenerating = false;
            syncGenerateBtn();
        });
    });

    /* ── Canvas2D QR Compositing ──
     * Loads template thumbnail as background, overlays QR image.
     * Position: template_json.qr_slot {x,y,w,h} OR center 35% of canvas.
     * Returns: Promise<{success:bool, dataUrl?:string, error?:string}>
     */
    function compositeQR(qrImageUrl, tplData) {
        return new Promise(function(resolve) {
            var w = tplData.canvas_width  || 1080;
            var h = tplData.canvas_height || 1080;

            /* Check for a qr_slot hint in template_json */
            var slot = null;
            try {
                var tjson = tplData.template_json;
                if (typeof tjson === 'object' && tjson && tjson.qr_slot) {
                    slot = tjson.qr_slot; /* {x, y, w, h} in canvas pixels */
                }
            } catch (e) {}

            var canvas = document.createElement('canvas');
            canvas.width  = w;
            canvas.height = h;
            var ctx = canvas.getContext('2d');

            function loadImg(src) {
                return new Promise(function(res, rej) {
                    var img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload  = function() { res(img); };
                    img.onerror = function() { rej(new Error('load-fail')); };
                    img.src = src;
                });
            }

            var bgSrc  = tplData.thumbnail_url || '';
            var loadBg = bgSrc ? loadImg(bgSrc) : Promise.resolve(null);

            Promise.all([loadBg, loadImg(qrImageUrl)])
                .then(function(imgs) {
                    var bgImg = imgs[0];
                    var qrImg = imgs[1];

                    /* Draw template background */
                    if (bgImg) {
                        ctx.drawImage(bgImg, 0, 0, w, h);
                    } else {
                        ctx.fillStyle = '#ffffff';
                        ctx.fillRect(0, 0, w, h);
                    }

                    /* Draw QR at slot or centered */
                    var qx, qy, qw, qh;
                    if (slot) {
                        qx = slot.x; qy = slot.y; qw = slot.w; qh = slot.h;
                    } else {
                        qw = qh = Math.round(Math.min(w, h) * 0.35);
                        qx = Math.round((w - qw) / 2);
                        qy = Math.round((h - qh) / 2);
                    }
                    ctx.drawImage(qrImg, qx, qy, qw, qh);

                    try {
                        resolve({ success: true, dataUrl: canvas.toDataURL('image/png') });
                    } catch (secErr) {
                        /* Tainted canvas — cross-origin image without CORS headers */
                        resolve({ success: false, error: 'cors' });
                    }
                })
                .catch(function() { resolve({ success: false, error: 'load' }); });
        });
    }

    /* ── 🔒 Overlay original QR onto AI-generated image ──
     * Guarantees QR scannability by drawing pixel-perfect QR over AI output at qr_slot.
     * AI image is used as the background (instead of thumbnail).
     * Both images need CORS-enabled hosts (qrserver.com, openai DALL-E URLs).
     */
    function overlayQrOnImage(aiImageUrl, qrImageUrl, tplData) {
        return new Promise(function(resolve) {
            var w = tplData.canvas_width  || 1080;
            var h = tplData.canvas_height || 1080;

            /* Parse qr_slot (may be stored as string or object on tplData) */
            var slot = null;
            try {
                var tj = tplData.template_json;
                if (typeof tj === 'string') { try { tj = JSON.parse(tj); } catch (e) { tj = null; } }
                if (tj && tj.qr_slot) slot = tj.qr_slot;
            } catch (e) {}

            function loadImg(src) {
                return new Promise(function(res, rej) {
                    var img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload  = function() { res(img); };
                    img.onerror = function() { rej(new Error('load-fail:' + src.slice(0, 60))); };
                    img.src = src;
                });
            }

            Promise.all([loadImg(aiImageUrl), loadImg(qrImageUrl)])
                .then(function(imgs) {
                    var aiImg = imgs[0];
                    var qrImg = imgs[1];

                    /* Use AI image dimensions if it differs from template canvas */
                    var cw = aiImg.naturalWidth  || w;
                    var ch = aiImg.naturalHeight || h;

                    var canvas = document.createElement('canvas');
                    canvas.width  = cw;
                    canvas.height = ch;
                    var ctx = canvas.getContext('2d');

                    /* AI image as full background */
                    ctx.drawImage(aiImg, 0, 0, cw, ch);

                    /* Compute QR rect — scale from template canvas to actual AI image dims */
                    var qx, qy, qw, qh;
                    if (slot) {
                        var sx = cw / w, sy = ch / h;
                        qx = Math.round(slot.x * sx);
                        qy = Math.round(slot.y * sy);
                        qw = Math.round(slot.w * sx);
                        qh = Math.round(slot.h * sy);
                    } else {
                        qw = qh = Math.round(Math.min(cw, ch) * 0.32);
                        qx = Math.round((cw - qw) / 2);
                        qy = Math.round((ch - qh) / 2);
                    }

                    /* White padding behind QR for contrast + quiet-zone */
                    var pad = Math.round(qw * 0.06);
                    ctx.fillStyle = '#ffffff';
                    ctx.fillRect(qx - pad, qy - pad, qw + pad * 2, qh + pad * 2);

                    /* Draw QR pixel-perfect (preserves scannability) */
                    ctx.imageSmoothingEnabled = false;
                    ctx.drawImage(qrImg, qx, qy, qw, qh);

                    try {
                        resolve({ success: true, dataUrl: canvas.toDataURL('image/png') });
                    } catch (secErr) {
                        resolve({ success: false, error: 'tainted-canvas (CORS)' });
                    }
                })
                .catch(function(err) {
                    resolve({ success: false, error: (err && err.message) || 'load-fail' });
                });
        });
    }

    /* ── Open Canva Editor with selected template ── */
    function openCanvaEditor(tplId) {
        window.open(<?php echo wp_json_encode( home_url( '/canva/' ) ); ?> + '?tpl=' + tplId, '_blank');
    }

    /* ── Reset result ── */
    elResetBtn.addEventListener('click', function() {
        elResult.classList.remove('visible');
        elResultImg.src = '';
        showStatus(elGenStat, '', '');
        var _mb = document.getElementById('qrs-media-badge');
        var _mbtn = document.getElementById('qrs-media-btn');
        if (_mb)   _mb.style.display   = 'none';
        if (_mbtn) _mbtn.style.display = 'none';
    });

    /* ── Canva Editor buttons ── */
    var elCanvaBtn     = document.getElementById('qrs-canva-btn');
    var elCanvaEditBtn = document.getElementById('qrs-canva-edit-btn');
    if (elCanvaBtn) {
        elCanvaBtn.addEventListener('click', function() {
            if (selectedTpl) openCanvaEditor(selectedTpl.id);
        });
    }
    if (elCanvaEditBtn) {
        elCanvaEditBtn.addEventListener('click', function() {
            if (selectedTpl) openCanvaEditor(selectedTpl.id);
        });
    }

    /* ── QR preview image load error (broken URL or CORS-blocked img src) ── */
    elPreviewImg.onerror = function() {
        showStatus(elUploadStat, '⚠️ Không thể tải ảnh từ URL này. Thử upload file trực tiếp.', 'error');
        elPreview.classList.remove('visible');
        elUploadZone.style.display = '';
        qrUrl = '';
        syncGenerateBtn();
    };

    /* ── Init ── */
    if (qrUrl) {
        setQrUrl(qrUrl);
    } else if (imgFromUrl) {
        /* ?img= param: load image URL directly (no QR generation needed) */
        setQrUrl(imgFromUrl);
        if (elUrlInput) elUrlInput.value = imgFromUrl;
    }
    loadCategories();
    loadTemplates();

}());
</script>
</body>
</html>
