<?php
/**
 * @package    Bizcity_Twin_AI
 * @subpackage Core\Memory
 * @author     Johnny Chu (Chu Hoàng Anh) <Hoanganh.itm@gmail.com>
 * @copyright  2024-2026 BizCity — Made in Vietnam 🇻🇳
 * @license    GPL-2.0-or-later
 * @link       https://bizcity.vn
 */

/**
 * BizCity Memory Specs — Admin Page View
 *
 * Two-panel layout: Tree sidebar (left) + Editor panel (right)
 * Tree structure: Project → Session → Memory Spec
 * Purple theme (#8b5cf6).
 *
 * @package  BizCity_Memory
 * @since    Phase 1.15 — 2026-04-09
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$rest_url   = esc_url_raw( rest_url( 'bizcity/memory/v1' ) );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$td         = 'bizcity-twin-ai';
$is_admin   = current_user_can( 'manage_options' );
?><style>
/* ── Memory Spec Theme Variables ── */
:root {
    --mem-primary:       #8b5cf6;
    --mem-primary-light: #f5f3ff;
    --mem-primary-dark:  #7c3aed;
    --mem-bg:            #f8fafc;
    --mem-card:          #fff;
    --mem-border:        #e5e7eb;
    --mem-text:          #1e293b;
    --mem-text-muted:    #64748b;
    --mem-sidebar:       260px;
    --mem-success:       #10b981;
    --mem-warning:       #f59e0b;
    --mem-danger:        #ef4444;
    --mem-stale:         #f97316;
}

/* ── Layout ── */
.mem-layout {
    display: flex;
    min-height: calc(100vh - 32px);
    background: var(--mem-bg);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    color: var(--mem-text);
    margin: 0 -20px 0 -2px;
}

/* ── Sidebar ── */
.mem-sidebar {
    width: var(--mem-sidebar);
    min-width: var(--mem-sidebar);
    background: var(--mem-card);
    border-right: 1px solid var(--mem-border);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.mem-sidebar-header {
    padding: 16px;
    border-bottom: 1px solid var(--mem-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.mem-sidebar-header h2 {
    margin: 0;
    font-size: 15px;
    font-weight: 600;
    color: var(--mem-primary);
}
.mem-sidebar-header h2 span { margin-right: 6px; }

.mem-btn-new {
    background: var(--mem-primary);
    color: #fff;
    border: none;
    border-radius: 6px;
    padding: 6px 12px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: background .15s;
}
.mem-btn-new:hover { background: var(--mem-primary-dark); }

/* ── Tree ── */
.mem-tree-wrap {
    flex: 1;
    overflow-y: auto;
    padding: 8px 0;
}
.mem-tree { list-style: none; margin: 0; padding: 0; }
.mem-tree-group { margin-bottom: 2px; }
.mem-tree-group-header {
    display: flex;
    align-items: center;
    padding: 6px 12px;
    cursor: pointer;
    user-select: none;
    font-size: 13px;
    font-weight: 600;
    color: var(--mem-text-muted);
    transition: background .1s;
}
.mem-tree-group-header:hover { background: var(--mem-primary-light); }
.mem-tree-chevron {
    display: inline-block;
    width: 16px;
    font-size: 10px;
    transition: transform .15s;
    color: var(--mem-text-muted);
}
.mem-tree-chevron.open { transform: rotate(90deg); }
.mem-tree-group-icon { margin: 0 6px; }

.mem-tree-session { list-style: none; margin: 0; padding: 0 0 0 18px; }
.mem-tree-session-header {
    display: flex;
    align-items: center;
    padding: 4px 8px;
    cursor: pointer;
    font-size: 12px;
    color: var(--mem-text-muted);
}
.mem-tree-session-header:hover { background: #f1f5f9; }

.mem-tree-items { list-style: none; margin: 0; padding: 0 0 0 16px; }
.mem-tree-item {
    display: flex;
    align-items: center;
    padding: 5px 10px;
    cursor: pointer;
    font-size: 13px;
    border-radius: 4px;
    margin: 1px 4px;
    transition: background .1s;
}
.mem-tree-item:hover { background: var(--mem-primary-light); }
.mem-tree-item.selected {
    background: var(--mem-primary);
    color: #fff;
}
.mem-tree-item-icon { margin-right: 6px; font-size: 12px; }
.mem-tree-item-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.mem-tree-item .mem-badge {
    font-size: 10px;
    padding: 1px 5px;
    border-radius: 8px;
    margin-left: 6px;
}
.mem-badge-active   { background: #d1fae5; color: #065f46; }
.mem-badge-stale    { background: #ffedd5; color: #9a3412; }
.mem-badge-archived { background: #f1f5f9; color: #64748b; }

/* ── Editor Panel ── */
.mem-editor {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.mem-editor-toolbar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--mem-border);
    background: var(--mem-card);
    flex-wrap: wrap;
}
.mem-editor-title-input {
    font-size: 16px;
    font-weight: 600;
    border: 1px solid transparent;
    border-radius: 4px;
    padding: 4px 8px;
    flex: 1;
    min-width: 200px;
    background: transparent;
    color: var(--mem-text);
}
.mem-editor-title-input:focus {
    outline: none;
    border-color: var(--mem-primary);
    background: #fff;
}

.mem-tbtn {
    padding: 6px 14px;
    border: 1px solid var(--mem-border);
    border-radius: 6px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    background: #fff;
    color: var(--mem-text);
    transition: all .15s;
}
.mem-tbtn:hover { border-color: var(--mem-primary); color: var(--mem-primary); }
.mem-tbtn--save { background: var(--mem-primary); color: #fff; border-color: var(--mem-primary); }
.mem-tbtn--save:hover { background: var(--mem-primary-dark); }
.mem-tbtn--archive { color: var(--mem-danger); }
.mem-tbtn--archive:hover { border-color: var(--mem-danger); background: #fef2f2; }

/* ── Editor Content ── */
.mem-editor-body {
    flex: 1;
    display: flex;
    overflow: hidden;
}
.mem-editor-textarea {
    flex: 1;
    border: none;
    padding: 16px 20px;
    font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
    font-size: 13px;
    line-height: 1.6;
    resize: none;
    background: var(--mem-card);
    color: var(--mem-text);
    outline: none;
    tab-size: 2;
}
.mem-editor-textarea::placeholder { color: var(--mem-text-muted); }

/* ── Preview Panel ── */
.mem-preview-panel {
    width: 50%;
    border-left: 1px solid var(--mem-border);
    padding: 16px 20px;
    overflow-y: auto;
    background: var(--mem-bg);
    display: none;
}
.mem-preview-panel.visible { display: block; }
.mem-preview-panel h2 { font-size: 18px; margin: 0 0 12px; color: var(--mem-primary); }
.mem-preview-panel h3 { font-size: 14px; margin: 16px 0 6px; color: var(--mem-text); }
.mem-preview-panel ul  { padding-left: 20px; margin: 4px 0; }
.mem-preview-panel li  { font-size: 13px; margin: 2px 0; line-height: 1.5; }

/* ── Log Panel ── */
.mem-log-panel {
    width: 320px;
    border-left: 1px solid var(--mem-border);
    background: var(--mem-card);
    overflow-y: auto;
    display: none;
    padding: 12px;
}
.mem-log-panel.visible { display: block; }
.mem-log-panel h3 { font-size: 14px; margin: 0 0 10px; color: var(--mem-primary); }
.mem-log-entry {
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
    font-size: 12px;
}
.mem-log-action { font-weight: 600; text-transform: uppercase; font-size: 11px; }
.mem-log-action.created    { color: var(--mem-success); }
.mem-log-action.updated    { color: var(--mem-primary); }
.mem-log-action.archived   { color: var(--mem-text-muted); }
.mem-log-action.finalized  { color: var(--mem-warning); }
.mem-log-step  { color: var(--mem-text-muted); }
.mem-log-time  { color: var(--mem-text-muted); font-size: 11px; }

/* ── Empty State ── */
.mem-empty {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    color: var(--mem-text-muted);
    font-size: 15px;
}
.mem-empty-icon { font-size: 48px; margin-bottom: 12px; opacity: .5; }

/* ── Meta Bar ── */
.mem-meta-bar {
    padding: 8px 16px;
    border-top: 1px solid var(--mem-border);
    background: #fafbfc;
    font-size: 11px;
    color: var(--mem-text-muted);
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
}

/* ── Responsive ── */
@media (max-width: 768px) {
    .mem-layout { flex-direction: column; }
    .mem-sidebar { width: 100%; min-width: 100%; max-height: 280px; }
}
</style>

<div class="mem-layout" id="mem-app">
    <!-- ═══ Sidebar ═══ -->
    <div class="mem-sidebar">
        <div class="mem-sidebar-header">
            <h2><span>🧠</span> <?php esc_html_e( 'Memory Specs', $td ); ?></h2>
            <?php if ( $is_admin ) : ?>
                <button class="mem-btn-new" id="mem-btn-new" title="<?php esc_attr_e( 'New Memory Spec', $td ); ?>">
                    + <?php esc_html_e( 'New', $td ); ?>
                </button>
            <?php endif; ?>
        </div>
        <div class="mem-tree-wrap">
            <ul class="mem-tree" id="mem-tree">
                <!-- Populated by JS -->
            </ul>
        </div>
    </div>

    <!-- ═══ Editor ═══ -->
    <div class="mem-editor" id="mem-editor">
        <div class="mem-editor-toolbar" id="mem-toolbar" style="display:none;">
            <input type="text" class="mem-editor-title-input" id="mem-title" placeholder="<?php esc_attr_e( 'Memory title...', $td ); ?>" />
            <button class="mem-tbtn mem-tbtn--save" id="mem-save">💾 <?php esc_html_e( 'Save', $td ); ?></button>
            <button class="mem-tbtn" id="mem-preview-toggle">👁 <?php esc_html_e( 'Preview', $td ); ?></button>
            <button class="mem-tbtn" id="mem-log-toggle">📋 <?php esc_html_e( 'Log', $td ); ?></button>
            <?php if ( $is_admin ) : ?>
                <button class="mem-tbtn mem-tbtn--archive" id="mem-archive">🗑 <?php esc_html_e( 'Archive', $td ); ?></button>
            <?php endif; ?>
        </div>

        <div class="mem-editor-body">
            <textarea class="mem-editor-textarea" id="mem-content" placeholder="<?php esc_attr_e( 'Select a memory spec from the tree, or create a new one.', $td ); ?>" style="display:none;"></textarea>
            <div class="mem-preview-panel" id="mem-preview"></div>
            <div class="mem-log-panel" id="mem-log-panel">
                <h3>📋 <?php esc_html_e( 'Audit Log', $td ); ?></h3>
                <div id="mem-log-list"></div>
            </div>
            <div class="mem-empty" id="mem-empty">
                <div class="mem-empty-icon">🧠</div>
                <div><?php esc_html_e( 'Select a Memory Spec to view or edit', $td ); ?></div>
            </div>
        </div>

        <div class="mem-meta-bar" id="mem-meta" style="display:none;">
            <span id="mem-meta-id"></span>
            <span id="mem-meta-key"></span>
            <span id="mem-meta-scope"></span>
            <span id="mem-meta-status"></span>
            <span id="mem-meta-updated"></span>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';

    var REST = <?php echo wp_json_encode( $rest_url ); ?>;
    var NONCE = <?php echo wp_json_encode( $rest_nonce ); ?>;

    var state = { tree: {}, current: null, dirty: false };

    /* ── Helpers ── */
    function api(method, path, body) {
        var opts = {
            method: method,
            headers: { 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' },
        };
        if (body) opts.body = JSON.stringify(body);
        return fetch(REST + path, opts).then(function(r) { return r.json(); });
    }

    function $(id) { return document.getElementById(id); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    /* ── Load Tree ── */
    function loadTree() {
        api('GET', 'tree').then(function(res) {
            if (!res.ok) return;
            state.tree = res.tree || {};
            renderTree();
        });
    }

    function renderTree() {
        var tree = state.tree;
        var html = '';
        var projectKeys = Object.keys(tree);

        if (projectKeys.length === 0) {
            html = '<li style="padding:12px;color:var(--mem-text-muted);font-size:13px;"><?php esc_html_e( 'No memory specs yet.', $td ); ?></li>';
            $('mem-tree').innerHTML = html;
            return;
        }

        projectKeys.forEach(function(projId) {
            var projLabel = projId || '(default)';
            html += '<li class="mem-tree-group">';
            html += '<div class="mem-tree-group-header" data-project="' + esc(projId) + '">';
            html += '<span class="mem-tree-chevron open">▶</span>';
            html += '<span class="mem-tree-group-icon">📁</span>';
            html += '<span>' + esc(projLabel) + '</span>';
            html += '</div>';

            var sessions = tree[projId];
            var sessionKeys = Object.keys(sessions);
            html += '<ul class="mem-tree-session">';

            sessionKeys.forEach(function(sessId) {
                var sessLabel = sessId || '(project-level)';
                html += '<li>';
                html += '<div class="mem-tree-session-header" data-session="' + esc(sessId) + '">';
                html += '<span class="mem-tree-chevron open">▶</span>';
                html += '<span style="margin:0 4px;">💬</span>';
                html += '<span>' + esc(sessLabel) + '</span>';
                html += '</div>';
                html += '<ul class="mem-tree-items">';

                var items = sessions[sessId];
                if (Array.isArray(items)) {
                    items.forEach(function(item) {
                        var badgeCls = 'mem-badge-' + (item.status || 'active');
                        var sel = (state.current && state.current.id == item.id) ? ' selected' : '';
                        html += '<li class="mem-tree-item' + sel + '" data-id="' + item.id + '">';
                        html += '<span class="mem-tree-item-icon">📝</span>';
                        html += '<span class="mem-tree-item-title">' + esc(item.title || item.memory_key || '#' + item.id) + '</span>';
                        html += '<span class="mem-badge ' + badgeCls + '">' + esc(item.status || 'active') + '</span>';
                        html += '</li>';
                    });
                }

                html += '</ul></li>';
            });

            html += '</ul></li>';
        });

        $('mem-tree').innerHTML = html;
        bindTreeEvents();
    }

    function bindTreeEvents() {
        // Toggle project groups
        document.querySelectorAll('.mem-tree-group-header').forEach(function(el) {
            el.addEventListener('click', function() {
                var chevron = this.querySelector('.mem-tree-chevron');
                var list = this.nextElementSibling;
                if (list) {
                    var vis = list.style.display !== 'none';
                    list.style.display = vis ? 'none' : '';
                    chevron.classList.toggle('open', !vis);
                }
            });
        });

        // Toggle session groups
        document.querySelectorAll('.mem-tree-session-header').forEach(function(el) {
            el.addEventListener('click', function() {
                var chevron = this.querySelector('.mem-tree-chevron');
                var list = this.nextElementSibling;
                if (list) {
                    var vis = list.style.display !== 'none';
                    list.style.display = vis ? 'none' : '';
                    chevron.classList.toggle('open', !vis);
                }
            });
        });

        // Select memory item
        document.querySelectorAll('.mem-tree-item').forEach(function(el) {
            el.addEventListener('click', function() {
                var id = parseInt(this.getAttribute('data-id'), 10);
                if (id) loadMemory(id);
            });
        });
    }

    /* ── Load Single Memory ── */
    function loadMemory(id) {
        api('GET', '' + id).then(function(res) {
            if (!res.ok) return;
            state.current = res.memory;
            state.dirty = false;
            renderEditor();
            renderTree(); // re-highlight selected
        });
    }

    /* ── Render Editor ── */
    function renderEditor() {
        var mem = state.current;
        if (!mem) {
            $('mem-toolbar').style.display = 'none';
            $('mem-content').style.display = 'none';
            $('mem-meta').style.display = 'none';
            $('mem-empty').style.display = 'flex';
            $('mem-preview').classList.remove('visible');
            $('mem-log-panel').classList.remove('visible');
            return;
        }

        $('mem-toolbar').style.display = 'flex';
        $('mem-content').style.display = 'block';
        $('mem-meta').style.display = 'flex';
        $('mem-empty').style.display = 'none';

        $('mem-title').value = mem.title || '';
        $('mem-content').value = mem.content || '';

        // Meta bar
        $('mem-meta-id').textContent = 'ID: ' + mem.id;
        $('mem-meta-key').textContent = 'Key: ' + (mem.memory_key || '');
        $('mem-meta-scope').textContent = 'Scope: ' + (mem.scope || '');
        $('mem-meta-status').textContent = 'Status: ' + (mem.status || '');
        $('mem-meta-updated').textContent = 'Updated: ' + (mem.updated_at || '');
    }

    /* ── Save ── */
    function saveMemory() {
        if (!state.current) return;
        var body = {
            title: $('mem-title').value,
            content: $('mem-content').value,
        };
        api('PUT', '' + state.current.id, body).then(function(res) {
            if (res.ok) {
                state.current = res.memory;
                state.dirty = false;
                renderEditor();
                loadTree();
            } else {
                alert('Save failed: ' + (res.error || 'Unknown error'));
            }
        });
    }

    /* ── Create New ── */
    function createNew() {
        var title = prompt('<?php echo esc_js( __( 'Memory Spec title:', $td ) ); ?>');
        if (!title) return;
        api('POST', 'create', { title: title, goal: title }).then(function(res) {
            if (res.ok) {
                state.current = res.memory;
                loadTree();
                renderEditor();
            }
        });
    }

    /* ── Archive ── */
    function archiveMemory() {
        if (!state.current) return;
        if (!confirm('<?php echo esc_js( __( 'Archive this memory spec?', $td ) ); ?>')) return;
        api('DELETE', '' + state.current.id).then(function(res) {
            if (res.ok) {
                state.current = null;
                renderEditor();
                loadTree();
            }
        });
    }

    /* ── Preview ── */
    function togglePreview() {
        var panel = $('mem-preview');
        panel.classList.toggle('visible');
        if (panel.classList.contains('visible')) {
            renderPreview();
        }
    }

    function renderPreview() {
        var content = $('mem-content').value || '';
        // Simple markdown → HTML (headings + lists + checkboxes)
        var html = content
            .replace(/^# (.+)$/gm, '<h2>$1</h2>')
            .replace(/^## (.+)$/gm, '<h3>$1</h3>')
            .replace(/^- \[x\] (.+)$/gm, '<li>✅ $1</li>')
            .replace(/^- \[ \] (.+)$/gm, '<li>⬜ $1</li>')
            .replace(/^- (.+)$/gm, '<li>$1</li>')
            .replace(/\n\n/g, '<br><br>');
        $('mem-preview').innerHTML = html;
    }

    /* ── Log Panel ── */
    function toggleLog() {
        var panel = $('mem-log-panel');
        panel.classList.toggle('visible');
        if (panel.classList.contains('visible') && state.current) {
            loadLog(state.current.id);
        }
    }

    function loadLog(id) {
        api('GET', '' + id + '/log?limit=30').then(function(res) {
            if (!res.ok) return;
            var html = '';
            var logs = res.logs || [];
            if (logs.length === 0) {
                html = '<div style="color:var(--mem-text-muted);font-size:12px;"><?php esc_html_e( 'No log entries.', $td ); ?></div>';
            } else {
                logs.forEach(function(entry) {
                    html += '<div class="mem-log-entry">';
                    html += '<span class="mem-log-action ' + esc(entry.action || '') + '">' + esc(entry.action || '') + '</span>';
                    if (entry.step_name) html += ' <span class="mem-log-step">(' + esc(entry.step_name) + ')</span>';
                    html += '<br><span class="mem-log-time">' + esc(entry.created_at || '') + '</span>';
                    html += '</div>';
                });
            }
            $('mem-log-list').innerHTML = html;
        });
    }

    /* ── Event Bindings ── */
    $('mem-save').addEventListener('click', saveMemory);
    $('mem-preview-toggle').addEventListener('click', togglePreview);
    $('mem-log-toggle').addEventListener('click', toggleLog);

    var btnNew = $('mem-btn-new');
    if (btnNew) btnNew.addEventListener('click', createNew);

    var btnArchive = $('mem-archive');
    if (btnArchive) btnArchive.addEventListener('click', archiveMemory);

    // Track dirty state
    $('mem-content').addEventListener('input', function() { state.dirty = true; });
    $('mem-title').addEventListener('input', function() { state.dirty = true; });

    // Keyboard shortcut: Ctrl+S
    document.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 's' && state.current) {
            e.preventDefault();
            saveMemory();
        }
    });

    /* ── Init ── */
    loadTree();
})();
</script>
