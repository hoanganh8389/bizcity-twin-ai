<?php
/**
 * BizCity — Task Detail (Nhiệm vụ chi tiết) — Conversation View
 *
 * Standalone page: /tasks/{conversation_id}/
 * Consumes REST API:
 *   GET /bizcity-intent/v1/tasks/{id}
 *   GET /bizcity-intent/v1/tasks/{id}/turns
 *
 * @package BizCity_Intent
 * @since   4.4.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    wp_redirect( wp_login_url( home_url( '/tasks/' ) ) );
    exit;
}

$task_id  = sanitize_text_field( get_query_var( 'bizcity_task_id', '' ) );
$api_base = esc_url( rest_url( 'bizcity-intent/v1' ) );
$nonce    = wp_create_nonce( 'wp_rest' );
$is_iframe = isset( $_GET['bizcity_iframe'] );
$tasks_url = $is_iframe ? home_url( '/tasks/?bizcity_iframe=1' ) : home_url( '/tasks/' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>🎯 Chi tiết nhiệm vụ — BizCity</title>
<style>
:root {
    --bg: #0f172a; --surface: #1e293b; --border: #334155;
    --text: #e2e8f0; --muted: #94a3b8; --accent: #3b82f6;
    --green: #10b981; --red: #ef4444; --yellow: #f59e0b;
    --user-bg: #1e3a5f; --bot-bg: #1e293b;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text); line-height: 1.6; }
.container { max-width: 760px; margin: 0 auto; padding: 24px 16px; }
.back-link { color: var(--accent); text-decoration: none; font-size: 13px; }
.back-link:hover { text-decoration: underline; }

/* Task header */
.task-header {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: 20px; margin: 16px 0;
}
.task-header h1 { font-size: 20px; margin-bottom: 6px; }
.task-header .meta-row { font-size: 12px; color: var(--muted); display: flex; gap: 16px; flex-wrap: wrap; margin-top: 4px; }
.task-header .status-badge {
    display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 10px;
    border-radius: 12px; margin-left: 8px;
}
.task-header .slots { margin-top: 12px; }
.task-header .slots details { font-size: 12px; }
.task-header .slots summary { cursor: pointer; color: var(--accent); font-size: 12px; }
.slot-grid { display: grid; grid-template-columns: auto 1fr; gap: 4px 12px; margin-top: 6px; font-size: 12px; }
.slot-key { color: var(--muted); font-weight: 500; }
.slot-val { word-break: break-all; }

/* Chat bubbles */
.chat-area { display: flex; flex-direction: column; gap: 10px; margin-top: 16px; }
.bubble {
    max-width: 85%; padding: 10px 14px; border-radius: 12px;
    font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;
}
.bubble.user { background: var(--user-bg); align-self: flex-end; border-bottom-right-radius: 4px; }
.bubble.assistant { background: var(--bot-bg); border: 1px solid var(--border); align-self: flex-start; border-bottom-left-radius: 4px; }
.bubble.system { background: transparent; border: 1px dashed var(--border); align-self: center; font-size: 12px; color: var(--muted); text-align: center; max-width: 95%; }
.bubble.tool { background: rgba(139,92,246,.1); border: 1px solid rgba(139,92,246,.3); align-self: flex-start; font-size: 12px; }
.bubble-time { font-size: 10px; color: var(--muted); margin-top: 2px; }
.bubble-role { font-size: 10px; color: var(--muted); margin-bottom: 2px; font-weight: 600; text-transform: uppercase; }

/* Pagination */
.pagination { display: flex; justify-content: center; align-items: center; gap: 8px; margin-top: 16px; }
.pagination button {
    background: var(--surface); color: var(--text); border: 1px solid var(--border);
    padding: 6px 14px; border-radius: 6px; cursor: pointer; font-size: 13px;
}
.pagination button:hover:not(:disabled) { border-color: var(--accent); }
.pagination button:disabled { opacity: .4; cursor: default; }
.pagination .pg-info { font-size: 13px; color: var(--muted); }

.loading { text-align: center; padding: 24px; color: var(--muted); }
.error-msg { text-align: center; padding: 24px; color: var(--red); }
</style>
</head>
<body>
<div class="container">
    <a href="<?php echo esc_url( $tasks_url ); ?>" class="back-link">← Danh sách nhiệm vụ</a>

    <div id="task-header" class="task-header" style="display:none"></div>
    <div class="chat-area" id="chat-area">
        <div class="loading">Đang tải...</div>
    </div>
    <div class="pagination" id="pagination"></div>
</div>

<script>
(function() {
    var API    = <?php echo wp_json_encode( $api_base ); ?>;
    var NONCE  = <?php echo wp_json_encode( $nonce ); ?>;
    var TASK_ID = <?php echo wp_json_encode( $task_id ); ?>;

    var turnPage = 1, turnPerPage = 50;

    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function fetchAPI(path) {
        return fetch(API + path, { headers: { 'X-WP-Nonce': NONCE }, credentials: 'same-origin' })
            .then(function(r) { return r.json(); });
    }

    function fmtTime(dt) {
        if (!dt) return '';
        try { return new Date(dt.replace(' ', 'T') + 'Z').toLocaleString('vi-VN'); } catch(e) { return dt; }
    }

    function statusColor(s) {
        s = (s || '').toUpperCase();
        if (s === 'COMPLETED') return 'background:rgba(16,185,129,.15);color:#10b981';
        if (s === 'ACTIVE' || s === 'IN_PROGRESS') return 'background:rgba(59,130,246,.15);color:#3b82f6';
        if (s === 'CANCELLED' || s === 'FAILED') return 'background:rgba(239,68,68,.15);color:#ef4444';
        if (s === 'WAITING_USER') return 'background:rgba(245,158,11,.15);color:#f59e0b';
        return 'background:rgba(148,163,184,.15);color:#94a3b8';
    }

    // Load task header
    fetchAPI('/tasks/' + TASK_ID).then(function(data) {
        if (data.code) {
            document.getElementById('chat-area').innerHTML = '<div class="error-msg">' + esc(data.message || 'Không tìm thấy nhiệm vụ') + '</div>';
            return;
        }
        var hdr = document.getElementById('task-header');
        hdr.style.display = 'block';

        var slotsHtml = '';
        if (data.slots && Object.keys(data.slots).length) {
            slotsHtml = '<div class="slots"><details><summary>📋 Dữ liệu thu thập (' + Object.keys(data.slots).length + ' trường)</summary><div class="slot-grid">';
            Object.keys(data.slots).forEach(function(k) {
                var v = data.slots[k];
                if (typeof v === 'object') v = JSON.stringify(v);
                slotsHtml += '<span class="slot-key">' + esc(k) + '</span><span class="slot-val">' + esc(String(v)) + '</span>';
            });
            slotsHtml += '</div></details></div>';
        }

        hdr.innerHTML =
            '<h1>' + esc(data.title || data.goal) + '<span class="status-badge" style="' + statusColor(data.status) + '">' + esc(data.status) + '</span></h1>' +
            '<div class="meta-row">' +
                '<span>🗂 Goal: ' + esc(data.goal) + '</span>' +
                '<span>💬 ' + data.turn_count + ' lượt</span>' +
                '<span>🕐 Tạo: ' + fmtTime(data.created_at) + '</span>' +
                '<span>🔄 Cập nhật: ' + fmtTime(data.last_activity_at) + '</span>' +
            '</div>' +
            (data.rolling_summary ? '<p style="margin-top:8px;font-size:13px;color:#94a3b8">' + esc(data.rolling_summary) + '</p>' : '') +
            slotsHtml;

        loadTurns();
    });

    function loadTurns() {
        var area = document.getElementById('chat-area');
        area.innerHTML = '<div class="loading">Đang tải hội thoại...</div>';

        fetchAPI('/tasks/' + TASK_ID + '/turns?page=' + turnPage + '&per_page=' + turnPerPage).then(function(data) {
            if (!data.turns || !data.turns.length) {
                area.innerHTML = '<div class="loading" style="color:#94a3b8">Chưa có lượt hội thoại nào</div>';
                document.getElementById('pagination').innerHTML = '';
                return;
            }

            area.innerHTML = data.turns.map(function(t) {
                var cls = t.role === 'user' ? 'user' : (t.role === 'tool' ? 'tool' : (t.role === 'system' ? 'system' : 'assistant'));
                return '<div class="bubble ' + cls + '">' +
                    '<div class="bubble-role">' + esc(t.role) + '</div>' +
                    esc(t.content || '(empty)') +
                    (t.created_at ? '<div class="bubble-time">' + fmtTime(t.created_at) + '</div>' : '') +
                '</div>';
            }).join('');

            // Pagination
            var pg = document.getElementById('pagination');
            if (data.total_pages <= 1) { pg.innerHTML = ''; return; }
            pg.innerHTML =
                '<button id="tp-prev"' + (data.page <= 1 ? ' disabled' : '') + '>← Trước</button>' +
                '<span class="pg-info">Trang ' + data.page + ' / ' + data.total_pages + '</span>' +
                '<button id="tp-next"' + (data.page >= data.total_pages ? ' disabled' : '') + '>Sau →</button>';
            document.getElementById('tp-prev').addEventListener('click', function() { if (turnPage > 1) { turnPage--; loadTurns(); } });
            document.getElementById('tp-next').addEventListener('click', function() { if (turnPage < data.total_pages) { turnPage++; loadTurns(); } });
        });
    }
})();
</script>
</body>
</html>
