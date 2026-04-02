<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$is_logged_in = is_user_logged_in();
$open_id      = isset( $_GET['id'] ) ? intval( $_GET['id'] ) : 0;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sheet Studio - BizCity</title>
<style>
:root {
    --c-ink: #122033;
    --c-muted: #607087;
    --c-line: #d9e2ec;
    --c-bg: #f5f7fb;
    --c-surface: #ffffff;
    --c-brand: #0f9d7a;
    --c-brand-2: #2f6fed;
    --c-warm: #f59f45;
    --c-danger: #d64545;
    --radius: 14px;
    --header-h: 54px;
    --nav-h: 64px;
    --safe-b: env(safe-area-inset-bottom, 0px);
}
*,*::before,*::after { box-sizing: border-box; }
html,body { margin: 0; padding: 0; height: 100%; }
body {
    font-family: Georgia, "Segoe UI", sans-serif;
    background:
        radial-gradient(circle at top left, rgba(47,111,237,.09), transparent 30%),
        radial-gradient(circle at bottom right, rgba(15,157,122,.12), transparent 28%),
        var(--c-bg);
    color: var(--c-ink);
}
button,input,textarea,select { font: inherit; }
button { cursor: pointer; }
.sh-header {
    position: fixed; top: 0; left: 0; right: 0; z-index: 30;
    display: flex; align-items: center; gap: 10px;
    height: var(--header-h); padding: 0 16px;
    background: rgba(255,255,255,.88);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid rgba(18,32,51,.08);
}
.sh-logo {
    width: 34px; height: 34px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, var(--c-brand), var(--c-brand-2));
    color: #fff; font-size: 18px;
}
.sh-title { flex: 1; min-width: 0; }
.sh-title strong { display: block; font-size: 14px; }
.sh-title span { display: block; font-size: 11px; color: var(--c-muted); }
.sh-badge {
    padding: 4px 9px; border-radius: 999px;
    font-size: 10px; font-weight: 700; color: var(--c-brand-2);
    background: rgba(47,111,237,.1);
}
.sh-main {
    position: fixed; inset: var(--header-h) 0 calc(var(--nav-h) + var(--safe-b)) 0;
    overflow: hidden;
}
.sh-tab {
    display: none; position: absolute; inset: 0; overflow-y: auto;
    padding: 16px 16px 26px;
}
.sh-tab.active { display: block; }
.sh-nav {
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 30;
    display: flex; background: rgba(255,255,255,.95);
    border-top: 1px solid rgba(18,32,51,.08);
    height: calc(var(--nav-h) + var(--safe-b));
    padding-bottom: var(--safe-b);
}
.sh-nav-item {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
    gap: 3px; border: 0; background: transparent; color: var(--c-muted);
}
.sh-nav-item.active { color: var(--c-brand); font-weight: 700; }
.sh-nav-icon { font-size: 20px; }
.sh-hero {
    padding: 22px 18px; border-radius: 22px; margin-bottom: 16px; color: #fff;
    background:
        linear-gradient(135deg, rgba(15,157,122,.94), rgba(47,111,237,.92)),
        linear-gradient(180deg, #163a6b, #12315b);
    box-shadow: 0 18px 50px rgba(18,49,91,.22);
}
.sh-hero h1 { margin: 0 0 8px; font-size: 22px; }
.sh-hero p { margin: 0; line-height: 1.55; font-size: 14px; max-width: 700px; }
.sh-stat-row { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
.sh-stat {
    min-width: 110px; padding: 10px 12px; border-radius: 14px;
    background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2);
}
.sh-stat strong { display: block; font-size: 18px; }
.sh-stat span { font-size: 11px; opacity: .85; }
.sh-section-title { margin: 0 0 10px; font-size: 14px; font-weight: 700; }
.sh-card {
    background: rgba(255,255,255,.9); border: 1px solid rgba(18,32,51,.08);
    border-radius: 18px; padding: 14px; margin-bottom: 14px;
    box-shadow: 0 6px 24px rgba(18,32,51,.06);
}
.sh-grid { display: grid; gap: 12px; }
.sh-grid.two { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.sh-label { display: block; font-size: 12px; color: var(--c-muted); margin-bottom: 6px; }
.sh-input, .sh-textarea, .sh-select {
    width: 100%; border: 1px solid var(--c-line); background: #fff;
    border-radius: 12px; padding: 10px 12px; color: var(--c-ink);
}
.sh-textarea { min-height: 110px; resize: vertical; }
.sh-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.sh-btn {
    border: 0; border-radius: 12px; padding: 10px 14px;
    background: #fff; color: var(--c-ink); border: 1px solid var(--c-line);
}
.sh-btn.brand { background: linear-gradient(135deg, var(--c-brand), var(--c-brand-2)); color: #fff; border-color: transparent; }
.sh-btn.warm { background: linear-gradient(135deg, #efb44d, #ef7d45); color: #fff; border-color: transparent; }
.sh-btn.danger { background: var(--c-danger); color: #fff; border-color: transparent; }
.sh-tip-list, .sh-history-list, .sh-command-list { display: grid; gap: 10px; }
.sh-tip, .sh-history-item, .sh-command {
    width: 100%; text-align: left; border: 1px solid rgba(18,32,51,.08); background: #fff;
    border-radius: 14px; padding: 12px 14px;
}
.sh-tip strong, .sh-history-item strong, .sh-command strong { display: block; margin-bottom: 4px; }
.sh-tip span, .sh-history-item span, .sh-command span { color: var(--c-muted); font-size: 12px; line-height: 1.45; }
.sh-workbook-shell {
    overflow: hidden; border-radius: 18px; border: 1px solid rgba(18,32,51,.08); background: #fff;
}
.sh-editor-bar {
    display: flex; gap: 8px; padding: 12px; flex-wrap: wrap; align-items: center;
    border-bottom: 1px solid rgba(18,32,51,.08);
    background: linear-gradient(180deg, #fbfdff, #f4f8fc);
}
.sh-editor-bar input { flex: 1; min-width: 200px; }
.sh-preview-wrap { overflow: auto; max-height: 48vh; }
.sh-table { width: 100%; border-collapse: collapse; min-width: 560px; }
.sh-table th, .sh-table td {
    border: 1px solid #e6edf4; padding: 8px 10px; font-size: 12px; vertical-align: top;
}
.sh-table th { background: #f3f7fb; position: sticky; top: 0; z-index: 1; }
.sh-json { width: 100%; min-height: 220px; border: 0; border-top: 1px solid rgba(18,32,51,.08); padding: 14px; resize: vertical; font-family: Consolas, monospace; font-size: 12px; }
.sh-muted { color: var(--c-muted); font-size: 12px; }
.sh-hidden { display: none !important; }
.sh-empty { padding: 18px; text-align: center; color: var(--c-muted); }
.sh-login {
    padding: 26px; margin: 18px; border-radius: 18px; background: #fff; border: 1px solid rgba(18,32,51,.08);
}
@media (max-width: 768px) {
    .sh-hero h1 { font-size: 18px; }
    .sh-table { min-width: 480px; }
}
</style>
</head>
<body>
<header class="sh-header">
    <div class="sh-logo">▦</div>
    <div class="sh-title">
        <strong>Sheet Studio</strong>
        <span>BizCity Twin AI spreadsheet MVP</span>
    </div>
    <div class="sh-badge">MVP</div>
</header>

<main class="sh-main">
<?php if ( ! $is_logged_in ) : ?>
    <section class="sh-tab active">
        <div class="sh-login">
            <h2>Dang nhap de mo Sheet Studio</h2>
            <p>Ban MVP nay dung AJAX + CPT de luu workbook theo user. Dang nhap WordPress de tao, luu va chinh sua workbook.</p>
        </div>
    </section>
<?php else : ?>
    <section class="sh-tab active" id="tab-create">
        <div class="sh-hero">
            <h1>Spreadsheet studio cho Twin AI</h1>
            <p>Tao workbook tu prompt, luu workbook JSON, phan tich bang tinh va bridge du lieu sang chat. Ban MVP nay la shell chuan de gan SpreadJS hoac spreadsheet engine khac o phase tiep theo.</p>
            <div class="sh-stat-row">
                <div class="sh-stat"><strong>4</strong><span>Intent tools</span></div>
                <div class="sh-stat"><strong>JSON</strong><span>Workbook source of truth</span></div>
                <div class="sh-stat"><strong>CSV</strong><span>Export MVP</span></div>
            </div>
        </div>

        <div class="sh-card">
            <h2 class="sh-section-title">Tao workbook moi</h2>
            <label class="sh-label" for="create-topic">Mo ta workbook</label>
            <textarea class="sh-textarea" id="create-topic" placeholder="Vi du: Tao bang ngan sach marketing 12 thang gom hang muc, thang, ngan sach, thuc chi, chenhlech va ghi chu."></textarea>
            <div class="sh-grid two">
                <div>
                    <label class="sh-label" for="create-purpose">Loai workbook</label>
                    <select class="sh-select" id="create-purpose">
                        <option value="auto">AI tu chon</option>
                        <option value="budget">Budget</option>
                        <option value="dashboard">Dashboard</option>
                        <option value="tracker">Tracker</option>
                        <option value="roster">Roster</option>
                        <option value="inventory">Inventory</option>
                        <option value="finance">Finance</option>
                        <option value="custom">Custom</option>
                    </select>
                </div>
                <div>
                    <label class="sh-label" for="create-rows">So dong mau</label>
                    <input class="sh-input" id="create-rows" type="number" min="3" max="60" value="12">
                </div>
            </div>
            <div class="sh-actions">
                <button class="sh-btn brand" id="btn-create">Tao workbook</button>
                <button class="sh-btn" id="btn-create-open-chat">Goi y lenh chat</button>
            </div>
        </div>

        <div class="sh-card">
            <h2 class="sh-section-title">Mau prompt nhanh</h2>
            <div class="sh-tip-list" id="tip-list">
                <button class="sh-tip" data-topic="Tao bang ngan sach marketing 12 thang voi KPI ROI va chenhlech ngan sach."><strong>Bang ngan sach marketing</strong><span>Gan cho team marketing theo thang, co ngan sach va thuc chi.</span></button>
                <button class="sh-tip" data-topic="Lam KPI dashboard cho team sales gom doanh thu, lead, conversion rate, owner va ky bao cao."><strong>KPI dashboard sales</strong><span>Phu hop de tao dashboard tong hop va formula patch.</span></button>
                <button class="sh-tip" data-topic="Tao bang cham cong nhan vien theo ca gom ngay, ca, gio vao, gio ra va tong gio."><strong>Bang cham cong</strong><span>Dung cho roster, shift va tong hop gio cong.</span></button>
            </div>
        </div>
    </section>

    <section class="sh-tab" id="tab-history">
        <div class="sh-card">
            <h2 class="sh-section-title">Workbook da luu</h2>
            <div class="sh-history-list" id="history-list"></div>
            <div class="sh-empty sh-hidden" id="history-empty">Chua co workbook nao. Tao workbook dau tien tu tab Tao moi.</div>
        </div>
    </section>

    <section class="sh-tab" id="tab-editor">
        <div class="sh-workbook-shell">
            <div class="sh-editor-bar">
                <input class="sh-input" type="text" id="editor-title" placeholder="Tieu de workbook">
                <button class="sh-btn brand" id="btn-save">Luu</button>
                <button class="sh-btn" id="btn-analyze">Phan tich</button>
                <button class="sh-btn warm" id="btn-export-json">Export JSON</button>
                <button class="sh-btn warm" id="btn-export-csv">Export CSV</button>
                <button class="sh-btn" id="btn-send-chat">Send to Chat</button>
            </div>
            <div class="sh-preview-wrap" id="table-preview-wrap">
                <div class="sh-empty" id="editor-empty">Chon mot workbook trong tab Lich su hoac tao workbook moi de bat dau.</div>
                <table class="sh-table sh-hidden" id="table-preview"></table>
            </div>
            <textarea class="sh-json" id="editor-json" spellcheck="false"></textarea>
        </div>
        <div class="sh-card">
            <h2 class="sh-section-title">Ghi chu editor</h2>
            <div class="sh-muted">Workbook duoc luu duoi dang JSON. Frontend engine phase sau chi can map vao `workbook_json` nay de render editor thuc su va patch nguoc ve backend.</div>
        </div>
    </section>

    <section class="sh-tab" id="tab-chat">
        <div class="sh-card">
            <h2 class="sh-section-title">Guided commands</h2>
            <div class="sh-command-list">
                <button class="sh-command" data-msg="Tao bang ngan sach marketing 12 thang" data-tool="create_sheet_from_prompt"><strong>Tao workbook</strong><span>Primary tool: create_sheet_from_prompt</span></button>
                <button class="sh-command" data-msg="Phan tich bang tinh nay va goi y dashboard" data-tool="analyze_sheet_data"><strong>Phan tich du lieu</strong><span>Secondary tool: analyze_sheet_data</span></button>
                <button class="sh-command" data-msg="Tao cong thuc tong doanh thu cho vung E2:E20" data-tool="fill_formula_range"><strong>Dien cong thuc</strong><span>Secondary tool: fill_formula_range</span></button>
                <button class="sh-command" data-msg="Export workbook 123 thanh CSV" data-tool="export_sheet_file"><strong>Export workbook</strong><span>Secondary tool: export_sheet_file</span></button>
            </div>
        </div>
    </section>
<?php endif; ?>
</main>

<?php if ( $is_logged_in ) : ?>
<nav class="sh-nav">
    <button class="sh-nav-item active" data-tab="create"><span class="sh-nav-icon">✦</span><span>Tao</span></button>
    <button class="sh-nav-item" data-tab="history"><span class="sh-nav-icon">▤</span><span>Lich su</span></button>
    <button class="sh-nav-item" data-tab="editor"><span class="sh-nav-icon">✎</span><span>Editor</span></button>
    <button class="sh-nav-item" data-tab="chat"><span class="sh-nav-icon">☰</span><span>Chat</span></button>
</nav>

<script>
(function() {
'use strict';

const CFG = {
    ajax: <?php echo json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
    nonce: <?php echo json_encode( wp_create_nonce( 'bztool_sheet' ) ); ?>,
    openId: <?php echo (int) $open_id; ?>,
};

let currentTab = 'create';
let currentWorkbookId = 0;

function el(id) { return document.getElementById(id); }

document.addEventListener('DOMContentLoaded', function() {
    bindNav();
    bindCreate();
    bindEditor();
    bindChat();
    bindTips();
    loadHistory();
    if (CFG.openId) {
        openWorkbook(CFG.openId);
        switchTab('editor');
    }
});

function bindNav() {
    document.querySelectorAll('.sh-nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchTab(this.dataset.tab); });
    });
}

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.sh-tab').forEach(function(node) {
        node.classList.toggle('active', node.id === 'tab-' + tab);
    });
    document.querySelectorAll('.sh-nav-item').forEach(function(node) {
        node.classList.toggle('active', node.dataset.tab === tab);
    });
}

function bindTips() {
    document.querySelectorAll('.sh-tip').forEach(function(btn) {
        btn.addEventListener('click', function() {
            el('create-topic').value = this.dataset.topic || '';
        });
    });
}

function bindCreate() {
    el('btn-create').addEventListener('click', async function() {
        const topic = el('create-topic').value.trim();
        if (!topic) {
            alert('Can nhap mo ta workbook.');
            return;
        }

        const form = new FormData();
        form.append('action', 'bztool_sheet_create');
        form.append('nonce', CFG.nonce);
        form.append('topic', topic);
        form.append('sheet_purpose', el('create-purpose').value);
        form.append('rows_estimate', el('create-rows').value || '12');

        const res = await fetch(CFG.ajax, { method: 'POST', body: form });
        const json = await res.json();
        if (!json.success) {
            alert((json.data && json.data.message) || 'Khong tao duoc workbook.');
            return;
        }

        currentWorkbookId = json.data.workbook_id;
        el('editor-title').value = json.data.title || 'Workbook';
        el('editor-json').value = json.data.workbook_json || '';
        renderWorkbook(json.data.workbook_json || '');
        await loadHistory();
        switchTab('editor');
    });

    el('btn-create-open-chat').addEventListener('click', function() {
        const topic = el('create-topic').value.trim();
        if (!topic) return;
        postToChat(topic, 'create_sheet_from_prompt');
    });
}

function bindEditor() {
    el('btn-save').addEventListener('click', async function() {
        if (!currentWorkbookId) {
            alert('Chua co workbook dang mo.');
            return;
        }
        const form = new FormData();
        form.append('action', 'bztool_sheet_save');
        form.append('nonce', CFG.nonce);
        form.append('id', String(currentWorkbookId));
        form.append('title', el('editor-title').value.trim() || 'Workbook');
        form.append('workbook_json', el('editor-json').value);
        const res = await fetch(CFG.ajax, { method: 'POST', body: form });
        const json = await res.json();
        alert(json.success ? 'Da luu workbook.' : ((json.data && json.data.message) || 'Khong luu duoc.'));
        await loadHistory();
    });

    el('btn-analyze').addEventListener('click', function() {
        const workbook = el('editor-json').value;
        postToChat('Phan tich bang tinh nay va goi y dashboard:\n' + workbook.slice(0, 2500), 'analyze_sheet_data');
    });

    el('btn-send-chat').addEventListener('click', function() {
        const workbook = el('editor-json').value;
        postToChat('Lam viec voi workbook nay:\n' + workbook.slice(0, 2500), 'create_sheet_from_prompt');
    });

    el('btn-export-json').addEventListener('click', function() {
        downloadText((el('editor-title').value.trim() || 'workbook') + '.json', el('editor-json').value, 'application/json');
    });

    el('btn-export-csv').addEventListener('click', function() {
        const csv = workbookToCSV(el('editor-json').value);
        downloadText((el('editor-title').value.trim() || 'workbook') + '.csv', csv, 'text/csv;charset=utf-8');
    });

    el('editor-json').addEventListener('input', function() {
        renderWorkbook(this.value);
    });
}

function bindChat() {
    document.querySelectorAll('.sh-command').forEach(function(btn) {
        btn.addEventListener('click', function() {
            postToChat(this.dataset.msg || '', this.dataset.tool || '');
        });
    });
}

async function loadHistory() {
    const url = CFG.ajax + '?action=bztool_sheet_list&nonce=' + encodeURIComponent(CFG.nonce);
    const res = await fetch(url);
    const json = await res.json();
    const list = el('history-list');
    list.innerHTML = '';

    const items = json.success && json.data && Array.isArray(json.data.items) ? json.data.items : [];
    el('history-empty').classList.toggle('sh-hidden', items.length > 0);

    items.forEach(function(item) {
        const wrap = document.createElement('div');
        wrap.className = 'sh-history-item';
        wrap.innerHTML = '<strong>' + escapeHtml(item.title || 'Workbook') + '</strong>'
            + '<span>' + escapeHtml(item.sheet_purpose || 'custom') + ' • ' + escapeHtml(item.updated_at || '') + '</span>'
            + '<div class="sh-actions">'
            + '<button class="sh-btn" data-open="' + item.id + '">Mo</button>'
            + '<button class="sh-btn danger" data-delete="' + item.id + '">Xoa</button>'
            + '</div>';
        list.appendChild(wrap);
    });

    list.querySelectorAll('[data-open]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openWorkbook(parseInt(this.dataset.open, 10));
        });
    });

    list.querySelectorAll('[data-delete]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            deleteWorkbook(parseInt(this.dataset.delete, 10));
        });
    });
}

async function openWorkbook(id) {
    const url = CFG.ajax + '?action=bztool_sheet_get&nonce=' + encodeURIComponent(CFG.nonce) + '&id=' + encodeURIComponent(String(id));
    const res = await fetch(url);
    const json = await res.json();
    if (!json.success) {
        alert((json.data && json.data.message) || 'Khong mo duoc workbook.');
        return;
    }
    currentWorkbookId = id;
    el('editor-title').value = json.data.title || 'Workbook';
    el('editor-json').value = json.data.workbook_json || '';
    renderWorkbook(json.data.workbook_json || '');
    switchTab('editor');
}

async function deleteWorkbook(id) {
    if (!window.confirm('Xoa workbook nay?')) return;
    const form = new FormData();
    form.append('action', 'bztool_sheet_delete');
    form.append('nonce', CFG.nonce);
    form.append('id', String(id));
    const res = await fetch(CFG.ajax, { method: 'POST', body: form });
    const json = await res.json();
    if (!json.success) {
        alert((json.data && json.data.message) || 'Khong xoa duoc workbook.');
        return;
    }
    if (currentWorkbookId === id) {
        currentWorkbookId = 0;
        el('editor-title').value = '';
        el('editor-json').value = '';
        renderWorkbook('');
    }
    await loadHistory();
}

function renderWorkbook(workbookJson) {
    const table = el('table-preview');
    const empty = el('editor-empty');
    table.innerHTML = '';
    if (!workbookJson.trim()) {
        table.classList.add('sh-hidden');
        empty.classList.remove('sh-hidden');
        return;
    }
    let parsed;
    try {
        parsed = JSON.parse(workbookJson);
    } catch (err) {
        table.classList.add('sh-hidden');
        empty.classList.remove('sh-hidden');
        empty.textContent = 'Workbook JSON chua hop le.';
        return;
    }
    const rows = parsed && parsed.sheets && parsed.sheets[0] && Array.isArray(parsed.sheets[0].rows)
        ? parsed.sheets[0].rows
        : [];
    if (!rows.length) {
        table.classList.add('sh-hidden');
        empty.classList.remove('sh-hidden');
        empty.textContent = 'Workbook chua co du lieu.';
        return;
    }
    empty.classList.add('sh-hidden');
    table.classList.remove('sh-hidden');

    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    (rows[0] || []).forEach(function(cell) {
        const th = document.createElement('th');
        th.textContent = cell;
        headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);

    const tbody = document.createElement('tbody');
    rows.slice(1).forEach(function(row) {
        const tr = document.createElement('tr');
        row.forEach(function(cell) {
            const td = document.createElement('td');
            td.textContent = cell == null ? '' : String(cell);
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    table.appendChild(tbody);
}

function workbookToCSV(workbookJson) {
    try {
        const parsed = JSON.parse(workbookJson);
        const rows = parsed.sheets[0].rows || [];
        return rows.map(function(row) {
            return row.map(function(cell) {
                const value = cell == null ? '' : String(cell).replace(/"/g, '""');
                return '"' + value + '"';
            }).join(',');
        }).join('\n');
    } catch (err) {
        return '';
    }
}

function downloadText(fileName, content, mime) {
    const blob = new Blob([content], { type: mime });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

function buildSlashMessage(msg, toolName) {
    var base = (msg || '').trim();
    var tool = (toolName || '').trim();
    if (!base || !tool) return base;
    if (base.indexOf('/') === 0) return base;
    return '/' + tool + ' ' + base;
}

function postToChat(message, toolName) {
    if (!message) return;
    var slashMsg = buildSlashMessage(message, toolName);
    if (window.parent && window.parent !== window) {
        window.parent.postMessage({
            type:        'bizcity_agent_command',
            source:      'bizcity-tool-sheet',
            plugin_slug: 'bizcity-tool-sheet',
            tool_name:   toolName || '',
            text:        slashMsg || message
        }, '*');
        return;
    }
    alert('Message to chat:\n\n' + (slashMsg || message));
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}
})();
</script>
<?php endif; ?>
</body>
</html>
