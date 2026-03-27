<?php
/**
 * BizCity Tool Mindmap — Mobile-First SPA
 *
 * Full Mermaid IDE: Tạo mới từ prompt AI + Lịch sử + Editor tương tác.
 * Bottom tab navigation, responsive, hoạt động cả standalone lẫn iframe.
 *
 * @package BizCity_Tool_Mindmap
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
<title>Mindmap Studio — BizCity</title>
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
.mm-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 50;
    height: var(--header-h);
    display: flex; align-items: center; gap: 10px;
    padding: 0 16px;
    background: rgba(255,255,255,.88);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--c-border);
}
.mm-header-logo { font-size: 22px; }
.mm-header h1 { font-size: 16px; font-weight: 700; }
.mm-header-badge {
    font-size: 10px; font-weight: 600;
    padding: 2px 8px; border-radius: 6px;
    background: var(--c-primary-bg); color: var(--c-primary);
}

.mm-main {
    position: fixed;
    top: var(--header-h); bottom: calc(var(--nav-h) + var(--safe-b));
    left: 0; right: 0;
    overflow: hidden;
}
.mm-tab {
    display: none;
    position: absolute; inset: 0;
    overflow-y: auto;
    padding: 16px 16px 24px;
    -webkit-overflow-scrolling: touch;
}
.mm-tab.active { display: block; }

.mm-nav {
    position: fixed; bottom: 0; left: 0; right: 0; z-index: 50;
    height: calc(var(--nav-h) + var(--safe-b));
    padding-bottom: var(--safe-b);
    display: flex; align-items: stretch;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px);
    border-top: 1px solid var(--c-border);
}
.mm-nav-item {
    flex: 1;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 2px;
    font-size: 10px; font-weight: 500; color: var(--c-muted);
    transition: color .2s;
    -webkit-tap-highlight-color: transparent;
}
.mm-nav-item .mm-nav-icon { font-size: 22px; line-height: 1; }
.mm-nav-item.active { color: var(--c-primary); font-weight: 700; }

/* ══════════════════════════════════════════════════
   CREATE TAB
   ══════════════════════════════════════════════════ */
.mm-section-title {
    font-size: 14px; font-weight: 700; color: var(--c-text);
    margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
.mm-chips {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-bottom: 14px;
}
.mm-chip {
    padding: 6px 12px; border-radius: 20px;
    font-size: 12px; font-weight: 500;
    background: var(--c-surface); border: 1px solid var(--c-border);
    color: var(--c-muted);
    transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.mm-chip.active {
    background: var(--c-primary); color: #fff;
    border-color: var(--c-primary);
}

.mm-prompt-box {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 12px;
    margin-bottom: 14px;
}
.mm-prompt-box textarea {
    width: 100%; border: none; outline: none;
    resize: none; min-height: 80px;
    font-size: 14px; line-height: 1.5;
    background: transparent;
}
.mm-prompt-box textarea::placeholder { color: #b0b8c4; }
.mm-prompt-actions {
    display: flex; justify-content: flex-end; gap: 8px;
    margin-top: 8px;
}
.mm-btn {
    padding: 10px 20px; border-radius: 10px;
    font-size: 14px; font-weight: 600;
    transition: all .15s;
    display: inline-flex; align-items: center; gap: 6px;
}
.mm-btn-primary {
    background: linear-gradient(135deg, var(--c-primary), var(--c-secondary));
    color: #fff;
    box-shadow: 0 2px 10px rgba(99,102,241,.3);
}
.mm-btn-primary:hover { box-shadow: 0 4px 16px rgba(99,102,241,.4); }
.mm-btn-primary:active { transform: scale(.97); }
.mm-btn-primary:disabled {
    opacity: .5; cursor: not-allowed;
    box-shadow: none; transform: none;
}
.mm-btn-outline {
    background: var(--c-surface);
    border: 1px solid var(--c-border); color: var(--c-text);
}
.mm-btn-outline:hover { border-color: var(--c-primary); color: var(--c-primary); }
.mm-btn-sm { padding: 6px 14px; font-size: 12px; border-radius: 8px; }
.mm-btn-danger { background: var(--c-danger); color: #fff; }

/* Loading */
.mm-loading {
    text-align: center; padding: 32px 16px;
}
.mm-spinner {
    width: 36px; height: 36px;
    border: 3px solid var(--c-border);
    border-top-color: var(--c-primary);
    border-radius: 50%;
    animation: spin .8s linear infinite;
    margin: 0 auto 12px;
}
@keyframes spin { to { transform: rotate(360deg); } }
.mm-loading-text { font-size: 13px; color: var(--c-muted); }

/* Preview area */
.mm-preview-wrap {
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 16px; margin-bottom: 12px;
    min-height: 160px;
    overflow: auto;
    -webkit-overflow-scrolling: touch;
}
.mm-preview-wrap svg { max-width: 100%; height: auto; }
.mm-preview-error {
    color: var(--c-danger); font-size: 13px;
    padding: 12px; text-align: center;
}

/* Result actions */
.mm-result { display: none; }
.mm-result.show { display: block; }
.mm-result-bar {
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
}
.mm-result-bar input[type=text] {
    flex: 1; min-width: 0;
    padding: 8px 12px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none;
    font-size: 14px;
}
.mm-result-bar input[type=text]:focus { border-color: var(--c-primary); }

/* Code peek */
.mm-code-peek {
    margin-top: 10px;
}
.mm-code-toggle {
    font-size: 12px; color: var(--c-primary);
    cursor: pointer; user-select: none;
    display: inline-flex; align-items: center; gap: 4px;
}
.mm-code-block {
    display: none; margin-top: 8px;
    background: #1e1e2e; color: #cdd6f4;
    border-radius: var(--radius-sm);
    padding: 12px; font-size: 12px;
    font-family: "Fira Code", "Cascadia Code", Consolas, monospace;
    white-space: pre-wrap; word-break: break-all;
    max-height: 200px; overflow: auto;
    line-height: 1.6;
}
.mm-code-block.show { display: block; }

/* ══════════════════════════════════════════════════
   HISTORY TAB
   ══════════════════════════════════════════════════ */
.mm-history-list { display: flex; flex-direction: column; gap: 10px; }
.mm-hcard {
    display: flex; align-items: flex-start; gap: 12px;
    background: var(--c-surface);
    border: 1px solid var(--c-border);
    border-radius: var(--radius);
    padding: 14px;
    cursor: pointer; transition: all .2s;
    -webkit-tap-highlight-color: transparent;
}
.mm-hcard:hover { border-color: #c7d2fe; box-shadow: 0 2px 12px rgba(99,102,241,.1); }
.mm-hcard:active { transform: scale(.98); }
.mm-hcard-icon {
    width: 42px; height: 42px; border-radius: 12px;
    background: var(--c-primary-bg);
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.mm-hcard-body { flex: 1; min-width: 0; }
.mm-hcard-title {
    font-size: 14px; font-weight: 600; color: var(--c-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.mm-hcard-meta {
    font-size: 11px; color: var(--c-muted); margin-top: 3px;
    display: flex; gap: 8px;
}
.mm-hcard-desc {
    font-size: 12px; color: var(--c-muted); margin-top: 4px;
    line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.mm-hcard-actions {
    display: flex; flex-direction: column; gap: 4px;
    flex-shrink: 0;
}
.mm-hcard-actions button {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; transition: background .15s;
}
.mm-hcard-actions button:hover { background: #f1f5f9; }
.mm-hcard-actions .mm-del:hover { background: #fef2f2; color: var(--c-danger); }

.mm-empty {
    text-align: center; padding: 48px 20px;
}
.mm-empty-icon { font-size: 48px; margin-bottom: 12px; }
.mm-empty p { color: var(--c-muted); font-size: 14px; }

.mm-load-more {
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

.mm-editor-toolbar {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0; flex-wrap: wrap;
}
.mm-editor-toolbar input[type=text] {
    flex: 1; min-width: 0;
    padding: 6px 10px; border-radius: var(--radius-sm);
    border: 1px solid var(--c-border); outline: none; font-size: 13px;
}
.mm-editor-toolbar input[type=text]:focus { border-color: var(--c-primary); }

.mm-view-btns {
    display: flex; gap: 2px;
    background: #f1f5f9; border-radius: var(--radius-sm);
    padding: 2px;
}
.mm-view-btn {
    padding: 5px 10px; border-radius: 6px;
    font-size: 11px; font-weight: 600; color: var(--c-muted);
    transition: all .15s;
}
.mm-view-btn.active { background: var(--c-surface); color: var(--c-text); box-shadow: 0 1px 3px rgba(0,0,0,.08); }

.mm-editor-body {
    flex: 1; display: flex; overflow: hidden;
    min-height: 0;
}
.mm-editor-code {
    flex: 1; display: flex; flex-direction: column;
    border-right: 1px solid var(--c-border);
    min-width: 0;
}
.mm-editor-code textarea {
    flex: 1; width: 100%; border: none; outline: none; resize: none;
    padding: 12px;
    font-family: "Fira Code", "Cascadia Code", Consolas, monospace;
    font-size: 13px; line-height: 1.6;
    background: #1e1e2e; color: #cdd6f4;
    tab-size: 2;
}
.mm-editor-code textarea::placeholder { color: #585b70; }
.mm-editor-preview-pane {
    flex: 1;
    overflow: auto;
    padding: 12px;
    background: var(--c-surface);
    min-width: 0;
    -webkit-overflow-scrolling: touch;
}
.mm-editor-preview-pane svg { max-width: 100%; height: auto; }

.mm-editor-actions {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0;
    flex-wrap: wrap;
}
.mm-editor-footer {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 12px;
    border-top: 1px solid var(--c-border);
    background: var(--c-surface);
    flex-shrink: 0;
}
.mm-editor-status {
    flex: 1; font-size: 11px; color: var(--c-muted);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

/* Editor views: code-only, preview-only, split */
.mm-editor-body[data-view="code"]    .mm-editor-preview-pane { display: none; }
.mm-editor-body[data-view="preview"] .mm-editor-code { display: none; }

/* Mobile: default code-only */
@media (max-width: 767px) {
    .mm-editor-body[data-view="split"] .mm-editor-code,
    .mm-editor-body[data-view="split"] .mm-editor-preview-pane {
        flex: 1;
    }
    .mm-editor-body[data-view="split"] {
        flex-direction: column;
    }
    .mm-editor-body[data-view="split"] .mm-editor-code {
        border-right: none;
        border-bottom: 1px solid var(--c-border);
        max-height: 45%;
    }
}
/* Desktop: split side-by-side */
@media (min-width: 768px) {
    .mm-editor-body[data-view="split"] .mm-editor-code,
    .mm-editor-body[data-view="split"] .mm-editor-preview-pane {
        flex: 1;
    }
}

/* ══════════════════════════════════════════════════
   VIEW MODAL (overlay when tapping history item)
   ══════════════════════════════════════════════════ */
.mm-modal {
    display: none;
    position: fixed; inset: 0; z-index: 100;
    background: var(--c-bg);
    flex-direction: column;
}
.mm-modal.show { display: flex; }
.mm-modal-header {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--c-border);
    background: var(--c-surface);
}
.mm-modal-header h2 {
    flex: 1; font-size: 15px; font-weight: 700;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.mm-modal-back {
    font-size: 13px; color: var(--c-primary);
    font-weight: 600; padding: 6px;
    display: flex; align-items: center; gap: 2px;
}
.mm-modal-actions { display: flex; gap: 4px; }
.mm-modal-actions button {
    width: 36px; height: 36px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; transition: background .15s;
}
.mm-modal-actions button:hover { background: #f1f5f9; }
.mm-modal-body {
    flex: 1; overflow: auto; padding: 16px;
    -webkit-overflow-scrolling: touch;
}
.mm-modal-body svg { max-width: 100%; height: auto; }

/* ══════════════════════════════════════════════════
   TOAST
   ══════════════════════════════════════════════════ */
.mm-toast {
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
.mm-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

/* ══════════════════════════════════════════════════
   LOGIN SCREEN
   ══════════════════════════════════════════════════ */
.mm-login {
    text-align: center; padding: 60px 24px;
}
.mm-login .mm-login-icon { font-size: 56px; margin-bottom: 16px; }
.mm-login h2 { font-size: 20px; margin-bottom: 8px; }
.mm-login p { color: var(--c-muted); font-size: 14px; margin-bottom: 24px; }
.mm-login-btn {
    display: inline-block; padding: 12px 32px;
    background: linear-gradient(135deg, var(--c-primary), var(--c-secondary));
    color: #fff; border-radius: 12px; text-decoration: none;
    font-weight: 600; font-size: 15px;
}

/* ══════════════════════════════════════════════════
   Utility
   ══════════════════════════════════════════════════ */
.hidden { display: none !important; }

/* ══════════════════════════════════════════════════
   Export Buttons
   ══════════════════════════════════════════════════ */
.mm-export-bar {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-top: 8px;
}
.mm-btn-export {
    padding: 7px 14px; border-radius: 8px;
    font-size: 12px; font-weight: 600;
    display: inline-flex; align-items: center; gap: 5px;
    transition: all .15s;
}
.mm-btn-download {
    background: #ecfdf5; color: #059669;
    border: 1px solid #a7f3d0;
}
.mm-btn-download:hover { background: #d1fae5; border-color: #6ee7b7; }
.mm-btn-upload {
    background: #eff6ff; color: #2563eb;
    border: 1px solid #bfdbfe;
}
.mm-btn-upload:hover { background: #dbeafe; border-color: #93c5fd; }
.mm-btn-upload:disabled, .mm-btn-download:disabled {
    opacity: .5; cursor: not-allowed;
}
</style>
</head>
<body>

<!-- ════════ Header ════════ -->
<header class="mm-header">
    <span class="mm-header-logo">🧠</span>
    <h1>Mindmap Studio</h1>
    <span class="mm-header-badge">Mermaid</span>
</header>

<!-- ════════ Main Content ════════ -->
<main class="mm-main">

    <?php if ( ! $is_logged_in ): ?>
    <!-- Login Required -->
    <section class="mm-tab active">
        <div class="mm-login">
            <div class="mm-login-icon">🔐</div>
            <h2>Đăng nhập để bắt đầu</h2>
            <p>Đăng nhập để tạo mindmap, flowchart và lưu lịch sử.</p>
            <a href="<?php echo esc_url( wp_login_url( home_url( '/tool-mindmap/' ) ) ); ?>" class="mm-login-btn">Đăng nhập</a>
        </div>
    </section>

    <?php else: ?>

    <!-- ═══════════════════════════════════════════
         TAB: TẠO MỚI
         ═══════════════════════════════════════════ -->
    <section id="tab-create" class="mm-tab active">

        <div class="mm-section-title">📊 Loại sơ đồ</div>
        <div class="mm-chips">
            <button class="mm-chip active" data-type="auto">🎯 Auto</button>
            <button class="mm-chip" data-type="stateDiagram">🧠 Mindmap</button>
            <button class="mm-chip" data-type="flowchart">📊 Flowchart</button>
            <button class="mm-chip" data-type="sequence">🔄 Sequence</button>
            <button class="mm-chip" data-type="class">🏗️ Class</button>
            <button class="mm-chip" data-type="gantt">📅 Gantt</button>
            <button class="mm-chip" data-type="pie">🥧 Pie</button>
            <button class="mm-chip" data-type="state">⚡ State Machine</button>
        </div>

        <div class="mm-prompt-box">
            <textarea id="prompt-input" rows="3"
                placeholder="Mô tả sơ đồ bạn muốn tạo...&#10;&#10;Ví dụ: Quy trình tuyển dụng nhân sự, Mindmap về Digital Marketing, Flowchart xử lý đơn hàng e-commerce..."></textarea>
            <div class="mm-prompt-actions">
                <button id="btn-generate" class="mm-btn mm-btn-primary">🎨 Tạo sơ đồ</button>
            </div>
        </div>

        <!-- Loading state -->
        <div id="create-loading" class="mm-loading hidden">
            <div class="mm-spinner"></div>
            <div class="mm-loading-text">AI đang vẽ sơ đồ... ✨</div>
        </div>

        <!-- Result area -->
        <div id="create-result" class="mm-result">

            <div class="mm-section-title">👁 Xem trước</div>
            <div id="create-preview" class="mm-preview-wrap"></div>

            <div class="mm-result-bar">
                <input type="text" id="save-title" placeholder="Tiêu đề sơ đồ...">
                <button id="btn-save" class="mm-btn mm-btn-primary mm-btn-sm">💾 Lưu</button>
                <button id="btn-to-editor" class="mm-btn mm-btn-outline mm-btn-sm">✏️ Sửa</button>
            </div>

            <div class="mm-code-peek">
                <span class="mm-code-toggle" id="toggle-code">▶ Xem Mermaid code</span>
                <pre class="mm-code-block" id="create-code"></pre>
            </div>

            <div class="mm-export-bar">
                <button id="btn-download-png" class="mm-btn-export mm-btn-download">📥 Tải PNG</button>
                <button id="btn-upload-media" class="mm-btn-export mm-btn-upload">💾 Lưu vào Thư viện</button>
            </div>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: LỊCH SỬ
         ═══════════════════════════════════════════ -->
    <section id="tab-history" class="mm-tab">

        <div class="mm-section-title" style="margin-bottom:14px">📋 Sơ đồ đã lưu</div>

        <div id="history-list" class="mm-history-list"></div>

        <div id="history-empty" class="mm-empty hidden">
            <div class="mm-empty-icon">📭</div>
            <p>Chưa có sơ đồ nào.<br>Tạo sơ đồ đầu tiên ngay!</p>
        </div>

        <div id="history-loading" class="mm-loading hidden">
            <div class="mm-spinner"></div>
            <div class="mm-loading-text">Đang tải...</div>
        </div>

        <div id="history-more" class="mm-load-more hidden">
            <button id="btn-load-more" class="mm-btn mm-btn-outline mm-btn-sm">Xem thêm</button>
        </div>

    </section>

    <!-- ═══════════════════════════════════════════
         TAB: EDITOR
         ═══════════════════════════════════════════ -->
    <section id="tab-editor" class="mm-tab">

        <div class="mm-editor-toolbar">
            <input type="text" id="editor-title" placeholder="Tiêu đề sơ đồ...">
            <div class="mm-view-btns">
                <button class="mm-view-btn active" data-view="code">📝</button>
                <button class="mm-view-btn" data-view="split">⬜</button>
                <button class="mm-view-btn" data-view="preview">👁</button>
            </div>
        </div>

        <div class="mm-editor-actions">
            <button id="btn-editor-render" class="mm-btn mm-btn-outline mm-btn-sm">▶ Render</button>
            <button id="btn-editor-save" class="mm-btn mm-btn-primary mm-btn-sm">💾 Lưu</button>
            <button id="btn-editor-png" class="mm-btn-export mm-btn-download mm-btn-sm">📥 PNG</button>
            <button id="btn-editor-upload" class="mm-btn-export mm-btn-upload mm-btn-sm">💾 Thư viện</button>
            <span class="mm-editor-status" id="editor-status"></span>
        </div>

        <div class="mm-editor-body" data-view="code">
            <div class="mm-editor-code">
                <textarea id="editor-code" spellcheck="false"
                    placeholder="graph TD&#10;    A[Bắt đầu] --> B{Có ý tưởng?}&#10;    B -->|Có| C[Nhập mô tả]&#10;    B -->|Chưa| D[Xem gợi ý]&#10;    C --> E[AI tạo sơ đồ]&#10;    D --> C&#10;    E --> F[Xem & Chỉnh sửa]&#10;    F --> G[💾 Lưu lại]"></textarea>
            </div>
            <div class="mm-editor-preview-pane" id="editor-preview"></div>
        </div>

    </section>

    <?php endif; ?>

</main><!-- /.mm-main -->

<!-- ════════ View Modal ════════ -->
<div id="view-modal" class="mm-modal">
    <div class="mm-modal-header">
        <button class="mm-modal-back" id="modal-close">← Quay lại</button>
        <h2 id="modal-title">Sơ đồ</h2>
        <div class="mm-modal-actions">
            <button id="modal-download" title="Tải PNG">📥</button>
            <button id="modal-upload" title="Lưu vào Thư viện">💾</button>
            <button id="modal-edit" title="Sửa">✏️</button>
            <button id="modal-delete" title="Xóa" style="color:var(--c-danger)">🗑️</button>
        </div>
    </div>
    <div class="mm-modal-body" id="modal-preview"></div>
</div>

<!-- ════════ Bottom Navigation ════════ -->
<?php if ( $is_logged_in ): ?>
<nav class="mm-nav">
    <button class="mm-nav-item active" data-tab="create">
        <span class="mm-nav-icon">✨</span>
        <span class="mm-nav-label">Tạo mới</span>
    </button>
    <button class="mm-nav-item" data-tab="history">
        <span class="mm-nav-icon">📋</span>
        <span class="mm-nav-label">Lịch sử</span>
    </button>
    <button class="mm-nav-item" data-tab="editor">
        <span class="mm-nav-icon">✏️</span>
        <span class="mm-nav-label">Editor</span>
    </button>
</nav>
<?php endif; ?>

<!-- ════════ Toast ════════ -->
<div id="toast" class="mm-toast"></div>

<!-- ════════ Mermaid.js ════════ -->
<script src="https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js"></script>

<?php if ( $is_logged_in ): ?>
<script>
(function() {
'use strict';

/* ══════════════════════════════════════════════════
   Config
   ══════════════════════════════════════════════════ */
const CFG = {
    ajax: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce: <?php echo json_encode( wp_create_nonce( 'bztool_mindmap' ) ); ?>,
    openId: <?php echo $open_id; ?>,
};

const TYPE_ICONS = {
    mindmap:'🧠', flowchart:'📊', sequence:'🔄', 'class':'🏗️',
    gantt:'📅', pie:'🥧', state:'⚡', er:'🔗', default:'📋'
};

/* ══════════════════════════════════════════════════
   State
   ══════════════════════════════════════════════════ */
let currentTab     = 'create';
let generatedData  = null;   // { title, type, mermaid, description }
let editorPostId   = 0;
let historyPage    = 0;
let historyTotal   = 0;
let historyLoaded  = false;
let modalPostId    = 0;
let renderCounter  = 0;

/* ══════════════════════════════════════════════════
   Init
   ══════════════════════════════════════════════════ */
mermaid.initialize({ startOnLoad: false, theme: 'default', securityLevel: 'loose' });

document.addEventListener('DOMContentLoaded', function() {
    bindEvents();
    if (CFG.openId) {
        loadMindmapIntoEditor(CFG.openId);
        switchTab('editor');
    }
});

/* ══════════════════════════════════════════════════
   Events
   ══════════════════════════════════════════════════ */
function bindEvents() {
    // Bottom nav tabs
    document.querySelectorAll('.mm-nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
    });

    // Type chips
    document.querySelectorAll('.mm-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            document.querySelectorAll('.mm-chip').forEach(function(c){ c.classList.remove('active'); });
            this.classList.add('active');
        });
    });

    // Generate button
    el('btn-generate').addEventListener('click', doGenerate);

    // Enter on prompt (desktop)
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
            el('editor-code').value  = generatedData.mermaid || '';
            editorPostId = generatedData.post_id || 0;
            renderEditorPreview();
        }
        switchTab('editor');
    });

    // Toggle code peek
    el('toggle-code').addEventListener('click', function() {
        var block = el('create-code');
        var open  = block.classList.toggle('show');
        this.textContent = (open ? '▼' : '▶') + ' Xem Mermaid code';
    });

    // Editor view toggles
    document.querySelectorAll('.mm-view-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.mm-view-btn').forEach(function(b){ b.classList.remove('active'); });
            this.classList.add('active');
            el('editor-code').closest('.mm-editor-body').dataset.view = this.dataset.view;
            if (this.dataset.view !== 'code') renderEditorPreview();
        });
    });

    // Editor render button
    el('btn-editor-render').addEventListener('click', renderEditorPreview);

    // Editor save
    el('btn-editor-save').addEventListener('click', doEditorSave);

    // Editor live preview (debounced)
    var editorTimeout;
    el('editor-code').addEventListener('input', function() {
        clearTimeout(editorTimeout);
        editorTimeout = setTimeout(function() {
            var view = el('editor-code').closest('.mm-editor-body').dataset.view;
            if (view !== 'code') renderEditorPreview();
        }, 600);
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
        loadMindmapIntoEditor(modalPostId);
        switchTab('editor');
    });
    el('modal-delete').addEventListener('click', function() {
        if (confirm('Xóa sơ đồ này?')) {
            deleteMindmap(modalPostId, function() {
                closeModal();
                historyLoaded = false;
                if (currentTab === 'history') loadHistory(true);
            });
        }
    });
}

/* ══════════════════════════════════════════════════
   Tab Switching
   ══════════════════════════════════════════════════ */
function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.mm-tab').forEach(function(t) { t.classList.remove('active'); });
    var target = el('tab-' + tab);
    if (target) target.classList.add('active');
    document.querySelectorAll('.mm-nav-item').forEach(function(n) { n.classList.remove('active'); });
    var navBtn = document.querySelector('[data-tab="' + tab + '"]');
    if (navBtn) navBtn.classList.add('active');

    if (tab === 'history' && !historyLoaded) loadHistory(true);
    if (tab === 'editor') {
        var view = el('editor-code').closest('.mm-editor-body').dataset.view;
        if (view !== 'code') renderEditorPreview();
    }
}

/* ══════════════════════════════════════════════════
   CREATE: Generate Mermaid
   ══════════════════════════════════════════════════ */
function doGenerate() {
    var prompt = el('prompt-input').value.trim();
    var activeChip = document.querySelector('.mm-chip.active');
    var type = activeChip ? activeChip.dataset.type : 'auto';

    if (!prompt) { toast('Nhập mô tả sơ đồ bạn muốn tạo!'); return; }

    el('btn-generate').disabled = true;
    el('create-result').classList.remove('show');
    show('create-loading');

    ajax('bztool_mm_generate', { prompt: prompt, type: type }, function(res) {
        hide('create-loading');
        el('btn-generate').disabled = false;

        if (!res.success) {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi tạo sơ đồ');
            return;
        }

        generatedData = res.data;
        generatedData.post_id = 0;
        el('save-title').value = res.data.title || '';
        el('create-code').textContent = res.data.mermaid || '';

        renderMermaid(res.data.mermaid, 'create-preview', function() {
            el('create-result').classList.add('show');
        });
    });
}

/* ══════════════════════════════════════════════════
   CREATE: Save
   ══════════════════════════════════════════════════ */
function doSaveFromCreate() {
    if (!generatedData || !generatedData.mermaid) { toast('Chưa có sơ đồ để lưu'); return; }

    var title = el('save-title').value.trim() || generatedData.title || '';

    el('btn-save').disabled = true;

    ajax('bztool_mm_save', {
        title:       title,
        mermaid:     generatedData.mermaid,
        type:        generatedData.type || 'flowchart',
        prompt:      el('prompt-input').value.trim(),
        description: generatedData.description || '',
        post_id:     generatedData.post_id || 0,
    }, function(res) {
        el('btn-save').disabled = false;

        if (res.success) {
            generatedData.post_id = res.data.post_id;
            editorPostId = res.data.post_id;
            toast('💾 Đã lưu: ' + (res.data.title || title));
            historyLoaded = false; // force reload history
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

    ajax('bztool_mm_list', { page: historyPage }, function(res) {
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
    var icon = TYPE_ICONS[item.type] || TYPE_ICONS['default'];
    var card = document.createElement('div');
    card.className = 'mm-hcard';
    card.innerHTML =
        '<div class="mm-hcard-icon">' + icon + '</div>' +
        '<div class="mm-hcard-body">' +
            '<div class="mm-hcard-title">' + esc(item.title) + '</div>' +
            '<div class="mm-hcard-meta">' +
                '<span>' + esc(item.type) + '</span>' +
                '<span>' + esc(item.date) + '</span>' +
            '</div>' +
            (item.description ? '<div class="mm-hcard-desc">' + esc(item.description) + '</div>' : '') +
        '</div>' +
        '<div class="mm-hcard-actions">' +
            '<button class="mm-edit" title="Sửa">✏️</button>' +
            '<button class="mm-del" title="Xóa">🗑️</button>' +
        '</div>';

    // Tap card body → view modal
    card.querySelector('.mm-hcard-body').addEventListener('click', function() {
        openModal(item.id);
    });
    card.querySelector('.mm-hcard-icon').addEventListener('click', function() {
        openModal(item.id);
    });

    // Edit button
    card.querySelector('.mm-edit').addEventListener('click', function(e) {
        e.stopPropagation();
        loadMindmapIntoEditor(item.id);
        switchTab('editor');
    });

    // Delete button
    card.querySelector('.mm-del').addEventListener('click', function(e) {
        e.stopPropagation();
        if (confirm('Xóa "' + item.title + '"?')) {
            deleteMindmap(item.id, function() {
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
    el('modal-preview').innerHTML = '<div class="mm-loading"><div class="mm-spinner"></div></div>';
    el('modal-title').textContent = 'Đang tải...';
    el('view-modal').classList.add('show');

    ajax('bztool_mm_get', { post_id: postId }, function(res) {
        if (!res.success) {
            el('modal-preview').innerHTML = '<p class="mm-preview-error">Không thể tải sơ đồ.</p>';
            return;
        }
        el('modal-title').textContent = res.data.title || 'Sơ đồ';
        renderMermaid(res.data.mermaid, 'modal-preview');
    });
}

function closeModal() {
    el('view-modal').classList.remove('show');
    modalPostId = 0;
}

/* ══════════════════════════════════════════════════
   EDITOR: Load mindmap
   ══════════════════════════════════════════════════ */
function loadMindmapIntoEditor(postId) {
    ajax('bztool_mm_get', { post_id: postId }, function(res) {
        if (!res.success) { toast('Không thể tải sơ đồ'); return; }
        editorPostId = postId;
        el('editor-title').value = res.data.title || '';
        el('editor-code').value  = res.data.mermaid || '';
        el('editor-status').textContent = 'Đã lưu · ID: ' + postId + ' · ' + (res.data.type || '');

        // Auto-switch to split view so the diagram renders immediately
        var body = el('editor-code').closest('.mm-editor-body');
        body.dataset.view = 'split';
        document.querySelectorAll('.mm-view-btn').forEach(function(b) {
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
        el('editor-preview').innerHTML = '<p style="color:#9ca3af;text-align:center;padding:24px">Nhập Mermaid code bên trái để xem preview</p>';
        return;
    }

    // Auto-switch to split view if currently code-only (so preview pane is visible)
    var body = el('editor-code').closest('.mm-editor-body');
    if (body.dataset.view === 'code') {
        body.dataset.view = 'split';
        document.querySelectorAll('.mm-view-btn').forEach(function(b) {
            b.classList.toggle('active', b.dataset.view === 'split');
        });
    }

    renderMermaid(code, 'editor-preview');
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
        // Update existing
        ajax('bztool_mm_update', { post_id: editorPostId, mermaid: code, title: title }, function(res) {
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
        // Save new
        ajax('bztool_mm_save', { title: title, mermaid: code, type: detectType(code) }, function(res) {
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
   Mermaid Render (dynamic)
   ══════════════════════════════════════════════════ */
async function renderMermaid(code, containerId, callback) {
    var container = el(containerId);
    if (!container) return;

    try {
        renderCounter++;
        var id = 'mm-svg-' + renderCounter;
        var result = await mermaid.render(id, code);
        container.innerHTML = result.svg;
        if (result.bindFunctions) result.bindFunctions(container);
    } catch (err) {
        container.innerHTML = '<div class="mm-preview-error">⚠️ Lỗi cú pháp Mermaid<br><small>' + esc(err.message || String(err)) + '</small></div>';
    }

    if (typeof callback === 'function') callback();
}

/* ══════════════════════════════════════════════════
   Delete Mindmap
   ══════════════════════════════════════════════════ */
function deleteMindmap(postId, callback) {
    ajax('bztool_mm_delete', { post_id: postId }, function(res) {
        if (res.success) {
            toast('🗑️ Đã xóa');
            if (callback) callback();
        } else {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi xóa');
        }
    });
}

/* ══════════════════════════════════════════════════
   Detect Mermaid type from code
   ══════════════════════════════════════════════════ */
function detectType(code) {
    code = code.trim().toLowerCase();
    if (/^mindmap\b/.test(code))           return 'mindmap';
    if (/^graph\b|^flowchart\b/.test(code)) return 'flowchart';
    if (/^sequencediagram\b/.test(code))   return 'sequence';
    if (/^classdiagram\b/.test(code))      return 'class';
    if (/^gantt\b/.test(code))             return 'gantt';
    if (/^pie\b/.test(code))               return 'pie';
    if (/^statediagram/.test(code))        return 'state';
    if (/^erdiagram\b/.test(code))         return 'er';
    return 'flowchart';
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
            console.error('[MindmapStudio]', e);
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

/* ══════════════════════════════════════════════════
   SVG → PNG Export engine
   ══════════════════════════════════════════════════ */

/**
 * Convert SVG element inside a container to PNG data URL.
 * @param {string} containerId  ID of container holding the rendered SVG.
 * @param {number} scale        Resolution multiplier (default 2 for retina).
 * @returns {Promise<string>}   Base64 data URL (image/png).
 */
function svgToPng(containerId, scale) {
    scale = scale || 2;
    return new Promise(function(resolve, reject) {
        var container = el(containerId);
        if (!container) return reject(new Error('Container not found'));
        var svg = container.querySelector('svg');
        if (!svg) return reject(new Error('Chưa có sơ đồ để xuất. Hãy render trước.'));

        // Clone SVG and inject inline styles to make it self-contained
        var clone = svg.cloneNode(true);
        clone.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        clone.setAttribute('xmlns:xlink', 'http://www.w3.org/1999/xlink');

        // Ensure dimensions
        var bbox  = svg.getBoundingClientRect();
        var w     = bbox.width  || parseFloat(svg.getAttribute('width'))  || 800;
        var h     = bbox.height || parseFloat(svg.getAttribute('height')) || 600;
        clone.setAttribute('width',  w);
        clone.setAttribute('height', h);

        // Inject computed styles into a <style> block to avoid tainted canvas
        var styleEl = document.createElement('style');
        var cssRules = [];
        try {
            var sheets = document.styleSheets;
            for (var i = 0; i < sheets.length; i++) {
                try {
                    var rules = sheets[i].cssRules || sheets[i].rules;
                    if (rules) {
                        for (var j = 0; j < rules.length; j++) {
                            cssRules.push(rules[j].cssText);
                        }
                    }
                } catch(e) { /* cross-origin sheet, skip */ }
            }
        } catch(e) {}
        styleEl.textContent = cssRules.join('\n');
        clone.insertBefore(styleEl, clone.firstChild);

        // Remove any <foreignObject> that can taint canvas
        var foreignObjects = clone.querySelectorAll('foreignObject');
        foreignObjects.forEach(function(fo) { fo.remove(); });

        // Use data URI instead of Blob URL to avoid tainted canvas
        var svgData = new XMLSerializer().serializeToString(clone);
        var dataUri = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgData);

        var img = new Image();
        img.onload = function() {
            var canvas  = document.createElement('canvas');
            canvas.width  = w * scale;
            canvas.height = h * scale;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/png'));
        };
        img.onerror = function() {
            reject(new Error('Lỗi render PNG'));
        };
        img.src = dataUri;
    });
}

/**
 * Trigger browser download of a data URL.
 */
function downloadDataURL(dataUrl, filename) {
    var a = document.createElement('a');
    a.href = dataUrl;
    a.download = filename || 'mindmap.png';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

/**
 * Upload PNG base64 to WordPress Media Library via AJAX.
 */
function uploadToMediaLibrary(dataUrl, title, postId, callback) {
    ajax('bztool_mm_upload_media', {
        image_data: dataUrl,
        title:      title || 'Mindmap',
        post_id:    postId || 0,
    }, function(res) {
        if (res.success) {
            toast('✅ Đã lưu vào Thư viện: ' + (res.data.filename || ''));
        } else {
            toast(res.data && res.data.message ? res.data.message : 'Lỗi upload');
        }
        if (callback) callback(res);
    });
}

/* ── Bind export buttons ── */

// CREATE TAB: Download PNG
el('btn-download-png').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    svgToPng('create-preview').then(function(png) {
        var fname = (generatedData && generatedData.title ? generatedData.title : 'mindmap') + '.png';
        downloadDataURL(png, fname);
        btn.disabled = false;
    }).catch(function(e) { toast(e.message); btn.disabled = false; });
});

// CREATE TAB: Upload to Media
el('btn-upload-media').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    svgToPng('create-preview').then(function(png) {
        var title  = el('save-title').value.trim() || (generatedData ? generatedData.title : 'Mindmap');
        var pid    = generatedData ? (generatedData.post_id || 0) : 0;
        uploadToMediaLibrary(png, title, pid, function() { btn.disabled = false; });
    }).catch(function(e) { toast(e.message); btn.disabled = false; });
});

// EDITOR TAB: Download PNG
el('btn-editor-png').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    // Ensure preview pane is rendered
    renderEditorPreview();
    setTimeout(function() {
        svgToPng('editor-preview').then(function(png) {
            var fname = (el('editor-title').value.trim() || 'mindmap-editor') + '.png';
            downloadDataURL(png, fname);
            btn.disabled = false;
        }).catch(function(e) { toast(e.message); btn.disabled = false; });
    }, 300);
});

// EDITOR TAB: Upload to Media
el('btn-editor-upload').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    renderEditorPreview();
    setTimeout(function() {
        svgToPng('editor-preview').then(function(png) {
            var title = el('editor-title').value.trim() || 'Mindmap Editor';
            uploadToMediaLibrary(png, title, editorPostId, function() { btn.disabled = false; });
        }).catch(function(e) { toast(e.message); btn.disabled = false; });
    }, 300);
});

// MODAL: Download PNG
el('modal-download').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    svgToPng('modal-preview').then(function(png) {
        var fname = (el('modal-title').textContent || 'mindmap') + '.png';
        downloadDataURL(png, fname);
        btn.disabled = false;
    }).catch(function(e) { toast(e.message); btn.disabled = false; });
});

// MODAL: Upload to Media
el('modal-upload').addEventListener('click', function() {
    var btn = this;
    btn.disabled = true;
    svgToPng('modal-preview').then(function(png) {
        var title = el('modal-title').textContent || 'Mindmap';
        uploadToMediaLibrary(png, title, modalPostId, function() { btn.disabled = false; });
    }).catch(function(e) { toast(e.message); btn.disabled = false; });
});

})();
</script>
<?php endif; ?>

</body>
</html>
