<?php
/**
 * BizCity Tool Slide — Mobile-First SPA
 *
 * Full Reveal.js Slide Studio: Tạo mới từ prompt AI + Lịch sử + Editor tương tác + Trình chiếu.
 * Bottom tab navigation, responsive, hoạt động cả standalone lẫn iframe.
 *
 * @package BizCity_Tool_Slide
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
<title>Slide Studio — BizCity</title>
<style>
/* ══════════════════════════════════════════════════
   CSS Variables & Reset
   ══════════════════════════════════════════════════ */
:root {
    --c-primary: #6366f1;
    --c-primary-light: #818cf8;
    --c-primary-bg: #eef2ff;
    --c-secondary: #8b5cf6;
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
input, textarea { font: inherit; color: inherit; }

/* ══════════════════════════════════════════════════
   Layout: Header + Main + Bottom Nav
   ══════════════════════════════════════════════════ */
.sl-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 50;
    height: var(--header-h);
    display: flex; align-items: center; gap: 10px;
    padding: 0 16px;
    background: rgba(255,255,255,.88);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--c-border);
}
.sl-header-logo { font-size: 22px; }
.sl-header h1 { font-size: 16px; font-weight: 700; }
.sl-header-badge {
    font-size: 10px; font-weight: 600;
    padding: 2px 8px; border-radius: 6px;
    background: var(--c-primary-bg); color: var(--c-primary);
}

.sl-main {
    position: fixed;
    top: var(--header-h); bottom: calc(var(--nav-h) + var(--safe-b));
    left: 0; right: 0;
    overflow: hidden;
}
.sl-tab {
    display: none;
    position: absolute; inset: 0;
    overflow-y: auto;
    padding: 16px 16px 24px;
    -webkit-overflow-scrolling: touch;
}
.sl-tab.active { display: block; }

.sl-nav {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 50;
    height: calc(var(--nav-h) + var(--safe-b));
    padding-bottom: var(--safe-b);
    display: flex; align-items: stretch;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-top: 1px solid var(--c-border);
}
.sl-nav-item {
    flex: 1;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 2px;
    font-size: 10px; font-weight: 500; color: var(--c-muted);
    transition: color .2s;
    -webkit-tap-highlight-color: transparent;
}
.sl-nav-item .sl-nav-icon { font-size: 22px; line-height: 1; }
.sl-nav-item.active { color: var(--c-primary); font-weight: 700; }

/* ══════════════════════════════════════════════════
   Shared Components
   ══════════════════════════════════════════════════ */
.sl-section-title {
    font-size: 14px; font-weight: 700; color: var(--c-text);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.sl-chips {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.sl-chip {
    padding: 6px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 500;
    background: var(--c-surface); border: 1px solid var(--c-border);
    color: var(--c-muted);
    transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.sl-chip.active {
    background: var(--c-primary); color: #fff;
    border-color: var(--c-primary);
}

.sl-prompt-box {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 14px;
}
.sl-prompt-box textarea {
    width: 100%; border: none; outline: none;
    resize: none; min-height: 80px;
    font-size: 14px; line-height: 1.5;
    background: transparent;
}
.sl-prompt-box textarea::placeholder { color: #b0b8c4; }
.sl-prompt-actions {
    display: flex; justify-content: flex-end; gap: 8px;
    margin-top: 8px;
}

.sl-btn {
    padding: 10px 20px; border-radius: 10px;
    font-size: 14px; font-weight: 600;
    transition: all .15s;
    display: inline-flex; align-items: center; gap: 6px;
}
.sl-btn-primary {
    background: linear-gradient(135deg, var(--c-primary), var(--c-secondary));
    color: #fff;
    box-shadow: 0 2px 10px rgba(99,102,241,.3);
}
.sl-btn-primary:hover { box-shadow: 0 4px 16px rgba(99,102,241,.4); }
.sl-btn-primary:active { transform: scale(.97); }
.sl-btn-primary:disabled {
    opacity: .5; cursor: not-allowed;
    box-shadow: none; transform: none;
}
.sl-btn-outline {
    background: var(--c-surface);
    border: 1px solid var(--c-border); color: var(--c-text);
}
.sl-btn-outline:hover { border-color: var(--c-primary); color: var(--c-primary); }
.sl-btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 8px; }
.sl-btn-danger { background: var(--c-danger); color: #fff; }

/* Loading */
.sl-loading { text-align: center; padding: 32px 16px; }
.sl-spinner {
    width: 36px; height: 36px;
    border: 3px solid var(--c-border);
    border-top-color: var(--c-primary);
    border-radius: 50%;
    animation: spin .8s linear infinite;
    margin: 0 auto 12px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.sl-loading-text { font-size: 13px; color: var(--c-muted); }

/* Preview area */
.sl-preview-wrap {
    background: #222;
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 12px;
    position: relative;
    aspect-ratio: 16/9;
}
.sl-preview-wrap iframe {
    width: 100%; height: 100%; border: none;
    background: #222;
}
.sl-preview-error {
    color: var(--c-danger); font-size: 13px;
    padding: 12px; text-align: center;
}
.sl-preview-placeholder {
    display: flex; align-items: center; justify-content: center;
    color: #666; font-size: 14px;
    position: absolute; inset: 0;
}

/* Result actions */
.sl-result { display: none; }
.sl-result.show { display: block; }
.sl-result-bar {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.sl-result-bar input[type=text] {
    flex: 1; min-width: 0;
    padding: 8px 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none;
    font-size: 14px;
}
.sl-result-bar input[type=text]:focus { border-color: var(--c-primary); }

/* Code peek */
.sl-code-peek { margin-top: 10px; }
.sl-code-toggle {
    font-size: 12px; color: var(--c-primary);
    cursor: pointer; user-select: none;
    display: inline-flex; align-items: center; gap: 4px;
}
.sl-code-block {
    display: none; margin-top: 8px;
    background: #1e1e2e; color: #cdd6f4;
    border-radius: var(--radius-sm);
    padding: 12px; font-size: 12px;
    font-family: "Fira Code", "Cascadia Code", Consolas, monospace;
    white-space: pre-wrap; word-break: break-all;
    max-height: 200px; overflow: auto;
    line-height: 1.6;
}
.sl-code-block.show { display: block; }

/* ── Template Grid ── */
.sl-tpl-section { margin-bottom: 14px; }
.sl-tpl-toggle {
    font-size: 13px; font-weight: 600; color: var(--c-primary);
    cursor: pointer; user-select: none;
    display: inline-flex; align-items: center; gap: 4px;
    margin-bottom: 8px;
}
.sl-tpl-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 8px;
    max-height: 240px;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    padding: 2px;
}
.sl-tpl-grid.collapsed { display: none; }
.sl-tpl-card {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius-sm);
    padding: 10px 8px;
    text-align: center;
    cursor: pointer;
    transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.sl-tpl-card:hover { border-color: var(--c-primary-light); background: var(--c-primary-bg); }
.sl-tpl-card:active { transform: scale(.96); }
.sl-tpl-card-icon { font-size: 22px; display: block; margin-bottom: 4px; }
.sl-tpl-card-name { font-size: 11px; font-weight: 600; color: var(--c-text); line-height: 1.3; }

/* ── Export group ── */
.sl-export-group { display: flex; gap: 4px; }

/* ══════════════════════════════════════════════════
   HISTORY TAB
   ══════════════════════════════════════════════════ */
.sl-history-list { display: flex; flex-direction: column; gap: 10px; }
.sl-hcard {
    display: flex; align-items: flex-start; gap: 12px;
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 14px;
    cursor: pointer; transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.sl-hcard:hover { border-color: #c7d2fe; box-shadow: 0 2px 12px rgba(99,102,241,.1); }
.sl-hcard:active { transform: scale(.98); }
.sl-hcard-icon {
    width: 42px; height: 42px; border-radius: 12px;
    background: var(--c-primary-bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.sl-hcard-body { flex: 1; min-width: 0; }
.sl-hcard-title {
    font-size: 14px; font-weight: 600; color: var(--c-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sl-hcard-meta {
    font-size: 11px; color: var(--c-muted); margin-top: 3px;
    display: flex; gap: 8px;
}
.sl-hcard-desc {
    font-size: 12px; color: var(--c-muted); margin-top: 4px;
    line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.sl-hcard-actions {
    display: flex; flex-direction: column; gap: 4px;
    flex-shrink: 0;
}
.sl-hcard-actions button {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; transition: background .15s;
}
.sl-hcard-actions button:hover { background: #f1f5f9; }
.sl-hcard-actions .sl-del:hover { background: #fef2f2; color: var(--c-danger); }

.sl-empty { text-align: center; padding: 48px 20px; }
.sl-empty-icon { font-size: 48px; margin-bottom: 12px; }
.sl-empty p { color: var(--c-muted); font-size: 14px; }
.sl-load-more { text-align: center; padding: 12px; }

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

.sl-editor-toolbar {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0; flex-wrap: wrap;
}
.sl-editor-toolbar input[type=text] {
    flex: 1; min-width: 0;
    padding: 6px 10px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none; font-size: 13px;
}
.sl-editor-toolbar input[type=text]:focus { border-color: var(--c-primary); }

.sl-editor-toolbar select {
    padding: 5px 8px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); font-size: 12px;
    background: var(--c-surface); color: var(--c-text);
    outline: none;
}
.sl-editor-toolbar select:focus { border-color: var(--c-primary); }

.sl-view-btns {
    display: flex; gap: 2px;
    background: #f1f5f9; border-radius: var(--radius-sm);
    padding: 2px;
}
.sl-view-btn {
    padding: 5px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600; color: var(--c-muted);
    transition: all .15s;
}
.sl-view-btn.active { background: var(--c-surface); color: var(--c-text); box-shadow: 0 1px 3px rgba(0,0,0,.08); }

.sl-editor-actions {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0;
    flex-wrap: wrap;
}
.sl-editor-status {
    flex: 1; font-size: 11px; color: var(--c-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.sl-editor-body {
    flex: 1; display: flex; overflow: hidden;
    min-height: 0;
}
.sl-editor-code {
    flex: 1; display: flex; flex-direction: column;
    border-right: 1px solid var(--c-border);
    min-width: 0;
}
.sl-editor-code textarea {
    flex: 1; width: 100%; border: none; outline: none; resize: none;
    padding: 12px;
    font-family: "Fira Code", "Cascadia Code", Consolas, monospace;
    font-size: 13px; line-height: 1.6;
    background: #1e1e2e; color: #cdd6f4;
    tab-size: 2;
}
.sl-editor-code textarea::placeholder { color: #585b70; }

.sl-editor-preview-pane {
    flex: 1;
    overflow: hidden;
    background: #222;
    min-width: 0;
    position: relative;
}
.sl-editor-preview-pane iframe {
    width: 100%; height: 100%; border: none;
}

/* Editor views: code-only, preview-only, split */
.sl-editor-body[data-view="code"]    .sl-editor-preview-pane { display: none; }
.sl-editor-body[data-view="preview"] .sl-editor-code { display: none; }

/* Mobile: split stacked */
@media (max-width: 767px) {
    .sl-editor-body[data-view="split"] {
        flex-direction: column;
    }
    .sl-editor-body[data-view="split"] .sl-editor-code {
        border-right: none;
        border-bottom: 1px solid var(--c-border);
        max-height: 45%;
    }
}

/* ══════════════════════════════════════════════════
   VIEW MODAL (overlay when tapping history item)
   ══════════════════════════════════════════════════ */
.sl-modal {
    display: none;
    position: fixed; inset: 0; z-index: 100;
    background: var(--c-bg);
    flex-direction: column;
}
.sl-modal.show { display: flex; }
.sl-modal-header {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
}
.sl-modal-header h2 {
    flex: 1; font-size: 15px; font-weight: 700;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sl-modal-back {
    font-size: 13px; color: var(--c-primary);
    font-weight: 600; padding: 6px;
    display: flex; align-items: center; gap: 2px;
}
.sl-modal-actions { display: flex; gap: 4px; }
.sl-modal-actions button {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; transition: background .15s;
}
.sl-modal-actions button:hover { background: #f1f5f9; }
.sl-modal-body {
    flex: 1; overflow: hidden;
    position: relative;
    background: #222;
}
.sl-modal-body iframe {
    width: 100%; height: 100%; border: none;
}

/* ══════════════════════════════════════════════════
   FULLSCREEN presentation overlay
   ══════════════════════════════════════════════════ */
.sl-fullscreen {
    display: none;
    position: fixed; inset: 0; z-index: 200;
    background: #000;
}
.sl-fullscreen.show { display: block; }
.sl-fullscreen iframe {
    width: 100%; height: 100%; border: none;
}
.sl-fullscreen-close {
    position: fixed; top: 12px; right: 12px; z-index: 210;
    width: 40px; height: 40px; border-radius: 50%;
    background: rgba(0,0,0,.6); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; cursor: pointer;
    transition: background .2s;
    backdrop-filter: blur(8px);
}
.sl-fullscreen-close:hover { background: rgba(0,0,0,.9); }

/* ══════════════════════════════════════════════════
   TOAST
   ══════════════════════════════════════════════════ */
.sl-toast {
    position: fixed;
    bottom: calc(var(--nav-h) + var(--safe-b) + 12px);
    left: 50%; transform: translateX(-50%) translateY(20px);
    background: #1f2937; color: #fff;
    padding: 10px 20px; border-radius: 10px;
    font-size: 13px; font-weight: 500;
    box-shadow: 0 4px 16px rgba(0,0,0,.2);
    z-index: 300;
    opacity: 0; transition: all .3s ease;
    pointer-events: none; white-space: nowrap;
}
.sl-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ══════════════════════════════════════════════════
   LOGIN SCREEN
   ══════════════════════════════════════════════════ */
.sl-login { text-align: center; padding: 60px 24px; }
.sl-login .sl-login-icon { font-size: 56px; margin-bottom: 16px; }
.sl-login h2 { font-size: 20px; margin-bottom: 8px; }
.sl-login p { color: var(--c-muted); font-size: 14px; margin-bottom: 24px; }
.sl-login-btn {
    display: inline-block; padding: 12px 32px;
    background: linear-gradient(135deg, var(--c-primary), var(--c-secondary));
    color: #fff; border-radius: 12px; text-decoration: none;
    font-weight: 600; font-size: 15px;
}

/* Utility */
.hidden { display: none !important; }
</style>
</head>
<body>

<!-- ════════ Header ════════ -->
<header class="sl-header">
    <span class="sl-header-logo">🎬</span>
    <h1>Slide Studio</h1>
    <span class="sl-header-badge">Reveal.js</span>
</header>

<!-- ════════ Main Content ════════ -->
<main class="sl-main">

    <?php if ( ! $is_logged_in ): ?>
    <section class="sl-tab active">
        <div class="sl-login">
            <div class="sl-login-icon">🎬</div>
            <h2>Slide Studio</h2>
            <p>Đăng nhập để tạo bài trình bày bằng AI</p>
            <a href="<?php echo wp_login_url( home_url( '/tool-slide/' ) ); ?>" class="sl-login-btn">Đăng nhập</a>
        </div>
    </section>

    <?php else: ?>

    <!-- ═══════════════════════════════════════════
         TAB: TẠO MỚI
         ═══════════════════════════════════════════ -->
    <section id="tab-create" class="sl-tab active">

        <div class="sl-section-title">🎨 Theme slide</div>
        <div class="sl-chips">
            <button class="sl-chip active" data-theme="auto">🤖 Auto</button>
            <button class="sl-chip" data-theme="white">⬜ White</button>
            <button class="sl-chip" data-theme="black">⬛ Black</button>
            <button class="sl-chip" data-theme="moon">🌙 Moon</button>
            <button class="sl-chip" data-theme="night">🌃 Night</button>
            <button class="sl-chip" data-theme="serif">📜 Serif</button>
            <button class="sl-chip" data-theme="simple">🔲 Simple</button>
            <button class="sl-chip" data-theme="solarized">☀️ Solarized</button>
            <button class="sl-chip" data-theme="blood">🔴 Blood</button>
            <button class="sl-chip" data-theme="league">🏆 League</button>
        </div>

        <div class="sl-tpl-section">
            <span class="sl-tpl-toggle" id="tpl-toggle">📦 Template có sẵn ▾</span>
            <div class="sl-tpl-grid" id="tpl-grid"></div>
        </div>

        <div class="sl-prompt-box">
            <textarea id="prompt-input" placeholder="Mô tả kịch bản bài trình bày...&#10;VD: Thuyết trình chiến lược Digital Marketing Q2 2026, bao gồm phân tích thị trường, mục tiêu, kênh triển khai, ngân sách và KPI"></textarea>
            <div class="sl-prompt-actions">
                <button id="btn-generate" class="sl-btn sl-btn-primary">🎬 Tạo Slide</button>
            </div>
        </div>

        <!-- Loading state -->
        <div id="create-loading" class="sl-loading hidden">
            <div class="sl-spinner"></div>
            <div class="sl-loading-text">AI đang tạo bài trình bày...</div>
        </div>

        <!-- Result area -->
        <div id="create-result" class="sl-result">
            <div class="sl-result-bar">
                <input type="text" id="save-title" placeholder="Tiêu đề bài trình bày...">
                <button id="btn-save" class="sl-btn sl-btn-primary sl-btn-sm">💾 Lưu</button>
                <button id="btn-to-editor" class="sl-btn sl-btn-outline sl-btn-sm">✏️ Sửa</button>
                <button id="btn-present-create" class="sl-btn sl-btn-outline sl-btn-sm">🖥 Trình chiếu</button>
                <div class="sl-export-group">
                    <button id="btn-export-pdf" class="sl-btn sl-btn-outline sl-btn-sm">📄 PDF</button>
                    <button id="btn-export-pptx" class="sl-btn sl-btn-outline sl-btn-sm">📑 PPTX</button>
                </div>
            </div>

            <div class="sl-preview-wrap" id="create-preview-wrap">
                <div class="sl-preview-placeholder" id="create-placeholder">Slide preview sẽ hiện ở đây</div>
                <iframe id="create-preview-frame" style="display:none"></iframe>
            </div>

            <div class="sl-code-peek">
                <span class="sl-code-toggle" id="toggle-code">▶ Xem HTML code</span>
                <pre class="sl-code-block" id="create-code"></pre>
            </div>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: LỊCH SỬ
         ═══════════════════════════════════════════ -->
    <section id="tab-history" class="sl-tab">

        <div class="sl-section-title" style="margin-bottom:14px">📋 Slide đã lưu</div>

        <div id="history-list" class="sl-history-list"></div>

        <div id="history-empty" class="sl-empty hidden">
            <div class="sl-empty-icon">📭</div>
            <p>Chưa có bài trình bày nào.<br>Tạo slide đầu tiên ngay!</p>
        </div>

        <div id="history-loading" class="sl-loading hidden">
            <div class="sl-spinner"></div>
            <div class="sl-loading-text">Đang tải...</div>
        </div>

        <div id="history-more" class="sl-load-more hidden">
            <button id="btn-load-more" class="sl-btn sl-btn-outline sl-btn-sm">Xem thêm</button>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: EDITOR
         ═══════════════════════════════════════════ -->
    <section id="tab-editor" class="sl-tab">

        <div class="sl-editor-toolbar">
            <input type="text" id="editor-title" placeholder="Tiêu đề slide...">
            <select id="editor-theme">
                <option value="white">White</option>
                <option value="black">Black</option>
                <option value="moon">Moon</option>
                <option value="night">Night</option>
                <option value="serif">Serif</option>
                <option value="simple">Simple</option>
                <option value="solarized">Solarized</option>
                <option value="blood">Blood</option>
                <option value="beige">Beige</option>
                <option value="league">League</option>
            </select>
            <div class="sl-view-btns">
                <button class="sl-view-btn active" data-view="code">📝</button>
                <button class="sl-view-btn" data-view="split">⬜</button>
                <button class="sl-view-btn" data-view="preview">👁</button>
            </div>
        </div>

        <div class="sl-editor-actions">
            <button id="btn-editor-render" class="sl-btn sl-btn-outline sl-btn-sm">▶ Render</button>
            <button id="btn-editor-save" class="sl-btn sl-btn-primary sl-btn-sm">💾 Lưu</button>
            <button id="btn-editor-present" class="sl-btn sl-btn-outline sl-btn-sm">🖥 Trình chiếu</button>
            <button id="btn-editor-pdf" class="sl-btn sl-btn-outline sl-btn-sm">📄 PDF</button>
            <button id="btn-editor-pptx" class="sl-btn sl-btn-outline sl-btn-sm">📑 PPTX</button>
            <span class="sl-editor-status" id="editor-status"></span>
        </div>

        <div class="sl-editor-body" data-view="code">
            <div class="sl-editor-code">
                <textarea id="editor-code" spellcheck="false"
                    placeholder="<section>&#10;  <h1>Tiêu đề bài trình bày</h1>&#10;  <p>Phụ đề</p>&#10;</section>&#10;&#10;<section>&#10;  <h2>Slide 2</h2>&#10;  <ul>&#10;    <li>Nội dung 1</li>&#10;    <li>Nội dung 2</li>&#10;  </ul>&#10;</section>"></textarea>
            </div>
            <div class="sl-editor-preview-pane" id="editor-preview">
                <iframe id="editor-preview-frame"></iframe>
            </div>
        </div>

    </section>

    <?php endif; ?>

</main><!-- /.sl-main -->

<!-- ════════ View Modal ════════ -->
<div id="view-modal" class="sl-modal">
    <div class="sl-modal-header">
        <button class="sl-modal-back" id="modal-close">← Quay lại</button>
        <h2 id="modal-title">Bài trình bày</h2>
        <div class="sl-modal-actions">
            <button id="modal-present" title="Trình chiếu">🖥</button>
            <button id="modal-edit" title="Sửa">✏️</button>
            <button id="modal-delete" title="Xóa" style="color:var(--c-danger)">🗑️</button>
        </div>
    </div>
    <div class="sl-modal-body" id="modal-preview">
        <iframe id="modal-preview-frame"></iframe>
    </div>
</div>

<!-- ════════ Fullscreen Presentation ════════ -->
<div id="fullscreen-overlay" class="sl-fullscreen">
    <iframe id="fullscreen-frame"></iframe>
    <button class="sl-fullscreen-close" id="fullscreen-close">✕</button>
</div>

<!-- ════════ Bottom Navigation ════════ -->
<?php if ( $is_logged_in ): ?>
<nav class="sl-nav">
    <button class="sl-nav-item active" data-tab="create">
        <span class="sl-nav-icon">✨</span>
        <span class="sl-nav-label">Tạo mới</span>
    </button>
    <button class="sl-nav-item" data-tab="history">
        <span class="sl-nav-icon">📋</span>
        <span class="sl-nav-label">Lịch sử</span>
    </button>
    <button class="sl-nav-item" data-tab="editor">
        <span class="sl-nav-icon">✏️</span>
        <span class="sl-nav-label">Editor</span>
    </button>
</nav>
<?php endif; ?>

<!-- ════════ Toast ════════ -->
<div id="toast" class="sl-toast"></div>

<?php if ( $is_logged_in ): ?>
<script src="https://cdn.jsdelivr.net/npm/pptxgenjs@3/dist/pptxgen.bundle.js"></script>
<script>
(function() {
'use strict';

/* ══════════════════════════════════════════════════
   Config
   ══════════════════════════════════════════════════ */
const CFG = {
    ajax: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce: <?php echo json_encode( wp_create_nonce( 'bztool_slide' ) ); ?>,
    openId: <?php echo $open_id; ?>,
    revealCss: 'https://cdn.jsdelivr.net/npm/reveal.js@5/dist/reveal.css',
    revealJs:  'https://cdn.jsdelivr.net/npm/reveal.js@5/dist/reveal.esm.js',
    themeBase: 'https://cdn.jsdelivr.net/npm/reveal.js@5/dist/theme/',
};

const THEME_ICONS = {
    white:'⬜', black:'⬛', moon:'🌙', night:'🌃', serif:'📜',
    simple:'🔲', solarized:'☀️', blood:'🔴', beige:'🟤', league:'🏆'
};

/* ══════════════════════════════════════════════════
   State
   ══════════════════════════════════════════════════ */
let currentTab     = 'create';
let generatedData  = null;   // { title, theme, slides_html, description, slide_count }
let editorPostId   = 0;
let editorTheme    = 'white';
let historyPage    = 0;
let historyTotal   = 0;
let historyLoaded  = false;
let modalPostId    = 0;
let modalSlidesHtml = '';
let modalTheme     = 'white';

const TEMPLATES = [
    // === Business ===
    { name:'Chiến lược Marketing', icon:'📊', theme:'white', prompt:'Thuyết trình chiến lược Marketing Digital cho doanh nghiệp năm 2026. Bao gồm: phân tích thị trường, đối thủ cạnh tranh, mục tiêu, chiến lược kênh (SEO, Ads, Social Media), ngân sách dự kiến và KPI đo lường.' },
    { name:'Kế hoạch Kinh doanh', icon:'💼', theme:'serif', prompt:'Trình bày kế hoạch kinh doanh hoàn chỉnh. Bao gồm: tổng quan doanh nghiệp, sứ mệnh tầm nhìn, phân tích thị trường, mô hình kinh doanh, chiến lược tăng trưởng, tài chính dự kiến 3 năm, đội ngũ và lộ trình triển khai.' },
    { name:'Pitch Deck Startup', icon:'🚀', theme:'night', prompt:'Tạo pitch deck cho startup công nghệ gọi vốn. Bao gồm: vấn đề giải quyết, giải pháp, thị trường tiềm năng, sản phẩm demo, mô hình kinh doanh, traction, đội ngũ sáng lập, tài chính và mục tiêu gọi vốn.' },
    { name:'Báo cáo Quý', icon:'📈', theme:'simple', prompt:'Báo cáo kết quả kinh doanh quý. Bao gồm: tổng quan, doanh thu lợi nhuận, so sánh quý trước, top sản phẩm/dịch vụ, thị trường chính, thách thức giải pháp, dự báo quý tiếp.' },
    { name:'Giới thiệu Công ty', icon:'🏢', theme:'league', prompt:'Giới thiệu tổng quan công ty cho đối tác/khách hàng. Bao gồm: lịch sử hình thành, sứ mệnh tầm nhìn, sản phẩm dịch vụ chính, thành tựu nổi bật, đội ngũ lãnh đạo, khách hàng tiêu biểu, liên hệ hợp tác.' },
    { name:'Ra mắt Sản phẩm', icon:'🎯', theme:'black', prompt:'Thuyết trình ra mắt sản phẩm mới. Bao gồm: bối cảnh thị trường, pain point khách hàng, giải pháp sản phẩm, tính năng chính, USP, demo/screenshot, pricing, roadmap, chiến lược go-to-market.' },
    { name:'Chiến lược Bán hàng', icon:'💰', theme:'white', prompt:'Trình bày chiến lược bán hàng. Bao gồm: thị trường mục tiêu, persona khách hàng, kênh bán hàng, quy trình sales funnel, pricing strategy, chỉ tiêu team, CRM, KPI đo lường.' },
    { name:'Báo cáo Tài chính', icon:'💵', theme:'serif', prompt:'Báo cáo tài chính thường niên. Bao gồm: bảng cân đối kế toán, báo cáo thu nhập, dòng tiền, chỉ số ROE ROA, biên lợi nhuận, phân tích xu hướng, dự báo.' },
    // === Marketing ===
    { name:'Social Media Plan', icon:'📱', theme:'moon', prompt:'Kế hoạch Social Media Marketing. Bao gồm: phân tích kênh hiện tại, đối tượng mục tiêu, content pillar, lịch đăng bài, chiến lược tăng follower, quảng cáo paid, budget, KPI và tools đo lường.' },
    { name:'Content Strategy', icon:'✍️', theme:'solarized', prompt:'Chiến lược Content Marketing toàn diện. Bao gồm: mục tiêu content, buyer persona, content audit, chủ đề chính, loại content (blog, video, podcast), SEO keyword, lịch editorial, đo lường.' },
    { name:'Brand Guidelines', icon:'🎨', theme:'simple', prompt:'Bộ nhận diện thương hiệu. Bao gồm: brand story, giá trị cốt lõi, logo usage, bảng màu, typography, hình ảnh phong cách, tone of voice, ứng dụng trên các kênh.' },
    { name:'SEO Strategy', icon:'🔍', theme:'white', prompt:'Chiến lược SEO tối ưu. Bao gồm: audit website, nghiên cứu keyword, on-page, technical SEO, content SEO, link building, local SEO, công cụ SEO, timeline, KPI tracking.' },
    { name:'Email Marketing', icon:'📧', theme:'moon', prompt:'Chiến lược Email Marketing. Bao gồm: xây dựng list, segmentation, email flows (welcome, nurture, cart abandon), template design, A/B testing, deliverability, automation, KPI (open rate, CTR, conversion).' },
    // === Tech ===
    { name:'Kiến trúc Hệ thống', icon:'🏗️', theme:'night', prompt:'Kiến trúc hệ thống phần mềm. Bao gồm: tổng quan high-level, microservices/monolith, database, API design, authentication, caching, CDN, monitoring, CI/CD, security, scalability.' },
    { name:'Product Roadmap', icon:'🗺️', theme:'league', prompt:'Product Roadmap. Bao gồm: vision sản phẩm, current state, user feedback, prioritization, Q1-Q4 milestones, feature epics, technical debt, resource, release plan, success metrics.' },
    { name:'Sprint Review', icon:'🏃', theme:'simple', prompt:'Sprint Review. Bao gồm: sprint goal, completed stories, demo features, velocity chart, burn-down, bugs resolved, technical improvements, team achievements, retrospective, next sprint.' },
    { name:'AI & Machine Learning', icon:'🤖', theme:'black', prompt:'Trình bày về AI và Machine Learning. Bao gồm: tổng quan AI, các loại ML (supervised, unsupervised, reinforcement), deep learning, NLP, computer vision, ứng dụng thực tế, frameworks, challenges, tương lai AI.' },
    { name:'Cybersecurity', icon:'🔒', theme:'blood', prompt:'Chiến lược Cybersecurity. Bao gồm: threat landscape, common attacks (phishing, ransomware, DDoS), framework bảo mật, authentication, encryption, network security, incident response, compliance, training.' },
    // === Education ===
    { name:'Đào tạo Nhân viên', icon:'👨‍🏫', theme:'white', prompt:'Chương trình đào tạo nhân viên mới. Bao gồm: giới thiệu công ty, văn hóa doanh nghiệp, quy trình làm việc, công cụ, chính sách nhân sự, KPI vị trí, mentorship, lộ trình phát triển.' },
    { name:'Khóa học Online', icon:'🎓', theme:'moon', prompt:'Khung chương trình khóa học online. Bao gồm: mục tiêu học tập, đối tượng học viên, chương trình chi tiết (module 1-8), phương pháp giảng dạy, bài tập thực hành, đánh giá, chứng chỉ.' },
    { name:'Nghiên cứu Khoa học', icon:'🔬', theme:'serif', prompt:'Kết quả nghiên cứu khoa học. Bao gồm: bối cảnh, câu hỏi/giả thuyết, phương pháp, thu thập dữ liệu, phân tích kết quả, biểu đồ/bảng số liệu, thảo luận, kết luận, hướng nghiên cứu tiếp.' },
    { name:'Workshop Kỹ năng', icon:'🛠️', theme:'solarized', prompt:'Workshop kỹ năng mềm. Bao gồm: communication, teamwork, time management, problem solving, leadership, emotional intelligence, bài tập nhóm, role play, takeaways.' },
    // === Creative ===
    { name:'Portfolio Showcase', icon:'🎨', theme:'black', prompt:'Showcase portfolio thiết kế/sáng tạo. Bao gồm: giới thiệu bản thân, phong cách, dự án tiêu biểu (5-7 case), quy trình, client testimonials, giải thưởng, dịch vụ, liên hệ.' },
    { name:'Design System', icon:'🧩', theme:'simple', prompt:'Design System. Bao gồm: design principles, color palette, typography, spacing, component library (buttons, forms, cards, navigation), icons, responsive, accessibility, usage examples.' },
    // === Events & Others ===
    { name:'Kế hoạch Sự kiện', icon:'🏪', theme:'league', prompt:'Kế hoạch tổ chức sự kiện. Bao gồm: tổng quan, mục tiêu, đối tượng, agenda, diễn giả/khách mời, logistics (venue, F&B, tech), ngân sách, marketing event, đo lường.' },
    { name:'Đề xuất Dự án', icon:'📋', theme:'white', prompt:'Đề xuất dự án mới. Bao gồm: bối cảnh, vấn đề, giải pháp, phạm vi, phương pháp triển khai, timeline, resource, ngân sách, rủi ro giải pháp, expected outcomes.' },
    { name:'Phân tích SWOT', icon:'📊', theme:'moon', prompt:'Phân tích SWOT. Bao gồm: Strengths, Weaknesses, Opportunities, Threats, ma trận SWOT, chiến lược SO/ST/WO/WT, action plan, kết luận.' },
    { name:'Team Building', icon:'🤝', theme:'solarized', prompt:'Kế hoạch Team Building. Bao gồm: mục tiêu, chủ đề, hoạt động ice-breaking, team games, outdoor adventure, cooking challenge, awards, feedback, takeaways, kỷ niệm.' },
    { name:'Sức khỏe & Wellness', icon:'🧘', theme:'simple', prompt:'Sức khỏe và Wellness. Bao gồm: tầm quan trọng sức khỏe, dinh dưỡng, tập luyện, quản lý stress, ngủ đủ giấc, mindfulness, work-life balance, thói quen tốt hàng ngày.' },
    { name:'Bất động Sản', icon:'🏠', theme:'league', prompt:'Giới thiệu dự án bất động sản. Bao gồm: tổng quan, vị trí, thiết kế kiến trúc, tiện ích nội khu, mặt bằng, giá bán chính sách, tiến độ, pháp lý, ngân hàng hỗ trợ.' },
    { name:'Du lịch & Lịch trình', icon:'✈️', theme:'moon', prompt:'Lịch trình du lịch hấp dẫn. Bao gồm: điểm đến, lịch trình từng ngày, điểm tham quan, ẩm thực địa phương, chỗ ở, di chuyển, ngân sách, tips du lịch.' },
    { name:'Đánh giá Hiệu suất', icon:'📊', theme:'white', prompt:'Performance Review. Bao gồm: tổng quan kết quả, KPI đạt được, dự án hoàn thành, điểm mạnh, cần cải thiện, feedback peers, mục tiêu tiếp theo, kế hoạch phát triển cá nhân.' },
    { name:'E-commerce Strategy', icon:'🛒', theme:'blood', prompt:'Chiến lược E-commerce. Bao gồm: thị trường online, nền tảng (Shopee, Tiki, website), sản phẩm chủ lực, pricing, logistics, customer experience, marketing, conversion, analytics, growth.' },
    { name:'Chuyển đổi Số', icon:'💻', theme:'night', prompt:'Chuyển đổi Số, Digital Transformation. Bao gồm: đánh giá hiện trạng, tầm nhìn Digital, lộ trình (phase 1-3), công nghệ (Cloud, AI, IoT, Big Data), đào tạo nhân sự, ngân sách, đo lường ROI.' },
];

/* ══════════════════════════════════════════════════
   Init
   ══════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    bindEvents();
    renderTemplates();
    if (CFG.openId) {
        loadSlideIntoEditor(CFG.openId);
        switchTab('editor');
    }
});

/* ══════════════════════════════════════════════════
   Events
   ══════════════════════════════════════════════════ */
function bindEvents() {
    // Bottom nav tabs
    document.querySelectorAll('.sl-nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
    });

    // Theme chips
    document.querySelectorAll('.sl-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.sl-chip').forEach(function(c){ c.classList.remove('active'); });
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
            el('editor-code').value  = generatedData.slides_html || '';
            el('editor-theme').value = generatedData.theme || 'white';
            editorPostId = generatedData.post_id || 0;
            editorTheme  = generatedData.theme || 'white';
            renderEditorPreview();
        }
        switchTab('editor');
    });

    // Present from create tab
    el('btn-present-create').addEventListener('click', function() {
        if (generatedData && generatedData.slides_html) {
            openFullscreen(generatedData.slides_html, generatedData.theme || 'white');
        }
    });

    // Toggle code peek
    el('toggle-code').addEventListener('click', function() {
        var block = el('create-code');
        var open  = block.classList.toggle('show');
        this.textContent = (open ? '▼' : '▶') + ' Xem HTML code';
    });

    // Editor view toggles
    document.querySelectorAll('.sl-view-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.sl-view-btn').forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');
            el('editor-code').closest('.sl-editor-body').dataset.view = this.dataset.view;
            if (this.dataset.view !== 'code') renderEditorPreview();
        });
    });

    // Editor render button
    el('btn-editor-render').addEventListener('click', renderEditorPreview);

    // Editor save
    el('btn-editor-save').addEventListener('click', doEditorSave);

    // Editor present (fullscreen)
    el('btn-editor-present').addEventListener('click', function() {
        var code = el('editor-code').value.trim();
        if (code) {
            openFullscreen(code, el('editor-theme').value);
        } else {
            toast('Chưa có nội dung slide!');
        }
    });

    // Editor theme change
    el('editor-theme').addEventListener('change', function() {
        editorTheme = this.value;
        var view = el('editor-code').closest('.sl-editor-body').dataset.view;
        if (view !== 'code') renderEditorPreview();
    });

    // Editor live preview (debounced)
    var editorTimeout;
    el('editor-code').addEventListener('input', function() {
        clearTimeout(editorTimeout);
        editorTimeout = setTimeout(function() {
            var view = el('editor-code').closest('.sl-editor-body').dataset.view;
            if (view !== 'code') renderEditorPreview();
        }, 800);
    });

    // Tab keyboard for code editor
    el('editor-code').addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var s = this.selectionStart, end = this.selectionEnd;
            this.value = this.value.substring(0, s) + '    ' + this.value.substring(end);
            this.selectionStart = this.selectionEnd = s + 4;
        }
    });

    // Load more history
    el('btn-load-more').addEventListener('click', function() { loadHistory(); });

    // Modal
    el('modal-close').addEventListener('click', closeModal);
    el('modal-edit').addEventListener('click', function() {
        closeModal();
        loadSlideIntoEditor(modalPostId);
        switchTab('editor');
    });
    el('modal-present').addEventListener('click', function() {
        if (modalSlidesHtml) {
            openFullscreen(modalSlidesHtml, modalTheme);
        }
    });
    el('modal-delete').addEventListener('click', function() {
        if (confirm('Xóa bài trình bày này?')) {
            deleteSlide(modalPostId, function() {
                closeModal();
                historyLoaded = false;
                if (currentTab === 'history') loadHistory(true);
            });
        }
    });

    // Fullscreen close
    el('fullscreen-close').addEventListener('click', closeFullscreen);
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeFullscreen();
    });

    // Template toggle
    el('tpl-toggle').addEventListener('click', function() {
        var grid = el('tpl-grid');
        var collapsed = grid.classList.toggle('collapsed');
        this.textContent = '📦 Template có sẵn ' + (collapsed ? '▸' : '▾');
    });

    // Export PDF/PPTX from create tab
    el('btn-export-pdf').addEventListener('click', function() {
        if (!generatedData || !generatedData.slides_html) { toast('Chưa có slide!'); return; }
        exportPDF(generatedData.slides_html, generatedData.theme || 'white', el('save-title').value.trim() || generatedData.title || 'slide');
    });
    el('btn-export-pptx').addEventListener('click', function() {
        if (!generatedData || !generatedData.slides_html) { toast('Chưa có slide!'); return; }
        exportPPTX(generatedData.slides_html, el('save-title').value.trim() || generatedData.title || 'slide');
    });

    // Export PDF/PPTX from editor tab
    el('btn-editor-pdf').addEventListener('click', function() {
        var code = el('editor-code').value.trim();
        if (!code) { toast('Chưa có nội dung!'); return; }
        exportPDF(code, el('editor-theme').value, el('editor-title').value.trim() || 'slide');
    });
    el('btn-editor-pptx').addEventListener('click', function() {
        var code = el('editor-code').value.trim();
        if (!code) { toast('Chưa có nội dung!'); return; }
        exportPPTX(code, el('editor-title').value.trim() || 'slide');
    });
}

/* ══════════════════════════════════════════════════
   Tab Switching
   ══════════════════════════════════════════════════ */
function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.sl-tab').forEach(function(t) { t.classList.remove('active'); });
    var target = el('tab-' + tab);
    if (target) target.classList.add('active');
    document.querySelectorAll('.sl-nav-item').forEach(function(n) { n.classList.remove('active'); });
    var navBtn = document.querySelector('[data-tab="' + tab + '"]');
    if (navBtn) navBtn.classList.add('active');

    if (tab === 'history' && !historyLoaded) loadHistory(true);
    if (tab === 'editor') {
        var view = el('editor-code').closest('.sl-editor-body').dataset.view;
        if (view !== 'code') renderEditorPreview();
    }
}

/* ══════════════════════════════════════════════════
   CREATE: Generate Slides
   ══════════════════════════════════════════════════ */
function doGenerate() {
    var prompt = el('prompt-input').value.trim();
    var activeChip = document.querySelector('.sl-chip.active');
    var theme = activeChip ? activeChip.dataset.theme : 'auto';

    if (!prompt) { toast('Nhập mô tả kịch bản bài trình bày!'); return; }

    el('btn-generate').disabled = true;
    el('create-result').classList.remove('show');
    show('create-loading');

    ajax('bztool_sl_generate', { prompt: prompt, theme: theme }, function(res) {
        hide('create-loading');
        el('btn-generate').disabled = false;

        if (!res.success) {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi tạo slide');
            return;
        }

        generatedData = res.data;
        generatedData.post_id = 0;
        el('save-title').value = res.data.title || '';
        el('create-code').textContent = res.data.slides_html || '';

        // Render preview in iframe
        renderSlidePreview(res.data.slides_html, res.data.theme || 'white', 'create-preview-frame');
        el('create-placeholder').style.display = 'none';
        el('create-preview-frame').style.display = 'block';
        el('create-result').classList.add('show');
    });
}

/* ══════════════════════════════════════════════════
   CREATE: Save
   ══════════════════════════════════════════════════ */
function doSaveFromCreate() {
    if (!generatedData || !generatedData.slides_html) { toast('Chưa có slide để lưu'); return; }

    var title = el('save-title').value.trim() || generatedData.title || '';

    el('btn-save').disabled = true;

    ajax('bztool_sl_save', {
        title:       title,
        slides_html: generatedData.slides_html,
        theme:       generatedData.theme || 'white',
        prompt:      el('prompt-input').value.trim(),
        description: generatedData.description || '',
        post_id:     generatedData.post_id || 0,
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

    ajax('bztool_sl_list', { page: historyPage }, function(res) {
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
    var icon = THEME_ICONS[item.theme] || '🎬';
    var card = document.createElement('div');
    card.className = 'sl-hcard';
    card.innerHTML =
        '<div class="sl-hcard-icon">' + icon + '</div>' +
        '<div class="sl-hcard-body">' +
            '<div class="sl-hcard-title">' + esc(item.title) + '</div>' +
            '<div class="sl-hcard-meta">' +
                '<span>' + esc(item.theme) + ' · ' + item.slide_count + ' slides</span>' +
                '<span>' + esc(item.date) + '</span>' +
            '</div>' +
            (item.description ? '<div class="sl-hcard-desc">' + esc(item.description) + '</div>' : '') +
        '</div>' +
        '<div class="sl-hcard-actions">' +
            '<button class="sl-edit" title="Sửa">✏️</button>' +
            '<button class="sl-del" title="Xóa">🗑️</button>' +
        '</div>';

    // Tap card body → view modal
    card.querySelector('.sl-hcard-body').addEventListener('click', function() {
        openModal(item.id);
    });
    card.querySelector('.sl-hcard-icon').addEventListener('click', function() {
        openModal(item.id);
    });

    // Edit button
    card.querySelector('.sl-edit').addEventListener('click', function(e) {
        e.stopPropagation();
        loadSlideIntoEditor(item.id);
        switchTab('editor');
    });

    // Delete button
    card.querySelector('.sl-del').addEventListener('click', function(e) {
        e.stopPropagation();
        if (confirm('Xóa "' + item.title + '"?')) {
            deleteSlide(item.id, function() {
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

    ajax('bztool_sl_get', { post_id: postId }, function(res) {
        if (!res.success) {
            el('modal-title').textContent = 'Lỗi tải';
            return;
        }
        el('modal-title').textContent = res.data.title || 'Bài trình bày';
        modalSlidesHtml = res.data.slides_html || '';
        modalTheme      = res.data.theme || 'white';
        renderSlidePreview(modalSlidesHtml, modalTheme, 'modal-preview-frame');
    });
}

function closeModal() {
    el('view-modal').classList.remove('show');
    modalPostId = 0;
    modalSlidesHtml = '';
}

/* ══════════════════════════════════════════════════
   EDITOR: Load slide
   ══════════════════════════════════════════════════ */
function loadSlideIntoEditor(postId) {
    ajax('bztool_sl_get', { post_id: postId }, function(res) {
        if (!res.success) { toast('Không thể tải bài trình bày'); return; }
        editorPostId = postId;
        el('editor-title').value = res.data.title || '';
        el('editor-code').value  = res.data.slides_html || '';
        el('editor-theme').value = res.data.theme || 'white';
        editorTheme = res.data.theme || 'white';
        el('editor-status').textContent = 'Đã lưu · ID: ' + postId + ' · ' + (res.data.slide_count || 0) + ' slides';

        // Auto-switch to split view so the slide renders immediately
        var body = el('editor-code').closest('.sl-editor-body');
        body.dataset.view = 'split';
        document.querySelectorAll('.sl-view-btn').forEach(function(b) {
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
    if (!code) {
        el('editor-preview-frame').srcdoc = '<p style="color:#999;text-align:center;padding:24px;font-family:sans-serif">Nhập HTML slide bên trái để xem preview</p>';
        return;
    }

    // Auto-switch to split view if currently code-only
    var body = el('editor-code').closest('.sl-editor-body');
    if (body.dataset.view === 'code') {
        body.dataset.view = 'split';
        document.querySelectorAll('.sl-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === 'split');
        });
    }

    var theme = el('editor-theme').value || 'white';
    renderSlidePreview(code, theme, 'editor-preview-frame');
}

/* ══════════════════════════════════════════════════
   EDITOR: Save
   ══════════════════════════════════════════════════ */
function doEditorSave() {
    var code  = el('editor-code').value.trim();
    var title = el('editor-title').value.trim();
    var theme = el('editor-theme').value;

    if (!code) { toast('Code trống!'); return; }

    el('btn-editor-save').disabled = true;

    if (editorPostId > 0) {
        ajax('bztool_sl_update', { post_id: editorPostId, slides_html: code, title: title, theme: theme }, function(res) {
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
        ajax('bztool_sl_save', { title: title, slides_html: code, theme: theme }, function(res) {
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
   Reveal.js Render (iframe-based)
   ══════════════════════════════════════════════════ */
function buildRevealHTML(slidesHtml, theme) {
    theme = theme || 'white';
    return '<!DOCTYPE html><html><head>'
        + '<meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<link rel="stylesheet" href="' + CFG.revealCss + '">'
        + '<link rel="stylesheet" href="' + CFG.themeBase + theme + '.css">'
        + '<style>'
        + 'html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; }'
        + '.reveal { height: 100%; }'
        + '.reveal h1 { font-size: 1.8em; }'
        + '.reveal h2 { font-size: 1.3em; margin-bottom: 0.5em; }'
        + '.reveal h3 { font-size: 1.1em; }'
        + '.reveal ul, .reveal ol { text-align: left; font-size: 0.85em; }'
        + '.reveal li { margin-bottom: 0.35em; line-height: 1.5; }'
        + '.reveal img { border-radius: 12px; max-width: 100%; height: auto; box-shadow: 0 4px 20px rgba(0,0,0,.12); }'
        + '.reveal blockquote { font-style: italic; border-left: 4px solid #6366f1; padding: 12px 20px; background: rgba(99,102,241,.05); border-radius: 0 12px 12px 0; margin: 15px 0; font-size: 0.9em; }'
        + '.reveal p { line-height: 1.6; }'
        + '.reveal section { padding: 20px 40px; }'
        + '.reveal strong { color: inherit; font-weight: 700; }'
        + '.reveal .slides > section > section { padding: 20px 40px; }'
        + '</style>'
        + '</head><body>'
        + '<div class="reveal"><div class="slides">'
        + slidesHtml
        + '</div></div>'
        + '<script type="module">'
        + 'import Reveal from "' + CFG.revealJs + '";'
        + 'Reveal.initialize({'
        + '  hash: false,'
        + '  history: false,'
        + '  embedded: true,'
        + '  respondToHashChanges: false,'
        + '  controls: true,'
        + '  progress: true,'
        + '  center: true,'
        + '  transition: "slide",'
        + '  width: 960,'
        + '  height: 540'
        + '});'
        + '<\/script>'
        + '</body></html>';
}

function buildRevealHTMLFullscreen(slidesHtml, theme) {
    theme = theme || 'white';
    return '<!DOCTYPE html><html><head>'
        + '<meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<link rel="stylesheet" href="' + CFG.revealCss + '">'
        + '<link rel="stylesheet" href="' + CFG.themeBase + theme + '.css">'
        + '<style>'
        + 'html, body { margin: 0; padding: 0; width: 100%; height: 100%; overflow: hidden; }'
        + '.reveal { height: 100%; }'
        + '.reveal h1 { font-size: 2em; }'
        + '.reveal h2 { font-size: 1.5em; margin-bottom: 0.5em; }'
        + '.reveal h3 { font-size: 1.2em; }'
        + '.reveal ul, .reveal ol { text-align: left; }'
        + '.reveal li { margin-bottom: 0.4em; line-height: 1.6; }'
        + '.reveal img { border-radius: 12px; max-width: 100%; height: auto; box-shadow: 0 4px 20px rgba(0,0,0,.12); }'
        + '.reveal blockquote { font-style: italic; border-left: 4px solid #6366f1; padding: 12px 20px; background: rgba(99,102,241,.05); border-radius: 0 12px 12px 0; margin: 15px 0; }'
        + '.reveal p { line-height: 1.6; }'
        + '.reveal section { padding: 20px 40px; }'
        + '.reveal strong { color: inherit; font-weight: 700; }'
        + '</style>'
        + '</head><body>'
        + '<div class="reveal"><div class="slides">'
        + slidesHtml
        + '</div></div>'
        + '<script type="module">'
        + 'import Reveal from "' + CFG.revealJs + '";'
        + 'Reveal.initialize({'
        + '  hash: false,'
        + '  history: false,'
        + '  respondToHashChanges: false,'
        + '  controls: true,'
        + '  progress: true,'
        + '  center: true,'
        + '  transition: "slide",'
        + '  width: 960,'
        + '  height: 700'
        + '});'
        + '<\/script>'
        + '</body></html>';
}

function renderSlidePreview(slidesHtml, theme, iframeId) {
    var iframe = el(iframeId);
    if (!iframe) return;
    iframe.srcdoc = buildRevealHTML(slidesHtml, theme);
}

/* ══════════════════════════════════════════════════
   Fullscreen Presentation
   ══════════════════════════════════════════════════ */
function openFullscreen(slidesHtml, theme) {
    el('fullscreen-frame').srcdoc = buildRevealHTMLFullscreen(slidesHtml, theme);
    el('fullscreen-overlay').classList.add('show');
    document.body.style.overflow = 'hidden';
}

function closeFullscreen() {
    el('fullscreen-overlay').classList.remove('show');
    el('fullscreen-frame').srcdoc = '';
    document.body.style.overflow = '';
}

/* ══════════════════════════════════════════════════
   Delete Slide
   ══════════════════════════════════════════════════ */
function deleteSlide(postId, callback) {
    ajax('bztool_sl_delete', { post_id: postId }, function(res) {
        if (res.success) {
            toast('🗑️ Đã xóa');
            if (callback) callback();
        } else {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi xóa');
        }
    });
}

/* ══════════════════════════════════════════════════
   Templates
   ══════════════════════════════════════════════════ */
function renderTemplates() {
    var grid = el('tpl-grid');
    if (!grid) return;
    TEMPLATES.forEach(function(tpl) {
        var card = document.createElement('div');
        card.className = 'sl-tpl-card';
        card.innerHTML = '<span class="sl-tpl-card-icon">' + tpl.icon + '</span><span class="sl-tpl-card-name">' + esc(tpl.name) + '</span>';
        card.addEventListener('click', function() { applyTemplate(tpl); });
        grid.appendChild(card);
    });
}

function applyTemplate(tpl) {
    el('prompt-input').value = tpl.prompt;
    if (tpl.theme) {
        document.querySelectorAll('.sl-chip').forEach(function(c) {
            c.classList.toggle('active', c.dataset.theme === tpl.theme);
        });
    }
    el('prompt-input').focus();
    el('prompt-input').scrollIntoView({ behavior: 'smooth', block: 'center' });
    toast('📦 Template: ' + tpl.name);
}

/* ══════════════════════════════════════════════════
   Export PDF
   ══════════════════════════════════════════════════ */
function exportPDF(slidesHtml, theme, title) {
    var w = window.open('', '_blank');
    if (!w) { toast('Popup bị chặn! Hãy cho phép popup.'); return; }

    w.document.write(
        '<!DOCTYPE html><html><head><meta charset="utf-8">'
        + '<title>' + esc(title || 'Slide') + '</title>'
        + '<link rel="stylesheet" href="' + CFG.revealCss + '">'
        + '<link rel="stylesheet" href="' + CFG.themeBase + (theme || 'white') + '.css">'
        + '<style>'
        + '@page { size: A4 landscape; margin: 0; }'
        + '*, *::before, *::after { -webkit-print-color-adjust: exact; print-color-adjust: exact; }'
        + 'html, body { margin: 0; background: #fff; }'
        + '.reveal { position: relative; }'
        + '.reveal .slides { position: relative !important; width: auto !important; height: auto !important; left: auto !important; top: auto !important; margin: 0 !important; transform: none !important; pointer-events: auto !important; perspective: none !important; }'
        + '.reveal .slides > section, .reveal .slides > section > section { display: flex !important; flex-direction: column; justify-content: center; position: relative !important; width: 960px !important; min-height: 540px !important; margin: 0 auto !important; padding: 40px 60px !important; box-sizing: border-box !important; page-break-after: always !important; transform: none !important; opacity: 1 !important; visibility: visible !important; transition: none !important; }'
        + '.reveal .slides > section:last-child { page-break-after: auto !important; }'
        + '.reveal .controls, .reveal .progress, .reveal .slide-number, .reveal .backgrounds { display: none !important; }'
        + '.reveal img { border-radius: 12px; max-width: 100%; height: auto; box-shadow: 0 4px 20px rgba(0,0,0,.12); }'
        + '.reveal blockquote { border-left: 4px solid #6366f1; padding: 12px 20px; background: rgba(99,102,241,.05); border-radius: 0 12px 12px 0; }'
        + '.reveal .fragment { opacity: 1 !important; visibility: visible !important; }'
        + '@media screen { .reveal .slides > section, .reveal .slides > section > section { border: 1px solid #e5e7eb; margin: 20px auto !important; box-shadow: 0 2px 12px rgba(0,0,0,.08); border-radius: 4px; } body { background: #f5f5f5; padding: 20px; } }'
        + '</style>'
        + '</head><body>'
        + '<div class="reveal"><div class="slides">' + slidesHtml + '</div></div>'
        + '<script>window.addEventListener("load",function(){ setTimeout(function(){ window.print(); },800); });<\/script>'
        + '</body></html>'
    );
    w.document.close();
    toast('📄 Tab mới đã mở -- Chọn Save as PDF trong hộp thoại in');
}

/* ══════════════════════════════════════════════════
   Export PPTX (PptxGenJS)
   ══════════════════════════════════════════════════ */
function exportPPTX(slidesHtml, title) {
    if (typeof PptxGenJS === 'undefined') { toast('Đang tải thư viện PPTX, thử lại...'); return; }

    var pptx = new PptxGenJS();
    pptx.layout = 'LAYOUT_WIDE';

    var temp = document.createElement('div');
    temp.innerHTML = slidesHtml;
    var sections = temp.querySelectorAll(':scope > section');
    if (sections.length === 0) sections = temp.querySelectorAll('section');

    sections.forEach(function(sec) {
        if (sec.querySelector(':scope > section')) {
            sec.querySelectorAll(':scope > section').forEach(function(sub) { addPptxSlide(pptx, sub); });
        } else {
            addPptxSlide(pptx, sec);
        }
    });

    var fileName = (title || 'slide').replace(/[^a-zA-Z0-9_\-\u00C0-\u024F\u1E00-\u1EFF\s]/g, '').replace(/\s+/g, '_');
    pptx.writeFile({ fileName: fileName + '.pptx' })
        .then(function() { toast('📑 PPTX đã tải xuống!'); })
        .catch(function(err) { toast('Lỗi xuất PPTX'); console.error(err); });
}

function addPptxSlide(pptx, section) {
    var slide = pptx.addSlide();

    var titleEl = section.querySelector('h1, h2, h3');
    var titleText = titleEl ? titleEl.textContent.trim() : '';

    if (titleText) {
        slide.addText(titleText, {
            x: 0.5, y: 0.3, w: '90%', h: 1.2,
            fontSize: titleEl.tagName === 'H1' ? 36 : (titleEl.tagName === 'H2' ? 28 : 24),
            bold: true, color: '333333', valign: 'middle'
        });
    }

    var bodyItems = [];
    section.querySelectorAll('p, li, blockquote, td').forEach(function(node) {
        if (titleEl && (node === titleEl || titleEl.contains(node))) return;
        if (node.closest('h1') || node.closest('h2') || node.closest('h3')) return;
        var text = node.textContent.trim();
        if (!text) return;
        var isQuote = node.tagName === 'BLOCKQUOTE';
        bodyItems.push({ text: text, options: {
            bullet: node.tagName === 'LI',
            italic: isQuote,
            color: isQuote ? '777777' : '555555'
        }});
    });

    if (bodyItems.length > 0) {
        slide.addText(bodyItems, {
            x: 0.5, y: titleText ? 1.8 : 0.5, w: '90%', h: 4.5,
            fontSize: 18, color: '555555', valign: 'top', lineSpacing: 28
        });
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
            console.error('[SlideStudio]', e);
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
