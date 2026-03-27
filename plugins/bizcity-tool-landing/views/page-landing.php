<?php
/**
 * BizCity Tool Landing Page — Mobile-First Builder SPA
 *
 * Live preview iframe, code editor, media upload, device preview toggle.
 * Bottom tab navigation, responsive, hoạt động cả standalone lẫn iframe.
 *
 * @package BizCity_Tool_Landing
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$open_id      = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Landing Page Studio — BizCity</title>
<style>
/* ══════════════════════════════════════════════════
   CSS Variables & Reset
   ══════════════════════════════════════════════════ */
:root {
    --c-primary: #6366f1;
    --c-primary-light: #818cf8;
    --c-primary-bg: #eef2ff;
    --c-secondary: #ec4899;
    --c-bg: #f8fafc;
    --c-surface: #ffffff;
    --c-text: #1f2937;
    --c-muted: #6b7280;
    --c-border: #e5e7eb;
    --c-success: #10b981;
    --c-danger: #ef4444;
    --c-warning: #f59e0b;
    --radius: 14px;
    --radius-sm: 8px;
    --nav-h: 60px;
    --header-h: 52px;
    --safe-b: env(safe-area-inset-bottom, 0px);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--c-bg);
    color: var(--c-text);
    -webkit-font-smoothing: antialiased;
    overflow: hidden;
    height: 100vh;
    height: 100dvh;
}
button { cursor: pointer; border: none; background: none; font: inherit; color: inherit; }
input, textarea, select { font: inherit; color: inherit; }

/* ══════════════════════════════════════════════════
   Layout: Header + Main + Bottom Nav
   ══════════════════════════════════════════════════ */
.lp-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 50;
    height: var(--header-h);
    display: flex; align-items: center; gap: 10px;
    padding: 0 16px;
    background: rgba(255,255,255,.88);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--c-border);
}
.lp-header-logo { font-size: 22px; }
.lp-header h1 { font-size: 16px; font-weight: 700; }
.lp-header-badge {
    font-size: 10px; font-weight: 600;
    padding: 2px 8px; border-radius: 6px;
    background: linear-gradient(135deg, #fdf2f8, #eef2ff);
    color: var(--c-secondary);
}

.lp-main {
    position: fixed;
    top: var(--header-h); bottom: calc(var(--nav-h) + var(--safe-b));
    left: 0; right: 0;
    overflow: hidden;
}
.lp-tab {
    display: none;
    position: absolute; inset: 0;
    overflow-y: auto;
    padding: 16px 16px 24px;
    -webkit-overflow-scrolling: touch;
}
.lp-tab.active { display: block; }

.lp-nav {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 50;
    height: calc(var(--nav-h) + var(--safe-b));
    padding-bottom: var(--safe-b);
    display: flex; align-items: stretch;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-top: 1px solid var(--c-border);
}
.lp-nav-item {
    flex: 1;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 2px;
    font-size: 10px; font-weight: 500; color: var(--c-muted);
    transition: color .2s;
    -webkit-tap-highlight-color: transparent;
}
.lp-nav-item .lp-nav-icon { font-size: 22px; line-height: 1; }
.lp-nav-item.active { color: var(--c-primary); font-weight: 700; }

/* ══════════════════════════════════════════════════
   CREATE TAB
   ══════════════════════════════════════════════════ */
.lp-section-title {
    font-size: 14px; font-weight: 700; color: var(--c-text);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}

/* Type chips */
.lp-chips {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.lp-chip {
    padding: 6px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 500;
    background: var(--c-surface); border: 1px solid var(--c-border);
    color: var(--c-muted);
    transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.lp-chip.active {
    background: var(--c-primary); color: #fff;
    border-color: var(--c-primary);
}

/* Template select */
.lp-select-wrap {
    margin-bottom: 14px;
}
.lp-select-wrap label {
    display: block; font-size: 12px; font-weight: 600; color: var(--c-muted);
    margin-bottom: 4px;
}
.lp-select-wrap select {
    width: 100%; padding: 8px 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none;
    font-size: 14px; background: var(--c-surface);
    -webkit-appearance: none; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M3 5l3 3 3-3' fill='none' stroke='%236b7280' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 32px;
}
.lp-select-wrap select:focus { border-color: var(--c-primary); }

/* Prompt box */
.lp-prompt-box {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 14px;
}
.lp-prompt-box textarea {
    width: 100%; border: none; outline: none;
    resize: none; min-height: 100px;
    font-size: 14px; line-height: 1.5;
    background: transparent;
}
.lp-prompt-box textarea::placeholder { color: #b0b8c4; }
.lp-prompt-actions {
    display: flex; justify-content: flex-end; gap: 8px;
    margin-top: 8px;
}

/* Buttons */
.lp-btn {
    padding: 10px 20px; border-radius: 10px;
    font-size: 14px; font-weight: 600;
    transition: all .15s;
    display: inline-flex; align-items: center; gap: 6px;
}
.lp-btn-primary {
    background: linear-gradient(135deg, var(--c-primary), var(--c-secondary));
    color: #fff;
    box-shadow: 0 2px 10px rgba(99,102,241,.3);
}
.lp-btn-primary:hover { box-shadow: 0 4px 16px rgba(99,102,241,.4); }
.lp-btn-primary:active { transform: scale(.97); }
.lp-btn-primary:disabled {
    opacity: .5; cursor: not-allowed;
    box-shadow: none; transform: none;
}
.lp-btn-outline {
    background: var(--c-surface);
    border: 1px solid var(--c-border); color: var(--c-text);
}
.lp-btn-outline:hover { border-color: var(--c-primary); color: var(--c-primary); }
.lp-btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 8px; }
.lp-btn-danger { background: var(--c-danger); color: #fff; }

/* Loading */
.lp-loading {
    text-align: center; padding: 32px 16px;
}
.lp-spinner {
    width: 36px; height: 36px;
    border: 3px solid var(--c-border);
    border-top-color: var(--c-primary);
    border-radius: 50%;
    animation: lp-spin .8s linear infinite;
    margin: 0 auto 12px;
}
@keyframes lp-spin { to { transform: rotate(360deg); } }
.lp-loading-text { font-size: 13px; color: var(--c-muted); }

/* Preview result */
.lp-result { display: none; }
.lp-result.show { display: block; }

.lp-preview-frame-wrap {
    position: relative;
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 12px;
}
.lp-preview-frame-wrap iframe {
    width: 100%; height: 400px;
    border: none;
}
.lp-device-bar {
    display: flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    border-bottom: 1px solid var(--c-border);
    background: #f8fafc;
}
.lp-device-btn {
    padding: 4px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600; color: var(--c-muted);
    transition: all .15s;
}
.lp-device-btn.active { background: var(--c-surface); color: var(--c-text); box-shadow: 0 1px 3px rgba(0,0,0,.08); }
.lp-device-label { font-size: 11px; color: var(--c-muted); margin-left: auto; }

/* Result bar */
.lp-result-bar {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.lp-result-bar input[type=text] {
    flex: 1; min-width: 0;
    padding: 8px 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none;
    font-size: 14px;
}
.lp-result-bar input[type=text]:focus { border-color: var(--c-primary); }

/* Export buttons */
.lp-export-bar {
    display: flex; gap: 6px; flex-wrap: wrap;
}
.lp-btn-export {
    padding: 7px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 5px;
    transition: all .15s;
}
.lp-btn-download {
    background: #ecfdf5; color: #059669;
    border: 1px solid #a7f3d0;
}
.lp-btn-download:hover { background: #d1fae5; border-color: #6ee7b7; }

/* ══════════════════════════════════════════════════
   HISTORY TAB
   ══════════════════════════════════════════════════ */
.lp-history-list { display: flex; flex-direction: column; gap: 10px; }
.lp-hcard {
    display: flex; align-items: flex-start; gap: 12px;
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 14px;
    cursor: pointer; transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.lp-hcard:hover { border-color: #c7d2fe; box-shadow: 0 2px 12px rgba(99,102,241,.1); }
.lp-hcard:active { transform: scale(.98); }
.lp-hcard-icon {
    width: 42px; height: 42px; border-radius: 12px;
    background: linear-gradient(135deg, #eef2ff, #fdf2f8);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.lp-hcard-body { flex: 1; min-width: 0; }
.lp-hcard-title {
    font-size: 14px; font-weight: 600; color: var(--c-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lp-hcard-meta {
    font-size: 11px; color: var(--c-muted); margin-top: 3px;
    display: flex; gap: 8px;
}
.lp-hcard-desc {
    font-size: 12px; color: var(--c-muted); margin-top: 4px;
    line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.lp-hcard-actions {
    display: flex; flex-direction: column; gap: 4px;
    flex-shrink: 0;
}
.lp-hcard-actions button {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; transition: background .15s;
}
.lp-hcard-actions button:hover { background: #f1f5f9; }
.lp-hcard-actions .lp-del:hover { background: #fef2f2; color: var(--c-danger); }

.lp-empty {
    text-align: center; padding: 48px 20px;
}
.lp-empty-icon { font-size: 48px; margin-bottom: 12px; }
.lp-empty p { color: var(--c-muted); font-size: 14px; }

.lp-load-more {
    text-align: center; padding: 12px;
}

/* ══════════════════════════════════════════════════
   EDITOR TAB
   ══════════════════════════════════════════════════ */
#tab-editor {
    display: none;
    position: absolute; inset: 0;
    overflow: hidden;
    padding: 0;
    flex-direction: column;
}
#tab-editor.active { display: flex; }

.lp-editor-toolbar {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0; flex-wrap: wrap;
}
.lp-editor-toolbar input[type=text] {
    flex: 1; min-width: 0;
    padding: 6px 10px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none; font-size: 13px;
}
.lp-editor-toolbar input[type=text]:focus { border-color: var(--c-primary); }

.lp-view-btns {
    display: flex; gap: 2px;
    background: #f1f5f9; border-radius: var(--radius-sm);
    padding: 2px;
}
.lp-view-btn {
    padding: 5px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600; color: var(--c-muted);
    transition: all .15s;
}
.lp-view-btn.active { background: var(--c-surface); color: var(--c-text); box-shadow: 0 1px 3px rgba(0,0,0,.08); }

.lp-editor-body {
    flex: 1; display: flex; overflow: hidden;
    min-height: 0;
}
.lp-editor-code {
    flex: 1; display: flex; flex-direction: column;
    border-right: 1px solid var(--c-border);
    min-width: 0;
}
.lp-editor-code textarea {
    flex: 1; width: 100%; border: none; outline: none; resize: none;
    padding: 12px;
    font-family: "Fira Code", "Cascadia Code", Consolas, monospace;
    font-size: 13px; line-height: 1.6;
    background: #1e1e2e; color: #cdd6f4;
    tab-size: 2;
}
.lp-editor-code textarea::placeholder { color: #585b70; }
.lp-editor-preview-pane {
    flex: 1;
    overflow: hidden;
    min-width: 0;
}
.lp-editor-preview-pane iframe {
    width: 100%; height: 100%; border: none;
}

.lp-editor-actions {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0;
    flex-wrap: wrap;
}
.lp-editor-footer {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-top: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0;
}
.lp-editor-status {
    flex: 1; font-size: 11px; color: var(--c-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Editor views */
.lp-editor-body[data-view="code"]    .lp-editor-preview-pane { display: none; }
.lp-editor-body[data-view="preview"] .lp-editor-code { display: none; }

@media (max-width: 767px) {
    .lp-editor-body[data-view="split"] {
        flex-direction: column;
    }
    .lp-editor-body[data-view="split"] .lp-editor-code {
        border-right: none;
        border-bottom: 1px solid var(--c-border);
        max-height: 40%;
    }
}
@media (min-width: 768px) {
    .lp-editor-body[data-view="split"] .lp-editor-code,
    .lp-editor-body[data-view="split"] .lp-editor-preview-pane {
        flex: 1;
    }
}

/* ══════════════════════════════════════════════════
   MEDIA TAB — Image upload & management
   ══════════════════════════════════════════════════ */
.lp-media-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
    margin-top: 12px;
}
.lp-media-item {
    position: relative;
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius-sm);
    overflow: hidden;
    cursor: pointer;
    transition: all .2s;
}
.lp-media-item:hover { border-color: var(--c-primary); box-shadow: 0 2px 8px rgba(99,102,241,.15); }
.lp-media-item img {
    width: 100%; aspect-ratio: 1; object-fit: cover;
    display: block;
}
.lp-media-item-url {
    padding: 6px 8px;
    font-size: 10px; color: var(--c-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lp-media-item .lp-copy-badge {
    position: absolute; top: 4px; right: 4px;
    background: rgba(0,0,0,.6); color: #fff;
    font-size: 10px; padding: 2px 6px; border-radius: 4px;
    opacity: 0; transition: opacity .15s;
}
.lp-media-item:hover .lp-copy-badge { opacity: 1; }

.lp-upload-zone {
    background: var(--c-surface);
    border: 2px dashed var(--c-border);
    border-radius: var(--radius);
    padding: 32px 16px;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
}
.lp-upload-zone:hover,
.lp-upload-zone.dragover {
    border-color: var(--c-primary);
    background: var(--c-primary-bg);
}
.lp-upload-zone-icon { font-size: 36px; margin-bottom: 8px; }
.lp-upload-zone p { font-size: 13px; color: var(--c-muted); }
.lp-upload-zone small { font-size: 11px; color: #9ca3af; margin-top: 4px; display: block; }

/* ══════════════════════════════════════════════════
   VIEW MODAL
   ══════════════════════════════════════════════════ */
.lp-modal {
    display: none;
    position: fixed; inset: 0; z-index: 100;
    background: var(--c-bg);
    flex-direction: column;
}
.lp-modal.show { display: flex; }
.lp-modal-header {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
}
.lp-modal-header h2 {
    flex: 1; font-size: 15px; font-weight: 700;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.lp-modal-back {
    font-size: 13px; color: var(--c-primary);
    font-weight: 600; padding: 6px;
    display: flex; align-items: center; gap: 2px;
}
.lp-modal-actions { display: flex; gap: 4px; }
.lp-modal-actions button {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; transition: background .15s;
}
.lp-modal-actions button:hover { background: #f1f5f9; }
.lp-modal-body {
    flex: 1; overflow: hidden;
}
.lp-modal-body iframe {
    width: 100%; height: 100%; border: none;
}

/* ══════════════════════════════════════════════════
   TOAST
   ══════════════════════════════════════════════════ */
.lp-toast {
    position: fixed;
    bottom: calc(var(--nav-h) + var(--safe-b) + 12px);
    left: 50%; transform: translateX(-50%) translateY(20px);
    background: #1f2937; color: #fff;
    padding: 10px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 500;
    box-shadow: 0 4px 16px rgba(0,0,0,.2);
    z-index: 200;
    opacity: 0; transition: all .3s ease;
    pointer-events: none; white-space: nowrap;
}
.lp-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ══════════════════════════════════════════════════
   LOGIN SCREEN
   ══════════════════════════════════════════════════ */
.lp-login {
    text-align: center; padding: 60px 24px;
}
.lp-login .lp-login-icon { font-size: 56px; margin-bottom: 16px; }
.lp-login h2 { font-size: 20px; margin-bottom: 8px; }
.lp-login p { color: var(--c-muted); font-size: 14px; margin-bottom: 24px; }
.lp-login-btn {
    display: inline-block; padding: 12px 32px;
    background: linear-gradient(135deg, var(--c-primary), var(--c-secondary));
    color: #fff; border-radius: 12px; text-decoration: none;
    font-weight: 600; font-size: 15px;
}

/* ══════════════════════════════════════════════════
   Utility
   ══════════════════════════════════════════════════ */
.hidden { display: none !important; }
</style>
</head>
<body>

<!-- ════════ Header ════════ -->
<header class="lp-header">
    <span class="lp-header-logo">🚀</span>
    <h1>Landing Page Studio</h1>
    <span class="lp-header-badge">AI Builder</span>
</header>

<!-- ════════ Main Content ════════ -->
<main class="lp-main">

    <?php if ( ! $is_logged_in ): ?>
    <section class="lp-tab active">
        <div class="lp-login">
            <div class="lp-login-icon">🔐</div>
            <h2>Đăng nhập để bắt đầu</h2>
            <p>Đăng nhập để tạo landing page chuyên nghiệp và lưu lịch sử.</p>
            <a href="<?php echo esc_url( wp_login_url( home_url( '/tool-landing/' ) ) ); ?>" class="lp-login-btn">Đăng nhập</a>
        </div>
    </section>

    <?php else: ?>

    <!-- ═══════════════════════════════════════════
         TAB: TẠO MỚI
         ═══════════════════════════════════════════ -->
    <section id="tab-create" class="lp-tab active">

        <div class="lp-section-title">🏷️ Loại sản phẩm/dịch vụ</div>
        <div class="lp-chips">
            <button class="lp-chip active" data-type="other">🎯 Auto</button>
            <button class="lp-chip" data-type="saas">💻 SaaS</button>
            <button class="lp-chip" data-type="education">📚 Giáo dục</button>
            <button class="lp-chip" data-type="health">🏥 Sức khỏe</button>
            <button class="lp-chip" data-type="beauty">💅 Làm đẹp</button>
            <button class="lp-chip" data-type="fnb">🍔 F&B</button>
            <button class="lp-chip" data-type="finance">💰 Tài chính</button>
            <button class="lp-chip" data-type="real-estate">🏠 BĐS</button>
            <button class="lp-chip" data-type="event">🎪 Sự kiện</button>
            <button class="lp-chip" data-type="app">📱 App</button>
            <button class="lp-chip" data-type="service">🔧 Dịch vụ</button>
            <button class="lp-chip" data-type="ecommerce">🛒 E-commerce</button>
        </div>

        <div class="lp-select-wrap">
            <label>📐 Conversion Pattern</label>
            <select id="template-select">
                <option value="hero-cta">🎯 Hero-CTA Trực tiếp</option>
                <option value="problem-solution">🔍 Problem-Solution</option>
                <option value="feature-benefit">⭐ Feature-Benefit</option>
                <option value="testimonial-proof">💬 Testimonial-Proof</option>
                <option value="pricing-comparison">💎 Pricing-Comparison</option>
                <option value="countdown-urgency">⏰ Countdown-Urgency</option>
                <option value="storytelling">📖 Storytelling</option>
                <option value="quiz-funnel">🧩 Quiz-Funnel</option>
                <option value="video-showcase">🎬 Video-Showcase</option>
                <option value="minimal-zen">🪷 Minimal-Zen</option>
            </select>
        </div>

        <div class="lp-prompt-box">
            <textarea id="prompt-input" rows="4"
                placeholder="Mô tả sản phẩm/dịch vụ bạn muốn tạo landing page...&#10;&#10;Ví dụ: Khóa học Digital Marketing online 3 tháng cho người mới bắt đầu, giá 2.990.000đ. Có mentor 1-1, cấp chứng chỉ quốc tế, cam kết việc làm..."></textarea>
            <div class="lp-prompt-actions">
                <button id="btn-generate" class="lp-btn lp-btn-primary">🚀 Tạo Landing Page</button>
            </div>
        </div>

        <!-- Loading state -->
        <div id="create-loading" class="lp-loading hidden">
            <div class="lp-spinner"></div>
            <div class="lp-loading-text">AI đang thiết kế landing page... ✨<br><small style="color:#9ca3af">Quá trình này thường mất 60-120 giây</small></div>
        </div>

        <!-- Result area -->
        <div id="create-result" class="lp-result">

            <div class="lp-section-title">👁 Preview</div>

            <div class="lp-preview-frame-wrap">
                <div class="lp-device-bar">
                    <button class="lp-device-btn active" data-device="desktop" data-width="100%">🖥️ Desktop</button>
                    <button class="lp-device-btn" data-device="tablet" data-width="768px">📱 Tablet</button>
                    <button class="lp-device-btn" data-device="mobile" data-width="375px">📲 Mobile</button>
                    <span class="lp-device-label" id="device-label">100%</span>
                </div>
                <div style="display:flex;justify-content:center;background:#e5e7eb;padding:0">
                    <iframe id="create-preview" sandbox="allow-scripts allow-same-origin" style="transition:width .3s ease"></iframe>
                </div>
            </div>

            <div class="lp-result-bar">
                <input type="text" id="save-title" placeholder="Tiêu đề landing page...">
                <button id="btn-save" class="lp-btn lp-btn-primary lp-btn-sm">💾 Lưu</button>
                <button id="btn-to-editor" class="lp-btn lp-btn-outline lp-btn-sm">✏️ Sửa code</button>
            </div>

            <div class="lp-export-bar">
                <button id="btn-download-html" class="lp-btn-export lp-btn-download">📥 Tải HTML</button>
            </div>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: LỊCH SỬ
         ═══════════════════════════════════════════ -->
    <section id="tab-history" class="lp-tab">

        <div class="lp-section-title" style="margin-bottom:14px">📋 Landing page đã lưu</div>

        <div id="history-list" class="lp-history-list"></div>

        <div id="history-empty" class="lp-empty hidden">
            <div class="lp-empty-icon">📭</div>
            <p>Chưa có landing page nào.<br>Tạo landing page đầu tiên ngay!</p>
        </div>

        <div id="history-loading" class="lp-loading hidden">
            <div class="lp-spinner"></div>
            <div class="lp-loading-text">Đang tải...</div>
        </div>

        <div id="history-more" class="lp-load-more hidden">
            <button id="btn-load-more" class="lp-btn lp-btn-outline lp-btn-sm">Xem thêm</button>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: EDITOR
         ═══════════════════════════════════════════ -->
    <section id="tab-editor" class="lp-tab">

        <div class="lp-editor-toolbar">
            <input type="text" id="editor-title" placeholder="Tiêu đề landing page...">
            <div class="lp-view-btns">
                <button class="lp-view-btn active" data-view="code">📝</button>
                <button class="lp-view-btn" data-view="split">⬜</button>
                <button class="lp-view-btn" data-view="preview">👁</button>
            </div>
        </div>

        <div class="lp-editor-actions">
            <button id="btn-editor-render" class="lp-btn lp-btn-outline lp-btn-sm">▶ Preview</button>
            <button id="btn-editor-save" class="lp-btn lp-btn-primary lp-btn-sm">💾 Lưu</button>
            <button id="btn-editor-download" class="lp-btn-export lp-btn-download lp-btn-sm">📥 HTML</button>
            <span class="lp-editor-status" id="editor-status"></span>
        </div>

        <div class="lp-editor-body" data-view="code">
            <div class="lp-editor-code">
                <textarea id="editor-code" spellcheck="false"
                    placeholder="&lt;!DOCTYPE html&gt;&#10;&lt;html&gt;&#10;&lt;head&gt;...&lt;/head&gt;&#10;&lt;body&gt;&#10;  &lt;!-- Paste or edit HTML here --&gt;&#10;&lt;/body&gt;&#10;&lt;/html&gt;"></textarea>
            </div>
            <div class="lp-editor-preview-pane">
                <iframe id="editor-preview" sandbox="allow-scripts allow-same-origin"></iframe>
            </div>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: MEDIA
         ═══════════════════════════════════════════ -->
    <section id="tab-media" class="lp-tab">

        <div class="lp-section-title">🖼️ Thư viện ảnh</div>
        <p style="font-size:12px;color:var(--c-muted);margin-bottom:12px">Upload ảnh → copy URL → paste vào code HTML để thay thế ảnh placeholder.</p>

        <div class="lp-upload-zone" id="upload-zone">
            <div class="lp-upload-zone-icon">📤</div>
            <p>Kéo thả ảnh hoặc <strong>click để chọn file</strong></p>
            <small>JPG, PNG, WebP, GIF — tối đa 5MB</small>
        </div>
        <input type="file" id="media-file-input" accept="image/*" multiple style="display:none">

        <div id="media-grid" class="lp-media-grid"></div>

    </section>

    <?php endif; ?>

</main><!-- /.lp-main -->

<!-- ════════ View Modal ════════ -->
<div id="view-modal" class="lp-modal">
    <div class="lp-modal-header">
        <button class="lp-modal-back" id="modal-close">← Quay lại</button>
        <h2 id="modal-title">Landing Page</h2>
        <div class="lp-modal-actions">
            <button id="modal-download" title="Tải HTML">📥</button>
            <button id="modal-edit" title="Sửa">✏️</button>
            <button id="modal-delete" title="Xóa" style="color:var(--c-danger)">🗑️</button>
        </div>
    </div>
    <div class="lp-modal-body">
        <iframe id="modal-preview" sandbox="allow-scripts allow-same-origin"></iframe>
    </div>
</div>

<!-- ════════ Bottom Navigation ════════ -->
<?php if ( $is_logged_in ): ?>
<nav class="lp-nav">
    <button class="lp-nav-item active" data-tab="create">
        <span class="lp-nav-icon">✨</span>
        <span class="lp-nav-label">Tạo mới</span>
    </button>
    <button class="lp-nav-item" data-tab="history">
        <span class="lp-nav-icon">📋</span>
        <span class="lp-nav-label">Lịch sử</span>
    </button>
    <button class="lp-nav-item" data-tab="editor">
        <span class="lp-nav-icon">✏️</span>
        <span class="lp-nav-label">Editor</span>
    </button>
    <button class="lp-nav-item" data-tab="media">
        <span class="lp-nav-icon">🖼️</span>
        <span class="lp-nav-label">Media</span>
    </button>
</nav>
<?php endif; ?>

<!-- ════════ Toast ════════ -->
<div id="toast" class="lp-toast"></div>

<?php if ( $is_logged_in ): ?>
<script>
(function() {
'use strict';

/* ══════════════════════════════════════════════════
   Config
   ══════════════════════════════════════════════════ */
var CFG = {
    ajax: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce: <?php echo json_encode( wp_create_nonce( 'bztool_landing' ) ); ?>,
    openId: <?php echo $open_id; ?>,
};

var TYPE_ICONS = {
    saas:'💻', education:'📚', health:'🏥', beauty:'💅', fnb:'🍔',
    finance:'💰', 'real-estate':'🏠', event:'🎪', app:'📱', service:'🔧',
    ecommerce:'🛒', consulting:'📊', nonprofit:'💚', portfolio:'🎨',
    other:'🚀', 'default':'🚀'
};

/* ══════════════════════════════════════════════════
   State
   ══════════════════════════════════════════════════ */
var currentTab     = 'create';
var generatedData  = null;   // { title, html, description }
var editorPostId   = 0;
var historyPage    = 0;
var historyTotal   = 0;
var historyLoaded  = false;
var modalPostId    = 0;
var uploadedMedia  = [];     // [{ id, url, filename }]

/* ══════════════════════════════════════════════════
   Init
   ══════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    bindEvents();
    if (CFG.openId) {
        loadLandingIntoEditor(CFG.openId);
        switchTab('editor');
    }
});

/* ══════════════════════════════════════════════════
   Events
   ══════════════════════════════════════════════════ */
function bindEvents() {
    // Bottom nav tabs
    document.querySelectorAll('.lp-nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
    });

    // Type chips
    document.querySelectorAll('.lp-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.lp-chip').forEach(function(c){ c.classList.remove('active'); });
            this.classList.add('active');
        });
    });

    // Generate button
    el('btn-generate').addEventListener('click', doGenerate);

    // Enter on prompt (Ctrl+Enter)
    el('prompt-input').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
            e.preventDefault();
            doGenerate();
        }
    });

    // Save from create tab
    el('btn-save').addEventListener('click', doSaveFromCreate);

    // Open editor from create
    el('btn-to-editor').addEventListener('click', function() {
        if (generatedData) {
            el('editor-title').value = generatedData.title || '';
            el('editor-code').value  = generatedData.html || '';
            editorPostId = generatedData.post_id || 0;
            renderEditorPreview();
        }
        switchTab('editor');
    });

    // Device preview buttons
    document.querySelectorAll('.lp-device-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.lp-device-btn').forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');
            var w = this.dataset.width;
            el('create-preview').style.width = w;
            el('device-label').textContent = w;
        });
    });

    // Download HTML from create
    el('btn-download-html').addEventListener('click', function() {
        if (generatedData && generatedData.html) {
            downloadHTML(generatedData.html, generatedData.title || 'landing-page');
        }
    });

    // Editor view toggles
    document.querySelectorAll('.lp-view-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.lp-view-btn').forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');
            el('editor-code').closest('.lp-editor-body').dataset.view = this.dataset.view;
            if (this.dataset.view !== 'code') renderEditorPreview();
        });
    });

    // Editor render button
    el('btn-editor-render').addEventListener('click', renderEditorPreview);

    // Editor save
    el('btn-editor-save').addEventListener('click', doEditorSave);

    // Editor download
    el('btn-editor-download').addEventListener('click', function() {
        var code = el('editor-code').value.trim();
        if (code) downloadHTML(code, el('editor-title').value.trim() || 'landing-page');
    });

    // Editor live preview (debounced)
    var editorTimeout;
    el('editor-code').addEventListener('input', function() {
        clearTimeout(editorTimeout);
        editorTimeout = setTimeout(function() {
            var view = el('editor-code').closest('.lp-editor-body').dataset.view;
            if (view !== 'code') renderEditorPreview();
        }, 800);
    });

    // Tab keyboard for code editor
    el('editor-code').addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var s = this.selectionStart, end = this.selectionEnd;
            this.value = this.value.substring(0, s) + '  ' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = s + 2;
        }
    });

    // Load more history
    el('btn-load-more').addEventListener('click', function() { loadHistory(); });

    // Modal
    el('modal-close').addEventListener('click', closeModal);
    el('modal-edit').addEventListener('click', function() {
        closeModal();
        loadLandingIntoEditor(modalPostId);
        switchTab('editor');
    });
    el('modal-delete').addEventListener('click', function() {
        if (confirm('Xóa landing page này?')) {
            deleteLanding(modalPostId, function() {
                closeModal();
                historyLoaded = false;
                if (currentTab === 'history') loadHistory(true);
            });
        }
    });
    el('modal-download').addEventListener('click', function() {
        if (!modalPostId) return;
        ajax('bztool_lp_get', { post_id: modalPostId }, function(res) {
            if (res.success && res.data.html) {
                downloadHTML(res.data.html, res.data.title || 'landing-page');
            }
        });
    });

    // Media upload zone
    var uploadZone = el('upload-zone');
    var fileInput  = el('media-file-input');

    uploadZone.addEventListener('click', function() { fileInput.click(); });
    uploadZone.addEventListener('dragover', function(e) {
        e.preventDefault(); this.classList.add('dragover');
    });
    uploadZone.addEventListener('dragleave', function() { this.classList.remove('dragover'); });
    uploadZone.addEventListener('drop', function(e) {
        e.preventDefault(); this.classList.remove('dragover');
        handleFileUpload(e.dataTransfer.files);
    });
    fileInput.addEventListener('change', function() {
        handleFileUpload(this.files);
        this.value = '';
    });
}

/* ══════════════════════════════════════════════════
   Tab Switching
   ══════════════════════════════════════════════════ */
function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.lp-tab').forEach(function(t) { t.classList.remove('active'); });
    var target = el('tab-' + tab);
    if (target) target.classList.add('active');
    document.querySelectorAll('.lp-nav-item').forEach(function(n) { n.classList.remove('active'); });
    var navBtn = document.querySelector('[data-tab="' + tab + '"]');
    if (navBtn) navBtn.classList.add('active');

    if (tab === 'history' && !historyLoaded) loadHistory(true);
    if (tab === 'editor') {
        var view = el('editor-code').closest('.lp-editor-body').dataset.view;
        if (view !== 'code') renderEditorPreview();
    }
}

/* ══════════════════════════════════════════════════
   CREATE: Generate Landing Page
   ══════════════════════════════════════════════════ */
function doGenerate() {
    var prompt = el('prompt-input').value.trim();
    var activeChip = document.querySelector('.lp-chip.active');
    var productType = activeChip ? activeChip.dataset.type : 'other';
    var template = el('template-select').value;

    if (!prompt) { toast('Nhập mô tả sản phẩm/dịch vụ bạn muốn tạo landing page!'); return; }

    el('btn-generate').disabled = true;
    el('create-result').classList.remove('show');
    show('create-loading');

    ajax('bztool_lp_generate', { prompt: prompt, product_type: productType, template: template }, function(res) {
        if (!res.success) {
            hide('create-loading');
            el('btn-generate').disabled = false;
            toast(res.data && res.data.message ? res.data.message : 'Lỗi tạo landing page');
            return;
        }

        // Async: poll for result
        pollGenerateJob(res.data.job_id, 0);
    });
}

function pollGenerateJob(jobId, tick) {
    ajax('bztool_lp_generate_status', { job_id: jobId }, function(res) {
        if (res.success && res.data && res.data.status === 'processing') {
            var elapsed = res.data.elapsed || tick * 3;
            var loadText = el('create-loading').querySelector('.lp-loading-text');
            if (loadText) {
                loadText.innerHTML = 'AI đang thiết kế landing page... ✨<br><small style="color:#9ca3af">Đã ' + elapsed + 's — thường mất 60-120 giây</small>';
            }
            setTimeout(function() { pollGenerateJob(jobId, tick + 1); }, 3000);
            return;
        }

        hide('create-loading');
        el('btn-generate').disabled = false;

        if (!res.success || !res.data) {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi tạo landing page');
            return;
        }

        generatedData = res.data;
        generatedData.post_id = 0;
        el('save-title').value = res.data.title || '';

        // Render into iframe
        writeToIframe('create-preview', res.data.html);

        el('create-result').classList.add('show');
    });
}

/* ══════════════════════════════════════════════════
   CREATE: Save
   ══════════════════════════════════════════════════ */
function doSaveFromCreate() {
    if (!generatedData || !generatedData.html) { toast('Chưa có landing page để lưu'); return; }

    var title = el('save-title').value.trim() || generatedData.title || '';

    el('btn-save').disabled = true;

    ajax('bztool_lp_save', {
        title:        title,
        html:         generatedData.html,
        product_type: document.querySelector('.lp-chip.active') ? document.querySelector('.lp-chip.active').dataset.type : 'other',
        template:     el('template-select').value,
        prompt:       el('prompt-input').value.trim(),
        description:  generatedData.description || '',
        post_id:      generatedData.post_id || 0,
    }, function(res) {
        el('btn-save').disabled = false;

        if (res.success) {
            generatedData.post_id = res.data.post_id;
            editorPostId = res.data.post_id;
            toast('💾 Đã lưu: ' + (res.data.title || title));
            historyLoaded = false;
        } else {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi lưu');
        }
    });
}

/* ══════════════════════════════════════════════════
   HISTORY: Load List
   ══════════════════════════════════════════════════ */
function loadHistory(reset) {
    if (reset) {
        historyPage = 0;
        el('history-list').innerHTML = '';
    }

    historyPage++;
    show('history-loading');
    hide('history-empty');
    hide('history-more');

    ajax('bztool_lp_list', { page: historyPage }, function(res) {
        hide('history-loading');

        if (!res.success) { toast('Lỗi tải lịch sử'); return; }

        historyLoaded = true;
        historyTotal  = res.data.total;
        var items     = res.data.items || [];

        if (items.length === 0 && historyPage === 1) {
            show('history-empty');
            return;
        }

        items.forEach(function(item) {
            el('history-list').appendChild(createHistoryCard(item));
        });

        if (historyPage < res.data.pages) {
            show('history-more');
        }
    });
}

function createHistoryCard(item) {
    var icon = TYPE_ICONS[item.product_type] || TYPE_ICONS['default'];
    var card = document.createElement('div');
    card.className = 'lp-hcard';
    card.innerHTML =
        '<div class="lp-hcard-icon">' + icon + '</div>' +
        '<div class="lp-hcard-body">' +
            '<div class="lp-hcard-title">' + esc(item.title) + '</div>' +
            '<div class="lp-hcard-meta">' +
                '<span>' + esc(item.product_type || 'other') + '</span>' +
                '<span>' + esc(item.date) + '</span>' +
            '</div>' +
            (item.description ? '<div class="lp-hcard-desc">' + esc(item.description) + '</div>' : '') +
        '</div>' +
        '<div class="lp-hcard-actions">' +
            '<button class="lp-edit" title="Sửa">✏️</button>' +
            '<button class="lp-del" title="Xóa">🗑️</button>' +
        '</div>';

    card.querySelector('.lp-hcard-body').addEventListener('click', function() {
        openModal(item.id);
    });
    card.querySelector('.lp-hcard-icon').addEventListener('click', function() {
        openModal(item.id);
    });

    card.querySelector('.lp-edit').addEventListener('click', function(e) {
        e.stopPropagation();
        loadLandingIntoEditor(item.id);
        switchTab('editor');
    });

    card.querySelector('.lp-del').addEventListener('click', function(e) {
        e.stopPropagation();
        if (confirm('Xóa "' + item.title + '"?')) {
            deleteLanding(item.id, function() {
                card.remove();
                historyTotal--;
                if (historyTotal <= 0) show('history-empty');
            });
        }
    });

    return card;
}

/* ══════════════════════════════════════════════════
   VIEW MODAL
   ══════════════════════════════════════════════════ */
function openModal(postId) {
    modalPostId = postId;
    el('modal-title').textContent = 'Đang tải...';
    el('view-modal').classList.add('show');

    ajax('bztool_lp_get', { post_id: postId }, function(res) {
        if (!res.success) {
            el('modal-title').textContent = 'Lỗi';
            return;
        }
        el('modal-title').textContent = res.data.title || 'Landing Page';
        writeToIframe('modal-preview', res.data.html);
    });
}

function closeModal() {
    el('view-modal').classList.remove('show');
    modalPostId = 0;
}

/* ══════════════════════════════════════════════════
   EDITOR: Load landing page
   ══════════════════════════════════════════════════ */
function loadLandingIntoEditor(postId) {
    ajax('bztool_lp_get', { post_id: postId }, function(res) {
        if (!res.success) { toast('Không thể tải landing page'); return; }
        editorPostId = postId;
        el('editor-title').value = res.data.title || '';
        el('editor-code').value  = res.data.html || '';
        el('editor-status').textContent = 'Đã lưu · ID: ' + postId;

        // Auto-switch to split view
        var body = el('editor-code').closest('.lp-editor-body');
        body.dataset.view = 'split';
        document.querySelectorAll('.lp-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === 'split');
        });

        renderEditorPreview();
    });
}

/* ══════════════════════════════════════════════════
   EDITOR: Render preview
   ══════════════════════════════════════════════════ */
function renderEditorPreview() {
    var code = el('editor-code').value.trim();
    if (!code) return;

    var body = el('editor-code').closest('.lp-editor-body');
    if (body.dataset.view === 'code') {
        body.dataset.view = 'split';
        document.querySelectorAll('.lp-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === 'split');
        });
    }

    writeToIframe('editor-preview', code);
}

/* ══════════════════════════════════════════════════
   EDITOR: Save
   ══════════════════════════════════════════════════ */
function doEditorSave() {
    var code  = el('editor-code').value.trim();
    var title = el('editor-title').value.trim();

    if (!code) { toast('Code trống!'); return; }

    el('btn-editor-save').disabled = true;

    if (editorPostId > 0) {
        ajax('bztool_lp_update', { post_id: editorPostId, html: code, title: title }, function(res) {
            el('btn-editor-save').disabled = false;
            if (res.success) {
                toast('💾 Đã cập nhật');
                el('editor-status').textContent = 'Đã lưu · ID: ' + editorPostId;
                historyLoaded = false;
            } else {
                toast(res.data && res.data.message ? res.data.message : 'Lỗi lưu');
            }
        });
    } else {
        ajax('bztool_lp_save', { title: title, html: code, product_type: 'other' }, function(res) {
            el('btn-editor-save').disabled = false;
            if (res.success) {
                editorPostId = res.data.post_id;
                toast('💾 Đã lưu mới: ' + (res.data.title || title));
                el('editor-status').textContent = 'ID: ' + editorPostId;
                historyLoaded = false;
            } else {
                toast(res.data && res.data.message ? res.data.message : 'Lỗi lưu');
            }
        });
    }
}

/* ══════════════════════════════════════════════════
   MEDIA: File Upload
   ══════════════════════════════════════════════════ */
function handleFileUpload(files) {
    if (!files || !files.length) return;

    for (var i = 0; i < files.length; i++) {
        var file = files[i];
        if (!file.type.startsWith('image/')) { toast('Chỉ hỗ trợ file ảnh'); continue; }
        if (file.size > 5 * 1024 * 1024) { toast('File quá lớn (max 5MB)'); continue; }

        uploadSingleFile(file);
    }
}

function uploadSingleFile(file) {
    var fd = new FormData();
    fd.append('action', 'bztool_lp_upload_media');
    fd.append('nonce', CFG.nonce);
    fd.append('file', file);

    toast('Đang upload: ' + file.name + '...');

    fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.success) {
                uploadedMedia.push(res.data);
                addMediaItem(res.data);
                toast('✅ Đã upload: ' + res.data.filename);
            } else {
                toast(res.data && res.data.message ? res.data.message : 'Lỗi upload');
            }
        })
        .catch(function(e) { toast('Lỗi upload: ' + e.message); });
}

function addMediaItem(item) {
    var grid = el('media-grid');
    var div  = document.createElement('div');
    div.className = 'lp-media-item';
    div.innerHTML =
        '<img src="' + esc(item.url) + '" alt="' + esc(item.filename) + '">' +
        '<div class="lp-media-item-url">' + esc(item.url) + '</div>' +
        '<span class="lp-copy-badge">📋 Copy</span>';

    div.addEventListener('click', function() {
        copyToClipboard(item.url);
        toast('📋 Đã copy URL: ' + item.filename);
    });

    grid.insertBefore(div, grid.firstChild);
}

/* ══════════════════════════════════════════════════
   Delete Landing Page
   ══════════════════════════════════════════════════ */
function deleteLanding(postId, callback) {
    ajax('bztool_lp_delete', { post_id: postId }, function(res) {
        if (res.success) {
            toast('🗑️ Đã xóa');
            if (callback) callback();
        } else {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi xóa');
        }
    });
}

/* ══════════════════════════════════════════════════
   Write HTML into iframe (sandboxed)
   ══════════════════════════════════════════════════ */
function writeToIframe(iframeId, html) {
    var iframe = el(iframeId);
    if (!iframe) return;

    var doc = iframe.contentDocument || iframe.contentWindow.document;
    doc.open();
    doc.write(html);
    doc.close();
}

/* ══════════════════════════════════════════════════
   Download HTML file
   ══════════════════════════════════════════════════ */
function downloadHTML(html, title) {
    var blob = new Blob([html], { type: 'text/html;charset=utf-8' });
    var url  = URL.createObjectURL(blob);
    var a    = document.createElement('a');
    a.href     = url;
    a.download = (title || 'landing-page').replace(/[^a-zA-Z0-9\u00C0-\u024F\s-]/g, '').trim().replace(/\s+/g, '-') + '.html';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

/* ══════════════════════════════════════════════════
   Copy to clipboard
   ══════════════════════════════════════════════════ */
function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}

/* ══════════════════════════════════════════════════
   AJAX helper
   ══════════════════════════════════════════════════ */
function ajax(action, data, callback) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', CFG.nonce);
    for (var k in data) {
        if (data.hasOwnProperty(k)) fd.append(k, data[k]);
    }

    fetch(CFG.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(j) { if (callback) callback(j); })
        .catch(function(e) {
            console.error('[LandingStudio]', e);
            if (callback) callback({ success: false, data: { message: 'Lỗi kết nối: ' + e.message } });
        });
}

/* ══════════════════════════════════════════════════
   Toast
   ══════════════════════════════════════════════════ */
var toastTimer;
function toast(msg) {
    var t = el('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() { t.classList.remove('show'); }, 2500);
}

/* ══════════════════════════════════════════════════
   Tiny helpers
   ══════════════════════════════════════════════════ */
function el(id) { return document.getElementById(id); }
function show(id) { el(id).classList.remove('hidden'); }
function hide(id) { el(id).classList.add('hidden'); }
function esc(s) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s || ''));
    return d.innerHTML;
}

})();
</script>
<?php endif; ?>

</body>
</html>
